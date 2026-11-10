<?php

declare(strict_types=1);

// A change between timed and all-day on a Google calendar, through the app
// to the edit harness's stand-in (GoogleStand), which answers as the probe
// of 2026-11-10 found Google answers: a PATCH's start and end merged into
// the held ones, a field sent as null removed, and 400 "Invalid start
// time." for a start or end that would hold a date and a clock at once
// (3.18 to 3.31). Each change must go through and leave the resource in the
// new form alone: a series both ways (10:00 Los Angeles to all-day, all-day
// to 00:00 UTC), a single event both ways, one ordinary day of a timed
// series made all-day, and the undo of that day (back to timed). The app's
// own request names the held form's fields as null; a move to another
// clock in the same form names none (GoogleWriter::overForm; decisions.md,
// "A start or end sent over a resource Google holds names the held form's
// fields as null when the form changes"). A refusal is a named check, never
// a throw. google_series_undo is off, as 1.5.5 ships it. Invented data.

use BetterCal\EditHarness\GoogleStand;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $start = static function () use ($db): array {
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        return [$u, GoogleStand::make($db, $d->undo, $u), $d];
    };
    $words = static function (callable $fn): ?string {
        try {
            $fn();
            return null;
        } catch (HttpError $x) {
            return $x->errorCode . ': ' . $x->getMessage();
        }
    };
    $gid = static fn(int $id): string => (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
    // The start and end of the app's last PATCH to $id, as sent.
    $sent = static function (GoogleStand $s, string $id): ?array {
        $last = null;
        foreach ($s->fake->requests as $r) {
            if ($r['method'] === 'PATCH' && rawurldecode(basename((string) $r['path'])) === $id) {
                $last = $r['body'];
            }
        }
        return $last === null ? null : array_intersect_key($last, ['start' => true, 'end' => true]);
    };
    // What the stand-in holds as the resource's start and end.
    $held = static fn(GoogleStand $s, string $id): array => [$s->fake->events[$id]['start'] ?? null, $s->fake->events[$id]['end'] ?? null];
    $dates = static fn(string $d, string $e): array => ['start' => ['date' => $d, 'dateTime' => null, 'timeZone' => null], 'end' => ['date' => $e, 'dateTime' => null, 'timeZone' => null]];
    $clocks = static fn(string $s, string $e, string $z): array => ['start' => ['dateTime' => $s, 'timeZone' => $z, 'date' => null], 'end' => ['dateTime' => $e, 'timeZone' => $z, 'date' => null]];

    $seriesLa = ['title' => 'Sample form', 'start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'allDay' => false, 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=10'];
    $seriesAllDay = ['title' => 'Sample form', 'start' => '2027-03-01', 'end' => '2027-03-02', 'allDay' => true, 'rrule' => 'FREQ=WEEKLY;COUNT=10'];
    $first = '2027-03-01T18:00:00+00:00';
    foreach ([
        ['a series timed at 10:00 Los Angeles made all-day (3.19)', $seriesLa, ['scope' => 'all', 'instanceStart' => $first, 'start' => '2027-03-01', 'end' => '2027-03-02', 'allDay' => true],
            $dates('2027-03-01', '2027-03-02'), [['date' => '2027-03-01'], ['date' => '2027-03-02']]],
        ['an all-day series made timed at 00:00 UTC (3.25)', $seriesAllDay, ['scope' => 'all', 'instanceStart' => '2027-03-01T00:00:00+00:00', 'start' => '2027-03-01T00:00:00+00:00', 'end' => '2027-03-01T01:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC'],
            $clocks('2027-03-01T00:00:00+00:00', '2027-03-01T01:00:00+00:00', 'UTC'), [['dateTime' => '2027-03-01T00:00:00+00:00', 'timeZone' => 'UTC'], ['dateTime' => '2027-03-01T01:00:00+00:00', 'timeZone' => 'UTC']]],
        ['a single event timed made all-day (3.27)', ['rrule' => null] + $seriesLa, ['start' => '2027-03-01', 'end' => '2027-03-02', 'allDay' => true],
            $dates('2027-03-01', '2027-03-02'), [['date' => '2027-03-01'], ['date' => '2027-03-02']]],
        ['a single event all-day made timed (3.29)', ['rrule' => null] + $seriesAllDay, ['start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'allDay' => false, 'tzid' => $la],
            $clocks('2027-03-01T10:00:00-08:00', '2027-03-01T11:00:00-08:00', $la), [['dateTime' => '2027-03-01T10:00:00-08:00', 'timeZone' => $la], ['dateTime' => '2027-03-01T11:00:00-08:00', 'timeZone' => $la]]],
    ] as [$what, $make, $change, $wantSent, $wantHeld]) {
        scenario("google form nulls: $what", function () use ($db, $start, $words, $gid, $sent, $held, $what, $make, $change, $wantSent, $wantHeld): void {
            [$u, $s] = $start();
            try {
                $e = $s->events;
                $id = (int) $e->create($u, ['calendarId' => $s->calendarId] + $make)['eventId'];
                $g = $gid($id);
                checkEq("$what: the change goes through", null, $words(static function () use ($e, $u, $id, $change): void {
                    isset($change['scope']) ? planned($e, $u, $id, $change) : $e->patch($u, $id, $change);
                    $e->throwDeferred();
                }));
                checkEq("$what: the app sends the start and end with the held form's fields as null", $wantSent, $sent($s, $g));
                checkEq("$what: Google holds the new form alone", $wantHeld, $held($s, $g));
                checkEq("$what: the row here is in the new form", $change['allDay'] ? 1 : 0, (int) $db->scalar('SELECT all_day FROM events WHERE id = ?', [$id]));
            } finally {
                GoogleStand::forget($db, $u);
            }
        });
    }

    // One ordinary day of the timed series made all-day (3.31), then that undone: the day back to a clock over the
    // all-day record Google now holds for it (GoogleUndo, an edit's inverse in the form held).
    scenario('google form nulls: one day of a timed series made all-day, then undone', function () use ($db, $start, $words, $gid, $sent, $held, $seriesLa, $dates, $clocks, $la): void {
        [$u, $s] = $start();
        try {
            $e = $s->events;
            $id = (int) $e->create($u, ['calendarId' => $s->calendarId] + $seriesLa)['eventId'];
            $day = $gid($id) . '_20270322T170000Z';
            checkEq('one day made all-day (3.31): the change goes through', null, $words(static function () use ($e, $u, $id): void {
                $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-03-22T17:00:00+00:00', 'start' => '2027-03-22', 'end' => '2027-03-23', 'allDay' => true]);
                $e->throwDeferred();
            }));
            checkEq('one day made all-day (3.31): the app sends the start and end with the clock fields as null', $dates('2027-03-22', '2027-03-23'), $sent($s, $day));
            checkEq('one day made all-day (3.31): Google holds the day all-day alone, under its timed id', [['date' => '2027-03-22'], ['date' => '2027-03-23']], $held($s, $day));
            $undo = new \BetterCal\Domain\Undo($db, null, $s->writer);
            checkEq('one day made all-day, undone: the undo goes through', null, $words(static function () use ($undo, $u): void {
                $undo->undoLatest($u);
            }));
            checkEq('one day made all-day, undone: the app sends the start and end with the date field as null',
                $clocks('2027-03-22T10:00:00-07:00', '2027-03-22T11:00:00-07:00', $la), $sent($s, $day));
            checkEq('one day made all-day, undone: Google holds the day at its clock alone',
                [['dateTime' => '2027-03-22T10:00:00-07:00', 'timeZone' => $la], ['dateTime' => '2027-03-22T11:00:00-07:00', 'timeZone' => $la]], $held($s, $day));
        } finally {
            GoogleStand::forget($db, $u);
        }
    });

    // The control: a series moved to another clock, timed both sides, sends no null (a body no probe has sent).
    scenario('google form nulls: a series moved to another clock names no null', function () use ($db, $start, $words, $gid, $sent, $seriesLa, $first, $la): void {
        [$u, $s] = $start();
        try {
            $e = $s->events;
            $id = (int) $e->create($u, ['calendarId' => $s->calendarId] + $seriesLa)['eventId'];
            checkEq('a series moved to 11:00: the change goes through', null, $words(static function () use ($e, $u, $id, $first, $la): void {
                planned($e, $u, $id, ['scope' => 'all', 'instanceStart' => $first, 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00', 'allDay' => false, 'tzid' => $la]);
                $e->throwDeferred();
            }));
            checkEq('a series moved to 11:00: the start and end name no null',
                ['start' => ['dateTime' => '2027-03-01T11:00:00-08:00', 'timeZone' => $la], 'end' => ['dateTime' => '2027-03-01T12:00:00-08:00', 'timeZone' => $la]], $sent($s, $gid($id)));
        } finally {
            GoogleStand::forget($db, $u);
        }
    });
};
