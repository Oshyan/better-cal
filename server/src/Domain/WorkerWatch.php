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
 */
final class WorkerWatch
{
    public const SUBJECT = 'worker';
    public const LABEL = 'Background worker (the cron job)';
    public const STALE_AFTER = 900;
    public const CHECK_EVERY = 120;

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
        $row = $db->one('SELECT status, last_ok_at FROM system_health WHERE subject = ?', [self::SUBJECT]);
        if ($row !== null && $row['status'] === 'failing') {
            return false;
        }
        // No run reported yet. An install that ran before this report existed
        // (an update to 0.9.83) has its jobs' successes to count from; only a
        // new install counts from when it was set up (its first account).
        $last = $row['last_ok_at'] ?? null;
        $everRan = $last !== null;
        if ($last === null) {
            $last = $db->scalar("SELECT MAX(last_ok_at) FROM system_health WHERE subject LIKE 'job:%'");
            $everRan = $last !== null;
            $last ??= $db->scalar('SELECT MIN(created_at) FROM users');
        }
        if ($last === null || Time::fromDb((string) $last)->getTimestamp() > $now->getTimestamp() - self::STALE_AFTER) {
            return false;
        }
        $error = !$everRan
            ? "It hasn't run since Better-Cal was set up, so reminders, subscribed calendars and email import aren't working. Add the cron job: Settings, System shows the command."
            : "It hasn't run since then, so reminders, subscribed calendars and email import have stopped. Most often the cron job was removed or paused, or the PHP it names was removed in a PHP upgrade. Settings, System shows the command to use now.";
        $health = new SystemHealth($db);
        if (!$health->markStalled(self::SUBJECT, self::LABEL, (string) $last, $error)) {
            return false;
        }
        $health->alertNow(self::SUBJECT, $email ?? new EmailSender($cfg), (string) ($cfg['alert_email'] ?? ''));
        return true;
    }
}
