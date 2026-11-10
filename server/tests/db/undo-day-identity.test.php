<?php

declare(strict_types=1);

// Two rules an undo keeps, checked on what it leaves at once, before any
// poll could mend it. A day is its series and its occurrence where the
// journal says exactly where it is now (GoogleUndo::dayAt, Undo::dayNow):
// a redo of an ordinary day's delete after the series' time changed, the
// day holding no row and no skip then, is refused with nothing sent,
// never placed by its date. And a day follows its series in
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

    // Changed with the gate (1.5.5; decisions.md, "An undo acts on a day only where the journal says exactly where it is").
    // Before, this scenario asserted that the redo went through: Google was sent the delete of 11 Jan at 11:00
    // (..._20270111T110000Z), the series here skipped 2027-01-11 11:00:00, the calendar no longer showed the day, and
    // the redo's entry stood on that skip. That placed the day by its date. The day had no row and no skip when the
    // series' start moved, the planner's mapping for such a day is recorded nowhere, and placing it by date is right
    // at some clocks (here, two hours later on the same day) and a neighbouring day at others (a time across
    // midnight, a start moved a day, a weekday changed). So it is refused, forced or not, with nothing sent.
    scenario('a redo of an ordinary Google day\'s delete after the series moved is refused: nothing recorded where that day went', function () use ($db, $weekly): void {
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
            $deleted = lastEntry($db, $u);
            $undo->undoById($u, $deleted);
            $redo = lastEntry($db, $u);
            planned($e, $u, $id, ['scope' => 'all', 'start' => '2027-01-04T11:00:00+00:00', 'end' => '2027-01-04T12:00:00+00:00']);
            $e->throwDeferred();
            $s->fake->requests = [];
            $last = lastEntry($db, $u);
            $refused = [];
            foreach ([false, true] as $force) {
                try {
                    $undo->undoById($u, $redo, $force);
                    $refused[] = null;
                } catch (\BetterCal\Http\HttpError $error) {
                    $refused[] = [$error->errorCode, $error->extra['reason'] ?? null, $error->getMessage()];
                }
            }
            $words = "Can't redo \"" . (string) $db->scalar('SELECT summary FROM mutations WHERE id = ?', [$deleted]) . "\". This repeating event was moved to another time or day after that, and Better-Cal can no longer tell which of its days the change was on. Make the change again on the day you meant.";
            checkEq('redo after a move: refused, forced or not, in words that say what changed', [['not_undoable', 'day_unplaced', $words], ['not_undoable', 'day_unplaced', $words]], $refused);
            checkEq('redo after a move: Google is sent nothing of any kind', [], array_column($s->fake->requests, 'method'));
            checkEq('redo after a move: the series here skips no day, as before', null, $db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$id]));
            checkEq('redo after a move: the entry is still there to undo, and none was recorded', [0, $last], [(int) $db->scalar('SELECT undone FROM mutations WHERE id = ?', [$redo]), lastEntry($db, $u)]);
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

        // A day's tags and people are its own only when its own list says so; a row an undo keeps for a later change
        // shows the series' set where it doesn't own them, and an owned set goes back by its delta (review of 94693e30
        // to cb2c6082, R3). Judged on the calendar window, as the app shows the day.
        scenario("undo of the notes that made a day's row, kept for its place ($mode): the day still shows the series' tags and people; an owned set stays its own", function () use ($db, $weekly, $mode): void {
            $u = freshUser($db);
            seriesUndo($db, $u, false);
            $d = domains($db);
            $s = $mode === 'google' ? GoogleStand::make($db, $d->undo, $u) : null;
            try {
                [$e, $undo, $cal] = $s !== null ? [$s->events, new Undo($db, null, $s->writer), $s->calendarId] : [$d->events, $d->undo, Settings::defaultCalendarId($db, $u)];
                $day = static fn(string $at): array => $e->window($u, new \DateTimeImmutable($at . 'T00:00:00Z'), new \DateTimeImmutable($at . 'T23:59:59Z'), [$cal], null, true);
                $shown = static function (array $w): array {
                    $own = (array) ($w[0]['ownFields'] ?? []);
                    sort($own);
                    return [$own, (array) ($w[0]['tags'] ?? []), (array) ($w[0]['people'] ?? [])];
                };
                $id = (int) $e->create($u, ['calendarId' => $cal, 'tagNames' => ['Sample A'], 'personNames' => ['Alex Example']] + $weekly)['eventId'];
                if ($s !== null) {
                    $s->poll();
                }
                $this1 = static fn(string $at, array $set): mixed => $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $at . 'T09:00:00+00:00'] + $set);

                // The day doesn't own its tags: the copy made with its row is the series', never taken away.
                $this1('2027-01-11', ['description' => 'Sample day notes']);
                $e->throwDeferred();
                $notes = lastEntry($db, $u);
                $this1('2027-01-11', ['location' => 'Sample Hall']);
                $e->throwDeferred();
                $undo->undoById($u, $notes);
                checkEq("notes undone, day kept for its place ($mode): owns its place alone and shows the series' tags and people", [['location'], ['Sample A'], ['Alex Example']], $shown($day('2027-01-11')));
                if ($s !== null) {
                    $s->poll();
                    checkEq("notes undone, then a poll ($mode): the day still shows the series' tags and people", [['location'], ['Sample A'], ['Alex Example']], $shown($day('2027-01-11')));
                }

                // The control: a day owning its tags keeps its own set through an undo of its notes, never replaced
                // by the series' copy; an undo of the tags edit that made its row takes them back to the series'.
                $this1('2027-01-18', ['tagNames' => ['Sample C']]);
                $e->throwDeferred();
                $tags = lastEntry($db, $u);
                $this1('2027-01-18', ['description' => 'Sample day notes']);
                $e->throwDeferred();
                $notes = lastEntry($db, $u);
                $this1('2027-01-18', ['location' => 'Sample Hall']);
                $e->throwDeferred();
                $undo->undoById($u, $notes);
                checkEq("notes undone on a day owning its tags ($mode): its own tags stay, its people are the series'", [['location', 'tags'], ['Sample C'], ['Alex Example']], $shown($day('2027-01-18')));
                // A later change to the set the day owns, undone, is the earlier set, tags and people alike: the edit
                // of an existing day's row journals its links with the row.
                $this1('2027-01-18', ['tagNames' => ['Sample C', 'Sample D'], 'personNames' => ['Sam Sample']]);
                $e->throwDeferred();
                $more = lastEntry($db, $u);
                checkEq("a day owning its tags given one more tag and its own person ($mode)", [['location', 'people', 'tags'], ['Sample C', 'Sample D'], ['Sam Sample']], $shown($day('2027-01-18')));
                $undo->undoById($u, $more);
                checkEq("that undone ($mode): the earlier own set is back, and the people are the series' again", [['location', 'tags'], ['Sample C'], ['Alex Example']], $shown($day('2027-01-18')));
                $undo->undoById($u, $tags);
                checkEq("then the tags edit that made its row undone ($mode): the day kept for its place shows the series' tags again", [['location'], ['Sample A'], ['Alex Example']], $shown($day('2027-01-18')));
            } finally {
                if ($s !== null) {
                    GoogleStand::forget($db, $u);
                }
            }
        });
    }
};
