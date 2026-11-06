<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Support\ExpansionBudget;
use BetterCal\Support\Limits;
use BetterCal\Support\Time;
use BetterCal\Support\WorkBudgetExceeded;

/**
 * The calendar's recurrence window: a series' occurrences in a window, read
 * from the app's one expander (Series\RuleIterator) in the event's zone,
 * with its skips and edited days applied, and the rule checks every writer
 * runs (validateRrule, inForm).
 */
final class Recurrence
{
    // A response-size guard, per series per query: 1,000 covers a daily
    // series across the widest window the API allows (two years), so the
    // app's own requests never hit it; a series that does (hourly, say) is
    // reported to the client (capped()), which asks for the rest, never cut
    // silently (#23). Counting paths read occurrences(), which has no cap.
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

    /** @var callable(array,\DateTimeImmutable,\DateTimeImmutable,?ExpansionBudget=):list<array{start:\DateTimeImmutable,end:\DateTimeImmutable}> */
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
    public function expand(
        array $master,
        array $overrides,
        \DateTimeImmutable $winStart,
        \DateTimeImmutable $winEnd,
        ?ExpansionBudget $budget = null,
    ): array
    {
        $result = [];
        $consumed = [];

        if (!empty($master['rrule'])) {
            $budget?->beginSeries(isset($master['id']) ? (int) $master['id'] : null);
        }

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
                $budget?->occurrence();
                $result[] = ['row' => $master, 'start' => $start, 'end' => $end, 'instanceUtc' => Time::toDb($start)];
            }
        } else {
            $exdates = [];
            // A row the expander can't read (Series\RuleNotReadable), whose
            // walk can't be completed (Series\WalkIncomplete) or whose skips
            // are quarantined over their limit (an InvalidArgumentException
            // from decodeExdates) takes the window's path for a rule it
            // can't read: the stored event alone, as safeRrule makes such a
            // rule on the way in, logged, so it never fails the whole window
            // or the reminder scan. Any other failure is a defect and
            // propagates.
            try {
                $exdates = array_fill_keys(self::decodeExdates($master['exdates_json'] ?? null), true);
                if (!\BetterCal\Domain\Series\Calc::reads((string) $master['rrule'])) {
                    throw new \BetterCal\Domain\Series\RuleNotReadable('the expander can\'t read the rule');
                }
                $raw = ($this->expander)($master, $winStart, $winEnd, $budget);
            } catch (\InvalidArgumentException|\BetterCal\Domain\Series\WalkIncomplete $e) {
                error_log('recurrence: event ' . ($master['id'] ?? '?') . ' could not be expanded: ' . $e->getMessage());
                $s0 = Time::fromDb((string) $master['start_utc']);
                $e0 = Time::fromDb((string) $master['end_utc']);
                $raw = ($s0 < $winEnd && $e0 > $winStart) ? [['start' => $s0, 'end' => $e0]] : [];
            }
            $count = 0;
            foreach ($raw as $inst) {
                $budget?->occurrence();
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
                // An override whose generated master instance was absent still
                // consumes aggregate result work.
                $budget?->occurrence();
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
        try {
            // The expander's own reading (Rule::table): what RFC 5545's table
            // marks N/A for the frequency is refused, never ignored.
            new \BetterCal\Domain\Series\RuleIterator($normalized, new \DateTimeImmutable('2026-01-01', Time::utc()));
        } catch (\BetterCal\Domain\Series\RuleNotReadable $e) {
            throw HttpError::badRequest('Invalid RRULE: ' . $e->getMessage(), 'invalid_rrule');
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

    /**
     * A rule in its series' own form (RFC 5545 3.3.10): UNTIL takes the
     * start's form, a date for an all-day series and a UTC date-time for a
     * timed one (a date there is the end of that day in the series' zone,
     * as the expander reads it), and an all-day series, whose start is a
     * date, has no clock parts (BYHOUR, BYMINUTE, BYSECOND). Every writer
     * writes a rule this way, and an all-day series' rule is read this way,
     * so one stored with clock parts is read with them left out. A rule
     * already in its form comes back as written.
     */
    public static function inForm(string $rrule, bool $allDay, ?string $tzid): string
    {
        // A rule outside the grammar (rruleParts' HttpError) is left as
        // written; every reader refuses it as one it can't read.
        try {
            $parts = self::rruleParts($rrule);
        } catch (HttpError) {
            return $rrule;
        }
        $changed = false;
        if ($allDay) {
            foreach (['BYHOUR', 'BYMINUTE', 'BYSECOND'] as $k) {
                if (isset($parts[$k])) {
                    unset($parts[$k]);
                    $changed = true;
                }
            }
        }
        $u = $parts['UNTIL'] ?? null;
        if ($u !== null && $allDay && preg_match('/^(\d{8})T(\d{6})(Z?)$/', $u, $m) === 1) {
            $at = $m[3] === 'Z' ? \DateTimeImmutable::createFromFormat('Ymd His', $m[1] . ' ' . $m[2], Time::utc()) : false;
            $parts['UNTIL'] = $at !== false ? $at->setTimezone(Time::zone($tzid))->format('Ymd') : $m[1];
            $changed = true;
        } elseif ($u !== null && !$allDay && preg_match('/^\d{8}$/', $u) === 1) {
            $end = \DateTimeImmutable::createFromFormat('Ymd H:i:s', $u . ' 23:59:59', Time::zone($tzid));
            if ($end !== false) {
                $parts['UNTIL'] = $end->setTimezone(Time::utc())->format('Ymd\THis\Z');
                $changed = true;
            }
        }
        return $changed ? self::joinParts($parts) : $rrule;
    }

    private static function joinParts(array $parts): string
    {
        $out = [];
        foreach ($parts as $k => $v) {
            $out[] = $k . '=' . $v;
        }
        return implode(';', $out);
    }

    /** The rule without its bounds (COUNT, UNTIL): what decides which days a series falls on. */
    public static function pattern(?string $rrule): string
    {
        $parts = self::rruleParts(strtoupper((string) $rrule));
        unset($parts['COUNT'], $parts['UNTIL']);
        ksort($parts);
        return self::joinParts($parts);
    }

    /** Event duration in seconds. */
    public static function durationSeconds(array $row): int
    {
        return max(0, Time::fromDb((string) $row['end_utc'])->getTimestamp() - Time::fromDb((string) $row['start_utc'])->getTimestamp());
    }

    /**
     * An RRULE from a feed, an import, CalDAV, Google or mail, made safe to
     * store and expand: null (no recurrence, so the event stays as a single
     * occurrence) when validateRrule refuses it, an INTERVAL or COUNT that
     * is not a small positive integer among them (scan 2026-09-23, F3/F4).
     * Tolerant by design: one bad event in a feed must not stop the rest
     * syncing, so a refused rule (HttpError, validateRrule's only refusal)
     * is no rule, and anything else is a defect that propagates.
     */
    public static function safeRrule(?string $rrule): ?string
    {
        $rrule = strtoupper(trim((string) $rrule));
        if ($rrule === '') {
            return null;
        }
        try {
            return self::validateRrule($rrule);
        } catch (HttpError) {
            return null;
        }
    }

    /**
     * The calendar's window over a series: occurrences() through the window,
     * at most one past MAX_INSTANCES, so expand() can tell a series that has
     * more and report it. A rule the expander can't read names no
     * occurrence, as in the model (Series\Calc gives it no keys); expand()
     * shows such a row as its stored event. The edit harness reads the
     * window by this name.
     *
     * @return list<array{start:\DateTimeImmutable,end:\DateTimeImmutable}>
     */
    public static function sabreExpand(
        array $master,
        \DateTimeImmutable $winStart,
        \DateTimeImmutable $winEnd,
        ?ExpansionBudget $budget = null,
    ): array
    {
        try {
            return self::occurrences($master, $winStart, $winEnd, $budget, self::MAX_INSTANCES + 1);
        } catch (\BetterCal\Domain\Series\RuleNotReadable) {
            return [];
        }
    }

    /**
     * Every raw occurrence of a stored series (no skips applied) that
     * overlaps [$from, $to), in order, at most $max: the expander's
     * (Series\RuleIterator) from the series' stored start, which reaches
     * $from by its own jump, so any window reads the days and keys the walk
     * from the start reads there. A rule the expander can't read throws
     * Series\RuleNotReadable and a walk that can't be completed
     * Series\WalkIncomplete; neither is ever a shorter series.
     *
     * @return list<array{start:\DateTimeImmutable,end:\DateTimeImmutable}>
     */
    public static function occurrences(
        array $master,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?ExpansionBudget $budget = null,
        int $max = PHP_INT_MAX,
    ): array
    {
        $it = self::iterator($master);
        $length = self::durationSeconds($master);
        // An occurrence overlaps the window when it ends after its start.
        $it->jumpTo($from->getTimestamp() - $length);
        $budget?->checkpoint();
        $out = [];
        while ($it->valid() && count($out) < $max) {
            $at = $it->current()->getTimestamp();
            if ($at >= $to->getTimestamp()) {
                break;
            }
            if ($at + $length > $from->getTimestamp()) {
                $out[] = ['start' => new \DateTimeImmutable('@' . $at), 'end' => new \DateTimeImmutable('@' . ($at + $length))];
            }
            $budget?->checkpoint();
            $it->next();
        }
        return $out;
    }

    /**
     * The expander over a stored series row: a timed series from the
     * instant it is stored at (a start in a repeated hour keeps its
     * instant), in its zone; an all-day series from its date at midnight in
     * its row's zone, as it is stored (Time::parseAllDay), its rule in its
     * own form, without clock parts.
     */
    public static function iterator(array $master): \BetterCal\Domain\Series\RuleIterator
    {
        $allDay = (int) ($master['all_day'] ?? 0) === 1;
        $tz = Time::zone($master['tzid'] ?? null);
        $start = Time::fromDb((string) $master['start_utc'])->setTimezone($tz);
        if ($allDay) {
            return new \BetterCal\Domain\Series\RuleIterator(self::inForm((string) $master['rrule'], true, 'UTC'), new \DateTimeImmutable($start->format('Y-m-d') . ' 00:00:00', $tz));
        }
        return new \BetterCal\Domain\Series\RuleIterator((string) $master['rrule'], $start);
    }
}
