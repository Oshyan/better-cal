<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\FeedCredentials;
use BetterCal\Support\Time;

/** Owner-reviewed browser provenance; unrelated to API-token ownership. */
final class BrowserSubscriptions
{
    public static function preview(Db $db, int $userId, ?string $keepSession, string $secret): array
    {
        $rows = self::rows($db, $userId, $keepSession);
        $items = [];
        foreach ($rows as $row) {
            try {
                $url = FeedCredentials::openSourceUrl((string) $row['source_url'], $secret);
                $host = parse_url($url, PHP_URL_HOST);
            } catch (\Throwable) {
                $host = null;
            }
            $items[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'host' => is_string($host) ? $host : 'Host unavailable', 'addedAt' => Time::dbToIso((string) $row['created_at'])];
        }
        return ['subscriptions' => $items, 'version' => self::version($rows)];
    }

    /** Caller holds the user lock, shared with subscription creation/claim. */
    public static function decide(Db $db, int $userId, ?string $keepSession, ?array $decision): int
    {
        $rows = self::rows($db, $userId, $keepSession, true);
        if ($rows === [] && $decision === null) return 0;
        if ($decision === null || !is_string($decision['version'] ?? null)
            || !hash_equals(self::version($rows), $decision['version'])
            || !in_array($decision['choice'] ?? null, ['keep', 'pause'], true)) {
            throw HttpError::conflict('subscription_review_changed', 'Review the subscriptions from other browsers again before signing them out. Nothing has changed yet.');
        }
        foreach ($rows as $row) {
            $db->update('calendars', $decision['choice'] === 'pause'
                ? ['subscription_authority' => 'session_review']
                : ['created_by_session_hash' => null], 'id = ?', [(int) $row['id']]);
        }
        return $decision['choice'] === 'pause' ? count($rows) : 0;
    }

    private static function rows(Db $db, int $userId, ?string $keepSession, bool $lock = false): array
    {
        return $db->all("SELECT id, name, source_url, created_at, created_by_session_hash FROM calendars
            WHERE user_id = ? AND kind = 'subscribed' AND COALESCE(provider, 'ics') = 'ics'
              AND subscription_authority = 'owner' AND created_by_session_hash IS NOT NULL
              AND created_by_session_hash <> ? ORDER BY id" . ($lock ? $db->forUpdate() : ''), [$userId, hash('sha256', (string) $keepSession)]);
    }

    private static function version(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
