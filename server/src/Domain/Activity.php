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

    /** Sources the API accepts as filters; 'mail', 'plugin' and 'dedup' match every mail:* / plugin:* / dedup:* source too. */
    public const SOURCES = ['web', 'api', 'caldav', 'feed', 'google', 'mail', 'quickadd', 'rsvp', 'import', 'geocode', 'system', 'plugin', 'review', 'dedup'];

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
                if ($s === 'mail' || $s === 'plugin') {
                    $conds[] = "source LIKE '$s:%'";
                } elseif ($s === 'dedup') {
                    $conds[] = "(source = 'dedup' OR source LIKE 'dedup:%')";
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
            'SELECT id, entity, entity_id, op, source, summary, details_json, undone, created_at, format,
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
        $seriesUndo = Settings::googleSeriesUndo($this->db, $userId);
        $entries = array_map(static function (array $r) use ($cutoff, $seriesUndo): array {
            $at = new \DateTimeImmutable((string) $r['created_at'], new \DateTimeZone('UTC'));
            $details = null;
            if ($r['details_json'] !== null) {
                $decoded = json_decode((string) $r['details_json'], true);
                $details = is_array($decoded) ? $decoded : null;
            }
            [$undoable, $why] = self::undoability($r, $details, $seriesUndo, $cutoff);
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
                'undoable' => $undoable,
            ] + ($why !== null ? ['notUndoable' => $why] : []);
        }, $rows);

        return [
            'entries' => $entries,
            'nextBefore' => $more && $entries !== [] ? $entries[count($entries) - 1]['id'] : null,
        ];
    }

    /**
     * Can this journal row be undone now, and the reason it can't when it
     * has one to say: the one test Activity lists by, Review offers a
     * proposal's undo by and a plugin run counts by. $r holds has_snapshot,
     * undone, created_at (UTC) and format; its format part is
     * Undo::olderFormat, the test the loader (Undo::entryToTakeBack) refuses
     * by. Held back by the setting (GoogleUndo::seriesUndoOff) and recorded
     * by an earlier version each say why; past the window or log only don't.
     *
     * @return array{0:bool,1:?string}
     */
    public static function undoability(array $r, ?array $details, bool $seriesUndo, ?\DateTimeImmutable $cutoff = null): array
    {
        $cutoff ??= new \DateTimeImmutable('-' . self::UNDO_DAYS . ' days', new \DateTimeZone('UTC'));
        $at = new \DateTimeImmutable((string) $r['created_at'], new \DateTimeZone('UTC'));
        $heldBack = !$seriesUndo && (int) $r['has_snapshot'] === 1 && GoogleUndo::seriesEntry($details);
        $older = Undo::olderFormat($r);
        $olderWhy = $older && (int) $r['undone'] === 0 ? (is_string($details['notUndoable'] ?? null) ? $details['notUndoable'] : ((int) $r['has_snapshot'] === 1 ? Undo::OLDER_FORMAT : null)) : null;
        $undoable = (int) $r['has_snapshot'] === 1 && (int) $r['undone'] === 0 && $at >= $cutoff && !$heldBack && !$older;
        return [$undoable, $heldBack ? GoogleUndo::SERIES_UNDO_OFF : $olderWhy];
    }

    /**
     * What a run's entries not yet undone allow (Undo::undoRun undoes
     * exactly those): how many there are, how many can be undone now
     * (undoability), and the first reason one can't.
     *
     * @return array{entries:int,undoable:int,why:?string}
     */
    public function runUndoability(int $userId, string $runId): array
    {
        $seriesUndo = Settings::googleSeriesUndo($this->db, $userId);
        $out = ['entries' => 0, 'undoable' => 0, 'why' => null];
        foreach ($this->db->all('SELECT undone, created_at, format, details_json, (before_json IS NOT NULL OR after_json IS NOT NULL) AS has_snapshot
             FROM mutations WHERE user_id = ? AND run_id = ? AND undone = 0', [$userId, $runId]) as $r) {
            $details = json_decode((string) ($r['details_json'] ?? ''), true);
            [$undoable, $why] = self::undoability($r, is_array($details) ? $details : null, $seriesUndo);
            $out['entries']++;
            $out['undoable'] += $undoable ? 1 : 0;
            $out['why'] ??= $undoable ? null : $why;
        }
        return $out;
    }

    /**
     * Retention pass (worker, daily): drop snapshots past the undo window so
     * old entries become log-only, then drop entries past the log window.
     * Then what plugins kept for calendars no undo can bring back any more.
     *
     * @return array{snapshotsCleared:int, deleted:int, pluginRowsForgotten:int}
     */
    public function prune(): array
    {
        $undoCut = (new \DateTimeImmutable('-' . self::UNDO_DAYS . ' days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $logCut = (new \DateTimeImmutable('-' . self::LOG_DAYS . ' days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $cleared = $this->db->run(
            'UPDATE mutations SET before_json = NULL, after_json = NULL
             WHERE created_at < ? AND (before_json IS NOT NULL OR after_json IS NOT NULL)',
            [$undoCut]
        )->rowCount();
        // And the rows a large entry kept in pieces, with them.
        Undo::dropUnusedPieces($this->db);
        // An entry deleted takes its pieces too, said here rather than left
        // to the foreign key's cascade (SQLite applies it only when asked).
        $this->db->run('DELETE FROM mutation_rows WHERE mutation_id IN (SELECT id FROM mutations WHERE created_at < ?)', [$logCut]);
        $deleted =$this->db->run('DELETE FROM mutations WHERE created_at < ?', [$logCut])->rowCount();
        $forgotten = Plugins::forgetGoneCalendars($this->db);
        // Changes half done at Google that can never run (their calendar gone, or kept as a copy by an earlier version): closed.
        $closed = GoogleSeries::closeLeftBehind($this->db);
        return ['snapshotsCleared' => $cleared, 'deleted' => $deleted, 'pluginRowsForgotten' => $forgotten, 'halfDoneClosed' => $closed];
    }
}
