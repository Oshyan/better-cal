<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\PushSender;
use BetterCal\Support\Time;

/**
 * Reminders: validation of per-event overrides and default shapes, the
 * effective-reminder resolution chain (event > calendar > global), fire-time
 * math (all-day daysBefore/time in the event's tzid), sent-reminder dedup
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
 *   local time in the event's tzid).
 */
final class Reminders
{
    public const MAX_ENTRIES = 5;
    public const MAX_MINUTES = 40320; // 4 weeks
    public const MAX_DAYS_BEFORE = 28;

    /** Occurrence-start lookahead for the scan; covers the max lead time (4 weeks + margin). */
    private const SCAN_LOOKAHEAD = 'P35D';
    /** A due reminder older than this is skipped (worker downtime cutoff). */
    private const FIRE_GRACE = 'PT1H';
    private const NOTIFIED_RETENTION = 'P7D';
    private const FAILING_SUBSCRIPTION_TTL = 'P3D';

    public function __construct(
        private readonly Db $db,
        private readonly PushSubscriptions $subscriptions,
        private readonly PushSender $sender,
        private readonly Recurrence $recurrence,
    ) {
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
        if ($calendarKind === 'subscribed') {
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
     * - {daysBefore, time}: daysBefore days before the event's local date, at
     *   the given wall-clock time, in the event's tzid (all-day defaults).
     */
    public static function fireAt(array $entry, \DateTimeImmutable $startUtc, bool $allDay, string $tzid): ?\DateTimeImmutable
    {
        $tz = Time::zone($tzid);
        if (isset($entry['daysBefore'], $entry['time'])) {
            [$h, $m] = array_map('intval', explode(':', (string) $entry['time']));
            return $startUtc->setTimezone($tz)
                ->sub(new \DateInterval('P' . max(0, (int) $entry['daysBefore']) . 'D'))
                ->setTime($h, $m)
                ->setTimezone(Time::utc());
        }
        if (isset($entry['minutes']) && is_numeric($entry['minutes'])) {
            $anchor = $allDay
                ? $startUtc->setTimezone($tz)->setTime(0, 0)->setTimezone(Time::utc())
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

    // ---- Worker job ---------------------------------------------------

    /**
     * Minutely scan: expand upcoming occurrences, compute due reminders, send
     * Web Push for due-and-unsent instances, then clean up old dedup rows and
     * long-failing subscriptions.
     *
     * @return array{sent:int, failed:int}
     */
    public function scan(?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? Time::nowUtc();
        $this->db->run(
            'DELETE FROM notified_instances WHERE sent_at < ?',
            [Time::toDb($now->sub(new \DateInterval(self::NOTIFIED_RETENTION)))]
        );
        $this->subscriptions->pruneFailing($now->sub(new \DateInterval(self::FAILING_SUBSCRIPTION_TTL)));

        if (!$this->sender->configured()) {
            return ['sent' => 0, 'failed' => 0];
        }
        $subsByUser = $this->subscriptions->allByUser();
        if ($subsByUser === []) {
            return ['sent' => 0, 'failed' => 0];
        }

        $sent = 0;
        $failed = 0;
        foreach ($subsByUser as $userId => $subs) {
            foreach ($this->dueForUser((int) $userId, $now) as $due) {
                $inserted = $this->db->run(
                    'INSERT IGNORE INTO notified_instances (instance_key, sent_at) VALUES (?, ?)',
                    [$due['key'], Time::toDb($now)]
                )->rowCount();
                if ($inserted === 0) {
                    continue; // already sent (dedup)
                }
                foreach ($subs as $sub) {
                    $result = $this->sender->send($sub, $due['payload']);
                    if ($result === PushSender::OK) {
                        $this->subscriptions->recordSuccess((int) $sub['id']);
                        $sent++;
                    } else {
                        if ($result === PushSender::GONE) {
                            $this->subscriptions->recordFailure((int) $sub['id']);
                        }
                        $failed++;
                    }
                }
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Due reminders for one user at $now: occurrences in the lookahead window
     * whose fire time falls in (now - grace, now].
     *
     * @return list<array{key:string, payload:array}>
     */
    private function dueForUser(int $userId, \DateTimeImmutable $now): array
    {
        $grace = $now->sub(new \DateInterval(self::FIRE_GRACE));
        $winStart = $grace;
        $winEnd = $now->add(new \DateInterval(self::SCAN_LOOKAHEAD));

        $calendars = [];
        foreach ($this->db->all('SELECT id, kind, settings_json FROM calendars WHERE user_id = ?', [$userId]) as $cal) {
            $settings = is_string($cal['settings_json'] ?? null) ? json_decode((string) $cal['settings_json'], true) : $cal['settings_json'];
            $defaults = is_array($settings) && isset($settings['reminderDefaults']) && is_array($settings['reminderDefaults'])
                ? $settings['reminderDefaults']
                : null;
            $calendars[(int) $cal['id']] = ['kind' => (string) $cal['kind'], 'defaults' => $defaults];
        }

        $rawSettings = $this->db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]);
        $stored = is_string($rawSettings) ? json_decode($rawSettings, true) : $rawSettings;
        $userSettings = Settings::withDefaults(is_array($stored) ? $stored : []);
        $globalTimed = is_array($userSettings['reminderTimed'] ?? null) ? $userSettings['reminderTimed'] : [];
        $globalAllDay = is_array($userSettings['reminderAllDay'] ?? null) ? $userSettings['reminderAllDay'] : [];

        // Masters + standalone events whose occurrences can start in the
        // window (mirrors Events::window's selection, without user filters).
        $params = [$userId, Time::toDb($winEnd), Time::toDb($winStart), Time::toDb($winEnd)];
        $masters = $this->db->all(
            "SELECT * FROM events WHERE user_id = ? AND deleted_at IS NULL AND recurrence_parent_id IS NULL
             AND attendance <> 'hidden' AND status <> 'cancelled'
             AND ((rrule IS NULL AND start_utc < ? AND end_utc > ?) OR (rrule IS NOT NULL AND start_utc < ?))",
            $params
        );
        $masterIds = array_map(static fn($r) => (int) $r['id'], $masters);
        [$in, $inParams] = Db::in($masterIds !== [] ? $masterIds : [0]);
        $overrides = $this->db->all(
            "SELECT * FROM events WHERE user_id = ? AND deleted_at IS NULL AND recurrence_parent_id IS NOT NULL
             AND attendance <> 'hidden' AND status <> 'cancelled'
             AND (recurrence_parent_id IN $in OR (start_utc < ? AND end_utc > ?))",
            [$userId, ...$inParams, Time::toDb($winEnd), Time::toDb($winStart)]
        );
        $ovByParent = [];
        foreach ($overrides as $ov) {
            $ovByParent[(int) $ov['recurrence_parent_id']][] = $ov;
        }

        $due = [];
        $seenParents = [];
        foreach ($masters as $master) {
            $seenParents[(int) $master['id']] = true;
            $occs = $this->recurrence->expand($master, $ovByParent[(int) $master['id']] ?? [], $winStart, $winEnd);
            foreach ($occs as $occ) {
                $this->collectDue($occ['row'], $occ['start'], $calendars, $globalTimed, $globalAllDay, $now, $grace, $due);
            }
        }
        // Overrides in-window whose master was not selected (series otherwise
        // out of range) — same edge Events::window handles.
        foreach ($ovByParent as $parentId => $ovs) {
            if (isset($seenParents[$parentId])) {
                continue;
            }
            foreach ($ovs as $ov) {
                $ovStart = Time::fromDb((string) $ov['start_utc']);
                if ($ovStart < $winEnd && Time::fromDb((string) $ov['end_utc']) > $winStart) {
                    $this->collectDue($ov, $ovStart, $calendars, $globalTimed, $globalAllDay, $now, $grace, $due);
                }
            }
        }
        return $due;
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
            $fire = self::fireAt(is_array($entry) ? $entry : [], $startUtc, $allDay, (string) $row['tzid']);
            if ($fire === null || $fire > $now || $fire <= $grace) {
                continue;
            }
            $offset = (int) round(($startUtc->getTimestamp() - $fire->getTimestamp()) / 60);
            $due[] = [
                'key' => self::instanceKey((int) $row['id'], $startUtc, $offset),
                'payload' => self::payload($row, $startUtc, $allDay),
            ];
        }
    }

    /** Notification payload shown by the service worker. */
    public static function payload(array $row, \DateTimeImmutable $startUtc, bool $allDay): array
    {
        $tz = Time::zone((string) $row['tzid']);
        $local = $startUtc->setTimezone($tz);
        $body = $allDay ? $local->format('D, M j') . ' · All day' : $local->format('D, M j, g:i A');
        if (!empty($row['location'])) {
            $body .= ' · ' . mb_substr((string) $row['location'], 0, 120);
        }
        $instanceId = Recurrence::instanceId((int) $row['id'], $startUtc);
        return [
            'title' => (string) ($row['title'] ?? '') !== '' ? (string) $row['title'] : '(untitled event)',
            'body' => $body,
            'url' => '/?event=' . rawurlencode($instanceId),
            'tag' => $instanceId,
        ];
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
