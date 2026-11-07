<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * The etag Google gave each event row it owns (#172): events.google_etag,
 * NULL until a poll or a write brings one. Writes to Google send it as
 * If-Match, so a change made at Google since it was read answers 412
 * instead of being overwritten. The probe (2026-11-07, group 5) found
 * that any change to a series changes the etag of its master and of every
 * instance, and that `sequence` and `updated` say nothing an etag doesn't,
 * so the etag is the only thing kept. Skipped when already there.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sqlite = $driver === 'sqlite';
    $columns = $sqlite
        ? array_column($db->all('PRAGMA table_info(`events`)'), 'name')
        : array_column($db->all('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['events']), 'name');
    if (in_array('google_etag', $columns, true)) {
        return 'events.google_etag already present';
    }
    $db->run('ALTER TABLE `events` ADD COLUMN `google_etag` ' . ($sqlite ? 'TEXT NULL' : 'VARCHAR(255) NULL DEFAULT NULL'));
    return 'added events.google_etag';
};
