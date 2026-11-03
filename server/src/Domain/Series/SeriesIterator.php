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
}
