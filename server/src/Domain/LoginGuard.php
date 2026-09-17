<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Throttle;
use BetterCal\Support\ClientIp;

/**
 * The limit on password guessing, shared by every door a password opens: the
 * REST login and CalDAV Basic auth count against the SAME buckets, or closing
 * one would just move the guessing to the other (BC-05/BC-06, issue #20).
 *
 * Two limits, neither of which locks the account:
 *
 * - Per source: MAX failures from one address (one IPv4, or one IPv6 /64) in
 *   WINDOW seconds, then that source is refused until the window rolls on.
 *
 * - Overall: once failures from EVERYWHERE pass GLOBAL_MAX in the window (many
 *   addresses taking turns), sources that have never signed in successfully are
 *   refused. Sources that have (in the last 30 days) are not, so the owner at
 *   home, and their phone's CalDAV client, keep working through an attack.
 *   Locking the ACCOUNT would hand an attacker a way to lock the owner out of
 *   their own calendar with ten bad guesses, which is a denial of service
 *   delivered by the defence.
 *
 * No sleeping: a delay per attempt would let an attacker park every PHP worker
 * on a timer. A refusal is immediate and costs a COUNT.
 *
 * A refused attempt does not run password_verify at all, so it cannot be used
 * to keep guessing (or to burn CPU on the hash) while "blocked".
 */
final class LoginGuard
{
    public const WINDOW = 900;          // 15 minutes
    public const DEFAULT_MAX = 10;      // failures per source per window
    public const GLOBAL_MAX = 60;       // failures from all sources per window
    public const KNOWN_FOR = 2592000;   // a successful sign-in vouches for its source for 30 days

    private const FAIL_ALL = 'auth-fail:all';

    /** @param list<string> $trustedProxies */
    public function __construct(
        private readonly Throttle $throttle,
        private readonly array $trustedProxies = [],
        private readonly int $maxFailures = self::DEFAULT_MAX,
        private readonly ?\BetterCal\Infra\Db $journal = null,
    ) {
    }

    /**
     * One failed attempt, from start to finish: count it, and when it is the
     * one that closes the door, say so once in Activity. An owner who sees
     * "Blocked sign-in attempts from 203.0.113.7" knows someone is trying;
     * one who sees it for their own address knows why their phone stopped
     * syncing and that it will resume by itself.
     *
     * @param string $door 'web' or 'caldav', for the Activity line
     */
    public function failed(string $source, string $door, ?\DateTimeImmutable $now = null): void
    {
        if (!$this->recordFailure($source, $now) || $this->journal === null) {
            return;
        }
        try {
            $owner = $this->journal->scalar('SELECT id FROM users ORDER BY id LIMIT 1');
            if ($owner === null) {
                return;
            }
            ActivityContext::with('system', fn() => (new Undo($this->journal))->record(
                (int) $owner,
                'system',
                0,
                'refuse',
                null,
                null,
                'Blocked sign-in attempts from ' . $source . ' after ' . $this->maxFailures . ' wrong passwords (' . $door . ')',
                ['source' => $source, 'door' => $door, 'unblocksInSeconds' => self::WINDOW]
            ));
        } catch (\Throwable $e) {
            error_log('login guard journal failed: ' . $e->getMessage());
        }
    }

    /** The counting unit for this request ("203.0.113.7", or "2001:db8:1:2::/64"). */
    public function source(array $server): string
    {
        return ClientIp::bucket(ClientIp::resolve($server, $this->trustedProxies));
    }

    /**
     * May this source try a password right now? Returns the number of seconds
     * to wait when it may not, else 0.
     */
    public function retryAfter(string $source, ?\DateTimeImmutable $now = null): int
    {
        $wait = $this->throttle->retryAfter('auth-fail:ip:' . $source, max(1, $this->maxFailures), self::WINDOW, $now);
        if ($wait > 0) {
            return $wait;
        }
        if ($this->throttle->count(self::FAIL_ALL, self::WINDOW, $now) >= self::GLOBAL_MAX && !$this->isKnown($source, $now)) {
            return max(1, $this->throttle->retryAfter(self::FAIL_ALL, self::GLOBAL_MAX, self::WINDOW, $now));
        }
        return 0;
    }

    /** @return bool true when THIS failure is the one that closes the door (worth telling the owner once) */
    public function recordFailure(string $source, ?\DateTimeImmutable $now = null): bool
    {
        $this->throttle->hit('auth-fail:ip:' . $source, $now);
        $this->throttle->hit(self::FAIL_ALL, $now);
        return $this->throttle->count('auth-fail:ip:' . $source, self::WINDOW, $now) === max(1, $this->maxFailures);
    }

    /** A success vouches for the source and wipes its own failures (a typo or two is not an attack). */
    public function recordSuccess(string $source, ?\DateTimeImmutable $now = null): void
    {
        $this->throttle->clear('auth-fail:ip:' . $source);
        // One row per source is all "known" needs; refresh it rather than
        // adding a row per CalDAV request (a syncing phone makes thousands).
        if ($this->throttle->count('auth-ok:ip:' . $source, 86400, $now) === 0) {
            $this->throttle->hit('auth-ok:ip:' . $source, $now);
        }
    }

    public function isKnown(string $source, ?\DateTimeImmutable $now = null): bool
    {
        return $this->throttle->count('auth-ok:ip:' . $source, self::KNOWN_FOR, $now) > 0;
    }
}
