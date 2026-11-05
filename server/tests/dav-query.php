<?php

declare(strict_types=1);

// CalDAV time ranges (RFC 4791 9.9) and expand (9.6.5) read by the app's own
// expander. Every expected date and object is listed by hand from the
// standard's rules and the series' own readings in decisions.md, never
// computed by the expander under test. In-memory SQLite, the rows written
// directly. Required from run.php.

use BetterCal\Dav\CalendarBackend;
use BetterCal\Dav\DavIcs;
use BetterCal\Dav\DavTimeRange;
use BetterCal\Domain\Ics;

if (!class_exists(\Sabre\CalDAV\Backend\AbstractBackend::class)) {
    return;
}

$dqUtc = static fn(string $at): DateTimeImmutable => new DateTimeImmutable($at, new DateTimeZone('UTC'));

// ---- 9.9's table for VEVENT, one occurrence against [10:00, 12:00) --------
$dqFrom = $dqUtc('2027-04-06 10:00:00');
$dqTo = $dqUtc('2027-04-06 12:00:00');
$dqIn = static fn(string $s, string $e, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null, bool $open = false): bool
    => DavTimeRange::overlaps($dqUtc($s), $dqUtc($e), $open ? $from : ($from ?? $dqFrom), $open ? $to : ($to ?? $dqTo));
checkEq('9.9 DTEND: ends at the range start, out; one second later, in; starts at the range end, out; a minute before, in; spans it, in', [false, true, false, true, true], [
    $dqIn('2027-04-06 09:00:00', '2027-04-06 10:00:00'), $dqIn('2027-04-06 09:00:00', '2027-04-06 10:00:01'),
    $dqIn('2027-04-06 12:00:00', '2027-04-06 13:00:00'), $dqIn('2027-04-06 11:59:00', '2027-04-06 13:00:00'),
    $dqIn('2027-04-06 08:00:00', '2027-04-06 14:00:00'),
]);
checkEq('9.9 no length (a DURATION of zero, or a timed start alone): at the range start, in; at its end, out; a second before, out', [true, false, false, true], [
    $dqIn('2027-04-06 10:00:00', '2027-04-06 10:00:00'), $dqIn('2027-04-06 12:00:00', '2027-04-06 12:00:00'),
    $dqIn('2027-04-06 09:59:59', '2027-04-06 09:59:59'), $dqIn('2027-04-06 11:59:59', '2027-04-06 11:59:59'),
]);
checkEq('9.9 open sides: no start takes everything before the end, no end everything after the start', [true, false, true, false], [
    $dqIn('1990-01-01 00:00:00', '1990-01-01 01:00:00', null, $dqTo, true), $dqIn('2027-04-06 12:00:00', '2027-04-06 13:00:00', null, $dqTo, true),
    $dqIn('2090-01-01 00:00:00', '2090-01-01 01:00:00', $dqFrom, null, true), $dqIn('2027-04-06 09:00:00', '2027-04-06 10:00:00', $dqFrom, null, true),
]);

// ---- The backend over stored rows ------------------------------------------
$dqDb = new BetterCal\Infra\Db(['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null]);
$dqDb->run('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, display_name TEXT, settings_json TEXT)');
$dqDb->run('CREATE TABLE calendars (id INTEGER PRIMARY KEY, user_id INTEGER, kind TEXT, name TEXT, color TEXT, position INTEGER DEFAULT 0, synctoken INTEGER DEFAULT 1)');
$dqDb->run('CREATE TABLE event_links (id INTEGER PRIMARY KEY, event_id INTEGER, container_id INTEGER, position INTEGER DEFAULT 0)');
$dqDb->run('CREATE TABLE events (id INTEGER PRIMARY KEY, user_id INTEGER, calendar_id INTEGER, uid TEXT, title TEXT, description TEXT, location TEXT, url TEXT,
    start_utc TEXT, end_utc TEXT, all_day INTEGER DEFAULT 0, tzid TEXT, rrule TEXT, exdates_json TEXT, status TEXT DEFAULT \'confirmed\', reminders_json TEXT,
    recurrence_parent_id INTEGER, recurrence_instance_utc TEXT, is_container INTEGER DEFAULT 0, deleted_at TEXT, updated_at TEXT DEFAULT \'2026-11-01 00:00:00\')');
davJournal($dqDb);
$dqDb->run("INSERT INTO users VALUES (1, 'sam@example.test', 'Sam Sample', '{}')");
$dqCalendar = 0;
$dqNewCalendar = static function () use ($dqDb, &$dqCalendar): int {
    $dqCalendar++;
    $dqDb->run("INSERT INTO calendars (id, user_id, kind, name, color) VALUES (?, 1, 'local', 'Sample calendar', '#336699')", [$dqCalendar]);
    return $dqCalendar;
};
$dqRow = static function (int $calendar, array $cols) use ($dqDb): int {
    return $dqDb->insert('events', $cols + ['user_id' => 1, 'calendar_id' => $calendar, 'title' => 'Sample', 'tzid' => 'UTC', 'all_day' => 0]);
};
/** A stored event read from an object as a PUT reads it. */
$dqFromIcs = static function (int $calendar, string $vevent) use ($dqRow): string {
    $parsed = Ics::parse("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\n" . $vevent . "END:VCALENDAR\r\n", 'UTC')[0];
    $dqRow($calendar, DavIcs::eventColumns($parsed) + ['uid' => (string) $parsed['uid']]);
    return DavIcs::objectUri((string) $parsed['uid']);
};
$dqBackend = new CalendarBackend($dqDb, new BetterCal\Domain\Undo($dqDb));
$dqRange = static fn(?string $s, ?string $e, array $props = []): array => [
    'name' => 'VCALENDAR', 'is-not-defined' => false, 'prop-filters' => [], 'time-range' => false,
    'comp-filters' => [[
        'name' => 'VEVENT', 'is-not-defined' => false, 'comp-filters' => [], 'prop-filters' => $props,
        'time-range' => $s === null && $e === null ? false : ['start' => $s === null ? null : new DateTime($s . 'Z'), 'end' => $e === null ? null : new DateTime($e . 'Z')],
    ]],
];
$dqQuery = static function (int $calendar, ?string $s, ?string $e, array $props = []) use ($dqBackend, $dqRange): array {
    $uris = $dqBackend->calendarQuery($calendar, $dqRange($s, $e, $props));
    sort($uris);
    return $uris;
};

// 9.9's rows as a client's objects arrive: DTEND, DURATION, a DATE start alone.
$dqTable = $dqNewCalendar();
$dqDtend = $dqFromIcs($dqTable, "BEGIN:VEVENT\r\nUID:dq-dtend@example.test\r\nDTSTAMP:20261101T000000Z\r\nDTSTART:20270406T090000Z\r\nDTEND:20270406T100000Z\r\nSUMMARY:Sample\r\nEND:VEVENT\r\n");
$dqDuration = $dqFromIcs($dqTable, "BEGIN:VEVENT\r\nUID:dq-duration@example.test\r\nDTSTAMP:20261101T000000Z\r\nDTSTART:20270406T113000Z\r\nDURATION:PT1H\r\nSUMMARY:Sample\r\nEND:VEVENT\r\n");
$dqDate = $dqFromIcs($dqTable, "BEGIN:VEVENT\r\nUID:dq-date@example.test\r\nDTSTAMP:20261101T000000Z\r\nDTSTART;VALUE=DATE:20270410\r\nSUMMARY:Sample\r\nEND:VEVENT\r\n");
checkEq('9.9 in the backend: DTEND 09:00-10:00 is out of [10:00, 12:00) and in [09:59, 10:00); DURATION PT1H from 11:30 is in it and out of [12:30, 13:00)', [
    [$dqDuration], [$dqDtend], [],
], [$dqQuery($dqTable, '2027-04-06 10:00:00', '2027-04-06 12:00:00'), $dqQuery($dqTable, '2027-04-06 09:59:00', '2027-04-06 10:00:00'), $dqQuery($dqTable, '2027-04-06 12:30:00', '2027-04-06 13:00:00')]);
checkEq('9.9 a DATE start alone is the one day: in from 23:00 on the 10th, out from midnight on the 11th and up to midnight on the 10th', [[$dqDate], [], []], [
    $dqQuery($dqTable, '2027-04-10 23:00:00', '2027-04-11 01:00:00'), $dqQuery($dqTable, '2027-04-11 00:00:00', '2027-04-12 00:00:00'), $dqQuery($dqTable, '2027-04-09 00:00:00', '2027-04-10 00:00:00'),
]);

// A weekly series, Tuesdays 18:00 to 19:00 UTC from Tue 5 Jan 2027 with no
// end: Tue 12 Jan moved to Thu 21 Jan, Tue 2 Feb skipped.
$dqWeekly = $dqNewCalendar();
$dqWeeklyId = $dqRow($dqWeekly, ['uid' => 'dq-choir@example.test', 'title' => 'Sample choir', 'start_utc' => '2027-01-05 18:00:00', 'end_utc' => '2027-01-05 19:00:00',
    'rrule' => 'FREQ=WEEKLY;BYDAY=TU', 'exdates_json' => json_encode(['2027-02-02 18:00:00'])]);
$dqRow($dqWeekly, ['uid' => 'dq-choir@example.test', 'title' => 'Sample choir, moved', 'start_utc' => '2027-01-21 18:00:00', 'end_utc' => '2027-01-21 19:00:00',
    'recurrence_parent_id' => $dqWeeklyId, 'recurrence_instance_utc' => '2027-01-12 18:00:00']);
$dqChoir = ['dq-choir@example.test.ics'];
checkEq('a series: an edited day moved into the range (Thu 21 Jan, between Tue 19 and Tue 26) puts it there', $dqChoir, $dqQuery($dqWeekly, '2027-01-20 00:00:00', '2027-01-22 00:00:00'));
checkEq('a series: the day an edited day was moved out of (Tue 12 Jan) holds nothing', [], $dqQuery($dqWeekly, '2027-01-12 00:00:00', '2027-01-13 00:00:00'));
checkEq('a series: a skipped day (Tue 2 Feb) holds nothing', [], $dqQuery($dqWeekly, '2027-02-02 00:00:00', '2027-02-03 00:00:00'));
checkEq('a series: Wed 27 Jan to Mon 1 Feb holds none of its occurrences, with Tue 26 Jan and Tue 9 Feb either side', [], $dqQuery($dqWeekly, '2027-01-27 00:00:00', '2027-02-01 00:00:00'));
checkEq('a series: one minute of Tue 26 Jan 18:30 is in an occurrence; from its end at 19:00 is out', [$dqChoir, []], [
    $dqQuery($dqWeekly, '2027-01-26 18:30:00', '2027-01-26 18:31:00'), $dqQuery($dqWeekly, '2027-01-26 19:00:00', '2027-01-27 00:00:00'),
]);
checkEq('a series with no end: Tue 3 Jun 2031 is one of its days, Mon 2 Jun 2031 is not, and a range open after 2040 holds it', [$dqChoir, [], $dqChoir], [
    $dqQuery($dqWeekly, '2031-06-03 00:00:00', '2031-06-04 00:00:00'), $dqQuery($dqWeekly, '2031-06-02 00:00:00', '2031-06-03 00:00:00'), $dqQuery($dqWeekly, '2040-01-01 00:00:00', null),
]);
checkEq('a series: a range that ends before its first day holds nothing; one open before its first day holds it', [[], $dqChoir], [
    $dqQuery($dqWeekly, '2026-12-01 00:00:00', '2027-01-05 18:00:00'), $dqQuery($dqWeekly, null, '2027-01-05 18:01:00'),
]);

// A gap day: daily at 02:30 in New York from Wed 10 Mar 2027. Clocks go from
// 02:00 to 03:00 on Sun 14 Mar, so that day's occurrence is at 03:30 EDT,
// 07:30 UTC (the clock plus the gap); the days before are at 07:30 UTC (EST)
// and the days after at 06:30 UTC (EDT).
$dqGap = $dqNewCalendar();
$dqRow($dqGap, ['uid' => 'dq-gap@example.test', 'start_utc' => '2027-03-10 07:30:00', 'end_utc' => '2027-03-10 08:00:00', 'tzid' => 'America/New_York', 'rrule' => 'FREQ=DAILY']);
$dqGapUri = ['dq-gap@example.test.ics'];
checkEq('a gap day: Sun 14 Mar is at 07:30 UTC, the shifted clock, so 07:30 to 07:31 holds it and 06:30 to 07:30 does not; Mon 15 Mar is at 06:30 UTC', [$dqGapUri, [], $dqGapUri, []], [
    $dqQuery($dqGap, '2027-03-14 07:30:00', '2027-03-14 07:31:00'), $dqQuery($dqGap, '2027-03-14 06:30:00', '2027-03-14 07:30:00'),
    $dqQuery($dqGap, '2027-03-15 06:30:00', '2027-03-15 06:31:00'), $dqQuery($dqGap, '2027-03-15 07:00:00', '2027-03-15 08:00:00'),
]);

// An all-day series: Fridays from Fri 8 Jan 2027, its days whole UTC dates.
$dqAllDay = $dqNewCalendar();
$dqRow($dqAllDay, ['uid' => 'dq-market@example.test', 'start_utc' => '2027-01-08 00:00:00', 'end_utc' => '2027-01-09 00:00:00', 'all_day' => 1, 'rrule' => 'FREQ=WEEKLY;BYDAY=FR']);
$dqMarket = ['dq-market@example.test.ics'];
checkEq('an all-day series: the last hour of Fri 15 Jan holds it; Sat 16 Jan does not; a range ending at Fri 15 Jan 00:00 does not', [$dqMarket, [], []], [
    $dqQuery($dqAllDay, '2027-01-15 23:00:00', '2027-01-16 00:00:00'), $dqQuery($dqAllDay, '2027-01-16 00:00:00', '2027-01-17 00:00:00'),
    $dqQuery($dqAllDay, '2027-01-14 23:59:59', '2027-01-15 00:00:00'),
]);

// A rule the library misread: BYSETPOS on a weekly rule picks the last of
// each week's Monday, Wednesday and Friday, so only Fridays (the library's
// iterator ignored BYSETPOS outside MONTHLY and YEARLY and answered Mondays).
$dqSetpos = $dqNewCalendar();
$dqRow($dqSetpos, ['uid' => 'dq-setpos@example.test', 'start_utc' => '2027-01-08 12:00:00', 'end_utc' => '2027-01-08 13:00:00', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE,FR;BYSETPOS=-1']);
checkEq('BYSETPOS on a weekly rule: Mon 11 and Wed 13 Jan hold nothing, Fri 15 Jan holds it', [[], [], ['dq-setpos@example.test.ics']], [
    $dqQuery($dqSetpos, '2027-01-11 00:00:00', '2027-01-12 00:00:00'), $dqQuery($dqSetpos, '2027-01-13 00:00:00', '2027-01-14 00:00:00'), $dqQuery($dqSetpos, '2027-01-15 00:00:00', '2027-01-16 00:00:00'),
]);

// Filters that are not a VEVENT time range keep the library's validation,
// alone and together with a time range.
$dqTitles = $dqNewCalendar();
$dqRow($dqTitles, ['uid' => 'dq-rehearsal@example.test', 'title' => 'Sample rehearsal', 'start_utc' => '2027-05-04 18:00:00', 'end_utc' => '2027-05-04 19:00:00']);
$dqRow($dqTitles, ['uid' => 'dq-dinner@example.test', 'title' => 'Sample dinner', 'start_utc' => '2027-05-04 18:00:00', 'end_utc' => '2027-05-04 20:00:00']);
$dqRow($dqTitles, ['uid' => 'dq-later@example.test', 'title' => 'Sample rehearsal', 'start_utc' => '2027-06-01 18:00:00', 'end_utc' => '2027-06-01 19:00:00']);
$dqSummary = [['name' => 'SUMMARY', 'is-not-defined' => false, 'param-filters' => [], 'time-range' => false,
    'text-match' => ['negate-condition' => false, 'collation' => 'i;ascii-casemap', 'value' => 'rehearsal']]];
checkEq('a text match alone is the library\'s, as before: both rehearsals, not the dinner', ['dq-later@example.test.ics', 'dq-rehearsal@example.test.ics'], $dqQuery($dqTitles, null, null, $dqSummary));
checkEq('a text match with a time range: an object passes both, the May rehearsal alone', ['dq-rehearsal@example.test.ics'], $dqQuery($dqTitles, '2027-05-04 00:00:00', '2027-05-05 00:00:00', $dqSummary));
checkEq('a time range alone over the same calendar: both May events', ['dq-dinner@example.test.ics', 'dq-rehearsal@example.test.ics'], $dqQuery($dqTitles, '2027-05-04 00:00:00', '2027-05-05 00:00:00'));
$dqTodo = ['name' => 'VCALENDAR', 'is-not-defined' => false, 'prop-filters' => [], 'time-range' => false,
    'comp-filters' => [['name' => 'VTODO', 'is-not-defined' => false, 'comp-filters' => [], 'prop-filters' => [], 'time-range' => false]]];
checkEq('a VTODO filter is the library\'s and finds no task here', [], $dqBackend->calendarQuery($dqTitles, $dqTodo));

// ---- Through sabre/dav: calendar-query and calendar-multiget with expand ---
$dqServer = new \Sabre\DAV\Server([
    new \Sabre\DAVACL\PrincipalCollection(new BetterCal\Dav\PrincipalBackend($dqDb)),
    new \Sabre\CalDAV\CalendarRoot(new BetterCal\Dav\PrincipalBackend($dqDb), $dqBackend),
]);
$dqServer->setBaseUri('/');
$dqServer->addPlugin(new BetterCal\Dav\SizedCalDavPlugin());
$dqReport = static function (string $path, string $body) use ($dqServer): string {
    $request = new \Sabre\HTTP\Request('REPORT', $path, ['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8'], $body);
    $response = new \Sabre\HTTP\Response();
    $dqServer->httpRequest = $request;
    $dqServer->httpResponse = $response;
    $dqServer->invokeMethod($request, $response, false);
    return (string) $response->getBodyAsString();
};
/** Each expanded VEVENT as "RECURRENCE-ID DTSTART", in the order served; "RRULE" if any is left. */
$dqInstances = static function (string $xml): array {
    $out = [];
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', html_entity_decode($xml), $events);
    foreach ($events[1] as $event) {
        preg_match('/^RECURRENCE-ID[^:\n]*:(\S+)/m', $event, $rid);
        preg_match('/^DTSTART[^:\n]*:(\S+)/m', $event, $start);
        $out[] = ($rid[1] ?? '-') . ' ' . ($start[1] ?? '-') . (preg_match('/^(RRULE|EXDATE)/m', $event) === 1 ? ' RRULE' : '');
    }
    return $out;
};
$dqExpand = '<C:calendar-data><C:expand start="%s" end="%s"/></C:calendar-data>';
$dqQueryBody = static fn(string $start, string $end, bool $expand = true): string => '<?xml version="1.0" encoding="utf-8"?>'
    . '<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:prop><D:getetag/>' . ($expand ? sprintf($dqExpand, $start, $end) : '<C:calendar-data/>') . '</D:prop>'
    . '<C:filter><C:comp-filter name="VCALENDAR"><C:comp-filter name="VEVENT"><C:time-range start="' . $start . '" end="' . $end . '"/></C:comp-filter></C:comp-filter></C:filter></C:calendar-query>';
$dqXml = $dqReport('/calendars/sam@example.test/cal-' . $dqWeekly . '/', $dqQueryBody('20270110T000000Z', '20270131T000000Z'));
checkEq('calendar-query with expand, 10 to 31 Jan: Tue 19, the day moved from Tue 12 to Thu 21, Tue 26, each its own VEVENT in UTC with no rule', [
    '20270119T180000Z 20270119T180000Z', '20270112T180000Z 20270121T180000Z', '20270126T180000Z 20270126T180000Z',
], $dqInstances($dqXml));
check('calendar-query with expand: the moved day keeps its own title', str_contains(html_entity_decode($dqXml), 'SUMMARY:Sample choir\, moved'));
$dqXml = $dqReport('/calendars/sam@example.test/cal-' . $dqWeekly . '/', $dqQueryBody('20270127T000000Z', '20270201T000000Z'));
checkEq('calendar-query, Wed 27 Jan to Mon 1 Feb: no object', 0, substr_count($dqXml, 'dq-choir@example.test.ics'));
$dqXml = $dqReport('/calendars/sam@example.test/cal-' . $dqWeekly . '/', '<?xml version="1.0" encoding="utf-8"?>'
    . '<C:calendar-multiget xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:prop>' . sprintf($dqExpand, '20270201T000000Z', '20270210T000000Z') . '</D:prop>'
    . '<D:href>/calendars/sam@example.test/cal-' . $dqWeekly . '/dq-choir@example.test.ics</D:href></C:calendar-multiget>');
checkEq('calendar-multiget with expand, 1 to 10 Feb: Tue 2 Feb skipped, Tue 9 Feb alone', ['20270209T180000Z 20270209T180000Z'], $dqInstances($dqXml));
$dqXml = $dqReport('/calendars/sam@example.test/cal-' . $dqGap . '/', $dqQueryBody('20270313T000000Z', '20270316T000000Z'));
checkEq('calendar-query with expand over the gap day: Sat 13 at 07:30 UTC, Sun 14 at 07:30 UTC (03:30 EDT), Mon 15 at 06:30 UTC', [
    '20270313T073000Z 20270313T073000Z', '20270314T073000Z 20270314T073000Z', '20270315T063000Z 20270315T063000Z',
], $dqInstances($dqXml));
$dqXml = $dqReport('/calendars/sam@example.test/cal-' . $dqAllDay . '/', $dqQueryBody('20270114T000000Z', '20270123T000000Z'));
checkEq('calendar-query with expand over an all-day series: Fri 15 and Fri 22 Jan, kept as dates', ['20270115 20270115', '20270122 20270122'], $dqInstances($dqXml));
$dqXml = $dqReport('/calendars/sam@example.test/cal-' . $dqWeekly . '/', $dqQueryBody('20270110T000000Z', '20270131T000000Z', false));
checkEq('calendar-query without expand: the object as stored, its series VEVENT with its rule and its moved day', [2, 1, 1],
    [substr_count($dqXml, 'BEGIN:VEVENT'), substr_count($dqXml, 'RRULE:FREQ=WEEKLY;BYDAY=TU'), substr_count($dqXml, 'RECURRENCE-ID')]);
