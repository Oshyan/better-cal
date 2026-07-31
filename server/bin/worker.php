<?php

declare(strict_types=1);

// Cron worker, run every minute: enqueues due feed polls and drains the job
// queue. Overlap-safe via MySQL GET_LOCK.

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Domain\Feeds;
use BetterCal\Domain\PromptEval;
use BetterCal\Domain\Ranking;
use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;
use BetterCal\Infra\LlmGateway;
use BetterCal\Support\Time;

const WORKER_LOCK = 'bettercal_worker';
const MAX_RUNTIME_SECONDS = 50;

$cfg = config();
$db = new Db($cfg['db']);
$queue = new JobQueue($db);
$feeds = new Feeds($db, $queue);
$llm = new LlmGateway($cfg);
$promptEval = new PromptEval($db, $llm, $queue);
$ranking = new Ranking($db, $llm, $queue);

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
