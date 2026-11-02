<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

return static function (Db $db): string {
    $sqlite = $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $exists = $sqlite
        ? $db->scalar("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'mutation_rows'") !== null
        : (int) $db->scalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['mutation_rows']) > 0;
    if (!$exists) {
        // Rows a journal entry made or removed, kept apart from it in pieces
        // when there are many (a calendar of tens of thousands of events
        // deleted): in the entry's own row they were more than the database
        // takes in one statement, and the delete lost its undo (#191). Each
        // piece is one table's rows, from before or after the change.
        $db->run($sqlite
            ? 'CREATE TABLE mutation_rows (
                mutation_id INTEGER NOT NULL,
                seq INTEGER NOT NULL,
                side VARCHAR(6) NOT NULL,
                table_name VARCHAR(40) NOT NULL,
                rows_json TEXT NOT NULL,
                PRIMARY KEY (mutation_id, seq),
                FOREIGN KEY (mutation_id) REFERENCES mutations(id) ON DELETE CASCADE
              )'
            : 'CREATE TABLE mutation_rows (
                mutation_id BIGINT UNSIGNED NOT NULL,
                seq INT UNSIGNED NOT NULL,
                side VARCHAR(6) NOT NULL,
                table_name VARCHAR(40) NOT NULL,
                rows_json MEDIUMTEXT NOT NULL,
                PRIMARY KEY (mutation_id, seq),
                CONSTRAINT fk_mutation_rows_mutation FOREIGN KEY (mutation_id) REFERENCES mutations(id) ON DELETE CASCADE
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    return 'A calendar of any size can be deleted and undone: its events are kept for undo in pieces.';
};
