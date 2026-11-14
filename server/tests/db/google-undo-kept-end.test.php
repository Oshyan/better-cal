<?php

declare(strict_types=1);

// The undo of a change to all events, where Google keeps a day's end (replay round 5, cause 7; the edit harness case
// google-an-untouched-edited-day-keeps-its-own-list-after-a-zone-and-rule-change, whose f-undo check fails the same
// way). When a master write moves the series' start and keeps its length, Google moves each kept record's start onto
// its occurrence and leaves its end where it was; the stand-in does the same since that round. Here a series has one
// day at its own time; a change of zone (at the same moments) and rule carries that time onto another day, and the
// undo puts the series back. Every day must then be as before the change, start and end, here and at Google.
// Expectations by date, from the series as it was before the change. Invented data.
//
// A regression test since 1.5.6. It failed until the series' write was followed by a read back with every day that is
// not as planned sent (decisions.md, "After the series' write, the days are set from what Google holds"): after the
// undo the day the change wrote kept Google's 13:00 to 14:00, here as a day with its own length, where the series is
// 13:00 to 14:30.

use BetterCal\EditHarness\GoogleStand;
use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $z = new DateTimeZone($la);
    $hm = static fn(string $iso): string => (new DateTimeImmutable($iso))->setTimezone($z)->format('m-d H:i');
    // Each day's start and end here, from the app's own window.
    $here = static function (object $t) use ($hm): array {
        $utc = new DateTimeZone('UTC');
        $out = [];
        foreach ($t->e->window($t->u, new DateTimeImmutable('2027-03-01 00:00:00', $utc), new DateTimeImmutable('2027-03-12 00:00:00', $utc), [$t->s->calendarId], null, true) as $x) {
            $out[] = $hm((string) $x['start']) . ' to ' . substr($hm((string) $x['end']), 6);
        }
        sort($out);
        return $out;
    };
    // Each day's start and end at Google, by a GET of every occurrence of the series as it holds it.
    $google = static function (object $t) use ($hm): array {
        $out = [];
        $m = $t->s->fake->events[$t->gid];
        foreach (GoogleStand::expand($m) as $s) {
            [$st, $i] = H::atGoogle($t, 'GET', '/events/' . rawurlencode($t->gid . '_' . $s->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z')));
            if ($st === 200 && ($i['status'] ?? '') !== 'cancelled') {
                $out[] = $hm((string) $i['start']['dateTime']) . ' to ' . substr($hm((string) $i['end']['dateTime']), 6);
            }
        }
        sort($out);
        return $out;
    };

    scenario('undo after a zone and rule change: every day as before, start and end, here and at Google', function () use ($db, $la, $here, $google): void {
        $t = H::make($db, ['start' => '2027-03-03T13:00:00-08:00', 'end' => '2027-03-03T14:30:00-08:00', 'tzid' => $la, 'floating' => true,
            'rrule' => 'FREQ=DAILY;BYDAY=WE,TH,FR,SA;UNTIL=20270311T075959Z']);
        try {
            seriesUndo($db, $t->u, true);
            H::this($t, '2027-03-06T13:00:00-08:00', ['start' => '2027-03-06T12:30:00-08:00', 'end' => '2027-03-06T14:00:00-08:00']);
            $want = ['03-03 13:00 to 14:30', '03-04 13:00 to 14:30', '03-05 13:00 to 14:30', '03-06 12:30 to 14:00', '03-10 13:00 to 14:30'];
            checkEq('before the change, here and at Google', [$want, $want], [$here($t), $google($t)]);
            H::change($t, ['scope' => 'all', 'instanceStart' => '2027-03-10T13:00:00-08:00', 'tzid' => 'Asia/Kolkata', 'start' => '2027-03-10T13:00:00+05:30',
                'end' => '2027-03-10T14:30:00+05:30', 'rrule' => 'FREQ=WEEKLY;BYDAY=SA;UNTIL=20270311T075959Z'], ['zone' => 'moment']);
            (new BetterCal\Domain\Undo($t->db, null, $t->s->writer))->undoById($t->u, lastEntry($db, $t->u), false);
            $t->e->throwDeferred();
            checkEq('after the undo, every day as before the change, here and at Google, nothing half done', [$want, $want, []], [$here($t), $google($t), H::halfDone($t)]);
        } finally {
            H::done($t);
        }
    });
};
