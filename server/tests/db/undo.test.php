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
    checkEq('undo: one date edited, then the series renamed: stale', 'stale_undo', refusal(fn() => $d->undo->undoById($u, $sunday)));

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
    checkEq("default calendar: it can't be set to none", 'invalid_request',
        refusal(fn() => (new Settings($db))->patch($v, ['defaultCalendarId' => null])));

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
};
