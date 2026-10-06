<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * Track the authority behind recurring ICS subscriptions. Existing ICS rows
 * cannot be attributed reliably, so they pause for one owner review; every
 * other calendar is backfilled as owner-managed. Restart-safe like migration
 * 036 because MySQL commits each ALTER TABLE independently.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $hasColumn = static function (string $column) use ($db, $driver): bool {
        if ($driver === 'sqlite') {
            foreach ($db->all('PRAGMA table_info(`calendars`)') as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        return (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['calendars', $column]
        ) > 0;
    };

    $added = 0;
    if (!$hasColumn('subscription_authority')) {
        $after = $driver === 'sqlite' ? '' : ' AFTER `source_url`';
        $db->run("ALTER TABLE `calendars` ADD COLUMN `subscription_authority` VARCHAR(24) NOT NULL DEFAULT 'legacy_review'" . $after);
        $added++;
    }
    if (!$hasColumn('created_by_token_id')) {
        $after = $driver === 'sqlite' ? '' : ' AFTER `subscription_authority`';
        $type = $driver === 'sqlite' ? 'INTEGER NULL' : 'BIGINT UNSIGNED NULL';
        $db->run('ALTER TABLE `calendars` ADD COLUMN `created_by_token_id` ' . $type . $after);
        $added++;
    }

    // Local, Google and plugin calendars do not use this boundary. Only an
    // existing recurring ICS fetch is ambiguous and therefore awaits review.
    $db->run(
        "UPDATE calendars SET subscription_authority = 'owner'
         WHERE kind <> 'subscribed' OR COALESCE(provider, 'ics') <> 'ics'"
    );

    if ($driver === 'sqlite') {
        $db->run('CREATE INDEX IF NOT EXISTS idx_calendars_creator_token ON calendars (created_by_token_id)');
    } else {
        $hasIndex = (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['calendars', 'idx_calendars_creator_token']
        ) > 0;
        if (!$hasIndex) {
            $db->run('CREATE INDEX idx_calendars_creator_token ON calendars (created_by_token_id)');
        }
    }

    $review = (int) $db->scalar(
        "SELECT COUNT(*) FROM calendars WHERE kind = 'subscribed' AND COALESCE(provider, 'ics') = 'ics'
         AND subscription_authority = 'legacy_review'"
    );
    return 'subscription authority columns ensured (' . $added . ' added); ' . $review . ' existing ICS subscription(s) await review';
};
