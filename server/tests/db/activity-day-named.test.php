<?php

declare(strict_types=1);

// The date a poll's Activity entry names for a day it removed (a removal
// is named by the event and the date it showed, docs/google-calendar.md).
// The rule: a key names a day under its series' form and zone, never the
// row's own; a day whose own form is not its series' (a timed day of an
// all-day series, a whole day of a timed series) is still the day its
// series gives the key (Feeds::droppedDay, told the series).
//
// Expectations by calendar date, written by hand (the round's hunt,
// 2026-11-10). Invented data.

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
    $cal = static fn(array $events): string => implode("\r\n", array_merge(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sample//Feed//EN'],
        ...array_map(static fn(array $e): array => array_merge(['BEGIN:VEVENT', 'UID:sample-named@example.test', 'DTSTAMP:20270101T000000Z'], $e, ['END:VEVENT']), $events))) . "\r\nEND:VCALENDAR\r\n";
    $lastPoll = static function (int $u) use ($db): array {
        $m = $db->one("SELECT summary, details_json FROM mutations WHERE user_id = ? AND entity = 'calendar' ORDER BY id DESC LIMIT 1", [$u]);
        $details = json_decode((string) ($m['details_json'] ?? '{}'), true) ?: [];
        return [(string) ($m['summary'] ?? ''), array_map(static fn(array $d): array => [$d['title'], $d['day'], $d['why']], (array) ($details['removedDays'] ?? []))];
    };

    scenario('activity day named: ICS feed: an all-day series whose 6 January is at 14:00 New York, tagged here, then skipped by the feed: Activity names 6 January', function () use ($db, $cal, $lastPoll): void {
        $u = freshUser($db);
        $d = domains($db);
        $calendarId = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample camp', 'kind' => 'subscribed', 'color' => '#336699']);
        $feeds = new Feeds($db);
        $sync = static fn(string $body) => $feeds->sync($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]), Ics::parse($body, 'America/New_York'));
        $series = ['DTSTART;VALUE=DATE:20270104', 'DTEND;VALUE=DATE:20270105', 'RRULE:FREQ=DAILY;COUNT=10', 'SUMMARY:Sample camp'];
        $sync($cal([$series, ['RECURRENCE-ID;VALUE=DATE:20270106', 'DTSTART;TZID=America/New_York:20270106T140000', 'DTEND;TZID=America/New_York:20270106T150000', 'SUMMARY:Sample camp, afternoon']]));
        $row = $db->one('SELECT * FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NOT NULL', [$calendarId]);
        checkEq('the record of 6 January is stored at the key of 6 January, at 14:00 New York (19:00 UTC)', ['2027-01-06 00:00:00', '2027-01-06 19:00:00', 0], [$row['recurrence_instance_utc'] ?? null, $row['start_utc'] ?? null, (int) ($row['all_day'] ?? -1)]);
        $d->events->patch($u, (int) $row['id'], ['tagNames' => ['sample-day']]);
        // The feed skips 6 January and no longer carries its record.
        $sync($cal([array_merge($series, ['EXDATE;VALUE=DATE:20270106'])]));
        [$summary, $days] = $lastPoll($u);
        checkEq('the row is gone, and Activity names it by 6 January, the date it showed', [null, [['Sample camp, afternoon', '2027-01-06', 'gone']]],
            [$db->scalar('SELECT id FROM events WHERE id = ?', [(int) $row['id']]), $days]);
    });

    scenario('activity day named: Google: a 19:00 New York series whose 6 January Google holds as a whole day, tagged here, then cancelled at Google: Activity names 6 January', function () use ($db, $lastPoll): void {
        $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
        $calendarAt = 'named@group.calendar.google.com';
        $base = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarAt) . '/events/';
        $zone = 'America/New_York';
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $fake = new FakeGoogle($calendarAt);
        $fake->token = null;
        $fake->zone = $zone;
        $fake->seed(['id' => 'smplnamed', 'iCalUID' => 'smplnamed@google.com', 'summary' => 'Sample game', 'recurrence' => ['RRULE:FREQ=DAILY;COUNT=10'],
            'start' => ['dateTime' => '2027-01-04T19:00:00-05:00', 'timeZone' => $zone], 'end' => ['dateTime' => '2027-01-04T20:00:00-05:00', 'timeZone' => $zone]]);
        // Made a whole day at Google ("This event", All day): 19:00 New York on the 6th is 00:00 UTC on the 7th.
        $fake->seed(['id' => 'smplnamed_20270107T000000Z', 'iCalUID' => 'smplnamed@google.com', 'summary' => 'Sample game', 'recurringEventId' => 'smplnamed',
            'originalStartTime' => ['dateTime' => '2027-01-06T19:00:00-05:00', 'timeZone' => $zone], 'start' => ['date' => '2027-01-06'], 'end' => ['date' => '2027-01-07']]);
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
        $poll = static fn() => $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]));
        $poll();
        $row = $db->one('SELECT * FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NOT NULL AND deleted_at IS NULL', [$calendarId]);
        checkEq('the whole day of 6 January is stored at the key of 19:00 on the 6th, showing on the 6th', ['2027-01-07 00:00:00', '2027-01-06 00:00:00', 1],
            [$row['recurrence_instance_utc'] ?? null, $row['start_utc'] ?? null, (int) ($row['all_day'] ?? -1)]);
        $events->patch($u, (int) $row['id'], ['tagNames' => ['sample-day']]);
        // Cancelled at Google by another client.
        $answer = ($fake->writerTransport())('PATCH', $base . rawurlencode('smplnamed_20270107T000000Z'), ['status' => 'cancelled'], ['Authorization: Bearer sample-access']);
        check('the stand-in took the cancellation', (int) $answer['status'] === 200, (string) $answer['body']);
        $poll();
        [$summary, $days] = $lastPoll($u);
        checkEq('the row is gone, and Activity names it by 6 January, the date it showed', [null, [['Sample game', '2027-01-06', 'gone']]],
            [$db->scalar('SELECT id FROM events WHERE id = ?', [(int) $row['id']]), $days]);
    });
};
