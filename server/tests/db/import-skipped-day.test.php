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

    scenario('import: a day moved to another calendar is left out of this one and counted; it stays where it was moved', function () use ($db, $file, $weekly, $import, $counts, $liveDays, $skips): void {
        $u = freshUser($db);
        $cal = Settings::defaultCalendarId($db, $u);
        [$id, $uid] = $weekly($u, $cal);
        $d = domains($db);
        $work = (int) $d->calendars->create($u, ['name' => 'Sample other'])['id'];
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-25T09:00:00Z', 'calendarId' => $work]);
        $moved = $db->all('SELECT uid, start_utc FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$work]);
        checkEq('import: the moved day is a skipped date here and a standalone event of another UID there', [['2027-01-25 09:00:00'], 1, false],
            [$skips($id), count($moved), ($moved[0]['uid'] ?? $uid) === $uid]);
        $out = $import($u, $cal, $file($uid, ['RECURRENCE-ID:20270125T090000Z' => '20270125', 'RECURRENCE-ID:20270201T090000Z' => '20270201']));
        checkEq('import: the day not taken out comes in under the repeating event; the moved day is left out and counted', [1, 1, 1], $counts($out));
        checkEq('import: the moved day is not here twice', 0,
            (int) $db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$cal, $uid, '2027-01-25 09:00:00']));
        checkEq('import: the moved day still lives in the other calendar', 1,
            (int) $db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$work]));
        checkEq('import: the day that came in is under the stored repeating event', [$id],
            array_map('intval', array_column($db->all('SELECT recurrence_parent_id FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NOT NULL AND deleted_at IS NULL', [$cal, $uid]), 'recurrence_parent_id')));
    });
};
