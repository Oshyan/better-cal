<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

return static function (Db $db): string {
    $sqlite = $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $exists = $sqlite
        ? $db->scalar("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'mutation_touches'") !== null
        : (int) $db->scalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['mutation_touches']) > 0;
    if (!$exists) {
        // What each journal entry changed: a row ("events:12",
        // "calendar_folders:5|3") and the column changed, or "*" for a row
        // made or removed. An undo is stale when a later entry changed the
        // same column of the same row, or made or removed that row (#187).
        // Kept apart from the snapshots, which a Google calendar's entries
        // leave empty.
        $db->run($sqlite
            ? 'CREATE TABLE mutation_touches (
                mutation_id INTEGER NOT NULL,
                row_key VARCHAR(160) NOT NULL,
                col VARCHAR(64) NOT NULL,
                PRIMARY KEY (mutation_id, row_key, col),
                FOREIGN KEY (mutation_id) REFERENCES mutations(id) ON DELETE CASCADE
              )'
            : 'CREATE TABLE mutation_touches (
                mutation_id BIGINT UNSIGNED NOT NULL,
                row_key VARCHAR(160) NOT NULL,
                col VARCHAR(64) NOT NULL,
                PRIMARY KEY (mutation_id, row_key, col),
                KEY idx_touch_row (row_key, mutation_id),
                CONSTRAINT fk_touch_mutation FOREIGN KEY (mutation_id) REFERENCES mutations(id) ON DELETE CASCADE
              ) ENGINE=InnoDB');
        if ($sqlite) {
            $db->run('CREATE INDEX idx_touch_row ON mutation_touches (row_key, mutation_id)');
        }
    }
    return 'Undo knows what each change touched, to tell when a later one changed the same things.';
};
