<?php

declare(strict_types=1);

// First install, in one go (docs/install.md):
//
//   php server/bin/install.php
//
// Checks this PHP, asks for the site's address, the database and your
// account, writes .env.php (with a generated session secret and push keys),
// creates the tables and your account, and prints the two things left to do
// by hand: pointing the web server at server/public, and the worker's cron
// line. Run it as the account that owns the files.
//
// Safe to run again: with settings already in place it uses them, applies
// any pending migrations, and only creates an account if none exists.
//
// For scripts, answers can come from options instead of questions:
//   --url=https://cal.example.com --db-host=localhost --db-port=3306
//   --db-name=bettercal --db-user=bettercal --email=you@example.com
// with the passwords in BETTERCAL_DB_PASS and BETTERCAL_SEED_PASSWORD.

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Infra\Db;
use BetterCal\Infra\Migrator;
use BetterCal\Infra\PrivateFiles;
use BetterCal\Infra\PrivateFilesCheck;
use BetterCal\Infra\Setup;
use BetterCal\Support\Cli;

$appRoot = dirname(__DIR__, 2);
$envPath = BetterCal\Support\SettingsFile::guarded($appRoot);
$opt = getopt('', ['url:', 'db-host:', 'db-port:', 'db-name:', 'db-user:', 'email:', 'name:', 'help']);
if (isset($opt['help'])) {
    Cli::say('Usage: php server/bin/install.php [--url=…] [--db-host=…] [--db-port=…] [--db-name=…] [--db-user=…] [--email=…]');
    Cli::say('Asks for anything not given. Passwords: BETTERCAL_DB_PASS and BETTERCAL_SEED_PASSWORD, or asked with the input hidden.');
    exit(0);
}
$interactive = Cli::interactive();
$need = static function (string $key, string $prompt, string $default = '') use (&$opt, $interactive): string {
    if (isset($opt[$key]) && is_string($opt[$key]) && $opt[$key] !== '') {
        return trim($opt[$key]);
    }
    if (!$interactive) {
        Cli::warn("Missing --$key (no terminal to ask on).");
        exit(2);
    }
    return Cli::ask($prompt, $default);
};
$secret = static function (string $env, string $prompt, bool $twice) use ($interactive): string {
    $v = getenv($env);
    if (is_string($v) && $v !== '') {
        return $v;
    }
    if (!$interactive) {
        Cli::warn("Set $env (no terminal to ask on).");
        exit(2);
    }
    while (true) {
        $a = Cli::askHidden($prompt);
        if (!$twice) {
            return $a;
        }
        if ($a === Cli::askHidden('Again')) {
            return $a;
        }
        Cli::say('The two entries differ; once more.');
    }
};

Cli::say('Better-Cal ' . trim((string) @file_get_contents($appRoot . '/VERSION')) . ' install, in ' . $appRoot);
Cli::say();

// 1. This PHP.
$missing = false;
foreach (Setup::requirements($appRoot) as $row) {
    if ($row['ok']) {
        continue;
    }
    Cli::say(($row['required'] ? 'MISSING  ' : 'note     ') . $row['name']);
    Cli::say('         ' . $row['fix']);
    $missing = $missing || $row['required'];
}
if ($missing) {
    Cli::say();
    Cli::say('Fix the missing items above, then run this again.');
    exit(1);
}
Cli::say('PHP and its extensions: ok.');

// 2. Settings: an existing .env, or a new one.
if (BetterCal\Support\SettingsFile::path($appRoot) !== null) {
    Cli::say('Using the existing .env.');
    $cfg = config();
    $problem = Setup::checkDatabase($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['pass']);
    if ($problem !== null) {
        Cli::say("The database in .env doesn't work. $problem");
        exit(1);
    }
    $email = null;
} else {
    Cli::say();
    Cli::say('The address people will open Better-Cal at, with https:// (a domain or subdomain of its own).');
    while (true) {
        $url = rtrim($need('url', 'Address', 'https://'), '/');
        $parts = parse_url($url);
        if (is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) && empty($parts['path']) && empty($parts['query'])) {
            break;
        }
        $local = is_array($parts) && ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true);
        if ($local) {
            Cli::say('Plain http works only on this machine: sign-in, the app and push need https anywhere else.');
            break;
        }
        Cli::say('That needs to be https://your.domain, with nothing after the domain.');
        if (isset($opt['url'])) {
            exit(2);
        }
    }

    Cli::say();
    Cli::say('The database: one empty MySQL or MariaDB database and a user with full rights on it.');
    while (true) {
        $host = $need('db-host', 'Database host', 'localhost');
        $port = (int) $need('db-port', 'Port', '3306');
        $name = $need('db-name', 'Database name', 'bettercal');
        $user = $need('db-user', 'Database user', 'bettercal');
        $pass = $secret('BETTERCAL_DB_PASS', 'Database password', false);
        try {
            $dsn = Setup::dsn($host, $port, $name);
        } catch (\InvalidArgumentException $e) {
            Cli::say($e->getMessage());
            $dsn = null;
        }
        $problem = $dsn === null ? 'Fix the details above.' : Setup::checkDatabase($dsn, $user, $pass);
        if ($problem === null) {
            Cli::say('Connected.');
            break;
        }
        Cli::say($problem);
        if (!$interactive) {
            exit(1);
        }
        Cli::say('Once more:');
        unset($opt['db-host'], $opt['db-port'], $opt['db-name'], $opt['db-user']);
    }

    $email = $need('email', 'Your email address (you sign in with it)');
    $push = null;
    try {
        $push = Setup::vapidKeys();
    } catch (\Throwable $e) {
        Cli::say('Push reminders are left off: ' . $e->getMessage());
    }
    $values = [
        'BETTERCAL_BASE_URL' => $url,
        'BETTERCAL_DB_DSN' => $dsn,
        'BETTERCAL_DB_USER' => $user,
        'BETTERCAL_DB_PASS' => $pass,
        'BETTERCAL_SESSION_SECRET' => Setup::secret(),
    ] + ($push !== null ? [
        'BETTERCAL_VAPID_PUBLIC' => $push['public'],
        'BETTERCAL_VAPID_PRIVATE' => $push['private'],
        'BETTERCAL_VAPID_SUBJECT' => 'mailto:' . $email,
    ] : []);
    $example = @file_get_contents($appRoot . '/.env.example');
    try {
        Setup::writeEnv($envPath, Setup::envText($example === false ? '' : $example, $values));
    } catch (\Throwable $e) {
        Cli::say($e->getMessage());
        exit(1);
    }
    Cli::say('Wrote .env.php (readable by this account only), with a new session secret' . ($push !== null ? ' and push keys.' : '.'));
    $cfg = config();
}

// 3. Tables.
$db = new Db($cfg['db']);
try {
    $ran = (new Migrator($db, dirname(__DIR__) . '/migrations'))->run(static function (string $line): void {});
} catch (\Throwable $e) {
    Cli::say('Creating the tables failed: ' . $e->getMessage());
    Cli::say('Nothing else was changed. Fix the cause and run this again; it picks up where it stopped.');
    exit(1);
}
Cli::say($ran === 0 ? 'Tables: already up to date.' : "Tables: created ($ran steps).");
foreach (PrivateFiles::secure($cfg) as $line) {
    Cli::say($line);
}

// 4. Your account: Better-Cal has one.
$existing = (int) $db->scalar('SELECT COUNT(*) FROM users');
if ($existing > 0) {
    Cli::say('An account already exists. (To set its password: php server/bin/seed.php --email=…)');
} else {
    $email ??= $need('email', 'Your email address (you sign in with it)');
    while (true) {
        $password = $secret('BETTERCAL_SEED_PASSWORD', 'Choose a password (8 characters or more)', true);
        try {
            Setup::createOwner($db, $email, $password, isset($opt['name']) && is_string($opt['name']) ? $opt['name'] : '');
            break;
        } catch (\InvalidArgumentException $e) {
            Cli::say($e->getMessage());
            if (!$interactive || getenv('BETTERCAL_SEED_PASSWORD')) {
                exit(1);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                unset($opt['email']);
                $email = $need('email', 'Your email address');
            }
        }
    }
    Cli::say("Account created for $email, with a calendar to start in.");
}

// 5. What's left to do by hand.
$base = $cfg['base_url'];
Cli::say();
Cli::say('Two things left, then open ' . $base . ':');
Cli::say();
Cli::say('1. Point the web server at:');
Cli::say('     ' . $appRoot . '/server/public');
Cli::say('   (on shared hosting, the web directory setting for your domain; Nginx and Apache examples are in docs/install.md).');
Cli::say();
Cli::say('2. Run the worker every minute: crontab -e, and add this line');
Cli::say('     ' . Setup::cronLine($appRoot));
Cli::say();
Cli::say('Then ' . $base . '/api/v1/health should show {"ok":true,...}, and signing in starts the welcome.');
Cli::say('Updates: php server/bin/update.php (docs/install.md, Updating).');
privateFilesSay($cfg);

/** The private-files check, said only when it isn't plainly Safe (Settings, System shows Safe). */
function privateFilesSay(array $cfg): void
{
    try {
        $r = PrivateFilesCheck::run($cfg);
    } catch (\Throwable) {
        return;
    }
    if ($r['status'] === 'safe') {
        return;
    }
    Cli::say();
    Cli::say($r['title'] . ' ' . $r['message']);
    foreach ($r['notes'] as $note) {
        Cli::say('  - ' . $note);
    }
}
