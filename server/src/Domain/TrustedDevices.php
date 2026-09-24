<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Support\Ids;
use BetterCal\Support\Time;

/**
 * Device cookies (issue #59; OWASP "Slow Down Online Guessing Attacks with
 * Device Cookies"). A browser that signs in with the right password gets a
 * long-lived random cookie. While the overall sign-in brake is on, the one
 * that refuses addresses it has not seen succeed lately, LoginGuard lets a
 * sign-in carrying a valid cookie through as if its address were known. That
 * is all it does: the password is still checked, the per-address limit still
 * applies, and wrong passwords sent with a cookie count against that cookie
 * until it stops vouching (LoginGuard::DEVICE_MAX).
 *
 * The cookie is not a session. It never signs anyone in, it survives signing
 * out (a signed-out laptop in a café is exactly the case it exists for), and
 * it is only ever sent to the sign-in endpoints (PATH).
 */
final class TrustedDevices
{
    public const COOKIE = 'bc_device';
    public const PATH = '/api/v1/auth';
    public const TTL_DAYS = 365;
    // Newest kept per account. Every sign-in replaces its own browser's row,
    // so this only bounds browsers that were cleared or thrown away.
    public const MAX_PER_USER = 20;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The device this cookie names, for the account being signed in to, or
     * null. Checked before the password, so it must cost one indexed lookup
     * and say nothing to the caller either way.
     */
    public function find(?string $token, string $email, ?\DateTimeImmutable $now = null): ?int
    {
        if ($token === null || preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        try {
            $id = $this->db->scalar(
                'SELECT d.id FROM trusted_devices d JOIN users u ON u.id = d.user_id
                 WHERE d.token_hash = ? AND u.email = ? AND d.expires_at > ?',
                [hash('sha256', $token), trim($email), Time::toDb($now ?? Time::nowUtc())]
            );
        } catch (\PDOException $e) {
            // Before migration 030 has run: no device vouches, which is how
            // things were before this existed. Never a reason to refuse.
            return null;
        }
        return $id === null ? null : (int) $id;
    }

    /**
     * A successful password sign-in: issue a fresh cookie value for this
     * browser, replacing the one it presented (so a copied cookie stops
     * working the next time the owner signs in). Returns the new value.
     */
    public function remember(int $userId, ?int $replaceId = null, ?\DateTimeImmutable $now = null): ?string
    {
        $now ??= Time::nowUtc();
        $token = Ids::base64url(random_bytes(32));
        try {
            $this->db->tx(function () use ($userId, $replaceId, $now, $token): void {
                if ($replaceId !== null) {
                    $this->db->run('DELETE FROM trusted_devices WHERE id = ? AND user_id = ?', [$replaceId, $userId]);
                }
                $this->db->insert('trusted_devices', [
                    'user_id' => $userId,
                    'token_hash' => hash('sha256', $token),
                    'created_at' => Time::toDb($now),
                    'expires_at' => Time::toDb($now->add(new \DateInterval('P' . self::TTL_DAYS . 'D'))),
                ]);
                $this->db->run('DELETE FROM trusted_devices WHERE expires_at <= ?', [Time::toDb($now)]);
                $ids = array_map('intval', array_column(
                    $this->db->all('SELECT id FROM trusted_devices WHERE user_id = ? ORDER BY id DESC', [$userId]),
                    'id'
                ));
                foreach (array_slice($ids, self::MAX_PER_USER) as $old) {
                    $this->db->run('DELETE FROM trusted_devices WHERE id = ?', [$old]);
                }
            });
        } catch (\PDOException $e) {
            error_log('trusted devices: could not remember this browser: ' . $e->getMessage());
            return null; // sign-in still succeeds; this browser just isn't vouched for
        }
        return $token;
    }

    /** Forget every browser of this account (a password reset). Returns how many. */
    public function forgetAll(int $userId): int
    {
        try {
            return $this->db->run('DELETE FROM trusted_devices WHERE user_id = ?', [$userId])->rowCount();
        } catch (\PDOException $e) {
            return 0;
        }
    }
}
