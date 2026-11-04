<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

use Sabre\VObject\InvalidDataException;
use Sabre\VObject\Recur\RRuleIterator;

/**
 * The app's rule iterator: sabre/vobject's, with the frequencies it lacks.
 * sabre 4.6.1 parses FREQ=MINUTELY and FREQ=SECONDLY but its next() has no
 * case for them, so it yields the start again and again. Here those two
 * follow RFC 5545 3.3.10 as written, its table of which BY parts expand a
 * period and which limit it:
 *
 *  - The periods are minutes (MINUTELY) or seconds (SECONDLY) stepped by
 *    INTERVAL in elapsed time from the start's own, so every 720 minutes
 *    is every 12 hours across a clock change too.
 *  - MINUTELY: BYSECOND expands each minute into its listed seconds (the
 *    start's second when there is none); BYMONTH, BYYEARDAY, BYMONTHDAY,
 *    BYDAY, BYHOUR and BYMINUTE limit.
 *  - SECONDLY: every part limits, BYSECOND included.
 *  - BYSETPOS limits the set each period gives, as for every frequency.
 *  - Every part is read on the wall clock of the start's zone. The start
 *    is always the first occurrence, as sabre has it for every frequency.
 *
 * What the table marks N/A for these frequencies is refused as a rule the
 * expander can't read (Imported Rules, by Capability: field changes only,
 * and a 400 invalid_rrule where a rule is written): BYWEEKNO, which only a
 * YEARLY rule may carry, and a BYDAY with a number, which only MONTHLY and
 * YEARLY may.
 *
 * A period that a limit rules out from an hour or a minute on rules out
 * every period until the next local hour or minute (or the zone's next
 * transition, whichever comes first), and one ruled out by its day every
 * period until the next day the rule's days admit (nextDay), so the walk
 * jumps there rather than stepping a second at a time.
 *
 * Every other frequency is sabre's own, but for one rule every frequency
 * keeps: an instant outside the rule's own days is no occurrence, so it is
 * skipped and the rule read on (outsideDays). Every expansion in the app reads this iterator
 * (Calc for the model, SeriesIterator for the window), so there is one
 * expander.
 *
 * How a rule ends is the rule's own bound (COUNT, UNTIL) or the calendar's,
 * never a number of instants looked at: the Gregorian calendar repeats every
 * 400 years, so a rule whose days admit none in 400 years admits none ever,
 * and a walk that comes round to the same place in the calendar and in its
 * own steps without an occurrence would repeat itself forever (cycle). sabre's
 * dateUpperLimit (the end of year 9999) ends every walk. The one limit of
 * the walk left, MAX_TRIES, is an error when reached, never an end.
 */
class RuleIterator extends RRuleIterator
{
    /**
     * Periods looked at for one MINUTELY or SECONDLY occurrence before the
     * walk stops with WalkLimitReached, an error and never the rule's end.
     * Days are jumped by the calendar (nextDay), so this is only reached
     * inside admitted days, where a clock limit no step lands on
     * (SECONDLY;INTERVAL=2;BYSECOND=31 from an even second) would otherwise
     * be read period by period through the 400-year cycle.
     */
    private const MAX_TRIES = 600000;
    /** Days in the Gregorian calendar's 400-year cycle. */
    private const CYCLE_DAYS = 146097;

    /** @var array{months:?list<int>,yearDays:?list<int>,monthDays:?list<int>,days:?list<array{0:int,1:int}>,inMonth:bool}|null the rule's day parts, read once */
    private ?array $dayParts = null;

    /** @var list<\DateTimeImmutable>|null the current period's occurrences after the current one; null before the first period is read */
    private ?array $pending = null;
    private int $period = 0;
    /** @var array{0:\DateTimeImmutable,1:string}|null the current period's last instant and the limit it failed, when the period gave nothing */
    private ?array $lastFail = null;
    private ?int $base = null;

    public function __construct($rrule, \DateTimeInterface $start)
    {
        parent::__construct($rrule, $start);
        if (!$this->owned()) {
            return;
        }
        if ($this->byWeekNo !== null) {
            throw new InvalidDataException('BYWEEKNO is only valid in a YEARLY rule (RFC 5545 3.3.10)');
        }
        foreach ($this->byDay ?? [] as $day) {
            if (preg_match('/\d/', (string) $day)) {
                throw new InvalidDataException('BYDAY with a number is only valid in a MONTHLY or YEARLY rule (RFC 5545 3.3.10)');
            }
        }
    }

    private function owned(): bool
    {
        return $this->frequency === 'minutely' || $this->frequency === 'secondly';
    }

    #[\ReturnTypeWillChange]
    public function rewind()
    {
        parent::rewind();
        $this->pending = null;
        $this->period = 0;
        $this->lastFail = null;
    }

    #[\ReturnTypeWillChange]
    public function next()
    {
        if (!$this->owned()) {
            $from = $this->currentDate?->getTimestamp();
            parent::next();
            // An instant outside the rule's own days is no occurrence: it is
            // skipped, not counted, and rules out its whole day, so the walk
            // goes on to the next day the rule's days admit (stepTowards) and
            // reads sabre's steps from there. It ends where the calendar says
            // the rule has no more: no admitted day in a 400-year cycle, or a
            // whole cycle of the walk gone by without one, or the year 9999.
            // Past UNTIL there is nothing to find (valid() ends it there).
            while ($this->currentDate !== null && ($this->until === null || $this->currentDate <= $this->until) && $this->outsideDays($this->currentDate)) {
                $ts = $this->currentDate->getTimestamp();
                $day = $this->nextDay($this->currentDate);
                if ($day === null || $ts > self::dateUpperLimit || ($from !== null && $ts - $from > $this->cycle())) {
                    $this->currentDate = null;
                    break;
                }
                $this->stepTowards($day);
                parent::next();
                --$this->counter;
            }
            return;
        }
        $found = null;
        if ($this->currentDate !== null) {
            if ($this->pending === null) {
                // The start's own period, from after the start (the start is
                // the first occurrence, yielded before any next()).
                $start = $this->startDate->getTimestamp();
                [$set, $this->lastFail] = $this->periodOccurrences(0);
                $this->pending = array_values(array_filter($set, static fn(\DateTimeImmutable $t): bool => $t->getTimestamp() > $start));
            }
            $until = $this->until?->getTimestamp();
            for ($i = 0; $this->pending === []; $i++) {
                if ($i >= self::MAX_TRIES) {
                    throw new WalkLimitReached('No occurrence in ' . self::MAX_TRIES . ' periods of ' . $this->frequency . ' rule after ' . (new \DateTimeImmutable('@' . $this->periodStart($this->period)))->format('c'));
                }
                $period = $this->nextPeriod($this->period, $this->lastFail);
                if ($period === null) {
                    break;
                }
                $this->period = $period;
                $at = $this->periodStart($this->period);
                if ($at > self::dateUpperLimit || ($until !== null && $at > $until)) {
                    break;
                }
                [$this->pending, $this->lastFail] = $this->periodOccurrences($this->period);
            }
            $found = array_shift($this->pending);
        }
        $this->currentDate = $found;
        ++$this->counter;
    }

    /**
     * Whether an instant sabre stepped to lies outside the rule's own days:
     * a month, a day of the year, a day of the month or a weekday its BY
     * parts don't name, a numbered weekday ("2TU", "-1FR") counted in its
     * month (MONTHLY, or YEARLY with BYMONTH) or its year. (sabre 4.6.1
     * yields such an instant when it runs out of a month's days and stops
     * at the next month's first because that is the UNTIL instant, which
     * the bound would otherwise admit.) Its clock parts are not read here:
     * for these frequencies sabre makes the hours and minutes, and they are
     * its own.
     */
    private function outsideDays(\DateTimeInterface $t): bool
    {
        return !$this->admits(Cal::dnOf(\DateTimeImmutable::createFromInterface($t)));
    }

    /**
     * The rule's day parts, read once: BYMONTH, BYYEARDAY and BYMONTHDAY as
     * numbers, BYDAY as [count, weekday] (a count only for MONTHLY and
     * YEARLY, the frequencies it counts in; 0 for any such weekday), and
     * whether a count is in the month (MONTHLY, or YEARLY with BYMONTH) or
     * the year.
     *
     * @return array{months:?list<int>,yearDays:?list<int>,monthDays:?list<int>,days:?list<array{0:int,1:int}>,inMonth:bool}
     */
    private function dayParts(): array
    {
        if ($this->dayParts === null) {
            $ints = static fn(?array $list): ?array => $list === null ? null : array_map('intval', $list);
            $counted = in_array($this->frequency, ['monthly', 'yearly'], true);
            $days = null;
            foreach ($this->byDay ?? [] as $day) {
                $day = strtoupper((string) $day);
                $days[] = [$counted ? (int) substr($day, 0, -2) : 0, (int) Cal::wdOf(substr($day, -2))];
            }
            $this->dayParts = [
                'months' => $ints($this->byMonth), 'yearDays' => $ints($this->byYearDay), 'monthDays' => $ints($this->byMonthDay),
                'days' => $days, 'inMonth' => $this->frequency === 'monthly' || $this->byMonth !== null,
            ];
        }
        return $this->dayParts;
    }

    /**
     * Whether the rule's day parts admit a local day: its month, its day of
     * the year and of the month (counted from the end when negative), its
     * weekday, and a numbered weekday's place in its month or year.
     */
    private function admits(int $dn): bool
    {
        $p = $this->dayParts();
        [$y, $m, $d] = Cal::ymd($dn);
        $listed = static function (array $list, int $v, int $len): bool {
            foreach ($list as $n) {
                if ($n === $v || ($n < 0 && $len + $n + 1 === $v)) {
                    return true;
                }
            }
            return false;
        };
        if ($p['months'] !== null && !in_array($m, $p['months'], true)) {
            return false;
        }
        $monthLen = Cal::monthLen($y, $m);
        if ($p['monthDays'] !== null && !$listed($p['monthDays'], $d, $monthLen)) {
            return false;
        }
        $yearDay = $dn - Cal::dn($y, 1, 1) + 1;
        $yearLen = Cal::leap($y) ? 366 : 365;
        if ($p['yearDays'] !== null && !$listed($p['yearDays'], $yearDay, $yearLen)) {
            return false;
        }
        if ($p['days'] === null) {
            return true;
        }
        $wd = Cal::wd($dn);
        [$pos, $len] = $p['inMonth'] ? [$d, $monthLen] : [$yearDay, $yearLen];
        foreach ($p['days'] as [$n, $code]) {
            if ($code === $wd && ($n === 0 || ($n > 0 && intdiv($pos - 1, 7) + 1 === $n) || ($n < 0 && intdiv($len - $pos, 7) + 1 === -$n))) {
                return true;
            }
        }
        return false;
    }

    /**
     * The first local day after $t's that the rule's days admit, as a day
     * number, or null when none does in the 400 years after it: the
     * calendar repeats every 400 years, so such a rule admits none ever.
     * Months BYMONTH leaves out are passed over whole, and in a month only
     * the days BYMONTHDAY names are read.
     */
    private function nextDay(\DateTimeInterface $t): ?int
    {
        $p = $this->dayParts();
        $from = Cal::dnOf(\DateTimeImmutable::createFromInterface($t)) + 1;
        $last = $from + self::CYCLE_DAYS - 1;
        for ($mi = Cal::miOf($from); ; $mi++) {
            [$y, $m] = Cal::fromMi($mi);
            $first = Cal::dn($y, $m, 1);
            if ($first > $last) {
                return null;
            }
            if ($p['months'] !== null && !in_array($m, $p['months'], true)) {
                continue;
            }
            $len = Cal::monthLen($y, $m);
            $days = range(1, $len);
            if ($p['monthDays'] !== null) {
                $days = array_unique(array_map(static fn(int $n): int => $n < 0 ? $len + $n + 1 : $n, $p['monthDays']));
                sort($days);
            }
            foreach ($days as $d) {
                $dn = $first + $d - 1;
                if ($d < 1 || $d > $len || $dn < $from) {
                    continue;
                }
                if ($dn > $last) {
                    return null;
                }
                if ($this->admits($dn)) {
                    return $dn;
                }
            }
        }
    }

    /**
     * Moves the walk on, for a frequency sabre steps by a fixed span of the
     * wall clock (INTERVAL hours, days or weeks: every step of an HOURLY
     * rule, and the days or weeks a DAILY or WEEKLY rule visits, its BYHOUR
     * and BYDAY read inside them), by the whole spans that keep it before
     * day $dn, or before the zone's next transition when that comes first,
     * in one modify. Up to a transition the wall clock moves with time, so
     * k spans at once land where k of sabre's steps would and the interval
     * keeps its phase from the start; across one, sabre's own step does
     * what it does there (a clock a gap pushed on stays pushed, as its steps
     * carry it), and the walk jumps again after it. MONTHLY and YEARLY are
     * left to sabre's steps, a dozen a year.
     */
    private function stepTowards(int $dn): void
    {
        $unit = ['hourly' => 'hours', 'daily' => 'days', 'weekly' => 'weeks'][$this->frequency] ?? null;
        if ($unit === null) {
            return;
        }
        $t = \DateTimeImmutable::createFromInterface($this->currentDate);
        $ts = $t->getTimestamp();
        $target = $this->dayStart($dn);
        $transitions = $t->getTimezone()->getTransitions($ts + 1, $target);
        if (is_array($transitions) && count($transitions) > 1) {
            $target = min($target, (int) $transitions[1]['ts']);
        }
        // Divided as a float: a stored INTERVAL too large for a span in
        // seconds (rows from before Recurrence::safeRrule) jumps nothing.
        $span = $this->interval * ['hours' => 3600, 'days' => 86400, 'weeks' => 604800][$unit];
        $spans = (int) floor(($target - $ts - 1) / $span);
        if ($spans > 0) {
            $this->currentDate = $t->modify('+' . ($spans * $this->interval) . ' ' . $unit);
        }
    }

    /** The first instant of a local day in the start's zone. */
    private function dayStart(int $dn): int
    {
        [$y, $m, $d] = Cal::ymd($dn);
        return (new \DateTimeImmutable('now', $this->startDate->getTimezone()))->setDate($y, $m, $d)->setTime(0, 0)->getTimestamp();
    }

    /**
     * How far the walk can go from an occurrence without finding the next
     * before it would only repeat itself, in seconds. This is the rule's own
     * end, not a search limit: the walk's place is its place in the calendar,
     * which repeats every 400 years, and in its steps, INTERVAL of the
     * frequency's unit, so it is back where it was, and repeats what it did,
     * after INTERVAL / gcd(c, INTERVAL) cycles, c being the cycle in that
     * unit (146097 days, its hours, 20871 weeks, 4800 months or 400 years).
     * A walk that found nothing in that time never will.
     */
    private function cycle(): int|float
    {
        $c = match ($this->frequency) {
            'hourly' => self::CYCLE_DAYS * 24,
            'daily' => self::CYCLE_DAYS,
            'weekly' => intdiv(self::CYCLE_DAYS, 7),
            'monthly' => 4800,
            default => 400,
        };
        [$a, $b] = [$c, $this->interval];
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return intdiv($this->interval, $a) * self::CYCLE_DAYS * 86400;
    }

    /** The step between periods, in seconds. */
    private function step(): int
    {
        return $this->interval * ($this->frequency === 'minutely' ? 60 : 1);
    }

    /** The first period's start: the start's own minute (MINUTELY) or second. */
    private function base(): int
    {
        if ($this->base === null) {
            $start = $this->startDate->getTimestamp();
            $this->base = $this->frequency === 'minutely' ? $start - (int) $this->local($start)->format('s') : $start;
        }
        return $this->base;
    }

    private function periodStart(int $n): int
    {
        return $this->base() + $n * $this->step();
    }

    private function local(int $ts): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone($this->startDate->getTimezone());
    }

    /**
     * A period's occurrences, sorted, and the last instant looked at with
     * the limit it failed (for the jump), when the period gave none.
     *
     * @return array{0:list<\DateTimeImmutable>,1:array{0:\DateTimeImmutable,1:string}|null}
     */
    private function periodOccurrences(int $n): array
    {
        $p = $this->periodStart($n);
        if ($this->frequency === 'minutely') {
            $seconds = $this->bySecond === null ? [(int) $this->local($this->startDate->getTimestamp())->format('s')] : array_map('intval', $this->bySecond);
            $seconds = array_values(array_unique(array_filter($seconds, static fn(int $s): bool => $s >= 0 && $s <= 59)));
            sort($seconds);
            $candidates = array_map(fn(int $s): \DateTimeImmutable => $this->local($p + $s), $seconds);
        } else {
            $candidates = [$this->local($p)];
        }
        $set = [];
        $last = null;
        foreach ($candidates as $t) {
            $fails = $this->fails($t);
            if ($fails === null) {
                $set[] = $t;
            } else {
                $last = [$t, $fails];
            }
        }
        if ($this->bySetPos !== null && $set !== []) {
            $picked = [];
            foreach (array_map('intval', $this->bySetPos) as $pos) {
                $at = $pos > 0 ? $pos - 1 : count($set) + $pos;
                if ($pos !== 0 && isset($set[$at])) {
                    $picked[$at] = $set[$at];
                }
            }
            ksort($picked);
            $set = array_values($picked);
        }
        return [$set, $set === [] ? $last : null];
    }

    /**
     * The coarsest limit an instant fails ('day', 'hour', 'minute' or
     * 'second'), or null when every part allows it.
     */
    private function fails(\DateTimeImmutable $t): ?string
    {
        $in = static fn(?array $list, int $v): bool => $list === null || in_array($v, array_map('intval', $list), true);
        return match (true) {
            !$this->admits(Cal::dnOf($t)) => 'day',
            !$in($this->byHour, (int) $t->format('G')) => 'hour',
            !$in($this->byMinute, (int) $t->format('i')) => 'minute',
            $this->frequency === 'secondly' && !$in($this->bySecond, (int) $t->format('s')) => 'second',
            default => null,
        };
    }

    /**
     * The next period worth reading after $n: the one after it, or, when $n
     * gave nothing because its last instant fails a limit from a day, an
     * hour or a minute on, the period holding the first instant that limit
     * can allow (the start of the next day the rule's days admit, or the
     * next local hour or minute; or the zone's next transition if that comes
     * first, since the wall clock jumps there). Null when no day ever is
     * admitted again (nextDay).
     *
     * @param array{0:\DateTimeImmutable,1:string}|null $last
     */
    private function nextPeriod(int $n, ?array $last): ?int
    {
        if ($last === null || $last[1] === 'second') {
            return $n + 1;
        }
        [$t, $level] = $last;
        $ts = $t->getTimestamp();
        if ($level === 'day') {
            $day = $this->nextDay($t);
            if ($day === null) {
                return null;
            }
            $target = $this->dayStart($day);
        } else {
            $into = (int) $t->format('s') + ($level === 'hour' ? (int) $t->format('i') * 60 : 0);
            $target = $ts + ($level === 'hour' ? 3600 : 60) - $into;
        }
        $transitions = $this->startDate->getTimezone()->getTransitions($ts + 1, $target);
        if (is_array($transitions) && count($transitions) > 1) {
            $target = min($target, (int) $transitions[1]['ts']);
        }
        return max($n + 1, intdiv($target - $this->base(), $this->step()));
    }
}
