<?php

declare(strict_types=1);

// A CalDAV client's alarms and zones are read by the one mapping of every
// source (Feeds::columnsOf, #287). An alarm set or changed in a phone's
// calendar app becomes the event's own reminder, as an import's does, and
// is served out again; alarms equal to the ones the event was served with
// leave its own value, so an event that follows the calendar's defaults
// keeps following them; an alarm removed is an explicit none. A time with
// no zone floats, and a zone a client gives an event that had none is
// kept. A day's alarms are read against its series'. Each block is a
// scenario(); every name is invented.

use BetterCal\Domain\Settings;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    // An event object as a client sends it: $start is the DTSTART line's
    // value part (with its parameters), $alarms minutes before the start.
    $object = static function (string $uid, string $start, string $end, array $alarms = [], array $days = []): string {
        $alarm = static fn(int $m): array => ['BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Reminder', 'TRIGGER:-PT' . $m . 'M', 'END:VALARM'];
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sample//Client//EN', 'BEGIN:VEVENT', "UID:$uid", 'DTSTAMP:20270101T000000Z',
            "DTSTART$start", "DTEND$end", 'SUMMARY:Sample visit'];
        foreach ($alarms as $m) {
            $lines = [...$lines, ...$alarm($m)];
        }
        $lines[] = 'END:VEVENT';
        foreach ($days as [$recurrence, $dayStart, $dayEnd, $dayAlarms]) {
            $lines = [...$lines, 'BEGIN:VEVENT', "UID:$uid", 'DTSTAMP:20270101T000000Z', "RECURRENCE-ID$recurrence", "DTSTART$dayStart", "DTEND$dayEnd", 'SUMMARY:Sample visit'];
            foreach ($dayAlarms as $m) {
                $lines = [...$lines, ...$alarm($m)];
            }
            $lines[] = 'END:VEVENT';
        }
        return implode("\r\n", [...$lines, 'END:VCALENDAR', '']);
    };
    $client = static function () use ($db): object {
        $u = freshUser($db);
        $db->run('UPDATE users SET settings_json = ? WHERE id = ?', [json_encode(['tz' => 'America/Los_Angeles']), $u]);
        $token = (new BetterCal\Domain\ApiTokens($db))->create($u, 'Sample client');
        $backend = new BetterCal\Dav\CalendarBackend($db, new BetterCal\Domain\Undo($db), fn() => BetterCal\Domain\RequestAuthority::credential($u, (int) $token['id'], null));
        $calendarId = (int) Settings::defaultCalendarId($db, $u);
        return (object) ['u' => $u, 'd' => domains($db), 'calendarId' => $calendarId,
            'put' => static fn(string $uid, string $body) => $backend->updateCalendarObject($calendarId, $uid . '.ics', $body),
            'served' => static fn(string $uid): string => (string) ($backend->getCalendarObject($calendarId, $uid . '.ics')['calendardata'] ?? '')];
    };
    $row = static fn(object $c, string $uid): array => (array) $db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL', [$c->calendarId, $uid]);
    $reminders = static fn(?string $json): mixed => $json === null ? null : json_decode($json, true);
    $triggers = static fn(string $body): array => preg_match_all('/^TRIGGER:(.+)$/m', $body, $m) > 0 ? array_map('trim', $m[1]) : [];

    scenario('caldav alarms: an alarm set, changed and removed in a phone app is the event\'s own reminder, served out again', function () use ($client, $object, $row, $reminders, $triggers): void {
        $c = $client();
        $uid = 'sample-visit-alarm';
        $start = ';TZID=America/Los_Angeles:20270304T090000';
        $end = ';TZID=America/Los_Angeles:20270304T100000';
        ($c->put)($uid, $object($uid, $start, $end, [15]));
        checkEq('a new event with an alarm 15 minutes before: its own reminder, served as that alarm',
            [[['minutes' => 15]], ['-PT15M']], [$reminders($row($c, $uid)['reminders_json']), $triggers(($c->served)($uid))]);
        ($c->put)($uid, $object($uid, $start, $end, [30, 5]));
        checkEq('the alarm changed to two, 30 and 5 minutes before: both are its reminders, served as both',
            [[['minutes' => 5], ['minutes' => 30]], ['-PT5M', '-PT30M']], [$reminders($row($c, $uid)['reminders_json']), $triggers(($c->served)($uid))]);
        ($c->put)($uid, $object($uid, $start, $end, []));
        checkEq('every alarm removed: an explicit none, not the calendar\'s defaults, and none served',
            [[], []], [$reminders($row($c, $uid)['reminders_json']), $triggers(($c->served)($uid))]);
        ($c->put)($uid, $object($uid, $start, $end, []));
        checkEq('sent again with no alarm: still an explicit none', [], $reminders($row($c, $uid)['reminders_json']));
    });

    scenario('caldav alarms: an event that follows the calendar\'s defaults keeps following them through a client\'s edit', function () use ($db, $client, $object, $row, $reminders): void {
        $c = $client();
        $uid = 'sample-visit-defaults';
        $start = ';TZID=America/Los_Angeles:20270305T090000';
        $end = ';TZID=America/Los_Angeles:20270305T100000';
        ($c->put)($uid, $object($uid, $start, $end));
        checkEq('a new event with no alarm follows the calendar\'s defaults', null, $row($c, $uid)['reminders_json']);
        ($c->put)($uid, str_replace('SUMMARY:Sample visit', 'SUMMARY:Sample visit, moved', $object($uid, $start, $end)));
        checkEq('renamed by the client, sent with no alarm as it was served: it still follows the defaults',
            ['Sample visit, moved', null], [$row($c, $uid)['title'], $row($c, $uid)['reminders_json']]);
        // Set in the app, then the client sends back what it was served.
        $c->d->events->patch($c->u, (int) $row($c, $uid)['id'], ['reminders' => [['minutes' => 10]]]);
        ($c->put)($uid, $object($uid, $start, $end, [10]));
        checkEq('a reminder set in the app and sent back as it was served: unchanged', [['minutes' => 10]], $reminders($row($c, $uid)['reminders_json']));
    });

    scenario('caldav alarms: a day\'s alarm is read against its series\'', function () use ($db, $client, $object, $reminders): void {
        $c = $client();
        $uid = 'sample-class-alarm';
        $series = $object($uid, ';TZID=America/Los_Angeles:20270304T090000', ';TZID=America/Los_Angeles:20270304T100000', [15]);
        $series = str_replace('SUMMARY:Sample visit', "RRULE:FREQ=DAILY;COUNT=5\r\nSUMMARY:Sample visit", $series);
        $withDays = static fn(array $days): string => str_replace("END:VEVENT\r\nEND:VCALENDAR", "END:VEVENT\r\n" . implode("\r\n", array_map(
            static fn(array $d): string => implode("\r\n", ['BEGIN:VEVENT', "UID:$uid", 'DTSTAMP:20270101T000000Z', 'RECURRENCE-ID;TZID=America/Los_Angeles:' . $d[0],
                'DTSTART;TZID=America/Los_Angeles:' . $d[1], 'DTEND;TZID=America/Los_Angeles:' . $d[2], 'SUMMARY:' . $d[3],
                ...array_merge(...array_map(static fn(int $m): array => ['BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Reminder', 'TRIGGER:-PT' . $m . 'M', 'END:VALARM'], $d[4])), 'END:VEVENT']),
            $days)) . "\r\nEND:VCALENDAR", $series);
        ($c->put)($uid, $withDays([['20270305T090000', '20270305T090000', '20270305T100000', 'Sample visit, second', [15]], ['20270306T090000', '20270306T090000', '20270306T100000', 'Sample visit, third', [60]]]));
        $day = static fn(string $key): array => (array) $db->one('SELECT reminders_json, override_fields FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc = ?', [$c->calendarId, $uid, $key]);
        $second = $day('2027-03-05 17:00:00');
        $third = $day('2027-03-06 17:00:00');
        checkEq('a renamed day with the series\' alarm follows the series\' reminders; a day with its own alarm owns it',
            [[['minutes' => 15]], ['title'], [['minutes' => 60]], ['title', 'reminders']],
            [$reminders($second['reminders_json']), json_decode((string) $second['override_fields'], true), $reminders($third['reminders_json']), json_decode((string) $third['override_fields'], true)]);
    });

    scenario('caldav zones: a time with no zone floats, and a zone a client gives it is kept', function () use ($client, $object, $row): void {
        $c = $client();
        $uid = 'sample-visit-zone';
        ($c->put)($uid, $object($uid, ':20270307T090000', ':20270307T100000'));
        $was = $row($c, $uid);
        checkEq('a new event at 9:00 with no zone: floating, 9:00 on the Home clock',
            [1, 'America/Los_Angeles', '2027-03-07 17:00:00'], [(int) $was['floating'], $was['tzid'], $was['start_utc']]);
        checkEq('served out with no zone, as it came', 1, preg_match('/^DTSTART:20270307T090000\r?$/m', ($c->served)($uid)));
        ($c->put)($uid, $object($uid, ';TZID=America/New_York:20270307T090000', ';TZID=America/New_York:20270307T100000'));
        $now = $row($c, $uid);
        checkEq('given New York by the client: no longer floating, 9:00 in New York',
            [0, 'America/New_York', '2027-03-07 14:00:00'], [(int) $now['floating'], $now['tzid'], $now['start_utc']]);
        checkEq('served out in New York', 1, preg_match('/^DTSTART;TZID=America\/New_York:20270307T090000\r?$/m', ($c->served)($uid)));
    });
};
