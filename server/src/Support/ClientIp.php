<?php

declare(strict_types=1);

namespace BetterCal\Support;

/**
 * Which address is this request really from, and which bucket does it count
 * against. Pure; unit-tested.
 *
 * The safe default is that the app is NOT behind a proxy: the peer address
 * (REMOTE_ADDR) is the client and X-Forwarded-For is ignored, because anyone
 * can send that header. Trusting it blindly would let an attacker reset their
 * own rate limit with every request, or aim a block at somebody else.
 *
 * Only when the peer is a proxy the operator listed (BETTERCAL_TRUSTED_PROXIES,
 * IPs or CIDRs) is the header read, and then from the RIGHT: the rightmost
 * entry that is not itself a trusted proxy is the address the outermost trusted
 * proxy actually saw. Everything to its left is whatever the client claimed.
 */
final class ClientIp
{
    /**
     * @param array<string,mixed> $server  $_SERVER
     * @param list<string> $trustedProxies IPs or CIDR ranges
     */
    public static function resolve(array $server, array $trustedProxies = []): string
    {
        $peer = self::normalize((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($peer === '' || $trustedProxies === [] || !self::inAny($peer, $trustedProxies)) {
            return $peer !== '' ? $peer : 'unknown';
        }
        $hops = array_reverse(array_map('trim', explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($hops as $hop) {
            $ip = self::normalize($hop);
            if ($ip === '') {
                break; // garbage in the chain: nothing to its left can be believed either
            }
            if (!self::inAny($ip, $trustedProxies)) {
                return $ip;
            }
        }
        return $peer;
    }

    /**
     * The unit a limit applies to. One IPv4 address is one source; an IPv6
     * source is its /64, since a single host is routinely handed a whole /64
     * and could otherwise walk through 2^64 "different" addresses.
     */
    public static function bucket(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) === 4) {
            return $ip;
        }
        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** Strip a port or brackets and validate; '' when it is not an IP. IPv4-mapped IPv6 becomes IPv4. */
    public static function normalize(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $raw, $m) === 1) {
            $raw = $m[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $raw, $m) === 1) {
            $raw = $m[1];
        }
        if (filter_var($raw, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        if (stripos($raw, '::ffff:') === 0 && filter_var(substr($raw, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return substr($raw, 7);
        }
        return strtolower($raw);
    }

    /** @param list<string> $ranges */
    public static function inAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($ip, trim((string) $range))) {
                return true;
            }
        }
        return false;
    }

    public static function inRange(string $ip, string $range): bool
    {
        if ($range === '') {
            return false;
        }
        [$net, $bits] = array_pad(explode('/', $range, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton(self::normalize((string) $net) ?: (string) $net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $max = strlen($ipBin) * 8;
        if ($bits !== null && !ctype_digit($bits)) {
            return false; // "10.0.0.0/abc" allows nothing, rather than everything
        }
        $bits = $bits === null ? $max : (int) $bits;
        if ($bits > $max) {
            return false;
        }
        $whole = intdiv($bits, 8);
        if (substr($ipBin, 0, $whole) !== substr($netBin, 0, $whole)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($ipBin[$whole]) & $mask) === (ord($netBin[$whole]) & $mask);
    }
}
