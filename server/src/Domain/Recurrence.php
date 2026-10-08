<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Support\Limits;
use BetterCal\Support\Time;

/**
 * The single recurrence expansion engine. Expansion happens in the event's
 * tzid (wall-clock stable across DST) via sabre/vobject's EventIterator,
 * then converts to UTC. Hard-capped at MAX_INSTANCES per event per query.
 */
final class Recurrence
{
    // Per series per query. 1,000 covers a daily series across the widest
    // window the API allows (two years), so the app's own requests never hit
    // it; a series that does (hourly, say) is reported, not cut silently (#23).
    public const MAX_INSTANCES = 1000;
    public const MAX_RRULE_BYTES = 2048;
    public const MAX_RRULE_PARTS = 16;
    public const MAX_RRULE_VALUES = 366;
    public const MAX_RRULE_TOTAL_VALUES = 512;
    /** Maximum raw selector combinations an accepted rule may ask the iterator to consider. */
    public const MAX_RRULE_CANDIDATES = 4096;
    /** Largest INTERVAL / COUNT accepted from outside. Anything beyond is not a real calendar. */
    public const MAX_INTERVAL = 1000;
    public const MAX_COUNT = 100000;

    private const ALLOWED_RRULE_KEYS = [
        'FREQ', 'UNTIL', 'COUNT', 'INTERVAL', 'BYSECOND', 'BYMINUTE', 'BYHOUR',
        'BYDAY', 'BYMONTHDAY', 'BYYEARDAY', 'BYWEEKNO', 'BYMONTH', 'BYSETPOS', 'WKST',
    ];
    private const FREQS = ['SECONDLY', 'MINUTELY', 'HOURLY', 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    /** @var callable(array,\DateTimeImmutable,\DateTimeImmutable):list<array{start:\DateTimeImmutable,end:\DateTimeImmutable}> */
    private $expander;

    /** @var array<int, true> series cut short at MAX_INSTANCES by expand() */
    private array $capped = [];

    public function __construct(?callable $expander = null)
    {
        $this->expander = $expander ?? [self::class, 'sabreExpand'];
    }

    /** Ids of the series expand() has cut short at MAX_INSTANCES since this object was made. */
    public function capped(): array
    {
        return array_keys($this->capped);
    }

    /** Frozen contract: instanceId = eventId + ":" + occurrenceStartUtc (Ymd\THis\Z). */
    public static function instanceId(int|string $eventId, \DateTimeImmutable $startUtc): string
    {
        return $eventId . ':' . $startUtc->setTimezone(Time::utc())->format('Ymd\THis\Z');
    }

    /**
     * Number of comma-packed EXDATE values in one unfolded content line.
     * Raw entries count even when empty or duplicated: the parser still has to
     * split and attempt them before Better-Cal can discard them.
     */
    public static function exdateValueCount(string $line): int
    {
        $colon = strpos($line, ':');
        if ($colon === false) {
            return 0;
        }
        $head = strtoupper(trim(substr($line, 0, $colon)));
        $name = (string) strtok($head, ';');
        // Sabre accepts RFC-style grouped property names (foo.EXDATE). The
        // group is metadata; the final name is still the EXDATE property the
        // parser will materialize.
        $dot = strrpos($name, '.');
        if ($dot !== false) {
            $name = substr($name, $dot + 1);
        }
        if ($name !== 'EXDATE') {
            return 0;
        }
        $value = substr($line, $colon + 1);
        return $value === '' ? 0 : substr_count($value, ',') + 1;
    }

    /**
     * Canonical per-series boundary for every representation (ICS, Google,
     * REST, internal edits and stored JSON). Duplicate values still consume
     * the admission budget, then collapse for ordinary recurrence work.
     *
     * @return list<string>
     */
    public static function validateExdates(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $count = count($values);
        $max = Limits::get('EXDATE_VALUES_PER_EVENT');
        if ($count > $max) {
            throw new \InvalidArgumentException(
                'One event has ' . number_format($count) . ' skipped occurrences, over the limit of ' . number_format($max) . '. Split the series into smaller parts.'
            );
        }
        return array_values(array_unique(array_map('strval', $values)));
    }

    /**
     * Decode stored exdates without first materializing an unbounded legacy
     * array. Dates cannot contain commas, so this conservative precheck is
     * exact for every value Better-Cal stores.
     *
     * @return list<string>
     */
    public static function decodeExdates(mixed $encoded): array
    {
        if ($encoded === null || $encoded === '' || $encoded === []) {
            return [];
        }
        if (is_array($encoded)) {
            return self::validateExdates($encoded);
        }
        $raw = trim((string) $encoded);
        if ($raw === '' || $raw === '[]') {
            return [];
        }
        $estimate = self::encodedExdateValueCount($raw);
        $max = Limits::get('EXDATE_VALUES_PER_EVENT');
        if ($estimate > $max) {
            throw new \InvalidArgumentException(
                'Stored event has more than ' . number_format($max) . ' skipped occurrences and was quarantined from recurrence processing.'
            );
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? self::validateExdates($decoded) : [];
    }

    /** Raw stored-array cardinality without materializing the JSON values. */
    public static function encodedExdateValueCount(mixed $encoded): int
    {
        if ($encoded === null || $encoded === '' || $encoded === []) {
            return 0;
        }
        if (is_array($encoded)) {
            return count($encoded);
        }
        $raw = trim((string) $encoded);
        if ($raw === '' || $raw === '[]' || !str_starts_with($raw, '[') || !str_ends_with($raw, ']')) {
            return 0;
        }
        // Better-Cal stores only UTC date strings, which cannot contain a
        // comma. For a corrupt row this may over-count, which fails closed.
        return substr_count($raw, ',') + 1;
    }

    /** @param list<array<string,mixed>> $events */
    public static function assertExdateBatch(array $events): void
    {
        $total = 0;
        foreach ($events as $event) {
            $values = is_array($event['exdates'] ?? null) ? $event['exdates'] : [];
            self::validateExdates($values);
            $total += count($values);
            $max = Limits::get('EXDATE_VALUES_PER_INPUT');
            if ($total > $max) {
                throw new \InvalidArgumentException(
                    'This calendar input has more than ' . number_format($max) . ' skipped occurrences. Split it into smaller calendars.'
                );
            }
        }
    }

    /**
     * Expand a master event row into occurrences within [winStart, winEnd).
     * Overrides replace their instances; exdates suppress theirs. Overrides
     * that moved into the window from an out-of-window instance are appended.
     *
     * @param array<string,mixed> $master
     * @param list<array<string,mixed>> $overrides rows with recurrence_instance_utc
     * @return list<array{row:array,start:\DateTimeImmutable,end:\DateTimeImmutable,instanceUtc:string}>
     */
    public function expand(array $master, array $overrides, \DateTimeImmutable $winStart, \DateTimeImmutable $winEnd): array
    {
        $result = [];
        $consumed = [];

        $ovByInstance = [];
        foreach ($overrides as $ov) {
            if (!empty($ov['recurrence_instance_utc'])) {
                $ovByInstance[(string) $ov['recurrence_instance_utc']] = $ov;
            }
        }

        if (empty($master['rrule'])) {
            $start = Time::fromDb((string) $master['start_utc']);
            $end = Time::fromDb((string) $master['end_utc']);
            if ($start < $winEnd && $end > $winStart) {
                $result[] = ['row' => $master, 'start' => $start, 'end' => $end, 'instanceUtc' => Time::toDb($start)];
            }
        } else {
            $exdates = [];
            // One unexpandable row must never fail the whole window (or the
            // reminder scan): it shows as its first occurrence and is logged.
            try {
                $exdates = array_fill_keys(self::decodeExdates($master['exdates_json'] ?? null), true);
                $raw = ($this->expander)($master, $winStart, $winEnd);
            } catch (\Throwable $e) {
                error_log('recurrence: event ' . ($master['id'] ?? '?') . ' could not be expanded: ' . $e->getMessage());
                $s0 = Time::fromDb((string) $master['start_utc']);
                $e0 = Time::fromDb((string) $master['end_utc']);
                $raw = ($s0 < $winEnd && $e0 > $winStart) ? [['start' => $s0, 'end' => $e0]] : [];
            }
            $count = 0;
            foreach ($raw as $inst) {
                if (++$count > self::MAX_INSTANCES) {
                    $this->capped[(int) ($master['id'] ?? 0)] = true;
                    break;
                }
                $instanceUtc = Time::toDb($inst['start']);
                if (isset($exdates[$instanceUtc])) {
                    continue;
                }
                if (isset($ovByInstance[$instanceUtc])) {
                    $ov = $ovByInstance[$instanceUtc];
                    $consumed[$instanceUtc] = true;
                    $ovStart = Time::fromDb((string) $ov['start_utc']);
                    $ovEnd = Time::fromDb((string) $ov['end_utc']);
                    if ($ovStart < $winEnd && $ovEnd > $winStart) {
                        $result[] = ['row' => $ov, 'start' => $ovStart, 'end' => $ovEnd, 'instanceUtc' => $instanceUtc];
                    }
                    continue;
                }
                $result[] = ['row' => $master, 'start' => $inst['start'], 'end' => $inst['end'], 'instanceUtc' => $instanceUtc];
            }
        }

        foreach ($ovByInstance as $instanceUtc => $ov) {
            if (isset($consumed[$instanceUtc])) {
                continue;
            }
            $ovStart = Time::fromDb((string) $ov['start_utc']);
            $ovEnd = Time::fromDb((string) $ov['end_utc']);
            if ($ovStart < $winEnd && $ovEnd > $winStart) {
                $result[] = ['row' => $ov, 'start' => $ovStart, 'end' => $ovEnd, 'instanceUtc' => (string) $instanceUtc];
            }
        }

        return $result;
    }

    /** Validate an RRULE string; throws HttpError(invalid_rrule) on failure. Returns normalized form. */
    public static function validateRrule(string $rrule): string
    {
        $rrule = strtoupper(trim($rrule));
        if ($rrule === '') {
            throw HttpError::badRequest('Empty RRULE', 'invalid_rrule');
        }
        if (strlen($rrule) > self::MAX_RRULE_BYTES) {
            throw HttpError::badRequest('RRULE is too long', 'invalid_rrule');
        }
        $parts = self::rruleParts($rrule);
        if (!isset($parts['FREQ']) || !in_array($parts['FREQ'], self::FREQS, true)) {
            throw HttpError::badRequest('RRULE must contain a valid FREQ', 'invalid_rrule');
        }
        foreach ($parts as $key => $value) {
            if (!in_array($key, self::ALLOWED_RRULE_KEYS, true)) {
                throw HttpError::badRequest("Unknown RRULE part: $key", 'invalid_rrule');
            }
            if ($value === '') {
                throw HttpError::badRequest("Empty RRULE value for $key", 'invalid_rrule');
            }
        }
        if (isset($parts['INTERVAL'])) {
            $interval = $parts['INTERVAL'];
            if (!ctype_digit($interval) || strlen($interval) > 6) {
                throw HttpError::badRequest('Invalid RRULE INTERVAL', 'invalid_rrule');
            }
            if ((int) $interval < 1 || (int) $interval > self::MAX_INTERVAL) {
                throw HttpError::badRequest('RRULE INTERVAL is too large', 'invalid_rrule');
            }
        }
        if (isset($parts['COUNT'])) {
            $count = $parts['COUNT'];
            if (!ctype_digit($count) || strlen($count) > 6) {
                throw HttpError::badRequest('Invalid RRULE COUNT', 'invalid_rrule');
            }
            if ((int) $count < 1 || (int) $count > self::MAX_COUNT) {
                throw HttpError::badRequest('RRULE COUNT is too large', 'invalid_rrule');
            }
        }
        self::validatePartValues($parts);
        $normalized = self::joinParts($parts);
        if (class_exists(\Sabre\VObject\Recur\RRuleIterator::class)) {
            try {
                new \Sabre\VObject\Recur\RRuleIterator($normalized, new \DateTimeImmutable('2026-01-01', Time::utc()));
            } catch (\Throwable $e) {
                throw HttpError::badRequest('Invalid RRULE: ' . $e->getMessage(), 'invalid_rrule');
            }
        }
        return $normalized;
    }

    /** @return array<string,string> */
    public static function rruleParts(string $rrule): array
    {
        if (strlen($rrule) > self::MAX_RRULE_BYTES) {
            throw HttpError::badRequest('RRULE is too long', 'invalid_rrule');
        }
        $parts = [];
        $pieces = explode(';', strtoupper(trim($rrule)));
        if (count($pieces) > self::MAX_RRULE_PARTS) {
            throw HttpError::badRequest('RRULE has too many parts', 'invalid_rrule');
        }
        foreach ($pieces as $piece) {
            if ($piece === '') {
                continue;
            }
            if (!str_contains($piece, '=')) {
                throw HttpError::badRequest("Malformed RRULE part: $piece", 'invalid_rrule');
            }
            [$k, $v] = explode('=', $piece, 2);
            $k = trim($k);
            if (array_key_exists($k, $parts)) {
                throw HttpError::badRequest("Duplicate RRULE part: $k", 'invalid_rrule');
            }
            $parts[$k] = trim($v);
        }
        return $parts;
    }

    /** @param array<string,string> $parts */
    private static function validatePartValues(array $parts): void
    {
        $total = 0;
        $candidateProduct = 1;
        $numericRanges = [
            'BYSECOND' => [0, 60], 'BYMINUTE' => [0, 59], 'BYHOUR' => [0, 23],
            'BYMONTHDAY' => [-31, 31], 'BYYEARDAY' => [-366, 366],
            'BYWEEKNO' => [-53, 53], 'BYMONTH' => [1, 12], 'BYSETPOS' => [-366, 366],
        ];
        foreach ($parts as $key => $value) {
            if (!str_starts_with($key, 'BY')) {
                continue;
            }
            $values = explode(',', $value);
            $total += count($values);
            if (count($values) > self::MAX_RRULE_VALUES || $total > self::MAX_RRULE_TOTAL_VALUES) {
                throw HttpError::badRequest('RRULE has too many list values', 'invalid_rrule');
            }
            // Output caps do not bound the iterator's work: BY* lists are
            // combined before BYSETPOS and before occurrences are yielded.
            // Bound that cross-product without multiplying past PHP_INT_MAX.
            // BYSETPOS selects from candidates rather than creating them.
            if ($key !== 'BYSETPOS') {
                $count = count($values);
                if ($candidateProduct > intdiv(self::MAX_RRULE_CANDIDATES, $count)) {
                    throw HttpError::badRequest('RRULE creates too many candidate combinations', 'invalid_rrule');
                }
                $candidateProduct *= $count;
            }
            foreach ($values as $item) {
                if ($item === '') {
                    throw HttpError::badRequest("Invalid RRULE $key value", 'invalid_rrule');
                }
                if ($key === 'BYDAY') {
                    if (preg_match('/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', $item, $m) !== 1
                        || (isset($m[1]) && $m[1] !== '' && ((int) $m[1] === 0 || abs((int) $m[1]) > 53))
                    ) {
                        throw HttpError::badRequest('Invalid RRULE BYDAY value', 'invalid_rrule');
                    }
                    continue;
                }
                [$min, $max] = $numericRanges[$key] ?? [null, null];
                if ($min === null || preg_match('/^[+-]?\d+$/', $item) !== 1) {
                    throw HttpError::badRequest("Invalid RRULE $key value", 'invalid_rrule');
                }
                $number = (int) $item;
                if ($number < $min || $number > $max || ($number === 0 && !in_array($key, ['BYSECOND', 'BYMINUTE', 'BYHOUR'], true))) {
                    throw HttpError::badRequest("Invalid RRULE $key value", 'invalid_rrule');
                }
            }
        }
        if (isset($parts['WKST']) && preg_match('/^(MO|TU|WE|TH|FR|SA|SU)$/', $parts['WKST']) !== 1) {
            throw HttpError::badRequest('Invalid RRULE WKST', 'invalid_rrule');
        }
        if (isset($parts['UNTIL']) && preg_match('/^\d{8}(T\d{6}Z?)?$/', $parts['UNTIL']) !== 1) {
            throw HttpError::badRequest('Invalid RRULE UNTIL', 'invalid_rrule');
        }
    }

    private static function joinParts(array $parts): string
    {
        $out = [];
        foreach ($parts as $k => $v) {
            $out[] = $k . '=' . $v;
        }
        return implode(';', $out);
    }

    /**
     * Days from a weekly series' start to the first day its own BYDAY lists
     * (0 when it already is one, or the rule is not a plain weekly BYDAY).
     * A start on an unlisted day is undefined in RFC 5545 and calendar apps
     * disagree on it (sabre counts it, others drop it), so the series starts
     * on its first real day instead (0.9.15).
     */
    public static function daysToFirstByday(string $rrule, \DateTimeImmutable $localStart): int
    {
        $parts = self::rruleParts(strtoupper($rrule));
        if (($parts['FREQ'] ?? '') !== 'WEEKLY' || empty($parts['BYDAY'])) {
            return 0;
        }
        $days = array_filter(explode(',', $parts['BYDAY']), static fn(string $d): bool => preg_match('/^(MO|TU|WE|TH|FR|SA|SU)$/', $d) === 1);
        if ($days === []) {
            return 0;
        }
        for ($i = 0; $i < 7; $i++) {
            if (in_array(strtoupper(substr($localStart->modify('+' . $i . ' days')->format('D'), 0, 2)), $days, true)) {
                return $i;
            }
        }
        return 0;
    }

    /**
     * A weekly rule's plain BYDAY days moved by $days ("every Monday" moved a
     * day later is "every Tuesday"); anything else unchanged.
     */
    public static function shiftByday(string $rrule, int $days): string
    {
        $parts = self::rruleParts($rrule);
        if (($parts['FREQ'] ?? '') !== 'WEEKLY' || empty($parts['BYDAY']) || $days % 7 === 0) {
            return $rrule;
        }
        $week = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
        $out = [];
        foreach (explode(',', $parts['BYDAY']) as $d) {
            $i = array_search($d, $week, true);
            if ($i === false) {
                return $rrule; // an nth-weekday form: leave the rule alone
            }
            $out[] = (((int) $i + $days) % 7 + 7) % 7;
        }
        sort($out);
        $parts['BYDAY'] = implode(',', array_map(static fn(int $i): string => $week[$i], array_unique($out)));
        return self::joinParts($parts);
    }

    /** The rule without its bounds (COUNT, UNTIL): what decides which days a series falls on. */
    public static function pattern(?string $rrule): string
    {
        $parts = self::rruleParts(strtoupper((string) $rrule));
        unset($parts['COUNT'], $parts['UNTIL']);
        ksort($parts);
        return self::joinParts($parts);
    }

    /**
     * Replace COUNT with an UNTIL bound (used for "following" splits). An
     * all-day UNTIL is a date, taken in the series' own zone like its
     * occurrences (sabreExpand); in UTC, a series west of UTC kept the day it
     * was split at.
     */
    public static function setUntil(string $rrule, \DateTimeImmutable $untilUtc, bool $allDay, ?string $tzid = null): string
    {
        $parts = self::rruleParts($rrule);
        unset($parts['COUNT']);
        $parts['UNTIL'] = $allDay
            ? $untilUtc->setTimezone(Time::zone($tzid))->format('Ymd')
            : $untilUtc->setTimezone(Time::utc())->format('Ymd\THis\Z');
        return self::joinParts($parts);
    }

    /** UNTIL bound for the old master when splitting a series before $instanceUtc. */
    public static function splitUntil(\DateTimeImmutable $instanceUtc): \DateTimeImmutable
    {
        return $instanceUtc->sub(new \DateInterval('PT1S'));
    }

    /** Event duration in seconds. */
    public static function durationSeconds(array $row): int
    {
        return max(0, Time::fromDb((string) $row['end_utc'])->getTimestamp() - Time::fromDb((string) $row['start_utc'])->getTimestamp());
    }

    /**
     * How many whole rule periods DTSTART can move forward without leaving the
     * window's first occurrence behind — or 0 when the rule is not one we can
     * safely skip through.
     *
     * sabre's EventIterator only walks forward: fastForward() steps one
     * occurrence at a time from DTSTART until it reaches the target. For a
     * daily series begun years ago that is thousands of iterations on every
     * request, and it grows by one a day forever. Measured on real data, that
     * walk was 86% of expansion time and expansion was 87% of the whole events
     * query.
     *
     * Moving DTSTART by a WHOLE multiple of the rule's period preserves the
     * rule's phase exactly, so the instants sabre then generates are identical
     * — this trades no correctness for the speedup. Deliberately conservative:
     *
     *  - Only DAILY and WEEKLY. MONTHLY/YEARLY iterate at most ~12 times a
     *    year, so their walk is already cheap (measured: about a hundred
     *    monthly masters cost tens of milliseconds in all), and month
     *    arithmetic has end-of-month traps.
     *  - Never with COUNT: the rule means "N occurrences from DTSTART", so
     *    moving DTSTART would silently change which instances exist. UNTIL is
     *    an absolute bound and is unaffected.
     *  - WEEKLY moves in whole INTERVAL-week blocks, which keeps every BYDAY
     *    position and the WKST-relative phase intact.
     *  - Lands at or BEFORE the target, never past it, and leaves the last
     *    partial period for sabre. Being approximately close is enough; the
     *    remaining walk is a handful of steps.
     *
     * @return int periods to advance (0 = leave DTSTART alone)
     */
    /**
     * An RRULE from a feed, an import, CalDAV, Google or mail, made safe to
     * store and expand: null (no recurrence, so the event stays as a single
     * occurrence) when INTERVAL or COUNT is not a small positive integer.
     * An absurd INTERVAL used to overflow to a float in skippablePeriods and
     * throw on every events request, blanking the calendar (scan
     * 2026-09-23, F3/F4). Tolerant by design: one bad event in a feed must
     * not stop the rest syncing, so this never throws.
     */
    public static function safeRrule(?string $rrule): ?string
    {
        $rrule = strtoupper(trim((string) $rrule));
        if ($rrule === '') {
            return null;
        }
        try {
            return self::validateRrule($rrule);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function skippablePeriods(string $rrule, \DateTimeImmutable $dtStart, \DateTimeImmutable $target): int
    {
        if ($target <= $dtStart) {
            return 0;
        }
        $parts = [];
        foreach (explode(';', strtoupper($rrule)) as $bit) {
            $kv = explode('=', $bit, 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }
        if (isset($parts['COUNT'])) {
            return 0;
        }
        $freq = $parts['FREQ'] ?? '';
        if ($freq !== 'DAILY' && $freq !== 'WEEKLY') {
            return 0;
        }
        $rawInterval = $parts['INTERVAL'] ?? '1';
        if (!ctype_digit($rawInterval) || strlen($rawInterval) > 6) {
            return 0;
        }
        $interval = max(1, (int) $rawInterval);
        if ($interval > self::MAX_INTERVAL) {
            return 0; // rows stored before safeRrule existed: no skipping, never overflow
        }
        $daysPerPeriod = ($freq === 'WEEKLY' ? 7 : 1) * $interval;

        // Whole days between the two, floored — computed on calendar dates so
        // a DST shift inside the span cannot round the count down by one.
        $days = (int) $dtStart->setTime(0, 0)->diff($target->setTime(0, 0))->format('%a');
        $periods = intdiv($days, $daysPerPeriod);
        // Give back one period as slack, so rounding can never overshoot the
        // first in-window occurrence.
        return max(0, $periods - 1);
    }

    /**
     * Default expander: sabre/vobject EventIterator, DTSTART in the event tzid.
     *
     * @return list<array{start:\DateTimeImmutable,end:\DateTimeImmutable}>
     */
    public static function sabreExpand(array $master, \DateTimeImmutable $winStart, \DateTimeImmutable $winEnd): array
    {
        if (!class_exists(\Sabre\VObject\Component\VCalendar::class)) {
            throw new \RuntimeException('sabre/vobject is not installed');
        }
        $allDay = (int) ($master['all_day'] ?? 0) === 1;
        $tz = Time::zone($master['tzid'] ?? null);
        $start = Time::fromDb((string) $master['start_utc'])->setTimezone($tz);
        $end = Time::fromDb((string) $master['end_utc'])->setTimezone($tz);
        $uid = (string) ($master['uid'] ?? 'bettercal-expand');

        // Start the iterator near the window instead of at the series origin,
        // where that is provably equivalent (see skippablePeriods). Both ends
        // move together so the duration is untouched, and the arithmetic runs
        // in the event's own zone so wall-clock time survives DST.
        $rrule = (string) $master['rrule'];
        $skip = self::skippablePeriods($rrule, $start, $winStart->setTimezone($tz));
        if ($skip > 0) {
            $freq = str_contains(strtoupper($rrule), 'FREQ=WEEKLY') ? 'WEEKLY' : 'DAILY';
            preg_match('/INTERVAL=(\d+)/', strtoupper($rrule), $m);
            $interval = max(1, (int) ($m[1] ?? 1));
            $shift = new \DateInterval('P' . ($skip * $interval * ($freq === 'WEEKLY' ? 7 : 1)) . 'D');
            $start = $start->add($shift);
            $end = $end->add($shift);
        }

        $vcal = new \Sabre\VObject\Component\VCalendar();
        $vevent = $vcal->add('VEVENT', ['UID' => $uid]);
        if ($allDay) {
            $vevent->add('DTSTART', $start->format('Ymd'), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $end->format('Ymd'), ['VALUE' => 'DATE']);
        } else {
            $vevent->add('DTSTART', $start);
            $vevent->add('DTEND', $end);
        }
        $vevent->add('RRULE', (string) $master['rrule']);

        $out = [];
        try {
            // All-day dates are midnights in the event's own zone, as they are
            // stored (Time::parseAllDay). Read in UTC, a series in a zone west
            // of UTC came out a day early: midnight UTC is the evening before.
            // A UTC-zoned series, as imports and CalDAV store them, is unchanged.
            $it = new \Sabre\VObject\Recur\EventIterator($vcal, $uid, $allDay ? $tz : Time::utc());
            $it->fastForward(\DateTime::createFromImmutable($winStart));
            $count = 0;
            // One past the cap, so expand() can tell a series that has more.
            while ($it->valid() && $count <= self::MAX_INSTANCES) {
                $occStart = \DateTimeImmutable::createFromInterface($it->getDtStart())->setTimezone(Time::utc());
                if ($occStart >= $winEnd) {
                    break;
                }
                $occEnd = \DateTimeImmutable::createFromInterface($it->getDtEnd())->setTimezone(Time::utc());
                $out[] = ['start' => $occStart, 'end' => $occEnd];
                $count++;
                $it->next();
            }
        } catch (\Throwable $e) {
            error_log('recurrence expansion failed for event ' . ($master['id'] ?? '?') . ': ' . $e->getMessage());
        }
        return $out;
    }
}
