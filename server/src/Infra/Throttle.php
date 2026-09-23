<?php

declare(strict_types=1);

namespace BetterCal\Infra;

use BetterCal\Support\Time;

/**
 * Sliding-window counters in the database (rate_events): count what happened
 * in a bucket over the last N seconds, record one more, forget old ones.
 *
 * Deliberately dumb. Policy (what is counted, how many is too many, who is
 * exempt) lives with the caller, e.g. Domain\LoginGuard; this only counts.
 *
 * A failure to count must never become a failure to sign in: every method
 * swallows database errors and answers as if nothing had been recorded. That
 * also covers the rolling deploy where the code arrives before migration 023.
 */
final class Throttle
{
    /** Rows older than this are of no use to any caller (the longest window is the 30-day "known source" memory). */
    private const RETENTION_SECONDS = 30 * 86400;

    public function __construct(private readonly Db $db)
    {
    }

    /** How many events the bucket has had in the last $windowSeconds. */
    public function count(string $bucket, int $windowSeconds, ?\DateTimeImmutable $now = null): int
    {
        try {
            return (int) $this->db->scalar(
                'SELECT COUNT(*) FROM rate_events WHERE bucket = ? AND created_at > ?',
                [self::key($bucket), Time::toDb(($now ?? Time::nowUtc())->sub(new \DateInterval('PT' . max(1, $windowSeconds) . 'S')))]
            );
        } catch (\Throwable $e) {
            error_log('throttle count failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Seconds until the bucket drops below $limit again: when its $limit-th
     * most recent event leaves the window. 0 when it is not over the limit.
     */
    public function retryAfter(string $bucket, int $limit, int $windowSeconds, ?\DateTimeImmutable $now = null): int
    {
        $now ??= Time::nowUtc();
        try {
            $rows = $this->db->all(
                'SELECT created_at FROM rate_events WHERE bucket = ? AND created_at > ? ORDER BY created_at DESC LIMIT ' . max(1, $limit),
                [self::key($bucket), Time::toDb($now->sub(new \DateInterval('PT' . max(1, $windowSeconds) . 'S')))]
            );
        } catch (\Throwable $e) {
            error_log('throttle read failed: ' . $e->getMessage());
            return 0;
        }
        if (count($rows) < $limit) {
            return 0;
        }
        $oldestCounted = Time::fromDb((string) $rows[count($rows) - 1]['created_at']);
        return max(1, $oldestCounted->getTimestamp() + $windowSeconds - $now->getTimestamp());
    }

    public function hit(string $bucket, ?\DateTimeImmutable $now = null): void
    {
        try {
            $this->db->insert('rate_events', ['bucket' => self::key($bucket), 'created_at' => Time::toDb($now ?? Time::nowUtc())]);
            // Cheap, occasional housekeeping instead of a job of its own.
            if (random_int(1, 50) === 1) {
                $this->prune($now);
            }
        } catch (\Throwable $e) {
            error_log('throttle hit failed: ' . $e->getMessage());
        }
    }

    /** Like hit(), returning the row id so the caller can release it again (0 when the store failed). */
    public function reserve(string $bucket, ?\DateTimeImmutable $now = null): int
    {
        try {
            return (int) $this->db->insert('rate_events', ['bucket' => self::key($bucket), 'created_at' => Time::toDb($now ?? Time::nowUtc())]);
        } catch (\Throwable $e) {
            error_log('throttle reserve failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** @param list<int> $ids rows from reserve() to take back */
    public function release(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
        if ($ids === []) {
            return;
        }
        try {
            $this->db->run('DELETE FROM rate_events WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        } catch (\Throwable $e) {
            error_log('throttle release failed: ' . $e->getMessage());
        }
    }

    /** How many different buckets starting with $prefix have had an event in the window. */
    public function distinct(string $prefix, int $windowSeconds, ?\DateTimeImmutable $now = null): int
    {
        try {
            return (int) $this->db->scalar(
                'SELECT COUNT(DISTINCT bucket) FROM rate_events WHERE bucket LIKE ? AND created_at > ?',
                [str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%', Time::toDb(($now ?? Time::nowUtc())->sub(new \DateInterval('PT' . max(1, $windowSeconds) . 'S')))]
            );
        } catch (\Throwable $e) {
            error_log('throttle distinct failed: ' . $e->getMessage());
            return 0;
        }
    }

    public function clear(string $bucket): void
    {
        try {
            $this->db->run('DELETE FROM rate_events WHERE bucket = ?', [self::key($bucket)]);
        } catch (\Throwable $e) {
            error_log('throttle clear failed: ' . $e->getMessage());
        }
    }

    public function prune(?\DateTimeImmutable $now = null): void
    {
        try {
            $this->db->run(
                'DELETE FROM rate_events WHERE created_at < ?',
                [Time::toDb(($now ?? Time::nowUtc())->sub(new \DateInterval('PT' . self::RETENTION_SECONDS . 'S')))]
            );
        } catch (\Throwable $e) {
            error_log('throttle prune failed: ' . $e->getMessage());
        }
    }

    /** Buckets carry caller-supplied text (an IP); keep them inside the column. */
    private static function key(string $bucket): string
    {
        return strlen($bucket) <= 191 ? $bucket : substr($bucket, 0, 120) . ':' . hash('sha256', $bucket); // 120 + 1 + 64
    }
}
