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
try {
    $ran = $migrator->run(static function (string $line): void {
        echo $line, "\n";
    });
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo $ran === 0 ? "Nothing to migrate.\n" : "Applied $ran migration(s).\n";
