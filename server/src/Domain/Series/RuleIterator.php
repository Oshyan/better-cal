<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

/**
 * The app's expander of recurrence rules, written from RFC 5545 3.3.10's
 * table; every expansion in the app reads it (Calc for the model, the
 * window in Recurrence, mail admission's activity estimate), so a day and
 * its key are the same wherever they are read.
 *
 * A rule is a walk over the periods of its FREQ, stepped by INTERVAL from
 * the start's own period: a year, a month, a week from WKST, a day, a wall
 * clock hour, or an elapsed minute or second. Each period's set is a pure
 * function of the rule, the start and the period's number (periodCandidates),
 * so the walk carries no state from where it began: a window that starts at
 * any instant reads what the walk from the start reads there (jumpTo, which
 * starts at the period holding the earliest wall clock that can name an
 * instant in the window, seekPeriod). Nothing is expanded until the walk is
 * stepped, and a period's set is produced as it is walked, so memory is one
 * period's, never a year's (BYSETPOS holds the period's instants, that
 * period only).
 *
 *  - A period's days are the days in it that every day part admits: an
 *    expand and a limit both keep the members that match, so the table's
 *    expand-then-limit is read as one test per day (admits). Where no part
 *    names a day, the start's own is implied (a weekly rule's weekday, a
 *    monthly rule's day of the month, a yearly rule's month and day, a
 *    BYWEEKNO rule's weekday), and a day a month or year doesn't have (30
 *    February) is no day: not an occurrence and not counted.
 *  - A numbered weekday is counted in the month for MONTHLY and for YEARLY
 *    with BYMONTH, in the year otherwise; BYWEEKNO's weeks are numbered from
 *    WKST, week 1 the first with four days of the year, a negative number
 *    from the year's last week, and its period is that numbering's year.
 *  - Clock parts expand or limit by the table: for DAILY and coarser,
 *    BYHOUR, BYMINUTE and BYSECOND expand each day into wall clocks, the
 *    start's own hour, minute and second where a part is absent; HOURLY
 *    limits by BYHOUR and expands its minutes and seconds; MINUTELY limits
 *    by BYHOUR and BYMINUTE and expands its seconds; SECONDLY limits by all.
 *  - A wall clock is an instant by one rule (Wall::resolve): its earlier
 *    instant where it happens twice, and where it doesn't exist the same
 *    clock plus the gap, that day only; every other day is at its own clock
 *    (decisions.md, "A clock time the spring change skips"). HOURLY steps
 *    its hours on the wall clock; MINUTELY and SECONDLY step elapsed time.
 *  - The occurrences are one stream, in time order and unique: a period's
 *    instants are merged in time order with those of any period they can
 *    reach (a gap or a skipped date resolves a clock into the next
 *    period's span), and two wall clocks on one instant, in a period or
 *    across periods, are one occurrence (RFC 5545 3.8.5.3: "Duplicate
 *    instances are ignored"; pull). COUNT and UNTIL bound that stream.
 *  - BYSETPOS picks from each period's whole set, in time order, for every
 *    frequency.
 *  - The start is always the first occurrence and the first counted (RFC
 *    5545 3.3.10: DTSTART "always counts as the first occurrence"); a
 *    generated instant before it is no occurrence. COUNT bounds the
 *    generated set, before any exclusion; UNTIL bounds every generated
 *    instance, inclusive, a date UNTIL read as the end of that day in the
 *    start's zone (Walk::valid).
 *
 * A rule ends only by its COUNT, its UNTIL, the end of year 9999, or a walk
 * that proves it has no more: no day the day parts admit in the calendar's
 * 400-year cycle (nextDay), or a whole cycle of periods (the calendar's 400
 * years together with the interval's phase) without an occurrence, from
 * there it would only repeat. Any other limit of the walk is
 * WalkLimitReached, an error the plan reports as a rule it can't read, never
 * the series' end.
 */
class RuleIterator extends Walk
{
    /**
     * Periods looked at for one occurrence before the walk stops with
     * WalkLimitReached, an error and never the rule's end: a walk the
     * calendar's cycle doesn't bound (MINUTELY and SECONDLY step elapsed
     * time, whose local reading changes with the zone's rules) or one too
     * long to finish in a request. Days are jumped (nextDay), so it is only
     * reached by a clock limit no step of the interval lands on.
     */
    private const MAX_TRIES = 600000;
    /** Days in the Gregorian calendar's 400-year cycle. */
    private const CYCLE_DAYS = 146097;
    /** The end of year 9999, the date upper limit of every walk. */
    public const UPPER_LIMIT = 253402300799;

    private string $freq;
    private int $interval;
    private \DateTimeImmutable $start;
    private \DateTimeZone $zone;
    private int $startDn;
    /** Period 0's number on the frequency's own scale: a year, a month index, a week's first day, a day, a wall hour, or an instant. */
    private int $origin;
    /** Periods of a whole cycle (the calendar's and the interval's phase together), or null where elapsed time has none. */
    private ?int $cycle;

    /** @var ?list<int> */
    private ?array $months;
    /** @var ?list<int> */
    private ?array $monthDays;
    /** @var ?list<int> */
    private ?array $yearDays;
    /** @var ?list<int> */
    private ?array $weekNos;
    /** @var ?list<array{0:int,1:int}> [ordinal, weekday], ordinal 0 for every such weekday */
    private ?array $days;
    /** A numbered weekday is counted in its month (MONTHLY, or YEARLY with BYMONTH), else its year. */
    private bool $inMonth;
    /** @var ?list<int> BYHOUR, BYMINUTE and BYSECOND, a leap second left out */
    private ?array $byHour;
    private ?array $byMinute;
    private ?array $bySecond;
    /** @var ?list<int> */
    private ?array $bySetPos;
    /** @var array{0:list<int>,1:list<int>,2:list<int>} the hours, minutes and seconds a period expands into */
    private array $clocks;
    private int $wkst;
    /** No instant after the start can be generated: proven by the rule alone. */
    private bool $never = false;

    /** The period the candidate stream stands in. */
    private int $period = 0;
    private bool $ended = false;
    /** @var array{0:\DateTimeImmutable,1:string}|null for MINUTELY and SECONDLY, an empty period's last instant and the coarsest limit it failed */
    private ?array $fail = null;
    /** @var ?\Generator<int,array{0:?int,1:int}> the candidates, period by period (candidates) */
    private ?\Generator $stream = null;
    /**
     * The walker the stream runs on: a copy of this iterator made once its
     * rule is read, which never holds a stream (or a walker) of its own. A
     * generator holds the object it runs on, so a stream run on the
     * iterator itself would make the two a cycle that only the cycle
     * collector frees, one per iterator, suspended or never started; run on
     * the walker, dropping the iterator frees all of it at once. The
     * walker's period is the period the stream stands in.
     */
    private ?self $walker = null;
    /** The stream's current item has been read into the merge. */
    private bool $taken = false;
    /** @var \SplMinHeap<int> candidates read and not yet yielded */
    private \SplMinHeap $merge;
    /** No candidate still in the stream is before this instant. */
    private int $floor = PHP_INT_MIN;
    /** The instant last yielded (the start's at first). */
    private int $last = 0;
    /** @var array{0:int,1:int,2:int}|null [day, smallest offset, largest offset] for the last day asked (offsetsNear) */
    private ?array $offsets = null;

    /** @param string|array<string,mixed> $rrule */
    public function __construct($rrule, \DateTimeInterface $start)
    {
        if (is_array($rrule)) {
            $rrule = implode(';', array_map(static fn($k, $v): string => $k . '=' . (is_array($v) ? implode(',', $v) : (string) $v), array_keys($rrule), $rrule));
        }
        $t = Rule::parse((string) $rrule, 0)->table();
        $this->start = \DateTimeImmutable::createFromInterface($start);
        $this->zone = $this->start->getTimezone();
        $this->freq = $t['freq'];
        $this->interval = $t['interval'];
        $this->count = $t['count'];
        $this->wkst = $t['wkst'];
        $this->startDn = Cal::dnOf($this->start);
        $clock = [(int) $this->start->format('G'), (int) $this->start->format('i'), (int) $this->start->format('s')];
        $this->until = $this->untilInstant($t['until']);

        // The start's own day where no part names one (RFC 5545 3.3.10:
        // what the rule doesn't say is taken from DTSTART).
        [, $sm, $sd] = Cal::ymd($this->startDn);
        $f = $this->freq;
        $this->months = $t['BYMONTH'];
        $this->monthDays = $t['BYMONTHDAY'];
        $this->yearDays = $t['BYYEARDAY'];
        $this->weekNos = $t['BYWEEKNO'];
        $this->days = $t['BYDAY'];
        $named = $this->monthDays !== null || $this->yearDays !== null || $this->days !== null;
        if ($f === 'YEARLY' && $this->weekNos === null && !$named) {
            $this->monthDays = [$sd];
            $this->months ??= [$sm];
        } elseif ($f === 'YEARLY' && !$named) {
            $this->days = [[0, Cal::wd($this->startDn)]];
        } elseif ($f === 'MONTHLY' && $this->monthDays === null && $this->days === null) {
            $this->monthDays = [$sd];
        } elseif ($f === 'WEEKLY' && $this->days === null) {
            $this->days = [[0, Cal::wd($this->startDn)]];
        }
        $this->inMonth = $f === 'MONTHLY' || $t['BYMONTH'] !== null;

        // A second 60 names a leap second, which the app's time scale
        // doesn't have: no instant, as 30 February is no day.
        $sorted = static function (?array $l): ?array {
            if ($l !== null) {
                sort($l);
            }
            return $l;
        };
        $this->byHour = $sorted($t['BYHOUR']);
        $this->byMinute = $sorted($t['BYMINUTE']);
        $this->bySecond = $t['BYSECOND'] === null ? null : $sorted(array_values(array_filter($t['BYSECOND'], static fn(int $s): bool => $s < 60)));
        $this->bySetPos = $t['BYSETPOS'];
        $this->clocks = [$this->byHour ?? [$clock[0]], $this->byMinute ?? [$clock[1]], $this->bySecond ?? [$clock[2]]];
        $this->never = $this->bySecond === [];

        $dayOrigin = ['YEARLY' => Cal::ymd($this->startDn)[0], 'MONTHLY' => Cal::miOf($this->startDn), 'WEEKLY' => $this->weekOf($this->startDn), 'DAILY' => $this->startDn];
        $this->origin = $dayOrigin[$f] ?? match ($f) {
            'HOURLY' => $this->startDn * 24 + $clock[0],
            // The start's own minute: its instant less its second.
            'MINUTELY' => $this->start->getTimestamp() - $clock[2],
            default => $this->start->getTimestamp(),
        };
        $units = ['YEARLY' => 400, 'MONTHLY' => 4800, 'WEEKLY' => intdiv(self::CYCLE_DAYS, 7), 'DAILY' => self::CYCLE_DAYS, 'HOURLY' => self::CYCLE_DAYS * 24][$f] ?? null;
        $this->cycle = $units === null ? null : intdiv($units, self::gcd($units, $this->interval));
        if ($f === 'HOURLY' && $this->byHour !== null) {
            // An HOURLY rule's hours of the day are the start's stepped by
            // INTERVAL modulo 24: the residues of the start's hour by
            // gcd(INTERVAL, 24). A BYHOUR naming none of them names no hour
            // the walk ever reaches (the cycle, read by arithmetic).
            $g = self::gcd($this->interval, 24);
            $this->never = $this->never || array_filter($this->byHour, fn(int $h): bool => ($h - $clock[0]) % $g === 0) === [];
        }
        $this->walker = clone $this;
        $this->rewind();
    }

    #[\ReturnTypeWillChange]
    public function rewind()
    {
        // Nothing is expanded here: the walk reads candidates only as it is
        // stepped, so constructing an iterator (as validation does) reads the
        // rule and no period.
        $this->ended = $this->never;
        $this->currentDate = $this->start;
        $this->counter = 0;
        $this->restart($this->never ? null : 0);
    }

    #[\ReturnTypeWillChange]
    public function next()
    {
        if ($this->currentDate !== null) {
            $t = $this->pull();
            if ($t === null || $t > self::UPPER_LIMIT) {
                // The rule has no more, or the date upper limit: no instant
                // after year 9999.
                $this->currentDate = null;
                $this->restart(null);
                $this->ended = true;
            } else {
                $this->currentDate = (new \DateTimeImmutable('@' . $t))->setTimezone($this->zone);
            }
        }
        ++$this->counter;
    }

    /**
     * Moves the walk to its first occurrence at or after instant $ts. A rule
     * with no COUNT starts its stream at the period holding the earliest
     * wall clock that can name an instant at or after $ts (seekPeriod), so
     * every occurrence from $ts on is still ahead and a narrow window reads
     * what a broad one reads in its range. A COUNT is of the occurrences
     * passed, so a rule with one walks on from where it stands.
     */
    public function jumpTo(int $ts): void
    {
        if ($this->count === null && $this->stream !== null && $this->currentDate !== null && $ts > $this->last) {
            $k = $this->seekPeriod($ts);
            if ($k > $this->walker->period) {
                // Every candidate of the periods before $k is before $ts, so
                // none the merge holds is still to be read.
                $this->restart($k);
                $this->next();
            }
        }
        while ($this->valid() && $this->currentDate->getTimestamp() < $ts) {
            $this->next();
        }
    }

    /** Starts the candidate stream at period $k with nothing merged, or ends it (null). */
    private function restart(?int $k): void
    {
        $this->merge = new \SplMinHeap();
        $this->floor = PHP_INT_MIN;
        $this->taken = false;
        $this->last = $this->currentDate?->getTimestamp() ?? 0;
        $this->walker->period = $k ?? $this->walker->period;
        $this->walker->fail = null;
        $this->stream = $k === null ? null : $this->walker->candidates($k);
    }

    /**
     * The next occurrence: the earliest candidate after the last one
     * yielded. RFC 5545 3.8.5.3: "Duplicate instances are ignored", and a
     * generated instant before the start is no occurrence. The stream's
     * candidates are in wall-clock order, which a gap or a skipped date
     * makes differ from time order (a shifted 02:00 resolves after the
     * 03:00 that follows it, and a skipped day's clocks onto the next day's
     * instants), so candidates are merged in time order and one is yielded
     * only once the stream proves nothing still in it comes before it (its
     * floor). Yielded in time order, an instant at or before the last one
     * is a duplicate or before the start, and is dropped before COUNT or
     * UNTIL reads it: they bound the unique ordered stream.
     */
    private function pull(): ?int
    {
        for (;;) {
            while (!$this->merge->isEmpty() && $this->merge->top() <= $this->last) {
                $this->merge->extract();
            }
            if (!$this->merge->isEmpty() && $this->merge->top() <= $this->floor) {
                break;
            }
            if ($this->stream === null) {
                break;
            }
            if ($this->taken) {
                $this->stream->next();
                $this->taken = false;
            }
            if (!$this->stream->valid()) {
                $this->stream = null;
                continue;
            }
            [$t, $floor] = $this->stream->current();
            $this->taken = true;
            $this->floor = max($this->floor, $floor);
            if ($t !== null && $t > $this->last) {
                $this->merge->insert($t);
            }
        }
        if ($this->merge->isEmpty()) {
            return null;
        }
        return $this->last = $this->merge->extract();
    }

    /**
     * The candidates of period $k and every period after it, in wall-clock
     * order, each as [instant, floor]: the floor an instant no candidate
     * after it comes before; between periods [null, floor]. Read on until
     * the rule has ended (its UNTIL passed, year 9999 passed, or proven to
     * have no more).
     *
     * @return \Generator<int,array{0:?int,1:int}>
     */
    private function candidates(int $k): \Generator
    {
        $tries = 0;
        $quiet = 0;
        for (;;) {
            $this->period = $k;
            $any = false;
            foreach ($this->periodCandidates($k) as $item) {
                $any = true;
                yield $item;
            }
            if ($any) {
                $tries = 0;
                $quiet = 0;
            }
            if (++$tries > self::MAX_TRIES) {
                throw new WalkLimitReached('No occurrence in ' . self::MAX_TRIES . ' periods of a ' . $this->freq . ' rule from period ' . $k);
            }
            $next = $this->advance($k);
            if ($next === null) {
                return;
            }
            $quiet += $next - $k;
            $k = $next;
            $low = $this->periodLow($k);
            if ($low > self::UPPER_LIMIT || ($this->until !== null && $low > $this->until->getTimestamp()) || ($this->cycle !== null && $quiet > $this->cycle)) {
                return;
            }
            yield [null, $low];
        }
    }

    // ---- one period --------------------------------------------------------

    /**
     * Period $k's candidates, as candidates() yields them. A period's set is
     * produced as it is walked, one wall clock at a time, so memory is a
     * period's own clocks, never a year's instants: a day's clocks are in
     * wall-clock order and each one's floor is its own wall clock read at
     * the largest offset the zone has near it (no instant whose wall clock
     * is at or after it is earlier). BYSETPOS picks from the period's whole
     * set in time order, so with it the period's instants are held, that
     * period's only, and yielded at the floor of its first wall clock. The
     * start is not filtered out here (pull does that).
     *
     * @return iterable<array{0:int,1:int}>
     */
    private function periodCandidates(int $k): iterable
    {
        if ($this->freq === 'MINUTELY' || $this->freq === 'SECONDLY') {
            // Elapsed periods hold their own instants, in order.
            foreach ($this->elapsedSet($k) as $t) {
                yield [$t, $t];
            }
            return;
        }
        if ($this->bySetPos === null) {
            foreach ($this->periodClocks($k) as [$dn, $clock]) {
                yield [Wall::resolve($dn, $clock, $this->zone)->getTimestamp(), $this->wallFloor($dn, $clock)];
            }
            return;
        }
        $set = [];
        $floor = null;
        foreach ($this->periodClocks($k) as [$dn, $clock]) {
            $set[] = Wall::resolve($dn, $clock, $this->zone)->getTimestamp();
            $floor ??= $this->wallFloor($dn, $clock);
        }
        // Two wall clocks on one instant are one member of the set.
        sort($set);
        $unique = [];
        foreach ($set as $t) {
            if ($unique === [] || $unique[count($unique) - 1] !== $t) {
                $unique[] = $t;
            }
        }
        unset($set);
        foreach (self::setPositions($unique, $this->bySetPos) as $t) {
            yield [$t, $floor];
        }
    }

    /**
     * The wall clocks of period $k (HOURLY to YEARLY), in order, as [day,
     * clock].
     *
     * @return \Generator<int,array{0:int,1:array{0:int,1:int,2:int}}>
     */
    private function periodClocks(int $k): \Generator
    {
        if ($this->freq === 'HOURLY') {
            $h = $this->origin + $k * $this->interval;
            $dn = self::floorDiv($h, 24);
            $hour = $h - $dn * 24;
            if ($this->admits($dn) && ($this->byHour === null || in_array($hour, $this->byHour, true))) {
                foreach ($this->clocks[1] as $m) {
                    foreach ($this->clocks[2] as $s) {
                        yield [$dn, [$hour, $m, $s]];
                    }
                }
            }
            return;
        }
        foreach ($this->periodDays($k) as $dn) {
            foreach ($this->clocks[0] as $h) {
                foreach ($this->clocks[1] as $m) {
                    foreach ($this->clocks[2] as $s) {
                        yield [$dn, [$h, $m, $s]];
                    }
                }
            }
        }
    }

    /**
     * A MINUTELY or SECONDLY period's occurrences, in order, BYSETPOS
     * applied; an empty one leaves its last instant's failed limit for
     * advance.
     *
     * @return list<int>
     */
    private function elapsedSet(int $k): array
    {
        $set = [];
        $p = $this->origin + $k * $this->step();
        $this->fail = null;
        foreach ($this->freq === 'MINUTELY' ? $this->clocks[2] : [0] as $s) {
            $t = (new \DateTimeImmutable('@' . ($p + $s)))->setTimezone($this->zone);
            $failed = $this->fails($t);
            if ($failed === null) {
                $set[] = $p + $s;
            } else {
                $this->fail = [$t, $failed];
            }
        }
        if ($set !== []) {
            $this->fail = null;
        }
        return self::setPositions($set, $this->bySetPos);
    }

    /**
     * The days of period $k (DAILY to YEARLY) that the day parts admit, in
     * order.
     *
     * @return list<int>
     */
    private function periodDays(int $k): array
    {
        $n = $k * $this->interval;
        $out = [];
        switch ($this->freq) {
            case 'DAILY':
                $dn = $this->origin + $n;
                return $this->admits($dn) ? [$dn] : [];
            case 'WEEKLY':
                $w = $this->origin + 7 * $n;
                for ($dn = $w; $dn < $w + 7; $dn++) {
                    if ($this->admits($dn)) {
                        $out[] = $dn;
                    }
                }
                return $out;
            case 'MONTHLY':
                return $this->monthDays(...Cal::fromMi($this->origin + $n));
        }
        $y = $this->origin + $n;
        if ($this->weekNos !== null) {
            $w1 = $this->week1($y);
            $weeks = intdiv($this->week1($y + 1) - $w1, 7);
            foreach ($this->weekNos as $no) {
                $week = $no > 0 ? $no : $weeks + $no + 1;
                if ($week < 1 || $week > $weeks) {
                    continue;
                }
                for ($dn = $w1 + 7 * ($week - 1), $i = 0; $i < 7; $i++, $dn++) {
                    if ($this->admits($dn)) {
                        $out[$dn] = $dn;
                    }
                }
            }
        } elseif ($this->yearDays !== null) {
            $jan1 = Cal::dn($y, 1, 1);
            $len = Cal::leap($y) ? 366 : 365;
            foreach ($this->yearDays as $no) {
                $d = $no > 0 ? $no : $len + $no + 1;
                if ($d >= 1 && $d <= $len && $this->admits($jan1 + $d - 1)) {
                    $out[$jan1 + $d - 1] = $jan1 + $d - 1;
                }
            }
        } else {
            for ($m = 1; $m <= 12; $m++) {
                foreach ($this->monthDays($y, $m) as $dn) {
                    $out[$dn] = $dn;
                }
            }
        }
        ksort($out);
        return array_values($out);
    }

    /** The days of a month the day parts admit, in order. @return list<int> */
    private function monthDays(int $y, int $m): array
    {
        if ($this->months !== null && !in_array($m, $this->months, true)) {
            return [];
        }
        $len = Cal::monthLen($y, $m);
        $first = Cal::dn($y, $m, 1);
        $list = range(1, $len);
        if ($this->monthDays !== null) {
            $list = [];
            foreach ($this->monthDays as $no) {
                $d = $no > 0 ? $no : $len + $no + 1;
                if ($d >= 1 && $d <= $len) {
                    $list[$d] = $d;
                }
            }
            ksort($list);
        }
        $out = [];
        foreach ($list as $d) {
            if ($this->admits($first + $d - 1)) {
                $out[] = $first + $d - 1;
            }
        }
        return $out;
    }

    /**
     * Whether the day parts admit a local day: its month, its day of the
     * month and of the year (counted from the end when negative), and its
     * weekday, a numbered one counted in its month or its year. BYWEEKNO is
     * read by the period's own days (periodDays).
     */
    private function admits(int $dn): bool
    {
        [$y, $m, $d] = Cal::ymd($dn);
        $listed = static function (array $list, int $v, int $len): bool {
            foreach ($list as $n) {
                if ($n === $v || ($n < 0 && $len + $n + 1 === $v)) {
                    return true;
                }
            }
            return false;
        };
        if ($this->months !== null && !in_array($m, $this->months, true)) {
            return false;
        }
        $monthLen = Cal::monthLen($y, $m);
        if ($this->monthDays !== null && !$listed($this->monthDays, $d, $monthLen)) {
            return false;
        }
        $yearDay = $dn - Cal::dn($y, 1, 1) + 1;
        $yearLen = Cal::leap($y) ? 366 : 365;
        if ($this->yearDays !== null && !$listed($this->yearDays, $yearDay, $yearLen)) {
            return false;
        }
        if ($this->days === null) {
            return true;
        }
        $wd = Cal::wd($dn);
        [$pos, $len] = $this->inMonth ? [$d, $monthLen] : [$yearDay, $yearLen];
        foreach ($this->days as [$n, $code]) {
            if ($code === $wd && ($n === 0 || ($n > 0 && intdiv($pos - 1, 7) + 1 === $n) || ($n < 0 && intdiv($len - $pos, 7) + 1 === -$n))) {
                return true;
            }
        }
        return false;
    }

    /**
     * The coarsest limit a MINUTELY or SECONDLY instant fails ('day',
     * 'hour', 'minute' or 'second'), read on its wall clock, or null.
     */
    private function fails(\DateTimeImmutable $t): ?string
    {
        $in = static fn(?array $list, int $v): bool => $list === null || in_array($v, $list, true);
        return match (true) {
            !$this->admits(Cal::dnOf($t)) => 'day',
            !$in($this->byHour, (int) $t->format('G')) => 'hour',
            !$in($this->byMinute, (int) $t->format('i')) => 'minute',
            $this->freq === 'SECONDLY' && !$in($this->bySecond, (int) $t->format('s')) => 'second',
            default => null,
        };
    }

    /**
     * BYSETPOS over a period's set in time order: the listed positions,
     * negative from its end, each once, in order. Without BYSETPOS, the set.
     *
     * @param list<int> $set instants
     * @param ?list<int> $positions
     * @return list<int>
     */
    private static function setPositions(array $set, ?array $positions): array
    {
        if ($positions === null || $set === []) {
            return $set;
        }
        $picked = [];
        foreach ($positions as $pos) {
            $at = $pos > 0 ? $pos - 1 : count($set) + $pos;
            if (isset($set[$at])) {
                $picked[$at] = $set[$at];
            }
        }
        ksort($picked);
        return array_values($picked);
    }

    // ---- moving between periods ----------------------------------------------

    /**
     * The next period worth reading after $k: the one after it, or where
     * $k's own day (DAILY, HOURLY) or its last instant's limit (MINUTELY,
     * SECONDLY) rules out more than one period, the first period that can
     * be admitted: the next day the day parts admit (nextDay), or the next
     * local hour or minute. Null when no day is ever admitted again.
     */
    private function advance(int $k): ?int
    {
        if ($this->freq === 'DAILY') {
            $dn = $this->origin + $k * $this->interval;
            if ($this->admits($dn)) {
                return $k + 1;
            }
            $day = $this->nextDay($dn);
            return $day === null ? null : $k + max(1, self::ceilDiv($day - $dn, $this->interval));
        }
        if ($this->freq === 'HOURLY') {
            $h = $this->origin + $k * $this->interval;
            $dn = self::floorDiv($h, 24);
            if ($this->admits($dn)) {
                return $k + 1;
            }
            $day = $this->nextDay($dn);
            return $day === null ? null : $k + max(1, self::ceilDiv($day * 24 - $h, $this->interval));
        }
        if (($this->freq !== 'MINUTELY' && $this->freq !== 'SECONDLY') || $this->fail === null || $this->fail[1] === 'second') {
            return $k + 1;
        }
        [$t, $level] = $this->fail;
        $ts = $t->getTimestamp();
        if ($level === 'day') {
            $day = $this->nextDay(Cal::dnOf($t));
            if ($day === null) {
                return null;
            }
            $target = Wall::resolve($day, [0, 0, 0], $this->zone)->getTimestamp();
        } else {
            $into = (int) $t->format('s') + ($level === 'hour' ? (int) $t->format('i') * 60 : 0);
            $target = $ts + ($level === 'hour' ? 3600 : 60) - $into;
        }
        // The wall clock jumps at a transition, so no further than it.
        $transitions = $this->zone->getTransitions($ts + 1, $target);
        if (is_array($transitions) && count($transitions) > 1) {
            $target = min($target, (int) $transitions[1]['ts']);
        }
        return max($k + 1, self::floorDiv($target - $this->origin, $this->step()));
    }

    /**
     * The first local day after $dn that the day parts admit, or null when
     * none does in the 400 years after it: the calendar repeats every 400
     * years, so such a rule admits none ever (the rule's own end). Months
     * BYMONTH leaves out are passed over whole, and in a month only the days
     * BYMONTHDAY names are read.
     */
    private function nextDay(int $dn): ?int
    {
        $from = $dn + 1;
        $last = $from + self::CYCLE_DAYS - 1;
        for ($mi = Cal::miOf($from); ; $mi++) {
            [$y, $m] = Cal::fromMi($mi);
            if (Cal::dn($y, $m, 1) > $last) {
                return null;
            }
            foreach ($this->monthDays($y, $m) as $d) {
                if ($d > $last) {
                    return null;
                }
                if ($d >= $from) {
                    return $d;
                }
            }
        }
    }

    /**
     * The period a seek to instant $ts starts at: the one holding the
     * earliest wall clock that can name an instant at or after $ts. An
     * instant is its wall clock less the offset it is read at, or in a gap
     * the offset before it, so an instant at or after $ts has a wall clock
     * at or after $ts read at the smallest offset the zone has near it; the
     * periods before the one holding that clock have only earlier clocks,
     * whose instants are all before $ts. Whatever a transition displaces (an
     * hour, half an hour, two hours, a whole date) is in that offset, so no
     * lookback is guessed. MINUTELY and SECONDLY periods are elapsed time:
     * the period holding $ts.
     */
    private function seekPeriod(int $ts): int
    {
        if ($this->freq === 'MINUTELY' || $this->freq === 'SECONDLY') {
            return self::floorDiv($ts - $this->origin, $this->step());
        }
        $wall = $ts + $this->offsetsNear(self::floorDiv($ts, 86400))[0];
        $dn = self::floorDiv($wall, 86400);
        [$y, $m] = Cal::ymd($dn);
        if ($this->freq === 'YEARLY' && $this->weekNos !== null) {
            // BYWEEKNO's period is its numbering's year.
            $y += $dn < $this->week1($y) ? -1 : ($dn >= $this->week1($y + 1) ? 1 : 0);
        }
        return match ($this->freq) {
            'YEARLY' => self::floorDiv($y - $this->origin, $this->interval),
            'MONTHLY' => self::floorDiv(Cal::mi($y, $m) - $this->origin, $this->interval),
            'WEEKLY' => self::floorDiv($this->weekOf($dn) - $this->origin, 7 * $this->interval),
            'DAILY' => self::floorDiv($dn - $this->origin, $this->interval),
            default => self::floorDiv(self::floorDiv($wall, 3600) - $this->origin, $this->interval),
        };
    }

    /**
     * An instant no candidate of period $k or after it comes before: the
     * floor of the period's first wall clock (wallFloor), or for MINUTELY
     * and SECONDLY the period's own first instant.
     */
    private function periodLow(int $k): int
    {
        $n = $k * $this->interval;
        if ($this->freq === 'HOURLY') {
            $h = $this->origin + $n;
            $dn = self::floorDiv($h, 24);
            return $this->wallFloor($dn, [$h - $dn * 24, 0, 0]);
        }
        $dn = match ($this->freq) {
            'YEARLY' => $this->weekNos !== null ? min(Cal::dn($this->origin + $n, 1, 1), $this->week1($this->origin + $n)) : Cal::dn($this->origin + $n, 1, 1),
            'MONTHLY' => Cal::dn(...[...Cal::fromMi($this->origin + $n), 1]),
            'WEEKLY' => $this->origin + 7 * $n,
            'DAILY' => $this->origin + $n,
            default => null,
        };
        return $dn === null ? $this->origin + $k * $this->step() : $this->wallFloor($dn, [0, 0, 0]);
    }

    /**
     * An instant no candidate at or after a wall clock comes before: the
     * clock read at the largest offset the zone has near it. Every candidate
     * of a later or equal wall clock is that clock less the offset it is
     * read at, or in a gap less the smaller offset before it, and an offset
     * within two days of the clock is one offsetsNear has.
     *
     * @param array{0:int,1:int,2:int} $clock
     */
    private function wallFloor(int $dn, array $clock): int
    {
        return Wall::asUtc($dn, $clock) - $this->offsetsNear($dn)[1];
    }

    /**
     * The smallest and largest offsets the zone has from three days before
     * day $dn (read as UTC) to three days after it, which holds every instant
     * within two days of any clock on that day. One day is kept, since a
     * walk asks for the same day clock after clock.
     *
     * @return array{0:int,1:int}
     */
    private function offsetsNear(int $dn): array
    {
        if ($this->offsets === null || $this->offsets[0] !== $dn) {
            $day = $dn * 86400;
            $offsets = array_map(static fn(array $t): int => (int) $t['offset'], $this->zone->getTransitions($day - 3 * 86400, $day + 4 * 86400) ?: []);
            if ($offsets === []) {
                $offsets = [$this->zone->getOffset(new \DateTimeImmutable('@' . $day))];
            }
            $this->offsets = [$dn, min($offsets), max($offsets)];
        }
        return [$this->offsets[1], $this->offsets[2]];
    }

    // ---- arithmetic --------------------------------------------------------

    /** The first day of the week (from WKST) holding a day. */
    private function weekOf(int $dn): int
    {
        return $dn - ((Cal::wd($dn) - $this->wkst + 7) % 7);
    }

    /** The first day of week 1 of year $y: the week, from WKST, holding at least four of the year's days. */
    private function week1(int $y): int
    {
        $jan1 = Cal::dn($y, 1, 1);
        $ws = $this->weekOf($jan1);
        return $jan1 - $ws <= 3 ? $ws : $ws + 7;
    }

    /** A MINUTELY or SECONDLY period's length in seconds. */
    private function step(): int
    {
        return $this->interval * ($this->freq === 'MINUTELY' ? 60 : 1);
    }

    /** UNTIL as an instant: a date is the end of that day in the start's zone, a floating date-time a wall clock there. */
    private function untilInstant(?string $u): ?\DateTimeImmutable
    {
        if ($u === null) {
            return null;
        }
        $dn = Cal::dn((int) substr($u, 0, 4), (int) substr($u, 4, 2), (int) substr($u, 6, 2));
        if (strlen($u) === 8) {
            return Wall::resolve($dn, [23, 59, 59], $this->zone);
        }
        $clock = [(int) substr($u, 9, 2), (int) substr($u, 11, 2), (int) substr($u, 13, 2)];
        return str_ends_with($u, 'Z')
            ? (new \DateTimeImmutable('@' . Wall::asUtc($dn, $clock)))->setTimezone($this->zone)
            : Wall::resolve($dn, $clock, $this->zone);
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return abs($a);
    }

    private static function floorDiv(int $a, int $b): int
    {
        return intdiv($a, $b) - (($a % $b !== 0 && (($a < 0) !== ($b < 0))) ? 1 : 0);
    }

    private static function ceilDiv(int $a, int $b): int
    {
        return -self::floorDiv(-$a, $b);
    }
}
