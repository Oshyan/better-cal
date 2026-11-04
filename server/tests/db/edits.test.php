<?php

declare(strict_types=1);

// A seeded slice of the repeating-series edit harness (tools/edit-harness):
// random series from the design's tables with skipped and edited days,
// random edits as vectors of deltas through Events, the plan API and Undo,
// checked against the expander and the harness's own reading of the
// design. It fails on any failure outside the known code paths (the 1.4.6
// "this and following", delete-following and attendance-following paths
// until 1.5.1) and lists each; the known ones are counted. The full run,
// with shrinking, is tools/edit-harness/run.sh.

use BetterCal\EditHarness\Known;
use BetterCal\EditHarness\Runner;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    scenario('edit harness: a seeded slice', function () use ($db): void {
        $runner = new Runner($db, domains($db), 7);
        $runner->run(200);
        $unknown = [];
        $count = [];
        $known = [];
        foreach ($runner->failures as $f) {
            $cause = Known::cause($f);
            if ($cause === null) {
                $sig = Runner::signature($f);
                $count[$sig] = ($count[$sig] ?? 0) + 1;
                $unknown[$sig] ??= $sig . ' (first: seed 7 case ' . $f['n'] . '): ' . $f['what'];
            } else {
                $known[$cause] = ($known[$cause] ?? 0) + 1;
            }
        }
        foreach ($unknown as $sig => $line) {
            $unknown[$sig] = $count[$sig] . ' x ' . $line;
        }
        checkEq('edit harness: 200 cases ran', 200, $runner->counts['cases']);
        foreach ($known as $cause => $n) {
            echo "    known: $n x $cause\n";
        }
        check('edit harness: every failure is under a known code path (tools/edit-harness/README.md)', $unknown === [], implode("\n  ", array_values($unknown)));
    });

    // A whole-series edit of a fourth-Monday series whose UNTIL is the 1st
    // of a month the rule doesn't give: the expander once yielded that 1st
    // as an occurrence. Read as the rule's days, the case has no failure.
    scenario('edit harness: a monthly UNTIL on a 1st the rule does not give is no occurrence', function () use ($db): void {
        $runner = new Runner($db, domains($db), 19);
        $runner->run(914, null, 914);
        checkEq('edit harness: seed 19 case 914 ran on the whole-series path', [1, ['all' => 1]], [$runner->counts['cases'], $runner->counts['byPath']]);
        check('edit harness: seed 19 case 914 has no failure', $runner->failures === [],
            implode("\n  ", array_map(static fn(array $f): string => Runner::signature($f) . ': ' . $f['what'], $runner->failures)));
    });
};
