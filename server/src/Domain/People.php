<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\ExpansionBudget;
use BetterCal\Support\Time;
use BetterCal\Support\WorkBudgetExceeded;

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
    // 'here' marks presence — a visiting friend, someone back in town — the
    // inverse statement of away, useful for the same planning glance.
    public const KINDS = ['away', 'busy', 'here'];

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
     * eventCount counts undeleted events; nextStart is the soonest start at
     * or after now among their events (nextStarts); currentSpan is the
     * availability span covering "now" (the sidebar dims currently-away
     * people from it); nextSpan the soonest future one.
     *
     * $viewer is the request's device clock (X-Device-Tz, as the event
     * window reads it): an event that keeps its clock time in any zone is
     * next by when it starts there. Without one, as stored (the Home clock).
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $userId, ?\DateTimeZone $viewer = null, ?\DateTimeImmutable $now = null): array
    {
        $now = ($now ?? Time::nowUtc())->setTimezone(Time::utc());
        $nowSql = Time::toDb($now);
        $rows = $this->db->all(
            "SELECT p.id, p.name, p.notes, p.show_on_calendar, COUNT(e.id) AS event_count
             FROM people p
             LEFT JOIN event_people ep ON ep.person_id = p.id
             LEFT JOIN events e ON e.id = ep.event_id AND e.deleted_at IS NULL
             WHERE p.user_id = ?
             GROUP BY p.id, p.name, p.notes, p.show_on_calendar
             ORDER BY p.name",
            [$userId]
        );
        $nextStart = $this->nextStarts($userId, $now, $viewer);
        // One query for everyone's current + upcoming spans.
        $current = [];
        $next = [];
        foreach ($this->db->all(
            'SELECT a.* FROM availability a JOIN people p ON p.id = a.person_id
             WHERE p.user_id = ? AND a.end_utc >= ?
             ORDER BY a.start_utc',
            [$userId, $nowSql]
        ) as $r) {
            $pid = (int) $r['person_id'];
            $span = self::spanRow($r);
            $started = Time::fromDb((string) $r['start_utc']) <= $now;
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
            'nextStart' => isset($nextStart[(int) $r['id']]) ? $nextStart[(int) $r['id']]->format('Y-m-d\TH:i:sP') : null,
            'currentSpan' => $current[(int) $r['id']] ?? null,
            'nextSpan' => $next[(int) $r['id']] ?? null,
        ], $rows);
    }

    /**
     * Each person's next event start, as it shows on the viewer's clock
     * (UTC): the soonest start at or after now among their undeleted
     * events. A one-off is compared on its own clock (Floating::nowByZone,
     * as search compares it), the soonest kept per person and zone, then
     * shown (Floating::span); a floating one near now or near that soonest
     * by its zone's slack is settled by its exact start as shown. A series counts its occurrences as search
     * reads them (Search::occurrencesAfter with the edited days
     * Recurrence::editedDays loads): a generated occurrence for the people
     * of the series, an edited day for its own people, once, at its own
     * start; looked for in the coming week first and then two years.
     *
     * @return array<int,\DateTimeImmutable> person id => start (UTC)
     */
    private function nextStarts(int $userId, \DateTimeImmutable $now, ?\DateTimeZone $viewer): array
    {
        $nowSql = Time::toDb($now);
        $clocks = Floating::nowByZone($this->db, $userId, $nowSql, $viewer);
        $out = [];
        $keep = static function (int $pid, \DateTimeImmutable $at) use (&$out): void {
            if (!isset($out[$pid]) || $at < $out[$pid]) {
                $out[$pid] = $at;
            }
        };
        // One-offs: the soonest per person and own clock. Edited days count
        // with their series. On an exact clock (every pinned row, a
        // floating one in a zone whose offsets agree with the viewer's)
        // the stored order is the order shown. A floating zone with slack
        // (Floating::nowByZone) is compared widened by it, and its rows
        // from there to the slack past the first one that has surely not
        // started (to its widened now, when none has) are read and settled
        // by their exact starts as shown: a row later than that shows after it.
        [$lowSql, $lowParams] = Floating::nowOnRowSql($clocks, $nowSql, 'e.', -1);
        [$highSql, $highParams] = Floating::nowOnRowSql($clocks, $nowSql, 'e.', 1);
        $settle = [];
        foreach ($this->db->all(
            "SELECT ep.person_id, e.floating, e.all_day, e.tzid, MIN(e.start_utc) AS start_utc,
                    MIN(CASE WHEN e.start_utc >= $highSql THEN e.start_utc END) AS sure
             FROM event_people ep
             JOIN people p ON p.id = ep.person_id
             JOIN events e ON e.id = ep.event_id AND e.deleted_at IS NULL
             WHERE p.user_id = ? AND (e.rrule IS NULL OR e.rrule = '') AND e.recurrence_parent_id IS NULL
               AND e.start_utc >= $lowSql
             GROUP BY ep.person_id, e.floating, e.all_day, e.tzid",
            [...$highParams, $userId, ...$lowParams]
        ) as $r) {
            $clock = Floating::is($r) ? ($clocks[(string) $r['tzid']] ?? null) : null;
            if ($clock !== null && $clock['slack'] > 0) {
                $at = Time::fromDb($clock['at']);
                $to = $r['sure'] !== null ? Time::fromDb((string) $r['sure']) : $at;
                $settle[] = [(int) $r['person_id'], (string) $r['tzid'], Time::toDb($at->modify('-' . $clock['slack'] . ' seconds')), Time::toDb($to->modify('+' . $clock['slack'] . ' seconds'))];
                continue;
            }
            $start = Time::fromDb((string) $r['start_utc']);
            $keep((int) $r['person_id'], Floating::span($r, $start, $start, $viewer)[0]);
        }
        foreach (array_chunk($settle, 100) as $chunk) {
            $or = [];
            $params = [$userId];
            foreach ($chunk as [$pid, $tzid, $from, $to]) {
                $or[] = '(ep.person_id = ? AND e.tzid = ? AND e.start_utc >= ? AND e.start_utc < ?)';
                array_push($params, $pid, $tzid, $from, $to);
            }
            foreach ($this->db->all(
                "SELECT ep.person_id, e.floating, e.all_day, e.tzid, e.start_utc
                 FROM event_people ep
                 JOIN people p ON p.id = ep.person_id
                 JOIN events e ON e.id = ep.event_id AND e.deleted_at IS NULL
                 WHERE p.user_id = ? AND (e.rrule IS NULL OR e.rrule = '') AND e.recurrence_parent_id IS NULL
                   AND e.floating = 1 AND e.all_day = 0 AND (" . implode(' OR ', $or) . ')',
                $params
            ) as $r) {
                $start = Time::fromDb((string) $r['start_utc']);
                $shown = Floating::span($r, $start, $start, $viewer)[0];
                if ($shown >= $now) {
                    $keep((int) $r['person_id'], $shown);
                }
            }
        }

        // Series: who each series row (the series' or an edited day's) is
        // linked to, and the series they belong to.
        $peopleOf = [];
        $seriesIds = [];
        foreach ($this->db->all(
            "SELECT ep.person_id, e.id, COALESCE(e.recurrence_parent_id, e.id) AS series_id
             FROM event_people ep
             JOIN people p ON p.id = ep.person_id
             JOIN events e ON e.id = ep.event_id AND e.deleted_at IS NULL
             WHERE p.user_id = ? AND (e.recurrence_parent_id IS NOT NULL OR (e.rrule IS NOT NULL AND e.rrule <> ''))",
            [$userId]
        ) as $r) {
            $peopleOf[(int) $r['id']][(int) $r['person_id']] = true;
            $seriesIds[(int) $r['series_id']] = true;
        }
        if ($seriesIds === []) {
            return $out;
        }
        $budget = ExpansionBudget::standard();
        try {
            $masters = [];
            foreach (array_chunk(array_keys($seriesIds), 250) as $chunk) {
                [$in, $params] = Db::in($chunk);
                array_push($masters, ...$this->db->all(
                    "SELECT * FROM events WHERE id IN $in AND user_id = ? AND deleted_at IS NULL AND rrule IS NOT NULL AND rrule <> ''",
                    [...$params, $userId]
                ));
            }
            $ovByParent = [];
            foreach (Recurrence::editedDays($this->db, $userId, array_map(static fn(array $m): int => (int) $m['id'], $masters), $now, $now->modify('+2 years'), $budget) as $ov) {
                $ovByParent[(int) $ov['recurrence_parent_id']][] = $ov;
            }
            foreach ($masters as $master) {
                $id = (int) $master['id'];
                $ovs = $ovByParent[$id] ?? [];
                // Everyone this series could be next for.
                $waiting = $peopleOf[$id] ?? [];
                foreach ($ovs as $ov) {
                    $waiting += $peopleOf[(int) $ov['id']] ?? [];
                }
                foreach ([$now->modify('+8 days'), null] as $until) {
                    if ($waiting === []) {
                        break;
                    }
                    foreach (Search::occurrencesAfter($master, $ovs, $nowSql, null, $budget, $viewer, true, $until) as $occ) {
                        $row = $occ['row'] ?? $master;
                        if (!isset($occ['row']) && isset($occ[2])) {
                            $row['_wall'] = $occ[2];
                        }
                        $shown = null;
                        foreach ($peopleOf[(int) $row['id']] ?? [] as $pid => $_) {
                            if (isset($waiting[$pid])) {
                                $shown ??= Floating::span($row, Time::fromDb($occ[0]), Time::fromDb($occ[1]), $viewer)[0];
                                $keep($pid, $shown);
                                unset($waiting[$pid]);
                            }
                        }
                        if ($waiting === []) {
                            break;
                        }
                    }
                }
            }
        } catch (WorkBudgetExceeded) {
            throw new HttpError('people_too_large', 'Too many repeating events to find everyone\'s next one safely.', 422);
        }
        return $out;
    }

    /**
     * Explicit person creation (People page / New menu). Returns the
     * existing person when the name is already taken (idempotent).
     *
     * @param array{name?: mixed, notes?: mixed} $in
     * @return array{id:int,name:string,notes:?string,created:bool}
     */
    public function create(int $userId, array $in): array
    {
        $name = self::normalizeName(is_string($in['name'] ?? null) ? $in['name'] : '');
        $existing = $this->db->one('SELECT id, name, notes FROM people WHERE user_id = ? AND name = ?', [$userId, $name]);
        if ($existing !== null) {
            return [
                'id' => (int) $existing['id'],
                'name' => (string) $existing['name'],
                'notes' => $existing['notes'] !== null ? (string) $existing['notes'] : null,
                'created' => false,
            ];
        }
        $notes = isset($in['notes']) && is_string($in['notes']) && trim($in['notes']) !== ''
            ? mb_substr(trim($in['notes']), 0, self::MAX_NOTES) : null;
        // Visible by default: a person you just added is a person you meant
        // to see; hiding is the deliberate act, not the starting state.
        $id = $this->undo()->write($userId, function () use ($userId, $name, $notes): int {
            $id = $this->db->insert('people', ['user_id' => $userId, 'name' => $name, 'notes' => $notes, 'show_on_calendar' => 1]);
            $this->undo()->record($userId, 'person', $id, 'create', null, ['people' => [$this->db->one('SELECT * FROM people WHERE id = ?', [$id])]]);
            return $id;
        });
        return ['id' => $id, 'name' => $name, 'notes' => $notes, 'created' => true];
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
        return $this->undo()->write($userId, fn(): array => $this->addSpanLocked($userId, $personId, $in));
    }

    private function addSpanLocked(int $userId, int $personId, array $in): array
    {
        $person = $this->requirePerson($userId, $personId);
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
        $row = $this->db->one('SELECT * FROM availability WHERE id = ?', [$id]);
        $this->undo()->record(
            $userId,
            'availability',
            $id,
            'create',
            null,
            ['availability' => [$row]],
            'Marked ' . (string) $person['name'] . ' ' . $kind . ' (' . $start->setTimezone(Time::displayZone('UTC', Settings::homeTzid($this->db, $userId)))->format('M j') . ')'
        );
        return self::spanRow($row);
    }

    /**
     * Reposition, rekind or re-note an existing span (calendar band drag,
     * the People page's edit, #159). Only the provided fields change; a
     * note of null or blank clears it.
     *
     * @param array{start?:mixed,end?:mixed,kind?:mixed,note?:mixed} $in
     */
    public function updateSpan(int $userId, int $personId, int $spanId, array $in): array
    {
        return $this->undo()->write($userId, fn(): array => $this->updateSpanLocked($userId, $personId, $spanId, $in));
    }

    private function updateSpanLocked(int $userId, int $personId, int $spanId, array $in): array
    {
        $person = $this->requirePerson($userId, $personId);
        $row = $this->db->one('SELECT * FROM availability WHERE id = ? AND person_id = ?', [$spanId, $personId]);
        if ($row === null) {
            throw HttpError::notFound('No such away or busy time');
        }
        $start = isset($in['start']) ? self::instant($in['start'], 'start') : Time::fromDb((string) $row['start_utc']);
        $end = isset($in['end']) ? self::instant($in['end'], 'end') : Time::fromDb((string) $row['end_utc']);
        if ($end <= $start) {
            throw HttpError::badRequest('end must be after start');
        }
        $fields = [
            'start_utc' => $start->format('Y-m-d H:i:s'),
            'end_utc' => $end->format('Y-m-d H:i:s'),
        ];
        if (isset($in['kind'])) {
            if (!is_string($in['kind']) || !in_array($in['kind'], self::KINDS, true)) {
                throw HttpError::badRequest('kind must be one of ' . implode('/', self::KINDS));
            }
            $fields['kind'] = $in['kind'];
        }
        if (array_key_exists('note', $in)) {
            if ($in['note'] !== null && !is_string($in['note'])) {
                throw HttpError::badRequest('note must be text or null');
            }
            $fields['note'] = is_string($in['note']) && trim($in['note']) !== ''
                ? mb_substr(trim($in['note']), 0, self::MAX_SPAN_NOTE) : null;
        }
        $this->db->update('availability', $fields, 'id = ?', [$spanId]);
        $after = $this->db->one('SELECT * FROM availability WHERE id = ?', [$spanId]);
        $this->undo()->record(
            $userId,
            'availability',
            $spanId,
            'update',
            ['availability' => [$row]],
            ['availability' => [$after]],
            'Moved ' . (string) ($fields['kind'] ?? $row['kind']) . ' time for ' . (string) $person['name']
                . ' (' . $start->setTimezone(Time::displayZone('UTC', Settings::homeTzid($this->db, $userId)))->format('M j') . ')'
        );
        return self::spanRow($after);
    }

    public function deleteSpan(int $userId, int $personId, int $spanId): void
    {
        $this->undo()->write($userId, fn() => $this->deleteSpanLocked($userId, $personId, $spanId));
    }

    private function deleteSpanLocked(int $userId, int $personId, int $spanId): void
    {
        $person = $this->requirePerson($userId, $personId);
        $row = $this->db->one('SELECT * FROM availability WHERE id = ? AND person_id = ?', [$spanId, $personId]);
        $count = $this->db->run('DELETE FROM availability WHERE id = ? AND person_id = ?', [$spanId, $personId])->rowCount();
        if ($count === 0) {
            throw HttpError::notFound('No such away or busy time');
        }
        $this->undo()->record(
            $userId,
            'availability',
            $spanId,
            'delete',
            ['availability' => [$row]],
            null,
            'Removed ' . (string) $row['kind'] . ' time for ' . (string) $person['name']
        );
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
     * returned — "Alex" -> "Alex Example" should unify history, not
     * error out.
     *
     * @param array{name?: mixed, notes?: mixed} $in
     * @return array{id:int,name:string,notes:?string}
     */
    public function update(int $userId, int $id, array $in): array
    {
        return $this->undo()->write($userId, fn(): array => $this->updateLocked($userId, $id, $in));
    }

    private function updateLocked(int $userId, int $id, array $in): array
    {
        $row = $this->db->one('SELECT id, name, notes FROM people WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw HttpError::notFound('No such person');
        }
        // A rename onto another person's name merges into them: both, the
        // events either is on and their away and busy times, as they were.
        $merging = isset($in['name']) && is_string($in['name'])
            ? $this->db->scalar('SELECT id FROM people WHERE user_id = ? AND name = ? AND id != ?', [$userId, self::normalizeName($in['name']), $id])
            : null;
        $ids = $merging !== null ? [$id, (int) $merging] : [$id];
        $snapshot = function () use ($ids): array {
            [$in, $params] = Db::in($ids);
            return [
                'people' => $this->db->all("SELECT * FROM people WHERE id IN $in", $params),
                'event_people' => $this->db->all("SELECT * FROM event_people WHERE person_id IN $in", $params),
                'availability' => $this->db->all("SELECT * FROM availability WHERE person_id IN $in", $params),
            ];
        };
        $before = $snapshot();

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
                $this->db->run('UPDATE availability SET person_id = ? WHERE person_id = ?', [$survivorId, $id]);
                $this->db->run('DELETE FROM people WHERE id = ?', [$id]);
                $id = $survivorId;
            } else {
                $this->db->run('UPDATE people SET name = ? WHERE id = ?', [$name, $id]);
            }
        }

        $this->undo()->record($userId, 'person', $id, 'update', $before, $snapshot());
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
        $this->undo()->write($userId, function () use ($userId, $id): void {
            $person = $this->db->one('SELECT * FROM people WHERE id = ? AND user_id = ?', [$id, $userId]);
            if ($person === null) {
                throw HttpError::notFound('No such person');
            }
            $before = [
                'people' => [$person],
                'event_people' => $this->db->all('SELECT * FROM event_people WHERE person_id = ?', [$id]),
                'availability' => $this->db->all('SELECT * FROM availability WHERE person_id = ?', [$id]),
            ];
            $this->db->run('DELETE FROM people WHERE id = ?', [$id]);
            $this->undo()->record($userId, 'person', $id, 'delete', $before, null);
        });
    }

    private ?Undo $undo = null;

    private function undo(): Undo
    {
        return $this->undo ??= new Undo($this->db);
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
