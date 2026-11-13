<?php
// Replays recorded traces (lib/Trace.php) against a target and compares
// every answer with the stand-in's. See README.md, "Replaying the App's
// Requests".
//
//   php tools/google-probe/replay.php --trace <file or dir> --target fake [--report <dir>] [--only <text>]
//   php tools/google-probe/replay.php --trace <file or dir> --calendar <googleCalendarId> --account <id or email>
//       [--report <dir>] [--only <text>] [--read-tries N] [--allow-other-events]
//
// --target fake replays each trace on a fresh stand-in set as the one it
// was recorded on, and touches nothing outside this process. Without it
// (--target google) it WRITES to the calendar named, as run.php does: use
// the same kind of throwaway calendar. Exit 0 when every scenario answered
// as recorded, 1 when one diverged, could not be replayed or failed, 2 on
// bad options. The report goes to workfolder/google-replay/ (gitignored)
// unless --report names another folder.
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

// A trace with a long listing (an unbounded series to its 730-occurrence cap) runs to megabytes; one is read at a time.
ini_set('memory_limit', '1024M');
$root = dirname(__DIR__, 2);
require $root . '/server/src/bootstrap.php';
require_once __DIR__ . '/lib/Api.php';
require_once __DIR__ . '/lib/Report.php';
require_once __DIR__ . '/lib/FakeGoogle.php';
require_once __DIR__ . '/lib/Trace.php';
require_once __DIR__ . '/lib/Replay.php';

use BetterCal\GoogleProbe\Api;
use BetterCal\GoogleProbe\Replay;
use BetterCal\GoogleProbe\Report;
use BetterCal\GoogleProbe\ReplayTarget;
use BetterCal\GoogleProbe\TraceRecorder;

$usage = "usage: php tools/google-probe/replay.php --trace <file or dir> (--target fake | --calendar <googleCalendarId> --account <id or email>) [--report <dir>] [--only <text>] [--read-tries N] [--allow-other-events]\n";
$opts = getopt('', ['trace:', 'target:', 'calendar:', 'account:', 'report:', 'only:', 'read-tries:', 'allow-other-events', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, $usage);
    exit(0);
}
$target = (string) ($opts['target'] ?? 'google');
if (!isset($opts['trace']) || !in_array($target, ['fake', 'google'], true) || ($target === 'google' && !isset($opts['calendar'], $opts['account']))) {
    fwrite(STDERR, $usage);
    exit(2);
}

// ---- the traces, read one at a time (a trace with a long listing runs to megabytes) ------
$from = (string) $opts['trace'];
$files = is_dir($from) ? (glob(rtrim($from, '/') . '/*.json') ?: []) : [$from];
sort($files);
$only = isset($opts['only']) ? (string) $opts['only'] : null;
$traces = static function () use ($files, $only): \Generator {
    foreach ($files as $f) {
        $t = json_decode((string) @file_get_contents($f), true);
        if (!is_array($t) || !isset($t['steps'])) {
            fwrite(STDERR, "Not a trace, left out: $f\n");
            continue;
        }
        if ($only === null || str_contains((string) $t['scenario'], $only)) {
            yield $t;
        }
    }
};

$sleep = static function (float $seconds): void {
    usleep((int) round($seconds * 1_000_000));
};
$none = static function (float $seconds): void {
};
$dir = isset($opts['report']) ? (string) $opts['report'] : $root . '/workfolder/google-replay';
$scrub = new Report($dir);
$results = [];
$coverage = [];
$run = ['target' => $target, 'started' => Api::now(), 'notes' => []];

if ($target === 'fake') {
    // Each trace on a fresh stand-in set as its own was.
    $replayOne = static function (array $t) use ($root, $none): array {
        $fake = ReplayTarget::fake($t, $root);
        $api = new Api($fake->transport(), static fn(): string => 'replay-token', $fake->calendarId, $none, 0.0);
        return (new Replay($api, $none, 1, 0.0))->scenario($t);
    };
} else {
    $calendarId = trim((string) $opts['calendar']);
    if ($calendarId === '' || strtolower($calendarId) === 'primary') {
        fwrite(STDERR, "--calendar must name a throwaway calendar by its Google id, never the main one.\n");
        exit(2);
    }
    $cfg = config();
    $db = new \BetterCal\Infra\Db($cfg['db']);
    $account = (string) $opts['account'];
    $rows = ctype_digit($account)
        ? $db->all('SELECT * FROM google_accounts WHERE id = ?', [(int) $account])
        : $db->all('SELECT * FROM google_accounts WHERE email = ?', [$account]);
    if (count($rows) !== 1) {
        fwrite(STDERR, count($rows) === 0 ? "No connected Google account matches --account.\n" : "More than one connected Google account matches --account; pass its id.\n");
        exit(1);
    }
    $account = $rows[0];
    $auth = new \BetterCal\Domain\GoogleAuth($db, $cfg);
    $transport = static function (string $method, string $url, array $headers, ?array $payload): array {
        $http = new \BetterCal\Infra\HttpClient(requestBudget: 2, userAgent: \BetterCal\Infra\HttpClient::userAgentFor('google-probe'));
        return $http->json($method, $url, $payload, $headers);
    };
    $api = new Api($transport, static fn(): string => $auth->accessToken($account), $calendarId, $sleep, 0.25);
    $scrub->secret((string) $account['email'], '{account}');
    $scrub->secret($calendarId, '{calendar}');
    try {
        $zone = ReplayTarget::preflight($api, isset($opts['allow-other-events']));
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Refused: ' . $e->getMessage() . "\n");
        exit(1);
    }
    $run['calendarZone'] = $zone;
    $swept = ReplayTarget::sweep($api);
    $run['notes'][] = $swept === 0 ? 'No events an earlier replay left were found.' : "Deleted $swept events an earlier replay left.";
    $replay = new Replay($api, $sleep, max(1, (int) ($opts['read-tries'] ?? 5)), 2.0);
    $run['marker'] = Replay::MARKER_KEY . '=' . $replay->marker();
    // One calendar for every trace: a trace recorded on a stand-in in another zone is skipped, and the report says so.
    $replayOne = static function (array $t) use ($replay, $zone): array {
        if ((string) ($t['zone'] ?? '') !== $zone) {
            return ['scenario' => $t['scenario'], 'source' => $t['source'] ?? '', 'status' => 'skipped', 'reason' => "recorded on a stand-in in {$t['zone']}; the calendar is in $zone", 'steps' => 0, 'sent' => 0];
        }
        return $replay->scenario($t);
    };
}

foreach ($traces() as $t) {
    fwrite(STDERR, "{$t['scenario']} ...");
    $r = $replayOne($t);
    fwrite(STDERR, " {$r['status']}\n");
    $results[] = $r;
    foreach (TraceRecorder::kinds($t) as $k => $n) {
        $coverage[$k] ??= ['scenarios' => 0, 'times' => 0];
        $coverage[$k]['scenarios'] += $n > 0 ? 1 : 0;
        $coverage[$k]['times'] += $n;
    }
}
if ($results === []) {
    fwrite(STDERR, "No trace to replay in $from.\n");
    exit(2);
}
if ($target === 'google') {
    $left = count(array_filter(ReplayTarget::listAll($api), static fn(array $e): bool => str_starts_with((string) ($e['extendedProperties']['private'][Replay::MARKER_KEY] ?? ''), Replay::MARKER_PREFIX) && empty($e['recurringEventId'])));
    $run['markedLive'] = $left;
    $run['notes'][] = "After the run, $left events marked by a replay are still live" . ($left > 0 ? '; the next replay or probe run deletes them.' : '.');
}

$run['finished'] = Api::now();
$run['traces'] = count($results);
$run['requests'] = array_sum(array_map(static fn(array $r): int => (int) ($r['sent'] ?? 0), $results));
ksort($coverage);
$paths = ReplayTarget::write($scrub, $dir, $run, $results, $coverage);
$tally = array_count_values(array_column($results, 'status'));
ksort($tally);
fwrite(STDERR, 'Replayed ' . count($results) . ' scenarios on ' . $target . ', ' . $run['requests'] . ' requests: ' . implode(', ', array_map(static fn($k, $v) => "$v $k", array_keys($tally), $tally)) . "\n{$paths[0]}\n{$paths[1]}\n");
exit(array_diff(array_keys($tally), ['same', 'skipped']) === [] ? 0 : 1);
