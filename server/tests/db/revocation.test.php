<?php

declare(strict_types=1);

// Every write made under a request rechecks the request's credential at the
// write boundary (Undo::write): a session or API key revoked after the
// request signed in and before its write commits nothing, for the routes
// added since 1.0 that carried no credential of their own (a calendar's
// place in the list, filing a calendar in a folder and taking it out, an
// event's looked-up place). With the credential still valid, each goes
// through.

use BetterCal\Domain\RequestAuthority;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $u = freshUser($db);
    $d = domains($db);
    $home = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$u]);
    $two = (int) $d->calendars->create($u, ['name' => 'Sample two'])['id'];
    $folder = (int) $d->folders->create($u, ['name' => 'Sample folder'])['id'];
    $d->folders->place($u, $folder, $home, 0);
    $event = (int) $d->events->create($u, eventAt($home, 'Sample supper', '2026-12-16') + ['location' => 'Sample Hall'])['eventId'];
    $state = static fn(): array => [
        $db->all('SELECT id, position FROM calendars WHERE user_id = ? ORDER BY id', [$u]),
        $db->all('SELECT calendar_id, folder_id, position FROM calendar_folders WHERE folder_id = ? ORDER BY calendar_id', [$folder]),
        $db->one('SELECT location_lat, location_lng, location_source FROM events WHERE id = ?', [$event]),
        (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$u]),
    ];
    $routes = [
        'POST /calendars/:id/position' => static fn() => $d->calendars->placeInList($u, $two, 0),
        'POST /folders/:id/calendars/:calendarId' => static fn() => $d->folders->place($u, $folder, $two, 0),
        'DELETE /folders/:id/calendars/:calendarId' => static fn() => $d->folders->unfile($u, $folder, $home),
        'POST /events/:id/place' => static fn() => $d->events->placeFromLookup($u, $event, 'Sample Hall', 45.5, -122.6, 'photon'),
    ];
    foreach ($routes as $route => $call) {
        foreach (requestCredentials($db, $u) as $label => [$authority, $revoke, $code]) {
            $revoke();
            $before = $state();
            checkEq("revocation ($label): $route revoked after signing in, before its write, is refused", $code,
                refusal(fn() => RequestAuthority::during($authority, $call)));
            checkEq("revocation ($label): $route commits nothing", $before, $state());
        }
    }
    foreach ($routes as $route => $call) {
        [$authority] = requestCredentials($db, $u)['API key'];
        $before = $state();
        checkEq("revocation: $route with the credential still valid goes through", null, refusal(fn() => RequestAuthority::during($authority, $call)));
        check("revocation: $route changes what it says", $state() !== $before);
    }

    // A change the journal doesn't keep goes through the other boundary
    // (RequestAuthority::checkedTx), with the same check: a thumbs signal.
    $feed = (int) $d->calendars->create($u, ['name' => 'Sample feed'])['id'];
    $db->run("UPDATE calendars SET kind = 'subscribed' WHERE id = ?", [$feed]);
    $fed = (int) $d->events->create($u, eventAt($home, 'Sample talk', '2026-12-18'))['eventId'];
    $db->run("UPDATE events SET calendar_id = ?, source = 'feed' WHERE id = ?", [$feed, $fed]);
    $signals = static fn(): int => (int) $db->scalar('SELECT COUNT(*) FROM feedback_signals WHERE event_id = ?', [$fed]);
    foreach (requestCredentials($db, $u) as $label => [$authority, $revoke, $code]) {
        $revoke();
        checkEq("revocation ($label): POST /events/:id/feedback revoked before its write is refused, nothing written", [$code, 0],
            [refusal(fn() => RequestAuthority::during($authority, fn() => $d->events->recordFeedback($u, $fed, 'up'))), $signals()]);
    }
    [$authority] = requestCredentials($db, $u)['session'];
    RequestAuthority::during($authority, fn() => $d->events->recordFeedback($u, $fed, 'up'));
    checkEq('revocation: with the credential valid, the signal is written', 1, $signals());

    // A change a request (not a read) makes moves the account's change
    // marker in the transaction that checks it, once; a read's does not.
    $marker = static fn(): int => (int) $db->scalar('SELECT change_seq FROM users WHERE id = ?', [$u]);
    [$authority] = requestCredentials($db, $u)['API key'];
    $was = $marker();
    RequestAuthority::during($authority, fn() => $d->folders->place($u, $folder, $two, 1), true);
    checkEq('marker: a request\'s change moves the change marker once', $was + 1, $marker());
    $was = $marker();
    RequestAuthority::during($authority, fn() => $d->folders->place($u, $folder, $two, 0), false);
    checkEq('marker: a read\'s write does not', $was, $marker());

    // A flow that ends its own credential checks it, then ends it, in one
    // transaction, and writes nothing after: signing out, and a key
    // revoking itself.
    $auth = new BetterCal\Domain\Auth($db, ['session_secret' => str_repeat('t', 32)]);
    $session = 'sample-signout-' . bin2hex(random_bytes(6));
    $db->run('INSERT INTO sessions (token_hash, user_id, csrf, expires_at) VALUES (?, ?, ?, ?)', [hash('sha256', $session), $u, str_repeat('c', 32), '2099-01-01 00:00:00']);
    $was = $marker();
    RequestAuthority::during(RequestAuthority::credential($u, null, $session), fn() => $auth->logout($session), true);
    checkEq('self-revoking: sign-out ends its session, the marker moved in the same transaction', [0, $was + 1],
        [(int) $db->scalar('SELECT COUNT(*) FROM sessions WHERE token_hash = ?', [hash('sha256', $session)]), $marker()]);
    checkEq('self-revoking: signing out a session already ended is still a quiet success', 0,
        RequestAuthority::during(RequestAuthority::credential($u, null, $session), fn() => $auth->logout($session), true));
    $tokens = new BetterCal\Domain\ApiTokens($db);
    [$keyAuthority] = requestCredentials($db, $u)['API key'];
    $keyId = (int) $keyAuthority->tokenId();
    check('self-revoking: a key revokes itself', RequestAuthority::during($keyAuthority, fn() => $tokens->revoke($u, $keyId), true));
    checkEq('self-revoking: and is gone', 0, (int) $db->scalar('SELECT COUNT(*) FROM api_tokens WHERE id = ?', [$keyId]));

    // A plugin job run after the response runs under the request's
    // credential, and its work is checked at its writes; the queue's state
    // (claimed, done, failed) is the server's own record. A credential
    // revoked after the claim fails the job once, for good: never left
    // running, never rerun by the worker with no credential.
    $dir = sys_get_temp_dir() . '/bc-revoked-plugin-' . bin2hex(random_bytes(4));
    $root = sys_get_temp_dir() . '/bc-revoked-root-' . bin2hex(random_bytes(4));
    mkdir($dir . '/sample-sync', 0700, true);
    mkdir($root . '/server', 0700, true);
    symlink(realpath(__DIR__ . '/../../migrations'), $root . '/server/migrations');
    try {
        file_put_contents($dir . '/sample-sync/plugin.json', json_encode(['id' => 'sample-sync', 'name' => 'Sample sync', 'version' => '1.0.0', 'jobs' => [['id' => 'sync', 'interval' => 'PT1H']]]));
        file_put_contents($dir . '/sample-sync/Plugin.php', '<?php
return new class implements BetterCal\\Plugin\\PluginInterface {
    public function runJob(BetterCal\\Plugin\\PluginHost $host, string $jobId): void { ($GLOBALS["sampleSyncDuring"] ?? static function (): void {})(); }
    public function validateSettings(array $values): array { return []; }
};');
        $db->run("INSERT INTO plugins (id, version, enabled, settings_json) VALUES ('sample-sync', '1.0.0', 1, '{}')");
        $cfg = ['db' => ['dsn' => (string) getenv('BC_TEST_DSN'), 'user' => (string) getenv('BC_TEST_DB_USER'), 'pass' => (string) getenv('BC_TEST_DB_PASS')],
            'app_root' => $root, 'session_secret' => str_repeat('s', 32)];
        $plugins = new BetterCal\Domain\Plugins($db, $dir, $cfg);
        $queue = new BetterCal\Infra\JobQueue($db);
        $job = static fn(int $id): array => $db->one('SELECT status, attempts FROM jobs WHERE id = ?', [$id]);

        foreach (requestCredentials($db, $u) as $label => [$authority, $revoke]) {
            $id = $queue->enqueue('plugin_job', ['userId' => $u, 'plugin' => 'sample-sync', 'job' => 'sync']);
            $GLOBALS['sampleSyncDuring'] = $revoke;
            try {
                $ran = refusal(fn() => RequestAuthority::during($authority, fn() => $plugins->runQueuedNow([$id]), false));
            } finally {
                unset($GLOBALS['sampleSyncDuring']);
            }
            checkEq("revocation ($label): a plugin job whose credential is revoked mid-run ends failed, once, nothing left running or queued", [null, ['status' => 'failed', 'attempts' => 1]],
                [$ran, $job($id)]);
        }
        [$authority] = requestCredentials($db, $u)['session'];
        $id = $queue->enqueue('plugin_job', ['userId' => $u, 'plugin' => 'sample-sync', 'job' => 'sync']);
        checkEq('revocation: with the credential valid, the job runs and is done', [1, ['status' => 'done', 'attempts' => 1]],
            [RequestAuthority::during($authority, fn() => $plugins->runQueuedNow([$id]), false), $job($id)]);
    } finally {
        $db->run("DELETE FROM plugins WHERE id = 'sample-sync'");
        array_map('unlink', glob($dir . '/sample-sync/*') ?: []);
        @rmdir($dir . '/sample-sync');
        @rmdir($dir);
        @unlink($root . '/server/migrations');
        @rmdir($root . '/server');
        array_map('unlink', glob($root . '/.runtime-cache/*') ?: []);
        @rmdir($root . '/.runtime-cache');
        @rmdir($root);
    }
};
