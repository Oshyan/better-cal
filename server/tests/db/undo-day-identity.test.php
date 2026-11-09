<?php

declare(strict_types=1);

// Two rules an undo keeps, checked on what it leaves at once, before any
// poll could mend it. A day is its series and its occurrence where the
// series has it now (GoogleUndo::dayAt): a redo of an ordinary day's
// delete after the series' time changed skips that day at Google, here
// and in its own entry's ground alike. And a day follows its series in
// every field it does not own, tags and people included
// (Feeds::followSeries): undoing a series' tags and people takes a day
// made since, owning only its notes, back to the series' links, and a day
// owning its tags keeps them, on a calendar here and on a Google calendar.
// Google is the edit harness's stand-in (GoogleStand). Each block is a
// scenario(): a throw fails that block, and the next still runs.

use BetterCal\Domain\Settings;
use BetterCal\Domain\Undo;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    $weekly = ['title' => 'Pizza night', 'start' => '2027-01-04T09:00:00+00:00', 'end' => '2027-01-04T10:00:00+00:00', 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=8'];

    scenario('a redo of an ordinary Google day\'s delete after the series moved skips the day where it is now', function () use ($db, $weekly): void {
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        try {
            $e = $s->events;
            $undo = new Undo($db, null, $s->writer);
            $id = (int) $e->create($u, ['calendarId' => $s->calendarId] + $weekly)['eventId'];
            $s->poll();
            $e->deleteEvent($u, $id, 'this', '2027-01-11T09:00:00+00:00');
            $e->throwDeferred();
            $undo->undoById($u, lastEntry($db, $u));
            $redo = lastEntry($db, $u);
            planned($e, $u, $id, ['scope' => 'all', 'start' => '2027-01-04T11:00:00+00:00', 'end' => '2027-01-04T12:00:00+00:00']);
            $e->throwDeferred();
            $s->fake->requests = [];
            $undo->undoById($u, $redo);
            $entry = lastEntry($db, $u);
            $sent = array_values(array_filter(array_map(static fn(array $r): string => $r['method'] . ' ' . basename((string) $r['path']), $s->fake->requests), static fn(string $c): bool => str_starts_with($c, 'DELETE')));
            $window = $e->window($u, new \DateTimeImmutable('2027-01-11T00:00:00Z'), new \DateTimeImmutable('2027-01-12T00:00:00Z'), [$s->calendarId], null, true);
            $ground = array_map(static fn(array $t): array => [(string) $t['row_key'], (string) $t['col']], $db->all('SELECT row_key, col FROM mutation_touches WHERE mutation_id = ?', [$entry]));
            checkEq('redo after a move: Google is sent the delete of the day where it is now', ['DELETE fake000001_20270111T110000Z'], $sent);
            checkEq('redo after a move: the series here skips that day, not the old one', ['2027-01-11 11:00:00'], json_decode((string) $db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$id]), true));
            checkEq('redo after a move: the calendar no longer shows the day', [], $window);
            checkEq('redo after a move: its entry stands on that day\'s skip', [["events:$id", 'skip:2027-01-11 11:00:00']], $ground);
        } finally {
            GoogleStand::forget($db, $u);
        }
    });

    foreach (['here', 'google'] as $mode) {
        scenario("undo of a series' tags and people ($mode): a day made since follows them back, a day owning its tags keeps them", function () use ($db, $weekly, $mode): void {
            $u = freshUser($db);
            seriesUndo($db, $u, false);
            $d = domains($db);
            $s = $mode === 'google' ? GoogleStand::make($db, $d->undo, $u) : null;
            try {
                [$e, $undo, $cal] = $s !== null ? [$s->events, new Undo($db, null, $s->writer), $s->calendarId] : [$d->events, $d->undo, Settings::defaultCalendarId($db, $u)];
                $id = (int) $e->create($u, ['calendarId' => $cal, 'tagNames' => ['Sample A'], 'personNames' => ['Alex Example']] + $weekly)['eventId'];
                planned($e, $u, $id, ['scope' => 'all', 'tagNames' => ['Sample B'], 'personNames' => ['Sam Sample']]);
                $e->throwDeferred();
                $change = lastEntry($db, $u);
                $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-11T09:00:00+00:00', 'description' => 'Sample own notes']);
                $e->throwDeferred();
                $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-18T09:00:00+00:00', 'tagNames' => ['Sample C']]);
                $e->throwDeferred();
                $undo->undoById($u, $change);
                $links = static fn(string $at): array => [
                    implode(',', array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id JOIN events e ON e.id = et.event_id WHERE e.recurrence_parent_id = ? AND e.recurrence_instance_utc = ? ORDER BY t.name', [$id, $at]), 'name')),
                    implode(',', array_column($db->all('SELECT p.name FROM event_people ep JOIN people p ON p.id = ep.person_id JOIN events e ON e.id = ep.event_id WHERE e.recurrence_parent_id = ? AND e.recurrence_instance_utc = ? ORDER BY p.name', [$id, $at]), 'name')),
                ];
                checkEq("undo of a series' tags and people ($mode): the day owning only its notes takes the series' tags and people back", ['Sample A', 'Alex Example'], $links('2027-01-11 09:00:00'));
                checkEq("undo of a series' tags and people ($mode): the day owning its tags keeps them, and follows the series' people", ['Sample C', 'Alex Example'], $links('2027-01-18 09:00:00'));
            } finally {
                if ($s !== null) {
                    GoogleStand::forget($db, $u);
                }
            }
        });
    }
};
