<?php

declare(strict_types=1);

// A record of one day of a series, named by RECURRENCE-ID, is for the
// occurrence that name gives under its SERIES' form and zone (Ics::
// keysUnder), whatever form the record itself has; and a skipped date
// (EXDATE) the same. Through every door iCalendar text comes in by: an ICS
// feed, a CalDAV PUT, an imported file and an emailed update.
//
// The two mixed forms: a series at 19:00 New York whose 6 January the
// sender made a whole day, written as zoned midnights and named by the
// series' own RECURRENCE-ID (19:00 on the 6th); and a whole-day series
// written as zoned midnights whose 6 January got a clock time, named by the
// series' own RECURRENCE-ID (midnight on the 6th). Then the names RFC 5545
// forbids and senders write: a DATE under a timed series, as an EXDATE and
// as a RECURRENCE-ID.
//
// 19:00 in New York on 6 January 2027 is 00:00 UTC on the 7th, so the key of
// the timed 6 January equals the key of an all-day 7 January, and a name
// read under the wrong form lands on a real neighbouring day. Every
// expectation is by calendar date in New York, written by hand. Invented
// data. The parser alone is tests/ics-keys.php.

use BetterCal\Domain\Feeds;
use BetterCal\Domain\Ics;
use BetterCal\Domain\ReviewQueue;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $ny = new DateTimeZone('America/New_York');
    $cal = static fn(array $events, string $uid, ?string $method = null): string => implode("\r\n", array_merge(
        ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sample//Feed//EN'], $method !== null ? ['METHOD:' . $method] : [],
        ...array_map(static fn(array $e): array => array_merge(['BEGIN:VEVENT', 'UID:' . $uid, 'DTSTAMP:20270101T000000Z'], $e, ['END:VEVENT']), $events),
    )) . "\r\nEND:VCALENDAR\r\n";
    // What the calendar shows on a New York date: [when, all-day, title, tags] per event, a whole day by its date.
    $shownOn = static function (object $f, string $day) use ($ny): array {
        $from = new DateTimeImmutable($day . ' 00:00', $ny);
        $out = [];
        foreach ($f->d->events->window($f->u, $from, $from->modify('+1 day'), [$f->calendarId], null, true) as $o) {
            $allDay = (bool) $o['allDay'];
            $start = new DateTimeImmutable((string) $o['start']);
            // A window from New York midnight also overlaps the whole day of the UTC date before it.
            if ($allDay && $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d') !== $day) {
                continue;
            }
            $out[] = [$allDay ? $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d') : $start->setTimezone($ny)->format('Y-m-d H:i'), $allDay, $o['title'], $o['tags'] ?? []];
        }
        sort($out);
        return $out;
    };
    $keysOf = static fn(int $calendarId, string $title): array => array_column(
        $db->all('SELECT recurrence_instance_utc FROM events WHERE calendar_id = ? AND title = ? AND deleted_at IS NULL', [$calendarId, $title]), 'recurrence_instance_utc');
    $feed = static function () use ($db): object {
        $u = freshUser($db);
        $d = domains($db);
        $calendarId = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample league', 'kind' => 'subscribed', 'color' => '#336699']);
        $feeds = new Feeds($db);
        return (object) ['u' => $u, 'd' => $d, 'calendarId' => $calendarId,
            'sync' => static fn(string $body) => $feeds->sync($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]), Ics::parse($body, 'America/New_York'))];
    };
    $client = static function () use ($db): object {
        $u = freshUser($db);
        $d = domains($db);
        $token = (new BetterCal\Domain\ApiTokens($db))->create($u, 'Sample client');
        $backend = new BetterCal\Dav\CalendarBackend($db, new BetterCal\Domain\Undo($db), fn() => BetterCal\Domain\RequestAuthority::credential($u, (int) $token['id'], null));
        return (object) ['u' => $u, 'd' => $d, 'calendarId' => (int) BetterCal\Domain\Settings::defaultCalendarId($db, $u), 'backend' => $backend];
    };
    $mailbox = static function () use ($db): object {
        $u = freshUser($db);
        $d = domains($db);
        BetterCal\Domain\Settings::put($db, $u, ['homeTz' => 'America/New_York']);
        BetterCal\Domain\MailIngest::ensureInviteCalendar($db, $u);
        BetterCal\Domain\Settings::put($db, $u, ['mailApprovedSenders' => ['host@example.test'], 'mailEventMode' => 'approved']);
        $mail = new BetterCal\Domain\MailIngest($db, $d->events, null, new BetterCal\Domain\MailAdmission($db));
        $message = static fn(string $id, string $body): array => ['messageId' => "$id@example.test", 'subject' => 'Sample', 'from' => 'host@example.test', 'icsParts' => [$body], 'html' => null, 'text' => null];
        return (object) ['u' => $u, 'd' => $d, 'rq' => new ReviewQueue($db, $d->events),
            'send' => static fn(string $id, string $body) => $mail->ingestMessage($u, $message($id, $body), 'America/New_York'),
            'series' => static fn(string $uid): ?array => $db->one('SELECT * FROM events WHERE user_id = ? AND uid = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL', [$u, $uid]),
            'items' => static fn(string $uid): array => $db->all("SELECT * FROM review_items WHERE user_id = ? AND source_key = ? AND status = 'open' ORDER BY id", [$u, $uid])];
    };
    $who = ['ORGANIZER;CN=Sample Host:mailto:host@example.test', 'ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:owner@example.test'];

    // A series at 19:00 New York, and its 6 January made a whole day (zoned midnights), named by 19:00 on the 6th.
    $game = ['DTSTART;TZID=America/New_York:20270104T190000', 'DTEND;TZID=America/New_York:20270104T200000', 'RRULE:FREQ=DAILY;COUNT=10', 'SUMMARY:Sample game'];
    $sixthWholeDay = ['RECURRENCE-ID;TZID=America/New_York:20270106T190000', 'DTSTART;TZID=America/New_York:20270106T000000', 'DTEND;TZID=America/New_York:20270107T000000', 'SUMMARY:Sample game, all day'];
    // A whole-day series as zoned midnights, and its 6 January given a clock time, named by midnight on the 6th.
    $camp = ['DTSTART;TZID=America/New_York:20270104T000000', 'DTEND;TZID=America/New_York:20270105T000000', 'RRULE:FREQ=DAILY;COUNT=10', 'SUMMARY:Sample camp'];
    $sixthAfternoon = ['RECURRENCE-ID;TZID=America/New_York:20270106T000000', 'DTSTART;TZID=America/New_York:20270106T140000', 'DTEND;TZID=America/New_York:20270106T150000', 'SUMMARY:Sample camp, afternoon'];
    $at19 = static fn(int $day, array $tags = []): array => [sprintf('2027-01-%02d 19:00', $day), false, 'Sample game', $tags];
    $wholeDay = static fn(int $day, array $tags = []): array => [sprintf('2027-01-%02d', $day), true, 'Sample camp', $tags];
    // After either change, by New York date: the two neighbours as the series has them, the 6th the record alone.
    $gameAfter = static fn(array $fifthTags = []): array => [[$at19(5, $fifthTags)], [['2027-01-06', true, 'Sample game, all day', []]], [$at19(7)]];
    $campAfter = static fn(array $sixthTags = []): array => [[$wholeDay(5)], [['2027-01-06 14:00', false, 'Sample camp, afternoon', $sixthTags]], [$wholeDay(7)]];
    $three = static fn(object $f): array => [$shownOn($f, '2027-01-05'), $shownOn($f, '2027-01-06'), $shownOn($f, '2027-01-07')];

    // ---- An ICS feed ---------------------------------------------------------

    scenario('instance names, feed: a 19:00 New York series whose 6 January becomes a whole day as zoned midnights', function () use ($db, $feed, $cal, $shownOn, $three, $keysOf, $game, $sixthWholeDay, $at19, $gameAfter): void {
        $f = $feed();
        ($f->sync)($cal([$game], 'sample-feed-game@example.test'));
        $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NULL', [$f->calendarId]);
        // A tag on 5 January, whose key (19:00 New York on the 5th) is 00:00 UTC on the 6th.
        $f->d->events->patch($f->u, $master, ['scope' => 'this', 'instanceStart' => '2027-01-06T00:00:00+00:00', 'tagNames' => ['sample-fifth']]);
        checkEq('feed, timed series: before, 5 January shows the game with its tag and 6 January the game', [[$at19(5, ['sample-fifth'])], [$at19(6)], [$at19(7)]], $three($f));
        ($f->sync)($cal([$game, $sixthWholeDay], 'sample-feed-game@example.test'));
        checkEq('feed, timed series: 5 January keeps its game and its tag, 6 January is the whole-day record alone, 7 January its game', $gameAfter(['sample-fifth']), $three($f));
        checkEq('feed, timed series: the record\'s row is keyed at the occurrence of 6 January, 00:00 UTC on the 7th', ['2027-01-07 00:00:00'], $keysOf($f->calendarId, 'Sample game, all day'));
        ($f->sync)($cal([$game, $sixthWholeDay], 'sample-feed-game@example.test'));
        checkEq('feed, timed series: read once more, the same three days', $gameAfter(['sample-fifth']), $three($f));
    });

    scenario('instance names, feed: a whole-day series as zoned midnights whose 6 January gets a clock time', function () use ($db, $feed, $cal, $three, $keysOf, $camp, $sixthAfternoon, $wholeDay, $campAfter): void {
        $f = $feed();
        ($f->sync)($cal([$camp], 'sample-feed-camp@example.test'));
        $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NULL', [$f->calendarId]);
        $f->d->events->patch($f->u, $master, ['scope' => 'this', 'instanceStart' => '2027-01-06T00:00:00+00:00', 'tagNames' => ['sample-sixth']]);
        checkEq('feed, whole-day series: before, 6 January shows the whole day with its tag', [[$wholeDay(5)], [$wholeDay(6, ['sample-sixth'])], [$wholeDay(7)]], $three($f));
        ($f->sync)($cal([$camp, $sixthAfternoon], 'sample-feed-camp@example.test'));
        checkEq('feed, whole-day series: 6 January is the record at 14:00 alone, with the tag given to that day; its neighbours are whole days', $campAfter(['sample-sixth']), $three($f));
        checkEq('feed, whole-day series: the record\'s row is keyed at the date of 6 January', ['2027-01-06 00:00:00'], $keysOf($f->calendarId, 'Sample camp, afternoon'));
    });

    // ---- A CalDAV PUT --------------------------------------------------------

    scenario('instance names, CalDAV PUT: a 19:00 New York series whose 6 January the client makes a whole day as zoned midnights', function () use ($db, $client, $cal, $three, $keysOf, $game, $sixthWholeDay, $gameAfter): void {
        $c = $client();
        $c->backend->createCalendarObject($c->calendarId, 'sample-put-game.ics', $cal([$game], 'sample-put-game'));
        $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL', [$c->calendarId, 'sample-put-game']);
        $c->d->events->patch($c->u, $master, ['scope' => 'this', 'instanceStart' => '2027-01-06T00:00:00+00:00', 'tagNames' => ['sample-fifth']]);
        // The client sends the tagged 5th back as it was served (the series' values) and makes the 6th a whole day.
        $fifthEcho = ['RECURRENCE-ID;TZID=America/New_York:20270105T190000', 'DTSTART;TZID=America/New_York:20270105T190000', 'DTEND;TZID=America/New_York:20270105T200000', 'SUMMARY:Sample game'];
        $c->backend->updateCalendarObject($c->calendarId, 'sample-put-game.ics', $cal([$game, $fifthEcho, $sixthWholeDay], 'sample-put-game'));
        checkEq('PUT, timed series: 5 January keeps its game and its tag, 6 January is the whole-day record alone, 7 January its game', $gameAfter(['sample-fifth']), $three($c));
        checkEq('PUT, timed series: the record\'s row is keyed at the occurrence of 6 January', ['2027-01-07 00:00:00'], $keysOf($c->calendarId, 'Sample game, all day'));
    });

    scenario('instance names, CalDAV PUT: a whole-day series as zoned midnights whose 6 January the client gives a clock time', function () use ($db, $client, $cal, $three, $keysOf, $camp, $sixthAfternoon, $campAfter): void {
        $c = $client();
        $c->backend->createCalendarObject($c->calendarId, 'sample-put-camp.ics', $cal([$camp], 'sample-put-camp'));
        $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL', [$c->calendarId, 'sample-put-camp']);
        $c->d->events->patch($c->u, $master, ['scope' => 'this', 'instanceStart' => '2027-01-06T00:00:00+00:00', 'tagNames' => ['sample-sixth']]);
        $c->backend->updateCalendarObject($c->calendarId, 'sample-put-camp.ics', $cal([$camp, $sixthAfternoon], 'sample-put-camp'));
        checkEq('PUT, whole-day series: 6 January is the record at 14:00 alone, with the tag given to that day; its neighbours are whole days', $campAfter(['sample-sixth']), $three($c));
        checkEq('PUT, whole-day series: the record\'s row is keyed at the date of 6 January', ['2027-01-06 00:00:00'], $keysOf($c->calendarId, 'Sample camp, afternoon'));
    });

    // ---- An imported file ----------------------------------------------------

    scenario('instance names, import: a file with each series and its day of the other form', function () use ($db, $client, $cal, $three, $keysOf, $game, $sixthWholeDay, $camp, $sixthAfternoon, $gameAfter, $campAfter): void {
        $c = $client();
        $import = new BetterCal\Domain\CalendarImport($db, $c->d->calendars);
        $gameCal = (int) $import->importIcs($c->u, $cal([$game, $sixthWholeDay], 'sample-file-game'), 'Sample games')['calendarId'];
        checkEq('import, timed series: 5 January its game, 6 January the whole-day record alone, 7 January its game', $gameAfter(), $three((object) ['u' => $c->u, 'd' => $c->d, 'calendarId' => $gameCal]));
        checkEq('import, timed series: the record\'s row is keyed at the occurrence of 6 January', ['2027-01-07 00:00:00'], $keysOf($gameCal, 'Sample game, all day'));
        $campCal = (int) $import->importIcs($c->u, $cal([$camp, $sixthAfternoon], 'sample-file-camp'), 'Sample camps')['calendarId'];
        checkEq('import, whole-day series: 6 January is the record at 14:00 alone; its neighbours are whole days', $campAfter(), $three((object) ['u' => $c->u, 'd' => $c->d, 'calendarId' => $campCal]));
        checkEq('import, whole-day series: the record\'s row is keyed at the date of 6 January', ['2027-01-06 00:00:00'], $keysOf($campCal, 'Sample camp, afternoon'));
    });

    // ---- An emailed update ---------------------------------------------------

    $mailed = function (string $what, string $uid, array $series, array $record, array $after) use ($db, $mailbox, $cal, $three, $who): void {
        $m = $mailbox();
        $added = ($m->send)("$uid-series", $cal([array_merge($series, ['SEQUENCE:0'], $who)], $uid, 'REQUEST'));
        $stored = ($m->series)($uid);
        checkEq("mail, $what: the series is on the calendar", ['created', true], [$added['outcome'] ?? null, $stored !== null]);
        $f = (object) ['u' => $m->u, 'd' => $m->d, 'calendarId' => (int) ($stored['calendar_id'] ?? 0)];
        // The organizer's change to the one day, sent alone.
        ($m->send)("$uid-day", $cal([array_merge($record, ['SEQUENCE:1'], $who)], $uid, 'REQUEST'));
        $items = ($m->items)($uid);
        checkEq("mail, $what: Review holds one change, to the Wed 6 Jan 2027 occurrence", [1, true],
            [count($items), isset($items[0]) && str_contains((string) $items[0]['summary'], 'changed the Wed 6 Jan 2027 occurrence')]);
        if (isset($items[0])) {
            $m->rq->accept($m->u, (int) $items[0]['id']);
        }
        checkEq("mail, $what: accepted, 6 January alone changes", $after, $three($f));
    };
    scenario('instance names, emailed update: the organizer makes 6 January of a 19:00 New York series a whole day as zoned midnights', function () use ($mailed, $game, $sixthWholeDay, $gameAfter): void {
        $mailed('timed series', 'sample-mail-game@example.test', $game, $sixthWholeDay, $gameAfter());
    });
    scenario('instance names, emailed update: the organizer gives 6 January of a whole-day series (zoned midnights) a clock time', function () use ($mailed, $camp, $sixthAfternoon, $campAfter): void {
        $mailed('whole-day series', 'sample-mail-camp@example.test', $camp, $sixthAfternoon, $campAfter());
    });

    // ---- A DATE that names a day of a timed series ---------------------------

    scenario('instance names, feed: a 19:00 New York series with EXDATE;VALUE=DATE:20270107 skips 7 January, and 6 January keeps its game', function () use ($feed, $cal, $shownOn, $game, $at19): void {
        $f = $feed();
        ($f->sync)($cal([array_merge($game, ['EXDATE;VALUE=DATE:20270107'])], 'sample-feed-skip@example.test'));
        checkEq('feed, a DATE in EXDATE: 6 January shows its game, 7 January nothing, 8 January its game', [[$at19(6)], [], [$at19(8)]],
            [$shownOn($f, '2027-01-06'), $shownOn($f, '2027-01-07'), $shownOn($f, '2027-01-08')]);
    });

    scenario('instance names, feed: a 19:00 New York series with a record named RECURRENCE-ID;VALUE=DATE:20270107, moved to 21:00 on the 7th', function () use ($feed, $cal, $three, $keysOf, $game, $at19): void {
        $f = $feed();
        ($f->sync)($cal([$game, ['RECURRENCE-ID;VALUE=DATE:20270107', 'DTSTART;TZID=America/New_York:20270107T210000', 'DTEND;TZID=America/New_York:20270107T220000', 'SUMMARY:Sample game, late']],
            'sample-feed-date-name@example.test'));
        checkEq('feed, a DATE in RECURRENCE-ID: 6 January keeps its game, 7 January is the record at 21:00 alone', [[$at19(5)], [$at19(6)], [['2027-01-07 21:00', false, 'Sample game, late', []]]], $three($f));
        checkEq('feed, a DATE in RECURRENCE-ID: the record\'s row is keyed at the occurrence of 7 January, 00:00 UTC on the 8th', ['2027-01-08 00:00:00'], $keysOf($f->calendarId, 'Sample game, late'));
    });

    // The organizer makes the series whole days and then renames its 7 January, naming it by the DATE its new series
    // has; both wait in Review. Here the series is still at 19:00, and the day's change is for its 7 January.
    $twoHeld = function (string $uid) use ($mailbox, $cal, $game, $who): array {
        $m = $mailbox();
        ($m->send)("$uid-series", $cal([array_merge($game, ['SEQUENCE:0'], $who)], $uid, 'REQUEST'));
        $stored = ($m->series)($uid);
        $f = (object) ['u' => $m->u, 'd' => $m->d, 'calendarId' => (int) ($stored['calendar_id'] ?? 0)];
        ($m->send)("$uid-allday", $cal([array_merge(['DTSTART;VALUE=DATE:20270104', 'DTEND;VALUE=DATE:20270105', 'RRULE:FREQ=DAILY;COUNT=10', 'SUMMARY:Sample game', 'SEQUENCE:1'], $who)], $uid, 'REQUEST'));
        ($m->send)("$uid-day", $cal([array_merge(['RECURRENCE-ID;VALUE=DATE:20270107', 'DTSTART;VALUE=DATE:20270107', 'DTEND;VALUE=DATE:20270108', 'SUMMARY:Sample game, whole day', 'SEQUENCE:2'], $who)], $uid, 'REQUEST'));
        $ofDay = static fn(array $it): bool => isset(json_decode((string) $it['payload_json'], true)['occurrence']);
        $items = ($m->items)($uid);
        return [$m, $f, $stored, array_values(array_filter($items, $ofDay)), array_values(array_filter($items, static fn(array $it): bool => !$ofDay($it)))];
    };
    // Accepting an item whose differences were worked out before another change is refused once, with them refreshed.
    $accept = static function (object $m, int $id): void {
        try {
            $m->rq->accept($m->u, $id);
        } catch (BetterCal\Http\HttpError $e) {
            if ($e->errorCode !== 'review_changed') {
                throw $e;
            }
            $m->rq->accept($m->u, $id);
        }
    };
    $wholeDays = static fn(int $day, string $title = 'Sample game'): array => [sprintf('2027-01-%02d', $day), true, $title, []];

    scenario('instance names, emailed change to a whole-day 7 January while the series\' change to whole days waits in Review: the day accepted first', function () use ($twoHeld, $accept, $three, $at19, $wholeDays): void {
        [$m, $f, $stored, $dayItems, $seriesItems] = $twoHeld('sample-mail-dates@example.test');
        check('mail, a DATE name: the series is on the calendar at 19:00', $stored !== null && (int) $stored['all_day'] === 0);
        checkEq('mail, a DATE name: Review holds one change to a day, and names the Thu 7 Jan 2027 occurrence, the day the organizer changed', [1, true],
            [count($dayItems), isset($dayItems[0]) && str_contains((string) $dayItems[0]['summary'], 'changed the Thu 7 Jan 2027 occurrence')]);
        $accept($m, (int) $dayItems[0]['id']);
        checkEq('mail, a DATE name: the day\'s change accepted alone, 6 January keeps its game and 7 January is the whole-day record alone',
            [[$at19(5)], [$at19(6)], [['2027-01-07', true, 'Sample game, whole day', []]]], $three($f));
        $accept($m, (int) $seriesItems[0]['id']);
        checkEq('mail, a DATE name: then the series\' change accepted, every day is a whole day and 7 January still the record alone',
            [[$wholeDays(5)], [$wholeDays(6)], [$wholeDays(7, 'Sample game, whole day')]], $three($f));
    });

    scenario('instance names, emailed change to a whole-day 7 January while the series\' change to whole days waits in Review: the series accepted first', function () use ($db, $twoHeld, $accept, $shownOn, $three, $wholeDays): void {
        [$m, $f, , $dayItems, $seriesItems] = $twoHeld('sample-mail-dates-2@example.test');
        $accept($m, (int) $seriesItems[0]['id']);
        checkEq('mail, the series first: the held day still names 7 January, now by its date under the whole-day series', '2027-01-07 00:00:00',
            json_decode((string) $db->scalar('SELECT payload_json FROM review_items WHERE id = ?', [(int) $dayItems[0]['id']]), true)['occurrence'] ?? null);
        $accept($m, (int) $dayItems[0]['id']);
        checkEq('mail, the series first: the day\'s change accepted, 7 January is the whole-day record alone, and 6 and 8 January are the series\' whole days',
            [[$wholeDays(6)], [$wholeDays(7, 'Sample game, whole day')], [$wholeDays(8)]], [$shownOn($f, '2027-01-06'), $shownOn($f, '2027-01-07'), $shownOn($f, '2027-01-08')]);
    });

    // The organizer renames Tuesday 5 January, then makes the series whole days on Mondays and Wednesdays: the series
    // no longer has the 5th. The held day's key (19:00 New York on the 5th, 00:00 UTC on the 6th) is the key of the
    // whole-day Wednesday 6 January, a day the new series has; the held change is not for that day.
    scenario('instance names, emailed change to 5 January held in Review, then the series accepted as whole days on other days: the held change is closed, and 6 January is not changed', function () use ($db, $mailbox, $cal, $game, $who, $accept, $shownOn, $wholeDays): void {
        $m = $mailbox();
        $uid = 'sample-mail-gone@example.test';
        ($m->send)("$uid-series", $cal([array_merge($game, ['SEQUENCE:0'], $who)], $uid, 'REQUEST'));
        $stored = ($m->series)($uid);
        $f = (object) ['u' => $m->u, 'd' => $m->d, 'calendarId' => (int) ($stored['calendar_id'] ?? 0)];
        ($m->send)("$uid-day", $cal([array_merge(['RECURRENCE-ID;TZID=America/New_York:20270105T190000', 'DTSTART;TZID=America/New_York:20270105T190000',
            'DTEND;TZID=America/New_York:20270105T200000', 'SUMMARY:Sample game, renamed', 'SEQUENCE:1'], $who)], $uid, 'REQUEST'));
        ($m->send)("$uid-days", $cal([array_merge(['DTSTART;VALUE=DATE:20270104', 'DTEND;VALUE=DATE:20270105', 'RRULE:FREQ=WEEKLY;BYDAY=MO,WE;COUNT=6', 'SUMMARY:Sample game', 'SEQUENCE:2'], $who)], $uid, 'REQUEST'));
        $items = ($m->items)($uid);
        $ofDay = static fn(array $it): bool => isset(json_decode((string) $it['payload_json'], true)['occurrence']);
        $day = array_values(array_filter($items, $ofDay))[0] ?? null;
        $series = array_values(array_filter($items, static fn(array $it): bool => !$ofDay($it)))[0] ?? null;
        checkEq('mail, a day the series loses: Review holds the change to the Tue 5 Jan 2027 occurrence and the series\' change', [true, true],
            [$day !== null && str_contains((string) $day['summary'], 'changed the Tue 5 Jan 2027 occurrence'), $series !== null]);
        if ($series !== null) {
            $accept($m, (int) $series['id']);
        }
        $after = $day === null ? null : $db->one('SELECT status, summary FROM review_items WHERE id = ?', [(int) $day['id']]);
        checkEq('mail, a day the series loses: the series accepted, the held change is closed, saying the series no longer has Tue 5 Jan 2027',
            ['superseded', 'Sample Host changed the Tue 5 Jan 2027 occurrence, but the series no longer has that day, so there is nothing to change.'],
            [$after['status'] ?? null, $after['summary'] ?? null]);
        checkEq('mail, a day the series loses: 5 January shows nothing, and 6 January the series\' whole day under its own title',
            [[], [$wholeDays(6)]], [$shownOn($f, '2027-01-05'), $shownOn($f, '2027-01-06')]);
    });
};
