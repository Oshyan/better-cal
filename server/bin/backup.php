<?php

declare(strict_types=1);

// Database backups (Infra\DbBackup).
//
//   php server/bin/backup.php --decrypt FILE OUT   write an encrypted backup out as plain .sql.gz
//
// Backups are encrypted with a key made from BETTERCAL_SESSION_SECRET, so
// this needs the settings the backup was made with. The plain copy is for
// restoring with phpMyAdmin or the mysql client:
//   gunzip < OUT | mysql -u USER -p DBNAME
// It holds everything in your calendar; delete it when you're done.

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Infra\BackupCipher;
use BetterCal\Infra\DbBackup;
use BetterCal\Support\Cli;

$args = array_slice($argv, 1);
if (count($args) !== 3 || $args[0] !== '--decrypt') {
    Cli::say('Usage: php server/bin/backup.php --decrypt FILE.bcbackup OUT.sql.gz');
    exit(1);
}
[, $in, $out] = $args;
$secret = (string) config()['session_secret'];
if ($secret === '') {
    Cli::say('BETTERCAL_SESSION_SECRET is empty, so there is no key to open the backup with.');
    exit(1);
}
try {
    DbBackup::decryptFile($in, BackupCipher::keyFrom($secret), $out);
} catch (\Throwable $e) {
    Cli::say('Stopped: ' . $e->getMessage());
    exit(1);
}
Cli::say("Wrote $out. It holds everything in your calendar, unencrypted; delete it when you're done.");
