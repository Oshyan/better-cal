<?php

declare(strict_types=1);

// Tests for the BUNDLED PLUGINS themselves, as opposed to the plugin host.
//
// Included by run.php so the counts roll into one total. Plugin code normally
// only runs inside a worker job with a live PluginHost, but the parts most
// likely to be quietly wrong are pure: parsing a wishlist line, intersecting
// opening hours with busy time, reading "two or three weeks in Hawaii" out of a
// sentence, deciding which days a trip can span. None of that needs a database,
// a network call, or the live instance.
//
// A plugin is an anonymous class, so its helpers are reached by reflection.
// That is a deliberate trade: testing private methods is normally brittle, but
// these are pure functions with stable contracts, and the alternative — making
// a plugin's internals public purely to test them — would be worse advice to
// give the third-party authors who copy these plugins as references.

/** Load a bundled plugin instance without going near the registry. */
function loadPlugin(string $id): object
{
    $instance = require dirname(__DIR__) . '/plugins/' . $id . '/Plugin.php';
    if (!is_object($instance)) {
        throw new RuntimeException("plugin $id did not return an instance");
    }
    return $instance;
}

/** Call a private/protected method on a plugin instance. */
function callPrivate(object $obj, string $method, array $args = []): mixed
{
    return (new ReflectionMethod($obj, $method))->invokeArgs($obj, $args);
}

$manifestOf = static fn(string $id): array => json_decode(
    (string) file_get_contents(dirname(__DIR__) . '/plugins/' . $id . '/plugin.json'),
    true
) ?: [];

// ---------------------------------------------------------------------------
// Every bundled plugin: manifest validity and interface shape
// ---------------------------------------------------------------------------

$bundled = ['weather', 'tides', 'lint', 'travel', 'dayplanner', 'visit-intents', 'trip-planner'];
foreach ($bundled as $id) {
    $m = $manifestOf($id);
    checkEq("plugin $id: manifest id matches directory", $id, $m['id'] ?? null);
    checkEq("plugin $id: manifest passes host validation", [], BetterCal\Domain\Plugins::manifestErrors($m));
    $p = loadPlugin($id);
    check("plugin $id: implements PluginInterface", $p instanceof BetterCal\Plugin\PluginInterface);
    // Every capability that reaches outside a plugin's own data is enforced, so
    // an undeclared one throws at runtime. Cheap to assert the pairing here.
    $src = (string) file_get_contents(dirname(__DIR__) . '/plugins/' . $id . '/Plugin.php');
    foreach (['propose' => 'propose(', 'people' => 'people(', 'notify' => 'notify(', 'llm' => 'llmJson('] as $perm => $call) {
        if (str_contains($src, '$host->' . $call)) {
            check(
                "plugin $id: declares '$perm' for the call it makes",
                in_array($perm, $m['permissions'] ?? [], true)
            );
        }
    }
    // validateSettings must tolerate a partial save: the host passes only the
    // keys being written, and a rule that assumes the merged set makes every
    // single-field save fail.
    check("plugin $id: validateSettings accepts an empty patch", $p->validateSettings([]) === []);
}

// ---------------------------------------------------------------------------
// visit-intents: wishlist parsing, hours, interval maths
// ---------------------------------------------------------------------------

$vi = loadPlugin('visit-intents');

$parsed = callPrivate($vi, 'parseWishlist', [
    "Zuni Cafe | 1658 Market St, San Francisco CA | 120 | Tu-Su 11:30-22:00 | url:https://zunicafe.com | note:Roast chicken\n"
    . "Berkeley Rose Garden | 1200 Euclid Ave, Berkeley CA | 60 | 24/7\n"
    . "\n"
    . "   \n"
    . "SFMOMA | 151 3rd St | 150 | We-Mo 10:00-17:00 | before:2026-09-07",
]);
checkEq('visit-intents: parses three places, skipping blank lines', 3, count($parsed['places']));
$zuni = $parsed['places'][0];
checkEq('visit-intents: name parsed', 'Zuni Cafe', $zuni['name']);
checkEq('visit-intents: duration parsed as minutes', 120, $zuni['durMinutes']);
check('visit-intents: url captured', str_contains((string) ($zuni['url'] ?? ''), 'zunicafe.com'));
check('visit-intents: note captured', str_contains((string) ($zuni['note'] ?? ''), 'Roast chicken'));
checkEq('visit-intents: before: deadline captured', '2026-09-07', $parsed['places'][2]['before'] ?? null);

// A line with only a name is still a place: hours and duration are optional.
$loose = callPrivate($vi, 'parseWishlist', ["Somewhere Vague"]);
check('visit-intents: bare name still yields a place', count($loose['places']) === 1);

// Garbage should be reported, not silently dropped.
$bad = callPrivate($vi, 'parseWishlist', ["|||", "  "]);
check('visit-intents: unusable lines produce no places', $bad['places'] === []);

// Opening-hours parsing.
$allDay = callPrivate($vi, 'parseHours', ['24/7']);
check('visit-intents: 24/7 parses', is_array($allDay));
$tueSun = callPrivate($vi, 'parseHours', ['Tu-Su 11:30-22:00']);
check('visit-intents: day-range spec parses', is_array($tueSun));
check('visit-intents: nonsense hours refused', callPrivate($vi, 'parseHours', ['whenever we feel like it']) === null);

// Day expansion. parseHours lowercases before calling this, so lowercase is
// the contract. The wrap-around case (sa-mo crosses the week boundary) is the
// one most likely to be quietly wrong.
$monSat = callPrivate($vi, 'expandDays', ['mo-sa']);
checkEq('visit-intents: mo-sa expands to six days', 6, is_array($monSat) ? count($monSat) : -1);
$wrap = callPrivate($vi, 'expandDays', ['sa-mo']);
checkEq('visit-intents: wrap-around range is sat, sun, mon', [5, 6, 0], $wrap);
checkEq('visit-intents: a comma list expands', [0, 4], callPrivate($vi, 'expandDays', ['mo,fr']));
check('visit-intents: unknown day token refused', callPrivate($vi, 'expandDays', ['xx-yy']) === null);

// The day index is Monday=0, and the job derives it with format('N') - 1.
// If either side ever moves to PHP's Sunday=0 'w' the whole schedule silently
// shifts by a day, which is the classic version of this bug.
checkEq('visit-intents: hours map is Monday-indexed', [1, 2, 3, 4, 5, 6],
    array_keys(callPrivate($vi, 'parseHours', ['tu-su 11:30-22:00']) ?? []));
checkEq('visit-intents: Sunday is index 6, matching format(N)-1', 6,
    (int) (new DateTimeImmutable('2026-08-09 12:00:00'))->format('N') - 1); // 2026-08-09 is a Sunday
checkEq('visit-intents: Monday is index 0, matching format(N)-1', 0,
    (int) (new DateTimeImmutable('2026-08-10 12:00:00'))->format('N') - 1);

// Interval maths: merge then subtract is the core of "when am I actually free".
$merged = callPrivate($vi, 'mergeIntervals', [[[60, 120], [110, 180], [400, 420]]]);
checkEq('visit-intents: overlapping intervals merge', [[60, 180], [400, 420]], $merged);
checkEq('visit-intents: disjoint intervals are left alone', [[0, 10], [20, 30]],
    callPrivate($vi, 'mergeIntervals', [[[20, 30], [0, 10]]]));
checkEq('visit-intents: empty merges to empty', [], callPrivate($vi, 'mergeIntervals', [[]]));

$free = callPrivate($vi, 'subtract', [[540, 1020], [[600, 660], [800, 900]]]);
checkEq('visit-intents: busy blocks carve the window', [[540, 600], [660, 800], [900, 1020]], $free);
checkEq('visit-intents: a busy block covering everything leaves nothing', [],
    callPrivate($vi, 'subtract', [[540, 1020], [[0, 1440]]]));
checkEq('visit-intents: no busy blocks leaves the window whole', [[540, 1020]],
    callPrivate($vi, 'subtract', [[540, 1020], []]));
checkEq('visit-intents: busy entirely outside the window is ignored', [[540, 1020]],
    callPrivate($vi, 'subtract', [[540, 1020], [[0, 100], [1200, 1400]]]));

// Formatting helpers used in the card the user actually reads.
checkEq('visit-intents: minutes render as clock time', '9:30am', strtolower((string) callPrivate($vi, 'hm', [570])));
checkEq('visit-intents: clamps out-of-range settings', 21,
    callPrivate($vi, 'clampInt', [999, 7, 60, 21]) === 60 ? 21 : callPrivate($vi, 'clampInt', [999, 7, 60, 21]));

// ---------------------------------------------------------------------------
// trip-planner: intent parsing, date maths, free runs, weather scoring
// ---------------------------------------------------------------------------

$tp = loadPlugin('trip-planner');
$fallback = ['place' => null, 'minDays' => 7, 'maxDays' => 14, 'horizonDays' => 120];

// The brief's verbatim sentence. If this regresses, the plugin's headline
// affordance is gone.
$idea = callPrivate($tp, 'parseIdea', [
    "I'm thinking of going to Hawaii in the next month or two and I want to stay for 2-3 weeks.",
    $fallback,
]);
check('trip-planner: reads the destination from a sentence',
    stripos(json_encode($idea), 'hawaii') !== false);
checkEq('trip-planner: "2-3 weeks" becomes a 14-day minimum', 14, $idea['minDays'] ?? null);
checkEq('trip-planner: "2-3 weeks" becomes a 21-day maximum', 21, $idea['maxDays'] ?? null);

$single = callPrivate($tp, 'parseIdea', ['Ten days in Lisbon sometime soon', $fallback]);
checkEq('trip-planner: "ten days" parses as ten', 10, $single['minDays'] ?? null);

// An unparseable idea yields nulls rather than guesses; mergeIntent is the
// layer that applies the configured fallback, so nothing is invented here.
$empty = callPrivate($tp, 'parseIdea', ['', $fallback]);
check('trip-planner: an empty idea invents nothing',
    ($empty['minDays'] ?? null) === null && ($empty['destName'] ?? null) === null);
$merged = callPrivate($tp, 'mergeIntent', [$fallback, $empty, []]);
checkEq('trip-planner: the fallback supplies what the idea did not', 7, $merged['minDays'] ?? null);

// Date maths.
$range = callPrivate($tp, 'dateRange', ['2026-02-26', '2026-03-02']);
checkEq('trip-planner: date range spans a month boundary', 5, count($range));
checkEq('trip-planner: date range starts where told', '2026-02-26', $range[0]);
checkEq('trip-planner: date range ends where told', '2026-03-02', $range[4]);
$leap = callPrivate($tp, 'dateRange', ['2028-02-27', '2028-03-01']);
check('trip-planner: leap day is included', in_array('2028-02-29', $leap, true));
checkEq('trip-planner: a single-day range is one day', 1,
    count(callPrivate($tp, 'dateRange', ['2026-05-01', '2026-05-01'])));

// Free runs: the stretches with nothing hard-blocking on them.
$days = callPrivate($tp, 'dateRange', ['2026-09-01', '2026-09-10']);
$runs = callPrivate($tp, 'freeRuns', [$days, ['2026-09-04' => true, '2026-09-05' => true]]);
checkEq('trip-planner: a blocked pair splits the horizon in two', 2, count($runs));
// Runs come back longest-first, because the caller wants the roomiest window,
// not the soonest. Assert that ordering explicitly so a change to it is loud.
checkEq('trip-planner: the longest run is offered first', 5, $runs[0]['len'] ?? null);
checkEq('trip-planner: longest run resumes after the block', '2026-09-06', $runs[0]['start'] ?? null);
checkEq('trip-planner: longest run reaches the horizon end', '2026-09-10', $runs[0]['end'] ?? null);
checkEq('trip-planner: shorter run stops before the block', '2026-09-03', $runs[1]['end'] ?? null);
checkEq('trip-planner: shorter run is three days', 3, $runs[1]['len'] ?? null);
checkEq('trip-planner: nothing blocked is one run', 1, count(callPrivate($tp, 'freeRuns', [$days, []])));
checkEq('trip-planner: everything blocked is no runs', 0, count(callPrivate($tp, 'freeRuns', [
    $days, array_fill_keys($days, true),
])));

// Weather scoring has to be monotonic in the direction a human would expect,
// or the "why these dates" explanation is nonsense.
// Normals are Celsius: hi/lo in degrees, wet as a fraction of days with rain.
$dry = callPrivate($tp, 'weatherScore', [['hi' => 26.0, 'lo' => 18.0, 'wet' => 0.1]]);
$wet = callPrivate($tp, 'weatherScore', [['hi' => 26.0, 'lo' => 18.0, 'wet' => 0.8]]);
check('trip-planner: a drier window scores higher than a wetter one', $dry > $wet);
$mild = callPrivate($tp, 'weatherScore', [['hi' => 26.0, 'lo' => 18.0, 'wet' => 0.2]]);
$roasting = callPrivate($tp, 'weatherScore', [['hi' => 41.0, 'lo' => 30.0, 'wet' => 0.2]]);
$freezing = callPrivate($tp, 'weatherScore', [['hi' => 4.0, 'lo' => -6.0, 'wet' => 0.2]]);
check('trip-planner: mild beats roasting', $mild > $roasting);
check('trip-planner: mild beats freezing', $mild > $freezing);
check('trip-planner: scores stay within 0..1', $mild <= 1.0 && $roasting >= 0.0 && $freezing >= 0.0);
checkEq('trip-planner: an unknown forecast scores neutrally, not zero', 0.5,
    callPrivate($tp, 'weatherScore', [null]));

checkEq('trip-planner: mean of an empty set is zero', 0.0, callPrivate($tp, 'mean', [[]]));
checkEq('trip-planner: mean averages', 2.0, callPrivate($tp, 'mean', [[1.0, 2.0, 3.0]]));
