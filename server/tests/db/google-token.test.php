<?php

declare(strict_types=1);

// The Google access token is stored on its account row (migration 070),
// sealed, so separate requests and worker ticks share one refresh. Here each
// "process" is its own GoogleAuth and GoogleWriter, as a browser request
// builds them; Google is a stand-in that counts every call, the token
// refresh among them.

use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Infra\Db;
use BetterCal\Infra\Secrets;
use BetterCal\Support\Time;

return static function (Db $db): void {
    scenario('google token: two requests share one stored token; a 401 clears it; a quarantined account sends nothing',function () use ($db): void {
        $u = freshUser($db);
        $d = domains($db);
        $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
        $calls = [];
        $request = static function () use ($db, $d, $cfg, &$calls): Events {
            $auth = new GoogleAuth($db, $cfg, static function (string $url, array $fields, ?float $deadline) use (&$calls): array {
                $calls[] = 'token';
                return ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access-' . count($calls), 'expires_in' => 3600])];
            });
            $transport = static function (string $method, string $url, ?array $payload) use (&$calls): array {
                $calls[] = $method;
                return ['status' => 200, 'body' => json_encode(($payload ?? []) + ['id' => 'g-sample', 'iCalUID' => 'sample-meeting@google', 'status' => 'confirmed'])];
            };
            return new Events($db, new BetterCal\Domain\Recurrence(), $d->undo, new BetterCal\Domain\Labels($db),
                new BetterCal\Domain\Filters($db, $d->undo, new BetterCal\Infra\JobQueue($db)), new BetterCal\Domain\Trips($db, $d->undo), null,
                new GoogleWriter($db, $auth, new Feeds($db), $transport));
        };
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $googleCal = $db->insert('calendars', ['user_id' => $u, 'name' => 'Google sample', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'sample@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $now = Time::nowDb();
        $meeting = $db->insert('events', ['user_id' => $u, 'calendar_id' => $googleCal, 'uid' => 'sample-meeting@google', 'title' => 'Sample meeting',
            'start_utc' => '2027-01-11 18:00:00', 'end_utc' => '2027-01-11 19:00:00', 'all_day' => 0, 'tzid' => 'UTC', 'source' => 'feed',
            'google_event_id' => 'g-sample', 'created_at' => $now, 'updated_at' => $now]);

        $request()->patch($u, $meeting, ['title' => 'Sample meeting, one']);
        $request()->patch($u, $meeting, ['title' => 'Sample meeting, two']);
        $row = $db->one('SELECT access_token_enc, access_token_expires_at FROM google_accounts WHERE id = ?', [$account]);
        checkEq('two requests, each with its own GoogleAuth: one refresh, then two writes', ['token', 'PATCH', 'PATCH'], $calls);
        check('the token is stored sealed with its expiry, never in the clear',
            Secrets::isSealed((string) $row['access_token_enc']) && !str_contains((string) $row['access_token_enc'], 'sample-access')
            && Time::fromDb((string) $row['access_token_expires_at'])->getTimestamp() > time() + 3000);

        $auth = new GoogleAuth($db, $cfg);
        $auth->tokenRejected($db->one('SELECT * FROM google_accounts WHERE id = ?', [$account]), 401);
        checkEq('a 401 clears the stored token', [null, null],
            array_values($db->one('SELECT access_token_enc, access_token_expires_at FROM google_accounts WHERE id = ?', [$account])));
        $calls = [];
        $request()->patch($u, $meeting, ['title' => 'Sample meeting, three']);
        checkEq('the next request refreshes once and stores again', [['token', 'PATCH'], true],
            [$calls, $db->scalar('SELECT access_token_enc FROM google_accounts WHERE id = ?', [$account]) !== null]);

        $db->run(
            "UPDATE google_accounts SET reauth_required_at = ?, status = 'error', last_error = 'paused', access_token_enc = NULL, access_token_expires_at = NULL WHERE id = ?",
            [Time::nowDb(), $account]
        );
        $calls = [];
        $code = refusal(fn() => $request()->patch($u, $meeting, ['title' => 'Sample meeting, four']));
        checkEq('a quarantined account sends Google nothing', [[], true], [$calls, $code !== null]);
    });
};
