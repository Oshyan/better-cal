<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

use BetterCal\Support\Time;

/**
 * A series' raw occurrences (no skips applied), worked out without the
 * expander's display cap: by arithmetic on periods for the rules in the drag
 * table, by sabre/vobject over the actual span for anything else. The start
 * is always an occurrence and counts toward COUNT, as sabre has it.
 */
final class Calc
{
    /** No series is walked further than this many periods or occurrences. */
    public const MAX_STEPS = 400000;

    /** @var array{0:int,1:int,2:int} */
    public array $clock;

    /** @var null|list<int> */
    private ?array $all = null;

    /**
     * @param array{0:int,1:int,2:int} $clock the series' clock time (BYHOUR and BYMINUTE win)
     */
    public function __construct(
        public Rule $rule,
        public int $startDn,
        public string $tz,
        public bool $allDay,
        array $clock,
        public int $length,
    ) {
        if ($rule->hour !== null) {
            $clock[0] = $rule->hour;
        }
        if ($rule->minute !== null) {
            $clock[1] = $rule->minute;
        }
        $this->clock = $allDay ? [0, 0, 0] : $clock;
    }

    public function zone(): \DateTimeZone
    {
        return Time::zone($this->tz);
    }

    /** The UTC instant of the occurrence on a local day (a gap's clock time moves forward). */
    public function instant(int $dn): \DateTimeImmutable
    {
        $r = $this->rule;
        // The expander (sabre/vobject 4.6.1, RRuleIterator::nextWeekly)
        // skips the weeks an INTERVAL leaves out by jumping from their week
        // start without setting the clock again. When that week start falls
        // in a skipped hour, the gap-shifted clock (03:30 for 02:30) carries
        // to the next counted week start, and an occurrence on that day has
        // it. The app's days are sabre's, so the key is too.
        if (!$this->allDay && $r->freq === 'WEEKLY' && $r->interval > 1 && !$r->bare && $r->hour === null && $r->minute === null
            && Cal::wd($dn) === $r->weekStart() && in_array($r->weekStart(), $r->members, true)) {
            $turn = $dn - 7 * ($r->interval - 1);
            if ($turn > $this->startDn) {
                $at = self::at($turn, $this->clock, $this->tz)->setTimezone($this->zone());
                if ($at->format('H:i:s') !== sprintf('%02d:%02d:%02d', ...$this->clock)) {
                    return self::at($dn, [(int) $at->format('G'), (int) $at->format('i'), (int) $at->format('s')], $this->tz);
                }
            }
        }
        return self::at($dn, $this->clock, $this->tz);
    }

    /** @param array{0:int,1:int,2:int} $clock */
    public static function at(int $dn, array $clock, string $tz): \DateTimeImmutable
    {
        $wall = sprintf('%s %02d:%02d:%02d', Cal::date($dn), $clock[0], $clock[1], $clock[2]);
        $zone = Time::zone($tz);
        $at = new \DateTimeImmutable($wall, $zone);
        // A clock time that happens twice is the first, in daylight time, as
        // sabre has it (RFC 5545 3.3.5); PHP alone takes the second. The
        // repeated span is the zone's own fall-back: an hour mostly, half an
        // hour on Lord Howe Island, two hours where a zone once fell back so.
        foreach ([7200, 3600, 1800] as $back) {
            $earlier = (new \DateTimeImmutable('@' . ($at->getTimestamp() - $back)))->setTimezone($zone);
            if ($earlier->format('Y-m-d H:i:s') === $wall) {
                $at = $earlier;
                break;
            }
        }
        return $at->setTimezone(Time::utc());
    }

    public function key(int $dn): string
    {
        return Time::toDb($this->instant($dn));
    }

    /** The local day an instant falls on in the series' zone. */
    public function dnOf(string $dbUtc): int
    {
        return Cal::dnOf(Time::fromDb($dbUtc)->setTimezone($this->zone()));
    }

    /** UNTIL as an instant, read the way sabre reads it: a date is midnight in the series' zone. */
    public function untilInstant(): ?\DateTimeImmutable
    {
        $u = $this->rule->until;
        if ($u === null) {
            return null;
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})(Z)?)?$/', $u, $m) !== 1) {
            return null;
        }
        $tz = isset($m[7]) && $m[7] === 'Z' ? Time::utc() : $this->zone();
        $text = sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4] ?? '00', $m[5] ?? '00', $m[6] ?? '00');
        return (new \DateTimeImmutable($text, $tz))->setTimezone(Time::utc());
    }

    public function bounded(): bool
    {
        return $this->rule->count !== null || $this->rule->until !== null;
    }

    /** Every raw occurrence of a bounded series. @return list<int> */
    public function all(): array
    {
        if ($this->all === null) {
            $this->all = $this->occurrences(PHP_INT_MAX);
        }
        return $this->all;
    }

    /** The last raw occurrence of a bounded series. */
    public function last(): ?int
    {
        if (!$this->bounded()) {
            return null;
        }
        $all = $this->all();
        return $all === [] ? null : $all[count($all) - 1];
    }

    /**
     * Raw occurrences from the start to $maxDn (inclusive), at most $max.
     *
     * @return list<int>
     */
    public function occurrences(int $maxDn, int $max = PHP_INT_MAX): array
    {
        if ($this->all !== null) {
            return array_values(array_filter($this->all, static fn(int $d): bool => $d <= $maxDn));
        }
        if (!$this->rule->supported()) {
            return $this->sabre($maxDn, $max);
        }
        $count = $this->rule->count;
        $until = $this->untilInstant();
        $out = [$this->startDn];
        if (($count !== null && $count <= 1) || $max <= 1) {
            return $out;
        }
        $r = $this->rule;
        $p0 = $r->periodOf($this->startDn);
        $p = $p0;
        $empty = 0;
        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            if ($this->periodFirst($p) > $maxDn) {
                break;
            }
            $days = $r->periodDays($p);
            if ($days === []) {
                // A rule that gives nothing for a whole cycle never will.
                if (++$empty > 4800) {
                    break;
                }
            } else {
                $empty = 0;
            }
            foreach ($days as [$d]) {
                if ($d <= $this->startDn) {
                    continue;
                }
                if ($d > $maxDn) {
                    break 2;
                }
                if ($until !== null && $this->instant($d) > $until) {
                    break 2;
                }
                $out[] = $d;
                if (($count !== null && count($out) >= $count) || count($out) >= $max) {
                    break 2;
                }
            }
            $p = $r->periodPlus($p, $r->interval);
            if ($r->kind === 'yearly' && $p > 9999) {
                break;
            }
        }
        return $out;
    }

    /** The first day of a period. */
    public function periodFirst(int $p): int
    {
        [$y, $m] = Cal::fromMi($p);
        return match ($this->rule->kind) {
            'monthly' => Cal::dn($y, $m, 1),
            'yearly' => Cal::dn($p, 1, 1),
            default => $p,
        };
    }

    /** Whether the pattern (from this start, ignoring the bound) gives $dn. */
    public function patternHas(int $dn): bool
    {
        if ($dn === $this->startDn) {
            return true;
        }
        if ($dn < $this->startDn) {
            return false;
        }
        if (!$this->rule->supported()) {
            return in_array($dn, $this->withoutBound()->occurrences($dn), true);
        }
        $r = $this->rule;
        $p = $r->periodOf($dn);
        if (!$r->counted($p, $r->periodOf($this->startDn))) {
            return false;
        }
        return $r->memberOf($dn) !== null;
    }

    /** Whether $dn is a raw occurrence of the series, bound included. */
    public function isOccurrence(int $dn): bool
    {
        if (!$this->patternHas($dn)) {
            return false;
        }
        if ($this->rule->count !== null) {
            $last = $this->last();
            return $last !== null && $dn <= $last;
        }
        $until = $this->untilInstant();
        return $until === null || $this->instant($dn) <= $until || $dn === $this->startDn;
    }

    public function withoutBound(): self
    {
        return new self($this->rule->withBound(null, null), $this->startDn, $this->tz, $this->allDay, $this->clock, $this->length);
    }

    /** @return list<int> */
    private function sabre(int $maxDn, int $max): array
    {
        if (!class_exists(\Sabre\VObject\Component\VCalendar::class)) {
            return [$this->startDn];
        }
        $vcal = new \Sabre\VObject\Component\VCalendar();
        $vevent = $vcal->add('VEVENT', ['UID' => 'series-model']);
        $start = (new \DateTimeImmutable(sprintf('%s %02d:%02d:%02d', Cal::date($this->startDn), ...$this->clock), $this->zone()));
        if ($this->allDay) {
            $vevent->add('DTSTART', $start->format('Ymd'), ['VALUE' => 'DATE']);
        } else {
            $vevent->add('DTSTART', $start);
        }
        $vevent->add('RRULE', $this->rule->text());
        $out = [];
        try {
            $it = new \Sabre\VObject\Recur\EventIterator($vcal, 'series-model', $this->allDay ? $this->zone() : Time::utc());
            for ($i = 0; $it->valid() && $i < self::MAX_STEPS && count($out) < $max; $i++) {
                $dn = Cal::dnOf(\DateTimeImmutable::createFromInterface($it->getDtStart())->setTimezone($this->zone()));
                if ($dn > $maxDn) {
                    break;
                }
                if ($out === [] || end($out) !== $dn) {
                    $out[] = $dn;
                }
                $it->next();
            }
        } catch (\Throwable) {
            return [$this->startDn];
        }
        return $out === [] ? [$this->startDn] : $out;
    }

    /** Whether sabre can read the rule at all (RSCALE and X- parts it refuses). */
    public static function sabreReads(string $rrule): bool
    {
        if (!class_exists(\Sabre\VObject\Recur\RRuleIterator::class)) {
            return true;
        }
        try {
            new \Sabre\VObject\Recur\RRuleIterator($rrule, new \DateTimeImmutable('2026-01-01', Time::utc()));
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
