<?php
// The space the harness draws from, taken from the design's tables
// (series-model.md, What a Drag Does, by Family and Imported Rules, by
// Capability): every rule family the drag table names and every row of the
// capability table, every bound kind, every zone kind, starts around clock
// changes and month ends; then edited and skipped days and the day acted on.
declare(strict_types=1);

namespace BetterCal\EditHarness;

final class Space
{
    /** Northern DST (two different weeks), southern DST, a half-hour zone, a zone whose clocks move by half an hour (Lord Howe), UTC; all-day series are UTC dates. */
    public const ZONES = ['America/Los_Angeles', 'Europe/Berlin', 'Pacific/Auckland', 'Asia/Kolkata', 'Australia/Lord_Howe', 'UTC'];
    public const TIMES = ['06:00', '09:30', '13:00', '18:00', '21:15', '23:30', '00:30', '02:30', '01:30'];

    /** Rows of the drag table (weights), then the capability table's other rows. */
    public const FAMILIES = [
        'daily' => 4, 'daily_byday' => 3,
        'weekly_bare' => 2, 'weekly_one' => 6, 'weekly_list' => 5, 'weekly_list_i2' => 4,
        'monthly_bare' => 3, 'monthly_day' => 5, 'monthly_day_late' => 4, 'monthly_clamped' => 3, 'monthly_from_end' => 3,
        'monthly_day_list' => 4, 'monthly_nth' => 5, 'monthly_nth_setpos' => 2, 'monthly_nth_list' => 4, 'monthly_setpos_set' => 3,
        'yearly_bare' => 2, 'yearly_day' => 4, 'yearly_nth' => 3, 'yearly_months' => 3,
        'onehour' => 2,
        'several' => 2, 'weekno' => 1, 'dayset' => 3, 'unreadable' => 2,
    ];

    /** The draw for one series (no database needed). */
    public static function series(Rng $r, ?string $only = null): array
    {
        $family = $only ?? $r->weighted(self::FAMILIES);
        $allDay = $family !== 'several' && $family !== 'onehour' && $r->chance(0.28);
        $tzid = $allDay ? 'UTC' : $r->pick(self::ZONES);
        $day = self::startDay($r, $tzid);
        $time = $allDay ? null : $r->pick(self::TIMES);
        $dn = Days::ofDate($day);
        $wd = Days::code(Days::wd($dn));
        $code = $r->pick(Days::CODES);
        [, $mo, $dom] = Days::ymd($dn);
        $list = static function () use ($r): array {
            $days = [];
            foreach (Days::CODES as $c) {
                if ($r->chance(0.35)) {
                    $days[] = $c;
                }
            }
            return count($days) >= 2 ? $days : $r->pick([['MO', 'WE', 'FR'], ['TU', 'TH'], ['SA', 'SU'], ['MO', 'TH']]);
        };
        $import = false;
        $rrule = match ($family) {
            'daily' => 'FREQ=DAILY' . ($r->chance(0.4) ? ';INTERVAL=' . $r->int(2, 3) : ''),
            'daily_byday' => 'FREQ=DAILY;BYDAY=' . implode(',', $list()),
            'weekly_bare' => 'FREQ=WEEKLY' . ($r->chance(0.35) ? ';INTERVAL=2' : ''),
            'weekly_one' => 'FREQ=WEEKLY' . ($r->chance(0.3) ? ';INTERVAL=2' : '') . ';BYDAY=' . ($r->chance(0.7) ? $wd : $code),
            'weekly_list' => 'FREQ=WEEKLY;BYDAY=' . implode(',', $list()),
            'weekly_list_i2' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=' . implode(',', $list()) . ($r->chance(0.25) ? ';WKST=SU' : ''),
            'monthly_bare' => 'FREQ=MONTHLY' . ($r->chance(0.25) ? ';INTERVAL=3' : ''),
            'monthly_day' => 'FREQ=MONTHLY' . ($r->chance(0.15) ? ';INTERVAL=2' : '') . ';BYMONTHDAY=' . ($r->chance(0.6) ? min($dom, 28) : $r->int(1, 28)),
            'monthly_day_late' => 'FREQ=MONTHLY;BYMONTHDAY=' . $r->pick([29, 30, 31]),
            'monthly_clamped' => 'FREQ=MONTHLY;BYMONTHDAY=' . $r->pick(['28,29,30', '28,29', '28,29,30,31']) . ';BYSETPOS=-1',
            'monthly_from_end' => 'FREQ=MONTHLY;BYMONTHDAY=' . $r->pick([-1, -1, -2, -3, -7]),
            'monthly_day_list' => 'FREQ=MONTHLY;BYMONTHDAY=' . $r->pick(['1,15', '10,20', '5,25', '1,15,28', '15,-1', '8,22']),
            'monthly_nth' => 'FREQ=MONTHLY' . ($r->chance(0.15) ? ';INTERVAL=2' : '') . ';BYDAY=' . $r->pick([1, 2, 3, 4, -1]) . $code,
            'monthly_nth_setpos' => 'FREQ=MONTHLY;BYDAY=' . $code . ';BYSETPOS=' . $r->pick([1, 2, 3, 4, -1]),
            'monthly_nth_list' => 'FREQ=MONTHLY;BYDAY=' . $r->pick(['1MO,3MO', '2TU,4TU', '1MO,3TU,-1FR', '1SA,-1SU', '2WE,4FR']),
            'monthly_setpos_set' => 'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=' . $r->pick(['1,3', '-1', '2', '1', '-1,-3']),
            'yearly_bare' => 'FREQ=YEARLY',
            'yearly_day' => self::yearlyDay($r),
            'yearly_nth' => 'FREQ=YEARLY;BYMONTH=' . $r->int(1, 12) . ';BYDAY=' . $r->pick([1, 2, 3, 4, -1]) . $code,
            'yearly_months' => 'FREQ=YEARLY;BYMONTH=' . $r->pick(['3,9', '1,7', '2,5,11']) . ($r->chance(0.6) ? ';BYMONTHDAY=' . $r->int(1, 28) : ';BYDAY=' . $r->pick([1, 2, -1]) . $code),
            'onehour' => 'FREQ=WEEKLY;BYDAY=' . $wd . ';BYHOUR=' . (int) substr((string) $time, 0, 2) . ';BYMINUTE=' . (int) substr((string) $time, 3, 2),
            'several' => $r->pick(['FREQ=DAILY;BYHOUR=9,15;BYMINUTE=0', 'FREQ=HOURLY;INTERVAL=6', 'FREQ=MINUTELY;INTERVAL=720']),
            'weekno' => 'FREQ=YEARLY;BYWEEKNO=' . $r->int(5, 40) . ';BYDAY=' . $code,
            'dayset' => $r->pick(['FREQ=YEARLY;BYYEARDAY=' . $r->int(20, 300), 'FREQ=MONTHLY;BYDAY=FR;BYMONTHDAY=13', 'FREQ=WEEKLY;BYDAY=MO;BYMONTH=1,2,3,10,11,12',
                'FREQ=DAILY;INTERVAL=2;BYDAY=MO,WE,FR', 'FREQ=MONTHLY;BYDAY=4MO,-1MO', 'FREQ=WEEKLY;BYDAY=MO,WE,FR;BYSETPOS=1']),
            'unreadable' => $r->pick(['FREQ=WEEKLY;BYDAY=TU;X-SAMPLE=1', 'RSCALE=GREGORIAN;FREQ=MONTHLY']),
        };
        if ($family === 'several' && $time !== null) {
            $time = $r->pick(['09:00', '03:00']);
        }
        if (in_array($family, ['several', 'weekno', 'dayset', 'unreadable'], true)) {
            $import = true; // stored as an import writes it (Rows from Elsewhere)
        }
        $bound = $r->weighted(['none' => 4, 'count' => 3, 'until' => 3]);
        if ($family === 'several' && $bound === 'none') {
            $bound = 'count';
        }
        return [
            'family' => $family,
            'rrule' => $rrule,
            'import' => $import,
            'bound' => $bound,
            'boundAt' => $bound === 'count' ? $r->int(2, 16) : $r->int(2, 12),
            'untilForm' => $r->chance(0.7) ? 'dayend' : 'exact',
            'allDay' => $allDay,
            'tzid' => $tzid,
            'start' => $day . ($allDay ? '' : ' ' . $time),
            'length' => $allDay ? $r->pick([1, 1, 1, 2]) : $r->pick([30, 60, 90, 120]),
        ];
    }

    private static function yearlyDay(Rng $r): string
    {
        if ($r->chance(0.15)) {
            return 'FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=' . $r->pick([29, -1, 28]);
        }
        $m = $r->int(1, 12);
        $d = $r->chance(0.3) ? $r->pick([28, 29, 30, 31]) : $r->int(1, 27);
        return 'FREQ=YEARLY;BYMONTH=' . $m . ';BYMONTHDAY=' . min($d, [31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$m - 1]);
    }

    /** A start around today: a few days before or after one of the zone's clock changes, a month end, the turn of the year, or anywhere. */
    private static function startDay(Rng $r, string $tz): string
    {
        $kind = $r->weighted(['change' => 3, 'monthEnd' => 3, 'any' => 4]);
        if ($kind === 'change' && $tz !== 'UTC' && $tz !== 'Asia/Kolkata') {
            $changes = [
                'America/Los_Angeles' => ['2027-03-14', '2027-11-07', '2026-11-01'],
                'Europe/Berlin' => ['2027-03-28', '2027-10-31', '2026-10-25'],
                'Pacific/Auckland' => ['2027-04-04', '2027-09-26', '2026-09-27'],
                'Australia/Lord_Howe' => ['2027-04-04', '2027-10-03', '2026-10-04'],
            ][$tz];
            return Days::date(Days::ofDate($r->pick($changes)) + $r->int(-16, 2));
        }
        if ($kind === 'monthEnd' || $kind === 'change') {
            return $r->pick(['2026-11-30', '2026-12-29', '2026-12-31', '2027-01-01', '2027-01-30', '2027-01-31', '2027-02-27', '2027-02-28', '2027-03-01', '2027-03-31', '2027-04-30', '2027-05-31', '2027-08-31', '2028-02-29', '2028-02-28']);
        }
        return Days::date(Days::ofDate('2026-06-01') + $r->int(0, 420));
    }

    /**
     * Edited and skipped days, and the day acted on, for a series whose raw
     * occurrences are known: placed before, at and after the day acted on,
     * on the first, the last and the first after a clock change.
     *
     * @param list<array{key:string,dn:int,secs:int}> $raw
     */
    public static function exceptions(Rng $r, array $series, array $raw): array
    {
        $n = count($raw);
        $last = $n - 1;
        $tz = $series['allDay'] ? 'UTC' : $series['tzid'];
        $afterChange = null;
        if ($n > 1 && !$series['allDay']) {
            $change = Clock::changeAfter($tz, $raw[0]['dn'], $raw[min($last, 40)]['dn']);
            if ($change !== null) {
                foreach ($raw as $i => $o) {
                    if ($o['dn'] >= $change) {
                        $afterChange = $i;
                        break;
                    }
                }
            }
        }
        $bounded = $series['bound'] !== 'none';
        $from = $r->weighted(['later' => 5, 'edited' => 3, 'first' => 2, 'last' => $bounded && $n <= 60 ? 2 : 0, 'afterChange' => $afterChange !== null ? 3 : 0]);
        $at = match ($from) {
            'first' => 0,
            'last' => $last,
            'afterChange' => (int) $afterChange,
            default => $n <= 2 ? 0 : $r->int(1, min($last, 24)),
        };
        $slots = array_values(array_unique(array_filter(
            [$at - 1, $at, $at + 1, 0, $last, $afterChange ?? -1, $afterChange !== null ? $afterChange - 1 : -1, $r->int(0, min($last, 30))],
            static fn(int $i): bool => $i >= 0 && $i <= $last && $i < 60
        )));
        $out = [];
        $used = [];
        $edits = $r->int(0, 3);
        $skips = $r->int(0, 2);
        for ($i = 0; $i < $edits + $skips && $slots !== []; $i++) {
            $idx = $r->pick($slots);
            $slots = array_values(array_diff($slots, [$idx]));
            $skip = $i >= $edits;
            if ($idx === $at && ($skip || $from !== 'edited')) {
                continue; // the day acted on is never skipped, and is edited only when that is the point
            }
            $used[$idx] = true;
            $out[] = $skip ? ['at' => $idx, 'kind' => 'skip'] : self::edited($r, $idx, $series, $idx === $at);
        }
        if ($from === 'edited' && !isset($used[$at])) {
            $out[] = self::edited($r, $at, $series, true);
        }
        return ['exceptions' => $out, 'acted' => $at, 'from' => $from];
    }

    /** One edited day: the fields it owns, each changed as a "this event" edit would. */
    private static function edited(Rng $r, int $idx, array $series, bool $acted = false): array
    {
        $fields = ['title', 'location', 'description', 'url', 'reminders', 'status', 'tags', 'people', 'date', 'time', 'zone', 'length', 'allDay'];
        $own = [];
        foreach ($fields as $f) {
            if ($r->chance(in_array($f, ['title', 'date', 'time'], true) ? 0.35 : 0.12)) {
                $own[] = $f;
            }
        }
        if ($own === []) {
            $own = [$r->pick(['title', 'date', 'time'])];
        }
        if ($series['allDay']) {
            $own = array_values(array_diff($own, ['time', 'zone']));
        }
        if ($acted || $series['family'] === 'several') {
            // The day acted on keeps its kind (all-day or timed) as the series': a drag from it is measured on one clock.
            $own = array_values(array_diff($own, ['allDay']));
        }
        if (in_array('allDay', $own, true)) {
            $own = array_values(array_diff($own, ['time', 'zone', 'length']));
        }
        if ($own === []) {
            $own = ['title'];
        }
        return ['at' => $idx, 'kind' => 'edit', 'own' => $own, 'days' => $r->pick([-3, -2, -1, 1, 2, 3]), 'mins' => $r->pick([-240, -90, -30, 45, 120, 180]),
            'zone' => $r->pick(['Asia/Tokyo', 'America/New_York']), 'len' => $r->pick([-15, 30, 60])];
    }
}
