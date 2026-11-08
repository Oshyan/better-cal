<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * A series change half done at Google keeps the rows here as they were,
 * with what hangs off them (tags, people, trip links: the shape Undo keeps),
 * in google_pending.local_json, so the rows Finish and Put back bring back
 * take their ids and all of that back (GoogleSeries::reattach). A row made
 * before this has none, and recovers as before. Skipped when already there.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sqlite = $driver === 'sqlite';
    $columns = $sqlite
        ? array_column($db->all('PRAGMA table_info(`google_pending`)'), 'name')
        : array_column($db->all('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['google_pending']), 'name');
    if (in_array('local_json', $columns, true)) {
        return 'google_pending.local_json already present';
    }
    $db->run('ALTER TABLE `google_pending` ADD COLUMN `local_json` ' . ($sqlite ? 'TEXT NULL' : 'LONGTEXT NULL'));
    return 'added google_pending.local_json: the rows a half-done Google change started from';
};
