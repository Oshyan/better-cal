<?php

declare(strict_types=1);

// From the outside read of 5f60dd8b..14a17871 (its probes, made regular tests), sentence 1: how many requests a change to all events makes now that
// each day's write is preceded by a read. A daily series at 10:00 Los Angeles with N days renamed, moved to 11:00 for
// all events (every renamed day is sent again: Google drops records on a clock change, probe 3.1). Counted from the
// stand-in's own request log; also the items every list returned, summed (each list returns every record of the
// series). Was 3N + 4 with a read of each day before its write; N + 4 since. Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    foreach ([5, 20, 40] as $n) {
        scenario("requests probe: $n renamed days, all events moved to 11:00", function () use ($db, $la, $n): void {
            $t = H::make($db, ['start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=DAILY;COUNT=' . ($n + 2)]);
            try {
                $day = new DateTimeImmutable('2027-03-01 10:00:00', new DateTimeZone($la));
                for ($i = 1; $i <= $n; $i++) {
                    H::this($t, $day->modify("+$i days")->format('Y-m-d\TH:i:sP'), ['title' => "Own title $i"]);
                }
                $base = count($t->s->fake->requests);
                [, $error] = H::tryChange($t, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']);
                $reqs = array_slice($t->s->fake->requests, $base);
                $lists = count(array_filter($reqs, static fn(array $r): bool => $r['method'] === 'GET' && str_ends_with($r['path'], '/events')));
                $gets = count(array_filter($reqs, static fn(array $r): bool => $r['method'] === 'GET' && !str_ends_with($r['path'], '/events')));
                $writes = count(array_filter($reqs, static fn(array $r): bool => $r['method'] !== 'GET'));
                $kept = (int) $db->scalar('SELECT COUNT(*) FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL AND title LIKE ?', [$t->id, 'Own title%']);
                check("$n renamed days: the change goes through and every renamed day keeps its title", $error === null && $kept === $n, (string) $error . " kept $kept");
                // Inside a change to all events each day is written once with no read before it (decisions.md, "Inside a change
                // to all events a day is written with no condition"): the series read, its master write, a read-back, the N
                // days, a final read: N + 4.
                checkEq("$n renamed days: N + 4 requests, no read of one day, three lists", [$n + 4, 0, 3], [count($reqs), $gets, $lists]);
            } finally {
                H::done($t);
            }
        });
    }
};
