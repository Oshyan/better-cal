<?php

declare(strict_types=1);

// From the independent read of the access build, d32b65b1..61be6c69): the first read of an event in no series became a
// list by its iCalUID where it was a GET by id (docs/design/google-access-detect-and-inform.md, section 2: "a list item
// is the same event resource a GET returns ... Nothing else is lost that the code uses"). A list by iCalUID can hold
// more than the event: every record Google keeps under that iCalUID. The rule checked: an ordinary edit of an event on
// a calendar the account may edit, unchanged at Google since the last poll, goes through, as it did on the fix branch
// (d32b65b1, where this file passes: run it there to see). Nothing is computed from server/src. Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/access-read-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $when = static fn(string $local): array => ['dateTime' => (new DateTimeImmutable($local, new DateTimeZone('America/Los_Angeles')))->format('Y-m-d\TH:i:sP'), 'timeZone' => 'America/Los_Angeles'];

    // An event that repeated once, with an edited day and a deleted day, and no longer repeats: made so here.
    scenario('access read, first read: an event that used to repeat (it had an edited day and a deleted day) and no longer does, made so here, can be edited and deleted', function () use ($db, $la): void {
        $s = PA::make($db);
        $id = (int) $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => 'Sample series', 'start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=4'])['eventId'];
        $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => '2027-03-08T10:00:00-08:00', 'title' => 'Sample series (own)']);
        $s->events->throwDeferred();
        $s->events->deleteEvent($s->u, $id, 'this', '2027-03-15T10:00:00-07:00');
        $s->events->throwDeferred();
        $e = PA::caught(function () use ($s, $id): void {
            planned($s->events, $s->u, $id, ['scope' => 'all', 'rrule' => null]);
            $s->events->throwDeferred();
        });
        ($s->poll)();
        // What is left of it here: events in no series (the product keeps an edited day as an event of its own).
        $left = $db->all('SELECT id, rrule, google_event_id FROM events WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY id', [$s->calendarId]);
        $row = $left[0] ?? null;
        if ($e !== null || $row === null || array_filter(array_column($left, 'rrule')) !== []) {
            check('the event was made to no longer repeat (the probe\'s own step)', false, PA::code($e) . ': ' . $e?->getMessage() . ' ' . json_encode($left));
            return;
        }
        $id = (int) $row['id'];
        $under = array_values(array_filter($s->fake->events, static fn(array $g): bool => ($g['iCalUID'] ?? '') === ($s->fake->events[(string) $row['google_event_id']]['iCalUID'] ?? null)));
        $e = PA::caught(fn() => $s->events->patch($s->u, $id, ['title' => 'Sample series, once']));
        checkEq('an edit of its title goes through (the stand-in holds ' . count($under) . ' records under its iCalUID)', null, PA::code($e) === null ? null : PA::code($e) . ': ' . $e->getMessage());
        $e = PA::caught(fn() => $s->events->deleteEvent($s->u, $id, null, null));
        checkEq('a delete of it goes through', null, PA::code($e) === null ? null : PA::code($e) . ': ' . $e->getMessage());
    });

    // The same event as Google can hold it: no longer repeating, its old records still listed under its iCalUID
    // (cancelled, as a list with showDeleted=true shows a deleted record: stage listread, L5 and L7).
    scenario('access read, first read: an event in no series whose iCalUID still lists an old cancelled record of a day can be edited', function () use ($db, $when): void {
        $s = PA::make($db);
        $s->fake->seed(['id' => 'smplonce', 'iCalUID' => 'smplonce@google.com', 'summary' => 'Sample once', 'creator' => ['email' => PA::ACCOUNT],
            'start' => $when('2027-03-01 10:00'), 'end' => $when('2027-03-01 11:00')]);
        $s->fake->seed(['id' => 'smplonce_20270308T180000Z', 'iCalUID' => 'smplonce@google.com', 'status' => 'cancelled', 'recurringEventId' => 'smplonce',
            'originalStartTime' => $when('2027-03-08 10:00'), 'start' => $when('2027-03-08 10:00'), 'end' => $when('2027-03-08 11:00')]);
        ($s->poll)();
        $id = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, 'smplonce']);
        check('the poll stored the event (the probe\'s own step)', $id > 0, 'no row');
        $e = PA::caught(fn() => $s->events->patch($s->u, $id, ['title' => 'Sample once, renamed']));
        checkEq('an edit of its title goes through: nothing changed at Google since the poll', [null, 'Sample once, renamed'],
            [PA::code($e) === null ? null : PA::code($e) . ': ' . $e->getMessage(), $s->fake->events['smplonce']['summary'] ?? null]);
    });

    // Section 2: "Every first read is a list, and an event missing from a list that answered 200 with a level is the one
    // meaning of 'deleted at Google'"; a deleted event is listed cancelled with showDeleted (stage listread, L5).
    scenario('access read, first read: an event deleted at Google since the last poll: an edit is refused as deleted with nothing sent, and a delete completes here, each after one list and no other read', function () use ($db, $la): void {
        foreach (['an edit', 'a delete'] as $what) {
            $s = PA::make($db);
            $id = (int) $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => 'Sample single', 'start' => '2027-03-02T09:00:00-08:00', 'end' => '2027-03-02T10:00:00-08:00', 'tzid' => $la])['eventId'];
            $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
            $answer = ($s->fake->transport())('DELETE', 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(PA::ACCOUNT) . '/events/' . rawurlencode($gid), ['Authorization: Bearer any', 'X-Harness: remote'], null);
            check("$what: the event is deleted at Google by another client (the probe's own step)", (int) $answer['status'] === 204, json_encode($answer));
            $from = count($s->fake->requests);
            $e = PA::caught(fn() => $what === 'an edit' ? $s->events->patch($s->u, $id, ['title' => 'Sample single, renamed']) : $s->events->deleteEvent($s->u, $id, null, null));
            $here = (int) $db->scalar('SELECT COUNT(*) FROM events WHERE id = ? AND deleted_at IS NULL', [$id]);
            checkEq("$what of an event deleted at Google: " . ($what === 'an edit' ? 'refused as deleted, the row kept' : 'completes, the row gone') . '; one list, nothing written',
                [$what === 'an edit' ? 'google_deleted' : null, $what === 'an edit' ? 1 : 0, ['GET list'], []], [PA::code($e), $here, PA::sent($s->fake, $from), PA::taken($s->fake, $from)]);
        }
    });

    // A row whose uid is not Google's iCalUID (the model, section 2: "the event is then read by its id, after the list").
    scenario('access read, first read: an event whose row holds another uid than Google\'s iCalUID is still edited, read by its id after the list', function () use ($db, $la): void {
        $s = PA::make($db);
        $id = (int) $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => 'Sample single', 'start' => '2027-03-02T09:00:00-08:00', 'end' => '2027-03-02T10:00:00-08:00', 'tzid' => $la])['eventId'];
        $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
        $db->run('UPDATE events SET uid = ? WHERE id = ?', [$gid, $id]);
        $from = count($s->fake->requests);
        $e = PA::caught(fn() => $s->events->patch($s->u, $id, ['title' => 'Sample single, renamed']));
        checkEq('the edit goes through: the list, the event by its id, the write', [null, ['GET list', 'GET id', 'PATCH id']], [PA::code($e) === null ? null : PA::code($e) . ': ' . $e->getMessage(), PA::sent($s->fake, $from)]);
        // And below writer it is refused by the rule, never read as deleted.
        $s->fake->role = 'freeBusyReader';
        $e = PA::caught(fn() => $s->events->deleteEvent($s->u, $id, null, null));
        checkEq('busy times only: its delete is refused as read-only, and the row is still here', ['google_read_only', 1], [PA::code($e), (int) $db->scalar('SELECT COUNT(*) FROM events WHERE id = ? AND deleted_at IS NULL', [$id])]);
    });
};
