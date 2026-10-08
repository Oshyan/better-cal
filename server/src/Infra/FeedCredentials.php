<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/** Storage boundary for feed URLs and public feed capabilities. */
final class FeedCredentials
{
    public const SOURCE_URL = 'calendar-source-url';
    public const OUTBOUND_TOKEN = 'outbound-feed-token';

    public static function sealSourceUrl(string $url, string $sessionSecret): string
    {
        return Secrets::sealFor(self::SOURCE_URL, $url, $sessionSecret);
    }

    public static function openSourceUrl(string $stored, string $sessionSecret): string
    {
        // Rolling/test compatibility. Migration 045 converts every live row
        // and Undo snapshot; accepting an old value here keeps the short
        // code-before-migration window from interrupting feed refreshes.
        return Secrets::isSealed($stored)
            ? Secrets::openFor(self::SOURCE_URL, $stored, $sessionSecret)
            : $stored;
    }

    /** @return array{hash:string,sealed:string} */
    public static function protectOutboundToken(string $token, string $sessionSecret): array
    {
        return [
            'hash' => hash('sha256', $token),
            'sealed' => Secrets::sealFor(self::OUTBOUND_TOKEN, $token, $sessionSecret),
        ];
    }

    public static function openOutboundToken(string $stored, string $sessionSecret): string
    {
        return Secrets::openFor(self::OUTBOUND_TOKEN, $stored, $sessionSecret);
    }
}
