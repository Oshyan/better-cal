<?php
// Property harness for repeating-series edits: random series with edited
// and skipped days from the design's tables, random edits as vectors of
// deltas through the real domain code (Events, the plan API, Undo) against
// a migrated database, checked against the expander and the harness's own
// reading of the design. See README.md. harness.php runs it from the
// command line; server/tests/db/edits.test.php runs a seeded slice.
declare(strict_types=1);

namespace BetterCal\EditHarness;

use BetterCal\Domain\Settings;
use BetterCal\Infra\Db;

require_once __DIR__ . '/Base.php';
require_once __DIR__ . '/Shape.php';
require_once __DIR__ . '/Truth.php';
require_once __DIR__ . '/Space.php';
require_once __DIR__ . '/Delta.php';
require_once __DIR__ . '/Expect.php';
require_once __DIR__ . '/Invariants.php';
require_once __DIR__ . '/Driver.php';

/**
 * What is known, by code path, never by invariant name or edit signature
 * (README, Known Code Paths).
 */
final class Known
{
    /** The paths that stay on their 1.4.6 code until 1.5.1, decided from what the case sent (scope, and a day after the first). */
    public const PATHS = ['following', 'deleteFollowing', 'attendFollowing'];

    public static function cause(array $failure): ?string
    {
        if (in_array($failure['path'], self::PATHS, true)) {
            return 'following-1.4.6 (the 1.4.6 following or delete-following path)';
        }
        return null;
    }
}

/** Runs cases over a database (shared by harness.php and the database suite). */
final class Runner
{
    public array $counts = ['cases' => 0, 'byFamily' => [], 'byPath' => [], 'failures' => [], 'unoracled' => []];
    /** @var list<array> */
    public array $failures = [];

    public function __construct(private readonly Db $db, private readonly \Domains $domains, private readonly int $seed)
    {
    }

    /** A fresh account every $perUser cases keeps the journal short. */
    public function run(int $cases, ?string $onlyFamily = null, ?int $onlyCase = null, int $perUser = 150, ?callable $progress = null): void
    {
        $driver = null;
        for ($i = 1; $i <= $cases; $i++) {
            if ($onlyCase !== null && $i !== $onlyCase) {
                continue;
            }
            if ($driver === null || ($i - 1) % $perUser === 0) {
                $driver = $this->driver();
            }
            $r = new Rng($this->seed * 1_000_003 + $i);
            $spec = ['n' => $i, 'seed' => $this->seed, 'series' => Space::series($r, $onlyFamily)];
            $result = $driver->run($spec, $r);
            $this->count($result);
            if ($progress !== null) {
                $progress($i, $result);
            }
        }
    }

    private function driver(): Driver
    {
        $u = \freshUser($this->db);
        return new Driver($this->db, $this->domains->events, $this->domains->undo, $u, (int) Settings::defaultCalendarId($this->db, $u));
    }

    public function count(array $result): void
    {
        $spec = $result['spec'];
        $family = $spec['series']['family'];
        $path = $result['path'];
        $this->counts['cases']++;
        $this->counts['byFamily'][$family] = ($this->counts['byFamily'][$family] ?? 0) + 1;
        $this->counts['byPath'][$path] = ($this->counts['byPath'][$path] ?? 0) + 1;
        foreach ($result['unoracled'] as $u) {
            $this->counts['unoracled'][$u] = ($this->counts['unoracled'][$u] ?? 0) + 1;
        }
        foreach ($result['records'] as $rec) {
            $this->counts['failures'][$rec['invariant']] = ($this->counts['failures'][$rec['invariant']] ?? 0) + 1;
            $this->failures[] = ['n' => $spec['n'], 'seed' => $spec['seed'], 'family' => $family, 'path' => $path, 'deltas' => isset($spec['vector']) ? Delta::nonZero($spec['vector']) : []] + $rec + ['spec' => $spec];
        }
    }

    /** A failure's signature, for one reproduction each: invariant, family, path. */
    public static function signature(array $failure): string
    {
        return $failure['invariant'] . ' | ' . $failure['family'] . ' | ' . $failure['path'];
    }

    /**
     * Shrink a failing case on its delta vector: drop the second edit, then
     * each edited or skipped day, then zero one delta at a time, keeping
     * each step that still fails the same invariant on the same path.
     */
    public function shrink(array $failure, int $budget = 18): array
    {
        $inv = $failure['invariant'];
        $path = $failure['path'];
        $driver = $this->driver();
        $best = ['spec' => $failure['spec'], 'records' => [array_diff_key($failure, ['spec' => 1])], 'path' => $path, 'unoracled' => []];
        $try = function (array $candidate) use (&$budget, $driver, $inv, $path, &$best): bool {
            if ($budget <= 0) {
                return false;
            }
            $budget--;
            unset($candidate['request'], $candidate['create']);
            try {
                $result = $driver->run($candidate, new Rng(0));
            } catch (\Throwable) {
                return false;
            }
            foreach ($result['records'] as $rec) {
                if ($rec['invariant'] === $inv && $result['path'] === $path) {
                    $best = $result;
                    return true;
                }
            }
            return false;
        };
        $cur = $failure['spec'];
        if (!empty($cur['stack'])) {
            $c = $cur;
            $c['stack'] = null;
            if ($try($c)) {
                $cur = $c;
            }
        }
        for ($i = count($cur['exceptions'] ?? []) - 1; $i >= 0; $i--) {
            $c = $cur;
            array_splice($c['exceptions'], $i, 1);
            if ($try($c)) {
                $cur = $c;
            }
        }
        foreach (Delta::ZEROABLE as $d) {
            $z = Delta::zero($cur['vector'], $d);
            if ($z === null) {
                continue;
            }
            $c = $cur;
            $c['vector'] = $z;
            if ($try($c)) {
                $cur = $c;
            }
        }
        $days = (int) $cur['vector']['date'];
        foreach ([1, 2, 7, 28, 30, 31] as $d) {
            $d *= $days < 0 ? -1 : 1;
            if ($days === 0 || abs($d) >= abs($days)) {
                break;
            }
            $c = $cur;
            $c['vector']['date'] = $d;
            if ($try($c)) {
                break;
            }
        }
        return $best;
    }
}
