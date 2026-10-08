<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Support\Limits;
use BetterCal\Support\Time;

/** Persistent fail-closed admission for email and manual plugin work. */
final class ExternalActionAdmission
{
    public const REMINDER_EMAIL = 'reminder_email';
    public const PLUGIN_MANUAL_RUN = 'plugin_manual_run';

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Reserve one outbound notification email before SMTP.
     *
     * @return array{admitted:bool,reason:?string,retryAt:?string}
     */
    public function admitEmail(int $userId, string $recipient, ?string $cycleKey, ?\DateTimeImmutable $now = null): array
    {
        $now ??= Time::nowUtc();
        $subject = hash('sha256', strtolower(trim($recipient)));
        $cycle = $cycleKey !== null && $cycleKey !== '' ? hash('sha256', $cycleKey) : null;

        try {
            return $this->db->tx(function () use ($userId, $subject, $cycle, $now): array {
                $this->lock();
                $hour = Time::toDb($now->sub(new \DateInterval('PT1H')));
                $day = Time::toDb($now->sub(new \DateInterval('P1D')));
                $checks = [
                    ['cycle', $cycle !== null ? $this->count('user_id = ? AND kind = ? AND cycle_key = ?', [$userId, self::REMINDER_EMAIL, $cycle]) : 0, Limits::get('NOTIFY_EMAILS_PER_CYCLE')],
                    ['account_hour', $this->count('user_id = ? AND kind = ? AND admitted_at > ?', [$userId, self::REMINDER_EMAIL, $hour]), Limits::get('NOTIFY_EMAILS_PER_ACCOUNT_HOUR')],
                    ['account_day', $this->count('user_id = ? AND kind = ? AND admitted_at > ?', [$userId, self::REMINDER_EMAIL, $day]), Limits::get('NOTIFY_EMAILS_PER_ACCOUNT_DAY')],
                    ['recipient_hour', $this->count('user_id = ? AND kind = ? AND subject_key = ? AND admitted_at > ?', [$userId, self::REMINDER_EMAIL, $subject, $hour]), Limits::get('NOTIFY_EMAILS_PER_RECIPIENT_HOUR')],
                    ['recipient_day', $this->count('user_id = ? AND kind = ? AND subject_key = ? AND admitted_at > ?', [$userId, self::REMINDER_EMAIL, $subject, $day]), Limits::get('NOTIFY_EMAILS_PER_RECIPIENT_DAY')],
                    ['install_hour', $this->count('kind = ? AND admitted_at > ?', [self::REMINDER_EMAIL, $hour]), Limits::get('NOTIFY_EMAILS_PER_INSTALL_HOUR')],
                    ['install_day', $this->count('kind = ? AND admitted_at > ?', [self::REMINDER_EMAIL, $day]), Limits::get('NOTIFY_EMAILS_PER_INSTALL_DAY')],
                ];
                foreach ($checks as [$reason, $count, $limit]) {
                    if ($count >= $limit) {
                        $retry = $now->add($reason === 'cycle'
                            ? new \DateInterval('PT10M')
                            : (str_ends_with($reason, '_day') ? new \DateInterval('P1D') : new \DateInterval('PT1H')));
                        return ['admitted' => false, 'reason' => $reason, 'retryAt' => Time::toDb($retry)];
                    }
                }
                $this->db->insert('external_action_admissions', [
                    'user_id' => $userId,
                    'kind' => self::REMINDER_EMAIL,
                    'subject_key' => $subject,
                    'cycle_key' => $cycle,
                    'admitted_at' => Time::toDb($now),
                ]);
                return ['admitted' => true, 'reason' => null, 'retryAt' => null];
            });
        } catch (\Throwable $e) {
            error_log('notification email admission unavailable: ' . $e->getMessage());
            return ['admitted' => false, 'reason' => 'accounting_unavailable', 'retryAt' => null];
        }
    }

    /** Acquire the shared count-and-insert lock inside the caller's transaction. */
    public function lock(): void
    {
        $suffix = $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        if ($this->db->scalar('SELECT id FROM external_action_lock WHERE id = 1' . $suffix) === null) {
            throw new \RuntimeException('external action admission lock is missing');
        }
    }

    public function count(string $where, array $params): int
    {
        return (int) ($this->db->scalar("SELECT COUNT(*) FROM external_action_admissions WHERE $where", $params) ?? 0);
    }

    public function record(int $userId, string $kind, string $subject, ?string $cycleKey, \DateTimeImmutable $now): void
    {
        $this->db->insert('external_action_admissions', [
            'user_id' => $userId,
            'kind' => $kind,
            'subject_key' => hash('sha256', $subject),
            'cycle_key' => $cycleKey !== null ? hash('sha256', $cycleKey) : null,
            'admitted_at' => Time::toDb($now),
        ]);
    }

    public function prune(?\DateTimeImmutable $now = null): int
    {
        $now ??= Time::nowUtc();
        return $this->db->run(
            'DELETE FROM external_action_admissions WHERE admitted_at < ?',
            [Time::toDb($now->sub(new \DateInterval('P7D')))]
        )->rowCount();
    }
}
