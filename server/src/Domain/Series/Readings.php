<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

/**
 * The drag table (series-model.md, What a Drag Does, by Family): for a
 * rule, the day acted on and where it landed, the readings the dialog
 * offers, each a transformation of the rule's members. A reading is an
 * array: id, label, rule (the new pattern, no bound), image (old day and
 * member to the new day, null for a loss), textKept (the rule text as
 * written stays, what a Google series allows until 1.5.3), and disabled
 * with code and reason when it can't be applied.
 *
 * Images are found by member (Correspondence, by Member), never by sorted
 * position: each old occurrence is what its member gives in its period, and
 * its image is what that member's new form gives in the shifted period.
 */
final class Readings
{
    /**
     * @return list<array<string,mixed>>
     */
    public static function forDrag(Rule $r0, int $actedDn, int $landedDn, int $startDn): array
    {
        $delta = $landedDn - $actedDn;
        $out = match ($r0->kind) {
            'daily' => [self::reading('follow', '', $r0, static fn(int $dn): int => $dn + $delta, true)],
            'weekly' => self::weekly($r0, $actedDn, $delta, $startDn),
            'monthly' => self::monthly($r0, $actedDn, $landedDn),
            'yearly' => self::yearly($r0, $actedDn, $landedDn),
            default => [],
        };
        // A generated rule meets the constraint a parsed one does: no two
        // members on one day in any period (Correspondence, by Member). Two
        // members that coincide only sometimes (28 and -1 in February) would
        // map two old occurrences, and two edited days, onto one key.
        foreach ($out as $i => $r) {
            if (empty($r['disabled']) && ($pair = $r['rule']->collision()) !== null) {
                $out[$i] = self::disable($r, 'member_taken', self::collisionWords($pair));
            }
        }
        return $out;
    }

    /** "the 28th and the last day would be one day in some months". */
    private static function collisionWords(array $pair): string
    {
        [$a, $b] = $pair;
        if (is_int($a)) {
            return Words::day($a) . ' would be one of its days twice';
        }
        $name = static fn(array $m): string => $m['t'] === 'nth' ? Words::member($m) : 'the ' . self::memberShort($m);
        return $name($a) . ' and ' . $name($b) . ' would be one day in some months';
    }

    /** The reading that leaves the days where they are (a time, zone, all-day, bound or field edit). */
    public static function same(Rule $r0): array
    {
        return self::reading('same', '', $r0, static fn(int $dn): int => $dn, true);
    }

    private static function reading(string $id, string $label, Rule $rule, callable $image, bool $textKept): array
    {
        return ['id' => $id, 'label' => $label, 'rule' => $rule, 'image' => $image, 'textKept' => $textKept];
    }

    private static function disable(array $reading, string $code, string $reason): array
    {
        return $reading + ['disabled' => true, 'code' => $code, 'reason' => $reason];
    }

    // ---- weekly ------------------------------------------------------

    private static function weekly(Rule $r0, int $actedDn, int $delta, int $startDn): array
    {
        $shift = static fn(int $wd): int => ((($wd + $delta) % 7) + 7) % 7;
        if ($r0->bare || count($r0->members) === 1) {
            $r = clone $r0;
            $r->members = [$shift($r0->members[0])];
            $r->dirty = $r0->dirty || ($delta % 7 !== 0 && !$r0->bare);
            return [self::reading('follow', '', $r, static fn(int $dn): int => $dn + $delta, $r0->bare || $delta % 7 === 0)];
        }
        $j = $r0->memberOf($actedDn);
        $whole = $delta % 7 === 0;
        $weeks = intdiv(abs($delta), 7);
        $dir = $delta > 0 ? 'later' : 'earlier';
        $out = [];
        // Shifting every listed day and the week start keeps each occurrence's
        // place in its week and its week's place in the interval.
        $pattern = clone $r0;
        $pattern->members = array_map($shift, $r0->members);
        // A DAILY;BYDAY list moved by whole weeks is the same rule: kept as
        // written, so its days and clock stay sabre's daily ones.
        $pattern->dirty = !($r0->dailyList && $whole && !$r0->dirty);
        if ($r0->interval > 1) {
            $pattern->wkst = $shift($r0->weekStart());
            if ($pattern->wkst === 0 && $r0->wkst === null) {
                $pattern->wkst = null;
            }
        }
        $patternDays = Words::andList(array_map([Words::class, 'day'], Rule::sortedDays($pattern->members, 0)));
        $patternReading = self::reading(
            'pattern',
            $whole ? 'Shift the whole pattern ' . Words::periods($weeks, 'week') . ' ' . $dir : 'Shift the whole weekly pattern: ' . $patternDays,
            $pattern,
            static fn(int $dn): int => $dn + $delta,
            $whole && ($r0->interval === 1 || $pattern->wkst === $r0->wkst)
        );
        if ($j !== null) {
            $wdJ = (int) $r0->members[$j];
            $newWd = $shift($wdJ);
            $members = $r0->members;
            $members[$j] = $newWd;
            $member = clone $r0;
            $member->members = $members;
            $member->dirty = true;
            $image = static fn(int $dn, ?int $mem = null): int => ($mem === null || $mem === $j) ? $dn + $delta : $dn;
            $label = $whole
                ? 'Move every ' . Words::day($wdJ) . ' ' . Words::periods($weeks, 'week') . ' ' . $dir
                : 'Move every ' . Words::day($wdJ) . ' to ' . Words::day($newWd) . ': ' . Words::andList(array_map([Words::class, 'day'], Rule::sortedDays($members, 0)));
            $reading = self::reading('member', $label, $member, $image, false);
            $others = $r0->members;
            unset($others[$j]);
            if (!$whole && in_array($newWd, $others, true)) {
                $reading = self::disable($reading, 'member_taken', Words::day($newWd) . ' is already one of its days');
            } elseif ($r0->interval > 1) {
                $wkst = self::weekStartHolding($r0, $member, $image, $startDn);
                if ($wkst === null) {
                    $reading = self::disable($reading, 'off_weeks', 'its ' . Words::day($wdJ) . 's would fall in weeks this skips');
                } else {
                    $reading['rule']->wkst = ($wkst === $r0->weekStart() ? $r0->wkst : $wkst);
                    if ($reading['rule']->wkst === 0 && $r0->wkst === null) {
                        $reading['rule']->wkst = null;
                    }
                }
            }
            $out[] = $reading;
        }
        $out[] = $patternReading;
        return $out;
    }

    /**
     * A week start under which every period's moved and unmoved days still
     * fall in one counted week (Mondays a week later in every other Monday
     * and Wednesday: WKST=TU), or null when none does.
     */
    private static function weekStartHolding(Rule $r0, Rule $member, callable $image, int $startDn): ?int
    {
        // Every period of a weekly rule holds the same members, so eight
        // periods show what all of them do: a sample by the rule's structure.
        $sample = [];
        $old = new Calc($r0->withBound(null, null), $startDn, 'UTC', true, [0, 0, 0], 86400);
        foreach ($old->occurrences($startDn + 7 * $r0->interval * 8, 40) as $dn) {
            $sample[] = $image($dn, $r0->memberOf($dn));
        }
        $start = min($sample);
        // The nearest week start to the one it has, earlier first.
        $w0 = $r0->weekStart();
        $candidates = [];
        foreach ([0, -1, 1, -2, 2, -3, 3] as $off) {
            $candidates[] = ((($w0 + $off) % 7) + 7) % 7;
        }
        foreach ($candidates as $w) {
            $try = clone $member;
            $try->wkst = $w;
            $calc = new Calc($try, $start, 'UTC', true, [0, 0, 0], 86400);
            $ok = true;
            foreach ($sample as $dn) {
                if (!$calc->patternHas($dn)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $w;
            }
        }
        return null;
    }

    // ---- monthly -----------------------------------------------------

    /**
     * The new forms of a single monthly day landing on $landedDn
     * (Naming the Landed Day), the series' own form first.
     *
     * @return list<array{0:string,1:array<string,int|string>}> [id, member]
     */
    public static function landedForms(array $old, int $landedDn): array
    {
        [$y, $m, $d] = Cal::ymd($landedDn);
        $len = Cal::monthLen($y, $m);
        $fromEnd = $len - $d + 1;
        $out = [];
        if ($old['t'] === 'nth') {
            $wd = Cal::wd($landedDn);
            if ((int) $old['n'] > 0) {
                $n = intdiv($d - 1, 7) + 1;
                $n = $n === 5 ? -1 : $n;
            } else {
                $n = -(intdiv($len - $d, 7) + 1);
                $n = $n === -5 ? 1 : $n;
            }
            return [['nth', ['t' => 'nth', 'n' => $n, 'wd' => $wd]]];
        }
        // A form that gives the landed day in its month (a drag by whole
        // months) stays as written, the one reading: "every 31st" stays every
        // 31st, the clamped 30th stays clamped (Written, not inferred). A day
        // counted from the end keeps both of its row's readings.
        $n0 = (int) $old['n'];
        $gives = match ($old['t']) {
            'day' => $n0 > 0 && $n0 === $d,
            'clamp' => min($n0, $len) === $d,
            default => false,
        };
        if ($gives) {
            return [[$old['t'] === 'clamp' ? 'clamp' : ($n0 >= 29 ? 'skip' : 'day'), $old]];
        }
        if ($old['t'] === 'day' && (int) $old['n'] < 0 && $fromEnd <= 7) {
            $out[] = ['fromEnd', ['t' => 'day', 'n' => -$fromEnd]];
        }
        if ($d <= 28) {
            $out[] = ['day', ['t' => 'day', 'n' => $d]];
            return $out;
        }
        $clamp = $d === 31 ? ['last', ['t' => 'day', 'n' => -1]] : ['clamp', ['t' => 'clamp', 'n' => $d]];
        $skip = ['skip', ['t' => 'day', 'n' => $d]];
        $last = ['last', ['t' => 'day', 'n' => -1]];
        $ownSkip = $old['t'] === 'day' && (int) $old['n'] >= 29;
        $monthEnd = $ownSkip ? [$skip, $clamp] : [$clamp, $skip];
        if ($d === $len && $d !== 31) {
            $monthEnd[] = $last;
        }
        foreach ($monthEnd as $form) {
            $dup = false;
            foreach ($out as $have) {
                if ($have[1] == $form[1]) {
                    $dup = true;
                }
            }
            if (!$dup) {
                $out[] = $form;
            }
        }
        return $out;
    }

    private static function monthly(Rule $r0, int $actedDn, int $landedDn): array
    {
        $k = Cal::miOf($landedDn) - Cal::miOf($actedDn);
        $j = $r0->memberOf($actedDn);
        $at = static function (array $mem, int $dn, int $shift) use ($r0): ?int {
            [$y, $m] = Cal::fromMi(Cal::miOf($dn) + $shift);
            $d = $r0->evalMember($mem, $y, $m);
            return $d === null ? null : Cal::dn($y, $m, $d);
        };
        // Set positions over a set of days are their own row, one position
        // or several: every position moves by the landed day's change of
        // position in the set.
        if ($r0->members[0]['t'] === 'pos') {
            return [self::setPosition($r0, $actedDn, $landedDn, $k, $at)];
        }
        if (count($r0->members) === 1 || $j === null) {
            $old = $r0->members[$j ?? 0];
            if (count($r0->members) > 1) {
                return [];
            }
            $out = [];
            foreach (self::landedForms($old, $landedDn) as [$id, $mem]) {
                $rule = clone $r0;
                $rule->members = [$mem];
                $rule->bare = $r0->bare && $mem['t'] === 'day' && (int) $mem['n'] > 0;
                $rule->dirty = $r0->dirty || $mem != $old || $rule->bare !== $r0->bare;
                $calc = static fn(int $dn): ?int => $at($mem, $dn, $k);
                $out[] = self::reading($id, 'On ' . Words::member($mem), $rule, $calc, $mem == $old && $rule->bare === $r0->bare);
            }
            return $out;
        }
        $first = $r0->members[0];
        [$y1, $m1, $d1] = Cal::ymd($landedDn);
        $whole = $k !== 0;
        $old = $r0->members[$j];
        $out = [];
        if ($first['t'] === 'day') {
            // The dragged member's new form counts as it counts (by number,
            // or from the end), and δ is its change in that counting, so
            // every member + δ gives the dragged one its landed day.
            $newJ = ['t' => 'day', 'n' => (int) $old['n'] < 0 ? $d1 - Cal::monthLen($y1, $m1) - 1 : $d1];
            $delta = (int) $newJ['n'] - (int) $old['n'];
            $sameDay = $delta === 0;
            $mm = $r0->members;
            $mm[$j] = $newJ;
            $member = self::monthRule($r0, $mm);
            $label = $sameDay && $whole
                ? 'Move every ' . self::memberShort($old) . ' ' . Words::periods(abs($k), 'month') . ($k > 0 ? ' later' : ' earlier')
                : 'Move every ' . self::memberShort($old) . ' to the ' . self::memberShort($newJ, false) . ': ' . Words::monthMembers($member);
            $reading = self::reading('member', $label, $member,
                static fn(int $dn, ?int $mem = null): ?int => $mem === $j ? $at($newJ, $dn, $k) : $at($r0->members[$mem ?? 0], $dn, 0), false);
            if (!$sameDay && self::taken($r0->members, $j, $newJ)) {
                $reading = self::disable($reading, 'member_taken', 'the ' . self::memberShort($newJ, false) . ' is already one of its days');
            } elseif ($k % $r0->interval !== 0) {
                $reading = self::disable($reading, 'interval_month', 'its ' . self::memberShort($old, false) . 's would fall in months this skips');
            }
            $out[] = $reading;
            // The whole pattern: every member moves by the same days.
            $pm = [];
            $bad = null;
            foreach ($r0->members as $mem) {
                $n = (int) $mem['n'] + $delta;
                if ((int) $mem['n'] > 0 && ($n < 1 || $n > 31)) {
                    $bad ??= $mem;
                }
                if ((int) $mem['n'] < 0 && ($n > -1 || $n < -31)) {
                    $bad ??= $mem;
                }
                $pm[] = ['t' => 'day', 'n' => $n];
            }
            $pattern = self::monthRule($r0, $pm);
            $plabel = $sameDay && $whole
                ? 'Shift the whole pattern ' . Words::periods(abs($k), 'month') . ($k > 0 ? ' later' : ' earlier')
                : 'Shift the whole monthly pattern' . ($bad === null ? ': ' . Words::monthMembers($pattern) : '');
            $preading = self::reading('pattern', $plabel, $pattern,
                static fn(int $dn, ?int $mem = null): ?int => $mem === null ? null : $at($pm[$mem], $dn, $k), $sameDay);
            if ($bad !== null) {
                $preading = self::disable($preading, 'past_month_end', 'the ' . self::memberShort($bad, false) . ' would move past the end of its month');
            }
            $out[] = $preading;
            return $out;
        }
        // An nth-weekday list.
        $wl = Cal::wd($landedDn);
        $len1 = Cal::monthLen($y1, $m1);
        if ((int) $old['n'] > 0) {
            $cl = intdiv($d1 - 1, 7) + 1;
            $cl = $cl === 5 ? -1 : $cl;
        } else {
            $cl = -(intdiv($len1 - $d1, 7) + 1);
            $cl = $cl === -5 ? 1 : $cl;
        }
        $newJ = ['t' => 'nth', 'n' => $cl, 'wd' => $wl];
        $sameDay = $newJ == $old;
        $mm = $r0->members;
        $mm[$j] = $newJ;
        $member = self::monthRule($r0, $mm);
        $label = $sameDay && $whole
            ? 'Move every ' . substr(Words::member($old), 4) . ' ' . Words::periods(abs($k), 'month') . ($k > 0 ? ' later' : ' earlier')
            : 'Move every ' . substr(Words::member($old), 4) . ' to ' . Words::member($newJ);
        $reading = self::reading('member', $label, $member,
            static fn(int $dn, ?int $mem = null): ?int => $mem === $j ? $at($newJ, $dn, $k) : $at($r0->members[$mem ?? 0], $dn, 0), false);
        if (!$sameDay && (self::taken($r0->members, $j, $newJ) || Rule::nthOverlap($mm))) {
            $reading = self::disable($reading, 'member_taken', Words::member($newJ) . ' is already one of its days, or the same day in some months');
        } elseif (abs($cl) > 4) {
            $reading = self::disable($reading, 'count_range', Words::member($newJ) . ' would have no place in some months');
        } elseif ($k % $r0->interval !== 0) {
            $reading = self::disable($reading, 'interval_month', 'its ' . substr(Words::member($old), 4) . 's would fall in months this skips');
        }
        $out[] = $reading;
        // The whole pattern: every member keeps its weekday's offset from the
        // dragged one, and its count moves as the dragged one's did.
        $dc = ((int) $old['n'] > 0) === ($cl > 0) ? $cl - (int) $old['n'] : null;
        $dw = $wl - (int) $old['wd'];
        $pm = [];
        $bad = $dc === null;
        foreach ($r0->members as $mem) {
            $n = (int) $mem['n'] + (int) $dc;
            if ($n === 0 || abs($n) > 4 || ($n > 0) !== ((int) $mem['n'] > 0)) {
                $bad = true;
            }
            $pm[] = ['t' => 'nth', 'n' => $n, 'wd' => ((((int) $mem['wd'] + $dw) % 7) + 7) % 7];
        }
        $pattern = self::monthRule($r0, $pm);
        $preading = self::reading('pattern',
            $sameDay && $whole ? 'Shift the whole pattern ' . Words::periods(abs($k), 'month') . ($k > 0 ? ' later' : ' earlier') : 'Shift the whole monthly pattern: ' . ($bad ? 'its days' : Words::monthMembers($pattern)),
            $pattern, static fn(int $dn, ?int $mem = null): ?int => $mem === null ? null : $at($pm[$mem], $dn, $k), $sameDay);
        if ($bad || Rule::nthOverlap($pm)) {
            $preading = self::disable($preading, 'count_range', 'a day of its pattern would have no place in some months');
        }
        $out[] = $preading;
        return $out;
    }

    /** "15th", "third Monday": a member's short name. */
    private static function memberShort(array $mem, bool $withThe = false): string
    {
        $w = Words::member($mem);
        $w = preg_replace('/, skipping months without one$/', '', $w) ?? $w;
        return $withThe ? $w : (str_starts_with($w, 'the ') ? substr($w, 4) : $w);
    }

    private static function taken(array $members, int $j, array $new): bool
    {
        foreach ($members as $i => $m) {
            if ($i !== $j && $m == $new) {
                return true;
            }
        }
        return false;
    }

    private static function monthRule(Rule $r0, array $members): Rule
    {
        $r = clone $r0;
        $sorted = $members;
        usort($sorted, static fn(array $a, array $b): int => ((int) $a['n'] < 0 ? 100 + (int) $a['n'] : (int) $a['n']) <=> ((int) $b['n'] < 0 ? 100 + (int) $b['n'] : (int) $b['n']) ?: ((int) ($a['wd'] ?? 0)) <=> ((int) ($b['wd'] ?? 0)));
        $r->members = $sorted;
        $r->bare = false;
        $r->dirty = true;
        return $r;
    }

    /** Set positions move by the landed day's change of position in the set. */
    private static function setPosition(Rule $r0, int $actedDn, int $landedDn, int $k, callable $at): array
    {
        $posIn = static function (int $dn) use ($r0): ?int {
            [$y, $m, $d] = Cal::ymd($dn);
            if (!in_array(Cal::wd($dn), $r0->set, true)) {
                return null;
            }
            $i = 0;
            $hits = [];
            $first = Cal::dn($y, $m, 1);
            for ($x = 1; $x <= Cal::monthLen($y, $m); $x++) {
                if (in_array(Cal::wd($first + $x - 1), $r0->set, true)) {
                    $hits[] = $x;
                }
            }
            $i = array_search($d, $hits, true);
            if ($i === false) {
                return null;
            }
            return (int) $r0->members[0]['n'] > 0 ? $i + 1 : $i - count($hits);
        };
        $from = $posIn($actedDn);
        $to = $posIn($landedDn);
        $rule = clone $r0;
        $rule->dirty = true;
        if ($from === null || $to === null) {
            return self::disable(self::reading('position', '', $rule, static fn(int $dn): ?int => null, false),
                'not_in_set', Words::day(Cal::wd($landedDn)) . ' isn\'t one of its days');
        }
        $dp = $to - $from;
        $pm = [];
        $bad = false;
        foreach ($r0->members as $mem) {
            $n = (int) $mem['n'] + $dp;
            if ($n === 0 || ($n > 0) !== ((int) $mem['n'] > 0) || abs($n) > 4 * count($r0->set)) {
                $bad = true;
            }
            $pm[] = ['t' => 'pos', 'n' => $n];
        }
        $rule->members = $pm;
        $reading = self::reading('position', '', $rule, static fn(int $dn, ?int $mem = null): ?int => $mem === null ? null : $at($pm[$mem], $dn, $k), $dp === 0);
        return $bad ? self::disable($reading, 'count_range', 'a position would have no place in some months') : $reading;
    }

    // ---- yearly ------------------------------------------------------

    private static function yearly(Rule $r0, int $actedDn, int $landedDn): array
    {
        [$y0, $mo0, $a0] = Cal::ymd($actedDn);
        [$y1, $mo1, $d1] = Cal::ymd($landedDn);
        $k = $y1 - $y0;
        // A start off its rule (an import's; RFC 5545 3.8.5.3 leaves it
        // undefined) has no member: it counts as the first, the rule's first day.
        $j = $r0->memberOf($actedDn) ?? 0;
        $dm = $mo1 - (int) $r0->months[$j];
        $old = $r0->members[0];
        $len1 = Cal::monthLen($y1, $mo1);
        $at = static function (array $mem, int $month, int $year) use ($r0): ?int {
            $d = $r0->evalMember($mem, $year, $month);
            return $d === null ? null : Cal::dn($year, $month, $d);
        };
        $forms = [];
        if ($old['t'] === 'nth') {
            $forms = self::landedForms($old, $landedDn);
        } elseif ($mo1 === 2 && $d1 === 29 && count($r0->months) === 1) {
            $forms = [['lastFeb', ['t' => 'day', 'n' => -1]], ['leap', ['t' => 'day', 'n' => 29]]];
        } elseif ($old['t'] === 'day' && (int) $old['n'] < 0 && $len1 - $d1 + 1 <= 7) {
            $forms = [['fromEnd', ['t' => 'day', 'n' => -($len1 - $d1 + 1)]], ['day', ['t' => 'day', 'n' => $d1]]];
        } else {
            $forms = [['day', ['t' => 'day', 'n' => $d1]]];
        }
        $months = array_map(static fn(int $m): int => $m + $dm, $r0->months);
        foreach ($months as $m) {
            if ($m < 1 || $m > 12) {
                $rule = clone $r0;
                return [self::disable(self::reading('follow', '', $rule, static fn(int $dn): ?int => null, false),
                    'year_cross', 'a month would move into another year')];
            }
        }
        $out = [];
        $whole = $k !== 0 && $dm === 0 && $forms[0][1] == $old && count($r0->months) > 1;
        if ($whole) {
            $member = clone $r0;
            $out[] = self::reading('member', 'Move every ' . Words::monthName((int) $r0->months[$j]) . '\'s ' . Words::periods(abs($k), 'year') . ($k > 0 ? ' later' : ' earlier'), $member,
                static fn(int $dn, ?int $mem = null): ?int => $at($old, (int) $r0->months[$mem ?? 0], Cal::ymd($dn)[0] + (($mem ?? $j) === $j ? $k : 0)), false);
        }
        foreach ($forms as [$id, $mem]) {
            $rule = clone $r0;
            $rule->members = [$mem];
            $rule->months = $months;
            $rule->bare = $r0->bare && $mem['t'] === 'day' && (int) $mem['n'] > 0 && !($mem['n'] === 29 && $months === [2]);
            $rule->bareMonth = $r0->bareMonth && $rule->bare && count($months) === 1;
            $rule->dirty = $r0->dirty || $mem != $old || $months !== $r0->months || $rule->bare !== $r0->bare || $rule->bareMonth !== $r0->bareMonth;
            $label = $whole ? 'Shift the whole pattern ' . Words::periods(abs($k), 'year') . ($k > 0 ? ' later' : ' earlier') : 'On ' . ($mem['t'] === 'day' && (int) $mem['n'] > 0 ? (int) $mem['n'] . ' ' . Words::andList(array_map([Words::class, 'monthName'], $months)) . ($id === 'leap' ? ', only in leap years' : '') : Words::member($mem) . ' of ' . Words::andList(array_map([Words::class, 'monthName'], $months)));
            $out[] = self::reading($whole ? 'pattern' : $id, $label, $rule,
                static fn(int $dn, ?int $m = null): ?int => $at($mem, $months[$m ?? 0], Cal::ymd($dn)[0] + $k),
                !$rule->dirty);
            if ($whole) {
                break;
            }
        }
        return $out;
    }
}
