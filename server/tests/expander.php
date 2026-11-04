<?php

declare(strict_types=1);

// The expander (Series\RuleIterator) against RFC 5545 3.3.10's table, cell
// by cell, and against each reading decisions.md records for repeating
// events. Every expected date is written out by hand (RFC 5545's own
// examples among them), never computed by the expander under test. No
// database. Required from run.php.

use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Series\Calc;
use BetterCal\Domain\Series\RuleIterator;
use BetterCal\Domain\Series\RuleNotReadable;
use BetterCal\Domain\Series\WalkLimitReached;
use BetterCal\Support\Time;

/** The first $n occurrences, as local wall clocks ('Y-m-d H:i:s'), or with $offset their offsets too. @return list<string> */
function exWalk(string $rrule, string $startLocal, string $tz = 'UTC', int $n = 10, bool $offset = false): array
{
    $it = new RuleIterator($rrule, new DateTimeImmutable($startLocal, Time::zone($tz)));
    $out = [];
    for ($it->rewind(); $it->valid() && count($out) < $n; $it->next()) {
        $out[] = $it->current()->format($offset ? 'Y-m-d H:i:sP' : 'Y-m-d H:i:s');
    }
    return $out;
}

/** Whether the expander refuses a rule as one it can't read. */
function exRefused(string $rrule): bool
{
    try {
        new RuleIterator($rrule, new DateTimeImmutable('2027-01-01 09:00:00', Time::utc()));
        return false;
    } catch (RuleNotReadable) {
        return true;
    }
}

// ---- The table: one cell per frequency and part --------------------------
// 2027 starts on a Friday; its months start Fri, Mon, Mon, Thu, Sat, Tue,
// Thu, Sun, Wed, Fri, Mon, Wed; 2028, a leap year, on a Saturday.

$table = [
    // SECONDLY: every part limits.
    'SECONDLY BYMONTH limits' => [['FREQ=SECONDLY;BYMONTH=2;COUNT=3', '2027-01-31 23:59:58'], ['2027-01-31 23:59:58', '2027-02-01 00:00:00', '2027-02-01 00:00:01']],
    'SECONDLY BYYEARDAY limits' => [['FREQ=SECONDLY;BYYEARDAY=-1;COUNT=3', '2027-12-30 23:59:59'], ['2027-12-30 23:59:59', '2027-12-31 00:00:00', '2027-12-31 00:00:01']],
    'SECONDLY BYMONTHDAY limits' => [['FREQ=SECONDLY;BYMONTHDAY=-1;COUNT=3', '2027-02-27 12:00:00'], ['2027-02-27 12:00:00', '2027-02-28 00:00:00', '2027-02-28 00:00:01']],
    'SECONDLY BYDAY limits' => [['FREQ=SECONDLY;BYDAY=MO;COUNT=3', '2027-01-02 08:00:00'], ['2027-01-02 08:00:00', '2027-01-04 00:00:00', '2027-01-04 00:00:01']],
    'SECONDLY BYHOUR limits' => [['FREQ=SECONDLY;BYHOUR=10;COUNT=3', '2027-01-01 09:59:58'], ['2027-01-01 09:59:58', '2027-01-01 10:00:00', '2027-01-01 10:00:01']],
    'SECONDLY BYMINUTE limits' => [['FREQ=SECONDLY;BYMINUTE=5;COUNT=3', '2027-01-01 09:03:00'], ['2027-01-01 09:03:00', '2027-01-01 09:05:00', '2027-01-01 09:05:01']],
    // Every 7 seconds from 09:00:00: second 0 again at 7 minutes, 30 at 210 seconds.
    'SECONDLY BYSECOND limits' => [['FREQ=SECONDLY;INTERVAL=7;BYSECOND=0,30;COUNT=4', '2027-01-01 09:00:00'], ['2027-01-01 09:00:00', '2027-01-01 09:03:30', '2027-01-01 09:07:00', '2027-01-01 09:10:30']],
    'SECONDLY BYSETPOS limits' => [['FREQ=SECONDLY;BYSECOND=10;BYSETPOS=1;COUNT=2', '2027-01-01 09:00:10'], ['2027-01-01 09:00:10', '2027-01-01 09:01:10']],
    // MINUTELY: BYSECOND expands, the rest limit.
    'MINUTELY BYMONTH limits' => [['FREQ=MINUTELY;INTERVAL=30;BYMONTH=3;COUNT=3', '2027-02-28 23:30:00'], ['2027-02-28 23:30:00', '2027-03-01 00:00:00', '2027-03-01 00:30:00']],
    'MINUTELY BYYEARDAY limits' => [['FREQ=MINUTELY;INTERVAL=720;BYYEARDAY=1,3;COUNT=3', '2027-01-01 00:00:00'], ['2027-01-01 00:00:00', '2027-01-01 12:00:00', '2027-01-03 00:00:00']],
    'MINUTELY BYMONTHDAY limits' => [['FREQ=MINUTELY;INTERVAL=720;BYMONTHDAY=2;COUNT=3', '2027-01-01 12:00:00'], ['2027-01-01 12:00:00', '2027-01-02 00:00:00', '2027-01-02 12:00:00']],
    'MINUTELY BYDAY limits' => [['FREQ=MINUTELY;INTERVAL=1440;BYDAY=MO;COUNT=3', '2027-01-01 09:00:00'], ['2027-01-01 09:00:00', '2027-01-04 09:00:00', '2027-01-11 09:00:00']],
    'MINUTELY BYHOUR limits' => [['FREQ=MINUTELY;INTERVAL=20;BYHOUR=9,10;COUNT=7', '2027-01-01 09:00:00'], ['2027-01-01 09:00:00', '2027-01-01 09:20:00', '2027-01-01 09:40:00', '2027-01-01 10:00:00', '2027-01-01 10:20:00', '2027-01-01 10:40:00', '2027-01-02 09:00:00']],
    'MINUTELY BYMINUTE limits' => [['FREQ=MINUTELY;INTERVAL=15;BYMINUTE=0,45;COUNT=4', '2027-01-01 09:00:00'], ['2027-01-01 09:00:00', '2027-01-01 09:45:00', '2027-01-01 10:00:00', '2027-01-01 10:45:00']],
    'MINUTELY BYSECOND expands' => [['FREQ=MINUTELY;BYSECOND=0,30;COUNT=3', '2027-01-01 09:00:15'], ['2027-01-01 09:00:15', '2027-01-01 09:00:30', '2027-01-01 09:01:00']],
    'MINUTELY BYSETPOS limits each minute\'s set' => [['FREQ=MINUTELY;BYSECOND=10,20,30;BYSETPOS=-1;COUNT=3', '2027-01-01 09:00:30'], ['2027-01-01 09:00:30', '2027-01-01 09:01:30', '2027-01-01 09:02:30']],
    // HOURLY: BYMINUTE and BYSECOND expand, the rest limit.
    'HOURLY BYMONTH limits' => [['FREQ=HOURLY;INTERVAL=6;BYMONTH=2;COUNT=3', '2027-01-31 18:00:00'], ['2027-01-31 18:00:00', '2027-02-01 00:00:00', '2027-02-01 06:00:00']],
    'HOURLY BYYEARDAY limits' => [['FREQ=HOURLY;INTERVAL=12;BYYEARDAY=-1;COUNT=3', '2027-12-30 12:00:00'], ['2027-12-30 12:00:00', '2027-12-31 00:00:00', '2027-12-31 12:00:00']],
    'HOURLY BYMONTHDAY limits' => [['FREQ=HOURLY;INTERVAL=8;BYMONTHDAY=15;COUNT=4', '2027-01-14 16:00:00'], ['2027-01-14 16:00:00', '2027-01-15 00:00:00', '2027-01-15 08:00:00', '2027-01-15 16:00:00']],
    'HOURLY BYDAY limits' => [['FREQ=HOURLY;INTERVAL=12;BYDAY=SA;COUNT=3', '2027-01-01 12:00:00'], ['2027-01-01 12:00:00', '2027-01-02 00:00:00', '2027-01-02 12:00:00']],
    // Every 5 hours from midnight: 0, 5, 10, 15, 20, then 1, 6, 11 the next day.
    'HOURLY BYHOUR limits' => [['FREQ=HOURLY;INTERVAL=5;BYHOUR=10,11;COUNT=3', '2027-01-01 00:00:00'], ['2027-01-01 00:00:00', '2027-01-01 10:00:00', '2027-01-02 11:00:00']],
    'HOURLY BYMINUTE expands' => [['FREQ=HOURLY;BYMINUTE=0,30;COUNT=4', '2027-01-01 09:00:00'], ['2027-01-01 09:00:00', '2027-01-01 09:30:00', '2027-01-01 10:00:00', '2027-01-01 10:30:00']],
    'HOURLY BYSECOND expands' => [['FREQ=HOURLY;BYSECOND=0,30;COUNT=3', '2027-01-01 09:15:00'], ['2027-01-01 09:15:00', '2027-01-01 09:15:30', '2027-01-01 10:15:00']],
    'HOURLY BYSETPOS limits each hour\'s set' => [['FREQ=HOURLY;BYMINUTE=0,20,40;BYSETPOS=2;COUNT=3', '2027-01-01 09:20:00'], ['2027-01-01 09:20:00', '2027-01-01 10:20:00', '2027-01-01 11:20:00']],
    // DAILY: BYHOUR, BYMINUTE and BYSECOND expand; BYMONTH, BYMONTHDAY and BYDAY limit.
    'DAILY BYMONTH limits' => [['FREQ=DAILY;BYMONTH=2;COUNT=3', '2027-01-30 09:00:00'], ['2027-01-30 09:00:00', '2027-02-01 09:00:00', '2027-02-02 09:00:00']],
    'DAILY BYMONTHDAY limits' => [['FREQ=DAILY;BYMONTHDAY=1,-1;COUNT=4', '2027-01-15 09:00:00'], ['2027-01-15 09:00:00', '2027-01-31 09:00:00', '2027-02-01 09:00:00', '2027-02-28 09:00:00']],
    // Every other day from Fri 1 Jan: 1, 3, 5 ... the Mondays among them are 11 and 25 Jan.
    'DAILY BYDAY limits' => [['FREQ=DAILY;INTERVAL=2;BYDAY=MO;COUNT=3', '2027-01-01 09:00:00'], ['2027-01-01 09:00:00', '2027-01-11 09:00:00', '2027-01-25 09:00:00']],
    'DAILY BYHOUR expands' => [['FREQ=DAILY;BYHOUR=9,17;COUNT=4', '2027-01-01 09:00:00'], ['2027-01-01 09:00:00', '2027-01-01 17:00:00', '2027-01-02 09:00:00', '2027-01-02 17:00:00']],
    'DAILY BYMINUTE expands' => [['FREQ=DAILY;BYMINUTE=0,30;COUNT=3', '2027-01-01 09:00:00'], ['2027-01-01 09:00:00', '2027-01-01 09:30:00', '2027-01-02 09:00:00']],
    'DAILY BYSECOND expands' => [['FREQ=DAILY;BYSECOND=0,30;COUNT=3', '2027-01-01 09:00:00'], ['2027-01-01 09:00:00', '2027-01-01 09:00:30', '2027-01-02 09:00:00']],
    'DAILY BYSETPOS limits each day\'s set' => [['FREQ=DAILY;BYHOUR=9,12,17;BYSETPOS=-1;COUNT=3', '2027-01-01 17:00:00'], ['2027-01-01 17:00:00', '2027-01-02 17:00:00', '2027-01-03 17:00:00']],
    // WEEKLY: BYDAY and the clock parts expand, BYMONTH limits.
    'WEEKLY BYMONTH limits' => [['FREQ=WEEKLY;BYMONTH=2;COUNT=3', '2027-01-25 09:00:00'], ['2027-01-25 09:00:00', '2027-02-01 09:00:00', '2027-02-08 09:00:00']],
    'WEEKLY BYDAY expands' => [['FREQ=WEEKLY;BYDAY=TU,TH;COUNT=4', '2027-01-05 09:00:00'], ['2027-01-05 09:00:00', '2027-01-07 09:00:00', '2027-01-12 09:00:00', '2027-01-14 09:00:00']],
    'WEEKLY BYHOUR expands' => [['FREQ=WEEKLY;BYHOUR=9,18;COUNT=3', '2027-01-05 09:00:00'], ['2027-01-05 09:00:00', '2027-01-05 18:00:00', '2027-01-12 09:00:00']],
    'WEEKLY BYMINUTE expands' => [['FREQ=WEEKLY;BYMINUTE=0,45;COUNT=3', '2027-01-05 09:00:00'], ['2027-01-05 09:00:00', '2027-01-05 09:45:00', '2027-01-12 09:00:00']],
    'WEEKLY BYSECOND expands' => [['FREQ=WEEKLY;BYSECOND=0,30;COUNT=3', '2027-01-05 09:00:00'], ['2027-01-05 09:00:00', '2027-01-05 09:00:30', '2027-01-12 09:00:00']],
    // The week of Mon 28 Dec 2026 holds Fri 1 Jan, its last listed day.
    'WEEKLY BYSETPOS limits each week\'s set' => [['FREQ=WEEKLY;BYDAY=MO,WE,FR;BYSETPOS=-1;COUNT=3', '2027-01-01 09:00:00'], ['2027-01-01 09:00:00', '2027-01-08 09:00:00', '2027-01-15 09:00:00']],
    // RFC 5545's two WKST examples.
    'WEEKLY WKST=MO (RFC 5545)' => [['FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=MO', '1997-08-05 09:00:00', 'America/New_York'], ['1997-08-05 09:00:00', '1997-08-10 09:00:00', '1997-08-19 09:00:00', '1997-08-24 09:00:00']],
    'WEEKLY WKST=SU (RFC 5545)' => [['FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=SU', '1997-08-05 09:00:00', 'America/New_York'], ['1997-08-05 09:00:00', '1997-08-17 09:00:00', '1997-08-19 09:00:00', '1997-08-31 09:00:00']],
    // MONTHLY: BYMONTHDAY, BYDAY (Note 1) and the clock parts expand, BYMONTH limits.
    'MONTHLY BYMONTH limits' => [['FREQ=MONTHLY;BYMONTH=1,3;COUNT=3', '2027-01-15 09:00:00'], ['2027-01-15 09:00:00', '2027-03-15 09:00:00', '2028-01-15 09:00:00']],
    'MONTHLY BYMONTHDAY expands' => [['FREQ=MONTHLY;BYMONTHDAY=1,-1;COUNT=4', '2027-01-01 09:00:00'], ['2027-01-01 09:00:00', '2027-01-31 09:00:00', '2027-02-01 09:00:00', '2027-02-28 09:00:00']],
    'MONTHLY BYDAY expands, numbered' => [['FREQ=MONTHLY;BYDAY=1FR,-1FR;COUNT=4', '2027-01-01 09:00:00'], ['2027-01-01 09:00:00', '2027-01-29 09:00:00', '2027-02-05 09:00:00', '2027-02-26 09:00:00']],
    'MONTHLY BYDAY expands, every one' => [['FREQ=MONTHLY;BYDAY=MO;COUNT=5', '2027-02-01 09:00:00'], ['2027-02-01 09:00:00', '2027-02-08 09:00:00', '2027-02-15 09:00:00', '2027-02-22 09:00:00', '2027-03-01 09:00:00']],
    // Note 1: with BYMONTHDAY, BYDAY limits. A 13th is a Friday where the month starts on a Sunday: Aug 2027, Oct 2028.
    'MONTHLY BYDAY limits with BYMONTHDAY' => [['FREQ=MONTHLY;BYDAY=FR;BYMONTHDAY=13;COUNT=2', '2027-08-13 09:00:00'], ['2027-08-13 09:00:00', '2028-10-13 09:00:00']],
    'MONTHLY BYHOUR expands' => [['FREQ=MONTHLY;BYHOUR=9,21;COUNT=3', '2027-01-15 09:00:00'], ['2027-01-15 09:00:00', '2027-01-15 21:00:00', '2027-02-15 09:00:00']],
    'MONTHLY BYMINUTE expands' => [['FREQ=MONTHLY;BYMINUTE=0,45;COUNT=3', '2027-01-15 09:00:00'], ['2027-01-15 09:00:00', '2027-01-15 09:45:00', '2027-02-15 09:00:00']],
    'MONTHLY BYSECOND expands' => [['FREQ=MONTHLY;BYSECOND=0,15;COUNT=3', '2027-01-15 09:00:00'], ['2027-01-15 09:00:00', '2027-01-15 09:00:15', '2027-02-15 09:00:00']],
    'MONTHLY BYSETPOS: the last weekday' => [['FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1;COUNT=3', '2027-01-29 09:00:00'], ['2027-01-29 09:00:00', '2027-02-26 09:00:00', '2027-03-31 09:00:00']],
    // YEARLY: everything expands but BYSETPOS.
    'YEARLY BYMONTH expands' => [['FREQ=YEARLY;BYMONTH=1,7;COUNT=3', '2027-01-10 09:00:00'], ['2027-01-10 09:00:00', '2027-07-10 09:00:00', '2028-01-10 09:00:00']],
    'YEARLY BYWEEKNO expands (RFC 5545)' => [['FREQ=YEARLY;BYWEEKNO=20;BYDAY=MO;COUNT=3', '1997-05-12 09:00:00', 'America/New_York'], ['1997-05-12 09:00:00', '1998-05-11 09:00:00', '1999-05-17 09:00:00']],
    // Week 1 of 2027 is 4 to 10 Jan, of 2028 3 to 9 Jan, of 2029 1 to 7 Jan: the start's Tuesday in each.
    'YEARLY BYWEEKNO alone keeps the start\'s weekday' => [['FREQ=YEARLY;BYWEEKNO=1;COUNT=3', '2027-01-05 09:00:00'], ['2027-01-05 09:00:00', '2028-01-04 09:00:00', '2029-01-02 09:00:00']],
    // The last week of 2027's numbering is 27 Dec 2027 to 2 Jan 2028; of 2028's, 25 to 31 Dec 2028.
    'YEARLY BYWEEKNO counted from the end' => [['FREQ=YEARLY;BYWEEKNO=-1;BYDAY=SU;COUNT=3', '2027-01-03 09:00:00'], ['2027-01-03 09:00:00', '2028-01-02 09:00:00', '2028-12-31 09:00:00']],
    'YEARLY BYYEARDAY expands (RFC 5545)' => [['FREQ=YEARLY;INTERVAL=3;COUNT=10;BYYEARDAY=1,100,200', '1997-01-01 09:00:00', 'America/New_York'],
        ['1997-01-01 09:00:00', '1997-04-10 09:00:00', '1997-07-19 09:00:00', '2000-01-01 09:00:00', '2000-04-09 09:00:00', '2000-07-18 09:00:00', '2003-01-01 09:00:00', '2003-04-10 09:00:00', '2003-07-19 09:00:00', '2006-01-01 09:00:00']],
    'YEARLY BYMONTHDAY expands into every month' => [['FREQ=YEARLY;BYMONTHDAY=1;COUNT=3', '2027-11-01 09:00:00'], ['2027-11-01 09:00:00', '2027-12-01 09:00:00', '2028-01-01 09:00:00']],
    'YEARLY BYMONTHDAY with BYMONTH: 29 February' => [['FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=29;COUNT=3', '2028-02-29 09:00:00'], ['2028-02-29 09:00:00', '2032-02-29 09:00:00', '2036-02-29 09:00:00']],
    'YEARLY BYDAY expands in the year (RFC 5545)' => [['FREQ=YEARLY;BYDAY=20MO;COUNT=3', '1997-05-19 09:00:00', 'America/New_York'], ['1997-05-19 09:00:00', '1998-05-18 09:00:00', '1999-05-17 09:00:00']],
    'YEARLY BYDAY expands in the month with BYMONTH (RFC 5545)' => [['FREQ=YEARLY;BYMONTH=3;BYDAY=TH;COUNT=5', '1997-03-13 09:00:00', 'America/New_York'], ['1997-03-13 09:00:00', '1997-03-20 09:00:00', '1997-03-27 09:00:00', '1998-03-05 09:00:00', '1998-03-12 09:00:00']],
    'YEARLY BYDAY limits with BYMONTHDAY' => [['FREQ=YEARLY;BYMONTHDAY=13;BYDAY=FR;COUNT=2', '2027-08-13 09:00:00'], ['2027-08-13 09:00:00', '2028-10-13 09:00:00']],
    'YEARLY BYDAY expands in the weeks with BYWEEKNO' => [['FREQ=YEARLY;BYWEEKNO=1;BYDAY=MO,SU;COUNT=3', '2027-01-04 09:00:00'], ['2027-01-04 09:00:00', '2027-01-10 09:00:00', '2028-01-03 09:00:00']],
    'YEARLY BYHOUR expands' => [['FREQ=YEARLY;BYHOUR=9,18;COUNT=3', '2027-03-01 09:00:00'], ['2027-03-01 09:00:00', '2027-03-01 18:00:00', '2028-03-01 09:00:00']],
    'YEARLY BYMINUTE expands' => [['FREQ=YEARLY;BYMINUTE=0,30;COUNT=3', '2027-03-01 09:00:00'], ['2027-03-01 09:00:00', '2027-03-01 09:30:00', '2028-03-01 09:00:00']],
    'YEARLY BYSECOND expands' => [['FREQ=YEARLY;BYSECOND=0,30;COUNT=3', '2027-03-01 09:00:00'], ['2027-03-01 09:00:00', '2027-03-01 09:00:30', '2028-03-01 09:00:00']],
    'YEARLY BYSETPOS limits the year\'s set' => [['FREQ=YEARLY;BYMONTH=1,2,3;BYMONTHDAY=1;BYSETPOS=-1;COUNT=2', '2027-03-01 09:00:00'], ['2027-03-01 09:00:00', '2028-03-01 09:00:00']],
    'YEARLY BYSETPOS: the year\'s first Monday, not each month\'s' => [['FREQ=YEARLY;BYDAY=MO;BYSETPOS=1;COUNT=2', '2027-01-04 09:00:00'], ['2027-01-04 09:00:00', '2028-01-03 09:00:00']],
];
foreach ($table as $name => [$args, $want]) {
    [$rrule, $start] = $args;
    checkEq("Expander table: $name", $want, exWalk($rrule, $start, $args[2] ?? 'UTC', count($want) + 2));
}

// The N/A cells, and BYDAY's two restrictions on a number: a rule the
// expander can't read, refused where a rule is written.
$na = [];
foreach (['SECONDLY', 'MINUTELY', 'HOURLY', 'DAILY', 'WEEKLY', 'MONTHLY'] as $f) {
    $na[] = "FREQ=$f;BYWEEKNO=1";
}
foreach (['DAILY', 'WEEKLY', 'MONTHLY'] as $f) {
    $na[] = "FREQ=$f;BYYEARDAY=1";
}
$na[] = 'FREQ=WEEKLY;BYMONTHDAY=1';
foreach (['SECONDLY', 'MINUTELY', 'HOURLY', 'DAILY', 'WEEKLY'] as $f) {
    $na[] = "FREQ=$f;BYDAY=1MO";
}
$na[] = 'FREQ=YEARLY;BYWEEKNO=1;BYDAY=1MO';
checkEq('Expander table: every N/A cell is a rule the expander can\'t read', array_fill_keys($na, true), array_combine($na, array_map('exRefused', $na)));
$writes = [];
foreach (['FREQ=DAILY;BYYEARDAY=1', 'FREQ=WEEKLY;BYMONTHDAY=1', 'FREQ=MONTHLY;BYWEEKNO=1', 'FREQ=DAILY;BYDAY=2TU'] as $rrule) {
    try {
        Recurrence::validateRrule($rrule);
        $writes[$rrule] = 'accepted';
    } catch (BetterCal\Http\HttpError $e) {
        $writes[$rrule] = $e->errorCode;
    }
}
checkEq('Expander table: a rule with an N/A part is refused where it is written', array_fill_keys(array_keys($writes), 'invalid_rrule'), $writes);
checkEq('Expander table: the table\'s valid cells are read', [false, false, false, false],
    [exRefused('FREQ=YEARLY;BYWEEKNO=1;BYDAY=MO'), exRefused('FREQ=HOURLY;BYYEARDAY=1'), exRefused('FREQ=MONTHLY;BYDAY=2TU'), exRefused('FREQ=DAILY;BYMONTHDAY=1')]);
checkEq('Expander: COUNT and UNTIL together is outside the grammar', true, exRefused('FREQ=DAILY;COUNT=2;UNTIL=20270110'));

// ---- The readings (decisions.md, "Repeating Events and the Standard") ----

// A clock the spring change skips (Los Angeles, Sun 14 Mar 2027, 02:00 to
// 03:00): that day at the clock plus the gap, every later day at its own,
// for every spelling of the rule.
$gap = ['2027-03-13 02:30:00-08:00', '2027-03-14 03:30:00-07:00', '2027-03-15 02:30:00-07:00', '2027-03-16 02:30:00-07:00'];
$spellings = [];
foreach (['FREQ=DAILY;COUNT=4', 'FREQ=DAILY;BYHOUR=2;BYMINUTE=30;COUNT=4', 'FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR,SA,SU;COUNT=4', 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR,SA,SU;COUNT=4', 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR,SA,SU;BYHOUR=2;COUNT=4'] as $rrule) {
    $spellings[$rrule] = exWalk($rrule, '2027-03-13 02:30', 'America/Los_Angeles', 4, true);
}
checkEq('Gap: every spelling keeps the gap day at 03:30 and the next day at its own 02:30', array_fill_keys(array_keys($spellings), $gap), $spellings);
checkEq('Gap: a monthly second Sunday and a yearly 14 March keep that day shifted, the next at 02:30', [
    ['2027-02-14 02:30:00-08:00', '2027-03-14 03:30:00-07:00', '2027-04-11 02:30:00-07:00'], ['2026-03-14 02:30:00-07:00', '2027-03-14 03:30:00-07:00', '2028-03-14 02:30:00-07:00'],
], [exWalk('FREQ=MONTHLY;BYDAY=2SU;COUNT=3', '2027-02-14 02:30', 'America/Los_Angeles', 3, true), exWalk('FREQ=YEARLY;COUNT=3', '2026-03-14 02:30', 'America/Los_Angeles', 3, true)]);
// Lord Howe's clocks move by half an hour: 02:15 on 3 Oct 2027 doesn't exist and is 02:45.
checkEq('Gap: a half-hour gap shifts the day by half an hour, the next day at its own clock', ['2027-10-02 02:15:00+10:30', '2027-10-03 02:45:00+11:00', '2027-10-04 02:15:00+11:00'],
    exWalk('FREQ=DAILY;BYHOUR=2;BYMINUTE=15;COUNT=3', '2027-10-02 02:15', 'Australia/Lord_Howe', 3, true));
// No carried state: the same days and keys from any window, and in the model.
$gapRow = ['id' => 1, 'rrule' => 'FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR,SA,SU', 'start_utc' => '2027-03-01 10:30:00', 'end_utc' => '2027-03-01 11:30:00', 'all_day' => 0, 'tzid' => 'America/Los_Angeles', 'exdates_json' => null];
$keysIn = static fn(string $from, string $to): array => array_values(array_filter(array_map(static fn(array $o): string => Time::toDb($o['start']), Recurrence::sabreExpand($gapRow, Time::fromDb($from), Time::fromDb('2027-03-17 00:00:00'))), static fn(string $k): bool => $k >= $to));
$calc = Calc::fromRow($gapRow);
$calcKeys = array_values(array_map(static fn(array $k): string => $k[1], array_filter($calc->keys(Calc::fromRow($gapRow)->dnOf('2027-03-16 12:00:00')), static fn(array $k): bool => $k[1] >= '2027-03-13')));
checkEq('Gap: a window from the start, one from the gap day and the model read the same keys', [
    ['2027-03-13 10:30:00', '2027-03-14 10:30:00', '2027-03-15 09:30:00', '2027-03-16 09:30:00'],
    ['2027-03-14 10:30:00', '2027-03-15 09:30:00', '2027-03-16 09:30:00'],
    ['2027-03-13 10:30:00', '2027-03-14 10:30:00', '2027-03-15 09:30:00', '2027-03-16 09:30:00'],
], [$keysIn('2027-03-01 00:00:00', '2027-03-13'), $keysIn('2027-03-14 10:00:00', '2027-03-14'), $calcKeys]);

// A repeated wall clock with no instant given is its earlier instant; an
// instant given stays that instant (Berlin, 31 Oct 2027: 02:30 is 00:30Z and 01:30Z).
$fold = static function (string $startUtc, string $rrule): array {
    $it = new RuleIterator($rrule, Time::fromDb($startUtc)->setTimezone(Time::zone('Europe/Berlin')));
    $out = [];
    for ($it->rewind(); $it->valid() && count($out) < 3; $it->next()) {
        $out[] = Time::toDb($it->current());
    }
    return $out;
};
checkEq('Fold: a weekly 02:30 reads the first of 31 Oct\'s two; a series stored at the second stays there', [
    ['2027-10-24 00:30:00', '2027-10-31 00:30:00', '2027-11-07 01:30:00'], ['2027-10-31 01:30:00', '2027-11-07 01:30:00', '2027-11-14 01:30:00'],
    ['2026-10-31 01:30:00', '2027-10-31 00:30:00', '2028-10-31 01:30:00'],
], [$fold('2027-10-24 00:30:00', 'FREQ=WEEKLY;COUNT=3'), $fold('2027-10-31 01:30:00', 'FREQ=WEEKLY;COUNT=3'), $fold('2026-10-31 01:30:00', 'FREQ=YEARLY;COUNT=3')]);

// HOURLY steps by wall clock; MINUTELY and SECONDLY in elapsed time.
checkEq('HOURLY: every 3 hours keeps 00:00, 03:00, 06:00 across the spring change', ['2027-03-13 21:00:00-08:00', '2027-03-14 00:00:00-08:00', '2027-03-14 03:00:00-07:00', '2027-03-14 06:00:00-07:00'],
    exWalk('FREQ=HOURLY;INTERVAL=3;COUNT=4', '2027-03-13 21:00', 'America/Los_Angeles', 4, true));
// The step to the skipped 02:00 is 03:00, which the next step names too: one
// occurrence, counted once (RFC 5545 3.8.5.3: duplicate instances are ignored).
$hourly = ['2027-03-14 00:00:00-08:00', '2027-03-14 01:00:00-08:00', '2027-03-14 03:00:00-07:00', '2027-03-14 04:00:00-07:00'];
$hourlyIn = static function (string $rrule, string $from): array {
    $row = ['id' => 1, 'rrule' => $rrule, 'start_utc' => '2027-03-14 08:00:00', 'end_utc' => '2027-03-14 08:30:00', 'all_day' => 0, 'tzid' => 'America/Los_Angeles', 'exdates_json' => null];
    return array_map(static fn(array $o): string => $o['start']->setTimezone(Time::zone('America/Los_Angeles'))->format('Y-m-d H:i:sP'),
        Recurrence::occurrences($row, Time::fromDb($from), Time::fromDb('2027-03-14 11:30:00')));
};
checkEq('HOURLY: the skipped 02:00 and the 03:00 after it are one occurrence, counted once, from any window', [$hourly, $hourly, array_slice($hourly, 2), array_slice($hourly, 2)], [
    exWalk('FREQ=HOURLY;COUNT=4', '2027-03-14 00:00', 'America/Los_Angeles', 5, true),
    $hourlyIn('FREQ=HOURLY;COUNT=4', '2027-03-14 08:00:00'),
    $hourlyIn('FREQ=HOURLY;COUNT=4', '2027-03-14 10:00:00'),
    $hourlyIn('FREQ=HOURLY', '2027-03-14 10:00:00'),
]);
checkEq('HOURLY: across the fall change the repeated 01:00 is read once, at its first instant', ['2027-11-07 00:00:00-07:00', '2027-11-07 01:00:00-07:00', '2027-11-07 02:00:00-08:00', '2027-11-07 03:00:00-08:00'],
    exWalk('FREQ=HOURLY;COUNT=4', '2027-11-07 00:00', 'America/Los_Angeles', 4, true));
checkEq('MINUTELY: every 60 minutes is elapsed time, both 01:30s of the fall change', ['2027-11-07 00:30:00-07:00', '2027-11-07 01:30:00-07:00', '2027-11-07 01:30:00-08:00', '2027-11-07 02:30:00-08:00'],
    exWalk('FREQ=MINUTELY;INTERVAL=60;COUNT=4', '2027-11-07 00:30', 'America/Los_Angeles', 4, true));
checkEq('MINUTELY: across the spring change, an elapsed hour from 01:30 is 03:30', ['2027-03-14 01:30:00-08:00', '2027-03-14 03:30:00-07:00', '2027-03-14 04:30:00-07:00'],
    exWalk('FREQ=MINUTELY;INTERVAL=60;COUNT=3', '2027-03-14 01:30', 'America/Los_Angeles', 3, true));
checkEq('SECONDLY: every 1800 seconds is elapsed time across the fall change', ['2027-11-07 01:00:00-07:00', '2027-11-07 01:30:00-07:00', '2027-11-07 01:00:00-08:00'],
    exWalk('FREQ=SECONDLY;INTERVAL=1800;COUNT=3', '2027-11-07 01:00', 'America/Los_Angeles', 3, true));

// Clock parts by the table: BYMINUTE read whatever the start's minute, and
// an hour named in a half-hour zone stays that hour (Lord Howe, 4 Apr 2027,
// 02:00 back to 01:30).
checkEq('Clock parts: a weekly rule with BYHOUR reads its BYMINUTE, the start first', ['2027-01-05 03:00:00', '2027-01-05 03:15:00', '2027-01-12 03:15:00'],
    exWalk('FREQ=WEEKLY;BYHOUR=3;BYMINUTE=15;COUNT=3', '2027-01-05 03:00'));
checkEq('Clock parts: 03:00 by BYHOUR stays 03:00 after a half-hour change', ['2027-04-03 03:00:00+11:00', '2027-04-04 03:00:00+10:30', '2027-04-05 03:00:00+10:30'],
    exWalk('FREQ=DAILY;BYHOUR=3;COUNT=3', '2027-04-03 03:00', 'Australia/Lord_Howe', 3, true));

// COUNT bounds the generated set before exclusions; a day that doesn't
// exist is not generated, so not counted.
checkEq('COUNT: the 31st three times is 31 Jan, 31 Mar and 31 May', ['2027-01-31 09:00:00', '2027-03-31 09:00:00', '2027-05-31 09:00:00'], exWalk('FREQ=MONTHLY;BYMONTHDAY=31;COUNT=3', '2027-01-31 09:00'));
checkEq('COUNT: a yearly 29 February twice is 2028 and 2032', ['2028-02-29 09:00:00', '2032-02-29 09:00:00'], exWalk('FREQ=YEARLY;COUNT=2', '2028-02-29 09:00'));
$skipped = (new Recurrence())->expand(['id' => 5, 'rrule' => 'FREQ=DAILY;COUNT=3', 'start_utc' => '2027-01-01 09:00:00', 'end_utc' => '2027-01-01 10:00:00', 'all_day' => 0, 'tzid' => 'UTC', 'exdates_json' => json_encode(['2027-01-02 09:00:00'])],
    [], Time::fromDb('2027-01-01 00:00:00'), Time::fromDb('2027-02-01 00:00:00'));
checkEq('COUNT: a skip is one of the three, so the series shows 1 and 3 Jan, never a 4th', ['2027-01-01 09:00:00', '2027-01-03 09:00:00'], array_column($skipped, 'instanceUtc'));
checkEq('UNTIL: inclusive, a date UNTIL the end of that day in the start\'s zone', [['2027-01-05 18:00:00', '2027-01-06 18:00:00', '2027-01-07 18:00:00'], ['2027-01-05 18:00:00', '2027-01-06 18:00:00']],
    [exWalk('FREQ=DAILY;UNTIL=20270107', '2027-01-05 18:00', 'America/Los_Angeles'), exWalk('FREQ=DAILY;UNTIL=20270107T020000Z', '2027-01-05 18:00', 'America/Los_Angeles')]);
checkEq('UNTIL: one before the start bounds what the rule generates; the start is still the first occurrence', ['2027-12-16 09:00:00'],
    exWalk('FREQ=DAILY;BYDAY=MO,TU,WE;UNTIL=20271117T075959Z', '2027-12-16 09:00', 'America/Los_Angeles'));

// A rule ends only by COUNT, UNTIL, the end of 9999 or a whole cycle without
// an occurrence; any other limit is WalkLimitReached, never an end.
checkEq('End: no 30 February in the calendar\'s cycle, so the start alone', ['2027-01-01 09:00:00'], exWalk('FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30', '2027-01-01 09:00'));
checkEq('End: every 2 hours from midnight never reaches 01:00, so the start alone', ['2027-01-01 00:00:00'], exWalk('FREQ=HOURLY;INTERVAL=2;BYHOUR=1', '2027-01-01 00:00'));
// Every 401 years from 2028: 2429, 2830 and 3231 are no leap years, 3632 is.
checkEq('End: an interval of 401 years still finds its 29 February', ['2028-02-29 09:00:00', '3632-02-29 09:00:00'], exWalk('FREQ=YEARLY;INTERVAL=401;COUNT=2', '2028-02-29 09:00'));
checkEq('End: daily on 29 February three times is 2028, 2032 and 2036', ['2027-01-01 09:00:00', '2028-02-29 09:00:00', '2032-02-29 09:00:00'], exWalk('FREQ=DAILY;BYMONTH=2;BYMONTHDAY=29;COUNT=3', '2027-01-01 09:00'));
checkEq('End: the walk ends with year 9999', ['9998-06-01 09:00:00', '9999-06-01 09:00:00'], exWalk('FREQ=YEARLY', '9998-06-01 09:00'));
$limit = null;
try {
    exWalk('FREQ=SECONDLY;INTERVAL=2;BYSECOND=31', '2027-01-01 09:00:00', 'UTC', 3);
} catch (WalkLimitReached $e) {
    $limit = 'WalkLimitReached';
}
checkEq('End: every 2 seconds from an even second never lands on second 31; the walk\'s limit is an error, never the end', 'WalkLimitReached', $limit);

// ---- One ordered, unique stream ------------------------------------------
// A gap or a skipped date resolves a period's clocks into the next period's
// span; the stream is merged in time order, a duplicate dropped before COUNT
// reads it. New York, 14 Mar 2027, 02:00 to 03:00: the skipped 02:00 and
// 02:30 are 03:00 and 03:30, which the next hour names too.
checkEq('Stream: an hourly half-hour rule across the spring change is twelve unique occurrences', [
    '2027-03-14 00:00:00-05:00', '2027-03-14 00:30:00-05:00', '2027-03-14 01:00:00-05:00', '2027-03-14 01:30:00-05:00',
    '2027-03-14 03:00:00-04:00', '2027-03-14 03:30:00-04:00', '2027-03-14 04:00:00-04:00', '2027-03-14 04:30:00-04:00',
    '2027-03-14 05:00:00-04:00', '2027-03-14 05:30:00-04:00', '2027-03-14 06:00:00-04:00', '2027-03-14 06:30:00-04:00',
], exWalk('FREQ=HOURLY;BYMINUTE=0,30;COUNT=12', '2027-03-14 00:00', 'America/New_York', 14, true));
$streamRow = static fn(string $rrule, string $startUtc, string $endUtc, string $tz): array => ['id' => 1, 'rrule' => $rrule, 'start_utc' => $startUtc, 'end_utc' => $endUtc, 'all_day' => 0, 'tzid' => $tz, 'exdates_json' => null];
$utcIn = static fn(array $row, string $from, string $to): array => array_map(static fn(array $o): string => Time::toDb($o['start']),
    Recurrence::occurrences($row, Time::fromDb($from), Time::fromDb($to)));
checkEq('Stream: the same rule through a window is the same twelve instants', [
    '2027-03-14 05:00:00', '2027-03-14 05:30:00', '2027-03-14 06:00:00', '2027-03-14 06:30:00', '2027-03-14 07:00:00', '2027-03-14 07:30:00',
    '2027-03-14 08:00:00', '2027-03-14 08:30:00', '2027-03-14 09:00:00', '2027-03-14 09:30:00', '2027-03-14 10:00:00', '2027-03-14 10:30:00',
], $utcIn($streamRow('FREQ=HOURLY;BYMINUTE=0,30;COUNT=12', '2027-03-14 05:00:00', '2027-03-14 05:10:00', 'America/New_York'), '2027-03-14 05:00:00', '2027-03-14 12:00:00'));
// Troll, 28 Mar 2027, 01:00 to 03:00: the skipped 02:00 is 04:00, after the
// 03:00 the next hour names, which is distinct and comes first.
checkEq('Stream: across a two-hour change a shifted 04:00 follows a distinct 03:00', [
    '2027-03-27 02:00:00+00:00', '2027-03-27 03:00:00+00:00', '2027-03-28 03:00:00+02:00', '2027-03-28 04:00:00+02:00', '2027-03-29 02:00:00+02:00', '2027-03-29 03:00:00+02:00',
], exWalk('FREQ=HOURLY;BYHOUR=2,3;COUNT=6', '2027-03-27 02:00', 'Antarctica/Troll', 8, true));
checkEq('Stream: a window holding that 03:00 reads it', ['2027-03-28 01:00:00'],
    $utcIn($streamRow('FREQ=HOURLY;BYHOUR=2,3;COUNT=6', '2027-03-27 02:00:00', '2027-03-27 02:10:00', 'Antarctica/Troll'), '2027-03-28 00:00:00', '2027-03-28 01:30:00'));
// Apia skipped 30 Dec 2011 (-10:00 to +14:00): that date's clocks are 31
// December's instants, one occurrence each.
checkEq('Stream: a daily two-clock rule across a skipped date is ten unique occurrences through 3 January', [
    '2011-12-29 09:00:00-10:00', '2011-12-29 10:00:00-10:00', '2011-12-31 09:00:00+14:00', '2011-12-31 10:00:00+14:00', '2012-01-01 09:00:00+14:00',
    '2012-01-01 10:00:00+14:00', '2012-01-02 09:00:00+14:00', '2012-01-02 10:00:00+14:00', '2012-01-03 09:00:00+14:00', '2012-01-03 10:00:00+14:00',
], exWalk('FREQ=DAILY;BYHOUR=9,10;COUNT=10', '2011-12-29 09:00', 'Pacific/Apia', 12, true));

// ---- Seeking: a narrow window reads what a broad one reads in its range --
// Each broad window's instants are listed by hand, and so is each narrow
// window's: one starting inside the transition and one after it.
$seekCases = [
    // New York, one hour: the skipped 02:00 and 02:30 are 07:00Z and 07:30Z.
    'a one-hour change' => [$streamRow('FREQ=HOURLY;BYMINUTE=0,30', '2027-03-14 05:00:00', '2027-03-14 05:10:00', 'America/New_York'), [
        [['2027-03-14 05:00:00', '2027-03-14 09:00:00'], ['2027-03-14 05:00:00', '2027-03-14 05:30:00', '2027-03-14 06:00:00', '2027-03-14 06:30:00', '2027-03-14 07:00:00', '2027-03-14 07:30:00', '2027-03-14 08:00:00', '2027-03-14 08:30:00']],
        [['2027-03-14 07:00:00', '2027-03-14 07:40:00'], ['2027-03-14 07:00:00', '2027-03-14 07:30:00']],
        [['2027-03-14 08:00:00', '2027-03-14 08:40:00'], ['2027-03-14 08:00:00', '2027-03-14 08:30:00']],
    ]],
    // Lord Howe, half an hour, 3 Oct 2027: the skipped 02:15 is 02:45 (15:45Z), which 02:45 names too.
    'a half-hour change' => [$streamRow('FREQ=HOURLY;BYMINUTE=15,45', '2027-10-02 13:45:00', '2027-10-02 13:55:00', 'Australia/Lord_Howe'), [
        [['2027-10-02 13:00:00', '2027-10-02 17:00:00'], ['2027-10-02 13:45:00', '2027-10-02 14:15:00', '2027-10-02 14:45:00', '2027-10-02 15:15:00', '2027-10-02 15:45:00', '2027-10-02 16:15:00', '2027-10-02 16:45:00']],
        [['2027-10-02 15:40:00', '2027-10-02 16:00:00'], ['2027-10-02 15:45:00']],
        [['2027-10-02 16:10:00', '2027-10-02 16:30:00'], ['2027-10-02 16:15:00']],
    ]],
    // Troll, two hours: 28 Mar's 01:45 is 03:45 (01:45Z), two wall hours after its own period.
    'a two-hour change' => [$streamRow('FREQ=HOURLY;BYHOUR=1;BYMINUTE=45', '2027-03-27 01:45:00', '2027-03-27 01:50:00', 'Antarctica/Troll'), [
        [['2027-03-27 00:00:00', '2027-03-29 00:00:00'], ['2027-03-27 01:45:00', '2027-03-28 01:45:00', '2027-03-28 23:45:00']],
        [['2027-03-28 01:40:00', '2027-03-28 02:00:00'], ['2027-03-28 01:45:00']],
        [['2027-03-28 23:40:00', '2027-03-29 00:00:00'], ['2027-03-28 23:45:00']],
    ]],
    // Apia, a skipped date: 31 Dec's 09:00 and 10:00 are 19:00Z and 20:00Z on 30 Dec.
    'a skipped date' => [$streamRow('FREQ=DAILY;BYHOUR=9,10', '2011-12-29 19:00:00', '2011-12-29 19:10:00', 'Pacific/Apia'), [
        [['2011-12-29 00:00:00', '2012-01-01 00:00:00'], ['2011-12-29 19:00:00', '2011-12-29 20:00:00', '2011-12-30 19:00:00', '2011-12-30 20:00:00', '2011-12-31 19:00:00', '2011-12-31 20:00:00']],
        [['2011-12-30 19:00:00', '2011-12-30 19:30:00'], ['2011-12-30 19:00:00']],
        [['2011-12-31 20:00:00', '2011-12-31 20:30:00'], ['2011-12-31 20:00:00']],
    ]],
];
foreach ($seekCases as $name => [$row, $windows]) {
    checkEq("Seek: across $name, the broad window and windows starting inside and after it", array_column($windows, 1),
        array_map(static fn(array $w): array => $utcIn($row, ...$w[0]), $windows));
}

// ---- Laziness: a period is produced as it is walked ----------------------
// Every weekday, 24 hours and 24 minutes: 210,240 clocks a year. Validating
// it and walking its first three hold one period's clocks, never the year's.
$probe = 'FREQ=YEARLY;BYDAY=MO,TU,WE,TH,FR,SA,SU;BYHOUR=' . implode(',', range(0, 23)) . ';BYMINUTE=' . implode(',', range(0, 23));
$before = memory_get_usage();
Recurrence::validateRrule($probe . ';COUNT=1');
$lazy = new RuleIterator($probe, new DateTimeImmutable('2027-01-01 00:00:00', Time::utc()));
$firstThree = [];
for ($lazy->rewind(); $lazy->valid() && count($firstThree) < 3; $lazy->next()) {
    $firstThree[] = $lazy->current()->format('Y-m-d H:i:s');
}
checkEq('Lazy: a rule of 210,240 clocks a year validates and walks its first three in under a MiB', [['2027-01-01 00:00:00', '2027-01-01 00:01:00', '2027-01-01 00:02:00'], true],
    [$firstThree, memory_get_usage() - $before < 1048576]);
unset($lazy);
