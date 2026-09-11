<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;

/** Shared tag/people helpers used by Events and Calendars. */
final class Labels
{
    public function __construct(private readonly Db $db)
    {
    }

    /** Upsert tag names, returning their ids. @param list<string> $names @return list<int> */
    public function tagIds(int $userId, array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $name = mb_substr(trim((string) $name), 0, 80);
            if ($name === '') {
                continue;
            }
            $id = $this->db->scalar('SELECT id FROM tags WHERE user_id = ? AND name = ?', [$userId, $name]);
            $ids[] = $id !== null ? (int) $id : $this->db->insert('tags', ['user_id' => $userId, 'name' => $name]);
        }
        return array_values(array_unique($ids));
    }

    /** Upsert people by name, returning their ids. @param list<string> $names @return list<int> */
    public function personIds(int $userId, array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $name = mb_substr(trim((string) $name), 0, 160);
            if ($name === '') {
                continue;
            }
            $id = $this->db->scalar('SELECT id FROM people WHERE user_id = ? AND name = ?', [$userId, $name]);
            $ids[] = $id !== null ? (int) $id : $this->db->insert('people', ['user_id' => $userId, 'name' => $name]);
        }
        return array_values(array_unique($ids));
    }

    public function setEventTags(int $userId, int $eventId, array $names): void
    {
        $this->db->run('DELETE FROM event_tags WHERE event_id = ?', [$eventId]);
        foreach ($this->tagIds($userId, $names) as $tagId) {
            $this->db->run('INSERT IGNORE INTO event_tags (event_id, tag_id) VALUES (?, ?)', [$eventId, $tagId]);
        }
    }

    /**
     * Ids from $ids that actually belong to $userId, in the order given.
     * Anything else is dropped rather than trusted — a person id is supplied
     * by the client, so it is an assertion to check, not a fact.
     *
     * @param list<mixed> $ids
     * @return list<int>
     */
    public function ownedPersonIds(int $userId, array $ids): array
    {
        $wanted = [];
        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $wanted[] = (int) $id;
            }
        }
        $wanted = array_values(array_unique(array_filter($wanted, static fn(int $i): bool => $i > 0)));
        if ($wanted === []) {
            return [];
        }
        [$in, $params] = Db::in($wanted);
        $owned = array_map(
            static fn(array $r): int => (int) $r['id'],
            $this->db->all("SELECT id FROM people WHERE user_id = ? AND id IN $in", [$userId, ...$params])
        );
        // Preserve the caller's order; $owned is only a membership test.
        return array_values(array_filter($wanted, static fn(int $i): bool => in_array($i, $owned, true)));
    }

    /**
     * Names that match no existing person, so an interactive caller can offer
     * to create them instead of creating them silently. Comparison matches
     * personIds(): trimmed, and case-insensitive via the column collation.
     *
     * @param list<string> $names
     * @return list<string>
     */
    public function unknownPersonNames(int $userId, array $names): array
    {
        $unknown = [];
        foreach ($names as $name) {
            $name = mb_substr(trim((string) $name), 0, 160);
            if ($name === '') {
                continue;
            }
            if ($this->db->scalar('SELECT id FROM people WHERE user_id = ? AND name = ?', [$userId, $name]) === null) {
                $unknown[] = $name;
            }
        }
        return array_values(array_unique($unknown));
    }

    /** Replace an event's people links wholesale. @param list<int> $personIds */
    public function replaceEventPeople(int $eventId, array $personIds): void
    {
        $this->db->run('DELETE FROM event_people WHERE event_id = ?', [$eventId]);
        foreach ($personIds as $personId) {
            $this->db->run('INSERT IGNORE INTO event_people (event_id, person_id) VALUES (?, ?)', [$eventId, $personId]);
        }
    }

    /**
     * Link by name, creating people that don't exist yet. This is the right
     * behaviour only where there is nobody to ask — ICS import, mail ingest,
     * API clients. Interactive saves send ids (see Events::applyPeoplePatch),
     * because a name-keyed save turns any stale client name into a brand-new
     * duplicate person the moment someone is renamed.
     */
    public function setEventPeople(int $userId, int $eventId, array $names): void
    {
        $this->replaceEventPeople($eventId, $this->personIds($userId, $names));
    }

    /** @param list<int> $eventIds @return array{tags:array<int,list<string>>,people:array<int,list<string>>} */
    public function forEvents(array $eventIds): array
    {
        $tags = [];
        $people = [];
        if ($eventIds !== []) {
            [$in, $params] = Db::in($eventIds);
            foreach ($this->db->all("SELECT et.event_id, t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id IN $in ORDER BY t.name", $params) as $row) {
                $tags[(int) $row['event_id']][] = (string) $row['name'];
            }
            foreach ($this->db->all("SELECT ep.event_id, p.name FROM event_people ep JOIN people p ON p.id = ep.person_id WHERE ep.event_id IN $in ORDER BY p.name", $params) as $row) {
                $people[(int) $row['event_id']][] = (string) $row['name'];
            }
        }
        return ['tags' => $tags, 'people' => $people];
    }

    /**
     * Ids of the user's events carrying a tag whose name contains $term
     * (case-insensitive substring, collation-backed). Used by search and the
     * events-window q= treatment, including `#tag` tag-only queries.
     *
     * @return list<int>
     */
    public function eventIdsForTagQuery(int $userId, string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $rows = $this->db->all(
            'SELECT DISTINCT et.event_id FROM event_tags et
             JOIN tags t ON t.id = et.tag_id
             WHERE t.user_id = ? AND t.name LIKE ?',
            [$userId, $like]
        );
        return array_map(static fn(array $row): int => (int) $row['event_id'], $rows);
    }

    /**
     * Event ids whose linked people match the term (substring). Mirrors
     * eventIdsForTagQuery for the search layer's people matching, including
     * `@name` people-only queries.
     *
     * @return list<int>
     */
    public function eventIdsForPersonQuery(int $userId, string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $rows = $this->db->all(
            'SELECT DISTINCT ep.event_id FROM event_people ep
             JOIN people p ON p.id = ep.person_id
             WHERE p.user_id = ? AND p.name LIKE ?',
            [$userId, $like]
        );
        return array_map(static fn(array $row): int => (int) $row['event_id'], $rows);
    }

    /** Link rows for undo snapshots. @return array<string,list<array>> */
    public function eventLinkRows(int $eventId): array
    {
        return [
            'event_tags' => $this->db->all('SELECT * FROM event_tags WHERE event_id = ?', [$eventId]),
            'event_people' => $this->db->all('SELECT * FROM event_people WHERE event_id = ?', [$eventId]),
        ];
    }
}
