<?php

declare(strict_types=1);

use BetterCal\Domain\Undo;
use BetterCal\Infra\Db;

/**
 * Every journal entry carries the format it was written in
 * (mutations.format), and an entry from an older format is log only.
 *
 * An entry already here when this runs was written before it, in format 1;
 * the column's default stays 1 so that holds for every row it is added to.
 * Undo::record writes the current format (Undo::JOURNAL_FORMAT) on every
 * new entry. Every format-1 entry is then made log only, the way 058 and
 * 064 made the entries they could not carry over: its snapshots dropped
 * (before_json, after_json, the pieces in mutation_rows) and its details
 * saying why (notUndoable, Undo::OLDER_FORMAT). Its touches and calendar
 * references stay, as for any entry made log only. Undo::undoById refuses
 * such an entry whatever the force flag, the bare Undo passes over it and
 * Activity shows the reason; nothing reads its grounds or snapshots again.
 *
 * Why not rewrite them: two rewrites of 1.5.4's grounds into today's form
 * failed review (memory over a large deletion's pieces; an old series move
 * whose keys looked current), and an entry is undoable for 7 days. A change
 * to a stored form is a version bump, not compatibility code.
 *
 * Set-based statements, no row read into PHP: memory stays flat whatever
 * the journal's size. An entry already log only keeps its own details (a
 * reason 064 gave stays). Idempotent: a second run finds the column and no
 * format-1 entry with snapshots.
 */
return static function (Db $db): string {
    $sqlite = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $columns = $sqlite
        ? array_column($db->all('PRAGMA table_info(`mutations`)'), 'name')
        : array_column($db->all('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['mutations']), 'name');
    $added = !in_array('format', $columns, true);
    if ($added) {
        $db->run('ALTER TABLE `mutations` ADD COLUMN `format` ' . ($sqlite ? 'INTEGER NOT NULL DEFAULT 1' : 'SMALLINT UNSIGNED NOT NULL DEFAULT 1'));
    }
    $hasPieces = $sqlite
        ? (int) $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'mutation_rows'") > 0
        : (int) $db->scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutation_rows'") > 0;
    $older = 'format < ' . Undo::JOURNAL_FORMAT . ' AND (before_json IS NOT NULL OR after_json IS NOT NULL)';
    $made = 0;
    $db->tx(function () use ($db, $hasPieces, $older, &$made): void {
        if ($hasPieces) {
            $db->run("DELETE FROM mutation_rows WHERE mutation_id IN (SELECT id FROM mutations WHERE $older)");
        }
        // Its reason in its details: an object gains notUndoable, none (or an empty list) becomes one; anything else is left as stored.
        $db->run("UPDATE mutations SET details_json = CASE
                WHEN details_json IS NULL OR details_json = '[]' OR details_json = '{}' THEN JSON_OBJECT('notUndoable', ?)
                WHEN JSON_VALID(details_json) THEN CASE WHEN LOWER(JSON_TYPE(details_json)) = 'object' THEN JSON_SET(details_json, '$.notUndoable', ?) ELSE details_json END
                ELSE details_json END
            WHERE $older", [Undo::OLDER_FORMAT, Undo::OLDER_FORMAT]);
        $made = $db->run("UPDATE mutations SET before_json = NULL, after_json = NULL WHERE $older")->rowCount();
    });
    return ($added ? 'added mutations.format' : 'mutations.format already present') . "; $made entries from an earlier format made log only";
};
