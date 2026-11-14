<?php

declare(strict_types=1);

// The switch refusal's days against an expectation worked out here by date arithmetic alone (the second independent
// read of the 1.5.6 branch, F4; its probe probe2-fix-hidden-predicate made a test), from the rule the evidence gives
// (probe 2026-11-13, 3.48 to 3.51, 3.63 to 3.65: a day of the new form at the instant of a record, live or cancelled,
// of the old; an all-day day's instant is its date at 00:00 UTC). Nothing here calls server/src to compute an
// expectation: the expander below knows daily, weekly (with a list of weekdays), monthly by day of month and yearly
// rules only. For each case the planner's refusal (its days, and which of them it calls deleted) is compared with the
// days hidden, and which of those hidden days are themselves days the person deleted: a day is named by the day that
// would be hidden, never by the instant of the record that hides it, which falls on another date where the series'
// clock is 00:00 UTC on the next day (16:00 or 17:00 Los Angeles, 19:00 or 20:00 New York). Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $utc = new DateTimeZone('UTC');
    /** The local dates of a rule's first $count days from $first (Y-m-d). */
    $dates = static function (string $first, string $freq, ?array $byday, int $count) use ($utc): array {
        $d = new DateTimeImmutable($first, $utc);
        $out = [];
        $names = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];
        for ($n = 0; $n < 1500 && count($out) < $count; $n++, $d = $d->modify('+1 day')) {
            $start = new DateTimeImmutable($first, $utc);
            $ok = match ($freq) {
                'DAILY' => true,
                'WEEKLY' => $byday !== null ? in_array($names[(int) $d->format('w')], $byday, true) : $d->format('w') === $start->format('w'),
                'MONTHLY' => $d->format('d') === $start->format('d'),
                'YEARLY' => $d->format('md') === $start->format('md'),
            };
            if ($ok) {
                $out[] = $d->format('Y-m-d');
            }
        }
        return $out;
    };
    $instant = static function (string $date, ?string $clock, ?string $zone) use ($utc): string {
        return $clock === null ? $date . ' 00:00:00' : (new DateTimeImmutable("$date $clock", new DateTimeZone((string) $zone)))->setTimezone($utc)->format('Y-m-d H:i:s');
    };
    $iso = static fn(string $date, string $clock, string $zone): string => (new DateTimeImmutable("$date $clock", new DateTimeZone($zone)))->format('Y-m-d\TH:i:sP');

    // [name, zone, clock, first date, FREQ, BYDAY or null, COUNT, direction, edited day indexes, deleted day indexes]
    $cases = [
        ['weekly 20:00 New York in July, 2nd renamed 3rd deleted, to all-day', 'America/New_York', '20:00', '2027-07-05', 'WEEKLY', null, 6, 'toAllDay', [1], [2]],
        ['daily 20:00 New York in July, 2nd deleted alone, to all-day', 'America/New_York', '20:00', '2027-07-05', 'DAILY', null, 8, 'toAllDay', [], [1]],
        ['daily 20:00 New York in July, 2nd renamed and 3rd deleted, to all-day', 'America/New_York', '20:00', '2027-07-05', 'DAILY', null, 8, 'toAllDay', [1], [2]],
        ['daily 20:00 New York in July, the last day renamed, to all-day', 'America/New_York', '20:00', '2027-07-05', 'DAILY', null, 8, 'toAllDay', [7], []],
        ['daily 20:00 New York in July, the last day deleted, to all-day', 'America/New_York', '20:00', '2027-07-05', 'DAILY', null, 8, 'toAllDay', [], [7]],
        ['daily 20:00 New York across the end of summer time (7 Nov 2027), a day renamed each side, to all-day', 'America/New_York', '20:00', '2027-11-01', 'DAILY', null, 14, 'toAllDay', [2, 9], []],
        ['daily 19:00 New York across the end of summer time, a day renamed each side, to all-day', 'America/New_York', '19:00', '2027-11-01', 'DAILY', null, 14, 'toAllDay', [2, 9], []],
        ['daily 21:00 New York in July (01:00 UTC), 2nd renamed 3rd deleted, to all-day', 'America/New_York', '21:00', '2027-07-05', 'DAILY', null, 8, 'toAllDay', [1], [2]],
        ['every weekday 17:00 Los Angeles in July, Monday renamed, to all-day', 'America/Los_Angeles', '17:00', '2027-07-05', 'WEEKLY', ['MO', 'TU', 'WE', 'TH', 'FR'], 10, 'toAllDay', [5], []],
        ['every weekday 17:00 Los Angeles in July, Friday renamed, to all-day', 'America/Los_Angeles', '17:00', '2027-07-05', 'WEEKLY', ['MO', 'TU', 'WE', 'TH', 'FR'], 10, 'toAllDay', [4], []],
        ['every weekday 17:00 Los Angeles in July, Friday deleted, to all-day', 'America/Los_Angeles', '17:00', '2027-07-05', 'WEEKLY', ['MO', 'TU', 'WE', 'TH', 'FR'], 10, 'toAllDay', [], [4]],
        ['monthly on the 5th 16:00 Los Angeles from January, 2nd renamed, to all-day', 'America/Los_Angeles', '16:00', '2027-01-05', 'MONTHLY', null, 6, 'toAllDay', [1], []],
        ['yearly 5 July 17:00 Los Angeles, 2nd renamed 3rd deleted, to all-day', 'America/Los_Angeles', '17:00', '2027-07-05', 'YEARLY', null, 4, 'toAllDay', [1], [2]],
        ['weekly 09:00 Tokyo, 2nd renamed 3rd deleted, to all-day', 'Asia/Tokyo', '09:00', '2027-01-04', 'WEEKLY', null, 6, 'toAllDay', [1], [2]],
        ['daily 13:00 Auckland in July (01:00 UTC), 2nd renamed, to all-day', 'Pacific/Auckland', '13:00', '2027-07-05', 'DAILY', null, 6, 'toAllDay', [1], []],
        ['daily 12:00 Auckland in July (00:00 UTC), 2nd renamed, to all-day', 'Pacific/Auckland', '12:00', '2027-07-05', 'DAILY', null, 6, 'toAllDay', [1], []],
        ['weekly 05:30 Kolkata (00:00 UTC), 2nd deleted, to all-day', 'Asia/Kolkata', '05:30', '2027-01-04', 'WEEKLY', null, 6, 'toAllDay', [], [1]],
        ['weekly 00:00 London in January (00:00 UTC), 2nd renamed, to all-day', 'Europe/London', '00:00', '2027-01-04', 'WEEKLY', null, 6, 'toAllDay', [1], []],
        ['weekly 01:00 London in July (00:00 UTC), 2nd renamed, to all-day', 'Europe/London', '01:00', '2027-07-05', 'WEEKLY', null, 6, 'toAllDay', [1], []],
        ['daily all-day in July, 2nd deleted alone, to 20:00 New York', 'America/New_York', '20:00', '2027-07-05', 'DAILY', null, 8, 'toTimed', [], [1]],
        ['daily all-day in July, the first day renamed, to 20:00 New York', 'America/New_York', '20:00', '2027-07-05', 'DAILY', null, 8, 'toTimed', [0], []],
        ['daily all-day in July, 2nd renamed 3rd deleted, to 20:00 New York', 'America/New_York', '20:00', '2027-07-05', 'DAILY', null, 8, 'toTimed', [1], [2]],
        ['weekly all-day in July, 2nd renamed, to 20:00 New York', 'America/New_York', '20:00', '2027-07-05', 'WEEKLY', null, 6, 'toTimed', [1], []],
        ['weekly all-day, 2nd renamed 3rd deleted, to 09:00 Tokyo', 'Asia/Tokyo', '09:00', '2027-01-04', 'WEEKLY', null, 6, 'toTimed', [1], [2]],
        ['daily all-day across the end of summer time, a day renamed each side, to 20:00 New York', 'America/New_York', '20:00', '2027-11-01', 'DAILY', null, 14, 'toTimed', [2, 9], []],
        ['daily all-day in July, 2nd renamed, to 10:00 Los Angeles', 'America/Los_Angeles', '10:00', '2027-07-05', 'DAILY', null, 6, 'toTimed', [1], []],
        ['weekly Mon and Tue 20:00 New York in July, the first Monday renamed, to all-day', 'America/New_York', '20:00', '2027-07-05', 'WEEKLY', ['MO', 'TU'], 6, 'toAllDay', [0], []],
    ];
    foreach ($cases as [$name, $zone, $clock, $first, $freq, $byday, $count, $direction, $edited, $deletedIdx]) {
        scenario("probe hidden predicate: $name", function () use ($db, $dates, $instant, $iso, $name, $zone, $clock, $first, $freq, $byday, $count, $direction, $edited, $deletedIdx): void {
            $days = $dates($first, $freq, $byday, $count);
            $rule = "FREQ=$freq" . ($byday !== null ? ';BYDAY=' . implode(',', $byday) : '') . ";COUNT=$count";
            $next = static fn(string $d): string => (new DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d');
            $endClock = (new DateTimeImmutable("2000-01-01 $clock"))->modify('+1 hour')->format('H:i');
            $timed = ['start' => $iso($first, $clock, $zone), 'end' => $iso($first, $endClock, $zone), 'tzid' => $zone, 'rrule' => $rule];
            $allDay = ['start' => $first, 'end' => $next($first), 'allDay' => true, 'rrule' => $rule];
            $toAllDay = $direction === 'toAllDay';
            $t = H::make($db, $toAllDay ? $timed : $allDay);
            try {
                $inst = static fn(string $d): string => $toAllDay ? $iso($d, $clock, $zone) : $d;
                foreach ($edited as $i) {
                    H::this($t, $inst($days[$i]), ['title' => 'Sample series (own ' . $i . ')']);
                }
                foreach ($deletedIdx as $i) {
                    H::skip($t, $inst($days[$i]));
                }
                // The evidence's rule, by dates: the old form's record instants, and each day of the new form at one.
                $oldInstant = static fn(string $d): string => $toAllDay ? $instant($d, $clock, $zone) : $instant($d, null, null);
                $newInstant = static fn(string $d): string => $toAllDay ? $instant($d, null, null) : $instant($d, $clock, $zone);
                $records = [];
                foreach (array_merge($edited, $deletedIdx) as $i) {
                    $records[$oldInstant($days[$i])] = true;
                }
                $wantHidden = [];
                $wantDeleted = [];
                foreach ($days as $i => $d) {
                    if (isset($records[$newInstant($d)])) {
                        $wantHidden[] = $d;
                        if (in_array($i, $deletedIdx, true)) {
                            $wantDeleted[] = $d; // a hidden day that is itself a day the person deleted
                        }
                    }
                }
                $body = $toAllDay
                    ? ['scope' => 'all', 'instanceStart' => $inst($first), 'start' => $first, 'end' => $next($first), 'allDay' => true]
                    : ['scope' => 'all', 'instanceStart' => $first, 'start' => $iso($first, $clock, $zone), 'end' => $iso($first, $endClock, $zone), 'allDay' => false, 'tzid' => $zone];
                $plan = $t->e->previewPatch($t->u, $t->id, $body + ['plan' => ['choices' => []]]);
                $r = array_values(array_filter($plan['refusals'] ?? [], static fn(array $x): bool => ($x['code'] ?? '') === 'googleHidden'))[0] ?? null;
                $gotHidden = $r['days'] ?? [];
                $gotDeleted = $r['deleted'] ?? [];
                $other = array_values(array_filter(array_column($plan['refusals'] ?? [], 'code'), static fn($c): bool => $c !== 'googleHidden'));
                if (getenv('BC_TEST_VERBOSE')) echo "    [$name]\n      hidden by the evidence's rule: " . json_encode($wantHidden) . ', of them deleted by the person: ' . json_encode($wantDeleted)
                    . "\n      the planner: days " . json_encode($gotHidden) . ', called deleted ' . json_encode($gotDeleted) . ($other !== [] ? ', other refusals ' . json_encode($other) : '')
                    . ($r !== null ? "\n      sentence: " . $r['words'] : '') . "\n";
                checkEq("$name: the days the planner refuses for are the days hidden", $wantHidden, $gotHidden);
                checkEq("$name: the days the sentence treats as deleted are the hidden days the person deleted", $wantDeleted, $gotDeleted);
                // Where nothing would be hidden, the switch goes through and Google lists what the calendar shows.
                if ($wantHidden === [] && $r === null) {
                    [, $error] = H::tryChange($t, $body);
                    checkEq("$name: nothing hidden, so the switch goes through", null, $error);
                    // The window the views read is a year at most: a yearly series is not compared day for day.
                    if ($error === null && $freq !== 'YEARLY') {
                        H::settled($t, $name, $zone, (new DateTimeImmutable($first))->modify('-3 days')->format('Y-m-d'), (new DateTimeImmutable(end($days)))->modify('+5 days')->format('Y-m-d'));
                    }
                }
            } finally {
                H::done($t);
            }
        });
    }
};
