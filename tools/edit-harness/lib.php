<?php
// Property harness for repeating-series edits: builds random series with
// exceptions, applies random edits through the real domain code (Events,
// Undo) against a migrated database, and checks invariants against its own
// reading of the rules rather than hand-written expectations. See README.md.
// harness.php runs it from the command line; server/tests/db/edits.test.php
// runs a seeded subset with the database suite.
declare(strict_types=1);

namespace BetterCal\EditHarness;

use BetterCal\Domain\Events;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\RepeatParser;
use BetterCal\Domain\Settings;
use BetterCal\Domain\Undo;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

/** A small deterministic generator (xorshift32), so a seed names a run on any PHP build. */
final class Rng
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed * 2654435761 + 1013904223) & 0xFFFFFFFF ?: 0x9E3779B9;
    }

    public function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x & 0xFFFFFFFF;
        return $this->state;
    }

    public function int(int $min, int $max): int
    {
        return $min + ($this->next() % ($max - $min + 1));
    }

    public function chance(float $p): bool
    {
        return ($this->next() % 10000) < (int) ($p * 10000);
    }

    /** @template T @param list<T> $items @return T */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    /** Pick by weight: ['a' => 3, 'b' => 1]. */
    public function weighted(array $weights): string
    {
        $total = array_sum($weights);
        $n = $this->int(1, $total);
        foreach ($weights as $key => $w) {
            $n -= $w;
            if ($n <= 0) {
                return (string) $key;
            }
        }
        return (string) array_key_last($weights);
    }
}

/**
 * What the harness knows about rules: every family the app writes, and
 * how each one is expected to answer a drag of its days.
 *
 *  shift: the occurrence days move by the drag (daily, weekly).
 *  landed: the days are the day landed on, in each period (a monthly or
 *          yearly rule naming one day, or none: the start's day).
 *  keep:   the rule cannot follow (a list of days, a day from the end): the
 *          days stay and the start snaps to the next listed day.
 */
final class Families
{
    public const FOLLOW = [
        'daily' => 'shift', 'weekly_plain' => 'shift', 'weekly_single' => 'shift', 'weekly_list' => 'shift',
        'monthly_plain' => 'landed', 'monthly_day' => 'landed', 'monthly_nth' => 'landed', 'monthly_nth_setpos' => 'landed',
        'yearly_plain' => 'landed', 'yearly_day' => 'landed', 'yearly_nth' => 'landed',
        'monthly_day_end' => 'keep', 'monthly_day_list' => 'keep', 'yearly_months' => 'keep',
    ];

    public const WEEK = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    public static function unit(string $family): ?string
    {
        return str_starts_with($family, 'monthly') ? 'month' : (str_starts_with($family, 'yearly') ? 'year' : null);
    }

    /** The nth-weekday count of a day: 1 to 4 from the start, 5 written as the last; or from the end when $fromEnd. */
    public static function nthOf(\DateTimeImmutable $day, bool $fromEnd): int
    {
        if ($fromEnd) {
            $n = -(intdiv((int) $day->format('t') - (int) $day->format('j'), 7) + 1);
            return $n === -5 ? 1 : $n;
        }
        $n = intdiv((int) $day->format('j') - 1, 7) + 1;
        return $n === 5 ? -1 : $n;
    }

    /**
     * The rule a 'landed' family is expected to have after its dragged
     * occurrence lands on $to: the same shape, naming $to's day. The
     * bounds (COUNT, UNTIL) are left as they were; the oracle bounds the
     * expansion itself.
     */
    public static function landedRule(string $family, string $rrule, \DateTimeImmutable $to): string
    {
        $parts = RepeatParser::ruleParts($rrule);
        $code = self::WEEK[(int) $to->format('N') - 1];
        switch ($family) {
            case 'monthly_day':
                $parts['BYMONTHDAY'] = $to->format('j');
                break;
            case 'monthly_plain':
                // A bare monthly rule repeats on its start's day; the series starts on the landed day.
                $parts['BYMONTHDAY'] = $to->format('j');
                break;
            case 'yearly_plain':
                $parts['BYMONTH'] = $to->format('n');
                $parts['BYMONTHDAY'] = $to->format('j');
                break;
            case 'yearly_day':
                $parts['BYMONTH'] = $to->format('n');
                $parts['BYMONTHDAY'] = $to->format('j');
                break;
            case 'monthly_nth':
            case 'yearly_nth':
                $fromEnd = str_starts_with($parts['BYDAY'], '-');
                $parts['BYDAY'] = self::nthOf($to, $fromEnd) . $code;
                if ($family === 'yearly_nth') {
                    $parts['BYMONTH'] = $to->format('n');
                }
                break;
            case 'monthly_nth_setpos':
                $fromEnd = str_starts_with($parts['BYSETPOS'], '-');
                $parts['BYDAY'] = $code;
                $parts['BYSETPOS'] = (string) self::nthOf($to, $fromEnd);
                break;
        }
        $out = [];
        foreach ($parts as $k => $v) {
            $out[] = $k . '=' . $v;
        }
        return implode(';', $out);
    }
}

/**
 * Reads series the way the app stores them and expands them with
 * Recurrence::sabreExpand (the expander the app uses), with the bounds
 * read from the rule and no skipped dates applied: the raw days a rule
 * produces, which every exception must be attached to.
 *
 * A bounded series is expanded through its end (its COUNT, or its
 * UNTIL), so its list is the whole series. An unbounded one is observed
 * over a window from its start, and expansion() says how far: a check
 * that needs a day beyond it looks there (isOccurrence), and two
 * unbounded lists compare over the window both saw (common).
 */
final class Oracle
{
    public const WINDOW_DAYS = 740;

    /** @return list<array{key:string,day:string,local:\DateTimeImmutable}> */
    public static function raw(array $row, ?int $limit = null): array
    {
        return self::expansion($row, $limit)['occ'];
    }

    /**
     * The raw occurrences and how far they are complete: 'through' is null
     * when the list is the whole series, or the key before which it holds
     * every occurrence (a window's end, or a cut at $limit or at the
     * expander's cap).
     *
     * @return array{occ:list<array{key:string,day:string,local:\DateTimeImmutable}>,through:?string}
     */
    public static function expansion(array $row, ?int $limit = null): array
    {
        $tz = Time::zone((string) $row['tzid']);
        $start = Time::fromDb((string) $row['start_utc']);
        $parts = RepeatParser::ruleParts((string) $row['rrule']);
        if (isset($parts['COUNT'])) {
            // The iterator stops at the COUNT; the far end only bounds a rule that yields nothing.
            $winEnd = $start->modify('+400 years');
        } elseif (isset($parts['UNTIL'])) {
            // A date UNTIL is a day in the series' zone; two days on cover it in any zone.
            // An UNTIL before the start still has the start (the expander yields it).
            $u = (string) $parts['UNTIL'];
            $winEnd = max($start->modify('+2 days'), (strlen($u) === 8
                ? new \DateTimeImmutable(substr($u, 0, 4) . '-' . substr($u, 4, 2) . '-' . substr($u, 6, 2), Time::utc())
                : \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $u, Time::utc()))->modify('+2 days'));
        } else {
            // Two years of a daily or weekly rule, eight of a monthly one, twenty-five of a yearly one.
            $unit = Families::unit(self::familyOf((string) $row['rrule']));
            $days = $unit === null ? self::WINDOW_DAYS : ($unit === 'month' ? self::WINDOW_DAYS * 4 : 9150);
            $winEnd = $start->modify('+' . $days . ' days');
        }
        $through = isset($parts['COUNT']) || isset($parts['UNTIL']) ? null : Time::toDb($winEnd);
        $master = ['exdates_json' => null, 'uid' => (string) ($row['uid'] ?? 'oracle')] + $row;
        $found = Recurrence::sabreExpand($master, $start->modify('-1 day'), $winEnd);
        if (count($found) > Recurrence::MAX_INSTANCES) {
            $through = Time::toDb(end($found)['start']);
            array_pop($found);
        }
        $out = [];
        foreach ($found as $occ) {
            $local = $occ['start']->setTimezone((int) $row['all_day'] === 1 ? Time::utc() : $tz);
            $out[] = ['key' => Time::toDb($occ['start']), 'day' => $local->format('Y-m-d'), 'local' => $local];
            if ($limit !== null && count($out) >= $limit) {
                // Complete through the last one taken.
                $through = Time::toDb($occ['start']->modify('+1 second'));
                break;
            }
        }
        return ['occ' => $out, 'through' => $through];
    }

    /** Whether $key is an occurrence of the row's rule, read in a window around it. */
    public static function isOccurrence(array $row, string $key): bool
    {
        $at = Time::fromDb($key);
        $master = ['exdates_json' => null, 'uid' => (string) ($row['uid'] ?? 'oracle')] + $row;
        foreach (Recurrence::sabreExpand($master, $at->modify('-1 second'), $at->modify('+1 second')) as $occ) {
            if (Time::toDb($occ['start']) === $key) {
                return true;
            }
        }
        return false;
    }

    /**
     * Two key lists cut to the window both are complete over: the earlier
     * of their 'through' keys (null for a whole series).
     *
     * @param list<string> $a @param list<string> $b
     * @return array{0:list<string>,1:list<string>}
     */
    public static function common(array $a, ?string $throughA, array $b, ?string $throughB): array
    {
        $through = $throughA === null ? $throughB : ($throughB === null ? $throughA : min($throughA, $throughB));
        if ($through === null) {
            return [$a, $b];
        }
        $before = static fn(array $keys): array => array_values(array_filter($keys, static fn(string $k): bool => $k < $through));
        return [$before($a), $before($b)];
    }

    /** The family name of a stored rule (for rows the harness did not generate). */
    public static function familyOf(string $rrule): string
    {
        $p = RepeatParser::ruleParts($rrule);
        $freq = $p['FREQ'] ?? '';
        if ($freq === 'DAILY') {
            return 'daily';
        }
        if ($freq === 'WEEKLY') {
            return empty($p['BYDAY']) ? 'weekly_plain' : (str_contains($p['BYDAY'], ',') ? 'weekly_list' : 'weekly_single');
        }
        if ($freq === 'MONTHLY') {
            if (isset($p['BYSETPOS'])) {
                return 'monthly_nth_setpos';
            }
            if (isset($p['BYDAY'])) {
                return 'monthly_nth';
            }
            if (isset($p['BYMONTHDAY'])) {
                return str_contains($p['BYMONTHDAY'], ',') ? 'monthly_day_list' : (str_starts_with($p['BYMONTHDAY'], '-') ? 'monthly_day_end' : 'monthly_day');
            }
            return 'monthly_plain';
        }
        if (isset($p['BYMONTH']) && str_contains($p['BYMONTH'], ',')) {
            return 'yearly_months';
        }
        if (isset($p['BYDAY'])) {
            return 'yearly_nth';
        }
        return isset($p['BYMONTHDAY']) ? 'yearly_day' : 'yearly_plain';
    }

    /** A key's local day in a zone (all-day keys are UTC dates). */
    public static function dayOf(string $key, string $tzid, bool $allDay): string
    {
        return Time::fromDb($key)->setTimezone($allDay ? Time::utc() : Time::zone($tzid))->format('Y-m-d');
    }

    /** The key of $day at $time (H:i:s) in $tzid, or the UTC date for an all-day series. */
    public static function keyAt(string $day, string $time, string $tzid, bool $allDay): string
    {
        if ($allDay) {
            return $day . ' 00:00:00';
        }
        return Time::toDb(new \DateTimeImmutable($day . ' ' . $time, Time::zone($tzid)));
    }

    public static function addDays(string $day, int $days): string
    {
        return (new \DateTimeImmutable($day, Time::utc()))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** Whole days from one local day to another. */
    public static function daysBetween(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from, Time::utc()))->diff(new \DateTimeImmutable($to, Time::utc()))->format('%r%a');
    }

    /** Months (or years) from one local day to another. */
    public static function periods(string $fromDay, string $toDay, string $unit): int
    {
        $a = new \DateTimeImmutable($fromDay, Time::utc());
        $b = new \DateTimeImmutable($toDay, Time::utc());
        return $unit === 'month'
            ? ((int) $b->format('Y') * 12 + (int) $b->format('n')) - ((int) $a->format('Y') * 12 + (int) $a->format('n'))
            : (int) $b->format('Y') - (int) $a->format('Y');
    }

    public static function periodOf(string $day, string $unit): string
    {
        return substr($day, 0, $unit === 'month' ? 7 : 4);
    }

    /**
     * The occurrences a series is expected to have after an edit, given
     * the raw occurrences it had ($old), the family's way of following a
     * drag, the drag's days, the landed day of the dragged occurrence, and
     * the new time, zone and all-day flag. Days only; see the invariants.
     *
     * @param list<array{key:string,day:string}> $old
     * @return array{days:list<string>,rule:?string,dropped:int}
     */
    public static function expected(string $family, array $old, string $rrule, int $days, ?string $landedDay): array
    {
        $follow = Families::FOLLOW[$family];
        $oldDays = array_column($old, 'day');
        if ($days === 0 || $oldDays === []) {
            return ['days' => $oldDays, 'rule' => null, 'dropped' => 0];
        }
        if ($follow === 'shift') {
            return ['days' => array_map(static fn(string $d): string => self::addDays($d, $days), $oldDays), 'rule' => null, 'dropped' => 0];
        }
        $startDay = self::addDays($oldDays[0], $days);
        if ($follow === 'keep') {
            // The start snaps forward to the next listed day: whatever came before it is gone.
            $rule = RepeatParser::ruleParts($rrule);
            $first = RepeatParser::firstOn($rule, new \DateTimeImmutable($startDay, Time::utc()))->format('Y-m-d');
            $kept = array_values(array_filter($oldDays, static fn(string $d): bool => $d >= $first));
            return ['days' => $kept, 'rule' => null, 'dropped' => count($oldDays) - count($kept)];
        }
        // landed: the rule names the landed day; the series starts on the first such day from its moved start.
        $unit = Families::unit($family);
        $rule = Families::landedRule($family, $rrule, new \DateTimeImmutable((string) $landedDay, Time::utc()));
        $parts = RepeatParser::ruleParts($rule);
        $count = isset($parts['COUNT']) ? (int) $parts['COUNT'] : null;
        $hasUntil = isset($parts['UNTIL']);
        unset($parts['COUNT'], $parts['UNTIL']);
        $pattern = implode(';', array_map(static fn($k, $v) => "$k=$v", array_keys($parts), $parts));
        // A yearly nth weekday keeps a start off its pattern (the app leaves it); the others start on the rule's day.
        $startFrom = $family === 'yearly_nth' ? $startDay : RepeatParser::firstOn($parts, new \DateTimeImmutable($startDay, Time::utc()))->format('Y-m-d');
        $row = ['start_utc' => $startFrom . ' 12:00:00', 'end_utc' => $startFrom . ' 13:00:00', 'tzid' => 'UTC', 'all_day' => 0, 'rrule' => $pattern];
        // A bounded expectation is expanded through its end, not over a window.
        if ($count !== null) {
            $row['rrule'] = $pattern . ';COUNT=' . $count;
            $expanded = array_column(self::raw($row), 'day');
        } elseif ($hasUntil) {
            // The series ends in the period its last day was in, moved with the start.
            $shift = self::periods($oldDays[0], $startFrom, $unit);
            $lastStart = self::shiftPeriod(end($oldDays), $shift, $unit);
            $lastPeriod = self::periodOf($lastStart, $unit);
            $periodEnd = (new \DateTimeImmutable($lastStart, Time::utc()))->modify('+1 ' . $unit)->modify('-1 second');
            $row['rrule'] = $pattern . ';UNTIL=' . $periodEnd->format('Ymd\THis\Z');
            $expanded = array_column(self::raw($row), 'day');
            $expanded = array_values(array_filter($expanded, static fn(string $d): bool => self::periodOf($d, $unit) <= $lastPeriod));
        } else {
            $expanded = array_column(self::raw($row), 'day');
        }
        return ['days' => $expanded, 'rule' => $rule, 'dropped' => 0];
    }

    /** The first of the month (or year) $periods on from $day's. */
    public static function shiftPeriod(string $day, int $periods, string $unit): string
    {
        $base = new \DateTimeImmutable($unit === 'month' ? substr($day, 0, 7) . '-01' : substr($day, 0, 4) . '-01-01', Time::utc());
        return $base->modify(($periods >= 0 ? '+' : '') . $periods . ' ' . $unit)->format('Y-m-d');
    }

    /**
     * Where an exception keyed on an old occurrence day is expected after
     * the edit: the same place in the series. For a 'shift' family that is
     * the day moved by the drag; for a 'landed' one the nth occurrence of
     * its month (year) becomes the nth of the month the series moved to;
     * for 'keep' and for an edit that moved no days, the same day.
     *
     * @param list<string> $oldDays raw days before @param list<string> $newDays raw days after
     */
    public static function mappedDay(string $family, string $day, array $oldDays, array $newDays, int $days): ?string
    {
        $follow = Families::FOLLOW[$family];
        if ($days === 0 || $follow === 'keep') {
            return $day;
        }
        if ($follow === 'shift') {
            return self::addDays($day, $days);
        }
        $unit = Families::unit($family);
        if ($oldDays === [] || $newDays === []) {
            return null;
        }
        $period = self::periodOf($day, $unit);
        $inPeriod = array_values(array_filter($oldDays, static fn(string $d): bool => self::periodOf($d, $unit) === $period));
        $n = array_search($day, $inPeriod, true);
        if ($n === false) {
            return null;
        }
        $shift = self::periods($oldDays[0], $newDays[0], $unit);
        $target = self::periodOf(self::shiftPeriod($day, $shift, $unit), $unit);
        $there = array_values(array_filter($newDays, static fn(string $d): bool => self::periodOf($d, $unit) === $target));
        return $there[$n] ?? null;
    }
}

/**
 * Builds one case: a series the way the app writes them, then (once the
 * series exists and its days are known) its exceptions and the edit.
 */
final class Generator
{
    public const ZONES = ['America/Los_Angeles', 'Europe/Berlin', 'Asia/Kolkata', 'UTC', 'Pacific/Auckland'];
    public const TIMES = ['06:00', '09:30', '13:00', '18:00', '21:15'];

    public const EDITS = [
        'drag_all' => 14, 'time_all' => 4, 'allday_all' => 4, 'zone_all' => 3, 'editor_drag_all' => 4, 'rule_all' => 3,
        'drag_following' => 12, 'rename_following' => 5, 'editor_drag_following' => 3,
        'delete_following' => 5, 'delete_this' => 5, 'attend_following' => 3,
        // Whole-series field and bound edits as first edits too (own fields
        // inherited on a primary rename, the Ends-changed path).
        'rename_all' => 3, 'attend_all' => 2, 'bound_all' => 3,
    ];

    /** The series part of a case (no database needed). */
    public static function series(Rng $r, ?string $onlyFamily = null): array
    {
        $family = $onlyFamily ?? $r->weighted([
            'daily' => 4, 'weekly_plain' => 2, 'weekly_single' => 8, 'weekly_list' => 6,
            'monthly_plain' => 4, 'monthly_day' => 8, 'monthly_day_end' => 3, 'monthly_day_list' => 3,
            'monthly_nth' => 6, 'monthly_nth_setpos' => 3,
            'yearly_plain' => 2, 'yearly_day' => 4, 'yearly_nth' => 4, 'yearly_months' => 2,
        ]);
        $allDay = $r->chance(0.3);
        $tzid = $allDay ? 'UTC' : $r->pick(self::ZONES);
        $zone = Time::zone($tzid);
        // Starts around today, biased toward month ends and the turn of the year.
        $day = $r->chance(0.3)
            ? $r->pick(['2026-12-29', '2026-12-30', '2026-12-31', '2027-01-01', '2027-01-30', '2027-01-31', '2027-02-27', '2027-02-28', '2027-03-01', '2027-03-31', '2027-04-30', '2026-11-30'])
            : (new \DateTimeImmutable('2026-06-01', $zone))->modify('+' . $r->int(0, 400) . ' days')->format('Y-m-d');
        $start = new \DateTimeImmutable($day, $zone);
        $onPattern = $r->chance(0.7);
        $parts = [];
        $week = Families::WEEK;
        $nth = $r->pick([1, 2, 3, 4, -1]);
        $code = $r->pick($week);
        switch ($family) {
            case 'daily':
                $parts = ['FREQ=DAILY'];
                if ($r->chance(0.4)) {
                    $parts[] = 'INTERVAL=' . $r->int(2, 3);
                }
                break;
            case 'weekly_plain':
                $parts = ['FREQ=WEEKLY'];
                if ($r->chance(0.4)) {
                    $parts[] = 'INTERVAL=2';
                }
                break;
            case 'weekly_single':
                if ($onPattern) {
                    $code = $week[(int) $start->format('N') - 1];
                }
                $parts = ['FREQ=WEEKLY'];
                if ($r->chance(0.3)) {
                    $parts[] = 'INTERVAL=2';
                }
                $parts[] = 'BYDAY=' . $code;
                break;
            case 'weekly_list':
                $days = [];
                foreach ($week as $i => $c) {
                    if ($r->chance(0.35)) {
                        $days[$i] = $c;
                    }
                }
                if (count($days) < 2) {
                    $days = [0 => 'MO', 2 => 'WE', 4 => 'FR'];
                }
                if ($onPattern) {
                    $start = RepeatParser::firstOn(['BYDAY' => implode(',', $days)], $start);
                }
                $parts = ['FREQ=WEEKLY'];
                if ($r->chance(0.3)) {
                    $parts[] = 'INTERVAL=2';
                }
                $parts[] = 'BYDAY=' . implode(',', $days);
                break;
            case 'monthly_plain':
                $parts = ['FREQ=MONTHLY'];
                break;
            case 'monthly_day':
                $dom = $r->chance(0.4) ? $r->pick([28, 29, 30, 31]) : $r->int(1, 27);
                if ($onPattern) {
                    $start = RepeatParser::firstOn(['BYMONTHDAY' => (string) $dom], $start);
                }
                $parts = ['FREQ=MONTHLY', 'BYMONTHDAY=' . $dom];
                break;
            case 'monthly_day_end':
                $dom = $r->pick([-1, -2]);
                if ($onPattern) {
                    $start = RepeatParser::firstOn(['BYMONTHDAY' => (string) $dom], $start);
                }
                $parts = ['FREQ=MONTHLY', 'BYMONTHDAY=' . $dom];
                break;
            case 'monthly_day_list':
                $list = $r->pick(['1,15', '10,20', '5,25', '1,15,28', '15,-1']);
                if ($onPattern) {
                    $start = RepeatParser::firstOn(['BYMONTHDAY' => $list], $start);
                }
                $parts = ['FREQ=MONTHLY', 'BYMONTHDAY=' . $list];
                break;
            case 'monthly_nth':
                if ($onPattern) {
                    $start = RepeatParser::firstOn(['BYDAY' => $nth . $code], $start);
                }
                $parts = ['FREQ=MONTHLY', 'BYDAY=' . $nth . $code];
                break;
            case 'monthly_nth_setpos':
                if ($onPattern) {
                    $start = RepeatParser::firstOn(['BYDAY' => $code, 'BYSETPOS' => (string) $nth], $start);
                }
                $parts = ['FREQ=MONTHLY', 'BYDAY=' . $code, 'BYSETPOS=' . $nth];
                break;
            case 'yearly_plain':
                $parts = ['FREQ=YEARLY'];
                break;
            case 'yearly_day':
                $month = $r->int(1, 12);
                $dom = $r->chance(0.3) ? $r->pick([28, 29, 30, 31]) : $r->int(1, 27);
                if ($month === 2 && $dom > 29) {
                    $dom = 28;
                }
                if (in_array($month, [4, 6, 9, 11], true) && $dom === 31) {
                    $dom = 30;
                }
                if ($onPattern) {
                    $start = RepeatParser::firstOn(['BYMONTH' => (string) $month, 'BYMONTHDAY' => (string) $dom], $start);
                }
                $parts = ['FREQ=YEARLY', 'BYMONTH=' . $month, 'BYMONTHDAY=' . $dom];
                break;
            case 'yearly_nth':
                $month = $r->int(1, 12);
                if ($onPattern) {
                    $start = RepeatParser::firstOn(['BYMONTH' => (string) $month, 'BYDAY' => $nth . $code], $start);
                }
                $parts = ['FREQ=YEARLY', 'BYMONTH=' . $month, 'BYDAY=' . $nth . $code];
                break;
            case 'yearly_months':
                $months = $r->pick(['3,9', '1,7', '2,5,11']);
                $dom = $r->int(1, 28);
                if ($onPattern) {
                    $start = RepeatParser::firstOn(['BYMONTH' => $months, 'BYMONTHDAY' => (string) $dom], $start);
                }
                $parts = ['FREQ=YEARLY', 'BYMONTH=' . $months, 'BYMONTHDAY=' . $dom];
                break;
        }
        $bound = $r->weighted(['none' => 4, 'count' => 3, 'until' => 3]);
        return [
            'family' => $family,
            'rrule' => implode(';', $parts),
            'bound' => $bound,
            'boundAt' => $bound === 'count' ? $r->int(4, 16) : $r->int(3, 12), // COUNT, or the index of the last occurrence for UNTIL
            'untilForm' => $r->chance(0.75) ? 'dayend' : 'exact', // a timed UNTIL at 23:59:59 of the last day (the editor), or at its last start
            'allDay' => $allDay,
            'tzid' => $tzid,
            'start' => $start->format('Y-m-d') . ($allDay ? '' : ' ' . $r->pick(self::TIMES)),
            'length' => $allDay ? $r->pick([1, 1, 1, 2]) : $r->pick([30, 60, 90, 120]),
            'onPattern' => $onPattern,
        ];
    }

    /**
     * The exceptions and the edit for a series whose raw days are known.
     *
     * @param list<array{key:string,day:string}> $raw
     */
    public static function plan(Rng $r, array $series, array $raw, ?string $onlyEdit = null): array
    {
        $n = count($raw);
        $allDay = $series['allDay'];
        $edit = $onlyEdit ?? $r->weighted(self::EDITS);
        $from = $r->weighted(['ordinary' => 6, 'edited' => 3, 'first' => 2, 'last' => 2]);
        if ($edit === 'rename_following' && $r->chance(0.6)) {
            $from = 'edited';
        }
        if ($from === 'last' && ($series['bound'] === 'none' || $n > 60)) {
            $from = 'ordinary';
        }
        $last = $n - 1;
        $at = match ($from) {
            'first' => 0,
            'last' => $last,
            default => $n <= 2 ? 0 : $r->int(1, min($last, 24)),
        };
        // Exceptions around the edit point: before, at, after, the last one.
        $slots = [];
        $want = $r->int(0, 3);
        $skips = $r->int(0, 2);
        $candidates = array_values(array_unique(array_filter(
            [$at - 1, $at, $at + 1, $last, $r->int(0, min($last, 30)), $r->int(0, min($last, 30)), 0],
            static fn(int $i): bool => $i >= 0 && $i <= $last
        )));
        $kinds = $allDay ? ['title', 'day', 'title'] : ['title', 'time', 'day', 'zone'];
        $exceptions = [];
        $used = [];
        $atKeepsOrdinary = $from === 'ordinary' || $from === 'first' || $from === 'last';
        for ($i = 0; $i < $want + $skips && $candidates !== []; $i++) {
            $idx = $r->pick($candidates);
            $candidates = array_values(array_diff($candidates, [$idx]));
            if (isset($used[$idx])) {
                continue;
            }
            $isSkip = $i >= $want;
            if ($isSkip && ($idx === $at || ($from === 'first' && $idx === 0))) {
                continue; // the day acted on is never a skipped one
            }
            if (!$isSkip && $idx === $at && $atKeepsOrdinary) {
                continue;
            }
            $used[$idx] = true;
            $exceptions[] = $isSkip
                ? ['kind' => 'skip', 'at' => $idx]
                : ['kind' => $r->pick($kinds), 'at' => $idx, 'days' => $r->pick([-3, -2, -1, 1, 2, 3]), 'hours' => $r->pick([-4, -2, 2, 3, 5])];
        }
        if ($from === 'edited') {
            $edited = array_values(array_filter($exceptions, static fn(array $e): bool => $e['kind'] !== 'skip'));
            if ($edited === []) {
                $exceptions[] = ['kind' => $r->pick($kinds), 'at' => $at, 'days' => $r->pick([-2, -1, 1, 2]), 'hours' => $r->pick([-2, 2, 3])];
            } else {
                $at = $edited[0]['at'];
            }
        }
        $days = $r->chance(0.25) ? $r->pick([28, 29, 30, 31, 35, 45, -3]) : $r->int(-3, 14);
        if ($days === 0) {
            $days = 1;
        }
        $hours = $allDay ? 0 : ($r->chance(0.4) ? $r->pick([-3, -1, 1, 2, 5]) : 0);
        $plan = ['kind' => $edit, 'from' => $from, 'at' => $at, 'days' => $days, 'hours' => $hours];
        if ($edit === 'time_all') {
            $plan['days'] = 0;
            $plan['hours'] = $allDay ? 0 : $r->pick([-3, -1, 1, 2, 5]);
            if ($allDay) {
                $plan['kind'] = 'drag_all'; // an all-day series has no time to change
            }
        } elseif ($edit === 'allday_all') {
            $plan['hours'] = 0;
            $plan['days'] = $r->chance(0.5) ? 0 : $plan['days'];
            $plan['tzid'] = $allDay ? $r->pick(array_values(array_diff(self::ZONES, ['UTC']))) : null;
        } elseif ($edit === 'zone_all') {
            if ($allDay) {
                $plan['kind'] = 'drag_all';
            } else {
                $plan['days'] = 0;
                $plan['hours'] = 0;
                $plan['tzid'] = $r->pick(array_values(array_diff(self::ZONES, ['UTC', $series['tzid']])));
            }
        } elseif ($edit === 'rule_all') {
            $plan['days'] = 0;
            $plan['hours'] = 0;
            $plan['rrule'] = self::otherRule($r, $series['rrule']);
        } elseif ($edit === 'rename_following' || $edit === 'delete_following' || $edit === 'delete_this' || $edit === 'attend_following' || $edit === 'rename_all' || $edit === 'attend_all') {
            $plan['days'] = 0;
            $plan['hours'] = 0;
        } elseif ($edit === 'bound_all') {
            $plan['days'] = 0;
            $plan['hours'] = 0;
            $plan['rrule'] = self::otherBound($r, $series['rrule'], $raw, $allDay);
        }
        $stack = null;
        if ($r->chance(0.4)) {
            $stack = ['kind' => $r->pick(['time_all', 'rename_all', 'delete_this', 'drag_all']), 'days' => $r->pick([-1, 1, 2, 7]), 'hours' => $allDay ? 0 : $r->pick([-1, 1, 2])];
            if ($allDay && $stack['kind'] === 'time_all') {
                $stack['kind'] = 'rename_all';
            }
        }
        return ['exceptions' => $exceptions, 'edit' => $plan, 'stack' => $stack];
    }

    /**
     * The same pattern with another bound (the editor's Ends changed): a
     * count a little shorter or longer, an end on one of its days, or none.
     *
     * @param list<array{key:string,day:string}> $raw
     */
    private static function otherBound(Rng $r, string $rrule, array $raw, bool $allDay): string
    {
        $pattern = Recurrence::pattern($rrule);
        $parts = RepeatParser::ruleParts($rrule);
        $n = count($raw);
        $pick = $r->int(0, 2);
        if ($pick === 0 && $n > 2) {
            $at = $raw[$r->int(1, min($n - 1, 30))];
            return $pattern . ';UNTIL=' . ($allDay ? str_replace('-', '', $at['day']) : Time::fromDb($at['key'])->format('Ymd\THis\Z'));
        }
        if ($pick === 1 && (isset($parts['COUNT']) || isset($parts['UNTIL']))) {
            return $pattern;
        }
        $count = isset($parts['COUNT']) ? max(2, (int) $parts['COUNT'] + $r->pick([-3, -2, -1, 1, 2, 4])) : $r->int(3, 12);
        return $pattern . ';COUNT=' . $count;
    }

    /** A rule with another pattern than $rrule (the editor's "repeat" changed), keeping its bound. */
    private static function otherRule(Rng $r, string $rrule): string
    {
        $parts = RepeatParser::ruleParts($rrule);
        $bound = array_intersect_key($parts, ['COUNT' => 1, 'UNTIL' => 1]);
        $base = $r->pick(['FREQ=WEEKLY;BYDAY=' . $r->pick(Families::WEEK), 'FREQ=MONTHLY;BYMONTHDAY=' . $r->int(1, 28), 'FREQ=DAILY;INTERVAL=2', 'FREQ=WEEKLY;BYDAY=TU,TH']);
        foreach ($bound as $k => $v) {
            $base .= ";$k=$v";
        }
        return $base;
    }
}

/**
 * Runs one case against the database through Events and Undo and checks
 * the invariants, returning failure records (empty when clean).
 */
final class Driver
{
    private array $records = [];
    private array $spec = [];
    private ?Rng $rng = null;

    public function __construct(
        private readonly Db $db,
        private readonly Events $events,
        private readonly Undo $undo,
        private readonly int $userId,
        private readonly int $calendarId,
    ) {
    }

    /**
     * Run a case: a spec from Generator::series (the plan is made here when
     * it has none) or a complete one (a shrunk reproduction).
     *
     * @return array{spec:array,records:list<array>}
     */
    public function run(array $spec, Rng $r, ?string $onlyEdit = null): array
    {
        $this->records = [];
        $this->spec = $spec;
        $this->rng = $r;
        $series = $spec['series'];
        $firstId = (int) $this->db->scalar('SELECT COALESCE(MAX(id), 0) + 1 FROM events');
        $id = $this->createSeries($series);
        $master = $this->row($id);
        $raw = Oracle::raw($master);
        $this->check('0-created', $raw !== [] && $raw[0]['key'] === (string) $master['start_utc'], 'the stored start is the first raw occurrence', ['start' => $master['start_utc'], 'first' => $raw[0]['key'] ?? null]);
        if (true) {
            // create() aligns a start off its pattern, for every family (series-model.md, Creating a Series).
            $this->check('0-aligned', RepeatParser::matches(RepeatParser::ruleParts((string) $master['rrule']), Time::fromDb((string) $master['start_utc'])->setTimezone(Time::zone((string) $master['tzid']))), 'the stored start is a day of its rule', ['start' => $master['start_utc'], 'rrule' => $master['rrule']]);
        }
        if (!isset($spec['edit'])) {
            // Only days the app lets anyone edit: within its horizon (about 20 years from now).
            $horizon = Time::toDb(Time::nowUtc()->modify('+19 years'));
            $within = array_values(array_filter($raw, static fn(array $o): bool => $o['key'] < $horizon));
            $spec = array_merge($spec, Generator::plan($r, $series, $within, $onlyEdit));
            $this->spec = $spec;
        }
        if ($raw === []) {
            return ['spec' => $spec, 'records' => $this->records];
        }
        try {
            $this->applyExceptions($id, $master, $raw, $spec['exceptions']);
        } catch (HttpError $e) {
            $this->check('0-exceptions', false, 'applying an exception was refused', ['error' => $e->errorCode, 'message' => $e->getMessage()]);
            return ['spec' => $spec, 'records' => $this->records];
        }
        $before = $this->model($id, $firstId);
        $s0 = $this->snapshot($firstId);
        $edit = $spec['edit'];
        try {
            $payload = $this->applyEdit($id, $before, $edit);
        } catch (HttpError $e) {
            if ($e->errorCode === 'series_refused') {
                // A refusal the plan names (a month list crossing the year, a day a drag can't follow): nothing written.
                $this->same('R-refused', $s0, $this->snapshot($firstId), 'a refused edit changes nothing');
                return ['spec' => $spec, 'records' => $this->records];
            }
            $this->check('api-refused', false, 'the edit was refused: ' . $e->errorCode, ['message' => $e->getMessage(), 'edit' => $edit]);
            return ['spec' => $spec, 'records' => $this->records];
        } catch (\Throwable $e) {
            // An error the request would answer with a 500: the transaction rolled back.
            $this->check('api-crash', false, 'the edit failed with ' . get_class($e), ['message' => mb_substr($e->getMessage(), 0, 200), 'edit' => $edit]);
            return ['spec' => $spec, 'records' => $this->records];
        }
        $this->spec['payload'] = $payload;
        $after = $this->model($id, $firstId);
        $s1 = $this->snapshot($firstId);
        $this->checkEdit($before, $after, $edit, $payload);
        // Undo: every row as it was.
        if ($this->undoLatest('6-undo', 'undo')) {
            $this->same('6-undo', $s0, $this->snapshot($firstId), 'after undo every row is as before the edit');
            // Redo: the same edit again gives the same state (new rows under new ids).
            try {
                $this->applyEdit($id, $before, $edit);
                $s1r = $this->snapshot($firstId);
                $this->same('6-redo', self::roles($s1, $id), self::roles($s1r, $id), 'the edit applied again gives the state it gave before');
                $live = !empty($spec['stack']) ? $this->liveMaster($id, $firstId) : null;
                if ($live !== null) {
                    try {
                        if ($this->applyStack($live, $firstId, $spec['stack']) && $this->undoLatest('6-stack', 'undo of the second edit')) {
                            $this->same('6-stack', $s1r, $this->snapshot($firstId), 'undoing the second edit gives the state after the first');
                            if ($this->undoLatest('6-stack', 'undo of the first edit under the second')) {
                                $this->same('6-stack', $s0, $this->snapshot($firstId), 'undoing both gives the state before either');
                            }
                        }
                    } catch (HttpError $e) {
                        if ($e->errorCode !== 'series_refused') {
                            $this->check('6-stack', false, 'the second edit failed: ' . $e->errorCode, ['message' => mb_substr($e->getMessage(), 0, 200), 'stack' => $spec['stack']]);
                        }
                    } catch (\Throwable $e) {
                        $this->check('6-stack', false, 'the second edit failed: ' . ($e instanceof HttpError ? $e->errorCode : get_class($e)), ['message' => mb_substr($e->getMessage(), 0, 200), 'stack' => $spec['stack']]);
                    }
                }
            } catch (\Throwable $e) {
                $this->check('6-redo', false, 'the edit applied again failed: ' . ($e instanceof HttpError ? $e->errorCode : get_class($e)), ['message' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }
        return ['spec' => $this->spec, 'records' => $this->records];
    }

    // --- building the case -------------------------------------------------

    private function createSeries(array $s): int
    {
        $tz = Time::zone($s['tzid']);
        $in = ['calendarId' => $this->calendarId, 'title' => 'Sample series', 'location' => 'Sample Hall', 'allDay' => $s['allDay'], 'tzid' => $s['tzid'], 'tagNames' => ['sample'], 'personNames' => ['Alex Example']];
        $rrule = $s['rrule'];
        if ($s['allDay']) {
            $in['start'] = $s['start'];
            $in['end'] = Oracle::addDays($s['start'], $s['length']);
        } else {
            $start = new \DateTimeImmutable($s['start'], $tz);
            $in['start'] = Time::iso($start);
            $in['end'] = Time::iso($start->modify('+' . $s['length'] . ' minutes'));
        }
        if ($s['bound'] === 'count') {
            $rrule .= ';COUNT=' . $s['boundAt'];
        } elseif ($s['bound'] === 'until') {
            // The last occurrence's day, read from the rule as create() will store it (aligned start).
            $probe = ['start_utc' => $s['allDay'] ? $s['start'] . ' 00:00:00' : Time::toDb(new \DateTimeImmutable($s['start'], $tz)), 'tzid' => $s['tzid'], 'all_day' => $s['allDay'] ? 1 : 0, 'rrule' => $rrule];
            $probe['end_utc'] = $probe['start_utc'];
            $aligned = self::alignedForProbe($probe, $s['family']);
            $occ = Oracle::raw($aligned, $s['boundAt'] + 1);
            $last = $occ[min($s['boundAt'], count($occ) - 1)];
            if ($s['allDay']) {
                $rrule .= ';UNTIL=' . str_replace('-', '', $last['day']);
            } elseif ($s['untilForm'] === 'exact') {
                $rrule .= ';UNTIL=' . Time::fromDb($last['key'])->format('Ymd\THis\Z');
            } else {
                $rrule .= ';UNTIL=' . (new \DateTimeImmutable($last['day'] . ' 23:59:59', $tz))->setTimezone(Time::utc())->format('Ymd\THis\Z');
            }
        }
        $in['rrule'] = $rrule;
        $this->spec['create'] = $in;
        return (int) $this->events->create($this->userId, $in)['eventId'];
    }

    /** What create() does to a start off its pattern, for the probe that picks an UNTIL. */
    private static function alignedForProbe(array $row, string $family): array
    {
        if (in_array($family, ['daily', 'weekly_plain', 'monthly_plain', 'yearly_plain'], true)) {
            return $row;
        }
        $tz = Time::zone((string) $row['tzid']);
        $start = Time::fromDb((string) $row['start_utc'])->setTimezone((int) $row['all_day'] === 1 ? Time::utc() : $tz);
        $first = RepeatParser::firstOn(RepeatParser::ruleParts((string) $row['rrule']), $start);
        $row['start_utc'] = Time::toDb($first);
        $row['end_utc'] = $row['start_utc'];
        return $row;
    }

    private function instanceArg(array $master, string $key): string
    {
        if ((int) $master['all_day'] === 1) {
            return substr($key, 0, 10);
        }
        return Time::iso(Time::fromDb($key)->setTimezone(Time::zone((string) $master['tzid'])));
    }

    /** @param list<array{key:string,day:string,local:\DateTimeImmutable}> $raw */
    private function applyExceptions(int $id, array $master, array $raw, array $exceptions): void
    {
        $allDay = (int) $master['all_day'] === 1;
        $tz = Time::zone((string) $master['tzid']);
        $length = Recurrence::durationSeconds($master);
        foreach ($exceptions as $i => $ex) {
            $occ = $raw[$ex['at']] ?? null;
            if ($occ === null) {
                continue;
            }
            $inst = $this->instanceArg($master, $occ['key']);
            if ($ex['kind'] === 'skip') {
                $this->events->deleteEvent($this->userId, $id, 'this', $inst);
                continue;
            }
            $in = ['scope' => 'this', 'instanceStart' => $inst, 'title' => 'Sample series, day ' . $ex['at']];
            if ($i === 0) {
                $in['tagNames'] = ['sample', 'extra'];
            }
            $start = Time::fromDb($occ['key']);
            if ($ex['kind'] === 'time') {
                $s = $start->setTimezone($tz)->modify(($ex['hours'] >= 0 ? '+' : '') . $ex['hours'] . ' hours');
                $in['start'] = Time::iso($s);
                $in['end'] = Time::iso($s->modify('+' . $length . ' seconds'));
            } elseif ($ex['kind'] === 'day') {
                if ($allDay) {
                    $in['start'] = Oracle::addDays($occ['day'], $ex['days']);
                    $in['end'] = Oracle::addDays($occ['day'], $ex['days'] + max(1, (int) round($length / 86400)));
                } else {
                    $s = $start->setTimezone($tz)->modify(($ex['days'] >= 0 ? '+' : '') . $ex['days'] . ' days');
                    $in['start'] = Time::iso($s);
                    $in['end'] = Time::iso($s->modify('+' . $length . ' seconds'));
                }
            } elseif ($ex['kind'] === 'zone') {
                // The same wall-clock reading in another zone: another instant.
                $other = (string) $master['tzid'] === 'Asia/Tokyo' ? 'America/New_York' : 'Asia/Tokyo';
                $s = new \DateTimeImmutable($start->setTimezone($tz)->format('Y-m-d H:i:s'), Time::zone($other));
                $in['tzid'] = $other;
                $in['start'] = Time::iso($s);
                $in['end'] = Time::iso($s->modify('+' . $length . ' seconds'));
            }
            $this->events->patch($this->userId, $id, $in);
        }
    }

    // --- the state model -------------------------------------------------

    private function row(int $id): array
    {
        return (array) $this->db->one('SELECT * FROM events WHERE id = ?', [$id]);
    }

    /** The series as rows: master, overrides, exdates, raw days; plus any new master made in this case. */
    private function model(int $id, int $firstId): array
    {
        $master = $this->row($id);
        // 'through': how far 'raw' holds every occurrence (null: the whole series).
        $seen = $master['rrule'] !== null && $master['deleted_at'] === null ? Oracle::expansion($master) : ['occ' => [], 'through' => null];
        $m = [
            'master' => $master,
            'overrides' => $this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY recurrence_instance_utc', [$id]),
            'exdates' => Recurrence::decodeExdates($master['exdates_json'] ?? null),
            'raw' => $seen['occ'],
            'through' => $seen['through'],
            'new' => null,
        ];
        $new = $this->db->one('SELECT * FROM events WHERE user_id = ? AND id >= ? AND id <> ? AND recurrence_parent_id IS NULL AND rrule IS NOT NULL AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$this->userId, $firstId, $id]);
        if ($new !== null) {
            $seenNew = Oracle::expansion($new);
            $m['new'] = [
                'master' => $new,
                'overrides' => $this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY recurrence_instance_utc', [(int) $new['id']]),
                'exdates' => Recurrence::decodeExdates($new['exdates_json'] ?? null),
                'raw' => $seenNew['occ'],
                'through' => $seenNew['through'],
            ];
        }
        return $m;
    }

    /** The series to stack a second edit on: the original, or the one a split made; null when none is live. */
    private function liveMaster(int $id, int $firstId): ?int
    {
        $m = $this->row($id);
        if ($m['deleted_at'] === null && Oracle::raw($m) !== []) {
            return $id;
        }
        $other = $this->db->scalar('SELECT id FROM events WHERE user_id = ? AND id >= ? AND recurrence_parent_id IS NULL AND rrule IS NOT NULL AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$this->userId, $firstId]);
        return $other === null ? null : (int) $other;
    }

    /** Every row this case made, link rows included, without the updated timestamps. */
    private function snapshot(int $firstId): array
    {
        $events = $this->db->all('SELECT * FROM events WHERE user_id = ? AND id >= ? ORDER BY id', [$this->userId, $firstId]);
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $events);
        $out = ['events' => [], 'event_tags' => [], 'event_people' => [], 'event_links' => []];
        foreach ($events as $r) {
            unset($r['updated_at']);
            $out['events'][] = $r;
        }
        if ($ids !== []) {
            [$in, $params] = Db::in($ids);
            $out['event_tags'] = $this->db->all("SELECT * FROM event_tags WHERE event_id IN $in ORDER BY event_id, tag_id", $params);
            $out['event_people'] = $this->db->all("SELECT * FROM event_people WHERE event_id IN $in ORDER BY event_id, person_id", $params);
            $out['event_links'] = $this->db->all("SELECT * FROM event_links WHERE event_id IN $in OR container_id IN $in ORDER BY id", array_merge($params, $params));
        }
        return $out;
    }

    /** A snapshot with ids replaced by roles, so a redo's new rows compare with the first run's. */
    private static function roles(array $snap, int $masterId): array
    {
        $role = [];
        foreach ($snap['events'] as $r) {
            $id = (int) $r['id'];
            if ($id === $masterId) {
                $role[$id] = 'M';
            } elseif ($r['recurrence_parent_id'] === null && $r['rrule'] !== null) {
                $role[$id] = 'N';
            } elseif ($r['recurrence_parent_id'] === null) {
                $role[$id] = 'S:' . $r['start_utc'];
            }
        }
        foreach ($snap['events'] as $r) {
            $id = (int) $r['id'];
            if (!isset($role[$id])) {
                $parent = (int) $r['recurrence_parent_id'];
                $role[$id] = ($role[$parent] ?? 'X') . ':' . ($r['recurrence_instance_utc'] ?? '');
            }
        }
        $events = [];
        foreach ($snap['events'] as $r) {
            $key = $role[(int) $r['id']];
            unset($r['id'], $r['uid'], $r['created_at'], $r['updated_at'], $r['recurrence_parent_id']);
            $r['deleted_at'] = $r['deleted_at'] === null ? null : 'deleted';
            $events[$key] = $r;
        }
        ksort($events);
        $links = [];
        foreach (['event_tags', 'event_people'] as $t) {
            $rows = [];
            foreach ($snap[$t] as $r) {
                $r['event_id'] = $role[(int) $r['event_id']] ?? 'X';
                $rows[] = $r;
            }
            sort($rows);
            $links[$t] = $rows;
        }
        return ['events' => $events] + $links;
    }

    // --- the edits -----------------------------------------------------------

    /**
     * Apply the edit as the client would send it, returning the payload.
     *
     * @throws HttpError
     */
    private function applyEdit(int $id, array $m, array $edit, string $slot = 'edit'): array
    {
        $master = $m['master'];
        $allDay = (int) $master['all_day'] === 1;
        $tz = Time::zone((string) $master['tzid']);
        $occ = $m['raw'][$edit['at']] ?? $m['raw'][0];
        $inst = $this->instanceArg($master, $occ['key']);
        $length = Recurrence::durationSeconds($master);
        $kind = $edit['kind'];
        $days = (int) ($edit['days'] ?? 0);
        $hours = (int) ($edit['hours'] ?? 0);
        $scope = str_ends_with($kind, '_following') ? 'following' : 'all';
        $in = ['scope' => $scope, 'instanceStart' => $inst];
        $times = function () use ($allDay, $occ, $tz, $days, $hours, $length): array {
            if ($allDay) {
                $day = Oracle::addDays($occ['day'], $days);
                return ['start' => $day, 'end' => Oracle::addDays($day, max(1, (int) round($length / 86400)))];
            }
            $s = Time::fromDb($occ['key'])->setTimezone($tz)->modify(($days >= 0 ? '+' : '') . $days . ' days')->modify(($hours >= 0 ? '+' : '') . $hours . ' hours');
            return ['start' => Time::iso($s), 'end' => Time::iso($s->modify('+' . $length . ' seconds'))];
        };
        switch ($kind) {
            case 'drag_all':
            case 'drag_following':
            case 'time_all':
                $in += $times();
                break;
            case 'editor_drag_all':
            case 'editor_drag_following':
                // The editor sends the rule it seeded from with every save, and the
                // title its form shows: the day's own when that day was edited.
                $shown = $master;
                foreach ($m['overrides'] as $ov) {
                    if ((string) $ov['recurrence_instance_utc'] === $occ['key']) {
                        $shown = $ov;
                    }
                }
                $in += $times() + ['rrule' => (string) $master['rrule'], 'title' => (string) $shown['title'], 'location' => (string) $shown['location']];
                break;
            case 'allday_all':
                if ($allDay) {
                    $day = Oracle::addDays($occ['day'], $days);
                    $z = Time::zone((string) $edit['tzid']);
                    $s = new \DateTimeImmutable($day . ' 10:00:00', $z);
                    $in += ['allDay' => false, 'tzid' => (string) $edit['tzid'], 'start' => Time::iso($s), 'end' => Time::iso($s->modify('+1 hour'))];
                } else {
                    $day = Oracle::addDays($occ['day'], $days);
                    $in += ['allDay' => true, 'start' => $day, 'end' => Oracle::addDays($day, 1)];
                }
                break;
            case 'zone_all':
                $z = Time::zone((string) $edit['tzid']);
                $s = new \DateTimeImmutable(Time::fromDb($occ['key'])->setTimezone($tz)->format('Y-m-d H:i:s'), $z);
                $in += ['tzid' => (string) $edit['tzid'], 'start' => Time::iso($s), 'end' => Time::iso($s->modify('+' . $length . ' seconds'))];
                break;
            case 'rule_all':
                $in += ['rrule' => (string) $edit['rrule'], 'title' => 'Sample series, new days'];
                break;
            case 'rename_following':
                $in += ['title' => 'Sample series, from here on', 'location' => 'Sample Annex'];
                break;
            case 'rename_all':
                $in += ['title' => 'Sample series, renamed'];
                break;
            case 'bound_all':
                // The editor sends the rule with its new end.
                $in += ['rrule' => (string) $edit['rrule']];
                break;
            case 'attend_all':
                $this->events->setAttendance($this->userId, $id, 'going', 'all', $inst);
                return ['attendance' => 'going', 'scope' => 'all', 'instanceStart' => $inst];
            case 'delete_following':
                $this->events->deleteEvent($this->userId, $id, 'following', $inst);
                return ['delete' => 'following', 'instanceStart' => $inst];
            case 'delete_this':
                $this->events->deleteEvent($this->userId, $id, 'this', $inst);
                return ['delete' => 'this', 'instanceStart' => $inst];
            case 'attend_following':
                $this->events->setAttendance($this->userId, $id, 'going', 'following', $inst);
                return ['attendance' => 'going', 'scope' => 'following', 'instanceStart' => $inst];
        }
        if ($scope === 'all') {
            return $this->commit($id, $in, $slot);
        }
        $this->events->patch($this->userId, $id, $in);
        return $in;
    }

    /**
     * A whole-series edit as the client makes it (series-model.md, The
     * API): the plan previewed, one option picked per group (the preselected
     * one in half the cases, any enabled one otherwise, the same again when
     * the case is replayed), and committed with its hash. What Google would
     * be offered for the same edit, from the same rows, is worked out before
     * the commit (invariant 8). The payload carries the plan, the choices
     * and the Google plan.
     */
    private function commit(int $id, array $in, string $slot): array
    {
        $choices = $this->spec['choices'][$slot] ?? null;
        $plan = $this->events->previewPatch($this->userId, $id, $in + ['plan' => ['choices' => $choices ?? []]]);
        if ($choices === null) {
            $choices = [];
            $preselected = $this->rng === null || $this->rng->chance(0.5);
            for ($round = 0; $round < 5; $round++) {
                $added = false;
                foreach ($plan['groups'] as $g) {
                    if (isset($choices[$g['id']])) {
                        continue;
                    }
                    $enabled = array_values(array_map(static fn(array $o): string => $o['id'], array_filter($g['options'], static fn(array $o): bool => empty($o['disabled']))));
                    $choices[$g['id']] = $preselected || $this->rng === null ? $g['selected'] : $this->rng->pick($enabled);
                    $added = true;
                }
                if (!$added) {
                    break;
                }
                $plan = $this->events->previewPatch($this->userId, $id, $in + ['plan' => ['choices' => $choices]]);
            }
            $this->spec['choices'][$slot] = $choices;
        }
        foreach ($plan['groups'] as $g) {
            $choices[$g['id']] ??= $g['selected'];
        }
        $google = null;
        try {
            $input = new \ReflectionMethod($this->events, 'seriesInput');
            $event = $this->row($id);
            [$gm, $grows, $gedit, $gopts] = $input->invoke($this->events, $this->userId, $event, $in, false, true);
            $google = \BetterCal\Domain\Series\Model::plan($gm, $grows, $gedit, $choices, $gopts);
        } catch (\Throwable $e) {
            $google = ['error' => $e->getMessage()];
        }
        $this->events->patch($this->userId, $id, $in + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices]]);
        return $in + ['_plan' => $plan, '_choices' => $choices, '_google' => $google];
    }

    /** True when a second edit was made (a series with no day left to edit gets none). */
    private function applyStack(int $id, int $firstId, array $stack): bool
    {
        $m = $this->model($id, $firstId);
        if ($m['raw'] === []) {
            return false;
        }
        $master = $m['master'];
        $skipped = array_fill_keys($m['exdates'], true);
        $occ = null;
        $at = 0;
        foreach ($m['raw'] as $i => $o) {
            if (!isset($skipped[$o['key']])) {
                $occ = $o;
                $at = $i;
                break;
            }
        }
        if ($occ === null) {
            return false;
        }
        $inst = $this->instanceArg($master, $occ['key']);
        switch ($stack['kind']) {
            case 'rename_all':
                $this->commit($id, ['scope' => 'all', 'instanceStart' => $inst, 'title' => 'Sample series, renamed'], 'stack');
                return true;
            case 'delete_this':
                $this->events->deleteEvent($this->userId, $id, 'this', $inst);
                return true;
            default:
                $edit = ['kind' => $stack['kind'] === 'time_all' ? 'time_all' : 'drag_all', 'at' => $at, 'days' => $stack['kind'] === 'time_all' ? 0 : $stack['days'], 'hours' => $stack['hours']];
                $this->applyEdit($id, $m, $edit, 'stack');
                return true;
        }
    }

    /**
     * The expander (sabre/vobject 4.6.1) yields a monthly rule's UNTIL as
     * an occurrence when it falls on the first of a month at the series'
     * time (a date for an all-day series). Recorded as its own invariant
     * and taken out of the list, so the other checks see the rule's days.
     *
     * @param list<array{key:string,day:string,local:\DateTimeImmutable}> $raw
     */
    private function withoutPhantom(string $inv, array $master, array $raw): array
    {
        if ($raw === [] || !str_contains((string) $master['rrule'], 'UNTIL=') || !str_starts_with((string) $master['rrule'], 'FREQ=MONTHLY')) {
            return $raw;
        }
        $last = end($raw);
        if (RepeatParser::matches(RepeatParser::ruleParts((string) $master['rrule']), $last['local'])) {
            return $raw;
        }
        $this->check($inv . '-phantom', false, 'the expander yields the UNTIL as an occurrence (no day of the rule)', ['occurrence' => $last['key'], 'rrule' => $master['rrule'], 'start' => $master['start_utc']]);
        array_pop($raw);
        return $raw;
    }

    /**
     * Undo the last change and record a refusal (under $inv) or an error the
     * request would answer with a 500 (under $inv-crash). True when it went through.
     */
    private function undoLatest(string $inv, string $what): bool
    {
        try {
            $this->undo->undoLatest($this->userId);
            return true;
        } catch (HttpError $e) {
            $this->check($inv, false, $what . ' refused: ' . $e->errorCode, ['message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->check($inv . '-crash', false, $what . ' failed with ' . get_class($e), ['message' => mb_substr($e->getMessage(), 0, 200)]);
        }
        return false;
    }

    // --- the invariants ------------------------------------------------------

    private function check(string $invariant, bool $ok, string $what, array $detail = []): void
    {
        if ($ok) {
            return;
        }
        $this->records[] = ['invariant' => $invariant, 'what' => $what, 'detail' => $detail];
    }

    private function same(string $invariant, array $expected, array $actual, string $what): void
    {
        if ($expected === $actual) {
            return;
        }
        $this->records[] = ['invariant' => $invariant, 'what' => $what, 'detail' => ['diff' => self::diff($expected, $actual)]];
    }

    /** The first few rows that differ, for the report. */
    private static function diff(array $a, array $b): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $table) {
            $ra = $a[$table] ?? [];
            $rb = $b[$table] ?? [];
            if ($ra === $rb) {
                continue;
            }
            $ja = array_map('json_encode', $ra);
            $jb = array_map('json_encode', $rb);
            $out[$table] = ['before' => array_values(array_diff($ja, $jb)), 'after' => array_values(array_diff($jb, $ja))];
            foreach ($out[$table] as $k => $rows) {
                $out[$table][$k] = array_slice($rows, 0, 4);
            }
        }
        return $out;
    }

    private function checkEdit(array $before, array $after, array $edit, array $payload): void
    {
        $kind = $edit['kind'];
        if ($kind === 'attend_all') {
            $this->checkAttendAll($before, $after);
            return;
        }
        if (isset($payload['delete'])) {
            $this->checkDelete($before, $after, $edit);
            return;
        }
        if ($kind === 'attend_following') {
            $this->checkFollowing($before, $after, $edit, $payload);
            return;
        }
        if (str_ends_with($kind, '_following')) {
            $this->checkFollowing($before, $after, $edit, $payload);
            return;
        }
        $this->checkWhole($before, $after, $edit, $payload);
    }

    /** The family of the series as stored (the generator's name, or read from the rule). */
    private function family(): string
    {
        return $this->spec['series']['family'];
    }

    /** New time of day (H:i:s) and zone of a series after a whole-series edit, from its stored row. */
    private static function timeOf(array $master): string
    {
        return (int) $master['all_day'] === 1 ? '00:00:00' : Time::fromDb((string) $master['start_utc'])->setTimezone(Time::zone((string) $master['tzid']))->format('H:i:s');
    }

    /**
     * A whole-series edit, against the plan it committed (series-model.md,
     * What the Harness Proves) and against the harness's own reading of the
     * drag table where a family's images are plain: days moved by the drag
     * for daily and weekly rules (a list's moved member alone under "move
     * every Monday"), and for a rule with one day a period, the occurrence
     * of the period k periods on.
     */
    private function checkWhole(array $before, array $after, array $edit, array $payload): void
    {
        $family = $this->family();
        $m0 = $before['master'];
        $m1 = $after['master'];
        $plan = $payload['_plan'] ?? null;
        $choices = $payload['_choices'] ?? [];
        $newAllDay = (int) $m1['all_day'] === 1;
        $newTz = (string) $m1['tzid'];
        $oldAllDay = (int) $m0['all_day'] === 1;
        $after['raw'] = $this->withoutPhantom('1', $m1, $after['raw']);
        $this->check('0-created', $after['raw'] !== [] && $after['raw'][0]['key'] === (string) $m1['start_utc'], 'the stored start is still the first raw occurrence', ['start' => $m1['start_utc'], 'first' => $after['raw'][0]['key'] ?? null, 'rrule' => $m1['rrule']]);
        if (!is_array($plan)) {
            // "This and following" from the first day is the whole series,
            // committed with its preselected options (no dialog until 1.5.1).
            $this->checkAttached('2', $after);
            return;
        }
        // The commit wrote the plan.
        $this->check('P-commit', (string) $m1['rrule'] === (string) $plan['rule']['after'], 'the stored rule is the plan\'s', ['plan' => $plan['rule']['after'], 'stored' => $m1['rrule']]);
        $this->check('P-commit', Time::toDb(Time::parseIso((string) $plan['start'])) === (string) $m1['start_utc'], 'the stored start is the plan\'s', ['plan' => $plan['start'], 'stored' => $m1['start_utc']]);
        $toDb = static fn(?string $k): ?string => $k === null ? null : str_replace(['T', 'Z'], [' ', ''], $k);
        $wantSkips = [];
        $wantKeys = [];
        foreach ($plan['days'] as $day) {
            if ($day['kind'] === 'skipped' && $day['outcome'] !== 'dropped') {
                $wantSkips[] = $toDb($day['newKey']);
            }
            if ($day['kind'] === 'edited' && in_array($day['outcome'], ['moves', 'stays'], true)) {
                $wantKeys[] = $toDb($day['newKey']);
            }
        }
        sort($wantSkips);
        sort($wantKeys);
        $gotSkips = $after['exdates'];
        sort($gotSkips);
        $gotKeys = array_map(static fn(array $o): string => (string) $o['recurrence_instance_utc'], $after['overrides']);
        sort($gotKeys);
        $this->check('P-days', $wantSkips === $gotSkips, 'the skipped days are where the plan put them', ['plan' => $wantSkips, 'stored' => $gotSkips]);
        $this->check('P-days', $wantKeys === $gotKeys, 'the edited days are where the plan put them', ['plan' => $wantKeys, 'stored' => $gotKeys]);
        $time = self::timeOf($m1);
        if ($edit['kind'] === 'rule_all') {
            // Compared by days, not text: the model writes a rule in its
            // canonical form (a monthly day equal to the start's is bare).
            $sameText = Recurrence::pattern((string) $m1['rrule']) === Recurrence::pattern((string) $edit['rrule']);
            $daysOf = static fn(string $rrule): array => array_column(Oracle::raw(['rrule' => Recurrence::pattern($rrule)] + $m1), 'key');
            $this->check('1-rule', $sameText || $daysOf((string) $m1['rrule']) === $daysOf((string) $edit['rrule']), 'the sent rule is stored (its days, from the stored start)', ['sent' => $edit['rrule'], 'stored' => $m1['rrule']]);
            $this->check('1-rule', RepeatParser::matches(RepeatParser::ruleParts((string) $m1['rrule']), $after['raw'][0]['local'] ?? Time::nowUtc()), 'the start is a day of the new rule', ['start' => $m1['start_utc'], 'rrule' => $m1['rrule']]);
        } else {
            $occ = $before['raw'][$edit['at']] ?? $before['raw'][0];
            $landed = self::landedDay($payload, $occ['day'], $newTz, $newAllDay);
            $days = Oracle::daysBetween($occ['day'], $landed);
            // Time of day, zone, all-day as sent.
            $wantAllDay = array_key_exists('allDay', $payload) ? (bool) $payload['allDay'] : $oldAllDay;
            $this->check('1-allday', $newAllDay === $wantAllDay, 'the all-day flag is as sent', ['sent' => $wantAllDay, 'stored' => $newAllDay]);
            if (!$newAllDay && isset($payload['start']) && !isset($choices['zone'])) {
                $wantTime = Time::parseIso((string) $payload['start'], $newTz)->setTimezone(Time::zone($newTz))->format('H:i:s');
                $this->check('1-time', $time === $wantTime, 'the series runs at the time it was dragged to', ['sent' => $wantTime, 'stored' => $time, 'tzid' => $newTz]);
            }
            if (isset($payload['tzid'])) {
                $this->check('1-zone', $newTz === ($wantAllDay ? 'UTC' : Time::normalizeTzid((string) $payload['tzid'])), 'the zone is as sent', ['sent' => $payload['tzid'], 'stored' => $newTz]);
            }
            // The day acted on shows where it was dropped: an occurrence there, its own row there, or an event of its own.
            if (($choices['zone'] ?? 'clock') === 'clock') {
                $this->checkActed($after, $landed, $newTz, $newAllDay, $payload);
            }
            // UNTIL is an occurrence: the last one.
            $p1 = RepeatParser::ruleParts((string) $m1['rrule']);
            if (isset($p1['UNTIL']) && $after['raw'] !== []) {
                $last = end($after['raw']);
                $untilOk = strlen((string) $p1['UNTIL']) === 8 ? str_replace('-', '', $last['day']) === $p1['UNTIL'] : Time::fromDb($last['key'])->format('Ymd\THis\Z') === $p1['UNTIL'];
                $this->check('1-until', $untilOk || !in_array($edit['kind'], ['drag_all', 'time_all', 'allday_all', 'zone_all'], true), 'UNTIL is the last occurrence', ['until' => $p1['UNTIL'], 'last' => $last['key']]);
            }
            // Images by member, where the family's are plain.
            $this->checkImages($family, $before, $after, $occ['day'], $landed, $days, $choices, $time, $newTz, $newAllDay, (string) $occ['key']);
            // Invariant 8: Google until 1.5.2.
            $this->checkGooglePlan($m0, $plan, $payload['_google'] ?? null, $choices);
        }
        $this->checkAttached('2', $after);
        foreach (['title', 'location', 'description'] as $col) {
            $want = array_key_exists($col, $payload) ? $payload[$col] : $m0[$col];
            $this->check('1-fields', (string) $m1[$col] === (string) $want, "$col is as it should be", ['column' => $col, 'expected' => $want, 'stored' => $m1[$col]]);
        }
        $this->check('5-none', $after['new'] === null, 'a whole-series edit makes no new series', ['new' => $after['new']['master']['id'] ?? null]);
        if ($edit['kind'] === 'rename_all') {
            // Invariant 4 for a rename: a day that inherits its title takes the new one; one that owns it keeps it unless given it.
            $byId = [];
            foreach ($after['overrides'] as $ov) {
                $byId[(int) $ov['id']] = $ov;
            }
            foreach ($before['overrides'] as $ov0) {
                $ov1 = $byId[(int) $ov0['id']] ?? null;
                if ($ov1 === null) {
                    continue;
                }
                $owns = in_array('title', json_decode((string) ($ov0['override_fields'] ?? '[]'), true) ?: [], true) && (($choices['ownTitle'] ?? 'keep') === 'keep');
                $want = $owns ? (string) $ov0['title'] : (string) $payload['title'];
                $this->check('4-fields', (string) $ov1['title'] === $want, $owns ? 'an edited day keeps its own title' : 'an edited day that inherits its title takes the new one', ['expected' => $want, 'stored' => $ov1['title']]);
            }
        }
        if ($edit['kind'] === 'bound_all') {
            $sent = RepeatParser::ruleParts((string) $edit['rrule']);
            $stored = RepeatParser::ruleParts((string) $m1['rrule']);
            $this->check('1-bound', Recurrence::pattern((string) $m1['rrule']) === Recurrence::pattern((string) $m0['rrule']) && ($sent['COUNT'] ?? null) === ($stored['COUNT'] ?? null) && isset($sent['UNTIL']) === isset($stored['UNTIL']),
                'the pattern stays and the bound is the one sent', ['sent' => $edit['rrule'], 'stored' => $m1['rrule']]);
        }
    }

    /** Attendance for all events: the series and every attached edited day answer, none owning its answer (until 1.5.1 gives that its choice). */
    private function checkAttendAll(array $before, array $after): void
    {
        $this->check('4-fields', (string) $after['master']['attendance'] === 'going', 'the series answers', ['stored' => $after['master']['attendance']]);
        foreach ($after['overrides'] as $ov) {
            $own = json_decode((string) ($ov['override_fields'] ?? '[]'), true) ?: [];
            $this->check('4-fields', (string) $ov['attendance'] === 'going' && !in_array('attendance', $own, true), 'every edited day takes the series\' answer', ['stored' => $ov['attendance'], 'own' => $own]);
        }
        $this->checkAttached('2', $after);
        $this->check('5-none', $after['new'] === null, 'a whole-series answer makes no new series');
        unset($before);
    }

    /** The dragged day shows on the day it was dropped on (series-model.md, The Day Acted On Comes First). */
    private function checkActed(array $after, string $landed, string $tz, bool $allDay, array $payload): void
    {
        if (!isset($payload['start'])) {
            return;
        }
        $skips = array_flip($after['exdates']);
        foreach ($after['raw'] as $o) {
            if ($o['day'] === $landed && !isset($skips[$o['key']])) {
                return;
            }
        }
        foreach ($after['overrides'] as $ov) {
            // Shown there, or keyed there: a day on its own date dragged
            // from its occurrence moves by the drag's days from where it shows.
            if (Oracle::dayOf((string) $ov['start_utc'], (string) $ov['tzid'], (int) $ov['all_day'] === 1) === $landed
                || Oracle::dayOf((string) $ov['recurrence_instance_utc'], $tz, $allDay) === $landed) {
                return;
            }
        }
        $start = $allDay ? $landed . ' 00:00:00' : Time::toDb(Time::parseIso((string) $payload['start'], $tz));
        $own = $this->db->scalar('SELECT id FROM events WHERE user_id = ? AND recurrence_parent_id IS NULL AND rrule IS NULL AND start_utc = ? AND deleted_at IS NULL', [$this->userId, $start]);
        $this->check('1-acted', $own !== null, 'the dragged day shows where it was dropped', ['landed' => $landed, 'start' => $start]);
    }

    /**
     * Invariant 3 by the harness's own reading: each skipped and edited
     * day that stays in the series is the image of its occurrence. Daily
     * and weekly: its day moved by the drag (only the dragged weekday's
     * under "move every Monday"); a rule with one day a period: the
     * period's occurrence k periods on. Lists of month days and of months
     * are left to the plan's own checks.
     */
    private function checkImages(string $family, array $before, array $after, string $actedDay, string $landed, int $days, array $choices, string $time, string $newTz, bool $newAllDay, string $acted = ''): void
    {
        $m0 = $before['master'];
        $oldTz = (string) $m0['tzid'];
        $oldAllDay = (int) $m0['all_day'] === 1;
        $reading = $choices['reading'] ?? null;
        $unit = Families::unit($family);
        $single = in_array($family, ['monthly_plain', 'monthly_day', 'monthly_day_end', 'monthly_nth', 'monthly_nth_setpos', 'yearly_plain', 'yearly_day', 'yearly_nth'], true);
        if (!in_array($family, ['daily', 'weekly_plain', 'weekly_single', 'weekly_list'], true) && !$single) {
            return;
        }
        if (($choices['zone'] ?? 'clock') === 'moment') {
            return;
        }
        $actedWd = (new \DateTimeImmutable($actedDay, Time::utc()))->format('N');
        $newDays = [];
        foreach ($after['raw'] as $o) {
            $newDays[$unit === null ? $o['day'] : Oracle::periodOf($o['day'], $unit)] = $o['day'];
        }
        $k = $unit === null ? 0 : Oracle::periods($actedDay, $landed, $unit);
        $image = function (string $day) use ($family, $days, $reading, $actedWd, $single, $unit, $k, $newDays): ?string {
            if (!$single) {
                if ($family === 'weekly_list' && $reading === 'member' && (new \DateTimeImmutable($day, Time::utc()))->format('N') !== $actedWd) {
                    return $day;
                }
                return Oracle::addDays($day, $days);
            }
            return $newDays[Oracle::periodOf(Oracle::shiftPeriod($day, $k, (string) $unit), (string) $unit)] ?? null;
        };
        $through = $after['through'];
        foreach ($after['exdates'] as $ex) {
            unset($ex);
        }
        $afterById = [];
        foreach ($after['overrides'] as $ov) {
            $afterById[(int) $ov['id']] = $ov;
        }
        $wantSkips = [];
        foreach ($before['exdates'] as $ex) {
            $day = $image(Oracle::dayOf($ex, $oldTz, $oldAllDay));
            if ($day !== null) {
                $key = Oracle::keyAt($day, $time, $newTz, $newAllDay);
                if (in_array($key, array_column($after['raw'], 'key'), true) || ($through !== null && $key >= $through)) {
                    $wantSkips[] = $key;
                }
            }
        }
        $generated = array_values(array_diff($after['exdates'], $wantSkips));
        // A skip this change generated lies on a day the new rule produces
        // that is no old occurrence's image (a gain at an edge left out), and
        // no ordinary old occurrence's image is skipped (series-model.md,
        // Gains at the edges; the 1.5.0 review's phantom edge gains).
        $oldSkipped = array_flip($before['exdates']);
        $images = [];
        $ordinaryImages = [];
        foreach ($before['raw'] as $o) {
            $day = $o['key'] === $acted ? $landed : $image($o['day']);
            if ($day === null) {
                continue;
            }
            $key = Oracle::keyAt($day, $time, $newTz, $newAllDay);
            $images[$key] = true;
            if (!isset($oldSkipped[$o['key']])) {
                $ordinaryImages[$key] = true;
            }
        }
        $newKeys = array_flip(array_column($after['raw'], 'key'));
        $oldThrough = $before['through'];
        foreach ($generated as $g) {
            $this->check('3-generated', isset($newKeys[$g]) || ($through !== null && $g >= $through), 'a generated skip is a day the new rule produces', ['skip' => $g]);
            // Past the window the old series was read over, an image can't be ruled out.
            if ($oldThrough === null || $g < $oldThrough) {
                $this->check('3-generated', !isset($images[$g]), 'a generated skip is no old occurrence\'s image', ['skip' => $g, 'reading' => $reading]);
            }
        }
        foreach ($after['exdates'] as $ex) {
            if (isset($ordinaryImages[$ex]) && !in_array($ex, $wantSkips, true)) {
                $this->check('3-generated', false, 'an ordinary old occurrence\'s image is not skipped', ['skip' => $ex, 'reading' => $reading]);
            }
        }
        sort($wantSkips);
        $kept = array_values(array_intersect($after['exdates'], $wantSkips));
        sort($kept);
        $this->check('3-skips', $kept === $wantSkips, 'each skipped day still in the series is its occurrence\'s image', ['expected' => $wantSkips, 'stored' => $after['exdates']]);
        foreach ($before['overrides'] as $ov0) {
            $ov1 = $afterById[(int) $ov0['id']] ?? null;
            if ($ov1 === null) {
                continue; // an event of its own, or deleted: the plan says so (P-days)
            }
            $day = $image(Oracle::dayOf((string) $ov0['recurrence_instance_utc'], $oldTz, $oldAllDay));
            $wantKey = $day === null ? null : Oracle::keyAt($day, $time, $newTz, $newAllDay);
            $this->check('3-edited', $wantKey === (string) $ov1['recurrence_instance_utc'], 'an edited day is keyed on its occurrence\'s image', ['was' => $ov0['recurrence_instance_utc'], 'expected' => $wantKey, 'stored' => $ov1['recurrence_instance_utc'], 'reading' => $reading]);
            $this->checkOwn($ov0, $ov1, $choices, (string) $ov0['recurrence_instance_utc'] === $acted ? $days : null);
        }
    }

    /** Invariant 4: an edited day keeps what it owns; what it inherits follows the series. */
    /** $actedDays: the drag's days when this is the day acted on (it moves with the drag, its own date too). */
    private function checkOwn(array $ov0, array $ov1, array $choices, ?int $actedDays = null): void
    {
        $own = json_decode((string) ($ov0['override_fields'] ?? '[]'), true) ?: [];
        foreach (['title' => 'title', 'location' => 'location', 'description' => 'description', 'attendance' => 'attendance'] as $field => $col) {
            if (in_array($field, $own, true) && ($choices['own' . ucfirst($field)] ?? 'keep') === 'keep') {
                $this->check('4-fields', (string) $ov0[$col] === (string) $ov1[$col], "an edited day keeps its own $col", ['was' => $ov0[$col], 'now' => $ov1[$col]]);
            }
        }
        $tz0 = (int) $ov0['all_day'] === 1 ? 'UTC' : (string) $ov0['tzid'];
        $tz1 = (int) $ov1['all_day'] === 1 ? 'UTC' : (string) $ov1['tzid'];
        $l0 = Time::fromDb((string) $ov0['start_utc'])->setTimezone(Time::zone($tz0));
        $l1 = Time::fromDb((string) $ov1['start_utc'])->setTimezone(Time::zone($tz1));
        if (in_array('date', $own, true) && $actedDays !== null) {
            $this->check('4-moved', Oracle::addDays($l0->format('Y-m-d'), $actedDays) === $l1->format('Y-m-d'), 'the day acted on, on its own date, moves by the drag', ['was' => $ov0['start_utc'], 'now' => $ov1['start_utc'], 'days' => $actedDays]);
        } elseif (in_array('date', $own, true) && ($choices['movedDays'] ?? 'keep') === 'keep') {
            $this->check('4-moved', $l0->format('Y-m-d') === $l1->format('Y-m-d'), 'a day on its own date stays on it', ['was' => $ov0['start_utc'], 'now' => $ov1['start_utc']]);
        }
        if (in_array('time', $own, true) && !in_array('allDay', $own, true) && (int) $ov1['all_day'] === (int) $ov0['all_day']) {
            $this->check('4-moved', $l0->format('H:i:s') === $l1->format('H:i:s') && $tz0 === $tz1, 'a day at its own time keeps it', ['was' => [$ov0['start_utc'], $tz0], 'now' => [$ov1['start_utc'], $tz1]]);
        }
        if (!in_array('date', $own, true) && !in_array('time', $own, true) && !in_array('allDay', $own, true)) {
            $this->check('4-time', (string) $ov1['start_utc'] === (string) $ov1['recurrence_instance_utc'], 'a day that inherits its date and time is at its occurrence', ['start' => $ov1['start_utc'], 'key' => $ov1['recurrence_instance_utc']]);
        }
    }

    /**
     * Invariant 8 until 1.5.2: on a Google calendar only edits that keep
     * the rule text are offered; one that keeps it is offered with the
     * same start and rule as the local plan and no outcome for edited days.
     */
    private function checkGooglePlan(array $m0, array $plan, ?array $google, array $choices = []): void
    {
        if ($google === null || isset($google['error'])) {
            $this->check('8-google', false, 'the Google plan could not be worked out: ' . ($google['error'] ?? 'none'));
            return;
        }
        // A list's member reading keeps its text for whole weeks but isn't the whole-pattern shift Google gets.
        $keeps = Recurrence::pattern((string) $plan['rule']['after']) === Recurrence::pattern((string) $m0['rrule']) && ($choices['reading'] ?? '') !== 'member';
        if (!$keeps) {
            $this->check('8-google', $google['refused'] || $google['refusedChoice'] || ($google['plan']['refusals'] ?? []) !== [], 'a change to the rule text is not offered on a Google calendar', ['rule' => $plan['rule']['after']]);
            return;
        }
        $g = $google['plan'];
        $edited = array_filter($g['days'] ?? [], static fn(array $d): bool => $d['kind'] === 'edited');
        $this->check('8-google', !$google['refused'] && $g['rule']['after'] === $plan['rule']['after'] && $g['start'] === $plan['start'] && $edited === [],
            'an edit that keeps the rule text is offered on Google as the local plan, claiming nothing for edited days', ['google' => [$g['rule']['after'] ?? null, $g['start'] ?? null], 'local' => [$plan['rule']['after'], $plan['start']], 'edited' => count($edited)]);
    }

    /** The local day a sent start lands on. */
    private static function landedDay(array $payload, string $occDay, string $tzid, bool $allDay): string
    {
        if (!isset($payload['start'])) {
            return $occDay;
        }
        return $allDay ? substr((string) $payload['start'], 0, 10) : Time::parseIso((string) $payload['start'], $tzid)->setTimezone(Time::zone($tzid))->format('Y-m-d');
    }

    private static function bounded(string $rrule): bool
    {
        $p = RepeatParser::ruleParts($rrule);
        return isset($p['COUNT']) || isset($p['UNTIL']);
    }

    /** Invariant 1 in one place: the raw days against the expectation. An unbounded series compares as far as both lists go. */
    private function same1(string $family, array $expected, array $newDays, int $days, bool $bounded, array $detail): void
    {
        $follow = Families::FOLLOW[$family];
        if ($expected['dropped'] > 0) {
            $this->check('1-lead', false, 'the start snapped forward past ' . $expected['dropped'] . ' earlier day(s) of a rule that keeps its days', ['dropped' => $expected['dropped']] + $detail);
        }
        $want = $expected['days'];
        $got = $newDays;
        if ($follow === 'keep' || !$bounded) {
            $n = min(count($want), count($got));
            $want = array_slice($want, 0, $n);
            $got = array_slice($got, 0, $n);
        }
        $what = match ($follow) {
            'keep' => 'the days are unchanged',
            'shift' => 'the days moved by the drag',
            default => 'the days are the landed day in each period',
        };
        $this->check('1-days', $want === $got, $what, self::firstDiff($expected['days'], $newDays) + $detail);
    }

    private static function firstDiff(array $expected, array $actual): array
    {
        $n = max(count($expected), count($actual));
        for ($i = 0; $i < $n; $i++) {
            if (($expected[$i] ?? null) !== ($actual[$i] ?? null)) {
                return ['at' => $i, 'expected' => array_slice($expected, max(0, $i - 1), 4), 'actual' => array_slice($actual, max(0, $i - 1), 4), 'counts' => [count($expected), count($actual)]];
            }
        }
        return [];
    }

    /** Invariant 4: what an edited day was changed to stays changed. */
    private function checkDeviation(array $ov0, array $ov1, array $m0, array $m1): void
    {
        foreach (['title', 'location', 'description', 'attendance'] as $col) {
            $this->check('4-fields', (string) $ov0[$col] === (string) $ov1[$col], "an edited day keeps its $col", ['column' => $col, 'was' => $ov0[$col], 'now' => $ov1[$col], 'key' => $ov1['recurrence_instance_utc']]);
        }
        $unmoved = (string) $ov0['start_utc'] === (string) $ov0['recurrence_instance_utc'];
        if ($unmoved) {
            $this->check('4-time', (string) $ov1['start_utc'] === (string) $ov1['recurrence_instance_utc'], 'a day edited in place is still at its occurrence', ['start' => $ov1['start_utc'], 'key' => $ov1['recurrence_instance_utc']]);
            $this->check('4-time', (int) $ov1['all_day'] === (int) $m1['all_day'] && (string) $ov1['tzid'] === (string) $m1['tzid'], 'a day edited in place takes the series zone and all-day flag', ['all_day' => [$ov1['all_day'], $m1['all_day']], 'tzid' => [$ov1['tzid'], $m1['tzid']]]);
            if ((int) $ov0['all_day'] === (int) $ov1['all_day']) {
                $this->check('4-time', Recurrence::durationSeconds($ov0) === Recurrence::durationSeconds($ov1), 'a day edited in place keeps its length', ['was' => Recurrence::durationSeconds($ov0), 'now' => Recurrence::durationSeconds($ov1)]);
            }
            return;
        }
        // Moved to its own time or day: the same distance from its occurrence, in days, at its own time of day.
        $ovTz = Time::zone((string) $ov0['tzid']);
        $by = (int) (new \DateTimeImmutable(Oracle::dayOf((string) $ov0['recurrence_instance_utc'], (string) $m0['tzid'], (int) $m0['all_day'] === 1), Time::utc()))
            ->diff(new \DateTimeImmutable(Oracle::dayOf((string) $ov1['recurrence_instance_utc'], (string) $m1['tzid'], (int) $m1['all_day'] === 1), Time::utc()))->format('%r%a');
        $ovAllDay = (int) $ov0['all_day'] === 1;
        $wantStart = $ovAllDay
            ? Oracle::addDays(substr((string) $ov0['start_utc'], 0, 10), $by) . ' 00:00:00'
            : Time::toDb(Time::fromDb((string) $ov0['start_utc'])->setTimezone($ovTz)->modify(($by >= 0 ? '+' : '') . $by . ' days'));
        $this->check('4-moved', $wantStart === (string) $ov1['start_utc'], 'a day moved to its own time keeps that time and its distance from its occurrence', ['was' => $ov0['start_utc'], 'expected' => $wantStart, 'stored' => $ov1['start_utc'], 'by days' => $by, 'key' => [$ov0['recurrence_instance_utc'], $ov1['recurrence_instance_utc']]]);
        $this->check('4-moved', (string) $ov0['tzid'] === (string) $ov1['tzid'] && (int) $ov0['all_day'] === (int) $ov1['all_day'], 'a day moved to its own time keeps its zone and all-day flag', ['tzid' => [$ov0['tzid'], $ov1['tzid']], 'all_day' => [$ov0['all_day'], $ov1['all_day']]]);
        $this->check('4-moved', Recurrence::durationSeconds($ov0) === Recurrence::durationSeconds($ov1), 'a day moved to its own time keeps its length', ['was' => Recurrence::durationSeconds($ov0), 'now' => Recurrence::durationSeconds($ov1)]);
    }

    /** Invariant 2: every override key and EXDATE is a raw day of its rule, on the master and on a new master. */
    private function checkAttached(string $inv, array $after): void
    {
        foreach ([$after, $after['new']] as $part) {
            if ($part === null || $part['master']['deleted_at'] !== null || $part['master']['rrule'] === null) {
                continue;
            }
            $keys = array_fill_keys(array_column($part['raw'], 'key'), true);
            // A day the observed list does not reach is looked for where it is.
            $through = $part['through'] ?? null;
            $master = $part['master'];
            $member = static fn(string $k): bool => isset($keys[$k]) || ($through !== null && $k >= $through && Oracle::isOccurrence($master, $k));
            // A key past a bounded series' last day is its own kind of detachment.
            $last = $part['raw'] === [] ? '' : end($part['raw'])['key'];
            $pastEnd = static fn(string $k): bool => $last !== '' && $k > $last && self::bounded((string) $part['master']['rrule']);
            foreach ($part['exdates'] as $ex) {
                $this->check($inv . '-skip' . ($pastEnd($ex) ? '-past-end' : ''), $member($ex),'a skipped day is no day of the rule' . ($pastEnd($ex) ? ' (it is past the series end)' : ''), ['exdate' => $ex, 'rrule' => $part['master']['rrule'], 'start' => $part['master']['start_utc'], 'near' => self::near($part['raw'], $ex)]);
            }
            $seen = [];
            foreach ($part['overrides'] as $ov) {
                $k = (string) $ov['recurrence_instance_utc'];
                $this->check($inv . '-edited' . ($pastEnd($k) ? '-past-end' : ''), $member($k), 'an edited day is keyed on no day of the rule (it shows beside the series)' . ($pastEnd($k) ? ' (it is past the series end)' : ''), ['key' => $k, 'title' => $ov['title'], 'rrule' => $part['master']['rrule'], 'start' => $part['master']['start_utc'], 'near' => self::near($part['raw'], $k)]);
                $this->check($inv . '-twice', !isset($seen[$k]), 'two edited rows for one day', ['key' => $k]);
                $seen[$k] = true;
            }
        }
    }

    private static function near(array $raw, string $key): array
    {
        $keys = array_column($raw, 'key');
        $out = [];
        foreach ($keys as $i => $k) {
            if (substr($k, 0, 7) === substr($key, 0, 7)) {
                $out[] = $k;
            }
        }
        return array_slice($out, 0, 5);
    }

    private function checkFollowing(array $before, array $after, array $edit, array $payload): void
    {
        $family = $this->family();
        $m0 = $before['master'];
        $m1 = $after['master'];
        $occ = $before['raw'][$edit['at']] ?? $before['raw'][0];
        $split = $occ['key'];
        $days = (int) ($edit['days'] ?? 0);
        $oldRaw = $before['raw'];
        $beforeSplit = array_values(array_filter($oldRaw, static fn(array $o): bool => $o['key'] < $split));
        $rest = array_values(array_filter($oldRaw, static fn(array $o): bool => $o['key'] >= $split));
        if ($beforeSplit === []) {
            // From the first day it is the whole series.
            $this->check('5-first', $after['new'] === null, 'from the first day the edit is the whole series, no split', ['new' => $after['new']['master']['id'] ?? null]);
            if ($edit['kind'] === 'attend_following') {
                $this->check('5-attend', (string) $m1['attendance'] === 'going' && array_values(array_unique(array_column($after['overrides'], 'attendance') ?: ['going'])) === ['going'], 'the series and its edited days are marked going', ['master' => $m1['attendance'], 'overrides' => array_column($after['overrides'], 'attendance')]);
                $this->checkAttached('2', $after);
                return;
            }
            $this->checkWhole($before, $after, ['kind' => str_replace('_following', '_all', $edit['kind']), 'days' => $days, 'hours' => $edit['hours'] ?? 0, 'at' => $edit['at']], $payload);
            return;
        }
        // The old master: the days before the split, exactly; its exceptions before the split, untouched.
        $after['raw'] = $this->withoutPhantom('5', $m1, $after['raw']);
        if ($after['new'] !== null) {
            $after['new']['raw'] = $this->withoutPhantom('5', $after['new']['master'], $after['new']['raw']);
        }
        $this->check('5-old', array_column($after['raw'], 'key') === array_column($beforeSplit, 'key'), 'the old series keeps exactly the days before the split', self::firstDiff(array_column($beforeSplit, 'key'), array_column($after['raw'], 'key')) + ['old rule' => $m0['rrule'], 'new rule' => $m1['rrule']]);
        $oldKept = array_values(array_filter($before['exdates'], static fn(string $ex): bool => $ex < $split));
        $stale = array_values(array_diff($after['exdates'], $oldKept));
        $this->check('5-old', array_values(array_intersect($after['exdates'], $oldKept)) === $oldKept, 'the old series keeps its skipped days before the split', ['expected' => $oldKept, 'stored' => $after['exdates']]);
        $this->check('5-old-stale-skips', $stale === [], 'the old series still lists skipped days from the split on, past its new end', ['stale' => $stale, 'split' => $split]);
        $ovBefore = array_values(array_filter($before['overrides'], static fn(array $ov): bool => (string) $ov['recurrence_instance_utc'] < $split));
        $this->check('5-old', array_column($ovBefore, 'id') === array_column($after['overrides'], 'id'), 'the old series keeps its edited days before the split', ['expected' => array_column($ovBefore, 'recurrence_instance_utc'), 'stored' => array_column($after['overrides'], 'recurrence_instance_utc')]);
        foreach ($after['overrides'] as $ov1) {
            foreach ($ovBefore as $ov0) {
                if ((int) $ov0['id'] === (int) $ov1['id']) {
                    $a = $ov0;
                    $b = $ov1;
                    unset($a['updated_at'], $b['updated_at']);
                    if ($edit['kind'] === 'attend_following') {
                        unset($a['attendance'], $b['attendance']);
                    }
                    $this->check('5-old', $a === $b, 'an edited day before the split is untouched', ['key' => $ov0['recurrence_instance_utc'], 'changed' => array_keys(array_diff_assoc(array_map('strval', array_filter($b, 'is_scalar')), array_map('strval', array_filter($a, 'is_scalar'))))]);
                }
            }
        }
        foreach (['title', 'location', 'start_utc', 'tzid', 'all_day'] as $col) {
            $this->check('5-old', (string) $m0[$col] === (string) $m1[$col], "the old series keeps its $col", ['column' => $col, 'was' => $m0[$col], 'now' => $m1[$col]]);
        }
        $new = $after['new'];
        $this->check('5-new', $new !== null, 'a new series holds the days from the split on');
        if ($new === null) {
            return;
        }
        $n = $new['master'];
        $newDays = array_column($new['raw'], 'day');
        $time = self::timeOf($n);
        $newTz = (string) $n['tzid'];
        $newAllDay = (int) $n['all_day'] === 1;
        $this->check('0-created', $new['raw'] !== [] && $new['raw'][0]['key'] === (string) $n['start_utc'], 'the new series starts on its first raw occurrence', ['start' => $n['start_utc'], 'first' => $new['raw'][0]['key'] ?? null, 'rrule' => $n['rrule']]);
        // The rest as a series of its own: what patchFollowing starts from.
        $p0 = RepeatParser::ruleParts((string) $m0['rrule']);
        $restRule = (string) $m0['rrule'];
        if (isset($p0['COUNT'])) {
            $p0['COUNT'] = (string) max(1, (int) $p0['COUNT'] - count($beforeSplit));
            $restRule = implode(';', array_map(static fn($k, $v) => "$k=$v", array_keys($p0), $p0));
        }
        $landed = self::landedDay($payload, $occ['day'], $newTz, $newAllDay);
        $days = Oracle::daysBetween($occ['day'], $landed);
        $expected = Oracle::expected($family, $rest, $restRule, $days, $landed);
        $pn = RepeatParser::ruleParts((string) $n['rrule']);
        $bounded = self::bounded($restRule);
        if ($edit['kind'] === 'rename_following' || $edit['kind'] === 'attend_following') {
            // The whole of a bounded series; an unbounded one over the window both lists saw.
            [$want, $got] = Oracle::common(array_column($rest, 'key'), $before['through'], array_column($new['raw'], 'key'), $new['through']);
            $this->check('5-new', $want === $got, 'the new series has exactly the days from the split on', self::firstDiff(array_column($rest, 'key'), array_column($new['raw'], 'key')) + ['rule' => $n['rrule']]);
        } else {
            $this->same1($family, $expected, $newDays, $days, $bounded, ['rest rule' => $restRule, 'new rule' => $n['rrule'], 'expected rule' => $expected['rule'], 'split' => $split, 'new start' => $n['start_utc'], 'landed' => $landed]);
            if (!$newAllDay && isset($payload['start'])) {
                $wantTime = Time::parseIso((string) $payload['start'], $newTz)->setTimezone(Time::zone($newTz))->format('H:i:s');
                $this->check('1-time', $time === $wantTime, 'the new series runs at the time it was dragged to', ['sent' => $wantTime, 'stored' => $time]);
            }
            // The day acted on is the new series' first day, at the target.
            $target = $newAllDay ? $landed . ' 00:00:00' : Time::toDb(Time::parseIso((string) $payload['start'], $newTz));
            $this->check('5-day', isset($new['raw'][0]) && $new['raw'][0]['key'] === $target, 'the day acted on is where it was dragged to', ['target' => $target, 'first' => $new['raw'][0]['key'] ?? null, 'rule' => $n['rrule']]);
        }
        $this->check('5-count', ($pn['COUNT'] ?? null) === (RepeatParser::ruleParts($restRule)['COUNT'] ?? null), 'the new series keeps what is left of COUNT', ['expected' => $restRule, 'stored' => $n['rrule']]);
        $this->check('5-until', isset($pn['UNTIL']) === isset($p0['UNTIL']), 'an UNTIL stays an UNTIL on the new series', ['old' => $m0['rrule'], 'new' => $n['rrule']]);
        // Exceptions from the split on: on the new series, at their place.
        $restDays = array_column($rest, 'day');
        $oldTz = (string) $m0['tzid'];
        $oldAllDay = (int) $m0['all_day'] === 1;
        $want = [];
        foreach ($before['exdates'] as $ex) {
            if ($ex < $split) {
                continue;
            }
            $day = Oracle::mappedDay($family, Oracle::dayOf($ex, $oldTz, $oldAllDay), $restDays, $newDays, $days);
            $want[] = $day === null ? 'unmapped:' . $ex : Oracle::keyAt($day, $time, $newTz, $newAllDay);
        }
        sort($want);
        $got = $new['exdates'];
        sort($got);
        $this->check('5-skips', $want === $got, 'the skipped days from the split on are on the new series at their place', ['expected' => $want, 'stored' => $got]);
        $newById = [];
        foreach ($new['overrides'] as $ov) {
            $newById[(int) $ov['id']] = $ov;
        }
        $splitRow = null;
        foreach ($before['overrides'] as $ov0) {
            $k0 = (string) $ov0['recurrence_instance_utc'];
            if ($k0 < $split) {
                continue;
            }
            $ov1 = $newById[(int) $ov0['id']] ?? null;
            if ($ov1 === null) {
                $this->check('5-edited', false, 'an edited day from the split on is not on the new series', ['key' => $k0, 'title' => $ov0['title']]);
                continue;
            }
            $day = Oracle::mappedDay($family, Oracle::dayOf($k0, $oldTz, $oldAllDay), $restDays, $newDays, $days);
            $wantKey = $day === null ? null : Oracle::keyAt($day, $time, $newTz, $newAllDay);
            $this->check('5-edited', $wantKey === (string) $ov1['recurrence_instance_utc'], 'an edited day from the split on keeps its place', ['was' => $k0, 'expected' => $wantKey, 'stored' => $ov1['recurrence_instance_utc']]);
            $this->check('5-edited', (string) $ov1['uid'] === (string) $n['uid'] && (int) $ov1['calendar_id'] === (int) $n['calendar_id'], 'a carried edited day takes the new series uid and calendar', ['uid' => [$ov1['uid'], $n['uid']]]);
            if ($k0 === $split) {
                $splitRow = [$ov0, $ov1];
                continue;
            }
            $a = $ov0;
            if ($edit['kind'] === 'attend_following') {
                $a['attendance'] = 'going';
            }
            $this->checkDeviation($a, $ov1, $m0, $n);
        }
        // The day acted on takes the edit's fields; later edited days keep theirs.
        if ($edit['kind'] === 'rename_following') {
            $this->check('5-day', (string) $n['title'] === (string) $payload['title'] && (string) $n['location'] === (string) $payload['location'], 'the new series takes the edit', ['title' => $n['title'], 'location' => $n['location']]);
            if ($splitRow !== null) {
                $this->check('5-day', (string) $splitRow[1]['title'] === (string) $payload['title'] && (string) $splitRow[1]['location'] === (string) $payload['location'], 'the edited day acted on takes the edit', ['title' => $splitRow[1]['title'], 'location' => $splitRow[1]['location']]);
            }
        } elseif ($edit['kind'] === 'attend_following') {
            $this->check('5-attend', (string) $n['attendance'] === 'going' && array_unique(array_column($new['overrides'], 'attendance') ?: ['going']) === ['going'], 'the new series and its edited days are marked going', ['master' => $n['attendance'], 'overrides' => array_column($new['overrides'], 'attendance')]);
            $this->check('5-attend', (string) $m1['attendance'] === (string) $m0['attendance'], 'the old series keeps its answer', ['was' => $m0['attendance'], 'now' => $m1['attendance']]);
        } elseif ($splitRow !== null) {
            // A drag from an edited day: its row follows to the dragged time (in place) or keeps its own.
            $this->checkDeviation($splitRow[0], $splitRow[1], $m0, $n);
        }
        foreach (['title', 'location', 'description'] as $col) {
            if ($edit['kind'] !== 'rename_following' && !array_key_exists($col, $payload)) {
                $this->check('5-new', (string) $n[$col] === (string) $m0[$col], "the new series keeps the $col", ['column' => $col, 'was' => $m0[$col], 'now' => $n[$col]]);
            }
        }
        $this->checkAttached('2', $after);
        if (isset($payload['start']) && !str_starts_with($edit['kind'], 'editor')) {
            $this->checkGoogleParity($m0, $payload, $n, true);
        }
    }

    private function checkDelete(array $before, array $after, array $edit): void
    {
        $m0 = $before['master'];
        $m1 = $after['master'];
        $occ = $before['raw'][$edit['at']] ?? $before['raw'][0];
        $key = $occ['key'];
        $this->check('5-none', $after['new'] === null, 'a delete makes no new series');
        if ($edit['kind'] === 'delete_this') {
            $want = $before['exdates'];
            $want[] = $key;
            sort($want);
            $got = $after['exdates'];
            sort($got);
            $this->check('7-this', $want === $got, 'the day is skipped and the other skips stay', ['expected' => $want, 'stored' => $got]);
            $this->check('7-this', array_column($after['raw'], 'key') === array_column($before['raw'], 'key'), 'the rule is unchanged', ['old' => $m0['rrule'], 'new' => $m1['rrule']]);
            $wantIds = [];
            foreach ($before['overrides'] as $ov) {
                if ((string) $ov['recurrence_instance_utc'] !== $key) {
                    $wantIds[] = (int) $ov['id'];
                }
            }
            $this->check('7-this', $wantIds === array_map('intval', array_column($after['overrides'], 'id')), 'only the edited row of that day is removed', ['expected' => $wantIds, 'stored' => array_column($after['overrides'], 'id')]);
            return;
        }
        // following
        $beforeSplit = array_values(array_filter($before['raw'], static fn(array $o): bool => $o['key'] < $key));
        if ($beforeSplit === []) {
            $this->check('7-following', $m1['deleted_at'] !== null, 'from the first day the whole series is deleted', ['deleted_at' => $m1['deleted_at']]);
            $this->check('7-following', $after['overrides'] === [], 'its edited days go with it', ['left' => count($after['overrides'])]);
            return;
        }
        $after['raw'] = $this->withoutPhantom('7', $m1, $after['raw']);
        $this->check('7-following', array_column($after['raw'], 'key') === array_column($beforeSplit, 'key'), 'the series keeps exactly the days before', self::firstDiff(array_column($beforeSplit, 'key'), array_column($after['raw'], 'key')) + ['old' => $m0['rrule'], 'new' => $m1['rrule']]);
        $wantIds = [];
        foreach ($before['overrides'] as $ov) {
            if ((string) $ov['recurrence_instance_utc'] < $key) {
                $wantIds[] = (int) $ov['id'];
            }
        }
        $this->check('7-following', $wantIds === array_map('intval', array_column($after['overrides'], 'id')), 'the edited days from there on are removed, the earlier ones kept', ['expected' => $wantIds, 'stored' => array_column($after['overrides'], 'id')]);
        $this->check('7-following', $before['exdates'] === $after['exdates'], 'the skipped days are as they were', ['was' => $before['exdates'], 'now' => $after['exdates']]);
        $this->checkAttached('2', $after);
    }

    /** Invariant 8 for a split: Google's "this and following" stays on today's code until 1.5.1, with no local twin. */
    private function checkGoogleParity(array $m0, array $payload, array $stored, bool $following = false): void
    {
    }
}

/**
 * The failure signatures already reported (README, "What it finds"), each
 * under the root cause it belongs to. The database suite fails only on a
 * signature none of these match; the harness marks matched ones.
 */
final class Known
{
    /** @var list<array{0:string,1:string}> regex over "invariant | family | edit" => root cause */
    public const ROOT_CAUSES = [
        ['/^(1|5|7)-phantom \\|/', 'until-first-phantom'],
        ['/^5-old-stale-skips \\|/', 'stale-skips'],
        ['/^2-skip-past-end \\| .* \\| (delete|rename|attend|drag|editor_drag)_following$/', 'stale-skips'],
        // "This and following" stays on today's code until 1.5.1 (series-model.md, Acceptance):
        // its drag keeps the old ways of following, and a following save takes the editor's rule.
        ['/^(1-lead|1-days|1-until|1-count|2-skip|2-edited|2-skip-past-end|2-edited-past-end|3-skips|3-edited|4-moved|4-fields|4-time|5-day|5-skips|5-edited|5-count|5-old) \\| .* \\| (drag|editor_drag|rename|attend|delete)_following$/', 'following-1.5.1'],
        ['/^6-stack \\| .* \\| (drag|editor_drag|rename|attend|delete)_following$/', 'following-1.5.1'],
    ];

    public static function rootCause(string $signature): ?string
    {
        foreach (self::ROOT_CAUSES as [$pattern, $cause]) {
            if (preg_match($pattern, $signature) === 1) {
                return $cause;
            }
        }
        return null;
    }
}

/** Runs cases over a database (shared by harness.php and the database suite). */
final class Runner
{
    public array $counts = ['cases' => 0, 'byFamily' => [], 'byEdit' => [], 'failures' => [], 'failuresByFamily' => []];
    /** @var list<array> */
    public array $failures = [];

    public function __construct(private readonly Db $db, private readonly \Domains $domains, private readonly int $seed)
    {
    }

    /** A fresh account every $perUser cases keeps the journal short. */
    public function run(int $cases, ?string $onlyFamily = null, ?string $onlyEdit = null, ?int $onlyCase = null, int $perUser = 150, ?callable $progress = null): void
    {
        $driver = null;
        for ($i = 1; $i <= $cases; $i++) {
            if ($onlyCase !== null && $i !== $onlyCase) {
                continue;
            }
            if ($driver === null || ($i - 1) % $perUser === 0) {
                $u = \freshUser($this->db);
                $driver = new Driver($this->db, $this->domains->events, $this->domains->undo, $u, (int) Settings::defaultCalendarId($this->db, $u));
            }
            $r = new Rng($this->seed * 1_000_003 + $i);
            $spec = ['n' => $i, 'seed' => $this->seed, 'series' => Generator::series($r, $onlyFamily)];
            $result = $driver->run($spec, $r, $onlyEdit);
            $this->count($result);
            if ($progress !== null) {
                $progress($i, $result);
            }
        }
    }

    public function count(array $result): void
    {
        $spec = $result['spec'];
        $family = $spec['series']['family'];
        $edit = $spec['edit']['kind'] ?? '?';
        $this->counts['cases']++;
        $this->counts['byFamily'][$family] = ($this->counts['byFamily'][$family] ?? 0) + 1;
        $this->counts['byEdit'][$edit] = ($this->counts['byEdit'][$edit] ?? 0) + 1;
        foreach ($result['records'] as $rec) {
            $inv = $rec['invariant'];
            $this->counts['failures'][$inv] = ($this->counts['failures'][$inv] ?? 0) + 1;
            $this->counts['failuresByFamily'][$inv][$family] = ($this->counts['failuresByFamily'][$inv][$family] ?? 0) + 1;
            $this->failures[] = ['n' => $spec['n'], 'seed' => $spec['seed'], 'family' => $family, 'edit' => $edit, 'from' => $spec['edit']['from'] ?? null] + $rec + ['spec' => $spec];
        }
    }

    /**
     * A failure's signature: invariant, family, edit kind. A "this and
     * following" edit from the series' first day is the whole series on the
     * model (patchAllPlanned), not 1.4.6 code, so it is marked "@first" and
     * no 1.5.1 key hides it.
     */
    public static function signature(array $failure): string
    {
        $edit = (string) $failure['edit'];
        $first = ($failure['from'] ?? null) === 'first' || (int) ($failure['spec']['edit']['at'] ?? -1) === 0;
        if ($first && str_ends_with($edit, '_following')) {
            $edit .= '@first';
        }
        return $failure['invariant'] . ' | ' . $failure['family'] . ' | ' . $edit;
    }

    /**
     * Shrink a failing case: drop the second edit, then each exception,
     * then the time change, then the drag, keeping each change that still
     * fails the same invariant. Returns the smallest spec found and its records.
     */
    public function shrink(array $failure, int $budget = 14): array
    {
        $spec = $failure['spec'];
        $inv = $failure['invariant'];
        $u = \freshUser($this->db);
        $driver = new Driver($this->db, $this->domains->events, $this->domains->undo, $u, (int) Settings::defaultCalendarId($this->db, $u));
        $r = new Rng(0);
        $fails = static function (array $result) use ($inv): bool {
            foreach ($result['records'] as $rec) {
                if ($rec['invariant'] === $inv) {
                    return true;
                }
            }
            return false;
        };
        $best = ['spec' => $spec, 'records' => array_values(array_filter([$failure], static fn($f) => true))];
        $try = function (array $candidate) use (&$budget, $driver, $r, $fails, &$best): bool {
            if ($budget <= 0) {
                return false;
            }
            $budget--;
            try {
                $result = $driver->run($candidate, $r);
            } catch (\Throwable) {
                return false;
            }
            if ($fails($result)) {
                $best = $result;
                return true;
            }
            return false;
        };
        $cur = $spec;
        if (!empty($cur['stack'])) {
            $c = $cur;
            $c['stack'] = null;
            if ($try($c)) {
                $cur = $c;
            }
        }
        for ($i = count($cur['exceptions']) - 1; $i >= 0; $i--) {
            $c = $cur;
            array_splice($c['exceptions'], $i, 1);
            if ($c['edit']['from'] === 'edited' && !array_filter($c['exceptions'], static fn($e) => $e['kind'] !== 'skip' && $e['at'] === $c['edit']['at'])) {
                continue;
            }
            if ($try($c)) {
                $cur = $c;
            }
        }
        if (($cur['edit']['hours'] ?? 0) !== 0) {
            $c = $cur;
            $c['edit']['hours'] = 0;
            if ($try($c)) {
                $cur = $c;
            }
        }
        $days = (int) ($cur['edit']['days'] ?? 0);
        if ($days !== 0) {
            foreach ([1, 2, 3, 7, 28, 30, 31] as $d) {
                $d = $d * ($days < 0 ? -1 : 1);
                if (abs($d) >= abs($days)) {
                    break;
                }
                $c = $cur;
                $c['edit']['days'] = $d;
                if ($try($c)) {
                    $cur = $c;
                    break;
                }
            }
        }
        return $best;
    }
}
