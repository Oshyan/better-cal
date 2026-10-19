<?php
declare(strict_types=1);

// Optional comparison against a prior checkout, using only invented events.
if (PHP_SAPI !== 'cli') exit(2);
require __DIR__ . '/../src/bootstrap.php';
if (!isset($argv[1]) || !is_file($argv[1])) throw new RuntimeException('Pass the prior Trip Planner Plugin.php path');
$old = require $argv[1];
$new = require __DIR__ . '/../plugins/trip-planner/Plugin.php';
$call = static fn($p, $m, $args) => (new ReflectionMethod($p, $m))->invokeArgs($p, $args);
$occ = [];
for ($i = 0; $i < 80; $i++) {
    $start = (new DateTimeImmutable('2027-01-01T09:00:00Z'))->modify('+' . ($i % 60) . ' days');
    $occ[] = ['calendarId' => 1 + $i % 3, 'title' => 'Sample ' . ($i % 19), 'start' => $start->format(DATE_ATOM), 'end' => $start->modify('+2 hours')->format(DATE_ATOM), 'recurring' => $i % 5 === 0];
}
$policies = [1 => ['policy' => 'soft', 'name' => 'Sample soft'], 2 => ['policy' => 'block', 'name' => 'Sample block'], 3 => ['policy' => 'ignore', 'name' => 'Sample ignored']];
$cfg = ['recurringBlocks' => false, 'shortEventMinutes' => 240];
$dates = []; for ($i = 0; $i < 90; $i++) $dates[] = (new DateTimeImmutable('2027-01-01'))->modify("+$i days")->format('Y-m-d');
$results = [];
foreach ([$old, $new] as $p) {
    $load = $call($p, 'classifyDays', [$occ, $policies, new DateTimeZone('UTC'), $cfg, $dates[0], $dates[89]]);
    $warnings = []; $call($p, 'autoQuietNoisyCalendars', [&$load, $dates[0], $dates[89], &$warnings]);
    $runs = $call($p, 'freeRuns', [$dates, $load['hard']]);
    $cands = $call($p, 'candidates', [$dates, $runs, $load, null, 3, 10, 1, $cfg]);
    usort($cands, static fn($a, $b) => $a['score'] === $b['score'] ? strcmp($a['start'], $b['start']) : $b['score'] <=> $a['score']);
    $picked = $call($p, 'pickDistinct', [$cands, 3]);
    if (method_exists($p, 'describeCandidate')) {
        foreach ($picked as &$c) $call($p, 'describeCandidate', [&$c, $load]);
        unset($c);
    }
    $results[] = [$picked, $warnings];
}
if (count($results[0][0]) !== count($results[1][0]) || $results[0][1] !== $results[1][1]) throw new RuntimeException('Planner selection or quiet-warning mismatch');
foreach ($results[0][0] as $i => $candidate) {
    // Candidate field order is not visible. Title/reason map order is, and
    // their values are compared strictly along with every score and date.
    foreach ($candidate as $field => $value) {
        if ($value !== ($results[1][0][$i][$field] ?? null)) throw new RuntimeException('Planner compatibility mismatch: ' . $field);
    }
}
echo "Planner selected windows, scores, reasons and quiet-calendar warnings match baseline exactly\n";
