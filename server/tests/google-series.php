<?php

declare(strict_types=1);

// GoogleSeries' pure parts (#172, stage two): a rule compared as its parts,
// what a read-back shows Google holds, the steps it calls for, and the
// words a difference gets. No database. Required from run.php, or run
// alone: php server/tests/google-series.php. Invented data.

use BetterCal\Domain\GoogleSeries;

if (!function_exists('check')) {
    require dirname(__DIR__) . '/src/bootstrap.php';
    $GLOBALS['__pass'] = 0;
    $GLOBALS['__fail'] = 0;
    function check(string $name, bool $cond, string $detail = ''): void
    {
        if ($cond) {
            $GLOBALS['__pass']++;
            return;
        }
        $GLOBALS['__fail']++;
        echo "FAIL: $name" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
    function checkEq(string $name, mixed $expected, mixed $actual): void
    {
        check($name, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    $GLOBALS['__standalone'] = true;
}

// A rule as parts: Google reorders them (COUNT after FREQ or WKST, BYDAY last), a list is a set, INTERVAL=1 says nothing.
checkEq('google rule: the same parts in another order are the same rule', true, GoogleSeries::sameRule('FREQ=WEEKLY;BYDAY=MO,WE;COUNT=10', 'RRULE:FREQ=WEEKLY;COUNT=10;BYDAY=WE,MO'));
checkEq('google rule: INTERVAL=1 is no part', true, GoogleSeries::sameRule('FREQ=DAILY;INTERVAL=1;COUNT=5', 'FREQ=DAILY;COUNT=5'));
checkEq('google rule: a different list is another rule', false, GoogleSeries::sameRule('FREQ=WEEKLY;BYDAY=MO,WE', 'FREQ=WEEKLY;BYDAY=MO,FR'));
checkEq('google rule: a different bound is another rule', false, GoogleSeries::sameRule('FREQ=WEEKLY;COUNT=10', 'FREQ=WEEKLY;COUNT=4'));
checkEq('google rule: the clamped form in Google\'s order is the same rule', true, GoogleSeries::sameRule('FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1;COUNT=4', 'FREQ=MONTHLY;COUNT=4;BYMONTHDAY=30,29,28;BYSETPOS=-1'));

// A read-back of a weekly series after a rename at Google: the master, a
// live record Google renamed (the plan keeps its own title), a cancelled
// record, and a dropped record (cancelled, no originalStartTime), which
// says nothing.
$tz = 'America/Los_Angeles';
$when = static fn(string $iso): array => ['dateTime' => $iso, 'timeZone' => $tz];
$items = [
    ['id' => 'smpl2weekly', 'iCalUID' => 'smpl2weekly@google.com', 'etag' => '"e7"', 'status' => 'confirmed', 'summary' => 'Sample weekly, renamed',
        'start' => $when('2027-03-01T10:00:00-08:00'), 'end' => $when('2027-03-01T11:00:00-08:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=10']],
    ['id' => 'smpl2weekly_20270329T170000Z', 'iCalUID' => 'smpl2weekly@google.com', 'etag' => '"e7"', 'status' => 'confirmed', 'summary' => 'Sample weekly, renamed',
        'recurringEventId' => 'smpl2weekly', 'originalStartTime' => $when('2027-03-29T10:00:00-07:00'), 'start' => $when('2027-03-29T10:00:00-07:00'), 'end' => $when('2027-03-29T11:00:00-07:00')],
    ['id' => 'smpl2weekly_20270405T170000Z', 'iCalUID' => 'smpl2weekly@google.com', 'etag' => '"e7"', 'status' => 'cancelled',
        'recurringEventId' => 'smpl2weekly', 'originalStartTime' => $when('2027-04-05T10:00:00-07:00'), 'start' => $when('2027-04-05T10:00:00-07:00'), 'end' => $when('2027-04-05T11:00:00-07:00')],
    ['id' => 'smpl2weekly_20270315T170000Z', 'iCalUID' => 'smpl2weekly@google.com', 'etag' => '"e7"', 'status' => 'cancelled', 'summary' => 'Sample weekly',
        'start' => $when('2027-03-16T10:00:00-07:00'), 'end' => $when('2027-03-16T11:00:00-07:00')],
];
$held = GoogleSeries::held($items, 'smpl2weekly');
checkEq('google held: the master, one live record by its key, one cancelled record as a skip, the dropped record ignored', [
    'Sample weekly, renamed', ['2027-03-29 17:00:00'], ['2027-04-05 17:00:00'], ['2027-04-05 17:00:00'],
], [$held['master']['title'], array_keys($held['records']), array_keys($held['cancelled']), array_keys($held['skips'])]);

$master = ['title' => 'Sample weekly, renamed', 'description' => null, 'location' => null, 'status' => 'confirmed', 'start_utc' => '2027-03-01 18:00:00', 'end_utc' => '2027-03-01 19:00:00', 'all_day' => 0, 'tzid' => $tz, 'url' => null, 'rrule' => 'FREQ=WEEKLY;COUNT=10'];
$own = ['title' => 'Sample weekly, special', 'description' => null, 'location' => null, 'status' => 'confirmed', 'start_utc' => '2027-03-29 17:00:00', 'end_utc' => '2027-03-29 18:00:00', 'all_day' => 0, 'tzid' => $tz, 'url' => null, 'own' => ['title']];
$moved = ['title' => 'Sample weekly, renamed', 'description' => null, 'location' => null, 'status' => 'confirmed', 'start_utc' => '2027-03-16 17:00:00', 'end_utc' => '2027-03-16 18:00:00', 'all_day' => 0, 'tzid' => $tz, 'url' => null, 'own' => ['date']];
$target = ['uid' => 'smpl2weekly@google.com', 'googleId' => 'smpl2weekly', 'etag' => '"e7"', 'insert' => false, 'remove' => false, 'master' => $master,
    'exceptions' => ['2027-03-15 17:00:00' => $moved, '2027-03-29 17:00:00' => $own], 'skips' => ['2027-04-05 17:00:00', '2027-04-12 17:00:00'], 'singles' => []];
$steps = GoogleSeries::stepsFor($target, $items);
checkEq('google steps: the dropped moved day sent whole, the own title sent again, the skip Google lacks cancelled; the skip it holds left alone', [
    ['exception', 'smpl2weekly_20270315T170000Z', 'Sample weekly, renamed', '2027-03-16T10:00:00-07:00'],
    ['exception', 'smpl2weekly_20270329T170000Z', 'Sample weekly, special', '2027-03-29T10:00:00-07:00'],
    ['skip', 'smpl2weekly_20270412T170000Z', null, null],
], array_map(static fn(array $s): array => [$s[0], $s[1], $s[2]['summary'] ?? null, $s[2]['start']['dateTime'] ?? null], $steps));
check('google steps: an exception\'s body carries no rule and no link', !isset($steps[0][2]['recurrence']) && !isset($steps[0][2]['source']));

// A record the target has no exception for (a day rejoining the series) is put back to the series' values at its occurrence;
// one that holds them already (29 March, renamed with its series) is an ordinary day and is never sent (rule A).
$plain = GoogleSeries::stepsFor(['exceptions' => [], 'skips' => []] + $target, $items);
checkEq('google steps: a cancelled record the plan has no exception for is un-cancelled at the series\' values; a record at the series\' values is left alone', [
    ['plain', 'smpl2weekly_20270405T170000Z', 'Sample weekly, renamed', '2027-04-05T10:00:00-07:00', 'confirmed'],
], array_map(static fn(array $s): array => [$s[0], $s[1], $s[2]['summary'] ?? null, $s[2]['start']['dateTime'] ?? null, $s[2]['status'] ?? null], $plain));

// The words a difference gets, each naming the day.
$year = (int) gmdate('Y');
$d = static fn(int $m, int $day): string => BetterCal\Domain\Series\Words::date(BetterCal\Domain\Series\Cal::dn(2027, $m, $day), $year);
checkEq('google differences: a day Google kept as an ordinary one, a title Google kept, a skip Google kept a day', [
    'Google kept ' . $d(3, 15) . " as an ordinary day; this change had 'Sample weekly, renamed' on " . $d(3, 16) . ' at 10:00.',
    "Google kept 'Sample weekly, renamed' on " . $d(3, 29) . "; this change had 'Sample weekly, special'.",
    $d(4, 12) . ' is still on the calendar: this change deleted it, but Google Calendar kept it.',
], GoogleSeries::differences($target, $items, $year));
checkEq('google differences: none when the read-back equals the plan', [], GoogleSeries::differences([
    'exceptions' => ['2027-03-29 17:00:00' => ['title' => 'Sample weekly, renamed'] + $own], 'skips' => ['2027-04-05 17:00:00'],
] + $target, $items, $year));
checkEq('google differences: a rule Google kept in another form is no difference; another rule is', [[], ['Google kept the rule FREQ=WEEKLY;COUNT=10; this change had FREQ=WEEKLY;COUNT=4.']], [
    GoogleSeries::differences(['exceptions' => ['2027-03-29 17:00:00' => ['title' => 'Sample weekly, renamed'] + $own], 'skips' => ['2027-04-05 17:00:00'], 'master' => ['rrule' => 'FREQ=WEEKLY;INTERVAL=1;COUNT=10'] + $master] + $target, $items, $year),
    GoogleSeries::differences(['exceptions' => ['2027-03-29 17:00:00' => ['title' => 'Sample weekly, renamed'] + $own], 'skips' => ['2027-04-05 17:00:00'], 'master' => ['rrule' => 'FREQ=WEEKLY;COUNT=4'] + $master] + $target, $items, $year),
]);

// The master's body: the fields that differ, the start and end together, the rule as the one RRULE line whenever anything differs.
$before = ['rrule' => 'FREQ=WEEKLY;COUNT=10', 'title' => 'Sample weekly'] + $master;
checkEq('google master body: a rename alone carries the title and the rule line, nothing else', ['summary' => 'Sample weekly, renamed', 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=10']], GoogleSeries::masterBody($before, $master));
checkEq('google master body: nothing differs, nothing sent', [], GoogleSeries::masterBody($master, $master));
checkEq('google master body: a new clock carries start and end', ['start', 'end', 'recurrence'], array_keys(GoogleSeries::masterBody($master, ['start_utc' => '2027-03-01 19:00:00', 'end_utc' => '2027-03-01 20:00:00'] + $master)));
checkEq('google insert body: a new series carries its rule alone, never an EXDATE line', ['RRULE:FREQ=WEEKLY;COUNT=10'], GoogleSeries::insertBody($master)['recurrence']);
check('google chosen id: 26 characters of Google\'s alphabet', preg_match('/^[0-9a-v]{26}$/', GoogleSeries::chooseId()) === 1);

// ---- What a master write leaves at Google (afterMaster): identity, Google's own resets, and proof ----
// Each expectation is written from the probe's report (research/google-series/probe-2026-11-07.md, group 3) or from
// the ids alone, never from the function. Held state in held()'s shape.
$gm = static fn(array $over = []): array => $over + ['title' => 'Sample weekly', 'description' => 'Sample notes', 'location' => 'Sample room 1', 'status' => 'confirmed',
    'start_utc' => '2027-03-01 18:00:00', 'end_utc' => '2027-03-01 19:00:00', 'all_day' => 0, 'tzid' => 'America/Los_Angeles', 'url' => null, 'rrule' => 'FREQ=WEEKLY;COUNT=10'];
$gh = static fn(array $master, array $records, array $cancelled, array $exdates = []): array => ['master' => $master, 'records' => $records,
    'cancelled' => array_fill_keys($cancelled, true), 'skips' => array_fill_keys(array_merge($cancelled, $exdates), true), 'etags' => []];
$gKeys = static fn(array $h): array => [array_keys($h['records']), array_keys($h['cancelled']), array_keys($h['skips'])];

// A change between timed and all-day: an id carries its form, so every record and every skip goes, in both
// directions, at every clock: where the keys coincide (00:00 UTC), where none does (10:00 Los Angeles), and where a
// timed key is the next day's date key (19:00 New York, a rule on consecutive days).
$forms = [
    '00:00 UTC, weekly' => [
        ['start_utc' => '2027-01-04 00:00:00', 'end_utc' => '2027-01-04 01:00:00', 'all_day' => 0, 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=4'], ['2027-01-11 00:00:00', '2027-01-18 00:00:00'],
        ['start_utc' => '2027-01-04 00:00:00', 'end_utc' => '2027-01-05 00:00:00', 'all_day' => 1, 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=4'], ['2027-01-11 00:00:00', '2027-01-18 00:00:00'],
    ],
    '10:00 Los Angeles, weekly' => [
        ['start_utc' => '2027-01-04 18:00:00', 'end_utc' => '2027-01-04 19:00:00', 'all_day' => 0, 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=4'], ['2027-01-11 18:00:00', '2027-01-18 18:00:00'],
        ['start_utc' => '2027-01-04 00:00:00', 'end_utc' => '2027-01-05 00:00:00', 'all_day' => 1, 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=4'], ['2027-01-11 00:00:00', '2027-01-18 00:00:00'],
    ],
    '19:00 New York, daily' => [
        ['start_utc' => '2027-01-05 00:00:00', 'end_utc' => '2027-01-05 01:00:00', 'all_day' => 0, 'tzid' => 'America/New_York', 'rrule' => 'FREQ=DAILY;COUNT=6'], ['2027-01-07 00:00:00', '2027-01-08 00:00:00'],
        ['start_utc' => '2027-01-04 00:00:00', 'end_utc' => '2027-01-05 00:00:00', 'all_day' => 1, 'tzid' => 'UTC', 'rrule' => 'FREQ=DAILY;COUNT=6'], ['2027-01-06 00:00:00', '2027-01-07 00:00:00'],
    ],
];
foreach ($forms as $clock => [$timed, $timedKeys, $day, $dayKeys]) {
    foreach ([['timed to all-day', $timed, $timedKeys, $day], ['all-day to timed', $day, $dayKeys, $timed]] as [$way, $from, $keys, $to]) {
        $old = $gm($from);
        $new = $gm($to);
        $len = strtotime($old['end_utc'] . ' UTC') - strtotime($old['start_utc'] . ' UTC');
        $record = ['description' => 'Own notes for the day.', 'start_utc' => $keys[0], 'end_utc' => gmdate('Y-m-d H:i:s', strtotime($keys[0] . ' UTC') + $len)] + $old;
        $after = GoogleSeries::afterMaster($gh($old, [$keys[0] => $record], [$keys[1]]), $old, $new);
        checkEq("google after a master write: $way at $clock drops the edited day's record and the skip, whatever key they share with a day of the new series", [[], [], []], $gKeys($after));
        checkEq("google after a master write: $way at $clock leaves the master as sent", [(int) $new['all_day'], $new['start_utc'], $new['end_utc']], [(int) $after['master']['all_day'], $after['master']['start_utc'], $after['master']['end_utc']]);
    }
}

// The probe's starting state (group 3): weekly at 10:00 Los Angeles, ten times; occurrence 3 moved a day later,
// occurrence 4 at its own time (15:00), occurrence 5 with its own title, occurrence 6 cancelled.
[$k3, $k4, $k5, $k6] = ['2027-03-15 17:00:00', '2027-03-22 17:00:00', '2027-03-29 17:00:00', '2027-04-05 17:00:00'];
$m0 = $gm();
$probeRecords = [
    $k3 => ['start_utc' => '2027-03-16 17:00:00', 'end_utc' => '2027-03-16 18:00:00'] + $m0,
    $k4 => ['start_utc' => '2027-03-22 22:00:00', 'end_utc' => '2027-03-22 23:00:00'] + $m0,
    $k5 => ['title' => 'Sample weekly (one changed)', 'start_utc' => $k5, 'end_utc' => '2027-03-29 18:00:00'] + $m0,
];
$h0 = $gh($m0, $probeRecords, [$k6]);
$times = static fn(array $h): array => array_map(static fn(array $r): array => [$r['start_utc'], $r['end_utc']], $h['records']);
$whole = [[$k3, $k4, $k5], [$k6], [$k6]];
$none = [[], [], []];
foreach ([
    // [the probe's case, the master as changed, the records, cancelled records and skips left]
    ['3.1 the start time of day (10:00 to 11:00)', ['start_utc' => '2027-03-01 19:00:00', 'end_utc' => '2027-03-01 20:00:00'], $none],
    ['3.2 the length (an hour to ninety minutes)', ['end_utc' => '2027-03-01 19:30:00'], $whole],
    ['3.3 the zone (same clock, America/Denver)', ['start_utc' => '2027-03-01 17:00:00', 'end_utc' => '2027-03-01 18:00:00', 'tzid' => 'America/Denver'], $none],
    ['3.4 the start date a day later, same rule', ['start_utc' => '2027-03-02 18:00:00', 'end_utc' => '2027-03-02 19:00:00'], $none],
    ['3.5 the start date onto its second occurrence, same rule', ['start_utc' => '2027-03-08 18:00:00', 'end_utc' => '2027-03-08 19:00:00'], $whole],
    ['3.6 the rule: a weekday changed (BYDAY=TU)', ['rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=10'], $none],
    ['3.7 the rule: a weekday added (BYDAY=MO,WE)', ['rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=10'], [[$k3, $k4, $k5], [], []]],
    ['3.8 the rule: COUNT longer (12)', ['rrule' => 'FREQ=WEEKLY;COUNT=12'], $whole],
    ['3.9 the rule: COUNT shorter (4)', ['rrule' => 'FREQ=WEEKLY;COUNT=4'], [[$k3, $k4], [], []]],
    ['3.10 the rule: COUNT to UNTIL (after occurrence 4)', ['rrule' => 'FREQ=WEEKLY;UNTIL=20270323T065959Z'], [[$k3, $k4], [], []]],
    ['3.11 the rule: the clamped month-end form', ['rrule' => 'FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1;COUNT=4'], $none],
    ['3.12 the title', ['title' => 'Sample weekly renamed'], $whole],
    ['3.13 the description', ['description' => 'Sample notes, changed'], $whole],
    ['3.14 the location', ['location' => 'Sample room 2'], $whole],
    ['3.15 the status (tentative)', ['status' => 'tentative'], [[$k3, $k4, $k5, $k6], [], []]],
] as [$case, $change, $left]) {
    checkEq("google after a master write, probe $case: the records, cancelled records and skips left", $left, $gKeys(GoogleSeries::afterMaster($h0, $m0, $gm($change))));
}
checkEq('google after a master write, probe 3.2: every kept record\'s end follows the new length from its own start', [
    $k3 => ['2027-03-16 17:00:00', '2027-03-16 18:30:00'], $k4 => ['2027-03-22 22:00:00', '2027-03-22 23:30:00'], $k5 => [$k5, '2027-03-29 18:30:00'],
], $times(GoogleSeries::afterMaster($h0, $m0, $gm(['end_utc' => '2027-03-01 19:30:00']))));
checkEq('google after a master write, probe 3.5: every kept record\'s own date and time are reset to its occurrence', [
    $k3 => [$k3, '2027-03-15 18:00:00'], $k4 => [$k4, '2027-03-22 18:00:00'], $k5 => [$k5, '2027-03-29 18:00:00'],
], $times(GoogleSeries::afterMaster($h0, $m0, $gm(['start_utc' => '2027-03-08 18:00:00', 'end_utc' => '2027-03-08 19:00:00']))));
checkEq('google after a master write, probe 3.12: the title is written into every record, the one with its own title included',
    ['Sample weekly renamed', 'Sample weekly renamed', 'Sample weekly renamed'], array_values(array_column(GoogleSeries::afterMaster($h0, $m0, $gm(['title' => 'Sample weekly renamed']))['records'], 'title')));
$tentative = GoogleSeries::afterMaster($h0, $m0, $gm(['status' => 'tentative']));
checkEq('google after a master write, probe 3.15: a status is written into every record and brings the cancelled day back at the series\' values',
    [['tentative', 'tentative', 'tentative', 'tentative'], [$k6, '2027-04-05 18:00:00']], [array_values(array_column($tentative['records'], 'status')), [$tentative['records'][$k6]['start_utc'], $tentative['records'][$k6]['end_utc']]]);
checkEq('google after a master write, probe 3.16: a skip held as an EXDATE line goes with the rule line sent alone; the records stay', [[$k3, $k4, $k5], [], []],
    $gKeys(GoogleSeries::afterMaster($gh($m0, $probeRecords, [], [$k6]), $m0, $gm(['title' => 'Sample weekly renamed']))));
checkEq('google after a master write: nothing changed, nothing sent, the series as it was', $h0, GoogleSeries::afterMaster($h0, $m0, $m0));
// What the probe has not seen keep records is predicted dropped (decisions.md): wrong that way costs a refused Put back, never a day.
foreach ([
    ['the zone alone, no instant moved (10:00 Los Angeles to 11:00 Denver; probe case 3.17 not answered yet)', ['tzid' => 'America/Denver']],
    ['the link (Google\'s source has no probe case)', ['url' => 'https://example.test/sample']],
    ['a new frequency that leaves the days where they were (weekly to daily; no probe case)', ['rrule' => 'FREQ=DAILY;COUNT=70']],
    ['a new interval that leaves some days where they were (every week to every second week; no probe case)', ['rrule' => 'FREQ=WEEKLY;INTERVAL=2;COUNT=10']],
] as [$class, $change]) {
    checkEq("google after a master write, unproven: $class is predicted to drop every record and skip", $none, $gKeys(GoogleSeries::afterMaster($h0, $m0, $gm($change))));
}
// Proven classes together are predicted as each alone (3.16 sent the whole body): a rename with a new length.
$both = GoogleSeries::afterMaster($h0, $m0, $gm(['title' => 'Sample weekly renamed', 'end_utc' => '2027-03-01 19:30:00']));
checkEq('google after a master write: a rename with a new length keeps every record, renamed, its end following', [$whole, 'Sample weekly renamed', ['2027-03-22 22:00:00', '2027-03-22 23:30:00']],
    [$gKeys($both), $both['records'][$k4]['title'], [$both['records'][$k4]['start_utc'], $both['records'][$k4]['end_utc']]]);

// ---- What a read-back holds, by identity (held), and where a step is sent (stepsFor) ----
// A list that lags a change to all-day: the master in the date form, the old records still listed in the timed form on
// the very keys the all-day days have. They are no days of that master.
$dayM = ['id' => 'smpl3', 'iCalUID' => 'smpl3@google.com', 'etag' => '"e2"', 'status' => 'confirmed', 'summary' => 'Sample series',
    'start' => ['date' => '2027-01-04'], 'end' => ['date' => '2027-01-05'], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4']];
$timedM = ['start' => ['dateTime' => '2027-01-04T00:00:00Z', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2027-01-04T01:00:00Z', 'timeZone' => 'UTC']] + $dayM;
$utc = static fn(string $iso): array => ['dateTime' => $iso, 'timeZone' => 'UTC'];
$timedRecord = ['id' => 'smpl3_20270111T000000Z', 'iCalUID' => 'smpl3@google.com', 'etag' => '"e2"', 'status' => 'confirmed', 'summary' => 'Sample series', 'description' => 'Own notes for the day.',
    'recurringEventId' => 'smpl3', 'originalStartTime' => $utc('2027-01-11T00:00:00Z'), 'start' => $utc('2027-01-11T00:00:00Z'), 'end' => $utc('2027-01-11T01:00:00Z')];
$timedSkip = ['id' => 'smpl3_20270118T000000Z', 'iCalUID' => 'smpl3@google.com', 'etag' => '"e2"', 'status' => 'cancelled',
    'recurringEventId' => 'smpl3', 'originalStartTime' => $utc('2027-01-18T00:00:00Z'), 'start' => $utc('2027-01-18T00:00:00Z'), 'end' => $utc('2027-01-18T01:00:00Z')];
$dayRecord = ['id' => 'smpl3_20270111', 'iCalUID' => 'smpl3@google.com', 'etag' => '"e2"', 'status' => 'confirmed', 'summary' => 'Sample series', 'description' => 'Own notes for the day.',
    'recurringEventId' => 'smpl3', 'originalStartTime' => ['date' => '2027-01-11'], 'start' => ['date' => '2027-01-11'], 'end' => ['date' => '2027-01-12']];
$daySkip = ['id' => 'smpl3_20270118', 'iCalUID' => 'smpl3@google.com', 'etag' => '"e2"', 'status' => 'cancelled',
    'recurringEventId' => 'smpl3', 'originalStartTime' => ['date' => '2027-01-18'], 'start' => ['date' => '2027-01-18'], 'end' => ['date' => '2027-01-19']];
$heldKeys = static function (array $h): array {
    ksort($h['ids']);
    return [array_keys($h['records']), array_keys($h['cancelled']), array_keys($h['skips']), $h['ids']];
};
foreach ([[$dayM, $timedRecord, $timedSkip], [$timedSkip, $timedRecord, $dayM]] as $n => $list) {
    checkEq('google held: a record and a cancelled record in another form than the master read with them are no days of it, on an equal key too (list order ' . ($n + 1) . ')',
        [[], [], [], []], $heldKeys(GoogleSeries::held($list, 'smpl3')));
    checkEq('google held: the other way round, a timed master with date-form records (list order ' . ($n + 1) . ')',
        [[], [], [], []], $heldKeys(GoogleSeries::held(array_map(static fn(array $i): array => $i === $dayM ? $timedM : ($i === $timedRecord ? $dayRecord : $daySkip), $list), 'smpl3')));
}
checkEq('google held: records in the master\'s form are its days by key, each with the id Google lists it under',
    [['2027-01-11 00:00:00'], ['2027-01-18 00:00:00'], ['2027-01-18 00:00:00'], ['2027-01-11 00:00:00' => 'smpl3_20270111', '2027-01-18 00:00:00' => 'smpl3_20270118']],
    $heldKeys(GoogleSeries::held([$daySkip, $timedRecord, $dayRecord, $dayM, $timedSkip], 'smpl3')));
checkEq('google held: the same list read under the timed master holds the timed records and not the date-form ones',
    [['2027-01-11 00:00:00'], ['2027-01-18 00:00:00'], ['2027-01-18 00:00:00'], ['2027-01-11 00:00:00' => 'smpl3_20270111T000000Z', '2027-01-18 00:00:00' => 'smpl3_20270118T000000Z']],
    $heldKeys(GoogleSeries::held([$daySkip, $timedRecord, $dayRecord, $timedM, $timedSkip], 'smpl3')));
// The all-day target over each read: with the master in the date form, the lagging timed records are nothing, so the
// edited day and the skip are sent to the date-form ids; a day Google lists is sent to the id it lists.
$dayMaster = ['title' => 'Sample series', 'description' => null, 'location' => null, 'status' => 'confirmed', 'start_utc' => '2027-01-04 00:00:00', 'end_utc' => '2027-01-05 00:00:00', 'all_day' => 1, 'tzid' => 'UTC', 'url' => null, 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
$dayTarget = ['uid' => 'smpl3@google.com', 'googleId' => 'smpl3', 'etag' => '"e2"', 'insert' => false, 'remove' => false, 'master' => $dayMaster, 'singles' => [],
    'exceptions' => ['2027-01-11 00:00:00' => ['description' => 'Own notes for the day.', 'start_utc' => '2027-01-11 00:00:00', 'end_utc' => '2027-01-12 00:00:00', 'own' => ['description']] + $dayMaster], 'skips' => ['2027-01-18 00:00:00']];
$sent = static fn(array $steps): array => array_map(static fn(array $s): array => [$s[0], $s[1]], $steps);
checkEq('google steps: over a list that lags a change to all-day, the edited day and the skip go to the date-form ids',
    [['exception', 'smpl3_20270111'], ['skip', 'smpl3_20270118']], $sent(GoogleSeries::stepsFor($dayTarget, [$dayM, $timedRecord, $timedSkip])));
checkEq('google steps: a day Google holds as the plan has it, under its date-form id, is not sent again', [], $sent(GoogleSeries::stepsFor($dayTarget, [$dayM, $dayRecord, $daySkip, $timedRecord, $timedSkip])));
checkEq('google steps: an id is in the form of the master as read, never the target\'s (a timed master read for an all-day target: run() sends no step then)',
    [['exception', 'smpl3_20270111T000000Z']], $sent(GoogleSeries::stepsFor(['skips' => []] + $dayTarget, [$timedM])));

// ---- The change over what Google holds now (rebase), across a change of form ----
// With nothing changed at Google since, each day Google holds is as it was before, as the master write leaves it, or
// as the plan has it, so the plan stands whole: the edited day with its own notes, the skip where the plan has it. At
// each clock, both directions, and at each point a change can be read at: before its master write landed, right after
// it, and once every step has.
$rowOf = static fn(array $master, int $id, string $key): array => ['id' => $id, 'recurrence_instance_utc' => $key, 'description' => 'Own notes for the day.', 'override_fields' => '["description"]',
    'start_utc' => $key, 'end_utc' => gmdate('Y-m-d H:i:s', strtotime($key . ' UTC') + (strtotime($master['end_utc'] . ' UTC') - strtotime($master['start_utc'] . ' UTC')))] + $master;
$seriesOf = static fn(array $master, string $edited, string $skipped): array => GoogleSeries::stateOf(
    ['id' => 1, 'uid' => 'smpl4@google.com', 'google_event_id' => 'smpl4', 'exdates_json' => json_encode([$skipped])] + $master, [$rowOf($master, 2, $edited)]);
$nowOf = static fn(array $s, bool $days): array => ['master' => $s['master'], 'records' => $days ? $s['exceptions'] : [],
    'cancelled' => $days ? array_fill_keys($s['skips'], true) : [], 'skips' => $days ? array_fill_keys($s['skips'], true) : [], 'etags' => [], 'ids' => []];
$daysOf = static fn(array $s): array => [array_map(static fn(array $x): array => [$x['description'], $x['own'], $x['start_utc'], (int) $x['all_day']], $s['exceptions']), $s['skips']];
$year = 2027;
foreach ($forms as $clock => [$timed, $timedKeys, $day, $dayKeys]) {
    foreach ([['timed to all-day', $timed, $timedKeys, $day, $dayKeys], ['all-day to timed', $day, $dayKeys, $timed, $timedKeys]] as [$way, $from, $fromKeys, $to, $toKeys]) {
        $before = $seriesOf($gm($from), $fromKeys[0], $fromKeys[1]);
        $target = $seriesOf($gm($to), $toKeys[0], $toKeys[1]);
        foreach ([
            'before its master write landed' => $nowOf($before, true),
            'right after its master write' => $nowOf($target, false),
            'once every step landed' => $nowOf($target, true),
        ] as $when => $now) {
            [$rebased, $words, $conflicts] = GoogleSeries::rebase($before, $target, $now, $year);
            checkEq("google rebase: $way at $clock, read $when with nothing changed at Google since: the plan stands whole, nothing said", [$daysOf($target), [], []], [$daysOf($rebased), $words, $conflicts]);
        }
    }
}
// A change made at Google since, on a day whose row the plan carries: matched by the row, not by the key, so it lands
// on that day at every clock. The day's title is changed at Google right after the master write (the new form); the
// plan does not change the title, so Google's stays, with the day's own notes from the plan.
foreach ($forms as $clock => [$timed, $timedKeys, $day, $dayKeys]) {
    $before = $seriesOf($gm($timed), $timedKeys[0], $timedKeys[1]);
    $target = $seriesOf($gm($day), $dayKeys[0], $dayKeys[1]);
    $now = $nowOf($target, false);
    $now['records'][$dayKeys[0]] = ['title' => 'Renamed at Google', 'start_utc' => $dayKeys[0], 'end_utc' => gmdate('Y-m-d H:i:s', strtotime($dayKeys[0] . ' UTC') + 86400)] + $target['master'];
    [$rebased, $words] = GoogleSeries::rebase($before, $target, $now, $year);
    $x = $rebased['exceptions'][$dayKeys[0]] ?? [];
    checkEq("google rebase: timed to all-day at $clock, the edited day renamed at Google since: it keeps Google's title and its own notes, on its own day, and the skip stands",
        ['Renamed at Google', 'Own notes for the day.', [$dayKeys[1]], []], [$x['title'] ?? null, $x['description'] ?? null, $rebased['skips'], $words]);
}

// A change made at Google since on a day the plan has no row for, across a change to all-day west of UTC (daily at
// 19:00 New York, so the timed key of one day is the date key of the next). The day before it was skipped in the old
// form on that very key; that skip is another instance, another day, and says nothing about this one.
$nyTimed = $gm($forms['19:00 New York, daily'][0]);
$nyDay = $gm($forms['19:00 New York, daily'][2]);
$bare = static fn(array $master, array $skips): array => GoogleSeries::stateOf(['id' => 1, 'uid' => 'smpl5@google.com', 'google_event_id' => 'smpl5', 'exdates_json' => json_encode($skips)] + $master, []);
$before = $bare($nyTimed, ['2027-01-07 00:00:00']); // the evening of 6 Jan in New York, skipped
$target = $bare($nyDay, ['2027-01-06 00:00:00']);   // all-day 6 Jan, skipped; 7 Jan an ordinary day
$now = $nowOf($target, false);
$now['records']['2027-01-07 00:00:00'] = ['title' => 'Renamed at Google', 'start_utc' => '2027-01-07 00:00:00', 'end_utc' => '2027-01-08 00:00:00'] + $target['master'];
[$rebased, $words] = GoogleSeries::rebase($before, $target, $now, $year);
checkEq('google rebase: made all-day west of UTC, the day after a skipped day renamed at Google since: it keeps Google\'s title (the plan changes nothing of that day), and the skip stands on its own day',
    [['2027-01-07 00:00:00' => 'Renamed at Google'], ['2027-01-06 00:00:00'], []], [array_column($rebased['exceptions'], 'title', 'start_utc'), $rebased['skips'], $words]);
$now = $nowOf($target, false);
$now['cancelled']['2027-01-07 00:00:00'] = $now['skips']['2027-01-07 00:00:00'] = true;
[$rebased, $words] = GoogleSeries::rebase($before, $target, $now, $year);
checkEq('google rebase: made all-day west of UTC, the day after a skipped day deleted at Google since: it stays deleted, beside the plan\'s own skip',
    [[], ['2027-01-06 00:00:00', '2027-01-07 00:00:00'], []], [$rebased['exceptions'], $rebased['skips'], $words]);
// The same day skipped before and in the plan (00:00 UTC, the keys coincide and are one day), renamed at Google after
// the master write brought it back: the plan's skip would overwrite a change made elsewhere, so it is kept and named.
$before = $bare($gm($forms['00:00 UTC, weekly'][0]), ['2027-01-18 00:00:00']);
$target = $bare($gm($forms['00:00 UTC, weekly'][2]), ['2027-01-18 00:00:00']);
$now = $nowOf($target, false);
$now['records']['2027-01-18 00:00:00'] = ['title' => 'Renamed at Google', 'start_utc' => '2027-01-18 00:00:00', 'end_utc' => '2027-01-19 00:00:00'] + $target['master'];
[$rebased, $words, $conflicts] = GoogleSeries::rebase($before, $target, $now, $year);
checkEq('google rebase: made all-day at 00:00 UTC, a skipped day renamed at Google since the master write: kept as Google has it and named, never skipped over',
    [['2027-01-18 00:00:00' => 'Renamed at Google'], [], [BetterCal\Domain\Series\Words::date(BetterCal\Domain\Series\Cal::dn(2027, 1, 18), $year) . " was changed in Google Calendar while this change was waiting to be finished; Google's version of that day was kept."], [['2027-01-18 00:00:00', 'day']]],
    [array_column($rebased['exceptions'], 'title', 'start_utc'), $rebased['skips'], $words, $conflicts]);

// A write of an unproven class is read against both answers. The link changed with the length (an hour to ninety
// minutes) over a day with its own time and length: if Google kept the record, its end followed the new length (probe
// 3.2), which is the write's own effect, not a change made since; if it dropped it, the day is ordinary. Either way
// the day's own time and length are the plan's.
$wk = $gm();
$ownRow = ['id' => 2, 'recurrence_instance_utc' => $k4, 'start_utc' => '2027-03-22 22:00:00', 'end_utc' => '2027-03-23 00:30:00', 'override_fields' => '["time","length"]'] + $wk;
$before = GoogleSeries::stateOf(['id' => 1, 'uid' => 'smpl6@google.com', 'google_event_id' => 'smpl6', 'exdates_json' => json_encode([$k6])] + $wk, [$ownRow]);
$longer = ['end_utc' => '2027-03-01 19:30:00', 'url' => 'https://example.test/sample'] + $wk;
$target = GoogleSeries::stateOf(['id' => 1, 'uid' => 'smpl6@google.com', 'google_event_id' => 'smpl6', 'exdates_json' => json_encode([$k6])] + $longer, [['url' => 'https://example.test/sample'] + $ownRow]);
$kept = $nowOf($target, false);
$kept['records'][$k4] = ['start_utc' => '2027-03-22 22:00:00', 'end_utc' => '2027-03-22 23:30:00'] + $wk;
$kept['cancelled'][$k6] = $kept['skips'][$k6] = true;
foreach (['Google kept the record, its end following the new length' => $kept, 'Google dropped the record and the skip' => $nowOf($target, false)] as $answer => $now) {
    [$rebased, $words, $conflicts] = GoogleSeries::rebase($before, $target, $now, $year);
    checkEq("google rebase: the link and the length changed together, $answer: the day keeps its own time and length, the skip stands, nothing said",
        [['2027-03-22 22:00:00', '2027-03-23 00:30:00', ['length', 'time']], [$k6], [], []],
        [[$rebased['exceptions'][$k4]['start_utc'] ?? null, $rebased['exceptions'][$k4]['end_utc'] ?? null, (static function (array $own): array { sort($own); return $own; })($rebased['exceptions'][$k4]['own'] ?? [])], $rebased['skips'], $words, $conflicts]);
}

// ---- A change made at Google while a change between timed and all-day waits for its master write ----
// Finish reads Google still in the old form. The master write that follows drops every record and skip there, and no
// key of the old form names a day of the new one, so nothing is carried by its key: the plan stands whole, and each
// day changed at Google since is named once, by its date as it showed under the old form (the evening of Fri 8 Jan in
// New York is Fri 8 Jan, whatever its key's date), with what was done and that it is not kept. Expectations by date,
// from the calendar: [the edited day, the day changed at Google] of each series, as month and day.
$sinceDays = [
    '00:00 UTC, weekly' => ['2027-01-25 00:00:00', [11, 25], '2027-01-25 00:00:00', [11, 25]],
    '10:00 Los Angeles, weekly' => ['2027-01-25 18:00:00', [11, 25], '2027-01-25 00:00:00', [11, 25]],
    '19:00 New York, daily' => ['2027-01-09 00:00:00', [6, 8], '2027-01-08 00:00:00', [6, 8]],
];
$dateWords = static fn(int $day): string => BetterCal\Domain\Series\Words::date(BetterCal\Domain\Series\Cal::dn(2027, 1, $day), 2027);
$plus = static fn(string $key, int $seconds): string => gmdate('Y-m-d H:i:s', strtotime($key . ' UTC') + $seconds);
foreach ($forms as $clock => [$timed, $timedKeys, $day, $dayKeys]) {
    [$timedOther, $timedDates, $dayOther, $dayDates] = $sinceDays[$clock];
    foreach ([['timed to all-day', $timed, $timedKeys, $timedOther, $timedDates, $day, $dayKeys, 'became all-day', 'given another time'],
        ['all-day to timed', $day, $dayKeys, $dayOther, $dayDates, $timed, $timedKeys, 'stopped being all-day', 'moved to another date']] as [$way, $from, $fromKeys, $other, $dates, $to, $toKeys, $why, $retimed]) {
        $before = $seriesOf($gm($from), $fromKeys[0], $fromKeys[1]);
        $target = $seriesOf($gm($to), $toKeys[0], $toKeys[1]);
        $m = $before['master'];
        $len = strtotime($m['end_utc'] . ' UTC') - strtotime($m['start_utc'] . ' UTC');
        $ordinary = ['start_utc' => $other, 'end_utc' => $plus($other, $len)] + $m;
        $changes = [
            'renamed' => [static function (array $now) use ($other, $ordinary): array {
                $now['records'][$other] = ['title' => 'Renamed at Google'] + $ordinary;
                return $now;
            }, $dates[1], 'renamed'],
            'deleted' => [static function (array $now) use ($other): array {
                $now['cancelled'][$other] = $now['skips'][$other] = true;
                return $now;
            }, $dates[1], 'deleted'],
            // A day of a timed series five hours later that day; a day of an all-day series a day later.
            'given another time, or date' => [static function (array $now) use ($other, $ordinary, $m, $plus, $len): array {
                $shift = (int) $m['all_day'] === 1 ? 86400 : 18000;
                $now['records'][$other] = ['start_utc' => $plus($other, $shift), 'end_utc' => $plus($other, $shift + $len)] + $ordinary;
                return $now;
            }, $dates[1], $retimed],
            'the plan\'s edited day given other notes' => [static function (array $now) use ($fromKeys): array {
                $now['records'][$fromKeys[0]]['description'] = 'Notes from Google';
                return $now;
            }, $dates[0], 'given other notes'],
        ];
        foreach ($changes as $what => [$change, $date, $done]) {
            [$rebased, $words, $conflicts] = GoogleSeries::rebase($before, $target, $change($nowOf($before, true)), $year);
            checkEq("google rebase: $way at $clock, its master write not yet at Google, a day $what at Google since: the plan stands whole and the change is named, by its own date, as not kept",
                [$daysOf($target), ['While this change was waiting to be finished, ' . $dateWords($date) . " was $done in Google Calendar. That was lost when the event $why. Make it again there if you still want it."], []],
                [$daysOf($rebased), $words, $conflicts]);
        }
    }
}
// Several things done to one day are one sentence; a skipped day brought back is named so.
$before = $seriesOf($gm($forms['19:00 New York, daily'][0]), '2027-01-07 00:00:00', '2027-01-08 00:00:00');
$target = $seriesOf($gm($forms['19:00 New York, daily'][2]), '2027-01-06 00:00:00', '2027-01-07 00:00:00');
$now = $nowOf($before, true);
$now['records']['2027-01-09 00:00:00'] = ['title' => 'Renamed at Google', 'description' => 'Notes from Google', 'location' => 'Sample Annex', 'start_utc' => '2027-01-09 00:00:00', 'end_utc' => '2027-01-09 01:00:00'] + $before['master'];
unset($now['cancelled']['2027-01-08 00:00:00'], $now['skips']['2027-01-08 00:00:00']);
[$rebased, $words] = GoogleSeries::rebase($before, $target, $now, $year);
checkEq('google rebase: made all-day, not yet at Google, a skipped day brought back and another renamed with other notes and place: one sentence a day, in date order, the plan whole', [$daysOf($target), [
    'While this change was waiting to be finished, ' . $dateWords(7) . ' was brought back in Google Calendar. That was lost when the event became all-day. Make it again there if you still want it.',
    'While this change was waiting to be finished, ' . $dateWords(8) . ' was renamed, given other notes and given another place in Google Calendar. That was lost when the event became all-day. Make it again there if you still want it.',
]], [$daysOf($rebased), $words]);

// ---- The same on the wider axis: any change whose first write has not reached Google ----
// Google holds the series keyed as the change found it. Where the change gives its days other keys (a new time, zone,
// start day or rule, as a new form does), which key a day has afterwards is the planner's to say, so a change made at
// Google since is named with a true reason and the plan stands whole. Where it keeps every key (a rename, a new
// length, a bound), the change made at Google is carried exactly, by its key; on a day the series no longer has, it
// is named. The series: weekly at 10:00 Los Angeles, four times; 11 Jan edited (its own notes), 18 Jan skipped; the
// day changed at Google is Mon 25 Jan.
$laMaster = $gm($forms['10:00 Los Angeles, weekly'][0]);
$laBefore = $seriesOf($laMaster, '2027-01-11 18:00:00', '2027-01-18 18:00:00');
$renamedAt = static function (array $now, string $key, array $master): array {
    $now['records'][$key] = ['title' => 'Renamed at Google', 'start_utc' => $key, 'end_utc' => gmdate('Y-m-d H:i:s', strtotime($key . ' UTC') + 3600)] + $master;
    return $now;
};
$sinceRename = $renamedAt($nowOf($laBefore, true), '2027-01-25 18:00:00', $laBefore['master']);
foreach ([
    // [what the change does, the master as changed, where the edited day and the skip are then, the reason a person reads]
    ['a new time (10:00 to 11:00)', ['start_utc' => '2027-01-04 19:00:00', 'end_utc' => '2027-01-04 20:00:00'], ['2027-01-11 19:00:00', '2027-01-18 19:00:00'], "the event's time changed"],
    ['another zone keeping the clock (10:00 Denver)', ['start_utc' => '2027-01-04 17:00:00', 'end_utc' => '2027-01-04 18:00:00', 'tzid' => 'America/Denver'], ['2027-01-11 17:00:00', '2027-01-18 17:00:00'], "the event's time zone changed"],
    ['a drag a week along its pattern', ['start_utc' => '2027-01-11 18:00:00', 'end_utc' => '2027-01-11 19:00:00'], ['2027-01-18 18:00:00', '2027-01-25 18:00:00'], 'the event moved to another day'],
    ['a new rule (every second week)', ['rrule' => 'FREQ=WEEKLY;INTERVAL=2;COUNT=4'], ['2027-01-18 18:00:00', '2027-02-01 18:00:00'], 'how the event repeats changed'],
] as [$what, $change, $toKeys, $why]) {
    $target = $seriesOf($gm($change + $forms['10:00 Los Angeles, weekly'][0]), $toKeys[0], $toKeys[1]);
    [$rebased, $words, $conflicts] = GoogleSeries::rebase($laBefore, $target, $sinceRename, $year);
    checkEq("google rebase: $what, its master write not yet at Google, a day renamed at Google since: the plan stands whole and the rename is named as not kept, with the reason",
        [$daysOf($target), ['While this change was waiting to be finished, ' . $dateWords(25) . " was renamed in Google Calendar. That was lost when $why. Make it again there if you still want it."], []], [$daysOf($rebased), $words, $conflicts]);
    [$rebased, $words] = GoogleSeries::rebase($laBefore, $target, $nowOf($laBefore, true), $year);
    checkEq("google rebase: $what, its master write not yet at Google, nothing changed at Google since: the plan whole, nothing said", [$daysOf($target), []], [$daysOf($rebased), $words]);
}
// A change that keeps every key: the rename made at Google is carried exactly, on its own key, beside the plan. Where
// the plan changes the same thing (it renames the series, so every day's title), the day's title is kept and named;
// where it does not (a new length), it is kept with nothing said.
foreach ([
    ['a rename of the series', ['title' => 'Sample weekly renamed'], ["The title of 'Renamed at Google' on " . $dateWords(25) . " was changed in Google Calendar while this change was waiting to be finished; Google's title was kept."]],
    ['a new length (an hour to ninety minutes)', ['end_utc' => '2027-01-04 19:30:00'], []],
] as [$what, $change, $said]) {
    $target = $seriesOf($gm($change + $forms['10:00 Los Angeles, weekly'][0]), '2027-01-11 18:00:00', '2027-01-18 18:00:00');
    [$rebased, $words] = GoogleSeries::rebase($laBefore, $target, $sinceRename, $year);
    checkEq("google rebase: $what, its master write not yet at Google, a day renamed at Google since: the rename is kept on its day, the plan's days beside it",
        [['2027-01-11 18:00:00', '2027-01-25 18:00:00'], 'Own notes for the day.', 'Renamed at Google', ['title'], ['2027-01-18 18:00:00'], $said],
        [array_keys($rebased['exceptions']), $rebased['exceptions']['2027-01-11 18:00:00']['description'] ?? null, $rebased['exceptions']['2027-01-25 18:00:00']['title'] ?? null,
            $rebased['exceptions']['2027-01-25 18:00:00']['own'] ?? null, $rebased['skips'], $words]);
}
// A shorter series (four times to three): Mon 25 Jan is no day of it any more, so no step can reach it. The rename made
// there is named, never sent; a deletion made on a day it keeps (Mon 4 Jan) is carried.
$shorter = $seriesOf($gm(['rrule' => 'FREQ=WEEKLY;COUNT=3'] + $forms['10:00 Los Angeles, weekly'][0]), '2027-01-11 18:00:00', '2027-01-18 18:00:00');
$now = $sinceRename;
$now['cancelled']['2027-01-04 18:00:00'] = $now['skips']['2027-01-04 18:00:00'] = true;
[$rebased, $words] = GoogleSeries::rebase($laBefore, $shorter, $now, $year);
checkEq('google rebase: a shorter series, its master write not yet at Google: a rename on the day it drops is named as not kept and no day is made of it; a deletion on a day it keeps is carried',
    [['2027-01-11 18:00:00'], ['2027-01-04 18:00:00', '2027-01-18 18:00:00'], ['While this change was waiting to be finished, ' . $dateWords(25) . ' was renamed in Google Calendar. That was lost when this change ended the event before that day. Make it again there if you still want it.']],
    [array_keys($rebased['exceptions']), $rebased['skips'], $words]);
[, $words] = GoogleSeries::rebase($laBefore, $shorter, $now, $year, 'while this change was waiting to be finished', false, true);
checkEq('google rebase: the same where the series is shorter because the change split it there (a series follows): the day is still there, and the reason says so',
    ['While this change was waiting to be finished, ' . $dateWords(25) . ' was renamed in Google Calendar. That was lost when this change split the event before that day. Make it again there if you still want it.'], $words);
[$rebased, $words] = GoogleSeries::rebase($laBefore, $shorter, $nowOf($shorter, true), $year);
checkEq('google rebase: the shorter series once its master write landed (Google dropped what was past the end itself): the plan whole, nothing said',
    [$daysOf($shorter), []], [$daysOf($rebased), $words]);
// A drag a week along the pattern that landed (its answer lost): 11 Jan edited and 25 Jan skipped before, so the plan has
// the edited day on 18 Jan and the skip on 1 Feb. Google kept the old record and the old skip at their old keys (probe
// 3.5), which is the write's own effect. And the day the plan moves the edited day onto, 18 Jan, was deleted at Google
// since: the deletion stands, and since the plan put an edited day there it is named, not kept in silence.
$dragBefore = $seriesOf($laMaster, '2027-01-11 18:00:00', '2027-01-25 18:00:00');
$dragged = $seriesOf($gm(['start_utc' => '2027-01-11 18:00:00', 'end_utc' => '2027-01-11 19:00:00'] + $forms['10:00 Los Angeles, weekly'][0]), '2027-01-18 18:00:00', '2027-02-01 18:00:00');
$now = ['master' => $dragged['master'], 'records' => ['2027-01-11 18:00:00' => $dragBefore['exceptions']['2027-01-11 18:00:00']],
    'cancelled' => ['2027-01-25 18:00:00' => true, '2027-01-18 18:00:00' => true], 'skips' => ['2027-01-25 18:00:00' => true, '2027-01-18 18:00:00' => true], 'etags' => [], 'ids' => []];
[$rebased, $words, $conflicts] = GoogleSeries::rebase($dragBefore, $dragged, $now, $year);
checkEq('google rebase: a drag that landed, the day the edited day moves onto deleted at Google since: it stays deleted and is named; the old skip Google kept at its old key is the write\'s own',
    [[], ['2027-01-18 18:00:00', '2027-02-01 18:00:00'], [$dateWords(18) . ' was deleted in Google Calendar while this change was waiting to be finished; it stays deleted.'], [['2027-01-18 18:00:00', 'day']]],
    [array_keys($rebased['exceptions']), $rebased['skips'], $words, $conflicts]);

// ---- What the condition believes a record holds of a field the day does not own (sameAsStored) ----
// The series was given a link. It is not proven that Google writes a series' link into its records, so an edited
// day's record holding either the series' link or the link it held before (none) is as stored; under each answer
// Google may give, the series is no "changed at Google", and the steps send the day its link where it lacks it.
$linkUrl = 'https://example.test/sample';
$linkMaster = ['id' => 1, 'uid' => 'smpl7@google.com', 'google_event_id' => 'smpl7', 'title' => 'Sample series', 'description' => null, 'location' => null, 'status' => 'confirmed', 'url' => $linkUrl,
    'start_utc' => '2027-01-04 00:00:00', 'end_utc' => '2027-01-04 01:00:00', 'all_day' => 0, 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=4', 'exdates_json' => null];
$linkRow = ['id' => 2, 'recurrence_instance_utc' => '2027-01-11 00:00:00', 'description' => 'Own notes for the day.', 'url' => null, 'override_fields' => '["description"]',
    'start_utc' => '2027-01-11 00:00:00', 'end_utc' => '2027-01-11 01:00:00'] + $linkMaster;
$source = static fn(?string $url): array => $url === null ? [] : ['source' => ['url' => $url, 'title' => 'example.test']];
$linkItem = ['id' => 'smpl7', 'iCalUID' => 'smpl7@google.com', 'etag' => '"e3"', 'status' => 'confirmed', 'summary' => 'Sample series',
    'start' => $utc('2027-01-04T00:00:00Z'), 'end' => $utc('2027-01-04T01:00:00Z'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4']] + $source($linkUrl);
$linkRecord = static fn(?string $url): array => ['id' => 'smpl7_20270111T000000Z', 'iCalUID' => 'smpl7@google.com', 'etag' => '"e3"', 'status' => 'confirmed', 'summary' => 'Sample series',
    'description' => 'Own notes for the day.', 'recurringEventId' => 'smpl7', 'originalStartTime' => $utc('2027-01-11T00:00:00Z'), 'start' => $utc('2027-01-11T00:00:00Z'), 'end' => $utc('2027-01-11T01:00:00Z')] + $source($url);
$linkTarget = GoogleSeries::stateOf($linkMaster, [$linkRow]);
checkEq('google condition: a series given a link, Google leaving the edited day\'s record without it: as stored, and the day is sent its link',
    [true, [['exception', 'smpl7_20270111T000000Z', $linkUrl]]],
    [GoogleSeries::sameAsStored([$linkItem, $linkRecord(null)], 'smpl7', $linkMaster, [$linkRow]),
        array_map(static fn(array $s): array => [$s[0], $s[1], $s[2]['source']['url'] ?? null], GoogleSeries::stepsFor($linkTarget, [$linkItem, $linkRecord(null)]))]);
checkEq('google condition: Google having written the link into the record: as stored, nothing to send',
    [true, []], [GoogleSeries::sameAsStored([$linkItem, $linkRecord($linkUrl)], 'smpl7', $linkMaster, [$linkRow]), GoogleSeries::stepsFor($linkTarget, [$linkItem, $linkRecord($linkUrl)])]);
$droppedRecord = ['id' => 'smpl7_20270111T000000Z', 'iCalUID' => 'smpl7@google.com', 'etag' => '"e3"', 'status' => 'cancelled', 'summary' => 'Sample series'];
checkEq('google condition: Google having dropped the record (the rows as a store of that leaves them, the day\'s row gone): as stored, and the plan\'s day is sent whole',
    [true, [['exception', 'smpl7_20270111T000000Z', 'Own notes for the day.']]],
    [GoogleSeries::sameAsStored([$linkItem, $droppedRecord], 'smpl7', $linkMaster, []),
        array_map(static fn(array $s): array => [$s[0], $s[1], $s[2]['description'] ?? null], GoogleSeries::stepsFor($linkTarget, [$linkItem, $droppedRecord]))]);
checkEq('google condition: the record given another link at Google is a change made there; so is any link on a day that owns its own',
    [false, false, true], [
        GoogleSeries::sameAsStored([$linkItem, $linkRecord('https://example.test/other')], 'smpl7', $linkMaster, [$linkRow]),
        GoogleSeries::sameAsStored([$linkItem, $linkRecord($linkUrl)], 'smpl7', $linkMaster, [['override_fields' => '["description","url"]'] + $linkRow]),
        GoogleSeries::sameAsStored([$linkItem, $linkRecord(null)], 'smpl7', $linkMaster, [['override_fields' => '["description","url"]'] + $linkRow]),
    ]);
checkEq('google condition: a field Google is proven to write into its records gets no such latitude (the record keeping an old title is a change)',
    false, GoogleSeries::sameAsStored([['summary' => 'Sample series renamed'] + $linkItem, $linkRecord($linkUrl)], 'smpl7', ['title' => 'Sample series renamed'] + $linkMaster, [['title' => 'Sample series'] + $linkRow]));

if (!empty($GLOBALS['__standalone'])) {
    echo "\n{$GLOBALS['__pass']} passed, {$GLOBALS['__fail']} failed\n";
    exit($GLOBALS['__fail'] === 0 ? 0 : 1);
}
