<?php
// Better-Cal's CalDAV entry (/dav). The app's code for it is
// server/src/entry-dav.php.
//
// This file is written so that any PHP can read it. PHP reads a whole file
// before running any of it, so on a PHP too old for Better-Cal (a control
// panel's version choice lost in an update, a host changing its default) the
// check below explains instead of the site failing with a blank page. Keep
// it that way, and keep the minimum in step with Infra\Setup::MIN_PHP (a
// test checks both).
if (PHP_VERSION_ID < 80401) {
    header('Content-Type: text/plain; charset=utf-8', true, 503);
    echo 'This site is running PHP ', PHP_VERSION, ". Better-Cal needs PHP 8.4.1 or newer; pick it in the hosting control panel.\n";
    exit;
}
define('BETTERCAL_WEB_ENTRY', true);
// Right after an update swapped the code, compiled copies of the old files
// may still be cached: clear them, once (Support\Maintenance::CODE_CHANGED).
if (is_file(dirname(__DIR__, 2) . '/.code-changed') && @unlink(dirname(__DIR__, 2) . '/.code-changed') && function_exists('opcache_reset')) {
    @opcache_reset();
}
require dirname(__DIR__) . '/src/entry-dav.php';
