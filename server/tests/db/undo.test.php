<?php

declare(strict_types=1);

// Undo against the real schema: what a change stands on (Undo::conflicts),
// what comes back when something it belonged to is gone, a list's order,
// the default calendar, and whose last change "undo" means.

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

    // A calendar made, then an event added to it: undoing the making would
    // take the event with it.
    $u = freshUser($db);
    $d = domains($db);
    $club = (int) $d->calendars->create($u, ['name' => 'Garden club'])['id'];
    $made = lastEntry($db, $u);
    $meeting = (int) $d->events->create($u, eventAt($club, 'First meeting'))['eventId'];
    checkEq('undo: a calendar made, then an event added to it: stale, and both stay', ['stale_undo', 1],
        [refusal(fn() => $d->undo->undoById($u, $made)), $count('SELECT COUNT(*) FROM events WHERE id = ?', [$meeting])]);
    $d->undo->undoById($u, $made, true);
    checkEq('undo: undone anyway, the calendar goes with its event', [0, 0],
        [$count('SELECT COUNT(*) FROM calendars WHERE id = ?', [$club]), $count('SELECT COUNT(*) FROM events WHERE id = ?', [$meeting])]);

    // An event added to a calendar changes nothing of the calendar itself.
    $books = (int) $d->calendars->create($u, ['name' => 'Book group'])['id'];
    $d->calendars->patch($u, $books, ['name' => 'Reading group']);
    $renamed = lastEntry($db, $u);
    $d->events->create($u, eventAt($books, 'November book'));
    checkEq('undo: a rename, then an event added to the calendar: still undoes', [null, 'Book group'],
        [refusal(fn() => $d->undo->undoById($u, $renamed)), $db->scalar('SELECT name FROM calendars WHERE id = ?', [$books])]);

    // A folder made, then a calendar filed in it.
    $clubs = (int) $d->folders->create($u, ['name' => 'Clubs'])['id'];
    $folderMade = lastEntry($db, $u);
    $d->folders->place($u, $clubs, $books, 0);
    checkEq('undo: a folder made, then a calendar filed in it: stale, and the filing stays', ['stale_undo', [$books]],
        [refusal(fn() => $d->undo->undoById($u, $folderMade)), Folders::order($db, $clubs)]);

    // A series and the dates of it edited on their own.
    $home = (int) Settings::defaultCalendarId($db, $u);
    $standup = (int) $d->events->create($u, eventAt($home, 'Standup', '2026-11-02', ['rrule' => 'FREQ=DAILY;COUNT=10']))['eventId'];
    $seriesMade = lastEntry($db, $u);
    $d->events->patch($u, $standup, ['scope' => 'all', 'instanceStart' => '2026-11-02T18:00:00+00:00', 'start' => '2026-11-02T19:00:00+00:00', 'end' => '2026-11-02T20:00:00+00:00']);
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
    $d->events->patch($u, $standup, ['scope' => 'all', 'instanceStart' => '2026-11-02T19:00:00+00:00', 'title' => 'Daily standup']);
    checkEq("undo: one date edited, then the series renamed: still undoes (the date's own row goes)", null, refusal(fn() => $d->undo->undoById($u, $sunday)));
    $d->events->patch($u, $standup, ['scope' => 'this', 'instanceStart' => '2026-11-09T19:00:00+00:00', 'title' => 'Standup, Monday']);
    $monday = lastEntry($db, $u);
    $d->events->deleteEvent($u, $standup, 'this', '2026-11-10T19:00:00+00:00');
    checkEq('undo: one date edited, then another date deleted: still undoes', null, refusal(fn() => $d->undo->undoById($u, $monday)));

    // A series' times stand on its rule and the dates it skips: undoing a
    // time change after a date was deleted, moved off or split from would
    // bring that date back, or leave half the series.
    $series = static fn(string $title, string $day) => (int) $d->events->create($u, eventAt($home, $title, $day, ['rrule' => 'FREQ=DAILY;COUNT=10']))['eventId'];
    $later = static fn(int $id, string $day) => $d->events->patch($u, $id, ['scope' => 'all', 'instanceStart' => $day . 'T18:00:00+00:00', 'start' => $day . 'T19:00:00+00:00', 'end' => $day . 'T20:00:00+00:00']);
    $a = $series('Morning run', '2026-12-01');
    $later($a, '2026-12-01');
    $timeChange = lastEntry($db, $u);
    $d->events->deleteEvent($u, $a, 'this', '2026-12-03T19:00:00+00:00');
    checkEq('undo: a series moved to another time, then one date deleted: stale', 'stale_undo', refusal(fn() => $d->undo->undoById($u, $timeChange)));
    $b = $series('Evening walk', '2026-12-01');
    $later($b, '2026-12-01');
    $timeChange = lastEntry($db, $u);
    $d->events->patch($u, $b, ['scope' => 'following', 'instanceStart' => '2026-12-05T19:00:00+00:00', 'title' => 'Evening walk, longer']);
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

    // Split, then an earlier date deleted: undoing the split is fine (the
    // rule and a skipped date each go back on their own). A date edited on
    // its own, then the series split later on: fine too.
    $e = $series('Swim', '2026-12-01');
    $d->events->patch($u, $e, ['scope' => 'following', 'instanceStart' => '2026-12-06T18:00:00+00:00', 'title' => 'Swim, later']);
    $split = lastEntry($db, $u);
    $d->events->deleteEvent($u, $e, 'this', '2026-12-02T18:00:00+00:00');
    checkEq('undo: a series split, then an earlier date deleted: the split still undoes', null, refusal(fn() => $d->undo->undoById($u, $split)));
    $f = $series('Read', '2026-12-01');
    $d->events->patch($u, $f, ['scope' => 'this', 'instanceStart' => '2026-12-02T18:00:00+00:00', 'title' => 'Read aloud']);
    $aloud = lastEntry($db, $u);
    $d->events->patch($u, $f, ['scope' => 'following', 'instanceStart' => '2026-12-07T18:00:00+00:00', 'title' => 'Read, later']);
    checkEq('undo: a date edited on its own, then the series split after it: still undoes', null, refusal(fn() => $d->undo->undoById($u, $aloud)));

    // A date skipped, then the series split before it: the skip went with
    // the new series, so undoing it on the old one would do nothing.
    $g = $series('Walk', '2026-12-01');
    $d->events->deleteEvent($u, $g, 'this', '2026-12-08T18:00:00+00:00');
    $skipped = lastEntry($db, $u);
    $d->events->patch($u, $g, ['scope' => 'following', 'instanceStart' => '2026-12-06T18:00:00+00:00', 'title' => 'Walk, later']);
    checkEq('undo: a date skipped, then the series split before it: stale', 'stale_undo', refusal(fn() => $d->undo->undoById($u, $skipped)));

    // A setting turned off from unset is a change, and its undo unsets it.
    $plain = (int) $d->calendars->create($u, ['name' => 'Plain'])['id'];
    $d->calendars->patch($u, $plain, ['groupSimilar' => false]);
    $d->undo->undoById($u, lastEntry($db, $u));
    checkEq('undo: a setting turned off from unset goes back to unset', false,
        array_key_exists('groupSimilar', json_decode((string) ($db->scalar('SELECT settings_json FROM calendars WHERE id = ?', [$plain]) ?? '{}'), true) ?: []));

    // A location goes back with its place on the map.
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

    // The event view's lookup of an address isn't a change of the
    // person's: their edit still undoes, and the looked-up place goes with it.
    $looked = (int) $d->events->create($u, eventAt($home, 'Supper', '2026-12-16'))['eventId'];
    $d->events->patch($u, $looked, ['location' => 'Sample Hall']);
    $typed = lastEntry($db, $u);
    checkEq('lookup: placed only while it has no place, and not journaled', [true, false, $typed],
        [$d->events->placeFromLookup($u, $looked, 45.5, -122.6, 'photon'), $d->events->placeFromLookup($u, $looked, 1, 1, null), lastEntry($db, $u)]);
    checkEq('undo: an address typed, then looked up by the event view: still undoes, pin and all', [null, null, null],
        [refusal(fn() => $d->undo->undoById($u, $typed)), ...array_values($db->one('SELECT location, location_lat FROM events WHERE id = ?', [$looked]))]);
    // A new address is looked up afresh, not skipped as tried last month.
    $db->run('UPDATE events SET geocoded_at = NOW() WHERE id = ?', [$looked]);
    $d->events->patch($u, $looked, ['location' => 'Sample Annex']);
    checkEq('lookup: a new address is looked up again', null, $db->scalar('SELECT geocoded_at FROM events WHERE id = ?', [$looked]));

    // A split carrying a skipped date, then another date skipped on the old
    // series: the split still undoes (its mark is one way).
    $h = $series('Jog', '2026-12-01');
    $d->events->deleteEvent($u, $h, 'this', '2026-12-08T18:00:00+00:00');
    $d->events->patch($u, $h, ['scope' => 'following', 'instanceStart' => '2026-12-06T18:00:00+00:00', 'title' => 'Jog, later']);
    $carried = lastEntry($db, $u);
    $d->events->deleteEvent($u, $h, 'this', '2026-12-03T18:00:00+00:00');
    checkEq('undo: a split carrying a skipped date, then an earlier date skipped: the split still undoes', null, refusal(fn() => $d->undo->undoById($u, $carried)));

    // A calendar's settings are separate settings: undoing a rename (which
    // stops its name following them) leaves a plugin's station changed since.
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

    // --- What comes back when something it belonged to is gone -------------

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

    $pair = (int) $d->folders->create($u, ['name' => 'Two in it'])['id'];
    $alpha = (int) $d->calendars->create($u, ['name' => 'Alpha', 'folderIds' => [$pair]])['id'];
    $bravo = (int) $d->calendars->create($u, ['name' => 'Bravo', 'folderIds' => [$pair]])['id'];
    $d->folders->delete($u, $pair);
    $folderDeleted = lastEntry($db, $u);
    $d->calendars->delete($u, $bravo);
    checkEq('undo: a folder deleted, then one of its calendars: the folder comes back with the other', [null, [$alpha]],
        [refusal(fn() => $d->undo->undoById($u, $folderDeleted)), Folders::order($db, $pair)]);

    $from = (int) $d->folders->create($u, ['name' => 'From'])['id'];
    $to = (int) $d->folders->create($u, ['name' => 'To'])['id'];
    $mover = (int) $d->calendars->create($u, ['name' => 'Mover', 'folderIds' => [$from]])['id'];
    $d->folders->place($u, $to, $mover, 0, $from);
    $moved = lastEntry($db, $u);
    $d->folders->delete($u, $from);
    checkEq("undo: a move out of a folder deleted since can't be undone, and it stays where it is", ['not_undoable', [$mover]],
        [refusal(fn() => $d->undo->undoById($u, $moved)), Folders::order($db, $to)]);

    // --- A list's order ----------------------------------------------------

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

    // --- The default calendar ----------------------------------------------

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

    // --- A toast's own entries ---------------------------------------------

    ActivityContext::takeEntries();
    $d->calendars->patch($u, $home, ['color' => '#224466']);
    checkEq("entries: a write notes what it journaled, for its toast's Undo (X-Undo-Entries)", [lastEntry($db, $u)], ActivityContext::takeEntries());
    checkEq('entries: and forgets them once taken', [], ActivityContext::takeEntries());

    // --- Whose last change "undo" means ----------------------------------

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
    $dw->undo->record($w, 'event', $edited, 'update', null, null, 'Updated at Google');
    checkEq("undo latest: your own last change that can't be undone is refused, and nothing older is undone", ['not_undoable', 'Renamed again'],
        [refusal(fn() => $dw->undo->undoLatest($w)), $db->scalar('SELECT title FROM events WHERE id = ?', [$edited])]);
};
