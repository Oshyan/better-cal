<?php

declare(strict_types=1);

namespace BetterCal\Support;

/**
 * One shared recurrence-expansion budget for a complete request or worker
 * slice. It is deliberately passed through every series expansion: a
 * per-series cap cannot bound a calendar containing many individually valid
 * repeating events.
 */
final class ExpansionBudget
{
    private int $series = 0;
    private int $occurrences = 0;
    private readonly float $startedAt;

    public function __construct(
        private readonly int $maxSeries,
        private readonly int $maxOccurrences,
        private readonly float $maxSeconds,
        private readonly ?\Closure $clock = null,
    ) {
        $this->startedAt = $this->now();
    }

    public static function standard(?int $occurrenceCap = null): self
    {
        return new self(
            Limits::get('EXPANSION_SERIES'),
            min(Limits::get('EXPANSION_OCCURRENCES'), $occurrenceCap ?? PHP_INT_MAX),
            Limits::get('EXPANSION_SECONDS'),
        );
    }

    public function beginSeries(): void
    {
        $this->checkTime();
        if (++$this->series > $this->maxSeries) {
            throw new WorkBudgetExceeded('too many repeating series');
        }
    }

    /** Charge iterator/output work, including suppressed and overridden instances. */
    public function occurrence(int $count = 1): void
    {
        $this->checkTime();
        $this->occurrences += $count;
        if ($this->occurrences > $this->maxOccurrences) {
            throw new WorkBudgetExceeded('too many generated occurrences');
        }
    }

    public function checkpoint(): void
    {
        $this->checkTime();
    }

    /** Maximum stored rows that can still be meaningful to this operation. */
    public function candidateRowLimit(): int
    {
        return $this->maxSeries + $this->maxOccurrences;
    }

    public function occurrenceLimit(): int
    {
        return $this->maxOccurrences;
    }

    /** @return array{series:int,occurrences:int} */
    public function usage(): array
    {
        return ['series' => $this->series, 'occurrences' => $this->occurrences];
    }

    private function checkTime(): void
    {
        if ($this->now() - $this->startedAt > $this->maxSeconds) {
            throw new WorkBudgetExceeded('recurrence expansion took too long');
        }
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }
}
