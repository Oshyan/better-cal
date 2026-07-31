<?php

declare(strict_types=1);

// Pure-PHP tests, no database and no composer deps required:
//   php server/tests/run.php

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Dav\ChangeLog;
use BetterCal\Dav\DavIcs;
use BetterCal\Domain\ApiTokens;
use BetterCal\Domain\FallbackParser;
use BetterCal\Domain\Filters;
use BetterCal\Domain\Ics;
use BetterCal\Domain\Recurrence;
use BetterCal\Http\HttpError;
use BetterCal\Http\Router;
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
