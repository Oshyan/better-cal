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
