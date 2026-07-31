<?php

declare(strict_types=1);

// Cron worker, run every minute: enqueues due feed polls and drains the job
// queue. Overlap-safe via MySQL GET_LOCK.

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Domain\Feeds;
use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;
use BetterCal\Support\Time;

const WORKER_LOCK = 'bettercal_worker';
const MAX_RUNTIME_SECONDS = 50;

$cfg = config();
$db = new Db($cfg['db']);
$queue = new JobQueue($db);
$feeds = new Feeds($db);

$locked = $db->scalar('SELECT GET_LOCK(?, 0)', [WORKER_LOCK]);
if ((int) $locked !== 1) {
    exit(0); // another worker is running
}

$started = time();
try {
    bc_enqueue_due_polls($db, $queue);

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

function bc_ts(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP');
}
