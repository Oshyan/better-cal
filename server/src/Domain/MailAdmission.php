<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Support\Limits;
use BetterCal\Support\ExpansionBudget;
use BetterCal\Support\Time;

/**
 * Persistent, account-wide admission for work triggered by the public ingest
 * mailbox. Message-ID, UID and From are all sender-controlled, so the global
 * counters are the security boundary; the sender counter is only a secondary
 * fairness brake.
 */
final class MailAdmission
{
    /** Expired finite series do not need expanding again during this worker run. */
    private array $expiredSeries = [];
    public const KIND_EVENT = 'event';
    public const KIND_LLM = 'llm';
    public const KIND_CHANGE = 'change';

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

    /** Policy, capacity and the first durable representation share one account lock. */
    public function createMailEvent(int $userId, string $key, string $from, callable $create, callable $hold): array
    {
        return $this->db->tx(function () use ($userId, $key, $from, $create, $hold): array {
            $this->lockAccount($userId);
            $settings = (new Settings($this->db))->forUser($userId);
            $auto = MailPolicy::addsAutomatically($settings, $from);
            $mode = (string) $settings['mailEventMode'];
            $result = $auto
                ? $this->createEvent($userId, $key, $from, fn() => $create($mode))
                : $this->createInvitationCandidate($userId, $key, $from, fn() => $hold($mode));
            return $result + ['held' => !$auto];
        });
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
     * A held change to an invitation the owner already has. It creates no
     * event, so it doesn't spend the new-event budget, but it does fill the
     * shared Review ceiling; without its own daily limits anyone knowing a
     * UID and its organizer's address could fill that in minutes and crowd
     * out real changes (review of 0.9.87). Caller holds the account lock.
     *
     * @return array{allowed:bool,code:?string,message:?string,retryAt:?string}
     */
    public function admitHeldChange(int $userId, string $candidateKey, string $from, ?\DateTimeImmutable $now = null): array
    {
        $now ??= Time::nowUtc();
        $day = $now->sub(new \DateInterval('P1D'));
        $sender = self::senderKey($from);
        if ($this->count(self::KIND_CHANGE, $userId, $day) >= Limits::get('MAIL_EVENTS_PER_DAY')) {
            return self::denied('change_account_day', 'Emailed changes to your events paused for Review because the account-wide daily limit was reached.', $now->add(new \DateInterval('P1D')));
        }
        if ($this->count(self::KIND_CHANGE, $userId, $day, $sender) >= Limits::get('MAIL_EVENTS_PER_SENDER_DAY')) {
            return self::denied('change_sender_day', 'Emailed changes from this sender paused for Review because its daily limit was reached.', $now->add(new \DateInterval('P1D')));
        }
        // A dismissed change may be sent again: each holding is its own admission.
        $this->record($userId, self::KIND_CHANGE, $candidateKey . ':' . bin2hex(random_bytes(8)), $sender, $now);
        return self::allowed();
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
               AND recurrence_parent_id IS NULL AND rrule IS NULL AND end_utc > ?",
            [$userId, Time::toDb($now)]
        );
        $series = $this->db->all("SELECT * FROM events WHERE user_id = ? AND deleted_at IS NULL
            AND created_via LIKE 'mail:%' AND recurrence_parent_id IS NULL AND rrule IS NOT NULL", [$userId]);
        $budget = ExpansionBudget::standard();
        foreach ($series as $master) {
            $overrides = $this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND user_id = ?', [(int) $master['id'], $userId]);
            $key = hash('sha256', json_encode([$master, $overrides]));
            if (isset($this->expiredSeries[$key]) && $now >= $this->expiredSeries[$key]) continue;
            try {
                if (self::seriesStillActive($master, $overrides, $now, $budget)) $active++;
                else $this->expiredSeries[$key] = $now;
            } catch (\Throwable $e) {
                error_log('mail capacity: recurring event ' . (int) $master['id'] . ' could not be checked (' . get_class($e) . ')');
                return self::denied('event_recurrence', 'New email events paused because repeating event #' . (int) $master['id'] . ' could not be checked within the safe work limit. Check or simplify its repeat rule before trying again.', null);
            }
            if ($active >= Limits::get('MAIL_ACTIVE_EVENTS')) break;
        }
        if ($active >= Limits::get('MAIL_ACTIVE_EVENTS')) {
            return self::denied(
                'event_active',
                'New events from email paused because the active-email-event capacity was reached. Remove unneeded emailed events or wait for events or finite repeating series to end.',
                null,
            );
        }
        return self::allowed();
    }

    /** A capacity predicate, not a bounded display-window expansion. Failures are not exhaustion. */
    public static function seriesStillActive(array $master, array $overrides, \DateTimeImmutable $now, ?ExpansionBudget $budget = null): bool
    {
        $budget ??= ExpansionBudget::standard();
        $budget->beginSeries();
        $skip = array_fill_keys(Recurrence::decodeExdates($master['exdates_json'] ?? null), true);
        foreach ($overrides as $override) {
            if (($override['deleted_at'] ?? null) === null && Time::fromDb((string) $override['end_utc']) > $now) return true;
            $skip[(string) $override['recurrence_instance_utc']] = true;
        }
        $rule = Recurrence::validateRrule((string) $master['rrule']);
        $parts = Recurrence::rruleParts($rule);
        if (!isset($parts['COUNT']) && !isset($parts['UNTIL'])) return true;
        $tz = Time::zone($master['tzid'] ?? null);
        $start = Time::fromDb((string) $master['start_utc'])->setTimezone($tz);
        $end = Time::fromDb((string) $master['end_utc'])->setTimezone($tz);
        $duration = $start->diff($end);
        $simple = array_diff(array_keys($parts), ['FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'WKST']) === [];
        // Sabre accepts SECONDLY/MINUTELY but its iterator cannot advance
        // those frequencies. Simple sub-hourly rules need no selector engine.
        if ($simple && in_array($parts['FREQ'], ['SECONDLY', 'MINUTELY'], true)) {
            $step = max(1, (int) ($parts['INTERVAL'] ?? 1)) * ($parts['FREQ'] === 'MINUTELY' ? 60 : 1);
            $until = isset($parts['UNTIL']) ? \Sabre\VObject\DateTimeParser::parse($parts['UNTIL'], $tz) : null;
            $index = max(0, intdiv(max(0, $now->getTimestamp() - $end->getTimestamp()), $step));
            $occurrence = $start->add(new \DateInterval('PT' . ($index * $step) . 'S'));
            while ((!isset($parts['COUNT']) || $index < (int) $parts['COUNT']) && ($until === null || $occurrence <= $until)) {
                $budget->occurrence();
                if ($occurrence->add($duration) > $now && !isset($skip[Time::toDb($occurrence)])) return true;
                $index++;
                $occurrence = $occurrence->add(new \DateInterval('PT' . $step . 'S'));
            }
            return false;
        }
        if (isset($parts['UNTIL']) && !isset($parts['COUNT'])) {
            $until = \Sabre\VObject\DateTimeParser::parse($parts['UNTIL'], $tz);
            // Include the last occurrence's duration, including an all-day
            // event crossing a clock change in its own time zone.
            if ($until->add($duration) <= $now) return false;
        }
        $iterator = new \Sabre\VObject\Recur\RRuleIterator($rule, $start);
        // COUNT is bounded by validateRrule. UNTIL-only rules can begin far
        // in the past; jump near now for the simple daily/weekly forms.
        if (!isset($parts['COUNT'])) {
            $periods = Recurrence::skippablePeriods($rule, $start, $now->sub($duration)->setTimezone($tz));
            if ($periods > 0) {
                $days = $periods * (int) ($parts['INTERVAL'] ?? 1) * ($parts['FREQ'] === 'WEEKLY' ? 7 : 1);
                $iterator = new \Sabre\VObject\Recur\RRuleIterator($rule, $start->add(new \DateInterval('P' . $days . 'D')));
            }
        }
        while ($iterator->valid()) {
            $budget->occurrence();
            $occurrence = \DateTimeImmutable::createFromInterface($iterator->current());
            if ($occurrence->add($duration) > $now && !isset($skip[Time::toDb($occurrence)])) return true;
            $iterator->next();
            if ($iterator->valid() && $iterator->current() <= $occurrence) {
                throw new \RuntimeException('The recurrence iterator did not advance');
            }
        }
        return false;
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
