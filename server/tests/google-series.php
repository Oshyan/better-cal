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
    'Google kept ' . $d(3, 15) . " as an ordinary day; the plan had 'Sample weekly, renamed' on " . $d(3, 16) . ' at 10:00.',
    "Google kept 'Sample weekly, renamed' on " . $d(3, 29) . "; the plan had 'Sample weekly, special'.",
    'Google kept ' . $d(4, 12) . '; the plan skipped it.',
], GoogleSeries::differences($target, $items, $year));
checkEq('google differences: none when the read-back equals the plan', [], GoogleSeries::differences([
    'exceptions' => ['2027-03-29 17:00:00' => ['title' => 'Sample weekly, renamed'] + $own], 'skips' => ['2027-04-05 17:00:00'],
] + $target, $items, $year));
checkEq('google differences: a rule Google kept in another form is no difference; another rule is', [[], ['Google kept the rule FREQ=WEEKLY;COUNT=10; the plan had FREQ=WEEKLY;COUNT=4.']], [
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

if (!empty($GLOBALS['__standalone'])) {
    echo "\n{$GLOBALS['__pass']} passed, {$GLOBALS['__fail']} failed\n";
    exit($GLOBALS['__fail'] === 0 ? 0 : 1);
}
