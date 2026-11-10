<?php

declare(strict_types=1);

// Rule 1, provider identity, on the recovery paths (series-model.md,
// Recovery; decisions.md, "What a master write leaves at Google is predicted
// kept only by identity and proof"). A whole-series change stops right
// after its master write: the write Google takes next is refused, so the
// master step is the last one journaled. What the app says the series holds
// then is its prediction of the master write's own effect, which is what
// Put back compares Google with before it reverses anything.
//
// From the spec: Put back goes ahead only while nothing changed at Google
// since, and then leaves Google and the rows here as they were before the
// change; Finish completes the change, an edited day keeping its own notes
// and place and a skipped day staying skipped. A change between timed and
// all-day drops every record at Google, so right after its master write
// Google holds none, as predicted, and Put back goes through. A zone change
// that moves no instant is predicted to drop them too until a live probe
// answers (case 3.17), while the stand-in keeps them: there Put back is
// refused and Finish is the way out, the documented cost of that
// prediction. That last scenario states today's decision, and changes when
// the probe answers.
//
// Each block is a scenario(). Google is the edit harness's stand-in. Every
// expected value is written from the spec; nothing in server/src computes
// one. Invented data.

use BetterCal\EditHarness\GoogleStand;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    $notes = 'Bring the sample checklist';
    $place = 'Sample Room';

    /** What the stand-in holds for a series: whether it is all-day, its zone, and each record by its id's suffix as [cancelled, notes, place]. */
    $google = static function (GoogleStand $s, string $gid): array {
        $m = $s->fake->events[$gid] ?? [];
        $records = [];
        foreach ($s->fake->records($gid) as $key => $r) {
            $cancelled = ($r['status'] ?? '') === 'cancelled';
            $records[(string) $key] = [$cancelled, $cancelled ? null : ($r['description'] ?? null), $cancelled ? null : ($r['location'] ?? null)];
        }
        return ['allDay' => isset($m['start']['date']), 'zone' => $m['start']['timeZone'] ?? null, 'records' => $records];
    };
    /** The rows here: the series' kind, zone and skips, and each edited day as [key, notes, place, all-day]. */
    $here = static function (Db $db, int $id): array {
        $m = $db->one('SELECT all_day, tzid, exdates_json FROM events WHERE id = ?', [$id]);
        $days = array_map(static fn(array $r): array => [(string) $r['recurrence_instance_utc'], $r['description'], $r['location'], (int) $r['all_day']],
            $db->all('SELECT recurrence_instance_utc, description, location, all_day FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY recurrence_instance_utc', [$id]));
        return ['allDay' => (int) $m['all_day'], 'zone' => (string) $m['tzid'], 'skips' => json_decode((string) ($m['exdates_json'] ?? '[]'), true) ?: [], 'days' => $days];
    };
    $pending = static fn(Db $db, int $u): array => $db->all('SELECT id, status FROM google_pending WHERE user_id = ? ORDER BY id', [$u]);

    /**
     * A weekly series of four with an edited day (its own notes and place) and a skipped day, then the whole-series
     * change $c['body'] stopped right after its master write, then $c['then'] (putBack or finish). $c names what must
     * hold after: `google` and `here` as the two readers above give them.
     */
    $run = static function (array $c) use ($db, $notes, $place, $google, $here, $pending): void {
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        $name = $c['name'];
        try {
            $e = $s->events;
            $id = (int) $e->create($u, ['calendarId' => $s->calendarId, 'title' => 'Sample weekly'] + $c['create'])['eventId'];
            $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $c['edit'], 'description' => $notes, 'location' => $place]);
            $e->throwDeferred();
            $e->deleteEvent($u, $id, 'this', $c['skip']);
            $e->throwDeferred();
            $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
            $googleBefore = $google($s, $gid);
            $hereBefore = $here($db, $id);
            checkEq("$name: before the change Google holds the edited day and the skip in the series' own form", $c['before'], $googleBefore['records']);

            // The change, stopped right after its master write: the next write Google would take is refused, or
            // (where the master write leaves nothing more to send) the master write's own answer is lost on the way back.
            if (($c['stop'] ?? 'next refused') === 'answer lost') {
                $s->knobs->loseAt = $s->knobs->writes + 1;
            } else {
                $s->knobs->beforeAt = $s->knobs->writes + 2;
            }
            $stopped = null;
            try {
                planned($e, $u, $id, $c['body'], $c['choices'] ?? []);
                $e->throwDeferred();
            } catch (HttpError $error) {
                $stopped = $error;
            }
            $rows = $pending($db, $u);
            checkEq("$name: the change stops half done after its master write, and says so", [true, 1, 'half'],
                [$stopped !== null && isset($stopped->extra['pending']), count($rows), $rows[0]['status'] ?? null]);
            checkEq("$name: right after the master write Google holds the series in its new kind with " . $c['left'], [$c['afterMaster'][0], $c['afterMaster'][1]],
                [$google($s, $gid)['allDay'], $google($s, $gid)['records']]);

            $s->fake->requests = [];
            $refused = null;
            $out = [];
            try {
                $out = $c['then'] === 'putBack' ? $e->googlePutBack($u, (int) $rows[0]['id']) : $e->googleFinish($u, (int) $rows[0]['id']);
                $e->throwDeferred();
            } catch (HttpError $error) {
                $refused = $error;
            }
            $writes = array_values(array_filter($s->fake->requests, static fn(array $r): bool => $r['method'] !== 'GET'));
            if ($c['then'] === 'putBack' && empty($c['refused'])) {
                checkEq("$name: Put back goes through, nothing left to settle", [null, true, []], [$refused?->errorCode, $out['ok'] ?? null, $pending($db, $u)]);
                checkEq("$name: after Put back Google holds the series as it was, the edited day and the skip under their old ids", $googleBefore, $google($s, $gid));
                checkEq("$name: after Put back the rows here are as they were", $hereBefore, $here($db, $id));
            } elseif ($c['then'] === 'putBack') {
                checkEq("$name: Put back is refused, naming Finish, with nothing sent and the change still in Review", ['google_changed_since', true, [], 1],
                    [$refused?->errorCode, str_contains((string) $refused?->getMessage(), 'Finish'), $writes, count($pending($db, $u))]);
                // Finish is the way out: the change completes with the edited day's notes and place and the skip.
                $e->googleFinish($u, (int) $rows[0]['id']);
                $e->throwDeferred();
                checkEq("$name: Finish then completes the change at Google: the edited day with its notes and place, the skip cancelled", [$c['done'], []], [$google($s, $gid), $pending($db, $u)]);
                checkEq("$name: and here: the edited day's row with its notes and place, the skip", $c['doneHere'], $here($db, $id));
            } else {
                checkEq("$name: Finish goes through, nothing left to settle", [null, []], [$refused?->errorCode, $pending($db, $u)]);
                checkEq("$name: after Finish Google holds the edited day with its notes and place and the skip under their new ids", $c['done'], $google($s, $gid));
                checkEq("$name: after Finish the rows here hold the edited day's notes and place and the skip", $c['doneHere'], $here($db, $id));
            }
        } finally {
            GoogleStand::forget($db, $u);
        }
    };

    $timedUtc = ['start' => '2027-01-04T00:00:00Z', 'end' => '2027-01-04T01:00:00Z', 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
    $allDay = ['start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true, 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
    $toAllDay = ['scope' => 'all', 'instanceStart' => '2027-01-04T00:00:00Z', 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true];
    $toTimed = ['scope' => 'all', 'instanceStart' => '2027-01-04', 'start' => '2027-01-04T00:00:00Z', 'end' => '2027-01-04T01:00:00Z', 'allDay' => false, 'tzid' => 'UTC'];
    $timedRecords = ['20270111T000000Z' => [false, $notes, $place], '20270118T000000Z' => [true, null, null]];
    $dayRecords = ['20270111' => [false, $notes, $place], '20270118' => [true, null, null]];
    $dayHere = ['allDay' => 1, 'zone' => 'UTC', 'skips' => ['2027-01-18 00:00:00'], 'days' => [['2027-01-11 00:00:00', $notes, $place, 1]]];
    $timedHere = ['allDay' => 0, 'zone' => 'UTC', 'skips' => ['2027-01-18 00:00:00'], 'days' => [['2027-01-11 00:00:00', $notes, $place, 0]]];

    // A change between timed and all-day where the keys here coincide (00:00 UTC): Google drops every record on the
    // master write, the app predicts exactly that, so nothing "changed at Google since" and Put back reverses it.
    scenario('timed at midnight UTC made all-day, stopped after the master write, put back', fn() => $run([
        'name' => 'timed 00:00 UTC to all-day, stopped after the master write, Put back', 'create' => $timedUtc, 'edit' => '2027-01-11T00:00:00Z', 'skip' => '2027-01-18T00:00:00Z',
        'body' => $toAllDay, 'before' => $timedRecords,
        'afterMaster' => [true, []], 'left' => 'no record', 'then' => 'putBack',
    ]));
    scenario('all-day made timed at midnight UTC, stopped after the master write, put back', fn() => $run([
        'name' => 'all-day to timed 00:00 UTC, stopped after the master write, Put back', 'create' => $allDay, 'edit' => '2027-01-11', 'skip' => '2027-01-18',
        'body' => $toTimed, 'before' => $dayRecords,
        'afterMaster' => [false, []], 'left' => 'no record', 'then' => 'putBack',
    ]));
    // The same stops, finished instead: the edited day and the skip are sent again under the new form's ids.
    scenario('timed at midnight UTC made all-day, stopped after the master write, finished', fn() => $run([
        'name' => 'timed 00:00 UTC to all-day, stopped after the master write, Finish', 'create' => $timedUtc, 'edit' => '2027-01-11T00:00:00Z', 'skip' => '2027-01-18T00:00:00Z',
        'body' => $toAllDay, 'before' => $timedRecords,
        'afterMaster' => [true, []], 'left' => 'no record', 'then' => 'finish',
        'done' => ['allDay' => true, 'zone' => null, 'records' => $dayRecords], 'doneHere' => $dayHere,
    ]));
    scenario('all-day made timed at midnight UTC, stopped after the master write, finished', fn() => $run([
        'name' => 'all-day to timed 00:00 UTC, stopped after the master write, Finish', 'create' => $allDay, 'edit' => '2027-01-11', 'skip' => '2027-01-18',
        'body' => $toTimed, 'before' => $dayRecords,
        'afterMaster' => [false, []], 'left' => 'no record', 'then' => 'finish',
        'done' => ['allDay' => false, 'zone' => 'UTC', 'records' => $timedRecords], 'doneHere' => $timedHere,
    ]));

    // The zone alone: 10:00 Los Angeles to 11:00 Denver, the same moments (in January the two are an hour apart all
    // month). Predicted to drop every record pending the live probe (case 3.17); the stand-in keeps them. So Put back
    // right after the master write is refused, and Finish completes the change with the edited day and the skip intact.
    $la = ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
    $laRecords = ['20270111T180000Z' => [false, $notes, $place], '20270118T180000Z' => [true, null, null]];
    scenario('a zone change keeping the same moments, stopped after the master write: Put back refused, Finish settles it (pending probe case 3.17)', fn() => $run([
        'name' => 'the zone alone (Los Angeles to Denver, the same moments), stopped after the master write', 'create' => $la, 'edit' => '2027-01-11T10:00:00-08:00', 'skip' => '2027-01-18T10:00:00-08:00',
        // The request names the series' clock in the new zone; the "same moment" reading keeps each instant, so the series shows at 11:00 in Denver.
        'body' => ['scope' => 'all', 'instanceStart' => '2027-01-04T10:00:00-08:00', 'tzid' => 'America/Denver', 'start' => '2027-01-04T10:00:00-07:00', 'end' => '2027-01-04T11:00:00-07:00'],
        'choices' => ['zone' => 'moment'], 'before' => $laRecords, 'stop' => 'answer lost',
        'afterMaster' => [false, $laRecords], 'left' => 'the edited day and the skip kept (the stand-in; real Google is not known)', 'then' => 'putBack', 'refused' => true,
        'done' => ['allDay' => false, 'zone' => 'America/Denver', 'records' => $laRecords],
        'doneHere' => ['allDay' => 0, 'zone' => 'America/Denver', 'skips' => ['2027-01-18 18:00:00'], 'days' => [['2027-01-11 18:00:00', $notes, $place, 0]]],
    ]));
};
