<?php

declare(strict_types=1);

// The key an iCalendar name of an instance gives (Ics::keysUnder), with no
// database: every form a RECURRENCE-ID or an EXDATE is written in (a
// date-time in the series' zone, in another zone, as a Z time, with no zone;
// a DATE) under every form a series is written in (timed in a zone, timed
// with no zone, all-day as DATEs, all-day as zoned midnights), and for each
// the record's own form varied, since the record's own DTSTART must never
// decide which day it is for. A key is a UTC instant under a timed series
// and a date at 00:00:00 UTC under an all-day one (series-model.md,
// Vocabulary). Every expected key is written out by hand: 19:00 in New York
// in January is 00:00 UTC the next day (and 23:00 UTC the same day in its
// summer), midnight in New York is 05:00 UTC, midnight in Tokyo is 15:00 UTC
// the day before. Invented data. Required from run.php; the database half is
// tests/db/ics-instance-names.test.php.

use BetterCal\Domain\Ics;

$ikCal = static fn(array $events): string => implode("\r\n", array_merge(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sample//Feed//EN'],
    ...array_map(static fn(array $e): array => array_merge(['BEGIN:VEVENT', 'UID:sample-keys@example.test', 'DTSTAMP:20270101T000000Z'], $e, ['END:VEVENT']), $events))) . "\r\nEND:VCALENDAR\r\n";
// The keys of the day records a text gives, in the order listed, and its series' skipped days.
$ikKeys = static function (array $events) use ($ikCal): array {
    $days = [];
    foreach (Ics::parse($ikCal($events), 'America/New_York') as $ev) {
        if ($ev['recurrence_instance_utc'] !== null) {
            $days[] = $ev['recurrence_instance_utc'];
        }
    }
    return $days;
};
$ikSkips = static function (array $series) use ($ikCal): array {
    return Ics::parse($ikCal([$series]), 'America/New_York')[0]['exdates'];
};

// The series, each daily from 4 January 2027.
$ikSeries = [
    'timed 19:00 New York' => ['DTSTART;TZID=America/New_York:20270104T190000', 'DTEND;TZID=America/New_York:20270104T200000', 'RRULE:FREQ=DAILY', 'SUMMARY:Sample game'],
    'timed 19:00 with no zone, read in New York' => ['DTSTART:20270104T190000', 'DTEND:20270104T200000', 'RRULE:FREQ=DAILY', 'SUMMARY:Sample game'],
    'all-day as DATEs' => ['DTSTART;VALUE=DATE:20270104', 'DTEND;VALUE=DATE:20270105', 'RRULE:FREQ=DAILY', 'SUMMARY:Sample camp'],
    'all-day as zoned midnights New York' => ['DTSTART;TZID=America/New_York:20270104T000000', 'DTEND;TZID=America/New_York:20270105T000000', 'RRULE:FREQ=DAILY', 'SUMMARY:Sample camp'],
    'all-day as zoned midnights Tokyo' => ['DTSTART;TZID=Asia/Tokyo:20270104T000000', 'DTEND;TZID=Asia/Tokyo:20270105T000000', 'RRULE:FREQ=DAILY', 'SUMMARY:Sample camp'],
];
// What a record of 6 January is itself: none of these may change its key.
$ikRecords = [
    'a timed record' => ['DTSTART;TZID=America/New_York:20270106T140000', 'DTEND;TZID=America/New_York:20270106T150000', 'SUMMARY:Sample, afternoon'],
    'a record with no zone' => ['DTSTART:20270106T140000', 'DTEND:20270106T150000', 'SUMMARY:Sample, afternoon'],
    'a whole-day record as a DATE' => ['DTSTART;VALUE=DATE:20270106', 'DTEND;VALUE=DATE:20270107', 'SUMMARY:Sample, whole day'],
    'a whole-day record as zoned midnights' => ['DTSTART;TZID=America/New_York:20270106T000000', 'DTEND;TZID=America/New_York:20270107T000000', 'SUMMARY:Sample, whole day'],
];
// The names of 6 January, by the series form they are written for and beside it, and the key each must give.
$ikTimedKey = '2027-01-07 00:00:00'; // 19:00 New York on 6 January
$ikDayKey = '2027-01-06 00:00:00';
$ikNames = [
    'timed 19:00 New York' => [$ikTimedKey, [
        'a date-time in the series\' zone' => 'RECURRENCE-ID;TZID=America/New_York:20270106T190000',
        'a date-time in another zone' => 'RECURRENCE-ID;TZID=America/Los_Angeles:20270106T160000',
        'a Z time' => 'RECURRENCE-ID:20270107T000000Z',
        'a date-time with no zone' => 'RECURRENCE-ID:20270106T190000',
        'a DATE' => 'RECURRENCE-ID;VALUE=DATE:20270106',
    ]],
    'timed 19:00 with no zone, read in New York' => [$ikTimedKey, [
        'a date-time with no zone' => 'RECURRENCE-ID:20270106T190000',
        'a date-time in a zone' => 'RECURRENCE-ID;TZID=America/New_York:20270106T190000',
        'a DATE' => 'RECURRENCE-ID;VALUE=DATE:20270106',
    ]],
    'all-day as DATEs' => [$ikDayKey, [
        'a DATE' => 'RECURRENCE-ID;VALUE=DATE:20270106',
        'a midnight in a zone' => 'RECURRENCE-ID;TZID=America/New_York:20270106T000000',
        'a Z midnight' => 'RECURRENCE-ID:20270106T000000Z',
        'a midnight with no zone' => 'RECURRENCE-ID:20270106T000000',
    ]],
    'all-day as zoned midnights New York' => [$ikDayKey, [
        'a midnight in the series\' zone' => 'RECURRENCE-ID;TZID=America/New_York:20270106T000000',
        'that midnight as a Z time' => 'RECURRENCE-ID:20270106T050000Z',
        'that midnight in another zone' => 'RECURRENCE-ID;TZID=America/Los_Angeles:20270105T210000',
        'a DATE' => 'RECURRENCE-ID;VALUE=DATE:20270106',
    ]],
    'all-day as zoned midnights Tokyo' => [$ikDayKey, [
        'a midnight in the series\' zone' => 'RECURRENCE-ID;TZID=Asia/Tokyo:20270106T000000',
        'that midnight as a Z time' => 'RECURRENCE-ID:20270105T150000Z',
        'a DATE' => 'RECURRENCE-ID;VALUE=DATE:20270106',
    ]],
];
foreach ($ikNames as $form => [$key, $names]) {
    foreach ($names as $how => $line) {
        foreach ($ikRecords as $record => $lines) {
            checkEq("ics keys: a series $form, 6 January named by $how, on $record: the key of 6 January under the series", [$key],
                $ikKeys([$ikSeries[$form], array_merge([$line], $lines)]));
        }
    }
}

// The neighbouring day, where the wrong reading lands on a real occurrence: a DATE under the timed series names its own
// date's occurrence, and 00:00 UTC of that date is the occurrence of the day before.
checkEq('ics keys: under 19:00 New York, RECURRENCE-ID;VALUE=DATE:20270107 is the occurrence of 7 January, never 00:00 UTC on the 7th, which is 6 January\'s',
    ['2027-01-08 00:00:00'], $ikKeys([$ikSeries['timed 19:00 New York'], ['RECURRENCE-ID;VALUE=DATE:20270107', 'DTSTART;TZID=America/New_York:20270107T210000', 'DTEND;TZID=America/New_York:20270107T220000', 'SUMMARY:Sample game, late']]));
checkEq('ics keys: a DATE under a timed series is the occurrence of that date as the series has it then: 19:00 New York on 15 July is 23:00 UTC',
    ['2027-07-15 23:00:00'], $ikKeys([$ikSeries['timed 19:00 New York'], ['RECURRENCE-ID;VALUE=DATE:20270715', 'DTSTART;TZID=America/New_York:20270715T210000', 'DTEND;TZID=America/New_York:20270715T220000', 'SUMMARY:Sample game, late']]));
checkEq('ics keys: under a series twice a day, a DATE in RECURRENCE-ID is the day\'s first occurrence',
    ['2027-01-06 14:00:00'], $ikKeys([['DTSTART;TZID=America/New_York:20270104T090000', 'DTEND;TZID=America/New_York:20270104T100000', 'RRULE:FREQ=DAILY;BYHOUR=9,15', 'SUMMARY:Sample shift'],
        ['RECURRENCE-ID;VALUE=DATE:20270106', 'DTSTART;TZID=America/New_York:20270106T100000', 'DTEND;TZID=America/New_York:20270106T110000', 'SUMMARY:Sample shift, late']]));

// Skipped dates: an EXDATE names an occurrence of the VEVENT it is in, which is the series.
$ikTimed = $ikSeries['timed 19:00 New York'];
foreach ([
    'a date-time in the series\' zone' => 'EXDATE;TZID=America/New_York:20270106T190000',
    'a Z time' => 'EXDATE:20270107T000000Z',
    'a date-time with no zone' => 'EXDATE:20270106T190000',
    'a DATE' => 'EXDATE;VALUE=DATE:20270106',
] as $how => $line) {
    checkEq("ics keys: a series timed 19:00 New York skipping 6 January by $how: the key of 6 January", [$ikTimedKey], $ikSkips(array_merge($ikTimed, [$line])));
}
foreach ([
    'all-day as DATEs' => ['a DATE' => 'EXDATE;VALUE=DATE:20270106', 'a midnight in a zone' => 'EXDATE;TZID=America/New_York:20270106T000000', 'a Z midnight' => 'EXDATE:20270106T000000Z'],
    'all-day as zoned midnights New York' => ['a midnight in the series\' zone' => 'EXDATE;TZID=America/New_York:20270106T000000', 'that midnight as a Z time' => 'EXDATE:20270106T050000Z', 'a DATE' => 'EXDATE;VALUE=DATE:20270106'],
    'all-day as zoned midnights Tokyo' => ['a midnight in the series\' zone' => 'EXDATE;TZID=Asia/Tokyo:20270106T000000', 'that midnight as a Z time' => 'EXDATE:20270105T150000Z'],
] as $form => $lines) {
    foreach ($lines as $how => $line) {
        checkEq("ics keys: a series $form skipping 6 January by $how: the date of 6 January", [$ikDayKey], $ikSkips(array_merge($ikSeries[$form], [$line])));
    }
}
checkEq('ics keys: under 19:00 New York, EXDATE;VALUE=DATE:20270107 skips 7 January, never 6 January, whose key is 00:00 UTC on the 7th',
    ['2027-01-08 00:00:00'], $ikSkips(array_merge($ikTimed, ['EXDATE;VALUE=DATE:20270107'])));
checkEq('ics keys: a series twice a day skipping a date by a DATE skips both of that day\'s occurrences',
    ['2027-01-06 14:00:00', '2027-01-06 20:00:00'], $ikSkips(['DTSTART;TZID=America/New_York:20270104T090000', 'DTEND;TZID=America/New_York:20270104T100000', 'RRULE:FREQ=DAILY;BYHOUR=9,15', 'SUMMARY:Sample shift', 'EXDATE;VALUE=DATE:20270106']));
checkEq('ics keys: a weekly Monday series skipping a Wednesday by a DATE names no occurrence: the series\' clock on that date, which hides nothing',
    ['2027-01-07 00:00:00'], $ikSkips(['DTSTART;TZID=America/New_York:20270104T190000', 'DTEND;TZID=America/New_York:20270104T200000', 'RRULE:FREQ=WEEKLY;BYDAY=MO', 'SUMMARY:Sample game', 'EXDATE;VALUE=DATE:20270106']));
checkEq('ics keys: two skipped dates on one line and a third on another, each named in its own way', ['2027-01-07 00:00:00', '2027-01-08 00:00:00', '2027-01-09 00:00:00'],
    $ikSkips(array_merge($ikTimed, ['EXDATE;TZID=America/New_York:20270106T190000,20270107T190000', 'EXDATE;VALUE=DATE:20270108'])));

// A record with no series in its text is named alone: a DATE is a day, a date-time an instant, and a midnight on a
// record itself sent as zoned midnights a day (a sender that writes its days that way).
$ikAlone = static fn(array $record): array => $ikKeys([$record]);
checkEq('ics keys: alone, a whole-day record as zoned midnights named by 19:00 New York is that instant, the occurrence of a timed series', [$ikTimedKey],
    $ikAlone(array_merge(['RECURRENCE-ID;TZID=America/New_York:20270106T190000'], $ikRecords['a whole-day record as zoned midnights'])));
checkEq('ics keys: alone, a whole-day record as a DATE named by 19:00 New York is that instant', [$ikTimedKey],
    $ikAlone(array_merge(['RECURRENCE-ID;TZID=America/New_York:20270106T190000'], $ikRecords['a whole-day record as a DATE'])));
checkEq('ics keys: alone, a whole-day record as a DATE named by 19:00 with no zone is that clock where times with no zone are read', [$ikTimedKey],
    $ikAlone(array_merge(['RECURRENCE-ID:20270106T190000'], $ikRecords['a whole-day record as a DATE'])));
checkEq('ics keys: alone, a whole-day record as zoned midnights named by its own midnight is that date', [$ikDayKey],
    $ikAlone(array_merge(['RECURRENCE-ID;TZID=America/New_York:20270106T000000'], $ikRecords['a whole-day record as zoned midnights'])));
checkEq('ics keys: alone, a whole-day record as zoned midnights named by a DATE is that date, not the day before', [$ikDayKey],
    $ikAlone(array_merge(['RECURRENCE-ID;VALUE=DATE:20270106'], $ikRecords['a whole-day record as zoned midnights'])));
checkEq('ics keys: alone, a timed record named by a DATE is that date', [$ikDayKey],
    $ikAlone(array_merge(['RECURRENCE-ID;VALUE=DATE:20270106'], $ikRecords['a timed record'])));

// A record whose series is only stored (a message about one day; a poll that keeps a series whose own record it
// could not read): Ics::under with the stored row, which holds no zone for an all-day series.
$ikStoredTimed = ['all_day' => 0, 'tzid' => 'America/New_York', 'start_utc' => '2027-01-05 00:00:00', 'end_utc' => '2027-01-05 01:00:00', 'rrule' => 'FREQ=DAILY', 'exdates_json' => null];
$ikStoredDays = ['all_day' => 1, 'tzid' => 'UTC', 'start_utc' => '2027-01-04 00:00:00', 'end_utc' => '2027-01-05 00:00:00', 'rrule' => 'FREQ=DAILY', 'exdates_json' => null];
$ikUnder = static function (array $record, array $stored) use ($ikCal): ?string {
    return Ics::under(Ics::parse($ikCal([$record]), 'America/New_York')[0], $stored)['recurrence_instance_utc'];
};
checkEq('ics keys: under a stored 19:00 New York series, a whole-day record as zoned midnights named by 19:00 on the 6th is 6 January\'s occurrence', $ikTimedKey,
    $ikUnder(array_merge(['RECURRENCE-ID;TZID=America/New_York:20270106T190000'], $ikRecords['a whole-day record as zoned midnights']), $ikStoredTimed));
checkEq('ics keys: under a stored 19:00 New York series, a whole-day record named by the DATE of the 7th is 7 January\'s occurrence', '2027-01-08 00:00:00',
    $ikUnder(['RECURRENCE-ID;VALUE=DATE:20270107', 'DTSTART;VALUE=DATE:20270107', 'DTEND;VALUE=DATE:20270108', 'SUMMARY:Sample, whole day'], $ikStoredTimed));
checkEq('ics keys: under a stored all-day series, a timed record named by 19:00 New York on the 6th is the date of the 6th, not the 7th its instant is on in UTC', $ikDayKey,
    $ikUnder(array_merge(['RECURRENCE-ID;TZID=America/New_York:20270106T190000'], $ikRecords['a timed record']), $ikStoredDays));
checkEq('ics keys: under a stored all-day series, a timed record named by its own midnight is that date', $ikDayKey,
    $ikUnder(array_merge(['RECURRENCE-ID;TZID=America/New_York:20270106T000000'], $ikRecords['a timed record']), $ikStoredDays));
checkEq('ics keys: under a stored all-day series, a record named by a Z midnight is that date', $ikDayKey,
    $ikUnder(array_merge(['RECURRENCE-ID:20270106T000000Z'], $ikRecords['a timed record']), $ikStoredDays));
checkEq('ics keys: a record with no name kept (one that is no iCalendar text\'s) is left as it is', '2027-01-07 00:00:00',
    Ics::under(['uid' => 'sample', 'recurrence_instance_utc' => '2027-01-07 00:00:00'], $ikStoredDays)['recurrence_instance_utc']);
