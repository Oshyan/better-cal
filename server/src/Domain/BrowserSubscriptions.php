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
            $items[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'host' => is_string($host) ? $host : 'Host unavailable', 'addedAt' => Time::dbToIso((string) $row['created_at']), 'fingerprint' => self::fingerprint($row)];
        }
        return ['subscriptions' => $items, 'version' => self::version($rows)];
    }

    /**
     * Apply the owner's reviewed choice, then let the sign-out finish. A
     * mismatch must never refuse: a signed-in thief could rename or add a
     * feed between preview and confirm on every attempt and keep its session
     * forever (review of 0.9.87). So the choice covers only rows the owner
     * saw with the same feed address and creator; anything added or
     * repointed since is paused, the safe side of either choice.
     * Caller holds the user lock, shared with subscription creation/claim.
     *
     * @return array{paused:int,changed:int} changed: paused because they were not reviewed as they now are
     */
    public static function decide(Db $db, int $userId, ?string $keepSession, ?array $decision): array
    {
        $rows = self::rows($db, $userId, $keepSession, true);
        if ($rows === []) return ['paused' => 0, 'changed' => 0];
        $choice = in_array($decision['choice'] ?? null, ['keep', 'pause'], true) ? $decision['choice'] : 'pause';
        $reviewed = is_array($decision['reviewed'] ?? null) ? array_fill_keys(array_filter($decision['reviewed'], 'is_string'), true) : null;
        // A page from before per-row review sends only the version: it then
        // covers all rows or none.
        $whole = $reviewed === null && is_string($decision['version'] ?? null) && hash_equals(self::version($rows), $decision['version']);
        $paused = 0;
        $changed = 0;
        foreach ($rows as $row) {
            $seen = $whole || isset($reviewed[self::fingerprint($row)]);
            if ($seen && $choice === 'keep') {
                $db->update('calendars', ['created_by_session_hash' => null], 'id = ?', [(int) $row['id']]);
                continue;
            }
            $db->update('calendars', ['subscription_authority' => 'session_review'], 'id = ?', [(int) $row['id']]);
            $paused++;
            if (!$seen) $changed++;
        }
        return ['paused' => $paused, 'changed' => $changed];
    }

    /** What the owner's choice is about: which feed, fetched from where, added by which browser. Not its name. */
    private static function fingerprint(array $row): string
    {
        return hash('sha256', json_encode([(int) $row['id'], (string) $row['source_url'], (string) $row['created_by_session_hash']], JSON_THROW_ON_ERROR));
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
