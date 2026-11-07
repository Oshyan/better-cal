<?php
// Property harness for repeating-series edits, command-line entry. Makes a
// database of its own on the server BC_TEST_DB names, migrates it, runs the
// cases and drops it. See README.md.
//
//   BC_TEST_DB=127.0.0.1:3306 php tools/edit-harness/harness.php [--cases N] [--seed S]
//       [--family F] [--case N] [--no-shrink] [--quiet] [--fixed[=FILE,...]]
//       [--save-spec DIR] [--findings FILE] [--model FILE] [--find CHECK|ROLE] [--shards N] [--every-option F] [--google]
//
// --fixed runs the fixed cases (cases/*.json, or the files named) after the
// drawn ones (--cases 0 for the fixed ones alone; a full run of 1,000 cases or
// more takes them all); --save-spec writes each
// case's complete spec to DIR, as a fixed case is written; --findings names
// the findings file; --model loads the series model from FILE instead of the
// tree (the mutation runner's patched copy, mutations/run.php); --find names
// each case that ran a category of the plan-data checks (to pick fixed cases);
// --shards N runs the cases on N processes at once, each on a database of its
// own, and merges them into the report one process gives; --google puts every
// case's series on a Google calendar served by the probe's stand-in Google
// (README, Google), checking what it holds after each edit and after a poll.
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['cases:', 'seed:', 'family:', 'case:', 'no-shrink', 'quiet', 'help', 'fixed::', 'save-spec:', 'findings:', 'model:', 'find:', 'shards:', 'shard:', 'partial:', 'every-option:', 'typed:', 'product:', 'google']);
if (isset($opts['help'])) {
    echo "Usage: BC_TEST_DB=host:port php harness.php [--cases N] [--seed S] [--family F] [--case N] [--no-shrink] [--quiet] [--fixed[=FILE,...]] [--save-spec DIR] [--findings FILE] [--model FILE] [--find CHECK|ROLE] [--shards N] [--every-option F] [--google]\n";
    exit(0);
}
$googleMode = isset($opts['google']);
$fixed = !array_key_exists('fixed', $opts) ? null : ($opts['fixed'] === false ? [] : explode(',', (string) $opts['fixed']));
$saveSpec = isset($opts['save-spec']) ? (string) $opts['save-spec'] : null;
$find = isset($opts['find']) ? (string) $opts['find'] : null;
$findingsFile = isset($opts['findings']) ? (string) $opts['findings'] : __DIR__ . '/findings.json';
$cases = (int) ($opts['cases'] ?? 3000);
$seed = (int) ($opts['seed'] ?? 1);
$onlyFamily = isset($opts['family']) ? (string) $opts['family'] : null;
$onlyCase = isset($opts['case']) ? (int) $opts['case'] : null;
$shrink = !isset($opts['no-shrink']);
$quiet = isset($opts['quiet']);
// --case is one case, and --find names cases as they run, in order: both run on one process.
$shards = $onlyCase !== null || $find !== null ? 1 : (int) ($opts['shards'] ?? 1);
if ($shards < 1) {
    fwrite(STDERR, "--shards needs a number of processes, 1 or more.\n");
    exit(1);
}
// --shard k/N and --partial FILE are internal: a child of --shards N runs its share and writes it to FILE.
$child = isset($opts['shard']);
[$shard, $ofShards] = $child ? array_map('intval', explode('/', (string) $opts['shard'], 2)) + [1 => 1] : [0, 1];
$partialFile = isset($opts['partial']) ? (string) $opts['partial'] : null;
// A full run must execute every required category; a slice or one family needn't.
$full = $onlyFamily === null && $onlyCase === null && $cases >= 1000;
// --every-option F: the share of drawn whole-series cases that commit every option of every choice (README, Every Option).
$everyOption = isset($opts['every-option']) ? (float) $opts['every-option'] : null;
if ($everyOption !== null && ($everyOption < 0 || $everyOption > 1)) {
    fwrite(STDERR, "--every-option needs a share from 0 to 1.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
// --product DIR: the product checked is that checkout's (its server/src through its own server/vendor autoload, its
// migrations); the harness's own classes and the database suite's helpers stay this tree's (README, Other Products).
$harnessRoot = $root;
if (isset($opts['product'])) {
    $product = realpath((string) $opts['product']);
    if ($product === false || !is_file($product . '/server/src/bootstrap.php')) {
        fwrite(STDERR, "--product needs a checkout with server/src/bootstrap.php.\n");
        exit(1);
    }
    $root = $product;
}
if (!is_file($root . '/server/vendor/autoload.php')) {
    fwrite(STDERR, "Needs Composer's libraries: composer install in server/.\n");
    exit(1);
}
if (getenv('BETTERCAL_SESSION_SECRET') === false) {
    putenv('BETTERCAL_SESSION_SECRET=' . bin2hex(random_bytes(32)));
}
putenv('BETTERCAL_STRICT_JOURNAL=1');
require $root . '/server/src/bootstrap.php';
if (isset($opts['model'])) {
    // Declared before anything autoloads the tree's, so this copy is the model every call reaches.
    require (string) $opts['model'];
}
require $harnessRoot . '/server/tests/db/lib.php';
require __DIR__ . '/lib.php';
BetterCal\EditHarness\Product::use($root);
if ($everyOption !== null) {
    BetterCal\EditHarness\Driver::$everyOption = $everyOption;
}
// --typed F: the share of drawn cases whose timed edits send the wall clocks typed (README, The Delta Vector).
if (isset($opts['typed'])) {
    BetterCal\EditHarness\Driver::$typed = (float) $opts['typed'];
}
if (!BetterCal\EditHarness\Product::$plan) {
    // No plan API: the wall clocks typed are a 1.5 request field, so every case is sent untyped (counted as not applicable).
    BetterCal\EditHarness\Driver::$typed = 0.0;
    // An old product fails thousands of checks, each record with its case's spec: the merge holds them all.
    ini_set('memory_limit', '4096M');
}

use BetterCal\EditHarness\Known;
use BetterCal\EditHarness\Prof;
use BetterCal\EditHarness\Report;
use BetterCal\EditHarness\Revision;
use BetterCal\EditHarness\Runner;
use BetterCal\Infra\Db;
use BetterCal\Infra\Migrator;

/**
 * This process's share of the cases (shard $shard of $of; all of them for 0 of 1) on a database of its own, with one
 * shrunk reproduction per signature of its own outside the known paths: what a report is made from.
 */
$runHere = static function (int $shard, int $of) use ($root, $cases, $seed, $onlyFamily, $onlyCase, $shrink, $quiet, $saveSpec, $find, $fixed, $full, $googleMode): array {
    [$host, $port] = array_pad(explode(':', getenv('BC_TEST_DB') ?: '127.0.0.1:3306', 2), 2, '3306');
    $user = getenv('BC_TEST_DB_USER') ?: 'root';
    $password = getenv('BC_TEST_DB_PASS') ?: '';
    $name = 'bc_edits_' . bin2hex(random_bytes(4));
    $server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $started = microtime(true);
    try {
        $db = new Db(['dsn' => "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", 'user' => $user, 'pass' => $password]);
        (new Migrator($db, $root . '/server/migrations'))->run(static function (string $line): void {
        });
        $runner = new Runner($db, domains($db), $seed, $googleMode);
        $runner->run($cases, $onlyFamily, $onlyCase, 150, static function (int $i, array $result) use ($quiet, $cases, $saveSpec, $find): void {
            if ($find !== null) {
                // --find 'check|role': the cases that ran a category, to pick one as a fixed case.
                [$fc, $fr] = explode('|', $find, 2) + [1 => ''];
                if (($result['runs'][$fc][$fr] ?? 0) > 0) {
                    echo "case $i ran $find\n";
                }
            }
            if ($saveSpec !== null && isset($result['spec']['vector'])) {
                // The complete spec, as a fixed case is written (what the run recorded of the request is left out).
                $spec = array_diff_key($result['spec'], ['request' => 1, 'create' => 1, 'path' => 1]);
                file_put_contents($saveSpec . '/seed' . $result['spec']['seed'] . '-case' . $i . '.json', json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            }
            // HARNESS_SLOW=seconds names each case that took longer.
            static $last = null;
            $now = microtime(true);
            if (getenv('HARNESS_SLOW') !== false && $last !== null && $now - $last > (float) getenv('HARNESS_SLOW')) {
                fwrite(STDERR, sprintf("case %d took %.1fs: %s %s %s\n", $i, $now - $last, $result['spec']['series']['family'], $result['spec']['series']['rrule'], $result['path']));
            }
            $last = $now;
            if (!$quiet && $result['records'] !== []) {
                foreach ($result['records'] as $rec) {
                    $known = Known::cause($rec + ['path' => $result['path'], 'spec' => $result['spec']]) !== null ? ' (known cause)' : '';
                    echo "case $i: {$rec['invariant']} {$result['spec']['series']['family']} {$result['path']}$known: {$rec['what']}\n";
                }
            }
            if (!$quiet && $i % 500 === 0) {
                fwrite(STDERR, "$i of $cases\n");
            }
        }, $of, $shard);
        // The fixed cases run once in all, on the first shard.
        if ($shard === 0 && $fixed !== null) {
            $runner->runFixed($fixed);
        } elseif ($shard === 0 && $full) {
            // A full run takes the fixed cases too: some required categories (a rule the expander can't read) are never drawn.
            $runner->runFixed();
        }
        $casesSeconds = round(microtime(true) - $started, 1);
        $misuses = method_exists(BetterCal\Domain\Undo::class, 'misuses') ? BetterCal\Domain\Undo::misuses() : [];

        // One shrunk reproduction per failure signature outside the known paths.
        $minimal = [];
        if ($shrink) {
            $bySignature = [];
            foreach ($runner->failures as $f) {
                $bySignature[Runner::signature($f)][] = $f;
            }
            ksort($bySignature);
            foreach ($bySignature as $sig => $list) {
                if (Known::cause($list[0]) === null) {
                    $minimal[$sig] = $runner->shrink($list[0]);
                }
            }
        }
        return [
            'partial' => true, 'shard' => $shard, 'counts' => $runner->counts, 'firstSeen' => $runner->firstSeen, 'failures' => $runner->failures, 'minimal' => $minimal,
            'misuses' => $misuses, 'prof' => Prof::$t, 'casesSeconds' => $casesSeconds, 'seconds' => round(microtime(true) - $started, 1),
        ];
    } finally {
        $server->exec("DROP DATABASE IF EXISTS `$name`");
    }
};

/**
 * One result from the shards' (one process's alone without --shards): counts summed, failures in case order with the
 * fixed cases last as one process runs them, journal misuses unioned, and for each signature the shrunk reproduction of
 * its first failure, from the shard that ran it.
 */
$merge = static function (array $parts): array {
    $add = static function (array $a, array $b) use (&$add): array {
        foreach ($b as $k => $v) {
            $a[$k] = is_array($v) ? $add($a[$k] ?? [], $v) : ($a[$k] ?? 0) + $v;
        }
        return $a;
    };
    $fixedLast = static fn(array $f): array => isset($f['spec']['fixed']) ? [1, 0] : [0, $f['n']];
    $key = static fn(array $f): string => isset($f['spec']['fixed']) ? 'fixed ' . $f['spec']['fixed'] : 'case ' . $f['n'];
    $m = ['counts' => [], 'failures' => [], 'minimal' => [], 'misuses' => [], 'prof' => []];
    $owner = [];
    $firstSeen = [];
    foreach ($parts as $j => $p) {
        $m['counts'] = $add($m['counts'], $p['counts']);
        foreach ($p['firstSeen'] as $path => $at) {
            $firstSeen[$path] = isset($firstSeen[$path]) ? min($firstSeen[$path], $at) : $at;
        }
        $m['prof'] = $add($m['prof'], $p['prof']);
        array_push($m['failures'], ...$p['failures']);
        $m['misuses'] = array_values(array_unique(array_merge($m['misuses'], $p['misuses'])));
        foreach ($p['failures'] as $f) {
            $owner[$key($f)] = $j;
        }
    }
    // Each map of the counts in the order one process fills it: by where its keys were first counted (Runner::bump).
    $order = static function (array $map, string $prefix) use (&$order, $firstSeen): array {
        uksort($map, static fn($a, $b): int => ($firstSeen[$prefix . $a] ?? [-1]) <=> ($firstSeen[$prefix . $b] ?? [-1]));
        foreach ($map as $k => $v) {
            if (is_array($v)) {
                $map[$k] = $order($v, $prefix . $k . "\0");
            }
        }
        return $map;
    };
    $m['counts'] = $order($m['counts'], '');
    // Stable: a case's records keep their order, and the fixed cases theirs (they all ran on one shard).
    usort($m['failures'], static fn(array $a, array $b): int => $fixedLast($a) <=> $fixedLast($b));
    $first = [];
    foreach ($m['failures'] as $f) {
        $first[Runner::signature($f)] ??= $f;
    }
    foreach ($first as $sig => $f) {
        if (isset($parts[$owner[$key($f)]]['minimal'][$sig])) {
            $m['minimal'][$sig] = $parts[$owner[$key($f)]]['minimal'][$sig];
        }
    }
    return $m;
};

/** The findings file and the printed report from a merged result; the exit code. */
$report = static function (array $p, float $casesSeconds, float $elapsed) use ($seed, $shrink, $findingsFile, $full): int {
    $bySignature = [];
    foreach ($p['failures'] as $f) {
        $bySignature[Runner::signature($f)][] = $f;
    }
    ksort($bySignature);
    $minimal = [];
    $byCause = [];
    $new = 0;
    foreach ($bySignature as $sig => $list) {
        $cause = Known::cause($list[0]);
        $byCause[$cause ?? 'FINDING'] = ($byCause[$cause ?? 'FINDING'] ?? 0) + count($list);
        if ($cause === null) {
            $new++;
            if (isset($p['minimal'][$sig])) {
                $minimal[$sig] = $p['minimal'][$sig];
            }
        }
    }
    ksort($byCause);
    $counts = $p['counts'];
    $misuses = $p['misuses'];
    $out = [
        'seed' => $seed, 'cases' => $counts['cases'], 'seconds' => $elapsed, 'casesSeconds' => $casesSeconds,
        'byFamily' => $counts['byFamily'], 'byPath' => $counts['byPath'],
        'failures' => $counts['failures'], 'byCause' => $byCause,
        'signatures' => array_map('count', $bySignature),
        'unknownSignatures' => $new,
        'unoracled' => $counts['unoracled'],
        'minimal' => $minimal,
        'records' => $p['failures'],
        'journalMisuses' => $misuses,
        'revision' => Revision::get(),
        'tally' => Report::tallyOf($counts, $p['failures']),
    ];
    // A product without the plan API never runs the plan's categories (README, Other Products): reported, not failed.
    $missingFails = $full && $out['tally']['missing'] !== [] && BetterCal\EditHarness\Product::$plan;
    $out['product'] = BetterCal\EditHarness\Product::summary($counts);
    file_put_contents($findingsFile, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));

    echo "\n{$counts['cases']} cases in {$casesSeconds}s, {$elapsed}s with shrinking (seed $seed)\n";
    ksort($counts['byFamily']);
    foreach ($counts['byFamily'] as $family => $n) {
        echo sprintf("  %-20s %5d\n", $family, $n);
    }
    echo "\nBy path:\n";
    ksort($counts['byPath']);
    foreach ($counts['byPath'] as $path => $n) {
        echo sprintf("  %-24s %5d\n", $path, $n);
    }
    $total = count($p['failures']);
    echo "\n$total failing check(s), " . count($bySignature) . " signature(s)" . ($misuses !== [] ? ', ' . count($misuses) . ' journal misuse(s)' : '') . "\n";
    foreach ($bySignature as $sig => $list) {
        echo sprintf("  %4d  %-64s %s\n", count($list), $sig, Known::cause($list[0]) ?? 'FINDING');
    }
    echo "\nBy cause:\n";
    foreach ($byCause as $cause => $n) {
        echo sprintf("  %5d  %s\n", $n, $cause);
    }
    if ($counts['unoracled'] !== []) {
        echo "\nNot checked (the design leaves it open):\n";
        foreach ($counts['unoracled'] as $what => $n) {
            echo sprintf("  %5d  %s\n", $n, $what);
        }
    }
    if (getenv('HARNESS_PROFILE') !== false) {
        echo "\nTime by phase:\n";
        $prof = $p['prof'];
        arsort($prof);
        foreach ($prof as $phase => $s) {
            echo sprintf("  %-18s %6.1fs\n", $phase, $s);
        }
    }
    // The executions table first, then the tallies, so the last lines of the output are the verdict.
    echo "\n" . Report::runsText($out['tally']);
    echo "\n" . Report::summaryText($out['tally']);
    echo "\n" . BetterCal\EditHarness\Product::text($out['product']);
    echo "Revision {$out['revision']}\n";
    echo ($new === 0 && $misuses === [] && !$missingFails ? 'Clean: every failure is under a known code path.' : ($new > 0 ? "$new signature(s) outside the known code paths." : ($missingFails ? 'Not clean: a required category never ran.' : 'Not clean: journal misuses.'))) . "\n";
    echo "findings.json has every record" . ($shrink ? ' and a shrunk reproduction per finding' : '') . ".\n";
    return $new === 0 && $misuses === [] && !$missingFails ? 0 : 1;
};

if ($child) {
    // A shard writes its share for the parent and prints nothing; the verdict is the parent's.
    $p = $runHere($shard, $ofShards);
    $json = json_encode($p, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($partialFile === null || $json === false || file_put_contents($partialFile, $json) !== strlen($json)) {
        fwrite(STDERR, "shard $shard/$ofShards could not write its partial findings.\n");
        exit(2);
    }
    exit(0);
}
if ($shards === 1) {
    $p = $runHere(0, 1);
    exit($report($merge([$p]), $p['casesSeconds'], $p['seconds']));
}

// N children at once, each a harness on its share of the cases, its output kept in a file and shown if it fails.
$started = microtime(true);
$tmp = sys_get_temp_dir() . '/bc-shards-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$code = 2;
try {
    $pass = ['--seed', (string) $seed, '--cases', (string) $cases, '--quiet'];
    if ($onlyFamily !== null) {
        array_push($pass, '--family', $onlyFamily);
    }
    if (!$shrink) {
        $pass[] = '--no-shrink';
    }
    if ($everyOption !== null) {
        array_push($pass, '--every-option', (string) $everyOption);
    }
    if (isset($opts['typed'])) {
        array_push($pass, '--typed', (string) $opts['typed']);
    }
    if ($fixed !== null) {
        $pass[] = $opts['fixed'] === false ? '--fixed' : '--fixed=' . $opts['fixed'];
    }
    if (isset($opts['model'])) {
        array_push($pass, '--model', (string) $opts['model']);
    }
    if (isset($opts['product'])) {
        array_push($pass, '--product', (string) $opts['product']);
    }
    if ($saveSpec !== null) {
        array_push($pass, '--save-spec', $saveSpec);
    }
    $procs = [];
    for ($k = 0; $k < $shards; $k++) {
        $cmd = array_merge([PHP_BINARY, __FILE__], $pass, ['--shard', "$k/$shards", '--partial', "$tmp/shard-$k.json"]);
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$tmp/shard-$k.out", 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException("shard $k/$shards did not start");
        }
        $procs[$k] = $proc;
    }
    $parts = [];
    $broken = [];
    foreach ($procs as $k => $proc) {
        $exit = proc_close($proc);
        $file = "$tmp/shard-$k.json";
        $part = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if ($exit !== 0 || !is_array($part) || ($part['partial'] ?? null) !== true || ($part['shard'] ?? null) !== $k) {
            $why = $exit !== 0 ? "exit $exit" : (is_file($file) ? 'its partial findings are not JSON from a shard' : 'no partial findings');
            $broken[] = "shard $k/$shards failed ($why); its output:\n" . trim((string) @file_get_contents("$tmp/shard-$k.out"));
            continue;
        }
        $parts[$k] = $part;
    }
    if ($broken !== []) {
        fwrite(STDERR, implode("\n\n", $broken) . "\n\nNo findings written: a run missing a shard is not the run asked for.\n");
    } else {
        $merged = $merge($parts);
        if (!$quiet) {
            // What one process prints as each drawn case fails, in case order.
            foreach ($merged['failures'] as $f) {
                if (!isset($f['spec']['fixed'])) {
                    $known = Known::cause($f) !== null ? ' (known cause)' : '';
                    echo "case {$f['n']}: {$f['invariant']} {$f['family']} {$f['path']}$known: {$f['what']}\n";
                }
            }
        }
        $code = $report($merged, max(array_column($parts, 'casesSeconds')), round(microtime(true) - $started, 1));
    }
} finally {
    foreach (glob("$tmp/*") ?: [] as $f) {
        unlink($f);
    }
    rmdir($tmp);
}
exit($code);
