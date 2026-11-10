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

            // The stop: the write after the master's is refused, or it is taken and its answer lost.
            if ($c['stop'] === 'after the master write') {
                $s->knobs->beforeAt = $s->knobs->writes + 2;
            } else {
                $s->knobs->loseAt = $s->knobs->writes + 2;
            }
            $stopped = null;
            try {
                planned($e, $u, $id, ['scope' => 'all', 'instanceStart' => $at(4)] + ($to ? $days : $timed));
                $e->throwDeferred();
            } catch (HttpError $error) {
                $stopped = $error;
            }
            $pending = $db->all('SELECT id, status FROM google_pending WHERE user_id = ? ORDER BY id', [$u]);
            $landed = $c['stop'] !== 'after the master write';
            checkEq("$name: the change stops half done, with Google holding the series in its new form" . ($landed ? " and the notes' record under its new id" : ' and no record'), [
                true, 'half', [$to, $landed ? [$suffixOf($to, $now(7)) => $notes] : []],
            ], [$stopped !== null && isset($stopped->extra['pending']), $pending[0]['status'] ?? null, $google($s, $gid)]);
            // What Google holds is stored: the tag, held here alone, is on its own day in the new form, and the
            // notes are on their day exactly when Google holds them.
            $stored = $here($id);
            checkEq("$name: after the stop the tag is on its own day at the new form's key, with no notes, and no row is without a key",
                [[(int) $to, null, ['sample-day']], 0],
                [isset($stored[$now($tagged)]) ? [$stored[$now($tagged)][0], $stored[$now($tagged)][1], $stored[$now($tagged)][3]] : null, $settledHere($u, $s->calendarId)[1]]);
            checkEq("$name: after the stop the notes are " . ($landed ? 'on their own day, with no tag' : 'on no day, as Google holds none'),
                $landed ? [$now(7) => [(int) $to, []]] : [],
                array_map(static fn(array $r): array => [$r[0], $r[3]], array_filter($stored, static fn(array $r): bool => $r[1] === $notes)));
            checkEq("$name: after the stop no other row holds the tag", [$now($tagged)], array_keys(array_filter($stored, static fn(array $r): bool => $r[3] !== [])));

            $refused = null;
            try {
                $c['then'] === 'Put back' ? $e->googlePutBack($u, (int) $pending[0]['id']) : $e->googleFinish($u, (int) $pending[0]['id']);
                $e->throwDeferred();
            } catch (HttpError $error) {
                $refused = $error;
            }
            if ($c['then'] === 'Put back') {
                checkEq("$name: Put back goes through, nothing left to settle and no row without a key", [null, [0, 0]], [$refused?->errorCode, $settledHere($u, $s->calendarId)]);
                checkEq("$name: after Put back Google holds the series as it was, the notes' record under its old id", $googleBefore, $google($s, $gid));
                checkEq("$name: after Put back the rows here are as they were: the tag and the notes each on their own day in the old form", $hereBefore, $here($id));
                checkEq("$name: and they are the rows they were", $rowsBefore, $db->all('SELECT id, recurrence_instance_utc FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id', [$id]));
            } else {
                checkEq("$name: Finish goes through, nothing left to settle and no row without a key", [null, [0, 0]], [$refused?->errorCode, $settledHere($u, $s->calendarId)]);
                checkEq("$name: after Finish Google holds the series in its new form and the notes' record under its new id, nothing on the tagged day", [$to, [$suffixOf($to, $now(7)) => $notes]], $google($s, $gid));
                $expected = [$now($tagged) => [(int) $to, null, '["tags"]', ['sample-day']], $now(7) => [(int) $to, $notes, '["description"]', []]];
                ksort($expected);
                checkEq("$name: after Finish the tag and the notes are each on their own day at the new form's keys", $expected, $here($id));
            }
        } finally {
            GoogleStand::forget($db, $u);
        }
    };

    // Made all-day, the record of 7 January has the key 19:00 on the 6th had: the tag is on the 6th. Made 19:00 New
    // York, the record of 7 January has the key the all-day 8th had: the tag is on the 8th.
    foreach (['19:00 New York made all-day, a tag on 6 January and notes on the 7th' => [true, 6], 'all-day made 19:00 New York, notes on 7 January and a tag on the 8th' => [false, 8]] as $what => [$toAllDay, $tagged]) {
        foreach (['after the master write', 'with the notes\' write taken and its answer lost'] as $stop) {
            foreach (['Finish', 'Put back'] as $then) {
                scenario("sync form recovery, $what, stopped $stop, then $then", fn() => $run([
                    'name' => "$what, stopped $stop, $then", 'toAllDay' => $toAllDay, 'tagged' => $tagged, 'stop' => $stop, 'then' => $then,
                ]));
            }
        }
    }
};
