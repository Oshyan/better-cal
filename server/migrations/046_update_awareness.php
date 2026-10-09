<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $hasTable = static function (string $table) use ($db, $driver): bool {
        if ($driver === 'sqlite') {
            return (int) $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) > 0;
        }
        return (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    };

    if (!$hasTable('app_update_state')) {
        $db->run($driver === 'sqlite'
            ? 'CREATE TABLE app_update_state (
                id INTEGER PRIMARY KEY,
                checked_at TEXT NULL,
                latest_version TEXT NULL,
                priority TEXT NULL,
                minimum_secure_version TEXT NULL,
                release_url TEXT NULL,
                released_at TEXT NULL,
                manifest_digest TEXT NULL,
                last_error TEXT NULL,
                revision INTEGER NOT NULL DEFAULT 0
            )'
            : 'CREATE TABLE app_update_state (
                id TINYINT UNSIGNED PRIMARY KEY,
                checked_at DATETIME NULL,
                latest_version VARCHAR(32) NULL,
                priority VARCHAR(16) NULL,
                minimum_secure_version VARCHAR(32) NULL,
                release_url VARCHAR(500) NULL,
                released_at DATETIME NULL,
                manifest_digest CHAR(64) NULL,
                last_error VARCHAR(1000) NULL,
                revision BIGINT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB');
    }
    if ($db->scalar('SELECT id FROM app_update_state WHERE id = 1') === null) {
        $db->insert('app_update_state', ['id' => 1]);
    }
    if (!$hasTable('user_update_notices')) {
        $db->run($driver === 'sqlite'
            ? 'CREATE TABLE user_update_notices (
                user_id INTEGER PRIMARY KEY,
                dismissed_version TEXT NULL,
                notified_secure_floor TEXT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )'
            : 'CREATE TABLE user_update_notices (
                user_id BIGINT UNSIGNED PRIMARY KEY,
                dismissed_version VARCHAR(32) NULL,
                notified_secure_floor VARCHAR(32) NULL,
                CONSTRAINT fk_update_notice_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB');
    }
    return 'update awareness state ready';
};
