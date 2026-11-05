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

    // The writers pass the same rules as Undo (Trips::assertRules), over
    // the state they leave, whichever end of a link the flag is set on.
    $refusal = static function (callable $fn): array {
        try {
            $fn();
            return [null, null];
        } catch (HttpError $e) {
            return [$e->status . ' ' . $e->errorCode, $e->getMessage()];
        }
    };
    $nested = static fn(int $u): int => (int) $db->scalar(
        'SELECT COUNT(*) FROM event_links l JOIN events c ON c.id = l.container_id JOIN events m ON m.id = l.event_id
         WHERE c.user_id = ? AND c.is_container = 1 AND m.is_container = 1 AND c.deleted_at IS NULL AND m.deleted_at IS NULL',
        [$u]
    );
    // A stop deleted, its trip turned off, the delete undone: the link is
    // back, dormant (above). From there, each flag set alone is allowed
    // while the link stays dormant; the one that would make it live
    // between two trips is refused, in either order.
    $dormant = static function ($d, int $u, int $cal) use ($db, $trip, $stop, $trips): array {
        $t = $trip($d, $u, $cal);
        $s = $stop($d, $u, $cal);
        $trips($d)->attach($u, $t, $s);
        $d->events->deleteEvent($u, $s, null, null);
        $deleted = lastEntry($db, $u);
        $d->events->patch($u, $t, ['isContainer' => false]);
        $d->undo->undoById($u, $deleted);
        return [$t, $s];
    };
    scenario('writers: a dormant link made live between two trips, the stop flagged first', function () use ($db, $dormant, $refusal, $state, $flag, $nested): void {
        $u = freshUser($db);
        $d = domains($db);
        [$t, $s] = $dormant($d, $u, (int) Settings::defaultCalendarId($db, $u));
        [$code] = $refusal(fn() => $d->events->patch($u, $s, ['isContainer' => true]));
        checkEq('the stop of a dormant link made a trip: allowed, its old trip still off', [null, 1, 0], [$code, $flag($s), $flag($t)]);
        $before = $state($u);
        [$code, $words] = $refusal(fn() => $d->events->patch($u, $t, ['isContainer' => true]));
        checkEq('its old trip made a trip again: 400 trip_nested, naming both and the rule, nothing written', ['400 trip_nested', true, 0], [$code, $state($u) === $before, $nested($u)]);
        checkEq('the refusal names the rows and the rule', "This would make 'Sample stop' a trip inside the trip 'Sample trip'; a trip can't contain another trip.", $words);
    });
    scenario('writers: a dormant link made live between two trips, the trip flagged first', function () use ($db, $dormant, $refusal, $state, $flag, $trips, $nested): void {
        $u = freshUser($db);
        $d = domains($db);
        [$t, $s] = $dormant($d, $u, (int) Settings::defaultCalendarId($db, $u));
        [$code] = $refusal(fn() => $d->events->patch($u, $t, ['isContainer' => true]));
        checkEq('a trip whose only link is dormant, to a stop that is no trip, made a trip again: allowed, the stop is its again', [null, 1, [$s]],
            [$code, $flag($t), array_map('intval', array_column($trips($d)->listMembers($u, $t), 'id'))]);
        $before = $state($u);
        [$code, $words] = $refusal(fn() => $d->events->patch($u, $s, ['isContainer' => true]));
        checkEq('then its stop made a trip: 400 trip_nested, nothing written', ['400 trip_nested', true, 0], [$code, $state($u) === $before, $nested($u)]);
        checkEq('the refusal reads the same from either end', "This would make 'Sample stop' a trip inside the trip 'Sample trip'; a trip can't contain another trip.", $words);
    });

    // The same through the series path: an edited day of a trip series is
    // the trip of a link made dormant the same way, and its stop made a
    // trip. The series made a trip again reaches that day (the series'
    // flag, on every row) and is refused from either row and from the
    // first day on; for this event, or this and following from a later
    // day, the edited day's flag is not written, so nothing nests.
    scenario('writers: a series made a trip while an edited day is the trip of a dormant link to a trip', function () use ($db, $series, $stop, $trips, $rowsOf, $flagsOf, $flag, $refusal, $state, $nested): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = (int) Settings::defaultCalendarId($db, $u);
        $c = $series($d, $u, $cal, true);
        $day = $rowsOf($c['series'])[1];
        $s = $stop($d, $u, $cal);
        $trips($d)->attach($u, $day, $s);
        $d->events->deleteEvent($u, $s, null, null);
        $deleted = lastEntry($db, $u);
        $off = ['scope' => 'all', 'instanceStart' => '2027-01-26T18:00:00+00:00', 'isContainer' => false];
        planned($d->events, $u, $c['series'], $off);
        $d->undo->undoById($u, $deleted);
        $d->events->patch($u, $s, ['isContainer' => true]);
        checkEq('the series off, its edited day\'s link dormant and back, the stop a trip', [[0, 0, 0], 1, 1], [$flagsOf($c['series']), $flag($s), count($db->all('SELECT id FROM event_links WHERE container_id = ? AND event_id = ?', [$day, $s]))]);
        $words = "This would make 'Sample stop' a trip inside the trip 'Sample trip day one'; a trip can't contain another trip.";
        foreach ([
            'from the master for all events' => fn() => planned($d->events, $u, $c['series'], ['isContainer' => true] + $off),
            'from the edited day\'s own row for all events' => fn() => planned($d->events, $u, $day, ['scope' => 'all', 'isContainer' => true]),
            'the edited day alone' => fn() => $d->events->patch($u, $day, ['isContainer' => true]),
            'this and following from the first day' => fn() => $d->events->patch($u, $c['series'], ['scope' => 'following', 'instanceStart' => '2027-01-05T18:00:00+00:00', 'isContainer' => true]),
        ] as $how => $write) {
            $before = $state($u);
            $got = $refusal($write);
            checkEq("the series made a trip $how: 400 trip_nested, naming the day and its stop, nothing written", [['400 trip_nested', $words], true], [$got, $state($u) === $before]);
        }
        foreach ([
            'for this event, the edited day\'s' => ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00'],
            'for this and following from the edited day' => ['scope' => 'following', 'instanceStart' => '2027-01-12T18:00:00+00:00'],
        ] as $how => $scope) {
            $u2 = freshUser($db);
            $d2 = domains($db);
            $cal2 = (int) Settings::defaultCalendarId($db, $u2);
            $c2 = $series($d2, $u2, $cal2, true);
            $day2 = $rowsOf($c2['series'])[1];
            $s2 = $stop($d2, $u2, $cal2);
            $trips($d2)->attach($u2, $day2, $s2);
            $d2->events->deleteEvent($u2, $s2, null, null);
            $deleted2 = lastEntry($db, $u2);
            planned($d2->events, $u2, $c2['series'], $off);
            $d2->undo->undoById($u2, $deleted2);
            $d2->events->patch($u2, $s2, ['isContainer' => true]);
            [$code] = $refusal(fn() => planned($d2->events, $u2, $c2['series'], ['isContainer' => true] + $scope));
            // This and following is the part from the day planned as a whole
            // series (1.5.1): the flag reaches every row of the part, this
            // day among them, so it is refused as for all events.
            $want = $scope['scope'] === 'following' ? '400 trip_nested' : null;
            checkEq("the series made a trip $how: " . ($want === null ? 'allowed' : 'refused') . ", the edited day's flag as it was, no trip inside a trip", [$want, 0, 0], [$code, $flag($day2), $nested($u2)]);
        }
    });

    // Making a link passes the same rules (POST /events/:id/links, trip_invalid).
    scenario('writers: a link that would put a trip inside a trip or inside itself is refused', function () use ($db, $trip, $trips, $refusal, $state): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = (int) Settings::defaultCalendarId($db, $u);
        $t = $trip($d, $u, $cal);
        $inner = $trip($d, $u, $cal, 'Sample day trip');
        $before = $state($u);
        $got = [$refusal(fn() => $trips($d)->attach($u, $t, $inner)), $refusal(fn() => $trips($d)->attach($u, $t, $t))];
        checkEq('a trip attached to a trip, and a trip to itself: 400 trip_invalid each, naming the rule, nothing written', [[
            ['400 trip_invalid', "This would make 'Sample day trip' a trip inside the trip 'Sample trip'; a trip can't contain another trip."],
            ['400 trip_invalid', "This would put the trip 'Sample trip' inside itself; a trip can't contain itself."],
        ], true], [$got, $state($u) === $before]);
    });

    // Turning a trip off while it has a live stop, as an edit.
    scenario('writers: a trip with a live stop is not turned off', function () use ($db, $trip, $stop, $trips, $refusal, $state): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = (int) Settings::defaultCalendarId($db, $u);
        $t = $trip($d, $u, $cal);
        $trips($d)->attach($u, $t, $stop($d, $u, $cal));
        $before = $state($u);
        $got = $refusal(fn() => $d->events->patch($u, $t, ['isContainer' => false]));
        checkEq('turned off with a stop in it: 400 trip_has_members, naming both, nothing written', [['400 trip_has_members', "This would turn off the trip 'Sample trip' while 'Sample stop' is in it; detach its events first."], true], [$got, $state($u) === $before]);
    });

    // On a Google calendar the rules run before Google is called: a refused
    // edit sends Google nothing, the title it carried included. Google is a
    // stand-in that counts every call, the token refresh among them.
    scenario('writers: a Google event\'s new title with a refused trip flag sends Google nothing', function () use ($db, $trip, $trips, $refusal, $state, $flag): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = (int) Settings::defaultCalendarId($db, $u);
        $calls = [];
        $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
        $auth = new BetterCal\Domain\GoogleAuth($db, $cfg, static function (string $url, array $fields, ?float $deadline) use (&$calls): array {
            $calls[] = 'token';
            return ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])];
        });
        $transport = static function (string $method, string $url, ?array $payload) use (&$calls): array {
            $calls[] = $method;
            return ['status' => 200, 'body' => json_encode(($payload ?? []) + ['id' => 'g-sample', 'iCalUID' => 'sample-meeting@google', 'status' => 'confirmed'])];
        };
        $events = new BetterCal\Domain\Events($db, new BetterCal\Domain\Recurrence(), $d->undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $d->undo, new BetterCal\Infra\JobQueue($db)), new Trips($db, $d->undo), null,
            new BetterCal\Domain\GoogleWriter($db, $auth, new BetterCal\Domain\Feeds($db), $transport));
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => BetterCal\Domain\GoogleAuth::SCOPES]);
        $googleCal = $db->insert('calendars', ['user_id' => $u, 'name' => 'Google sample', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'sample@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $now = BetterCal\Support\Time::nowDb();
        $meeting = $db->insert('events', ['user_id' => $u, 'calendar_id' => $googleCal, 'uid' => 'sample-meeting@google', 'title' => 'Sample meeting',
            'start_utc' => '2027-01-11 18:00:00', 'end_utc' => '2027-01-11 19:00:00', 'all_day' => 0, 'tzid' => 'UTC', 'source' => 'feed',
            'google_event_id' => 'g-sample', 'created_at' => $now, 'updated_at' => $now]);
        $t = $trip($d, $u, $cal);
        $trips($d)->attach($u, $t, $meeting);
        $edit = ['title' => 'Sample meeting, renamed', 'isContainer' => true];
        $before = $state($u);
        $got = $refusal(fn() => $events->patch($u, $meeting, $edit));
        checkEq('a stop on a Google calendar renamed and made a trip: 400 trip_nested, no call to Google, nothing written', [
            ['400 trip_nested', "This would make 'Sample meeting' a trip inside the trip 'Sample trip'; a trip can't contain another trip."], [], true,
        ], [$got, $calls, $state($u) === $before]);
        // Out of its trip, the same edit is sent and written.
        $trips($d)->detach($u, $t, $meeting);
        $events->patch($u, $meeting, $edit);
        checkEq('out of its trip, the same edit: sent to Google once, renamed and a trip', [['token', 'PATCH'], 'Sample meeting, renamed', 1],
            [$calls, (string) $db->scalar('SELECT title FROM events WHERE id = ?', [$meeting]), $flag($meeting)]);
    });
};
