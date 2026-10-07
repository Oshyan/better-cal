<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Dav\ChangeLog;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Ids;
use BetterCal\Support\Limits;
use BetterCal\Support\Time;

final class Events
{
    public const MAX_WINDOW_SECONDS = 2 * 366 * 86400; // ~2 year hard cap per query
    // How long a genuinely-new event wears its badge. A day is long enough to
    // catch it on the next visit without the calendar staying lit for most of
    // a week.
    private const NEW_WINDOW_HOURS = 24;
    private const SCOPES = ['this', 'following', 'all'];
    private const ATTENDANCE = ['none', 'interested', 'going', 'hidden'];
    /** Relationship words accepted where an attendance is (the stored enum stays as it was). */
    private const ATTENDANCE_ALIASES = ['planned' => 'going', 'maybe' => 'interested', 'available' => 'none', 'hidden' => 'hidden'];

    /**
     * What an event is to the person, on every event (docs/design: the
     * relationship scale). Derived: the calendar's role sets the default and
     * the row's own marks override it. Pure; unit-tested.
     *
     *   context   the calendar is information; never a plan
     *   hidden    marked hidden (only visible with includeHidden)
     *   planned   marked going, or on one of my calendars and not tentative
     *   maybe     marked interested, or on one of my calendars and tentative
     *   available on an opportunities calendar and not picked
     */
    public static function relationship(string $attendance, string $status, string $role): string
    {
        if ($role === 'context') {
            return 'context';
        }
        if ($attendance === 'hidden') {
            return 'hidden';
        }
        if ($attendance === 'going') {
            return 'planned';
        }
        if ($attendance === 'interested') {
            return 'maybe';
        }
        if ($role === 'mine') {
            return $status === 'tentative' ? 'maybe' : 'planned';
        }
        return 'available';
    }

    /**
     * Set the relationship with the vocabulary the calendar's role allows.
     * On my own calendar it is the event's status (tentative or confirmed,
     * which CalDAV clients and Google see too); on an opportunities calendar
     * it is the private attendance mark. Scope applies to a series as for
     * any other change.
     */
    public function setRelationship(int $userId, int $id, string $relationship, ?string $scope = null, ?string $instanceStart = null): void
    {
        $event = $this->get($userId, $id);
        $role = (string) ($this->calendarMeta((int) $event['calendar_id'])['role'] ?? 'mine');
        if ($role === 'context') {
            throw HttpError::badRequest('Events on a context calendar are information, not plans');
        }
        if ($role === 'mine') {
            if (!in_array($relationship, ['planned', 'maybe'], true)) {
                throw HttpError::badRequest('On your own calendar an event is planned or maybe');
            }
            $in = ['status' => $relationship === 'maybe' ? 'tentative' : 'confirmed'];
            if (!empty($event['rrule']) && empty($event['recurrence_parent_id'])) {
                $in['scope'] = $scope ?? 'all';
                $in['instanceStart'] = $instanceStart;
            }
            $this->patch($userId, $id, $in);
            return;
        }
        if (!isset(self::ATTENDANCE_ALIASES[$relationship])) {
            throw HttpError::badRequest('relationship must be planned|maybe|available|hidden');
        }
        $this->setAttendance($userId, $id, self::ATTENDANCE_ALIASES[$relationship], $scope, $instanceStart);
    }

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
    private ?GoogleWriter $googleWriter = null;

    // ---- Google write-through ---------------------------------------------------
    // A Google calendar the connected account may edit takes writes here, but
    // Google first: the change goes to the API, and what Google answers with
    // is materialised locally (GoogleWriter::apply) before the local-only
    // parts of the same edit (tags, people, reminders, the trip flag,
    // coordinates) are applied to the resulting row. Activity gets a plain
    // entry, not an undoable one: an undo would have to be a second write
    // to Google, and the row is Google's, not a snapshot of ours.

    /** The calendar row when this event lives on a writable Google calendar, else null. */
    private function googleCalendarFor(int $calendarId): ?array
    {
        $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]);
        return $calendar !== null && GoogleWriter::writable($calendar) ? $calendar : null;
    }

    private ?Duplicates $duplicates = null;

    /** The row duplicates are linked by: the series for an occurrence override. */
    private static function seriesId(array $row): int
    {
        return !empty($row['recurrence_parent_id']) ? (int) $row['recurrence_parent_id'] : (int) $row['id'];
    }

    private function duplicates(): Duplicates
    {
        return $this->duplicates ??= new Duplicates($this->db);
    }

    private function google(): GoogleWriter
    {
        return $this->googleWriter ??= new GoogleWriter($this->db, new GoogleAuth($this->db, config()), new Feeds($this->db, null, config()));
    }

    private static function requireGoogleId(array $row): string
    {
        $id = (string) ($row['google_event_id'] ?? '');
        if ($id === '') {
            throw new HttpError('google_unsynced', 'This event has not finished syncing from Google yet; refresh the calendar and try again.', 409);
        }
        return $id;
    }

    /** @return array{0:array,1:array} Google-owned columns and local-only columns */
    private static function splitGoogleFields(array $fields): array
    {
        unset($fields['url']); // Google's event link; not ours to edit
        $google = array_intersect_key($fields, array_flip(GoogleWriter::GOOGLE_COLUMNS));
        $local = array_diff_key($fields, $google);
        return [$google, $local];
    }

    private function googleJournal(int $userId, string $summary, array $calendar, ?int $eventId = null): void
    {
        $this->undo->record($userId, 'event', $eventId ?? 0, 'update', null, null, $summary . " on Google calendar '" . (string) $calendar['name'] . "'");
    }

    private function googleCreate(int $userId, array $calendar, array $row, array $in): array
    {
        $resp = $this->google()->insert($calendar, $row);
        $this->google()->apply($calendar, [$resp]);
        $created = $this->google()->rowByGoogleId((int) $calendar['id'], (string) ($resp['id'] ?? ''));
        if ($created === null) {
            throw new HttpError('google_write_failed', 'Google accepted the event but it did not come back on the calendar', 502);
        }
        $id = (int) $created['id'];
        $this->db->tx(function () use ($row, $id, $userId, $in): void {
            [, $local] = self::splitGoogleFields(array_intersect_key($row, array_flip(['reminders_json', 'is_container', 'location_lat', 'location_lng'])));
            if ($local !== []) {
                $this->db->update('events', $local, 'id = ?', [$id]);
            }
            if (!empty($in['tagNames']) && is_array($in['tagNames'])) {
                $this->labels->setEventTags($userId, $id, $in['tagNames']);
            }
            $this->applyPeoplePatch($userId, $id, $in);
        });
        $this->googleJournal($userId, "Created '" . (string) $row['title'] . "'", $calendar, $id);
        return $this->get($userId, $id);
    }

    private function googlePatchAll(int $userId, array $calendar, array $event, array $in): void
    {
        $id = (int) $event['id'];
        $in = $this->rebaseSeriesEdit($event, $in);
        [$googleFields, $localFields] = self::splitGoogleFields($this->columnPatch($event, $in));
        if ($googleFields !== []) {
            $googleId = self::requireGoogleId($event);
            $resp = $this->google()->patch($calendar, $googleId, GoogleWriter::body(array_merge($event, $googleFields)));
            $this->google()->apply($calendar, [$resp]);
        }
        $this->db->tx(function () use ($id, $userId, $localFields, $in): void {
            if ($localFields !== []) {
                $this->db->update('events', $localFields + ['updated_at' => Time::nowDb()], 'id = ?', [$id]);
            }
            if (array_key_exists('tagNames', $in) && is_array($in['tagNames'])) {
                $this->labels->setEventTags($userId, $id, $in['tagNames']);
            }
            $this->applyPeoplePatch($userId, $id, $in);
        });
        if ($googleFields !== []) {
            $this->googleJournal($userId, "Updated '" . (string) $event['title'] . "'", $calendar, $id);
        }
    }

    private function googlePatchThis(int $userId, array $calendar, array $master, array $in): void
    {
        $instanceUtc = $this->requireInstance($in, $master);
        $masterId = (int) $master['id'];
        $allDay = (int) $master['all_day'] === 1;
        $existing = $this->db->one(
            'SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL',
            [$masterId, $instanceUtc]
        );
        if ($existing !== null) {
            [$googleFields, $localFields] = self::splitGoogleFields($this->columnPatch($existing, $in, $instanceUtc, forOverride: true));
            if ($googleFields !== []) {
                // A local-only override (attendance, reminders) has no Google
                // id; the occurrence's instance id addresses it at Google.
                $googleId = !empty($existing['google_event_id'])
                    ? (string) $existing['google_event_id']
                    : GoogleWriter::instanceId(self::requireGoogleId($master), $instanceUtc, $allDay, (string) $master['tzid']);
                $resp = $this->google()->patch($calendar, $googleId, GoogleWriter::body(array_merge($existing, $googleFields)));
                $this->google()->apply($calendar, [$resp]);
            }
            $rowId = (int) $existing['id'];
        } else {
            // The occurrence becomes an exception at Google: patch its
            // instance id with the occurrence as it should now be.
            $duration = Recurrence::durationSeconds($master);
            $instStart = Time::fromDb($instanceUtc);
            $override = $this->copyForChild($master);
            $override['rrule'] = null;
            $override['exdates_json'] = null;
            $override['start_utc'] = Time::toDb($instStart);
            $override['end_utc'] = Time::toDb($instStart->add(new \DateInterval('PT' . $duration . 'S')));
            $patch = $this->columnPatch($override, $in, $instanceUtc, forOverride: true);
            [, $localFields] = self::splitGoogleFields($patch);
            $override = array_merge($override, $patch);
            $instanceId = GoogleWriter::instanceId(self::requireGoogleId($master), $instanceUtc, $allDay, (string) $master['tzid']);
            $resp = $this->google()->patch($calendar, $instanceId, GoogleWriter::body($override));
            $this->google()->apply($calendar, [$resp]);
            $created = $this->google()->rowByGoogleId((int) $calendar['id'], (string) ($resp['id'] ?? $instanceId));
            if ($created === null) {
                throw new HttpError('google_write_failed', 'Google accepted the change but the occurrence did not come back', 502);
            }
            $rowId = (int) $created['id'];
            // Same event on one day: it starts with the series' tags and people.
            $this->db->run('INSERT IGNORE INTO event_tags (event_id, tag_id) SELECT ?, tag_id FROM event_tags WHERE event_id = ?', [$rowId, $masterId]);
            $this->db->run('INSERT IGNORE INTO event_people (event_id, person_id) SELECT ?, person_id FROM event_people WHERE event_id = ?', [$rowId, $masterId]);
        }
        $this->db->tx(function () use ($rowId, $userId, $localFields, $in): void {
            if ($localFields !== []) {
                $this->db->update('events', $localFields + ['updated_at' => Time::nowDb()], 'id = ?', [$rowId]);
            }
            if (array_key_exists('tagNames', $in) && is_array($in['tagNames'])) {
                $this->labels->setEventTags($userId, $rowId, $in['tagNames']);
            }
            $this->applyPeoplePatch($userId, $rowId, $in);
        });
        $this->googleJournal($userId, "Updated one occurrence of '" . (string) $master['title'] . "'", $calendar, $masterId);
    }

    private function googlePatchFollowing(int $userId, array $calendar, array $master, array $in): void
    {
        $instanceUtc = $this->requireInstance($in, $master);
        $masterId = (int) $master['id'];
        $instStart = Time::fromDb($instanceUtc);
        $duration = Recurrence::durationSeconds($master);
        $allDay = (int) $master['all_day'] === 1;
        $masterGoogleId = self::requireGoogleId($master);

        // The old series ends before this occurrence; a new one starts here.
        $oldRrule = Recurrence::setUntil((string) $master['rrule'], Recurrence::splitUntil($instStart), $allDay, (string) $master['tzid']);
        $endOld = $this->google()->patch($calendar, $masterGoogleId, [
            'recurrence' => GoogleWriter::recurrenceLines($oldRrule, $this->decodeExdates($master), $allDay, (string) $master['tzid']),
        ]);

        $newMaster = $this->copyForChild($master);
        $newMaster['start_utc'] = Time::toDb($instStart);
        $newMaster['end_utc'] = Time::toDb($instStart->add(new \DateInterval('PT' . $duration . 'S')));
        $newMaster['rrule'] = $this->followingRrule($master, $instanceUtc);
        $newMaster['exdates_json'] = $this->exdatesFrom($master, $instanceUtc);
        $patch = $this->columnPatch($newMaster, $in);
        [, $localFields] = self::splitGoogleFields($patch);
        $newMaster = array_merge($newMaster, $patch);
        if (isset($in['rrule']) && $in['rrule'] !== null && $in['rrule'] !== '') {
            $newMaster['rrule'] = (string) $in['rrule'];
        }
        $created = $this->google()->insert($calendar, $newMaster);
        $this->google()->apply($calendar, [$endOld, $created]);
        $row = $this->google()->rowByGoogleId((int) $calendar['id'], (string) ($created['id'] ?? ''));
        if ($row !== null) {
            $rowId = (int) $row['id'];
            $this->db->tx(function () use ($rowId, $userId, $localFields, $in): void {
                if ($localFields !== []) {
                    $this->db->update('events', $localFields + ['updated_at' => Time::nowDb()], 'id = ?', [$rowId]);
                }
                if (array_key_exists('tagNames', $in) && is_array($in['tagNames'])) {
                    $this->labels->setEventTags($userId, $rowId, $in['tagNames']);
                }
                $this->applyPeoplePatch($userId, $rowId, $in);
            });
        }
        $this->googleJournal($userId, "Changed '" . (string) $master['title'] . "' from " . $instStart->format('M j') . " on", $calendar, $masterId);
    }

    private function googleDeleteAll(int $userId, array $calendar, array $event): void
    {
        $this->google()->delete($calendar, self::requireGoogleId($event));
        $this->google()->apply($calendar, [], [GoogleWriter::tombstone((string) $event['uid'], null)]);
        $this->googleJournal($userId, "Deleted '" . (string) $event['title'] . "'", $calendar, (int) $event['id']);
    }

    private function googleDeleteThis(int $userId, array $calendar, array $master, ?string $instanceStart): void
    {
        $instanceUtc = $this->requireInstance(['instanceStart' => $instanceStart], $master);
        // Refuse before mutating Google: otherwise its delete could succeed
        // while the local cache rejects the resulting exclusion list.
        $this->withExdate($master, $instanceUtc);
        $allDay = (int) $master['all_day'] === 1;
        $override = $this->db->one(
            'SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL',
            [(int) $master['id'], $instanceUtc]
        );
        $googleId = $override !== null && !empty($override['google_event_id'])
            ? (string) $override['google_event_id']
            : GoogleWriter::instanceId(self::requireGoogleId($master), $instanceUtc, $allDay, (string) $master['tzid']);
        $this->google()->delete($calendar, $googleId);
        $this->google()->apply($calendar, [], [GoogleWriter::tombstone((string) $master['uid'], $instanceUtc)]);
        $this->googleJournal($userId, "Deleted one occurrence of '" . (string) $master['title'] . "'", $calendar, (int) $master['id']);
    }

    private function googleDeleteFollowing(int $userId, array $calendar, array $master, ?string $instanceStart): void
    {
        $instanceUtc = $this->requireInstance(['instanceStart' => $instanceStart], $master);
        $allDay = (int) $master['all_day'] === 1;
        $newRrule = Recurrence::setUntil((string) $master['rrule'], Recurrence::splitUntil(Time::fromDb($instanceUtc)), $allDay, (string) $master['tzid']);
        $resp = $this->google()->patch($calendar, self::requireGoogleId($master), [
            'recurrence' => GoogleWriter::recurrenceLines($newRrule, $this->decodeExdates($master), $allDay, (string) $master['tzid']),
        ]);
        $this->google()->apply($calendar, [$resp]);
        $this->googleJournal($userId, "Ended '" . (string) $master['title'] . "' before " . Time::fromDb($instanceUtc)->format('M j'), $calendar, (int) $master['id']);
    }

    private function googleDeleteOverride(int $userId, array $calendar, array $override): void
    {
        if (!empty($override['recurrence_parent_id'])) {
            $parent = $this->db->one('SELECT * FROM events WHERE id = ?', [(int) $override['recurrence_parent_id']]);
            if ($parent !== null) {
                $this->withExdate($parent, (string) $override['recurrence_instance_utc']);
            }
        }
        $this->google()->delete($calendar, self::requireGoogleId($override));
        $this->google()->apply($calendar, [], [GoogleWriter::tombstone((string) $override['uid'], (string) $override['recurrence_instance_utc'])]);
        $this->googleJournal($userId, "Deleted one occurrence of '" . (string) $override['title'] . "'", $calendar, (int) $override['id']);
    }


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
            foreach ($this->db->all('SELECT id, created_at, kind, role, settings_json FROM calendars') as $c) {
                $settings = is_string($c['settings_json'] ?? null)
                    ? json_decode((string) $c['settings_json'], true)
                    : $c['settings_json'];
                $defaults = is_array($settings) && isset($settings['reminderDefaults']) && is_array($settings['reminderDefaults'])
                    ? $settings['reminderDefaults']
                    : null;
                $this->calMeta[(int) $c['id']] = [
                    'createdAt' => Time::fromDb((string) $c['created_at']),
                    'kind' => (string) $c['kind'],
                    'role' => (string) ($c['role'] ?? 'mine'),
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
        // Overrides come regardless of attendance: a hidden override must
        // still replace its instance (and then drop out below), or the
        // master's version of that day would show through the hiding.
        $ovSql .= $this->windowFilters($ovParams, $calendarIds, null, true);
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

        if (!$includeHidden) {
            $expanded = array_values(array_filter($expanded, static fn(array $o): bool => (string) ($o['row']['attendance'] ?? 'none') !== 'hidden'));
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
        $seriesIds = [];
        foreach ($expanded as $occ) {
            $eventIds[(int) $occ['row']['id']] = true;
            $seriesIds[self::seriesId($occ['row'])] = true;
        }
        if ($links === null) {
            $links = $this->labels->forEvents(array_keys($eventIds));
        }
        // Trip membership, batch-loaded like tags (one query, no N+1).
        $links['containers'] = $this->trips->containersFor(array_keys($eventIds));
        $links['dupes'] = $this->duplicates()->linkedFor(array_keys($seriesIds));
        $links['feedback'] = $this->feedbackFor(self::feedIds(array_map(static fn($o) => $o['row'], $expanded)));

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

    /**
     * A calendar on its way to Google (0.9.4, #55) takes no edits until the
     * upload is done: a change made mid-upload could miss Google entirely.
     * It takes seconds for most calendars.
     */
    private function assertNotMoving(int $calendarId): void
    {
        try {
            $moving = $this->db->scalar(
                "SELECT id FROM calendar_moves WHERE calendar_id = ? AND status IN ('queued', 'running') LIMIT 1",
                [$calendarId]
            );
        } catch (\PDOException) {
            return; // no moves table (an install mid-upgrade, the test database)
        }
        if ($moving !== null) {
            throw new HttpError('calendar_moving', 'This calendar is being moved to Google; it takes edits again once the upload finishes.', 409);
        }
    }

    /** @return array occurrence for the created event (first instance) */
    public function create(int $userId, array $in): array
    {
        $calendarId = (int) ($in['calendarId'] ?? 0);
        $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ? AND user_id = ?', [$calendarId, $userId]);
        if ($calendar === null) {
            throw HttpError::badRequest('Unknown calendarId');
        }
        $this->assertNotMoving($calendarId);
        $google = $calendar['kind'] === 'subscribed' && GoogleWriter::writable($calendar) ? $calendar : null;
        if ($calendar['kind'] === 'subscribed' && $google === null) {
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
        // All-day events are dates, stored one way (0.9.16): UTC midnights,
        // tzid UTC, as imports, Google and CalDAV store them.
        if ($allDay) {
            $tzid = 'UTC';
        }
        [$startUtc, $endUtc] = $this->parseTimes($in, $allDay, $tzid, null);

        $rrule = null;
        if (!empty($in['rrule'])) {
            $rrule = Recurrence::validateRrule((string) $in['rrule']);
        }
        $exdates = [];
        if ($rrule !== null && !empty($in['exdates']) && is_array($in['exdates'])) {
            // Count before mapping/filtering: invalid and duplicate input still
            // costs work and must not provide a cardinality bypass.
            try {
                $exdates = array_values(array_filter(
                    Recurrence::validateExdates($in['exdates']),
                    static fn(string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $d) === 1,
                ));
            } catch (\InvalidArgumentException $e) {
                throw HttpError::badRequest($e->getMessage(), 'recurrence_exception_limit');
            }
        }

        $row = [
            'user_id' => $userId,
            'calendar_id' => $calendarId,
            // Callers with an external identity (mail ingest iMIP) pass uid so
            // updates and cancellations can find the event again.
            // Control characters never enter a UID or URL: both are written to
            // feeds and CalDAV unescaped, where a newline is a new property.
            'uid' => Ics::uidOrNew(is_string($in['uid'] ?? null) ? $in['uid'] : ''),
            'title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 500),
            'description' => isset($in['description']) ? Sanitize::description(Ics::clip((string) $in['description'], Limits::get('DESCRIPTION_CHARS'))) : null,
            'location' => isset($in['location']) ? mb_substr((string) $in['location'], 0, 500) : null,
            'location_lat' => self::coordOrNull($in, 'locationLat'),
            'location_lng' => self::coordOrNull($in, 'locationLng'),
            'url' => isset($in['url']) ? self::cleanUrl((string) $in['url']) : null,
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
            'all_day' => $allDay ? 1 : 0,
            'tzid' => $tzid,
            'rrule' => $rrule,
            // Only a copy of a series carries these in; the editor never sends them.
            'exdates_json' => $exdates === [] ? null : json_encode($exdates),
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
        // A weekly series starts on a day it actually repeats on (0.9.15).
        $row = array_merge($row, self::alignedStart($row));

        if ($google !== null) {
            return $this->serializeSingle($this->googleCreate($userId, $google, $row, $in));
        }

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

    /**
     * Copy an event, or one occurrence of a series, onto another calendar as
     * a new event: same content, recurrence (whole series, with its skipped
     * dates), tags, people, reminders and coordinates; a fresh identity, and
     * no Google event link (that pointed at the original). Goes through
     * create(), so a target on a writable Google calendar is written through
     * and a target that cannot take events is refused the same way. This is
     * also how something crosses the Google boundary: copy, then delete the
     * original if a move was meant.
     */
    public function copyTo(int $userId, int $id, int $targetCalendarId, string $scope = 'all', ?string $instanceStart = null): array
    {
        $event = $this->get($userId, $id);
        if ($targetCalendarId === (int) $event['calendar_id']) {
            throw HttpError::badRequest('That is the calendar it is already on');
        }
        $source = $event;
        $series = !empty($event['rrule']) && empty($event['recurrence_parent_id']);
        if ($series && $scope === 'this') {
            // One occurrence becomes a plain event: the override for that day
            // if there is one, else the master's content at the instance time.
            $instanceUtc = $this->requireInstance(['instanceStart' => $instanceStart], $event);
            $override = $this->db->one(
                'SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL',
                [(int) $event['id'], $instanceUtc]
            );
            if ($override !== null) {
                $source = $override;
            } else {
                $duration = Recurrence::durationSeconds($event);
                $source['start_utc'] = $instanceUtc;
                $source['end_utc'] = Time::toDb(Time::fromDb($instanceUtc)->add(new \DateInterval('PT' . $duration . 'S')));
            }
            $series = false;
        }
        $links = $this->labels->forEvents([(int) $source['id']]);
        $personIds = array_map(static fn(array $r): int => (int) $r['person_id'], $this->db->all('SELECT person_id FROM event_people WHERE event_id = ?', [(int) $source['id']]));
        $tzid = (string) $source['tzid'];
        $in = [
            'calendarId' => $targetCalendarId,
            'title' => (string) $source['title'],
            'description' => $source['description'],
            'location' => $source['location'],
            'locationLat' => $source['location_lat'],
            'locationLng' => $source['location_lng'],
            'start' => Time::dbToIso((string) $source['start_utc'], $tzid),
            'end' => Time::dbToIso((string) $source['end_utc'], $tzid),
            'allDay' => (int) $source['all_day'] === 1,
            'tzid' => $tzid,
            'status' => (string) $source['status'],
            'tagNames' => $links['tags'][(int) $source['id']] ?? [],
            'personIds' => $personIds,
        ];
        $reminders = Reminders::decode($source['reminders_json'] ?? null);
        if ($reminders !== null) {
            $in['reminders'] = $reminders;
        }
        if ($series) {
            $in['rrule'] = (string) $event['rrule'];
            $in['exdates'] = $this->decodeExdates($event);
        }
        if ($event['source'] === 'local' && !empty($source['url'])) {
            $in['url'] = (string) $source['url'];
        }
        $copy = $this->create($userId, $in);
        // A copy made on purpose is not a duplicate of its source (#9).
        Duplicates::markDistinct($this->db, $userId, (int) $event['id'], (int) $copy['eventId']);
        return $copy;
    }

    /**
     * Does this patch move the event to another calendar? Editors send the
     * whole form, calendar included, so a calendarId equal to the event's own
     * is not a move and must not trip the move refusals (a time edit on a
     * Google calendar once did).
     */
    public static function isCalendarChange(array $event, array $in): bool
    {
        return isset($in['calendarId']) && (int) $in['calendarId'] !== (int) $event['calendar_id'];
    }

    public function patch(int $userId, int $id, array $in): void
    {
        $event = $this->get($userId, $id);
        if (isset($in['calendarId']) && !self::isCalendarChange($event, $in)) {
            unset($in['calendarId']);
        }
        // Reminders are user-local metadata (like tags), so feed events accept
        // them even though their feed-derived content is read-only.
        $editKeys = array_diff(array_keys($in), ['scope', 'instanceStart', 'tagNames', 'reminders']);
        if ($editKeys !== []) {
            $this->assertNotMoving((int) $event['calendar_id']);
            if (isset($in['calendarId'])) {
                $this->assertNotMoving((int) $in['calendarId']);
            }
        }
        $google = $event['source'] === 'feed' ? $this->googleCalendarFor((int) $event['calendar_id']) : null;
        if ($event['source'] === 'feed' && $editKeys !== [] && $google === null) {
            throw HttpError::forbidden('feed_readonly', 'Feed events are read-only except attendance, tags and reminders');
        }
        if ($google !== null && self::isCalendarChange($event, $in)) {
            throw HttpError::forbidden('google_move', 'Events on a Google calendar stay there; create it on the other calendar instead');
        }
        if ($google !== null && !empty($in['moveMembers'])) {
            throw HttpError::forbidden('google_trip_move', 'A trip on a Google calendar moves one event at a time');
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
                throw HttpError::forbidden(
                    GoogleWriter::writable($calendar) ? 'google_move' : 'feed_readonly',
                    GoogleWriter::writable($calendar) ? 'Moving an event onto a Google calendar is not supported yet; create it there instead' : 'Cannot move events onto a subscribed calendar'
                );
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

        $targetCalendar = isset($in['calendarId']) ? (int) $in['calendarId'] : null;
        if ($targetCalendar !== null && $targetCalendar !== (int) $event['calendar_id'] && $google === null) {
            if (!empty($event['recurrence_parent_id'])) {
                $this->detachToCalendar($userId, $event, null, $in);
                return;
            }
            if ($isRecurringMaster && $scope === 'this') {
                $this->detachToCalendar($userId, $event, $this->requireInstance($in, $event), $in);
                return;
            }
            // 'following': patchFollowing splits and the new series takes the
            // calendar; 'all': patchAll moves the series and its exceptions.
        }

        if ($google !== null) {
            match ($scope) {
                'this' => $this->googlePatchThis($userId, $google, $event, $in),
                'following' => $this->googlePatchFollowing($userId, $google, $event, $in),
                'all' => $this->googlePatchAll($userId, $google, $event, $in),
            };
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
        // The move as the trip's own calendar sees it: whole days, plus any
        // change in its time of day. Members move by the same days on their
        // own calendars, keeping their wall-clock times; shifting them by the
        // elapsed seconds moved them an hour (and all-day ones a day) when
        // the move crossed a DST change (audit, 0.9.14).
        $deltaDays = 0;
        $deltaSec = 0;
        if (isset($fields['start_utc'])) {
            $ctz = Time::zone((string) ($fields['tzid'] ?? $event['tzid']));
            $was = Time::fromDb((string) $event['start_utc'])->setTimezone($ctz);
            $now = Time::fromDb((string) $fields['start_utc'])->setTimezone($ctz);
            $deltaDays = (int) (new \DateTimeImmutable($was->format('Y-m-d'), Time::utc()))->diff(new \DateTimeImmutable($now->format('Y-m-d'), Time::utc()))->format('%r%a');
            $deltaSec = $now->getTimestamp() - $was->modify(($deltaDays >= 0 ? '+' : '') . $deltaDays . ' days')->getTimestamp();
        }
        $shift = static function (string $utc, string $tzid, bool $allDay) use ($deltaDays, $deltaSec): string {
            $t = Time::fromDb($utc)->setTimezone(Time::zone($tzid))->modify(($deltaDays >= 0 ? '+' : '') . $deltaDays . ' days');
            return Time::toDb($deltaSec !== 0 && !$allDay ? $t->modify(($deltaSec >= 0 ? '+' : '') . $deltaSec . ' seconds') : $t);
        };

        $members = $this->db->all(
            'SELECT e.* FROM events e JOIN event_links l ON l.event_id = e.id
             WHERE l.container_id = ? AND e.user_id = ? AND e.deleted_at IS NULL',
            [$id, $userId]
        );

        $beforeRows = array_merge([$event], $members);
        $this->db->tx(function () use ($id, $fields, $members, $deltaDays, $deltaSec, $shift): void {
            if ($fields !== []) {
                $this->db->update('events', $fields + ['updated_at' => Time::nowDb()], 'id = ?', [$id]);
            }
            if ($deltaDays !== 0 || $deltaSec !== 0) {
                foreach ($members as $m) {
                    $this->db->update('events', [
                        'start_utc' => $shift((string) $m['start_utc'], (string) $m['tzid'], (int) $m['all_day'] === 1),
                        'end_utc' => $shift((string) $m['end_utc'], (string) $m['tzid'], (int) $m['all_day'] === 1),
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

    /**
     * A whole-series edit made from one occurrence (the editor sends that
     * occurrence's start and end with instanceStart) changes the series by
     * what changed for that occurrence: the days it moved, its new time of
     * day, zone and length. Applied as given, the opened occurrence became
     * the series' first, and every earlier one vanished (0.9.15). API calls
     * with no instanceStart still set the series' own start, as before.
     */
    private function rebaseSeriesEdit(array $master, array $in): array
    {
        if (empty($master['rrule']) || !empty($master['recurrence_parent_id']) || !isset($in['start']) || empty($in['instanceStart'])) {
            return $in;
        }
        $instUtc = Time::fromDb($this->requireInstance($in, $master));
        $occ = ['start_utc' => Time::toDb($instUtc), 'end_utc' => Time::toDb($instUtc->add(new \DateInterval('PT' . Recurrence::durationSeconds($master) . 'S')))] + $master;
        $occFields = $this->columnPatch($occ, array_intersect_key($in, array_flip(['start', 'end', 'allDay', 'tzid'])));
        $same = static fn(string $k): bool => !isset($occFields[$k]) || (string) $occFields[$k] === (string) $occ[$k];
        if ($same('start_utc') && $same('end_utc') && $same('all_day') && $same('tzid')) {
            unset($in['start'], $in['end']);
            return $in;
        }
        $oldTz = Time::zone((string) $master['tzid']);
        $newTz = Time::zone((string) ($occFields['tzid'] ?? $master['tzid']));
        $allDay = (int) ($occFields['all_day'] ?? $master['all_day']) === 1;
        $occWas = $instUtc->setTimezone($oldTz);
        $occNow = Time::fromDb((string) $occFields['start_utc'])->setTimezone($newTz);
        $days = self::dayDelta($occWas, $occNow);
        $length = Time::fromDb((string) ($occFields['end_utc'] ?? $occ['end_utc']))->getTimestamp() - Time::fromDb((string) $occFields['start_utc'])->getTimestamp();
        $date = (new \DateTimeImmutable(Time::fromDb((string) $master['start_utc'])->setTimezone($oldTz)->format('Y-m-d'), Time::utc()))
            ->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
        // "Every Monday" moved to a Tuesday becomes "every Tuesday", unless the
        // same edit chose the days itself.
        $rule = (string) ($in['rrule'] ?? $master['rrule']);
        if ($days % 7 !== 0 && Recurrence::pattern($rule) === Recurrence::pattern((string) $master['rrule'])) {
            $shifted = Recurrence::shiftByday($rule, $days);
            if ($shifted !== $rule) {
                $in['rrule'] = $shifted;
            }
        }
        if ($allDay) {
            $in['start'] = $date;
            $in['end'] = (new \DateTimeImmutable($date, Time::utc()))->modify('+' . max(1, (int) round($length / 86400)) . ' days')->format('Y-m-d');
        } else {
            $start = new \DateTimeImmutable($date . ' ' . $occNow->format('H:i:s'), $newTz);
            $in['start'] = Time::iso($start);
            $in['end'] = Time::iso($start->modify('+' . max(60, $length) . ' seconds'));
        }
        return $in;
    }

    /**
     * A weekly series starting on a day its BYDAY doesn't list moves to the
     * first day it does, keeping its time (start and end columns, or []).
     */
    private static function alignedStart(array $row): array
    {
        if (empty($row['rrule'])) {
            return [];
        }
        $tz = Time::zone((string) $row['tzid']);
        $start = Time::fromDb((string) $row['start_utc'])->setTimezone($tz);
        $n = Recurrence::daysToFirstByday((string) $row['rrule'], $start);
        if ($n === 0) {
            return [];
        }
        return [
            'start_utc' => Time::toDb($start->modify('+' . $n . ' days')),
            'end_utc' => Time::toDb(Time::fromDb((string) $row['end_utc'])->setTimezone($tz)->modify('+' . $n . ' days')),
        ];
    }

    /** Whole calendar days from one local date to another. */
    private static function dayDelta(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) (new \DateTimeImmutable($from->format('Y-m-d'), Time::utc()))
            ->diff(new \DateTimeImmutable($to->format('Y-m-d'), Time::utc()))->format('%r%a');
    }

    /**
     * When a series' start, zone or all-day flag changes, its skipped dates,
     * edited occurrences and a timed UNTIL move with it: each keeps its place
     * in the series (the same number of days on, at the series' new time of
     * day, in its new zone). Left behind, a deleted day came back and an
     * edited one showed twice (0.9.15, as Thunderbird and Nextcloud do it).
     * Null when nothing moved.
     *
     * @return null|callable(string): string  old instance key => new one
     */
    private static function seriesKeyShift(array $before, array $after): ?callable
    {
        $oldTz = Time::zone((string) $before['tzid']);
        $newTz = Time::zone((string) $after['tzid']);
        $oldAllDay = (int) $before['all_day'] === 1;
        $newAllDay = (int) $after['all_day'] === 1;
        $was = Time::fromDb((string) $before['start_utc'])->setTimezone($oldTz);
        $now = Time::fromDb((string) $after['start_utc'])->setTimezone($newTz);
        if ($was->format('Y-m-d H:i:s') === $now->format('Y-m-d H:i:s') && $oldTz->getName() === $newTz->getName() && $oldAllDay === $newAllDay) {
            return null;
        }
        $days = self::dayDelta($was, $now);
        $time = $newAllDay ? '00:00:00' : $now->format('H:i:s');
        return static function (string $key) use ($oldTz, $newTz, $days, $time): string {
            $date = (new \DateTimeImmutable(Time::fromDb($key)->setTimezone($oldTz)->format('Y-m-d'), Time::utc()))
                ->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
            return Time::toDb((new \DateTimeImmutable($date . ' ' . $time, $newTz))->setTimezone(Time::utc()));
        };
    }

    private function patchAll(int $userId, array $event, array $in): void
    {
        $id = (int) $event['id'];
        $beforeLinks = $this->labels->eventLinkRows($id);
        $in = $this->rebaseSeriesEdit($event, $in);
        $fields = $this->columnPatch($event, $in);
        $isSeries = !empty($event['rrule']) && empty($event['recurrence_parent_id']);
        $shift = null;
        if ($isSeries && !empty(($fields + $event)['rrule'])) {
            $after = array_merge($event, $fields);
            $tz = Time::zone((string) $event['tzid']);
            $moved = self::dayDelta(Time::fromDb((string) $event['start_utc'])->setTimezone($tz), Time::fromDb((string) $after['start_utc'])->setTimezone(Time::zone((string) $after['tzid'])));
            if (Recurrence::pattern((string) $after['rrule']) === Recurrence::pattern(Recurrence::shiftByday((string) $event['rrule'], $moved))) {
                // Same days, moved together: skipped and edited days follow.
                $shift = self::seriesKeyShift($event, $after);
            } else {
                // The days themselves changed: the series starts on its first
                // listed day, and old keys no longer name places in it.
                $fields = array_merge($fields, self::alignedStart($after));
            }
        }
        if ($shift !== null) {
            $exdates = array_map($shift, $this->decodeExdates($event));
            $fields['exdates_json'] = $exdates === [] ? null : json_encode($exdates);
            // A timed UNTIL moves with the series; a rule sent with the edit
            // (the editor's own end date) is taken as given.
            $rrule = (string) ($fields['rrule'] ?? $event['rrule']);
            if (!array_key_exists('rrule', $in) && preg_match('/UNTIL=(\d{8}T\d{6}Z)/', $rrule, $m) === 1) {
                $until = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $m[1], Time::utc());
                if ($until !== false) {
                    // Its day moved like an occurrence, at the series' new
                    // time: still inclusive of the last occurrence.
                    $moved = Time::fromDb($shift(Time::toDb($until)));
                    $fields['rrule'] = str_replace('UNTIL=' . $m[1], 'UNTIL=' . $moved->format('Ymd\THis\Z'), $rrule);
                }
            }
        }
        // A calendar change moves the exceptions too: an override parked on
        // another calendar than its master is a row nothing can reach.
        $movesCalendar = isset($fields['calendar_id']) && !empty($event['rrule']);
        $overridesBefore = ($movesCalendar || $shift !== null) ? $this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$id]) : [];

        $this->db->tx(function () use ($id, $userId, $fields, $in, $movesCalendar, $shift, $overridesBefore, $event): void {
            if ($fields !== []) {
                $fields['updated_at'] = Time::nowDb();
                $this->db->update('events', $fields, 'id = ?', [$id]);
                if ($movesCalendar) {
                    $this->db->run('UPDATE events SET calendar_id = ? WHERE recurrence_parent_id = ?', [$fields['calendar_id'], $id]);
                }
            }
            if ($shift !== null) {
                $after = array_merge($event, $fields);
                foreach ($overridesBefore as $ov) {
                    $key = $shift((string) $ov['recurrence_instance_utc']);
                    $set = ['recurrence_instance_utc' => $key, 'updated_at' => Time::nowDb()];
                    // An occurrence edited in other ways but not moved follows
                    // the series to its new day, time and zone.
                    if ((string) $ov['start_utc'] === (string) $ov['recurrence_instance_utc']) {
                        $len = Time::fromDb((string) $ov['end_utc'])->getTimestamp() - Time::fromDb((string) $ov['start_utc'])->getTimestamp();
                        if ((int) $after['all_day'] !== (int) $ov['all_day']) {
                            $len = Recurrence::durationSeconds($after);
                        }
                        $set += [
                            'start_utc' => $key,
                            'end_utc' => Time::toDb(Time::fromDb($key)->modify('+' . max(60, $len) . ' seconds')),
                            'all_day' => (int) $after['all_day'],
                            'tzid' => (string) $after['tzid'],
                        ];
                    }
                    $this->db->update('events', $set, 'id = ?', [(int) $ov['id']]);
                }
            }
            if (array_key_exists('tagNames', $in) && is_array($in['tagNames'])) {
                $this->labels->setEventTags($userId, $id, $in['tagNames']);
            }
            $this->applyPeoplePatch($userId, $id, $in);
        });

        $after = $this->get($userId, $id);
        $overridesAfter = [];
        foreach ($overridesBefore as $ov) {
            $overridesAfter[] = $this->db->one('SELECT * FROM events WHERE id = ?', [(int) $ov['id']]);
        }
        $this->undo->record(
            $userId,
            'event',
            $id,
            'update',
            ['events' => array_merge([$event], $overridesBefore)] + $beforeLinks,
            ['events' => array_merge([$after], array_values(array_filter($overridesAfter)))] + $this->labels->eventLinkRows($id)
        );
        ChangeLog::recordUpdate($this->db, $event, $after);
    }

    /**
     * The override row for one occurrence of a series, creating it from the
     * master (same content, same tags and people) when there is none. This is
     * how a per-occurrence attendance or reminder gets a row to live on
     * without changing anything else about the day.
     */
    private function ensureOverride(int $userId, array $master, string $instanceUtc): array
    {
        $masterId = (int) $master['id'];
        $existing = $this->db->one(
            'SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL',
            [$masterId, $instanceUtc]
        );
        if ($existing !== null) {
            return $existing;
        }
        $duration = Recurrence::durationSeconds($master);
        $instStart = Time::fromDb($instanceUtc);
        $override = $this->copyForChild($master);
        $override['recurrence_parent_id'] = $masterId;
        $override['recurrence_instance_utc'] = $instanceUtc;
        $override['rrule'] = null;
        $override['exdates_json'] = null;
        $override['google_event_id'] = null;
        $override['created_via'] = ActivityContext::get();
        $override['start_utc'] = Time::toDb($instStart);
        $override['end_utc'] = Time::toDb($instStart->add(new \DateInterval('PT' . $duration . 'S')));
        $newId = $this->db->tx(function () use ($override, $masterId): int {
            $id = $this->db->insert('events', $override);
            $this->db->run('INSERT IGNORE INTO event_tags (event_id, tag_id) SELECT ?, tag_id FROM event_tags WHERE event_id = ?', [$id, $masterId]);
            $this->db->run('INSERT IGNORE INTO event_people (event_id, person_id) SELECT ?, person_id FROM event_people WHERE event_id = ?', [$id, $masterId]);
            return $id;
        });
        return $this->get($userId, $newId);
    }

    /**
     * One occurrence leaves its series for another calendar: it becomes a
     * standalone event there (new identity, same content, tags and people,
     * plus whatever else the same edit changed) and the series skips that
     * day. Used for an override row moved on its own, and for a master with
     * scope 'this'. 'following' is a split (patchFollowing, the new series
     * takes the calendar) and 'all' moves the series with its exceptions.
     */
    private function detachToCalendar(int $userId, array $event, ?string $instanceUtc, array $in): void
    {
        if (!empty($event['recurrence_parent_id'])) {
            $master = $this->db->one('SELECT * FROM events WHERE id = ?', [(int) $event['recurrence_parent_id']]);
            $instanceUtc = (string) $event['recurrence_instance_utc'];
            $source = $event;
        } else {
            $master = $event;
            $source = $this->db->one(
                'SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL',
                [(int) $master['id'], (string) $instanceUtc]
            );
            if ($source === null) {
                $duration = Recurrence::durationSeconds($master);
                $source = $master;
                $source['start_utc'] = (string) $instanceUtc;
                $source['end_utc'] = Time::toDb(Time::fromDb((string) $instanceUtc)->add(new \DateInterval('PT' . $duration . 'S')));
            }
        }
        $existingOverrideId = !empty($source['recurrence_parent_id']) ? (int) $source['id'] : null;
        $row = $this->copyForChild($source);
        $row['uid'] = Ids::ulid();
        $row['recurrence_parent_id'] = null;
        $row['recurrence_instance_utc'] = null;
        $row['rrule'] = null;
        $row['exdates_json'] = null;
        $row['google_event_id'] = null;
        $row['source'] = 'local';
        $row['created_via'] = ActivityContext::get();
        $row = array_merge($row, $this->columnPatch($row, $in, null, forOverride: true));
        $copyFromId = $existingOverrideId ?? ($master !== null ? (int) $master['id'] : null);

        $beforeRows = array_values(array_filter([$master, $existingOverrideId !== null ? $source : null]));
        $newId = $this->db->tx(function () use ($row, $copyFromId, $existingOverrideId, $master, $instanceUtc, $userId, $in): int {
            $id = $this->db->insert('events', $row);
            if ($copyFromId !== null) {
                $this->db->run('INSERT IGNORE INTO event_tags (event_id, tag_id) SELECT ?, tag_id FROM event_tags WHERE event_id = ?', [$id, $copyFromId]);
                $this->db->run('INSERT IGNORE INTO event_people (event_id, person_id) SELECT ?, person_id FROM event_people WHERE event_id = ?', [$id, $copyFromId]);
            }
            if (array_key_exists('tagNames', $in) && is_array($in['tagNames'])) {
                $this->labels->setEventTags($userId, $id, $in['tagNames']);
            }
            $this->applyPeoplePatch($userId, $id, $in);
            if ($master !== null) {
                $exdates = $this->withExdate($master, (string) $instanceUtc);
                $this->db->update('events', ['exdates_json' => json_encode($exdates), 'updated_at' => Time::nowDb()], 'id = ?', [(int) $master['id']]);
            }
            if ($existingOverrideId !== null) {
                $this->db->run('DELETE FROM events WHERE id = ?', [$existingOverrideId]);
            }
            return $id;
        });
        $created = $this->get($userId, $newId);
        $afterRows = [$created];
        if ($master !== null) {
            $afterRows[] = $this->db->one('SELECT * FROM events WHERE id = ?', [(int) $master['id']]);
            ChangeLog::record($this->db, (int) $master['calendar_id'], (string) $master['uid'], ChangeLog::OP_MODIFY);
        }
        $this->undo->record(
            $userId,
            'event',
            $newId,
            'update',
            ['events' => $beforeRows],
            ['events' => $afterRows] + $this->labels->eventLinkRows($newId),
            "Moved one occurrence of '" . (string) $source['title'] . "' to another calendar"
        );
        ChangeLog::record($this->db, (int) $created['calendar_id'], (string) $created['uid'], ChangeLog::OP_ADD);
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

    private function patchFollowing(int $userId, array $master, array $in): int
    {
        $instanceUtc = $this->requireInstance($in, $master);
        $masterId = (int) $master['id'];
        $instStart = Time::fromDb($instanceUtc);
        $duration = Recurrence::durationSeconds($master);
        $allDay = (int) $master['all_day'] === 1;

        $oldRrule = Recurrence::setUntil((string) $master['rrule'], Recurrence::splitUntil($instStart), $allDay, (string) $master['tzid']);

        $newMaster = $this->copyForChild($master);
        $newMaster['uid'] = Ids::ulid();
        $newMaster['created_via'] = ActivityContext::get(); // the splitter, not the original creator
        $newMaster['start_utc'] = Time::toDb($instStart);
        $newMaster['end_utc'] = Time::toDb($instStart->add(new \DateInterval('PT' . $duration . 'S')));
        $newMaster['rrule'] = $this->followingRrule($master, $instanceUtc);
        $newMaster['exdates_json'] = $this->exdatesFrom($master, $instanceUtc);
        $newMaster = array_merge($newMaster, $this->columnPatch($newMaster, $in));
        if (isset($in['rrule']) && $in['rrule'] !== null && $in['rrule'] !== '') {
            $newMaster['rrule'] = (string) $in['rrule'];
        }
        // New days chosen for the rest of the series: it starts on the first.
        $newMaster = array_merge($newMaster, self::alignedStart($newMaster));

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
        return $newId;
    }

    public function deleteEvent(int $userId, int $id, ?string $scope, ?string $instanceStart): void
    {
        $event = $this->get($userId, $id);
        $this->assertNotMoving((int) $event['calendar_id']);
        $google = $event['source'] === 'feed' ? $this->googleCalendarFor((int) $event['calendar_id']) : null;
        if ($event['source'] === 'feed' && $google === null) {
            throw HttpError::forbidden('feed_readonly', 'Feed events cannot be deleted; hide them instead');
        }
        if (($this->calendarMeta((int) $event['calendar_id'])['kind'] ?? '') === 'plugin'
            && !str_starts_with(ActivityContext::get(), 'plugin:')) {
            throw HttpError::forbidden('plugin_readonly', 'Plugin events are managed by their plugin; hide the calendar instead');
        }

        // Deleting an override row directly = deleting that one occurrence.
        if (!empty($event['recurrence_parent_id'])) {
            if ($google !== null) {
                $this->googleDeleteOverride($userId, $google, $event);
                return;
            }
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

        if ($google !== null) {
            match ($scope) {
                'this' => $this->googleDeleteThis($userId, $google, $event, $instanceStart),
                'following' => $this->googleDeleteFollowing($userId, $google, $event, $instanceStart),
                'all' => $this->googleDeleteAll($userId, $google, $event),
            };
            return;
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
        $exdates = $this->withExdate($master, $instanceUtc);
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
        $newRrule = Recurrence::setUntil((string) $master['rrule'], Recurrence::splitUntil($instStart), $allDay, (string) $master['tzid']);
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
                $exdates = $this->withExdate($parent, $instanceUtc);
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

    /**
     * Attendance is per row, so on a series it needs a scope like any other
     * change: 'this' gives the occurrence a row of its own (an override that
     * differs only in attendance, so the feed or Google series keeps syncing
     * around it; Feeds::sync leaves a person's own overrides alone),
     * 'following' splits the series there, 'all' (or no scope) sets the
     * master and every override.
     */
    public function setAttendance(int $userId, int $id, string $attendance, ?string $scope = null, ?string $instanceStart = null): void
    {
        $attendance = self::ATTENDANCE_ALIASES[$attendance] ?? $attendance;
        if (!in_array($attendance, self::ATTENDANCE, true)) {
            throw HttpError::badRequest('attendance must be none|interested|going|hidden (or planned|maybe|available)');
        }
        $event = $this->get($userId, $id);
        $isMaster = !empty($event['rrule']) && empty($event['recurrence_parent_id']);
        $targetId = $id;
        if ($isMaster && $scope === 'this') {
            $targetId = (int) $this->ensureOverride($userId, $event, $this->requireInstance(['instanceStart' => $instanceStart], $event))['id'];
        } elseif ($isMaster && $scope === 'following') {
            $targetId = $this->patchFollowing($userId, $event, ['instanceStart' => $instanceStart]);
        }
        $target = $this->get($userId, $targetId);
        $beforeRows = [$target];
        $this->db->tx(function () use ($targetId, $attendance, $isMaster, $scope, $id, &$beforeRows): void {
            $this->db->update('events', ['attendance' => $attendance], 'id = ?', [$targetId]);
            if ($isMaster && ($scope === null || $scope === 'all')) {
                $overrides = $this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$id]);
                $beforeRows = array_merge($beforeRows, $overrides);
                $this->db->run('UPDATE events SET attendance = ? WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$attendance, $id]);
            }
        });
        $afterRows = [];
        foreach ($beforeRows as $r) {
            $afterRows[] = $this->db->one('SELECT * FROM events WHERE id = ?', [(int) $r['id']]);
        }
        $this->undo->record($userId, 'event', $targetId, 'update', ['events' => $beforeRows], ['events' => array_values(array_filter($afterRows))]);
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

    /**
     * Explicit thumbs feedback ('up'|'down'); pure training signal, no undo.
     * The newest signal is the event's current one (ranking reads it that
     * way), so repeating it adds nothing and is ignored (#107); the other
     * thumb switches it. Returns the event's feedback after the call.
     */
    public function recordFeedback(int $userId, int $id, string $signal): string
    {
        if (!in_array($signal, ['up', 'down'], true)) {
            throw HttpError::badRequest('signal must be up|down');
        }
        $this->get($userId, $id); // ownership + existence
        if (($this->feedbackFor([$id])[$id] ?? null) !== $signal) {
            $this->db->insert('feedback_signals', ['user_id' => $userId, 'event_id' => $id, 'kind' => $signal]);
        }
        return $signal;
    }

    /**
     * The current thumbs state of each event: its newest up/down signal, from
     * the thumbs or from triage (going or interested counts as up, hiding as
     * down). Only feed events take feedback, so only they are looked up.
     *
     * @param list<int> $ids @return array<int, string> event id => up|down
     */
    private function feedbackFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$in, $params] = Db::in($ids);
        $out = [];
        foreach ($this->db->all("SELECT event_id, kind FROM feedback_signals WHERE event_id IN $in AND kind IN ('up', 'down') ORDER BY id", $params) as $r) {
            $out[(int) $r['event_id']] = (string) $r['kind'];
        }
        return $out;
    }

    /** @param list<array> $rows @return list<int> ids of the feed events among them */
    private static function feedIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            if ((string) ($r['source'] ?? '') === 'feed') {
                $ids[(int) $r['id']] = true;
            }
        }
        return array_keys($ids);
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
        $links['dupes'] = $this->duplicates()->linkedFor([self::seriesId($row)]);
        $links['feedback'] = $this->feedbackFor(self::feedIds([$row]));
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
        $links['dupes'] = $this->duplicates()->linkedFor(array_values(array_unique(array_map(self::seriesId(...), $rows))));
        $links['feedback'] = $this->feedbackFor(self::feedIds($rows));
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
            unset($out['hasReminders'], $out['hasDescription']); // the record carries the real things
            return $out;
        }
        // The list shape: what the grid draws. Everything the detail view,
        // the popover and the editor need beyond this rides the single-event
        // fetch on open (#15). Nulls and empty lists are omitted; a missing
        // key reads as null / [] on every client surface.
        foreach (self::DETAIL_ONLY as $k) {
            unset($out[$k]);
        }
        // A timed context moment (sunset, a tide) keeps its own clock on the
        // grid, so it alone carries its zone (docs/relationships.md).
        if (($out['relationship'] ?? '') === 'context' && empty($out['allDay'])) {
            $out['tzid'] = (string) $row['tzid'];
        }
        return array_filter($out, static fn($v): bool => $v !== null && $v !== []);
    }

    /**
     * Fields only the single-event record carries. Measured per occurrence
     * before removal: uid 76, reminders 43, createdAt 39, updatedAt 39,
     * rrule 35, tzid 28, reminderSource 26, description 21 — a third of the
     * row, none of it read by anything that draws a grid.
     */
    private const DETAIL_ONLY = ['uid', 'createdAt', 'updatedAt', 'reminders', 'reminderSource', 'rrule', 'description', 'tzid', 'geocodedAt'];

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
        // Effective reminders are computed for both shapes: the list needs
        // only the bit (hasReminders, so the popover can reserve the row
        // before the record arrives) and the inputs are cached per calendar
        // and per user, so the per-row cost is the pure merge.
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
        $rrule = null;
        if ($full) {
            // Overrides carry no rrule of their own; surface the parent's so
            // clients can always describe the cadence, not just "repeats".
            // A lookup per override row, which is why it is record-only.
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
            // When the sweep last tried to place this address. Set with no
            // coordinates means "tried and could not", which the detail view
            // says out loud instead of showing an address with no map.
            'geocodedAt' => !empty($row['geocoded_at']) ? Time::iso(Time::fromDb((string) $row['geocoded_at'])) : null,
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
            'relationship' => self::relationship((string) $row['attendance'], (string) $row['status'], (string) ($calMeta['role'] ?? 'mine')),
            'icon' => isset($row['icon']) && $row['icon'] !== '' ? (string) $row['icon'] : null,
            'score' => isset($row['score']) && $row['score'] !== null ? (float) $row['score'] : null,
            // Thumbs (feed events): 'up' or 'down' once given, so the buttons show it (#107).
            'feedback' => $links['feedback'][$id] ?? null,
            'reminders' => $reminders,
            // List-shape bits, sent only when true (null is omitted): they let
            // the popover draw a correctly sized skeleton for the rows the
            // record will fill in, so a late description expands a reserved
            // space instead of pushing everything below it. ~18 bytes per
            // occurrence on average, the one payload cost of the polish pass.
            'hasReminders' => $reminders !== [] ? true : null,
            'hasDescription' => ($row['description'] !== null && $row['description'] !== '') ? true : null,
            'reminderSource' => $reminderSource,
            'tags' => $links['tags'][$id] ?? [],
            'people' => $links['people'][$id] ?? [],
            'isContainer' => (int) ($row['is_container'] ?? 0) === 1,
            // Invitations (from mail or a Google guest list) and bookings read
            // from mail: what they are, and whether an answer can go (Rsvp).
            'invite' => self::invite($row),
            'containers' => $links['containers'][$id] ?? [],
            // The same event on other calendars (#9): the client shows one.
            // Linked by series, so a moved occurrence carries its series' copies.
            'dupes' => $links['dupes'][self::seriesId($row)] ?? [],
            'styleJson' => $style ?: null,
            'createdAt' => Time::iso($createdAt),
            'updatedAt' => Time::iso(Time::fromDb((string) $row['updated_at'])),
            'isNew' => $this->isNew($row, $createdAt),
        ];
    }

    private static function invite(array $row): ?array
    {
        if (!isset($row['invite_json']) || $row['invite_json'] === null) {
            return null;
        }
        $invite = is_array($row['invite_json']) ? $row['invite_json'] : json_decode((string) $row['invite_json'], true);
        return is_array($invite) ? Rsvp::describe($invite, config()) : null;
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
        // An all-day event stored as UTC dates (imports, Google, CalDAV) that
        // becomes timed with no zone given takes the owner's Home zone: kept
        // at UTC, a repeating 9:00 moved an hour at every DST change (audit, 0.9.14).
        if (!isset($in['tzid']) && !$allDay && (int) $current['all_day'] === 1 && Time::normalizeTzid($tzid) === 'UTC') {
            $home = $this->userTzid((int) $current['user_id']);
            if ($home !== 'UTC') {
                $tzid = $home;
                $fields['tzid'] = $home;
            }
        }

        // All-day events are dates, stored one way (0.9.16): UTC midnights,
        // tzid UTC. The zone the stored boundaries are dated in is kept for
        // the conversion: the event's own zone for a timed event becoming
        // all-day, and for an all-day event stored the old way (local
        // midnights), which is converted the first time it's edited.
        $floorTz = Time::zone($tzid);
        $legacyDay = false;
        if ($allDay) {
            $legacyDay = (int) $current['all_day'] === 1 && Time::normalizeTzid((string) $current['tzid']) !== 'UTC';
            if (Time::normalizeTzid((string) $current['tzid']) !== 'UTC' || isset($in['tzid'])) {
                $fields['tzid'] = 'UTC';
            }
            $tzid = 'UTC';
        }

        if (isset($in['start']) || isset($in['end']) || $legacyDay) {
            $curStart = Time::fromDb((string) $current['start_utc']);
            $curEnd = Time::fromDb((string) $current['end_utc']);
            if ($allDay) {
                // A SENT boundary is a date and is read literally
                // (Time::parseAllDay). A boundary that was not sent is the
                // stored instant's date in the zone it was stored in: that is
                // what turns a timed event into an all-day one.
                $asDate = static fn(\DateTimeImmutable $t): \DateTimeImmutable => new \DateTimeImmutable($t->setTimezone($floorTz)->format('Y-m-d'), Time::utc());
                $newStart = isset($in['start'])
                    ? self::allDayBoundary((string) $in['start'], 'UTC')
                    : $asDate($curStart);
                $newEnd = isset($in['end'])
                    ? self::allDayBoundary((string) $in['end'], 'UTC')
                    : $asDate($curEnd);
                if ($newEnd <= $newStart) {
                    $newEnd = self::nextMidnight($newStart, 'UTC');
                }
            } else {
                $newStart = isset($in['start']) ? Time::parseIso((string) $in['start'], $tzid) : $curStart;
                $newEnd = isset($in['end']) ? Time::parseIso((string) $in['end'], $tzid) : $curEnd;
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
                    $fields[$col] = Sanitize::description(Ics::clip($fields[$col], Limits::get('DESCRIPTION_CHARS')));
                }
                if ($col === 'url' && $fields[$col] !== null) {
                    $fields[$col] = self::cleanUrl($fields[$col]);
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
        if ($allDay) {
            // Dates, not instants: see Time::parseAllDay.
            $start = self::allDayBoundary((string) $in['start'], $tzid);
            $end = isset($in['end']) ? self::allDayBoundary((string) $in['end'], $tzid) : null;
            if ($end === null || $end <= $start) {
                $end = self::nextMidnight($start, $tzid);
            }
        } else {
            $start = Time::parseIso((string) $in['start'], $tzid);
            $end = isset($in['end']) ? Time::parseIso((string) $in['end'], $tzid) : $start->add(new \DateInterval('PT1H'));
        }
        if ($end <= $start) {
            throw HttpError::badRequest('end must be after start');
        }
        return [Time::toDb($start), Time::toDb($end)];
    }

    /** An all-day boundary from a request, as a 400 rather than a 500 when malformed. */
    private static function allDayBoundary(string $iso, string $tzid): \DateTimeImmutable
    {
        try {
            return Time::parseAllDay($iso, $tzid);
        } catch (\InvalidArgumentException $e) {
            throw HttpError::badRequest($e->getMessage());
        }
    }

    /** The midnight after this one in the event's zone (not +24h: DST days are 23 or 25 hours). */
    private static function nextMidnight(\DateTimeImmutable $utc, string $tzid): \DateTimeImmutable
    {
        return $utc->setTimezone(Time::zone($tzid))->modify('+1 day')->setTime(0, 0)->setTimezone(Time::utc());
    }

    private function requireInstance(array $in, array $master): string
    {
        $raw = $in['instanceStart'] ?? null;
        if ($raw === null || $raw === '') {
            throw HttpError::badRequest('instanceStart is required for this scope');
        }
        // An all-day occurrence is named by its date. Its serialized start is
        // "<date>T00:00:00+00:00", which as an INSTANT is only the occurrence
        // for a series stored in UTC; for a series in any other zone it named
        // no occurrence at all.
        if ((int) ($master['all_day'] ?? 0) === 1) {
            return Time::toDb(self::allDayBoundary((string) $raw, (string) $master['tzid']));
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
        try {
            return Recurrence::decodeExdates($row['exdates_json'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw HttpError::conflict('recurrence_exception_limit', $e->getMessage());
        }
    }

    /** Add one skipped occurrence without allowing a series over its durable limit. */
    private function withExdate(array $row, string $instanceUtc): array
    {
        $exdates = $this->decodeExdates($row);
        if (!in_array($instanceUtc, $exdates, true)) {
            $exdates[] = $instanceUtc;
        }
        try {
            return Recurrence::validateExdates($exdates);
        } catch (\InvalidArgumentException $e) {
            throw HttpError::conflict('recurrence_exception_limit', $e->getMessage());
        }
    }

    private function exdatesFrom(array $master, string $instanceUtc): ?string
    {
        $kept = array_values(array_filter($this->decodeExdates($master), static fn(string $ex) => $ex >= $instanceUtc));
        return $kept === [] ? null : json_encode($kept);
    }

    /**
     * The rule for the part of a series from $instanceUtc on: a COUNT keeps
     * what is left of it (occurrences before the split are the old series').
     * Dropping it made "10 times" split on the 6th run on for ever (audit,
     * 0.9.14). Skipped dates still count toward COUNT (RFC 5545), so the
     * occurrences before the split are counted from the rule alone.
     */
    private function followingRrule(array $master, string $instanceUtc): string
    {
        $rrule = (string) $master['rrule'];
        $parts = Recurrence::rruleParts($rrule);
        if (!isset($parts['COUNT'])) {
            return $rrule;
        }
        try {
            $before = count(Recurrence::sabreExpand(['exdates_json' => null] + $master, Time::fromDb((string) $master['start_utc']), Time::fromDb($instanceUtc)));
        } catch (\Throwable) {
            $before = 0;
        }
        $parts['COUNT'] = (string) max(1, (int) $parts['COUNT'] - $before);
        $out = [];
        foreach ($parts as $k => $v) {
            $out[] = $k . '=' . $v;
        }
        return implode(';', $out);
    }

    /** A URL as stored: no control characters (it is exported unescaped), bounded, null when nothing is left. */
    private static function cleanUrl(string $url): ?string
    {
        $url = Ics::clip(Ics::structural(trim($url)), Limits::get('URL_CHARS'));
        return $url !== '' ? $url : null;
    }

    private function statusOrDefault(mixed $status): string
    {
        $status = strtolower((string) ($status ?? 'confirmed'));
        return in_array($status, ['confirmed', 'tentative', 'cancelled'], true) ? $status : 'confirmed';
    }
}
