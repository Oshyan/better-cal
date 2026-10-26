<?php

declare(strict_types=1);

// The settings file and the other private files (docs/private-files.md).
//
//   php server/bin/settings.php --convert   move .env to .env.php and encrypt older backups
//   php server/bin/settings.php --check     can the private files be downloaded? (as Settings, System says)
//
// Better-Cal converts on its own after an update, for files that belong to
// the account it runs as. Run this as the account that owns .env (root, on a
// server where .env is root-owned) to convert the rest.

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Infra\PrivateFiles;
use BetterCal\Infra\PrivateFilesCheck;
use BetterCal\Infra\DbBackup;
use BetterCal\Support\Cli;
use BetterCal\Support\SettingsFile;

$opt = getopt('', ['convert', 'check', 'new-secret-line', 'help']);
if (!isset($opt['convert']) && !isset($opt['check']) && !isset($opt['new-secret-line'])) {
    Cli::say('Usage: php server/bin/settings.php --convert | --check | --new-secret-line');
    exit(isset($opt['help']) ? 0 : 1);
}

$cfg = config();
if (isset($opt['new-secret-line'])) {
    if ((string) ($cfg['session_secret'] ?? '') !== '') {
        Cli::say('A session secret is already configured. Nothing changed.');
        exit(1);
    }
    Cli::say('Add this private line to .env.php, or replace its empty BETTERCAL_SESSION_SECRET= line. Keep the first guard line.');
    Cli::say('If the original secret was lost, recover it instead to keep old encrypted backups and service credentials readable.');
    Cli::say(DbBackup::newSecretLine());
    exit(0);
}
if (isset($opt['check'])) {
    $r = PrivateFilesCheck::run($cfg);
    Cli::say($r['title'] . ' ' . $r['message']);
    foreach ($r['notes'] as $note) {
        Cli::say('  - ' . $note);
    }
    exit($r['status'] === 'exposed' ? 2 : 0);
}
$lines = PrivateFiles::secure($cfg, true);
foreach ($lines as $line) {
    Cli::say($line);
}
$failed = array_filter($lines, static fn(string $l): bool => str_starts_with($l, "Couldn't"));
if (is_file(SettingsFile::plain($cfg['app_root']))) {
    if (is_file(SettingsFile::guarded($cfg['app_root']))) {
        Cli::say('Both .env and .env.php exist and hold different settings. Better-Cal uses .env.php; merge anything');
        Cli::say('you still need from .env into it, then delete .env.');
    } else {
        Cli::say('.env is still there: this account could not convert it.');
    }
    exit(1);
}
if ($lines === []) {
    Cli::say('Nothing to convert: the settings are in .env.php and the backups are encrypted.');
}
exit($failed === [] ? 0 : 1);
