<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\EmailSender;
use BetterCal\Infra\PushSender;
use BetterCal\Support\ExpansionBudget;
use BetterCal\Support\Limits;
use BetterCal\Support\Time;
use BetterCal\Support\WorkBudgetExceeded;

/**
 * Reminders: validation of per-event overrides and default shapes, the
 * effective-reminder resolution chain (event > calendar > global), fire-time
 * math (all-day daysBefore/time on the owner's Home-zone clock), sent-reminder dedup
 * keys, and the minutely reminder_scan worker job that sends Web Push.
 *
 * Shapes:
 * - Event override (events.reminders_json): list of {"minutes": int} offsets
 *   before start (before local midnight for all-day events); [] means
 *   explicitly no reminders; NULL means inherit.
 * - Calendar default (calendars.settings_json.reminderDefaults) and global
 *   default (user settings reminderTimed/reminderAllDay):
 *   timed entries {"minutes": int}, all-day entries {"daysBefore": int,
 *   "time": "HH:MM"} (fire daysBefore days before the event date at that
 *   time in the owner's Home zone, settings.tz; the event's own zone only
 *   when no Home zone is stored).
 */
final class Reminders
{
    /** The clock reminder text reads on, and 12/24-hour, for the user being scanned. */
    private ?string $viewTz = null;
    private bool $h24 = false;

    public const MAX_ENTRIES = 5;
    public const MAX_MINUTES = 40320; // 4 weeks
    public const MAX_DAYS_BEFORE = 28;

    /** Occurrence-start lookahead for the scan; covers the max lead time (4 weeks + margin). */
    private const SCAN_LOOKAHEAD = 'P35D';
    /** A due reminder older than this is skipped (worker downtime cutoff). */
    private const FIRE_GRACE = 'PT1H';
    private const NOTIFIED_RETENTION = 'P7D';
    private const FAILING_SUBSCRIPTION_TTL = 'P3D';
    private const MASTER_SLICE = 250;
    private const OVERRIDE_SLICE = 500;
    private const ROW_PROJECTION = 'id, user_id, calendar_id, uid, title,
        SUBSTR(description, 1, 8192) AS description, location, location_lat, location_lng, url,
        start_utc, end_utc, all_day, tzid, rrule, exdates_json, recurrence_parent_id,
        recurrence_instance_utc, status, source, attendance, reminders_json';

    private readonly SystemHealth $health;

    public function __construct(
        private readonly Db $db,
        private readonly PushSubscriptions $subscriptions,
        private readonly PushSender $sender,
        private readonly EmailSender $email,
        private readonly Recurrence $recurrence,
    ) {
        $this->health = new SystemHealth($db);
    }

    // ---- Pure: validation ---------------------------------------------

    /**
     * Validate a per-event reminders override. null = inherit; [] = none.
     *
     * @return list<array{minutes:int}>|null normalized (unique, ascending)
     */
    public static function validateEventReminders(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        return self::validateTimedList($value, 'reminders');
    }

    /** @return list<array{minutes:int}> */
    public static function validateTimedList(mixed $value, string $field = 'reminderTimed'): array
    {
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            throw HttpError::badRequest("$field must be a list of {minutes} objects", 'invalid_reminders');
        }
        if (count($value) > self::MAX_ENTRIES) {
            throw HttpError::badRequest("$field allows at most " . self::MAX_ENTRIES . ' entries', 'invalid_reminders');
        }
        $minutes = [];
        foreach ($value as $entry) {
            if (!is_array($entry) || !isset($entry['minutes']) || !is_numeric($entry['minutes'])) {
                throw HttpError::badRequest("$field entries must be {minutes: int}", 'invalid_reminders');
            }
            $m = (int) $entry['minutes'];
            if ($m < 0 || $m > self::MAX_MINUTES) {
                throw HttpError::badRequest("$field minutes must be between 0 and " . self::MAX_MINUTES, 'invalid_reminders');
            }
            $minutes[$m] = true;
        }
        $out = array_keys($minutes);
        sort($out);
        return array_map(static fn(int $m): array => ['minutes' => $m], $out);
    }

    /** @return list<array{daysBefore:int,time:string}> */
    public static function validateAllDayList(mixed $value, string $field = 'reminderAllDay'): array
    {
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            throw HttpError::badRequest("$field must be a list of {daysBefore, time} objects", 'invalid_reminders');
        }
        if (count($value) > self::MAX_ENTRIES) {
            throw HttpError::badRequest("$field allows at most " . self::MAX_ENTRIES . ' entries', 'invalid_reminders');
        }
        $out = [];
        $seen = [];
        foreach ($value as $entry) {
            if (!is_array($entry) || !isset($entry['daysBefore'], $entry['time'])
                || !is_numeric($entry['daysBefore']) || !is_string($entry['time'])
            ) {
                throw HttpError::badRequest("$field entries must be {daysBefore: int, time: \"HH:MM\"}", 'invalid_reminders');
            }
            $days = (int) $entry['daysBefore'];
            if ($days < 0 || $days > self::MAX_DAYS_BEFORE) {
                throw HttpError::badRequest("$field daysBefore must be between 0 and " . self::MAX_DAYS_BEFORE, 'invalid_reminders');
            }
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $entry['time'], $m) !== 1) {
                throw HttpError::badRequest("$field time must be HH:MM (24-hour)", 'invalid_reminders');
            }
            $time = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
            $key = $days . '@' . $time;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['daysBefore' => $days, 'time' => $time];
        }
        return $out;
    }

    /**
     * Validate a calendar's reminderDefaults object. null clears the default
     * (fall through to global). Missing keys are treated as [] (none).
     *
     * @return array{timed:list<array{minutes:int}>,allDay:list<array{daysBefore:int,time:string}>}|null
     */
    public static function validateDefaults(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw HttpError::badRequest('reminderDefaults must be an object with timed/allDay lists or null', 'invalid_reminders');
        }
        foreach (array_keys($value) as $key) {
            if (!in_array($key, ['timed', 'allDay'], true)) {
                throw HttpError::badRequest("reminderDefaults has unknown key '$key'", 'invalid_reminders');
            }
        }
        return [
            'timed' => self::validateTimedList($value['timed'] ?? [], 'reminderDefaults.timed'),
            'allDay' => self::validateAllDayList($value['allDay'] ?? [], 'reminderDefaults.allDay'),
        ];
    }

    // ---- Pure: effective resolution -----------------------------------

    /**
     * Resolve the reminders that actually fire for an event: the event
     * override when set (including [] = explicitly none), else the calendar
     * default, else the global default. Subscribed (feed) calendars never
     * inherit the global default — a feed reminds only when its calendar has
     * explicit reminderDefaults (or the event an override), so subscribing to
     * a busy feed does not turn every event into a notification.
     *
     * @param list<array>|null $eventReminders decoded reminders_json
     * @param array|null $calendarDefaults decoded settings_json.reminderDefaults
     * @param list<array> $globalTimed validated reminderTimed setting
     * @param list<array> $globalAllDay validated reminderAllDay setting
     * @return array{0:list<array>,1:string} [entries, source: event|calendar|default]
     */
    public static function effective(
        ?array $eventReminders,
        ?array $calendarDefaults,
        array $globalTimed,
        array $globalAllDay,
        bool $allDay,
        string $calendarKind = 'local',
    ): array {
        if ($eventReminders !== null) {
            return [$eventReminders, 'event'];
        }
        if ($calendarDefaults !== null) {
            $list = $allDay ? ($calendarDefaults['allDay'] ?? []) : ($calendarDefaults['timed'] ?? []);
            return [is_array($list) ? array_values($list) : [], 'calendar'];
        }
        // Feeds and plugin calendars (sunsets, tides) don't inherit the global
        // default; a reminder on one is set per event or per calendar.
        if ($calendarKind === 'subscribed' || $calendarKind === 'plugin') {
            return [[], 'default'];
        }
        return [$allDay ? $globalAllDay : $globalTimed, 'default'];
    }

    // ---- Pure: fire-time math -----------------------------------------

    /**
     * UTC instant a reminder entry fires for an occurrence.
     *
     * - {minutes}: minutes before the occurrence start instant. For all-day
     *   events "start" is local midnight of the event date in its tzid.
     * - {daysBefore, time}: daysBefore days before the event's date, at the
     *   given wall-clock time on the owner's Home clock ($homeTzid); the
     *   event's own zone when no Home zone is known (all-day defaults).
     */
    public static function fireAt(array $entry, \DateTimeImmutable $startUtc, bool $allDay, string $tzid, ?string $homeTzid = null): ?\DateTimeImmutable
    {
        $tz = Time::zone($tzid);
        // An all-day event is a DATE, read in the event's zone; but "6 PM the
        // day before" is a time on the OWNER's clock, so it is taken in their
        // Home zone (settings.tz). Using the event's zone for both meant every
        // all-day event imported as UTC (thousands, from a Google export)
        // reminded at 18:00 UTC, which is 11 AM in California. Without a Home
        // zone the event's own zone is the best guess left.
        $local = $startUtc->setTimezone($tz);
        if ($allDay && $homeTzid !== null && $homeTzid !== '') {
            $local = new \DateTimeImmutable($local->format('Y-m-d') . ' 00:00:00', Time::zone($homeTzid));
        }
        if (isset($entry['daysBefore'], $entry['time'])) {
            [$h, $m] = array_map('intval', explode(':', (string) $entry['time']));
            return $local
                ->sub(new \DateInterval('P' . max(0, (int) $entry['daysBefore']) . 'D'))
                ->setTime($h, $m)
                ->setTimezone(Time::utc());
        }
        if (isset($entry['minutes']) && is_numeric($entry['minutes'])) {
            $anchor = $allDay
                ? $local->setTime(0, 0)->setTimezone(Time::utc())
                : $startUtc;
            $m = (int) $entry['minutes'];
            return $m === 0 ? $anchor : $anchor->sub(new \DateInterval('PT' . $m . 'M'));
        }
        return null;
    }

    /** Sent-reminder dedup key: eventId:occurrenceStartUtc:offsetMinutes. */
    public static function instanceKey(int $eventId, \DateTimeImmutable $startUtc, int $offsetMinutes): string
    {
        return $eventId . ':' . $startUtc->setTimezone(Time::utc())->format('Ymd\THis\Z') . ':' . $offsetMinutes;
    }

    // ---- Pure: delivery channel decision -------------------------------

    /**
     * Which channels one due reminder goes to, per the user's notifyChannel
     * setting. push-fallback sends push normally and emails only when (a) no
     * live (non-failing) subscription exists or (b) every push send this
     * attempt came back rejected/gone. Honest limitation: a push the service
     * ACCEPTED but never displayed (device unreachable past the TTL) cannot
     * be detected here, so fallback email does not cover that case.
     *
     * @param list<string> $pushOutcomes PushSender results for this attempt
     * @return array{push:bool, email:bool}
     */
    public static function channelPlan(string $channel, bool $hasLiveSub, array $pushOutcomes): array
    {
        return [
            'push' => $channel !== 'email',
            'email' => match ($channel) {
                'email', 'both' => true,
                'push-fallback' => !$hasLiveSub || !in_array(PushSender::OK, $pushOutcomes, true),
                default => false,
            },
        ];
    }

    // ---- Worker job ---------------------------------------------------

    /**
     * Minutely scan: expand upcoming occurrences, compute due reminders,
     * deliver due-and-unsent instances over the user's channels (Web Push
     * and/or email), then clean up old dedup rows and long-failing
     * subscriptions. One notified_instances row marks the reminder sent
     * regardless of channel, so a retry after a partial success never
     * double-sends.
     *
     * @param array{userId?:int,phase?:string,afterId?:int,limited?:bool} $cursor
     * @return array{sent:int, failed:int, emailed:int, emailSuppressed:int, more:bool, cursor:?array}
     */
    public function scan(?\DateTimeImmutable $now = null, array $cursor = []): array
    {
        $now = $now ?? Time::nowUtc();
        $this->db->run(
            'DELETE FROM notified_instances WHERE sent_at < ?',
            [Time::toDb($now->sub(new \DateInterval(self::NOTIFIED_RETENTION)))]
        );
        $this->subscriptions->pruneFailing($now->sub(new \DateInterval(self::FAILING_SUBSCRIPTION_TTL)));

        $pushReady = $this->sender->configured();
        $emailReady = $this->email->isConfigured();
        if (!$pushReady && !$emailReady) {
            return ['sent' => 0, 'failed' => 0, 'emailed' => 0, 'emailSuppressed' => 0, 'more' => false, 'cursor' => null];
        }
        $subsByUser = $pushReady ? $this->subscriptions->allByUser() : [];

        $sent = 0;
        $failed = 0;
        $emailed = 0;
        $emailSuppressed = 0;
        $emailAdmitted = false;
        $emailAdmission = new ExternalActionAdmission($this->db);
        $cycleKey = is_string($cursor['cycleId'] ?? null) && $cursor['cycleId'] !== ''
            ? (string) $cursor['cycleId']
            : Time::toDb($now);
        $cursorUser = max(0, (int) ($cursor['userId'] ?? 0));
        $user = $this->db->one(
            'SELECT id, email, settings_json FROM users WHERE id >= ? ORDER BY id LIMIT 1',
            [$cursorUser > 0 ? $cursorUser : 1]
        );
        if ($user === null) {
            return ['sent' => 0, 'failed' => 0, 'emailed' => 0, 'emailSuppressed' => 0, 'more' => false, 'cursor' => null];
        }
        $userId = (int) $user['id'];
        $sameUser = $cursorUser === $userId;
        $phase = $sameUser && ($cursor['phase'] ?? '') === 'overrides' ? 'overrides' : 'masters';
        $afterId = $sameUser ? max(0, (int) ($cursor['afterId'] ?? 0)) : 0;
        $nextUserCursor = function () use ($userId): ?array {
            $next = (int) ($this->db->scalar('SELECT id FROM users WHERE id > ? ORDER BY id LIMIT 1', [$userId]) ?? 0);
            return $next > 0 ? ['userId' => $next, 'phase' => 'masters', 'afterId' => 0] : null;
        };

        $stored = is_string($user['settings_json'] ?? null) ? json_decode((string) $user['settings_json'], true) : null;
            $settings = Settings::withDefaults(is_array($stored) ? $stored : []);
            $channel = (string) $settings['notifyChannel'];
            $emailTo = Settings::notifyDestination($settings, (string) $user['email'], $this->db);
            $subs = $subsByUser[$userId] ?? [];
            $wantsPush = $channel !== 'email' && $subs !== [];
            $wantsEmail = $emailReady && $channel !== 'push';
            if (!$wantsPush && !$wantsEmail) {
                $next = $nextUserCursor();
                return ['sent' => 0, 'failed' => 0, 'emailed' => 0, 'emailSuppressed' => 0, 'more' => $next !== null, 'cursor' => $next];
            }
            $hasLiveSub = array_filter($subs, static fn(array $s): bool => ($s['failing_since'] ?? null) === null) !== [];

            $slice = $this->dueForUserSlice($userId, $now, $phase, $afterId);
            $dueForUser = $slice['due'];
            $cycleLimited = !empty($cursor['limited']) || $slice['limited'];
            if ($slice['limited']) {
                $this->health->recordFailure(
                    'reminder-budget:' . $userId,
                    'job',
                    $userId,
                    'Reminder expansion',
                    'One reminder slice exceeded its safe event, recurrence, or time budget. Later slices will continue automatically.'
                );
                error_log('reminder expansion budget reached for user ' . $userId . ' at ' . $phase . ':' . $afterId);
            } elseif (!$slice['more'] && $phase === 'overrides' && !$cycleLimited) {
                $this->health->recordOk('reminder-budget:' . $userId, 'job', $userId, 'Reminder expansion', false);
            }

            foreach ($dueForUser as $due) {
                $inserted = $this->db->run(
                    'INSERT IGNORE INTO notified_instances (instance_key, sent_at) VALUES (?, ?)',
                    [$due['key'], Time::toDb($now)]
                )->rowCount();
                if ($inserted === 0) {
                    continue; // already sent (dedup, shared across channels)
                }
                $outcomes = [];
                if ($wantsPush) {
                    foreach ($subs as $sub) {
                        // Reminders lose their value fast: if the push service
                        // cannot deliver within 15 minutes (device unreachable
                        // or dozing), drop it rather than arriving hours stale.
                        $result = $this->sender->send($sub, $due['payload'], 900);
                        $outcomes[] = $result;
                        $device = PushSubscriptions::labelFor($sub);
                        if ($result === PushSender::OK) {
                            $this->subscriptions->recordSuccess((int) $sub['id']);
                            $this->health->recordOk('push:' . $sub['id'], 'push', $userId, $device);
                            $sent++;
                        } else {
                            if ($result === PushSender::GONE) {
                                $this->subscriptions->recordFailure((int) $sub['id']);
                            }
                            // Every failure counts here, not only 'gone': a
                            // device that never gets its reminders because the
                            // push service keeps answering 5xx used to be a
                            // counter and an error_log line.
                            $this->health->recordFailure(
                                'push:' . $sub['id'],
                                'push',
                                $userId,
                                $device,
                                $result === PushSender::GONE ? 'subscription expired or revoked by the browser' : 'push service did not accept the message'
                            );
                            $failed++;
                        }
                    }
                }
                $plan = self::channelPlan($channel, $hasLiveSub, $outcomes);
                if ($wantsEmail && $plan['email'] && $emailTo !== '') {
                    $admitted = $emailAdmission->admitEmail($userId, $emailTo, $cycleKey, $now);
                    if (!$admitted['admitted']) {
                        $emailSuppressed++;
                    } else {
                        $emailAdmitted = true;
                        // Email failures log inside sendReminder and never
                        // block the scan. The reservation and dedup row stay:
                        // failures must not become a quota or retry bypass.
                        if ($this->email->sendReminder($emailTo, $due['payload'])) {
                            $emailed++;
                        }
                    }
                }
            }
        if ($emailSuppressed > 0) {
            $this->health->recordFailure(
                'reminder-email-budget:' . $userId,
                'job',
                $userId,
                'Reminder email delivery',
                'Some reminder emails were suppressed because the persistent delivery safety limit was reached. Push delivery continued where configured.'
            );
            error_log('reminder email safety budget suppressed ' . $emailSuppressed . ' delivery attempt(s) for user ' . $userId);
        } elseif ($emailAdmitted) {
            $this->health->recordOk('reminder-email-budget:' . $userId, 'job', $userId, 'Reminder email delivery', false);
        }
        $next = $slice['more']
            ? ['userId' => $userId, 'phase' => $slice['phase'], 'afterId' => $slice['afterId'], 'limited' => $cycleLimited]
            : $nextUserCursor();
        return ['sent' => $sent, 'failed' => $failed, 'emailed' => $emailed, 'emailSuppressed' => $emailSuppressed, 'more' => $next !== null, 'cursor' => $next];
    }

    /**
     * Due reminders for one user at $now: occurrences in the lookahead window
     * whose fire time falls in (now - grace, now].
     *
     * @return list<array{key:string, payload:array}>
     */
    private function dueForUser(int $userId, \DateTimeImmutable $now): array
    {
        return $this->dueForUserSlice($userId, $now, 'masters', 0)['due'];
    }

    /**
     * One durable reminder-work slice. The worker carries the returned cursor
     * in the next job, so a dense account cannot make every later reminder
     * restart behind the same over-budget prefix.
     *
     * @return array{due:list<array{key:string,payload:array}>,more:bool,phase:string,afterId:int,limited:bool}
     */
    private function dueForUserSlice(int $userId, \DateTimeImmutable $now, string $phase, int $afterId): array
    {
        $grace = $now->sub(new \DateInterval(self::FIRE_GRACE));
        // Occurrences are collected from a day and more back: an all-day
        // reminder fires on the Home clock, which can be after the stored
        // day has ended in UTC ("6 PM that day" in Los Angeles for an event
        // stored as a UTC date). Starting at the grace mark, those occurrences
        // were never looked at (audit, 0.9.14). Which ones are due is still
        // decided by the fire time below, and sends are deduplicated per
        // instance, so the wider window adds no repeats.
        $winStart = $grace->sub(new \DateInterval('PT26H'));
        $winEnd = $now->add(new \DateInterval(self::SCAN_LOOKAHEAD));

        $calendars = [];
        foreach ($this->db->all('SELECT id, kind, provider, settings_json FROM calendars WHERE user_id = ?', [$userId]) as $cal) {
            $settings = is_string($cal['settings_json'] ?? null) ? json_decode((string) $cal['settings_json'], true) : $cal['settings_json'];
            $defaults = is_array($settings) && isset($settings['reminderDefaults']) && is_array($settings['reminderDefaults'])
                ? $settings['reminderDefaults']
                : null;
            $calendars[(int) $cal['id']] = ['kind' => (string) $cal['kind'], 'provider' => (string) ($cal['provider'] ?? 'ics'), 'defaults' => $defaults];
        }

        $rawSettings = $this->db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]);
        $stored = is_string($rawSettings) ? json_decode($rawSettings, true) : $rawSettings;
        $userSettings = Settings::withDefaults(is_array($stored) ? $stored : []);
        $globalTimed = is_array($userSettings['reminderTimed'] ?? null) ? $userSettings['reminderTimed'] : [];
        $globalAllDay = is_array($userSettings['reminderAllDay'] ?? null) ? $userSettings['reminderAllDay'] : [];
        // The owner's Home zone: the clock all-day reminder times are read on.
        $homeTzid = is_string($userSettings['tz'] ?? null) && $userSettings['tz'] !== '' ? $userSettings['tz'] : null;
        // The clock a reminder's text reads on (0.9.1): where the owner's
        // device last was, then Home, the way the app shows times on the
        // device in hand. 12- or 24-hour as the owner set it.
        $hereTzid = is_string($userSettings['hereTz'] ?? null) && $userSettings['hereTz'] !== '' ? $userSettings['hereTz'] : null;
        $this->viewTz = $hereTzid ?? $homeTzid;
        $this->h24 = (string) ($userSettings['timeFormat'] ?? '12') === '24';

        $phase = $phase === 'overrides' ? 'overrides' : 'masters';
        $limited = false;
        $more = false;
        if ($phase === 'masters') {
            // Masters + standalone events whose occurrences can start in the
            // window (mirrors Events::window's selection, without filters).
            $params = [$userId, $afterId, Time::toDb($winEnd), Time::toDb($winStart), Time::toDb($winEnd)];
            $masters = $this->db->all(
                'SELECT ' . self::ROW_PROJECTION . " FROM events
                 WHERE user_id = ? AND id > ? AND deleted_at IS NULL AND recurrence_parent_id IS NULL
                   AND attendance <> 'hidden' AND status <> 'cancelled'
                   AND ((rrule IS NULL AND start_utc < ? AND end_utc > ?) OR (rrule IS NOT NULL AND start_utc < ?))
                 ORDER BY id LIMIT " . (self::MASTER_SLICE + 1),
                $params
            );
            $more = count($masters) > self::MASTER_SLICE;
            if ($more) {
                array_pop($masters);
            }
            $masterIds = array_map(static fn(array $row): int => (int) $row['id'], $masters);
            $lastSelectedId = $masterIds !== [] ? max($masterIds) : $afterId;
            if ($masterIds !== []) {
                [$in, $inParams] = Db::in($masterIds);
                $overrides = $this->db->all(
                    'SELECT ' . self::ROW_PROJECTION . " FROM events
                     WHERE user_id = ? AND deleted_at IS NULL AND recurrence_parent_id IN $in
                       AND attendance <> 'hidden' AND status <> 'cancelled'
                       AND ((start_utc < ? AND end_utc > ?)
                            OR (recurrence_instance_utc >= ? AND recurrence_instance_utc < ?))
                     ORDER BY id LIMIT " . (Limits::get('EXPANSION_OCCURRENCES') + 1),
                    [$userId, ...$inParams, Time::toDb($winEnd), Time::toDb($winStart), Time::toDb($winStart), Time::toDb($winEnd)]
                );
                if (count($overrides) > Limits::get('EXPANSION_OCCURRENCES')) {
                    // Skip only this bounded slice and continue after it; a
                    // durable health row tells the owner what was missed.
                    $overrides = [];
                    $masters = [];
                    $limited = true;
                    $more = true;
                }
            } else {
                $overrides = [];
            }
        } else {
            $masters = [];
            $lastSelectedId = $afterId;
            $overrides = $this->db->all(
                'SELECT ' . self::ROW_PROJECTION . ' FROM events
                 WHERE user_id = ? AND id > ? AND deleted_at IS NULL AND recurrence_parent_id IS NOT NULL
                   AND attendance <> \'hidden\' AND status <> \'cancelled\'
                   AND start_utc < ? AND end_utc > ?
                 ORDER BY id LIMIT ' . (self::OVERRIDE_SLICE + 1),
                [$userId, $afterId, Time::toDb($winEnd), Time::toDb($winStart)]
            );
            $more = count($overrides) > self::OVERRIDE_SLICE;
            if ($more) {
                array_pop($overrides);
            }
            if ($overrides !== []) {
                $lastSelectedId = max(array_map(static fn(array $row): int => (int) $row['id'], $overrides));
            }
        }
        $ovByParent = [];
        foreach ($overrides as $ov) {
            $ovByParent[(int) $ov['recurrence_parent_id']][] = $ov;
        }

        // The same event on two calendars (#9) reminds once: from the copy
        // that would be shown, among those that have reminders at all.
        $byId = [];
        foreach ($masters as $m) {
            $byId[(int) $m['id']] = $m;
        }
        $quiet = (new Duplicates($this->db))->silenced(
            $byId,
            function (array $row) use ($calendars, $globalTimed, $globalAllDay): bool {
                $cal = $calendars[(int) $row['calendar_id']] ?? ['kind' => 'local', 'defaults' => null];
                [$entries] = self::effective(self::decode($row['reminders_json'] ?? null), $cal['defaults'], $globalTimed, $globalAllDay, (int) $row['all_day'] === 1, $cal['kind']);
                return $entries !== [];
            },
            $calendars
        );

        $due = [];
        $seenParents = [];
        $budget = ExpansionBudget::standard();
        $processedId = $limited ? $lastSelectedId : $afterId;
        foreach ($masters as $master) {
            $processedId = (int) $master['id'];
            $seenParents[(int) $master['id']] = true;
            if (isset($quiet[(int) $master['id']])) {
                continue;
            }
            $masterCal = $calendars[(int) $master['calendar_id']] ?? ['kind' => 'local', 'defaults' => null];
            [$masterEntries] = self::effective(
                self::decode($master['reminders_json'] ?? null),
                $masterCal['defaults'],
                $globalTimed,
                $globalAllDay,
                (int) $master['all_day'] === 1,
                $masterCal['kind'],
            );
            $mayRemind = $masterEntries !== [];
            if (!$mayRemind) {
                foreach ($ovByParent[(int) $master['id']] ?? [] as $override) {
                    $overrideCal = $calendars[(int) $override['calendar_id']] ?? $masterCal;
                    [$overrideEntries] = self::effective(
                        self::decode($override['reminders_json'] ?? null),
                        $overrideCal['defaults'],
                        $globalTimed,
                        $globalAllDay,
                        (int) $override['all_day'] === 1,
                        $overrideCal['kind'],
                    );
                    if ($overrideEntries !== []) {
                        $mayRemind = true;
                        break;
                    }
                }
            }
            if (!$mayRemind) {
                continue;
            }
            try {
                $occs = $this->recurrence->expand($master, $ovByParent[(int) $master['id']] ?? [], $winStart, $winEnd, $budget);
            } catch (WorkBudgetExceeded) {
                $limited = true;
                $more = true;
                break;
            }
            foreach ($occs as $occ) {
                $this->collectDue($occ['row'], $occ['start'], $calendars, $globalTimed, $globalAllDay, $now, $grace, $due, $homeTzid);
            }
        }
        // Overrides in-window whose master was not selected (series otherwise
        // out of range) — same edge Events::window handles.
        foreach ($ovByParent as $parentId => $ovs) {
            if (isset($seenParents[$parentId]) || isset($quiet[$parentId])) {
                continue;
            }
            foreach ($ovs as $ov) {
                $ovStart = Time::fromDb((string) $ov['start_utc']);
                if ($ovStart < $winEnd && Time::fromDb((string) $ov['end_utc']) > $winStart) {
                    $budget->occurrence();
                    $this->collectDue($ov, $ovStart, $calendars, $globalTimed, $globalAllDay, $now, $grace, $due, $homeTzid);
                }
            }
        }
        if ($phase === 'masters') {
            if ($limited || $more) {
                return ['due' => $due, 'more' => true, 'phase' => 'masters', 'afterId' => $processedId, 'limited' => $limited];
            }
            // A final bounded phase picks up an in-window exception whose
            // master itself did not qualify for the master query.
            return ['due' => $due, 'more' => true, 'phase' => 'overrides', 'afterId' => 0, 'limited' => false];
        }
        return ['due' => $due, 'more' => $more, 'phase' => 'overrides', 'afterId' => $lastSelectedId, 'limited' => false];
    }

    /** @param list<array{key:string,payload:array}> $due */
    private function collectDue(
        array $row,
        \DateTimeImmutable $startUtc,
        array $calendars,
        array $globalTimed,
        array $globalAllDay,
        \DateTimeImmutable $now,
        \DateTimeImmutable $grace,
        array &$due,
        ?string $homeTzid = null,
    ): void {
        $calendarId = (int) $row['calendar_id'];
        $cal = $calendars[$calendarId] ?? ['kind' => 'local', 'defaults' => null];
        $allDay = (int) $row['all_day'] === 1;
        [$entries] = self::effective(
            self::decode($row['reminders_json'] ?? null),
            $cal['defaults'],
            $globalTimed,
            $globalAllDay,
            $allDay,
            $cal['kind']
        );
        foreach ($entries as $entry) {
            $fire = self::fireAt(is_array($entry) ? $entry : [], $startUtc, $allDay, (string) $row['tzid'], $homeTzid);
            if ($fire === null || $fire > $now || $fire <= $grace) {
                continue;
            }
            $offset = (int) round(($startUtc->getTimestamp() - $fire->getTimestamp()) / 60);
            $due[] = [
                'key' => self::instanceKey((int) $row['id'], $startUtc, $offset),
                'payload' => self::payload($row, $startUtc, $allDay, $this->viewTz, $this->h24),
            ];
        }
    }

    /**
     * Notification payload shown by the service worker (and the reminder
     * email). A timed event's time reads on $viewTz, the owner's device zone
     * (then Home), as the app shows it; an event that keeps a zone of its own
     * whose clock reads differently says that time too: "12:45 PM (4:45 AM
     * in Los Angeles)". Without a view zone it reads on the event's zone,
     * unless that is UTC (imported events with no zone of their own). An
     * all-day event names its own date. $h24: the 24-hour clock setting.
     */
    public static function payload(array $row, \DateTimeImmutable $startUtc, bool $allDay, ?string $viewTz = null, bool $h24 = false): array
    {
        $eventTzid = (string) ($row['tzid'] ?? '');
        $tz = Time::zone($eventTzid);
        $local = $startUtc->setTimezone($tz);
        if ($allDay) {
            $body = $local->format('D, M j') . ' · All day';
        } else {
            $clock = $h24 ? 'H:i' : 'g:i A';
            $view = $viewTz !== null && $viewTz !== '' ? Time::zone($viewTz) : $tz;
            $seen = $startUtc->setTimezone($view);
            $body = $seen->format('D, M j, ' . $clock);
            $ownZone = $eventTzid !== '' && strtoupper($eventTzid) !== 'UTC' && $tz->getName() !== $view->getName();
            if ($ownZone && $local->format('Y-m-d H:i') !== $seen->format('Y-m-d H:i')) {
                $city = str_replace('_', ' ', substr($tz->getName(), (int) strrpos($tz->getName(), '/') + 1));
                $other = $local->format('D, M j') === $seen->format('D, M j') ? $local->format($clock) : $local->format('D ' . $clock);
                $body .= ' (' . $other . ' in ' . $city . ')';
            }
        }
        if (!empty($row['location'])) {
            $body .= ' · ' . mb_substr((string) $row['location'], 0, 120);
        }
        $instanceId = Recurrence::instanceId((int) $row['id'], $startUtc);
        // &at= carries the occurrence start so the click handler can load a
        // window guaranteed to contain it (contract: docs/api-contract.md).
        $at = $startUtc->setTimezone(Time::utc())->format('Y-m-d\TH:i:s\Z');
        return [
            'title' => (string) ($row['title'] ?? '') !== '' ? (string) $row['title'] : '(untitled event)',
            'body' => $body,
            'url' => '/?event=' . rawurlencode($instanceId) . '&at=' . rawurlencode($at),
            'tag' => $instanceId,
        ] + array_filter(self::links($row), static fn($v) => $v !== null);
    }

    /**
     * The notification's buttons (0.6.3): Join for a video call, Map for a
     * place. Map opens the place by name at its coordinates, the way the
     * app's Directions does (web/src/lib/maps.js gmapsUrl); coordinates alone
     * when the text names nothing findable, text alone when nothing is stored.
     *
     * @return array{join:?string,map:?string}
     */
    public static function links(array $row): array
    {
        $loc = trim((string) ($row['location'] ?? ''));
        $join = null;
        // The same meeting links and "address comes later" phrases the app
        // knows (web/src/lib/patterns.js, one copy for both since 0.9.15).
        foreach ([$loc, (string) ($row['url'] ?? ''), (string) ($row['description'] ?? '')] as $text) {
            $hit = \BetterCal\Support\Patterns::meetingLink($text);
            if ($hit !== null) {
                $join = $hit[0];
                break;
            }
        }
        $lat = $row['location_lat'] ?? null;
        $lng = $row['location_lng'] ?? null;
        $hasCoords = is_numeric($lat) && is_numeric($lng);
        $isLink = preg_match('~^(?:https?://|www\.)~i', $loc) === 1;
        $pending = \BetterCal\Support\Patterns::isPendingLocation($loc);
        $coordText = preg_match('/^-?\d+(?:\.\d+)?\s*,\s*-?\d+(?:\.\d+)?$/', $loc) === 1;
        $findable = $loc !== '' && !$isLink && !$pending && !$coordText;
        $map = null;
        if ($findable && $hasCoords) {
            $map = 'https://www.google.com/maps/search/' . str_replace('%20', '+', rawurlencode($loc)) . '/@' . (float) $lat . ',' . (float) $lng . ',17z';
        } elseif ($hasCoords && !$isLink) {
            $map = 'https://www.google.com/maps/search/?api=1&query=' . (float) $lat . ',' . (float) $lng;
        } elseif ($findable && $join === null) {
            $map = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($loc);
        }
        return ['join' => $join, 'map' => $map];
    }

    /** @return list<array>|null decoded reminders_json */
    public static function decode(mixed $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = is_array($json) ? $json : json_decode((string) $json, true);
        return is_array($decoded) ? array_values($decoded) : null;
    }
}
