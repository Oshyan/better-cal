<?php
// Differential harness, Better-Cal side. Builds a matrix of series the way
// Better-Cal stores them, expands each with Recurrence::expand (sabreExpand),
// exports each with Ics::buildCalendar, and writes cases.json.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/server/vendor/autoload.php';

use BetterCal\Domain\Ics;
use BetterCal\Domain\Recurrence;
use BetterCal\Support\Time;

$zones = ['America/Los_Angeles', 'America/New_York', 'America/St_Johns', 'America/Sao_Paulo', 'Pacific/Pago_Pago',
    'Europe/London', 'Europe/Berlin', 'Asia/Kolkata', 'Asia/Kathmandu', 'Asia/Tokyo', 'Australia/Adelaide',
    'Australia/Sydney', 'Pacific/Auckland', 'Pacific/Chatham', 'Pacific/Kiritimati', 'UTC'];
$times = ['00:30', '01:30', '02:30', '09:00', '23:30'];
// Starts: just before northern spring/fall changes, just before southern changes, and an old series.
$starts = ['2026-03-05', '2026-10-22', '2026-03-31', '2019-01-07'];
$rules = [
    'FREQ=DAILY;COUNT=20',
    'FREQ=WEEKLY;BYDAY=MO,WE,FR;COUNT=30',
    'FREQ=WEEKLY;INTERVAL=2;BYDAY=SU,TH',
    'FREQ=MONTHLY;BYMONTHDAY=31;COUNT=12',
    'FREQ=MONTHLY;BYDAY=-1SU;COUNT=12',
    'FREQ=MONTHLY;BYDAY=2TU',
    'FREQ=YEARLY;BYMONTH=3,10,11;BYDAY=1SU,2SU,-1SU',
    'FREQ=DAILY;UNTIL=@+40',   // UNTIL as Better-Cal's editor writes it, 40 days on
    'FREQ=WEEKLY;UNTIL=@+70',
    'FREQ=DAILY',
];
$only = getenv('ONLY') ?: null;

$cases = [];
$n = 0;
foreach ($zones as $zone) {
    foreach ($starts as $date) {
        foreach ($rules as $rule) {
            foreach (array_merge(['allday'], $times) as $time) {
                $allDay = $time === 'allday';
                $n++;
                $uid = "case-$n@example.test";
                $tz = new DateTimeZone($zone);
                if ($allDay) {
                    $s = Time::parseAllDay($date, $zone);
                    $e = $s->setTimezone($tz)->modify('+1 day')->setTime(0, 0)->setTimezone(Time::utc());
                } else {
                    $s = Time::parseIso($date . 'T' . $time . ':00', $zone);
                    $e = $s->add(new DateInterval('PT1H'));
                }
                $rrule = $rule;
                if (preg_match('/UNTIL=@\+(\d+)/', $rule, $m)) {
                    $untilDay = (new DateTimeImmutable($date, $tz))->modify('+' . $m[1] . ' days');
                    // Editor: all-day writes the date; timed writes 23:59 on the device clock as UTC.
                    // Device clock == event zone here (the common case).
                    $u = $allDay ? $untilDay->format('Ymd')
                        : (new DateTimeImmutable($untilDay->format('Y-m-d') . ' 23:59:00', $tz))->setTimezone(Time::utc())->format('Ymd\THis\Z');
                    $rrule = preg_replace('/UNTIL=@\+\d+/', 'UNTIL=' . $u, $rule);
                }
                $master = [
                    'id' => $n, 'uid' => $uid, 'title' => "Case $n", 'all_day' => $allDay ? 1 : 0, 'tzid' => $zone,
                    'start_utc' => Time::toDb($s), 'end_utc' => Time::toDb($e), 'rrule' => $rrule, 'exdates_json' => null,
                    'status' => 'confirmed', 'description' => null, 'location' => null, 'url' => null, 'reminders_json' => null,
                ];
                // The first occurrences, to pick an exdate (3rd) and an override (4th) as the app would key them.
                $first = Recurrence::sabreExpand($master, $s, $s->modify('+3 years'));
                $overrides = [];
                if (count($first) >= 5) {
                    $key = static function (DateTimeImmutable $occ) use ($allDay, $zone): string {
                        // What the client sends as instanceStart, then requireInstance().
                        if ($allDay) {
                            return Time::toDb(Time::parseAllDay($occ->setTimezone(new DateTimeZone($zone))->format('Y-m-d') . 'T00:00:00+00:00', $zone));
                        }
                        return Time::toDb(Time::parseIso(Time::iso($occ->setTimezone(new DateTimeZone($zone))), $zone));
                    };
                    $master['exdates_json'] = json_encode([$key($first[2]['start'])]);
                    $o = $first[3];
                    $shift = $allDay ? 'P1D' : 'PT2H';
                    $overrides[] = [
                        'id' => 100000 + $n, 'uid' => $uid, 'title' => "Case $n moved", 'all_day' => $allDay ? 1 : 0, 'tzid' => $zone,
                        'start_utc' => Time::toDb($o['start']->add(new DateInterval($shift))), 'end_utc' => Time::toDb($o['end']->add(new DateInterval($shift))),
                        'rrule' => null, 'exdates_json' => null, 'recurrence_instance_utc' => $key($o['start']), 'recurrence_parent_id' => $n,
                        'status' => 'confirmed', 'description' => null, 'location' => null, 'url' => null, 'reminders_json' => null,
                    ];
                }
                $windows = [
                    'early' => [$s->modify('-2 days'), $s->modify('+400 days')],
                    'late' => [new DateTimeImmutable('2026-09-01 00:00:00', Time::utc()), new DateTimeImmutable('2027-04-15 00:00:00', Time::utc())],
                ];
                $out = [];
                foreach ($windows as $wname => [$ws, $we]) {
                    $occ = (new Recurrence())->expand($master, $overrides, $ws, $we);
                    $list = [];
                    foreach ($occ as $o) {
                        $list[] = fmt($o['start'], $o['end'], $allDay && (int) $o['row']['all_day'] === 1, (string) $o['row']['tzid']);
                    }
                    sort($list);
                    $out[$wname] = ['from' => Time::toDb($ws), 'to' => Time::toDb($we), 'occ' => $list];
                }
                $cases[] = [
                    'n' => $n, 'zone' => $zone, 'start' => $date, 'time' => $time, 'rrule' => $rrule, 'allDay' => $allDay,
                    'exdates' => $master['exdates_json'], 'override' => $overrides[0]['recurrence_instance_utc'] ?? null,
                    'ics' => Ics::buildCalendar('Harness', null, array_merge([$master], $overrides)),
                    'bettercal' => $out,
                ];
            }
        }
    }
}

function fmt(DateTimeImmutable $s, DateTimeImmutable $e, bool $allDay, string $tzid): string
{
    if ($allDay) {
        $z = new DateTimeZone($tzid);
        return 'D' . $s->setTimezone($z)->format('Ymd') . '/' . $e->setTimezone($z)->format('Ymd');
    }
    return $s->setTimezone(Time::utc())->format('Ymd\THis\Z') . '/' . $e->setTimezone(Time::utc())->format('Ymd\THis\Z');
}

file_put_contents(__DIR__ . '/cases.json', json_encode($cases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo count($cases) . " cases\n";
