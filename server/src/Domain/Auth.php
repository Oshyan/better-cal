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

    public function __construct(private readonly Db $db, private readonly array $cfg)
    {
    }

    /** @return ?array{token:string,csrf:string,user:array} */
    public function login(string $email, string $password): ?array
    {
        $user = $this->db->one('SELECT * FROM users WHERE email = ?', [trim($email)]);
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
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
    public function setPassword(int $userId, string $password, bool $revokeTokens = false): array
    {
        return $this->db->tx(function () use ($userId, $password, $revokeTokens): array {
            $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
            $sessions = $this->db->run('DELETE FROM sessions WHERE user_id = ?', [$userId])->rowCount();
            $tokens = $revokeTokens
                ? $this->db->run('DELETE FROM api_tokens WHERE user_id = ?', [$userId])->rowCount()
                : 0;
            return ['sessions' => $sessions, 'tokens' => $tokens];
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
