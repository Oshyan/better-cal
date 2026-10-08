<?php

declare(strict_types=1);

use BetterCal\Infra\Db;
use BetterCal\Infra\FeedCredentials;
use BetterCal\Infra\Secrets;

/**
 * A database or backup must not itself contain usable inbound or outbound
 * feed capabilities. Public feed lookup keeps a one-way verifier; values the
 * owner must see again are purpose-bound under the application secret.
 */
return static function (Db $db): string {
    $secret = (string) (config()['session_secret'] ?? '');
    if ($secret === '') {
        throw new RuntimeException('BETTERCAL_SESSION_SECRET is required before feed credentials can be protected');
    }

    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $hasTable = static function (string $table) use ($db, $driver): bool {
        if ($driver === 'sqlite') {
            return (int) $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) > 0;
        }
        return (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    };
    $hasColumn = static function (string $table, string $column) use ($db, $driver): bool {
        if ($driver === 'sqlite') {
            return in_array($column, array_column($db->all('PRAGMA table_info(`' . $table . '`)'), 'name'), true);
        }
        return (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    };

    $sources = 0;
    if ($hasTable('calendars')) {
        if ($driver !== 'sqlite') {
            // Secretbox/base64 expands the stored value. Existing source URLs
            // were admitted into TEXT, so widen before transforming them.
            $db->run('ALTER TABLE `calendars` MODIFY COLUMN `source_url` MEDIUMTEXT NULL');
        }
        foreach ($db->all('SELECT id, source_url FROM calendars WHERE source_url IS NOT NULL') as $row) {
            $stored = (string) $row['source_url'];
            if ($stored === '' || Secrets::isSealed($stored)) {
                continue;
            }
            $db->update('calendars', [
                'source_url' => FeedCredentials::sealSourceUrl($stored, $secret),
            ], 'id = ?', [(int) $row['id']]);
            $sources++;
        }
    }

    $outbound = 0;
    if ($hasTable('out_feeds')) {
        if (!$hasColumn('out_feeds', 'token_sealed')) {
            $db->run('ALTER TABLE `out_feeds` ADD COLUMN `token_sealed` ' . ($driver === 'sqlite' ? 'TEXT NULL' : 'TEXT NULL AFTER `token`'));
        }
        if ($driver !== 'sqlite') {
            // The SHA-256 verifier is 64 hexadecimal characters. MySQL
            // preserves the existing unique index across this widening.
            $db->run('ALTER TABLE `out_feeds` MODIFY COLUMN `token` CHAR(64) NOT NULL');
        }
        foreach ($db->all('SELECT id, token, token_sealed FROM out_feeds') as $row) {
            $raw = null;
            $sealed = (string) ($row['token_sealed'] ?? '');
            if ($sealed !== '') {
                $raw = FeedCredentials::openOutboundToken($sealed, $secret);
            } else {
                $raw = (string) $row['token'];
                $sealed = FeedCredentials::protectOutboundToken($raw, $secret)['sealed'];
            }
            $hash = hash('sha256', $raw);
            if (!hash_equals($hash, (string) $row['token']) || (string) ($row['token_sealed'] ?? '') === '') {
                $db->update('out_feeds', ['token' => $hash, 'token_sealed' => $sealed], 'id = ?', [(int) $row['id']]);
                $outbound++;
            }
        }
        if ($driver !== 'sqlite') {
            $db->run('ALTER TABLE `out_feeds` MODIFY COLUMN `token_sealed` TEXT NOT NULL');
        }
    }

    $snapshots = 0;
    if ($hasTable('mutations')) {
        foreach ($db->all('SELECT id, before_json, after_json FROM mutations WHERE before_json IS NOT NULL OR after_json IS NOT NULL') as $mutation) {
            $updates = [];
            foreach (['before_json', 'after_json'] as $column) {
                if ($mutation[$column] === null) {
                    continue;
                }
                $decoded = json_decode((string) $mutation[$column], true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('Mutation ' . (int) $mutation['id'] . ' has invalid JSON; repair it before protecting feed credentials');
                }
                $changed = false;
                if (!isset($decoded['tables']['calendars']) || !is_array($decoded['tables']['calendars'])) {
                    continue;
                }
                foreach ($decoded['tables']['calendars'] as &$calendar) {
                    if (!is_array($calendar) || !isset($calendar['source_url']) || !is_string($calendar['source_url'])
                        || $calendar['source_url'] === '' || Secrets::isSealed($calendar['source_url'])) {
                        continue;
                    }
                    $calendar['source_url'] = FeedCredentials::sealSourceUrl($calendar['source_url'], $secret);
                    $changed = true;
                }
                unset($calendar);
                if ($changed) {
                    $updates[$column] = json_encode($decoded, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                    $snapshots++;
                }
            }
            if ($updates !== []) {
                $db->update('mutations', $updates, 'id = ?', [(int) $mutation['id']]);
            }
        }
    }

    return "$sources subscription credential" . ($sources === 1 ? '' : 's')
        . ", $outbound outbound " . ($outbound === 1 ? 'capability' : 'capabilities')
        . ", and $snapshots Undo snapshot" . ($snapshots === 1 ? '' : 's') . ' protected';
};
