<?php

declare(strict_types=1);

// A series that changes form at the other end (timed made all-day, or
// all-day made timed) changes every key: a timed key is a UTC instant, an
// all-day key the date at 00:00:00 UTC. At UTC and east of it a timed
// occurrence at 00:00 UTC and the all-day day with the same key are one
// local day; west of UTC the timed one is the evening before (19:00 in New
// York on 6 January has the key of all-day 7 January). So the sync gives a
// record of the series in its new form to the row of the same day, and a
// row at an equal key that is another day follows its own day
// (workfolder/reviews/audit-155-identity.md, row 24).
//
// Every expectation here is written from that rule by hand: the keys and
// ids are worked out by this file's own two functions, the rows are read
// by SQL, and nothing in server/src decides what passes. Against the
// probe's stand-in Google (tools/google-probe), an ICS feed and a CalDAV
// client's PUT, invented data. The function alone, with no database, is
// tests/sync-standing.php.

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
    $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
    $calendarAt = 'flip@group.calendar.google.com';
    $base = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarAt) . '/events/';
    $utc = new DateTimeZone('UTC');

    // A daily series, ten days from Monday 4 January 2027: an hour at $clock
    // in $zone (timed), or the whole day (all-day).
    // The key of its day $d of January, in each form.
    $keyOf = static function (bool $allDay, int $d, string $zone, string $clock) use ($utc): string {
        return $allDay
            ? sprintf('2027-01-%02d 00:00:00', $d)
            : (new DateTimeImmutable(sprintf('2027-01-%02d %s', $d, $clock), new DateTimeZone($zone)))->setTimezone($utc)->format('Y-m-d H:i:s');
    };
    // Google's id for that day: the series id and the key in the series' form.
    $idOf = static fn(bool $allDay, string $key): string => 'smplflip_' . (new DateTimeImmutable($key, $utc))->format($allDay ? 'Ymd' : 'Ymd\THis\Z');
    $times = static function (bool $allDay, string $zone, string $clock): array {
        if ($allDay) {
            return ['start' => ['date' => '2027-01-04'], 'end' => ['date' => '2027-01-05']];
        }
        $start = new DateTimeImmutable('2027-01-04 ' . $clock, new DateTimeZone($zone));
        return ['start' => ['dateTime' => $start->format('Y-m-d\TH:i:sP'), 'timeZone' => $zone],
            'end' => ['dateTime' => $start->modify('+1 hour')->format('Y-m-d\TH:i:sP'), 'timeZone' => $zone]];
    };
    // What a day's row holds, read by SQL: its key, form, start, notes, reminders, own list and tags.
    $rowsOf = static function (int $masterId) use ($db): array {
        $out = [];
        foreach ($db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY recurrence_instance_utc', [$masterId]) as $r) {
            $out[(int) $r['id']] = [
                'key' => $r['recurrence_instance_utc'], 'allDay' => (int) $r['all_day'], 'start' => $r['start_utc'], 'notes' => $r['description'],
                'reminders' => $r['reminders_json'] === null ? null : json_encode(json_decode((string) $r['reminders_json'], true)), 'own' => $r['override_fields'] === null ? null : json_encode(json_decode((string) $r['override_fields'], true)),
                'tags' => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [(int) $r['id']]), 'name'),
            ];
        }
        return $out;
    };

    // The series at Google in form $allDay and stored here; four days marked
    // here: 5 January with notes of its own (a record at Google), 6 January
    // with a tag and 7 January with a reminder (held here alone, no record
    // at Google), 8 January with notes of its own.
    // ($marks, when given, marks the days instead: fn(Events, user, master id, fn(day): its instanceStart).)
    $setup = static function (bool $allDay, string $zone, string $clock, ?Closure $marks = null) use ($db, $cfg, $calendarAt, $times, $keyOf): object {
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $fake = new FakeGoogle($calendarAt);
        $fake->token = null;
        $fake->zone = $zone;
        $fake->seed(['id' => 'smplflip', 'iCalUID' => 'smplflip@google.com', 'summary' => 'Sample practice', 'description' => 'Sample notes',
            'recurrence' => ['RRULE:FREQ=DAILY;COUNT=10']] + $times($allDay, $zone, $clock));
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
        $calendarId = $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample club', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => $calendarAt, 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]));
        $masterId = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$calendarId, 'smplflip']);
        $at = static fn(int $day): string => str_replace(' ', 'T', $keyOf($allDay, $day, $zone, $clock)) . '+00:00';
        if ($marks !== null) {
            $marks($events, $u, $masterId, $at);
        } else {
            $events->patch($u, $masterId, ['scope' => 'this', 'instanceStart' => $at(5), 'description' => 'Notes for the fifth']);
            $events->patch($u, $masterId, ['scope' => 'this', 'instanceStart' => $at(6), 'tagNames' => ['sample-day']]);
            $events->patch($u, $masterId, ['scope' => 'this', 'instanceStart' => $at(7), 'reminders' => [['minutes' => 10]]]);
            $events->patch($u, $masterId, ['scope' => 'this', 'instanceStart' => $at(8), 'description' => 'Notes for the eighth']);
        }
        $idOn = [];
        foreach ([5, 6, 7, 8] as $day) {
            $idOn[$day] = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$masterId, $keyOf($allDay, $day, $zone, $clock)]);
        }
        return (object) ['u' => $u, 'fake' => $fake, 'sync' => $sync, 'feeds' => $feeds, 'calendarId' => $calendarId, 'masterId' => $masterId, 'idOn' => $idOn];
    };
    // A change made at Google, not through the app.
    $elsewhere = static function (object $s, string $id, array $body) use ($base): void {
        $answer = ($s->fake->writerTransport())('PATCH', $base . rawurlencode($id), $body, ['Authorization: Bearer sample-access']);
        if ((int) $answer['status'] !== 200) {
            throw new RuntimeException('the stand-in refused the change to ' . $id . ': ' . $answer['body']);
        }
    };

    // After the poll: the series in its new form from its first day, no
    // skipped day, no row left deleted, and no day row left without a key.
    $settled = static function (object $s, bool $allDay, string $firstKey) use ($db): void {
        checkEq('the series is in its new form here, with no skipped day, no row left deleted and no day row without a key', [(int) $allDay, $firstKey, null, 0, 0], [
            (int) $db->scalar('SELECT all_day FROM events WHERE id = ?', [$s->masterId]), $db->scalar('SELECT start_utc FROM events WHERE id = ?', [$s->masterId]),
            $db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$s->masterId]),
            (int) $db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND deleted_at IS NOT NULL', [$s->calendarId]),
            (int) $db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND recurrence_parent_id IS NOT NULL AND recurrence_instance_utc IS NULL', [$s->calendarId]),
        ]);
    };

    $zones = [
        'west of UTC (19:00 New York, whose key is the next day\'s date)' => ['America/New_York', '19:00'],
        'at UTC (00:00, the control: an equal key is the same day)' => ['UTC', '00:00'],
        'east of UTC (09:00 Tokyo, the control: an equal key is the same day)' => ['Asia/Tokyo', '09:00'],
    ];
    foreach ($zones as $where => [$zone, $clock]) {
        foreach (['timed made all-day' => true, 'all-day made timed' => false] as $direction => $toAllDay) {
            foreach (['a delta poll' => false, 'a whole read' => true] as $read => $whole) {
                // The form changes at Google and nothing else: Google drops
                // every record of the old form (the stand-in's master
                // change), so the notes of 5 and 8 January are gone there,
                // and those days hold nothing here alone.
                scenario("sync form match, $where, $direction at Google, $read: the tag and the reminder stay on their days, and no old-form row or skip is left",
                    function () use ($db, $setup, $elsewhere, $rowsOf, $settled, $keyOf, $times, $zone, $clock, $toAllDay, $whole): void {
                        $s = $setup(!$toAllDay, $zone, $clock);
                        $elsewhere($s, 'smplflip', $times($toAllDay, $zone, $clock));
                        if ($whole) {
                            $db->run('UPDATE calendars SET google_sync_token = NULL WHERE id = ?', [$s->calendarId]);
                        }
                        $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
                        $k = static fn(int $day): string => $keyOf($toAllDay, $day, $zone, $clock);
                        checkEq('6 January keeps its row and tag, 7 January its row and reminder, each at its own day\'s key in the new form; 5 and 8 January, whose notes Google dropped, are ordinary days', [
                            $s->idOn[6] => ['key' => $k(6), 'allDay' => (int) $toAllDay, 'start' => $k(6), 'notes' => 'Sample notes', 'reminders' => null, 'own' => '["tags"]', 'tags' => ['sample-day']],
                            $s->idOn[7] => ['key' => $k(7), 'allDay' => (int) $toAllDay, 'start' => $k(7), 'notes' => 'Sample notes', 'reminders' => '[{"minutes":10}]', 'own' => '["reminders"]', 'tags' => []],
                        ], $rowsOf($s->masterId));
                        $settled($s, $toAllDay, $k(4));
                    });

                // The form changes at Google and the notes of 5 and 8
                // January are held there in the new form (entered again on
                // those days after the change): two records of the new
                // series. West of UTC the record of all-day 8 January has
                // the key the row of 7 January had (19:00 on the 7th is
                // 00:00 UTC on the 8th), and made timed, the record of
                // 5 January has the key the all-day row of 6 January had.
                scenario("sync form match, $where, $direction at Google with two days' notes held there in the new form, $read: each record goes to its own day's row",
                    function () use ($db, $setup, $elsewhere, $rowsOf, $settled, $keyOf, $idOf, $times, $zone, $clock, $toAllDay, $whole): void {
                        $s = $setup(!$toAllDay, $zone, $clock);
                        $k = static fn(int $day): string => $keyOf($toAllDay, $day, $zone, $clock);
                        $elsewhere($s, 'smplflip', $times($toAllDay, $zone, $clock));
                        $elsewhere($s, $idOf($toAllDay, $k(5)), ['description' => 'Notes for the fifth']);
                        $elsewhere($s, $idOf($toAllDay, $k(8)), ['description' => 'Notes for the eighth']);
                        if ($whole) {
                            $db->run('UPDATE calendars SET google_sync_token = NULL WHERE id = ?', [$s->calendarId]);
                        }
                        $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
                        checkEq('each of the four days is the row it was, at its own day\'s key in the new form: the notes on 5 and 8 January, the tag on 6 January, the reminder on 7 January', [
                            $s->idOn[5] => ['key' => $k(5), 'allDay' => (int) $toAllDay, 'start' => $k(5), 'notes' => 'Notes for the fifth', 'reminders' => null, 'own' => '["description"]', 'tags' => []],
                            $s->idOn[6] => ['key' => $k(6), 'allDay' => (int) $toAllDay, 'start' => $k(6), 'notes' => 'Sample notes', 'reminders' => null, 'own' => '["tags"]', 'tags' => ['sample-day']],
                            $s->idOn[7] => ['key' => $k(7), 'allDay' => (int) $toAllDay, 'start' => $k(7), 'notes' => 'Sample notes', 'reminders' => '[{"minutes":10}]', 'own' => '["reminders"]', 'tags' => []],
                            $s->idOn[8] => ['key' => $k(8), 'allDay' => (int) $toAllDay, 'start' => $k(8), 'notes' => 'Notes for the eighth', 'reminders' => null, 'own' => '["description"]', 'tags' => []],
                        ], $rowsOf($s->masterId));
                        $settled($s, $toAllDay, $k(4));
                    });
            }
        }
    }

    // A skipped day stored in the old form, and a delta that lists the
    // series' new master and nothing else: no dropped item for the old
    // cancelled record (the stand-in always lists one, so this list is
    // built by hand, from the rows as the poll reads them and the master as
    // Google would send it, and merged and stored by the calls the poll
    // makes). West of UTC the old skip's key is the key of the neighbouring
    // day in the new form, the image of the tagged row: kept on the new
    // master it would hide that day and send the tagged row away as a day
    // the series no longer has. It is not kept (GoogleSync::applyChanges,
    // only the final master's form counts), so the tag stays on its day
    // and the day skipped under the old form is a day again, as it is at
    // Google, where a change of form drops every record.
    foreach (['timed made all-day, 6 January skipped and 7 January tagged' => [true, 6, 7], 'all-day made timed, 7 January skipped and 6 January tagged' => [false, 7, 6]] as $what => [$toAllDay, $skipped, $tagged]) {
        scenario("sync form match, west of UTC, $what, a delta that lists the new master alone: the old-form skip is not kept and the tagged day keeps its row",
            function () use ($db, $setup, $keyOf, $times, $toAllDay, $skipped, $tagged): void {
                $zone = 'America/New_York';
                $s = $setup(!$toAllDay, $zone, '19:00', static function (Events $events, int $u, int $masterId, Closure $at) use ($skipped, $tagged): void {
                    $events->deleteEvent($u, $masterId, 'this', $at($skipped));
                    $events->patch($u, $masterId, ['scope' => 'this', 'instanceStart' => $at($tagged), 'tagNames' => ['sample-day']]);
                });
                $was = static fn(int $day): string => $keyOf(!$toAllDay, $day, $zone, '19:00');
                $now = static fn(int $day): string => $keyOf($toAllDay, $day, $zone, '19:00');
                $row = static fn(): array => array_map(static fn(array $r): array => [(int) $r['id'], $r['recurrence_instance_utc'], (int) $r['all_day']],
                    $db->all('SELECT id, recurrence_instance_utc, all_day FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$s->masterId]));
                $skips = static fn(): array => json_decode((string) ($db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$s->masterId]) ?? '[]'), true);
                $id = (int) ($row()[0][0] ?? 0);
                checkEq('before: the day is skipped under its old-form key, which is the key the tagged day has in the new form, and the tagged day has its row',
                    [[$was($skipped)], $now($tagged), [[$id, $was($tagged), (int) !$toAllDay]]], [$skips(), $was($skipped), $row()]);
                $master = $times($toAllDay, $zone, '19:00') + $s->fake->events['smplflip'];
                $calendar = $db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]);
                $snapshot = GoogleSync::applyChanges(
                    GoogleSync::rowsToParsed($db->all('SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$s->calendarId])),
                    GoogleSync::toParsedList([$master]), null, null, true);
                $s->feeds->sync($calendar, $snapshot);
                checkEq('after: no skip is left, the series is in its new form, and the tagged day is the row it was, at its own day\'s key with its tag',
                    [[], (int) $toAllDay, [[$id, $now($tagged), (int) $toAllDay]], ['sample-day']],
                    [$skips(), (int) $db->scalar('SELECT all_day FROM events WHERE id = ?', [$s->masterId]), $row(),
                        array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ?', [$id]), 'name')]);
            });
    }

    // An ICS feed: the same coincidence between a timed RECURRENCE-ID and a
    // RECURRENCE-ID;VALUE=DATE. A daily series at 19:00 New York whose
    // 8 January has a title of its own in the feed, and whose 7 January was
    // given a tag here; the feed's series changes form with its record.
    $feed = static function () use ($db): object {
        $u = freshUser($db);
        $d = domains($db);
        $calendarId = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample league', 'kind' => 'subscribed', 'color' => '#336699']);
        $feeds = new Feeds($db);
        return (object) ['u' => $u, 'd' => $d, 'calendarId' => $calendarId,
            'sync' => static fn(string $body) => $feeds->sync($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]), Ics::parse($body, 'America/New_York'))];
    };
    $timedFeed = static fn(int $ownDay): string => implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sample//Feed//EN',
        'BEGIN:VEVENT', 'UID:sample-league@example.test', 'DTSTAMP:20270101T000000Z', 'DTSTART;TZID=America/New_York:20270104T190000', 'DURATION:PT1H',
        'RRULE:FREQ=DAILY;COUNT=10', 'SUMMARY:Sample game', 'END:VEVENT',
        'BEGIN:VEVENT', 'UID:sample-league@example.test', 'DTSTAMP:20270101T000000Z', sprintf('RECURRENCE-ID;TZID=America/New_York:202701%02dT190000', $ownDay),
        sprintf('DTSTART;TZID=America/New_York:202701%02dT190000', $ownDay), 'DURATION:PT1H', 'SUMMARY:Sample game, final', 'END:VEVENT',
        'END:VCALENDAR', '',
    ]);
    $dayFeed = static fn(int $ownDay): string => implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sample//Feed//EN',
        'BEGIN:VEVENT', 'UID:sample-league@example.test', 'DTSTAMP:20270101T000000Z', 'DTSTART;VALUE=DATE:20270104', 'DTEND;VALUE=DATE:20270105',
        'RRULE:FREQ=DAILY;COUNT=10', 'SUMMARY:Sample game', 'END:VEVENT',
        'BEGIN:VEVENT', 'UID:sample-league@example.test', 'DTSTAMP:20270101T000000Z', sprintf('RECURRENCE-ID;VALUE=DATE:202701%02d', $ownDay),
        sprintf('DTSTART;VALUE=DATE:202701%02d', $ownDay), sprintf('DTEND;VALUE=DATE:202701%02d', $ownDay + 1), 'SUMMARY:Sample game, final', 'END:VEVENT',
        'END:VCALENDAR', '',
    ]);
    $feedRows = static function (int $calendarId) use ($db): array {
        $out = [];
        foreach ($db->all('SELECT * FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NOT NULL AND deleted_at IS NULL ORDER BY recurrence_instance_utc', [$calendarId]) as $r) {
            $out[(int) $r['id']] = [$r['recurrence_instance_utc'], (int) $r['all_day'], $r['title'],
                array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [(int) $r['id']]), 'name')];
        }
        return $out;
    };

    scenario('sync form match, an ICS feed west of UTC made all-day with its record of 8 January: the record\'s row and the row holding a tag on 7 January each stay on their day', function () use ($db, $feed, $timedFeed, $dayFeed, $feedRows): void {
        $f = $feed();
        ($f->sync)($timedFeed(8));
        $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NULL', [$f->calendarId]);
        // 19:00 New York on 7 January is 00:00 UTC on the 8th, and on 8 January 00:00 UTC on the 9th.
        $f->d->events->patch($f->u, $master, ['scope' => 'this', 'instanceStart' => '2027-01-08T00:00:00+00:00', 'tagNames' => ['sample-day']]);
        $tagged = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ?', [$f->calendarId, '2027-01-08 00:00:00']);
        $final = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ?', [$f->calendarId, '2027-01-09 00:00:00']);
        checkEq('before: the tagged row of 7 January and the feed\'s record of 8 January, at their timed keys', [
            $tagged => ['2027-01-08 00:00:00', 0, 'Sample game', ['sample-day']], $final => ['2027-01-09 00:00:00', 0, 'Sample game, final', []],
        ], $feedRows($f->calendarId));
        ($f->sync)($dayFeed(8));
        checkEq('after: the same two rows on the same two days, as all-day days: the tag on 7 January, the record\'s title on 8 January', [
            $tagged => ['2027-01-07 00:00:00', 1, 'Sample game', ['sample-day']], $final => ['2027-01-08 00:00:00', 1, 'Sample game, final', []],
        ], $feedRows($f->calendarId));
    });

    scenario('sync form match, an all-day ICS feed made 19:00 New York with its record of 6 January: the record\'s row and the row holding a tag on 7 January each stay on their day', function () use ($db, $feed, $timedFeed, $dayFeed, $feedRows): void {
        $f = $feed();
        ($f->sync)($dayFeed(6));
        $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NULL', [$f->calendarId]);
        $f->d->events->patch($f->u, $master, ['scope' => 'this', 'instanceStart' => '2027-01-07T00:00:00+00:00', 'tagNames' => ['sample-day']]);
        $final = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ?', [$f->calendarId, '2027-01-06 00:00:00']);
        $tagged = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ?', [$f->calendarId, '2027-01-07 00:00:00']);
        checkEq('before: the feed\'s record of 6 January and the tagged row of 7 January, at their date keys', [
            $final => ['2027-01-06 00:00:00', 1, 'Sample game, final', []], $tagged => ['2027-01-07 00:00:00', 1, 'Sample game', ['sample-day']],
        ], $feedRows($f->calendarId));
        ($f->sync)($timedFeed(6));
        // 19:00 New York on 6 January is 00:00 UTC on the 7th: the key the all-day row of 7 January had.
        checkEq('after: the same two rows on the same two days, at 19:00: the record\'s title on 6 January, the tag on 7 January', [
            $final => ['2027-01-07 00:00:00', 0, 'Sample game, final', []], $tagged => ['2027-01-08 00:00:00', 0, 'Sample game', ['sample-day']],
        ], $feedRows($f->calendarId));
    });

    // Two rows whose day is one under the new form (two hours of an hourly
    // series made daily and all-day), with a record of that day in the
    // feed: the record is for the row with the lower id, and the other row
    // gives it what it holds here and goes, as any row does whose day
    // another row already holds.
    scenario('sync form match, an hourly ICS feed made all-day and daily with a record of its first day: the record goes to the first of that day\'s two tagged rows, which takes the other\'s tag', function () use ($db, $feed, $feedRows): void {
        $f = $feed();
        $hourly = implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sample//Feed//EN',
            'BEGIN:VEVENT', 'UID:sample-league@example.test', 'DTSTAMP:20270101T000000Z', 'DTSTART;TZID=America/New_York:20270104T100000', 'DURATION:PT30M',
            'RRULE:FREQ=HOURLY;COUNT=5', 'SUMMARY:Sample game', 'END:VEVENT', 'END:VCALENDAR', '']);
        ($f->sync)($hourly);
        $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NULL', [$f->calendarId]);
        // 11:00 and 12:00 in New York on 4 January are 16:00 and 17:00 UTC.
        $f->d->events->patch($f->u, $master, ['scope' => 'this', 'instanceStart' => '2027-01-04T16:00:00+00:00', 'tagNames' => ['sample-eleven']]);
        $f->d->events->patch($f->u, $master, ['scope' => 'this', 'instanceStart' => '2027-01-04T17:00:00+00:00', 'tagNames' => ['sample-twelve']]);
        $first = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ?', [$f->calendarId, '2027-01-04 16:00:00']);
        $second = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ?', [$f->calendarId, '2027-01-04 17:00:00']);
        ($f->sync)(implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sample//Feed//EN',
            'BEGIN:VEVENT', 'UID:sample-league@example.test', 'DTSTAMP:20270101T000000Z', 'DTSTART;VALUE=DATE:20270104', 'DTEND;VALUE=DATE:20270105',
            'RRULE:FREQ=DAILY;COUNT=5', 'SUMMARY:Sample game', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:sample-league@example.test', 'DTSTAMP:20270101T000000Z', 'RECURRENCE-ID;VALUE=DATE:20270104',
            'DTSTART;VALUE=DATE:20270104', 'DTEND;VALUE=DATE:20270105', 'SUMMARY:Sample game, final', 'END:VEVENT', 'END:VCALENDAR', '']));
        checkEq('one row for 4 January: the first of the two, with the record\'s title and both tags; the second is gone and no row is without a key', [
            [$first => ['2027-01-04 00:00:00', 1, 'Sample game, final', ['sample-eleven', 'sample-twelve']]], null, 0,
        ], [$feedRows($f->calendarId), $db->scalar('SELECT id FROM events WHERE id = ?', [$second]),
            (int) $db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND recurrence_parent_id IS NOT NULL AND recurrence_instance_utc IS NULL', [$f->calendarId])]);
    });

    // A CalDAV client's PUT matches its records to the stored rows too
    // (CalendarBackend::putObject), on a calendar of the account's own: the
    // client makes the series all-day (or timed) and sends each day it knows
    // again under the key of the new form. Each record is for its own day's
    // row, with the tag that day was given here.
    $client = static function () use ($db): object {
        $u = freshUser($db);
        $d = domains($db);
        $token = (new BetterCal\Domain\ApiTokens($db))->create($u, 'Sample client');
        $backend = new BetterCal\Dav\CalendarBackend($db, new BetterCal\Domain\Undo($db), fn() => BetterCal\Domain\RequestAuthority::credential($u, (int) $token['id'], null));
        $calendarId = (int) BetterCal\Domain\Settings::defaultCalendarId($db, $u);
        return (object) ['u' => $u, 'd' => $d, 'calendarId' => $calendarId,
            'put' => static fn(string $body) => $backend->updateCalendarObject($calendarId, 'sample-class-flip.ics', $body),
            'create' => static fn(string $body) => $backend->createCalendarObject($calendarId, 'sample-class-flip.ics', $body)];
    };
    // The object a client sends: the series in one form, and the days it
    // names (day of January => title), each under that form's key.
    $object = static function (bool $allDay, array $days): string {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sample//Client//EN', 'BEGIN:VEVENT', 'UID:sample-class-flip', 'DTSTAMP:20270101T000000Z',
            ...($allDay ? ['DTSTART;VALUE=DATE:20270104', 'DTEND;VALUE=DATE:20270105'] : ['DTSTART;TZID=America/New_York:20270104T190000', 'DTEND;TZID=America/New_York:20270104T200000']),
            'RRULE:FREQ=DAILY;COUNT=10', 'SUMMARY:Sample class', 'END:VEVENT'];
        foreach ($days as $day => $title) {
            $lines = [...$lines, 'BEGIN:VEVENT', 'UID:sample-class-flip', 'DTSTAMP:20270101T000000Z', ...($allDay
                ? [sprintf('RECURRENCE-ID;VALUE=DATE:202701%02d', $day), sprintf('DTSTART;VALUE=DATE:202701%02d', $day), sprintf('DTEND;VALUE=DATE:202701%02d', $day + 1)]
                : [sprintf('RECURRENCE-ID;TZID=America/New_York:202701%02dT190000', $day), sprintf('DTSTART;TZID=America/New_York:202701%02dT190000', $day), sprintf('DTEND;TZID=America/New_York:202701%02dT200000', $day)]),
                'SUMMARY:' . $title, 'END:VEVENT'];
        }
        return implode("\r\n", [...$lines, 'END:VCALENDAR', '']);
    };
    foreach (['a timed series made all-day' => true, 'an all-day series made 19:00 New York' => false] as $what => $toAllDay) {
        scenario("sync form match, a CalDAV PUT west of UTC, $what with its records of 7 and 8 January: each record is its own day's row, with that day's tag", function () use ($db, $client, $object, $feedRows, $keyOf, $toAllDay): void {
            $c = $client();
            $was = static fn(int $day): string => $keyOf(!$toAllDay, $day, 'America/New_York', '19:00');
            $now = static fn(int $day): string => $keyOf($toAllDay, $day, 'America/New_York', '19:00');
            ($c->create)($object(!$toAllDay, [7 => 'Sample class', 8 => 'Sample class, final']));
            $idOn = [];
            foreach ([7 => 'sample-seven', 8 => 'sample-eight'] as $day => $tag) {
                $idOn[$day] = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ?', [$c->calendarId, $was($day)]);
                $c->d->events->patch($c->u, $idOn[$day], ['tagNames' => [$tag]]);
            }
            checkEq('before: the two days\' rows at the keys of the form they were sent in, each with its tag', [
                $idOn[7] => [$was(7), (int) !$toAllDay, 'Sample class', ['sample-seven']], $idOn[8] => [$was(8), (int) !$toAllDay, 'Sample class, final', ['sample-eight']],
            ], $feedRows($c->calendarId));
            ($c->put)($object($toAllDay, [7 => 'Sample class', 8 => 'Sample class, final']));
            checkEq('after: the same two rows on the same two days at the keys of the new form, each with its own title and tag', [
                $idOn[7] => [$now(7), (int) $toAllDay, 'Sample class', ['sample-seven']], $idOn[8] => [$now(8), (int) $toAllDay, 'Sample class, final', ['sample-eight']],
            ], $feedRows($c->calendarId));
            checkEq('no row is left without a key', 0, (int) $db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND recurrence_parent_id IS NOT NULL AND recurrence_instance_utc IS NULL', [$c->calendarId]));
            $c->d->undo->undoById($c->u, lastEntry($db, $c->u));
            checkEq('the PUT undone: the same two rows back at the keys they had, each with its title and tag', [
                $idOn[7] => [$was(7), (int) !$toAllDay, 'Sample class', ['sample-seven']], $idOn[8] => [$was(8), (int) !$toAllDay, 'Sample class, final', ['sample-eight']],
            ], $feedRows($c->calendarId));
        });
    }

    scenario('sync form match, a CalDAV PUT that keeps the series\' form: its records are matched as they were, a day it leaves out goes', function () use ($db, $client, $object, $feedRows, $keyOf): void {
        $c = $client();
        $key = static fn(int $day): string => $keyOf(false, $day, 'America/New_York', '19:00');
        ($c->create)($object(false, [7 => 'Sample class', 8 => 'Sample class, final']));
        $seven = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ?', [$c->calendarId, $key(7)]);
        $eight = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ?', [$c->calendarId, $key(8)]);
        $c->d->events->patch($c->u, $eight, ['tagNames' => ['sample-eight']]);
        ($c->put)($object(false, [8 => 'Sample class, last']));
        checkEq('the record of 8 January is the row it was, renamed, with its tag; the row of 7 January, which the PUT left out, is gone', [
            $eight => [$key(8), 0, 'Sample class, last', ['sample-eight']],
        ], $feedRows($c->calendarId));
        checkEq('and the row left out is gone whole', null, $db->scalar('SELECT id FROM events WHERE id = ?', [$seven]));
    });

    scenario('sync form match, an ICS feed read again with no change of form: the record and the tagged row are matched as they were, and nothing is written', function () use ($db, $feed, $timedFeed, $feedRows): void {
        $f = $feed();
        ($f->sync)($timedFeed(8));
        $master = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NULL', [$f->calendarId]);
        $f->d->events->patch($f->u, $master, ['scope' => 'this', 'instanceStart' => '2027-01-08T00:00:00+00:00', 'tagNames' => ['sample-day']]);
        $before = $db->all('SELECT * FROM events WHERE calendar_id = ? ORDER BY id', [$f->calendarId]);
        $entries = (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$f->u]);
        $count = ($f->sync)($timedFeed(8));
        checkEq('the same rows, column for column, no row counted as changed and no entry', [$before, 0, 0],
            [$db->all('SELECT * FROM events WHERE calendar_id = ? ORDER BY id', [$f->calendarId]), $count, (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$f->u]) - $entries]);
    });
};
