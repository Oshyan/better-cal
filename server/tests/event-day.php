<?php

declare(strict_types=1);

// Which day an event belongs to (Time::dayOf), the one reading every server
// path uses: a timed event's start on its own clock, an all-day event's UTC
// date. Invented events in three zones, each with a clock change of its own
// (one has none, so its change-day case is a plain day there). Included by
// run.php.

use BetterCal\Support\Time;

if (!function_exists('check')) {
    fwrite(STDERR, "Run through server/tests/run.php\n");
    exit(1);
}

// A local clock typed in a zone, as the UTC instant the row stores.
$stored = static fn(string $local, string $zone): \DateTimeImmutable => (new \DateTimeImmutable($local, new \DateTimeZone($zone)))->setTimezone(Time::utc());
// The days a zone's clock changes in a year, so a wrong change day fails
// loudly instead of testing an ordinary one.
$changeDays = static function (string $zone, int $year): array {
    $tz = new \DateTimeZone($zone);
    $from = (new \DateTimeImmutable("$year-01-01", $tz))->getTimestamp();
    $to = (new \DateTimeImmutable(($year + 1) . '-01-01', $tz))->getTimestamp();
    $out = [];
    foreach ($tz->getTransitions($from, $to) as $t) {
        if ($t['ts'] > $from) {
            $out[] = (new \DateTimeImmutable('@' . $t['ts']))->setTimezone($tz)->format('Y-m-d');
        }
    }
    return $out;
};

$zones = [
    // zone, a timed start on 2027-05-10 whose UTC date is another day, the change days.
    ['America/Los_Angeles', '2027-05-10 19:30', ['2027-03-14', '2027-11-07']],
    ['Pacific/Auckland', '2027-05-10 09:00', ['2027-04-04', '2027-09-26']],
    ['Asia/Tokyo', '2027-05-10 08:00', []],
];
foreach ($zones as [$zone, $timed, $changes]) {
    $tz = new \DateTimeZone($zone);
    check("$zone: the year's clock changes are the expected days", $changeDays($zone, 2027) === $changes, implode(',', $changeDays($zone, 2027)));

    // A timed event: the day its start falls on its own clock, not the UTC date.
    $at = $stored($timed, $zone);
    check("$zone: a timed event is on its own clock's day", Time::dayOf($at, false, $zone) === '2027-05-10', Time::dayOf($at, false, $zone));
    check("$zone: the UTC date of that start is another day", $at->format('Y-m-d') !== '2027-05-10', $at->format('Y-m-d'));
    check("$zone: a DateTimeZone reads the same as its name", Time::dayOf($at, false, $tz) === Time::dayOf($at, false, $zone));

    // An all-day event: its stored UTC date, whatever zone is passed.
    $day = Time::fromDb('2027-05-10 00:00:00');
    check("$zone: an all-day event is its UTC date", Time::dayOf($day, true, $zone) === '2027-05-10', Time::dayOf($day, true, $zone));
    check("$zone: an all-day event with no zone is the same date", Time::dayOf($day, true, null) === '2027-05-10');
    check("$zone: an all-day day is read on UTC", Time::dayZone(true, $zone)->getName() === 'UTC');
    check("$zone: a timed day is read on its own clock", Time::dayZone(false, $zone)->getName() === $zone);

    // An overnight event belongs to the day it starts; its end is on the next.
    $start = $stored('2027-05-10 23:00', $zone);
    $end = $stored('2027-05-11 01:00', $zone);
    check("$zone: an overnight event is on its start's day", Time::dayOf($start, false, $zone) === '2027-05-10');
    check("$zone: an overnight event's end is on the next day", Time::dayOf($end, false, $zone) === '2027-05-11');

    // Across a clock change: the clocks before and after the change on each
    // change day read as that day, the first instant of a repeated clock too.
    foreach ($changes as $changeDay) {
        foreach (['00:30', '01:30', '03:30', '23:30'] as $clock) {
            $at = $stored("$changeDay $clock", $zone);
            check("$zone: $changeDay $clock is on $changeDay", Time::dayOf($at, false, $zone) === $changeDay, Time::dayOf($at, false, $zone));
        }
        $last = $stored("$changeDay 23:30", $zone);
        check("$zone: an hour after the change day's last half hour is the next day", Time::dayOf($last->modify('+1 hour'), false, $zone) === (new \DateTimeImmutable($changeDay))->modify('+1 day')->format('Y-m-d'));
    }
    if ($changes === []) {
        $at = $stored('2027-03-14 00:30', $zone);
        check("$zone: no clock change, a day's first half hour is its own", Time::dayOf($at, false, $zone) === '2027-03-14' && $at->format('Y-m-d') === '2027-03-13');
    }
}

// Read on another clock (a duplicate check on the Home clock): that clock's day.
$late = $stored('2027-05-10 19:30', 'America/Los_Angeles');
check('a timed event read on a clock far east is that clock\'s day', Time::dayOf($late, false, 'Asia/Tokyo') === '2027-05-11');
check('an all-day event read on a clock far east keeps its date', Time::dayOf(Time::fromDb('2027-05-10 00:00:00'), true, 'Asia/Tokyo') === '2027-05-10');
check('an unknown zone reads as UTC', Time::dayOf($late, false, 'Nowhere/Invented') === $late->format('Y-m-d'));
