<?php
// Better-Cal's web entry: every request but /dav comes here. The app's code
// for it is server/src/entry-web.php.
//
// This file is written so that any PHP can read it. PHP reads a whole file
// before running any of it, so on a PHP too old for Better-Cal (a control
// panel's version choice lost in an update, a host changing its default) the
// check below explains instead of the site failing with a blank page. Keep
// it that way, and keep the minimum in step with Infra\Setup::MIN_PHP (a
// test checks both).
if (PHP_VERSION_ID < 80401) {
    header('Content-Type: text/html; charset=utf-8', true, 503);
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width">',
        '<title>Better-Cal needs a newer PHP</title>',
        '<p>This site is running PHP ', htmlspecialchars(PHP_VERSION), '. Better-Cal needs PHP 8.4.1 or newer.</p>',
        '<p>Pick PHP 8.4 or newer for this domain in your hosting control panel\'s PHP settings. ',
        'If you had picked a version before updating Better-Cal, pick it again: the update may have replaced the file where the panel keeps that choice.</p>';
    exit;
}
define('BETTERCAL_WEB_ENTRY', true);
// Right after an update swapped the code, compiled copies of the old files
// may still be cached: clear them, once (Support\Maintenance::CODE_CHANGED).
if (is_file(dirname(__DIR__, 2) . '/.code-changed') && @unlink(dirname(__DIR__, 2) . '/.code-changed') && function_exists('opcache_reset')) {
    @opcache_reset();
}
require dirname(__DIR__) . '/src/entry-web.php';
