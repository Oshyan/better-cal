<?php

declare(strict_types=1);

namespace BetterCal\Support;

/**
 * The settings file, `.env.php` (docs/private-files.md, #142).
 *
 * The same KEY=VALUE lines as `.env`, after a first line that makes the file
 * inert if a web server is ever asked for it: PHP runs it, `__halt_compiler()`
 * stops it at once, and the response is empty. It is always read as text,
 * never included. Nothing after the guard is ever parsed as PHP, so no value
 * (a password containing `<?`, say) can break it.
 *
 * `.env` still works until it's converted; `.env.php` wins when both exist.
 * Real environment variables win over both, as before.
 */
final class SettingsFile
{
    /** The first line of every guarded file Better-Cal writes. Checked as an exact prefix. */
    public const GUARD_PREFIX = '<?php __halt_compiler();';
    public const GUARD = self::GUARD_PREFIX . " // Better-Cal settings. Keep this first line: it stops this file being shown on the web.\n";

    /**
     * Line breaks are \n, \r\n and \r only. PCRE's \R also breaks at
     * vertical tab, form feed and byte 0x85, which is inside the UTF-8 for
     * letters like Å, ą and х, so a password containing one came back cut in
     * two.
     */
    private const LINES = '/\r\n|\n|\r/';

    public static function guarded(string $appRoot): string
    {
        return $appRoot . '/.env.php';
    }

    public static function plain(string $appRoot): string
    {
        return $appRoot . '/.env';
    }

    /** The file in use, or null when there is none (not set up yet). */
    public static function path(string $appRoot): ?string
    {
        foreach ([self::guarded($appRoot), self::plain($appRoot)] as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
        return null;
    }

    /**
     * The settings as key => value. Throws SettingsFileError when .env.php
     * can't be trusted; repairs a guard line that was simply deleted.
     *
     * @return array<string,string>
     */
    public static function load(string $appRoot): array
    {
        $path = self::path($appRoot);
        if ($path === null || !is_readable($path)) {
            return [];
        }
        $raw = (string) file_get_contents($path);
        if (str_ends_with($path, '.php')) {
            $raw = self::checkGuard($path, $raw);
        }
        return self::parse($raw);
    }

    /**
     * The text after the guard. A BOM, blank lines and CRLF before it are
     * fine; anything else in front of it would be printed by PHP, and a
     * file in another encoding (UTF-16) can't be guarded at all.
     */
    public static function checkGuard(string $path, string $raw): string
    {
        if (str_contains(substr($raw, 0, 64), "\0")) {
            throw new SettingsFileError(basename($path) . " isn't plain text (it may have been saved as UTF-16). Save it as UTF-8 with its first line intact.");
        }
        $text = str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;
        $lead = ltrim($text, "\r\n");
        if (str_starts_with($lead, self::GUARD_PREFIX)) {
            $nl = strpos($lead, "\n");
            return $nl === false ? '' : substr($lead, $nl + 1);
        }
        // The guard line was deleted or changed. If what's left is plainly
        // settings (comments, blank lines and KEY=VALUE lines, at least one
        // of them a setting), put it back rather than refuse to start: a
        // file already exposed isn't made safer by taking the app down. An
        // empty file is never "repaired": it may be an editor or upload
        // halfway through writing it, and replacing it would lose it all.
        if (trim($lead) === '') {
            throw new SettingsFileError(basename($path) . ' is empty (it may be in the middle of being saved).');
        }
        $firstContent = '';
        foreach (preg_split(self::LINES, $lead) ?: [] as $line) {
            $t = trim($line);
            if ($t !== '') {
                $firstContent = $t;
                break;
            }
        }
        $looksLikeSettings = (str_starts_with($firstContent, '#') || preg_match('/^[A-Z][A-Z0-9_]*=/', $firstContent) === 1)
            && preg_match('/^\s*[A-Z][A-Z0-9_]*=/m', $lead) === 1;
        // Never through a symbolic link: replacing it would put a copy of
        // settings kept elsewhere on purpose into the app folder.
        if ($looksLikeSettings && !is_link($path) && self::repairGuard($path, $raw, $lead)) {
            error_log('settings: put back the first line of ' . basename($path) . ', which keeps it from being shown on the web');
            return $lead;
        }
        throw new SettingsFileError(basename($path) . " has lost or changed its first line, which keeps it from being shown on the web. Make its first line exactly:\n" . rtrim(self::GUARD));
    }

    /**
     * Put the guard back, holding the settings lock and only if the file
     * still reads as it did: one being uploaded a piece at a time must not
     * be replaced with the part that has arrived.
     */
    private static function repairGuard(string $path, string $raw, string $lead): bool
    {
        $lock = self::lock(dirname($path));
        if ($lock === null) {
            return false;
        }
        try {
            clearstatcache(true, $path);
            if (@file_get_contents($path) !== $raw) {
                return false;
            }
            return self::writeAtomic($path, self::GUARD . $lead);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The settings lock (.runtime-cache/settings.lock), held while the file
     * is converted or repaired; null when another process holds it or it
     * can't be made. Made as root, it still belongs to the app's account.
     *
     * @return resource|null
     */
    private static function lock(string $appRoot)
    {
        $lockDir = $appRoot . '/.runtime-cache';
        $asRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
        if (!is_dir($lockDir) && @mkdir($lockDir, 0700) && $asRoot) {
            @chown($lockDir, (int) fileowner($appRoot));
            @chgrp($lockDir, (int) filegroup($appRoot));
        }
        $lockFile = $lockDir . '/settings.lock';
        $newLock = !file_exists($lockFile);
        $lock = @fopen($lockFile, 'c');
        if ($lock === false) {
            return null;
        }
        if ($newLock && $asRoot) {
            @chown($lockFile, (int) fileowner($appRoot));
            @chgrp($lockFile, (int) filegroup($appRoot));
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return null;
        }
        return $lock;
    }

    /** KEY=VALUE lines, as config.php has always read them. @return array<string,string> */
    public static function parse(string $text): array
    {
        $out = [];
        foreach (preg_split(self::LINES, $text) ?: [] as $line) {
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
            if ($key !== '') {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Replace a file through a temporary file in the same folder whose name
     * also ends in .php, so it is inert even in the moment it exists. Keeps
     * the file's mode (0600 for a new one). False when it couldn't.
     */
    public static function writeAtomic(string $path, string $contents, ?int $mode = null): bool
    {
        $mode ??= is_file($path) ? (fileperms($path) & 0777) : 0600;
        // Run as root (an operator's command): the file stays the app's.
        $owner = is_file($path) ? $path : dirname($path);
        $uid = @fileowner($owner);
        $gid = @filegroup($owner);
        $tmp = dirname($path) . '/.' . basename($path, '.php') . '.tmp-' . bin2hex(random_bytes(8)) . '.php';
        $old = umask(0077);
        try {
            // On disk before the rename, so a crash can't leave an empty file.
            $fh = @fopen($tmp, 'x');
            if ($fh === false) {
                return false;
            }
            $ok = @fwrite($fh, $contents) === strlen($contents) && fflush($fh) && (!function_exists('fsync') || @fsync($fh));
            fclose($fh);
            if (!$ok) {
                @unlink($tmp);
                return false;
            }
            @chmod($tmp, $mode);
            if (function_exists('posix_geteuid') && posix_geteuid() === 0 && $uid !== false) {
                @chown($tmp, (int) $uid);
                @chgrp($tmp, (int) $gid);
            }
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                return false;
            }
            return true;
        } finally {
            umask($old);
        }
    }

    /**
     * Turn `.env` into `.env.php`, or tidy up when both exist. Only when the
     * file belongs to the account running this (a root-owned `.env`, as on a
     * server set up by the deploy script, is left for its operator: run
     * `php server/bin/settings.php --convert` as that user). Returns what it
     * did, in words, or null when there was nothing to do.
     */
    public static function convert(string $appRoot, bool $anyOwner = false): ?string
    {
        $plain = self::plain($appRoot);
        $guarded = self::guarded($appRoot);
        if (!is_file($plain) || is_link($plain)) {
            return null; // a symbolic link points at settings kept elsewhere on purpose
        }
        if (!$anyOwner && function_exists('posix_geteuid') && @fileowner($plain) !== posix_geteuid()) {
            return null;
        }
        $asRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $lock = self::lock($appRoot);
        if ($lock === null) {
            return null; // another process is converting
        }
        try {
            clearstatcache();
            if (!is_file($plain)) {
                return null;
            }
            $text = (string) file_get_contents($plain);
            if (is_file($guarded)) {
                if (self::same(self::parse(self::checkGuard($guarded, (string) file_get_contents($guarded))), self::parse($text))) {
                    return @unlink($plain) ? 'Removed .env, an old copy of the settings already in .env.php.' : null;
                }
                return null; // both exist and differ: reported by the private-files check, never resolved silently
            }
            $mode = fileperms($plain) & 0777;
            if (PHP_SAPI !== 'cli') {
                // Converted by the web server's own PHP, which owns the file
                // and so reads it at 0600: a hand-made .env is usually 0644,
                // readable by other accounts on a shared server.
                $mode = 0600;
            }
            $body = ltrim(str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text, "\r\n");
            if (str_starts_with($body, self::GUARD_PREFIX)) {
                $nl = strpos($body, "\n");
                $body = $nl === false ? '' : substr($body, $nl + 1); // copied from the example, guard and all
            }
            if (!self::writeAtomic($guarded, self::GUARD . $body, $mode)) {
                return null;
            }
            if ($asRoot) {
                @chown($guarded, (int) fileowner($plain));
                @chgrp($guarded, (int) filegroup($plain));
            }
            $check = self::parse(self::checkGuard($guarded, (string) file_get_contents($guarded)));
            if (!self::same($check, self::parse($text))) {
                @unlink($guarded);
                throw new SettingsFileError('Converting .env to .env.php gave different settings; .env was left as it is.');
            }
            @unlink($plain);
            return 'Moved the settings from .env to .env.php, which a web server shows as a blank page.';
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** The same settings, compared as strings (never "1e3" == "1000"), in any order. */
    private static function same(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);
        return $a === $b;
    }
}
