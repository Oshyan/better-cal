<?php

declare(strict_types=1);

namespace BetterCal\Support;

/**
 * "Updating, back in a minute": while bin/update.php swaps the code and
 * migrates, a file in the app root makes the web front ends answer 503 and
 * the worker skip its run, so nothing writes to a database halfway between
 * two versions. Command-line tools (the migration itself) are unaffected.
 */
final class Maintenance
{
    public const FILE = '.maintenance';
    /**
     * Written when an update swaps the code. PHP's opcode cache keys compiled
     * files by path, so for a moment (or until a restart, where a host turns
     * timestamp checks off) a request can run the old version's compiled
     * copy of a file beside the new version's others. The next web request
     * clears the cache (index.php, dav.php) and removes this.
     */
    public const CODE_CHANGED = '.code-changed';

    public static function codeChanged(string $appRoot): void
    {
        @file_put_contents($appRoot . '/' . self::CODE_CHANGED, gmdate('c') . "\n");
    }

    public static function active(string $appRoot): bool
    {
        return is_file($appRoot . '/' . self::FILE);
    }

    public static function on(string $appRoot, string $reason): void
    {
        if (file_put_contents($appRoot . '/' . self::FILE, json_encode(['since' => gmdate('c'), 'reason' => $reason]) . "\n") === false) {
            throw new \RuntimeException('Could not turn on maintenance mode in ' . $appRoot);
        }
    }

    /** What the maintenance file says (since, reason), or null. @return ?array<string,string> */
    public static function info(string $appRoot): ?array
    {
        $raw = @file_get_contents($appRoot . '/' . self::FILE);
        $info = $raw === false ? null : json_decode($raw, true);
        return is_array($info) ? $info : null;
    }

    public static function off(string $appRoot): void
    {
        $file = $appRoot . '/' . self::FILE;
        if (is_file($file) && !unlink($file)) {
            throw new \RuntimeException("Could not remove $file; delete it to bring the site back.");
        }
    }

    /** The web answer while it's on: JSON for the API, a short page for everything else. */
    public static function respond(string $path): never
    {
        http_response_code(503);
        header('Retry-After: 60');
        header('Cache-Control: no-store');
        if (str_starts_with($path, '/api/') || str_starts_with($path, '/dav')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => ['code' => 'maintenance', 'message' => 'Better-Cal is updating; try again in a minute.']]), "\n";
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta http-equiv="refresh" content="30"><title>Updating</title>'
            . '<div style="font:16px system-ui,sans-serif;padding:24px;max-width:28em">'
            . '<p><strong>Better-Cal is updating.</strong></p><p>It will be back in a minute; this page reloads on its own.</p></div>';
        exit;
    }
}
