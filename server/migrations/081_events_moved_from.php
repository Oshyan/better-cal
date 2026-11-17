<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * Where an event moved off a repeating event came from (#255): when one day
 * of a repeating event is moved to another calendar, the event it becomes
 * stores the repeating event's uid (events.moved_from_uid) and the day's key
 * as the repeating event's skip stores it (events.moved_from_key). Both are
 * null on every other row. Only adds the columns: no row is rewritten, and a
 * day moved before this keeps no link. Skipped when already there.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sqlite = $driver === 'sqlite';
    $columns = $sqlite
        ? array_column($db->all('PRAGMA table_info(`events`)'), 'name')
        : array_column($db->all('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['events']), 'name');
    $added = [];
    if (!in_array('moved_from_uid', $columns, true)) {
        $db->run('ALTER TABLE `events` ADD COLUMN `moved_from_uid` ' . ($sqlite ? 'TEXT NULL' : 'VARCHAR(255) NULL'));
        $added[] = 'events.moved_from_uid';
    }
    if (!in_array('moved_from_key', $columns, true)) {
        $db->run('ALTER TABLE `events` ADD COLUMN `moved_from_key` ' . ($sqlite ? 'TEXT NULL' : 'DATETIME NULL'));
        $added[] = 'events.moved_from_key';
    }
    return $added === [] ? 'events.moved_from_uid and moved_from_key already present' : 'added ' . implode(', ', $added);
};
