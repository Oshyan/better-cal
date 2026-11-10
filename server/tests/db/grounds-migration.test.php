<?php

declare(strict_types=1);

// Migration 077: what a journal entry stands on, in one form. An entry
// recorded before 1.5.5 kept a day's field on the day's own row
// ("events:<row>", "title"); 1.5.5 records it on the series' row as the
// day's ground ("events:<series>", "day:<occurrence>|title"), and a later
// change is found only under the keys an entry recorded. Tests that make
// both changes with one version never meet the old form, so each block
// here writes it as 1.5.4 did (directly, or through 1.5.4's own code),
// runs the migration, then changes the same field with today's code and
// asks for an unforced undo of the old entry.
// Each block is a scenario(): a throw fails that block, and the next still runs.

use BetterCal\Domain\Settings;
use BetterCal\Domain\Undo;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    $migrate = require dirname(__DIR__, 2) . '/migrations/077_grounds_one_form.php';
    // An entry's ground, as 1.5.4 stored it: these touches and no other.
    $legacy = static function (int $entry, array $touches) use ($db): void {
        $db->run('DELETE FROM mutation_touches WHERE mutation_id = ?', [$entry]);
        foreach ($touches as [$row, $col]) {
            $db->insert('mutation_touches', ['mutation_id' => $entry, 'row_key' => $row, 'col' => $col]);
        }
    };
    $ground = static function (int $entry) use ($db): array {
        $out = array_map(static fn(array $t): array => [(string) $t['row_key'], (string) $t['col']],
            $db->all('SELECT row_key, col FROM mutation_touches WHERE mutation_id = ? ORDER BY row_key, col', [$entry]));
        return $out;
    };
    $weekly = static function (Db $db, int $u, array $more = []): int {
        return (int) domains($db)->events->create($u, ['calendarId' => Settings::defaultCalendarId($db, $u), 'title' => 'Pizza night',
            'start' => '2027-01-04T18:00:00+00:00', 'end' => '2027-01-04T19:00:00+00:00', 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=8'] + $more)['eventId'];
    };
    $day = '2027-01-11 18:00:00';

    scenario('077: a day renamed before the upgrade, renamed again after it: the old entry is stale', function () use ($db, $migrate, $legacy, $ground, $weekly, $day): void {
        $u = freshUser($db);
        $d = domains($db);
        $series = $weekly($db, $u);
        $d->events->patch($u, $series, ['scope' => 'this', 'instanceStart' => '2027-01-11T18:00:00+00:00', 'title' => 'Pizza night at home']);
        $row = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$series]);
        $d->events->patch($u, $row, ['title' => 'Pizza night, the earlier rename']);
        $old = lastEntry($db, $u);
        // As 1.5.4 recorded a rename of a day owning its title: the row and its column.
        $legacy($old, [["events:$row", 'title']]);
        $migrate($db);
        checkEq('077: the rename\'s ground is the day\'s title on its series', [["events:$series", "day:$day|title"]], $ground($old));
        $d->events->patch($u, $row, ['title' => 'Pizza night, the newer rename']);
        checkEq('077: an unforced undo of the older rename is refused as stale, and the newer title stands',
            ['stale_undo', 'Pizza night, the newer rename'], [refusal(fn() => $d->undo->undoById($u, $old)), $db->scalar('SELECT title FROM events WHERE id = ?', [$row])]);
        $migrate($db);
        checkEq('077: a second run changes nothing', [["events:$series", "day:$day|title"]], $ground($old));
    });

    scenario('077: a series\' own touches stay; its whole skips column becomes the dates it skipped', function () use ($db, $migrate, $legacy, $ground, $weekly): void {
        $u = freshUser($db);
        $d = domains($db);
        $series = $weekly($db, $u);
        planned($d->events, $u, $series, ['scope' => 'all', 'title' => 'Pizza night, every week']);
        $renamed = lastEntry($db, $u);
        $legacy($renamed, [["events:$series", 'title']]);
        $d->events->deleteEvent($u, $series, 'this', '2027-01-18T18:00:00+00:00');
        $skipped = lastEntry($db, $u);
        $legacy($skipped, [["events:$series", 'exdates_json']]);
        $migrate($db);
        checkEq('077: a series\' title touch stays as stored', [["events:$series", 'title']], $ground($renamed));
        checkEq('077: a series\' skips column becomes the date skipped', [["events:$series", 'skip:2027-01-18 18:00:00']], $ground($skipped));
    });

    scenario('077: a day made whole, its tags and its mark on the series become the day\'s owned fields', function () use ($db, $migrate, $legacy, $ground, $weekly, $day): void {
        $u = freshUser($db);
        $d = domains($db);
        $series = $weekly($db, $u, ['tagNames' => ['Sample A']]);
        $d->events->patch($u, $series, ['scope' => 'this', 'instanceStart' => '2027-01-11T18:00:00+00:00', 'title' => 'Pizza night at home']);
        $made = lastEntry($db, $u);
        $row = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$series]);
        $tag = (int) $db->scalar('SELECT tag_id FROM event_tags WHERE event_id = ?', [$row]);
        // As 1.5.4 recorded a day's row made: the row whole, its links, the marks they leave.
        $legacy($made, [["events:$row", '*'], ["events:$series", '~'], ["event_tags:$row|$tag", '*'], ["events:$row", '+'], ["tags:$tag", '+']]);
        $migrate($db);
        $g = $ground($made);
        checkEq('077: no key of the day\'s own row is left, nor the old mark',
            [], array_values(array_filter($g, static fn(array $t): bool => $t[0] === "events:$row" || $t[0] === "event_tags:$row|$tag" || $t[1] === '~')));
        checkEq('077: the day\'s title and tags, on its series', [true, true],
            [in_array(["events:$series", "day:$day|title"], $g, true), in_array(["events:$series", "day:$day|tags"], $g, true)]);
    });

    scenario('077: a day\'s touch with no snapshot to read it by becomes the whole series', function () use ($db, $migrate, $legacy, $ground, $weekly): void {
        $u = freshUser($db);
        $d = domains($db);
        $series = $weekly($db, $u);
        $d->events->patch($u, $series, ['scope' => 'this', 'instanceStart' => '2027-01-11T18:00:00+00:00', 'title' => 'Pizza night at home']);
        $row = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$series]);
        // A log-only line, as an entry whose snapshots Google holds keeps none.
        $entry = $d->undo->record($u, 'event', $row, 'update', null, null, 'Renamed Pizza night at home');
        $legacy($entry, [["events:$row", 'title']]);
        $migrate($db);
        checkEq('077: refused as stale against anything later on the series', [["events:$series", '*']], $ground($entry));
        checkEq('077: which every later touch of the series conflicts with', [true, true],
            [Undo::conflicts('*', 'day:2027-01-25 18:00:00|description', 'events'), Undo::conflicts('*', 'title', 'events')]);
    });

    // An entry already in today's form is never derived again: a clock
    // change that carried a skipped day records the skip's move, and the
    // migration leaves it so, where deriving it again without the move
    // made it a skip removed and a skip added, and the day's undo stale.
    scenario('077: a skipped day carried by a clock change keeps its move, and its undo goes through (Google)', function () use ($db, $migrate, $legacy, $ground): void {
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        try {
            $e = $s->events;
            $undo = new Undo($db, null, $s->writer);
            $id = (int) $e->create($u, ['calendarId' => $s->calendarId, 'title' => 'Pizza night', 'start' => '2027-01-04T09:00:00+00:00', 'end' => '2027-01-04T10:00:00+00:00',
                'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=8'])['eventId'];
            $s->poll();
            $e->deleteEvent($u, $id, 'this', '2027-01-11T09:00:00+00:00');
            $e->throwDeferred();
            $deleted = lastEntry($db, $u);
            planned($e, $u, $id, ['scope' => 'all', 'instanceStart' => '2027-01-04T09:00:00+00:00', 'start' => '2027-01-04T11:00:00+00:00', 'end' => '2027-01-04T12:00:00+00:00']);
            $e->throwDeferred();
            $moved = lastEntry($db, $u);
            [$deletedWas, $movedWas] = [$ground($deleted), $ground($moved)];
            check('077 (a carried skip): the clock change recorded the skip\'s move', in_array(["events:$id", 'move:2027-01-11 09:00:00>2027-01-11 11:00:00'], $movedWas, true), json_encode($movedWas));
            // As the version before this one stored both (the review's cross-version shape): the same touches, written directly.
            $legacy($deleted, $deletedWas);
            $legacy($moved, $movedWas);
            $migrate($db);
            checkEq('077 (a carried skip): both entries\' grounds are as they were', [$deletedWas, $movedWas], [$ground($deleted), $ground($moved)]);
            $undo->undoById($u, $deleted);
            $e->throwDeferred();
            $window = $e->window($u, new \DateTimeImmutable('2027-01-11T00:00:00Z'), new \DateTimeImmutable('2027-01-12T00:00:00Z'), [$s->calendarId], null, true);
            checkEq('077 (a carried skip): the day\'s undo goes through, the day back at 11:00 and skipped nowhere',
                [1, '2027-01-11T11:00:00', []],
                [count($window), substr((string) ($window[0]['start_utc'] ?? $window[0]['start'] ?? ''), 0, 19), json_decode((string) ($db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$id]) ?? '[]'), true)]);
        } finally {
            GoogleStand::forget($db, $u);
        }
    });

    scenario('077: through 1.5.4\'s own code, a day renamed twice, then once more after the upgrade', function () use ($db, $migrate): void {
        $old = oldVersion(oldSource('v1.5.4'), [
            ['series' => 'pizza', 'title' => 'Pizza night', 'start' => '2027-01-04T18:00:00+00:00', 'end' => '2027-01-04T19:00:00+00:00', 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=8'],
            ['edit' => 'pizza', 'at' => '2027-01-11T18:00:00Z', 'fields' => ['title' => 'Pizza night at home']],
            ['edit' => 'pizza', 'at' => '2027-01-11T18:00:00Z', 'fields' => ['title' => 'Pizza night, the earlier rename']],
            ['entry' => 'renamed'],
        ]);
        check('077: the old entry was made by 1.5.4\'s code', !str_contains((string) $old['from'], dirname(__DIR__, 2) . '/src/'), (string) $old['from']);
        [$u, $series, $entry] = [(int) $old['user'], (int) $old['series']['pizza'], (int) $old['entries']['renamed']];
        $row = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$series]);
        $migrate($db);
        $d = domains($db);
        $d->events->patch($u, $row, ['title' => 'Pizza night, the newer rename']);
        checkEq('077 (1.5.4\'s entry): an unforced undo is refused as stale, and the newer title stands',
            ['stale_undo', 'Pizza night, the newer rename'], [refusal(fn() => $d->undo->undoById($u, $entry)), $db->scalar('SELECT title FROM events WHERE id = ?', [$row])]);
    });
};
