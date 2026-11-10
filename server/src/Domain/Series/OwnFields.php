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
     * field it set (series-model.md, Own Fields), by kind: its trip (the
     * event_links naming the row as a trip or as one of a trip's stops), its
     * duplicate decisions (an event_duplicates pair settled as linked or
     * dismissed; a possible pair is the scan's proposal, which it makes
     * again, not a decision) and its plugin data (event_plugin_data rows).
     * A row exists for its own fields and for whatever refers to it, so a
     * day something names never goes back to the series while it is named,
     * and a rejoin never cascade-deletes what names the row. Each kind is
     * [table, the columns naming its reference, the columns naming events,
     * a condition, the table's key]; read each time (references), never
     * stored in override_fields. The same table is what Undo keeps of a
     * row it removes and puts back with it (Undo::linksOf): what refers to
     * a row goes when the row goes (each cascades on delete), so it comes
     * back when the row does.
     */
    public const REFERENCES = [
        'trip' => ['event_links', ['id'], ['container_id', 'event_id'], null, ['id']],
        'duplicates' => ['event_duplicates', ['id'], ['event_a', 'event_b'], "status IN ('linked', 'dismissed')", ['id']],
        'pluginData' => ['event_plugin_data', ['plugin_id', 'k'], ['event_id'], null, ['event_id', 'plugin_id', 'k']],
    ];

    /**
     * What refers to each of $ids, every kind above read by the one query:
     * [id => list of "kind:reference"], in order, so a plan's hash covers
     * each one.
     *
     * @param list<int> $ids
     * @return array<int,list<string>>
     */
    public static function references(\BetterCal\Infra\Db $db, array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            [$in, $params] = \BetterCal\Infra\Db::in($chunk);
            foreach (self::REFERENCES as $kind => [$table, $refCols, $eventCols, $condition]) {
                $where = '(' . implode(' OR ', array_map(static fn(string $c): string => "`$c` IN $in", $eventCols)) . ')' . ($condition === null ? '' : " AND $condition");
                $order = implode(', ', array_map(static fn(string $c): string => "`$c`", $refCols));
                $rows = $db->all("SELECT * FROM `$table` WHERE $where ORDER BY $order", array_merge(...array_fill(0, count($eventCols), $params)));
                foreach ($rows as $r) {
                    $ref = $kind . ':' . implode('/', array_map(static fn(string $c): string => (string) $r[$c], $refCols));
                    foreach (array_unique(array_map(static fn(string $c): int => (int) $r[$c], $eventCols)) as $rid) {
                        if (in_array($rid, $chunk, true)) {
                            $out[$rid][] = $ref;
                        }
                    }
                }
            }
        }
        return $out;
    }

    /** The link tables of a day's tags and people, by own field: [table, the column naming what is linked]. */
    public const LINKS = ['tags' => ['event_tags', 'tag_id'], 'people' => ['event_people', 'person_id']];

    /**
     * A day's tags and people follow its series (series-model.md, Own
     * Fields): every live day row of series $masterId (or the ones $dayIds
     * names) whose own list does not name a field of $fields holds the
     * series' set for it as the series has it now. The one function every
     * writer calls, in its transaction, once it has made, brought back,
     * re-listed or re-parented a day's row or written a series' links (the
     * link setters, Labels::setEventTags and replaceEventPeople, call it for
     * the event they set): a day whose list names the field keeps its own
     * set; a day with no list at all is left as it is (a form no writer here
     * leaves; the rows a feed made before 1.5.5 under a master it did not
     * carry are the owner's to decide); a series gone or deleted has no set
     * to follow, and nothing is done. Only a set that differs is written.
     * Returns the ids of the days it changed.
     *
     * @param ?list<int> $dayIds
     * @param list<string> $fields
     * @return list<int>
     */
    public static function follow(\BetterCal\Infra\Db $db, int $masterId, ?array $dayIds = null, array $fields = ['tags', 'people']): array
    {
        if ($dayIds === [] || $db->scalar('SELECT 1 FROM events WHERE id = ? AND recurrence_parent_id IS NULL AND deleted_at IS NULL', [$masterId]) === null) {
            return [];
        }
        $sql = 'SELECT id, override_fields FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc IS NOT NULL AND deleted_at IS NULL';
        $days = [];
        foreach ($dayIds === null ? [null] : array_chunk(array_values(array_unique(array_map('intval', $dayIds))), 500) as $chunk) {
            [$in, $params] = $chunk === null ? ['', []] : \BetterCal\Infra\Db::in($chunk);
            foreach ($db->all($sql . ($chunk === null ? '' : " AND id IN $in"), array_merge([$masterId], $params)) as $r) {
                $days[(int) $r['id']] = self::decode($r['override_fields'] ?? null);
            }
        }
        $changed = [];
        foreach (array_intersect_key(self::LINKS, array_flip($fields)) as $field => [$table, $col]) {
            $follows = array_keys(array_filter($days, static fn(?array $own): bool => $own !== null && !in_array($field, $own, true)));
            if ($follows === []) {
                continue;
            }
            $set = array_map('intval', array_column($db->all("SELECT `$col` FROM `$table` WHERE event_id = ? ORDER BY `$col`", [$masterId]), $col));
            foreach (array_chunk($follows, 500) as $chunk) {
                [$in, $params] = \BetterCal\Infra\Db::in($chunk);
                $has = array_fill_keys($chunk, []);
                foreach ($db->all("SELECT event_id, `$col` FROM `$table` WHERE event_id IN $in ORDER BY event_id, `$col`", $params) as $r) {
                    $has[(int) $r['event_id']][] = (int) $r[$col];
                }
                foreach ($has as $dayId => $now) {
                    if ($now === $set) {
                        continue;
                    }
                    $db->run("DELETE FROM `$table` WHERE event_id = ?", [$dayId]);
                    $db->run($db->insertIgnore() . " INTO `$table` (event_id, `$col`) SELECT ?, `$col` FROM `$table` WHERE event_id = ?", [$dayId, $masterId]);
                    $changed[$dayId] = true;
                }
            }
        }
        return array_keys($changed);
    }

    /**
     * An edited day's own things: the fields it owns and each kind of thing
     * that refers to it ($references, "kind:reference" entries as
     * references() gives them). Empty only for a day with nothing of its
     * own, the one kind of row that goes back to the series.
     *
     * @param list<string> $fields
     * @param list<string> $references
     * @return list<string>
     */
    public static function own(array $fields, array $references): array
    {
        $kinds = array_map(static fn(string $r): string => explode(':', $r, 2)[0], $references);
        return self::normalize(array_merge(array_diff($fields, array_keys(self::REFERENCES)), $kinds));
    }

    /**
     * The one list of what an edited day holds here alone, which no feed,
     * Google or ICS, carries: these own fields, and a place's coordinates
     * (COORDINATE_COLUMNS: a feed carries a place's text, never where it
     * is). Read by the sync (Feeds::retained, recordHolds), by recovery's
     * carry-over (Feeds::retained), by the gate on a read-only
     * feed's day (feedEditKeys) and checked against what Undo holds as
     * Google's (Undo::GOOGLE_HELD, whose complement these columns are),
     * so the four can't drift.
     */
    public const HELD_HERE = ['reminders', 'attendance', 'tags', 'people', 'icon'];

    /**
     * The fields Google holds of an event: every field but those held here
     * alone (HELD_HERE). The one allowed list a Google request body is
     * built from (GoogleWriter::allowedKeys, GoogleSeries' days), so a field
     * held here can't reach Google by construction.
     *
     * @return list<string>
     */
    public static function googleFields(): array
    {
        return array_values(array_diff(self::FIELDS, self::HELD_HERE));
    }

    /**
     * The fields of an own list Google holds (googleFields): none for a day
     * with only local data, an ordinary day to every Google write and undo.
     *
     * @param list<string> $own
     * @return list<string>
     */
    public static function googleHeld(array $own): array
    {
        return array_values(array_intersect($own, self::googleFields()));
    }

    /** The place's columns no feed carries: its coordinates and where they came from. */
    public const COORDINATE_COLUMNS = ['location_lat', 'location_lng', 'location_source', 'location_provider', 'location_placed_at', 'geocoded_at'];

    /** The request keys that set each field held here alone (attendance is answered by its own path, and an icon is set by whoever made the event, a plugin or quick add: no patch key sets either). */
    private const HELD_HERE_INPUT = ['reminders' => ['reminders'], 'attendance' => [], 'tags' => ['tagNames'], 'people' => ['personIds', 'personNames'], 'icon' => []];

    /** The request keys that set a place's coordinates. */
    private const COORDINATE_INPUT = ['locationLat', 'locationLng', 'locationSource', 'locationProvider'];

    /**
     * A request's keys for what is held here alone, which a day of a
     * read-only (ICS) feed may still be given: every field of HELD_HERE,
     * read from the list, and a place's coordinates.
     *
     * @return list<string>
     */
    public static function feedEditKeys(): array
    {
        $out = self::COORDINATE_INPUT;
        foreach (self::HELD_HERE as $field) {
            $out = array_merge($out, self::HELD_HERE_INPUT[$field] ?? throw new \LogicException("no request key for the held-here field $field"));
        }
        return $out;
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

    /** The own fields that place a day: its date, clock, length and all-day setting, which are one value of it (its times). */
    public const PLACEMENT = ['date', 'time', 'length', 'allDay'];

    /** The columns an own field writes: its content columns, or, a field placing the day, its times. */
    public static function columnsOf(string $field): array
    {
        return in_array($field, self::PLACEMENT, true) ? ['start_utc', 'end_utc', 'all_day', 'tzid'] : (self::COLUMNS[$field] ?? []);
    }

    /** An own field as a ground of its day (Undo::touchesOf): a field placing the day is its times, one ground ("when"); any other is itself. */
    public static function groundOf(string $field): string
    {
        return in_array($field, self::PLACEMENT, true) ? 'when' : $field;
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
        return json_encode(self::normalize(array_diff($list, array_keys(self::REFERENCES))));
    }

    /** @param list<mixed> $list @return list<string> */
    public static function normalize(array $list): array
    {
        $out = [];
        foreach ([...self::FIELDS, ...array_keys(self::REFERENCES)] as $f) {
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
            $shownDay = Time::dayOf($start, $rowAllDay, $tzR);
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
            $sameZone = Time::normalizeTzid((string) $row['tzid']) === Time::normalizeTzid((string) $master['tzid']);
            $series = null;
            if (!$mAllDay && $sameZone) {
                $series = $key !== '' && !in_array('date', $own, true) ? Time::fromDb($key)
                    : Calc::at(Cal::dnOf($start->setTimezone($tzR)), self::seriesClock($master, $key), (string) $master['tzid']);
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

    /**
     * Where a stored edited day starts and ends by its own list (Own Fields):
     * on its own date where it owns one, else its occurrence's day; at its
     * own clock in its own zone where it owns its time, else at the series'
     * clock in the series' zone; the wall clock read by the one rule (a
     * skipped clock is the clock plus the gap, a repeated one the instant
     * the row names, else the first). Its length is its own. A day that owns
     * neither is at its key. An all-day row, a row of an all-day series and
     * a row with no key are returned as they are.
     *
     * @param list<string> $own
     */
    public static function placed(array $row, array $master, array $own): array
    {
        $key = (string) ($row['recurrence_instance_utc'] ?? '');
        if ($key === '' || (int) ($row['all_day'] ?? 0) === 1 || (int) ($master['all_day'] ?? 0) === 1 || empty($row['start_utc']) || empty($row['end_utc'])) {
            return $row;
        }
        $ownDate = in_array('date', $own, true);
        $ownTime = in_array('time', $own, true);
        $start = Time::fromDb((string) $row['start_utc']);
        $len = Time::fromDb((string) $row['end_utc'])->getTimestamp() - $start->getTimestamp();
        $tz = $ownTime ? (string) $row['tzid'] : (string) $master['tzid'];
        if (!$ownDate && !$ownTime) {
            $at = Time::fromDb($key);
        } else {
            $dn = $ownDate ? Cal::dnOf($start->setTimezone(Time::zone((string) $row['tzid'])))
                : Cal::dnOf(Time::fromDb($key)->setTimezone(Time::zone((string) $master['tzid'])));
            $clock = $ownTime ? self::clockOf($start->setTimezone(Time::zone($tz))) : self::seriesClock($master, $key);
            $at = Calc::at($dn, $clock, $tz, $start);
        }
        $out = $row;
        if ($at->getTimestamp() !== $start->getTimestamp()) {
            $out['start_utc'] = Time::toDb($at);
            $out['end_utc'] = Time::toDb($at->modify('+' . $len . ' seconds'));
        }
        if (Time::normalizeTzid((string) $row['tzid']) !== Time::normalizeTzid($tz)) {
            $out['tzid'] = $tz;
        }
        return $out;
    }

    /**
     * The series' clock an edited day inherits: the series' one clock, read
     * from its start in its zone; for a rule several times a day, which has
     * no one clock, its occurrence's own.
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function seriesClock(array $master, string $key): array
    {
        $zone = Time::zone((string) $master['tzid']);
        $mStart = Time::fromDb((string) $master['start_utc'])->setTimezone($zone);
        $several = false;
        try {
            $several = Rule::parse((string) ($master['rrule'] ?? ''), Cal::dnOf($mStart))->several;
        } catch (\Throwable) {
        }
        return self::clockOf($several && $key !== '' ? Time::fromDb($key)->setTimezone($zone) : $mStart);
    }

    /** @return array{0:int,1:int,2:int} */
    private static function clockOf(\DateTimeImmutable $local): array
    {
        return [(int) $local->format('G'), (int) $local->format('i'), (int) $local->format('s')];
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
        // The place is its text with its coordinates: a day given other
        // coordinates at the same text has a place of its own (Own Fields).
        foreach (['location_lat', 'location_lng'] as $col) {
            if (!in_array('location', $out, true) && array_key_exists($col, $a) && array_key_exists($col, $b) && self::coordinate($a[$col]) !== self::coordinate($b[$col])) {
                $out[] = 'location';
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

    /** A coordinate as compared: null when unset, else to nine decimals (a number sent and the value stored back are the same place). */
    private static function coordinate(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : number_format((float) $value, 9, '.', '');
    }

    /** The day a row shows on (Time::dayOf). */
    private static function shownDay(array $r): string
    {
        return Time::dayOf(Time::fromDb((string) $r['start_utc']), (int) ($r['all_day'] ?? 0) === 1, (string) ($r['tzid'] ?? 'UTC'));
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
