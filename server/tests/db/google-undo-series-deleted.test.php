<?php

declare(strict_types=1);

// An undo or redo of a change to one day, where the whole series was
// deleted at Google since the last poll: every write to a day asks the one
// condition on its series first (GoogleSeries::condition), and a series
// Google lists cancelled is refused before anything is sent, with nothing
// written here (docs/api-contract.md, Activity: "when the event or series
// changed at Google since, with nothing written"). One case per branch of
// GoogleUndo::undo that sends: an edit's undo with a row and with none, a
// delete's undo, a delete's redo with a row and with none, an answer's
// undo. Found by the interleaving mode (seed 7717 case 375, the redo of a
// delete of a day with no row). Google is the edit harness's stand-in.
// Invented data.

use BetterCal\Domain\Rsvp;
use BetterCal\Domain\Undo;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\EmailSender;
use BetterCal\Support\Time;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    // Yearly from Fri 25 Sep 2026 06:00 in Auckland, four times; the day acted on is 25 Sep 2027.
    $yearly = ['title' => 'Sample practice', 'description' => 'Sample notes', 'rrule' => 'FREQ=YEARLY;UNTIL=20280924T180000Z', 'start' => '2026-09-25T06:00:00+12:00', 'end' => '2026-09-25T08:00:00+12:00', 'allDay' => false, 'tzid' => 'Pacific/Auckland'];
    $day = '2026-09-25T06:00:00+12:00';
    $cases = [
        'an edit undone, its row still there' => ['before' => ['tag', 'notes'], 'guests' => false],
        'an edit undone and redone, the day holding no row' => ['before' => ['notes', 'undo'], 'guests' => false],
        'a delete undone' => ['before' => ['delete'], 'guests' => false],
        'a delete undone and redone, the day holding no row' => ['before' => ['delete', 'undo'], 'guests' => false],
        'the delete of an edited day undone and redone, the day holding its row again' => ['before' => ['notes', 'delete', 'undo'], 'guests' => false],
        'an answer to an invitation for one day undone' => ['before' => ['notes', 'answer'], 'guests' => true],
    ];
    foreach ($cases as $name => $c) {
        scenario("google undo, the series deleted at Google since the last poll: $name", function () use ($db, $yearly, $day, $name, $c): void {
            $u = freshUser($db);
            seriesUndo($db, $u, false);
            $d = domains($db);
            $s = GoogleStand::make($db, $d->undo, $u);
            try {
                $e = $s->events;
                $undo = new Undo($db, null, $s->writer);
                if ($c['guests']) {
                    $s->fake->seed(['id' => 'smplinvite', 'iCalUID' => 'smplinvite@google.com', 'summary' => 'Sample practice', 'description' => 'Sample notes',
                        'start' => ['dateTime' => '2026-09-25T06:00:00+12:00', 'timeZone' => 'Pacific/Auckland'], 'end' => ['dateTime' => '2026-09-25T08:00:00+12:00', 'timeZone' => 'Pacific/Auckland'],
                        'recurrence' => ['RRULE:FREQ=YEARLY;COUNT=4'], 'organizer' => ['email' => 'host@example.test'],
                        'attendees' => [['email' => 'host@example.test', 'responseStatus' => 'accepted'], ['email' => 'owner@example.test', 'self' => true, 'responseStatus' => 'needsAction']]]);
                    $s->poll();
                    $id = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ?', [$s->calendarId, 'smplinvite']);
                } else {
                    $id = (int) $e->create($u, ['calendarId' => $s->calendarId] + $yearly)['eventId'];
                }
                $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
                foreach ($c['before'] as $do) {
                    switch ($do) {
                        case 'tag': $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $day, 'tagNames' => ['sample-day']]); break;
                        case 'notes': $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $day, 'description' => 'Notes of the day']); break;
                        case 'delete': $e->deleteEvent($u, $id, 'this', $day); break;
                        case 'undo': $undo->undoById($u, lastEntry($db, $u)); break;
                        case 'answer':
                            $session = bin2hex(random_bytes(16));
                            $db->insert('sessions', ['token_hash' => hash('sha256', $session), 'user_id' => $u, 'csrf' => 'x', 'authenticated_at' => Time::nowDb(), 'expires_at' => '2030-01-01 00:00:00']);
                            (new Rsvp($db, new EmailSender([]), [], $s->writer))->answer($u, (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$id]), 'accepted', $session);
                            break;
                    }
                    $e->throwDeferred();
                }
                $entry = lastEntry($db, $u);
                // The whole series deleted at Google by another client, and no poll since.
                $answer = ($s->fake->transport())('DELETE', 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(GoogleStand::CALENDAR) . '/events/' . rawurlencode($gid),
                    ['Authorization: Bearer sample-access', 'X-Harness: remote'], null);
                check("$name: (setup) the stand-in took the series' deletion", in_array((int) $answer['status'], [200, 204], true), json_encode($answer));
                $all = static fn(): array => [
                    $db->all('SELECT * FROM events WHERE user_id = ? ORDER BY id', [$u]),
                    $db->all('SELECT et.* FROM event_tags et JOIN events e ON e.id = et.event_id WHERE e.user_id = ? ORDER BY et.event_id, et.tag_id', [$u]),
                    $db->all('SELECT id, undone FROM mutations WHERE user_id = ? ORDER BY id', [$u]),
                    $db->all('SELECT id, status FROM google_pending WHERE user_id = ? ORDER BY id', [$u]),
                ];
                $was = $all();
                $s->fake->requests = [];
                $refused = null;
                try {
                    $undo->undoById($u, $entry);
                } catch (HttpError $error) {
                    $refused = [$error->errorCode, $error->status];
                }
                checkEq("$name: refused, the series being deleted at Google", ['google_deleted', 409], $refused);
                checkEq("$name: Google is asked nothing but reads", [], array_values(array_unique(array_filter(array_column($s->fake->requests, 'method'), static fn(string $m): bool => $m !== 'GET'))));
                check("$name: nothing is written here, and the entry is still there to undo", $was === $all());
            } finally {
                GoogleStand::forget($db, $u);
            }
        });
    }
};
