<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * Whether an event takes up time (#303): events.show_as, 'busy' (it does)
 * or 'free' (it does not), as iCalendar's TRANSP (OPAQUE or TRANSPARENT)
 * and Google's `transparency` say it. Every row starts busy, as every
 * event was read until now; no row is rewritten. An event from a feed or a
 * Google calendar takes its source's marking at its next poll. Skipped when
 * already there.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sqlite = $driver === 'sqlite';
    $columns = $sqlite
        ? array_column($db->all('PRAGMA table_info(`events`)'), 'name')
        : array_column($db->all('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['events']), 'name');
    if (in_array('show_as', $columns, true)) {
        return 'events.show_as already present';
    }
    $db->run('ALTER TABLE `events` ADD COLUMN `show_as` ' . ($sqlite ? "TEXT NOT NULL DEFAULT 'busy'" : "VARCHAR(4) NOT NULL DEFAULT 'busy'"));
    return 'added events.show_as';
};
