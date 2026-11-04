<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

use BetterCal\Support\Time;

/**
 * An edited day's own fields (series-model.md, Edited Days and Their Own
 * Fields): the list stored in an override row's override_fields, the
 * columns each field covers, and the equality rule that fills the list for
 * rows whose ownership isn't known (the 063 backfill, and rows first seen
 * from a feed, Google or CalDAV).
 */
final class OwnFields
{
    public const FIELDS = ['date', 'time', 'length', 'allDay', 'title', 'location', 'description', 'url', 'reminders', 'status', 'attendance', 'tags', 'people', 'icon'];

    /**
     * What refers to an edited row, and so is the day's own without being a
     * field it set (series-model.md, Own Fields): its trip, the event_links
     * naming the row as a trip or as one of a trip's stops. A row exists for
     * its own fields and for what refers to it, so a day in a trip never
     * goes back to the series while the link stands. Read from event_links
     * each time, never stored in override_fields.
     */
    public const REFERENCES = ['trip'];

    /**
     * An edited day's own things: the fields it owns and, when anything
     * refers to it, its trip. Empty only for a day with nothing of its own,
     * the one kind of row that goes back to the series.
     *
     * @param list<string> $fields
     * @return list<string>
     */
    public static function own(array $fields, bool $inTrip): array
    {
        return self::normalize($inTrip ? array_merge($fields, ['trip']) : array_diff($fields, self::REFERENCES));
    }

    /** The content fields and the columns each one writes. */
    public const COLUMNS = [
        'title' => ['title'],
        'location' => ['location', 'location_lat', 'location_lng', 'location_source', 'location_provider', 'location_placed_at', 'geocoded_at'],
        'description' => ['description'],
        'url' => ['url'],
        'reminders' => ['reminders_json'],
        'status' => ['status'],
        'attendance' => ['attendance'],
        'icon' => ['icon'],
    ];

    /** @var \WeakMap<\BetterCal\Infra\Db,bool>|null */
    private static ?\WeakMap $column = null;

    /** Whether events has override_fields (a database before migration 063 doesn't). */
    public static function hasColumn(\BetterCal\Infra\Db $db): bool
    {
        self::$column ??= new \WeakMap();
        if (!isset(self::$column[$db])) {
            $sqlite = $db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
            self::$column[$db] = $sqlite
                ? in_array('override_fields', array_column($db->all('PRAGMA table_info(`events`)'), 'name'), true)
                : (int) $db->scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'override_fields'") > 0;
        }
        return self::$column[$db];
    }

    /** The field a column belongs to, or null. */
    public static function fieldOf(string $column): ?string
    {
        foreach (self::COLUMNS as $field => $cols) {
            if (in_array($column, $cols, true)) {
                return $field;
            }
        }
        return null;
    }

    /** @return list<string> */
    public static function decode(mixed $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $list = is_array($json) ? $json : json_decode((string) $json, true);
        if (!is_array($list)) {
            return null;
        }
        return self::normalize($list);
    }

    /** The stored list: the fields alone, since what refers to a row is read from its links. @param list<string> $list */
    public static function encode(array $list): string
    {
        return json_encode(self::normalize(array_diff($list, self::REFERENCES)));
    }

    /** @param list<mixed> $list @return list<string> */
    public static function normalize(array $list): array
    {
        $out = [];
        foreach ([...self::FIELDS, ...self::REFERENCES] as $f) {
            if (in_array($f, $list, true)) {
                $out[] = $f;
            }
        }
        return $out;
    }

    /**
     * The fields an edited row owns by equality with its master: what
     * differs from the series is its own. $tags and $people are each row's
     * sorted names or ids, when known.
     *
     * @return list<string>
     */
    public static function infer(array $row, array $master, ?array $rowTags = null, ?array $masterTags = null, ?array $rowPeople = null, ?array $masterPeople = null): array
    {
        $own = [];
        $tzM = Time::zone((string) ($master['tzid'] ?? 'UTC'));
        $tzR = Time::zone((string) ($row['tzid'] ?? 'UTC'));
        $key = (string) ($row['recurrence_instance_utc'] ?? '');
        $start = Time::fromDb((string) $row['start_utc']);
        if ($key !== '') {
            $keyLocal = Time::fromDb($key)->setTimezone($tzM);
            $rowAllDay = (int) ($row['all_day'] ?? 0) === 1;
            $shownDay = $rowAllDay ? $start->setTimezone(Time::utc())->format('Y-m-d') : $start->setTimezone($tzR)->format('Y-m-d');
            if ($shownDay !== $keyLocal->format('Y-m-d')) {
                $own[] = 'date';
            }
        }
        $mAllDay = (int) ($master['all_day'] ?? 0) === 1;
        $rAllDay = (int) ($row['all_day'] ?? 0) === 1;
        if ($mAllDay !== $rAllDay) {
            $own[] = 'allDay';
        }
        if (!$rAllDay) {
            // Its time is the series' when it shows at the instant the series
            // gives it there: on its occurrence's day its key, on a day of its
            // own the series' clock there. Compared as instants, since an
            // equal day, clock and zone are two instants where the hour
            // repeats (the first, when no instant names it).
            $mStart = Time::fromDb((string) $master['start_utc'])->setTimezone($tzM);
            $sameZone = Time::normalizeTzid((string) $row['tzid']) === Time::normalizeTzid((string) $master['tzid']);
            $series = null;
            if (!$mAllDay && $sameZone) {
                $series = $key !== '' && !in_array('date', $own, true) ? Time::fromDb($key)
                    : Calc::at(Cal::dnOf($start->setTimezone($tzR)), [(int) $mStart->format('G'), (int) $mStart->format('i'), (int) $mStart->format('s')], (string) $master['tzid']);
            }
            if ($series === null || $series->getTimestamp() !== $start->getTimestamp()) {
                $own[] = 'time';
            }
        }
        $len = static fn(array $r): int => Time::fromDb((string) $r['end_utc'])->getTimestamp() - Time::fromDb((string) $r['start_utc'])->getTimestamp();
        if ($mAllDay === $rAllDay && $len($row) !== $len($master)) {
            $own[] = 'length';
        }
        foreach (['title', 'location', 'description', 'url', 'icon', 'status', 'attendance'] as $col) {
            if (array_key_exists($col, $row) && array_key_exists($col, $master) && (string) ($row[$col] ?? '') !== (string) ($master[$col] ?? '')) {
                $own[] = $col;
            }
        }
        if (self::reminders($row['reminders_json'] ?? null) !== self::reminders($master['reminders_json'] ?? null)) {
            $own[] = 'reminders';
        }
        if ($rowTags !== null && $masterTags !== null && self::set($rowTags) !== self::set($masterTags)) {
            $own[] = 'tags';
        }
        if ($rowPeople !== null && $masterPeople !== null && self::set($rowPeople) !== self::set($masterPeople)) {
            $own[] = 'people';
        }
        return self::normalize($own);
    }

    private static function reminders(mixed $json): string
    {
        if ($json === null || $json === '') {
            return 'null';
        }
        $list = json_decode((string) $json, true);
        if (!is_array($list)) {
            return 'null';
        }
        $norm = array_map(static fn($r): string => json_encode($r), $list);
        sort($norm);
        return json_encode($norm);
    }

    /** @param list<mixed> $items */
    private static function set(array $items): string
    {
        $items = array_map('strval', $items);
        sort($items);
        return json_encode(array_values(array_unique($items)));
    }

    /**
     * Ownership after a row arrives from elsewhere (Rows from Elsewhere):
     * an unchanged field keeps its ownership; a changed one is its own when
     * it differs from the master's value, inherited when it equals it; a
     * row never seen, or with no list, by equality.
     *
     * @param array|null $stored the row as stored before this sync, null when new
     * @return list<string>
     */
    public static function afterSync(?array $stored, array $incoming, array $master): array
    {
        $known = $stored !== null ? self::decode($stored['override_fields'] ?? null) : null;
        if ($stored === null || $known === null) {
            return self::infer($incoming, $master);
        }
        $byEquality = self::infer($incoming, $master);
        $own = $known;
        $changed = self::changedFields($stored, $incoming);
        foreach ($changed as $field) {
            $own = array_values(array_diff($own, [$field]));
            if (in_array($field, $byEquality, true)) {
                $own[] = $field;
            }
        }
        return self::normalize($own);
    }

    /**
     * The fields whose values differ between two versions of one row. The
     * day, the clock with its zone and the length are each compared on
     * their own (Rows from Elsewhere): a move to another day at the same
     * clock time changes the date only, though its instants all differ.
     *
     * @return list<string>
     */
    public static function changedFields(array $a, array $b): array
    {
        $out = [];
        foreach (self::COLUMNS as $field => $cols) {
            if (!array_key_exists($cols[0], $b)) {
                continue;
            }
            if ($field === 'reminders' ? self::reminders($a['reminders_json'] ?? null) !== self::reminders($b['reminders_json'] ?? null)
                : (string) ($a[$cols[0]] ?? '') !== (string) ($b[$cols[0]] ?? '')) {
                $out[] = $field;
            }
        }
        $placed = static fn(array $r): bool => !empty($r['start_utc']) && !empty($r['end_utc']);
        if ($placed($a) && $placed($b)) {
            if (self::shownDay($a) !== self::shownDay($b)) {
                $out[] = 'date';
            }
            // On one day, the same clock and zone at another instant is the
            // other of a repeated hour's two instants: a new time too.
            if (self::clock($a) !== self::clock($b)
                || (self::shownDay($a) === self::shownDay($b) && (int) ($a['all_day'] ?? 0) === 0 && Time::fromDb((string) $a['start_utc'])->getTimestamp() !== Time::fromDb((string) $b['start_utc'])->getTimestamp())) {
                $out[] = 'time';
            }
            if (self::seconds($a) !== self::seconds($b)) {
                $out[] = 'length';
            }
        } elseif ($placed($a) !== $placed($b)) {
            array_push($out, 'date', 'time', 'length');
        }
        if ((int) ($a['all_day'] ?? 0) !== (int) ($b['all_day'] ?? 0)) {
            $out[] = 'allDay';
        }
        return self::normalize($out);
    }

    /** The day a row shows on: its local date, in UTC for an all-day row. */
    private static function shownDay(array $r): string
    {
        $tz = (int) ($r['all_day'] ?? 0) === 1 ? 'UTC' : (string) ($r['tzid'] ?? 'UTC');
        return Time::fromDb((string) $r['start_utc'])->setTimezone(Time::zone($tz))->format('Y-m-d');
    }

    /** A row's time: its clock and zone, none for an all-day row. */
    private static function clock(array $r): string
    {
        if ((int) ($r['all_day'] ?? 0) === 1) {
            return '';
        }
        $tz = Time::normalizeTzid((string) ($r['tzid'] ?? 'UTC'));
        return Time::fromDb((string) $r['start_utc'])->setTimezone(Time::zone($tz))->format('H:i:s') . ' ' . $tz;
    }

    private static function seconds(array $r): int
    {
        return Time::fromDb((string) $r['end_utc'])->getTimestamp() - Time::fromDb((string) $r['start_utc'])->getTimestamp();
    }
}
