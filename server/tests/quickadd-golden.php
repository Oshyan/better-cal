<?php

declare(strict_types=1);

// Quick add golden readings: about two thousand phrases (titles that look like
// dates, crossed with the date and time forms people type) and what the
// fallback parser makes of each, in fixtures/quickadd-golden.json. Any change
// to a reading fails here, so a new rule can't quietly reread text that
// already worked. Review the listed changes; when every one is intended,
// regenerate with:
//
//   php server/tests/quickadd-golden.php --update
//
// Run on its own it compares and exits non-zero on a change; run.php includes
// it as part of the suite.

use BetterCal\Domain\FallbackParser;

$goldenStandalone = !function_exists('checkEq');
if ($goldenStandalone) {
    require __DIR__ . '/../vendor/autoload.php';
}

$goldenTitles = [
    'Lunch', 'Dinner with Sam', 'Standup', 'Dentist', 'Team sync', 'Call mom', 'Gym', 'Pickup from school',
    'Drive to airport', 'Flight to Springfield', 'Talk at the conference', 'Meet at Sat Nav Club', 'Sun Valley trip',
    'Brunch at Sunday Social', 'Movie night', 'Week review', 'Weekend planning', 'End of quarter review',
    'Board meeting', 'PT session', 'ET follow-up', 'MT prep', 'Read 3 chapters', 'Run 5 miles', 'Pay 2 bills',
    'Buy 10 stamps', 'Call 2 clients', 'Haircut', 'Remind Alex about rent', 'Alert the team', 'Day off',
    'Office hours', 'Move to new office', 'From scratch cooking class', 'Starting line meetup', 'The 1975 concert',
    'Room 101 meeting', 'Lunch at 9 Elm Court', 'Coffee at Cafe 3', 'Meeting with the team at HQ', 'Night hike',
    'Morning run', 'Evening class', 'Sat exam prep', 'Wed planning', 'Fri drinks', 'Thu sync', 'March for science',
    'May Day picnic', 'June wedding', 'Summer camp', 'Year end party', 'Month end close', '2 week sprint review',
    'Week 3 retro', 'Day 1 onboarding', 'Q3 planning', 'v2 launch', 'Mon-Fri check', 'Trip for 2',
];
$goldenWhens = [
    '', 'tomorrow', 'today', 'Friday', 'friday 3pm', 'next Thursday 7pm', 'at 7pm', 'at 7', 'at noon', '7/31',
    'July 31 8pm', 'Aug 3-7', 'Aug 3 to Aug 7', '2026-08-15', 'tomorrow at 9am', 'Monday 9:30am', 'at 19:00',
    '7-9pm', 'tonight', 'this evening', 'next week', 'in the morning', 'Sunday', 'Saturday 10am', 'Sat 10am',
    'Dec 20', 'the day after tomorrow', 'a week from Friday', 'every Tuesday', 'weekly', 'tomorrow for 2 hours',
    'on Friday', 'on Saturday at 8pm', 'on Jan 5', 'every 1st and 3rd Monday', '3pm ET with Sam',
];
// Day ranges and stretches, on their own and against a few titles.
$goldenExtra = [
    'Mon-Fri', 'Monday through Friday', 'Fri-Mon', 'Mon to Fri at 9am', 'Mon-Fri next week', 'Fri 5pm to Sun 2pm',
    'Thu 10pm to Fri 6am', 'Aug 3 9am to Aug 5 noon', 'daily Mon-Fri', 'Mon-Fri 9am', 'Mon–Fri', 'Mon—Fri 9am',
    'Aug 3–7', 'Fri 5pm – Sun 2pm', 'Mon-Fri 9am-5pm', 'Monday through Friday 9am to 5pm', 'Mon-Fri 10pm-6am',
    'Mon-Fri 10pm to 6am', 'Sat-Sun 10am-4pm', 'Aug 3-7 9am-5pm', 'Aug 3 to 7 9am-5pm', 'Aug 3 to Aug 7 9am-5pm',
    'Aug 3-7 10pm-6am', 'Dec 30 to Jan 2 9am-5pm', 'Aug 3-7 9am-noon', 'Aug 3 - 6-8pm', '3-7 Aug',
];
// What the parser assumed (`assumed`, 1.5.1), read on 2026-07-30 at 10:00:
// an am/pm it chose, a date put in next year, a time past today moved to
// tomorrow, no time read at all, and nothing assumed.
$goldenAssumed = [
    'Dentist Mar 3 2pm', 'Coffee at 2:30', 'Gym 6:30', 'Standup 9-5pm', 'Standup 9-5', 'Lunch 11-1pm',
    'Trip Mar 3-7', 'Call mom tonight', 'Standup 9am', 'Gym 07:30', 'Lunch tomorrow noon', 'Dentist Dec 3 2pm',
];
$goldenInputs = [];
foreach ($goldenTitles as $title) {
    foreach ($goldenWhens as $when) {
        // One with-clause per phrase: nobody types "Dinner with Sam 3pm
        // ET with Sam".
        if (preg_match('/\bwith\b/i', $title) === 1 && preg_match('/\bwith\b/i', $when) === 1) {
            continue;
        }
        $goldenInputs[] = trim("$title $when");
    }
}
foreach (['Standup', 'Workshop', 'Trip', 'Mon-Fri check', 'Ferry to Friday Harbor', 'Office hours'] as $title) {
    foreach ($goldenExtra as $when) {
        $goldenInputs[] = "$title $when";
    }
}
foreach ($goldenAssumed as $text) {
    $goldenInputs[] = $text;
}
$goldenInputs = array_values(array_unique($goldenInputs));

$goldenTz = 'America/Los_Angeles';
$goldenNow = new DateTimeImmutable('2026-07-30T10:00:00', new DateTimeZone($goldenTz)); // a Thursday
$goldenRead = static function (string $text) use ($goldenTz, $goldenNow): array {
    $d = FallbackParser::parse($text, $goldenTz, $goldenNow);
    return [
        $d['title'], substr($d['start'], 0, 16), substr($d['end'], 0, 16), $d['allDay'], $d['location'],
        $d['personNames'], $d['rrule'] ?? null, $d['reminders'] ?? null,
        // What it assumed, only when it assumed something.
    ] + ($d['assumed'] !== [] ? [8 => $d['assumed']] : []);
};
$goldenFile = __DIR__ . '/fixtures/quickadd-golden.json';

if ($goldenStandalone && in_array('--update', $argv, true)) {
    $lines = [];
    foreach ($goldenInputs as $text) {
        $lines[] = json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ': '
            . json_encode($goldenRead($text), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    file_put_contents($goldenFile, "{\n" . implode(",\n", $lines) . "\n}\n");
    echo count($lines) . " readings written\n";
    exit(0);
}

$golden = json_decode((string) file_get_contents($goldenFile), true, 16, JSON_THROW_ON_ERROR);
$goldenChanged = [];
foreach ($goldenInputs as $text) {
    $expected = $golden[$text] ?? null;
    $actual = $goldenRead($text);
    if ($expected !== $actual) {
        $goldenChanged[] = $text . "\n    was " . json_encode($expected, JSON_UNESCAPED_SLASHES) . "\n    now " . json_encode($actual, JSON_UNESCAPED_SLASHES);
    }
}
$goldenDetail = count($goldenChanged) . " of " . count($goldenInputs) . " readings changed (review, then rerun with --update):\n  "
    . implode("\n  ", array_slice($goldenChanged, 0, 12));

if ($goldenStandalone) {
    echo $goldenChanged === [] ? count($goldenInputs) . " readings unchanged\n" : $goldenDetail . "\n";
    exit($goldenChanged === [] ? 0 : 1);
}
check('quick add: golden readings are unchanged', $goldenChanged === [], $goldenDetail);
check('quick add: golden file covers every input', count($golden) === count($goldenInputs));
