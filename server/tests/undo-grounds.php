<?php

declare(strict_types=1);

// Undo::changeGround with no database: an entry's ground from the rows it
// keeps and its planner's record (docs/design/decisions.md, "The days a
// change brought into being are its ground", "Finish's entry records what
// the change did with the days by the function the commit uses"). The rule:
// a move stands in an entry only where the rows show the change carried
// something from that key to that key; a skip the plan meant to carry that
// the rows don't show carried is recorded as what happened to it (gone, or
// made), never as a move; and a skip the rows show carried is that move
// alone, never also two skips changed. Every expectation is written out by
// hand. Required from run.php. The database half is
// tests/db/google-series-recovery-grounds.test.php.

use BetterCal\Domain\Undo;

$ugSeries = static fn(array $skips, string $start = '2027-07-20 00:00:00'): array => ['id' => 1, 'recurrence_instance_utc' => null, 'recurrence_parent_id' => null, 'rrule' => 'FREQ=DAILY;COUNT=8',
    'start_utc' => $start, 'exdates_json' => $skips === [] ? null : json_encode($skips)];
$ugDay = static fn(string $key): array => ['id' => 2, 'recurrence_parent_id' => 1, 'recurrence_instance_utc' => $key, 'override_fields' => '["tags"]', 'start_utc' => $key];
$ugMove = static fn(string $from, string $to): array => ['events:1', "move:$from>$to"];
$ugGround = static function (array $before, array $after, array $recorded): array {
    $out = array_map(static fn(array $g): string => $g[0] . ' ' . $g[1], Undo::changeGround(['events' => $before], ['events' => $after], $recorded));
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
};
[$ugA, $ugB, $ugC] = ['2027-07-23 00:00:00', '2027-07-24 00:00:00', '2027-07-25 00:00:00'];

checkEq('change ground: a skip the plan carried and the rows show carried is its move alone',
    ['events:1 move:2027-07-23 00:00:00>2027-07-23 01:00:00', 'events:1 start_utc'],
    $ugGround([$ugSeries([$ugA])], [$ugSeries(['2027-07-23 01:00:00'], '2027-07-20 01:00:00')], [$ugMove($ugA, '2027-07-23 01:00:00')]));
checkEq('change ground: a skip the plan meant to carry that the series no longer has (Google kept the day) is that skip gone, and no move',
    ['events:1 skip:2027-07-23 00:00:00', 'events:1 start_utc'],
    $ugGround([$ugSeries([$ugA])], [$ugSeries([], '2027-07-20 03:00:00')], [$ugMove($ugA, '2027-07-23 01:00:00')]));
checkEq('change ground: of two skips the plan carried, the one the rows show is its move and the other is gone',
    ['events:1 move:2027-07-23 00:00:00>2027-07-23 01:00:00', 'events:1 skip:2027-07-25 00:00:00', 'events:1 start_utc'],
    $ugGround([$ugSeries([$ugA, $ugC])], [$ugSeries(['2027-07-23 01:00:00'], '2027-07-20 01:00:00')], [$ugMove($ugA, '2027-07-23 01:00:00'), $ugMove($ugC, '2027-07-25 01:00:00')]));
// Two neighbouring days skipped, the series dragged a day later: the first skip lands on the key the second left.
checkEq('change ground: skips carried in a chain, each onto the key the next one left, are their moves alone',
    ['events:1 move:2027-07-23 00:00:00>2027-07-24 00:00:00', 'events:1 move:2027-07-24 00:00:00>2027-07-25 00:00:00', 'events:1 start_utc'],
    $ugGround([$ugSeries([$ugA, $ugB])], [$ugSeries([$ugB, $ugC], '2027-07-21 00:00:00')], [$ugMove($ugA, $ugB), $ugMove($ugB, $ugC)]));
checkEq('change ground: a skip made on a key another skip was carried from is a skip made there, beside that move',
    ['events:1 move:2027-07-23 00:00:00>2027-07-24 00:00:00', 'events:1 skip:2027-07-23 00:00:00'],
    $ugGround([$ugSeries([$ugA])], [$ugSeries([$ugA, $ugB])], [$ugMove($ugA, $ugB)]));
checkEq("change ground: a day's row carried to another key is its move, read from the rows, recorded or not",
    [['events:1 move:2027-07-23 00:00:00>2027-07-24 00:00:00'], ['events:1 move:2027-07-23 00:00:00>2027-07-24 00:00:00']],
    [$ugGround([$ugSeries([]), $ugDay($ugA)], [$ugSeries([]), $ugDay($ugB)], []), $ugGround([$ugSeries([]), $ugDay($ugA)], [$ugSeries([]), $ugDay($ugB)], [$ugMove($ugA, $ugB)])]);
checkEq('change ground: with no rows to read (an entry given its ground whole), the record stands as given',
    [$ugMove($ugA, $ugB), ['events:1', 'added']], Undo::changeGround(null, null, [$ugMove($ugA, $ugB), ['events:1', 'added']]));
