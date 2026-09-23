<?php

declare(strict_types=1);

namespace BetterCal\Dav;

use BetterCal\Domain\Auth;
use BetterCal\Domain\LoginGuard;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

/**
 * HTTP Basic auth for CalDAV clients. Username is the account email; the
 * password is either the account password (password_verify) or a personal
 * access token value ("bc_..."), matched by sha256 against api_tokens.
 *
 * Every request authenticates, so this is as good a place to guess passwords
 * as the login form. It counts against the SAME limits (LoginGuard): a source
 * blocked on one is blocked on the other.
 */
final class AuthBackend extends \Sabre\DAV\Auth\Backend\AbstractBasic
{
    public function __construct(private readonly Db $db, private readonly ?LoginGuard $guard = null)
    {
        $this->setRealm('Better-Cal');
    }

    protected function validateUserPass($username, $password)
    {
        $source = $this->guard?->source($_SERVER) ?? '';
        $wait = $this->guard?->begin($source) ?? 0;
        if ($wait > 0) {
            throw new TooManyAttempts($wait);
        }
        $ok = $this->passwordOrTokenMatches((string) $username, (string) $password);
        if ($ok) {
            $this->guard?->succeeded($source);
        } else {
            $this->guard?->rejected($source, 'caldav');
        }
        return $ok;
    }

    private function passwordOrTokenMatches(string $username, string $password): bool
    {
        $user = $this->db->one('SELECT id, password_hash FROM users WHERE email = ?', [trim($username)]);
        if ($user === null) {
            Auth::burnTime($this->db, $password); // same work as a real check (F11)
            return false;
        }
        if (password_verify($password, (string) $user['password_hash'])) {
            return true;
        }
        $token = $this->db->one(
            'SELECT id FROM api_tokens WHERE user_id = ? AND token_hash = ? AND (expires_at IS NULL OR expires_at > ?)',
            [(int) $user['id'], hash('sha256', $password), Time::nowDb()]
        );
        return $token !== null;
    }
}
