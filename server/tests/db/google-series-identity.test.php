<?php

declare(strict_types=1);

// Rule 1, provider identity (review R1 of cb2c6082..63a96467; the model in
// workfolder/reviews/audit-155-identity.md): Google names an instance of a
// series by the series id and the instance's original start in the master's
// own form, `<id>_<UTC basic time>Z` for a timed series and `<id>_<YYYYMMDD>`
// for an all-day one, while the rows here key it by a UTC instant (the date
// at 00:00 UTC when all-day). The two coincide for a timed occurrence at
// midnight UTC while Google's ids differ, and west of UTC an equal key is
// another day: 19:00 in New York on 5 January is 00:00 UTC on the 6th.
//
// What a person must see, from the spec (series-model.md, "Exceptions on a
// master change"; the plan's own words "keeps its place and notes" and "stays
// skipped"): when a whole-series change turns a timed series into an all-day
// one or back, or changes its zone, an edited day keeps its own notes and
// place, a skipped day stays skipped, and a day holding only what is kept
// here (a tag) keeps it, each on its own calendar date: in what Google holds
// after the write (by id), in the rows here and in the calendar window; and a
// day row's stored Google id is always one Google answers for that day. Every
// block runs with google_series_undo off. Each block is a scenario(): a throw
// fails that block and the next still runs. Google is the edit harness's
// stand-in (GoogleStand), whose occurrences come from the harness's own
// evaluator; its settings (FakeGoogle: order, dropped items, the zone alone)
// come from the environment unless a block sets one. Every expected value
// below is written from the spec; nothing in server/src computes one.
// Invented data.

use BetterCal\Domain\ReviewQueue;
use BetterCal\Domain\Undo;
use BetterCal\EditHarness\Driver;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\EditHarness\Rng;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    $notes = 'Bring the sample checklist';
    $place = 'Sample Room';
    $tag = 'sample-day-tag';
    $utc = new DateTimeZone('UTC');

    // A request to the stand-in as another client makes it (no If-Match).
    $atGoogle = static function (GoogleStand $s, string $method, string $path, ?array $body = null): array {
        $answer = $s->fake->transport()($method, 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(GoogleStand::CALENDAR) . $path,
            ['Authorization: Bearer ' . ($s->fake->token ?? 'any')], $body);
        return [(int) $answer['status'], json_decode((string) $answer['body'], true)];
    };
    // A key here: a date at 00:00:00 UTC when all-day, else the date and clock in the zone, in UTC.
    $keyOf = static fn(string $date, array $w): string => $w['allDay'] ? $date . ' 00:00:00'
        : (new DateTimeImmutable($date . ' ' . $w['clock'], new DateTimeZone($w['zone'])))->setTimezone($utc)->format('Y-m-d H:i:s');
    // The harness's own id for a date under the series' form after the change.
    $idOn = static fn(string $gid, string $date, array $w): string => GoogleStand::idOf($gid, $keyOf($date, $w), $w['allDay']);
    // The calendar date a row or an instance shows on.
    $dateOfRow = static fn(array $r, array $w): string => (int) $r['all_day'] === 1 ? substr((string) $r['start_utc'], 0, 10)
        : (new DateTimeImmutable((string) $r['start_utc'], $utc))->setTimezone(new DateTimeZone($w['zone'] ?? 'UTC'))->format('Y-m-d');
    $dateOfItem = static fn(array $i, array $w): string => isset($i['start']['date']) ? (string) $i['start']['date']
        : (new DateTimeImmutable((string) $i['start']['dateTime']))->setTimezone(new DateTimeZone($w['zone'] ?? 'UTC'))->format('Y-m-d');
    $tagsOf = static fn(int $rowId): array => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [$rowId]), 'name');

    /**
     * A series on the stand-in's calendar with an edited day (its own notes and place), a skipped day and a day given
     * only a tag, each by a "this event" change ($c: create, edit, skip, tag, each an instanceStart or absent).
     */
    $setup = static function (array $c) use ($db, $notes, $place, $tag): object {
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        foreach ($c['stand'] ?? [] as $k => $v) {
            $s->fake->$k = $v;
        }
        $e = $s->events;
        $id = (int) $e->create($u, ['calendarId' => $s->calendarId, 'title' => 'Sample weekly'] + $c['create'])['eventId'];
        if (isset($c['edit'])) {
            $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $c['edit'], 'description' => $notes, 'location' => $place]);
            $e->throwDeferred();
        }
        if (isset($c['skip'])) {
            $e->deleteEvent($u, $id, 'this', $c['skip']);
            $e->throwDeferred();
        }
        if (isset($c['tag'])) {
            $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $c['tag'], 'tagNames' => [$tag]]);
            $e->throwDeferred();
        }
        $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
        return (object) ['u' => $u, 'd' => $d, 's' => $s, 'e' => $e, 'id' => $id, 'gid' => $gid];
    };

    /** A whole-series change through the plan, with the plan's own words checked first: the edited day kept, the skip kept. */
    $change = static function (object $t, string $name, array $body, array $choices = [], array $w = []) use ($db): void {
        $plan = $t->e->previewPatch($t->u, $t->id, $body + ['plan' => ['choices' => $choices]]);
        $days = $plan['days'] ?? [];
        if (isset($w['edited'])) {
            $ed = array_values(array_filter($days, static fn(array $x): bool => ($x['kind'] ?? '') === 'edited'))[0] ?? null;
            check("$name: the plan keeps the edited day's notes and place on its date",
                $ed !== null && ($ed['outcome'] ?? '') !== 'deleted' && ($ed['to'] ?? '') === $w['edited'] && array_intersect(['location', 'description'], $ed['kept'] ?? []) === ['location', 'description'],
                json_encode($ed));
        }
        if (isset($w['skipped'])) {
            $sk = array_values(array_filter($days, static fn(array $x): bool => ($x['kind'] ?? '') === 'skipped'))[0] ?? null;
            check("$name: the plan keeps the skipped day skipped on its date", $sk !== null && ($sk['to'] ?? '') === $w['skipped'] && ($sk['outcomeWords'] ?? '') === 'stays skipped', json_encode($sk));
        }
        $t->s->fake->requests = [];
        $t->e->patch($t->u, $t->id, $body + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices]]);
        $t->e->throwDeferred();
    };

    /**
     * What a person must see after a change, $w: the series' kind (allDay; clock and zone when timed), the dates of
     * the edited, skipped and tagged days, whether the change had to send the edited day again (sent), and the window.
     */
    $verify = static function (object $t, string $name, array $w) use ($db, $notes, $place, $tag, $keyOf, $idOn, $dateOfRow, $dateOfItem, $tagsOf, $atGoogle, $utc): void {
        $gid = $t->gid;
        $master = $t->s->fake->events[$gid] ?? [];
        checkEq("$name: Google holds the series in its new kind", $w['allDay'], isset($master['start']['date']));
        $records = [];
        foreach ($t->s->fake->events as $rid => $r) {
            if (($r['recurringEventId'] ?? '') === $gid && isset($r['originalStartTime'])) {
                $records[$rid] = $r;
            }
        }
        [, $list] = $atGoogle($t->s, 'GET', '/events/' . rawurlencode($gid) . '/instances');
        $instances = $list['items'] ?? [];
        $rows = $db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$t->id]);
        $rowOn = static fn(string $date): ?array => array_values(array_filter($rows, static fn(array $r): bool => $dateOfRow($r, $w) === $date))[0] ?? null;
        $window = $t->e->window($t->u, new DateTimeImmutable($w['window'][0] . ' 00:00:00', $utc), new DateTimeImmutable($w['window'][1] . ' 00:00:00', $utc), [$t->s->calendarId], null, true);
        $shownOn = static fn(string $date): array => array_values(array_filter($window, static fn(array $x): bool => substr((string) $x['start'], 0, 10) === $date));
        if (isset($w['edited'])) {
            $rec = $records[$idOn($gid, $w['edited'], $w)] ?? null;
            check("$name: Google holds the edited day's record under its id in the series' form, with its notes and place",
                $rec !== null && ($rec['status'] ?? '') !== 'cancelled' && ($rec['description'] ?? null) === $notes && ($rec['location'] ?? null) === $place,
                'want ' . $idOn($gid, $w['edited'], $w) . ', records ' . json_encode(array_map(static fn(array $r): array => [$r['status'] ?? 'confirmed', $r['description'] ?? null], $records)));
            $on = array_values(array_filter($instances, static fn(array $i): bool => $dateOfItem($i, $w) === $w['edited']));
            check("$name: Google lists the edited day on its date with its notes and place", count($on) === 1 && ($on[0]['description'] ?? null) === $notes && ($on[0]['location'] ?? null) === $place, json_encode($on));
            if (!empty($w['sent'])) {
                $sent = array_filter($t->s->fake->requests, static fn(array $r): bool => $r['method'] !== 'GET' && ($r['body']['description'] ?? null) === $notes && str_contains($r['path'], $gid . '_'));
                check("$name: a write sent to Google carries the edited day's notes and place", $sent !== [],
                    json_encode(array_map(static fn(array $r): array => [$r['method'], basename($r['path'])], array_values(array_filter($t->s->fake->requests, static fn(array $r): bool => $r['method'] !== 'GET')))));
            }
            $row = $rowOn($w['edited']);
            check("$name: the edited day's row is on its date with its own notes and place, in the series' kind",
                $row !== null && $row['description'] === $notes && $row['location'] === $place && (int) $row['all_day'] === ($w['allDay'] ? 1 : 0),
                json_encode(array_map(static fn(array $r): array => [$r['recurrence_instance_utc'], $r['description'], $r['location'], $r['override_fields']], $rows)));
            $day = $shownOn($w['edited']);
            check("$name: the window shows the edited day with its place and notes", count($day) === 1 && ($day[0]['location'] ?? null) === $place && !empty($day[0]['hasDescription']), json_encode($day));
        }
        if (isset($w['skipped'])) {
            $sid = $idOn($gid, $w['skipped'], $w);
            $exdate = false;
            foreach ((array) ($master['recurrence'] ?? []) as $line) {
                $exdate = $exdate || (str_starts_with((string) $line, 'EXDATE') && str_contains((string) $line, substr($sid, strlen($gid) + 1, 8)));
            }
            check("$name: Google holds the skipped day as skipped under its id in the series' form",
                (($records[$sid]['status'] ?? '') === 'cancelled') || $exdate, 'want ' . $sid . ', records ' . json_encode(array_keys($records)) . ', recurrence ' . json_encode($master['recurrence'] ?? null));
            checkEq("$name: Google lists nothing on the skipped day", [], array_values(array_filter($instances, static fn(array $i): bool => $dateOfItem($i, $w) === $w['skipped'])));
            checkEq("$name: the skipped day is skipped here, and nothing else is", [$keyOf($w['skipped'], $w)],
                json_decode((string) $db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$t->id]), true) ?: []);
            checkEq("$name: the window shows nothing on the skipped day", [], $shownOn($w['skipped']));
        }
        if (isset($w['tagged'])) {
            $holders = [];
            foreach ($rows as $r) {
                if (in_array($tag, $tagsOf((int) $r['id']), true)) {
                    $holders[] = $dateOfRow($r, $w);
                }
            }
            checkEq("$name: the tag is on its own day and no other", [$w['tagged']], $holders);
        }
        // I3: every stored id is one Google answers for that day, and Google's records are the rows' by id.
        checkEq("$name: what Google holds is what the rows say, by id (I3)", [], array_map(static fn(array $p): string => $p['problem'] . ' ' . json_encode(array_diff_key($p, ['problem' => 1])),
            $t->s->idProblems($db->one('SELECT * FROM events WHERE id = ?', [$t->id]), $rows)));
    };

    $scenario = static function (string $name, callable $fn) use ($db): void {
        scenario($name, $fn);
    };

    $timedUtc = ['start' => '2027-01-04T00:00:00Z', 'end' => '2027-01-04T01:00:00Z', 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
    $timedLa = ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
    $allDay = ['start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true, 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
    $window = ['2027-01-03', '2027-01-27'];
    $asAllDay = ['allDay' => true, 'window' => $window];
    $asUtc = ['allDay' => false, 'clock' => '00:00', 'zone' => 'UTC', 'window' => $window];
    $asLa = ['allDay' => false, 'clock' => '10:00', 'zone' => 'America/Los_Angeles', 'window' => $window];
    $toAllDay = static fn(string $acted, string $date): array => ['scope' => 'all', 'instanceStart' => $acted, 'start' => $date, 'end' => (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d'), 'allDay' => true];
    $toUtc = ['scope' => 'all', 'instanceStart' => '2027-01-04', 'start' => '2027-01-04T00:00:00Z', 'end' => '2027-01-04T01:00:00Z', 'allDay' => false, 'tzid' => 'UTC'];
    $toLa = ['scope' => 'all', 'instanceStart' => '2027-01-04', 'start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'allDay' => false, 'tzid' => 'America/Los_Angeles'];

    // Tests 1, 3 and 4 of the audit: the reviewer's probe (weekly at 00:00 UTC, the second with its own notes and
    // place, the third deleted, made all-day), its other direction, the skip on either side, and the 10:00 Los Angeles
    // controls where no key coincides.
    foreach ([
        ['timed 00:00 UTC to all-day (edit, then skip)', $timedUtc, '2027-01-11T00:00:00Z', '2027-01-18T00:00:00Z', $toAllDay('2027-01-04T00:00:00Z', '2027-01-04'), $asAllDay, '2027-01-11', '2027-01-18'],
        ['timed 00:00 UTC to all-day (skip, then edit)', $timedUtc, '2027-01-18T00:00:00Z', '2027-01-11T00:00:00Z', $toAllDay('2027-01-04T00:00:00Z', '2027-01-04'), $asAllDay, '2027-01-18', '2027-01-11'],
        ['timed 00:00 UTC to all-day (from the last day)', $timedUtc, '2027-01-04T00:00:00Z', '2027-01-18T00:00:00Z', $toAllDay('2027-01-25T00:00:00Z', '2027-01-25'), $asAllDay, '2027-01-04', '2027-01-18'],
        ['timed 10:00 Los Angeles to all-day', $timedLa, '2027-01-11T10:00:00-08:00', '2027-01-18T10:00:00-08:00', $toAllDay('2027-01-04T10:00:00-08:00', '2027-01-04'), $asAllDay, '2027-01-11', '2027-01-18'],
        ['all-day to timed 00:00 UTC (edit, then skip)', $allDay, '2027-01-11', '2027-01-18', $toUtc, $asUtc, '2027-01-11', '2027-01-18'],
        ['all-day to timed 00:00 UTC (skip, then edit)', $allDay, '2027-01-18', '2027-01-11', $toUtc, $asUtc, '2027-01-18', '2027-01-11'],
        ['all-day to timed 10:00 Los Angeles', $allDay, '2027-01-11', '2027-01-18', $toLa, $asLa, '2027-01-11', '2027-01-18'],
    ] as [$name, $create, $edit, $skip, $body, $as, $edited, $skipped]) {
        $scenario($name, function () use ($db, $setup, $change, $verify, $name, $create, $edit, $skip, $body, $as, $edited, $skipped): void {
            $t = $setup(['create' => $create, 'edit' => $edit, 'skip' => $skip]);
            try {
                $w = $as + ['edited' => $edited, 'skipped' => $skipped, 'sent' => true];
                $change($t, $name, $body, [], $w);
                // The recorded asked state: what the change asked Google to hold.
                $details = json_decode((string) $db->scalar('SELECT details_json FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$t->u]), true);
                $asked = $details['google']['before'][0]['asked'] ?? [];
                check("$name: the recorded asked state keeps the edited day's notes and place",
                    count(array_filter($asked['exceptions'] ?? [], static fn(array $x): bool => ($x['description'] ?? null) === 'Bring the sample checklist' && ($x['location'] ?? null) === 'Sample Room')) === 1,
                    json_encode($asked['exceptions'] ?? null));
                check("$name: the recorded asked state keeps the skip", count(array_filter($asked['skips'] ?? [], static fn(string $k): bool => str_starts_with($k, $skipped))) === 1, json_encode($asked['skips'] ?? null));
                $verify($t, $name, $w);
            } finally {
                GoogleStand::forget($db, $t->u);
            }
        });
    }

    // Test 4, second half: timed 10:00 Los Angeles made all-day, stopped right after the master step (the stand-in
    // refuses the next write). Put back must go through and leave Google and the rows as they were.
    $scenario('a stop right after the master step, then Put back', function () use ($db, $setup, $timedLa, $toAllDay, $verify, $asLa): void {
        $name = 'timed 10:00 Los Angeles to all-day, stopped after the master step, then Put back';
        $t = $setup(['create' => $timedLa, 'edit' => '2027-01-11T10:00:00-08:00', 'skip' => '2027-01-18T10:00:00-08:00']);
        try {
            $was = $t->s->held($t->gid);
            $body = $toAllDay('2027-01-04T10:00:00-08:00', '2027-01-04');
            $plan = $t->e->previewPatch($t->u, $t->id, $body);
            $t->s->knobs->writes = 0;
            $t->s->knobs->beforeAt = 2; // the master PATCH is taken; the first step after it is refused
            $caught = null;
            try {
                $t->e->patch($t->u, $t->id, $body + ['plan' => ['hash' => $plan['hash'], 'choices' => []]]);
                $t->e->throwDeferred();
            } catch (HttpError $e) {
                $caught = $e;
            }
            $t->s->knobs->beforeAt = null;
            check("$name: the change stops half done", $caught !== null && $t->s->knobs->fired === 'before', $caught === null ? 'no error' : $caught->errorCode);
            $listed = (new ReviewQueue($db, $t->e))->googleHalfDone($t->u);
            check("$name: Review lists the half-done change", count($listed) === 1, json_encode($listed));
            $out = null;
            $refused = null;
            try {
                $out = $t->e->googlePutBack($t->u, (int) ($listed[0]['id'] ?? 0));
                $t->e->throwDeferred();
            } catch (HttpError $e) {
                $refused = $e->status . ' ' . $e->errorCode . ': ' . $e->getMessage();
            }
            check("$name: Put back goes through", $refused === null && ($out['ok'] ?? false) === true, (string) $refused);
            if ($refused === null) {
                checkEq("$name: Google holds the series as it was", $was, $t->s->held($t->gid));
                $verify($t, $name, $asLa + ['edited' => '2027-01-11', 'skipped' => '2027-01-18']);
            }
        } finally {
            GoogleStand::forget($db, $t->u);
        }
    });

    // Test 5: west of UTC on consecutive days. Daily at 19:00 New York in January (00:00 UTC the next day): an edited day
    // on the 5th, a skip on the 6th, a tag on the 8th; made all-day, and the reverse. Each stays on its own date.
    $ny = ['allDay' => false, 'clock' => '19:00', 'zone' => 'America/New_York', 'window' => ['2027-01-03', '2027-01-14']];
    $scenario('west of UTC, daily at 19:00 New York made all-day', function () use ($db, $setup, $change, $verify, $ny, $toAllDay): void {
        $name = 'daily 19:00 New York to all-day';
        $t = $setup(['create' => ['start' => '2027-01-04T19:00:00-05:00', 'end' => '2027-01-04T20:00:00-05:00', 'tzid' => 'America/New_York', 'rrule' => 'FREQ=DAILY;COUNT=8'],
            'edit' => '2027-01-05T19:00:00-05:00', 'skip' => '2027-01-06T19:00:00-05:00', 'tag' => '2027-01-08T19:00:00-05:00']);
        try {
            $w = ['allDay' => true, 'window' => $ny['window'], 'edited' => '2027-01-05', 'skipped' => '2027-01-06', 'tagged' => '2027-01-08'];
            $change($t, $name, $toAllDay('2027-01-04T19:00:00-05:00', '2027-01-04'), [], $w);
            $verify($t, $name, $w);
        } finally {
            GoogleStand::forget($db, $t->u);
        }
    });
    $scenario('west of UTC, daily all-day made 19:00 New York', function () use ($db, $setup, $change, $verify, $ny): void {
        $name = 'daily all-day to 19:00 New York';
        $t = $setup(['create' => ['start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true, 'tzid' => 'UTC', 'rrule' => 'FREQ=DAILY;COUNT=8'],
            'edit' => '2027-01-05', 'skip' => '2027-01-06', 'tag' => '2027-01-08']);
        try {
            $w = $ny + ['edited' => '2027-01-05', 'skipped' => '2027-01-06', 'tagged' => '2027-01-08'];
            $change($t, $name, ['scope' => 'all', 'instanceStart' => '2027-01-04', 'start' => '2027-01-04T19:00:00-05:00', 'end' => '2027-01-04T20:00:00-05:00', 'allDay' => false, 'tzid' => 'America/New_York'], [], $w);
            $verify($t, $name, $w);
        } finally {
            GoogleStand::forget($db, $t->u);
        }
    });

    // Test 6: a double flip. All-day with a skip, made timed at 00:00 UTC, made all-day again: the skip is stored after each.
    $scenario('a double flip keeps the skip after each', function () use ($db, $setup, $change, $verify, $allDay, $toUtc, $toAllDay, $asUtc, $asAllDay): void {
        $name = 'all-day to 00:00 UTC to all-day';
        $t = $setup(['create' => $allDay, 'skip' => '2027-01-18']);
        try {
            $change($t, "$name, first flip", $toUtc, [], ['skipped' => '2027-01-18']);
            $verify($t, "$name, after the first flip", $asUtc + ['skipped' => '2027-01-18']);
            $change($t, "$name, second flip", $toAllDay('2027-01-04T00:00:00Z', '2027-01-04'), [], ['skipped' => '2027-01-18']);
            $verify($t, "$name, after the second flip", $asAllDay + ['skipped' => '2027-01-18']);
        } finally {
            GoogleStand::forget($db, $t->u);
        }
    });

    // Test 7: a day holding only a tag through a flip, then renamed with "this event": the rename reaches Google on the
    // date-form id, and no request goes to the timed id.
    $scenario('a tagged day through a flip, then renamed', function () use ($db, $setup, $change, $verify, $timedUtc, $toAllDay, $asAllDay, $atGoogle): void {
        $name = 'a tagged day through a flip at 00:00 UTC, then renamed';
        $t = $setup(['create' => $timedUtc, 'tag' => '2027-01-11T00:00:00Z']);
        try {
            $change($t, $name, $toAllDay('2027-01-04T00:00:00Z', '2027-01-04'));
            $verify($t, "$name, after the flip", $asAllDay + ['tagged' => '2027-01-11']);
            $t->s->fake->requests = [];
            $error = null;
            try {
                $t->e->patch($t->u, $t->id, ['scope' => 'this', 'instanceStart' => '2027-01-11', 'title' => 'Sample weekly, renamed day']);
                $t->e->throwDeferred();
            } catch (\Throwable $e) {
                $error = get_class($e) . ': ' . $e->getMessage();
            }
            check("$name: the rename goes through", $error === null, (string) $error);
            $paths = array_map(static fn(array $r): string => $r['method'] . ' ' . rawurldecode(basename($r['path'])), array_values(array_filter($t->s->fake->requests, static fn(array $r): bool => $r['method'] !== 'GET')));
            check("$name: the rename is sent to the date-form id", in_array('PATCH ' . $t->gid . '_20270111', $paths, true), json_encode($paths));
            checkEq("$name: nothing is sent to the timed id", [], array_values(array_filter($paths, static fn(string $p): bool => str_contains($p, $t->gid . '_20270111T000000Z'))));
            [$status, $item] = $atGoogle($t->s, 'GET', '/events/' . rawurlencode($t->gid . '_20270111'));
            checkEq("$name: Google shows the day renamed", [200, 'Sample weekly, renamed day'], [$status, $item['summary'] ?? null]);
        } finally {
            GoogleStand::forget($db, $t->u);
        }
    });

    // Test 8: an undo across a flip. A "this event" rename, the series made all-day at a coinciding key, then Undo of the
    // rename: refused as stale before anything is sent, or it lands on the date-form id; never a request to the timed id.
    $scenario('an undo of a rename across a flip', function () use ($db, $setup, $change, $timedUtc, $toAllDay, $atGoogle): void {
        $name = 'an undo of a day\'s rename across a flip at 00:00 UTC';
        $t = $setup(['create' => $timedUtc]);
        try {
            $t->e->patch($t->u, $t->id, ['scope' => 'this', 'instanceStart' => '2027-01-11T00:00:00Z', 'title' => 'Sample weekly, renamed day']);
            $t->e->throwDeferred();
            $entry = lastEntry($db, $t->u);
            $change($t, $name, $toAllDay('2027-01-04T00:00:00Z', '2027-01-04'));
            $t->s->fake->requests = [];
            $refused = null;
            try {
                (new Undo($db, null, $t->s->writer))->undoById($t->u, $entry);
            } catch (HttpError $e) {
                $refused = $e->errorCode;
            }
            $writes = array_values(array_filter($t->s->fake->requests, static fn(array $r): bool => $r['method'] !== 'GET'));
            $paths = array_map(static fn(array $r): string => $r['method'] . ' ' . rawurldecode(basename($r['path'])), $writes);
            checkEq("$name: no request goes to the timed id", [], array_values(array_filter($paths, static fn(string $p): bool => str_contains($p, $t->gid . '_20270111T000000Z'))));
            echo '    note: ' . $name . ': ' . ($refused !== null ? "refused ($refused)" : 'undone') . ', writes sent: ' . json_encode($paths) . "\n";
            if ($refused !== null) {
                checkEq("$name: refused as stale, nothing is sent", [], $paths);
            } else {
                [$status, $item] = $atGoogle($t->s, 'GET', '/events/' . rawurlencode($t->gid . '_20270111'));
                checkEq("$name: undone, Google shows the day with the series' title on its date-form id", [200, 'Sample weekly'], [$status, $item['summary'] ?? null]);
            }
        } finally {
            GoogleStand::forget($db, $t->u);
        }
    });

    // Test 9: the stale-local case. The flip is made at Google by another client, over an edited day, a skip and a tagged
    // day; then a delta poll, and separately a whole read. Google dropped the edited day and the skip (a form change drops
    // every exception), so no old-form row or skip is left here, and the tag stays on its day.
    foreach (['a delta poll' => false, 'a whole read' => true] as $how => $whole) {
        $scenario("a flip made at Google, then $how", function () use ($db, $setup, $timedUtc, $atGoogle, $tagsOf, $tag, $how, $whole): void {
            $name = "a flip made at Google over an edited day, a skip and a tagged day, then $how";
            $t = $setup(['create' => $timedUtc, 'edit' => '2027-01-11T00:00:00Z', 'skip' => '2027-01-18T00:00:00Z', 'tag' => '2027-01-25T00:00:00Z']);
            try {
                $t->s->poll();
                [$status] = $atGoogle($t->s, 'PATCH', '/events/' . rawurlencode($t->gid), ['start' => ['date' => '2027-01-04'], 'end' => ['date' => '2027-01-05']]);
                checkEq("$name: the stand-in takes the flip", 200, $status);
                if ($whole) {
                    $db->run('UPDATE calendars SET google_sync_token = NULL WHERE id = ?', [$t->s->calendarId]);
                }
                $t->s->poll();
                $master = $db->one('SELECT * FROM events WHERE id = ?', [$t->id]);
                checkEq("$name: the series is all-day here", 1, (int) $master['all_day']);
                checkEq("$name: no old-form skip is left", [], json_decode((string) ($master['exdates_json'] ?? 'null'), true) ?: []);
                $rows = $db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$t->id]);
                checkEq("$name: no day row of the old form is left", [], array_values(array_map(static fn(array $r): string => (string) $r['google_event_id'],
                    array_filter($rows, static fn(array $r): bool => (int) $r['all_day'] !== 1 || str_ends_with((string) $r['google_event_id'], 'Z')))));
                $holders = [];
                foreach ($rows as $r) {
                    if (in_array($tag, $tagsOf((int) $r['id']), true)) {
                        $holders[] = substr((string) $r['start_utc'], 0, 10);
                    }
                }
                checkEq("$name: the tag is on its own day", ['2027-01-25'], $holders);
                checkEq("$name: what Google holds is what the rows say, by id (I3)", [], array_map(static fn(array $p): string => $p['problem'], $t->s->idProblems($master, $rows)));
            } finally {
                GoogleStand::forget($db, $t->u);
            }
        });
    }

    // Test 10: the zone alone (10:00 Los Angeles to 11:00 Denver, the dialog's "same moment"), an edited day and a skip:
    // both there after, with the stand-in set each way for the open question Q2.
    foreach (['kept', 'dropped'] as $zoneAlone) {
        $scenario("the zone alone, the stand-in's zone change $zoneAlone", function () use ($db, $setup, $change, $verify, $timedLa, $zoneAlone): void {
            $name = "10:00 Los Angeles to 11:00 Denver at the same moment (stand-in: the zone alone $zoneAlone)";
            $t = $setup(['create' => $timedLa, 'edit' => '2027-01-11T10:00:00-08:00', 'skip' => '2027-01-18T10:00:00-08:00', 'stand' => ['zoneAlone' => $zoneAlone]]);
            try {
                $w = ['allDay' => false, 'clock' => '11:00', 'zone' => 'America/Denver', 'window' => ['2027-01-03', '2027-01-27'], 'edited' => '2027-01-11', 'skipped' => '2027-01-18'];
                // "The same moment": the wall clock sent in the new zone (10:00) is read on the clock the day showed on
                // (Los Angeles; README, The Oracle), so no instant moves and the series is 11:00 in Denver.
                $change($t, $name, ['scope' => 'all', 'instanceStart' => '2027-01-04T10:00:00-08:00', 'start' => '2027-01-04T10:00:00-07:00', 'end' => '2027-01-04T11:00:00-07:00', 'tzid' => 'America/Denver'],
                    ['zone' => 'moment'], $w);
                $verify($t, $name, $w);
            } finally {
                GoogleStand::forget($db, $t->u);
            }
        });
    }

    // I3 bites: a stored id swapped to the other form's is named, and the clean state names nothing.
    $scenario('I3 names a stored id of the other form', function () use ($db, $setup, $timedUtc): void {
        $t = $setup(['create' => $timedUtc, 'edit' => '2027-01-11T00:00:00Z', 'skip' => '2027-01-18T00:00:00Z']);
        try {
            $master = $db->one('SELECT * FROM events WHERE id = ?', [$t->id]);
            $rows = $db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$t->id]);
            checkEq('I3: the clean state has no problem', [], $t->s->idProblems($master, $rows));
            $db->run('UPDATE events SET google_event_id = ? WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ?', [$t->gid . '_20270111', $t->id, '2027-01-11 00:00:00']);
            $rows = $db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$t->id]);
            $problems = $t->s->idProblems($master, $rows);
            check('I3: a day row\'s id swapped to the date form is named', count(array_filter($problems, static fn(array $p): bool => ($p['googleId'] ?? '') === $t->gid . '_20270111')) === 1, json_encode($problems));
            // And a record made in the other form at the stand-in is named by its shape.
            $rid = $t->gid . '_20270111T000000Z';
            $t->s->fake->events[$rid]['originalStartTime'] = ['date' => '2027-01-11'];
            check('I3: a record whose originalStartTime is not in its master\'s form is named',
                array_filter($t->s->idProblems($master, $rows), static fn(array $p): bool => str_contains($p['problem'], 'originalStartTime')) !== [], json_encode($t->s->idProblems($master, $rows)));
        } finally {
            GoogleStand::forget($db, $t->u);
        }
    });

    // Test 2, the reviewer's second probe: seed 911 cases 193 and 879 of the harness's Google mode, as recorded (invented
    // data), run through the harness's driver with the setting off. Each is an Auckland series at 13:00 in its summer
    // (00:00 UTC) made all-day; 193 loses its skipped day, 879 an edited day's notes and place. The driver's own checks
    // are the expectation; its Undo comparison (f-undo) runs after the forward edit and is not this test's.
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
        $scenario("harness seed 911 case $n, forward edit", function () use ($db, $n, $spec): void {
            $u = freshUser($db);
            seriesUndo($db, $u, false);
            $d = domains($db);
            $other = (int) $d->calendars->create($u, ['name' => 'Sample other'])['id'];
            $s = GoogleStand::make($db, $d->undo, $u);
            try {
                $driver = new Driver($db, $s->events, new Undo($db, null, $s->writer), $u, $s->calendarId, $other, $s);
                $out = $driver->run($spec, new Rng(0));
                $forward = array_values(array_filter($out['records'], static fn(array $r): bool => $r['invariant'] !== 'f-undo'));
                checkEq("harness seed 911 case $n: no forward-edit check fails", [], array_map(static fn(array $r): string => $r['invariant'] . ': ' . $r['what'], $forward));
            } finally {
                GoogleStand::forget($db, $u);
            }
        });
    }
};
