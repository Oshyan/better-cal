<?php
// The product the harness checks (README, Other Products): this tree by
// default, or another checkout's server/src (harness.php --product DIR). A
// product without the series plan API (1.3.0, 1.4.0) gets the same edits
// sent the old way (Driver::applyEdit), its plan checks counted as "no plan",
// and its outcome checked against every option combination of the design's
// choices (Driver::matchOptions), since the old versions decided silently.
declare(strict_types=1);

namespace BetterCal\EditHarness;

use BetterCal\Infra\Db;

final class Product
{
    /** The checkout whose server/src is loaded. */
    public static string $root = '';

    /** Whether the product has the series plan API (Events::previewPatch): 1.5.0 on. */
    public static bool $plan = true;

    /**
     * Checks that read a plan (its preview, hash, groups, choices, preselections, data) or the plan's own guarantees,
     * by invariant name: without a plan API they are counted as "no plan", never as failures.
     */
    public const NO_PLAN = [
        'c-groups', 'c-readings', 'c-preselect', 'c-preselect-design', 'c-plan', 'c-shape', 'c-placement', 'c-next',
        'c-untouched', 'e-preview', 'i-google', 'e-google', 's-stale', 'H-isolation',
    ];

    public static function use(string $root): void
    {
        self::$root = $root;
        self::$plan = method_exists(\BetterCal\Domain\Events::class, 'previewPatch');
    }

    /** The account's default calendar: the product's own reading where it has one, else its oldest local calendar. */
    public static function defaultCalendarId(Db $db, int $userId): int
    {
        if (method_exists(\BetterCal\Domain\Settings::class, 'defaultCalendarId')) {
            return (int) \BetterCal\Domain\Settings::defaultCalendarId($db, $userId);
        }
        return (int) $db->scalar("SELECT id FROM calendars WHERE user_id = ? AND kind = 'local' ORDER BY id LIMIT 1", [$userId]);
    }

    /** The product's commit: HARNESS_REVISION, else HEAD read from its checkout's git files. */
    public static function commitOf(string $root): ?string
    {
        $git = $root . '/.git';
        if (is_file($git) && preg_match('/^gitdir: (.+)$/m', (string) file_get_contents($git), $m)) {
            $git = trim($m[1]);
        }
        $head = @file_get_contents($git . '/HEAD');
        if ($head === false) {
            return null;
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
        }
        return null;
    }

    /** The product's tallies from the merged counts: how each case's outcome matched the options, no-plan skips, not applicable. */
    public static function summary(array $counts): array
    {
        return [
            'plan' => self::$plan,
            'root' => basename(self::$root),
            'match' => $counts['product']['match'] ?? [],
            'otherOption' => $counts['product']['other'] ?? [],
            'noOption' => $counts['product']['none'] ?? [],
            'noPlan' => $counts['product']['noPlan'] ?? [],
            'notApplicable' => $counts['product']['na'] ?? [],
            'combos' => $counts['product']['combos'] ?? [],
            'target' => $counts['product']['target'] ?? [],
            'matchOnRule' => $counts['product']['onRule'] ?? [],
        ];
    }

    public static function text(array $s): string
    {
        $out = 'Product ' . $s['root'] . ($s['plan'] ? ' (plan API)' : ' (no plan API: the same edits sent the old way)') . "\n";
        $line = static function (string $title, array $map) use (&$out): void {
            if ($map === []) {
                return;
            }
            arsort($map);
            $out .= $title . ":\n";
            foreach ($map as $k => $n) {
                $out .= sprintf("  %5d  %s\n", $n, $k);
            }
        };
        $line('Edits by how the outcome matched the design\'s options', $s['match']);
        $line('The same, for series created on a day of their rule', $s['matchOnRule']);
        $line('Matching another option than the preselected one (the option that matched)', $s['otherOption']);
        $line('Matching no option (the preselected combination\'s failing checks)', $s['noOption']);
        $line('Edits sent to', $s['target']);
        $line('Plan checks not run (no plan)', $s['noPlan']);
        $line('Not applicable to this product', $s['notApplicable']);
        return $out;
    }
}
