<?php

declare(strict_types=1);

// From the outside read of 5f60dd8b..14a17871 (its probes, made regular tests), sentences 1 and 3: a change to all events stopped at each of its
// requests, the reads among them (the read before each day's write is new in this range), with the network staying
// down through the stop (so the stop's own read fails and the baseline is the prediction) or one answer lost.
// A weekly series at 10:00 Los Angeles, six Mondays from 4 Jan 2027: 11 Jan renamed, 18 Jan deleted here (a cancelled
// record at Google), 1 Feb deleted when the series was made (an EXDATE line at Google).
// Expectations by date (PF::stops), each change's worked out here from what was asked: until the read-back rule the
// stops were compared with what the uninterrupted change left, so a fault the two shared (a day at its own time ending
// at the series' time after the zone change) passed. Invented data.

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
    // What each change should leave, by date, worked out here from what was asked (PF::stops' $expect), as the calendar
    // shows a day in Los Angeles: "date time|title|notes|place". The days that show are the Mondays 4, 11 and 25 January
    // and 8 February (18 January and 1 February are deleted); $over gives a date its own time or title, and $more adds days.
    $shown = static function (string $time, string $title, string $notes, array $over, array $more = []): array {
        $out = [];
        foreach (array_merge(['2027-01-04', '2027-01-11', '2027-01-25', '2027-02-08'], $more) as $date) {
            [$at, $name] = ($over[$date] ?? []) + [$time, $title];
            $out[] = $date . ' ' . $at . '|' . $name . '|' . $notes . '|';
        }
        return $out;
    };
    $own = ['2027-01-11' => [1 => 'Sample series (own)']];
    $changes = [
        'renamed for all events' => [['scope' => 'all', 'title' => 'Sample series, renamed'], $shown('10:00', 'Sample series, renamed', '', $own)],
        'moved to 11:00 for all events' => [['scope' => 'all', 'instanceStart' => '2027-01-04T10:00:00-08:00', 'start' => '2027-01-04T11:00:00-08:00', 'end' => '2027-01-04T12:00:00-08:00'], $shown('11:00', 'Sample series', '', $own)],
        'made all-day for all events' => [['scope' => 'all', 'instanceStart' => '2027-01-04T10:00:00-08:00', 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true], $shown('all-day', 'Sample series', '', $own)],
    ];
    $only = getenv('PROBE_ONLY') ?: null;
    foreach ($changes as $what => [$body, $expect]) {
        if ($only !== null && !str_contains($what, $only)) {
            continue;
        }
        PF::stops("stops: $what", $build, static function (object $t) use ($body): void {
            H::change($t, $body);
        }, $la, '2027-01-01', '2027-03-01', explode(',', getenv('PROBE_MODES') ?: 'down,lost'), $expect);
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
    // This series' own days: 11 January with its own title (and length and link, which the view by date doesn't show),
    // 25 January at its own time, 14:00.
    $own2 = ['2027-01-11' => [1 => 'Sample series (own)'], '2027-01-25' => [0 => '14:00']];
    $asItWas = $shown('10:00', 'Sample series', '', $own2);
    $more = [
        // The same moments, read in Los Angeles: every day where it was, the day at its own time among them.
        'zone to Denver at the same moments' => [['scope' => 'all', 'instanceStart' => $at, 'start' => '2027-01-04T11:00:00-07:00', 'end' => '2027-01-04T12:00:00-07:00', 'tzid' => 'America/Denver'], $asItWas],
        'a link given to all events' => [['scope' => 'all', 'url' => 'https://example.test/series'], $asItWas],
        // Twelve events on Mondays and Wednesdays from 4 January: the six Wednesdays to 10 February beside the Mondays.
        'Wednesdays added' => [['scope' => 'all', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=12'], $shown('10:00', 'Sample series', '', $own2, ['2027-01-06', '2027-01-13', '2027-01-20', '2027-01-27', '2027-02-03', '2027-02-10'])],
        'an hour longer for all events' => [['scope' => 'all', 'instanceStart' => $at, 'start' => $at, 'end' => '2027-01-04T12:00:00-08:00'], $asItWas],
        'notes for all events' => [['scope' => 'all', 'description' => 'Sample notes'], $shown('10:00', 'Sample series', 'Sample notes', $own2)],
        // 'this and following' moved to 11:00 from 11 Jan, stopped after its new series is made with the network down, loses
        // a day's own time and a deleted day at Finish and is refused by Put back: the same on the tree before 2026-11-13's
        // product changes; filed, not run here.
        // The days before 25 January are left: 4 January and 11 January.
        'delete following from 25 Jan' => [null, ['2027-01-04 10:00|Sample series||', '2027-01-11 10:00|Sample series (own)||']],
    ];
    if (getenv('PROBE_MORE') !== '0') {
        foreach ($more as $what => [$body, $expect]) {
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
            }, $la, '2027-01-01', '2027-04-01', ['down'], $expect);
        }
    }
    $odd = array_values(array_filter(PF::$lines, static fn(string $l): bool => str_contains($l, 'REFUSED') || str_contains($l, 'FAILED')));
    echo "  ---- stopped runs: " . count(PF::$lines) . '; Put back refused with nothing changed at Google, or a recovery failed: ' . count($odd) . " ----\n  " . implode("\n  ", $odd) . "\n";
};
