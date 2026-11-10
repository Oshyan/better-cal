<?php

declare(strict_types=1);

// What is held here alone stays on its day when a CalDAV client edits a
// series (docs/caldav.md: tags, people, reminders and attendance "are kept
// untouched when a client edits an event"). A PUT is the whole object as
// the client has it, and a client holds none of those, so what becomes of a
// stored day's row the PUT gave no record to is decided by the function the
// sync uses for a poll (Feeds::settle): kept on its day while the series
// has the day, gone and named when it does not.
//
// The client's moves: the class's clock (19:00 to 20:00) and its zone (New
// York to Los Angeles), each with its edited days sent again under the
// RECURRENCE-IDs of the new time, as clients that keep exceptions through
// a time change do; a day's own record dropped; a day removed. Each PUT is
// then undone, and every row and link is as it was. The same clock move
// through an ICS feed is the control: both doors leave the same days.
//
// Expectations by calendar date in New York, written by hand: 19:00 in New
// York on 7 January 2027 is 00:00 UTC on the 8th; 19:00 in Los Angeles is
// 22:00 in New York. Invented data.

use BetterCal\Domain\Feeds;
use BetterCal\Domain\Ics;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $ny = new DateTimeZone('America/New_York');
    // The object a client sends: a daily class from 4 January at $clock in $zone, the days it names (day => title) at
    // that clock, and the days it has removed.
    $object = static function (string $clock, array $days, string $zone = 'America/New_York', array $removed = []): string {
        $end = sprintf('%02d%s', (int) substr($clock, 0, 2) + 1, substr($clock, 2));
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sample//Client//EN', 'BEGIN:VEVENT', 'UID:sample-class-kept', 'DTSTAMP:20270101T000000Z',
            "DTSTART;TZID=$zone:20270104T{$clock}00", "DTEND;TZID=$zone:20270104T{$end}00", 'RRULE:FREQ=DAILY;COUNT=10', 'SUMMARY:Sample class',
            ...array_map(static fn(int $day): string => sprintf('EXDATE;TZID=%s:202701%02dT%s00', $zone, $day, $clock), $removed), 'END:VEVENT'];
        foreach ($days as $day => $title) {
            $lines = [...$lines, 'BEGIN:VEVENT', 'UID:sample-class-kept', 'DTSTAMP:20270101T000000Z', sprintf('RECURRENCE-ID;TZID=%s:202701%02dT%s00', $zone, $day, $clock),
                sprintf('DTSTART;TZID=%s:202701%02dT%s00', $zone, $day, $clock), sprintf('DTEND;TZID=%s:202701%02dT%s00', $zone, $day, $end), 'SUMMARY:' . $title, 'END:VEVENT'];
        }
        return implode("\r\n", [...$lines, 'END:VCALENDAR', '']);
    };
    // What the calendar shows on a New York date: [local start, title, tags] per event.
    $shownOn = static function (object $c, string $day) use ($ny): array {
        $from = new DateTimeImmutable($day . ' 00:00', $ny);
        $out = [];
        foreach ($c->d->events->window($c->u, $from, $from->modify('+1 day'), [$c->calendarId], null, true) as $o) {
            $out[] = [(new DateTimeImmutable((string) $o['start']))->setTimezone($ny)->format('Y-m-d H:i'), $o['title'], $o['tags'] ?? []];
        }
        sort($out);
        return $out;
    };
    // Every row of the class with its tags by name, and the day rows' reminders by New York date.
    $state = static fn(object $c): array => [
        'events' => $db->all('SELECT * FROM events WHERE calendar_id = ? AND uid = ? ORDER BY id', [$c->calendarId, 'sample-class-kept']),
        'tags' => $db->all('SELECT et.event_id, t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id JOIN events e ON e.id = et.event_id WHERE e.calendar_id = ? AND e.uid = ? ORDER BY et.event_id, t.name',
            [$c->calendarId, 'sample-class-kept']),
    ];
    $remindersOn = static function (object $c) use ($db, $ny): array {
        $out = [];
        foreach ($db->all('SELECT start_utc, reminders_json FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NOT NULL AND deleted_at IS NULL AND reminders_json IS NOT NULL ORDER BY start_utc', [$c->calendarId]) as $r) {
            $out[(new DateTimeImmutable((string) $r['start_utc'], new DateTimeZone('UTC')))->setTimezone($ny)->format('Y-m-d')] = json_encode(json_decode((string) $r['reminders_json'], true));
        }
        return $out;
    };
    $unkeyed = static fn(object $c): int => (int) $db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND recurrence_parent_id IS NOT NULL AND recurrence_instance_utc IS NULL', [$c->calendarId]);
    // A client with the class at 19:00 New York, 8 January renamed by the client; here, a tag on 7 January (an
    // ordinary day until then), and a tag and a reminder on the renamed 8 January.
    $client = static function () use ($db, $object): object {
        $u = freshUser($db);
        $d = domains($db);
        $token = (new BetterCal\Domain\ApiTokens($db))->create($u, 'Sample client');
        $backend = new BetterCal\Dav\CalendarBackend($db, new BetterCal\Domain\Undo($db), fn() => BetterCal\Domain\RequestAuthority::credential($u, (int) $token['id'], null));
        $calendarId = (int) BetterCal\Domain\Settings::defaultCalendarId($db, $u);
        $backend->createCalendarObject($calendarId, 'sample-class-kept.ics', $object('1900', [8 => 'Sample class, final']));
        $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL', [$calendarId, 'sample-class-kept']);
        $d->events->patch($u, $master, ['scope' => 'this', 'instanceStart' => '2027-01-08T00:00:00+00:00', 'tagNames' => ['sample-seven']]);
        $eight = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ?', [$calendarId, '2027-01-09 00:00:00']);
        $d->events->patch($u, $eight, ['tagNames' => ['sample-eight'], 'reminders' => [['minutes' => 10]]]);
        return (object) ['u' => $u, 'd' => $d, 'calendarId' => $calendarId, 'eight' => $eight,
            'seven' => (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ?', [$calendarId, '2027-01-08 00:00:00']),
            'put' => static fn(string $body) => $backend->updateCalendarObject($calendarId, 'sample-class-kept.ics', $body),
            'served' => static fn(): string => (string) ($backend->getCalendarObject($calendarId, 'sample-class-kept.ics')['calendardata'] ?? ''),
            'entry' => static fn(): array => (array) $db->one("SELECT summary, details_json FROM mutations WHERE user_id = ? AND entity = 'event' ORDER BY id DESC LIMIT 1", [$u])];
    };
    $before = [
        [['2027-01-07 19:00', 'Sample class', ['sample-seven']]], [['2027-01-08 19:00', 'Sample class, final', ['sample-eight']]], [['2027-01-09 19:00', 'Sample class', []]],
    ];
    $days = static fn(object $c): array => [$shownOn($c, '2027-01-07'), $shownOn($c, '2027-01-08'), $shownOn($c, '2027-01-09')];
    // The PUT undone: what the calendar shows, and every row and tag of the class, as before it.
    $undone = static function (string $what, object $c, array $was) use ($db, $before, $days, $state, $remindersOn, $unkeyed): void {
        $c->d->undo->undoById($c->u, lastEntry($db, $c->u));
        checkEq("$what, the PUT undone: 7, 8 and 9 January show as they did, each tag on its day, and the reminder on 8 January", [$before, ['2027-01-08' => '[{"minutes":10}]'], 0],
            [$days($c), $remindersOn($c), $unkeyed($c)]);
        checkRestored("$what, the PUT undone: every row of the class and every tag is as it was", $was, $state($c));
    };

    scenario('caldav kept here: the client moves a daily 19:00 New York class to 20:00 and sends its two edited days again at 20:00', function () use ($client, $object, $before, $days, $state, $remindersOn, $unkeyed, $undone): void {
        $c = $client();
        checkEq('clock move: before, 7 January has its tag, 8 January its title and tag, 9 January is an ordinary day', $before, $days($c));
        checkEq('clock move: the server gives the client both days as edited days of the class', 2, substr_count(($c->served)(), 'RECURRENCE-ID'));
        $was = $state($c);
        ($c->put)($object('2000', [7 => 'Sample class', 8 => 'Sample class, final']));
        checkEq('clock move: 7 January at 20:00 keeps its tag, 8 January at 20:00 its title and tag, 9 January is an ordinary day at 20:00', [
            [['2027-01-07 20:00', 'Sample class', ['sample-seven']]], [['2027-01-08 20:00', 'Sample class, final', ['sample-eight']]], [['2027-01-09 20:00', 'Sample class', []]],
        ], $days($c));
        checkEq('clock move: 8 January keeps its reminder, and no row is left without a key', [['2027-01-08' => '[{"minutes":10}]'], 0], [$remindersOn($c), $unkeyed($c)]);
        checkEq('clock move: nothing was lost, so the entry\'s words name no removed day', false, str_contains((string) (($c->entry)()['summary'] ?? ''), 'removed here'));
        $undone('clock move', $c, $was);
    });

    scenario('caldav kept here: the client moves the class from 19:00 New York to 19:00 Los Angeles and sends its two edited days again in that zone', function () use ($client, $object, $days, $state, $remindersOn, $unkeyed, $undone): void {
        $c = $client();
        $was = $state($c);
        ($c->put)($object('1900', [7 => 'Sample class', 8 => 'Sample class, final'], 'America/Los_Angeles'));
        checkEq('zone move: 7 January at 22:00 New York keeps its tag, 8 January its title and tag, 9 January is an ordinary day', [
            [['2027-01-07 22:00', 'Sample class', ['sample-seven']]], [['2027-01-08 22:00', 'Sample class, final', ['sample-eight']]], [['2027-01-09 22:00', 'Sample class', []]],
        ], $days($c));
        checkEq('zone move: 8 January keeps its reminder, and no row is left without a key', [['2027-01-08' => '[{"minutes":10}]'], 0], [$remindersOn($c), $unkeyed($c)]);
        $undone('zone move', $c, $was);
    });

    scenario('caldav kept here: the client moves the class to 20:00 and sends no edited day at all', function () use ($client, $object, $days, $state, $remindersOn, $unkeyed, $undone): void {
        $c = $client();
        $was = $state($c);
        ($c->put)($object('2000', []));
        checkEq('clock move, no days sent: 7 January keeps its tag, 8 January its tag with the series\' title, each at 20:00', [
            [['2027-01-07 20:00', 'Sample class', ['sample-seven']]], [['2027-01-08 20:00', 'Sample class', ['sample-eight']]], [['2027-01-09 20:00', 'Sample class', []]],
        ], $days($c));
        checkEq('clock move, no days sent: 8 January keeps its reminder, and no row is left without a key', [['2027-01-08' => '[{"minutes":10}]'], 0], [$remindersOn($c), $unkeyed($c)]);
        $undone('clock move, no days sent', $c, $was);
    });

    scenario('caldav kept here: the client drops its record of 8 January, a day whose row holds a tag and a reminder', function () use ($db, $client, $object, $days, $state, $remindersOn, $undone): void {
        $c = $client();
        $was = $state($c);
        // The client keeps the tagged 7th as it was served and puts the 8th back to the series: it sends no record of it.
        ($c->put)($object('1900', [7 => 'Sample class']));
        checkEq('a record dropped: 8 January is back to the series\' title and keeps its tag; 7 January keeps its tag', [
            [['2027-01-07 19:00', 'Sample class', ['sample-seven']]], [['2027-01-08 19:00', 'Sample class', ['sample-eight']]], [['2027-01-09 19:00', 'Sample class', []]],
        ], $days($c));
        checkEq('a record dropped: 8 January is the row it was, owning its reminder and its tag and nothing else, with its reminder', [['reminders', 'tags'], ['2027-01-08' => '[{"minutes":10}]']],
            [json_decode((string) $db->scalar('SELECT override_fields FROM events WHERE id = ?', [$c->eight]), true), $remindersOn($c)]);
        $undone('a record dropped', $c, $was);
    });

    scenario('caldav kept here: the client removes 8 January, a day whose row holds a tag and a reminder', function () use ($db, $client, $object, $days, $state, $remindersOn, $undone): void {
        $c = $client();
        $was = $state($c);
        ($c->put)($object('1900', [7 => 'Sample class'], 'America/New_York', [8]));
        checkEq('a day removed: 8 January shows nothing and its row is gone; 7 January keeps its tag', [
            [[['2027-01-07 19:00', 'Sample class', ['sample-seven']]], [], [['2027-01-09 19:00', 'Sample class', []]]], null, [],
        ], [$days($c), $db->scalar('SELECT id FROM events WHERE id = ?', [$c->eight]), $remindersOn($c)]);
        $entry = ($c->entry)();
        $named = json_decode((string) ($entry['details_json'] ?? ''), true)['removedDays'] ?? [];
        checkEq('a day removed: the entry names the row that went, by its id and date, with what it held and why', [
            ['id' => $c->eight, 'title' => 'Sample class, final', 'day' => '2027-01-08', 'held' => ['its reminders', 'its tags'], 'why' => 'gone'],
        ], $named);
        checkEq('a day removed: and says so in words', true,
            str_contains((string) ($entry['summary'] ?? ''), "removed here: 'Sample class, final' on 2027-01-08 with its reminders and its tags: its series no longer has that day"));
        $undone('a day removed', $c, $was);
    });

    scenario('caldav kept here: the same clock move through an ICS feed leaves the same days as the PUT', function () use ($db, $object, $shownOn): void {
        $u = freshUser($db);
        $d = domains($db);
        $calendarId = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample school', 'kind' => 'subscribed', 'color' => '#336699']);
        $feeds = new Feeds($db);
        $sync = static fn(string $body) => $feeds->sync($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]), Ics::parse($body, 'America/New_York'));
        $f = (object) ['u' => $u, 'd' => $d, 'calendarId' => $calendarId];
        $sync($object('1900', [8 => 'Sample class, final']));
        $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NULL', [$calendarId]);
        $d->events->patch($u, $master, ['scope' => 'this', 'instanceStart' => '2027-01-08T00:00:00+00:00', 'tagNames' => ['sample-seven']]);
        $eight = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ?', [$calendarId, '2027-01-09 00:00:00']);
        $d->events->patch($u, $eight, ['tagNames' => ['sample-eight'], 'reminders' => [['minutes' => 10]]]);
        $sync($object('2000', [7 => 'Sample class', 8 => 'Sample class, final']));
        checkEq('the feed: 7 January at 20:00 keeps its tag, 8 January at 20:00 its title and tag', [
            [['2027-01-07 20:00', 'Sample class', ['sample-seven']]], [['2027-01-08 20:00', 'Sample class, final', ['sample-eight']]],
        ], [$shownOn($f, '2027-01-07'), $shownOn($f, '2027-01-08')]);
        checkEq('the feed: 8 January keeps its reminder', '[{"minutes":10}]',
            json_encode(json_decode((string) $db->scalar('SELECT reminders_json FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$calendarId, '2027-01-09 01:00:00']), true)));
    });
};
