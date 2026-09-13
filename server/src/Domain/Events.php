<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Dav\ChangeLog;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Ids;
use BetterCal\Support\Time;

final class Events
{
    private const MAX_WINDOW_SECONDS = 2 * 366 * 86400; // ~2 year hard cap per query
    // How long a genuinely-new event wears its badge. A day is long enough to
    // catch it on the next visit without the calendar staying lit for most of
    // a week.
    private const NEW_WINDOW_HOURS = 24;
    private const SCOPES = ['this', 'following', 'all'];
    private const ATTENDANCE = ['none', 'interested', 'going', 'hidden'];

    public function __construct(
        private readonly Db $db,
        private readonly Recurrence $recurrence,
        private readonly Undo $undo,
        private readonly Labels $labels,
        private readonly Filters $filters,
        private readonly Trips $trips,
    ) {
    }

    /** @var array<int, array{createdAt:\DateTimeImmutable,kind:string,reminderDefaults:?array}>|null calendar id => meta, lazy per request */
    private ?array $calMeta = null;
    /** @var array<int, array{timed:list<array>,allDay:list<array>}> user id => global reminder defaults, lazy */
    private array $userReminderDefaults = [];
    /** @var array<int, ?string> recurrence parent id => rrule, memoized per request */
    private array $parentRrules = [];

    /** The master's rrule for an override row (null when the parent is gone). */
    private function parentRrule(int $parentId): ?string
    {
        if (!array_key_exists($parentId, $this->parentRrules)) {
            $value = $this->db->scalar('SELECT rrule FROM events WHERE id = ?', [$parentId]);
            $this->parentRrules[$parentId] = is_string($value) && $value !== '' ? $value : null;
        }
        return $this->parentRrules[$parentId];
    }

    /** @return array{createdAt:\DateTimeImmutable,kind:string,reminderDefaults:?array}|null */
    private function calendarMeta(int $calendarId): ?array
    {
        if ($this->calMeta === null) {
            $this->calMeta = [];
            foreach ($this->db->all('SELECT id, created_at, kind, settings_json FROM calendars') as $c) {
                $settings = is_string($c['settings_json'] ?? null)
                    ? json_decode((string) $c['settings_json'], true)
                    : $c['settings_json'];
                $defaults = is_array($settings) && isset($settings['reminderDefaults']) && is_array($settings['reminderDefaults'])
                    ? $settings['reminderDefaults']
                    : null;
                $this->calMeta[(int) $c['id']] = [
                    'createdAt' => Time::fromDb((string) $c['created_at']),
                    'kind' => (string) $c['kind'],
                    'reminderDefaults' => $defaults,
                ];
            }
        }
        return $this->calMeta[$calendarId] ?? null;
    }

    /** @var array<int,string> user id => tzid, memoized per request */
    private array $userTz = [];

    /**
     * The user's own timezone: their stored setting (the client posts it at
     * boot), else the most common non-UTC tzid already on their calendars,
     * else UTC. Never a hardcoded region.
     */
    private function userTzid(int $userId): string
    {
        if (isset($this->userTz[$userId])) {
            return $this->userTz[$userId];
        }
        $raw = $this->db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]);
        $settings = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $tz = is_string($settings['tz'] ?? null) && $settings['tz'] !== '' ? $settings['tz'] : null;
        if ($tz === null) {
            // UTC is excluded: a bulk import writes thousands of rows as UTC,
            // which means "unknown", not a place anyone lives.
            $guess = $this->db->scalar(
                "SELECT e.tzid FROM events e JOIN calendars c ON c.id = e.calendar_id
                 WHERE e.user_id = ? AND e.deleted_at IS NULL AND e.tzid <> '' AND e.tzid <> 'UTC'
                   AND c.kind <> 'plugin'
                 GROUP BY e.tzid ORDER BY COUNT(*) DESC LIMIT 1",
                [$userId]
            );
            $tz = is_string($guess) && $guess !== '' ? $guess : 'UTC';
        }
        return $this->userTz[$userId] = Time::normalizeTzid($tz);
    }

    private function calendarCreatedAt(int $calendarId): ?\DateTimeImmutable
    {
        return $this->calendarMeta($calendarId)['createdAt'] ?? null;
    }

    /** @return array{timed:list<array>,allDay:list<array>} */
    private function reminderDefaultsForUser(int $userId): array
    {
        if (!isset($this->userReminderDefaults[$userId])) {
            $raw = $this->db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]);
            $stored = is_string($raw) ? json_decode($raw, true) : $raw;
            $settings = Settings::withDefaults(is_array($stored) ? $stored : []);
            $this->userReminderDefaults[$userId] = [
                'timed' => is_array($settings['reminderTimed'] ?? null) ? $settings['reminderTimed'] : [],
                'allDay' => is_array($settings['reminderAllDay'] ?? null) ? $settings['reminderAllDay'] : [],
            ];
        }
        return $this->userReminderDefaults[$userId];
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

        $qContext = $this->queryContext($userId, $q);

        $params = [$userId, Time::toDb($end), Time::toDb($start), Time::toDb($end)];
        $sql = 'SELECT * FROM events WHERE user_id = ? AND deleted_at IS NULL AND recurrence_parent_id IS NULL
                AND ((rrule IS NULL AND start_utc < ? AND end_utc > ?) OR (rrule IS NOT NULL AND start_utc < ?))';
        $sql .= $this->windowFilters($params, $calendarIds, $qContext, $includeHidden);
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

        // User filters: hide drops the occurrence, dim/highlight mark it
        // (additive fields). Keyword/regex filters match inline; prompt
        // filters join the cached background verdicts (filter_evals) — no
        // LLM calls here, ever.
        $activeFilters = $this->filters->enabledForUser($userId);
        $windowRows = [];
        foreach ($expanded as $occ) {
            $windowRows[(int) $occ['row']['id']] ??= $occ['row'];
        }
        $promptCtx = $this->filters->promptFilterContext($userId, array_keys($windowRows));

        // When an enabled keyword/regex filter matches the tags field, tag
        // links are needed at disposition time: load them for the candidate
        // rows up front (the same link set is reused for serialization).
        $links = Filters::anyUsesTags($activeFilters)
            ? $this->labels->forEvents(array_keys($windowRows))
            : null;

        // On-read healing: feed events in this window that an enabled prompt
        // filter covers but has no cached verdict for (e.g. past months never
        // swept) get queued for background evaluation. Enqueue only — the LLM
        // never runs in the request path.
        if ($promptCtx['filters'] !== []) {
            $missing = [];
            foreach ($windowRows as $eventId => $row) {
                if ((string) $row['source'] !== 'feed') {
                    continue;
                }
                foreach ($promptCtx['filters'] as $pf) {
                    if ($pf['calendarIds'] !== null && !isset($pf['calendarIds'][(int) $row['calendar_id']])) {
                        continue;
                    }
                    if (!isset($promptCtx['evaluated'][$pf['id']][$eventId])) {
                        $missing[] = $eventId;
                        break;
                    }
                }
            }
            if ($missing !== []) {
                $this->filters->enqueueEvalForEvents($missing);
            }
        }
        if ($activeFilters !== [] || $promptCtx['filters'] !== []) {
            $kept = [];
            foreach ($expanded as $occ) {
                $row = $occ['row'];
                if ($links !== null) {
                    $row['tags'] = $links['tags'][(int) $row['id']] ?? [];
                }
                $disposition = Filters::strongest(
                    Filters::disposition($row, $activeFilters),
                    Filters::promptDisposition($row, $promptCtx['filters'], $promptCtx['failed'])
                );
                // includeHidden=1 also bypasses filter hiding: reminders fire
                // regardless of filters, and the notification deep link must
                // be able to resolve the occurrence it points at.
                if ($disposition === 'hide' && !$includeHidden) {
                    continue;
                }
                if ($disposition === 'dim') {
                    $occ['dimmed'] = true;
                } elseif ($disposition === 'highlight') {
                    $occ['highlighted'] = true;
                    // Which filter won decides the glow colour; keyword and
                    // regex filters answer first, then prompt filters.
                    $occ['highlightColor'] = Filters::highlightColorFor($row, $activeFilters)
                        ?? Filters::promptHighlightColorFor($row, $promptCtx['filters'], $promptCtx['failed']);
                }
                $kept[] = $occ;
            }
            $expanded = $kept;
        }

        $eventIds = [];
        foreach ($expanded as $occ) {
            $eventIds[(int) $occ['row']['id']] = true;
        }
        if ($links === null) {
            $links = $this->labels->forEvents(array_keys($eventIds));
        }
        // Trip membership, batch-loaded like tags (one query, no N+1).
        $links['containers'] = $this->trips->containersFor(array_keys($eventIds));

        return array_map(
            function (array $occ) use ($links): array {
                $serialized = $this->serialize($occ['row'], $occ['start'], $occ['end'], $links, full: false);
                if (!empty($occ['dimmed'])) {
                    $serialized['dimmed'] = true;
                }
                if (!empty($occ['highlighted'])) {
                    $serialized['highlighted'] = true;
                    if (!empty($occ['highlightColor'])) {
                        $serialized['highlightColor'] = $occ['highlightColor'];
                    }
                }
                return $serialized;
            },
            $expanded
        );
    }

    /**
     * Resolve a q= text query into SQL-ready context: LIKE text match plus
     * tag-name matches; a query equal to or prefixed with `#` searches tags
     * only (mirrors GET /search).
     *
     * @return array{like:?string,tagOnly:bool,tagEventIds:list<int>}|null null = no query
     */
    private function queryContext(int $userId, ?string $q): ?array
    {
        $q = $q !== null ? trim($q) : '';
        if ($q === '') {
            return null;
        }
        $tagOnly = str_starts_with($q, '#');
        $term = $tagOnly ? trim(mb_substr($q, 1)) : $q;
        return [
            'like' => $tagOnly || $term === '' ? null : '%' . addcslashes($term, '%_\\') . '%',
            'tagOnly' => $tagOnly,
            'tagEventIds' => $term !== '' ? $this->labels->eventIdsForTagQuery($userId, $term) : [],
        ];
    }

    private function windowFilters(array &$params, ?array $calendarIds, ?array $qContext, bool $includeHidden): string
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
        if ($qContext !== null) {
            [$tagIn, $tagParams] = Db::in($qContext['tagEventIds'] !== [] ? $qContext['tagEventIds'] : [0]);
            if ($qContext['tagOnly'] || $qContext['like'] === null) {
                $sql .= " AND id IN $tagIn";
                array_push($params, ...$tagParams);
            } else {
                $sql .= " AND (title LIKE ? OR description LIKE ? OR location LIKE ? OR id IN $tagIn)";
                array_push($params, $qContext['like'], $qContext['like'], $qContext['like'], ...$tagParams);
            }
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
        if (($calendar['kind'] ?? '') === 'plugin' && !str_starts_with(ActivityContext::get(), 'plugin:')) {
            throw HttpError::forbidden('plugin_readonly', 'This calendar is managed by a plugin');
        }

        // No tzid supplied (a server-side creator: an accepted proposal, an
        // agent call). Fall back to the USER's zone rather than a constant —
        // hardcoding Pacific here silently stamps every such event with the
        // wrong zone for anyone who does not live there, which changes how
        // all-day events and recurrences are interpreted.
        $tzid = isset($in['tzid'])
            ? Time::normalizeTzid((string) $in['tzid'])
            : $this->userTzid($userId);
        $allDay = filter_var($in['allDay'] ?? false, FILTER_VALIDATE_BOOL);
        [$startUtc, $endUtc] = $this->parseTimes($in, $allDay, $tzid, null);

        $rrule = null;
        if (!empty($in['rrule'])) {
            $rrule = Recurrence::validateRrule((string) $in['rrule']);
        }

        $row = [
            'user_id' => $userId,
            'calendar_id' => $calendarId,
            // Callers with an external identity (mail ingest iMIP) pass uid so
            // updates and cancellations can find the event again.
            'uid' => is_string($in['uid'] ?? null) && trim($in['uid']) !== ''
                ? mb_substr(trim($in['uid']), 0, 255)
                : Ids::ulid(),
            'title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 500),
            'description' => isset($in['description']) ? Sanitize::description((string) $in['description']) : null,
            'location' => isset($in['location']) ? mb_substr((string) $in['location'], 0, 500) : null,
            'location_lat' => self::coordOrNull($in, 'locationLat'),
            'location_lng' => self::coordOrNull($in, 'locationLng'),
            'url' => isset($in['url']) ? (string) $in['url'] : null,
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
            'all_day' => $allDay ? 1 : 0,
            'tzid' => $tzid,
            'rrule' => $rrule,
            'is_container' => filter_var($in['isContainer'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0,
            'reminders_json' => array_key_exists('reminders', $in)
                ? self::encodeReminders(Reminders::validateEventReminders($in['reminders']))
                : null,
            'status' => $this->statusOrDefault($in['status'] ?? null),
            'source' => 'local',
            'created_via' => ActivityContext::get(),
            'created_at' => Time::nowDb(),
            'updated_at' => Time::nowDb(),
        ];

        $id = $this->db->tx(function () use ($row, $userId, $in): int {
            $id = $this->db->insert('events', $row);
            if (!empty($in['tagNames']) && is_array($in['tagNames'])) {
                $this->labels->setEventTags($userId, $id, $in['tagNames']);
            }
            $this->applyPeoplePatch($userId, $id, $in);
            return $id;
        });

        $created = $this->get($userId, $id);
        $this->undo->record($userId, 'event', $id, 'create', null, ['events' => [$created]] + $this->labels->eventLinkRows($id));
        ChangeLog::record($this->db, (int) $created['calendar_id'], (string) $created['uid'], ChangeLog::OP_ADD);
        return $this->serializeSingle($created);
    }

    public function patch(int $userId, int $id, array $in): void
    {
        $event = $this->get($userId, $id);
        // Reminders are user-local metadata (like tags), so feed events accept
        // them even though their feed-derived content is read-only.
        $editKeys = array_diff(array_keys($in), ['scope', 'instanceStart', 'tagNames', 'reminders']);
        if ($event['source'] === 'feed' && $editKeys !== []) {
            throw HttpError::forbidden('feed_readonly', 'Feed events are read-only except attendance, tags and reminders');
        }
        if (($this->calendarMeta((int) $event['calendar_id'])['kind'] ?? '') === 'plugin' && $editKeys !== []
            && !str_starts_with(ActivityContext::get(), 'plugin:')) {
            throw HttpError::forbidden('plugin_readonly', 'Plugin events are read-only except attendance, tags and reminders');
        }

        // Clearing the trip flag requires an empty trip: members would
        // otherwise dangle pointing at a non-container.
        if (
            array_key_exists('isContainer', $in)
            && !filter_var($in['isContainer'], FILTER_VALIDATE_BOOL)
            && (int) ($event['is_container'] ?? 0) === 1
        ) {
            Trips::assertClearable($this->trips->liveMemberCount($id));
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
            if ($calendar['kind'] === 'plugin') {
                throw HttpError::forbidden('plugin_readonly', 'Cannot move events onto a plugin-managed calendar');
            }
        }

        // Dragging a trip band can move the whole trip: shift every linked
        // member by the same offset, atomically, in ONE undo entry. Done
        // server-side because the client only sees members inside its loaded
        // window; a client-side loop would silently strand the rest.
        if (
            !empty($in['moveMembers'])
            && (int) ($event['is_container'] ?? 0) === 1
            && isset($in['start'])
        ) {
            $this->moveContainerWithMembers($userId, $event, $in);
            return;
        }

        match ($scope) {
            'this' => $this->patchThis($userId, $event, $in),
            'following' => $this->patchFollowing($userId, $event, $in),
            'all' => $this->patchAll($userId, $event, $in),
        };
    }

    /** Shift a container and all its members by the container's start delta. */
    private function moveContainerWithMembers(int $userId, array $event, array $in): void
    {
        $id = (int) $event['id'];
        $fields = $this->columnPatch($event, $in);
        $deltaSec = isset($fields['start_utc'])
            ? Time::fromDb((string) $fields['start_utc'])->getTimestamp() - Time::fromDb((string) $event['start_utc'])->getTimestamp()
            : 0;

        $members = $this->db->all(
            'SELECT e.* FROM events e JOIN event_links l ON l.event_id = e.id
             WHERE l.container_id = ? AND e.user_id = ? AND e.deleted_at IS NULL',
            [$id, $userId]
        );

        $beforeRows = array_merge([$event], $members);
        $this->db->tx(function () use ($id, $fields, $members, $deltaSec): void {
            if ($fields !== []) {
                $this->db->update('events', $fields + ['updated_at' => Time::nowDb()], 'id = ?', [$id]);
            }
            if ($deltaSec !== 0) {
                foreach ($members as $m) {
                    $this->db->update('events', [
                        'start_utc' => Time::toDb(Time::fromDb((string) $m['start_utc'])->modify(($deltaSec >= 0 ? '+' : '') . $deltaSec . ' seconds')),
                        'end_utc' => Time::toDb(Time::fromDb((string) $m['end_utc'])->modify(($deltaSec >= 0 ? '+' : '') . $deltaSec . ' seconds')),
                        'updated_at' => Time::nowDb(),
                    ], 'id = ?', [(int) $m['id']]);
                }
            }
        });

        $afterRows = [$this->get($userId, $id)];
        foreach ($members as $m) {
            $afterRows[] = $this->get($userId, (int) $m['id']);
        }
        $this->undo->record(
            $userId,
            'event',
            $id,
            'update',
            ['events' => $beforeRows],
            ['events' => $afterRows],
            'Moved trip \'' . (string) $event['title'] . '\' and ' . count($members) . ' event' . (count($members) === 1 ? '' : 's')
        );
        ChangeLog::record($this->db, (int) $event['calendar_id'], (string) $event['uid'], ChangeLog::OP_MODIFY);
        foreach ($members as $m) {
            ChangeLog::record($this->db, (int) $m['calendar_id'], (string) $m['uid'], ChangeLog::OP_MODIFY);
        }
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
            $this->applyPeoplePatch($userId, $id, $in);
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
        ChangeLog::recordUpdate($this->db, $event, $after);
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
            // forOverride: an instance row can no more carry the series RRULE
            // or become a trip than a freshly split one can. Without it, the
            // editor (which always sends the series rrule it seeded from) was
            // stamping FREQ=... onto an imported moved instance on every edit.
            // Inert to expansion (masters are selected by parent IS NULL) but
            // wrong on the row, and a trap for anything reading rrule.
            $fields = $this->columnPatch($existing, $in, $instanceUtc, forOverride: true);
            $this->db->tx(function () use ($existing, $userId, $fields, $in): void {
                if ($fields !== []) {
                    $fields['updated_at'] = Time::nowDb();
                    $this->db->update('events', $fields, 'id = ?', [(int) $existing['id']]);
                }
                if (array_key_exists('tagNames', $in) && is_array($in['tagNames'])) {
                    $this->labels->setEventTags($userId, (int) $existing['id'], $in['tagNames']);
                }
                $this->applyPeoplePatch($userId, (int) $existing['id'], $in);
            });
            $after = $this->get($userId, (int) $existing['id']);
            $this->undo->record($userId, 'event', (int) $existing['id'], 'update', ['events' => [$existing]], ['events' => [$after]]);
            ChangeLog::record($this->db, (int) $master['calendar_id'], (string) $master['uid'], ChangeLog::OP_MODIFY);
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
        // The override row is created NOW by whoever is editing this instance,
        // not by whatever created the master; otherwise splitting one instance
        // of a feed/agent series mints a fresh created_at under an automated
        // via and the "new" pill appears on the user's own edit.
        $override['created_via'] = ActivityContext::get();
        $override['start_utc'] = Time::toDb($instStart);
        $override['end_utc'] = Time::toDb($instStart->add(new \DateInterval('PT' . $duration . 'S')));
        $override = array_merge($override, $this->columnPatch($override, $in, $instanceUtc, forOverride: true));

        $newId = $this->db->tx(function () use ($override, $masterId, $userId, $in): int {
            $id = $this->db->insert('events', $override);
            // The override represents the same event on one day, so it starts
            // with the master's tags and people; explicit patch values then
            // replace them (this is what lets "add person to this occurrence
            // only" work at all — links are keyed by row id).
            $this->db->run(
                'INSERT IGNORE INTO event_tags (event_id, tag_id) SELECT ?, tag_id FROM event_tags WHERE event_id = ?',
                [$id, $masterId]
            );
            $this->db->run(
                'INSERT IGNORE INTO event_people (event_id, person_id) SELECT ?, person_id FROM event_people WHERE event_id = ?',
                [$id, $masterId]
            );
            if (array_key_exists('tagNames', $in) && is_array($in['tagNames'])) {
                $this->labels->setEventTags($userId, $id, $in['tagNames']);
            }
            $this->applyPeoplePatch($userId, $id, $in);
            return $id;
        });
        $created = $this->get($userId, $newId);
        $this->undo->record($userId, 'event', $masterId, 'update', ['events' => [$master]], ['events' => [$master, $created]]);
        ChangeLog::record($this->db, (int) $master['calendar_id'], (string) $master['uid'], ChangeLog::OP_MODIFY);
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
        $newMaster['created_via'] = ActivityContext::get(); // the splitter, not the original creator
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
        ChangeLog::record($this->db, (int) $master['calendar_id'], (string) $master['uid'], ChangeLog::OP_MODIFY);
        ChangeLog::record($this->db, (int) $created['calendar_id'], (string) $created['uid'], ChangeLog::OP_ADD);
    }

    public function deleteEvent(int $userId, int $id, ?string $scope, ?string $instanceStart): void
    {
        $event = $this->get($userId, $id);
        if ($event['source'] === 'feed') {
            throw HttpError::forbidden('feed_readonly', 'Feed events cannot be deleted; hide them instead');
        }
        if (($this->calendarMeta((int) $event['calendar_id'])['kind'] ?? '') === 'plugin'
            && !str_starts_with(ActivityContext::get(), 'plugin:')) {
            throw HttpError::forbidden('plugin_readonly', 'Plugin events are managed by their plugin; hide the calendar instead');
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
        ChangeLog::record($this->db, (int) $event['calendar_id'], (string) $event['uid'], ChangeLog::OP_DELETE);
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
        ChangeLog::record($this->db, (int) $master['calendar_id'], (string) $master['uid'], ChangeLog::OP_MODIFY);
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
        ChangeLog::record($this->db, (int) $master['calendar_id'], (string) $master['uid'], ChangeLog::OP_MODIFY);
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
        ChangeLog::record($this->db, (int) $override['calendar_id'], (string) $override['uid'], ChangeLog::OP_MODIFY);
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
        ChangeLog::record($this->db, (int) $event['calendar_id'], (string) $event['uid'], ChangeLog::OP_MODIFY);

        // Triage on feed events doubles as ranking training signal (PRD 5.8):
        // committing/starring is an up, hiding is a down. Clearing is neutral.
        if ((string) $event['source'] === 'feed' && (string) $event['attendance'] !== $attendance) {
            $kind = match ($attendance) {
                'going', 'interested' => 'up',
                'hidden' => 'down',
                default => null,
            };
            if ($kind !== null) {
                $this->db->insert('feedback_signals', ['user_id' => $userId, 'event_id' => $id, 'kind' => $kind]);
            }
        }
    }

    /** Explicit thumbs feedback ('up'|'down'); pure training signal, no undo. */
    public function recordFeedback(int $userId, int $id, string $signal): void
    {
        if (!in_array($signal, ['up', 'down'], true)) {
            throw HttpError::badRequest('signal must be up|down');
        }
        $this->get($userId, $id); // ownership + existence
        $this->db->insert('feedback_signals', ['user_id' => $userId, 'event_id' => $id, 'kind' => $signal]);
    }

    // ---- Serialization ------------------------------------------------

    /**
     * Serialize ONE event row as the full record (fetching its own labels).
     * This is the detail shape: what the editor, the detail view and API
     * clients get when they ask for a specific event, and what a create
     * returns. It carries everything, uid included.
     */
    public function serializeSingle(array $row): array
    {
        $links = $this->labels->forEvents([(int) $row['id']]);
        $links['containers'] = $this->trips->containersFor([(int) $row['id']]);
        return $this->serialize($row, Time::fromDb((string) $row['start_utc']), Time::fromDb((string) $row['end_utc']), $links, full: true);
    }

    /**
     * Serialize many rows as grid occurrences: the window, search results,
     * a person's events, a trip's members. This shape is what thousands of
     * rows travel as, so it carries what the grid draws and nothing it does
     * not (#15). `uid` is dropped — no client surface reads it — and null
     * fields are omitted rather than sent as `"key":null`; consumers treat a
     * missing key as null, which every reader already did.
     *
     * @param list<array> $rows @return list<array>
     */
    public function serializeRows(array $rows): array
    {
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $links = $this->labels->forEvents($ids);
        $links['containers'] = $this->trips->containersFor($ids);
        return array_map(
            fn(array $row) => $this->serialize($row, Time::fromDb((string) $row['start_utc']), Time::fromDb((string) $row['end_utc']), $links, full: false),
            $rows
        );
    }

    /**
     * Measured cost of what this trims, one-month window, 725 occurrences,
     * 793 bytes each before: `uid` 76 bytes/occurrence (9.6%); a null field
     * costs its key plus `:null,` on every row it is absent from, and
     * description alone is null on 98% of them.
     *
     * @param array{tags:array<int,list<string>>,people:array<int,list<string>>,containers:array<int,list<array{eventId:int,title:string}>>} $links
     */
    private function serialize(array $row, \DateTimeImmutable $startUtc, \DateTimeImmutable $endUtc, array $links, bool $full = false): array
    {
        $out = $this->serializeFields($row, $startUtc, $endUtc, $links, $full);
        if ($full) {
            return $out;
        }
        // The list shape: what the grid draws. Everything the detail view,
        // the popover and the editor need beyond this rides the single-event
        // fetch on open (#15). Nulls and empty lists are omitted; a missing
        // key reads as null / [] on every client surface.
        foreach (self::DETAIL_ONLY as $k) {
            unset($out[$k]);
        }
        return array_filter($out, static fn($v): bool => $v !== null && $v !== []);
    }

    /**
     * Fields only the single-event record carries. Measured per occurrence
     * before removal: uid 76, reminders 43, createdAt 39, updatedAt 39,
     * rrule 35, tzid 28, reminderSource 26, description 21 — a third of the
     * row, none of it read by anything that draws a grid.
     */
    private const DETAIL_ONLY = ['uid', 'createdAt', 'updatedAt', 'reminders', 'reminderSource', 'rrule', 'description', 'tzid'];

    /**
     * Beyond bytes, the list shape skips work: effective reminders need the
     * calendar's defaults and the user's globals per row, and an override's
     * rrule is a lookup of its parent per row — an N+1 the window used to pay
     * for a field nothing on the window read.
     *
     * @param array{tags:array<int,list<string>>,people:array<int,list<string>>,containers:array<int,list<array{eventId:int,title:string}>>} $links
     */
    private function serializeFields(array $row, \DateTimeImmutable $startUtc, \DateTimeImmutable $endUtc, array $links, bool $full): array
    {
        $id = (int) $row['id'];
        $tzid = (string) $row['tzid'];
        $tz = Time::zone($tzid);
        $style = null;
        if (!empty($row['style_json'])) {
            $style = is_array($row['style_json']) ? $row['style_json'] : json_decode((string) $row['style_json'], true);
        }
        $createdAt = Time::fromDb((string) $row['created_at']);
        $reminders = [];
        $reminderSource = null;
        $rrule = null;
        if ($full) {
            $calMeta = $this->calendarMeta((int) $row['calendar_id']);
            $globals = $this->reminderDefaultsForUser((int) $row['user_id']);
            [$reminders, $reminderSource] = Reminders::effective(
                Reminders::decode($row['reminders_json'] ?? null),
                $calMeta['reminderDefaults'] ?? null,
                $globals['timed'],
                $globals['allDay'],
                (int) $row['all_day'] === 1,
                $calMeta['kind'] ?? 'local'
            );
            // Overrides carry no rrule of their own; surface the parent's so
            // clients can always describe the cadence, not just "repeats".
            $rrule = !empty($row['rrule'])
                ? (string) $row['rrule']
                : (!empty($row['recurrence_parent_id']) ? $this->parentRrule((int) $row['recurrence_parent_id']) : null);
        }
        return [
            'instanceId' => Recurrence::instanceId($id, $startUtc),
            'eventId' => $id,
            'calendarId' => (int) $row['calendar_id'],
            'uid' => (string) $row['uid'],
            'title' => (string) $row['title'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'location' => $row['location'] !== null ? (string) $row['location'] : null,
            'locationLat' => isset($row['location_lat']) ? (float) $row['location_lat'] : null,
            'locationLng' => isset($row['location_lng']) ? (float) $row['location_lng'] : null,
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
            'rrule' => $rrule,
            'source' => (string) $row['source'],
            'attendance' => (string) $row['attendance'],
            'status' => (string) $row['status'],
            'score' => isset($row['score']) && $row['score'] !== null ? (float) $row['score'] : null,
            'reminders' => $reminders,
            'reminderSource' => $reminderSource,
            'tags' => $links['tags'][$id] ?? [],
            'people' => $links['people'][$id] ?? [],
            'isContainer' => (int) ($row['is_container'] ?? 0) === 1,
            // Mail-ingested invitations: organizer/attendees/partstat for the
            // detail view's invitation panel and RSVP.
            'invite' => isset($row['invite_json']) && $row['invite_json'] !== null
                ? (is_array($row['invite_json']) ? $row['invite_json'] : json_decode((string) $row['invite_json'], true))
                : null,
            'containers' => $links['containers'][$id] ?? [],
            'styleJson' => $style ?: null,
            'createdAt' => Time::iso($createdAt),
            'updatedAt' => Time::iso(Time::fromDb((string) $row['updated_at'])),
            'isNew' => $this->isNew($row, $createdAt),
        ];
    }

    /**
     * "New" means it recently ARRIVED on your calendar without you putting it
     * there: a feed poll, mail ingest, an agent writing through the API, an
     * import. Your own creations (web, quick add, your phone via CalDAV) are
     * never new — you were there. The check is source-based (created_via,
     * stamped at insert) so nothing an event does later can change it; the
     * old formula compared created_at against the CURRENT calendar's
     * initial-load window, which meant moving an event out of a young
     * calendar stripped its bulk suppression and resurrected the pill.
     */
    private function isNew(array $row, \DateTimeImmutable $createdAt): bool
    {
        return self::isNewFor(
            (string) ($row['created_via'] ?? 'web'),
            $createdAt,
            $this->calendarCreatedAt((int) $row['calendar_id']),
            Time::nowUtc()
        );
    }

    /** Pure form of the "new" rule, unit-tested in server/tests/run.php. */
    public static function isNewFor(string $via, \DateTimeImmutable $createdAt, ?\DateTimeImmutable $calCreated, \DateTimeImmutable $now): bool
    {
        // Only automated arrivals qualify.
        if ($via !== 'feed' && $via !== 'api' && $via !== 'import' && !str_starts_with($via, 'mail:') && !str_starts_with($via, 'plugin:')) {
            return false;
        }
        if ($createdAt <= $now->sub(new \DateInterval('PT' . self::NEW_WINDOW_HOURS . 'H'))) {
            return false;
        }
        // Bulk population is never "new": a feed's first poll and a Takeout
        // import both create thousands of rows at once, and marking them all
        // new makes the badge meaningless. Anything written within the
        // calendar's own first minutes is part of that initial load.
        return $calCreated === null || $createdAt > $calCreated->add(new \DateInterval('PT5M'));
    }

    // ---- Helpers ------------------------------------------------------

    /**
     * Apply an event's people patch, if the payload carries one at all.
     *
     * `personIds` is the authoritative, id-keyed form and is what the editor
     * sends. `personNames` upserts by name and stays for the callers with
     * nobody to prompt: ICS import, mail ingest, quick-add's own create, API
     * clients. When both arrive they union, so a client may pass known ids
     * alongside a genuinely new name.
     *
     * Why ids matter: a name-keyed save cannot tell "this is a new person"
     * from "my copy of this person's name is out of date". Rename someone in
     * the People manager, then save an event the client still believes is
     * linked to the old name, and the old name comes back as a second,
     * freshly created person while the real link is dropped.
     */
    private function applyPeoplePatch(int $userId, int $eventId, array $in): void
    {
        $hasIds = array_key_exists('personIds', $in) && is_array($in['personIds']);
        $hasNames = array_key_exists('personNames', $in) && is_array($in['personNames']);
        if (!$hasIds && !$hasNames) {
            return;
        }
        $ids = $hasIds ? $this->labels->ownedPersonIds($userId, $in['personIds']) : [];
        if ($hasNames) {
            $ids = array_merge($ids, $this->labels->personIds($userId, $in['personNames']));
        }
        $this->labels->replaceEventPeople($eventId, array_values(array_unique($ids)));
    }

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
                if ($col === 'description' && $fields[$col] !== null) {
                    $fields[$col] = Sanitize::description($fields[$col]);
                }
            }
        }
        foreach (['locationLat' => 'location_lat', 'locationLng' => 'location_lng'] as $key => $col) {
            if (array_key_exists($key, $in)) {
                if ($in[$key] !== null && !is_numeric($in[$key])) {
                    throw HttpError::badRequest("$key must be a number or null");
                }
                $fields[$col] = $in[$key] === null ? null : (float) $in[$key];
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
        if (array_key_exists('isContainer', $in) && !$forOverride) {
            // Clearing with live members is rejected in patch() before this runs.
            $fields['is_container'] = filter_var($in['isContainer'], FILTER_VALIDATE_BOOL) ? 1 : 0;
        }
        if (array_key_exists('reminders', $in)) {
            $fields['reminders_json'] = self::encodeReminders(Reminders::validateEventReminders($in['reminders']));
        }
        return $fields;
    }

    /** Optional locationLat/locationLng on create: number or null. */
    private static function coordOrNull(array $in, string $key): ?float
    {
        if (!array_key_exists($key, $in) || $in[$key] === null) {
            return null;
        }
        if (!is_numeric($in[$key])) {
            throw HttpError::badRequest("$key must be a number or null");
        }
        return (float) $in[$key];
    }

    /** null (inherit) stays NULL; [] and lists persist as JSON. */
    private static function encodeReminders(?array $reminders): ?string
    {
        return $reminders === null ? null : json_encode($reminders);
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
