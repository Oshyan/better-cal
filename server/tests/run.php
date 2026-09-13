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
    . "ATTENDEE;CN=Oshyan;PARTSTAT=NEEDS-ACTION:mailto:oshyan@gmail.com\r\n"
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
foreach (['127.0.0.1', '10.1.2.3', '172.16.0.9', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fe80::1', 'fd00::1', '::ffff:127.0.0.1', 'not-an-ip'] as $bad) {
    check('http policy refuses ' . $bad, HttpClient::isForbiddenIp($bad));
}
foreach (['8.8.8.8', '140.82.112.3', '2606:4700:4700::1111', '100.128.0.1'] as $ok) {
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

$reply = MailIngest::buildReplyIcs('abc-123@example.com', 'alice@example.com', 'oshyan@gmail.com', 'ACCEPTED', 2, 'Team sync', new DateTimeImmutable('2026-08-03T12:00:00Z'));
check('rsvp reply has METHOD', str_contains($reply, 'METHOD:REPLY'));
check('rsvp reply has partstat attendee', str_contains($reply, 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:oshyan@gmail.com'));
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
    ['endpoint' => 'https://ok.example/x'],
    ['endpoint' => 'https://ok.example/x', 'keys' => ['p256dh' => 'bad key!', 'auth' => 'b']],
] as $bad) {
    try {
        PushSubscriptions::validate($bad);
        check('push validate rejects bad subscription', false);
    } catch (HttpError $e) {
        checkEq('push validate bad subscription code', 'invalid_subscription', $e->errorCode);
    }
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
// Geocode sweep: the pure parts. Grouping key and bias precedence.
{
    checkEq('sweep key: whitespace collapsed and case folded', 'pillar point harbor, half moon bay', GeocodeSweep::normalizeLocation("  Pillar   Point\tHarbor, Half Moon Bay \n"));
    checkEq('sweep key: same address, different spacing, one group', GeocodeSweep::normalizeLocation('15 Calton Hill, Edinburgh'), GeocodeSweep::normalizeLocation('15  Calton Hill,  EDINBURGH'));
    checkEq('sweep bias: home location wins', [37.8, -122.27], GeocodeSweep::biasFor(['homeLat' => 37.8, 'homeLng' => -122.27, 'tz' => 'Europe/London'], 'Asia/Tokyo'));
    $tzBias = GeocodeSweep::biasFor(['homeLat' => null, 'homeLng' => null], 'America/Los_Angeles');
    check('sweep bias: falls back to the event zone centroid', $tzBias[0] !== null && $tzBias[1] !== null && $tzBias[1] < -100, json_encode($tzBias));
    checkEq('sweep bias: nothing known means no bias', [null, null], GeocodeSweep::biasFor([], null));
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

$pass = $GLOBALS['__pass'];
$fail = $GLOBALS['__fail'];
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
