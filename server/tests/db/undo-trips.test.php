<?php

declare(strict_types=1);

// Undo passes the trip rules (docs/api-contract.md, Trips and Undo): a
// restore that would leave a trip inside a trip, or turn off a trip that
// has live stops, is refused with undo_conflict, writes nothing and leaves
// the entry to undo once the conflict is gone. A link whose trip's flag is
// clear is dormant and comes back. Each case runs through every way an
// entry is undone: the last change, by entry with and without force, and a
// run strict and not.

use BetterCal\Domain\ActivityContext;
use BetterCal\Domain\Settings;
use BetterCal\Domain\Trips;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $paths = ['undoLatest', 'undoById', 'undoById force', 'undoRun strict', 'undoRun'];

    // Everything of the account an undo could write: its events, the links
    // between them, which entries are undone.
    $state = static function (int $u) use ($db): array {
        return [
            $db->all('SELECT * FROM events WHERE user_id = ? ORDER BY id', [$u]),
            $db->all('SELECT l.* FROM event_links l JOIN events e ON e.id = l.container_id WHERE e.user_id = ? ORDER BY l.id', [$u]),
            $db->all('SELECT id, undone FROM mutations WHERE user_id = ? ORDER BY id', [$u]),
        ];
    };
    $flag = static fn(int $id): int => (int) $db->scalar('SELECT is_container FROM events WHERE id = ?', [$id]);
    $linked = static fn(int $trip, int $stop): int => (int) $db->scalar('SELECT COUNT(*) FROM event_links WHERE container_id = ? AND event_id = ?', [$trip, $stop]);

    // One undo through $path: the error's status and code, or for a run
    // not strict its tally with the codes it refused.
    $undo = static function (string $path, $d, int $u, int $entry, string $run): array {
        try {
            $out = match ($path) {
                'undoLatest' => $d->undo->undoLatest($u),
                'undoById' => $d->undo->undoById($u, $entry),
                'undoById force' => $d->undo->undoById($u, $entry, true),
                'undoRun strict' => $d->undo->undoRun($u, $run, true),
                'undoRun' => $d->undo->undoRun($u, $run),
            };
        } catch (HttpError $e) {
            return [$e->status . ' ' . $e->errorCode, $e->getMessage()];
        }
        if ($path !== 'undoRun') {
            return [null, null];
        }
        return [[$out['undone'], $out['skipped'], array_column($out['refused'] ?? [], 'code')], implode(' ', array_column($out['refused'] ?? [], 'message'))];
    };
    $refusedAs = static fn(string $path): mixed => $path === 'undoRun' ? [0, 0, ['undo_conflict']] : '400 undo_conflict';
    $passedAs = static fn(string $path): mixed => $path === 'undoRun' ? [1, 0, []] : null;

    /**
     * Run one case through every path, each on an account of its own:
     * $setup makes the ground, $target the entry undone (inside a plugin
     * run for the run paths), $conflict a later change from elsewhere (an
     * API key, so neither "undo the last change" nor the run takes it),
     * $resolve what clears the conflict, $done what the undo put back.
     * With no $resolve the later change is no conflict: the restore passes.
     */
    $each = static function (string $name, callable $setup, callable $target, ?callable $conflict, ?callable $resolve, callable $done, ?string $message = null)
        use ($db, $paths, $state, $undo, $refusedAs, $passedAs): void {
        foreach ($paths as $path) {
            scenario("$name ($path)", function () use ($db, $name, $path, $setup, $target, $conflict, $resolve, $done, $message, $state, $undo, $refusedAs, $passedAs): void {
                $u = freshUser($db);
                $d = domains($db);
                $c = $setup($u, $d, (int) Settings::defaultCalendarId($db, $u));
                $run = 'sample-run-' . bin2hex(random_bytes(4));
                if (str_starts_with($path, 'undoRun')) {
                    ActivityContext::withRun('plugin:sample', $run, fn() => $target($u, $d, $c));
                } else {
                    $target($u, $d, $c);
                }
                $entry = lastEntry($db, $u);
                if ($conflict !== null) {
                    ActivityContext::with('api', fn() => $conflict($u, $d, $c));
                }
                if ($resolve !== null) {
                    $before = $state($u);
                    [$got, $words] = $undo($path, $d, $u, $entry, $run);
                    checkEq("$name ($path): refused with undo_conflict, nothing written (every row, link and entry as before), the entry still to undo", [$refusedAs($path), true, 0],
                        [$got, $state($u) === $before, (int) $db->scalar('SELECT undone FROM mutations WHERE id = ?', [$entry])]);
                    if ($message !== null && $path === 'undoById') {
                        checkEq("$name: the refusal names the rows and the rule", $message, $words);
                    }
                    ActivityContext::with('api', fn() => $resolve($u, $d, $c));
                }
                [$got] = $undo($path, $d, $u, $entry, $run);
                checkEq("$name ($path): " . ($resolve !== null ? 'once the conflict is gone, the entry undoes' : 'undone'), [$passedAs($path), 1],
                    [$got, (int) $db->scalar('SELECT undone FROM mutations WHERE id = ?', [$entry])]);
                $done($u, $d, $c);
            });
        }
    };

    $trip = static fn($d, int $u, int $cal, string $title = 'Sample trip'): int => (int) $d->events->create($u, eventAt($cal, $title, '2027-01-10', ['isContainer' => true]))['eventId'];
    $stop = static fn($d, int $u, int $cal, string $title = 'Sample stop'): int => (int) $d->events->create($u, eventAt($cal, $title, '2027-01-11'))['eventId'];
    $trips = static fn($d): Trips => new Trips($db, $d->undo);

    // A stop taken out of its trip, then made a trip itself. Undoing the
    // detach first would put a trip inside a trip; undoing the flag first,
    // then the detach, is the other order and both go through.
    $each('detach, then flag the stop',
        function (int $u, $d, int $cal) use ($trip, $stop, $trips): array {
            $t = $trip($d, $u, $cal);
            $s = $stop($d, $u, $cal);
            $trips($d)->attach($u, $t, $s);
            return ['trip' => $t, 'stop' => $s];
        },
        fn(int $u, $d, array $c) => $trips($d)->detach($u, $c['trip'], $c['stop']),
        fn(int $u, $d, array $c) => $d->events->patch($u, $c['stop'], ['isContainer' => true]),
        fn(int $u, $d, array $c) => $d->undo->undoById($u, lastEntry($db, $u)),
        function (int $u, $d, array $c) use ($flag, $linked): void {
            checkEq('detach, then flag the stop: undone, the stop is back in its trip and no trip itself', [1, 0, 1], [$flag($c['trip']), $flag($c['stop']), $linked($c['trip'], $c['stop'])]);
        },
        "Undoing this would make 'Sample stop' a trip inside the trip 'Sample trip'; a trip can't contain another trip.");

    // A trip with no stops turned off, then put in another trip: undoing
    // the flag would make it a trip inside that one.
    $each('flag cleared, then put in a trip',
        function (int $u, $d, int $cal) use ($trip): array {
            return ['trip' => $trip($d, $u, $cal), 'inner' => $trip($d, $u, $cal, 'Sample day trip')];
        },
        fn(int $u, $d, array $c) => $d->events->patch($u, $c['inner'], ['isContainer' => false]),
        fn(int $u, $d, array $c) => $trips($d)->attach($u, $c['trip'], $c['inner']),
        fn(int $u, $d, array $c) => $trips($d)->detach($u, $c['trip'], $c['inner']),
        function (int $u, $d, array $c) use ($flag, $linked): void {
            checkEq('flag cleared, then put in a trip: undone once out of it, a trip again and in none', [1, 0], [$flag($c['inner']), $linked($c['trip'], $c['inner'])]);
        },
        "Undoing this would make 'Sample day trip' a trip inside the trip 'Sample trip'; a trip can't contain another trip.");

    // An event made a trip, then given a stop: undoing the flag would turn
    // off a trip that has a stop in it.
    $each('flag set, then a stop added',
        function (int $u, $d, int $cal) use ($stop): array {
            return ['trip' => $stop($d, $u, $cal, 'Sample trip'), 'stop' => $stop($d, $u, $cal)];
        },
        fn(int $u, $d, array $c) => $d->events->patch($u, $c['trip'], ['isContainer' => true]),
        fn(int $u, $d, array $c) => $trips($d)->attach($u, $c['trip'], $c['stop']),
        fn(int $u, $d, array $c) => $trips($d)->detach($u, $c['trip'], $c['stop']),
        function (int $u, $d, array $c) use ($flag, $linked): void {
            checkEq('flag set, then a stop added: undone once the stop is out, no trip', [0, 0], [$flag($c['trip']), $linked($c['trip'], $c['stop'])]);
        },
        "Undoing this would turn off the trip 'Sample trip' while 'Sample stop' is in it; detach its events first.");

    // The series commit's Undo puts the flag back on every row of a trip
    // series. One edited day was put in another trip since: refused.
    $series = static function ($d, int $u, int $cal, bool $isTrip): array {
        $id = (int) $d->events->create($u, eventAt($cal, 'Sample trip series', '2027-01-05', ['rrule' => 'FREQ=WEEKLY;BYDAY=TU', 'isContainer' => $isTrip]))['eventId'];
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'title' => 'Sample trip day one']);
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'title' => 'Sample trip day two']);
        return ['series' => $id];
    };
    $rowsOf = static fn(int $id): array => array_map('intval', array_column($db->all('SELECT id FROM events WHERE id = ? OR recurrence_parent_id = ? ORDER BY id', [$id, $id]), 'id'));
    $flagsOf = static fn(int $id): array => array_map('intval', array_column($db->all('SELECT is_container FROM events WHERE id = ? OR recurrence_parent_id = ? ORDER BY id', [$id, $id]), 'is_container'));
    $each('the series turned off for all events, then a day put in a trip',
        function (int $u, $d, int $cal) use ($series, $trip, $rowsOf): array {
            $c = $series($d, $u, $cal, true) + ['outer' => $trip($d, $u, $cal, 'Sample outer trip')];
            return $c + ['day' => $rowsOf($c['series'])[1]];
        },
        fn(int $u, $d, array $c) => planned($d->events, $u, $c['series'], ['scope' => 'all', 'instanceStart' => '2027-01-26T18:00:00+00:00', 'isContainer' => false]),
        fn(int $u, $d, array $c) => $trips($d)->attach($u, $c['outer'], $c['day']),
        fn(int $u, $d, array $c) => $trips($d)->detach($u, $c['outer'], $c['day']),
        function (int $u, $d, array $c) use ($flagsOf): void {
            checkEq('the series turned off, then a day put in a trip: undone once out of it, every row a trip again', [1, 1, 1], $flagsOf($c['series']));
        },
        "Undoing this would make 'Sample trip day one' a trip inside the trip 'Sample outer trip'; a trip can't contain another trip.");
    $each('the series made a trip for all events, then a stop added to a day',
        function (int $u, $d, int $cal) use ($series, $stop, $rowsOf): array {
            $c = $series($d, $u, $cal, false) + ['stop' => $stop($d, $u, $cal)];
            return $c + ['day' => $rowsOf($c['series'])[2]];
        },
        fn(int $u, $d, array $c) => planned($d->events, $u, $c['series'], ['scope' => 'all', 'instanceStart' => '2027-01-26T18:00:00+00:00', 'isContainer' => true]),
        fn(int $u, $d, array $c) => $trips($d)->attach($u, $c['day'], $c['stop']),
        fn(int $u, $d, array $c) => $trips($d)->detach($u, $c['day'], $c['stop']),
        function (int $u, $d, array $c) use ($flagsOf): void {
            checkEq('the series made a trip, then a stop added: undone once the stop is out, no row a trip', [0, 0, 0], $flagsOf($c['series']));
        },
        "Undoing this would turn off the trip 'Sample trip day two' while 'Sample stop' is in it; detach its events first.");

    // A trip that is its own stop: no writer makes one, so the test writes
    // the link itself. Detached, its undo would put the trip inside itself;
    // once the trip is deleted the link comes back dormant.
    $each('a trip linked to itself, detached',
        function (int $u, $d, int $cal) use ($db, $trip): array {
            $t = $trip($d, $u, $cal);
            $db->insert('event_links', ['container_id' => $t, 'event_id' => $t, 'position' => 0]);
            return ['trip' => $t];
        },
        fn(int $u, $d, array $c) => $trips($d)->detach($u, $c['trip'], $c['trip']),
        null,
        fn(int $u, $d, array $c) => $d->events->deleteEvent($u, $c['trip'], null, null),
        function (int $u, $d, array $c) use ($linked): void {
            checkEq('a trip linked to itself, detached: undone once the trip is deleted, the link is back', 1, $linked($c['trip'], $c['trip']));
        },
        "Undoing this would put the trip 'Sample trip' inside itself; a trip can't contain itself.");

    // A stop deleted, then its trip turned off (it had no live stops): the
    // link is dormant, and undoing the delete brings the stop back with it.
    $each('a stop deleted, then its trip turned off: the dormant link comes back',
        function (int $u, $d, int $cal) use ($trip, $stop, $trips): array {
            $t = $trip($d, $u, $cal);
            $s = $stop($d, $u, $cal);
            $trips($d)->attach($u, $t, $s);
            return ['trip' => $t, 'stop' => $s];
        },
        fn(int $u, $d, array $c) => $d->events->deleteEvent($u, $c['stop'], null, null),
        fn(int $u, $d, array $c) => $d->events->patch($u, $c['trip'], ['isContainer' => false]),
        null,
        function (int $u, $d, array $c) use ($db, $flag, $linked): void {
            checkEq('a stop deleted, then its trip turned off: the stop is back, the link stands, the trip stays off', [null, 0, 1],
                [$db->scalar('SELECT deleted_at FROM events WHERE id = ?', [$c['stop']]), $flag($c['trip']), $linked($c['trip'], $c['stop'])]);
        });

    // A trip series with a stop on an edited day and one on the series,
    // deleted whole: every row and link comes back, trips over stops.
    $snapshot = null;
    $each('a trip series with stops deleted and undone',
        function (int $u, $d, int $cal) use ($series, $stop, $trips, $rowsOf): array {
            $c = $series($d, $u, $cal, true);
            $rows = $rowsOf($c['series']);
            $trips($d)->attach($u, $rows[1], $stop($d, $u, $cal));
            $trips($d)->attach($u, $c['series'], $stop($d, $u, $cal, 'Sample stop two'));
            return $c;
        },
        function (int $u, $d, array $c) use ($db, &$snapshot): void {
            $snapshot = [
                $db->all('SELECT id, is_container, deleted_at FROM events WHERE user_id = ? ORDER BY id', [$u]),
                $db->all('SELECT l.* FROM event_links l JOIN events e ON e.id = l.container_id WHERE e.user_id = ? ORDER BY l.id', [$u]),
            ];
            $d->events->deleteEvent($u, $c['series'], 'all', null);
        },
        null, null,
        function (int $u, $d, array $c) use ($db, &$snapshot): void {
            checkEq('a trip series with stops deleted and undone: every row, flag and link as before', $snapshot, [
                $db->all('SELECT id, is_container, deleted_at FROM events WHERE user_id = ? ORDER BY id', [$u]),
                $db->all('SELECT l.* FROM event_links l JOIN events e ON e.id = l.container_id WHERE e.user_id = ? ORDER BY l.id', [$u]),
            ]);
        });
};
