<?php

declare(strict_types=1);

namespace BetterCal\Support;

/**
 * Text patterns shared with the browser (web/src/lib/patterns.js, the one
 * copy): meeting links that get a Join button, and location text that means
 * "the address comes later". Read from that file as JSON, once per request.
 */
final class Patterns
{
    /** @var array{meetings:list<array{name:string,hosts:list<string>,subdomains:bool}>,pendingLocation:list<string>}|null */
    private static ?array $data = null;

    /** @return array{meetings:list<array{name:string,hosts:list<string>,subdomains:bool}>,pendingLocation:list<string>} */
    public static function all(): array
    {
        if (self::$data === null) {
            $text = (string) @file_get_contents(dirname(__DIR__, 3) . '/web/src/lib/patterns.js');
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            $decoded = $start !== false && $end !== false ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
            self::$data = is_array($decoded) ? $decoded + ['meetings' => [], 'pendingLocation' => []] : ['meetings' => [], 'pendingLocation' => []];
        }
        return self::$data;
    }

    /** A shared pattern as a PCRE regex, case-insensitive. */
    public static function regex(string $pattern): string
    {
        return '~' . str_replace('~', '\~', $pattern) . '~i';
    }

    /** The first meeting link in $text: [url, provider name], or null. */
    public static function meetingLink(string $text): ?array
    {
        preg_match_all('~https://[^\s<>"\')]+~i', $text, $matches);
        foreach ($matches[0] ?? [] as $candidate) {
            $url = rtrim((string) $candidate, '.,;:!?]');
            if ($url === '' || strlen($url) > 2048) {
                continue;
            }
            $parts = parse_url($url);
            if (!is_array($parts)
                || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || trim((string) ($parts['host'] ?? '')) === ''
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
                || !isset($parts['path']) || $parts['path'] === '' || $parts['path'] === '/'
            ) {
                continue;
            }
            $host = strtolower((string) $parts['host']);
            if (str_ends_with($host, '.')) {
                continue;
            }
            foreach (self::all()['meetings'] as $m) {
                foreach ((array) ($m['hosts'] ?? []) as $base) {
                    $base = strtolower((string) $base);
                    if ($host === $base || (!empty($m['subdomains']) && str_ends_with($host, '.' . $base))) {
                        return [$url, (string) $m['name']];
                    }
                }
            }
        }
        return null;
    }

    /** Does this location text say the address comes later? */
    public static function isPendingLocation(string $text): bool
    {
        $t = trim($text);
        if ($t === '') {
            return false;
        }
        foreach (self::all()['pendingLocation'] as $p) {
            if (preg_match(self::regex((string) $p), $t) === 1) {
                return true;
            }
        }
        return false;
    }
}
