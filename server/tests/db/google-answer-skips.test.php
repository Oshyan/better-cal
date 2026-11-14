<?php

declare(strict_types=1);

// A write's answer that is a series' own resource says nothing about its
// skipped days. Google keeps a skipped day as a cancelled record of its own
// (the master's recurrence gains no EXDATE), so the series' resource never
// names it. Answering a repeating invitation sends the series' guest list
// and stores Google's answer, the series' resource alone; undoing the answer
// does the same. The rule: only a list that is whole for a series (a whole
// read, a read-back, the answer to a write that sent the recurrence) says
// everything about its skips; any other list adds and removes skips only by
// the items it carries (GoogleSync::applyChanges, rule 7; GoogleWriter::apply
// is told what the write sent).
//
// Expectations by calendar date in Los Angeles, written by hand (the round's
// hunt, 2026-11-10). Invented data, the stand-in Google of tools/google-probe.

use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Rsvp;
use BetterCal\Domain\Trips;
use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\Infra\Db;
use BetterCal\Infra\EmailSender;
use BetterCal\Support\Time;

require_once dirname(__DIR__, 3) . '/tools/google-probe/lib/FakeGoogle.php';

return static function (Db $db): void {
    $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
    $calendarAt = 'owner@example.test';
    $zone = 'America/Los_Angeles';
    $la = new DateTimeZone($zone);
    $when = static fn(string $local): array => ['dateTime' => (new DateTimeImmutable($local, new DateTimeZone('America/Los_Angeles')))->format('Y-m-d\TH:i:sP'), 'timeZone' => 'America/Los_Angeles'];
    $guests = ['organizer' => ['email' => 'host@example.test'],
        'attendees' => [['email' => 'host@example.test', 'responseStatus' => 'accepted'], ['email' => 'owner@example.test', 'self' => true, 'responseStatus' => 'needsAction']]];

    $setup = static function () use ($db, $cfg, $calendarAt, $zone, $when, $guests): object {
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $fake = new FakeGoogle($calendarAt);
        $fake->token = null;
        $fake->zone = $zone;
        // A daily invitation at 10:00 Los Angeles, ten days from Monday 4 January 2027.
        $fake->seed(['id' => 'smplinv', 'iCalUID' => 'smplinv@google.com', 'summary' => 'Sample standup', 'recurrence' => ['RRULE:FREQ=DAILY;COUNT=10'],
            'start' => $when('2027-01-04 10:00'), 'end' => $when('2027-01-04 10:30')] + $guests);
        // The organizer cancelled Wednesday 6 January: Google holds it as a cancelled record (10:00 PST is 18:00 UTC).
        $fake->seed(['id' => 'smplinv_20270106T180000Z', 'iCalUID' => 'smplinv@google.com', 'status' => 'cancelled', 'recurringEventId' => 'smplinv',
            'originalStartTime' => $when('2027-01-06 10:00'), 'start' => $when('2027-01-06 10:00'), 'end' => $when('2027-01-06 10:30')]);
        $inner = $fake->writerTransport();
        $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
            => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
        $feeds = new Feeds($db);
        // $net->downAfterWrite: the network goes right after the writer's next write is taken, so the read after it can't be made.
        $net = (object) ['downAfterWrite' => false, 'down' => false];
        $writer = new GoogleWriter($db, $auth, $feeds, static function (string $method, string $url, ?array $payload, array $headers = []) use ($inner, $net): array {
            if ($net->down) {
                throw new \RuntimeException('HTTP error for the stand-in: network down');
            }
            $answer = $inner($method, $url, $payload, array_merge(['Authorization: Bearer sample-access'], $headers));
            if ($net->downAfterWrite && $method !== 'GET') {
                $net->downAfterWrite = false;
                $net->down = true;
            }
            return $answer;
        });
        $syncTransport = $fake->syncTransport();
        $sync = new GoogleSync($db, $auth, $feeds, static fn(string $url, array $headers): array => $syncTransport($url, array_merge(['Authorization: Bearer sample-access'], $headers)));
        $events = new Events($db, new Recurrence(), $d->undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $d->undo, new BetterCal\Infra\JobQueue($db)), new Trips($db, $d->undo), null, $writer);
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $calendarId = $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample work', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => $calendarAt, 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $poll = static fn() => $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]));
        $poll();
        $masterId = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$calendarId, 'smplinv']);
        return (object) ['u' => $u, 'd' => $d, 'fake' => $fake, 'events' => $events, 'writer' => $writer, 'poll' => $poll, 'calendarId' => $calendarId, 'masterId' => $masterId, 'net' => $net];
    };
    // The Los Angeles dates of 5 to 7 January the calendar shows an event on.
    $shownDays = static function (object $s) use ($la): array {
        $out = [];
        foreach ($s->events->window($s->u, new DateTimeImmutable('2027-01-05 00:00', $la), new DateTimeImmutable('2027-01-08 00:00', $la), [$s->calendarId], null, true) as $o) {
            $out[] = (new DateTimeImmutable((string) $o['start']))->setTimezone($la)->format('Y-m-d H:i');
        }
        sort($out);
        return $out;
    };
    $skips = static fn(object $s): array => json_decode((string) ($db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$s->masterId]) ?? '[]'), true);
    $session = static function (object $s) use ($db): string {
        $token = bin2hex(random_bytes(16));
        $db->insert('sessions', ['token_hash' => hash('sha256', $token), 'user_id' => $s->u, 'csrf' => 'x', 'authenticated_at' => Time::nowDb(), 'expires_at' => '2030-01-01 00:00:00']);
        return $token;
    };

    scenario('google answer skips: an answer to a repeating invitation whose 6 January the organizer cancelled: 6 January stays cancelled here', function () use ($db, $setup, $shownDays, $skips, $session): void {
        $s = $setup();
        checkEq('before: 6 January is a skipped day (10:00 Los Angeles, 18:00 UTC), and the calendar shows 5 and 7 January only',
            [['2027-01-06 18:00:00'], ['2027-01-05 10:00', '2027-01-07 10:00']], [$skips($s), $shownDays($s)]);
        (new Rsvp($db, new EmailSender([]), [], $s->writer))->answer($s->u, $s->masterId, 'accepted', $session($s));
        checkEq('Google still holds the cancelled record of 6 January', 'cancelled', $s->fake->events['smplinv_20270106T180000Z']['status'] ?? null);
        checkEq('after the answer: 6 January is still a skipped day, and the calendar shows 5 and 7 January only',
            [['2027-01-06 18:00:00'], ['2027-01-05 10:00', '2027-01-07 10:00']], [$skips($s), $shownDays($s)]);
        ($s->poll)();
        checkEq('after the next poll: 6 January is a skipped day', [['2027-01-06 18:00:00'], ['2027-01-05 10:00', '2027-01-07 10:00']], [$skips($s), $shownDays($s)]);
    });

    // The same with something held here alone on the cancelled day's neighbour, and the answer undone.
    scenario('google answer skips: the answer undone: 6 January stays cancelled here', function () use ($db, $setup, $shownDays, $skips, $session): void {
        $s = $setup();
        (new Rsvp($db, new EmailSender([]), [], $s->writer))->answer($s->u, $s->masterId, 'accepted', $session($s));
        $answer = lastEntry($db, $s->u);
        ($s->poll)();
        checkEq('after the answer and a poll: 6 January is a skipped day', ['2027-01-06 18:00:00'], $skips($s));
        (new BetterCal\Domain\Undo($db, null, $s->writer))->undoById($s->u, $answer);
        checkEq('after the undo of the answer: 6 January is still a skipped day, and the calendar shows 5 and 7 January only',
            [['2027-01-06 18:00:00'], ['2027-01-05 10:00', '2027-01-07 10:00']], [$skips($s), $shownDays($s)]);
    });

    // The two scenarios above are kept by the read after the answer: it lists the series with its cancelled record, and
    // that list is stored as a poll stores one. When that read can't be made (the network goes right after Google takes
    // the answer), the answer stands and only Google's answer to the write is stored: the series' own resource, which
    // says nothing of the days Google holds as cancelled records, so the skips stored here stand (GoogleWriter::apply,
    // told the write sent no recurrence: rule 7 of the merge; docs/design/google-series-read-back.md, "What This Does
    // Not Cover": "if the read after it can't be made, the answer stands, nothing is compared").
    $mine = static fn(object $s): ?string => array_values(array_filter($s->fake->events['smplinv']['attendees'] ?? [], static fn(array $a): bool => !empty($a['self'])))[0]['responseStatus'] ?? null;
    scenario('google answer skips: the read after the answer can\'t be made: the answer stands, and 6 January stays cancelled here', function () use ($db, $setup, $shownDays, $skips, $session, $mine): void {
        $s = $setup();
        $s->net->downAfterWrite = true;
        (new Rsvp($db, new EmailSender([]), [], $s->writer))->answer($s->u, $s->masterId, 'accepted', $session($s));
        checkEq('the answer was taken by Google and the network went right after it', ['accepted', true], [$mine($s), $s->net->down]);
        checkEq('after the answer with no read after it: 6 January is still a skipped day, and the calendar shows 5 and 7 January only',
            [['2027-01-06 18:00:00'], ['2027-01-05 10:00', '2027-01-07 10:00']], [$skips($s), $shownDays($s)]);
        $s->net->down = false;
        ($s->poll)();
        checkEq('after the next poll, the network back: 6 January is a skipped day', [['2027-01-06 18:00:00'], ['2027-01-05 10:00', '2027-01-07 10:00']], [$skips($s), $shownDays($s)]);
    });

    scenario('google answer skips: the read after the answer\'s undo can\'t be made: the undo stands, and 6 January stays cancelled here', function () use ($db, $setup, $shownDays, $skips, $session, $mine): void {
        $s = $setup();
        (new Rsvp($db, new EmailSender([]), [], $s->writer))->answer($s->u, $s->masterId, 'accepted', $session($s));
        $answer = lastEntry($db, $s->u);
        ($s->poll)(); // as in the scenario above: the undo starts from what a poll stored
        $s->net->downAfterWrite = true;
        (new BetterCal\Domain\Undo($db, null, $s->writer))->undoById($s->u, $answer);
        checkEq('the undo was taken by Google and the network went right after it', ['needsAction', true], [$mine($s), $s->net->down]);
        checkEq('after the undo with no read after it: 6 January is still a skipped day, and the calendar shows 5 and 7 January only',
            [['2027-01-06 18:00:00'], ['2027-01-05 10:00', '2027-01-07 10:00']], [$skips($s), $shownDays($s)]);
    });
};
