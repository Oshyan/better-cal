<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * Floating times (#167): events.floating, 1 for an event whose clock time
 * holds in any zone. Its start_utc and end_utc stay the wall clock read in
 * its own zone (tzid), so no row is rewritten and every existing query and
 * index keeps working; only the read path moves a floating row to the
 * viewer's clock (Domain\Floating). Every row starts pinned. Skipped when
 * already there.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sqlite = $driver === 'sqlite';
    $columns = $sqlite
        ? array_column($db->all('PRAGMA table_info(`events`)'), 'name')
        : array_column($db->all('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['events']), 'name');
    if (in_array('floating', $columns, true)) {
        return 'events.floating already present';
    }
    $db->run('ALTER TABLE `events` ADD COLUMN `floating` ' . ($sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'TINYINT(1) NOT NULL DEFAULT 0'));
    return 'added events.floating';
};
