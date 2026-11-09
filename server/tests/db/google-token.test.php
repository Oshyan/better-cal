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
use BetterCal\Domain\GoogleSync;
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

    // The 401 retry, end to end through the app's own path: Google (a
    // stand-in) answers each request from a script of statuses, the token
    // endpoint mints a numbered token or refuses the grant, and every
    // request records the bearer token it carried.
    $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
    $stand = static function () use ($db, $cfg): object {
        $u = freshUser($db);
        $t = new class {
            /** @var list<int> statuses Google answers, in order; 200 once they run out */
            public array $answers = [];
            /** @var list<string> what happened, in order: 'token', 'refused grant', or "METHOD bearer" */
            public array $calls = [];
            public int $minted = 0;
            public bool $revoked = false;
            /** @var ?\Closure run when Google answers 401 */
            public ?\Closure $on401 = null;
            public int $u = 0;
            public int $account = 0;
            public int $calendarId = 0;
            public int $meeting = 0;
            public ?\Closure $auth = null;
            public ?\Closure $answer = null;
            /** @var ?array the event as Google holds it, which a PATCH answers whole */
            public ?array $resource = null;
        };
        $t->u = $u;
        $t->auth = static fn(): GoogleAuth => new GoogleAuth($db, $cfg, static function (string $url, array $fields, ?float $deadline) use ($t): array {
            if ($t->revoked) {
                $t->calls[] = 'refused grant';
                return ['status' => 400, 'body' => json_encode(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'])];
            }
            $t->calls[] = 'token';
            return ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access-' . ++$t->minted, 'expires_in' => 3600])];
        });
        $t->answer = static function (string $method, array $headers, array $ok) use ($t): array {
            $bearer = '';
            foreach ($headers as $h) {
                if (str_starts_with($h, 'Authorization: Bearer ')) {
                    $bearer = substr($h, strlen('Authorization: Bearer '));
                }
            }
            $t->calls[] = "$method $bearer";
            $status = array_shift($t->answers) ?? 200;
            if ($status === 401) {
                if ($t->on401 !== null) {
                    ($t->on401)();
                }
                return ['status' => 401, 'body' => json_encode(['error' => ['code' => 401, 'message' => 'Invalid Credentials']])];
            }
            return ['status' => $status, 'body' => json_encode($ok)];
        };
        $t->account = (int) $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $t->calendarId = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Google sample', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $t->account, 'google_calendar_id' => 'sample@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $now = Time::nowDb();
        $t->meeting = (int) $db->insert('events', ['user_id' => $u, 'calendar_id' => $t->calendarId, 'uid' => 'sample-meeting@google', 'title' => 'Sample meeting',
            'start_utc' => '2027-01-11 18:00:00', 'end_utc' => '2027-01-11 19:00:00', 'all_day' => 0, 'tzid' => 'UTC', 'source' => 'feed',
            'google_event_id' => 'g-sample', 'created_at' => $now, 'updated_at' => $now]);
        return $t;
    };
    // One request's Events, writing through its own GoogleAuth and GoogleWriter.
    $events = static function (object $t) use ($db): Events {
        $d = domains($db);
        // Google answers a PATCH with the whole event as it now is (a body carries only what changes).
        $t->resource ??= ['id' => 'g-sample', 'iCalUID' => 'sample-meeting@google', 'status' => 'confirmed', 'etag' => '"e1"', 'summary' => 'Sample meeting',
            'start' => ['dateTime' => '2027-01-11T18:00:00+00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2027-01-11T19:00:00+00:00', 'timeZone' => 'UTC']];
        $transport = static function (string $method, string $url, ?array $payload, array $headers = []) use ($t): array {
            $t->resource = array_merge($t->resource, $payload ?? []);
            return ($t->answer)($method, $headers, $t->resource);
        };
        return new Events($db, new BetterCal\Domain\Recurrence(), $d->undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $d->undo, new BetterCal\Infra\JobQueue($db)), new BetterCal\Domain\Trips($db, $d->undo), null,
            new GoogleWriter($db, ($t->auth)(), new Feeds($db), $transport));
    };
    $sync = static fn(object $t): GoogleSync => new GoogleSync($db, ($t->auth)(), new Feeds($db), static fn(string $url, array $headers): array
        => ($t->answer)('LIST', $headers, ['kind' => 'calendar#events', 'nextSyncToken' => 'sample-sync-token', 'items' => [
            // The calendar's one event, as the rows hold it: a whole read listing nothing is not applied (an empty listing never deletes).
            ['id' => 'g-sample', 'iCalUID' => 'sample-meeting@google', 'status' => 'confirmed', 'summary' => 'Sample meeting', 'etag' => '"e1"',
                'start' => ['dateTime' => '2027-01-11T18:00:00Z', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2027-01-11T19:00:00Z', 'timeZone' => 'UTC']],
        ]]));
    $calendarOf = static fn(object $t): array => $db->one('SELECT * FROM calendars WHERE id = ?', [$t->calendarId]);
    $title = static fn(object $t): string => (string) $db->scalar('SELECT title FROM events WHERE id = ?', [$t->meeting]);
    $sealed = static fn(object $t): ?string => $db->scalar('SELECT access_token_enc FROM google_accounts WHERE id = ?', [$t->account]);
    $reauth = static fn(object $t): bool => GoogleAuth::serializeAccount($db->one('SELECT * FROM google_accounts WHERE id = ?', [$t->account]))['reauthRequired'];
    // The token a later request is handed from the row, by a GoogleAuth whose token endpoint fails if it is asked.
    $heldToken = static function (object $t) use ($db, $cfg): string {
        $auth = new GoogleAuth($db, $cfg, static fn(): array => ['status' => 500, 'body' => '{"error":"not expected"}']);
        return $auth->accessToken($db->one('SELECT * FROM google_accounts WHERE id = ?', [$t->account]));
    };
    $pollError = static function (object $t) use ($sync, $calendarOf): ?string {
        try {
            $sync($t)->poll($calendarOf($t));
            return null;
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
    };

    scenario('google token: a write Google answers 401 refreshes once and is sent again with the new token, which replaces the stored one', function () use ($stand, $events, $title, $sealed, $heldToken): void {
        $t = $stand();
        $events($t)->patch($t->u, $t->meeting, ['title' => 'Sample meeting, one']);
        $before = $sealed($t);
        $t->calls = [];
        $t->answers = [401, 200];
        $events($t)->patch($t->u, $t->meeting, ['title' => 'Sample meeting, two']);
        checkEq('a write: 401, one refresh, the same write again with the fresh token', ['PATCH sample-access-1', 'token', 'PATCH sample-access-2'], $t->calls);
        checkEq('a write: the retried write landed', 'Sample meeting, two', $title($t));
        check('a write: the stored token row was replaced', $before !== null && $sealed($t) !== null && $sealed($t) !== $before);
        checkEq('a write: the next request is handed the new token from the row, with no refresh', 'sample-access-2', $heldToken($t));
    });

    scenario('google token: a second 401 on the retried write is reported, not retried again', function () use ($stand, $events, $title): void {
        $t = $stand();
        $t->answers = [401, 401];
        $code = refusal(fn() => $events($t)->patch($t->u, $t->meeting, ['title' => 'Sample meeting, refused']));
        checkEq('a second 401: one refresh, one retry, then the refusal', ['token', 'PATCH sample-access-1', 'token', 'PATCH sample-access-2'], $t->calls);
        checkEq('a second 401: the write fails as Google rejecting it', 'google_write_failed', $code);
        checkEq('a second 401: nothing changed here', 'Sample meeting', $title($t));
    });

    scenario('google token: a revoked grant on the refresh after a 401 marks the account for reconnecting and the write fails cleanly', function () use ($stand, $events, $title, $sealed, $reauth): void {
        $t = $stand();
        $t->answers = [401];
        $t->on401 = static function () use ($t): void {
            $t->revoked = true;
        };
        $code = refusal(fn() => $events($t)->patch($t->u, $t->meeting, ['title' => 'Sample meeting, revoked']));
        checkEq('a revoked grant: the refresh is refused and nothing is sent again', ['token', 'PATCH sample-access-1', 'refused grant'], $t->calls);
        checkEq('a revoked grant: the write fails with the reconnect refusal, not a network error', 'google_reconnect_needed', $code);
        checkEq('a revoked grant: the account is marked reauthRequired, its token cleared', [true, null], [$reauth($t), $sealed($t)]);
        checkEq('a revoked grant: nothing changed here', 'Sample meeting', $title($t));
        $t->calls = [];
        checkEq('a revoked grant: the next write sends Google nothing', ['google_reconnect_needed', []], [refusal(fn() => $events($t)->patch($t->u, $t->meeting, ['title' => 'Sample meeting, later'])), $t->calls]);
    });

    scenario('google token: a poll Google answers 401 refreshes once and lists again with the new token; a second 401 is reported', function () use ($db, $stand, $sync, $calendarOf, $heldToken, $pollError): void {
        $t = $stand();
        $t->answers = [401, 200];
        $sync($t)->poll($calendarOf($t));
        checkEq('a poll: 401, one refresh, the same listing again with the fresh token', ['token', 'LIST sample-access-1', 'token', 'LIST sample-access-2'], $t->calls);
        checkEq('a poll: it finished and kept the listing\'s sync token', 'sample-sync-token', $db->scalar('SELECT google_sync_token FROM calendars WHERE id = ?', [$t->calendarId]));
        checkEq('a poll: the new token is stored', 'sample-access-2', $heldToken($t));

        $t->calls = [];
        $t->answers = [401, 401];
        $error = $pollError($t);
        checkEq('a poll, a second 401: one refresh, one retry, no third listing', ['LIST sample-access-2', 'token', 'LIST sample-access-3'], $t->calls);
        check('a poll, a second 401: it fails with Google\'s 401', $error !== null && str_contains($error, 'HTTP 401'), (string) $error);
    });

    scenario('google token: a poll\'s 401 that comes past its deadline asks for no token and sends nothing again', function () use ($db, $stand, $pollError): void {
        $t = $stand();
        BetterCal\Support\Limits::configure(['GOOGLE_SYNC_SECONDS' => 1]);
        try {
            $t->answers = [401];
            // The 401 comes back once the poll's one second is spent: the
            // poll set its deadline before this request was made.
            $t->on401 = static function (): void {
                $past = microtime(true) + 1.0;
                while (microtime(true) < $past) {
                    usleep(20_000);
                }
            };
            $error = $pollError($t);
        } finally {
            BetterCal\Support\Limits::reset();
        }
        checkEq('past the deadline: no refresh and no second listing', ['token', 'LIST sample-access-1'], $t->calls);
        check('past the deadline: the poll fails on its deadline', $error !== null && str_contains($error, 'elapsed-time safety limit'), (string) $error);
        checkEq('past the deadline: the sync position is kept', null, $db->scalar('SELECT google_sync_token FROM calendars WHERE id = ?', [$t->calendarId]));
    });

    scenario('google token: a revoked grant on a poll\'s refresh after a 401 marks the account for reconnecting', function () use ($stand, $sync, $calendarOf, $reauth): void {
        $t = $stand();
        $t->answers = [401];
        $t->on401 = static function () use ($t): void {
            $t->revoked = true;
        };
        $code = refusal(fn() => $sync($t)->poll($calendarOf($t)));
        checkEq('a poll, a revoked grant: the refresh is refused and nothing is listed again', ['token', 'LIST sample-access-1', 'refused grant'], $t->calls);
        checkEq('a poll, a revoked grant: it fails with the reconnect refusal', 'google_reconnect_needed', $code);
        checkEq('a poll, a revoked grant: the account is marked reauthRequired', true, $reauth($t));
    });
};
