<?php

declare(strict_types=1);

// Read-only deploy preflight, streamed to the existing install before code is
// copied. It answers from the authoritative migration ledger, so a failed
// rsync/migrate attempt cannot make a retry look complete merely because the
// new migration file is already present.

$appDir = rtrim((string) getenv('APP_DIR'), '/');
$filename = (string) getenv('MIGRATION_FILE');
if ($appDir === '' || $filename === '' || basename($filename) !== $filename) {
    fwrite(STDERR, "APP_DIR and a migration filename are required\n");
    exit(2);
}

require $appDir . '/server/src/bootstrap.php';

$db = new BetterCal\Infra\Db(config()['db']);
try {
    echo $db->scalar('SELECT filename FROM schema_migrations WHERE filename = ?', [$filename]) === $filename ? "1\n" : "0\n";
} catch (PDOException $e) {
    $message = strtolower($e->getMessage());
    if ((string) $e->getCode() === '42S02' || str_contains($message, 'no such table')) {
        echo "0\n";
        exit(0);
    }
    throw $e;
}
