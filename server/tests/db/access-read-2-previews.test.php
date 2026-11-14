<?php

declare(strict_types=1);

// Probe (second independent read of the access build, 61be6c69..94befaeb): previews now ask Google on a calendar stored
// below writer (the model, docs/design/google-access-detect-and-inform.md, section 8, rule A: "the previews of every
// change (one rule for previews: each starts a change)"). The rules the expectations come from:
// - what is held here alone is changed with no request to Google and is never refused for the calendar's access: the
//   product's own sentence for a feed, "Feed events are read-only except attendance, tags and reminders", and the owner's
//   "a request that buys nothing is not" welcome. Marking Planned, Maybe or Hidden is held here alone on a calendar the
//   account can only view (the commit, setAttendance, sends nothing there). On the commit before this range (61be6c69)
//   the first scenario passes: run it there to see.
// - rule A's cost: "one small list" per refusal on the stored level; a preview is written nowhere (SeriesPlans:
//   "written nowhere"), so what it leaves stored is what Google said and nothing else.
// Nothing is computed from server/src. Invented data.

use BetterCal\Infra\Db;

require_once __DIR__ . '/access-read-lib.php';

return static function (Db $db): void {
    $when = static fn(string $local): array => ['dateTime' => (new DateTimeImmutable($local, new DateTimeZone('America/Los_Angeles')))->format('Y-m-d\TH:i:sP'), 'timeZone' => 'America/Los_Angeles'];
    // A calendar another person shares, with a weekly event of theirs: $level is what the account has had from the start.
    $shared = static function (string $level) use ($db, $when): object {
        $s = PA::make($db, $level);
        $s->fake->role = 'owner';
        $s->fake->seed(['id' => 'smplweekly', 'iCalUID' => 'smplweekly@google.com', 'summary' => 'Sample practice', 'creator' => ['email' => 'host@example.test'],
            'start' => $when('2027-03-01 10:00'), 'end' => $when('2027-03-01 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']]);
        $db->run('UPDATE calendars SET google_access_role = ? WHERE id = ?', ['reader', $s->calendarId]); // a listing that is applied
        $s->fake->role = 'reader';
        ($s->poll)();
        $s->fake->role = $level;
        $db->run('UPDATE calendars SET google_access_role = ? WHERE id = ?', [$level, $s->calendarId]);
        $s->id = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, 'smplweekly']);
        return $s;
    };
    $attendanceOf = static fn(object $s): array => array_column($db->all('SELECT attendance, start_utc FROM events WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY start_utc, id', [$s->calendarId]), 'attendance');

    foreach (['reader', 'freeBusyReader', 'none'] as $level) {
        scenario("access read 2, previews: on a Google calendar the account has at $level, marking a repeating event Planned or Hidden (held here alone) works for one day, for all events and from a day on, with no request to Google", function () use ($db, $shared, $attendanceOf, $level): void {
            foreach (['going', 'hidden'] as $mark) {
                $s = $shared($level);
                check("$level, $mark: the series is here (the probe's own step)", $s->id > 0, 'no row');
                $from = count($s->fake->requests);
                $e = PA::caught(fn() => $s->events->setAttendance($s->u, $s->id, $mark, 'this', '2027-03-08T10:00:00-08:00'));
                checkEq("$level, $mark for one day: goes through, no request", [null, []], [PA::code($e), PA::sent($s->fake, $from)]);
                $e = PA::caught(fn() => $s->events->setAttendance($s->u, $s->id, $mark));
                checkEq("$level, $mark for all events: goes through, no request", [null, []], [PA::code($e), PA::sent($s->fake, $from)]);
                // From a day on, as the client makes it: the preview, then the commit with its plan.
                $s = $shared($level);
                $from = count($s->fake->requests);
                $plan = null;
                $e = PA::caught(function () use ($s, $mark, &$plan): void {
                    $plan = $s->events->previewAttendance($s->u, $s->id, $mark, 'following', '2027-03-15T10:00:00-07:00');
                });
                checkEq("$level, $mark from a day on, the preview: answered, no request to Google", [null, []], [PA::code($e) === null ? null : PA::code($e) . ': ' . $e->getMessage(), PA::sent($s->fake, $from)]);
                $e = PA::caught(fn() => $s->events->setAttendance($s->u, $s->id, $mark, 'following', '2027-03-15T10:00:00-07:00',
                    is_array($plan) && empty($plan['whole']) ? ['hash' => $plan['hash'] ?? null, 'choices' => []] : null));
                checkEq("$level, $mark from a day on, the commit: goes through, no request, and something here carries the mark", [null, [], true],
                    [PA::code($e) === null ? null : PA::code($e) . ': ' . $e->getMessage(), PA::sent($s->fake, $from), in_array($mark, $attendanceOf($s), true)]);
            }
        });
    }

    // What a preview of a change Google holds costs on a calendar stored view only, and what it leaves stored.
    scenario('access read 2, previews: on a calendar stored view only, each preview of a change Google holds costs one small list, changes no row, and leaves stored only what Google said', function () use ($db, $when): void {
        $la = 'America/Los_Angeles';
        $make = static function () use ($db, $la): object {
            $s = PA::make($db);
            $s->id = (int) $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => 'Sample series', 'start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6'])['eventId'];
            ($s->poll)();
            $s->fake->role = 'reader';
            ($s->poll)();
            return $s;
        };
        $previews = [
            'a change to all events' => fn(object $s) => $s->events->previewPatch($s->u, $s->id, ['scope' => 'all', 'title' => 'Sample series, renamed', 'plan' => ['choices' => []]]),
            'this and following' => fn(object $s) => $s->events->previewPatch($s->u, $s->id, ['scope' => 'following', 'instanceStart' => '2027-03-15T10:00:00-07:00', 'title' => 'Sample series, later', 'plan' => ['choices' => []]]),
            'a delete from a day on' => fn(object $s) => $s->events->previewDelete($s->u, $s->id, 'following', '2027-03-15T10:00:00-07:00'),
        ];
        foreach ($previews as $what => $preview) {
            // Still view only at Google.
            $s = $make();
            $before = PA::state($db, $s->u, $s->calendarId, $s->fake);
            $lines = count(PA::lines($db, $s->u, $s->calendarId));
            $from = count($s->fake->requests);
            $e = PA::caught(fn() => $preview($s));
            checkEq("still view only, a preview of $what: refused as read-only after exactly one small list", ['google_read_only', ['GET small']], [PA::code($e), PA::sent($s->fake, $from)]);
            checkEq("still view only, a preview of $what: no row, tag, entry or half-done change differs; no new line; the one item still open", [$before, $lines, ['google_read_only']],
                [PA::state($db, $s->u, $s->calendarId, $s->fake), count(PA::lines($db, $s->u, $s->calendarId)), PA::items($db, $s->u, $s->calendarId)]);
            // Given back: the preview is answered; the commit that follows asks no second small list.
            $s = $make();
            $s->fake->role = 'writer';
            $before = PA::state($db, $s->u, $s->calendarId, $s->fake);
            $from = count($s->fake->requests);
            $e = PA::caught(fn() => $preview($s));
            checkEq("given back, a preview of $what: answered after one small list; no row differs; the level stored writer and the item ended", [null, ['GET small'], $before['rows'], 'writer', []],
                [PA::code($e) === null ? null : PA::code($e) . ': ' . $e->getMessage(), PA::sent($s->fake, $from), PA::state($db, $s->u, $s->calendarId, $s->fake)['rows'], PA::storedRole($db, $s->calendarId), PA::items($db, $s->u, $s->calendarId)]);
        }
        // A preview and its commit, as the client makes a change to all events on a calendar just given back: one small list in all.
        $s = $make();
        $s->fake->role = 'writer';
        $from = count($s->fake->requests);
        $e = PA::caught(function () use ($s): void {
            planned($s->events, $s->u, $s->id, ['scope' => 'all', 'title' => 'Sample series, renamed']);
            $s->events->throwDeferred();
        });
        checkEq('given back, the previews and the commit of a change to all events: through, with one small list in all', [null, 1],
            [PA::code($e) === null ? null : PA::code($e) . ': ' . $e->getMessage(), count(array_filter(PA::sent($s->fake, $from), static fn(string $r): bool => $r === 'GET small'))]);
    });
};
