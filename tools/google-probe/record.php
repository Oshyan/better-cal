<?php
// Records the trace set for replay.php from the edit harness in its Google
// mode and from record-app.php (single events, a whole series deleted): one
// process per scenario (a fresh database and a fresh stand-in each, so a
// trace holds that scenario alone), GOOGLE_TRACE naming its file. See
// README.md, "Replaying the App's Requests".
//
//   BC_TEST_DB=127.0.0.1:33308 php tools/google-probe/record.php --out <dir>
//       [--fixed google|all|none] [--seed 7] [--generated 15] [--max-requests 1500]
//
// --fixed google (the default) takes the harness's fixed cases whose names
// start with google- and every sequence; all takes every fixed case; none
// takes none. --generated N adds drawn cases 1 to N of --seed, each in its
// own process. Scenarios go in order until --max-requests would be passed.
// A trace whose stand-in was changed outside a request in a way no request
// repeats (lib/Trace.php, "setup") goes to <dir>/unreplayable/, one with
// no request is dropped; both are listed. The four record-app.php
// scenarios come first.
// Prints what the set covers and which of the write kinds it should
// cover it lacks.
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

ini_set('memory_limit', '1024M');
$root = dirname(__DIR__, 2);
require_once __DIR__ . '/lib/Api.php';
require_once __DIR__ . '/lib/FakeGoogle.php';
require_once __DIR__ . '/lib/Trace.php';

use BetterCal\GoogleProbe\TraceRecorder;

/** The write kinds the set is meant to cover (TraceRecorder::kinds). */
const WANTED = [
    'all events: field change', 'all events: time change', 'all events: repeat-rule change', 'all events: zone change',
    'all events: timed to all-day', 'all events: all-day to timed', 'all events: timed to all-day, with an edited day', 'all events: timed to all-day, with a deleted day',
    'all events: all-day to timed, with an edited day', 'all events: all-day to timed, with a deleted day',
    'this and following (bound, then a new series)', 'edit one day', 'delete one day', 'delete a whole repeating event',
    'insert a repeating event', 'insert a single event', 'change a single event', 'delete a single event', 'poll with a sync token',
];

$opts = getopt('', ['out:', 'fixed:', 'seed:', 'generated:', 'max-requests:', 'zone:', 'help']);
if (isset($opts['help']) || !isset($opts['out'])) {
    fwrite(STDERR, "usage: BC_TEST_DB=host:port php tools/google-probe/record.php --out <dir> [--fixed google|all|none] [--seed 7] [--generated 15] [--max-requests 1500] [--zone UTC]\n");
    exit(isset($opts['help']) ? 0 : 2);
}
// The stand-in calendar's own zone (GoogleStand reads GOOGLE_STAND_ZONE): the zone of the calendar the set will replay on.
$zone = (string) ($opts['zone'] ?? 'UTC');
if (!in_array($zone, \DateTimeZone::listIdentifiers(), true) && $zone !== 'UTC') {
    fwrite(STDERR, "--zone takes a zone name such as America/Los_Angeles, not '$zone'.\n");
    exit(2);
}
$out = rtrim((string) $opts['out'], '/');
$fixedKind = (string) ($opts['fixed'] ?? 'google');
$seed = (int) ($opts['seed'] ?? 7);
$generated = (int) ($opts['generated'] ?? 15);
$budget = (int) ($opts['max-requests'] ?? 1500);
if (!in_array($fixedKind, ['google', 'all', 'none'], true)) {
    fwrite(STDERR, "--fixed is google, all or none.\n");
    exit(2);
}
[$host, $port] = array_pad(explode(':', getenv('BC_TEST_DB') ?: '', 2), 2, '3306');
if ($host === '' || @fsockopen($host, (int) $port, $errno, $errstr, 3) === false) {
    fwrite(STDERR, "The test database is not reachable (BC_TEST_DB=" . (getenv('BC_TEST_DB') ?: 'unset') . "); the harness needs it.\n");
    exit(1);
}
foreach (["$out/unreplayable", "$out/.harness"] as $d) {
    if (!is_dir($d) && !mkdir($d, 0700, true) && !is_dir($d)) {
        fwrite(STDERR, "cannot make $d\n");
        exit(1);
    }
}

// ---- the scenarios, in the order they are taken: each a command run in its own process --------
$harness = static fn(string $name, array $args): array => array_merge([PHP_BINARY, "$root/tools/edit-harness/harness.php", '--google', '--no-shrink', '--quiet', '--findings', "$out/.harness/$name.json"], $args);
$scenarios = [];
// What the harness doesn't make (single events, a whole series deleted): record-app.php.
foreach (['single-timed', 'single-all-day', 'series-deleted-plain', 'series-deleted-whole'] as $s) {
    $scenarios["app-$s"] = [PHP_BINARY, __DIR__ . '/record-app.php', '--scenario', $s];
}
if ($fixedKind !== 'none') {
    $cases = glob("$root/tools/edit-harness/cases/*.json") ?: [];
    sort($cases);
    foreach ($cases as $f) {
        if ($fixedKind === 'all' || str_starts_with(basename($f), 'google-')) {
            $scenarios[basename($f, '.json')] = $harness(basename($f, '.json'), ['--fixed=' . $f, '--cases', '0']);
        }
    }
    $sequences = glob("$root/tools/edit-harness/sequences/*.json") ?: [];
    sort($sequences);
    foreach ($sequences as $f) {
        $scenarios['sequence-' . basename($f, '.json')] = $harness('sequence-' . basename($f, '.json'), ['--fixed=' . $f, '--cases', '0']);
    }
}
for ($i = 1; $i <= $generated; $i++) {
    $scenarios["seed$seed-case$i"] = $harness("seed$seed-case$i", ['--seed', (string) $seed, '--cases', (string) $i, '--case', (string) $i]);
}

$total = 0;
$kept = [];
$left = [];
$coverage = [];
$writes = 0;
$mailing = [];
foreach ($scenarios as $name => $cmd) {
    $file = "$out/$name.json";
    @unlink($file);
    $env = ['GOOGLE_TRACE' => $file, 'GOOGLE_STAND_ZONE' => $zone] + getenv();
    $started = microtime(true);
    $proc = proc_open($cmd, [1 => ['file', "$out/.harness/$name.log", 'w'], 2 => ['file', "$out/.harness/$name.log", 'a']], $pipes, $root, $env);
    $code = is_resource($proc) ? proc_close($proc) : -1;
    $seconds = round(microtime(true) - $started, 1);
    $t = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $requests = is_array($t) ? count(array_filter($t['steps'], static fn(array $s): bool => $s['kind'] === 'request')) : 0;
    if ($requests === 0) {
        @unlink($file);
        $left[] = "$name: no request reached the stand-in (harness exit $code; a case not applicable in the Google mode, or the harness failed: $out/.harness/$name.log)";
        fwrite(STDERR, "$name: no requests ({$seconds}s)\n");
        continue;
    }
    // Every write the app sent to an event keeps quiet (sendUpdates=none), whether or not the trace is kept for replay.
    $writes += count(array_filter($t['steps'], static fn(array $s): bool => $s['kind'] === 'request' && $s['method'] !== 'GET'));
    foreach (TraceRecorder::mailing($t) as $m) {
        $mailing[] = "$name, $m";
    }
    foreach (glob("$out/$name-[0-9]*.json") ?: [] as $extra) {
        // A second stand-in in one process (another account): set aside, as the set is one stand-in per scenario.
        rename($extra, "$out/unreplayable/" . basename($extra));
        $left[] = "$name: the harness made another stand-in in the same process; its trace moved to unreplayable/" . basename($extra);
    }
    // An outside change with a setup (the harness's adopt of a series it just made) replays; any other can't.
    $outside = array_filter($t['steps'], static fn(array $s): bool => $s['kind'] === 'outside' && !empty($s['changed']) && !is_array($s['setup'] ?? null));
    if ($outside !== []) {
        rename($file, "$out/unreplayable/$name.json");
        $left[] = "$name: the harness changed the stand-in outside a request before request " . reset($outside)['before'] . ' in a way no request repeats (' . implode(', ', array_slice(reset($outside)['changed'], 0, 3)) . '); moved to unreplayable/';
        fwrite(STDERR, "$name: unreplayable ({$seconds}s)\n");
        continue;
    }
    if ($total + $requests > $budget) {
        @unlink($file);
        $left[] = "$name: left out, its $requests requests would pass --max-requests $budget";
        fwrite(STDERR, "$name: over the budget, left out\n");
        continue;
    }
    $total += $requests;
    $kept[$name] = $requests;
    foreach (TraceRecorder::kinds($t) as $k => $n) {
        $coverage[$k] ??= [];
        if ($n > 0) {
            $coverage[$k][] = $name;
        }
    }
    fwrite(STDERR, "$name: $requests requests, harness exit $code ({$seconds}s)\n");
}

ksort($coverage);
echo count($kept) . " scenarios, $total requests, in $out\n\nCoverage (kind: scenarios):\n";
foreach ($coverage as $k => $names) {
    echo "  $k: " . count($names) . "\n";
}
$missing = array_values(array_filter(WANTED, static fn(string $k): bool => empty($coverage[$k])));
echo "\nWanted and missing: " . ($missing === [] ? 'none' : implode('; ', $missing)) . "\n";
if ($left !== []) {
    echo "\nLeft out or noted:\n";
    foreach ($left as $l) {
        echo "  $l\n";
    }
}
echo "\nWrites the app sent: $writes, in every scenario recorded (kept or not); without sendUpdates=none: " . count($mailing) . "\n";
foreach ($mailing as $m) {
    echo "  $m\n";
}
file_put_contents("$out/.harness/summary.json", json_encode(['zone' => $zone, 'scenarios' => $kept, 'requests' => $total, 'coverage' => $coverage, 'missing' => $missing, 'left' => $left,
    'writes' => $writes, 'mailing' => $mailing], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
// A write that could mail guests fails the recording: the app promises never to (sendUpdates=none on every write).
exit($mailing === [] ? 0 : 1);
