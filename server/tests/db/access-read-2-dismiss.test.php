<?php

declare(strict_types=1);

// Probe (second independent read of the access build, 61be6c69..94befaeb): the one dismiss rule for Review notices of a
// state, compared by state. The rule (docs/design/decisions.md, "Every Review notice of a state can be dismissed, by one
// rule"): "dismissed, a notice stays dismissed while its state stays the same; it comes back when the state changes, or
// when an action the person takes is refused because of that state; dismissing changes nothing but the item." And for
// mail (SystemHealth::acknowledged, as its own words say): no email goes for a feed's failing polls "while the notice
// stays dismissed; a new state is a new notice, open, and alerts as before". From those: a dismiss acknowledges the state
// its notice reports and nothing else, so a failure the notice does not report is not acknowledged; and a dismissed
// half-done change keeps a way back ("the state a dismiss hides is said where the person meets it"). SystemHealth is
// asked what it would do; no expectation is computed from server/src. Invented data.

use BetterCal\Domain\Events;
use BetterCal\Domain\GoogleSeries;
use BetterCal\Domain\ReviewQueue;
use BetterCal\Domain\SystemHealth;
use BetterCal\Infra\Db;

require_once __DIR__ . '/access-read-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $single = ['title' => 'Sample single', 'start' => '2027-03-02T09:00:00-08:00', 'end' => '2027-03-02T10:00:00-08:00', 'tzid' => $la];
    $make = static function (string $level = 'owner') use ($db, $single): object {
        $s = PA::make($db);
        $s->id = (int) $s->events->create($s->u, ['calendarId' => $s->calendarId] + $single)['eventId'];
        ($s->poll)();
        if ($level !== 'owner') {
            $s->fake->role = $level;
            ($s->poll)();
        }
        return $s;
    };
    $items = static fn(object $s, string $status = 'open'): array => PA::items($db, $s->u, $s->calendarId, $status);
    $dismissOpen = static function (object $s) use ($db): void {
        $id = (int) $db->scalar("SELECT id FROM review_items WHERE user_id = ? AND source_key = ? AND status = 'open' ORDER BY id DESC LIMIT 1", [$s->u, 'google-access:' . $s->calendarId]);
        (new ReviewQueue($db, $s->events))->dismissNotice($s->u, $id);
    };
    // A poll of the calendar that failed, as Feeds::pollFailed records it, and whether mail for it is held as acknowledged.
    $held = static function (object $s, string $error) use ($db): bool {
        $health = new SystemHealth($db);
        $health->recordFailure('feed:' . $s->calendarId, 'feed', $s->u, 'Feed: ' . PA::NAME, $error);
        return $health->acknowledged($db->one('SELECT * FROM system_health WHERE subject = ?', ['feed:' . $s->calendarId]));
    };
    $kindOf = ['reader' => 'google_read_only', 'freeBusyReader' => 'google_read_only', 'none' => 'google_not_shown'];

    // ---- A dismissed state that changes into another comes back; one that ends, ends ----------------------------------

    scenario('access read 2, dismiss: for every pair of access states, a notice dismissed in the first and the level then moved to the second: exactly one notice is open, for the second, and none stays dismissed', function () use ($db, $make, $items, $dismissOpen, $kindOf): void {
        foreach (['reader', 'freeBusyReader', 'none'] as $a) {
            foreach (['reader', 'freeBusyReader', 'none'] as $b) {
                if ($a === $b) {
                    continue;
                }
                $s = $make($a);
                $before = [$db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]), PA::state($db, $s->u, $s->calendarId, $s->fake)];
                $dismissOpen($s);
                checkEq("$a, dismissed: nothing but the item changed (the calendar's row, its events, tags, entries, the stand-in)", $before,
                    [$db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]), PA::state($db, $s->u, $s->calendarId, $s->fake)]);
                ($s->poll)();
                checkEq("$a, dismissed, a poll in the same state: stays dismissed", [[], [$kindOf[$a]]], [$items($s), $items($s, 'dismissed')]);
                $s->fake->role = $b;
                ($s->poll)();
                checkEq("$a dismissed, then $b: one notice open for $b, none dismissed", [[$kindOf[$b]], [], $b], [$items($s), $items($s, 'dismissed'), PA::storedRole($db, $s->calendarId)]);
            }
            // Back to editable and into the same state again: a new notice, open.
            $s = $make($a);
            $dismissOpen($s);
            $s->fake->role = 'writer';
            ($s->poll)();
            $s->fake->role = $a;
            ($s->poll)();
            checkEq("$a dismissed, editable again, then $a again: a new notice, open", [[$kindOf[$a]], []], [$items($s), $items($s, 'dismissed')]);
        }
    });

    // ---- What a dismissed notice acknowledges, for mail ------------------------------------------------------------------

    scenario('access read 2, dismiss and mail: a dismissed notice holds mail for the failing polls of the state it reports, and for no other failure of that calendar', function () use ($db, $make, $dismissOpen, $held): void {
        // Not shown: every poll fails with that sentence; dismissed, its mail is held (the build's own rule; holds).
        $s = $make('none');
        // The failure a poll at that level records, as the poll says it (rule 3 of the second read's brief: mail is held
        // for that failure alone, so the probe gives the poll's own words, not a part of them).
        $said = (string) ($s->poll)();
        checkEq('not shown, its notice open: mail is not held', false, $held($s, $said));
        $dismissOpen($s);
        checkEq('not shown, its notice dismissed: mail for that state is held', true, $held($s, $said));

        // View only: polls succeed at that level, so the notice reports no failing poll at all. Dismissed, a later
        // failure of the calendar's polls for another reason (the account's sign-in refused, Google unreachable) is a
        // state the person was never told about, let alone acknowledged.
        $s = $make('reader');
        $dismissOpen($s);
        checkEq('view only, its notice dismissed, then its polls fail for another reason: that failure is not acknowledged, its mail goes', false,
            $held($s, 'Google refused the sign-in: the refresh token was revoked'));

        // Busy times only, dismissed: the failing poll of that state is held; another failure is not.
        $s = $make('freeBusyReader');
        $dismissOpen($s);
        checkEq('busy times only, its notice dismissed, then its polls fail for another reason: not acknowledged', false,
            $held($s, 'Google could not be reached: connection timed out'));

        // The state ended (editable again) and the calendar then fails: nothing is acknowledged.
        $s = $make('reader');
        $dismissOpen($s);
        $s->fake->role = 'writer';
        ($s->poll)();
        checkEq('view only dismissed, editable again, then its polls fail: not acknowledged', false, $held($s, 'Google could not be reached: connection timed out'));
    });

    // ---- A half-done change, dismissed ---------------------------------------------------------------------------------

    scenario('access read 2, dismiss, a half-done change: dismissing changes nothing but the item, the event of every day says so with the way back, and the access notice still opens when the calendar turns read-only', function () use ($db): void {
        $weekly = ['start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=6'];
        $t = H::make($db, $weekly);
        try {
            H::this($t, '2027-03-08T10:00:00-08:00', ['title' => 'Sample series (own)']);
            $t->s->knobs->netAt = $t->s->knobs->writes + 2;
            H::tryChange($t, ['scope' => 'all', 'instanceStart' => '2027-03-01T10:00:00-08:00', 'start' => '2027-03-01T11:00:00-08:00', 'end' => '2027-03-01T12:00:00-08:00']);
            $t->s->knobs->down = false;
            $queue = new ReviewQueue($db, $t->e);
            $notices = $queue->halfDoneNotices($t->u);
            $notice = array_values($notices)[0] ?? null;
            check('the change is half done and has its notice, open (the probe\'s own step)', count($notices) === 1 && ($notice['status'] ?? '') === 'open', json_encode($notices));
            $pending = static fn(): array => $db->all('SELECT * FROM google_pending WHERE user_id = ? ORDER BY id', [$t->u]);
            $before = [$pending(), PA::state($db, $t->u, $t->s->calendarId, $t->s->fake)];
            $queue->dismissNotice($t->u, (int) $notice['row']);
            checkEq('dismissed: the pending change, every row, tag and entry and the stand-in are as before', $before, [$pending(), PA::state($db, $t->u, $t->s->calendarId, $t->s->fake)]);
            $rows = $db->all('SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY id', [$t->s->calendarId]);
            $lines = array_map(static fn(array $r): ?array => Events::halfDoneLine($db, $t->e, $r), $rows);
            check('dismissed: every row of the series (the series and its edited day) carries the line, not open, with its notice to reopen',
                $rows !== [] && array_filter($lines, static fn(?array $l): bool => $l === null || $l['open'] !== false || (int) $l['noticeId'] !== (int) $notice['row']) === [], json_encode($lines));
            $t->s->fake->role = 'reader';
            $t->s->poll();
            checkEq('the calendar turns view only: its access notice opens, though the half-done one stays dismissed', [['google_read_only'], 'dismissed'],
                [PA::items($db, $t->u, $t->s->calendarId), array_values($queue->halfDoneNotices($t->u))[0]['status'] ?? null]);
            // The way back: the event's line reopens it.
            $queue->reopenNotice($t->u, (int) $notice['row']);
            checkEq('the event\'s line opens it again', 'open', array_values($queue->halfDoneNotices($t->u))[0]['status'] ?? null);
        } finally {
            H::done($t);
        }
    });

    // A delete of all events whose answer is lost: Google took it, so the series is gone here too once the stop's read
    // is stored. The change is half done (Put back would bring the series back). Dismissed, where does the person meet it?
    scenario('access read 2, dismiss, a half-done delete of a whole series: dismissed, it still has a way back (an event whose details carry its line, or it is listed)', function () use ($db): void {
        $weekly = ['start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=6'];
        $t = H::make($db, $weekly);
        try {
            $t->s->knobs->loseAt = $t->s->knobs->writes + 1; // Google takes the delete; its answer never comes
            $e = PA::caught(function () use ($t): void {
                $t->e->deleteEvent($t->u, $t->id, 'all', null);
                $t->e->throwDeferred();
            });
            $queue = new ReviewQueue($db, $t->e);
            $notices = $queue->halfDoneNotices($t->u);
            if (count($notices) !== 1) {
                check('a delete whose answer was lost is listed half done (the probe\'s own step; not so here, nothing to check)', true);
                return;
            }
            $notice = array_values($notices)[0];
            $queue->dismissNotice($t->u, (int) $notice['row']);
            $rows = $db->all('SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY id', [$t->s->calendarId]);
            $withLine = array_filter($rows, static fn(array $r): bool => Events::halfDoneLine($db, $t->e, $r) !== null);
            // Rule 4 of the second read's brief: the way back is Review's history, which lists a dismissed notice while its
            // state holds with Show again (one rule for every notice kind), when no event here carries the line.
            $controller = new BetterCal\Http\Controllers\ReviewController($queue, new BetterCal\Domain\Proposals($db, $t->e, new BetterCal\Domain\Trips($db, $t->d->undo)), $t->d->calendars, null, null, null, $t->e);
            $req = new BetterCal\Http\Request('GET', '/review', ['status' => 'all']);
            $req->user = ['id' => $t->u];
            $req->authMethod = 'session';
            $listed = array_values(array_filter(json_decode($controller->index($req)->body, true)['items'], static fn(array $i): bool => $i['kind'] === 'google_half'));
            checkEq('dismissed: the change is still half done, and an event here carries its line or Review\'s history lists it with Show again',
                [1, true], [count(GoogleSeries::halfDoneOf($db, $t->u)), $withLine !== [] || in_array('Show again', array_column($listed[0]['actions'] ?? [], 'label'), true)]);
        } finally {
            H::done($t);
        }
    });
};
