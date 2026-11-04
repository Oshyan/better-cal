<?php
// The harness's own reading of a rule (series-model.md, Vocabulary and
// Imported Rules, by Capability): its family, its capability and its
// members, and an evaluator of the days a pattern names in each period.
// Written from RFC 5545 and the design, with no call into Domain/Series;
// the expander (Truth) checks it on every series the harness makes.
declare(strict_types=1);

namespace BetterCal\EditHarness;

/**
 * A member's form in a month: ['d', n] the nth day (from the end when n is
 * negative, nothing in a month without it), ['c', n] the clamped nth
 * (BYMONTHDAY=28..n;BYSETPOS=-1: n, or the month's last day when shorter),
 * ['nth', n, wd] the nth weekday (from the end when negative), ['pos', n]
 * the nth day of the weekday set (from the end when negative).
 */
final class Shape
{
    /** table: a row of the drag table; onehour: a table row naming one BYHOUR/BYMINUTE; several, weekno, dayset, unreadable: the capability rows. */
    public string $cap = 'table';
    public string $freq = '';
    public int $interval = 1;
    public int $wkst = 0;
    public bool $wkstWritten = false;
    /** Weekly and DAILY;BYDAY: the listed weekdays (a bare weekly rule: the start's). */
    public array $wds = [];
    public bool $dailyList = false;
    /** Monthly and yearly: the member forms. */
    public array $forms = [];
    /** Yearly: the months. */
    public array $months = [];
    /** Set-position members: the weekday set. */
    public array $set = [];
    public ?int $count = null;
    public ?string $until = null;
    public array $parts = [];
    public bool $bare = false;
    /** A rule naming its hour, read in its zone: the zone and the clock it names (inZone). */
    private ?string $gapTz = null;
    private int $gapSecs = 0;

    /**
     * The pattern read in a series' zone at its clock. A rule naming its hour
     * (BYHOUR) is stepped hour by hour by the expander and has no occurrence
     * on a day whose clock falls in a spring-forward gap (series-model.md,
     * Zones, DST and All-Day Days), so such a day is no day of it. Any other
     * rule keeps a gap day, at the clock plus the gap.
     */
    public function inZone(string $tz, int $clockSecs, bool $allDay, bool $clockFromRule = true): self
    {
        $this->gapTz = null;
        if (!$allDay && isset($this->parts['BYHOUR']) && !str_contains($this->parts['BYHOUR'], ',')) {
            $this->gapTz = $tz;
            // The clock the rule names, or $clockSecs for a rule about to be
            // written at that clock (a drag writes the new hour and minute).
            $this->gapSecs = !$clockFromRule ? $clockSecs : (int) $this->parts['BYHOUR'] * 3600
                + (isset($this->parts['BYMINUTE']) ? (int) $this->parts['BYMINUTE'] : intdiv($clockSecs % 3600, 60)) * 60
                + (isset($this->parts['BYSECOND']) ? (int) $this->parts['BYSECOND'] : $clockSecs % 60);
        }
        return $this;
    }

    /** Whether the expander leaves $dn, a day of the pattern, out for its clock (inZone): no occurrence there. */
    public function skipsForGap(int $dn): bool
    {
        return $this->gapTz !== null && Clock::inGap($dn, $this->gapSecs, $this->gapTz);
    }

    /** @return array<string,string> */
    public static function parts(string $rrule): array
    {
        $out = [];
        foreach (explode(';', strtoupper(trim($rrule))) as $p) {
            if ($p === '' || !str_contains($p, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $p, 2);
            $out[$k] = $v;
        }
        return $out;
    }

    /** The rule without COUNT and UNTIL, its parts in order. */
    public static function pattern(string $rrule): string
    {
        $p = self::parts($rrule);
        unset($p['COUNT'], $p['UNTIL']);
        return implode(';', array_map(static fn($k, $v) => "$k=$v", array_keys($p), $p));
    }

    public static function parse(string $rrule, int $startDn): self
    {
        $s = new self();
        $p = self::parts($rrule);
        $s->parts = $p;
        $s->freq = $p['FREQ'] ?? '';
        $s->interval = max(1, (int) ($p['INTERVAL'] ?? 1));
        $s->count = isset($p['COUNT']) ? (int) $p['COUNT'] : null;
        $s->until = $p['UNTIL'] ?? null;
        if (isset($p['WKST'])) {
            $s->wkst = max(0, Days::wdOf($p['WKST']));
            $s->wkstWritten = true;
        }
        foreach (array_keys($p) as $k) {
            if ($k === 'RSCALE' || str_starts_with($k, 'X-')) {
                $s->cap = 'unreadable';
                return $s;
            }
        }
        $known = ['FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'WKST', 'BYDAY', 'BYMONTHDAY', 'BYMONTH', 'BYSETPOS', 'BYHOUR', 'BYMINUTE', 'BYSECOND', 'BYWEEKNO', 'BYYEARDAY'];
        foreach (array_keys($p) as $k) {
            if (!in_array($k, $known, true)) {
                $s->cap = 'unreadable';
                return $s;
            }
        }
        if (in_array($s->freq, ['HOURLY', 'MINUTELY', 'SECONDLY'], true)) {
            $s->cap = 'several';
            return $s;
        }
        foreach (['BYHOUR', 'BYMINUTE', 'BYSECOND'] as $k) {
            if (isset($p[$k]) && str_contains($p[$k], ',')) {
                $s->cap = 'several';
                return $s;
            }
        }
        if (isset($p['BYWEEKNO'])) {
            $s->cap = 'weekno';
            return $s;
        }
        if (isset($p['BYYEARDAY'])) {
            $s->cap = 'dayset';
            return $s;
        }
        $hour = isset($p['BYHOUR']) || isset($p['BYMINUTE']) || isset($p['BYSECOND']);
        [$sy, $sm, $sd] = Days::ymd($startDn);
        $days = isset($p['BYDAY']) ? explode(',', $p['BYDAY']) : [];
        $nth = [];
        $plain = [];
        foreach ($days as $d) {
            if (preg_match('/^([+-]?\d+)?(MO|TU|WE|TH|FR|SA|SU)$/', $d, $m) !== 1) {
                $s->cap = 'unreadable';
                return $s;
            }
            if (($m[1] ?? '') !== '') {
                $nth[] = ['nth', (int) $m[1], Days::wdOf($m[2])];
            } else {
                $plain[] = Days::wdOf($m[2]);
            }
        }
        $mdays = isset($p['BYMONTHDAY']) ? array_map('intval', explode(',', $p['BYMONTHDAY'])) : [];
        $setpos = isset($p['BYSETPOS']) ? array_map('intval', explode(',', $p['BYSETPOS'])) : [];
        $dayset = static function () use ($s): self {
            $s->cap = 'dayset';
            return $s;
        };
        switch ($s->freq) {
            case 'DAILY':
                if ($mdays !== [] || isset($p['BYMONTH']) || $setpos !== [] || $nth !== []) {
                    return $dayset();
                }
                if ($plain !== []) {
                    if ($s->interval > 1) {
                        return $dayset();
                    }
                    $s->dailyList = true;
                    $s->wds = self::sortedUnique($plain);
                }
                break;
            case 'WEEKLY':
                if ($mdays !== [] || isset($p['BYMONTH']) || $setpos !== [] || $nth !== []) {
                    return $dayset();
                }
                $s->wds = $plain === [] ? [Days::wd($startDn)] : self::sortedUnique($plain);
                $s->bare = $plain === [];
                break;
            case 'MONTHLY':
                if (isset($p['BYMONTH'])) {
                    return $dayset();
                }
                $forms = self::monthForms($mdays, $plain, $nth, $setpos, $sd, $s);
                if ($forms === null) {
                    return $dayset();
                }
                $s->forms = $forms;
                $s->bare = $mdays === [] && $days === [];
                break;
            case 'YEARLY':
                $months = isset($p['BYMONTH']) ? array_map('intval', explode(',', $p['BYMONTH'])) : [$sm];
                if ($days === [] && $mdays === [] && $setpos === []) {
                    $forms = [['d', $sd]];
                } else {
                    if (!isset($p['BYMONTH'])) {
                        return $dayset();
                    }
                    $forms = self::monthForms($mdays, $plain, $nth, $setpos, $sd, $s);
                    if ($forms === null || count($forms) !== 1) {
                        return $dayset();
                    }
                }
                sort($months);
                $s->months = $months;
                $s->forms = $forms;
                $s->bare = !isset($p['BYMONTH']) && $days === [] && $mdays === [];
                break;
            default:
                $s->cap = 'unreadable';
                return $s;
        }
        if ($s->overlaps()) {
            return $dayset();
        }
        if ($hour) {
            $s->cap = 'onehour';
        }
        return $s;
    }

    /**
     * Whether two patterns of the drag table are the same pattern by the
     * design's comparison (Mapping, Defined, step 1): every list a set, a
     * bare rule its start's member (a weekly one its weekday, a monthly one
     * its day, a yearly one its month and day), WKST only where it counts.
     */
    public static function samePattern(self $a, self $b): bool
    {
        if ($a->cap !== 'table' || $b->cap !== 'table') {
            return false;
        }
        $canon = static function (array $l): array {
            $l = array_map('json_encode', $l);
            sort($l);
            return $l;
        };
        $wkst = static fn(self $s): ?int => $s->freq === 'WEEKLY' && $s->interval > 1 && count($s->wds) > 1 ? $s->wkst : null;
        return $a->freq === $b->freq && $a->interval === $b->interval && $a->dailyList === $b->dailyList
            && $canon($a->wds) === $canon($b->wds) && $canon($a->forms) === $canon($b->forms)
            && $canon($a->months) === $canon($b->months) && $canon($a->set) === $canon($b->set) && $wkst($a) === $wkst($b);
    }

    /** @return list<array>|null */
    private static function monthForms(array $mdays, array $plain, array $nth, array $setpos, int $startDay, self $s): ?array
    {
        if ($mdays !== [] && ($plain !== [] || $nth !== [])) {
            return null; // BYMONTHDAY with BYDAY
        }
        if ($mdays !== []) {
            if ($setpos !== []) {
                // The clamped month end: BYMONTHDAY=28,...,n;BYSETPOS=-1.
                $sorted = $mdays;
                sort($sorted);
                if ($setpos === [-1] && $sorted[0] === 28 && count($sorted) >= 2 && $sorted === range(28, end($sorted)) && end($sorted) <= 31) {
                    return [['c', (int) end($sorted)]];
                }
                return null;
            }
            $out = [];
            foreach ($mdays as $n) {
                if ($n === 0 || abs($n) > 31) {
                    return null;
                }
                $out[] = ['d', $n];
            }
            return $out;
        }
        if ($nth !== []) {
            if ($plain !== [] || $setpos !== []) {
                return null;
            }
            foreach ($nth as $f) {
                if ($f[1] === 0 || abs($f[1]) > 5) {
                    return null;
                }
            }
            return $nth;
        }
        if ($plain !== []) {
            if ($setpos === []) {
                return null; // every Monday of the month
            }
            $set = self::sortedUnique($plain);
            if (count($set) === 1 && count($setpos) === 1) {
                return [['nth', $setpos[0], $set[0]]];
            }
            $s->set = $set;
            $out = [];
            foreach ($setpos as $n) {
                if ($n === 0) {
                    return null;
                }
                $out[] = ['pos', $n];
            }
            // Positions in one direction only (mixed directions aren't a row of the table).
            $signs = array_unique(array_map(static fn(array $f): int => $f[1] > 0 ? 1 : -1, $out));
            return count($signs) === 1 ? $out : null;
        }
        if ($setpos !== []) {
            return null;
        }
        return [['d', $startDay]];
    }

    private static function sortedUnique(array $wds): array
    {
        $wds = array_values(array_unique($wds));
        sort($wds);
        return $wds;
    }

    /** Two nth members that can name one day in some month (4MO and -1MO): not a row of the table. */
    private function overlaps(): bool
    {
        $nth = array_values(array_filter($this->forms, static fn(array $f): bool => $f[0] === 'nth'));
        for ($i = 0; $i < count($nth); $i++) {
            for ($j = $i + 1; $j < count($nth); $j++) {
                if ($nth[$i][2] === $nth[$j][2] && ($nth[$i][1] > 0) !== ($nth[$j][1] > 0)) {
                    $sum = abs($nth[$i][1]) + abs($nth[$j][1]);
                    if ($sum === 5 || $sum === 6) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public function supported(): bool
    {
        return $this->cap === 'table' || $this->cap === 'onehour';
    }

    /** day, week, month or year. */
    public function unit(): string
    {
        return match ($this->freq) {
            'DAILY' => 'day', 'WEEKLY' => 'week', 'MONTHLY' => 'month', default => 'year',
        };
    }

    public function periodOf(int $dn): int
    {
        return match ($this->freq) {
            'DAILY' => $dn,
            'WEEKLY' => intdiv($dn - ($this->wkst - 3) - (($dn - ($this->wkst - 3)) % 7 + 7) % 7, 7),
            'MONTHLY' => Days::miOf($dn),
            default => Days::ymd($dn)[0],
        };
    }

    /** Whether period $p is counted by a series whose start is in period $p0. */
    public function counted(int $p, int $p0): bool
    {
        return (($p - $p0) % $this->interval + $this->interval) % $this->interval === 0;
    }

    /** A form's day in a month, or null. */
    public function evalForm(array $f, int $y, int $m): ?int
    {
        $len = Days::monthLen($y, $m);
        switch ($f[0]) {
            case 'd':
                $d = $f[1] > 0 ? $f[1] : $len + $f[1] + 1;
                return $d >= 1 && $d <= $len ? Days::dn($y, $m, $d) : null;
            case 'c':
                return Days::dn($y, $m, min($f[1], $len));
            case 'nth':
                $first = Days::dn($y, $m, 1);
                if ($f[1] > 0) {
                    $d = 1 + (($f[2] - Days::wd($first)) % 7 + 7) % 7 + 7 * ($f[1] - 1);
                } else {
                    $last = Days::dn($y, $m, $len);
                    $d = $len - ((Days::wd($last) - $f[2]) % 7 + 7) % 7 - 7 * (-$f[1] - 1);
                }
                return $d >= 1 && $d <= $len ? Days::dn($y, $m, $d) : null;
            case 'pos':
                $hits = $this->setDays($y, $m);
                $i = $f[1] > 0 ? $f[1] - 1 : count($hits) + $f[1];
                return $hits[$i] ?? null;
        }
        return null;
    }

    /** The days of the weekday set in a month. */
    public function setDays(int $y, int $m): array
    {
        $first = Days::dn($y, $m, 1);
        $out = [];
        for ($i = 0; $i < Days::monthLen($y, $m); $i++) {
            if (in_array(Days::wd($first + $i), $this->set, true)) {
                $out[] = $first + $i;
            }
        }
        return $out;
    }

    public function memberCount(): int
    {
        return match ($this->freq) {
            'DAILY' => $this->dailyList ? count($this->wds) : 1,
            'WEEKLY' => count($this->wds),
            'MONTHLY' => count($this->forms),
            default => count($this->months) * count($this->forms),
        };
    }

    /** A member's day in period $p (no phase), or null. */
    public function memberDay(int $member, int $p): ?int
    {
        switch ($this->freq) {
            case 'DAILY':
                if ($this->dailyList) {
                    return Days::wd($p) === $this->wds[$member] ? $p : null;
                }
                return $p;
            case 'WEEKLY':
                $first = $p * 7 + ($this->wkst - 3);
                return $first + (($this->wds[$member] - $this->wkst) % 7 + 7) % 7;
            case 'MONTHLY':
                [$y, $m] = Days::fromMi($p);
                return $this->evalForm($this->forms[$member], $y, $m);
            default:
                $mi = intdiv($member, count($this->forms));
                return $this->evalForm($this->forms[$member % count($this->forms)], $p, $this->months[$mi]);
        }
    }

    /**
     * The days the pattern names in period $p, each with its member, in
     * order, one per day (two members naming one day give it once, as the
     * expander does).
     *
     * @return list<array{0:int,1:int}>
     */
    public function periodDays(int $p): array
    {
        $out = [];
        for ($i = 0, $n = $this->memberCount(); $i < $n; $i++) {
            $d = $this->memberDay($i, $p);
            if ($d !== null && !isset($out[$d])) {
                $out[$d] = [$d, $i];
            }
        }
        ksort($out);
        return array_values($out);
    }

    /** The member producing $dn, or null when the pattern doesn't name it. */
    public function memberOf(int $dn): ?int
    {
        foreach ($this->periodDays($this->periodOf($dn)) as [$d, $i]) {
            if ($d === $dn) {
                return $i;
            }
        }
        return null;
    }

    /**
     * The series' days from $fromDn to $toDn (inclusive), counting the
     * periods of a series whose start is $startDn (its phase), the days
     * before the start included when $fromDn is earlier. Its occurrences,
     * without the days its clock's gap leaves out (inZone); with $pattern,
     * every day the pattern names, the members' days for images.
     *
     * @return list<int>
     */
    public function days(int $startDn, int $fromDn, int $toDn, int $max = PHP_INT_MAX, bool $pattern = false): array
    {
        $p0 = $this->periodOf($startDn);
        $out = [];
        for ($p = $this->periodOf($fromDn), $last = $this->periodOf($toDn); $p <= $last; $p++) {
            if (!$this->counted($p, $p0)) {
                continue;
            }
            foreach ($this->periodDays($p) as [$d]) {
                if ($d >= $fromDn && $d <= $toDn && ($pattern || !$this->skipsForGap($d))) {
                    $out[] = $d;
                    if (count($out) >= $max) {
                        return $out;
                    }
                }
            }
        }
        return $out;
    }

    /** Whether $dn is an occurrence's day of the pattern counted from a series starting on $startDn. */
    public function has(int $dn, int $startDn): bool
    {
        return $this->counted($this->periodOf($dn), $this->periodOf($startDn)) && $this->memberOf($dn) !== null && !$this->skipsForGap($dn);
    }

    /** Days per period at most, for sizing a walk. */
    public function periodSpan(): int
    {
        return match ($this->freq) {
            'DAILY' => 1, 'WEEKLY' => 7, 'MONTHLY' => 31, default => 366,
        };
    }
}
