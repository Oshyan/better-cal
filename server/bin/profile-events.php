<?php

declare(strict_types=1);

// Profiles the events-window read path (GH #10) against real data, so a fix
// targets the measured cost rather than the suspected one.
//
//   php bin/profile-events.php [--months=5] [--runs=3] [--user=1]
//
// Replays window()'s phases with the same collaborators it uses, which keeps
// the instrumentation out of production code while still attributing time to
// the stage that spends it.

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Domain;
use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;
use BetterCal\Support\Time;

$opt = getopt('', ['months::', 'runs::', 'user::']);
$months = max(1, (int) ($opt['months'] ?? 5));
$runs = max(1, (int) ($opt['runs'] ?? 3));
$userId = max(1, (int) ($opt['user'] ?? 1));

$cfg = config();
$db = new Db($cfg['db']);
$undo = new Domain\Undo($db);
$labels = new Domain\Labels($db);
$recurrence = new Domain\Recurrence();
$queue = new JobQueue($db);
$filters = new Domain\Filters($db, $undo, $queue);
$trips = new Domain\Trips($db, $undo);
$events = new Domain\Events($db, $recurrence, $undo, $labels, $filters, $trips);

$start = new DateTimeImmutable('first day of this month 00:00:00');
$end = $start->add(new DateInterval('P' . $months . 'M'));
$ms = static fn (float $t): float => (microtime(true) - $t) * 1000;

echo "window {$start->format('Y-m-d')} -> {$end->format('Y-m-d')} ({$months}mo)\n\n";

$events->window($userId, $start, $end, null, null, false); // warm

$totals = [];
for ($i = 0; $i < $runs; $i++) {
    $t = microtime(true);
    $out = $events->window($userId, $start, $end, null, null, false);
    $totals[] = $ms($t);
}
sort($totals);
printf("Events::window .............. %.0fms median  (%d occurrences)\n\n",
    $totals[intdiv(count($totals), 2)], count($out));

// ---- phase replay ---------------------------------------------------------
$t = microtime(true);
$masters = $db->all(
    'SELECT * FROM events WHERE user_id = ? AND deleted_at IS NULL AND recurrence_parent_id IS NULL
       AND ((rrule IS NULL AND start_utc < ? AND end_utc > ?) OR (rrule IS NOT NULL AND start_utc < ?))',
    [$userId, Time::toDb($end), Time::toDb($start), Time::toDb($end)]
);
$sqlMs = $ms($t);

$t = microtime(true);
[$in, $inParams] = Db::in(array_map(static fn ($r) => (int) $r['id'], $masters) ?: [0]);
$overrides = $db->all(
    "SELECT * FROM events WHERE user_id = ? AND deleted_at IS NULL AND recurrence_parent_id IS NOT NULL
       AND (recurrence_parent_id IN $in OR (start_utc < ? AND end_utc > ?))",
    [$userId, ...$inParams, Time::toDb($end), Time::toDb($start)]
);
$sqlMs += $ms($t);
printf("  SQL (masters + overrides) . %6.0fms   %d masters, %d overrides\n", $sqlMs, count($masters), count($overrides));

$ovByParent = [];
foreach ($overrides as $ov) {
    $ovByParent[(int) $ov['recurrence_parent_id']][] = $ov;
}

$t = microtime(true);
$expanded = [];
$recurringMasters = 0;
$fromRecurring = 0;
foreach ($masters as $master) {
    $before = count($expanded);
    foreach ($recurrence->expand($master, $ovByParent[(int) $master['id']] ?? [], $start, $end) as $occ) {
        $expanded[] = $occ;
    }
    if (!empty($master['rrule'])) {
        $recurringMasters++;
        $fromRecurring += count($expanded) - $before;
    }
}
printf("  recurrence expansion ...... %6.0fms   %d masters (%d recurring) -> %d occurrences (%d from rrules)\n",
    $ms($t), count($masters), $recurringMasters, count($expanded), $fromRecurring);

$t = microtime(true);
usort($expanded, static fn (array $a, array $b): int =>
    [$a['start']->getTimestamp(), $b['end']->getTimestamp(), (string) $a['row']['title']]
    <=> [$b['start']->getTimestamp(), $a['end']->getTimestamp(), (string) $b['row']['title']]);
printf("  sort ...................... %6.0fms\n", $ms($t));

$t = microtime(true);
$activeFilters = $filters->enabledForUser($userId);
$windowRows = [];
foreach ($expanded as $occ) {
    $windowRows[(int) $occ['row']['id']] ??= $occ['row'];
}
$promptCtx = $filters->promptFilterContext($userId, array_keys($windowRows));
printf("  filter context ............ %6.0fms   %d active filters, %d distinct events\n",
    $ms($t), count($activeFilters), count($windowRows));

$t = microtime(true);
foreach ($expanded as $occ) {
    Domain\Filters::disposition($occ['row'], $activeFilters);
    Domain\Filters::promptDisposition($occ['row'], $promptCtx['filters'], $promptCtx['failed']);
}
printf("  filter evaluation ......... %6.0fms   over %d occurrences\n", $ms($t), count($expanded));

$eventIds = [];
foreach ($expanded as $occ) {
    $eventIds[(int) $occ['row']['id']] = true;
}
$t = microtime(true);
$links = $labels->forEvents(array_keys($eventIds));
$links['containers'] = $trips->containersFor(array_keys($eventIds));
printf("  labels + trips batch ...... %6.0fms   %d distinct events\n", $ms($t), count($eventIds));

// ---- what the client actually receives ------------------------------------
$t = microtime(true);
$json = json_encode(['events' => $out]);
$jsonMs = $ms($t);
printf("\n  json_encode ............... %6.0fms   %s payload\n",
    $jsonMs, number_format(strlen($json) / 1024, 0) . ' KB');
printf("  bytes per occurrence ...... %6.0f\n", strlen($json) / max(1, count($out)));

// ---- how the cost scales --------------------------------------------------
echo "\nscaling (median of 3, same process):\n";
foreach ([1, 2, 5, 12] as $m) {
    $e2 = $start->add(new DateInterval('P' . $m . 'M'));
    $ts = [];
    for ($i = 0; $i < 3; $i++) {
        $t = microtime(true);
        $r = $events->window($userId, $start, $e2, null, null, false);
        $ts[] = $ms($t);
    }
    sort($ts);
    printf("  %2dmo  %6.0fms  %5d occurrences  %5.2f ms/occurrence\n",
        $m, $ts[1], count($r), $ts[1] / max(1, count($r)));
}
