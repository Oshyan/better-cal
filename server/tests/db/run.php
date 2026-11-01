<?php

declare(strict_types=1);

// The database suite (server/tests/README.md): what depends on the
// database itself, against MariaDB or MySQL with the schema the migrations
// build. Foreign keys, row locks, two connections at once and the
// migrations' own SQL are the production ones here; the pure-PHP suite
// (../run.php) keeps the rest.
//
// BC_TEST_DB is the server's host:port (default 127.0.0.1:3306), with
// BC_TEST_DB_USER and BC_TEST_DB_PASS (default root, no password). Each run
// makes a database of its own and drops it after.

// An install has one; feed addresses are sealed with it (migration 045).
if (getenv('BETTERCAL_SESSION_SECRET') === false) {
    putenv('BETTERCAL_SESSION_SECRET=' . bin2hex(random_bytes(32)));
}

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require __DIR__ . '/lib.php';

use BetterCal\Infra\Db;
use BetterCal\Infra\Migrator;

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

try {
    $db = new Db(['dsn' => "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", 'user' => $user, 'pass' => $password]);
    $migrator = new Migrator($db, dirname(__DIR__, 2) . '/migrations');
    $migrator->run(static function (string $line): void {
    });
    checkEq('schema: every migration applies to an empty database', [], $migrator->pending());
    foreach (glob(__DIR__ . '/*.test.php') as $file) {
        try {
            (require $file)($db);
        } catch (\Throwable $e) {
            // The rest of that file is skipped; the other files still run.
            check(basename($file) . ' ran to the end', false, get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }
} finally {
    $server->exec("DROP DATABASE IF EXISTS `$name`");
}

$pass = $GLOBALS['__pass'];
$fail = $GLOBALS['__fail'];
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
