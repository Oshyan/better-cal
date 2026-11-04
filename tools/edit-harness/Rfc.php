<?php
// The harness's own evaluator of a recurrence rule (README, The Oracle):
// RFC 5545 3.3.10 and 3.8.5.3 read plainly, from the rule's text, the
// series' stored start and its zone's tzdata alone. It calls nothing in
// server/src (no Calc, Recurrence, RuleIterator or sabre) and never reads
// the expander's stopping conditions: a rule ends at its COUNT, at its
// UNTIL, or not at all, and a search that reaches the evaluator's own
// horizon is an incomplete evaluation, never an end.
//
// Each period of FREQ, stepped by INTERVAL from the start's own, is the set
// of its days; each BY part is one function applied to it in the RFC's
// order (BYMONTH, BYWEEKNO, BYYEARDAY, BYMONTHDAY, BYDAY, then the start's
// own day where no part names one, then BYHOUR, BYMINUTE, BYSECOND), and
// BYSETPOS last. On the set of a whole period an "expand" and a "limit"
// both keep the members that match, so each part is a filter; the table's
// N/A cells are rules this evaluator does not read (named in $unread).
//
// Where the product contract states a rule the RFC doesn't, the contract
// decides (series-model.md, Zones, DST and All-Day Days; the drag table):
// a clock that doesn't exist is the clock plus the gap, except in a rule
// naming its hour (BYHOUR), which has no occurrence there; a clock that
// happens twice is its first instant, the stored start excepted (an instant
// given stays that instant); a DAILY rule with BYDAY and INTERVAL 1 keeps
// the shifted clock after a spring gap on every later day; HOURLY,
// MINUTELY and SECONDLY periods are stepped in elapsed time from the start.
declare(strict_types=1);

namespace BetterCal\EditHarness;

final class Rfc
{
    /** How far from its start the evaluator looks for a rule's occurrences. */
    public const HORIZON_YEARS = 400;
    /** Periods looked at in one evaluation before it is called incomplete (a guard on the harness's own time, not an end). */
    private const MAX_PERIODS = 3000000;
    private const PARTS = ['FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'WKST', 'BYMONTH', 'BYWEEKNO', 'BYYEARDAY', 'BYMONTHDAY', 'BYDAY', 'BYHOUR', 'BYMINUTE', 'BYSECOND', 'BYSETPOS'];
    private const SUBDAILY = ['HOURLY' => 3600, 'MINUTELY' => 60, 'SECONDLY' => 1];

    /** Why the evaluator doesn't read the rule (a part the RFC marks N/A for its FREQ, a part it doesn't know), or null. */
    public ?string $unread = null;
    /** Whether the rule is one the expander can't read by design (RSCALE, an X- part): refused, outside the evaluator. */
    public bool $refused = false;
    /** Why the evaluation stopped short of the rule's bound (its horizon, its period guard), or null. */
    public ?string $incomplete = null;
    /** Whether the start is a member of its own period's set (RFC 5545 3.8.5.3 leaves a start off its rule undefined). */
    public ?bool $startOnRule = null;
    /** Whether every occurrence the rule has is read (its COUNT or UNTIL reached). */
    public bool $complete = false;

    public string $freq = '';
    public int $interval = 1;
    public ?int $count = null;
    public ?int $untilTs = null;
    public ?int $untilDn = null;
    public int $wkst = 0;
    /** @var array<string,list<int>> the numeric BY lists */
    private array $by = [];
    /** @var list<array{0:?int,1:int}> BYDAY entries: [ordinal or null, weekday] */
    private array $byDay = [];
    private bool $hasByDay = false;

    private string $tz;
    private ?\DateTimeZone $zoneObj = null;
    private bool $allDay;
    private int $startTs;
    private int $startDn;
    private int $startSecs;
    /** @var list<array{0:int,1:int,2:int}> the occurrences read so far: [instant, local day, local clock] */
    private array $occ = [];
    private int $k = 0;
    private int $periods = 0;
    /** The DAILY;BYDAY clock carried past a spring gap (product rule), in seconds. */
    private int $carry = 0;

    /** @var array<string,self> */
    private static array $cache = [];

    /** The evaluator for a stored series row (rrule, start_utc, tzid, all_day), shared by every reading of the same series. */
    public static function ofRow(array $row): self
    {
        $allDay = (int) ($row['all_day'] ?? 0) === 1;
        $tz = $allDay ? 'UTC' : (string) ($row['tzid'] ?? 'UTC');
        $ts = (new \DateTimeImmutable((string) $row['start_utc'], new \DateTimeZone('UTC')))->getTimestamp();
        return self::of((string) $row['rrule'], $ts, $tz, $allDay);
    }

    public static function of(string $rrule, int $startTs, string $tz, bool $allDay): self
    {
        $key = "$rrule|$startTs|$tz|" . ($allDay ? 1 : 0);
        if (count(self::$cache) > 2000) {
            self::$cache = [];
        }
        return self::$cache[$key] ??= new self($rrule, $startTs, $tz, $allDay);
    }

    private function __construct(string $rrule, int $startTs, string $tz, bool $allDay)
    {
        $this->tz = $tz;
        $this->allDay = $allDay;
        $this->startTs = $startTs;
        $local = (new \DateTimeImmutable('@' . $startTs))->setTimezone(new \DateTimeZone($tz));
        $this->startDn = Days::dn((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));
        $this->startSecs = (int) $local->format('G') * 3600 + (int) $local->format('i') * 60 + (int) $local->format('s');
        $this->parse($rrule);
    }

    /** Which parts the rule names and how it is bound, as the run's summary reports what was oracled ("DAILY BYMONTH BYMONTHDAY, COUNT"). */
    public function combination(): string
    {
        $parts = array_keys($this->by);
        if ($this->hasByDay) {
            $parts[] = array_filter($this->byDay, static fn(array $d): bool => $d[0] !== null) !== [] ? 'BYDAY(nth)' : 'BYDAY';
        }
        $order = array_flip(self::PARTS + [99 => 'BYDAY(nth)']);
        usort($parts, static fn(string $a, string $b): int => ($order[$a] ?? $order['BYDAY']) <=> ($order[$b] ?? $order['BYDAY']));
        $bound = $this->count !== null ? 'COUNT' : ($this->untilTs !== null || $this->untilDn !== null ? 'UNTIL' : 'none');
        return trim($this->freq . ($this->interval > 1 ? ' INTERVAL' : '') . ' ' . implode(' ', $parts)) . ($this->allDay ? ' (all-day)' : '') . ", $bound";
    }

    /**
     * The hand-listed examples (rfc-examples.php) the evaluator must give:
     * each one it gets otherwise, with what it gave.
     *
     * @return list<array>
     */
    public static function examples(): array
    {
        $bad = [];
        foreach (require __DIR__ . '/rfc-examples.php' as [$rule, $start, $tz, $allDay, $n, $want]) {
            $at = str_contains($start, 'T') ? new \DateTimeImmutable($start) : new \DateTimeImmutable($start, new \DateTimeZone($allDay ? 'UTC' : $tz));
            $ev = new self($rule, $at->getTimestamp(), $allDay ? 'UTC' : $tz, $allDay);
            $got = array_map(static fn(array $o): string => (new \DateTimeImmutable('@' . $o[0]))->setTimezone(new \DateTimeZone($allDay ? 'UTC' : $tz))->format('Y-m-d\TH:i:sP'), $n === null ? $ev->all() : $ev->first($n));
            if ($got !== $want || ($n === null && !$ev->complete)) {
                $bad[] = ['rule' => $rule, 'start' => $start, 'tz' => $tz, 'want' => $want, 'got' => $got, 'incomplete' => $ev->incomplete];
            }
        }
        return $bad;
    }

    // ---- reading -----------------------------------------------------------

    /**
     * Every occurrence with an instant at or before $ts, in order, read on
     * as far as that takes (at most to the rule's bound or the horizon).
     *
     * @return list<array{0:int,1:int,2:int}>
     */
    public function through(int $ts): array
    {
        $this->extend($ts);
        return array_values(array_filter($this->occ, static fn(array $o): bool => $o[0] <= $ts));
    }

    /** The first $n occurrences (fewer when the rule has fewer). @return list<array{0:int,1:int,2:int}> */
    public function first(int $n): array
    {
        while (count($this->occ) < $n && !$this->complete && $this->incomplete === null) {
            $this->step();
        }
        return array_slice($this->occ, 0, $n);
    }

    /** Every occurrence of a bounded rule (incomplete when its bound lies past the horizon). @return list<array{0:int,1:int,2:int}> */
    public function all(): array
    {
        while (!$this->complete && $this->incomplete === null) {
            $this->step();
        }
        return $this->occ;
    }

    public function bounded(): bool
    {
        return $this->count !== null || $this->untilTs !== null || $this->untilDn !== null;
    }

    private function extend(int $ts): void
    {
        while (!$this->complete && $this->incomplete === null && $this->periodFloor($this->k) <= $ts) {
            $this->step();
        }
    }

    // ---- the rule's text ---------------------------------------------------

    private function parse(string $rrule): void
    {
        $p = [];
        foreach (explode(';', strtoupper(trim($rrule))) as $kv) {
            if ($kv === '' || !str_contains($kv, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $kv, 2);
            $p[$k] = $v;
        }
        foreach (array_keys($p) as $k) {
            if ($k === 'RSCALE' || str_starts_with($k, 'X-')) {
                $this->refused = true;
                $this->unread = "$k (a rule the expander can't read, refused by design)";
                return;
            }
            if (!in_array($k, self::PARTS, true)) {
                $this->unread = "$k is no part RFC 5545 names";
                return;
            }
        }
        $this->freq = $p['FREQ'] ?? '';
        if (!in_array($this->freq, ['SECONDLY', 'MINUTELY', 'HOURLY', 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            $this->unread = 'FREQ ' . $this->freq;
            return;
        }
        $this->interval = max(1, (int) ($p['INTERVAL'] ?? 1));
        $this->count = isset($p['COUNT']) ? (int) $p['COUNT'] : null;
        if (isset($p['UNTIL'])) {
            $u = $p['UNTIL'];
            if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $u, $m) === 1) {
                $this->untilDn = Days::dn((int) $m[1], (int) $m[2], (int) $m[3]);
            } elseif (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/', $u, $m) === 1) {
                $this->untilTs = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
            } else {
                $this->unread = "UNTIL $u";
                return;
            }
        }
        if (isset($p['WKST'])) {
            $this->wkst = Days::wdOf($p['WKST']);
        }
        foreach (['BYMONTH', 'BYWEEKNO', 'BYYEARDAY', 'BYMONTHDAY', 'BYHOUR', 'BYMINUTE', 'BYSECOND', 'BYSETPOS'] as $k) {
            if (isset($p[$k])) {
                $this->by[$k] = array_map('intval', explode(',', $p[$k]));
            }
        }
        if (isset($p['BYDAY'])) {
            $this->hasByDay = true;
            foreach (explode(',', $p['BYDAY']) as $d) {
                if (preg_match('/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', $d, $m) !== 1) {
                    $this->unread = "BYDAY $d";
                    return;
                }
                $this->byDay[] = [($m[1] ?? '') === '' ? null : (int) $m[1], Days::wdOf($m[2])];
            }
        }
        // The table's N/A cells (RFC 5545 3.3.10): rules this evaluator doesn't read.
        $nth = array_filter($this->byDay, static fn(array $d): bool => $d[0] !== null) !== [];
        $na = match (true) {
            isset($this->by['BYWEEKNO']) && $this->freq !== 'YEARLY' => 'BYWEEKNO outside YEARLY',
            isset($this->by['BYYEARDAY']) && in_array($this->freq, ['DAILY', 'WEEKLY', 'MONTHLY'], true) => "BYYEARDAY with {$this->freq}",
            isset($this->by['BYMONTHDAY']) && $this->freq === 'WEEKLY' => 'BYMONTHDAY with WEEKLY',
            $nth && !in_array($this->freq, ['MONTHLY', 'YEARLY'], true) => "a numbered BYDAY with {$this->freq}",
            $nth && isset($this->by['BYWEEKNO']) => 'a numbered BYDAY with BYWEEKNO',
            $this->allDay && (isset($this->by['BYHOUR']) || isset($this->by['BYMINUTE']) || isset($this->by['BYSECOND']) || isset(self::SUBDAILY[$this->freq])) => 'a clock part on an all-day series',
            default => null,
        };
        $this->unread = $na;
    }

    // ---- periods -----------------------------------------------------------

    /** An instant no occurrence of period $k comes before (its first day's local midnight, a day early for any zone). */
    private function periodFloor(int $k): int
    {
        if (isset(self::SUBDAILY[$this->freq])) {
            return $this->startTs + $k * $this->interval * self::SUBDAILY[$this->freq] - 3600;
        }
        return ($this->periodFirstDay($k) - 1) * 86400;
    }

    private function periodFirstDay(int $k): int
    {
        [$y, $m] = Days::ymd($this->startDn);
        return match ($this->freq) {
            'YEARLY' => Days::dn($y + $k * $this->interval, 1, 1) - 7, // BYWEEKNO's weeks reach into the year before
            'MONTHLY' => Days::dn(...[...Days::fromMi(Days::mi($y, $m) + $k * $this->interval), 1]),
            'WEEKLY' => $this->weekStart($this->startDn) + 7 * $k * $this->interval,
            default => $this->startDn + $k * $this->interval,
        };
    }

    /** The first day of the week (WKST) holding $dn. */
    private function weekStart(int $dn): int
    {
        return $dn - ((Days::wd($dn) - $this->wkst + 7) % 7);
    }

    /** Read one more period: its occurrences after the start, bounded by COUNT and UNTIL. */
    private function step(): void
    {
        if ($this->unread !== null) {
            $this->incomplete = 'not read: ' . $this->unread;
            return;
        }
        if ($this->k === 0) {
            $this->occ[] = [$this->startTs, $this->startDn, $this->startSecs]; // DTSTART is always the first occurrence (3.8.5.3)
            if ($this->count !== null && $this->count <= 1) {
                $this->complete = true;
                return;
            }
        }
        if (++$this->periods > self::MAX_PERIODS) {
            $this->incomplete = 'the evaluator\'s period guard (' . self::MAX_PERIODS . ' periods)';
            return;
        }
        $k = $this->k++;
        if ($this->periodFloor($k) > $this->startTs + self::HORIZON_YEARS * 366 * 86400) {
            $this->incomplete = 'the evaluator\'s horizon (' . self::HORIZON_YEARS . ' years from the start)';
            return;
        }
        $set = isset(self::SUBDAILY[$this->freq]) ? $this->subDailyPeriod($k) : $this->datePeriod($k);
        foreach ($set as $o) {
            if ($o[0] <= $this->startTs) {
                continue;
            }
            if (($this->untilTs !== null && $o[0] > $this->untilTs) || ($this->untilDn !== null && $o[1] > $this->untilDn)) {
                $this->complete = true;
                return;
            }
            $this->occ[] = $o;
            if ($this->count !== null && count($this->occ) >= $this->count) {
                $this->complete = true;
                return;
            }
        }
        // With no occurrence left before UNTIL, the rule has ended once a period starts past it.
        if (($this->untilTs !== null && $this->periodFloor($this->k) > $this->untilTs + 86400) || ($this->untilDn !== null && $this->periodFloor($this->k) > ($this->untilDn + 2) * 86400)) {
            $this->complete = true;
        }
    }

    /**
     * A period of a frequency of days or longer: its days, each part's
     * filter in order, the clock parts, BYSETPOS, then each local time's
     * instant.
     *
     * @return list<array{0:int,1:int,2:int}>
     */
    private function datePeriod(int $k): array
    {
        $days = $this->periodDays($k);
        if ($this->freq === 'DAILY') {
            $this->carryGap($days[0]);
        }
        $days = $this->byMonth($days);
        $days = $this->byWeekNo($days, $k);
        $days = $this->byYearDay($days);
        $days = $this->byMonthDay($days);
        $days = $this->byDayFilter($days);
        $days = $this->startDay($days);
        $times = $this->bySetPos($this->clockParts($days));
        if ($k === 0) {
            $this->startOnRule = in_array([$this->startDn, $this->startSecs], $times, true);
        }
        $out = [];
        foreach ($times as [$dn, $secs]) {
            $ts = $this->instant($dn, $secs);
            if ($ts !== null) {
                $out[$ts] = [$ts, $dn, $this->localSecs($ts)];
            }
        }
        ksort($out);
        return array_values($out);
    }

    /** @return list<int> the days of period $k, before any part */
    private function periodDays(int $k): array
    {
        [$y0, $m0] = Days::ymd($this->startDn);
        switch ($this->freq) {
            case 'YEARLY':
                $y = $y0 + $k * $this->interval;
                if (isset($this->by['BYWEEKNO'])) {
                    // The year's weeks by number, which may reach into the years either side.
                    $first = $this->week1($y);
                    return range($first - 7, $this->week1($y + 1) + 6);
                }
                return range(Days::dn($y, 1, 1), Days::dn($y, 12, 31));
            case 'MONTHLY':
                [$y, $m] = Days::fromMi(Days::mi($y0, $m0) + $k * $this->interval);
                return range(Days::dn($y, $m, 1), Days::dn($y, $m, Days::monthLen($y, $m)));
            case 'WEEKLY':
                $ws = $this->weekStart($this->startDn) + 7 * $k * $this->interval;
                return range($ws, $ws + 6);
            default:
                return [$this->startDn + $k * $this->interval];
        }
    }

    /** BYMONTH: the days in the listed months. */
    private function byMonth(array $days): array
    {
        if (!isset($this->by['BYMONTH'])) {
            return $days;
        }
        return array_values(array_filter($days, fn(int $d): bool => in_array(Days::ymd($d)[1], $this->by['BYMONTH'], true)));
    }

    /** BYWEEKNO (YEARLY): the days in the listed weeks of the period's year, week 1 the first with four days in it, negative from the year's last week. */
    private function byWeekNo(array $days, int $k): array
    {
        if (!isset($this->by['BYWEEKNO'])) {
            return $days;
        }
        $y = Days::ymd($this->startDn)[0] + $k * $this->interval;
        $w1 = $this->week1($y);
        $weeks = intdiv($this->week1($y + 1) - $w1, 7);
        return array_values(array_filter($days, function (int $d) use ($w1, $weeks): bool {
            if ($d < $w1) {
                return false;
            }
            $n = intdiv($d - $w1, 7) + 1;
            return $n <= $weeks && (in_array($n, $this->by['BYWEEKNO'], true) || in_array($n - $weeks - 1, $this->by['BYWEEKNO'], true));
        }));
    }

    /** The first day of week 1 of year $y: the week (from WKST) holding at least four of the year's days. */
    private function week1(int $y): int
    {
        $jan1 = Days::dn($y, 1, 1);
        $ws = $this->weekStart($jan1);
        return $jan1 - $ws <= 3 ? $ws : $ws + 7;
    }

    /** BYYEARDAY: the days whose number in their year (negative from its end) is listed. */
    private function byYearDay(array $days): array
    {
        if (!isset($this->by['BYYEARDAY'])) {
            return $days;
        }
        return array_values(array_filter($days, function (int $d): bool {
            $y = Days::ymd($d)[0];
            $n = $d - Days::dn($y, 1, 1) + 1;
            $len = Days::leap($y) ? 366 : 365;
            return in_array($n, $this->by['BYYEARDAY'], true) || in_array($n - $len - 1, $this->by['BYYEARDAY'], true);
        }));
    }

    /** BYMONTHDAY: the days whose number in their month (negative from its end) is listed. */
    private function byMonthDay(array $days): array
    {
        if (!isset($this->by['BYMONTHDAY'])) {
            return $days;
        }
        return array_values(array_filter($days, function (int $d): bool {
            [$y, $m, $md] = Days::ymd($d);
            return in_array($md, $this->by['BYMONTHDAY'], true) || in_array($md - Days::monthLen($y, $m) - 1, $this->by['BYMONTHDAY'], true);
        }));
    }

    /**
     * BYDAY: the days on a listed weekday; a numbered one is that weekday's
     * nth in its month (MONTHLY, or YEARLY with BYMONTH) or in its year
     * (YEARLY otherwise), negative from the end.
     */
    private function byDayFilter(array $days): array
    {
        if (!$this->hasByDay) {
            return $days;
        }
        $inMonth = $this->freq === 'MONTHLY' || isset($this->by['BYMONTH']);
        return array_values(array_filter($days, function (int $d) use ($inMonth): bool {
            $wd = Days::wd($d);
            foreach ($this->byDay as [$n, $want]) {
                if ($wd !== $want) {
                    continue;
                }
                if ($n === null) {
                    return true;
                }
                [$y, $m] = Days::ymd($d);
                [$from, $to] = $inMonth ? [Days::dn($y, $m, 1), Days::dn($y, $m, Days::monthLen($y, $m))] : [Days::dn($y, 1, 1), Days::dn($y, 12, 31)];
                $fromStart = intdiv($d - $from, 7) + 1;
                $fromEnd = -(intdiv($to - $d, 7) + 1);
                if ($n === $fromStart || $n === $fromEnd) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * The start's own day where no part names one (3.3.10: what a part
     * leaves out is taken from DTSTART): a weekly rule's weekday, a monthly
     * rule's day of the month, a yearly rule's day and month (its day only,
     * with BYMONTH; its weekday, with BYWEEKNO alone).
     */
    private function startDay(array $days): array
    {
        [, $sm, $sd] = Days::ymd($this->startDn);
        $wd = Days::wd($this->startDn);
        $named = $this->hasByDay || isset($this->by['BYMONTHDAY']) || isset($this->by['BYYEARDAY']);
        $keep = match (true) {
            $this->freq === 'WEEKLY' && !$this->hasByDay => static fn(int $d): bool => Days::wd($d) === $wd,
            $this->freq === 'MONTHLY' && !$named => static fn(int $d): bool => Days::ymd($d)[2] === $sd,
            $this->freq === 'YEARLY' && !$named && isset($this->by['BYWEEKNO']) => static fn(int $d): bool => Days::wd($d) === $wd,
            $this->freq === 'YEARLY' && !$named && isset($this->by['BYMONTH']) => static fn(int $d): bool => Days::ymd($d)[2] === $sd,
            $this->freq === 'YEARLY' && !$named => static fn(int $d): bool => Days::ymd($d)[1] === $sm && Days::ymd($d)[2] === $sd,
            default => null,
        };
        return $keep === null ? $days : array_values(array_filter($days, $keep));
    }

    /**
     * BYHOUR, BYMINUTE and BYSECOND on each day: every listed hour, minute
     * and second (the start's where a part is missing), sorted.
     *
     * @return list<array{0:int,1:int}> [day, local clock]
     */
    private function clockParts(array $days): array
    {
        $base = $this->startSecs + $this->carry;
        $hours = $this->by['BYHOUR'] ?? [intdiv($base, 3600)];
        $minutes = $this->by['BYMINUTE'] ?? [intdiv($base % 3600, 60)];
        $seconds = $this->by['BYSECOND'] ?? [$base % 60];
        $clocks = [];
        foreach ($hours as $h) {
            foreach ($minutes as $mi) {
                foreach ($seconds as $s) {
                    $clocks[] = $h * 3600 + $mi * 60 + $s;
                }
            }
        }
        $clocks = array_values(array_unique($clocks));
        sort($clocks);
        $out = [];
        foreach ($days as $d) {
            foreach ($clocks as $c) {
                $out[] = [$d, $c];
            }
        }
        return $out;
    }

    /** BYSETPOS, last: the listed positions of the period's sorted set (negative from its end). @param list<mixed> $set */
    private function bySetPos(array $set): array
    {
        if (!isset($this->by['BYSETPOS'])) {
            return $set;
        }
        $out = [];
        $n = count($set);
        foreach ($this->by['BYSETPOS'] as $p) {
            $i = $p > 0 ? $p - 1 : $n + $p;
            if ($p !== 0 && $i >= 0 && $i < $n) {
                $out[$i] = $set[$i];
            }
        }
        ksort($out);
        return array_values($out);
    }

    /**
     * A DAILY rule with BYDAY and INTERVAL 1 keeps the clock its day's
     * spring gap shifted it to on every later day (the drag table's DAILY
     * with BYDAY row: its clock follows the daily iterator), whether or not
     * the gap day is one of its days.
     */
    private function carryGap(int $dn): void
    {
        if (!$this->hasByDay || $this->interval !== 1 || isset($this->by['BYHOUR']) || $this->allDay || $dn <= $this->startDn) {
            return;
        }
        $secs = $this->startSecs + $this->carry;
        if ($this->wallInstants($dn, $secs) === []) {
            $this->carry += $this->gapSize($dn, $secs);
        }
    }

    // ---- frequencies under a day -------------------------------------------

    /**
     * A period of HOURLY, MINUTELY or SECONDLY: INTERVAL hours, minutes or
     * seconds of elapsed time from the start's own; BYMINUTE (HOURLY) and
     * BYSECOND (HOURLY, MINUTELY) expand it into the listed minutes and
     * seconds of its hour or minute (the start's where missing), and every
     * other part limits its instants, read on the zone's wall clock.
     *
     * @return list<array{0:int,1:int,2:int}>
     */
    private function subDailyPeriod(int $k): array
    {
        $unit = self::SUBDAILY[$this->freq];
        $pk = $this->startTs + $k * $this->interval * $unit;
        $local = $this->localSecs($pk);
        $minutes = $this->freq === 'HOURLY' ? ($this->by['BYMINUTE'] ?? [intdiv($this->startSecs % 3600, 60)]) : null;
        $seconds = $this->freq !== 'SECONDLY' ? ($this->by['BYSECOND'] ?? [$this->startSecs % 60]) : null;
        $cands = [];
        if ($this->freq === 'HOURLY') {
            foreach ($minutes as $mi) {
                foreach ($seconds as $s) {
                    $cands[] = $pk - $local % 3600 + $mi * 60 + $s;
                }
            }
        } elseif ($this->freq === 'MINUTELY') {
            foreach ($seconds as $s) {
                $cands[] = $pk - $local % 60 + $s;
            }
        } else {
            $cands[] = $pk;
        }
        $cands = array_values(array_unique($cands));
        sort($cands);
        $out = [];
        foreach ($cands as $ts) {
            $dn = $this->localDn($ts);
            $secs = $this->localSecs($ts);
            $day = [$dn];
            $day = $this->byMonthDay($this->byYearDay($this->byWeekNo($this->byMonth($day), 0)));
            $day = $this->byDayFilter($day);
            if ($day === []) {
                continue;
            }
            if (isset($this->by['BYHOUR']) && !in_array(intdiv($secs, 3600), $this->by['BYHOUR'], true)) {
                continue;
            }
            if ($this->freq !== 'HOURLY' && isset($this->by['BYMINUTE']) && !in_array(intdiv($secs % 3600, 60), $this->by['BYMINUTE'], true)) {
                continue;
            }
            if ($this->freq === 'SECONDLY' && isset($this->by['BYSECOND']) && !in_array($secs % 60, $this->by['BYSECOND'], true)) {
                continue;
            }
            $out[] = [$ts, $dn, $secs];
        }
        if ($k === 0) {
            $this->startOnRule = in_array($this->startTs, array_column($this->bySetPos($out), 0), true);
        }
        // A period whose day the date parts rule out: the periods up to the next local midnight are ruled out with it.
        if ($out === [] && !isset($this->by['BYHOUR']) && !isset($this->by['BYMINUTE']) && !isset($this->by['BYSECOND'])) {
            $midnight = $this->instant($this->localDn($pk) + 1, 0) ?? $pk;
            $skip = intdiv(max(0, $midnight - $pk), $this->interval * $unit);
            if ($skip > 1) {
                $this->k += $skip - 1;
            }
        }
        return $this->bySetPos($out);
    }

    // ---- local time and tzdata ---------------------------------------------

    /**
     * The instant of a local day and clock in the series' zone: one where
     * the clock names one; the first where it names two (the stored start
     * excepted: an instant given stays that instant); where it names none, the
     * clock plus the gap, or none at all in a rule naming its hour.
     */
    private function instant(int $dn, int $secs): ?int
    {
        if ($this->allDay || $this->tz === 'UTC') {
            return $dn * 86400 + $secs;
        }
        $at = $this->wallInstants($dn, $secs);
        if (count($at) === 1) {
            return $at[0];
        }
        if (count($at) > 1) {
            return $dn === $this->startDn && $secs === $this->startSecs && in_array($this->startTs, $at, true) ? $this->startTs : $at[0];
        }
        if (isset($this->by['BYHOUR'])) {
            return null;
        }
        // The offset in force before the gap: the wall clock read on it lands the gap's size later.
        $wall = $dn * 86400 + $secs;
        return $wall - ($this->zone())->getOffset(new \DateTimeImmutable('@' . ($wall - 86400)));
    }

    /** @return list<int> every instant the wall clock names on a day in the zone, by its transitions (none in a gap, two where it repeats) */
    private function wallInstants(int $dn, int $secs): array
    {
        $zone = $this->zone();
        $wall = $dn * 86400 + $secs;
        $offsets = [$zone->getOffset(new \DateTimeImmutable('@' . $wall)) => true];
        foreach ($zone->getTransitions($wall - 2 * 86400, $wall + 2 * 86400) ?: [] as $t) {
            $offsets[(int) $t['offset']] = true;
        }
        $out = [];
        foreach (array_keys($offsets) as $off) {
            if ($zone->getOffset(new \DateTimeImmutable('@' . ($wall - $off))) === $off) {
                $out[] = $wall - $off;
            }
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /** How far a gap moves a clock that falls in it: the offsets after and before it differ by that much. */
    private function gapSize(int $dn, int $secs): int
    {
        $zone = $this->zone();
        $wall = $dn * 86400 + $secs;
        return $zone->getOffset(new \DateTimeImmutable('@' . ($wall + 86400))) - $zone->getOffset(new \DateTimeImmutable('@' . ($wall - 86400)));
    }

    private function localDn(int $ts): int
    {
        $l = (new \DateTimeImmutable('@' . $ts))->setTimezone($this->zone());
        return Days::dn((int) $l->format('Y'), (int) $l->format('n'), (int) $l->format('j'));
    }

    private function zone(): \DateTimeZone
    {
        return $this->zoneObj ??= new \DateTimeZone($this->tz);
    }

    private function localSecs(int $ts): int
    {
        $l = (new \DateTimeImmutable('@' . $ts))->setTimezone($this->zone());
        return (int) $l->format('G') * 3600 + (int) $l->format('i') * 60 + (int) $l->format('s');
    }
}
