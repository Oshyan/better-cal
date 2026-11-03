<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

use BetterCal\Support\Time;

/**
 * A series' raw occurrences (no skips applied), read from the expander the
 * app persists and shows (sabre/vobject's RRuleIterator), without the
 * display cap: every day and key here is one sabre yields for the series as
 * it is stored. Nothing in the model computes an occurrence's instant by
 * itself, so the app's quirks of the expander (a clock carried past a
 * skipped hour, a day an hour-stepping rule leaves out) are the model's
 * too, with no list of them to keep.
 *
 * What Calc adds is the storage rule for a first day in a skipped hour
 * (Zones, DST and All-Day Days): the series is stored from its rule's
 * latest day before that one on which the clock exists, that day a
 * generated skip and COUNT one higher, so the expander keeps the clock.
 * The series as the model reads it starts on its first shown day, and
 * this class expands the stored form and leaves the stored day out.
 *
 * Arithmetic on members and periods (images, phases, the No Loss walk)
 * stays in Rule and Readings.
 */
final class Calc
{
    /** No series is walked further than this many occurrences. */
    public const MAX_STEPS = 400000;

    /** @var array{0:int,1:int,2:int} the series' clock time (BYHOUR and BYMINUTE win) */
    public array $clock;

    /** The stored day before a first day in a skipped hour, or null. */
    public ?int $anchor = null;

    /**
     * The instant the start is stored at, where it is one of two the wall
     * clock names (a repeated hour): the expander iterates from the stored
     * instant, never from its wall clock read back (Zones, DST and All-Day
     * Days). Null: the first instant, as Calc::at reads it.
     */
    private ?\DateTimeImmutable $stored = null;

    /** @var array<string,array{keys:list<array{0:int,1:string}>,it:?\Iterator,done:bool,ok:bool}> */
    private static array $cache = [];

    /**
     * @param array{0:int,1:int,2:int} $clock
     * @param bool|int $anchor true: store a first day in a skipped hour from
     *  the day before (Zones); false: as it is; an int: from that stored day
     * @param ?\DateTimeImmutable $at an instant a series is stored at: kept as
     *  this one's stored start when it is the same wall clock on the same
     *  stored day in the same zone (a start that doesn't move keeps its
     *  instant), else ignored
     */
    public function __construct(
        public Rule $rule,
        public int $startDn,
        public string $tz,
        public bool $allDay,
        array $clock,
        public int $length,
        bool|int $anchor = true,
        ?\DateTimeImmutable $at = null,
    ) {
        if ($rule->hour !== null) {
            $clock[0] = $rule->hour;
        }
        if ($rule->minute !== null) {
            $clock[1] = $rule->minute;
        }
        $this->clock = $allDay ? [0, 0, 0] : $clock;
        if (is_int($anchor)) {
            $this->anchor = $anchor;
        } elseif ($anchor && !$this->allDay && self::inGap($startDn, $this->clock, $tz)) {
            $this->anchor = $this->anchorDay();
        }
        if ($at !== null && !$this->allDay) {
            $zone = Time::zone($this->tz);
            $wall = static fn(\DateTimeImmutable $t): string => $t->setTimezone($zone)->format('Y-m-d H:i:s');
            if ($wall($at) === $wall($this->storedStart())) {
                $this->stored = $at->setTimezone(Time::utc());
            }
        }
    }

    /**
     * A stored series row as the model reads it: its rule from its stored
     * start, except a series stored from the period before a first day in a
     * skipped hour (its stored start a skip, the next day in the gap), which
     * is read from that first shown day with its COUNT less the stored one.
     */
    public static function fromRow(array $row, ?Rule $rule = null): self
    {
        $allDay = (int) ($row['all_day'] ?? 0) === 1;
        $tz = $allDay ? 'UTC' : (string) ($row['tzid'] ?? 'UTC');
        $start = Time::fromDb((string) $row['start_utc'])->setTimezone(Time::zone($tz));
        $dn = Cal::dnOf($start);
        $clock = $allDay ? [0, 0, 0] : [(int) $start->format('G'), (int) $start->format('i'), (int) $start->format('s')];
        $len = max(0, Time::fromDb((string) $row['end_utc'])->getTimestamp() - $start->getTimestamp());
        $rule ??= Rule::parse((string) $row['rrule'], $dn);
        $calc = new self($rule, $dn, $tz, $allDay, $clock, $len, false, $start);
        try {
            $skips = \BetterCal\Domain\Recurrence::decodeExdates($row['exdates_json'] ?? null);
        } catch (\Throwable) {
            $skips = [];
        }
        if (!$allDay && $rule->supported() && in_array((string) $row['start_utc'], $skips, true)) {
            $two = $calc->occurrences(PHP_INT_MAX, 2);
            if (count($two) === 2 && self::inGap($two[1], $calc->clock, $tz)) {
                $logical = $rule->count !== null ? $rule->withBound($rule->count - 1, null) : $rule;
                return new self($logical, $two[1], $tz, false, $calc->clock, $len, $dn, $start);
            }
        }
        return $calc;
    }

    public function zone(): \DateTimeZone
    {
        return Time::zone($this->tz);
    }

    // ---- the stored form ---------------------------------------------------

    /** The rule as stored: COUNT counts the stored day before an anchored start. */
    public function storedRule(): Rule
    {
        return $this->anchor !== null && $this->rule->count !== null ? $this->rule->withBound($this->rule->count + 1, null) : $this->rule;
    }

    /** The start as stored (UTC): the anchor day at the clock, or the first day. */
    public function storedStart(): \DateTimeImmutable
    {
        if ($this->stored !== null) {
            return $this->stored;
        }
        return $this->allDay ? self::at($this->startDn, [0, 0, 0], 'UTC') : self::at($this->anchor ?? $this->startDn, $this->clock, $this->tz);
    }

    /** What the expansion depends on: equal signatures expand alike. */
    public function signature(): string
    {
        return json_encode([$this->storedRule()->text(), Time::toDb($this->storedStart()), Time::normalizeTzid($this->allDay ? 'UTC' : $this->tz), $this->allDay, $this->anchor]);
    }

    /**
     * The latest day of the rule and its phase before the first day on
     * which the clock exists, when the pattern stored from it gives the same
     * days after it; null when there is none. Which days follow is the
     * pattern's alone: the bound is the same either way, and read from a
     * start in the gap it would end by the shifted clock, not the series'
     * (an UNTIL at the last day's clock), which is what the anchor is for.
     */
    private function anchorDay(): ?int
    {
        $r = $this->rule->withBound(null, null);
        if (!$r->supported()) {
            return null;
        }
        $want = array_column(self::expansion($r->text(), self::at($this->startDn, $this->clock, $this->tz), $this->tz, false, PHP_INT_MAX, 40), 0);
        $p = $r->periodOf($this->startDn);
        for ($i = 0; $i < 60; $i++) {
            foreach (array_reverse($r->periodDays($p)) as [$d]) {
                if ($d >= $this->startDn) {
                    continue;
                }
                if (self::inGap($d, $this->clock, $this->tz)) {
                    return null;
                }
                $got = array_column(self::expansion($r->text(), self::at($d, $this->clock, $this->tz), $this->tz, false, PHP_INT_MAX, 41), 0);
                return array_slice(array_values(array_unique($got)), 1) === array_values(array_unique($want)) ? $d : null;
            }
            $p = $r->periodPlus($p, -$r->interval);
        }
        return null;
    }

    // ---- the expansion -----------------------------------------------------

    /**
     * Every raw occurrence as [local day, key], from the start through $maxDn,
     * at most $max: what sabre yields for the stored form, the stored anchor
     * day left out. A rule several times a day has several keys on a day.
     *
     * @return list<array{0:int,1:string}>
     */
    public function keys(int $maxDn, int $max = PHP_INT_MAX): array
    {
        $this->extend($maxDn, $max, false);
        $out = [];
        foreach ($this->list as $k) {
            if ($k[0] > $maxDn || count($out) >= $max) {
                break;
            }
            $out[] = $k;
        }
        return $out;
    }

    /** @var list<array{0:int,1:string}> the expansion read so far, the anchor day left out */
    private array $list = [];
    /** @var array<int,list<string>> the same, by local day */
    private array $byDay = [];
    /** Every key on a day up to this one has been read. */
    private ?int $readTo = null;
    private bool $complete = false;

    /**
     * Reads the expansion on through $maxDn, or until it holds $max keys
     * ($days: $max counts days instead), in growing steps so that lookups
     * day by day don't read it again from the start each time.
     */
    private function extend(int $maxDn, int $max, bool $days): void
    {
        $limit = Cal::dn(9999, 12, 31);
        $maxDn = min($maxDn, $limit);
        $have = function () use ($maxDn, $days): int {
            if (!$days) {
                $n = 0;
                foreach ($this->list as $k) {
                    if ($k[0] > $maxDn) {
                        break;
                    }
                    $n++;
                }
                return $n;
            }
            $n = 0;
            foreach ($this->byDay as $d => $_) {
                if ($d > $maxDn) {
                    break;
                }
                $n++;
            }
            return $n;
        };
        while (!$this->complete && ($this->readTo === null || $this->readTo < $maxDn) && ($max === PHP_INT_MAX || $have() < $max)) {
            $from = $this->readTo ?? $this->startDn;
            $to = min($maxDn, $from + max(400, 2 * ($from - $this->startDn)));
            $ck = self::cacheKey($this->storedRule()->text(), $this->storedStart(), $this->tz, $this->allDay);
            $raw = self::expansion($this->storedRule()->text(), $this->storedStart(), $this->tz, $this->allDay, $to, PHP_INT_MAX);
            $this->list = [];
            $this->byDay = [];
            foreach ($raw as $k) {
                if ($this->anchor !== null && $k[0] === $this->anchor) {
                    continue;
                }
                $this->list[] = $k;
                $this->byDay[$k[0]][] = $k[1];
            }
            $this->readTo = $to;
            $this->complete = self::$cache[$ck]['done'] && count($raw) === count(self::$cache[$ck]['keys']);
            if ($to >= $limit) {
                $this->complete = true;
            }
        }
    }

    /** Whether the expander reads the stored rule at all. */
    public function expandable(): bool
    {
        self::expansion($this->storedRule()->text(), $this->storedStart(), $this->tz, $this->allDay, $this->startDn, 1);
        return self::$cache[self::cacheKey($this->storedRule()->text(), $this->storedStart(), $this->tz, $this->allDay)]['ok'];
    }

    /**
     * Raw occurrences from the start to $maxDn (inclusive), at most $max, one per day.
     *
     * @return list<int>
     */
    public function occurrences(int $maxDn, int $max = PHP_INT_MAX): array
    {
        $this->extend($maxDn, $max, true);
        $out = [];
        foreach ($this->byDay as $d => $_) {
            if ($d > $maxDn || count($out) >= $max) {
                break;
            }
            $out[] = $d;
        }
        return $out;
    }

    /** The keys sabre yields on one local day, in order. @return list<string> */
    public function keysOn(int $dn): array
    {
        if ($dn < $this->startDn) {
            return [];
        }
        $this->extend($dn, PHP_INT_MAX, false);
        return $this->byDay[$dn] ?? [];
    }

    /** The UTC instant of the occurrence on a local day; a day with none, the series' clock there. */
    public function instant(int $dn): \DateTimeImmutable
    {
        $on = $this->keysOn($dn);
        if ($on !== []) {
            return Time::fromDb($on[0]);
        }
        return $this->allDay ? self::at($dn, [0, 0, 0], 'UTC') : self::at($dn, $this->clock, $this->tz);
    }

    public function key(int $dn): string
    {
        return Time::toDb($this->instant($dn));
    }

    /** Whether $dn is a raw occurrence of the series, bound included. */
    public function isOccurrence(int $dn): bool
    {
        return $this->keysOn($dn) !== [];
    }

    /** Whether the pattern from this start, ignoring the bound, gives $dn. */
    public function patternHas(int $dn): bool
    {
        return $dn === $this->startDn || $this->withoutBound()->isOccurrence($dn);
    }

    public function withoutBound(): self
    {
        return new self($this->rule->withBound(null, null), $this->startDn, $this->tz, $this->allDay, $this->clock, $this->length, $this->anchor ?? false, $this->stored);
    }

    /** The local day an instant falls on in the series' zone. */
    public function dnOf(string $dbUtc): int
    {
        return Cal::dnOf(Time::fromDb($dbUtc)->setTimezone($this->allDay ? Time::utc() : $this->zone()));
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
        $tz = isset($m[7]) && $m[7] === 'Z' ? Time::utc() : ($this->allDay ? Time::utc() : $this->zone());
        $text = sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4] ?? '00', $m[5] ?? '00', $m[6] ?? '00');
        return (new \DateTimeImmutable($text, $tz))->setTimezone(Time::utc());
    }

    public function bounded(): bool
    {
        return $this->rule->count !== null || $this->rule->until !== null;
    }

    /** Every raw occurrence of a bounded series, one per day. @return list<int> */
    public function all(): array
    {
        return $this->occurrences(PHP_INT_MAX);
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

    // ---- sabre -------------------------------------------------------------

    private static function cacheKey(string $rrule, \DateTimeImmutable $start, string $tz, bool $allDay): string
    {
        return $rrule . '|' . $start->getTimestamp() . '|' . ($allDay ? 'D' : Time::normalizeTzid($tz));
    }

    /**
     * sabre's raw occurrences of a stored rule from a stored start, as [local
     * day, key], through $maxDn and at most $max, extended on demand and
     * kept per stored form. A rule sabre can't read gives none.
     *
     * @return list<array{0:int,1:string}>
     */
    private static function expansion(string $rrule, \DateTimeImmutable $start, string $tz, bool $allDay, int $maxDn, int $max): array
    {
        $ck = self::cacheKey($rrule, $start, $tz, $allDay);
        if (!isset(self::$cache[$ck])) {
            if (count(self::$cache) > 256) {
                self::$cache = array_slice(self::$cache, -128, null, true);
            }
            $entry = ['keys' => [], 'it' => null, 'done' => false, 'ok' => true];
            try {
                $local = $allDay ? new \DateTimeImmutable($start->setTimezone(Time::utc())->format('Y-m-d') . ' 00:00:00', Time::utc()) : $start->setTimezone(Time::zone($tz));
                $entry['it'] = new RuleIterator($rrule, $local);
            } catch (\Throwable) {
                $entry['done'] = true;
                $entry['ok'] = false;
            }
            self::$cache[$ck] = $entry;
        }
        $e = &self::$cache[$ck];
        $zone = $allDay ? Time::utc() : Time::zone($tz);
        while (!$e['done'] && count($e['keys']) < $max && ($e['keys'] === [] || $e['keys'][count($e['keys']) - 1][0] <= $maxDn)) {
            try {
                if (!$e['it']->valid() || count($e['keys']) >= self::MAX_STEPS) {
                    $e['done'] = true;
                    break;
                }
                $at = \DateTimeImmutable::createFromInterface($e['it']->current());
                $dn = Cal::dnOf($at->setTimezone($zone));
                $e['keys'][] = [$dn, $allDay ? Cal::date($dn) . ' 00:00:00' : Time::toDb($at)];
                $e['it']->next();
            } catch (\Throwable) {
                $e['done'] = true;
                $e['ok'] = $e['keys'] !== [];
            }
        }
        $out = [];
        foreach ($e['keys'] as $k) {
            if ($k[0] > $maxDn || count($out) >= $max) {
                break;
            }
            $out[] = $k;
        }
        unset($e);
        return $out;
    }

    // ---- wall clocks -------------------------------------------------------

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

    /** Whether a clock time doesn't exist on a local day (a spring-forward gap). */
    public static function inGap(int $dn, array $clock, string $tz): bool
    {
        return self::at($dn, $clock, $tz)->setTimezone(Time::zone($tz))->format('H:i:s') !== sprintf('%02d:%02d:%02d', ...$clock);
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
