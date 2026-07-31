<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Ids;
use BetterCal\Support\Time;

final class Events
{
    private const MAX_WINDOW_SECONDS = 2 * 366 * 86400; // ~2 year hard cap per query
    private const NEW_WINDOW_HOURS = 48;
    private const SCOPES = ['this', 'following', 'all'];
    private const ATTENDANCE = ['none', 'interested', 'going', 'hidden'];

    public function __construct(
        private readonly Db $db,
        private readonly Recurrence $recurrence,
        private readonly Undo $undo,
        private readonly Labels $labels,
        private readonly Filters $filters,
    ) {
    }

    /** @var array<int, \DateTimeImmutable>|null calendar id => created_at, lazy per request */
    private ?array $calCreatedAt = null;

    private function calendarCreatedAt(int $calendarId): ?\DateTimeImmutable
    {
        if ($this->calCreatedAt === null) {
            $this->calCreatedAt = [];
            foreach ($this->db->all('SELECT id, created_at FROM calendars') as $c) {
                $this->calCreatedAt[(int) $c['id']] = Time::fromDb((string) $c['created_at']);
            }
        }
        return $this->calCreatedAt[$calendarId] ?? null;
    }

    // ---- Window query -------------------------------------------------

    /** @return list<array> occurrences, sorted start asc / end desc / title asc */
    public function window(
        int $userId,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?array $calendarIds,
        ?string $q,
        bool $includeHidden,
    ): array {
        if ($end <= $start) {
            throw HttpError::badRequest('end must be after start');
        }
        if ($end->getTimestamp() - $start->getTimestamp() > self::MAX_WINDOW_SECONDS) {
            $end = $start->add(new \DateInterval('PT' . self::MAX_WINDOW_SECONDS . 'S'));
        }

        $params = [$userId, Time::toDb($end), Time::toDb($start), Time::toDb($end)];
        $sql = 'SELECT * FROM events WHERE user_id = ? AND deleted_at IS NULL AND recurrence_parent_id IS NULL
                AND ((rrule IS NULL AND start_utc < ? AND end_utc > ?) OR (rrule IS NOT NULL AND start_utc < ?))';
        $sql .= $this->windowFilters($params, $calendarIds, $q, $includeHidden);
        $masters = $this->db->all($sql, $params);

        $masterIds = array_map(static fn($r) => (int) $r['id'], $masters);
        [$in, $inParams] = Db::in($masterIds !== [] ? $masterIds : [0]);
        $ovParams = [$userId, ...$inParams, Time::toDb($end), Time::toDb($start)];
        $ovSql = "SELECT * FROM events WHERE user_id = ? AND deleted_at IS NULL AND recurrence_parent_id IS NOT NULL
                  AND (recurrence_parent_id IN $in OR (start_utc < ? AND end_utc > ?))";
        $ovSql .= $this->windowFilters($ovParams, $calendarIds, null, $includeHidden);
        $overrides = $this->db->all($ovSql, $ovParams);

        $ovByParent = [];
        foreach ($overrides as $ov) {
            $ovByParent[(int) $ov['recurrence_parent_id']][] = $ov;
        }

        $expanded = [];
        $seenParents = [];
        foreach ($masters as $master) {
            $mid = (int) $master['id'];
            $seenParents[$mid] = true;
            foreach ($this->recurrence->expand($master, $ovByParent[$mid] ?? [], $start, $end) as $occ) {
                $expanded[] = $occ;
            }
        }
        // Overrides in-window whose master was not selected (series otherwise out of range).
        foreach ($ovByParent as $parentId => $ovs) {
            if (isset($seenParents[$parentId])) {
                continue;
            }
            foreach ($ovs as $ov) {
                $ovStart = Time::fromDb((string) $ov['start_utc']);
                $ovEnd = Time::fromDb((string) $ov['end_utc']);
                if ($ovStart < $end && $ovEnd > $start) {
                    $expanded[] = ['row' => $ov, 'start' => $ovStart, 'end' => $ovEnd, 'instanceUtc' => Time::toDb($ovStart)];
                }
            }
        }

        // Deterministic order: start asc, end desc, title asc (by UTC instant).
        usort($expanded, static function (array $a, array $b): int {
            return [$a['start']->getTimestamp(), $b['end']->getTimestamp(), (string) $a['row']['title']]
                <=> [$b['start']->getTimestamp(), $a['end']->getTimestamp(), (string) $b['row']['title']];
        });

        // User filters: hide drops the occurrence, dim marks it (additive field).
        $activeFilters = $this->filters->enabledForUser($userId);
        if ($activeFilters !== []) {
            $kept = [];
            foreach ($expanded as $occ) {
                $disposition = Filters::disposition($occ['row'], $activeFilters);
                if ($disposition === 'hide') {
                    continue;
                }
                if ($disposition === 'dim') {
                    $occ['dimmed'] = true;
                }
                $kept[] = $occ;
            }
            $expanded = $kept;
        }

        $eventIds = [];
        foreach ($expanded as $occ) {
            $eventIds[(int) $occ['row']['id']] = true;
        }
        $links = $this->labels->forEvents(array_keys($eventIds));

        return array_map(
            function (array $occ) use ($links): array {
                $serialized = $this->serialize($occ['row'], $occ['start'], $occ['end'], $links);
                if (!empty($occ['dimmed'])) {
                    $serialized['dimmed'] = true;
                }
                return $serialized;
            },
            $expanded
        );
    }

    private function windowFilters(array &$params, ?array $calendarIds, ?string $q, bool $includeHidden): string
    {
        $sql = '';
        if ($calendarIds !== null && $calendarIds !== []) {
            [$in, $inParams] = Db::in($calendarIds);
            $sql .= " AND calendar_id IN $in";
            array_push($params, ...$inParams);
        }
        if (!$includeHidden) {
            $sql .= " AND attendance <> 'hidden'";
        }
        if ($q !== null && trim($q) !== '') {
            $like = '%' . addcslashes(trim($q), '%_\\') . '%';
            $sql .= ' AND (title LIKE ? OR description LIKE ? OR location LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        return $sql;
    }

    // ---- CRUD ---------------------------------------------------------

    public function get(int $userId, int $id): array
    {
        $row = $this->db->one('SELECT * FROM events WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$id, $userId]);
        if ($row === null) {
            throw HttpError::notFound('Event not found');
        }
        return $row;
    }

    /** @return array occurrence for the created event (first instance) */
    public function create(int $userId, array $in): array
    {
        $calendarId = (int) ($in['calendarId'] ?? 0);
        $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ? AND user_id = ?', [$calendarId, $userId]);
        if ($calendar === null) {
            throw HttpError::badRequest('Unknown calendarId');
        }
        if ($calendar['kind'] === 'subscribed') {
            throw HttpError::forbidden('feed_readonly', 'Cannot create events on a subscribed calendar');
        }

        $tzid = isset($in['tzid']) ? Time::normalizeTzid((string) $in['tzid']) : 'America/Los_Angeles';
        $allDay = filter_var($in['allDay'] ?? false, FILTER_VALIDATE_BOOL);
        [$startUtc, $endUtc] = $this->parseTimes($in, $allDay, $tzid, null);

        $rrule = null;
        if (!empty($in['rrule'])) {
            $rrule = Recurrence::validateRrule((string) $in['rrule']);
        }

        $row = [
            'user_id' => $userId,
            'calendar_id' => $calendarId,
            'uid' => Ids::ulid(),
            'title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 500),
            'description' => isset($in['description']) ? (string) $in['description'] : null,
            'location' => isset($in['location']) ? mb_substr((string) $in['location'], 0, 500) : null,
            'url' => isset($in['url']) ? (string) $in['url'] : null,
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
            'all_day' => $allDay ? 1 : 0,
            'tzid' => $tzid,
            'rrule' => $rrule,
            'status' => $this->statusOrDefault($in['status'] ?? null),
            'source' => 'local',
            'created_at' => Time::nowDb(),
            'updated_at' => Time::nowDb(),
        ];

        $id = $this->db->tx(function () use ($row, $userId, $in): int {
            $id = $this->db->insert('events', $row);
            if (!empty($in['tagNames']) && is_array($in['tagNames'])) {
                $this->labels->setEventTags($userId, $id, $in['tagNames']);
            }
            if (!empty($in['personNames']) && is_array($in['personNames'])) {
                $this->labels->setEventPeople($userId, $id, $in['personNames']);
            }
            return $id;
        });

        $created = $this->get($userId, $id);
        $this->undo->record($userId, 'event', $id, 'create', null, ['events' => [$created]] + $this->labels->eventLinkRows($id));
        return $this->serializeSingle($created);
    }

    public function patch(int $userId, int $id, array $in): void
    {
        $event = $this->get($userId, $id);
        $editKeys = array_diff(array_keys($in), ['scope', 'instanceStart', 'tagNames']);
        if ($event['source'] === 'feed' && $editKeys !== []) {
            throw HttpError::forbidden('feed_readonly', 'Feed events are read-only except attendance and tags');
        }

        $isRecurringMaster = !empty($event['rrule']) && empty($event['recurrence_parent_id']);
        $scope = isset($in['scope']) ? (string) $in['scope'] : null;
        if ($isRecurringMaster) {
            if ($scope === null || !in_array($scope, self::SCOPES, true)) {
                throw HttpError::badRequest('scope (this|following|all) is required for recurring events');
            }
        } else {
            $scope = 'all';
        }

        if (isset($in['rrule']) && $in['rrule'] !== null && $in['rrule'] !== '') {
            $in['rrule'] = Recurrence::validateRrule((string) $in['rrule']);
        }
        if (isset($in['calendarId'])) {
            $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ? AND user_id = ?', [(int) $in['calendarId'], $userId]);
            if ($calendar === null) {
                throw HttpError::badRequest('Unknown calendarId');
            }
            if ($calendar['kind'] === 'subscribed') {
                throw HttpError::forbidden('feed_readonly', 'Cannot move events onto a subscribed calendar');
            }
        }

        match ($scope) {
            'this' => $this->patchThis($userId, $event, $in),
            'following' => $this->patchFollowing($userId, $event, $in),
            'all' => $this->patchAll($userId, $event, $in),
        };
    }

    private function patchAll(int $userId, array $event, array $in): void
    {
        $id = (int) $event['id'];
        $beforeLinks = $this->labels->eventLinkRows($id);
        $fields = $this->columnPatch($event, $in);

        $this->db->tx(function () use ($id, $userId, $fields, $in): void {
            if ($fields !== []) {
                $fields['updated_at'] = Time::nowDb();
                $this->db->update('events', $fields, 'id = ?', [$id]);
            }
            if (array_key_exists('tagNames', $in) && is_array($in['tagNames'])) {
                $this->labels->setEventTags($userId, $id, $in['tagNames']);
            }
            if (array_key_exists('personNames', $in) && is_array($in['personNames'])) {
                $this->labels->setEventPeople($userId, $id, $in['personNames']);
            }
        });

        $after = $this->get($userId, $id);
        $this->undo->record(
            $userId,
            'event',
            $id,
            'update',
            ['events' => [$event]] + $beforeLinks,
            ['events' => [$after]] + $this->labels->eventLinkRows($id)
        );
    }

    private function patchThis(int $userId, array $master, array $in): void
    {
        $instanceUtc = $this->requireInstance($in, $master);
        $masterId = (int) $master['id'];
        $existing = $this->db->one(
            'SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL',
            [$masterId, $instanceUtc]
        );

        if ($existing !== null) {
            $fields = $this->columnPatch($existing, $in, $instanceUtc);
            $this->db->tx(function () use ($existing, $userId, $fields, $in): void {
                if ($fields !== []) {
                    $fields['updated_at'] = Time::nowDb();
                    $this->db->update('events', $fields, 'id = ?', [(int) $existing['id']]);
                }
                if (array_key_exists('tagNames', $in) && is_array($in['tagNames'])) {
                    $this->labels->setEventTags($userId, (int) $existing['id'], $in['tagNames']);
                }
            });
            $after = $this->get($userId, (int) $existing['id']);
            $this->undo->record($userId, 'event', (int) $existing['id'], 'update', ['events' => [$existing]], ['events' => [$after]]);
            return;
        }

        // New override row for this instance.
        $duration = Recurrence::durationSeconds($master);
        $instStart = Time::fromDb($instanceUtc);
        $override = $this->copyForChild($master);
        $override['recurrence_parent_id'] = $masterId;
        $override['recurrence_instance_utc'] = $instanceUtc;
        $override['rrule'] = null;
        $override['exdates_json'] = null;
        $override['start_utc'] = Time::toDb($instStart);
        $override['end_utc'] = Time::toDb($instStart->add(new \DateInterval('PT' . $duration . 'S')));
        $override = array_merge($override, $this->columnPatch($override, $in, $instanceUtc, forOverride: true));

        $newId = $this->db->tx(fn(): int => $this->db->insert('events', $override));
        $created = $this->get($userId, $newId);
        $this->undo->record($userId, 'event', $masterId, 'update', ['events' => [$master]], ['events' => [$master, $created]]);
    }

    private function patchFollowing(int $userId, array $master, array $in): void
    {
        $instanceUtc = $this->requireInstance($in, $master);
        $masterId = (int) $master['id'];
        $instStart = Time::fromDb($instanceUtc);
        $duration = Recurrence::durationSeconds($master);
        $allDay = (int) $master['all_day'] === 1;

        $oldRrule = Recurrence::setUntil((string) $master['rrule'], Recurrence::splitUntil($instStart), $allDay);

        $newMaster = $this->copyForChild($master);
        $newMaster['uid'] = Ids::ulid();
        $newMaster['start_utc'] = Time::toDb($instStart);
        $newMaster['end_utc'] = Time::toDb($instStart->add(new \DateInterval('PT' . $duration . 'S')));
        $newMaster['rrule'] = $this->stripCount((string) $master['rrule']);
        $newMaster['exdates_json'] = $this->exdatesFrom($master, $instanceUtc);
        $newMaster = array_merge($newMaster, $this->columnPatch($newMaster, $in));
        if (isset($in['rrule']) && $in['rrule'] !== null && $in['rrule'] !== '') {
            $newMaster['rrule'] = (string) $in['rrule'];
        }

        $movedOverrides = $this->db->all(
            'SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc >= ? AND deleted_at IS NULL',
            [$masterId, $instanceUtc]
        );

        $newId = $this->db->tx(function () use ($masterId, $oldRrule, $newMaster, $movedOverrides): int {
            $this->db->update('events', ['rrule' => $oldRrule, 'updated_at' => Time::nowDb()], 'id = ?', [$masterId]);
            foreach ($movedOverrides as $ov) {
                $this->db->run('DELETE FROM events WHERE id = ?', [(int) $ov['id']]);
            }
            return $this->db->insert('events', $newMaster);
        });

        $afterMaster = $this->get($userId, $masterId);
        $created = $this->get($userId, $newId);
        $this->undo->record(
            $userId,
            'event',
            $masterId,
            'update',
            ['events' => array_merge([$master], $movedOverrides)],
            ['events' => [$afterMaster, $created]]
        );
    }

    public function deleteEvent(int $userId, int $id, ?string $scope, ?string $instanceStart): void
    {
        $event = $this->get($userId, $id);
        if ($event['source'] === 'feed') {
            throw HttpError::forbidden('feed_readonly', 'Feed events cannot be deleted; hide them instead');
        }

        // Deleting an override row directly = deleting that one occurrence.
        if (!empty($event['recurrence_parent_id'])) {
            $this->deleteOverrideInstance($userId, $event);
            return;
        }

        $isRecurring = !empty($event['rrule']);
        if ($isRecurring) {
            $scope ??= 'all';
            if (!in_array($scope, self::SCOPES, true)) {
                throw HttpError::badRequest('scope must be this|following|all');
            }
        } else {
            $scope = 'all';
        }

        match ($scope) {
            'this' => $this->deleteThis($userId, $event, $instanceStart),
            'following' => $this->deleteFollowing($userId, $event, $instanceStart),
            'all' => $this->deleteAll($userId, $event),
        };
    }

    private function deleteAll(int $userId, array $event): void
    {
        $id = (int) $event['id'];
        $overrides = $this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$id]);
        $now = Time::nowDb();
        $this->db->tx(function () use ($id, $now): void {
            $this->db->run('UPDATE events SET deleted_at = ? WHERE id = ? OR recurrence_parent_id = ?', [$now, $id, $id]);
        });
        $afterRows = $this->db->all('SELECT * FROM events WHERE id = ? OR recurrence_parent_id = ?', [$id, $id]);
        $this->undo->record($userId, 'event', $id, 'delete', ['events' => array_merge([$event], $overrides)], ['events' => $afterRows]);
    }

    private function deleteThis(int $userId, array $master, ?string $instanceStart): void
    {
        $instanceUtc = $this->requireInstance(['instanceStart' => $instanceStart], $master);
        $masterId = (int) $master['id'];
        $exdates = $this->decodeExdates($master);
        if (!in_array($instanceUtc, $exdates, true)) {
            $exdates[] = $instanceUtc;
        }
        $override = $this->db->one(
            'SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL',
            [$masterId, $instanceUtc]
        );
        $this->db->tx(function () use ($masterId, $exdates, $override): void {
            $this->db->update('events', ['exdates_json' => json_encode($exdates), 'updated_at' => Time::nowDb()], 'id = ?', [$masterId]);
            if ($override !== null) {
                $this->db->run('DELETE FROM events WHERE id = ?', [(int) $override['id']]);
            }
        });
        $after = $this->get($userId, $masterId);
        $before = ['events' => $override !== null ? [$master, $override] : [$master]];
        $this->undo->record($userId, 'event', $masterId, 'update', $before, ['events' => [$after]]);
    }

    private function deleteFollowing(int $userId, array $master, ?string $instanceStart): void
    {
        $instanceUtc = $this->requireInstance(['instanceStart' => $instanceStart], $master);
        $masterId = (int) $master['id'];
        $instStart = Time::fromDb($instanceUtc);
        $allDay = (int) $master['all_day'] === 1;
        $newRrule = Recurrence::setUntil((string) $master['rrule'], Recurrence::splitUntil($instStart), $allDay);
        $movedOverrides = $this->db->all(
            'SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc >= ? AND deleted_at IS NULL',
            [$masterId, $instanceUtc]
        );
        $this->db->tx(function () use ($masterId, $newRrule, $movedOverrides): void {
            $this->db->update('events', ['rrule' => $newRrule, 'updated_at' => Time::nowDb()], 'id = ?', [$masterId]);
            foreach ($movedOverrides as $ov) {
                $this->db->run('DELETE FROM events WHERE id = ?', [(int) $ov['id']]);
            }
        });
        $after = $this->get($userId, $masterId);
        $this->undo->record(
            $userId,
            'event',
            $masterId,
            'update',
            ['events' => array_merge([$master], $movedOverrides)],
            ['events' => [$after]]
        );
    }

    private function deleteOverrideInstance(int $userId, array $override): void
    {
        $parent = $this->db->one('SELECT * FROM events WHERE id = ?', [(int) $override['recurrence_parent_id']]);
        $instanceUtc = (string) $override['recurrence_instance_utc'];
        $this->db->tx(function () use ($parent, $override, $instanceUtc): void {
            if ($parent !== null) {
                $exdates = $this->decodeExdates($parent);
                if (!in_array($instanceUtc, $exdates, true)) {
                    $exdates[] = $instanceUtc;
                }
                $this->db->update('events', ['exdates_json' => json_encode($exdates), 'updated_at' => Time::nowDb()], 'id = ?', [(int) $parent['id']]);
            }
            $this->db->run('DELETE FROM events WHERE id = ?', [(int) $override['id']]);
        });
        $beforeRows = $parent !== null ? [$parent, $override] : [$override];
        $afterParent = $parent !== null ? $this->db->one('SELECT * FROM events WHERE id = ?', [(int) $parent['id']]) : null;
        $this->undo->record(
            $userId,
            'event',
            (int) $override['id'],
            'delete',
            ['events' => $beforeRows],
            ['events' => $afterParent !== null ? [$afterParent] : []]
        );
    }

    public function setAttendance(int $userId, int $id, string $attendance): void
    {
        if (!in_array($attendance, self::ATTENDANCE, true)) {
            throw HttpError::badRequest('attendance must be none|interested|going|hidden');
        }
        $event = $this->get($userId, $id);
        $this->db->update('events', ['attendance' => $attendance], 'id = ?', [$id]);
        $after = $this->get($userId, $id);
        $this->undo->record($userId, 'event', $id, 'update', ['events' => [$event]], ['events' => [$after]]);
    }

    // ---- Serialization ------------------------------------------------

    /** Serialize an event row into an occurrence (fetching its own labels). */
    public function serializeSingle(array $row): array
    {
        $links = $this->labels->forEvents([(int) $row['id']]);
        return $this->serialize($row, Time::fromDb((string) $row['start_utc']), Time::fromDb((string) $row['end_utc']), $links);
    }

    /** @param list<array> $rows @return list<array> */
    public function serializeRows(array $rows): array
    {
        $links = $this->labels->forEvents(array_map(static fn($r) => (int) $r['id'], $rows));
        return array_map(
            fn(array $row) => $this->serialize($row, Time::fromDb((string) $row['start_utc']), Time::fromDb((string) $row['end_utc']), $links),
            $rows
        );
    }

    /** @param array{tags:array<int,list<string>>,people:array<int,list<string>>} $links */
    private function serialize(array $row, \DateTimeImmutable $startUtc, \DateTimeImmutable $endUtc, array $links): array
    {
        $id = (int) $row['id'];
        $tzid = (string) $row['tzid'];
        $tz = Time::zone($tzid);
        $style = null;
        if (!empty($row['style_json'])) {
            $style = is_array($row['style_json']) ? $row['style_json'] : json_decode((string) $row['style_json'], true);
        }
        $createdAt = Time::fromDb((string) $row['created_at']);
        return [
            'instanceId' => Recurrence::instanceId($id, $startUtc),
            'eventId' => $id,
            'calendarId' => (int) $row['calendar_id'],
            'uid' => (string) $row['uid'],
            'title' => (string) $row['title'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'location' => $row['location'] !== null ? (string) $row['location'] : null,
            'url' => $row['url'] !== null ? (string) $row['url'] : null,
            // All-day events are calendar dates, not instants: serialize the date
            // in the event's own zone at a fixed +00:00 midnight so clients can
            // read the date portion literally and never shift it across zones.
            'start' => (int) $row['all_day'] === 1
                ? $startUtc->setTimezone($tz)->format('Y-m-d') . 'T00:00:00+00:00'
                : Time::iso($startUtc->setTimezone($tz)),
            'end' => (int) $row['all_day'] === 1
                ? $endUtc->setTimezone($tz)->format('Y-m-d') . 'T00:00:00+00:00'
                : Time::iso($endUtc->setTimezone($tz)),
            'allDay' => (int) $row['all_day'] === 1,
            'tzid' => $tzid,
            'recurring' => !empty($row['rrule']) || !empty($row['recurrence_parent_id']),
            'source' => (string) $row['source'],
            'attendance' => (string) $row['attendance'],
            'status' => (string) $row['status'],
            'tags' => $links['tags'][$id] ?? [],
            'people' => $links['people'][$id] ?? [],
            'styleJson' => $style ?: null,
            'createdAt' => Time::iso($createdAt),
            'updatedAt' => Time::iso(Time::fromDb((string) $row['updated_at'])),
            'isNew' => $this->isNew($row, $createdAt),
        ];
    }

    /**
     * "New" means it recently appeared on YOUR calendar. A feed's initial
     * import is not new (everything would light up); only events that show up
     * in a poll after the subscription settles (5 min grace) count.
     */
    private function isNew(array $row, \DateTimeImmutable $createdAt): bool
    {
        if ($createdAt <= Time::nowUtc()->sub(new \DateInterval('PT' . self::NEW_WINDOW_HOURS . 'H'))) {
            return false;
        }
        if ((string) $row['source'] !== 'feed') {
            return true;
        }
        $calCreated = $this->calendarCreatedAt((int) $row['calendar_id']);
        return $calCreated === null || $createdAt > $calCreated->add(new \DateInterval('PT5M'));
    }

    // ---- Helpers ------------------------------------------------------

    /** Map API patch fields onto event columns; only returns changed columns. */
    private function columnPatch(array $current, array $in, ?string $fallbackInstance = null, bool $forOverride = false): array
    {
        $fields = [];
        $tzid = isset($in['tzid']) ? Time::normalizeTzid((string) $in['tzid']) : (string) $current['tzid'];
        if (isset($in['tzid'])) {
            $fields['tzid'] = $tzid;
        }
        $allDay = array_key_exists('allDay', $in)
            ? filter_var($in['allDay'], FILTER_VALIDATE_BOOL)
            : (int) $current['all_day'] === 1;
        if (array_key_exists('allDay', $in)) {
            $fields['all_day'] = $allDay ? 1 : 0;
        }

        if (isset($in['start']) || isset($in['end'])) {
            $curStart = Time::fromDb((string) $current['start_utc']);
            $curEnd = Time::fromDb((string) $current['end_utc']);
            $newStart = isset($in['start']) ? Time::parseIso((string) $in['start'], $tzid) : $curStart;
            $newEnd = isset($in['end']) ? Time::parseIso((string) $in['end'], $tzid) : $curEnd;
            if ($allDay) {
                $tz = Time::zone($tzid);
                $newStart = $newStart->setTimezone($tz)->setTime(0, 0)->setTimezone(Time::utc());
                $newEnd = $newEnd->setTimezone($tz)->setTime(0, 0)->setTimezone(Time::utc());
                if ($newEnd <= $newStart) {
                    $newEnd = $newStart->add(new \DateInterval('P1D'));
                }
            }
            if ($newEnd <= $newStart) {
                throw HttpError::badRequest('end must be after start');
            }
            $fields['start_utc'] = Time::toDb($newStart);
            $fields['end_utc'] = Time::toDb($newEnd);
        }

        foreach (['title' => 'title', 'description' => 'description', 'location' => 'location', 'url' => 'url'] as $key => $col) {
            if (array_key_exists($key, $in)) {
                $value = $in[$key];
                $fields[$col] = $value === null ? null : (string) $value;
                if ($col === 'title') {
                    $fields[$col] = mb_substr(trim((string) $value), 0, 500);
                }
                if ($col === 'location' && $fields[$col] !== null) {
                    $fields[$col] = mb_substr($fields[$col], 0, 500);
                }
            }
        }
        if (array_key_exists('status', $in) && $in['status'] !== null) {
            $fields['status'] = $this->statusOrDefault($in['status']);
        }
        if (array_key_exists('calendarId', $in)) {
            $fields['calendar_id'] = (int) $in['calendarId'];
        }
        if (array_key_exists('rrule', $in) && !$forOverride) {
            $fields['rrule'] = ($in['rrule'] === null || $in['rrule'] === '') ? null : (string) $in['rrule'];
        }
        return $fields;
    }

    /** @return array{0:string,1:string} start/end as UTC db strings */
    private function parseTimes(array $in, bool $allDay, string $tzid, ?array $current): array
    {
        if (!isset($in['start'])) {
            throw HttpError::badRequest('start is required');
        }
        $start = Time::parseIso((string) $in['start'], $tzid);
        $end = isset($in['end']) ? Time::parseIso((string) $in['end'], $tzid) : null;
        if ($allDay) {
            $tz = Time::zone($tzid);
            $start = $start->setTimezone($tz)->setTime(0, 0)->setTimezone(Time::utc());
            $end = $end?->setTimezone($tz)->setTime(0, 0)->setTimezone(Time::utc());
            if ($end === null || $end <= $start) {
                $end = $start->add(new \DateInterval('P1D'));
            }
        } else {
            $end ??= $start->add(new \DateInterval('PT1H'));
        }
        if ($end <= $start) {
            throw HttpError::badRequest('end must be after start');
        }
        return [Time::toDb($start), Time::toDb($end)];
    }

    private function requireInstance(array $in, array $master): string
    {
        $raw = $in['instanceStart'] ?? null;
        if ($raw === null || $raw === '') {
            throw HttpError::badRequest('instanceStart is required for this scope');
        }
        return Time::toDb(Time::parseIso((string) $raw, (string) $master['tzid']));
    }

    private function copyForChild(array $master): array
    {
        $copy = $master;
        unset($copy['id']);
        $copy['created_at'] = Time::nowDb();
        $copy['updated_at'] = Time::nowDb();
        $copy['deleted_at'] = null;
        return $copy;
    }

    private function decodeExdates(array $row): array
    {
        if (empty($row['exdates_json'])) {
            return [];
        }
        $decoded = is_array($row['exdates_json']) ? $row['exdates_json'] : json_decode((string) $row['exdates_json'], true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    private function exdatesFrom(array $master, string $instanceUtc): ?string
    {
        $kept = array_values(array_filter($this->decodeExdates($master), static fn(string $ex) => $ex >= $instanceUtc));
        return $kept === [] ? null : json_encode($kept);
    }

    private function stripCount(string $rrule): string
    {
        $parts = Recurrence::rruleParts($rrule);
        unset($parts['COUNT']);
        $out = [];
        foreach ($parts as $k => $v) {
            $out[] = $k . '=' . $v;
        }
        return implode(';', $out);
    }

    private function statusOrDefault(mixed $status): string
    {
        $status = strtolower((string) ($status ?? 'confirmed'));
        return in_array($status, ['confirmed', 'tentative', 'cancelled'], true) ? $status : 'confirmed';
    }
}
