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
 * function of the rule, the start and the period's number (periodSet), so
 * the walk carries no state from where it began: a window that starts at
 * any period reads what the walk from the start reads there (jumpTo).
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
 *    (decisions.md, "A clock time the spring change skips"). Two wall clocks
 *    on one instant are one occurrence. HOURLY steps its hours on the wall
 *    clock; MINUTELY and SECONDLY step elapsed time.
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

    /** The period the walk stands in. */
    private int $period = 0;
    /** @var list<\DateTimeImmutable> the period's occurrences after the current one */
    private array $pending = [];
    private bool $ended = false;
    /** Periods since the last one that gave an occurrence. */
    private int $quiet = 0;
    /** @var array{0:\DateTimeImmutable,1:string}|null for MINUTELY and SECONDLY, an empty period's last instant and the coarsest limit it failed */
    private ?array $fail = null;

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
        $this->rewind();
    }

    #[\ReturnTypeWillChange]
    public function rewind()
    {
        $this->period = 0;
        $this->quiet = 0;
        $this->counter = 0;
        $this->fail = null;
        $this->ended = $this->never;
        $this->currentDate = $this->start;
        $from = $this->start->getTimestamp();
        $this->pending = $this->never ? [] : array_values(array_filter($this->periodSet(0), static fn(\DateTimeImmutable $t): bool => $t->getTimestamp() > $from));
    }

    #[\ReturnTypeWillChange]
    public function next()
    {
        if ($this->currentDate !== null) {
            if ($this->pending === []) {
                $this->fill();
            }
            $this->currentDate = array_shift($this->pending);
            if ($this->currentDate !== null && $this->currentDate->getTimestamp() > self::UPPER_LIMIT) {
                // The date upper limit: no instant after year 9999.
                $this->currentDate = null;
                $this->pending = [];
                $this->ended = true;
            }
            if ($this->currentDate !== null) {
                $this->quiet = 0;
            }
        }
        ++$this->counter;
    }

    /**
     * Moves the walk to its first occurrence at or after instant $ts. Every
     * period's set is the rule's alone, so a rule with no COUNT starts at
     * the period before the one holding $ts (an instant a gap moved on can
     * cross into the next period) and reads on from there: the same days
     * and keys as the walk from the start. A COUNT is of the occurrences
     * passed, so a rule with one walks on from where it stands.
     */
    public function jumpTo(int $ts): void
    {
        $k = $this->count === null && $this->currentDate !== null && !$this->ended ? $this->periodAt($ts) - 1 : 0;
        if ($k > $this->period) {
            $this->period = $k;
            $this->quiet = 0;
            $this->fail = null;
            $this->pending = array_values(array_filter($this->periodSet($k), static fn(\DateTimeImmutable $t): bool => $t->getTimestamp() >= $ts));
            $this->next();
        }
        while ($this->valid() && $this->currentDate->getTimestamp() < $ts) {
            $this->next();
        }
    }

    /**
     * Reads periods on until one gives an occurrence or the rule has ended
     * (its UNTIL passed, year 9999 passed, or proven to have no more).
     */
    private function fill(): void
    {
        for ($tries = 0; $this->pending === [] && !$this->ended; $tries++) {
            if ($tries >= self::MAX_TRIES) {
                throw new WalkLimitReached('No occurrence in ' . self::MAX_TRIES . ' periods of a ' . $this->freq . ' rule from period ' . $this->period);
            }
            $next = $this->advance($this->period);
            if ($next === null) {
                $this->ended = true;
                break;
            }
            $this->quiet += $next - $this->period;
            $this->period = $next;
            $low = $this->periodLow($next);
            if ($low > self::UPPER_LIMIT || ($this->until !== null && $low > $this->until->getTimestamp()) || ($this->cycle !== null && $this->quiet > $this->cycle)) {
                $this->ended = true;
                break;
            }
            $this->pending = $this->periodSet($next);
        }
    }

    // ---- one period --------------------------------------------------------

    /**
     * Period $k's occurrences, in time order, BYSETPOS applied; the start is
     * not filtered out here (rewind does that).
     *
     * @return list<\DateTimeImmutable>
     */
    private function periodSet(int $k): array
    {
        $set = [];
        if ($this->freq === 'MINUTELY' || $this->freq === 'SECONDLY') {
            $p = $this->origin + $k * $this->step();
            $this->fail = null;
            foreach ($this->freq === 'MINUTELY' ? $this->clocks[2] : [0] as $s) {
                $t = (new \DateTimeImmutable('@' . ($p + $s)))->setTimezone($this->zone);
                $failed = $this->fails($t);
                if ($failed === null) {
                    $set[$t->getTimestamp()] = $t;
                } else {
                    $this->fail = [$t, $failed];
                }
            }
            if ($set !== []) {
                $this->fail = null;
            }
        } elseif ($this->freq === 'HOURLY') {
            $h = $this->origin + $k * $this->interval;
            $dn = self::floorDiv($h, 24);
            $hour = $h - $dn * 24;
            if ($this->admits($dn) && ($this->byHour === null || in_array($hour, $this->byHour, true))) {
                foreach ($this->clocks[1] as $m) {
                    foreach ($this->clocks[2] as $s) {
                        $t = Wall::resolve($dn, [$hour, $m, $s], $this->zone);
                        $set[$t->getTimestamp()] = $t;
                    }
                }
            }
        } else {
            foreach ($this->periodDays($k) as $dn) {
                foreach ($this->clocks[0] as $h) {
                    foreach ($this->clocks[1] as $m) {
                        foreach ($this->clocks[2] as $s) {
                            $t = Wall::resolve($dn, [$h, $m, $s], $this->zone);
                            $set[$t->getTimestamp()] = $t;
                        }
                    }
                }
            }
        }
        ksort($set);
        return self::setPositions(array_values($set), $this->bySetPos);
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
     * @param list<\DateTimeImmutable> $set
     * @param ?list<int> $positions
     * @return list<\DateTimeImmutable>
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
     * The period holding instant $ts, by the frequency's own scale read on
     * the start's zone: its year, month, week, day or wall hour, or its
     * elapsed minute or second.
     */
    private function periodAt(int $ts): int
    {
        $local = (new \DateTimeImmutable('@' . $ts))->setTimezone($this->zone);
        $dn = Cal::dnOf($local);
        [$y, $m] = Cal::ymd($dn);
        return match ($this->freq) {
            'YEARLY' => self::floorDiv($y - $this->origin, $this->interval),
            'MONTHLY' => self::floorDiv(Cal::mi($y, $m) - $this->origin, $this->interval),
            'WEEKLY' => self::floorDiv($this->weekOf($dn) - $this->origin, 7 * $this->interval),
            'DAILY' => self::floorDiv($dn - $this->origin, $this->interval),
            'HOURLY' => self::floorDiv($dn * 24 + (int) $local->format('G') - $this->origin, $this->interval),
            default => self::floorDiv($ts - $this->origin, $this->step()),
        };
    }

    /**
     * An instant no occurrence of period $k comes before: its first local
     * day's midnight read at the largest offset any zone has (14 hours), or
     * for MINUTELY and SECONDLY the period's own first instant.
     */
    private function periodLow(int $k): int
    {
        $n = $k * $this->interval;
        $dn = match ($this->freq) {
            'YEARLY' => $this->weekNos !== null ? min(Cal::dn($this->origin + $n, 1, 1), $this->week1($this->origin + $n)) : Cal::dn($this->origin + $n, 1, 1),
            'MONTHLY' => Cal::dn(...[...Cal::fromMi($this->origin + $n), 1]),
            'WEEKLY' => $this->origin + 7 * $n,
            'DAILY' => $this->origin + $n,
            'HOURLY' => self::floorDiv($this->origin + $n, 24),
            default => null,
        };
        return $dn === null ? $this->origin + $k * $this->step() : Wall::asUtc($dn, [0, 0, 0]) - 14 * 3600;
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
