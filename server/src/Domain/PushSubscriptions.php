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
    public function subscribe(int $userId, array $in): void
    {
        $sub = self::validate($in, $this->extraPushHosts);
        $existed = $this->db->scalar('SELECT id FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?', [self::endpointHash($sub['endpoint']), $userId]) !== null;
        $this->db->run(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, last_used_at)
             VALUES (?, ?, ?, ?, ?, NULL) AS new_row
             ON DUPLICATE KEY UPDATE
               user_id = new_row.user_id, endpoint = new_row.endpoint,
               p256dh = new_row.p256dh, auth = new_row.auth, failing_since = NULL',
            [$userId, $sub['endpoint'], self::endpointHash($sub['endpoint']), $sub['p256dh'], $sub['auth']]
        );
        if (!$existed) {
            $id = (int) $this->db->scalar('SELECT id FROM push_subscriptions WHERE endpoint_hash = ?', [self::endpointHash($sub['endpoint'])]);
            (new Undo($this->db))->record($userId, 'push', $id, 'create', null, null,
                'Registered a device for reminders (' . self::service($sub['endpoint']) . ')');
        }
    }

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
        foreach ($this->db->all('SELECT id, endpoint, endpoint_hash, created_at, last_used_at, failing_since FROM push_subscriptions WHERE user_id = ? ORDER BY id', [$userId]) as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'service' => self::service((string) $r['endpoint']),
                'endpointHash' => (string) $r['endpoint_hash'],
                'createdAt' => $r['created_at'] !== null ? Time::iso(Time::fromDb((string) $r['created_at'])) : null,
                'lastUsedAt' => $r['last_used_at'] !== null ? Time::iso(Time::fromDb((string) $r['last_used_at'])) : null,
                'failing' => $r['failing_since'] !== null,
            ];
        }
        return $out;
    }

    /** Remove one device by id (the owner reviewing the list); written to Activity. */
    public function remove(int $userId, int $id): void
    {
        $row = $this->db->one('SELECT endpoint FROM push_subscriptions WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw HttpError::notFound('No such device');
        }
        $this->db->run('DELETE FROM push_subscriptions WHERE id = ?', [$id]);
        (new Undo($this->db))->record($userId, 'push', $id, 'delete', null, null,
            'Removed a device from reminders (' . self::service((string) $row['endpoint']) . ')');
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
        return $this->db->all('SELECT * FROM push_subscriptions WHERE user_id = ? ORDER BY id', [$userId]);
    }

    /** @return array<int, list<array>> all subscriptions grouped by user id */
    public function allByUser(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT * FROM push_subscriptions ORDER BY user_id, id') as $row) {
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
