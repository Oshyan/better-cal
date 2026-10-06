<?php
// Round trip: Better-Cal export -> Better-Cal import (Ics::parse, the CalDAV/import door).
// Reports (1) representation changes in the stored columns, (2) any change in the
// expanded occurrences (the thing a user would see).
declare(strict_types=1);
require dirname(__DIR__, 2) . '/server/vendor/autoload.php';
use BetterCal\Domain\Ics;
use BetterCal\Domain\Recurrence;
use BetterCal\Support\Time;

$cases = json_decode(file_get_contents(__DIR__ . '/cases.json'), true);
$repr = [];
$semantic = 0;
$examples = [];
foreach ($cases as $c) {
    $parsed = Ics::parse($c['ics'], 'America/Los_Angeles');
    $master = null; $ovs = [];
    foreach ($parsed as $i => $p) {
        $row = ['id' => $i + 1, 'uid' => $p['uid'], 'all_day' => $p['all_day'], 'tzid' => $p['tzid'], 'start_utc' => $p['start_utc'], 'end_utc' => $p['end_utc'],
            'rrule' => $p['rrule'], 'exdates_json' => $p['exdates'] ? json_encode($p['exdates']) : null, 'recurrence_instance_utc' => $p['recurrence_instance_utc']];
        if ($p['recurrence_instance_utc'] === null) { $master = $row; } else { $ovs[] = $row; }
    }
    // representation drift
    $origEx = $c['exdates'] ? json_decode($c['exdates'], true) : [];
    $d = [];
    $stored = $c['time'] === 'allday' ? 'UTC' : $c['zone']; // all-day rows are stored as UTC dates (0.9.16)
    if ($master['tzid'] !== $stored) $d[] = 'tzid ' . $stored . '->' . $master['tzid'];
    if ($master['exdates_json'] !== null && json_decode($master['exdates_json'], true) !== $origEx) $d[] = 'exdate key';
    if ($c['override'] !== null && ($ovs[0]['recurrence_instance_utc'] ?? null) !== $c['override']) $d[] = 'override key';
    foreach ($d as $k) { $bucket = ($c['allDay'] ? 'allday ' : 'timed ') . preg_replace('/ [^ ]+->/', ' ->', $k); $repr[$bucket] = ($repr[$bucket] ?? 0) + 1; }
    // semantic drift: expand again and compare with the original expansion
    foreach ($c['bettercal'] as $wname => $w) {
        $occ = (new Recurrence())->expand($master, $ovs, Time::fromDb($w['from']), Time::fromDb($w['to']));
        $list = [];
        foreach ($occ as $o) {
            $ad = (int) $o['row']['all_day'] === 1;
            $z = new DateTimeZone($o['row']['tzid']);
            $list[] = $ad ? 'D' . $o['start']->setTimezone($z)->format('Ymd') . '/' . $o['end']->setTimezone($z)->format('Ymd')
                : $o['start']->format('Ymd\THis\Z') . '/' . $o['end']->format('Ymd\THis\Z');
        }
        sort($list);
        // all-day windows are instants; compare away from the edges
        $trim = static fn(array $l) => array_values(array_filter($l, static fn($v) => substr($v, $v[0] === 'D' ? 1 : 0, 8) > Time::fromDb($w['from'])->modify('+2 days')->format('Ymd') && substr($v, $v[0] === 'D' ? 1 : 0, 8) < Time::fromDb($w['to'])->modify('-2 days')->format('Ymd')));
        if ($trim($list) !== $trim($w['occ'])) {
            $semantic++;
            if (count($examples) < 6) $examples[] = "#{$c['n']} {$c['zone']} {$c['start']} {$c['time']} {$c['rrule']} $wname: only before=" . implode(' ', array_slice(array_diff($trim($w['occ']), $trim($list)), 0, 3)) . ' only after=' . implode(' ', array_slice(array_diff($trim($list), $trim($w['occ'])), 0, 3));
        }
    }
}
arsort($repr);
echo "representation changes after export->import:\n";
foreach ($repr as $k => $n) echo "  $n  $k\n";
echo "case-windows whose occurrences changed: $semantic\n" . implode("\n", $examples) . "\n";
