<?php

declare(strict_types=1);

// Migration 064 over rows and undo entries made by the version before it
// (the last commit on the library's expander), through that version's own
// domain methods: the keys it wrote, moved onto the app's own expander
// everywhere they are stored. A weekday list at 02:30 in Los Angeles kept
// the spring gap's 03:30 from 15 Mar 2027 on (10:30Z; 09:30Z now), and a
// yearly 02:30 in Berlin read 31 Oct 2027's repeated clock at its second
// instant (01:30Z; 00:30Z now).
// Each block is a scenario(), with an account of its own: a throw fails
// that block, and the next still runs. Each run of the migration counts
// what is left to move in the whole database, so a block cut off before
// its own run leaves rows the next block's run moves too, and that block's
// counts fail with it.

use BetterCal\Domain\Activity;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

return static function (Db $db): void {
    $migrate = require dirname(__DIR__, 2) . '/migrations/064_series_keys_one_expander.php';
    $source = oldSource('bed10fe');
    try {
        $la = static fn(string $name, string $title, string $rrule): array => ['series' => $name, 'title' => $title, 'start' => '2027-03-01T02:30:00-08:00',
            'end' => '2027-03-01T03:30:00-08:00', 'tzid' => 'America/Los_Angeles', 'rrule' => $rrule];
        $row = static fn(int $id): array => array_values($db->one('SELECT recurrence_instance_utc, start_utc FROM events WHERE id = ?', [$id]) ?? []);
        $editedOf = static fn(int $master): array => array_map(static fn(array $r): array => [$r['recurrence_instance_utc'], $r['start_utc'], $r['title']],
            $db->all('SELECT recurrence_instance_utc, start_utc, title FROM events WHERE recurrence_parent_id = ? ORDER BY recurrence_instance_utc', [$master]));
        $skipsOf = static fn(int $master): array => json_decode((string) $db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$master]), true) ?? [];
        // What the calendar shows of a series over [from, to): each occurrence's start and title.
        $shown = static function (int $master, string $from, string $to) use ($db): array {
            return array_map(static fn(array $o): array => [Time::toDb(new \DateTimeImmutable($o['start'])), $o['title']], array_values(array_filter(
                domains($db)->events->window((int) $db->scalar('SELECT user_id FROM events WHERE id = ?', [$master]), Time::fromDb($from), Time::fromDb($to), null, null, false),
                static fn(array $o): bool => (int) $o['eventId'] === $master || (int) ($o['seriesId'] ?? 0) === $master)));
        };

        // The chain the last three blocks make, each in an account of its own.
        $chain = static fn(string $calendar, array $between = []): array => array_merge([
            ['calendar' => $calendar, 'name' => 'Sample removed calendar'],
            $la('chain', 'Sample chain', 'FREQ=DAILY;BYDAY=MO,WE,FR;COUNT=30') + ['calendar' => $calendar],
            ['edit' => 'chain', 'at' => '2027-03-17T10:30:00Z', 'fields' => ['title' => 'Sample chain, edited']],
            ['edit' => 'chain', 'at' => '2027-03-17T10:30:00Z', 'fields' => ['start' => '2027-03-17T12:00:00-07:00', 'end' => '2027-03-17T13:00:00-07:00']],
            ['entry' => 'clock'],
        ], $between, [['deleteCalendar' => $calendar], ['entry' => 'deleted']]);
        $dayOf = static fn(int $master): array => array_values($db->one('SELECT recurrence_instance_utc, start_utc, override_fields FROM events WHERE recurrence_parent_id = ?', [$master]) ?? []);

        // A run cut off after its first series, and run again: what moved
        // isn't moved twice, the rest is.
        scenario('migration 064: a run cut off, and run again', function () use ($db, $migrate, $source, $la, $row, $editedOf, $skipsOf, $shown): void {
            $old = oldVersion($source, [
                $la('first', 'Sample first', 'FREQ=DAILY;BYDAY=MO,WE,FR'),
                ['edit' => 'first', 'at' => '2027-03-17T10:30:00Z', 'fields' => ['title' => 'Sample first, edited']],
                $la('second', 'Sample second', 'FREQ=DAILY;BYDAY=MO,WE,FR'),
                ['skip' => 'second', 'at' => '2027-03-19T10:30:00Z'],
            ]);
            check('migration 064 tests: the rows are made by the older version\'s code', str_starts_with($old['from'], $source . '/'), $old['from']);
            [$first, $second] = [$old['series']['first'], $old['series']['second']];
            $firstEdit = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$first]);
            checkEq('migration 064: the older version keyed the days after the gap at 03:30', [['2027-03-17 10:30:00', '2027-03-17 10:30:00'], ['2027-03-19 10:30:00']],
                [$row($firstEdit), $skipsOf($second)]);
            $committed = [];
            try {
                $migrate($db, false, static function (int $id) use (&$committed): void {
                    $committed[] = $id;
                    throw new \RuntimeException('cut off');
                });
            } catch (\RuntimeException) {
            }
            checkEq('migration 064: cut off after its first series, that one has moved and the next has not', [[$first], ['2027-03-17 09:30:00', '2027-03-17 09:30:00'], ['2027-03-19 10:30:00']],
                [$committed, $row($firstEdit), $skipsOf($second)]);
            $migrate($db);
            checkEq('migration 064: run again, the rest moves and what moved stays where it went', [['2027-03-17 09:30:00', '2027-03-17 09:30:00'], ['2027-03-19 09:30:00']],
                [$row($firstEdit), $skipsOf($second)]);
        });

        // F1: a series' last occurrence, by COUNT and by UNTIL, edited and
        // skipped; its first, edited, is an occurrence of both readings.
        scenario("migration 064: a series' last occurrence", function () use ($db, $migrate, $source, $la, $row, $editedOf, $skipsOf, $shown): void {
            $old = oldVersion($source, [
                $la('countEdited', 'Sample count, edited last', 'FREQ=DAILY;BYDAY=MO,WE,FR;COUNT=9'),
                ['edit' => 'countEdited', 'at' => '2027-03-19T10:30:00Z', 'fields' => ['title' => 'Sample last day']],
                ['edit' => 'countEdited', 'at' => '2027-03-01T10:30:00Z', 'fields' => ['title' => 'Sample first day']],
                $la('countSkipped', 'Sample count, skipped last', 'FREQ=DAILY;BYDAY=MO,WE,FR;COUNT=9'),
                ['skip' => 'countSkipped', 'at' => '2027-03-19T10:30:00Z'],
                $la('untilEdited', 'Sample until, edited last', 'FREQ=DAILY;BYDAY=MO,WE,FR;UNTIL=20270320T000000Z'),
                ['edit' => 'untilEdited', 'at' => '2027-03-19T10:30:00Z', 'fields' => ['title' => 'Sample last day']],
                $la('untilSkipped', 'Sample until, skipped last', 'FREQ=DAILY;BYDAY=MO,WE,FR;UNTIL=20270320T000000Z'),
                ['skip' => 'untilSkipped', 'at' => '2027-03-19T10:30:00Z'],
                ['series' => 'fold', 'title' => 'Sample fold', 'start' => '2026-10-31T02:30:00+01:00', 'end' => '2026-10-31T03:30:00+01:00', 'tzid' => 'Europe/Berlin', 'rrule' => 'FREQ=YEARLY;COUNT=2'],
                ['edit' => 'fold', 'at' => '2027-10-31T01:30:00Z', 'fields' => ['title' => 'Sample last fold']],
            ]);
            $s = $old['series'];
            $before = [$editedOf($s['countEdited']), $skipsOf($s['countSkipped']), $editedOf($s['untilEdited']), $skipsOf($s['untilSkipped']), $editedOf($s['fold'])];
            checkEq('migration 064: the older version keyed each last day by its own reading', [
                [['2027-03-01 10:30:00', '2027-03-01 10:30:00', 'Sample first day'], ['2027-03-19 10:30:00', '2027-03-19 10:30:00', 'Sample last day']], ['2027-03-19 10:30:00'],
                [['2027-03-19 10:30:00', '2027-03-19 10:30:00', 'Sample last day']], ['2027-03-19 10:30:00'], [['2027-10-31 01:30:00', '2027-10-31 01:30:00', 'Sample last fold']],
            ], $before);
            $dry = $migrate($db, true);
            checkEq('migration 064: the dry run counts every last day and writes nothing', [true, $before], [
                str_starts_with($dry, 'dry run: 5 series, 3 edited-day keys and 2 skips would move; '),
                [$editedOf($s['countEdited']), $skipsOf($s['countSkipped']), $editedOf($s['untilEdited']), $skipsOf($s['untilSkipped']), $editedOf($s['fold'])],
            ]);
            $migrate($db);
            checkEq('migration 064: each last day moves to its occurrence, the first stays', [
                [['2027-03-01 10:30:00', '2027-03-01 10:30:00', 'Sample first day'], ['2027-03-19 09:30:00', '2027-03-19 09:30:00', 'Sample last day']], ['2027-03-19 09:30:00'],
                [['2027-03-19 09:30:00', '2027-03-19 09:30:00', 'Sample last day']], ['2027-03-19 09:30:00'], [['2027-10-31 00:30:00', '2027-10-31 00:30:00', 'Sample last fold']],
            ], [$editedOf($s['countEdited']), $skipsOf($s['countSkipped']), $editedOf($s['untilEdited']), $skipsOf($s['untilSkipped']), $editedOf($s['fold'])]);
            checkEq('migration 064: the last day shows once, edited, and a skipped last day stays skipped', [
                [['2027-03-17 09:30:00', 'Sample count, edited last'], ['2027-03-19 09:30:00', 'Sample last day']], [['2027-03-17 09:30:00', 'Sample count, skipped last']],
                [['2027-03-17 09:30:00', 'Sample until, edited last'], ['2027-03-19 09:30:00', 'Sample last day']], [['2027-03-17 09:30:00', 'Sample until, skipped last']],
            ], [$shown($s['countEdited'], '2027-03-17 00:00:00', '2027-03-21 00:00:00'), $shown($s['countSkipped'], '2027-03-17 00:00:00', '2027-03-21 00:00:00'),
                $shown($s['untilEdited'], '2027-03-17 00:00:00', '2027-03-21 00:00:00'), $shown($s['untilSkipped'], '2027-03-17 00:00:00', '2027-03-21 00:00:00')]);
            $again = $migrate($db, true);
            check('migration 064: run again, nothing is left to move', str_starts_with($again, 'dry run: 0 series, 0 edited-day keys and 0 skips would move'), $again);
        });

        // F2: skips and an edited day made, then the edited day deleted, by
        // the older version; after the migration each undo puts back keys
        // of the new reading.
        scenario('migration 064: undo after the move', function () use ($db, $migrate, $source, $la, $row, $editedOf, $skipsOf, $shown): void {
            $old = oldVersion($source, [
                $la('history', 'Sample history', 'FREQ=DAILY;BYDAY=MO,WE,FR;COUNT=30'),
                ['skip' => 'history', 'at' => '2027-03-17T10:30:00Z'],
                ['edit' => 'history', 'at' => '2027-03-22T10:30:00Z', 'fields' => ['title' => 'Sample history, edited']],
                ['skip' => 'history', 'at' => '2027-03-22T10:30:00Z'],
                ['entry' => 'deleted'],
                ['skip' => 'history', 'at' => '2027-03-19T10:30:00Z'],
                ['entry' => 'last'],
            ]);
            [$u, $history] = [$old['user'], $old['series']['history']];
            $said = $migrate($db);
            check('migration 064: the journal\'s entries of the series are rewritten', str_contains($said, '; 4 undo entries rewritten, 0 made log-only'), $said);
            checkEq('migration 064: the live skips move', ['2027-03-17 09:30:00', '2027-03-22 09:30:00', '2027-03-19 09:30:00'], $skipsOf($history));
            domains($db)->undo->undoById($u, $old['entries']['last']);
            checkEq('migration 064: undoing the last skip brings back that day only', [['2027-03-15 09:30:00', 'Sample history'], ['2027-03-19 09:30:00', 'Sample history']],
                $shown($history, '2027-03-15 00:00:00', '2027-03-21 00:00:00'));
            domains($db)->undo->undoById($u, $old['entries']['deleted']);
            checkEq('migration 064: undoing the deleted edited day brings it back at its key, its inherited time with it', [
                [['2027-03-22 09:30:00', '2027-03-22 09:30:00', 'Sample history, edited']], ['2027-03-17 09:30:00'],
            ], [$editedOf($history), $skipsOf($history)]);
            // A reload (domains afresh) and another edit, on the new reading.
            $d = domains($db);
            $d->events->patch($u, $history, ['scope' => 'this', 'instanceStart' => '2027-03-24T09:30:00Z', 'title' => 'Sample history, later']);
            checkEq('migration 064: after the undos and another edit, each day shows once', [
                ['2027-03-19 09:30:00', 'Sample history'], ['2027-03-22 09:30:00', 'Sample history, edited'], ['2027-03-24 09:30:00', 'Sample history, later'], ['2027-03-26 09:30:00', 'Sample history'],
            ], $shown($history, '2027-03-17 00:00:00', '2027-03-27 00:00:00'));
        });

        // A key the new reading has no occurrence for: under a COUNT, the
        // old reading of a rule naming its hour left out the gap day and
        // ended a day later; the new one keeps the gap day at 03:30, so the
        // old last day is none of its days. An entry that keeps that key
        // can't put back an identity, so it is log-only and says why.
        scenario('migration 064: a key the new reading has no occurrence for', function () use ($db, $migrate, $source, $la, $row, $editedOf, $skipsOf, $shown): void {
            $old = oldVersion($source, [
                ['series' => 'hours', 'title' => 'Sample hours', 'start' => '2027-03-11T02:30:00-08:00', 'end' => '2027-03-11T03:00:00-08:00', 'tzid' => 'America/Los_Angeles',
                    'rrule' => 'FREQ=DAILY;BYHOUR=2;COUNT=5'],
                ['window' => 'before', 'from' => '2027-03-13T00:00:00Z', 'to' => '2027-03-18T00:00:00Z'],
                ['edit' => 'hours', 'at' => '2027-03-16T09:30:00Z', 'fields' => ['title' => 'Sample hours, edited']],
                ['entry' => 'edit'],
            ]);
            [$u, $hours, $entry] = [$old['user'], $old['series']['hours'], $old['entries']['edit']];
            checkEq('migration 064: the older version left out the gap day and went on a day', [['2027-03-13 10:30:00', 'Sample hours'], ['2027-03-15 09:30:00', 'Sample hours'], ['2027-03-16 09:30:00', 'Sample hours']],
                $old['windows']['before']);
            $said = $migrate($db);
            $code = null;
            $message = '';
            try {
                domains($db)->undo->undoById($u, $entry);
            } catch (HttpError $e) {
                [$code, $message] = [$e->errorCode, $e->getMessage()];
            }
            $listed = array_values(array_filter((new Activity($db))->list($u)['entries'], static fn(array $e): bool => $e['id'] === $entry));
            checkEq('migration 064: an entry with a key no occurrence has now is log-only, and undo says why', [true, [['2027-03-16 09:30:00', '2027-03-16 09:30:00', 'Sample hours, edited']], 'not_undoable', true, false],
                [str_contains($said, ', 1 made log-only'), $editedOf($hours), $code, str_contains($message, 'upgrade to 1.5.0'), $listed[0]['undoable'] ?? null]);
        });

        // A day's title edited, then its clock (an entry with the day only),
        // then its calendar deleted: no series row is live, and the clock
        // edit's entry keeps none, so its days read by the series as the
        // journal kept it. Undo the delete, then the clock edit: the day is
        // back at the new reading's 02:30, owning its title only.
        scenario('migration 064: a day edited, then its calendar deleted', function () use ($db, $migrate, $source, $shown, $chain, $dayOf): void {
            $old = oldVersion($source, $chain('gone'));
            [$u, $series, $entries] = [$old['user'], $old['series']['chain'], $old['entries']];
            $said = $migrate($db);
            check('migration 064: a deleted calendar\'s entries are rewritten, none made log-only', str_contains($said, ', 0 made log-only') && !str_contains($said, '; 0 undo entries'), $said);
            domains($db)->undo->undoById($u, $entries['deleted']);
            checkEq('migration 064: undoing the calendar delete brings the day back at the new key, at noon', ['2027-03-17 09:30:00', '2027-03-17 19:00:00', '["time","title"]'], $dayOf($series));
            domains($db)->undo->undoById($u, $entries['clock']);
            checkEq('migration 064: undoing the clock edit then puts back the new reading\'s 02:30, the title alone owned', [
                ['2027-03-17 09:30:00', '2027-03-17 09:30:00', '["title"]'], [['2027-03-17 09:30:00', 'Sample chain, edited']],
            ], [$dayOf($series), $shown($series, '2027-03-17 00:00:00', '2027-03-18 00:00:00')]);
        });

        // The rule changed between the clock edit and the delete: the delete
        // keeps the later rule, whose reading leaves the old key where it is;
        // the clock edit reads by the rule of its own time.
        scenario('migration 064: the rule changed between', function () use ($db, $migrate, $source, $shown, $chain, $dayOf): void {
            $old = oldVersion($source, $chain('changed', [['change' => 'chain', 'fields' => ['rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE,FR;COUNT=30']], ['entry' => 'rule']]));
            [$u, $series, $entries] = [$old['user'], $old['series']['chain'], $old['entries']];
            $said = $migrate($db);
            check('migration 064: with the rule changed between, none made log-only', str_contains($said, ', 0 made log-only'), $said);
            foreach (['deleted', 'rule', 'clock'] as $name) {
                domains($db)->undo->undoById($u, $entries[$name]);
            }
            checkEq('migration 064: undoing the delete, the rule change and the clock edit puts the day back at 02:30 on the first rule', [
                'FREQ=DAILY;BYDAY=MO,WE,FR;COUNT=30', ['2027-03-17 09:30:00', '2027-03-17 09:30:00', '["title"]'], [['2027-03-17 09:30:00', 'Sample chain, edited']],
            ], [(string) $db->scalar('SELECT rrule FROM events WHERE id = ?', [$series]), $dayOf($series), $shown($series, '2027-03-17 00:00:00', '2027-03-18 00:00:00')]);
        });

        // The same chain with every other entry past the undo window (the
        // retention pass clears their snapshots): no snapshot and no live row
        // says the series' rule, so the clock edit is log-only and says why.
        scenario('migration 064: the chain past the undo window', function () use ($db, $migrate, $source, $chain): void {
            $old = oldVersion($source, $chain('pruned'));
            [$u, $entries] = [$old['user'], $old['entries']];
            $db->run('UPDATE mutations SET created_at = ? WHERE user_id = ? AND id <> ?',
                [gmdate('Y-m-d H:i:s', time() - (Activity::UNDO_DAYS + 1) * 86400), $u, $entries['clock']]);
            (new Activity($db))->prune();
            $said = $migrate($db);
            $code = null;
            $message = '';
            try {
                domains($db)->undo->undoById($u, $entries['clock']);
            } catch (HttpError $e) {
                [$code, $message] = [$e->errorCode, $e->getMessage()];
            }
            checkEq('migration 064: an entry whose series no snapshot keeps is log-only, and undo says why', [true, 'not_undoable', true],
                [str_contains($said, ', 1 made log-only'), $code, str_contains($message, 'no longer on record')]);
        });
    } finally {
        removeTree($source);
    }
};
