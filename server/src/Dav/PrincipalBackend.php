<?php

declare(strict_types=1);

namespace BetterCal\Dav;

use BetterCal\Infra\Db;

/**
 * Principals backed by the users table (effectively single-user). Principal
 * uri is "principals/{email}", matching AbstractBasic's default prefix.
 */
final class PrincipalBackend extends \Sabre\DAVACL\PrincipalBackend\AbstractBackend
{
    private const PREFIX = 'principals';

    public function __construct(private readonly Db $db)
    {
    }

    public function getPrincipalsByPrefix($prefixPath)
    {
        if ($prefixPath !== self::PREFIX) {
            return [];
        }
        return array_map(
            static fn(array $u): array => self::principal($u),
            $this->db->all('SELECT id, email, display_name FROM users ORDER BY id')
        );
    }

    public function getPrincipalByPath($path)
    {
        if (!str_starts_with((string) $path, self::PREFIX . '/')) {
            return null;
        }
        $email = substr((string) $path, strlen(self::PREFIX) + 1);
        $user = $this->db->one('SELECT id, email, display_name FROM users WHERE email = ?', [$email]);
        return $user === null ? null : self::principal($user);
    }

    public function updatePrincipal($path, \Sabre\DAV\PropPatch $propPatch)
    {
        // Principals are read-only over DAV.
    }

    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof')
    {
        if ($prefixPath !== self::PREFIX || $searchProperties === []) {
            return [];
        }
        $email = $searchProperties['{http://sabredav.org/ns}email-address'] ?? null;
        if (!is_string($email) || $email === '') {
            return [];
        }
        $user = $this->db->one('SELECT email FROM users WHERE email = ?', [$email]);
        return $user === null ? [] : [self::PREFIX . '/' . $user['email']];
    }

    public function getGroupMemberSet($principal)
    {
        return [];
    }

    public function getGroupMembership($principal)
    {
        return [];
    }

    public function setGroupMemberSet($principal, array $members)
    {
        throw new \Sabre\DAV\Exception\Forbidden('Group membership is read-only');
    }

    /** @param array<string,mixed> $user */
    private static function principal(array $user): array
    {
        $email = (string) $user['email'];
        $name = trim((string) ($user['display_name'] ?? ''));
        return [
            'uri' => self::PREFIX . '/' . $email,
            '{DAV:}displayname' => $name !== '' ? $name : $email,
            '{http://sabredav.org/ns}email-address' => $email,
        ];
    }
}
