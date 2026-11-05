<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Dav\ChangeLog;
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

    /** Whether a checked transaction moves the account's change marker (a request that may change what devices show). */
    private static bool $movesMarker = false;

    /**
     * The one distinction every write made under a request falls on. A write
     * is either a change to what the account holds, made inside a
     * transaction in which the request's credential was checked with lock
     * (Undo::write where the change is journaled, checkedTx() where it is
     * not), or bookkeeping the server keeps about requests themselves, which
     * belongs to no account and is written the same whoever asked. The
     * bookkeeping is these tables and nothing else; a write to any other
     * table outside a checked transaction is a journaling misuse
     * (Undo::uncheckedWrite).
     */
    public const BOOKKEEPING = [
        'rate_events' => 'throttle counters: how often a source or a bucket asked, whoever it was',
        'model_admissions' => 'admission to the model: the budget a request draws on, counted before it is known to be valid',
        'external_action_admissions' => 'admission to an outside action (a test email): the same kind of budget',
        'http_cache' => 'a lookup cache (an event page, a plugin fetch): the same answer for whoever asks',
        'geocode_cache' => 'a lookup cache (an address): the same answer for whoever asks',
        'system_health' => "the server's health record of its own jobs and providers",
    ];
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
    public static function during(self|\Closure|null $authority, callable $fn, ?bool $movesMarker = null): mixed
    {
        [$was, $wasMoving] = [self::$current, self::$movesMarker];
        self::$current = $authority;
        self::$movesMarker = $movesMarker ?? $wasMoving;
        $watch = Db::watchWrites($authority === null ? null : static function (string $sql): void {
            if (self::current() === null) {
                return;
            }
            [$verb, $table] = self::written($sql);
            if (!isset(self::BOOKKEEPING[$table])) {
                Undo::uncheckedWrite($verb, $table);
            }
        });
        try {
            return $fn();
        } finally {
            [self::$current, self::$movesMarker] = [$was, $wasMoving];
            Db::watchWrites($watch);
        }
    }

    /**
     * The boundary for a change the journal doesn't keep: a transaction that
     * checks the request's credential with lock, then runs $fn. With no
     * request registered (a worker, a command), just the transaction.
     */
    public static function checkedTx(Db $db, callable $fn): mixed
    {
        return $db->tx(static function () use ($db, $fn): mixed {
            self::current()?->assertValid($db);
            return $fn();
        });
    }

    /**
     * A credential was checked with lock in the transaction open now (the
     * checks assertValid makes, wherever they are made): its writes are
     * covered, and in a request that may change what devices show, the
     * account's change marker moves with them, once per transaction.
     */
    public static function checked(Db $db, int $userId): void
    {
        if (!$db->pdo()->inTransaction()) {
            return;
        }
        $already = $db->credentialCheckedNow();
        $db->credentialChecked();
        if (!$already && self::$movesMarker && self::current() !== null) {
            ChangeLog::touchAccount($db, $userId);
        }
    }

    /** @return array{0:string,1:string} the statement's verb and the table it writes */
    public static function written(string $sql): array
    {
        preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\s+(?:IGNORE\s+|OR\s+\w+\s+)?(?:INTO\s+|FROM\s+)?`?(\w+)/i', $sql, $m);
        return [strtoupper($m[1] ?? 'WRITE'), strtolower($m[2] ?? '?')];
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
        $still = is_string($current) && hash_equals($verifiedHash, $current);
        if ($still && $lock) {
            self::checked($db, $userId);
        }
        return $still;
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
    }
}
