<?php

declare(strict_types=1);

// From the outside read of 5f60dd8b..14a17871 (its probes, made regular tests), sentence 5: one day in the other form than its repeating event, on a
// Google calendar, through every path the brief lists: the edit, its undo and redo, a second edit of the same day,
// its delete, a change to all events over it (stopped at each request, then Finish and Put back), a switch of the
// whole series' form and back, and a split carrying it. The rule: the day keeps its own time (or its own all-day
// date) and nothing stops half done; expectations by date. Invented data.

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

require_once __DIR__ . '/google-stops-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    // All-day Mondays 1, 8, 15, 22 March 2027; 15 March given 15:00 to 16:00 Los Angeles.
    $allDay = ['start' => '2027-03-01', 'end' => '2027-03-02', 'allDay' => true, 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
    $timedDay = ['start' => '2027-03-15T15:00:00-07:00', 'end' => '2027-03-15T16:00:00-07:00', 'allDay' => false, 'tzid' => $la];
    // 10:00 Mondays; 15 March made all-day.
    $timed = ['start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
    $allDayDay = ['start' => '2027-03-15', 'end' => '2027-03-16', 'allDay' => true];
    $view = static fn(object $t): array => array_map(static fn(string $l): string => substr(explode('|', $l)[0], 5) . '|' . explode('|', $l)[1], H::hereView($t, $la, '2027-02-20', '2027-04-10'));
    $gview = static fn(object $t): array => array_map(static fn(string $l): string => substr(explode('|', $l)[0], 5) . '|' . explode('|', $l)[1], H::googleView($t, $la));
    $undo = static function (object $t, int $entry): string {
        try {
            (new BetterCal\Domain\Undo($t->db, null, $t->s->writer))->undoById($t->u, $entry, false);
            $t->e->throwDeferred();
            return 'done';
        } catch (HttpError $e) {
            return $e->errorCode . ': ' . $e->getMessage();
        } catch (\Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }
    };
    $buildA = static function () use ($db, $allDay, $timedDay): object {
        $t = H::make($db, $allDay);
        H::this($t, '2027-03-15', $timedDay);
        return $t;
    };
    $buildT = static function () use ($db, $timed, $allDayDay): object {
        $t = H::make($db, $timed);
        H::this($t, '2027-03-15T10:00:00-07:00', $allDayDay);
        return $t;
    };

    scenario('other form probe: a day of an all-day series given 15:00, then what the calendar and Google show', function () use ($buildA, $view, $gview): void {
        $t = $buildA();
        try {
            checkEq('all-day series, 15 Mar at 15:00: here', ['03-01 all-day|Sample series', '03-08 all-day|Sample series', '03-15 15:00|Sample series', '03-22 all-day|Sample series'], $view($t));
            checkEq('all-day series, 15 Mar at 15:00: at Google', $view($t), $gview($t));
            H::settled($t, 'all-day series, 15 Mar at 15:00', 'America/Los_Angeles', '2027-02-20', '2027-04-10');
        } finally {
            H::done($t);
        }
    });

    // The day edited again in each way, then deleted.
    foreach ([
        'given 17:00 instead' => [['start' => '2027-03-15T17:00:00-07:00', 'end' => '2027-03-15T18:00:00-07:00', 'allDay' => false, 'tzid' => $la], '03-15 17:00|Sample series'],
        'made all-day again' => [['start' => '2027-03-15', 'end' => '2027-03-16', 'allDay' => true], '03-15 all-day|Sample series'],
        'moved to the 16th at 15:00' => [['start' => '2027-03-16T15:00:00-07:00', 'end' => '2027-03-16T16:00:00-07:00', 'allDay' => false, 'tzid' => $la], '03-16 15:00|Sample series'],
        'renamed' => [['title' => 'Own title'], '03-15 15:00|Own title'],
    ] as $what => [$edit, $line]) {
        scenario("other form probe: the timed day of an all-day series, $what", function () use ($buildA, $view, $gview, $what, $edit, $line): void {
            $t = $buildA();
            try {
                [, $error] = H::tryChange($t, ['scope' => 'this', 'instanceStart' => '2027-03-15'] + $edit);
                $want = ['03-01 all-day|Sample series', '03-08 all-day|Sample series', $line, '03-22 all-day|Sample series'];
                checkEq("timed day $what: goes through; here and at Google by date", [null, $want, $want], [$error, $view($t), $gview($t)]);
                H::settled($t, "timed day $what", 'America/Los_Angeles', '2027-02-20', '2027-04-10');
            } finally {
                H::done($t);
            }
        });
    }
    scenario('other form probe: the timed day of an all-day series deleted, and the delete undone', function () use ($buildA, $view, $gview, $undo, $db): void {
        $t = $buildA();
        try {
            $error = null;
            try {
                H::skip($t, '2027-03-15');
            } catch (\Throwable $e) {
                $error = get_class($e) . ': ' . $e->getMessage();
            }
            $want = ['03-01 all-day|Sample series', '03-08 all-day|Sample series', '03-22 all-day|Sample series'];
            checkEq('timed day deleted: goes through; here and at Google by date', [null, $want, $want], [$error, $view($t), $gview($t)]);
            $said = $undo($t, lastEntry($db, $t->u));
            $back = ['03-01 all-day|Sample series', '03-08 all-day|Sample series', '03-15 15:00|Sample series', '03-22 all-day|Sample series'];
            checkEq('the delete undone: the day back at 15:00, here and at Google', ['done', $back, $back], [$said, $view($t), $gview($t)]);
        } finally {
            H::done($t);
        }
    });
    scenario('other form probe: the edit that gave the day 15:00 undone, then redone', function () use ($buildA, $view, $gview, $undo, $db): void {
        $t = $buildA();
        try {
            $entry = lastEntry($db, $t->u);
            $said = $undo($t, $entry);
            $plain = ['03-01 all-day|Sample series', '03-08 all-day|Sample series', '03-15 all-day|Sample series', '03-22 all-day|Sample series'];
            checkEq('the edit undone: 15 Mar all-day again, here and at Google', ['done', $plain, $plain], [$said, $view($t), $gview($t)]);
            $said = $undo($t, lastEntry($db, $t->u));
            $back = ['03-01 all-day|Sample series', '03-08 all-day|Sample series', '03-15 15:00|Sample series', '03-22 all-day|Sample series'];
            checkEq('and redone: 15 Mar at 15:00 again, here and at Google', ['done', $back, $back], [$said, $view($t), $gview($t)]);
            H::settled($t, 'the edit undone and redone', 'America/Los_Angeles', '2027-02-20', '2027-04-10');
        } finally {
            H::done($t);
        }
    });
    scenario('other form probe: the reverse, a day of a timed series made all-day, undone and redone', function () use ($buildT, $view, $gview, $undo, $db): void {
        $t = $buildT();
        try {
            $made = ['03-01 10:00|Sample series', '03-08 10:00|Sample series', '03-15 all-day|Sample series', '03-22 10:00|Sample series'];
            checkEq('timed series, 15 Mar all-day: here and at Google', [$made, $made], [$view($t), $gview($t)]);
            $said = $undo($t, lastEntry($db, $t->u));
            $plain = ['03-01 10:00|Sample series', '03-08 10:00|Sample series', '03-15 10:00|Sample series', '03-22 10:00|Sample series'];
            checkEq('undone: 15 Mar at 10:00 again', ['done', $plain, $plain], [$said, $view($t), $gview($t)]);
            $said = $undo($t, lastEntry($db, $t->u));
            checkEq('redone: 15 Mar all-day again', ['done', $made, $made], [$said, $view($t), $gview($t)]);
        } finally {
            H::done($t);
        }
    });

    // Changes to all events and a split over such a day, each stopped at every request (the network staying down), then Finish and Put back.
    $at = '2027-03-01';
    $only = getenv('PROBE_ONLY') ?: null;
    foreach ([
        'all-day series with a 15:00 day, renamed for all events' => [$buildA, ['scope' => 'all', 'title' => 'Sample series, renamed']],
        'all-day series with a 15:00 day, moved to Tuesdays for all events' => [$buildA, ['scope' => 'all', 'instanceStart' => $at, 'start' => '2027-03-02', 'end' => '2027-03-03', 'allDay' => true]],
        'all-day series with a 15:00 day, given 10:00 for all events' => [$buildA, ['scope' => 'all', 'instanceStart' => $at, 'start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'allDay' => false, 'tzid' => $la]],
        'all-day series with a 15:00 day, this and following renamed from 8 Mar' => [$buildA, ['scope' => 'following', 'instanceStart' => '2027-03-08', 'title' => 'Renamed part']],
        'timed series with an all-day day, renamed for all events' => [$buildT, ['scope' => 'all', 'title' => 'Sample series, renamed']],
        'timed series with an all-day day, moved to 11:00 for all events' => [$buildT, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']],
        'timed series with an all-day day, made all-day for all events' => [$buildT, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01', 'end' => '2027-03-02', 'allDay' => true]],
    ] as $what => [$build, $body]) {
        if ($only !== null && !str_contains($what, $only)) {
            continue;
        }
        PF::stops("other form: $what", $build, static function (object $t) use ($body): void {
            H::change($t, $body);
        }, $la, '2027-02-20', '2027-04-10', ['down']);
        // What the uninterrupted change leaves, by date, for the report.
        $t = $build();
        try {
            [, $error] = H::tryChange($t, $body);
            echo "  [$what] " . ($error ?? 'ok') . ' => ' . implode(' / ', $view($t)) . "\n";
        } finally {
            H::done($t);
        }
    }
    $refused = array_values(array_filter(PF::$lines, static fn(string $l): bool => str_contains($l, 'REFUSED') || str_contains($l, 'FAILED')));
    echo "  ---- stopped runs: " . count(PF::$lines) . ', of them Put back refused or a recovery failed: ' . count($refused) . " ----\n  " . implode("\n  ", $refused) . "\n";
};
