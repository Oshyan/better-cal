<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/**
 * The pieces of a first install that bin/install.php puts together, kept apart
 * from any terminal handling so a setup page in the browser (#138) can share
 * them: requirement checks, the database address, writing .env, push keys and
 * the owner's account.
 */
final class Setup
{
    public const MIN_PHP = '8.4.1';
    /** Extensions docs/install.md lists. */
    public const EXTENSIONS = ['pdo_mysql', 'curl', 'mbstring', 'dom', 'simplexml', 'xmlreader', 'xmlwriter', 'zip',
        'json', 'openssl', 'sodium', 'ctype', 'iconv', 'fileinfo'];

    /**
     * What this PHP is missing. Each row: what, whether it's fine, and what to
     * do when it isn't. "required" false means it works without it.
     *
     * @return list<array{name:string,ok:bool,required:bool,fix:string}>
     */
    public static function requirements(string $appRoot): array
    {
        $rows = [[
            'name' => 'PHP ' . self::MIN_PHP . ' or newer (this is ' . PHP_VERSION . ')',
            'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            'required' => true,
            'fix' => "Pick PHP 8.4 or newer in your host's control panel, or install php8.4-cli (docs/install.md).",
        ]];
        foreach (self::EXTENSIONS as $ext) {
            $rows[] = [
                'name' => "PHP extension $ext",
                'ok' => extension_loaded($ext),
                'required' => true,
                'fix' => "Enable $ext in your host's PHP settings, or install it (on Debian and Ubuntu, the php8.4 package that provides it).",
            ];
        }
        $rows[] = [
            'name' => 'PHP libraries (server/vendor)',
            'ok' => is_file($appRoot . '/server/vendor/autoload.php'),
            'required' => true,
            'fix' => 'Use the release zip, which includes them, or run: cd server && composer install --no-dev',
        ];
        $procOpen = function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
        $rows[] = [
            'name' => 'proc_open allowed (time-limited address lookups for outgoing requests)',
            'ok' => $procOpen,
            'required' => false,
            'fix' => 'Optional. Without it everything works, but a stuck DNS server can hold a request longer.',
        ];
        return $rows;
    }

    /** The PDO address for a MySQL or MariaDB database. */
    public static function dsn(string $host, int $port, string $name): string
    {
        foreach (['host' => $host, 'database name' => $name] as $what => $value) {
            if ($value === '' || preg_match('/[;=\s\x00-\x1f]/', $value) === 1) {
                throw new \InvalidArgumentException("The $what can't be empty or contain spaces, ';' or '='.");
            }
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('The port must be between 1 and 65535.');
        }
        return "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
    }

    /**
     * Try the database. Null when it works, else what went wrong in words.
     * Also refuses a server too old to run the migrations.
     */
    public static function checkDatabase(string $dsn, string $user, string $pass): ?string
    {
        try {
            $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 10]);
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (\Throwable $e) {
            return 'Could not connect: ' . $e->getMessage();
        }
        if (stripos($version, 'mariadb') !== false) {
            if (preg_match('/(\d+\.\d+)/', $version, $m) === 1 && version_compare($m[1], '10.6', '<')) {
                return "This is MariaDB $version; Better-Cal needs 10.6 or newer.";
            }
        } elseif (preg_match('/^(\d+\.\d+)/', $version, $m) === 1 && version_compare($m[1], '8.0', '<')) {
            return "This is MySQL $version; Better-Cal needs 8.0 or newer (8.4 is tested).";
        }
        return null;
    }

    /** A random secret for BETTERCAL_SESSION_SECRET. */
    public static function secret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * A Web Push key pair (VAPID, P-256), as .env wants them.
     *
     * @return array{public:string,private:string}
     */
    public static function vapidKeys(): array
    {
        if (!function_exists('openssl_pkey_new')) {
            throw new \RuntimeException('The openssl extension is needed to make push keys.');
        }
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new \RuntimeException('Key generation failed: ' . (openssl_error_string() ?: 'unknown openssl error'));
        }
        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
            throw new \RuntimeException('Could not read the generated key.');
        }
        $b64 = static fn(string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
        $pad = static fn(string $bin): string => str_pad($bin, 32, "\0", STR_PAD_LEFT);
        return [
            'public' => $b64("\x04" . $pad($details['ec']['x']) . $pad($details['ec']['y'])),
            'private' => $b64($pad($details['ec']['d'])),
        ];
    }

    /**
     * .env text from the example file with these values filled in: each
     * KEY= line that isn't commented out is replaced in place, so the
     * explanations stay next to it; a key the example lacks is added at the
     * end. Values are written double-quoted, which config.php strips, so a
     * password with spaces or '#' survives as it is.
     *
     * @param array<string,string> $values
     */
    public static function envText(string $example, array $values): string
    {
        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) !== 1) {
                throw new \InvalidArgumentException("Bad setting name $key");
            }
            if (preg_match('/[\r\n\x00]/', $value) === 1) {
                throw new \InvalidArgumentException("$key can't contain a line break.");
            }
        }
        $lines = explode("\n", $example);
        $done = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=/', $line, $m) === 1 && array_key_exists($m[1], $values) && !isset($done[$m[1]])) {
                $lines[$i] = $m[1] . '=' . self::quote($values[$m[1]]);
                $done[$m[1]] = true;
            }
        }
        $text = rtrim(implode("\n", $lines), "\n") . "\n";
        $missing = array_diff_key($values, $done);
        if ($missing !== []) {
            $text .= "\n# Added by bin/install.php\n";
            foreach ($missing as $key => $value) {
                $text .= $key . '=' . self::quote($value) . "\n";
            }
        }
        return $text;
    }

    /** Values that need no quotes stay bare, so .env reads as people expect. */
    private static function quote(string $value): string
    {
        return preg_match('~^[A-Za-z0-9_.:/@+\-;=,]*$~D', $value) === 1 ? $value : '"' . $value . '"';
    }

    /**
     * Write .env readable by its owner alone. Refuses to replace one: a
     * second install must never silently swap the session secret or the
     * database out from under a working one.
     */
    public static function writeEnv(string $path, string $text): void
    {
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException("$path already exists; not replacing it.");
        }
        $old = umask(0077);
        try {
            $fh = @fopen($path, 'x');
            if ($fh === false) {
                throw new \RuntimeException("Could not create $path: check that this account can write to " . dirname($path) . '.');
            }
            fwrite($fh, $text);
            fclose($fh);
        } finally {
            umask($old);
        }
        @chmod($path, 0600);
    }

    /**
     * The owner's account, with a first calendar, as bin/seed.php makes it.
     * A new account opens on the first-run welcome.
     */
    public static function createOwner(Db $db, string $email, string $password, string $name = ''): int
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('That email address is not valid.');
        }
        if (strlen($password) < 8 || strlen($password) > 1024) {
            throw new \InvalidArgumentException('The password must be 8 characters or more.');
        }
        $userId = $db->insert('users', [
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => $name !== '' ? $name : explode('@', $email)[0],
            'settings_json' => json_encode(['welcomeDone' => false]),
        ]);
        self::ensureCalendar($db, $userId);
        return $userId;
    }

    /** A first "Personal" calendar for an account with none. Returns its id, or null when one existed. */
    public static function ensureCalendar(Db $db, int $userId): ?int
    {
        if ($db->scalar('SELECT id FROM calendars WHERE user_id = ? LIMIT 1', [$userId]) !== null) {
            return null;
        }
        return $db->insert('calendars', ['user_id' => $userId, 'name' => 'Personal', 'color' => '#4a7dff', 'kind' => 'local']);
    }

    /** The crontab line for the worker, with this PHP's own path. */
    public static function cronLine(string $appRoot): string
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        return '* * * * * ' . $php . ' ' . $appRoot . '/server/bin/worker.php >> ' . $appRoot . '/worker.log 2>&1';
    }
}
