<?php
// Puts bench.php's result files side by side, one Markdown table per operation.
//
//   php tools/compare/perf/report.php FILE...
//
// Files are grouped by version: a --count run gives the statement and row
// counts, the other runs wall time, memory and RSS. Wall times are shown
// only from runs bench.php marked --idle (the machine and database had
// nothing else running); otherwise "contended".
//
// Per operation and role (preview, commit, preview + commit, undo, read):
// wall = the role's requests' time added up, median and p95 over the
// repetitions (p95 by nearest rank: with 5 the slowest); peak = the largest
// memory_get_peak_usage(true) of the role's requests, used = the same
// without true; RSS = the largest maximum resident set size; q = statements
// sent; rows = rows written (inserted, updated, deleted).

declare(strict_types=1);

$files = array_slice($argv, 1);
$by = [];
foreach ($files as $f) {
    $j = json_decode((string) file_get_contents($f), true);
    $v = (string) $j['version'];
    $by[$v][$j['count'] ? 'count' : 'run'] = $j;
}
uksort($by, 'version_compare');
$versions = array_keys($by);

$pct = static function (array $xs, float $p): ?float {
    if ($xs === []) {
        return null;
    }
    sort($xs);
    return $xs[max(0, (int) ceil($p * count($xs)) - 1)];
};
/** One role's figure per repetition: the requests of $roles in that repetition, combined. */
$per = static function (array $runs, array $roles, callable $combine): array {
    $out = [];
    foreach ($runs as $steps) {
        $mine = array_values(array_filter($steps, static fn($s) => in_array($s['role'], $roles, true)));
        if ($mine !== []) {
            $out[] = $combine($mine);
        }
    }
    return $out;
};
$mb = static fn(?float $b): string => $b === null ? '' : sprintf('%.1f', $b / 1048576);

$names = [];
foreach ($by as $v => $kinds) {
    foreach ($kinds as $j) {
        foreach (array_keys($j['ops']) as $n) {
            $names[$n] = true;
        }
    }
}
$names = array_keys($names);

echo "| version | series | edited | skipped | events rows | opcache | wall from |\n|---|---|---|---|---|---|---|\n";
foreach ($by as $v => $kinds) {
    $j = $kinds['run'] ?? $kinds['count'];
    printf("| %s | %d | %d | %d | %d | %s | %s |\n", $v, $j['made']['series'], $j['made']['edited'], $j['made']['skipped'], $j['rows']['events'],
        $j['opcache'] ? 'file cache' : 'off', isset($kinds['run']) && $kinds['run']['idle'] ? 'idle run' : 'contended (not shown)');
}
echo "\n";

foreach ($names as $n) {
    echo "### $n\n\n| role | figure | " . implode(' | ', $versions) . " |\n|---|---|" . str_repeat('---|', count($versions)) . "\n";
    $roleSets = str_starts_with($n, 'edit.')
        ? ['preview' => ['preview'], 'commit' => ['commit'], 'preview + commit' => ['preview', 'commit'], 'undo' => ['undo']]
        : ['read' => ['read']];
    foreach ($roleSets as $roleName => $roles) {
        $rows = ['wall median ms' => [], 'wall p95 ms' => [], 'peak MB' => [], 'used MB' => [], 'RSS MB' => [], 'requests' => [], 'queries' => [], 'rows written' => [], 'items' => [], 'answer' => []];
        $any = false;
        foreach ($versions as $v) {
            $run = $by[$v]['run']['ops'][$n] ?? null;
            $cnt = $by[$v]['count']['ops'][$n] ?? null;
            $err = (is_array($run) && isset($run['error'])) ? $run['error'] : ((is_array($cnt) && isset($cnt['error'])) ? $cnt['error'] : null);
            if ($err !== null) {
                foreach ($rows as $k => $_) {
                    $rows[$k][$v] = $k === 'wall median ms' ? 'error' : '';
                }
                continue;
            }
            $run = $run ?? [];
            $cnt = $cnt ?? [];
            $ms = $per($run, $roles, static fn($m) => array_sum(array_column($m, 'ms')));
            $peak = $per($run, $roles, static fn($m) => max(array_column($m, 'peak')));
            $used = $per($run, $roles, static fn($m) => max(array_column($m, 'peakUsed')));
            $rss = $per($run, $roles, static fn($m) => max(array_column($m, 'rss')));
            $reqs = $per($run !== [] ? $run : $cnt, $roles, static fn($m) => count($m));
            $qs = $per($cnt, $roles, static fn($m) => array_sum(array_map(static fn($s) => $s['db']['Questions'], $m)));
            $wr = $per($cnt, $roles, static fn($m) => array_sum(array_map(static fn($s) => $s['db']['Handler_write'] + $s['db']['Handler_update'] + $s['db']['Handler_delete'], $m)));
            $items = $per($run !== [] ? $run : $cnt, $roles, static fn($m) => array_sum(array_map(static fn($s) => (int) ($s['items'] ?? 0), $m)));
            $any = $any || $reqs !== [];
            $idle = (bool) ($by[$v]['run']['idle'] ?? false);
            $rows['wall median ms'][$v] = $ms === [] ? '' : ($idle ? sprintf('%.0f', $pct($ms, 0.5)) : 'contended');
            $rows['wall p95 ms'][$v] = $ms === [] ? '' : ($idle ? sprintf('%.0f', $pct($ms, 0.95)) : 'contended');
            $rows['peak MB'][$v] = $mb($pct($peak, 0.5));
            $rows['used MB'][$v] = $mb($pct($used, 0.5));
            $rows['RSS MB'][$v] = $mb($pct($rss, 0.5));
            $rows['requests'][$v] = $reqs === [] ? '' : (string) $pct($reqs, 0.5);
            $rows['queries'][$v] = $qs === [] ? '' : (string) $pct($qs, 0.5);
            $rows['rows written'][$v] = $wr === [] ? '' : (string) $pct($wr, 0.5);
            $rows['items'][$v] = $items === [] || max($items) === 0 ? '' : (string) $pct($items, 0.5);
            $statuses = [];
            foreach ($run !== [] ? $run : $cnt as $steps) {
                foreach ($steps as $s) {
                    if (in_array($s['role'], $roles, true)) {
                        $statuses[$s['status']] = true;
                    }
                }
            }
            ksort($statuses);
            $rows['answer'][$v] = implode('/', array_keys($statuses));
        }
        if (!$any) {
            continue;
        }
        foreach ($rows as $k => $vals) {
            if (implode('', array_map(static fn($v) => $vals[$v] ?? '', $versions)) === '') {
                continue;
            }
            echo "| $roleName | $k | " . implode(' | ', array_map(static fn($v) => $vals[$v] ?? '', $versions)) . " |\n";
        }
    }
    $undo = [];
    foreach ($versions as $v) {
        $r = $by[$v]['run']['undoRestored'][$n] ?? $by[$v]['count']['undoRestored'][$n] ?? null;
        if ($r !== null) {
            $undo[] = "$v " . ($r ? 'yes' : 'NO');
        }
    }
    if ($undo !== []) {
        echo "\nUndo put the events back as they were: " . implode(', ', $undo) . "\n";
    }
    echo "\n";
}
