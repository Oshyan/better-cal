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
use BetterCal\Domain\Settings;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    $u = freshUser($db);
    // The setting's reading (Settings::DEFAULTS, on as 1.5.7 ships): an account that stores nothing for it, or an
    // empty settings object, reads on; only an explicit false reads off. Nothing is rewritten to get there.
    $stored = static fn(int $id): mixed => $db->scalar('SELECT settings_json FROM users WHERE id = ?', [$id]);
    check('a new account stores no google_conflict_choice', !array_key_exists('google_conflict_choice', json_decode((string) $stored($u), true) ?: []));
    check('a missing google_conflict_choice reads on', Settings::googleConflictChoice($db, $u) === true);
    $bare = freshUser($db);
    $db->update('users', ['settings_json' => '{}'], 'id = ?', [$bare]);
    check('empty stored settings read on', Settings::googleConflictChoice($db, $bare) === true);
    conflictChoice($db, $bare, false);
    check('an explicit false reads off', Settings::googleConflictChoice($db, $bare) === false);
    conflictChoice($db, $bare, true);
    check('an explicit true reads on', Settings::googleConflictChoice($db, $bare) === true);
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
        $over = ['title' => $served['title'], 'description' => $served['description'], 'location' => $served['location'], 'url' => $served['url'], 'status' => $served['status'], 'showAs' => $served['showAs']] + $served['time'];
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
    // absent with the setting off (on as shipped; off is an operator's switch), so those answers are 1.5.6's key for key;
    // present, empty or not, with it on.
    conflictChoice($db, $u, false);
    checkEq('setting off: no kept key in the answers', [[], []], [$e->keptAnswer($u), $e->keptAnswer($u, [['field' => 'title', 'now' => 'Sample']])]);
    conflictChoice($db, $u, true);
    checkEq('setting on: the kept key, empty or the answer\'s own', [['kept' => []], ['kept' => [['field' => 'title', 'now' => 'Sample']]]],
        [$e->keptAnswer($u), $e->keptAnswer($u, [['field' => 'title', 'now' => 'Sample']])]);

    // With the setting off, `over` is read by nothing: the same rename of two equal events, one with an
    // invented `over` for every field, sends Google the same requests and leaves the two rows the same.
    conflictChoice($db, $u, false);
    $twin = static fn(): int => (int) $e->create($u, ['calendarId' => $s->calendarId, 'title' => 'Sample review', 'start' => '2027-05-04T09:00:00+00:00',
        'end' => '2027-05-04T10:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC', 'location' => 'Room 4'])['eventId'];
    [$plain, $invented] = [$twin(), $twin()];
    $sent = static function (callable $write) use ($s): array {
        $from = count($s->fake->requests);
        $write();
        return array_map(static fn(array $r): array => [$r['method'], $r['body']], array_slice($s->fake->requests, $from));
    };
    $rename = ['title' => 'Sample review, moved'];
    $a = $sent(fn() => $e->patch($u, $plain, $rename));
    $b = $sent(fn() => $e->patch($u, $invented, $rename + ['over' => ['title' => 'Invented', 'description' => '<p>Invented</p>', 'location' => 'Invented Hall',
        'url' => 'https://example.test/invented', 'status' => 'tentative', 'start' => '2027-06-01T09:00:00+00:00', 'end' => '2027-06-01T10:00:00+00:00', 'allDay' => false]]));
    $kept = static fn(array $r): array => array_intersect_key($r, array_flip(['title', 'description', 'location', 'url', 'status', 'start_utc', 'end_utc', 'all_day']));
    checkEq('setting off: an invented over changes nothing, neither what is sent nor what is written', [$a, $kept($e->get($u, $plain))],
        [$b, $kept($e->get($u, $invented))]);
    checkEq('setting off: the rename went through', 'Sample review, moved', $e->get($u, $invented)['title']);

};
