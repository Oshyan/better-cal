<?php

declare(strict_types=1);

// Probe (third independent read of the access branch): a day of a repeating event given something held here alone (a
// tag, a mark) while its Google calendar is view only, then the calendar made editable at Google (the access branch now
// learns that, where before a level once stored was never read again), then that one day edited or deleted here. The
// rule: a change to one day is sent to Google as that day's change and no other (docs/api-contract.md, Events: a day's
// edit and delete write the day's own instance; the plan's promise, "A change made here to an event on a Google calendar
// is sent to Google first"), so the series' other days, here and at Google, stay as they were. Nothing is computed from
// server/src. Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/access-read-lib.php';

return static function (Db $db): void {
    $la = new DateTimeZone('America/Los_Angeles');
    $make = static function () use ($db): object {
        $s = PA::make($db);
        $s->id = (int) $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => 'Sample series', 'start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00',
            'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=6'])['eventId'];
        $s->gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$s->id]);
        ($s->poll)();
        $s->fake->role = 'reader';
        ($s->poll)();
        return $s;
    };
    $shown = static function (object $s) use ($la): array {
        $utc = new DateTimeZone('UTC');
        $out = [];
        foreach ($s->events->window($s->u, new DateTimeImmutable('2027-03-01 00:00:00', $utc), new DateTimeImmutable('2027-04-30 00:00:00', $utc), [$s->calendarId], null, true) as $x) {
            $out[] = (new DateTimeImmutable((string) $x['start']))->setTimezone($la)->format('Y-m-d H:i') . '|' . ($x['title'] ?? '');
        }
        sort($out);
        return $out;
    };
    // What Google shows of the series: each live day, by the stand-in's own occurrences and records.
    $atGoogle = static function (object $s) use ($la): array {
        $master = $s->fake->events[$s->gid] ?? null;
        if ($master === null || ($master['status'] ?? '') === 'cancelled') {
            return [];
        }
        $out = [];
        foreach (\BetterCal\EditHarness\GoogleStand::expand($master) as $start) {
            $id = $s->gid . '_' . $start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
            $record = $s->fake->events[$id] ?? null;
            if ($record !== null && ($record['status'] ?? '') === 'cancelled') {
                continue;
            }
            $out[] = $start->setTimezone($la)->format('Y-m-d H:i') . '|' . ($record['summary'] ?? $master['summary'] ?? '');
        }
        sort($out);
        return $out;
    };
    $days = ['2027-03-01 10:00', '2027-03-08 10:00', '2027-03-15 10:00', '2027-03-22 10:00', '2027-03-29 10:00', '2027-04-05 10:00'];
    $held = [
        'a tag' => fn(object $s) => $s->events->patch($s->u, $s->id, ['scope' => 'this', 'instanceStart' => '2027-03-08T10:00:00-08:00', 'tagNames' => ['sample-tag']]),
        'a mark (Planned)' => fn(object $s) => $s->events->setAttendance($s->u, $s->id, 'going', 'this', '2027-03-08T10:00:00-08:00'),
    ];

    foreach ($held as $what => $give) {
        foreach (['deleted', 'renamed'] as $then) {
            scenario("probe 3, day rows: a day given $what while its calendar is view only, the calendar then made editable at Google, that day then $then here: only that day changes, here and at Google", function () use ($db, $make, $shown, $atGoogle, $days, $give, $what, $then): void {
                $s = $make();
                $give($s);
                $row = $db->one('SELECT id, google_event_id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$s->id]);
                check("view only, $what on 8 March: the day has a row of its own here (the probe's own step)", $row !== null, 'no row');
                $s->fake->role = 'writer';
                ($s->poll)();
                checkEq('made editable at Google: the poll stores it, and the series shows its six days here and at Google', ['writer', 6, 6], [PA::storedRole($db, $s->calendarId), count($shown($s)), count($atGoogle($s))]);
                $row = $db->one('SELECT id, google_event_id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$s->id]);
                if ($row === null) {
                    check('the day\'s row is still here after the poll (not so: nothing further to check)', true);
                    return;
                }
                $from = count($s->fake->requests);
                $e = PA::caught(function () use ($s, $row, $then): void {
                    $then === 'deleted' ? $s->events->deleteEvent($s->u, (int) $row['id'], null, null) : $s->events->patch($s->u, (int) $row['id'], ['title' => 'Sample series (8 March)']);
                    $s->events->throwDeferred();
                });
                $writes = array_values(array_filter(array_map(static fn(array $r): string => $r['method'] . ' ' . rawurldecode(basename((string) $r['path'])) . ' ' . ($r['status'] ?? ''), array_slice($s->fake->requests, $from)),
                    static fn(string $r): bool => !str_starts_with($r, 'GET')));
                $other = array_values(array_filter($days, static fn(string $d): bool => $d !== '2027-03-08 10:00'));
                $expected = array_map(static fn(string $d): string => "$d|Sample series", $other);
                if ($then === 'renamed') {
                    $expected[] = '2027-03-08 10:00|Sample series (8 March)';
                    sort($expected);
                }
                if ($e !== null) {
                    // Refused is no harm: nothing changed. Then every day is as it was, here and at Google.
                    $expected = array_map(static fn(string $d): string => "$d|Sample series", $days);
                }
                checkEq("the day $then (" . ($e === null ? 'through' : 'refused: ' . PA::code($e)) . '; writes: ' . json_encode($writes) . '): at Google the series\' other five days are as they were' . ($e === null ? ", and 8 March is $then" : ''), $expected, $atGoogle($s));
                checkEq("the day $then: here the calendar shows the same", $expected, $shown($s));
            });
        }
    }
};
