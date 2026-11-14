<?php

declare(strict_types=1);

// Probe (third independent read of the access branch, 94befaeb..c3765fb0): what now counts as a refusal, the
// unconfirmed refusal, health mail and Review's history. The model (docs/design/google-access-detect-and-inform.md,
// section 9): rule 2, "A refusal built after the small list is a refusal. Google answered the write with a 4xx and took
// nothing, so the step is not journaled as sent, the change is not listed half done ... When the small list itself
// cannot be read, the refused write is read as nothing: refused as unconfirmed ... never a DELETE's 404 as 'already
// removed'"; section 8, rule D, "Whether anything was changed is known from what the change sent" (a change that had sent
// part of itself never says nothing was changed); rule 3, mail "is held only for the failure the dismissed notice
// reports, while that state holds"; rule 4, "Review's history (status all) lists every dismissed notice whose state
// still holds, with 'Show again'". The converse of rule 2 is the series model's: a write Google took is never treated
// as not sent. Nothing is computed from server/src. Invented data.

use BetterCal\Domain\GoogleSeries;
use BetterCal\Domain\Proposals;
use BetterCal\Domain\ReviewQueue;
use BetterCal\Domain\SystemHealth;
use BetterCal\Domain\Trips;
use BetterCal\Http\Controllers\ReviewController;
use BetterCal\Http\Request;
use BetterCal\Infra\Db;

require_once __DIR__ . '/access-read-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $single = ['title' => 'Sample single', 'start' => '2027-03-02T09:00:00-08:00', 'end' => '2027-03-02T10:00:00-08:00', 'tzid' => $la];
    $weekly = ['title' => 'Sample series', 'start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6'];
    $make = static function (array $event) use ($db): object {
        $s = PA::make($db);
        $s->id = (int) $s->events->create($s->u, ['calendarId' => $s->calendarId] + $event)['eventId'];
        $s->gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$s->id]);
        ($s->poll)();
        return $s;
    };
    $state = static fn(object $s): array => PA::state($db, $s->u, $s->calendarId, $s->fake);
    $hourLater = ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00'];
    // The level drops to $level as the writer's $nth write goes out, and the next GET after it (the small list) can't be read.
    $dropThenUnreadable = static function (object $s, int $nth, string $level): void {
        $writes = 0;
        $armed = false;
        $s->hook = static function (int $n, string $method, string $url) use ($s, &$writes, &$armed, $nth, $level): void {
            if ($method !== 'GET') {
                if (++$writes === $nth) {
                    $s->fake->role = $level;
                    $armed = true;
                }
            } elseif ($armed) {
                $armed = false;
                throw new \RuntimeException('HTTP error for the stand-in: network down');
            }
        };
    };

    // ---- The unconfirmed refusal ---------------------------------------------------------------------------------------

    foreach (['an edit' => fn(object $s) => $s->events->patch($s->u, $s->id, ['title' => 'Sample single, renamed']),
        'a delete' => fn(object $s) => $s->events->deleteEvent($s->u, $s->id, null, null)] as $what => $attempt) {
        foreach (['reader', 'none'] as $level) {
            scenario("probe 3, unconfirmed: $what refused by Google as the level drops to $level, and the small list can't be read: refused, nothing changed here or at Google, nothing announced; the next attempt, the network back, gets the access refusal", function () use ($db, $make, $single, $state, $dropThenUnreadable, $attempt, $what, $level): void {
                $s = $make($single);
                $s->events->patch($s->u, $s->id, ['tagNames' => ['sample-tag']]);
                $before = $state($s);
                $dropThenUnreadable($s, 1, $level);
                $e = PA::caught(fn() => $attempt($s));
                $s->hook = null;
                checkEq("$level, $what, the small list unreadable: refused as a failure to reach Google (502), never as read-only, deleted or changed", ['google_write_failed', 502], [PA::code($e), $e?->status ?? null]);
                check("$level, $what: its sentence says Google did not say, and that nothing was changed", str_contains((string) $e?->getMessage(), 'Google did not say') && str_contains((string) $e?->getMessage(), 'nothing was changed'), (string) $e?->getMessage());
                checkEq("$level, $what: nothing held here or at Google differs; the level stored and Review as before", [$before, 'owner', []],
                    [$state($s), PA::storedRole($db, $s->calendarId), PA::items($db, $s->u, $s->calendarId)]);
                $e = PA::caught(fn() => $attempt($s));
                checkEq("$level, $what again, Google reachable: the access refusal, the level stored, nothing changed", [$level === 'none' ? 'google_not_shown' : 'google_read_only', $level, $before['rows']],
                    [PA::code($e), PA::storedRole($db, $s->calendarId), $state($s)['rows']]);
            });
        }
    }

    scenario('probe 3, unconfirmed: a write Google keeps refusing while its lists say writer is said as Google refusing that change every time, never as unconfirmed, and stores nothing', function () use ($db, $make, $single, $state): void {
        $s = $make($single);
        $before = $state($s);
        $s->hook = static function (int $n, string $method, string $url) use ($s): void {
            $s->fake->role = $method === 'GET' ? 'writer' : 'reader'; // every PATCH answers 403 forbidden; every list says writer
        };
        foreach ([1, 2, 3] as $try) {
            $from = count($s->fake->requests);
            $e = PA::caught(fn() => $s->events->patch($s->u, $s->id, ['title' => 'Sample single, renamed']));
            checkEq("attempt $try: refused as Google refusing that change (403), after its list, the write and one small list", ['google_write_failed', 403, ['GET list', 'PATCH id', 'GET small']],
                [PA::code($e), $e?->status ?? null, PA::sent($s->fake, $from)]);
            check("attempt $try: the sentence says Google refused this change", str_contains((string) $e?->getMessage(), 'Google refused this change'), (string) $e?->getMessage());
        }
        $s->hook = null;
        checkEq('after three attempts: nothing here differs, no access notice', [$before['rows'], []], [$state($s)['rows'], PA::items($db, $s->u, $s->calendarId)]);
    });

    // ---- What counts as a refusal inside a change to all events --------------------------------------------------------

    foreach (['reader', 'none'] as $level) {
        scenario("probe 3, refusals: a change to all events whose first write Google refuses as the level drops to $level, the small list unreadable: nothing reached Google, nothing is listed half done", function () use ($db, $make, $weekly, $state, $dropThenUnreadable, $hourLater, $level): void {
            $s = $make($weekly);
            $before = $state($s);
            $dropThenUnreadable($s, 1, $level);
            $from = count($s->fake->requests);
            $e = PA::caught(function () use ($s, $hourLater): void {
                planned($s->events, $s->u, $s->id, $hourLater);
                $s->events->throwDeferred();
            });
            $s->hook = null;
            checkEq("$level: refused, Google took nothing, nothing half done, the rows as before", [true, [], 0, $before['rows']],
                [$e !== null, PA::taken($s->fake, $from), count(GoogleSeries::halfDoneOf($db, $s->u)), $state($s)['rows']]);
            check("$level: no sentence says part of the change reached Google", !str_contains((string) $e?->getMessage(), 'reached Google'), (string) $e?->getMessage());
        });

        scenario("probe 3, refusals: a change to all events whose second write Google refuses as the level drops to $level, the small list unreadable: its first write was taken, so it is listed half done with one step, and no sentence says nothing was changed", function () use ($db, $make, $weekly, $dropThenUnreadable, $hourLater, $level): void {
            $s = $make($weekly);
            $s->events->patch($s->u, $s->id, ['scope' => 'this', 'instanceStart' => '2027-03-08T10:00:00-08:00', 'title' => 'Sample series (own)']);
            $s->events->throwDeferred();
            $dropThenUnreadable($s, 2, $level);
            $from = count($s->fake->requests);
            $e = PA::caught(function () use ($s, $hourLater): void {
                planned($s->events, $s->u, $s->id, $hourLater);
                $s->events->throwDeferred();
            });
            $s->hook = null;
            $half = GoogleSeries::halfDoneOf($db, $s->u);
            checkEq("$level: Google took exactly one write, and the change is listed half done with exactly that one step", [1, 1, 1], [count(PA::taken($s->fake, $from)), count($half), (int) ($half[0]['stepsDone'] ?? -1)]);
            check("$level: the sentence the person reads does not say nothing was changed (part of it reached Google)", !str_contains((string) $e?->getMessage(), 'nothing was changed'), (string) $e?->getMessage());
            check("$level: what Review says stopped it does not say nothing was changed either", !str_contains((string) ($half[0]['error'] ?? ''), 'nothing was changed'), (string) ($half[0]['error'] ?? ''));
        });
    }

    // The converse: a write Google took is never treated as not sent, whatever answers follow it.
    foreach (['reader', 'freeBusyReader', 'none'] as $level) {
        scenario("probe 3, refusals: a change to all events whose one write Google took, the level then $level for every answer after it: the change is stored here or listed half done, never neither", function () use ($db, $make, $weekly, $level): void {
            $s = $make($weekly);
            $writes = 0;
            $s->hook = static function (int $n, string $method, string $url) use ($s, &$writes, $level): void {
                if ($method !== 'GET') {
                    $writes++;
                } elseif ($writes >= 1) {
                    $s->fake->role = $level;
                }
            };
            $from = count($s->fake->requests);
            $e = PA::caught(function () use ($s): void {
                planned($s->events, $s->u, $s->id, ['scope' => 'all', 'title' => 'Sample series, renamed']);
                $s->events->throwDeferred();
            });
            $s->hook = null;
            $here = (string) $db->scalar('SELECT title FROM events WHERE id = ? AND deleted_at IS NULL', [$s->id]);
            $half = count(GoogleSeries::halfDoneOf($db, $s->u));
            checkEq("$level after the write: Google holds the new title (the stand-in's record)", ['Sample series, renamed', 1], [$s->fake->events[$s->gid]['summary'] ?? null, count(PA::taken($s->fake, $from))]);
            check("$level after the write: the change Google took is stored here (title: '$here') or listed half done ($half), never neither", $here === 'Sample series, renamed' || $half === 1, PA::code($e) . ': ' . $e?->getMessage());
            // Given back: nothing is left half done once Finish runs, and here matches Google.
            $s->fake->role = 'writer';
            if ($half === 1) {
                $r = PA::caught(function () use ($s, $db): void {
                    $s->events->googleFinish($s->u, (int) GoogleSeries::halfDoneOf($db, $s->u)[0]['id']);
                    $s->events->throwDeferred();
                });
                checkEq("$level after the write, given back, Finish: through, nothing half done, the title here is Google's", [null, 0, 'Sample series, renamed'],
                    [$r === null ? null : PA::code($r) . ': ' . $r->getMessage(), count(GoogleSeries::halfDoneOf($db, $s->u)), $db->scalar('SELECT title FROM events WHERE id = ? AND deleted_at IS NULL', [$s->id])]);
            } else {
                ($s->poll)();
                checkEq("$level after the write, given back, a poll: the title here is Google's", 'Sample series, renamed', $db->scalar('SELECT title FROM events WHERE id = ? AND deleted_at IS NULL', [$s->id]));
            }
        });
    }

    // ---- Health mail ---------------------------------------------------------------------------------------------------

    scenario('probe 3, mail: held only for the failure the dismissed notice reports while that state holds: not after the state ends and returns undismissed, not for another state\'s failure, not for a failure of another kind', function () use ($db, $make, $single): void {
        $health = new SystemHealth($db);
        $held = static function (object $s, string $error) use ($db, $health): bool {
            $health->recordFailure('feed:' . $s->calendarId, 'feed', $s->u, 'Feed: ' . PA::NAME, $error);
            return $health->acknowledged($db->one('SELECT * FROM system_health WHERE subject = ?', ['feed:' . $s->calendarId]));
        };
        $dismissOpen = static function (object $s) use ($db): void {
            $id = (int) $db->scalar("SELECT id FROM review_items WHERE user_id = ? AND source_key = ? AND status = 'open' ORDER BY id DESC LIMIT 1", [$s->u, 'google-access:' . $s->calendarId]);
            (new ReviewQueue($db, $s->events))->dismissNotice($s->u, $id);
        };
        $other = 'Google could not be reached: connection timed out';
        // Not shown, dismissed; then shown again; then not shown again.
        $s = $make($single);
        $s->fake->role = 'none';
        $first = (string) ($s->poll)();
        checkEq('not shown, the notice open: not held', false, $held($s, $first));
        $dismissOpen($s);
        $again = (string) ($s->poll)(); // a later poll in the same state says it without "no longer"
        checkEq('not shown, dismissed: held for the first poll\'s words and for a later poll\'s, not for another failure', [true, true, false], [$held($s, $first), $held($s, $again), $held($s, $other)]);
        $s->fake->role = 'writer';
        ($s->poll)();
        checkEq('shown again (the state ended): a failure of any kind is not held', [false, false], [$held($s, $other), $held($s, $again)]);
        $s->fake->role = 'none';
        $back = (string) ($s->poll)();
        checkEq('not shown again: a new notice, open, so its failure is not held until it is dismissed', [['google_not_shown'], false], [PA::items($db, $s->u, $s->calendarId), $held($s, $back)]);
        $dismissOpen($s);
        checkEq('dismissed again: held', true, $held($s, $back));
        // Busy times only, dismissed; then not shown.
        $s = $make($single);
        $s->fake->role = 'freeBusyReader';
        $busy = (string) ($s->poll)();
        $dismissOpen($s);
        checkEq('busy times only, dismissed: its own failure held, another not', [true, false], [$held($s, $busy), $held($s, $other)]);
        $s->fake->role = 'none';
        $gone = (string) ($s->poll)();
        checkEq('then not shown (a new state, its notice open): neither that failure nor the old one is held', [false, false], [$held($s, $gone), $held($s, $busy)]);
        // View only, dismissed: polls succeed; a failure of any kind is not held.
        $s = $make($single);
        $s->fake->role = 'reader';
        ($s->poll)();
        $dismissOpen($s);
        checkEq('view only, dismissed: no failure is held', false, $held($s, $other));
    });

    // ---- Review's history ----------------------------------------------------------------------------------------------

    $listed = static function (object $x, int $u, $events, string $status) use ($db): array {
        $controller = new ReviewController(new ReviewQueue($db, $events), new Proposals($db, $events, new Trips($db, $x->undo)), $x->calendars, null, null, null, $events);
        $req = new Request('GET', '/review', ['status' => $status]);
        $req->user = ['id' => $u];
        $req->authMethod = 'session';
        return json_decode($controller->index($req)->body, true)['items'];
    };
    $noticeId = static function (array $item, string $action): int {
        $path = (string) (array_values(array_filter($item['actions'] ?? [], static fn(array $a): bool => $a['name'] === $action))[0]['path'] ?? '');
        return preg_match('#/(\d+)/[a-z-]+$#', $path, $m) === 1 ? (int) $m[1] : 0;
    };

    scenario('probe 3, history: a half-done delete of a whole series, dismissed, with no event left here: the history lists it once with Show again, shown again it offers Put back, and Put back brings the series back here and at Google', function () use ($db, $listed, $noticeId): void {
        $t = H::make($db, ['start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=6']);
        try {
            $t->e->patch($t->u, $t->id, ['scope' => 'all', 'tagNames' => ['sample-tag']]);
            $t->s->knobs->loseAt = $t->s->knobs->writes + 1; // Google takes the delete; its answer never comes
            PA::caught(function () use ($t): void {
                $t->e->deleteEvent($t->u, $t->id, 'all', null);
                $t->e->throwDeferred();
            });
            $of = static fn(array $items): array => array_values(array_filter($items, static fn(array $i): bool => $i['kind'] === 'google_half'));
            $open = $of($listed($t->d, $t->u, $t->e, 'open'));
            if (count($open) !== 1) {
                check('a delete whose answer was lost is listed half done (the probe\'s own step; not so here, nothing to check)', true);
                return;
            }
            checkEq('half done: listed once, with Put back and Dismiss; no event of the series is left here', [true, true, 0],
                [in_array('put_back', array_column($open[0]['actions'], 'name'), true), in_array('dismiss', array_column($open[0]['actions'], 'name'), true),
                    (int) $db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$t->s->calendarId])]);
            (new ReviewQueue($db, $t->e))->dismissNotice($t->u, $noticeId($open[0], 'dismiss'));
            $history = $of($listed($t->d, $t->u, $t->e, 'all'));
            checkEq('dismissed: not among the open items; the history lists it once, dismissed, with Show again as its one action', [0, 1, 'dismissed', ['reopen']],
                [count($of($listed($t->d, $t->u, $t->e, 'open'))), count($history), $history[0]['status'] ?? null, array_column($history[0]['actions'] ?? [], 'name')]);
            (new ReviewQueue($db, $t->e))->reopenNotice($t->u, $noticeId($history[0] ?? [], 'reopen'));
            $open = $of($listed($t->d, $t->u, $t->e, 'open'));
            checkEq('shown again: among the open items once, with Put back; the history lists it once', [1, true, 1],
                [count($open), in_array('put_back', array_column($open[0]['actions'] ?? [], 'name'), true), count($of($listed($t->d, $t->u, $t->e, 'all')))]);
            $r = PA::caught(function () use ($t, $open): void {
                $t->e->googlePutBack($t->u, (int) ($open[0]['detail']['id'] ?? 0));
                $t->e->throwDeferred();
            });
            (new ReviewQueue($db, $t->e))->halfDoneNotices($t->u);
            checkEq('Put back: through; the series is back here with its tag and live at Google; nothing half done; Review lists it nowhere',
                [null, 1, 1, 'confirmed', 0, 0, 0],
                [$r === null ? null : PA::code($r) . ': ' . $r->getMessage(),
                    (int) $db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND deleted_at IS NULL AND recurrence_parent_id IS NULL AND rrule IS NOT NULL', [$t->s->calendarId]),
                    (int) $db->scalar('SELECT COUNT(*) FROM event_tags et JOIN events e ON e.id = et.event_id WHERE e.calendar_id = ? AND e.deleted_at IS NULL AND e.recurrence_parent_id IS NULL', [$t->s->calendarId]),
                    $t->s->fake->events[$t->gid]['status'] ?? 'confirmed', count(GoogleSeries::halfDoneOf($db, $t->u)),
                    count($of($listed($t->d, $t->u, $t->e, 'open'))), count($of($listed($t->d, $t->u, $t->e, 'all')))]);
        } finally {
            H::done($t);
        }
    });

    scenario('probe 3, history: a dismissed access notice is listed once with Show again while its state holds; once the state has ended it has no Show again, and asking for it to be shown again opens nothing', function () use ($db, $make, $single, $listed, $noticeId): void {
        $s = $make($single);
        $s->fake->role = 'reader';
        ($s->poll)();
        $of = static fn(array $items): array => array_values(array_filter($items, static fn(array $i): bool => in_array($i['kind'], ['google_read_only', 'google_not_shown'], true)));
        $open = $of($listed($s->d, $s->u, $s->events, 'open'));
        $id = $noticeId($open[0] ?? [], 'dismiss');
        (new ReviewQueue($db, $s->events))->dismissNotice($s->u, $id);
        $history = $of($listed($s->d, $s->u, $s->events, 'all'));
        checkEq('view only, dismissed: not open; the history lists it once with Show again', [0, 1, ['reopen']],
            [count($of($listed($s->d, $s->u, $s->events, 'open'))), count($history), array_column($history[0]['actions'] ?? [], 'name')]);
        (new ReviewQueue($db, $s->events))->reopenNotice($s->u, $id);
        checkEq('shown again: open once, and once in the history', [1, 1], [count($of($listed($s->d, $s->u, $s->events, 'open'))), count($of($listed($s->d, $s->u, $s->events, 'all')))]);
        (new ReviewQueue($db, $s->events))->dismissNotice($s->u, $id);
        $s->fake->role = 'writer';
        ($s->poll)();
        $history = $of($listed($s->d, $s->u, $s->events, 'all'));
        checkEq('editable again (the state ended): the history offers no Show again for it', [], array_merge(...array_map(static fn(array $i): array => array_column($i['actions'] ?? [], 'name'), $history ?: [[]])));
        PA::caught(fn() => (new ReviewQueue($db, $s->events))->reopenNotice($s->u, $id));
        checkEq('asked to show it again all the same: nothing is open, and the calendar has no access notice', [0, []], [count($of($listed($s->d, $s->u, $s->events, 'open'))), PA::items($db, $s->u, $s->calendarId)]);
    });
};
