<?php

declare(strict_types=1);

// Lists, and with --drop drops, the scratch databases the test runners left
// behind (a failed drop is reported by the runner and does not fail it, so
// leftovers are found here instead). Only names starting with one of
// ScratchDatabase::PREFIXES are considered, and of those only one older than
// --older-than by its oldest table, with no connection using it. A database
// with no tables has no age to read and is left: it may be one a runner made
// a moment ago, or one whose folder holds a stray file (a trigger backup the
// server left, *.TRG-) that DROP DATABASE cannot remove; what to do by hand
// is printed. It refuses a server that is not on this machine unless told.
//
//   BC_TEST_DB=127.0.0.1:3306 php server/tests/db/sweep.php --older-than 6h [--drop] [--remote] [--only NAME,...]
//
// --older-than N with s, m, h or d (required). --drop drops them; without it
// nothing is changed. --remote allows a BC_TEST_DB host other than
// 127.0.0.1, localhost or ::1. BC_TEST_DB_USER and BC_TEST_DB_PASS as the
// suite reads them. --only narrows it to the names given (each still held to
// the prefixes, the age and the connections). Exit 1 when a drop failed or
// the arguments are wrong.

require __DIR__ . '/scratch.php';

$opts = getopt('', ['older-than:', 'drop', 'remote', 'only:']);
if (!isset($opts['older-than']) || !preg_match('/^(\d+)([smhd])$/', (string) $opts['older-than'], $m)) {
    fwrite(STDERR, "--older-than N with s, m, h or d is required, e.g. --older-than 6h.\n");
    exit(1);
}
$minAge = (int) $m[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$m[2]];
[$host, $port] = array_pad(explode(':', getenv('BC_TEST_DB') ?: '127.0.0.1:3306', 2), 2, '3306');
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) && !isset($opts['remote'])) {
    fwrite(STDERR, "BC_TEST_DB names $host, not this machine: nothing done. --remote allows it.\n");
    exit(1);
}
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", getenv('BC_TEST_DB_USER') ?: 'root', getenv('BC_TEST_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$ours = static function (string $name): bool {
    foreach (ScratchDatabase::PREFIXES as $p) {
        if (str_starts_with($name, $p) && preg_match('/^[a-z0-9_]+$/', $name) === 1) {
            return true;
        }
    }
    return false;
};
$only = isset($opts['only']) ? explode(',', (string) $opts['only']) : null;
$names = array_values(array_filter($server->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN), static fn(string $n): bool => $ours($n) && ($only === null || in_array($n, $only, true))));
// Age by the oldest table, on the server's own clock.
$ages = [];
foreach ($server->query('SELECT TABLE_SCHEMA, TIMESTAMPDIFF(SECOND, MIN(CREATE_TIME), NOW()) FROM information_schema.TABLES GROUP BY TABLE_SCHEMA')->fetchAll(PDO::FETCH_NUM) as [$schema, $age]) {
    $ages[$schema] = $age === null ? null : (int) $age;
}
$inUse = array_flip(array_filter(array_map(static fn(array $p): string => (string) ($p['db'] ?? ''), $server->query('SHOW PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC))));

$failed = 0;
$stray = [];
$counts = ['dropped' => 0, 'would drop' => 0, 'left' => 0];
foreach ($names as $name) {
    $age = $ages[$name] ?? null;
    $why = match (true) {
        isset($inUse[$name]) => 'a connection is using it',
        $age === null => 'no tables, so no age to read',
        $age < $minAge => "younger than {$opts['older-than']}",
        default => null,
    };
    $ageText = match (true) {
        $age === null => 'age unknown',
        $age < 7200 => sprintf('%d min old', intdiv($age, 60)),
        default => sprintf('%.1f h old', $age / 3600),
    };
    if ($why !== null) {
        echo "left        $name ($ageText): $why\n";
        $counts['left']++;
        if ($age === null && !isset($inUse[$name])) {
            $stray[] = $name;
        }
        continue;
    }
    if (!isset($opts['drop'])) {
        echo "would drop  $name ($ageText)\n";
        $counts['would drop']++;
        continue;
    }
    if (ScratchDatabase::drop($server, $name)) {
        echo "dropped     $name ($ageText)\n";
        $counts['dropped']++;
    } else {
        // The server dropped what it knows and left the folder: from here on it has no tables, like the others below.
        $failed++;
        $stray[] = $name;
    }
}
printf("\n%d scratch database(s) by prefix (%s): %s.\n", count($names), implode(', ', ScratchDatabase::PREFIXES),
    implode(', ', array_map(static fn(string $k, int $n): string => "$n $k", array_keys($counts), $counts)) . ($failed > 0 ? ", $failed could not be dropped" : ''));
if ($stray !== []) {
    $dir = rtrim((string) $server->query('SELECT @@datadir')->fetchColumn(), '/');
    echo "A database with no tables is either one a runner made moments ago or one whose folder holds a file DROP DATABASE\n"
        . "does not know (a stray trigger backup, *.TRG-), which fails with errno 39. For the second kind, by hand, on the\n"
        . "server's machine (in its container, if it runs in one): list $dir/<name>/, and when it holds only such a file and no\n"
        . "run is using the name, remove the file and then DROP DATABASE `<name>` (or run this again).\n";
}
exit($failed > 0 ? 1 : 0);
