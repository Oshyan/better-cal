<?php

declare(strict_types=1);

// Cron worker, run every minute: enqueues due feed polls and drains the job
// queue. Overlap-safe via MySQL GET_LOCK.

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Domain\Feeds;
use BetterCal\Domain\MailIngest;
use BetterCal\Domain\PromptEval;
use BetterCal\Domain\PushSubscriptions;
use BetterCal\Domain\Ranking;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Reminders;
use BetterCal\Infra\Db;
use BetterCal\Infra\EmailSender;
use BetterCal\Infra\JobQueue;
use BetterCal\Infra\MailFetcher;
use BetterCal\Infra\LlmGateway;
use BetterCal\Infra\PushSender;
use BetterCal\Support\Time;

const MAX_RUNTIME_SECONDS = 50;

$cfg = config();
$db = new Db($cfg['db']);
// GET_LOCK is server-global in MySQL, not per-database: namespace the lock by
// the database name so the dev instance's worker and production's can never
// block each other while sharing one MySQL server.
preg_match('/dbname=([^;]+)/', (string) $cfg['db']['dsn'], $lockM);
define('WORKER_LOCK', 'bettercal_worker:' . ($lockM[1] ?? 'default'));
$queue = new JobQueue($db);
$feeds = new Feeds($db, $queue);
$llm = new LlmGateway($cfg);
$promptEval = new PromptEval($db, $llm, $queue);
$ranking = new Ranking($db, $llm, $queue);
$reminders = new Reminders($db, new PushSubscriptions($db), new PushSender($cfg), new EmailSender($cfg), new Recurrence());
// Mail ingest needs the full Events domain (create/patch invited events).
$undo = new BetterCal\Domain\Undo($db);
$labels = new BetterCal\Domain\Labels($db);
$filters = new BetterCal\Domain\Filters($db, $undo, $queue);
$trips = new BetterCal\Domain\Trips($db, $undo);
$eventsDomain = new BetterCal\Domain\Events($db, new Recurrence(), $undo, $labels, $filters, $trips);
$pluginsDomain = new BetterCal\Domain\Plugins($db);

$locked = $db->scalar('SELECT GET_LOCK(?, 0)', [WORKER_LOCK]);
if ((int) $locked !== 1) {
    exit(0); // another worker is running
}

$started = time();
try {
    bc_enqueue_due_polls($db, $queue);
    bc_enqueue_recurring($db, $queue);

    while (time() - $started < MAX_RUNTIME_SECONDS) {
        $job = $queue->claimNext();
        if ($job === null) {
            break;
        }
        $payload = json_decode((string) ($job['payload_json'] ?? '{}'), true) ?: [];
        try {
            switch ((string) $job['type']) {
                case 'feed_poll':
                    $calendarId = (int) ($payload['calendarId'] ?? 0);
                    $imported = $feeds->poll($calendarId);
                    echo bc_ts() . " feed_poll calendar=$calendarId imported=$imported\n";
                    break;
                case 'filter_eval':
                    $filterId = isset($payload['filterId']) ? (int) $payload['filterId'] : null;
                    $eventIds = isset($payload['eventIds']) && is_array($payload['eventIds'])
                        ? array_map('intval', $payload['eventIds'])
                        : null;
                    $evaluated = $promptEval->run($filterId, $eventIds);
                    echo bc_ts() . ' filter_eval'
                        . ($filterId !== null ? " filter=$filterId" : '')
                        . ($eventIds !== null ? ' events=' . count($eventIds) : '')
                        . " evaluated=$evaluated\n";
                    break;
                case 'rank_events':
                    $scored = $ranking->run();
                    echo bc_ts() . " rank_events scored=$scored\n";
                    break;
                case 'mail_ingest':
                    $fetcher = new MailFetcher($cfg);
                    if ($fetcher->isConfigured()) {
                        $ingest = new MailIngest($db, $eventsDomain, $llm);
                        $uid = (int) ($db->scalar('SELECT id FROM users ORDER BY id LIMIT 1') ?? 0);
                        $tzRow = $db->scalar('SELECT settings_json FROM users ORDER BY id LIMIT 1');
                        $tzSettings = is_string($tzRow) ? (json_decode($tzRow, true) ?: []) : [];
                        $tz = is_string($tzSettings['tz'] ?? null) && $tzSettings['tz'] !== '' ? $tzSettings['tz'] : 'UTC';
                        $done = 0;
                        foreach ($fetcher->fetchUnseen(10) as $msg) {
                            $r = $ingest->ingestMessage($uid, $msg, $tz);
                            echo bc_ts() . ' mail_ingest msg=' . substr($msg['messageId'], 0, 40)
                                . ' tier=' . ($r['tier'] ?? '-') . ' outcome=' . $r['outcome'] . "\n";
                            $done++;
                        }
                        if ($done === 0) {
                            echo bc_ts() . " mail_ingest idle\n";
                        }
                    }
                    break;
                case 'reminder_scan':
                    $result = $reminders->scan();
                    echo bc_ts() . ' reminder_scan sent=' . $result['sent'] . ' failed=' . $result['failed']
                        . ' emailed=' . ($result['emailed'] ?? 0) . "\n";
                    break;
                case 'plugin_job':
                    // Plugin code runs ONLY here (and in explicit settings
                    // validation): budgeted, health-recorded, circuit-broken.
                    $payload = json_decode((string) $job['payload_json'], true) ?: [];
                    $uidP = (int) ($db->scalar('SELECT id FROM users ORDER BY id LIMIT 1') ?? 0);
                    $r = $pluginsDomain->runJob($uidP, (string) ($payload['plugin'] ?? ''), (string) ($payload['job'] ?? ''));
                    echo bc_ts() . ' plugin_job ' . ($payload['plugin'] ?? '?') . '/' . ($payload['job'] ?? '?')
                        . ' outcome=' . $r['outcome'] . (isset($r['durationMs']) ? ' ' . $r['durationMs'] . 'ms' : '')
                        . ($r['error'] !== null ? ' error=' . $r['error'] : '') . "\n";
                    break;
                case 'activity_prune':
                    $result = (new BetterCal\Domain\Activity($db))->prune();
                    echo bc_ts() . ' activity_prune cleared=' . $result['snapshotsCleared']
                        . ' deleted=' . $result['deleted'] . "\n";
                    break;
                default:
                    throw new \RuntimeException('Unknown job type: ' . $job['type']);
            }
            $queue->markDone((int) $job['id']);
        } catch (\Throwable $e) {
            $queue->markFailed($job, $e->getMessage());
            fwrite(STDERR, bc_ts() . ' job ' . $job['id'] . ' (' . $job['type'] . ') failed: ' . $e->getMessage() . "\n");
        }
    }

    $queue->prune();
} finally {
    $db->run('SELECT RELEASE_LOCK(?)', [WORKER_LOCK]);
}

/** Enqueue feed_poll jobs for subscribed calendars whose poll interval has elapsed. */
function bc_enqueue_due_polls(Db $db, JobQueue $queue): void
{
    $due = $db->all(
        "SELECT id FROM calendars
         WHERE kind = 'subscribed' AND source_url IS NOT NULL
           AND (last_polled_at IS NULL
                OR last_polled_at <= DATE_SUB(?, INTERVAL poll_interval_minutes MINUTE))",
        [Time::nowDb()]
    );
    foreach ($due as $row) {
        $calendarId = (int) $row['id'];
        if (!$queue->hasActiveFeedPoll($calendarId)) {
            $queue->enqueue('feed_poll', ['calendarId' => $calendarId]);
        }
    }
}

/**
 * Recurring LLM jobs: hourly rank refresh, nightly prompt-filter catch-up.
 * "Recently created" (any status) gates re-enqueue, so failed runs still
 * respect their own backoff instead of piling up.
 */
function bc_enqueue_recurring(Db $db, JobQueue $queue): void
{
    bc_enqueue_if_stale($db, $queue, 'rank_events', 'PT1H');
    bc_enqueue_if_stale($db, $queue, 'filter_eval', 'P1D');
    // Every worker run (cron fires each minute); 50s so the previous run's
    // job never suppresses this minute's scan.
    bc_enqueue_if_stale($db, $queue, 'reminder_scan', 'PT50S');
    // Mail ingest polls the calendar@ mailbox every ~2 minutes.
    bc_enqueue_if_stale($db, $queue, 'mail_ingest', 'PT2M');
    // Activity retention: snapshots kept 7 days, log rows 90 (docs/api-contract.md).
    bc_enqueue_if_stale($db, $queue, 'activity_prune', 'P1D');

    // Plugin jobs: manifests declare intervals; staleness is judged from
    // plugin_runs (a failing job still respects its interval). Cap per tick.
    $duePlugin = array_slice((new BetterCal\Domain\Plugins($db))->dueJobs(), 0, 4);
    foreach ($duePlugin as $dj) {
        $hash = 'plugin:' . $dj['plugin'] . ':' . $dj['job'];
        if (!$queue->hasPendingWithHash('plugin_job', $hash)) {
            $queue->enqueue('plugin_job', ['plugin' => $dj['plugin'], 'job' => $dj['job'], 'hash' => $hash]);
        }
    }
}

function bc_enqueue_if_stale(Db $db, JobQueue $queue, string $type, string $interval): void
{
    $recent = $db->scalar(
        'SELECT id FROM jobs WHERE type = ? AND created_at > ? LIMIT 1',
        [$type, Time::toDb(Time::nowUtc()->sub(new DateInterval($interval)))]
    );
    if ($recent === null) {
        $queue->enqueue($type, []);
    }
}

function bc_ts(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP');
}
