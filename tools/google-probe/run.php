<?php
// The Google probe, command-line entry (docs/design/google-series-survey.md,
// "The Probe"). It WRITES to the calendar it is given, and only to that one:
// use a throwaway calendar. See README.md.
//
//   php tools/google-probe/run.php --calendar <googleCalendarId> --account <google_accounts id or email>
//       [--only 1,2,...] [--guest <address>] [--report <dir>] [--allow-other-events]
//
// It loads the app's config and database as server/bin/worker.php does,
// takes the account's access token through GoogleAuth and talks to the
// Calendar API through HttpClient. The report goes to
// workfolder/google-probe/ (gitignored) unless --report names another folder.
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

use BetterCal\Domain\GoogleAuth;
use BetterCal\GoogleProbe\Api;
use BetterCal\GoogleProbe\Probe;
use BetterCal\GoogleProbe\Report;
use BetterCal\Infra\Db;
use BetterCal\Infra\HttpClient;

$opts = getopt('', ['calendar:', 'account:', 'only:', 'guest:', 'report:', 'allow-other-events', 'help']);
if (isset($opts['help']) || !isset($opts['calendar'], $opts['account'])) {
    fwrite(STDERR, "usage: php tools/google-probe/run.php --calendar <googleCalendarId> --account <google_accounts id or email> [--only 1,2,...] [--guest <address>] [--report <dir>] [--allow-other-events]\n");
    exit(isset($opts['help']) ? 0 : 2);
}
$calendarId = trim((string) $opts['calendar']);
if ($calendarId === '' || strtolower($calendarId) === 'primary') {
    fwrite(STDERR, "--calendar must name a throwaway calendar by its Google id, never the main one.\n");
    exit(2);
}
$only = isset($opts['only']) ? array_values(array_filter(array_map('trim', explode(',', (string) $opts['only'])))) : null;
foreach ($only ?? [] as $g) {
    if (!isset(Probe::GROUPS[$g])) {
        fwrite(STDERR, "--only takes group numbers 1 to 9, comma-separated; '$g' is not one.\n");
        exit(2);
    }
}
$guest = isset($opts['guest']) ? trim((string) $opts['guest']) : null;
if ($guest !== null && filter_var($guest, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "--guest needs an address.\n");
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
    fwrite(STDERR, "Note: that calendar is connected in Better-Cal, so its polls will bring the probe's events in and out. A calendar the app doesn't know is quieter.\n");
}

$auth = new GoogleAuth($db, $cfg);
$sleep = static function (float $seconds): void {
    usleep((int) round($seconds * 1_000_000));
};
$transport = static function (string $method, string $url, array $headers, ?array $payload): array {
    $http = new HttpClient(requestBudget: 2, userAgent: HttpClient::userAgentFor('google-probe'));
    return $http->json($method, $url, $payload, $headers);
};
$api = new Api($transport, static fn(): string => $auth->accessToken($account), $calendarId, $sleep, 0.25, $guest !== null);

$dir = isset($opts['report']) ? (string) $opts['report'] : $root . '/workfolder/google-probe';
$report = new Report($dir);
$report->secret((string) $account['email'], '{account}');
$report->secret($calendarId, '{calendar}');
if ($guest !== null) {
    $report->secret($guest, '{guest}');
}

fwrite(STDERR, "Writing the report to {$report->mdPath} as it runs.\n");
try {
    (new Probe($api, $report, $sleep, ['only' => $only, 'guest' => $guest, 'allowOtherEvents' => isset($opts['allow-other-events'])]))->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Stopped: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "What it got is in {$report->jsonPath}.\n");
    exit(1);
}
fwrite(STDERR, 'Done: ' . count($report->cases()) . " cases, {$api->requests} requests.\n{$report->mdPath}\n{$report->jsonPath}\n");
