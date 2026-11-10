<?php

declare(strict_types=1);

// A day row's stored Google id is an identity (audit-155-identity, rule
// point 3, fix list items 6 and 7): the series id, the master's form and
// the day's key, derived again whenever the row is placed, a form change
// at a key that stays included. A series timed at 00:00 UTC made all-day
// keeps each day on its key while every instance id changes form
// (`<id>_20270111T000000Z` becomes `<id>_20270111`), so a row that kept the
// old id sent its next "this event" edit, and the undo of an earlier one,
// to an id Google no longer answers. Each block ends on invariant I1 as raw
// SQL (shares nothing with the product). Google is the edit harness's
// stand-in (GoogleStand); google_series_undo is off, as 1.5.5 ships it.
// The days here are a row holding only a tag and a renamed day, never an
// edited day holding a Google field through the whole-series change nor a
// skip, so nothing here leans on the prediction of the master write (R1);
// the one block that has a renamed day through the change accepts either
// outcome the rule allows and pins that neither reaches the timed id. The
// undo of a tag given before the change is where the day's id now
// (GoogleUndo::dayAt) shows on its own: the undo finds the day's row by
// that id. Invented data.

use BetterCal\Domain\Undo;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    $timed = ['title' => 'Sample weekly', 'start' => '2027-01-04T00:00:00+00:00', 'end' => '2027-01-04T01:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
    $allDay = ['title' => 'Sample weekly', 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true, 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
    $toAllDay = ['scope' => 'all', 'instanceStart' => '2027-01-04T00:00:00+00:00', 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true];
    $toTimed = ['scope' => 'all', 'instanceStart' => '2027-01-04T00:00:00+00:00', 'start' => '2027-01-04T00:00:00+00:00', 'end' => '2027-01-04T01:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC'];
    $day = '2027-01-11T00:00:00+00:00';

    // Invariant I1 (audit-155-identity, "Invariants over all data"): every live day row with a Google id, whose master
    // is live and has one, stores the id of its key under its master's form. Raw SQL, one account's rows.
    $i1 = static function (int $u) use ($db): array {
        return $db->all("SELECT d.id, d.google_event_id, m.google_event_id AS series, m.all_day, d.recurrence_instance_utc
            FROM events d
            JOIN events m ON m.id = d.recurrence_parent_id AND m.deleted_at IS NULL AND m.google_event_id IS NOT NULL
            WHERE d.user_id = ? AND d.deleted_at IS NULL
              AND d.recurrence_instance_utc IS NOT NULL
              AND d.google_event_id IS NOT NULL
              AND d.google_event_id <> CONCAT(m.google_event_id, '_',
                    CASE WHEN m.all_day = 1 THEN DATE_FORMAT(d.recurrence_instance_utc, '%Y%m%d')
                         ELSE DATE_FORMAT(d.recurrence_instance_utc, '%Y%m%dT%H%i%sZ') END)", [$u]);
    };
    // The app's writes to the stand-in, as "METHOD id".
    $writes = static fn(GoogleStand $s): array => array_values(array_filter(array_map(static fn(array $r): string => $r['method'] . ' ' . rawurldecode(basename((string) $r['path'])), $s->fake->requests),
        static fn(string $c): bool => !str_starts_with($c, 'GET')));
    $start = static function () use ($db): array {
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        return [$u, GoogleStand::make($db, $d->undo, $u)];
    };
    // A call that must go through, its refusal's words (null when it went through), so a refusal is a named check
    // rather than a throw that ends the block.
    $words = static function (callable $fn): ?string {
        try {
            $fn();
            return null;
        } catch (HttpError $x) {
            return $x->errorCode . ': ' . $x->getMessage();
        }
    };
    $series = static fn(int $id): string => (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
    $dayRow = static fn(int $id): ?array => $db->one("SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = '2027-01-11 00:00:00' AND deleted_at IS NULL", [$id]);

    foreach ([['timed at 00:00 UTC made all-day', $timed, $toAllDay, '20270111T000000Z', '20270111'],
        ['all-day made timed at 00:00 UTC', $allDay, $toTimed, '20270111', '20270111T000000Z']] as [$what, $from, $flip, $was, $now]) {
        scenario("google day identity: a day holding only a tag, its series $what, keeps its key and takes the new form's id; its next rename reaches that id",
            function () use ($db, $start, $from, $flip, $was, $now, $day, $series, $dayRow, $writes, $i1, $words): void {
                [$u, $s] = $start();
                try {
                    $e = $s->events;
                    $id = (int) $e->create($u, ['calendarId' => $s->calendarId] + $from)['eventId'];
                    $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $day, 'tagNames' => ['sample-day']]);
                    $e->throwDeferred();
                    $sid = $series($id);
                    checkEq("tag-only day ($was): its row stores the day's id in the series' form", $sid . '_' . $was, (string) ($dayRow($id)['google_event_id'] ?? ''));
                    planned($e, $u, $id, $flip);
                    $e->throwDeferred();
                    checkEq("tag-only day ($was to $now): right after the change the row stores the new form's id on the same key",
                        [$sid . '_' . $now, '2027-01-11 00:00:00'], [(string) ($dayRow($id)['google_event_id'] ?? ''), (string) ($dayRow($id)['recurrence_instance_utc'] ?? '')]);
                    checkEq("tag-only day ($was to $now): I1 holds after the change", [], $i1($u));
                    $s->fake->requests = [];
                    checkEq("tag-only day ($was to $now): the rename goes through", null, $words(static function () use ($e, $u, $id, $day): void {
                        $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $day, 'title' => 'Sample weekly, renamed']);
                        $e->throwDeferred();
                    }));
                    checkEq("tag-only day ($was to $now): the rename goes to the new form's id", ['PATCH ' . $sid . '_' . $now], $writes($s));
                    checkEq("tag-only day ($was to $now): the stand-in holds the renamed day under that id",
                        'Sample weekly, renamed', (string) ($s->fake->events[$sid . '_' . $now]['summary'] ?? ''));
                    checkEq("tag-only day ($was to $now): the row keeps its tag and the new title",
                        ['Sample weekly, renamed', ['sample-day']], [(string) ($dayRow($id)['title'] ?? ''),
                            array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ?', [(int) ($dayRow($id)['id'] ?? 0)]), 'name')]);
                    checkEq("tag-only day ($was to $now): I1 holds at the end", [], $i1($u));
                } finally {
                    GoogleStand::forget($db, $u);
                }
            });
    }

    // The same change made at Google and read by a poll: the poll places the row (Feeds::sync through Feeds::ofSeries),
    // the one writer between the change and the next edit.
    scenario('google day identity: a series made all-day at Google, read by a poll, leaves a day holding only a tag on the date-form id',
        function () use ($db, $start, $timed, $day, $series, $dayRow, $writes, $i1, $words): void {
            [$u, $s] = $start();
            try {
                $e = $s->events;
                $id = (int) $e->create($u, ['calendarId' => $s->calendarId] + $timed)['eventId'];
                $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $day, 'tagNames' => ['sample-day']]);
                $e->throwDeferred();
                $sid = $series($id);
                $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(GoogleStand::CALENDAR) . '/events/' . rawurlencode($sid);
                $answer = ($s->fake->transport())('PATCH', $url, ['Authorization: Bearer sample-access', 'X-Harness: remote'], ['start' => ['date' => '2027-01-04'], 'end' => ['date' => '2027-01-05']]);
                checkEq('made all-day at Google: the stand-in takes it', 200, (int) ($answer['status'] ?? 0));
                $s->poll();
                checkEq('made all-day at Google: after the poll the series is all-day and the tagged day keeps its key and takes the date-form id',
                    [1, $sid . '_20270111', '2027-01-11 00:00:00'],
                    [(int) $db->scalar('SELECT all_day FROM events WHERE id = ?', [$id]), (string) ($dayRow($id)['google_event_id'] ?? ''), (string) ($dayRow($id)['recurrence_instance_utc'] ?? '')]);
                checkEq('made all-day at Google: I1 holds after the poll', [], $i1($u));
                $s->fake->requests = [];
                checkEq('made all-day at Google: the next rename goes through', null, $words(static function () use ($e, $u, $id, $day): void {
                    $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $day, 'title' => 'Sample weekly, renamed']);
                    $e->throwDeferred();
                }));
                checkEq('made all-day at Google: the next rename goes to the date-form id', ['PATCH ' . $sid . '_20270111'], $writes($s));
                checkEq('made all-day at Google: I1 holds at the end', [], $i1($u));
            } finally {
                GoogleStand::forget($db, $u);
            }
        });

    scenario('google day identity: the undo of a rename made before the series was made all-day never goes to the timed id',
        function () use ($db, $start, $timed, $toAllDay, $day, $series, $dayRow, $writes, $i1): void {
            [$u, $s] = $start();
            try {
                $e = $s->events;
                $undo = new Undo($db, null, $s->writer);
                $id = (int) $e->create($u, ['calendarId' => $s->calendarId] + $timed)['eventId'];
                $sid = $series($id);
                $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $day, 'title' => 'Sample weekly, renamed']);
                $e->throwDeferred();
                $rename = lastEntry($db, $u);
                planned($e, $u, $id, $toAllDay);
                $e->throwDeferred();
                checkEq('undo across a flip: I1 holds after the change', [], $i1($u));
                $s->fake->requests = [];
                $refused = null;
                try {
                    $undo->undoById($u, $rename);
                } catch (HttpError $x) {
                    $refused = [$x->errorCode, $x->getMessage()];
                }
                $sent = $writes($s);
                // Two outcomes keep the rule, and which one comes depends on whether the change kept the renamed day
                // (review R1, fixed apart): refused as stale with its reason and nothing sent, or sent to the date-form
                // id alone. Never a request to the timed id, which Google no longer answers.
                check('undo across a flip: nothing is sent to the timed id', !in_array('PATCH ' . $sid . '_20270111T000000Z', $sent, true), implode(', ', $sent));
                if ($refused !== null) {
                    checkEq('undo across a flip: refused as stale with its reason, nothing sent',
                        [['stale_undo', 'Newer changes exist for this item; undoing would overwrite them'], []], [$refused, $sent]);
                } else {
                    checkEq('undo across a flip: it goes to the date-form id alone', ['PATCH ' . $sid . '_20270111'], $sent);
                    checkEq('undo across a flip: the day is back to the series\' title, here and at the stand-in',
                        ['Sample weekly', 'Sample weekly'], [(string) ($dayRow($id)['title'] ?? 'Sample weekly'), (string) ($s->fake->events[$sid . '_20270111']['summary'] ?? 'Sample weekly')]);
                }
                checkEq('undo across a flip: I1 holds at the end', [], $i1($u));
            } finally {
                GoogleStand::forget($db, $u);
            }
        });

    scenario('google day identity: the undo of a tag given before the series was made all-day finds the day by its id now',
        function () use ($db, $start, $timed, $toAllDay, $day, $dayRow, $writes, $i1, $words): void {
            [$u, $s] = $start();
            try {
                $e = $s->events;
                $undo = new Undo($db, null, $s->writer);
                $id = (int) $e->create($u, ['calendarId' => $s->calendarId] + $timed)['eventId'];
                $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $day, 'tagNames' => ['sample-day']]);
                $e->throwDeferred();
                $tag = lastEntry($db, $u);
                planned($e, $u, $id, $toAllDay);
                $e->throwDeferred();
                $s->fake->requests = [];
                checkEq('tag undo across a flip: the undo goes through', null, $words(static fn() => $undo->undoById($u, $tag)));
                $row = $dayRow($id);
                checkEq('tag undo across a flip: Google is sent nothing', [], $writes($s));
                checkEq('tag undo across a flip: the day no longer holds the tag',
                    [], $row === null ? [] : array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ?', [(int) $row['id']]), 'name'));
                checkEq('tag undo across a flip: I1 holds at the end', [], $i1($u));
            } finally {
                GoogleStand::forget($db, $u);
            }
        });
};
