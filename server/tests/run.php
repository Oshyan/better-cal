<?php

declare(strict_types=1);

// Pure-PHP tests, no database and no composer deps required:
//   php server/tests/run.php

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Dav\ChangeLog;
use BetterCal\Dav\DavIcs;
use BetterCal\Domain\ApiTokens;
use BetterCal\Domain\Calendars;
use BetterCal\Domain\FallbackParser;
use BetterCal\Domain\Geocode;
use BetterCal\Domain\Filters;
use BetterCal\Domain\Ics;
use BetterCal\Domain\PromptEval;
use BetterCal\Domain\QuickAdd;
use BetterCal\Domain\Ranking;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Settings;
use BetterCal\Http\HttpError;
use BetterCal\Http\Router;
use BetterCal\Infra\LlmGateway;
use BetterCal\Infra\LlmTransport;
use BetterCal\Support\Ids;
use BetterCal\Support\Time;

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    if ($cond) {
        $GLOBALS['__pass']++;
        return;
    }
    $GLOBALS['__fail']++;
    echo "FAIL: $name" . ($detail !== '' ? " — $detail" : '') . "\n";
}

function checkEq(string $name, mixed $expected, mixed $actual): void
{
    check($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

// ---------------------------------------------------------------------------
// Time helpers
// ---------------------------------------------------------------------------

checkEq('parseIso with offset to db', '2026-07-30 17:00:00', Time::toDb(Time::parseIso('2026-07-30T10:00:00-07:00')));
checkEq('parseIso naive assumes tz', '2026-07-30 17:00:00', Time::toDb(Time::parseIso('2026-07-30T10:00:00', 'America/Los_Angeles')));
checkEq('tz alias PST normalizes', 'America/Los_Angeles', Time::normalizeTzid('PST'));
checkEq('tz alias EDT normalizes', 'America/New_York', Time::normalizeTzid('EDT'));
checkEq('bad tz falls back to UTC', 'UTC', Time::normalizeTzid('Mars/Olympus_Mons'));
checkEq('dbToIso renders offset', '2026-07-30T10:00:00-07:00', Time::dbToIso('2026-07-30 17:00:00', 'America/Los_Angeles'));

// ---------------------------------------------------------------------------
// FallbackParser (fixed now: Thursday 2026-07-30 10:00 America/Los_Angeles)
// ---------------------------------------------------------------------------

$tz = 'America/Los_Angeles';
$now = new DateTimeImmutable('2026-07-30T10:00:00', new DateTimeZone($tz));
$p = static fn(string $text): array => FallbackParser::parse($text, $tz, $now);

$d = $p('Dinner with Sam next thursday 7pm at Zuni');
checkEq('fp1 title', 'Dinner', $d['title']);
checkEq('fp1 start', '2026-08-06T19:00:00-07:00', $d['start']);
checkEq('fp1 end', '2026-08-06T20:00:00-07:00', $d['end']);
checkEq('fp1 location', 'Zuni', $d['location']);
checkEq('fp1 people', ['Sam'], $d['personNames']);
checkEq('fp1 source', 'fallback', $d['source']);
check('fp1 confidence in range', $d['confidence'] > 0.5 && $d['confidence'] <= 0.95);

$d = $p('Meeting tomorrow 9am');
checkEq('fp2 start', '2026-07-31T09:00:00-07:00', $d['start']);
checkEq('fp2 title', 'Meeting', $d['title']);
checkEq('fp2 allDay', false, $d['allDay']);

$d = $p('Call 7/31 3pm');
checkEq('fp3 start', '2026-07-31T15:00:00-07:00', $d['start']);

$d = $p('Party July 31 8pm');
checkEq('fp4 start', '2026-07-31T20:00:00-07:00', $d['start']);
checkEq('fp4 title', 'Party', $d['title']);

$d = $p('Trip 2026-08-15');
checkEq('fp5 allDay', true, $d['allDay']);
checkEq('fp5 start', '2026-08-15T00:00:00-07:00', $d['start']);
checkEq('fp5 end', '2026-08-16T00:00:00-07:00', $d['end']);

$d = $p('Gym 7-9pm');
checkEq('fp6 start', '2026-07-30T19:00:00-07:00', $d['start']);
checkEq('fp6 end', '2026-07-30T21:00:00-07:00', $d['end']);

$d = $p('Standup 9:30am for 15 minutes');
checkEq('fp7 start rolls to tomorrow', '2026-07-31T09:30:00-07:00', $d['start']);
checkEq('fp7 end', '2026-07-31T09:45:00-07:00', $d['end']);

$d = $p('Review friday 19:00');
checkEq('fp8 start', '2026-07-31T19:00:00-07:00', $d['start']);

$d = $p('Movie 19:00-21:30');
checkEq('fp9 start', '2026-07-30T19:00:00-07:00', $d['start']);
checkEq('fp9 end', '2026-07-30T21:30:00-07:00', $d['end']);

$d = $p('Dentist tomorrow at 2pm for 2 hours');
checkEq('fp10 start', '2026-07-31T14:00:00-07:00', $d['start']);
checkEq('fp10 end', '2026-07-31T16:00:00-07:00', $d['end']);
checkEq('fp10 no location', null, $d['location']);

$d = $p('Coffee 8am');
checkEq('fp11 past time rolls to tomorrow', '2026-07-31T08:00:00-07:00', $d['start']);

$d = $p('Lunch 1/15 12:00');
checkEq('fp12 rolls to next year', '2027-01-15T12:00:00-08:00', $d['start']);

$d = $p('Brainstorm');
checkEq('fp13 next round hour', '2026-07-30T11:00:00-07:00', $d['start']);
checkEq('fp13 end', '2026-07-30T12:00:00-07:00', $d['end']);
checkEq('fp13 title', 'Brainstorm', $d['title']);

$d = $p('Hike saturday');
checkEq('fp14 allDay weekday', true, $d['allDay']);
checkEq('fp14 start', '2026-08-01T00:00:00-07:00', $d['start']);

$d = $p('Sync this thursday 4pm');
checkEq('fp15 this thursday is today', '2026-07-30T16:00:00-07:00', $d['start']);

// Date ranges → multi-day all-day events (end exclusive).
$d = $p('Conference June 1-12');
checkEq('fp16 range rolls to next year', '2027-06-01T00:00:00-07:00', $d['start']);
checkEq('fp16 range end exclusive', '2027-06-13T00:00:00-07:00', $d['end']);
checkEq('fp16 range allDay', true, $d['allDay']);
checkEq('fp16 range title', 'Conference', $d['title']);
checkEq('fp16 range complete', true, $d['complete']);

$d = $p('Retreat Aug 3 to Aug 7');
checkEq('fp17 month-to-month range start', '2026-08-03T00:00:00-07:00', $d['start']);
checkEq('fp17 month-to-month range end', '2026-08-08T00:00:00-07:00', $d['end']);

$d = $p('Festival Sep 4-6, 2027');
checkEq('fp18 range explicit year start', '2027-09-04T00:00:00-07:00', $d['start']);
checkEq('fp18 range explicit year end', '2027-09-07T00:00:00-07:00', $d['end']);

$d = $p('Trip Dec 30 to Jan 2');
checkEq('fp19 cross-year range start', '2026-12-30T00:00:00-08:00', $d['start']);
checkEq('fp19 cross-year range end', '2027-01-03T00:00:00-08:00', $d['end']);

$d = $p('Vacation July 20 through July 24');
checkEq('fp20 through range rolls forward', '2027-07-20T00:00:00-07:00', $d['start']);
checkEq('fp20 through range end', '2027-07-25T00:00:00-07:00', $d['end']);

$d = $p('Demo June 1 to 5pm');
checkEq('fp21 time tail is not a range', '2027-06-01T17:00:00-07:00', $d['start']);
checkEq('fp21 time tail not allDay', false, $d['allDay']);

// noon / midnight
$d = $p('Lunch tomorrow at noon');
checkEq('fp22 noon', '2026-07-31T12:00:00-07:00', $d['start']);
checkEq('fp22 noon title', 'Lunch', $d['title']);

$d = $p('Call at midnight');
checkEq('fp23 midnight rolls to tomorrow', '2026-07-31T00:00:00-07:00', $d['start']);
checkEq('fp23 midnight incomplete without date', false, $d['complete']);

$d = $p('Coffee noon');
checkEq('fp24 bare noon later today', '2026-07-30T12:00:00-07:00', $d['start']);

// "next week <weekday>" = that weekday within the next calendar week (Mon start).
$d = $p('Standup next week monday 9am');
checkEq('fp25 next week monday', '2026-08-03T09:00:00-07:00', $d['start']);
checkEq('fp25 title', 'Standup', $d['title']);

$d = $p('Review next week friday');
checkEq('fp26 next week friday', '2026-08-07T00:00:00-07:00', $d['start']);
checkEq('fp26 allDay', true, $d['allDay']);

$d = $p('Brunch next week sunday');
checkEq('fp27 next week sunday ends the week', '2026-08-09T00:00:00-07:00', $d['start']);

// "all day" keyword
$d = $p('Conference tomorrow all day');
checkEq('fp28 all day keyword', true, $d['allDay']);
checkEq('fp28 all day start', '2026-07-31T00:00:00-07:00', $d['start']);
checkEq('fp28 all day title', 'Conference', $d['title']);
checkEq('fp28 all day complete', true, $d['complete']);

$d = $p('Focus block all day');
checkEq('fp29 all day without date is today', '2026-07-30T00:00:00-07:00', $d['start']);
checkEq('fp29 all day without date incomplete', false, $d['complete']);

$d = $p('Hike all-day saturday');
checkEq('fp30 hyphenated all-day', true, $d['allDay']);
checkEq('fp30 hyphenated all-day start', '2026-08-01T00:00:00-07:00', $d['start']);
checkEq('fp30 hyphenated all-day title', 'Hike', $d['title']);

// People lists with commas.
$d = $p('Dinner with Sam, Alex and Pat tomorrow 7pm');
checkEq('fp31 comma people list', ['Sam', 'Alex', 'Pat'], $d['personNames']);
checkEq('fp31 title', 'Dinner', $d['title']);
checkEq('fp31 start', '2026-07-31T19:00:00-07:00', $d['start']);

$d = $p('tomorrow 3pm');
checkEq('fp32 untitled placeholder', 'New event', $d['title']);
check('fp32 untitled stays below llm-skip threshold', $d['confidence'] < QuickAdd::FALLBACK_CONFIDENCE);

// Completeness + confidence gates feeding the QuickAdd LLM-skip decision.
checkEq('fp complete date+time', true, $p('Meeting tomorrow 9am')['complete']);
checkEq('fp complete date-only allDay', true, $p('Trip 2026-08-15')['complete']);
checkEq('fp incomplete time-only', false, $p('Coffee 8am')['complete']);
checkEq('fp incomplete bare title', false, $p('Brainstorm')['complete']);
check('fp date+time clears threshold', $p('Meeting tomorrow 9am')['confidence'] >= QuickAdd::FALLBACK_CONFIDENCE);
check('fp date-only clears threshold', $p('Trip 2026-08-15')['confidence'] >= QuickAdd::FALLBACK_CONFIDENCE);
check('fp time-only below threshold', $p('Coffee 8am')['confidence'] < QuickAdd::FALLBACK_CONFIDENCE);

// ---------------------------------------------------------------------------
// QuickAdd::useLlm — nlParseMode gating (pure, no DB, no LLM)
// ---------------------------------------------------------------------------

$completeParse = ['complete' => true, 'confidence' => 0.8];
checkEq('qa never skips llm', false, QuickAdd::useLlm('never', ['complete' => false, 'confidence' => 0.1]));
checkEq('qa always calls llm even when complete', true, QuickAdd::useLlm('always', $completeParse));
checkEq('qa smart skips llm on complete confident parse', false, QuickAdd::useLlm('smart', $completeParse));
checkEq('qa smart calls llm below threshold', true, QuickAdd::useLlm('smart', ['complete' => true, 'confidence' => 0.7]));
checkEq('qa smart threshold boundary skips', false, QuickAdd::useLlm('smart', ['complete' => true, 'confidence' => 0.75]));
checkEq('qa smart calls llm on incomplete parse', true, QuickAdd::useLlm('smart', ['complete' => false, 'confidence' => 0.9]));
checkEq('qa smart integrates with parser (complete)', false, QuickAdd::useLlm('smart', $p('Meeting tomorrow 9am')));
checkEq('qa smart integrates with parser (incomplete)', true, QuickAdd::useLlm('smart', $p('Brainstorm')));

// ---------------------------------------------------------------------------
// ICS escaping and folding
// ---------------------------------------------------------------------------

$raw = "Comma, semi;colon\\ and\nnewline";
checkEq('ics escape', 'Comma\\, semi\\;colon\\\\ and\\nnewline', Ics::escape($raw));
checkEq('ics escape/unescape round trip', $raw, Ics::unescape(Ics::escape($raw)));
checkEq('ics crlf normalized', "a\nb", Ics::unescape(Ics::escape("a\r\nb")));

$longLine = 'SUMMARY:' . str_repeat('é', 120) . str_repeat('x', 40);
$folded = Ics::fold($longLine);
checkEq('ics fold/unfold round trip', $longLine, Ics::unfold($folded));
$maxLen = 0;
$validUtf8 = true;
foreach (explode("\r\n", $folded) as $line) {
    $maxLen = max($maxLen, strlen($line));
    $validUtf8 = $validUtf8 && mb_check_encoding(ltrim($line, ' '), 'UTF-8');
}
check('ics folded lines <= 75 octets', $maxLen <= 75, "max was $maxLen");
check('ics fold never splits utf8', $validUtf8);
checkEq('ics short line not folded', 'SUMMARY:short', Ics::fold('SUMMARY:short'));

$calendar = Ics::buildCalendar('My Feed', 'This feed contains only events matching: yoga', [
    [
        'uid' => 'abc-123', 'title' => 'Yoga, advanced; session', 'description' => "Line1\nLine2",
        'location' => 'Studio A', 'url' => 'https://example.com/e/1',
        'start_utc' => '2026-08-01 17:00:00', 'end_utc' => '2026-08-01 18:00:00',
        'all_day' => 0, 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;BYDAY=SA',
        'exdates_json' => json_encode(['2026-08-08 17:00:00']), 'status' => 'confirmed',
    ],
    [
        'uid' => 'def-456', 'title' => 'All day thing', 'start_utc' => '2026-08-02 07:00:00',
        'end_utc' => '2026-08-03 07:00:00', 'all_day' => 1, 'tzid' => 'America/Los_Angeles', 'status' => 'confirmed',
    ],
]);
check('vcal has calname', str_contains($calendar, 'X-WR-CALNAME:My Feed'));
check('vcal has caldesc scope', str_contains(Ics::unfold($calendar), 'X-WR-CALDESC:This feed contains only events matching: yoga'));
checkEq('vcal has two vevents', 2, substr_count($calendar, 'BEGIN:VEVENT'));
check('vcal escapes summary', str_contains($calendar, 'SUMMARY:Yoga\\, advanced\\; session'));
check('vcal escapes newline in description', str_contains($calendar, 'DESCRIPTION:Line1\\nLine2'));
check('vcal rrule not expanded', str_contains($calendar, 'RRULE:FREQ=WEEKLY;BYDAY=SA'));
check('vcal exdate exported', str_contains($calendar, 'EXDATE:20260808T170000Z'));
check('vcal all-day uses DATE value', str_contains($calendar, 'DTSTART;VALUE=DATE:20260802'));
check('vcal ends properly', str_ends_with($calendar, "END:VCALENDAR\r\n"));
$over = 0;
foreach (explode("\r\n", $calendar) as $line) {
    if (strlen($line) > 75) {
        $over++;
    }
}
checkEq('vcal every line <= 75 octets', 0, $over);

// ---------------------------------------------------------------------------
// Router dispatch
// ---------------------------------------------------------------------------

$router = new Router();
$router->add('GET', '/api/v1/events', fn() => 'window');
$router->add('POST', '/api/v1/events', fn() => 'create');
$router->add('PATCH', '/api/v1/events/:id', fn() => 'patch');
$router->add('POST', '/api/v1/calendars/subscribe', fn() => 'subscribe');
$router->add('POST', '/api/v1/calendars/:id/refresh', fn() => 'refresh');

$m = $router->match('GET', '/api/v1/events');
checkEq('router literal match', 'window', ($m['handler'])());
$m = $router->match('PATCH', '/api/v1/events/42');
checkEq('router param captured', '42', $m['params']['id']);
$m = $router->match('POST', '/api/v1/calendars/subscribe');
checkEq('router literal beats param', 'subscribe', ($m['handler'])());
$m = $router->match('POST', '/api/v1/calendars/7/refresh');
checkEq('router nested param', '7', $m['params']['id']);
$m = $router->match('HEAD', '/api/v1/events');
checkEq('router HEAD falls back to GET', 'window', ($m['handler'])());

try {
    $router->match('GET', '/api/v1/nope');
    check('router 404', false);
} catch (HttpError $e) {
    checkEq('router 404 status', 404, $e->status);
}
try {
    $router->match('DELETE', '/api/v1/events');
    check('router 405', false);
} catch (HttpError $e) {
    checkEq('router 405 status', 405, $e->status);
    checkEq('router 405 code', 'method_not_allowed', $e->errorCode);
}

// ---------------------------------------------------------------------------
// Recurrence window math (mocked expander, no DB, no sabre)
// ---------------------------------------------------------------------------

$weeklyExpander = static function (array $master, DateTimeImmutable $winStart, DateTimeImmutable $winEnd): array {
    $out = [];
    $start = Time::fromDb((string) $master['start_utc']);
    $duration = Time::fromDb((string) $master['end_utc'])->getTimestamp() - $start->getTimestamp();
    for ($i = 0; $i < 200; $i++) {
        $s = $start->add(new DateInterval('P' . ($i * 7) . 'D'));
        if ($s >= $winEnd) {
            break;
        }
        if ($s->add(new DateInterval('PT' . $duration . 'S')) > $winStart) {
            $out[] = ['start' => $s, 'end' => $s->add(new DateInterval('PT' . $duration . 'S'))];
        }
    }
    return $out;
};

$rec = new Recurrence($weeklyExpander);
$master = [
    'id' => 10, 'uid' => 'u1', 'title' => 'Weekly',
    'start_utc' => '2026-01-05 18:00:00', 'end_utc' => '2026-01-05 19:00:00',
    'rrule' => 'FREQ=WEEKLY', 'all_day' => 0, 'tzid' => 'UTC',
    'exdates_json' => json_encode(['2026-01-12 18:00:00']),
];
$override = [
    'id' => 11, 'uid' => 'u1', 'title' => 'Weekly (moved)',
    'start_utc' => '2026-01-20 18:00:00', 'end_utc' => '2026-01-20 19:00:00',
    'recurrence_parent_id' => 10, 'recurrence_instance_utc' => '2026-01-19 18:00:00',
    'all_day' => 0, 'tzid' => 'UTC',
];
$win = [Time::fromDb('2026-01-01 00:00:00'), Time::fromDb('2026-02-01 00:00:00')];
$occs = $rec->expand($master, [$override], $win[0], $win[1]);

checkEq('recur count (4 wks, 1 exdate ok, 1 override)', 3, count($occs));
$instances = array_map(static fn($o) => $o['instanceUtc'], $occs);
check('recur emits first instance', in_array('2026-01-05 18:00:00', $instances, true));
check('recur skips exdate', !in_array('2026-01-12 18:00:00', $instances, true));
check('recur last instance present', in_array('2026-01-26 18:00:00', $instances, true));
$moved = null;
foreach ($occs as $o) {
    if ($o['instanceUtc'] === '2026-01-19 18:00:00') {
        $moved = $o;
    }
}
check('recur override replaces instance', $moved !== null);
checkEq('recur override row used', 11, $moved !== null ? (int) $moved['row']['id'] : null);
checkEq('recur override moved start', '2026-01-20 18:00:00', $moved !== null ? Time::toDb($moved['start']) : null);

// Override moved into the window from an instance the expander never emitted.
$strayOverride = [
    'id' => 12, 'uid' => 'u1', 'title' => 'Stray',
    'start_utc' => '2026-01-28 09:00:00', 'end_utc' => '2026-01-28 10:00:00',
    'recurrence_parent_id' => 10, 'recurrence_instance_utc' => '2026-03-02 18:00:00',
    'all_day' => 0, 'tzid' => 'UTC',
];
$occs = $rec->expand($master, [$strayOverride], $win[0], $win[1]);
check('recur stray override appended', in_array('2026-03-02 18:00:00', array_map(static fn($o) => $o['instanceUtc'], $occs), true));

// Cap at 500 instances.
$floodExpander = static function (array $master): array {
    $out = [];
    $s = Time::fromDb((string) $master['start_utc']);
    for ($i = 0; $i < 600; $i++) {
        $out[] = ['start' => $s->add(new DateInterval('PT' . $i . 'H')), 'end' => $s->add(new DateInterval('PT' . ($i + 1) . 'H'))];
    }
    return $out;
};
$capRec = new Recurrence($floodExpander);
$capMaster = $master;
$capMaster['exdates_json'] = null;
$occs = $capRec->expand($capMaster, [], Time::fromDb('2026-01-01 00:00:00'), Time::fromDb('2027-01-01 00:00:00'));
checkEq('recur capped at 500 instances', 500, count($occs));

// Non-recurring passthrough.
$single = ['id' => 20, 'uid' => 'u2', 'title' => 'Once', 'start_utc' => '2026-01-10 01:00:00', 'end_utc' => '2026-01-10 02:00:00', 'rrule' => null, 'all_day' => 0, 'tzid' => 'UTC'];
$occs = $rec->expand($single, [], $win[0], $win[1]);
checkEq('non-recurring emits one occurrence', 1, count($occs));
$occs = $rec->expand($single, [], Time::fromDb('2027-01-01 00:00:00'), Time::fromDb('2027-02-01 00:00:00'));
checkEq('non-recurring outside window emits none', 0, count($occs));

// Instance id contract (frozen format).
checkEq('instanceId format', '10:20260105T180000Z', Recurrence::instanceId(10, Time::fromDb('2026-01-05 18:00:00')));

// RRULE helpers.
checkEq('setUntil replaces COUNT', 'FREQ=WEEKLY;UNTIL=20260201T000000Z', Recurrence::setUntil('FREQ=WEEKLY;COUNT=10', Time::fromDb('2026-02-01 00:00:00'), false));
checkEq('setUntil all-day uses DATE', 'FREQ=DAILY;UNTIL=20260201', Recurrence::setUntil('FREQ=DAILY', Time::fromDb('2026-02-01 00:00:00'), true));
checkEq('splitUntil is instance minus 1s', '2026-01-18 17:59:59', Time::toDb(Recurrence::splitUntil(Time::fromDb('2026-01-18 18:00:00'))));
checkEq('validateRrule normalizes case', 'FREQ=WEEKLY;BYDAY=MO,WE', Recurrence::validateRrule('freq=weekly;byday=mo,we'));
foreach (['', 'FOO=BAR', 'FREQ=SOMETIMES', 'FREQ=WEEKLY;INTERVAL=0', 'FREQ=WEEKLY;NOPE=1', 'FREQ'] as $bad) {
    try {
        Recurrence::validateRrule($bad);
        check("validateRrule rejects '$bad'", false);
    } catch (HttpError $e) {
        checkEq("validateRrule '$bad' error code", 'invalid_rrule', $e->errorCode);
    }
}

// ---------------------------------------------------------------------------
// Filters: pure matching (Filters::evaluate / Filters::disposition, no DB)
// ---------------------------------------------------------------------------

$occ = [
    'calendar_id' => 3,
    'title' => 'Yoga Class at the Studio',
    'description' => "Vinyasa flow.\nBring a mat and water.",
    'location' => 'Mission Cultural Center',
];
$kw = static fn(string $pattern, ?array $fields = null): array => [
    'type' => 'keyword',
    'config' => $fields === null ? ['pattern' => $pattern] : ['pattern' => $pattern, 'fields' => $fields],
];
$rx = static fn(string $pattern, ?array $fields = null): array => [
    'type' => 'regex',
    'config' => $fields === null ? ['pattern' => $pattern] : ['pattern' => $pattern, 'fields' => $fields],
];

check('flt keyword case-insensitive substring', Filters::evaluate($occ, $kw('yoga')));
check('flt keyword matches mid-word', Filters::evaluate($occ, $kw('ULTUR')));
check('flt keyword no match', !Filters::evaluate($occ, $kw('pottery')));
check('flt keyword field selection excludes', !Filters::evaluate($occ, $kw('mat and water', ['title'])));
check('flt keyword description-only field matches', Filters::evaluate($occ, $kw('mat and water', ['description'])));
check('flt keyword location field matches', Filters::evaluate($occ, $kw('mission', ['location'])));
checkEq('flt keyword unknown field names never match', false, Filters::evaluate($occ, $kw('yoga', ['url'])));
check('flt keyword empty pattern never matches', !Filters::evaluate($occ, $kw('')));
check('flt keyword unicode case fold', Filters::evaluate(['title' => 'CAFÉ night'], $kw('café')));
check('flt missing fields treated as empty', !Filters::evaluate(['title' => 'Solo'], $kw('anything', ['description'])));
check('flt regex basic match', Filters::evaluate($occ, $rx('yo+ga')));
check('flt regex case-insensitive', Filters::evaluate($occ, $rx('^yoga')));
check('flt regex anchor no match', !Filters::evaluate($occ, $rx('^studio')));
check('flt regex alternation on location', Filters::evaluate($occ, $rx('cultural|jazz', ['location'])));
check('flt regex field selection excludes', !Filters::evaluate($occ, $rx('vinyasa', ['title', 'location'])));
check('flt regex multiline value', Filters::evaluate($occ, $rx('bring a mat', ['description'])));
check('flt invalid regex never matches', !Filters::evaluate($occ, $rx('([unclosed')));

// validateConfig: regex validation and field normalization.
try {
    Filters::validateConfig('regex', ['pattern' => '([unclosed']);
    check('flt invalid regex rejected', false);
} catch (HttpError $e) {
    checkEq('flt invalid regex error code', 'filter_invalid_regex', $e->errorCode);
}
checkEq('flt valid regex accepted', ['pattern' => 'a|b', 'fields' => ['title', 'description', 'location']], Filters::validateConfig('regex', ['pattern' => 'a|b']));
checkEq('flt fields normalized to known order', ['title', 'location'], Filters::validateConfig('keyword', ['pattern' => 'x', 'fields' => ['location', 'title']])['fields']);
try {
    Filters::validateConfig('keyword', ['pattern' => '   ']);
    check('flt blank pattern rejected', false);
} catch (HttpError $e) {
    checkEq('flt blank pattern status', 400, $e->status);
}

// disposition: calendar scoping and hide-beats-dim.
$hideAll = ['type' => 'keyword', 'config' => ['pattern' => 'yoga'], 'action' => 'hide', 'calendarIds' => null];
$dimAll = ['type' => 'keyword', 'config' => ['pattern' => 'yoga'], 'action' => 'dim', 'calendarIds' => null];
$hideCal9 = ['type' => 'keyword', 'config' => ['pattern' => 'yoga'], 'action' => 'hide', 'calendarIds' => [9 => true]];
checkEq('flt disposition global hide', 'hide', Filters::disposition($occ, [$hideAll]));
checkEq('flt disposition global dim', 'dim', Filters::disposition($occ, [$dimAll]));
checkEq('flt disposition hide beats dim', 'hide', Filters::disposition($occ, [$dimAll, $hideAll]));
checkEq('flt disposition other calendar skipped', null, Filters::disposition($occ, [$hideCal9]));
checkEq('flt disposition scoped calendar applies', 'hide', Filters::disposition(['calendar_id' => 9] + $occ, [$hideCal9]));
checkEq('flt disposition no filters', null, Filters::disposition($occ, []));

// ---------------------------------------------------------------------------
// Prompt filters: config validation, batching, response validation, verdicts,
// disposition precedence (pure, no DB, no LLM)
// ---------------------------------------------------------------------------

$pfConfig = Filters::validateConfig('prompt', [
    'prompt' => '  dance and live music events, small venues ',
    'negativePrompt' => ' no webinars ',
    'threshold' => '0.6',
]);
checkEq('pf config prompt trimmed', 'dance and live music events, small venues', $pfConfig['prompt']);
checkEq('pf config negative trimmed', 'no webinars', $pfConfig['negativePrompt']);
checkEq('pf config threshold cast to float', 0.6, $pfConfig['threshold']);
checkEq('pf config omits empty negative', false, array_key_exists('negativePrompt', Filters::validateConfig('prompt', ['prompt' => 'x'])));
checkEq('pf config omits absent threshold', false, array_key_exists('threshold', Filters::validateConfig('prompt', ['prompt' => 'x'])));
try {
    Filters::validateConfig('prompt', ['prompt' => '   ']);
    check('pf blank prompt rejected', false);
} catch (HttpError $e) {
    checkEq('pf blank prompt status', 400, $e->status);
}
try {
    Filters::validateConfig('prompt', ['prompt' => 'x', 'threshold' => 1.5]);
    check('pf out-of-range threshold rejected', false);
} catch (HttpError $e) {
    checkEq('pf threshold range status', 400, $e->status);
}
try {
    Filters::validateConfig('prompt', ['prompt' => 'x', 'threshold' => 'high']);
    check('pf non-numeric threshold rejected', false);
} catch (HttpError $e) {
    checkEq('pf threshold type status', 400, $e->status);
}

// Batching math: 25 events per LLM call.
checkEq('pe batches 60 -> 25/25/10', [25, 25, 10], array_map('count', PromptEval::batches(range(1, 60))));
checkEq('pe batches exact multiple', [25, 25], array_map('count', PromptEval::batches(range(1, 50))));
checkEq('pe batches small input single batch', [3], array_map('count', PromptEval::batches([1, 2, 3])));
checkEq('pe batches empty', [], PromptEval::batches([]));

// Eval response validation: clamping, unknown ids, malformed entries.
$evalOut = PromptEval::validateEvalResponse(['results' => [
    ['eventId' => 1, 'pass' => true, 'score' => 1.7],
    ['eventId' => 2, 'pass' => false, 'score' => -0.25],
    ['eventId' => 3, 'pass' => true],
    ['eventId' => 99, 'pass' => true, 'score' => 0.5],
    ['eventId' => 4, 'pass' => 'yes', 'score' => 0.5],
    'garbage',
]], [1, 2, 3, 4]);
checkEq('pe response clamps high score to 1', 1.0, $evalOut[1]['score']);
checkEq('pe response clamps low score to 0', 0.0, $evalOut[2]['score']);
checkEq('pe response missing score is null', null, $evalOut[3]['score']);
checkEq('pe response keeps pass flags', [true, false], [$evalOut[1]['pass'], $evalOut[2]['pass']]);
checkEq('pe response drops unknown event id', false, array_key_exists(99, $evalOut));
checkEq('pe response drops non-bool pass', false, array_key_exists(4, $evalOut));
checkEq('pe response garbage payload -> empty', [], PromptEval::validateEvalResponse('garbage', [1]));
checkEq('pe response missing results -> empty', [], PromptEval::validateEvalResponse(['nope' => []], [1]));

// Verdicts: threshold overrides the boolean when a score exists.
checkEq('pe verdict pass bool', 'pass', PromptEval::verdictFor(['pass' => true, 'score' => 0.2], null));
checkEq('pe verdict fail bool', 'fail', PromptEval::verdictFor(['pass' => false, 'score' => 0.9], null));
checkEq('pe verdict threshold promotes', 'pass', PromptEval::verdictFor(['pass' => false, 'score' => 0.9], 0.5));
checkEq('pe verdict threshold demotes', 'fail', PromptEval::verdictFor(['pass' => true, 'score' => 0.3], 0.5));
checkEq('pe verdict threshold equal passes', 'pass', PromptEval::verdictFor(['pass' => false, 'score' => 0.5], 0.5));
checkEq('pe verdict null score falls back to bool', 'pass', PromptEval::verdictFor(['pass' => true, 'score' => null], 0.5));

// eventPayload: compact shape, description excerpt.
$payload = PromptEval::eventPayload([
    'id' => 7, 'title' => 'Salsa Night', 'description' => str_repeat('x', 400),
    'location' => 'El Valenciano', 'start_utc' => '2026-08-02 02:00:00', 'tzid' => 'America/Los_Angeles',
]);
checkEq('pe payload event id', 7, $payload['eventId']);
checkEq('pe payload local start', '2026-08-01T19:00:00-07:00', $payload['start']);
checkEq('pe payload description excerpted', 301, mb_strlen($payload['description']));

// Prompt dispositions from cached verdicts + precedence with keyword filters.
$promptRow = ['id' => 101, 'calendar_id' => 3] + $occ;
$pfHide = ['id' => 1, 'action' => 'hide', 'calendarIds' => null];
$pfDim = ['id' => 2, 'action' => 'dim', 'calendarIds' => null];
$pfCal9Hide = ['id' => 3, 'action' => 'hide', 'calendarIds' => [9 => true]];
$failAll = [1 => [101 => true], 2 => [101 => true], 3 => [101 => true]];
checkEq('pd fail -> hide', 'hide', Filters::promptDisposition($promptRow, [$pfHide], $failAll));
checkEq('pd fail -> dim', 'dim', Filters::promptDisposition($promptRow, [$pfDim], $failAll));
checkEq('pd no verdict treated as pass', null, Filters::promptDisposition($promptRow, [$pfHide, $pfDim], []));
checkEq('pd out-of-scope calendar skipped', null, Filters::promptDisposition($promptRow, [$pfCal9Hide], $failAll));
checkEq('pd scoped calendar applies', 'hide', Filters::promptDisposition(['calendar_id' => 9] + $promptRow, [$pfCal9Hide], $failAll));
checkEq('pd hide beats dim', 'hide', Filters::promptDisposition($promptRow, [$pfDim, $pfHide], $failAll));
checkEq('strongest hide wins', 'hide', Filters::strongest('dim', 'hide'));
checkEq('strongest dim over null', 'dim', Filters::strongest(null, 'dim'));
checkEq('strongest all null', null, Filters::strongest(null, null));
checkEq(
    'mixed keyword dim + prompt hide -> hide',
    'hide',
    Filters::strongest(
        Filters::disposition($promptRow, [$dimAll]),
        Filters::promptDisposition($promptRow, [$pfHide], $failAll)
    )
);
checkEq(
    'mixed keyword miss + prompt dim -> dim',
    'dim',
    Filters::strongest(
        Filters::disposition(['title' => 'Pottery class', 'calendar_id' => 3, 'id' => 101], [$dimAll]),
        Filters::promptDisposition(['title' => 'Pottery class', 'calendar_id' => 3, 'id' => 101], [$pfDim], $failAll)
    )
);

// On-read healing job identity: stable hash over the sorted unique id set.
checkEq('flt eval hash order-insensitive', Filters::evalPayloadHash([3, 1, 2]), Filters::evalPayloadHash([1, 2, 3, 3]));
check('flt eval hash differs for different sets', Filters::evalPayloadHash([1, 2]) !== Filters::evalPayloadHash([1, 3]));
checkEq('flt eval hash is sha256 of joined ids', hash('sha256', '1,2,3'), Filters::evalPayloadHash([2, '3', 1]));
checkEq('flt on-read enqueue cap', 300, Filters::MAX_ON_READ_EVENT_IDS);
checkEq('pe sweep window years', [2, 3], [PromptEval::WINDOW_YEARS_PAST, PromptEval::WINDOW_YEARS_FUTURE]);

// ---------------------------------------------------------------------------
// Geocode: normalization, hashing, response mapping, negative cache (pure)
// ---------------------------------------------------------------------------

checkEq('geo normalize trims + collapses whitespace', 'Zuni Cafe, San Francisco', Geocode::normalize("  Zuni   Cafe,\n San Francisco  "));
checkEq('geo normalize caps length', Geocode::MAX_QUERY_LENGTH, mb_strlen(Geocode::normalize(str_repeat('a', 600))));
checkEq('geo hash whitespace-insensitive', Geocode::queryHash('Zuni  Cafe'), Geocode::queryHash(' Zuni Cafe '));
checkEq('geo hash case-insensitive', Geocode::queryHash('ZUNI CAFE'), Geocode::queryHash('zuni cafe'));
check('geo hash differs for different queries', Geocode::queryHash('Zuni Cafe') !== Geocode::queryHash('Tartine'));
check('geo hash is 64 hex chars', preg_match('/^[0-9a-f]{64}$/', Geocode::queryHash('anything')) === 1);

$photon = ['features' => [[
    'geometry' => ['coordinates' => [-122.4216, 37.7739]],
    'properties' => ['name' => 'Zuni Cafe', 'city' => 'San Francisco', 'state' => 'California', 'country' => 'United States'],
]]];
$geo = Geocode::mapResponse($photon);
checkEq('geo map lat from GeoJSON [lng,lat]', 37.7739, $geo['lat']);
checkEq('geo map lng from GeoJSON [lng,lat]', -122.4216, $geo['lng']);
checkEq('geo map display joins parts', 'Zuni Cafe, San Francisco, California, United States', $geo['display']);
checkEq('geo map dedupes repeated parts', 'Berlin, Germany', Geocode::mapResponse(['features' => [[
    'geometry' => ['coordinates' => [13.4, 52.5]],
    'properties' => ['name' => 'Berlin', 'city' => 'Berlin', 'country' => 'Germany'],
]]])['display']);
checkEq('geo map empty features -> null', null, Geocode::mapResponse(['features' => []]));
checkEq('geo map garbage -> null', null, Geocode::mapResponse('garbage'));
checkEq('geo map missing coordinates -> null', null, Geocode::mapResponse(['features' => [['properties' => ['name' => 'X']]]]));
checkEq('geo map non-numeric coordinates -> null', null, Geocode::mapResponse(['features' => [['geometry' => ['coordinates' => ['a', 'b']]]]]));

checkEq('geo negative cache row -> all-null result', ['lat' => null, 'lng' => null, 'display' => null], Geocode::resultFromRow(['lat' => null, 'lng' => null, 'display' => null]));
checkEq(
    'geo positive cache row round-trips',
    ['lat' => 37.7739, 'lng' => -122.4216, 'display' => 'Zuni Cafe'],
    Geocode::resultFromRow(['lat' => '37.7739', 'lng' => '-122.4216', 'display' => 'Zuni Cafe'])
);

// ---------------------------------------------------------------------------
// Settings: defaults + validation (pure, no DB)
// ---------------------------------------------------------------------------

checkEq('set defaults on empty store', Settings::DEFAULTS, Settings::withDefaults([]));
$merged = Settings::withDefaults(['theme' => 'dark', 'legacyKey' => 1]);
checkEq('set stored value wins', 'dark', $merged['theme']);
checkEq('set unknown stored keys dropped', false, array_key_exists('legacyKey', $merged));
checkEq('set other defaults filled in', 'smart', $merged['nlParseMode']);

checkEq('set validate weekStart', ['weekStart' => 'mon'], Settings::validate(['weekStart' => 'mon']));
checkEq('set validate numeric timeFormat', ['timeFormat' => '24'], Settings::validate(['timeFormat' => 24]));
checkEq('set validate defaultView', ['defaultView' => 'agenda'], Settings::validate(['defaultView' => 'agenda']));
checkEq('set validate nlParseMode', ['nlParseMode' => 'never'], Settings::validate(['nlParseMode' => 'never']));
checkEq('set validate null defaultCalendarId', ['defaultCalendarId' => null], Settings::validate(['defaultCalendarId' => null]));
checkEq('set validate numeric-string defaultCalendarId', ['defaultCalendarId' => 7], Settings::validate(['defaultCalendarId' => '7']));
try {
    Settings::validate(['theme' => 'neon']);
    check('set bad enum rejected', false);
} catch (HttpError $e) {
    checkEq('set bad enum status', 400, $e->status);
}
try {
    Settings::validate(['weekStart' => 'tue']);
    check('set bad weekStart rejected', false);
} catch (HttpError $e) {
    checkEq('set bad weekStart status', 400, $e->status);
}
try {
    Settings::validate(['nope' => 1]);
    check('set unknown key rejected', false);
} catch (HttpError $e) {
    checkEq('set unknown key code', 'unknown_setting', $e->errorCode);
}
try {
    Settings::validate(['defaultCalendarId' => -1]);
    check('set negative calendar id rejected', false);
} catch (HttpError $e) {
    checkEq('set negative calendar id status', 400, $e->status);
}

// ---------------------------------------------------------------------------
// Calendars::groupSimilarFor — per-calendar setting with kind defaults (pure)
// ---------------------------------------------------------------------------

checkEq('cal groupSimilar subscribed default true', true, Calendars::groupSimilarFor(null, 'subscribed'));
checkEq('cal groupSimilar local default false', false, Calendars::groupSimilarFor(null, 'local'));
checkEq('cal groupSimilar stored false wins', false, Calendars::groupSimilarFor('{"groupSimilar":false}', 'subscribed'));
checkEq('cal groupSimilar stored true wins', true, Calendars::groupSimilarFor('{"groupSimilar":true}', 'local'));
checkEq('cal groupSimilar bad json falls back to kind', true, Calendars::groupSimilarFor('not json', 'subscribed'));
checkEq('cal groupSimilar unrelated settings fall back', false, Calendars::groupSimilarFor('{"other":1}', 'local'));

// ---------------------------------------------------------------------------
// Ranking: signal-to-example serialization and score validation (pure)
// ---------------------------------------------------------------------------

$sig = static fn(int $eventId, string $kind, string $title): array => ['event_id' => $eventId, 'kind' => $kind, 'title' => $title];
$examples = Ranking::signalExamples([
    $sig(5, 'up', 'Jazz night'),      // newest signal for event 5
    $sig(4, 'hide', 'Crypto webinar'),
    $sig(5, 'down', 'Jazz night'),    // older signal for event 5: superseded
    $sig(6, 'down', 'Marketing mixer'),
    $sig(7, 'up', '   '),             // blank title: dropped
]);
checkEq('rk examples shape + order', ['title' => 'Jazz night', 'signal' => 'up'], $examples[0]);
checkEq('rk examples dedupe keeps latest signal', 1, count(array_filter($examples, static fn($e) => $e['title'] === 'Jazz night')));
checkEq('rk examples hide folds into down', 'down', $examples[1]['signal']);
checkEq('rk examples blank titles dropped', 3, count($examples));
$manySignals = array_map(static fn(int $i) => $sig($i, 'up', "Event $i"), range(1, 40));
checkEq('rk examples capped at 30', 30, count(Ranking::signalExamples($manySignals)));
checkEq('rk examples custom cap', 2, count(Ranking::signalExamples($manySignals, 2)));

$rankOut = Ranking::validateRankResponse(['results' => [
    ['eventId' => 1, 'score' => 2.0],
    ['eventId' => 2, 'score' => -1],
    ['eventId' => 3, 'score' => 0.42],
    ['eventId' => 99, 'score' => 0.9],
    ['eventId' => 4, 'score' => 'high'],
]], [1, 2, 3, 4]);
checkEq('rk response clamps high', 1.0, $rankOut[1]);
checkEq('rk response clamps low', 0.0, $rankOut[2]);
checkEq('rk response passes in-range', 0.42, $rankOut[3]);
checkEq('rk response drops unknown id', false, array_key_exists(99, $rankOut));
checkEq('rk response drops non-numeric score', false, array_key_exists(4, $rankOut));
checkEq('rk response garbage -> empty', [], Ranking::validateRankResponse(null, [1]));

// ---------------------------------------------------------------------------
// LlmGateway batch methods via a fake transport (no network)
// ---------------------------------------------------------------------------

$fakeTransport = new class implements LlmTransport {
    public ?string $reply = null;
    public array $requests = [];

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): ?string
    {
        $this->requests[] = ['url' => $url, 'body' => $body, 'timeout' => $timeoutSeconds];
        return $this->reply;
    }
};
$envelope = static fn(array $json): string => json_encode([
    'candidates' => [['content' => ['parts' => [['text' => json_encode($json)]]]]],
]);
$gw = new LlmGateway(['gemini' => ['key' => 'test-key', 'model' => 'test-model']], $fakeTransport);
$batchEvent = ['eventId' => 1, 'title' => 'Salsa Night', 'description' => null, 'location' => null, 'start' => '2026-08-01T19:00:00-07:00'];

$fakeTransport->reply = $envelope(['results' => [['eventId' => 1, 'pass' => true, 'score' => 0.8]]]);
checkEq(
    'gw eval batch parses results',
    [['eventId' => 1, 'pass' => true, 'score' => 0.8]],
    $gw->evaluateFilterBatch('dance events', 'no webinars', [$batchEvent])
);
check('gw eval sent prompt to model', str_contains((string) $fakeTransport->requests[0]['body'], 'dance events'));
check('gw eval sent negative prompt', str_contains((string) $fakeTransport->requests[0]['body'], 'no webinars'));

$fakeTransport->reply = $envelope(['results' => [['eventId' => 1, 'score' => 0.4]]]);
checkEq(
    'gw rank parses results',
    [['eventId' => 1, 'score' => 0.4]],
    $gw->rankEvents([['title' => 'Jazz night', 'signal' => 'up']], [$batchEvent])
);
$fakeTransport->reply = $envelope(['nope' => true]);
checkEq('gw eval missing results -> null', null, $gw->evaluateFilterBatch('x', null, [$batchEvent]));
$fakeTransport->reply = null;
checkEq('gw transport failure -> null', null, $gw->evaluateFilterBatch('x', null, [$batchEvent]));
checkEq('gw empty batch short-circuits', null, $gw->evaluateFilterBatch('x', null, []));
$gwUnconfigured = new LlmGateway(['gemini' => ['key' => '', 'model' => 'test-model']], $fakeTransport);
checkEq('gw unconfigured -> null', null, $gwUnconfigured->rankEvents([], [$batchEvent]));

// ---------------------------------------------------------------------------
// Ids
// ---------------------------------------------------------------------------

$ulid = Ids::ulid();
check('ulid is 26 chars', strlen($ulid) === 26);
check('ulid alphabet', preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $ulid) === 1);
check('ulid time prefix non-decreasing', strcmp(substr($ulid, 0, 10), substr(Ids::ulid(), 0, 10)) <= 0);
$token = Ids::feedToken();
check('feed token 43 chars urlsafe', strlen($token) === 43 && preg_match('/^[A-Za-z0-9_-]+$/', $token) === 1);

// ---------------------------------------------------------------------------
// ApiTokens: value format, hashing, Bearer header parsing (pure, no DB)
// ---------------------------------------------------------------------------

$apiToken = ApiTokens::generate();
check('api token is 46 chars', strlen($apiToken) === 46);
check('api token has bc_ prefix', str_starts_with($apiToken, 'bc_'));
check('api token matches format', ApiTokens::isValidFormat($apiToken));
check('api tokens are unique', ApiTokens::generate() !== $apiToken);
checkEq('api token hash is sha256 of full value', hash('sha256', $apiToken), ApiTokens::hashToken($apiToken));
check('api token hash is 64 hex chars', preg_match('/^[0-9a-f]{64}$/', ApiTokens::hashToken($apiToken)) === 1);

check('format rejects missing prefix', !ApiTokens::isValidFormat(substr($apiToken, 3)));
check('format rejects wrong prefix', !ApiTokens::isValidFormat('bx_' . substr($apiToken, 3)));
check('format rejects short token', !ApiTokens::isValidFormat('bc_short'));
check('format rejects long token', !ApiTokens::isValidFormat($apiToken . 'a'));
check('format rejects non-urlsafe chars', !ApiTokens::isValidFormat('bc_' . str_repeat('a', 41) . '+/'));
check('format rejects empty', !ApiTokens::isValidFormat(''));

checkEq('bearer parse', $apiToken, ApiTokens::parseBearer('Bearer ' . $apiToken));
checkEq('bearer parse lowercase scheme', $apiToken, ApiTokens::parseBearer('bearer ' . $apiToken));
checkEq('bearer parse tolerates extra whitespace', $apiToken, ApiTokens::parseBearer('  Bearer   ' . $apiToken . '  '));
checkEq('bearer null header', null, ApiTokens::parseBearer(null));
checkEq('bearer empty header', null, ApiTokens::parseBearer(''));
checkEq('bearer wrong scheme', null, ApiTokens::parseBearer('Basic dXNlcjpwYXNz'));
checkEq('bearer scheme without token', null, ApiTokens::parseBearer('Bearer'));
checkEq('bearer scheme with only spaces', null, ApiTokens::parseBearer('Bearer   '));
checkEq('bearer glued scheme rejected', null, ApiTokens::parseBearer('Bearer' . $apiToken));
checkEq('bearer scheme prefix rejected', null, ApiTokens::parseBearer('BearerX ' . $apiToken));

// ---------------------------------------------------------------------------
// DavIcs: CalDAV mapping helpers (pure, no sabre/dav required)
// ---------------------------------------------------------------------------

checkEq('dav calendar uri', 'cal-7', DavIcs::calendarUri(7));
checkEq('dav calendar id from uri', 7, DavIcs::calendarIdFromUri('cal-7'));
checkEq('dav calendar id rejects prefix', null, DavIcs::calendarIdFromUri('xcal-7'));
checkEq('dav calendar id rejects non-numeric', null, DavIcs::calendarIdFromUri('cal-abc'));
checkEq('dav calendar id rejects leading zero', null, DavIcs::calendarIdFromUri('cal-07'));

checkEq('dav object uri from uid', 'ABC123.ics', DavIcs::objectUri('ABC123'));
checkEq('dav uid from object uri', 'ABC123', DavIcs::uidFromObjectUri('ABC123.ics'));
$ulidUid = Ids::ulid();
checkEq('dav uid/uri round trip', $ulidUid, DavIcs::uidFromObjectUri(DavIcs::objectUri($ulidUid)));
checkEq('dav uid requires .ics suffix', null, DavIcs::uidFromObjectUri('ABC123.txt'));
checkEq('dav uid rejects empty stem', null, DavIcs::uidFromObjectUri('.ics'));
check('dav uid preserves dots in uid', DavIcs::uidFromObjectUri('a.b.ics') === 'a.b');

checkEq('dav etag derivation', md5('2026-07-30 10:00:00:42'), DavIcs::etag('2026-07-30 10:00:00', 42));
check('dav etag changes with updated_at', DavIcs::etag('2026-01-01 00:00:00', 1) !== DavIcs::etag('2026-01-01 00:00:01', 1));
check('dav etag changes with id', DavIcs::etag('2026-01-01 00:00:00', 1) !== DavIcs::etag('2026-01-01 00:00:00', 2));

checkEq('dav change op add', 1, ChangeLog::OP_ADD);
checkEq('dav change op modify', 2, ChangeLog::OP_MODIFY);
checkEq('dav change op delete', 3, ChangeLog::OP_DELETE);

// DATE vs DATE-TIME mapping decisions (parsed shape as produced by Ics::parse).
$cols = DavIcs::eventColumns([
    'uid' => 'x', 'title' => 'Vacation', 'description' => null, 'location' => null, 'url' => null,
    'start_utc' => '2026-08-15 00:00:00', 'end_utc' => '2026-08-16 00:00:00',
    'all_day' => 1, 'tzid' => 'America/Los_Angeles', 'rrule' => null, 'exdates' => [],
    'status' => 'confirmed', 'recurrence_instance_utc' => null,
]);
checkEq('dav DATE maps to all_day=1', 1, $cols['all_day']);
checkEq('dav DATE pins tzid to UTC', 'UTC', $cols['tzid']);
checkEq('dav empty exdates -> null json', null, $cols['exdates_json']);
checkEq('dav no rrule stays null', null, $cols['rrule']);

$cols = DavIcs::eventColumns([
    'uid' => 'x', 'title' => 'Standup', 'description' => 'notes', 'location' => 'HQ', 'url' => 'https://x.test',
    'start_utc' => '2026-08-03 17:00:00', 'end_utc' => '2026-08-03 17:30:00',
    'all_day' => 0, 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=DAILY',
    'exdates' => ['2026-08-05 17:00:00'], 'status' => 'tentative',
    'recurrence_instance_utc' => null,
]);
checkEq('dav DATETIME keeps tzid', 'America/Los_Angeles', $cols['tzid']);
checkEq('dav DATETIME all_day=0', 0, $cols['all_day']);
checkEq('dav exdates encode to json', json_encode(['2026-08-05 17:00:00']), $cols['exdates_json']);
checkEq('dav rrule passthrough', 'FREQ=DAILY', $cols['rrule']);
checkEq('dav status passthrough', 'tentative', $cols['status']);
checkEq('dav bad tzid falls back to UTC', 'UTC', DavIcs::eventColumns([
    'start_utc' => '2026-08-03 17:00:00', 'end_utc' => '2026-08-03 18:00:00',
    'all_day' => 0, 'tzid' => 'Mars/Olympus_Mons', 'exdates' => [],
])['tzid']);
checkEq('dav empty rrule string -> null', null, DavIcs::eventColumns([
    'start_utc' => '2026-08-03 17:00:00', 'end_utc' => '2026-08-03 18:00:00',
    'all_day' => 0, 'tzid' => 'UTC', 'rrule' => '', 'exdates' => [],
])['rrule']);

// Object body: master (RRULE + EXDATE) plus one RECURRENCE-ID override, one VCALENDAR.
$davMaster = [
    'uid' => 'ev-1', 'title' => 'Weekly sync', 'description' => null, 'location' => null, 'url' => null,
    'start_utc' => '2026-08-03 17:00:00', 'end_utc' => '2026-08-03 18:00:00',
    'all_day' => 0, 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
    'exdates_json' => json_encode(['2026-08-17 17:00:00']), 'status' => 'confirmed',
];
$davOverride = [
    'uid' => 'ev-1', 'title' => 'Weekly sync (moved)', 'start_utc' => '2026-08-10 18:00:00',
    'end_utc' => '2026-08-10 19:00:00', 'all_day' => 0, 'tzid' => 'America/Los_Angeles',
    'recurrence_instance_utc' => '2026-08-10 17:00:00', 'status' => 'confirmed',
];
$obj = DavIcs::buildObject($davMaster, [$davOverride]);
checkEq('dav object one VCALENDAR', 1, substr_count($obj, 'BEGIN:VCALENDAR'));
checkEq('dav object two VEVENTs', 2, substr_count($obj, 'BEGIN:VEVENT'));
checkEq('dav object uid on both vevents', 2, substr_count($obj, 'UID:ev-1'));
check('dav object master keeps rrule', str_contains($obj, 'RRULE:FREQ=WEEKLY;BYDAY=MO'));
check('dav object master keeps exdate', str_contains($obj, 'EXDATE:20260817T170000Z'));
checkEq('dav object one recurrence-id', 1, substr_count($obj, 'RECURRENCE-ID:20260810T170000Z'));
check('dav object no X-WR metadata', !str_contains($obj, 'X-WR-'));
check('dav object starts with vcalendar', str_starts_with($obj, "BEGIN:VCALENDAR\r\n"));
check('dav object ends with vcalendar', str_ends_with($obj, "END:VCALENDAR\r\n"));

// sabre-dependent classes: only verify wiring when sabre/dav is installed.
if (class_exists(\Sabre\CalDAV\Backend\AbstractBackend::class)) {
    check('dav backend loads', class_exists(\BetterCal\Dav\CalendarBackend::class));
    check('dav backend has SyncSupport', is_subclass_of(\BetterCal\Dav\CalendarBackend::class, \Sabre\CalDAV\Backend\SyncSupport::class));
    check('dav auth backend loads', class_exists(\BetterCal\Dav\AuthBackend::class));
    check('dav principal backend loads', class_exists(\BetterCal\Dav\PrincipalBackend::class));
}

// ---------------------------------------------------------------------------

$pass = $GLOBALS['__pass'];
$fail = $GLOBALS['__fail'];
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
