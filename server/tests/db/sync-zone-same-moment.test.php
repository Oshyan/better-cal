<?php

declare(strict_types=1);

// A series whose zone changes at the other end with no instant moving
// (19:00 New York rewritten as 00:00 UTC of the next date, or as 09:00
// Tokyo). Every occurrence is the same moment and, at Google, the same
// instance (series, form and key all unchanged). The rule: a row whose key
// is still an occurrence of its series, in the same form, is that
// occurrence, whatever local date the key had under the old zone; only a
// key that is no longer one is carried to the series' occurrence on the day
// it showed (Feeds::ofSeries: by identity first, by the day second). So a
// day that holds something here alone (a tag) stays on its own occurrence.
//
// The tagged occurrence is the game of Wednesday 6 January 2027 at 19:00 in
// New York, the instant 2027-01-07T00:00:00Z. Expectations are that
// instant's, written by hand (the round's hunt, 2026-11-10). Invented data.

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
    // The instants (UTC) of the occurrences of 5 to 7 January New York time that carry a tag, by SQL and by the window.
    $taggedRows = static fn(int $calendarId): array => array_map(static fn(array $r): array => [$r['recurrence_instance_utc'], $r['start_utc'], $r['google_event_id']],
        $db->all('SELECT e.recurrence_instance_utc, e.start_utc, e.google_event_id FROM events e JOIN event_tags et ON et.event_id = e.id
            WHERE e.calendar_id = ? AND e.deleted_at IS NULL AND e.recurrence_instance_utc IS NOT NULL ORDER BY e.recurrence_instance_utc', [$calendarId]));
    $taggedShown = static function (Events $events, int $u, int $calendarId): array {
        $out = [];
        foreach ($events->window($u, new DateTimeImmutable('2027-01-05T12:00:00Z'), new DateTimeImmutable('2027-01-08T12:00:00Z'), [$calendarId], null, true) as $o) {
            if (($o['tags'] ?? []) !== []) {
                $out[] = (new DateTimeImmutable((string) $o['start']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i\Z');
            }
        }
        return $out;
    };

    $rewrites = [
        '00:00 UTC' => ['dateTime' => '2027-01-05T00:00:00Z', 'timeZone' => 'UTC', 'end' => '2027-01-05T01:00:00Z'],
        '09:00 Tokyo' => ['dateTime' => '2027-01-05T09:00:00+09:00', 'timeZone' => 'Asia/Tokyo', 'end' => '2027-01-05T10:00:00+09:00'],
    ];
    foreach ($rewrites as $what => $to) {
        foreach (['kept', 'dropped'] as $zoneAlone) {
            scenario("sync zone same moment: Google: a daily 19:00 New York series rewritten at Google as $what, no instant moving (records on a zone change: $zoneAlone): the tag stays on the game of 6 January 19:00 New York", function () use ($db, $taggedRows, $taggedShown, $to, $zoneAlone): void {
                $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
                $calendarAt = 'moment@group.calendar.google.com';
                $base = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarAt) . '/events/';
                $u = freshUser($db);
                seriesUndo($db, $u, false);
                $d = domains($db);
                $fake = new FakeGoogle($calendarAt);
                $fake->token = null;
                $fake->zone = 'America/New_York';
                $fake->zoneAlone = $zoneAlone;
                $fake->seed(['id' => 'smplmoment', 'iCalUID' => 'smplmoment@google.com', 'summary' => 'Sample game', 'recurrence' => ['RRULE:FREQ=DAILY;COUNT=10'],
                    'start' => ['dateTime' => '2027-01-04T19:00:00-05:00', 'timeZone' => 'America/New_York'], 'end' => ['dateTime' => '2027-01-04T20:00:00-05:00', 'timeZone' => 'America/New_York']]);
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
                $masterId = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ?', [$calendarId, 'smplmoment']);
                $events->patch($u, $masterId, ['scope' => 'this', 'instanceStart' => '2027-01-07T00:00:00+00:00', 'tagNames' => ['sample-day']]);
                checkEq('before: the tag is on the occurrence at 2027-01-07T00:00Z, whose instance at Google is smplmoment_20270107T000000Z',
                    [[['2027-01-07 00:00:00', '2027-01-07 00:00:00', 'smplmoment_20270107T000000Z']], ['2027-01-07T00:00Z']], [$taggedRows($calendarId), $taggedShown($events, $u, $calendarId)]);
                // Another client rewrites the series' start in another zone at the same instant.
                $answer = ($fake->writerTransport())('PATCH', $base . 'smplmoment', ['start' => ['dateTime' => $to['dateTime'], 'timeZone' => $to['timeZone']],
                    'end' => ['dateTime' => $to['end'], 'timeZone' => $to['timeZone']]], ['Authorization: Bearer sample-access']);
                check('the stand-in took the change', (int) $answer['status'] === 200, (string) $answer['body']);
                $poll();
                checkEq('the series here has the new zone and the same first instant', [$to['timeZone'], '2027-01-05 00:00:00'],
                    [$db->scalar('SELECT tzid FROM events WHERE id = ?', [$masterId]), $db->scalar('SELECT start_utc FROM events WHERE id = ?', [$masterId])]);
                checkEq('after: the tag is still on the occurrence at 2027-01-07T00:00Z, the same instance at Google',
                    [[['2027-01-07 00:00:00', '2027-01-07 00:00:00', 'smplmoment_20270107T000000Z']], ['2027-01-07T00:00Z']], [$taggedRows($calendarId), $taggedShown($events, $u, $calendarId)]);
            });
        }
    }

    scenario('sync zone same moment: ICS feed: a daily 19:00 New York series republished in UTC at the same instants: the tag stays on the game of 6 January 19:00 New York', function () use ($db, $taggedRows, $taggedShown): void {
        $u = freshUser($db);
        $d = domains($db);
        $calendarId = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample league', 'kind' => 'subscribed', 'color' => '#336699']);
        $feeds = new Feeds($db);
        $sync = static fn(string $body) => $feeds->sync($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]), Ics::parse($body, 'America/New_York'));
        $cal = static fn(array $e): string => implode("\r\n", array_merge(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sample//Feed//EN', 'BEGIN:VEVENT', 'UID:sample-moment@example.test', 'DTSTAMP:20270101T000000Z'],
            $e, ['RRULE:FREQ=DAILY;COUNT=10', 'SUMMARY:Sample game', 'END:VEVENT', 'END:VCALENDAR', '']));
        $sync($cal(['DTSTART;TZID=America/New_York:20270104T190000', 'DTEND;TZID=America/New_York:20270104T200000']));
        $masterId = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NULL', [$calendarId]);
        $d->events->patch($u, $masterId, ['scope' => 'this', 'instanceStart' => '2027-01-07T00:00:00+00:00', 'tagNames' => ['sample-day']]);
        checkEq('before: the tag is on the occurrence at 2027-01-07T00:00Z', ['2027-01-07T00:00Z'], $taggedShown($d->events, $u, $calendarId));
        $sync($cal(['DTSTART:20270105T000000Z', 'DTEND:20270105T010000Z']));
        checkEq('after: the tag is still on the occurrence at 2027-01-07T00:00Z', [['2027-01-07 00:00:00', '2027-01-07 00:00:00', null], ['2027-01-07T00:00Z']],
            [$taggedRows($calendarId)[0] ?? null, $taggedShown($d->events, $u, $calendarId)]);
    });
};
