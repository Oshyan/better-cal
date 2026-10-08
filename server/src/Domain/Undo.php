<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Dav\ChangeLog;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

/**
 * Mutation log + undo. Each mutating API call records before/after snapshots
 * as {"tables": {table: [rows...]}}. Undo restores every before-row (upsert)
 * and deletes rows that exist only in after (i.e. rows the mutation created).
 */
final class Undo
{
    /** Restore order respects FK dependencies; deletions run in reverse. */
    private const TABLE_ORDER = [
        'folders', 'tags', 'people', 'calendars', 'calendar_folders', 'calendar_tags',
        'events', 'event_links', 'event_tags', 'event_people', 'out_feeds', 'filters', 'saved_views',
        'availability',
    ];

    private const TABLE_PK = [
        'folders' => ['id'], 'tags' => ['id'], 'people' => ['id'], 'calendars' => ['id'],
        'events' => ['id'], 'out_feeds' => ['id'], 'filters' => ['id'], 'saved_views' => ['id'],
        'event_links' => ['id'], 'availability' => ['id'],
        'calendar_folders' => ['calendar_id', 'folder_id'],
        'calendar_tags' => ['calendar_id', 'tag_id'],
        'event_tags' => ['event_id', 'tag_id'],
        'event_people' => ['event_id', 'person_id'],
    ];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array<string,list<array>>|null $beforeTables
     * @param array<string,list<array>>|null $afterTables
     */
    public function record(int $userId, string $entity, int $entityId, string $op, ?array $beforeTables, ?array $afterTables, ?string $summary = null, ?array $details = null, array $calendarIds = []): void
    {
        $mutationId = 0;
        try {
            $mutationId = $this->db->insert('mutations', [
                'user_id' => $userId,
                'entity' => $entity,
                'entity_id' => $entityId,
                'op' => $op,
                'before_json' => $beforeTables === null ? null : json_encode(['tables' => $beforeTables], JSON_INVALID_UTF8_SUBSTITUTE),
                'after_json' => $afterTables === null ? null : json_encode(['tables' => $afterTables], JSON_INVALID_UTF8_SUBSTITUTE),
                'source' => ActivityContext::get(),
                'run_id' => ActivityContext::runId(),
                'summary' => mb_substr($summary ?? self::defaultSummary($entity, $op, $beforeTables, $afterTables), 0, 300),
                'details_json' => $details === null ? null : json_encode($details, JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
        } catch (\PDOException $e) {
            // Pre-011 schema during a rolling deploy: fall back to core columns.
            $mutationId = $this->db->insert('mutations', [
                'user_id' => $userId,
                'entity' => $entity,
                'entity_id' => $entityId,
                'op' => $op,
                'before_json' => $beforeTables === null ? null : json_encode(['tables' => $beforeTables], JSON_INVALID_UTF8_SUBSTITUTE),
                'after_json' => $afterTables === null ? null : json_encode(['tables' => $afterTables], JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
        }
        $this->recordCalendarRefs($mutationId, $beforeTables, $afterTables, $calendarIds);
    }

    /** @param list<int> $explicitCalendarIds */
    private function recordCalendarRefs(int $mutationId, ?array $beforeTables, ?array $afterTables, array $explicitCalendarIds = []): void
    {
        $ids = array_map('intval', $explicitCalendarIds);
        foreach ([$beforeTables, $afterTables] as $tables) {
            if (!is_array($tables)) {
                continue;
            }
            foreach ($tables['events'] ?? [] as $row) {
                if (is_array($row) && isset($row['calendar_id'])) {
                    $ids[] = (int) $row['calendar_id'];
                }
            }
            foreach ($tables['calendars'] ?? [] as $row) {
                if (is_array($row) && isset($row['id'])) {
                    $ids[] = (int) $row['id'];
                }
            }
        }
        try {
            foreach (array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0))) as $calendarId) {
                $this->db->insert('mutation_calendar_refs', [
                    'mutation_id' => $mutationId,
                    'calendar_id' => $calendarId,
                ]);
            }
            if ($ids !== []) {
                [$in, $params] = Db::in(array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0))));
                $googleBacked = $params !== [] && $this->db->scalar(
                    "SELECT id FROM calendars WHERE id IN $in AND kind = 'subscribed' AND provider = 'google' LIMIT 1",
                    $params
                ) !== null;
                if ($googleBacked) {
                    // Google-backed edits are activity-only by design. Make
                    // that permanent so adopt-back-to-local cannot revive a
                    // provider-cache snapshot created after the move.
                    $this->db->update('mutations', ['before_json' => null, 'after_json' => null], 'id = ?', [$mutationId]);
                }
            }
        } catch (\PDOException $e) {
            $message = strtolower($e->getMessage());
            if ((string) $e->getCode() === '42S02'
                || str_contains($message, 'no such table: mutation_calendar_refs')
                || str_contains($message, 'no such table: calendars')) {
                return; // rolling-install and narrow-test compatibility
            }
            throw $e;
        }
    }

    /**
     * Human line for the activity feed when the caller didn't provide one:
     * "Added event 'Dinner at Luna' (Aug 14)". Pure.
     */
    public static function defaultSummary(string $entity, string $op, ?array $before, ?array $after): string
    {
        $verb = ['create' => 'Added', 'update' => 'Updated', 'delete' => 'Deleted'][$op] ?? ucfirst($op);
        $noun = str_replace('_', ' ', $entity);
        $tableByEntity = ['event' => 'events', 'calendar' => 'calendars', 'folder' => 'folders',
            'filter' => 'filters', 'saved_view' => 'saved_views', 'person' => 'people', 'availability' => 'availability'];
        $table = $tableByEntity[$entity] ?? null;
        $row = null;
        foreach ([$after, $before] as $side) {
            if ($table !== null && isset($side[$table][0]) && is_array($side[$table][0])) {
                $row = $side[$table][0];
                break;
            }
        }
        $name = is_array($row) ? (string) ($row['title'] ?? $row['name'] ?? '') : '';
        $line = $verb . ' ' . $noun . ($name !== '' ? " '" . $name . "'" : '');
        if ($entity === 'event' && is_array($row) && isset($row['start_utc'])) {
            try {
                $line .= ' (' . (new \DateTimeImmutable((string) $row['start_utc'], new \DateTimeZone('UTC')))->format('M j') . ')';
            } catch (\Exception) {
            }
        }
        return $line;
    }

    /** @return array{entity:string,op:string} */
    public function undoLatest(int $userId): array
    {
        $mutation = $this->db->one(
            // A creation has only an after snapshot; it is undone by deleting
            // what it made. Requiring a before snapshot skipped it and undid
            // the change before it instead. Log-only rows have neither.
            'SELECT * FROM mutations WHERE user_id = ? AND undone = 0 AND (before_json IS NOT NULL OR after_json IS NOT NULL) ORDER BY id DESC LIMIT 1',
            [$userId]
        );
        if ($mutation === null) {
            throw HttpError::notFound('Nothing to undo', 'nothing_to_undo');
        }
        return $this->apply($mutation);
    }

    /**
     * Undo one specific activity entry (docs: 7-day window). Refuses
     * log-only rows (no snapshots, or snapshots already pruned) and, unless
     * $force, rows whose entity has newer un-undone mutations (undoing under
     * those would silently clobber later edits).
     *
     * @return array{entity:string,op:string}
     */
    public function undoById(int $userId, int $mutationId, bool $force = false): array
    {
        $mutation = $this->db->one(
            'SELECT * FROM mutations WHERE id = ? AND user_id = ?',
            [$mutationId, $userId]
        );
        if ($mutation === null) {
            throw HttpError::notFound('No such activity entry');
        }
        if ((int) $mutation['undone'] === 1) {
            throw HttpError::badRequest('Already undone', 'already_undone');
        }
        if ($mutation['before_json'] === null && $mutation['after_json'] === null) {
            throw HttpError::badRequest('This entry is log-only and cannot be undone (automated or older than the 7-day undo window)', 'not_undoable');
        }
        if (!$force) {
            $newer = $this->db->scalar(
                'SELECT id FROM mutations WHERE user_id = ? AND entity = ? AND entity_id = ? AND id > ? AND undone = 0 LIMIT 1',
                [$userId, (string) $mutation['entity'], (int) $mutation['entity_id'], $mutationId]
            );
            if ($newer !== null) {
                throw HttpError::badRequest('Newer changes exist for this item; undoing would overwrite them', 'stale_undo');
            }
        }
        return $this->apply($mutation);
    }

    /**
     * Undo every mutation a plugin run made, newest first. In strict mode,
     * each entry keeps the stale-undo guard: newer entries from this same run
     * have already been marked undone, while a later owner edit remains live
     * and aborts the group instead of being overwritten.
     * Strict mode lets a caller wrap the whole group in one outer transaction:
     * the first non-undoable entry aborts instead of committing a partial undo.
     *
     * @return array{undone:int,skipped:int}
     */
    public function undoRun(int $userId, string $runId, bool $strict = false): array
    {
        $rows = $this->db->all(
            'SELECT id FROM mutations WHERE user_id = ? AND run_id = ? AND undone = 0 ORDER BY id DESC',
            [$userId, $runId]
        );
        if ($strict && $rows === []) {
            throw HttpError::badRequest(
                'This run no longer has undo records',
                'not_undoable'
            );
        }
        $undone = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            try {
                $this->undoById($userId, (int) $row['id'], !$strict);
                $undone++;
            } catch (\Throwable $e) {
                if ($strict) {
                    throw $e;
                }
                $skipped++; // log-only entries and already-undone rows
            }
        }
        return ['undone' => $undone, 'skipped' => $skipped];
    }

    /** @return array{entity:string,op:string} */
    private function apply(array $mutation): array
    {

        $before = self::tables($mutation['before_json']);
        $after = self::tables($mutation['after_json']);
        $calendarIds = [];
        foreach ([$before, $after] as $snapshot) {
            foreach ($snapshot['events'] ?? [] as $row) {
                if (isset($row['calendar_id'])) {
                    $calendarIds[] = (int) $row['calendar_id'];
                }
            }
            foreach ($snapshot['calendars'] ?? [] as $row) {
                if (isset($row['id'])) {
                    $calendarIds[] = (int) $row['id'];
                }
            }
        }

        $this->db->tx(function () use ($mutation, $before, $after, $calendarIds): void {
            CalendarMoveGuard::lockAndAssertMutable($this->db, $calendarIds);
            foreach (array_values(array_unique($calendarIds)) as $calendarId) {
                $calendar = CalendarMoveGuard::lockCalendar($this->db, $calendarId);
                if ($calendar !== null && (string) $calendar['kind'] === 'subscribed'
                    && (string) ($calendar['provider'] ?? '') === 'google') {
                    // Google-backed rows are a provider cache. Restoring any
                    // old full-row snapshot locally would be overwritten on
                    // poll and can resurrect the pre-move source/provider
                    // state, including cross-calendar trip snapshots that a
                    // cutover could not find by mutation.entity_id alone.
                    throw HttpError::badRequest('Google calendar changes cannot be undone here', 'not_undoable');
                }
            }
            // GoogleMove finalization clears undo snapshots under the same
            // calendar lock. Re-read after acquiring it so an undo request
            // that began just before cutover cannot apply its stale copy to
            // the newly Google-backed cache.
            $current = $this->db->one(
                'SELECT before_json, after_json, undone FROM mutations WHERE id = ?',
                [$mutation['id']]
            );
            if ($current === null || (int) $current['undone'] === 1
                || ($current['before_json'] === null && $current['after_json'] === null)
                || $current['before_json'] !== $mutation['before_json']
                || $current['after_json'] !== $mutation['after_json']) {
                throw HttpError::badRequest('This entry can no longer be undone', 'not_undoable');
            }
            // Delete rows created by the mutation (present in after, absent from before).
            foreach (array_reverse(self::TABLE_ORDER) as $table) {
                $beforeKeys = [];
                foreach ($before[$table] ?? [] as $row) {
                    $beforeKeys[$this->pkKey($table, $row)] = true;
                }
                foreach ($after[$table] ?? [] as $row) {
                    if (!isset($beforeKeys[$this->pkKey($table, $row)])) {
                        $this->deleteByPk($table, $row);
                    }
                }
            }
            // Restore original rows.
            foreach (self::TABLE_ORDER as $table) {
                foreach ($before[$table] ?? [] as $row) {
                    $this->db->upsert($table, $row);
                }
            }
            $this->db->run('UPDATE mutations SET undone = 1 WHERE id = ?', [$mutation['id']]);
        });

        // CalDAV journal: an undone event mutation must reach DAV clients too.
        // Recording MODIFY is enough — sync clients drop uris that 404.
        $pairs = [];
        foreach ([$before['events'] ?? [], $after['events'] ?? []] as $rows) {
            foreach ($rows as $row) {
                if (isset($row['calendar_id'], $row['uid'])) {
                    $pairs[$row['calendar_id'] . '|' . $row['uid']] = [(int) $row['calendar_id'], (string) $row['uid']];
                }
            }
        }
        foreach ($pairs as [$calendarId, $uid]) {
            ChangeLog::record($this->db, $calendarId, $uid, ChangeLog::OP_MODIFY);
        }

        return ['entity' => (string) $mutation['entity'], 'op' => (string) $mutation['op']];
    }

    private static function tables(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) && isset($decoded['tables']) && is_array($decoded['tables']) ? $decoded['tables'] : [];
    }

    private function pkKey(string $table, array $row): string
    {
        $parts = [];
        foreach (self::TABLE_PK[$table] ?? ['id'] as $col) {
            $parts[] = (string) ($row[$col] ?? '');
        }
        return implode('|', $parts);
    }

    private function deleteByPk(string $table, array $row): void
    {
        if (!in_array($table, self::TABLE_ORDER, true)) {
            return;
        }
        $conds = [];
        $params = [];
        foreach (self::TABLE_PK[$table] ?? ['id'] as $col) {
            $conds[] = "`$col` = ?";
            $params[] = $row[$col] ?? null;
        }
        $this->db->run("DELETE FROM `$table` WHERE " . implode(' AND ', $conds), $params);
    }
}
