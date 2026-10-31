<?php

declare(strict_types=1);

use BetterCal\Infra\Db;

return static function (Db $db): string {
    $sqlite = $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $exists = $sqlite
        ? $db->scalar("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'mutation_touches'") !== null
        : (int) $db->scalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['mutation_touches']) > 0;
    if (!$exists) {
        // What each journal entry changed, row by row ("events:12",
        // "calendar_folders:5|3", "calendar_position:5"): an undo is stale
        // when a later entry changed any of the same (#187). Kept apart from
        // the snapshots, which a Google calendar's entries leave empty.
        $db->run($sqlite
            ? 'CREATE TABLE mutation_touches (
                mutation_id INTEGER NOT NULL,
                touch VARCHAR(191) NOT NULL,
                PRIMARY KEY (mutation_id, touch),
                FOREIGN KEY (mutation_id) REFERENCES mutations(id) ON DELETE CASCADE
              )'
            : 'CREATE TABLE mutation_touches (
                mutation_id BIGINT UNSIGNED NOT NULL,
                touch VARCHAR(191) NOT NULL,
                PRIMARY KEY (mutation_id, touch),
                KEY idx_touch (touch, mutation_id),
                CONSTRAINT fk_touch_mutation FOREIGN KEY (mutation_id) REFERENCES mutations(id) ON DELETE CASCADE
              ) ENGINE=InnoDB');
        if ($sqlite) {
            $db->run('CREATE INDEX idx_touch ON mutation_touches (touch, mutation_id)');
        }
    }
    return 'Undo knows what each change touched, to tell when a later one changed the same things.';
};
