<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\PushSender;
use BetterCal\Support\Time;

/**
 * Web Push subscription storage. Endpoints are unique via a sha256 hash
 * column (endpoints exceed index length limits as TEXT). Delivery failures
 * with gone endpoints (404/410) set failing_since; subscriptions failing for
 * longer than the reminder scan's TTL are pruned by the worker.
 */
final class PushSubscriptions
{
    /** @param list<string> $extraPushHosts operator-allowed push hosts beyond PushSender::DEFAULT_PUSH_HOSTS */
    public function __construct(private readonly Db $db, private readonly array $extraPushHosts = [])
    {
    }

    public static function endpointHash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * Validate a PushSubscription JSON payload {endpoint, keys:{p256dh, auth}}.
     *
     * The endpoint is a URL this server will later POST to, so it must belong
     * to a known push service (PushSender::endpointProblem), not merely be
     * https.
     *
     * @param list<string> $extraPushHosts
     * @return array{endpoint:string, p256dh:string, auth:string}
     */
    public static function validate(array $in, array $extraPushHosts = []): array
    {
        $endpoint = trim((string) ($in['endpoint'] ?? ''));
        if ($endpoint === '' || strlen($endpoint) > 2000) {
            throw HttpError::badRequest('endpoint must be an https push endpoint URL', 'invalid_subscription');
        }
        $problem = PushSender::endpointProblem($endpoint, $extraPushHosts);
        if ($problem !== null) {
            throw HttpError::badRequest($problem, 'invalid_subscription');
        }
        $keys = $in['keys'] ?? null;
        if (!is_array($keys)) {
            throw HttpError::badRequest('keys {p256dh, auth} are required', 'invalid_subscription');
        }
        $p256dh = trim((string) ($keys['p256dh'] ?? ''));
        $auth = trim((string) ($keys['auth'] ?? ''));
        foreach (['p256dh' => $p256dh, 'auth' => $auth] as $name => $value) {
            if ($value === '' || strlen($value) > 255 || preg_match('/^[A-Za-z0-9_\-+\/=]+$/', $value) !== 1) {
                throw HttpError::badRequest("keys.$name must be a base64 key", 'invalid_subscription');
            }
        }
        return ['endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth];
    }

    /**
     * Upsert by endpoint hash; re-subscribing clears any failing state. A
     * device that is new to the account is written to Activity, so a device
     * nobody remembers adding is visible.
     */
    public function subscribe(
        int $userId,
        array $in,
        bool $resync = false,
        ?int $tokenId = null,
        ?string $sessionToken = null,
    ): void
    {
        $sub = self::validate($in, $this->extraPushHosts);
        $hash = self::endpointHash($sub['endpoint']);
        $label = self::label($in['label'] ?? null);
        $this->db->tx(function () use ($userId, $sub, $hash, $label, $resync, $tokenId, $sessionToken): void {
            if ($tokenId === null) {
                Auth::assertSession($this->db, $userId, $sessionToken, true);
            } else {
                ApiTokens::assertStillValid($this->db, $tokenId, $userId, true);
            }
            $existed = $this->db->scalar('SELECT id FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?', [$hash, $userId]) !== null;
            if ($resync) {
                // The quiet re-registration a browser does when it opens the app:
                // it restores a device a password reset cleared, and nothing else.
                // A device the owner removed stays removed, and an existing row
                // keeps its failure state so a dead device can still be pruned.
                // It does refresh what the device calls itself (0.6.7).
                if ($existed && $label !== null) {
                    $this->db->run('UPDATE push_subscriptions SET device_label = ? WHERE endpoint_hash = ? AND user_id = ?', [$label, $hash, $userId]);
                }
                if ($existed || $this->db->scalar('SELECT 1 FROM push_removed WHERE user_id = ? AND endpoint_hash = ?', [$userId, $hash]) !== null) {
                    return;
                }
            } else {
                $this->db->run('DELETE FROM push_removed WHERE user_id = ? AND endpoint_hash = ?', [$userId, $hash]);
            }
            $this->db->run(
                'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, last_used_at, created_by_token_id, device_label)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   user_id = VALUES(user_id), endpoint = VALUES(endpoint),
                   p256dh = VALUES(p256dh), auth = VALUES(auth), failing_since = NULL,
                   created_by_token_id = VALUES(created_by_token_id),
                   device_label = COALESCE(VALUES(device_label), push_subscriptions.device_label)',
                [$userId, $sub['endpoint'], $hash, $sub['p256dh'], $sub['auth'], $tokenId, $label]
            );
            if (!$existed) {
                $id = (int) $this->db->scalar('SELECT id FROM push_subscriptions WHERE endpoint_hash = ?', [$hash]);
                (new Undo($this->db))->record($userId, 'push', $id, 'create', null, null,
                    'Registered a device for reminders (' . ($label ?? self::service($sub['endpoint'])) . ')' . ($tokenId !== null ? ', with an API token' : ''));
            }
        });
    }

    /**
     * What a device calls itself ("Pixel 9 Pro · Chrome app"): the browser's
     * own words, so it is cleaned rather than trusted. Printable text only,
     * one line, at most 80 characters; null when nothing is left.
     */
    public static function label(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $s = preg_replace('/[\p{C}]+/u', ' ', $raw) ?? '';
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        $s = mb_substr($s, 0, 80);
        return $s !== '' ? $s : null;
    }

    /**
     * A device is sent to while the token that registered it is still valid
     * (revoking deletes it outright via the foreign key; expiry is checked
     * here). Devices the owner registered while signed in have no token.
     */
    private const LIVE = '(created_by_token_id IS NULL OR created_by_token_id IN (SELECT id FROM api_tokens WHERE expires_at IS NULL OR expires_at > ?))';

    /** Which push service an endpoint belongs to, in words a person recognises. */
    public static function service(string $endpoint): string
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        return match (true) {
            $host === 'fcm.googleapis.com' => 'Chrome, Edge or Android',
            str_ends_with($host, 'push.services.mozilla.com') => 'Firefox',
            str_ends_with($host, 'push.apple.com') => 'Safari or iPhone',
            str_ends_with($host, 'notify.windows.com') => 'Windows',
            default => $host !== '' ? $host : 'unknown service',
        };
    }

    /**
     * Every device registered for this account, for the owner to review and
     * remove (F5: a device registered with a stolen credential shows here).
     * The endpoint itself is never returned; its hash lets a browser find
     * itself in the list.
     *
     * @return list<array{id:int,service:string,endpointHash:string,createdAt:?string,lastUsedAt:?string,failing:bool}>
     */
    public function devices(int $userId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT id, endpoint, endpoint_hash, created_at, last_used_at, failing_since, created_by_token_id, device_label FROM push_subscriptions WHERE user_id = ? ORDER BY id', [$userId]) as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'service' => self::service((string) $r['endpoint']),
                'label' => $r['device_label'] !== null ? (string) $r['device_label'] : null,
                'endpointHash' => (string) $r['endpoint_hash'],
                'createdAt' => $r['created_at'] !== null ? Time::iso(Time::fromDb((string) $r['created_at'])) : null,
                'lastUsedAt' => $r['last_used_at'] !== null ? Time::iso(Time::fromDb((string) $r['last_used_at'])) : null,
                'failing' => $r['failing_since'] !== null,
                'viaToken' => $r['created_by_token_id'] !== null,
            ];
        }
        return $out;
    }

    /** Remove one device by id (the owner reviewing the list); written to Activity. */
    public function remove(int $userId, int $id): void
    {
        $row = $this->db->one('SELECT endpoint, device_label FROM push_subscriptions WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw HttpError::notFound('No such device');
        }
        $this->db->run('DELETE FROM push_subscriptions WHERE id = ?', [$id]);
        $hash = self::endpointHash((string) $row['endpoint']);
        if ($this->db->scalar('SELECT 1 FROM push_removed WHERE user_id = ? AND endpoint_hash = ?', [$userId, $hash]) === null) {
            $this->db->insert('push_removed', ['user_id' => $userId, 'endpoint_hash' => $hash]);
        }
        (new Undo($this->db))->record($userId, 'push', $id, 'delete', null, null,
            'Removed a device from reminders (' . ($row['device_label'] ?? self::service((string) $row['endpoint'])) . ')');
    }

    public function unsubscribe(int $userId, string $endpoint): bool
    {
        if (trim($endpoint) === '') {
            return false;
        }
        return $this->db->run(
            'DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?',
            [$userId, self::endpointHash(trim($endpoint))]
        )->rowCount() > 0;
    }

    public function hasAny(int $userId): bool
    {
        return $this->db->scalar('SELECT id FROM push_subscriptions WHERE user_id = ? LIMIT 1', [$userId]) !== null;
    }

    /** @return list<array> */
    public function forUser(int $userId): array
    {
        return $this->db->all('SELECT * FROM push_subscriptions WHERE user_id = ? AND ' . self::LIVE . ' ORDER BY id', [$userId, Time::nowDb()]);
    }

    /** @return array<int, list<array>> all subscriptions grouped by user id */
    public function allByUser(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT * FROM push_subscriptions WHERE ' . self::LIVE . ' ORDER BY user_id, id', [Time::nowDb()]) as $row) {
            $out[(int) $row['user_id']][] = $row;
        }
        return $out;
    }

    /**
     * A human name for a subscription, from the push service its endpoint
     * points at plus when it was added. There is nothing else to go on: the
     * browser never tells the server what device it is. Pure.
     */
    public static function labelFor(array $sub): string
    {
        $host = (string) (parse_url((string) ($sub['endpoint'] ?? ''), PHP_URL_HOST) ?? '');
        $kind = match (true) {
            str_contains($host, 'push.apple.com') => 'Safari / Apple device',
            str_contains($host, 'fcm.googleapis.com'), str_contains($host, 'android.googleapis.com') => 'Chrome / Android',
            str_contains($host, 'mozilla.com') => 'Firefox',
            str_contains($host, 'notify.windows.com') => 'Edge / Windows',
            default => ($host !== '' ? $host : 'unknown push service'),
        };
        $added = isset($sub['created_at']) ? substr((string) $sub['created_at'], 0, 10) : '';
        return 'Reminders to ' . $kind . ($added !== '' ? " (added $added)" : '');
    }

    public function recordSuccess(int $id): void
    {
        $this->db->run(
            'UPDATE push_subscriptions SET last_used_at = ?, failing_since = NULL WHERE id = ?',
            [Time::nowDb(), $id]
        );
    }

    /** Gone endpoint (404/410): start (or keep) the failing clock. */
    public function recordFailure(int $id): void
    {
        $this->db->run(
            'UPDATE push_subscriptions SET failing_since = COALESCE(failing_since, ?) WHERE id = ?',
            [Time::nowDb(), $id]
        );
    }

    /** Delete subscriptions that have been failing since before $before. */
    public function pruneFailing(\DateTimeImmutable $before): int
    {
        return $this->db->run(
            'DELETE FROM push_subscriptions WHERE failing_since IS NOT NULL AND failing_since < ?',
            [Time::toDb($before)]
        )->rowCount();
    }
}
