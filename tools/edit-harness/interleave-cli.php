<?php
// The interleaving mode's command line (README, Interleaving), reached through harness.php --interleave:
//
//   BC_TEST_DB=127.0.0.1:3306 ./tools/edit-harness/run.sh --interleave [--seed S] [--cases N] [--case N] [--fixed[=NAME,...]]
//       [--no-shrink] [--quiet] [--findings FILE] [--model FILE] [--save-spec DIR] [--shards N]
//
// Makes a database of its own, migrates it, runs each drawn case (a Google-backed series and a run of steps) on an
// account of its own, then the fixed cases (cases/interleave/*.json; --cases 0 --fixed for them alone), shrinks one
// failing sequence per signature to its shortest prefix and subset, and writes the findings file.
declare(strict_types=1);

$opts = getopt('', ['interleave', 'cases:', 'seed:', 'case:', 'no-shrink', 'quiet', 'fixed::', 'findings:', 'model:', 'save-spec:', 'shards:', 'shard:', 'partial:']);
$cases = (int) ($opts['cases'] ?? 200);
$seed = (int) ($opts['seed'] ?? 1);
$onlyCase = isset($opts['case']) ? (int) $opts['case'] : null;
$shrink = !isset($opts['no-shrink']);
$quiet = isset($opts['quiet']);
$fixed = !array_key_exists('fixed', $opts) ? null : ($opts['fixed'] === false ? [] : explode(',', (string) $opts['fixed']));
$findingsFile = isset($opts['findings']) ? (string) $opts['findings'] : __DIR__ . '/findings-interleave.json';
$saveSpec = isset($opts['save-spec']) ? (string) $opts['save-spec'] : null;
$shards = $onlyCase !== null ? 1 : max(1, (int) ($opts['shards'] ?? 1));
$child = isset($opts['shard']);
[$shard, $ofShards] = $child ? array_map('intval', explode('/', (string) $opts['shard'], 2)) + [1 => 1] : [0, 1];

$root = dirname(__DIR__, 2);
if (!is_file($root . '/server/vendor/autoload.php')) {
    fwrite(STDERR, "Needs Composer's libraries: composer install in server/.\n");
    exit(1);
}

if ($shards > 1 && !$child) {
    // N processes, each on a database of its own, cases by number modulo N; the fixed cases on the first; merged here.
    $tmp = sys_get_temp_dir() . '/interleave-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    $procs = [];
    for ($k = 0; $k < $shards; $k++) {
        $cmd = [PHP_BINARY, __FILE__, '--interleave', '--cases', (string) $cases, '--seed', (string) $seed, '--shard', "$k/$shards", '--partial', "$tmp/$k.json", '--quiet'];
        foreach (['no-shrink', 'model', 'fixed'] as $o) {
            if (array_key_exists($o, $opts)) {
                $cmd[] = $opts[$o] === false ? "--$o" : "--$o=" . $opts[$o];
            }
        }
        $procs[$k] = proc_open($cmd, [1 => ['file', "$tmp/$k.out", 'w'], 2 => ['redirect', 1]], $pipes);
    }
    $parts = [];
    foreach ($procs as $k => $p) {
        $code = proc_close($p);
        $part = is_file("$tmp/$k.json") ? json_decode((string) file_get_contents("$tmp/$k.json"), true) : null;
        if (!is_array($part)) {
            fwrite(STDERR, "shard $k wrote nothing (exit $code):\n" . substr((string) @file_get_contents("$tmp/$k.out"), -3000) . "\n");
            exit(1);
        }
        $parts[] = $part;
    }
    array_map('unlink', glob("$tmp/*") ?: []);
    rmdir($tmp);
    $merged = ['records' => [], 'minimal' => [], 'counts' => ['cases' => 0, 'steps' => 0], 'runs' => [], 'na' => [], 'seconds' => 0];
    foreach ($parts as $part) {
        $merged['records'] = array_merge($merged['records'], $part['records']);
        $merged['minimal'] += $part['minimal'];
        $merged['counts']['cases'] += $part['counts']['cases'];
        $merged['counts']['steps'] += $part['counts']['steps'];
        foreach ($part['runs'] as $c => $byRole) {
            foreach ($byRole as $role => $n) {
                $merged['runs'][$c][$role] = ($merged['runs'][$c][$role] ?? 0) + $n;
            }
        }
        foreach ($part['na'] as $why => $n) {
            $merged['na'][$why] = ($merged['na'][$why] ?? 0) + $n;
        }
        $merged['seconds'] = max($merged['seconds'], $part['seconds']);
    }
    usort($merged['records'], static fn(array $a, array $b): int => [isset($a['spec']['fixed']) ? 1 : 0, $a['n']] <=> [isset($b['spec']['fixed']) ? 1 : 0, $b['n']]);
    exit(interleaveReport($merged, $findingsFile, $seed, $quiet));
}

if (getenv('BETTERCAL_SESSION_SECRET') === false) {
    putenv('BETTERCAL_SESSION_SECRET=' . bin2hex(random_bytes(32)));
}
putenv('BETTERCAL_STRICT_JOURNAL=1');
// Every failing record keeps its case's spec, and the sync refuses to start without memory to spare.
ini_set('memory_limit', '2048M');
require $root . '/server/src/bootstrap.php';
if (isset($opts['model'])) {
    // A patched copy of one product class (the mutation runner's), declared before anything autoloads the tree's.
    require (string) $opts['model'];
}
require $root . '/server/tests/db/lib.php';
require __DIR__ . '/lib.php';
require_once __DIR__ . '/Interleave.php';
BetterCal\EditHarness\Product::use($root);

use BetterCal\EditHarness\GoogleStand;
use BetterCal\EditHarness\Interleave;
use BetterCal\EditHarness\Revision;
use BetterCal\EditHarness\Warnings;
use BetterCal\Infra\Db;
use BetterCal\Infra\Migrator;

[$host, $port] = array_pad(explode(':', getenv('BC_TEST_DB') ?: '127.0.0.1:3306', 2), 2, '3306');
$user = getenv('BC_TEST_DB_USER') ?: 'root';
$password = getenv('BC_TEST_DB_PASS') ?: '';
// A fresh database every this many cases: a case's events stay until its database is dropped, and MariaDB 10.11's
// InnoDB fulltext cache, grown past innodb_ft_cache_size by thousands of event rows, has crashed the server on a DELETE;
// DROP DATABASE never takes the fulltext delete path.
const CASES_PER_DATABASE = 50;
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = null;
$started = microtime(true);
try {
    $box = (object) ['db' => null, 'domains' => null, 'used' => 0];
    /** Drops the database in use, if any, and makes and migrates a new one. */
    $fresh = static function () use ($box, $server, $host, $port, $user, $password, $root, &$name): void {
        if ($name !== null) {
            $box->db = $box->domains = null;
            gc_collect_cycles();
            $server->exec("DROP DATABASE IF EXISTS `$name`");
        }
        $name = 'bc_interleave_' . bin2hex(random_bytes(4));
        $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $box->db = new Db(['dsn' => "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", 'user' => $user, 'pass' => $password]);
        (new Migrator($box->db, $root . '/server/migrations'))->run(static function (string $line): void {
        });
        $box->domains = domains($box->db);
        $box->used = 0;
    };
    $fresh();
    /** One case on an account and a stand-in of its own, on a database that is fresh every CASES_PER_DATABASE cases. */
    $runCase = static function (array $spec) use ($box, $fresh): array {
        if ($box->used >= CASES_PER_DATABASE) {
            $fresh();
        }
        $box->used++;
        $db = $box->db;
        $u = freshUser($db);
        $stand = GoogleStand::make($db, $box->domains->undo, $u);
        $case = new Interleave($db, $stand->events, new BetterCal\Domain\Undo($db, null, $stand->writer), $stand, $u);
        try {
            return Warnings::during(static fn(): array => $case->run($spec));
        } finally {
            GoogleStand::forget($db, $u);
        }
    };
    $out = ['records' => [], 'minimal' => [], 'counts' => ['cases' => 0, 'steps' => 0], 'runs' => [], 'na' => []];
    $count = static function (array $result) use (&$out): void {
        $spec = $result['spec'];
        $out['counts']['cases']++;
        $out['counts']['steps'] += count($spec['steps']);
        foreach ($result['runs'] as $c => $byRole) {
            foreach ($byRole as $role => $n) {
                $out['runs'][$c][$role] = ($out['runs'][$c][$role] ?? 0) + $n;
            }
        }
        foreach ($result['product']['na'] ?? [] as $why => $n) {
            $out['na'][$why] = ($out['na'][$why] ?? 0) + $n;
        }
        foreach ($result['records'] as $rec) {
            $out['records'][] = ['n' => $spec['n'] ?? 0, 'seed' => $spec['seed'] ?? 0, 'revision' => Revision::get(), 'family' => $spec['series']['family'] ?? '?', 'path' => 'interleave'] + $rec + ['spec' => $spec];
        }
    };
    for ($i = 1; $i <= $cases; $i++) {
        if (($onlyCase !== null && $i !== $onlyCase) || $i % $ofShards !== $shard % $ofShards) {
            continue;
        }
        $result = $runCase(Interleave::draw($seed, $i));
        $count($result);
        if ($saveSpec !== null) {
            file_put_contents("$saveSpec/interleave-seed$seed-case$i.json", json_encode(array_diff_key($result['spec'], ['create' => 1]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
        if (!$quiet) {
            foreach ($result['records'] as $rec) {
                echo "case $i: {$rec['invariant']}: {$rec['what']}\n";
            }
            if ($i % 50 === 0) {
                fwrite(STDERR, "$i of $cases\n");
            }
        }
    }
    if ($shard === 0 && ($fixed !== null || ($cases >= 500 && $onlyCase === null))) {
        $files = glob(__DIR__ . '/cases/interleave/*.json') ?: [];
        sort($files);
        if ($fixed !== null && $fixed !== []) {
            $files = array_map(static fn(string $f): string => is_file($f) ? $f : __DIR__ . '/cases/interleave/' . preg_replace('/\.json$/', '', $f) . '.json', $fixed);
        }
        foreach ($files as $file) {
            $spec = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $spec['fixed'] = basename($file, '.json');
            $spec += ['seed' => 0, 'n' => 0];
            foreach ($spec['steps'] as $k => $st) {
                unset($spec['steps'][$k]['ran']);
            }
            $result = $runCase($spec);
            $count($result);
            if (!$quiet) {
                foreach ($result['records'] as $rec) {
                    echo "{$spec['fixed']}: {$rec['invariant']}: {$rec['what']}\n";
                }
            }
        }
    }
    // One shrunk reproduction per signature: the shortest prefix through the failing step, then each other step dropped while it still fails the same way.
    if ($shrink) {
        $first = [];
        foreach ($out['records'] as $rec) {
            $first[interleaveSignature($rec)] ??= $rec;
        }
        foreach ($first as $sig => $rec) {
            $spec = $rec['spec'];
            unset($spec['fixed']);
            $fails = static function (array $steps) use ($spec, $sig, $runCase): bool {
                $try = $spec;
                $try['steps'] = array_values(array_map(static fn(array $s): array => array_diff_key($s, ['ran' => 1]), $steps));
                foreach ($runCase($try)['records'] as $r) {
                    if (interleaveSignature($r) === $sig) {
                        return true;
                    }
                }
                return false;
            };
            $steps = array_slice($spec['steps'], 0, (int) ($rec['detail']['step'] ?? count($spec['steps'])));
            if (!$fails($steps)) {
                $steps = $spec['steps'];
            }
            for ($k = count($steps) - 2; $k >= 0; $k--) {
                $less = $steps;
                array_splice($less, $k, 1);
                if ($fails($less)) {
                    $steps = $less;
                }
            }
            $spec['steps'] = array_values(array_map(static fn(array $s): array => array_diff_key($s, ['ran' => 1]), $steps));
            $out['minimal'][$sig] = array_diff_key($spec, ['create' => 1]);
        }
    }
    $out['seconds'] = round(microtime(true) - $started, 1);
} finally {
    if ($name !== null) {
        $server->exec("DROP DATABASE IF EXISTS `$name`");
    }
}
if ($child) {
    file_put_contents((string) $opts['partial'], json_encode($out, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    exit(0);
}
exit(interleaveReport($out, $findingsFile, $seed, $quiet));

/** A failure's signature, for one reproduction each: the invariant and the step kind it failed after. */
function interleaveSignature(array $rec): string
{
    return $rec['invariant'] . ' | ' . ($rec['detail']['do'] ?? '?');
}

function interleaveReport(array $out, string $findingsFile, int $seed, bool $quiet): int
{
    file_put_contents($findingsFile, json_encode(['mode' => 'interleave', 'seed' => $seed] + $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    $bySig = [];
    foreach ($out['records'] as $rec) {
        $sig = interleaveSignature($rec);
        $bySig[$sig][] = $rec;
    }
    ksort($bySig);
    echo "\nInterleaving: {$out['counts']['cases']} cases, {$out['counts']['steps']} steps, {$out['seconds']}s, seed $seed\n";
    foreach ($out['runs'] as $check => $byRole) {
        if (!str_starts_with($check, 'x-')) {
            continue;
        }
        echo "  $check: " . array_sum($byRole) . " runs\n";
    }
    $required = ['x-alone', 'x-sync-silent', 'x-local-silent', 'x-write-asked', 'x-agree', 'x-structure', 'x-own', 'x-conflict', 'x-pending', 'x-finish', 'x-putback', 'x-undo-fields', 'x-undo-bare', 'x-honest'];
    $missing = array_values(array_filter($required, static fn(string $c): bool => array_sum($out['runs'][$c] ?? []) === 0));
    foreach ($out['na'] as $why => $n) {
        echo "  not applicable ($n): $why\n";
    }
    echo count($out['records']) . " failing checks, " . count($bySig) . " signatures\n";
    foreach ($bySig as $sig => $list) {
        $cases = array_unique(array_map(static fn(array $r): string => (string) ($r['spec']['fixed'] ?? $r['n']), $list));
        echo "  $sig: " . count($list) . ' in ' . count($cases) . ' cases (first ' . reset($cases) . '): ' . $list[0]['what'] . "\n";
    }
    if ($missing !== [] && $out['counts']['cases'] >= 500) {
        echo 'Missing: ' . implode(', ', $missing) . " never ran.\n";
        return 1;
    }
    return $out['records'] === [] ? 0 : 1;
}
