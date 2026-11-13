<?php

declare(strict_types=1);

// From the outside read of 5f60dd8b..14a17871 (its probes, made regular tests), sentences 1 and 3: a change to all events stopped at each of its
// requests, the reads among them (the read before each day's write is new in this range), with the network staying
// down through the stop (so the stop's own read fails and the baseline is the prediction) or one answer lost.
// A weekly series at 10:00 Los Angeles, six Mondays from 4 Jan 2027: 11 Jan renamed, 18 Jan deleted here (a cancelled
// record at Google), 1 Feb deleted when the series was made (an EXDATE line at Google).
// Expectations by date (PF::stops). Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/google-stops-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $build = static function () use ($db, $la): object {
        $t = H::make($db, ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6', 'exdates' => ['2027-02-01 18:00:00']]);
        H::this($t, '2027-01-11T10:00:00-08:00', ['title' => 'Sample series (own)']);
        H::skip($t, '2027-01-18T10:00:00-08:00');
        return $t;
    };
    $changes = [
        'renamed for all events' => ['scope' => 'all', 'title' => 'Sample series, renamed'],
        'moved to 11:00 for all events' => ['scope' => 'all', 'instanceStart' => '2027-01-04T10:00:00-08:00', 'start' => '2027-01-04T11:00:00-08:00', 'end' => '2027-01-04T12:00:00-08:00'],
        'made all-day for all events' => ['scope' => 'all', 'instanceStart' => '2027-01-04T10:00:00-08:00', 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true],
    ];
    $only = getenv('PROBE_ONLY') ?: null;
    foreach ($changes as $what => $body) {
        if ($only !== null && !str_contains($what, $only)) {
            continue;
        }
        PF::stops("stops: $what", $build, static function (object $t) use ($body): void {
            H::change($t, $body);
        }, $la, '2027-01-01', '2027-03-01', explode(',', getenv('PROBE_MODES') ?: 'down,lost'));
    }
    // More shapes, the network staying down from each request on: a day with its own length and link and a day with
    // its own time under a zone change at the same moments (probe 3.17: Google keeps the record, its start reset and
    // its end left), a link given to all events over a day with its own link (3.32: written into every record),
    // weekdays added (3.42 to 3.47: a cancelled record kept), an hour longer, notes, a split moved to 11:00, delete following.
    $build2 = static function () use ($db, $la): object {
        $t = H::make($db, ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6', 'exdates' => ['2027-02-01 18:00:00']]);
        H::this($t, '2027-01-11T10:00:00-08:00', ['title' => 'Sample series (own)', 'start' => '2027-01-11T10:00:00-08:00', 'end' => '2027-01-11T12:30:00-08:00', 'url' => 'https://example.test/own']);
        H::this($t, '2027-01-25T10:00:00-08:00', ['start' => '2027-01-25T14:00:00-08:00', 'end' => '2027-01-25T15:00:00-08:00']);
        H::skip($t, '2027-01-18T10:00:00-08:00');
        return $t;
    };
    $at = '2027-01-04T10:00:00-08:00';
    $more = [
        'zone to Denver at the same moments' => ['scope' => 'all', 'instanceStart' => $at, 'start' => '2027-01-04T11:00:00-07:00', 'end' => '2027-01-04T12:00:00-07:00', 'tzid' => 'America/Denver'],
        'a link given to all events' => ['scope' => 'all', 'url' => 'https://example.test/series'],
        'Wednesdays added' => ['scope' => 'all', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=12'],
        'an hour longer for all events' => ['scope' => 'all', 'instanceStart' => $at, 'start' => $at, 'end' => '2027-01-04T12:00:00-08:00'],
        'notes for all events' => ['scope' => 'all', 'description' => 'Sample notes'],
        // 'this and following' moved to 11:00 from 11 Jan, stopped after its new series is made with the network down, loses
        // a day's own time and a deleted day at Finish and is refused by Put back: the same on the tree before 2026-11-13's
        // product changes; filed, not run here.
        'delete following from 25 Jan' => null,
    ];
    if (getenv('PROBE_MORE') !== '0') {
        foreach ($more as $what => $body) {
            if ($only !== null && !str_contains($what, $only)) {
                continue;
            }
            PF::stops("stops 2: $what", $build2, static function (object $t) use ($body): void {
                if ($body === null) {
                    plannedDelete($t->e, $t->u, $t->id, '2027-01-25T10:00:00-08:00');
                    $t->e->throwDeferred();
                    return;
                }
                H::change($t, $body);
            }, $la, '2027-01-01', '2027-04-01', ['down']);
        }
    }
    $odd = array_values(array_filter(PF::$lines, static fn(string $l): bool => str_contains($l, 'REFUSED') || str_contains($l, 'FAILED')));
    echo "  ---- stopped runs: " . count(PF::$lines) . '; Put back refused with nothing changed at Google, or a recovery failed: ' . count($odd) . " ----\n  " . implode("\n  ", $odd) . "\n";
};
