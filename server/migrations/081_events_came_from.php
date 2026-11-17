<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * Where an event made out of another came from (#255): the source's uid
 * (events.came_from_uid), the day's key where the new event was one day of
 * the source (events.came_from_key, as the source's skip stores it, else
 * null), and how (events.came_from_how: moved, kept, split, copy,
 * duplicate). Written by Events::cameFrom's callers on new rows; null on
 * every other row. Only adds the columns: no row is rewritten, and an event
 * made before this keeps no link. Skipped when already there.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sqlite = $driver === 'sqlite';
    $columns = $sqlite
        ? array_column($db->all('PRAGMA table_info(`events`)'), 'name')
        : array_column($db->all('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['events']), 'name');
    $added = [];
    foreach (['came_from_uid' => 'VARCHAR(255) NULL', 'came_from_key' => 'DATETIME NULL', 'came_from_how' => 'VARCHAR(12) NULL'] as $col => $type) {
        if (!in_array($col, $columns, true)) {
            $db->run('ALTER TABLE `events` ADD COLUMN `' . $col . '` ' . ($sqlite ? 'TEXT NULL' : $type));
            $added[] = 'events.' . $col;
        }
    }
    return $added === [] ? 'events.came_from_uid, came_from_key and came_from_how already present' : 'added ' . implode(', ', $added);
};
