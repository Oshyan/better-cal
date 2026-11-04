<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

/**
 * An iterator over a rule's occurrences, in order, with the rule's two
 * bounds (RFC 5545 3.3.10): COUNT bounds the generated set, the start the
 * first of it, and UNTIL bounds every generated instance, inclusive.
 * RuleIterator steps it; this class reads where it stands.
 */
abstract class Walk implements \Iterator
{
    /** The current occurrence, in the start's zone; null once the rule has no more. */
    protected ?\DateTimeImmutable $currentDate = null;
    /** The current occurrence's place in the generated set, from 0 (the start). */
    protected int $counter = 0;
    protected ?int $count = null;
    protected ?\DateTimeImmutable $until = null;

    #[\ReturnTypeWillChange]
    public function current()
    {
        return $this->currentDate;
    }

    #[\ReturnTypeWillChange]
    public function key()
    {
        return $this->counter;
    }

    #[\ReturnTypeWillChange]
    public function valid()
    {
        if ($this->currentDate === null) {
            return false;
        }
        if ($this->count !== null && $this->counter >= $this->count) {
            return false;
        }
        // The start is always the set's first member (RFC 5545 3.8.5.3: the
        // set is generated from DTSTART with the rule), so an UNTIL before it
        // bounds only what the rule generates.
        return $this->counter === 0 || $this->until === null || $this->currentDate <= $this->until;
    }
}
