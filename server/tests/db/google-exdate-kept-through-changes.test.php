<?php

declare(strict_types=1);

// From the outside read of 5f60dd8b..14a17871 (its probes, made regular tests), sentence 2: "Google never brings the day back". A day Google holds as
// an EXDATE line, under each change to the whole series or part of it the brief lists: a clock change, a switch to
// all-day and back, a rule change, a bound change, a split before and after the day, delete following, and EXDATE
// lines written in other forms than the series' start (UTC, a comma list, another zone). The rule the expectations
// come from: a deleted day stays deleted, at Google and here, whatever else the change does. By date. Invented data.

use BetterCal\Domain\GoogleWriter;
use BetterCal\Infra\Db;

require_once __DIR__ . '/google-stops-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    // Weekly Mondays 10:00 Los Angeles: 4, 11, 18, 25 Jan, 1, 8 Feb 2027. 18 Jan deleted when the series was made.
    $timed = ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6', 'exdates' => ['2027-01-18 18:00:00']];
    $allDay = ['start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true, 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=6', 'exdates' => ['2027-01-18 00:00:00']];
    $dates = static fn(array $view): array => array_values(array_unique(array_map(static fn(string $l): string => substr($l, 0, 10), $view)));
    /** After $act on a series made with $create: [error, dates here, dates at Google, half-done changes]. */
    $run = static function (string $name, array $create, callable $act, ?callable $seed = null) use ($db, $la, $dates): void {
        $t = H::make($db, $create);
        try {
            $error = null;
            try {
                $act($t);
            } catch (\Throwable $e) {
                $error = get_class($e) . ': ' . $e->getMessage();
            }
            $here = H::hereView($t, $la, '2027-01-01', '2027-04-01');
            $there = H::googleView($t, $la);
            check("$name: the change goes through", $error === null, (string) $error);
            check("$name: 18 Jan is still deleted here", !in_array('2027-01-18', $dates($here), true), implode(' / ', $here));
            check("$name: 18 Jan is still deleted at Google", !in_array('2027-01-18', $dates($there), true), implode(' / ', $there) . "\n   google: " . implode("\n           ", H::stored($t)));
            checkEq("$name: nothing half done", 0, count(H::halfDone($t)));
            H::settled($t, $name, $la, '2027-01-01', '2027-04-01');
            echo "  [$name] here: " . implode(' ', array_map(static fn(string $l): string => substr($l, 5, 11), $here)) . "\n";
        } finally {
            H::done($t);
        }
    };
    $at = '2027-01-04T10:00:00-08:00';
    $cases = [
        'timed, moved to 11:00' => [$timed, ['scope' => 'all', 'instanceStart' => $at, 'start' => '2027-01-04T11:00:00-08:00', 'end' => '2027-01-04T12:00:00-08:00']],
        'timed, made all-day' => [$timed, ['scope' => 'all', 'instanceStart' => $at, 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true]],
        'timed, moved to Tuesdays' => [$timed, ['scope' => 'all', 'instanceStart' => $at, 'start' => '2027-01-05T10:00:00-08:00', 'end' => '2027-01-05T11:00:00-08:00']],
        'timed, rule to eight times' => [$timed, ['scope' => 'all', 'rrule' => 'FREQ=WEEKLY;COUNT=8']],
        'timed, rule to Mondays and Wednesdays' => [$timed, ['scope' => 'all', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=12']],
        'timed, an hour longer' => [$timed, ['scope' => 'all', 'instanceStart' => $at, 'start' => $at, 'end' => '2027-01-04T12:00:00-08:00']],
        'timed, zone to Denver at the same moment' => [$timed, ['scope' => 'all', 'instanceStart' => $at, 'start' => '2027-01-04T11:00:00-07:00', 'end' => '2027-01-04T12:00:00-07:00', 'tzid' => 'America/Denver']],
        'timed, a link given' => [$timed, ['scope' => 'all', 'url' => 'https://example.test/sample']],
        'timed, this and following renamed from 25 Jan (after the day)' => [$timed, ['scope' => 'following', 'instanceStart' => '2027-01-25T10:00:00-08:00', 'title' => 'Renamed part']],
        'timed, this and following renamed from 11 Jan (before the day)' => [$timed, ['scope' => 'following', 'instanceStart' => '2027-01-11T10:00:00-08:00', 'title' => 'Renamed part']],
        'timed, this and following moved to 11:00 from 11 Jan' => [$timed, ['scope' => 'following', 'instanceStart' => '2027-01-11T10:00:00-08:00', 'start' => '2027-01-11T11:00:00-08:00', 'end' => '2027-01-11T12:00:00-08:00']],
        'all-day, renamed' => [$allDay, ['scope' => 'all', 'title' => 'Sample series, renamed']],
        'all-day, given 10:00' => [$allDay, ['scope' => 'all', 'instanceStart' => '2027-01-04', 'start' => $at, 'end' => '2027-01-04T11:00:00-08:00', 'allDay' => false, 'tzid' => $la]],
        'all-day, moved to Tuesdays' => [$allDay, ['scope' => 'all', 'instanceStart' => '2027-01-04', 'start' => '2027-01-05', 'end' => '2027-01-06', 'allDay' => true]],
    ];
    $only = getenv('PROBE_ONLY') ?: null;
    foreach ($cases as $what => [$create, $body]) {
        if ($only !== null && !str_contains($what, $only)) {
            continue;
        }
        scenario("exdate probe: $what", function () use ($run, $what, $create, $body): void {
            $run($what, $create, static function (object $t) use ($body): void {
                H::change($t, $body);
            });
        });
    }
    scenario('exdate probe: timed, delete following from 1 Feb', function () use ($run, $timed): void {
        $run('timed, delete following from 1 Feb', $timed, static function (object $t): void {
            plannedDelete($t->e, $t->u, $t->id, '2027-02-01T10:00:00-08:00');
            $t->e->throwDeferred();
        });
    });
    scenario('exdate probe: timed, all-day and back to 10:00', function () use ($run, $timed, $at, $la): void {
        $run('timed, all-day and back', $timed, static function (object $t) use ($at, $la): void {
            H::change($t, ['scope' => 'all', 'instanceStart' => $at, 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true]);
            H::change($t, ['scope' => 'all', 'instanceStart' => '2027-01-04', 'start' => $at, 'end' => '2027-01-04T11:00:00-08:00', 'allDay' => false, 'tzid' => $la]);
        });
    });
    scenario('exdate probe: timed, another day deleted and one renamed here first, then moved to 11:00 and back', function () use ($run, $timed, $at): void {
        $run('timed, with a cancelled record and a renamed day too, to 11:00 and back', $timed, static function (object $t) use ($at): void {
            H::skip($t, '2027-01-25T10:00:00-08:00');
            H::this($t, '2027-01-11T10:00:00-08:00', ['title' => 'Own title']);
            H::change($t, ['scope' => 'all', 'instanceStart' => $at, 'start' => '2027-01-04T11:00:00-08:00', 'end' => '2027-01-04T12:00:00-08:00']);
            H::change($t, ['scope' => 'all', 'instanceStart' => '2027-01-04T11:00:00-08:00', 'start' => $at, 'end' => '2027-01-04T11:00:00-08:00']);
        });
    });

    // EXDATE lines as other clients write them, put at the stand-in as an import leaves a series, then renamed for all events.
    $row = ['uid' => 'sample-probe-series@example.test', 'title' => 'Sample series', 'description' => null, 'location' => null, 'status' => 'confirmed', 'url' => null,
        'start_utc' => '2027-01-04 18:00:00', 'end_utc' => '2027-01-04 19:00:00', 'all_day' => 0, 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6', 'exdates_json' => null];
    foreach ([
        'a UTC line' => ['EXDATE:20270118T180000Z'],
        'two days on one line' => ['EXDATE;TZID=America/Los_Angeles:20270118T100000,20270201T100000'],
        'a line in New York time' => ['EXDATE;TZID=America/New_York:20270118T130000'],
        'the EXDATE line before the RRULE line' => null,
    ] as $what => $lines) {
        scenario("exdate probe: seeded with $what, renamed for all events, then moved to 11:00", function () use ($db, $la, $row, $what, $lines, $dates): void {
            $u = freshUser($db);
            seriesUndo($db, $u, false);
            $d = domains($db);
            $s = BetterCal\EditHarness\GoogleStand::make($db, $d->undo, $u);
            $body = GoogleWriter::importBody($row);
            $body['recurrence'] = $lines === null ? ['EXDATE;TZID=America/Los_Angeles:20270118T100000', 'RRULE:FREQ=WEEKLY;COUNT=6'] : array_merge(['RRULE:FREQ=WEEKLY;COUNT=6'], $lines);
            $s->fake->seed(['id' => 'smplprobe1'] + $body);
            $s->poll();
            $id = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, 'smplprobe1']);
            $t = (object) ['db' => $db, 'u' => $u, 'd' => $d, 's' => $s, 'e' => $s->events, 'id' => $id, 'gid' => 'smplprobe1'];
            try {
                $first = $dates(H::hereView($t, $la, '2027-01-01', '2027-04-01'));
                check("seeded with $what: read here with 18 Jan deleted", !in_array('2027-01-18', $first, true), implode(' ', $first));
                foreach (['renamed' => ['scope' => 'all', 'title' => 'Sample series, renamed'],
                    'moved to 11:00' => ['scope' => 'all', 'instanceStart' => '2027-01-04T10:00:00-08:00', 'start' => '2027-01-04T11:00:00-08:00', 'end' => '2027-01-04T12:00:00-08:00']] as $step => $change) {
                    [, $error] = H::tryChange($t, $change);
                    $here = $dates(H::hereView($t, $la, '2027-01-01', '2027-04-01'));
                    $there = $dates(H::googleView($t, $la));
                    checkEq("seeded with $what, $step: goes through, 18 Jan deleted here and at Google, nothing half done", [null, false, false, 0],
                        [$error, in_array('2027-01-18', $here, true), in_array('2027-01-18', $there, true), count(H::halfDone($t))]);
                    echo "  [seeded with $what, $step] here: " . implode(' ', array_map(static fn(string $l): string => substr($l, 5), $here)) . ' | google: ' . implode(' ', array_map(static fn(string $l): string => substr($l, 5), $there))
                        . ' | lines: ' . implode(' ', (array) ($s->fake->events['smplprobe1']['recurrence'] ?? [])) . "\n";
                }
            } finally {
                H::done($t);
            }
        });
    }
};
