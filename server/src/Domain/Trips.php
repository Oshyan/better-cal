<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Dav\ChangeLog;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

/**
 * Trips (docs/api-contract.md, Trips): a normal event row with is_container=1 groups
 * other events through event_links. Membership is a relationship, not
 * ownership — members keep their own calendar, times and lifecycle, feed
 * events can be members (the link is user-local metadata, like tags), and
 * same-calendar membership is as valid as cross-calendar. One nesting level
 * only: a container never attaches to another container. The schema is n:m;
 * v1 UX keeps one trip per event.
 */
final class Trips
{
    public function __construct(
        private readonly Db $db,
        private readonly Undo $undo,
    ) {
    }

    // ---- Pure seams (unit-testable without a DB) -----------------------

    /** Who asks for the trip rules (assertRules), for what a refusal is called and how it reads. */
    public const BY_EDIT = 'edit';
    public const BY_LINK = 'link';
    public const BY_UNDO = 'undo';

    /**
     * The refusal codes the contract gives each caller, by the rule broken:
     * a trip inside a trip or inside itself (nested), a trip turned off
     * while it has a live stop (cleared).
     */
    private const REFUSALS = [
        self::BY_EDIT => ['nested' => 'trip_nested', 'cleared' => 'trip_has_members'],
        self::BY_LINK => ['nested' => 'trip_invalid', 'cleared' => 'trip_has_members'],
        self::BY_UNDO => ['nested' => 'undo_conflict', 'cleared' => 'undo_conflict'],
    ];

    /**
     * A link is made only to a trip: $container must have the flag set.
     * What the link then leaves (a trip inside a trip, or inside itself) is
     * for assertRules to refuse. The caller already resolved the row
     * (ownership checked). Feed events pass as stops: the link never
     * touches feed-derived content.
     *
     * @param array<string,mixed> $container
     */
    public static function assertLinkable(array $container): void
    {
        if ((int) ($container['is_container'] ?? 0) !== 1) {
            throw HttpError::badRequest('Target event is not a trip', 'trip_invalid');
        }
    }

    /**
     * The trips among $eventIds as they are now, by id: what a write or a
     * restore starts from, for assertRules.
     *
     * @param list<int> $eventIds
     * @return array<int,true>
     */
    public static function flaggedAmong(Db $db, array $eventIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($eventIds)), 500) as $chunk) {
            [$in, $params] = Db::in($chunk);
            foreach ($db->all("SELECT id FROM events WHERE id IN $in AND is_container = 1", $params) as $row) {
                $out[(int) $row['id']] = true;
            }
        }
        return $out;
    }

    /**
     * The trip rules, stated once, over the state a write or a restore left,
     * read from the database: every link touching $eventIds whose two
     * events are live. Refused: a link whose trip and stop both have the
     * flag set (a trip never contains another trip, whichever end the flag
     * was set on), a trip that is its own stop, and a trip whose flag was
     * cleared ($flaggedBefore, from flaggedAmong) while it has a stop. A
     * link whose trip's flag was already clear is dormant, as containersFor
     * reads it, and stands. Run inside the write's transaction, after it,
     * so a refusal rolls all of it back: by every writer that sets or
     * clears the flag or makes a link, and by Undo's restore. $by names
     * the caller, for the refusal's code and words.
     *
     * @param list<int> $eventIds the events the write touched, as rows or as the ends of a link
     * @param array<int,true> $flaggedBefore
     */
    public static function assertRules(Db $db, array $eventIds, array $flaggedBefore, string $by): void
    {
        [$was, $wasParams] = Db::in(array_keys($flaggedBefore));
        foreach (array_chunk(array_values(array_unique($eventIds)), 500) as $chunk) {
            [$in, $params] = Db::in($chunk);
            $row = $db->one(
                "SELECT l.container_id, l.event_id, c.is_container AS trip_flag
                 FROM event_links l JOIN events c ON c.id = l.container_id JOIN events m ON m.id = l.event_id
                 WHERE (l.container_id IN $in OR l.event_id IN $in)
                   AND c.deleted_at IS NULL AND m.deleted_at IS NULL
                   AND ((c.is_container = 1 AND (m.is_container = 1 OR l.container_id = l.event_id))
                        OR (c.is_container = 0 AND c.id IN $was))
                 ORDER BY l.id LIMIT 1",
                [...$params, ...$params, ...$wasParams]
            );
            if ($row === null) {
                continue;
            }
            $title = static fn(int $id): string => "'" . $db->scalar('SELECT title FROM events WHERE id = ?', [$id]) . "'";
            [$trip, $stop] = [$title((int) $row['container_id']), $title((int) $row['event_id'])];
            $lead = $by === self::BY_UNDO ? 'Undoing this would' : 'This would';
            [$rule, $words] = match (true) {
                (int) $row['trip_flag'] === 0 => ['cleared', "$lead turn off the trip $trip while $stop is in it; detach its events first."],
                (int) $row['container_id'] === (int) $row['event_id'] => ['nested', "$lead put the trip $trip inside itself; a trip can't contain itself."],
                default => ['nested', "$lead make $stop a trip inside the trip $trip; a trip can't contain another trip."],
            };
            throw HttpError::badRequest($words, self::REFUSALS[$by][$rule]);
        }
    }

    /**
     * Fold link+container rows into the per-member serialization map:
     * eventId => [{eventId, title}, ...] (usually a single entry in v1).
     *
     * @param list<array<string,mixed>> $rows keys event_id, container_id, title
     * @return array<int,list<array{eventId:int,title:string}>>
     */
    public static function containerMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['event_id']][] = [
                'eventId' => (int) $row['container_id'],
                'title' => (string) $row['title'],
            ];
        }
        return $map;
    }

    // ---- Mutations -----------------------------------------------------

    public function attach(int $userId, int $containerId, int $eventId): void
    {
        $this->undo->write($userId, function () use ($userId, $containerId, $eventId): void {
            [$container, $member, $calendarIds] = $this->lockEvents($userId, $containerId, $eventId);
            self::assertLinkable($container);
            if ($this->db->scalar('SELECT id FROM event_links WHERE container_id = ? AND event_id = ?', [$containerId, $eventId]) !== null) {
                return; // already attached: idempotent, nothing to journal
            }
            $position = (int) $this->db->scalar(
                'SELECT COALESCE(MAX(position) + 1, 0) FROM event_links WHERE container_id = ?',
                [$containerId]
            );
            $id = $this->db->insert('event_links', ['container_id' => $containerId, 'event_id' => $eventId, 'position' => $position]);
            // A link changes no flag: what it leaves is all there is to refuse.
            self::assertRules($this->db, [$containerId, $eventId], [], self::BY_LINK);
            $this->touchEvents([$containerId, $eventId]);
            $link = $this->db->one('SELECT * FROM event_links WHERE id = ?', [$id]);
            $this->undo->record($userId, 'event_links', $containerId, 'create', ['event_links' => []], ['event_links' => [$link]], calendarIds: $calendarIds);
            $this->journalBoth($container, $member);
        });
    }

    public function detach(int $userId, int $containerId, int $eventId): void
    {
        $this->undo->write($userId, function () use ($userId, $containerId, $eventId): void {
            [$container, $member, $calendarIds] = $this->lockEvents($userId, $containerId, $eventId, true);
            $link = $this->db->one('SELECT * FROM event_links WHERE container_id = ? AND event_id = ?', [$containerId, $eventId]);
            if ($link === null) {
                throw HttpError::notFound('Event is not part of this trip');
            }
            $this->db->run('DELETE FROM event_links WHERE id = ?', [(int) $link['id']]);
            $this->touchEvents([$containerId, $eventId]);
            $this->undo->record($userId, 'event_links', $containerId, 'delete', ['event_links' => [$link]], ['event_links' => []], calendarIds: $calendarIds);
            $this->journalBoth($container, $member);
        });
    }

    // ---- Reads ---------------------------------------------------------

    /**
     * Member event rows of a trip, chronological by each master row's own
     * start. Deliberately non-windowed: the trip detail view needs every
     * member regardless of the visible range; recurring members appear once
     * as their master row, not expanded. Serialize via Events::serializeRows.
     *
     * @return list<array<string,mixed>>
     */
    public function listMembers(int $userId, int $containerId): array
    {
        $container = $this->ownedEvent($userId, $containerId);
        if ((int) $container['is_container'] !== 1) {
            throw HttpError::badRequest('Event is not a trip', 'trip_invalid');
        }
        return $this->db->all(
            'SELECT e.* FROM event_links l JOIN events e ON e.id = l.event_id
             WHERE l.container_id = ? AND e.deleted_at IS NULL
             ORDER BY e.start_utc, e.end_utc DESC, e.title, e.id',
            [$containerId]
        );
    }

    /**
     * Batch map for occurrence serialization (one query per response, like
     * tags): eventId => [{eventId,title}]. Soft-deleted containers and rows
     * whose flag was cleared drop out here, so stale link rows never surface.
     *
     * @param list<int> $eventIds
     * @return array<int,list<array{eventId:int,title:string}>>
     */
    public function containersFor(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        [$in, $params] = Db::in($eventIds);
        return self::containerMap($this->db->all(
            "SELECT l.event_id, l.container_id, c.title
             FROM event_links l JOIN events c ON c.id = l.container_id
             WHERE l.event_id IN $in AND c.deleted_at IS NULL AND c.is_container = 1
             ORDER BY l.position, l.id",
            $params
        ));
    }

    /**
     * RELATED-TO uid map for ICS export: per event id, the uids of its
     * members (children — the event is a container) and of its containers
     * (parents — the event is a member). Static so DAV/feed code paths
     * without a Trips instance can call it with their Db.
     *
     * @param list<int> $eventIds
     * @return array{children:array<int,list<string>>,parents:array<int,list<string>>}
     */
    public static function relatedUidMap(Db $db, array $eventIds, ?int $maxLinks = null): array
    {
        $out = ['children' => [], 'parents' => []];
        if ($eventIds === []) {
            return $out;
        }
        [$in, $params] = Db::in($eventIds);
        $limitSql = $maxLinks === null ? '' : ' LIMIT ' . (max(0, $maxLinks) + 1);
        $childRows = $db->all(
            "SELECT l.container_id, m.uid FROM event_links l JOIN events m ON m.id = l.event_id
             WHERE l.container_id IN $in AND m.deleted_at IS NULL
             ORDER BY l.position, l.id$limitSql",
            $params
        );
        if ($maxLinks !== null && count($childRows) > $maxLinks) {
            throw new \LengthException('Too many event relationships to serialize safely');
        }
        foreach ($childRows as $row) {
            $out['children'][(int) $row['container_id']][] = (string) $row['uid'];
        }
        $remaining = $maxLinks === null ? null : $maxLinks - count($childRows);
        $limitSql = $remaining === null ? '' : ' LIMIT ' . (max(0, $remaining) + 1);
        $parentRows = $db->all(
            "SELECT l.event_id, c.uid FROM event_links l JOIN events c ON c.id = l.container_id
             WHERE l.event_id IN $in AND c.deleted_at IS NULL AND c.is_container = 1
             ORDER BY l.position, l.id$limitSql",
            $params
        );
        if ($remaining !== null && count($parentRows) > $remaining) {
            throw new \LengthException('Too many event relationships to serialize safely');
        }
        foreach ($parentRows as $row) {
            $out['parents'][(int) $row['event_id']][] = (string) $row['uid'];
        }
        return $out;
    }

    // ---- Helpers -------------------------------------------------------

    /** @return array<string,mixed> */
    private function ownedEvent(int $userId, int $id): array
    {
        $row = $this->db->one('SELECT * FROM events WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$id, $userId]);
        if ($row === null) {
            throw HttpError::notFound('Event not found');
        }
        return $row;
    }

    /**
     * Lock both calendars, then prove the events still belong to those exact
     * rows. Event moves and trip membership edits take the same locks, so a
     * bulk trip move cannot gain an unguarded member after its calendar set
     * was chosen.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:list<int>}
     */
    private function lockEvents(int $userId, int $containerId, int $eventId, bool $allowDeletedMember = false): array
    {
        $container = $this->ownedEvent($userId, $containerId);
        $member = $allowDeletedMember
            ? $this->db->one('SELECT * FROM events WHERE id = ? AND user_id = ?', [$eventId, $userId])
            : $this->ownedEvent($userId, $eventId);
        if ($member === null) {
            throw HttpError::notFound('Event not found');
        }
        $calendarIds = array_values(array_unique([(int) $container['calendar_id'], (int) $member['calendar_id']]));
        sort($calendarIds, SORT_NUMERIC);
        CalendarMoveGuard::lockCalendars($this->db, $calendarIds);
        $freshContainer = $this->ownedEvent($userId, $containerId);
        $freshMember = $allowDeletedMember
            ? $this->db->one('SELECT * FROM events WHERE id = ? AND user_id = ?', [$eventId, $userId])
            : $this->ownedEvent($userId, $eventId);
        if ($freshMember === null
            || (int) $freshContainer['calendar_id'] !== (int) $container['calendar_id']
            || (int) $freshMember['calendar_id'] !== (int) $member['calendar_id']
        ) {
            throw HttpError::conflict('event_changed', 'This trip changed while it was being updated; reload it and try again.');
        }
        return [$freshContainer, $freshMember, $calendarIds];
    }

    /** @return list<array> current link rows of a container, for undo snapshots */
    private function linkRows(int $containerId): array
    {
        return $this->db->all('SELECT * FROM event_links WHERE container_id = ? ORDER BY id', [$containerId]);
    }

    /**
     * Bump updated_at on both sides so CalDAV etags (md5 of updated_at)
     * change along with the RELATED-TO lines.
     *
     * @param list<int> $ids
     */
    private function touchEvents(array $ids): void
    {
        [$in, $params] = Db::in($ids);
        $this->db->run("UPDATE events SET updated_at = ? WHERE id IN $in", [Time::nowDb(), ...$params]);
    }

    /**
     * MODIFY both calendar objects so sync-collection clients refresh them.
     *
     * @param array<string,mixed> $container
     * @param array<string,mixed>|null $member
     */
    private function journalBoth(array $container, ?array $member): void
    {
        ChangeLog::record($this->db, (int) $container['calendar_id'], (string) $container['uid'], ChangeLog::OP_MODIFY);
        if ($member !== null) {
            ChangeLog::record($this->db, (int) $member['calendar_id'], (string) $member['uid'], ChangeLog::OP_MODIFY);
        }
    }
}
