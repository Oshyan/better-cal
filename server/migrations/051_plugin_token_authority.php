<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

return static function (Db $db): string {
    $sqlite = $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $exists = $sqlite
        ? in_array('changed_by_token_id', array_column($db->all('PRAGMA table_info(plugins)'), 'name'), true)
        : (int) $db->scalar('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['plugins', 'changed_by_token_id']) > 0;
    if (!$exists) {
        $db->run('ALTER TABLE plugins ADD COLUMN changed_by_token_id ' . ($sqlite ? 'INTEGER' : 'BIGINT UNSIGNED') . ' NULL');
    }
    // NULL leaves existing plugins as they are: nothing recorded which
    // credential enabled them before this change.
    return 'Plugins now remember an API key that enables or configures them.';
};
