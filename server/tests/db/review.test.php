<?php

declare(strict_types=1);

// Review decisions and their undo: undoing an accept puts back all of it,
// the event, the invitation stored on it and the item open in Review, so
// the person can decide again; an answer that never left undoes with it.

use BetterCal\Domain\ActivityContext;
use BetterCal\Domain\ReviewQueue;
use BetterCal\Domain\Rsvp;
use BetterCal\Infra\Db;
use BetterCal\Infra\EmailSender;
use BetterCal\Support\Time;

return static function (Db $db): void {
    // The entries a request's toast undoes (X-Undo-Entries), newest first.
    $toast = static function () use ($db): array {
        $noted = ActivityContext::takeEntries();
        if ($noted === []) {
            return [];
        }
        [$in, $p] = Db::in($noted);
        return array_reverse(array_map('intval', array_column($db->all(
            "SELECT id FROM mutations WHERE id IN $in AND undone = 0 AND (before_json IS NOT NULL OR after_json IS NOT NULL) ORDER BY id", $p), 'id')));
    };
    $invite = ['method' => 'REQUEST', 'kind' => 'invitation', 'organizer' => ['email' => 'host@example.test', 'name' => 'Sample Host'],
        'attendees' => [['email' => 'owner@example.test', 'partstat' => 'NEEDS-ACTION']], 'sequence' => 0];
    $fields = ['title' => 'Sample potluck', 'start' => '2026-12-12T01:00:00+00:00', 'end' => '2026-12-12T03:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC'];
    $status = static fn(int $item): string => (string) $db->scalar('SELECT status FROM review_items WHERE id = ?', [$item]);
    $live = static fn(int $event): int => (int) $db->scalar('SELECT COUNT(*) FROM events WHERE id = ? AND deleted_at IS NULL', [$event]);

    // A new invitation added from Review, then undone: off the calendar and
    // back in Review, to add or dismiss again.
    $u = freshUser($db);
    $d = domains($db);
    $review = new ReviewQueue($db, $d->events);
    $item = $review->holdNewInvitation($u, 'potluck-a@example.test', $fields, $invite, 'host@example.test');
    ActivityContext::takeEntries();
    $added = $review->accept($u, $item);
    $entries = $toast();
    checkEq('review: adding an invitation is one entry to undo', 1, count($entries));
    foreach ($entries as $e) {
        $d->undo->undoById($u, $e);
    }
    checkEq('review: the add undone, the event is gone and the item open in Review again', [0, 'open'], [$live((int) $added['eventId']), $status($item)]);
    $again = $review->accept($u, $item);
    checkEq('review: and it can be added again', 1, $live((int) $again['eventId']));

    // "Add and accept" with no reply sent (no mail set up here): the answer
    // stayed here, so the toast undoes the answer and the add.
    $v = freshUser($db);
    $dv = domains($db);
    $rv = new ReviewQueue($db, $dv->events);
    $item = $rv->holdNewInvitation($v, 'potluck-b@example.test', $fields, $invite, 'host@example.test');
    ActivityContext::takeEntries();
    $added = $rv->accept($v, $item);
    $token = bin2hex(random_bytes(16));
    $db->insert('sessions', ['token_hash' => hash('sha256', $token), 'user_id' => $v, 'csrf' => 'x', 'authenticated_at' => Time::nowDb(), 'expires_at' => '2030-01-01 00:00:00']);
    $reply = (new Rsvp($db, new EmailSender([]), []))->answer($v, (int) $added['eventId'], 'accepted', $token);
    $entries = $toast();
    checkEq('review: add and accept, no reply sent: two entries, the answer and the add', [false, 2], [$reply['sent'], count($entries)]);
    $refused = [];
    foreach ($entries as $e) {
        $refused[] = refusal(fn() => $dv->undo->undoById($v, $e));
    }
    checkEq('review: undone, newest first: the answer, then the add; the item open again', [[null, null], 0, 'open'],
        [$refused, $live((int) $added['eventId']), $status($item)]);

    // A change to an invitation accepted, then undone: the event as it was,
    // its stored invitation as it was, and the change back in Review.
    $w = freshUser($db);
    $dw = domains($db);
    $rw = new ReviewQueue($db, $dw->events);
    $item = $rw->holdNewInvitation($w, 'potluck-c@example.test', $fields, $invite, 'host@example.test');
    $event = (int) $rw->accept($w, $item)['eventId'];
    $storedBefore = $db->scalar('SELECT invite_json FROM events WHERE id = ?', [$event]);
    $moved = ['start' => '2026-12-13T01:00:00+00:00', 'end' => '2026-12-13T03:00:00+00:00'] + $fields;
    $change = (int) $rw->holdInviteChange($w, $db->one('SELECT * FROM events WHERE id = ?', [$event]), 'potluck-c@example.test', 'REQUEST', $moved, ['sequence' => 1] + $invite, 'host@example.test');
    ActivityContext::takeEntries();
    $rw->accept($w, $change);
    checkEq('review: the change accepted moves the event and stores the newer invitation', ['2026-12-13 01:00:00', 1],
        [$db->scalar('SELECT start_utc FROM events WHERE id = ?', [$event]), json_decode((string) $db->scalar('SELECT invite_json FROM events WHERE id = ?', [$event]), true)['sequence'] ?? null]);
    foreach ($toast() as $e) {
        $dw->undo->undoById($w, $e);
    }
    checkEq('review: the change undone: the event and its invitation as they were, and the change open in Review', ['2026-12-12 01:00:00', $storedBefore, 'open'], [
        $db->scalar('SELECT start_utc FROM events WHERE id = ?', [$event]),
        $db->scalar('SELECT invite_json FROM events WHERE id = ?', [$event]),
        $status($change),
    ]);

    // Two versions of one invitation held side by side: adding one turns
    // the other into a change of it; undone, both are as they were.
    $x = freshUser($db);
    $dx = domains($db);
    $rx = new ReviewQueue($db, $dx->events);
    $first = $rx->holdNewInvitation($x, 'potluck-d@example.test', $fields, $invite, 'host@example.test');
    $second = $rx->holdNewInvitation($x, 'potluck-d@example.test', ['title' => 'Sample potluck, moved indoors'] + $fields, $invite, 'host@example.test');
    $kind = static fn(int $item): array => array_values($db->one('SELECT kind, status, event_id FROM review_items WHERE id = ?', [$item]));
    $secondBefore = $kind($second);
    ActivityContext::takeEntries();
    $rx->accept($x, $first);
    checkEq('review: adding one version makes the other a change of it', ['invite_change', 'open'], array_slice($kind($second), 0, 2));
    foreach ($toast() as $e) {
        $dx->undo->undoById($x, $e);
    }
    checkEq('review: undone, the other version is a new invitation again, not a change of a removed event', [$secondBefore, 'open'], [$kind($second), $status($first)]);

    // A sender approved with an add, then another approved: undoing the add
    // takes back only the one it approved.
    $y = freshUser($db);
    $dy = domains($db);
    $ry = new ReviewQueue($db, $dy->events);
    $held = $ry->holdNewInvitation($y, 'potluck-e@example.test', $fields, $invite, 'host@example.test');
    ActivityContext::takeEntries();
    $ry->accept($y, $held, 'address');
    $entries = $toast();
    $dy->undo->write($y, fn() => \BetterCal\Domain\MailPolicy::approve($db, $y, 'friend@example.test', 'address'));
    foreach ($entries as $e) {
        $dy->undo->undoById($y, $e);
    }
    $senders = (new \BetterCal\Domain\Settings($db))->forUser($y)['mailApprovedSenders'] ?? [];
    checkEq('review: undoing an add takes back its sender approval, and keeps one approved since', [false, true],
        [in_array('host@example.test', $senders, true), in_array('friend@example.test', $senders, true)]);

    // A newer emailed version held after the add stands on it: the add no
    // longer undoes, so that version isn't overwritten or left pointing at
    // an event undo removed. Once in a slot of its own, once rewriting the
    // newest slot when the meeting's are full.
    foreach ([1 => 'its own slot', 3 => 'the newest slot rewritten'] as $others => $how) {
        $z = freshUser($db);
        $dz = domains($db);
        $rz = new ReviewQueue($db, $dz->events);
        $uid = 'potluck-f' . $others . '@example.test';
        $added = $rz->holdNewInvitation($z, $uid, $fields, $invite, 'host@example.test');
        for ($i = 1; $i <= $others; $i++) {
            $rz->holdNewInvitation($z, $uid, ['title' => "Sample potluck, version $i"] + $fields, $invite, 'host@example.test');
        }
        ActivityContext::takeEntries();
        $event = (int) $rz->accept($z, $added)['eventId'];
        $entries = $toast();
        $later = ['start' => '2026-12-14T01:00:00+00:00', 'end' => '2026-12-14T03:00:00+00:00'] + $fields;
        $rz->holdInviteChange($z, $db->one('SELECT * FROM events WHERE id = ?', [$event]), $uid, 'REQUEST', $later, ['sequence' => 2] + $invite, 'host@example.test');
        checkEq("review: a newer version held after an add ($how): the add is stale, the event stays", ['stale_undo', 1],
            [refusal(fn() => $dz->undo->undoById($z, $entries[0])), $live($event)]);
    }

    // A day of a series that isn't on the calendar yet, held before its
    // series (a cancellation, or a change): the Review page names it as that
    // day and offers only Dismiss, since accepting it has nothing to apply
    // until the series is added. Once it is, the item is a change to that
    // occurrence and offers its action.
    $q = freshUser($db);
    $dq = domains($db);
    $rq = new ReviewQueue($db, $dq->events);
    $controller = new \BetterCal\Http\Controllers\ReviewController($rq, new \BetterCal\Domain\Proposals($db, $dq->events, new \BetterCal\Domain\Trips($db, $dq->undo)), $dq->calendars);
    $listed = static function () use ($controller, $q): array {
        $req = new \BetterCal\Http\Request('GET', '/review');
        $req->user = ['id' => $q];
        $req->authMethod = 'session';
        $out = [];
        foreach (json_decode($controller->index($req)->body, true)['items'] as $item) {
            $out[$item['key']] = $item;
        }
        return $out;
    };
    $weekly = ['rrule' => 'FREQ=WEEKLY'] + $fields;
    $cancelledDay = $rq->holdNewInvitation($q, 'reading-group@example.test', ['title' => 'Sample reading group'], ['method' => 'CANCEL'] + $invite, 'host@example.test', 'imip', 'approved', '2026-12-19 01:00:00');
    $changedDay = $rq->holdNewInvitation($q, 'reading-group@example.test', ['start' => '2026-12-26T02:00:00+00:00', 'end' => '2026-12-26T04:00:00+00:00'] + $fields, $invite, 'host@example.test', 'imip', 'approved', '2026-12-26 01:00:00');
    $cards = $listed();
    checkEq('review page: a cancelled day held before its series is a cancellation of that day with only Dismiss to decide', ['invite_new', 'CANCEL', '2026-12-19T01:00:00+00:00', ['Dismiss']], [
        $cards["invite_new:$cancelledDay"]['kind'] ?? null, $cards["invite_new:$cancelledDay"]['detail']['method'] ?? null,
        $cards["invite_new:$cancelledDay"]['detail']['occurrence'] ?? null, array_column($cards["invite_new:$cancelledDay"]['actions'] ?? [], 'label'),
    ]);
    checkEq('review page: a changed day held before its series offers only Dismiss too', ['REQUEST', '2026-12-26T01:00:00+00:00', ['Dismiss']], [
        $cards["invite_new:$changedDay"]['detail']['method'] ?? null, $cards["invite_new:$changedDay"]['detail']['occurrence'] ?? null,
        array_column($cards["invite_new:$changedDay"]['actions'] ?? [], 'label'),
    ]);
    $rq->accept($q, $rq->holdNewInvitation($q, 'reading-group@example.test', $weekly, ['sequence' => 1] + $invite, 'host@example.test'));
    $cards = $listed();
    checkEq('review page: once the series is added, the held days are changes to their occurrences, with their actions', [['Skip that occurrence', 'Dismiss'], ['Accept change', 'Dismiss']], [
        array_column($cards["invite_change:$cancelledDay"]['actions'] ?? [], 'label'), array_column($cards["invite_change:$changedDay"]['actions'] ?? [], 'label'),
    ]);
};
