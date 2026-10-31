<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Dav\ChangeLog;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

/**
 * Mutation log + undo. Each mutating API call records before/after snapshots
 * as {"tables": {table: [rows...]}}. Undo restores every before-row (upsert)
 * and deletes rows that exist only in after (i.e. rows the mutation created).
 */
final class Undo
{
    /**
     * A calendar's place in a folder or the calendar list (#187): undone by
     * putting just that calendar back where it was, so later changes to
     * the others stay. Newer moves of the same calendar make it stale.
     */
    public const PLACE = 'calendar_place';

    /** Restore order respects FK dependencies; deletions run in reverse. */
    private const TABLE_ORDER = [
        'folders', 'tags', 'people', 'calendars', 'calendar_folders',
        'events', 'event_links', 'event_tags', 'event_people', 'out_feeds', 'filters', 'saved_views',
        'availability',
    ];

    private const TABLE_PK = [
        'folders' => ['id'], 'tags' => ['id'], 'people' => ['id'], 'calendars' => ['id'],
        'events' => ['id'], 'out_feeds' => ['id'], 'filters' => ['id'], 'saved_views' => ['id'],
        'event_links' => ['id'], 'availability' => ['id'],
        'calendar_folders' => ['calendar_id', 'folder_id'],
        'event_tags' => ['event_id', 'tag_id'],
        'event_people' => ['event_id', 'person_id'],
    ];

    /** $plugins: the plugin registry undo uses for plugin edits (tests pass one with a stand-in plugin). */
    public function __construct(private readonly Db $db, private readonly ?Plugins $plugins = null)
    {
    }

    /**
     * @param array<string,list<array>>|null $beforeTables
     * @param array<string,list<array>>|null $afterTables
     */
    /**
     * $touches: what the change touched beyond its snapshots' rows (a
     * calendar's place, which has no rows of its own; see touchesOf).
     *
     * @param list<string> $touches
     */
    public function record(int $userId, string $entity, int $entityId, string $op, ?array $beforeTables, ?array $afterTables, ?string $summary = null, ?array $details = null, array $calendarIds = [], array $touches = []): void
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
                'summary' => mb_substr($summary ?? self::defaultSummary($entity, $op, $beforeTables, $afterTables, Settings::homeTzid($this->db, $userId)), 0, 300),
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
        // Before the refs: a Google calendar's entry has its snapshots
        // emptied there, and what it touched has to stay known.
        $this->recordTouches($mutationId, array_merge(self::touchesOf($beforeTables, $afterTables), $touches));
        $this->recordCalendarRefs($mutationId, $beforeTables, $afterTables, $calendarIds);
    }

    /**
     * The rows a change actually changed, as "table:key" (a composite key
     * joined by "|"), plus "calendar_position:<id>" when a calendar's place
     * in the list moved. A row in both snapshots and the same apart from
     * its updated_at was only carried along, so it isn't counted: a rename
     * doesn't touch the folders a calendar is in. Pure.
     *
     * @return list<string>
     */
    public static function touchesOf(?array $before, ?array $after): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? []))) as $table) {
            $pk = self::TABLE_PK[$table] ?? ['id'];
            $keyed = static function (array $rows) use ($pk): array {
                $byKey = [];
                foreach ($rows as $r) {
                    if (is_array($r)) {
                        $byKey[implode('|', array_map(static fn($c) => (string) ($r[$c] ?? ''), $pk))] = $r;
                    }
                }
                return $byKey;
            };
            $b = $keyed($before[$table] ?? []);
            $a = $keyed($after[$table] ?? []);
            foreach ($b + $a as $k => $_) {
                $was = $b[$k] ?? null;
                $now = $a[$k] ?? null;
                $strip = static fn(?array $r): ?array => $r === null ? null : array_diff_key($r, ['updated_at' => true]);
                if ($strip($was) == $strip($now)) {
                    continue;
                }
                $out[] = $table . ':' . $k;
                if ($table === 'calendars' && (string) ($was['position'] ?? '') !== (string) ($now['position'] ?? '')) {
                    $out[] = 'calendar_position:' . $k;
                }
            }
        }
        return $out;
    }

    /** @param list<string> $touches */
    private function recordTouches(int $mutationId, array $touches): void
    {
        if ($mutationId <= 0 || $touches === []) {
            return;
        }
        try {
            foreach (array_values(array_unique($touches)) as $touch) {
                $this->db->run($this->db->insertIgnore() . ' INTO mutation_touches (mutation_id, touch) VALUES (?, ?)', [$mutationId, mb_substr($touch, 0, 191)]);
            }
        } catch (\PDOException $e) {
            $message = strtolower($e->getMessage());
            if ((string) $e->getCode() === '42S02' || str_contains($message, 'no such table: mutation_touches')) {
                return; // a rolling install before migration 056, and narrow tests
            }
            throw $e;
        }
    }

    /**
     * Whether a later change, not undone, changed what this one would put
     * back: the same item (the rule before migration 056, for older
     * entries), or any of the same rows or places.
     */
    private function staleFor(array $mutation): bool
    {
        $id = (int) $mutation['id'];
        $userId = (int) $mutation['user_id'];
        if ($this->db->scalar(
            'SELECT id FROM mutations WHERE user_id = ? AND entity = ? AND entity_id = ? AND id > ? AND undone = 0 LIMIT 1',
            [$userId, (string) $mutation['entity'], (int) $mutation['entity_id'], $id]
        ) !== null) {
            return true;
        }
        try {
            return $this->db->scalar(
                'SELECT m.id FROM mutation_touches t JOIN mutations m ON m.id = t.mutation_id
                 WHERE m.user_id = ? AND m.id > ? AND m.undone = 0
                   AND t.touch IN (SELECT touch FROM mutation_touches WHERE mutation_id = ?) LIMIT 1',
                [$userId, $id, $id]
            ) !== null;
        } catch (\PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'no such table: mutation_touches') || (string) $e->getCode() === '42S02') {
                return false;
            }
            throw $e;
        }
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
    public static function defaultSummary(string $entity, string $op, ?array $before, ?array $after, ?string $homeTzid = null): string
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
                // An all-day event's date is its UTC midnight; a timed one's
                // is the day on its clock (the owner's, for a UTC-stored one).
                $start = new \DateTimeImmutable((string) $row['start_utc'], Time::utc());
                if ((int) ($row['all_day'] ?? 0) !== 1) {
                    $start = $start->setTimezone(Time::displayZone((string) ($row['tzid'] ?? 'UTC'), $homeTzid));
                }
                $line .= ' (' . $start->format('M j') . ')';
            } catch (\Exception) {
            }
        }
        return $line;
    }

    /** @return array{entity:string,op:string} */
    public function undoLatest(int $userId, ?RequestAuthority $authority = null): array
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
        if ((string) $mutation['entity'] === 'plugin_edit') {
            return $this->undoPluginEdit($mutation, $authority);
        }
        if ((string) $mutation['entity'] === self::PLACE) {
            return $this->undoPlace($mutation, $authority);
        }
        return $this->apply($mutation, $authority);
    }

    /** The newest journal entry's id for this user (0 when none): where a coalesce starts. */
    public function lastId(int $userId): int
    {
        return (int) ($this->db->scalar('SELECT MAX(id) FROM mutations WHERE user_id = ?', [$userId]) ?? 0);
    }

    /**
     * Merge the entries written since $sinceId into the first of them, so
     * one undo puts back what one save did in several steps: each row as
     * it was before the first step that touched it, and as the last left
     * it (#187 review). Only entries about these events count, so a job
     * writing its own meanwhile stays apart. Fewer than two: nothing to do.
     *
     * @param list<int> $eventIds
     */
    public function coalesce(int $userId, int $sinceId, string $summary, array $eventIds): void
    {
        [$in, $params] = Db::in(array_values(array_unique(array_map('intval', $eventIds))));
        $rows = $this->db->all("SELECT * FROM mutations WHERE user_id = ? AND id > ? AND entity = 'event' AND entity_id IN $in ORDER BY id", array_merge([$userId, $sinceId], $params));
        if (count($rows) < 2) {
            return;
        }
        $before = [];
        $after = [];
        $seen = [];
        $logOnly = false;
        foreach ($rows as $row) {
            if ($row['before_json'] === null && $row['after_json'] === null) {
                $logOnly = true;
            }
            $b = self::tables($row['before_json']);
            $a = self::tables($row['after_json']);
            foreach (array_unique(array_merge(array_keys($b), array_keys($a))) as $table) {
                $pk = self::TABLE_PK[$table] ?? ['id'];
                $key = static fn(array $r): string => implode('|', array_map(static fn($c) => (string) ($r[$c] ?? ''), $pk));
                $inBefore = [];
                foreach ($b[$table] ?? [] as $r) {
                    $inBefore[$key($r)] = $r;
                }
                $inAfter = [];
                foreach ($a[$table] ?? [] as $r) {
                    $inAfter[$key($r)] = $r;
                }
                foreach ($inBefore + $inAfter as $k => $_) {
                    if (!isset($seen[$table][$k])) {
                        $seen[$table][$k] = true;
                        if (isset($inBefore[$k])) {
                            $before[$table][$k] = $inBefore[$k];
                        }
                    }
                }
                foreach ($inBefore as $k => $_) {
                    if (!isset($inAfter[$k])) {
                        unset($after[$table][$k]);
                    }
                }
                foreach ($inAfter as $k => $r) {
                    $after[$table][$k] = $r;
                }
            }
        }
        $list = static fn(array $tables): array => array_map('array_values', $tables);
        $keep = (int) $rows[0]['id'];
        $this->db->update('mutations', [
            'before_json' => $logOnly ? null : json_encode(['tables' => $list($before)], JSON_INVALID_UTF8_SUBSTITUTE),
            'after_json' => $logOnly ? null : json_encode(['tables' => $list($after)], JSON_INVALID_UTF8_SUBSTITUTE),
            'summary' => mb_substr($summary, 0, 300),
        ], 'id = ?', [$keep]);
        foreach (array_slice($rows, 1) as $row) {
            try {
                foreach ($this->db->all('SELECT touch FROM mutation_touches WHERE mutation_id = ?', [(int) $row['id']]) as $t) {
                    $this->db->run($this->db->insertIgnore() . ' INTO mutation_touches (mutation_id, touch) VALUES (?, ?)', [$keep, (string) $t['touch']]);
                }
                $this->db->run('DELETE FROM mutation_touches WHERE mutation_id = ?', [(int) $row['id']]);
            } catch (\PDOException $e) {
                // before migration 056, and narrow tests
            }
            try {
                foreach ($this->db->all('SELECT calendar_id FROM mutation_calendar_refs WHERE mutation_id = ?', [(int) $row['id']]) as $ref) {
                    $this->db->run($this->db->insertIgnore() . ' INTO mutation_calendar_refs (mutation_id, calendar_id) VALUES (?, ?)', [$keep, (int) $ref['calendar_id']]);
                }
                $this->db->run('DELETE FROM mutation_calendar_refs WHERE mutation_id = ?', [(int) $row['id']]);
            } catch (\PDOException $e) {
                // narrow-test compatibility: no refs table
            }
            $this->db->run('DELETE FROM mutations WHERE id = ?', [(int) $row['id']]);
        }
    }

    /** @return array{entity:string,op:string} */
    private function undoPlace(array $mutation, ?RequestAuthority $authority = null): array
    {
        $details = json_decode((string) ($mutation['details_json'] ?? ''), true);
        $before = is_array($details) && is_array($details['before'] ?? null) ? $details['before'] : null;
        $userId = (int) $mutation['user_id'];
        $calendarId = (int) $mutation['entity_id'];
        if ($before === null || $this->db->scalar('SELECT id FROM calendars WHERE id = ? AND user_id = ?', [$calendarId, $userId]) === null) {
            throw HttpError::badRequest('That calendar is no longer here to move back', 'not_undoable');
        }
        $this->db->tx(function () use ($before, $userId, $calendarId, $mutation, $authority): void {
            $authority?->assertValid($this->db);
            // Every folder it goes back into has to be there, held until
            // this is done, or nothing moves: with the one it came from
            // deleted, it was taken out of the other and left in none.
            foreach ($before as $place) {
                if (isset($place['folder'], $place['at']) && $this->db->scalar('SELECT id FROM folders WHERE id = ? AND user_id = ?' . $this->db->forUpdate(), [(int) $place['folder'], $userId]) === null) {
                    throw HttpError::badRequest('The folder it was in has been deleted, so it can\'t go back there', 'not_undoable');
                }
            }
            foreach ($before as $place) {
                $at = isset($place['at']) ? (int) $place['at'] : null;
                if (!empty($place['list'])) {
                    if ($at !== null) {
                        Calendars::putInList($this->db, $userId, $calendarId, $at);
                    }
                } elseif (isset($place['folder']) && $this->db->scalar('SELECT id FROM folders WHERE id = ? AND user_id = ?', [(int) $place['folder'], $userId]) !== null) {
                    Folders::putAt($this->db, (int) $place['folder'], $calendarId, $at);
                }
            }
            $this->db->update('mutations', ['undone' => 1], 'id = ?', [(int) $mutation['id']]);
        });
        return ['entity' => 'calendar', 'op' => 'update'];
    }

    /**
     * Undo one specific activity entry (docs: 7-day window). Refuses
     * log-only rows (no snapshots, or snapshots already pruned) and, unless
     * $force, rows whose entity has newer un-undone mutations (undoing under
     * those would silently clobber later edits).
     *
     * @return array{entity:string,op:string}
     */
    public function undoById(int $userId, int $mutationId, bool $force = false, ?RequestAuthority $authority = null): array
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
        if ((string) $mutation['entity'] === 'plugin_edit') {
            // Sends to the plugin's source first: not inside a transaction.
            if (!$force && $this->staleFor($mutation)) {
                throw HttpError::badRequest('Newer changes exist for this item; undoing would overwrite them', 'stale_undo');
            }
            return $this->undoPluginEdit($mutation, $authority);
        }
        // Checked and undone together, so nothing slips in between.
        return $this->db->tx(function () use ($mutation, $force, $authority): array {
            if (!$force && $this->staleFor($mutation)) {
                throw HttpError::badRequest('Newer changes exist for this item; undoing would overwrite them', 'stale_undo');
            }
            if ((string) $mutation['entity'] === self::PLACE) {
                return $this->undoPlace($mutation, $authority);
            }
            return $this->apply($mutation, $authority);
        });
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
    public function undoRun(int $userId, string $runId, bool $strict = false, ?RequestAuthority $authority = null): array
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
                $this->undoById($userId, (int) $row['id'], !$strict, $authority);
                $undone++;
            } catch (\Throwable $e) {
                // A revoked credential stops the whole run, not just one entry.
                if ($strict || ($e instanceof HttpError && in_array($e->errorCode, ['session_revoked', 'token_revoked'], true))) {
                    throw $e;
                }
                $skipped++; // log-only entries and already-undone rows
            }
        }
        return ['undone' => $undone, 'skipped' => $skipped];
    }

    /** @return array{entity:string,op:string} */
    /**
     * Undo a time change a plugin wrote back to its source (Events'
     * plugin write-through): send the earlier time to the source the same
     * way, then store what it confirms. No local snapshot to restore: the
     * row is the source's.
     *
     * @return array{entity:string,op:string}
     */
    private function undoPluginEdit(array $mutation, ?RequestAuthority $authority = null): array
    {
        $details = json_decode((string) ($mutation['details_json'] ?? ''), true);
        $span = is_array($details) ? ($details['span'] ?? null) : null;
        $pluginId = is_array($details) ? (string) ($details['pluginId'] ?? '') : '';
        $userId = (int) $mutation['user_id'];
        $event = $this->db->one('SELECT * FROM events WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [(int) $mutation['entity_id'], $userId]);
        if (!is_array($span) || $pluginId === '' || $event === null) {
            throw HttpError::badRequest('That event is no longer here to move back', 'not_undoable');
        }
        $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ?', [(int) $event['calendar_id']]);
        $plugins = $this->plugins ?? new Plugins($this->db, null, config());
        if ($calendar === null || (string) ($calendar['plugin_id'] ?? '') !== $pluginId || !$plugins->calendarReschedulable($calendar)) {
            throw HttpError::badRequest('That event can no longer be moved back from here', 'not_undoable');
        }
        $prefix = 'plg-' . $pluginId . '-';
        $uid = (string) $event['uid'];
        // As for the move itself: the credential is checked just before the
        // source is changed and again when the result is saved here.
        $authority?->assertValid($this->db, false);
        $result = $plugins->applyEdit($userId, $pluginId, [
            'sourceKey' => str_starts_with($uid, $prefix) ? substr($uid, strlen($prefix)) : $uid,
            'eventId' => (int) $event['id'],
            'calendarId' => (int) $event['calendar_id'],
            'title' => (string) $event['title'],
            'before' => Events::pluginSpan((string) $event['start_utc'], (string) $event['end_utc'], (int) $event['all_day'] === 1),
            'after' => $span,
        ]);
        [$startUtc, $endUtc, $allDay] = \BetterCal\Plugin\PluginHost::spanToUtc($result);
        $this->db->tx(function () use ($event, $startUtc, $endUtc, $allDay, $mutation, $authority): void {
            $authority?->assertValid($this->db);
            $this->db->update('events', [
                'start_utc' => $startUtc,
                'end_utc' => $endUtc,
                'all_day' => $allDay ? 1 : 0,
                'tzid' => 'UTC',
                'updated_at' => \BetterCal\Support\Time::nowDb(),
            ], 'id = ?', [(int) $event['id']]);
            ChangeLog::record($this->db, (int) $event['calendar_id'], (string) $event['uid'], ChangeLog::OP_MODIFY);
            $this->db->update('mutations', ['undone' => 1], 'id = ?', [(int) $mutation['id']]);
        });
        return ['entity' => 'event', 'op' => 'update'];
    }

    private function apply(array $mutation, ?RequestAuthority $authority = null): array
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

        $this->db->tx(function () use ($mutation, $before, $after, $calendarIds, $authority): void {
            // The credential that asked for the undo must still be valid as it's saved.
            $authority?->assertValid($this->db);
            // Recovery uses this lock before changing channel authorization.
            if ($this->db->forUpdate() !== '') {
                $this->db->one('SELECT id FROM users WHERE id = ? FOR UPDATE', [(int) $mutation['user_id']]);
            }
            // Link deltas (and older link snapshots) carry their calendars in
            // the durable reference table, not full event rows.
            if ($mutation['entity'] === 'event_links') {
                foreach ($this->db->all('SELECT calendar_id FROM mutation_calendar_refs WHERE mutation_id = ?', [$mutation['id']]) as $ref) $calendarIds[] = (int) $ref['calendar_id'];
            }
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
                    if ($table === 'calendars') {
                        $live = $this->db->one('SELECT * FROM calendars WHERE id = ?', [(int) $row['id']]);
                        if ($live !== null) {
                            foreach (['subscription_authority', 'created_by_token_id', 'created_by_session_hash'] as $key) {
                                if (array_key_exists($key, $live)) $row[$key] = $live[$key];
                            }
                            if (($row['kind'] ?? '') === 'subscribed' && (($live['kind'] ?? '') !== 'subscribed' || ($row['source_url'] ?? null) !== ($live['source_url'] ?? null))) {
                                throw HttpError::badRequest('Subscription source changes cannot be restored from an older undo snapshot. Review this calendar instead.', 'not_undoable');
                            }
                            // Calendar name/color undo must not restore cleared plugin credentials.
                            if (array_key_exists('settings_json', $row)) {
                                $oldSettings = json_decode((string) ($row['settings_json'] ?? '{}'), true) ?: [];
                                $liveSettings = json_decode((string) ($live['settings_json'] ?? '{}'), true) ?: [];
                                $oldSettings['plugins'] = $liveSettings['plugins'] ?? [];
                                $row['settings_json'] = json_encode($oldSettings);
                            }
                        } elseif (($row['kind'] ?? '') === 'subscribed') {
                            if (!empty($row['created_by_session_hash'])) $row['subscription_authority'] = 'session_review';
                            if (!empty($row['created_by_token_id']) && !ApiTokens::stillValid($this->db, (int) $row['created_by_token_id'])) $row['created_by_token_id'] = null;
                        }
                    } elseif ($table === 'out_feeds') {
                        $live = $this->db->one('SELECT * FROM out_feeds WHERE id = ?', [(int) $row['id']]);
                        if ($live === null) {
                            throw HttpError::badRequest('A deleted public feed cannot be restored with an old URL. Create a new feed instead.', 'not_undoable');
                        }
                        foreach (['token', 'token_sealed', 'created_by_token_id'] as $key) {
                            if (array_key_exists($key, $live)) $row[$key] = $live[$key];
                        }
                    }
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
