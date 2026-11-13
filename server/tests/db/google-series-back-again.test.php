<?php

declare(strict_types=1);

// Issue 256 and the baseline of "changed at Google since", against the stand-in as the probe of 2026-11-13 found
// Google (docs/design/research/google-series/probe-2026-11-13.md; decisions.md, "Each day's write in a change to all
// events carries that day's own etag" and "A step's expectation is Google's answer to its write").
//
// First, from the rule (each day's write carries that day's own etag): a weekly series of four with a renamed day and
// a deleted day, changed for all events and changed back (timed to all-day and back, all-day to timed and back, 10:00
// to 11:00 and back), completes each time in one go, the renamed day keeping its title and the deleted day staying
// deleted, at Google and here, nothing half done (3.36, 3.37, 3.40 live: the third request refused).
//
// Then the two requirements on what Put back compares Google with: a field Google sets by itself on our write (its
// reminders, once all-day) is ours and Put back runs; a change someone else makes at Google between our master write
// and our next read is never ours, and Put back is refused.
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
    // write (the next write meets the network down): Google answered the master with reminders it set itself (useDefault
    // false, as on every all-day event), and Put back runs.
    scenario('google back again: a switch to all-day stopped after its master write, Google setting the master\'s reminders itself; Put back runs', function () use ($begin, $to, $state, $keys, $db): void {
        $t = $begin('10:00');
        try {
            $t->s->knobs->writes = 0;
            $t->s->knobs->netAt = 2;
            [, $stopped] = H::tryChange($t, $to('10:00', 'all-day'));
            $t->s->knobs->netAt = null;
            $t->s->knobs->down = false;
            checkEq('the switch stops half done after its master write', [true, 1], [$stopped !== null, count(H::halfDone($t))]);
            [, $refused] = H::recover($t, 'putback');
            checkEq('Put back runs: the series timed again, the renamed day and the deleted day as they were, nothing half done',
                [null, ['Sample series (own)', [$keys['10:00'][1]], 'Sample series (own)', [$keys['10:00'][1]], 0]], [$refused, $state($t, '10:00')]);
        } finally {
            H::done($t);
        }
    });

    // Requirement 2: a change someone else makes at Google between our master write and our next read is never ours. A
    // clock change for all events (10:00 to 11:00), with someone renaming an ordinary day (22 March) at Google right after
    // the master write; the change's first day step lands and its second meets the network down. Put back is refused.
    // The same with the stranger's change on the master (its place), the change stopped right after its master write.
    foreach ([
        'an ordinary day renamed' => ['_20270322T180000Z', ['summary' => 'Renamed in Google Calendar'], 3, 'a day step'],
        'the series\' place changed' => ['', ['location' => 'Sample hall, theirs'], 2, 'its master write'],
    ] as $what => [$suffix, $body, $downAt, $after]) {
        scenario("google back again: a clock change, $what at Google right after its master write, stopped after $after: Put back is refused", function () use ($begin, $to, $what, $suffix, $body, $downAt, $after): void {
            $t = $begin('10:00');
            try {
                $t->s->knobs->race = static function () use ($t, $suffix, $body): void {
                    H::atGoogle($t, 'PATCH', '/events/' . rawurlencode($t->gid . $suffix), $body);
                };
                $t->s->knobs->writes = 0;
                $t->s->knobs->netAt = $downAt; // 3: the master's write and the first day's land; 2: the master's alone
                [, $stopped] = H::tryChange($t, $to('10:00', '11:00'));
                $t->s->knobs->netAt = null;
                $t->s->knobs->down = false;
                $t->s->knobs->race = null;
                checkEq("$what: the change stops half done after $after", [true, 1], [$stopped !== null, count(H::halfDone($t))]);
                [, $refused] = H::recover($t, 'putback');
                check("$what: Put back is refused as changed at Google since, and still listed half done", $refused !== null && str_contains($refused, 'google_changed_since') && count(H::halfDone($t)) === 1, (string) $refused);
            } finally {
                H::done($t);
            }
        });
    }
};
