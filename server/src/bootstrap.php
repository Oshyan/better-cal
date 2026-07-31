<?php

declare(strict_types=1);

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
