<?php

declare(strict_types=1);

// An import never brings back what was taken out of the calendar here (#255):
// one answer for a deleted row, a deleted repeating event, and a date the
// stored repeating event skips, which is how one ordinary day is deleted and
// how a day moved to another calendar is stored. Each case imports a file
// holding the same repeating event and an edited version of the day, into
// the calendar it was taken out of. Invented data.

use BetterCal\Domain\CalendarImport;
use BetterCal\Domain\Settings;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $vevent = static fn(string $uid, string $body): string => "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20261101T000000Z\r\n$body\r\nEND:VEVENT\r\n";
    // The repeating event as a file has it, with edited days by RECURRENCE-ID
    // line, and any extra VEVENTs.
    $file = static function (string $uid, array $days, string $extra = '') use ($vevent): string {
        $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\n"
            . $vevent($uid, "DTSTART:20270104T090000Z\r\nDTEND:20270104T100000Z\r\nRRULE:FREQ=WEEKLY;COUNT=8\r\nSUMMARY:Sample weekly");
        foreach ($days as $recurrenceId => $day) {
            $out .= $vevent($uid, "$recurrenceId\r\nDTSTART:{$day}T110000Z\r\nDTEND:{$day}T120000Z\r\nSUMMARY:Sample edited day");
        }
        return $out . $extra . "END:VCALENDAR\r\n";
    };
    // Weekly on Mondays at 09:00 UTC from 4 January 2027, eight of them.
    $weekly = static function (int $u, int $cal) use ($db): array {
        $id = (int) domains($db)->events->create($u, ['calendarId' => $cal, 'title' => 'Sample weekly',
            'start' => '2027-01-04T09:00:00Z', 'end' => '2027-01-04T10:00:00Z', 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=8'])['eventId'];
        return [$id, (string) $db->scalar('SELECT uid FROM events WHERE id = ?', [$id])];
    };
    $import = static fn(int $u, int $cal, string $ics): array => (new CalendarImport($db, domains($db)->calendars))->importIcs($u, $ics, 'ignored', null, $cal);
    $counts = static fn(array $out): array => [$out['imported'], $out['deletedBefore'], $out['alreadyThere']];
    $liveDays = static fn(int $cal, string $uid): int => (int) $db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NOT NULL AND deleted_at IS NULL', [$cal, $uid]);
    $skips = static fn(int $id): array => json_decode((string) $db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$id]), true) ?? [];

    scenario('import: a day deleted from a repeating event here is left out and counted; the Activity line says so', function () use ($db, $file, $weekly, $import, $counts, $liveDays, $skips, $vevent): void {
        $u = freshUser($db);
        $cal = Settings::defaultCalendarId($db, $u);
        [$id, $uid] = $weekly($u, $cal);
        domains($db)->events->deleteEvent($u, $id, 'this', '2027-01-18T09:00:00Z');
        checkEq('import: the deleted day is a skipped date with no row', [['2027-01-18 09:00:00'], 0], [$skips($id), $liveDays($cal, $uid)]);
        $other = $vevent('sample-new-event@example.test', "DTSTART:20270120T090000Z\r\nDTEND:20270120T100000Z\r\nSUMMARY:Sample new");
        $out = $import($u, $cal, $file($uid, ['RECURRENCE-ID:20270118T090000Z' => '20270118'], $other));
        checkEq('import: the new event comes in, the repeating event is already there, the deleted day is left out and counted', [1, 1, 1], $counts($out));
        checkEq('import: no live day comes back, and the date stays skipped', [0, ['2027-01-18 09:00:00']], [$liveDays($cal, $uid), $skips($id)]);
        checkEq('import: the Activity line says how many were left out, in words true of every kind', true,
            str_ends_with((string) $db->scalar('SELECT summary FROM mutations WHERE id = ?', [lastEntry($db, $u)]), '; 1 left out, removed from this calendar before'));
    });

    scenario('import: a day named by date alone is read under the stored repeating event, and left out when that day was deleted', function () use ($db, $file, $weekly, $import, $counts, $liveDays): void {
        $u = freshUser($db);
        $cal = Settings::defaultCalendarId($db, $u);
        [$id, $uid] = $weekly($u, $cal);
        domains($db)->events->deleteEvent($u, $id, 'this', '2027-01-18T09:00:00Z');
        $out = $import($u, $cal, $file($uid, ['RECURRENCE-ID;VALUE=DATE:20270118' => '20270118']));
        checkEq('import: a RECURRENCE-ID of the deleted date is that day, left out and counted', [0, 1, 1], $counts($out));
        checkEq('import: no live day comes back', 0, $liveDays($cal, $uid));
    });

    scenario('import: an edited day that was deleted is left out and counted', function () use ($db, $file, $weekly, $import, $counts, $liveDays, $skips): void {
        $u = freshUser($db);
        $cal = Settings::defaultCalendarId($db, $u);
        [$id, $uid] = $weekly($u, $cal);
        $d = domains($db);
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-18T09:00:00Z', 'title' => 'Sample own day']);
        checkEq('import: the edited day has a row of its own', 1, $liveDays($cal, $uid));
        $d->events->deleteEvent($u, $id, 'this', '2027-01-18T09:00:00Z');
        checkEq('import: deleted, it is a skipped date with no row', [['2027-01-18 09:00:00'], 0], [$skips($id), $liveDays($cal, $uid)]);
        $out = $import($u, $cal, $file($uid, ['RECURRENCE-ID:20270118T090000Z' => '20270118']));
        checkEq('import: the day is left out and counted', [0, 1, 1], $counts($out));
        checkEq('import: no live day comes back', 0, $liveDays($cal, $uid));
    });

    // Where a moved day came from: the event it became keeps its repeating
    // event's uid and the day's key, as the skip stores it.
    $link = static fn(int $cal): array => array_map(static fn(array $r): array => [$r['moved_from_uid'], $r['moved_from_key']],
        $db->all('SELECT moved_from_uid, moved_from_key FROM events WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY id', [$cal]));
    $countMoved = static fn(array $out): array => array_map(static fn(array $m): array => [$m['name'], $m['count']], $out['movedAway']);
    $summary = static fn(int $u): string => (string) $db->scalar('SELECT summary FROM mutations WHERE id = ?', [lastEntry($db, $u)]);

    scenario('import: a day moved to another calendar is left out of this one and counted apart, by where it is now', function () use ($db, $file, $weekly, $import, $counts, $skips, $link, $countMoved, $summary, $vevent): void {
        $u = freshUser($db);
        $cal = Settings::defaultCalendarId($db, $u);
        [$id, $uid] = $weekly($u, $cal);
        $d = domains($db);
        $work = (int) $d->calendars->create($u, ['name' => 'Sample work'])['id'];
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-25T09:00:00Z', 'calendarId' => $work]);
        $moved = $db->all('SELECT uid FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$work]);
        checkEq('import: the moved day is a skipped date here and an event of another uid there, which keeps where it came from', [['2027-01-25 09:00:00'], 1, false, [[$uid, '2027-01-25 09:00:00']]],
            [$skips($id), count($moved), ($moved[0]['uid'] ?? $uid) === $uid, $link($work)]);
        $other = $vevent('sample-new-event@example.test', "DTSTART:20270120T090000Z\r\nDTEND:20270120T100000Z\r\nSUMMARY:Sample new");
        $out = $import($u, $cal, $file($uid, ['RECURRENCE-ID:20270125T090000Z' => '20270125', 'RECURRENCE-ID:20270201T090000Z' => '20270201'], $other));
        checkEq('import: the day not taken out and the new event come in; the moved day is not counted as removed', [2, 0, 1], $counts($out));
        checkEq('import: the moved day is counted by the calendar it is in now', [['Sample work', 1]], $countMoved($out));
        checkEq('import: the Activity line says where the day is now', "Imported 2 events into 'Sample calendar'; 1 left out because it is now in 'Sample work'",
            preg_replace("/into '[^']*'/", "into 'Sample calendar'", $summary($u)));
        checkEq('import: the moved day is not here twice', 0,
            (int) $db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$cal, $uid, '2027-01-25 09:00:00']));
        checkEq('import: the moved day still lives in the other calendar', 1,
            (int) $db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$work]));
        checkEq('import: the day that came in is under the stored repeating event', [$id],
            array_map('intval', array_column($db->all('SELECT recurrence_parent_id FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NOT NULL AND deleted_at IS NULL', [$cal, $uid]), 'recurrence_parent_id')));
    });

    scenario('import: two days moved to one calendar are said together; a moved day whose event was since deleted counts as removed', function () use ($db, $file, $weekly, $import, $counts, $countMoved, $summary): void {
        $u = freshUser($db);
        $cal = Settings::defaultCalendarId($db, $u);
        [$id, $uid] = $weekly($u, $cal);
        $d = domains($db);
        $work = (int) $d->calendars->create($u, ['name' => 'Sample work'])['id'];
        $club = (int) $d->calendars->create($u, ['name' => 'Sample club'])['id'];
        foreach (['2027-01-11', '2027-01-18'] as $day) {
            $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => $day . 'T09:00:00Z', 'calendarId' => $work]);
        }
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-25T09:00:00Z', 'calendarId' => $club]);
        $gone = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$club]);
        $d->events->deleteEvent($u, $gone, 'all', null);
        $out = $import($u, $cal, $file($uid, ['RECURRENCE-ID:20270111T090000Z' => '20270111', 'RECURRENCE-ID:20270118T090000Z' => '20270118',
            'RECURRENCE-ID:20270125T090000Z' => '20270125', 'RECURRENCE-ID:20270201T090000Z' => '20270201']));
        checkEq('import: one day comes in, the day whose moved event is deleted is removed, the repeating event is there', [1, 1, 1], $counts($out));
        checkEq('import: two days counted under the calendar they are in now', [['Sample work', 2]], $countMoved($out));
        checkEq('import: the Activity line, in the plural', "; 1 left out, removed from this calendar before; 2 left out because they are now in 'Sample work'",
            substr($summary($u), (int) strpos($summary($u), ';')));
    });

    scenario('import: a moved day keeps where it came from, from both ways into a move, through undo and redo and a second move', function () use ($db, $weekly, $link): void {
        $u = freshUser($db);
        $cal = Settings::defaultCalendarId($db, $u);
        [$id, $uid] = $weekly($u, $cal);
        $d = domains($db);
        $work = (int) $d->calendars->create($u, ['name' => 'Sample work'])['id'];
        $club = (int) $d->calendars->create($u, ['name' => 'Sample club'])['id'];
        // A day with a row of its own, moved from its own row (no scope).
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-18T09:00:00Z', 'title' => 'Sample own day']);
        $own = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$id]);
        checkEq('link: a day row made by an edit keeps no link', [[null, null]],
            array_map(static fn(array $r): array => [$r['moved_from_uid'], $r['moved_from_key']], $db->all('SELECT moved_from_uid, moved_from_key FROM events WHERE id = ?', [$own])));
        $d->events->patch($u, $own, ['calendarId' => $work]);
        checkEq('link: a day with its own row, moved, keeps its repeating event and day', [[$uid, '2027-01-18 09:00:00']], $link($work));
        // An ordinary day, moved from the repeating event with scope this.
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-25T09:00:00Z', 'calendarId' => $club]);
        $entry = lastEntry($db, $u);
        checkEq('link: an ordinary day, moved, keeps its repeating event and day', [[$uid, '2027-01-25 09:00:00']], $link($club));
        checkEq('link: the repeating event itself keeps no link', [null, null],
            array_values($db->one('SELECT moved_from_uid, moved_from_key FROM events WHERE id = ?', [$id])));
        $d->undo->undoById($u, $entry);
        checkEq('link: undone, the moved event is gone and no live row holds the link', [[], 0],
            [$link($club), (int) $db->scalar('SELECT COUNT(*) FROM events WHERE user_id = ? AND moved_from_key = ? AND deleted_at IS NULL', [$u, '2027-01-25 09:00:00'])]);
        // A local undo records no entry of its own, so a move on a local
        // calendar has no redo: the move is made again, and writes the link again.
        checkEq('link: the undone move has no redo; undoing it again is refused', 'already_undone',
            (static function () use ($d, $u, $entry): string { try { $d->undo->undoById($u, $entry); return 'undone again'; } catch (\BetterCal\Http\HttpError $e) { return (string) $e->errorCode; } })());
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-25T09:00:00Z', 'calendarId' => $club]);
        checkEq('link: made again, the moved event has its link', [[$uid, '2027-01-25 09:00:00']], $link($club));
        // A second move, of the moved event to a third calendar.
        $third = (int) $d->calendars->create($u, ['name' => 'Sample third'])['id'];
        $movedId = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$club]);
        $d->events->patch($u, $movedId, ['calendarId' => $third]);
        checkEq('link: moved again, it keeps where it first came from', [[$uid, '2027-01-25 09:00:00']], $link($third));
    });

    scenario('import: the full record of a moved event carries where it came from; others carry null', function () use ($db, $weekly): void {
        $u = freshUser($db);
        $cal = Settings::defaultCalendarId($db, $u);
        [$id, $uid] = $weekly($u, $cal);
        $d = domains($db);
        $work = (int) $d->calendars->create($u, ['name' => 'Sample work'])['id'];
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-25T09:00:00Z', 'calendarId' => $work]);
        $moved = $d->events->get($u, (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$work]));
        $record = $d->events->serializeSingle($moved);
        checkEq('api: movedFrom names the repeating event, its day, and where it is now', ['uid' => $uid, 'day' => '2027-01-25T09:00:00Z', 'eventId' => $id, 'title' => 'Sample weekly', 'calendarId' => $cal],
            $record['movedFrom'] ?? 'missing');
        checkEq('api: the repeating event\'s own record carries movedFrom null', [true, null],
            [array_key_exists('movedFrom', $s = $d->events->serializeSingle($d->events->get($u, $id))), $s['movedFrom']]);
        $d->events->deleteEvent($u, $id, 'all', null);
        checkEq('api: with the repeating event deleted, movedFrom keeps its uid and day, and names nothing', ['uid' => $uid, 'day' => '2027-01-25T09:00:00Z', 'eventId' => null, 'title' => null, 'calendarId' => null],
            $d->events->serializeSingle($moved)['movedFrom'] ?? 'missing');
    });
};
