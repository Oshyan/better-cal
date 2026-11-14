<?php

declare(strict_types=1);

// The run's hidden-days check comes after the check that Google still holds what the change was planned from (the
// second independent read of the 1.5.6 branch, "Behaviour changes no sentence claimed"). The rule: a change planned
// from the rows here, when Google holds something else since the last poll, is refused as changed at Google since,
// before anything else is judged; what Google would hide is judged only over a series that is as the change was
// planned from. A weekly 10:00 Los Angeles series in winter; at Google another client moves it to Sundays at 16:00 (00:00 UTC
// on the Mondays) and renames one of its days there, whose record then sits at the instant of a Monday all-day day. Here, not yet polled, the series is made all-day for all events:
// the planner sees 10:00 and nothing hidden; the run must say the event changed at Google. Invented data.

use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $weekly = ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=4'];

    scenario('changed at Google since, and hidden there: the change is refused as changed at Google since, nothing sent', function () use ($db, $weekly, $la): void {
        $t = H::make($db, $weekly);
        try {
            $at = static fn(string $l): array => ['dateTime' => (new DateTimeImmutable($l, new DateTimeZone($la)))->format('Y-m-d\TH:i:sP'), 'timeZone' => $la];
            [$s1] = H::atGoogle($t, 'PATCH', '/events/' . rawurlencode($t->gid), FakeGoogle::asSet(['start' => $at('2027-01-03 16:00'), 'end' => $at('2027-01-03 17:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4']]));
            [$s2] = H::atGoogle($t, 'PATCH', '/events/' . rawurlencode($t->gid . '_20270111T000000Z'), ['summary' => 'Sample series (renamed at Google)']);
            checkEq('the other client\'s two writes were taken', [200, 200], [$s1, $s2]);
            $before = count($t->s->fake->requests);
            [, $error] = H::tryChange($t, ['scope' => 'all', 'instanceStart' => '2027-01-04T10:00:00-08:00', 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true]);
            checkEq('refused as changed at Google since, nothing sent, nothing half done', [true, [], []],
                [$error !== null && str_contains($error, 'google_conflict'), H::writes($t, $before), H::halfDone($t)]);
        } finally {
            H::done($t);
        }
    });
};
