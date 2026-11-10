<?php

declare(strict_types=1);

// Rule 1, provider identity (review R1 of cb2c6082..63a96467): Google names
// an instance of a series by the series id and the instance's original start
// in the master's own form, `<id>_<UTC basic time>Z` for a timed series and
// `<id>_<YYYYMMDD>` for an all-day one, while the rows here key it by a UTC
// instant (the date at 00:00 UTC when all-day). The two coincide for a timed
// occurrence at midnight UTC while Google's identities differ.
//
// What a person must see, from the spec (series-model.md, "Exceptions on a
// master change"; the plan's own words "keeps its place and notes" and
// "stays skipped"): when a whole-series change turns a timed series into an
// all-day one or back, an edited day keeps its own notes and place and a
// skipped day stays skipped, on the same calendar dates, in what Google holds
// after the write, in the rows here and in the calendar window. Every block
// runs with google_series_undo off and makes no Undo call: these are forward
// edits. Each block is a scenario(): a throw fails that block and the next
// still runs. Google is the edit harness's stand-in (GoogleStand), whose
// occurrences come from the harness's own evaluator. Every expected value
// below is written from the spec; nothing in server/src computes one.
// Invented data.

use BetterCal\EditHarness\Driver;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\EditHarness\Rng;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    $notes = 'Bring the sample checklist';
    $place = 'Sample Room';

    /**
     * One identity change over a weekly series of four with an edited day (its own notes and place) and a skipped
     * day. $c: name, create (the series), edit and skip (the instanceStart of each), body (the whole-series change),
     * allDay (the series' kind after it), edited and skipped (their calendar dates, the same before and after),
     * window (the span read), and, for a series made timed, clock and zone (where each day lands).
     */
    $run = static function (array $c) use ($db, $notes, $place): void {
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        $name = $c['name'];
        try {
            $e = $s->events;
            $id = (int) $e->create($u, ['calendarId' => $s->calendarId, 'title' => 'Sample weekly'] + $c['create'])['eventId'];
            $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $c['edit'], 'description' => $notes, 'location' => $place]);
            $e->throwDeferred();
            $e->deleteEvent($u, $id, 'this', $c['skip']);
            $e->throwDeferred();
            $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
            $s->fake->requests = [];

            // The plan, before anything is written: it says the edited day keeps its notes and place and the skipped day stays skipped.
            $plan = $e->previewPatch($u, $id, $c['body']);
            $days = $plan['days'] ?? [];
            $editedDay = array_values(array_filter($days, static fn(array $x): bool => ($x['kind'] ?? '') === 'edited'))[0] ?? null;
            $skippedDay = array_values(array_filter($days, static fn(array $x): bool => ($x['kind'] ?? '') === 'skipped'))[0] ?? null;
            check("$name: the plan keeps the edited day's notes and place on its date",
                $editedDay !== null && ($editedDay['outcome'] ?? '') !== 'deleted' && ($editedDay['to'] ?? '') === $c['edited']
                    && array_intersect(['location', 'description'], $editedDay['kept'] ?? []) === ['location', 'description'],
                json_encode($editedDay));
            check("$name: the plan keeps the skipped day skipped on its date",
                $skippedDay !== null && ($skippedDay['to'] ?? '') === $c['skipped'] && ($skippedDay['outcomeWords'] ?? '') === 'stays skipped',
                json_encode($skippedDay));

            $e->patch($u, $id, $c['body'] + ['plan' => ['hash' => $plan['hash'], 'choices' => []]]);
            $e->throwDeferred();

            // The recorded `asked` state: what the change asked Google to hold.
            $details = json_decode((string) $db->scalar('SELECT details_json FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$u]), true);
            $asked = $details['google']['before'][0]['asked'] ?? [];
            $askedEdited = array_values(array_filter($asked['exceptions'] ?? [], static fn(array $x): bool => ($x['description'] ?? null) === $notes && ($x['location'] ?? null) === $place));
            check("$name: the recorded asked state keeps the edited day's notes and place", count($askedEdited) === 1, json_encode($asked['exceptions'] ?? null));
            check("$name: the recorded asked state keeps the skip", count(array_filter($asked['skips'] ?? [], static fn(string $k): bool => str_starts_with($k, $c['skipped']))) === 1, json_encode($asked['skips'] ?? null));

            // What Google holds after the write, by identity: the master's form, a record of the edited day in that form
            // with its notes and place, and the skipped day in that form (a cancelled record or an EXDATE).
            $master = $s->fake->events[$gid] ?? [];
            checkEq("$name: Google holds the series in its new kind", $c['allDay'], isset($master['start']['date']));
            $form = static fn(string $date): string => $c['allDay'] ? str_replace('-', '', $date)
                : (new DateTimeImmutable($date . ' ' . $c['clock'], new DateTimeZone($c['zone'])))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
            $records = $s->fake->records($gid);
            $rec = $records[$form($c['edited'])] ?? null;
            check("$name: Google holds the edited day's record under its new identity with its notes and place",
                $rec !== null && ($rec['status'] ?? '') !== 'cancelled' && ($rec['description'] ?? null) === $notes && ($rec['location'] ?? null) === $place,
                'records ' . json_encode(array_map(static fn(array $r): array => [$r['status'] ?? 'confirmed', $r['description'] ?? null, $r['location'] ?? null], $records)));
            $skipRec = $records[$form($c['skipped'])] ?? null;
            $exdate = false;
            foreach ((array) ($master['recurrence'] ?? []) as $line) {
                $exdate = $exdate || (str_starts_with((string) $line, 'EXDATE') && str_contains((string) $line, $form($c['skipped'])));
            }
            check("$name: Google holds the skipped day as skipped under its new identity", ($skipRec !== null && ($skipRec['status'] ?? '') === 'cancelled') || $exdate,
                'records ' . json_encode(array_keys($records)) . ', recurrence ' . json_encode($master['recurrence'] ?? null));
            // The instances Google lists for the series (what every other client shows).
            $list = $s->fake->transport()('GET', 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(GoogleStand::CALENDAR) . '/events/' . rawurlencode($gid) . '/instances',
                ['Authorization: Bearer sample-access'], null);
            $instances = json_decode((string) $list['body'], true)['items'] ?? [];
            $dateOf = static fn(array $i): string => isset($i['start']['date']) ? (string) $i['start']['date']
                : (new DateTimeImmutable((string) $i['start']['dateTime']))->setTimezone(new DateTimeZone($c['zone'] ?? 'UTC'))->format('Y-m-d');
            $onEdited = array_values(array_filter($instances, static fn(array $i): bool => $dateOf($i) === $c['edited']));
            check("$name: Google lists the edited day with its notes and place", count($onEdited) === 1 && ($onEdited[0]['description'] ?? null) === $notes && ($onEdited[0]['location'] ?? null) === $place,
                json_encode($onEdited));
            checkEq("$name: Google lists nothing on the skipped day", [], array_values(array_filter($instances, static fn(array $i): bool => $dateOf($i) === $c['skipped'])));
            // The writes the change sent: one of them carries the edited day's notes, after the master's.
            $sent = array_values(array_filter($s->fake->requests, static fn(array $r): bool => $r['method'] !== 'GET'));
            check("$name: a write sent to Google carries the edited day's notes and place",
                array_filter($sent, static fn(array $r): bool => ($r['body']['description'] ?? null) === $notes && ($r['body']['location'] ?? null) === $place && str_contains($r['path'], $gid . '_')) !== [],
                json_encode(array_map(static fn(array $r): array => [$r['method'], basename($r['path']), $r['body']], $sent)));

            // The rows here: the edited day's row on its date with its fields, the skip on its date.
            $rows = $db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$id]);
            $row = array_values(array_filter($rows, static fn(array $r): bool => str_starts_with((string) $r['start_utc'], $c['allDay'] ? $c['edited'] : '')
                || (!$c['allDay'] && (new DateTimeImmutable((string) $r['start_utc'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($c['zone']))->format('Y-m-d') === $c['edited'])))[0] ?? null;
            check("$name: the edited day's row is still there with its own notes and place",
                $row !== null && $row['description'] === $notes && $row['location'] === $place && (int) $row['all_day'] === ($c['allDay'] ? 1 : 0),
                json_encode(array_map(static fn(array $r): array => [$r['recurrence_instance_utc'], $r['description'], $r['location'], $r['override_fields']], $rows)));
            $exdates = json_decode((string) $db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$id]), true) ?: [];
            $skipKey = $c['allDay'] ? $c['skipped'] . ' 00:00:00'
                : (new DateTimeImmutable($c['skipped'] . ' ' . $c['clock'], new DateTimeZone($c['zone'])))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            checkEq("$name: the skipped day is still skipped here", [$skipKey], $exdates);

            // The calendar window: the edited day shows with its place, the skipped day shows nothing.
            $window = $e->window($u, new DateTimeImmutable($c['window'][0] . ' 00:00:00', new DateTimeZone('UTC')), new DateTimeImmutable($c['window'][1] . ' 00:00:00', new DateTimeZone('UTC')),
                [$s->calendarId], null, true);
            $shown = static fn(array $w): string => substr((string) $w['start'], 0, 10);
            $onDay = array_values(array_filter($window, static fn(array $w): bool => $shown($w) === $c['edited']));
            check("$name: the window shows the edited day with its place and notes",
                count($onDay) === 1 && ($onDay[0]['location'] ?? null) === $place && !empty($onDay[0]['hasDescription']), json_encode($onDay));
            checkEq("$name: the window shows nothing on the skipped day", [], array_values(array_filter($window, static fn(array $w): bool => $shown($w) === $c['skipped'])));
        } finally {
            GoogleStand::forget($db, $u);
        }
    };

    $timedUtc = ['start' => '2027-01-04T00:00:00Z', 'end' => '2027-01-04T01:00:00Z', 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
    $timedLa = ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
    $allDay = ['start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true, 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
    $toAllDay = ['scope' => 'all', 'instanceStart' => '2027-01-04T00:00:00Z', 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true];
    $window = ['2027-01-03', '2027-01-27'];

    // The reviewer's first probe (R1): weekly Mondays at 00:00 UTC, the second with its own notes and place, the third
    // deleted, the whole series made all-day on the same dates.
    scenario('timed at midnight UTC made all-day, edited day before the skip', fn() => $run([
        'name' => 'timed 00:00 UTC to all-day (edit, then skip)', 'create' => $timedUtc,
        'edit' => '2027-01-11T00:00:00Z', 'skip' => '2027-01-18T00:00:00Z', 'body' => $toAllDay,
        'allDay' => true, 'edited' => '2027-01-11', 'skipped' => '2027-01-18', 'window' => $window,
    ]));
    // The skip on the other side of the edited day.
    scenario('timed at midnight UTC made all-day, skip before the edited day', fn() => $run([
        'name' => 'timed 00:00 UTC to all-day (skip, then edit)', 'create' => $timedUtc,
        'edit' => '2027-01-18T00:00:00Z', 'skip' => '2027-01-11T00:00:00Z', 'body' => $toAllDay,
        'allDay' => true, 'edited' => '2027-01-18', 'skipped' => '2027-01-11', 'window' => $window,
    ]));
    // The same change acted on from a later day, with the edited day before it and the skip after.
    scenario('timed at midnight UTC made all-day from a later day', fn() => $run([
        'name' => 'timed 00:00 UTC to all-day (from the last day)', 'create' => $timedUtc,
        'edit' => '2027-01-04T00:00:00Z', 'skip' => '2027-01-18T00:00:00Z',
        'body' => ['scope' => 'all', 'instanceStart' => '2027-01-25T00:00:00Z', 'start' => '2027-01-25', 'end' => '2027-01-26', 'allDay' => true],
        'allDay' => true, 'edited' => '2027-01-04', 'skipped' => '2027-01-18', 'window' => $window,
    ]));
    // A clock that is not midnight UTC: the keys here differ too, so this one is the control.
    scenario('timed at 10:00 Los Angeles made all-day', fn() => $run([
        'name' => 'timed 10:00 Los Angeles to all-day', 'create' => $timedLa,
        'edit' => '2027-01-11T10:00:00-08:00', 'skip' => '2027-01-18T10:00:00-08:00',
        'body' => ['scope' => 'all', 'instanceStart' => '2027-01-04T10:00:00-08:00', 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true],
        'allDay' => true, 'edited' => '2027-01-11', 'skipped' => '2027-01-18', 'window' => $window,
    ]));
    // All-day made timed at 00:00 UTC: the keys here coincide again, Google's ids differ.
    scenario('all-day made timed at midnight UTC, edited day before the skip', fn() => $run([
        'name' => 'all-day to timed 00:00 UTC (edit, then skip)', 'create' => $allDay,
        'edit' => '2027-01-11', 'skip' => '2027-01-18',
        'body' => ['scope' => 'all', 'instanceStart' => '2027-01-04', 'start' => '2027-01-04T00:00:00Z', 'end' => '2027-01-04T01:00:00Z', 'allDay' => false, 'tzid' => 'UTC'],
        'allDay' => false, 'clock' => '00:00', 'zone' => 'UTC', 'edited' => '2027-01-11', 'skipped' => '2027-01-18', 'window' => $window,
    ]));
    scenario('all-day made timed at midnight UTC, skip before the edited day', fn() => $run([
        'name' => 'all-day to timed 00:00 UTC (skip, then edit)', 'create' => $allDay,
        'edit' => '2027-01-18', 'skip' => '2027-01-11',
        'body' => ['scope' => 'all', 'instanceStart' => '2027-01-04', 'start' => '2027-01-04T00:00:00Z', 'end' => '2027-01-04T01:00:00Z', 'allDay' => false, 'tzid' => 'UTC'],
        'allDay' => false, 'clock' => '00:00', 'zone' => 'UTC', 'edited' => '2027-01-18', 'skipped' => '2027-01-11', 'window' => $window,
    ]));
    // All-day made timed at 10:00 Los Angeles: the keys differ, the control.
    scenario('all-day made timed at 10:00 Los Angeles', fn() => $run([
        'name' => 'all-day to timed 10:00 Los Angeles', 'create' => $allDay,
        'edit' => '2027-01-11', 'skip' => '2027-01-18',
        'body' => ['scope' => 'all', 'instanceStart' => '2027-01-04', 'start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'allDay' => false, 'tzid' => 'America/Los_Angeles'],
        'allDay' => false, 'clock' => '10:00', 'zone' => 'America/Los_Angeles', 'edited' => '2027-01-11', 'skipped' => '2027-01-18', 'window' => $window,
    ]));

    // The reviewer's second probe: seed 911 cases 193 and 879 of the harness's Google mode, as recorded (invented
    // data), run through the harness's driver with the setting off. Each is an Auckland series at 13:00 in its
    // summer (00:00 UTC) made all-day; 193 loses its skipped day, 879 an edited day's notes and place. The driver's
    // own checks are the expectation; its Undo comparison (f-undo) runs after the forward edit and is not this test's.
    $specs = [
        193 => ['n' => 193, 'seed' => 911, 'series' => ['family' => 'monthly_nth', 'rrule' => 'FREQ=MONTHLY;BYDAY=-1TU', 'import' => false, 'bound' => 'count', 'boundAt' => 11, 'untilForm' => 'exact', 'allDay' => false, 'tzid' => 'Pacific/Auckland', 'start' => '2028-02-28 13:00', 'length' => 120, 'floating' => false],
            'exceptions' => [['at' => 10, 'kind' => 'skip'], ['at' => 3, 'kind' => 'edit', 'own' => ['date'], 'days' => 3, 'mins' => 45, 'zone' => 'America/New_York', 'len' => -15]],
            'acted' => 3, 'from' => 'edited',
            'vector' => ['scope' => 'all', 'date' => 0, 'clock' => 0, 'zone' => null, 'length' => 0, 'allDay' => true, 'toZone' => 'America/Los_Angeles', 'toClock' => 32400, 'trip' => false, 'fields' => [], 'rule' => 'same', 'sameForm' => 'text', 'newRule' => 'FREQ=WEEKLY;BYDAY=TU,TH', 'bound' => null, 'via' => 'row', 'startOnly' => false],
            'stack' => null, 'everyOption' => false, 'typed' => false, 'choices' => ['edit' => []], 'preselected' => ['edit' => false]],
        879 => ['n' => 879, 'seed' => 911, 'series' => ['family' => 'monthly_nth_list', 'rrule' => 'FREQ=MONTHLY;BYDAY=1SA,-1SU', 'import' => false, 'bound' => 'count', 'boundAt' => 11, 'untilForm' => 'dayend', 'allDay' => false, 'tzid' => 'Pacific/Auckland', 'start' => '2026-09-01 13:00', 'length' => 60, 'floating' => true],
            'exceptions' => [['at' => 1, 'kind' => 'edit', 'own' => ['location', 'description', 'time'], 'days' => 2, 'mins' => 45, 'zone' => 'America/New_York', 'len' => 30], ['at' => 0, 'kind' => 'edit', 'own' => ['title', 'date'], 'days' => 3, 'mins' => -30, 'zone' => 'Asia/Tokyo', 'len' => 60]],
            'acted' => 9, 'from' => 'later',
            'vector' => ['scope' => 'all', 'date' => 0, 'clock' => -10800, 'zone' => null, 'length' => 3600, 'allDay' => true, 'toZone' => 'America/Los_Angeles', 'toClock' => 9000, 'trip' => false, 'fields' => [], 'rule' => 'absent', 'sameForm' => 'editor', 'newRule' => 'FREQ=WEEKLY;BYDAY=TU,TH', 'bound' => null, 'via' => 'row', 'startOnly' => false],
            'stack' => null, 'everyOption' => false, 'typed' => false, 'choices' => ['edit' => []], 'preselected' => ['edit' => false]],
    ];
    foreach ($specs as $n => $spec) {
        scenario("harness seed 911 case $n, forward edit", function () use ($db, $n, $spec): void {
            $u = freshUser($db);
            seriesUndo($db, $u, false);
            $d = domains($db);
            $other = (int) $d->calendars->create($u, ['name' => 'Sample other'])['id'];
            $s = GoogleStand::make($db, $d->undo, $u);
            try {
                $driver = new Driver($db, $s->events, new BetterCal\Domain\Undo($db, null, $s->writer), $u, $s->calendarId, $other, $s);
                $out = $driver->run($spec, new Rng(0));
                $forward = array_values(array_filter($out['records'], static fn(array $r): bool => $r['invariant'] !== 'f-undo'));
                checkEq("harness seed 911 case $n: no forward-edit check fails", [], array_map(static fn(array $r): string => $r['invariant'] . ': ' . $r['what'], $forward));
            } finally {
                GoogleStand::forget($db, $u);
            }
        });
    }
};
