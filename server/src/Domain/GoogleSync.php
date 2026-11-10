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
        // Stands in for the network in the database suite, which replays
        // recorded Google answers: fn(string $url, array $headers):
        // array{status:int,body:string}, called where the real HttpClient's
        // get() would be. Null (the app) uses the real client.
        private readonly ?\Closure $transport = null,
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
            throw new \RuntimeException('Google account disconnected; connect it again in Settings, Connections and add this calendar there to re-attach it, or adopt it as local');
        }
        $account = $this->db->one('SELECT * FROM google_accounts WHERE id = ?', [(int) $calendar['google_account_id']]);
        if ($account === null) {
            throw new \RuntimeException('Google account disconnected; connect it again in Settings, Connections and add this calendar there to re-attach it, or adopt it as local');
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

        // A delta with no items: nothing changed at Google since the stored
        // token. The rows here are then the snapshot, and Feeds::sync of the
        // rows against themselves finds no difference and writes nothing, so
        // neither the snapshot nor the sync is made: the poll records the new
        // token and its success under the same checks a sync takes. One thing
        // that sync would have written is skipped with it: an edited day
        // stored before migration 063 whose own list is still unknown (null)
        // would have its list inferred by equality (OwnFields::afterSync).
        // It stays null until the next poll that brings a change or the next
        // whole read; every reader of the list treats null the same way
        // meanwhile, so nothing shows differently.
        $unchanged = $syncToken !== null && $changes === [];
        if ($unchanged) {
            $snapshot = [];
        } elseif ($syncToken === null) {
            $snapshot = self::applyChanges([], $changes, true, $eventLimit, $deadline);
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
            $snapshot = self::applyChanges($cached, $changes, false, $eventLimit, $deadline);
            unset($cached);
        }
        unset($changes);
        self::assertWithinDeadline($deadline);
        $whole = $syncToken === null;
        $emptyRefused = false;
        // The account first, as every change to its data takes it (Undo::write).
        $count = (new Undo($this->db))->write((int) $calendar['user_id'], function () use ($account, $calendar, $calendarId, $eventLimit, $learnedRole, $snapshot, $nextToken, $deadline, $unchanged, $whole, &$emptyRefused): int {
            // Adopt and every event writer serialize on this row. Re-read the
            // complete Google binding after the fetch so a response from the
            // old authority can never land in a newly-local, moving calendar.
            $current = CalendarMoveGuard::lockCalendar($this->db, $calendarId);
            if (!self::sameBinding($calendar, $current)) {
                throw HttpError::conflict('subscription_authorization_revoked', 'This calendar no longer uses that Google source; the fetched response was discarded.');
            }
            // An edit landed while Google's list was being fetched: what was
            // fetched predates it and would put the old version back until
            // the next poll. Drop this result and keep the sync position, so
            // the next poll fetches from before the edit and sees it.
            if (self::editedDuringFetch($calendar, $current)) {
                return 0;
            }
            // The last HTTP request may have been in flight when reset landed.
            // After the account and the calendar, as compromise reset and
            // event writers take them, the Google account's lock makes
            // reset/finalization atomic.
            $this->auth->assertUsable($account, true);
            if ($learnedRole !== null) {
                $this->db->update('calendars', ['google_access_role' => $learnedRole], 'id = ?', [$calendarId]);
                $current['google_access_role'] = $learnedRole;
            }
            if ($unchanged) {
                $this->db->update('calendars', ['google_sync_token' => $nextToken], 'id = ?', [$calendarId]);
                $this->feeds->pollSucceeded($current, $this->storedCount($calendarId), 0);
                return 0;
            }
            // An empty listing never deletes: a whole read (the first, or
            // after a 410) that lists nothing live for a calendar holding
            // rows is not applied. Rows and sync position stay as they are,
            // the calendar goes to Review, and the next poll reads it whole
            // again. Google names permission changes among a 410's causes,
            // and a listing that lost its access lists nothing; a calendar
            // emptied on purpose still empties through a delta poll.
            if ($whole && $snapshot === [] && $this->storedCount($calendarId) > 0) {
                $emptyRefused = true;
                return 0;
            }
            if ($whole) {
                ReviewQueue::closeGoogleEmpty($this->db, (int) $calendar['user_id'], $calendarId);
            }
            // Feeds::sync loads and indexes every stored row (including a
            // soft-deleted one), while the new snapshot is still resident.
            // Re-query under the account lock immediately before that load.
            $this->assertCachedSnapshotFits($calendarId, $eventLimit, $deadline);
            self::assertWithinDeadline($deadline);
            $snapshot = $this->pendingHeld($current, $snapshot, $deadline);
            $count = $this->feeds->sync($current, $snapshot, true, $deadline);
            self::assertWithinDeadline($deadline);
            $this->db->update('calendars', ['google_sync_token' => $nextToken], 'id = ?', [$calendarId]);
            $this->feeds->pollSucceeded($current, $this->storedCount($calendarId), $count);
            return $count;
        });
        if ($emptyRefused) {
            $message = 'Google listed no events for this calendar while it has events here; nothing was removed, and the next poll reads it again.';
            error_log('google sync: calendar ' . $calendarId . ': a whole read listed nothing; not applied');
            (new Undo($this->db))->write((int) $calendar['user_id'], fn() => ReviewQueue::holdGoogleEmpty($this->db, (int) $calendar['user_id'], $calendar));
            throw new \RuntimeException($message);
        }
        return $count;
    }

    /**
     * The poll's rule for a series with changes not yet at Google
     * (GoogleSeries::pendingNames: today a change a failure left half done,
     * later one queued while offline): the poll neither removes, rewrites
     * nor adds that series' rows here; it stores everything else. What
     * Google holds of the series is what Finish rebases over and Put back
     * checks against, and each reads it from Google when it runs and
     * stores it then (GoogleSeries::finish, putBack), so the poll leaves
     * it to them: storing it here would take rows from under the change
     * (an edited day its part here still names, a day holding data here
     * alone) and give recovery a series that is neither the change's
     * before nor its after. The series' entries in the snapshot (by uid,
     * or by a Google id the change chose) are replaced by its rows as they
     * stand here, which Feeds::sync then finds unchanged.
     *
     * @param list<array> $snapshot
     * @return list<array>
     */
    private function pendingHeld(array $calendar, array $snapshot, ?float $deadline): array
    {
        $pending = GoogleSeries::pendingNames($this->db, (int) $calendar['user_id'], (int) $calendar['id']);
        if ($pending === []) {
            return $snapshot;
        }
        $uids = [];
        foreach (array_keys($pending) as $name) {
            $uids[(string) $name] = true;
        }
        foreach ($snapshot as $ev) {
            if (isset($pending[(string) ($ev['google_event_id'] ?? '')])) {
                $uids[(string) $ev['uid']] = true;
            }
        }
        $kept = array_values(array_filter($snapshot, static fn(array $ev): bool => !isset($uids[(string) $ev['uid']])));
        [$in, $params] = Db::in(array_map('strval', array_keys($uids)));
        $rows = $this->db->all("SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL AND uid IN $in", array_merge([(int) $calendar['id']], $params));
        return array_merge($kept, self::rowsToParsed($rows, null, $deadline));
    }

    /**
     * A Google calendar's count for its day's feed stats (feed_stats.raw_count):
     * the live rows it holds after the poll, the same under a whole read, a
     * delta and an empty delta (a delta knows only what changed, so Google's
     * own record count is not to be had there). Masters and edited days alike,
     * as the rows stand here.
     */
    private function storedCount(int $calendarId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$calendarId]);
    }

    /**
     * Did an event on this calendar change after the poll began? Every event
     * write (here, or materialised from Google's answer to one) records a
     * change and bumps the calendar's synctoken; the poll compares the value
     * it started with to the one under the calendar lock.
     */
    public static function editedDuringFetch(array $atStart, ?array $current): bool
    {
        return $current !== null
            && isset($atStart['synctoken'], $current['synctoken'])
            && (int) $current['synctoken'] !== (int) $atStart['synctoken'];
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
                $headers = ['Authorization: Bearer ' . $access, 'Accept: application/json'];
                $r = $this->transport !== null ? ($this->transport)($url, $headers) : $http->get($url, $headers);
                // A held token Google no longer takes: once more with a fresh
                // one, and only once (a second 401 is reported below), within
                // the poll's deadline: a refresh past it is not asked for.
                if ($this->auth->tokenRejected($account, (int) $r['status'])) {
                    $access = $this->auth->accessToken($account, $budget->deadline());
                    $headers = ['Authorization: Bearer ' . $access, 'Accept: application/json'];
                    $r = $this->transport !== null ? ($this->transport)($url, $headers) : $http->get($url, $headers);
                }
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
        // Google's own id for the resource: what the API is addressed by when
        // writing back (an exception's id is the instance id form).
        $googleId = isset($item['id']) ? (string) $item['id'] : null;
        $cancelled = ($item['status'] ?? 'confirmed') === 'cancelled';
        // What the item is, before what it says. An instance of a series is
        // what Google marks as one (originalStartTime, recurringEventId), or
        // a cancelled item under an instance id: an exception record Google
        // dropped (a master change that reset the series, an end before it;
        // probe 3.1, 2.6) comes so, with the series' iCalUID and neither
        // mark. An instance is its series, its form (timed or all-day) and
        // its key, never its key alone (GoogleWriter::instanceId): the key
        // and the form are read from originalStartTime, by its shape, and
        // for an item without one from its id. An instance never speaks as
        // its series: one whose identity can't be read names nothing here.
        $marked = isset($item['originalStartTime']) || (string) ($item['recurringEventId'] ?? '') !== '';
        $original = is_array($item['originalStartTime'] ?? null) ? self::whenUtc($item['originalStartTime']) : null;
        $instance = null;
        $instanceAllDay = null;
        if ($original !== null) {
            $instance = $original;
            $instanceAllDay = isset($item['originalStartTime']['date']);
        } elseif ($marked || $cancelled) {
            $of = GoogleWriter::instanceOf((string) $googleId);
            if ($of !== null) {
                $instance = $of['key'];
                $instanceAllDay = $of['allDay'];
            } elseif ($marked) {
                error_log('google sync: an instance of series ' . $uid . ' whose day could not be read was left out');
                return null;
            }
        }
        if ($cancelled) {
            if ($instance === null) {
                return ['cancelled' => true, 'uid' => $uid, 'recurrence_instance_utc' => null];
            }
            // A cancelled record (it has its originalStartTime) is a skipped
            // day. A dropped one (none) says the record under that id is
            // gone: the series is not, and the day is no skip. Each names
            // the one resource with its id, whatever sits at the same key in
            // the other form (applyChanges).
            return ['cancelled' => true, 'uid' => $uid, 'recurrence_instance_utc' => $instance]
                + ($original === null ? ['dropped' => true] : [])
                + ['google_event_id' => $googleId, 'instance_all_day' => $instanceAllDay];
        }
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
        // `source`), else none: Google's page for the event (htmlLink) is
        // not the event's link, and stored as one it would travel back to
        // Google as the link and sit on every edited day as a value of its
        // own (#172).
        $link = isset($item['source']['url']) && preg_match('#^https?://#i', (string) $item['source']['url']) === 1
            ? (string) $item['source']['url']
            : null;
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
        ] + ($instance !== null
            // An instance's form: its series' when the record was made (the
            // shape of originalStartTime), never its own start's, which an
            // all-day day of a timed series has in the other shape.
            ? ['instance_all_day' => $instanceAllDay]
            : []) + (isset($item['etag']) && is_string($item['etag']) && $item['etag'] !== '' && strlen($item['etag']) <= 255
            // Google's version of the resource, sent back as If-Match on the
            // next write (GoogleWriter). Only when Google sends one, so an
            // answer without it leaves the stored one alone.
            ? ['google_etag' => $item['etag']]
            : []) + (isset($item['attendees'])
            // A guest list: an invitation to answer when someone else asked
            // this account (Rsvp::fromGoogle). Only when Google sends one, so
            // an event without guests keeps whatever block it had.
            ? ['invite_json' => ($invite = Rsvp::fromGoogle($item)) !== null ? json_encode($invite) : null]
            : []);
    }

    /**
     * Merge a list of Google items into a snapshot (both in the parsed
     * shape): the calendar's rows as Google holds them once the list is
     * read. A poll hands it a whole read (no snapshot) or a delta, a single
     * write its answer (GoogleWriter::apply), a series change its read-back
     * (GoogleSeries::store). Google gives a list no order, so the result is
     * a function of the set of items: the same for every order of the list.
     *
     * An item is related to a row or a skipped day by identity, never by
     * its key alone. An instance at Google is its series, its form (timed
     * or all-day) and its key (GoogleWriter::instanceId): a timed occurrence
     * at 00:00 UTC and the all-day one with the same key are two resources,
     * and a change of form leaves both in one list. The rules (decisions.md,
     * "A list from Google is merged by identity, whatever its order"):
     *
     * 1. One item per resource. A series (or an event that doesn't repeat)
     *    is named by its uid, an instance by its series, form and key;
     *    where a list names one twice, the later stands. That is the only
     *    use of order, and it is within one resource.
     * 2. The final master of a series is the list's when it has one, else
     *    the snapshot's, and its form is the series' form. A cancelled
     *    master removes the series: its rows go, and a record of it listed
     *    live beside it makes no row.
     * 3. A snapshot row's identity is its key under the form of the master
     *    it was stored under, and a stored skip's the same. A row with no
     *    master in the snapshot has the form its stored Google id names.
     * 4. A dropped item (a record Google no longer holds: cancelled, no
     *    originalStartTime) speaks only about the resource with its id: the
     *    row with its identity goes and the stored skip with its identity
     *    is no skip. It touches nothing of the other form at the same key.
     * 5. Only the final master's form counts. A snapshot row or stored skip
     *    of another form is nothing Google can hold under that master: the
     *    row leaves and the skip clears, whether or not the list names a
     *    dropped item for it (a change of form made elsewhere). A live or
     *    cancelled record of another form is left out, with a log line.
     * 6. Within the final form a key has at most one item: a live record is
     *    a row and no skip, a cancelled record a skip and no row, a dropped
     *    item neither, unless the master's own EXDATE lines in the same
     *    list name the day (a record dropped for an EXDATE line, 3.16).
     * 7. Google holds a series' skipped days in two places: the master's
     *    EXDATE lines, and a cancelled record per day (what a DELETE of an
     *    instance leaves; the master gains no line, probe 2.4). A list says
     *    everything about a series' skips only when it is whole for that
     *    series ($whole): then they are the master's lines and the
     *    cancelled records of its form, and a stored skip the list does
     *    not name was put back. Any other list adds and removes skips only
     *    by the items it carries: the stored skips of the series' form
     *    stand, with the master's lines and the cancelled records beside
     *    them, less each a live record or a dropped item of that form
     *    contradicts. A master the list doesn't carry keeps its stored
     *    skips the same way, whatever the list is.
     *
     * An instance item with no form (GoogleWriter::tombstone: the app's own
     * word about the day at a key, made under the series as stored) is of
     * the series' form. A series with no master, listed or stored, has no
     * form to judge by: each item acts on its own identity.
     *
     * $whole: the list is everything Google holds of each series whose
     * master it carries: a whole read of the calendar, a read-back of the
     * series, or the answer to a write that itself sent the series'
     * recurrence (its lines are then the series' skips as just written). A
     * sync-token delta is not, nor the answer to a write that sent no
     * recurrence (an answer to an invitation, a status, a title: Google's
     * answer is the series' own resource and says nothing of its cancelled
     * records), nor a tombstone. Every caller says which; there is no
     * default, since the wrong one clears every skipped day of a series by
     * saying nothing about them.
     *
     * @param list<array> $snapshot
     * @param list<array> $changes
     * @return list<array>
     */
    public static function applyChanges(
        array $snapshot,
        array $changes,
        bool $whole,
        ?int $maxEvents = null,
        ?float $deadline = null,
    ): array
    {
        $maxEvents ??= PHP_INT_MAX;
        if (count($snapshot) > $maxEvents) {
            throw new \RuntimeException(self::eventLimitMessage($maxEvents, 'cached Google calendar'));
        }
        Recurrence::assertExdateBatch($snapshot);
        // Rule 1 for a series' own item: the later one listed stands. Its
        // instances' items wait, in the order listed, until the series'
        // form is known (mergeSeries).
        $listed = [];
        $items = [];
        foreach ($changes as $index => $ev) {
            if (($index & 127) === 0) {
                self::assertWithinDeadline($deadline);
            }
            if ($ev['recurrence_instance_utc'] === null) {
                $listed[(string) $ev['uid']] = $ev;
            } else {
                $items[(string) $ev['uid']][] = $ev;
            }
        }
        // The snapshot by key, and what it holds of each series the list
        // names. Every other row passes through as it is.
        $byKey = [];
        $masters = [];
        $rows = [];
        foreach ($snapshot as $index => $ev) {
            if (($index & 127) === 0) {
                self::assertWithinDeadline($deadline);
            }
            $uid = (string) $ev['uid'];
            $byKey[self::key($ev)] = $ev;
            if (!isset($listed[$uid]) && !isset($items[$uid])) {
                continue;
            }
            if ($ev['recurrence_instance_utc'] === null) {
                $masters[$uid] = $ev;
            } else {
                $rows[$uid][(string) $ev['recurrence_instance_utc']] = $ev;
            }
        }
        // Each series the list names, in the order of its uid and not the
        // list's: a row that stays keeps its place, a new one is appended,
        // the master first and then its days by key.
        $named = array_map('strval', array_keys($listed + $items));
        sort($named, SORT_STRING);
        foreach ($named as $index => $uid) {
            if (($index & 127) === 0) {
                self::assertWithinDeadline($deadline);
            }
            $after = self::mergeSeries($uid, $masters[$uid] ?? null, $rows[$uid] ?? [], $listed[$uid] ?? null, $items[$uid] ?? [], $whole);
            foreach (array_merge(isset($masters[$uid]) ? [''] : [], array_keys($rows[$uid] ?? [])) as $key) {
                if (!isset($after[$key])) {
                    unset($byKey[$uid . '|' . $key]);
                }
            }
            foreach ($after as $key => $ev) {
                $byKey[$uid . '|' . $key] = $ev;
            }
        }
        // The limit is on what the calendar holds after the list, not on a
        // moment of walking it: a list that adds one event and removes
        // another is the same list in either order.
        $result = array_values($byKey);
        if (count($result) > $maxEvents) {
            throw new \RuntimeException(self::eventLimitMessage($maxEvents, 'Google calendar'));
        }
        Recurrence::assertExdateBatch($result);
        self::assertWithinDeadline($deadline);
        return $result;
    }

    /**
     * One series after a list, by the rules of applyChanges: its master
     * under '' and its days' rows under their keys, or nothing when the
     * list cancels it.
     *
     * @param array<string,array> $storedRows the snapshot's day rows of the series, by key
     * @param list<array> $items the list's instance items of the series, in the order listed
     * @return array<string,array>
     */
    private static function mergeSeries(string $uid, ?array $storedMaster, array $storedRows, ?array $listed, array $items, bool $whole): array
    {
        // Rule 2: the final master, and the series' form.
        if ($listed !== null && !empty($listed['cancelled'])) {
            return [];
        }
        $master = $listed ?? $storedMaster;
        $form = $master === null ? null : self::form((int) ($master['all_day'] ?? 0) === 1);
        $storedForm = $storedMaster === null ? null : self::form((int) ($storedMaster['all_day'] ?? 0) === 1);
        // Rule 3: each stored row under its identity, form then key.
        $held = [];
        $formAt = [];
        foreach ($storedRows as $key => $row) {
            $f = $storedForm ?? self::form(GoogleWriter::instanceOf((string) ($row['google_event_id'] ?? ''))['allDay'] ?? null);
            $held[$f][(string) $key] = $row;
            $formAt[(string) $key] = $f;
        }
        // Rule 1: one item per identity, the later standing. An item that
        // names no form is of the series' form; in a series with no master
        // it names the row stored at its key.
        $said = [];
        foreach ($items as $ev) {
            $key = (string) $ev['recurrence_instance_utc'];
            $f = isset($ev['instance_all_day']) ? self::form((bool) $ev['instance_all_day']) : ($form ?? $formAt[$key] ?? self::form(null));
            $said[$f][$key] = $ev;
        }
        // Rules 4 to 6: what each item says of the resource with its
        // identity. Only the series' form counts toward its days.
        $live = [];
        $cancelled = [];
        $dropped = [];
        $strays = 0;
        foreach ($said as $f => $ofForm) {
            $counts = $form === null || (string) $f === $form;
            foreach ($ofForm as $key => $ev) {
                $key = (string) $key;
                if (!empty($ev['dropped'])) {
                    unset($held[$f][$key]);
                    if ($counts) {
                        $dropped[$key] = true;
                    }
                } elseif (!$counts) {
                    $strays++;
                } elseif (!empty($ev['cancelled'])) {
                    unset($held[$f][$key]);
                    $cancelled[$key] = true;
                } else {
                    $held[$f][$key] = $ev;
                    $live[$key] = true;
                }
            }
        }
        if ($strays > 0) {
            error_log('google sync: series ' . $uid . ': ' . $strays . ' record(s) listed in a form other than the series\' own were left out');
        }
        // Rule 5: the rows of the series' form are its days. With no master
        // there is no form to judge by, and where two forms would give one
        // key the forms' order decides, never the list's.
        if ($form !== null) {
            $days = $held[$form] ?? [];
        } else {
            ksort($held, SORT_STRING);
            $days = [];
            foreach ($held as $ofForm) {
                foreach ($ofForm as $key => $row) {
                    $days[(string) $key] = $row;
                }
            }
        }
        ksort($days, SORT_STRING);
        $out = [];
        if ($master !== null) {
            // Rule 7. The skips are a set: the master's own lines, the
            // stored skips kept and the cancelled records, less the days a
            // live record holds.
            $lines = $listed !== null ? (array) ($listed['exdates'] ?? []) : [];
            $kept = $listed === null || (!$whole && $storedForm === $form) ? (array) ($storedMaster['exdates'] ?? []) : [];
            $skips = [];
            foreach ($lines as $d) {
                $skips[(string) $d] = true;
            }
            foreach ($kept as $d) {
                if (!isset($dropped[(string) $d])) {
                    $skips[(string) $d] = true;
                }
            }
            foreach (array_keys($cancelled) as $d) {
                $skips[(string) $d] = true;
            }
            foreach (array_keys($live) as $d) {
                unset($skips[(string) $d]);
            }
            // Written in key order, whatever order the list named them in.
            // A list that changes no skipped day leaves the stored list as
            // it is: Feeds::sameValue compares it in order, and a merge
            // that skips and brings back nothing is no change to the master.
            ksort($skips, SORT_STRING);
            $dates = array_map('strval', array_keys($skips));
            $was = $storedMaster === null ? [] : array_map('strval', (array) ($storedMaster['exdates'] ?? []));
            $wasSet = array_values(array_unique($was));
            sort($wasSet, SORT_STRING);
            $master['exdates'] = Recurrence::validateExdates($wasSet === $dates ? $was : $dates);
            $out[''] = $master;
        }
        foreach ($days as $key => $row) {
            $out[(string) $key] = $row;
        }
        return $out;
    }

    /** A form as a word: 'date' (all-day), 'time' (timed), '' (none known). */
    private static function form(?bool $allDay): string
    {
        return $allDay === null ? '' : ($allDay ? 'date' : 'time');
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
            ] + (isset($r['google_etag']) && $r['google_etag'] !== ''
                ? ['google_etag' => (string) $r['google_etag']]
                : []) + (isset($r['invite_json']) && $r['invite_json'] !== null
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
                    + COALESCE(LENGTH(google_event_id), 0) + COALESCE(LENGTH(google_etag), 0) + COALESCE(LENGTH(icon), 0)
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
