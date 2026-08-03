<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

/**
 * People directory: names linked to events (event_people, populated by the
 * editor's People field and quick-add "with X" clauses). This is the
 * management surface — list with usage stats, rename/merge, notes, delete.
 * The availability table stays dormant until that feature is designed.
 */
final class People
{
    public const MAX_NAME = 160;
    public const MAX_NOTES = 10000;

    public function __construct(private readonly Db $db)
    {
    }

    /** Trimmed, whitespace-collapsed, length-checked name. Throws on empty. */
    public static function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '') {
            throw HttpError::badRequest('name is required');
        }
        return mb_substr($name, 0, self::MAX_NAME);
    }

    /**
     * All people with usage stats, alphabetical. eventCount counts undeleted
     * events; nextStart is the soonest future event start (UTC ISO) or null.
     *
     * @return list<array{id:int,name:string,notes:?string,eventCount:int,nextStart:?string}>
     */
    public function list(int $userId): array
    {
        $rows = $this->db->all(
            "SELECT p.id, p.name, p.notes,
                    COUNT(e.id) AS event_count,
                    MIN(CASE WHEN e.start_utc >= UTC_TIMESTAMP() THEN e.start_utc END) AS next_start
             FROM people p
             LEFT JOIN event_people ep ON ep.person_id = p.id
             LEFT JOIN events e ON e.id = ep.event_id AND e.deleted_at IS NULL
             WHERE p.user_id = ?
             GROUP BY p.id, p.name, p.notes
             ORDER BY p.name",
            [$userId]
        );
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'notes' => $r['notes'] !== null ? (string) $r['notes'] : null,
            'eventCount' => (int) $r['event_count'],
            'nextStart' => $r['next_start'] !== null
                ? (new \DateTimeImmutable((string) $r['next_start'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP')
                : null,
        ], $rows);
    }

    /**
     * Rename and/or set notes. Renaming onto an existing person's name merges
     * the two: links move to the survivor (duplicates collapse via the
     * composite primary key), the renamed row is deleted, and the survivor is
     * returned — "Virginia" -> "Virginia Miller" should unify history, not
     * error out.
     *
     * @param array{name?: mixed, notes?: mixed} $in
     * @return array{id:int,name:string,notes:?string}
     */
    public function update(int $userId, int $id, array $in): array
    {
        $row = $this->db->one('SELECT id, name, notes FROM people WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw HttpError::notFound('No such person');
        }

        if (array_key_exists('notes', $in)) {
            $notes = $in['notes'] !== null ? mb_substr((string) $in['notes'], 0, self::MAX_NOTES) : null;
            $this->db->run('UPDATE people SET notes = ? WHERE id = ?', [$notes, $id]);
        }

        if (isset($in['name']) && is_string($in['name'])) {
            $name = self::normalizeName($in['name']);
            $existing = $this->db->one(
                'SELECT id FROM people WHERE user_id = ? AND name = ? AND id != ?',
                [$userId, $name, $id]
            );
            if ($existing !== null) {
                $survivorId = (int) $existing['id'];
                $this->db->run(
                    'INSERT IGNORE INTO event_people (event_id, person_id)
                     SELECT event_id, ? FROM event_people WHERE person_id = ?',
                    [$survivorId, $id]
                );
                $this->db->run('DELETE FROM people WHERE id = ?', [$id]);
                $id = $survivorId;
            } else {
                $this->db->run('UPDATE people SET name = ? WHERE id = ?', [$name, $id]);
            }
        }

        $out = $this->db->one('SELECT id, name, notes FROM people WHERE id = ?', [$id]);
        return [
            'id' => (int) $out['id'],
            'name' => (string) $out['name'],
            'notes' => $out['notes'] !== null ? (string) $out['notes'] : null,
        ];
    }

    /** Remove a person and their event links (events themselves untouched). */
    public function delete(int $userId, int $id): void
    {
        $count = $this->db->run('DELETE FROM people WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($count === 0) {
            throw HttpError::notFound('No such person');
        }
    }

    /**
     * Event rows linked to a person, newest first (the client regroups into
     * upcoming/past). Same row shape Events::serializeRows expects.
     *
     * @return list<array>
     */
    public function eventRows(int $userId, int $personId, int $limit = 200): array
    {
        $person = $this->db->one('SELECT id FROM people WHERE id = ? AND user_id = ?', [$personId, $userId]);
        if ($person === null) {
            throw HttpError::notFound('No such person');
        }
        $limit = max(1, min(500, $limit));
        return $this->db->all(
            "SELECT e.* FROM events e
             JOIN event_people ep ON ep.event_id = e.id
             WHERE ep.person_id = ? AND e.user_id = ? AND e.deleted_at IS NULL
             ORDER BY e.start_utc DESC
             LIMIT $limit",
            [$personId, $userId]
        );
    }
}
