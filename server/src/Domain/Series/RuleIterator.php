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
 * A period that a limit rules out from a day, an hour or a minute on rules
 * out every period until the next local day, hour or minute (or the zone's
 * next transition, whichever comes first), so the walk jumps there rather
 * than stepping a second at a time.
 *
 * Every other frequency is sabre's own, but for one rule every frequency
 * keeps: an instant outside the rule's own days is no occurrence, so it is
 * skipped and the rule read on (outsideDays). Every expansion in the app reads this iterator
 * (Calc for the model, SeriesIterator for the window), so there is one
 * expander.
 */
class RuleIterator extends RRuleIterator
{
    /** Periods looked at for one occurrence before the rule is taken as ended. */
    private const MAX_TRIES = 600000;
    /** Instants outside the rule's days skipped in a row before the rule is taken as ended. */
    private const MAX_SKIPS = 1000;

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
            parent::next();
            // An instant outside the rule's own days is no occurrence: it is
            // skipped, not counted, and the rule read on from it.
            for ($i = 0; $this->currentDate !== null && $this->outsideDays($this->currentDate); $i++) {
                if ($i >= self::MAX_SKIPS) {
                    $this->currentDate = null;
                    break;
                }
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
            for ($i = 0; $i < self::MAX_TRIES && $this->pending === []; $i++) {
                $this->period = $this->nextPeriod($this->period, $this->lastFail);
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
        $t = \DateTimeImmutable::createFromInterface($t);
        if ($this->fails($t) === 'day') {
            return true;
        }
        if ($this->byDay === null || !in_array($this->frequency, ['monthly', 'yearly'], true)) {
            return false;
        }
        $wd = strtoupper(substr($t->format('D'), 0, 2));
        $inMonth = $this->frequency === 'monthly' || $this->byMonth !== null;
        $pos = $inMonth ? (int) $t->format('j') : (int) $t->format('z') + 1;
        $len = $inMonth ? (int) $t->format('t') : 365 + (int) $t->format('L');
        foreach ($this->byDay as $day) {
            $day = strtoupper((string) $day);
            if (substr($day, -2) !== $wd) {
                continue;
            }
            $n = (int) substr($day, 0, -2);
            if ($n === 0 || ($n > 0 && intdiv($pos - 1, 7) + 1 === $n) || ($n < 0 && intdiv($len - $pos, 7) + 1 === -$n)) {
                return false;
            }
        }
        return true;
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
        $fromEnd = static function (?array $list, int $v, int $len): bool {
            if ($list === null) {
                return true;
            }
            foreach (array_map('intval', $list) as $n) {
                if ($n === $v || ($n < 0 && $len + $n + 1 === $v)) {
                    return true;
                }
            }
            return false;
        };
        $days = static fn(?array $list, string $code): bool => $list === null
            || in_array($code, array_map(static fn($d): string => strtoupper(substr((string) $d, -2)), $list), true);
        return match (true) {
            !$in($this->byMonth, (int) $t->format('n')),
            !$fromEnd($this->byYearDay, (int) $t->format('z') + 1, 365 + (int) $t->format('L')),
            !$fromEnd($this->byMonthDay, (int) $t->format('j'), (int) $t->format('t')),
            !$days($this->byDay, strtoupper(substr($t->format('D'), 0, 2))) => 'day',
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
     * can allow (the next local day, hour or minute, or the zone's next
     * transition if that comes first, since the wall clock jumps there).
     *
     * @param array{0:\DateTimeImmutable,1:string}|null $last
     */
    private function nextPeriod(int $n, ?array $last): int
    {
        if ($last === null || $last[1] === 'second') {
            return $n + 1;
        }
        [$t, $level] = $last;
        $into = (int) $t->format('s') + match ($level) {
            'day' => (int) $t->format('G') * 3600 + (int) $t->format('i') * 60,
            'hour' => (int) $t->format('i') * 60,
            default => 0,
        };
        $span = ['day' => 86400, 'hour' => 3600, 'minute' => 60][$level];
        $ts = $t->getTimestamp();
        $target = $ts + $span - $into;
        $transitions = $this->startDate->getTimezone()->getTransitions($ts + 1, $target);
        if (is_array($transitions) && count($transitions) > 1) {
            $target = min($target, (int) $transitions[1]['ts']);
        }
        return max($n + 1, intdiv($target - $this->base(), $this->step()));
    }
}
