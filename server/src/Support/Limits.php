<?php

declare(strict_types=1);

namespace BetterCal\Support;

/**
 * Work budgets: how much of anything the app will take in one go.
 *
 * Every entry here bounds input that someone ELSE wrote (a subscribed feed, an
 * emailed invitation, an imported file, a CalDAV client) before it turns into
 * parser memory, database writes or browser work. They are not quotas on the
 * owner; each default is far above what a real calendar produces, and the
 * point is that a hostile or broken input fails cleanly with a message
 * instead of taking the worker, the import or the tab down with it.
 *
 * One place, so an operator can see and tune all of them: each has an env
 * override (BETTERCAL_LIMIT_<NAME>, see .env.example), read by config() and
 * applied here once at startup. Static because the code that needs them is
 * mostly pure (Ics::parse, Filters matching) and has no config to be handed.
 */
final class Limits
{
    private const DEFAULTS = [
        // Text kept per event field. A description longer than this is cut, not
        // refused: a feed with one absurd event should still sync the rest.
        'DESCRIPTION_CHARS' => 65535,
        'URL_CHARS' => 2048,
        // A calendar file someone uploads, and how many events it may hold.
        // Generous on purpose: a long-lived Google Calendar export is tens of
        // megabytes and well over ten thousand events, and importing one is
        // what this app is for. The count is further capped by the memory PHP
        // really has, see importEventBudget().
        'IMPORT_BYTES' => 26214400,      // 25 MiB
        'IMPORT_EVENTS' => 20000,
        // One CalDAV object (one event plus its overrides) from a client.
        'DAV_OBJECT_BYTES' => 1048576,   // 1 MiB
        'DAV_OVERRIDES' => 500,
        // One incoming email, and the parts of it that get read.
        'MAIL_BYTES' => 5242880,         // 5 MiB, the whole message as the server reports it
        'MAIL_ICS_BYTES' => 1048576,     // 1 MiB per calendar part
        'MAIL_ICS_EVENTS' => 200,        // an invitation is one meeting plus its exceptions
        'MAIL_BODY_CHARS' => 524288,     // text/html handed to markup and LLM extraction
        // Regex filter evaluations per request or job, across all filters.
        'REGEX_EVALS' => 20000,
    ];

    /** @var array<string,int> */
    private static array $values = self::DEFAULTS;

    /** @return int the limit, always positive */
    public static function get(string $name): int
    {
        return self::$values[$name] ?? throw new \InvalidArgumentException("unknown limit: $name");
    }

    /**
     * Apply operator overrides. Unknown names and values below 1 are ignored:
     * a typo in .env must not turn a limit into "zero of everything".
     *
     * @param array<string,mixed> $overrides
     */
    public static function configure(array $overrides): void
    {
        foreach ($overrides as $name => $value) {
            if (isset(self::DEFAULTS[$name]) && is_numeric($value) && (int) $value >= 1) {
                self::$values[$name] = (int) $value;
            }
        }
    }

    /** Measured: a parsed event costs about 8 KB of object graph; budget 12 KB for the rows built from it. */
    private const BYTES_PER_PARSED_EVENT = 12288;
    private const MEMORY_HEADROOM = 16777216; // 16 MiB left for everything else in the request

    /**
     * How many events an import may hold HERE: the configured cap, lowered to
     * what fits in this PHP process's memory_limit. Parsing is where an import
     * dies, and an out-of-memory fatal shows the owner a blank error; a number
     * they can act on ("this server can take about 9,000 events at once") is
     * the difference between a limit and a crash. Pure; unit-tested.
     *
     * @param ?string $memoryLimit php.ini memory_limit ("256M", "-1"); null reads it
     * @param ?int $usedBytes memory already in use; null reads it
     */
    public static function importEventBudget(?string $memoryLimit = null, ?int $usedBytes = null): int
    {
        $configured = self::get('IMPORT_EVENTS');
        $limit = self::iniBytes($memoryLimit ?? (string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return $configured; // unlimited (-1), or unreadable: nothing to derive from
        }
        $free = $limit - ($usedBytes ?? memory_get_usage(true)) - self::MEMORY_HEADROOM;
        return max(100, min($configured, intdiv(max(0, $free), self::BYTES_PER_PARSED_EVENT)));
    }

    /** "256M" -> bytes; "-1" and garbage -> 0 (no usable limit). */
    public static function iniBytes(string $value): int
    {
        if (preg_match('/^\s*(\d+)\s*([KMG]?)B?\s*$/i', $value, $m) !== 1) {
            return 0;
        }
        return (int) $m[1] * [1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($m[2]) ?: 0];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::DEFAULTS);
    }

    public static function reset(): void
    {
        self::$values = self::DEFAULTS;
    }
}
