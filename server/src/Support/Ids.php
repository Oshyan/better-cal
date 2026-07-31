<?php

declare(strict_types=1);

namespace BetterCal\Support;

final class Ids
{
    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** 26-char ULID (48-bit ms timestamp + 80 random bits), Crockford base32. */
    public static function ulid(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        $chars = '';
        for ($i = 9; $i >= 0; $i--) {
            $chars .= self::CROCKFORD[($ms >> ($i * 5)) & 31];
        }
        $rand = random_bytes(10);
        $bits = '';
        foreach (str_split($rand) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        for ($i = 0; $i < 16; $i++) {
            $chars .= self::CROCKFORD[bindec(substr($bits, $i * 5, 5))];
        }
        return $chars;
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** 43-char URL-safe feed token. */
    public static function feedToken(): string
    {
        return self::base64url(random_bytes(32));
    }
}
