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
require_once __DIR__ . '/Rfc.php';
require_once __DIR__ . '/Truth.php';
require_once __DIR__ . '/Space.php';
require_once __DIR__ . '/Delta.php';
require_once __DIR__ . '/Expect.php';
require_once __DIR__ . '/Invariants.php';
require_once __DIR__ . '/PlanChecks.php';
require_once __DIR__ . '/PlanPlacement.php';
require_once __DIR__ . '/PlanNext.php';
require_once __DIR__ . '/PlanUntouched.php';
require_once __DIR__ . '/Driver.php';

/**
 * What is known, by code path, never by invariant name or edit signature
 * (README, Known Code Paths).
 */
final class Known
{
    /** The paths that stay on their 1.4.6 code until 1.5.1, decided from what the case sent (scope, and a day after the first). */
    public const PATHS = ['following', 'deleteFollowing', 'attendFollowing'];

    /** The accepted causes beside the paths, each decided until it is fixed (README, Known Code Paths). */
    public const LORD_HOWE_BYHOUR = 'lord-howe-byhour (the expander\'s hour-by-hour stepping in a zone whose clocks move by half an hour, #202, until 1.5.1)';
    public const FOLLOWING = 'following-1.4.6 (the 1.4.6 following or delete-following path)';

    public static function cause(array $failure): ?string
    {
        $series = $failure['spec']['series'] ?? [];
        // A series in Lord Howe naming its hour: the expander's BYHOUR clock drifts half an hour after the zone's change, deferred to 1.5.1 (#202).
        // Read from the series as drawn, or, for an occurrence check, from the series it compared (an edit can bring a series there).
        $d = $failure['detail'] ?? [];
        $lordHowe = static fn(?string $tz, ?string $rule): bool => $tz === 'Australia/Lord_Howe' && str_contains(strtoupper((string) $rule), 'BYHOUR');
        if (($lordHowe($series['tzid'] ?? null, $series['rrule'] ?? null) && !($series['allDay'] ?? false)) || $lordHowe($d['tz'] ?? null, $d['rrule'] ?? null)) {
            return self::LORD_HOWE_BYHOUR;
        }
        $following = in_array($failure['path'] ?? '', self::PATHS, true);
        // The following path writing a part several times a day as all-day (MINUTELY, HOURLY on a date), which the evaluator
        // doesn't read: the legacy path until 1.5.1, under its cause.
        $subDaily = preg_match('/(^|;)FREQ=(HOURLY|MINUTELY|SECONDLY)(;|$)/', strtoupper((string) ($d['rrule'] ?? ''))) === 1;
        if ($following && $subDaily && $failure['invariant'] === 'c-occurrences' && ($d['why'] ?? '') === 'a clock part on an all-day series') {
            return self::FOLLOWING;
        }
        // Any other failure of a check of the plan's data or the occurrences is never excused (README, Known Code Paths).
        if (in_array($failure['invariant'], Driver::NEW_CHECKS, true)) {
            return null;
        }
        return $following ? self::FOLLOWING : null;
    }
}

/**
 * The tallies a run reports and fails on (README, Reporting): failures
 * outside the known paths, accepted ones by cause, failures of the plan's
 * data checks on known-path cases (never accepted), unoracled and skipped
 * cases, executions of each check by role and outcome, and the required
 * categories that ran zero times.
 */
final class Report
{
    /** Categories of the plan's data checks a run must execute at least once (a full run draws them; the CI slice adds the fixed cases). */
    public const REQUIRED = [
        'c-shape' => ['plan', 'refused plan', 'key', 'date', 'bound instant', 'zone-qualified', 'placement', 'all-day placement', 'timed entry', 'all-day entry', 'plan.zone', 'notes[].tz', 'plan.start (anchored)', 'plan.start (timed)', 'plan.start (all-day)'],
        'c-placement' => ['outcome (moves)', 'outcome (stays)', 'outcome (separate)', 'outcome (rejoins)', 'outcome (deleted)', 'outcome (acted row moves)', 'outcome (acted separate)',
            'ownDate (moves)', 'shows (skipped moves)', 'shows (skipped stays)', 'to (skipped moves)', 'to (generated)', 'movedDays chosen', 'movedDays other', 'plan.acted before',
            'losses member', 'losses complete', 'gains member', 'gains complete', 'bound.last', 'bound.from.last', 'bound.until', 'bound.from.until',
            'reading earlier', 'reading dates', 'notes (overlap)', 'notes (gap)', 'notes (overlap) present', 'notes (gap) present', 'plan.start', 'rule.to.start', 'rule.from.start'],
        'c-next' => ['next (timed plan)', 'next (all-day plan)', 'next (timed plan, new rule)', 'nextMore true', 'nextMore false', 'unreadable next',
            'entry occurrence', 'entry edited', 'entry acted', 'entry edited, own zone', 'chosen reading next'],
        'c-untouched' => ['master', 'edited row stays', 'edited row leaves', 'edited row goes', 'control, same calendar', 'control, another calendar', 'insertion', 'calendar', 'link'],
    ];

    public static function tally(Runner $runner): array
    {
        $byCause = [];
        $unexpected = 0;
        $newOnKnown = 0;
        foreach ($runner->failures as $f) {
            $cause = Known::cause($f);
            if ($cause === null) {
                $unexpected++;
            } else {
                $byCause[$cause] = ($byCause[$cause] ?? 0) + 1;
            }
            if ($cause === null && in_array($f['invariant'], Driver::NEW_CHECKS, true) && in_array($f['path'], Known::PATHS, true)) {
                $newOnKnown++;
            }
        }
        $missing = [];
        foreach (self::REQUIRED as $check => $roles) {
            foreach ($roles as $role) {
                if (($runner->counts['runs'][$check][$role] ?? 0) === 0) {
                    $missing[] = "$check | $role";
                }
            }
        }
        return [
            'unexpected' => $unexpected, 'byCause' => $byCause, 'newOnKnown' => $newOnKnown,
            'unoracled' => array_sum($runner->counts['unoracled']), 'skipped' => $runner->counts['skipped'] ?? 0,
            'runs' => $runner->counts['runs'] ?? [], 'missing' => $missing,
        ];
    }

    /** The executions of the plan-data checks, by role and outcome. */
    public static function runsText(array $t): string
    {
        $out = "Executions of the plan-data checks, by role and outcome:\n";
        foreach (Driver::NEW_CHECKS as $check) {
            $roles = $t['runs'][$check] ?? [];
            ksort($roles);
            $out .= sprintf("  %-12s %7d in all\n", $check, array_sum($roles));
            foreach ($roles as $role => $n) {
                $out .= sprintf("    %7d  %s\n", $n, $role);
            }
        }
        return $out;
    }

    /** The tallies, a few lines. */
    public static function summaryText(array $t): string
    {
        $runs = array_map(static fn(array $r): int => array_sum($r), $t['runs']);
        $out = sprintf("Unexpected failures: %d. Plan-data check failures on known-cause cases: %d. Unoracled parts: %d. Skipped cases: %d.\n",
            $t['unexpected'], $t['newOnKnown'], $t['unoracled'], $t['skipped']);
        $out .= 'Accepted by known cause: ' . ($t['byCause'] === [] ? 'none' : implode('; ', array_map(static fn(string $c, int $n): string => "$n $c", array_keys($t['byCause']), $t['byCause']))) . ".\n";
        $out .= 'Plan-data checks run: ' . implode(', ', array_map(static fn(string $c): string => $c . ' ' . ($runs[$c] ?? 0), Driver::NEW_CHECKS)) . ".\n";
        $out .= $t['missing'] === [] ? "Every required category ran.\n" : 'Required categories that never ran (' . count($t['missing']) . '): ' . implode('; ', $t['missing']) . "\n";
        return $out;
    }
}

/** The revision a record was made at: the checkout's commit, and the series model's content as loaded (a mutation runs against a copy). */
final class Revision
{
    private static ?string $rev = null;

    public static function get(): string
    {
        if (self::$rev === null) {
            $model = (new \ReflectionClass(\BetterCal\Domain\Series\Model::class))->getFileName();
            self::$rev = self::commit() . '+model:' . substr((string) sha1_file((string) $model), 0, 10);
        }
        return self::$rev;
    }

    /** HARNESS_REVISION, else HEAD read from the checkout's git files (a worktree's too), else "unknown". */
    private static function commit(): string
    {
        if (getenv('HARNESS_REVISION') !== false) {
            return (string) getenv('HARNESS_REVISION');
        }
        $git = dirname(__DIR__, 2) . '/.git';
        if (is_file($git) && preg_match('/^gitdir: (.+)$/m', (string) file_get_contents($git), $m)) {
            $git = trim($m[1]);
        }
        $head = @file_get_contents($git . '/HEAD');
        if ($head === false) {
            return 'unknown';
        }
        if (!preg_match('/^ref: (.+)$/m', $head, $m)) {
            return substr(trim($head), 0, 10);
        }
        $common = is_file($git . '/commondir') ? $git . '/' . trim((string) file_get_contents($git . '/commondir')) : $git;
        foreach ([$git, $common] as $dir) {
            $ref = @file_get_contents($dir . '/' . trim($m[1]));
            if ($ref !== false) {
                return substr(trim($ref), 0, 10);
            }
            $packed = @file_get_contents($dir . '/packed-refs');
            if ($packed !== false && preg_match('/^([0-9a-f]{40}) ' . preg_quote(trim($m[1]), '/') . '$/m', $packed, $p)) {
                return substr($p[1], 0, 10);
            }
        }
        return 'unknown';
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
        // The evaluator every check reads must give its hand-listed examples first (rfc-examples.php): a run on a wrong oracle stops.
        $bad = Rfc::examples();
        if ($bad !== []) {
            throw new \RuntimeException('the harness\'s rule evaluator disagrees with ' . count($bad) . ' of its hand-listed examples: ' . json_encode($bad[0], JSON_UNESCAPED_SLASHES));
        }
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

    /**
     * The fixed cases (tools/edit-harness/cases/*.json, or the files named):
     * complete specs, each run as drawn, on one account per file set.
     *
     * @param list<string> $files
     */
    public function runFixed(array $files = []): void
    {
        $files = $files !== [] ? $files : self::fixedFiles();
        $driver = $this->driver();
        foreach ($files as $file) {
            // A case is named by its path, or by its name under cases/.
            if (!is_file($file)) {
                $name = preg_replace('/\.json$/', '', $file);
                $file = is_file(__DIR__ . "/cases/$name.json") ? __DIR__ . "/cases/$name.json" : __DIR__ . "/mutations/cases/$name.json";
            }
            $spec = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $spec['fixed'] = basename($file, '.json');
            $result = $driver->run($spec, new Rng(0));
            $this->count($result);
        }
    }

    /** @return list<string> */
    public static function fixedFiles(): array
    {
        $files = glob(__DIR__ . '/cases/*.json') ?: [];
        sort($files);
        return $files;
    }

    private function driver(): Driver
    {
        $u = \freshUser($this->db);
        $other = (int) $this->domains->calendars->create($u, ['name' => 'Sample other calendar'])['id'];
        return new Driver($this->db, $this->domains->events, $this->domains->undo, $u, (int) Settings::defaultCalendarId($this->db, $u), $other);
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
        if ($path === 'none') {
            $this->counts['skipped'] = ($this->counts['skipped'] ?? 0) + 1;
        }
        foreach ($result['runs'] ?? [] as $check => $byRole) {
            foreach ($byRole as $role => $n) {
                $this->counts['runs'][$check][$role] = ($this->counts['runs'][$check][$role] ?? 0) + $n;
            }
        }
        foreach ($result['records'] as $rec) {
            $this->counts['failures'][$rec['invariant']] = ($this->counts['failures'][$rec['invariant']] ?? 0) + 1;
            $this->failures[] = ['n' => $spec['n'], 'seed' => $spec['seed'], 'revision' => Revision::get(), 'family' => $family, 'path' => $path, 'deltas' => isset($spec['vector']) ? Delta::nonZero($spec['vector']) : []] + $rec + ['spec' => $spec];
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
