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
 * Subjects: 'job:<type>' and 'geocoder:<provider>' (system-wide), plus
 * 'feed:<calendarId>' and 'push:<subscriptionId>' (per user). Callers report
 * every outcome; this class notices the moments that matter — a failing
 * streak that has proved itself (JOURNAL_AFTER consecutive failures; one bad
 * fetch is weather, not news) and the first success after such a streak — and
 * writes exactly one Activity entry for each. A streak that persists past a
 * per-kind threshold earns one email, and one more when it recovers; never one
 * per failure.
 * A single blip still lands in the table (Settings, System) and still
 * counts toward the email thresholds; it just does not become history.
 *
 * Who gets emailed: per-user subjects go to that user's account email;
 * system-wide subjects go to BETTERCAL_ALERT_EMAIL when set, else to the
 * first user, who on a self-hosted install is the operator.
 */
final class SystemHealth
{
    /** A job must have failed this many times in a row AND for this long. */
    /** Consecutive failures before a streak is journaled to Activity. */
    public const JOURNAL_AFTER = 2;
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

    public function db(): Db
    {
        return $this->db;
    }

    // ---- Reporting ----------------------------------------------------

    /** @return bool true when this was the first failure after success (a transition) */
    public function recordFailure(string $subject, string $kind, ?int $userId, string $label, string $error): bool
    {
        $now = Time::nowDb();
        $error = mb_substr($error, 0, 2000);
        $label = mb_substr($label, 0, 160);
        return $this->db->tx(function () use ($subject, $kind, $userId, $label, $error, $now): bool {
            // The no-op upsert is the lock acquisition point. Concurrent
            // request workers serialize on the subject before any reads,
            // avoiding lost streak increments and duplicate Activity entries.
            $this->ensureRow($subject, $kind, $userId, $label);
            $row = $this->lockedRow($subject);
            if ($row === null) {
                throw new \RuntimeException('Could not create system health row');
            }
            $transition = $row['status'] !== 'failing';
            $streak = $transition ? 1 : (int) $row['consecutive_failures'] + 1;
            $this->db->update('system_health', [
                'kind' => $kind,
                'user_id' => $userId,
                'label' => $label,
                'status' => 'failing',
                'first_failed_at' => $transition ? $now : $row['first_failed_at'],
                'last_failed_at' => $now,
                'consecutive_failures' => $streak,
                'last_error' => $error,
                'alerted_at' => $transition ? null : $row['alerted_at'],
            ], 'subject = ?', [$subject]);
            // Journaled once, the moment the locked streak proves itself; a
            // flaky upstream that fails one fetch in thirty never reaches Activity.
            if ($streak === self::JOURNAL_AFTER) {
                $this->journal($userId, "$label started failing: $error");
            }
            return $transition;
        });
    }

    /** @return bool true when this was the first success after a failing streak (recovery) */
    public function recordOk(string $subject, string $kind, ?int $userId, string $label, bool $create = true): bool
    {
        $now = Time::nowDb();
        $label = mb_substr($label, 0, 160);
        return $this->db->tx(function () use ($subject, $kind, $userId, $label, $create, $now): bool {
            if ($create) {
                $this->ensureRow($subject, $kind, $userId, $label);
            }
            $row = $this->lockedRow($subject);
            if ($row === null) {
                return false;
            }
            // High-frequency callers such as place autocomplete pass false:
            // after a failure has recovered, healthy requests must become
            // read-only instead of serializing forever on this global row.
            if (!$create && $row['status'] !== 'failing') {
                return false;
            }
            $recovered = $row['status'] === 'failing';
            // A recovery is only news if the failure was.
            $journaled = $recovered && (int) $row['consecutive_failures'] >= self::JOURNAL_AFTER;
            // alerted_at survives recovery on purpose: the alert job reads it
            // to know a failure email went out and a recovery email is owed,
            // then clears it.
            $this->db->update('system_health', [
                'kind' => $kind,
                'user_id' => $userId,
                'label' => $label,
                'status' => 'ok',
                'last_ok_at' => $now,
                'consecutive_failures' => 0,
            ], 'subject = ?', [$subject]);
            if ($journaled) {
                $this->journal($userId, "$label recovered after " . self::describeStreak($row, $now));
            }
            return $recovered;
        });
    }

    /**
     * A condition found by a whole run, rather than an attempt that failed:
     * the duplicate check's pass ending with events left out. A run is its
     * own proof, so the condition is journaled when first recorded; the
     * same words while it is already recorded change nothing (no count, no
     * time, no Activity line), so a condition that holds unchanged is
     * recorded once, however many runs find it. New words record it again,
     * as a count of one. It never earns an email by itself: a job's email
     * needs JOB_MIN_FAILURES in a row (shouldAlert). Returns whether
     * anything was recorded.
     */
    public function recordCondition(string $subject, string $kind, ?int $userId, string $label, string $words): bool
    {
        $now = Time::nowDb();
        $words = mb_substr($words, 0, 2000);
        $label = mb_substr($label, 0, 160);
        return $this->db->tx(function () use ($subject, $kind, $userId, $label, $words, $now): bool {
            $this->ensureRow($subject, $kind, $userId, $label);
            $row = $this->lockedRow($subject);
            if ($row === null) {
                throw new \RuntimeException('Could not create system health row');
            }
            if ($row['status'] === 'failing' && (string) $row['last_error'] === $words) {
                return false;
            }
            $transition = $row['status'] !== 'failing';
            $this->db->update('system_health', [
                'kind' => $kind,
                'user_id' => $userId,
                'label' => $label,
                'status' => 'failing',
                'first_failed_at' => $transition ? $now : $row['first_failed_at'],
                'last_failed_at' => $now,
                'consecutive_failures' => 1,
                'last_error' => $words,
                'alerted_at' => $transition ? null : $row['alerted_at'],
            ], 'subject = ?', [$subject]);
            $this->journal($userId, "$label: $words");
            return true;
        });
    }

    /**
     * A condition (recordCondition) that a later run no longer finds: the
     * subject is ok again, journaled with $note. Nothing is written when it
     * was not recorded. An email sent for the streak before is followed by
     * its recovery email, as for any failure. Returns whether it cleared.
     */
    public function clearCondition(string $subject, string $label, string $note): bool
    {
        $now = Time::nowDb();
        $label = mb_substr($label, 0, 160);
        return $this->db->tx(function () use ($subject, $label, $note, $now): bool {
            $row = $this->lockedRow($subject);
            if ($row === null || $row['status'] !== 'failing') {
                return false;
            }
            $this->db->update('system_health', [
                'label' => $label,
                'status' => 'ok',
                'last_ok_at' => $now,
                'consecutive_failures' => 0,
            ], 'subject = ?', [$subject]);
            $this->journal($row['user_id'] !== null ? (int) $row['user_id'] : null, "$label: $note");
            return true;
        });
    }

    /**
     * Mark a subject failing because something stopped happening, rather
     * than because an attempt failed: the worker not running (WorkerWatch).
     * The streak starts at $sinceDb, the last time it did happen, and counts
     * as proven at once (journaled, and alertable now). Returns whether this
     * was the transition; a subject already failing is left as it is.
     */
    public function markStalled(string $subject, string $label, string $sinceDb, string $error): bool
    {
        $now = Time::nowDb();
        $label = mb_substr($label, 0, 160);
        return $this->db->tx(function () use ($subject, $label, $sinceDb, $error, $now): bool {
            $this->ensureRow($subject, 'job', null, $label);
            $row = $this->lockedRow($subject);
            if ($row === null || $row['status'] === 'failing') {
                return false;
            }
            $this->db->update('system_health', [
                'label' => $label,
                'status' => 'failing',
                'first_failed_at' => $sinceDb,
                'last_failed_at' => $now,
                'consecutive_failures' => self::JOB_MIN_FAILURES,
                'last_error' => mb_substr($error, 0, 2000),
                'alerted_at' => null,
            ], 'subject = ?', [$subject]);
            $this->journal(null, "$label stopped: $error");
            return true;
        });
    }

    /**
     * A subject that stopped on purpose (the worker, turned off by its
     * owner): no longer failing, with no recovery email owed. Its last good
     * time stays as it was. Journaled once.
     */
    public function markOff(string $subject, string $label, string $note): void
    {
        $this->db->tx(function () use ($subject, $label, $note): void {
            $this->ensureRow($subject, 'job', null, mb_substr($label, 0, 160));
            $this->lockedRow($subject);
            $this->db->update('system_health', [
                'status' => 'ok',
                'consecutive_failures' => 0,
                'last_error' => null,
                'alerted_at' => null,
            ], 'subject = ?', [$subject]);
            $this->journal(null, mb_substr($label . ' ' . $note, 0, 300));
        });
    }

    /**
     * Send the failure email for one system-wide subject now, rather than at
     * the worker's next sweep (there is none when the worker is what
     * stopped). The recovery email follows from the worker's sweep once it
     * runs again. Returns whether it was sent.
     */
    public function alertNow(string $subject, EmailSender $email, ?string $alertEmail): bool
    {
        if (!$email->isConfigured()) {
            return false;
        }
        $row = $this->db->one('SELECT * FROM system_health WHERE subject = ?', [$subject]);
        $to = $row === null ? null : $this->recipientFor($row, $alertEmail);
        if ($row === null || $to === null || $row['status'] !== 'failing' || !empty($row['alerted_at'])) {
            return false;
        }
        $msg = self::buildFailureEmail([$row], Time::nowUtc(), $this->tzFor($row), $this->baseUrl());
        if (!$email->sendPlain($to, $msg['subject'], $msg['text'], $msg['html'])) {
            return false;
        }
        $this->db->run('UPDATE system_health SET alerted_at = ? WHERE subject = ?', [Time::nowDb(), $subject]);
        return true;
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
        $this->pruneGone();
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
     * Whether the person has acknowledged the state a feed's failing polls
     * report: its Review notice of that state is dismissed (a calendar Google
     * no longer shows, shows only busy times, lists nothing, or a feed whose
     * updates are paused). Then no email goes for it, first or again, while
     * the notice stays dismissed; a new state is a new notice, open, and
     * alerts as before.
     */
    /** @return list<string> the failures a poll at $role records, whatever level was stored before it */
    private static function accessFailures(array $calendar, string $role): array
    {
        return array_values(array_unique(array_map(static fn(?string $was): string => GoogleWriter::accessWords($calendar, $role, $was), [...GoogleWriter::ROLES, null])));
    }

    public function acknowledged(array $row): bool
    {
        if (preg_match('/^feed:(\d+)$/', (string) $row['subject'], $m) !== 1 || empty($row['user_id'])) {
            return false;
        }
        $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ? AND user_id = ?', [(int) $m[1], (int) $row['user_id']]);
        if ($calendar === null) {
            return false;
        }
        // Held only for the failure the dismissed notice reports, while that same state holds: the failure a poll in
        // that state records (its words, made by the same function the poll uses), never any other failure.
        foreach ($this->db->all("SELECT kind FROM review_items WHERE user_id = ? AND status = 'dismissed' AND source_key IN (?, ?, ?)",
            [(int) $row['user_id'], ReviewQueue::accessKey((int) $calendar['id']), 'google-empty:' . (int) $calendar['id'], 'subscription:' . (int) $calendar['id']]) as $n) {
            $role = (string) ($calendar['google_access_role'] ?? '');
            $failure = match ((string) $n['kind']) {
                // A poll in that state says it from the level stored before it (the first poll after a move, or a later one).
                ReviewQueue::KIND_GOOGLE_NOT_SHOWN => $role === 'none' ? self::accessFailures($calendar, 'none') : [],
                // View only: polls succeed, nothing to hold. Busy times only: its poll's own failure.
                ReviewQueue::KIND_GOOGLE_READ_ONLY => $role === 'freeBusyReader' ? self::accessFailures($calendar, 'freeBusyReader') : [],
                ReviewQueue::KIND_GOOGLE_EMPTY => [GoogleSync::EMPTY_MESSAGE],
                'subscription' => (SubscriptionAuthority::describe($this->db, $calendar)['status'] ?? null) === 'paused' ? [(string) SubscriptionAuthority::describe($this->db, $calendar)['reason']] : [],
                default => [],
            };
            if (in_array((string) ($row['last_error'] ?? ''), array_map(static fn(string $f): string => mb_substr($f, 0, 2000), $failure), true)) {
                return true;
            }
        }
        return false;
    }

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
    public function sweepAlerts(EmailSender $email, ?string $alertEmail, array $placeSubjects = []): array
    {
        $out = ['failureEmails' => 0, 'recoveryEmails' => 0, 'skipped' => null];
        $this->pruneGone();
        if (!$email->isConfigured()) {
            $out['skipped'] = 'smtp not configured';
            return $out;
        }
        $now = Time::nowUtc();
        $nowDb = Time::toDb($now);

        $rows = $this->db->all("SELECT * FROM system_health WHERE status = 'failing' OR alerted_at IS NOT NULL");
        $failingSubjects = array_column(array_filter($rows, static fn(array $r): bool => $r['status'] === 'failing'), 'subject');
        $failing = [];   // recipient => list<row>
        $recovered = []; // recipient => list<row>
        foreach ($rows as $row) {
            $to = $this->recipientFor($row, $alertEmail);
            if ($to === null) {
                continue;
            }
            if ($row['status'] === 'failing' && self::shouldAlert($row, $now)
                && !self::placeAlertHeld((string) $row['subject'], $failingSubjects, $placeSubjects)
                && !$this->acknowledged($row)) {
                $failing[$to][] = $row;
            } elseif ($row['status'] === 'ok' && !empty($row['alerted_at'])) {
                $recovered[$to][] = $row;
            }
        }

        foreach ($failing as $to => $rows) {
            $tz = $this->tzFor($rows[0]);
            // Photon alone, and down: say how to keep place search working.
            $note = $placeSubjects === ['geocoder:photon'] && in_array('geocoder:photon', array_column($rows, 'subject'), true)
                ? PlaceProviders::PHOTON_DOWN_ADVICE : null;
            $msg = self::buildFailureEmail($rows, $now, $tz, $this->baseUrl(), $note);
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
     * Is a failing place search service's alert held back? Yes while another
     * configured service ($placeSubjects, Photon among them) still works: the
     * search falls through to it, so nothing waits on the outage. A service
     * no longer configured isn't worth an alert either. Other subjects are
     * never held. Pure.
     *
     * @param list<string> $failingSubjects every subject failing now
     * @param list<string> $placeSubjects the configured services' subjects
     */
    public static function placeAlertHeld(string $subject, array $failingSubjects, array $placeSubjects): bool
    {
        if ($placeSubjects === [] || !str_starts_with($subject, 'geocoder:') || $subject === 'geocoder:open-meteo') {
            return false;
        }
        if (!in_array($subject, $placeSubjects, true)) {
            return true;
        }
        return array_diff($placeSubjects, $failingSubjects) !== [];
    }

    /**
     * Pure message builder. Timestamps are ISO 8601 with the recipient's
     * offset; $note, when given, follows the list. @param list<array> $rows
     * @return array{subject:string,text:string,html:string}
     */
    public static function buildFailureEmail(array $rows, \DateTimeImmutable $now, \DateTimeZone $tz, string $baseUrl, ?string $note = null): array
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
            . ($note !== null ? "\n\n" . $note : '')
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
        $html .= '</ul>' . ($note !== null ? '<p style="margin:12px 0 0;font-size:14px">' . $h($note) . '</p>' : '')
            . '<p style="margin:16px 0 0;font-size:13px;color:#555b64">Details and history: <a href="' . $h($link) . '">Settings, System</a> and the Activity page. You get one email per failing streak, and one when it recovers.</p></div>';
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

    /**
     * "14 failed attempts over 3 hours and 20 minutes" from a row's streak, as a person would say it (#281): the time
     * in words, and none for a streak under a minute ("2 failed attempts", never "over 0m"). Pure.
     */
    public static function describeStreak(array $row, string $nowDb): string
    {
        $n = (int) ($row['consecutive_failures'] ?? 0);
        $part = $n . ' failed attempt' . ($n === 1 ? '' : 's');
        if (empty($row['first_failed_at'])) {
            return $part;
        }
        $secs = max(0, Time::fromDb($nowDb)->getTimestamp() - Time::fromDb((string) $row['first_failed_at'])->getTimestamp());
        if ($secs < 60) {
            return $part;
        }
        $unit = static fn(int $v, string $word): string => $v . ' ' . $word . ($v === 1 ? '' : 's');
        $d = intdiv($secs, 86400);
        $hh = intdiv($secs % 86400, 3600);
        $mm = intdiv($secs % 3600, 60);
        $dur = $d > 0 ? $unit($d, 'day') . ($hh > 0 ? ' and ' . $unit($hh, 'hour') : '')
            : ($hh > 0 ? $unit($hh, 'hour') . ($mm > 0 ? ' and ' . $unit($mm, 'minute') : '') : $unit($mm, 'minute'));
        return "$part over $dur";
    }

    // ---- Internals ----------------------------------------------------

    /**
     * Drop the rows of feeds and push devices that no longer exist. Nothing
     * deletes a subject's row when its calendar or device goes (the table
     * has no foreign keys), so Settings, System kept listing them, and a
     * failing one could keep an alert open. Best effort: reading health must
     * work even where a table is missing.
     */
    private function pruneGone(): void
    {
        try {
            $bySubject = ['feed' => [], 'push' => []];
            foreach ($this->db->all("SELECT subject FROM system_health WHERE subject LIKE 'feed:%' OR subject LIKE 'push:%'") as $row) {
                [$kind, $id] = explode(':', (string) $row['subject'], 2) + [1 => ''];
                if (isset($bySubject[$kind]) && ctype_digit($id)) {
                    $bySubject[$kind][(int) $id] = (string) $row['subject'];
                }
            }
            foreach (['feed' => 'calendars', 'push' => 'push_subscriptions'] as $kind => $table) {
                if ($bySubject[$kind] === []) {
                    continue;
                }
                [$in, $params] = Db::in(array_keys($bySubject[$kind]));
                $alive = array_flip(array_map('intval', array_column($this->db->all("SELECT id FROM $table WHERE id IN $in", $params), 'id')));
                $gone = array_values(array_diff_key($bySubject[$kind], $alive));
                if ($gone !== []) {
                    [$in, $params] = Db::in($gone);
                    $this->db->run("DELETE FROM system_health WHERE subject IN $in", $params);
                }
            }
        } catch (\Throwable) {
            // A missing table or a locked row: leave the rows for next time.
        }
    }

    private function ensureRow(string $subject, string $kind, ?int $userId, string $label): void
    {
        $params = [$subject, $kind, $userId, $label];
        if ($this->driver() === 'mysql') {
            $this->db->run(
                'INSERT INTO system_health (subject, kind, user_id, label) VALUES (?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE subject = VALUES(subject)',
                $params
            );
            return;
        }
        // The test suite uses SQLite. Its writer transaction supplies the
        // serialization that InnoDB supplies with the upsert row lock.
        $this->db->run(
            'INSERT OR IGNORE INTO system_health (subject, kind, user_id, label) VALUES (?, ?, ?, ?)',
            $params
        );
    }

    private function lockedRow(string $subject): ?array
    {
        $lock = $this->db->forUpdate();
        return $this->db->one('SELECT * FROM system_health WHERE subject = ?' . $lock, [$subject]);
    }

    private function driver(): string
    {
        return (string) $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    private function journal(?int $userId, string $summary): void
    {
        $uid = $userId ?? $this->ownerUserId();
        if ($uid === null) {
            return;
        }
        // The health record is bookkeeping; its note in Activity is the
        // account's, so it is written in a checked transaction.
        RequestAuthority::checkedTx($this->db, fn() => ActivityContext::with('system', function () use ($uid, $summary): void {
            (new Undo($this->db))->record($uid, 'system', 0, 'update', null, null, mb_substr($summary, 0, 300));
        }));
    }

    /**
     * Where a failure email goes: a user's own subjects (their feeds, devices
     * and jobs) to the address their reminders go to (Settings "Send email
     * to", else the account address), so one setting says where this app's
     * mail is read; system-wide subjects to BETTERCAL_ALERT_EMAIL when set,
     * else to the owner by the same rule.
     */
    private function recipientFor(array $row, ?string $alertEmail): ?string
    {
        if ($row['user_id'] === null && $alertEmail !== null && trim($alertEmail) !== '') {
            return trim($alertEmail);
        }
        $userId = $row['user_id'] !== null ? (int) $row['user_id'] : $this->ownerUserId();
        return $userId === null ? null : self::userDestination($this->db, $userId);
    }

    /** The address a user's own mail from this app goes to (reminders and failure emails alike), or null without a user. */
    public static function userDestination(Db $db, int $userId): ?string
    {
        $user = $db->one('SELECT email, settings_json FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            return null;
        }
        $stored = json_decode((string) ($user['settings_json'] ?? ''), true);
        return Settings::notifyDestination(Settings::withDefaults(is_array($stored) ? $stored : []), (string) $user['email'], $db);
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
