<?php

declare(strict_types=1);

use BetterCal\Domain\Auth;
use BetterCal\Domain\BrowserSubscriptions;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\HealthDiagnostics;
use BetterCal\Domain\NotificationDestinations;
use BetterCal\Domain\Plugins;
use BetterCal\Domain\PluginSecrets;
use BetterCal\Domain\RequestAuthority;
use BetterCal\Domain\Settings;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\Secrets;

// In-memory fixtures contain invented values only. Barriers run at exact
// lifecycle boundaries; no sleeps or probabilistic race loops are used.
$credentialDb = static function (): Db {
    $db = new Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]);
    foreach ([
        'users' => 'id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, settings_json TEXT',
        'sessions' => 'token_hash TEXT PRIMARY KEY, user_id INTEGER, csrf TEXT, authenticated_at TEXT, expires_at TEXT, last_seen_at TEXT',
        'api_tokens' => 'id INTEGER PRIMARY KEY, user_id INTEGER, expires_at TEXT',
        'calendars' => "id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, source_url TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, kind TEXT, provider TEXT, subscription_authority TEXT, created_by_token_id INTEGER, created_by_session_hash TEXT, settings_json TEXT, google_account_id INTEGER, google_calendar_id TEXT, google_binding_version INTEGER DEFAULT 0",
        'google_accounts' => 'id INTEGER PRIMARY KEY, user_id INTEGER, email TEXT, refresh_token_enc TEXT, status TEXT, last_error TEXT, reauth_required_at TEXT, scopes TEXT, created_at TEXT',
        'calendar_moves' => 'id INTEGER PRIMARY KEY, google_account_id INTEGER',
        'plugins' => 'id TEXT PRIMARY KEY, settings_json TEXT, enabled INTEGER DEFAULT 1, disabled_reason TEXT, consecutive_failures INTEGER DEFAULT 0, changed_by_token_id INTEGER',
        'push_subscriptions' => 'id INTEGER PRIMARY KEY, user_id INTEGER, endpoint TEXT, endpoint_hash TEXT, p256dh TEXT, auth TEXT, created_by_token_id INTEGER',
        'mutations' => 'id INTEGER PRIMARY KEY, user_id INTEGER, entity TEXT, entity_id INTEGER, op TEXT, before_json TEXT, after_json TEXT, source TEXT, run_id TEXT, summary TEXT, details_json TEXT',
        'events' => 'id INTEGER PRIMARY KEY, user_id INTEGER, calendar_id INTEGER, uid TEXT, google_event_id TEXT, invite_json TEXT, deleted_at TEXT, attendance TEXT',
    ] as $table => $columns) $db->run("CREATE TABLE $table ($columns)");
    $db->run('INSERT INTO users VALUES (1, ?, ?, ?)', ['sam@example.test', password_hash('sample-password', PASSWORD_DEFAULT), '{}']);
    $db->run('INSERT INTO sessions (token_hash,user_id,authenticated_at,expires_at) VALUES (?,1,CURRENT_TIMESTAMP,?)', [hash('sha256', 'sample-session'), '2099-01-01 00:00:00']);
    $db->run('INSERT INTO api_tokens VALUES (1,1,NULL)');
    return $db;
};
$credentialRefused = static function (string $name, callable $call, ?string $code = null): void {
    try {
        $call();
        check($name, false, 'unsafe operation succeeded');
    } catch (HttpError $e) {
        check($name, $code === null || $e->errorCode === $code, $e->errorCode);
    }
};
$cfg = ['session_secret' => 'invented-credential-test-secret', 'google' => ['client_id' => 'sample', 'client_secret' => 'sample'], 'base_url' => 'https://calendar.example.test'];

$db = $credentialDb();
$auth = new Auth($db, $cfg);
$newHash = password_hash('replacement-password', PASSWORD_DEFAULT);
$GLOBALS['credentialPasswordBarrier'] = static function () use ($db, $newHash): void {
    $db->run('UPDATE users SET password_hash = ? WHERE id = 1', [$newHash]);
    $db->run('DELETE FROM sessions WHERE user_id = 1');
};
checkEq('credential race: reset after verification refuses old-password login', null, $auth->login('sam@example.test', 'sample-password'));
checkEq('credential race: stale login does not restore an old hash', $newHash, $db->scalar('SELECT password_hash FROM users WHERE id = 1'));
checkEq('credential race: no stale session inserted', 0, (int) $db->scalar('SELECT COUNT(*) FROM sessions'));
check('credential control: current password still signs in', $auth->login('sam@example.test', 'replacement-password') !== null);

$db = $credentialDb();
$settings = new Settings($db);
foreach ([['workerOffAt' => null], ['workerOffAt' => '2026-01-01 00:00:00'], ['updateNotifications' => 'off', 'theme' => 'dark']] as $patch) {
    $credentialRefused('token settings: alert suppression refused', fn() => $settings->patch(1, $patch, 1), 'session_required');
}
checkEq('token settings: refused mixed patch writes nothing', '{}', $db->scalar('SELECT settings_json FROM users'));
foreach (['all', 'security'] as $preference) checkEq('token settings: allowed update preference', $preference, $settings->patch(1, ['updateNotifications' => $preference], 1)['updateNotifications']);
$stale = RequestAuthority::credential(1, null, 'sample-session');
$db->run('UPDATE users SET settings_json = ? WHERE id = 1', [json_encode(['notifyEmail' => null, 'notifyEmailToken' => null])]);
$db->run('DELETE FROM sessions');
$credentialRefused('settings race: ordinary patch from revoked session refused', fn() => $settings->patch(1, ['theme' => 'dark'], null, 'sample-session'), 'session_revoked');
$credentialRefused('shared authority: captured session cannot commit', fn() => $db->tx(fn() => $stale->assertValid($db)), 'session_revoked');
$db->run('DELETE FROM api_tokens');
$credentialRefused('shared authority: captured token cannot commit', fn() => $settings->patch(1, ['theme' => 'dark'], 1));

$db = $credentialDb();
$addFeed = static function (int $id, ?string $session, string $authority = 'owner') use ($db): void {
    $db->run("INSERT INTO calendars (id,user_id,name,source_url,kind,provider,subscription_authority,created_by_session_hash) VALUES (?,1,?,?,'subscribed','ics',?,?)", [$id, 'Sample feed ' . $id, 'https://sam:invented-password@feeds.example.test/private.ics?key=invented-secret', $authority, $session === null ? null : hash('sha256', $session)]);
};
$addFeed(1, null);
$addFeed(2, 'other-browser');
$addFeed(3, 'sample-session');
$addFeed(4, null, 'token');
$preview = BrowserSubscriptions::preview($db, 1, 'sample-session', $cfg['session_secret']);
checkEq('recovery preview: only other-browser feeds need a choice', [2], array_column($preview['subscriptions'], 'id'));
check('recovery preview: no URL credentials or path', !str_contains(json_encode($preview), 'invented-secret') && !str_contains(json_encode($preview), 'private.ics') && !str_contains(json_encode($preview), 'invented-password'));
checkEq('recovery preview: feed host remains useful', 'feeds.example.test', $preview['subscriptions'][0]['host']);
$addFeed(5, 'other-browser');
$credentialRefused('recovery race: new feed invalidates old preview', fn() => $db->tx(fn() => BrowserSubscriptions::decide($db, 1, 'sample-session', ['version' => $preview['version'], 'choice' => 'pause'])), 'subscription_review_changed');
checkEq('recovery race: stale preview pauses nothing', 0, (int) $db->scalar("SELECT COUNT(*) FROM calendars WHERE subscription_authority = 'session_review'"));
$preview = BrowserSubscriptions::preview($db, 1, 'sample-session', $cfg['session_secret']);
checkEq('recovery control: explicit pause applies to listed feeds', 2, $db->tx(fn() => BrowserSubscriptions::decide($db, 1, 'sample-session', ['version' => $preview['version'], 'choice' => 'pause'])));
checkEq('recovery compatibility: old owner/current-browser/token authority preserved', ['owner','owner','token'], array_column($db->all('SELECT subscription_authority FROM calendars WHERE id IN (1,3,4) ORDER BY id'), 'subscription_authority'));
$db->run("UPDATE calendars SET subscription_authority='owner' WHERE id=2");
$preview = BrowserSubscriptions::preview($db, 1, 'sample-session', $cfg['session_secret']);
$db->tx(fn() => BrowserSubscriptions::decide($db, 1, 'sample-session', ['version' => $preview['version'], 'choice' => 'keep']));
checkEq('recovery control: explicit keep confirms ownership', null, $db->scalar('SELECT created_by_session_hash FROM calendars WHERE id=2'));

$db = $credentialDb();
$schema = [['key' => 'credential', 'type' => 'secret', 'label' => 'Credential']];
foreach (['settings','calendarSettings','eventSettings'] as $scope) {
    $sealed = PluginSecrets::seal('sample-secret', $scope, $schema, ['credential' => 'invented-private-value'], $cfg['session_secret']);
    check('plugin secret: plaintext is sealed in ' . $scope, !str_contains(json_encode($sealed), 'invented-private-value'));
    checkEq('plugin secret: public set status in ' . $scope, ['credential' => ['set' => true]], PluginSecrets::project($schema, $sealed));
    checkEq('plugin secret: runtime access in ' . $scope, ['credential' => 'invented-private-value'], PluginSecrets::open('sample-secret', $scope, $sealed, $cfg['session_secret']));
    checkEq('plugin secret: envelope hidden even without schema', ['credential' => ['set' => true]], PluginSecrets::project([], $sealed));
}
$credentialRefused('plugin secret: missing session secret refuses storage', fn() => PluginSecrets::seal('sample-secret','settings',$schema,['credential'=>'invented'],''), 'session_secret_missing');
$db->run("INSERT INTO plugins (id,settings_json) VALUES ('sample-secret','{}')");
$plugins = new Plugins($db, __DIR__ . '/fixtures/credential-plugins', $cfg);
check('plugin fixture manifest is valid', $plugins->manifest('sample-secret') !== null);
$saved = $plugins->saveSettings('sample-secret', ['credential'=>'invented-private-value'], RequestAuthority::credential(1, 1, null));
checkEq('plugin settings: secret save response is write-only', ['set'=>true], $saved['credential']);
$saved = $plugins->saveSettings('sample-secret', ['mode'=>'normal'], RequestAuthority::credential(1, 1, null));
checkEq('plugin settings: omission preserves secret', ['set'=>true], $saved['credential']);
checkEq('plugin settings: a key save records that key for revocation', 1, (int) $db->scalar("SELECT changed_by_token_id FROM plugins WHERE id='sample-secret'"));
$credentialRefused('plugin validation: private errors refused', fn() => $plugins->saveSettings('sample-secret',['mode'=>'reject','credential'=>'invented-leak'], RequestAuthority::credential(1, 1, null)));
try { $plugins->saveSettings('sample-secret',['mode'=>'reject','credential'=>'invented-leak']); } catch (HttpError $e) { check('plugin validation: error does not echo secret', !str_contains($e->getMessage(),'invented-leak')); }
$host = new BetterCal\Plugin\PluginHost($db,'sample-secret',1,new BetterCal\Infra\HttpClient(),$plugins->manifest('sample-secret'),$cfg);
checkEq('plugin settings: runtime opens stored secret', 'invented-private-value', $host->settings()['credential']);
$host->log('token=invented-private-value');
check('plugin log: secret never enters run tail', !str_contains($host->logTail(),'invented-private-value'));
$saved = $plugins->saveSettings('sample-secret',['credential'=>null],RequestAuthority::credential(1,1,null));
checkEq('plugin settings: explicit null clears', ['set'=>false], $saved['credential']);
$GLOBALS['credentialValidationBarrier'] = static fn() => $db->run('DELETE FROM api_tokens');
$credentialRefused('plugin race: revoke during validation blocks save', fn() => $plugins->saveSettings('sample-secret',['mode'=>'late'],RequestAuthority::credential(1,1,null)));
checkEq('plugin race: stored setting unchanged', 'normal', json_decode($db->scalar('SELECT settings_json FROM plugins'),true)['mode']);

$db = $credentialDb();
$sealedGrant = Secrets::seal('invented-grant', $cfg['session_secret']);
$db->run("INSERT INTO google_accounts (id,user_id,email,refresh_token_enc,status,created_at) VALUES (1,1,'sam@example.test',?,'ok',CURRENT_TIMESTAMP)", [$sealedGrant]);
$google = new GoogleAuth($db, $cfg, fn() => ['status'=>400,'body'=>'{"error":"invalid_grant","error_description":"invented provider error"}']);
$credentialRefused('Google revoked grant: classified as reconnect needed', fn() => $google->accessToken($google->account(1,1)), 'google_reconnect_needed');
$account = GoogleAuth::serializeAccount($google->account(1,1));
check('Google revoked grant: Settings receives reconnect flag and clear text', $account['reauthRequired'] && str_contains($account['error'],'Reconnect needed'));
$db->run('UPDATE google_accounts SET reauth_required_at=NULL,status=?', ['ok']);
$replacement = Secrets::seal('replacement-grant', $cfg['session_secret']);
$google = new GoogleAuth($db,$cfg,static function () use ($db,$replacement): array {
    $db->run('UPDATE google_accounts SET refresh_token_enc=? WHERE id=1',[$replacement]);
    return ['status'=>400,'body'=>'{"error":"invalid_grant"}'];
});
$credentialRefused('Google race: stale failed refresh is refused', fn() => $google->accessToken($google->account(1,1)), 'google_reconnect_needed');
checkEq('Google race: stale failure does not poison replacement grant', null, $db->scalar('SELECT reauth_required_at FROM google_accounts'));
$google = new GoogleAuth($db,$cfg,static function () use ($db): array {
    $db->run('DELETE FROM sessions');
    return ['status'=>200,'body'=>'{}'];
});
$credentialRefused('Google disconnect race: revoked initiating session cannot delete', fn() => $google->disconnect(1,1,'sample-session'), 'session_revoked');
checkEq('Google disconnect race: account remains',1,(int)$db->scalar('SELECT COUNT(*) FROM google_accounts'));

$db = $credentialDb();
$db->run("INSERT INTO push_subscriptions VALUES (1,1,'https://push.example.test/sample','sample-hash','sample-public-key','sample-auth',1)");
$push = $db->one('SELECT * FROM push_subscriptions');
check('notification control: live token destination allowed',NotificationDestinations::push($db,$push)!==null);
$db->run('DELETE FROM api_tokens');
checkEq('notification barrier: revoked token drops captured push',null,NotificationDestinations::push($db,$push));
$db->run('UPDATE users SET settings_json=?',[json_encode(['notifyEmail'=>'notice@example.test','notifyEmailToken'=>1])]);
check('notification barrier: revoked token email not sent',!NotificationDestinations::email($db,1,'notice@example.test'));
check('notification control: account email remains usable',NotificationDestinations::email($db,1,'sam@example.test'));

$raw = 'HTTP 403 from https://sam:invented-password@feeds.example.test/private/path?key=invented-secret in /srv/sample/private.php';
$safe = HealthDiagnostics::error($raw);
check('health: cleaned HTTP reason and host retained',str_contains($safe,'HTTP 403') && str_contains($safe,'feeds.example.test'));
foreach (['invented-password','invented-secret','/private/path','/srv/sample'] as $private) check('health: private diagnostic component removed',!str_contains($safe,$private));
check('health: unknown raw error is not echoed',!str_contains(HealthDiagnostics::error('credential invented-private-value'),'invented-private-value'));
