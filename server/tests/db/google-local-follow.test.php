<?php

declare(strict_types=1);

// A day of a series given only things held here (a tag) is a row of its
// series (Feeds::sync, Feeds::ofSeries): when the series changes at the
// other end, the row exists only while the series still has its day, moves
// with the day when the series' clock time moves, and takes every field it
// doesn't own from the series, its tags kept. Google, under a whole read
// and a delta read alike, and an ICS feed. Against the probe's stand-in
// Google (tools/google-probe), invented data.

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
use BetterCal\Support\Time;

require_once dirname(__DIR__, 3) . '/tools/google-probe/lib/FakeGoogle.php';

return static function (Db $db): void {
    $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
    $tz = 'America/Los_Angeles';
    $la = new DateTimeZone($tz);
    $when = static fn(string $local): array => ['dateTime' => (new DateTimeImmutable($local, $la))->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz];
    $expand = static function (array $master): array {
        $zone = (string) ($master['start']['timeZone'] ?? 'UTC');
        $start = new DateTimeImmutable((string) $master['start']['dateTime']);
        $end = new DateTimeImmutable((string) $master['end']['dateTime']);
        $rrule = null;
        foreach ((array) ($master['recurrence'] ?? []) as $line) {
            if (str_starts_with((string) $line, 'RRULE:')) {
                $rrule = substr((string) $line, 6);
            }
        }
        if ($rrule === null) {
            return [];
        }
        $row = ['start_utc' => Time::toDb($start), 'end_utc' => Time::toDb($end), 'all_day' => 0, 'tzid' => $zone, 'rrule' => $rrule];
        return array_map(static fn(array $o): DateTimeImmutable => $o['start']->setTimezone(new DateTimeZone($zone)),
            Recurrence::occurrences($row, $start, $start->modify('+2 years'), null, 730));
    };

    // Mondays 10:00 Los Angeles from 1 March 2027, ten of them; 8 March is
    // given a tag here, which sends Google nothing.
    $setup = static function () use ($db, $cfg, $expand, $when): object {
        $u = freshUser($db);
        $d = domains($db);
        $fake = new FakeGoogle('follow@group.calendar.google.com');
        $fake->token = null;
        $fake->zone = 'America/Los_Angeles';
        $fake->expand = $expand;
        $fake->seed(['id' => 'smpl5weekly', 'iCalUID' => 'smpl5weekly@google.com', 'summary' => 'Sample practice', 'description' => 'Sample notes', 'location' => 'Sample hall 1',
            'start' => $when('2027-03-01 10:00'), 'end' => $when('2027-03-01 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=10']]);
        $inner = $fake->writerTransport();
        $calls = new ArrayObject();
        $transport = static function (string $method, string $url, ?array $payload, array $headers = []) use ($calls, $inner): array {
            $calls[] = $method;
            return $inner($method, $url, $payload, array_merge(['Authorization: Bearer sample-access'], $headers));
        };
        $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
            => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
        $feeds = new Feeds($db);
        $writer = new GoogleWriter($db, $auth, $feeds, $transport);
        $syncTransport = $fake->syncTransport();
        $sync = new GoogleSync($db, $auth, $feeds, static fn(string $url, array $headers): array => $syncTransport($url, array_merge(['Authorization: Bearer sample-access'], $headers)));
        $events = new Events($db, new Recurrence(), $d->undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $d->undo, new BetterCal\Infra\JobQueue($db)), new Trips($db, $d->undo), null, $writer);
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $calendarId = $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample club', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'follow@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]));
        $masterId = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$calendarId, 'smpl5weekly']);
        $events->patch($u, $masterId, ['scope' => 'this', 'instanceStart' => '2027-03-08T18:00:00+00:00', 'tagNames' => ['sample-day']]);
        $calls->exchangeArray([]);
        return (object) ['u' => $u, 'fake' => $fake, 'calls' => $calls, 'sync' => $sync, 'events' => $events, 'calendarId' => $calendarId, 'masterId' => $masterId];
    };
    // A change made at Google (not through the app), then a poll: a whole
    // read (no sync token) or a delta.
    $remote = static function (object $s, array $body, bool $whole) use ($db): void {
        $answer = ($s->fake->writerTransport())('PATCH', 'https://www.googleapis.com/calendar/v3/calendars/follow%40group.calendar.google.com/events/smpl5weekly', $body, ['Authorization: Bearer sample-access']);
        if ((int) $answer['status'] !== 200) {
            throw new RuntimeException('the stand-in refused the change: ' . $answer['body']);
        }
        if ($whole) {
            $db->run('UPDATE calendars SET google_sync_token = NULL WHERE id = ?', [$s->calendarId]);
        }
        $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
    };
    $children = static fn(object $s): array => $db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$s->masterId]);
    $tags = static fn(int $id): array => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [$id]), 'name');
    $feedEntry = static fn(object $s): ?string => $db->scalar("SELECT summary FROM mutations WHERE user_id = ? AND entity = 'calendar' ORDER BY id DESC LIMIT 1", [$s->u]);
    $shownOn = static function (object $s, string $day) use ($tz): array {
        $from = new DateTimeImmutable($day . ' 00:00', new DateTimeZone($tz));
        return array_map(static fn(array $o): array => [$o['start'], $o['isException'], $o['tags'] ?? []],
            $s->events->window($s->u, $from, $from->modify('+1 day'), [$s->calendarId], null, true));
    };

    foreach (['a whole read' => true, 'a delta read' => false] as $read => $whole) {
        scenario("google local follow, $read: the series given a COUNT that ends it before the tagged day removes the day's row and its tag, and Activity says so", function () use ($setup, $remote, $children, $tags, $feedEntry, $shownOn, $whole, $db): void {
            $s = $setup();
            $id = (int) $children($s)[0]['id'];
            $remote($s, ['recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=1']], $whole);
            checkEq('the series ends after one; no row for 8 March, no tag left, nothing shown that day, and Google sent nothing from here', [
                'FREQ=WEEKLY;COUNT=1', [], [], [], [],
            ], [$db->scalar('SELECT rrule FROM events WHERE id = ?', [$s->masterId]), $children($s), $tags($id), $shownOn($s, '2027-03-08'), $s->calls->getArrayCopy()]);
            checkEq('Activity names the day and its tags as removed by the poll', true,
                str_contains((string) $feedEntry($s), "removed ('Sample practice' on 2027-03-08 with its tags: its series no longer has that day)"));
        });

        scenario("google local follow, $read: an UNTIL before the tagged day removes its row; one after it keeps the row", function () use ($setup, $remote, $children, $tags, $whole): void {
            $s = $setup();
            $id = (int) $children($s)[0]['id'];
            $remote($s, ['recurrence' => ['RRULE:FREQ=WEEKLY;UNTIL=20270320T000000Z']], $whole);
            checkEq('an UNTIL after 8 March: the row and its tag stay', [[$id], ['sample-day']], [array_map('intval', array_column($children($s), 'id')), $tags($id)]);
            $remote($s, ['recurrence' => ['RRULE:FREQ=WEEKLY;UNTIL=20270305T000000Z']], $whole);
            checkEq('an UNTIL before it: the row and its tag are gone', [[], []], [$children($s), $tags($id)]);
        });

        scenario("google local follow, $read: a new rule that still has the tagged day keeps its row; one that hasn't removes it", function () use ($setup, $remote, $children, $tags, $whole): void {
            $s = $setup();
            $id = (int) $children($s)[0]['id'];
            $remote($s, ['recurrence' => ['RRULE:FREQ=DAILY;COUNT=30']], $whole);
            checkEq('every day: 8 March is still a day of it, the row, its key and tag kept', [[[$id, '2027-03-08 18:00:00']], ['sample-day']],
                [array_map(static fn(array $r): array => [(int) $r['id'], $r['recurrence_instance_utc']], $children($s)), $tags($id)]);
            $remote($s, ['recurrence' => ['RRULE:FREQ=WEEKLY;INTERVAL=2;COUNT=5']], $whole);
            checkEq('every other Monday from 1 March: 8 March is no day of it, the row and tag gone', [[], []], [$children($s), $tags($id)]);
        });

        scenario("google local follow, $read: the series at a new clock time moves the tagged day's row with it, its tag kept", function () use ($setup, $remote, $children, $tags, $whole, $when): void {
            $s = $setup();
            $id = (int) $children($s)[0]['id'];
            $remote($s, ['start' => $when('2027-03-01 11:30'), 'end' => $when('2027-03-01 12:00')], $whole);
            $row = $children($s)[0] ?? [];
            checkEq('the same row at 11:30 on 8 March, the series\' new length, under the occurrence\'s new instance id, with its tag and only the tag its own', [
                $id, '2027-03-08 19:30:00', '2027-03-08 19:30:00', '2027-03-08 20:00:00', 'smpl5weekly_20270308T193000Z', ['sample-day'], '["tags"]',
            ], [(int) ($row['id'] ?? 0), $row['recurrence_instance_utc'] ?? null, $row['start_utc'] ?? null, $row['end_utc'] ?? null, $row['google_event_id'] ?? null, $tags($id), $row['override_fields'] ?? null]);
        });

        scenario("google local follow, $read: a new title, place and notes for the series reach the tagged day, its tag kept", function () use ($setup, $remote, $children, $tags, $whole, $shownOn): void {
            $s = $setup();
            $id = (int) $children($s)[0]['id'];
            $remote($s, ['summary' => 'Sample practice, renamed', 'location' => 'Sample hall 2', 'description' => 'Sample notes, new'], $whole);
            $row = $children($s)[0] ?? [];
            checkEq('the row has the series\' new title, place and notes, its tag, and owns only the tag', [
                $id, 'Sample practice, renamed', 'Sample hall 2', 'Sample notes, new', ['sample-day'], '["tags"]',
            ], [(int) ($row['id'] ?? 0), $row['title'] ?? null, $row['location'] ?? null, $row['description'] ?? null, $tags($id), $row['override_fields'] ?? null]);
            checkEq('shown on 8 March as an edited day with its tag', [['2027-03-08T10:00:00-08:00', true, ['sample-day']]], $shownOn($s, '2027-03-08'));
        });
    }

    scenario('google local follow: a poll of an unchanged series leaves the tagged day\'s row as it was, and records nothing', function () use ($db, $setup, $children): void {
        $s = $setup();
        $before = $children($s);
        $entries = (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$s->u]);
        $db->run('UPDATE calendars SET google_sync_token = NULL WHERE id = ?', [$s->calendarId]);
        $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
        checkEq('the same row, unchanged, and no entry', [$before, 0], [$children($s), (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$s->u]) - $entries]);
    });

    // An ICS feed: a day given a tag here is a row the person made; it
    // follows its series the same way.
    scenario('feed local follow: an ICS feed\'s series renamed, then shortened, renames and then removes the tagged day\'s row', function () use ($db): void {
        $u = freshUser($db);
        $d = domains($db);
        $calendarId = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample league', 'kind' => 'subscribed', 'color' => '#336699']);
        $feeds = new Feeds($db);
        $calendar = $db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]);
        $ics = static fn(string $title, string $rule, string $place = 'Sample field', string $start = '20270301T100000'): string => implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sample//Feed//EN', 'BEGIN:VEVENT', 'UID:sample-league@example.test', 'DTSTAMP:20270101T000000Z',
            'DTSTART;TZID=America/Los_Angeles:' . $start, 'DURATION:PT1H', 'RRULE:' . $rule, 'SUMMARY:' . $title, 'LOCATION:' . $place, 'END:VEVENT', 'END:VCALENDAR', '',
        ]);
        $feeds->sync($calendar, Ics::parse($ics('Sample game', 'FREQ=WEEKLY;COUNT=10')));
        $masterId = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NULL', [$calendarId]);
        $d->events->patch($u, $masterId, ['scope' => 'this', 'instanceStart' => '2027-03-08T18:00:00+00:00', 'tagNames' => ['sample-day']]);
        $row = static fn(): ?array => $db->one('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$masterId]);
        $tags = static fn(int $id): array => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [$id]), 'name');
        $id = (int) ($row()['id'] ?? 0);
        checkEq('the tagged day has a row', [true, ['sample-day']], [$id > 0, $tags($id)]);
        $feeds->sync($calendar, Ics::parse($ics('Sample game, renamed', 'FREQ=WEEKLY;COUNT=10', 'Sample park')));
        checkEq('renamed and moved to another place: the row has both, and its tag', [$id, 'Sample game, renamed', 'Sample park', ['sample-day']],
            [(int) ($row()['id'] ?? 0), $row()['title'] ?? null, $row()['location'] ?? null, $tags($id)]);
        $feeds->sync($calendar, Ics::parse($ics('Sample game, renamed', 'FREQ=WEEKLY;COUNT=10', 'Sample park', '20270301T090000')));
        checkEq('an hour earlier: the row moved with the day', ['2027-03-08 17:00:00', '2027-03-08 17:00:00', ['sample-day']],
            [$row()['recurrence_instance_utc'] ?? null, $row()['start_utc'] ?? null, $tags($id)]);
        $feeds->sync($calendar, Ics::parse($ics('Sample game, renamed', 'FREQ=WEEKLY;COUNT=1', 'Sample park', '20270301T090000')));
        checkEq('shortened to one: no row, no tag, and the feed\'s Activity entry names the day', [null, [], true],
            [$row(), $tags($id), str_contains((string) $db->scalar("SELECT summary FROM mutations WHERE user_id = ? AND entity = 'calendar' ORDER BY id DESC LIMIT 1", [$u]),
                "'Sample game, renamed' on 2027-03-08 with its tags: its series no longer has that day")]);
    });
};
