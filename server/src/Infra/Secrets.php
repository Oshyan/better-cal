<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/**
 * Sealed storage for third-party credentials (a Google refresh token is the
 * first). Keyed from the session secret, so a database dump alone does not
 * yield working tokens; losing the session secret loses the tokens, which
 * only means reconnecting the account.
 */
final class Secrets
{
    private const CONTEXT = 'bettercal-sealed-credentials';

    public static function seal(string $plain, string $sessionSecret): string
    {
        $key = self::key($sessionSecret);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = sodium_crypto_secretbox($plain, $nonce, $key);
        return 'v1:' . sodium_bin2base64($nonce . $sealed, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public static function open(string $stored, string $sessionSecret): string
    {
        if (!str_starts_with($stored, 'v1:')) {
            throw new \RuntimeException('Sealed value has an unknown format');
        }
        $raw = sodium_base642bin(substr($stored, 3), SODIUM_BASE64_VARIANT_ORIGINAL);
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($sealed, $nonce, self::key($sessionSecret));
        if ($plain === false) {
            throw new \RuntimeException('Sealed value could not be opened (session secret changed?)');
        }
        return $plain;
    }

    private static function key(string $sessionSecret): string
    {
        if ($sessionSecret === '') {
            throw new \RuntimeException('BETTERCAL_SESSION_SECRET is required to store credentials');
        }
        return sodium_crypto_generichash(self::CONTEXT . '|' . $sessionSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
