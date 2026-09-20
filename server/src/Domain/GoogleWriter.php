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
 */
final class GoogleWriter
{
    private const EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/%s/events';
    /** Event columns Google owns; everything else on a row is local metadata. */
    public const GOOGLE_COLUMNS = ['title', 'description', 'location', 'start_utc', 'end_utc', 'all_day', 'tzid', 'rrule', 'exdates_json', 'status'];

    public function __construct(
        private readonly Db $db,
        private readonly GoogleAuth $auth,
        private readonly Feeds $feeds,
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
        if ($allDay) {
            $body['start'] = ['date' => $start->format('Y-m-d')];
            $body['end'] = ['date' => $end->format('Y-m-d')];
        } else {
            $body['start'] = ['dateTime' => $start->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz];
            $body['end'] = ['dateTime' => $end->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz];
        }
        $exdates = [];
        if (!empty($row['exdates_json'])) {
            $decoded = is_array($row['exdates_json']) ? $row['exdates_json'] : json_decode((string) $row['exdates_json'], true);
            $exdates = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
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
     * The id Google gives one occurrence of a series: the series id plus the
     * occurrence's original start (UTC basic form for timed, date for
     * all-day). Patching or deleting that id is how "this occurrence only"
     * is said to the API.
     */
    public static function instanceId(string $masterGoogleId, string $instanceUtc, bool $allDay): string
    {
        $dt = Time::fromDb($instanceUtc);
        return $masterGoogleId . '_' . ($allDay ? $dt->format('Ymd') : $dt->format('Ymd\THis\Z'));
    }

    // ---- Calls ----------------------------------------------------------------

    public function insert(array $calendar, array $row): array
    {
        return $this->call($calendar, 'POST', '', self::body($row));
    }

    public function patch(array $calendar, string $googleId, array $body): array
    {
        return $this->call($calendar, 'PATCH', '/' . rawurlencode($googleId), $body);
    }

    public function delete(array $calendar, string $googleId): void
    {
        $this->call($calendar, 'DELETE', '/' . rawurlencode($googleId), null);
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

    private function call(array $calendar, string $method, string $path, ?array $payload): array
    {
        $account = $this->db->one('SELECT * FROM google_accounts WHERE id = ?', [(int) $calendar['google_account_id']]);
        if ($account === null) {
            throw new HttpError('google_disconnected', 'The Google account this calendar came from is disconnected; reconnect it under Settings, Connections.', 409);
        }
        try {
            $access = $this->auth->accessToken($account);
        } catch (\RuntimeException $e) {
            throw new HttpError('google_write_failed', 'Google refused the sign-in: ' . $e->getMessage(), 502);
        }
        $url = sprintf(self::EVENTS_URL, rawurlencode((string) $calendar['google_calendar_id'])) . $path . '?sendUpdates=none';
        $http = new HttpClient(requestBudget: 10, userAgent: 'Better-Cal/0.1 (+google-connector)');
        try {
            $r = $http->json($method, $url, $payload, ['Authorization: Bearer ' . $access]);
        } catch (\RuntimeException $e) {
            throw new HttpError('google_write_failed', 'Google could not be reached: ' . $e->getMessage(), 502);
        }
        if ($r['status'] === 204 || ($method === 'DELETE' && $r['status'] === 410)) {
            return []; // deleted, or already gone
        }
        $data = json_decode($r['body'], true);
        if ($r['status'] < 200 || $r['status'] >= 300 || !is_array($data)) {
            $why = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            $status = $r['status'] === 403 ? 403 : 502;
            $message = $r['status'] === 403
                ? 'Google says this account may not edit that calendar' . ($why !== '' ? " ($why)" : '')
                : 'Google rejected the change: HTTP ' . $r['status'] . ($why !== '' ? " ($why)" : '');
            throw new HttpError('google_write_failed', $message, $status);
        }
        return $data;
    }
}
