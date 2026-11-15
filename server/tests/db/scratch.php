<?php

declare(strict_types=1);

// The scratch databases a test process makes for itself, and their cleanup.
// The rule (docs/design/decisions.md, "A failed cleanup is not a verdict"):
// a process computes and writes its result first and drops its databases
// after, and a drop that fails is reported on its own line, with the
// database's name and the server's reason, and changes nothing else; the
// process exits with the status its results earned. No retry, no wait.
// Shared by the database suite (run.php), the edit harness (harness.php,
// interleave-cli.php), the stand-in recorder (tools/google-probe/record-app.php),
// the comparison tools (tools/compare) and the sweep (sweep.php). It needs
// nothing but PDO, and the tools that load it already load this folder's
// lib.php, so none takes a new dependency on the other side.

final class ScratchDatabase
{
    /**
     * The prefixes of the databases these processes make and drop, each followed by random or labelled characters:
     * the only names the sweep may drop. A runner that makes a new kind of scratch database names its prefix here.
     */
    public const PREFIXES = ['bc_test_', 'bc_edits_', 'bc_interleave_', 'bc_trace_', 'cmp_scen_', 'cmp_issue_'];

    /** The start of the line a failed drop prints, which a parent counts in its children's output. */
    public const NOT_DROPPED = 'scratch database not dropped:';

    /** How many drops failed in this process, and in the children whose output it counted (countIn). */
    public static int $notDropped = 0;

    /** @var list<array{0: PDO, 1: string}> The databases this process made and has not dropped yet. */
    private static array $made = [];

    /** Records a database this process made, for dropMade() once the result is written. */
    public static function made(PDO $server, string $name): void
    {
        self::$made[] = [$server, $name];
    }

    /** Drops every database this process made and has not dropped, newest first: the cleanup, after the result. */
    public static function dropMade(): void
    {
        foreach (array_reverse(self::$made) as [$server, $name]) {
            self::drop($server, $name);
        }
    }

    /**
     * Drops one scratch database. A failure is reported and counted, never thrown: the result is already written.
     * BC_TEST_DROP_FAILS=1 is the seam that proves it: the drop is sent for a name the server refuses (the name with a
     * trailing space), so the database stays and the server's own reason is printed, as a stray file in its folder does.
     */
    public static function drop(PDO $server, string $name): bool
    {
        self::$made = array_values(array_filter(self::$made, static fn(array $m): bool => $m[1] !== $name));
        $target = getenv('BC_TEST_DROP_FAILS') === '1' ? "$name " : $name;
        try {
            $server->exec("DROP DATABASE IF EXISTS `$target`");
            return true;
        } catch (PDOException $e) {
            self::$notDropped++;
            fwrite(STDERR, self::NOT_DROPPED . " $name: " . $e->getMessage() . "\n");
            return false;
        }
    }

    /**
     * Adds the failed drops a child process reported in its output to this process's count, and repeats their lines
     * when $repeat (a parent that does not print its children's output), so every one is named where it is counted.
     */
    public static function countIn(string $output, bool $repeat = false): void
    {
        preg_match_all('/^' . preg_quote(self::NOT_DROPPED, '/') . '.*$/m', $output, $m);
        self::$notDropped += count($m[0]);
        foreach ($repeat ? $m[0] : [] as $line) {
            fwrite(STDERR, $line . "\n");
        }
    }

    /** The line every runner's summary carries: how many scratch databases it and its children could not drop. */
    public static function summary(): string
    {
        $n = self::$notDropped;
        return $n === 0
            ? 'scratch databases not dropped: 0'
            : "scratch databases not dropped: $n (named above; php server/tests/db/sweep.php lists them once they are old)";
    }
}
