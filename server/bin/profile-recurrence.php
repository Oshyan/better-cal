<?php

declare(strict_types=1);

// Second half of the GH #10 profile: attributes recurrence-expansion cost to
// individual masters, and splits per-master time into "build the sabre object"
// versus "fast-forward from DTSTART to the window". The split decides the fix:
// object churn wants caching, fast-forward wants a cheaper way to reach the
// window.
//
//   php bin/profile-recurrence.php [--months=5] [--top=15]

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Domain\Recurrence;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

$opt = getopt('', ['months::', 'top::', 'user::']);
$months = max(1, (int) ($opt['months'] ?? 5));
$top = max(1, (int) ($opt['top'] ?? 15));
$userId = max(1, (int) ($opt['user'] ?? 1));

$cfg = config();
$db = new Db($cfg['db']);
$start = new DateTimeImmutable('first day of this month 00:00:00');
$end = $start->add(new DateInterval('P' . $months . 'M'));
$ms = static fn (float $t): float => (microtime(true) - $t) * 1000;

$masters = $db->all(
    'SELECT * FROM events WHERE user_id = ? AND deleted_at IS NULL AND recurrence_parent_id IS NULL
       AND rrule IS NOT NULL AND rrule <> "" AND start_utc < ?',
    [$userId, Time::toDb($end)]
);
echo count($masters) . " recurring masters, window {$start->format('Y-m-d')} -> {$end->format('Y-m-d')}\n\n";

$rows = [];
$totalBuild = 0.0;
$totalFF = 0.0;
$totalWalk = 0.0;

foreach ($masters as $m) {
    $allDay = (int) ($m['all_day'] ?? 0) === 1;
    $tz = Time::zone($m['tzid'] ?? null);
    $s = Time::fromDb((string) $m['start_utc'])->setTimezone($tz);
    $e = Time::fromDb((string) $m['end_utc'])->setTimezone($tz);
    $uid = (string) ($m['uid'] ?? 'x');

    $t = microtime(true);
    $vcal = new \Sabre\VObject\Component\VCalendar();
    $ve = $vcal->add('VEVENT', ['UID' => $uid]);
    if ($allDay) {
        $ve->add('DTSTART', $s->format('Ymd'), ['VALUE' => 'DATE']);
        $ve->add('DTEND', $e->format('Ymd'), ['VALUE' => 'DATE']);
    } else {
        $ve->add('DTSTART', $s);
        $ve->add('DTEND', $e);
    }
    $ve->add('RRULE', (string) $m['rrule']);
    $it = new \Sabre\VObject\Recur\EventIterator($vcal, $uid, Time::utc());
    $buildMs = $ms($t);

    $t = microtime(true);
    $it->fastForward(\DateTime::createFromImmutable($start));
    $ffMs = $ms($t);

    $t = microtime(true);
    $n = 0;
    while ($it->valid() && $n < 2000) {
        $occStart = \DateTimeImmutable::createFromInterface($it->getDtStart())->setTimezone(Time::utc());
        if ($occStart >= $end) {
            break;
        }
        $n++;
        $it->next();
    }
    $walkMs = $ms($t);

    $totalBuild += $buildMs;
    $totalFF += $ffMs;
    $totalWalk += $walkMs;

    $ageDays = (int) (($start->getTimestamp() - Time::fromDb((string) $m['start_utc'])->getTimestamp()) / 86400);
    $rows[] = [
        'id' => (int) $m['id'],
        'title' => mb_substr((string) $m['title'], 0, 30),
        'rrule' => mb_substr((string) $m['rrule'], 0, 42),
        'ageDays' => $ageDays,
        'build' => $buildMs,
        'ff' => $ffMs,
        'walk' => $walkMs,
        'total' => $buildMs + $ffMs + $walkMs,
        'occ' => $n,
    ];
}

printf("build sabre objects ....... %6.0fms  (%.0f%%)\n", $totalBuild, 100 * $totalBuild / max(0.001, $totalBuild + $totalFF + $totalWalk));
printf("fastForward to window ..... %6.0fms  (%.0f%%)\n", $totalFF, 100 * $totalFF / max(0.001, $totalBuild + $totalFF + $totalWalk));
printf("walk inside window ........ %6.0fms  (%.0f%%)\n\n", $totalWalk, 100 * $totalWalk / max(0.001, $totalBuild + $totalFF + $totalWalk));

usort($rows, static fn ($a, $b) => $b['total'] <=> $a['total']);
printf("%-6s %-30s %8s %7s %7s %7s %5s  %s\n", 'id', 'title', 'ageDays', 'build', 'ffwd', 'walk', 'occ', 'rrule');
foreach (array_slice($rows, 0, $top) as $r) {
    printf("%-6d %-30s %8d %6.1f %6.1f %6.1f %5d  %s\n",
        $r['id'], $r['title'], $r['ageDays'], $r['build'], $r['ff'], $r['walk'], $r['occ'], $r['rrule']);
}

// Does fast-forward cost track how old the series is?
$old = array_filter($rows, static fn ($r) => $r['ageDays'] > 365);
$new = array_filter($rows, static fn ($r) => $r['ageDays'] <= 365);
$avg = static fn (array $set, string $k): float => $set === [] ? 0.0 : array_sum(array_column($set, $k)) / count($set);
printf("\nseries older than a year (%d): avg ffwd %.2fms\n", count($old), $avg($old, 'ff'));
printf("series under a year   (%d): avg ffwd %.2fms\n", count($new), $avg($new, 'ff'));
