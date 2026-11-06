<?php

declare(strict_types=1);

// One checkout's run of every case (run.php starts one per version, each in
// a process of its own, since two versions' classes can't share one).
//
//   php replay.php <checkout> <label> <host:port> <report.json> [case ...]
//
// Boots <checkout>'s server/src, makes the database cmp_issue_<label>,
// migrates it with that checkout's server/migrations, runs each case in
// cases/ (or those named) on an account of its own, writes the results as
// JSON and drops the database.

[, $checkout, $label, $hostPort, $reportFile] = $argv + [null, null, null, null, null];
if ($reportFile === null) {
    fwrite(STDERR, "usage: php replay.php <checkout> <label> <host:port> <report.json> [case ...]\n");
    exit(2);
}
$only = array_slice($argv, 5);
$checkout = rtrim(realpath($checkout) ?: $checkout, '/');

// An install has one; migration 045 needs it.
if (getenv('BETTERCAL_SESSION_SECRET') === false) {
    putenv('BETTERCAL_SESSION_SECRET=' . bin2hex(random_bytes(32)));
}
date_default_timezone_set('UTC');
require $checkout . '/server/src/bootstrap.php';
require __DIR__ . '/lib.php';

use BetterCal\Infra\Db;
use BetterCal\Infra\Migrator;

[$host, $port] = array_pad(explode(':', $hostPort, 2), 2, '3306');
$user = getenv('BC_TEST_DB_USER') ?: 'root';
$pass = getenv('BC_TEST_DB_PASS') ?: '';
$name = 'cmp_issue_' . preg_replace('/[^a-z0-9]+/', '', strtolower($label));
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `$name`");
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$results = [];
try {
    $db = new Db(['dsn' => "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", 'user' => $user, 'pass' => $pass]);
    (new Migrator($db, $checkout . '/server/migrations'))->run(static function (string $line): void {
    });
    $version = trim((string) @file_get_contents($checkout . '/VERSION'));
    $cases = glob(__DIR__ . '/cases/*.php') ?: [];
    sort($cases);
    foreach ($cases as $file) {
        $case = require $file;
        if ($only !== [] && !in_array($case['id'], $only, true)) {
            continue;
        }
        $row = ['id' => $case['id'], 'issue' => $case['issue'], 'title' => $case['title']];
        try {
            $api = Api::fresh($db);
            $checks = ($case['run'])($api, $version);
            $failed = array_values(array_filter($checks, static fn(array $c): bool => $c['pass'] === false));
            $na = array_values(array_filter($checks, static fn(array $c): bool => $c['pass'] === null));
            $row['verdict'] = $failed !== [] ? 'fail' : ($na !== [] && count($na) === count($checks) ? 'na' : 'pass');
            $row['checks'] = $checks;
        } catch (NotApplicable $e) {
            $row['verdict'] = 'na';
            $row['checks'] = [['name' => 'applies', 'pass' => null, 'evidence' => $e->getMessage()]];
        } catch (\Throwable $e) {
            $row['verdict'] = 'error';
            $row['checks'] = [['name' => 'ran', 'pass' => false, 'evidence' => get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine()]];
        }
        $results[] = $row;
    }
} finally {
    $server->exec("DROP DATABASE IF EXISTS `$name`");
}
file_put_contents($reportFile, json_encode(['label' => $label, 'version' => $version ?? '', 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
