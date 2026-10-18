<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

return static function (Db $db): string {
    $sqlite = $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $exists = $sqlite
        ? in_array('created_by_session_hash', array_column($db->all('PRAGMA table_info(calendars)'), 'name'), true)
        : (int) $db->scalar('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['calendars', 'created_by_session_hash']) > 0;
    if (!$exists) {
        $db->run('ALTER TABLE calendars ADD COLUMN created_by_session_hash ' . ($sqlite ? 'TEXT' : 'CHAR(64)') . ' NULL');
    }
    // NULL grandfathers existing owner-authorized feeds. Token bindings and
    // feeds already awaiting review retain their existing authorization.
    return 'Browser subscription creator tracking is ready; existing feeds keep their authorization.';
};
