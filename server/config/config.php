<?php

declare(strict_types=1);

/** Load KEY=VALUE lines from a .env file into the environment (existing env wins). */
function bc_load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

/** @return array{db:array{dsn:string,user:string,pass:string},base_url:string,gemini:array{key:string,model:string},session_secret:string,vapid:array{public:string,private:string,subject:string},version:string,app_root:string} */
function config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $appRoot = dirname(__DIR__, 2);
    bc_load_env($appRoot . '/.env');

    $env = static fn(string $key, string $default = ''): string => (($v = getenv($key)) === false || $v === '') ? $default : $v;

    $cfg = [
        'db' => [
            'dsn' => $env('BETTERCAL_DB_DSN', 'mysql:host=127.0.0.1;dbname=bettercal;charset=utf8mb4'),
            'user' => $env('BETTERCAL_DB_USER', 'bettercal'),
            'pass' => $env('BETTERCAL_DB_PASS'),
        ],
        'base_url' => rtrim($env('BETTERCAL_BASE_URL', 'http://localhost'), '/'),
        'gemini' => [
            'key' => $env('BETTERCAL_GEMINI_API_KEY'),
            'model' => $env('BETTERCAL_GEMINI_MODEL', 'gemini-3.6-flash'),
        ],
        'session_secret' => $env('BETTERCAL_SESSION_SECRET'),
        'google' => [
            // OAuth client for the Google Calendar connector (docs/google-calendar.md).
            // Empty means the connector is not set up on this server; the
            // Settings page says so instead of offering a Connect button.
            'client_id' => $env('BETTERCAL_GOOGLE_CLIENT_ID'),
            'client_secret' => $env('BETTERCAL_GOOGLE_CLIENT_SECRET'),
        ],
        'maptiler' => [
            // Optional MapTiler tile key; when empty the event detail
            // mini-map keeps plain OSM tiles.
            'key' => $env('BETTERCAL_MAPTILER_KEY'),
        ],
        'vapid' => [
            'public' => $env('BETTERCAL_VAPID_PUBLIC'),
            'private' => $env('BETTERCAL_VAPID_PRIVATE'),
            'subject' => $env('BETTERCAL_VAPID_SUBJECT'),
        ],
        'auth' => [
            // Password guessing is limited per source address (Domain\LoginGuard).
            // By default the app assumes it is NOT behind a proxy and uses the
            // connecting address; X-Forwarded-For is ignored because anyone can
            // send it. Only if a reverse proxy or load balancer on ANOTHER
            // address connects to PHP, list its IPs or CIDR ranges here (comma
            // separated) so the real client address is read from the header.
            // A web server on the same machine passing requests to PHP-FPM is
            // not such a proxy: it already hands PHP the client's address.
            'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', $env('BETTERCAL_TRUSTED_PROXIES'))), static fn(string $p): bool => $p !== '')),
            // Failed password attempts allowed per source per 15 minutes.
            'max_failures' => max(1, (int) $env('BETTERCAL_LOGIN_MAX_FAILURES', '10')),
        ],
        'push' => [
            // Push endpoints must belong to a known browser push service
            // (PushSender::DEFAULT_PUSH_HOSTS). List extra hosts here, comma
            // separated, only if your users' browser hands out endpoints on a
            // different one; each entry also matches its subdomains.
            'extra_hosts' => array_values(array_filter(array_map('trim', explode(',', $env('BETTERCAL_PUSH_EXTRA_HOSTS'))), static fn(string $h): bool => $h !== '')),
        ],
        'smtp' => [
            // Optional SMTP relay for reminder emails; host + from are the
            // minimum, user/pass only when the relay requires auth. Email
            // delivery stays off (gracefully) while these are empty.
            'host' => $env('BETTERCAL_SMTP_HOST'),
            'port' => (int) $env('BETTERCAL_SMTP_PORT', '587'),
            'user' => $env('BETTERCAL_SMTP_USER'),
            'pass' => $env('BETTERCAL_SMTP_PASS'),
            'from' => $env('BETTERCAL_SMTP_FROM'),
        ],
        // Where system-wide failure emails go (a job type that keeps failing,
        // as opposed to one user's feed or device, which go to that user).
        // Empty means the first user, who on a self-hosted install is the
        // operator. Needs the SMTP block above to do anything.
        'alert_email' => $env('BETTERCAL_ALERT_EMAIL', ''),
        'imap' => [
            // Mailbox the ingest worker polls for forwarded invites
            // (docs/email-ingest.md). Defaults reuse the SMTP mailbox
            // (calendar@): same account, IMAP port.
            'host' => $env('BETTERCAL_IMAP_HOST', $env('BETTERCAL_SMTP_HOST')),
            'port' => (int) $env('BETTERCAL_IMAP_PORT', '993'),
            'user' => $env('BETTERCAL_IMAP_USER', $env('BETTERCAL_SMTP_USER')),
            'pass' => $env('BETTERCAL_IMAP_PASS', $env('BETTERCAL_SMTP_PASS')),
        ],
        'rsvp_smtp' => [
            // Optional second SMTP profile for iMIP RSVP replies, so they can
            // come from the address organizers actually invited (e.g. Gmail
            // with an app password). Falls back to the main SMTP profile
            // (calendar@) when empty — accepted by many but not all servers.
            'host' => $env('BETTERCAL_RSVP_SMTP_HOST'),
            'port' => (int) $env('BETTERCAL_RSVP_SMTP_PORT', '587'),
            'user' => $env('BETTERCAL_RSVP_SMTP_USER'),
            'pass' => $env('BETTERCAL_RSVP_SMTP_PASS'),
            'from' => $env('BETTERCAL_RSVP_SMTP_FROM'),
        ],
        // One source of truth: VERSION at the repository root (docs/versioning in CHANGELOG.md).
        'version' => (static function () use ($appRoot): string {
            $v = @file_get_contents($appRoot . '/VERSION');
            return is_string($v) && preg_match('/^\d+\.\d+\.\d+$/', trim($v)) === 1 ? trim($v) : '0.0.0';
        })(),
        'app_root' => $appRoot,
    ];

    // Work budgets (Support\Limits): BETTERCAL_LIMIT_<NAME> overrides a default.
    $limits = [];
    foreach (\BetterCal\Support\Limits::names() as $name) {
        $value = $env('BETTERCAL_LIMIT_' . $name);
        if ($value !== '') {
            $limits[$name] = $value;
        }
    }
    \BetterCal\Support\Limits::configure($limits);

    return $cfg;
}
