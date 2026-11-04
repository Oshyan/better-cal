<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

use Sabre\VObject\Recur\EventIterator;

/**
 * sabre/vobject's event iterator over the app's rule iterator
 * (RuleIterator), so the window and the model read one expander.
 *
 * Given the stored start (and end), it iterates from that instant rather
 * than from DTSTART read back from its wall clock: a start in a repeated
 * hour (a clock falling back) is one instant of two, and PHP reads the wall
 * clock as the later one, where the model stores the first (Zones, DST and
 * All-Day Days). Iterating from the instant, every expansion of a stored
 * series starts where it is stored.
 */
final class SeriesIterator extends EventIterator
{
    public function __construct($input, $uid = null, ?\DateTimeZone $timeZone = null, ?\DateTimeImmutable $start = null, ?\DateTimeImmutable $end = null)
    {
        parent::__construct($input, $uid, $timeZone);
        if (!isset($this->masterEvent->RRULE) || isset($this->masterEvent->RDATE)) {
            return;
        }
        if ($start !== null) {
            $this->startDate = $start->setTimezone($this->startDate->getTimezone());
            if ($end !== null) {
                $this->eventDuration = $end->getTimestamp() - $start->getTimestamp();
            }
        }
        $this->recurIterator = new RuleIterator($this->masterEvent->RRULE->getParts(), $this->startDate);
        $this->rewind();
    }

    /**
     * sabre's fastForward steps one occurrence at a time from the start.
     * Here the rule iterator first jumps to the window (RuleIterator::jumpTo,
     * the walk's own jumps from the start, so the window reads the state the
     * walk carries from the start), then sabre's steps finish. With exceptions
     * or overridden events in the calendar, sabre's walk is left as it is.
     */
    public function fastForward(\DateTimeInterface $dateTime)
    {
        if ($this->recurIterator instanceof RuleIterator && $this->exceptions === [] && $this->overriddenEvents === [] && $this->nextDate === null && $this->recurIterator->valid()) {
            $target = $dateTime->getTimestamp() - (int) $this->eventDuration;
            if ($this->recurIterator->current()->getTimestamp() < $target) {
                // The rule iterator holds the occurrence after the current
                // one; once it has jumped, the current one is the one before
                // the window and the next step reads the first in it.
                $this->recurIterator->jumpTo($target);
                $this->next();
            }
        }
        parent::fastForward($dateTime);
    }
}
