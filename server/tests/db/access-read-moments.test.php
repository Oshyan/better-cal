<?php

declare(strict_types=1);

// From the independent read of the access build, d32b65b1..61be6c69): the moments at which the level can change during a
// change, and an answer to an invitation. The stand's requests pass through a hook (access-read-lib.php), so the level
// is lowered at a chosen request: before a change's write (after its read), or before its second write (mid-change).
// Each expectation is the owner's rule ("Sentences must be plain and true for the state the person is in"; "A half-done
// change at that moment stays in Review, frozen"; "detect it and inform the person") or the model
// (docs/design/google-access-detect-and-inform.md, section 4, for what it deliberately leaves). Nothing is computed from
// server/src. Invented data.

use BetterCal\Domain\ReviewQueue;
use BetterCal\Domain\Rsvp;
use BetterCal\Infra\Db;
use BetterCal\Infra\EmailSender;
use BetterCal\Support\Time;

require_once __DIR__ . '/access-read-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $single = ['title' => 'Sample single', 'start' => '2027-03-02T09:00:00-08:00', 'end' => '2027-03-02T10:00:00-08:00', 'tzid' => $la];
    $weekly = ['title' => 'Sample series', 'start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6'];
    $make = static function (array $event) use ($db): object {
        $s = PA::make($db);
        $s->id = (int) $s->events->create($s->u, ['calendarId' => $s->calendarId] + $event)['eventId'];
        $s->gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$s->id]);
        return $s;
    };
    // The level becomes $level just before the writer's $nth write (a request that is not a GET) is sent.
    $lowerAtWrite = static function (object $s, int $nth, string $level): void {
        $writes = 0;
        $s->hook = static function (int $n, string $method, string $url) use ($s, &$writes, $nth, $level): void {
            if ($method !== 'GET' && ++$writes === $nth) {
                $s->fake->role = $level;
            }
        };
    };
    $state = static fn(object $s): array => PA::state($db, $s->u, $s->calendarId, $s->fake);
    $half = static fn(object $s): array => (new ReviewQueue($db, $s->events))->googleHalfDone($s->u);

    // ---- Between a change's read and its write (the model, section 4: "milliseconds") --------------------------------

    foreach (['reader', 'freeBusyReader', 'none'] as $level) {
        scenario("access read, access lowered to $level between an edit's read and its write: nothing here or at Google changes, and the sentence is true", function () use ($db, $make, $single, $lowerAtWrite, $state, $level): void {
            $s = $make($single);
            $s->events->patch($s->u, $s->id, ['tagNames' => ['sample-tag']]);
            $before = $state($s);
            $lowerAtWrite($s, 1, $level);
            $e = PA::caught(fn() => $s->events->patch($s->u, $s->id, ['title' => 'Sample single, renamed']));
            check("$level between read and write, an edit: refused", $e !== null, 'the edit went through');
            checkEq("$level between read and write, an edit: nothing held here or at Google differs", $before, $state($s));
            // The owner: sentences true for the state the person is in. Nothing changed, so "nothing was changed" is true;
            // "changed at Google since you last saw it" is not (the event did not change; the access did).
            check("$level between read and write, an edit: the sentence does not say the event changed at Google or was deleted there",
                !str_contains((string) $e?->getMessage(), 'changed at Google') && !str_contains((string) $e?->getMessage(), 'deleted at Google'), (string) $e?->getMessage());
        });

        scenario("access read, access lowered to $level between a delete's read and its write: the event and what it holds here are kept", function () use ($db, $make, $single, $lowerAtWrite, $state, $level): void {
            $s = $make($single);
            $s->events->patch($s->u, $s->id, ['tagNames' => ['sample-tag']]);
            $before = $state($s);
            $lowerAtWrite($s, 1, $level);
            $e = PA::caught(fn() => $s->events->deleteEvent($s->u, $s->id, null, null));
            // Section 6: "a delete on a calendar no longer visible completed as 'already deleted'" is damage; section 4
            // leaves this moment (the write's own 404 "is read as today's deleted at Google").
            checkEq("$level between read and write, a delete: Google still holds the event, so it is still here with its tag (section 4 leaves this moment at 'none')",
                [true, $before['rows'], $before['tags']], [($s->fake->events[$s->gid]['status'] ?? '') !== 'cancelled', $state($s)['rows'], $state($s)['tags']]);
        });
    }

    // ---- Mid-change: after a change to all events sent its first write -------------------------------------------------

    foreach (['reader', 'freeBusyReader', 'none'] as $level) {
        scenario("access read, access lowered to $level after the first write of a change to all events: the change is listed half done and frozen, the person is told the access changed, and no sentence says nothing was changed", function () use ($db, $make, $weekly, $lowerAtWrite, $state, $half, $level): void {
            $s = $make($weekly);
            $s->events->patch($s->u, $s->id, ['scope' => 'this', 'instanceStart' => '2027-03-08T10:00:00-08:00', 'title' => 'Sample series (own)']);
            $s->events->throwDeferred();
            $s->events->patch($s->u, $s->id, ['scope' => 'this', 'instanceStart' => '2027-03-15T10:00:00-07:00', 'title' => 'Sample series (own two)']);
            $s->events->throwDeferred();
            $rowsBefore = count($state($s)['rows']);
            $lowerAtWrite($s, 2, $level);
            $from = count($s->fake->requests);
            $e = PA::caught(function () use ($s): void {
                planned($s->events, $s->u, $s->id, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']);
                $s->events->throwDeferred();
            });
            $s->hook = null;
            $took = PA::taken($s->fake, $from);
            $listed = $half($s);
            check("$level mid-change: Google took the first write and refused the next (the stand-in's record)", count($took) >= 1 && $e !== null, json_encode([$took, PA::code($e)]));
            if (getenv('PROBE_ACCESS_SAY') !== false) {
                fwrite(STDERR, "[$level mid-change] told: " . $e?->getMessage() . "\n[$level mid-change] Review: " . json_encode($listed[0]['error'] ?? null) . ' rows ' . $rowsBefore . ' -> ' . count($state($s)['rows']) . "\n");
            }
            checkEq("$level mid-change: Review lists one half-done change", 1, count($listed));
            // Nothing read from Google below writer is stored over what is held here: a busy-times-only answer carries
            // no titles (the access probe, stage freebusy, rows 6a, 6c, 6g), and the series' title is not Google's to blank.
            checkEq("$level mid-change: the series here still has its title", 'Sample series', $db->scalar('SELECT title FROM events WHERE id = ? AND deleted_at IS NULL', [$s->id]));
            check("$level mid-change: the sentence the person reads does not say 'nothing was changed' (part of it reached Google)",
                !str_contains((string) $e?->getMessage(), 'nothing was changed'), (string) $e?->getMessage());
            check("$level mid-change: what Review says stopped it does not say 'nothing was changed' either",
                !str_contains((string) ($listed[0]['error'] ?? ''), 'nothing was changed'), (string) ($listed[0]['error'] ?? ''));
            check("$level mid-change: the sentence does not say the event was deleted at Google (it was not)",
                !str_contains((string) $e?->getMessage(), 'deleted at Google'), (string) $e?->getMessage());
            // The person presses Finish, then Put back (Review still offers both until the level is learned).
            foreach (['finish', 'putBack'] as $how) {
                $before = $state($s);
                $from = count($s->fake->requests);
                $r = PA::caught(function () use ($s, $how, $half): void {
                    $id = (int) $half($s)[0]['id'];
                    $how === 'finish' ? $s->events->googleFinish($s->u, $id) : $s->events->googlePutBack($s->u, $id);
                    $s->events->throwDeferred();
                });
                checkEq("$level mid-change, $how: refused, Google took nothing, the change still listed", [true, [], 1], [$r !== null, PA::taken($s->fake, $from), count($half($s))]);
                checkEq("$level mid-change, $how: no row here is removed or changed by the refusal", $before['rows'], $state($s)['rows']);
            }
            $expect = $level === 'none' ? 'google_not_shown' : 'google_read_only';
            checkEq("$level mid-change: after the attempt the level is stored, one Review item says so, and the half-done change has no action and the model's sentence",
                [$level, [$expect], true], [PA::storedRole($db, $s->calendarId), PA::items($db, $s->u, $s->calendarId),
                    str_contains((string) ($half($s)[0]['unrecoverable'] ?? ''), "so it can't be finished or put back")]);
        });
    }

    // Rule B of the read, the record a stopped change keeps: access drops to busy times only as the change's first write
    // goes out and its answer never comes (so Put back must judge by what the change recorded Google would hold). The
    // read the stop makes then is at busy times only and is never kept, neither over the rows nor as that record; given
    // back, Put back reads Google as it is (the write never landed) and goes through, the series as it was.
    scenario('access read, access drops to busy times only as a change\'s write goes unanswered: nothing read at that level is kept, and given back, Put back goes through', function () use ($db, $make, $weekly): void {
        $s = $make($weekly);
        $s->hook = static function (int $n, string $method, string $url) use ($s): void {
            if ($method === 'PATCH') {
                $s->hook = null;
                $s->fake->role = 'freeBusyReader';
                throw new \RuntimeException('HTTP error for the stand-in: the connection dropped as the write went out');
            }
        };
        $e = PA::caught(function () use ($s): void {
            $s->events->patch($s->u, $s->id, ['scope' => 'all', 'title' => 'Sample series, renamed'] + []);
            $s->events->throwDeferred();
        });
        $half = BetterCal\Domain\GoogleSeries::halfDoneOf($db, $s->u);
        check('the change stopped half done', $e !== null && count($half) === 1, PA::code($e) . ' ' . count($half));
        // What the change recorded Google would hold is what it records when its stop can read nothing at all (the
        // network down from the write on): a read at busy times only adds nothing to it.
        $steps = static fn(object $x): array => array_map(static fn(array $st): array => array_diff_key($st, ['at' => 1]),
            json_decode((string) BetterCal\Domain\RequestAuthority::asServer(fn() => $db->journal()->scalar('SELECT steps_json FROM google_pending WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$x->u])), true) ?: []);
        $down = $make($weekly);
        $down->hook = (static function () use ($down): \Closure {
            $off = false;
            return static function (int $n, string $method, string $url) use (&$off): void {
                if ($off || $method === 'PATCH') {
                    $off = true;
                    throw new \RuntimeException('HTTP error for the stand-in: network down');
                }
            };
        })();
        PA::caught(function () use ($down): void {
            $down->events->patch($down->u, $down->id, ['scope' => 'all', 'title' => 'Sample series, renamed']);
            $down->events->throwDeferred();
        });
        checkEq('the stop\'s record is the one a stop that read nothing keeps', $steps($down), $steps($s));
        checkEq('nothing read at busy times only was stored over the series', 'Sample series', $db->scalar('SELECT title FROM events WHERE id = ?', [$s->id]));
        $s->fake->role = 'writer';
        $r = PA::caught(function () use ($s, $half): void {
            $s->events->googlePutBack($s->u, (int) ($half[0]['id'] ?? 0));
            $s->events->throwDeferred();
        });
        checkEq('given back: Put back goes through, the series here and at Google as it was', [null, 'Sample series', 'Sample series', 0],
            [$r === null ? null : PA::code($r) . ': ' . $r->getMessage(), $db->scalar('SELECT title FROM events WHERE id = ?', [$s->id]),
                $s->fake->events[(string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$s->id])]['summary'] ?? null, count(BetterCal\Domain\GoogleSeries::halfDoneOf($db, $s->u))]);
    });

    // ---- After a change's write: its trailing list learns the level ----------------------------------------------------

    scenario('access read, access lowered right after a day\'s edit was taken: the edit stands here and at Google, and the level its trailing list gave is stored and said once', function () use ($db, $make, $weekly): void {
        $s = $make($weekly);
        // A day's edit sends: the series' list, the day by id, the PATCH, the series' list again (access-read-request-counts).
        $s->hook = static function (int $n, string $method, string $url) use ($s): void {
            static $writes = 0;
            if ($method !== 'GET') {
                $writes++;
            } elseif ($writes === 1) {
                $s->fake->role = 'reader';
            }
        };
        $base = $s->n;
        $e = PA::caught(function () use ($s): void {
            $s->events->patch($s->u, $s->id, ['scope' => 'this', 'instanceStart' => '2027-03-08T10:00:00-08:00', 'title' => 'Sample series (own)']);
            $s->events->throwDeferred();
        });
        $s->hook = null;
        $row = $db->one('SELECT title FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$s->id]);
        checkEq('the edit went through and is stored here as Google holds it', [null, 'Sample series (own)'], [PA::code($e), $row['title'] ?? null]);
        checkEq('the level the last list gave is stored; one Review item; one Activity line', ['reader', ['google_read_only'], 1],
            [PA::storedRole($db, $s->calendarId), PA::items($db, $s->u, $s->calendarId), count(PA::lines($db, $s->u, $s->calendarId))]);
    });

    // ---- An answer to an invitation -------------------------------------------------------------------------------------

    $invited = static function (bool $series) use ($db): object {
        $s = PA::make($db);
        $when = static fn(string $local): array => ['dateTime' => (new DateTimeImmutable($local, new DateTimeZone('America/Los_Angeles')))->format('Y-m-d\TH:i:sP'), 'timeZone' => 'America/Los_Angeles'];
        $s->fake->seed(['id' => 'smplinv', 'iCalUID' => 'smplinv@google.com', 'summary' => 'Sample standup', 'creator' => ['email' => 'host@example.test'],
            'start' => $when('2027-01-04 10:00'), 'end' => $when('2027-01-04 10:30'), 'organizer' => ['email' => 'host@example.test'],
            'attendees' => [['email' => 'host@example.test', 'responseStatus' => 'accepted'], ['email' => PA::ACCOUNT, 'self' => true, 'responseStatus' => 'needsAction']]]
            + ($series ? ['recurrence' => ['RRULE:FREQ=DAILY;COUNT=5']] : []));
        ($s->poll)();
        $s->id = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, 'smplinv']);
        $token = bin2hex(random_bytes(16));
        $db->insert('sessions', ['token_hash' => hash('sha256', $token), 'user_id' => $s->u, 'csrf' => 'x', 'authenticated_at' => Time::nowDb(), 'expires_at' => '2030-01-01 00:00:00']);
        $s->session = $token;
        return $s;
    };
    foreach ([false, true] as $series) {
        $kind = $series ? 'a repeating invitation' : 'an invitation';
        scenario("access read, an answer to $kind at each level: refused on its first read below writer with nothing sent and nothing changed; through with no extra request when Google says writer", function () use ($db, $invited, $state, $series, $kind): void {
            $s = $invited($series);
            $from = count($s->fake->requests);
            (new Rsvp($db, new EmailSender([]), [], $s->writer))->answer($s->u, $s->id, 'accepted', $s->session);
            $normal = PA::sent($s->fake, $from);
            checkEq("an answer to $kind, writer: its first request is a list and no small list is asked", ['GET list', 0],
                [$normal[0] ?? null, count(array_filter($normal, static fn(string $r): bool => $r === 'GET small'))]);
            foreach (['reader' => 'google_read_only', 'freeBusyReader' => 'google_read_only', 'none' => 'google_not_shown'] as $level => $code) {
                $s = $invited($series);
                $before = $state($s);
                $s->fake->role = $level;
                $from = count($s->fake->requests);
                $e = PA::caught(fn() => (new Rsvp($db, new EmailSender([]), [], $s->writer))->answer($s->u, $s->id, 'accepted', $s->session));
                checkEq("an answer to $kind, $level before any poll: refused by the access rule, its first request a list, nothing taken", [$code, 'GET list', []],
                    [PA::code($e), PA::sent($s->fake, $from)[0] ?? null, PA::taken($s->fake, $from)]);
                checkEq("an answer to $kind, $level: nothing held here or at Google differs", $before, $state($s));
                checkEq("an answer to $kind, $level: the level stored, one Review item", [$level, [$code]], [PA::storedRole($db, $s->calendarId), PA::items($db, $s->u, $s->calendarId)]);
                // Given back: the answer, attempted on the calendar stored below writer, goes through (the owner's rule).
                $s->fake->role = 'writer';
                $from = count($s->fake->requests);
                $e = PA::caught(fn() => (new Rsvp($db, new EmailSender([]), [], $s->writer))->answer($s->u, $s->id, 'accepted', $s->session));
                checkEq("an answer to $kind, given back after $level: goes through, the level stored writer, the item closed", [null, 'writer', []],
                    [PA::code($e) === null ? null : PA::code($e) . ': ' . $e->getMessage(), PA::storedRole($db, $s->calendarId), PA::items($db, $s->u, $s->calendarId)]);
            }
        });
    }
};
