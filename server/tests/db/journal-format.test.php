<?php

declare(strict_types=1);

// Journal entries carry their format (mutations.format, migration 077). An
// entry in an older format than Undo::JOURNAL_FORMAT is log only: the
// migration drops its snapshots and pieces and gives its reason; its undo
// refuses with 409 not_undoable and the words, forced or not; Activity
// lists it as not undoable with the same reason; the bare Undo passes over
// it. An entry is made older here by setting its format to 1, as every
// row the migration finds is, then the migration is run. Invented data.

use BetterCal\Domain\Activity;
use BetterCal\Domain\Settings;
use BetterCal\Domain\Undo;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $migrate = require dirname(__DIR__, 2) . '/migrations/077_journal_format.php';
    $words = static function (callable $fn): array {
        try {
            $fn();
            return [];
        } catch (HttpError $e) {
            return [$e->status, $e->errorCode, $e->getMessage()];
        }
    };
    $older = [409, 'not_undoable', "Recorded by an earlier version of Better-Cal; it can't be undone after the upgrade."];
    $title = static fn(int $id): string => (string) $db->scalar('SELECT title FROM events WHERE id = ?', [$id]);
    $undone = static fn(int $entry): int => (int) $db->scalar('SELECT undone FROM mutations WHERE id = ?', [$entry]);
    $entryOf = static function (int $u, int $entry) use ($db): ?array {
        foreach ((new Activity($db))->list($u, [])['entries'] as $e) {
            if ($e['id'] === $entry) {
                return $e;
            }
        }
        return null;
    };

    scenario('journal format: an entry records the current format; the column defaults to 1 for the rows the migration finds', function () use ($db): void {
        $u = freshUser($db);
        $d = domains($db);
        $d->events->create($u, eventAt(Settings::defaultCalendarId($db, $u), 'Pizza night'));
        checkEq('a new entry is written in the current format', Undo::JOURNAL_FORMAT, (int) $db->scalar('SELECT format FROM mutations WHERE id = ?', [lastEntry($db, $u)]));
        $columns = array_column($db->all("SELECT COLUMN_NAME AS name, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutations' AND COLUMN_NAME = 'format'"), 'dflt', 'name');
        checkEq('the column defaults to 1', ['format' => '1'], $columns);
    });

    scenario('journal format: an older entry is made log only with its reason, refuses its undo forced or not, is listed with the words and passed over by the bare Undo', function () use ($db, $migrate, $words, $older, $title, $undone, $entryOf): void {
        $u = freshUser($db);
        $d = domains($db);
        $calendar = Settings::defaultCalendarId($db, $u);
        $made = (int) $d->events->create($u, eventAt($calendar, 'Pizza night'))['eventId'];
        $d->events->patch($u, $made, ['title' => 'Pizza night at Alex Example\'s']);
        $renamed = lastEntry($db, $u);
        // A deleted calendar keeps its rows in pieces: those go with the snapshots.
        $big = (int) $d->calendars->create($u, ['name' => 'Sample archive'])['id'];
        $d->events->create($u, eventAt($big, 'Sam Sample\'s party', '2026-12-10'));
        $d->calendars->delete($u, $big);
        $deleted = lastEntry($db, $u);
        $db->run('UPDATE mutations SET format = 1 WHERE user_id = ?', [$u]);
        $db->insert('mutation_rows', ['mutation_id' => $deleted, 'seq' => 999, 'side' => 'before', 'table_name' => 'events', 'rows_json' => '[]']);
        $line = $migrate($db);
        check('the migration says what it did', str_contains($line, 'made log only'), $line);
        $row = $db->one('SELECT before_json, after_json, details_json FROM mutations WHERE id = ?', [$renamed]);
        checkEq('the older rename is log only, its reason in its details', [null, null, $older[2]],
            [$row['before_json'], $row['after_json'], json_decode((string) $row['details_json'], true)['notUndoable'] ?? null]);
        checkEq('the older deletion\'s pieces are gone, its touches stay', [0, true],
            [(int) $db->scalar('SELECT COUNT(*) FROM mutation_rows WHERE mutation_id = ?', [$deleted]), (int) $db->scalar('SELECT COUNT(*) FROM mutation_touches WHERE mutation_id = ?', [$deleted]) > 0]);
        checkEq('a second run changes nothing', true, str_contains($migrate($db), '0 entries'));

        checkEq('unforced and forced: 409 not_undoable with the words, the title as it is, the entry not undone', [$older, $older, 'Pizza night at Alex Example\'s', 0],
            [$words(fn() => $d->undo->undoById($u, $renamed)), $words(fn() => $d->undo->undoById($u, $renamed, true)), $title($made), $undone($renamed)]);
        $listed = $entryOf($u, $renamed);
        checkEq('Activity: not undoable, with the same words', [false, $older[2]], [$listed['undoable'] ?? null, $listed['notUndoable'] ?? null]);

        // A newer change: the bare Undo takes it back, then passes over the older entries.
        $d->events->patch($u, $made, ['description' => 'Bring a salad']);
        $newer = lastEntry($db, $u);
        checkEq('Activity: the newer entry is undoable, with no reason', [true, null], [$entryOf($u, $newer)['undoable'] ?? null, $entryOf($u, $newer)['notUndoable'] ?? null]);
        $d->undo->undoLatest($u);
        checkEq('the bare Undo takes back the newer change', 1, $undone($newer));
        checkEq('then it passes over the older entries: nothing to undo, nothing undone', ['nothing_to_undo', 0, 'Pizza night at Alex Example\'s'],
            [refusal(fn() => $d->undo->undoLatest($u)), $undone($renamed), $title($made)]);
    });

    scenario('journal format: the bare Undo passes over an older entry to a change below it', function () use ($db, $undone, $title): void {
        $u = freshUser($db);
        $d = domains($db);
        $calendar = Settings::defaultCalendarId($db, $u);
        $first = (int) $d->events->create($u, eventAt($calendar, 'Sam Sample\'s party'))['eventId'];
        $second = (int) $d->events->create($u, eventAt($calendar, 'Pizza night', '2026-12-09'))['eventId'];
        $d->events->patch($u, $first, ['title' => 'Sam Sample\'s birthday']);
        $below = lastEntry($db, $u);
        $d->events->patch($u, $second, ['title' => 'Pizza night, moved']);
        $older = lastEntry($db, $u);
        $db->run('UPDATE mutations SET format = 1 WHERE id = ?', [$older]);
        $d->undo->undoLatest($u);
        checkEq('the older entry stays; the change below it in today\'s format is undone', [0, 'Pizza night, moved', 1, 'Sam Sample\'s party'],
            [$undone($older), $title($second), $undone($below), $title($first)]);
    });
};
