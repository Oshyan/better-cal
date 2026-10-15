<?php

declare(strict_types=1);

namespace BetterCal\Http;

/**
 * Better-Cal answers only at its own address (BETTERCAL_BASE_URL). On
 * shared hosting a subdomain is often a folder inside the main site's web
 * root, so the same files can also be reached at example.com/cal/; that
 * second address would put Better-Cal on the main site's origin, where its
 * scripts count as the same site (docs/install.md, Choosing where to put it).
 * A request for any other host is sent to the real address (GET and HEAD)
 * or refused (421).
 *
 * BETTERCAL_ALLOWED_HOSTS adds hosts for setups that need them (a proxy that
 * rewrites Host, a second name). With the base URL left at its default
 * (http://localhost, nothing configured) there is nothing to compare with,
 * so nothing is checked.
 */
final class HostCheck
{
    /**
     * The host name alone, lowercased, without a port or a trailing dot. Ports
     * aren't compared: what this guards against is another name reaching the
     * files, and web servers differ in whether they pass the port on (nginx
     * often hands PHP $host, without it), so comparing ports would lock out
     * an install on a non-standard port.
     */
    public static function normalize(string $host, string $scheme = 'https'): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            return $end === false ? $host : substr($host, 0, $end + 1);
        }
        return rtrim((string) preg_replace('/:\d+$/', '', $host), '.');
    }

    /** @param list<string> $extra */
    public static function allowed(string $baseUrl, string $requestHost, array $extra = []): bool
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            return true;
        }
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $expected = self::normalize($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''), $scheme);
        if ($expected === 'localhost' && rtrim($baseUrl, '/') === 'http://localhost') {
            return true; // not configured
        }
        $got = self::normalize($requestHost, $scheme);
        if ($got === $expected) {
            return true;
        }
        foreach ($extra as $h) {
            if ($got === self::normalize($h, $scheme)) {
                return true;
            }
        }
        return false;
    }

    public static function respond(string $baseUrl, string $method, string $uri): never
    {
        header('Cache-Control: no-store');
        if (in_array($method, ['GET', 'HEAD'], true)) {
            $path = (string) parse_url($uri, PHP_URL_PATH);
            header('Location: ' . rtrim($baseUrl, '/') . ($path !== '' ? $path : '/'), true, 308);
            exit;
        }
        http_response_code(421);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['code' => 'wrong_host', 'message' => 'This Better-Cal answers only at ' . rtrim($baseUrl, '/') . '.']]), "\n";
        exit;
    }
}
