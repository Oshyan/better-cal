<?php

declare(strict_types=1);

// Applies server/migrations/*.sql and *.php in filename order, tracked in
// schema_migrations. Idempotent: already-applied files are skipped. The work
// is in BetterCal\Infra\Migrator, which install.php and update.php share.
// It first waits out a worker job (or a plugin run a request started), as
// update.php does: no job writes with one version's code to the other's
// schema. Maintenance mode (deploy.sh, update.php) keeps the next from
// starting; by hand, run it when the worker is quiet.

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Infra\Db;
use BetterCal\Infra\Migrator;
use BetterCal\Infra\Updater;

$cfg = config();
$db = new Db($cfg['db']);
try {
    $held = (int) $db->scalar('SELECT GET_LOCK(?, 90)', [Updater::workerLockName((string) $cfg['db']['dsn'])]) === 1;
} catch (\PDOException) {
    $held = true; // no named locks here (SQLite): nothing runs beside this
}
if (!$held) {
    fwrite(STDERR, "A worker job is still running after 90 seconds; try again in a minute.\n");
    exit(1);
}
$migrator = new Migrator($db, dirname(__DIR__) . '/migrations');
// --dry-run: say what each pending data migration would change, writing
// nothing. A PHP migration that takes a second argument reads it as a dry
// run (064 does); any other is named and left for the real run.
if (in_array('--dry-run', $argv, true)) {
    foreach ($migrator->pending() as $file) {
        $name = basename($file);
        $fn = str_ends_with($name, '.php') ? require $file : null;
        $dry = $fn instanceof \Closure && (new \ReflectionFunction($fn))->getNumberOfParameters() >= 2;
        echo $name, ': ', $dry ? $fn($db, true) : 'no dry run; it applies when migrating', "\n";
    }
    exit(0);
}
try {
    $ran = $migrator->run(static function (string $line): void {
        echo $line, "\n";
    });
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo $ran === 0 ? "Nothing to migrate.\n" : "Applied $ran migration(s).\n";
