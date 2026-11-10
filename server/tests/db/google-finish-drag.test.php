<?php

declare(strict_types=1);

// Finish of a series dragged along its own pattern, after a day deleted at Google meanwhile (finding F3 of the
// hunt of 20f77fe9; decisions.md, "A change made at Google while a change's first write waits is named, not
// carried, where the change gives the days other keys").
//
// A daily series at 10:00 Los Angeles, Mon 4 Jan to Mon 11 Jan 2027, Wed 6 Jan with its own notes. All events are
// dragged two days later, so each occurrence lands on the old instant of the one two days after it: the series then
// runs Wed 6 Jan to Wed 13 Jan, and the day with the notes is Fri 8 Jan (series-model.md: "edited days are re-keyed
// to the images of their keys and keep their own fields"). The network goes down as the master write is sent: Google
// took nothing, Review lists the change as half done. Then someone deletes Fri 8 Jan at Google, in the series as
// Google still holds it (Mon 4 to Mon 11). Then Finish.
//
// By date, from the model and the contract: the event deleted at Google is the one that was on Fri 8 Jan, which the
// change moves to Sun 10 Jan; the event with the notes was on Wed 6 Jan and moves to Fri 8 Jan. An equal key is not
// the same day: Fri 8 Jan 18:00 UTC named the deleted day in the series Google held when it was deleted, and names
// the day with the notes in the series the change makes. Which day of the new series the deleted one became is the
// planner's to say, so the deletion is not carried by its key nor by a guess at the shift: after Finish the series
// runs Wed 6 to Wed 13 Jan whole, the notes on Fri 8 Jan, and the deletion is named once, by its date, as not kept
// because the series moved to another day. (The hunter's probe expected the deletion kept on Sun 10 Jan; the
// restated rule names it instead, and the first block asserts the sentence.)
//
// The second block is the same with the master write taken and its answer lost, where the day deleted at Google is
// a day of the series as changed (Fri 8 Jan, the notes' new day, whose notes Google does not yet show): there the
// deletion stands, and the contract says a day changed since in something the change touches "is kept and named".
//
// Expected values are written from the contract by date; nothing in server/src computes one. Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    // A date as the app words one: the year only when it is not this year.
    $day = static fn(string $ymd): string => (new DateTimeImmutable($ymd))->format(substr($ymd, 0, 4) === gmdate('Y') ? 'D j M' : 'D j M Y');
    $build = static function () use ($db, $la): object {
        $t = H::make($db, ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=DAILY;COUNT=8']);
        H::this($t, '2027-01-06T10:00:00-08:00', ['description' => 'Notes A']);
        return $t;
    };
    $drag = ['scope' => 'all', 'instanceStart' => '2027-01-04T10:00:00-08:00', 'start' => '2027-01-06T10:00:00-08:00', 'end' => '2027-01-06T11:00:00-08:00'];
    $run = static function (string $name, string $knob, array $expect, string $says) use ($build, $drag, $la): void {
        $t = $build();
        try {
            $t->s->knobs->writes = 0;
            $t->s->knobs->$knob = 1;
            [, $stopped] = H::tryChange($t, $drag);
            $t->s->knobs->$knob = null;
            $t->s->knobs->down = false;
            check("$name: the change stops half done and Review lists it", $stopped !== null && count(H::halfDone($t)) === 1, (string) $stopped);
            // Fri 8 Jan as another client finds it in Google's own listing, deleted there.
            $target = null;
            [, $list] = H::atGoogle($t, 'GET', '/events/' . rawurlencode($t->gid) . '/instances?maxResults=100');
            foreach ($list['items'] ?? [] as $i) {
                if (isset($i['start']['dateTime']) && (new DateTimeImmutable($i['start']['dateTime']))->setTimezone(new DateTimeZone($la))->format('Y-m-d') === '2027-01-08') {
                    $target = (string) $i['id'];
                }
            }
            [$status] = H::atGoogle($t, 'DELETE', '/events/' . rawurlencode((string) $target));
            checkEq("$name: the stand-in takes the deletion of Fri 8 Jan ($target)", 204, $status);
            [$out, $refused] = H::recover($t, 'finish');
            check("$name: Finish completes", $refused === null, (string) $refused);
            $now = H::settled($t, $name, $la, '2027-01-01', '2027-01-20');
            $words = (array) ($out['differences'] ?? []);
            check("$name: the calendar by date", $now === $expect,
                "\n   missing: " . implode("\n            ", array_diff($expect, $now)) . "\n   extra:   " . implode("\n            ", array_diff($now, $expect)) . "\n   the answer's differences: " . json_encode($words));
            checkEq("$name: the answer names the deletion made at Google, once", [$says], $words);
        } finally {
            H::done($t);
        }
    };
    $line = static fn(string $d, string $notes = ''): string => "2027-01-$d 10:00|Sample series|$notes|";

    scenario('F3: all events dragged two days later, the master write sent and not taken, Fri 8 Jan deleted at Google, then Finish', static fn() => $run(
        'F3 (master write sent and not taken)', 'netAt',
        // The series Wed 6 to Wed 13 Jan whole; the notes on Fri 8 Jan; the deletion made at Google named as not kept.
        [$line('06'), $line('07'), $line('08', 'Notes A'), $line('09'), $line('10'), $line('11'), $line('12'), $line('13')],
        $day('2027-01-08') . ' was deleted at Google since this change stopped; that change is not kept, because the series moved to another day.'));

    scenario('F3 second block: the same, the master write taken and its answer lost', static fn() => $run(
        'F3 (master write taken, its answer lost)', 'loseAt',
        // The day deleted at Google is Fri 8 Jan of the series as changed: it stays deleted, and the notes that were to show there go with it, named.
        [$line('06'), $line('07'), $line('09'), $line('10'), $line('11'), $line('12'), $line('13')],
        $day('2027-01-08') . ' was deleted at Google since this change stopped; it stays deleted.'));
};
