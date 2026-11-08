<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * A series change half done at Google records the rows its own stores
 * made here (google_pending.made_json, their ids, journaled as each store
 * runs), so Finish removes exactly those before the change's part here is
 * written, and keeps every other row its snapshot lacks (a day edited here
 * since). A row made before this has none, and its Finish removes nothing
 * the snapshot lacks. Skipped when already there.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sqlite = $driver === 'sqlite';
    $columns = $sqlite
        ? array_column($db->all('PRAGMA table_info(`google_pending`)'), 'name')
        : array_column($db->all('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['google_pending']), 'name');
    if (in_array('made_json', $columns, true)) {
        return 'google_pending.made_json already present';
    }
    $db->run('ALTER TABLE `google_pending` ADD COLUMN `made_json` ' . ($sqlite ? 'TEXT NULL' : 'LONGTEXT NULL'));
    return 'added google_pending.made_json: the rows a half-done Google change\'s own stores made here, for Finish';
};
