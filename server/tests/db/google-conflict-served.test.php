<?php

declare(strict_types=1);

// The base of the conflict choice (docs/design/google-conflict-choice.md,
// the page "1.5.7, Slice 1", (b)): a field served by the API and sent back
// untouched in `over` parses to the row's own value. For each of the six
// fields, over events on a Google calendar of every form a field takes (a
// timed event in a zone, an all-day one, one on the same clock in any zone,
// notes with markup, a link, an empty place, Maybe), serve-then-parse is the
// identity: the value parsed back is the row's, compared both as the row
// stores it and by the field's equality. Invented data.

use BetterCal\Domain\GoogleConflict;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    $u = freshUser($db);
    $d = domains($db);
    $s = GoogleStand::make($db, $d->undo, $u);
    $e = $s->events;
    $made = [
        'timed in a zone' => ['title' => 'Sample planning', 'start' => '2027-04-06T10:00:00-07:00', 'end' => '2027-04-06T11:30:00-07:00', 'allDay' => false, 'tzid' => 'America/Los_Angeles',
            'description' => '<p>Agenda</p><p>Bring <b>slides</b> &amp; notes</p>', 'location' => 'Example Hall, Room 2', 'url' => 'https://example.test/agenda'],
        'all-day over two days' => ['title' => 'Sample retreat', 'start' => '2027-04-08', 'end' => '2027-04-10', 'allDay' => true],
        'same clock in any zone' => ['title' => 'Sample stretch', 'start' => '2027-04-07T07:00:00', 'end' => '2027-04-07T07:30:00', 'allDay' => false, 'floating' => true],
        'empty place and notes, Maybe' => ['title' => 'Sample maybe', 'start' => '2027-04-09T18:00:00+00:00', 'end' => '2027-04-09T19:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC', 'status' => 'tentative'],
    ];
    foreach ($made as $name => $in) {
        $id = (int) $e->create($u, ['calendarId' => $s->calendarId] + $in)['eventId'];
        $row = $e->get($u, $id);
        $served = $e->servedFields($row);
        $over = ['title' => $served['title'], 'description' => $served['description'], 'location' => $served['location'], 'url' => $served['url'], 'status' => $served['status']] + $served['time'];
        $back = GoogleConflict::mapOf(array_merge($row, $e->overColumns($u, $row, $over)));
        $own = GoogleConflict::mapOf($row);
        foreach (GoogleConflict::FIELDS as $field) {
            check("served then parsed from over is the row's own value: $field, $name", $back[$field] === $own[$field] && GoogleConflict::equal($field, $back[$field], $own[$field]));
            // One field alone in over parses the same.
            $alone = $field === 'time' ? $served['time'] : [$field => $over[$field]];
            $one = GoogleConflict::mapOf(array_merge($row, $e->overColumns($u, $row, $alone)));
            check("served then parsed from over alone is the row's own value: $field, $name", $one[$field] === $own[$field]);
        }
    }

    // The `kept` key of the 200s of PATCH, relationship and rsvp (Events::keptAnswer, which all three add):
    // absent with the setting off, as shipped, so those answers are 1.5.6's key for key; present, empty or not, with it on.
    conflictChoice($db, $u, false);
    checkEq('setting off: no kept key in the answers', [[], []], [$e->keptAnswer($u), $e->keptAnswer($u, [['field' => 'title', 'now' => 'Sample']])]);
    conflictChoice($db, $u, true);
    checkEq('setting on: the kept key, empty or the answer\'s own', [['kept' => []], ['kept' => [['field' => 'title', 'now' => 'Sample']]]],
        [$e->keptAnswer($u), $e->keptAnswer($u, [['field' => 'title', 'now' => 'Sample']])]);

    // The 409's own sentence, one name per field (the page, "Words"): title, time, location, notes, link; the status
    // never by a name, said as Planned or Maybe.
    $ask = static fn(string $field, mixed $now): array => ['field' => $field, 'was' => null, 'now' => $now, 'yours' => null];
    $words = [
        "'Team sync' wasn't changed: since it was last shown, its title was changed in Google Calendar to 'Standup'. Open the event to see it and make the change again."
            => GoogleConflict::message('Team sync', [$ask('title', 'Standup')], false),
        "'Team sync' wasn't deleted: since it was last shown, its location was changed in Google Calendar. Open the event to see it, and delete it again if you still mean to."
            => GoogleConflict::message('Team sync', [$ask('location', 'Room 7')], true),
        "'Team sync' wasn't changed: since it was last shown, its location was removed in Google Calendar. Open the event to see it and make the change again."
            => GoogleConflict::message('Team sync', [$ask('location', null)], false),
        "'Team sync' wasn't changed: since it was last shown, its notes were changed in Google Calendar. Open the event to see it and make the change again."
            => GoogleConflict::message('Team sync', [$ask('description', '<p>New</p>')], false),
        "'Team sync' wasn't deleted: since it was last shown, its title, notes and link were changed in Google Calendar, and it was changed there to Maybe. Open the event to see it, and delete it again if you still mean to."
            => GoogleConflict::message('Team sync', [$ask('title', 'Standup'), $ask('description', 'x'), $ask('url', 'https://example.test/x'), $ask('status', 'tentative')], true),
        "'Team sync' wasn't deleted: since it was last shown, it was changed in Google Calendar to Planned. Open the event to see it, and delete it again if you still mean to."
            => GoogleConflict::message('Team sync', [$ask('status', 'confirmed')], true),
        "'Team sync' wasn't changed: since it was last shown, its time was changed in Google Calendar to Tue, Nov 18, 10:00 AM to 11:00 AM. Open the event to see it and make the change again."
            => GoogleConflict::message('Team sync', [$ask('time', ['start' => '2025-11-18T10:00:00-08:00', 'end' => '2025-11-18T11:00:00-08:00', 'allDay' => false])], false),
    ];
    foreach ($words as $want => $got) {
        checkEq('the question in words: ' . $want, $want, $got);
    }
};
