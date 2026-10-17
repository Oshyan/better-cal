<?php

declare(strict_types=1);

// Update to the newest release (docs/install.md, Updating):
//
//   php server/bin/update.php            check, show what the update needs, ask, update
//   php server/bin/update.php --check    only say whether there is one
//   php server/bin/update.php --yes      don't ask
//   php server/bin/update.php --rollback put the previous version and the database back
//
// It finds the newest release on GitHub and checks it the way the app's
// update notice does (a published, immutable release with a valid manifest),
// then: backs up the database, puts the site in maintenance mode, installs
// the new code (from the release zip, its SHA-256 checked against GitHub's
// digest; or, in a git checkout, by checking out the release tag), runs the
// new code's migrations, and brings the site back. The previous version and
// the backup stay until you remove them, and --rollback puts both back.
//
// The last part (migrate, check, leave maintenance mode) runs as the NEW
// code: update.php starts `update.php --finish` from it. Where this PHP may
// not start programs, it says to run that command yourself.
//
// Run it as the account that owns the files.

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Domain\Updates;
use BetterCal\Infra\Db;
use BetterCal\Infra\BackupCipher;
use BetterCal\Infra\DbBackup;
use BetterCal\Infra\Migrator;
use BetterCal\Infra\PrivateFiles;
use BetterCal\Infra\PrivateFilesCheck;
use BetterCal\Infra\Setup;
use BetterCal\Infra\Updater;
use BetterCal\Support\Cli;
use BetterCal\Support\Maintenance;
use BetterCal\Support\ReleaseSignature;
use BetterCal\Support\SettingsFile;

$opt = getopt('', ['check', 'yes', 'finish', 'rollback', 'help']);
if (isset($opt['help'])) {
    Cli::say('Usage: php server/bin/update.php [--check | --yes | --rollback]');
    exit(0);
}

$cfg = config();
$appRoot = $cfg['app_root'];
$updater = new Updater($appRoot);
// Everything used after the code is swapped must already be loaded: the
// autoloader looks for classes under the old path, which by then holds the
// new version.
foreach ([DbBackup::class, BackupCipher::class, \BetterCal\Infra\PrivatePath::class, Migrator::class, Maintenance::class, Setup::class, ReleaseSignature::class, SettingsFile::class, PrivateFiles::class] as $class) {
    class_exists($class);
}

try {
    if (isset($opt['finish'])) {
        exit(finish($cfg, $updater));
    }
    if (isset($opt['rollback'])) {
        exit(rollback($cfg, $updater, isset($opt['yes'])));
    }
    exit(update($cfg, $updater, isset($opt['check']), isset($opt['yes'])));
} catch (\Throwable $e) {
    Cli::say();
    Cli::say('Stopped: ' . $e->getMessage());
    try { $state = $updater->readState(); } catch (\Throwable) { $state = null; }
    if (is_array($state) && in_array($state['stage'] ?? '', ['backed-up', 'swapping', 'swapped', 'failed', 'done'], true)) {
        Cli::say('To put the previous version (and, if it changed, the database) back: php ' . $appRoot . '/server/bin/update.php --rollback');
    }
    exit(1);
}

function update(array $cfg, Updater $updater, bool $checkOnly, bool $yes): int
{
    if ((string) ($cfg['session_secret'] ?? '') === '') {
        Cli::say(DbBackup::missingSecretMessage());
        if (!$checkOnly) {
            return 1;
        }
    }
    $appRoot = $updater->appRoot;
    $current = (string) $cfg['version'];
    $state = $updater->readState();
    if (is_array($state) && !in_array($state['stage'] ?? '', ['done', 'rolled-back'], true)) {
        Cli::say("An update from {$state['from']} to {$state['to']} didn't finish (stage: {$state['stage']}).");
        if (in_array($state['stage'] ?? '', ['swapped', 'failed'], true)) {
            Cli::say('Finish it:  php ' . $appRoot . '/server/bin/update.php --finish');
        }
        Cli::say('Undo it:    php ' . $appRoot . '/server/bin/update.php --rollback');
        return 1;
    }

    $db = new Db($cfg['db']);
    if (!updateLock($db, $cfg)) {
        return 1;
    }
    Cli::say("This is Better-Cal $current. Checking for a newer release...");
    $release = (new Updates($db, $cfg))->newestRelease();
    $to = $release['version'];
    if (version_compare($to, $current, '<=')) {
        Cli::say("Up to date: $current is the newest release.");
        return 0;
    }
    Cli::say();
    Cli::say("Better-Cal $to is available" . ($release['priority'] === 'security' ? ' (a security update)' : '') . '.');

    // The release must be signed by a key this copy trusts (config/release-keys.php):
    // GitHub's checks alone would let anyone who took over the GitHub account
    // publish code every install accepts. No keys means nothing verifies.
    if ($release['signature'] === null) {
        Cli::say("  Not signed: $to has no " . ReleaseSignature::ASSET . '. Not installing it; please report this.');
        return 1;
    }
    $signed = ReleaseSignature::verify((new Updates($db, $cfg))->fetchSignature($release['signature']), ReleaseSignature::trustedKeys());
    if ($signed['version'] !== $to || $signed['manifest'] !== $release['manifestSha256']
        || ($release['zip'] !== null && $signed['zip'] !== $release['zip']['sha256'])) {
        Cli::say("  The signature is for a different release than $to. Not installing it; please report this.");
        return 1;
    }
    Cli::say("  Signed by {$signed['key']}.");
    // The notes aren't signed (they can be edited after publishing), so
    // they're shown only for a release whose signature checked out.
    Cli::say('  What changed: ' . $release['releaseUrl']);
    $operators = Updater::operatorsLine($release['notes']);
    if ($operators !== null) {
        Cli::say('  Release notes (unsigned): ' . $operators);
    }
    if (!in_array($to, nextVersions($current), true)) {
        Cli::say('  If this skips versions, that is fine; read the Operators lines of the ones in between too:');
        Cli::say('  https://github.com/Oshyan/better-cal/releases');
    }
    if ($checkOnly) {
        return 0;
    }
    if ($updater->looksServed()) {
        Cli::say();
        Cli::say("Note: $appRoot looks like it may be inside a web root. Backups and the previous version go beside it,");
        Cli::say('and Better-Cal adds .htaccess files to keep Apache from serving them, but the app belongs outside the');
        Cli::say('web root, with only server/public served (docs/install.md).');
    }

    // Before anything changes: can this install be updated this way?
    $git = $updater->isGit();
    if ($git) {
        if (!Updater::canRun()) {
            return manual('This PHP may not start other programs (proc_open is disabled), so it can\'t run git.', $to);
        }
        [$code, $out] = Updater::capture(['git', 'status', '--porcelain', '--untracked-files=no'], $appRoot);
        if ($code !== 0) {
            return manual('git is not available here: ' . trim($out), $to);
        }
        if (trim($out) !== '') {
            Cli::say('This checkout has local changes to tracked files; commit or set them aside first:');
            Cli::say(rtrim($out));
            return 1;
        }
        [$code] = Updater::capture(['composer', '--version'], $appRoot);
        if ($code !== 0) {
            return manual('Composer is not available here, and a git checkout needs it for the PHP libraries.', $to);
        }
    } else {
        if ($release['zip'] === null) {
            return manual("The $to release has no zip to update from.", $to);
        }
        if (!is_writable($updater->parent())) {
            Cli::say($updater->parent() . " isn't writable by this account. The update unpacks the new version beside");
            Cli::say('the current one and swaps them, so it needs to be able to create folders there.');
            return 1;
        }
        $free = @disk_free_space($updater->parent());
        if ($free !== false && $free < $release['zip']['size'] * 6 + 100 * 1024 * 1024) {
            Cli::say('There is not enough free disk space for the new version and a backup (' . round($free / 1048576) . ' MB free).');
            return 1;
        }
    }
    $updater->ensureBackupDir();

    Cli::say();
    if (!$yes) {
        if (!Cli::interactive()) {
            Cli::say('Run again with --yes to update without a terminal to ask on.');
            return 1;
        }
        if (!Cli::confirm("Update $current to $to now? The site shows \"updating\" for a minute or two", false)) {
            Cli::say('Nothing changed.');
            return 0;
        }
    }

    // 1. Maintenance mode, then wait out a running worker job (its own lock),
    // then back up the database.
    Maintenance::on($appRoot, "updating $current to $to");
    if ((int) $db->scalar('SELECT GET_LOCK(?, 90)', [Updater::workerLockName((string) $cfg['db']['dsn'])]) !== 1) {
        Maintenance::off($appRoot);
        Cli::say('The worker has been busy for 90 seconds. Nothing changed; try again in a few minutes.');
        return 1;
    }
    $stamp = gmdate('Ymd-His');
    $dumper = DbBackup::forConfig($db, $cfg);
    $backup = $updater->backupDir() . "/db-$current-$stamp-" . bin2hex(random_bytes(16)) . $dumper->extension();
    Cli::say('Backing up the database...');
    $state = [
        'from' => $current, 'to' => $to, 'mode' => $git ? 'git' : 'zip', 'appRoot' => $appRoot,
        'backup' => $backup, 'previous' => null, 'startedAt' => gmdate('c'), 'stage' => 'backed-up',
    ];
    try {
        $dump = $dumper->dump($backup, "before updating $current to $to");
        // Until the record exists, nothing could finish or undo this update,
        // so a failure writing it leaves the site as it was.
        $updater->writeState($state);
    } catch (\Throwable $e) {
        Maintenance::off($appRoot);
        throw new \RuntimeException('The backup failed: ' . $e->getMessage() . '. Nothing changed: still ' . $current . '.', 0, $e);
    }
    @chmod($backup, 0600);
    Cli::say("  $backup (" . $dump['tables'] . ' tables, ' . number_format($dump['rows']) . ' rows, ' . round($dump['bytes'] / 1024) . ' KB)');

    // 2. The new code.
    try {
        if ($git) {
            Cli::say("Checking out v$to...");
            if (Updater::run(['git', 'fetch', '--tags', '--quiet'], $appRoot) !== 0) {
                throw new \RuntimeException('git fetch failed');
            }
            [, $commit] = Updater::capture(['git', 'rev-parse', "v$to^{commit}"], $appRoot);
            if ($signed !== null && trim($commit) !== $signed['commit']) {
                throw new \RuntimeException("The tag v$to points at a different commit than the signed release");
            }
            [, $json] = Updater::capture(['git', 'show', "v$to:server/composer.json"], $appRoot);
            requirePhp(Updater::phpRequirement($json), $to);
            $keysBefore = Updater::keysAt($appRoot);
            if (Updater::run(['git', '-c', 'advice.detachedHead=false', 'checkout', '--quiet', "v$to"], $appRoot) !== 0) {
                throw new \RuntimeException("git checkout v$to failed");
            }
            $state['stage'] = 'swapped';
            $updater->writeState($state);
            Maintenance::codeChanged($appRoot);
            sayKeyChanges($keysBefore, Updater::keysAt($appRoot), $to);
            Cli::say('Installing the PHP libraries...');
            if (Updater::run(['composer', 'install', '--no-dev', '--no-interaction', '--quiet'], $appRoot . '/server') !== 0) {
                throw new \RuntimeException('composer install failed');
            }
        } else {
            $zipPath = $updater->backupDir() . "/better-cal-$to.zip";
            $state['zip'] = $zipPath;
            Cli::say('Downloading better-cal-' . $to . '.zip (' . round($release['zip']['size'] / 1048576, 1) . ' MB)...');
            (new Updates($db, $cfg))->downloadZip($release['zip'], $zipPath);
            Cli::say("  SHA-256 matches GitHub's digest.");
            $tree = $updater->unpack($zipPath, $to);
            $state['tree'] = $tree;
            @unlink($zipPath);
            requirePhp(Updater::phpRequirement((string) @file_get_contents($tree . '/server/composer.json')), $to);
            sayKeyChanges(Updater::keysAt($appRoot), Updater::keysAt($tree), $to);
            $kept = $updater->carryOver($tree);
            Cli::say('  Carried over: ' . implode(', ', $kept['carried']) . '.');
            Maintenance::on($tree, "updating $current to $to");
            $previous = Updater::freePath($updater->parent() . '/' . $updater->name() . '-' . $current);
            $state['previous'] = $previous;
            $state['leftBehind'] = array_slice($kept['leftBehind'], 0, 50);
            $state['stage'] = 'swapping';
            $updater->writeState($state);
            if (!@rename($appRoot, $previous)) {
                throw new \RuntimeException("Could not move the current version aside to $previous");
            }
            if (!@rename($tree, $appRoot)) {
                @rename($previous, $appRoot);
                throw new \RuntimeException('Could not move the new version into place; the current one was put back');
            }
            @rmdir(dirname($tree));
            Maintenance::codeChanged($appRoot);
            Updater::denyWeb($previous);
            $state['stage'] = 'swapped';
            $updater->writeState($state);
            Cli::say("  The previous version is in $previous.");
            if ($kept['leftBehind'] !== []) {
                Cli::say('  Only in the previous version (move anything you still need):');
                foreach (array_slice($kept['leftBehind'], 0, 15) as $rel) {
                    Cli::say("    $rel");
                }
            }
        }
    } catch (\Throwable $e) {
        if (($state['stage'] ?? '') !== 'swapped') {
            discardStaging($state);
            Maintenance::off($appRoot);
            $state['stage'] = 'rolled-back';
            $updater->writeState($state);
            throw new \RuntimeException($e->getMessage() . '. Nothing changed: still ' . $current . '.', 0, $e);
        }
        $state['stage'] = 'failed';
        $updater->writeState($state);
        throw $e;
    }

    // 3. The new code takes it from here, and takes the update lock.
    $db->scalar('SELECT RELEASE_LOCK(?)', [Updater::updateLockName((string) $cfg['db']['dsn'])]);
    Cli::say('Finishing with the new version...');
    if (!Updater::canRun()) {
        Cli::say('This PHP may not start programs, so run this to finish:');
        Cli::say('  php ' . $appRoot . '/server/bin/update.php --finish');
        return 0;
    }
    return Updater::run([PHP_BINARY, $appRoot . '/server/bin/update.php', '--finish'], $appRoot);
}

/** Run by update(), as the new code: migrate, check, leave maintenance mode. */
function finish(array $cfg, Updater $updater): int
{
    $appRoot = $updater->appRoot;
    $state = $updater->readState();
    if (!is_array($state) || !in_array($state['stage'] ?? '', ['swapped', 'failed'], true)) {
        Cli::say('There is no update waiting to be finished.');
        return 1;
    }
    $db = new Db($cfg['db']);
    if (!updateLock($db, $cfg)) {
        return 1;
    }
    try {
        $ran = (new Migrator($db, $appRoot . '/server/migrations'))->run(static function (string $line): void {
            Cli::say('  ' . $line);
        });
        if ((new Migrator($db, $appRoot . '/server/migrations'))->pending() !== []) {
            throw new \RuntimeException('Some database changes are still pending');
        }
        if ((string) $cfg['version'] !== (string) $state['to']) {
            throw new \RuntimeException("The code in place is {$cfg['version']}, not {$state['to']}");
        }
    } catch (\Throwable $e) {
        $state['stage'] = 'failed';
        $state['error'] = $e->getMessage();
        $updater->writeState($state);
        Cli::say('The update stopped: ' . $e->getMessage());
        Cli::say('The site stays in maintenance mode. To put the previous version and the database back:');
        Cli::say('  php ' . $appRoot . '/server/bin/update.php --rollback');
        Cli::say('Please report the error: https://github.com/Oshyan/better-cal/issues');
        return 1;
    }
    Cli::say($ran === 0 ? '  No database changes in this release.' : "  Applied $ran database change(s).");
    // Private files in the current layout (#142): settings in .env.php, here
    // and in the previous copy, backups encrypted.
    foreach (PrivateFiles::secure($cfg) as $line) {
        Cli::say('  ' . $line);
    }
    $state = $updater->readState() ?? $state;
    if (is_file(SettingsFile::plain($appRoot))) {
        Cli::say('  Your settings are still in .env, which this account can\'t convert. Run as its owner:');
        Cli::say('    php ' . $appRoot . '/server/bin/settings.php --convert');
    }
    Maintenance::off($appRoot);
    $state['stage'] = 'done';
    $state['finishedAt'] = gmdate('c');
    $updater->writeState($state);
    Cli::say();
    Cli::say("Updated to Better-Cal {$state['to']}. The site is back.");
    Cli::say('Check ' . $cfg['base_url'] . '/api/v1/health, and Settings, About shows the new version.');
    Cli::say('Kept: the database backup, ' . $state['backup']
        . ($state['previous'] ? ', and the previous version, ' . $state['previous'] : '') . '.');
    Cli::say('If something is wrong, php server/bin/update.php --rollback puts both back.');
    $pruned = $updater->prune(2);
    if ($pruned !== []) {
        Cli::say('Removed older copies and backups beyond the newest two: ' . implode(', ', $pruned) . '.');
    }
    try {
        $r = PrivateFilesCheck::run($cfg);
        if ($r['status'] !== 'safe') {
            Cli::say();
            Cli::say($r['title'] . ' ' . $r['message']);
            foreach ($r['notes'] as $note) {
                Cli::say('  - ' . $note);
            }
        }
    } catch (\Throwable) {
        // The check is advice; the update is done either way.
    }
    return 0;
}

/** Put the previous version and the pre-update database back. */
function rollback(array $cfg, Updater $updater, bool $yes): int
{
    $appRoot = $updater->appRoot;
    $state = $updater->readState();
    if (!is_array($state) || !in_array($state['stage'] ?? '', ['backed-up', 'swapping', 'swapped', 'failed', 'done'], true)) {
        Cli::say('There is no update to roll back.');
        return 1;
    }
    $db = new Db($cfg['db']);
    if (!updateLock($db, $cfg)) {
        return 1;
    }
    $from = (string) $state['from'];
    $to = (string) $state['to'];
    $state['stage'] = Updater::settledStage($state);
    if ($state['stage'] === 'backed-up') {
        // Stopped before the code changed (a closed terminal, a killed
        // process): the database and the code are still the old ones, so
        // undoing is only tidying up.
        discardStaging($state);
        Maintenance::off($appRoot);
        $state['stage'] = 'rolled-back';
        $state['rolledBackAt'] = gmdate('c');
        $updater->writeState($state);
        Cli::say("The update to $to stopped before anything changed. Tidied up; still on Better-Cal $from.");
        return 0;
    }
    $floor = (new Updates($db, $cfg))->securityFloor();
    if ($floor !== null && version_compare($from, $floor, '<')) {
        Cli::say("Warning: $from is below $floor, the oldest version with every security fix. Update again once the problem is fixed.");
    }
    Cli::say("This puts Better-Cal $from back, with the database as it was before the update ({$state['startedAt']}).");
    Cli::say('Anything entered since then is lost.');
    if (!$yes && (!Cli::interactive() || !Cli::confirm('Roll back now?', false))) {
        Cli::say('Nothing changed.');
        return 0;
    }
    if (!is_file((string) $state['backup'])) {
        Cli::say("The backup {$state['backup']} is missing; not rolling back.");
        return 1;
    }
    if ($state['mode'] === 'zip' && (!is_string($state['previous']) || !is_dir($state['previous']))) {
        Cli::say("The previous version's folder is missing; not rolling back.");
        return 1;
    }
    Maintenance::on($appRoot, "rolling back $to to $from");
    if (dirname((string) $state['backup']) !== $updater->backupDir()) {
        throw new \RuntimeException('The recorded backup is outside this install\'s backup directory.');
    }
    if ((int) $db->scalar('SELECT GET_LOCK(?, 0)', [Updater::workerLockName((string) $cfg['db']['dsn'])]) !== 1) {
        Cli::say('The worker is finishing a job. Nothing has been restored; the site stays closed. Run --rollback again after the job finishes.');
        return 1;
    }
    Cli::say('Restoring the database...');
    $restored = DbBackup::forConfig($db, $cfg)->restore((string) $state['backup']);
    Cli::say('  ' . $restored['tables'] . ' tables restored' . ($restored['dropped'] > 0 ? ', ' . $restored['dropped'] . ' added by the update removed' : '') . '.');
    if ($state['mode'] === 'git') {
        if (Updater::run(['git', '-c', 'advice.detachedHead=false', 'checkout', '--quiet', "v$from"], $appRoot) !== 0
            || Updater::run(['composer', 'install', '--no-dev', '--no-interaction', '--quiet'], $appRoot . '/server') !== 0) {
            throw new \RuntimeException("Going back to v$from with git failed; check out v$from and run composer install by hand");
        }
        plainSettingsFor($appRoot, $from);
        Maintenance::codeChanged($appRoot);
        Maintenance::off($appRoot);
    } else {
        plainSettingsFor((string) $state['previous'], $from);
        $failed = Updater::freePath($updater->parent() . '/' . $updater->name() . '-' . $to . '-rolled-back');
        if (!@rename($appRoot, $failed)) {
            throw new \RuntimeException("Moving $to aside failed; move {$state['previous']} to $appRoot by hand");
        }
        if (!@rename((string) $state['previous'], $appRoot)) {
            @rename($failed, $appRoot); // never leave no app at all
            throw new \RuntimeException("Putting {$state['previous']} back failed; move it to $appRoot by hand");
        }
        Maintenance::codeChanged($appRoot);
        Maintenance::off($appRoot);
        Cli::say("  $to is set aside in $failed.");
    }
    $state['stage'] = 'rolled-back';
    $state['rolledBackAt'] = gmdate('c');
    $updater->writeState($state);
    Cli::say("Back on Better-Cal $from.");
    return 0;
}

/**
 * Versions before 0.9.83 read only .env. Going back to one, write .env from
 * .env.php so it starts, and say what that means: its settings file is no
 * longer guarded (#142).
 */
function plainSettingsFor(string $root, string $version): void
{
    $config = (string) @file_get_contents($root . '/server/config/config.php');
    $guarded = SettingsFile::guarded($root);
    if (str_contains($config, '.env.php') || is_file(SettingsFile::plain($root)) || !is_file($guarded)) {
        return;
    }
    $body = SettingsFile::checkGuard($guarded, (string) file_get_contents($guarded));
    $mode = fileperms($guarded) & 0777;
    $old = umask(0077);
    try {
        if (@file_put_contents(SettingsFile::plain($root), $body) === false) {
            throw new \RuntimeException("Better-Cal $version reads its settings from .env, and writing $root/.env failed");
        }
    } finally {
        umask($old);
    }
    @chmod(SettingsFile::plain($root), $mode);
    // That version never reads .env.php; left behind, it would win over a
    // .env edited meanwhile once a later version is installed again.
    @unlink($guarded);
    Cli::say("  Better-Cal $version reads its settings from .env, so .env was written from .env.php. That version");
    Cli::say('  doesn\'t protect its settings file the way 0.9.83 and later do; update again when you can.');
}

/** The update lock (Infra\Updater::updateLockName), held until this process ends. */
function updateLock(Db $db, array $cfg): bool
{
    if ((int) $db->scalar('SELECT GET_LOCK(?, 0)', [Updater::updateLockName((string) $cfg['db']['dsn'])]) === 1) {
        return true;
    }
    Cli::say('Another update is running (in another terminal, or the database update page). Nothing changed.');
    return false;
}

/** Remove what an update unpacked or downloaded before the swap. */
function discardStaging(array $state): void
{
    if (is_string($state['tree'] ?? null)) {
        Updater::removeTree(dirname($state['tree']));
    }
    if (is_string($state['zip'] ?? null)) {
        @unlink($state['zip']);
    }
}

/** Say what a release changes about who may sign the next one: never quietly. */
function sayKeyChanges(array $before, array $after, string $to): void
{
    $changes = Updater::keyChanges($before, $after);
    if ($changes === []) {
        return;
    }
    Cli::say("  Note: $to " . implode('; ', $changes) . '. From then on, releases must be signed by: ' . implode(', ', array_keys($after)) . '.');
    Cli::say('  If the release notes don\'t explain this change, report it: https://github.com/Oshyan/better-cal/issues');
}

function requirePhp(?string $min, string $to): void
{
    if ($min !== null && version_compare(PHP_VERSION, $min, '<')) {
        throw new \RuntimeException("Better-Cal $to needs PHP $min or newer, and this is " . PHP_VERSION . ". Switch PHP first");
    }
}

/** The versions that can directly follow this one (the next patch, minor or major). @return list<string> */
function nextVersions(string $version): array
{
    [$major, $minor, $patch] = array_map('intval', explode('.', $version) + [0, 0, 0]);
    return ["$major.$minor." . ($patch + 1), "$major." . ($minor + 1) . '.0', ($major + 1) . '.0.0'];
}

function manual(string $why, string $to): int
{
    Cli::say($why);
    Cli::say("Update by hand instead (docs/install.md, Updating), to v$to.");
    return 1;
}
