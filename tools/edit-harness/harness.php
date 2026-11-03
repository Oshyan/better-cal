<?php
// Property harness for repeating-series edits, command-line entry. Makes a
// database of its own on the server BC_TEST_DB names, migrates it, runs the
// cases and drops it. See README.md.
//
//   BC_TEST_DB=127.0.0.1:3306 php tools/edit-harness/harness.php [--cases N] [--seed S]
//       [--family F] [--case N] [--no-shrink] [--quiet]
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['cases:', 'seed:', 'family:', 'case:', 'no-shrink', 'quiet', 'help']);
if (isset($opts['help'])) {
    echo "Usage: BC_TEST_DB=host:port php harness.php [--cases N] [--seed S] [--family F] [--case N] [--no-shrink] [--quiet]\n";
    exit(0);
}
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
    $runner->run($cases, $onlyFamily, $onlyCase, 150, static function (int $i, array $result) use ($quiet, $cases): void {
        // HARNESS_SLOW=seconds names each case that took longer.
        static $last = null;
        $now = microtime(true);
        if (getenv('HARNESS_SLOW') !== false && $last !== null && $now - $last > (float) getenv('HARNESS_SLOW')) {
            fwrite(STDERR, sprintf("case %d took %.1fs: %s %s %s\n", $i, $now - $last, $result['spec']['series']['family'], $result['spec']['series']['rrule'], $result['path']));
        }
        $last = $now;
        if (!$quiet && $result['records'] !== []) {
            foreach ($result['records'] as $rec) {
                $known = Known::cause(['invariant' => $rec['invariant'], 'path' => $result['path']]) !== null ? ' (known path)' : '';
                echo "case $i: {$rec['invariant']} {$result['spec']['series']['family']} {$result['path']}$known: {$rec['what']}\n";
            }
        }
        if (!$quiet && $i % 500 === 0) {
            fwrite(STDERR, "$i of $cases\n");
        }
    });
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
    ];
    file_put_contents(__DIR__ . '/findings.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));

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
    echo "\n" . ($new === 0 && $misuses === [] ? 'Clean: every failure is under a known code path.' : "$new signature(s) outside the known code paths.") . "\n";
    echo "findings.json has every record" . ($shrink ? ' and a shrunk reproduction per finding' : '') . ".\n";
} finally {
    $server->exec("DROP DATABASE IF EXISTS `$name`");
}
exit(($out['unknownSignatures'] ?? 1) === 0 && $misuses === [] ? 0 : 1);
