<?php

declare(strict_types=1);

// Only the two front controllers (server/public/index.php and dav.php) may
// run from the web; they declare themselves before loading this. Every other
// PHP file (bin/ scripts, tests, anything a host might serve because the app
// folder sits inside a web root that ignores .htaccess) gets a bare 404 when
// requested over HTTP, before it can do anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg' && !defined('BETTERCAL_WEB_ENTRY')) {
    http_response_code(404);
    exit;
}

// Composer autoload when installed (on the server); PSR-4 fallback for local
// CLI use (tests, bin scripts) where composer install has not run.
$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'BetterCal\\')) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('BetterCal\\'))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require_once dirname(__DIR__) . '/config/config.php';
