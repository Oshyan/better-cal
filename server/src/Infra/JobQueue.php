<?php

declare(strict_types=1);

namespace BetterCal\Infra;

use BetterCal\Support\Time;

final class JobQueue
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly Db $db)
    {
    }

    public function enqueue(string $type, array $payload = [], ?\DateTimeImmutable $runAfter = null): int
    {
        return $this->db->insert('jobs', [
            'type' => $type,
            'payload_json' => json_encode($payload),
            'run_after' => Time::toDb($runAfter ?? Time::nowUtc()),
            'status' => 'pending',
        ]);
    }

    /** Is any job of this type pending or running (any payload)? */
    public function hasPending(string $type): bool
    {
        return $this->db->scalar(
            "SELECT id FROM jobs WHERE type = ? AND status IN ('pending', 'running') LIMIT 1",
            [$type]
        ) !== null;
    }

    /** Is a job of this type with the given payload `hash` pending or running? */
    public function hasPendingWithHash(string $type, string $hash): bool
    {
        return $this->db->scalar(
            "SELECT id FROM jobs WHERE type = ? AND status IN ('pending', 'running')
             AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.hash')) = ? LIMIT 1",
            [$type, $hash]
        ) !== null;
    }

    public function hasActiveFeedPoll(int $calendarId): bool
    {
        return $this->db->scalar(
            "SELECT id FROM jobs WHERE type = 'feed_poll' AND status IN ('pending', 'running')
             AND JSON_EXTRACT(payload_json, '$.calendarId') = ? LIMIT 1",
            [$calendarId]
        ) !== null;
    }

    /** Claim the next due job (single-worker safe via conditional UPDATE). */
    public function claimNext(): ?array
    {
        $job = $this->db->one(
            "SELECT * FROM jobs WHERE status = 'pending' AND run_after <= ? ORDER BY run_after, id LIMIT 1",
            [Time::nowDb()]
        );
        if ($job === null) {
            return null;
        }
        $claimed = $this->db->run(
            "UPDATE jobs SET status = 'running', attempts = attempts + 1 WHERE id = ? AND status = 'pending'",
            [$job['id']]
        )->rowCount();
        if ($claimed === 0) {
            return null;
        }
        $job['attempts'] = (int) $job['attempts'] + 1;
        return $job;
    }

    public function markDone(int $jobId): void
    {
        $this->db->run("UPDATE jobs SET status = 'done', last_error = NULL WHERE id = ?", [$jobId]);
    }

    public function markFailed(array $job, string $error): void
    {
        $attempts = (int) $job['attempts'];
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->db->run("UPDATE jobs SET status = 'failed', last_error = ? WHERE id = ?", [mb_substr($error, 0, 2000), $job['id']]);
            return;
        }
        $delay = new \DateInterval('PT' . ($attempts * 5) . 'M');
        $this->db->run(
            "UPDATE jobs SET status = 'pending', last_error = ?, run_after = ? WHERE id = ?",
            [mb_substr($error, 0, 2000), Time::toDb(Time::nowUtc()->add($delay)), $job['id']]
        );
    }

    /**
     * A job left 'running' by a worker that died mid-job (killed at deploy,
     * out of memory, host reboot) would otherwise stay running forever: it
     * never re-runs, and hasPending() keeps answering yes for its type, so
     * nothing enqueues a fresh one either. Anything running longer than the
     * worker itself may live gets the same treatment as a thrown failure:
     * retried with backoff, failed for good after MAX_ATTEMPTS.
     *
     * @return list<int> the ids reaped
     */
    public function reapStalled(int $minutes = 30): array
    {
        $cut = Time::toDb(Time::nowUtc()->sub(new \DateInterval('PT' . $minutes . 'M')));
        $stalled = $this->db->all("SELECT * FROM jobs WHERE status = 'running' AND updated_at < ?", [$cut]);
        $ids = [];
        foreach ($stalled as $job) {
            $this->markFailed($job, 'stalled: still running after ' . $minutes . ' minutes; the worker probably died');
            $ids[] = (int) $job['id'];
        }
        return $ids;
    }

    /** Prune finished jobs older than a week. */
    public function prune(): void
    {
        $this->db->run(
            "DELETE FROM jobs WHERE status IN ('done', 'failed') AND updated_at < ?",
            [Time::toDb(Time::nowUtc()->sub(new \DateInterval('P7D')))]
        );
    }
}
