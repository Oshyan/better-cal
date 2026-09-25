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
use BetterCal\Domain\GeocodeSweep;
use BetterCal\Domain\Filters;
use BetterCal\Domain\Ics;
use BetterCal\Domain\PromptEval;
use BetterCal\Domain\QuickAdd;
use BetterCal\Domain\Ranking;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Settings;
use BetterCal\Domain\SystemHealth;
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
checkEq('fp1 title keeps with-clause', 'Dinner with Sam', $d['title']);
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
checkEq('fp31 title keeps with-clause', 'Dinner with Sam, Alex and Pat', $d['title']);
checkEq('fp31 start', '2026-07-31T19:00:00-07:00', $d['start']);

$d = $p('tomorrow 3pm');
checkEq('fp32 untitled placeholder', 'New event', $d['title']);
check('fp32 untitled stays below llm-skip threshold', $d['confidence'] < QuickAdd::FALLBACK_CONFIDENCE);

// People + title rework: the with-clause stays in the title (title = input
// minus date/time/location phrases only); personNames parses the clause.
$d = $p('dinner with the Sages at 6PM today'); // exact user screenshot input
checkEq('fp33 screenshot title keeps with-clause', 'Dinner with the Sages', $d['title']);
checkEq('fp33 screenshot people keep article as group name', ['The Sages'], $d['personNames']);
checkEq('fp33 screenshot start 6 PM today', '2026-07-30T18:00:00-07:00', $d['start']);
checkEq('fp33 screenshot end', '2026-07-30T19:00:00-07:00', $d['end']);
checkEq('fp33 screenshot not allDay', false, $d['allDay']);

$d = $p('lunch w/ Ada tomorrow at noon');
checkEq('fp34 w/ shorthand people', ['Ada'], $d['personNames']);
checkEq('fp34 w/ shorthand title', 'Lunch w/ Ada', $d['title']);
checkEq('fp34 w/ start', '2026-07-31T12:00:00-07:00', $d['start']);

$d = $p('review w/Pat 3pm tomorrow');
checkEq('fp35 glued w/ people', ['Pat'], $d['personNames']);
checkEq('fp35 glued w/ title', 'Review w/Pat', $d['title']);

$d = $p("Coffee with Mary-Jane O'Brien friday 9am");
checkEq('fp36 hyphen and apostrophe name kept whole', ["Mary-Jane O'Brien"], $d['personNames']);
checkEq('fp36 title', "Coffee with Mary-Jane O'Brien", $d['title']);

$d = $p('Dinner with the Sages and Sam tomorrow 6pm');
checkEq('fp37 group plus person', ['The Sages', 'Sam'], $d['personNames']);
checkEq('fp37 title', 'Dinner with the Sages and Sam', $d['title']);

$d = $p('Dinner with Sam, Alex and Pat at Delfina tomorrow 7pm');
checkEq('fp38 people list with location', ['Sam', 'Alex', 'Pat'], $d['personNames']);
checkEq('fp38 location', 'Delfina', $d['location']);
checkEq('fp38 title', 'Dinner with Sam, Alex and Pat', $d['title']);

$d = $p('Dinner at Zuni with Sam tomorrow 7pm');
checkEq('fp39 location before with-clause', 'Zuni', $d['location']);
checkEq('fp39 people', ['Sam'], $d['personNames']);
checkEq('fp39 title', 'Dinner with Sam', $d['title']);

$d = $p('the standup tomorrow 9am');
checkEq('fp40 leading article sentence-cased only', 'The standup', $d['title']);
checkEq('fp40 no people', [], $d['personNames']);

$d = $p('meet at 6pm at The Ferry Building tomorrow');
checkEq('fp41 at-time wins over at-location', '2026-07-31T18:00:00-07:00', $d['start']);
checkEq('fp41 multi-word capitalized location intact', 'The Ferry Building', $d['location']);
checkEq('fp41 title sentence-cased', 'Meet', $d['title']);

$d = $p('Drinks at 8pm');
checkEq('fp42 at-time is a time not a location', null, $d['location']);
checkEq('fp42 start', '2026-07-30T20:00:00-07:00', $d['start']);

$d = $p('Picnic at Golden Gate Park saturday');
checkEq('fp43 multi-word location', 'Golden Gate Park', $d['location']);
checkEq('fp43 allDay', true, $d['allDay']);
checkEq('fp43 title', 'Picnic', $d['title']);

$d = $p('Call at 14:30 tomorrow');
checkEq('fp44 24h at-time wins', '2026-07-31T14:30:00-07:00', $d['start']);
checkEq('fp44 no location', null, $d['location']);

$d = $p('Hike with the team saturday');
checkEq('fp45 article kept in group name', ['The Team'], $d['personNames']);
checkEq('fp45 title', 'Hike with the team', $d['title']);

$d = $p('sync with Sam and Alex tomorrow 10am');
checkEq('fp46 lowercase input sentence-cased', 'Sync with Sam and Alex', $d['title']);
checkEq('fp46 people', ['Sam', 'Alex'], $d['personNames']);

$d = $p('Coffee with'); // dangling with-clause: no names, words stay in title
checkEq('fp47 dangling with has no people', [], $d['personNames']);
checkEq('fp47 dangling with title', 'Coffee with', $d['title']);

// Completeness + confidence gates feeding the QuickAdd LLM-skip decision.
checkEq('fp complete date+time', true, $p('Meeting tomorrow 9am')['complete']);
checkEq('fp complete date-only allDay', true, $p('Trip 2026-08-15')['complete']);
checkEq('fp incomplete time-only', false, $p('Coffee 8am')['complete']);
checkEq('fp incomplete bare title', false, $p('Brainstorm')['complete']);
check('fp date+time clears threshold', $p('Meeting tomorrow 9am')['confidence'] >= QuickAdd::FALLBACK_CONFIDENCE);
check('fp date-only clears threshold', $p('Trip 2026-08-15')['confidence'] >= QuickAdd::FALLBACK_CONFIDENCE);
check('fp time-only below threshold', $p('Coffee 8am')['confidence'] < QuickAdd::FALLBACK_CONFIDENCE);

// Vague time-of-day defaults + tonight/yesterday
$d = $p('Call mom Sunday evening');
checkEq('fp trailing evening -> 6pm', '18:00', substr($d['start'], 11, 5));
checkEq('fp trailing evening keeps title', 'Call mom', $d['title']);
check('fp trailing evening is timed', !$d['allDay']);
$d = $p('Standup Monday in the morning');
checkEq('fp in-the-morning -> 9am', '09:00', substr($d['start'], 11, 5));
$d = $p('Dinner tonight');
checkEq('fp tonight -> today 6pm', '18:00', substr($d['start'], 11, 5));
checkEq('fp tonight resolves date', true, $d['dateFound']);
checkEq('fp tonight keeps title', 'Dinner', $d['title']);
$d = $p('Night hike Friday');
check('fp leading Night stays in title', str_contains($d['title'], 'Night hike'));
check('fp leading Night stays all-day', $d['allDay']);
$d = $p('Gym yesterday 6pm');
checkEq('fp yesterday is explicit past date', true, $d['dateFound']);
check('fp yesterday lands in the past', $d['start'] < \BetterCal\Support\Time::iso($now));

// Compound relative dates ($now = Thu 2026-07-30)
$d = $p('Lunch day after tomorrow');
checkEq('fp day after tomorrow -> +2 days', '2026-08-01', substr($d['start'], 0, 10));
checkEq('fp day after tomorrow keeps title', 'Lunch', $d['title']);
$d = $p('Dentist a week from Friday 3pm');
checkEq('fp week from Friday -> Friday+7', '2026-08-07', substr($d['start'], 0, 10));
checkEq('fp week from Friday keeps time', '15:00', substr($d['start'], 11, 5));
checkEq('fp week from Friday title', 'Dentist', $d['title']);
$d = $p('Review first Monday of September 10am');
checkEq('fp first Monday of September', '2026-09-07', substr($d['start'], 0, 10));
$d = $p('Retro last Friday of October');
checkEq('fp last Friday of October', '2026-10-30', substr($d['start'], 0, 10));
$d = $p('Standup first Monday of July 9am');
checkEq('fp past nth-weekday month rolls to next year', '2027-07-05', substr($d['start'], 0, 10));
$d = $p('Party the Saturday after next 8pm');
checkEq('fp leftover temporal words demote completeness', false, $d['complete']);
check('fp leftover temporal words cap confidence', $d['confidence'] <= 0.5);

// ---------------------------------------------------------------------------
// People::normalizeName (pure)
// ---------------------------------------------------------------------------

use BetterCal\Domain\People;

checkEq('people name trims + collapses whitespace', 'Virginia Miller', People::normalizeName("  Virginia \n Miller  "));
checkEq('people name caps length', People::MAX_NAME, mb_strlen(People::normalizeName(str_repeat('a', 300))));
$threw = false;
try {
    People::normalizeName('   ');
} catch (\BetterCal\Http\HttpError) {
    $threw = true;
}
check('people empty name throws', $threw);

// ---------------------------------------------------------------------------
// MailIngest — iMIP parse, schema.org extraction, RSVP reply (pure)
// ---------------------------------------------------------------------------

use BetterCal\Domain\MailIngest;

if (class_exists(\Sabre\VObject\Reader::class)) {
$imipIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\n"
    . "UID:abc-123\@example.com\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260810T170000Z\r\nDTEND:20260810T180000Z\r\n"
    . "SUMMARY:Team sync\r\nLOCATION:Room 4\r\nSEQUENCE:2\r\n"
    . "ORGANIZER;CN=Alice:mailto:alice@example.com\r\n"
    . "ATTENDEE;CN=Owner;PARTSTAT=NEEDS-ACTION:mailto:owner@example.com\r\n"
    . "END:VEVENT\r\nEND:VCALENDAR\r\n";
$imip = MailIngest::parseImip($imipIcs);
checkEq('imip method', 'REQUEST', $imip['method']);
checkEq('imip event title', 'Team sync', $imip['events'][0]['title']);
checkEq('imip organizer email', 'alice@example.com', $imip['events'][0]['invite']['organizer']['email']);
checkEq('imip organizer name', 'Alice', $imip['events'][0]['invite']['organizer']['name']);
checkEq('imip attendee partstat', 'NEEDS-ACTION', $imip['events'][0]['invite']['attendees'][0]['partstat']);
checkEq('imip sequence', 2, $imip['events'][0]['invite']['sequence']);
checkEq('imip garbage -> null', null, MailIngest::parseImip('not an ics'));
}

// Plugin system (docs/plugins/prd-v1.md): pure pieces.
use BetterCal\Domain\Plugins;
use BetterCal\Infra\HttpClient;

// Manifest validation.
$goodMan = ['id' => 'weather', 'name' => 'Weather', 'version' => '0.1.0',
    'permissions' => ['http', 'events:write'], 'jobs' => [['id' => 'refresh', 'interval' => 'PT3H']],
    'settings' => [['key' => 'units', 'label' => 'Units', 'type' => 'select', 'options' => ['F', 'C']]]];
checkEq('plugin manifest: valid passes', [], Plugins::manifestErrors($goodMan));
check('plugin manifest: bad id caught', Plugins::manifestErrors(['id' => 'Bad_Id', 'name' => 'x', 'version' => '1.0.0']) !== []);
check('plugin manifest: bad interval caught', in_array('job refresh: interval must be an ISO 8601 duration (e.g. PT3H)',
    Plugins::manifestErrors($goodMan === [] ? [] : array_merge($goodMan, ['jobs' => [['id' => 'refresh', 'interval' => '3h']]])), true));
check('plugin manifest: unknown permission caught', in_array('unknown permission: mail',
    Plugins::manifestErrors(array_merge($goodMan, ['permissions' => ['mail']])), true));
check('plugin manifest: select without options caught',
    Plugins::manifestErrors(array_merge($goodMan, ['settings' => [['key' => 'u', 'label' => 'U', 'type' => 'select']]])) !== []);
check('plugin manifest: future minHost refused',
    Plugins::manifestErrors(array_merge($goodMan, ['minHost' => '9.0.0'])) !== []);

// Settings schema validation.
$schema = [
    ['key' => 'days', 'type' => 'number', 'min' => 1, 'max' => 14],
    ['key' => 'units', 'type' => 'select', 'options' => ['F', 'C']],
    ['key' => 'on', 'type' => 'toggle'],
    ['key' => 'loc', 'type' => 'location'],
    ['key' => 'note', 'type' => 'text'],
];
[$cv, $ce] = Plugins::validateAgainstSchema($schema, ['days' => 10, 'units' => 'C', 'on' => 'true', 'loc' => ['name' => 'SF', 'lat' => 37.77, 'lng' => -122.42], 'note' => 'hi']);
checkEq('plugin settings: clean pass has no errors', [], $ce);
checkEq('plugin settings: toggle coerces', true, $cv['on']);
checkEq('plugin settings: number kept numeric', 10, $cv['days']);
[$cv2, $ce2] = Plugins::validateAgainstSchema($schema, ['days' => 99, 'units' => 'K', 'loc' => ['name' => 'x']]);
check('plugin settings: max enforced', isset($ce2['days']));
check('plugin settings: select enforced', isset($ce2['units']));
check('plugin settings: location needs coords', isset($ce2['loc']));
[$cv3, ] = Plugins::validateAgainstSchema($schema, ['loc' => null]);
check('plugin settings: location clearable', array_key_exists('loc', $cv3) && $cv3['loc'] === null);

// Over-long text used to be truncated silently behind a 200, so a setting
// looked saved and the plugin then ran on a fraction of it. Refuse instead.
[, $ceLong] = Plugins::validateAgainstSchema($schema, ['note' => str_repeat('a', 501)]);
check('plugin settings: over-long text refused, not truncated', isset($ceLong['note']));
[$cvEdge, $ceEdge] = Plugins::validateAgainstSchema($schema, ['note' => str_repeat('a', 500)]);
check('plugin settings: text at the limit still saves', $ceEdge === [] && mb_strlen($cvEdge['note']) === 500);

// textarea exists because the schema has no list type; it may raise its own
// ceiling so a wishlist need not be split across four fields.
$areaSchema = [['key' => 'list', 'type' => 'textarea', 'maxLength' => 4000]];
[$cvA, $ceA] = Plugins::validateAgainstSchema($areaSchema, ['list' => str_repeat('b', 4000)]);
check('plugin settings: textarea honours a raised maxLength', $ceA === [] && mb_strlen($cvA['list']) === 4000);
[, $ceA2] = Plugins::validateAgainstSchema($areaSchema, ['list' => str_repeat('b', 4001)]);
check('plugin settings: textarea still refuses past its maxLength', isset($ceA2['list']));
checkEq('plugin settings: textarea ceiling clamps a greedy maxLength', Plugins::TEXTAREA_MAX,
    Plugins::textLimit(['type' => 'textarea', 'maxLength' => 999999]));
checkEq('plugin settings: plain text cannot raise its own ceiling', Plugins::TEXT_MAX,
    Plugins::textLimit(['type' => 'text', 'maxLength' => 999999]));

// null means "clear" for every type, matching setEventData(null). Coercion
// used to turn a clear into '' for text and false for a toggle.
[$cvNull, ] = Plugins::validateAgainstSchema($schema, ['note' => null, 'on' => null, 'days' => null]);
check('plugin settings: null clears text', array_key_exists('note', $cvNull) && $cvNull['note'] === null);
check('plugin settings: null clears toggle', array_key_exists('on', $cvNull) && $cvNull['on'] === null);
check('plugin settings: null clears number', array_key_exists('days', $cvNull) && $cvNull['days'] === null);

// Coordinates were accepted unchecked, so lat 991 stored fine.
[, $ceGeo] = Plugins::validateAgainstSchema($schema, ['loc' => ['name' => 'nowhere', 'lat' => 991, 'lng' => -122.4]]);
check('plugin settings: out-of-range latitude refused', isset($ceGeo['loc']));
[, $ceGeo2] = Plugins::validateAgainstSchema($schema, ['loc' => ['name' => 'nowhere', 'lat' => 37.7, 'lng' => 900]]);
check('plugin settings: out-of-range longitude refused', isset($ceGeo2['loc']));

// (string) on an array is the literal "Array", so a structured value sent to a
// text field silently stored that word.
[, $ceArr] = Plugins::validateAgainstSchema($schema, ['note' => ['lat' => 1, 'lng' => 2]]);
check('plugin settings: non-scalar refused by text field', isset($ceArr['note']));

// A default longer than its own field could never be re-saved once edited.
check('plugin manifest: over-long text default refused', Plugins::manifestErrors(array_merge($goodMan, [
    'settings' => [['key' => 'note', 'label' => 'Note', 'type' => 'text', 'default' => str_repeat('a', 501)]],
])) !== []);
check('plugin manifest: textarea type accepted', Plugins::manifestErrors(array_merge($goodMan, [
    'settings' => [['key' => 'list', 'label' => 'List', 'type' => 'textarea']],
])) === []);

// decoration.icon: names are validated server-side against a list that has to
// mirror the frontend's icon set. Read the real icons.js and compare, so the
// two cannot drift into "valid name, renders nothing".
$iconsJs = (string) file_get_contents(dirname(__DIR__, 2) . '/web/src/ui/icons.js');
$iconBody = explode('const body = {', $iconsJs, 2)[1] ?? '';
preg_match_all('/^    ([a-zA-Z][a-zA-Z0-9_-]*):/m', $iconBody, $mIcons);
$jsIcons = $mIcons[1];
sort($jsIcons);
$phpIcons = Plugins::ICON_NAMES;
sort($phpIcons);
checkEq('icon set: PHP allowlist matches icons.js', $jsIcons, $phpIcons);

check('icon: a host name validates', Plugins::iconError('calendar') === null);
check('icon: an unknown name is refused', Plugins::iconError('map-pin') !== null);
check('icon: emoji passes through', Plugins::iconError('🌊') === null);
check('icon: ZWJ emoji sequence passes', Plugins::iconError('👩‍🚀') === null);
check('icon: a smuggled label is refused', Plugins::iconError('a whole sentence of text') !== null);
check('icon: absent is fine', Plugins::iconError(null) === null);
check('icon: empty string is refused', Plugins::iconError('  ') !== null);
check('manifest: bad decoration icon fails install', Plugins::manifestErrors(array_merge($goodMan, [
    'decoration' => ['icon' => 'not-an-icon', 'color' => '#5b8dd9'],
])) !== []);

// Plugin-supplied icons are PATH DATA, never markup: the host builds the <svg>
// shell, so there is no element to carry a script or an external reference.
// The grammar is the whole defence, so probe its edges.
check('iconPath: a plain path validates',
    Plugins::iconPathError('M3 8.6a4.4 4.4 0 0 1 4.4 4.4M3 4.6') === null);
check('iconPath: absent is fine', Plugins::iconPathError(null) === null);
check('iconPath: must start with a moveto', Plugins::iconPathError('L3 4 L5 6') !== null);
check('iconPath: markup is refused', Plugins::iconPathError('M0 0"/><script>alert(1)</script>') !== null);
check('iconPath: an element is refused', Plugins::iconPathError('M0 0 <foreignObject>') !== null);
check('iconPath: a url() reference is refused', Plugins::iconPathError('M0 0 url(#x)') !== null);
check('iconPath: an xlink href is refused', Plugins::iconPathError('M0 0 xlink:href="http://x"') !== null);
check('iconPath: an entity is refused', Plugins::iconPathError('M0 0 &#60;svg&#62;') !== null);
check('iconPath: quotes are refused', Plugins::iconPathError('M0 0 "') !== null);
check('iconPath: scientific notation survives', Plugins::iconPathError('M1e2 2.5e-3 L4 4') === null);
check('iconPath: arcs and curves survive',
    Plugins::iconPathError('M8 1A7 7 0 1 1 8 15C4 15 1 12 1 8Z') === null);
check('iconPath: over-long path refused',
    Plugins::iconPathError('M' . str_repeat('1 2 ', 600)) !== null);
check('manifest: a valid iconPath installs', Plugins::manifestErrors(array_merge($goodMan, [
    'decoration' => ['iconPath' => 'M2 8 L8 2 L14 8'],
])) === []);
check('manifest: a hostile iconPath fails install', Plugins::manifestErrors(array_merge($goodMan, [
    'decoration' => ['iconPath' => 'M0 0"><script>x</script>'],
])) !== []);

// Declared dependencies between plugins (tier 1).
check('manifest: requires accepts a list of ids',
    Plugins::manifestErrors(array_merge($goodMan, ['requires' => ['open-meteo']])) === []);
check('manifest: optional accepts a list of ids',
    Plugins::manifestErrors(array_merge($goodMan, ['optional' => ['tides', 'open-meteo']])) === []);
// The fixture's own id is "weather", so this also proves the self-check covers
// `optional` and not just `requires`.
check('manifest: a plugin cannot optionally depend on itself',
    Plugins::manifestErrors(array_merge($goodMan, ['optional' => ['weather']])) !== []);
check('manifest: requires refuses a non-list',
    Plugins::manifestErrors(array_merge($goodMan, ['requires' => 'open-meteo'])) !== []);
check('manifest: requires refuses a bad id',
    Plugins::manifestErrors(array_merge($goodMan, ['requires' => ['Not An Id']])) !== []);
check('manifest: a plugin cannot require itself',
    Plugins::manifestErrors(array_merge($goodMan, ['requires' => [$goodMan['id']]])) !== []);

// SSRF policy: the refusal list.
foreach (['127.0.0.1', '10.1.2.3', '172.16.0.9', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fe80::1', 'fd00::1', '::ffff:127.0.0.1', 'not-an-ip',
          // F24 (scan 2026-09-23): anything outside global unicast, and wrappers of private IPv4
          '64:ff9b::a00:5', '64:ff9b::7f00:1', '64:ff9b:1::a00:5', '::a00:5', 'fec0::1', 'ff02::1', '2001:db8::1', '2001:0:4136:e378::1', '2002:a00:5::1', '2002:7f00:1::1'] as $bad) {
    check('http policy refuses ' . $bad, HttpClient::isForbiddenIp($bad));
}
foreach (['8.8.8.8', '140.82.112.3', '2606:4700:4700::1111', '100.128.0.1', '2a00:1450:4009:81f::200e', '2002:808:808::1', '64:ff9b::808:808'] as $ok) {
    check('http policy allows ' . $ok, !HttpClient::isForbiddenIp($ok));
}

// GH #21: feed fetching used raw cURL with FOLLOWLOCATION and no address
// check, so a subscription URL could be walked to an internal address. It goes
// through the policied client now — assert the refusal reaches the feed path
// rather than trusting that the client is merely imported.
$feeds = new BetterCal\Domain\Feeds(new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]));
foreach (['http://127.0.0.1/evil.ics', 'https://169.254.169.254/latest/meta-data', 'http://[::1]/x.ics'] as $bad) {
    $refused = false;
    try {
        $feeds->fetch($bad);
    } catch (\RuntimeException $e) {
        $refused = str_contains($e->getMessage(), 'Refused') || str_contains($e->getMessage(), 'Feed fetch failed');
    }
    check('feed fetch refuses internal target ' . $bad, $refused);
}
$rejectedScheme = false;
try {
    $feeds->fetch('file:///etc/passwd');
} catch (\RuntimeException $e) {
    $rejectedScheme = true;
}
check('feed fetch refuses non-http scheme', $rejectedScheme);

// Plugin v2 + proposals: pure validation.
use BetterCal\Domain\Proposals;

// Proposal plan shape.
$goodPlan = ['events' => [['title' => 'Dinner', 'start' => '2026-09-01T19:00:00-07:00']]];
checkEq('proposal plan: minimal valid', [], Proposals::planErrors($goodPlan));
check('proposal plan: empty events rejected', Proposals::planErrors(['events' => []]) !== []);
check('proposal plan: missing title caught', in_array('events[0].title is required', Proposals::planErrors(['events' => [['start' => 'x']]]), true));
check('proposal plan: missing start caught', in_array('events[0].start is required', Proposals::planErrors(['events' => [['title' => 'x']]]), true));
check('proposal plan: trip needs title', Proposals::planErrors($goodPlan + ['trip' => ['start' => 'a', 'end' => 'b']]) !== []);
checkEq('proposal plan: trip valid', [], Proposals::planErrors($goodPlan + ['trip' => ['title' => 'T', 'start' => '2026-09-01', 'end' => '2026-09-04']]));
check('proposal plan: oversized batch rejected', Proposals::planErrors(['events' => array_fill(0, 51, ['title' => 't', 'start' => 's'])]) !== []);
check('proposal plan: non-object rejected', Proposals::planErrors('nope') !== []);

// v2 permissions and manifest sections.
$v2Man = ['id' => 'planner', 'name' => 'Planner', 'version' => '0.1.0',
    'permissions' => ['propose', 'llm', 'notify', 'people'],
    'jobs' => [['id' => 'plan', 'interval' => 'P1D']],
    'eventSettings' => [['key' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['a', 'b']]]];
checkEq('manifest: v2 permissions accepted', [], Plugins::manifestErrors($v2Man));
check('manifest: eventSettings validated like other schemas',
    Plugins::manifestErrors(array_merge($v2Man, ['eventSettings' => [['key' => 'x', 'label' => 'X', 'type' => 'bogus']]])) !== []);
check('manifest: bad animation caught',
    Plugins::manifestErrors(array_merge($v2Man, ['decoration' => ['animation' => 'disco']])) !== []);
checkEq('manifest: valid animation accepted', [],
    Plugins::manifestErrors(array_merge($v2Man, ['decoration' => ['icon' => 'trip', 'animation' => 'pulse']])));

// isNew: source-based arrival rule (pure). The pill marks automated arrivals
// only; the user's own creations never wear it, and nothing that happens to an
// event later (calendar moves included) can change the verdict.
use BetterCal\Domain\Events;

$nowN = new DateTimeImmutable('2026-08-06T12:00:00Z');
$freshN = $nowN->sub(new DateInterval('PT1H'));
$oldCal = $nowN->sub(new DateInterval('P30D'));
$youngCal = $nowN->sub(new DateInterval('PT2M'));

check('isNew: fresh feed arrival is new', Events::isNewFor('feed', $freshN, $oldCal, $nowN));
check('isNew: fresh api arrival is new', Events::isNewFor('api', $freshN, $oldCal, $nowN));
check('isNew: fresh mail invite is new', Events::isNewFor('mail:imip', $freshN, $oldCal, $nowN));
check('isNew: user-created is never new', !Events::isNewFor('web', $freshN, $oldCal, $nowN));
check('isNew: quick add is never new', !Events::isNewFor('quickadd', $freshN, $oldCal, $nowN));
check('isNew: own phone via caldav is never new', !Events::isNewFor('caldav', $freshN, $oldCal, $nowN));
check('isNew: stale feed arrival is not new', !Events::isNewFor('feed', $nowN->sub(new DateInterval('PT25H')), $oldCal, $nowN));
check('isNew: initial bulk load is not new', !Events::isNewFor('feed', $freshN, $freshN->sub(new DateInterval('PT2M')), $nowN));
check('isNew: import after settle is new', Events::isNewFor('import', $freshN, $oldCal, $nowN));
check('isNew: unknown via is never new', !Events::isNewFor('mystery', $freshN, $oldCal, $nowN));

// iMIP forgery/replay guard (BC-07/08/09). An iMIP message is unauthenticated
// mail; the organizer bound when the invite was first accepted is what later
// REQUEST/CANCEL messages must match, or anyone who learns a UID can cancel or
// rewrite the owner's meeting.
$bound = ['organizer' => ['email' => 'alice@example.com'], 'sequence' => 2];
$may = static fn(?array $stored, array $in, string $from) => MailIngest::imipMayMutate($stored, $in, $from);

check('imip same organizer may update', $may($bound, ['organizer' => ['email' => 'alice@example.com'], 'sequence' => 3], 'mailer@corp.example')[0]);
check('imip organizer case/name insensitive', $may($bound, ['organizer' => ['email' => 'ALICE@Example.com'], 'sequence' => 2], '')[0]);
check('imip trusted sender covers relayed body', $may($bound, ['organizer' => ['email' => 'bob@evil.test'], 'sequence' => 3], 'Alice <alice@example.com>')[0]);
check('imip mailto: prefix normalizes', $may($bound, ['organizer' => ['email' => 'mailto:alice@example.com'], 'sequence' => 2], '')[0]);

checkEq('imip forged cancel refused', 'organizer mismatch', $may($bound, ['organizer' => ['email' => 'mallory@evil.test'], 'sequence' => 9], 'mallory@evil.test')[1]);
checkEq('imip no-organizer message refused', 'organizer mismatch', $may($bound, ['sequence' => 3], 'mallory@evil.test')[1]);
checkEq('imip stale sequence refused', 'stale sequence', $may($bound, ['organizer' => ['email' => 'alice@example.com'], 'sequence' => 1], '')[1]);
check('imip equal sequence allowed', $may($bound, ['organizer' => ['email' => 'alice@example.com'], 'sequence' => 2], '')[0]);

// UID collision with a local event that never carried an invite: fail closed
// rather than letting unauthenticated mail take ownership of it (BC-08).
checkEq('imip unbound local uid refused', 'no organizer bound to this event', $may(null, ['organizer' => ['email' => 'alice@example.com'], 'sequence' => 1], 'alice@example.com')[1]);
checkEq('imip empty stored organizer refused', 'no organizer bound to this event', $may(['organizer' => null, 'sequence' => 0], ['organizer' => ['email' => 'x@y.test']], 'x@y.test')[1]);

// BC-09: a cancellation ends its sequence number. A REQUEST carrying the same
// number was written before the cancel and must not rewrite the cancelled event;
// re-issuing the meeting takes a higher one. A live event still accepts equal.
$mayWhen = static fn(string $status, int $seq) => MailIngest::imipMayMutate($bound, ['organizer' => ['email' => 'alice@example.com'], 'sequence' => $seq], '', $status);
checkEq('imip replay after cancel: same sequence refused', 'stale sequence (event already cancelled)', $mayWhen('cancelled', 2)[1]);
checkEq('imip replay after cancel: lower sequence refused', 'stale sequence', $mayWhen('cancelled', 1)[1]);
check('imip re-issue after cancel: higher sequence allowed', $mayWhen('cancelled', 3)[0]);
check('imip live event: equal sequence still allowed', $mayWhen('confirmed', 2)[0]);

// --- Review queue: emailed changes are held, never applied (BC-07) -----------
{
    $rq = BetterCal\Domain\ReviewQueue::class;
    $stored = [
        'id' => 40, 'title' => 'Planning dinner', 'status' => 'confirmed', 'all_day' => 0, 'tzid' => 'America/Los_Angeles',
        'start_utc' => '2026-10-02 02:00:00', 'end_utc' => '2026-10-02 04:00:00', // Oct 1, 7-9 PM Pacific
        'location' => 'Zuni Cafe', 'description' => '<p>Bring the <b>deck</b>.</p>', 'rrule' => null,
    ];
    $same = [
        'title' => 'Planning dinner', 'start' => '2026-10-01T19:00:00-07:00', 'end' => '2026-10-01T21:00:00-07:00',
        'allDay' => false, 'tzid' => 'America/Los_Angeles', 'location' => 'Zuni Cafe', 'description' => '<p>Bring the <b>deck</b>.</p>', 'rrule' => null,
    ];
    checkEq('review diff: a re-send that changes nothing is not a decision', [], $rq::inviteDiff($stored, 'REQUEST', $same));
    checkEq('review diff: the same instant written in another zone is not a change', [],
        $rq::inviteDiff($stored, 'REQUEST', ['start' => '2026-10-02T03:00:00+01:00', 'end' => '2026-10-02T05:00:00+01:00'] + $same));
    $moved = $rq::inviteDiff($stored, 'REQUEST', ['start' => '2026-10-03T19:00:00-07:00', 'end' => '2026-10-03T21:00:00-07:00', 'location' => 'Nopa'] + $same);
    checkEq('review diff: a moved meeting lists exactly what moved', ['start', 'end', 'location'], array_column($moved, 'field'));
    checkEq('review diff: shows the stored start in the event\'s own zone', '2026-10-01T19:00:00-07:00', $moved[0]['from']);
    checkEq('review diff: and the proposed one', '2026-10-03T19:00:00-07:00', $moved[0]['to']);
    checkEq('review diff: location from -> to', ['Zuni Cafe', 'Nopa'], [$moved[2]['from'], $moved[2]['to']]);
    $desc = $rq::inviteDiff($stored, 'REQUEST', ['description' => '<p>Bring the   <i>budget</i> instead.</p>'] + $same);
    checkEq('review diff: description is compared and previewed as plain text', ['Bring the deck.', 'Bring the budget instead.'], [$desc[0]['from'], $desc[0]['to']]);
    checkEq('review diff: markup-only description edits are not a change', [], $rq::inviteDiff($stored, 'REQUEST', ['description' => '<div>Bring the deck.</div>'] + $same));
    checkEq('review diff: a cancellation is one line', [['field' => 'status', 'label' => 'Status', 'from' => 'confirmed', 'to' => 'cancelled']], $rq::inviteDiff($stored, 'CANCEL', []));
    checkEq('review diff: cancelling what is already cancelled is nothing', [], $rq::inviteDiff(['status' => 'cancelled'] + $stored, 'CANCEL', []));
    checkEq('review diff: a re-issued meeting says it comes back', 'status', array_column($rq::inviteDiff(['status' => 'cancelled'] + $stored, 'REQUEST', $same), 'field')[0] ?? null);
    $allDayStored = ['all_day' => 1, 'tzid' => 'UTC', 'start_utc' => '2026-10-05 00:00:00', 'end_utc' => '2026-10-06 00:00:00'] + $stored;
    checkEq('review diff: all-day compares dates, not instants', [],
        $rq::inviteDiff($allDayStored, 'REQUEST', ['allDay' => true, 'start' => '2026-10-05T00:00:00-07:00', 'end' => '2026-10-06T00:00:00-07:00'] + $same));
    checkEq('review diff: all-day moved a day', ['2026-10-05', '2026-10-06'], (static function (array $d): array { return [$d[0]['from'], $d[0]['to']]; })(
        $rq::inviteDiff($allDayStored, 'REQUEST', ['allDay' => true, 'start' => '2026-10-06', 'end' => '2026-10-07'] + $same)));

    // Holding writes ONLY to the queue: there is no events table here at all,
    // so touching the calendar would be an error, not just a wrong answer.
    $rdb = new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]);
    $rdb->run("CREATE TABLE review_items (id INTEGER PRIMARY KEY, user_id INTEGER, kind TEXT, event_id INTEGER, source_key TEXT, title TEXT, summary TEXT, payload_json TEXT, status TEXT DEFAULT 'open', created_at TEXT DEFAULT CURRENT_TIMESTAMP, decided_at TEXT)");
    $rdb->run('CREATE TABLE mutations (id INTEGER PRIMARY KEY, user_id INTEGER, entity TEXT, entity_id INTEGER, op TEXT, before_json TEXT, after_json TEXT, source TEXT, run_id TEXT, summary TEXT, details_json TEXT)');
    $queue = new BetterCal\Domain\ReviewQueue($rdb, (new ReflectionClass(Events::class))->newInstanceWithoutConstructor());
    $alice = ['organizer' => ['email' => 'alice@example.com', 'name' => 'Alice'], 'sequence' => 3, 'attendees' => []];
    checkEq('review hold: a no-op message creates no item', null, $queue->holdInviteChange(1, $stored, 'uid-1', 'REQUEST', $same, $alice, 'alice@example.com'));
    $first = $queue->holdInviteChange(1, $stored, 'uid-1', 'REQUEST', ['location' => 'Nopa'] + $same, $alice, 'alice@example.com');
    check('review hold: a real change is held', is_int($first) && $first > 0);
    $second = $queue->holdInviteChange(1, $stored, 'uid-1', 'CANCEL', [], ['sequence' => 4] + $alice, 'alice@example.com');
    $open = $queue->listFor(1);
    checkEq('review hold: a newer change for the same meeting replaces the open one', [$second], array_column($open, 'id'));
    checkEq('review hold: summary names who and what', 'Alice cancelled this.', $open[0]['summary']);
    checkEq('review hold: another meeting is its own item', 2, count((function () use ($queue, $stored, $same, $alice) {
        $queue->holdInviteChange(1, ['id' => 41] + $stored, 'uid-2', 'REQUEST', ['title' => 'Planning lunch'] + $same, $alice, 'alice@example.com');
        return $queue->listFor(1);
    })()));
    checkEq('review hold: another user sees none of it', [], $queue->listFor(2));
    checkEq('review count', 2, $queue->openCount(1));
    $dismissed = $queue->dismiss(1, $second);
    checkEq('review dismiss: closes the item', 'dismissed', $dismissed['status']);
    checkEq('review dismiss: leaves a trace in Activity', 'Dismissed an emailed cancellation of "Planning dinner"', $rdb->scalar("SELECT summary FROM mutations WHERE op = 'refuse'"));
    try {
        $queue->dismiss(1, $second);
        check('review dismiss: deciding twice is refused', false);
    } catch (HttpError $e) {
        checkEq('review dismiss: deciding twice is a 409', [409, 'review_decided'], [$e->status, $e->errorCode]);
    }
    try {
        $queue->dismiss(2, $first);
        check('review: another user cannot decide my item', false);
    } catch (HttpError $e) {
        checkEq('review: another user\'s item is a 404, not a 403 (no existence leak)', 404, $e->status);
    }
    checkEq('review list all: superseded items stay out, decided ones show', ['open', 'dismissed'], array_column($queue->listFor(1, false), 'status'));
}

$ldHtml = '<html><body><script type="application/ld+json">'
    . json_encode(['@context' => 'https://schema.org', '@type' => 'Event', 'name' => 'Concert Night',
        'startDate' => '2026-09-12T19:30:00-07:00', 'endDate' => '2026-09-12T22:00:00-07:00',
        'location' => ['@type' => 'Place', 'name' => 'The Fillmore', 'address' => ['streetAddress' => '1805 Geary Blvd', 'addressLocality' => 'San Francisco']],
        'url' => 'https://example.com/tix'])
    . '</script></body></html>';
$ld = MailIngest::extractLdJsonEvents($ldHtml);
checkEq('ldjson event name', 'Concert Night', $ld[0]['title']);
checkEq('ldjson start', '2026-09-12T19:30:00-07:00', $ld[0]['start']);
checkEq('ldjson location composed', 'The Fillmore, 1805 Geary Blvd, San Francisco', $ld[0]['location']);
checkEq('ldjson url', 'https://example.com/tix', $ld[0]['url']);

$resHtml = '<script type="application/ld+json">' . json_encode([
    '@type' => 'EventReservation',
    'reservationFor' => ['@type' => 'Event', 'name' => 'Workshop', 'startDate' => '2026-10-01T10:00:00-07:00'],
]) . '</script>';
checkEq('ldjson reservation unwraps', 'Workshop', MailIngest::extractLdJsonEvents($resHtml)[0]['title']);
checkEq('ldjson no markup -> empty', [], MailIngest::extractLdJsonEvents('<p>plain mail</p>'));
// F9 (scan 2026-09-23): script/style blocks are found by a linear scan.
checkEq('html blocks: script and style found in order', ['style', 'script'], array_column(BetterCal\Domain\Sanitize::htmlBlocks('<p>a</p><STYLE>x</style><b>b</b><script type="x">y</SCRIPT>'), 'tag'));
checkEq('html blocks: dropScriptStyle keeps the rest', '<p>a</p> <b>b</b> ', BetterCal\Domain\Sanitize::dropScriptStyle('<p>a</p><style>x</style><b>b</b><script>y</script>'));
checkEq('html blocks: an unclosed block runs to the end, as in a browser', '<p>a</p> ', BetterCal\Domain\Sanitize::dropScriptStyle('<p>a</p><script>never closed'));
checkEq('forward preamble: a window edge inside a multi-byte character still strips the header', 'x',
    trim(MailIngest::stripForwardPreamble(str_repeat('é', 150) . '---------- Forwarded message ---------' . "\nFrom: A <a@example.com>\nDate: Mon\nSubject: S\nTo: <b@example.com>") === str_repeat('é', 150) . ' ' ? 'x' : 'kept'));
checkEq('html blocks: <scripts> is not a script tag', [], BetterCal\Domain\Sanitize::htmlBlocks('<scripts>no</scripts>'));
{
    $bomb = str_repeat('<script', 75000) . '>';
    $t0 = microtime(true);
    MailIngest::extractLdJsonEvents($bomb);
    MailIngest::flattenHtml($bomb);
    BetterCal\Domain\Sanitize::dropScriptStyle(str_repeat('<script x', 60000) . '>' . str_repeat('<style ', 60000));
    MailIngest::stripForwardPreamble(str_repeat('-', 200000) . ' Forwarded message ---');
    check('html blocks: 75,000 "<script" and 200,000 dashes take under a second', microtime(true) - $t0 < 1.0, sprintf('%.2fs', microtime(true) - $t0));
}

check('llm gate passes eventish subject', MailIngest::llmGateAllows('Your registration is confirmed!'));
check('llm gate blocks ordinary mail', !MailIngest::llmGateAllows('Re: lunch tomorrow?'));

// Embedded GCal link tier (survives Gmail forwards that strip JSON-LD)
$ebHtml = '<a href="https://www.google.com/maps">map</a> '
    . '<a href="https://calendar.google.com/calendar/render?action=TEMPLATE&amp;text=The%20Feels&amp;dates=20260815T170000Z%2F20260817T000000Z&amp;location=The%20Fold">Add to Google</a>';
$links = MailIngest::extractGcalLinks($ebHtml);
checkEq('gcal link extracted from html', 1, count($links));
check('gcal link entities decoded', str_contains($links[0], '&text=The%20Feels'));
checkEq('no gcal links -> empty', [], MailIngest::extractGcalLinks('<p>plain</p>'));

$fwd = "---Sig line--- ---------- Forwarded message --------- From: Eventbrite <noreply@order.eventbrite.com> Date: Mon, Aug 3, 2026 at 7:09 AM Subject: Your Tickets To: <me@example.com> Saturday, August 15, 2026 at 10:00 AM";
$stripped = MailIngest::stripForwardPreamble($fwd);
check('forward preamble date removed', !str_contains($stripped, 'Aug 3, 2026'));
check('forward preamble keeps event date', str_contains($stripped, 'August 15, 2026'));
checkEq('no preamble -> unchanged', 'plain text', MailIngest::stripForwardPreamble('plain text'));

check('date evidence: month name', MailIngest::hasDateEvidence('Saturday, August 15, 2026 at 10 AM'));
check('date evidence: slash date', MailIngest::hasDateEvidence('See you 8/15!'));
check('date evidence: none in link soup', !MailIngest::hasDateEvidence('Go to My Tickets Access your tickets in the app'));

$flat = MailIngest::flattenHtml('<html><style>.x{color:red}</style><body><p>Hello &amp; welcome</p><script>evil()</script><div>Aug 15</div></body></html>');
check('flatten drops style/script', !str_contains($flat, 'color:red') && !str_contains($flat, 'evil'));
check('flatten decodes entities', str_contains($flat, 'Hello & welcome'));
check('flatten keeps content', str_contains($flat, 'Aug 15'));

$reply = MailIngest::buildReplyIcs('abc-123@example.com', 'alice@example.com', 'owner@example.com', 'ACCEPTED', 2, 'Team sync', new DateTimeImmutable('2026-08-03T12:00:00Z'));
check('rsvp reply has METHOD', str_contains($reply, 'METHOD:REPLY'));
check('rsvp reply has partstat attendee', str_contains($reply, 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:owner@example.com'));
check('rsvp reply has organizer', str_contains($reply, 'ORGANIZER:mailto:alice@example.com'));
check('rsvp reply keeps sequence', str_contains($reply, 'SEQUENCE:2'));

// ---------------------------------------------------------------------------
// GcalLink — Google Calendar template link parsing (pure)
// ---------------------------------------------------------------------------

use BetterCal\Domain\GcalLink;

$lumaUrl = 'https://calendar.google.com/calendar/render?action=TEMPLATE&dates=20260809T160000Z%2F20260810T050000Z&details=Get%20up-to-date%20information%20at%3A%20https%3A%2F%2Fluma.com%2Fthe-noe6&location=540%20Laguna%20St%2C%20San%20Francisco%20%2B%20Full%20Space&text=The%20Commons%20Public%20Hours%20%E2%98%95';
check('gcal detects render links', GcalLink::isTemplateUrl($lumaUrl));
check('gcal detects eventedit links', GcalLink::isTemplateUrl('https://calendar.google.com/calendar/u/0/r/eventedit?text=Hi&dates=20260101/20260102'));
check('gcal detects with surrounding whitespace', GcalLink::isTemplateUrl('  ' . $lumaUrl . '  '));
check('gcal rejects plain text', !GcalLink::isTemplateUrl('Lunch with Ada Friday noon'));
check('gcal rejects other google urls', !GcalLink::isTemplateUrl('https://calendar.google.com/calendar/r?cid=abc'));

$g = GcalLink::parse($lumaUrl, 'America/Los_Angeles');
checkEq('gcal luma title', 'The Commons Public Hours ☕', $g['title']);
checkEq('gcal luma start (UTC->LA)', '2026-08-09T09:00:00-07:00', $g['start']);
checkEq('gcal luma end', '2026-08-09T22:00:00-07:00', $g['end']);
check('gcal luma is timed', !$g['allDay']);
checkEq('gcal luma location', '540 Laguna St, San Francisco + Full Space', $g['location']);
check('gcal luma description carries link', str_contains($g['description'], 'https://luma.com/the-noe6'));
checkEq('gcal luma source', 'gcal-link', $g['source']);

$g = GcalLink::parse('https://calendar.google.com/calendar/render?action=TEMPLATE&text=Offsite&dates=20260901/20260903', 'America/Los_Angeles');
check('gcal all-day range', $g['allDay']);
checkEq('gcal all-day start', '2026-09-01T00:00:00-07:00', $g['start']);
checkEq('gcal all-day exclusive end kept', '2026-09-03T00:00:00-07:00', $g['end']);

$g = GcalLink::parse('https://calendar.google.com/calendar/render?action=TEMPLATE&text=Call&dates=20260901T100000/20260901T110000&ctz=America/New_York', 'America/Los_Angeles');
checkEq('gcal naive times honor ctz', '2026-09-01T10:00:00-04:00', $g['start']);

$g = GcalLink::parse('https://calendar.google.com/calendar/render?action=TEMPLATE&text=Standup&dates=20260901T100000Z/20260901T103000Z&recur=RRULE:FREQ=WEEKLY;BYDAY=TU', 'America/Los_Angeles');
checkEq('gcal recur strips RRULE prefix', 'FREQ=WEEKLY;BYDAY=TU', $g['rrule']);

checkEq('gcal missing dates -> null', null, GcalLink::parse('https://calendar.google.com/calendar/render?action=TEMPLATE&text=NoDates', 'America/Los_Angeles'));

// ---------------------------------------------------------------------------
// QuickAdd::awayIntent — availability statement detection (pure)
// ---------------------------------------------------------------------------

$ai = QuickAdd::awayIntent('John is away Aug 10 to 15');
checkEq('qa away intent name', 'John', $ai['name']);
checkEq('qa away intent kind', 'away', $ai['kind']);
checkEq('qa away intent rest', 'Aug 10 to 15', $ai['rest']);
checkEq('qa away intent full name', 'Virginia Miller', QuickAdd::awayIntent('Virginia Miller will be out next week')['name']);
checkEq('qa busy stays busy', 'busy', QuickAdd::awayIntent('Sam is busy Friday')['kind']);
checkEq('qa here maps to here', 'here', QuickAdd::awayIntent('Ada is here next week')['kind']);
checkEq('qa in town maps to here', 'here', QuickAdd::awayIntent('Marcus in town Aug 10-15')['kind']);
checkEq('qa visiting maps to here', 'here', QuickAdd::awayIntent('Virginia is visiting Friday')['kind']);
checkEq('qa back maps to here', 'here', QuickAdd::awayIntent('John is back Monday')['kind']);
checkEq('qa traveling means away', 'away', QuickAdd::awayIntent('Ada traveling Sep 1-5')['kind']);
checkEq('qa bare gone form parses', 'Sam', QuickAdd::awayIntent('Sam gone Tuesday to Friday')['name']);
checkEq('qa event text with digits is not an intent', null, QuickAdd::awayIntent('Checkout 3pm gone wrong'));
checkEq('qa plain event is not an intent', null, QuickAdd::awayIntent('Dinner with Sam Friday 7pm'));

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
// QuickAdd::mergeLlm — deterministic guard over LLM drafts (pure)
// ---------------------------------------------------------------------------

$mergeNow = new DateTimeImmutable('2026-08-03T12:00:00-07:00');
$mkLlm = static fn(array $over = []) => $over + [
    'title' => 'Cocktails', 'start' => '2026-08-04T16:00:00-07:00', 'end' => '2026-08-04T17:00:00-07:00',
    'allDay' => false, 'location' => null, 'personNames' => [],
];
$mkFb = static fn(array $over = []) => $over + [
    'title' => 'Cocktails with Virginia', 'start' => '2026-08-03T16:00:00-07:00', 'end' => '2026-08-03T17:00:00-07:00',
    'allDay' => false, 'location' => null, 'personNames' => ['Virginia'],
    'confidence' => 0.6, 'source' => 'fallback', 'complete' => false, 'dateFound' => false,
];

$m = QuickAdd::mergeLlm($mkLlm(['start' => '2026-08-01T16:00:00-07:00', 'end' => '2026-08-01T17:00:00-07:00']), $mkFb(), $mergeNow);
checkEq('qam past LLM start without explicit date takes fallback times', '2026-08-03T16:00:00-07:00', $m['start']);
checkEq('qam past-start guard carries fallback end', '2026-08-03T17:00:00-07:00', $m['end']);

$m = QuickAdd::mergeLlm($mkLlm(['start' => '2026-08-01T16:00:00-07:00']), $mkFb(['dateFound' => true, 'start' => '2026-08-01T16:00:00-07:00']), $mergeNow);
checkEq('qam explicit past date is honored', '2026-08-01T16:00:00-07:00', $m['start']);

$m = QuickAdd::mergeLlm($mkLlm(['start' => 'garbage']), $mkFb(), $mergeNow);
checkEq('qam unparseable LLM start falls back', '2026-08-03T16:00:00-07:00', $m['start']);

$m = QuickAdd::mergeLlm($mkLlm(), $mkFb(), $mergeNow);
checkEq('qam future LLM start is kept', '2026-08-04T16:00:00-07:00', $m['start']);
checkEq('qam stripped with-clause restores fallback title', 'Cocktails with Virginia', $m['title']);
checkEq('qam personNames union pulls fallback people', ['Virginia'], $m['personNames']);

$m = QuickAdd::mergeLlm($mkLlm(['title' => 'Cocktails with Virginia Miller', 'personNames' => ['Virginia Miller']]), $mkFb(), $mergeNow);
checkEq('qam LLM title with with-clause is kept', 'Cocktails with Virginia Miller', $m['title']);
checkEq('qam union dedupes case-insensitively but keeps distinct names', ['Virginia Miller', 'Virginia'], $m['personNames']);

$m = QuickAdd::mergeLlm($mkLlm(), $mkFb(['location' => 'Zuni Cafe']), $mergeNow);
checkEq('qam location backfills from fallback', 'Zuni Cafe', $m['location']);
$m = QuickAdd::mergeLlm($mkLlm(['location' => 'Tartine']), $mkFb(['location' => 'Zuni Cafe']), $mergeNow);
checkEq('qam LLM location wins when present', 'Tartine', $m['location']);

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

// --- DTSTART skip-ahead (GH #10) -------------------------------------------
// The optimisation is only sound if the instants are IDENTICAL with and
// without it, so the real test is differential: expand each rule both ways
// through the same sabre iterator and require the same list back. Eligibility
// mistakes are caught by the checks further down; a wrong TIME would only ever
// be caught here.
if (class_exists(\Sabre\VObject\Component\VCalendar::class)) {
    $expandBoth = static function (string $rrule, string $startDb, string $tzid, bool $allDay,
                                   string $winStartDb, string $winEndDb): array {
        $mk = static function (bool $useSkip) use ($rrule, $startDb, $tzid, $allDay, $winStartDb, $winEndDb): array {
            $tz = Time::zone($tzid);
            $s = Time::fromDb($startDb)->setTimezone($tz);
            $e = $s->add(new DateInterval('PT1H'));
            $winStart = Time::fromDb($winStartDb);
            $winEnd = Time::fromDb($winEndDb);
            if ($useSkip) {
                $skip = Recurrence::skippablePeriods($rrule, $s, $winStart->setTimezone($tz));
                if ($skip > 0) {
                    preg_match('/INTERVAL=(\d+)/', strtoupper($rrule), $mm);
                    $iv = max(1, (int) ($mm[1] ?? 1));
                    $per = str_contains(strtoupper($rrule), 'FREQ=WEEKLY') ? 7 : 1;
                    $shift = new DateInterval('P' . ($skip * $iv * $per) . 'D');
                    $s = $s->add($shift);
                    $e = $e->add($shift);
                }
            }
            $vcal = new \Sabre\VObject\Component\VCalendar();
            $ve = $vcal->add('VEVENT', ['UID' => 'diff']);
            if ($allDay) {
                $ve->add('DTSTART', $s->format('Ymd'), ['VALUE' => 'DATE']);
                $ve->add('DTEND', $e->format('Ymd'), ['VALUE' => 'DATE']);
            } else {
                $ve->add('DTSTART', $s);
                $ve->add('DTEND', $e);
            }
            $ve->add('RRULE', $rrule);
            $it = new \Sabre\VObject\Recur\EventIterator($vcal, 'diff', Time::utc());
            $it->fastForward(\DateTime::createFromImmutable($winStart));
            $out = [];
            $n = 0;
            while ($it->valid() && $n < 400) {
                $os = \DateTimeImmutable::createFromInterface($it->getDtStart())->setTimezone(Time::utc());
                if ($os >= $winEnd) {
                    break;
                }
                $out[] = $os->format('Y-m-d H:i:s');
                $n++;
                $it->next();
            }
            return $out;
        };
        return [$mk(false), $mk(true)];
    };

    // Seven-year-old series are the case that motivated this. Windows include
    // one straddling each DST transition in the event's own zone, since the
    // shift is done in local time precisely so wall clock survives them.
    $skipCases = [
        ['FREQ=DAILY;INTERVAL=1', '2019-03-04 17:00:00', 'America/Los_Angeles', false],
        ['FREQ=DAILY;INTERVAL=3', '2019-03-04 17:00:00', 'America/Los_Angeles', false],
        ['FREQ=WEEKLY;INTERVAL=1;BYDAY=FR', '2019-03-04 17:00:00', 'America/Los_Angeles', false],
        ['FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE', '2019-03-04 17:00:00', 'America/Los_Angeles', false],
        ['FREQ=WEEKLY;INTERVAL=1;BYDAY=MO,TU,WE,TH,FR', '2019-03-04 17:00:00', 'America/New_York', false],
        ['FREQ=DAILY;INTERVAL=1', '2019-03-04 00:00:00', 'America/Los_Angeles', true],
        ['FREQ=WEEKLY;INTERVAL=1;BYDAY=SU', '2019-03-04 00:00:00', 'America/Los_Angeles', true],
        ['FREQ=DAILY;INTERVAL=1;UNTIL=20261015T000000Z', '2019-03-04 17:00:00', 'America/Los_Angeles', false],
        ['FREQ=DAILY;INTERVAL=1', '2019-03-04 09:30:00', 'America/Los_Angeles', false],
    ];
    $skipWindows = [
        ['2026-08-01 00:00:00', '2027-01-01 00:00:00'],
        ['2026-03-01 00:00:00', '2026-04-01 00:00:00'],
        ['2026-10-25 00:00:00', '2026-11-08 00:00:00'],
    ];
    foreach ($skipCases as [$rr, $sd, $tzid, $ad]) {
        foreach ($skipWindows as [$ws, $we]) {
            [$plain, $skipped] = $expandBoth($rr, $sd, $tzid, $ad, $ws, $we);
            checkEq("skip-ahead identical: $rr @ $ws", $plain, $skipped);
            // A rule whose UNTIL precedes the window legitimately yields
            // nothing; everywhere else an empty list would mean the skip ate
            // the series, so assert non-empty only where output is expected.
            preg_match('/UNTIL=(\d{8})/', $rr, $um);
            $endsBeforeWindow = isset($um[1]) && $um[1] < str_replace('-', '', substr($ws, 0, 10));
            if (!$endsBeforeWindow) {
                check("skip-ahead still yields occurrences: $rr @ $ws", count($plain) > 0);
            }
        }
    }
}

// Eligibility: only DAILY/WEEKLY, never COUNT, never backwards.
$farStart = Time::fromDb('2019-03-04 17:00:00');
$skipTarget = Time::fromDb('2026-08-01 00:00:00');
check('skip: old daily series skips thousands', Recurrence::skippablePeriods('FREQ=DAILY', $farStart, $skipTarget) > 2000);
// F3/F4 (scan 2026-09-23): an absurd INTERVAL is refused at the door and
// survives expansion if it was stored before that.
checkEq('safeRrule: ordinary rule passes, uppercased', 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO', Recurrence::safeRrule('freq=weekly;interval=2;byday=MO'));
checkEq('safeRrule: absurd INTERVAL drops the recurrence', null, Recurrence::safeRrule('FREQ=WEEKLY;INTERVAL=99999999999999999999'));
checkEq('safeRrule: INTERVAL over the cap drops it', null, Recurrence::safeRrule('FREQ=DAILY;INTERVAL=1001'));
checkEq('safeRrule: absurd COUNT drops it', null, Recurrence::safeRrule('FREQ=DAILY;COUNT=999999999'));
checkEq('safeRrule: non-numeric INTERVAL drops it', null, Recurrence::safeRrule('FREQ=DAILY;INTERVAL=abc'));
checkEq('safeRrule: empty is no rule', null, Recurrence::safeRrule('  '));
checkEq('skip: a stored absurd INTERVAL skips nothing and does not throw', 0, Recurrence::skippablePeriods('FREQ=WEEKLY;INTERVAL=99999999999999999999', $farStart, $skipTarget));
{
    $boom = new Recurrence(static function (): array { throw new \TypeError('intdiv(): Argument #2 must be of type int, float given'); });
    $m = ['id' => 9, 'rrule' => 'FREQ=WEEKLY', 'start_utc' => '2026-09-21 10:00:00', 'end_utc' => '2026-09-21 11:00:00'];
    $got = $boom->expand($m, [], new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')), new DateTimeImmutable('2026-09-27', new DateTimeZone('UTC')));
    checkEq('expand: an expander that throws yields the first occurrence, not a failed window', 1, count($got));
}
check('skip: old weekly series skips hundreds', Recurrence::skippablePeriods('FREQ=WEEKLY;BYDAY=MO', $farStart, $skipTarget) > 300);
checkEq('skip: COUNT is never moved', 0, Recurrence::skippablePeriods('FREQ=DAILY;COUNT=10', $farStart, $skipTarget));
checkEq('skip: MONTHLY is left alone', 0, Recurrence::skippablePeriods('FREQ=MONTHLY', $farStart, $skipTarget));
checkEq('skip: YEARLY is left alone', 0, Recurrence::skippablePeriods('FREQ=YEARLY', $farStart, $skipTarget));
checkEq('skip: target before start does nothing', 0, Recurrence::skippablePeriods('FREQ=DAILY', $skipTarget, $farStart));
checkEq('skip: interval divides the distance', 2,
    Recurrence::skippablePeriods('FREQ=DAILY;INTERVAL=10', Time::fromDb('2026-01-01 00:00:00'), Time::fromDb('2026-02-01 00:00:00')));
$skipS0 = Time::fromDb('2026-01-01 09:00:00');
$skipT0 = Time::fromDb('2026-03-01 09:00:00');
check('skip: lands at or before the target, never past it',
    $skipS0->add(new DateInterval('P' . Recurrence::skippablePeriods('FREQ=DAILY', $skipS0, $skipT0) . 'D')) <= $skipT0);

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

// Tags field: opt-in matching over the event's tag name list.
$occTagged = ['calendar_id' => 3, 'title' => 'Practice', 'tags' => ['work', 'Deep Focus']];
check('flt tags keyword matches tag name', Filters::evaluate($occTagged, $kw('work', ['tags'])));
check('flt tags keyword case-insensitive substring', Filters::evaluate($occTagged, $kw('focus', ['tags'])));
check('flt tags no match', !Filters::evaluate($occTagged, $kw('yoga', ['tags'])));
check('flt tags regex matches', Filters::evaluate($occTagged, $rx('^deep', ['tags'])));
check('flt default fields exclude tags', !Filters::evaluate($occTagged, $kw('work')));
check('flt tags missing key never matches', !Filters::evaluate($occ, $kw('work', ['tags'])));
check('flt tags plus title field still matches title', Filters::evaluate($occTagged, $kw('practice', ['title', 'tags'])));
checkEq('flt tags accepted in config fields', ['tags'], Filters::validateConfig('keyword', ['pattern' => 'x', 'fields' => ['tags']])['fields']);
checkEq('flt config default fields stay three', ['title', 'description', 'location'], Filters::validateConfig('keyword', ['pattern' => 'x'])['fields']);
check('flt anyUsesTags true', Filters::anyUsesTags([['config' => ['pattern' => 'x', 'fields' => ['title', 'tags']]]]));
check('flt anyUsesTags false', !Filters::anyUsesTags([['config' => ['pattern' => 'x', 'fields' => ['title']]], ['config' => ['pattern' => 'y']]]));

// disposition: calendar scoping and hide > dim > highlight precedence.
$hideAll = ['type' => 'keyword', 'config' => ['pattern' => 'yoga'], 'action' => 'hide', 'calendarIds' => null];
$dimAll = ['type' => 'keyword', 'config' => ['pattern' => 'yoga'], 'action' => 'dim', 'calendarIds' => null];
$hlAll = ['type' => 'keyword', 'config' => ['pattern' => 'yoga'], 'action' => 'highlight', 'calendarIds' => null];
$hideCal9 = ['type' => 'keyword', 'config' => ['pattern' => 'yoga'], 'action' => 'hide', 'calendarIds' => [9 => true]];
checkEq('flt disposition global hide', 'hide', Filters::disposition($occ, [$hideAll]));
checkEq('flt disposition global dim', 'dim', Filters::disposition($occ, [$dimAll]));
checkEq('flt disposition global highlight', 'highlight', Filters::disposition($occ, [$hlAll]));
checkEq('flt disposition hide beats dim', 'hide', Filters::disposition($occ, [$dimAll, $hideAll]));
checkEq('flt disposition dim beats highlight', 'dim', Filters::disposition($occ, [$hlAll, $dimAll]));
checkEq('flt disposition hide beats highlight', 'hide', Filters::disposition($occ, [$hlAll, $hideAll]));
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
$pfHl = ['id' => 4, 'action' => 'highlight', 'calendarIds' => null];
$failAllHl = $failAll + [4 => [101 => true]];
checkEq('pd fail -> highlight', 'highlight', Filters::promptDisposition($promptRow, [$pfHl], $failAllHl));
checkEq('pd dim beats highlight', 'dim', Filters::promptDisposition($promptRow, [$pfHl, $pfDim], $failAllHl));
checkEq('strongest hide wins', 'hide', Filters::strongest('dim', 'hide'));
checkEq('strongest dim over null', 'dim', Filters::strongest(null, 'dim'));
checkEq('strongest dim over highlight', 'dim', Filters::strongest('highlight', 'dim'));
checkEq('strongest hide over highlight', 'hide', Filters::strongest('highlight', 'hide'));
checkEq('strongest highlight over null', 'highlight', Filters::strongest(null, 'highlight'));
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
check('geo hash differs across bias regions', Geocode::queryHash('SFO', 37.77, -122.42) !== Geocode::queryHash('SFO', 55.68, 12.57));
checkEq('geo hash stable within a bias cell', Geocode::queryHash('SFO', 37.61, -122.38), Geocode::queryHash('SFO', 37.77, -122.42));
check('geo hash unbiased differs from biased', Geocode::queryHash('SFO') !== Geocode::queryHash('SFO', 37.77, -122.42));
// PluginHost::geocode() shipped broken for the whole of v1/v2: it indexed
// lookup()'s answer as $hits[0], but lookup() returns a single {lat,lng,display}
// map with no key 0, so every plugin geocode silently returned null. Two
// independent plugin authors hit it. Pin the return shape so the list/map
// confusion cannot come back.
$geoShape = Geocode::mapResponse(['features' => [[
    'geometry' => ['coordinates' => [-122.513625, 37.780252]],
    'properties' => ['name' => 'Sutro Baths', 'city' => 'San Francisco', 'country' => 'United States'],
]]]);
check('geo lookup answers a map, not a hit list', !array_is_list($geoShape));
check('geo lookup map has no index 0 to read', !isset($geoShape[0]));
check('geo lookup map carries lat/lng directly', isset($geoShape['lat'], $geoShape['lng']));
checkEq('geo bias cell rounds to integer degrees', '38,-122', Geocode::biasCell(37.77, -122.42));
checkEq('geo bias cell none without bias', 'none', Geocode::biasCell(null, null));
// Picking among same-named places. The primary provider orders "Lisbon" as
// eight American towns; significance ranking has to reach past them, without
// disturbing addresses and venues, where the provider's own order is right.
$feat = static fn(string $name, string $key, string $value): array => [
    'geometry' => ['coordinates' => [1.0, 2.0]],
    'properties' => ['name' => $name, 'osm_key' => $key, 'osm_value' => $value],
];
$lisbons = ['features' => [
    $feat('Lisbon', 'place', 'town'),
    $feat('Lisbon', 'place', 'village'),
    $feat('Lisbon', 'place', 'city'),
    $feat('Lisbon', 'place', 'hamlet'),
]];
checkEq('geo pick: the city wins among same-named places', 'city',
    Geocode::pickFeature($lisbons, 'Lisbon')['properties']['osm_value']);
checkEq('geo pick: matching is case and space insensitive', 'city',
    Geocode::pickFeature($lisbons, '  lisbon ')['properties']['osm_value']);
// A city outranks a county of the same name: someone typing "Florence" means
// the city, not the county wrapped around a different one.
checkEq('geo pick: city outranks county', 'city', Geocode::pickFeature(['features' => [
    $feat('Florence', 'place', 'county'), $feat('Florence', 'place', 'city'),
]], 'Florence')['properties']['osm_value']);
// No exact-name candidate: an address or venue. Provider order must stand.
checkEq('geo pick: address keeps provider order', 'Zuni Café', Geocode::pickFeature(['features' => [
    $feat('Zuni Café', 'amenity', 'restaurant'), $feat('Market Street', 'highway', 'secondary'),
]], '1658 Market St, San Francisco')['properties']['name']);
check('geo pick: empty feature list yields null', Geocode::pickFeature(['features' => []], 'x') === null);
check('geo pick: junk payload yields null', Geocode::pickFeature('nonsense', 'x') === null);

// When to ask a second provider. Narrow on purpose.
check('geo doubt: a town invites a second opinion',
    Geocode::shouldConsultSecondary($feat('Lisbon', 'place', 'town')));
check('geo doubt: a city invites one too (small US "cities" outrank capitals)',
    Geocode::shouldConsultSecondary($feat('Florence', 'place', 'city')));
check('geo doubt: a state does not',
    !Geocode::shouldConsultSecondary($feat('Hawaii', 'place', 'state')));
check('geo doubt: a country does not',
    !Geocode::shouldConsultSecondary($feat('Portugal', 'place', 'country')));
check('geo doubt: a venue never does',
    !Geocode::shouldConsultSecondary($feat('Zuni Café', 'amenity', 'restaurant')));
check('geo doubt: a street never does',
    !Geocode::shouldConsultSecondary($feat('Market Street', 'highway', 'secondary')));
check('geo doubt: nothing found invites one', Geocode::shouldConsultSecondary(null));

// The secondary may only override with a genuinely major place, which is what
// stops it replacing a correct "Hawaii, United States" with a Guatemalan village.
checkEq('geo secondary: a major city overrides', 'Lisbon, Lisbon District, Portugal',
    Geocode::secondaryOverride(['results' => [
        ['name' => 'Lisbon', 'admin1' => 'Lisbon District', 'country' => 'Portugal',
         'latitude' => 38.72, 'longitude' => -9.14, 'population' => 517802],
    ]])['display']);
check('geo secondary: a small place does not override',
    Geocode::secondaryOverride(['results' => [
        ['name' => 'Hawaii', 'country' => 'Guatemala', 'latitude' => 14.0, 'longitude' => -90.8],
    ]]) === null);
check('geo secondary: a sub-threshold population does not override',
    Geocode::secondaryOverride(['results' => [
        ['name' => 'Kailua-Kona', 'country' => 'United States',
         'latitude' => 19.6, 'longitude' => -156.0, 'population' => 11975],
    ]]) === null);
check('geo secondary: it skips small hits to find a major one',
    Geocode::secondaryOverride(['results' => [
        ['name' => 'Small', 'latitude' => 1, 'longitude' => 1, 'population' => 200],
        ['name' => 'Munich', 'admin1' => 'Bavaria', 'country' => 'Germany',
         'latitude' => 48.1, 'longitude' => 11.6, 'population' => 1260391],
    ]])['display'] === 'Munich, Bavaria, Germany');
check('geo secondary: junk yields null', Geocode::secondaryOverride('nope') === null);
check('geo secondary: no results yields null', Geocode::secondaryOverride(['results' => []]) === null);

check('geo airport code: SFO', Geocode::isAirportCode('SFO'));
check('geo airport code: trims whitespace', Geocode::isAirportCode(' KOA '));
check('geo airport code: lowercase is not one', !Geocode::isAirportCode('sfo'));
check('geo airport code: mixed case is not one', !Geocode::isAirportCode('Gym'));
check('geo airport code: longer text is not one', !Geocode::isAirportCode('SFO Airport'));
check('geo airport code: digits are not one', !Geocode::isAirportCode('SF1'));
$sfoAirport = Geocode::airport('SFO');
check('geo airport SFO is in the Bay Area', $sfoAirport !== null && abs($sfoAirport['lat'] - 37.62) < 0.1 && abs($sfoAirport['lng'] + 122.37) < 0.1);
checkEq('geo airport SFO display', 'San Francisco International Airport, San Francisco, US', $sfoAirport['display']);
$koaAirport = Geocode::airport('KOA');
check('geo airport KOA is on the Big Island', $koaAirport !== null && abs($koaAirport['lat'] - 19.74) < 0.1 && abs($koaAirport['lng'] + 156.05) < 0.1);
checkEq('geo airport unknown code -> null', null, Geocode::airport('QQZ'));
checkEq('geo airport non-code -> null', null, Geocode::airport('Zuni Cafe'));

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

checkEq('geo negative cache row -> all-null result', ['lat' => null, 'lng' => null, 'display' => null, 'kind' => null], Geocode::resultFromRow(['lat' => null, 'lng' => null, 'display' => null]));
checkEq(
    'geo positive cache row round-trips',
    ['lat' => 37.7739, 'lng' => -122.4216, 'display' => 'Zuni Cafe', 'kind' => 'restaurant'],
    Geocode::resultFromRow(['lat' => '37.7739', 'lng' => '-122.4216', 'display' => 'Zuni Cafe', 'kind' => 'restaurant'])
);
// A row cached before the kind column existed still round-trips, as null.
checkEq(
    'geo pre-kind cache row round-trips with a null kind',
    ['lat' => 1.0, 'lng' => 2.0, 'display' => 'Somewhere', 'kind' => null],
    Geocode::resultFromRow(['lat' => '1.0', 'lng' => '2.0', 'display' => 'Somewhere'])
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
checkEq('set validate defaultViewId', ['defaultViewId' => 5], Settings::validate(['defaultViewId' => '5']));
checkEq('set validate defaultViewId null', ['defaultViewId' => null], Settings::validate(['defaultViewId' => null]));
checkEq('set validate nlParseMode', ['nlParseMode' => 'never'], Settings::validate(['nlParseMode' => 'never']));
checkEq('set validate overviewMode 3day', ['overviewMode' => '3day'], Settings::validate(['overviewMode' => '3day']));
checkEq('set validate overviewMode month', ['overviewMode' => 'month'], Settings::validate(['overviewMode' => 'month']));
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
    Settings::validate(['overviewMode' => 'week']);
    check('set bad overviewMode rejected', false);
} catch (HttpError $e) {
    checkEq('set bad overviewMode status', 400, $e->status);
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
$gw = new LlmGateway(['gemini' => ['key' => 'test-key', 'model' => 'gemini-test-model']], $fakeTransport);
$batchEvent = ['eventId' => 1, 'title' => 'Salsa Night', 'description' => null, 'location' => null, 'start' => '2026-08-01T19:00:00-07:00'];

$fakeTransport->reply = $envelope(['results' => [['eventId' => 1, 'pass' => true, 'score' => 0.8]]]);
checkEq(
    'gw eval batch parses results',
    [['eventId' => 1, 'pass' => true, 'score' => 0.8]],
    $gw->evaluateFilterBatch('dance events', 'no webinars', [$batchEvent])
);
check('gw eval sent prompt to model', str_contains((string) $fakeTransport->requests[0]['body'], 'dance events'));
check('gw eval sent negative prompt', str_contains((string) $fakeTransport->requests[0]['body'], 'no webinars'));
{
    // F19 (scan 2026-09-23): the user's instructions and the third-party event text travel apart.
    $sent = json_decode((string) $fakeTransport->requests[0]['body'], true);
    check('gw eval: instructions are the system instruction', str_contains((string) ($sent['system_instruction']['parts'][0]['text'] ?? ''), 'dance events'));
    check('gw eval: event text is its own user turn, marked untrusted', str_contains((string) ($sent['contents'][0]['parts'][0]['text'] ?? ''), 'untrusted') && str_contains((string) ($sent['contents'][0]['parts'][0]['text'] ?? ''), 'Salsa Night'));
    check('gw eval: event text is not in the instructions', !str_contains((string) ($sent['system_instruction']['parts'][0]['text'] ?? ''), 'Salsa Night'));
    $gemma = new LlmGateway(['gemini' => ['key' => 'k', 'model' => 'gemma-3-27b-it']], $fakeTransport);
    $fakeTransport->reply = $envelope(['results' => []]);
    $gemma->evaluateFilterBatch('dance events', null, [$batchEvent]);
    $sentG = json_decode((string) end($fakeTransport->requests)['body'], true);
    check('gw eval: a non-Gemini model gets no system_instruction, two parts instead', !isset($sentG['system_instruction']) && count($sentG['contents'][0]['parts'] ?? []) === 2);
}

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
// F8 (scan 2026-09-23): a feed poll is bounded by the same memory-aware budget as an import.
checkEq('feed budget: capped by memory like an import', 100, BetterCal\Support\Limits::feedEventBudget('32M', 30 * 1048576));
check('ics budget: a folded BEGIN:VEVENT is still counted', BetterCal\Domain\Ics::budgetProblem("BEGIN:VCALENDAR\r\n" . str_repeat("BEGIN:VEV\r\n ENT\r\nEND:VEVENT\r\n", 3) . "END:VCALENDAR\r\n", 1 << 20, 2) !== null);
check('ics budget: extra carriage returns do not hide events', BetterCal\Domain\Ics::budgetProblem("BEGIN:VCALENDAR\r\n" . str_repeat("BEGIN:VEVENT\r\r\nEND:VEVENT\r\n", 3) . "END:VCALENDAR\r\n", 1 << 20, 2) !== null);
check('ics budget: lone-CR line endings are counted too', BetterCal\Domain\Ics::budgetProblem("BEGIN:VCALENDAR\r" . str_repeat("BEGIN:VEVENT\rEND:VEVENT\r", 3) . "END:VCALENDAR\r", 1 << 20, 2) !== null);
check('ics budget: one event with a flood of lines is refused', BetterCal\Domain\Ics::budgetProblem("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\n" . str_repeat("X-A:1\r\n", 2000) . "END:VEVENT\r\nEND:VCALENDAR\r\n", 1 << 20, 10) !== null);
checkEq('ics budget: an ordinary event passes', null, BetterCal\Domain\Ics::budgetProblem("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:a\r\nSUMMARY:x\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", 1 << 20, 1));
checkEq('feed budget: FEED_EVENTS when memory is plentiful', BetterCal\Support\Limits::get('FEED_EVENTS'), BetterCal\Support\Limits::feedEventBudget('-1', 0));
checkEq('dav uid from object uri', 'ABC123', DavIcs::uidFromObjectUri('ABC123.ics'));
$ulidUid = Ids::ulid();
checkEq('dav uid/uri round trip', $ulidUid, DavIcs::uidFromObjectUri(DavIcs::objectUri($ulidUid)));
checkEq('dav uid requires .ics suffix', null, DavIcs::uidFromObjectUri('ABC123.txt'));
checkEq('dav uid rejects empty stem', null, DavIcs::uidFromObjectUri('.ics'));
// F7 (scan 2026-09-23): a UID from outside never becomes a raw path segment.
checkEq('dav: an ordinary UID keeps its plain object name', 'abc-123@example.com.ics', DavIcs::objectUri('abc-123@example.com'));
checkEq('dav: a UID with + keeps its plain name (no churn for existing events)', 'a1b2+x=y@host.ics', DavIcs::objectUri('a1b2+x=y@host'));
foreach (['evil/x', '../../etc', 'back\\slash', 'b64-looks-encoded', '.', '..'] as $odd) {
    $uri = DavIcs::objectUri($odd);
    check('dav: path-breaking UID ' . json_encode($odd) . ' is encoded into one segment', str_starts_with($uri, 'b64-') && strpbrk($uri, '/\\') === false);
    checkEq('dav: path-breaking UID ' . json_encode($odd) . ' round-trips', $odd, DavIcs::uidFromObjectUri($uri));
}
checkEq('dav: a b64 name from 0.1.3-0.1.5 still resolves', 'urn:uuid:1234', DavIcs::uidFromObjectUri('b64-' . rtrim(strtr(base64_encode('urn:uuid:1234'), '+/', '-_'), '=') . '.ics'));
foreach (['with space', 'pct%2F', 'q?x#y', 'ünïcödé', 'urn:uuid:1234', '{braced}'] as $plain) {
    checkEq('dav: harmless UID ' . json_encode($plain) . ' keeps its name (no href churn)', $plain . '.ics', DavIcs::objectUri($plain));
}
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
// Reminders: validation, effective resolution, fire-time math, dedup keys,
// VALARM trigger mapping, push subscription validation (pure, no DB)
// ---------------------------------------------------------------------------

use BetterCal\Domain\PushSubscriptions;
use BetterCal\Domain\Reminders;

// Validation: event overrides normalize (unique, ascending); null inherits.
checkEq('rem override null inherits', null, Reminders::validateEventReminders(null));
checkEq('rem override empty list allowed', [], Reminders::validateEventReminders([]));
checkEq(
    'rem override normalized unique ascending',
    [['minutes' => 5], ['minutes' => 30]],
    Reminders::validateEventReminders([['minutes' => 30], ['minutes' => 5], ['minutes' => 5]])
);
foreach ([[['minutes' => -1]], [['minutes' => 999999]], 'nope', [['mins' => 5]]] as $bad) {
    try {
        Reminders::validateEventReminders($bad);
        check('rem override rejects bad input', false);
    } catch (HttpError $e) {
        checkEq('rem override bad input code', 'invalid_reminders', $e->errorCode);
    }
}
checkEq(
    'rem allday list normalized',
    [['daysBefore' => 1, 'time' => '18:00'], ['daysBefore' => 0, 'time' => '09:05']],
    Reminders::validateAllDayList([
        ['daysBefore' => 1, 'time' => '18:00'],
        ['daysBefore' => 0, 'time' => '9:05'],
        ['daysBefore' => 1, 'time' => '18:00'], // duplicate dropped
    ])
);
foreach ([[['daysBefore' => 30, 'time' => '10:00']], [['daysBefore' => 1, 'time' => '25:00']], [['daysBefore' => 1]]] as $bad) {
    try {
        Reminders::validateAllDayList($bad);
        check('rem allday rejects bad input', false);
    } catch (HttpError $e) {
        checkEq('rem allday bad input code', 'invalid_reminders', $e->errorCode);
    }
}
// Extended ranges: up to 4 weeks (40320 minutes / 28 daysBefore).
checkEq('rem range constants', [40320, 28], [Reminders::MAX_MINUTES, Reminders::MAX_DAYS_BEFORE]);
checkEq('rem override 4 weeks accepted', [['minutes' => 40320]], Reminders::validateEventReminders([['minutes' => 40320]]));
try {
    Reminders::validateEventReminders([['minutes' => 40321]]);
    check('rem override beyond 4 weeks rejected', false);
} catch (HttpError $e) {
    checkEq('rem override beyond 4 weeks code', 'invalid_reminders', $e->errorCode);
}
checkEq(
    'rem allday daysBefore 28 accepted',
    [['daysBefore' => 28, 'time' => '09:00']],
    Reminders::validateAllDayList([['daysBefore' => 28, 'time' => '9:00']])
);

$defaults = Reminders::validateDefaults(['timed' => [['minutes' => 15]]]);
checkEq('rem defaults missing key is none', [], $defaults['allDay']);
checkEq('rem defaults timed kept', [['minutes' => 15]], $defaults['timed']);
checkEq('rem defaults null clears', null, Reminders::validateDefaults(null));
try {
    Reminders::validateDefaults(['weird' => []]);
    check('rem defaults rejects unknown key', false);
} catch (HttpError $e) {
    checkEq('rem defaults unknown key code', 'invalid_reminders', $e->errorCode);
}

// Effective resolution order: event > calendar > global.
$gTimed = [['minutes' => 10]];
$gAllDay = [['daysBefore' => 1, 'time' => '18:00']];
$calDef = ['timed' => [['minutes' => 30]], 'allDay' => [['daysBefore' => 2, 'time' => '08:00']]];
checkEq('rem effective event wins', [[['minutes' => 5]], 'event'],
    Reminders::effective([['minutes' => 5]], $calDef, $gTimed, $gAllDay, false));
checkEq('rem effective explicit none stays event', [[], 'event'],
    Reminders::effective([], $calDef, $gTimed, $gAllDay, false));
checkEq('rem effective calendar over global', [[['minutes' => 30]], 'calendar'],
    Reminders::effective(null, $calDef, $gTimed, $gAllDay, false));
checkEq('rem effective calendar allday branch', [[['daysBefore' => 2, 'time' => '08:00']], 'calendar'],
    Reminders::effective(null, $calDef, $gTimed, $gAllDay, true));
checkEq('rem effective global timed fallback', [$gTimed, 'default'],
    Reminders::effective(null, null, $gTimed, $gAllDay, false));
checkEq('rem effective global allday fallback', [$gAllDay, 'default'],
    Reminders::effective(null, null, $gTimed, $gAllDay, true));
checkEq('rem effective subscribed never inherits global', [[], 'default'],
    Reminders::effective(null, null, $gTimed, $gAllDay, false, 'subscribed'));
checkEq('rem effective subscribed calendar default applies', [[['minutes' => 30]], 'calendar'],
    Reminders::effective(null, $calDef, $gTimed, $gAllDay, false, 'subscribed'));

// Fire-time math. Timed: minutes before the start instant.
$startUtc = Time::fromDb('2026-08-07 19:00:00'); // noon LA
checkEq('rem fire timed 10min', '2026-08-07 18:50:00',
    Time::toDb(Reminders::fireAt(['minutes' => 10], $startUtc, false, 'America/Los_Angeles')));
checkEq('rem fire timed zero offset', '2026-08-07 19:00:00',
    Time::toDb(Reminders::fireAt(['minutes' => 0], $startUtc, false, 'America/Los_Angeles')));

// All-day daysBefore/time fires in the EVENT's timezone (stored start is
// local midnight: 2026-08-07 in LA = 07:00 UTC).
$allDayLa = Time::fromDb('2026-08-07 07:00:00');
checkEq('rem fire allday day before 18:00 LA', '2026-08-07 01:00:00',
    Time::toDb(Reminders::fireAt(['daysBefore' => 1, 'time' => '18:00'], $allDayLa, true, 'America/Los_Angeles')));
checkEq('rem fire allday same day 09:00 LA', '2026-08-07 16:00:00',
    Time::toDb(Reminders::fireAt(['daysBefore' => 0, 'time' => '09:00'], $allDayLa, true, 'America/Los_Angeles')));
// Same calendar date in Tokyo (midnight = 2026-08-06 15:00 UTC): day before
// 18:00 JST = 2026-08-06 09:00 UTC.
$allDayTokyo = Time::fromDb('2026-08-06 15:00:00');
checkEq('rem fire allday day before 18:00 Tokyo', '2026-08-06 09:00:00',
    Time::toDb(Reminders::fireAt(['daysBefore' => 1, 'time' => '18:00'], $allDayTokyo, true, 'Asia/Tokyo')));
// Minutes offsets on all-day events count back from local midnight.
checkEq('rem fire allday minutes before midnight', '2026-08-07 01:00:00',
    Time::toDb(Reminders::fireAt(['minutes' => 360], $allDayLa, true, 'America/Los_Angeles')));
checkEq('rem fire garbage entry null', null, Reminders::fireAt(['bogus' => true], $startUtc, false, 'UTC'));

// With a Home zone, all-day reminder TIMES are on the owner's clock while the
// DATE still comes from the event. The case that mattered: an all-day event
// imported as UTC (Aug 7 = 2026-08-07 00:00Z) used to remind "the day before
// at 18:00" at 18:00 UTC, which is 11 AM for an owner in California.
$allDayUtc = Time::fromDb('2026-08-07 00:00:00');
checkEq('rem fire allday UTC event, no home zone: event clock (old behaviour kept)', '2026-08-06 18:00:00',
    Time::toDb(Reminders::fireAt(['daysBefore' => 1, 'time' => '18:00'], $allDayUtc, true, 'UTC')));
checkEq('rem fire allday UTC event, LA home: 18:00 in LA the day before', '2026-08-07 01:00:00',
    Time::toDb(Reminders::fireAt(['daysBefore' => 1, 'time' => '18:00'], $allDayUtc, true, 'UTC', 'America/Los_Angeles')));
checkEq('rem fire allday UTC event, LA home: same day 09:00 in LA', '2026-08-07 16:00:00',
    Time::toDb(Reminders::fireAt(['daysBefore' => 0, 'time' => '09:00'], $allDayUtc, true, 'UTC', 'America/Los_Angeles')));
checkEq('rem fire allday UTC event, LA home: {minutes} counts back from LA midnight', '2026-08-07 01:00:00',
    Time::toDb(Reminders::fireAt(['minutes' => 360], $allDayUtc, true, 'UTC', 'America/Los_Angeles')));
checkEq('rem fire allday LA event, LA home: unchanged', '2026-08-07 01:00:00',
    Time::toDb(Reminders::fireAt(['daysBefore' => 1, 'time' => '18:00'], $allDayLa, true, 'America/Los_Angeles', 'America/Los_Angeles')));
checkEq('rem fire allday Tokyo event, LA home: Aug 7 is still the date, the clock is LA', '2026-08-07 01:00:00',
    Time::toDb(Reminders::fireAt(['daysBefore' => 1, 'time' => '18:00'], Time::fromDb('2026-08-06 15:00:00'), true, 'Asia/Tokyo', 'America/Los_Angeles')));
checkEq('rem fire timed event ignores the home zone', '2026-07-31 02:50:00',
    Time::toDb(Reminders::fireAt(['minutes' => 10], Time::fromDb('2026-07-31 03:00:00'), false, 'UTC', 'America/Los_Angeles')));
checkEq('rem fire allday, empty home zone falls back to the event clock', '2026-08-06 18:00:00',
    Time::toDb(Reminders::fireAt(['daysBefore' => 1, 'time' => '18:00'], $allDayUtc, true, 'UTC', '')));

// Dedup key format: eventId:occurrenceStartUtc:offsetMinutes.
checkEq('rem instance key format', '42:20260807T190000Z:10', Reminders::instanceKey(42, $startUtc, 10));

// Notification payload.
$payloadRow = [
    'id' => 42, 'title' => 'Dinner', 'location' => 'Zuni Cafe',
    'tzid' => 'America/Los_Angeles', 'all_day' => 0,
];
$pl = Reminders::payload($payloadRow, $startUtc, false);
checkEq('rem payload title', 'Dinner', $pl['title']);
checkEq('rem payload body time + location', 'Fri, Aug 7, 12:00 PM · Zuni Cafe', $pl['body']);
checkEq('rem payload tag is instanceId', '42:20260807T190000Z', $pl['tag']);
checkEq(
    'rem payload url deep link carries event + at',
    '/?event=' . rawurlencode('42:20260807T190000Z') . '&at=' . rawurlencode('2026-08-07T19:00:00Z'),
    $pl['url']
);
checkEq(
    'rem payload url format',
    1,
    preg_match('#^/\?event=\d+%3A\d{8}T\d{6}Z&at=\d{4}-\d{2}-\d{2}T\d{2}%3A\d{2}%3A\d{2}Z$#', $pl['url'])
);
$plAllDay = Reminders::payload(['id' => 7, 'title' => 'Fair', 'location' => null, 'tzid' => 'America/Los_Angeles', 'all_day' => 1], $allDayLa, true);
checkEq('rem payload allday body', 'Fri, Aug 7 · All day', $plAllDay['body']);
checkEq(
    'rem payload allday url at is occurrence start utc',
    '/?event=' . rawurlencode('7:20260807T070000Z') . '&at=' . rawurlencode('2026-08-07T07:00:00Z'),
    $plAllDay['url']
);

// Delivery channel plan: which channels a due reminder goes to, per the
// notifyChannel setting, live-subscription state, and push outcomes.
use BetterCal\Infra\EmailSender;
use BetterCal\Infra\PushSender;

checkEq('chan push with live sub', ['push' => true, 'email' => false],
    Reminders::channelPlan('push', true, [PushSender::OK]));
checkEq('chan push no sub never emails', ['push' => true, 'email' => false],
    Reminders::channelPlan('push', false, []));
checkEq('chan push all failed never emails', ['push' => true, 'email' => false],
    Reminders::channelPlan('push', true, [PushSender::ERROR]));
checkEq('chan email skips push', ['push' => false, 'email' => true],
    Reminders::channelPlan('email', true, []));
checkEq('chan email no sub still emails', ['push' => false, 'email' => true],
    Reminders::channelPlan('email', false, []));
checkEq('chan both always both', ['push' => true, 'email' => true],
    Reminders::channelPlan('both', true, [PushSender::OK]));
checkEq('chan both no sub still emails', ['push' => true, 'email' => true],
    Reminders::channelPlan('both', false, []));
checkEq('chan fallback delivered push only', ['push' => true, 'email' => false],
    Reminders::channelPlan('push-fallback', true, [PushSender::OK]));
checkEq('chan fallback partial success no email', ['push' => true, 'email' => false],
    Reminders::channelPlan('push-fallback', true, [PushSender::ERROR, PushSender::OK]));
checkEq('chan fallback all rejected emails', ['push' => true, 'email' => true],
    Reminders::channelPlan('push-fallback', true, [PushSender::ERROR]));
checkEq('chan fallback all gone emails', ['push' => true, 'email' => true],
    Reminders::channelPlan('push-fallback', true, [PushSender::GONE, PushSender::GONE]));
checkEq('chan fallback no live sub emails', ['push' => true, 'email' => true],
    Reminders::channelPlan('push-fallback', false, []));

// Reminder email builder: subject/body carry title + local time + location,
// deep link is absolute against the base URL (pure, no SMTP).
$msg = EmailSender::buildMessage($pl, 'https://cal.example.com');
checkEq('email subject carries title', 'Reminder: Dinner', $msg['subject']);
check('email html carries local time', str_contains($msg['html'], 'Fri, Aug 7, 12:00 PM'));
check('email html carries location', str_contains($msg['html'], 'Zuni Cafe'));
$absLink = 'https://cal.example.com/?event=' . rawurlencode('42:20260807T190000Z') . '&at=' . rawurlencode('2026-08-07T19:00:00Z');
check('email html button link absolute', str_contains($msg['html'], 'href="' . htmlspecialchars($absLink, ENT_QUOTES, 'UTF-8') . '"'));
check('email text alt carries title and link', str_contains($msg['text'], 'Dinner') && str_contains($msg['text'], $absLink));
check('email html escapes markup', !str_contains(EmailSender::buildMessage(['title' => '<b>x</b>', 'body' => '', 'url' => '/'], 'https://cal.example.com')['html'], '<b>x</b>'));
checkEq('email untitled fallback', 'Reminder: (untitled event)', EmailSender::buildMessage(['body' => '', 'url' => '/'], 'https://x.example')['subject']);
check('email base url trailing slash collapsed', str_contains(EmailSender::buildMessage(['title' => 'T', 'body' => '', 'url' => '/'], 'https://x.example/')['text'], 'https://x.example/'));

// VALARM trigger mapping, both directions.
checkEq('valarm parse -PT10M', 10, Ics::parseTriggerMinutes('-PT10M'));
checkEq('valarm parse -P1D', 1440, Ics::parseTriggerMinutes('-P1D'));
checkEq('valarm parse -PT1H30M', 90, Ics::parseTriggerMinutes('-PT1H30M'));
checkEq('valarm parse -P1W', 10080, Ics::parseTriggerMinutes('-P1W'));
checkEq('valarm parse PT0S at start', 0, Ics::parseTriggerMinutes('PT0S'));
checkEq('valarm parse -PT30S rounds to minute', 1, Ics::parseTriggerMinutes('-PT30S'));
checkEq('valarm parse after-start rejected', null, Ics::parseTriggerMinutes('PT15M'));
checkEq('valarm parse absolute rejected', null, Ics::parseTriggerMinutes('20260807T120000Z'));
checkEq('valarm format 10', '-PT10M', Ics::formatTrigger(10));
checkEq('valarm format 90', '-PT1H30M', Ics::formatTrigger(90));
checkEq('valarm format 1440', '-P1D', Ics::formatTrigger(1440));
checkEq('valarm format 1500', '-P1DT1H', Ics::formatTrigger(1500));
checkEq('valarm format 0', 'PT0S', Ics::formatTrigger(0));
foreach ([10, 90, 1440, 1500, 0, 360, 10080] as $m) {
    checkEq("valarm round trip $m", $m, Ics::parseTriggerMinutes(Ics::formatTrigger($m)));
}

// Export: explicit overrides write display VALARMs; inherited/none do not.
$valCal = Ics::buildCalendar('Rem', null, [
    [
        'uid' => 'r-1', 'title' => 'With override', 'start_utc' => '2026-08-07 19:00:00',
        'end_utc' => '2026-08-07 20:00:00', 'all_day' => 0, 'tzid' => 'UTC', 'status' => 'confirmed',
        'reminders_json' => json_encode([['minutes' => 10], ['minutes' => 1440]]),
    ],
    [
        'uid' => 'r-2', 'title' => 'Inherited', 'start_utc' => '2026-08-08 19:00:00',
        'end_utc' => '2026-08-08 20:00:00', 'all_day' => 0, 'tzid' => 'UTC', 'status' => 'confirmed',
        'reminders_json' => null,
    ],
]);
checkEq('valarm export two alarms for override', 2, substr_count($valCal, 'BEGIN:VALARM'));
check('valarm export display action', str_contains($valCal, 'ACTION:DISPLAY'));
check('valarm export trigger 10min', str_contains($valCal, 'TRIGGER:-PT10M'));
check('valarm export trigger 1day', str_contains($valCal, 'TRIGGER:-P1D'));

// Calendars: stored reminderDefaults surface, absent stays null.
checkEq('cal reminderDefaults parsed', ['timed' => [['minutes' => 30]], 'allDay' => []],
    Calendars::reminderDefaultsFor(json_encode(['reminderDefaults' => ['timed' => [['minutes' => 30]], 'allDay' => []]])));
checkEq('cal reminderDefaults absent null', null, Calendars::reminderDefaultsFor(json_encode(['groupSimilar' => true])));
checkEq('cal reminderDefaults null settings', null, Calendars::reminderDefaultsFor(null));

// Settings: reminder keys validate through the allowlist.
$sv = Settings::validate(['reminderTimed' => [['minutes' => 30]], 'reminderAllDay' => [['daysBefore' => 2, 'time' => '7:30']]]);
checkEq('settings reminderTimed validated', [['minutes' => 30]], $sv['reminderTimed']);
checkEq('settings reminderAllDay normalized time', [['daysBefore' => 2, 'time' => '07:30']], $sv['reminderAllDay']);
checkEq('settings defaults include reminders', [['minutes' => 10]], Settings::withDefaults([])['reminderTimed']);
checkEq('settings defaults allday reminder', [['daysBefore' => 1, 'time' => '18:00']], Settings::withDefaults([])['reminderAllDay']);
try {
    Settings::validate(['reminderTimed' => 'ten minutes']);
    check('settings rejects bad reminderTimed', false);
} catch (HttpError $e) {
    checkEq('settings bad reminderTimed status', 400, $e->status);
}

// notifyChannel: enum validation + default.
checkEq('settings notifyChannel default', 'push', Settings::withDefaults([])['notifyChannel']);
foreach (['push', 'email', 'both', 'push-fallback'] as $chan) {
    checkEq("settings notifyChannel $chan accepted", ['notifyChannel' => $chan], Settings::validate(['notifyChannel' => $chan]));
}
foreach (['sms', 'pushfallback', '', 1] as $bad) {
    try {
        Settings::validate(['notifyChannel' => $bad]);
        check('settings rejects bad notifyChannel', false);
    } catch (HttpError $e) {
        checkEq('settings bad notifyChannel status', 400, $e->status);
    }
}

// notifyEmail: validation (valid/trimmed accepted, invalid rejected, null and
// blank clear) plus the recipient-resolution helper (set vs unset).
checkEq('settings notifyEmail default', null, Settings::withDefaults([])['notifyEmail']);
checkEq(
    'settings notifyEmail valid accepted+trimmed',
    ['notifyEmail' => 'me@example.com'],
    Settings::validate(['notifyEmail' => '  me@example.com  '])
);
checkEq('settings notifyEmail null clears', ['notifyEmail' => null], Settings::validate(['notifyEmail' => null]));
checkEq('settings notifyEmail blank clears', ['notifyEmail' => null], Settings::validate(['notifyEmail' => '   ']));
foreach (['not-an-email', 'a@b', 'x@' . str_repeat('d', 250) . '.com', 42] as $bad) {
    try {
        Settings::validate(['notifyEmail' => $bad]);
        check('settings rejects bad notifyEmail', false);
    } catch (HttpError $e) {
        checkEq('settings bad notifyEmail status', 400, $e->status);
    }
}
checkEq(
    'notifyDestination uses notifyEmail when set',
    'inbox@example.com',
    Settings::notifyDestination(Settings::withDefaults(['notifyEmail' => 'inbox@example.com']), 'account@example.com')
);
checkEq(
    'notifyDestination falls back to account email',
    'account@example.com',
    Settings::notifyDestination(Settings::withDefaults([]), 'account@example.com')
);

// Push subscription validation.
$psub = PushSubscriptions::validate([
    'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
    'keys' => ['p256dh' => 'BPk9_dh-key_', 'auth' => 'authtok='],
]);
checkEq('push validate endpoint kept', 'https://fcm.googleapis.com/fcm/send/abc123', $psub['endpoint']);
checkEq('push validate keys kept', ['BPk9_dh-key_', 'authtok='], [$psub['p256dh'], $psub['auth']]);
foreach ([
    ['endpoint' => 'http://insecure.example/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']],
    ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x'],
    ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'keys' => ['p256dh' => 'bad key!', 'auth' => 'b']],
    // BC-02: well-formed keys, https, but not a push service.
    ['endpoint' => 'https://internal.corp.example/admin', 'keys' => ['p256dh' => 'a', 'auth' => 'b']],
] as $bad) {
    try {
        PushSubscriptions::validate($bad);
        check('push validate rejects bad subscription', false);
    } catch (HttpError $e) {
        checkEq('push validate bad subscription code', 'invalid_subscription', $e->errorCode);
    }
}

// BC-02: the endpoint is a client-supplied URL the server later POSTs to, so
// it has to be a real push service, not just https.
foreach ([
    'https://fcm.googleapis.com/fcm/send/abc',
    'https://updates.push.services.mozilla.com/wpush/v2/abc',
    'https://web.push.apple.com/QAbc',
    'https://wns2-by3p.notify.windows.com/w/?token=abc',
    'https://FCM.GoogleAPIs.com/fcm/send/abc',
    'https://fcm.googleapis.com:443/fcm/send/abc',
] as $okEndpoint) {
    checkEq("push endpoint allowed: $okEndpoint", null, BetterCal\Infra\PushSender::endpointProblem($okEndpoint));
}
foreach ([
    'plain http' => 'http://fcm.googleapis.com/fcm/send/abc',
    'arbitrary https host' => 'https://attacker.example/collect',
    'loopback' => 'https://127.0.0.1/x',
    'internal name' => 'https://intranet/x',
    'cloud metadata' => 'https://169.254.169.254/latest/meta-data/',
    'suffix lookalike' => 'https://evilfcm.googleapis.com.attacker.example/x',
    'prefix lookalike (no dot boundary)' => 'https://notfcm.googleapis.com.example/x',
    'allowed name as a subdomain label of another domain' => 'https://fcm.googleapis.com.attacker.example/x',
    'userinfo disguising the real host' => 'https://fcm.googleapis.com@attacker.example/x',
    'credentials on an allowed host' => 'https://user:pw@fcm.googleapis.com/x',
    'non-default port' => 'https://fcm.googleapis.com:8443/x',
    'no host' => 'https:///x',
    'not a url' => 'fcm.googleapis.com/fcm/send/abc',
    'empty' => '',
] as $label => $badEndpoint) {
    check("push endpoint refused: $label", BetterCal\Infra\PushSender::endpointProblem($badEndpoint) !== null);
}
// Operator override: additive, subdomain-matching, and nothing else gets in with it.
checkEq('push extra host allowed', null, BetterCal\Infra\PushSender::endpointProblem('https://push.selfhosted.example/abc', ['push.selfhosted.example']));
checkEq('push extra host matches subdomains', null, BetterCal\Infra\PushSender::endpointProblem('https://eu.push.selfhosted.example/abc', [' Push.SelfHosted.example. ']));
check('push extra host does not open other hosts', BetterCal\Infra\PushSender::endpointProblem('https://attacker.example/x', ['push.selfhosted.example']) !== null);
check('push blank extra host entry allows nothing', BetterCal\Infra\PushSender::endpointProblem('https://attacker.example/x', ['', '  ', '.']) !== null);
checkEq('push defaults still allowed alongside extras', null, BetterCal\Infra\PushSender::endpointProblem('https://web.push.apple.com/QAbc', ['push.selfhosted.example']));
// Send-time enforcement covers rows stored before the policy existed: refused
// before the library (or the network) is touched, and reported as ERROR so the
// device shows as failing rather than being pruned.
{
    // The log line is what tells "refused by policy" apart from any other
    // ERROR (without vendor/ the library path would also end in ERROR).
    $pushLog = (string) tempnam(sys_get_temp_dir(), 'bcpush');
    $prevLog = ini_set('error_log', $pushLog);
    $outcome = (new BetterCal\Infra\PushSender(['vapid' => ['public' => 'x', 'private' => 'y'], 'push' => ['extra_hosts' => []]]))
        ->send(['endpoint' => 'https://127.0.0.1/x', 'p256dh' => 'a', 'auth' => 'b'], ['title' => 't']);
    ini_set('error_log', $prevLog === false ? '' : $prevLog);
    checkEq('push send: a stored non-push endpoint is an ERROR', BetterCal\Infra\PushSender::ERROR, $outcome);
    check('push send: refused by policy, before the library is touched', str_contains((string) file_get_contents($pushLog), 'push send refused: 127.0.0.1 is not a recognized push service'));
    @unlink($pushLog);
}
checkEq('push endpoint hash is sha256', hash('sha256', 'https://x.example/e'), PushSubscriptions::endpointHash('https://x.example/e'));

// ---------------------------------------------------------------------------
// Sanitize: description HTML allowlist (pure, no DB)
// ---------------------------------------------------------------------------

use BetterCal\Domain\PlaceSearch;
use BetterCal\Domain\Sanitize;

// Plain text passes through untouched, including angle-bracket prose.
checkEq('san plain text untouched', "Coffee first\nthen work", Sanitize::description("Coffee first\nthen work"));
checkEq('san math prose untouched', 'a < b and b > c', Sanitize::description('a < b and b > c'));
checkEq('san null stays null', null, Sanitize::description(null));

// Allowed formatting tags survive, attributes are stripped.
checkEq('san bold kept', '<b>hi</b>', Sanitize::description('<b>hi</b>'));
checkEq('san lists kept', '<ul><li>a</li><li>b</li></ul>', Sanitize::description('<ul><li>a</li><li>b</li></ul>'));
checkEq('san div/br kept', '<div>one<br>two</div>', Sanitize::description('<div>one<br/>two</div>'));
checkEq('san class and style attributes stripped', '<p>x</p>', Sanitize::description('<p class="big" style="color:red">x</p>'));

// Dangerous content is removed entirely.
checkEq('san script dropped with contents', '<p>ok</p>', Sanitize::description('<p>ok</p><script>alert(1)</script>'));
checkEq('san style block dropped', 'text', Sanitize::description('<style>body{display:none}</style>text'));
checkEq('san iframe dropped', 'before after', Sanitize::description('before <iframe src="https://evil.example"></iframe>after'));
checkEq('san onclick stripped', '<b>click</b>', Sanitize::description('<b onclick="steal()">click</b>'));
checkEq('san onerror img gone', 'x', Sanitize::description('x<img src=x onerror=alert(1)>'));

// Links: http/https kept (with hardening attrs), javascript: unwrapped.
checkEq(
    'san https link kept and hardened',
    '<a href="https://example.com/x" target="_blank" rel="noopener noreferrer">site</a>',
    Sanitize::description('<a href="https://example.com/x">site</a>')
);
checkEq('san javascript href unwrapped', 'evil', Sanitize::description('<a href="javascript:alert(1)">evil</a>'));
checkEq('san data href unwrapped', 'x', Sanitize::description('<a href="data:text/html,pwn">x</a>'));

// Unknown tags unwrap but keep their text.
checkEq('san span unwrapped', 'hello <b>there</b>', Sanitize::description('<span data-x="1">hello</span> <b>there</b>'));
checkEq('san heading unwrapped', 'Title<p>body</p>', Sanitize::description('<h1>Title</h1><p>body</p>'));

// Text nodes are entity-escaped on the way out.
checkEq('san text re-escaped', '<p>a &amp; b</p>', Sanitize::description('<p>a &amp; b</p>'));

// isHtml detection.
check('san isHtml true for tags', Sanitize::isHtml('<p>x</p>'));
check('san isHtml false for prose', !Sanitize::isHtml('less < more, x > y'));
check('san isHtml false for null', !Sanitize::isHtml(null));

// toText flattens blocks and brs to newlines and decodes entities.
checkEq('san toText plain passthrough', 'just text', Sanitize::toText('just text'));
checkEq('san toText blocks to newlines', "one\ntwo\nthree", Sanitize::toText('<p>one</p><div>two<br>three</div>'));
checkEq('san toText decodes entities', 'a & b', Sanitize::toText('<p>a &amp; b</p>'));
checkEq('san toText drops script bodies', 'ok', Sanitize::toText('<script>bad()</script><p>ok</p>'));

// ---------------------------------------------------------------------------
// ICS: rich descriptions -> DESCRIPTION (text) + X-ALT-DESC (html)
// ---------------------------------------------------------------------------

$richCal = Ics::buildCalendar('Rich', null, [
    [
        'uid' => 'rich-1', 'title' => 'Board meeting', 'description' => '<p>Agenda:</p><ul><li>budget</li></ul>',
        'start_utc' => '2026-08-01 17:00:00', 'end_utc' => '2026-08-01 18:00:00',
        'all_day' => 0, 'tzid' => 'UTC', 'status' => 'confirmed',
    ],
    [
        'uid' => 'rich-2', 'title' => 'Plain', 'description' => "Line1\nLine2",
        'start_utc' => '2026-08-02 17:00:00', 'end_utc' => '2026-08-02 18:00:00',
        'all_day' => 0, 'tzid' => 'UTC', 'status' => 'confirmed',
    ],
]);
$richUnfolded = Ics::unfold($richCal);
check('ics rich DESCRIPTION is stripped text', str_contains($richUnfolded, 'DESCRIPTION:Agenda:\\nbudget'));
check('ics rich X-ALT-DESC carries html', str_contains($richUnfolded, 'X-ALT-DESC;FMTTYPE=text/html:<p>Agenda:</p><ul><li>budget</li></ul>'));
checkEq('ics plain description has no X-ALT-DESC', 1, substr_count($richUnfolded, 'X-ALT-DESC'));
check('ics plain description unchanged', str_contains($richUnfolded, 'DESCRIPTION:Line1\\nLine2'));

// ---------------------------------------------------------------------------
// PlaceSearch: tz centroids, address compose, distance, ranking (pure)
// ---------------------------------------------------------------------------

// tz centroid map: known zones resolve, unknown/empty do not.
$sfCentroid = PlaceSearch::tzCentroid('America/Los_Angeles');
check('ps centroid LA near SF', $sfCentroid !== null && abs($sfCentroid[0] - 37.77) < 0.01 && abs($sfCentroid[1] + 122.42) < 0.01);
check('ps centroid Tokyo in Japan', PlaceSearch::tzCentroid('Asia/Tokyo')[1] > 135);
checkEq('ps centroid unknown zone null', null, PlaceSearch::tzCentroid('Mars/Olympus_Mons'));
checkEq('ps centroid null input null', null, PlaceSearch::tzCentroid(null));
checkEq('ps centroid empty input null', null, PlaceSearch::tzCentroid(''));
check('ps centroid map has broad coverage', count(PlaceSearch::TZ_CENTROIDS) >= 40);
$psValid = true;
foreach (PlaceSearch::TZ_CENTROIDS as $tzName => $pair) {
    if (!in_array($tzName, \DateTimeZone::listIdentifiers(), true)) {
        $psValid = false;
        echo "  bad tz id: $tzName\n";
    }
    if (abs($pair[0]) > 90 || abs($pair[1]) > 180) {
        $psValid = false;
    }
}
check('ps centroid ids are real IANA zones with sane coords', $psValid);

// composeAddress: street + housenumber, city, state, country; concise.
checkEq(
    'ps address full',
    '1658 Market Street, San Francisco, California, United States',
    PlaceSearch::composeAddress(['housenumber' => '1658', 'street' => 'Market Street', 'city' => 'San Francisco', 'state' => 'California', 'country' => 'United States'])
);
checkEq('ps address street only', 'Market Street, San Francisco', PlaceSearch::composeAddress(['street' => 'Market Street', 'city' => 'San Francisco']));
checkEq('ps address city dedupes name', 'Germany', PlaceSearch::composeAddress(['name' => 'Berlin', 'city' => 'Berlin', 'country' => 'Germany']));
checkEq('ps address dedupes repeated parts', 'Singapore', PlaceSearch::composeAddress(['city' => 'Singapore', 'state' => 'Singapore', 'country' => 'Singapore', 'name' => 'Zoo']));
checkEq('ps address empty props', '', PlaceSearch::composeAddress([]));

// distanceKm: SF -> LA is roughly 560 km; zero distance to self.
$dSfLa = PlaceSearch::distanceKm(37.77, -122.42, 34.05, -118.24);
check('ps distance SF-LA plausible', $dSfLa > 540 && $dSfLa < 580, "got $dSfLa");
check('ps distance to self is zero', PlaceSearch::distanceKm(10.0, 20.0, 10.0, 20.0) < 0.001);

// mapFeatures: shape, GeoJSON [lng,lat] order, dedupe, bias distance + far flag.
$psPhoton = ['features' => [
    [
        'geometry' => ['coordinates' => [-122.4216, 37.7739]],
        'properties' => ['name' => 'Zuni Cafe', 'housenumber' => '1658', 'street' => 'Market Street', 'city' => 'San Francisco', 'state' => 'California', 'country' => 'United States'],
    ],
    [ // duplicate of the first (other OSM layer): dropped
        'geometry' => ['coordinates' => [-122.4216, 37.7739]],
        'properties' => ['name' => 'Zuni Cafe', 'housenumber' => '1658', 'street' => 'Market Street', 'city' => 'San Francisco', 'state' => 'California', 'country' => 'United States'],
    ],
    [
        'geometry' => ['coordinates' => [151.21, -33.87]],
        'properties' => ['name' => 'Zuni', 'city' => 'Sydney', 'country' => 'Australia'],
    ],
    ['geometry' => ['coordinates' => ['x', 'y']], 'properties' => ['name' => 'Broken']],
]];
$psMapped = PlaceSearch::mapFeatures($psPhoton, 37.77, -122.42);
checkEq('ps map count after dedupe + invalid drop', 2, count($psMapped));
checkEq('ps map lat from GeoJSON', 37.7739, $psMapped[0]['lat']);
checkEq('ps map lng from GeoJSON', -122.4216, $psMapped[0]['lng']);
checkEq('ps map display', 'Zuni Cafe, 1658 Market Street, San Francisco, California, United States', $psMapped[0]['display']);
checkEq('ps map city extracted', 'San Francisco', $psMapped[0]['city']);
check('ps map near candidate not far', $psMapped[0]['far'] === false && $psMapped[0]['distanceKm'] < 5);
check('ps map antipodal candidate flagged far', $psMapped[1]['far'] === true && $psMapped[1]['distanceKm'] > 10000);
checkEq('ps map garbage -> empty', [], PlaceSearch::mapFeatures('garbage', null, null));
checkEq('ps map no bias -> null distance', null, PlaceSearch::mapFeatures($psPhoton, null, null)[0]['distanceKm']);

// rank: a near match overtakes a textually-first far match; near-order stable.
$psRanked = PlaceSearch::rank([
    ['name' => 'Far first', 'distanceKm' => 9000.0],
    ['name' => 'Near second', 'distanceKm' => 3.0],
    ['name' => 'Near third', 'distanceKm' => 8.0],
]);
checkEq('ps rank near beats far', ['Near second', 'Near third', 'Far first'], array_column($psRanked, 'name'));
$psClose = PlaceSearch::rank([
    ['name' => 'A', 'distanceKm' => 10.0],
    ['name' => 'B', 'distanceKm' => 12.0],
]);
checkEq('ps rank preserves photon order among near ties', ['A', 'B'], array_column($psClose, 'name'));
checkEq('ps rank no bias keeps order', ['X', 'Y'], array_column(PlaceSearch::rank([
    ['name' => 'X', 'distanceKm' => null],
    ['name' => 'Y', 'distanceKm' => null],
]), 'name'));
checkEq('ps rank empty', [], PlaceSearch::rank([]));

// ---------------------------------------------------------------------------
// Settings: home location + map style keys
// ---------------------------------------------------------------------------

checkEq('set homeLat validated', ['homeLat' => 37.77], Settings::validate(['homeLat' => 37.77]));
checkEq('set homeLng string accepted', ['homeLng' => -122.42], Settings::validate(['homeLng' => '-122.42']));
checkEq('set homeLat null clears', ['homeLat' => null], Settings::validate(['homeLat' => null]));
checkEq('set homeLabel trimmed', ['homeLabel' => 'San Francisco'], Settings::validate(['homeLabel' => '  San Francisco  ']));
checkEq('set homeLabel blank becomes null', ['homeLabel' => null], Settings::validate(['homeLabel' => '   ']));
checkEq('set mapStyle validated', ['mapStyle' => 'dataviz'], Settings::validate(['mapStyle' => 'dataviz']));
checkEq('set mapStyle default', 'streets-v2', Settings::withDefaults([])['mapStyle']);
checkEq('set home defaults null', [null, null, null], [
    Settings::withDefaults([])['homeLat'],
    Settings::withDefaults([])['homeLng'],
    Settings::withDefaults([])['homeLabel'],
]);
foreach ([['homeLat' => 91], ['homeLng' => -181], ['homeLat' => 'north'], ['mapStyle' => 'satellite'], ['homeLabel' => 42]] as $bad) {
    try {
        Settings::validate($bad);
        check('set bad home/map value rejected: ' . json_encode($bad), false);
    } catch (HttpError $e) {
        checkEq('set bad home/map value status', 400, $e->status);
    }
}

// ---------------------------------------------------------------------------
// Trips: linkability matrix, clear gating, container map shape, RELATED-TO
// serialization both directions (pure, no DB)
// ---------------------------------------------------------------------------

use BetterCal\Domain\Trips;

$tripRow = ['id' => 1, 'is_container' => 1, 'calendar_id' => 3, 'uid' => 'trip-1'];
$tripMemberRow = ['id' => 2, 'is_container' => 0, 'calendar_id' => 4, 'uid' => 'ev-2', 'source' => 'feed'];

try {
    Trips::assertLinkable($tripRow, $tripMemberRow);
    check('trip attach valid pair accepted (feed member ok)', true);
} catch (HttpError) {
    check('trip attach valid pair accepted (feed member ok)', false);
}
try {
    // Same-calendar membership is explicitly supported (design decision 2).
    Trips::assertLinkable($tripRow, ['id' => 8, 'is_container' => 0, 'calendar_id' => 3]);
    check('trip attach same-calendar member accepted', true);
} catch (HttpError) {
    check('trip attach same-calendar member accepted', false);
}
foreach ([
    'non-container target' => [['id' => 9, 'is_container' => 0], $tripMemberRow],
    'container in container' => [$tripRow, ['id' => 5, 'is_container' => 1]],
    'self-attach' => [$tripRow, $tripRow],
] as $tripLabel => [$tripC, $tripM]) {
    try {
        Trips::assertLinkable($tripC, $tripM);
        check("trip attach rejects $tripLabel", false);
    } catch (HttpError $e) {
        checkEq("trip attach $tripLabel error code", 'trip_invalid', $e->errorCode);
        checkEq("trip attach $tripLabel status", 400, $e->status);
    }
}

// Clearing is_container requires an empty trip.
try {
    Trips::assertClearable(0);
    check('trip clear with no members allowed', true);
} catch (HttpError) {
    check('trip clear with no members allowed', false);
}
try {
    Trips::assertClearable(2);
    check('trip clear with members rejected', false);
} catch (HttpError $e) {
    checkEq('trip clear with members code', 'trip_has_members', $e->errorCode);
    checkEq('trip clear with members status', 400, $e->status);
}

// containersFor batch shape: link rows fold to eventId => [{eventId,title}].
$tripMap = Trips::containerMap([
    ['event_id' => 2, 'container_id' => 1, 'title' => 'Hawaii Trip'],
    ['event_id' => 7, 'container_id' => 1, 'title' => 'Hawaii Trip'],
    ['event_id' => '7', 'container_id' => '9', 'title' => 'Conference'],
]);
checkEq('trip map single container entry', [['eventId' => 1, 'title' => 'Hawaii Trip']], $tripMap[2]);
checkEq('trip map n:m rows keep order', [
    ['eventId' => 1, 'title' => 'Hawaii Trip'],
    ['eventId' => 9, 'title' => 'Conference'],
], $tripMap[7]);
checkEq('trip map only linked ids present', [2, 7], array_keys($tripMap));
checkEq('trip map empty input', [], Trips::containerMap([]));

// RELATED-TO export: container VEVENT lists member uids as RELTYPE=CHILD,
// member VEVENT lists its container uid as RELTYPE=PARENT (feed + CalDAV).
$tripCal = Ics::buildCalendar('Trips', null, [
    [
        'uid' => 'trip-1', 'title' => 'Hawaii Trip', 'start_utc' => '2026-06-01 07:00:00',
        'end_utc' => '2026-06-13 07:00:00', 'all_day' => 1, 'tzid' => 'America/Los_Angeles',
        'status' => 'confirmed', 'related_children' => ['flight-out', 'flight-back'],
    ],
    [
        'uid' => 'flight-out', 'title' => 'Flight to HNL', 'start_utc' => '2026-06-01 16:00:00',
        'end_utc' => '2026-06-01 22:00:00', 'all_day' => 0, 'tzid' => 'America/Los_Angeles',
        'status' => 'confirmed', 'related_parents' => ['trip-1'],
    ],
]);
$tripUnfolded = Ics::unfold($tripCal);
checkEq('trip ics container child lines', 2, substr_count($tripUnfolded, 'RELATED-TO;RELTYPE=CHILD:'));
check('trip ics child uid out', str_contains($tripUnfolded, 'RELATED-TO;RELTYPE=CHILD:flight-out'));
check('trip ics child uid back', str_contains($tripUnfolded, 'RELATED-TO;RELTYPE=CHILD:flight-back'));
checkEq('trip ics member parent line', 1, substr_count($tripUnfolded, 'RELATED-TO;RELTYPE=PARENT:trip-1'));
check('trip ics undecorated rows write nothing', !str_contains(Ics::buildCalendar('X', null, [[
    'uid' => 'plain', 'title' => 'Plain', 'start_utc' => '2026-06-01 16:00:00',
    'end_utc' => '2026-06-01 17:00:00', 'all_day' => 0, 'tzid' => 'UTC', 'status' => 'confirmed',
]]), 'RELATED-TO'));

// DavIcs object builder threads relationship uids onto the master VEVENT.
$tripObj = Ics::unfold(DavIcs::buildObject($davMaster, [$davOverride], ['member-uid'], []));
check('dav object related child on master', str_contains($tripObj, 'RELATED-TO;RELTYPE=CHILD:member-uid'));
checkEq('dav object override carries no relations', 1, substr_count($tripObj, 'RELATED-TO'));
check('dav object related parent on master', str_contains(
    Ics::unfold(DavIcs::buildObject($davMaster, [], [], ['trip-uid'])),
    'RELATED-TO;RELTYPE=PARENT:trip-uid'
));
check('dav object no relations by default', !str_contains(DavIcs::buildObject($davMaster, [$davOverride]), 'RELATED-TO'));

// ICS import ignores RELATED-TO (trip links are user-local; API-only).
if (class_exists(\Sabre\VObject\Reader::class)) {
    $tripImport = Ics::parse(
        "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:t1\r\n"
        . "DTSTART:20260601T160000Z\r\nDTEND:20260601T170000Z\r\nSUMMARY:X\r\n"
        . "RELATED-TO;RELTYPE=PARENT:trip-1\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
    );
    check(
        'trip ics import ignores RELATED-TO',
        count($tripImport) === 1
            && !array_key_exists('related_parents', $tripImport[0])
            && !array_key_exists('related_children', $tripImport[0])
    );
}

// --- Activity log: sources + default summaries -----------------------------

use BetterCal\Domain\ActivityContext;
use BetterCal\Domain\Undo;

checkEq('activity default source', 'web', ActivityContext::get());
$seen = null;
ActivityContext::with('feed', function () use (&$seen): void {
    $seen = ActivityContext::get();
});
checkEq('activity with() sets source inside', 'feed', $seen);
checkEq('activity with() restores source after', 'web', ActivityContext::get());
try {
    ActivityContext::with('mail:llm', function (): void {
        throw new \RuntimeException('boom');
    });
} catch (\RuntimeException) {
}
checkEq('activity with() restores source after throw', 'web', ActivityContext::get());
$returned = ActivityContext::with('api', fn(): string => 'value');
checkEq('activity with() passes through return value', 'value', $returned);

checkEq(
    'activity summary: event create with date',
    "Added event 'Dinner at Zuni' (Aug 14)",
    Undo::defaultSummary('event', 'create', null, ['events' => [['title' => 'Dinner at Zuni', 'start_utc' => '2026-08-14 02:00:00']]])
);
checkEq(
    'activity summary: delete names from before side',
    "Deleted calendar 'Work'",
    Undo::defaultSummary('calendar', 'delete', ['calendars' => [['name' => 'Work']]], null)
);
checkEq(
    'activity summary: no snapshot stays generic',
    'Updated event',
    Undo::defaultSummary('event', 'update', null, null)
);
checkEq(
    'activity summary: saved view underscores become spaces',
    "Added saved view 'Focus'",
    Undo::defaultSummary('saved_view', 'create', null, ['saved_views' => [['name' => 'Focus']]])
);

// ---------------------------------------------------------------------------
// System health: when a failing streak earns an email, and the words used.
{
    $now = new DateTimeImmutable('2026-09-13 12:00:00', new DateTimeZone('UTC'));
    $row = static fn(array $o = []): array => $o + [
        'subject' => 'job:filter_eval', 'kind' => 'job', 'label' => 'Prompt filter evaluation', 'status' => 'failing',
        'first_failed_at' => '2026-09-13 10:00:00', 'consecutive_failures' => 5, 'alerted_at' => null, 'last_error' => 'LLM 401',
    ];
    check('alert: job failing 2h with 5 failures is due', SystemHealth::shouldAlert($row(), $now));
    check('alert: job failing 30m is not yet due', !SystemHealth::shouldAlert($row(['first_failed_at' => '2026-09-13 11:30:00']), $now));
    check('alert: job failing 2h but only twice is not due', !SystemHealth::shouldAlert($row(['consecutive_failures' => 2]), $now));
    check('alert: a working row is never due', !SystemHealth::shouldAlert($row(['status' => 'ok']), $now));
    check('alert: already emailed an hour ago is not due again', !SystemHealth::shouldAlert($row(['alerted_at' => '2026-09-13 11:00:00']), $now));
    check('alert: already emailed yesterday is due again', SystemHealth::shouldAlert($row(['alerted_at' => '2026-09-12 11:00:00']), $now));
    check('alert: a feed failing 6h is not due (a day)', !SystemHealth::shouldAlert($row(['kind' => 'feed', 'first_failed_at' => '2026-09-13 06:00:00']), $now));
    check('alert: a feed failing 25h is due', SystemHealth::shouldAlert($row(['kind' => 'feed', 'first_failed_at' => '2026-09-12 11:00:00']), $now));
    check('alert: a device failing 7h is due regardless of count', SystemHealth::shouldAlert($row(['kind' => 'push', 'first_failed_at' => '2026-09-13 05:00:00', 'consecutive_failures' => 1]), $now));
    checkEq('streak words: hours and minutes', '5 failures over 2h 0m', SystemHealth::describeStreak($row(), '2026-09-13 12:00:00'));
    checkEq('streak words: days', '30 failures over 1d 6h', SystemHealth::describeStreak($row(['consecutive_failures' => 30, 'first_failed_at' => '2026-09-12 06:00:00']), '2026-09-13 12:00:00'));
    $mail = SystemHealth::buildFailureEmail([$row()], $now, new DateTimeZone('America/Los_Angeles'), 'https://cal.example');
    checkEq('failure email: subject names the one thing', 'Better-Cal: Prompt filter evaluation has been failing', $mail['subject']);
    check('failure email: times read as a person would say them, in their zone', str_contains($mail['text'], 'Sun, Sep 13, 2026 at 3:00 AM PDT'), $mail['text']);
    check('failure email: says it is one per streak', str_contains($mail['text'], 'one email per failing streak'));
    $two = SystemHealth::buildFailureEmail([$row(), $row(['subject' => 'feed:31', 'kind' => 'feed', 'label' => 'Feed: Luma'])], $now, new DateTimeZone('UTC'), '');
    checkEq('failure email: several things, counted', 'Better-Cal: 2 things have been failing', $two['subject']);
    checkEq('device label: apple push', 'Reminders to Safari / Apple device (added 2026-09-01)', \BetterCal\Domain\PushSubscriptions::labelFor(['endpoint' => 'https://web.push.apple.com/QAbc', 'created_at' => '2026-09-01 10:00:00']));
    checkEq('device label: fcm', 'Reminders to Chrome / Android (added 2026-09-01)', \BetterCal\Domain\PushSubscriptions::labelFor(['endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'created_at' => '2026-09-01 10:00:00']));
}

// ---------------------------------------------------------------------------
// Geocode plausibility guard: a free-text description whose best match lies
// far from the bias is a bad guess, not an answer. Addresses and names pass.
{
    check('implausible: long description, far, not a place-level kind', Geocode::implausible("a temple mansion in oakland's ivy hill", 'locality', 3300.0));
    check('implausible: near is always fine', !Geocode::implausible("a temple mansion in oakland's ivy hill", 'locality', 12.0));
    check('implausible: an address with a comma may be anywhere', !Geocode::implausible('15 calton hill, edinburgh, eh1 3bj', 'house', 8300.0));
    check('implausible: a short name may be anywhere', !Geocode::implausible('heathrow terminal 5', 'building', 8600.0));
    check('implausible: a bare place name may be anywhere', !Geocode::implausible('tokyo', 'city', 8300.0));
    check('implausible: a long name that IS a place-level kind is allowed', !Geocode::implausible('hawaii volcanoes national park hawaii', 'national_park', 3800.0));
    check('implausible: exactly the threshold is not beyond it', !Geocode::implausible('some long description of a venue', 'locality', 1500.0));
    check('implausible: unknown kind counts as not place-level', Geocode::implausible('some long description of a venue', null, 1501.0));
}

// ---------------------------------------------------------------------------
// Geocode sweep: the pure parts. Grouping key and bias precedence.
{
    checkEq('sweep key: whitespace collapsed and case folded', 'pillar point harbor, half moon bay', GeocodeSweep::normalizeLocation("  Pillar   Point\tHarbor, Half Moon Bay \n"));
    checkEq('sweep key: same address, different spacing, one group', GeocodeSweep::normalizeLocation('15 Calton Hill, Edinburgh'), GeocodeSweep::normalizeLocation('15  Calton Hill,  EDINBURGH'));
    checkEq('sweep key: curly and straight apostrophes are one address', GeocodeSweep::normalizeLocation("A Temple Mansion in Oakland’s Ivy Hill"), GeocodeSweep::normalizeLocation("a temple mansion in oakland's ivy hill"));
    checkEq('sweep bias: home location wins', [37.8, -122.27], GeocodeSweep::biasFor(['homeLat' => 37.8, 'homeLng' => -122.27, 'tz' => 'Europe/London'], 'Asia/Tokyo'));
    $tzBias = GeocodeSweep::biasFor(['homeLat' => null, 'homeLng' => null], 'America/Los_Angeles');
    check('sweep bias: falls back to the event zone centroid', $tzBias[0] !== null && $tzBias[1] !== null && $tzBias[1] < -100, json_encode($tzBias));
    checkEq('sweep bias: nothing known means no bias', [null, null], GeocodeSweep::biasFor([], null));
    check('sweep: a URL is not an address', GeocodeSweep::isUrlLocation('https://luma.com/max0yr6g'));
    check('sweep: a www link is not an address', GeocodeSweep::isUrlLocation('www.zoom.us/j/123'));
    check('sweep: an address containing a link is still an address', !GeocodeSweep::isUrlLocation('Mox, 1680 Mission St (map: https://x.y)'));
    check('sweep: a plain address is an address', !GeocodeSweep::isUrlLocation('15 Calton Hill, Edinburgh'));
}

// ---------------------------------------------------------------------------
// Feed content state. Three independent answers, not one boolean: a feed that
// is empty and always was is fine; one that WENT empty is how expired tokens
// fail (a valid, empty calendar); stale means the publisher has not changed
// anything for the threshold AND nothing is upcoming. Error is status's job.
{
    $now = new DateTimeImmutable('2026-09-13 12:00:00', new DateTimeZone('UTC'));
    $sub = static fn(array $over = []): array => $over + [
        'kind' => 'subscribed', 'last_polled_at' => '2026-09-13 11:00:00', 'last_poll_status' => 'ok',
        'created_at' => '2026-01-01 00:00:00', 'content_changed_at' => '2026-09-01 00:00:00', 'stale_after_days' => 60,
    ];
    $st = static fn(array $c, int $lastRaw, int $everRaw, bool $upcoming) => Calendars::contentState($c, $lastRaw, $everRaw, $upcoming, $now);

    checkEq('content: local calendars are simply active', 'active', $st(['kind' => 'local'] + $sub(), 0, 0, false));
    checkEq('content: never polled is active (status says never)', 'active', $st($sub(['last_polled_at' => null]), 0, 0, false));
    checkEq('content: fresh empty feed is empty, not stale', 'empty', $st($sub(['created_at' => '2026-09-13 10:00:00', 'content_changed_at' => null]), 0, 0, false));
    checkEq('content: long-empty feed is still just empty', 'empty', $st($sub(['content_changed_at' => null]), 0, 0, false));
    checkEq('content: feed that had events and now has none is emptied', 'emptied', $st($sub(), 0, 73, false));
    checkEq('content: events present and changed recently is active', 'active', $st($sub(), 50, 73, false));
    checkEq('content: unchanged past threshold but has upcoming is active', 'active', $st($sub(['content_changed_at' => '2026-05-01 00:00:00']), 50, 50, true));
    checkEq('content: unchanged past threshold and nothing upcoming is stale', 'stale', $st($sub(['content_changed_at' => '2026-05-01 00:00:00']), 50, 50, false));
    checkEq('content: never seen to change dates from subscription, not epoch', 'active', $st($sub(['created_at' => '2026-09-01 00:00:00', 'content_changed_at' => null]), 50, 50, false));
    checkEq('content: never seen to change, subscribed long ago, nothing upcoming is stale', 'stale', $st($sub(['created_at' => '2026-01-01 00:00:00', 'content_changed_at' => null]), 50, 50, false));
    checkEq('content: exactly at threshold counts as stale', 'stale', $st($sub(['content_changed_at' => '2026-07-15 12:00:00']), 50, 50, false));
    checkEq('content: one second inside threshold is active', 'active', $st($sub(['content_changed_at' => '2026-07-15 12:00:01']), 50, 50, false));
    checkEq('content: an erroring poll does not invent a content verdict', 'active', $st($sub(['last_poll_status' => 'error']), 50, 50, false));
}

// ---------------------------------------------------------------------------
// columnPatch's forOverride gate. An instance override row must never take
// the series RRULE or the trip flag from a patch payload, because the editor
// always sends the rrule it seeded from; the existing-override branch of
// patchThis once forgot the flag and stamped FREQ=... onto a moved instance.
// columnPatch reads only $current/$in and static helpers unless `status` is
// sent, so it is callable on an Events built without its constructor.
{
    $events = (new ReflectionClass(\BetterCal\Domain\Events::class))->newInstanceWithoutConstructor();
    $columnPatch = static fn(array $current, array $in, bool $forOverride): array =>
        (new ReflectionMethod($events, 'columnPatch'))->invokeArgs($events, [$current, $in, null, $forOverride]);
    $override = [
        'tzid' => 'America/Los_Angeles', 'all_day' => 0,
        'start_utc' => '2026-09-11 17:00:00', 'end_utc' => '2026-09-11 18:00:00',
        'rrule' => null, 'recurrence_parent_id' => 16563, 'recurrence_instance_utc' => '2026-09-15 19:30:00',
    ];
    $payload = [
        'start' => '2026-09-11T12:00:00-07:00', 'end' => '2026-09-11T13:00:00-07:00',
        'rrule' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=TU', 'isContainer' => true, 'title' => 'Cleaners',
    ];
    $fields = $columnPatch($override, $payload, true);
    check('override patch: series rrule is not written onto the instance row', !array_key_exists('rrule', $fields), json_encode($fields));
    check('override patch: the trip flag is not written onto the instance row', !array_key_exists('is_container', $fields));
    checkEq('override patch: the move itself still lands', '2026-09-11 19:00:00', $fields['start_utc'] ?? null);
    checkEq('override patch: title still lands', 'Cleaners', $fields['title'] ?? null);
    $master = $columnPatch($override, $payload, false);
    checkEq('master patch: the same payload does set rrule on a non-override', 'FREQ=WEEKLY;INTERVAL=2;BYDAY=TU', $master['rrule'] ?? null);
}

// ---------------------------------------------------------------------------
// The bundled plugins' own pure logic (parsers, interval maths, date maths).
require __DIR__ . '/plugins.php';

// ---------------------------------------------------------------------------

// --- Activity retention -------------------------------------------------------
// Db::run returns the statement; prune must report row counts, not statements
// (the worker prints them, and the daily job failed on that until it was
// caught by the system health alert).
{
    $adb = new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]);
    $adb->run('CREATE TABLE mutations (id INTEGER PRIMARY KEY, created_at TEXT, before_json TEXT, after_json TEXT)');
    $adb->run("INSERT INTO mutations (created_at, before_json, after_json) VALUES
        (datetime('now', '-100 days'), '{}', NULL),
        (datetime('now', '-10 days'), NULL, '{}'),
        (datetime('now', '-1 hour'), '{}', '{}')");
    $pruned = (new BetterCal\Domain\Activity($adb))->prune();
    checkEq('prune: snapshots older than 7 days cleared (count, not statement)', 2, $pruned['snapshotsCleared']);
    checkEq('prune: rows older than 90 days deleted', 1, $pruned['deleted']);
    checkEq('prune: recent rows untouched', 2, (int) $adb->one('SELECT COUNT(*) AS n FROM mutations')['n']);
}

// --- Job queue: stalled jobs -------------------------------------------------
// A worker that dies mid-job leaves the row 'running' forever (production had
// one from July 31). Reaping treats it as a failed attempt: retried with
// backoff, or failed for good once attempts are spent.
{
    $qdb = new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]);
    $qdb->run('CREATE TABLE jobs (id INTEGER PRIMARY KEY, type TEXT, payload_json TEXT, run_after TEXT, attempts INTEGER, status TEXT, last_error TEXT, created_at TEXT, updated_at TEXT)');
    $old = BetterCal\Support\Time::toDb(BetterCal\Support\Time::nowUtc()->sub(new \DateInterval('PT2H')));
    $fresh = BetterCal\Support\Time::toDb(BetterCal\Support\Time::nowUtc());
    $qdb->run("INSERT INTO jobs (id, type, payload_json, run_after, attempts, status, last_error, created_at, updated_at) VALUES
        (1, 'filter_eval', '{}', ?, 1, 'running', NULL, ?, ?),
        (2, 'feed_poll', '{}', ?, 5, 'running', NULL, ?, ?),
        (3, 'mail_ingest', '{}', ?, 1, 'running', NULL, ?, ?),
        (4, 'rank_events', '{}', ?, 1, 'pending', NULL, ?, ?)", [$old, $old, $old, $old, $old, $old, $fresh, $fresh, $fresh, $old, $old, $old]);
    $queue = new BetterCal\Infra\JobQueue($qdb);
    checkEq('reap: the two stalled jobs are reaped, the live and pending ones are not', [1, 2], $queue->reapStalled(30));
    checkEq('reap: first-attempt stall goes back to pending with backoff', 'pending', $qdb->scalar('SELECT status FROM jobs WHERE id = 1'));
    check('reap: the reason is recorded', str_contains((string) $qdb->scalar('SELECT last_error FROM jobs WHERE id = 1'), 'stalled'));
    checkEq('reap: out of attempts fails for good', 'failed', $qdb->scalar('SELECT status FROM jobs WHERE id = 2'));
    checkEq('reap: a job still within its window is left running', 'running', $qdb->scalar('SELECT status FROM jobs WHERE id = 3'));
    check('reap: nothing left to reap on the second pass', $queue->reapStalled(30) === []);
}

// --- System health: journaling threshold -------------------------------------
// One failed fetch is weather; the Activity entry comes on the second
// consecutive failure and the recovery entry only after such a streak.
{
    $hdb = new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]);
    $hdb->run('CREATE TABLE system_health (subject TEXT PRIMARY KEY, kind TEXT, user_id INTEGER, label TEXT, status TEXT DEFAULT "ok", first_failed_at TEXT, last_failed_at TEXT, last_ok_at TEXT, consecutive_failures INTEGER DEFAULT 0, last_error TEXT, alerted_at TEXT)');
    $hdb->run('CREATE TABLE mutations (id INTEGER PRIMARY KEY, user_id INTEGER, entity TEXT, entity_id INTEGER, op TEXT, before_json TEXT, after_json TEXT, source TEXT, run_id TEXT, summary TEXT, details_json TEXT)');
    $health = new SystemHealth($hdb);
    $entries = fn(): array => array_column($hdb->all('SELECT summary FROM mutations ORDER BY id'), 'summary');
    $health->recordOk('feed:9', 'feed', 1, 'Feed: Hangs');
    $health->recordFailure('feed:9', 'feed', 1, 'Feed: Hangs', 'HTTP 404');
    checkEq('health: one failure is not journaled', [], $entries());
    $health->recordOk('feed:9', 'feed', 1, 'Feed: Hangs');
    checkEq('health: recovery from a blip is not journaled either', [], $entries());
    checkEq('health: the blip is still in the table', 'ok', $hdb->scalar('SELECT status FROM system_health WHERE subject = ?', ['feed:9']));
    $health->recordFailure('feed:9', 'feed', 1, 'Feed: Hangs', 'HTTP 404');
    $health->recordFailure('feed:9', 'feed', 1, 'Feed: Hangs', 'HTTP 404');
    checkEq('health: the second consecutive failure is journaled once', ['Feed: Hangs started failing: HTTP 404'], $entries());
    $health->recordFailure('feed:9', 'feed', 1, 'Feed: Hangs', 'HTTP 404');
    checkEq('health: a third failure adds nothing', 1, count($entries()));
    checkEq('health: streak counted', 3, (int) $hdb->scalar('SELECT consecutive_failures FROM system_health WHERE subject = ?', ['feed:9']));
    $health->recordOk('feed:9', 'feed', 1, 'Feed: Hangs');
    check('health: recovery after a real streak is journaled', str_starts_with($entries()[1] ?? '', 'Feed: Hangs recovered after 3 failures'));
    $health->recordFailure('feed:10', 'feed', 1, 'Feed: New', 'timeout');
    checkEq('health: a brand-new subject failing once is not journaled', 2, count($entries()));
}

// --- Web-server neutrality ------------------------------------------------------
// Cache policy belongs to the app, not to one nginx vhost: whichever server is
// in front, and however it is configured, the browser gets the same answer.
{
    $sf = BetterCal\Http\StaticFiles::class;
    checkEq('static: the service worker is never cached blind', 'no-cache', $sf::cacheControl('sw.js'));
    checkEq('static: the document revalidates', 'no-cache', $sf::cacheControl('index.html'));
    checkEq('static: app modules revalidate', 'no-cache', $sf::cacheControl('src/app/main.js'));
    checkEq('static: styles revalidate', 'no-cache', $sf::cacheControl('styles/app.css'));
    checkEq('static: vendor files cache for 30 days, immutable', 'public, max-age=2592000, immutable', $sf::cacheControl('vendor/preact.module.js'));
    checkEq('static: a leading slash changes nothing', 'public, max-age=2592000, immutable', $sf::cacheControl('/vendor/leaflet/leaflet.js'));
    checkEq('static: "vendor" elsewhere in the path is not vendor/', 'no-cache', $sf::cacheControl('src/vendor/x.js'));
    $tag = $sf::etag(1234, 1789000000);
    check('static: etag is a weak validator', str_starts_with($tag, 'W/"') && str_ends_with($tag, '"'));
    check('static: etag changes with size', $tag !== $sf::etag(1235, 1789000000));
    check('static: etag changes with mtime', $tag !== $sf::etag(1234, 1789000001));
    check('static: If-None-Match with the same tag matches', $sf::matches($tag, $tag));
    check('static: matches inside a list', $sf::matches('"abc", ' . $tag . ', "def"', $tag));
    check('static: weak and strong forms compare equal', $sf::matches(substr($tag, 2), $tag));
    check('static: * matches', $sf::matches('*', $tag));
    check('static: a different tag does not match', !$sf::matches('W/"1-2"', $tag));
    check('static: no header, no match', !$sf::matches(null, $tag) && !$sf::matches('', $tag));

    // Apache + PHP-FPM hands Authorization over under a REDIRECT_ prefix (or
    // not at all); API tokens must not depend on which server is in front.
    $serverBackup = $_SERVER;
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/me', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer bc_x'];
    checkEq('request: Authorization restored from REDIRECT_HTTP_AUTHORIZATION', 'Bearer bc_x', BetterCal\Http\Request::fromGlobals()->header('Authorization'));
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/me', 'HTTP_AUTHORIZATION' => 'Bearer real', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer other'];
    checkEq('request: a real Authorization header wins', 'Bearer real', BetterCal\Http\Request::fromGlobals()->header('Authorization'));
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/me'];
    checkEq('request: no Authorization anywhere is null', null, BetterCal\Http\Request::fromGlobals()->header('Authorization'));
    $_SERVER = $serverBackup;
}

// --- All-day boundaries are dates, not instants --------------------------------
// The server used to convert the sent instant into the event's zone and floor
// it, so the stored date depended on where the BROWSER was: "June 2" edited
// from UTC+1 onto a Pacific (or UTC) event became June 1, and the server's own
// "+00:00 midnight" serialization echoed back by an API client lost a day
// anywhere west of UTC. The date written is the date meant, in every zone.
{
    $day = static fn(string $iso, string $tzid): string => Time::parseAllDay($iso, $tzid)->setTimezone(Time::zone($tzid))->format('Y-m-d H:i');
    foreach (['America/Los_Angeles', 'UTC', 'Europe/Lisbon', 'Asia/Tokyo', 'Pacific/Auckland', 'Asia/Kolkata'] as $eventTz) {
        foreach ([
            'bare date' => '2026-06-02',
            'browser midnight, Pacific' => '2026-06-02T00:00:00-07:00',
            'browser midnight, UTC+1 (the travelling case)' => '2026-06-02T00:00:00+01:00',
            'browser midnight, Tokyo' => '2026-06-02T00:00:00+09:00',
            'browser midnight, Auckland' => '2026-06-02T00:00:00+12:00',
            'browser midnight, half-hour zone' => '2026-06-02T00:00:00+05:30',
            'our own serialization echoed back' => '2026-06-02T00:00:00+00:00',
            'Zulu' => '2026-06-02T00:00:00Z',
            'no seconds' => '2026-06-02T00:00+01:00',
            'a time of day (timed event made all-day)' => '2026-06-02T19:00:00-07:00',
            'a time of day, late evening far east' => '2026-06-02T23:30:00+12:00',
        ] as $label => $sent) {
            checkEq("all-day: $label -> June 2 midnight in $eventTz", '2026-06-02 00:00', $day($sent, $eventTz));
        }
    }
    // Legacy clients: "+00:00 midnight" pushed through a local Date. Tabs that
    // were open across the deploy still send these, and they mean the UTC date.
    foreach ([
        'Pacific summer' => '2026-06-01T17:00:00-07:00',
        'Pacific winter' => '2026-01-01T16:00:00-08:00',
        'Berlin' => '2026-06-02T02:00:00+02:00',
        'Tokyo' => '2026-06-02T09:00:00+09:00',
        'Kolkata' => '2026-06-02T05:30:00+05:30',
    ] as $label => $sent) {
        $expected = str_starts_with($sent, '2026-01') ? '2026-01-02 00:00' : '2026-06-02 00:00';
        checkEq("all-day legacy shift ($label) still lands on the intended date", $expected, $day($sent, 'UTC'));
        checkEq("all-day legacy shift ($label), Pacific-zoned event", $expected, $day($sent, 'America/Los_Angeles'));
    }
    // A shift that crossed a DST change is an hour off the UTC midnight it means.
    checkEq('all-day legacy shift across spring-forward (23:00Z)', '2026-03-10 00:00', $day('2026-03-09T16:00:00-07:00', 'UTC'));
    checkEq('all-day legacy shift across fall-back (01:00Z)', '2026-11-03 00:00', $day('2026-11-02T17:00:00-08:00', 'UTC'));
    // Stored instant is midnight in the EVENT's zone.
    checkEq('all-day: Pacific event stored at 07:00Z in summer', '2026-06-02 07:00:00', Time::toDb(Time::parseAllDay('2026-06-02', 'America/Los_Angeles')));
    checkEq('all-day: Pacific event stored at 08:00Z in winter', '2026-01-02 08:00:00', Time::toDb(Time::parseAllDay('2026-01-02', 'America/Los_Angeles')));
    checkEq('all-day: UTC event stored at 00:00Z', '2026-06-02 00:00:00', Time::toDb(Time::parseAllDay('2026-06-02T00:00:00+01:00', 'UTC')));
    foreach (['', 'tomorrow', '2026-13-01', '2026-02-30', '06/02/2026', '2026-06-02x'] as $garbage) {
        try {
            Time::parseAllDay($garbage, 'UTC');
            check("all-day: '$garbage' is rejected", false);
        } catch (\InvalidArgumentException) {
            check("all-day: '$garbage' is rejected", true);
        }
    }
}

// --- Work budgets and ICS correctness (BC-10 to BC-16) --------------------------
{
    $L = BetterCal\Support\Limits::class;

    // BC-14: folding by offset. Same output as the old cut-and-copy version for
    // every width and for multi-byte text, and linear in the length.
    $oldFold = static function (string $line): string {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = [];
        $width = 75;
        while (strlen($line) > $width) {
            $cut = $width;
            while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out[] = substr($line, 0, $cut);
            $line = substr($line, $cut);
            $width = 74;
        }
        $out[] = $line;
        return implode("\r\n ", $out);
    };
    $samples = [str_repeat('a', 75), str_repeat('a', 76), str_repeat('a', 149), str_repeat('a', 150), str_repeat('a', 1000),
        'DESCRIPTION:' . str_repeat('héllo wörld ', 40), 'SUMMARY:' . str_repeat('日本語のテキスト', 30), 'X:' . str_repeat('😀', 60)];
    $same = true;
    foreach ($samples as $s) {
        $same = $same && Ics::fold($s) === $oldFold($s);
    }
    check('fold: byte-for-byte the same output as before, incl. multi-byte text', $same);
    $allValid = true;
    foreach ($samples as $s) {
        foreach (explode("\r\n ", Ics::fold($s)) as $i => $piece) {
            $allValid = $allValid && strlen($piece) <= ($i === 0 ? 75 : 74) && mb_check_encoding($piece, 'UTF-8');
        }
        $allValid = $allValid && Ics::unfold(Ics::fold($s)) === $s;
    }
    check('fold: every piece fits, is valid UTF-8, and unfolds back to the input', $allValid);
    $big = 'DESCRIPTION:' . str_repeat('x', 1048576);
    $t = hrtime(true);
    Ics::fold($big);
    $ms = (hrtime(true) - $t) / 1e6;
    check('fold: 1 MiB folds in linear time (was 117 ms; allow 40)', $ms < 40, round($ms, 1) . ' ms');

    // BC-16: nothing structural can carry a line break out of an export.
    checkEq('structural: CR/LF and other controls are removed', 'abcX-BC-INJECTED:1', Ics::structural("abc\r\nX-BC-INJECTED:1"));
    checkEq('structural: tab, NUL, DEL too', 'abc', Ics::structural("a\tb\x00c\x7F"));
    checkEq('structural: ordinary ids and URLs are untouched', 'https://example.com/a?b=c&d=é#f', Ics::structural('https://example.com/a?b=c&d=é#f'));
    checkEq('uid: cleaned and bounded', 'abcX-BC-INJECTED:1', Ics::uidOrNew("  abc\nX-BC-INJECTED:1 "));
    check('uid: nothing usable left means a fresh one, never an empty uid', strlen(Ics::uidOrNew("\r\n")) > 10);
    $exported = Ics::buildObject([[
        'uid' => "evil\r\nX-BC-INJECTED:1", 'title' => 'T', 'start_utc' => '2026-10-01 10:00:00', 'end_utc' => '2026-10-01 11:00:00',
        'all_day' => 0, 'tzid' => 'UTC', 'status' => 'confirmed', 'url' => "https://x.example/\r\nATTENDEE:mailto:a@b.c", 'rrule' => "FREQ=DAILY\r\nX-EVIL:1",
    ]]);
    check('export: a stored UID/URL/RRULE with a newline cannot add a property line (rows from before the fix)',
        preg_match('/^(X-BC-INJECTED|ATTENDEE|X-EVIL)/m', $exported) === 0);
    check('export: the values are still there, on their own lines', str_contains($exported, "UID:evilX-BC-INJECTED:1\r\n") && str_contains($exported, "URL:https://x.example/ATTENDEE:mailto:a@b.c\r\n"));
    checkEq('clip: cuts by characters, not bytes', 'héll', Ics::clip('héllo', 4));
    checkEq('clip: leaves short text alone', 'héllo', Ics::clip('héllo', 5));

    // BC-12/13/10: decided on the raw text, before any parser runs.
    $cal = static fn(int $n): string => "BEGIN:VCALENDAR\r\n" . str_repeat("BEGIN:VEVENT\r\nUID:x\r\nEND:VEVENT\r\n", $n) . "END:VCALENDAR\r\n";
    checkEq('budget: within both limits', null, Ics::budgetProblem($cal(10), 100000, 10));
    check('budget: one event over', str_contains((string) Ics::budgetProblem($cal(11), 100000, 10), '11 events, over the limit of 10'));
    check('budget: bytes over, said in MiB', str_contains((string) Ics::budgetProblem(str_repeat('x', 3 * 1048576), 2 * 1048576, 10), '3 MiB, over the 2 MiB limit'));
    checkEq('budget: counts VEVENT openings in any case, with trailing space', 2,
        (int) preg_match_all('/^BEGIN:VEVENT[ \t]*\r?$/mi', "begin:vevent \r\nEND:VEVENT\r\nBEGIN:VEVENT\nEND:VEVENT\n"));
    checkEq('budget: nested components and text mentioning it do not count', null,
        Ics::budgetProblem("BEGIN:VEVENT\r\nDESCRIPTION:see BEGIN:VEVENT in the docs\r\nBEGIN:VALARM\r\nEND:VALARM\r\nEND:VEVENT\r\n", 1000, 1));
    checkEq('mail: a calendar-sized "invitation" is not parsed at all', null, MailIngest::parseImip($cal($L::get('MAIL_ICS_EVENTS') + 1)));

    // Limits: one block, overridable, and a typo cannot zero a limit.
    $L::reset();
    checkEq('limits: default', 1048576, $L::get('DAV_OBJECT_BYTES'));
    $L::configure(['DAV_OBJECT_BYTES' => '2097152', 'IMPORT_EVENTS' => '0', 'REGEX_EVALS' => 'lots', 'NOT_A_LIMIT' => '5']);
    checkEq('limits: a valid override applies', 2097152, $L::get('DAV_OBJECT_BYTES'));
    checkEq('limits: zero is ignored, not applied', 20000, $L::get('IMPORT_EVENTS'));
    checkEq('limits: garbage is ignored', 20000, $L::get('REGEX_EVALS'));
    $L::reset();
    checkEq('limits: ini sizes', [268435456, 131072, 1073741824, 0, 0], array_map($L::iniBytes(...), ['256M', '128K', '1G', '-1', 'plenty']));
    checkEq('import budget: plenty of memory means the configured cap', 20000, $L::importEventBudget('2G', 50 * 1048576));
    checkEq('import budget: unlimited memory means the configured cap', 20000, $L::importEventBudget('-1', 50 * 1048576));
    checkEq('import budget: a 128M PHP can hold about 8,000 parsed events', intdiv(128 * 1048576 - 16 * 1048576 - 16 * 1048576, 12288), $L::importEventBudget('128M', 16 * 1048576));
    checkEq('import budget: never below a usable floor', 100, $L::importEventBudget('32M', 31 * 1048576));

    // BC-11: a careless pattern meets text written for it.
    Filters::resetRegexBudget();
    $evil = ['type' => 'regex', 'config' => ['pattern' => '(a+)+$', 'fields' => ['title']]];
    $t = hrtime(true);
    $hit = Filters::evaluate(['title' => str_repeat('a', 40) . '!'], $evil);
    $firstMs = (hrtime(true) - $t) / 1e6;
    check('regex: a catastrophic match gives up instead of hanging, and counts as no match', $hit === false && $firstMs < 1500, round($firstMs) . ' ms');
    $t = hrtime(true);
    for ($i = 0; $i < 200; $i++) {
        Filters::evaluate(['title' => str_repeat('a', 40) . '!'], $evil);
    }
    check('regex: the pattern that gave up is not tried again this run', (hrtime(true) - $t) / 1e6 < 50);
    check('regex: other patterns still work after one gave up', Filters::evaluate(['title' => 'Team standup'], ['type' => 'regex', 'config' => ['pattern' => 'stand.?up', 'fields' => ['title']]]));
    checkEq('regex: PHP\'s own limits are restored after each match', [ini_get('pcre.backtrack_limit'), ini_get('pcre.jit')], (static function () {
        $before = [ini_get('pcre.backtrack_limit'), ini_get('pcre.jit')];
        Filters::evaluate(['title' => 'x'], ['type' => 'regex', 'config' => ['pattern' => 'x', 'fields' => ['title']]]);
        return $before;
    })());
    Filters::resetRegexBudget();
    $L::configure(['REGEX_EVALS' => 5]);
    $plain = ['type' => 'regex', 'config' => ['pattern' => 'dinner', 'fields' => ['title']]];
    $hits = 0;
    for ($i = 0; $i < 8; $i++) {
        $hits += Filters::evaluate(['title' => 'dinner'], $plain) ? 1 : 0;
    }
    checkEq('regex: past the run\'s budget, regex filters stop matching (fail open)', 5, $hits);
    check('regex: keyword filters are not part of that budget', Filters::evaluate(['title' => 'dinner'], ['type' => 'keyword', 'config' => ['pattern' => 'dinner', 'fields' => ['title']]]));
    $L::reset();
    Filters::resetRegexBudget();

    // BC-10: a message is recorded as started before its body is touched.
    $mdb = new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]);
    $mdb->run('CREATE TABLE mail_ingest (id INTEGER PRIMARY KEY, message_id TEXT UNIQUE, subject TEXT, from_addr TEXT, tier TEXT, outcome TEXT NOT NULL, event_id INTEGER, error TEXT, processed_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $mdb->run('CREATE TABLE review_items (id INTEGER PRIMARY KEY)');
    $mail = new MailIngest($mdb, (new ReflectionClass(Events::class))->newInstanceWithoutConstructor(), null);
    $env = ['messageId' => 'poison@example.test', 'subject' => 'Huge', 'from' => 'x@example.test'];
    check('mail: a new message may begin', $mail->begin($env));
    checkEq('mail: it is logged as started before anything is parsed', 'started', $mdb->scalar("SELECT outcome FROM mail_ingest WHERE message_id = 'poison@example.test'"));
    check('mail: after a crash, the next run refuses to walk into it again', $mail->begin($env) === false);
    $mail->abort('poison@example.test');
    check('mail: a retryable failure forgets the start, so it IS retried', $mail->begin($env));
    $r = $mail->ingestMessage(1, $env + ['icsParts' => [], 'html' => null, 'text' => null, 'oversize' => 9000000], 'UTC');
    checkEq('mail: an oversize message is skipped and says why', ['skipped', 'message too large to ingest (9,000,000 bytes)'], [$r['outcome'], $r['error']]);
    checkEq('mail: the started row becomes the outcome, not a second row', ['skipped', 1],
        [$mdb->scalar("SELECT outcome FROM mail_ingest WHERE message_id = 'poison@example.test'"), (int) $mdb->scalar('SELECT COUNT(*) FROM mail_ingest')]);
    checkEq('mail: a finished message is a duplicate next time', 'duplicate', $mail->ingestMessage(1, $env + ['icsParts' => [], 'html' => null, 'text' => null], 'UTC')['error']);
    check('mail: and may not begin again', $mail->begin($env) === false);
    $direct = $mail->ingestMessage(1, ['messageId' => 'direct@example.test', 'subject' => 'No begin', 'from' => 'y@example.test', 'icsParts' => [], 'html' => null, 'text' => 'hello'], 'UTC');
    checkEq('mail: callers that never call begin() still get a log row', 1, (int) $mdb->scalar("SELECT COUNT(*) FROM mail_ingest WHERE message_id = 'direct@example.test'"));
}

// --- Sign-in throttle (BC-05/BC-06, issue #20) ----------------------------------
{
    $cip = BetterCal\Support\ClientIp::class;
    // Default: not behind a proxy. The header is attacker-controlled and ignored.
    checkEq('client ip: the peer address, by default', '203.0.113.7', $cip::resolve(['REMOTE_ADDR' => '203.0.113.7']));
    checkEq('client ip: X-Forwarded-For is ignored unless the peer is a trusted proxy', '203.0.113.7',
        $cip::resolve(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1']));
    checkEq('client ip: an untrusted peer cannot claim to be someone else even when proxies are configured', '203.0.113.7',
        $cip::resolve(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1'], ['10.0.0.0/8']));
    // Behind a trusted proxy: read from the RIGHT. The left is what the client typed.
    checkEq('client ip: behind a trusted proxy, the address the proxy saw', '198.51.100.1',
        $cip::resolve(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1'], ['10.0.0.0/8']));
    checkEq('client ip: a spoofed leftmost entry is not believed', '198.51.100.1',
        $cip::resolve(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.1'], ['10.0.0.0/8']));
    checkEq('client ip: a chain of trusted proxies is walked through', '198.51.100.1',
        $cip::resolve(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.1, 10.0.0.9'], ['10.0.0.0/8']));
    checkEq('client ip: garbage in the chain falls back to the peer', '10.0.0.5',
        $cip::resolve(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1, not-an-ip'], ['10.0.0.0/8']));
    checkEq('client ip: trusted proxy but no header is the proxy itself', '10.0.0.5', $cip::resolve(['REMOTE_ADDR' => '10.0.0.5'], ['10.0.0.5']));
    checkEq('client ip: ports and brackets are stripped', ['198.51.100.1', '2001:db8::1'], [$cip::normalize('198.51.100.1:443'), $cip::normalize('[2001:DB8::1]:443')]);
    checkEq('client ip: IPv4-mapped IPv6 is the IPv4 address', '198.51.100.1', $cip::normalize('::ffff:198.51.100.1'));
    checkEq('client ip: nothing usable is "unknown", never an empty bucket', 'unknown', $cip::resolve([]));
    check('cidr: inside', $cip::inRange('10.20.30.40', '10.0.0.0/8'));
    check('cidr: outside', !$cip::inRange('11.0.0.1', '10.0.0.0/8'));
    check('cidr: a non-octet boundary', $cip::inRange('172.20.1.1', '172.16.0.0/12') && !$cip::inRange('172.32.0.1', '172.16.0.0/12'));
    check('cidr: a bare address is an exact match', $cip::inRange('10.0.0.5', '10.0.0.5') && !$cip::inRange('10.0.0.6', '10.0.0.5'));
    check('cidr: IPv6', $cip::inRange('2001:db8:1::9', '2001:db8::/32') && !$cip::inRange('2001:db9::1', '2001:db8::/32'));
    check('cidr: families never match each other', !$cip::inRange('10.0.0.1', '::/0'));
    check('cidr: a malformed prefix allows nothing, not everything', !$cip::inRange('10.0.0.1', '10.0.0.0/abc') && !$cip::inRange('10.0.0.1', '10.0.0.0/99') && !$cip::inRange('10.0.0.1', ''));
    checkEq('bucket: one IPv4 address', '203.0.113.7', $cip::bucket('203.0.113.7'));
    checkEq('bucket: an IPv6 source is its /64', '2001:db8:1:2::/64', $cip::bucket('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
    checkEq('bucket: two hosts in one /64 are one source', $cip::bucket('2001:db8:1:2::1'), $cip::bucket('2001:db8:1:2:ffff::9'));

    $tdb = new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]);
    $tdb->run('CREATE TABLE rate_events (id INTEGER PRIMARY KEY, bucket TEXT, created_at TEXT)');
    $tdb->run('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
    $tdb->run("INSERT INTO users (id, email) VALUES (1, 'owner@example.com')");
    $tdb->run('CREATE TABLE mutations (id INTEGER PRIMARY KEY, user_id INTEGER, entity TEXT, entity_id INTEGER, op TEXT, before_json TEXT, after_json TEXT, source TEXT, run_id TEXT, summary TEXT, details_json TEXT)');
    $throttle = new BetterCal\Infra\Throttle($tdb);
    $t0 = Time::parseIso('2026-09-17T12:00:00+00:00');
    $at = static fn(int $sec) => $t0->add(new DateInterval('PT' . $sec . 'S'));
    foreach ([0, 10, 20] as $s) {
        $throttle->hit('b', $at($s));
    }
    checkEq('throttle: counts inside the window', 3, $throttle->count('b', 60, $at(30)));
    checkEq('throttle: old events fall out', 1, $throttle->count('b', 60, $at(75)));
    checkEq('throttle: under the limit, no wait', 0, $throttle->retryAfter('b', 4, 60, $at(30)));
    checkEq('throttle: at the limit, wait until the oldest counted event leaves', 30, $throttle->retryAfter('b', 3, 60, $at(30)));
    checkEq('throttle: other buckets are separate', 0, $throttle->count('c', 60, $at(30)));
    $throttle->clear('b');
    checkEq('throttle: clear', 0, $throttle->count('b', 60, $at(30)));
    checkEq('throttle: a missing table never blocks anyone', [0, 0], (static function () {
        $broken = new BetterCal\Infra\Throttle(new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]));
        $broken->hit('x');
        return [$broken->count('x', 60), $broken->retryAfter('x', 1, 60)];
    })());

    $guard = new BetterCal\Domain\LoginGuard($throttle, [], 3, $tdb);
    $bad = '203.0.113.7';
    checkEq('guard: a fresh source may try', 0, $guard->retryAfter($bad, $at(100)));
    $guard->failed($bad, 'web', $at(100));
    $guard->failed($bad, 'caldav', $at(110));
    checkEq('guard: still allowed below the limit', 0, $guard->retryAfter($bad, $at(115)));
    $guard->failed($bad, 'web', $at(120));
    check('guard: blocked at the limit, across BOTH doors', $guard->retryAfter($bad, $at(121)) > 0);
    checkEq('guard: for the rest of the window', 100 + BetterCal\Domain\LoginGuard::WINDOW - 121, $guard->retryAfter($bad, $at(121)));
    checkEq('guard: another source is unaffected', 0, $guard->retryAfter('198.51.100.9', $at(121)));
    checkEq('guard: allowed again once the window rolls on', 0, $guard->retryAfter($bad, $at(100 + BetterCal\Domain\LoginGuard::WINDOW + 1)));
    checkEq('guard: the block is journaled once, naming the source', ['Blocked sign-in attempts from 203.0.113.7 after 3 wrong passwords (web)'],
        array_column($tdb->all("SELECT summary FROM mutations WHERE op = 'refuse'"), 'summary'));
    // F12/F15 (scan 2026-09-23): the attempt is counted before the password
    // is checked, so a burst of concurrent requests cannot all slip in.
    $g2 = new BetterCal\Domain\LoginGuard($throttle, [], 3, $tdb);
    $burst = '198.18.0.50';
    $allowed = 0;
    for ($i = 0; $i < 30; $i++) {
        if ((new BetterCal\Domain\LoginGuard($throttle, [], 3, $tdb))->begin($burst, $at(500)) === 0) {
            $allowed++; // none of these ever reports an outcome: all still "in flight"
        }
    }
    checkEq('guard: 30 concurrent attempts, a bounded number get a password check', 3 + BetterCal\Domain\LoginGuard::INFLIGHT_SLACK, $allowed);
    $tdb->run("DELETE FROM rate_events WHERE bucket LIKE 'auth-try:%'");
    $seq = '198.18.0.51';
    for ($i = 0; $i < 3; $i++) {
        $one = new BetterCal\Domain\LoginGuard($throttle, [], 3, $tdb);
        if ($one->begin($seq, $at(510 + $i)) === 0) {
            $one->rejected($seq, 'web', $at(510 + $i));
        }
    }
    check('guard: after the limit of real failures, the source is refused', (new BetterCal\Domain\LoginGuard($throttle, [], 3, $tdb))->begin($seq, $at(520)) > 0);
    $ok = '198.18.0.60';
    for ($i = 0; $i < 20; $i++) {
        $one = new BetterCal\Domain\LoginGuard($throttle, [], 3, $tdb);
        if ($one->begin($ok, $at(600 + $i)) === 0) {
            $one->succeeded($ok, $at(600 + $i));
        }
    }
    checkEq('guard: a device that always succeeds is never limited (successes release their slot)', 0, $g2->retryAfter($ok, $at(640)));
    // F14: the overall brake needs failures from many different sources.
    $few = new BetterCal\Infra\Throttle($tdb);
    $tdb->run('DELETE FROM rate_events');
    for ($i = 0; $i < 70; $i++) {
        $few->hit('auth-fail:all', $at(700));
        $few->hit('auth-fail:ip:203.0.113.' . ($i % 3), $at(700));
    }
    checkEq('guard: 70 failures from 3 sources do not shut out a new device (F14)', 0, $g2->retryAfter('198.51.100.200', $at(701)));
    for ($i = 0; $i < 6; $i++) {
        $few->hit('auth-fail:ip:192.0.2.' . (100 + $i), $at(700));
    }
    checkEq('guard: failures from 9 sources still do not shut out a new device (F14 needs a real crowd)', 0, $g2->retryAfter('198.51.100.200', $at(701)));
    for ($i = 0; $i < BetterCal\Domain\LoginGuard::GLOBAL_SOURCES; $i++) {
        $few->hit('auth-fail:ip:192.0.2.' . (150 + $i), $at(700));
    }
    check('guard: with failures from many sources the brake does engage', $g2->retryAfter('198.51.100.200', $at(701)) > 0);
    $tdb->run('DELETE FROM rate_events');

    // A typo or two, then the right password: the source is known, and its
    // failures are NOT wiped (a success must not reset the guessing budget,
    // or a token or a NAT neighbour's syncing phone launders it).
    $home = '192.0.2.10';
    $guard->failed($home, 'web', $at(200));
    $guard->recordSuccess($home, $at(210));
    $guard->failed($home, 'web', $at(215));
    checkEq('guard: a typo or two after a success still leaves room', 0, $guard->retryAfter($home, $at(217)));
    check('guard: a success makes the source known', $guard->isKnown($home, $at(217)) && !$guard->isKnown($bad, $at(217)));
    $launder = '192.0.2.77';
    for ($i = 0; $i < 3; $i++) {
        $guard->failed($launder, 'web', $at(220 + $i));
        $guard->recordSuccess($launder, $at(230 + $i));
    }
    check('guard: successes between failures do not reset the budget (F2/F13)', $guard->retryAfter($launder, $at(240)) > 0);
    $guard->recordSuccess($home, $at(300));
    $guard->recordSuccess($home, $at(301));
    checkEq('guard: a syncing CalDAV client does not add a row per request', 1, (int) $tdb->scalar("SELECT COUNT(*) FROM rate_events WHERE bucket = 'auth-ok:ip:192.0.2.10'"));
    // Distributed guessing: many sources, a couple of tries each, never
    // reaching the per-source limit. The overall limit closes the door to
    // strangers, and the owner's usual address still gets in.
    for ($i = 0; $i < BetterCal\Domain\LoginGuard::GLOBAL_MAX; $i++) {
        $guard->failed('198.18.' . intdiv($i, 250) . '.' . ($i % 250), 'web', $at(400 + $i));
    }
    check('guard: a never-seen source is refused while the attack lasts', $guard->retryAfter('198.19.0.1', $at(500)) > 0);
    checkEq('guard: the owner\'s known address is not locked out by it', 0, $guard->retryAfter($home, $at(500)));
    checkEq('guard: strangers are allowed again when it subsides', 0, $guard->retryAfter('198.19.0.1', $at(400 + BetterCal\Domain\LoginGuard::GLOBAL_MAX + BetterCal\Domain\LoginGuard::WINDOW + 1)));
    checkEq('guard: source comes from the request, IPv6 as its /64', '2001:db8:1:2::/64', $guard->source(['REMOTE_ADDR' => '2001:db8:1:2::77']));
}

// --- Password reset ends the sessions opened under the old password (BC-21) ---
// A reset is the owner's response to "someone else may be in". Sessions last
// 180 days and resolve() checks only hash + expiry, so the intruder's cookie
// used to survive the reset and could mint a fresh API token.
{
    $pdb = new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]);
    $pdb->run('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, display_name TEXT, settings_json TEXT)');
    $pdb->run('CREATE TABLE sessions (token_hash TEXT PRIMARY KEY, user_id INTEGER, csrf TEXT, expires_at TEXT, last_seen_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdb->run('CREATE TABLE api_tokens (id INTEGER PRIMARY KEY, user_id INTEGER, token_hash TEXT)');
    $pdb->run('CREATE TABLE push_subscriptions (id INTEGER PRIMARY KEY, user_id INTEGER, endpoint TEXT)');
    $pdb->run('CREATE TABLE out_feeds (id INTEGER PRIMARY KEY, user_id INTEGER, token TEXT)');
    $pdb->run("INSERT INTO push_subscriptions (user_id, endpoint) VALUES (1, 'https://fcm.googleapis.com/a'), (1, 'https://attacker.example/b'), (2, 'https://fcm.googleapis.com/c')");
    $pdb->run("INSERT INTO out_feeds (user_id, token) VALUES (1, 'feed-one'), (2, 'feed-other')");
    $pdb->run("INSERT INTO users (id, email, password_hash, display_name) VALUES (1, 'owner@example.com', ?, 'Owner'), (2, 'other@example.com', ?, 'Other')", [
        password_hash('old-password', PASSWORD_DEFAULT),
        password_hash('other-password', PASSWORD_DEFAULT),
    ]);
    $pdb->run("INSERT INTO api_tokens (user_id, token_hash) VALUES (1, 'a'), (1, 'b'), (2, 'c')");
    $pdb->run("UPDATE users SET settings_json = '{\"notifyEmail\":\"attacker@example.com\"}' WHERE id = 1");
    $pauth = new BetterCal\Domain\Auth($pdb, ['base_url' => 'https://cal.example.com']);
    $stolen = $pauth->login('owner@example.com', 'old-password');
    $mine = $pauth->login('owner@example.com', 'old-password');
    $bystander = $pauth->login('other@example.com', 'other-password');
    check('reset: sessions resolve before the reset', $pauth->resolve($stolen['token']) !== null && $pauth->resolve($mine['token']) !== null);

    $revoked = $pauth->setPassword(1, 'new-password');
    checkEq('reset: both of the owner\'s sessions are reported revoked', 2, $revoked['sessions']);
    checkEq('reset: every push device of the owner goes (F5)', [2, 0], [$revoked['pushDevices'], (int) $pdb->scalar('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = 1')]);
    checkEq('reset: another user\'s push device stays', 1, (int) $pdb->scalar('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = 2'));
    checkEq('reset: a routine reset keeps feed addresses and the email setting', ['feed-one', 0, false], [$pdb->scalar('SELECT token FROM out_feeds WHERE user_id = 1'), $revoked['feedsRotated'], $revoked['notifyEmailReset']]);
    check('reset: a session opened under the old password no longer resolves', $pauth->resolve($stolen['token']) === null);
    check('reset: the owner\'s own old session is ended too', $pauth->resolve($mine['token']) === null);
    check('reset: another user\'s session is untouched', $pauth->resolve($bystander['token']) !== null);
    check('reset: the old password stops working', $pauth->login('owner@example.com', 'old-password') === null);
    check('reset: the new password works', $pauth->login('owner@example.com', 'new-password') !== null);
    checkEq('reset: API tokens survive a routine reset', 2, (int) $pdb->scalar('SELECT COUNT(*) FROM api_tokens WHERE user_id = 1'));

    $revoked = $pauth->setPassword(1, 'newer-password', true);
    checkEq('reset --revoke-tokens: the session from the last login and both tokens go', [1, 2], [$revoked['sessions'], $revoked['tokens']]);
    check('reset --revoke-tokens: the owner\'s feed gets a new address (F6)', $revoked['feedsRotated'] === 1 && $pdb->scalar('SELECT token FROM out_feeds WHERE user_id = 1') !== 'feed-one');
    checkEq('reset --revoke-tokens: another user\'s feed keeps its address', 'feed-other', $pdb->scalar('SELECT token FROM out_feeds WHERE user_id = 2'));
    check('reset --revoke-tokens: a foreign reminder address is cleared (F6)', $revoked['notifyEmailReset'] && json_decode((string) $pdb->scalar('SELECT settings_json FROM users WHERE id = 1'), true)['notifyEmail'] === null);
    checkEq('reset --revoke-tokens: another user\'s token is untouched', 1, (int) $pdb->scalar('SELECT COUNT(*) FROM api_tokens WHERE user_id = 2'));
}

// --- Device cookies: the owner's browsers get past the sign-in brake (#59) ---
{
    $ddb = new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]);
    $ddb->run('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, display_name TEXT, settings_json TEXT)');
    $ddb->run('CREATE TABLE sessions (token_hash TEXT PRIMARY KEY, user_id INTEGER, csrf TEXT, expires_at TEXT, last_seen_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $ddb->run('CREATE TABLE trusted_devices (id INTEGER PRIMARY KEY, user_id INTEGER, token_hash TEXT UNIQUE, created_at TEXT, expires_at TEXT)');
    $ddb->run('CREATE TABLE rate_events (id INTEGER PRIMARY KEY, bucket TEXT, created_at TEXT)');
    $ddb->run('CREATE TABLE mutations (id INTEGER PRIMARY KEY, user_id INTEGER, entity TEXT, entity_id INTEGER, op TEXT, before_json TEXT, after_json TEXT, source TEXT, run_id TEXT, summary TEXT, details_json TEXT)');
    $ddb->run('CREATE TABLE api_tokens (id INTEGER PRIMARY KEY, user_id INTEGER, token_hash TEXT)');
    $ddb->run('CREATE TABLE push_subscriptions (id INTEGER PRIMARY KEY, user_id INTEGER, endpoint TEXT)');
    $ddb->run('CREATE TABLE out_feeds (id INTEGER PRIMARY KEY, user_id INTEGER, token TEXT)');
    $ddb->run("INSERT INTO users (id, email, password_hash, display_name) VALUES (1, 'owner@example.com', ?, 'Owner'), (2, 'other@example.com', ?, 'Other')", [
        password_hash('right-password', PASSWORD_DEFAULT),
        password_hash('other-password', PASSWORD_DEFAULT),
    ]);
    $devices = new BetterCal\Domain\TrustedDevices($ddb);
    $d0 = Time::parseIso('2026-09-24T12:00:00+00:00');
    $later = static fn(string $spec) => $d0->add(new DateInterval($spec));

    $tok = $devices->remember(1, null, $d0);
    check('devices: a random cookie value is issued', is_string($tok) && preg_match('/^[A-Za-z0-9_-]{43}$/', $tok) === 1);
    check('devices: only its hash is stored', (int) $ddb->scalar('SELECT COUNT(*) FROM trusted_devices WHERE token_hash = ?', [$tok]) === 0);
    $id = $devices->find($tok, 'owner@example.com', $later('PT1H'));
    check('devices: the cookie names its device', is_int($id));
    checkEq('devices: it vouches only for its own account', null, $devices->find($tok, 'other@example.com', $later('PT1H')));
    checkEq('devices: junk, empty and missing cookies name nothing', [null, null, null], [$devices->find('not-a-device', 'owner@example.com'), $devices->find('', 'owner@example.com'), $devices->find(null, 'owner@example.com')]);
    checkEq('devices: it expires after a year', null, $devices->find($tok, 'owner@example.com', $later('P366D')));
    $tok2 = $devices->remember(1, $id, $later('PT2H'));
    checkEq('devices: signing in again replaces the cookie, and the old value stops working', [null, true], [$devices->find($tok, 'owner@example.com', $later('PT3H')), $devices->find($tok2, 'owner@example.com', $later('PT3H')) !== null]);
    $newest = null;
    for ($i = 0; $i < 25; $i++) {
        $newest = $devices->remember(1, null, $later('PT' . (3 + $i) . 'H'));
    }
    checkEq('devices: at most MAX_PER_USER kept per account', BetterCal\Domain\TrustedDevices::MAX_PER_USER, (int) $ddb->scalar('SELECT COUNT(*) FROM trusted_devices WHERE user_id = 1'));
    check('devices: the newest are the ones kept', $devices->find($newest, 'owner@example.com', $later('P1D')) !== null && $devices->find($tok2, 'owner@example.com', $later('P1D')) === null);
    $ddb->run('DELETE FROM trusted_devices');
    $broken = new BetterCal\Domain\TrustedDevices(new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]));
    checkEq('devices: before the migration runs, nothing vouches and nothing breaks', [null, null, 0], [$broken->find(str_repeat('a', 43), 'owner@example.com'), $broken->remember(1), $broken->forgetAll(1)]);

    // The whole sign-in path, with the overall brake held on by a crowd.
    $savedAddr = $_SERVER['REMOTE_ADDR'] ?? null;
    $dthrottle = new BetterCal\Infra\Throttle($ddb);
    $holdBrake = static function () use ($dthrottle): void {
        for ($i = 0; $i < BetterCal\Domain\LoginGuard::GLOBAL_MAX; $i++) {
            $dthrottle->hit('auth-fail:all');
            $dthrottle->hit('auth-fail:ip:192.0.2.' . ($i % BetterCal\Domain\LoginGuard::GLOBAL_SOURCES));
        }
    };
    $holdBrake();
    $dauth = new BetterCal\Domain\Auth($ddb, ['base_url' => 'https://cal.example.com']);
    $login = static function (string $addr, string $password, array $cookies = []) use ($dauth, $dthrottle, $ddb, $devices): BetterCal\Http\Response {
        $_SERVER['REMOTE_ADDR'] = $addr;
        $ctl = new BetterCal\Http\Controllers\AuthController($dauth, new BetterCal\Domain\LoginGuard($dthrottle, [], 10, $ddb), $devices);
        return $ctl->login(new BetterCal\Http\Request('POST', '/api/v1/auth/login', [], ['email' => 'owner@example.com', 'password' => $password], [], $cookies));
    };
    $setCookies = static function (BetterCal\Http\Response $r): array {
        $out = [];
        foreach ($r->cookies as [$name, $value, $options]) {
            $out[$name] = ['value' => $value, 'options' => $options];
        }
        return $out;
    };
    $r = $login('198.51.100.44', 'right-password');
    checkEq('brake: a new browser on a new network is refused, even with the right password', 429, $r->status);
    check('brake: the refusal says new devices are paused, not that this network guessed', str_contains($r->body, 'new devices are paused') && !str_contains($r->body, 'from this network'));
    $mine = $devices->remember(1);
    $r = $login('198.51.100.45', 'right-password', [BetterCal\Domain\TrustedDevices::COOKIE => $mine]);
    checkEq('brake: a browser that signed in before gets through from a new network', 200, $r->status);
    $c = $setCookies($r);
    check('brake: it gets a session and a fresh device cookie', isset($c['bc_session'], $c['bc_device']) && $c['bc_device']['value'] !== $mine);
    check('brake: the cookie it presented is retired', $devices->find($mine, 'owner@example.com') === null && $devices->find($c['bc_device']['value'], 'owner@example.com') !== null);
    $o = $c['bc_device']['options'];
    checkEq('device cookie: only sent to the sign-in endpoints, never to scripts or other sites, over https', ['/api/v1/auth', true, 'Strict', true], [$o['path'], $o['httponly'], $o['samesite'], $o['secure']]);
    check('device cookie: lives about a year', $o['expires'] > time() + 360 * 86400);

    // A copied cookie is not a guessing ticket: wrong passwords count against it.
    $copied = $devices->remember(1);
    $codes = [];
    for ($i = 0; $i < BetterCal\Domain\LoginGuard::DEVICE_MAX; $i++) {
        $codes[] = $login('203.0.113.80', 'guess-' . $i, [BetterCal\Domain\TrustedDevices::COOKIE => $copied])->status;
    }
    checkEq('brake: wrong passwords with a device cookie are ordinary failures', array_fill(0, BetterCal\Domain\LoginGuard::DEVICE_MAX, 401), $codes);
    checkEq('brake: after DEVICE_MAX of them the cookie stops vouching', 429, $login('203.0.113.81', 'right-password', [BetterCal\Domain\TrustedDevices::COOKIE => $copied])->status);
    // The per-address limit still applies to a remembered browser.
    $other = $devices->remember(1);
    for ($i = 0; $i < 10; $i++) {
        $dthrottle->hit('auth-fail:ip:198.51.100.90');
    }
    $r = $login('198.51.100.90', 'right-password', [BetterCal\Domain\TrustedDevices::COOKIE => $other]);
    check('brake: a device cookie does not lift the per-address limit', $r->status === 429 && str_contains($r->body, 'from this network'));
    // Without the brake, the first sign-in on a browser is what earns the cookie.
    $ddb->run('DELETE FROM rate_events');
    $r = $login('198.51.100.60', 'right-password');
    check('no brake: an ordinary sign-in succeeds and the browser is remembered', $r->status === 200 && isset($setCookies($r)['bc_device']));
    checkEq('no brake: a wrong password is still just a 401', 401, $login('198.51.100.61', 'nope')->status);

    // A reset forgets every remembered browser of that account.
    $ddb->run("INSERT INTO trusted_devices (user_id, token_hash, created_at, expires_at) VALUES (2, 'x', '2026-09-24 00:00:00', '2099-01-01 00:00:00')");
    $rev = $dauth->setPassword(1, 'new-password');
    check('reset: remembered browsers are forgotten', $rev['trustedDevices'] > 0 && (int) $ddb->scalar('SELECT COUNT(*) FROM trusted_devices WHERE user_id = 1') === 0);
    checkEq('reset: another account\'s browsers are untouched', 1, (int) $ddb->scalar('SELECT COUNT(*) FROM trusted_devices WHERE user_id = 2'));
    if ($savedAddr === null) {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $savedAddr;
    }
}

// --- Sign out everywhere else: the in-app answer to a lost device ---
{
    $odb = new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]);
    $odb->run('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, display_name TEXT, settings_json TEXT)');
    $odb->run('CREATE TABLE sessions (token_hash TEXT PRIMARY KEY, user_id INTEGER, csrf TEXT, expires_at TEXT, last_seen_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $odb->run('CREATE TABLE trusted_devices (id INTEGER PRIMARY KEY, user_id INTEGER, token_hash TEXT UNIQUE, created_at TEXT, expires_at TEXT)');
    $odb->run('CREATE TABLE push_subscriptions (id INTEGER PRIMARY KEY, user_id INTEGER, endpoint TEXT, endpoint_hash TEXT)');
    $odb->run('CREATE TABLE api_tokens (id INTEGER PRIMARY KEY, user_id INTEGER, token_hash TEXT)');
    $odb->run('CREATE TABLE mutations (id INTEGER PRIMARY KEY, user_id INTEGER, entity TEXT, entity_id INTEGER, op TEXT, before_json TEXT, after_json TEXT, source TEXT, run_id TEXT, summary TEXT, details_json TEXT)');
    $odb->run("INSERT INTO users (id, email, password_hash) VALUES (1, 'owner@example.com', ?), (2, 'other@example.com', ?)", [
        password_hash('pw', PASSWORD_DEFAULT), password_hash('pw2', PASSWORD_DEFAULT),
    ]);
    $oauth = new BetterCal\Domain\Auth($odb, ['base_url' => 'https://cal.example.com']);
    $odev = new BetterCal\Domain\TrustedDevices($odb);
    $here = $oauth->login('owner@example.com', 'pw');
    $lost = $oauth->login('owner@example.com', 'pw');
    $laptop = $oauth->login('owner@example.com', 'pw');
    $theirs = $oauth->login('other@example.com', 'pw2');
    $hereDev = $odev->remember(1);
    $lostDev = $odev->remember(1);
    $theirDev = $odev->remember(2);
    $hereHash = hash('sha256', 'https://fcm.googleapis.com/here');
    $odb->run('INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash) VALUES (1, ?, ?), (1, ?, ?), (2, ?, ?)', [
        'https://fcm.googleapis.com/here', $hereHash,
        'https://fcm.googleapis.com/lost', hash('sha256', 'https://fcm.googleapis.com/lost'),
        'https://fcm.googleapis.com/theirs', hash('sha256', 'https://fcm.googleapis.com/theirs'),
    ]);
    $odb->run("INSERT INTO api_tokens (user_id, token_hash) VALUES (1, 'k')");
    checkEq('sessions: the count leaves out this browser', 2, $oauth->otherSessionCount(1, $here['token']));

    $octl = new BetterCal\Http\Controllers\AuthController($oauth, null, $odev);
    $asOwner = static function (string $method, string $path, array $body, array $cookies) use ($oauth): BetterCal\Http\Request {
        $r = new BetterCal\Http\Request($method, $path, [], $body, [], $cookies);
        $r->user = $oauth->resolve($cookies[BetterCal\Domain\Auth::COOKIE])['user'];
        $r->authMethod = 'session';
        return $r;
    };
    $cookies = [BetterCal\Domain\Auth::COOKIE => $here['token'], BetterCal\Domain\TrustedDevices::COOKIE => $hereDev];
    checkEq('sessions: the Account tab sees two other browsers', ['others' => 2], json_decode($octl->otherSessions($asOwner('GET', '/api/v1/auth/sessions', [], $cookies))->body, true));
    $r = $octl->signOutOthers($asOwner('POST', '/api/v1/auth/sign-out-others', ['keepPushHash' => $hereHash], $cookies));
    checkEq('sign out elsewhere: counts what went', ['sessions' => 2, 'devices' => 1, 'pushDevices' => 1], json_decode($r->body, true));
    check('sign out elsewhere: the lost device and the laptop are signed out', $oauth->resolve($lost['token']) === null && $oauth->resolve($laptop['token']) === null);
    check('sign out elsewhere: this browser stays signed in', $oauth->resolve($here['token']) !== null);
    check('sign out elsewhere: only this browser is still remembered', $odev->find($hereDev, 'owner@example.com') !== null && $odev->find($lostDev, 'owner@example.com') === null);
    checkEq('sign out elsewhere: only this browser keeps push reminders', [$hereHash], array_column($odb->all('SELECT endpoint_hash FROM push_subscriptions WHERE user_id = 1'), 'endpoint_hash'));
    checkEq('sign out elsewhere: API keys are left alone', 1, (int) $odb->scalar('SELECT COUNT(*) FROM api_tokens WHERE user_id = 1'));
    check('sign out elsewhere: another account is untouched', $oauth->resolve($theirs['token']) !== null
        && $odev->find($theirDev, 'other@example.com') !== null
        && (int) $odb->scalar('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = 2') === 1);
    checkEq('sign out elsewhere: written to Activity, log-only', [['Signed out everywhere else: 2 other browsers signed out, 1 push device removed', null]],
        array_map(static fn($m) => [$m['summary'], $m['before_json']], $odb->all("SELECT summary, before_json FROM mutations WHERE entity = 'system'")));
    // A malformed push hash keeps nothing rather than matching something odd.
    $again = $oauth->login('owner@example.com', 'pw');
    $odb->run('INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash) VALUES (1, ?, ?)', ['https://fcm.googleapis.com/x', hash('sha256', 'https://fcm.googleapis.com/x')]);
    $r = json_decode($octl->signOutOthers($asOwner('POST', '/api/v1/auth/sign-out-others', ['keepPushHash' => "' OR 1=1 --"], $cookies))->body, true);
    checkEq('sign out elsewhere: a malformed push hash is ignored, so every push device goes', [1, 2, 0], [$r['sessions'], $r['pushDevices'], (int) $odb->scalar('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = 1')]);
    // An API token cannot do it (it must not be able to sign the owner out).
    $tokReq = new BetterCal\Http\Request('POST', '/api/v1/auth/sign-out-others', [], [], [], []);
    $tokReq->user = ['id' => 1, 'email' => 'owner@example.com'];
    $tokReq->authMethod = 'token';
    $refused = null;
    try {
        $octl->signOutOthers($tokReq);
    } catch (BetterCal\Http\HttpError $e) {
        $refused = $e->getMessage();
    }
    check('sign out elsewhere: refused to an API token', $refused !== null);
}

// --- App shell: preload block and service-worker version, filled in when served ---
{
    $AS = BetterCal\Http\AppShell::class;
    $root = sys_get_temp_dir() . '/bc-appshell-' . bin2hex(random_bytes(4));
    foreach (['src/app', 'src/lib', 'styles', 'tests'] as $d) {
        mkdir($root . '/' . $d, 0777, true);
    }
    file_put_contents("$root/index.html", "<head>\n" . $AS::PRELOAD_START . "\n" . $AS::PRELOAD_END . "\n</head>\n");
    file_put_contents("$root/sw.js", "// top\n" . $AS::SW_START . "\nconst VERSION = 'bc-unversioned';\nconst SHELL = [];\n" . $AS::SW_END . "\nself.rest = 1;\n");
    file_put_contents("$root/styles/app.css", 'body{}');
    file_put_contents("$root/src/app/main.js", "import { a } from './a.js';\nexport { b } from '../lib/b.js';\nimport '../lib/side.js';\nconst later = () => import('./lazy.js');\nimport x from 'https://cdn.example/x.js';\n");
    file_put_contents("$root/src/app/a.js", "import { b } from '../lib/b.js';\nexport const a = 1;\n");
    file_put_contents("$root/src/lib/b.js", "export const b = 2;\n");
    file_put_contents("$root/src/lib/side.js", "/* side effect */\n");
    file_put_contents("$root/src/app/lazy.js", "export default 1;\n");
    file_put_contents("$root/tests/t.mjs", "// a test\n");
    $extra = ['/', '/assets/styles/app.css', '/assets/icons/missing.png'];
    $cache = $root . '-cache.json';
    $savedLog = ini_get('error_log');
    ini_set('error_log', $root . '-errors.log'); // the missing-entry notice is expected here

    $shell = new BetterCal\Http\AppShell($root, $cache, $extra);
    checkEq('app shell: static imports breadth-first; dynamic and remote imports left out', ['src/app/main.js', 'src/app/a.js', 'src/lib/b.js', 'src/lib/side.js'], $shell->graph());
    $st = $shell->state();
    check('app shell: the first request computes', $shell->computed);
    checkEq('app shell: preload skips main.js (it has its own script tag)', ['/assets/src/app/a.js', '/assets/src/lib/b.js', '/assets/src/lib/side.js'], $st['modules']);
    checkEq('app shell: shell = fixed entries, then the graph; one missing on disk is left out', ['/', '/assets/styles/app.css', '/assets/src/app/main.js', '/assets/src/app/a.js', '/assets/src/lib/b.js', '/assets/src/lib/side.js'], $st['shell']);
    check('app shell: a missing entry is logged', str_contains((string) @file_get_contents($root . '-errors.log'), 'icons/missing.png'));
    check('app shell: the version is bc- and 12 hex digits', preg_match('/^bc-[0-9a-f]{12}$/', $st['version']) === 1);
    $again = new BetterCal\Http\AppShell($root, $cache, $extra);
    checkEq('app shell: the next request uses the cache', [false, $st['version']], [$again->state()['version'] === $st['version'] ? $again->computed : 'changed', $st['version']]);
    $html = $again->renderIndex();
    check('app shell: the document gets a preload link per module, and none for main.js', str_contains($html, '<link rel="modulepreload" href="/assets/src/lib/b.js">') && !str_contains($html, 'main.js') && str_contains($html, $AS::PRELOAD_END));
    $sw = $again->renderServiceWorker();
    check('app shell: the worker gets its version and shell list, the rest of it untouched', str_contains($sw, 'const VERSION = "' . $st['version'] . '";') && str_contains($sw, '  "/assets/src/lib/side.js",') && str_contains($sw, "self.rest = 1;") && !str_contains($sw, 'bc-unversioned'));
    checkEq('app shell: the committed template itself is not changed', "const VERSION = 'bc-unversioned';", (static function () use ($root) { preg_match("/const VERSION = '[^']*';/", (string) file_get_contents("$root/sw.js"), $m); return $m[0] ?? ''; })());

    file_put_contents("$root/tests/t.mjs", "// a longer test file now\n");
    $t = new BetterCal\Http\AppShell($root, $cache, $extra);
    checkEq('app shell: editing a test file changes nothing', [$st['version'], false], [$t->state()['version'], $t->computed]);
    file_put_contents("$root/src/lib/b.js", "export const b = 3; // changed\n");
    $t = new BetterCal\Http\AppShell($root, $cache, $extra);
    $v2 = $t->state()['version'];
    check('app shell: changing a module recomputes and gives a new version', $t->computed && $v2 !== $st['version']);
    file_put_contents("$root/src/app/lazy.js", "export default 22;\n");
    $t = new BetterCal\Http\AppShell($root, $cache, $extra);
    check('app shell: any file under web/ recomputes, even one outside the shell', $t->state()['version'] === $v2 && $t->computed);
    file_put_contents($cache, '{not json');
    $t = new BetterCal\Http\AppShell($root, $cache, $extra);
    checkEq('app shell: a corrupt cache file is recomputed, not trusted', [$v2, true], [$t->state()['version'], $t->computed]);
    $nowhere = new BetterCal\Http\AppShell($root, $root . '/no/such/dir/cache.json', $extra);
    checkEq('app shell: an unwritable cache still serves (computing each time)', [$v2, true, $v2, true], [$nowhere->state()['version'], $nowhere->computed, $nowhere->state()['version'], $nowhere->computed]);
    checkEq('app shell: without the markers the text is left alone', 'no markers here', $AS::fillWorker('no markers here', 'bc-x', ['/']));

    ini_set('error_log', (string) $savedLog);
    foreach ([$cache, $root . '-errors.log'] as $f) {
        @unlink($f);
    }
    $rm = static function (string $dir) use (&$rm): void {
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $n) {
            is_dir("$dir/$n") ? $rm("$dir/$n") : unlink("$dir/$n");
        }
        rmdir($dir);
    };
    $rm($root);

    // The real frontend.
    $web = dirname(__DIR__, 2) . '/web';
    $real = new BetterCal\Http\AppShell($web, null);
    $rs = $real->state();
    checkEq('app shell (real): every fixed shell entry exists on disk', [], array_values(array_diff($AS::EXTRA, $rs['shell'])));
    check('app shell (real): the graph reaches the app\'s modules', count($rs['modules']) > 50 && in_array('/assets/src/app/push.js', $rs['modules'], true) && in_array('/assets/vendor/preact.module.js', $rs['modules'], true));
    check('app shell (real): the committed document has an empty preload block', str_contains((string) file_get_contents("$web/index.html"), $AS::PRELOAD_START . "\n" . $AS::PRELOAD_END));
    check('app shell (real): the committed worker is the unversioned template', str_contains((string) file_get_contents("$web/sw.js"), "const VERSION = '" . $AS::UNVERSIONED . "';"));
    check('app shell (real): served, it carries the version', str_contains($real->renderServiceWorker(), 'const VERSION = "' . $rs['version'] . '";'));
}

// --- Google Calendar connector ------------------------------------------------
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Infra\Secrets;

{
    // Sealed credentials round-trip and refuse the wrong key.
    $sealed = Secrets::seal('1//refresh-token', 'secret-a');
    check('secrets: sealed value is not the plaintext', !str_contains($sealed, 'refresh-token'));
    checkEq('secrets: opens with the right secret', '1//refresh-token', Secrets::open($sealed, 'secret-a'));
    $wrong = false;
    try {
        Secrets::open($sealed, 'secret-b');
    } catch (\RuntimeException) {
        $wrong = true;
    }
    check('secrets: wrong secret is refused', $wrong);

    // OAuth state binds the callback to the user and expires.
    $gcfg = ['session_secret' => 'secret-a', 'base_url' => 'https://cal.example', 'google' => ['client_id' => 'cid', 'client_secret' => 'cs']];
    $gauth = new GoogleAuth(new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]), $gcfg);
    $state = $gauth->signState(7, 1_000_000);
    check('google state: verifies for its user', $gauth->verifyState($state, 7, 1_000_100));
    check('google state: rejected for another user', !$gauth->verifyState($state, 8, 1_000_100));
    check('google state: rejected after expiry', !$gauth->verifyState($state, 7, 1_000_000 + 601));
    check('google state: rejected when tampered', !$gauth->verifyState(substr($state, 0, -2) . 'zz', 7, 1_000_100));
    check('google auth url: carries scopes, offline access and the redirect', (static function () use ($gauth): bool {
        $u = $gauth->authUrl(7);
        return str_contains($u, 'calendar.readonly') && str_contains($u, 'calendar.events') && str_contains($u, 'access_type=offline') && str_contains($u, rawurlencode('https://cal.example/api/v1/google/callback'));
    })());
    // What kind of calendar each list entry is, from the id and role Google gives.
    checkEq('google kind: primary is yours', 'yours', GoogleAuth::calendarKind('owner@example.com', 'owner', true));
    checkEq('google kind: owned secondary is yours', 'yours', GoogleAuth::calendarKind('abc@group.calendar.google.com', 'owner', false));
    checkEq('google kind: writer on a secondary is shared', 'shared', GoogleAuth::calendarKind('05bdc@group.calendar.google.com', 'writer', false));
    checkEq('google kind: someone else primary is shared', 'shared', GoogleAuth::calendarKind('friend@gmail.com', 'reader', false));
    checkEq('google kind: ICS import is a feed', 'feed', GoogleAuth::calendarKind('xyz@import.calendar.google.com', 'reader', false));
    checkEq('google kind: holidays are google', 'google', GoogleAuth::calendarKind('en.usa#holiday@group.v.calendar.google.com', 'reader', false));

    // Google event resources to the Ics::parse shape.
    $timed = GoogleSync::toParsed([
        'id' => 'abc', 'iCalUID' => 'abc@google.com', 'status' => 'confirmed', 'summary' => 'Dinner',
        'description' => 'Table for four', 'location' => 'Dishoom, Covent Garden', 'htmlLink' => 'https://www.google.com/calendar/event?eid=abc',
        'start' => ['dateTime' => '2026-09-21T19:00:00+01:00', 'timeZone' => 'Europe/London'],
        'end' => ['dateTime' => '2026-09-21T21:00:00+01:00', 'timeZone' => 'Europe/London'],
    ]);
    checkEq('google->parsed: uid is the iCalUID', 'abc@google.com', $timed['uid']);
    checkEq('google->parsed: timed start in UTC', '2026-09-21 18:00:00', $timed['start_utc']);
    checkEq('google->parsed: timed end in UTC', '2026-09-21 20:00:00', $timed['end_utc']);
    checkEq('google->parsed: tzid from the event', 'Europe/London', $timed['tzid']);
    checkEq('google->parsed: not all-day', 0, $timed['all_day']);
    checkEq('google->parsed: event link becomes the url', 'https://www.google.com/calendar/event?eid=abc', $timed['url']);
    checkEq('google->parsed: no instance for a plain event', null, $timed['recurrence_instance_utc']);

    $allDay = GoogleSync::toParsed([
        'id' => 'd1', 'iCalUID' => 'd1@google.com', 'summary' => '', 'start' => ['date' => '2026-09-21'], 'end' => ['date' => '2026-09-24'],
    ]);
    checkEq('google->parsed: all-day start is midnight UTC like Ics', '2026-09-21 00:00:00', $allDay['start_utc']);
    checkEq('google->parsed: all-day end is exclusive as given', '2026-09-24 00:00:00', $allDay['end_utc']);
    checkEq('google->parsed: all-day flag', 1, $allDay['all_day']);
    checkEq('google->parsed: empty summary gets a title', '(No title)', $allDay['title']);

    $series = GoogleSync::toParsed([
        'id' => 'r1', 'iCalUID' => 'r1@google.com', 'summary' => 'Standup',
        'start' => ['dateTime' => '2026-09-21T09:00:00+01:00', 'timeZone' => 'Europe/London'],
        'end' => ['dateTime' => '2026-09-21T09:15:00+01:00', 'timeZone' => 'Europe/London'],
        'recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=MO', 'EXDATE;TZID=Europe/London:20260928T090000,20261005T090000', 'RDATE;VALUE=DATE:20261101'],
    ]);
    checkEq('google->parsed: rrule without the prefix', 'FREQ=WEEKLY;BYDAY=MO', $series['rrule']);
    checkEq('google->parsed: exdates resolved through the TZID to UTC', ['2026-09-28 08:00:00', '2026-10-05 08:00:00'], $series['exdates']);

    $exception = GoogleSync::toParsed([
        'id' => 'r1_20261012T080000Z', 'iCalUID' => 'r1@google.com', 'summary' => 'Standup (moved)', 'recurringEventId' => 'r1',
        'originalStartTime' => ['dateTime' => '2026-10-12T09:00:00+01:00', 'timeZone' => 'Europe/London'],
        'start' => ['dateTime' => '2026-10-12T10:00:00+01:00', 'timeZone' => 'Europe/London'],
        'end' => ['dateTime' => '2026-10-12T10:15:00+01:00', 'timeZone' => 'Europe/London'],
    ]);
    checkEq('google->parsed: exception keeps the series uid', 'r1@google.com', $exception['uid']);
    checkEq('google->parsed: exception instance is the original start in UTC', '2026-10-12 08:00:00', $exception['recurrence_instance_utc']);

    $tombstone = GoogleSync::toParsed(['id' => 'r1_20261019T080000Z', 'iCalUID' => 'r1@google.com', 'status' => 'cancelled', 'recurringEventId' => 'r1',
        'originalStartTime' => ['dateTime' => '2026-10-19T09:00:00+01:00']]);
    check('google->parsed: cancelled instance is a tombstone with its instance', !empty($tombstone['cancelled']) && $tombstone['recurrence_instance_utc'] === '2026-10-19 08:00:00');
    checkEq('google->parsed: nothing without a uid', null, GoogleSync::toParsed(['status' => 'confirmed']));

    // Incremental merge: changes over a snapshot.
    $snapshot = [$timed, $series, $exception];
    $merged = GoogleSync::applyChanges($snapshot, [
        $tombstone,                                                   // one instance skipped -> EXDATE on the series
        ['cancelled' => true, 'uid' => 'abc@google.com', 'recurrence_instance_utc' => null], // dinner deleted
        GoogleSync::toParsed(['id' => 'n1', 'iCalUID' => 'n1@google.com', 'summary' => 'New', 'start' => ['date' => '2026-11-01'], 'end' => ['date' => '2026-11-02']]),
    ]);
    $byUid = [];
    foreach ($merged as $ev) {
        $byUid[$ev['uid'] . '|' . ($ev['recurrence_instance_utc'] ?? '')] = $ev;
    }
    check('merge: deleted event is gone', !isset($byUid['abc@google.com|']));
    check('merge: new event is present', isset($byUid['n1@google.com|']));
    check('merge: exception survives', isset($byUid['r1@google.com|2026-10-12 08:00:00']));
    checkEq('merge: cancelled instance became an EXDATE on the series', ['2026-09-28 08:00:00', '2026-10-05 08:00:00', '2026-10-19 08:00:00'], $byUid['r1@google.com|']['exdates']);
    $gone = GoogleSync::applyChanges($merged, [['cancelled' => true, 'uid' => 'r1@google.com', 'recurrence_instance_utc' => null]]);
    check('merge: cancelling the series removes master and exception', count(array_filter($gone, static fn(array $e): bool => $e['uid'] === 'r1@google.com')) === 0);

    // Rows back to the parsed shape (what the incremental merge starts from).
    $rows = GoogleSync::rowsToParsed([[
        'uid' => 'r1@google.com', 'title' => 'Standup', 'description' => null, 'location' => null, 'url' => null,
        'start_utc' => '2026-09-21 08:00:00', 'end_utc' => '2026-09-21 08:15:00', 'all_day' => '0', 'tzid' => 'Europe/London',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO', 'exdates_json' => '["2026-09-28 08:00:00"]', 'status' => 'confirmed', 'recurrence_instance_utc' => null,
    ]]);
    checkEq('rows->parsed: exdates decoded', ['2026-09-28 08:00:00'], $rows[0]['exdates']);
    checkEq('rows->parsed: all_day is an int', 0, $rows[0]['all_day']);
}

// --- Google write-through: body shape, instance ids, writability -----------------
use BetterCal\Domain\GoogleWriter;

{
    $timed = GoogleWriter::body([
        'title' => 'Dinner', 'description' => 'Table for four', 'location' => 'Dishoom', 'status' => 'confirmed',
        'start_utc' => '2026-09-21 18:00:00', 'end_utc' => '2026-09-21 20:00:00', 'all_day' => 0, 'tzid' => 'Europe/London',
        'rrule' => null, 'exdates_json' => null,
    ]);
    checkEq('google body: timed start in the event zone with the zone named', ['dateTime' => '2026-09-21T19:00:00+01:00', 'timeZone' => 'Europe/London'], $timed['start']);
    checkEq('google body: timed end', ['dateTime' => '2026-09-21T21:00:00+01:00', 'timeZone' => 'Europe/London'], $timed['end']);
    checkEq('google body: summary/location carried', ['Dinner', 'Dishoom'], [$timed['summary'], $timed['location']]);
    checkEq('google body: no recurrence is an empty list', [], $timed['recurrence']);
    check('google body: attendees and reminders are never sent', !isset($timed['attendees']) && !isset($timed['reminders']));

    // All-day rows created here hold midnight in the event zone (07:00 UTC for Pacific); Google wants the date.
    $allDay = GoogleWriter::body(['title' => 'Yorkshire', 'start_utc' => '2026-09-21 07:00:00', 'end_utc' => '2026-09-25 07:00:00', 'all_day' => 1, 'tzid' => 'America/Los_Angeles']);
    checkEq('google body: all-day dates from the event zone', [['date' => '2026-09-21'], ['date' => '2026-09-25']], [$allDay['start'], $allDay['end']]);
    $allDayUtc = GoogleWriter::body(['title' => 'Bath', 'start_utc' => '2026-09-24 00:00:00', 'end_utc' => '2026-09-27 00:00:00', 'all_day' => 1, 'tzid' => 'UTC']);
    checkEq('google body: all-day dates for a Google-origin row (UTC midnight)', [['date' => '2026-09-24'], ['date' => '2026-09-27']], [$allDayUtc['start'], $allDayUtc['end']]);

    $series = GoogleWriter::body([
        'title' => 'Standup', 'start_utc' => '2026-09-21 08:00:00', 'end_utc' => '2026-09-21 08:15:00', 'all_day' => 0, 'tzid' => 'Europe/London',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO', 'exdates_json' => '["2026-09-28 08:00:00"]', 'status' => 'tentative',
    ]);
    checkEq('google body: recurrence carries RRULE and zoned EXDATE', ['RRULE:FREQ=WEEKLY;BYDAY=MO', 'EXDATE;TZID=Europe/London:20260928T090000'], $series['recurrence']);
    checkEq('google body: tentative survives', 'tentative', $series['status']);
    checkEq('google recurrence: all-day EXDATE is a date', ['RRULE:FREQ=DAILY', 'EXDATE;VALUE=DATE:20260928'], GoogleWriter::recurrenceLines('FREQ=DAILY', ['2026-09-28 00:00:00'], true, 'UTC'));

    checkEq('google instance id: timed is UTC basic with Z', 'abc123_20261012T080000Z', GoogleWriter::instanceId('abc123', '2026-10-12 08:00:00', false));
    checkEq('google instance id: all-day is the date', 'abc123_20261012', GoogleWriter::instanceId('abc123', '2026-10-12 00:00:00', true));

    $cal = ['provider' => 'google', 'google_calendar_id' => 'x@group.calendar.google.com', 'google_account_id' => 1, 'google_access_role' => 'writer'];
    check('google writable: writer role', GoogleWriter::writable($cal));
    check('google writable: owner role', GoogleWriter::writable(['google_access_role' => 'owner'] + $cal));
    check('google writable: reader is not', !GoogleWriter::writable(['google_access_role' => 'reader'] + $cal));
    check('google writable: disconnected account is not', !GoogleWriter::writable(['google_account_id' => null] + $cal));
    check('google writable: an ICS feed is not', !GoogleWriter::writable(['provider' => 'ics', 'source_url' => 'https://x/y.ics']));
    checkEq('google tombstone shape', ['cancelled' => true, 'uid' => 'u', 'recurrence_instance_utc' => null], GoogleWriter::tombstone('u', null));
}

// --- Information icons are host icons a plugin may put on an event ---
{
    foreach (['sunset', 'air', 'tideLow', 'rain', 'flag'] as $n) {
        checkEq('plugin icon: ' . $n . ' is a host icon', null, BetterCal\Domain\Plugins::iconError($n));
    }
    check('plugin icon: a guess is still refused', BetterCal\Domain\Plugins::iconError('weather-sunny') !== null);
}

// --- The version comes from VERSION and is a plain semver ---
{
    $fileV = trim((string) file_get_contents(dirname(__DIR__, 2) . '/VERSION'));
    check('version: VERSION is semver', preg_match('/^\d+\.\d+\.\d+$/', $fileV) === 1);
    checkEq('version: config reads VERSION', $fileV, config()['version']);
    check('version: CHANGELOG has an entry for it', str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md'), '## ' . $fileV . ' '));
}

// --- F10/F11: an unknown email costs what a known one does ---
{
    $bdb = new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]);
    $bdb->run('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT)');
    $real = password_hash('correct horse', PASSWORD_BCRYPT, ['cost' => 11]);
    $bdb->run("INSERT INTO users (id, email, password_hash) VALUES (1, 'owner@example.com', ?)", [$real]);
    $time = static function (callable $f): float { $t = hrtime(true); $f(); return (hrtime(true) - $t) / 1e6; };
    $known = $time(static fn() => password_verify('wrong', $real));
    $unknown = $time(static fn() => BetterCal\Domain\Auth::burnTime($bdb, 'wrong'));
    check('burnTime: an unknown email takes as long as a real check (within 40%)', abs($unknown - $known) / max($known, 0.001) < 0.4, sprintf('known %.1fms, unknown %.1fms', $known, $unknown));
}

// --- F25: the push Topic is an opaque keyed hash ---
{
    $topic = BetterCal\Infra\PushSender::topicFor('4410:20260923T190000Z', 'k1');
    check('push topic: 32 url-safe characters', preg_match('/^[A-Za-z0-9_-]{32}$/', $topic) === 1);
    check('push topic: reveals neither the event id nor the time', !str_contains(base64_decode(strtr($topic, '-_', '+/')) ?: '', '4410') && !str_contains($topic, base64_encode('4410')));
    checkEq('push topic: stable per occurrence, so repeats still collapse', $topic, BetterCal\Infra\PushSender::topicFor('4410:20260923T190000Z', 'k1'));
    check('push topic: another occurrence, another topic', $topic !== BetterCal\Infra\PushSender::topicFor('4410:20260930T190000Z', 'k1'));
}
// --- F22: the server's HTML check is linear too ---
{
    $t0 = microtime(true);
    BetterCal\Domain\Sanitize::isHtml(str_repeat('<a', 30000));
    check('isHtml: 30,000 "<a" without ">" in well under a second', microtime(true) - $t0 < 0.5, sprintf('%.3fs', microtime(true) - $t0));
    $t0 = microtime(true);
    BetterCal\Domain\Sanitize::toText('<b>x</b>' . str_repeat('<script x>', 40000));
    check('toText: 40,000 unclosed "<script" in well under a second', microtime(true) - $t0 < 0.5, sprintf('%.3fs', microtime(true) - $t0));
    check('toText: a stray unclosed tag in prose keeps the words after it (export)', str_contains(BetterCal\Domain\Sanitize::toText('<p>Learn the <style> element today</p>'), 'element today'));
    checkEq('toText: script and style still dropped', 'a b', trim(preg_replace('/\s+/', ' ', BetterCal\Domain\Sanitize::toText('<p>a</p><script>evil()</script><style>x{}</style><p>b</p>'))));
    check('isHtml: still finds real markup', BetterCal\Domain\Sanitize::isHtml('see <b>this</b>') && !BetterCal\Domain\Sanitize::isHtml('a < b and <3'));
}

// --- 0.2.1: channels a token creates belong to the token ---
{
    $cdb = new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]);
    $cdb->run('PRAGMA foreign_keys = ON');
    $cdb->run('CREATE TABLE api_tokens (id INTEGER PRIMARY KEY, user_id INTEGER, expires_at TEXT NULL)');
    $cdb->run('CREATE TABLE out_feeds (id INTEGER PRIMARY KEY, user_id INTEGER, token TEXT, created_by_token_id INTEGER NULL REFERENCES api_tokens(id) ON DELETE CASCADE)');
    $cdb->run("INSERT INTO api_tokens (id, user_id, expires_at) VALUES (1, 1, NULL), (2, 1, '2000-01-01 00:00:00')");
    $cdb->run("INSERT INTO out_feeds (user_id, token, created_by_token_id) VALUES (1, 'owner', NULL), (1, 'agent', 1)");
    check('token-bound: a live token is valid, an expired one is not', BetterCal\Domain\ApiTokens::stillValid($cdb, 1) && !BetterCal\Domain\ApiTokens::stillValid($cdb, 2));
    $cdb->run('DELETE FROM api_tokens WHERE id = 1');
    checkEq('token-bound: revoking a token removes the feed it created, not the owner\'s', ['owner'], array_column($cdb->all('SELECT token FROM out_feeds ORDER BY id'), 'token'));
    $s = BetterCal\Domain\Settings::withDefaults(['notifyEmail' => 'agent@example.com', 'notifyEmailToken' => 99]);
    checkEq('token-bound: an address set by a revoked token falls back to the account', 'owner@example.com', BetterCal\Domain\Settings::notifyDestination($s, 'owner@example.com', $cdb));
    checkEq('token-bound: with no database to ask, the account address is used', 'owner@example.com', BetterCal\Domain\Settings::notifyDestination($s, 'owner@example.com'));
    $cdb->run("INSERT INTO api_tokens (id, user_id, expires_at) VALUES (3, 1, NULL)");
    $s2 = BetterCal\Domain\Settings::withDefaults(['notifyEmail' => 'agent@example.com', 'notifyEmailToken' => 3]);
    checkEq('token-bound: while the token is valid its address is used', 'agent@example.com', BetterCal\Domain\Settings::notifyDestination($s2, 'owner@example.com', $cdb));
    checkEq('token-bound: an address the owner set is used as is', 'me@example.com', BetterCal\Domain\Settings::notifyDestination(BetterCal\Domain\Settings::withDefaults(['notifyEmail' => 'me@example.com']), 'owner@example.com'));
    try {
        BetterCal\Domain\Settings::validate(['notifyEmailToken' => 5]);
        check('token-bound: notifyEmailToken cannot be set by a client', false);
    } catch (BetterCal\Http\HttpError $e) {
        check('token-bound: notifyEmailToken cannot be set by a client', true);
    }
}

// --- Bundled plugins: icons, AQI, sun ---
{
    $weather = require dirname(__DIR__) . '/plugins/weather/Plugin.php';
    checkEq('weather: icons are host icons', ['sun', 'cloud', 'rain', 'snow', 'storm'], [$weather::describe(0)[0], $weather::describe(3)[0], $weather::describe(61)[0], $weather::describe(71)[0], $weather::describe(95)[0]]);
    foreach ([0, 3, 45, 61, 71, 95] as $code) {
        checkEq('weather: icon for code ' . $code . ' passes the host check', null, BetterCal\Domain\Plugins::iconError($weather::describe($code)[0]));
    }
    checkEq('weather: daily max AQI per local date', ['2026-09-24' => 61, '2026-09-25' => 40], $weather::dailyMaxAqi(['2026-09-24T01:00', '2026-09-24T15:00', '2026-09-25T09:00', '2026-09-25T10:00'], [30, 60.6, null, 40]));
    checkEq('weather: AQI categories', ['Good', 'Moderate', 'Unhealthy for sensitive groups', 'Hazardous'], [$weather::aqiCategory(50), $weather::aqiCategory(51), $weather::aqiCategory(120), $weather::aqiCategory(400)]);
    $sun = require dirname(__DIR__) . '/plugins/sun/Plugin.php';
    $oak = $sun::sunTimes(37.8044, -122.2712, '2026-09-24', 1);
    checkEq('sun: one sunrise and one sunset for Oakland on 2026-09-24', ['sunrise', 'sunset'], array_column($oak, 'kind'));
    $local = array_map(static fn(array $x): string => (new DateTimeImmutable('@' . $x['at']))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('H:i'), $oak);
    check('sun: Oakland sunrise near 7:00 and sunset near 19:00 local', $local[0] >= '06:45' && $local[0] <= '07:15' && $local[1] >= '18:50' && $local[1] <= '19:20', implode(' / ', $local));
    checkEq('sun: dated by the local day', '2026-09-24', $oak[1]['date']);
    checkEq('sun: polar night yields no events that day', [], $sun::sunTimes(78.2, 15.6, '2026-12-21', 1));
    $syd = $sun::sunTimes(-33.87, 151.21, '2026-09-24', 1);
    checkEq('sun: works east of Greenwich too (Sydney)', ['sunrise', 'sunset'], array_column($syd, 'kind'));
    checkEq('sun: the manifest is valid', [], BetterCal\Domain\Plugins::manifestErrors(json_decode((string) file_get_contents(dirname(__DIR__) . '/plugins/sun/plugin.json'), true)));
    checkEq('weather: the manifest is still valid', [], BetterCal\Domain\Plugins::manifestErrors(json_decode((string) file_get_contents(dirname(__DIR__) . '/plugins/weather/plugin.json'), true)));
}

// --- Standing channels out of the account are for a person, not a token ---
{
    $tokenReq = new BetterCal\Http\Request('POST', '/api/v1/outfeeds');
    $tokenReq->authMethod = 'token';
    try {
        $tokenReq->requireSession('Creating an outbound feed');
        check('session only: a token is refused', false);
    } catch (BetterCal\Http\HttpError $e) {
        checkEq('session only: a token is refused with 403 session_required', [403, 'session_required'], [$e->status, $e->errorCode]);
    }
    $sessReq = new BetterCal\Http\Request('POST', '/api/v1/outfeeds');
    $sessReq->authMethod = 'session';
    $sessReq->requireSession('Creating an outbound feed');
    check('session only: a session passes', true);
    checkEq('push service: FCM', 'Chrome, Edge or Android', BetterCal\Domain\PushSubscriptions::service('https://fcm.googleapis.com/fcm/send/x'));
    checkEq('push service: Apple', 'Safari or iPhone', BetterCal\Domain\PushSubscriptions::service('https://web.push.apple.com/abc'));
    checkEq('push service: anything else names its host', 'push.example.net', BetterCal\Domain\PushSubscriptions::service('https://push.example.net/x'));
}

// --- A patch that names the event's own calendar is not a move ---
{
    $ev = ['calendar_id' => 41];
    check('calendar change: same id is not a move', !BetterCal\Domain\Events::isCalendarChange($ev, ['calendarId' => '41', 'start' => '2026-09-22T10:00:00+01:00']));
    check('calendar change: no id is not a move', !BetterCal\Domain\Events::isCalendarChange($ev, ['start' => '2026-09-22T10:00:00+01:00']));
    check('calendar change: another id is', BetterCal\Domain\Events::isCalendarChange($ev, ['calendarId' => 24]));
}

// --- Relationship: the calendar's role sets the default, the row's marks override ---
{
    $rel = [BetterCal\Domain\Events::class, 'relationship'];
    checkEq('rel: my confirmed event is planned', 'planned', $rel('none', 'confirmed', 'mine'));
    checkEq('rel: my tentative event is maybe', 'maybe', $rel('none', 'tentative', 'mine'));
    checkEq('rel: an opportunity untouched is available', 'available', $rel('none', 'confirmed', 'opportunities'));
    checkEq('rel: an opportunity marked interested is maybe', 'maybe', $rel('interested', 'confirmed', 'opportunities'));
    checkEq('rel: an opportunity marked going is planned', 'planned', $rel('going', 'confirmed', 'opportunities'));
    checkEq('rel: hidden wins over tentative', 'hidden', $rel('hidden', 'tentative', 'mine'));
    checkEq('rel: context is context whatever the marks', 'context', $rel('going', 'tentative', 'context'));
    checkEq('rel: a going mark on my calendar is planned', 'planned', $rel('going', 'tentative', 'mine'));
}

// --- /events refuses a window longer than the cap instead of cutting it short ---
{
    // The size check comes before any lookup, so stand-ins without a
    // database are enough to reach it.
    $evStub = (new ReflectionClass(BetterCal\Domain\Events::class))->newInstanceWithoutConstructor();
    $trStub = (new ReflectionClass(BetterCal\Domain\Trips::class))->newInstanceWithoutConstructor();
    $ectl = new BetterCal\Http\Controllers\EventsController($evStub, $trStub);
    $longReq = new BetterCal\Http\Request('GET', '/api/v1/events', ['start' => '2016-08-20T00:00:00Z', 'end' => '2036-10-25T00:00:00Z']);
    $longReq->user = ['id' => 1];
    $status = null;
    $message = '';
    try {
        $ectl->window($longReq);
    } catch (BetterCal\Http\HttpError $e) {
        $status = $e->status;
        $message = $e->getMessage();
    }
    checkEq('events window: twenty years is refused, not quietly cut to two', 400, $status);
    check('events window: the refusal says to ask in pieces', str_contains($message, 'in pieces'));
}

$pass = $GLOBALS['__pass'];
$fail = $GLOBALS['__fail'];
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
