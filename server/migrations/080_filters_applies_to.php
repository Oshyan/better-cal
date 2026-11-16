<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * Which events a filter's action takes (filters.applies_to): 'match', the
 * events that match it, or 'nonmatch', the events in its scope that do not.
 * Every keyword and regex filter has always taken the events that match, so
 * the column's default, 'match', is what they do. Every AI prompt filter has
 * always taken the events the model judged not to fit its description (hide,
 * dim and highlight alike, until 1.5.8), so prompt rows are filled with
 * 'nonmatch' from their own type: the new column says what each row already
 * did, and an upgrade changes no filter's effect. Skipped when already there.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sqlite = $driver === 'sqlite';
    $columns = $sqlite
        ? array_column($db->all('PRAGMA table_info(`filters`)'), 'name')
        : array_column($db->all('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['filters']), 'name');
    if (in_array('applies_to', $columns, true)) {
        return 'filters.applies_to already present';
    }
    $db->run('ALTER TABLE `filters` ADD COLUMN `applies_to` ' . ($sqlite ? "TEXT NOT NULL DEFAULT 'match'" : "VARCHAR(8) NOT NULL DEFAULT 'match'"));
    $filled = $db->run("UPDATE `filters` SET `applies_to` = 'nonmatch' WHERE `type` = 'prompt'")->rowCount();
    return "added filters.applies_to; $filled AI prompt filter(s) take the events judged not to fit, as they did";
};
