<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Infra\HttpClient;
use BetterCal\Support\Limits;
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
        $calendarId = (int) $calendar['id'];
        if ($calendar['google_account_id'] === null) {
            throw new \RuntimeException('Google account disconnected; reconnect it under Settings, Google, or delete this calendar');
        }
        $account = $this->db->one('SELECT * FROM google_accounts WHERE id = ?', [(int) $calendar['google_account_id']]);
        if ($account === null) {
            throw new \RuntimeException('Google account disconnected; reconnect it under Settings, Google, or delete this calendar');
        }
        $access = $this->auth->accessToken($account);
        $googleCalendarId = (string) $calendar['google_calendar_id'];
        // The access role decides whether writes are allowed (GoogleWriter).
        // Calendars added before it was recorded learn it on their next poll.
        if (empty($calendar['google_access_role'])) {
            foreach ($this->auth->listCalendars($account) as $entry) {
                if ($entry['id'] === $googleCalendarId) {
                    $this->db->update('calendars', ['google_access_role' => $entry['accessRole']], 'id = ?', [$calendarId]);
                    break;
                }
            }
        }
        $syncToken = $calendar['google_sync_token'] !== null ? (string) $calendar['google_sync_token'] : null;

        try {
            [$items, $nextToken] = $this->listEvents($googleCalendarId, $access, $syncToken);
        } catch (GoneException) {
            // Google forgot our token (it does after a while, and after some
            // kinds of change). Start over: a full list is the truth.
            $syncToken = null;
            [$items, $nextToken] = $this->listEvents($googleCalendarId, $access, null);
        }

        $changes = self::toParsedList($items);
        if ($syncToken === null) {
            $snapshot = self::applyChanges([], $changes);
        } else {
            $snapshot = self::applyChanges(self::rowsToParsed($this->db->all(
                'SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL',
                [$calendarId]
            )), $changes);
        }
        $count = $this->feeds->sync($calendar, $snapshot);
        if ($nextToken !== null) {
            $this->db->update('calendars', ['google_sync_token' => $nextToken], 'id = ?', [$calendarId]);
        }
        $this->feeds->pollSucceeded($calendar, count($snapshot), $count);
        return $count;
    }

    // ---- Google API ---------------------------------------------------------

    /**
     * @return array{0:list<array>,1:?string} items and the next sync token
     * @throws GoneException when Google says the sync token is stale (410)
     */
    private function listEvents(string $googleCalendarId, string $access, ?string $syncToken): array
    {
        $http = new HttpClient(requestBudget: 60, userAgent: 'Better-Cal/0.1 (+google-connector)', maxBytes: 20 * 1024 * 1024);
        $items = [];
        $pageToken = null;
        $nextSync = null;
        do {
            $params = ['maxResults' => (string) self::PAGE_SIZE, 'showDeleted' => 'true', 'singleEvents' => 'false'];
            if ($syncToken !== null) {
                $params['syncToken'] = $syncToken;
            }
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }
            $url = sprintf(self::EVENTS_URL, rawurlencode($googleCalendarId)) . '?' . http_build_query($params);
            $r = $http->get($url, ['Authorization: Bearer ' . $access, 'Accept: application/json']);
            if ($r['status'] === 410) {
                throw new GoneException();
            }
            $data = json_decode($r['body'], true);
            if ($r['status'] < 200 || $r['status'] >= 300 || !is_array($data)) {
                $why = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
                throw new \RuntimeException('Google Calendar API: HTTP ' . $r['status'] . ($why !== '' ? " ($why)" : ''));
            }
            foreach ($data['items'] ?? [] as $item) {
                $items[] = $item;
            }
            $pageToken = isset($data['nextPageToken']) ? (string) $data['nextPageToken'] : null;
            if (isset($data['nextSyncToken'])) {
                $nextSync = (string) $data['nextSyncToken'];
            }
        } while ($pageToken !== null);
        return [$items, $nextSync];
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
    public static function toParsedList(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $parsed = self::toParsed($item);
            if ($parsed !== null) {
                $out[] = $parsed;
            }
        }
        return $out;
    }

    public static function toParsed(array $item): ?array
    {
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
        $url = isset($item['htmlLink']) ? Ics::clip(Ics::structural((string) $item['htmlLink']), Limits::get('URL_CHARS')) : null;

        return [
            'uid' => $uid,
            'title' => mb_substr($title === '' ? '(No title)' : $title, 0, 500),
            'description' => $description !== null && $description !== '' ? Ics::clip($description, Limits::get('DESCRIPTION_CHARS')) : null,
            'location' => isset($item['location']) && trim((string) $item['location']) !== '' ? mb_substr((string) $item['location'], 0, 500) : null,
            'url' => $url !== null && $url !== '' ? $url : null,
            'start_utc' => $start->format(Time::DB),
            'end_utc' => $end->format(Time::DB),
            'all_day' => $allDay ? 1 : 0,
            'tzid' => $tzid,
            'rrule' => $rrule,
            'exdates' => array_values(array_unique($exdates)),
            'status' => $status,
            'recurrence_instance_utc' => $instance,
            'reminders' => [],
            'google_event_id' => $googleId,
        ];
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
    public static function applyChanges(array $snapshot, array $changes): array
    {
        $byKey = [];
        foreach ($snapshot as $ev) {
            $byKey[self::key($ev)] = $ev;
        }
        $pendingExdates = [];
        foreach ($changes as $ev) {
            if (!empty($ev['cancelled'])) {
                if ($ev['recurrence_instance_utc'] === null) {
                    foreach (array_keys($byKey) as $k) {
                        if (str_starts_with((string) $k, $ev['uid'] . '|')) {
                            unset($byKey[$k]);
                        }
                    }
                } else {
                    unset($byKey[self::key($ev)]);
                    $pendingExdates[$ev['uid']][] = $ev['recurrence_instance_utc'];
                }
                continue;
            }
            $byKey[self::key($ev)] = $ev;
        }
        foreach ($pendingExdates as $uid => $dates) {
            $masterKey = $uid . '|';
            if (!isset($byKey[$masterKey])) {
                continue;
            }
            $byKey[$masterKey]['exdates'] = array_values(array_unique(array_merge($byKey[$masterKey]['exdates'] ?? [], $dates)));
        }
        return array_values($byKey);
    }

    /**
     * The table's rows back into the parsed shape, so the incremental merge
     * starts from what we have.
     *
     * @param list<array> $rows
     * @return list<array>
     */
    public static function rowsToParsed(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $exdates = [];
            if (!empty($r['exdates_json'])) {
                $decoded = json_decode((string) $r['exdates_json'], true);
                if (is_array($decoded)) {
                    $exdates = array_values(array_map('strval', $decoded));
                }
            }
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
            ];
        }
        return $out;
    }

    private static function key(array $ev): string
    {
        return $ev['uid'] . '|' . ($ev['recurrence_instance_utc'] ?? '');
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
     *   EXDATE;TZID=Europe/London:20260921T150000,20260928T150000
     *   EXDATE;VALUE=DATE:20260921
     *   EXDATE:20260921T140000Z
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
