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
// makes a database of its own and drops it after.

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
    foreach (glob(__DIR__ . '/' . (getenv('BC_TEST_ONLY') ?: '*') . '.test.php') as $file) {
        try {
            (require $file)($db);
        } catch (\Throwable $e) {
            // One failure for the throw; the rest of that file is skipped
            // (a block wrapped in scenario() keeps a throw to itself), and
            // the other files still run.
            check(basename($file) . ' ran to the end', false, get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }
    check('every change to undoable data was journaled in Undo::write', BetterCal\Domain\Undo::misuses() === [], implode("\n  ", BetterCal\Domain\Undo::misuses()));
} finally {
    $server->exec("DROP DATABASE IF EXISTS `$name`");
}

$pass = $GLOBALS['__pass'];
$fail = $GLOBALS['__fail'];
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
