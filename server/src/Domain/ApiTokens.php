<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Support\Ids;
use BetterCal\Support\Time;

final class ApiTokens
{
    public const PREFIX = 'bc_';

    public function __construct(private readonly Db $db)
    {
    }

    /** New token value: "bc_" + 43 url-safe base64 chars from 32 random bytes. */
    public static function generate(): string
    {
        return self::PREFIX . Ids::base64url(random_bytes(32));
    }

    /** Tokens are stored as sha256 of the full "bc_..." value. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isValidFormat(string $token): bool
    {
        return preg_match('/^bc_[A-Za-z0-9_-]{43}$/', $token) === 1;
    }

    /** Extract the credential from an "Authorization: Bearer <token>" header value; null for anything else. */
    public static function parseBearer(?string $header): ?string
    {
        if ($header === null || !preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
            return null;
        }
        return $m[1];
    }

    /** @return array{id:int,name:string,token:string,createdAt:string} */
    public function create(int $userId, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('name is required');
        }
        if (mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('name must be 120 characters or fewer');
        }
        $token = self::generate();
        $now = Time::nowDb();
        $id = $this->db->insert('api_tokens', [
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => self::hashToken($token),
            'created_at' => $now,
        ]);
        return ['id' => $id, 'name' => $name, 'token' => $token, 'createdAt' => Time::dbToIso($now)];
    }

    /** @return list<array{id:int,name:string,createdAt:string,lastUsedAt:?string}> */
    public function listAll(int $userId): array
    {
        $rows = $this->db->all(
            'SELECT id, name, created_at, last_used_at FROM api_tokens WHERE user_id = ? ORDER BY id',
            [$userId]
        );
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'createdAt' => Time::dbToIso((string) $r['created_at']),
            'lastUsedAt' => $r['last_used_at'] === null ? null : Time::dbToIso((string) $r['last_used_at']),
        ], $rows);
    }

    public function revoke(int $userId, int $id): bool
    {
        return $this->db->run('DELETE FROM api_tokens WHERE id = ? AND user_id = ?', [$id, $userId])->rowCount() > 0;
    }

    /** Resolve a bearer token to its user row (password hash removed); null if unknown or expired. */
    /** The id of the token the last successful resolve() matched. */
    public ?int $lastTokenId = null;

    /** Is this token still usable (exists, not expired)? For channels bound to a token. */
    public static function stillValid(\BetterCal\Infra\Db $db, int $tokenId): bool
    {
        return $db->scalar('SELECT 1 FROM api_tokens WHERE id = ? AND (expires_at IS NULL OR expires_at > ?)', [$tokenId, Time::nowDb()]) !== null;
    }

    public function resolve(string $token): ?array
    {
        if (!self::isValidFormat($token)) {
            return null;
        }
        $row = $this->db->one(
            'SELECT t.id AS token_id, t.last_used_at AS token_last_used_at, u.*
             FROM api_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND (t.expires_at IS NULL OR t.expires_at > ?)',
            [self::hashToken($token), Time::nowDb()]
        );
        if ($row === null) {
            return null;
        }
        $tokenId = (int) $row['token_id'];
        $this->lastTokenId = $tokenId;
        $lastUsed = $row['token_last_used_at'];
        unset($row['token_id'], $row['token_last_used_at'], $row['password_hash']);
        // Throttle last_used_at writes to once a minute.
        if ($lastUsed === null || Time::fromDb((string) $lastUsed) < Time::nowUtc()->sub(new \DateInterval('PT1M'))) {
            $this->db->run('UPDATE api_tokens SET last_used_at = ? WHERE id = ?', [Time::nowDb(), $tokenId]);
        }
        return $row;
    }
}
