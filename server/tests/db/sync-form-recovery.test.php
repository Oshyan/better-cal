<?php

declare(strict_types=1);

// A change of form made here (a series at 19:00 New York made all-day, or
// an all-day one made 19:00 New York) that stops part way, then Finish, and
// separately Put back. When it stops, the app stores what Google holds
// (GoogleSeries::store through Feeds::sync) while the rows here are still
// in the old form: the read-back is the series in its new form, with the
// edited day's record in the new form when that write was taken. West of
// UTC that record's key is the key the neighbouring day's row had (all-day
// 7 January has the key 19:00 on the 6th had), so the store is where a
// record meets rows of the other form on this path
// (workfolder/reviews/audit-155-identity.md, row 24; the sync's match,
// Feeds::standing).
//
// From the rule: a tag given to one day and notes given to the next are
// each on their own day at every point: after the stop, after Finish, and
// after Put back, which leaves the rows here and Google as they were.
// Since the probe of 2026-11-13 that meeting makes the switch refused on a
// Google calendar (Google would hide the day for good: google-hidden-switch),
// so the stop, Finish and Put back this file walked through can't happen
// there; it now checks the refusal, with the state before it.
// Every expected key and id is written by this file's own two functions,
// the rows are read by SQL and Google by the stand-in's store. Google is
// the edit harness's stand-in. Invented data.

use BetterCal\EditHarness\GoogleStand;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    $notes = 'Bring the sample checklist';
    $utc = new DateTimeZone('UTC');
    // The key of day $d of January: its date at 00:00 UTC (all-day), or 19:00 New York in UTC (timed).
    $keyOf = static fn(bool $allDay, int $d): string => $allDay
        ? sprintf('2027-01-%02d 00:00:00', $d)
        : (new DateTimeImmutable(sprintf('2027-01-%02d 19:00', $d), new DateTimeZone('America/New_York')))->setTimezone($utc)->format('Y-m-d H:i:s');
    // The suffix of Google's id for that day, in the series' form.
    $suffixOf = static fn(bool $allDay, string $key): string => (new DateTimeImmutable($key, $utc))->format($allDay ? 'Ymd' : 'Ymd\THis\Z');
    // The day rows here, by key: form, notes, own list and tags.
    $here = static function (int $id) use ($db): array {
        $out = [];
        foreach ($db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY recurrence_instance_utc', [$id]) as $r) {
            $out[(string) $r['recurrence_instance_utc']] = [(int) $r['all_day'], $r['description'], $r['override_fields'] === null ? null : json_encode(json_decode((string) $r['override_fields'], true)),
                array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [(int) $r['id']]), 'name')];
        }
        return $out;
    };
    // What Google holds: the series' form, and each record's notes by its id's suffix.
    $google = static function (GoogleStand $s, string $gid): array {
        $records = [];
        foreach ($s->fake->records($gid) as $suffix => $r) {
            $records[(string) $suffix] = ($r['status'] ?? '') === 'cancelled' ? 'cancelled' : ($r['description'] ?? null);
        }
        return [isset($s->fake->events[$gid]['start']['date']), $records];
    };
    $settledHere = static fn(int $u, int $calendarId): array => [
        (int) $db->scalar('SELECT COUNT(*) FROM google_pending WHERE user_id = ?', [$u]),
        (int) $db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND recurrence_parent_id IS NOT NULL AND recurrence_instance_utc IS NULL', [$calendarId]),
    ];

    /**
     * A daily series of ten from 4 January 2027 in one form, a tag on $c['tagged'] and notes on 7 January, made the
     * other form for all events and stopped at $c['stop'], then $c['then'].
     */
    $run = static function (array $c) use ($db, $notes, $keyOf, $suffixOf, $here, $google, $settledHere): void {
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        $name = $c['name'];
        $to = $c['toAllDay'];
        $tagged = $c['tagged'];
        $was = static fn(int $day): string => $keyOf(!$to, $day);
        $now = static fn(int $day): string => $keyOf($to, $day);
        $at = static fn(int $day): string => $to ? sprintf('2027-01-%02dT19:00:00-05:00', $day) : sprintf('2027-01-%02d', $day);
        $timed = ['start' => '2027-01-04T19:00:00-05:00', 'end' => '2027-01-04T20:00:00-05:00', 'allDay' => false, 'tzid' => 'America/New_York'];
        $days = ['start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true, 'tzid' => 'UTC'];
        try {
            $e = $s->events;
            $id = (int) $e->create($u, ['calendarId' => $s->calendarId, 'title' => 'Sample practice', 'rrule' => 'FREQ=DAILY;COUNT=10'] + ($to ? $timed : $days))['eventId'];
            $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $at($tagged), 'tagNames' => ['sample-day']]);
            $e->throwDeferred();
            $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $at(7), 'description' => $notes]);
            $e->throwDeferred();
            $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
            $hereBefore = $here($id);
            $googleBefore = $google($s, $gid);
            $rowsBefore = $db->all('SELECT id, recurrence_instance_utc FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id', [$id]);
            checkEq("$name: before, the tag and the notes are each on their own day, and Google holds the notes' record in the series' form", [
                [$was(min($tagged, 7)) => $tagged < 7 ? [(int) !$to, null, '["tags"]', ['sample-day']] : [(int) !$to, $notes, '["description"]', []],
                    $was(max($tagged, 7)) => $tagged > 7 ? [(int) !$to, null, '["tags"]', ['sample-day']] : [(int) !$to, $notes, '["description"]', []]],
                [!$to, [$suffixOf(!$to, $was(7)) => $notes]],
            ], [$hereBefore, $googleBefore]);

            // Since the probe of 2026-11-13 (3.48 to 3.51, 3.63 to 3.65): the notes' record sits at the instant of a day of
            // the new form (that meeting is this file's subject), and Google would hide that day for good, so on a Google
            // calendar the switch is refused before anything is sent, and nothing stops half way.
            $s->fake->requests = [];
            $refused = null;
            try {
                planned($e, $u, $id, ['scope' => 'all', 'instanceStart' => $at(4)] + ($to ? $days : $timed));
                $e->throwDeferred();
            } catch (HttpError $error) {
                $refused = $error->errorCode;
            }
            $writes = array_values(array_filter($s->fake->requests, static fn(array $r): bool => $r['method'] !== 'GET'));
            checkEq("$name: the switch is refused on Google, nothing sent, nothing half done, Google and the rows here as they were", [true, [], [0, 0], $googleBefore, $hereBefore],
                [in_array($refused, ['series_refused', 'google_hidden_days'], true), $writes, $settledHere($u, $s->calendarId), $google($s, $gid), $here($id)]);
        } finally {
            GoogleStand::forget($db, $u);
        }
    };

    // Made all-day, the record of 7 January has the key 19:00 on the 6th had: the tag is on the 6th. Made 19:00 New
    // York, the record of 7 January has the key the all-day 8th had: the tag is on the 8th.
    foreach (['19:00 New York made all-day, a tag on 6 January and notes on the 7th' => [true, 6], 'all-day made 19:00 New York, notes on 7 January and a tag on the 8th' => [false, 8]] as $what => [$toAllDay, $tagged]) {
        scenario("sync form recovery, $what: refused on Google", fn() => $run(['name' => $what, 'toAllDay' => $toAllDay, 'tagged' => $tagged]));
    }
};
