<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $has = $driver === 'sqlite'
        ? count(array_filter($db->all('PRAGMA table_info(app_update_state)'), static fn(array $c): bool => $c['name'] === 'newest_version')) > 0
        : (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['app_update_state', 'newest_version']
        ) > 0;
    // Behind a signing-key change (#194) latest_version is the newest
    // release this copy verifies, a step towards the newest published one;
    // that one is kept here so the notice can say the step is one. Written
    // on every good check, equal to latest_version when nothing was walked
    // past, so it is never stale.
    if (!$has) {
        $db->run('ALTER TABLE app_update_state ADD COLUMN newest_version ' . ($driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(32) NULL'));
    }
    return 'The update notice says when the release it names is a step towards a newer one.';
};
