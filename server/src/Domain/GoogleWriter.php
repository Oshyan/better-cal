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
    public const CONFLICT_MESSAGE = 'This event changed in Google Calendar since you last saw it. Refresh and try again.';
    /** The conflict's reason alone, for a change that had already sent part of itself. */
    public const CONFLICT_SAID = 'This event changed in Google Calendar since this change read it.';
    public const BUSY_MESSAGE = 'Google Calendar is busy and refused the change for now; nothing was changed. Try again in a minute.';
    public const DELETED_MESSAGE = 'This event was deleted in Google Calendar; nothing was changed. Refresh to see the calendar as it is there.';
    public const REFUSED_MESSAGE = 'Google Calendar refused this change; nothing was changed. If you just got edit access back, try again in a moment.';

    /** The note on the Activity line of a removal whose target Google had already removed (gone). */
    public const ALREADY_GONE = 'Google Calendar had already removed it';
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
    public const GOOGLE_COLUMNS = ['title', 'description', 'location', 'url', 'start_utc', 'end_utc', 'all_day', 'tzid', 'rrule', 'exdates_json', 'status', 'show_as'];

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
        return self::bound($calendar) && self::writes($calendar['google_access_role'] ?? null);
    }

    /**
     * Whether a new event on $calendar takes up time when the person did not
     * say (#303): the one rule the create path and the editor (served on
     * each calendar as `newShowAs`) both ask. On a Google calendar a new
     * all-day event is free, as Google Calendar's own page makes it (its API
     * would make it busy, `opaque`); every other new event is busy, which on
     * Google is its API's default, so nothing is sent for it. Pure.
     */
    public static function newShowAs(array $calendar, bool $allDay): string
    {
        return $allDay && self::bound($calendar) ? 'free' : 'busy';
    }

    /** Is this a calendar read through a connected Google account, whatever its access? Pure. */
    public static function bound(array $calendar): bool
    {
        return ($calendar['provider'] ?? 'ics') === 'google'
            && !empty($calendar['google_calendar_id'])
            && ($calendar['google_account_id'] ?? null) !== null;
    }

    // ---- Access: what Google last said this account may do here -----------------
    // The model: docs/design/google-access-detect-and-inform.md.

    /** Google's access roles (the accessRole of an events.list answer), lowest first; 'none': not visible. */
    public const ROLES = ['none', 'freeBusyReader', 'reader', 'writer', 'owner'];

    /** Does this level let the account write to the calendar? Pure. */
    public static function writes(?string $role): bool
    {
        return in_array((string) $role, ['writer', 'owner'], true);
    }

    /** The tier a level is in for the person: 'edit', 'view' (view only, or busy times only) or 'none' (not shown). Pure. */
    public static function tier(string $role): string
    {
        return $role === 'none' ? 'none' : (self::writes($role) ? 'edit' : ($role === 'freeBusyReader' ? 'busy' : 'view'));
    }

    /**
     * Does a listing at this level show what Google holds, so it may be
     * applied to the events stored here? Reader and above do; busy times
     * only does not (Google leaves out the title of a read, the access
     * probe's stage freebusy rows 6a, 6c, 6g, and the description and
     * private properties of a list's items, row 4), and is never applied:
     * what is held here stays as it was (docs/design/google-access-detect-and-inform.md,
     * section 1). A level not stated (null) is not known to be lower, as
     * before. The one rule every place that applies a list answer asks:
     * the poll (GoogleSync) and a change's read-backs (GoogleSeries::store);
     * apply stores only a write's own answer, which Google gives only at
     * writer or owner (the access probe, rows 5, 6d to 6h). Pure.
     */
    public static function shows(?string $role): bool
    {
        return $role === null || in_array($role, ['reader', 'writer', 'owner'], true);
    }

    /** Whether the last list answer for the calendar through this writer shows what Google holds (shows). */
    public function listShows(array $calendar): bool
    {
        return self::shows($this->listed[self::keyOf($calendar)][0] ?? null);
    }

    /**
     * What an answer to an events.list request on the calendar says of this
     * account's level: its top-level accessRole (on every list answer, plain
     * and delta, in every state the access probe of 2026-11-13 met: rows 3,
     * 4, 4d), 'none' for a 404 (sharing removed: every list answers 404,
     * stage unshared) or a 410 to a list sent without a sync token, null when
     * it says neither (busy, a failure, no role, a role Google doesn't
     * document). Pure.
     */
    public static function roleOfList(int $status, string $body): ?string
    {
        // A 410 to a list sent with no sync token has no live row (a deleted calendar answered 404 like an unshared
        // one, 2026-11-14, stage deleted): nothing depends on it; it says no level.
        if ($status === 404) {
            return 'none';
        }
        if ($status < 200 || $status >= 300) {
            return null;
        }
        $role = json_decode($body, true)['accessRole'] ?? null;
        return is_string($role) && in_array($role, self::ROLES, true) ? $role : null;
    }

    /** The calendar's name in a sentence, quoted. */
    private static function named(array $calendar): string
    {
        $name = trim((string) ($calendar['name'] ?? ''));
        return "'" . ($name !== '' ? $name : 'This calendar') . "'";
    }

    /**
     * Why a change or an undo can't be made on a calendar Google says this
     * account may not write to ($role, below writer), and what restores it:
     * the one sentence every refusal of the rule gives. Pure.
     */
    public static function readOnlyWords(array $calendar, string $role, bool $undo = false): string
    {
        $name = self::named($calendar);
        // Derived from the level stored before this refusal ($calendar's): "no longer" and "restore" are true only
        // of a calendar that could be changed here until now; one that couldn't is said as what it is.
        if (self::writes($calendar['google_access_role'] ?? null)) {
            $why = $role === 'none'
                ? 'Google Calendar no longer shows it to this account. If it is shared with you again, changes work here again.'
                : 'your permissions for it in Google Calendar no longer allow changes. If you have access, restore full editing there.';
            return ($undo ? "This can't be undone while $name is read-only: " : "$name is read-only: ") . $why;
        }
        $state = match (self::tier($role)) {
            'none' => "Google Calendar doesn't show $name to this account",
            'busy' => "Google Calendar shows this account only busy times for $name",
            default => "$name is view only in Google Calendar",
        };
        return $undo ? "This can't be undone: $state." : "$state, so it can't be changed here.";
    }

    /**
     * The refusal of a change (or an undo) on a calendar Google says this
     * account may not write to: nothing was sent. Its own codes, never a
     * Google status, so nothing reads it as the event deleted (gone).
     */
    public static function readOnly(array $calendar, string $role, bool $undo = false): HttpError
    {
        return new HttpError($role === 'none' ? 'google_not_shown' : 'google_read_only', self::readOnlyWords($calendar, $role, $undo), 403,
            ['access' => $role, 'was' => $calendar['google_access_role'] ?? null, 'calendarId' => isset($calendar['id']) ? (int) $calendar['id'] : null]);
    }

    /**
     * The access rule refusing a change the person attempted: readOnly, and
     * the calendar's access notice, if dismissed, open again once the
     * request is over (ReviewQueue::reopenState: a refusal because of the
     * state a notice reports brings it back).
     */
    private function refused(array $calendar, string $role, bool $undo = false): HttpError
    {
        if (isset($calendar['id'], $calendar['user_id'])) {
            ReviewQueue::reopenAfter($this->db, (int) $calendar['user_id'], ReviewQueue::accessKey((int) $calendar['id']));
        }
        return self::readOnly($calendar, $role, $undo);
    }

    /**
     * The refusal when Google's word on the level could not be read: before a
     * change's first write (admit), or after a write Google refused (call).
     * Nothing was changed by this request; `refused` says Google took
     * nothing, so a change under way does not count it as a step sent
     * (GoogleSeries::refused). Whether anything was changed is known from
     * what the change sent, not from this one request: `said` is the reason
     * without "nothing was changed", for a change that had already sent part
     * of itself and stops here (GoogleSeries::run).
     */
    public static function unconfirmed(): HttpError
    {
        return new HttpError('google_write_failed', 'Google Calendar did not say what this account may do with this calendar, so nothing was changed. Try again in a minute.', 502,
            ['refused' => true, 'said' => 'Google Calendar did not say what this account may do with this calendar.']);
    }

    /** Whether a refusal is the access rule's (readOnly). Pure. */
    public static function isReadOnly(HttpError $e): bool
    {
        return in_array($e->errorCode, ['google_read_only', 'google_not_shown'], true);
    }

    /** Requests sent through this writer so far: what tells a change's own list from an earlier one. */
    private int $sent = 0;
    /** The level each calendar's last events.list answer through this writer gave, and the request it answered. @var array<string,array{0:string,1:int}> */
    private array $listed = [];
    /** Levels queued to be stored once the transaction is over, by calendar (one store per level). @var array<string,string> */
    private array $queued = [];

    private static function keyOf(array $calendar): string
    {
        return (string) ($calendar['google_account_id'] ?? '') . '|' . (string) ($calendar['google_calendar_id'] ?? '');
    }

    /** Where this writer's requests stand: a read begun after it is this change's own (admit). */
    public function mark(): int
    {
        return $this->sent;
    }

    /** The level the last list answer for the calendar gave, if that list was sent after $since. */
    private function listedSince(array $calendar, int $since): ?string
    {
        $l = $this->listed[self::keyOf($calendar)] ?? null;
        return $l !== null && $l[1] > $since ? $l[0] : null;
    }

    /**
     * The decision of the access rule, the one function every change's
     * first read goes through before anything is read from it or sent
     * (GoogleSeries::condition, readNow, run, and Finish's and Put back's
     * reads): a change is sent only when the level a list of this change
     * gave ($since: the writer's mark before its first read) is writer or
     * owner; otherwise it is refused, nothing sent. When the change's first
     * read was not a list (nothing is stored here to list it by), one small
     * list is asked first. A calendar not yet a calendar here (moving one to
     * Google fills a calendar that has no row yet, GoogleMove) is out of the
     * rule.
     */
    public function admit(array $calendar, ?\Closure $beforeSend, int $since): void
    {
        if (!isset($calendar['id'])) {
            return;
        }
        $role = $this->listedSince($calendar, $since) ?? $this->accessNow($calendar, $beforeSend);
        if ($role === null) {
            throw self::unconfirmed();
        }
        if (!self::writes($role)) {
            throw $this->refused($calendar, $role);
        }
    }

    /**
     * Google's word now on this account's level for the calendar: one small
     * events.list request (one item), its level stored (learn) and
     * returned, 'none' when the list answers 404, null when Google could not
     * be read (unreachable, a 5xx, still busy, no level).
     */
    public function accessNow(array $calendar, ?\Closure $beforeSend = null): ?string
    {
        $since = $this->sent;
        try {
            $this->call($calendar, 'GET', '', null, ['maxResults' => '1'], $beforeSend);
        } catch (HttpError $e) {
            if (self::isReadOnly($e)) {
                return 'none';
            }
            if (in_array($e->errorCode, ['google_write_failed', 'google_busy'], true)) {
                return null;
            }
            throw $e;
        }
        return $this->listedSince($calendar, $since);
    }

    /**
     * A change or an undo attempted on a Google calendar stored below
     * writer: Google is asked first (accessNow), and the calendar is
     * returned with the level it gives when that is writer, so the change
     * goes on as on any writable calendar; otherwise it is refused with the
     * rule's sentence. A calendar stored writer is returned as it is (the
     * change's own first read decides).
     */
    public function recheck(array $calendar, ?\Closure $beforeSend = null, bool $undo = false): array
    {
        if (self::writable($calendar) || !self::bound($calendar)) {
            return $calendar;
        }
        $role = $this->accessNow($calendar, $beforeSend);
        if ($role === null) {
            throw new HttpError('google_write_failed', 'Google Calendar could not be asked whether this calendar may be changed now, so nothing was changed. Try again in a minute.', 502);
        }
        if (!self::writes($role)) {
            throw $this->refused($calendar, $role, $undo);
        }
        return ['google_access_role' => $role] + $calendar;
    }

    /** What a list answer said of the level: kept for admit, and stored once the transaction is over. */
    private function listAnswered(array $calendar, array $account, string $role): void
    {
        $key = self::keyOf($calendar);
        $this->listed[$key] = [$role, $this->sent];
        if (!isset($calendar['id'], $calendar['user_id']) || ($this->queued[$key] ?? null) === $role) {
            return;
        }
        $this->queued[$key] = $role;
        $this->db->whenTxEnds(function () use ($calendar, $account, $role, $key): void {
            unset($this->queued[$key]);
            $this->learn($calendar, $account, $role);
        });
    }

    /**
     * Store a level a change's own list gave: the newest word, stored once
     * that change's transaction is over, whatever it did (a refusal rolls it
     * back, and what Google said still holds), in a write of its own, while
     * the calendar row is still this Google calendar of this account and the
     * account is usable under its lock (nothing is stored across the
     * compromise reset).
     */
    private function learn(array $calendar, array $account, string $role): void
    {
        $undo = new Undo($this->db);
        $undo->write((int) $calendar['user_id'], function () use ($undo, $calendar, $account, $role): void {
            $now = CalendarMoveGuard::lockCalendar($this->db, (int) $calendar['id']);
            if (!GoogleSync::sameBinding($calendar, $now)) {
                return;
            }
            try {
                $this->auth->assertUsable($account, true);
            } catch (HttpError) {
                return;
            }
            self::settleAccess($this->db, $undo, $now, $role);
        });
    }

    /**
     * Store a level on a calendar row the caller holds locked, with the
     * account checked: when the calendar moves between tiers (editable, view
     * only, not shown) Activity gets one line saying so and Review gets the
     * calendar's one item for the tier it is in now (none while editable).
     * A level learned for the first time (none stored: a calendar added
     * before levels were kept, or one the compromise reset paused) says
     * nothing. The one place a level is stored after a calendar is added;
     * the poll calls it inside its own write.
     */
    public static function settleAccess(Db $db, Undo $undo, array $now, string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            return;
        }
        $was = $now['google_access_role'] !== null ? (string) $now['google_access_role'] : null;
        if ($was === $role) {
            return;
        }
        $db->update('calendars', ['google_access_role' => $role], 'id = ?', [(int) $now['id']]);
        // A level learned from unknown (none stored: added before levels were kept, or paused by the compromise reset)
        // is a move like any other: its line and its item, in words that claim nothing about before.
        if ($was !== null && self::tier($was) === self::tier($role)) {
            return;
        }
        $name = self::named($now);
        $words = self::accessWords($now, $role, $was);
        $undo->record((int) $now['user_id'], 'calendar', (int) $now['id'], 'update', null, null, match (self::tier($role)) {
            'edit' => $was === null ? "Your permissions for $name in Google Calendar allow changes. Changes and undo work here." : "Your permissions for $name in Google Calendar allow changes now. Changes and undo work here.",
            default => $was !== null && self::tier($was) === 'none' ? "Google Calendar shows $name to this account again. " . $words : $words,
        });
        // A level learned when none was stored closes any stale item, but opens one only below what the calendar was
        // last known to allow; nothing is known after the compromise reset (it clears the level), so then no item opens
        // and the Activity line and the settings line carry it.
        if ($was === null) {
            ReviewQueue::closeGoogleAccess($db, (int) $now['user_id'], (int) $now['id']);
            return;
        }
        ReviewQueue::holdGoogleAccess($db, (int) $now['user_id'], ['google_access_role' => $role] + $now, self::tier($role), $words);
    }

    /** What a calendar below writer is, in the person's words: the Activity line and the Review item say it. Pure. */
    public static function accessWords(array $calendar, string $role, ?string $was = null): string
    {
        $name = self::named($calendar);
        $was = func_num_args() >= 3 ? $was : ($calendar['google_access_role'] ?? null);
        // Derived from the level before ($was): "no longer", "now" and "restore" only where something was known before.
        $editable = self::writes($was);
        return match (self::tier($role)) {
            'none' => $was !== null && $was !== 'none'
                ? "Google Calendar no longer shows $name to this account: its owner stopped sharing it, or deleted it. Its events here are kept, read-only, and no longer update. If it is shared with you again, it updates again; otherwise remove it, or keep it as a calendar of your own."
                : "Google Calendar doesn't show $name to this account: its owner stopped sharing it, or deleted it. Its events here are kept, read-only, and don't update. If it is shared with you, it updates; otherwise remove it, or keep it as a calendar of your own.",
            'busy' => $editable
                ? "Google Calendar now shows this account only busy times for $name, so its events here stay as they were and don't update, and changes and undo are unavailable. If you have access, restore full details and editing there."
                : "Google Calendar shows this account only busy times for $name, so its events here stay as they were and don't update, and it can't be changed here.",
            default => $editable
                ? "Your permissions for $name in Google Calendar are now read-only. If you have access, restore full editing there. Until then $name works read-only here: changes, and undoing past changes, are unavailable."
                : "$name is view only in Google Calendar, so it can't be changed here.",
        };
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
        // An event that takes up no time is `transparent` ("Show as: Free").
        // A busy one sends nothing, which is Google's default (`opaque`) for
        // timed and all-day events alike: an insert follows Google's own
        // default, and a patch from free sends null, which clears it.
        if ((string) $row['show_as'] === 'free') {
            $body['transparency'] = 'transparent';
        }
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
     * The body keys body() sends only when the row has a value for them (a
     * link as `source`, free as `transparency`), so that a new event follows
     * Google's own default. Over a resource Google already holds, a key left
     * out of a PATCH is left as Google has it, so a body that says a row
     * whole names each of these as null when the row has none (whole).
     */
    public const SET_ONLY = ['source', 'transparency'];

    /**
     * A body that says its row whole over a resource Google already holds:
     * each SET_ONLY key the row has no value for, as null, which clears it.
     * The one rule for every write that sends an event or a day whole
     * rather than what changed (patchOf): a day of a series sent again, a
     * master written over, a moved calendar's edited days. Pure.
     */
    public static function whole(array $body): array
    {
        foreach (self::SET_ONLY as $key) {
            $body[$key] ??= null;
        }
        return $body;
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
        'showAs' => ['transparency'],
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
        // $before is the resource as Google holds it: its start says the form.
        return is_array($before['start'] ?? null) ? self::overForm($body, isset($before['start']['date'])) : $body;
    }

    /**
     * A body sent over a resource Google already holds, its start and end
     * said against the form the resource holds them in ($heldAllDay: its
     * start is a date). Google merges a PATCH's start into the one it holds,
     * so a start naming only the new form's fields leaves both forms there
     * and is refused, 400 "Invalid start time." (the probe of 2026-11-10,
     * 3.18 to 3.30). A start or end in the other form therefore names the
     * held form's fields as null, which removes them: `dateTime` and
     * `timeZone` going all-day, `date` going timed, as Google took every time
     * (3.19 to 3.31). One in the held form, or none, goes as it is: a null
     * there is not proven. The one rule every writer of a start or end over
     * an existing resource follows; an insert sends a whole new resource and
     * never comes here. Pure.
     */
    public static function overForm(array $body, bool $heldAllDay): array
    {
        foreach (['start', 'end'] as $key) {
            if (!is_array($body[$key] ?? null)) {
                continue;
            }
            $sentAllDay = isset($body[$key]['date']);
            if ($sentAllDay !== $heldAllDay) {
                $body[$key] += $sentAllDay ? ['dateTime' => null, 'timeZone' => null] : ['date' => null];
            }
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
    public function patch(array $calendar, string $googleId, array $body, ?\Closure $beforeSend = null, ?array $read = null, bool $refresh = true, ?array $items = null): array
    {
        $series = $refresh ? $this->seriesOf($calendar, $googleId) : null;
        $resp = $this->call($calendar, 'PATCH', '/' . rawurlencode($googleId), $body, beforeSend: $beforeSend, ifMatch: $this->condition($calendar, $googleId, $beforeSend, $read, $items));
        if ($refresh && ($series !== null || !empty($resp['recurringEventId']) || !empty($resp['recurrence']))) {
            $this->refreshAfterWrite($calendar, (string) ($resp['iCalUID'] ?? $series ?? ''));
        }
        return $resp;
    }

    public function delete(array $calendar, string $googleId, ?\Closure $beforeSend = null, ?array $read = null, bool $refresh = true, ?array $items = null): void
    {
        $series = $refresh ? $this->seriesOf($calendar, $googleId) : null;
        $this->call($calendar, 'DELETE', '/' . rawurlencode($googleId), null, beforeSend: $beforeSend, ifMatch: $this->condition($calendar, $googleId, $beforeSend, $read, $items));
        $this->refreshAfterWrite($calendar, $series);
    }

    /**
     * The event $googleId as Google holds it now, for a caller that builds
     * its write from it (an answer to an invitation, an undo), read as every
     * write's first read is (GoogleSeries::readNow: the list by the
     * iCalendar UID stored here, whose level decides), and that list. Both
     * go to patch or delete, so the write's condition reads nothing again.
     *
     * @return array{0:array, 1:list<array>}
     */
    public function readNow(array $calendar, string $googleId, ?\Closure $beforeSend = null, ?string $uid = null): array
    {
        return (new GoogleSeries($this->db, $this, $this->feeds))->readNow($calendar, $googleId, $beforeSend, $uid);
    }

    /**
     * The conflict choice's read (docs/design/google-conflict-choice.md, the
     * page "1.5.7, Slice 1", the step): an event in no series read as every
     * write's first read is (readNow, decided by the access rule there),
     * compared field by field with $base and $wanted (GoogleConflict::threeWay,
     * the one comparison), and stored as a poll stores what it finds, with
     * its Activity entry, so the calendar shows what Google holds whatever
     * the caller does next and the write's condition finds the rows equal to
     * the read. Gone at Google: refused as the write would be (deleted). Made
     * repeating there: the 1.5.6 refusal, nothing stored (the rule is no
     * field of the comparison).
     *
     * @param array<string,mixed> $base GoogleConflict::mapOf of what the person was shown
     * @param array<string,mixed> $wanted the fields the change sets (GoogleConflict::DELETE each, for a delete)
     *
     * $series (the page "Slice 2", (c) and (e)): for a repeating event, its
     * Google id, its iCalendar UID and `key`, the day compared (null: the
     * repeating event's own fields, an answer for all events). "Now" is then
     * that day in the read (its record, else the series' values there,
     * GoogleSeries::dayNow), and the whole list is stored as a read-back of
     * the series is (GoogleSeries::store), journaled as a poll's. `outcomes`
     * is null when the read has no such day; the caller decides from the
     * stored event whether it still is one.
     *
     * @param array{id:string, uid:string, key:?string}|null $series
     * @return array{outcomes:?array<string,string>, read:array, items:list<array>}
     */
    public function readAndStore(array $calendar, string $googleId, array $base, array $wanted, ?\Closure $beforeSend = null, ?array $series = null): array
    {
        [$read, $items] = $this->readNow($calendar, $googleId, $beforeSend);
        if ($series !== null) {
            $master = GoogleSeries::held($items, $series['id'])['master'];
            if ($master === null) {
                throw self::deleted();
            }
            $now = $series['key'] === null ? $master : GoogleSeries::dayNow($items, $series['id'], $series['key']);
            $outcomes = $now === null ? null : GoogleConflict::threeWay($base, GoogleConflict::mapOf($now), $wanted);
            (new GoogleSeries($this->db, $this, $this->feeds))->store($calendar, [$series['uid'] => $items], [], null, true);
            return ['outcomes' => $outcomes, 'read' => $read, 'items' => $items];
        }
        $parsed = GoogleSync::toParsedList([$read])[0] ?? null;
        if (!is_array($parsed) || !empty($parsed['cancelled'])) {
            throw self::deleted();
        }
        if (!empty($parsed['rrule'])) {
            throw new HttpError('google_conflict', self::CONFLICT_MESSAGE, 409);
        }
        $outcomes = GoogleConflict::threeWay($base, GoogleConflict::mapOf($parsed), $wanted);
        $this->apply($calendar, [$read], false, [], true);
        return ['outcomes' => $outcomes, 'read' => $read, 'items' => $items];
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
     * The one write that goes with no If-Match: a day's step inside a change
     * to all events, after the change's conditional write to the series
     * (GoogleSeries::run). While our own change is sent the last write wins
     * (decisions.md, "Inside a change to all events a day is written with no
     * condition"); Google takes such a write in every state a run meets (probe
     * 2026-11-13, 3.66). Every other PATCH and DELETE stays conditional.
     */
    public function sendDayStep(array $calendar, string $path, array $body, ?\Closure $beforeSend = null): array
    {
        return $this->call($calendar, 'PATCH', $path, $body, [], $beforeSend, null, true);
    }

    /**
     * Every record Google holds under one iCalendar UID (a series' master and
     * its records, cancelled ones included, or a single event), by events.list
     * with singleEvents=false and showDeleted=true, at most $pages pages. Each
     * answer's level is kept and stored (call).
     *
     * @return list<array>
     */
    public function listByUid(array $calendar, string $uid, ?\Closure $beforeSend = null, int $pages = 4): array
    {
        $items = [];
        $pageToken = null;
        $n = 0;
        do {
            $query = ['iCalUID' => $uid, 'singleEvents' => 'false', 'showDeleted' => 'true', 'maxResults' => '2500'];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $data = $this->call($calendar, 'GET', '', null, $query, $beforeSend);
            foreach ((array) ($data['items'] ?? []) as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $pageToken = isset($data['nextPageToken']) && is_string($data['nextPageToken']) && $data['nextPageToken'] !== '' ? $data['nextPageToken'] : null;
        } while ($pageToken !== null && ++$n < $pages);
        return $items;
    }

    /**
     * The If-Match for a write to $googleId: the etag of a read (made now,
     * or the caller's $read), once what was read is the series (or event)
     * as stored here (GoogleSeries::condition, the one rule every write
     * follows; no caller is exempt).
     */
    private function condition(array $calendar, string $googleId, ?\Closure $beforeSend, ?array $read = null, ?array $items = null): string
    {
        return (new GoogleSeries($this->db, $this, $this->feeds))->condition($calendar, $googleId, $beforeSend, $items, $read)['etag'];
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
     * $sentRecurrence: whether the write these answer sent the series'
     * recurrence (its rule and every skip as an EXDATE line). Only then is
     * the answer everything about the series' skipped days. An answer to a
     * write that sent none (an answer to an invitation, a status, a field,
     * one day) is the series' or the day's own resource and says nothing of
     * the days Google holds as cancelled records, so the skips stored here
     * stand (GoogleSync::applyChanges, rule 7). The writer knows what it
     * sent, and says so at every call.
     *
     * $journal: a read stored before a change (the conflict choice,
     * Events::googleChoice) is stored as a poll stores what it finds, with
     * its Activity entry and touches, so an older entry's undo sees it; a
     * write's own answer is not (the caller records what the person did).
     *
     * @param list<array> $resources Google event resources
     * @param list<array{cancelled:true,uid:string,recurrence_instance_utc:?string}> $tombstones
     */
    public function apply(array $calendar, array $resources, bool $sentRecurrence, array $tombstones = [], bool $journal = false): void
    {
        $changes = array_merge(GoogleSync::toParsedList($resources), $tombstones);
        $snapshot = GoogleSync::applyChanges(GoogleSync::rowsToParsed($this->db->all(
            'SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL',
            [(int) $calendar['id']]
        )), $changes, $sentRecurrence);
        // No feed roll-up in Activity for a write's answer: the caller records what the person did.
        $this->feeds->sync($calendar, $snapshot, journal: $journal);
    }

    /**
     * Whether a body sent to Google says the series' recurrence: the one
     * reading of "the write sent the recurrence" every caller of apply
     * gives. An insert's body always does (body), a PATCH's only when the
     * rule or the skips changed (patchOf), an instance's never. Pure.
     */
    public static function sendsRecurrence(array $body): bool
    {
        return array_key_exists('recurrence', $body);
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

    private function call(array $calendar, string $method, string $path, ?array $payload, array $query = [], ?\Closure $beforeSend = null, ?string $ifMatch = null, bool $dayStep = false): array
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
        // and none goes without the etag of a read (condition), but for the
        // one exemption, a run's day step (sendDayStep).
        $headers = [];
        if (($method === 'PATCH' || $method === 'DELETE') && !$dayStep) {
            if ($ifMatch === null || $ifMatch === '') {
                throw new HttpError('google_write_failed', 'Nothing was sent to Google Calendar: no version of the event was read to write to.', 502);
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
        $this->sent++;
        try {
            $r = $send($access);
        } catch (\RuntimeException $e) {
            throw new HttpError('google_write_failed', 'Google Calendar could not be reached: ' . $e->getMessage(), 502);
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
                throw new HttpError('google_write_failed', 'Google Calendar could not be reached: ' . $e->getMessage(), 502);
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
                throw new HttpError('google_write_failed', 'Google Calendar could not be reached: ' . $e->getMessage(), 502);
            }
        }
        // Every events.list answer states the level (the access probe, rows 3, 4, 4d): kept for the
        // decision (admit) and stored. A list answered 404 is the calendar no longer shown to this
        // account (stage unshared), never an event deleted: it is refused as such.
        if ($method === 'GET' && $path === '') {
            $role = self::roleOfList((int) $r['status'], (string) $r['body']);
            if ($role !== null) {
                $this->listAnswered($calendar, $account, $role);
            }
            if ($role === 'none' && isset($calendar['id'])) {
                throw $this->refused($calendar, 'none');
            }
            // Any other refusal of a list says nothing of an event: never read as one deleted (gone).
            if ($role === null && (int) $r['status'] >= 400 && self::refusal((int) $r['status'], (string) $r['body']) === 'deleted') {
                throw new HttpError('google_write_failed', 'Google Calendar gave an answer Better-Cal does not read for this calendar\'s list; nothing was changed. Try again in a minute.', 502,
                    ['googleStatus' => 0, 'said' => 'Google Calendar gave an answer Better-Cal does not read for this calendar\'s list.']);
            }
        }
        // A write refused for what may be the account's access (a 403 not about a rate, a 404, a 410, a
        // 412) is read only after one small list says the level now: access lowered or sharing removed
        // between a change's list and its write answers each of these (the access probe: an insert 403
        // requiredAccessLevel or 404, row 5; a PATCH 403 forbidden, 404, or 412 for the account's own
        // single event once unshared, rows 6d to 6h; a DELETE there was not probed). Below writer, the
        // write is refused as read-only, never as a change or a deletion at Google. Its cost, one list,
        // is paid only on such a refusal. A write to a calendar not yet a calendar here (a move) is out.
        $refusedAs = self::refusal((int) $r['status'], (string) $r['body']);
        if ($method !== 'GET' && $path !== '/import' && isset($calendar['id'])
            && ($r['status'] === 412 || in_array($refusedAs, ['permission', 'deleted'], true))) {
            $role = $this->accessNow($calendar, $beforeSend);
            if ($role === null) {
                // The level could not be read: the refused write is read as nothing (never a 404 as "already removed").
                throw self::unconfirmed();
            }
            if (!self::writes($role)) {
                throw $this->refused($calendar, $role);
            }
        }
        if ($r['status'] === 412) {
            throw new HttpError('google_conflict', self::CONFLICT_MESSAGE, 409, ['said' => self::CONFLICT_SAID]);
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
            // Each refusal's message says nothing was changed, true of the one request; `said` is the same
            // reason without that claim, for a caller whose change had already sent part of itself
            // (GoogleSeries::run says what reached Google from what it sent).
            $google = $why !== '' ? " Its reason: \"$why\"." : '';
            throw match ($refusedAs) {
                'busy' => new HttpError('google_busy', self::BUSY_MESSAGE, 503, $extra + ['said' => 'Google Calendar is busy and refused the change for now.']),
                'deleted' => self::deleted((int) $r['status']),
                // The level a small list gave just now was writer, so this is not the calendar read-only: the
                // probe saw Google refuse a write for a few seconds after edit access was given back (row 6e).
                'permission' => new HttpError('google_write_failed', self::REFUSED_MESSAGE . $google, 403, $extra + ['said' => 'Google Calendar refused this part of the change.' . $google]),
                default => new HttpError('google_write_failed', 'Google Calendar rejected the change: HTTP ' . $r['status'] . ($why !== '' ? " ($why)" : ''), 502, $extra),
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
        return new HttpError('google_deleted', self::DELETED_MESSAGE, 409, ['googleStatus' => $googleStatus, 'said' => 'This event was deleted in Google Calendar.']);
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
