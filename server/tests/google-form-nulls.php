<?php

declare(strict_types=1);

// A change between timed and all-day sent over a resource Google holds names
// the held form's fields as null (GoogleWriter::overForm). The expected
// bodies are the ones real Google took in the probe of 2026-11-10 (cases
// 3.19 to 3.31, docs/design/research/google-series/probe-2026-11-10.md),
// written out here by hand; the bodies compared with them are the ones the
// app's own builders make for the same change: a series' master
// (GoogleSeries::masterBody) both ways at 10:00 Los Angeles and at 00:00
// UTC, a single event both ways (GoogleWriter::patchOf over its stored row,
// as Events::patchBody builds it), and one ordinary day of a timed series
// made all-day (patchOf over the occurrence, and the step GoogleSeries sends
// for an all-day edited day of a timed series). A change that keeps the form
// sends no null: that body is not proven. No database. Required from
// run.php, or run alone: php server/tests/google-form-nulls.php. Invented
// data.

use BetterCal\Domain\GoogleSeries;
use BetterCal\Domain\GoogleWriter;

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

(static function (): void {
    $la = 'America/Los_Angeles';
    $rule = 'FREQ=WEEKLY;COUNT=10';
    // A target's master (GoogleSeries::stateOf's shape): the series as stored, and as the change leaves it.
    $master = static fn(string $start, string $end, int $allDay, string $tz): array => [
        'title' => 'Sample form', 'description' => null, 'location' => null, 'status' => 'confirmed',
        'start_utc' => $start, 'end_utc' => $end, 'all_day' => $allDay, 'tzid' => $tz, 'url' => null, 'rrule' => $rule,
    ];
    $timedLa = $master('2027-03-01 18:00:00', '2027-03-01 19:00:00', 0, $la);
    $timedUtc = $master('2027-03-01 00:00:00', '2027-03-01 01:00:00', 0, 'UTC');
    $allDay = $master('2027-03-01 00:00:00', '2027-03-02 00:00:00', 1, 'UTC');
    $recurrence = ['RRULE:FREQ=WEEKLY;COUNT=10'];

    // The accepted bodies, as the probe sent them.
    $asDates = ['start' => ['date' => '2027-03-01', 'dateTime' => null, 'timeZone' => null], 'end' => ['date' => '2027-03-02', 'dateTime' => null, 'timeZone' => null]];
    $at10La = ['start' => ['dateTime' => '2027-03-01T10:00:00-08:00', 'timeZone' => $la, 'date' => null], 'end' => ['dateTime' => '2027-03-01T11:00:00-08:00', 'timeZone' => $la, 'date' => null]];
    $at0Utc = ['start' => ['dateTime' => '2027-03-01T00:00:00+00:00', 'timeZone' => 'UTC', 'date' => null], 'end' => ['dateTime' => '2027-03-01T01:00:00+00:00', 'timeZone' => 'UTC', 'date' => null]];

    checkEq('Form nulls: a series timed at 10:00 Los Angeles made all-day is the body Google took (3.19)', $asDates + ['recurrence' => $recurrence], GoogleSeries::masterBody($timedLa, $allDay));
    checkEq('Form nulls: a series timed at 00:00 UTC made all-day is the body Google took (3.21)', $asDates + ['recurrence' => $recurrence], GoogleSeries::masterBody($timedUtc, $allDay));
    checkEq('Form nulls: an all-day series made timed at 10:00 Los Angeles is the body Google took (3.23)', $at10La + ['recurrence' => $recurrence], GoogleSeries::masterBody($allDay, $timedLa));
    checkEq('Form nulls: an all-day series made timed at 00:00 UTC is the body Google took (3.25)', $at0Utc + ['recurrence' => $recurrence], GoogleSeries::masterBody($allDay, $timedUtc));
    // A series whose row is unknown here sends the whole and says nothing of a form it doesn't know.
    $whole = GoogleSeries::masterBody(null, $allDay);
    checkEq('Form nulls: a master body with no series as last read names no null in its start or end', [['date' => '2027-03-01'], ['date' => '2027-03-02']], [$whole['start'] ?? null, $whole['end'] ?? null]);

    // A single event: its row as stored and as the edit leaves it, through patchOf as Events builds the body.
    $row = static fn(string $start, string $end, int $allDay, string $tz): array => [
        'title' => 'Sample single', 'description' => 'Sample notes', 'location' => 'Sample room', 'status' => 'confirmed', 'url' => null,
        'start_utc' => $start, 'end_utc' => $end, 'all_day' => $allDay, 'tzid' => $tz, 'rrule' => null, 'exdates_json' => null,
    ];
    $singleTimed = $row('2027-03-01 18:00:00', '2027-03-01 19:00:00', 0, $la);
    $singleAllDay = $row('2027-03-01 00:00:00', '2027-03-02 00:00:00', 1, 'UTC');
    checkEq('Form nulls: a single event made all-day sends the start and end Google took (3.27)', $asDates, GoogleWriter::patchOf(GoogleWriter::body($singleTimed), GoogleWriter::body($singleAllDay)));
    checkEq('Form nulls: a single event made timed sends the start and end Google took (3.29)', $at10La, GoogleWriter::patchOf(GoogleWriter::body($singleAllDay), GoogleWriter::body($singleTimed)));

    // One ordinary day of the timed series (22 March, 10:00 Los Angeles, 17:00 UTC) made all-day.
    $day31 = ['start' => ['date' => '2027-03-22', 'dateTime' => null, 'timeZone' => null], 'end' => ['date' => '2027-03-23', 'dateTime' => null, 'timeZone' => null]];
    $occurrence = $row('2027-03-22 17:00:00', '2027-03-22 18:00:00', 0, $la);
    $dayAllDay = $row('2027-03-22 00:00:00', '2027-03-23 00:00:00', 1, 'UTC');
    checkEq('Form nulls: one ordinary day of a timed series made all-day sends the body Google took (3.31)', $day31,
        GoogleWriter::patchOf(GoogleWriter::instanceBody($occurrence), GoogleWriter::instanceBody($dayAllDay)));
    // The same day as an edited day of a series change: the step to its instance, the series timed at Google.
    $item = static fn(string $id, array $start, array $end, array $more = []): array => ['id' => $id, 'iCalUID' => 'smplform@google.com', 'etag' => '"e1"', 'status' => 'confirmed', 'summary' => 'Sample form',
        'start' => $start, 'end' => $end] + $more;
    $clock = static fn(string $iso): array => ['dateTime' => $iso, 'timeZone' => $la];
    $masterItem = $item('smplform', $clock('2027-03-01T10:00:00-08:00'), $clock('2027-03-01T11:00:00-08:00'), ['recurrence' => $recurrence]);
    $target = ['uid' => 'smplform@google.com', 'googleId' => 'smplform', 'etag' => '"e1"', 'insert' => false, 'remove' => false, 'master' => $timedLa,
        'exceptions' => ['2027-03-22 17:00:00' => ['own' => ['allDay']] + array_merge($timedLa, ['start_utc' => '2027-03-22 00:00:00', 'end_utc' => '2027-03-23 00:00:00', 'all_day' => 1, 'tzid' => 'UTC'])],
        'skips' => [], 'singles' => []];
    $steps = GoogleSeries::stepsFor($target, [$masterItem]);
    checkEq('Form nulls: an all-day edited day of a timed series goes to its timed instance with the start and end Google took (3.31)',
        [['exception', 'smplform_20270322T170000Z', $day31]],
        array_map(static fn(array $s): array => [$s[0], $s[1], array_intersect_key($s[2], ['start' => true, 'end' => true])], $steps));
    // Held as an all-day record already (3.31's result), the same day sent again keeps its form: no null.
    $record = $item('smplform_20270322T170000Z', ['date' => '2027-03-22'], ['date' => '2027-03-23'],
        ['recurringEventId' => 'smplform', 'originalStartTime' => $clock('2027-03-22T10:00:00-07:00'), 'description' => 'Sample notes from elsewhere']);
    $again = GoogleSeries::stepsFor($target, [$masterItem, $record]);
    checkEq('Form nulls: a day Google holds all-day, sent again all-day, names no null',
        [['exception', 'smplform_20270322T170000Z', ['start' => ['date' => '2027-03-22'], 'end' => ['date' => '2027-03-23']]]],
        array_map(static fn(array $s): array => [$s[0], $s[1], array_intersect_key($s[2], ['start' => true, 'end' => true])], $again));

    // Where the form doesn't change, nothing is nulled (no probe has sent that body).
    $at11 = array_merge($timedLa, ['start_utc' => '2027-03-01 19:00:00', 'end_utc' => '2027-03-01 20:00:00']);
    checkEq('Form nulls: a series moved to another clock, timed both sides, names no null',
        ['start' => ['dateTime' => '2027-03-01T11:00:00-08:00', 'timeZone' => $la], 'end' => ['dateTime' => '2027-03-01T12:00:00-08:00', 'timeZone' => $la], 'recurrence' => $recurrence],
        GoogleSeries::masterBody($timedLa, $at11));
    checkEq('Form nulls: an all-day single event given another day, all-day both sides, names no null',
        ['start' => ['date' => '2027-03-02'], 'end' => ['date' => '2027-03-03']],
        GoogleWriter::patchOf(GoogleWriter::body($singleAllDay), GoogleWriter::body(array_merge($singleAllDay, ['start_utc' => '2027-03-02 00:00:00', 'end_utc' => '2027-03-03 00:00:00']))));
    checkEq('Form nulls: a body with no start or end is left as it is', ['summary' => 'Sample'], GoogleWriter::overForm(['summary' => 'Sample'], true));
})();

if (!empty($GLOBALS['__standalone'])) {
    echo $GLOBALS['__pass'] . ' passed, ' . $GLOBALS['__fail'] . " failed\n";
    exit($GLOBALS['__fail'] === 0 ? 0 : 1);
}
