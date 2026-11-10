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
// Every check by its label, in order, for the report run.php writes when
// BC_TEST_REPORT names a file (the edit harness's mutation runner judges a
// mutation by which assertions it fails). `threw` marks a failure that is a
// throw caught by scenario() or by run.php, not an assertion.
$GLOBALS['__checks'] = [];

function check(string $name, bool $cond, string $detail = '', bool $threw = false): void
{
    $GLOBALS['__checks'][] = ['name' => $name, 'pass' => $cond] + ($cond ? [] : ['detail' => $detail, 'threw' => $threw]);
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
 *
 * After the block, the link rule holds over all data (linkRuleCheck): a
 * violation the block left is named by the block. $linksOptOut is for a
 * block that builds an older or broken form on purpose: its reason, beside
 * the call, and the block's own violations are then not counted.
 */
function scenario(string $name, callable $fn, ?string $linksOptOut = null): void
{
    $outer = ($GLOBALS['__scenarioDepth'] ?? 0) === 0;
    if ($outer) {
        linkRuleCheck("made outside any block, found before the block '$name'", null, null);
    }
    $GLOBALS['__scenarioDepth'] = ($GLOBALS['__scenarioDepth'] ?? 0) + 1;
    try {
        $fn();
    } catch (\Throwable $e) {
        check($name, false, get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine(), true);
    } finally {
        $GLOBALS['__scenarioDepth']--;
    }
    if ($outer) {
        linkRuleCheck("left by the block '$name'", $name, $linksOptOut);
    }
}

// Rule 2, link ownership, as one invariant over all data (audit-155-links, "The invariant as SQL"): a live day row (an
// events row with a parent and a key, not deleted, whose master is live) holds, as stored event_tags and event_people
// rows, its own set when its own list (override_fields) names tags or people, and otherwise exactly its master's
// current set. Two companions are reported apart: no live day row has a NULL list (the pre-063 form, which no writer
// may leave), and a live day row whose master is missing or deleted is a structure finding, not a link one. Raw SQL on
// the connection alone: nothing in server/src decides what passes. The edit harness runs the same queries.
const LINKS_CHECK = "every live day row holds its own tags and people, or exactly its series'";
const LINKS_LIST_CHECK = 'no live day row has a NULL own list';
const LINKS_SERIES_CHECK = "no live day row's series is missing or deleted";

/**
 * Every violation of the link rule and its companions, keyed by kind and day row ("tags:12"), each with its kind
 * (tags, people, list, series), the day row, its master and what it holds against its master. $userId and $fromId
 * narrow it to one account's rows from an id on (the edit harness's case); null and 0 read every row.
 *
 * @return array<string,array{kind:string,day:int,master:int,what:string}>
 */
function linkViolations(BetterCal\Infra\Db $db, ?int $userId = null, int $fromId = 0): array
{
    $pdo = $db->pdo();
    $run = static function (string $sql) use ($pdo, $userId, $fromId): array {
        $st = $pdo->prepare($sql);
        $st->execute($userId === null ? [$fromId] : [$fromId, $userId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    };
    $day = 'd.recurrence_parent_id IS NOT NULL AND d.recurrence_instance_utc IS NOT NULL AND d.deleted_at IS NULL AND d.id >= ?'
        . ($userId === null ? '' : ' AND d.user_id = ?');
    $out = [];
    foreach (['tags' => ['event_tags', 'tag_id'], 'people' => ['event_people', 'person_id']] as $field => [$table, $col]) {
        $rows = $run("SELECT d.id AS day, m.id AS master, d.override_fields AS own,
                (SELECT GROUP_CONCAT(x.$col ORDER BY x.$col) FROM $table x WHERE x.event_id = d.id) AS day_set,
                (SELECT GROUP_CONCAT(y.$col ORDER BY y.$col) FROM $table y WHERE y.event_id = m.id) AS series_set
            FROM events d JOIN events m ON m.id = d.recurrence_parent_id AND m.deleted_at IS NULL
            WHERE $day AND d.override_fields IS NOT NULL AND d.override_fields NOT LIKE '%\"$field\"%'
              AND (EXISTS (SELECT 1 FROM $table x WHERE x.event_id = d.id
                      AND NOT EXISTS (SELECT 1 FROM $table y WHERE y.event_id = m.id AND y.$col = x.$col))
                OR EXISTS (SELECT 1 FROM $table y WHERE y.event_id = m.id
                      AND NOT EXISTS (SELECT 1 FROM $table x WHERE x.event_id = d.id AND x.$col = y.$col)))
            ORDER BY d.id");
        foreach ($rows as $r) {
            $out["$field:{$r['day']}"] = ['kind' => $field, 'day' => (int) $r['day'], 'master' => (int) $r['master'],
                'what' => "day row {$r['day']} (own list {$r['own']}) holds $field [" . ($r['day_set'] ?? '') . "] where its series {$r['master']} holds [" . ($r['series_set'] ?? '') . ']'];
        }
    }
    foreach ($run("SELECT d.id AS day, d.recurrence_parent_id AS master FROM events d JOIN events m ON m.id = d.recurrence_parent_id AND m.deleted_at IS NULL
            WHERE $day AND d.override_fields IS NULL ORDER BY d.id") as $r) {
        $out["list:{$r['day']}"] = ['kind' => 'list', 'day' => (int) $r['day'], 'master' => (int) $r['master'], 'what' => "day row {$r['day']} of series {$r['master']} has a NULL own list"];
    }
    foreach ($run("SELECT d.id AS day, d.recurrence_parent_id AS master, m.id AS found FROM events d LEFT JOIN events m ON m.id = d.recurrence_parent_id
            WHERE $day AND (m.id IS NULL OR m.deleted_at IS NOT NULL) ORDER BY d.id") as $r) {
        $out["series:{$r['day']}"] = ['kind' => 'series', 'day' => (int) $r['day'], 'master' => (int) $r['master'],
            'what' => "day row {$r['day']} is live under series {$r['master']}, which is " . ($r['found'] === null ? 'missing' : 'deleted')];
    }
    return $out;
}

/**
 * The link rule in the database suite (scenario, and run.php after each file): the violations not already standing
 * at the last look fail, under $block's labels when a block made them, else as made outside any block ($where says
 * where they were found). A block always records its three checks, pass or fail; the backstop records failures only.
 * The connection is the one run.php made for the files ($GLOBALS['__linkDb']); without one nothing is checked.
 */
function linkRuleCheck(string $where, ?string $block, ?string $optOut): void
{
    $db = $GLOBALS['__linkDb'] ?? null;
    if ($db === null) {
        return;
    }
    $now = linkViolations($db);
    $new = array_diff_key($now, $GLOBALS['__linkSeen'] ?? []);
    // What stands now is what the next look compares with: a row put right and broken again is found again.
    $GLOBALS['__linkSeen'] = $now;
    if ($optOut !== null) {
        if ($new !== []) {
            $GLOBALS['__linkOptedOut'][$block] = ['why' => $optOut, 'count' => count($new)];
        }
        return;
    }
    foreach ([LINKS_CHECK => ['tags', 'people'], LINKS_LIST_CHECK => ['list'], LINKS_SERIES_CHECK => ['series']] as $label => $kinds) {
        $found = array_filter($new, static fn(array $v): bool => in_array($v['kind'], $kinds, true));
        $detail = $where . ': ' . implode('; ', array_slice(array_column($found, 'what'), 0, 12)) . (count($found) > 12 ? '; and ' . (count($found) - 12) . ' more' : '');
        if ($block !== null) {
            check("$block: $label", $found === [], $detail);
        } elseif ($found !== []) {
            check($label, false, $detail);
        }
    }
}

/**
 * Rows put back by a later write (an undo, say) against the rows as they
 * were: their content equal, and each updated_at at or after the
 * original's. The write stamps a row it puts back with its own time, which
 * is right (sync clients read updated_at to see a row changed), so no
 * check compares that wall-clock column for equality across two writes.
 * $was and $now may nest rows at any depth; an updated_at holding a list
 * (stamps gathered from many rows) is compared entry by entry.
 */
function checkRestored(string $name, array $was, array $now): void
{
    $split = static function (array $value, string $path, array &$stamps) use (&$split): array {
        $out = [];
        foreach ($value as $key => $item) {
            if ($key === 'updated_at') {
                $leaves = static function (mixed $v, string $at) use (&$leaves, &$stamps): void {
                    if (!is_array($v)) {
                        $stamps[$at] = $v;
                        return;
                    }
                    foreach ($v as $i => $w) {
                        $leaves($w, "$at/$i");
                    }
                };
                $leaves($item, "$path/updated_at");
                continue;
            }
            $out[$key] = is_array($item) ? $split($item, "$path/$key", $stamps) : $item;
        }
        return $out;
    };
    [$stampsWas, $stampsNow] = [[], []];
    $contentWas = $split($was, '', $stampsWas);
    $contentNow = $split($now, '', $stampsNow);
    $earlier = [];
    foreach ($stampsWas + $stampsNow as $path => $_) {
        [$a, $b] = [$stampsWas[$path] ?? null, $stampsNow[$path] ?? null];
        if ($a === null || $b === null || (string) $b < (string) $a) {
            $earlier[] = "$path: " . var_export($a, true) . ' then ' . var_export($b, true);
        }
    }
    $detail = $contentWas !== $contentNow
        ? substr('content expected ' . var_export($contentWas, true) . ', got ' . var_export($contentNow, true), 0, 4000)
        : 'updated_at missing or earlier than the original: ' . implode('; ', array_slice($earlier, 0, 12));
    check($name, $contentWas === $contentNow && $earlier === [], $detail);
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
/**
 * A whole-series edit as the client makes it (series-model.md, The API):
 * preview, then commit the plan with every group named, the preselected
 * option unless $choices says otherwise. Anything else is patched as it is.
 */
function planned(BetterCal\Domain\Events $events, int $userId, int $id, array $in, array $choices = []): array
{
    try {
        $plan = $events->previewPatch($userId, $id, $in + ['plan' => ['choices' => $choices]]);
    } catch (BetterCal\Http\HttpError $e) {
        if ($e->errorCode !== 'preview_unavailable') {
            throw $e;
        }
        $events->patch($userId, $id, $in);
        return [];
    }
    $sel = $choices;
    foreach ($plan['groups'] as $g) {
        $sel[$g['id']] ??= $g['selected'];
    }
    $plan = $events->previewPatch($userId, $id, $in + ['plan' => ['choices' => $sel]]);
    foreach ($plan['groups'] as $g) {
        $sel[$g['id']] ??= $g['selected'];
    }
    $events->patch($userId, $id, $in + ['plan' => ['hash' => $plan['hash'], 'choices' => $sel]]);
    return $plan;
}

/** Delete following as the client makes it: preview, then the commit with its plan (none from the first day, delete all). */
function plannedDelete(BetterCal\Domain\Events $events, int $userId, int $id, string $instanceStart): array
{
    $plan = $events->previewDelete($userId, $id, 'following', $instanceStart);
    $events->deleteEvent($userId, $id, 'following', $instanceStart, null, !empty($plan['whole']) ? null : ['hash' => $plan['hash'], 'choices' => []]);
    return $plan;
}

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
    $u = Setup::createOwner($db, 'owner-' . bin2hex(random_bytes(4)) . '@example.test', 'correct horse battery');
    // Undo of a whole-series change on a Google calendar is off in 1.5.5
    // (Settings google_series_undo); the suite and the edit harness measure
    // it on, as it will ship in 1.5.6. One scenario in google-series-undo.test.php turns it off.
    seriesUndo($db, $u, true);
    return $u;
}

/** Turn the google_series_undo switch on or off for an account (Settings::DEFAULTS). */
function seriesUndo(Db $db, int $userId, bool $on): void
{
    $settings = json_decode((string) $db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]), true) ?: [];
    $db->update('users', ['settings_json' => json_encode(['google_series_undo' => $on] + $settings)], 'id = ?', [$userId]);
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

/** This connection's thread id on the server, for another connection to look for it in INNODB_TRX. */
function connectionId(Db $db): int
{
    return (int) $db->scalar('SELECT CONNECTION_ID()');
}

/**
 * Whether the connection with that thread id is waiting on a row lock now.
 * INNODB_TRX lists every connection on the server, so a count of all
 * waiters would also see another suite's on a shared local server, and a
 * child would commit before this suite's undo had arrived. Ask it no more
 * often than every 150ms: the server refreshes what INNODB_TRX shows only
 * when nobody has read it for 100ms, so a tighter poll reads the same
 * stale answer for as long as it keeps asking (waitForWaiter).
 */
function someoneWaits(Db $db, int $threadId): bool
{
    return (int) $db->scalar(
        "SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT' AND trx_mysql_thread_id = ?",
        [$threadId]
    ) > 0;
}

/** Wait until the connection with that thread id is seen waiting on a row lock, at most $seconds. */
function waitForWaiter(Db $db, int $threadId, float $seconds = 15.0): bool
{
    return waitFor(static fn() => someoneWaits($db, $threadId), $seconds, 0.15);
}

/** Remove a directory this suite made under the system temp dir, with everything in it. */
function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir((string) $entry) : unlink((string) $entry);
    }
    rmdir($dir);
}

/**
 * An older version's server/src, from the repository's history (git
 * archive), in a directory under the system temp dir the caller removes
 * (removeTree). Throws when the history doesn't have it (a shallow clone).
 */
function oldSource(string $revision): string
{
    $dir = realpath(sys_get_temp_dir()) . '/bc-old-src-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    $tar = $dir . '/src.tar';
    $run = static function (array $cmd): void {
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $said = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        if (proc_close($p) !== 0) {
            throw new \RuntimeException(implode(' ', $cmd) . ': ' . trim($said));
        }
    };
    // Named safe for this one command: a container's checkout may belong to
    // another user than the test process, and git refuses it otherwise. A
    // suite run from an exported tree with no history (the deploy's
    // snapshot) names the repository it came from in BC_TEST_REPO.
    $repo = getenv('BC_TEST_REPO') ?: dirname(__DIR__, 3);
    $run([getenv('BC_TEST_GIT') ?: 'git', '-c', 'safe.directory=' . $repo, '-C', $repo, 'archive', '--format=tar', '-o', $tar, $revision, 'server/src']);
    $run(['tar', '-xf', $tar, '-C', $dir]);
    unlink($tar);
    return $dir;
}

/**
 * Run $steps (old-version.php says how they read) through the domain
 * methods of the older version in $source (oldSource), in a process of its
 * own on this suite's database. Returns what they made, by name.
 *
 * @return array{user:int,calendar:int,series:array<string,int>,entries:array<string,int>,from:string}
 */
function oldVersion(string $source, array $steps): array
{
    $stepsFile = $source . '/steps-' . bin2hex(random_bytes(4)) . '.json';
    $outFile = $stepsFile . '.out';
    file_put_contents($stepsFile, json_encode($steps));
    $p = proc_open([PHP_BINARY, __DIR__ . '/old-version.php', $source . '/server/src', $stepsFile, $outFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $said = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $status = proc_close($p);
    $out = is_file($outFile) ? json_decode((string) file_get_contents($outFile), true) : null;
    @unlink($stepsFile);
    @unlink($outFile);
    if ($status !== 0 || !is_array($out)) {
        throw new \RuntimeException('the older version stopped: ' . trim($said));
    }
    return $out;
}

/**
 * A signed-in browser session and an API key for the account, live: each
 * kind's credential, the statement that revokes it (run as another
 * request), and the refusal a write under it then gets.
 *
 * @return array<string,array{0:BetterCal\Domain\RequestAuthority,1:callable():void,2:string}>
 */
function requestCredentials(Db $db, int $userId): array
{
    $session = 'sample-session-' . bin2hex(random_bytes(8));
    $db->run('INSERT INTO sessions (token_hash, user_id, csrf, expires_at) VALUES (?, ?, ?, ?)', [hash('sha256', $session), $userId, str_repeat('c', 32), '2099-01-01 00:00:00']);
    $db->run('INSERT INTO api_tokens (user_id, name, token_hash) VALUES (?, ?, ?)', [$userId, 'Sample key', hash('sha256', $session . '-key')]);
    $tokenId = (int) $db->pdo()->lastInsertId();
    return [
        'session' => [BetterCal\Domain\RequestAuthority::credential($userId, null, $session),
            static fn() => BetterCal\Domain\RequestAuthority::during(null, fn() => $db->run('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $session)])), 'session_revoked'],
        'API key' => [BetterCal\Domain\RequestAuthority::credential($userId, $tokenId, null),
            static fn() => BetterCal\Domain\RequestAuthority::during(null, fn() => $db->run('DELETE FROM api_tokens WHERE id = ?', [$tokenId])), 'token_revoked'],
    ];
}
