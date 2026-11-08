<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * A series change half done at Google keeps its part here (the plan's
 * writes and the request's fields: tags, people, reminders, flags) in
 * google_pending.plan_json, so Finish writes it as the change would have,
 * with the same writer. A row made before this has none, and finishes at
 * Google alone, as before. Skipped when already there.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sqlite = $driver === 'sqlite';
    $columns = $sqlite
        ? array_column($db->all('PRAGMA table_info(`google_pending`)'), 'name')
        : array_column($db->all('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['google_pending']), 'name');
    if (in_array('plan_json', $columns, true)) {
        return 'google_pending.plan_json already present';
    }
    $db->run('ALTER TABLE `google_pending` ADD COLUMN `plan_json` ' . ($sqlite ? 'TEXT NULL' : 'LONGTEXT NULL'));
    return 'added google_pending.plan_json: the part here of a half-done Google change, for Finish';
};
