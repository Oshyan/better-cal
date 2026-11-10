<?php

declare(strict_types=1);

// A record of one day held before its series: the account was invited to
// one occurrence of a repeating event (a day's record with no series in the
// calendar; the sync stores it with no series above it), and the whole
// series arrives later. The arriving series' record of that day is for that
// row, the same instance by its key and, at Google, by its id: the row is
// adopted by its series (put under it, its own fields read by equality with
// the series as for any row never seen, its tags and people its own where
// they differ), never removed with what was given to it here.
//
// On a Google calendar (the stand-in of tools/google-probe, in both list
// orders), through an ICS feed and through a CalDAV PUT, with the record
// first and, as the control, the series first. Expectations by calendar
// date in New York, written by hand: the class is daily at 19:00 New York
// from 4 January 2027, and its 6 January was moved to 20:00 and renamed.
// Invented data.

use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Ics;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Trips;
use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/google-probe/lib/FakeGoogle.php';

return static function (Db $db): void {
    $ny = new DateTimeZone('America/New_York');
    $shownOn = static function (Events $events, int $u, int $calendarId, string $day) use ($ny): array {
        $from = new DateTimeImmutable($day . ' 00:00', $ny);
        $out = [];
        foreach ($events->window($u, $from, $from->modify('+1 day'), [$calendarId], null, true) as $o) {
            $out[] = [(new DateTimeImmutable((string) $o['start']))->setTimezone($ny)->format('Y-m-d H:i'), $o['title'], $o['tags'] ?? []];
        }
        sort($out);
        return $out;
    };
    // The day rows of a calendar: id => [key, the series' row above it, title, own list].
    $dayRows = static function (int $calendarId) use ($db): array {
        $out = [];
        foreach ($db->all('SELECT * FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NOT NULL AND deleted_at IS NULL ORDER BY id', [$calendarId]) as $r) {
            $out[(int) $r['id']] = [$r['recurrence_instance_utc'], $r['recurrence_parent_id'] === null ? null : (int) $r['recurrence_parent_id'], $r['title'], json_decode((string) $r['override_fields'], true)];
        }
        return $out;
    };
    $masterOf = static fn(int $calendarId): ?int => ($id = $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL', [$calendarId])) === null ? null : (int) $id;
    $lastPoll = static fn(int $u): string => (string) $db->scalar("SELECT summary FROM mutations WHERE user_id = ? AND entity = 'calendar' ORDER BY id DESC LIMIT 1", [$u]);
    $special = [['2027-01-06 20:00', 'Sample class, special', ['sample-day']]];
    $key = '2027-01-07 00:00:00'; // 19:00 New York on 6 January

    // ---- A Google calendar ---------------------------------------------------

    $zone = 'America/New_York';
    $google = static function (string $order = 'made') use ($db, $zone): object {
        $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
        $calendarAt = 'owner@example.test';
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $fake = new FakeGoogle($calendarAt);
        $fake->token = null;
        $fake->zone = $zone;
        $fake->order = $order;
        $inner = $fake->writerTransport();
        $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
            => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
        $feeds = new Feeds($db);
        $writer = new GoogleWriter($db, $auth, $feeds, static fn(string $method, string $url, ?array $payload, array $headers = []): array
            => $inner($method, $url, $payload, array_merge(['Authorization: Bearer sample-access'], $headers)));
        $syncTransport = $fake->syncTransport();
        $sync = new GoogleSync($db, $auth, $feeds, static fn(string $url, array $headers): array => $syncTransport($url, array_merge(['Authorization: Bearer sample-access'], $headers)));
        $events = new Events($db, new Recurrence(), $d->undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $d->undo, new BetterCal\Infra\JobQueue($db)), new Trips($db, $d->undo), null, $writer);
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $calendarId = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample work', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => $calendarAt, 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        return (object) ['u' => $u, 'events' => $events, 'fake' => $fake, 'calendarId' => $calendarId,
            'poll' => static fn() => $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]))];
    };
    // Google's record of 6 January: moved to 20:00 and renamed, or exactly as its series has the day.
    $record = static fn(bool $changed): array => ['id' => 'smplheld_20270107T000000Z', 'iCalUID' => 'smplheld@google.com', 'recurringEventId' => 'smplheld',
        'summary' => $changed ? 'Sample class, special' : 'Sample class', 'originalStartTime' => ['dateTime' => '2027-01-06T19:00:00-05:00', 'timeZone' => $zone],
        'start' => ['dateTime' => $changed ? '2027-01-06T20:00:00-05:00' : '2027-01-06T19:00:00-05:00', 'timeZone' => $zone],
        'end' => ['dateTime' => $changed ? '2027-01-06T21:00:00-05:00' : '2027-01-06T20:00:00-05:00', 'timeZone' => $zone]];
    $series = ['id' => 'smplheld', 'iCalUID' => 'smplheld@google.com', 'summary' => 'Sample class', 'recurrence' => ['RRULE:FREQ=DAILY;COUNT=10'],
        'start' => ['dateTime' => '2027-01-04T19:00:00-05:00', 'timeZone' => $zone], 'end' => ['dateTime' => '2027-01-04T20:00:00-05:00', 'timeZone' => $zone]];

    foreach (['made', 'reversed'] as $order) {
        scenario("day before series, Google (list order $order): a record of 6 January held before its series and tagged here, then the series arrives", function () use ($db, $google, $record, $series, $shownOn, $dayRows, $masterOf, $lastPoll, $special, $key, $order): void {
            $g = $google($order);
            $g->fake->seed($record(true));
            ($g->poll)();
            $row = $db->one('SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$g->calendarId]);
            checkEq("Google ($order): before, the record is held alone at its key, with no series above it", [(int) $row['id'] => [$key, null, 'Sample class, special', []]], $dayRows($g->calendarId));
            $g->events->patch($g->u, (int) $row['id'], ['tagNames' => ['sample-day']]);
            checkEq("Google ($order): tagged here, 6 January shows the record at 20:00 with its tag", $special, $shownOn($g->events, $g->u, $g->calendarId, '2027-01-06'));
            // The account is invited to the whole series.
            $g->fake->seed($series);
            ($g->poll)();
            checkEq("Google ($order): the series arrived, 5 January shows the class at 19:00", [['2027-01-05 19:00', 'Sample class', []]], $shownOn($g->events, $g->u, $g->calendarId, '2027-01-05'));
            checkEq("Google ($order): the series arrived, 6 January shows the record Google still holds, at 20:00, with the tag given here", $special, $shownOn($g->events, $g->u, $g->calendarId, '2027-01-06'));
            checkEq("Google ($order): the row is the row it was, now under its series, owning its time, its title and its tag",
                [(int) $row['id'] => [$key, $masterOf($g->calendarId), 'Sample class, special', ['time', 'title', 'tags']]], $dayRows($g->calendarId));
            checkEq("Google ($order): Activity says the series was added and nothing removed", [true, false], [str_contains($lastPoll($g->u), '1 added'), str_contains($lastPoll($g->u), 'removed')]);
            checkEq("Google ($order): Google still holds the record of 6 January, live", ['Sample class, special', 'confirmed'],
                [$g->fake->events['smplheld_20270107T000000Z']['summary'] ?? null, $g->fake->events['smplheld_20270107T000000Z']['status'] ?? null]);
            ($g->poll)();
            checkEq("Google ($order): one more poll, 6 January still shows the record at 20:00 with its tag", $special, $shownOn($g->events, $g->u, $g->calendarId, '2027-01-06'));
        });
    }

    scenario('day before series, Google: the series first and then its record of 6 January, tagged here (the control)', function () use ($db, $google, $record, $series, $shownOn, $dayRows, $masterOf, $special, $key): void {
        $g = $google();
        $g->fake->seed($series);
        ($g->poll)();
        $g->fake->seed($record(true));
        ($g->poll)();
        $row = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ?', [$g->calendarId, $key]);
        $g->events->patch($g->u, $row, ['tagNames' => ['sample-day']]);
        ($g->poll)();
        checkEq('Google, series first: 6 January shows the record at 20:00 with its tag', $special, $shownOn($g->events, $g->u, $g->calendarId, '2027-01-06'));
        checkEq('Google, series first: the row is under its series, owning its time, its title and its tag', [$row => [$key, $masterOf($g->calendarId), 'Sample class, special', ['time', 'title', 'tags']]], $dayRows($g->calendarId));
    });

    scenario('day before series, Google: a record of 6 January exactly as its series has the day, held before the series, then the series arrives', function () use ($db, $google, $record, $series, $shownOn, $dayRows, $masterOf, $lastPoll, $key): void {
        foreach (['tagged here' => true, 'with nothing given to it here' => false] as $what => $tagged) {
            $g = $google();
            $g->fake->seed($record(false));
            ($g->poll)();
            $row = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$g->calendarId]);
            if ($tagged) {
                $g->events->patch($g->u, $row, ['tagNames' => ['sample-day']]);
            }
            $g->fake->seed($series);
            ($g->poll)();
            checkEq("Google, a record equal to its series, $what: 6 January shows the class at 19:00" . ($tagged ? ' with its tag' : ', once'),
                [['2027-01-06 19:00', 'Sample class', $tagged ? ['sample-day'] : []]], $shownOn($g->events, $g->u, $g->calendarId, '2027-01-06'));
            checkEq("Google, a record equal to its series, $what: " . ($tagged ? 'the row stays under its series for its tag, owning nothing else' : 'it is an ordinary day again, no row, and Activity names no removed day'),
                $tagged ? [[$row => [$key, $masterOf($g->calendarId), 'Sample class', ['tags']]], false] : [[], false],
                [$dayRows($g->calendarId), str_contains($lastPoll($g->u), "'Sample class' on")]);
        }
    });

    // ---- An ICS feed ---------------------------------------------------------

    $cal = static fn(array $events, string $uid = 'sample-held@example.test'): string => implode("\r\n", array_merge(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sample//Feed//EN'],
        ...array_map(static fn(array $e): array => array_merge(['BEGIN:VEVENT', 'UID:' . $uid, 'DTSTAMP:20270101T000000Z'], $e, ['END:VEVENT']), $events))) . "\r\nEND:VCALENDAR\r\n";
    $icsRecord = ['RECURRENCE-ID;TZID=America/New_York:20270106T190000', 'DTSTART;TZID=America/New_York:20270106T200000', 'DTEND;TZID=America/New_York:20270106T210000', 'SUMMARY:Sample class, special'];
    $icsSeries = ['DTSTART;TZID=America/New_York:20270104T190000', 'DTEND;TZID=America/New_York:20270104T200000', 'RRULE:FREQ=DAILY;COUNT=10', 'SUMMARY:Sample class'];
    $feed = static function () use ($db): object {
        $u = freshUser($db);
        $d = domains($db);
        $calendarId = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample league', 'kind' => 'subscribed', 'color' => '#336699']);
        $feeds = new Feeds($db);
        return (object) ['u' => $u, 'events' => $d->events, 'calendarId' => $calendarId,
            'sync' => static fn(string $body) => $feeds->sync($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]), Ics::parse($body, 'America/New_York'))];
    };

    scenario('day before series, ICS feed: a record of 6 January listed before its series and tagged here, then the series listed too, then the record no longer listed', function () use ($db, $feed, $cal, $icsRecord, $icsSeries, $shownOn, $dayRows, $masterOf, $lastPoll, $special, $key): void {
        $f = $feed();
        ($f->sync)($cal([$icsRecord]));
        $row = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$f->calendarId]);
        $f->events->patch($f->u, $row, ['tagNames' => ['sample-day']]);
        checkEq('feed: before, the record is held alone, and 6 January shows it at 20:00 with its tag', [[$row => [$key, null, 'Sample class, special', []]], $special],
            [$dayRows($f->calendarId), $shownOn($f->events, $f->u, $f->calendarId, '2027-01-06')]);
        ($f->sync)($cal([$icsSeries, $icsRecord]));
        checkEq('feed: the series is listed too, 6 January shows the record alone, at 20:00, with the tag given here', $special, $shownOn($f->events, $f->u, $f->calendarId, '2027-01-06'));
        checkEq('feed: the row is the row it was, now under its series, owning its time, its title and its tag', [$row => [$key, $masterOf($f->calendarId), 'Sample class, special', ['time', 'title', 'tags']]], $dayRows($f->calendarId));
        checkEq('feed: Activity names no removed day', false, str_contains($lastPoll($f->u), 'removed'));
        ($f->sync)($cal([$icsSeries, $icsRecord]));
        checkEq('feed: read once more, 6 January still shows the record at 20:00 with its tag', $special, $shownOn($f->events, $f->u, $f->calendarId, '2027-01-06'));
        // The publisher puts the day back to the series: its record is no longer listed.
        ($f->sync)($cal([$icsSeries]));
        checkEq('feed: the record no longer listed, 6 January shows the class at 19:00 and keeps the tag given here', [['2027-01-06 19:00', 'Sample class', ['sample-day']]], $shownOn($f->events, $f->u, $f->calendarId, '2027-01-06'));
        checkEq('feed: the row stays for its tag, owning nothing else', [$row => [$key, $masterOf($f->calendarId), 'Sample class', ['tags']]], $dayRows($f->calendarId));
    });

    scenario('day before series, ICS feed: a record of 6 January held alone and tagged here, then the feed lists the series without it', function () use ($db, $feed, $cal, $icsRecord, $icsSeries, $shownOn, $dayRows, $masterOf, $key): void {
        foreach (['tagged here' => true, 'with nothing given to it here' => false] as $what => $tagged) {
            $f = $feed();
            ($f->sync)($cal([$icsRecord]));
            $row = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$f->calendarId]);
            if ($tagged) {
                $f->events->patch($f->u, $row, ['tagNames' => ['sample-day']]);
            }
            ($f->sync)($cal([$icsSeries]));
            checkEq("feed, the series without the record, the day $what: 6 January shows the class at 19:00" . ($tagged ? ' with its tag' : ', once'),
                [['2027-01-06 19:00', 'Sample class', $tagged ? ['sample-day'] : []]], $shownOn($f->events, $f->u, $f->calendarId, '2027-01-06'));
            checkEq("feed, the series without the record, the day $what: " . ($tagged ? 'the row is under its series for its tag' : 'no row is left'),
                $tagged ? [$row => [$key, $masterOf($f->calendarId), 'Sample class', ['tags']]] : [], $dayRows($f->calendarId));
        }
    });

    scenario('day before series, ICS feed: the series first and then its record of 6 January, tagged here (the control)', function () use ($db, $feed, $cal, $icsRecord, $icsSeries, $shownOn, $dayRows, $masterOf, $special, $key): void {
        $f = $feed();
        ($f->sync)($cal([$icsSeries]));
        ($f->sync)($cal([$icsSeries, $icsRecord]));
        $row = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ?', [$f->calendarId, $key]);
        $f->events->patch($f->u, $row, ['tagNames' => ['sample-day']]);
        ($f->sync)($cal([$icsSeries, $icsRecord]));
        checkEq('feed, series first: 6 January shows the record at 20:00 with its tag', $special, $shownOn($f->events, $f->u, $f->calendarId, '2027-01-06'));
        checkEq('feed, series first: the row is under its series, owning its time, its title and its tag', [$row => [$key, $masterOf($f->calendarId), 'Sample class, special', ['time', 'title', 'tags']]], $dayRows($f->calendarId));
    });

    // ---- A CalDAV PUT --------------------------------------------------------

    scenario('day before series, CalDAV: a record of 6 January imported alone and tagged here, then a client sends the series with it, then without it', function () use ($db, $cal, $icsRecord, $icsSeries, $shownOn, $dayRows, $masterOf, $special, $key): void {
        $u = freshUser($db);
        $d = domains($db);
        $token = (new BetterCal\Domain\ApiTokens($db))->create($u, 'Sample client');
        $backend = new BetterCal\Dav\CalendarBackend($db, new BetterCal\Domain\Undo($db), fn() => BetterCal\Domain\RequestAuthority::credential($u, (int) $token['id'], null));
        $calendarId = (int) BetterCal\Domain\Settings::defaultCalendarId($db, $u);
        (new BetterCal\Domain\CalendarImport($db, $d->calendars))->importIcs($u, $cal([$icsRecord], 'sample-held-file'), 'ignored', null, $calendarId);
        $row = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND uid = ? AND deleted_at IS NULL', [$calendarId, 'sample-held-file']);
        $d->events->patch($u, $row, ['tagNames' => ['sample-day']]);
        checkEq('PUT: before, the imported record is held alone at its key, with no series above it', [$key, null],
            [$db->scalar('SELECT recurrence_instance_utc FROM events WHERE id = ?', [$row]), $db->scalar('SELECT recurrence_parent_id FROM events WHERE id = ?', [$row])]);
        $backend->updateCalendarObject($calendarId, 'sample-held-file.ics', $cal([$icsSeries, $icsRecord], 'sample-held-file'));
        $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL', [$calendarId, 'sample-held-file']);
        checkEq('PUT: the series sent with the record, 6 January shows the record at 20:00 with the tag given here, and 5 January the class', [$special, [['2027-01-05 19:00', 'Sample class', []]]],
            [$shownOn($d->events, $u, $calendarId, '2027-01-06'), $shownOn($d->events, $u, $calendarId, '2027-01-05')]);
        checkEq('PUT: the row is the row it was, now under its series, owning its time, its title and its tag', [$row => [$key, $master, 'Sample class, special', ['time', 'title', 'tags']]],
            array_intersect_key($dayRows($calendarId), [$row => true]));
        $backend->updateCalendarObject($calendarId, 'sample-held-file.ics', $cal([$icsSeries], 'sample-held-file'));
        checkEq('PUT: the series sent without the record, 6 January shows the class at 19:00 and keeps its tag', [['2027-01-06 19:00', 'Sample class', ['sample-day']]], $shownOn($d->events, $u, $calendarId, '2027-01-06'));
        checkEq('PUT: the row stays for its tag, owning nothing else', [$row => [$key, $master, 'Sample class', ['tags']]], array_intersect_key($dayRows($calendarId), [$row => true]));
    });

    scenario('day before series, CalDAV: a record of 6 January imported alone and tagged here, then a client sends the series without it', function () use ($db, $cal, $icsRecord, $icsSeries, $shownOn, $dayRows, $key): void {
        $u = freshUser($db);
        $d = domains($db);
        $token = (new BetterCal\Domain\ApiTokens($db))->create($u, 'Sample client');
        $backend = new BetterCal\Dav\CalendarBackend($db, new BetterCal\Domain\Undo($db), fn() => BetterCal\Domain\RequestAuthority::credential($u, (int) $token['id'], null));
        $calendarId = (int) BetterCal\Domain\Settings::defaultCalendarId($db, $u);
        (new BetterCal\Domain\CalendarImport($db, $d->calendars))->importIcs($u, $cal([$icsRecord], 'sample-held-file-2'), 'ignored', null, $calendarId);
        $row = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND uid = ? AND deleted_at IS NULL', [$calendarId, 'sample-held-file-2']);
        $d->events->patch($u, $row, ['tagNames' => ['sample-day']]);
        $backend->updateCalendarObject($calendarId, 'sample-held-file-2.ics', $cal([$icsSeries], 'sample-held-file-2'));
        $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc IS NULL AND deleted_at IS NULL', [$calendarId, 'sample-held-file-2']);
        checkEq('PUT, the series without the record: 6 January shows the class at 19:00 with the tag given here', [['2027-01-06 19:00', 'Sample class', ['sample-day']]], $shownOn($d->events, $u, $calendarId, '2027-01-06'));
        checkEq('PUT, the series without the record: the row is under its series for its tag', [$row => [$key, $master, 'Sample class', ['tags']]], array_intersect_key($dayRows($calendarId), [$row => true]));
    });
};
