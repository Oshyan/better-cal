<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

/** Authorization lifetime for recurring inbound ICS subscriptions. */
final class SubscriptionAuthority
{
    public const OWNER = 'owner';
    public const TOKEN = 'token';
    public const LEGACY_REVIEW = 'legacy_review';

    /** @return array{subscription_authority:string,created_by_token_id:?int} */
    public static function creationFields(string $kind, ?string $sourceUrl, ?array $google, ?int $tokenId): array
    {
        $bound = $kind === 'subscribed' && $sourceUrl !== null && $google === null && $tokenId !== null;
        return [
            'subscription_authority' => $bound ? self::TOKEN : self::OWNER,
            'created_by_token_id' => $bound ? $tokenId : null,
        ];
    }

    /** @return ?array{status:string,origin:string,reason:?string} null for non-ICS calendars */
    public static function describe(Db $db, array $calendar, bool $forUpdate = false): ?array
    {
        if ((string) ($calendar['kind'] ?? '') !== 'subscribed' || (string) ($calendar['provider'] ?? 'ics') !== 'ics') {
            return null;
        }

        // Migration 037 gives historical/restored ICS rows the fail-closed
        // legacy_review default. Keep the same result during the brief deploy
        // window after new PHP is copied but before the migration has run.
        $origin = (string) ($calendar['subscription_authority'] ?? self::LEGACY_REVIEW);
        if ($origin === self::OWNER) {
            return ['status' => 'active', 'origin' => self::OWNER, 'reason' => null];
        }
        if ($origin === self::LEGACY_REVIEW) {
            return [
                'status' => 'paused',
                'origin' => self::LEGACY_REVIEW,
                'reason' => 'This subscription predates authorization tracking. Review it and choose Keep active to resume updates.',
            ];
        }
        if ($origin !== self::TOKEN) {
            return [
                'status' => 'paused',
                'origin' => $origin,
                'reason' => 'This subscription has an unknown authorization state and was paused safely.',
            ];
        }

        $tokenId = (int) ($calendar['created_by_token_id'] ?? 0);
        if (array_key_exists('_creator_token_live', $calendar)) {
            $live = (bool) $calendar['_creator_token_live'];
            return $live
                ? ['status' => 'active', 'origin' => self::TOKEN, 'reason' => null]
                : [
                    'status' => 'paused',
                    'origin' => self::TOKEN,
                    'reason' => 'The API key that created this subscription was revoked or expired. Keep it active to make it account-owned.',
                ];
        }
        $lock = $forUpdate && $db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        if ($lock !== '') {
            // Compromise reset locks the user before deleting tokens. Match
            // that order before the post-fetch token lock so recovery cannot
            // deadlock with a feed sync that is about to write user data.
            $db->one('SELECT id FROM users WHERE id = ?' . $lock, [(int) $calendar['user_id']]);
        }
        $live = $tokenId > 0 && $db->scalar(
            'SELECT 1 FROM api_tokens WHERE id = ? AND user_id = ? AND (expires_at IS NULL OR expires_at > ?)' . $lock,
            [$tokenId, (int) $calendar['user_id'], Time::nowDb()]
        ) !== null;
        return $live
            ? ['status' => 'active', 'origin' => self::TOKEN, 'reason' => null]
            : [
                'status' => 'paused',
                'origin' => self::TOKEN,
                'reason' => 'The API key that created this subscription was revoked or expired. Keep it active to make it account-owned.',
            ];
    }

    /** Refuse before an external fetch or sync when the creating authority is no longer live. */
    public static function assertCanPoll(Db $db, array $calendar, bool $forUpdate = false): void
    {
        $state = self::describe($db, $calendar, $forUpdate);
        if ($state !== null && $state['status'] !== 'active') {
            throw HttpError::conflict('subscription_authorization_revoked', (string) $state['reason']);
        }
    }

    /**
     * Hold the user and the creator token across the final calendar reread and
     * sync. Token revocation/reset uses the same order, so neither can commit
     * between the authorization decision and the imported writes.
     */
    public static function lockForPoll(Db $db, array $calendar): void
    {
        if ($db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        $userId = (int) ($calendar['user_id'] ?? 0);
        $db->one('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
        if ((string) ($calendar['subscription_authority'] ?? self::OWNER) === self::TOKEN) {
            $tokenId = (int) ($calendar['created_by_token_id'] ?? 0);
            if ($tokenId > 0) {
                $db->one('SELECT id FROM api_tokens WHERE id = ? AND user_id = ? FOR UPDATE', [$tokenId, $userId]);
            }
        }
    }
}
