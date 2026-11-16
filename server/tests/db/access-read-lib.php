<?php

declare(strict_types=1);

// Helpers for the access-read-* files (an independent read of the access build, d32b65b1..61be6c69). Nothing here
// computes an expectation from server/src: PA::state reads the tables and the stand-in's own store; PA::make builds a
// stand whose requests pass through $hook first, so a probe can change the level Google gives at a chosen moment of a
// change (between its read and its write, after its first write). Invented data.

use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Trips;
use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';
require_once dirname(__DIR__, 3) . '/tools/google-probe/lib/FakeGoogle.php';

final class PA
{
    public const NAME = 'Sample crew';
    public const ACCOUNT = 'owner@example.test';

    /**
     * A stand-in, an account, a Google calendar and the product's Events for a new person. $s->hook, when set, is called
     * before each request the writer sends (its number among the writer's requests, the method, the url).
     */
    public static function make(Db $db, string $role = 'owner'): object
    {
        $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $fake = new FakeGoogle(self::ACCOUNT);
        $fake->token = null;
        $fake->zone = 'America/Los_Angeles';
        $fake->owner = self::ACCOUNT;
        $fake->role = $role;
        $fake->expand = static fn(array $master): array => \BetterCal\EditHarness\GoogleStand::expand($master);
        $s = (object) ['db' => $db, 'u' => $u, 'd' => $d, 'fake' => $fake, 'hook' => null, 'n' => 0];
        $inner = $fake->writerTransport();
        $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
            => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
        $feeds = new Feeds($db);
        $s->writer = new GoogleWriter($db, $auth, $feeds, static function (string $method, string $url, ?array $payload, array $headers = []) use ($inner, $s): array {
            $s->n++;
            if ($s->hook !== null) {
                ($s->hook)($s->n, $method, $url);
            }
            return $inner($method, $url, $payload, array_merge(['Authorization: Bearer sample-access'], $headers));
        });
        $syncTransport = $fake->syncTransport();
        // $s->syncHook, when set, is called as each of a poll's requests goes out.
        $s->syncHook = null;
        $sync = new GoogleSync($db, $auth, $feeds, static function (string $url, array $headers) use ($syncTransport, $s): array {
            if ($s->syncHook !== null) {
                ($s->syncHook)($url);
            }
            return $syncTransport($url, array_merge(['Authorization: Bearer sample-access'], $headers));
        });
        $s->events = new Events($db, new Recurrence(), $d->undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $d->undo, new BetterCal\Infra\JobQueue($db)), new Trips($db, $d->undo), null, $s->writer);
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => self::ACCOUNT,
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $s->accountId = (int) $account;
        $s->calendarId = (int) $db->insert('calendars', ['user_id' => $u, 'name' => self::NAME, 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => self::ACCOUNT, 'google_access_role' => $role, 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $s->poll = static function () use ($db, $sync, $s): ?string {
            try {
                $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
                return null;
            } catch (\Throwable $e) {
                return $e->getMessage();
            }
        };
        return $s;
    }

    /** What is held here for a calendar and what the stand-in holds: compared before and after an attempt. */
    public static function state(Db $db, int $userId, int $calendarId, FakeGoogle $fake): array
    {
        return [
            'rows' => $db->all('SELECT * FROM events WHERE calendar_id = ? ORDER BY id', [$calendarId]),
            'tags' => $db->all('SELECT et.event_id, et.tag_id FROM event_tags et JOIN events e ON e.id = et.event_id WHERE e.calendar_id = ? ORDER BY 1, 2', [$calendarId]),
            'halfDone' => (int) $db->scalar('SELECT COUNT(*) FROM google_pending WHERE calendar_id = ?', [$calendarId]),
            'entries' => $db->all("SELECT id, undone FROM mutations WHERE user_id = ? AND entity <> 'calendar' ORDER BY id", [$userId]),
            'google' => $fake->events,
        ];
    }

    /** The requests the stand-in received since $from, as "METHOD list|small|id|instances" ('small': the one-item list). */
    public static function sent(FakeGoogle $fake, int $from): array
    {
        return array_map(static function (array $r): string {
            $path = (string) $r['path'];
            $what = str_ends_with($path, '/instances') ? 'instances' : (!str_ends_with($path, '/events') ? 'id' : (($r['query']['maxResults'] ?? null) === '1' && !isset($r['query']['iCalUID']) ? 'small' : 'list'));
            return $r['method'] . ' ' . $what;
        }, array_slice($fake->requests, $from));
    }

    /** The writes among them the stand-in took (answered below 400). */
    public static function taken(FakeGoogle $fake, int $from): array
    {
        $out = [];
        foreach (array_slice($fake->requests, $from) as $r) {
            if ($r['method'] !== 'GET' && (int) ($r['status'] ?? 500) < 400) {
                $out[] = $r['method'] . ' ' . rawurldecode(basename((string) $r['path']));
            }
        }
        return $out;
    }

    public static function caught(callable $fn): ?\Throwable
    {
        try {
            $fn();
            return null;
        } catch (\Throwable $e) {
            return $e;
        }
    }

    public static function code(?\Throwable $e): ?string
    {
        return $e === null ? null : ($e instanceof HttpError ? $e->errorCode : get_class($e));
    }

    public static function storedRole(Db $db, int $calendarId): ?string
    {
        $role = $db->scalar('SELECT google_access_role FROM calendars WHERE id = ?', [$calendarId]);
        return $role === null ? null : (string) $role;
    }

    /** The calendar's access items in Review, by status: kinds in id order. */
    public static function items(Db $db, int $userId, int $calendarId, string $status = 'open'): array
    {
        return array_column($db->all('SELECT kind FROM review_items WHERE user_id = ? AND source_key = ? AND status = ? ORDER BY id',
            [$userId, 'google-access:' . $calendarId, $status]), 'kind');
    }

    /** Activity's lines about the calendar's access, in order. */
    public static function lines(Db $db, int $userId, int $calendarId): array
    {
        return array_values(array_filter(array_column($db->all("SELECT summary FROM mutations WHERE user_id = ? AND entity = 'calendar' AND entity_id = ? ORDER BY id",
            [$userId, $calendarId]), 'summary'), static fn(?string $l): bool => preg_match("/^(Your permissions for|Google Calendar no longer shows|Google Calendar doesn't show|Google Calendar shows|Google Calendar now shows|'[^']*' is view only in Google Calendar)/", (string) $l) === 1));
    }
}
