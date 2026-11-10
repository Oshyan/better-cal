<?php

declare(strict_types=1);

// What a day holds here alone through a change half done at Google
// (series-model.md, Recovery and Rows from Elsewhere; google-calendar.md,
// What Writes Send; interleaving-invariants.md, x-alone and A1).
//
// The rule, stated for recovery: a change, however it ends, leaves every day
// holding exactly what it held here alone before the change began, wherever
// the day still is and still shows what it showed. Finish and Put back are
// not exempt because a store ran in between: what a store of the half-done
// change took from a row is the change's own passing effect, never something
// that happened at Google. Put back leaves the rows as they were; Finish
// leaves them as the change, completed in one go, would have.
//
// Each scenario gives one day of a series one kind of thing no provider
// carries (a tag, a person, reminders, an answer, an icon, picked
// coordinates, a trip link, a duplicate decision, plugin data), stops a
// change for all events part way, and reads the day again after Finish and
// after Put back. The ways a row passes through the stopped change: a row
// that stood and that a store changed; a row a store removed; a row the plan
// moves to another key; the series' own row. Every expected value is what
// the day held before the change began, read before it; nothing in
// server/src computes one. Google is the edit harness's stand-in. Invented
// data.

use BetterCal\Domain\Trips;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    $zone = 'America/New_York';
    $first = '2027-07-19T20:00:00-04:00';
    $day = '2027-07-23'; // the day that holds things
    $dayAt = '2027-07-23T20:00:00-04:00';
    $notes = 'Bring the sample checklist';

    /** The series' row and its days' rows by the date each shows on (the series' zone, or its dates when all-day). */
    $rowsOf = static function (int $id) use ($db): array {
        $m = $db->one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [$id]);
        $out = ['*' => $m];
        foreach ($db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id', [$id]) as $r) {
            $key = new DateTimeImmutable((string) $r['recurrence_instance_utc'], new DateTimeZone('UTC'));
            $date = (int) $m['all_day'] === 1 ? $key->format('Y-m-d') : $key->setTimezone(new DateTimeZone((string) $m['tzid']))->format('Y-m-d');
            $out[$date] = $r;
        }
        return $out;
    };
    /**
     * What a row holds that no provider carries, each kind read from the tables: its tags and people by name, its
     * reminders, its answer, its icon, its coordinates with where they came from, the fields of these its own list
     * names, and what refers to it (trip links by the other end's title, duplicate decisions, plugin data).
     */
    $alone = static function (?array $r) use ($db): ?array {
        if ($r === null) {
            return null;
        }
        $id = (int) $r['id'];
        $names = static fn(string $sql): array => array_map('strval', array_column($db->all($sql, [$id]), 'name'));
        $own = json_decode((string) ($r['override_fields'] ?? 'null'), true);
        $title = static fn(int $other): string => (string) $db->scalar('SELECT title FROM events WHERE id = ?', [$other]);
        $links = [];
        foreach ($db->all('SELECT container_id, event_id FROM event_links WHERE container_id = ? OR event_id = ? ORDER BY id', [$id, $id]) as $l) {
            $links[] = (int) $l['event_id'] === $id ? 'a stop of ' . $title((int) $l['container_id']) : 'the trip of ' . $title((int) $l['event_id']);
        }
        $pairs = [];
        foreach ($db->all('SELECT event_a, event_b, status FROM event_duplicates WHERE event_a = ? OR event_b = ? ORDER BY id', [$id, $id]) as $p) {
            $pairs[] = $p['status'] . ' with ' . $title((int) $p['event_a'] === $id ? (int) $p['event_b'] : (int) $p['event_a']);
        }
        return [
            'tags' => $names('SELECT t.name FROM event_tags e JOIN tags t ON t.id = e.tag_id WHERE e.event_id = ? ORDER BY t.name'),
            'people' => $names('SELECT p.name FROM event_people e JOIN people p ON p.id = e.person_id WHERE e.event_id = ? ORDER BY p.name'),
            'reminders' => json_decode((string) ($r['reminders_json'] ?? 'null'), true),
            'answer' => (string) $r['attendance'],
            'icon' => $r['icon'],
            'place' => [(string) $r['location'], $r['location_lat'] === null ? null : round((float) $r['location_lat'], 5), $r['location_lng'] === null ? null : round((float) $r['location_lng'], 5), $r['location_source']],
            'own' => is_array($own) ? array_values(array_intersect(['location', 'reminders', 'attendance', 'tags', 'people', 'icon'], $own)) : null,
            'trip' => $links,
            'duplicates' => $pairs,
            'plugin' => $db->all('SELECT plugin_id, k, v_json FROM event_plugin_data WHERE event_id = ? ORDER BY plugin_id, k', [$id]),
        ];
    };
    $pending = static fn(int $u): array => $db->all('SELECT id, status FROM google_pending WHERE user_id = ? ORDER BY id', [$u]);

    // The changes for all events a scenario stops part way.
    $changes = [
        'an hour later' => ['scope' => 'all', 'instanceStart' => $first, 'start' => '2027-07-19T21:00:00-04:00', 'end' => '2027-07-19T22:00:00-04:00'],
        'made all-day' => ['scope' => 'all', 'instanceStart' => $first, 'start' => '2027-07-19', 'end' => '2027-07-20', 'allDay' => true],
        'a new place' => ['scope' => 'all', 'instanceStart' => $first, 'location' => 'Sample Annex'],
        'three events' =>['scope' => 'all', 'instanceStart' => $first, 'rrule' => 'FREQ=DAILY;COUNT=3'],
    ];

    /**
     * A daily series at 20:00 New York, eight of them, at 'Sample Hall' with a tag and a person. $c['give'] gives the
     * day what it holds (fn(object $s, int $seriesId): void). Then $c['change'], stopped at $c['stop'] ('after its
     * master write': the write Google would take next is refused; 'its answer lost': Google takes the master write and
     * its answer never comes), $c['between'] (what happens here while it is half done), and $c['then'] (finish, putBack).
     * After: the day holds what it held ($c['day'] => the date read, default the day given things; '*' the series).
     */
    $run = static function (string $name, array $c) use ($db, $zone, $first, $day, $changes, $rowsOf, $alone, $pending): void {
        $u = freshUser($db);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        try {
            $e = $s->events;
            $id = (int) $e->create($u, ['calendarId' => $s->calendarId, 'title' => 'Sample series', 'location' => 'Sample Hall', 'description' => 'Sample notes',
                'start' => $first, 'end' => '2027-07-19T21:00:00-04:00', 'tzid' => $zone, 'rrule' => 'FREQ=DAILY;COUNT=8', 'tagNames' => ['sample'], 'personNames' => ['Alex Example']])['eventId'];
            $ctx = (object) ['u' => $u, 'd' => $d, 'events' => $e, 'stand' => $s, 'db' => $db];
            ($c['give'])($ctx, $id);
            $e->throwDeferred();
            $at = $c['day'] ?? $day;
            $before = $alone($rowsOf($id)[$at] ?? null);
            check("$name: before the change the day holds something here alone", $before !== null, json_encode($before));
            $shown = $before === null ? null : $before['place'][0];

            if ($c['stop'] === 'its answer lost') {
                $s->knobs->loseAt = $s->knobs->writes + 1;
            } else {
                $s->knobs->beforeAt = $s->knobs->writes + 2;
            }
            $stopped = null;
            try {
                planned($e, $u, $id, $changes[$c['change']], $c['choices'] ?? []);
                $e->throwDeferred();
            } catch (HttpError $error) {
                $stopped = $error;
            }
            $s->knobs->loseAt = $s->knobs->beforeAt = null;
            $half = $pending($u);
            checkEq("$name: the change stops half done, listed for Review", [true, 1, 'half'], [$stopped !== null && isset($stopped->extra['pending']), count($half), $half[0]['status'] ?? null]);
            if (isset($c['between'])) {
                ($c['between'])($ctx, $id, $rowsOf($id)[$at] ?? null);
            }
            $out = [];
            $refused = null;
            try {
                $out = $c['then'] === 'finish' ? $e->googleFinish($u, (int) $half[0]['id']) : $e->googlePutBack($u, (int) $half[0]['id']);
                $e->throwDeferred();
            } catch (HttpError $error) {
                $refused = $error;
            }
            checkEq("$name: " . ($c['then'] === 'finish' ? 'Finish' : 'Put back') . ' goes through, nothing left to settle', [null, true, []], [$refused?->errorCode, $out['ok'] ?? null, $pending($u)]);
            $after = $alone($rowsOf($id)[$at] ?? null);
            // The rule: what it held before the change began, where the day still shows the place it showed (A1: a day
            // showing another place text after the step has that place's coordinates, which is the change's own request).
            $expected = $c['expect'] ?? $before;
            if ($c['then'] === 'finish' && $expected !== null && $after !== null && $after['place'][0] !== $shown && !isset($c['expect'])) {
                $expected['place'] = $after['place'];
            }
            checkEq("$name: the day holds what it held here alone before the change began", $expected, $after);
            if ($after !== null) {
                $named = implode(' ', array_map('strval', (array) ($out['differences'] ?? [])));
                check("$name: nothing of the day is reported as removed", !str_contains($named, 'Removed here'), $named);
            }
        } finally {
            GoogleStand::forget($db, $u);
        }
    };

    // The day's own notes make it an edited day at Google, so the change's master write drops its record there and the
    // store of the stopped change leaves its row following the series, kept for what it holds here alone.
    $withNotes = static fn(array $more): \Closure => static function (object $s, int $id) use ($dayAt, $notes, $more): void {
        $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => $dayAt, 'description' => $notes] + $more);
    };
    $rowOn = static fn(int $id) => (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$id]);
    $kinds = [
        'a tag' => $withNotes(['tagNames' => ['sample', 'own tag']]),
        'a person' => $withNotes(['personNames' => ['Alex Example', 'Sam Sample']]),
        'reminders' => $withNotes(['reminders' => [['minutes' => 25]]]),
        'an icon' => $withNotes(['icon' => 'star']),
        'an answer' => static function (object $s, int $id) use ($dayAt, $notes): void {
            $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => $dayAt, 'description' => $notes]);
            $s->events->throwDeferred();
            $s->events->setAttendance($s->u, $id, 'going', 'this', $dayAt);
        },
        "coordinates picked for the series' place" => $withNotes(['locationLat' => 37.52, 'locationLng' => -122.25]),
        'a trip link' => static function (object $s, int $id) use ($dayAt, $notes, $rowOn): void {
            $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => $dayAt, 'description' => $notes]);
            $s->events->throwDeferred();
            $home = (int) $s->d->calendars->create($s->u, ['name' => 'Sample home'])['id'];
            $trip = (int) $s->events->create($s->u, eventAt($home, 'Sample trip', '2027-07-22', ['isContainer' => true]))['eventId'];
            (new Trips($s->db, $s->d->undo))->attach($s->u, $trip, $rowOn($id));
        },
        'a duplicate decision' => static function (object $s, int $id) use ($dayAt, $notes, $rowOn): void {
            $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => $dayAt, 'description' => $notes]);
            $s->events->throwDeferred();
            $home = (int) $s->d->calendars->create($s->u, ['name' => 'Sample home'])['id'];
            $copy = (int) $s->events->create($s->u, eventAt($home, 'Sample copy', '2027-07-23'))['eventId'];
            $row = $rowOn($id);
            $s->db->insert('event_duplicates', ['user_id' => $s->u, 'event_a' => min($row, $copy), 'event_b' => max($row, $copy), 'status' => 'dismissed', 'basis' => 'owner']);
        },
        'plugin data' => static function (object $s, int $id) use ($dayAt, $notes, $rowOn): void {
            $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => $dayAt, 'description' => $notes]);
            $s->events->throwDeferred();
            $s->db->insert('event_plugin_data', ['event_id' => $rowOn($id), 'plugin_id' => 'sample-plugin', 'k' => 'note', 'v_json' => '"kept"']);
        },
    ];

    // A row that stood and that a store changed, which the plan then moves to its new key: every kind of thing.
    foreach ($kinds as $kind => $give) {
        foreach (['finish', 'putBack'] as $then) {
            scenario("a day with $kind and notes of its own, the series moved an hour later and stopped after its master write, " . ($then === 'finish' ? 'finished' : 'put back'), fn() => $run(
                "$kind, an hour later, stopped after the master write, $then", ['give' => $give, 'change' => 'an hour later', 'stop' => 'after its master write', 'then' => $then]));
        }
    }

    // The two shapes the interleaving seeds found (61349 case 272, 60611 case 153): a day tagged here, then given picked
    // coordinates; the place changed for all events, so the day keeps its own place, text and coordinates; then a change
    // of the series' time or form stopped part way. The store of the stopped change leaves the day's row following the
    // series' place text, its coordinates gone with the text it showed; the day's row stood through it for its tag.
    $taggedPlace = static function (object $s, int $id) use ($dayAt, $first): void {
        $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => $dayAt, 'tagNames' => ['sample', 'own tag']]);
        $s->events->throwDeferred();
        $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => $dayAt, 'locationLat' => 37.52, 'locationLng' => -122.25]);
        $s->events->throwDeferred();
        planned($s->events, $s->u, $id, ['scope' => 'all', 'instanceStart' => $first, 'location' => 'Sample room 3']);
    };
    $remindedPlace = static function (object $s, int $id) use ($dayAt, $notes): void {
        $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => $dayAt, 'description' => $notes, 'reminders' => [['minutes' => 25]]]);
        $s->events->throwDeferred();
        $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => $dayAt, 'location' => 'Sample room 9', 'locationLat' => 37.53, 'locationLng' => -122.25]);
    };
    foreach ([
        ['a day tagged, then given picked coordinates, the place then changed for all events', $taggedPlace, 'made all-day', 'after its master write'],
        ['a day tagged, then given picked coordinates, the place then changed for all events', $taggedPlace, 'an hour later', 'after its master write'],
        ['a day tagged, then given picked coordinates, the place then changed for all events', $taggedPlace, 'made all-day', 'its answer lost'],
        ['a day with notes and reminders, then a place of its own with picked coordinates', $remindedPlace, 'an hour later', 'after its master write'],
        ['a day with notes and reminders, then a place of its own with picked coordinates', $remindedPlace, 'made all-day', 'its answer lost'],
        // The master write of a new place for all events overwrites the day's own place text at Google, and the store
        // reads it back: the row's text changes with its record there, and its coordinates go with the text.
        ['a day with notes and reminders, then a place of its own with picked coordinates', $remindedPlace, 'a new place', 'after its master write'],
    ] as [$what, $give, $change, $stop]) {
        foreach (['finish', 'putBack'] as $then) {
            scenario("$what; the series $change, stopped ($stop), " . ($then === 'finish' ? 'finished' : 'put back') . ': the day keeps its place, text and coordinates', fn() => $run(
                "$what, $change, $stop, $then", ['give' => $give, 'change' => $change, 'stop' => $stop, 'then' => $then]));
        }
    }

    // A day whose place is the series' text with coordinates of its own (its place is its own by them alone), then a new
    // place for all events: the day keeps the old text with its coordinates. Stopped after its master write, the store
    // leaves the day showing the new text, so its coordinates and its ownership of the place go together; both come back.
    foreach (['finish', 'putBack'] as $then) {
        scenario("a day with notes and coordinates picked for the series' place, a new place for all events stopped after its master write, " . ($then === 'finish' ? 'finished' : 'put back') . ': the day keeps the place it showed, coordinates and all',
            fn() => $run("coordinates on the series' place, a new place, stopped after the master write, $then",
                ['give' => $kinds["coordinates picked for the series' place"], 'change' => 'a new place', 'stop' => 'after its master write', 'then' => $then]));
    }

    // A row a store removed. A day whose only own thing is its place, text and picked coordinates: the store of the
    // stopped change leaves it showing the series' text, so nothing holds its row and it goes; recovery brings it back whole.
    $placeOnly = static function (object $s, int $id) use ($dayAt): void {
        $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => $dayAt, 'location' => 'Sample room 9', 'locationLat' => 37.53, 'locationLng' => -122.25]);
    };
    foreach (['finish', 'putBack'] as $then) {
        scenario('a day whose only own thing is its place with picked coordinates, the series moved an hour later and stopped, ' . ($then === 'finish' ? 'finished' : 'put back'), fn() => $run(
            "a place of its own alone, an hour later, stopped after the master write, $then", ['give' => $placeOnly, 'change' => 'an hour later', 'stop' => 'after its master write', 'then' => $then]));
    }
    // A day the half-done series no longer has: the series cut to three events, the answer of its master write lost,
    // so the store removes the fifth day's row with its tag. Put back leaves the rows as they were before the change.
    scenario('a tagged day with notes past a new end, the answer of the count change lost at its master write, put back: the day is back with what it held', fn() => $run(
        'a tag, cut to three events, the master write answer lost, putBack', ['give' => $kinds['a tag'], 'change' => 'three events', 'stop' => 'its answer lost', 'then' => 'putBack']));

    // The series' own row. Its place has picked coordinates; the answer of a new place for all events is lost at its
    // master write, and the store reads the new text back, the coordinates going with the old one. Put back returns
    // the old text at Google and here, so the series is where it was, coordinates and all.
    $seriesPlace = static function (object $s, int $id) use ($first, $dayAt, $notes): void {
        planned($s->events, $s->u, $id, ['scope' => 'all', 'instanceStart' => $first, 'locationLat' => 37.5, 'locationLng' => -122.2]);
        $s->events->throwDeferred();
        $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => $dayAt, 'description' => $notes]);
    };
    scenario("the series' own picked coordinates, the answer of a new place for all events lost at its master write, put back: the series is where it was", fn() => $run(
        "the series' coordinates, a new place, the master write answer lost, putBack", ['give' => $seriesPlace, 'change' => 'a new place', 'stop' => 'its answer lost', 'then' => 'putBack', 'day' => '*']));

    // While the change is half done the day's emptied place is looked up (the geocode sweep fills a place with no
    // coordinates): a lookup of the series' text, on the day's row. Finish gives the day its own place back, the text
    // with the coordinates the person picked for it, never the lookup's.
    $lookup = static function (object $s, int $id, ?array $row): void {
        if ($row !== null) {
            $s->db->update('events', ['location_lat' => 40.1, 'location_lng' => -74.1, 'location_source' => 'lookup', 'location_provider' => 'sample-geocoder',
                'location_placed_at' => '2027-01-01 00:00:00', 'geocoded_at' => '2027-01-01 00:00:00'], 'id = ?', [(int) $row['id']]);
        }
    };
    foreach (['finish', 'putBack'] as $then) {
        scenario('a tagged day with picked coordinates of its own place, the stopped change emptying them, a lookup filling them meanwhile, ' . ($then === 'finish' ? 'finished' : 'put back') . ': the picked coordinates are back', fn() => $run(
            "a lookup while half done, $then", ['give' => $taggedPlace, 'change' => 'an hour later', 'stop' => 'after its master write', 'between' => $lookup, 'then' => $then]));
    }

    // Placed here since: while the change is half done the day is given other coordinates for the same place text (the
    // series' own, which the stopped change never changed, so no store emptied them). They are a change made here
    // since, kept as every field held here alone changed since is.
    $repick = static function (object $s, int $id, ?array $row): void {
        if ($row !== null) {
            $s->db->update('events', ['location_lat' => 38.25, 'location_lng' => -121.5, 'location_source' => 'picked', 'location_provider' => null, 'location_placed_at' => '2027-01-02 00:00:00'], 'id = ?', [(int) $row['id']]);
        }
    };
    foreach (['finish', 'putBack'] as $then) {
        scenario("a day with coordinates picked for the series' place, picked again while the change is half done, " . ($then === 'finish' ? 'finished' : 'put back') . ': the later pick stays', function () use ($run, $kinds, $then, $repick): void {
            $run("picked again while half done, $then", ['give' => $kinds["coordinates picked for the series' place"], 'change' => 'an hour later', 'stop' => 'after its master write', 'between' => $repick, 'then' => $then,
                'expect' => ['tags' => ['sample'], 'people' => ['Alex Example'], 'reminders' => null, 'answer' => 'none', 'icon' => null, 'place' => ['Sample Hall', 38.25, -121.5, 'picked'],
                    'own' => ['location'], 'trip' => [], 'duplicates' => [], 'plugin' => []]]);
        });
    }
};
