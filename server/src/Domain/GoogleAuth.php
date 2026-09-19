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
 * Read-only scope on purpose for the read side; the write side (write-through
 * to calendars the user can edit) asks for more when it lands, which costs
 * one re-consent. The refresh token is the only thing kept, sealed with the
 * session secret; access tokens are minted per run and never stored.
 */
final class GoogleAuth
{
    public const SCOPES = 'openid email https://www.googleapis.com/auth/calendar.readonly';
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

    /** Where to send the browser. The state ties the callback to this user. */
    public function authUrl(int $userId): string
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
            'state' => $this->signState($userId),
        ]);
    }

    /** Stateless CSRF binding: user id and expiry, HMAC'd with the session secret. */
    public function signState(int $userId, ?int $now = null): string
    {
        $exp = ($now ?? time()) + self::STATE_TTL;
        $nonce = bin2hex(random_bytes(8));
        $payload = $userId . '.' . $exp . '.' . $nonce;
        return $payload . '.' . hash_hmac('sha256', $payload, $this->stateKey());
    }

    public function verifyState(string $state, int $userId, ?int $now = null): bool
    {
        $parts = explode('.', $state);
        if (count($parts) !== 4) {
            return false;
        }
        [$uid, $exp, $nonce, $mac] = $parts;
        $payload = $uid . '.' . $exp . '.' . $nonce;
        if (!hash_equals(hash_hmac('sha256', $payload, $this->stateKey()), $mac)) {
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
    public function connect(int $userId, string $code): array
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
        $existing = $this->db->one('SELECT * FROM google_accounts WHERE user_id = ? AND email = ?', [$userId, $email]);
        if ($existing !== null) {
            $this->db->update('google_accounts', [
                'refresh_token_enc' => $sealed, 'scopes' => $scopes, 'status' => 'ok', 'last_error' => null,
            ], 'id = ?', [(int) $existing['id']]);
            return $this->db->one('SELECT * FROM google_accounts WHERE id = ?', [(int) $existing['id']]);
        }
        $id = $this->db->insert('google_accounts', [
            'user_id' => $userId, 'email' => $email, 'refresh_token_enc' => $sealed, 'scopes' => $scopes,
        ]);
        return $this->db->one('SELECT * FROM google_accounts WHERE id = ?', [$id]);
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
        if (($account['status'] ?? 'ok') !== 'ok') {
            $this->db->update('google_accounts', ['status' => 'ok', 'last_error' => null], 'id = ?', [(int) $account['id']]);
        }
        return (string) $token['access_token'];
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
     * @return list<array{id:string,name:string,accessRole:string,primary:bool,color:?string}>
     */
    public function listCalendars(array $account): array
    {
        $access = $this->accessToken($account);
        $out = [];
        $pageToken = null;
        do {
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
                ];
            }
            $pageToken = isset($data['nextPageToken']) ? (string) $data['nextPageToken'] : null;
        } while ($pageToken !== null);
        usort($out, static fn(array $a, array $b): int => [$b['primary'], strtolower($a['name'])] <=> [$a['primary'], strtolower($b['name'])]);
        return $out;
    }

    public static function serializeAccount(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'status' => (string) $row['status'],
            'error' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
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

    private function stateKey(): string
    {
        return hash('sha256', 'google-state|' . (string) $this->cfg['session_secret'], true);
    }

    private function requireConfigured(): void
    {
        if (!$this->configured()) {
            throw new HttpError('google_not_configured', 'Google Calendar is not set up on this server (BETTERCAL_GOOGLE_CLIENT_ID / _SECRET; see docs/google-calendar.md).', 503);
        }
    }
}
