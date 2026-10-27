<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

return static function (Db $db): string {
    $sqlite = $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $exists = $sqlite
        ? in_array('calendar_id', array_column($db->all('PRAGMA table_info(plugin_ranges)'), 'name'), true)
        : (int) $db->scalar('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['plugin_ranges', 'calendar_id']) > 0;
    if (!$exists) {
        $db->run('ALTER TABLE plugin_ranges ADD COLUMN calendar_id ' . ($sqlite ? 'INTEGER' : 'BIGINT UNSIGNED') . ' NULL');
        $db->run('CREATE INDEX idx_ranges_calendar ON plugin_ranges (plugin_id, calendar_id)');
    }
    // NULL keeps existing bands as they were: belonging to the plugin, not
    // to one of its calendars.
    return 'Plugin bands can belong to one of the plugin\'s calendars.';
};
