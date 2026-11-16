<?php

declare(strict_types=1);

// A Finish or Put back refused for days Google would hide gives advice the person can follow from a half-done change
// (the second independent read of the 1.5.6 branch, F5; its probe probe2-fix-putback-hidden made a test). The rule: a
// half-done change takes no other change until it is finished or put back, so a refusal there names the other of the
// two first, never a new change alone. A daily series at 20:00 New York in July (00:00 UTC the day after), its last day
// renamed: made all-day, nothing is hidden (no day follows the last). The switch stops once the renamed day's write is
// taken. Put back would make it timed at 20:00 again, and the renamed day's all-day record (13 July, 00:00 UTC) is at
// the instant of 12 July's 20:00 day, so Put back is refused; Finish completes with the renamed day kept. Invented data.

use BetterCal\Domain\GoogleSeries;
use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $ny = 'America/New_York';
    $daily = ['start' => '2027-07-05T20:00:00-04:00', 'end' => '2027-07-05T21:00:00-04:00', 'tzid' => $ny, 'rrule' => 'FREQ=DAILY;COUNT=8'];
    $toAllDay = ['scope' => 'all', 'instanceStart' => '2027-07-05T20:00:00-04:00', 'start' => '2027-07-05', 'end' => '2027-07-06', 'allDay' => true];

    scenario('hidden at Put back: the switch stopped after the renamed day was written, Put back refused naming Finish, Finish completes', function () use ($db, $daily, $toAllDay, $ny): void {
        $t = H::make($db, $daily);
        try {
            H::this($t, '2027-07-12T20:00:00-04:00', ['title' => 'Sample series (own)']);
            $t->s->knobs->loseAt = $t->s->knobs->writes + 2; // the renamed day's write is taken and its answer lost
            [, $error] = H::tryChange($t, $toAllDay);
            $t->s->knobs->loseAt = null;
            checkEq('stopped half done', [true, 1], [$error !== null, count(H::halfDone($t))]);
            [, $putBack] = H::recover($t, 'putback');
            check('Put back is refused, saying to finish the change first, never a new change alone',
                $putBack !== null && str_contains($putBack, 'Finish the change instead; afterwards, make it timed at another time first, then change the time to 20:00.')
                && !str_contains($putBack, '. Make it timed at another time first'), (string) $putBack);
            [, $finish] = H::recover($t, 'finish');
            checkEq('Finish completes, nothing half done', [null, []], [$finish, H::halfDone($t)]);
            $view = H::hereView($t, $ny, '2027-07-01', '2027-07-20');
            checkEq('every day all-day from 5 to 12 July, the renamed one keeping its title', ['2027-07-05 all-day|Sample series||', '2027-07-06 all-day|Sample series||', '2027-07-07 all-day|Sample series||',
                '2027-07-08 all-day|Sample series||', '2027-07-09 all-day|Sample series||', '2027-07-10 all-day|Sample series||', '2027-07-11 all-day|Sample series||', '2027-07-12 all-day|Sample series (own)||'], $view);
            checkEq('at Google, the same', $view, H::googleView($t, $ny));
        } finally {
            H::done($t);
        }
    });

    scenario('hidden at Finish: the words name Put back first, then the way that works', function (): void {
        checkEq('to all-day, from Finish', 'Google Calendar has a fault here: if this event became all-day at its current time, one of its days (Mon 12 Jul) would stop showing there, though nothing deleted it. Put the change back instead; afterwards, move the event to another time first, then make it all-day.',
            GoogleSeries::hiddenWords(['2027-07-12'], [], true, null, 2027, 'finish'));
        checkEq('a new change keeps its own way', 'Google Calendar has a fault here: if this event became timed at 20:00, one of its days (Mon 12 Jul) would stop showing there, though nothing deleted it. Make it timed at another time first, then change the time to 20:00.',
            GoogleSeries::hiddenWords(['2027-07-12'], [], false, '20:00', 2027));
    });
};
