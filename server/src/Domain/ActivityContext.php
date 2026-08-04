<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/**
 * Request-scoped source tag for the activity log. Entry points set it once
 * (API bearer auth -> 'api', the DAV gateway -> 'caldav', the mail worker
 * per tier -> 'mail:imip' etc.); every Undo::record picks it up so domain
 * code never threads a source parameter through its call stack.
 *
 * Manual-ish sources: web, caldav, quickadd. Automated: api (agent), feed,
 * mail:*, import. The UI groups them; the log stores the specific value.
 */
final class ActivityContext
{
    private static string $source = 'web';

    public static function set(string $source): void
    {
        self::$source = $source;
    }

    public static function get(): string
    {
        return self::$source;
    }

    /** Run $fn under a temporary source, restoring the previous one after. */
    public static function with(string $source, callable $fn): mixed
    {
        $prev = self::$source;
        self::$source = $source;
        try {
            return $fn();
        } finally {
            self::$source = $prev;
        }
    }
}
