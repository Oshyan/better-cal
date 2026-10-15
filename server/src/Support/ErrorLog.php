<?php

declare(strict_types=1);

namespace BetterCal\Support;

/**
 * Where PHP writes its errors, kept private (#142). Shared hosts often set
 * PHP's error_log to a bare "error_log", a file beside whichever script ran:
 * for Better-Cal that's server/public/error_log, which the web server hands
 * out like any other file, server paths and all. When the setting points
 * there (a relative name, or anywhere inside the app folder), errors go to
 * .runtime-cache/php-errors.php instead, behind the settings file's guard
 * line. A log the host keeps elsewhere is left alone.
 *
 * The worker also routes errors there when nothing is set, since its cron
 * line discards output.
 */
final class ErrorLog
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    public static function path(string $appRoot): string
    {
        return $appRoot . '/.runtime-cache/php-errors.php';
    }

    public static function route(string $appRoot, bool $whenUnset = false): void
    {
        $current = (string) ini_get('error_log');
        if ($current === '' ? !$whenUnset : !self::insideApp($current, $appRoot)) {
            return;
        }
        $path = self::path($appRoot);
        if (!self::prepare($path)) {
            return;
        }
        ini_set('log_errors', '1');
        ini_set('error_log', $path);
    }

    /**
     * Whether an error_log setting lands in the app folder: a relative name
     * (resolved against the running script's folder) or a path under it.
     */
    public static function insideApp(string $setting, string $appRoot): bool
    {
        if ($setting === 'syslog' || str_starts_with($setting, 'syslog:')) {
            return false;
        }
        if (!str_starts_with($setting, '/') && preg_match('~^[A-Za-z]:[\\\\/]~', $setting) !== 1) {
            return true;
        }
        // Compared as written and resolved, since either may go through a symbolic link.
        $dirs = [dirname($setting), realpath(dirname($setting)) ?: dirname($setting)];
        $roots = [$appRoot, realpath($appRoot) ?: $appRoot];
        foreach ($dirs as $dir) {
            foreach ($roots as $root) {
                if (str_starts_with(rtrim($dir, '/') . '/', rtrim($root, '/') . '/')) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Make sure the log exists, starts with the guard, and hasn't grown past its limit. */
    private static function prepare(string $path): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return false;
        }
        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            $keep = '';
            if ($size !== false) {
                // Keep the newer half, from a line start.
                $fh = @fopen($path, 'rb');
                if ($fh !== false) {
                    fseek($fh, -(int) (self::MAX_BYTES / 2), SEEK_END);
                    $tail = (string) stream_get_contents($fh);
                    fclose($fh);
                    $nl = strpos($tail, "\n");
                    $keep = $nl === false ? '' : substr($tail, $nl + 1);
                }
            }
            return SettingsFile::writeAtomic($path, SettingsFile::GUARD_PREFIX . " // Better-Cal PHP error log\n" . $keep, 0600);
        }
        $head = (string) @file_get_contents($path, false, null, 0, strlen(SettingsFile::GUARD_PREFIX));
        if ($head !== SettingsFile::GUARD_PREFIX) {
            return SettingsFile::writeAtomic($path, SettingsFile::GUARD_PREFIX . " // Better-Cal PHP error log\n", 0600);
        }
        return true;
    }
}
