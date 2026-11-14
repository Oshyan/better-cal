<?php

declare(strict_types=1);

// Issue 256 and the baseline of "changed at Google since", against the stand-in as the probe of 2026-11-13 found
// Google (docs/design/research/google-series/probe-2026-11-13.md; decisions.md, "Each day's write in a change to all
// events carries that day's own etag" and "The last write wins while one of our changes is being sent").
//
// First, from the rule (each day's write carries that day's own etag): a weekly series of four with a renamed day and
// a deleted day, changed for all events and changed back (timed to all-day and back, all-day to timed and back, 10:00
// to 11:00 and back), completes each time in one go, the renamed day keeping its title and the deleted day staying
// deleted, at Google and here, nothing half done (3.36, 3.37, 3.40 live: the third request refused).
//
// Then what Put back compares Google with, by the rule (the last write wins while our change is being sent; between
// changes Google is read first): what Google held when the stopped change read it at its stop. A field Google sets by
// itself on our write (its reminders, once all-day) is no change, and Put back runs; a change someone else makes inside
// the window, before that read, is not detected, and Put back goes ahead (documented); the same change made after the
// stop is refused.
//
// Expectations by date from the rules; nothing in server/src computes one. Google is the edit harness's stand-in.
// Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $timed = static fn(string $clock): array => ['start' => "2027-03-01T$clock:00-08:00", 'end' => '2027-03-01T' . sprintf('%02d', (int) $clock + 1) . substr($clock, 2) . ':00-08:00', 'tzid' => $la];
    $allDay = ['start' => '2027-03-01', 'end' => '2027-03-02', 'allDay' => true, 'tzid' => 'UTC'];
    // Occurrence 2 (8 March) and 3 (15 March; daylight time from 14 March), by their key in each form.
    $keys = [
        '10:00' => ['2027-03-08 18:00:00', '2027-03-15 17:00:00', '2027-03-08T10:00:00-08:00', '2027-03-15T10:00:00-07:00'],
        '11:00' => ['2027-03-08 19:00:00', '2027-03-15 18:00:00', '2027-03-08T11:00:00-08:00', '2027-03-15T11:00:00-07:00'],
        'all-day' => ['2027-03-08 00:00:00', '2027-03-15 00:00:00', '2027-03-08', '2027-03-15'],
    ];
    $shape = static fn(string $form): array => $form === 'all-day' ? $allDay + ['rrule' => 'FREQ=WEEKLY;COUNT=4'] : $timed($form) + ['rrule' => 'FREQ=WEEKLY;COUNT=4'];
    $to = static function (string $from, string $form) use ($keys, $timed, $allDay, $la): array {
        $at = $from === 'all-day' ? '2027-03-01' : $timed($from)['start'];
        $body = $form === 'all-day' ? ['start' => '2027-03-01', 'end' => '2027-03-02', 'allDay' => true] : ['start' => $timed($form)['start'], 'end' => $timed($form)['end'], 'allDay' => false, 'tzid' => $la];
        return ['scope' => 'all', 'instanceStart' => $at] + $body;
    };
    /** A series in $form with occurrence 2 renamed and occurrence 3 deleted. */
    $begin = static function (string $form) use ($db, $shape, $keys): object {
        $t = H::make($db, $shape($form));
        H::this($t, $keys[$form][2], ['title' => 'Sample series (own)']);
        H::skip($t, $keys[$form][3]);
        return $t;
    };
    /** [the renamed day's title at Google, the skips at Google, the renamed day's title here, the skips here, half-done changes]. */
    $state = static function (object $t, string $form) use ($db, $keys): array {
        $held = $t->s->held($t->gid) ?? ['records' => [], 'skips' => []];
        $row = $db->one('SELECT title FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$t->id, $keys[$form][0]]);
        $exdates = BetterCal\Domain\Recurrence::decodeExdates($db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$t->id]));
        sort($exdates);
        return [$held['records'][$keys[$form][0]][0] ?? null, $held['skips'], $row['title'] ?? null, $exdates, count(H::halfDone($t))];
    };

    foreach ([['10:00', 'all-day'], ['all-day', '10:00'], ['10:00', '11:00']] as [$from, $via]) {
        scenario("google back again: $from to $via and back, a renamed and a deleted day, each change in one go", function () use ($begin, $to, $state, $keys, $from, $via): void {
            $t = $begin($from);
            try {
                foreach ([[$from, $via], [$via, $from]] as $i => [$a, $b]) {
                    [, $error] = H::tryChange($t, $to($a, $b));
                    checkEq("$from to $via and back, change " . ($i + 1) . " ($a to $b): it goes through, the renamed day keeps its title and the deleted day stays deleted, at Google and here",
                        [null, ['Sample series (own)', [$keys[$b][1]], 'Sample series (own)', [$keys[$b][1]], 0]], [$error, $state($t, $b)]);
                }
            } finally {
                H::done($t);
            }
        });
    }

    // Requirement 1: a field Google sets by itself on our write is ours. A switch to all-day stopped right after its master
    // write (the next write refused by Google, the network up, so the change reads the series where it stops): Google
    // answered the master with reminders it set itself (useDefault false, as on every all-day event), and Put back runs
    // on what that stop read found.
    scenario('google back again: a switch to all-day stopped after its master write, Google setting the master\'s reminders itself; Put back runs', function () use ($begin, $to, $state, $keys, $db): void {
        $t = $begin('10:00');
        try {
            $t->s->knobs->writes = 0;
            $t->s->knobs->beforeAt = 2;
            $from = count($t->s->fake->requests);
            [, $stopped] = H::tryChange($t, $to('10:00', 'all-day'));
            $t->s->knobs->beforeAt = null;
            checkEq('the switch stops half done after its master write', [true, 1], [$stopped !== null, count(H::halfDone($t))]);
            // The stop read: after the master's write and its read-back, the series is read once more where the change stopped.
            $after = array_values(array_map(static fn(array $r): string => $r['method'] . (str_ends_with((string) $r['path'], '/events') ? ' list' : ''), array_slice($t->s->fake->requests, $from)));
            $patch = array_search('PATCH', $after, true);
            // And, since the access read's rule D, the one small list a refused write asks before it is read (it said writer).
            checkEq('after its master write the change read: the series (read-back), the small list after the refusal, the series where it stopped', ['GET list', 'GET list', 'GET list'],
                $patch === false ? null : array_values(array_filter(array_slice($after, $patch + 1), static fn(string $m): bool => $m === 'GET list')));
            [, $refused] = H::recover($t, 'putback');
            checkEq('Put back runs: the series timed again, the renamed day and the deleted day as they were, nothing half done',
                [null, ['Sample series (own)', [$keys['10:00'][1]], 'Sample series (own)', [$keys['10:00'][1]], 0]], [$refused, $state($t, '10:00')]);
        } finally {
            H::done($t);
        }
    });

    // The window: a change someone else makes at Google between our master write and the read where the change stopped is
    // inside the last-write-wins window, so it is not detected and Put back goes ahead (an ordinary day renamed; the
    // series' place changed). The change stops with a write Google refuses (403), so its stop read happens. The
    // counterpart: the same rename made after the change stopped is a change since, and Put back is refused.
    $stopAfterRace = static function (object $t, ?\Closure $race, int $refusedAt) use ($to): ?string {
        $t->s->knobs->race = $race;
        $t->s->knobs->writes = 0;
        $t->s->knobs->beforeAt = $refusedAt; // 3: the master's write and the first day's land; 2: the master's alone
        [, $stopped] = H::tryChange($t, $to('10:00', '11:00'));
        $t->s->knobs->beforeAt = null;
        $t->s->knobs->race = null;
        return $stopped;
    };
    foreach ([
        'an ordinary day renamed' => ['_20270322T180000Z', ['summary' => 'Renamed in Google Calendar'], 3, 'a day step'],
        'the series\' place changed' => ['', ['location' => 'Sample hall, theirs'], 2, 'its master write'],
    ] as $what => [$suffix, $body, $refusedAt, $after]) {
        scenario("google back again, last write wins: a clock change, $what at Google right after its master write (inside the window), stopped after $after: Put back goes ahead", function () use ($begin, $stopAfterRace, $what, $suffix, $body, $refusedAt, $after): void {
            $t = $begin('10:00');
            try {
                $stopped = $stopAfterRace($t, static function () use ($t, $suffix, $body): void {
                    H::atGoogle($t, 'PATCH', '/events/' . rawurlencode($t->gid . $suffix), $body);
                }, $refusedAt);
                checkEq("$what: the change stops half done after $after", [true, 1], [$stopped !== null, count(H::halfDone($t))]);
                [, $refused] = H::recover($t, 'putback');
                checkEq("$what: Put back goes ahead, nothing left half done (the change inside the window is not detected: the last write wins)", [null, 0], [$refused, count(H::halfDone($t))]);
            } finally {
                H::done($t);
            }
        });
    }
    scenario('google back again: a clock change stopped after a day step, then an ordinary day renamed at Google: Put back is refused', function () use ($begin, $stopAfterRace): void {
        $t = $begin('10:00');
        try {
            $stopped = $stopAfterRace($t, null, 3);
            checkEq('the change stops half done after a day step', [true, 1], [$stopped !== null, count(H::halfDone($t))]);
            [$status] = H::atGoogle($t, 'PATCH', '/events/' . rawurlencode($t->gid . '_20270322T180000Z'), ['summary' => 'Renamed in Google Calendar']);
            checkEq('the stand-in takes the rename made after the stop', 200, $status);
            [, $refused] = H::recover($t, 'putback');
            check('Put back is refused as changed at Google since, and the change still listed half done', $refused !== null && str_contains($refused, 'google_changed_since') && count(H::halfDone($t)) === 1, (string) $refused);
        } finally {
            H::done($t);
        }
    });

    // One day of a repeating event in the other form: a clock time on a day of an all-day series (probe 3.38) and an
    // all-day day of a timed series (3.31), each one PATCH on the day's instance id with the held form's fields sent null,
    // as Google took both; the day stored here in its own form, the series as it was.
    foreach ([
        'a day of an all-day series given 15:00' => [$allDay + ['rrule' => 'FREQ=WEEKLY;COUNT=4'], '2027-03-22', ['start' => '2027-03-22T15:00:00-07:00', 'end' => '2027-03-22T16:00:00-07:00', 'allDay' => false, 'tzid' => $la],
            '_20270322', ['date' => null], 0, 1],
        'a day of a timed series made all-day' => [$timed('10:00') + ['rrule' => 'FREQ=WEEKLY;COUNT=4'], '2027-03-22T10:00:00-07:00', ['start' => '2027-03-22', 'end' => '2027-03-23', 'allDay' => true],
            '_20270322T170000Z', ['dateTime' => null, 'timeZone' => null], 1, 0],
    ] as $what => [$create, $inst, $edit, $suffix, $nulls, $dayAllDay, $seriesAllDay]) {
        scenario("google back again: $what, one PATCH with the held form's fields null", function () use ($db, $what, $create, $inst, $edit, $suffix, $nulls, $dayAllDay, $seriesAllDay): void {
            $t = H::make($db, $create);
            try {
                $t->s->fake->requests = [];
                [, $error] = H::tryChange($t, ['scope' => 'this', 'instanceStart' => $inst] + $edit);
                $writes = array_values(array_filter($t->s->fake->requests, static fn(array $r): bool => $r['method'] !== 'GET'));
                $day = $db->one('SELECT all_day FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$t->id]);
                $series = $db->one('SELECT all_day FROM events WHERE id = ?', [$t->id]);
                checkEq("$what: taken, one PATCH on the day's id naming the held form's fields null; the day in its own form here, the series as it was",
                    [null, [['PATCH', basename($t->gid . $suffix)]], $nulls, $dayAllDay, $seriesAllDay],
                    [$error, array_map(static fn(array $r): array => [$r['method'], rawurldecode(basename($r['path']))], $writes),
                        array_intersect_key((array) ($writes[0]['body']['start'] ?? []), $nulls), (int) ($day['all_day'] ?? -1), (int) ($series['all_day'] ?? -1)]);
            } finally {
                H::done($t);
            }
        });
    }
};
