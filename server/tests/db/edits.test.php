<?php

declare(strict_types=1);

// A seeded slice of the repeating-series edit harness (tools/edit-harness):
// random series with skipped and edited days, random edits through Events
// and Undo, invariants checked against the rules. It fails on a failure
// signature the harness's known root causes don't cover (a regression or a
// new defect); the known ones are counted and listed. The full run, with
// shrinking, is tools/edit-harness/run.sh.

use BetterCal\EditHarness\Known;
use BetterCal\EditHarness\Runner;
use BetterCal\Infra\Db;

require dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    scenario('edit harness: a seeded slice', function () use ($db): void {
        $runner = new Runner($db, domains($db), 7);
        $runner->run(200);
        $bySignature = [];
        foreach ($runner->failures as $f) {
            $bySignature[Runner::signature($f)][] = $f;
        }
        $unknown = [];
        $known = [];
        foreach ($bySignature as $sig => $list) {
            $cause = Known::rootCause($sig);
            if ($cause === null) {
                $unknown[] = count($list) . ' x ' . $sig . ': ' . $list[0]['what'];
            } else {
                $known[$cause] = ($known[$cause] ?? 0) + count($list);
            }
        }
        checkEq('edit harness: 200 cases ran', 200, $runner->counts['cases']);
        check('edit harness: every failure is under a known root cause (tools/edit-harness/README.md)', $unknown === [], implode("\n  ", $unknown));
    });
};
