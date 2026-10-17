<?php

declare(strict_types=1);

namespace BetterCal\Http;

use BetterCal\Infra\Db;
use BetterCal\Infra\DbBackup;
use BetterCal\Infra\Migrator;
use BetterCal\Infra\PrivateFiles;
use BetterCal\Infra\PrivateFilesCheck;
use BetterCal\Infra\Setup;
use BetterCal\Infra\Updater;
use BetterCal\Support\Maintenance;

/**
 * The two pages that run before the app can: setup in the browser, for hosts
 * without a command line (#138), and the database update after new files
 * were uploaded by hand. Both are plain server-rendered forms with no app
 * code behind them, and both first ask for the code AccessCode writes into
 * the app folder, so only someone who controls the files gets past the first
 * screen.
 *
 * Setup exists only while there is no .env (and no BETTERCAL_DB_DSN in the
 * environment). Once .env is written it is gone for good: index.php never
 * reaches it again, whatever happens to the database. It refuses a database
 * that already holds a Better-Cal account, since that means a lost .env
 * (restore it) rather than a new install.
 */
final class InstallPages
{
    // ---- Setup -------------------------------------------------------------

    public static function needsSetup(string $appRoot): bool
    {
        $dsn = getenv('BETTERCAL_DB_DSN');
        return \BetterCal\Support\SettingsFile::path($appRoot) === null && ($dsn === false || $dsn === '');
    }

    public static function setup(string $appRoot): never
    {
        $code = new AccessCode($appRoot, 'setup', static fn(): bool => self::needsSetup($appRoot));
        if (!$code->writable()) {
            self::page('Set up Better-Cal', '<p>PHP can\'t write to the Better-Cal folder, so setup has nowhere to save its settings.</p>'
                . '<p>Make the folder writable by the account PHP runs as (on most shared hosting it already is), or install from a command line instead: <code>php server/bin/install.php</code>. <a href="https://github.com/Oshyan/better-cal/blob/main/docs/install.md">The install guide</a> covers both.</p>', 503);
        }
        $token = self::gate($code, 'Set up Better-Cal', 'To show this is your server, open <strong>' . AccessCode::FILE
            . '</strong> in the Better-Cal folder you uploaded (with your host\'s file manager or FTP) and enter the code in it.');

        $missing = array_values(array_filter(Setup::requirements($appRoot), static fn(array $r): bool => !$r['ok'] && $r['required']));
        if ($missing !== []) {
            $items = implode('', array_map(static fn(array $r): string => '<li><strong>' . self::e($r['name']) . '</strong><br>' . self::e($r['fix']) . '</li>', $missing));
            self::page('Set up Better-Cal', "<p>This server is missing something Better-Cal needs:</p><ul>$items</ul><p>Fix it (usually in your host's PHP settings), then reload this page.</p>");
        }

        $errors = [];
        $v = [
            'url' => self::requestBaseUrl(), 'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'email' => '',
        ];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['step'] ?? '') === 'install') {
            self::checkForm($token);
            foreach ($v as $k => $_) {
                $v[$k] = trim((string) ($_POST[$k] ?? ''));
            }
            $dbPass = (string) ($_POST['db_pass'] ?? '');
            $pass = (string) ($_POST['password'] ?? '');
            $url = rtrim($v['url'], '/');
            $parts = parse_url($url);
            $local = is_array($parts) && ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true);
            if (!is_array($parts) || (($parts['scheme'] ?? '') !== 'https' && !$local) || empty($parts['host']) || !empty($parts['path']) || !empty($parts['query'])) {
                $errors[] = 'The address needs to be https://your.domain, with nothing after the domain.';
            }
            if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'That email address is not valid.';
            }
            if (strlen($pass) < 8) {
                $errors[] = 'Choose a password of 8 characters or more.';
            } elseif ($pass !== (string) ($_POST['password2'] ?? '')) {
                $errors[] = 'The two passwords differ.';
            }
            $dsn = null;
            try {
                $dsn = Setup::dsn($v['db_host'], (int) $v['db_port'], $v['db_name']);
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
            if ($errors === [] && $dsn !== null) {
                $problem = Setup::checkDatabase($dsn, $v['db_user'], $dbPass);
                if ($problem !== null) {
                    $errors[] = 'The database: ' . $problem;
                }
            }
            if ($errors === [] && $dsn !== null) {
                $db = new Db(['dsn' => $dsn, 'user' => $v['db_user'], 'pass' => $dbPass]);
                $hasUsers = false;
                try {
                    $hasUsers = (int) $db->scalar('SELECT COUNT(*) FROM users') > 0;
                } catch (\Throwable) {
                    // No users table yet: an empty database, as it should be.
                }
                if ($hasUsers) {
                    $errors[] = 'That database already holds a Better-Cal account. If this install lost its .env, restore it from a backup rather than setting up again (a new one could not read what the old one saved).';
                }
            }
            if ($errors === [] && $dsn !== null) {
                self::install($appRoot, $code, $token, $url, $dsn, $v['db_user'], $dbPass, $v['email'], $pass);
            }
        }

        $err = $errors === [] ? '' : '<div class="err">' . implode('<br>', array_map([self::class, 'e'], $errors)) . '</div>';
        $f = static fn(string $k): string => self::e($v[$k]);
        self::page('Set up Better-Cal', $err . '<form method="post" autocomplete="off">'
            . '<input type="hidden" name="step" value="install"><input type="hidden" name="form" value="' . self::e(AccessCode::formToken($token)) . '">'
            . '<h2>Address</h2><label>The address people will open Better-Cal at<input name="url" value="' . $f('url') . '" required></label>'
            . '<p class="hint">A domain or subdomain of its own, like https://cal.example.com, not a folder of another site (<a href="https://github.com/Oshyan/better-cal/blob/main/docs/install.md#choosing-where-to-put-it">why</a>).</p>'
            . '<h2>Database</h2><p class="hint">An empty MySQL or MariaDB database and a user for it, from your host\'s control panel.</p>'
            . '<div class="row"><label>Host<input name="db_host" value="' . $f('db_host') . '" required></label><label class="narrow">Port<input name="db_port" value="' . $f('db_port') . '" inputmode="numeric" required></label></div>'
            . '<label>Database name<input name="db_name" value="' . $f('db_name') . '" required></label>'
            . '<label>User<input name="db_user" value="' . $f('db_user') . '" required></label>'
            . '<label>Password<input name="db_pass" type="password"></label>'
            . '<h2>Your account</h2><label>Email (you sign in with it)<input name="email" type="email" value="' . $f('email') . '" required></label>'
            . '<label>Password (8 characters or more)<input name="password" type="password" minlength="8" required></label>'
            . '<label>The same password again<input name="password2" type="password" minlength="8" required></label>'
            . '<button type="submit">Set up Better-Cal</button></form>');
    }

    private static function install(string $appRoot, AccessCode $code, string $token, string $url, string $dsn, string $dbUser, string $dbPass, string $email, string $pass): never
    {
        @set_time_limit(300);
        try {
            $push = Setup::vapidKeys();
        } catch (\Throwable) {
            $push = null;
        }
        $values = ['BETTERCAL_BASE_URL' => $url, 'BETTERCAL_DB_DSN' => $dsn, 'BETTERCAL_DB_USER' => $dbUser,
            'BETTERCAL_DB_PASS' => $dbPass, 'BETTERCAL_SESSION_SECRET' => Setup::secret()]
            + ($push !== null ? ['BETTERCAL_VAPID_PUBLIC' => $push['public'], 'BETTERCAL_VAPID_PRIVATE' => $push['private'], 'BETTERCAL_VAPID_SUBJECT' => 'mailto:' . $email] : []);
        // Some migrations read the settings (045 seals feed credentials with
        // the session secret), and .env doesn't exist yet: put the new values
        // in this request's environment, where config() looks first.
        foreach ($values as $k => $val) {
            putenv("$k=$val");
            $_ENV[$k] = $val;
        }
        $db = new Db(['dsn' => $dsn, 'user' => $dbUser, 'pass' => $dbPass]);
        $envPath = \BetterCal\Support\SettingsFile::guarded($appRoot);
        $wroteEnv = false;
        try {
            // Tables, then .env, then the account. Until .env exists this page
            // is the way in, and the account is what marks a database as
            // installed, so a failure at any step can simply be retried: a
            // .env written here is taken back if the account then fails.
            (new Migrator($db, $appRoot . '/server/migrations'))->run(static function (string $line): void {});
            $example = @file_get_contents($appRoot . '/.env.example');
            Setup::writeEnv($envPath, Setup::envText($example === false ? '' : $example, $values));
            $wroteEnv = true;
            Setup::createOwner($db, $email, $pass);
        } catch (\Throwable $e) {
            if ($wroteEnv) {
                @unlink($envPath);
            }
            self::page('Set up Better-Cal', '<div class="err">Setup stopped: ' . self::e($e->getMessage()) . '</div><p>Nothing that would get in the way of trying again was saved. <a href="">Back to setup</a></p>', 500);
        }
        self::finishCode($code, $token);
        self::clearCookie();
        $php = Setup::workerPhp($appRoot);
        $command = Setup::cronCommand($appRoot, $php['php']);
        self::page('Better-Cal is set up', self::privateFilesNotice(['app_root' => $appRoot, 'base_url' => $url])
            . '<p>Your settings are saved, the tables are made, and your account is ready.</p>'
            . '<h2>One thing left: the worker</h2><p>It sends reminders, refreshes subscribed calendars and tidies up, once a minute. In your host\'s control panel, add a cron job (often under "Cron jobs" or "Scheduled tasks"):</p>'
            . '<ul><li><strong>When:</strong> every minute. Where the panel has separate fields, set minute, hour, day, month and weekday all to <code>*</code> (often called "every" or "once per minute").</li>'
            . '<li><strong>Command:</strong></li></ul><pre>' . self::e($command) . '</pre>'
            . '<p class="hint">If the panel offers a "PHP" type that fills in the start of the path for you, choose its "custom" or "command" type instead and paste the whole command. '
            . ($php['follows'] ? 'It runs the server\'s own PHP, so it keeps working when the server\'s PHP is upgraded.'
                : 'It names this site\'s PHP version directly, because the server\'s plain <code>php</code> is older or couldn\'t be checked. If you change PHP versions later, Settings, System shows the new command, and tells you if the worker stops.')
            . ' With a terminal, the crontab line is <code>' . self::e(Setup::cronLine($appRoot, $php['php'])) . '</code></p>'
            . '<p><a class="btn" href="/">Open Better-Cal and sign in</a></p>');
    }

    // ---- Database update -------------------------------------------------

    /**
     * Whether new files have database changes waiting. Cheap on every request:
     * the list of migration files is compared with the last list known to be
     * applied, and the database is only asked when they differ.
     */
    public static function needsDatabaseUpdate(array $cfg): bool
    {
        $dir = $cfg['app_root'] . '/server/migrations';
        $sig = self::schemaSignature($dir);
        $marker = $cfg['app_root'] . '/.runtime-cache/schema-current';
        if (@file_get_contents($marker) === $sig) {
            return false;
        }
        try {
            $migrator = new Migrator(new Db($cfg['db']), $dir);
            $pending = $migrator->pending();
            // Or the database is ahead of these files (a newer version's
            // update, then older files put back by hand): this code must not
            // run on a schema it wasn't written for.
            $unknown = $migrator->unknown();
        } catch (\Throwable) {
            return false; // the database is unreachable: the app reports that itself
        }
        if ($pending === [] && $unknown === []) {
            if (is_dir(dirname($marker)) || @mkdir(dirname($marker), 0700)) {
                @file_put_contents($marker, $sig);
            }
            return false;
        }
        return true;
    }

    private static function schemaSignature(string $dir): string
    {
        $files = array_map('basename', array_merge(glob($dir . '/*.sql') ?: [], glob($dir . '/*.php') ?: []));
        sort($files, SORT_STRING);
        return hash('sha256', implode("\n", $files));
    }

    /**
     * While changes wait: the API and CalDAV answer 503 with a code the app
     * recognises, and pages show the update screen. Static files are left to
     * the normal routes. Returns only for those.
     */
    public static function databaseUpdate(array $cfg, string $path): void
    {
        self::databaseUpdatePage($cfg, $path);
    }

    /** What this page writes into the maintenance file while it works. */
    private const PAGE_WORK = ['database update', 'restoring the database'];

    /**
     * Maintenance left on by this page's own work after the request doing it
     * was cut off (a host's time limit, a lost connection): nothing holds the
     * update lock any more, so nothing is working. 'continue' when the update
     * had in fact finished (maintenance is turned off), 'page' when this page
     * should take it from here, null otherwise: really at work, or
     * bin/update.php's own maintenance, which --finish or --rollback ends.
     */
    public static function stoppedDatabaseWork(array $cfg): ?string
    {
        $info = Maintenance::info($cfg['app_root']);
        if (!in_array($info['reason'] ?? null, self::PAGE_WORK, true)) {
            return null;
        }
        // Anything uncertain (the database unreachable, an error) keeps the
        // site closed: only a clear "nothing is working on it, and nothing is
        // left to apply" opens it.
        try {
            $db = new Db($cfg['db']);
            if ((int) $db->scalar('SELECT IS_FREE_LOCK(?)', [Updater::updateLockName((string) $cfg['db']['dsn'])]) !== 1) {
                return null;
            }
            $migrator = new Migrator($db, $cfg['app_root'] . '/server/migrations');
            $settled = $migrator->pending() === [] && $migrator->unknown() === [];
        } catch (\Throwable) {
            return null;
        }
        if ($info['reason'] === 'database update' && $settled) {
            try {
                Maintenance::off($cfg['app_root']);
            } catch (\Throwable) {
                return null;
            }
            return 'continue';
        }
        return 'page';
    }

    /** Hold the update lock for the rest of this request, or say who has it. */
    private static function lockUpdate(Db $db, array $cfg): void
    {
        if ((int) $db->scalar('SELECT GET_LOCK(?, 0)', [Updater::updateLockName((string) $cfg['db']['dsn'])]) !== 1) {
            self::page('Database update needed', '<div class="note">An update is already running (in another tab, or from a command line). Wait a minute, then <a href="/update-database">reload this page</a>.</div>', 409);
        }
    }

    /**
     * Restore forms for the newest database backups an update made (this
     * page, or bin/update.php), newest first, by when they were made.
     */
    private static function restoreForms(Updater $updater, string $token, int $max = 3): string
    {
        $mine = array_filter(glob($updater->backupDir() . '/db-*') ?: [], 'is_file');
        usort($mine, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $out = '';
        foreach (array_slice($mine, 0, $max) as $f) {
            $out .= '<form method="post"><input type="hidden" name="step" value="restore"><input type="hidden" name="backup" value="' . self::e(basename($f)) . '">'
                . '<input type="hidden" name="form" value="' . self::e(AccessCode::formToken($token)) . '">'
                . '<button type="submit">Restore the backup from ' . self::e(gmdate('Y-m-d H:i:s', (int) filemtime($f))) . ' UTC</button></form>';
        }
        return $out;
    }

    private static function databaseUpdatePage(array $cfg, string $path): void
    {
        if (str_starts_with($path, '/api/') || str_starts_with($path, '/dav')) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['error' => ['code' => 'database_update_required', 'message' => 'Better-Cal was updated and its database needs updating: open /update-database.']]), "\n";
            exit;
        }
        if (str_starts_with($path, '/assets/') || $path === '/sw.js' || str_starts_with($path, '/feed/')) {
            return;
        }
        $appRoot = $cfg['app_root'];
        $schema = self::schemaSignature($appRoot . '/server/migrations');
        $code = new AccessCode($appRoot, 'update', static fn(): bool => @file_get_contents($appRoot . '/.runtime-cache/schema-current') !== $schema);
        if (!$code->writable()) {
            self::page('Database update needed', '<p>Better-Cal\'s files were updated and its database needs updating too, but PHP can\'t write to the Better-Cal folder to do it from here.</p><p>From a command line: <code>php server/bin/migrate.php</code>.</p>', 503);
        }
        $token = self::gate($code, 'Database update needed', 'Better-Cal\'s files were updated, and its database needs a matching update before it can open. To show this is your server, open <strong>'
            . AccessCode::FILE . '</strong> in the Better-Cal folder (with your host\'s file manager or FTP) and enter the code in it.');
        $updater = new Updater($appRoot);
        try {
            $updater->backupDir();
        } catch (\Throwable $e) {
            self::page('Private backup storage needs attention', '<div class="err">' . self::e($e->getMessage()) . '</div>'
                . '<p>No backup or restore was started. Use a real backup folder owned by the account running Better-Cal, without linked files. Your host can help correct its ownership. Then reload this page.</p>', 503);
        }
        $db = new Db($cfg['db']);
        $migrator = new Migrator($db, $appRoot . '/server/migrations');
        $pending = $migrator->pending();
        $unknown = $migrator->unknown();
        $version = (string) $cfg['version'];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['step'] ?? '') === 'restore') {
            self::checkForm($token);
            self::lockUpdate($db, $cfg);
            self::restoreBackup($cfg, $db, $updater, (string) ($_POST['backup'] ?? ''), $token);
        }
        // A partial restore is not an update candidate. Even a stale form
        // posting step=update must not migrate it and reopen the site.
        $stopped = Maintenance::active($appRoot) ? Maintenance::info($appRoot) : null;
        if (($stopped['reason'] ?? null) === 'restoring the database') {
            $restores = self::restoreForms($updater, $token);
            self::page('Database restore stopped', '<div class="err">A database restore stopped before it finished. The site stays closed until it is done: run the restore again.</div>'
                . ($restores !== '' ? $restores : '<p>The backup is no longer there. Restore a complete backup through your host before reopening the site.</p>'), 503);
        }
        if ((string) ($cfg['session_secret'] ?? '') === '') {
            self::page('Database update needed', '<div class="err">' . self::e(DbBackup::missingSecretMessage()) . '</div>'
                . '<p>In your file manager, add this line to <code>.env.php</code>, or replace its empty <code>BETTERCAL_SESSION_SECRET=</code> line. Keep the first guard line unchanged, then reload this page.</p>'
                . '<p><code>' . self::e(DbBackup::newSecretLine()) . '</code></p>'
                . '<p>If an original secret was lost, recover it instead to keep older encrypted backups and service credentials readable. Keep this value private.</p>'
                . self::restoreForms($updater, $token), 503);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['step'] ?? '') === 'update' && $unknown === []) {
            self::checkForm($token);
            self::lockUpdate($db, $cfg);
            @set_time_limit(300);
            try {
                $updater->ensureBackupDir();
                $dumper = DbBackup::forConfig($db, $cfg);
                $backup = $updater->backupDir() . '/db-before-' . $cfg['version'] . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(16)) . $dumper->extension();
                $dumper->dump($backup, 'before the database update for ' . $cfg['version']);
                @chmod($backup, 0600);
            } catch (\Throwable $e) {
                self::page('Database update needed', '<div class="err">The backup failed, so nothing was changed: ' . self::e($e->getMessage()) . '</div><p><a href="/update-database">Try again</a></p>', 500);
            }
            Maintenance::on($appRoot, 'database update');
            // Wait out a worker job already running (it checks for maintenance
            // before each one), as bin/update.php does.
            if ((int) $db->scalar('SELECT GET_LOCK(?, 60)', [Updater::workerLockName((string) $cfg['db']['dsn'])]) !== 1) {
                Maintenance::off($appRoot);
                self::page('Database update needed', '<div class="note">The worker has been busy for a minute, so nothing was changed. <a href="/update-database">Try again</a> in a few minutes.</div>', 503);
            }
            try {
                $ran = $migrator->run(static function (string $line): void {});
            } catch (\Throwable $e) {
                Maintenance::off($appRoot);
                self::page('Database update needed', '<div class="err">The update stopped: ' . self::e($e->getMessage()) . '</div>'
                    . '<p>A backup from just before is in <code>' . self::e($backup) . '</code>. To go back: restore it here, then put the previous version\'s files back (<a href="https://github.com/Oshyan/better-cal/blob/main/docs/install.md#updating">Updating</a> shows how). Please also <a href="https://github.com/Oshyan/better-cal/issues">report the error</a>.</p>'
                    . '<form method="post"><input type="hidden" name="step" value="restore"><input type="hidden" name="backup" value="' . self::e(basename($backup)) . '"><input type="hidden" name="form" value="' . self::e(AccessCode::formToken($token)) . '">'
                    . '<button type="submit">Restore the database as it was</button></form>', 500);
            }
            Maintenance::off($appRoot);
            // Settings still in .env, or older plain backups: the current,
            // private layout (#142). After the site is back: encrypting a pile
            // of old backups can take a while, and the worker finishes it if
            // this request is cut short.
            try {
                PrivateFiles::secure($cfg);
            } catch (\Throwable $e) {
                error_log('Better-Cal: converting private files after the database update: ' . $e->getMessage());
            }
            self::needsDatabaseUpdate($cfg);
            self::finishCode($code, $token);
            self::clearCookie();
            self::page('Database updated', self::privateFilesNotice($cfg) . '<p>Better-Cal ' . self::e((string) $cfg['version']) . ' is ready: ' . $ran . ' database change' . ($ran === 1 ? '' : 's') . ' applied.</p>'
                . '<p class="hint">A backup from just before is in <code>' . self::e($backup) . '</code>; delete it once you\'re happy.</p>'
                . '<p><a class="btn" href="/">Open Better-Cal</a></p>');
        }
        $stopped = Maintenance::active($appRoot) ? Maintenance::info($appRoot) : null;
        $restores = self::restoreForms($updater, $token);
        if ($unknown !== []) {
            $u = count($unknown);
            self::page('These files are older than the database', '<p>The database was updated by a newer version of Better-Cal: it has ' . $u . ' change' . ($u === 1 ? '' : 's')
                . ' that these files (Better-Cal ' . self::e($version) . ') don\'t know, so they can\'t safely open it.</p>'
                . '<p>Put the newer version\'s files back, or, to go back to ' . self::e($version) . ', restore the backup made before it was updated. Anything entered since that backup is lost.</p>'
                . ($restores !== '' ? $restores : '<p class="hint">No backup from before that update was found beside the Better-Cal folder; use your host\'s backups.</p>'), 503);
        }
        $n = count($pending);
        $note = ($stopped['reason'] ?? null) === 'database update'
            ? '<div class="err">A database update started here at ' . self::e((string) ($stopped['since'] ?? '?')) . ' stopped before it finished, often because the host ends long requests. '
                . 'Run it again (it carries on from where it stopped), or restore the backup from just before it and put the previous version\'s files back.</div>' . $restores
            : '';
        self::page('Database update needed', $note . '<p>Better-Cal ' . self::e($version) . ' has ' . $n . ' database change' . ($n === 1 ? '' : 's') . ' to apply.</p>'
            . '<p>This backs up the database first (beside the Better-Cal folder), shows "updating" to anyone else for the few seconds it takes, then applies them.</p>'
            . '<form method="post"><input type="hidden" name="step" value="update"><input type="hidden" name="form" value="' . self::e(AccessCode::formToken($token)) . '">'
            . '<button type="submit">Back up and update the database</button></form>', 503);
    }

    /**
     * Put back the backup this page made before a database update that
     * failed. Only a backup of that kind, by its file name in the backup
     * folder; restore() checks the whole file before it changes anything.
     */
    private static function restoreBackup(array $cfg, Db $db, Updater $updater, string $name, string $token): never
    {
        $path = $updater->backupDir() . '/' . $name;
        if (preg_match('/^db-(before-)?[0-9][0-9A-Za-z.\-]*\.(bcbackup|sql\.gz)$/D', $name) !== 1 || !is_file($path)) {
            self::page('Database update needed', '<div class="err">That backup isn\'t there any more.</div><p><a href="/update-database">Back</a></p>', 404);
        }
        @set_time_limit(300);
        Maintenance::on($cfg['app_root'], 'restoring the database');
        if ((int) $db->scalar('SELECT GET_LOCK(?, 0)', [Updater::workerLockName((string) $cfg['db']['dsn'])]) !== 1) {
            self::page('Database restore waiting', '<div class="note">The worker is finishing a job. Nothing has been restored. The site stays closed so new jobs cannot start.</div>'
                . self::restoreAgainForm($name, $token), 409);
        }
        try {
            $r = DbBackup::forConfig($db, $cfg)->restore($path);
        } catch (\Throwable $e) {
            self::page('Database restore stopped', '<div class="err">Restoring stopped: ' . self::e($e->getMessage()) . '</div>'
                . '<p>The site stays closed because the restore did not finish. Run the restore again, or choose another backup.</p>'
                . self::restoreAgainForm($name, $token) . self::restoreForms($updater, $token), 500);
        }
        Maintenance::off($cfg['app_root']);
        @unlink($cfg['app_root'] . '/.runtime-cache/schema-current');
        self::page('Database restored', '<p>The database is back as it was when that backup was made (' . $r['tables'] . ' tables).</p>'
            . '<p>If these files are the version from before the update, <a href="/">open Better-Cal</a>. If they are the newer version, put the previous version\'s files back first, as <a href="https://github.com/Oshyan/better-cal/blob/main/docs/install.md#updating">Updating</a> shows; until then this page keeps asking to update the database.</p>');
    }

    // ---- Shared ------------------------------------------------------------

    private static function restoreAgainForm(string $name, string $token): string
    {
        return '<form method="post"><input type="hidden" name="step" value="restore"><input type="hidden" name="backup" value="' . self::e($name)
            . '"><input type="hidden" name="form" value="' . self::e(AccessCode::formToken($token)) . '"><button type="submit">Run the restore again</button></form>';
    }

    /**
     * The private-files check, shown only when it isn't plainly Safe (Safe
     * stays on Settings, System). Never stops the page: a check that fails
     * says nothing here.
     */
    private static function privateFilesNotice(array $cfg): string
    {
        try {
            $r = PrivateFilesCheck::run($cfg);
        } catch (\Throwable) {
            return '';
        }
        if ($r['status'] === 'safe') {
            return '';
        }
        $notes = $r['notes'] === [] ? '' : '<ul>' . implode('', array_map(static fn(string $n): string => '<li>' . self::e($n) . '</li>', $r['notes'])) . '</ul>';
        $body = '<strong>' . self::e($r['title']) . '</strong> ' . self::e($r['message']) . $notes;
        return $r['status'] === 'exposed' ? '<div class="err">' . $body . '</div>' : '<div class="note">' . $body . '</div>';
    }

    /**
     * The code screen. Returns the session token once a correct code has
     * been entered (now or earlier); otherwise renders the form and exits.
     */
    private static function gate(AccessCode $code, string $title, string $intro): string
    {
        try {
            $cookie = $_COOKIE[AccessCode::COOKIE] ?? null;
            if (is_string($cookie) && $code->hasSession($cookie)) {
                return $cookie;
            }
            $msg = '';
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['step'] ?? '') === 'code') {
                $token = $code->verify((string) ($_POST['code'] ?? ''));
                if ($token !== null) {
                    setcookie(AccessCode::COOKIE, $token, ['expires' => time() + 3600, 'path' => '/', 'secure' => self::https(), 'httponly' => true, 'samesite' => 'Strict']);
                    header('Location: ' . (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), true, 303);
                    exit;
                }
                $msg = '<div class="err">' . ($code->wasUsed() ? self::e(AccessCode::USED_MESSAGE) : 'That code didn\'t match. Check it against the file and try again.') . '</div>';
            }
            if (!$code->ensure()) {
                header('Location: /', true, 303);
                exit;
            }
        } catch (\RuntimeException) {
            self::page('Better-Cal can\'t write its files', '<p>Better-Cal couldn\'t write the code file into its folder. Check that the account PHP runs as can write there and that the disk isn\'t full, then reload this page.</p>', 503);
        }
        self::page($title, "<p>$intro</p>" . self::httpWarning() . "$msg<form method=\"post\" autocomplete=\"off\"><input type=\"hidden\" name=\"step\" value=\"code\">"
            . '<label>Code<input name="code" placeholder="XXXX-XXXX-XXXX" autocapitalize="characters" spellcheck="false" required></label>'
            . '<button type="submit">Continue</button></form>', 403);
    }

    private static function finishCode(AccessCode $code, string $token): void
    {
        try {
            $code->finish($token);
        } catch (\RuntimeException) {
            // The completed operation is no longer eligible for a code or session.
            error_log('Better-Cal: could not remove the completed access-code file.');
        }
    }

    private static function checkForm(string $token): void
    {
        if (!hash_equals(AccessCode::formToken($token), (string) ($_POST['form'] ?? ''))) {
            self::page('Something went wrong', '<p>That form had expired. <a href="">Reload the page</a> and try again.</p>', 403);
        }
    }

    private static function clearCookie(): void
    {
        setcookie(AccessCode::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => self::https(), 'httponly' => true, 'samesite' => 'Strict']);
    }

    /**
     * Over plain HTTP, the code and the passwords typed next cross the
     * network unencrypted. A warning, not a refusal: some hosts end HTTPS in
     * front of PHP without saying so.
     */
    private static function httpWarning(): string
    {
        $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        if (self::https() || $host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test')) {
            return '';
        }
        $url = 'https://' . $host . (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        return '<div class="note">This page isn\'t using HTTPS, so what you type here travels unencrypted. If your site has a certificate, <a href="' . self::e($url) . '">open it over HTTPS</a> instead.</div>';
    }

    private static function https(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    private static function requestBaseUrl(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) !== 1) {
            return 'https://';
        }
        return (self::https() ? 'https' : (in_array(explode(':', $host)[0], ['localhost', '127.0.0.1'], true) ? 'http' : 'https')) . '://' . $host;
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function page(string $title, string $body, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('Referrer-Policy: no-referrer');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . self::e($title) . '</title><style>'
            . ':root{color-scheme:light dark;--fg:#1d2330;--muted:#5d6577;--bg:#f6f7f9;--card:#fff;--line:#d9dde5;--accent:#5b7fd4;--err:#b3362f}'
            . '@media (prefers-color-scheme:dark){:root{--fg:#e6e8ee;--muted:#9aa2b2;--bg:#16181d;--card:#1f2229;--line:#343844;--accent:#7593dd;--err:#e0766f}}'
            . 'body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;padding:24px 16px}'
            . 'main{max-width:34em;margin:0 auto;background:var(--card);border:1px solid var(--line);border-radius:10px;padding:20px 24px}'
            . 'h1{font-size:1.35em;margin:0 0 12px}h2{font-size:1.05em;margin:20px 0 6px}label{display:block;margin:10px 0;font-weight:500}'
            . 'input{display:block;width:100%;box-sizing:border-box;margin-top:4px;padding:8px 10px;font:inherit;color:inherit;background:var(--bg);border:1px solid var(--line);border-radius:6px}'
            . '.row{display:flex;gap:12px}.row label{flex:1}.row .narrow{flex:0 0 7em}'
            . 'button,.btn{display:inline-block;margin-top:16px;padding:9px 18px;font:inherit;font-weight:600;color:#fff;background:var(--accent);border:0;border-radius:6px;text-decoration:none;cursor:pointer}'
            . '.err{color:var(--err);border:1px solid var(--err);border-radius:6px;padding:8px 12px;margin:10px 0}.note{border:1px solid var(--line);border-radius:6px;padding:8px 12px;margin:10px 0}.hint{color:var(--muted);font-size:.92em}'
            . 'pre,code{font:13px ui-monospace,Menlo,monospace}pre{white-space:pre-wrap;word-break:break-all;background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:10px}'
            . 'ul{padding-left:1.2em}a{color:var(--accent)}</style></head><body><main><h1>' . self::e($title) . '</h1>' . $body . '</main></body></html>';
        exit;
    }
}
