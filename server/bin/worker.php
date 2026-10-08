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
$feeds = new Feeds($db, $queue, $cfg);
$llm = new LlmGateway($cfg);
$ranking = new Ranking($db, $llm, $queue);
$reminders = new Reminders($db, new PushSubscriptions($db), new PushSender($cfg), new EmailSender($cfg), new Recurrence());
// Mail ingest needs the full Events domain (create/patch invited events).
$undo = new BetterCal\Domain\Undo($db);
$labels = new BetterCal\Domain\Labels($db);
$filters = new BetterCal\Domain\Filters($db, $undo, $queue);
$trips = new BetterCal\Domain\Trips($db, $undo);
$eventsDomain = new BetterCal\Domain\Events($db, new Recurrence(), $undo, $labels, $filters, $trips);
$promptEval = new PromptEval(
    $db,
    $llm,
    $queue,
    new BetterCal\Domain\ModelAdmission($db),
    new BetterCal\Domain\ReviewQueue($db, $eventsDomain),
);
$pluginsDomain = new BetterCal\Domain\Plugins($db);
$geocodeSweep = new BetterCal\Domain\GeocodeSweep(
    $db,
    new BetterCal\Domain\Geocode($db, new BetterCal\Infra\PoliciedGeocoderTransport($db)),
    new BetterCal\Domain\Settings($db)
);
$systemHealth = new BetterCal\Domain\SystemHealth($db);
$emailSender = new EmailSender($cfg);

// Job types reported to system_health at the job level, with the label a
// person sees. feed_poll is reported per calendar by Feeds::poll and
// plugin_job per plugin by the plugin runner, so neither is listed: one bad
// feed must not read as "feed polling is broken".
const HEALTH_JOB_LABELS = [
    'filter_eval' => 'Prompt filter evaluation',
    'rank_events' => 'Event ranking',
    'mail_ingest' => 'Mail ingest',
    'reminder_scan' => 'Reminder scan',
    'activity_prune' => 'Activity retention',
    'geocode_sweep' => 'Geocoding',
    'system_alerts' => 'Alert emails',
    'duplicate_scan' => 'Duplicate detection',
];

$locked = $db->scalar('SELECT GET_LOCK(?, 0)', [WORKER_LOCK]);
if ((int) $locked !== 1) {
    exit(0); // another worker is running
}

$started = time();
try {
    foreach ($queue->reapStalled() as $stalledId) {
        echo bc_ts() . " job $stalledId reaped: stuck in running, retried or failed\n";
    }
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
                    $calendarId = isset($payload['calendarId']) ? (int) $payload['calendarId'] : null;
                    $evaluated = $promptEval->run($filterId, $eventIds, $calendarId);
                    echo bc_ts() . ' filter_eval'
                        . ($filterId !== null ? " filter=$filterId" : '')
                        . ($eventIds !== null ? ' events=' . count($eventIds) : '')
                        . ($calendarId !== null ? " calendar=$calendarId" : '')
                        . " evaluated=$evaluated\n";
                    break;
                case 'google_move':
                    // Moving a calendar to Google (#55): up to 40 seconds of
                    // uploading per run; an unfinished move queues itself again.
                    $moveId = (int) ($payload['moveId'] ?? 0);
                    $googleAuth = new BetterCal\Domain\GoogleAuth($db, $cfg);
                    $mover = new BetterCal\Domain\GoogleMove($db, $googleAuth, new BetterCal\Domain\GoogleWriter($db, $googleAuth, $feeds), $feeds, $undo, $queue);
                    $result = $mover->run($moveId, 40);
                    if ($result === BetterCal\Domain\GoogleMove::RESULT_MORE) {
                        $queue->enqueue('google_move', ['moveId' => $moveId]);
                    } elseif ($result === BetterCal\Domain\GoogleMove::RESULT_BUSY) {
                        // The browser request may own the short first lease.
                        // Keep one recovery job so a killed PHP request cannot
                        // strand the move after its lease expires.
                        $queue->enqueue('google_move', ['moveId' => $moveId], Time::nowUtc()->modify('+2 minutes'));
                    }
                    echo bc_ts() . " google_move move=$moveId $result\n";
                    break;
                case 'duplicate_scan':
                    $cursor = isset($payload['cursor']) && is_array($payload['cursor']) ? $payload['cursor'] : [];
                    $dup = (new BetterCal\Domain\Duplicates($db))->scanAllSlice($cursor);
                    $cycleThrottled = !empty($payload['throttled']) || $dup['throttled'];
                    if ($dup['more'] && is_array($dup['cursor'])) {
                        // One slice per worker minute. This leaves room for
                        // reminders, mail and feed jobs even when a calendar
                        // needs many duplicate-detection pages.
                        $queue->enqueue('duplicate_scan', [
                            'cursor' => $dup['cursor'],
                            'throttled' => $cycleThrottled,
                        ], Time::nowUtc()->modify('+1 minute'));
                    } elseif ($cycleThrottled) {
                        $systemHealth->recordFailure(
                            'job:duplicate_scan_budget',
                            'job',
                            null,
                            'Duplicate detection backlog',
                            'One scan cycle reached its safety budget; duplicate suggestions were bounded or deferred.'
                        );
                    } else {
                        $systemHealth->recordOk(
                            'job:duplicate_scan_budget',
                            'job',
                            null,
                            'Duplicate detection backlog',
                            false
                        );
                    }
                    echo bc_ts() . " duplicate_scan linked={$dup['linked']} possible={$dup['possible']}"
                        . " rows={$dup['rows']} comparisons={$dup['comparisons']}"
                        . ($dup['more'] ? ' continued=1' : '')
                        . ($cycleThrottled ? ' bounded=1' : '') . "\n";
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
                        $hereTz = \BetterCal\Infra\LlmGateway::validZone($tzSettings['hereTz'] ?? null);
                        $ingest->pruneReceipts();
                        $done = 0;
                        // begin/abort: a message is recorded as started before its
                        // body is downloaded, so one that crashes the worker is not
                        // walked into again on the next run (see MailFetcher).
                        foreach ($fetcher->fetchUnseen(
                            10,
                            $ingest->beginTransport(...),
                            $ingest->identifyTransport(...),
                            $ingest->abortTransport(...)
                        ) as $msg) {
                            $r = $ingest->ingestMessage($uid, $msg, $tz, $hereTz);
                            // A held change waits for a decision, and the
                            // owner is not looking at the queue: tell them. One
                            // notification tag per existing meeting; new
                            // invitations share a queue tag so a burst replaces
                            // the prior notice instead of buzzing repeatedly.
                            if ($r['outcome'] === 'held') {
                                try {
                                    $isNewInvitation = $r['eventId'] === null;
                                    $heldTitle = $isNewInvitation
                                        ? 'an emailed invitation'
                                        : (string) ($db->scalar('SELECT title FROM events WHERE id = ?', [(int) $r['eventId']]) ?? 'an invitation');
                                    (new \BetterCal\Infra\Notifier($db, $cfg))->send(
                                        $uid,
                                        $isNewInvitation ? 'New emailed invitation to review' : 'An organizer changed "' . $heldTitle . '"',
                                        $isNewInvitation
                                            ? 'It has not been added to your calendar. Review it to add or dismiss.'
                                            : 'Nothing has changed on your calendar yet. Review it to accept or dismiss.',
                                        '/review',
                                        $isNewInvitation ? 'review-new-invitation' : 'review-' . (int) $r['eventId']
                                    );
                                } catch (\Throwable $e) {
                                    echo bc_ts() . ' mail_ingest notify failed: ' . $e->getMessage() . "\n";
                                }
                            }
                            echo bc_ts() . ' mail_ingest msg=' . substr($msg['messageId'], 0, 40)
                                . ' tier=' . ($r['tier'] ?? '-') . ' outcome=' . $r['outcome']
                                . ($r['error'] !== null ? ' reason=' . str_replace(["\r", "\n"], ' ', (string) $r['error']) : '')
                                . "\n";
                            $done++;
                        }
                        if ($done === 0) {
                            echo bc_ts() . " mail_ingest idle\n";
                        }
                    }
                    break;
                case 'reminder_scan':
                    $cursor = isset($payload['cursor']) && is_array($payload['cursor']) ? $payload['cursor'] : [];
                    try {
                        $scanNow = isset($payload['scanNow']) && is_string($payload['scanNow'])
                            ? Time::fromDb($payload['scanNow'])
                            : Time::nowUtc();
                    } catch (\Throwable) {
                        $scanNow = Time::nowUtc();
                    }
                    $cycleId = isset($payload['cycleId']) && is_string($payload['cycleId'])
                        ? substr($payload['cycleId'], 0, 40)
                        : bin2hex(random_bytes(12));
                    $result = $reminders->scan($scanNow, $cursor);
                    if ($result['more'] && is_array($result['cursor'])) {
                        // Continue promptly inside this worker's 50-second
                        // envelope. If it runs out, the pending slice resumes
                        // next minute. The stable cycle clock prevents later
                        // slices from aging past the reminder grace window;
                        // notified_instances key makes replay after a crash
                        // safe, and the durable cursor prevents a dense prefix
                        // from starving later reminders.
                        $queue->enqueue('reminder_scan', [
                            'cycleId' => $cycleId,
                            'scanNow' => Time::toDb($scanNow),
                            'cursor' => $result['cursor'],
                        ]);
                    }
                    echo bc_ts() . ' reminder_scan sent=' . $result['sent'] . ' failed=' . $result['failed']
                        . ' emailed=' . ($result['emailed'] ?? 0)
                        . ($result['more'] ? ' continued=1' : '') . "\n";
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
                case 'system_alerts':
                    $r = $systemHealth->sweepAlerts($emailSender, $cfg['alert_email'] ?? null);
                    if ($r['failureEmails'] + $r['recoveryEmails'] > 0 || $r['skipped'] !== null) {
                        echo bc_ts() . " system_alerts failure_emails={$r['failureEmails']} recovery_emails={$r['recoveryEmails']}"
                            . ($r['skipped'] !== null ? " skipped={$r['skipped']}" : '') . "\n";
                    }
                    break;
                case 'geocode_sweep':
                    if ($geocodeSweep->hasCandidates()) {
                        $r = $geocodeSweep->run();
                        echo bc_ts() . " geocode_sweep groups={$r['groups']} lookups={$r['lookups']} resolved={$r['resolved']}"
                            . " unplaced={$r['unplaced']}" . ($r['transient'] ? ' transient=1' : '') . " in {$r['seconds']}s\n";
                    }
                    break;
                case 'activity_prune':
                    $result = (new BetterCal\Domain\Activity($db))->prune();
                    $modelAdmissions = (new BetterCal\Domain\ModelAdmission($db))->prune();
                    echo bc_ts() . ' activity_prune cleared=' . $result['snapshotsCleared']
                        . ' deleted=' . $result['deleted'] . ' model_admissions=' . $modelAdmissions . "\n";
                    break;
                default:
                    throw new \RuntimeException('Unknown job type: ' . $job['type']);
            }
            $queue->markDone((int) $job['id']);
            if (isset(HEALTH_JOB_LABELS[(string) $job['type']])) {
                $systemHealth->recordOk('job:' . $job['type'], 'job', null, HEALTH_JOB_LABELS[(string) $job['type']]);
            }
        } catch (\Throwable $e) {
            if ((string) $job['type'] === 'feed_poll'
                && $e instanceof BetterCal\Http\HttpError
                && $e->errorCode === 'subscription_authorization_revoked'
            ) {
                // This is a durable security stop, not a transient fetch
                // failure. Complete the stale queued job without retries.
                $queue->markDone((int) $job['id']);
                echo bc_ts() . ' feed_poll skipped: ' . $e->getMessage() . "\n";
            } else {
                $queue->markFailed($job, $e->getMessage());
                if (isset(HEALTH_JOB_LABELS[(string) $job['type']])) {
                    $systemHealth->recordFailure('job:' . $job['type'], 'job', null, HEALTH_JOB_LABELS[(string) $job['type']], $e->getMessage());
                }
                fwrite(STDERR, bc_ts() . ' job ' . $job['id'] . ' (' . $job['type'] . ') failed: ' . $e->getMessage() . "\n");
            }
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
        "SELECT c.id FROM calendars c
         LEFT JOIN google_accounts ga ON ga.id = c.google_account_id
         LEFT JOIN api_tokens creator_token ON creator_token.id = c.created_by_token_id AND creator_token.user_id = c.user_id
         WHERE c.kind = 'subscribed'
           AND ((COALESCE(c.provider, 'ics') <> 'google' AND c.source_url IS NOT NULL
                 AND (c.subscription_authority = 'owner'
                      OR (c.subscription_authority = 'token' AND creator_token.id IS NOT NULL
                          AND (creator_token.expires_at IS NULL OR creator_token.expires_at > ?))))
                OR (c.provider = 'google' AND ga.reauth_required_at IS NULL))
           AND (c.last_polled_at IS NULL
                OR c.last_polled_at <= DATE_SUB(?, INTERVAL poll_interval_minutes MINUTE))",
        [Time::nowDb(), Time::nowDb()]
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
    // A dense reminder cycle carries a durable cursor across jobs. Never seed
    // another root while any slice is pending/running: parallel cursor chains
    // would repeatedly rescan the prefix and grow the priority queue.
    if (!$queue->hasPending('reminder_scan')) {
        $now = Time::nowUtc();
        bc_enqueue_if_stale($db, $queue, 'reminder_scan', 'PT50S', [
            'cycleId' => bin2hex(random_bytes(12)),
            'scanNow' => Time::toDb($now),
        ]);
    }
    // Mail ingest polls the calendar@ mailbox every ~2 minutes.
    bc_enqueue_if_stale($db, $queue, 'mail_ingest', 'PT2M');
    // Activity retention: snapshots kept 7 days, log rows 90 (docs/api-contract.md).
    bc_enqueue_if_stale($db, $queue, 'activity_prune', 'P1D');
    // Coordinates for anything with an address and none yet, upcoming first.
    // Every tick; the job itself is a no-op when there is nothing to place.
    bc_enqueue_if_stale($db, $queue, 'geocode_sweep', 'PT50S');
    // Alert emails: ten minutes, so a broken SMTP is one failed attempt per
    // ten minutes rather than per minute. The decision of WHAT to email is
    // SystemHealth::shouldAlert; this is only how often it is asked.
    bc_enqueue_if_stale($db, $queue, 'system_alerts', 'PT10M');
    // The same event arriving twice (#9). Feeds poll every few minutes at
    // most, so a duplicate shows as one within ten.
    bc_enqueue_if_stale($db, $queue, 'duplicate_scan', 'PT10M');

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

function bc_enqueue_if_stale(Db $db, JobQueue $queue, string $type, string $interval, array $payload = []): void
{
    $recent = $db->scalar(
        'SELECT id FROM jobs WHERE type = ? AND created_at > ? LIMIT 1',
        [$type, Time::toDb(Time::nowUtc()->sub(new DateInterval($interval)))]
    );
    if ($recent === null) {
        $queue->enqueue($type, $payload);
    }
}

function bc_ts(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP');
}
