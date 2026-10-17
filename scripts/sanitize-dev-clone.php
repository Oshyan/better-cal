<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Sanitize a production database snapshot before it is published as dev.
 *
 * deploy-dev.sh streams this reviewed file to PHP as root while the snapshot
 * is still in an unprivileged staging schema. Secrets remain in process memory:
 * no credential or feed URL is printed, logged, or placed in argv.
 */

const BC_DEV_CLONE_CONTEXT = 'bettercal-sealed-credentials';
const BC_DEV_CLONE_SOURCE_PURPOSE = 'calendar-source-url';

function bcDevCloneKey(string $sessionSecret): string
{
    if ($sessionSecret === '') {
        throw new RuntimeException('A non-empty session secret is required');
    }
    return sodium_crypto_generichash(
        BC_DEV_CLONE_CONTEXT . '|' . $sessionSecret,
        '',
        SODIUM_CRYPTO_SECRETBOX_KEYBYTES
    );
}

function bcDevCloneSealSource(string $url, string $sessionSecret): string
{
    $plain = BC_DEV_CLONE_SOURCE_PURPOSE . "\0" . $url;
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $sealed = sodium_crypto_secretbox($plain, $nonce, bcDevCloneKey($sessionSecret));
    return 'v1:' . sodium_bin2base64($nonce . $sealed, SODIUM_BASE64_VARIANT_ORIGINAL);
}

function bcDevCloneOpenSource(string $stored, string $sessionSecret): string
{
    if (!str_starts_with($stored, 'v1:')) {
        return $stored;
    }
    try {
        $raw = sodium_base642bin(substr($stored, 3), SODIUM_BASE64_VARIANT_ORIGINAL);
    } catch (SodiumException $e) {
        throw new RuntimeException('A cloned subscription credential is corrupt', 0, $e);
    }
    if (strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        throw new RuntimeException('A cloned subscription credential is corrupt');
    }
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, bcDevCloneKey($sessionSecret));
    $prefix = BC_DEV_CLONE_SOURCE_PURPOSE . "\0";
    if ($plain === false || !str_starts_with($plain, $prefix)) {
        throw new RuntimeException('A cloned subscription credential could not be opened');
    }
    return substr($plain, strlen($prefix));
}

/** @return array{subscriptions:int,stripped:bool} */
function bcSanitizeDevClone(
    PDO $pdo,
    string $productionSecret,
    string $developmentSecret,
    string $developmentLoginPassword,
    bool $stripSubscriptions,
): array {
    if ($productionSecret === '' || $developmentSecret === '' || hash_equals($productionSecret, $developmentSecret)) {
        throw new RuntimeException('Production and development session secrets must be non-empty and distinct');
    }
    $passwordLength = strlen($developmentLoginPassword);
    if ($passwordLength < 8 || $passwordLength > 1024 || str_contains($developmentLoginPassword, "\n")
        || str_contains($developmentLoginPassword, "\r")) {
        throw new RuntimeException('The development login password is invalid');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->beginTransaction();
    try {
        $ics = $pdo->query(
            "SELECT id, source_url FROM calendars
             WHERE kind = 'subscribed' AND COALESCE(provider, 'ics') = 'ics'
             ORDER BY id"
        )->fetchAll();

        if ($stripSubscriptions) {
            $pdo->exec(
                "UPDATE calendars SET kind = 'local', provider = 'ics', source_url = NULL,
                 subscription_authority = 'owner', created_by_token_id = NULL
                 WHERE kind = 'subscribed' AND COALESCE(provider, 'ics') = 'ics'"
            );
        } else {
            $updateSource = $pdo->prepare('UPDATE calendars SET source_url = ? WHERE id = ?');
            foreach ($ics as $row) {
                $stored = $row['source_url'];
                if ($stored === null || $stored === '') {
                    continue;
                }
                $url = bcDevCloneOpenSource((string) $stored, $productionSecret);
                $updateSource->execute([
                    bcDevCloneSealSource($url, $developmentSecret),
                    (int) $row['id'],
                ]);
            }
        }

        // Google calendars can be writable and cannot work after their OAuth
        // account is removed. Keep their cached events as inert local data.
        $pdo->exec(
            "UPDATE calendars SET kind = 'local', provider = 'ics', source_url = NULL,
             subscription_authority = 'owner', created_by_token_id = NULL,
             google_calendar_id = NULL, google_access_role = NULL,
             google_sync_token = NULL, google_account_id = NULL
             WHERE COALESCE(provider, 'ics') = 'google'"
        );

        // A source URL has meaning only on the explicitly preserved ICS rows.
        $pdo->exec(
            "UPDATE calendars SET source_url = NULL
             WHERE NOT (kind = 'subscribed' AND COALESCE(provider, 'ics') = 'ics')"
        );

        foreach (['sessions', 'trusted_devices', 'push_subscriptions', 'out_feeds', 'google_accounts', 'api_tokens'] as $table) {
            $pdo->exec('DELETE FROM `' . $table . '`');
        }

        // Plugins can hold API credentials in their settings/KV and can make
        // outbound HTTP calls when their copied jobs run. Preserve generated
        // calendar/event data as a snapshot, but require deliberate dev-only
        // configuration before any plugin can execute again.
        $pdo->exec(
            "UPDATE plugins SET enabled = 0, settings_json = NULL,
             consecutive_failures = 0, disabled_reason = 'Disabled in development clone'"
        );
        $pdo->exec('DELETE FROM plugin_kv');
        $pdo->exec('DELETE FROM http_cache');
        $pdo->exec('UPDATE plugin_runs SET log_tail = NULL');
        $pdo->exec(
            "UPDATE jobs SET status = 'failed', payload_json = NULL,
             last_error = 'Cancelled while creating development clone'
             WHERE status IN ('pending', 'running')"
        );
        $calendarSettings = $pdo->query(
            'SELECT id, settings_json FROM calendars WHERE settings_json IS NOT NULL'
        )->fetchAll();
        $updateCalendarSettings = $pdo->prepare('UPDATE calendars SET settings_json = ? WHERE id = ?');
        foreach ($calendarSettings as $row) {
            try {
                $settings = json_decode((string) $row['settings_json'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException('A cloned calendar has invalid settings', 0, $e);
            }
            if (!is_array($settings) || !array_key_exists('plugins', $settings)) {
                continue;
            }
            unset($settings['plugins']);
            $updateCalendarSettings->execute([
                json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                (int) $row['id'],
            ]);
        }

        $hash = password_hash($developmentLoginPassword, PASSWORD_DEFAULT);
        $setPassword = $pdo->prepare('UPDATE users SET password_hash = ?');
        $setPassword->execute([$hash]);
        $pdo->exec('UPDATE mutations SET before_json = NULL, after_json = NULL');

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    foreach (['sessions', 'trusted_devices', 'push_subscriptions', 'out_feeds', 'google_accounts', 'api_tokens'] as $table) {
        if ((int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() !== 0) {
            throw new RuntimeException('Development clone sanitation did not clear ' . $table);
        }
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM plugins WHERE enabled <> 0 OR settings_json IS NOT NULL')->fetchColumn() !== 0
        || (int) $pdo->query('SELECT COUNT(*) FROM plugin_kv')->fetchColumn() !== 0
        || (int) $pdo->query('SELECT COUNT(*) FROM http_cache')->fetchColumn() !== 0
        || (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE status IN ('pending', 'running')")->fetchColumn() !== 0
        || (int) $pdo->query('SELECT COUNT(*) FROM plugin_runs WHERE log_tail IS NOT NULL')->fetchColumn() !== 0) {
        throw new RuntimeException('Development clone sanitation retained active plugin or queued integration state');
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM calendars WHERE COALESCE(provider, 'ics') = 'google'")->fetchColumn() !== 0) {
        throw new RuntimeException('Development clone sanitation retained a Google binding');
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM calendars WHERE source_url IS NOT NULL AND NOT (kind = 'subscribed' AND COALESCE(provider, 'ics') = 'ics')")->fetchColumn() !== 0) {
        throw new RuntimeException('Development clone sanitation retained a misplaced subscription credential');
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM mutations WHERE before_json IS NOT NULL OR after_json IS NOT NULL')->fetchColumn() !== 0) {
        throw new RuntimeException('Development clone sanitation retained an Undo credential snapshot');
    }

    $retained = $pdo->query(
        "SELECT source_url FROM calendars
         WHERE kind = 'subscribed' AND COALESCE(provider, 'ics') = 'ics' AND source_url IS NOT NULL"
    )->fetchAll();
    if ($stripSubscriptions && $retained !== []) {
        throw new RuntimeException('Development clone sanitation retained a stripped subscription');
    }
    foreach ($retained as $row) {
        $stored = (string) $row['source_url'];
        if (!str_starts_with($stored, 'v1:') || bcDevCloneOpenSource($stored, $developmentSecret) === '') {
            throw new RuntimeException('A retained development subscription is not protected by the development secret');
        }
    }
    foreach ($pdo->query('SELECT settings_json FROM calendars WHERE settings_json IS NOT NULL')->fetchAll() as $row) {
        $settings = json_decode((string) $row['settings_json'], true);
        if (is_array($settings) && array_key_exists('plugins', $settings)) {
            throw new RuntimeException('Development clone sanitation retained per-calendar plugin configuration');
        }
    }

    return ['subscriptions' => count($retained), 'stripped' => $stripSubscriptions];
}

/** The settings file: .env.php, or .env until it's converted. Its first line holds no '=', so both read alike. */
function bcDevCloneSettings(string $root): string
{
    return file_exists($root . '/.env.php') || is_link($root . '/.env.php') ? $root . '/.env.php' : $root . '/.env';
}

/** Runs as the file's application identity, never as the privileged clone reader. */
function bcReadCloneSettings(string $root): array
{
    if ($root === '' || $root[0] !== '/' || str_contains($root, '//') || str_contains($root, "\0")) {
        throw new RuntimeException('The clone settings root must be an absolute path');
    }
    if ((file_exists($root . '/.env.php') || is_link($root . '/.env.php')) && (file_exists($root . '/.env') || is_link($root . '/.env'))) {
        throw new RuntimeException('Both .env.php and .env exist; keep one before cloning');
    }
    $path = bcDevCloneSettings($root);
    $at = '';
    foreach (explode('/', trim($path, '/')) as $part) {
        $at .= '/' . $part;
        if ($part === '.' || $part === '..' || is_link($at)) {
            throw new RuntimeException('A clone settings path contains a symbolic link or relative component');
        }
    }
    clearstatcache(true, $path);
    $before = @lstat($path);
    if ($before === false || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1) {
        throw new RuntimeException('A clone settings file must be a regular file without links');
    }
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException('The application identity cannot read its settings');
    }
    try {
        $opened = fstat($fh);
        clearstatcache(true, $path);
        $after = @lstat($path);
        foreach ([$before, $after] as $st) {
            if ($st === false || $st['dev'] !== $opened['dev'] || $st['ino'] !== $opened['ino']
                || ($st['mode'] & 0170000) !== 0100000 || $st['nlink'] !== 1) {
                throw new RuntimeException('The clone settings file changed while it was opened');
            }
        }
        $raw = stream_get_contents($fh);
        if ($raw === false) {
            throw new RuntimeException('Could not read the clone settings');
        }
    } finally {
        fclose($fh);
    }
    $values = [];
    foreach (preg_split('/\r\n|\n|\r/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$found, $value] = explode('=', $line, 2);
        $value = trim($value);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            $value = substr($value, 1, -1);
        }
        $key = trim($found);
        if (isset($values[$key])) {
            throw new RuntimeException('The clone settings contain a repeated key');
        }
        $values[$key] = $value;
    }
    return $values;
}

/** Snapshots are captured once, under each identity, by the trusted remote shell. */
function bcDevCloneSnapshot(string $root): array
{
    $name = $root === (string) getenv('PROD_APP_ROOT') ? 'PROD_SETTINGS_SNAPSHOT' : 'DEV_SETTINGS_SNAPSHOT';
    $values = json_decode((string) getenv($name), true);
    if (!is_array($values) || array_filter($values, static fn($v): bool => !is_string($v)) !== []) {
        throw new RuntimeException('The private clone settings snapshot is missing or invalid');
    }
    return $values;
}

/** Effective-access checks run as dev, including the right to chmod or replace. */
function bcCheckProductionNotWritable(string $root): void
{
    if (!function_exists('posix_geteuid')) {
        throw new RuntimeException('Strict deployment write checks need the POSIX extension in the maintenance PHP command.');
    }
    $uid = posix_geteuid();
    $at = '';
    foreach (explode('/', trim($root, '/')) as $part) {
        $at .= '/' . $part;
        $st = @lstat($at);
        if ($st === false || ($st['mode'] & 0170000) !== 0040000) {
            throw new RuntimeException('Could not verify a real production directory');
        }
        $stickySystem = ($st['mode'] & 01000) !== 0 && $st['uid'] === 0;
        if ($st['uid'] === $uid || (is_writable($at) && !$stickySystem)) {
            throw new RuntimeException('The dev identity can modify or replace production files through a writable parent');
        }
        if (!is_executable($at)) {
            return; // No traversal and no ownership with which to grant it.
        }
    }
    $walk = static function (string $dir) use (&$walk, $uid): void {
        $names = @scandir($dir);
        if ($names === false) {
            throw new RuntimeException('Could not inspect production write access completely');
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') { continue; }
            $path = $dir . '/' . $name;
            $st = @lstat($path);
            if ($st === false || is_link($path)) {
                throw new RuntimeException('Strict dev isolation cannot verify a linked or changing production path');
            }
            if ($st['uid'] === $uid || is_writable($path)) {
                throw new RuntimeException('The dev identity can rewrite a production file or directory');
            }
            if (is_dir($path) && is_executable($path)) { $walk($path); }
        }
    };
    $walk($root);
}

function bcDevCloneDsnForDatabase(string $dsn, string $database): string
{
    if (!str_starts_with($dsn, 'mysql:') || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $database) !== 1) {
        throw new RuntimeException('Clone database configuration is invalid');
    }
    if (preg_match('/(^|;)dbname=[^;]*/', $dsn) === 1) {
        return (string) preg_replace('/(^|;)dbname=[^;]*/', '$1dbname=' . $database, $dsn, 1);
    }
    return rtrim($dsn, ';') . ';dbname=' . $database;
}

/** Prove app credentials cannot open the private staging schema before import. */
function bcVerifyDevCloneStagePrivacy(): void
{
    $productionRoot = (string) getenv('PROD_APP_ROOT');
    $developmentRoot = (string) getenv('DEV_APP_ROOT');
    $stagingDatabase = (string) getenv('STAGING_DB');
    $productionDatabase = (string) getenv('PROD_DB');
    $developmentDatabase = (string) getenv('DEV_DB');
    foreach ([$productionRoot, $developmentRoot] as $root) {
        if ($root === '' || $root[0] !== '/' || is_link($root) || !is_dir($root)) {
            throw new RuntimeException('A clone application root is invalid');
        }
    }
    foreach ([$stagingDatabase, $productionDatabase, $developmentDatabase] as $database) {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $database) !== 1) {
            throw new RuntimeException('Clone database configuration is invalid');
        }
    }

    $configs = [
        [$productionRoot, $productionDatabase],
        [$developmentRoot, $developmentDatabase],
    ];
    foreach ($configs as [$root, $expectedDatabase]) {
        $env = bcDevCloneSnapshot($root);
        $dsn = $env['BETTERCAL_DB_DSN'] ?? '';
        $user = $env['BETTERCAL_DB_USER'] ?? '';
        $password = $env['BETTERCAL_DB_PASS'] ?? '';
        if ($dsn === '' || $user === '' || $password === '') {
            throw new RuntimeException('An application database configuration is incomplete');
        }
        $intended = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ((string) $intended->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('An application database configuration selects an unexpected schema');
        }
        unset($intended);

        try {
            $unexpected = new PDO(
                bcDevCloneDsnForDatabase($dsn, $stagingDatabase),
                $user,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            // MySQL/MariaDB error 1044 is the expected database-access denial.
            if ((int) ($e->errorInfo[1] ?? 0) === 1044) {
                continue;
            }
            throw new RuntimeException('Could not prove that clone staging is private', 0, $e);
        }
        unset($unexpected);
        throw new RuntimeException('An application database identity can access the private clone staging schema');
    }
    echo "Verified private development-clone staging schema.\n";
}

function bcRunDevCloneSanitizer(): void
{
    $productionRoot = (string) getenv('PROD_APP_ROOT');
    $developmentRoot = (string) getenv('DEV_APP_ROOT');
    $database = (string) getenv('STAGING_DB');
    $strip = (string) getenv('STRIP_DEV_SUBSCRIPTIONS');
    foreach ([$productionRoot, $developmentRoot] as $root) {
        if ($root === '' || $root[0] !== '/' || is_link($root) || !is_dir($root)) {
            throw new RuntimeException('A clone application root is invalid');
        }
    }
    if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $database) !== 1 || !in_array($strip, ['0', '1'], true)) {
        throw new RuntimeException('Clone sanitizer configuration is invalid');
    }

    $productionSecret = bcDevCloneSnapshot($productionRoot)['BETTERCAL_SESSION_SECRET'] ?? '';
    $developmentSecret = bcDevCloneSnapshot($developmentRoot)['BETTERCAL_SESSION_SECRET'] ?? '';
    $loginPassword = (string) getenv('DEV_LOGIN_PASSWORD');
    $rootPassword = (string) getenv('MYSQL_ROOT_PASSWORD');
    $pdo = new PDO(
        'mysql:host=127.0.0.1;dbname=' . $database . ';charset=utf8mb4',
        'root',
        $rootPassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
        throw new RuntimeException('Clone sanitizer connected to an unexpected database');
    }
    $result = bcSanitizeDevClone(
        $pdo,
        $productionSecret,
        $developmentSecret,
        $loginPassword,
        $strip === '1',
    );
    echo 'Sanitized development clone; retained ' . $result['subscriptions'] . ' protected ICS subscription(s)'
        . ($result['stripped'] ? ' (stripping requested)' : '') . ".\n";
}

if (getenv('BETTERCAL_READ_CLONE_SETTINGS') === '1') {
    echo json_encode(bcReadCloneSettings((string) getenv('APP_ROOT')), JSON_THROW_ON_ERROR);
} elseif (getenv('BETTERCAL_CHECK_PROD_WRITE') === '1') {
    bcCheckProductionNotWritable((string) getenv('APP_ROOT'));
} elseif (getenv('BETTERCAL_VERIFY_DEV_CLONE_STAGE') === '1') {
    bcVerifyDevCloneStagePrivacy();
} elseif (getenv('BETTERCAL_RUN_DEV_CLONE_SANITIZER') === '1') {
    bcRunDevCloneSanitizer();
}
