<?php
// An edit is a vector of deltas drawn independently (README, The Delta
// Vector): a date delta, a clock delta, a zone change, a length delta, an
// all-day toggle, the trip flag, each field an edited day can own, the rule
// sent back unchanged or changed, and a bound change, under a scope.
declare(strict_types=1);

namespace BetterCal\EditHarness;

final class Delta
{
    /** The values a series-wide edit sets (invented sample data). */
    public const VALUES = [
        'title' => 'Sample series, renamed',
        'location' => 'Sample Annex',
        'description' => 'Bring the music folder.',
        'url' => 'https://example.test/series',
        'reminders' => [45],
        'status' => 'tentative',
        'tags' => ['added', 'sample'],
        'people' => ['Alex Example', 'Sam Sample'],
    ];

    public const FIELDS = ['title', 'location', 'description', 'url', 'reminders', 'status', 'tags', 'people'];

    /** The deltas a shrink can zero, one at a time. */
    public const ZEROABLE = ['date', 'clock', 'zone', 'length', 'allDay', 'trip', 'rule', 'bound', 'startOnly', 'title', 'location', 'description', 'url', 'reminders', 'status', 'tags', 'people'];

    public static function draw(Rng $r, array $series, int $rawCount): array
    {
        $timed = !$series['allDay'];
        $v = [
            'scope' => $r->weighted(['all' => 62, 'following' => 14, 'deleteThis' => 6, 'deleteFollowing' => 6, 'attendAll' => 6, 'attendFollowing' => 6]),
            'date' => 0,
            'clock' => 0,
            'zone' => null,
            'length' => 0,
            'allDay' => false,
            'toZone' => $r->pick(['America/Los_Angeles', 'Europe/Berlin', 'Pacific/Auckland', 'Asia/Kolkata']),
            'toClock' => $r->pick([9 * 3600, 18 * 3600 + 1800, 2 * 3600 + 1800]),
            'trip' => $r->chance(0.08),
            'fields' => [],
            'rule' => $r->weighted(['absent' => 65, 'same' => 20, 'new' => 15]),
            'sameForm' => $r->pick(['text', 'editor']),
            'newRule' => self::otherRule($r, $series['rrule']),
            'bound' => null,
            'via' => $r->pick(['row', 'master']),
            // A start moved alone (series-model.md, Bounds): a whole-series
            // request that names no day and puts the series' start on a later
            // day of its own pattern (the driver picks the day), with no zone,
            // all-day or pattern change, which the design reads as a bound.
            'startOnly' => false,
        ];
        if ($r->chance(0.08)) {
            $v['startOnly'] = true;
            $v['scope'] = 'all';
            $v['zone'] = null;
            $v['via'] = 'master';
        }
        if (!$r->chance(0.45)) {
            $v['date'] = $r->chance(0.25) ? $r->pick([28, 29, 30, 31, 35, 45, -7, 7, 14, 21, -28, -30]) : ($r->pick([-3, -2, -1, 1, 2, 3, 4, 5, 6, 8, 10, 13]));
        }
        if ($timed && $r->chance(0.35)) {
            $v['clock'] = $r->pick([-180, -90, -60, -30, 15, 30, 60, 90, 180]) * 60;
        }
        if ($timed && $r->chance(0.15)) {
            $v['zone'] = $r->pick(array_values(array_diff(Space::ZONES, [$series['tzid']])));
        }
        if ($r->chance(0.18)) {
            $v['length'] = $timed ? $r->pick([-15, 15, 30, 60]) * 60 : 1;
        }
        $v['allDay'] = !$v['startOnly'] && $r->chance(0.12);
        foreach (self::FIELDS as $f) {
            if ($r->chance(0.12)) {
                $v['fields'][$f] = self::VALUES[$f];
            }
        }
        if ($v['startOnly'] && $v['rule'] === 'new') {
            $v['rule'] = 'same';
        }
        if ($r->chance(0.18)) {
            $kind = $r->pick(['count', 'until', 'none']);
            if ($kind === 'none' && $series['bound'] === 'none') {
                $kind = 'count';
            }
            $v['bound'] = match ($kind) {
                'count' => ['count' => $r->int(2, 14)],
                'until' => ['untilAt' => $r->int(1, max(1, min($rawCount - 1, 20)))],
                default => ['none' => true],
            };
        }
        return $v;
    }

    /**
     * The identity shapes (README, Google: Identity): a whole-series change that changes the identity Google gives an
     * instance, over a series with an edited day (its own place and notes) and a skipped day. Google names an instance by
     * the series id and its original start in the master's form (UTC basic time when timed, the date when all-day), while
     * the rows here key it by a UTC instant (the date at 00:00 UTC when all-day), so the two coincide for a timed
     * occurrence at midnight UTC while Google's identities differ. Weights per shape.
     */
    public const IDENTITY = [
        'toAllDayMidnight' => 4,   // timed at 00:00 UTC made all-day: the keys here coincide, Google's ids don't
        'toAllDayShifted' => 2,    // daily at 16:00 Los Angeles (00:00 UTC the next day) made all-day: an old key is another day's new key
        'toAllDayClock' => 2,      // timed at another clock made all-day: the keys differ
        'toTimedMidnight' => 4,    // all-day made timed at 00:00 UTC (or 13:00 Auckland in its summer): the keys coincide
        'toTimedClock' => 2,       // all-day made timed at 10:00 Los Angeles: the keys differ
        'zoneSameInstant' => 2,    // a zone change keeping the same moment: no instant moves (oracle pending a live probe)
        'landsOnOther' => 2,       // a drag by one period: each occurrence lands on the next one's old instant
    ];

    /** The shape whose stand-in behaviour is not yet known from real Google: counted apart (README, Google: Identity). */
    public const IDENTITY_PENDING = ['zoneSameInstant'];

    /**
     * A drawn case replaced by an identity shape: its series, edited and skipped days, the day acted on, the vector and
     * the choices, from $r (an Rng of the case's seed and number alone, so the drawn space of every other case is
     * untouched). $only names one shape.
     */
    public static function identity(Rng $r, ?string $only = null): array
    {
        $shape = $only ?? $r->weighted(self::IDENTITY);
        // Winter dates, so a Los Angeles or Berlin clock and Auckland's summer offset hold for every occurrence.
        $mondays = ['2026-12-07', '2027-01-04', '2027-01-18', '2027-02-01'];
        $day = $r->pick($mondays);
        $bound = $r->weighted(['count' => 3, 'none' => 1, 'until' => 1]);
        $series = ['family' => 'weekly_bare', 'rrule' => 'FREQ=WEEKLY', 'import' => false, 'bound' => $bound, 'boundAt' => 6, 'untilForm' => 'dayend',
            'allDay' => false, 'tzid' => 'UTC', 'start' => $day . ' 00:00', 'length' => 60, 'floating' => false];
        $v = ['scope' => 'all', 'date' => 0, 'clock' => 0, 'zone' => null, 'length' => 0, 'allDay' => false, 'toZone' => 'UTC', 'toClock' => 0,
            'trip' => false, 'fields' => [], 'rule' => 'absent', 'sameForm' => 'text', 'newRule' => 'FREQ=DAILY;INTERVAL=3', 'bound' => null,
            'via' => $r->pick(['row', 'master']), 'startOnly' => false];
        $choices = [];
        switch ($shape) {
            case 'toAllDayMidnight':
                // 00:00 UTC, or 13:00 in Auckland's summer (the same instant): Auckland's day is the UTC day.
                if ($r->chance(0.5)) {
                    $series['tzid'] = 'Pacific/Auckland';
                    $series['start'] = Days::date(Days::ofDate($day) + 1) . ' 13:00';
                }
                $v['allDay'] = true;
                break;
            case 'toAllDayShifted':
                $series['family'] = 'daily';
                $series['rrule'] = 'FREQ=DAILY';
                $series['boundAt'] = 8;
                $series['tzid'] = 'America/Los_Angeles';
                $series['start'] = $day . ' 16:00';
                $v['allDay'] = true;
                break;
            case 'toAllDayClock':
                $series['tzid'] = $r->pick(['America/Los_Angeles', 'Europe/Berlin']);
                $series['start'] = $day . ' ' . $r->pick(['10:00', '09:30']);
                $v['allDay'] = true;
                break;
            case 'toTimedMidnight':
                $series['allDay'] = true;
                $series['start'] = $day;
                $series['length'] = 1;
                $v['allDay'] = true;
                [$v['toZone'], $v['toClock']] = $r->pick([['UTC', 0], ['Pacific/Auckland', 13 * 3600]]);
                break;
            case 'toTimedClock':
                $series['allDay'] = true;
                $series['start'] = $day;
                $series['length'] = 1;
                $v['allDay'] = true;
                [$v['toZone'], $v['toClock']] = $r->pick([['America/Los_Angeles', 10 * 3600], ['Europe/Berlin', 9 * 3600 + 1800]]);
                break;
            case 'zoneSameInstant':
                [$series['tzid'], $series['start'], $v['zone']] = $r->pick([
                    ['America/Los_Angeles', $day . ' 10:00', 'America/Denver'],
                    ['America/Los_Angeles', $day . ' 10:00', 'Europe/Berlin'],
                    ['UTC', $day . ' 00:00', 'Pacific/Auckland'],
                ]);
                $choices = ['zone' => 'moment'];
                break;
            case 'landsOnOther':
                if ($r->chance(0.5)) {
                    $series['family'] = 'daily';
                    $series['rrule'] = 'FREQ=DAILY';
                    $series['boundAt'] = 8;
                    $series['tzid'] = 'America/Los_Angeles';
                    $series['start'] = $day . ' 10:00';
                    $v['date'] = 1;
                } else {
                    $v['date'] = 7;
                }
                break;
        }
        // The day acted on, an edited day with its own place and notes, and a skipped day, each before or after it;
        // in a quarter of the cases the day acted on is the edited day itself.
        $acted = $r->pick([0, 3]);
        $edited = $r->pick([1, 4]);
        $skipped = $r->pick([2, 5]);
        $from = $acted === 0 ? 'first' : 'later';
        if ($r->chance(0.25)) {
            $acted = $edited;
            $from = 'edited';
        }
        $own = $r->chance(0.3) ? ['title', 'location', 'description'] : ['location', 'description'];
        $exceptions = [
            ['at' => $edited, 'kind' => 'edit', 'own' => $own, 'days' => 1, 'mins' => 45, 'zone' => 'America/New_York', 'len' => 30],
            ['at' => $skipped, 'kind' => 'skip'],
        ];
        return ['series' => $series, 'exceptions' => $exceptions, 'acted' => $acted, 'from' => $from, 'vector' => $v, 'stack' => null,
            'choices' => ['edit' => $choices], 'preselected' => ['edit' => $choices === []], 'identity' => $shape];
    }

    /** A pattern other than the series' (the editor's Repeat changed): another family, or the same days shifted. */
    private static function otherRule(Rng $r, string $rrule): string
    {
        $p = Shape::parts($rrule);
        if (($p['FREQ'] ?? '') === 'WEEKLY' && isset($p['BYDAY']) && !str_contains($p['BYDAY'], '-') && $r->chance(0.4)) {
            $s = $r->int(1, 6);
            $days = array_map(static fn(string $c): string => Days::code((Days::wdOf($c) + $s) % 7), explode(',', $p['BYDAY']));
            $p['BYDAY'] = implode(',', $days);
            unset($p['COUNT'], $p['UNTIL']);
            return implode(';', array_map(static fn($k, $v) => "$k=$v", array_keys($p), $p));
        }
        for ($i = 0; $i < 6; $i++) {
            $rule = $r->pick(['FREQ=WEEKLY;BYDAY=' . $r->pick(Days::CODES), 'FREQ=MONTHLY;BYMONTHDAY=' . $r->int(1, 28), 'FREQ=DAILY;INTERVAL=2',
                'FREQ=WEEKLY;BYDAY=TU,TH', 'FREQ=MONTHLY;BYDAY=2WE', 'FREQ=YEARLY;BYMONTH=' . $r->int(1, 12) . ';BYMONTHDAY=' . $r->int(1, 28)]);
            if ($rule !== Shape::pattern($rrule)) {
                return $rule;
            }
        }
        return 'FREQ=DAILY;INTERVAL=3';
    }

    /** The second edit, undone twice: a small change to the series as it then is. */
    public static function stack(Rng $r): ?array
    {
        if (!$r->chance(0.3)) {
            return null;
        }
        return ['kind' => $r->pick(['time', 'rename', 'deleteThis', 'drag']), 'days' => $r->pick([-1, 1, 2, 7]), 'mins' => $r->pick([-60, 60, 120])];
    }

    /** The vector with one delta zeroed (a shrink step), or null when it already is. */
    public static function zero(array $v, string $d): ?array
    {
        switch ($d) {
            case 'date':
            case 'clock':
            case 'length':
                if ($v[$d] === 0) {
                    return null;
                }
                $v[$d] = 0;
                return $v;
            case 'zone':
            case 'bound':
                if ($v[$d] === null) {
                    return null;
                }
                $v[$d] = null;
                return $v;
            case 'allDay':
            case 'trip':
            case 'startOnly':
                if ($v[$d] === false) {
                    return null;
                }
                $v[$d] = false;
                return $v;
            case 'rule':
                if ($v['rule'] === 'absent') {
                    return null;
                }
                $v['rule'] = 'absent';
                return $v;
            default:
                if (!array_key_exists($d, $v['fields'])) {
                    return null;
                }
                unset($v['fields'][$d]);
                return $v;
        }
    }

    /** The non-zero deltas, for reports. */
    public static function nonZero(array $v): array
    {
        $out = [];
        foreach (['date', 'clock', 'length'] as $k) {
            if ($v[$k] !== 0) {
                $out[] = $k . ($k === 'date' ? sprintf('%+d', $v[$k]) : sprintf('%+dm', intdiv($v[$k], 60)));
            }
        }
        if ($v['zone'] !== null) {
            $out[] = 'zone';
        }
        if ($v['allDay']) {
            $out[] = 'allDay';
        }
        if ($v['trip']) {
            $out[] = 'trip';
        }
        if ($v['rule'] !== 'absent') {
            $out[] = 'rule:' . $v['rule'];
        }
        if ($v['bound'] !== null) {
            $out[] = 'bound:' . array_key_first($v['bound']);
        }
        foreach (array_keys($v['fields']) as $f) {
            $out[] = $f;
        }
        return $out;
    }
}
