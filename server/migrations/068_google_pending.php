<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

/**
 * A series change sent to a Google calendar is several requests, and a
 * transaction can't span them (#172, stage two: GoogleSeries). Before the
 * first request the app records what it is about to do: the series as they
 * should end up (targets), the series as they were (befores, with every
 * exception's complete state, so a step that removes one at Google can be
 * reversed), and each step with its state and the etag it left. The row
 * goes when the change completes; a change that stops half way keeps it,
 * and Review lists it with Finish and Put back. Skipped when already there.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sqlite = $driver === 'sqlite';
    $exists = $sqlite
        ? $db->scalar("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'google_pending'") !== null
        : (int) $db->scalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['google_pending']) > 0;
    if ($exists) {
        return 'google_pending already present';
    }
    $db->run($sqlite
        ? 'CREATE TABLE google_pending (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            calendar_id INTEGER NOT NULL,
            event_id INTEGER NULL,
            title TEXT NOT NULL DEFAULT \'\',
            summary TEXT NOT NULL DEFAULT \'\',
            status TEXT NOT NULL DEFAULT \'running\',
            error TEXT NULL,
            targets_json TEXT NOT NULL,
            befores_json TEXT NOT NULL,
            steps_json TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
        : 'CREATE TABLE google_pending (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            calendar_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED NULL,
            title VARCHAR(500) NOT NULL DEFAULT \'\',
            summary VARCHAR(1000) NOT NULL DEFAULT \'\',
            status VARCHAR(16) NOT NULL DEFAULT \'running\',
            error TEXT NULL,
            targets_json LONGTEXT NOT NULL,
            befores_json LONGTEXT NOT NULL,
            steps_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_google_pending_user (user_id, status),
            CONSTRAINT fk_google_pending_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    return 'added google_pending: the record of a series change under way at Google';
};
