<?php

declare(strict_types=1);

// Google calendars' state and write discipline (#172, stage one): the etag
// per row from polls and writes, If-Match on every PATCH and DELETE, a 412
// shown as 409 google_conflict with nothing written, the series' etags
// read back after a write to one of its rows, the delta rule for skipped
// days (probe 7.2), a 410 re-listing everything, and a poll's Activity
// entry under the source google. Google is a stand-in replaying invented
// answers shaped like the probe's read-backs.

use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
    $uid = 'probe1a2b3c@google.com';
    $tz = 'America/Los_Angeles';

    // Invented resources shaped like the probe's: a weekly series, one moved
    // occurrence (8 March) and one skipped (15 March, a cancelled instance).
    $master = static fn(string $etag, string $title = 'Sample standup'): array => [
        'kind' => 'calendar#event', 'id' => 'probe1a2b3c', 'iCalUID' => $uid, 'etag' => $etag, 'status' => 'confirmed', 'summary' => $title,
        'start' => ['dateTime' => '2027-03-01T10:00:00-08:00', 'timeZone' => $tz], 'end' => ['dateTime' => '2027-03-01T10:30:00-08:00', 'timeZone' => $tz],
        'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6'],
    ];
    $moved = static fn(string $etag, string $title = 'Sample standup (moved)'): array => [
        'kind' => 'calendar#event', 'id' => 'probe1a2b3c_20270308T180000Z', 'iCalUID' => $uid, 'etag' => $etag, 'status' => 'confirmed', 'summary' => $title,
        'recurringEventId' => 'probe1a2b3c', 'originalStartTime' => ['dateTime' => '2027-03-08T10:00:00-08:00', 'timeZone' => $tz],
        'start' => ['dateTime' => '2027-03-09T10:00:00-08:00', 'timeZone' => $tz], 'end' => ['dateTime' => '2027-03-09T10:30:00-08:00', 'timeZone' => $tz],
    ];
    $cancelled = static fn(string $etag): array => [
        'kind' => 'calendar#event', 'id' => 'probe1a2b3c_20270315T170000Z', 'iCalUID' => $uid, 'etag' => $etag, 'status' => 'cancelled',
        'recurringEventId' => 'probe1a2b3c', 'originalStartTime' => ['dateTime' => '2027-03-15T10:00:00-07:00', 'timeZone' => $tz],
    ];
    $single = static fn(string $etag, string $title = 'Sample review'): array => [
        'kind' => 'calendar#event', 'id' => 'probe9z8y7x', 'iCalUID' => 'probe9z8y7x@google.com', 'etag' => $etag, 'status' => 'confirmed', 'summary' => $title,
        'start' => ['dateTime' => '2027-03-03T14:00:00-08:00', 'timeZone' => $tz], 'end' => ['dateTime' => '2027-03-03T15:00:00-08:00', 'timeZone' => $tz],
    ];

    // One account, one Google calendar, the writer, the poll and Events,
    // each with a stand-in: $google->writes answers GoogleWriter's calls,
    // $google->pages the poll's list requests, and both record what came.
    $setup = static function () use ($db, $cfg): object {
        $u = freshUser($db);
        $d = domains($db);
        $google = new class {
            /** @var list<array{0:string,1:string,2:?array,3:array}> */
            public array $calls = [];
            /** @var ?\Closure fn(string $method, string $url, ?array $payload, array $headers): array */
            public ?\Closure $writes = null;
            /** @var ?\Closure fn(array $query): array */
            public ?\Closure $pages = null;
        };
        $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
            => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
        $feeds = new Feeds($db);
        $writer = new GoogleWriter($db, $auth, $feeds, static function (string $method, string $url, ?array $payload, array $headers = []) use ($google): array {
            $google->calls[] = [$method, $url, $payload, $headers];
            return ($google->writes)($method, $url, $payload, $headers);
        });
        $sync = new GoogleSync($db, $auth, $feeds, static function (string $url, array $headers) use ($google): array {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $google->calls[] = ['LIST', $url, null, $headers];
            return ($google->pages)($query);
        });
        $events = new Events($db, new BetterCal\Domain\Recurrence(), $d->undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $d->undo, new BetterCal\Infra\JobQueue($db)), new BetterCal\Domain\Trips($db, $d->undo), null, $writer);
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $calendarId = $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample team', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'team@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        return (object) ['u' => $u, 'google' => $google, 'writer' => $writer, 'sync' => $sync, 'events' => $events, 'calendarId' => $calendarId];
    };
    $calendar = static fn(object $t): array => $db->one('SELECT * FROM calendars WHERE id = ?', [$t->calendarId]);
    $page = static fn(array $items, string $token): array => ['status' => 200, 'body' => json_encode(['kind' => 'calendar#events', 'items' => $items, 'nextSyncToken' => $token])];
    $row = static fn(object $t, string $googleId): ?array => $db->one('SELECT * FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$t->calendarId, $googleId]);
    $etags = static fn(object $t): array => array_column($db->all('SELECT google_event_id, google_etag FROM events WHERE calendar_id = ? ORDER BY google_event_id', [$t->calendarId]), 'google_etag', 'google_event_id');
    $exdates = static fn(object $t): array => json_decode((string) ($db->scalar('SELECT exdates_json FROM events WHERE calendar_id = ? AND google_event_id = ?', [$t->calendarId, 'probe1a2b3c']) ?? '[]'), true);
    $headerOf = static function (array $headers, string $name): ?string {
        foreach ($headers as $h) {
            if (stripos($h, $name . ':') === 0) {
                return trim(substr($h, strlen($name) + 1));
            }
        }
        return null;
    };
    // The first poll: the whole calendar, the series and a single event.
    $firstPoll = static function (object $t) use ($calendar, $page, $master, $moved, $cancelled, $single): void {
        $t->google->pages = static fn(array $q): array => $page([$master('"e1"'), $moved('"e1"'), $cancelled('"e1"'), $single('"s1"')], 'sync-1');
        $t->sync->poll($calendar($t));
    };

    scenario('google etag: a poll stores the etag on the master, on each exception and on a single event; a new etag alone changes nothing else', function () use ($db, $setup, $firstPoll, $calendar, $page, $master, $moved, $etags, $exdates): void {
        $t = $setup();
        $firstPoll($t);
        checkEq('the first poll keeps every row\'s etag and the cancelled day as a skip', [
            ['probe1a2b3c' => '"e1"', 'probe1a2b3c_20270308T180000Z' => '"e1"', 'probe9z8y7x' => '"s1"'], ['2027-03-15 17:00:00'],
        ], [$etags($t), $exdates($t)]);
        $before = $db->all('SELECT id, title, updated_at FROM events WHERE calendar_id = ? ORDER BY id', [$t->calendarId]);
        $entries = (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$t->u]);
        $synctoken = (int) $db->scalar('SELECT synctoken FROM calendars WHERE id = ?', [$t->calendarId]);
        // The series changed at Google in a way that moved only its etags.
        $t->google->pages = static fn(array $q): array => $page([$master('"e2"'), $moved('"e2"')], 'sync-2');
        $t->sync->poll($calendar($t));
        checkEq('a delta with new etags only stores them: no content, updated_at, Activity entry or CalDAV change', [
            ['probe1a2b3c' => '"e2"', 'probe1a2b3c_20270308T180000Z' => '"e2"', 'probe9z8y7x' => '"s1"'], $before, $entries, $synctoken,
        ], [
            $etags($t), $db->all('SELECT id, title, updated_at FROM events WHERE calendar_id = ? ORDER BY id', [$t->calendarId]),
            (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$t->u]), (int) $db->scalar('SELECT synctoken FROM calendars WHERE id = ?', [$t->calendarId]),
        ]);
    });

    scenario('google poll: its Activity entry carries the source google and says the change came from Google', function () use ($db, $setup, $firstPoll, $calendar, $page, $master): void {
        $t = $setup();
        $firstPoll($t);
        $first = $db->one('SELECT source, summary FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$t->u]);
        $t->google->pages = static fn(array $q): array => $page([$master('"e2"', 'Sample standup, new room')], 'sync-2');
        $t->sync->poll($calendar($t));
        $second = $db->one('SELECT source, summary FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$t->u]);
        $listed = (new BetterCal\Domain\Activity($db))->list($t->u, ['sources' => ['google']])['entries'];
        checkEq('both polls are entries under google, worded as from Google, and the source filter finds them', [
            ['source' => 'google', 'summary' => "From Google calendar 'Sample team': 3 added"],
            ['source' => 'google', 'summary' => "From Google calendar 'Sample team': 1 updated"],
            2,
        ], [$first, $second, count($listed)]);
    });

    scenario('google write: If-Match carries the stored etag, the answer\'s etag is stored and the series\' other etags are read back', function () use ($db, $setup, $firstPoll, $moved, $master, $etags, $row, $headerOf): void {
        $t = $setup();
        $firstPoll($t);
        $t->google->calls = [];
        $t->google->writes = static function (string $method, string $url, ?array $payload, array $headers) use ($moved, $master): array {
            if ($method === 'PATCH') {
                return ['status' => 200, 'body' => json_encode($moved('"e3"', (string) ($payload['summary'] ?? '')))];
            }
            // The series' list, filtered to its UID: every live row's new etag.
            return ['status' => 200, 'body' => json_encode(['items' => [$master('"e3"'), $moved('"e3"')]])];
        };
        $masterId = (int) $row($t, 'probe1a2b3c')['id'];
        $t->events->patch($t->u, $masterId, ['title' => 'Sample standup, guest', 'scope' => 'this', 'instanceStart' => '2027-03-08T18:00:00+00:00']);
        [$patch, $list] = $t->google->calls + [null, null];
        parse_str((string) parse_url((string) ($list[1] ?? ''), PHP_URL_QUERY), $listQuery);
        checkEq('one PATCH with If-Match "e1" on the occurrence, then one list of the series (singleEvents=false, by UID, no events.instances)', [
            ['PATCH', '"e1"'], 2, ['GET', 'probe1a2b3c@google.com', 'false', false],
        ], [
            [$patch[0] ?? null, $headerOf($patch[3] ?? [], 'If-Match')], count($t->google->calls),
            [$list[0] ?? null, $listQuery['iCalUID'] ?? null, $listQuery['singleEvents'] ?? null, str_contains((string) ($list[1] ?? ''), '/instances')],
        ]);
        checkEq('the occurrence holds its new title and the answer\'s etag; the master took its new etag from the read-back; the single event is untouched', [
            'Sample standup, guest', ['probe1a2b3c' => '"e3"', 'probe1a2b3c_20270308T180000Z' => '"e3"', 'probe9z8y7x' => '"s1"'],
        ], [$row($t, 'probe1a2b3c_20270308T180000Z')['title'], $etags($t)]);
    });

    scenario('google write: a row with no stored etag is sent without If-Match and keeps what comes back; a single event is not read back', function () use ($db, $setup, $firstPoll, $single, $etags, $row, $headerOf): void {
        $t = $setup();
        $firstPoll($t);
        // As a row synced before this release: no etag yet.
        $db->run('UPDATE events SET google_etag = NULL WHERE calendar_id = ? AND google_event_id = ?', [$t->calendarId, 'probe9z8y7x']);
        $t->google->calls = [];
        $t->google->writes = static fn(string $method, string $url, ?array $payload, array $headers): array
            => ['status' => 200, 'body' => json_encode($single('"s2"', (string) ($payload['summary'] ?? '')))];
        $t->events->patch($t->u, (int) $row($t, 'probe9z8y7x')['id'], ['title' => 'Sample review, moved room']);
        checkEq('one PATCH, no If-Match, the answer\'s etag stored', [['PATCH'], null, '"s2"', 'Sample review, moved room'], [
            array_column($t->google->calls, 0), $headerOf($t->google->calls[0][3] ?? [], 'If-Match'), $etags($t)['probe9z8y7x'], $row($t, 'probe9z8y7x')['title'],
        ]);
        // The next write is conditional on that etag.
        $t->google->calls = [];
        $t->events->deleteEvent($t->u, (int) $row($t, 'probe9z8y7x')['id'], null, null);
        checkEq('the delete that follows carries If-Match "s2"', ['DELETE', '"s2"'], [$t->google->calls[0][0] ?? null, $headerOf($t->google->calls[0][3] ?? [], 'If-Match')]);
    });

    scenario('google write: a 412 is 409 google_conflict with Google\'s words, and nothing is written here', function () use ($db, $setup, $firstPoll, $row): void {
        $t = $setup();
        $firstPoll($t);
        $t->google->writes = static fn(string $method, string $url, ?array $payload, array $headers): array
            => ['status' => 412, 'body' => json_encode(['error' => ['code' => 412, 'message' => 'Precondition Failed']])];
        $state = static fn(): array => [
            $db->all('SELECT * FROM events WHERE calendar_id = ? ORDER BY id', [$t->calendarId]),
            (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$t->u]),
            (int) $db->scalar('SELECT synctoken FROM calendars WHERE id = ?', [$t->calendarId]),
        ];
        $before = $state();
        $caught = [];
        foreach ([
            static fn() => $t->events->patch($t->u, (int) $row($t, 'probe9z8y7x')['id'], ['title' => 'Sample review, renamed']),
            static fn() => $t->events->patch($t->u, (int) $row($t, 'probe1a2b3c')['id'], ['title' => 'One day', 'scope' => 'this', 'instanceStart' => '2027-03-08T18:00:00+00:00']),
            static fn() => $t->events->deleteEvent($t->u, (int) $row($t, 'probe1a2b3c_20270308T180000Z')['id'], null, null),
        ] as $call) {
            try {
                $call();
                $caught[] = 'no error';
            } catch (HttpError $e) {
                $caught[] = $e->status . ' ' . $e->errorCode . ': ' . $e->getMessage();
            }
        }
        $words = '409 google_conflict: This event changed at Google since you last saw it. Refresh and try again.';
        checkEq('an edit, an occurrence edit and a delete each answer the conflict; rows, Activity and CalDAV untouched', [[$words, $words, $words], $before], [$caught, $state()]);
    });

    scenario('google delta: a changed master keeps the exceptions it did not carry and every skip it did not contradict (probe 7.2)', function () use ($db, $setup, $firstPoll, $calendar, $page, $master, $moved, $cancelled, $row, $exdates): void {
        $t = $setup();
        $firstPoll($t);
        // A master renamed at Google; this delta re-sends neither the moved
        // occurrence nor the cancelled one.
        $t->google->pages = static fn(array $q): array => $page([$master('"e2"', 'Sample standup renamed')], 'sync-2');
        $t->sync->poll($calendar($t));
        checkEq('the master is renamed; the moved occurrence and the skipped day stay', ['Sample standup renamed', 'Sample standup (moved)', ['2027-03-15 17:00:00']], [
            $row($t, 'probe1a2b3c')['title'], $row($t, 'probe1a2b3c_20270308T180000Z')['title'] ?? null, $exdates($t),
        ]);
        // As Google does after a master change: the cancelled instance again.
        $t->google->pages = static fn(array $q): array => $page([$master('"e3"', 'Sample standup again'), $moved('"e3"'), $cancelled('"e3"')], 'sync-3');
        $t->sync->poll($calendar($t));
        checkEq('a cancelled instance re-sent with the master is still one skip, not a day back', ['Sample standup again', ['2027-03-15 17:00:00']], [$row($t, 'probe1a2b3c')['title'], $exdates($t)]);
        // Put back at Google (a PATCH to confirmed): the delta brings it live.
        $back = ['status' => 'confirmed', 'summary' => 'Sample standup again', 'etag' => '"e4"',
            'start' => ['dateTime' => '2027-03-15T10:00:00-07:00', 'timeZone' => 'America/Los_Angeles'], 'end' => ['dateTime' => '2027-03-15T10:30:00-07:00', 'timeZone' => 'America/Los_Angeles']] + $cancelled('"e4"');
        $t->google->pages = static fn(array $q): array => $page([$master('"e4"', 'Sample standup again'), $back], 'sync-4');
        $t->sync->poll($calendar($t));
        // Back at the series' own values, it is an ordinary occurrence again: Google's record for it makes no row here.
        checkEq('a day the delta brings back live is no longer skipped, and is an ordinary occurrence, no row of its own', [[], null], [$exdates($t), $row($t, 'probe1a2b3c_20270315T170000Z')['title'] ?? null]);
    });

    scenario('google 410: an expired sync token lists everything again and replaces the calendar\'s rows whole', function () use ($db, $setup, $firstPoll, $calendar, $master, $etags, $exdates): void {
        $t = $setup();
        $firstPoll($t);
        $asked = [];
        $t->google->pages = static function (array $q) use (&$asked, $master): array {
            $asked[] = isset($q['syncToken']) ? 'delta' : 'full';
            if (isset($q['syncToken'])) {
                return ['status' => 410, 'body' => json_encode(['error' => ['code' => 410, 'message' => 'Sync token is no longer valid']])];
            }
            // The calendar as Google has it now: the series alone, renamed,
            // its moved and skipped days gone; the single event deleted.
            return ['status' => 200, 'body' => json_encode(['items' => [$master('"e9"', 'Sample standup, new term')], 'nextSyncToken' => 'sync-fresh'])];
        };
        $t->sync->poll($calendar($t));
        checkEq('a delta refused, then one full list; only the series is left, renamed, with no skip, its etag stored and the new token kept', [
            ['delta', 'full'], [['probe1a2b3c', 'Sample standup, new term']], ['probe1a2b3c' => '"e9"'], [], 'sync-fresh',
        ], [
            $asked,
            array_map(static fn(array $r): array => [$r['google_event_id'], $r['title']], $db->all('SELECT google_event_id, title FROM events WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY id', [$t->calendarId])),
            $etags($t), $exdates($t), $db->scalar('SELECT google_sync_token FROM calendars WHERE id = ?', [$t->calendarId]),
        ]);
    });
};
