<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\HttpClient;
use BetterCal\Support\Limits;
use BetterCal\Support\RemotePaginationBudget;
use BetterCal\Support\Time;

/**
 * One Google calendar into one subscribed calendar, read-only (GH #42, tier 1).
 *
 * Google's events are turned into the exact shape Ics::parse produces, so
 * Feeds::sync does the materialisation (upsert by uid + instance, delete
 * what disappeared, ChangeLog, Activity roll-up, health) unchanged. The
 * first poll lists everything and stores the sync token Google hands back;
 * every later poll asks only "what changed since?", merges those changes
 * into the current snapshot, and hands the whole snapshot to sync, which
 * diffs against the table and writes only the differences. A 410 from
 * Google (token expired) starts over with a full list.
 */
final class GoogleSync
{
    private const EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/%s/events';
    private const PAGE_SIZE = 2500;

    public function __construct(
        private readonly Db $db,
        private readonly GoogleAuth $auth,
        private readonly Feeds $feeds,
    ) {
    }

    /** @return int upserted count (Feeds::sync's return) */
    public function poll(array $calendar): int
    {
        $deadline = microtime(true) + Limits::get('GOOGLE_SYNC_SECONDS');
        $eventLimit = Limits::googleEventBudget();
        $calendarId = (int) $calendar['id'];
        if ($eventLimit < 1) {
            throw new \RuntimeException(
                'Google calendar synchronization cannot start because this PHP worker has no safe memory capacity;'
                . ' increase PHP memory_limit. The existing calendar and sync position were kept unchanged.'
            );
        }
        if ($calendar['google_account_id'] === null) {
            throw new \RuntimeException('Google account disconnected; delete this calendar or adopt it as local, then add it again from Settings, Connections');
        }
        $account = $this->db->one('SELECT * FROM google_accounts WHERE id = ?', [(int) $calendar['google_account_id']]);
        if ($account === null) {
            throw new \RuntimeException('Google account disconnected; delete this calendar or adopt it as local, then add it again from Settings, Connections');
        }
        // Check the actual stored payload before any remote request. A count
        // alone is not enough: individually valid 64 KiB descriptions can
        // make SELECT * fatal long before the event ceiling is reached.
        $this->assertCachedSnapshotFits($calendarId, $eventLimit, $deadline);
        $access = $this->auth->accessToken($account, $deadline);
        self::assertWithinDeadline($deadline);
        $googleCalendarId = (string) $calendar['google_calendar_id'];
        // The access role decides whether writes are allowed (GoogleWriter).
        // Calendars added before it was recorded learn it on their next poll.
        $learnedRole = null;
        if (empty($calendar['google_access_role'])) {
            foreach ($this->auth->listCalendars($account, $deadline) as $entry) {
                if ($entry['id'] === $googleCalendarId) {
                    $learnedRole = $entry['accessRole'];
                    break;
                }
            }
        }
        $syncToken = $calendar['google_sync_token'] !== null ? (string) $calendar['google_sync_token'] : null;
        $budget = new RemotePaginationBudget(
            'Google calendar sync',
            Limits::get('GOOGLE_SYNC_PAGES'),
            $eventLimit,
            Limits::get('GOOGLE_SYNC_BYTES'),
            $deadline,
        );
        $http = new HttpClient(
            requestBudget: Limits::get('GOOGLE_SYNC_PAGES'),
            userAgent: HttpClient::userAgentFor('google-connector'),
            maxBytes: min(20 * 1024 * 1024, Limits::get('GOOGLE_SYNC_BYTES')),
            maxTotalBytes: Limits::get('GOOGLE_SYNC_BYTES'),
            absoluteDeadline: $deadline,
        );
        $exdateWork = 0;

        try {
            [$changes, $nextToken] = $this->listEvents(
                $account,
                $googleCalendarId,
                $access,
                $syncToken,
                $http,
                $budget,
                $exdateWork,
            );
        } catch (GoneException) {
            // Google forgot our token (it does after a while, and after some
            // kinds of change). Start over: a full list is the truth.
            $syncToken = null;
            $budget->restartSequence();
            [$changes, $nextToken] = $this->listEvents(
                $account,
                $googleCalendarId,
                $access,
                null,
                $http,
                $budget,
                $exdateWork,
            );
        }

        if ($syncToken === null) {
            $snapshot = self::applyChanges([], $changes, $eventLimit, $deadline);
        } else {
            // Re-evaluate with the parsed remote changes already resident:
            // this is the precise point before PDO would materialise rows.
            $this->assertCachedSnapshotFits($calendarId, $eventLimit, $deadline);
            self::assertWithinDeadline($deadline);
            $rows = $this->db->all(
                'SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL',
                [$calendarId]
            );
            $cached = self::rowsToParsed($rows, $eventLimit, $deadline);
            unset($rows);
            $snapshot = self::applyChanges($cached, $changes, $eventLimit, $deadline);
            unset($cached);
        }
        unset($changes);
        self::assertWithinDeadline($deadline);
        return $this->db->tx(function () use ($account, $calendar, $calendarId, $eventLimit, $learnedRole, $snapshot, $nextToken, $deadline): int {
            // Adopt and every event writer serialize on this row. Re-read the
            // complete Google binding after the fetch so a response from the
            // old authority can never land in a newly-local, moving calendar.
            $current = CalendarMoveGuard::lockCalendar($this->db, $calendarId);
            if (!self::sameBinding($calendar, $current)) {
                throw HttpError::conflict('subscription_authorization_revoked', 'This calendar no longer uses that Google source; the fetched response was discarded.');
            }
            // The last HTTP request may have been in flight when reset landed.
            // The calendar comes first, matching compromise reset and event
            // writers; then the account lock makes reset/finalization atomic.
            $this->auth->assertUsable($account, true);
            if ($learnedRole !== null) {
                $this->db->update('calendars', ['google_access_role' => $learnedRole], 'id = ?', [$calendarId]);
                $current['google_access_role'] = $learnedRole;
            }
            // Feeds::sync loads and indexes every stored row (including a
            // soft-deleted one), while the new snapshot is still resident.
            // Re-query under the account lock immediately before that load.
            $this->assertCachedSnapshotFits($calendarId, $eventLimit, $deadline);
            self::assertWithinDeadline($deadline);
            $count = $this->feeds->sync($current, $snapshot, true, $deadline);
            self::assertWithinDeadline($deadline);
            $this->db->update('calendars', ['google_sync_token' => $nextToken], 'id = ?', [$calendarId]);
            $this->feeds->pollSucceeded($current, count($snapshot), $count);
            return $count;
        });
    }

    /** Does a fetched response still belong to the exact subscribed Google source? */
    public static function sameBinding(array $expected, ?array $current): bool
    {
        return $current !== null
            && (string) ($current['kind'] ?? '') === 'subscribed'
            && (string) ($current['provider'] ?? '') === 'google'
            && (int) ($current['google_account_id'] ?? 0) === (int) ($expected['google_account_id'] ?? 0)
            && (string) ($current['google_calendar_id'] ?? '') === (string) ($expected['google_calendar_id'] ?? '')
            && (int) ($current['google_binding_version'] ?? 0) === (int) ($expected['google_binding_version'] ?? 0);
    }

    // ---- Google API ---------------------------------------------------------

    /**
     * @return array{0:list<array>,1:string} parsed changes and the next sync token
     * @throws GoneException when Google says the sync token is stale (410)
     */
    private function listEvents(
        array $account,
        string $googleCalendarId,
        string $access,
        ?string $syncToken,
        HttpClient $http,
        RemotePaginationBudget $budget,
        int &$exdateWork,
    ): array {
        $changes = [];
        $pageToken = null;
        $nextSync = null;
        do {
            // A compromise reset may commit between pages. Permit only the
            // request already in flight, never the rest of a long listing.
            $this->auth->assertUsable($account);
            $params = [
                'maxResults' => (string) $budget->beginPage(self::PAGE_SIZE),
                'showDeleted' => 'true',
                'singleEvents' => 'false',
            ];
            if ($syncToken !== null) {
                $params['syncToken'] = $syncToken;
            }
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }
            $url = sprintf(self::EVENTS_URL, rawurlencode($googleCalendarId)) . '?' . http_build_query($params);
            try {
                $r = $http->get($url, ['Authorization: Bearer ' . $access, 'Accept: application/json']);
            } catch (\RuntimeException $e) {
                // Translate the shared transport's intentionally generic
                // ceilings into the calendar error the owner will actually see.
                $budget->assertWithinDeadline();
                if (str_contains($e->getMessage(), 'cumulative response budget')) {
                    throw new \RuntimeException(
                        'Google calendar sync exceeded its ' . round(Limits::get('GOOGLE_SYNC_BYTES') / 1048576, 1)
                        . ' MiB cumulative response limit; the existing calendar and sync position were kept unchanged.',
                        previous: $e,
                    );
                }
                if (str_contains($e->getMessage(), 'Response exceeded')) {
                    $pageMiB = min(20 * 1024 * 1024, Limits::get('GOOGLE_SYNC_BYTES')) / 1048576;
                    throw new \RuntimeException(
                        'Google calendar sync returned one page over its ' . round($pageMiB, 1)
                        . ' MiB safety limit; the existing calendar and sync position were kept unchanged.',
                        previous: $e,
                    );
                }
                throw $e;
            }
            $budget->consumeResponse($r['body']);
            if ($r['status'] === 410) {
                throw new GoneException();
            }
            $data = json_decode($r['body'], true);
            if ($r['status'] < 200 || $r['status'] >= 300 || !is_array($data)) {
                $error = is_array($data) ? ($data['error'] ?? null) : null;
                $why = is_array($error) ? (string) ($error['message'] ?? '') : (is_string($error) ? $error : '');
                throw new \RuntimeException('Google Calendar API: HTTP ' . $r['status'] . ($why !== '' ? " ($why)" : ''));
            }
            $items = $budget->acceptItems($data['items'] ?? []);
            $pageToken = $budget->nextPageToken($data);
            $candidate = self::terminalSyncToken($data, $pageToken);
            if ($candidate !== null) {
                $nextSync = $candidate;
            }
            $pageChanges = self::toParsedList($items, $budget->deadline(), $exdateWork);
            foreach ($pageChanges as $change) {
                $changes[] = $change;
            }
            unset($r, $data, $items, $pageChanges);
        } while ($pageToken !== null);

        return [$changes, $nextSync];
    }

    // ---- Shape translation (pure, unit-tested) ---------------------------------

    /**
     * Google event resources to the Ics::parse shape. Cancelled items come
     * back as tombstones (['cancelled' => true, 'uid', 'recurrence_instance_utc'])
     * so applyChanges can remove a series or add an EXDATE.
     *
     * @param list<array> $items
     * @return list<array>
     */
    public static function toParsedList(array $items, ?float $deadline = null, ?int &$work = null): array
    {
        $out = [];
        $work ??= 0;
        $max = Limits::get('EXDATE_VALUES_PER_INPUT');
        foreach ($items as $index => $item) {
            if (($index & 63) === 0) {
                self::assertWithinDeadline($deadline);
            }
            $work += self::itemExdateValueCount($item);
            // A cancelled recurring instance becomes one EXDATE when changes
            // merge into the cached master, even though Google sends it as a
            // separate resource rather than an EXDATE content line.
            if (($item['status'] ?? '') === 'cancelled' && isset($item['originalStartTime'])) {
                $work++;
            }
            if ($work > $max) {
                throw new \InvalidArgumentException(
                    'This Google sync batch has more than ' . number_format($max) . ' skipped occurrences and was not applied.'
                );
            }
            $parsed = self::toParsed($item);
            if ($parsed !== null) {
                $out[] = $parsed;
            }
        }
        self::assertWithinDeadline($deadline);
        return $out;
    }

    public static function toParsed(array $item): ?array
    {
        $rawExdates = self::itemExdateValueCount($item);
        $maxExdates = Limits::get('EXDATE_VALUES_PER_EVENT');
        if ($rawExdates > $maxExdates) {
            throw new \InvalidArgumentException(
                'One Google event has ' . number_format($rawExdates) . ' skipped occurrences, over the limit of ' . number_format($maxExdates) . '.'
            );
        }
        $uid = trim((string) ($item['iCalUID'] ?? ''));
        if ($uid === '') {
            $uid = trim((string) ($item['id'] ?? ''));
        }
        if ($uid === '') {
            return null;
        }
        $uid = Ics::uidOrNew(Ics::structural($uid));
        $instance = null;
        if (isset($item['originalStartTime'])) {
            $instance = self::whenUtc($item['originalStartTime']);
        }
        if (($item['status'] ?? 'confirmed') === 'cancelled') {
            return ['cancelled' => true, 'uid' => $uid, 'recurrence_instance_utc' => $instance];
        }
        // Google's own id for the resource: what the API is addressed by when
        // writing back (an exception's id is the instance id form).
        $googleId = isset($item['id']) ? (string) $item['id'] : null;
        if (!isset($item['start'])) {
            return null;
        }
        $allDay = isset($item['start']['date']);
        $tzid = 'UTC';
        if (!$allDay && !empty($item['start']['timeZone'])) {
            $tzid = Time::normalizeTzid((string) $item['start']['timeZone']);
        }
        $start = self::when($item['start']);
        $end = isset($item['end']) ? self::when($item['end']) : null;
        if ($start === null) {
            return null;
        }
        if ($end === null || $end <= $start) {
            $end = $start->add(new \DateInterval($allDay ? 'P1D' : 'PT1H'));
        }

        $rrule = null;
        $exdates = [];
        foreach ((array) ($item['recurrence'] ?? []) as $line) {
            $line = (string) $line;
            if (str_starts_with(strtoupper($line), 'RRULE:')) {
                $rrule = Recurrence::safeRrule(substr($line, 6));
            } elseif (str_starts_with(strtoupper($line), 'EXDATE')) {
                foreach (self::exdateValues($line, $tzid) as $ex) {
                    $exdates[] = $ex;
                }
            }
        }

        $status = strtolower((string) ($item['status'] ?? 'confirmed'));
        if (!in_array($status, ['confirmed', 'tentative'], true)) {
            $status = 'confirmed';
        }
        $title = trim((string) ($item['summary'] ?? ''));
        $description = isset($item['description']) ? (string) $item['description'] : null;
        // The event's own link when it has one (GoogleWriter sends it as
        // `source`), else Google's page for the event.
        $link = isset($item['source']['url']) && preg_match('#^https?://#i', (string) $item['source']['url']) === 1
            ? (string) $item['source']['url']
            : (isset($item['htmlLink']) ? (string) $item['htmlLink'] : null);
        $url = Ics::webUrl($link);

        return [
            'uid' => $uid,
            'title' => mb_substr($title === '' ? '(No title)' : $title, 0, 500),
            'description' => $description !== null && $description !== '' ? Ics::clip($description, Limits::get('DESCRIPTION_CHARS')) : null,
            'location' => isset($item['location']) && trim((string) $item['location']) !== '' ? mb_substr((string) $item['location'], 0, 500) : null,
            'url' => $url,
            'start_utc' => $start->format(Time::DB),
            'end_utc' => $end->format(Time::DB),
            'all_day' => $allDay ? 1 : 0,
            'tzid' => $tzid,
            'rrule' => $rrule,
            'exdates' => Recurrence::validateExdates($exdates),
            'status' => $status,
            'recurrence_instance_utc' => $instance,
            'reminders' => [],
            'google_event_id' => $googleId,
        ] + (isset($item['attendees'])
            // A guest list: an invitation to answer when someone else asked
            // this account (Rsvp::fromGoogle). Only when Google sends one, so
            // an event without guests keeps whatever block it had.
            ? ['invite_json' => ($invite = Rsvp::fromGoogle($item)) !== null ? json_encode($invite) : null]
            : []);
    }

    /**
     * Apply a change list to a snapshot (both in the parsed shape). Keys are
     * uid|instance, like Feeds::sync. A cancelled master removes its whole
     * series; a cancelled instance removes its override and becomes an
     * EXDATE on the master, which is how Google says "this one is skipped".
     *
     * @param list<array> $snapshot
     * @param list<array> $changes
     * @return list<array>
     */
    public static function applyChanges(
        array $snapshot,
        array $changes,
        ?int $maxEvents = null,
        ?float $deadline = null,
    ): array
    {
        $maxEvents ??= PHP_INT_MAX;
        if (count($snapshot) > $maxEvents) {
            throw new \RuntimeException(self::eventLimitMessage($maxEvents, 'cached Google calendar'));
        }
        Recurrence::assertExdateBatch($snapshot);
        $byKey = [];
        $keysByUid = [];
        foreach ($snapshot as $index => $ev) {
            if (($index & 127) === 0) {
                self::assertWithinDeadline($deadline);
            }
            $key = self::key($ev);
            $byKey[$key] = $ev;
            $keysByUid[(string) $ev['uid']][$key] = true;
        }
        $pendingExdates = [];
        foreach ($changes as $index => $ev) {
            if (($index & 127) === 0) {
                self::assertWithinDeadline($deadline);
            }
            if (!empty($ev['cancelled'])) {
                if ($ev['recurrence_instance_utc'] === null) {
                    foreach (array_keys($keysByUid[(string) $ev['uid']] ?? []) as $key) {
                        unset($byKey[$key]);
                    }
                    unset($keysByUid[(string) $ev['uid']]);
                } else {
                    $key = self::key($ev);
                    unset($byKey[$key], $keysByUid[(string) $ev['uid']][$key]);
                    $pendingExdates[$ev['uid']][] = $ev['recurrence_instance_utc'];
                }
                continue;
            }
            $key = self::key($ev);
            if (!isset($byKey[$key]) && count($byKey) >= $maxEvents) {
                throw new \RuntimeException(self::eventLimitMessage($maxEvents, 'Google calendar'));
            }
            $byKey[$key] = $ev;
            $keysByUid[(string) $ev['uid']][$key] = true;
        }
        foreach ($pendingExdates as $uid => $dates) {
            $masterKey = $uid . '|';
            if (!isset($byKey[$masterKey])) {
                continue;
            }
            $byKey[$masterKey]['exdates'] = Recurrence::validateExdates(array_merge($byKey[$masterKey]['exdates'] ?? [], $dates));
        }
        $result = array_values($byKey);
        if (count($result) > $maxEvents) {
            throw new \RuntimeException(self::eventLimitMessage($maxEvents, 'Google calendar'));
        }
        Recurrence::assertExdateBatch($result);
        self::assertWithinDeadline($deadline);
        return $result;
    }

    /**
     * The table's rows back into the parsed shape, so the incremental merge
     * starts from what we have.
     *
     * @param list<array> $rows
     * @return list<array>
     */
    public static function rowsToParsed(array $rows, ?int $maxEvents = null, ?float $deadline = null): array
    {
        $maxEvents ??= PHP_INT_MAX;
        if (count($rows) > $maxEvents) {
            throw new \RuntimeException(self::eventLimitMessage($maxEvents, 'cached Google calendar'));
        }
        $out = [];
        $totalExdates = 0;
        $maxExdates = Limits::get('EXDATE_VALUES_PER_INPUT');
        foreach ($rows as $index => $r) {
            if (($index & 127) === 0) {
                self::assertWithinDeadline($deadline);
            }
            $encodedExdates = $r['exdates_json'] ?? null;
            $totalExdates += Recurrence::encodedExdateValueCount($encodedExdates);
            if ($totalExdates > $maxExdates) {
                throw new \InvalidArgumentException(
                    'The cached Google calendar has more than ' . number_format($maxExdates) . ' skipped occurrences and was not processed.'
                );
            }
            $exdates = Recurrence::decodeExdates($encodedExdates);
            $out[] = [
                'uid' => (string) $r['uid'],
                'title' => (string) $r['title'],
                'description' => $r['description'] !== null ? (string) $r['description'] : null,
                'location' => $r['location'] !== null ? (string) $r['location'] : null,
                'url' => $r['url'] !== null ? (string) $r['url'] : null,
                'start_utc' => (string) $r['start_utc'],
                'end_utc' => (string) $r['end_utc'],
                'all_day' => (int) $r['all_day'],
                'tzid' => (string) $r['tzid'],
                'rrule' => $r['rrule'] !== null ? (string) $r['rrule'] : null,
                'exdates' => $exdates,
                'status' => (string) $r['status'],
                'recurrence_instance_utc' => $r['recurrence_instance_utc'] !== null ? (string) $r['recurrence_instance_utc'] : null,
                'reminders' => [],
                'google_event_id' => isset($r['google_event_id']) && $r['google_event_id'] !== null ? (string) $r['google_event_id'] : null,
            ] + (isset($r['invite_json']) && $r['invite_json'] !== null
                ? ['invite_json' => is_array($r['invite_json']) ? json_encode($r['invite_json']) : (string) $r['invite_json']]
                : []);
        }
        self::assertWithinDeadline($deadline);
        return $out;
    }

    private static function key(array $ev): string
    {
        return $ev['uid'] . '|' . ($ev['recurrence_instance_utc'] ?? '');
    }

    /**
     * Guard both the active snapshot count and the real variable-width bytes
     * before any SELECT * can construct the cached calendar in PHP memory.
     */
    private function assertCachedSnapshotFits(int $calendarId, int $eventLimit, ?float $deadline): void
    {
        self::assertWithinDeadline($deadline);
        $metrics = $this->db->one(
            "SELECT
                COALESCE(SUM(CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END), 0) AS active_count,
                COUNT(*) AS row_count,
                COALESCE(SUM(
                    COALESCE(LENGTH(uid), 0) + COALESCE(LENGTH(title), 0)
                    + COALESCE(LENGTH(description), 0) + COALESCE(LENGTH(location), 0)
                    + COALESCE(LENGTH(url), 0) + COALESCE(LENGTH(tzid), 0)
                    + COALESCE(LENGTH(rrule), 0) + COALESCE(LENGTH(exdates_json), 0)
                    + COALESCE(LENGTH(status), 0) + COALESCE(LENGTH(source), 0)
                    + COALESCE(LENGTH(attendance), 0) + COALESCE(LENGTH(style_json), 0)
                    + COALESCE(LENGTH(dynamic_json), 0) + COALESCE(LENGTH(reminders_json), 0)
                    + COALESCE(LENGTH(invite_json), 0) + COALESCE(LENGTH(created_via), 0)
                    + COALESCE(LENGTH(google_event_id), 0) + COALESCE(LENGTH(icon), 0)
                ), 0) AS payload_bytes
             FROM events WHERE calendar_id = ?",
            [$calendarId],
        ) ?? ['active_count' => 0, 'row_count' => 0, 'payload_bytes' => 0];
        self::assertWithinDeadline($deadline);
        $activeCount = (int) $metrics['active_count'];
        if ($activeCount > $eventLimit) {
            throw new \RuntimeException(self::eventLimitMessage($eventLimit, 'cached Google calendar'));
        }
        $problem = Limits::googleSnapshotMemoryProblem(
            (int) $metrics['row_count'],
            (int) $metrics['payload_bytes'],
        );
        if ($problem !== null) {
            throw new \RuntimeException($problem);
        }
    }

    private static function assertWithinDeadline(?float $deadline): void
    {
        if ($deadline !== null && microtime(true) >= $deadline) {
            throw new \RuntimeException('Google calendar synchronization exceeded its elapsed-time safety limit; the existing calendar and sync position were kept unchanged.');
        }
    }

    private static function eventLimitMessage(int $limit, string $subject): string
    {
        return ucfirst($subject) . ' has more than the safe limit of ' . number_format($limit)
            . ' events for this server; the existing calendar and sync position were kept unchanged.';
    }

    /** Validate Google's only durable position marker before local writes. */
    private static function terminalSyncToken(array $data, ?string $pageToken): ?string
    {
        if ($pageToken !== null) {
            if (array_key_exists('nextSyncToken', $data)) {
                throw new \RuntimeException('Google calendar sync returned a sync token before its final page; the existing data was kept unchanged.');
            }
            return null;
        }
        $candidate = $data['nextSyncToken'] ?? null;
        if (!is_string($candidate) || trim($candidate) === '' || strlen($candidate) > 255) {
            throw new \RuntimeException('Google calendar sync ended without a valid sync token; the existing data was kept unchanged.');
        }
        return $candidate;
    }

    /** Number of raw comma-packed EXDATE entries before allocating them. */
    private static function itemExdateValueCount(array $item): int
    {
        $count = 0;
        foreach ((array) ($item['recurrence'] ?? []) as $line) {
            $count += Recurrence::exdateValueCount((string) $line);
        }
        return $count;
    }

    /** Google's {date} | {dateTime, timeZone} to a UTC instant; all-day dates are midnight UTC like Ics does. */
    private static function when(array $when): ?\DateTimeImmutable
    {
        try {
            if (isset($when['date'])) {
                return new \DateTimeImmutable((string) $when['date'] . ' 00:00:00', Time::utc());
            }
            if (isset($when['dateTime'])) {
                return (new \DateTimeImmutable((string) $when['dateTime']))->setTimezone(Time::utc());
            }
        } catch (\Exception) {
            return null;
        }
        return null;
    }

    private static function whenUtc(array $when): ?string
    {
        $dt = self::when($when);
        return $dt?->format(Time::DB);
    }

    /**
     * EXDATE line values to UTC db strings. Forms seen from Google:
     *   EXDATE;TZID=America/Chicago:20260310T090000,20260317T090000
     *   EXDATE;VALUE=DATE:20260310
     *   EXDATE:20260310T140000Z
     *
     * @return list<string>
     */
    private static function exdateValues(string $line, string $defaultTzid): array
    {
        $colon = strpos($line, ':');
        if ($colon === false) {
            return [];
        }
        $head = substr($line, 0, $colon);
        $values = substr($line, $colon + 1);
        $tzid = $defaultTzid;
        if (preg_match('/TZID=([^;:]+)/i', $head, $m)) {
            $tzid = Time::normalizeTzid($m[1]);
        }
        $out = [];
        foreach (explode(',', $values) as $v) {
            $v = trim($v);
            try {
                if (preg_match('/^\d{8}$/', $v)) {
                    $out[] = (new \DateTimeImmutable($v, Time::utc()))->format(Time::DB);
                } elseif (str_ends_with($v, 'Z')) {
                    $out[] = (new \DateTimeImmutable($v, Time::utc()))->format(Time::DB);
                } elseif ($v !== '') {
                    $out[] = (new \DateTimeImmutable($v, Time::zone($tzid)))->setTimezone(Time::utc())->format(Time::DB);
                }
            } catch (\Exception) {
                // an unparseable EXDATE is dropped, not fatal
            }
        }
        return $out;
    }
}

/** Google answered 410: the sync token is no longer valid. */
final class GoneException extends \RuntimeException
{
}
