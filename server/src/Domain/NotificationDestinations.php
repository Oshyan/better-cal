<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Support\Time;

/** Last authority check before provider handoff, without network-held locks. */
final class NotificationDestinations
{
    public static function push(Db $db, array $expected): ?array
    {
        $fresh = $db->one('SELECT * FROM push_subscriptions WHERE id = ? AND user_id = ?', [(int) $expected['id'], (int) $expected['user_id']]);
        if ($fresh === null) return null;
        foreach (['endpoint', 'endpoint_hash', 'p256dh', 'auth', 'created_by_token_id'] as $key) {
            if (($fresh[$key] ?? null) !== ($expected[$key] ?? null)) return null;
        }
        $token = $fresh['created_by_token_id'] ?? null;
        if ($token !== null && $db->scalar('SELECT 1 FROM api_tokens WHERE id = ? AND user_id = ? AND (expires_at IS NULL OR expires_at > ?)', [(int) $token, (int) $fresh['user_id'], Time::nowDb()]) === null) return null;
        return $fresh;
    }

    public static function email(Db $db, int $userId, string $expected): bool
    {
        if ($expected === '') return false;
        $user = $db->one('SELECT email, settings_json FROM users WHERE id = ?', [$userId]);
        if ($user === null) return false;
        $stored = json_decode((string) ($user['settings_json'] ?? '{}'), true);
        $destination = Settings::notifyDestination(Settings::withDefaults(is_array($stored) ? $stored : []), (string) $user['email'], $db);
        return hash_equals($expected, $destination);
    }
}
