<?php
// Property harness for repeating-series edits, command-line entry. Makes a
// database of its own on the server BC_TEST_DB names, migrates it, runs the
// cases and drops it. See README.md.
//
//   BC_TEST_DB=127.0.0.1:3306 php tools/edit-harness/harness.php [--cases N] [--seed S]
//       [--family F] [--case N] [--no-shrink] [--quiet] [--fixed[=FILE,...]]
//       [--save-spec DIR] [--findings FILE] [--model FILE] [--find CHECK|ROLE]
//
// --fixed runs the fixed cases (cases/*.json, or the files named) after the
// drawn ones (--cases 0 for the fixed ones alone; a full run of 1,000 cases or
// more takes them all); --save-spec writes each
// case's complete spec to DIR, as a fixed case is written; --findings names
// the findings file; --model loads the series model from FILE instead of the
// tree (the mutation runner's patched copy, mutations/run.php); --find names
// each case that ran a category of the plan-data checks (to pick fixed cases).
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['cases:', 'seed:', 'family:', 'case:', 'no-shrink', 'quiet', 'help', 'fixed::', 'save-spec:', 'findings:', 'model:', 'find:']);
if (isset($opts['help'])) {
    echo "Usage: BC_TEST_DB=host:port php harness.php [--cases N] [--seed S] [--family F] [--case N] [--no-shrink] [--quiet] [--fixed[=FILE,...]] [--save-spec DIR] [--findings FILE] [--model FILE] [--find CHECK|ROLE]\n";
    exit(0);
}
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

$root = dirname(__DIR__, 2);
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
require $root . '/server/tests/db/lib.php';
require __DIR__ . '/lib.php';

use BetterCal\EditHarness\Known;
use BetterCal\EditHarness\Runner;
use BetterCal\Infra\Db;
use BetterCal\Infra\Migrator;

[$host, $port] = array_pad(explode(':', getenv('BC_TEST_DB') ?: '127.0.0.1:3306', 2), 2, '3306');
$user = getenv('BC_TEST_DB_USER') ?: 'root';
$password = getenv('BC_TEST_DB_PASS') ?: '';
$name = 'bc_edits_' . bin2hex(random_bytes(4));
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$started = microtime(true);
$out = [];
$misuses = [];
try {
    $db = new Db(['dsn' => "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", 'user' => $user, 'pass' => $password]);
    (new Migrator($db, $root . '/server/migrations'))->run(static function (string $line): void {
    });
    $runner = new Runner($db, domains($db), $seed);
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
    });
    if ($fixed !== null) {
        $runner->runFixed($fixed);
    } elseif ($onlyFamily === null && $onlyCase === null && $cases >= 1000) {
        // A full run takes the fixed cases too: some required categories (a rule the expander can't read) are never drawn.
        $runner->runFixed();
    }
    $casesSeconds = round(microtime(true) - $started, 1);
    $misuses = BetterCal\Domain\Undo::misuses();

    // One shrunk reproduction per failure signature outside the known paths (and one per known cause).
    $bySignature = [];
    foreach ($runner->failures as $f) {
        $bySignature[Runner::signature($f)][] = $f;
    }
    ksort($bySignature);
    $minimal = [];
    if ($shrink) {
        foreach ($bySignature as $sig => $list) {
            if (Known::cause($list[0]) === null) {
                $minimal[$sig] = $runner->shrink($list[0]);
            }
        }
    }
    $elapsed = round(microtime(true) - $started, 1);
    $byCause = [];
    $new = 0;
    foreach ($bySignature as $sig => $list) {
        $cause = Known::cause($list[0]);
        $byCause[$cause ?? 'FINDING'] = ($byCause[$cause ?? 'FINDING'] ?? 0) + count($list);
        if ($cause === null) {
            $new++;
        }
    }
    ksort($byCause);
    $out = [
        'seed' => $seed, 'cases' => $runner->counts['cases'], 'seconds' => $elapsed, 'casesSeconds' => $casesSeconds,
        'byFamily' => $runner->counts['byFamily'], 'byPath' => $runner->counts['byPath'],
        'failures' => $runner->counts['failures'], 'byCause' => $byCause,
        'signatures' => array_map('count', $bySignature),
        'unknownSignatures' => $new,
        'unoracled' => $runner->counts['unoracled'],
        'minimal' => $minimal,
        'records' => $runner->failures,
        'journalMisuses' => $misuses,
        'revision' => BetterCal\EditHarness\Revision::get(),
        'tally' => BetterCal\EditHarness\Report::tally($runner),
    ];
    // A full run must execute every required category; a slice or one family needn't.
    $full = $onlyFamily === null && $onlyCase === null && $cases >= 1000;
    $missingFails = $full && $out['tally']['missing'] !== [];
    file_put_contents($findingsFile, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));

    echo "\n{$runner->counts['cases']} cases in {$casesSeconds}s, {$elapsed}s with shrinking (seed $seed)\n";
    ksort($runner->counts['byFamily']);
    foreach ($runner->counts['byFamily'] as $family => $n) {
        echo sprintf("  %-20s %5d\n", $family, $n);
    }
    echo "\nBy path:\n";
    ksort($runner->counts['byPath']);
    foreach ($runner->counts['byPath'] as $path => $n) {
        echo sprintf("  %-24s %5d\n", $path, $n);
    }
    $total = count($runner->failures);
    echo "\n$total failing check(s), " . count($bySignature) . " signature(s)" . ($misuses !== [] ? ', ' . count($misuses) . ' journal misuse(s)' : '') . "\n";
    foreach ($bySignature as $sig => $list) {
        echo sprintf("  %4d  %-64s %s\n", count($list), $sig, Known::cause($list[0]) ?? 'FINDING');
    }
    echo "\nBy cause:\n";
    foreach ($byCause as $cause => $n) {
        echo sprintf("  %5d  %s\n", $n, $cause);
    }
    if ($runner->counts['unoracled'] !== []) {
        echo "\nNot checked (the design leaves it open):\n";
        foreach ($runner->counts['unoracled'] as $what => $n) {
            echo sprintf("  %5d  %s\n", $n, $what);
        }
    }
    if (getenv('HARNESS_PROFILE') !== false) {
        echo "\nTime by phase:\n";
        arsort(BetterCal\EditHarness\Prof::$t);
        foreach (BetterCal\EditHarness\Prof::$t as $phase => $s) {
            echo sprintf("  %-18s %6.1fs\n", $phase, $s);
        }
    }
    // The executions table first, then the tallies, so the last lines of the output are the verdict.
    echo "\n" . BetterCal\EditHarness\Report::runsText($out['tally']);
    echo "\n" . BetterCal\EditHarness\Report::summaryText($out['tally']);
    echo "Revision {$out['revision']}\n";
    echo ($new === 0 && $misuses === [] && !$missingFails ? 'Clean: every failure is under a known code path.' : ($new > 0 ? "$new signature(s) outside the known code paths." : ($missingFails ? 'Not clean: a required category never ran.' : 'Not clean: journal misuses.'))) . "\n";
    echo "findings.json has every record" . ($shrink ? ' and a shrunk reproduction per finding' : '') . ".\n";
} finally {
    $server->exec("DROP DATABASE IF EXISTS `$name`");
}
exit(($out['unknownSignatures'] ?? 1) === 0 && $misuses === [] && !($missingFails ?? true) ? 0 : 1);
