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
};
