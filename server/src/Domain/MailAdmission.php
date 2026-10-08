<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Support\Limits;
use BetterCal\Support\Time;

/**
 * Persistent, account-wide admission for work triggered by the public ingest
 * mailbox. Message-ID, UID and From are all sender-controlled, so the global
 * counters are the security boundary; the sender counter is only a secondary
 * fairness brake.
 */
final class MailAdmission
{
    public const KIND_EVENT = 'event';
    public const KIND_LLM = 'llm';

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * A cheap preflight used before paying for an LLM call. Event creation
     * repeats the same decision under the account lock, so this is not the
     * concurrency boundary.
     *
     * @return array{allowed:bool,code:?string,message:?string,retryAt:?string}
     */
    public function eventCapacity(int $userId, string $from, ?\DateTimeImmutable $now = null): array
    {
        return $this->eventDecision($userId, self::senderKey($from), $now ?? Time::nowUtc());
    }

    /**
     * Reserve one new-event admission and create its first durable
     * representation in the same transaction. That is normally the event; a
     * first-time unauthenticated iMIP REQUEST is a Review candidate instead.
     * The users row serializes concurrent admissions for this account in
     * MySQL. Nested transactions join this outer transaction (Db::tx).
     *
     * @return array{allowed:bool,value:mixed,code:?string,message:?string,retryAt:?string}
     */
    public function createEvent(
        int $userId,
        string $messageKey,
        string $from,
        callable $create,
        ?\DateTimeImmutable $now = null,
    ): array {
        $now ??= Time::nowUtc();
        return $this->db->tx(function () use ($userId, $messageKey, $from, $create, $now): array {
            $this->lockAccount($userId);
            $decision = $this->eventDecision($userId, self::senderKey($from), $now);
            if (!$decision['allowed']) {
                return $decision + ['value' => null];
            }
            $this->record($userId, self::KIND_EVENT, $messageKey, self::senderKey($from), $now);
            return $decision + ['value' => $create()];
        });
    }

    /**
     * Reserve a new-event admission for a first-time invitation, while also
     * enforcing the persistent open Review-candidate ceiling. Ordinary booking
     * creation intentionally does not share this queue-specific brake.
     *
     * @return array{allowed:bool,value:mixed,code:?string,message:?string,retryAt:?string}
     */
    public function createInvitationCandidate(
        int $userId,
        string $messageKey,
        string $from,
        callable $create,
        ?\DateTimeImmutable $now = null,
    ): array {
        $now ??= Time::nowUtc();
        return $this->db->tx(function () use ($userId, $messageKey, $from, $create, $now): array {
            $this->lockAccount($userId);
            $decision = $this->eventDecision($userId, self::senderKey($from), $now);
            if (!$decision['allowed']) {
                return $decision + ['value' => null];
            }
            $pending = (int) $this->db->scalar(
                "SELECT COUNT(*) FROM review_items WHERE user_id = ? AND status = 'open' AND kind IN (?, ?)",
                [$userId, ReviewQueue::KIND_INVITE_NEW, ReviewQueue::KIND_INVITE_CHANGE]
            );
            if ($pending >= Limits::get('MAIL_PENDING_INVITATIONS')) {
                return self::denied(
                    'event_pending',
                    'New emailed invitations paused because Review already holds the maximum number of invitation decisions. Dismiss or decide some of them before more can be added.',
                    null,
                ) + ['value' => null];
            }
            $this->record($userId, self::KIND_EVENT, $messageKey, self::senderKey($from), $now);
            return $decision + ['value' => $create()];
        });
    }

    /**
     * Reserve before contacting Gemini. Attempts are intentionally not refunded
     * when the provider returns null, errors, or the worker dies mid-request.
     *
     * @return array{allowed:bool,code:?string,message:?string,retryAt:?string}
     */
    public function reserveLlm(
        int $userId,
        string $messageKey,
        string $from,
        ?\DateTimeImmutable $now = null,
    ): array {
        $now ??= Time::nowUtc();
        return $this->db->tx(function () use ($userId, $messageKey, $from, $now): array {
            $this->lockAccount($userId);
            $senderKey = self::senderKey($from);
            $hour = $now->sub(new \DateInterval('PT1H'));
            $day = $now->sub(new \DateInterval('P1D'));
            $accountHour = $this->count(self::KIND_LLM, $userId, $hour);
            if ($accountHour >= Limits::get('MAIL_LLM_PER_HOUR')) {
                return self::denied(
                    'llm_account_hour',
                    'Automated email reading paused because the hourly model-call limit was reached.',
                    $now->add(new \DateInterval('PT1H')),
                );
            }
            $accountDay = $this->count(self::KIND_LLM, $userId, $day);
            if ($accountDay >= Limits::get('MAIL_LLM_PER_DAY')) {
                return self::denied(
                    'llm_account_day',
                    'Automated email reading paused because the daily model-call limit was reached.',
                    $now->add(new \DateInterval('P1D')),
                );
            }
            $senderDay = $this->count(self::KIND_LLM, $userId, $day, $senderKey);
            if ($senderDay >= Limits::get('MAIL_LLM_PER_SENDER_DAY')) {
                return self::denied(
                    'llm_sender_day',
                    'Automated email reading paused for this sender because its daily model-call limit was reached.',
                    $now->add(new \DateInterval('P1D')),
                );
            }
            $this->record($userId, self::KIND_LLM, $messageKey, $senderKey, $now);
            return self::allowed();
        });
    }

    /** Delete accounting history after it can no longer affect a window. */
    public function prune(?\DateTimeImmutable $now = null): int
    {
        $now ??= Time::nowUtc();
        $cutoff = $now->sub(new \DateInterval('P' . Limits::get('MAIL_LOG_RETENTION_DAYS') . 'D'));
        return $this->db->run('DELETE FROM mail_admissions WHERE admitted_at < ?', [Time::toDb($cutoff)])->rowCount();
    }

    /** @return array{allowed:bool,code:?string,message:?string,retryAt:?string} */
    private function eventDecision(int $userId, string $senderKey, \DateTimeImmutable $now): array
    {
        $day = $now->sub(new \DateInterval('P1D'));
        if ($this->count(self::KIND_EVENT, $userId, $day) >= Limits::get('MAIL_EVENTS_PER_DAY')) {
            return self::denied(
                'event_account_day',
                'New events from email paused because the account-wide daily limit was reached.',
                $now->add(new \DateInterval('P1D')),
            );
        }
        if ($this->count(self::KIND_EVENT, $userId, $day, $senderKey) >= Limits::get('MAIL_EVENTS_PER_SENDER_DAY')) {
            return self::denied(
                'event_sender_day',
                'New events from this email sender paused because its daily limit was reached.',
                $now->add(new \DateInterval('P1D')),
            );
        }
        $active = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM events
             WHERE user_id = ? AND deleted_at IS NULL AND created_via LIKE 'mail:%'
               AND recurrence_parent_id IS NULL AND (end_utc > ? OR rrule IS NOT NULL)",
            [$userId, Time::toDb($now)]
        );
        if ($active >= Limits::get('MAIL_ACTIVE_EVENTS')) {
            return self::denied(
                'event_active',
                'New events from email paused because the active-email-event capacity was reached. Remove unneeded emailed events or wait for non-repeating events to end.',
                null,
            );
        }
        return self::allowed();
    }

    private function lockAccount(int $userId): void
    {
        $driver = (string) $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = 'SELECT id FROM users WHERE id = ?' . ($driver === 'mysql' ? ' FOR UPDATE' : '');
        if ($this->db->scalar($sql, [$userId]) === null) {
            throw new \RuntimeException('Mail admission could not lock its account');
        }
    }

    private function count(string $kind, int $userId, \DateTimeImmutable $since, ?string $senderKey = null): int
    {
        $sql = 'SELECT COUNT(*) FROM mail_admissions WHERE user_id = ? AND kind = ? AND admitted_at > ?';
        $params = [$userId, $kind, Time::toDb($since)];
        if ($senderKey !== null) {
            $sql .= ' AND sender_key = ?';
            $params[] = $senderKey;
        }
        return (int) $this->db->scalar($sql, $params);
    }

    private function record(int $userId, string $kind, string $messageKey, string $senderKey, \DateTimeImmutable $now): void
    {
        $this->db->insert('mail_admissions', [
            'user_id' => $userId,
            'kind' => $kind,
            'message_key' => hash('sha256', $messageKey),
            'sender_key' => $senderKey,
            'admitted_at' => Time::toDb($now),
        ]);
    }

    private static function senderKey(string $from): string
    {
        $from = strtolower(trim($from));
        return hash('sha256', $from === '' ? '(missing)' : $from);
    }

    /** @return array{allowed:true,code:null,message:null,retryAt:null} */
    private static function allowed(): array
    {
        return ['allowed' => true, 'code' => null, 'message' => null, 'retryAt' => null];
    }

    /** @return array{allowed:false,code:string,message:string,retryAt:?string} */
    private static function denied(string $code, string $message, ?\DateTimeImmutable $retryAt): array
    {
        return [
            'allowed' => false,
            'code' => $code,
            'message' => $message,
            'retryAt' => $retryAt !== null ? Time::iso($retryAt) : null,
        ];
    }
}
