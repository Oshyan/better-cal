<?php

declare(strict_types=1);

// Feeds::standing with no database: which stored day row a record of a list
// is for (workfolder/reviews/audit-155-identity.md, row 24). A row stands at
// its own key while its series keeps its form; when the form changes it
// stands at the key of its own day in the new form, which west of UTC is
// not the row at an equal key. Every expected key is written out by hand:
// 19:00 in New York in January is 00:00 UTC the next day, 09:00 in Tokyo is
// 00:00 UTC the same day. Required from run.php. The database half is
// tests/db/sync-form-match.test.php.

use BetterCal\Domain\Feeds;

$ssSeries = static fn(array $over): array => $over + ['id' => 1, 'title' => 'Sample practice', 'description' => null, 'location' => null, 'url' => null, 'status' => 'confirmed',
    'rrule' => 'FREQ=DAILY;COUNT=10', 'exdates_json' => null, 'deleted_at' => null];
$ssTimed = static fn(string $zone, string $startUtc, array $over = []): array => $ssSeries($over + ['all_day' => 0, 'tzid' => $zone, 'start_utc' => $startUtc,
    'end_utc' => (new DateTimeImmutable($startUtc, new DateTimeZone('UTC')))->modify('+1 hour')->format('Y-m-d H:i:s')]);
$ssDays = static fn(array $over = []): array => $ssSeries($over + ['all_day' => 1, 'tzid' => 'UTC', 'start_utc' => '2027-01-04 00:00:00', 'end_utc' => '2027-01-05 00:00:00']);
$ssRow = static fn(int $id, string $key, ?string $deleted = null): array => ['id' => $id, 'recurrence_parent_id' => 1, 'recurrence_instance_utc' => $key, 'google_event_id' => null, 'deleted_at' => $deleted];
// What standing answers, as key => row id, the ids that leave their keys, and whether the form changed.
$ssShape = static function (array $stand): array {
    $at = array_map(static fn(array $r): int => (int) $r['id'], $stand['at']);
    ksort($at);
    $leaving = array_map(static fn(array $r): int => (int) $r['id'], $stand['leaving']);
    sort($leaving);
    return [$at, $leaving, $stand['reformed']];
};

// Daily at 19:00 New York from 4 January 2027: rows for 6, 7 and 8 January.
$ssNewYork = $ssTimed('America/New_York', '2027-01-05 00:00:00');
$ssNewYorkRows = [$ssRow(6, '2027-01-07 00:00:00'), $ssRow(7, '2027-01-08 00:00:00'), $ssRow(8, '2027-01-09 00:00:00')];
checkEq('standing: west of UTC, timed made all-day: each row stands at its own day\'s date, never at the equal key, and every row leaves its key', [
    ['2027-01-06 00:00:00' => 6, '2027-01-07 00:00:00' => 7, '2027-01-08 00:00:00' => 8], [6, 7, 8], true,
], $ssShape(Feeds::standing($ssNewYorkRows, $ssNewYork, $ssDays())));
// The same days as all-day rows, the series made 19:00 New York.
$ssDayRows = [$ssRow(6, '2027-01-06 00:00:00'), $ssRow(7, '2027-01-07 00:00:00'), $ssRow(8, '2027-01-08 00:00:00')];
checkEq('standing: west of UTC, all-day made timed: each row stands at 19:00 of its own day, the next date\'s midnight in UTC', [
    ['2027-01-07 00:00:00' => 6, '2027-01-08 00:00:00' => 7, '2027-01-09 00:00:00' => 8], [6, 7, 8], true,
], $ssShape(Feeds::standing($ssDayRows, $ssDays(), $ssNewYork)));
// At UTC and east of it the timed key at 00:00 UTC is the same day's date: every row stands where it is.
checkEq('standing: at 00:00 UTC, timed made all-day: every row stands at its own key and none leaves', [
    ['2027-01-06 00:00:00' => 6, '2027-01-07 00:00:00' => 7, '2027-01-08 00:00:00' => 8], [], true,
], $ssShape(Feeds::standing($ssDayRows, $ssTimed('UTC', '2027-01-04 00:00:00'), $ssDays())));
checkEq('standing: at 09:00 Tokyo, all-day made timed: every row stands at its own key and none leaves', [
    ['2027-01-06 00:00:00' => 6, '2027-01-07 00:00:00' => 7, '2027-01-08 00:00:00' => 8], [], true,
], $ssShape(Feeds::standing($ssDayRows, $ssDays(), $ssTimed('Asia/Tokyo', '2027-01-04 00:00:00'))));
// East of UTC away from midnight (19:00 Tokyo is 10:00 UTC the same day): no key coincides, each row still stands at its day.
checkEq('standing: at 19:00 Tokyo, timed made all-day: each row stands at its own day\'s date', [
    ['2027-01-06 00:00:00' => 6, '2027-01-07 00:00:00' => 7], [6, 7], true,
], $ssShape(Feeds::standing([$ssRow(6, '2027-01-06 10:00:00'), $ssRow(7, '2027-01-07 10:00:00')], $ssTimed('Asia/Tokyo', '2027-01-04 10:00:00'), $ssDays())));
// The form kept: a row stands at its own key whatever else the series changed (here its clock, an hour later).
checkEq('standing: the form kept and the clock moved: every row stands at its own key, none leaves, and the form is unchanged', [
    ['2027-01-07 00:00:00' => 6, '2027-01-08 00:00:00' => 7, '2027-01-09 00:00:00' => 8], [], false,
], $ssShape(Feeds::standing($ssNewYorkRows, $ssNewYork, $ssTimed('America/New_York', '2027-01-05 01:00:00'))));
// A day the series no longer has stands nowhere: it leaves its key and no record is for it.
checkEq('standing: made all-day and cut to four days: the rows of 6 and 7 January stand at their dates, the row of 8 January stands nowhere', [
    ['2027-01-06 00:00:00' => 6, '2027-01-07 00:00:00' => 7], [6, 7, 8], true,
], $ssShape(Feeds::standing($ssNewYorkRows, $ssNewYork, $ssDays(['rrule' => 'FREQ=DAILY;COUNT=4']))));
// A day the series as it comes skips stands nowhere either.
checkEq('standing: made all-day with 7 January skipped: its row stands nowhere', [
    ['2027-01-06 00:00:00' => 6, '2027-01-08 00:00:00' => 8], [6, 7, 8], true,
], $ssShape(Feeds::standing($ssNewYorkRows, $ssNewYork, $ssDays(['exdates_json' => '["2027-01-07 00:00:00"]']))));
// Two rows of one day (two hours of an hourly series made all-day): the live row with the lower id is found there.
$ssHourly = $ssTimed('America/New_York', '2027-01-04 15:00:00', ['rrule' => 'FREQ=HOURLY;COUNT=40']);
checkEq('standing: two rows whose day is one: the live row with the lower id is the one a record is for, a deleted one last', [
    ['2027-01-04 00:00:00' => 12], [11, 12, 13], true,
], $ssShape(Feeds::standing([$ssRow(11, '2027-01-04 16:00:00', '2027-01-02 00:00:00'), $ssRow(13, '2027-01-04 17:00:00'), $ssRow(12, '2027-01-04 18:00:00')], $ssHourly, $ssDays())));
