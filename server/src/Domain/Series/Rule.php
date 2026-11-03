<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

/**
 * A repeat rule read into its members (docs/design/series-model.md,
 * Vocabulary): the listed weekdays of a weekly rule, the days, nth weekdays
 * or set positions of a monthly one, the months of a yearly one. A rule in a
 * row of the drag table is one of the kinds daily, weekly, monthly or
 * yearly and is worked out here by arithmetic; anything else is 'other'
 * (expanded by sabre, its capability decided by its parts) or 'unreadable'.
 *
 * Monthly and yearly members are arrays: ['t' => 'day', 'n' => 15] (a
 * negative n counts from the end), ['t' => 'clamp', 'n' => 30] (the 30th,
 * or the last day in shorter months), ['t' => 'nth', 'n' => 2, 'wd' => 1]
 * (the second Tuesday) and ['t' => 'pos', 'n' => 3] (the third day of
 * $set in the month).
 */
final class Rule
{
    /** @var array<string,string> the parts as read, bound included */
    public array $parts = [];
    public string $freq = '';
    public int $interval = 1;
    /** 'daily', 'weekly', 'monthly', 'yearly', 'other' or 'unreadable' */
    public string $kind = 'other';
    /** @var list<int|array<string,int|string>> */
    public array $members = [];
    /** @var list<int> a yearly rule's months, in order */
    public array $months = [];
    /** @var list<int> the weekdays a set-position member counts */
    public array $set = [];
    public ?int $wkst = null;
    public ?int $hour = null;
    public ?int $minute = null;
    public ?int $count = null;
    public ?string $until = null;
    /** The day comes from the start (no BYDAY or BYMONTHDAY written). */
    public bool $bare = false;
    /** A bare yearly rule: neither its month nor its day written. */
    public bool $bareMonth = false;
    /** A single nth weekday written as BYDAY=TU;BYSETPOS=2. */
    public bool $setposForm = false;
    /** Written from members (true) or kept as read. */
    public bool $dirty = false;
    /** Why days can't move, when they can't: a refusal code (Capabilities). */
    public ?string $fixedDays = null;
    /** Several times a day (BYHOUR or BYMINUTE lists, HOURLY and finer). */
    public bool $several = false;
    /**
     * Written FREQ=DAILY with BYDAY: read as the weekly list, its text kept
     * as written (the expander reads it as written, whatever that does
     * across a skipped hour) until a reading rewrites the members, which
     * writes it as WEEKLY.
     */
    public bool $dailyList = false;

    public static function parse(string $rrule, int $startDn): self
    {
        $r = new self();
        $parts = [];
        foreach (explode(';', strtoupper(trim($rrule))) as $piece) {
            if ($piece === '') {
                continue;
            }
            $kv = explode('=', $piece, 2);
            if (count($kv) !== 2 || array_key_exists(trim($kv[0]), $parts)) {
                $r->kind = 'unreadable';
                return $r;
            }
            $parts[trim($kv[0])] = trim($kv[1]);
        }
        $r->parts = $parts;
        $r->freq = $parts['FREQ'] ?? '';
        $known = ['FREQ', 'UNTIL', 'COUNT', 'INTERVAL', 'BYSECOND', 'BYMINUTE', 'BYHOUR', 'BYDAY', 'BYMONTHDAY', 'BYYEARDAY', 'BYWEEKNO', 'BYMONTH', 'BYSETPOS', 'WKST'];
        foreach (array_keys($parts) as $k) {
            if (!in_array($k, $known, true)) {
                $r->kind = 'unreadable';
                return $r;
            }
        }
        if (!in_array($r->freq, ['SECONDLY', 'MINUTELY', 'HOURLY', 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            $r->kind = 'unreadable';
            return $r;
        }
        $r->interval = isset($parts['INTERVAL']) && ctype_digit($parts['INTERVAL']) ? max(1, (int) $parts['INTERVAL']) : 1;
        if (isset($parts['COUNT'])) {
            $r->count = ctype_digit($parts['COUNT']) ? (int) $parts['COUNT'] : null;
        }
        if (isset($parts['UNTIL'])) {
            $r->until = $parts['UNTIL'];
        }
        if (isset($parts['WKST'])) {
            $r->wkst = Cal::wdOf($parts['WKST']);
        }
        foreach (['BYHOUR' => 'hour', 'BYMINUTE' => 'minute'] as $key => $prop) {
            if (isset($parts[$key])) {
                if (preg_match('/^\d{1,2}$/', $parts[$key]) === 1) {
                    $r->$prop = (int) $parts[$key];
                } else {
                    $r->several = true;
                }
            }
        }
        if (in_array($r->freq, ['SECONDLY', 'MINUTELY', 'HOURLY'], true) || isset($parts['BYSECOND'])) {
            $r->several = true;
        }
        if ($r->several) {
            $r->kind = 'other';
            $r->fixedDays = 'several';
            return $r;
        }
        if (isset($parts['BYWEEKNO'])) {
            $r->kind = 'other';
            $r->fixedDays = 'weekno';
            return $r;
        }
        if (isset($parts['BYYEARDAY'])) {
            return $r->other('dayset');
        }
        $byday = isset($parts['BYDAY']) ? explode(',', $parts['BYDAY']) : [];
        $plain = [];
        $nth = [];
        foreach ($byday as $d) {
            if (preg_match('/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', $d, $m) !== 1) {
                return $r->other('dayset');
            }
            if (($m[1] ?? '') === '') {
                $plain[] = (int) Cal::wdOf($m[2]);
            } else {
                $nth[] = ['t' => 'nth', 'n' => (int) $m[1], 'wd' => (int) Cal::wdOf($m[2])];
            }
        }
        $bmd = isset($parts['BYMONTHDAY']) ? array_map('intval', explode(',', $parts['BYMONTHDAY'])) : [];
        $pos = isset($parts['BYSETPOS']) ? array_map('intval', explode(',', $parts['BYSETPOS'])) : [];
        $months = isset($parts['BYMONTH']) ? array_map('intval', explode(',', $parts['BYMONTH'])) : [];
        [, $sm, $sd] = Cal::ymd($startDn);

        if ($r->freq === 'DAILY') {
            if ($bmd !== [] || $pos !== [] || $months !== [] || $nth !== []) {
                return $r->other('dayset');
            }
            if ($plain !== []) {
                if ($r->interval !== 1) {
                    return $r->other('dayset');
                }
                // DAILY with BYDAY and INTERVAL 1 reads as the weekly list. It
                // stays as written (and expanded as sabre's daily iterator
                // does) until a reading changes its days.
                $r->freq = 'WEEKLY';
                $r->kind = 'weekly';
                $r->members = self::sortedDays($plain, 0);
                $r->dailyList = true;
                return $r;
            }
            $r->kind = 'daily';
            return $r;
        }
        if ($r->freq === 'WEEKLY') {
            if ($bmd !== [] || $pos !== [] || $months !== [] || $nth !== []) {
                return $r->other('dayset');
            }
            $r->kind = 'weekly';
            if ($plain === []) {
                $r->bare = true;
                $r->members = [Cal::wd($startDn)];
            } else {
                $r->members = self::sortedDays(array_values(array_unique($plain)), $r->wkst ?? 0);
            }
            return $r;
        }
        if ($r->freq === 'MONTHLY') {
            if ($months !== []) {
                return $r->other('dayset');
            }
            $member = self::monthMembers($bmd, $plain, $nth, $pos, $r);
            if ($member === null) {
                return $r->other($r->fixedDays ?? 'dayset');
            }
            if ($member === []) {
                $r->bare = true;
                $member = [['t' => 'day', 'n' => $sd]];
            }
            $r->kind = 'monthly';
            $r->members = $member;
            return $r;
        }
        // YEARLY
        if ($months !== [] && (count($months) !== count(array_unique($months)))) {
            return $r->other('dayset');
        }
        $member = self::monthMembers($bmd, $plain, $nth, $pos, $r);
        if ($member === null || count($member) > 1 || ($months === [] && $member !== [])) {
            return $r->other($r->fixedDays ?? 'dayset');
        }
        if ($member !== [] && $member[0]['t'] === 'pos' && count($r->set) !== 1) {
            return $r->other('dayset');
        }
        $r->kind = 'yearly';
        if ($months === []) {
            $r->bareMonth = true;
            $months = [$sm];
        }
        sort($months);
        $r->months = $months;
        if ($member === []) {
            $r->bare = true;
            $member = [['t' => 'day', 'n' => $sd]];
        }
        $r->members = $member;
        return $r;
    }

    private function other(string $why): self
    {
        $this->kind = 'other';
        $this->fixedDays = $why;
        return $this;
    }

    /**
     * The members of a monthly (or yearly, within its month) rule: [] for a
     * bare one, null for a shape outside the drag table.
     *
     * @return null|list<array<string,int|string>>
     */
    private static function monthMembers(array $bmd, array $plain, array $nth, array $pos, self $r): ?array
    {
        if ($bmd !== [] && ($plain !== [] || $nth !== [])) {
            return null; // "Friday the 13th"
        }
        if ($bmd !== []) {
            sort($bmd);
            if ($pos !== []) {
                // The clamped forms: 28,29;BYSETPOS=-1 and 28,29,30;BYSETPOS=-1.
                if ($pos === [-1] && ($bmd === [28, 29] || $bmd === [28, 29, 30] || $bmd === [28, 29, 30, 31])) {
                    return [['t' => 'clamp', 'n' => max($bmd)]];
                }
                return null;
            }
            $seen = [];
            $out = [];
            foreach ($bmd as $n) {
                if ($n === 0 || isset($seen[$n])) {
                    return null;
                }
                $seen[$n] = true;
                $out[] = ['t' => 'day', 'n' => $n];
            }
            // A day from the start and one from the end are one day in a
            // month of some length (the 31st and the last): another day set.
            foreach ($bmd as $p) {
                foreach ($bmd as $q) {
                    if ($p > 0 && $q < 0 && $p - $q - 1 >= 28 && $p - $q - 1 <= 31) {
                        $r->fixedDays = 'overlap';
                        return null;
                    }
                }
            }
            usort($out, static fn(array $a, array $b): int => ($a['n'] < 0 ? 100 + $a['n'] : $a['n']) <=> ($b['n'] < 0 ? 100 + $b['n'] : $b['n']));
            return $out;
        }
        if ($nth !== []) {
            if ($plain !== [] || $pos !== []) {
                return null;
            }
            foreach ($nth as $m) {
                if ($m['n'] === 0 || abs($m['n']) > 5) {
                    return null;
                }
            }
            if (count($nth) > 1) {
                foreach ($nth as $m) {
                    if (abs($m['n']) > 4) {
                        return null;
                    }
                }
                if (self::nthOverlap($nth)) {
                    $r->fixedDays = 'overlap';
                    return null;
                }
            }
            return $nth;
        }
        if ($plain !== []) {
            if ($pos === []) {
                return null; // every Monday of the month: another day set
            }
            $plain = array_values(array_unique($plain));
            sort($plain);
            if (count($plain) === 1 && count($pos) === 1 && $pos[0] !== 0 && abs($pos[0]) <= 5) {
                $r->setposForm = true;
                return [['t' => 'nth', 'n' => $pos[0], 'wd' => $plain[0]]];
            }
            foreach ($pos as $p) {
                if ($p === 0 || ($p > 0) !== ($pos[0] > 0) || abs($p) > 4 * count($plain)) {
                    return null;
                }
            }
            $r->set = $plain;
            $pos = array_values(array_unique($pos));
            sort($pos);
            return array_map(static fn(int $p): array => ['t' => 'pos', 'n' => $p], $pos);
        }
        if ($pos !== []) {
            return null;
        }
        return [];
    }

    /** Two nth weekdays that name one day in some month (4MO and -1MO). */
    public static function nthOverlap(array $nth): bool
    {
        $n = count($nth);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $nth[$i];
                $b = $nth[$j];
                if ($a['wd'] !== $b['wd']) {
                    continue;
                }
                if ($a['n'] === $b['n']) {
                    return true;
                }
                if (($a['n'] > 0) !== ($b['n'] > 0)) {
                    // In a month with 4 or 5 of that weekday, the kth from the
                    // start is the jth from the end when k + j = count + 1.
                    $p = $a['n'] > 0 ? $a['n'] : $b['n'];
                    $q = $a['n'] > 0 ? -$b['n'] : -$a['n'];
                    if ($p + $q === 5 || $p + $q === 6) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Two members that give one day in some period, or null: a listed
     * weekday twice, or monthly members that coincide in a month of some
     * length and first weekday (the 28th and the last day in February, the
     * fourth and the last Monday in a month with four). The same constraint
     * a parsed rule meets (monthMembers), checked over every kind of month:
     * whether two members coincide depends only on the month's length and
     * the weekday it starts on, and 28 years hold every such pair.
     *
     * @return null|array{0:int|array<string,int|string>,1:int|array<string,int|string>}
     */
    public function collision(): ?array
    {
        if (count($this->members) < 2) {
            return null;
        }
        if ($this->kind === 'weekly') {
            $seen = [];
            foreach ($this->members as $wd) {
                if (isset($seen[$wd])) {
                    return [$wd, $wd];
                }
                $seen[$wd] = true;
            }
            return null;
        }
        if (!in_array($this->kind, ['monthly', 'yearly'], true)) {
            return null;
        }
        $kinds = [];
        for ($y = 2000; $y < 2028; $y++) {
            for ($m = 1; $m <= 12; $m++) {
                $sig = Cal::monthLen($y, $m) . ':' . Cal::wd(Cal::dn($y, $m, 1));
                if (isset($kinds[$sig])) {
                    continue;
                }
                $kinds[$sig] = true;
                $days = [];
                foreach ($this->members as $i => $mem) {
                    $d = $this->evalMember($mem, $y, $m);
                    if ($d === null) {
                        continue;
                    }
                    if (isset($days[$d])) {
                        return [$this->members[$days[$d]], $mem];
                    }
                    $days[$d] = $i;
                }
            }
        }
        return null;
    }

    /** @param list<int> $days @return list<int> sorted from the week start */
    public static function sortedDays(array $days, int $wkst): array
    {
        $days = array_values(array_unique($days));
        usort($days, static fn(int $a, int $b): int => (($a - $wkst + 7) % 7) <=> (($b - $wkst + 7) % 7));
        return $days;
    }

    public function supported(): bool
    {
        return in_array($this->kind, ['daily', 'weekly', 'monthly', 'yearly'], true);
    }

    // ---- periods -----------------------------------------------------

    /** The week start a weekly rule counts from (WKST, Monday by default). */
    public function weekStart(): int
    {
        return $this->wkst ?? 0;
    }

    /** The period holding a day: a day number, a week's first day, a month index or a year. */
    public function periodOf(int $dn): int
    {
        return match ($this->kind) {
            'daily' => $dn,
            'weekly' => $dn - ((Cal::wd($dn) - $this->weekStart() + 7) % 7),
            'monthly' => Cal::miOf($dn),
            'yearly' => Cal::ymd($dn)[0],
            default => $dn,
        };
    }

    /** The first day of a period. */
    public function periodFirst(int $p): int
    {
        [$y, $m] = Cal::fromMi($p);
        return match ($this->kind) {
            'monthly' => Cal::dn($y, $m, 1),
            'yearly' => Cal::dn($p, 1, 1),
            default => $p,
        };
    }

    /** The step from one period to the next, in period units. */
    public function unit(): int
    {
        return $this->kind === 'weekly' ? 7 : 1;
    }

    /** The period $n periods on from $p. */
    public function periodPlus(int $p, int $n): int
    {
        return $p + $n * $this->unit();
    }

    /** Periods from $p0 to $p. */
    public function periodsBetween(int $p0, int $p): int
    {
        return intdiv($p - $p0, $this->unit());
    }

    public function counted(int $p, int $p0): bool
    {
        $n = $this->periodsBetween($p0, $p);
        return (($n % $this->interval) + $this->interval) % $this->interval === 0;
    }

    /**
     * The days the pattern gives in one period, in order, each with the
     * member that produced it (two members on one day give one occurrence,
     * the first member's).
     *
     * @return list<array{0:int,1:int}> [day number, member index]
     */
    public function periodDays(int $p): array
    {
        $out = [];
        if ($this->kind === 'daily') {
            return [[$p, 0]];
        }
        if ($this->kind === 'weekly') {
            foreach ($this->members as $i => $wd) {
                $out[] = [$p + (($wd - $this->weekStart() + 7) % 7), $i];
            }
        } elseif ($this->kind === 'monthly') {
            [$y, $m] = Cal::fromMi($p);
            foreach ($this->members as $i => $mem) {
                $d = $this->evalMember($mem, $y, $m);
                if ($d !== null) {
                    $out[] = [Cal::dn($y, $m, $d), $i];
                }
            }
        } elseif ($this->kind === 'yearly') {
            foreach ($this->months as $i => $m) {
                $d = $this->evalMember($this->members[0], $p, $m);
                if ($d !== null) {
                    $out[] = [Cal::dn($p, $m, $d), $i];
                }
            }
        }
        usort($out, static fn(array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $seen = [];
        $uniq = [];
        foreach ($out as $o) {
            if (!isset($seen[$o[0]])) {
                $seen[$o[0]] = true;
                $uniq[] = $o;
            }
        }
        return $uniq;
    }

    /** The member producing $dn in its period, or null. */
    public function memberOf(int $dn): ?int
    {
        foreach ($this->periodDays($this->periodOf($dn)) as [$d, $i]) {
            if ($d === $dn) {
                return $i;
            }
        }
        return null;
    }

    /** The day of the month a monthly member gives, or null when it gives none. */
    public function evalMember(array $mem, int $y, int $m): ?int
    {
        $len = Cal::monthLen($y, $m);
        switch ($mem['t']) {
            case 'day':
                $n = (int) $mem['n'];
                $d = $n > 0 ? $n : $len + $n + 1;
                return $d >= 1 && $d <= $len ? $d : null;
            case 'clamp':
                return min((int) $mem['n'], $len);
            case 'nth':
                return self::nthDay((int) $mem['n'], (int) $mem['wd'], $y, $m);
            case 'pos':
                $hits = [];
                $first = Cal::dn($y, $m, 1);
                for ($d = 1; $d <= $len; $d++) {
                    if (in_array(Cal::wd($first + $d - 1), $this->set, true)) {
                        $hits[] = $d;
                    }
                }
                $p = (int) $mem['n'];
                return $p > 0 ? ($hits[$p - 1] ?? null) : ($hits[count($hits) + $p] ?? null);
        }
        return null;
    }

    public static function nthDay(int $n, int $wd, int $y, int $m): ?int
    {
        $len = Cal::monthLen($y, $m);
        if ($n > 0) {
            $first = 1 + (($wd - Cal::wd(Cal::dn($y, $m, 1))) + 7) % 7;
            $d = $first + 7 * ($n - 1);
            return $d <= $len ? $d : null;
        }
        $last = $len - ((Cal::wd(Cal::dn($y, $m, $len)) - $wd + 7) % 7);
        $d = $last - 7 * (-$n - 1);
        return $d >= 1 ? $d : null;
    }

    // ---- writing -----------------------------------------------------

    public function withBound(?int $count, ?string $until): self
    {
        $r = clone $this;
        $r->count = $count;
        $r->until = $until;
        return $r;
    }

    /** The rule without its bound, as text. */
    public function pattern(): string
    {
        return $this->withBound(null, null)->text();
    }

    /** The rule as text: as read when nothing changed, from its members when something did. */
    public function text(): string
    {
        if (!$this->dirty) {
            $parts = $this->parts;
            unset($parts['COUNT'], $parts['UNTIL']);
            if ($this->hour !== null) {
                $parts['BYHOUR'] = (string) $this->hour;
            }
            if ($this->minute !== null) {
                $parts['BYMINUTE'] = (string) $this->minute;
            }
            return self::join($parts, $this->count, $this->until);
        }
        $parts = ['FREQ' => $this->freq];
        if ($this->interval > 1) {
            $parts['INTERVAL'] = (string) $this->interval;
        }
        if ($this->kind === 'yearly' && !$this->bareMonth) {
            $parts['BYMONTH'] = implode(',', $this->months);
        }
        if ($this->kind === 'weekly' && !$this->bare) {
            $parts['BYDAY'] = implode(',', array_map([Cal::class, 'code'], self::sortedDays($this->members, $this->weekStart())));
        }
        if (in_array($this->kind, ['monthly', 'yearly'], true) && !$this->bare) {
            $first = $this->members[0];
            if ($first['t'] === 'day') {
                $parts['BYMONTHDAY'] = implode(',', array_map(static fn(array $m): string => (string) $m['n'], $this->members));
            } elseif ($first['t'] === 'clamp') {
                $parts['BYMONTHDAY'] = implode(',', range(28, (int) $first['n']));
                $parts['BYSETPOS'] = '-1';
            } elseif ($first['t'] === 'nth') {
                if ($this->setposForm && count($this->members) === 1) {
                    $parts['BYDAY'] = Cal::code((int) $first['wd']);
                    $parts['BYSETPOS'] = (string) $first['n'];
                } else {
                    $parts['BYDAY'] = implode(',', array_map(static fn(array $m): string => $m['n'] . Cal::code((int) $m['wd']), $this->members));
                }
            } elseif ($first['t'] === 'pos') {
                $parts['BYDAY'] = implode(',', array_map([Cal::class, 'code'], $this->set));
                $parts['BYSETPOS'] = implode(',', array_map(static fn(array $m): string => (string) $m['n'], $this->members));
            }
        }
        if ($this->hour !== null) {
            $parts['BYHOUR'] = (string) $this->hour;
        }
        if ($this->minute !== null) {
            $parts['BYMINUTE'] = (string) $this->minute;
        }
        if ($this->wkst !== null) {
            $parts['WKST'] = Cal::code($this->wkst);
        }
        return self::join($parts, $this->count, $this->until);
    }

    private static function join(array $parts, ?int $count, ?string $until): string
    {
        if ($count !== null) {
            $parts['COUNT'] = (string) $count;
        } elseif ($until !== null) {
            $parts['UNTIL'] = $until;
        }
        $out = [];
        foreach ($parts as $k => $v) {
            $out[] = $k . '=' . $v;
        }
        return implode(';', $out);
    }

    /**
     * The pattern compared the way the model compares a sent rule with the
     * stored one (The Function, step 1): INTERVAL=1 dropped, BYDAY sorted,
     * a day equal to the start's read as bare, WKST only where it counts.
     */
    public function comparable(): string
    {
        if (!$this->supported()) {
            $parts = $this->parts;
            unset($parts['COUNT'], $parts['UNTIL']);
            // The clock it names, as text() writes it.
            if ($this->hour !== null) {
                $parts['BYHOUR'] = (string) $this->hour;
            }
            if ($this->minute !== null) {
                $parts['BYMINUTE'] = (string) $this->minute;
            }
            if (($parts['INTERVAL'] ?? '') === '1') {
                unset($parts['INTERVAL']);
            }
            if (!isset($parts['BYWEEKNO'])) {
                unset($parts['WKST']);
            }
            ksort($parts);
            return json_encode($parts);
        }
        $key = [$this->kind, $this->interval, $this->months, $this->members, $this->set, $this->hour, $this->minute];
        if ($this->kind === 'weekly') {
            $m = $this->members;
            sort($m);
            $key[3] = $m;
            if ($this->interval > 1 && count($m) > 1) {
                $key[] = $this->weekStart();
            }
        }
        if ($this->kind === 'monthly' && count($this->members) === 1 && $this->members[0]['t'] === 'nth') {
            $key[3] = [['t' => 'nth', 'n' => $this->members[0]['n'], 'wd' => $this->members[0]['wd']]];
        }
        return json_encode($key);
    }
}
