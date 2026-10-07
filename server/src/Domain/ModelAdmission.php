<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Support\Limits;
use BetterCal\Support\Time;

/** Persistent admission for non-mail paid model calls. */
final class ModelAdmission
{
    public const QUICKADD = 'quickadd';
    public const PROMPT_FILTER = 'prompt_filter';

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Reserve interactive parsing capacity. Browser sessions share the account
     * budget; bearer tokens also have their own smaller fairness bucket.
     *
     * @return array{allowed:bool,reservationId:?int,code:?string,message:?string,retryAt:?string}
     */
    public function reserveQuickAdd(
        int $userId,
        ?int $tokenId,
        ?\DateTimeImmutable $now = null,
    ): array {
        $kind = $tokenId !== null ? 'token' : 'session';
        $identity = $tokenId !== null ? (string) $tokenId : 'owner';
        return $this->reserve(
            $userId,
            self::QUICKADD,
            $kind,
            $identity,
            $now ?? Time::nowUtc(),
            30,
            'MODEL_QUICKADD_PER_HOUR',
            'MODEL_QUICKADD_PER_DAY',
            $tokenId !== null ? 'MODEL_QUICKADD_PER_TOKEN_HOUR' : null,
            $tokenId !== null ? 'MODEL_QUICKADD_PER_TOKEN_DAY' : null,
            'MODEL_QUICKADD_CONCURRENT',
            $tokenId !== null ? 'MODEL_QUICKADD_PER_TOKEN_CONCURRENT' : null,
            'AI parsing is temporarily paused because the Quick Add model-call limit was reached.',
        );
    }

    /**
     * Reserve one prompt-filter batch. Calendar and account limits are both
     * authoritative; every batch contains events from exactly one calendar.
     *
     * @return array{allowed:bool,reservationId:?int,code:?string,message:?string,retryAt:?string}
     */
    public function reservePromptFilter(
        int $userId,
        int $calendarId,
        ?\DateTimeImmutable $now = null,
    ): array {
        return $this->reserve(
            $userId,
            self::PROMPT_FILTER,
            'calendar',
            (string) $calendarId,
            $now ?? Time::nowUtc(),
            90,
            'MODEL_FILTER_PER_HOUR',
            'MODEL_FILTER_PER_DAY',
            'MODEL_FILTER_PER_CALENDAR_HOUR',
            'MODEL_FILTER_PER_CALENDAR_DAY',
            null,
            null,
            'AI filter processing is temporarily paused because the prompt-filter model-call limit was reached.',
        );
    }

    /** Mark the lease over; the attempt remains in the rolling counters. */
    public function finish(?int $reservationId, ?\DateTimeImmutable $now = null): void
    {
        if ($reservationId === null) {
            return;
        }
        try {
            $this->db->update('model_admissions', ['finished_at' => Time::toDb($now ?? Time::nowUtc())], 'id = ? AND finished_at IS NULL', [$reservationId]);
        } catch (\Throwable $e) {
            // The lease expires on its own. Do not turn a completed fallback or
            // worker batch into a user-visible failure because bookkeeping did.
            error_log('model admission finish failed: ' . get_class($e));
        }
    }

    /** Delete accounting history after no configured rolling window needs it. */
    public function prune(?\DateTimeImmutable $now = null): int
    {
        $now ??= Time::nowUtc();
        $cutoff = $now->sub(new \DateInterval('P' . Limits::get('MODEL_LOG_RETENTION_DAYS') . 'D'));
        return $this->db->run('DELETE FROM model_admissions WHERE admitted_at < ?', [Time::toDb($cutoff)])->rowCount();
    }

    /**
     * @return array{allowed:bool,reservationId:?int,code:?string,message:?string,retryAt:?string}
     */
    private function reserve(
        int $userId,
        string $operation,
        string $principalKind,
        string $principalIdentity,
        \DateTimeImmutable $now,
        int $leaseSeconds,
        string $accountHourLimit,
        string $accountDayLimit,
        ?string $principalHourLimit,
        ?string $principalDayLimit,
        ?string $accountConcurrentLimit,
        ?string $principalConcurrentLimit,
        string $message,
    ): array {
        try {
            return $this->db->tx(function () use (
                $userId,
                $operation,
                $principalKind,
                $principalIdentity,
                $now,
                $leaseSeconds,
                $accountHourLimit,
                $accountDayLimit,
                $principalHourLimit,
                $principalDayLimit,
                $accountConcurrentLimit,
                $principalConcurrentLimit,
                $message,
            ): array {
                $this->lockAccount($userId);
                $key = hash('sha256', $principalKind . ':' . $principalIdentity);
                $hour = $now->sub(new \DateInterval('PT1H'));
                $day = $now->sub(new \DateInterval('P1D'));

                if ($this->count($userId, $operation, $hour) >= Limits::get($accountHourLimit)) {
                    return self::denied('model_account_hour', $message, $this->rollingRetryAt($userId, $operation, $hour, 'PT1H', $now));
                }
                if ($this->count($userId, $operation, $day) >= Limits::get($accountDayLimit)) {
                    return self::denied('model_account_day', $message, $this->rollingRetryAt($userId, $operation, $day, 'P1D', $now));
                }
                if ($principalHourLimit !== null
                    && $this->count($userId, $operation, $hour, $principalKind, $key) >= Limits::get($principalHourLimit)
                ) {
                    return self::denied('model_' . $principalKind . '_hour', $message,
                        $this->rollingRetryAt($userId, $operation, $hour, 'PT1H', $now, $principalKind, $key));
                }
                if ($principalDayLimit !== null
                    && $this->count($userId, $operation, $day, $principalKind, $key) >= Limits::get($principalDayLimit)
                ) {
                    return self::denied('model_' . $principalKind . '_day', $message,
                        $this->rollingRetryAt($userId, $operation, $day, 'P1D', $now, $principalKind, $key));
                }
                if ($accountConcurrentLimit !== null
                    && $this->activeCount($userId, $operation, $now) >= Limits::get($accountConcurrentLimit)
                ) {
                    return self::denied('model_account_concurrent', 'AI parsing is busy with other requests. The basic parser is still available.',
                        $this->activeRetryAt($userId, $operation, $now));
                }
                if ($principalConcurrentLimit !== null
                    && $this->activeCount($userId, $operation, $now, $principalKind, $key) >= Limits::get($principalConcurrentLimit)
                ) {
                    return self::denied('model_' . $principalKind . '_concurrent', 'AI parsing is busy for this API token. The basic parser is still available.',
                        $this->activeRetryAt($userId, $operation, $now, $principalKind, $key));
                }

                $id = $this->db->insert('model_admissions', [
                    'user_id' => $userId,
                    'operation' => $operation,
                    'principal_kind' => $principalKind,
                    'principal_key' => $key,
                    'admitted_at' => Time::toDb($now),
                    'lease_until' => Time::toDb($now->add(new \DateInterval('PT' . $leaseSeconds . 'S'))),
                    'finished_at' => null,
                ]);
                return self::allowed($id);
            });
        } catch (\Throwable $e) {
            // Missing/corrupt accounting must never silently remove the paid-
            // call boundary. The caller keeps its deterministic/fail-open path.
            error_log('model admission unavailable: ' . get_class($e));
            return self::denied(
                'model_admission_unavailable',
                'AI processing is temporarily paused because its safety accounting is unavailable.',
                $now->add(new \DateInterval('PT5M')),
            );
        }
    }

    private function lockAccount(int $userId): void
    {
        $driver = (string) $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = 'SELECT id FROM users WHERE id = ?' . ($driver === 'mysql' ? ' FOR UPDATE' : '');
        if ($this->db->scalar($sql, [$userId]) === null) {
            throw new \RuntimeException('Model admission could not lock its account');
        }
    }

    private function count(
        int $userId,
        string $operation,
        \DateTimeImmutable $since,
        ?string $principalKind = null,
        ?string $principalKey = null,
    ): int {
        $sql = 'SELECT COUNT(*) FROM model_admissions WHERE user_id = ? AND operation = ? AND admitted_at > ?';
        $params = [$userId, $operation, Time::toDb($since)];
        if ($principalKind !== null && $principalKey !== null) {
            $sql .= ' AND principal_kind = ? AND principal_key = ?';
            array_push($params, $principalKind, $principalKey);
        }
        return (int) $this->db->scalar($sql, $params);
    }

    private function activeCount(
        int $userId,
        string $operation,
        \DateTimeImmutable $now,
        ?string $principalKind = null,
        ?string $principalKey = null,
    ): int {
        $sql = 'SELECT COUNT(*) FROM model_admissions WHERE user_id = ? AND operation = ? AND finished_at IS NULL AND lease_until > ?';
        $params = [$userId, $operation, Time::toDb($now)];
        if ($principalKind !== null && $principalKey !== null) {
            $sql .= ' AND principal_kind = ? AND principal_key = ?';
            array_push($params, $principalKind, $principalKey);
        }
        return (int) $this->db->scalar($sql, $params);
    }

    private function rollingRetryAt(
        int $userId,
        string $operation,
        \DateTimeImmutable $since,
        string $window,
        \DateTimeImmutable $now,
        ?string $principalKind = null,
        ?string $principalKey = null,
    ): \DateTimeImmutable {
        $sql = 'SELECT MIN(admitted_at) FROM model_admissions WHERE user_id = ? AND operation = ? AND admitted_at > ?';
        $params = [$userId, $operation, Time::toDb($since)];
        if ($principalKind !== null && $principalKey !== null) {
            $sql .= ' AND principal_kind = ? AND principal_key = ?';
            array_push($params, $principalKind, $principalKey);
        }
        $oldest = $this->db->scalar($sql, $params);
        return is_string($oldest) && $oldest !== ''
            ? Time::fromDb($oldest)->add(new \DateInterval($window))
            : $now->add(new \DateInterval($window));
    }

    private function activeRetryAt(
        int $userId,
        string $operation,
        \DateTimeImmutable $now,
        ?string $principalKind = null,
        ?string $principalKey = null,
    ): \DateTimeImmutable {
        $sql = 'SELECT MIN(lease_until) FROM model_admissions WHERE user_id = ? AND operation = ? AND finished_at IS NULL AND lease_until > ?';
        $params = [$userId, $operation, Time::toDb($now)];
        if ($principalKind !== null && $principalKey !== null) {
            $sql .= ' AND principal_kind = ? AND principal_key = ?';
            array_push($params, $principalKind, $principalKey);
        }
        $earliest = $this->db->scalar($sql, $params);
        return is_string($earliest) && $earliest !== '' ? Time::fromDb($earliest) : $now->add(new \DateInterval('PT30S'));
    }

    /** @return array{allowed:true,reservationId:int,code:null,message:null,retryAt:null} */
    private static function allowed(int $reservationId): array
    {
        return ['allowed' => true, 'reservationId' => $reservationId, 'code' => null, 'message' => null, 'retryAt' => null];
    }

    /** @return array{allowed:false,reservationId:null,code:string,message:string,retryAt:string} */
    private static function denied(string $code, string $message, \DateTimeImmutable $retryAt): array
    {
        return [
            'allowed' => false,
            'reservationId' => null,
            'code' => $code,
            'message' => $message,
            'retryAt' => Time::iso($retryAt),
        ];
    }
}
