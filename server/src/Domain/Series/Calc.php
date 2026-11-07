<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

use BetterCal\Support\Time;

/**
 * A series' raw occurrences (no skips applied), read from the expander the
 * app persists and shows (RuleIterator), without the display cap: every day
 * and key here is one the expander yields for the series as it is stored.
 * Nothing in the model computes an occurrence's instant by itself, so the
 * model and the calendar never differ on a key.
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
    /**
     * No series is walked further than this many occurrences: a memory guard
     * on the keys kept per stored form, reached only by a rule several times
     * a day read for decades. Reaching it is WalkLimitReached, never the
     * series' end.
     */
    public const MAX_STEPS = 400000;

    /**
     * The walk cache is bounded by what it costs, not only by how many forms
     * it holds. Measured on PHP 8.4: a kept day costs about 550 bytes (its
     * [day, key] pair, the key's string and the list's slot), and a form
     * about 12 KB on its own (its iterator and the iterator's walker), so 256
     * small forms are about 3 MB. A request on 128 MB hosting can spare a
     * few MB for the cache: 6 MiB in all, about 11,000 days across the forms
     * held. One walk over 2 MiB (about 3,800 days, ten years of a daily
     * series) is not kept at all: it would push out every other form, and
     * one that long is rarely read twice in a request. An eight-year hourly
     * walk (70,000 days, about 38 MB) was the case that showed it.
     */
    public const CACHE_BYTES = 6 * 1024 * 1024;
    public const CACHE_ENTRY_MAX_BYTES = 2 * 1024 * 1024;
    public const CACHE_ENTRIES = 256;
    private const KEY_BYTES = 550;
    private const ENTRY_BYTES = 12288;

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
        $rule ??= Rule::parse($allDay ? \BetterCal\Domain\Recurrence::inForm((string) $row['rrule'], true, 'UTC') : (string) $row['rrule'], $dn);
        $calc = new self($rule, $dn, $tz, $allDay, $clock, $len, false, $start);
        // Only to find an anchored start: a skip list quarantined over its
        // limit (decodeExdates refuses it) is read as none here, and every
        // path that writes or acts on it refuses it (Events::decodeExdates).
        try {
            $skips = \BetterCal\Domain\Recurrence::decodeExdates($row['exdates_json'] ?? null);
        } catch (\InvalidArgumentException) {
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
        // A confirmation of what the rule's structure guarantees, not a
        // bound: the day found is a day of the rule's members in the start's
        // phase, so the pattern from it is the start's; its first 40
        // occurrences check the arithmetic against the expander.
        $want = array_column(self::expansion($r->text(), self::at($this->startDn, $this->clock, $this->tz), $this->tz, false, PHP_INT_MAX, 40), 0);
        $p = $r->periodOf($this->startDn);
        // Back through the rule's cycle (Rule::cycle): none there is none ever.
        for ($i = 0, $n = $r->cycle(); $i < $n; $i++) {
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

    /**
     * Every raw occurrence on or after a local day, as [local day, key], in
     * order, read on only as far as they are taken. The expander steps from
     * the series' start, since a rule's days follow from its start and its
     * count, so however many keys come before the day, every one from it on
     * is there.
     *
     * @return \Generator<int, array{0:int,1:string}>
     */
    public function keysFrom(int $fromDn): \Generator
    {
        $i = 0;
        $span = 400;
        while (true) {
            $this->extend($fromDn + $span, PHP_INT_MAX, false);
            // Read through $readTo, every key on those days is in the list.
            $through = $this->complete ? PHP_INT_MAX : (int) $this->readTo;
            for (; $i < count($this->list) && $this->list[$i][0] <= $through; $i++) {
                if ($this->list[$i][0] >= $fromDn) {
                    yield $this->list[$i];
                }
            }
            if ($this->complete) {
                return;
            }
            $span *= 2;
        }
    }

    /** @var list<array{0:int,1:string}> the expansion read so far, the anchor day left out */
    private array $list = [];
    /** @var array<int,list<string>> the same, by local day */
    private array $byDay = [];
    /** Every key on a day up to this one has been read. */
    private ?int $readTo = null;
    /** How many of the expansion's keys are in the list: a longer read appends the rest. */
    private int $taken = 0;
    /** @var ?array{keys:list<array{0:int,1:string}>,it:?\Iterator,done:bool,ok:bool} a form too big for the shared cache, read on from here */
    private ?array $own = null;
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
            // The expansion through a later day is the earlier one and more
            // (one iterator, read on), so only the keys past those taken
            // are added: a series read a little further isn't copied whole.
            $raw = self::expansion($this->storedRule()->text(), $this->storedStart(), $this->tz, $this->allDay, $to, PHP_INT_MAX, $state, $this->own);
            for ($i = $this->taken, $n = count($raw); $i < $n; $i++) {
                $k = $raw[$i];
                if ($this->anchor !== null && $k[0] === $this->anchor) {
                    continue;
                }
                $this->list[] = $k;
                $this->byDay[$k[0]][] = $k[1];
            }
            $this->taken = count($raw);
            $this->readTo = $to;
            $this->complete = $state['whole'];
            if ($to >= $limit) {
                $this->complete = true;
            }
        }
    }

    /** Whether the expander reads the stored rule at all. */
    public function expandable(): bool
    {
        self::expansion($this->storedRule()->text(), $this->storedStart(), $this->tz, $this->allDay, $this->startDn, 1, $state);
        return $state['ok'];
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
            // A day's one key; a rule several times a day is refused every
            // series-wide move that reads a day's instant, and "this event"
            // reads its keys (Several times a day can collide).
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

    /** UNTIL as an instant, read the way the iterator reads it: a date is the end of that day in the series' zone. */
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
        $text = isset($m[4]) && $m[4] !== '' ? sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]) : sprintf('%s-%s-%s 23:59:59', $m[1], $m[2], $m[3]);
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
     * The expander's raw occurrences of a stored rule from a stored start, as
     * [local day, key], through $maxDn and at most $max, extended on demand
     * and kept per stored form while the cache can afford it (CACHE_BYTES).
     * A rule the expander can't read (RuleNotReadable) gives none and reads
     * as not expandable. $state says whether the answer is the whole series
     * (the walk is done and every day it holds was returned) and whether the
     * expander reads the rule.
     *
     * A form the cache can't afford (over CACHE_ENTRY_MAX_BYTES) is handed
     * to the reader in $own, which passes it back to read on from where it
     * got to, instead of walking the whole series again from its start.
     *
     * @param-out array{whole:bool,ok:bool} $state
     * @param ?array{keys:list<array{0:int,1:string}>,it:?\Iterator,done:bool,ok:bool} $own
     * @return list<array{0:int,1:string}>
     */
    private static function expansion(string $rrule, \DateTimeImmutable $start, string $tz, bool $allDay, int $maxDn, int $max, ?array &$state = null, ?array &$own = null): array
    {
        $ck = self::cacheKey($rrule, $start, $tz, $allDay);
        if ($own !== null) {
            $e = &$own;
        } else {
            if (!isset(self::$cache[$ck])) {
                $entry = ['keys' => [], 'it' => null, 'done' => false, 'ok' => true];
                try {
                    $local = $allDay ? new \DateTimeImmutable($start->setTimezone(Time::utc())->format('Y-m-d') . ' 00:00:00', Time::utc()) : $start->setTimezone(Time::zone($tz));
                    // An all-day series is read in its own form: no clock parts.
                    $entry['it'] = new RuleIterator($allDay ? \BetterCal\Domain\Recurrence::inForm($rrule, true, 'UTC') : $rrule, $local);
                } catch (RuleNotReadable) {
                    $entry['done'] = true;
                    $entry['ok'] = false;
                }
                self::$cache[$ck] = $entry;
            }
            $e = &self::$cache[$ck];
        }
        // A walk that can't be completed (a limit of the walk, MAX_STEPS
        // here or the iterator's own) is never the series' end: the caller
        // sees it as WalkIncomplete, and the stored form is from then on one
        // the expander can't read (no keys, expandable() false), as a rule
        // it refuses is, so the model reads it that way (Model::read). Any
        // other failure is a defect and propagates as itself.
        $zone = $allDay ? Time::utc() : Time::zone($tz);
        while (!$e['done'] && count($e['keys']) < $max && ($e['keys'] === [] || $e['keys'][count($e['keys']) - 1][0] <= $maxDn)) {
            try {
                if (!$e['it']->valid()) {
                    $e['done'] = true;
                    break;
                }
                if (count($e['keys']) >= self::MAX_STEPS) {
                    throw new WalkLimitReached('No series is walked further than ' . self::MAX_STEPS . ' occurrences');
                }
                $at = \DateTimeImmutable::createFromInterface($e['it']->current());
                $dn = Cal::dnOf($at->setTimezone($zone));
                $e['keys'][] = [$dn, $allDay ? Cal::date($dn) . ' 00:00:00' : Time::toDb($at)];
                $e['it']->next();
            } catch (WalkIncomplete $thrown) {
                $e['done'] = true;
                $e['ok'] = false;
                $e['keys'] = [];
                unset($e);
                throw $thrown;
            }
        }
        $out = [];
        foreach ($e['keys'] as $k) {
            if ($k[0] > $maxDn || count($out) >= $max) {
                break;
            }
            $out[] = $k;
        }
        $state = ['whole' => $e['done'] && count($out) === count($e['keys']), 'ok' => $e['ok']];
        if ($own !== null) {
            unset($e);
            return $out;
        }
        if (self::cost($e) > self::CACHE_ENTRY_MAX_BYTES) {
            $own = $e;
            unset($e);
            unset(self::$cache[$ck]);
            return $out;
        }
        unset($e);
        self::bound($ck);
        return $out;
    }

    /**
     * Keeps the cache within its bounds after form $ck was read: a form
     * costing more than CACHE_ENTRY_MAX_BYTES is dropped (read again, the
     * same days, never fewer, if it is asked for again), then the oldest
     * forms go until the rest fit CACHE_BYTES and CACHE_ENTRIES.
     */
    private static function bound(string $ck): void
    {
        if (isset(self::$cache[$ck]) && self::cost(self::$cache[$ck]) > self::CACHE_ENTRY_MAX_BYTES) {
            unset(self::$cache[$ck]);
        }
        // Summed each time rather than kept as a running total, so nothing
        // that replaces the cache can leave a stale count behind; 256 forms
        // at most.
        $bytes = 0;
        foreach (self::$cache as $entry) {
            $bytes += self::cost($entry);
        }
        while (self::$cache !== [] && ($bytes > self::CACHE_BYTES || count(self::$cache) > self::CACHE_ENTRIES)) {
            $oldest = array_key_first(self::$cache);
            $bytes -= self::cost(self::$cache[$oldest]);
            unset(self::$cache[$oldest]);
        }
    }

    /** @param array{keys:list<array{0:int,1:string}>} $entry */
    private static function cost(array $entry): int
    {
        return self::ENTRY_BYTES + count($entry['keys']) * self::KEY_BYTES;
    }

    /** @return array{forms:int,bytes:int,days:int} what the walk cache holds, for tests and diagnostics */
    public static function cacheUsage(): array
    {
        return ['forms' => count(self::$cache), 'bytes' => array_sum(array_map(self::cost(...), self::$cache)), 'days' => array_sum(array_map(static fn(array $e): int => count($e['keys']), self::$cache))];
    }

    // ---- wall clocks -------------------------------------------------------

    /**
     * The instant a wall clock names on a local day in a zone. An instant
     * given stays that instant: when $given is that wall clock (one of the
     * two a repeated hour names), it is the answer, so a day, clock and zone
     * read back from an instant never become the other instant. With none
     * given, a repeated wall clock is its first instant.
     *
     * @param array{0:int,1:int,2:int} $clock
     */
    public static function at(int $dn, array $clock, string $tz, ?\DateTimeImmutable $given = null): \DateTimeImmutable
    {
        $wall = sprintf('%s %02d:%02d:%02d', Cal::date($dn), $clock[0], $clock[1], $clock[2]);
        $zone = Time::zone($tz);
        if ($given !== null && $given->setTimezone($zone)->format('Y-m-d H:i:s') === $wall) {
            return $given->setTimezone(Time::utc());
        }
        // The expander's one rule for a wall clock (Wall::resolve): a clock
        // that happens twice is the first (RFC 5545 3.3.5; PHP alone takes
        // the second), one in a gap the clock plus the gap.
        return Wall::resolve($dn, $clock, $zone)->setTimezone(Time::utc());
    }

    /**
     * Every instant a wall clock names on a local day in a zone, in order,
     * from the zone's own transitions: none in a spring-forward gap, two
     * in a repeated span (an hour mostly, half an hour on Lord Howe
     * Island), one otherwise.
     *
     * @param array{0:int,1:int,2:int} $clock
     * @return list<\DateTimeImmutable>
     */
    public static function instantsAt(int $dn, array $clock, string $tz): array
    {
        return Wall::instants($dn, $clock, Time::zone($tz));
    }

    /** Whether a clock time doesn't exist on a local day (a spring-forward gap). */
    public static function inGap(int $dn, array $clock, string $tz): bool
    {
        return self::at($dn, $clock, $tz)->setTimezone(Time::zone($tz))->format('H:i:s') !== sprintf('%02d:%02d:%02d', ...$clock);
    }

    /**
     * Whether the expander can read the rule at all (Rule::table): a part
     * RFC 5545 doesn't define (RSCALE, an X- part), a value outside its
     * grammar or a part its table marks N/A for the frequency is a rule it
     * can't read.
     */
    public static function reads(string $rrule): bool
    {
        try {
            new RuleIterator($rrule, new \DateTimeImmutable('2026-01-01', Time::utc()));
            return true;
        } catch (RuleNotReadable) {
            return false;
        }
    }
}
