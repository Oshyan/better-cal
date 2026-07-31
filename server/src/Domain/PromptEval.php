<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;
use BetterCal\Infra\LlmGateway;
use BetterCal\Support\Time;

/**
 * Background evaluation of prompt filters against feed events (PRD 5.4/5.8).
 * Runs only from the worker ('filter_eval' jobs), never in the request path.
 * Pending (filter, event) pairs — enabled prompt filters crossed with
 * in-scope future feed events that have no cached verdict — are scored in
 * batches of up to BATCH_SIZE events per Gemini call and cached in
 * filter_evals. Un-evaluated pairs are treated as pass by the read path, so
 * LLM failures never hide or delay anything; the job's backoff retries them.
 */
final class PromptEval
{
    public const BATCH_SIZE = 25;
    /** Cap per filter per enqueue (create/update re-evaluation sweep). */
    public const MAX_EVENTS_PER_FILTER = 500;
    /** LLM calls per job run; leftovers continue in a follow-up job. */
    private const MAX_CALLS_PER_RUN = 4;
    private const DESCRIPTION_EXCERPT_CHARS = 300;

    public function __construct(
        private readonly Db $db,
        private readonly LlmGateway $llm,
        private readonly ?JobQueue $queue = null,
    ) {
    }

    /**
     * Evaluate pending pairs for one filter (create/update sweep) or for all
     * enabled prompt filters (poll hook, nightly catch-up). Returns the number
     * of events evaluated. Throws when one or more LLM batches failed so the
     * job queue retries with backoff; verdicts already written stay written.
     */
    public function run(?int $filterId = null): int
    {
        if (!$this->llm->isConfigured()) {
            return 0; // un-evaluated pairs are passes; nothing to retry
        }
        $params = [];
        $where = "enabled = 1 AND type = 'prompt'";
        if ($filterId !== null) {
            $where .= ' AND id = ?';
            $params[] = $filterId;
        }
        $filters = $this->db->all("SELECT * FROM filters WHERE $where ORDER BY id", $params);

        $evaluated = 0;
        $failures = 0;
        $calls = 0;
        $remaining = false;
        foreach ($filters as $filter) {
            $config = json_decode((string) $filter['config_json'], true);
            if (!is_array($config) || trim((string) ($config['prompt'] ?? '')) === '') {
                continue;
            }
            $pending = $this->pendingEvents($filter);
            foreach (self::batches($pending) as $batch) {
                if ($calls >= self::MAX_CALLS_PER_RUN) {
                    $remaining = true;
                    break 2;
                }
                $calls++;
                $payload = array_map(fn(array $row) => self::eventPayload($row), $batch);
                $raw = $this->llm->evaluateFilterBatch(
                    (string) $config['prompt'],
                    isset($config['negativePrompt']) ? (string) $config['negativePrompt'] : null,
                    $payload
                );
                if ($raw === null) {
                    $failures++;
                    continue; // pairs stay pending; retried via job backoff
                }
                $threshold = isset($config['threshold']) ? (float) $config['threshold'] : null;
                $results = self::validateEvalResponse(['results' => $raw], array_column($payload, 'eventId'));
                foreach ($results as $eventId => $result) {
                    $this->db->upsert('filter_evals', [
                        'filter_id' => (int) $filter['id'],
                        'event_id' => $eventId,
                        'verdict' => self::verdictFor($result, $threshold),
                        'score' => $result['score'],
                        'evaluated_at' => Time::nowDb(),
                    ]);
                    $evaluated++;
                }
            }
        }

        if ($failures > 0) {
            throw new \RuntimeException("$failures prompt-eval LLM batch(es) failed; pairs left pending");
        }
        if ($remaining && $this->queue !== null) {
            // Continue in the next worker minute instead of overrunning this run.
            $this->queue->enqueue('filter_eval', $filterId !== null ? ['filterId' => $filterId] : []);
        }
        return $evaluated;
    }

    /**
     * Feed events in the filter's scope, future (or recurring), with no
     * cached verdict for this filter yet. Most recently added first, capped.
     *
     * @return list<array<string,mixed>>
     */
    private function pendingEvents(array $filter): array
    {
        $params = [(int) $filter['id'], (int) $filter['user_id'], Time::nowDb()];
        $scopeSql = '';
        if ($filter['scope'] === 'calendar') {
            $scopeSql = ' AND e.calendar_id = ?';
            $params[] = (int) $filter['scope_id'];
        } elseif ($filter['scope'] === 'folder') {
            $scopeSql = ' AND e.calendar_id IN (SELECT calendar_id FROM calendar_folders WHERE folder_id = ?)';
            $params[] = (int) $filter['scope_id'];
        }
        return $this->db->all(
            "SELECT e.id, e.title, e.description, e.location, e.start_utc, e.tzid
             FROM events e
             LEFT JOIN filter_evals fe ON fe.filter_id = ? AND fe.event_id = e.id
             WHERE fe.id IS NULL AND e.user_id = ? AND e.source = 'feed' AND e.deleted_at IS NULL
               AND (e.end_utc >= ? OR e.rrule IS NOT NULL)
               $scopeSql
             ORDER BY e.created_at DESC, e.id DESC
             LIMIT " . self::MAX_EVENTS_PER_FILTER,
            $params
        );
    }

    // ---- Pure helpers (unit-tested, no DB / no LLM) --------------------

    /**
     * Chunk items into LLM batches of at most BATCH_SIZE.
     *
     * @return list<list<mixed>>
     */
    public static function batches(array $items): array
    {
        return $items === [] ? [] : array_chunk($items, self::BATCH_SIZE);
    }

    /** Compact event representation sent to the model. */
    public static function eventPayload(array $row): array
    {
        $description = $row['description'] !== null ? trim((string) $row['description']) : null;
        if ($description !== null && mb_strlen($description) > self::DESCRIPTION_EXCERPT_CHARS) {
            $description = mb_substr($description, 0, self::DESCRIPTION_EXCERPT_CHARS) . '…';
        }
        return [
            'eventId' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => $description !== '' ? $description : null,
            'location' => $row['location'] !== null ? (string) $row['location'] : null,
            'start' => Time::dbToIso((string) $row['start_utc'], (string) ($row['tzid'] ?? 'UTC')),
        ];
    }

    /**
     * Validate a raw model response: keep only expected event ids, require a
     * boolean pass, clamp score into [0,1] (null when absent or non-numeric).
     *
     * @param list<int> $expectedEventIds
     * @return array<int,array{pass:bool,score:?float}> eventId => result
     */
    public static function validateEvalResponse(mixed $decoded, array $expectedEventIds): array
    {
        $results = is_array($decoded) && is_array($decoded['results'] ?? null) ? $decoded['results'] : null;
        if ($results === null) {
            return [];
        }
        $expected = array_fill_keys(array_map('intval', $expectedEventIds), true);
        $out = [];
        foreach ($results as $entry) {
            if (!is_array($entry) || !is_numeric($entry['eventId'] ?? null) || !is_bool($entry['pass'] ?? null)) {
                continue;
            }
            $eventId = (int) $entry['eventId'];
            if (!isset($expected[$eventId])) {
                continue;
            }
            $score = is_numeric($entry['score'] ?? null) ? max(0.0, min(1.0, (float) $entry['score'])) : null;
            $out[$eventId] = ['pass' => (bool) $entry['pass'], 'score' => $score];
        }
        return $out;
    }

    /**
     * Verdict for one validated result. With a configured threshold the score
     * decides (score >= threshold passes); without one — or when the model
     * returned no usable score — the boolean pass decides.
     *
     * @param array{pass:bool,score:?float} $result
     */
    public static function verdictFor(array $result, ?float $threshold): string
    {
        if ($threshold !== null && $result['score'] !== null) {
            return $result['score'] >= $threshold ? 'pass' : 'fail';
        }
        return $result['pass'] ? 'pass' : 'fail';
    }
}
