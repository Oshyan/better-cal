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
    // Two cases the app gets right that the oracle once failed, reading an
    // observed window as the whole series: a skipped day just past the
    // window of an unbounded daily series, and a leap-day yearly series
    // whose COUNT runs past the window, split partway.
    scenario('edit harness: days beyond the observed window', function () use ($db): void {
        foreach ([41, 2769] as $case) {
            $runner = new Runner($db, domains($db), 2);
            $runner->run($case, null, null, $case);
            checkEq("edit harness: seed 2 case $case ran and has no failing check", [1, []], [
                $runner->counts['cases'],
                array_map(static fn(array $f): string => Runner::signature($f) . ': ' . $f['what'], $runner->failures),
            ]);
        }
    });
    // An all-day every-other-Friday series dragged by no days and no time:
    // the app writes nothing, so its stored UNTIL stays a valid bound, which
    // the oracle once insisted be rewritten to the last occurrence.
    scenario('edit harness: a no-op drag keeps its bound', function () use ($db): void {
        $runner = new Runner($db, domains($db), 3);
        $runner->run(2218, null, null, 2218);
        checkEq('edit harness: seed 3 case 2218 ran and has no failing check', [1, []], [
            $runner->counts['cases'],
            array_map(static fn(array $f): string => Runner::signature($f) . ': ' . $f['what'], $runner->failures),
        ]);
    });
};
