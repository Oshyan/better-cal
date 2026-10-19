<?php
declare(strict_types=1);

// Disposable, loopback-only fixture. Never read an install's database settings.
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) exit(2);
$dsn = (string) getenv('BC_RESOURCE_DSN');
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]+;dbname=bc_phase6$/D', $dsn)) throw new RuntimeException('Use the dedicated disposable bc_phase6 database');
putenv('BETTERCAL_SESSION_SECRET=synthetic-resource-secret');
define('BETTERCAL_WEB_ENTRY', true);
require __DIR__ . '/../src/bootstrap.php';
use BetterCal\Infra\{Db, Migrator, JobQueue};
use BetterCal\Domain\{Auth, ApiTokens, Calendars, CalendarImport, CalendarMoveGuard, Events, Feeds, Filters, Ics, Labels, MailIngest, PushSubscriptions, Recurrence, Trips, Undo};
use BetterCal\Support\Limits;
use BetterCal\Http\HttpError;
$dbCfg = ['dsn' => $dsn, 'user' => 'sample', 'pass' => 'synthetic-only'];
$db = new Db($dbCfg);
$undo = new Undo($db);
$calendars = new Calendars($db, $undo, 'synthetic-resource-secret');
$importer = new CalendarImport($db, $calendars);
$event = static fn(string $uid, string $extra = ''): string => "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20900101T090000Z\r\nDTEND:20900101T100000Z\r\nSUMMARY:Sample event\r\n$extra" . "END:VEVENT\r\n";
$calendar = static fn(string $events): string => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Sample//EN\r\n$events" . "END:VCALENDAR\r\n";
$payload = static fn(string $suffix): array => ['endpoint' => 'https://fcm.googleapis.com/sample/' . $suffix, 'keys' => ['p256dh' => 'sample-key', 'auth' => 'sample-auth']];

if (PHP_SAPI === 'cli-server') {
    $principals = new BetterCal\Dav\PrincipalBackend($db);
    $auth = new BetterCal\Dav\AuthBackend($db);
    $backend = new BetterCal\Dav\CalendarBackend($db, $undo, fn() => $auth->authority());
    $server = new Sabre\DAV\Server([new Sabre\DAVACL\PrincipalCollection($principals), new Sabre\CalDAV\CalendarRoot($principals, $backend)]);
    $server->setBaseUri('/dav');
    $server->addPlugin(new Sabre\DAV\Auth\Plugin($auth));
    $server->addPlugin(new BetterCal\Dav\SizedCalDavPlugin());
    $server->addPlugin(new Sabre\DAVACL\Plugin());
    $server->exec(); exit;
}
if (($argv[1] ?? '') === 'import-child') {
    echo "ready\n"; fflush(STDOUT); fgets(STDIN);
    try { $importer->importIcs(1, $calendar($event('racing@example.test')), 'ignored', null, (int) $argv[2]); echo "imported\n"; }
    catch (HttpError $e) { echo $e->errorCode . "\n"; }
    exit;
}
if (($argv[1] ?? '') === 'push-child') {
    Limits::configure(['PUSH_DEVICES_PER_ACCOUNT' => 2]);
    echo "ready\n"; fflush(STDOUT); fgets(STDIN);
    try { (new PushSubscriptions($db))->subscribe(1, $payload('third'), false, (int) $argv[2]); echo "registered\n"; }
    catch (HttpError $e) { echo $e->errorCode . "\n"; }
    exit;
}
$checks = 0;
$verify = static function (string $name, bool $ok) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    $checks++; echo "PASS: $name\n";
};
$verify('fixture starts with an empty database', $db->all('SHOW TABLES') === []);
(new Migrator($db, __DIR__ . '/../migrations'))->run(static function () {});
$db->insert('users', ['email' => 'sam@example.test', 'password_hash' => password_hash('sample-password', PASSWORD_DEFAULT), 'settings_json' => '{"mailEventMode":"any"}']);
$session = (new Auth($db, ['session_secret' => 'synthetic-resource-secret']))->login('sam@example.test', 'sample-password')['token'];
$token = (new ApiTokens($db))->create(1, 'Sample agent');
$tokenId = (int) $token['id'];
$local = $calendars->create(1, ['name' => 'Sample local']); $id = (int) $local['id'];
$mixed = $calendar($event('bad@example.test', "DTEND:20900101T100000Z,20900102T100000Z\r\n") . $event('good@example.test'));
$done = $importer->importIcs(1, $mixed, 'ignored', null, $id);
$verify('mixed import commits good event and reports rejected one', $done['imported'] === 1 && count($done['skippedEvents']) === 1);
$feed = $calendars->create(1, ['name' => 'Sample feed']); $feedId = (int) $feed['id'];
$feeds = new Feeds($db); $feedRow = $db->one('SELECT * FROM calendars WHERE id=?', [$feedId]);
$feeds->sync($feedRow, Ics::parse($calendar($event('bad@example.test') . $event('good@example.test') . $event('deleted@example.test'))));
$parsed = Ics::parse($mixed, null, $skipped);
$feeds->sync($feedRow, $parsed, true, null, $skipped);
$verify('feed preserves rejected UID but deletes genuinely absent event', $db->scalar('SELECT id FROM events WHERE calendar_id=? AND uid=?', [$feedId, 'bad@example.test']) !== null && $db->scalar('SELECT id FROM events WHERE calendar_id=? AND uid=?', [$feedId, 'deleted@example.test']) === null);
$feeds->pollSucceeded($feedRow, count($parsed), 0, Ics::skippedMessage($skipped));
$verify('partial feed warning is visible without failed-poll status', $db->scalar('SELECT last_poll_status FROM calendars WHERE id=?', [$feedId]) === 'ok' && str_contains($db->scalar('SELECT last_poll_error FROM calendars WHERE id=?', [$feedId]), 'skipped'));
$feeds->sync($feedRow, $parsed, true, null, [['uid' => null]]);
$verify('unknown rejected identity suppresses only deletion', $db->scalar('SELECT id FROM events WHERE calendar_id=? AND uid=?', [$feedId, 'bad@example.test']) !== null);
$feeds->sync($feedRow, $parsed);
$verify('later readable feed resumes normal deletion', $db->scalar('SELECT id FROM events WHERE calendar_id=? AND uid=?', [$feedId, 'bad@example.test']) === null);
$events = new Events($db, new Recurrence(), $undo, new Labels($db), new Filters($db, $undo, new JobQueue($db)), new Trips($db, $undo));
$mail = new MailIngest($db, $events);
$mailText = $calendar($event('mail-bad@example.test', "DTEND:20900101T100000Z,20900102T100000Z\r\n") . $event('mail-one@example.test') . $event('mail-two@example.test'));
$result = $mail->ingestMessage(1, ['messageId' => 'mixed@example.test', 'subject' => 'Sample booking', 'from' => 'sam@example.test', 'icsParts' => [$mailText], 'html' => null, 'text' => null], 'UTC');
$verify('mail creates all good siblings and durable partial-error notice', $result['outcome'] === 'created' && (int) $db->scalar("SELECT COUNT(*) FROM events WHERE uid IN ('mail-one@example.test','mail-two@example.test')") === 2 && (int) $db->scalar("SELECT COUNT(*) FROM review_items WHERE kind='mail_error'") === 1);
$db->run('UPDATE users SET settings_json=? WHERE id=1', ['{"mailEventMode":"review"}']);
$heldText = $calendar($event('held@example.test') . $event('held@example.test') . $event('held-sibling@example.test'));
$held = $mail->ingestMessage(1, ['messageId' => 'held-mixed@example.test', 'subject' => 'Sample booking', 'from' => 'sam@example.test', 'icsParts' => [$heldText], 'html' => null, 'text' => null], 'UTC');
$verify('duplicate held component cannot block later good mail siblings', $held['outcome'] === 'held' && $db->scalar("SELECT id FROM review_items WHERE source_key='held-sibling@example.test'") !== null);

// Real database locks are the barriers. Observe the waiting statement before
// changing state; scheduling delays cannot turn this into a false race pass.
$race = static function (string $mode, int $arg, string $waitingSql, callable $whileLocked, string $expected) use ($db, $verify): void {
    $proc = proc_open([PHP_BINARY, __FILE__, $mode, (string) $arg], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    try {
        $verify('child reaches explicit start barrier', trim(fgets($pipes[1])) === 'ready');
        fwrite($pipes[0], "go\n"); fflush($pipes[0]);
        $waiting = false; $deadline = microtime(true) + 10;
        do {
            foreach ($db->all('SHOW FULL PROCESSLIST') as $row) {
                if (str_contains((string) ($row['Info'] ?? ''), $waitingSql)) { $waiting = true; break; }
            }
        } while (!$waiting && microtime(true) < $deadline && proc_get_status($proc)['running']);
        $verify('child is blocked at the shared row lock', $waiting);
        $whileLocked(); $db->pdo()->commit();
        $verify('waiting request rechecks committed state', trim(fgets($pipes[1])) === $expected);
    } finally {
        if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
        proc_terminate($proc); foreach ($pipes as $pipe) fclose($pipe); proc_close($proc);
    }
};
$db->pdo()->beginTransaction(); CalendarMoveGuard::lockCalendar($db, $id);
$race('import-child', $id, 'SELECT * FROM calendars WHERE id =', static function () use ($db, $id): void {
    $account = $db->insert('google_accounts', ['user_id' => 1, 'email' => 'sam@example.test', 'refresh_token_enc' => 'synthetic', 'scopes' => 'synthetic']);
    $db->insert('calendar_moves', ['user_id' => 1, 'calendar_id' => $id, 'google_account_id' => $account, 'google_calendar_id' => 'sample-remote', 'remote_marker' => str_repeat('a', 32), 'status' => 'running']);
}, 'calendar_moving');
$verify('racing import wrote nothing', $db->scalar("SELECT id FROM events WHERE uid='racing@example.test'") === null);
$push = new PushSubscriptions($db);
Limits::configure(['PUSH_DEVICES_PER_ACCOUNT' => 2]);
$push->subscribe(1, $payload('Case'), false, $tokenId);
$push->subscribe(1, $payload('case'), false, $tokenId);
$verify('case-sensitive push paths remain distinct on MySQL', count($push->forUser(1)) === 2);
$push->subscribe(1, $payload('Case#alias'), false, $tokenId);
$verify('fragment refresh at capacity does not create a device', count($push->forUser(1)) === 2);
$db->pdo()->beginTransaction(); $db->one('SELECT id FROM users WHERE id = 1 FOR UPDATE');
$race('push-child', $tokenId, 'SELECT id FROM users WHERE id =', static function (): void {}, 'push_device_limit');
$verify('over-cap enrollment left prior devices intact', count($push->forUser(1)) === 2);
$push->unsubscribe(1, 'https://fcm.googleapis.com/sample/Case#different', $tokenId);
$verify('fragment removal preserves different case-sensitive destination', count($push->forUser(1)) === 1 && $push->forUser(1)[0]['endpoint'] === 'https://fcm.googleapis.com/sample/case');
// Historical aliases cannot transfer browser/token provenance during repair.
$db->insert('push_subscriptions', ['user_id' => 1, 'endpoint' => 'https://fcm.googleapis.com/sample/case#old', 'endpoint_hash' => hash('sha256', 'https://fcm.googleapis.com/sample/case#old'), 'p256dh' => 'sample', 'auth' => 'sample']);
try { $push->subscribe(1, $payload('case'), false, null, $session); $verify('mixed provenance alias is refused', false); }
catch (HttpError $e) { $verify('mixed provenance alias is refused', $e->errorCode === 'push_alias_conflict'); }
$verify('historical aliases are delivered to only once', count($push->forUser(1)) === 1);
$push->remove(1, (int) $push->devices(1)[0]['id']);
$verify('owner removal removes all aliases of this device', !$push->hasAny(1));
$push->subscribe(1, $payload('case#new'), true, null, $session);
$verify('canonical removal tombstone prevents quiet re-registration', !$push->hasAny(1));
Limits::reset();

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); $address = stream_socket_get_name($socket, false); fclose($socket);
$root = sys_get_temp_dir() . '/bc-phase6-http-' . bin2hex(random_bytes(4)); mkdir($root, 0700);
$http = proc_open([PHP_BINARY, '-d', 'memory_limit=64M', '-S', $address, __FILE__], [0 => ['pipe','r'], 1 => ['file', $root . '/http.log', 'a'], 2 => ['pipe','w']], $pipes);
try {
    $verify('live CalDAV fixture starts', str_contains(fgets($pipes[2]), 'started'));
    $davCal = $calendars->create(1, ['name' => 'Sample DAV']);
    $url = 'http://' . $address . '/dav/calendars/sam%40example.test/cal-' . $davCal['id'] . '/dav-sample.ics';
    $put = static function (string $body, string $type = 'text/calendar') use ($url, $token): int {
        $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $body, CURLOPT_USERPWD => 'sam@example.test:' . $token['token'], CURLOPT_HTTPHEADER => ['Content-Type: ' . $type], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $response = curl_exec($ch); if ($response === false) throw new RuntimeException(curl_error($ch));
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); if ($status >= 500) throw new RuntimeException('Unexpected CalDAV server error: ' . $response); return $status;
    };
    $verify('live CalDAV accepts ordinary long description', $put($calendar($event('dav-sample', 'DESCRIPTION:' . str_repeat('sample ', 4000) . "\r\n"))) === 201);
    $verify('live CalDAV refuses oversized body before parser', $put(str_repeat('x', Limits::get('DAV_OBJECT_BYTES') + 1)) === 403);
    $verify('live CalDAV refuses malformed override atomically', $put($calendar($event('dav-sample') . $event('dav-sample', "RECURRENCE-ID:20900102T090000Z,20900103T090000Z\r\n"))) === 400);
    $verify('CalDAV refusal retains prior description', strlen($db->scalar("SELECT description FROM events WHERE calendar_id=? AND uid='dav-sample'", [$davCal['id']])) > 20000);
    $jcal = ['vcalendar', [['version', new stdClass(), 'text', '2.0'], ['prodid', new stdClass(), 'text', '-//Sample//EN']], [['vevent', [
        ['uid', new stdClass(), 'text', 'dav-sample'], ['dtstamp', new stdClass(), 'date-time', '2026-01-01T00:00:00Z'],
        ['dtstart', new stdClass(), 'date-time', '2090-01-01T09:00:00Z'], ['dtend', new stdClass(), 'date-time', '2090-01-01T10:00:00Z'], ['summary', new stdClass(), 'text', 'Sample'],
    ], []]]];
    $verify('valid jCal remains supported', $put(json_encode($jcal), 'application/calendar+json') === 204);
    $jcal[2][0][1][2][] = '2090-01-02T09:00:00Z';
    $verify('jCal multi-value singleton refused before normalization', $put(json_encode($jcal), 'application/calendar+json') === 400);
    $series = $event('dav-sample', "RRULE:FREQ=DAILY;COUNT=501\r\n");
    for ($i = 1; $i <= 500; $i++) {
        $start = (new DateTimeImmutable('2090-01-01 09:00:00', new DateTimeZone('UTC')))->modify("+$i days");
        $series .= str_replace(['20900101T090000Z', '20900101T100000Z'], [$start->format('Ymd\\THis\\Z'), $start->modify('+1 hour')->format('Ymd\\THis\\Z')], $event('dav-sample', 'RECURRENCE-ID:' . $start->format('Ymd\\THis\\Z') . "\r\n"));
    }
    $verify('CalDAV keeps the existing 500-override allowance', $put($calendar($series)) === 204 && (int) $db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id=?', [$davCal['id']]) === 501);
} finally {
    proc_terminate($http); foreach ($pipes as $pipe) fclose($pipe); proc_close($http); unlink($root . '/http.log'); rmdir($root);
}
// A synthetic heavy-account ZIP: no real install data, generated one calendar
// at a time and imported through the actual production path.
$zipPath = tempnam(sys_get_temp_dir(), 'bc-phase6-large-');
try {
    $zip = new ZipArchive(); $zip->open($zipPath, ZipArchive::OVERWRITE);
    for ($cal = 0; $cal < 50; $cal++) {
        $body = '';
        for ($ev = 0; $ev < 1000; $ev++) $body .= $event("large-$cal-$ev@example.test", 'DESCRIPTION:' . str_repeat('Invented sample text. ', 85) . "\r\n");
        $zip->addFromString("Sample archive $cal.ics", $calendar($body));
    }
    $zip->close(); unset($zip, $body); gc_mem_caches();
    $verify('synthetic heavy export fits compressed upload budget', filesize($zipPath) < Limits::get('IMPORT_BYTES'));
    $result = $importer->importZip(1, $zipPath);
    if ($result['skipped'] !== []) echo json_encode(array_slice($result['skipped'], 0, 3)) . "\n";
    $verify('heavy ZIP imports all calendars and events without truncation', count($result['calendars']) === 50 && array_sum(array_column($result['calendars'], 'imported')) === 50000 && $result['skipped'] === []);
} finally { unlink($zipPath); }
echo "$checks live resource checks passed\n";
