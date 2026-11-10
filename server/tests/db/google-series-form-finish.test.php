<?php

declare(strict_types=1);

// Finish of a change between timed and all-day whose master write never
// reached Google, after a change made at Google meanwhile (decisions.md,
// "A change made at Google while a change of form waits is named, not
// carried"; series-model.md, Recovery). The change stops with the network
// down at its master PATCH: the journal holds the step as sent, Google
// still holds the series in the old form. Someone then renames a day at
// Google, deletes one, gives one another time, or changes the notes of the
// day the plan carries. Finish sends the master write, which drops every
// record Google holds, and no id of the old form names a day of the new
// series.
//
// What a person must see, from the spec: Finish completes (the change is
// never stuck), the series holds the plan and nothing else, at Google and
// here, with the edited day on its own date; nothing is sent to an id of
// the old form or to a day the series does not have; and the change made at
// Google is named once, by the date the day had when it was made (the
// evening of Fri 8 Jan in New York is Fri 8 Jan), with what was done and
// that it is not kept. A poll after changes nothing.
//
// Before this, by the same twelve runs: a rename or deletion landed on the
// next day west of UTC with no word, was lost under sentences naming a day
// the series does not have when all-day was made timed, and a day given
// its own time left the change failing with a 400 at every Finish.
//
// Each block is a scenario(). Google is the edit harness's stand-in. Every
// expected value is written from the calendar by date; nothing in
// server/src computes one. Invented data.

use BetterCal\EditHarness\GoogleStand;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    $notes = 'Own notes for the day.';
    $url = static fn(string $id): string => 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(GoogleStand::CALENDAR) . '/events/' . rawurlencode($id);
    $remote = ['Authorization: Bearer sample-access', 'X-Harness: remote'];
    // A date as the app words one: the year only when it is not this year.
    $date = static fn(string $ymd): string => (new DateTimeImmutable($ymd))->format(substr($ymd, 0, 4) === gmdate('Y') ? 'D j M' : 'D j M Y');
    $rows = 'SELECT id, recurrence_parent_id, recurrence_instance_utc, title, description, start_utc, end_utc, all_day, tzid, exdates_json, google_event_id, deleted_at FROM events WHERE user_id = ? ORDER BY id';

    // Each series has one edited day (its own notes), which the plan carries. `day` is the day changed at Google, by
    // the suffix of its id in the old form and by its date; `edited` the plan's edited day the same way; `planned`
    // where that edited day is once the change is done: its id's suffix in the new form and its key here.
    $shapes = [
        'daily 19:00 New York made all-day' => [
            'create' => ['start' => '2027-01-04T19:00:00-05:00', 'end' => '2027-01-04T20:00:00-05:00', 'tzid' => 'America/New_York', 'rrule' => 'FREQ=DAILY;COUNT=8'],
            'edit' => '2027-01-06T19:00:00-05:00',
            'flip' => ['scope' => 'all', 'instanceStart' => '2027-01-04T19:00:00-05:00', 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true],
            'allDay' => true, 'why' => 'now all-day',
            'day' => ['20270109T000000Z', '2027-01-08'], 'edited' => ['20270107T000000Z', '2027-01-06'], 'planned' => ['20270106', '2027-01-06 00:00:00'],
            'retime' => [['start' => ['dateTime' => '2027-01-08T15:00:00-05:00', 'timeZone' => 'America/New_York'], 'end' => ['dateTime' => '2027-01-08T16:00:00-05:00', 'timeZone' => 'America/New_York']], 'given another time'],
        ],
        'weekly 10:00 Los Angeles made all-day' => [
            'create' => ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=6'],
            'edit' => '2027-01-11T10:00:00-08:00',
            'flip' => ['scope' => 'all', 'instanceStart' => '2027-01-04T10:00:00-08:00', 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true],
            'allDay' => true, 'why' => 'now all-day',
            'day' => ['20270118T180000Z', '2027-01-18'], 'edited' => ['20270111T180000Z', '2027-01-11'], 'planned' => ['20270111', '2027-01-11 00:00:00'],
            'retime' => [['start' => ['dateTime' => '2027-01-18T15:00:00-08:00', 'timeZone' => 'America/Los_Angeles'], 'end' => ['dateTime' => '2027-01-18T16:00:00-08:00', 'timeZone' => 'America/Los_Angeles']], 'given another time'],
        ],
        'weekly all-day made 10:00 Los Angeles' => [
            'create' => ['start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true, 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=6'],
            'edit' => '2027-01-11',
            'flip' => ['scope' => 'all', 'instanceStart' => '2027-01-04', 'start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'allDay' => false, 'tzid' => 'America/Los_Angeles'],
            'allDay' => false, 'why' => 'no longer all-day',
            'day' => ['20270118', '2027-01-18'], 'edited' => ['20270111', '2027-01-11'], 'planned' => ['20270111T180000Z', '2027-01-11 18:00:00'],
            'retime' => [['start' => ['date' => '2027-01-19'], 'end' => ['date' => '2027-01-20']], 'moved to another date'],
        ],
    ];
    // What is done at Google meanwhile: [method, which day, body], and the words for it.
    $changes = [
        'a day renamed at Google' => static fn(array $c): array => ['PATCH', 'day', ['summary' => 'Renamed at Google'], 'renamed'],
        'a day deleted at Google' => static fn(array $c): array => ['DELETE', 'day', null, 'deleted'],
        'a day given another time at Google' => static fn(array $c): array => ['PATCH', 'day', $c['retime'][0], $c['retime'][1]],
        'the plan\'s edited day given other notes at Google' => static fn(array $c): array => ['PATCH', 'edited', ['description' => 'Notes from Google'], 'given other notes'],
    ];

    /** The series made, its change of form sent with the network down from request number $downAt on (null: never); returns the stand, the account, the series and what stopped it. */
    $begin = static function (array $c, ?int $downAt) use ($db, $notes): array {
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        $e = $s->events;
        $id = (int) $e->create($u, ['calendarId' => $s->calendarId, 'title' => 'Sample series'] + $c['create'])['eventId'];
        $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $c['edit'], 'description' => $notes]);
        $e->throwDeferred();
        $base = count($s->fake->requests);
        if ($downAt !== null) {
            $s->fake->failAfter = $base + $downAt;
        }
        $stopped = null;
        try {
            planned($e, $u, $id, $c['flip']);
            $e->throwDeferred();
        } catch (\Throwable $x) {
            $stopped = $x;
        }
        $s->fake->failAfter = null;
        return [$s, $u, $id, $stopped, $base];
    };

    foreach ($shapes as $shape => $c) {
        foreach ($changes as $what => $build) {
            scenario("google form finish: $shape, its master write not taken, $what, then Finish", function () use ($db, $shape, $c, $what, $build, $begin, $notes, $url, $remote, $date, $rows): void {
                $name = "$shape, $what";
                // How many requests the change makes before its master write: counted on a run that goes through.
                [$s, $u, , , $base] = $begin($c, null);
                $before = null;
                foreach (array_slice($s->fake->requests, $base) as $i => $r) {
                    if ($r['method'] !== 'GET') {
                        $before = $i;
                        break;
                    }
                }
                GoogleStand::forget($db, $u);
                [$s, $u, $id, $stopped] = $begin($c, $before);
                try {
                    $e = $s->events;
                    $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
                    $pending = $db->all('SELECT id, status, steps_json FROM google_pending WHERE user_id = ?', [$u]);
                    $steps = array_map(static fn(array $st): string => $st['kind'] . ' ' . $st['state'], json_decode((string) ($pending[0]['steps_json'] ?? '[]'), true) ?: []);
                    checkEq("$name: the change stops half done, its master write sent and not taken, Google still in the old form",
                        [true, 1, 'half', ['master sent'], !$c['allDay']],
                        [$stopped !== null, count($pending), $pending[0]['status'] ?? null, $steps, isset($s->fake->events[$gid]['start']['date'])]);

                    [$method, $which, $body, $done] = $build($c);
                    $answer = ($s->fake->transport())($method, $url($gid . '_' . $c[$which][0]), $remote, $body);
                    check("$name: Google takes the change made there", in_array((int) ($answer['status'] ?? 0), [200, 204], true), 'HTTP ' . (int) ($answer['status'] ?? 0));

                    $s->fake->requests = [];
                    $out = null;
                    $failed = null;
                    try {
                        $out = $e->googleFinish($u, (int) ($pending[0]['id'] ?? 0));
                        $e->throwDeferred();
                    } catch (\Throwable $x) {
                        $failed = get_class($x) . ': ' . $x->getMessage();
                    }
                    checkEq("$name: Finish completes and the change is settled", [null, 0], [$failed, (int) $db->scalar('SELECT COUNT(*) FROM google_pending WHERE user_id = ?', [$u])]);
                    checkEq("$name: Finish names the change made at Google once, by the day's own date, as not kept",
                        [$date($c[$which][1]) . " was $done at Google since this change stopped; that change is not kept, because the series is {$c['why']}."], $out['differences'] ?? null);
                    $sent = array_map(static fn(array $r): string => $r['method'] . ' ' . str_replace($gid, '{series}', rawurldecode(basename((string) $r['path']))),
                        array_values(array_filter($s->fake->requests, static fn(array $r): bool => $r['method'] !== 'GET')));
                    checkEq("$name: Finish writes the master and the plan's edited day under its new id, nothing to an old id or to a day the series does not have",
                        ['PATCH {series}', 'PATCH {series}_' . $c['planned'][0]], $sent);

                    $held = [];
                    foreach ($s->fake->records($gid) as $key => $r) {
                        $held[(string) $key] = [(string) ($r['status'] ?? ''), (string) ($r['summary'] ?? ''), (string) ($r['description'] ?? '')];
                    }
                    checkEq("$name: Google holds the series in its new kind with the plan's edited day alone, on its own date with its notes",
                        [$c['allDay'], [$c['planned'][0] => ['confirmed', 'Sample series', $notes]]], [isset($s->fake->events[$gid]['start']['date']), $held]);
                    $master = $db->one('SELECT all_day, exdates_json FROM events WHERE id = ?', [$id]);
                    $days = array_map(static fn(array $r): array => [(string) $r['recurrence_instance_utc'], (string) $r['title'], (string) $r['description'], (string) $r['google_event_id']],
                        $db->all('SELECT recurrence_instance_utc, title, description, google_event_id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY recurrence_instance_utc', [$id]));
                    checkEq("$name: the rows here hold the same: the edited day's row on its own date with its notes, no other day, no skip",
                        [$c['allDay'] ? 1 : 0, [], [[$c['planned'][1], 'Sample series', $notes, $gid . '_' . $c['planned'][0]]]],
                        [(int) $master['all_day'], json_decode((string) ($master['exdates_json'] ?? '[]'), true) ?: [], $days]);
                    $snapshot = $db->all($rows, [$u]);
                    $s->poll();
                    checkEq("$name: a poll after changes no row", $snapshot, $db->all($rows, [$u]));
                } finally {
                    GoogleStand::forget($db, $u);
                }
            });
        }
    }
};
