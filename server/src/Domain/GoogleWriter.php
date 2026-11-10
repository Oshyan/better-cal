<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\HttpClient;
use BetterCal\Support\Time;

/**
 * Write-through to a Google calendar the connected account may edit (GH #42).
 *
 * Google stays authoritative and our rows are its cache: an edit made here
 * goes to Google first, and what Google answers with is materialised
 * locally through the same path a poll uses (GoogleSync::applyChanges +
 * Feeds::sync). No local-only version of a Google event ever exists, so
 * there is nothing to reconcile; a failed write is a 502 and nothing here
 * changes. Attendees and reminders are not sent (attendees would mail
 * people; reminders are per-user on Google's side), so a body never
 * touches them.
 *
 * Every PATCH and DELETE is conditional on a read made just before it
 * (GoogleSeries::condition): the series or event it writes is read from
 * Google, compared with the rows here by its digest (a change made at Google
 * since the last poll is 409 google_conflict, and nothing is sent or written
 * here), and that read's etag goes as If-Match, so a change between the read
 * and the write answers 412, shown the same way. Nothing is ever sent
 * without one. Any write to a series changes the etag of its master and of
 * every instance (the probe, 2026-11-07, group 5), so a write to one row of
 * a series is followed by refreshEtags for the rest.
 */
final class GoogleWriter
{
    private const EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/%s/events';
    private const MOVE_RECOVERY_PROPERTY = 'betterCalMove';
    /** Pages refreshEtags reads for one series at most (2,500 rows each). */
    private const ETAG_PAGES = 4;
    public const CONFLICT_MESSAGE = 'This event changed at Google since you last saw it. Refresh and try again.';
    public const BUSY_MESSAGE = 'Google is busy and refused the change for now; nothing was changed. Try again in a minute.';
    public const DELETED_MESSAGE = 'This event was deleted at Google; nothing was changed. Refresh to see the calendar as Google has it.';

    /** The note on the Activity line of a removal whose target Google had already removed (gone). */
    public const ALREADY_GONE = 'Google had already removed it';
    /**
     * Google's reasons for a refusal that says "too many requests, wait"
     * (a 403 or 429 of the usageLimits domain): the write changed nothing
     * and is sent again after a pause, then reported as busy.
     */
    private const BUSY_REASONS = ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'dailyLimitExceeded', 'variableTermLimitExceeded'];
    /** The pauses before each new try of a request Google refused as busy, in seconds (exponential, as Google's guide asks). */
    private const BUSY_BACKOFF = [1.0, 2.0];
    /** Waits between busy retries; tests set it to record instead of sleep. */
    public ?\Closure $sleep = null;
    /** Event columns Google owns; everything else on a row is local metadata. */
    public const GOOGLE_COLUMNS = ['title', 'description', 'location', 'url', 'start_utc', 'end_utc', 'all_day', 'tzid', 'rrule', 'exdates_json', 'status'];

    public function __construct(
        private readonly Db $db,
        private readonly GoogleAuth $auth,
        private readonly Feeds $feeds,
        private readonly ?\Closure $jsonTransport = null,
    ) {
    }

    /** May the connected account write to this calendar? Pure; unit-tested. */
    public static function writable(array $calendar): bool
    {
        return ($calendar['provider'] ?? 'ics') === 'google'
            && !empty($calendar['google_calendar_id'])
            && $calendar['google_account_id'] !== null
            && in_array((string) ($calendar['google_access_role'] ?? ''), ['writer', 'owner'], true);
    }

    // ---- Shape translation (pure, unit-tested) ---------------------------------

    /**
     * A DB-shaped event row to the Google event resource body. All-day rows
     * become dates in the event's zone; timed rows RFC 3339 with the zone
     * named, which is what keeps a series on the right wall-clock time
     * across DST. Recurrence carries the RRULE and the EXDATEs.
     */
    public static function body(array $row): array
    {
        $allDay = (int) ($row['all_day'] ?? 0) === 1;
        $tz = Time::normalizeTzid((string) ($row['tzid'] ?? 'UTC'));
        $zone = Time::zone($tz);
        $start = Time::fromDb((string) $row['start_utc'])->setTimezone($zone);
        $end = Time::fromDb((string) $row['end_utc'])->setTimezone($zone);
        $body = [
            'summary' => (string) ($row['title'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'location' => (string) ($row['location'] ?? ''),
            // tentative ↔ "maybe" on my calendar (Events::relationship); Google shows it hatched
            'status' => (string) ($row['status'] ?? '') === 'tentative' ? 'tentative' : 'confirmed',
        ];
        // The event's link (a Partiful or Luma page, say) travels as Google's
        // `source`, which GoogleSync reads back, so a write never trades it
        // for Google's own event page (0.9.4).
        $url = trim((string) ($row['url'] ?? ''));
        if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
            $body['source'] = ['url' => $url, 'title' => mb_substr((string) (parse_url($url, PHP_URL_HOST) ?: 'Link'), 0, 100)];
        }
        if ($allDay) {
            $body['start'] = ['date' => $start->format('Y-m-d')];
            $body['end'] = ['date' => $end->format('Y-m-d')];
        } else {
            $body['start'] = ['dateTime' => $start->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz];
            $body['end'] = ['dateTime' => $end->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz];
        }
        try {
            $exdates = Recurrence::decodeExdates($row['exdates_json'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw HttpError::conflict('recurrence_exception_limit', $e->getMessage());
        }
        // Always present: an empty list is how a series stops being one.
        $body['recurrence'] = self::recurrenceLines(!empty($row['rrule']) ? (string) $row['rrule'] : null, $exdates, $allDay, $tz);
        return $body;
    }

    /**
     * @param list<string> $exdatesUtc DB-format UTC instants
     * @return list<string>
     */
    public static function recurrenceLines(?string $rrule, array $exdatesUtc, bool $allDay, string $tz): array
    {
        if ($rrule === null || $rrule === '') {
            return [];
        }
        try {
            $exdatesUtc = Recurrence::validateExdates($exdatesUtc);
        } catch (\InvalidArgumentException $e) {
            throw HttpError::conflict('recurrence_exception_limit', $e->getMessage());
        }
        $lines = ['RRULE:' . $rrule];
        $zone = Time::zone($tz);
        foreach ($exdatesUtc as $ex) {
            $dt = Time::fromDb($ex)->setTimezone($zone);
            $lines[] = $allDay
                ? 'EXDATE;VALUE=DATE:' . $dt->format('Ymd')
                : 'EXDATE;TZID=' . $tz . ':' . $dt->format('Ymd\THis');
        }
        return $lines;
    }

    /**
     * The id Google gives one occurrence of a series: its identity is the
     * series id, the form (timed or all-day) and the key, never the key
     * alone. Timed: `<series>_<UTC basic time>Z`. All-day: `<series>_<YYYYMMDD>`,
     * the date of the key, which for an all-day series is that date at
     * 00:00:00 UTC (stored so since migration 035). Two ids are equal exactly
     * when series, form and key are. Patching or deleting that id is how
     * "this occurrence only" is said to the API. The one function for it;
     * instanceOf is its inverse.
     */
    public static function instanceId(string $seriesId, string $key, bool $allDay): string
    {
        $dt = Time::fromDb($key);
        return $seriesId . '_' . $dt->format($allDay ? 'Ymd' : 'Ymd\THis\Z');
    }

    /**
     * The inverse of instanceId: the series id, the key and the form an
     * instance id names, or null for an id that names no instance (a
     * master's, a single event's). The series id is everything before the
     * last `_`, so a series id with underscores of its own comes back whole.
     *
     * @return array{series: string, key: string, allDay: bool}|null
     */
    public static function instanceOf(string $id): ?array
    {
        $cut = strrpos($id, '_');
        if ($cut === false) {
            return null;
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})Z)?$/', substr($id, $cut + 1), $m) !== 1) {
            return null;
        }
        $allDay = !isset($m[4]);
        return [
            'series' => substr($id, 0, $cut),
            'key' => sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4] ?? '00', $m[5] ?? '00', $m[6] ?? '00'),
            'allDay' => $allDay,
        ];
    }

    /**
     * A changed occurrence of a series, as the body of a patch to its
     * instance id: the series' recurrence never goes on an instance.
     */
    public static function instanceBody(array $row): array
    {
        $body = self::body($row);
        unset($body['recurrence']);
        return $body;
    }

    /**
     * The body keys each field Google holds is sent as (a day's date, time,
     * length and all-day setting are its start and end together).
     */
    private const BODY_KEYS = [
        'date' => ['start', 'end'], 'time' => ['start', 'end'], 'length' => ['start', 'end'], 'allDay' => ['start', 'end'],
        'title' => ['summary'], 'location' => ['location'], 'description' => ['description'], 'url' => ['source'], 'status' => ['status'],
    ];

    /**
     * The one allowed list of a Google request body: the keys of the fields
     * Google holds (OwnFields::googleFields, the complement of what is held
     * here alone) and the series' rule. Pure.
     *
     * @return list<string>
     */
    public static function allowedKeys(): array
    {
        return array_values(array_unique(array_merge(['recurrence'], self::keysOf(Series\OwnFields::googleFields()))));
    }

    /**
     * The body keys the fields $fields are sent as (each a field Google
     * holds). Pure.
     *
     * @param list<string> $fields
     * @return list<string>
     */
    public static function keysOf(array $fields): array
    {
        $keys = [];
        foreach ($fields as $field) {
            $keys = array_merge($keys, self::BODY_KEYS[$field] ?? throw new \LogicException("no body key for the field Google holds $field"));
        }
        return array_values(array_unique($keys));
    }

    /**
     * The PATCH that takes an event from $before to $after (bodies in
     * body()'s shape): each allowed key whose value differs, the start and
     * end together, and one $after lacks (a link removed) as null, which
     * clears it at Google. Every writer builds its body here: empty when
     * nothing Google holds changes, and such a change is held here alone,
     * sends nothing and journals as a local change. Pure.
     */
    public static function patchOf(array $before, array $after): array
    {
        $body = [];
        foreach (array_intersect(GoogleUndo::changedKeys($before, $after), self::allowedKeys()) as $key) {
            $body[$key] = $after[$key] ?? null;
        }
        return $body;
    }

    /**
     * The body for importing an event that already exists here (moving a
     * calendar to Google, 0.9.4): import keeps our iCalendar UID, so the
     * first poll after the move matches every row, and it sends no mail.
     */
    public static function importBody(array $row): array
    {
        return self::body($row) + ['iCalUID' => (string) $row['uid']];
    }

    // ---- Calls ----------------------------------------------------------------

    public function import(array $calendar, array $row, ?string $recoveryMarker = null): array
    {
        $body = self::importBody($row);
        if ($recoveryMarker !== null && $recoveryMarker !== '') {
            // Private extended properties are not displayed as event content.
            // The opaque value makes an accepted import discoverable if the
            // PHP process dies before its Google id is saved locally.
            $body['extendedProperties'] = ['private' => [self::MOVE_RECOVERY_PROPERTY => $recoveryMarker]];
        }
        return $this->call($calendar, 'POST', '/import', $body);
    }

    public function findImportedByMarker(array $calendar, string $marker, string $icalUid): ?array
    {
        $data = $this->call($calendar, 'GET', '', null, [
            'privateExtendedProperty' => self::MOVE_RECOVERY_PROPERTY . '=' . $marker,
            'iCalUID' => $icalUid,
            'showDeleted' => 'false',
            'maxResults' => '2',
        ]);
        return self::recoveredImport($data['items'] ?? [], $marker, $icalUid);
    }

    /** @param list<array> $items */
    public static function recoveredImport(array $items, string $marker, string $icalUid): ?array
    {
        $matches = array_values(array_filter($items, static function (array $item) use ($marker, $icalUid): bool {
            return (string) ($item['iCalUID'] ?? '') === $icalUid
                && hash_equals($marker, (string) ($item['extendedProperties']['private'][self::MOVE_RECOVERY_PROPERTY] ?? ''));
        }));
        if (count($matches) > 1) {
            throw new \RuntimeException('Google returned more than one event for the move recovery marker');
        }
        return $matches[0] ?? null;
    }

    public function insert(array $calendar, array $row, ?\Closure $beforeSend = null): array
    {
        return $this->call($calendar, 'POST', '', self::body($row), beforeSend: $beforeSend);
    }

    public function get(array $calendar, string $googleId): array
    {
        return $this->call($calendar, 'GET', '/' . rawurlencode($googleId), null);
    }

    /**
     * $read: the resource as the caller has just read it and built the body
     * from (Rsvp, an undo), whose etag is the If-Match once the same
     * comparison every write makes has passed (condition); null reads it
     * there. $refresh: re-read the series' etags after a write to one of
     * its rows.
     */
    public function patch(array $calendar, string $googleId, array $body, ?\Closure $beforeSend = null, ?array $read = null, bool $refresh = true): array
    {
        $series = $refresh ? $this->seriesOf($calendar, $googleId) : null;
        $resp = $this->call($calendar, 'PATCH', '/' . rawurlencode($googleId), $body, beforeSend: $beforeSend, ifMatch: $this->condition($calendar, $googleId, $beforeSend, $read));
        if ($refresh && ($series !== null || !empty($resp['recurringEventId']) || !empty($resp['recurrence']))) {
            $this->refreshAfterWrite($calendar, (string) ($resp['iCalUID'] ?? $series ?? ''));
        }
        return $resp;
    }

    public function delete(array $calendar, string $googleId, ?\Closure $beforeSend = null, ?array $read = null, bool $refresh = true): void
    {
        $series = $refresh ? $this->seriesOf($calendar, $googleId) : null;
        $this->call($calendar, 'DELETE', '/' . rawurlencode($googleId), null, beforeSend: $beforeSend, ifMatch: $this->condition($calendar, $googleId, $beforeSend, $read));
        $this->refreshAfterWrite($calendar, $series);
    }

    /**
     * One request for a caller that runs its own sequence of them
     * (GoogleSeries): the method, path, body, query and If-Match as given,
     * no stored etag looked up and no read-back after. Google's answer,
     * decoded; [] for a 204 or a DELETE's 410; the usual errors otherwise.
     */
    public function send(array $calendar, string $method, string $path, ?array $body = null, array $query = [], ?string $ifMatch = null, ?\Closure $beforeSend = null): array
    {
        return $this->call($calendar, $method, $path, $body, $query, $beforeSend, $ifMatch);
    }

    /**
     * The If-Match for a write to $googleId: the etag of a read (made now,
     * or the caller's $read), once what was read is the series (or event)
     * as stored here (GoogleSeries::condition, the one rule every write
     * follows; no caller is exempt).
     */
    private function condition(array $calendar, string $googleId, ?\Closure $beforeSend, ?array $read = null): string
    {
        return (new GoogleSeries($this->db, $this, $this->feeds))->condition($calendar, $googleId, $beforeSend, null, $read)['etag'];
    }

    /**
     * The iCalendar UID of the series $googleId belongs to here, or null for
     * an event in no series: its row's when it has one, else (an occurrence
     * not yet changed, addressed by its instance id) its series' row's.
     */
    private function seriesOf(array $calendar, string $googleId): ?string
    {
        if (!isset($calendar['id'], $calendar['user_id'])) {
            return null;
        }
        $row = $this->rowByGoogleId((int) $calendar['id'], $googleId);
        if ($row !== null) {
            return !empty($row['rrule']) || $row['recurrence_instance_utc'] !== null ? (string) $row['uid'] : null;
        }
        $cut = strrpos($googleId, '_');
        $master = $cut !== false ? $this->rowByGoogleId((int) $calendar['id'], substr($googleId, 0, $cut)) : null;
        return $master !== null ? (string) $master['uid'] : null;
    }

    /**
     * After a write that succeeded at Google: the series' other rows' etags.
     * A failure to read them changes nothing: the write stands, and the next
     * poll's delta lists the series' rows with their new etags (probe 5.2).
     */
    private function refreshAfterWrite(array $calendar, ?string $series): void
    {
        if ($series === null || $series === '' || !isset($calendar['id'], $calendar['user_id'])) {
            return;
        }
        try {
            $etags = $this->seriesEtags($calendar, $series);
        } catch (HttpError | \RuntimeException) {
            return;
        }
        $this->storeEtags($calendar, $etags);
    }

    /**
     * Read the current etag of every row of one series from Google and store
     * each on the row here that Google knows by that id: one list request
     * (singleEvents=false, filtered to the series' iCalendar UID), never
     * events.instances. Only etags change; no content, no updated_at.
     *
     * @return int rows whose etag changed
     */
    public function refreshEtags(array $calendar, string $icalUid): int
    {
        return $this->storeEtags($calendar, $this->seriesEtags($calendar, $icalUid));
    }

    /** @return array<string,string> Google id => etag, for the series' live resources */
    private function seriesEtags(array $calendar, string $icalUid): array
    {
        $out = [];
        $pageToken = null;
        $pages = 0;
        do {
            $query = ['iCalUID' => $icalUid, 'singleEvents' => 'false', 'showDeleted' => 'false', 'maxResults' => '2500'];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $data = $this->call($calendar, 'GET', '', null, $query);
            foreach ((array) ($data['items'] ?? []) as $item) {
                if (is_array($item) && isset($item['id'], $item['etag']) && is_string($item['etag']) && $item['etag'] !== '' && strlen($item['etag']) <= 255) {
                    $out[(string) $item['id']] = $item['etag'];
                }
            }
            $pageToken = isset($data['nextPageToken']) && is_string($data['nextPageToken']) && $data['nextPageToken'] !== '' ? $data['nextPageToken'] : null;
        } while ($pageToken !== null && ++$pages < self::ETAG_PAGES);
        return $out;
    }

    /** @param array<string,string> $etags Google id => etag */
    private function storeEtags(array $calendar, array $etags): int
    {
        if ($etags === []) {
            return 0;
        }
        return (int) (new Undo($this->db))->write((int) $calendar['user_id'], function () use ($calendar, $etags): int {
            $changed = 0;
            foreach ($etags as $googleId => $etag) {
                $changed += $this->db->run(
                    'UPDATE events SET google_etag = ?, updated_at = updated_at
                     WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL AND (google_etag IS NULL OR google_etag <> ?)',
                    [$etag, (int) $calendar['id'], (string) $googleId, $etag]
                )->rowCount();
            }
            return $changed;
        });
    }

    /**
     * Materialise what Google answered with, exactly as a poll would: the
     * resources upsert, the tombstones delete or become EXDATEs. The next
     * scheduled poll sees the same changes again and finds nothing to do.
     *
     * @param list<array> $resources Google event resources
     * @param list<array{cancelled:true,uid:string,recurrence_instance_utc:?string}> $tombstones
     */
    public function apply(array $calendar, array $resources, array $tombstones = []): void
    {
        $changes = array_merge(GoogleSync::toParsedList($resources), $tombstones);
        $snapshot = GoogleSync::applyChanges(GoogleSync::rowsToParsed($this->db->all(
            'SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL',
            [(int) $calendar['id']]
        )), $changes);
        // No feed roll-up in Activity: the caller records what the person did.
        $this->feeds->sync($calendar, $snapshot, journal: false);
    }

    public function rowByGoogleId(int $calendarId, string $googleId): ?array
    {
        return $this->db->one(
            'SELECT * FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL',
            [$calendarId, $googleId]
        );
    }

    /** A tombstone in the parsed shape, for apply(). */
    public static function tombstone(string $uid, ?string $instanceUtc): array
    {
        return ['cancelled' => true, 'uid' => $uid, 'recurrence_instance_utc' => $instanceUtc];
    }

    private function call(array $calendar, string $method, string $path, ?array $payload, array $query = [], ?\Closure $beforeSend = null, ?string $ifMatch = null): array
    {
        $account = $this->db->one('SELECT * FROM google_accounts WHERE id = ?', [(int) $calendar['google_account_id']]);
        if ($account === null) {
            throw new HttpError('google_disconnected', 'The Google account this calendar came from is disconnected; reconnect it under Settings, Connections.', 409);
        }
        $access = $this->access($account);
        if ($method !== 'GET') {
            $query = ['sendUpdates' => 'none'] + $query;
        }
        $url = sprintf(self::EVENTS_URL, rawurlencode((string) $calendar['google_calendar_id'])) . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        // Only a write is conditional: what it changes must be what was read,
        // and none goes without the etag of a read (condition).
        $headers = [];
        if ($method === 'PATCH' || $method === 'DELETE') {
            if ($ifMatch === null || $ifMatch === '') {
                throw new HttpError('google_write_failed', 'Nothing was sent to Google: no version of the event was read to write to.', 502);
            }
            $headers = ['If-Match: ' . $ifMatch];
        }
        $http = new HttpClient(requestBudget: 10, userAgent: HttpClient::userAgentFor('google-connector'));
        // The transport seam (tests, the edit harness) takes the request the
        // real client sends, its bearer token among the headers, so the
        // retry below is the app's own path under test too.
        $send = fn(string $access): array => $this->jsonTransport !== null
            ? ($this->jsonTransport)($method, $url, $payload, array_merge(['Authorization: Bearer ' . $access], $headers))
            : $http->json($method, $url, $payload, array_merge(['Authorization: Bearer ' . $access], $headers));
        // Refreshing a token is network work too. Recheck after it, not before it.
        if ($beforeSend !== null) $beforeSend();
        try {
            $r = $send($access);
        } catch (\RuntimeException $e) {
            throw new HttpError('google_write_failed', 'Google could not be reached: ' . $e->getMessage(), 502);
        }
        // A held token Google no longer takes: once more with a fresh one,
        // and only once (a second 401 is reported below). A refresh Google
        // refuses (a revoked grant) is the sign-in's error, as on the first
        // try, and the recheck runs after the refresh as it does above.
        if ($this->auth->tokenRejected($account, (int) $r['status'])) {
            $access = $this->access($account);
            if ($beforeSend !== null) $beforeSend();
            try {
                $r = $send($access);
            } catch (\RuntimeException $e) {
                throw new HttpError('google_write_failed', 'Google could not be reached: ' . $e->getMessage(), 502);
            }
        }
        // Google refused it as busy (a rate or quota limit): it changed
        // nothing, so the same request goes again after a pause, a bounded
        // number of times.
        for ($try = 0; self::refusal((int) $r['status'], (string) $r['body']) === 'busy' && $try < count(self::BUSY_BACKOFF); $try++) {
            ($this->sleep ?? static fn(float $s) => usleep((int) ($s * 1e6)))(self::BUSY_BACKOFF[$try]);
            if ($beforeSend !== null) $beforeSend();
            try {
                $r = $send($access);
            } catch (\RuntimeException $e) {
                throw new HttpError('google_write_failed', 'Google could not be reached: ' . $e->getMessage(), 502);
            }
        }
        if ($r['status'] === 412) {
            throw new HttpError('google_conflict', self::CONFLICT_MESSAGE, 409);
        }
        if ($r['status'] === 204 || ($method === 'DELETE' && $r['status'] === 410)) {
            return []; // deleted, or already gone
        }
        $data = json_decode($r['body'], true);
        if ($r['status'] < 200 || $r['status'] >= 300 || !is_array($data)) {
            $why = is_array($data) && is_array($data['error'] ?? null) ? (string) ($data['error']['message'] ?? '') : '';
            // Google's own status rides along (a caller retrying an insert
            // with a chosen id reads a 409 as "it landed the first time";
            // one reading a day past the expansion cap reads a 404).
            $extra = ['googleStatus' => $r['status']];
            // Classified by Google's reason, not its status alone: busy
            // (a rate or quota limit, still so after the retries above),
            // the target deleted at Google, a permission refusal, the rest.
            throw match (self::refusal((int) $r['status'], (string) $r['body'])) {
                'busy' => new HttpError('google_busy', self::BUSY_MESSAGE, 503, $extra),
                'deleted' => self::deleted((int) $r['status']),
                'permission' => new HttpError('google_write_failed', 'Google says this account may not edit that calendar' . ($why !== '' ? " ($why)" : ''), 403, $extra),
                default => new HttpError('google_write_failed', 'Google rejected the change: HTTP ' . $r['status'] . ($why !== '' ? " ($why)" : ''), 502, $extra),
            };
        }
        return $data;
    }

    /**
     * The refusal for a target Google no longer has (a 404 or 410 on the
     * write or on the read before it): nothing changed here. Google's
     * status rides along, so a caller removing it reads it as gone.
     */
    public static function deleted(int $googleStatus = 404): HttpError
    {
        return new HttpError('google_deleted', self::DELETED_MESSAGE, 409, ['googleStatus' => $googleStatus]);
    }

    /**
     * Whether a refusal says the target is gone at Google (a 404 or 410 on
     * the write or on the read before it, or the record listed cancelled:
     * deleted). The one reading every removal makes: to a removal it is
     * done, so the removal completes here and says Google had already
     * removed it (ALREADY_GONE); to an edit it stays a refusal. Pure.
     */
    public static function gone(HttpError $e): bool
    {
        return in_array((int) ($e->extra['googleStatus'] ?? 0), [404, 410], true);
    }

    /**
     * What a Google answer that is not a success says, by Google's reason
     * (error.errors[].reason, error.status) as well as its status: 'busy'
     * (429, or a 403 whose reason is a rate or quota limit), 'deleted' (404
     * or 410: the event the request names is gone at Google), 'permission'
     * (any other 403), 'other', or null for a success. Pure.
     */
    public static function refusal(int $status, string $body): ?string
    {
        if ($status >= 200 && $status < 300) {
            return null;
        }
        if ($status === 429) {
            return 'busy';
        }
        if ($status === 404 || $status === 410) {
            return 'deleted';
        }
        if ($status !== 403) {
            return 'other';
        }
        $data = json_decode($body, true);
        $error = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
        $reasons = [];
        foreach ((array) ($error['errors'] ?? []) as $e) {
            if (is_array($e)) {
                $reasons[] = (string) ($e['reason'] ?? '');
                if ((string) ($e['domain'] ?? '') === 'usageLimits') {
                    return 'busy';
                }
            }
        }
        foreach ((array) ($error['details'] ?? []) as $d) {
            if (is_array($d)) {
                $reasons[] = (string) ($d['reason'] ?? '');
            }
        }
        if (array_intersect($reasons, self::BUSY_REASONS) !== [] || (string) ($error['status'] ?? '') === 'RESOURCE_EXHAUSTED'
            || array_intersect(array_map('strtoupper', $reasons), ['RATE_LIMIT_EXCEEDED', 'RESOURCE_EXHAUSTED']) !== []) {
            return 'busy';
        }
        return 'permission';
    }

    /** An access token for a write: a refusal of our own (reconnect needed, quarantined) as is, anything else the sign-in's failure. */
    private function access(array $account): string
    {
        try {
            return $this->auth->accessToken($account);
        } catch (HttpError $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw new HttpError('google_write_failed', 'Google refused the sign-in: ' . $e->getMessage(), 502);
        }
    }
}
