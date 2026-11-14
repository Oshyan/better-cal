<?php

declare(strict_types=1);

// "This and following" made all-day or timed on a Google calendar (the second independent read of the 1.5.6 branch,
// F3; its probe probe2-fix-split-switch made a test). The days from the split on become a series of their own,
// inserted at Google under a new id, which holds no record of the old series; the old series only gets an end. No
// series Google holds changes between timed and all-day, so by the evidence's rule (probe 2026-11-13, 3.48 to 3.51: a
// switch of a series hides a day of its new form at the instant of one of its own records) nothing can be hidden, and
// the change goes through. A daily 20:00 New York series in July is 00:00 UTC, where a change of the whole series to
// all-day is refused; the control shows that refusal stands for all events. Expectations by date. Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $ny = 'America/New_York';
    $daily = ['start' => '2027-07-05T20:00:00-04:00', 'end' => '2027-07-05T21:00:00-04:00', 'tzid' => $ny, 'rrule' => 'FREQ=DAILY;COUNT=10'];
    $following = ['scope' => 'following', 'instanceStart' => '2027-07-07T20:00:00-04:00', 'start' => '2027-07-07', 'end' => '2027-07-08', 'allDay' => true];
    $codes = static fn(array $plan): array => array_values(array_unique(array_column($plan['refusals'] ?? [], 'code')));

    scenario('split to all-day over a renamed day at 00:00 UTC: no hidden-days refusal, the change goes through, every day shows here and at Google', function () use ($db, $daily, $following, $codes, $ny): void {
        $t = H::make($db, $daily);
        try {
            H::this($t, '2027-07-08T20:00:00-04:00', ['title' => 'Sample series (own)']);
            $plan = $t->e->previewPatch($t->u, $t->id, $following + ['plan' => ['choices' => []]]);
            checkEq('the preview carries no refusal', [], $codes($plan));
            [, $error] = H::tryChange($t, $following);
            checkEq('the change goes through, nothing half done', [null, []], [$error, H::halfDone($t)]);
            $want = ['2027-07-05 20:00|Sample series||', '2027-07-06 20:00|Sample series||', '2027-07-07 all-day|Sample series||', '2027-07-08 all-day|Sample series (own)||',
                '2027-07-09 all-day|Sample series||', '2027-07-10 all-day|Sample series||', '2027-07-11 all-day|Sample series||', '2027-07-12 all-day|Sample series||',
                '2027-07-13 all-day|Sample series||', '2027-07-14 all-day|Sample series||'];
            checkEq('here, by date: two timed days, then eight all-day, the renamed one keeping its title', $want, H::hereView($t, $ny, '2027-07-01', '2027-07-20'));
            checkEq('at Google, the same', $want, H::googleView($t, $ny));
        } finally {
            H::done($t);
        }
    });

    scenario('control: the same series made all-day for all events is refused for the day Google would hide', function () use ($db, $daily, $codes): void {
        $t = H::make($db, $daily);
        try {
            H::this($t, '2027-07-08T20:00:00-04:00', ['title' => 'Sample series (own)']);
            $plan = $t->e->previewPatch($t->u, $t->id, ['scope' => 'all', 'instanceStart' => '2027-07-05T20:00:00-04:00', 'start' => '2027-07-05', 'end' => '2027-07-06', 'allDay' => true, 'plan' => ['choices' => []]]);
            checkEq('refused: googleHidden', ['googleHidden'], $codes($plan));
        } finally {
            H::done($t);
        }
    });
};
