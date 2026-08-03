<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

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
    public const MAX_SPAN_NOTE = 500;
    public const KINDS = ['away', 'busy'];

    public function __construct(private readonly Db $db)
    {
    }

    /** Parse an ISO8601 instant into UTC, or throw a friendly 400. */
    private static function instant(mixed $value, string $field): \DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            throw HttpError::badRequest("$field is required");
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw HttpError::badRequest("$field must be an ISO8601 datetime");
        }
    }

    /** @return array{id:int,personId:int,start:string,end:string,kind:string,note:?string} */
    private static function spanRow(array $r): array
    {
        $iso = static fn(string $db): string => (new \DateTimeImmutable($db, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
        return [
            'id' => (int) $r['id'],
            'personId' => (int) $r['person_id'],
            'start' => $iso((string) $r['start_utc']),
            'end' => $iso((string) $r['end_utc']),
            'kind' => (string) $r['kind'],
            'note' => $r['note'] !== null ? (string) $r['note'] : null,
        ];
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
     * All people with usage stats and availability status, alphabetical.
     * eventCount counts undeleted events; nextStart is the soonest future
     * event start; currentSpan is the availability span covering "now" (the
     * sidebar dims currently-away people from it); nextSpan the soonest
     * future one.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $userId): array
    {
        $rows = $this->db->all(
            "SELECT p.id, p.name, p.notes, p.show_on_calendar,
                    COUNT(e.id) AS event_count,
                    MIN(CASE WHEN e.start_utc >= UTC_TIMESTAMP() THEN e.start_utc END) AS next_start
             FROM people p
             LEFT JOIN event_people ep ON ep.person_id = p.id
             LEFT JOIN events e ON e.id = ep.event_id AND e.deleted_at IS NULL
             WHERE p.user_id = ?
             GROUP BY p.id, p.name, p.notes, p.show_on_calendar
             ORDER BY p.name",
            [$userId]
        );
        // One query for everyone's current + upcoming spans.
        $current = [];
        $next = [];
        foreach ($this->db->all(
            'SELECT a.* FROM availability a JOIN people p ON p.id = a.person_id
             WHERE p.user_id = ? AND a.end_utc >= UTC_TIMESTAMP()
             ORDER BY a.start_utc',
            [$userId]
        ) as $r) {
            $pid = (int) $r['person_id'];
            $span = self::spanRow($r);
            $started = Time::fromDb((string) $r['start_utc']) <= Time::nowUtc();
            if ($started && !isset($current[$pid])) {
                $current[$pid] = $span;
            } elseif (!$started && !isset($next[$pid])) {
                $next[$pid] = $span;
            }
        }
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'notes' => $r['notes'] !== null ? (string) $r['notes'] : null,
            'showOnCalendar' => (int) ($r['show_on_calendar'] ?? 0) === 1,
            'eventCount' => (int) $r['event_count'],
            'nextStart' => $r['next_start'] !== null
                ? (new \DateTimeImmutable((string) $r['next_start'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP')
                : null,
            'currentSpan' => $current[(int) $r['id']] ?? null,
            'nextSpan' => $next[(int) $r['id']] ?? null,
        ], $rows);
    }

    // ---- Availability spans --------------------------------------------

    /** @return list<array> all spans for one person, newest first */
    public function spans(int $userId, int $personId): array
    {
        $this->requirePerson($userId, $personId);
        $rows = $this->db->all(
            'SELECT * FROM availability WHERE person_id = ? ORDER BY start_utc DESC LIMIT 200',
            [$personId]
        );
        return array_map(self::spanRow(...), $rows);
    }

    /** @param array{start?:mixed,end?:mixed,kind?:mixed,note?:mixed} $in */
    public function addSpan(int $userId, int $personId, array $in): array
    {
        $this->requirePerson($userId, $personId);
        $start = self::instant($in['start'] ?? null, 'start');
        $end = self::instant($in['end'] ?? null, 'end');
        if ($end <= $start) {
            throw HttpError::badRequest('end must be after start');
        }
        $kind = is_string($in['kind'] ?? null) && in_array($in['kind'], self::KINDS, true) ? $in['kind'] : 'away';
        $note = isset($in['note']) && is_string($in['note']) && trim($in['note']) !== ''
            ? mb_substr(trim($in['note']), 0, self::MAX_SPAN_NOTE) : null;
        $id = $this->db->insert('availability', [
            'person_id' => $personId,
            'start_utc' => $start->format('Y-m-d H:i:s'),
            'end_utc' => $end->format('Y-m-d H:i:s'),
            'kind' => $kind,
            'note' => $note,
        ]);
        return self::spanRow($this->db->one('SELECT * FROM availability WHERE id = ?', [$id]));
    }

    public function deleteSpan(int $userId, int $personId, int $spanId): void
    {
        $this->requirePerson($userId, $personId);
        $count = $this->db->run('DELETE FROM availability WHERE id = ? AND person_id = ?', [$spanId, $personId]);
        if ($count === 0) {
            throw HttpError::notFound('No such availability span');
        }
    }

    /**
     * Visible people's spans overlapping a window (calendar bands).
     *
     * @return list<array> spanRow + name
     */
    public function spansInWindow(int $userId, string $startIso, string $endIso): array
    {
        $start = self::instant($startIso, 'start');
        $end = self::instant($endIso, 'end');
        $rows = $this->db->all(
            'SELECT a.*, p.name FROM availability a
             JOIN people p ON p.id = a.person_id
             WHERE p.user_id = ? AND p.show_on_calendar = 1
               AND a.start_utc < ? AND a.end_utc > ?
             ORDER BY a.start_utc',
            [$userId, $end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')]
        );
        return array_map(static fn(array $r): array => self::spanRow($r) + ['name' => (string) $r['name']], $rows);
    }

    /**
     * Scheduling assist: spans overlapping [start, end) for the named people
     * (case-insensitive), regardless of show_on_calendar.
     *
     * @param list<string> $names
     * @return list<array> spanRow + name
     */
    public function check(int $userId, array $names, string $startIso, string $endIso): array
    {
        $names = array_values(array_filter(array_map(
            static fn($n) => is_string($n) ? trim($n) : '',
            $names
        ), static fn(string $n): bool => $n !== ''));
        if ($names === []) {
            return [];
        }
        $start = self::instant($startIso, 'start');
        $end = self::instant($endIso, 'end');
        [$in, $params] = Db::in(array_map('mb_strtolower', $names));
        $rows = $this->db->all(
            "SELECT a.*, p.name FROM availability a
             JOIN people p ON p.id = a.person_id
             WHERE p.user_id = ? AND LOWER(p.name) IN $in
               AND a.start_utc < ? AND a.end_utc > ?
             ORDER BY a.start_utc",
            [$userId, ...$params, $end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')]
        );
        return array_map(static fn(array $r): array => self::spanRow($r) + ['name' => (string) $r['name']], $rows);
    }

    /** @return array the person row, or throw 404 */
    private function requirePerson(int $userId, int $personId): array
    {
        $row = $this->db->one('SELECT * FROM people WHERE id = ? AND user_id = ?', [$personId, $userId]);
        if ($row === null) {
            throw HttpError::notFound('No such person');
        }
        return $row;
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

        if (array_key_exists('showOnCalendar', $in)) {
            $this->db->run(
                'UPDATE people SET show_on_calendar = ? WHERE id = ?',
                [filter_var($in['showOnCalendar'], FILTER_VALIDATE_BOOL) ? 1 : 0, $id]
            );
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

        $out = $this->db->one('SELECT id, name, notes, show_on_calendar FROM people WHERE id = ?', [$id]);
        return [
            'id' => (int) $out['id'],
            'name' => (string) $out['name'],
            'notes' => $out['notes'] !== null ? (string) $out['notes'] : null,
            'showOnCalendar' => (int) $out['show_on_calendar'] === 1,
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
