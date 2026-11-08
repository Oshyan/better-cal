<?php

declare(strict_types=1);

// Floating times (#167) with no database: the one rule for a floating
// row's instant on a viewer's clock (Domain\Floating), ICS in and out, and
// quick add's words. Every expected instant is written out by hand.
// Required from run.php. The database half is tests/db/floating.test.php.

use BetterCal\Domain\FallbackParser;
use BetterCal\Domain\Floating;
use BetterCal\Domain\Ics;
use BetterCal\Domain\QuickAdd;
use BetterCal\Domain\Recurrence;
use BetterCal\Support\Time;

$fz = static fn(string $z): DateTimeZone => new DateTimeZone($z);
$fu = static fn(string $utc): DateTimeImmutable => new DateTimeImmutable($utc, new DateTimeZone('UTC'));
$fs = static fn(DateTimeImmutable $t): string => $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');

// 7:00 at Home in Los Angeles on 4 Mar 2027 (15:00 UTC), read on other clocks.
checkEq('floating: 7:00 at Home is 7:00 in Tokyo, 22:00 UTC the day before', '2027-03-03 22:00', $fs(Floating::shown($fu('2027-03-04 15:00'), $fz('America/Los_Angeles'), $fz('Asia/Tokyo'))));
checkEq('floating: and 7:00 in Berlin, 06:00 UTC', '2027-03-04 06:00', $fs(Floating::shown($fu('2027-03-04 15:00'), $fz('America/Los_Angeles'), $fz('Europe/Berlin'))));
checkEq('floating: the same zone is the same instant', '2027-03-04 15:00', $fs(Floating::shown($fu('2027-03-04 15:00'), $fz('America/Los_Angeles'), $fz('America/Los_Angeles'))));
checkEq('floating: stored() is the way back, 9:00 in Tokyo stored as 9:00 at Home', '2027-03-04 17:00', $fs(Floating::stored(new DateTimeImmutable('2027-03-04T09:00:00+09:00'), $fz('America/Los_Angeles'), $fz('Asia/Tokyo'))));
// Across the US change (14 Mar 2027) the wall clock holds, not the offset.
checkEq('floating: 7:00 after the US change is still 7:00 in Berlin', '2027-03-15 06:00', $fs(Floating::shown($fu('2027-03-15 14:00'), $fz('America/Los_Angeles'), $fz('Europe/Berlin'))));
// A clock the viewer's zone skips reads by the one wall-clock rule (Wall): the clock plus the gap.
checkEq('floating: 2:30 on Berlin\'s change day reads as 3:30 there', '2027-03-28 01:30', $fs(Floating::shown($fu('2027-03-28 09:30'), $fz('America/Los_Angeles'), $fz('Europe/Berlin'))));
[$spanS, $spanE] = Floating::span(['floating' => 1, 'all_day' => 0, 'tzid' => 'America/Los_Angeles'], $fu('2027-03-04 15:00'), $fu('2027-03-04 16:00'), $fz('Asia/Tokyo'));
checkEq('floating: a floating row\'s span moves, start and end', ['2027-03-03 22:00', '2027-03-03 23:00'], [$fs($spanS), $fs($spanE)]);
[$pinS] = Floating::span(['floating' => 0, 'all_day' => 0, 'tzid' => 'America/Los_Angeles'], $fu('2027-03-04 15:00'), $fu('2027-03-04 16:00'), $fz('Asia/Tokyo'));
checkEq('floating: a pinned row\'s does not', '2027-03-04 15:00', $fs($pinS));
check('floating: an all-day row never floats', !Floating::is(['floating' => 1, 'all_day' => 1]));
[$ws, $we] = Floating::widened($fu('2027-03-04 00:00'), $fu('2027-03-05 00:00'));
checkEq('floating: a window widens by the span of the clocks, UTC-12 to UTC+14', ['2027-03-02 22:00', '2027-03-06 02:00'], [$fs($ws), $fs($we)]);

// A floating series' occurrences are the rule's wall clocks, and only the
// viewer's zone decides the instant each one is (Floating::occurrence): a
// clock the own zone skips is not skipped for a viewer whose zone has it.
$fexp = static function (array $row, string $from, string $to, string $viewer, ?Recurrence $r = null): array {
    $r ??= new Recurrence();
    return $r->expand($row + ['id' => 1, 'user_id' => 1, 'calendar_id' => 1, 'title' => 'Sample series', 'all_day' => 0, 'floating' => 1, 'exdates_json' => null],
        [], new DateTimeImmutable($from), new DateTimeImmutable($to), null, new DateTimeZone($viewer));
};
$fclock = static fn(array $occs, string $zone): array => array_map(static fn(array $o): string => $o['start']->setTimezone(new DateTimeZone($zone))->format('m-d H:i') . '-' . $o['end']->setTimezone(new DateTimeZone($zone))->format('H:i'), $occs);
// Daily 02:30 to 02:45 at Home in Los Angeles, whose clocks skip 02:00 to 03:00 on 14 Mar 2027.
$fgap = ['start_utc' => '2027-03-01 10:30:00', 'end_utc' => '2027-03-01 10:45:00', 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=DAILY;COUNT=20'];
$fTokyo = $fexp($fgap, '2027-03-13T00:00:00Z', '2027-03-16T00:00:00Z', 'Asia/Tokyo');
checkEq('floating gap: daily 02:30 at Home is 02:30 in Tokyo on the day Home skips it', ['03-14 02:30-02:45', '03-15 02:30-02:45', '03-16 02:30-02:45'], $fclock($fTokyo, 'Asia/Tokyo'));
checkEq('floating gap: the occurrence keys stay as stored, 03:30 at Home that day', ['2027-03-14 10:30:00', '2027-03-15 09:30:00', '2027-03-16 09:30:00'], array_column($fTokyo, 'instanceUtc'));
checkEq('floating gap: a Home viewer sees 03:30 that day, its own zone\'s reading', ['03-13 02:30-02:45', '03-14 03:30-03:45', '03-15 02:30-02:45'],
    $fclock($fexp($fgap, '2027-03-13T08:00:00Z', '2027-03-16T08:00:00Z', 'America/Los_Angeles'), 'America/Los_Angeles'));
// The reverse: stored in Tokyo, which has no gap, viewed in Los Angeles, which skips 02:30 on 14 Mar.
$fgapTokyo = ['start_utc' => '2027-02-28 17:30:00', 'end_utc' => '2027-02-28 17:45:00', 'tzid' => 'Asia/Tokyo', 'rrule' => 'FREQ=DAILY;COUNT=20'];
checkEq('floating gap: a viewer whose zone skips 02:30 reads it by the gap rule, the range moving as one', ['03-13 02:30-02:45', '03-14 03:30-03:45', '03-15 02:30-02:45'],
    $fclock($fexp($fgapTokyo, '2027-03-13T08:00:00Z', '2027-03-16T08:00:00Z', 'America/Los_Angeles'), 'America/Los_Angeles'));
// The rule's own clock comes from the expander, before the zone reads it.
$fit = Recurrence::iterator(['start_utc' => '2027-03-13 10:30:00', 'end_utc' => '2027-03-13 10:45:00', 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=DAILY;COUNT=3', 'all_day' => 0]);
$fwalls = [];
foreach ($fit as $t) {
    $fwalls[] = gmdate('m-d H:i', $fit->wall()) . ' at ' . $t->format('H:i');
}
checkEq('floating gap: the expander names 02:30 as the clock it generated 03:30 from', ['03-13 02:30 at 02:30', '03-14 02:30 at 03:30', '03-15 02:30 at 02:30'], $fwalls);

// The window a floating series is expanded over is the viewer's moved to the
// series' own clock, so the cap is spent on what the window shows.
$fmin = ['start_utc' => '2027-01-01 00:00:00', 'end_utc' => '2027-01-01 00:01:00', 'tzid' => 'UTC', 'rrule' => 'FREQ=MINUTELY'];
$fr = new Recurrence();
$fm = $fexp($fmin, '2027-01-10T12:00:00Z', '2027-01-10T13:00:00Z', 'UTC', $fr);
checkEq('floating window: minutely from 1 Jan, an hour on 10 Jan, 60 shown and nothing cut short', [60, '2027-01-10 12:00', []], [count($fm), $fs($fm[0]['start'] ?? $fu('1970-01-01 00:00')), $fr->capped()]);
$fr = new Recurrence();
$fm = $fexp($fmin, '2027-01-10T12:00:00Z', '2027-01-10T13:00:00Z', 'Asia/Tokyo', $fr);
checkEq('floating window: the same seen from Tokyo, 60 from 21:00 there', [60, '2027-01-10 12:00', []], [count($fm), $fs($fm[0]['start'] ?? $fu('1970-01-01 00:00')), $fr->capped()]);
$fr = new Recurrence();
$fm = $fexp(['tzid' => 'America/Los_Angeles'] + $fmin, '2027-01-10T12:00:00Z', '2027-01-10T13:00:00Z', 'Asia/Tokyo', $fr);
checkEq('floating window: stored at Home, seen from Tokyo, 60', [60, []], [count($fm), $fr->capped()]);
$fsec = ['start_utc' => '2027-01-01 00:00:00', 'end_utc' => '2027-01-01 00:00:01', 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=SECONDLY'];
$fr = new Recurrence();
$fm = $fexp($fsec, '2027-01-10T12:00:00Z', '2027-01-10T12:10:00Z', 'Europe/Berlin', $fr);
checkEq('floating window: secondly, ten minutes, 600 shown', [600, '2027-01-10 12:00', []], [count($fm), $fs($fm[0]['start'] ?? $fu('1970-01-01 00:00')), $fr->capped()]);
$fr = new Recurrence();
$fm = $fexp($fsec, '2027-01-10T12:00:00Z', '2027-01-10T13:00:00Z', 'Europe/Berlin', $fr);
checkEq('floating window: secondly, an hour, cut short at the window\'s start and said so', [1000, '2027-01-10 12:00', [1]], [count($fm), $fs($fm[0]['start'] ?? $fu('1970-01-01 00:00')), $fr->capped()]);
// Near Home's change (10:00 UTC on 14 Mar) the moved window's edge widens by the hour the clocks move: more than the cap of seconds before the window.
$fr = new Recurrence();
$fm = $fexp($fsec, '2027-03-14T12:00:00Z', '2027-03-14T12:05:00Z', 'Europe/Berlin', $fr);
checkEq('floating window: secondly beside a clock change still shows the window, 300', [300, '2027-03-14 12:00', []], [count($fm), $fs($fm[0]['start'] ?? $fu('1970-01-01 00:00')), $fr->capped()]);

if (class_exists(\Sabre\VObject\Reader::class)) {
    // A start with no TZID and no Z comes in floating, at its wall clock in
    // the zone the import reads it in (the owner's Home); a zoned one doesn't.
    $floatIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
        . "BEGIN:VEVENT\r\nUID:float-one\r\nSUMMARY:Sample meditation\r\nDTSTART:20270304T070000\r\nDTEND:20270304T073000\r\nRRULE:FREQ=DAILY;COUNT=5\r\nEND:VEVENT\r\n"
        . "BEGIN:VEVENT\r\nUID:zoned-one\r\nSUMMARY:Sample call\r\nDTSTART;TZID=Europe/Berlin:20270304T090000\r\nDTEND;TZID=Europe/Berlin:20270304T100000\r\nEND:VEVENT\r\n"
        . "BEGIN:VEVENT\r\nUID:utc-one\r\nSUMMARY:Sample launch\r\nDTSTART:20270304T170000Z\r\nDTEND:20270304T180000Z\r\nEND:VEVENT\r\n"
        . "BEGIN:VEVENT\r\nUID:day-one\r\nSUMMARY:Sample holiday\r\nDTSTART;VALUE=DATE:20270305\r\nDTEND;VALUE=DATE:20270306\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    $floatParsed = Ics::parse($floatIcs, 'America/Los_Angeles');
    checkEq('floating ics: a zoneless start floats; zoned, UTC and all-day ones do not', [1, 0, 0, 0], array_column($floatParsed, 'floating'));
    checkEq('floating ics: read at its wall clock in the Home zone', ['2027-03-04 15:00:00', 'America/Los_Angeles'], [$floatParsed[0]['start_utc'], $floatParsed[0]['tzid']]);

    // Out again with no TZID and no Z, its UNTIL a local time as RFC 5545 asks.
    $floatRow = ['uid' => 'float-out', 'title' => 'Sample stretching', 'start_utc' => '2027-03-04 15:00:00', 'end_utc' => '2027-03-04 15:30:00',
        'all_day' => 0, 'tzid' => 'America/Los_Angeles', 'floating' => 1, 'rrule' => 'FREQ=DAILY;UNTIL=20270320T145959Z',
        'exdates_json' => json_encode(['2027-03-06 15:00:00']), 'status' => 'confirmed'];
    $floatOut = Ics::buildObject([
        $floatRow,
        ['uid' => 'float-out', 'title' => 'Sample stretching, late', 'start_utc' => '2027-03-16 15:00:00', 'end_utc' => '2027-03-16 15:30:00', 'all_day' => 0,
            'tzid' => 'America/Los_Angeles', 'floating' => 1, 'recurrence_instance_utc' => '2027-03-16 14:00:00', 'status' => 'confirmed'],
    ]);
    check('floating export: DTSTART and DTEND are wall clocks with no zone', str_contains($floatOut, "DTSTART:20270304T070000\r\n") && str_contains($floatOut, "DTEND:20270304T073000\r\n"), $floatOut);
    check('floating export: no TZID and no VTIMEZONE', !str_contains($floatOut, 'TZID') && !str_contains($floatOut, 'VTIMEZONE'));
    check('floating export: UNTIL is a local time too', str_contains($floatOut, 'RRULE:FREQ=DAILY;UNTIL=20270320T075959'));
    check('floating export: EXDATE and RECURRENCE-ID are wall clocks', str_contains($floatOut, "EXDATE:20270306T070000\r\n") && str_contains($floatOut, "RECURRENCE-ID:20270316T070000\r\n"));
    $floatBack = Ics::parse($floatOut, 'America/Los_Angeles');
    checkEq('floating export: read back floating at the same wall clock', [[1, '2027-03-04 15:00:00'], [1, '2027-03-16 15:00:00']],
        array_map(static fn(array $e): array => [$e['floating'], $e['start_utc']], $floatBack));
}

// Quick add: "floating" or "local time" after the time (Fantastical's word).
$fqNow = new DateTimeImmutable('2027-03-01T10:00:00-08:00');
$fq = static fn(string $text): array => FallbackParser::parse($text, 'America/Los_Angeles', $fqNow);
$fqMed = $fq('Meditation 7am floating');
checkEq('floating quick add: "Meditation 7am floating"', ['Meditation', true, '07:00'], [$fqMed['title'], $fqMed['floating'], substr($fqMed['start'], 11, 5)]);
$fqRun = $fq('Run tomorrow 6:30am local time');
checkEq('floating quick add: "Run tomorrow 6:30am local time"', ['Run', true, '2027-03-02T06:30'], [$fqRun['title'], $fqRun['floating'], substr($fqRun['start'], 0, 16)]);
$fqDaily = $fq('Vitamins every day 8am floating');
checkEq('floating quick add: with a repeat', ['Vitamins', true, true], [$fqDaily['title'], $fqDaily['floating'], $fqDaily['rrule'] !== null]);
checkEq('floating quick add: without the word, pinned', false, $fq('Standup 9am')['floating']);
checkEq('floating quick add: "floating" not after a time stays in the title', ['Floating lanterns', false], array_values(array_intersect_key($fq('Floating lanterns Friday 8pm'), ['title' => 1, 'floating' => 1])));
// The model agrees: its floating is taken, and either reading is enough.
$fqLlm = ['title' => 'Meditation', 'start' => '2027-03-02T07:00:00-08:00', 'end' => '2027-03-02T08:00:00-08:00', 'allDay' => false, 'location' => null, 'personNames' => [], 'rrule' => null, 'floating' => true];
checkEq('floating quick add: a model draft saying floating floats', true, QuickAdd::mergeLlm($fqLlm, $fq('Meditation tomorrow 7am'), $fqNow)['floating']);
checkEq('floating quick add: the parser\'s reading stands when the model misses it', true, QuickAdd::mergeLlm(['floating' => false] + $fqLlm, $fq('Meditation tomorrow 7am floating'), $fqNow)['floating']);
