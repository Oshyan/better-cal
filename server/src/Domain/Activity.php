<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;

/**
 * Activity log reads + retention. The write side lives in Undo::record()
 * (every mutating code path already journals there); this class turns those
 * rows into the latest-first feed the Activity page renders, and enforces
 * retention: snapshots (undo capability) live UNDO_DAYS, the log itself
 * LOG_DAYS.
 */
final class Activity
{
    public const UNDO_DAYS = 7;
    public const LOG_DAYS = 90;
    public const MAX_LIMIT = 100;

    /** Sources the API accepts as filters; 'mail' matches every mail:* tier. */
    public const SOURCES = ['web', 'api', 'caldav', 'feed', 'mail', 'quickadd', 'rsvp', 'import'];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Latest-first page of activity entries.
     *
     * @param array{before?:?int, limit?:int, sources?:?list<string>, q?:?string} $opts
     * @return array{entries:list<array<string,mixed>>, nextBefore:?int}
     */
    public function list(int $userId, array $opts = []): array
    {
        $limit = max(1, min((int) ($opts['limit'] ?? 50), self::MAX_LIMIT));
        $where = ['user_id = ?'];
        $params = [$userId];

        if (isset($opts['before']) && $opts['before'] !== null && (int) $opts['before'] > 0) {
            $where[] = 'id < ?';
            $params[] = (int) $opts['before'];
        }

        $sources = $opts['sources'] ?? null;
        if (is_array($sources) && $sources !== []) {
            $conds = [];
            foreach ($sources as $s) {
                if (!in_array($s, self::SOURCES, true)) {
                    continue;
                }
                if ($s === 'mail') {
                    $conds[] = "source LIKE 'mail:%'";
                } else {
                    $conds[] = 'source = ?';
                    $params[] = $s;
                }
            }
            if ($conds !== []) {
                $where[] = '(' . implode(' OR ', $conds) . ')';
            }
        }

        $q = isset($opts['q']) && is_string($opts['q']) ? trim($opts['q']) : '';
        if ($q !== '') {
            $where[] = 'summary LIKE ?';
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }

        // LIMIT is inlined ($limit is clamped above): execute()-bound params
        // arrive as strings, which MySQL rejects in a LIMIT clause.
        $fetch = $limit + 1; // one extra row = "there is another page"
        $rows = $this->db->all(
            'SELECT id, entity, entity_id, op, source, summary, details_json, undone, created_at,
                    (before_json IS NOT NULL OR after_json IS NOT NULL) AS has_snapshot
             FROM mutations WHERE ' . implode(' AND ', $where) . "
             ORDER BY id DESC LIMIT $fetch",
            $params
        );

        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }
        $cutoff = new \DateTimeImmutable('-' . self::UNDO_DAYS . ' days', new \DateTimeZone('UTC'));
        $entries = array_map(static function (array $r) use ($cutoff): array {
            $at = new \DateTimeImmutable((string) $r['created_at'], new \DateTimeZone('UTC'));
            $details = null;
            if ($r['details_json'] !== null) {
                $decoded = json_decode((string) $r['details_json'], true);
                $details = is_array($decoded) ? $decoded : null;
            }
            return [
                'id' => (int) $r['id'],
                'at' => $at->format('Y-m-d\TH:i:sP'),
                'source' => (string) ($r['source'] ?? 'web'),
                'entity' => (string) $r['entity'],
                'entityId' => (int) $r['entity_id'],
                'op' => (string) $r['op'],
                'summary' => $r['summary'] !== null ? (string) $r['summary'] : '',
                'details' => $details,
                'undone' => (int) $r['undone'] === 1,
                'undoable' => (int) $r['has_snapshot'] === 1 && (int) $r['undone'] === 0 && $at >= $cutoff,
            ];
        }, $rows);

        return [
            'entries' => $entries,
            'nextBefore' => $more && $entries !== [] ? $entries[count($entries) - 1]['id'] : null,
        ];
    }

    /**
     * Retention pass (worker, daily): drop snapshots past the undo window so
     * old entries become log-only, then drop entries past the log window.
     *
     * @return array{snapshotsCleared:int, deleted:int}
     */
    public function prune(): array
    {
        $undoCut = (new \DateTimeImmutable('-' . self::UNDO_DAYS . ' days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $logCut = (new \DateTimeImmutable('-' . self::LOG_DAYS . ' days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $cleared = $this->db->run(
            'UPDATE mutations SET before_json = NULL, after_json = NULL
             WHERE created_at < ? AND (before_json IS NOT NULL OR after_json IS NOT NULL)',
            [$undoCut]
        );
        $deleted = $this->db->run('DELETE FROM mutations WHERE created_at < ?', [$logCut]);
        return ['snapshotsCleared' => $cleared, 'deleted' => $deleted];
    }
}
