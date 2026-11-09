<?php

declare(strict_types=1);

// Undo of a write to one Google resource (#172): a field change, a delete,
// an attendance answer and one occurrence of a series, each undone by a
// second write to Google conditional on the etag recorded after the
// change, and each undo undoable back. A stale etag refuses the undo with
// nothing written (a whole-series change: google-series-undo.test.php).
// Google is a stand-in holding invented resources, with an etag that
// changes on every write (on every row of a series for a write to any of
// them, probe 5) and If-Match answered 412 when stale.

use BetterCal\Domain\Activity;
use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Rsvp;
use BetterCal\Domain\Undo;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\EmailSender;
use BetterCal\Support\Time;

return static function (Db $db): void {
    $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
    $tz = 'America/Los_Angeles';

    // Invented resources: a single event, a weekly series with one
    // occurrence Google lists only once it is changed (virtual), and an
    // invitation the account is a guest of.
    $resources = static fn(): array => [
        'probe9z8y7x' => ['kind' => 'calendar#event', 'id' => 'probe9z8y7x', 'iCalUID' => 'probe9z8y7x@google.com', 'status' => 'confirmed', 'summary' => 'Sample review',
            'description' => 'Sample notes', 'location' => 'Sample room 1',
            'start' => ['dateTime' => '2027-03-03T14:00:00-08:00', 'timeZone' => $tz], 'end' => ['dateTime' => '2027-03-03T15:00:00-08:00', 'timeZone' => $tz]],
        'probe1a2b3c' => ['kind' => 'calendar#event', 'id' => 'probe1a2b3c', 'iCalUID' => 'probe1a2b3c@google.com', 'status' => 'confirmed', 'summary' => 'Sample standup',
            'start' => ['dateTime' => '2027-03-01T10:00:00-08:00', 'timeZone' => $tz], 'end' => ['dateTime' => '2027-03-01T10:30:00-08:00', 'timeZone' => $tz],
            'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']],
        'probe1a2b3c_20270308T180000Z' => ['kind' => 'calendar#event', 'id' => 'probe1a2b3c_20270308T180000Z', 'iCalUID' => 'probe1a2b3c@google.com', 'status' => 'confirmed',
            'summary' => 'Sample standup', 'recurringEventId' => 'probe1a2b3c', 'originalStartTime' => ['dateTime' => '2027-03-08T10:00:00-08:00', 'timeZone' => $tz],
            'start' => ['dateTime' => '2027-03-08T10:00:00-08:00', 'timeZone' => $tz], 'end' => ['dateTime' => '2027-03-08T10:30:00-08:00', 'timeZone' => $tz], 'virtual' => true],
        'probe5k6m7n' => ['kind' => 'calendar#event', 'id' => 'probe5k6m7n', 'iCalUID' => 'probe5k6m7n@google.com', 'status' => 'confirmed', 'summary' => 'Sample planning',
            'start' => ['dateTime' => '2027-03-04T09:00:00-08:00', 'timeZone' => $tz], 'end' => ['dateTime' => '2027-03-04T10:00:00-08:00', 'timeZone' => $tz],
            'organizer' => ['email' => 'host@example.test'],
            'attendees' => [['email' => 'host@example.test', 'responseStatus' => 'accepted'], ['email' => 'owner@example.test', 'self' => true, 'responseStatus' => 'needsAction']]],
    ];

    $setup = static function () use ($db, $cfg, $resources): object {
        $u = freshUser($db);
        $d = domains($db);
        // Google as a store: each write answers the resource with a new
        // etag, given to every resource of its series too.
        $google = new class {
            /** @var array<string,array> */
            public array $res = [];
            /** @var list<array{0:string,1:string,2:?array,3:?string}> method, id or query, payload, If-Match */
            public array $calls = [];
            public int $seq = 1;

            public function bump(string $uid): void
            {
                foreach ($this->res as $id => $r) {
                    if ($r['iCalUID'] === $uid && empty($r['virtual'])) {
                        $this->res[$id]['etag'] = '"g' . ++$this->seq . '"';
                    }
                }
            }

            public function out(array $r): array
            {
                unset($r['virtual']);
                return $r;
            }

            /** @return list<array> */
            public function listed(?string $uid = null, bool $deleted = false): array
            {
                return array_values(array_map(fn(array $r) => $this->out($r), array_filter($this->res,
                    static fn(array $r): bool => empty($r['virtual']) && ($uid === null || ($r['iCalUID'] === $uid && ($deleted || $r['status'] !== 'cancelled'))))));
            }
        };
        foreach ($resources() as $id => $r) {
            $google->res[$id] = $r + ['etag' => '"g1"'];
        }
        $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
            => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
        $feeds = new Feeds($db);
        $writer = new GoogleWriter($db, $auth, $feeds, static function (string $method, string $url, ?array $payload, array $headers = []) use ($google): array {
            $ifMatch = null;
            foreach ($headers as $h) {
                if (stripos($h, 'If-Match:') === 0) {
                    $ifMatch = trim(substr($h, 9));
                }
            }
            $path = (string) parse_url($url, PHP_URL_PATH);
            $id = str_contains($path, '/events/') ? rawurldecode(substr($path, strrpos($path, '/events/') + 8)) : '';
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $google->calls[] = [$method, $id !== '' ? $id : (string) ($query['iCalUID'] ?? ''), $payload, $ifMatch];
            if ($id === '') {
                return ['status' => 200, 'body' => json_encode(['items' => $google->listed((string) ($query['iCalUID'] ?? ''), ($query['showDeleted'] ?? '') === 'true')])];
            }
            $r = $google->res[$id] ?? null;
            if ($r === null) {
                return ['status' => 404, 'body' => json_encode(['error' => ['code' => 404, 'message' => 'Not Found']])];
            }
            if ($method === 'GET') {
                return ['status' => 200, 'body' => json_encode($google->out($r))];
            }
            if ($ifMatch !== null && empty($r['virtual']) && $ifMatch !== $r['etag']) {
                return ['status' => 412, 'body' => json_encode(['error' => ['code' => 412, 'message' => 'Precondition Failed']])];
            }
            if ($method === 'DELETE') {
                $google->res[$id]['status'] = 'cancelled';
                $google->bump($r['iCalUID']);
                return ['status' => 204, 'body' => ''];
            }
            $google->res[$id] = array_merge($r, (array) $payload);
            unset($google->res[$id]['virtual']);
            $google->bump($r['iCalUID']);
            return ['status' => 200, 'body' => json_encode($google->out($google->res[$id]))];
        });
        $sync = new GoogleSync($db, $auth, $feeds, static fn(string $url, array $headers): array
            => ['status' => 200, 'body' => json_encode(['kind' => 'calendar#events', 'items' => $google->listed(), 'nextSyncToken' => 'sync-' . $google->seq])]);
        $events = new Events($db, new BetterCal\Domain\Recurrence(), $d->undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $d->undo, new BetterCal\Infra\JobQueue($db)), new BetterCal\Domain\Trips($db, $d->undo), null, $writer);
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $calendarId = $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample team', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'team@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]));
        $google->calls = [];
        return (object) ['u' => $u, 'google' => $google, 'writer' => $writer, 'events' => $events, 'calendarId' => $calendarId,
            'undo' => new Undo($db, null, $writer), 'labels' => new BetterCal\Domain\Labels($db)];
    };
    $row = static fn(object $t, string $googleId): ?array => $db->one('SELECT * FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$t->calendarId, $googleId]);
    $last = static fn(object $t): array => $db->one('SELECT * FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$t->u]);
    $google = static fn(array $entry): ?array => json_decode((string) $entry['details_json'], true)['google'] ?? null;
    $calls = static fn(object $t): array => array_map(static fn(array $c): array => [$c[0], $c[1], $c[3]], $t->google->calls);
    $undone = static fn(int $id): int => (int) $db->scalar('SELECT undone FROM mutations WHERE id = ?', [$id]);

    scenario('google undo: a field change is undone by its before body sent with the etag recorded after it, and the undo undoes back', function () use ($db, $setup, $row, $last, $google, $calls, $undone): void {
        $t = $setup();
        $id = (int) $row($t, 'probe9z8y7x')['id'];
        $t->events->patch($t->u, $id, ['title' => 'Sample review, renamed', 'location' => 'Sample room 2']);
        $entry = $last($t);
        $g = $google($entry);
        $listed = (new Activity($db))->list($t->u, [])['entries'][0];
        $snap = static fn(?string $json): ?string => json_decode((string) $json, true)['tables']['events'][0]['title'] ?? null;
        checkEq('the entry keeps the row before and after (its local part undoes from them) and the before state, etags before and after; Activity offers its undo', [
            'Sample review', 'Sample review, renamed', 'edit', 'probe9z8y7x', 'Sample review', 'Sample room 1', '"g1"', '"g2"', true,
        ], [$snap($entry['before_json']), $snap($entry['after_json']), $g['kind'] ?? null, $g['googleId'] ?? null, $g['before']['summary'] ?? null, $g['before']['location'] ?? null,
            $g['etagBefore'] ?? null, $g['etagAfter'] ?? null, $listed['undoable']]);

        $t->google->calls = [];
        $t->undo->undoById($t->u, (int) $entry['id']);
        $sent = $t->google->calls[1][2] ?? [];
        checkEq('a read, then one PATCH with If-Match "g2" from it, sending back the fields the change made, at their before values, and nothing else; the answer is stored', [
            [['GET', 'probe9z8y7x', null], ['PATCH', 'probe9z8y7x', '"g2"']], ['location', 'summary'], ['Sample review', 'Sample room 1'],
            ['Sample review', 'Sample room 1', '"g3"'], 1,
        ], [$calls($t), (static function (array $k): array { sort($k); return $k; })(array_keys($sent)), [$sent['summary'] ?? null, $sent['location'] ?? null],
            [$row($t, 'probe9z8y7x')['title'], $row($t, 'probe9z8y7x')['location'], $row($t, 'probe9z8y7x')['google_etag']], $undone((int) $entry['id'])]);

        $back = $last($t);
        $bg = $google($back);
        checkEq('the undo is an entry of its own, undoable, whose before is the renamed event', [
            "Undid: Updated 'Sample review' on Google calendar 'Sample team'", 'edit', 'Sample review, renamed', '"g2"', '"g3"',
        ], [$back['summary'], $bg['kind'] ?? null, $bg['before']['summary'] ?? null, $bg['etagBefore'] ?? null, $bg['etagAfter'] ?? null]);
        $t->google->calls = [];
        $t->undo->undoById($t->u, (int) $back['id']);
        checkEq('undoing the undo puts the change back, conditional on "g3" read just before, worded as the change itself', [
            [['GET', 'probe9z8y7x', null], ['PATCH', 'probe9z8y7x', '"g3"']], 'Sample review, renamed', 'Sample room 2', "Updated 'Sample review' on Google calendar 'Sample team'",
        ], [$calls($t), $row($t, 'probe9z8y7x')['title'], $row($t, 'probe9z8y7x')['location'], $last($t)['summary']]);
    });

    scenario('google undo: a delete is undone by a PATCH of the same id to confirmed, with its tags back', function () use ($db, $setup, $row, $last, $google, $calls, $undone): void {
        $t = $setup();
        $id = (int) $row($t, 'probe9z8y7x')['id'];
        $t->labels->setEventTags($t->u, $id, ['sample-tag']);
        $t->events->deleteEvent($t->u, $id, null, null);
        $entry = $last($t);
        $snapshot = json_decode((string) $entry['before_json'], true)['tables'] ?? [];
        checkEq('the delete went with If-Match "g1" from a read just before; the row is gone; the entry is a delete keeping the status, and the row and its tag as its snapshot', [
            [['GET', 'probe9z8y7x', null], ['DELETE', 'probe9z8y7x', '"g1"']], null, 'delete', 'confirmed', [$id], 1, false,
        ], [$calls($t), $row($t, 'probe9z8y7x'), $google($entry)['kind'] ?? null, $google($entry)['before']['status'] ?? null,
            array_map('intval', array_column($snapshot['events'] ?? [], 'id')), count($snapshot['event_tags'] ?? []), array_key_exists('local', $google($entry) ?? [])]);

        $t->google->calls = [];
        $t->undo->undoById($t->u, (int) $entry['id']);
        $back = $row($t, 'probe9z8y7x');
        $tags = $back !== null ? array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ?', [(int) $back['id']]), 'name') : [];
        checkEq('read back as deleted, then one PATCH {status: confirmed} with the deleted resource\'s etag; the event is back under its id with its tag', [
            [['GET', 'probe9z8y7x', null], ['PATCH', 'probe9z8y7x', '"g2"']], ['status' => 'confirmed'], 'Sample review', $id, ['sample-tag'], 1,
        ], [$calls($t), $t->google->calls[1][2] ?? null, $back['title'] ?? null, (int) ($back['id'] ?? 0), $tags, $undone((int) $entry['id'])]);

        // The undo undoes back: deleted again, conditional on what came back.
        $t->google->calls = [];
        $t->undo->undoById($t->u, (int) $last($t)['id']);
        checkEq('undoing the undo deletes it again with If-Match "g3", read just before', [[['GET', 'probe9z8y7x', null], ['DELETE', 'probe9z8y7x', '"g3"']], null], [$calls($t), $row($t, 'probe9z8y7x')]);
    });

    scenario('google undo: an attendance answer is undone by sending the earlier responseStatus', function () use ($db, $setup, $row, $last, $google, $calls, $undone): void {
        $t = $setup();
        $id = (int) $row($t, 'probe5k6m7n')['id'];
        $session = bin2hex(random_bytes(16));
        $db->insert('sessions', ['token_hash' => hash('sha256', $session), 'user_id' => $t->u, 'csrf' => 'x', 'authenticated_at' => Time::nowDb(), 'expires_at' => '2030-01-01 00:00:00']);
        (new Rsvp($db, new EmailSender([]), [], $t->writer))->answer($t->u, $id, 'accepted', $session);
        $entry = $last($t);
        $partstat = static fn(): ?string => json_decode((string) $db->scalar('SELECT invite_json FROM events WHERE id = ?', [$id]), true)['myPartstat'] ?? null;
        checkEq('the answer went to Google; its entry keeps the earlier answer and is undoable', [
            'ACCEPTED', 'answer', 'needsAction', '"g2"', '{"tables":[]}',
        ], [$partstat(), $google($entry)['kind'] ?? null, $google($entry)['responseStatus'] ?? null, $google($entry)['etagAfter'] ?? null, $entry['before_json']]);

        $t->google->calls = [];
        $t->undo->undoById($t->u, (int) $entry['id']);
        $sent = $t->google->calls[1][2]['attendees'] ?? [];
        checkEq('read once (the guest list, which is also the write\'s condition), then one PATCH with If-Match "g2" putting back needsAction for the account alone; the invitation reads unanswered', [
            [['GET', 'probe5k6m7n', null], ['PATCH', 'probe5k6m7n', '"g2"']], ['accepted', 'needsAction'], 'NEEDS-ACTION', 1, 'accepted',
        ], [$calls($t), array_column($sent, 'responseStatus'), $partstat(), $undone((int) $entry['id']), $google($last($t))['responseStatus'] ?? null]);
    });

    scenario('google undo: a stale etag refuses the undo with the stale words and the conflict, and nothing is written', function () use ($db, $setup, $row, $last, $calls): void {
        $t = $setup();
        $id = (int) $row($t, 'probe9z8y7x')['id'];
        $t->events->patch($t->u, $id, ['title' => 'Sample review, renamed']);
        $entry = $last($t);
        // Changed at Google since, not yet polled here.
        $t->google->res['probe9z8y7x']['summary'] = 'Sample review, renamed at Google';
        $t->google->bump('probe9z8y7x@google.com');
        $state = static fn(): array => [$db->all('SELECT * FROM events WHERE calendar_id = ? ORDER BY id', [$t->calendarId]),
            $db->all('SELECT id, undone FROM mutations WHERE user_id = ? ORDER BY id', [$t->u])];
        $before = $state();
        $t->google->calls = [];
        $caught = null;
        try {
            $t->undo->undoById($t->u, (int) $entry['id']);
        } catch (HttpError $e) {
            $caught = $e->status . ' ' . $e->errorCode . ': ' . $e->getMessage();
        }
        checkEq('the read before the PATCH shows the change, so nothing is sent: refused, rows and Activity as they were', [
            '409 google_conflict: Newer changes exist for this item; undoing would overwrite them. This event changed at Google since you last saw it.',
            [['GET', 'probe9z8y7x', null]], $before,
        ], [$caught, $calls($t), $state()]);
    });

    scenario('google undo: one occurrence of a series, changed and deleted, each undone at its instance id', function () use ($db, $setup, $row, $last, $google, $calls, $undone): void {
        $t = $setup();
        $masterId = (int) $row($t, 'probe1a2b3c')['id'];
        $t->events->patch($t->u, $masterId, ['title' => 'Sample standup, guest', 'scope' => 'this', 'instanceStart' => '2027-03-08T18:00:00+00:00']);
        $entry = $last($t);
        $g = $google($entry);
        checkEq('the occurrence edit records the occurrence as it showed, at its instance id, with no recurrence', [
            'probe1a2b3c_20270308T180000Z', 'Sample standup', '2027-03-08T10:00:00-08:00', false, 'Sample standup, guest',
        ], [$g['googleId'] ?? null, $g['before']['summary'] ?? null, $g['before']['start']['dateTime'] ?? null, array_key_exists('recurrence', $g['before'] ?? []),
            $row($t, 'probe1a2b3c_20270308T180000Z')['title'] ?? null]);
        $t->google->calls = [];
        $t->undo->undoById($t->u, (int) $entry['id']);
        $patch = $t->google->calls[1] ?? [];
        // Back at the series' own values, the day is an ordinary occurrence again, as before the edit: Google's record for it makes no row.
        checkEq('the series read, one PATCH of the instance with the etag that read gave (the one the edit left), then the series\' etags read back; the occurrence is an ordinary day again, no row', [
            ['PATCH', 'probe1a2b3c_20270308T180000Z', $g['etagAfter']], ['GET', 'GET'], null, 1,
        ], [[$patch[0] ?? null, $patch[1] ?? null, $patch[3] ?? null], [$t->google->calls[0][0] ?? null, $t->google->calls[2][0] ?? null], $row($t, 'probe1a2b3c_20270308T180000Z')['title'] ?? null, $undone((int) $entry['id'])]);

        // This occurrence deleted, then undone: back live, no longer skipped.
        $exdates = static fn(): array => json_decode((string) ($db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$masterId]) ?? '[]'), true) ?: [];
        $t->events->deleteEvent($t->u, $masterId, 'this', '2027-03-08T18:00:00+00:00');
        $deleted = $last($t);
        $gone = [$row($t, 'probe1a2b3c_20270308T180000Z'), $exdates()];
        $t->google->calls = [];
        $t->undo->undoById($t->u, (int) $deleted['id']);
        checkEq('the delete is a skip; its undo reads the instance, then the series for the write\'s condition, sends {status: confirmed} to it, and the day is back as an ordinary occurrence', [
            [null, ['2027-03-08 18:00:00']], ['GET', 'GET', 'PATCH'], ['status' => 'confirmed'], [], null,
        ], [$gone, array_slice(array_column($t->google->calls, 0), 0, 3), $t->google->calls[2][2] ?? null, $exdates(), $row($t, 'probe1a2b3c_20270308T180000Z')['title'] ?? null]);

        // The undo undoes back: the day, an ordinary occurrence with no row here, is skipped again.
        $back = $last($t);
        $t->google->calls = [];
        $t->undo->undoById($t->u, (int) $back['id']);
        checkEq('undoing the undo deletes the instance at Google again and skips the day here; that undo names the entry it undid', [
            [['GET', 'probe1a2b3c@google.com'], ['DELETE', 'probe1a2b3c_20270308T180000Z']], ['2027-03-08 18:00:00'], 'cancelled', 1, (int) $back['id'],
        ], [array_map(static fn(array $c): array => [$c[0], $c[1]], array_slice($t->google->calls, 0, 2)), $exdates(), $t->google->res['probe1a2b3c_20270308T180000Z']['status'] ?? null,
            $undone((int) $back['id']), $google($last($t))['undoes'] ?? null]);
    });

    // A Redo of a "this event" edit whose undo made the day ordinary again
    // (no row here): it reads the day at Google, and that read passes the
    // same comparison with the rows here as every write, so a change made
    // there since the last poll refuses it; a change only to a field Google
    // alone holds does not.
    $redoAfterRemote = static function (array $remote) use ($db, $setup, $row, $last): array {
        $t = $setup();
        $dayId = 'probe1a2b3c_20270308T180000Z';
        $masterId = (int) $row($t, 'probe1a2b3c')['id'];
        $t->events->patch($t->u, $masterId, ['location' => 'Sample room A', 'scope' => 'this', 'instanceStart' => '2027-03-08T18:00:00+00:00']);
        $t->undo->undoById($t->u, (int) $last($t)['id']);
        $redo = $last($t);
        $gone = $row($t, $dayId);
        // Another client changes the day; no poll.
        $t->google->res[$dayId] = array_merge($t->google->res[$dayId], $remote);
        unset($t->google->res[$dayId]['virtual']);
        $t->google->bump('probe1a2b3c@google.com');
        $state = static fn(): array => [$db->all('SELECT * FROM events WHERE calendar_id = ? ORDER BY id', [$t->calendarId]),
            $db->all('SELECT id, undone, summary FROM mutations WHERE user_id = ? ORDER BY id', [$t->u])];
        $before = $state();
        $t->google->calls = [];
        $caught = null;
        try {
            $t->undo->undoById($t->u, (int) $redo['id']);
        } catch (HttpError $e) {
            $caught = $e->errorCode;
        }
        return ['gone' => $gone, 'caught' => $caught, 'writes' => array_values(array_filter(array_column($t->google->calls, 0), static fn(string $m): bool => $m !== 'GET')),
            'same' => $state() === $before, 'location' => $t->google->res[$dayId]['location'] ?? null];
    };

    scenario('google undo: a Redo of a day with no row here, after the same field changed at Google unpolled, is refused and changes nothing on either side', function () use ($redoAfterRemote): void {
        $r = $redoAfterRemote(['location' => 'Sample remote room']);
        checkEq('the undo left no row for the day; the Redo is refused with google_conflict, sends no write, and Google, the rows and Activity are as they were', [null, 'google_conflict', [], true, 'Sample remote room'],
            [$r['gone'], $r['caught'], $r['writes'], $r['same'], $r['location']]);
    });

    scenario('google undo: a Redo after a change at Google to a field only Google holds goes through', function () use ($redoAfterRemote): void {
        $r = $redoAfterRemote(['colorId' => '4']);
        checkEq('no refusal, one PATCH, the day takes the Redo\'s place', [null, ['PATCH'], 'Sample room A'], [$r['caught'], $r['writes'], $r['location']]);
    });
};
