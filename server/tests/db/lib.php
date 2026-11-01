<?php

declare(strict_types=1);

// Helpers for the database suite (run.php): the same check/checkEq as the
// pure-PHP suite, a fresh account per scenario, and the domain objects
// wired as the app wires them.

use BetterCal\Domain\Calendars;
use BetterCal\Domain\Events;
use BetterCal\Domain\Filters;
use BetterCal\Domain\Folders;
use BetterCal\Domain\Labels;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Trips;
use BetterCal\Domain\Undo;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;
use BetterCal\Infra\Setup;

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    if ($cond) {
        $GLOBALS['__pass']++;
        return;
    }
    $GLOBALS['__fail']++;
    echo "FAIL: $name" . ($detail !== '' ? " — $detail" : '') . "\n";
}

function checkEq(string $name, mixed $expected, mixed $actual): void
{
    check($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

/**
 * Run one block of a test file. A call that throws (an undo refused where
 * the test expected it to go through, say) fails the block as one check
 * and the file's next block still runs; without this, one wrong outcome
 * threw the rest of the file away unseen.
 */
function scenario(string $name, callable $fn): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        check($name, false, get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

/** A second connection to the test database, for a transaction of its own next to the suite's. */
function otherConnection(): Db
{
    return new Db(['dsn' => (string) getenv('BC_TEST_DSN'), 'user' => (string) getenv('BC_TEST_DB_USER'), 'pass' => (string) getenv('BC_TEST_DB_PASS')]);
}

/**
 * How many deadlocks the server has broken so far, or null where it
 * doesn't say: MariaDB counts them in a status variable, MySQL in its
 * InnoDB metrics (when that metric is on). Neither is the other's.
 */
function deadlockCount(Db $db): ?int
{
    if (str_contains(strtolower((string) $db->scalar('SELECT VERSION()')), 'mariadb')) {
        $row = $db->one("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'");
        return $row === null ? null : (int) $row['Value'];
    }
    try {
        $row = $db->one("SELECT COUNT, STATUS FROM information_schema.INNODB_METRICS WHERE NAME = 'lock_deadlocks'");
    } catch (\PDOException) {
        return null;
    }
    return $row !== null && strtolower((string) $row['STATUS']) === 'enabled' ? (int) $row['COUNT'] : null;
}

/** The error code $fn refuses with, or null when it goes through. */
function refusal(callable $fn): ?string
{
    try {
        $fn();
        return null;
    } catch (HttpError $e) {
        return $e->errorCode;
    }
}

/** A new account (Setup, as an install makes one): its first calendar and default. */
function freshUser(Db $db): int
{
    return Setup::createOwner($db, 'owner-' . bin2hex(random_bytes(4)) . '@example.test', 'correct horse battery');
}

/** The newest journal entry's id for an account. */
function lastEntry(Db $db, int $userId): int
{
    return (int) $db->scalar('SELECT MAX(id) FROM mutations WHERE user_id = ?', [$userId]);
}

final class Domains
{
    public function __construct(
        public readonly Undo $undo,
        public readonly Calendars $calendars,
        public readonly Folders $folders,
        public readonly Events $events,
    ) {
    }
}

function domains(Db $db): Domains
{
    $undo = new Undo($db);
    return new Domains(
        $undo,
        new Calendars($db, $undo, str_repeat('t', 32)),
        new Folders($db, $undo),
        new Events($db, new Recurrence(), $undo, new Labels($db), new Filters($db, $undo, new JobQueue($db)), new Trips($db, $undo)),
    );
}

/** An event's fields for create(), an hour on a given UTC day. */
function eventAt(int $calendarId, string $title, string $day = '2026-12-08', array $more = []): array
{
    return ['calendarId' => $calendarId, 'title' => $title, 'start' => $day . 'T18:00:00+00:00', 'end' => $day . 'T19:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC'] + $more;
}

/**
 * Run tests/db/child.php with $args in its own process (a second
 * connection), returning a handle; wait() for its exit code and output.
 */
final class Child
{
    /** @var resource */
    private $proc;
    /** @var array<int,resource> */
    private array $pipes = [];

    public function __construct(array $args)
    {
        $cmd = array_merge([PHP_BINARY, __DIR__ . '/child.php'], array_map('strval', $args));
        $this->proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
    }

    /** @return array{0:int,1:string} */
    public function wait(): array
    {
        $out = stream_get_contents($this->pipes[1]) . stream_get_contents($this->pipes[2]);
        return [proc_close($this->proc), trim($out)];
    }
}

/** Wait until $cond() holds, at most $seconds, asking every $every seconds: for another process to reach a step. */
function waitFor(callable $cond, float $seconds = 10.0, float $every = 0.02): bool
{
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        if ($cond()) {
            return true;
        }
        usleep((int) ($every * 1_000_000));
    }
    return false;
}

/**
 * Whether some transaction is waiting on a row lock now. Ask it no more
 * often than every 150ms: the server refreshes what INNODB_TRX shows only
 * when nobody has read it for 100ms, so a tighter poll reads the same
 * stale answer for as long as it keeps asking (waitForWaiter).
 */
function someoneWaits(Db $db): bool
{
    return (int) $db->scalar("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'") > 0;
}

/** Wait until another connection is seen waiting on a row lock, at most $seconds. */
function waitForWaiter(Db $db, float $seconds = 15.0): bool
{
    return waitFor(static fn() => someoneWaits($db), $seconds, 0.15);
}
