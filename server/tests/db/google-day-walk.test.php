<?php

declare(strict_types=1);

// From the second independent read of the 1.5.6 fixes (its probe, made a regular test): one day with its own title,
// notes, place and time, one day with its own notes alone, one day with its own place alone and one deleted day,
// walked through each kind of change to all events, on a series with no notes and on one with notes. The expectation
// is the claim's: a day's own notes survive a change to all events, and so does everything else of its own the change
// does not name. After each change: what the calendar shows is what Google lists (H::settled), and each day still
// holds, here and at Google, each thing of its own that the change did not name. Two of its 28 cases (the zone to
// Denver at the same moments, with and without notes on the series) failed before the read-back rule
// (docs/design/google-series-read-back.md): the day at its own time ended 18:00 to 22:30 UTC. Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $first = '2027-01-04T10:00:00-08:00';
    $base = ['start' => $first, 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6'];
    // [name, body, choices, what of a day's own the change names (and may therefore change)]
    $changes = [
        'title' => [['title' => 'Renamed series'], [], ['title']],
        'notes set' => [['description' => 'New series notes'], [], ['notes']],
        'notes cleared' => [['description' => ''], [], ['notes']],
        'place' => [['location' => 'New place'], [], ['place']],
        'link' => [['url' => 'https://example.test/sample'], [], []],
        'time an hour later' => [['instanceStart' => $first, 'start' => '2027-01-04T11:00:00-08:00', 'end' => '2027-01-04T12:00:00-08:00'], [], ['time']],
        'an hour longer' => [['instanceStart' => $first, 'start' => $first, 'end' => '2027-01-04T12:00:00-08:00'], [], ['time']],
        // 11:00 Denver is 10:00 Los Angeles: the same moments (the preselected reading), so no day's own time is named by the change.
        'zone to Denver at the same moments' => [['instanceStart' => $first, 'tzid' => 'America/Denver', 'start' => '2027-01-04T11:00:00-07:00', 'end' => '2027-01-04T12:00:00-07:00'], [], []],
        'zone to Denver at the same clock time' => [['instanceStart' => $first, 'tzid' => 'America/Denver', 'start' => '2027-01-04T10:00:00-07:00', 'end' => '2027-01-04T11:00:00-07:00'], [], ['time']],
        'a weekday added' => [['rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=12'], [], []],
        'two more events' => [['rrule' => 'FREQ=WEEKLY;COUNT=8'], [], []],
        'one event fewer' => [['rrule' => 'FREQ=WEEKLY;COUNT=5'], [], []],
        'all-day' => [['instanceStart' => $first, 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true], [], ['time']],
    ];
    $dayRows = static function (object $t) use ($db): array {
        $out = [];
        foreach ($db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY recurrence_instance_utc', [$t->id]) as $r) {
            $out[] = $r;
        }
        return $out;
    };
    $find = static function (array $rows, string $col, string $value): ?array {
        foreach ($rows as $r) {
            if ((string) ($r[$col] ?? '') === $value) {
                return $r;
            }
        }
        return null;
    };
    $records = static function (object $t): array {
        $out = [];
        foreach ($t->s->fake->events as $id => $e) {
            if (isset($e['recurringEventId'], $e['originalStartTime']) && ($e['status'] ?? '') !== 'cancelled') {
                $out[(string) $id] = $e;
            }
        }
        return $out;
    };
    $findRecord = static function (array $records, string $field, string $value): ?array {
        foreach ($records as $e) {
            if ((string) ($e[$field] ?? '') === $value) {
                return $e;
            }
        }
        return null;
    };
    $utc = new DateTimeZone('UTC');
    $at = static fn(?array $when): string => $when === null ? '' : (isset($when['date']) ? (string) $when['date'] : (new DateTimeImmutable((string) $when['dateTime']))->setTimezone($utc)->format('Y-m-d H:i'));

    foreach (['no notes' => null, 'notes of its own' => 'Series notes'] as $seriesNotes => $notes) {
        foreach ($changes as $what => [$body, $choices, $names]) {
            if ($what === 'notes cleared' && $notes === null) {
                continue;
            }
            scenario("day walk: $what for all events, on a series with $seriesNotes", function () use ($db, $base, $notes, $what, $body, $choices, $names, $dayRows, $find, $records, $findRecord, $at, $seriesNotes): void {
                $t = H::make($db, $base + ($notes !== null ? ['description' => $notes] : []));
                try {
                    // Mon 11 Jan: its own title, notes, place and time (13:00 to 14:30). Mon 18 Jan: its own notes. Mon 1 Feb: its own place. Mon 25 Jan: deleted.
                    H::this($t, '2027-01-11T10:00:00-08:00', ['title' => 'Own title', 'description' => 'Own notes A', 'location' => 'Own place A', 'start' => '2027-01-11T13:00:00-08:00', 'end' => '2027-01-11T14:30:00-08:00']);
                    H::this($t, '2027-01-18T10:00:00-08:00', ['description' => 'Own notes B']);
                    H::this($t, '2027-02-01T10:00:00-08:00', ['location' => 'Own place C']);
                    H::skip($t, '2027-01-25T10:00:00-08:00');
                    [$plan, $error] = H::tryChange($t, ['scope' => 'all'] + $body, $choices);
                    $label = "$what, $seriesNotes";
                    if ($error !== null) {
                        check("$label: the change goes through", false, $error);
                        return;
                    }
                    checkEq("$label: nothing half done", [], H::halfDone($t));
                    $rows = $dayRows($t);
                    $recs = $records($t);
                    $a = $find($rows, 'description', 'Own notes A');
                    $b = $find($rows, 'description', 'Own notes B');
                    $c = $find($rows, 'location', 'Own place C');
                    $ga = $findRecord($recs, 'description', 'Own notes A');
                    $gb = $findRecord($recs, 'description', 'Own notes B');
                    $gc = $findRecord($recs, 'location', 'Own place C');
                    if (!in_array('notes', $names, true)) {
                        checkEq("$label: both days keep their own notes, here and at Google", [true, true, true, true], [$a !== null, $b !== null, $ga !== null, $gb !== null]);
                    }
                    if (!in_array('place', $names, true)) {
                        checkEq("$label: the days with a place of their own keep it, here and at Google", ['Own place A', 'Own place C', 'Own place A', 'Own place C'],
                            [$a['location'] ?? ($find($rows, 'location', 'Own place A')['location'] ?? null), $c['location'] ?? null, $ga['location'] ?? ($findRecord($recs, 'location', 'Own place A')['location'] ?? null), $gc['location'] ?? null]);
                    }
                    if (!in_array('title', $names, true)) {
                        checkEq("$label: the day with a title of its own keeps it, here and at Google", ['Own title', 'Own title'], [$find($rows, 'title', 'Own title')['title'] ?? null, $findRecord($recs, 'summary', 'Own title')['summary'] ?? null]);
                    }
                    if (!in_array('time', $names, true)) {
                        // Its own time, 13:00 to 14:30 Los Angeles on 11 January: 21:00 to 22:30 UTC, whatever zone the series names now.
                        $ta = $find($rows, 'title', 'Own title');
                        $tg = $findRecord($recs, 'summary', 'Own title');
                        checkEq("$label: the day with a time of its own keeps it (11 Jan 21:00 to 22:30 UTC), here and at Google", ['2027-01-11 21:00:00', '2027-01-11 22:30:00', '2027-01-11 21:00', '2027-01-11 22:30'],
                            [$ta['start_utc'] ?? null, $ta['end_utc'] ?? null, $at($tg['start'] ?? null), $at($tg['end'] ?? null)]);
                    }
                    H::settled($t, $label, 'America/Los_Angeles', '2026-12-28', '2027-03-15');
                } finally {
                    H::done($t);
                }
            });
        }
    }
};
