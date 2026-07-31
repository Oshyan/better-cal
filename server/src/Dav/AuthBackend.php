<?php

declare(strict_types=1);

namespace BetterCal\Dav;

use BetterCal\Infra\Db;
use BetterCal\Support\Time;

/**
 * HTTP Basic auth for CalDAV clients. Username is the account email; the
 * password is either the account password (password_verify) or a personal
 * access token value ("bc_..."), matched by sha256 against api_tokens.
 */
final class AuthBackend extends \Sabre\DAV\Auth\Backend\AbstractBasic
{
    public function __construct(private readonly Db $db)
    {
        $this->setRealm('Better-Cal');
    }

    protected function validateUserPass($username, $password)
    {
        $user = $this->db->one('SELECT id, password_hash FROM users WHERE email = ?', [trim((string) $username)]);
        if ($user === null) {
            // Burn comparable time so unknown emails are not distinguishable.
            password_verify((string) $password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            return false;
        }
        if (password_verify((string) $password, (string) $user['password_hash'])) {
            return true;
        }
        $token = $this->db->one(
            'SELECT id FROM api_tokens WHERE user_id = ? AND token_hash = ? AND (expires_at IS NULL OR expires_at > ?)',
            [(int) $user['id'], hash('sha256', (string) $password), Time::nowDb()]
        );
        return $token !== null;
    }
}
