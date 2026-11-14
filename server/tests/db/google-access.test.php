<?php

declare(strict_types=1);

// Google access, detect and inform (docs/design/google-access-detect-and-inform.md). Every expectation follows from the
// model's sentences and from the test's own record of the level it set the stand-in to and when ($said: the level of
// the last list answer the stand-in gave), never from the product:
// - after every poll and every change, the stored level is the level of the last list answer the stand-in gave;
// - a change is sent to Google only if its own first request was a list that answered writer or owner; otherwise it is
//   refused with its sentence, nothing is sent, and nothing held here is lost;
// - Activity has one line per move between tiers (editable, read-only, not shown), and exactly one Review item is open
//   per calendar below writer, dismissible, coming back only when the level moves between tiers again.
// The stand-in answers each level as the access probe of 2026-11-13 saw (tools/google-probe/test.php checks it).
// Invented data.

use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\ReviewQueue;
use BetterCal\Domain\Undo;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $single = ['start' => '2027-03-02T09:00:00-08:00', 'end' => '2027-03-02T10:00:00-08:00', 'tzid' => $la];
    $weekly = ['start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=4'];

    // The requests the stand-in received since $from, as "METHOD what": a list ('GET list'), a read or a write by id.
    $sent = static function (object $t, int $from): array {
        return array_map(static fn(array $r): string => $r['method'] . (str_ends_with($r['path'], '/events') ? ' list' : ' id'), array_slice($t->s->fake->requests, $from));
    };
    $writes = static fn(array $sent): array => array_values(array_filter($sent, static fn(string $s): bool => !str_starts_with($s, 'GET')));
    $stored = static fn(object $t): ?string => $db->scalar('SELECT google_access_role FROM calendars WHERE id = ?', [$t->s->calendarId]);
    $items = static fn(object $t, string $status = 'open'): array => array_column($db->all('SELECT kind FROM review_items WHERE user_id = ? AND source_key = ? AND status = ? ORDER BY id',
        [$t->u, 'google-access:' . $t->s->calendarId, $status]), 'kind');
    // Activity's lines about the calendar's access (a poll's own entries stand on the calendar too).
    $lines = static fn(object $t): array => array_values(array_filter(array_column($db->all("SELECT summary FROM mutations WHERE user_id = ? AND entity = 'calendar' AND entity_id = ? ORDER BY id",
        [$t->u, $t->s->calendarId]), 'summary'), static fn(?string $l): bool => preg_match("/^(Your permissions for|Google no longer shows|Google doesn't show|Google shows|Google now shows|'[^']*' is view only at Google)/", (string) $l) === 1));
    $row = static fn(object $t): ?array => $db->one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [$t->id]);
    $tags = static fn(object $t): array => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [$t->id]), 'name');
    $caught = static function (callable $fn): ?HttpError {
        try {
            $fn();
            return null;
        } catch (HttpError $e) {
            return $e;
        }
    };
    $undo = static fn(object $t): Undo => new Undo($db, null, $t->s->writer);
    $lastEntry = static fn(object $t): int => (int) $db->scalar('SELECT id FROM mutations WHERE user_id = ? AND undone = 0 ORDER BY id DESC LIMIT 1', [$t->u]);

    // ---- A change attempted minutes after access was lowered, before any poll ---------------------------------------

    foreach (['reader' => ['google_read_only', 'reader', 'is read-only: your permissions for it at Google no longer allow changes'],
        'freeBusyReader' => ['google_read_only', 'freeBusyReader', 'is read-only: your permissions for it at Google no longer allow changes'],
        'none' => ['google_not_shown', 'none', 'is read-only: Google no longer shows it to this account']] as $level => [$code, $said, $words]) {
        scenario("google access, lowered to $level before any poll: an edit, a delete and an undo of a single event are refused on their own first read, nothing is sent, nothing held here is lost", function () use ($db, $single, $level, $code, $said, $words, $sent, $writes, $stored, $items, $lines, $row, $tags, $caught, $undo, $lastEntry): void {
            $t = H::make($db, $single);
            try {
                $t->e->patch($t->u, $t->id, ['tagNames' => ['sample-tag']]);
                $t->e->patch($t->u, $t->id, ['title' => 'Sample single, renamed']);
                $entry = $lastEntry($t);
                $before = $row($t);
                $t->s->fake->role = $level;
                foreach (['an edit' => fn() => $t->e->patch($t->u, $t->id, ['title' => 'Sample single, again']),
                    'a delete' => fn() => $t->e->deleteEvent($t->u, $t->id, null, null),
                    'an undo' => fn() => $undo($t)->undoById($t->u, $entry)] as $what => $attempt) {
                    $from = count($t->s->fake->requests);
                    $e = $caught($attempt);
                    $got = $sent($t, $from);
                    checkEq("$level, $what: refused with the access refusal, its first request a list, nothing written", [$code, 403, 'GET list', []],
                        [$e?->errorCode, $e?->status, $got[0] ?? null, $writes($got)]);
                    // The first attempt meets the calendar stored editable, so it says access was lowered; the later ones meet
                    // it stored at the level the first learned, so they say what it is (rule D(2) of the read).
                    $plain = ['reader' => "is view only at Google", 'freeBusyReader' => 'only busy times', 'none' => "doesn't show"][$level];
                    check("$level, $what: the sentence says the calendar is read-only and why", $what === 'an edit'
                        ? str_contains((string) $e?->getMessage(), $words)
                        : str_contains((string) $e?->getMessage(), $plain) && ($what !== 'an undo' || str_starts_with((string) $e?->getMessage(), "This can't be undone")), (string) $e?->getMessage());
                }
                checkEq("$level: the event is here as it was, its tag kept (a delete was not taken for already deleted)", [$before, ['sample-tag']], [$row($t), $tags($t)]);
                checkEq("$level: the stored level is the one the stand-in's last list gave; one Review item; one Activity line", [$said, [$code], 1],
                    [$stored($t), $items($t), count(array_filter($lines($t), static fn(string $l): bool => str_contains($l, match ($level) { 'none' => 'no longer shows', 'freeBusyReader' => 'only busy times', default => 'are now read-only' })))]);
            } finally {
                H::done($t);
            }
        });
    }

    scenario('google access, busy times only: a change to one day and to all events of a repeating event is refused; its series, which Google answers 404 by id, is never read as deleted', function () use ($db, $weekly, $sent, $writes, $stored, $caught, $row): void {
        $t = H::make($db, $weekly);
        try {
            H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own)']);
            $rows = $db->all('SELECT id, title, start_utc FROM events WHERE (id = ? OR recurrence_parent_id = ?) AND deleted_at IS NULL ORDER BY id', [$t->id, $t->id]);
            $t->s->fake->role = 'freeBusyReader';
            foreach (['one day' => fn() => H::this($t, '2027-03-15T10:00:00-07:00', ['title' => 'Sample series (later)']),
                'all events' => fn() => H::change($t, ['scope' => 'all', 'title' => 'Sample series, renamed']),
                'a day deleted' => fn() => H::skip($t, '2027-03-22T10:00:00-07:00')] as $what => $attempt) {
                $from = count($t->s->fake->requests);
                $e = $caught($attempt);
                $got = $sent($t, $from);
                checkEq("busy only, $what: refused as read-only, not as deleted; first request a list; nothing written", ['google_read_only', 'GET list', []],
                    [$e?->errorCode, $got[0] ?? null, $writes($got)]);
            }
            checkEq('busy only: every row of the series kept as it was; stored level freeBusyReader', [$rows, 'freeBusyReader'],
                [$db->all('SELECT id, title, start_utc FROM events WHERE (id = ? OR recurrence_parent_id = ?) AND deleted_at IS NULL ORDER BY id', [$t->id, $t->id]), $stored($t)]);
        } finally {
            H::done($t);
        }
    });

    scenario('google access, a new event on a calendar Google now lets this account only view: the insert is refused, one small list says why, the level is stored', function () use ($db, $single, $sent, $stored, $caught, $items): void {
        $t = H::make($db, $single);
        try {
            $t->s->fake->role = 'reader';
            $from = count($t->s->fake->requests);
            $e = $caught(fn() => $t->e->create($t->u, ['calendarId' => $t->s->calendarId, 'title' => 'Sample new'] + ['start' => '2027-03-04T09:00:00-08:00', 'end' => '2027-03-04T10:00:00-08:00', 'tzid' => 'America/Los_Angeles']));
            checkEq('the insert refused (403 at Google), then one small list, then the access refusal; nothing made here', [['POST list', 'GET list'], 'google_read_only', 0, 'reader', ['google_read_only']],
                [$sent($t, $from), $e?->errorCode, (int) $db->scalar("SELECT COUNT(*) FROM events WHERE calendar_id = ? AND title = 'Sample new'", [$t->s->calendarId]), $stored($t), $items($t)]);
        } finally {
            H::done($t);
        }
    });

    // ---- Access given back, before any poll -------------------------------------------------------------------------

    scenario('google access, given back before any poll: a change on the calendar stored read-only asks Google first, goes through, and closes the Review item', function () use ($db, $single, $sent, $writes, $stored, $items, $lines, $row): void {
        $t = H::make($db, $single);
        try {
            $t->s->fake->role = 'reader';
            $t->s->poll();
            checkEq('the poll stored reader and opened the item', ['reader', ['google_read_only']], [$stored($t), $items($t)]);
            $t->s->fake->role = 'writer';
            $from = count($t->s->fake->requests);
            $t->e->patch($t->u, $t->id, ['title' => 'Sample single, back']);
            $got = $sent($t, $from);
            checkEq('one small list (the recheck), then the change\'s own list and its PATCH; the change landed', [['GET list', 'GET list', 'PATCH id'], 'Sample single, back', 'Sample single, back'],
                [$got, $row($t)['title'] ?? null, $t->s->fake->events[$t->gid]['summary'] ?? null]);
            checkEq('stored writer; no item open, the one there was closed; one line for the drop, one for the return', ['writer', [], ['google_read_only'], 2],
                [$stored($t), $items($t), $items($t, 'gone'), count(array_filter($lines($t), static fn(string $l): bool => str_contains($l, 'at Google')))]);
            // An undo, too, after access comes back: the entry of the change just made.
            $t->s->fake->role = 'reader';
            $t->s->poll();
            $t->s->fake->role = 'writer';
            $from = count($t->s->fake->requests);
            (new Undo($db, null, $t->s->writer))->undoById($t->u, (int) $db->scalar('SELECT id FROM mutations WHERE user_id = ? AND entity = ? AND undone = 0 ORDER BY id DESC LIMIT 1', [$t->u, 'event']));
            checkEq('an undo on the calendar stored read-only: the recheck, then the undo goes through', ['GET list', ['PATCH id'], 'Sample series', 'writer'],
                [$sent($t, $from)[0] ?? null, $writes($sent($t, $from)), $row($t)['title'] ?? null, $stored($t)]);
        } finally {
            H::done($t);
        }
    });

    // ---- Ordinary changes on a writable calendar send no request they did not send before -----------------------------

    // The counts before this change, measured on the fix branch (d32b65b1) with this same scenario: a single event's edit
    // and delete each a read then the write (its read was a GET by id, now the list by its iCalUID); a day's edit a read
    // of the series, a read of the day by id, the write and a list of the series' etags; a change to all events as
    // google-series-request-count counts it (N + 4). The level read costs no request.
    scenario('google access, the request count: an ordinary edit of a single event, of one day and of all events on a writable calendar sends what it sent before', function () use ($db, $single, $weekly, $sent): void {
        $t = H::make($db, $single);
        try {
            $from = count($t->s->fake->requests);
            $t->e->patch($t->u, $t->id, ['title' => 'Sample single, renamed']);
            $edit = $sent($t, $from);
            $from = count($t->s->fake->requests);
            $t->e->deleteEvent($t->u, $t->id, null, null);
            checkEq('a single event: its edit and its delete, two requests each, the read a list', [['GET list', 'PATCH id'], ['GET list', 'DELETE id']], [$edit, $sent($t, $from)]);
        } finally {
            H::done($t);
        }
        $t = H::make($db, $weekly);
        try {
            $from = count($t->s->fake->requests);
            H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own)']);
            $day = $sent($t, $from);
            $from = count($t->s->fake->requests);
            H::change($t, ['scope' => 'all', 'title' => 'Sample series, renamed']);
            checkEq('one day: the series read, the day by id, the write, the etags; all events: as before', [['GET list', 'GET id', 'PATCH id', 'GET list'], 5],
                [$day, count($sent($t, $from))]);
        } finally {
            H::done($t);
        }
    });

    // ---- Busy times only: what Google lists is not applied ------------------------------------------------------------

    // The rule (the model, section 1): a listing below reader is not applied. Expectation: what was held before the level
    // dropped is still held, every column of every event row, until a listing at reader or above comes; then the
    // calendar catches up with what Google holds (a title changed there while the level was low).
    $held = static fn(object $t): array => $db->all('SELECT id, uid, title, description, location, url, start_utc, end_utc, all_day, rrule, exdates_json, status, recurrence_instance_utc
        FROM events WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY id', [$t->s->calendarId]);
    scenario('google access, busy times only: a poll applies nothing Google lists (every row stays as it was, its tags too), stores the level, says events don\'t update; given back, the next poll catches up', function () use ($db, $weekly, $held, $stored, $tags): void {
        $t = H::make($db, $weekly + ['description' => 'Sample notes', 'location' => 'Sample Hall']);
        try {
            H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own)']);
            $t->e->patch($t->u, $t->id, ['scope' => 'all', 'tagNames' => ['sample-tag']]);
            $t->s->poll();
            $before = $held($t);
            $t->s->fake->role = 'freeBusyReader';
            $said = 'freeBusyReader';
            $error = null;
            foreach ([1, 2] as $n) { // the 410 and whole read, then a delta at that level
                try {
                    $t->s->poll();
                } catch (\RuntimeException $e) {
                    $error = $e->getMessage();
                }
            }
            checkEq('busy times only, two polls: every row as it was, its tag kept, the level stored, the poll saying events don\'t update', [$before, ['sample-tag'], $said, true],
                [$held($t), $tags($t), $stored($t), str_contains((string) $error, 'only busy times') && str_contains((string) $error, "don't update")]);
            // Meanwhile at Google, by the calendar's owner: the series renamed (in the stand-in's store, as another account writes).
            $t->s->fake->events[$t->gid]['summary'] = 'Sample series, renamed elsewhere';
            $t->s->fake->role = 'reader';
            $t->s->poll();
            checkEq('view only again: the next poll reads the calendar whole and catches up, the tag kept', ['Sample series, renamed elsewhere', ['sample-tag'], 'reader'],
                [$db->scalar('SELECT title FROM events WHERE id = ?', [$t->id]), $tags($t), $stored($t)]);
        } finally {
            H::done($t);
        }
    });

    scenario('google access, busy times only reached during a change: its read-backs are not stored over what is held', function () use ($db, $weekly, $held, $stored): void {
        $t = H::make($db, $weekly + ['description' => 'Sample notes']);
        try {
            H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own)']);
            $before = array_map(static fn(array $r): array => array_diff_key($r, ['start_utc' => 1, 'end_utc' => 1]), $held($t));
            // Right after the change's first write is taken, the owner lowers the account to busy times only.
            $t->s->knobs->race = static function () use ($t): void {
                $t->s->fake->role = 'freeBusyReader';
            };
            H::tryChange($t, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']);
            $titles = array_column($held($t), 'title');
            checkEq('no row here took a blank title or lost its notes from a read made at busy times only; the level stored', [[], 'freeBusyReader'],
                [array_values(array_filter($held($t), static fn(array $r): bool => in_array((string) $r['title'], ['', '(No title)'], true) || ($r['recurrence_instance_utc'] === null && (string) $r['description'] !== 'Sample notes'))), $stored($t)]);
            check('the titles held before are held still', array_column($before, 'title') === $titles, json_encode([array_column($before, 'title'), $titles]));
        } finally {
            H::done($t);
        }
    });

    // ---- Re-attach stores the level through the one store ----------------------------------------------------------

    scenario('google access, re-attach: a calendar re-attached at a lower level than it had gets its line and its Review item, as a poll would give it', function () use ($db, $single, $stored, $items, $lines): void {
        $t = H::make($db, $single);
        try {
            $account = (int) $db->scalar('SELECT google_account_id FROM calendars WHERE id = ?', [$t->s->calendarId]);
            $db->run('UPDATE calendars SET google_account_id = NULL WHERE id = ?', [$t->s->calendarId]); // as a disconnect leaves it
            $t->d->undo->write($t->u, fn() => $t->d->calendars->reattachGoogle($t->u, $t->s->calendarId, $account, 'reader', 'owner@example.test'));
            checkEq('re-attached at reader: stored, one line, one item', ['reader', 1, ['google_read_only']], [$stored($t), count($lines($t)), $items($t)]);
        } finally {
            H::done($t);
        }
    });

    // ---- What the poll stores ---------------------------------------------------------------------------------------

    scenario('google access, the poll: it stores the level of its own list answers, the 410 after a change of access included; the Review item opens once, can be dismissed, stays dismissed while the level stays, and comes back when the level moves again', function () use ($db, $single, $stored, $items, $lines): void {
        $t = H::make($db, $single);
        try {
            $t->s->poll(); // a sync token, given at owner
            $said = 'reader';
            $t->s->fake->role = $said; // the token from before answers 410 (row 4d); the whole read states the level
            $t->s->poll();
            checkEq('a poll after access was lowered (its delta 410, then the whole read): the level stored, one item, one line', [$said, ['google_read_only'], 1],
                [$stored($t), $items($t), count($lines($t))]);
            $t->s->poll();
            checkEq('another poll at the same level: still one item, no new line', [['google_read_only'], 1], [$items($t), count($lines($t))]);
            $id = (int) $db->scalar("SELECT id FROM review_items WHERE user_id = ? AND status = 'open' AND kind = 'google_read_only'", [$t->u]);
            (new ReviewQueue($db, $t->e))->dismissGoogleDifferences($t->u, $id);
            $t->s->poll();
            checkEq('dismissed; another poll at the same level: nothing opened again, no new line', [[], ['google_read_only'], 1],
                [$items($t), $items($t, 'dismissed'), count($lines($t))]);
            $t->s->fake->role = 'freeBusyReader'; // a state of its own: events stop updating too
            $busy = null;
            try {
                $t->s->poll();
            } catch (\RuntimeException $e) {
                $busy = $e->getMessage();
            }
            checkEq('busy times only: stored, the poll says events stay as they were, a new item (the state changed), a second line', [true, 'freeBusyReader', ['google_read_only'], 2],
                [str_contains((string) $busy, 'only busy times') && str_contains((string) $busy, "don't update"), $stored($t), $items($t), count($lines($t))]);
            $t->s->fake->role = 'none';
            $error = null;
            try {
                $t->s->poll();
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
            checkEq('sharing removed: the poll fails saying so, stores none, opens the not-shown item; a third line', [true, 'none', ['google_not_shown'], 3],
                [str_contains((string) $error, 'no longer shows'), $stored($t), $items($t), count($lines($t))]);
            $t->s->fake->role = 'writer';
            $t->s->poll();
            checkEq('shared again with editing: stored writer, nothing open, a fourth line', ['writer', [], 4], [$stored($t), $items($t), count($lines($t))]);
            $t->s->fake->role = 'reader';
            $t->s->poll();
            checkEq('lowered again: a new item opens', [['google_read_only']], [$items($t)]);
        } finally {
            H::done($t);
        }
    });

    scenario('google access, ordering: an older poll answer never overwrites a newer change\'s read; nothing is stored across the compromise reset', function () use ($db, $single, $stored): void {
        $t = H::make($db, $single);
        try {
            $t->s->fake->role = 'reader';
            $t->s->poll();
            // A poll that fetched at reader, and, after its last page came back and before its write, a change's read that
            // found writer: the change's read is the newest word and stays.
            $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
            $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
                => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
            $list = $t->s->fake->syncTransport();
            $after = null;
            $sync = new GoogleSync($db, $auth, new Feeds($db), static function (string $url, array $headers) use ($list, &$after): array {
                $answer = $list($url, $headers);
                if ($after !== null && !isset(json_decode((string) $answer['body'], true)['nextPageToken'])) {
                    $run = $after;
                    $after = null;
                    $run();
                }
                return $answer;
            });
            $after = static function () use ($t): void {
                $t->s->fake->role = 'writer';
                $t->s->writer->accessNow($t->s->calendar());
            };
            $sync->poll($t->s->calendar());
            checkEq('the stored level is the change\'s (writer), the last list the stand-in answered', 'writer', $stored($t));

            // The compromise reset lands while a change's list is answered (inside its transaction, after the request
            // went): what the list said is not stored.
            $t->s->fake->role = 'reader';
            $account = (int) $db->scalar('SELECT google_account_id FROM calendars WHERE id = ?', [$t->s->calendarId]);
            $db->tx(static function () use ($db, $t, $account): void {
                $t->s->writer->accessNow($t->s->calendar());
                $db->run('UPDATE google_accounts SET reauth_required_at = ? WHERE id = ?', [\BetterCal\Support\Time::nowDb(), $account]);
            });
            checkEq('the reset landed before the level was stored: it stays as it was', 'writer', $stored($t));
        } finally {
            H::done($t);
        }
    });

    // ---- The poll's own checks, Check again, and a stopped change's record (the independent read) -------------------

    // A poll that fetched while the compromise reset landed stores nothing of what it fetched, its level included.
    $syncWith = static function (object $t, \Closure $after) use ($db): GoogleSync {
        $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
        $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
            => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
        $list = $t->s->fake->syncTransport();
        return new GoogleSync($db, $auth, new Feeds($db), static function (string $url, array $headers) use ($list, $after): array {
            return $after($list($url, $headers), $url);
        });
    };
    scenario('google access, the poll\'s account check comes before its store: a reset landing during the fetch stores no level', function () use ($db, $single, $stored, $syncWith): void {
        $t = H::make($db, $single);
        try {
            $t->s->poll();
            $t->s->fake->role = 'reader';
            $account = (int) $db->scalar('SELECT google_account_id FROM calendars WHERE id = ?', [$t->s->calendarId]);
            // The reset lands as the fetch's last page is answered (after the 410 the change of level gave, and the whole read).
            $sync = $syncWith($t, static function (array $answer) use ($db, $account): array {
                $page = json_decode((string) $answer['body'], true);
                if ((int) $answer['status'] === 200 && !isset($page['nextPageToken'])) {
                    $db->run('UPDATE google_accounts SET reauth_required_at = ? WHERE id = ?', [\BetterCal\Support\Time::nowDb(), $account]);
                }
                return $answer;
            });
            try {
                $sync->poll($t->s->calendar());
            } catch (\Throwable) {
            }
            checkEq('the level stays the one stored before the reset', 'owner', $stored($t));
        } finally {
            H::done($t);
        }
    });

    scenario('google access, a list answered 410 with no sync token sent has no live row, so nothing depends on it: no level stored, no item, the poll fails', function () use ($db, $single, $stored, $items, $syncWith): void {
        $t = H::make($db, $single);
        try {
            $db->run('UPDATE calendars SET google_sync_token = NULL WHERE id = ?', [$t->s->calendarId]);
            $sync = $syncWith($t, static fn(array $answer, string $url): array => str_contains($url, 'syncToken=') ? $answer
                : ['status' => 410, 'body' => json_encode(['error' => ['code' => 410, 'message' => 'Gone', 'errors' => [['domain' => 'global', 'reason' => 'deleted']]]])]);
            $error = null;
            try {
                $sync->poll($t->s->calendar());
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
            checkEq('the level as stored before, no item, and the poll fails', ['owner', [], true], [$stored($t), $items($t), $error !== null]);
        } finally {
            H::done($t);
        }
    });

    scenario('google access, Check again: one small list; given back it stores writer and closes the item, still view only it says so and changes nothing else', function () use ($db, $single, $stored, $items, $sent): void {
        $t = H::make($db, $single);
        try {
            $t->s->fake->role = 'reader';
            $t->s->poll();
            $from = count($t->s->fake->requests);
            $still = $t->e->checkGoogleAccess($t->u, $t->s->calendarId);
            checkEq('still view only: one small list, reader, the item still open', [['GET list'], 'reader', 'reader', ['google_read_only']], [$sent($t, $from), $still, $stored($t), $items($t)]);
            $t->s->fake->role = 'writer';
            $from = count($t->s->fake->requests);
            $back = $t->e->checkGoogleAccess($t->u, $t->s->calendarId);
            checkEq('given back: one small list, writer stored, no item open', [['GET list'], 'writer', 'writer', []], [$sent($t, $from), $back, $stored($t), $items($t)]);
        } finally {
            H::done($t);
        }
    });

    scenario('google access, a change stopped at busy times only keeps no read made at that level as what Google held: given back, Put back goes through', function () use ($db, $weekly, $stored, $caught): void {
        $t = H::make($db, $weekly);
        try {
            H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own)']);
            $t->s->knobs->race = static function () use ($t): void {
                $t->s->fake->role = 'freeBusyReader';
            };
            H::tryChange($t, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']);
            $half = (new ReviewQueue($db, $t->e))->googleHalfDone($t->u);
            check('stopped half done', count($half) === 1, json_encode($half));
            $t->s->fake->role = 'writer';
            $e = $caught(function () use ($t, $half): void {
                $t->e->googlePutBack($t->u, (int) $half[0]['id']);
                $t->e->throwDeferred();
            });
            checkEq('given back (asked first), Put back goes through: nothing changed at Google since but its own steps', [null, 'Sample series'],
                [$e === null ? null : $e->errorCode . ': ' . $e->getMessage(), $db->scalar('SELECT title FROM events WHERE id = ?', [$t->id])]);
        } finally {
            H::done($t);
        }
    });

    scenario('google access, health mail: a feed\'s failing polls send no alert while the person has dismissed the notice of that state', function () use ($db, $single): void {
        $t = H::make($db, $single);
        try {
            $t->s->fake->role = 'none';
            $failure = '';
            foreach ([1, 2] as $n) {
                try {
                    $t->s->poll();
                } catch (\RuntimeException $e) {
                    $failure = $e->getMessage(); // what the feed's health records for that poll
                }
            }
            $health = new BetterCal\Domain\SystemHealth($db);
            $row = static function (string $error) use ($db, $t, $health): array {
                $health->recordFailure('feed:' . $t->s->calendarId, 'feed', $t->u, 'Feed: sample', $error);
                return $db->one('SELECT * FROM system_health WHERE subject = ?', ['feed:' . $t->s->calendarId]);
            };
            $before = $health->acknowledged($row($failure));
            $id = (int) $db->scalar("SELECT id FROM review_items WHERE user_id = ? AND status = 'open' AND kind = 'google_not_shown'", [$t->u]);
            (new ReviewQueue($db, $t->e))->dismissNotice($t->u, $id);
            checkEq('open: alerts as before; dismissed: its own failure held, any other failure alerts', [false, true, false],
                [$before, $health->acknowledged($row($failure)), $health->acknowledged($row('Google could not be reached: connection timed out'))]);
        } finally {
            H::done($t);
        }
    });

    // Rule D(1) of the read: whether anything was changed is known from what the change sent. A write Google refuses
    // (403, the small list saying writer: the seconds after access is given back, round 2) after the change's first write
    // was taken: the sentence and Review say part of it reached Google, never that nothing was changed.
    scenario('google access, a write refused part way through a change to all events: neither the sentence nor Review says nothing was changed', function () use ($db, $weekly): void {
        $t = H::make($db, $weekly);
        try {
            H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own)']);
            $t->s->knobs->beforeAt = $t->s->knobs->writes + 2; // the change's second write, after its first was taken
            [, $error] = H::tryChange($t, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']);
            $half = (new ReviewQueue($db, $t->e))->googleHalfDone($t->u);
            checkEq('part of it reached Google, said so; "nothing was changed" said nowhere', [true, false, 1, false],
                [str_contains((string) $error, 'Part of this change reached Google'), str_contains((string) $error, 'nothing was changed'),
                    count($half), str_contains((string) ($half[0]['error'] ?? ''), 'nothing was changed')]);
        } finally {
            H::done($t);
        }
    });

    // ---- A change half done when access was lost ---------------------------------------------------------------------

    scenario('google access, a change to all events half done when access was lost: listed in Review with its sentence and no action, and the event takes no other change', function () use ($db, $weekly, $caught): void {
        $t = H::make($db, $weekly);
        try {
            H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own)']);
            $t->s->knobs->netAt = $t->s->knobs->writes + 2; // its second write goes nowhere: half done
            [, $error] = H::tryChange($t, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']);
            $t->s->knobs->down = false;
            check('the change stopped half done', str_contains((string) $error, 'half done'), (string) $error . ' fired=' . var_export($t->s->knobs->fired, true) . ' writes=' . $t->s->knobs->writes);
            $t->s->fake->role = 'reader';
            $t->s->poll();
            $half = (new ReviewQueue($db, $t->e))->googleHalfDone($t->u);
            check('Review: its sentence says the permissions are read-only and it stays until editing is restored or the calendar is removed',
                count($half) === 1 && str_contains((string) $half[0]['unrecoverable'], 'are now read-only, so it can\'t be finished or put back') && str_contains((string) $half[0]['unrecoverable'], 'or you remove the calendar'),
                json_encode($half[0]['unrecoverable'] ?? null));
            $e = $caught(fn() => H::this($t, '2027-03-22T10:00:00-07:00', ['title' => 'Sample series (later)']));
            check('the event takes no other change while it is listed', $e !== null, '');
            $from = count($t->s->fake->requests);
            $e = $caught(fn() => $t->e->googleFinish($t->u, (int) $half[0]['id']));
            // Rule A of the read: one small list asks Google first; still view only, refused with the same sentence.
            checkEq('Finish asks Google with one small list, is refused with the same sentence, and writes nothing', [true, ['GET']],
                [str_contains((string) $e?->getMessage(), 'are now read-only, so it can\'t be finished or put back'), array_column(array_slice($t->s->fake->requests, $from), 'method')]);
        } finally {
            H::done($t);
        }
    });
};
