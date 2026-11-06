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

// Past the expander's cap: Recurrence::expand reads at most 1,000 occurrences
// of a series per window, so a range holding more is read in windows the cap
// cannot cut, never answered short.
// Hourly, 30 minutes long, from Mon 1 Mar 2027 00:00 UTC, 1,100 events: the
// last is 1,099 hours on, Thu 15 Apr 19:00 (45 days and 19 hours).
$dqHourly = $dqNewCalendar();
$dqRow($dqHourly, ['uid' => 'dq-hourly@example.test', 'start_utc' => '2027-03-01 00:00:00', 'end_utc' => '2027-03-01 00:30:00', 'rrule' => 'FREQ=HOURLY;COUNT=1100']);
$dqHourlyAll = $dqInstances($dqReport('/calendars/sam@example.test/cal-' . $dqHourly . '/', $dqQueryBody('20270301T000000Z', '20270501T000000Z')));
checkEq('expand over a range holding 1,100 occurrences, more than the cap: all 1,100, each once, from Mon 1 Mar 00:00 to Thu 15 Apr 19:00', [
    1100, 1100, '20270301T000000Z 20270301T000000Z', '20270415T190000Z 20270415T190000Z',
], [count($dqHourlyAll), count(array_unique($dqHourlyAll)), $dqHourlyAll[0] ?? null, $dqHourlyAll[1099] ?? null]);
// The same series with its first 1,000 hours skipped: what is left starts
// 1,000 hours on, Sun 11 Apr 16:00 (41 days and 16 hours).
$dqSkipped = $dqNewCalendar();
$dqSkips = [];
for ($dqHour = 0; $dqHour < 1000; $dqHour++) {
    $dqSkips[] = $dqUtc('2027-03-01 00:00:00')->modify('+' . $dqHour . ' hours')->format('Y-m-d H:i:s');
}
$dqRow($dqSkipped, ['uid' => 'dq-skipped@example.test', 'start_utc' => '2027-03-01 00:00:00', 'end_utc' => '2027-03-01 00:30:00', 'rrule' => 'FREQ=HOURLY;COUNT=1100', 'exdates_json' => json_encode($dqSkips)]);
checkEq('a range open before the series whose first 1,000 occurrences are skipped holds it, by the ones after them; one ending at Sun 11 Apr 16:00 does not', [['dq-skipped@example.test.ics'], []], [
    $dqQuery($dqSkipped, null, '2027-05-01 00:00:00'), $dqQuery($dqSkipped, null, '2027-04-11 16:00:00'),
]);
$dqLeft = $dqInstances($dqReport('/calendars/sam@example.test/cal-' . $dqSkipped . '/', $dqQueryBody('20270301T000000Z', '20270501T000000Z')));
checkEq('expand over it: the 100 left, from Sun 11 Apr 16:00 to Thu 15 Apr 19:00', [100, '20270411T160000Z 20270411T160000Z', '20270415T190000Z 20270415T190000Z'],
    [count($dqLeft), $dqLeft[0] ?? null, $dqLeft[99] ?? null]);

// The request's expansion budget, as the calendar's window charges it: a
// report past it is refused with the budget's words, never answered short.
// Hourly with no end from Fri 1 Jan 2027: 1 Jan 2027 to 1 Jan 2030 holds
// 26,280 (1,095 days), past the budget; daily with no end over ten years
// holds 3,653 (three leap days), under it.
$dqDense = $dqNewCalendar();
$dqRow($dqDense, ['uid' => 'dq-dense@example.test', 'start_utc' => '2027-01-01 00:00:00', 'end_utc' => '2027-01-01 00:30:00', 'rrule' => 'FREQ=HOURLY']);
$dqRefused = static function (callable $fn): ?array {
    try {
        $fn();
        return null;
    } catch (\Sabre\DAV\Exception\TooManyMatches $e) {
        return [$e->getHTTPCode(), $e->getMessage()];
    }
};
checkEq('expand past the budget, 26,280 hourly occurrences over three years: refused, 403 number-of-matches-within-limits in the budget\'s words', [
    403, 'This report has too many events or repeating occurrences to answer safely (too many generated occurrences). Ask for a shorter time range.',
], $dqRefused(fn() => $dqReport('/calendars/sam@example.test/cal-' . $dqDense . '/', $dqQueryBody('20270101T000000Z', '20300101T000000Z'))));
checkEq('the same series in a time range alone is answered: the first occurrence is enough', ['dq-dense@example.test.ics'], $dqQuery($dqDense, '2027-01-01 00:00:00', '2030-01-01 00:00:00'));
$dqDaily = $dqNewCalendar();
$dqRow($dqDaily, ['uid' => 'dq-daily@example.test', 'start_utc' => '2027-01-01 07:00:00', 'end_utc' => '2027-01-01 08:00:00', 'rrule' => 'FREQ=DAILY']);
$dqTen = $dqInstances($dqReport('/calendars/sam@example.test/cal-' . $dqDaily . '/', $dqQueryBody('20270101T000000Z', '20370101T000000Z')));
checkEq('expand under the budget, daily over ten years: all 3,653, from Fri 1 Jan 2027 to Wed 31 Dec 2036', [3653, '20270101T070000Z 20270101T070000Z', '20361231T070000Z 20361231T070000Z'],
    [count($dqTen), $dqTen[0] ?? null, $dqTen[3652] ?? null]);

// More occurrences at one moment than the cap: a window the cap still cuts at
// its shortest is refused like a range past the budget, never answered short.
// Every minute from Tue 1 Jun 2027 00:00 UTC, each 1,100 minutes long: at Thu
// 3 Jun 00:00:30 the ones started from Wed 2 Jun 05:41 on are running, 1,100 of
// them. The same series 1,000 minutes long has 1,000 running then (from Wed 2
// Jun 07:21), exactly the cap.
$dqStacked = $dqNewCalendar();
$dqRow($dqStacked, ['uid' => 'dq-stacked@example.test', 'start_utc' => '2027-06-01 00:00:00', 'end_utc' => '2027-06-01 18:20:00', 'rrule' => 'FREQ=MINUTELY;COUNT=4000']);
$dqAtCap = $dqNewCalendar();
$dqRow($dqAtCap, ['uid' => 'dq-atcap@example.test', 'start_utc' => '2027-06-01 00:00:00', 'end_utc' => '2027-06-01 16:40:00', 'rrule' => 'FREQ=MINUTELY;COUNT=4000']);
$dqStackedWords = [403, 'This report has too many events or repeating occurrences to answer safely (more occurrences at one moment than one answer can hold). Ask for a shorter time range.'];
checkEq('1,100 occurrences running at one second, Thu 3 Jun 00:00:30 to 00:00:31: the time range and the expand are each refused, 403, never answered short', [$dqStackedWords, $dqStackedWords], [
    $dqRefused(fn() => $dqQuery($dqStacked, '2027-06-03 00:00:30', '2027-06-03 00:00:31')),
    $dqRefused(fn() => $dqReport('/calendars/sam@example.test/cal-' . $dqStacked . '/', $dqQueryBody('20270603T000030Z', '20270603T000031Z'))),
]);
checkEq('the same over the hour from Thu 3 Jun 00:00: refused, 403', 403, $dqRefused(fn() => $dqQuery($dqStacked, '2027-06-03 00:00:00', '2027-06-03 01:00:00'))[0] ?? null);
$dqCapAll = $dqInstances($dqReport('/calendars/sam@example.test/cal-' . $dqAtCap . '/', $dqQueryBody('20270603T000030Z', '20270603T000031Z')));
checkEq('1,000 running at that second: the time range holds it and the expand lists all 1,000, each once, from Wed 2 Jun 07:21 to Thu 3 Jun 00:00', [
    ['dq-atcap@example.test.ics'], 1000, 1000, '20270602T072100Z 20270602T072100Z', '20270603T000000Z 20270603T000000Z',
], [$dqQuery($dqAtCap, '2027-06-03 00:00:30', '2027-06-03 00:00:31'), count($dqCapAll), count(array_unique($dqCapAll)), $dqCapAll[0] ?? null, $dqCapAll[999] ?? null]);

// An occurrence with no length on the boundary between two windows. Daily at
// 09:00 UTC from Thu 1 Jul 2027 with no length, 32 days (to Sun 1 Aug), the
// first 31 skipped. A range from 09:00:01 on 1 Jul starts the first window at
// 1 Jul 09:00:00, so it ends 31 days on, at Sun 1 Aug 09:00:00: the one left.
$dqEdge = $dqNewCalendar();
$dqEdgeSkips = [];
for ($dqDay = 0; $dqDay < 31; $dqDay++) {
    $dqEdgeSkips[] = $dqUtc('2027-07-01 09:00:00')->modify('+' . $dqDay . ' days')->format('Y-m-d H:i:s');
}
$dqRow($dqEdge, ['uid' => 'dq-edge@example.test', 'start_utc' => '2027-07-01 09:00:00', 'end_utc' => '2027-07-01 09:00:00', 'rrule' => 'FREQ=DAILY;COUNT=32', 'exdates_json' => json_encode($dqEdgeSkips)]);
checkEq('a series whose one occurrence left, Sun 1 Aug 09:00 with no length, sits where the first window ends: the time range from 1 Jul 09:00:01 to 15 Aug holds it, and the expand lists it once', [
    ['dq-edge@example.test.ics'], ['20270801T090000Z 20270801T090000Z'],
], [
    $dqQuery($dqEdge, '2027-07-01 09:00:01', '2027-08-15 00:00:00'),
    $dqInstances($dqReport('/calendars/sam@example.test/cal-' . $dqEdge . '/', $dqQueryBody('20270701T090001Z', '20270815T000000Z'))),
]);

// The budget is charged for the occurrences the walk generates, skipped ones
// too, not only those answered: hourly from Wed 1 Sep 2027, 1,000 events, the
// first 900 skipped, leaves 100, from Fri 8 Oct 12:00 (900 hours on) to Tue 12
// Oct 15:00. Through a budget of 100 it is refused; through the standard one
// it is answered.
$dqMostlySkipped = ['id' => 1, 'uid' => 'dq-mostly@example.test', 'start_utc' => '2027-09-01 00:00:00', 'end_utc' => '2027-09-01 00:30:00', 'tzid' => 'UTC', 'all_day' => 0,
    'rrule' => 'FREQ=HOURLY;COUNT=1000', 'exdates_json' => json_encode(array_map(static fn(int $h): string => $dqUtc('2027-09-01 00:00:00')->modify('+' . $h . ' hours')->format('Y-m-d H:i:s'), range(0, 899)))];
$dqBudgetRefusal = static function (BetterCal\Support\ExpansionBudget $budget) use ($dqMostlySkipped, $dqUtc) {
    try {
        return DavTimeRange::occurrences($dqMostlySkipped, [], $dqUtc('2027-09-01 00:00:00'), $dqUtc('2027-11-01 00:00:00'), false, $budget);
    } catch (BetterCal\Support\WorkBudgetExceeded $e) {
        return $e->getMessage();
    }
};
$dqMostlyLeft = $dqBudgetRefusal(BetterCal\Support\ExpansionBudget::standard());
checkEq('900 skipped occurrences through a budget of 100: refused; through the standard budget: the 100 left, from Fri 8 Oct 12:00 to Tue 12 Oct 15:00', [
    'too many generated occurrences', 100, '2027-10-08 12:00:00', '2027-10-12 15:00:00',
], [
    $dqBudgetRefusal(new BetterCal\Support\ExpansionBudget(10, 100, 60.0)),
    is_array($dqMostlyLeft) ? count($dqMostlyLeft) : $dqMostlyLeft,
    is_array($dqMostlyLeft) ? $dqMostlyLeft[0]['start']->format('Y-m-d H:i:s') : null,
    is_array($dqMostlyLeft) ? $dqMostlyLeft[99]['start']->format('Y-m-d H:i:s') : null,
]);

// The series charge is per series, not per window: a range with no end reads
// each object over many windows (a series from 2020 read to the year 9999
// takes about twenty), and each series counts once against the request's
// 5,000. 300 daily series of three days each in January 2020, all long over,
// and one event on Mon 1 Mar 2027: a range from Fri 1 Jan 2027 with no end
// holds the event alone, not refused.
//
// And the windows leave no garbage behind: an iterator's candidate stream
// runs on a walker of its own, never on the iterator, so each window's
// iterator is freed when it is dropped, not left to the cycle collector. The
// query runs with the collector off and must grow memory by under 8 MiB (it
// grew by about 68 MiB when every window's iterator was a cycle).
$dqExpired = $dqNewCalendar();
$dqExpiredMasters = [];
for ($dqN = 0; $dqN < 300; $dqN++) {
    $dqAt = $dqUtc('2020-01-01 09:00:00')->modify('+' . $dqN . ' minutes')->format('Y-m-d H:i:s');
    $dqExpiredId = $dqRow($dqExpired, ['uid' => 'dq-expired-' . $dqN . '@example.test', 'start_utc' => $dqAt, 'end_utc' => $dqAt, 'rrule' => 'FREQ=DAILY;COUNT=3']);
    $dqExpiredMasters[] = $dqDb->one('SELECT * FROM events WHERE id = ?', [$dqExpiredId]);
}
$dqRow($dqExpired, ['uid' => 'dq-upcoming@example.test', 'start_utc' => '2027-03-01 18:00:00', 'end_utc' => '2027-03-01 19:00:00']);
$dqGcWasOn = gc_enabled();
gc_disable();
$dqMemBefore = memory_get_usage();
$dqExpiredAnswer = (static function () use ($dqQuery, $dqExpired) {
    try {
        return $dqQuery($dqExpired, '2027-01-01 00:00:00', null);
    } catch (\Sabre\DAV\Exception\TooManyMatches $e) {
        return [$e->getHTTPCode(), $e->getMessage()];
    }
})();
$dqMemGrowth = memory_get_usage() - $dqMemBefore;
if ($dqGcWasOn) {
    gc_enable();
}
checkEq('300 series ended in January 2020 and one event on Mon 1 Mar 2027, under the standard budget: a range from Fri 1 Jan 2027 with no end holds the event alone, not refused', [
    'dq-upcoming@example.test.ics',
], $dqExpiredAnswer);
check('the same query, the cycle collector off, grows memory by under 8 MiB', $dqMemGrowth < 8 * 1024 * 1024, 'grew ' . number_format($dqMemGrowth) . ' bytes');
$dqPerSeries = BetterCal\Support\ExpansionBudget::standard();
foreach ($dqExpiredMasters as $dqMaster) {
    DavTimeRange::occurrences($dqMaster, [], $dqUtc('2027-01-01 00:00:00'), null, true, $dqPerSeries);
}
checkEq('the same 300 read over every window to the year 9999 through one budget: 300 series charged, one each, and no occurrences', ['series' => 300, 'occurrences' => 0], $dqPerSeries->usage());

// The series charge in an expand REPORT is per object: each object served
// expanded is parsed, with no stored id, and is charged under its own path,
// so a calendar-multiget (which never passes through calendarQuery's budget)
// counts every object it expands, and one object read over many windows
// counts once. Three daily series of two days from Fri 1 Jan 2027 at 10:00,
// read 1 to 4 Jan.
$dqTrio = $dqNewCalendar();
$dqTrioUids = ['dq-trio-one@example.test', 'dq-trio-two@example.test', 'dq-trio-three@example.test'];
foreach ($dqTrioUids as $dqUid) {
    $dqRow($dqTrio, ['uid' => $dqUid, 'start_utc' => '2027-01-01 10:00:00', 'end_utc' => '2027-01-01 11:00:00', 'rrule' => 'FREQ=DAILY;COUNT=2']);
}
$dqTrioPath = '/calendars/sam@example.test/cal-' . $dqTrio . '/';
$dqMultiget = static fn(string $path, array $uids, string $start, string $end): string => '<?xml version="1.0" encoding="utf-8"?>'
    . '<C:calendar-multiget xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:prop>' . sprintf($dqExpand, $start, $end) . '</D:prop>'
    . implode('', array_map(static fn(string $uid): string => '<D:href>' . $path . $uid . '.ics</D:href>', $uids)) . '</C:calendar-multiget>';
$dqSeriesAt = static function (int $series, callable $fn) use ($dqRefused) {
    $prior = BetterCal\Support\Limits::get('EXPANSION_SERIES');
    BetterCal\Support\Limits::configure(['EXPANSION_SERIES' => $series]);
    try {
        return $dqRefused($fn);
    } finally {
        BetterCal\Support\Limits::configure(['EXPANSION_SERIES' => $prior]);
    }
};
$dqSeriesRefusal = [403, 'This report has too many events or repeating occurrences to answer safely (too many repeating series). Ask for a shorter time range.'];
$dqPair = static fn() => $dqReport($dqTrioPath, $dqMultiget($dqTrioPath, array_slice($dqTrioUids, 0, 2), '20270101T000000Z', '20270104T000000Z'));
checkEq('calendar-multiget with expand of two repeating objects, a series limit of 1: refused, two series', $dqSeriesRefusal, $dqSeriesAt(1, $dqPair));
$dqPairXml = null;
checkEq('the same multiget, a series limit of 2: answered, both objects\' two days', [null, 4], [
    $dqSeriesAt(2, static function () use ($dqPair, &$dqPairXml) { $dqPairXml = $dqPair(); }), substr_count((string) $dqPairXml, 'BEGIN:VEVENT'),
]);
$dqTrioAll = static fn() => $dqReport($dqTrioPath, $dqMultiget($dqTrioPath, $dqTrioUids, '20270101T000000Z', '20270104T000000Z'));
$dqTrioXml = null;
checkEq('calendar-multiget with expand of three repeating objects counts three: refused at a limit of 2, answered at 3 with six days', [$dqSeriesRefusal, null, 6], [
    $dqSeriesAt(2, $dqTrioAll), $dqSeriesAt(3, static function () use ($dqTrioAll, &$dqTrioXml) { $dqTrioXml = $dqTrioAll(); }), substr_count((string) $dqTrioXml, 'BEGIN:VEVENT'),
]);
$dqHourlyOnce = null;
checkEq('calendar-multiget with expand of the hourly object over March and April, read in many windows, a series limit of 1: answered, all 1,100', [null, 1100], [
    $dqSeriesAt(1, static function () use ($dqReport, $dqMultiget, $dqHourly, &$dqHourlyOnce) {
        $dqHourlyOnce = $dqReport('/calendars/sam@example.test/cal-' . $dqHourly . '/', $dqMultiget('/calendars/sam@example.test/cal-' . $dqHourly . '/', ['dq-hourly@example.test'], '20270301T000000Z', '20270501T000000Z'));
    }),
    substr_count((string) $dqHourlyOnce, 'BEGIN:VEVENT'),
]);
$dqTrioBudget = BetterCal\Support\ExpansionBudget::standard();
foreach ($dqTrioUids as $dqUid) {
    $dqObject = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\nBEGIN:VEVENT\r\nUID:" . $dqUid . "\r\nDTSTART:20270101T100000Z\r\nDTEND:20270101T110000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nSUMMARY:Sample\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    DavTimeRange::expandObject($dqObject, $dqUtc('2027-01-01 00:00:00'), $dqUtc('2027-01-04 00:00:00'), $dqTrioBudget);
}
checkEq('three parsed objects expanded through one budget with no path given: three series, by their UIDs, and six occurrences', ['series' => 3, 'occurrences' => 6], $dqTrioBudget->usage());
