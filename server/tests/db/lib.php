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

/** Wait until $cond() holds, at most $seconds: for another process to reach a step. */
function waitFor(callable $cond, float $seconds = 10.0): bool
{
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        if ($cond()) {
            return true;
        }
        usleep(20_000);
    }
    return false;
}

/** Whether some transaction is waiting on a row lock now. */
function someoneWaits(Db $db): bool
{
    return (int) $db->scalar("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'") > 0;
}
