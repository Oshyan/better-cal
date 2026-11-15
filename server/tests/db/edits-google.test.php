<?php

declare(strict_types=1);

// A seeded slice of the repeating-series edit harness (tools/edit-harness) on a Google calendar, a file of its own so
// the suite runs it beside edits.test.php. Seed 11 is a draw that neither CI nor tools/gates.sh full runs (they run the
// harness's Google mode on seed 7), so these 40 cases are the suite's own.

use BetterCal\EditHarness\Runner;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    // A slice on a Google calendar served by the probe's stand-in (README,
    // Google): every edit goes to Google through GoogleSeries, what the
    // stand-in holds after each equals the rows here, and a poll changes
    // nothing (8-google). Undo there is the undo stage's, not applicable.
    scenario('edit harness: a seeded slice on a Google calendar', function () use ($db): void {
        $runner = new Runner($db, domains($db), 11, true);
        $runner->run(40);
        $unknown = [];
        foreach ($runner->failures as $f) {
            $sig = Runner::signature($f);
            $unknown[$sig] ??= $sig . " (first: seed 11 case {$f['n']}): " . $f['what'] . ' ' . json_encode($f['detail'] ?? null);
        }
        checkEq('edit harness (Google): 40 drawn cases ran', 40, $runner->counts['cases']);
        check('edit harness (Google): every failure is a finding (none expected)', $unknown === [], implode("\n  ", array_values($unknown)));
    });
};
