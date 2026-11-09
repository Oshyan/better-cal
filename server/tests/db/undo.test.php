<?php

declare(strict_types=1);

// Undo against the real schema: what a change stands on (Undo::conflicts),
// what comes back when something it belonged to is gone, a list's order,
// the default calendar, and whose last change "undo" means. Each block is
// a scenario(): an undo refused where one was expected to go through fails
// that block, and the next still runs.

use BetterCal\Domain\Activity;
use BetterCal\Domain\ActivityContext;
use BetterCal\Domain\Folders;
use BetterCal\Domain\MailIngest;
use BetterCal\Domain\Settings;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $count = static fn(string $sql, array $params = []): int => (int) $db->scalar($sql, $params);
    $order = static fn(int $userId): array => array_map('intval', array_column($db->all('SELECT id FROM calendars WHERE user_id = ? ORDER BY position, id', [$userId]), 'id'));
    $storedDefault = static function (int $userId) use ($db): mixed {
        return json_decode((string) $db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]), true)['defaultCalendarId'] ?? null;
    };

    // --- What a change stands on -------------------------------------------

    $u = freshUser($db);
    $d = domains($db);
    $home = (int) Settings::defaultCalendarId($db, $u);
    // A daily series, and a move of all of it an hour later.
    $series = static fn(string $title, string $day) => (int) $d->events->create($u, eventAt($home, $title, $day, ['rrule' => 'FREQ=DAILY;COUNT=10']))['eventId'];
    $later = static fn(int $id, string $day) => planned($d->events, $u, $id, ['scope' => 'all', 'instanceStart' => $day . 'T18:00:00+00:00', 'start' => $day . 'T19:00:00+00:00', 'end' => $day . 'T20:00:00+00:00']);

    // A calendar made, then an event added to it: undoing the making would
    // take the event with it.
    scenario('undo: a calendar made, then an event added to it', function () use ($db, $d, $u, $count): void {
        $club = (int) $d->calendars->create($u, ['name' => 'Garden club'])['id'];
        $made = lastEntry($db, $u);
        $meeting = (int) $d->events->create($u, eventAt($club, 'First meeting'))['eventId'];
        checkEq('undo: a calendar made, then an event added to it: stale, and both stay', ['stale_undo', 1],
            [refusal(fn() => $d->undo->undoById($u, $made)), $count('SELECT COUNT(*) FROM events WHERE id = ?', [$meeting])]);
        $d->undo->undoById($u, $made, true);
        checkEq('undo: undone anyway, the calendar goes with its event', [0, 0],
            [$count('SELECT COUNT(*) FROM calendars WHERE id = ?', [$club]), $count('SELECT COUNT(*) FROM events WHERE id = ?', [$meeting])]);
    });

    // An event added to a calendar changes nothing of the calendar itself;
    // a folder made, then a calendar filed in it, is stood on.
    scenario('undo: what an event added or a calendar filed stands on', function () use ($db, $d, $u): void {
        $books = (int) $d->calendars->create($u, ['name' => 'Book group'])['id'];
        $d->calendars->patch($u, $books, ['name' => 'Reading group']);
        $renamed = lastEntry($db, $u);
        $d->events->create($u, eventAt($books, 'November book'));
        checkEq('undo: a rename, then an event added to the calendar: still undoes', [null, 'Book group'],
            [refusal(fn() => $d->undo->undoById($u, $renamed)), $db->scalar('SELECT name FROM calendars WHERE id = ?', [$books])]);
        $clubs = (int) $d->folders->create($u, ['name' => 'Clubs'])['id'];
        $folderMade = lastEntry($db, $u);
        $d->folders->place($u, $clubs, $books, 0);
        checkEq('undo: a folder made, then a calendar filed in it: stale, and the filing stays', ['stale_undo', [$books]],
            [refusal(fn() => $d->undo->undoById($u, $folderMade)), Folders::order($db, $clubs)]);
    });

    // A series and the dates of it edited on their own.
    scenario('undo: a series and its dates edited on their own', function () use ($db, $d, $u, $home, $count): void {
        $standup = (int) $d->events->create($u, eventAt($home, 'Standup', '2026-11-02', ['rrule' => 'FREQ=DAILY;COUNT=10']))['eventId'];
        $seriesMade = lastEntry($db, $u);
        planned($d->events, $u, $standup, ['scope' => 'all', 'instanceStart' => '2026-11-02T18:00:00+00:00', 'start' => '2026-11-02T19:00:00+00:00', 'end' => '2026-11-02T20:00:00+00:00']);
        $wholeSeries = lastEntry($db, $u);
        $d->events->patch($u, $standup, ['scope' => 'this', 'instanceStart' => '2026-11-04T19:00:00+00:00', 'title' => 'Standup and demo']);
        $oneDate = lastEntry($db, $u);
        $edited = $count('SELECT COUNT(*) FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$standup]);
        checkEq('undo: a whole series changed, then one date of it edited: stale', 'stale_undo', refusal(fn() => $d->undo->undoById($u, $wholeSeries)));
        checkEq('undo: a series made, then one date of it edited: stale, and the edited date stays', ['stale_undo', 1],
            [refusal(fn() => $d->undo->undoById($u, $seriesMade)), $edited]);
        $d->events->patch($u, $standup, ['scope' => 'this', 'instanceStart' => '2026-11-06T19:00:00+00:00', 'title' => 'Standup, short']);
        checkEq('undo: one date edited, then another of the series: the first still undoes', [null, 1],
            [refusal(fn() => $d->undo->undoById($u, $oneDate)), $count('SELECT COUNT(*) FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$standup])]);
        $d->events->patch($u, $standup, ['scope' => 'this', 'instanceStart' => '2026-11-08T19:00:00+00:00', 'title' => 'Standup, Sunday']);
        $sunday = lastEntry($db, $u);
        planned($d->events, $u, $standup, ['scope' => 'all', 'instanceStart' => '2026-11-02T19:00:00+00:00', 'title' => 'Daily standup']);
        checkEq("undo: one date edited, then the series renamed: still undoes (the date's own row goes)", null, refusal(fn() => $d->undo->undoById($u, $sunday)));
        $d->events->patch($u, $standup, ['scope' => 'this', 'instanceStart' => '2026-11-09T19:00:00+00:00', 'title' => 'Standup, Monday']);
        $monday = lastEntry($db, $u);
        $d->events->deleteEvent($u, $standup, 'this', '2026-11-10T19:00:00+00:00');
        checkEq('undo: one date edited, then another date deleted: still undoes', null, refusal(fn() => $d->undo->undoById($u, $monday)));
    });

    // A series' times stand on its rule and the dates it skips: undoing a
    // time change after a date was deleted, moved off or split from would
    // bring that date back, or leave half the series.
    scenario("undo: a series' times stand on its rule and skipped dates", function () use ($db, $d, $u, $count, $series, $later): void {
        $a = $series('Morning run', '2026-12-01');
        $later($a, '2026-12-01');
        $timeChange = lastEntry($db, $u);
        $d->events->deleteEvent($u, $a, 'this', '2026-12-03T19:00:00+00:00');
        checkEq('undo: a series moved to another time, then one date deleted: stale', 'stale_undo', refusal(fn() => $d->undo->undoById($u, $timeChange)));
        $b = $series('Evening walk', '2026-12-01');
        $later($b, '2026-12-01');
        $timeChange = lastEntry($db, $u);
        planned($d->events, $u, $b, ['scope' => 'following', 'instanceStart' => '2026-12-05T19:00:00+00:00', 'title' => 'Evening walk, longer']);
        checkEq('undo: a series moved to another time, then split from a date on: stale', 'stale_undo', refusal(fn() => $d->undo->undoById($u, $timeChange)));
        $c = $series('Lunch', '2026-12-01');
        $later($c, '2026-12-01');
        $timeChange = lastEntry($db, $u);
        $d->events->setAttendance($u, $c, 'going', 'this', '2026-12-04T19:00:00+00:00');
        $answered = lastEntry($db, $u);
        checkEq('undo: a series moved to another time, then one date marked going: stale', 'stale_undo', refusal(fn() => $d->undo->undoById($u, $timeChange)));
        $d->undo->undoById($u, $answered);
        checkEq("undo: the date's going undone, its row made for it goes too", 0,
            $count('SELECT COUNT(*) FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$c]));
    });

    // Split, then an earlier date deleted: undoing the split is fine (the
    // rule and a skipped date each go back on their own). A date edited on
    // its own, then the series split later on: fine too.
    scenario('undo: a split and the dates around it', function () use ($db, $d, $u, $series): void {
        $e = $series('Swim', '2026-12-01');
        planned($d->events, $u, $e, ['scope' => 'following', 'instanceStart' => '2026-12-06T18:00:00+00:00', 'title' => 'Swim, later']);
        $split = lastEntry($db, $u);
        $d->events->deleteEvent($u, $e, 'this', '2026-12-02T18:00:00+00:00');
        checkEq('undo: a series split, then an earlier date deleted: the split still undoes', null, refusal(fn() => $d->undo->undoById($u, $split)));
        $f = $series('Read', '2026-12-01');
        $d->events->patch($u, $f, ['scope' => 'this', 'instanceStart' => '2026-12-02T18:00:00+00:00', 'title' => 'Read aloud']);
        $aloud = lastEntry($db, $u);
        planned($d->events, $u, $f, ['scope' => 'following', 'instanceStart' => '2026-12-07T18:00:00+00:00', 'title' => 'Read, later']);
        checkEq('undo: a date edited on its own, then the series split after it: still undoes', null, refusal(fn() => $d->undo->undoById($u, $aloud)));

        // A date skipped, then the series split before it: the skip went
        // with the new series, so undoing it on the old one would do nothing.
        $g = $series('Walk', '2026-12-01');
        $d->events->deleteEvent($u, $g, 'this', '2026-12-08T18:00:00+00:00');
        $skipped = lastEntry($db, $u);
        planned($d->events, $u, $g, ['scope' => 'following', 'instanceStart' => '2026-12-06T18:00:00+00:00', 'title' => 'Walk, later']);
        checkEq('undo: a date skipped, then the series split before it: stale', 'stale_undo', refusal(fn() => $d->undo->undoById($u, $skipped)));

        // A split carrying a skipped date, then another date skipped on the
        // old series: since 1.5.1 the split takes the carried skip off the
        // old series (a skip belongs to the occurrence it removed). A skip is
        // its own date's (2026-11-09): the split's undo puts its own skip
        // back and leaves the later one, so it is not stale.
        $h = $series('Jog', '2026-12-01');
        $d->events->deleteEvent($u, $h, 'this', '2026-12-08T18:00:00+00:00');
        planned($d->events, $u, $h, ['scope' => 'following', 'instanceStart' => '2026-12-06T18:00:00+00:00', 'title' => 'Jog, later']);
        $carried = lastEntry($db, $u);
        $d->events->deleteEvent($u, $h, 'this', '2026-12-03T18:00:00+00:00');
        checkEq('undo: a split carrying a skipped date, then an earlier date skipped: undoes, both dates skipped on the series', [null, ['2026-12-03 18:00:00', '2026-12-08 18:00:00']],
            [refusal(fn() => $d->undo->undoById($u, $carried)), json_decode((string) $db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$h]), true)]);
    });

    // A setting turned off from unset is a change, and its undo unsets it.
    scenario('undo: a setting turned off from unset', function () use ($db, $d, $u): void {
        $plain = (int) $d->calendars->create($u, ['name' => 'Plain'])['id'];
        $d->calendars->patch($u, $plain, ['groupSimilar' => false]);
        $d->undo->undoById($u, lastEntry($db, $u));
        checkEq('undo: a setting turned off from unset goes back to unset', false,
            array_key_exists('groupSimilar', json_decode((string) ($db->scalar('SELECT settings_json FROM calendars WHERE id = ?', [$plain]) ?? '{}'), true) ?: []));
    });

    // A location goes back with its place on the map.
    scenario('undo: a location and its place on the map', function () use ($db, $d, $u, $home): void {
        $where = (int) $d->events->create($u, eventAt($home, 'Talk', '2026-12-15'))['eventId'];
        $d->events->patch($u, $where, ['location' => 'Sample Hall']);
        $located = lastEntry($db, $u);
        $db->run('UPDATE events SET location_lat = 45.5, location_lng = -122.6, geocoded_at = NOW() WHERE id = ?', [$where]); // the geocode sweep
        $d->undo->undoById($u, $located);
        checkEq('undo: a location undone takes its map place with it', [null, null],
            array_values($db->one('SELECT location, location_lat FROM events WHERE id = ?', [$where])));
        $d->events->patch($u, $where, ['location' => 'Old Hall', 'locationLat' => 45.5, 'locationLng' => -122.6, 'locationProvider' => 'photon']);
        $d->events->patch($u, $where, ['locationLat' => 45.6, 'locationLng' => -122.7, 'locationProvider' => 'photon']);
        $pinMoved = lastEntry($db, $u);
        $d->events->patch($u, $where, ['location' => 'New Hall']);
        checkEq('undo: a pin moved, then a new address: the pin move is stale, and the address stays', ['stale_undo', 'New Hall'],
            [refusal(fn() => $d->undo->undoById($u, $pinMoved)), $db->scalar('SELECT location FROM events WHERE id = ?', [$where])]);
    });

    // The event view's lookup of an address isn't a change of the
    // person's: their edit still undoes, and the looked-up place goes with it.
    scenario("lookup: the event view's address lookup", function () use ($db, $d, $u, $home): void {
        $looked = (int) $d->events->create($u, eventAt($home, 'Supper', '2026-12-16'))['eventId'];
        $d->events->patch($u, $looked, ['location' => 'Sample Hall']);
        $typed = lastEntry($db, $u);
        checkEq('lookup: placed only while it has no place, and not journaled', [true, false, $typed],
            [$d->events->placeFromLookup($u, $looked, 'Sample Hall', 45.5, -122.6, 'photon'), $d->events->placeFromLookup($u, $looked, 'Sample Hall', 1, 1, null), lastEntry($db, $u)]);
        checkEq('undo: an address typed, then looked up by the event view: still undoes, pin and all', [null, null, null],
            [refusal(fn() => $d->undo->undoById($u, $typed)), ...array_values($db->one('SELECT location, location_lat FROM events WHERE id = ?', [$looked]))]);
        // A lookup that comes back after the address changed (on another
        // device) isn't stored on the new address; nor is half a place, and a
        // provider name is one PATCH would take.
        $moved = (int) $d->events->create($u, eventAt($home, 'Brunch', '2026-12-17'))['eventId'];
        $d->events->patch($u, $moved, ['location' => 'Sample Cafe']);
        checkEq('lookup: a stale address is not placed', [false, null],
            [$d->events->placeFromLookup($u, $moved, 'Old Sample Cafe', 45.5, -122.6, null), $db->scalar('SELECT location_lat FROM events WHERE id = ?', [$moved])]);
        checkEq('lookup: both coordinates are required', 'invalid_request', refusal(fn() => $d->events->placeFromLookup($u, $moved, 'Sample Cafe', 45.5, null, null)));
        $d->events->placeFromLookup($u, $moved, 'Sample Cafe', 45.5, -122.6, 'Not A Provider!');
        checkEq('lookup: a provider name PATCH would refuse is not stored', null, $db->scalar('SELECT location_provider FROM events WHERE id = ?', [$moved]));
        // A new address is looked up afresh, not skipped as tried last month.
        $db->run('UPDATE events SET geocoded_at = NOW() WHERE id = ?', [$looked]);
        $d->events->patch($u, $looked, ['location' => 'Sample Annex']);
        checkEq('lookup: a new address is looked up again', null, $db->scalar('SELECT geocoded_at FROM events WHERE id = ?', [$looked]));
    });

    // A calendar's settings are separate settings: undoing a rename (which
    // stops its name following them) leaves a plugin's station changed since.
    scenario("undo: a calendar's settings are separate settings", function () use ($db, $d, $u): void {
        $tides = (int) $d->calendars->create($u, ['name' => 'Tides, North Point'])['id'];
        $db->run('UPDATE calendars SET settings_json = ? WHERE id = ?', [json_encode(['own' => ['station' => 'north'], 'autoName' => true]), $tides]);
        $d->calendars->patch($u, $tides, ['name' => 'My tides']);
        $renamedTides = lastEntry($db, $u);
        $d->undo->write($u, function () use ($d, $db, $u, $tides): void {
            $db->run('UPDATE calendars SET settings_json = ? WHERE id = ?', [json_encode(['own' => ['station' => 'south']]), $tides]);
            $d->undo->record($u, 'calendar', $tides, 'update', null, null, 'Changed station', touches: [[BetterCal\Domain\Undo::keyOf('calendars', ['id' => $tides]), 'settings_json.own']]);
        });
        checkEq("undo: a rename undone after a plugin's station changed: not stale, the name follows again, the station stays", [null, 'Tides, North Point', ['own' => ['station' => 'south'], 'autoName' => true]], [
            refusal(fn() => $d->undo->undoById($u, $renamedTides)),
            $db->scalar('SELECT name FROM calendars WHERE id = ?', [$tides]),
            json_decode((string) $db->scalar('SELECT settings_json FROM calendars WHERE id = ?', [$tides]), true),
        ]);
    });

    // --- What comes back when something it belonged to is gone -------------

    scenario('undo: a calendar deleted, then its folder', function () use ($db, $d, $u, $count): void {
        $shortLived = (int) $d->folders->create($u, ['name' => 'Short-lived'])['id'];
        $choir = (int) $d->calendars->create($u, ['name' => 'Choir', 'folderIds' => [$shortLived]])['id'];
        $d->events->create($u, eventAt($choir, 'Rehearsal'));
        $d->calendars->delete($u, $choir);
        $calendarDeleted = lastEntry($db, $u);
        $d->folders->delete($u, $shortLived);
        checkEq('undo: a calendar deleted, then its folder: the calendar comes back with its events, not filed', [null, 1, 1, 0], [
            refusal(fn() => $d->undo->undoById($u, $calendarDeleted)),
            $count('SELECT COUNT(*) FROM calendars WHERE id = ?', [$choir]),
            $count('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$choir]),
            $count('SELECT COUNT(*) FROM calendar_folders WHERE calendar_id = ?', [$choir]),
        ]);
    });

    scenario('undo: a folder deleted, then one of its calendars', function () use ($db, $d, $u): void {
        $pair = (int) $d->folders->create($u, ['name' => 'Two in it'])['id'];
        $alpha = (int) $d->calendars->create($u, ['name' => 'Alpha', 'folderIds' => [$pair]])['id'];
        $bravo = (int) $d->calendars->create($u, ['name' => 'Bravo', 'folderIds' => [$pair]])['id'];
        $d->folders->delete($u, $pair);
        $folderDeleted = lastEntry($db, $u);
        $d->calendars->delete($u, $bravo);
        checkEq('undo: a folder deleted, then one of its calendars: the folder comes back with the other', [null, [$alpha]],
            [refusal(fn() => $d->undo->undoById($u, $folderDeleted)), Folders::order($db, $pair)]);
    });

    scenario('undo: a move out of a folder deleted since', function () use ($db, $d, $u): void {
        $from = (int) $d->folders->create($u, ['name' => 'From'])['id'];
        $to = (int) $d->folders->create($u, ['name' => 'To'])['id'];
        $mover = (int) $d->calendars->create($u, ['name' => 'Mover', 'folderIds' => [$from]])['id'];
        $d->folders->place($u, $to, $mover, 0, $from);
        $moved = lastEntry($db, $u);
        $d->folders->delete($u, $from);
        checkEq("undo: a move out of a folder deleted since can't be undone, and it stays where it is", ['not_undoable', [$mover]],
            [refusal(fn() => $d->undo->undoById($u, $moved)), Folders::order($db, $to)]);
    });

    // --- A list's order, and the default calendar --------------------------

    scenario("order and the default calendar: a list numbered afresh, and the default as calendars go", function () use ($db, $order, $storedDefault): void {
        // Calendars the app makes go at the end, and don't become the default.
        $v = freshUser($db);
        $personal = $storedDefault($v);
        $invitations = MailIngest::ensureInviteCalendar($db, $v);
        $ordered = $order($v);
        checkEq('order: the invitations calendar goes at the end of the list', $invitations, end($ordered));
        checkEq('default calendar: set at setup, and a calendar the app makes leaves it be', [true, $personal],
            [is_int($personal), Settings::defaultCalendarId($db, $v)]);

        // A list numbered afresh, then a calendar moved in it: undoing the
        // renumbering would put that one in the wrong place.
        $dv = domains($db);
        $a = (int) $dv->calendars->create($v, ['name' => 'A'])['id'];
        $b = (int) $dv->calendars->create($v, ['name' => 'B'])['id'];
        $c = (int) $dv->calendars->create($v, ['name' => 'C'])['id'];
        // Two in one place, as calendars made before 057 were.
        $db->run('UPDATE calendars SET position = ? WHERE id = ?', [(int) $db->scalar('SELECT position FROM calendars WHERE id = ?', [$a]), $b]);
        $dv->calendars->placeInList($v, $c, array_search($b, $order($v), true));
        $renumbered = lastEntry($db, $v);
        checkEq('order: no room between two, the whole list is numbered afresh', true,
            count(json_decode((string) $db->scalar('SELECT after_json FROM mutations WHERE id = ?', [$renumbered]), true)['tables']['calendars']) > 1);
        $dv->calendars->placeInList($v, $personal, 99);
        checkEq('undo: a list numbered afresh, then a calendar moved in it: stale', 'stale_undo', refusal(fn() => $dv->undo->undoById($v, $renumbered)));

        // The default calendar as calendars are deleted.
        $work = (int) $dv->calendars->create($v, ['name' => 'Work'])['id'];
        $dv->calendars->patch($v, $work, ['name' => 'Work things']);
        $next = $dv->calendars->delete($v, $personal);
        checkEq('default calendar: deleting it moves it to the oldest local calendar left, and says which', [$next, $next],
            [Settings::defaultCalendarId($db, $v), $storedDefault($v)]);
        checkEq('default calendar: deleting another leaves it be', null, $dv->calendars->delete($v, $work));
        $db->run('UPDATE users SET settings_json = ? WHERE id = ?', [json_encode(['defaultCalendarId' => 999999]), $v]);
        checkEq('default calendar: the settings read says the calendar new events go to now, not a stored id gone since',
            Settings::defaultCalendarId($db, $v), (new Settings($db))->resolved($v, (new Settings($db))->forUser($v))['defaultCalendarId']);
        checkEq("default calendar: it can't be set to none", 'invalid_request',
            refusal(fn() => (new Settings($db))->patch($v, ['defaultCalendarId' => null])));
    });

    // --- A toast's own entries ---------------------------------------------

    scenario("entries: a toast's own entries", function () use ($db, $d, $u, $home): void {
        ActivityContext::takeEntries();
        $d->calendars->patch($u, $home, ['color' => '#224466']);
        checkEq("entries: a write notes what it journaled, for its toast's Undo (X-Undo-Entries)", [lastEntry($db, $u)], ActivityContext::takeEntries());
        checkEq('entries: and forgets them once taken', [], ActivityContext::takeEntries());
    });

    // --- Whose last change "undo" means ----------------------------------

    scenario('undo latest: whose last change "undo" means', function () use ($db, $count): void {
        $w = freshUser($db);
        $dw = domains($db);
        $first = (int) Settings::defaultCalendarId($db, $w);
        $dw->calendars->patch($w, $first, ['name' => 'Mine, renamed']);
        $byKey = ActivityContext::with('api', fn() => (int) $dw->calendars->create($w, ['name' => 'Made with a key'])['id']);
        ActivityContext::with('feed', fn() => $dw->undo->record($w, 'calendar', $first, 'update', null, null, 'Feed checked'));
        ActivityContext::with('api', fn() => $dw->undo->undoLatest($w));
        checkEq('undo latest: asked with an API key, the API\'s last change is undone', [0, 'Mine, renamed'],
            [$count('SELECT COUNT(*) FROM calendars WHERE id = ?', [$byKey]), $db->scalar('SELECT name FROM calendars WHERE id = ?', [$first])]);
        $dw->undo->undoLatest($w);
        checkEq("undo latest: from the app, the app's last change, past the feed's", 'Personal', $db->scalar('SELECT name FROM calendars WHERE id = ?', [$first]));
        // A creation is undone by removing what it made, and the change before
        // it is next in line.
        $dw->events->create($w, eventAt($first, 'Original'));
        $edited = (int) $db->scalar('SELECT MAX(id) FROM events WHERE calendar_id = ?', [$first]);
        $dw->events->patch($w, $edited, ['title' => 'Renamed']);
        $made = (int) $dw->events->create($w, eventAt($first, 'New one', '2026-12-09'))['eventId'];
        checkEq('undo latest: right after a creation, the creation is undone', [['event', 'create'], 0],
            [array_values($dw->undo->undoLatest($w)), $count('SELECT COUNT(*) FROM events WHERE id = ?', [$made])]);
        $dw->undo->undoLatest($w);
        checkEq('undo latest: and then the edit before it', 'Original', $db->scalar('SELECT title FROM events WHERE id = ?', [$edited]));
        // Your own last change that can't be undone (one sent to Google): said
        // so, and nothing older is undone instead.
        $dw->events->patch($w, $edited, ['title' => 'Renamed again']);
        // (Journaled as a change sent to Google is: log-only, naming the event it changed.)
        $dw->undo->write($w, fn() => $dw->undo->record($w, 'event', $edited, 'update', null, null, 'Updated at Google', touches: [[BetterCal\Domain\Undo::keyOf('events', ['id' => $edited]), '*']]));
        checkEq("undo latest: your own last change that can't be undone is refused, and nothing older is undone", ['not_undoable', 'Renamed again'],
            [refusal(fn() => $dw->undo->undoLatest($w)), $db->scalar('SELECT title FROM events WHERE id = ?', [$edited])]);
    });

    // Text is compared as text: "01" to "1" is a change, undoes, and makes
    // an older change to that title stale.
    scenario('undo: values compared as stored text', function () use ($db): void {
        $t = freshUser($db);
        $dt = domains($db);
        $tcal = (int) Settings::defaultCalendarId($db, $t);
        $title = static fn(int $id) => $db->scalar('SELECT title FROM events WHERE id = ?', [$id]);
        $num = (int) $dt->events->create($t, eventAt($tcal, '01', '2026-12-20'))['eventId'];
        $dt->events->patch($t, $num, ['title' => '1']);
        $dt->undo->undoById($t, lastEntry($db, $t));
        checkEq('undo: "01" renamed "1", undone, is "01" again', '01', $title($num));
        $older = (int) $dt->events->create($t, eventAt($tcal, 'Original title', '2026-12-21'))['eventId'];
        $dt->events->patch($t, $older, ['title' => '01']);
        $first = lastEntry($db, $t);
        $dt->events->patch($t, $older, ['title' => '1']);
        checkEq('undo: a rename to "01", then "1": the first is stale, the later title stays', ['stale_undo', '1'],
            [refusal(fn() => $dt->undo->undoById($t, $first)), $title($older)]);
        checkEq('undo: values compared as stored text', [true, true, false, false, false],
            [\BetterCal\Domain\Undo::same(1, '1'), \BetterCal\Domain\Undo::same(null, null), \BetterCal\Domain\Undo::same('01', '1'), \BetterCal\Domain\Undo::same('', null), \BetterCal\Domain\Undo::same('100', '1e2')]);
    });

    // A merge keeps the away times of the person merged away (they're the
    // survivor's now), and one undo puts back both people as they were.
    scenario('people: a merge and its undo', function () use ($db): void {
        $m = freshUser($db);
        $folk = new \BetterCal\Domain\People($db);
        $alex = (int) $folk->create($m, ['name' => 'Alex Example'])['id'];
        $sam = (int) $folk->create($m, ['name' => 'Sam Sample'])['id'];
        $alexSpan = (int) $folk->addSpan($m, $alex, ['start' => '2026-12-01T00:00:00Z', 'end' => '2026-12-03T00:00:00Z'])['id'];
        $samSpan = (int) $folk->addSpan($m, $sam, ['start' => '2026-12-10T00:00:00Z', 'end' => '2026-12-12T00:00:00Z'])['id'];
        $owner = static fn(int $span) => (int) $db->scalar('SELECT person_id FROM availability WHERE id = ?', [$span]);
        $folk->update($m, $alex, ['name' => 'Sam Sample']);
        checkEq('people: merged, the away times of both are the survivor\'s', [$sam, $sam], [$owner($alexSpan), $owner($samSpan)]);
        domains($db)->undo->undoById($m, lastEntry($db, $m));
        checkEq('people: the merge undone, each has their own away times again', ['Alex Example', $alex, $sam],
            [$db->scalar('SELECT name FROM people WHERE id = ?', [$alex]), $owner($alexSpan), $owner($samSpan)]);
    });

    // Every kind of time the code accepts is a kind the column holds: "here"
    // arrived in the code long after the column was made for away and busy,
    // and marking someone as here failed on every install until 076.
    scenario('people: every kind of time the code accepts is stored', function () use ($db): void {
        $m = freshUser($db);
        $folk = new \BetterCal\Domain\People($db);
        $alex = (int) $folk->create($m, ['name' => 'Alex Example'])['id'];
        $stored = [];
        foreach (\BetterCal\Domain\People::KINDS as $i => $kind) {
            $day = sprintf('2026-12-%02dT00:00:00Z', 1 + 2 * $i);
            $next = sprintf('2026-12-%02dT00:00:00Z', 2 + 2 * $i);
            $span = (int) $folk->addSpan($m, $alex, ['start' => $day, 'end' => $next, 'kind' => $kind])['id'];
            $stored[] = (string) $db->scalar('SELECT kind FROM availability WHERE id = ?', [$span]);
        }
        checkEq('people: each kind round-trips through the column', \BetterCal\Domain\People::KINDS, $stored);
    });

    // --- The default calendar as calendars come and go ---------------------

    // Deleting the last calendar of the person's own: new events go to the
    // invitations calendar for now, but that isn't a choice, so the next
    // calendar made takes over; undoing the making leaves no choice pointing
    // at a calendar that's gone.
    scenario('default calendar: the last calendar of your own deleted and made again', function () use ($db, $storedDefault): void {
        $c = freshUser($db);
        $dc = domains($db);
        $own = (int) Settings::defaultCalendarId($db, $c);
        $invites = MailIngest::ensureInviteCalendar($db, $c);
        $dc->calendars->delete($c, $own);
        $deleted = lastEntry($db, $c);
        checkEq('default calendar: with only the invitations calendar left it takes new events, and no choice is stored', [$invites, null],
            [Settings::defaultCalendarId($db, $c), $storedDefault($c)]);
        $fresh = (int) $dc->calendars->create($c, ['name' => 'Fresh start'])['id'];
        $madeFresh = lastEntry($db, $c);
        checkEq('default calendar: the next calendar made takes over from the invitations calendar', [$fresh, $fresh],
            [Settings::defaultCalendarId($db, $c), $storedDefault($c)]);
        $dc->undo->undoById($c, $madeFresh);
        checkEq('default calendar: undoing the making leaves no choice pointing at the calendar that went', [null, $invites],
            [$storedDefault($c), Settings::defaultCalendarId($db, $c)]);
        $dc->undo->undoById($c, $deleted);
        checkEq('default calendar: undoing the delete makes it the default again', $own, Settings::defaultCalendarId($db, $c));
    });

    // A choice made since stays: the delete moved the default to the next
    // calendar, the person then chose another, and undoing the delete brings
    // the calendar back without taking the choice away.
    scenario('default calendar: a choice made since a delete stays when the delete is undone', function () use ($db, $storedDefault): void {
        $g = freshUser($db);
        $dg = domains($db);
        $gFirst = (int) Settings::defaultCalendarId($db, $g);
        $gNext = (int) $dg->calendars->create($g, ['name' => 'Next'])['id'];
        $gChosen = (int) $dg->calendars->create($g, ['name' => 'Chosen'])['id'];
        $dg->calendars->delete($g, $gFirst);
        $gDeleted = lastEntry($db, $g);
        checkEq('default calendar: deleting the default moves it to the oldest shown', $gNext, $storedDefault($g));
        Settings::put($db, $g, ['defaultCalendarId' => $gChosen]);
        $dg->undo->undoById($g, $gDeleted);
        checkEq('default calendar: a choice made since the delete stays when the delete is undone', [$gChosen, true],
            [$storedDefault($g), $db->scalar('SELECT id FROM calendars WHERE id = ?', [$gFirst]) !== null]);
    });

    // --- Grouped changes and the account's row ------------------------------

    // A grouped change whose steps change a row and change it back shows
    // nothing in the merged snapshots, but its steps touched the row: an
    // older change to it is stale after it.
    scenario('undo: a grouped change that changed a row and changed it back', function () use ($db): void {
        $r = freshUser($db);
        $dr = domains($db);
        $rcal = (int) Settings::defaultCalendarId($db, $r);
        $round = (int) $dr->events->create($r, eventAt($rcal, 'Round trip', '2026-12-22'))['eventId'];
        $dr->events->patch($r, $round, ['title' => 'Renamed']);
        $renamed = lastEntry($db, $r);
        $dr->undo->write($r, fn() => $dr->undo->group(function () use ($dr, $r, $round): void {
            $dr->events->patch($r, $round, ['title' => 'There']);
            $dr->events->patch($r, $round, ['title' => 'Renamed']);
        }, 'There and back'));
        checkEq('undo: a grouped change that changed a row and changed it back still touched it, so an older change to it is stale', ['There and back', 'stale_undo'],
            [$db->scalar('SELECT summary FROM mutations WHERE id = ?', [lastEntry($db, $r)]), refusal(fn() => $dr->undo->undoById($r, $renamed))]);
    });

    // A plugin calendar deleted: the bands and the status and place rows its
    // plugin kept for it wait out the delete's undo window, then go.
    $tideCal = (int) $d->calendars->create($u, ['name' => 'Tides, Sample Harbor'])['id'];
    $db->run("UPDATE calendars SET kind = 'plugin', plugin_id = 'tides' WHERE id = ?", [$tideCal]);
    $db->run("INSERT INTO plugin_ranges (plugin_id, calendar_id, source_key, start_utc, end_utc, label) VALUES ('tides', ?, ?, '2026-12-01 10:00:00', '2026-12-01 11:00:00', 'Low tide')", [$tideCal, $tideCal . ':low']);
    $db->run("INSERT INTO plugin_kv (plugin_id, k, v_json) VALUES ('tides', ?, '{\"text\": \"38 low tides\"}'), ('tides', ?, '{\"lat\": 1, \"lng\": 2}')", ['__calstatus.' . $tideCal, '__calplace.' . $tideCal]);
    $d->calendars->delete($u, $tideCal);
    $tideDeleted = lastEntry($db, $u);
    $tideRows = static fn(): array => [$count('SELECT COUNT(*) FROM plugin_ranges WHERE calendar_id = ?', [$tideCal]), $count("SELECT COUNT(*) FROM plugin_kv WHERE plugin_id = 'tides' AND k IN (?, ?)", ['__calstatus.' . $tideCal, '__calplace.' . $tideCal])];
    (new Activity($db))->prune();
    checkEq("retention: a deleted plugin calendar's bands and status rows stay while the delete can be undone", [1, 2], $tideRows());
    $db->run('UPDATE mutations SET created_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE id = ?', [$tideDeleted]);
    (new Activity($db))->prune();
    checkEq("retention: they go when the delete's undo window closes", [0, 0], $tideRows());

    // The account's row is held for the whole of a change: another
    // connection waiting for it gives up before the change is done. Every
    // change and every undo takes it first, so none waits on another in the
    // opposite order.
    scenario("undo: the account's row is held for the whole of a change", function () use ($db): void {
        $r = freshUser($db);
        $dr = domains($db);
        $other = otherConnection();
        $other->run('SET SESSION innodb_lock_wait_timeout = 1');
        $heldDuring = null;
        $dr->undo->write($r, function () use ($other, $r, &$heldDuring): void {
            try {
                $other->tx(static fn() => $other->all('SELECT id FROM users WHERE id = ? FOR UPDATE', [$r]));
                $heldDuring = false;
            } catch (\PDOException) {
                $heldDuring = true;
            }
        });
        check("undo: the account's row is held for the whole of a change", $heldDuring === true, var_export($heldDuring, true));
    });

    // A calendar deleted inside group(): there is no entry yet to add its
    // events to, so the delete fails instead of keeping an entry that would
    // bring back an empty calendar.
    scenario('undo: a calendar deleted inside a group fails, and changes nothing', function () use ($db, $count): void {
        $r = freshUser($db);
        $dr = domains($db);
        $cal = (int) $dr->calendars->create($r, ['name' => 'Grouped sample'])['id'];
        $dr->events->create($r, eventAt($cal, 'Grouped sample event'));
        $entries = $count('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$r]);
        $refused = null;
        try {
            $dr->undo->group(fn() => $dr->calendars->delete($r, $cal), 'Grouped delete');
        } catch (\LogicException $e) {
            $refused = $e->getMessage();
        }
        check('undo: a calendar deleted inside a group fails', $refused !== null && str_contains($refused, 'without an entry to add to'), (string) $refused);
        checkEq('undo: and the calendar, its event and the journal are as they were', [1, 1, $entries], [
            $count('SELECT COUNT(*) FROM calendars WHERE id = ?', [$cal]),
            $count('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$cal]),
            $count('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$r]),
        ]);
        $fails = static function (callable $fn): ?string {
            try {
                $fn();
            } catch (\LogicException $e) {
                return $e->getMessage();
            }
            return null;
        };
        $entry = lastEntry($db, $r);
        checkEq("undo: settings noted with no entry fail too, nothing to note is fine, and removed rows of a table undo doesn't put back from pieces are refused", [true, null, true], [
            str_contains((string) $fails(fn() => $dr->undo->noteSettings(0, ['defaultCalendarId' => [$cal, null]])), 'without an entry to add to'),
            $fails(fn() => $dr->undo->noteSettings(0, [])),
            str_contains((string) $fails(fn() => $dr->undo->recordRemoved($entry, ['calendars' => [['id' => $cal]]])), "can't keep calendars rows"),
        ]);
    });

    // The journal keeps text as itself (no \u escapes), and an entry written
    // escaped by an earlier version still undoes the same, kept inline or in
    // pieces.
    scenario('undo: the journal keeps text as itself, and entries written escaped still undo', function () use ($db, $count): void {
        $r = freshUser($db);
        $dr = domains($db);
        $cal = (int) $dr->calendars->create($r, ['name' => "Caf\u{E9} \u{65E5}\u{672C} \u{1F389}"])['id'];
        $event = (int) $dr->events->create($r, eventAt($cal, "Caf\u{E9} \u{4F1A}\u{8B70} \u{1F389} a/b", '2026-12-08', ['description' => "Notes \u{2713} https://example.test/a/b"]))['eventId'];
        $row = static fn(): array => [$db->one('SELECT * FROM events WHERE id = ?', [$event]) ?? [], $db->scalar('SELECT name FROM calendars WHERE id = ?', [$cal])];
        $was = $row();
        $escape = static fn(string $json): string => (string) json_encode(json_decode($json, true), JSON_INVALID_UTF8_SUBSTITUTE);

        $dr->events->deleteEvent($r, $event, null, null);
        $entry = lastEntry($db, $r);
        $stored = (string) $db->scalar('SELECT before_json FROM mutations WHERE id = ?', [$entry]);
        check('undo: an entry keeps its text as itself', str_contains($stored, "\u{4F1A}\u{8B70} \u{1F389} a/b") && str_contains($stored, 'https://example.test/a/b') && !str_contains($stored, '\\u'), $stored);
        $dr->undo->undoById($r, $entry);
        checkRestored('undo: undone, the event is back as it was', $was, $row());

        $dr->events->deleteEvent($r, $event, null, null);
        $entry = lastEntry($db, $r);
        $db->run('UPDATE mutations SET before_json = ? WHERE id = ?', [$escape((string) $db->scalar('SELECT before_json FROM mutations WHERE id = ?', [$entry])), $entry]);
        check('undo: (an entry written escaped, as before)', str_contains((string) $db->scalar('SELECT before_json FROM mutations WHERE id = ?', [$entry]), '\\ud83c\\udf89'));
        $dr->undo->undoById($r, $entry);
        checkRestored('undo: an entry written escaped undoes the same', $was, $row());

        // Its calendar deleted: the event in a piece, the calendar in the entry.
        $dr->calendars->delete($r, $cal);
        $entry = lastEntry($db, $r);
        $piece = (string) $db->scalar("SELECT rows_json FROM mutation_rows WHERE mutation_id = ? AND table_name = 'events'", [$entry]);
        check('undo: a piece keeps its text as itself', str_contains($piece, "\u{4F1A}\u{8B70} \u{1F389} a/b") && !str_contains($piece, '\\u'), $piece);
        $db->run("UPDATE mutation_rows SET rows_json = ? WHERE mutation_id = ? AND table_name = 'events'", [$escape($piece), $entry]);
        $db->run('UPDATE mutations SET before_json = ? WHERE id = ?', [$escape((string) $db->scalar('SELECT before_json FROM mutations WHERE id = ?', [$entry])), $entry]);
        $dr->undo->undoById($r, $entry);
        checkRestored('undo: a calendar delete whose entry and piece were written escaped undoes the same', $was, $row());
        checkEq('undo: (each step its own entry, all undone)', 0, $count('SELECT COUNT(*) FROM mutations WHERE user_id = ? AND undone = 0 AND op = ?', [$r, 'delete']));
    });

    // A calendar moved to Google: its entries can no longer be undone, and
    // the rows they kept in pieces go with them. Other entries' pieces, of
    // this account or another, are left; the daily prune sweeps those whose
    // entry can no longer be undone.
    scenario("undo: a calendar moved to Google drops its own entries' pieces, not another account's", function () use ($db, $count): void {
        $a = freshUser($db);
        $b = freshUser($db);
        $dr = domains($db);
        $moved = (int) $dr->calendars->create($a, ['name' => 'Moving sample'])['id'];
        $kept = (int) $dr->calendars->create($a, ['name' => 'Staying sample'])['id'];
        $theirs = (int) $dr->calendars->create($b, ['name' => 'Another account sample'])['id'];
        // Each event's entry with its row in a piece, as a large one keeps it.
        $inline = \BetterCal\Domain\Undo::$inlineBytes;
        \BetterCal\Domain\Undo::$inlineBytes = 0;
        try {
            $dr->events->create($a, eventAt($moved, 'Moving sample event'));
            $onMoved = lastEntry($db, $a);
            $dr->events->create($a, eventAt($kept, 'Staying sample event'));
            $onKept = lastEntry($db, $a);
            $dr->events->create($b, eventAt($theirs, 'Another account event'));
            $onTheirs = lastEntry($db, $b);
        } finally {
            \BetterCal\Domain\Undo::$inlineBytes = $inline;
        }
        // The other account's entry past its undo window, its pieces not yet
        // swept (as between the prune's two steps).
        $db->run('UPDATE mutations SET before_json = NULL, after_json = NULL WHERE id = ?', [$onTheirs]);
        $pieces = static fn(int $entry): int => $count('SELECT COUNT(*) FROM mutation_rows WHERE mutation_id = ?', [$entry]);
        checkEq('undo: (each entry has its row in a piece)', [1, 1, 1], [$pieces($onMoved), $pieces($onKept), $pieces($onTheirs)]);
        $db->tx(static fn() => \BetterCal\Domain\GoogleMove::endUndoOf($db, $moved));
        checkEq("undo: the move clears its calendar's entries and their pieces, and leaves the others' pieces", [1, 0, 1, 1], [
            $count('SELECT COUNT(*) FROM mutations WHERE id = ? AND before_json IS NULL AND after_json IS NULL', [$onMoved]),
            $pieces($onMoved), $pieces($onKept), $pieces($onTheirs),
        ]);
        \BetterCal\Domain\Undo::dropUnusedPieces($db);
        checkEq("undo: the prune's sweep then takes the other account's, and the entry still undoable keeps its own", [0, 1], [$pieces($onTheirs), $pieces($onKept)]);
    });

    // What refers to a row (OwnFields::REFERENCES: its trip, its duplicate
    // decisions, its plugin data) cascades when the row is deleted, and is
    // what Undo keeps of it: deleting an edited day and undoing that brings
    // back its dismissed duplicate pair and its plugin data with it.
    scenario('undo: an edited day deleted comes back with its duplicate decision and plugin data', function () use ($db, $count): void {
        $u = freshUser($db);
        $d = domains($db);
        $home = (int) Settings::defaultCalendarId($db, $u);
        $series = (int) $d->events->create($u, eventAt($home, 'Sample class', '2027-03-02', ['rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=6']))['eventId'];
        $d->events->patch($u, $series, ['scope' => 'this', 'instanceStart' => '2027-03-09T18:00:00+00:00', 'title' => 'Sample class, guest night']);
        $day = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$series]);
        $other = (int) $d->events->create($u, eventAt($home, 'Sample guest night', '2027-03-09'))['eventId'];
        $db->insert('event_duplicates', ['user_id' => $u, 'event_a' => min($day, $other), 'event_b' => max($day, $other), 'status' => 'dismissed', 'basis' => 'owner']);
        $db->run('INSERT INTO event_plugin_data (event_id, plugin_id, k, v_json) VALUES (?, ?, ?, ?)', [$day, 'sample-plugin', 'note', '{"seats":12}']);
        $refs = static fn(): array => [
            $db->all('SELECT event_a, event_b, status FROM event_duplicates WHERE event_a = ? OR event_b = ?', [$day, $day]),
            $db->all('SELECT event_id, plugin_id, k, v_json FROM event_plugin_data WHERE event_id = ?', [$day]),
        ];
        $was = $refs();
        $d->events->deleteEvent($u, $day, null, null);
        checkEq('undo: deleting the edited day takes its pair and plugin data with it', [0, [[], []]],
            [$count('SELECT COUNT(*) FROM events WHERE id = ?', [$day]), $refs()]);
        $d->undo->undoLatest($u);
        checkEq('undo: one Undo brings the day back, its dismissed pair and its plugin data with it', [1, $was],
            [$count('SELECT COUNT(*) FROM events WHERE id = ?', [$day]), $refs()]);
    });

    // An undo and its CalDAV journal are one transaction, the one that
    // checked the credential: a credential revoked the moment the undo has
    // committed finds no second transaction left to refuse, so CalDAV
    // clients still hear of the undo. The revocation is made by the
    // credential's own lookup, as soon as another connection sees the undo
    // committed.
    scenario('undo: an undo of a delete writes its CalDAV journal in its own transaction', function () use ($db, $count): void {
        $u = freshUser($db);
        $d = domains($db);
        $home = (int) Settings::defaultCalendarId($db, $u);
        $event = (int) $d->events->create($u, eventAt($home, 'Sample supper', '2027-02-11'))['eventId'];
        $d->events->deleteEvent($u, $event, null, null);
        $entry = lastEntry($db, $u);
        [$authority, $revoke] = requestCredentials($db, $u)['session'];
        $other = otherConnection();
        $revoked = false;
        $current = static function () use ($authority, $revoke, $other, $entry, &$revoked): BetterCal\Domain\RequestAuthority {
            if (!$revoked && (int) $other->scalar('SELECT undone FROM mutations WHERE id = ?', [$entry]) === 1) {
                $revoked = true;
                $revoke();
            }
            return $authority;
        };
        $journal = static fn(): int => $count('SELECT COUNT(*) FROM dav_changes WHERE calendar_id = ?', [$home]);
        $was = $journal();
        checkEq('undo: the undo answers ok, with no check after it committed', [null, false],
            [refusal(fn() => BetterCal\Domain\RequestAuthority::during($current, fn() => $d->undo->undoById($u, $entry))), $revoked]);
        checkEq('undo: it is done, the event is back and its journal row is written', [1, null, $was + 1], [
            $count('SELECT undone FROM mutations WHERE id = ?', [$entry]), $db->scalar('SELECT deleted_at FROM events WHERE id = ?', [$event]), $journal(),
        ]);
    });

    // A skip is its own date's, and a row an edit made stands on what it owns (2026-11-09, F1 and F2 of the third read).
    scenario('undo: two dates of a series deleted undo in either order; the edit that made a date\'s row is not staled by another field of it', function () use ($db, $d, $u, $series): void {
        foreach ([false, true] as $reverse) {
            $s = $series($reverse ? 'Sample walk' : 'Sample run', '2027-02-01');
            $d->events->deleteEvent($u, $s, 'this', '2027-02-03T18:00:00+00:00');
            $first = lastEntry($db, $u);
            $d->events->deleteEvent($u, $s, 'this', '2027-02-05T18:00:00+00:00');
            $second = lastEntry($db, $u);
            [$a, $b] = $reverse ? [$second, $first] : [$first, $second];
            checkEq('undo: two skipped dates, ' . ($reverse ? 'newest' : 'oldest') . ' first: both undo, no skip left', [null, null, []],
                [refusal(fn() => $d->undo->undoById($u, $a)), refusal(fn() => $d->undo->undoById($u, $b)),
                    json_decode((string) ($db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$s]) ?? '[]'), true) ?: []]);
        }
        $s = $series('Sample swim', '2027-02-01');
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-02-04T18:00:00+00:00', 'title' => 'Sample swim, long']);
        $made = lastEntry($db, $u);
        $row = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$s]);
        $d->events->patch($u, $row, ['description' => 'Sample notes']);
        checkEq('undo: the edit that made the row undoes; the later notes stay', [null, 'Sample notes'],
            [refusal(fn() => $d->undo->undoById($u, $made)), $db->scalar('SELECT description FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$s])]);
    });

    // One definition of staleness (Rule B, 2026-11-09): grounds are fields, an own list's contents among them; a snapshot entry's ground is what its restore puts back.
    scenario('undo: two edits of different fields of one date undo in either order; a date\'s tags are its field; a clock change stands on the series\' days', function () use ($db, $d, $u, $series): void {
        $rows = static fn(int $s): array => $db->all('SELECT title, location, description, override_fields AS own, (SELECT GROUP_CONCAT(t.name ORDER BY t.name) FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = events.id) AS tags
            FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$s]);
        foreach ([false, true] as $newerFirst) {
            $s = $series($newerFirst ? 'Sample hike' : 'Sample ride', '2027-02-01');
            $at = '2027-02-04T18:00:00+00:00';
            $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => $at, 'description' => 'Sample day notes']);
            $row = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$s]);
            $d->events->patch($u, $row, ['scope' => 'this', 'instanceStart' => $at, 'title' => 'Sample day title']);
            $title = lastEntry($db, $u);
            $d->events->patch($u, $row, ['scope' => 'this', 'instanceStart' => $at, 'location' => 'Sample room Q']);
            $place = lastEntry($db, $u);
            $errors = array_map(fn(int $e) => refusal(fn() => $d->undo->undoById($u, $e)), $newerFirst ? [$place, $title] : [$title, $place]);
            checkEq('undo: title and place of one date, ' . ($newerFirst ? 'newer' : 'older') . ' first: both undo, the notes stay its own (k4)',
                [[null, null], [['title' => $newerFirst ? 'Sample hike' : 'Sample ride', 'location' => null, 'description' => 'Sample day notes', 'own' => '["description"]', 'tags' => null]]],
                [$errors, $rows($s)]);
        }
        $s = $series('Sample yoga', '2027-02-01');
        $at = '2027-02-04T18:00:00+00:00';
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => $at, 'tagNames' => ['Sample tag A']]);
        $first = lastEntry($db, $u);
        $row = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$s]);
        $d->events->patch($u, $row, ['scope' => 'this', 'instanceStart' => $at, 'tagNames' => ['Sample tag A', 'Sample tag B']]);
        checkEq('undo: the edit that gave a date its tags is stale after a later tag change of that date; both tags stay (k6)', ['stale_undo', 'Sample tag A,Sample tag B'],
            [refusal(fn() => $d->undo->undoById($u, $first)), $rows($s)[0]['tags'] ?? null]);
        foreach ([false, true] as $tagsFirst) {
            $s = $series($tagsFirst ? 'Sample swim' : 'Sample row', '2027-02-01');
            $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => $at, 'title' => 'Sample day title']);
            $made = lastEntry($db, $u);
            $row = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$s]);
            $d->events->patch($u, $row, ['scope' => 'this', 'instanceStart' => $at, 'tagNames' => ['Sample tag C']]);
            $tags = lastEntry($db, $u);
            $errors = array_map(fn(int $e) => refusal(fn() => $d->undo->undoById($u, $e)), $tagsFirst ? [$tags, $made] : [$made, $tags]);
            checkEq('undo: a date\'s row made by one edit and tagged by another, ' . ($tagsFirst ? 'tags' : 'title') . ' undone first: both undo and no row is left (k1)',
                [[null, null], []], [$errors, $rows($s)]);
        }
        $s = $series('Sample dance', '2027-02-01');
        planned($d->events, $u, $s, ['scope' => 'all', 'instanceStart' => '2027-02-01T18:00:00+00:00', 'start' => '2027-02-01T19:00:00+00:00', 'end' => '2027-02-01T20:00:00+00:00']);
        $clock = lastEntry($db, $u);
        $d->events->deleteEvent($u, $s, 'this', '2027-02-04T19:00:00+00:00');
        checkEq('undo: a clock change for all events is stale once a date at the new clock was skipped: its restore puts every date back (t1, a calendar here)', 'stale_undo',
            refusal(fn() => $d->undo->undoById($u, $clock)));
    });

    // A snapshot undo leaves every row following the series it restores, and the days a change brought into being are its ground (rules B and C, the fifth adversarial read's p6 and p11 on a calendar here).
    scenario('undo: a count raise undone after a later edit of a day it added is stale, saying why; forced, the day goes and is named; a rename undone leaves a row made since following the old title', function () use ($db, $d, $u, $series): void {
        $s = $series('Sample stretch', '2027-02-01');
        planned($d->events, $u, $s, ['scope' => 'all', 'instanceStart' => '2027-02-01T18:00:00+00:00', 'rrule' => 'FREQ=DAILY;COUNT=14']);
        $count = lastEntry($db, $u);
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-02-13T18:00:00+00:00', 'title' => 'Sample day title', 'tagNames' => ['Sample tag D']]);
        $row = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$s]);
        $caught = null;
        try {
            $d->undo->undoById($u, $count);
        } catch (BetterCal\Http\HttpError $e) {
            $caught = $e;
        }
        checkEq('undo: stale, naming the day the count raise brought into being (p6)', ['stale_undo', true],
            [$caught?->errorCode, $caught !== null && str_contains($caught->getMessage(), 'Sat 13 Feb 2027 exists because of this change')]);
        $d->undo->undoById($u, $count, true);
        $note = $db->one('SELECT summary, details_json FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$u]);
        checkEq('undo: forced, the count is back, no row is left past the end, and a line names the day by row and date (p6)', [
            'FREQ=DAILY;COUNT=10', [], [[$row, '2027-02-13']],
        ], [$db->scalar('SELECT rrule FROM events WHERE id = ?', [$s]), $db->all('SELECT id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$s]),
            array_map(static fn(array $x): array => [(int) $x['id'], (string) $x['day']], json_decode((string) $note['details_json'], true)['removedDays'] ?? [])]);
        $s = $series('Sample walk', '2027-02-01');
        planned($d->events, $u, $s, ['scope' => 'all', 'instanceStart' => '2027-02-01T18:00:00+00:00', 'title' => 'Sample renamed']);
        $rename = lastEntry($db, $u);
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-02-03T18:00:00+00:00', 'description' => 'Sample day notes']);
        checkEq('undo: the rename undoes; the row made since shows the old title with its own notes (p11)', [null, [['Sample walk', 'Sample day notes']]],
            [refusal(fn() => $d->undo->undoById($u, $rename)), array_map(static fn(array $r): array => [$r['title'], $r['description']],
                $db->all('SELECT title, description FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$s]))]);
    });

    // The sixth adversarial read on a calendar here: the days a change added are its planner's record (f6), a place
    // is its text and its coordinates (f8), and a row brought back where a row holds its day is its snapshot under that row's id (f9, f10).
    scenario('undo: an all-day change in a zone west of UTC, then a day edit: not refused as a day the change added (f6)', function () use ($db, $d, $u, $home): void {
        $s = (int) $d->events->create($u, ['calendarId' => $home, 'title' => 'Sample west', 'start' => '2027-01-04T09:00:00-08:00', 'end' => '2027-01-04T10:00:00-08:00',
            'tzid' => 'America/Los_Angeles', 'allDay' => false, 'rrule' => 'FREQ=WEEKLY;COUNT=8'])['eventId'];
        planned($d->events, $u, $s, ['scope' => 'all', 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true]);
        $allDay = lastEntry($db, $u);
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-01-18', 'title' => 'Sample day title']);
        $words = null;
        try {
            $d->undo->undoById($u, $allDay);
        } catch (BetterCal\Http\HttpError $e) {
            $words = $e->getMessage();
        }
        checkEq('undo: no "exists because of this change" for a day the all-day change only carried', false, str_contains((string) $words, 'exists because'));
    });

    scenario('undo: a place renamed for all events without coordinates clears the series\' coordinates; undone, a row made since shows the old place with its coordinates (f8)', function () use ($db, $d, $u, $home): void {
        $s = (int) $d->events->create($u, eventAt($home, 'Sample placed', '2027-01-04', ['rrule' => 'FREQ=WEEKLY;COUNT=8', 'location' => 'Sample Hall', 'locationLat' => 37.1, 'locationLng' => -122.1]))['eventId'];
        planned($d->events, $u, $s, ['scope' => 'all', 'location' => 'Sample Annex']);
        $rename = lastEntry($db, $u);
        $master = $db->one('SELECT location, location_lat FROM events WHERE id = ?', [$s]);
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-01-18T18:00:00+00:00', 'description' => 'Sample day notes']);
        $error = refusal(fn() => $d->undo->undoById($u, $rename));
        $row = $db->one('SELECT location, location_lat, location_lng FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$s]);
        checkEq('undo: the rename leaves no coordinates; undone, the row takes the place text and coordinates together', [['Sample Annex', null], null, ['Sample Hall', 37.1, -122.1]],
            [[$master['location'], $master['location_lat']], $error, [$row['location'] ?? null, (float) ($row['location_lat'] ?? 0), (float) ($row['location_lng'] ?? 0)]]);
    });

    scenario('undo: a forced undo bringing a day back where a row holds that day now puts its snapshot back under that row\'s id, no database error (f9, f10)', function () use ($db, $d, $u, $home): void {
        $s = (int) $d->events->create($u, eventAt($home, 'Sample count', '2027-01-04', ['rrule' => 'FREQ=WEEKLY;COUNT=8']))['eventId'];
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-02-15T18:00:00+00:00', 'title' => 'Sample A', 'tagNames' => ['Sample tag A']]);
        planned($d->events, $u, $s, ['scope' => 'all', 'rrule' => 'FREQ=WEEKLY;COUNT=4']);
        $lower = lastEntry($db, $u);
        planned($d->events, $u, $s, ['scope' => 'all', 'rrule' => 'FREQ=WEEKLY;COUNT=8']);
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-02-15T18:00:00+00:00', 'description' => 'Sample B notes']);
        $held = (int) $db->scalar("SELECT id FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = '2027-02-15 18:00:00'", [$s]);
        $forced = refusal(fn() => $d->undo->undoById($u, $lower, true));
        checkEq('undo: forced, one row at the day, the snapshot as it was under the id of the row there (f9)', [null, [[$held, 'Sample A', null]]],
            [$forced, array_map(static fn(array $r): array => [(int) $r['id'], $r['title'], $r['description']],
                $db->all("SELECT id, title, description FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = '2027-02-15 18:00:00' AND deleted_at IS NULL", [$s]))]);
        $s = (int) $d->events->create($u, eventAt($home, 'Sample bound', '2027-01-04', ['rrule' => 'FREQ=WEEKLY;COUNT=8']))['eventId'];
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-01-04T18:00:00+00:00', 'title' => 'Sample A']);
        planned($d->events, $u, $s, ['scope' => 'all', 'start' => '2027-01-11T18:00:00+00:00', 'end' => '2027-01-11T19:00:00+00:00']);
        $bound = lastEntry($db, $u);
        planned($d->events, $u, $s, ['scope' => 'all', 'start' => '2027-01-04T18:00:00+00:00', 'end' => '2027-01-04T19:00:00+00:00']);
        $d->events->patch($u, $s, ['scope' => 'this', 'instanceStart' => '2027-01-04T18:00:00+00:00', 'description' => 'Sample B notes']);
        $held = (int) $db->scalar("SELECT id FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = '2027-01-04 18:00:00'", [$s]);
        $forced = refusal(fn() => $d->undo->undoById($u, $bound, true));
        checkEq('undo: a later start bound brought the day back; forced, the snapshot under the id of the row there (f10)', [null, [[$held, 'Sample A', null]]],
            [$forced, array_map(static fn(array $r): array => [(int) $r['id'], $r['title'], $r['description']],
                $db->all("SELECT id, title, description FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = '2027-01-04 18:00:00' AND deleted_at IS NULL", [$s]))]);
    });
};
