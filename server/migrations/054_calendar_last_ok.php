<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

return static function (Db $db): string {
    $sqlite = $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $exists = $sqlite
        ? in_array('last_ok_at', array_column($db->all('PRAGMA table_info(calendars)'), 'name'), true)
        : (int) $db->scalar('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['calendars', 'last_ok_at']) > 0;
    if (!$exists) {
        $db->run('ALTER TABLE calendars ADD COLUMN last_ok_at ' . ($sqlite ? 'TEXT' : 'DATETIME') . ' NULL');
    }
    // A feed whose last check worked last worked then. One that's failing now
    // has no record of its last success, and says so rather than guess.
    $n = $db->run("UPDATE calendars SET last_ok_at = last_polled_at WHERE last_ok_at IS NULL AND last_poll_status = 'ok' AND last_polled_at IS NOT NULL")->rowCount();
    return 'Feeds remember when they last updated (' . $n . ' filled from their last check).';
};
