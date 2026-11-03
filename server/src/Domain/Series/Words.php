<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

/**
 * The series model's sentences: a rule in words ("Weekly on Tuesday at
 * 19:00", "Monthly on the 30th, or the last day in shorter months"), short
 * dates ("Wed 10 Mar", the year only when it isn't $thisYear), and the
 * names of members the readings' labels use. One generator for the dialog,
 * the Activity entry and an agent; web/src/lib/repeat.js follows the same
 * table (series-model-examples.md).
 */
final class Words
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    private const SHORT = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    private const ORD = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth'];
    private const LAST = [1 => 'last', 2 => 'second-last', 3 => 'third-last', 4 => 'fourth-last', 5 => 'fifth-last', 6 => 'sixth-last', 7 => 'seventh-last'];

    public static function day(int $wd): string
    {
        return self::DAYS[$wd];
    }

    public static function monthName(int $m): string
    {
        return self::MONTHS[$m - 1];
    }

    /** "1st", "2nd", "15th", "31st". */
    public static function ordinal(int $n): string
    {
        $suffix = 'th';
        if ($n % 100 < 11 || $n % 100 > 13) {
            $suffix = [1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th';
        }
        return $n . $suffix;
    }

    /** "a, b and c". @param list<string> $items */
    public static function andList(array $items): string
    {
        $items = array_values($items);
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }
        return implode(', ', array_slice($items, 0, -1)) . ' and ' . $items[count($items) - 1];
    }

    /** "Wed 10 Mar", with the year when it isn't $thisYear. */
    public static function date(int $dn, int $thisYear): string
    {
        [$y, $m, $d] = Cal::ymd($dn);
        return self::SHORT[Cal::wd($dn)] . ' ' . $d . ' ' . substr(self::MONTHS[$m - 1], 0, 3) . ($y !== $thisYear ? ' ' . $y : '');
    }

    /**
     * A plan day's words in their two parts and whole: `head`, the day
     * ("'Rehearsal' Tue 13 Apr", "Tue 23 Mar, moved to Fri 26 Mar"), and
     * `outcomeWords`, what happens to it ("moves to Wed 14 Apr, keeps its
     * title"), so the dialog draws them as two lines; `words` is the
     * sentence they make, $lead before the head ("Skipped ") and $sep
     * between them. Every date in either part is self::date's.
     *
     * @return array{head: string, outcomeWords: string, words: string}
     */
    public static function dayParts(string $head, string $outcome, string $sep = ' ', string $lead = ''): array
    {
        return ['head' => $head, 'outcomeWords' => $outcome, 'words' => $head === '' && $outcome === '' ? '' : $lead . $head . $sep . $outcome];
    }

    /** "10 Mar", with the year when it isn't $thisYear. */
    public static function dayMonth(int $dn, int $thisYear): string
    {
        [$y, $m, $d] = Cal::ymd($dn);
        return $d . ' ' . substr(self::MONTHS[$m - 1], 0, 3) . ($y !== $thisYear ? ' ' . $y : '');
    }

    /** "the 15th", "the last day", "the 30th, or the last day in shorter months", "the second Tuesday". */
    public static function member(array $mem, ?Rule $rule = null): string
    {
        switch ($mem['t']) {
            case 'day':
                $n = (int) $mem['n'];
                if ($n < 0) {
                    return isset(self::LAST[-$n]) ? 'the ' . self::LAST[-$n] . ' day' : 'the ' . self::ordinal(-$n) . ' day from the end';
                }
                return $n >= 29 ? 'the ' . self::ordinal($n) . ', skipping months without one' : 'the ' . self::ordinal($n);
            case 'clamp':
                return (int) $mem['n'] >= 31 ? 'the last day' : 'the ' . self::ordinal((int) $mem['n']) . ', or the last day in shorter months';
            case 'nth':
                $n = (int) $mem['n'];
                return 'the ' . ($n > 0 ? self::ORD[$n] ?? self::ordinal($n) : self::LAST[-$n] ?? self::ordinal(-$n) . '-last') . ' ' . self::DAYS[(int) $mem['wd']];
            case 'pos':
                $n = (int) $mem['n'];
                return 'the ' . ($n > 0 ? self::ORD[$n] ?? self::ordinal($n) : self::LAST[-$n] ?? self::ordinal(-$n) . '-last');
        }
        return '';
    }

    /** The members of a monthly rule as one phrase: "the 1st and 15th", "the first and third Monday". */
    public static function monthMembers(Rule $r): string
    {
        $m = $r->members;
        if (count($m) === 1) {
            return self::member($m[0], $r);
        }
        $t = $m[0]['t'];
        if ($t === 'day' && min(array_map(static fn(array $x): int => (int) $x['n'], $m)) > 0) {
            $max = max(array_map(static fn(array $x): int => (int) $x['n'], $m));
            return 'the ' . self::andList(array_map(static fn(array $x): string => self::ordinal((int) $x['n']), $m)) . ($max >= 29 ? ', skipping months without them' : '');
        }
        if ($t === 'nth') {
            $wds = array_unique(array_map(static fn(array $x): int => (int) $x['wd'], $m));
            if (count($wds) === 1) {
                return 'the ' . self::andList(array_map(static fn(array $x): string => (int) $x['n'] > 0 ? self::ORD[(int) $x['n']] : self::LAST[-(int) $x['n']], $m)) . ' ' . self::DAYS[(int) $m[0]['wd']];
            }
            return 'the ' . self::andList(array_map(static fn(array $x): string => substr(self::member($x), 4), $m));
        }
        if ($t === 'pos') {
            $set = $r->set;
            $noun = $set === [0, 1, 2, 3, 4] ? 'weekday' : ($set === [5, 6] ? 'weekend day' : self::andList(array_map(static fn(int $d): string => self::DAYS[$d], $set)));
            return 'the ' . self::andList(array_map(static fn(array $x): string => (int) $x['n'] > 0 ? self::ORD[(int) $x['n']] ?? self::ordinal((int) $x['n']) : self::LAST[-(int) $x['n']] ?? '', $m)) . ' ' . $noun;
        }
        return self::andList(array_map(static fn(array $x): string => self::member($x), $m));
    }

    /**
     * A rule in words, with its clock time when timed: "Weekly on Tuesday
     * at 19:00". Null for a rule outside the drag table that isn't simple.
     *
     * @param array{0:int,1:int,2:int}|null $clock
     */
    public static function rule(Rule $r, ?array $clock = null): string
    {
        $at = $clock !== null ? sprintf(' at %02d:%02d', $clock[0], $clock[1]) : '';
        $n = $r->interval;
        $every = static fn(string $unit, string $adverb): string => $n === 1 ? $adverb : "Every $n {$unit}s";
        switch ($r->kind) {
            case 'daily':
                return $every('day', 'Daily') . $at;
            case 'weekly':
                $days = Rule::sortedDays($r->members, 0);
                if ($n === 1 && $days === [0, 1, 2, 3, 4]) {
                    return 'Every weekday' . $at;
                }
                return $every('week', 'Weekly') . ' on ' . self::andList(array_map(static fn(int $d): string => self::DAYS[$d], $days)) . $at;
            case 'monthly':
                return self::clockBeforeClause($every('month', 'Monthly') . ' on ' . self::monthMembers($r), $at);
            case 'yearly':
                $mem = $r->members[0];
                $months = self::andList(array_map(static fn(int $m): string => self::MONTHS[$m - 1], $r->months));
                if ($mem['t'] === 'day' && (int) $mem['n'] > 0) {
                    $words = $every('year', 'Yearly') . ' on ' . $mem['n'] . ' ' . $months;
                    if ((int) $mem['n'] === 29 && $r->months === [2]) {
                        $words .= ', only in leap years';
                    }
                    return self::clockBeforeClause($words, $at);
                }
                return $every('year', 'Yearly') . ' on ' . self::member($mem) . ' of ' . $months . $at;
        }
        if ($r->kind === 'unreadable') {
            return 'Repeats in a form Better-Cal can\'t read';
        }
        return 'Repeats (' . $r->pattern() . ')' . $at;
    }

    /**
     * The clock right after the day, before a clause about other months:
     * "Monthly on the 31st at 16:00, skipping months without one".
     */
    private static function clockBeforeClause(string $words, string $at): string
    {
        if ($at === '' || preg_match('/, (skipping|or the last|only in)/', $words, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return $words . $at;
        }
        $comma = (int) $m[0][1];
        return substr($words, 0, $comma) . $at . substr($words, $comma);
    }

    public static function lower(string $s): string
    {
        return $s === '' ? '' : strtolower($s[0]) . substr($s, 1);
    }

    public static function upper(string $s): string
    {
        return $s === '' ? '' : strtoupper($s[0]) . substr($s, 1);
    }

    /** "Two", "Three" up to "Ten" to start a sentence, the number past that. */
    public static function count(int $n): string
    {
        return [2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten'][$n] ?? (string) $n;
    }

    /** "6 events", "1 event". */
    public static function events(int $n): string
    {
        return $n . ' event' . ($n === 1 ? '' : 's');
    }

    /** "a week", "2 weeks", "a month", "3 months". */
    public static function periods(int $n, string $unit): string
    {
        return $n === 1 ? 'a ' . $unit : $n . ' ' . $unit . 's';
    }
}
