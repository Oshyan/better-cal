<?php

declare(strict_types=1);

// From the independent read of the access build, d32b65b1..61be6c69): every thing a person can do to an event on a Google
// calendar, at every level Google can give the account, compared by state. Each expectation is a sentence of the model
// (docs/design/google-access-detect-and-inform.md) or the owner's rule, and is computed from this file's own record of
// the level it set the stand-in to, never from server/src:
// - section 1: "A change to a Google calendar is sent only when the access level Google states on a list answer, read
//   in that change's own first request, is writer or owner."
// - section 6: "Otherwise it is refused with its sentence, nothing is sent, and nothing held here is lost"; "after every
//   poll and every change, the stored level is the level of the last list answer the stand-in gave"; "exactly one Review
//   item is open per calendar below writer"; "Activity has one line per move between tiers".
// - section 2, last row: a change or an undo on a calendar stored below writer sends "one small list first
//   (maxResults=1). If it says writer, the change goes on as normal": 1 extra request, only on such a calendar.
// - section 3: the two refusal sentences, a change's and an undo's.
// - the owner: "A change or an undo attempted on a read-only calendar asks Google first and goes through if access is
//   back."
// The stand-in answers each level as the access probe of 2026-11-13 saw (tools/google-probe/test.php). Invented data.

use BetterCal\Domain\Undo;
use BetterCal\Infra\Db;

require_once __DIR__ . '/access-read-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $single = ['start' => '2027-03-02T09:00:00-08:00', 'end' => '2027-03-02T10:00:00-08:00', 'tzid' => $la];
    $weekly = ['start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6'];
    $last = static fn(object $t): int => (int) $db->scalar('SELECT id FROM mutations WHERE user_id = ? AND undone = 0 ORDER BY id DESC LIMIT 1', [$t->u]);
    $undo = static fn(object $t, int $entry) => (new Undo($db, null, $t->s->writer))->undoById($t->u, $entry);

    // A fixture: the event(s), with something held here alone on them, and the entries an undo case takes back.
    $fixtures = [
        'single' => static function () use ($db, $single, $la, $last): object {
            $t = H::make($db, $single);
            $t->e->patch($t->u, $t->id, ['tagNames' => ['sample-tag'], 'reminders' => [['minutes' => 30]]]);
            $t->e->patch($t->u, $t->id, ['title' => 'Sample single, renamed']);
            $t->edit = $last($t);
            $t->other = (int) $t->e->create($t->u, ['calendarId' => $t->s->calendarId, 'title' => 'Sample other', 'start' => '2027-03-04T09:00:00-08:00', 'end' => '2027-03-04T10:00:00-08:00', 'tzid' => $la])['eventId'];
            $t->create = $last($t);
            $third = (int) $t->e->create($t->u, ['calendarId' => $t->s->calendarId, 'title' => 'Sample third', 'start' => '2027-03-05T09:00:00-08:00', 'end' => '2027-03-05T10:00:00-08:00', 'tzid' => $la])['eventId'];
            $t->e->patch($t->u, $third, ['tagNames' => ['sample-tag']]);
            $t->e->deleteEvent($t->u, $third, null, null);
            $t->delete = $last($t);
            return $t;
        },
        'weekly' => static function () use ($db, $weekly, $last): object {
            $t = H::make($db, $weekly);
            $t->e->patch($t->u, $t->id, ['scope' => 'all', 'tagNames' => ['sample-tag']]);
            H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own)']);
            $t->dayEdit = $last($t);
            $t->dayRow = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$t->id]);
            H::skip($t, '2027-04-05T10:00:00-07:00');
            $t->dayDelete = $last($t);
            return $t;
        },
        'half' => static function () use ($db, $weekly): object {
            $t = H::make($db, $weekly);
            H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own)']);
            $t->s->knobs->netAt = $t->s->knobs->writes + 2; // the change's second write goes nowhere: half done
            H::tryChange($t, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']);
            $t->s->knobs->down = false;
            return $t;
        },
    ];
    $recover = static function (object $t, string $how): void {
        $listed = H::halfDone($t);
        $how === 'finish' ? $t->e->googleFinish($t->u, (int) $listed[0]['id']) : $t->e->googlePutBack($t->u, (int) $listed[0]['id']);
        $t->e->throwDeferred();
    };
    // name => [fixture, the attempt, 'change' | 'undo' | 'insert' | 'recover']
    $cases = [
        'a new event' => ['single', fn(object $t) => $t->e->create($t->u, ['calendarId' => $t->s->calendarId, 'title' => 'Sample new', 'start' => '2027-03-06T09:00:00-08:00', 'end' => '2027-03-06T10:00:00-08:00', 'tzid' => $la]), 'insert'],
        'a new repeating event' => ['single', fn(object $t) => $t->e->create($t->u, ['calendarId' => $t->s->calendarId, 'title' => 'Sample new series', 'start' => '2027-03-06T09:00:00-08:00', 'end' => '2027-03-06T10:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=3']), 'insert'],
        'an edit of an event' => ['single', fn(object $t) => $t->e->patch($t->u, $t->id, ['title' => 'Sample single, again']), 'change'],
        'a drag of an event' => ['single', fn(object $t) => $t->e->patch($t->u, $t->id, ['start' => '2027-03-02T11:00:00-08:00', 'end' => '2027-03-02T12:00:00-08:00']), 'change'],
        'a delete of an event' => ['single', fn(object $t) => $t->e->deleteEvent($t->u, $t->id, null, null), 'change'],
        'an undo of an edit' => ['single', fn(object $t) => $undo($t, $t->edit), 'undo'],
        'an undo of a new event' => ['single', fn(object $t) => $undo($t, $t->create), 'undo'],
        'an undo of a delete' => ['single', fn(object $t) => $undo($t, $t->delete), 'undo'],
        'an edit of a day with no record' => ['weekly', fn(object $t) => H::this($t, '2027-03-15T10:00:00-07:00', ['title' => 'Sample series (later)']), 'change'],
        'an edit of an edited day' => ['weekly', fn(object $t) => H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own, again)']), 'change'],
        'a drag of a day' => ['weekly', fn(object $t) => H::this($t, '2027-03-22T10:00:00-07:00', ['start' => '2027-03-22T12:00:00-07:00', 'end' => '2027-03-22T13:00:00-07:00']), 'change'],
        'use series value on an edited day' => ['weekly', fn(object $t) => $t->e->patch($t->u, $t->dayRow, ['useSeries' => ['title']]), 'change'],
        'a delete of a day with no record' => ['weekly', fn(object $t) => H::skip($t, '2027-03-22T10:00:00-07:00'), 'change'],
        'a delete of an edited day' => ['weekly', fn(object $t) => $t->e->deleteEvent($t->u, $t->dayRow, null, null), 'change'],
        'a change to all events (title)' => ['weekly', fn(object $t) => H::change($t, ['scope' => 'all', 'title' => 'Sample series, renamed']), 'change'],
        'a change to all events (time)' => ['weekly', fn(object $t) => H::change($t, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']), 'change'],
        'this and following (title)' => ['weekly', fn(object $t) => H::change($t, ['scope' => 'following', 'instanceStart' => '2027-03-22T10:00:00-07:00', 'title' => 'Sample series, later part']), 'change'],
        'a delete of this and following' => ['weekly', function (object $t): void {
            plannedDelete($t->e, $t->u, $t->id, '2027-03-15T10:00:00-07:00');
            $t->e->throwDeferred();
        }, 'change'],
        'a delete of all events' => ['weekly', function (object $t): void {
            $t->e->deleteEvent($t->u, $t->id, 'all', null);
            $t->e->throwDeferred();
        }, 'change'],
        'an undo of a day\'s edit' => ['weekly', fn(object $t) => $undo($t, $t->dayEdit), 'undo'],
        'an undo of a day\'s delete' => ['weekly', fn(object $t) => $undo($t, $t->dayDelete), 'undo'],
        'Finish of a half-done change' => ['half', fn(object $t) => $recover($t, 'finish'), 'recover'],
        'Put back of a half-done change' => ['half', fn(object $t) => $recover($t, 'putBack'), 'recover'],
    ];
    $name = "'Sample Google calendar'";
    $sentence = static fn(string $level, bool $undo): string => ($undo ? "This can't be undone while $name is read-only: " : "$name is read-only: ")
        . 'your permissions for it at Google no longer allow changes. If you have access, restore full editing there.';
    $state = static fn(object $t): array => PA::state($db, $t->u, $t->s->calendarId, $t->s->fake);
    $role = static fn(object $t): ?string => PA::storedRole($db, $t->s->calendarId);
    $items = static fn(object $t, string $status = 'open'): array => PA::items($db, $t->u, $t->s->calendarId, $status);
    $lines = static fn(object $t): array => PA::lines($db, $t->u, $t->s->calendarId);

    // ---- 1. The level lowered or taken away at Google, before any poll: the calendar still stored as editable ---------

    foreach (['reader' => 'google_read_only', 'freeBusyReader' => 'google_read_only', 'none' => 'google_not_shown'] as $level => $code) {
        scenario("access read, access $level before any poll: each thing a person can do is refused on its own first read, nothing is sent, nothing here or at Google differs, and the person is told once", function () use ($db, $fixtures, $cases, $level, $code, $sentence, $state, $role, $items, $lines): void {
            foreach ($cases as $what => [$fixture, $attempt, $kind]) {
                $t = $fixtures[$fixture]();
                try {
                    $before = $state($t);
                    $t->s->fake->role = $level;
                    $from = count($t->s->fake->requests);
                    $e = PA::caught(fn() => $attempt($t));
                    $sent = PA::sent($t->s->fake, $from);
                    // An insert's first request is the insert itself (section 2): refused by Google, then one small list says why.
                    $first = $kind === 'insert' ? ['POST list', 'GET small'] : ['GET list'];
                    checkEq("$level, $what: refused with the access refusal (403), nothing taken by Google", [$code, []],
                        [PA::code($e), PA::taken($t->s->fake, $from)]);
                    checkEq("$level, $what: its first request is a list (an insert: the insert, then one small list)", $first, array_slice($sent, 0, count($first)));
                    checkEq("$level, $what: nothing held here or at Google differs (rows, tags, half-done changes, Activity's entries, the stand-in's store)", $before, $state($t));
                    if ($level !== 'none') {
                        checkEq("$level, $what: the model's sentence, word for word", $sentence($level, $kind === 'undo'), $e?->getMessage());
                    } else {
                        check("$level, $what: the sentence says Google no longer shows the calendar" . ($kind === 'undo' ? ', as an undo\'s refusal' : ''),
                            str_contains((string) $e?->getMessage(), 'Google no longer shows') && ($kind !== 'undo' || str_starts_with((string) $e?->getMessage(), "This can't be undone while")), (string) $e?->getMessage());
                    }
                    checkEq("$level, $what: the stored level is the one the stand-in's last list gave; one Review item open; one Activity line", [$level, [$code], 1],
                        [$role($t), $items($t), count($lines($t))]);
                } finally {
                    H::done($t);
                }
            }
        });
    }

    // ---- 2. Stored below writer (a poll learned it), and Google now says writer again, before any poll ----------------

    foreach (['reader', 'none'] as $level) {
        scenario("access read, access given back after $level, before any poll: each attempt asks Google with one small list, goes through, and the Review item closes", function () use ($db, $fixtures, $cases, $level, $role, $items, $lines): void {
            foreach ($cases as $what => [$fixture, $attempt, $kind]) {
                $t = $fixtures[$fixture]();
                try {
                    $t->s->fake->role = $level;
                    PA::caught(fn() => $t->s->poll());
                    $open = $items($t);
                    $t->s->fake->role = 'writer';
                    $from = count($t->s->fake->requests);
                    $e = PA::caught(fn() => $attempt($t));
                    $sent = PA::sent($t->s->fake, $from);
                    checkEq("given back after $level, $what: the poll had stored the level and opened one item", [1], [count($open)]);
                    if ($kind === 'recover') {
                        // One check for the pair. The sentence it is refused with says "until full editing is restored at
                        // Google", and editing is restored; the owner's rule is that an attempt asks Google first.
                        checkEq("given back after $level, $what: asks Google and goes through (the owner: 'asks Google first and goes through if access is back'; its refusal says 'until full editing is restored at Google', which it is)",
                            [null, true], [PA::code($e) === null ? null : PA::code($e) . ': ' . $e->getMessage(), PA::taken($t->s->fake, $from) !== []]);
                        continue;
                    }
                    checkEq("given back after $level, $what: it goes through (the owner: 'asks Google first and goes through if access is back')", null, PA::code($e) === null ? null : PA::code($e) . ': ' . $e->getMessage());
                    checkEq("given back after $level, $what: one small list, sent first, and no other", [true, 1],
                        [($sent[0] ?? null) === 'GET small', count(array_filter($sent, static fn(string $s): bool => $s === 'GET small'))]);
                    check("given back after $level, $what: Google took a write", PA::taken($t->s->fake, $from) !== [], json_encode($sent));
                    checkEq("given back after $level, $what: stored writer, no item open, the item closed as gone, a second Activity line", ['writer', [], 1, 2],
                        [$role($t), $items($t), count($items($t, 'gone')), count($lines($t))]);
                } finally {
                    H::done($t);
                }
            }
        });
    }

    // ---- 3. Stored below writer, and Google still says so: one small list, nothing else, nothing said twice ------------

    scenario('access read, access still read-only on a calendar stored read-only: each attempt costs one small list and nothing else, is refused with its sentence, and says nothing new in Review or Activity', function () use ($db, $fixtures, $cases, $sentence, $state, $role, $items, $lines): void {
        foreach ($cases as $what => [$fixture, $attempt, $kind]) {
            $t = $fixtures[$fixture]();
            try {
                $t->s->fake->role = 'reader';
                $t->s->poll();
                $before = $state($t);
                $from = count($t->s->fake->requests);
                $e = PA::caught(fn() => $attempt($t));
                if ($kind === 'recover') {
                    // Rule A of the read (a refusal for stored read-only access is always preceded by asking Google):
                    // one small list, then refused with the half-done change's sentence.
                    checkEq("still read-only, $what: refused after exactly one small list", [true, ['GET small']], [$e !== null, PA::sent($t->s->fake, $from)]);
                    check("still read-only, $what: the model's sentence for a half-done change", str_contains((string) $e?->getMessage(),
                        "This change stopped part way, and your permissions for 'Sample Google calendar' at Google are now read-only, so it can't be finished or put back. It stays here, and the event takes no other change, until full editing is restored at Google or you remove the calendar."), (string) $e?->getMessage());
                } else {
                    checkEq("still read-only, $what: refused as read-only after exactly one small list", ['google_read_only', ['GET small']], [PA::code($e), PA::sent($t->s->fake, $from)]);
                    // Rule D(2) of the read: stored view only before the refusal, so it says what the calendar is, no "no longer".
                    checkEq("still read-only, $what: the sentence for a calendar already view only, word for word",
                        $kind === 'undo' ? "This can't be undone: 'Sample Google calendar' is view only at Google." : "'Sample Google calendar' is view only at Google, so it can't be changed here.", $e?->getMessage());
                }
                checkEq("still read-only, $what: nothing held here or at Google differs", $before, $state($t));
                checkEq("still read-only, $what: stored reader, the one item still open, no second Activity line", ['reader', ['google_read_only'], 1], [$role($t), $items($t), count($lines($t))]);
            } finally {
                H::done($t);
            }
        }
    });

    // A change of what is held here alone asks Google nothing, at any level (the owner: "a request that buys nothing is not" welcome).
    scenario('access read, access read-only: a tag and a reminder (held here alone) are changed with no request to Google', function () use ($db, $fixtures, $role): void {
        foreach (['reader', 'none'] as $level) {
            $t = $fixtures['single']();
            try {
                $t->s->fake->role = $level;
                PA::caught(fn() => $t->s->poll());
                $from = count($t->s->fake->requests);
                $e = PA::caught(fn() => $t->e->patch($t->u, $t->id, ['tagNames' => ['sample-tag', 'sample-second'], 'reminders' => [['minutes' => 10]]]));
                checkEq("stored $level: tags and a reminder changed, no request", [null, [], 2], [PA::code($e), PA::sent($t->s->fake, $from),
                    (int) $db->scalar('SELECT COUNT(*) FROM event_tags WHERE event_id = ?', [$t->id])]);
            } finally {
                H::done($t);
            }
        }
    });
};
