<?php

declare(strict_types=1);

// What a poll may remove, and how Google's refusals of a write read
// (decisions.md, Sync and Storage): an empty listing never deletes (a whole
// read or a 410 reset that lists nothing for a calendar holding rows is not
// applied, and Review says so); every row a poll removes while it held
// something here alone is named in Activity by its row id and the date it
// showed (A5); Google's refusals are classified by Google's reason (busy,
// retried with backoff; deleted at Google; no permission). Against the
// probe's stand-in Google (tools/google-probe), invented data.

use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Trips;
use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\Http\HttpError;
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

    // Mondays 10:00 Los Angeles from 1 March 2027, ten of them, the series
    // tagged here and 8 March given a tag of its own; a single event, "Pizza
    // night", tagged here too. $writer answers come from $t->script while
    // it holds any (status, body), else from the stand-in.
    $setup = static function () use ($db, $cfg, $expand, $when): object {
        $u = freshUser($db);
        $d = domains($db);
        $fake = new FakeGoogle('guards@group.calendar.google.com');
        $fake->token = null;
        $fake->zone = 'America/Los_Angeles';
        $fake->expand = $expand;
        $fake->seed(['id' => 'smpl7weekly', 'iCalUID' => 'smpl7weekly@google.com', 'summary' => 'Sample practice', 'location' => 'Sample hall 1',
            'start' => $when('2027-03-01 10:00'), 'end' => $when('2027-03-01 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=10']]);
        $fake->seed(['id' => 'smpl7pizza', 'iCalUID' => 'smpl7pizza@google.com', 'summary' => 'Pizza night', 'start' => $when('2027-03-05 19:00'), 'end' => $when('2027-03-05 21:00')]);
        $t = new class {
            /** @var list<array{0:int,1:string}> */
            public array $script = [];
            public array $sent = [];
            public array $slept = [];
        };
        $inner = $fake->writerTransport();
        $transport = static function (string $method, string $url, ?array $payload, array $headers = []) use ($t, $inner): array {
            $t->sent[] = $method;
            if ($t->script !== []) {
                [$status, $body] = array_shift($t->script);
                return ['status' => $status, 'body' => $body];
            }
            return $inner($method, $url, $payload, array_merge(['Authorization: Bearer sample-access'], $headers));
        };
        $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
            => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
        $feeds = new Feeds($db);
        $writer = new GoogleWriter($db, $auth, $feeds, $transport);
        $writer->sleep = static function (float $s) use ($t): void {
            $t->slept[] = $s;
        };
        $syncTransport = $fake->syncTransport();
        $sync = new GoogleSync($db, $auth, $feeds, static fn(string $url, array $headers): array => $syncTransport($url, array_merge(['Authorization: Bearer sample-access'], $headers)));
        $events = new Events($db, new Recurrence(), $d->undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $d->undo, new BetterCal\Infra\JobQueue($db)), new Trips($db, $d->undo), null, $writer);
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $calendarId = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample club', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'guards@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]));
        $masterId = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$calendarId, 'smpl7weekly']);
        $pizzaId = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$calendarId, 'smpl7pizza']);
        $events->patch($u, $masterId, ['scope' => 'all', 'tagNames' => ['sample-series']]);
        $events->patch($u, $masterId, ['scope' => 'this', 'instanceStart' => '2027-03-08T18:00:00+00:00', 'tagNames' => ['sample-day']]);
        $events->patch($u, $pizzaId, ['tagNames' => ['sample-food']]);
        $dayId = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$masterId]);
        $t->sent = [];
        return (object) ['u' => $u, 'fake' => $fake, 't' => $t, 'sync' => $sync, 'events' => $events, 'writer' => $writer, 'calendarId' => $calendarId,
            'masterId' => $masterId, 'dayId' => $dayId, 'pizzaId' => $pizzaId];
    };
    $calendar = static fn(object $s): array => $db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]);
    $poll = static function (object $s) use ($calendar): ?string {
        try {
            $s->sync->poll($calendar($s));
            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    };
    $live = static fn(object $s): array => array_map('intval', array_column($db->all('SELECT id FROM events WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY id', [$s->calendarId]), 'id'));
    $notices = static fn(object $s): array => array_column($db->all("SELECT status FROM review_items WHERE user_id = ? AND kind = 'google_empty' ORDER BY id", [$s->u]), 'status');
    $lastEntry = static fn(object $s): ?array => $db->one("SELECT summary, details_json FROM mutations WHERE user_id = ? AND entity = 'calendar' ORDER BY id DESC LIMIT 1", [$s->u]);
    $atGoogle = static function (object $s, string $method, string $id, ?array $body = null): void {
        $answer = ($s->fake->writerTransport())($method, 'https://www.googleapis.com/calendar/v3/calendars/guards%40group.calendar.google.com/events/' . $id, $body, ['Authorization: Bearer sample-access']);
        if ((int) $answer['status'] >= 300) {
            throw new RuntimeException('the stand-in refused the change: ' . $answer['body']);
        }
    };

    // ---- An empty listing never deletes ------------------------------------

    foreach (['a whole read' => null, 'a 410 reset' => 'sample-stale-token'] as $read => $token) {
        scenario("google poll guards, $read: Google listing nothing for a calendar holding rows is not applied; Review says so; the next whole read that lists them applies and closes it", function () use ($setup, $poll, $live, $notices, $calendar, $token, $db): void {
            $s = $setup();
            $rows = $live($s);
            $kept = $s->fake->events;
            $s->fake->events = []; // the account lost its access: Google lists nothing
            $db->run('UPDATE calendars SET google_sync_token = ? WHERE id = ?', [$token, $s->calendarId]);
            $error = $poll($s);
            checkEq('nothing listed: every row kept, the sync position as it was, one open notice, and the poll says why', [$rows, $token, ['open'], true],
                [$live($s), $calendar($s)['google_sync_token'], $notices($s), str_contains((string) $error, 'nothing was removed')]);
            $poll($s);
            checkEq('a second empty read: still every row, still one notice', [$rows, ['open']], [$live($s), $notices($s)]);
            $s->fake->events = $kept;
            checkEq('listed again: the poll applies, the notice closes, the rows stay', [null, $rows, ['gone']], [$poll($s), $live($s), $notices($s)]);
        });
    }

    scenario('google poll guards: a delta that deletes every event still applies (a calendar emptied at Google on purpose)', function () use ($setup, $poll, $live, $notices, $atGoogle): void {
        $s = $setup();
        $atGoogle($s, 'DELETE', 'smpl7weekly');
        $atGoogle($s, 'DELETE', 'smpl7pizza');
        checkEq('the delta applies: no row left, no notice', [null, [], []], [$poll($s), $live($s), $notices($s)]);
    });

    // ---- A5: a removal is named by its row id and its shown date ---------------

    scenario('google poll guards: a series and an event deleted at Google are named by row id and date with what they held here', function () use ($setup, $poll, $lastEntry, $atGoogle): void {
        $s = $setup();
        $atGoogle($s, 'DELETE', 'smpl7weekly');
        $atGoogle($s, 'DELETE', 'smpl7pizza');
        $poll($s);
        $entry = $lastEntry($s);
        $named = array_map(static fn(array $d): array => [$d['id'], $d['day'], $d['held'], $d['why']], json_decode((string) $entry['details_json'], true)['removedDays'] ?? []);
        usort($named, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        checkEq('the master, its tagged day and the event, each by id and the date it showed', [
            [$s->masterId, '2027-03-01', ['its tags'], 'deleted'],
            [$s->pizzaId, '2027-03-05', ['its tags'], 'deleted'],
            [$s->dayId, '2027-03-08', ['its tags'], 'deleted'],
        ], $named);
        check('the summary names them in words', str_contains((string) $entry['summary'], "'Pizza night' on 2027-03-05 with its tags") && str_contains((string) $entry['summary'], 'no longer in the calendar'), (string) $entry['summary']);
    });

    scenario('google poll guards: a COUNT that ends the series before its tagged day names that day by row id and date', function () use ($setup, $poll, $lastEntry, $atGoogle): void {
        $s = $setup();
        $atGoogle($s, 'PATCH', 'smpl7weekly', ['recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=1']]);
        $poll($s);
        $named = array_map(static fn(array $d): array => [$d['id'], $d['day'], $d['why']], json_decode((string) $lastEntry($s)['details_json'], true)['removedDays'] ?? []);
        checkEq('the day, by its row id and 8 March', [[$s->dayId, '2027-03-08', 'gone']], $named);
    });

    // ---- Google's refusals, by Google's reason ------------------------------

    $busy = static fn(string $reason, int $status = 403): array => [$status, json_encode(['error' => ['code' => $status, 'message' => 'Sample limit',
        'errors' => [['domain' => 'usageLimits', 'reason' => $reason, 'message' => 'Sample limit']]]])];

    scenario('google refusals: a rate limit (403) or a 429 is retried after a pause and lands', function () use ($setup, $busy, $db): void {
        foreach (['a 403 rateLimitExceeded' => $busy('rateLimitExceeded'), 'a 429' => [429, json_encode(['error' => ['code' => 429, 'message' => 'Too many requests', 'status' => 'RESOURCE_EXHAUSTED']])]] as $what => $answer) {
            $s = $setup();
            $s->t->script = [$answer];
            $s->events->patch($s->u, $s->pizzaId, ['title' => 'Pizza night, later']);
            checkEq("$what: one pause, the same PATCH again, and the change landed", [[1.0], ['PATCH', 'PATCH'], 'Pizza night, later'],
                [$s->t->slept, array_values(array_filter($s->t->sent, static fn(string $m): bool => $m === 'PATCH')), $db->scalar('SELECT title FROM events WHERE id = ?', [$s->pizzaId])]);
        }
    });

    scenario('google refusals: still busy after the retries is "Google is busy", 503, and nothing changed here', function () use ($setup, $busy, $db): void {
        $s = $setup();
        $s->t->script = [$busy('quotaExceeded'), $busy('quotaExceeded'), $busy('quotaExceeded')];
        $error = null;
        try {
            $s->events->patch($s->u, $s->pizzaId, ['title' => 'Pizza night, later']);
        } catch (HttpError $e) {
            $error = $e;
        }
        checkEq('three tries, two pauses growing, google_busy 503, the title as it was', [[1.0, 2.0], 'google_busy', 503, true, 'Pizza night'],
            [$s->t->slept, $error?->errorCode, $error?->status, str_contains((string) $error?->getMessage(), 'Google is busy'), $db->scalar('SELECT title FROM events WHERE id = ?', [$s->pizzaId])]);
    });

    scenario('google refusals: a 404 on the event is "deleted at Google"; a permission 403 keeps its words; neither is retried', function () use ($setup): void {
        $s = $setup();
        $s->t->script = [[404, json_encode(['error' => ['code' => 404, 'message' => 'Not Found', 'errors' => [['domain' => 'global', 'reason' => 'notFound']]]])]];
        $code = refusal(fn() => $s->events->patch($s->u, $s->pizzaId, ['title' => 'Pizza night, later']));
        checkEq('a 404: google_deleted, no pause', ['google_deleted', []], [$code, $s->t->slept]);
        $s->t->script = [[403, json_encode(['error' => ['code' => 403, 'message' => 'Forbidden', 'errors' => [['domain' => 'global', 'reason' => 'forbidden']]]])]];
        $error = null;
        try {
            $s->events->patch($s->u, $s->pizzaId, ['title' => 'Pizza night, later']);
        } catch (HttpError $e) {
            $error = $e;
        }
        checkEq('a permission 403: today\'s words, 403, no pause', ['google_write_failed', 403, true, []],
            [$error?->errorCode, $error?->status, str_starts_with((string) $error?->getMessage(), 'Google says this account may not edit that calendar'), $s->t->slept]);
    });

    scenario('google refusals: the reading of an answer, by status and reason', function (): void {
        $body = static fn(array $error): string => json_encode(['error' => $error]);
        checkEq('busy, deleted, permission, other, success', ['busy', 'busy', 'busy', 'busy', 'deleted', 'deleted', 'permission', 'permission', 'other', null], [
            GoogleWriter::refusal(429, ''),
            GoogleWriter::refusal(403, $body(['errors' => [['domain' => 'usageLimits', 'reason' => 'userRateLimitExceeded']]])),
            GoogleWriter::refusal(403, $body(['errors' => [['domain' => 'calendar', 'reason' => 'quotaExceeded']]])),
            GoogleWriter::refusal(403, $body(['status' => 'RESOURCE_EXHAUSTED'])),
            GoogleWriter::refusal(404, ''),
            GoogleWriter::refusal(410, ''),
            GoogleWriter::refusal(403, $body(['errors' => [['domain' => 'global', 'reason' => 'forbidden']]])),
            GoogleWriter::refusal(403, 'not json'),
            GoogleWriter::refusal(400, ''),
            GoogleWriter::refusal(200, '{}'),
        ]);
    });
};
