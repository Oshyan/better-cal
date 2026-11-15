<?php
// The mutation runner (README, Mutations): each mutation in list.php is
// applied to a copy of its file (never to the tree), the copy loaded in
// place of the tree's, and its fixed cases run; the named checks must fail
// and every other check must pass. First the fixed cases run unmutated and
// must be clean. A check that does not fail its mutation is not a check.
//
// Every run (the unmutated baselines and each mutation) is a process of its
// own: the harness or the database suite on one file, with its own patched
// copy, its own result file and its own database, so --jobs N runs N of them
// at once. Every run finishes before anything is judged, and the judging is
// the same whatever the order they finished in, in the list's order.
//
//   BC_TEST_DB=127.0.0.1:3306 php tools/edit-harness/mutations/run.php [--only ID,...] [--jobs N] [--list FILE]
//   ... --search ID --seed S --cases N   (which drawn cases trip a mutation, to fix as cases)
//
// --jobs N: how many runs at once (default: the logical CPUs, counted as server/tests/db/run.php counts them for
// BC_TEST_JOBS; --jobs 1 runs them one after another). --list FILE: another list in list.php's form, in place of it (to
// check the runner itself).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 3);
// Each run drops its own database after writing its result; the drops they could not make are counted (ScratchDatabase).
require_once $root . '/server/tests/db/scratch.php';
$opts = getopt('', ['only:', 'search:', 'seed:', 'cases:', 'jobs:', 'list:']);
$mutations = require (isset($opts['list']) ? (string) $opts['list'] : __DIR__ . '/list.php');
$tmp = sys_get_temp_dir() . '/bc-mutations-' . bin2hex(random_bytes(4));
// The patched copies are run from here, so only a folder this run made will do (issue 280).
if (!@mkdir($tmp, 0700)) {
    fwrite(STDERR, "Could not make a new folder $tmp for the patched copies (one by that name may already exist); nothing was run.\n");
    exit(2);
}

/** The logical CPUs, where the system says; 1 where it doesn't (as server/tests/db/run.php counts them). */
$cpus = static function (): int {
    if (is_readable('/proc/cpuinfo')) {
        return max(1, (int) preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo')));
    }
    $n = (int) @shell_exec('sysctl -n hw.logicalcpu 2>/dev/null');
    return $n > 0 ? $n : max(1, (int) getenv('NUMBER_OF_PROCESSORS'));
};

/** A copy of the mutation's file with each find replaced, or an error when a find isn't there exactly once. */
$apply = static function (array $m) use ($root, $tmp): string {
    $src = (string) file_get_contents($root . '/' . $m['file']);
    foreach ($m['patch'] as [$find, $replace]) {
        $n = substr_count($src, $find);
        if ($n !== 1) {
            throw new RuntimeException("{$m['id']}: the text to change occurs $n times in {$m['file']} (expected once): " . substr($find, 0, 80));
        }
        $src = str_replace($find, $replace, $src);
    }
    $path = "$tmp/patched-{$m['id']}.php";
    file_put_contents($path, $src);
    return $path;
};

/**
 * A run, not yet started: the harness on fixed cases (or drawn ones) with the model loaded from $model, writing its
 * findings to $out. --series-undo on: some mutations damage undo of a change to all events on a Google calendar, which
 * ships off and which the harness leaves off by default; a mutation is caught only where its mechanism runs.
 */
$harnessJob = static fn(?string $model, array $args, string $out): array => [
    'kind' => 'harness', 'out' => $out, 'env' => [],
    'cmd' => array_merge([PHP_BINARY, $root . '/tools/edit-harness/harness.php', '--no-shrink', '--quiet', '--series-undo', 'on', '--findings', $out], $args, $model === null ? [] : ['--model', $model]),
];

/** A run, not yet started: one database test file, with $preload loaded in place of the tree's class, its report to $out. */
$dbtestJob = static fn(string $test, ?string $preload, string $out): array => [
    'kind' => 'dbtest', 'out' => $out, 'test' => $test,
    'cmd' => [PHP_BINARY, $root . '/server/tests/db/run.php'],
    'env' => ['BC_TEST_ONLY' => $test, 'BC_TEST_REPORT' => $out] + ($preload === null ? [] : ['BC_TEST_PRELOAD' => $preload]),
];

/**
 * Runs the jobs, up to $n at once, each a process of its own (no shell), the rest queued in order: for each job its
 * exit code, its output and its seconds, by the job's key. A process that cannot start is a result too (exit -1).
 */
$pool = static function (array $jobs, int $n): array {
    $queue = array_keys($jobs);
    $running = [];
    $done = [];
    while ($queue !== [] || $running !== []) {
        while ($queue !== [] && count($running) < $n) {
            $key = array_shift($queue);
            $proc = proc_open($jobs[$key]['cmd'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $jobs[$key]['env'] + getenv());
            if (!is_resource($proc)) {
                $done[$key] = ['code' => -1, 'out' => 'could not start a process', 'seconds' => 0.0];
                continue;
            }
            stream_set_blocking($pipes[1], false);
            $running[$key] = ['proc' => $proc, 'pipe' => $pipes[1], 'out' => '', 'at' => microtime(true)];
        }
        if ($running === []) {
            continue;
        }
        $read = array_column($running, 'pipe');
        $w = $e = null;
        stream_select($read, $w, $e, null);
        foreach ($running as $key => $r) {
            $running[$key]['out'] .= (string) stream_get_contents($r['pipe']);
            if (!feof($r['pipe'])) {
                continue;
            }
            fclose($r['pipe']);
            $done[$key] = ['code' => proc_close($r['proc']), 'out' => $running[$key]['out'], 'seconds' => microtime(true) - $r['at']];
            unset($running[$key]);
        }
    }
    return $done;
};

/** A finished harness run: the failing invariants by case and every record, or why it has none (its findings missing). */
$readHarness = static function (array $job, array $res): array {
    $f = is_file($job['out']) ? json_decode((string) file_get_contents($job['out']), true) : null;
    if (!is_array($f) || !isset($f['records']) || !is_array($f['records'])) {
        return ['error' => "the harness wrote no findings (exit {$res['code']}): " . substr(trim($res['out']), -600)];
    }
    $byCase = [];
    foreach ($f['records'] as $r) {
        $case = $r['spec']['fixed'] ?? (string) $r['n'];
        $byCase[$case][$r['invariant']] = ($byCase[$case][$r['invariant']] ?? 0) + 1;
    }
    return ['error' => null, 'byCase' => $byCase, 'records' => $f['records']];
};

/**
 * A finished database test run: its failing assertion labels, and why the run broke (null when it ran to the end with
 * no throw): the file didn't run, didn't finish, or a block threw; or the process left no report.
 */
$readDbtest = static function (array $job, array $res): array {
    $test = $job['test'];
    $r = is_file($job['out']) ? json_decode((string) file_get_contents($job['out']), true) : null;
    if (!is_array($r) || !isset($r['checks'], $r['files'])) {
        return ['failing' => [], 'broke' => "no report (exit {$res['code']}): " . substr(trim($res['out']), -300)];
    }
    $failing = array_values(array_unique(array_map(static fn(array $c): string => $c['name'], array_filter($r['checks'], static fn(array $c): bool => !$c['pass']))));
    $threw = array_values(array_filter($r['checks'], static fn(array $c): bool => !$c['pass'] && $c['threw']));
    $broke = match (true) {
        !isset($r['files']["$test.test.php"]) => "$test.test.php did not run",
        $r['files']["$test.test.php"] !== true => "$test.test.php did not run to the end",
        $threw !== [] => count($threw) . ' block(s) threw, first ' . $threw[0]['name'] . ': ' . $threw[0]['detail'],
        default => null,
    };
    return ['failing' => $failing, 'broke' => $broke];
};

$failed = 0;
try {
    if (isset($opts['search'])) {
        $m = array_values(array_filter($mutations, static fn(array $x): bool => $x['id'] === $opts['search']))[0] ?? null;
        if ($m === null) {
            throw new RuntimeException("no mutation {$opts['search']}");
        }
        // The cases whose named checks fail more than unmutated, and whose other checks fail no more.
        $args = ['--seed', (string) ($opts['seed'] ?? 1), '--cases', (string) ($opts['cases'] ?? 300)];
        $jobs = ['base' => $harnessJob(null, $args, "$tmp/search-base.json"), 'mutated' => $harnessJob($apply($m), $args, "$tmp/search-mutated.json")];
        $res = $pool($jobs, 2);
        $base = $readHarness($jobs['base'], $res['base']);
        $r = $readHarness($jobs['mutated'], $res['mutated']);
        foreach ([$base, $r] as $x) {
            if ($x['error'] !== null) {
                throw new RuntimeException($x['error']);
            }
        }
        foreach ($r['byCase'] as $case => $inv) {
            $more = [];
            foreach ($inv as $name => $n) {
                if ($n > ($base['byCase'][$case][$name] ?? 0)) {
                    $more[$name] = $n - ($base['byCase'][$case][$name] ?? 0);
                }
            }
            $named = array_intersect_key($more, array_flip($m['checks']));
            if ($named !== []) {
                $others = array_diff_key($more, array_flip($m['checks']));
                echo "case $case: " . json_encode($named) . ($others === [] ? '' : ' also ' . json_encode($others)) . (isset($base['byCase'][$case]) ? ' (fails unmutated too: ' . json_encode($base['byCase'][$case]) . ')' : '') . "\n";
            }
        }
        exit(0);
    }
    $only = isset($opts['only']) ? explode(',', (string) $opts['only']) : null;
    $selected = array_values(array_filter($mutations, static fn(array $m): bool => $only === null || in_array($m['id'], $only, true)));
    $ids = array_column($selected, 'id');
    if (count($ids) !== count(array_unique($ids))) {
        // Each verdict is kept by id: two mutations under one id would leave one of them unjudged.
        throw new RuntimeException('the list names an id twice: ' . implode(', ', array_keys(array_filter(array_count_values($ids), static fn(int $n): bool => $n > 1))));
    }
    $jobsAtOnce = isset($opts['jobs']) ? max(1, (int) $opts['jobs']) : $cpus();

    // Every case the harness mutations name, unmutated: clean. A failure here
    // is a finding about the tree, named; each mutation is then judged by the
    // failures it adds to these, record by record.
    // An interleaving mutation (`interleave: true`) runs its cases in that mode (cases/interleave/), judged the same way.
    $modeArgs = static fn(array $m): array => !empty($m['interleave']) ? ['--interleave'] : [];
    $cases = array_values(array_unique(array_merge(...array_map(static fn(array $m): array => empty($m['interleave']) ?($m['cases'] ?? []) : [], $selected ?: [['cases' => []]]))));
    $icases = array_values(array_unique(array_merge(...array_map(static fn(array $m): array => !empty($m['interleave']) ? ($m['cases'] ?? []) : [], $selected ?: [['cases' => []]]))));
    $tests = array_values(array_unique(array_filter(array_map(static fn(array $m): ?string => $m['dbtest'] ?? null, $selected))));

    // Every run, keyed; each writes only its own file in $tmp, named by its key, and makes its own database.
    $jobs = [];
    $verdicts = [];
    $judgeLater = [];
    // The database tests first: they are the longest runs, so they start while the short ones fill the other slots.
    foreach ($tests as $test) {
        $jobs["base-db:$test"] = $dbtestJob($test, null, "$tmp/base-db-$test.json");
    }
    foreach ($selected as $m) {
        if (isset($m['dbtest']) && ($m['checks'] ?? []) === []) {
            $verdicts[$m['id']] = [false, "FAIL  {$m['id']}: names no assertions it must fail"];
            continue;
        }
        try {
            $copy = $apply($m);
        } catch (RuntimeException $e) {
            // A patch that no longer applies tests nothing: a failure, named, and the run goes on to the rest.
            $verdicts[$m['id']] = [false, "FAIL  {$m['id']}: the patch does not apply: " . $e->getMessage()];
            continue;
        }
        $out = "$tmp/mutated-{$m['id']}.json";
        $jobs["m:{$m['id']}"] = isset($m['dbtest'])
            ? $dbtestJob($m['dbtest'], $copy, $out)
            : $harnessJob($copy, array_merge($modeArgs($m), ['--cases', '0', '--fixed=' . implode(',', $m['cases'])]), $out);
        $judgeLater[] = $m['id'];
    }
    // Database-test runs first, the rest in list order after them.
    uasort($jobs, static fn(array $a, array $b): int => ($a['kind'] === 'dbtest' ? 0 : 1) <=> ($b['kind'] === 'dbtest' ? 0 : 1));
    if ($cases !== []) {
        $jobs['base-harness'] = $harnessJob(null, ['--cases', '0', '--fixed=' . implode(',', $cases)], "$tmp/base-harness.json");
    }
    if ($icases !== []) {
        $jobs['base-interleave'] = $harnessJob(null, ['--interleave', '--cases', '0', '--fixed=' . implode(',', $icases)], "$tmp/base-interleave.json");
    }

    $started = microtime(true);
    $results = $pool($jobs, $jobsAtOnce);
    foreach ($results as $res) {
        ScratchDatabase::countIn($res['out'], true);
    }

    // Judged only now, every run finished, in the list's order.
    $sig = static fn(array $r): string => ($r['spec']['fixed'] ?? (string) $r['n']) . '|' . json_encode([$r['invariant'], $r['what'], $r['detail']]);
    $baseline = [];
    $baselineOk = ['harness' => true, 'interleave' => true];
    foreach (['harness' => [$cases, "the mutations' %d fixed cases"], 'interleave' => [$icases, "the interleaving mutations' %d fixed cases"]] as $mode => [$list, $what]) {
        if ($list === []) {
            continue;
        }
        $key = "base-$mode";
        $base = isset($results[$key]) ? $readHarness($jobs[$key], $results[$key]) : ['error' => 'it has no result'];
        $label = sprintf($what, count($list));
        if ($base['error'] !== null) {
            echo "FAIL  baseline: $label did not run: {$base['error']}\n";
            $baselineOk[$mode] = false;
            $failed++;
            continue;
        }
        $clean = $base['byCase'] === [];
        foreach ($base['records'] as $rec) {
            $baseline[$sig($rec)] = true;
        }
        echo ($clean ? 'ok  ' : 'FAIL') . "  baseline: $label pass unmutated" . ($clean ? '' : ': ' . json_encode($base['byCase'])) . "\n";
        $failed += $clean ? 0 : 1;
    }
    // Each database test a mutation names, unmutated: it must run to the end with no failure, and its failures, if any,
    // are the baseline the mutation's are judged against.
    $dbBase = [];
    foreach ($tests as $test) {
        $key = "base-db:$test";
        $dbBase[$test] = isset($results[$key]) ? $readDbtest($jobs[$key], $results[$key]) : ['failing' => [], 'broke' => 'it has no result'];
        $clean = $dbBase[$test]['failing'] === [] && $dbBase[$test]['broke'] === null;
        echo ($clean ? 'ok  ' : 'FAIL') . "  baseline: $test.test.php passes unmutated"
            . ($clean ? '' : ': ' . ($dbBase[$test]['broke'] ?? '') . ' ' . json_encode($dbBase[$test]['failing'])) . "\n";
        $failed += $clean ? 0 : 1;
    }
    $byId = array_column($selected, null, 'id');
    foreach ($judgeLater as $id) {
        $m = $byId[$id];
        $key = "m:$id";
        if (!isset($results[$key])) {
            $verdicts[$id] = [false, "FAIL  $id: its run has no result"];
            continue;
        }
        if (isset($m['dbtest'])) {
            // Judged as the harness branch is: by the assertions it adds to the unmutated run, by label. It must fail every
            // named one and no other; a throw (caught by scenario() or by run.php), a crash, or the file not running to the end
            // fails the mutation run whatever it adds. With no clean-running baseline it is not judged, and fails.
            if ($dbBase[$m['dbtest']]['broke'] !== null) {
                $verdicts[$id] = [false, "FAIL  $id: {$m['what']}: not judged, {$m['dbtest']}.test.php did not run unmutated: {$dbBase[$m['dbtest']]['broke']}"];
                continue;
            }
            $r = $readDbtest($jobs[$key], $results[$key]);
            $added = array_values(array_diff($r['failing'], $dbBase[$m['dbtest']]['failing']));
            $missing = array_values(array_diff($m['checks'], $added));
            $others = array_values(array_diff($added, $m['checks']));
            $broke = $r['broke'];
            $ok = $missing === [] && $others === [] && $broke === null;
            $verdicts[$id] = [$ok, ($ok ? 'ok  ' : 'FAIL') . "  $id: {$m['what']}: {$m['dbtest']}.test.php fails " . (count($m['checks']) - count($missing)) . ' of its ' . count($m['checks']) . ' named assertions'
                . ($missing === [] ? '' : '; did not fail ' . json_encode($missing)) . ($others === [] ? '' : '; other assertions failed ' . json_encode($others))
                . ($broke === null ? '' : "; the run broke: $broke")];
            continue;
        }
        $mode = !empty($m['interleave']) ? 'interleave' : 'harness';
        if (!$baselineOk[$mode]) {
            $verdicts[$id] = [false, "FAIL  $id: {$m['what']}: not judged, its cases' unmutated run has no result"];
            continue;
        }
        $r = $readHarness($jobs[$key], $results[$key]);
        if ($r['error'] !== null) {
            $verdicts[$id] = [false, "FAIL  $id: {$m['what']}: {$r['error']}"];
            continue;
        }
        $tripped = [];
        $others = [];
        foreach ($r['records'] as $rec) {
            if (isset($baseline[$sig($rec)])) {
                continue; // fails unmutated too
            }
            $name = $rec['invariant'];
            if (in_array($name, $m['checks'], true)) {
                $tripped[$name] = ($tripped[$name] ?? 0) + 1;
            } else {
                $others[$name] = ($others[$name] ?? 0) + 1;
            }
        }
        $missing = array_values(array_diff($m['checks'], array_keys($tripped)));
        $ok = $missing === [] && $others === [];
        $verdicts[$id] = [$ok, ($ok ? 'ok  ' : 'FAIL') . "  $id: {$m['what']}: " . json_encode($tripped)
            . ($missing === [] ? '' : '; did not trip ' . implode(', ', $missing)) . ($others === [] ? '' : '; other checks failed ' . json_encode($others))];
    }
    // One line per mutation in the list's order. Counted against the list: an id with no verdict fails, never passes.
    foreach ($selected as $m) {
        [$ok, $line] = $verdicts[$m['id']] ?? [false, "FAIL  {$m['id']}: no verdict"];
        echo "$line\n";
        $failed += $ok ? 0 : 1;
    }
    $slowest = $results;
    uasort($slowest, static fn(array $a, array $b): int => $b['seconds'] <=> $a['seconds']);
    printf("\n%d runs, %d at once, in %.0f s; the longest %s (%.0f s)\n", count($results), $jobsAtOnce, microtime(true) - $started, (string) array_key_first($slowest), $slowest[array_key_first($slowest)]['seconds'] ?? 0);
    echo ScratchDatabase::summary() . "\n";
} catch (Throwable $e) {
    // Anything else that stops the runner fails the run: a runner that breaks must never pass.
    fwrite(STDERR, "The mutation runner stopped: " . get_class($e) . ": " . $e->getMessage() . "\n");
    $failed++;
} finally {
    foreach (glob("$tmp/*") ?: [] as $f) {
        unlink($f);
    }
    rmdir($tmp);
}
echo "\n" . ($failed === 0 ? 'Every mutation trips its check.' : "$failed mutation check(s) failed.") . "\n";
exit($failed === 0 ? 0 : 1);
