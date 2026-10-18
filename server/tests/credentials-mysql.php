<?php

declare(strict_types=1);

// Run only against a new disposable database, never an install's settings.
require __DIR__ . '/../src/bootstrap.php';

use BetterCal\Domain\{ApiTokens, Auth, BrowserSubscriptions, Calendars, Feeds, GoogleAuth, GoogleWriter, Plugins, RequestAuthority, Rsvp, Settings, Undo};
use BetterCal\Http\{HttpError, Request};
use BetterCal\Http\Controllers\PluginsController;
use BetterCal\Infra\{Db, EmailSender, JobQueue, Migrator, Secrets};

if (getenv('BC_CREDENTIALS_DISPOSABLE') !== '1') throw new RuntimeException('Requires a disposable test database');
putenv('BETTERCAL_SESSION_SECRET=synthetic-session-secret');
$dbCfg = ['dsn' => getenv('BC_CREDENTIALS_DSN'), 'user' => 'sample', 'pass' => 'synthetic-only'];
$db = new Db($dbCfg);
if ((int) $db->scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') !== 0) throw new RuntimeException('Test database must be empty');
(new Migrator($db, __DIR__ . '/../migrations'))->run(static function (): void {});
$checks = 0;
function verifyCredential(string $name, bool $ok): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    $checks++;
    echo 'PASS: ' . $name . "\n";
}
function refusedCredential(callable $call, string $code): void {
    try { $call(); } catch (HttpError $e) {
        verifyCredential($code, $e->errorCode === $code);
        return;
    }
    throw new RuntimeException('Expected refusal: ' . $code);
}
$cfg = ['db' => $dbCfg, 'session_secret' => 'synthetic-session-secret', 'base_url' => 'https://calendar.example.test', 'google' => ['client_id' => 'sample', 'client_secret' => 'sample']];
$db->insert('users', ['email' => 'sam@example.test', 'password_hash' => password_hash('sample-password', PASSWORD_DEFAULT)]);
$auth = new Auth($db, $cfg);
$login = $auth->login('sam@example.test', 'sample-password');
$session = $login['token'];
$tokens = new ApiTokens($db);
$token = $tokens->create(1, 'Sample agent');
$tokenId = (int) $token['id'];
$authority = RequestAuthority::credential(1, $tokenId, null);
$undo = new Undo($db);
$calendars = new Calendars($db, $undo, $cfg['session_secret']);
$calendar = $calendars->create(1, ['name' => 'Sample calendar']);
$calendarId = (int) $calendar['id'];
$eventId = $db->insert('events', ['user_id' => 1, 'calendar_id' => $calendarId, 'uid' => 'sample-event', 'title' => 'Sample event', 'start_utc' => '2027-01-01 10:00:00', 'end_utc' => '2027-01-01 11:00:00']);
$plugins = new Plugins($db, __DIR__ . '/fixtures/credential-plugins', $cfg);
$pc = new PluginsController($plugins, new JobQueue($db), null, $undo, $db);
$request = static function (array $body = []) use ($tokenId): Request {
    $req = new Request('PATCH', '/', body: $body);
    $req->user = ['id' => 1]; $req->authMethod = 'token'; $req->tokenId = $tokenId;
    return $req;
};
$pc->enable($request(), ['id' => 'sample-secret']);
$pc->saveSettings($request(['credential' => 'invented-global-secret']), ['id' => 'sample-secret']);
$pc->saveCalendarSettings($request(['credential' => 'invented-calendar-secret']), ['id' => 'sample-secret', 'calendarId' => $calendarId]);
$pc->saveEventData($request(['credential' => 'invented-event-secret']), ['id' => $eventId, 'pluginId' => 'sample-secret']);
$saved = $pc->saveEventData($request([]), ['id' => $eventId, 'pluginId' => 'sample-secret']);
verifyCredential('event secret omission keeps set status', str_contains($saved->body, '"set":true') && !str_contains($saved->body, 'invented-event-secret'));
$inventory = $pc->index($request())->body;
$eventData = $pc->eventData($request(), ['id' => $eventId])->body;
$calendarData = json_encode($calendars->listAll(1, $tokenId));
verifyCredential('all three secret scopes hidden from inventory and calendar/event reads', !str_contains($inventory . $eventData . $calendarData, 'invented-'));
$pc->runNow($request(), ['id' => 'sample-secret']);
$pc->disable($request(), ['id' => 'sample-secret']);
verifyCredential('token plugin enable/configure/run/disable controls work', (int) $db->scalar('SELECT COUNT(*) FROM jobs') > 0 && !(bool) $db->scalar("SELECT enabled FROM plugins WHERE id='sample-secret'"));
refusedCredential(fn() => $pc->uninstall($request(), ['id' => 'sample-secret']), 'session_required');

$davAuth = new BetterCal\Dav\AuthBackend($db);
$validateDav = new ReflectionMethod($davAuth, 'validateUserPass');
verifyCredential('CalDAV authenticates and remembers the exact token', $validateDav->invoke($davAuth, 'sam@example.test', $token['token']));
$dav = new BetterCal\Dav\CalendarBackend($db, $undo, fn() => $davAuth->authority());
$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\nBEGIN:VEVENT\r\nUID:sample-dav\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20270102T100000Z\r\nDTEND:20270102T110000Z\r\nSUMMARY:Sample DAV event\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$dav->createCalendarObject($calendarId, 'sample-dav.ics', $ics);
verifyCredential('live CalDAV token can write', (int) $db->scalar("SELECT COUNT(*) FROM events WHERE uid='sample-dav'") === 1);

$feed = $calendars->create(1, ['name' => 'Sample feed'], 'subscribed', 'https://feeds.example.test/sample.ics', creatorTokenId: $tokenId);
refusedCredential(fn() => $calendars->claimSubscription(1, (int) $feed['id'], $session), 'subscription_already_authorized');
$tokens->revoke(1, $tokenId);
try {
    $dav->deleteCalendarObject($calendarId, 'sample-dav.ics');
    throw new RuntimeException('Revoked CalDAV write was admitted');
} catch (Sabre\DAV\Exception\NotAuthenticated) {
    verifyCredential('admitted CalDAV token cannot delete after revocation', (int) $db->scalar("SELECT COUNT(*) FROM events WHERE uid='sample-dav' AND deleted_at IS NULL") === 1);
}
$claimed = $calendars->claimSubscription(1, (int) $feed['id'], $session);
verifyCredential('revoked-token feed can be kept with recent password', $claimed['subscriptionAuthorization']['status'] === 'active');
$db->run('UPDATE sessions SET authenticated_at = ? WHERE token_hash = ?', ['2000-01-01 00:00:00', hash('sha256', $session)]);
$db->run("UPDATE calendars SET subscription_authority='session_review' WHERE id=?", [$feed['id']]);
refusedCredential(fn() => $calendars->claimSubscription(1, (int) $feed['id'], $session), 'step_up_required');
$auth->confirmPassword(1, $session, 'sample-password');

// An unrelated undo snapshot must not revive authority or a cleared secret.
$before = $db->one('SELECT * FROM calendars WHERE id=?', [$calendarId]);
$db->run('UPDATE calendars SET name=? WHERE id=?', ['Renamed sample', $calendarId]);
$after = $db->one('SELECT * FROM calendars WHERE id=?', [$calendarId]);
$undo->record(1,'calendar',$calendarId,'update',['calendars'=>[$before]],['calendars'=>[$after]],'Renamed sample calendar');
$mutationId = (int) $db->scalar('SELECT MAX(id) FROM mutations');
$db->run('UPDATE calendars SET settings_json=? WHERE id=?', [json_encode(['plugins'=>['sample-secret'=>['credential'=>null]]]),$calendarId]);
$undo->undoById(1,$mutationId);
verifyCredential('ordinary calendar undo cannot restore cleared plugin secret', json_decode($db->scalar('SELECT settings_json FROM calendars WHERE id=?',[$calendarId]),true)['plugins']['sample-secret']['credential'] === null);

// Repeatable-read snapshot must not make a revoked credential appear live.
$other = new Db($dbCfg);
$db->pdo()->beginTransaction();
$db->scalar('SELECT COUNT(*) FROM sessions');
$other->run('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $session)]);
refusedCredential(fn() => RequestAuthority::credential(1, null, $session)->assertValid($db), 'session_revoked');
$db->pdo()->rollBack();
verifyCredential('current-read authority ignores an older transaction snapshot', true);

// Two processes meet at an explicit pipe barrier: admission happens before
// recovery, but the mutation starts only after recovery committed.
if (!function_exists('pcntl_fork')) throw new RuntimeException('This integration test requires pcntl');
$session = $auth->login('sam@example.test', 'sample-password')['token'];
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
$pid = pcntl_fork();
if ($pid === 0) {
    fclose($pair[0]);
    $child = new Db($dbCfg);
    RequestAuthority::credential(1, null, $session)->assertValid($child, false);
    fwrite($pair[1], "admitted\n");
    fgets($pair[1]);
    try { (new Settings($child))->patch(1, ['theme' => 'dark'], null, $session); fwrite($pair[1], "unsafe\n"); }
    catch (HttpError $e) { fwrite($pair[1], $e->errorCode . "\n"); }
    fclose($pair[1]);
    exit(0);
}
fclose($pair[1]);
verifyCredential('child request admitted before recovery', trim(fgets($pair[0])) === 'admitted');
$other->run('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $session)]);
fwrite($pair[0], "recovered\n");
verifyCredential('admitted settings mutation refused after recovery barrier', trim(fgets($pair[0])) === 'session_revoked');
fclose($pair[0]); pcntl_waitpid($pid, $status);
// Child destructors close inherited PDO sockets. Use fresh connections now.
$db = new Db($dbCfg); $auth = new Auth($db, $cfg);
$session = $auth->login('sam@example.test', 'sample-password')['token'];

// Provider callbacks are deterministic pre-send and post-send race barriers.
$grant = Secrets::seal('invented-grant', $cfg['session_secret']);
$accountId = $db->insert('google_accounts', ['user_id'=>1,'email'=>'sam@example.test','refresh_token_enc'=>$grant,'scopes'=>'sample','status'=>'ok']);
$db->update('calendars', ['kind'=>'subscribed','provider'=>'google','google_account_id'=>$accountId,'google_calendar_id'=>'sample-calendar','google_access_role'=>'writer'], 'id=?', [$calendarId]);
$invite = ['via'=>'google','kind'=>'invitation','myPartstat'=>'NEEDS-ACTION'];
$db->update('events', ['google_event_id'=>'sample-event','invite_json'=>json_encode($invite)], 'id=?', [$eventId]);
$resource = ['id'=>'sample-event','iCalUID'=>'sample-event','summary'=>'Sample event','start'=>['dateTime'=>'2027-01-01T10:00:00Z'],'end'=>['dateTime'=>'2027-01-01T11:00:00Z'],'organizer'=>['email'=>'alex@example.test'],'attendees'=>[['email'=>'sam@example.test','self'=>true,'responseStatus'=>'accepted']]];
foreach (['before-patch','after-patch','grant-replaced','control'] as $mode) {
    $session = $auth->login('sam@example.test', 'sample-password')['token'];
    $db->update('events', ['invite_json'=>json_encode($invite)], 'id=?', [$eventId]);
    $sent = 0;
    $google = new GoogleAuth($db,$cfg,fn() => ['status'=>200,'body'=>'{"access_token":"synthetic-access"}']);
    $writer = new GoogleWriter($db,$google,new Feeds($db,null,$cfg),static function ($method) use ($mode,$db,$calendarId,$accountId,$resource,$cfg,&$sent): array {
        verifyCredential('provider I/O is outside database transaction', !$db->pdo()->inTransaction());
        if ($method === 'GET' && $mode === 'before-patch') $db->run('UPDATE calendars SET google_binding_version=google_binding_version+1 WHERE id=?',[$calendarId]);
        if ($method === 'PATCH') {
            $sent++;
            if ($mode === 'after-patch') $db->run('DELETE FROM sessions');
            if ($mode === 'grant-replaced') $db->update('google_accounts',['refresh_token_enc'=>Secrets::seal('replacement-grant',$cfg['session_secret'])],'id=?',[$accountId]);
        }
        return ['status'=>200,'body'=>json_encode($resource)];
    });
    $rsvp = new Rsvp($db,new EmailSender([]),$cfg,$writer);
    if ($mode === 'control') {
        verifyCredential('legitimate Google reply still commits', $rsvp->answer(1,$eventId,'accepted',$session)['sent']);
    } else {
        $code = ['before-patch'=>'google_binding_changed','after-patch'=>'session_revoked','grant-replaced'=>'google_connection_changed'][$mode];
        refusedCredential(fn() => $rsvp->answer(1,$eventId,'accepted',$session),$code);
        verifyCredential('rejected reply leaves local invitation unchanged', json_decode($db->scalar('SELECT invite_json FROM events WHERE id=?',[$eventId]),true)['myPartstat']==='NEEDS-ACTION');
    }
    verifyCredential('pre-send cutover stops PATCH; post-send guard cannot recall it', $sent === ($mode === 'before-patch' ? 0 : 1));
}
echo "Credential MySQL integration: $checks checks passed\n";
