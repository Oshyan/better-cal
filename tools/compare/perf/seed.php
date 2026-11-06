<?php
// Seeds one checkout's database for bench.php, through that checkout's own
// domain code, so each version stores the same invented calendar its own way.
//
//   php seed.php <run-copy server dir> <fixture.json out> <small|full>
//
// The database is the one BETTERCAL_DB_DSN names (empty; migrated here with
// the checkout's own migrations). Everything is drawn from a fixed seed: the
// same series, the same edited and skipped days, on every version. The
// days are picked by the rule (sabre/vobject's iterator), then looked up in
// the version's own window read, and the edit is sent with that version's
// own occurrence start, as the client would send it.

declare(strict_types=1);

[$_, $serverDir, $fixtureOut, $size] = $argv + [null, null, null, 'full'];
require $serverDir . '/src/bootstrap.php';

use BetterCal\Domain;
use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;
use BetterCal\Infra\Migrator;
use BetterCal\Infra\Setup;

$cfg = config();
$db = new Db($cfg['db']);
(new Migrator($db, $serverDir . '/migrations'))->run(static function (string $line): void {
});

$email = 'sam.sample@example.test';
$password = 'invented-sample-password-1';
$userId = Setup::createOwner($db, $email, $password, 'Sam Sample');
$calendarId = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$userId]);
$largeCalendarId = $db->insert('calendars', ['user_id' => $userId, 'name' => 'Large sample', 'color' => '#2a9d8f', 'kind' => 'local']);
$session = (new Domain\Auth($db, $cfg))->login($email, $password);
$token = (new Domain\ApiTokens($db))->create($userId, 'perf bench')['token'];

$undo = new Domain\Undo($db);
$jobQueue = new JobQueue($db);
$labels = new Domain\Labels($db);
$filters = new Domain\Filters($db, $undo, $jobQueue);
$trips = new Domain\Trips($db, $undo);
$events = new Domain\Events($db, new Domain\Recurrence(), $undo, $labels, $filters, $trips);

// Its own generator: the app's code draws from the global one too (1.5.1 does), which shifted the global stream between versions.
$rng = new Random\Randomizer(new Random\Engine\Mt19937(20261106));
$pick = static fn(array $a) => $a[$rng->getInt(0, count($a) - 1)];
$zones = ['America/Los_Angeles', 'America/New_York', 'Europe/Berlin', 'UTC', 'Asia/Tokyo'];
$days = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
$utc = new DateTimeZone('UTC');
// No drawn edit lands on the days the benchmark acts on.
$reserved = ['2026-11-30', '2026-12-01', '2026-12-02', '2026-12-03'];

/**
 * A spec: title, calendar, zone, local start (Y-m-d H:i), minutes long or
 * null for all-day, rule, and how many days to edit and skip.
 */
$specs = [];
// The ordinary series the benchmark edits: fixed, not drawn.
$specs[] = ['key' => 'ordinary', 'title' => 'Sample standup', 'cal' => 'main', 'tz' => 'America/Los_Angeles', 'start' => '2024-03-05 10:00',
    'minutes' => 30, 'rrule' => 'FREQ=WEEKLY;BYDAY=TU,TH', 'edits' => 3, 'skips' => 1];
$ordinaryCount = $size === 'small' ? 20 : 200;
for ($i = 1; $i < $ordinaryCount; $i++) {
    $tz = $pick($zones);
    $y = $rng->getInt(2018, 2026);
    $m = $y === 2026 ? $rng->getInt(1, 9) : $rng->getInt(1, 12);
    $d = $rng->getInt(1, 28);
    $clock = sprintf('%02d:%02d', $rng->getInt(7, 20), $pick([0, 15, 30, 45]));
    $minutes = $pick([30, 45, 60, 90, 120]);
    $first = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $d));
    $r = $rng->getInt(1, 100);
    if ($r <= 20) {
        $rrule = 'FREQ=DAILY' . ($rng->getInt(0, 1) ? ';INTERVAL=' . $rng->getInt(2, 3) : '');
    } elseif ($r <= 55) {
        $own = strtoupper(substr($first->format('D'), 0, 2));
        $list = array_values(array_unique(array_merge([$own], array_map(static fn() => $days[$rng->getInt(0, 4)], range(1, $rng->getInt(0, 2))))));
        usort($list, static fn($a, $b) => array_search($a, $days, true) <=> array_search($b, $days, true));
        $rrule = 'FREQ=WEEKLY' . ($rng->getInt(1, 4) === 1 ? ';INTERVAL=2' : '') . ';BYDAY=' . implode(',', $list);
    } elseif ($r <= 70) {
        $rrule = 'FREQ=MONTHLY;BYMONTHDAY=' . $d;
    } elseif ($r <= 85) {
        // A numbered weekday of the month: the start is put on its first one.
        [$byday, $pos, $phrase] = $pick([
            ['TU', 2, 'second tuesday of'], ['TH', 1, 'first thursday of'], ['MO', -1, 'last monday of'], ['WE', 3, 'third wednesday of'],
            ['MO,TU,WE,TH,FR', -1, null],
        ]);
        $month = $first->format('Y-m');
        if ($phrase !== null) {
            $first = new DateTimeImmutable("$phrase $month");
        } else {
            $first = new DateTimeImmutable("last day of $month");
            while ((int) $first->format('N') > 5) {
                $first = $first->modify('-1 day');
            }
        }
        $rrule = "FREQ=MONTHLY;BYDAY=$byday;BYSETPOS=$pos";
    } else {
        $rrule = 'FREQ=YEARLY';
        if ($rng->getInt(1, 10) <= 4) {
            $minutes = null;
        }
    }
    // Half bounded: a count, or an end date (some long past).
    $b = $rng->getInt(1, 4);
    if ($b === 1) {
        $rrule .= ';COUNT=' . $rng->getInt(5, 120);
    } elseif ($b === 2) {
        $until = $first->modify('+' . $rng->getInt(6, 60) . ' months');
        $rrule .= ';UNTIL=' . ($minutes === null ? $until->format('Ymd') : $until->format('Ymd') . 'T235959Z');
    }
    $specs[] = ['key' => "s$i", 'title' => "Sample series $i", 'cal' => 'main', 'tz' => $minutes === null ? 'UTC' : $tz,
        'start' => $first->format('Y-m-d') . ' ' . ($minutes === null ? '00:00' : $clock), 'minutes' => $minutes, 'rrule' => $rrule,
        'edits' => $rng->getInt(0, 3), 'skips' => $rng->getInt(0, 2)];
}
// The large ones: 1,000 occurrences and more, dozens of edited days.
$large = [
    ['key' => 'large', 'title' => 'Sample daily check-in', 'tz' => 'America/Los_Angeles', 'start' => '2022-01-03 09:00', 'minutes' => 30, 'rrule' => 'FREQ=DAILY', 'edits' => 60, 'skips' => 20],
    ['key' => 'large2', 'title' => 'Sample training', 'tz' => 'Europe/Berlin', 'start' => '2019-01-07 18:00', 'minutes' => 90, 'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE,FR', 'edits' => 40, 'skips' => 15],
    ['key' => 'large3', 'title' => 'Sample log', 'tz' => 'UTC', 'start' => '2023-01-02 07:30', 'minutes' => 15, 'rrule' => 'FREQ=DAILY;COUNT=1500', 'edits' => 30, 'skips' => 10],
    ['key' => 'large4', 'title' => 'Sample lunch block', 'tz' => 'America/New_York', 'start' => '2021-06-01 12:00', 'minutes' => 60, 'rrule' => 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR', 'edits' => 50, 'skips' => 20],
    ['key' => 'large5', 'title' => 'Sample day note', 'tz' => 'UTC', 'start' => '2023-06-01 00:00', 'minutes' => null, 'rrule' => 'FREQ=DAILY;UNTIL=20270601', 'edits' => 24, 'skips' => 12],
];
if ($size === 'small') {
    $large = array_slice($large, 0, 2);
    foreach ($large as &$l) {
        $l['edits'] = 5;
        $l['skips'] = 2;
    }
    unset($l);
}
foreach ($large as $l) {
    $specs[] = $l + ['cal' => 'large'];
}

/** The rule's local starts (sabre/vobject), up to the end of 2027 and 2,000 at most. */
$ruleDays = static function (array $s): array {
    $tz = new DateTimeZone($s['tz']);
    $it = new Sabre\VObject\Recur\RRuleIterator($s['rrule'], new DateTimeImmutable($s['start'], $tz));
    $out = [];
    $stop = new DateTimeImmutable('2028-01-01', $tz);
    foreach ($it as $dt) {
        if ($dt >= $stop || count($out) >= 2000) {
            break;
        }
        $out[] = $dt;
    }
    return $out;
};

/** The local date an occurrence's start shows on: an all-day date as sent, a timed instant in the series' zone. */
$localDay = static fn(string $start, DateTimeZone $tz): string => strlen($start) === 10 ? $start : (new DateTimeImmutable($start))->setTimezone($tz)->format('Y-m-d');

$ids = [];
// Every drawn day and edit, hashed into the fixture: equal on every version, or the data differs.
$draws = [];
$made = ['series' => 0, 'edited' => 0, 'skipped' => 0, 'missed' => 0, 'occurrences' => 0, 'kinds' => []];
foreach ($specs as $s) {
    $tz = new DateTimeZone($s['tz']);
    $start = new DateTimeImmutable($s['start'], $tz);
    $allDay = $s['minutes'] === null;
    $in = ['calendarId' => $s['cal'] === 'large' ? $largeCalendarId : $calendarId, 'title' => $s['title'], 'rrule' => $s['rrule'], 'allDay' => $allDay];
    if ($allDay) {
        $in += ['start' => $start->format('Y-m-d'), 'end' => $start->modify('+1 day')->format('Y-m-d'), 'tzid' => 'UTC'];
    } else {
        $in += ['start' => $start->format(DATE_ATOM), 'end' => $start->modify('+' . $s['minutes'] . ' minutes')->format(DATE_ATOM), 'tzid' => $s['tz']];
    }
    $occ = $events->create($userId, $in);
    $id = (int) $occ['eventId'];
    $ids[$s['key']] = $id;
    $made['series']++;
    $kind = explode(';', $s['rrule'])[0] . (str_contains($s['rrule'], 'BYSETPOS') ? '+BYSETPOS' : (str_contains($s['rrule'], 'BYDAY') ? '+BYDAY' : ''))
        . (str_contains($s['rrule'], 'COUNT') || str_contains($s['rrule'], 'UNTIL') ? ' bounded' : ' unbounded');
    $made['kinds'][$kind] = ($made['kinds'][$kind] ?? 0) + 1;

    $all = $ruleDays($s);
    $made['occurrences'] += count($all);
    $candidates = array_values(array_filter($all, static fn(DateTimeImmutable $dt) => !in_array($dt->format('Y-m-d'), $reserved, true)));
    if (count($candidates) < 3) {
        continue;
    }
    // Days chosen once, skips and edits apart. A large series gets a share
    // of its edits inside the year the benchmark reads.
    $want = $s['edits'] + $s['skips'];
    $chosen = [];
    $inYear = array_keys(array_filter($candidates, static fn(DateTimeImmutable $dt) => $dt->format('Y-m-d') >= '2026-11-01' && $dt->format('Y-m-d') < '2027-11-01'));
    if ($s['cal'] === 'large' && $inYear !== []) {
        for ($k = 0; $k < intdiv($want, 4) && count($chosen) < count($inYear); $k++) {
            $chosen[$inYear[$rng->getInt(0, count($inYear) - 1)]] = true;
        }
    }
    for ($guard = 0; count($chosen) < min($want, count($candidates) - 1) && $guard < 10000; $guard++) {
        $chosen[$rng->getInt(1, count($candidates) - 1)] = true;
    }
    $chosen = array_keys($chosen);
    sort($chosen);
    $roles = [];
    $skipsLeft = $s['skips'];
    foreach ($chosen as $idx) {
        $skip = $skipsLeft > 0 && $rng->getInt(1, $want) <= $s['skips'];
        $roles[$idx] = $skip ? 'skip' : 'edit';
        $skipsLeft -= $skip ? 1 : 0;
    }
    foreach ($roles as $idx => $role) {
        $day = $candidates[$idx];
        // Drawn before the lookup, so a day one version can't find leaves the draws of the rest alone.
        $what = $rng->getInt(1, 10);
        $draws[] = [$s['key'], $role, $day->format(DATE_ATOM), $what];
        // The version's own occurrence of that day: its start, as the client sends it back.
        $from = $day->setTime(0, 0)->modify('-1 day')->setTimezone($utc);
        $to = $day->setTime(0, 0)->modify('+2 days')->setTimezone($utc);
        $found = null;
        foreach ($events->window($userId, $from, $to, [$in['calendarId']], null, true) as $o) {
            if ((int) $o['eventId'] === $id && empty($o['isException']) && $localDay((string) $o['start'], $tz) === $day->format('Y-m-d')) {
                $found = $o;
                break;
            }
        }
        if ($found === null) {
            $made['missed']++;
            continue;
        }
        if ($role === 'skip') {
            $events->deleteEvent($userId, $id, 'this', (string) $found['start']);
            $made['skipped']++;
            continue;
        }
        $patch =['scope' => 'this', 'instanceStart' => (string) $found['start']];
        if ($what <= 5 || $allDay) {
            $patch['title'] = $s['title'] . ' (edited)';
        } elseif ($what <= 8) {
            $patch['start'] = (new DateTimeImmutable((string) $found['start']))->modify('+1 hour')->format(DATE_ATOM);
            $patch['end'] = (new DateTimeImmutable((string) $found['end']))->modify('+1 hour')->format(DATE_ATOM);
        } else {
            $patch['location'] = 'Sample Room ' . $rng->getInt(1, 9);
        }
        $events->patch($userId, $id, $patch);
        $made['edited']++;
    }
}

ksort($made['kinds']);
file_put_contents($fixtureOut, json_encode([
    'version' => trim((string) @file_get_contents(dirname($serverDir) . '/VERSION')) ?: null,
    'userId' => $userId,
    'email' => $email,
    'calendarId' => $calendarId,
    'largeCalendarId' => $largeCalendarId,
    'session' => $session['token'],
    'csrf' => $session['csrf'],
    'token' => $token,
    'ids' => $ids,
    'made' => $made + ['draws' => md5(json_encode($draws)) . ':' . count($draws), 'specs' => md5(json_encode($specs))],
    'rows' => [
        'events' => (int) $db->scalar('SELECT COUNT(*) FROM events WHERE user_id = ?', [$userId]),
        'masters' => (int) $db->scalar('SELECT COUNT(*) FROM events WHERE user_id = ? AND rrule IS NOT NULL', [$userId]),
        'mutations' => (int) $db->scalar('SELECT COUNT(*) FROM mutations'),
    ],
], JSON_PRETTY_PRINT));
echo json_encode($made), "\n";
