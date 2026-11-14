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
};
