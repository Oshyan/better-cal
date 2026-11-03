<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

use Sabre\VObject\Recur\RRuleIterator;

/**
 * The app's rule iterator: sabre/vobject's, with the steps it lacks. sabre
 * 4.6.1 parses FREQ=MINUTELY and FREQ=SECONDLY but its next() has no case
 * for them, so it yields the start again and again (a COUNT of its start,
 * or the start forever). Here those frequencies step by their interval in
 * elapsed time (RFC 5545 3.3.10), each step kept only when the BY parts
 * allow it (BYMONTH, BYMONTHDAY, BYDAY, BYHOUR, BYMINUTE, BYSECOND read as
 * limits). Every other frequency is sabre's own, unchanged.
 *
 * Every expansion in the app reads this iterator (Calc for the model,
 * SeriesIterator for the window), so there is one expander.
 */
class RuleIterator extends RRuleIterator
{
    /** Steps looked at for one occurrence before the rule is taken as ended. */
    private const MAX_TRIES = 600000;

    #[\ReturnTypeWillChange]
    public function next()
    {
        if ($this->frequency !== 'minutely' && $this->frequency !== 'secondly') {
            parent::next();
            return;
        }
        $step = $this->interval * ($this->frequency === 'minutely' ? 60 : 1);
        $t = \DateTimeImmutable::createFromInterface($this->currentDate);
        $found = null;
        for ($i = 0; $i < self::MAX_TRIES; $i++) {
            $t = $t->setTimestamp($t->getTimestamp() + $step);
            if ($t->getTimestamp() > self::dateUpperLimit) {
                break;
            }
            if ($this->allowed($t)) {
                $found = $t;
                break;
            }
        }
        $this->currentDate = $found;
        ++$this->counter;
    }

    /** Whether the BY parts allow an instant, read on its own wall clock. */
    private function allowed(\DateTimeImmutable $t): bool
    {
        $in = static fn(?array $list, int $v): bool => $list === null || in_array($v, array_map('intval', $list), true);
        if (!$in($this->byMonth, (int) $t->format('n')) || !$in($this->byHour, (int) $t->format('G'))
            || !$in($this->byMinute, (int) $t->format('i')) || !$in($this->bySecond, (int) $t->format('s'))) {
            return false;
        }
        if ($this->byMonthDay !== null) {
            $d = (int) $t->format('j');
            $len = (int) $t->format('t');
            $ok = false;
            foreach ($this->byMonthDay as $n) {
                $n = (int) $n;
                if ($n === $d || ($n < 0 && $len + $n + 1 === $d)) {
                    $ok = true;
                }
            }
            if (!$ok) {
                return false;
            }
        }
        if ($this->byDay !== null) {
            $code = strtoupper(substr($t->format('D'), 0, 2));
            $ok = false;
            foreach ($this->byDay as $day) {
                if (strtoupper(substr((string) $day, -2)) === $code) {
                    $ok = true;
                }
            }
            if (!$ok) {
                return false;
            }
        }
        return true;
    }
}
