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
        'version' => '0.1.0',
        'app_root' => $appRoot,
    ];
    return $cfg;
}
