<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Infra\EmailSender;
use BetterCal\Support\Time;

/**
 * Whether background work is working, per subject, with transitions recorded
 * where the user will see them and email when a failure has lasted long
 * enough to be real.
 *
 * Subjects: 'job:<type>' (system-wide), 'feed:<calendarId>' and
 * 'push:<subscriptionId>' (per user). Callers report every outcome; this
 * class notices the moments that matter — the first failure after success
 * and the first success after failure — and writes exactly one Activity
 * entry for each. A failing streak that persists past a per-kind threshold
 * earns one email, and one more when it recovers; never one per failure.
 *
 * Who gets emailed: per-user subjects go to that user's account email;
 * system-wide subjects go to BETTERCAL_ALERT_EMAIL when set, else to the
 * first user, who on a self-hosted install is the operator.
 */
final class SystemHealth
{
    /** A job must have failed this many times in a row AND for this long. */
    public const JOB_MIN_FAILURES = 3;
    public const JOB_ALERT_AFTER = 'PT1H';
    /** Feeds poll hourly; a day of errors is a source that is really gone. */
    public const FEED_ALERT_AFTER = 'P1D';
    /** Push failures are only observed when a reminder is attempted. */
    public const PUSH_ALERT_AFTER = 'PT6H';
    /** At most one failure email per subject per day while it stays failing. */
    public const REALERT_AFTER = 'P1D';
    /** "Sat, Sep 13, 2026 at 12:46 AM PDT": email is read by a person, in their zone. */
    public const EMAIL_TIME = 'D, M j, Y \a\t g:i A T';

    public function __construct(private readonly Db $db)
    {
    }

    // ---- Reporting ----------------------------------------------------

    /** @return bool true when this was the first failure after success (a transition) */
    public function recordFailure(string $subject, string $kind, ?int $userId, string $label, string $error): bool
    {
        $now = Time::nowDb();
        $error = mb_substr($error, 0, 2000);
        $row = $this->db->one('SELECT * FROM system_health WHERE subject = ?', [$subject]);
        if ($row === null) {
            $this->db->insert('system_health', [
                'subject' => $subject, 'kind' => $kind, 'user_id' => $userId, 'label' => mb_substr($label, 0, 160),
                'status' => 'failing', 'first_failed_at' => $now, 'last_failed_at' => $now,
                'consecutive_failures' => 1, 'last_error' => $error,
            ]);
            $this->journal($userId, "$label started failing: $error");
            return true;
        }
        $transition = $row['status'] !== 'failing';
        $this->db->update('system_health', [
            'label' => mb_substr($label, 0, 160),
            'status' => 'failing',
            'first_failed_at' => $transition ? $now : $row['first_failed_at'],
            'last_failed_at' => $now,
            'consecutive_failures' => $transition ? 1 : (int) $row['consecutive_failures'] + 1,
            'last_error' => $error,
            'alerted_at' => $transition ? null : $row['alerted_at'],
        ], 'subject = ?', [$subject]);
        if ($transition) {
            $this->journal($userId, "$label started failing: $error");
        }
        return $transition;
    }

    /** @return bool true when this was the first success after a failing streak (recovery) */
    public function recordOk(string $subject, string $kind, ?int $userId, string $label): bool
    {
        $now = Time::nowDb();
        $row = $this->db->one('SELECT * FROM system_health WHERE subject = ?', [$subject]);
        if ($row === null) {
            $this->db->insert('system_health', [
                'subject' => $subject, 'kind' => $kind, 'user_id' => $userId, 'label' => mb_substr($label, 0, 160),
                'status' => 'ok', 'last_ok_at' => $now,
            ]);
            return false;
        }
        $recovered = $row['status'] === 'failing';
        // alerted_at survives recovery on purpose: the alert job reads it to
        // know a failure email went out and a recovery email is owed, then
        // clears it.
        $this->db->update('system_health', [
            'label' => mb_substr($label, 0, 160),
            'status' => 'ok',
            'last_ok_at' => $now,
            'consecutive_failures' => 0,
        ], 'subject = ?', [$subject]);
        if ($recovered) {
            $this->journal($userId, "$label recovered after " . self::describeStreak($row, $now));
        }
        return $recovered;
    }

    // ---- Reading ------------------------------------------------------

    /**
     * Everything a user should see: their own subjects plus the system-wide
     * ones, failing first, then by label.
     *
     * @return list<array<string,mixed>>
     */
    public function snapshot(int $userId): array
    {
        $rows = $this->db->all(
            "SELECT * FROM system_health WHERE user_id = ? OR user_id IS NULL
             ORDER BY (status = 'failing') DESC, kind, label",
            [$userId]
        );
        return array_map(static fn(array $r): array => [
            'subject' => (string) $r['subject'],
            'kind' => (string) $r['kind'],
            'label' => (string) $r['label'],
            'status' => (string) $r['status'],
            'firstFailedAt' => $r['first_failed_at'] !== null ? Time::dbToIso((string) $r['first_failed_at']) : null,
            'lastFailedAt' => $r['last_failed_at'] !== null ? Time::dbToIso((string) $r['last_failed_at']) : null,
            'lastOkAt' => $r['last_ok_at'] !== null ? Time::dbToIso((string) $r['last_ok_at']) : null,
            'consecutiveFailures' => (int) $r['consecutive_failures'],
            'lastError' => $r['last_error'] !== null ? (string) $r['last_error'] : null,
            'alertedAt' => $r['alerted_at'] !== null ? Time::dbToIso((string) $r['alerted_at']) : null,
        ], $rows);
    }

    // ---- Alerting -----------------------------------------------------

    /**
     * Does a failing row deserve an email right now? Pure, unit-tested.
     * The row is the raw table row; $now is UTC.
     */
    public static function shouldAlert(array $row, \DateTimeImmutable $now): bool
    {
        if (($row['status'] ?? '') !== 'failing' || empty($row['first_failed_at'])) {
            return false;
        }
        $since = Time::fromDb((string) $row['first_failed_at']);
        $threshold = match ((string) ($row['kind'] ?? 'job')) {
            'feed' => self::FEED_ALERT_AFTER,
            'push' => self::PUSH_ALERT_AFTER,
            default => self::JOB_ALERT_AFTER,
        };
        if ($since->add(new \DateInterval($threshold)) > $now) {
            return false;
        }
        if (($row['kind'] ?? 'job') === 'job' && (int) ($row['consecutive_failures'] ?? 0) < self::JOB_MIN_FAILURES) {
            return false;
        }
        if (!empty($row['alerted_at'])) {
            return Time::fromDb((string) $row['alerted_at'])->add(new \DateInterval(self::REALERT_AFTER)) <= $now;
        }
        return true;
    }

    /**
     * Send the emails that are due: one digest per recipient covering every
     * subject that crossed its threshold, and one per recipient for subjects
     * that recovered after an alert went out. Run from the worker every ten
     * minutes, so a broken SMTP cannot turn into a send attempt per minute.
     *
     * @return array{failureEmails:int,recoveryEmails:int,skipped:string|null}
     */
    public function sweepAlerts(EmailSender $email, ?string $alertEmail): array
    {
        $out = ['failureEmails' => 0, 'recoveryEmails' => 0, 'skipped' => null];
        if (!$email->isConfigured()) {
            $out['skipped'] = 'smtp not configured';
            return $out;
        }
        $now = Time::nowUtc();
        $nowDb = Time::toDb($now);

        $failing = [];   // recipient => list<row>
        $recovered = []; // recipient => list<row>
        foreach ($this->db->all("SELECT * FROM system_health WHERE status = 'failing' OR alerted_at IS NOT NULL") as $row) {
            $to = $this->recipientFor($row, $alertEmail);
            if ($to === null) {
                continue;
            }
            if ($row['status'] === 'failing' && self::shouldAlert($row, $now)) {
                $failing[$to][] = $row;
            } elseif ($row['status'] === 'ok' && !empty($row['alerted_at'])) {
                $recovered[$to][] = $row;
            }
        }

        foreach ($failing as $to => $rows) {
            $tz = $this->tzFor($rows[0]);
            $msg = self::buildFailureEmail($rows, $now, $tz, $this->baseUrl());
            if ($email->sendPlain((string) $to, $msg['subject'], $msg['text'], $msg['html'])) {
                foreach ($rows as $r) {
                    $this->db->run('UPDATE system_health SET alerted_at = ? WHERE subject = ?', [$nowDb, $r['subject']]);
                }
                $out['failureEmails']++;
            }
        }
        foreach ($recovered as $to => $rows) {
            $tz = $this->tzFor($rows[0]);
            $msg = self::buildRecoveryEmail($rows, $tz);
            if ($email->sendPlain((string) $to, $msg['subject'], $msg['text'], $msg['html'])) {
                foreach ($rows as $r) {
                    $this->db->run('UPDATE system_health SET alerted_at = NULL WHERE subject = ?', [$r['subject']]);
                }
                $out['recoveryEmails']++;
            }
        }
        return $out;
    }

    /**
     * Pure message builder. Timestamps are ISO 8601 with the recipient's
     * offset. @param list<array> $rows @return array{subject:string,text:string,html:string}
     */
    public static function buildFailureEmail(array $rows, \DateTimeImmutable $now, \DateTimeZone $tz, string $baseUrl): array
    {
        $n = count($rows);
        $subject = 'Better-Cal: ' . ($n === 1 ? (string) $rows[0]['label'] . ' has been failing' : "$n things have been failing");
        $lines = [];
        foreach ($rows as $r) {
            $since = Time::fromDb((string) $r['first_failed_at'])->setTimezone($tz)->format(self::EMAIL_TIME);
            $lines[] = '- ' . $r['label'] . ': failing since ' . $since . ' (' . self::describeStreak($r, Time::toDb($now)) . ')'
                . ($r['last_error'] !== null && $r['last_error'] !== '' ? "\n  last error: " . mb_substr((string) $r['last_error'], 0, 300) : '');
        }
        $link = rtrim($baseUrl, '/') . '/#settings';
        $text = "These have been failing long enough to be worth your attention:\n\n" . implode("\n", $lines)
            . "\n\nDetails and history: $link (Settings, System) and the Activity page.\n"
            . "You get one email per failing streak, and one when it recovers.\n";
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:-apple-system,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px 16px;color:#1c1e22">'
            . '<p style="margin:0 0 12px;font-size:14px">These have been failing long enough to be worth your attention:</p><ul style="padding-left:18px;font-size:14px">';
        foreach ($rows as $r) {
            $since = Time::fromDb((string) $r['first_failed_at'])->setTimezone($tz)->format(self::EMAIL_TIME);
            $html .= '<li style="margin:0 0 8px"><strong>' . $h((string) $r['label']) . '</strong>: failing since ' . $h($since)
                . ' (' . $h(self::describeStreak($r, Time::toDb($now))) . ')'
                . ($r['last_error'] !== null && $r['last_error'] !== '' ? '<br><span style="color:#555b64;font-size:13px">last error: ' . $h(mb_substr((string) $r['last_error'], 0, 300)) . '</span>' : '')
                . '</li>';
        }
        $html .= '</ul><p style="margin:16px 0 0;font-size:13px;color:#555b64">Details and history: <a href="' . $h($link) . '">Settings, System</a> and the Activity page. You get one email per failing streak, and one when it recovers.</p></div>';
        return ['subject' => $subject, 'text' => $text, 'html' => $html];
    }

    /** @param list<array> $rows @return array{subject:string,text:string,html:string} */
    public static function buildRecoveryEmail(array $rows, \DateTimeZone $tz): array
    {
        $n = count($rows);
        $subject = 'Better-Cal: ' . ($n === 1 ? (string) $rows[0]['label'] . ' recovered' : "$n things recovered");
        $lines = [];
        foreach ($rows as $r) {
            $at = $r['last_ok_at'] !== null ? Time::fromDb((string) $r['last_ok_at'])->setTimezone($tz)->format(self::EMAIL_TIME) : '';
            $lines[] = '- ' . $r['label'] . ': working again' . ($at !== '' ? " as of $at" : '');
        }
        $text = "Recovered:\n\n" . implode("\n", $lines) . "\n";
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:-apple-system,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px 16px;color:#1c1e22"><p style="font-size:14px">Recovered:</p><ul style="padding-left:18px;font-size:14px">'
            . implode('', array_map(static fn(string $l): string => '<li>' . $h(ltrim($l, '- ')) . '</li>', $lines)) . '</ul></div>';
        return ['subject' => $subject, 'text' => $text, 'html' => $html];
    }

    /** "14 failures over 3h 20m" from a row's streak, pure. */
    public static function describeStreak(array $row, string $nowDb): string
    {
        $n = (int) ($row['consecutive_failures'] ?? 0);
        $part = $n . ' failure' . ($n === 1 ? '' : 's');
        if (empty($row['first_failed_at'])) {
            return $part;
        }
        $secs = max(0, Time::fromDb($nowDb)->getTimestamp() - Time::fromDb((string) $row['first_failed_at'])->getTimestamp());
        $d = intdiv($secs, 86400);
        $hh = intdiv($secs % 86400, 3600);
        $mm = intdiv($secs % 3600, 60);
        $dur = $d > 0 ? "{$d}d {$hh}h" : ($hh > 0 ? "{$hh}h {$mm}m" : "{$mm}m");
        return "$part over $dur";
    }

    // ---- Internals ----------------------------------------------------

    private function journal(?int $userId, string $summary): void
    {
        $uid = $userId ?? $this->ownerUserId();
        if ($uid === null) {
            return;
        }
        ActivityContext::with('system', function () use ($uid, $summary): void {
            (new Undo($this->db))->record($uid, 'system', 0, 'update', null, null, mb_substr($summary, 0, 300));
        });
    }

    private function recipientFor(array $row, ?string $alertEmail): ?string
    {
        if ($row['user_id'] !== null) {
            $e = $this->db->scalar('SELECT email FROM users WHERE id = ?', [(int) $row['user_id']]);
            return $e !== null ? (string) $e : null;
        }
        if ($alertEmail !== null && trim($alertEmail) !== '') {
            return trim($alertEmail);
        }
        $e = $this->db->scalar('SELECT email FROM users ORDER BY id LIMIT 1');
        return $e !== null ? (string) $e : null;
    }

    private function ownerUserId(): ?int
    {
        $id = $this->db->scalar('SELECT id FROM users ORDER BY id LIMIT 1');
        return $id !== null ? (int) $id : null;
    }

    private function tzFor(array $row): \DateTimeZone
    {
        $uid = $row['user_id'] !== null ? (int) $row['user_id'] : $this->ownerUserId();
        if ($uid !== null) {
            $tz = (new Settings($this->db))->forUser($uid)['tz'] ?? null;
            if (is_string($tz) && $tz !== '') {
                try {
                    return new \DateTimeZone($tz);
                } catch (\Throwable) {
                    // fall through
                }
            }
        }
        return new \DateTimeZone('UTC');
    }

    private function baseUrl(): string
    {
        return (string) (config()['base_url'] ?? '');
    }
}
