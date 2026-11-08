<?php
// The mutation runner (README, Mutations): each mutation in list.php is
// applied to a copy of its file (never to the tree), the copy loaded in
// place of the tree's, and its fixed cases run; the named checks must fail
// and every other check must pass. First the fixed cases run unmutated and
// must be clean. A check that does not fail its mutation is not a check.
//
//   BC_TEST_DB=127.0.0.1:3306 php tools/edit-harness/mutations/run.php [--only ID,...]
//   ... --search ID --seed S --cases N   (which drawn cases trip a mutation, to fix as cases)
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 3);
$opts = getopt('', ['only:', 'search:', 'seed:', 'cases:']);
$mutations = require __DIR__ . '/list.php';
$tmp = sys_get_temp_dir() . '/bc-mutations-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);

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
    $path = "$tmp/{$m['id']}.php";
    file_put_contents($path, $src);
    return $path;
};

/** Run a command (no shell), returning [exit code, output]. */
$exec = static function (array $cmd, array $env = []): array {
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env + getenv());
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($p), (string) $out];
};

/** The harness on fixed cases (or drawn ones), with the model loaded from $model: the failing invariants by case. */
$harness = static function (?string $model, array $args) use ($exec, $root, $tmp): array {
    $findings = "$tmp/findings-" . bin2hex(random_bytes(3)) . '.json';
    $cmd = array_merge([PHP_BINARY, $root . '/tools/edit-harness/harness.php', '--no-shrink', '--quiet', '--findings', $findings], $args, $model === null ? [] : ['--model', $model]);
    [$code, $out] = $exec($cmd);
    $f = is_file($findings) ? json_decode((string) file_get_contents($findings), true) : null;
    if (!is_array($f)) {
        throw new RuntimeException("the harness wrote no findings:\n" . substr($out, -2000));
    }
    $byCase = [];
    foreach ($f['records'] as $r) {
        $byCase[$r['spec']['fixed'] ?? (string) $r['n']][$r['invariant']] = ($byCase[$r['spec']['fixed'] ?? (string) $r['n']][$r['invariant']] ?? 0) + 1;
    }
    return ['byCase' => $byCase, 'misuses' => $f['journalMisuses'] ?? [], 'records' => $f['records']];
};

/**
 * One database test file, with $preload loaded in place of the tree's class: its failing assertion labels, and why the
 * run broke (null when it ran to the end with no throw): the file didn't run, didn't finish, or a block threw.
 */
$dbtest = static function (string $test, ?string $preload) use ($exec, $root, $tmp): array {
    $report = "$tmp/dbtest-" . bin2hex(random_bytes(3)) . '.json';
    [$code, $out] = $exec([PHP_BINARY, $root . '/server/tests/db/run.php'], ['BC_TEST_ONLY' => $test, 'BC_TEST_REPORT' => $report] + ($preload === null ? [] : ['BC_TEST_PRELOAD' => $preload]));
    $r = is_file($report) ? json_decode((string) file_get_contents($report), true) : null;
    if (!is_array($r)) {
        return ['failing' => [], 'broke' => "no report (exit $code): " . substr(trim($out), -300)];
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
        $base = $harness(null, $args)['byCase'];
        $r = $harness($apply($m), $args);
        foreach ($r['byCase'] as $case => $inv) {
            $more = [];
            foreach ($inv as $name => $n) {
                if ($n > ($base[$case][$name] ?? 0)) {
                    $more[$name] = $n - ($base[$case][$name] ?? 0);
                }
            }
            $named = array_intersect_key($more, array_flip($m['checks']));
            if ($named !== []) {
                $others = array_diff_key($more, array_flip($m['checks']));
                echo "case $case: " . json_encode($named) . ($others === [] ? '' : ' also ' . json_encode($others)) . (isset($base[$case]) ? ' (fails unmutated too: ' . json_encode($base[$case]) . ')' : '') . "\n";
            }
        }
        exit(0);
    }
    $only = isset($opts['only']) ? explode(',', (string) $opts['only']) : null;
    $selected = array_values(array_filter($mutations, static fn(array $m): bool => $only === null || in_array($m['id'], $only, true)));
    // Every case the harness mutations name, unmutated: clean. A failure here
    // is a finding about the tree, named; each mutation is then judged by the
    // failures it adds to these, record by record.
    // An interleaving mutation (`interleave: true`) runs its cases in that mode (cases/interleave/), judged the same way.
    $modeArgs = static fn(array $m): array => !empty($m['interleave']) ? ['--interleave'] : [];
    $cases = array_values(array_unique(array_merge(...array_map(static fn(array $m): array => empty($m['interleave']) ? ($m['cases'] ?? []) : [], $selected ?: [['cases' => []]]))));
    $icases = array_values(array_unique(array_merge(...array_map(static fn(array $m): array => !empty($m['interleave']) ? ($m['cases'] ?? []) : [], $selected ?: [['cases' => []]]))));
    $sig = static fn(array $r): string => ($r['spec']['fixed'] ?? (string) $r['n']) . '|' . json_encode([$r['invariant'], $r['what'], $r['detail']]);
    $baseline = [];
    if ($cases !== []) {
        $base = $harness(null, ['--cases', '0', '--fixed=' . implode(',', $cases)]);
        $clean = $base['byCase'] === [];
        foreach ($base['records'] as $rec) {
            $baseline[$sig($rec)] = true;
        }
        echo ($clean ? 'ok  ' : 'FAIL') . "  baseline: the mutations' " . count($cases) . " fixed cases pass unmutated" . ($clean ? '' : ': ' . json_encode($base['byCase'])) . "\n";
        $failed += $clean ? 0 : 1;
    }
    if ($icases !== []) {
        $base = $harness(null, ['--interleave', '--cases', '0', '--fixed=' . implode(',', $icases)]);
        $clean = $base['byCase'] === [];
        foreach ($base['records'] as $rec) {
            $baseline[$sig($rec)] = true;
        }
        echo ($clean ? 'ok  ' : 'FAIL') . "  baseline: the interleaving mutations' " . count($icases) . " fixed cases pass unmutated" . ($clean ? '' : ': ' . json_encode($base['byCase'])) . "\n";
        $failed += $clean ? 0 : 1;
    }
    // Each database test a mutation names, unmutated: it must run to the end with no failure, and its failures, if any,
    // are the baseline the mutation's are judged against.
    $dbBase = [];
    foreach (array_unique(array_filter(array_map(static fn(array $m): ?string => $m['dbtest'] ?? null, $selected))) as $test) {
        $dbBase[$test] = $dbtest($test, null);
        $clean = $dbBase[$test]['failing'] === [] && $dbBase[$test]['broke'] === null;
        echo ($clean ? 'ok  ' : 'FAIL') . "  baseline: $test.test.php passes unmutated"
            . ($clean ? '' : ': ' . ($dbBase[$test]['broke'] ?? '') . ' ' . json_encode($dbBase[$test]['failing'])) . "\n";
        $failed += $clean ? 0 : 1;
    }
    foreach ($selected as $m) {
        try {
            $copy = $apply($m);
        } catch (RuntimeException $e) {
            // A patch that no longer applies tests nothing: a failure, named, and the run goes on to the rest.
            echo "FAIL  {$m['id']}: the patch does not apply: " . $e->getMessage() . "\n";
            $failed++;
            continue;
        }
        if (isset($m['dbtest'])) {
            // Judged as the harness branch is: by the assertions it adds to the unmutated run, by label. It must fail every
            // named one and no other; a throw (caught by scenario() or by run.php), a crash, or the file not running to the end
            // fails the mutation run whatever it adds.
            if (($m['checks'] ?? []) === []) {
                echo "FAIL  {$m['id']}: names no assertions it must fail\n";
                $failed++;
                continue;
            }
            $r = $dbtest($m['dbtest'], $copy);
            $added = array_values(array_diff($r['failing'], $dbBase[$m['dbtest']]['failing']));
            $missing = array_values(array_diff($m['checks'], $added));
            $others = array_values(array_diff($added, $m['checks']));
            $broke = $r['broke'];
            $ok = $missing === [] && $others === [] && $broke === null;
            echo ($ok ? 'ok  ' : 'FAIL') . "  {$m['id']}: {$m['what']}: {$m['dbtest']}.test.php fails " . (count($m['checks']) - count($missing)) . ' of its ' . count($m['checks']) . ' named assertions'
                . ($missing === [] ? '' : '; did not fail ' . json_encode($missing)) . ($others === [] ? '' : '; other assertions failed ' . json_encode($others))
                . ($broke === null ? '' : "; the run broke: $broke") . "\n";
            $failed += $ok ? 0 : 1;
            continue;
        }
        $r = $harness($copy, array_merge($modeArgs($m), ['--cases', '0', '--fixed=' . implode(',', $m['cases'])]));
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
        echo ($ok ? 'ok  ' : 'FAIL') . "  {$m['id']}: {$m['what']}: " . json_encode($tripped)
            . ($missing === [] ? '' : '; did not trip ' . implode(', ', $missing)) . ($others === [] ? '' : '; other checks failed ' . json_encode($others)) . "\n";
        $failed += $ok ? 0 : 1;
    }
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
