<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\FeedCredentials;
use BetterCal\Support\Ids;
use BetterCal\Support\Time;

final class Auth
{
    public const COOKIE = 'bc_session';
    public const TTL_DAYS = 180;
    public const STEP_UP_SECONDS = 600;
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
            'authenticated_at' => Time::nowDb(),
            'expires_at' => Time::toDb($expires),
        ]);
        // Opportunistic cleanup of expired sessions.
        $this->db->run('DELETE FROM sessions WHERE expires_at < ?', [Time::nowDb()]);
        return ['token' => $token, 'csrf' => $csrf, 'user' => $user];
    }

    /**
     * End one browser session and, when supplied, stop reminders to that
     * browser's current push endpoint in the same transaction. The push row
     * is deliberately deleted without a push_removed tombstone: the browser
     * keeps its PushManager subscription and may quietly register it again
     * after the owner signs back in.
     *
     * @return int number of current-device push rows removed (zero or one)
     */
    public function logout(?string $token, ?string $pushEndpointHash = null): int
    {
        if ($token === null || $token === '') {
            return 0;
        }
        if ($pushEndpointHash !== null && preg_match('/^[0-9a-f]{64}$/', $pushEndpointHash) !== 1) {
            throw HttpError::badRequest('pushEndpointHash must be a lowercase SHA-256 value', 'invalid_push_endpoint_hash');
        }

        $tokenHash = hash('sha256', $token);
        return $this->db->tx(function () use ($tokenHash, $pushEndpointHash): int {
            $lock = $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $session = $this->db->one('SELECT user_id FROM sessions WHERE token_hash = ?' . $lock, [$tokenHash]);
            if ($session === null) {
                return 0; // Already signed out (or reset) is an idempotent success.
            }
            $userId = (int) $session['user_id'];
            $push = $pushEndpointHash === null ? 0 : $this->db->run(
                'DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?',
                [$userId, $pushEndpointHash]
            )->rowCount();
            $this->db->run('DELETE FROM sessions WHERE token_hash = ? AND user_id = ?', [$tokenHash, $userId]);
            return $push;
        });
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
     * @return array{sessions:int,tokens:int,pushDevices:int,trustedDevices:int,feedsRotated:int,notifyEmailReset:bool,subscriptionsPaused:int,googleAccounts:int,googleMoves:int}
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
            $subscriptionsPaused = 0;
            $googleAccounts = 0;
            $googleMoves = 0;
            if ($revokeTokens) {
                $subscriptionsPaused = (int) $this->db->scalar(
                    "SELECT COUNT(*) FROM calendars c JOIN api_tokens t ON t.id = c.created_by_token_id
                     WHERE c.user_id = ? AND t.user_id = ? AND c.kind = 'subscribed'
                       AND COALESCE(c.provider, 'ics') = 'ics' AND c.subscription_authority = 'token'",
                    [$userId, $userId]
                );
                $tokens = $this->db->run('DELETE FROM api_tokens WHERE user_id = ?', [$userId])->rowCount();
                foreach ($this->db->all('SELECT id FROM out_feeds WHERE user_id = ?', [$userId]) as $f) {
                    $protected = FeedCredentials::protectOutboundToken(
                        Ids::feedToken(),
                        (string) ($this->cfg['session_secret'] ?? '')
                    );
                    $this->db->run(
                        'UPDATE out_feeds SET token = ?, token_sealed = ? WHERE id = ?',
                        [$protected['hash'], $protected['sealed'], (int) $f['id']]
                    );
                    $feeds++;
                }
                $raw = $this->db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]);
                $s = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($s) && !empty($s['notifyEmail'])) {
                    $s['notifyEmail'] = null;
                    $s['notifyEmailToken'] = null;
                    $this->db->run('UPDATE users SET settings_json = ? WHERE id = ?', [json_encode($s), $userId]);
                    $emailReset = true;
                }

                // A Google refresh token is another standing credential, and
                // a move already queued can keep exporting after every browser
                // session is gone. Quarantine locally in this transaction;
                // seed.php asks Google to revoke the tokens only after commit.
                $now = Time::nowDb();
                if ($this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
                    // Every move path takes calendar before move. Lock every
                    // affected calendar in stable order before cancelling its
                    // move so reset cannot deadlock a final cutover.
                    $this->db->all(
                        'SELECT id FROM calendars WHERE user_id = ? ORDER BY id FOR UPDATE',
                        [$userId]
                    );
                }
                $googleAccounts = (int) $this->db->scalar('SELECT COUNT(*) FROM google_accounts WHERE user_id = ?', [$userId]);
                $this->db->run(
                    "UPDATE google_accounts
                     SET reauth_required_at = ?, status = 'error', last_error = ?
                     WHERE user_id = ?",
                    [$now, 'Paused by the account compromise reset; reconnect this Google account to resume.', $userId]
                );
                // Calendar rows were locked first, matching move starts,
                // snapshot writers and worker finalization.
                $googleMoves = $this->db->run(
                    "UPDATE calendar_moves
                     SET status = 'failed', cancelled_at = ?, finished_at = ?, error = ?
                     WHERE user_id = ? AND status IN ('queued', 'running', 'failed') AND cancelled_at IS NULL",
                    [$now, $now, 'Stopped by the account compromise reset. Events already uploaded to Google may remain there; start a new move after reconnecting.', $userId]
                )->rowCount();
                // Keep cached events and account/calendar identities, but make
                // the calendars visibly read-only until a successful poll after
                // reconnect learns their access role again.
                $this->db->run(
                    "UPDATE calendars
                     SET google_access_role = NULL, last_poll_status = 'error', last_poll_error = ?
                     WHERE user_id = ? AND provider = 'google'",
                    ['Google access was paused by the account compromise reset; reconnect under Settings, Connections.', $userId]
                );
            }
            return ['sessions' => $sessions, 'tokens' => $tokens, 'pushDevices' => $push, 'trustedDevices' => $devices, 'feedsRotated' => $feeds, 'notifyEmailReset' => $emailReset, 'subscriptionsPaused' => $subscriptionsPaused, 'googleAccounts' => $googleAccounts, 'googleMoves' => $googleMoves];
        });
    }

    /** Verify the current user's password and refresh only this browser session's step-up time. */
    public function confirmPassword(int $userId, ?string $token, string $password): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        $hash = $this->db->scalar('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if (!is_string($hash) || !password_verify($password, $hash)) {
            return false;
        }
        $tokenHash = hash('sha256', $token);
        $session = $this->db->one(
            'SELECT token_hash FROM sessions WHERE token_hash = ? AND user_id = ? AND expires_at > ?',
            [$tokenHash, $userId, Time::nowDb()]
        );
        if ($session === null) {
            return false;
        }
        $this->db->run('UPDATE sessions SET authenticated_at = ? WHERE token_hash = ?', [Time::nowDb(), $tokenHash]);
        return true;
    }

    /**
     * Recheck the exact browser session at a durable transaction boundary.
     * Lock the user first, matching setPassword's user -> session order; the
     * later Google account/calendar/move writes may take user FK locks too.
     *
     * @return array{token_hash:string,authenticated_at:?string}
     */
    public static function assertSession(Db $db, int $userId, ?string $token, bool $forUpdate = false): array
    {
        if ($token === null || $token === '') {
            throw HttpError::conflict('session_revoked', 'This browser session ended before the sensitive change could be saved; sign in and try again.');
        }
        $lock = $forUpdate && $db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $user = $db->one('SELECT id FROM users WHERE id = ?' . $lock, [$userId]);
        if ($user === null) {
            throw HttpError::conflict('session_revoked', 'This browser session ended before the sensitive change could be saved; sign in and try again.');
        }
        $session = $db->one(
            'SELECT token_hash, authenticated_at FROM sessions WHERE token_hash = ? AND user_id = ? AND expires_at > ?' . $lock,
            [hash('sha256', $token), $userId, Time::nowDb()]
        );
        if ($session === null) {
            throw HttpError::conflict('session_revoked', 'This browser session ended before the sensitive change could be saved; sign in and try again.');
        }
        return $session;
    }

    /**
     * The live session above must also have seen the password recently. The
     * controller-level gate makes the normal case friendly; this locked check
     * closes reset/reconnect races before the durable Google action is saved.
     *
     * @return array{token_hash:string,authenticated_at:?string}
     */
    public static function assertRecentSession(Db $db, int $userId, ?string $token, bool $forUpdate = false): array
    {
        $session = self::assertSession($db, $userId, $token, $forUpdate);
        try {
            $fresh = isset($session['authenticated_at'])
                && $session['authenticated_at'] !== null
                && Time::fromDb((string) $session['authenticated_at']) >= Time::nowUtc()->sub(new \DateInterval('PT' . self::STEP_UP_SECONDS . 'S'));
        } catch (\Throwable) {
            $fresh = false;
        }
        if (!$fresh) {
            throw HttpError::forbidden('step_up_required', 'Confirm your Better-Cal password and try the sensitive change again.');
        }
        return $session;
    }

    /** How many browsers other than the one holding $token are signed in to this account. */
    public function otherSessionCount(int $userId, ?string $token): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM sessions WHERE user_id = ? AND token_hash <> ? AND expires_at > ?',
            [$userId, hash('sha256', (string) $token), Time::nowDb()]
        );
    }

    /**
     * "Sign out everywhere else", the owner's answer to a lost or stolen
     * device when they still have one that works. Everything a device keeps
     * without the password goes, except for the browser asking:
     *
     * - every other session, so nothing else stays signed in;
     * - every other remembered browser (TrustedDevices), so none of them
     *   vouches through the sign-in brake any more;
     * - every other push device, so reminders (event titles, places) stop
     *   reaching the lost one. They are not marked removed: the owner's
     *   other browsers register again when they next sign in, exactly as
     *   after a password reset, and the lost one cannot without signing in.
     *
     * API tokens and the channels they created are separate credentials the
     * owner manages one by one on the same page, so they are left alone.
     * Session-created public feeds and a custom reminder destination are not:
     * they could have been left by the lost browser, and are rotated/reset.
     * Written to Activity.
     *
     * @return array{sessions:int,devices:int,pushDevices:int,feedsRotated:int,notifyEmailReset:bool}
     */
    public function signOutOthers(int $userId, ?string $keepToken, ?int $keepDevice, ?string $keepPushHash): array
    {
        $out = $this->db->tx(function () use ($userId, $keepToken, $keepDevice, $keepPushHash): array {
            // Serialize recovery with session-created channel writes. If a
            // password reset or earlier recovery already ended this browser,
            // it must not perform another partially authorized cleanup.
            self::assertSession($this->db, $userId, $keepToken, true);
            $sessions = $this->db->run(
                'DELETE FROM sessions WHERE user_id = ? AND token_hash <> ?',
                [$userId, hash('sha256', (string) $keepToken)]
            )->rowCount();
            $devices = (new TrustedDevices($this->db))->forgetAllExcept($userId, $keepDevice);
            $push = $keepPushHash === null
                ? $this->db->run('DELETE FROM push_subscriptions WHERE user_id = ?', [$userId])->rowCount()
                : $this->db->run('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash <> ?', [$userId, $keepPushHash])->rowCount();
            $feeds = 0;
            foreach ($this->db->all('SELECT id FROM out_feeds WHERE user_id = ? AND created_by_token_id IS NULL', [$userId]) as $feed) {
                $protected = FeedCredentials::protectOutboundToken(
                    Ids::feedToken(),
                    (string) ($this->cfg['session_secret'] ?? '')
                );
                $this->db->run(
                    'UPDATE out_feeds SET token = ?, token_sealed = ? WHERE id = ?',
                    [$protected['hash'], $protected['sealed'], (int) $feed['id']]
                );
                $feeds++;
            }
            $emailReset = false;
            $raw = $this->db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]);
            $settings = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($settings) && !empty($settings['notifyEmail']) && empty($settings['notifyEmailToken'])) {
                $settings['notifyEmail'] = null;
                $settings['notifyEmailToken'] = null;
                $this->db->run('UPDATE users SET settings_json = ? WHERE id = ?', [json_encode($settings), $userId]);
                $emailReset = true;
            }
            return ['sessions' => $sessions, 'devices' => $devices, 'pushDevices' => $push, 'feedsRotated' => $feeds, 'notifyEmailReset' => $emailReset];
        });
        $parts = [$out['sessions'] . ' other browser' . ($out['sessions'] === 1 ? '' : 's') . ' signed out'];
        if ($out['pushDevices'] > 0) {
            $parts[] = $out['pushDevices'] . ' push device' . ($out['pushDevices'] === 1 ? '' : 's') . ' removed';
        }
        if ($out['feedsRotated'] > 0) {
            $parts[] = $out['feedsRotated'] . ' public feed URL' . ($out['feedsRotated'] === 1 ? '' : 's') . ' changed';
        }
        if ($out['notifyEmailReset']) {
            $parts[] = 'reminder email reset to the account address';
        }
        (new Undo($this->db))->record($userId, 'system', 0, 'delete', null, null,
            'Signed out everywhere else: ' . implode(', ', $parts), $out);
        return $out;
    }

    /** @return ?array{user:array,csrf:string,authenticatedAt:?string,cacheId:string,offlineUntil:int} */
    public function resolve(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        $row = $this->db->one(
            'SELECT s.csrf, s.last_seen_at, s.expires_at AS session_expires_at, s.authenticated_at AS session_authenticated_at, s.token_hash, u.* FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.expires_at > ?',
            [hash('sha256', $token), Time::nowDb()]
        );
        if ($row === null) {
            return null;
        }
        $csrf = (string) $row['csrf'];
        $tokenHash = (string) $row['token_hash'];
        $lastSeen = (string) $row['last_seen_at'];
        $expiresAt = Time::fromDb((string) $row['session_expires_at']);
        $authenticatedAt = isset($row['session_authenticated_at']) && $row['session_authenticated_at'] !== null
            ? (string) $row['session_authenticated_at']
            : null;
        unset($row['csrf'], $row['last_seen_at'], $row['session_expires_at'], $row['session_authenticated_at'], $row['token_hash'], $row['password_hash']);
        if (Time::fromDb($lastSeen) < Time::nowUtc()->sub(new \DateInterval('PT1H'))) {
            $this->db->run('UPDATE sessions SET last_seen_at = ? WHERE token_hash = ?', [Time::nowDb(), $tokenHash]);
        }
        return [
            'user' => $row,
            'csrf' => $csrf,
            'authenticatedAt' => $authenticatedAt,
            // Opaque, per-login cache scope. It changes whenever a new
            // session is issued without exposing the bearer cookie itself.
            'cacheId' => hash('sha256', 'better-cal/offline/v1|' . $tokenHash),
            // Offline data is deliberately shorter-lived than the server
            // session. The worker also refuses it after the real session end.
            'offlineUntil' => min($expiresAt->getTimestamp(), time() + 86400),
        ];
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
