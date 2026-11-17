<?php

declare(strict_types=1);

// The plain Undo (POST /undo, Undo::undoLatest) says a refusal once: the
// refusal names the entry (entryId), and a caller that sends it back as
// `past` on its next press goes on to the latest change before it that can
// be undone, each entry at or newer than `past` asked again (one that has
// become undoable since is taken). Without `past`, the same refusal each
// time, as before. Activity's undo by id is unchanged. Nothing is stored
// for a refusal (decisions.md, "The plain Undo passes over a refusal the
// caller has already been told"). Invented data.

use BetterCal\Domain\Settings;
use BetterCal\Domain\Undo;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    // A press: 'done' and the entry it took back, or the refusal's code, message and entryId.
    $press = static function (Undo $undo, int $u, ?int $past = null) use ($db): array {
        $was = array_column($db->all('SELECT id FROM mutations WHERE user_id = ? AND undone = 0', [$u]), 'id');
        try {
            $undo->undoLatest($u, null, $past);
            $now = array_column($db->all('SELECT id FROM mutations WHERE user_id = ? AND undone = 0', [$u]), 'id');
            return ['done', array_values(array_map('intval', array_diff($was, $now)))];
        } catch (HttpError $e) {
            return [$e->errorCode, $e->getMessage(), $e->extra['entry'] ?? null];
        }
    };
    $byId = static function (Undo $undo, int $u, int $id): array {
        try {
            $undo->undoById($u, $id);
            return ['done'];
        } catch (HttpError $e) {
            return [$e->errorCode, $e->getMessage()];
        }
    };
    $stale = 'Newer changes exist for this item; undoing would overwrite them';
    $told = $stale . ' It can still be undone in Activity once the newer changes are undone.';

    scenario('plain undo: a day change under a held-back change to all events on a Google calendar is refused once, then the next press reaches the older change', function () use ($db, $press, $byId, $stale, $told): void {
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        try {
            $undo = new Undo($db, null, $s->writer);
            $s->events->create($u, ['calendarId' => $s->calendarId, 'title' => 'Sample errand', 'start' => '2027-01-02T18:00:00+00:00', 'end' => '2027-01-02T19:00:00+00:00']);
            $errand = lastEntry($db, $u);
            $id = (int) $s->events->create($u, ['calendarId' => $s->calendarId, 'title' => 'Sample practice', 'start' => '2027-01-05T18:00:00+00:00', 'end' => '2027-01-05T19:00:00+00:00', 'rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=6'])['eventId'];
            $s->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'title' => 'Sample practice, moved room']);
            $s->events->throwDeferred();
            $day = lastEntry($db, $u);
            planned($s->events, $u, $id, ['scope' => 'all', 'instanceStart' => '2027-01-05T18:00:00+00:00', 'title' => 'Sample rehearsal'], ['ownTitle' => 'give']);
            $s->events->throwDeferred();

            checkEq('the first press lands on the day change and is refused, naming it and saying Activity can undo it later', ['stale_undo', $told, $day], $press($undo, $u));
            checkEq('a press that does not send the refusal back is refused the same way (as before)', ['stale_undo', $told, $day], $press($undo, $u));
            checkEq('a press that sends it back goes on to the older change and undoes it', ['done', [$errand]], $press($undo, $u, $day));
            checkEq("Activity's undo of the day change by id is unchanged: refused as changed since, in its own words", ['stale_undo', $stale], $byId($undo, $u, $day));
        } finally {
            GoogleStand::forget($db, $u);
        }
    });

    scenario('plain undo: an undo that asks (its field changed again in Google Calendar) is said once with its id, and past reaches the change before it', function () use ($db, $press, $told): void {
        $u = freshUser($db);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        try {
            $undo = new Undo($db, null, $s->writer);
            $s->events->create($u, ['calendarId' => $s->calendarId, 'title' => 'Sample errand', 'start' => '2027-01-02T18:00:00+00:00', 'end' => '2027-01-02T19:00:00+00:00']);
            $errand = lastEntry($db, $u);
            $id = (int) $s->events->create($u, ['calendarId' => $s->calendarId, 'title' => 'Sample review', 'start' => '2027-01-05T18:00:00+00:00', 'end' => '2027-01-05T19:00:00+00:00'])['eventId'];
            $s->events->patch($u, $id, ['title' => 'Sample review, moved']);
            $s->events->throwDeferred();
            $rename = lastEntry($db, $u);
            // Another client renames it again in Google Calendar, then a poll reads it (an entry the plain Undo passes over).
            $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
            $answer = ($s->fake->transport())('PATCH', 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(GoogleStand::CALENDAR) . '/events/' . rawurlencode($gid),
                ['Authorization: Bearer sample-access', 'X-Harness: remote'], \BetterCal\GoogleProbe\FakeGoogle::asSet(['summary' => 'Sample review, renamed elsewhere']));
            checkEq('(setup) the rename in Google Calendar is taken', 200, (int) $answer['status']);
            $s->poll();
            $first = $press($undo, $u);
            checkEq('the first press lands on the rename and asks, naming it by its id; nothing is answered for the caller', ['google_changed', $rename],
                [$first[0], $first[2]]);
            checkEq('the title stays as Google Calendar has it', 'Sample review, renamed elsewhere', (string) $db->scalar('SELECT title FROM events WHERE id = ?', [$id]));
            checkEq('a press that does not send it back asks again', ['google_changed', $rename], [$press($undo, $u)[0], $press($undo, $u)[2]]);
            // Before the rename: the event's creation, which the rename and the poll stand on. Sent back, the press passes
            // over the rename (it still asks) and lands there, refused in turn with its own id; sent back again, the errand.
            $made = (int) $db->scalar('SELECT MAX(id) FROM mutations WHERE user_id = ? AND id < ?', [$u, $rename]);
            checkEq('a press that sends it back passes over the question to the change before it, refused once with its own id',
                ['stale_undo', $told, $made], $press($undo, $u, $rename));
            checkEq('sent back in turn, the press reaches the errand and undoes it', ['done', [$errand]], $press($undo, $u, $made));
            checkEq('the question was never answered: the title is still as Google Calendar has it', 'Sample review, renamed elsewhere',
                (string) $db->scalar('SELECT title FROM events WHERE id = ?', [$id]));
        } finally {
            GoogleStand::forget($db, $u);
        }
    });

    // A change the plain Undo passes over (the app's own, here a feed's) stands on a day change.
    $local = static function () use ($db): array {
        $u = freshUser($db);
        $d = domains($db);
        $home = (int) Settings::defaultCalendarId($db, $u);
        $d->events->create($u, eventAt($home, 'Sample errand', '2027-01-02'));
        $errand = lastEntry($db, $u);
        $id = (int) $d->events->create($u, eventAt($home, 'Sample practice', '2027-01-05', ['rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=6']))['eventId'];
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'title' => 'Sample practice, moved room']);
        $day = lastEntry($db, $u);
        planned($d->events, $u, $id, ['scope' => 'all', 'instanceStart' => '2027-01-05T18:00:00+00:00', 'title' => 'Sample rehearsal'], ['ownTitle' => 'give']);
        $all = lastEntry($db, $u);
        $db->update('mutations', ['source' => 'feed'], 'id = ?', [$all]);
        return [$u, $d, $home, $errand, $id, $day, $all];
    };

    scenario('plain undo: an entry named by past that has become undoable since is taken', function () use ($db, $press, $byId, $told, $local): void {
        [$u, $d, , , , $day, $all] = $local();
        checkEq('refused once, naming the day change', ['stale_undo', $told, $day], $press($d->undo, $u));
        checkEq('the change standing on it is undone from Activity', ['done'], $byId($d->undo, $u, $all));
        checkEq('the next press, sending the refusal back, asks the day change again and takes it', ['done', [$day]], $press($d->undo, $u, $day));
    });

    scenario('plain undo: a change made after the refusal is the next press\'s, even with past', function () use ($db, $press, $told, $local): void {
        [$u, $d, $home, , , $day] = $local();
        checkEq('refused once, naming the day change', ['stale_undo', $told, $day], $press($d->undo, $u));
        $d->events->create($u, eventAt($home, 'Sample visit', '2027-01-09'));
        $new = lastEntry($db, $u);
        checkEq('the new change is undone first', ['done', [$new]], $press($d->undo, $u, $day));
        // Before the day change: the series' own making, which the day change and the feed's change stand on.
        $made = (int) $db->scalar('SELECT MAX(id) FROM mutations WHERE user_id = ? AND id < ?', [$u, $day]);
        checkEq('then, past still sent, the press passes over the refused day change and lands on the change before it, refused in turn with its own id',
            ['stale_undo', $told, $made], $press($d->undo, $u, $day));
    });

    scenario('plain undo: with nothing undoable before the refused entry, the next press says so', function () use ($db, $press, $told): void {
        $u = freshUser($db);
        $d = domains($db);
        $home = (int) Settings::defaultCalendarId($db, $u);
        $id = (int) $d->events->create($u, eventAt($home, 'Sample practice', '2027-01-05', ['rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=6']))['eventId'];
        $db->update('mutations', ['source' => 'feed'], 'id = ?', [lastEntry($db, $u)]);
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'title' => 'Sample practice, moved room']);
        $day = lastEntry($db, $u);
        planned($d->events, $u, $id, ['scope' => 'all', 'instanceStart' => '2027-01-05T18:00:00+00:00', 'title' => 'Sample rehearsal'], ['ownTitle' => 'give']);
        $db->update('mutations', ['source' => 'feed'], 'id = ?', [lastEntry($db, $u)]);
        checkEq('refused once', ['stale_undo', $told, $day], $press($d->undo, $u));
        checkEq('then nothing to undo', ['nothing_to_undo', 'Nothing to undo', null], $press($d->undo, $u, $day));
    });
};
