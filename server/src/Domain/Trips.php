<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Dav\ChangeLog;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

/**
 * Trips (design-containers.md): a normal event row with is_container=1 groups
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

    /**
     * Validate that $member may attach to $container. Both are event rows the
     * caller already resolved (ownership checked). Feed events pass: the link
     * never touches feed-derived content.
     *
     * @param array<string,mixed> $container
     * @param array<string,mixed> $member
     */
    public static function assertLinkable(array $container, array $member): void
    {
        if ((int) ($container['is_container'] ?? 0) !== 1) {
            throw HttpError::badRequest('Target event is not a trip', 'trip_invalid');
        }
        if ((int) ($container['id'] ?? 0) === (int) ($member['id'] ?? -1)) {
            throw HttpError::badRequest('A trip cannot contain itself', 'trip_invalid');
        }
        if ((int) ($member['is_container'] ?? 0) === 1) {
            throw HttpError::badRequest('A trip cannot contain another trip', 'trip_invalid');
        }
    }

    /** Clearing is_container is only allowed once the trip has no members. */
    public static function assertClearable(int $liveMemberCount): void
    {
        if ($liveMemberCount > 0) {
            throw HttpError::badRequest("Detach the trip's events before turning this off", 'trip_has_members');
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
        $container = $this->ownedEvent($userId, $containerId);
        $member = $this->ownedEvent($userId, $eventId);
        self::assertLinkable($container, $member);

        if ($this->db->scalar('SELECT id FROM event_links WHERE container_id = ? AND event_id = ?', [$containerId, $eventId]) !== null) {
            return; // already attached: idempotent, nothing to journal
        }

        $before = $this->linkRows($containerId);
        $this->db->tx(function () use ($containerId, $eventId): void {
            $position = (int) $this->db->scalar(
                'SELECT COALESCE(MAX(position) + 1, 0) FROM event_links WHERE container_id = ?',
                [$containerId]
            );
            $this->db->insert('event_links', ['container_id' => $containerId, 'event_id' => $eventId, 'position' => $position]);
            $this->touchEvents([$containerId, $eventId]);
        });
        $this->undo->record($userId, 'event_links', $containerId, 'create', ['event_links' => $before], ['event_links' => $this->linkRows($containerId)]);
        $this->journalBoth($container, $member);
    }

    public function detach(int $userId, int $containerId, int $eventId): void
    {
        $container = $this->ownedEvent($userId, $containerId);
        $link = $this->db->one('SELECT * FROM event_links WHERE container_id = ? AND event_id = ?', [$containerId, $eventId]);
        if ($link === null) {
            throw HttpError::notFound('Event is not part of this trip');
        }

        $before = $this->linkRows($containerId);
        $this->db->tx(function () use ($link, $containerId, $eventId): void {
            $this->db->run('DELETE FROM event_links WHERE id = ?', [(int) $link['id']]);
            $this->touchEvents([$containerId, $eventId]);
        });
        $this->undo->record($userId, 'event_links', $containerId, 'delete', ['event_links' => $before], ['event_links' => $this->linkRows($containerId)]);
        // The member may be soft-deleted (detach still valid); its uid/calendar remain journalable.
        $member = $this->db->one('SELECT calendar_id, uid FROM events WHERE id = ?', [$eventId]);
        $this->journalBoth($container, $member);
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

    /** Live member count; gates clearing is_container (see assertClearable). */
    public function liveMemberCount(int $containerId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM event_links l JOIN events e ON e.id = l.event_id
             WHERE l.container_id = ? AND e.deleted_at IS NULL',
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
    public static function relatedUidMap(Db $db, array $eventIds): array
    {
        $out = ['children' => [], 'parents' => []];
        if ($eventIds === []) {
            return $out;
        }
        [$in, $params] = Db::in($eventIds);
        foreach ($db->all(
            "SELECT l.container_id, m.uid FROM event_links l JOIN events m ON m.id = l.event_id
             WHERE l.container_id IN $in AND m.deleted_at IS NULL
             ORDER BY l.position, l.id",
            $params
        ) as $row) {
            $out['children'][(int) $row['container_id']][] = (string) $row['uid'];
        }
        foreach ($db->all(
            "SELECT l.event_id, c.uid FROM event_links l JOIN events c ON c.id = l.container_id
             WHERE l.event_id IN $in AND c.deleted_at IS NULL AND c.is_container = 1
             ORDER BY l.position, l.id",
            $params
        ) as $row) {
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
