<?php

declare(strict_types=1);

// Applies server/migrations/*.sql and *.php in filename order, tracked in
// schema_migrations. Idempotent: already-applied files are skipped. The work
// is in BetterCal\Infra\Migrator, which install.php and update.php share.

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Infra\Db;
use BetterCal\Infra\Migrator;

$cfg = config();
$migrator = new Migrator(new Db($cfg['db']), dirname(__DIR__) . '/migrations');
try {
    $ran = $migrator->run(static function (string $line): void {
        echo $line, "\n";
    });
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo $ran === 0 ? "Nothing to migrate.\n" : "Applied $ran migration(s).\n";
