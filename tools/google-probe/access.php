<?php
// The access probe, command-line entry: what Google answers the connected
// account at one stage of a shared calendar's access being lowered and taken
// away (lib/AccessProbe.php has the fixed set of requests). It WRITES to the
// calendar it is given on most stages: use a scratch calendar made in another
// Google account and shared with this one. See README.md, "The Access Probe".
//
//   php tools/google-probe/access.php --calendar <googleCalendarId> --account <google_accounts id or email>
//       --stage <label> [--report <dir>] [--allow-other-events]
//
// Loads the app's config and database as run.php does, and talks to Google
// through lib/Api.php (the token, sendUpdates=none on every write, retries).
// The report and the state file go to workfolder/google-access/ (gitignored)
// unless --report names another folder; run every stage with the same one.
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
require $root . '/server/src/bootstrap.php';
require __DIR__ . '/lib/Api.php';
require __DIR__ . '/lib/Report.php';
require __DIR__ . '/lib/Probe.php';
require __DIR__ . '/lib/AccessProbe.php';

use BetterCal\Domain\GoogleAuth;
use BetterCal\GoogleProbe\AccessProbe;
use BetterCal\GoogleProbe\Api;
use BetterCal\GoogleProbe\Report;
use BetterCal\Infra\Db;
use BetterCal\Infra\HttpClient;

$usage = "usage: php tools/google-probe/access.php --calendar <googleCalendarId> --account <google_accounts id or email> --stage <label> [--report <dir>] [--allow-other-events]\n"
    . "  stages the README uses, in order: writer, reader, freebusy, unshared, restored, deleted (and cleanup, which only deletes what the probe made); listread, on any calendar the account may write, compares a GET by id with a list by iCalUID and deletes what it made\n";
$opts = getopt('', ['calendar:', 'account:', 'stage:', 'report:', 'allow-other-events', 'help']);
if (isset($opts['help']) || !isset($opts['calendar'], $opts['account'], $opts['stage'])) {
    fwrite(STDERR, $usage);
    exit(isset($opts['help']) ? 0 : 2);
}
$calendarId = trim((string) $opts['calendar']);
if ($calendarId === '' || strtolower($calendarId) === 'primary') {
    fwrite(STDERR, "--calendar must name a scratch calendar by its Google id, never the main one.\n");
    exit(2);
}
$stage = (string) $opts['stage'];
if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $stage) !== 1) {
    fwrite(STDERR, "--stage is a short label of lower-case letters, digits and hyphens.\n");
    exit(2);
}

$cfg = config();
$db = new Db($cfg['db']);
$account = (string) $opts['account'];
$rows = ctype_digit($account)
    ? $db->all('SELECT * FROM google_accounts WHERE id = ?', [(int) $account])
    : $db->all('SELECT * FROM google_accounts WHERE email = ?', [$account]);
if (count($rows) !== 1) {
    fwrite(STDERR, count($rows) === 0 ? "No connected Google account matches --account.\n" : "More than one connected Google account matches --account; pass its id.\n");
    exit(1);
}
$account = $rows[0];
if ($db->scalar('SELECT COUNT(*) FROM calendars WHERE google_calendar_id = ?', [$calendarId]) > 0) {
    fwrite(STDERR, "Note: that calendar is connected in Better-Cal, so its polls will see the probe's events and the access changes too.\n");
}

$auth = new GoogleAuth($db, $cfg);
$sleep = static function (float $seconds): void {
    usleep((int) round($seconds * 1_000_000));
};
$transport = static function (string $method, string $url, array $headers, ?array $payload): array {
    $http = new HttpClient(requestBudget: 2, userAgent: HttpClient::userAgentFor('google-probe'));
    return $http->json($method, $url, $payload, $headers);
};
$api = new Api($transport, static fn(): string => $auth->accessToken($account), $calendarId, $sleep, 0.25);

$dir = isset($opts['report']) ? (string) $opts['report'] : $root . '/workfolder/google-access';
$scrubber = new Report($dir);
$scrubber->secret((string) $account['email'], '{account}');
$scrubber->secret($calendarId, '{calendar}');

$probe = new AccessProbe($api, $scrubber, $dir, isset($opts['allow-other-events']));
fwrite(STDERR, "Stage $stage; state in {$probe->statePath}.\n");
$record = $probe->run($stage);
foreach ($record['rows'] as $r) {
    fwrite(STDERR, sprintf("  %-3s %-55s %d %s\n", $r['n'], $r['request'], $r['status'], $r['reason']));
}
foreach ($record['notes'] as $note) {
    fwrite(STDERR, "  $note\n");
}
if (isset($record['stopped'])) {
    fwrite(STDERR, "Stopped: {$record['stopped']}\n{$probe->mdPath}\n");
    exit(1);
}
fwrite(STDERR, "Done: {$record['requests']} requests.\n{$probe->mdPath}\n{$probe->jsonPath}\n");
