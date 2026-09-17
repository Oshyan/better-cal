<?php

declare(strict_types=1);

namespace BetterCal\Http;

/**
 * Cache policy for the frontend's static files, owned by the app so it is the
 * same on any web server. When every request is routed to index.php these
 * headers are what the browser gets; an operator who lets nginx or Apache serve
 * /assets directly for speed should mirror them (docs/install.md has both).
 *
 * - vendor/ is versioned third-party code that only changes by replacing the
 *   file: cache it for a month, immutable.
 * - everything else (the document, styles, app modules, the manifest, and above
 *   all sw.js) is "no-cache": stored, but revalidated on every use. With the
 *   ETag below that is a 304 with no body, so a deploy shows up on the next
 *   load without anyone bumping a version. A cached sw.js is the failure that
 *   matters most: the browser would never learn a new version exists.
 */
final class StaticFiles
{
    public const VENDOR_MAX_AGE = 2592000; // 30 days

    /** @param string $relative path under web/, e.g. "vendor/preact.module.js" or "sw.js" */
    public static function cacheControl(string $relative): string
    {
        return str_starts_with(ltrim($relative, '/'), 'vendor/')
            ? 'public, max-age=' . self::VENDOR_MAX_AGE . ', immutable'
            : 'no-cache';
    }

    /** Weak validator from size and mtime: cheap, and a deploy changes both. */
    public static function etag(int $size, int $mtime): string
    {
        return 'W/"' . dechex($size) . '-' . dechex($mtime) . '"';
    }

    /** Does the request's If-None-Match (a list, possibly "*") cover this ETag? Weak comparison. */
    public static function matches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || trim($ifNoneMatch) === '') {
            return false;
        }
        $strip = static fn(string $t): string => preg_replace('/^W\//', '', trim($t)) ?? '';
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            if (trim($candidate) === '*' || $strip($candidate) === $strip($etag)) {
                return true;
            }
        }
        return false;
    }
}
