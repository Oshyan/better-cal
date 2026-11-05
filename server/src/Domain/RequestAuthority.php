<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Infra\Db;

/** Exact request credential, rechecked at a write boundary or just before sending. */
final class RequestAuthority
{
    private function __construct(
        public readonly int $userId,
        private readonly ?int $tokenId,
        private readonly ?string $session,
        private readonly ?string $passwordHash = null,
    ) {}

    /**
     * The credential of the request being served (during()), or a closure
     * that resolves it once the request has signed in (CalDAV). Null for a
     * worker's job, a command or a test that registers none.
     *
     * @var self|\Closure():?self|null
     */
    private static self|\Closure|null $current = null;

    /** The credential every write made now is checked against (Undo::write), or null. */
    public static function current(): ?self
    {
        $current = self::$current;
        return $current instanceof \Closure ? $current() : $current;
    }

    /**
     * Run $fn as a request made with $authority: every write made under it
     * is checked at the write boundary (Undo::write, right after the
     * account's lock), and a write statement made outside a transaction
     * in which a credential was checked is a journaling misuse
     * (Undo::uncheckedWrite). Null runs $fn as no request at all.
     */
    public static function during(self|\Closure|null $authority, callable $fn): mixed
    {
        $was = self::$current;
        self::$current = $authority;
        $watch = Db::watchWrites($authority === null ? null : static function (string $sql): void {
            if (self::current() !== null) {
                Undo::uncheckedWrite($sql);
            }
        });
        try {
            return $fn();
        } finally {
            self::$current = $was;
            Db::watchWrites($watch);
        }
    }

    /** What a signed-in request authenticated with: its API key or its browser session; null when it didn't sign in. */
    public static function of(Request $request): ?self
    {
        if ($request->user === null) {
            return null;
        }
        if ($request->authMethod === 'token' && $request->tokenId !== null) {
            return self::credential((int) $request->user['id'], $request->tokenId, null);
        }
        if ($request->authMethod === 'session') {
            return self::credential((int) $request->user['id'], null, $request->cookies[Auth::COOKIE] ?? null);
        }
        return null;
    }

    public static function credential(int $userId, ?int $tokenId, ?string $session): self
    {
        return new self($userId, $tokenId, $session);
    }

    public static function request(Request $request): self
    {
        if ($request->authMethod === 'token' && $request->tokenId !== null) {
            return self::credential((int) $request->user['id'], $request->tokenId, null);
        }
        $request->requireSession('This change');
        return self::credential((int) $request->user['id'], null, $request->cookies[Auth::COOKIE] ?? null);
    }

    /** The API key behind this request, or null for a browser session or password. */
    public function tokenId(): ?int
    {
        return $this->tokenId;
    }

    /** The hash must be the exact version against which the password was verified. */
    public static function password(int $userId, string $verifiedHash): self
    {
        return new self($userId, null, null, $verifiedHash);
    }

    public static function passwordStillCurrent(Db $db, int $userId, string $verifiedHash, bool $lock = true): bool
    {
        $current = $db->scalar('SELECT password_hash FROM users WHERE id = ?' . ($lock ? $db->forUpdate() : ''), [$userId]);
        return is_string($current) && hash_equals($verifiedHash, $current);
    }

    /** With $lock, call inside the committing transaction, before locking domain rows. */
    public function assertValid(Db $db, bool $lock = true, bool $recent = false): void
    {
        if ($lock && !$db->pdo()->inTransaction()) {
            throw new \LogicException('A locked authority check requires a transaction');
        }
        if ($this->passwordHash !== null) {
            if (!self::passwordStillCurrent($db, $this->userId, $this->passwordHash, $lock)) {
                throw HttpError::unauthorized('The password changed before this change could be saved. Sign in again.');
            }
        } elseif ($this->tokenId !== null) {
            if ($recent) {
                throw HttpError::forbidden('session_required', 'This change needs a signed-in browser.');
            }
            ApiTokens::assertStillValid($db, $this->tokenId, $this->userId, $lock);
        } elseif ($recent) {
            Auth::assertRecentSession($db, $this->userId, $this->session, $lock);
        } else {
            Auth::assertSession($db, $this->userId, $this->session, $lock);
        }
        if ($lock) {
            $db->credentialChecked();
        }
    }
}
