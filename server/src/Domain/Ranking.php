<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;
use BetterCal\Infra\LlmGateway;
use BetterCal\Support\Time;

/**
 * Trainable ranking (PRD 5.8): thumbs up/down feedback signals plus
 * attendance-derived signals train a score on future feed events. Runs only
 * from the worker ('rank_events' jobs: hourly and after each feed poll),
 * never in the request path. With fewer than MIN_SIGNALS signals nothing is
 * scored (cold start); LLM failures leave events unscored and retry via job
 * backoff.
 */
final class Ranking
{
    public const BATCH_SIZE = 25;
    public const MIN_SIGNALS = 5;
    public const MAX_EXAMPLES = 30;
    /** LLM calls per job run; leftovers continue in a follow-up job. */
    private const MAX_CALLS_PER_RUN = 4;

    public function __construct(
        private readonly Db $db,
        private readonly LlmGateway $llm,
        private readonly ?JobQueue $queue = null,
        private readonly ?ModelAdmission $admission = null,
        private readonly ?ReviewQueue $review = null,
    ) {
    }

    /**
     * Score unscored/stale future feed events for every user with enough
     * signals. Returns the number of events scored; throws when LLM batches
     * failed so the job retries with backoff.
     */
    public function run(): int
    {
        if (!$this->llm->isConfigured()) {
            return 0;
        }
        $scored = 0;
        $failures = 0;
        $calls = 0;
        $remaining = false;
        $admission = $this->admission ?? new ModelAdmission($this->db);
        $users = $this->db->all(
            'SELECT user_id, COUNT(*) AS n, MAX(created_at) AS latest FROM feedback_signals GROUP BY user_id'
        );
        foreach ($users as $user) {
            if ((int) $user['n'] < self::MIN_SIGNALS) {
                continue; // cold start: no scoring until enough signal exists
            }
            $userId = (int) $user['user_id'];
            $examples = self::signalExamples($this->db->all(
                'SELECT fs.event_id, fs.kind, e.title
                 FROM feedback_signals fs JOIN events e ON e.id = fs.event_id
                 WHERE fs.user_id = ? ORDER BY fs.id DESC LIMIT 100',
                [$userId]
            ));
            if ($examples === []) {
                continue;
            }
            // Pick calendars before the candidate limit: a saturated feed's
            // first hundred events must not hide every other calendar.
            $calendars = $this->db->all("SELECT id, name FROM calendars WHERE user_id = ? ORDER BY id", [$userId]);
            foreach ($calendars as $calendar) {
                $candidates = $this->staleEvents($userId, (string) $user['latest'], (int) $calendar['id']);
                foreach (PromptEval::batches($candidates) as $batch) {
                    if ($calls >= self::MAX_CALLS_PER_RUN) {
                        $remaining = true;
                        break 3;
                    }
                    $reservation = $admission->reserveRanking($userId, (int) $calendar['id']);
                    if (!$reservation['allowed']) {
                        $this->review?->holdModelLimit($userId, 'ranking_' . $reservation['code'], (string) $reservation['message'],
                            (string) $reservation['retryAt'], (int) $calendar['id'], (string) $calendar['name'], count($batch), 'ranking');
                        if (str_starts_with((string) $reservation['code'], 'model_account_') || $reservation['code'] === 'model_admission_unavailable') continue 3;
                        break; // another calendar can still have capacity
                    }
                    $calls++;
                    $payload = array_map(static fn(array $row) => PromptEval::eventPayload($row), $batch);
                    try {
                        $raw = $this->llm->rankEvents($examples, $payload);
                    } finally {
                        $admission->finish($reservation['reservationId']);
                    }
                    if ($raw === null) {
                        $failures++;
                        continue; // events stay unscored/stale; retried via job backoff
                    }
                    $scores = self::validateRankResponse(['results' => $raw], array_column($payload, 'eventId'));
                    foreach ($scores as $eventId => $score) {
                        $this->db->update('events', ['score' => $score, 'scored_at' => Time::nowDb()], 'id = ?', [$eventId]);
                        $scored++;
                    }
                }
            }
        }

        if ($failures > 0) {
            throw new \RuntimeException("$failures ranking LLM batch(es) failed; events left unscored");
        }
        if ($remaining && $this->queue !== null) {
            $this->queue->enqueue('rank_events', []);
        }
        return $scored;
    }

    /** Record an explicit thumbs signal ('up'|'down') for an event. */
    public function recordSignal(int $userId, int $eventId, string $kind): void
    {
        $this->db->insert('feedback_signals', [
            'user_id' => $userId,
            'event_id' => $eventId,
            'kind' => $kind,
        ]);
    }

    /**
     * Future feed events never scored, edited since scoring, or scored before
     * the newest signal arrived (new signals invalidate old scores).
     *
     * @return list<array<string,mixed>>
     */
    private function staleEvents(int $userId, string $latestSignalAt, int $calendarId): array
    {
        return $this->db->all(
            "SELECT id, title, description, location, start_utc, tzid FROM events
             WHERE user_id = ? AND calendar_id = ? AND source = 'feed' AND deleted_at IS NULL
               AND attendance <> 'hidden'
               AND (end_utc >= ? OR rrule IS NOT NULL)
               AND (scored_at IS NULL OR scored_at < updated_at OR scored_at < ?)
             ORDER BY start_utc, id
             LIMIT " . (self::BATCH_SIZE * self::MAX_CALLS_PER_RUN),
            [$userId, $calendarId, Time::nowDb(), $latestSignalAt]
        );
    }

    // ---- Pure helpers (unit-tested, no DB / no LLM) --------------------

    /**
     * Serialize signal rows (newest first: event_id, kind, title) into compact
     * LLM examples: one entry per event (latest signal wins), 'hide' folded
     * into 'down', capped at $max.
     *
     * @param list<array{event_id:int|string,kind:string,title:string}> $rows
     * @return list<array{title:string,signal:'up'|'down'}>
     */
    public static function signalExamples(array $rows, int $max = self::MAX_EXAMPLES): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $eventId = (int) $row['event_id'];
            if (isset($seen[$eventId])) {
                continue;
            }
            $seen[$eventId] = true;
            $title = trim((string) $row['title']);
            if ($title === '') {
                continue;
            }
            $out[] = [
                'title' => $title,
                'signal' => (string) $row['kind'] === 'up' ? 'up' : 'down',
            ];
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    /**
     * Validate a raw model response: keep only expected event ids, require a
     * finite numeric score in [0,1], and reject duplicate IDs.
     *
     * @param list<int> $expectedEventIds
     * @return array<int,float> eventId => score
     */
    public static function validateRankResponse(mixed $decoded, array $expectedEventIds): array
    {
        $results = is_array($decoded) && is_array($decoded['results'] ?? null) ? $decoded['results'] : null;
        if ($results === null) {
            return [];
        }
        $expected = array_fill_keys(array_map('intval', $expectedEventIds), true);
        $out = [];
        $seen = [];
        foreach ($results as $entry) {
            if (!is_array($entry) || !is_int($entry['eventId'] ?? null)) {
                continue;
            }
            $eventId = $entry['eventId'];
            if (!isset($expected[$eventId])) {
                continue;
            }
            if (isset($seen[$eventId])) { unset($out[$eventId]); continue; }
            $seen[$eventId] = true;
            $score = $entry['score'] ?? null;
            if ((!is_int($score) && !is_float($score)) || !is_finite((float) $score) || $score < 0 || $score > 1) continue;
            $out[$eventId] = (float) $score;
        }
        return $out;
    }
}
