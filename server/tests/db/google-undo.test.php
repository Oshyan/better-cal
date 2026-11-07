<?php

declare(strict_types=1);

// Undo of a write to one Google resource (#172): a field change, a delete,
// an attendance answer and one occurrence of a series, each undone by a
// second write to Google conditional on the etag recorded after the
// change, and each undo undoable back. A stale etag refuses the undo with
// nothing written; a whole-series change stays log-only. Google is a
// stand-in holding invented resources, with an etag that changes on every
// write (on every row of a series for a write to any of them, probe 5) and
// If-Match answered 412 when stale.

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
            public function listed(?string $uid = null): array
            {
                return array_values(array_map(fn(array $r) => $this->out($r), array_filter($this->res,
                    static fn(array $r): bool => empty($r['virtual']) && ($uid === null || ($r['iCalUID'] === $uid && $r['status'] !== 'cancelled')))));
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
                return ['status' => 200, 'body' => json_encode(['items' => $google->listed((string) ($query['iCalUID'] ?? ''))])];
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
        checkEq('the entry keeps empty snapshots and the before state, etags before and after; Activity offers its undo', [
            '{"tables":[]}', '{"tables":[]}', 'edit', 'probe9z8y7x', 'Sample review', 'Sample room 1', '"g1"', '"g2"', true,
        ], [$entry['before_json'], $entry['after_json'], $g['kind'] ?? null, $g['googleId'] ?? null, $g['before']['summary'] ?? null, $g['before']['location'] ?? null,
            $g['etagBefore'] ?? null, $g['etagAfter'] ?? null, $listed['undoable']]);

        $t->google->calls = [];
        $t->undo->undoById($t->u, (int) $entry['id']);
        $sent = $t->google->calls[0][2] ?? [];
        checkEq('one PATCH with If-Match "g2", sending the before body; the answer is stored', [
            [['PATCH', 'probe9z8y7x', '"g2"']], ['Sample review', 'Sample notes', 'Sample room 1', '2027-03-03T14:00:00-08:00'],
            ['Sample review', 'Sample room 1', '"g3"'], 1,
        ], [$calls($t), [$sent['summary'] ?? null, $sent['description'] ?? null, $sent['location'] ?? null, $sent['start']['dateTime'] ?? null],
            [$row($t, 'probe9z8y7x')['title'], $row($t, 'probe9z8y7x')['location'], $row($t, 'probe9z8y7x')['google_etag']], $undone((int) $entry['id'])]);

        $back = $last($t);
        $bg = $google($back);
        checkEq('the undo is an entry of its own, undoable, whose before is the renamed event', [
            "Undid: Updated 'Sample review' on Google calendar 'Sample team'", 'edit', 'Sample review, renamed', '"g2"', '"g3"',
        ], [$back['summary'], $bg['kind'] ?? null, $bg['before']['summary'] ?? null, $bg['etagBefore'] ?? null, $bg['etagAfter'] ?? null]);
        $t->google->calls = [];
        $t->undo->undoById($t->u, (int) $back['id']);
        checkEq('undoing the undo puts the change back, conditional on "g3", worded as the change itself', [
            [['PATCH', 'probe9z8y7x', '"g3"']], 'Sample review, renamed', 'Sample room 2', "Updated 'Sample review' on Google calendar 'Sample team'",
        ], [$calls($t), $row($t, 'probe9z8y7x')['title'], $row($t, 'probe9z8y7x')['location'], $last($t)['summary']]);
    });

    scenario('google undo: a delete is undone by a PATCH of the same id to confirmed, with its tags back', function () use ($db, $setup, $row, $last, $google, $calls, $undone): void {
        $t = $setup();
        $id = (int) $row($t, 'probe9z8y7x')['id'];
        $t->labels->setEventTags($t->u, $id, ['sample-tag']);
        $t->events->deleteEvent($t->u, $id, null, null);
        $entry = $last($t);
        checkEq('the delete went with If-Match "g1"; the row is gone; the entry is a delete keeping the status and the tag', [
            [['DELETE', 'probe9z8y7x', '"g1"']], null, 'delete', 'confirmed', 1,
        ], [$calls($t), $row($t, 'probe9z8y7x'), $google($entry)['kind'] ?? null, $google($entry)['before']['status'] ?? null, count($google($entry)['local']['tags'] ?? [])]);

        $t->google->calls = [];
        $t->undo->undoById($t->u, (int) $entry['id']);
        $back = $row($t, 'probe9z8y7x');
        $tags = $back !== null ? array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ?', [(int) $back['id']]), 'name') : [];
        checkEq('read back as deleted, then one PATCH {status: confirmed} with the deleted resource\'s etag; the event is back under its id with its tag', [
            [['GET', 'probe9z8y7x', null], ['PATCH', 'probe9z8y7x', '"g2"']], ['status' => 'confirmed'], 'Sample review', ['sample-tag'], 1,
        ], [$calls($t), $t->google->calls[1][2] ?? null, $back['title'] ?? null, $tags, $undone((int) $entry['id'])]);

        // The undo undoes back: deleted again, conditional on what came back.
        $t->google->calls = [];
        $t->undo->undoById($t->u, (int) $last($t)['id']);
        checkEq('undoing the undo deletes it again with If-Match "g3"', [[['DELETE', 'probe9z8y7x', '"g3"']], null], [$calls($t), $row($t, 'probe9z8y7x')]);
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
        checkEq('read, then one PATCH with If-Match "g2" putting back needsAction for the account alone; the invitation reads unanswered', [
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
        checkEq('one PATCH answered 412, refused, rows and Activity as they were', [
            '409 google_conflict: Newer changes exist for this item; undoing would overwrite them. This event changed at Google since you last saw it.',
            [['PATCH', 'probe9z8y7x', '"g2"']], $before,
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
        $patch = $t->google->calls[0] ?? [];
        checkEq('one PATCH of the instance with the recorded etag, then the series\' etags read back; the occurrence has its title again', [
            ['PATCH', 'probe1a2b3c_20270308T180000Z', $g['etagAfter']], 'GET', 'Sample standup', 1,
        ], [[$patch[0] ?? null, $patch[1] ?? null, $patch[3] ?? null], $t->google->calls[1][0] ?? null, $row($t, 'probe1a2b3c_20270308T180000Z')['title'] ?? null, $undone((int) $entry['id'])]);

        // This occurrence deleted, then undone: back live, no longer skipped.
        $exdates = static fn(): array => json_decode((string) ($db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$masterId]) ?? '[]'), true) ?: [];
        $t->events->deleteEvent($t->u, $masterId, 'this', '2027-03-08T18:00:00+00:00');
        $deleted = $last($t);
        $gone = [$row($t, 'probe1a2b3c_20270308T180000Z'), $exdates()];
        $t->google->calls = [];
        $t->undo->undoById($t->u, (int) $deleted['id']);
        checkEq('the delete is a skip; its undo reads the instance, sends {status: confirmed} to it, and the day is back', [
            [null, ['2027-03-08 18:00:00']], ['GET', 'PATCH'], ['status' => 'confirmed'], [], 'Sample standup',
        ], [$gone, array_slice(array_column($t->google->calls, 0), 0, 2), $t->google->calls[1][2] ?? null, $exdates(), $row($t, 'probe1a2b3c_20270308T180000Z')['title'] ?? null]);
    });

    scenario('google undo: a whole-series change stays log-only, refused with its words', function () use ($db, $setup, $row, $last): void {
        $t = $setup();
        $masterId = (int) $row($t, 'probe1a2b3c')['id'];
        $t->events->patch($t->u, $masterId, ['title' => 'Sample standup, new term', 'scope' => 'all']);
        $entry = $last($t);
        $caught = null;
        try {
            $t->undo->undoById($t->u, (int) $entry['id']);
        } catch (HttpError $e) {
            $caught = $e->errorCode;
        }
        checkEq('the series is renamed; its entry keeps no snapshot and no Google before state; undo refuses', [
            'Sample standup, new term', null, null, false, 'not_undoable',
        ], [$row($t, 'probe1a2b3c')['title'], $entry['before_json'], $entry['after_json'],
            isset(json_decode((string) $entry['details_json'], true)['google']), $caught]);
    });
};
