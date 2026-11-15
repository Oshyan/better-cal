<?php
// A scenario of the app's own Google writes that the edit harness doesn't
// make (a single event created, changed and deleted; a whole repeating
// event deleted, plain and over an edited and a deleted day), run through the product's Events against a GoogleStand
// on a database of its own, a poll after each write. record.php runs it,
// one scenario per process, with GOOGLE_TRACE naming the trace file. Only
// the stand-in is contacted. See README.md, "Replaying the App's Requests".
//
//   BC_TEST_DB=127.0.0.1:33308 GOOGLE_TRACE=<file.json> php tools/google-probe/record-app.php --scenario <name> [--zone America/Los_Angeles]
//
// Scenarios: single-timed, single-all-day, series-deleted-plain, series-deleted-whole.
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

ini_set('memory_limit', '2048M');
// As the harness sets them: a secret for this run's own database, and the journal's strict mode.
if (getenv('BETTERCAL_SESSION_SECRET') === false) {
    putenv('BETTERCAL_SESSION_SECRET=' . bin2hex(random_bytes(32)));
}
putenv('BETTERCAL_STRICT_JOURNAL=1');
$root = dirname(__DIR__, 2);
require $root . '/server/src/bootstrap.php';
require $root . '/server/tests/db/lib.php';
require_once $root . '/server/tests/db/scratch.php';
require $root . '/tools/edit-harness/lib.php';
BetterCal\EditHarness\Product::use($root);

use BetterCal\EditHarness\GoogleStand;
use BetterCal\Infra\Db;
use BetterCal\Infra\Migrator;

const SCENARIOS = ['single-timed', 'single-all-day', 'series-deleted-plain', 'series-deleted-whole'];
$opts = getopt('', ['scenario:', 'zone:']);
$scenario = (string) ($opts['scenario'] ?? '');
if (!in_array($scenario, SCENARIOS, true)) {
    fwrite(STDERR, 'usage: php tools/google-probe/record-app.php --scenario ' . implode('|', SCENARIOS) . " [--zone <calendar zone>]\n");
    exit(2);
}
// The stand-in calendar's zone, as record.php --zone sets it through the environment; --zone here wins.
if (isset($opts['zone'])) {
    putenv('GOOGLE_STAND_ZONE=' . (string) $opts['zone']);
}

[$host, $port] = array_pad(explode(':', getenv('BC_TEST_DB') ?: '127.0.0.1:3306', 2), 2, '3306');
$user = getenv('BC_TEST_DB_USER') ?: 'root';
$password = getenv('BC_TEST_DB_PASS') ?: '';
$name = 'bc_trace_' . bin2hex(random_bytes(4));
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
ScratchDatabase::made($server, $name);
$code = 0;
try {
    $db = new Db(['dsn' => "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", 'user' => $user, 'pass' => $password]);
    (new Migrator($db, $root . '/server/migrations'))->run(static function (string $line): void {
    });
    $domains = domains($db);
    $u = BetterCal\EditHarness\Product::account($db);
    $stand = GoogleStand::make($db, $domains->undo, $u);
    $events = $stand->events;
    $cal = $stand->calendarId;
    $stand->poll();
    $write = static function (callable $w) use ($stand): void {
        $w();
        $stand->poll();
    };
    switch ($scenario) {
        case 'single-timed':
            $id = 0;
            $write(static function () use ($events, $u, $cal, &$id): void {
                $id = (int) $events->create($u, ['calendarId' => $cal, 'title' => 'Sample single', 'location' => 'Sample Hall',
                    'start' => '2027-04-02T09:00:00-07:00', 'end' => '2027-04-02T10:00:00-07:00', 'allDay' => false, 'tzid' => 'America/Los_Angeles'])['eventId'];
            });
            $write(static fn() => $events->patch($u, $id, ['title' => 'Sample single renamed']));
            $write(static fn() => $events->patch($u, $id, ['start' => '2027-04-02T11:00:00-07:00', 'end' => '2027-04-02T12:30:00-07:00']));
            $write(static fn() => $events->patch($u, $id, ['tzid' => 'America/Denver', 'start' => '2027-04-02T11:00:00-07:00', 'end' => '2027-04-02T12:30:00-07:00']));
            $write(static fn() => $events->patch($u, $id, ['allDay' => true, 'start' => '2027-04-02', 'end' => '2027-04-03']));
            $write(static fn() => $events->patch($u, $id, ['allDay' => false, 'start' => '2027-04-02T09:00:00-07:00', 'end' => '2027-04-02T10:00:00-07:00', 'tzid' => 'America/Los_Angeles']));
            $write(static fn() => $events->deleteEvent($u, $id, null, null));
            break;
        case 'single-all-day':
            $id = 0;
            $write(static function () use ($events, $u, $cal, &$id): void {
                $id = (int) $events->create($u, ['calendarId' => $cal, 'title' => 'Sample day out', 'start' => '2027-04-10', 'end' => '2027-04-12', 'allDay' => true, 'tzid' => 'UTC'])['eventId'];
            });
            $write(static fn() => $events->patch($u, $id, ['location' => 'Sample Park', 'description' => 'Sample notes']));
            $write(static fn() => $events->patch($u, $id, ['start' => '2027-04-11', 'end' => '2027-04-12']));
            $write(static fn() => $events->deleteEvent($u, $id, null, null));
            break;
        case 'series-deleted-plain':
            // Its subject is the delete: nothing before it but the series made and polled.
            $id = 0;
            $write(static function () use ($events, $u, $cal, &$id): void {
                $id = (int) $events->create($u, ['calendarId' => $cal, 'title' => 'Sample practice', 'location' => 'Sample Hall', 'rrule' => 'FREQ=WEEKLY;COUNT=6',
                    'start' => '2027-05-04T17:00:00+00:00', 'end' => '2027-05-04T18:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC'])['eventId'];
            });
            $write(static fn() => $events->deleteEvent($u, $id, 'all', null));
            break;
        case 'series-deleted-whole':
            // The delete over an edited day and a deleted day, each made and polled first.
            $id = 0;
            $write(static function () use ($events, $u, $cal, &$id): void {
                $id = (int) $events->create($u, ['calendarId' => $cal, 'title' => 'Sample weekly', 'location' => 'Sample Hall', 'rrule' => 'FREQ=WEEKLY;COUNT=6',
                    'start' => '2027-05-03T18:00:00+00:00', 'end' => '2027-05-03T19:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC'])['eventId'];
            });
            $write(static fn() => $events->patch($u, $id, ['title' => 'Sample weekly, one day', 'scope' => 'this', 'instanceStart' => '2027-05-10T18:00:00+00:00']));
            $write(static fn() => $events->deleteEvent($u, $id, 'this', '2027-05-17T18:00:00+00:00'));
            $write(static fn() => $events->deleteEvent($u, $id, 'all', null));
            break;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "$scenario stopped: " . get_class($e) . ': ' . $e->getMessage() . "\n");
    $code = 1;
} finally {
    // The trace is written as the scenario runs; the cleanup after it never changes the exit status (ScratchDatabase).
    ScratchDatabase::dropMade();
}
echo ScratchDatabase::summary() . "\n";
exit($code);
