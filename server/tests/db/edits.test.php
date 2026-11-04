<?php

declare(strict_types=1);

// A seeded slice of the repeating-series edit harness (tools/edit-harness):
// random series from the design's tables with skipped and edited days,
// random edits as vectors of deltas through Events, the plan API and Undo,
// checked against the expander and the harness's own reading of the
// design, then the harness's fixed cases. It fails on any failure outside
// the known code paths (the 1.4.6 "this and following", delete-following
// and attendance-following paths until 1.5.1) and lists each; the known ones
// are counted. A failure of a check of the plan's data (c-shape,
// c-placement, c-next, c-untouched) is never under a known path, and the
// slice and the fixed cases together must run every required category of
// those checks. The full run, with shrinking, is tools/edit-harness/run.sh.

use BetterCal\EditHarness\Known;
use BetterCal\EditHarness\Report;
use BetterCal\EditHarness\Runner;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    scenario('edit harness: a seeded slice and the fixed cases', function () use ($db): void {
        $runner = new Runner($db, domains($db), 7);
        $runner->run(200);
        $runner->runFixed();
        $unknown = [];
        $count = [];
        foreach ($runner->failures as $f) {
            if (Known::cause($f) === null) {
                $sig = Runner::signature($f);
                $count[$sig] = ($count[$sig] ?? 0) + 1;
                $where = isset($f['spec']['fixed']) ? 'fixed case ' . $f['spec']['fixed'] : 'seed 7 case ' . $f['n'];
                $unknown[$sig] ??= $sig . " (first: $where, revision {$f['revision']}): " . $f['what'];
            }
        }
        foreach ($unknown as $sig => $line) {
            $unknown[$sig] = $count[$sig] . ' x ' . $line;
        }
        $t = Report::tally($runner);
        checkEq('edit harness: 200 drawn cases and every fixed case ran', 200 + count(Runner::fixedFiles()), $runner->counts['cases']);
        foreach ($t['byCause'] as $cause => $n) {
            echo "    known: $n x $cause\n";
        }
        echo '    ' . str_replace("\n", "\n    ", rtrim(Report::summaryText($t))) . "\n";
        check('edit harness: every failure is under a known code path (tools/edit-harness/README.md)', $unknown === [], implode("\n  ", array_values($unknown)));
        checkEq('edit harness: no check of the plan\'s data fails on a case under a known path', 0, $t['newOnKnown']);
        checkEq('edit harness: every required category of the plan\'s data checks ran', [], $t['missing']);
    });

    // A whole-series edit of a first-Monday series whose UNTIL is the 1st of
    // a month the rule doesn't give: the expander once yielded that 1st as an
    // occurrence. Read as the rule's days, the case has no failure.
    scenario('edit harness: a monthly UNTIL on a 1st the rule does not give is no occurrence', function () use ($db): void {
        $runner = new Runner($db, domains($db), 19);
        $runner->runFixed(['monthly-until-on-a-first-the-rule-skips']);
        checkEq('edit harness: the case ran on the whole-series path', [1, ['all' => 1]], [$runner->counts['cases'], $runner->counts['byPath']]);
        check('edit harness: the case has no failure', $runner->failures === [],
            implode("\n  ", array_map(static fn(array $f): string => Runner::signature($f) . ': ' . $f['what'], $runner->failures)));
    });

    // A known limitation (README, Known, Accepted Differences): an all-day
    // series stored the old way, at local midnights in an eastern zone, reads
    // as UTC dates a day early in the model and the harness alike. The case
    // pins what happens today, so a change to it is seen.
    scenario('edit harness: a legacy all-day series at local midnights reads a day early', function () use ($db): void {
        $runner = new Runner($db, domains($db), 0);
        $runner->runFixed([dirname(__DIR__, 3) . '/tools/edit-harness/cases/limitations/legacy-all-day-series-at-local-midnights.json']);
        $got = array_values(array_unique(array_column($runner->failures, 'invariant')));
        sort($got);
        checkEq('edit harness: the legacy case fails as documented (its start read a day early, off its rule, and the occurrence asked for not found)', ['H-evaluator', 'api-crash', 'g-created'], $got);
    });
};
