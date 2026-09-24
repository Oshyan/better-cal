<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Support\Ids;
use BetterCal\Support\Time;

final class Auth
{
    public const COOKIE = 'bc_session';
    public const TTL_DAYS = 180;
    /** A valid bcrypt hash of nothing in particular, verified against when the account does not exist. */
    public const DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    /**
     * Spend the same time an unknown email would on a real check. The fixed
     * DUMMY_HASH is cost 10 while real hashes are minted at the runtime's
     * default (12 on PHP 8.4), so an unknown address answered about four
     * times faster and the difference named which emails have accounts
     * (scan 2026-09-23, F10/F11). Verifying against the account's own stored
     * hash costs exactly what a real attempt does; the result is discarded.
     */
    public static function burnTime(Db $db, string $password): void
    {
        $hash = null;
        try {
            $hash = $db->scalar('SELECT password_hash FROM users ORDER BY id LIMIT 1');
        } catch (\Throwable) {
        }
        password_verify($password, is_string($hash) && $hash !== '' ? $hash : self::DUMMY_HASH);
    }

    public function __construct(private readonly Db $db, private readonly array $cfg)
    {
    }

    /** @return ?array{token:string,csrf:string,user:array} */
    public function login(string $email, string $password): ?array
    {
        $user = $this->db->one('SELECT * FROM users WHERE email = ?', [trim($email)]);
        if ($user === null) {
            // Spend the same time as a real check, so "no such account" and
            // "wrong password" cannot be told apart by how fast the answer comes.
            self::burnTime($this->db, $password);
            return null;
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            return null;
        }
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        $token = Ids::base64url(random_bytes(32));
        $csrf = bin2hex(random_bytes(16));
        $expires = Time::nowUtc()->add(new \DateInterval('P' . self::TTL_DAYS . 'D'));
        $this->db->insert('sessions', [
            'token_hash' => hash('sha256', $token),
            'user_id' => (int) $user['id'],
            'csrf' => $csrf,
            'expires_at' => Time::toDb($expires),
        ]);
        // Opportunistic cleanup of expired sessions.
        $this->db->run('DELETE FROM sessions WHERE expires_at < ?', [Time::nowDb()]);
        return ['token' => $token, 'csrf' => $csrf, 'user' => $user];
    }

    public function logout(?string $token): void
    {
        if ($token !== null && $token !== '') {
            $this->db->run('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $token)]);
        }
    }

    /**
     * Change a user's password and end every session that was opened under the
     * old one, atomically.
     *
     * A password reset is what an owner does when they suspect someone else is
     * in. Sessions last TTL_DAYS and resolve() only checks hash and expiry, so
     * without this the intruder's cookie keeps working for months after the
     * reset, and a live session can mint itself a fresh API token.
     *
     * API tokens are revoked only on request: they are separately issued
     * credentials (CalDAV clients, the MCP server) and a routine password
     * change should not silently break every device. After a compromise, pass
     * $revokeTokens and re-issue them.
     *
     * @return array{sessions:int,tokens:int} how many of each were revoked
     */
    /**
     * A reset signs every browser out, and with it every push device those
     * browsers registered: a device registered with a stolen session would
     * otherwise keep receiving event details forever (scan 2026-09-23, F5).
     * The owner's own browsers register again on their next sign-in (the
     * client re-sends a subscription it still holds), so nothing is lost.
     *
     * With $revokeTokens (the "I was compromised" switch) every other
     * standing channel goes too: API tokens, public feed URLs (rotated, so
     * the owner's feeds keep their names and scopes but need their new
     * addresses), and a reminder email destination that is not the account
     * address (F6).
     *
     * @return array{sessions:int,tokens:int,pushDevices:int,trustedDevices:int,feedsRotated:int,notifyEmailReset:bool}
     */
    public function setPassword(int $userId, string $password, bool $revokeTokens = false): array
    {
        return $this->db->tx(function () use ($userId, $password, $revokeTokens): array {
            $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
            $sessions = $this->db->run('DELETE FROM sessions WHERE user_id = ?', [$userId])->rowCount();
            $push = $this->db->run('DELETE FROM push_subscriptions WHERE user_id = ?', [$userId])->rowCount();
            // Browsers that signed in under the old password stop vouching
            // through the sign-in brake (issue #59); each earns it again.
            $devices = (new TrustedDevices($this->db))->forgetAll($userId);
            $tokens = 0;
            $feeds = 0;
            $emailReset = false;
            if ($revokeTokens) {
                $tokens = $this->db->run('DELETE FROM api_tokens WHERE user_id = ?', [$userId])->rowCount();
                foreach ($this->db->all('SELECT id FROM out_feeds WHERE user_id = ?', [$userId]) as $f) {
                    $this->db->run('UPDATE out_feeds SET token = ? WHERE id = ?', [Ids::feedToken(), (int) $f['id']]);
                    $feeds++;
                }
                $raw = $this->db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]);
                $s = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($s) && !empty($s['notifyEmail'])) {
                    $s['notifyEmail'] = null;
                    $this->db->run('UPDATE users SET settings_json = ? WHERE id = ?', [json_encode($s), $userId]);
                    $emailReset = true;
                }
            }
            return ['sessions' => $sessions, 'tokens' => $tokens, 'pushDevices' => $push, 'trustedDevices' => $devices, 'feedsRotated' => $feeds, 'notifyEmailReset' => $emailReset];
        });
    }

    /** @return ?array{user:array,csrf:string} */
    public function resolve(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        $row = $this->db->one(
            'SELECT s.csrf, s.last_seen_at, s.token_hash, u.* FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.expires_at > ?',
            [hash('sha256', $token), Time::nowDb()]
        );
        if ($row === null) {
            return null;
        }
        $csrf = (string) $row['csrf'];
        $tokenHash = (string) $row['token_hash'];
        $lastSeen = (string) $row['last_seen_at'];
        unset($row['csrf'], $row['last_seen_at'], $row['token_hash'], $row['password_hash']);
        if (Time::fromDb($lastSeen) < Time::nowUtc()->sub(new \DateInterval('PT1H'))) {
            $this->db->run('UPDATE sessions SET last_seen_at = ? WHERE token_hash = ?', [Time::nowDb(), $tokenHash]);
        }
        return ['user' => $row, 'csrf' => $csrf];
    }

    /** The device cookie (TrustedDevices): a year, sent only to the sign-in endpoints, never to scripts or other sites. */
    public function deviceCookieOptions(): array
    {
        return [
            'expires' => time() + TrustedDevices::TTL_DAYS * 86400,
            'path' => TrustedDevices::PATH,
            'secure' => str_starts_with($this->cfg['base_url'], 'https') || !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }

    public function cookieOptions(bool $clear = false): array
    {
        return [
            'expires' => $clear ? time() - 3600 : time() + self::TTL_DAYS * 86400,
            'path' => '/',
            'secure' => str_starts_with($this->cfg['base_url'], 'https') || !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    public static function serializeUser(array $user): array
    {
        $settings = null;
        if (!empty($user['settings_json'])) {
            $settings = is_array($user['settings_json']) ? $user['settings_json'] : json_decode((string) $user['settings_json'], true);
        }
        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'displayName' => (string) $user['display_name'],
            'settings' => Settings::withDefaults(is_array($settings) ? $settings : []),
        ];
    }
}
