<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\HttpClient;
use BetterCal\Infra\Secrets;
use BetterCal\Support\Time;

/**
 * Google account connection: OAuth consent, token storage, token refresh,
 * and the calendar listing (docs/google-calendar.md, GH #42).
 *
 * Scope: read everything, write events, and (0.9.4, #55) create calendars
 * of its own: calendar.app.created lets Better-Cal make a secondary calendar
 * and manage events on the calendars it made, and nothing else, which is how
 * a local calendar is moved to Google. Accounts connected before 0.9.4 lack
 * it until they reconnect once (needsReconnectToCreate). The refresh token
 * is the only thing kept, sealed with the session secret; access tokens are
 * minted per run and never stored.
 */
final class GoogleAuth
{
    public const SCOPES = 'openid email https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.app.created';
    public const CREATE_SCOPE = 'https://www.googleapis.com/auth/calendar.app.created';
    private const CALENDARS_URL = 'https://www.googleapis.com/calendar/v3/calendars';
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const CALENDAR_LIST_URL = 'https://www.googleapis.com/calendar/v3/users/me/calendarList';
    private const STATE_TTL = 600;

    public function __construct(private readonly Db $db, private readonly array $cfg)
    {
    }

    public function configured(): bool
    {
        return ($this->cfg['google']['client_id'] ?? '') !== '' && ($this->cfg['google']['client_secret'] ?? '') !== '';
    }

    public function redirectUri(): string
    {
        return rtrim((string) $this->cfg['base_url'], '/') . '/api/v1/google/callback';
    }

    // ---- Consent -----------------------------------------------------------

    /** Where to send the browser. The state ties the callback to this user and session. */
    public function authUrl(int $userId, string $sessionToken): string
    {
        $this->requireConfigured();
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->cfg['google']['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            // offline + consent: the only way Google hands out a refresh token
            // every time, not just the first.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $this->signState($userId, $sessionToken),
        ]);
    }

    /** Stateless CSRF binding: user id, expiry and the initiating browser session. */
    public function signState(int $userId, string $sessionToken, ?int $now = null): string
    {
        $exp = ($now ?? time()) + self::STATE_TTL;
        $nonce = bin2hex(random_bytes(8));
        $payload = $userId . '.' . $exp . '.' . $nonce;
        return $payload . '.' . hash_hmac('sha256', $payload, $this->stateKey($sessionToken));
    }

    public function verifyState(string $state, int $userId, string $sessionToken, ?int $now = null): bool
    {
        $parts = explode('.', $state);
        if (count($parts) !== 4) {
            return false;
        }
        [$uid, $exp, $nonce, $mac] = $parts;
        $payload = $uid . '.' . $exp . '.' . $nonce;
        if ($sessionToken === '' || !hash_equals(hash_hmac('sha256', $payload, $this->stateKey($sessionToken)), $mac)) {
            return false;
        }
        return (int) $uid === $userId && (int) $exp >= ($now ?? time());
    }

    /**
     * Finish the consent: exchange the code, learn the account's email, seal
     * the refresh token. Reconnecting the same account replaces its token.
     *
     * @return array the account row
     */
    public function connect(int $userId, string $code, string $sessionToken): array
    {
        $this->requireConfigured();
        $token = $this->tokenRequest([
            'code' => $code,
            'client_id' => $this->cfg['google']['client_id'],
            'client_secret' => $this->cfg['google']['client_secret'],
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
        $refresh = (string) ($token['refresh_token'] ?? '');
        if ($refresh === '') {
            throw new \RuntimeException('Google did not return a refresh token; remove Better-Cal under Google Account, Third-party access, and connect again');
        }
        $access = (string) ($token['access_token'] ?? '');
        $info = $this->http()->getJson(self::USERINFO_URL, ['Authorization: Bearer ' . $access]);
        $email = strtolower(trim((string) ($info['email'] ?? '')));
        if ($email === '') {
            throw new \RuntimeException('Google did not return the account email');
        }
        $sealed = Secrets::seal($refresh, (string) $this->cfg['session_secret']);
        $scopes = (string) ($token['scope'] ?? self::SCOPES);
        return $this->storeConnection($userId, $sessionToken, $email, $sealed, $scopes);
    }

    /** Revoke at Google (best effort) and forget the token. Calendars stay; their next poll says why it failed. */
    public function disconnect(int $userId, int $accountId): void
    {
        $account = $this->account($userId, $accountId);
        try {
            $refresh = Secrets::open((string) $account['refresh_token_enc'], (string) $this->cfg['session_secret']);
            $this->http()->postForm(self::REVOKE_URL, ['token' => $refresh]);
        } catch (\Throwable) {
            // Already revoked, or unreachable: the row goes regardless.
        }
        $this->db->run('DELETE FROM google_accounts WHERE id = ?', [(int) $account['id']]);
    }

    // ---- Tokens -----------------------------------------------------------

    /** A fresh access token for this account (one refresh grant per call). */
    public function accessToken(array $account): string
    {
        $this->requireConfigured();
        // Re-read instead of trusting a worker/controller snapshot loaded
        // before a compromise reset committed.
        $account = $this->assertUsable($account);
        $refresh = Secrets::open((string) $account['refresh_token_enc'], (string) $this->cfg['session_secret']);
        try {
            $token = $this->tokenRequest([
                'refresh_token' => $refresh,
                'client_id' => $this->cfg['google']['client_id'],
                'client_secret' => $this->cfg['google']['client_secret'],
                'grant_type' => 'refresh_token',
            ]);
        } catch (\RuntimeException $e) {
            $this->db->update('google_accounts', ['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 2000)], 'id = ?', [(int) $account['id']]);
            throw $e;
        }
        // The reset may have committed while Google's token endpoint was in
        // flight. Do not hand the resulting access token to a later API call.
        $account = $this->assertUsable($account);
        if (($account['status'] ?? 'ok') !== 'ok') {
            $this->db->update('google_accounts', ['status' => 'ok', 'last_error' => null], 'id = ?', [(int) $account['id']]);
        }
        return (string) $token['access_token'];
    }

    /** Reload and reject the distinct compromise-quarantine state before any Google request. */
    public function assertUsable(array $account, bool $forUpdate = false): array
    {
        $id = (int) ($account['id'] ?? 0);
        $lock = $forUpdate && $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $fresh = $id > 0 ? $this->db->one('SELECT * FROM google_accounts WHERE id = ?' . $lock, [$id]) : null;
        if ($fresh === null) {
            throw HttpError::conflict('google_disconnected', 'The Google account is disconnected; reconnect it under Settings, Connections.');
        }
        if (($fresh['reauth_required_at'] ?? null) !== null) {
            throw HttpError::conflict('google_reconnect_needed', 'Google access was paused by the account compromise reset; reconnect this account under Settings, Connections.');
        }
        return $fresh;
    }

    /** Best-effort remote cleanup after local compromise quarantine has committed. */
    public function revokeQuarantinedTokens(int $userId): array
    {
        $attempted = 0;
        $confirmed = 0;
        foreach ($this->db->all('SELECT * FROM google_accounts WHERE user_id = ? AND reauth_required_at IS NOT NULL', [$userId]) as $account) {
            $attempted++;
            try {
                $refresh = Secrets::open((string) $account['refresh_token_enc'], (string) $this->cfg['session_secret']);
                $r = $this->http()->postForm(self::REVOKE_URL, ['token' => $refresh]);
                if ($r['status'] >= 200 && $r['status'] < 300) {
                    $confirmed++;
                }
            } catch (\Throwable) {
                // Local quarantine is already authoritative. Never roll it back
                // because Google or the network was unavailable.
            }
        }
        return ['attempted' => $attempted, 'confirmed' => $confirmed];
    }

    // ---- Reading ------------------------------------------------------------

    /** @return list<array> */
    public function accounts(int $userId): array
    {
        return $this->db->all('SELECT * FROM google_accounts WHERE user_id = ? ORDER BY id', [$userId]);
    }

    public function account(int $userId, int $accountId): array
    {
        $row = $this->db->one('SELECT * FROM google_accounts WHERE id = ? AND user_id = ?', [$accountId, $userId]);
        if ($row === null) {
            throw HttpError::notFound('Google account not found');
        }
        return $row;
    }

    /**
     * The account's calendar list as Google sees it: everything the user
     * owns, subscribes to, or has been shared, including the shared-but-not-
     * public ones no ICS address can reach.
     *
     * Each entry carries a kind, which Google does not state but the calendar
     * id encodes: yours (primary, or a secondary you own), shared (someone
     * else's, shared with you), feed (Google's own copy of an ICS
     * subscription, always behind the source), google (holidays, birthdays).
     *
     * @return list<array{id:string,name:string,accessRole:string,primary:bool,color:?string,kind:string}>
     */
    public function listCalendars(array $account): array
    {
        $access = $this->accessToken($account);
        $out = [];
        $pageToken = null;
        do {
            // A reset may land after accessToken() but before or between
            // pages. At most the request already in flight may finish.
            $this->assertUsable($account);
            $url = self::CALENDAR_LIST_URL . '?' . http_build_query(array_filter([
                'minAccessRole' => 'reader', 'showHidden' => 'true', 'maxResults' => '250', 'pageToken' => $pageToken,
            ]));
            $data = $this->http()->getJson($url, ['Authorization: Bearer ' . $access]);
            foreach ($data['items'] ?? [] as $item) {
                $out[] = [
                    'id' => (string) $item['id'],
                    'name' => (string) ($item['summaryOverride'] ?? $item['summary'] ?? $item['id']),
                    'accessRole' => (string) ($item['accessRole'] ?? 'reader'),
                    'primary' => !empty($item['primary']),
                    'color' => isset($item['backgroundColor']) ? (string) $item['backgroundColor'] : null,
                    'kind' => self::calendarKind((string) $item['id'], (string) ($item['accessRole'] ?? 'reader'), !empty($item['primary'])),
                ];
            }
            $pageToken = isset($data['nextPageToken']) ? (string) $data['nextPageToken'] : null;
        } while ($pageToken !== null);
        $order = ['yours' => 0, 'shared' => 1, 'feed' => 2, 'google' => 3];
        usort($out, static fn(array $a, array $b): int => [$order[$a['kind']], $b['primary'], strtolower($a['name'])] <=> [$order[$b['kind']], $a['primary'], strtolower($b['name'])]);
        return $out;
    }

    /** May this account create calendars at Google? Pure; unit-tested. */
    public static function canCreateCalendars(array $account): bool
    {
        $granted = preg_split('/\s+/', trim((string) ($account['scopes'] ?? ''))) ?: [];
        return in_array(self::CREATE_SCOPE, $granted, true) || in_array('https://www.googleapis.com/auth/calendar', $granted, true);
    }

    /**
     * Create a secondary calendar owned by this account; returns its id.
     * Needs calendar.app.created (canCreateCalendars).
     */
    public function createCalendar(array $account, string $name, string $tzid): string
    {
        $access = $this->accessToken($account);
        $r = $this->http()->json('POST', self::CALENDARS_URL, ['summary' => $name, 'timeZone' => $tzid], ['Authorization: Bearer ' . $access]);
        $data = json_decode($r['body'], true);
        if ($r['status'] < 200 || $r['status'] >= 300 || !is_array($data) || empty($data['id'])) {
            $why = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            throw new \RuntimeException('Google would not create the calendar: HTTP ' . $r['status'] . ($why !== '' ? " ($why)" : ''));
        }
        return (string) $data['id'];
    }

    /** Pure; unit-tested. See listCalendars() for what each kind means. */
    public static function calendarKind(string $id, string $accessRole, bool $primary): string
    {
        if ($primary) {
            return 'yours';
        }
        if (str_ends_with($id, '@import.calendar.google.com')) {
            return 'feed';
        }
        if (str_ends_with($id, '@group.v.calendar.google.com')) {
            return 'google';
        }
        return $accessRole === 'owner' ? 'yours' : 'shared';
    }

    /**
     * Pure. The role a newly added Google calendar starts with: your own
     * calendars are things you do, Google's holidays and birthdays are
     * information, and anything shared with you or copied from a feed is an
     * opportunity, as an ICS subscription is. Changeable in its settings.
     */
    public static function defaultRole(string $kind): string
    {
        return match ($kind) {
            'yours' => 'mine',
            'google' => 'context',
            default => 'opportunities',
        };
    }

    public static function serializeAccount(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'status' => (string) $row['status'],
            'reauthRequired' => ($row['reauth_required_at'] ?? null) !== null,
            'error' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
            'canCreateCalendars' => self::canCreateCalendars($row),
            'connectedAt' => Time::dbToIso((string) $row['created_at']),
        ];
    }

    // ---- Internals ----------------------------------------------------------

    private function tokenRequest(array $fields): array
    {
        $r = $this->http()->postForm(self::TOKEN_URL, $fields);
        $data = json_decode($r['body'], true);
        if ($r['status'] < 200 || $r['status'] >= 300 || !is_array($data)) {
            $why = is_array($data) ? (string) ($data['error_description'] ?? $data['error'] ?? '') : '';
            throw new \RuntimeException('Google token request failed: HTTP ' . $r['status'] . ($why !== '' ? " ($why)" : ''));
        }
        return $data;
    }

    private function http(): HttpClient
    {
        return new HttpClient(requestBudget: 20, userAgent: 'Better-Cal/0.1 (+google-connector)');
    }

    private function stateKey(string $sessionToken): string
    {
        $base = hash('sha256', 'google-state|' . (string) $this->cfg['session_secret'], true);
        return hash_hmac('sha256', 'session|' . hash('sha256', $sessionToken), $base, true);
    }

    /**
     * Persist a completed consent only while its initiating browser session
     * still exists. The row lock shares the reset's session-delete boundary:
     * if this wins, reset quarantines the account afterwards; if reset wins,
     * this refuses to recreate or reactivate a durable Google credential.
     */
    private function storeConnection(int $userId, string $sessionToken, string $email, string $sealed, string $scopes): array
    {
        return $this->db->tx(function () use ($userId, $sessionToken, $email, $sealed, $scopes): array {
            try {
                // User then session: the same order as compromise reset, and
                // before account writes that take a user FK lock.
                Auth::assertSession($this->db, $userId, $sessionToken, true);
            } catch (HttpError) {
                throw HttpError::conflict('oauth_session_expired', 'The browser session that started Google consent is no longer signed in; start again.');
            }

            $existing = $this->db->one('SELECT * FROM google_accounts WHERE user_id = ? AND email = ?', [$userId, $email]);
            if ($existing !== null) {
                $this->db->update('google_accounts', [
                    'refresh_token_enc' => $sealed, 'scopes' => $scopes, 'status' => 'ok', 'last_error' => null,
                    'reauth_required_at' => null,
                ], 'id = ?', [(int) $existing['id']]);
                if (($existing['reauth_required_at'] ?? null) !== null) {
                    $this->db->run(
                        "UPDATE calendars SET last_polled_at = NULL, last_poll_status = 'never', last_poll_error = NULL
                         WHERE google_account_id = ?",
                        [(int) $existing['id']]
                    );
                }
                return $this->db->one('SELECT * FROM google_accounts WHERE id = ?', [(int) $existing['id']]);
            }
            $id = $this->db->insert('google_accounts', [
                'user_id' => $userId, 'email' => $email, 'refresh_token_enc' => $sealed, 'scopes' => $scopes,
            ]);
            return $this->db->one('SELECT * FROM google_accounts WHERE id = ?', [$id]);
        });
    }

    private function requireConfigured(): void
    {
        if (!$this->configured()) {
            throw new HttpError('google_not_configured', 'Google Calendar is not set up on this server (BETTERCAL_GOOGLE_CLIENT_ID / _SECRET; see docs/google-calendar.md).', 503);
        }
    }
}
