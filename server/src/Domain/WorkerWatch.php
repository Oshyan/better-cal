<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Infra\EmailSender;
use BetterCal\Support\Maintenance;
use BetterCal\Support\Time;

/**
 * Noticing that the worker stopped running. Its cron job can stop without a
 * word (the PHP it names was removed, the host paused it, someone deleted
 * it), and then reminders, feed refreshes and email import quietly stop too.
 *
 * The worker reports each run as the 'worker' subject of SystemHealth. The
 * check can't be another scheduled job, since the scheduler is what broke:
 * it rides on requests that arrive anyway (the app polling for changes,
 * phones syncing over CalDAV), at most every CHECK_EVERY seconds, after the
 * response has gone. No run for STALE_AFTER: the subject turns failing
 * (Settings, System, a banner in the app, Activity) and one email goes out
 * when email is set up. The worker's next run marks it working again, and
 * its alert sweep sends the recovery email.
 *
 * Plain calendar use (the app, phones over CalDAV) works without the worker,
 * so only a worker that ran and then stopped is an error. One that never ran
 * isn't set up yet, and one its owner turned off on purpose (state()) is off;
 * neither warns. Features that need it say so where they're turned on.
 */
final class WorkerWatch
{
    public const SUBJECT = 'worker';
    public const LABEL = 'Background worker (the cron job)';
    public const STALE_AFTER = 900;
    public const CHECK_EVERY = 120;

    /**
     * running, never (no run recorded, nor any job's), off (turned off on
     * purpose, and no run since), or stopped (failing).
     *
     * @return array{state:string,lastRunAt:?string,offAt:?string}
     */
    public static function state(Db $db): array
    {
        $row = $db->one('SELECT status, last_ok_at FROM system_health WHERE subject = ?', [self::SUBJECT]);
        $last = $row['last_ok_at'] ?? $db->scalar("SELECT MAX(last_ok_at) FROM system_health WHERE subject LIKE 'job:%'");
        try {
            $owner = $db->scalar('SELECT id FROM users ORDER BY id LIMIT 1');
            $offAt = $owner === null ? null : ((new Settings($db))->forUser((int) $owner)['workerOffAt'] ?? null);
        } catch (\Throwable) {
            $offAt = null; // reading health must work even where a table is missing
        }
        $iso = static fn(?string $t): ?string => $t === null ? null : Time::dbToIso($t);
        if ($offAt !== null && ($last === null || (string) $last < (string) $offAt)) {
            return ['state' => 'off', 'lastRunAt' => $iso($last), 'offAt' => $iso($offAt)];
        }
        if ($row !== null && $row['status'] === 'failing') {
            return ['state' => 'stopped', 'lastRunAt' => $iso($last), 'offAt' => null];
        }
        return ['state' => $last === null ? 'never' : 'running', 'lastRunAt' => $iso($last), 'offAt' => null];
    }

    /** The owner turned the worker off on purpose: no warnings until it runs again. */
    public static function turnOff(Db $db): void
    {
        $owner = $db->scalar('SELECT id FROM users ORDER BY id LIMIT 1');
        if ($owner === null) {
            return;
        }
        (new Settings($db))->patch((int) $owner, ['workerOffAt' => Time::nowDb()]);
        (new SystemHealth($db))->markOff(self::SUBJECT, self::LABEL, 'turned off on purpose; it is back on when the cron job runs it again.');
    }

    /** For the worker: it ran. */
    public static function ran(SystemHealth $health): void
    {
        $health->recordOk(self::SUBJECT, 'job', null, self::LABEL);
    }

    /**
     * From a front controller, after the response: check, unless a check ran
     * in the last CHECK_EVERY seconds (a file's time, so most requests cost
     * one stat). Never throws.
     */
    public static function afterResponse(array $cfg): void
    {
        register_shutdown_function(static function () use ($cfg): void {
            try {
                $stamp = $cfg['app_root'] . '/.runtime-cache/worker-watch';
                $at = @filemtime($stamp);
                if ($at !== false && $at > time() - self::CHECK_EVERY) {
                    return;
                }
                if (!is_dir(dirname($stamp)) || !@touch($stamp)) {
                    return;
                }
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    litespeed_finish_request();
                }
                self::check(new Db($cfg['db']), $cfg);
            } catch (\Throwable $e) {
                error_log('Better-Cal: checking whether the worker runs: ' . $e->getMessage());
            }
        });
    }

    /**
     * Mark the worker stopped when it's due. Returns true when it was just
     * marked (and emailed, where email is set up).
     */
    public static function check(Db $db, array $cfg, ?EmailSender $email = null, ?\DateTimeImmutable $now = null): bool
    {
        if (Maintenance::active((string) $cfg['app_root']) || \BetterCal\Http\InstallPages::needsDatabaseUpdate($cfg)) {
            return false; // an update pauses the worker on purpose
        }
        $now ??= Time::nowUtc();
        $state = self::state($db);
        if ($state['state'] !== 'running') {
            return false; // already stopped, off on purpose, or never set up: none of them news
        }
        $row = $db->one('SELECT status, last_ok_at FROM system_health WHERE subject = ?', [self::SUBJECT]);
        // An install that ran before this report existed (an update to
        // 0.9.83) counts from its jobs' last success.
        $last = $row['last_ok_at'] ?? $db->scalar("SELECT MAX(last_ok_at) FROM system_health WHERE subject LIKE 'job:%'");
        if ($last === null || Time::fromDb((string) $last)->getTimestamp() > $now->getTimestamp() - self::STALE_AFTER) {
            return false;
        }
        $error = "It hasn't run since then, so reminders, subscribed calendars and email import have stopped. Most often the cron job was removed or paused, or the PHP it names was removed in a PHP upgrade. Settings, System shows the command to use now.";
        $health = new SystemHealth($db);
        if (!$health->markStalled(self::SUBJECT, self::LABEL, (string) $last, $error)) {
            return false;
        }
        $health->alertNow(self::SUBJECT, $email ?? new EmailSender($cfg), (string) ($cfg['alert_email'] ?? ''));
        return true;
    }
}
