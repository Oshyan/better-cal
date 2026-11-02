<?php
// Property harness for repeating-series edits, command-line entry. Makes a
// database of its own on the server BC_TEST_DB names, migrates it, runs the
// cases and drops it. See README.md.
//
//   BC_TEST_DB=127.0.0.1:3306 php tools/edit-harness/harness.php [--cases N] [--seed S]
//       [--family F] [--edit E] [--case N] [--no-shrink] [--quiet]
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['cases:', 'seed:', 'family:', 'edit:', 'case:', 'no-shrink', 'quiet', 'help']);
if (isset($opts['help'])) {
    echo "Usage: BC_TEST_DB=host:port php harness.php [--cases N] [--seed S] [--family F] [--edit E] [--case N] [--no-shrink] [--quiet]\n";
    exit(0);
}
$cases = (int) ($opts['cases'] ?? 3000);
$seed = (int) ($opts['seed'] ?? 1);
$onlyFamily = isset($opts['family']) ? (string) $opts['family'] : null;
$onlyEdit = isset($opts['edit']) ? (string) $opts['edit'] : null;
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
try {
    $db = new Db(['dsn' => "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", 'user' => $user, 'pass' => $password]);
    (new Migrator($db, $root . '/server/migrations'))->run(static function (string $line): void {
    });
    $runner = new Runner($db, domains($db), $seed);
    $runner->run($cases, $onlyFamily, $onlyEdit, $onlyCase, 150, static function (int $i, array $result) use ($quiet, $cases): void {
        if (!$quiet && $result['records'] !== []) {
            $spec = $result['spec'];
            foreach ($result['records'] as $rec) {
                echo "case $i: {$rec['invariant']} {$spec['series']['family']} {$spec['edit']['kind']}" . (isset($spec['edit']['from']) ? " ({$spec['edit']['from']})" : '') . ": {$rec['what']}\n";
            }
        }
        if (!$quiet && $i % 500 === 0) {
            fwrite(STDERR, "$i of $cases\n");
        }
    });
    $misuses = BetterCal\Domain\Undo::misuses();

    // One minimal reproduction per signature.
    $bySignature = [];
    foreach ($runner->failures as $f) {
        $bySignature[Runner::signature($f)][] = $f;
    }
    $minimal = [];
    if ($shrink) {
        foreach ($bySignature as $sig => $list) {
            $minimal[$sig] = $runner->shrink($list[0]);
        }
    }
    $elapsed = round(microtime(true) - $started, 1);
    $out = [
        'seed' => $seed, 'cases' => $runner->counts['cases'], 'seconds' => $elapsed,
        'byFamily' => $runner->counts['byFamily'], 'byEdit' => $runner->counts['byEdit'],
        'failures' => $runner->counts['failures'], 'failuresByFamily' => $runner->counts['failuresByFamily'],
        'signatures' => array_map('count', $bySignature),
        'minimal' => $minimal,
        'records' => $runner->failures,
        'journalMisuses' => $misuses,
    ];
    file_put_contents(__DIR__ . '/findings.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    echo "\n{$runner->counts['cases']} cases in {$elapsed}s (seed $seed)\n";
    ksort($runner->counts['byFamily']);
    foreach ($runner->counts['byFamily'] as $family => $n) {
        echo sprintf("  %-20s %5d\n", $family, $n);
    }
    $total = count($runner->failures);
    echo "\n$total failing check(s), " . count($bySignature) . " signature(s)" . ($misuses !== [] ? ', ' . count($misuses) . ' journal misuse(s)' : '') . "\n";
    ksort($bySignature);
    $new = 0;
    $byCause = [];
    foreach ($bySignature as $sig => $list) {
        $cause = Known::rootCause($sig);
        $byCause[$cause ?? 'NEW'] = ($byCause[$cause ?? 'NEW'] ?? 0) + count($list);
        if ($cause === null) {
            $new++;
        }
        echo sprintf("  %4d  %-60s %s\n", count($list), $sig, $cause ?? 'NEW');
    }
    ksort($byCause);
    echo "\nBy root cause (README, \"What it finds\"):\n";
    foreach ($byCause as $cause => $n) {
        echo sprintf("  %4d  %s\n", $n, $cause);
    }
    echo "\n" . ($new === 0 ? 'Clean: no signature outside the known root causes.' : "$new signature(s) outside the known root causes.") . "\n";
    echo "findings.json has every record" . ($shrink ? ' and a minimal reproduction per signature' : '') . ".\n";
    $out['byCause'] = $byCause;
    $out['unknownSignatures'] = $new;
    file_put_contents(__DIR__ . '/findings.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
} finally {
    $server->exec("DROP DATABASE IF EXISTS `$name`");
}
exit(($out["unknownSignatures"] ?? 0) === 0 && $misuses === [] ? 0 : 1);
