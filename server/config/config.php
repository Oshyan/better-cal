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
        'vapid' => [
            'public' => $env('BETTERCAL_VAPID_PUBLIC'),
            'private' => $env('BETTERCAL_VAPID_PRIVATE'),
            'subject' => $env('BETTERCAL_VAPID_SUBJECT'),
        ],
        'version' => '0.1.0',
        'app_root' => $appRoot,
    ];
    return $cfg;
}
