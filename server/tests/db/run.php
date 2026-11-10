<?php

declare(strict_types=1);

// The database suite (CONTRIBUTING.md says which suite a test goes in):
// what depends on the database itself, against MariaDB or MySQL with the
// schema the migrations build. Foreign keys, row locks, two connections at once and the
// migrations' own SQL are the production ones here; the pure-PHP suite
// (../run.php) keeps the rest.
//
// BC_TEST_DB is the server's host:port (default 127.0.0.1:3306), with
// BC_TEST_DB_USER and BC_TEST_DB_PASS (default root, no password). Each run
// makes a database of its own and drops it after. BC_TEST_JOBS=N runs the
// files N at once, each in its own process and database (default: the
// logical CPUs; 1 runs them one after another in this process).

// An install has one; feed addresses are sealed with it (migration 045).
if (getenv('BETTERCAL_SESSION_SECRET') === false) {
    putenv('BETTERCAL_SESSION_SECRET=' . bin2hex(random_bytes(32)));
}

// A change to undoable data journaled outside Undo::write fails the run.
putenv('BETTERCAL_STRICT_JOURNAL=1');

require dirname(__DIR__, 2) . '/src/bootstrap.php';
// The edit harness's mutation runner (tools/edit-harness/mutations) loads a
// patched copy of one class in place of the tree's, and runs one file.
if (getenv('BC_TEST_PRELOAD') !== false) {
    require (string) getenv('BC_TEST_PRELOAD');
}
require __DIR__ . '/lib.php';

// The app as installed: repeating events need Composer's libraries.
if (!class_exists(\Sabre\VObject\Reader::class)) {
    fwrite(STDERR, "The database suite needs Composer's libraries: composer install in server/.\n");
    exit(1);
}

use BetterCal\Infra\Db;
use BetterCal\Infra\Migrator;

// The two checks the runner makes around the files, in every database it
// makes: merged, each counts once, first and last, failing if any run failed it.
const SCHEMA_CHECK = 'schema: every migration applies to an empty database';
const JOURNAL_CHECK = 'every change to undoable data was journaled in Undo::write';

// Every test file BC_TEST_ONLY names (all of them by default), in name order.
$tests = glob(__DIR__ . '/' . (getenv('BC_TEST_ONLY') ?: '*') . '.test.php') ?: [];
sort($tests);

/** The logical CPUs, where the system says; 1 where it doesn't. */
$cpus = static function (): int {
    if (is_readable('/proc/cpuinfo')) {
        return max(1, (int) preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo')));
    }
    $n = (int) @shell_exec('sysctl -n hw.logicalcpu 2>/dev/null');
    return $n > 0 ? $n : max(1, (int) getenv('NUMBER_OF_PROCESSORS'));
};

/** The files run here, in one new database: the report (which files ran to the end, every check). */
$runHere = static function (array $tests): array {
    [$host, $port] = array_pad(explode(':', getenv('BC_TEST_DB') ?: '127.0.0.1:3306', 2), 2, '3306');
    $user = getenv('BC_TEST_DB_USER') ?: 'root';
    $password = getenv('BC_TEST_DB_PASS') ?: '';
    $name = 'bc_test_' . bin2hex(random_bytes(4));
    $server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    // For the second connection (child.php).
    putenv('BC_TEST_DSN=' . "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4");
    putenv("BC_TEST_DB_USER=$user");
    putenv("BC_TEST_DB_PASS=$password");
    $files = [];
    try {
        $db = new Db(['dsn' => "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", 'user' => $user, 'pass' => $password]);
        $migrator = new Migrator($db, dirname(__DIR__, 2) . '/migrations');
        $migrator->run(static function (string $line): void {
        });
        checkEq(SCHEMA_CHECK, [], $migrator->pending());
        // The link rule (lib.php, linkRuleCheck) reads this database after every block and after every file.
        $GLOBALS['__linkDb'] = $db;
        $GLOBALS['__linkSeen'] = [];
        foreach ($tests as $file) {
            $files[basename($file)] = false;
            try {
                (require $file)($db);
                $files[basename($file)] = true;
            } catch (\Throwable $e) {
                // One failure for the throw; the rest of that file is skipped
                // (a block wrapped in scenario() keeps a throw to itself), and
                // the other files still run.
                check(basename($file) . ' ran to the end', false, get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine(), true);
            }
            // The backstop: a violation made outside any block of the file, named by the file.
            linkRuleCheck('made outside any block of ' . basename($file), null, null);
        }
        $GLOBALS['__linkDb'] = null;
        foreach ($GLOBALS['__linkOptedOut'] ?? [] as $block => $o) {
            echo "link rule not counted for the block '$block' ({$o['count']} violation(s)): {$o['why']}\n";
        }
        check(JOURNAL_CHECK, BetterCal\Domain\Undo::misuses() === [], implode("\n  ", BetterCal\Domain\Undo::misuses()));
    } finally {
        $server->exec("DROP DATABASE IF EXISTS `$name`");
    }
    return ['files' => $files, 'checks' => $GLOBALS['__checks']];
};

/**
 * Each file in a process of its own (this script with BC_TEST_ONLY), up to
 * $jobs at once, the rest queued: their reports in file order. Each one's
 * output is printed once it and every file before it have finished. A
 * process that leaves no report fails its file, loudly, with its output.
 */
$runApart = static function (array $tests, int $jobs): array {
    $tmp = sys_get_temp_dir() . '/bc-db-run-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700, true);
    $env = [];
    foreach (['BC_TEST_PRELOAD', 'BC_TEST_DB', 'BC_TEST_DB_USER', 'BC_TEST_DB_PASS', 'BETTERCAL_SESSION_SECRET'] as $k) {
        if (getenv($k) !== false) {
            $env[$k] = (string) getenv($k);
        }
    }
    $queue = array_values($tests);
    $running = [];
    $done = [];
    $start = static function (int $i) use ($queue, $tmp, $env, &$running): void {
        $stem = basename($queue[$i], '.test.php');
        $report = "$tmp/$stem.json";
        $proc = proc_open([PHP_BINARY, __FILE__], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null,
            ['BC_TEST_ONLY' => $stem, 'BC_TEST_REPORT' => $report] + $env + getenv());
        $started = is_resource($proc);
        if ($started) {
            stream_set_blocking($pipes[1], false);
        }
        $running[$i] = ['proc' => $started ? $proc : null, 'pipe' => $started ? $pipes[1] : null,
            'out' => $started ? '' : "could not start a process\n", 'report' => $report, 'at' => microtime(true)];
    };
    $finish = static function (int $i) use ($queue, &$running, &$done): void {
        $r = $running[$i];
        unset($running[$i]);
        $file = basename($queue[$i]);
        $code = $r['proc'] === null ? -1 : proc_close($r['proc']);
        $report = is_file($r['report']) ? json_decode((string) file_get_contents($r['report']), true) : null;
        // The child's own totals line; the parent prints the file's instead.
        $out = (string) preg_replace('/\n\d+ passed, \d+ failed\n$/', '', $r['out']);
        if (!is_array($report) || !isset($report['files'], $report['checks'])) {
            $report = ['files' => [$file => false], 'checks' => [['name' => "$file ran to the end", 'pass' => false,
                'detail' => "its process exited $code and left no report", 'threw' => true]]];
            $out = "FAIL: $file: its process exited $code and left no report; its output:\n" . $r['out'] . "\n";
        }
        $failed = count(array_filter($report['checks'], static fn(array $c): bool => !$c['pass']));
        $done[$i] = ['report' => $report, 'out' => $out . sprintf("%s: %d passed, %d failed (%.1f s)\n", $file, count($report['checks']) - $failed, $failed, microtime(true) - $r['at'])];
    };
    try {
        $next = 0;
        $printed = 0;
        while ($next < count($queue) || $running !== []) {
            while ($next < count($queue) && count($running) < $jobs) {
                $start($next++);
            }
            $read = array_values(array_filter(array_column($running, 'pipe')));
            if ($read !== []) {
                $w = $e = null;
                stream_select($read, $w, $e, null);
            }
            foreach ($running as $i => $r) {
                if ($r['pipe'] !== null) {
                    $running[$i]['out'] .= (string) stream_get_contents($r['pipe']);
                    if (!feof($r['pipe'])) {
                        continue;
                    }
                    fclose($r['pipe']);
                }
                $finish($i);
            }
            while (isset($done[$printed])) {
                echo $done[$printed++]['out'];
            }
        }
    } finally {
        foreach (glob("$tmp/*") ?: [] as $f) {
            unlink($f);
        }
        rmdir($tmp);
    }
    ksort($done);
    return array_column($done, 'report');
};

/**
 * Reports, in file order, as one: which files ran to the end, the runner's
 * own checks once each (failing if any report failed one, with each one's
 * detail), every other check in order, and the totals counted from them.
 */
$merge = static function (array $reports): array {
    $files = [];
    $own = [SCHEMA_CHECK => null, JOURNAL_CHECK => null];
    $body = [];
    foreach ($reports as $r) {
        $files += $r['files'];
        foreach ($r['checks'] as $c) {
            if (!array_key_exists($c['name'], $own)) {
                $body[] = $c;
                continue;
            }
            $was = $own[$c['name']] ?? ['name' => $c['name'], 'pass' => true];
            if (!$c['pass']) {
                $where = count($reports) > 1 ? '(' . implode(', ', array_keys($r['files'])) . ') ' : '';
                $was = ['name' => $c['name'], 'pass' => false, 'detail' => ($was['pass'] ? '' : $was['detail'] . "\n  ") . $where . $c['detail'],
                    'threw' => ($was['threw'] ?? false) || $c['threw']];
            }
            $own[$c['name']] = $was;
        }
    }
    $checks = array_merge($own[SCHEMA_CHECK] === null ? [] : [$own[SCHEMA_CHECK]], $body, $own[JOURNAL_CHECK] === null ? [] : [$own[JOURNAL_CHECK]]);
    $failed = count(array_filter($checks, static fn(array $c): bool => !$c['pass']));
    return ['files' => $files, 'checks' => $checks, 'passed' => count($checks) - $failed, 'failed' => $failed];
};

// One file named by BC_TEST_ONLY, or BC_TEST_JOBS=1, runs here in this process.
$jobs = getenv('BC_TEST_ONLY') !== false ? 1 : min(count($tests), getenv('BC_TEST_JOBS') !== false ? max(1, (int) getenv('BC_TEST_JOBS')) : $cpus());
$result = $merge($jobs > 1 ? $runApart($tests, $jobs) : [$runHere($tests)]);

// The checks by label, for the mutation runner: which files ran (and to the end), every check, pass or fail.
if (getenv('BC_TEST_REPORT') !== false) {
    file_put_contents((string) getenv('BC_TEST_REPORT'), json_encode($result));
}
echo "\n{$result['passed']} passed, {$result['failed']} failed\n";
exit($result['failed'] === 0 ? 0 : 1);
