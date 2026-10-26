<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/**
 * Repeats in quick-add text (#165): "every Tuesday", "Mondays and
 * Thursdays", "every other week", "first Monday of the month", "every year
 * on 12/23", with a start ("starting Nov 3") and an end ("until Dec 15",
 * "for 6 weeks", "10 times"). The phrase set follows what Fantastical and
 * Todoist document, minus hourly repeats and task-only rules. Pure, no I/O.
 *
 * extract() takes the repeat and its start/end phrases out of the text and
 * leaves the rest (the date, time, place and title) to FallbackParser, which
 * then asks firstOn() for the first matching day and rrule() for the rule.
 */
final class RepeatParser
{
    private const DAY = '(?:mon(?:day)?|tue(?:s(?:day)?)?|wed(?:nesday)?|thu(?:r(?:s(?:day)?)?)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)';
    private const DAYS_PLURAL = '(?:mondays|tuesdays|wednesdays|thursdays|fridays|saturdays|sundays)';
    // Between day names: a comma, "and", "&", or just a space ("mon wed fri").
    private const JOIN = '(?:\s*(?:,\s*and\b|,|&|\band\b)\s*|\s+)';
    private const ORD = '(first|1st|second|2nd|third|3rd|fourth|4th|fifth|5th|last)';
    private const MONTH = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)';
    private const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR'];
    private const WEEKEND = ['SA', 'SU'];
    private const CODES = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA']; // PHP's date('w') order
    // The app's own words for times of day (FallbackParser's vague times).
    private const TIMES = ['morning' => [9, 0], 'afternoon' => [14, 0], 'evening' => [18, 0], 'night' => [20, 0]];
    private const NUMBERS = ['a' => 1, 'an' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6,
        'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12];

    /**
     * @return null|array{work:string, rule:array<string,string>, startOn:?\DateTimeImmutable,
     *   until:?\DateTimeImmutable, count:?int, span:?array{n:int,unit:string}, time:?array{0:int,1:int}}
     */
    public static function extract(string $work, \DateTimeImmutable $now): ?array
    {
        $core = self::core($work);
        if ($core === null) {
            return null;
        }
        [$work, $rule, $time] = $core;
        // "Daily standup Mon-Fri": a day range narrows a plain daily repeat
        // to those days.
        if ($rule === ['FREQ' => 'DAILY'] && ($range = self::dayRangeAt($work)) !== null) {
            $rule = ['FREQ' => 'WEEKLY', 'BYDAY' => $range['byday']];
            $work = $range['rest'];
        }
        $out = ['work' => $work, 'rule' => $rule, 'startOn' => null, 'until' => null, 'count' => null, 'span' => null, 'time' => $time];
        return self::bounds($out, $now);
    }

    /** @return null|array{0:string,1:array<string,string>,2:?array{0:int,1:int}} */
    private static function core(string $work): ?array
    {
        $D = self::DAY;
        $list = $D . '(?:' . self::JOIN . $D . ')*';
        $plural = self::DAYS_PLURAL . '(?:' . self::JOIN . self::DAYS_PLURAL . ')*';
        $ord = self::ORD;
        $what = '(' . $D . '|weekday|weekend\s+day|day)';

        // Typed shortcut (Fantastical): ":weekly".
        if (preg_match('/(?<=\s):(daily|weekly|monthly|yearly|annually)\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), ['FREQ' => self::freqWord($m[1])], null];
        }
        // "first Monday of the month", "last weekday of every month",
        // "the 3rd Friday of each month", "last day of the month", and
        // "the first of every month" (a day, when no day is named).
        if (preg_match('/\b(?:(?:every|each|on)\s+)?(?:the\s+)?' . $ord . '\s+(?:' . $what . '\s+)?(?:of|in)\s+(?:the|each|every)\s+month\b/i', $work, $m)
            || preg_match('/\b(?:monthly|(?:every|each)\s+month)\s+on\s+(?:the\s+)?' . $ord . '\s+' . $what . '\b/i', $work, $m)
            || preg_match('/\b(?:every|each)\s+' . $ord . '\s+' . $what . '\b(?!\s+(?:of|in)\s+' . self::MONTH . '\b)/i', $work, $m)
        ) {
            return [self::cut($work, $m[0]), self::nthRule($m[1], ($m[2] ?? '') !== '' ? $m[2] : 'day'), null];
        }
        // "monthly on the 15th", "the 15th of every month", "every 15th".
        if (preg_match('/\b(?:monthly|(?:every|each)\s+month)\s+on\s+(?:the\s+)?(\d{1,2})(?:st|nd|rd|th)?\b/i', $work, $m)
            || preg_match('/\b(?:on\s+)?the\s+(\d{1,2})(?:st|nd|rd|th)\s+(?:of\s+)?(?:every|each)\s+month\b/i', $work, $m)
            || preg_match('/\b(?:every|each)\s+(\d{1,2})(?:st|nd|rd|th)\b(?!\s+(?:of\s+)?' . self::MONTH . '\b)/i', $work, $m)
        ) {
            $n = (int) $m[1];
            if ($n >= 1 && $n <= 31) {
                return [self::cut($work, $m[0]), ['FREQ' => 'MONTHLY', 'BYMONTHDAY' => (string) $n], null];
            }
        }
        // "every Monday to Friday", "every Mon-Fri".
        if (preg_match('/\b(?:every|each)\s+(' . $D . ')\s*(?:-|–|to|through|thru)\s*(' . $D . ')\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), ['FREQ' => 'WEEKLY', 'BYDAY' => self::dayRange($m[1], $m[2])], null];
        }
        // Weekdays and weekends.
        if (preg_match('/\b(?:every|each)\s+(?:week\s*day|work\s*day)\b|\b(?:on\s+)?(?:weekdays|workdays)\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), ['FREQ' => 'WEEKLY', 'BYDAY' => implode(',', self::WEEKDAYS)], null];
        }
        if (preg_match('/\b(?:every|each)\s+weekend\b|\b(?:on\s+)?weekends\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), ['FREQ' => 'WEEKLY', 'BYDAY' => implode(',', self::WEEKEND)], null];
        }
        // Quarters, and "every morning" (Todoist's times of day).
        if (preg_match('/\b(?:every|each)\s+quarter\b|\bquarterly\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), ['FREQ' => 'MONTHLY', 'INTERVAL' => '3'], null];
        }
        if (preg_match('/\b(?:every|each)\s+(morning|afternoon|evening|night)\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), ['FREQ' => 'DAILY'], self::TIMES[strtolower($m[1])]];
        }
        // "every other Tuesday", "every 2 weeks (on Mon and Thu)", "every 3 days".
        // "each other" is just words, so "other" only counts after "every".
        if (preg_match('/\bevery\s+other\s+(' . $list . ')\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), ['FREQ' => 'WEEKLY', 'INTERVAL' => '2', 'BYDAY' => self::codes($m[1])], null];
        }
        if (preg_match('/\b(?:every\s+(other|\d{1,2})|each\s+(\d{1,2}))\s+(day|week|month|year)s?\b(?:\s+on\s+(' . $list . ')\b)?/i', $work, $m)) {
            $every = $m[1] !== '' ? $m[1] : $m[2];
            $n = strtolower($every) === 'other' ? 2 : (int) $every;
            if ($n >= 1) {
                $rule = ['FREQ' => self::unitFreq($m[3])] + ($n > 1 ? ['INTERVAL' => (string) $n] : []);
                if (($m[4] ?? '') !== '' && $rule['FREQ'] === 'WEEKLY') {
                    $rule['BYDAY'] = self::codes($m[4]);
                }
                return [self::cut($work, $m[0]), $rule, null];
            }
        }
        // "every Tuesday", "every Mon, Wed and Fri".
        if (preg_match('/\b(?:every|each)\s+(' . $list . ')\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), ['FREQ' => 'WEEKLY', 'BYDAY' => self::codes($m[1])], null];
        }
        // "every week on Tuesday", "every year (on)", "everyday". A date after
        // "every year on" stays in the text for the date parser.
        if (preg_match('/\b(?:every|each)\s+(day|week|month|year)\b(?:\s+on\s+(' . $list . ')\b|\s+on\b)?/i', $work, $m)
            || preg_match('/\b(everyday)\b/i', $work, $m)
        ) {
            $freq = self::unitFreq(strtolower($m[1]) === 'everyday' ? 'day' : $m[1]);
            $rule = ['FREQ' => $freq];
            if (($m[2] ?? '') !== '' && $freq === 'WEEKLY') {
                $rule['BYDAY'] = self::codes($m[2]);
            }
            return [self::cut($work, $m[0]), $rule, null];
        }
        // "every Jan 27", "every 12/23": yearly, the date stays for the date parser.
        if (preg_match('/\b(?:every|each)\s+(?=' . self::MONTH . '\.?\s+\d|\d{1,2}\s+' . self::MONTH . '\b|\d{1,2}\/\d{1,2}\b)/i', $work, $m)) {
            return [self::cut($work, $m[0]), ['FREQ' => 'YEARLY'], null];
        }
        // "Mondays and Thursdays", "on Tuesdays".
        if (preg_match('/\b(?:on\s+)?(' . $plural . ')\b/i', $work, $m)) {
            return [self::cut($work, $m[0]), ['FREQ' => 'WEEKLY', 'BYDAY' => self::codes($m[1])], null];
        }
        // "Standup Mon-Fri 9am": a day range with a time (one time, not one
        // at each end, which reads as a single stretch: "Fri 5pm to Sun 2pm")
        // repeats on those days. Without a time it is one multi-day event,
        // left to FallbackParser. At the very start the time must come right
        // after it ("Mon-Fri 9am standup"); otherwise the range is part of
        // the title ("Mon-Fri check friday 3pm").
        if (($range = self::dayRangeAt($work)) !== null && self::hasTime($work)
            && (!$range['leading'] || preg_match('/^\s*(?:at\s+)?(?:\d{1,2}(?::\d{2})?\s*(?:am|pm)\b|\d{1,2}:\d{2}\b|noon\b|midnight\b)/i', substr($work, $range['offset'] + strlen($range['match']))) === 1)) {
            return [$range['rest'], ['FREQ' => 'WEEKLY', 'BYDAY' => $range['byday']], null];
        }
        // "daily", "weekly on Tue and Thu", "biweekly", "annually". As the
        // first word it also names the event ("Weekly review"), so it stays
        // in the title there; only a trailing "on <days>" leaves.
        if (preg_match('/\b(daily|weekly|fortnightly|bi-?weekly|monthly|yearly|annually)\b(\s+on\s+(' . $list . ')\b)?/i', $work, $m, PREG_OFFSET_CAPTURE)) {
            $word = strtolower($m[1][0]);
            $rule = in_array($word, ['fortnightly', 'biweekly', 'bi-weekly'], true)
                ? ['FREQ' => 'WEEKLY', 'INTERVAL' => '2']
                : ['FREQ' => self::freqWord($word)];
            if (isset($m[3]) && $m[3][0] !== '' && $rule['FREQ'] === 'WEEKLY') {
                $rule['BYDAY'] = self::codes($m[3][0]);
            }
            $leading = trim(substr($work, 0, $m[0][1])) === '' && preg_match('/\S/', substr($work, $m[0][1] + strlen($m[0][0]))) === 1;
            if (!$leading) {
                $work = self::cut($work, $m[0][0]);
            } elseif (($m[2][0] ?? '') !== '') {
                $work = self::cut($work, $m[2][0]);
            }
            return [$work, $rule, null];
        }
        return null;
    }

    /** Start and end phrases, read only once a repeat was found. */
    private static function bounds(array $out, \DateTimeImmutable $now): array
    {
        $work = $out['work'];
        // "from Jan 21 to May 23", "starting Nov 3", "beginning Monday". The
        // first of these followed by a date ("Pickup from school every Monday
        // starting Nov 3" reads past "from school").
        preg_match_all('/\b(?:from|starting(?:\s+(?:on|from))?|beginning(?:\s+on)?|effective)\s+/i', $work, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($all as $m) {
            $at = $m[0][1];
            $rest = substr($work, $at + strlen($m[0][0]));
            $first = FallbackParser::leadingDate($rest, $now);
            if ($first !== null) {
                $out['startOn'] = $first['date'];
                $len = strlen($m[0][0]) + $first['length'];
                if ($first['rangeEnd'] !== null) {
                    $out['until'] = $first['rangeEnd'];
                } elseif (preg_match('/^\s*(?:to|until|till|through|thru|-|–|—)\s*/i', substr($rest, $first['length']), $t)) {
                    $second = FallbackParser::leadingDate(substr($rest, $first['length'] + strlen($t[0])), $now);
                    if ($second !== null) {
                        $out['until'] = $second['date'];
                        $len += strlen($t[0]) + $second['length'];
                    }
                }
                $work = substr($work, 0, $at) . ' ' . substr($work, $at + $len);
                break;
            }
        }
        // "until Dec 15", "ending on Friday", "through May", "to May 23". The
        // first of these words followed by a date wins, so "Walk to the park
        // every Monday until Dec 15" still ends on Dec 15. A bare "to" is
        // everyday English ("to Friday Warehouse", "to Sunday market"), so
        // after it only a calendar date counts, one with a number in it.
        if ($out['until'] === null
            && preg_match_all('/\b(until|till|through|thru|ending(?:\s+on)?|ends(?:\s+on)?|to)\s+/i', $work, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)
        ) {
            foreach ($all as $m) {
                $at = $m[0][1];
                $rest = substr($work, $at + strlen($m[0][0]));
                $end = FallbackParser::leadingDate($rest, $now);
                if ($end === null || (strtolower($m[1][0]) === 'to' && preg_match('/\d/', substr($rest, 0, $end['length'])) !== 1)) {
                    continue;
                }
                $out['until'] = $end['date'];
                $work = substr($work, 0, $at) . ' ' . substr($work, $at + strlen($m[0][0]) + $end['length']);
                break;
            }
        }
        // "for 6 weeks", "for a month" (days and longer; "for 2 hours" is the
        // event's own length and stays for FallbackParser).
        if (preg_match('/\bfor\s+(\d{1,3}|' . implode('|', array_keys(self::NUMBERS)) . ')\s+(day|week|month|year)s?\b/i', $work, $m)) {
            $n = ctype_digit($m[1]) ? (int) $m[1] : self::NUMBERS[strtolower($m[1])];
            if ($n >= 1) {
                $out['span'] = ['n' => $n, 'unit' => strtolower($m[2])];
                $work = self::cut($work, $m[0]);
            }
        }
        // "10 times".
        if (preg_match('/\b(?:for\s+)?(\d{1,3})\s+(?:times|occurrences)\b/i', $work, $m) && (int) $m[1] >= 1) {
            $out['count'] = (int) $m[1];
            $work = self::cut($work, $m[0]);
        }
        $out['work'] = $work;
        return $out;
    }

    /** "FREQ=WEEKLY;BYDAY=TU" as ['FREQ' => 'WEEKLY', 'BYDAY' => 'TU']. @return array<string,string> */
    public static function ruleParts(string $rrule): array
    {
        $parts = [];
        foreach (explode(';', strtoupper(trim($rrule))) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if ($k !== '') {
                $parts[$k] = $v;
            }
        }
        return $parts;
    }

    /** The first day on or after $from that the rule lands on (time of day kept). */
    public static function firstOn(array $rule, \DateTimeImmutable $from): \DateTimeImmutable
    {
        $day = $from;
        for ($i = 0; $i < 1500; $i++) {
            if (self::matches($rule, $day)) {
                return $day;
            }
            $day = $day->modify('+1 day');
        }
        return $from;
    }

    /** Whether a day is one the rule names. A rule without BY-parts takes its start's day. */
    public static function matches(array $rule, \DateTimeImmutable $day): bool
    {
        $code = self::CODES[(int) $day->format('w')];
        $byday = isset($rule['BYDAY']) ? explode(',', $rule['BYDAY']) : [];
        $dom = (int) $day->format('j');
        $last = (int) $day->format('t');
        if (isset($rule['BYMONTHDAY'])) {
            $want = (int) $rule['BYMONTHDAY'];
            return $want === -1 ? $dom === $last : $dom === $want;
        }
        if ($byday === []) {
            return true;
        }
        if (isset($rule['BYSETPOS'])) {
            // The nth (or last) of the listed days within the month.
            if (!in_array($code, $byday, true)) {
                return false;
            }
            $hits = [];
            for ($d = 1; $d <= $last; $d++) {
                if (in_array(self::CODES[(int) $day->setDate((int) $day->format('Y'), (int) $day->format('n'), $d)->format('w')], $byday, true)) {
                    $hits[] = $d;
                }
            }
            $pos = (int) $rule['BYSETPOS'];
            $pick = $pos > 0 ? ($hits[$pos - 1] ?? null) : ($hits[count($hits) + $pos] ?? null);
            return $pick === $dom;
        }
        foreach ($byday as $entry) {
            if (preg_match('/^([+-]?\d)?([A-Z]{2})$/', $entry, $p) !== 1 || $p[2] !== $code) {
                continue;
            }
            if (($p[1] ?? '') === '') {
                return true;
            }
            $n = (int) $p[1];
            $nth = intdiv($dom - 1, 7) + 1;
            $fromEnd = intdiv($last - $dom, 7) + 1;
            if (($n > 0 && $nth === $n) || ($n < 0 && $fromEnd === -$n)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The RRULE for an extracted repeat that starts at $start: the rule, then
     * an end. A date series ends on a date (UNTIL=YYYYMMDD); a timed one at
     * the end of that day on the event's clock, in UTC.
     */
    public static function rrule(array $rep, \DateTimeImmutable $start, bool $allDay): string
    {
        $parts = [];
        foreach (['FREQ', 'INTERVAL', 'BYDAY', 'BYMONTHDAY', 'BYSETPOS'] as $k) {
            if (isset($rep['rule'][$k])) {
                $parts[] = $k . '=' . $rep['rule'][$k];
            }
        }
        $until = $rep['until'];
        if ($until === null && $rep['span'] !== null) {
            $until = $start->modify('+' . $rep['span']['n'] . ' ' . $rep['span']['unit'])->modify('-1 day');
        }
        if ($rep['count'] !== null) {
            $parts[] = 'COUNT=' . $rep['count'];
        } elseif ($until !== null) {
            $untilDay = $start->setDate((int) $until->format('Y'), (int) $until->format('n'), (int) $until->format('j'));
            if ($untilDay >= $start->setTime(0, 0)) {
                $parts[] = 'UNTIL=' . ($allDay
                    ? $untilDay->format('Ymd')
                    : $untilDay->setTime(23, 59, 59)->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'));
            }
        }
        return implode(';', $parts);
    }

    /** @return array<string,string> */
    private static function nthRule(string $ordinal, string $what): array
    {
        $n = [
            'first' => 1, '1st' => 1, 'second' => 2, '2nd' => 2, 'third' => 3, '3rd' => 3,
            'fourth' => 4, '4th' => 4, 'fifth' => 5, '5th' => 5,
        ][strtolower($ordinal)] ?? -1;
        $what = strtolower(preg_replace('/\s+/', ' ', $what) ?? $what);
        return match ($what) {
            'day' => ['FREQ' => 'MONTHLY', 'BYMONTHDAY' => (string) $n],
            'weekday' => ['FREQ' => 'MONTHLY', 'BYDAY' => implode(',', self::WEEKDAYS), 'BYSETPOS' => (string) $n],
            'weekend day' => ['FREQ' => 'MONTHLY', 'BYDAY' => implode(',', self::WEEKEND), 'BYSETPOS' => (string) $n],
            default => ['FREQ' => 'MONTHLY', 'BYDAY' => $n . self::code($what)],
        };
    }

    /**
     * The first day range in $work without "every" ("Mon-Fri", "Monday
     * through Friday", "from Fri to Mon"): not a place after "to"/"at", and
     * not when the second day starts a name ("Monday to Friday Harbor").
     * `leading` is true when nothing comes before it.
     *
     * @return null|array{match:string,offset:int,from:string,to:string,byday:string,rest:string,leading:bool}
     */
    public static function dayRangeAt(string $work): ?array
    {
        $D = self::DAY;
        $re = '/(?<![\w-])(?:from\s+)?(' . $D . ')\.?(?:\s*[-–—]\s*|\s+(?:to|through|thru)\s+)(' . $D . ')\.?(?![\w\'-])(?!\s+(?-i:[A-Z]))/i';
        if (preg_match_all($re, $work, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }
        // One later in the text wins over one that opens it, which may be
        // part of the title ("Mon-Fri check Mon-Fri 9am").
        $leading = null;
        foreach ($all as $m) {
            $at = $m[0][1];
            if (preg_match('/\b(?:to|at)\s+$/i', substr($work, 0, $at)) === 1) {
                continue;
            }
            $range = [
                'match' => $m[0][0], 'offset' => $at, 'from' => self::code($m[1][0]), 'to' => self::code($m[2][0]),
                'byday' => self::dayRange($m[1][0], $m[2][0]),
                'rest' => substr($work, 0, $at) . ' ' . substr($work, $at + strlen($m[0][0])),
                'leading' => trim(substr($work, 0, $at)) === '',
            ];
            if (!$range['leading']) {
                return $range;
            }
            $leading ??= $range;
        }
        return $leading;
    }

    /** A clock time or time of day anywhere in $work ("9am", "9:30", "noon", "in the morning"). */
    private static function hasTime(string $work): bool
    {
        return preg_match('/\b\d{1,2}(?::\d{2})?\s*(?:am|pm)\b|\b\d{1,2}:\d{2}\b|\b(?:noon|midnight|tonight)\b|\bat\s+\d{1,2}\b|\b(?:in\s+the|this)\s+(?:morning|afternoon|evening)\b/i', $work) === 1;
    }

    /** "Mon-Fri", "Monday through Friday": every day in the range, wrapping past Sunday. */
    private static function dayRange(string $from, string $to): string
    {
        $a = array_search(self::code($from), self::CODES, true);
        $b = array_search(self::code($to), self::CODES, true);
        $out = [];
        for ($i = (int) $a; ; $i = ($i + 1) % 7) {
            $out[] = self::CODES[$i];
            if ($i === (int) $b || count($out) === 7) {
                break;
            }
        }
        return implode(',', $out);
    }

    private static function codes(string $list): string
    {
        preg_match_all('/' . self::DAY . 's?\b/i', $list, $m);
        return implode(',', array_values(array_unique(array_map(self::code(...), $m[0]))));
    }

    private static function code(string $day): string
    {
        return strtoupper(substr($day, 0, 2));
    }

    private static function unitFreq(string $unit): string
    {
        return ['day' => 'DAILY', 'week' => 'WEEKLY', 'month' => 'MONTHLY', 'year' => 'YEARLY'][strtolower($unit)];
    }

    private static function freqWord(string $word): string
    {
        return match (strtolower($word)) {
            'daily' => 'DAILY', 'weekly' => 'WEEKLY', 'monthly' => 'MONTHLY', default => 'YEARLY',
        };
    }

    private static function cut(string $work, string $match): string
    {
        $pos = strpos($work, $match);
        return $pos === false ? $work : substr($work, 0, $pos) . ' ' . substr($work, $pos + strlen($match));
    }
}
