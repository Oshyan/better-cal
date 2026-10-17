<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/** Checks before touching private storage. Never repairs a link or a foreign file. */
final class PrivatePath
{
    /** PHP enforces this boundary itself, including resolved link targets. */
    public static function scope(string $path): ?string
    {
        $restriction = (string) ini_get('open_basedir');
        if ($restriction === '') { return '/'; }
        foreach (explode(PATH_SEPARATOR, $restriction) as $base) {
            if ($base === '/') { return '/'; }
            if ($base !== '' && $base[0] !== '/') { $base = (string) @realpath($base); }
            $base = rtrim($base, '/');
            if ($base !== '' && $base[0] === '/' && ($path === $base || str_starts_with($path, $base . '/'))) {
                return $base;
            }
        }
        return null;
    }

    public static function owner(string $probeDir): int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }
        // POSIX is optional on shared hosting. This empty, private temporary
        // handle tells us the writing identity, unlike getmyuid (code owner).
        $probe = @tmpfile();
        $path = null;
        if ($probe === false) {
            // Some hosts permit neither POSIX nor the system temp folder.
            // An exclusive empty probe cannot overwrite or disclose data.
            $path = $probeDir . '/.bc-owner-' . bin2hex(random_bytes(16));
            $mask = umask(0077);
            try { $probe = @fopen($path, 'xb'); } finally { umask($mask); }
        }
        if ($probe === false) {
            throw new \RuntimeException('Could not determine the account for private files.');
        }
        try {
            return (int) fstat($probe)['uid'];
        } finally {
            fclose($probe);
            if ($path !== null) { @unlink($path); }
        }
    }

    public static function check(string $path, bool $directory, bool $missing = false): void
    {
        if (!str_starts_with($path, '/') || preg_match('~(?:^|/)\.\.?(/|$)|//|\x00~', $path)) {
            throw new \RuntimeException('Private storage needs a canonical absolute path.');
        }
        $scope = self::scope($path);
        if ($scope === null) {
            throw new \RuntimeException('Private storage is outside the folders PHP may use.');
        }
        $owner = self::owner($directory && is_dir($path) ? $path : dirname($path));
        $group = function_exists('posix_getegid') ? posix_getegid() : null;
        $parts = explode('/', trim($path, '/'));
        $at = '';
        foreach ($parts as $i => $part) {
            $at .= '/' . $part;
            // Hosts may allow the app but prohibit even stat() on its
            // ancestors. Trust the host's configured boundary, not a link
            // encountered within it; PHP also confines resolved targets.
            if ($scope !== '/' && strlen($at) < strlen($scope)) { continue; }
            clearstatcache(true, $at);
            $st = @lstat($at);
            $last = $i === count($parts) - 1;
            if ($st === false) {
                if ($last && $missing) {
                    return;
                }
                throw new \RuntimeException('A private storage directory is missing or unreadable.');
            }
            $type = $st['mode'] & 0170000;
            if ($type !== (($last && !$directory) ? 0100000 : 0040000)) {
                throw new \RuntimeException('Private storage must use real directories and regular files, not symbolic links.');
            }
            if ($last && ((int) $st['uid'] !== $owner || (!$directory && (int) $st['nlink'] !== 1))) {
                throw new \RuntimeException('Private storage must belong to the account running Better-Cal and have no hard links. Run this operation as its owner.');
            }
            // Hosting account groups commonly have write access to the home
            // or app folder. Trust the effective group, not every writable
            // group. Without POSIX, leave group isolation to the host.
            // A sticky system temporary directory is safe for an owned child.
            // Root must not use its authority inside a lower-trust writable tree.
            $otherGroupWritable = ($st['mode'] & 0020) !== 0 && $group !== null && (int) $st['gid'] !== $group;
            if (!$last && ((($st['mode'] & 0002) !== 0 || $otherGroupWritable) && ($st['mode'] & 01000) === 0
                || $owner === 0 && (int) $st['uid'] !== 0)) {
                throw new \RuntimeException('A private storage parent can be changed by another account. Run this operation as the storage owner.');
            }
        }
    }

    public static function directory(string $path, bool $create = false): void
    {
        self::check($path, true, $create);
        if (!is_dir($path) && !@mkdir($path, 0700)) {
            throw new \RuntimeException('Could not create the private storage directory.');
        }
        self::check($path, true);
        if (!@chmod($path, 0700)) {
            throw new \RuntimeException('Could not make the private storage directory private.');
        }
    }
}
