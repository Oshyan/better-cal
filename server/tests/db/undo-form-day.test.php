<?php

declare(strict_types=1);

// An undo, and a redo, of a change to one day of a series, across a change
// of the series' form (timed made all-day, or back) or of its time zone,
// in four zones, made here and brought by a poll. Such a change re-places
// the series' days, and an equal key is another day wherever the key's UTC
// date is not its local one. So the undo acts only where the journal says
// exactly where the day is: its own row is still there, or the change
// recorded a move for it (a row it carried, a skip it carried). A day that
// had no row and no skip then has no recorded place: it is refused, forced
// or not, with nothing written and nothing sent, in every zone, and never
// lands on another day (docs/design/decisions.md, "An undo acts on a day
// only where the journal says exactly where it is"; Undo::dayNow). Until
// 2026-11-10 this file expected such a day placed by its date under the
// form and zone its key was written in; an independent read found that
// reading wrong for a zone change at the same moments and for the
// planner's own moves, so those cells are refusals here now.
//
// Every expected day is this file's own date arithmetic: the day acted on
// is always 6 January 2027 of a daily series of ten from the 4th, the keys
// and Google's instance ids are worked out by the two functions below, the
// rows are read by SQL and Google by the stand-in's own store. Nothing in
// server/src computes an expectation. Google is the edit harness's
// stand-in. Invented data.

use BetterCal\Domain\Feeds;
use BetterCal\Domain\Ics;
use BetterCal\Domain\Rsvp;
use BetterCal\Domain\Settings;
use BetterCal\Domain\Undo;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\EmailSender;
use BetterCal\Support\Time;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    $utc = new DateTimeZone('UTC');
    $first = 'Notes of the sixth';
    // A zone, the series' clock there, and the zone's offset in January.
    $zones = [
        '19:00 New York' => ['America/New_York', '19:00', '-05:00'],
        '07:00 Tokyo' => ['Asia/Tokyo', '07:00', '+09:00'],
        '00:00 UTC' => ['UTC', '00:00', '+00:00'],
        '10:00 Los Angeles' => ['America/Los_Angeles', '10:00', '-08:00'],
    ];
    $tokyoEvening = ['Asia/Tokyo', '19:00', '+09:00'];
    // The key of day $d of January under a form: its date at 00:00 UTC, or its clock in its zone as a UTC instant.
    $keyOf = static function (bool $allDay, int $d, array $z) use ($utc): string {
        return $allDay ? sprintf('2027-01-%02d 00:00:00', $d)
            : (new DateTimeImmutable(sprintf('2027-01-%02d %s', $d, $z[1]), new DateTimeZone($z[0])))->setTimezone($utc)->format('Y-m-d H:i:s');
    };
    // The suffix of Google's id for that day, in the series' form.
    $suffixOf = static fn(bool $allDay, string $key): string => (new DateTimeImmutable($key, $utc))->format($allDay ? 'Ymd' : 'Ymd\THis\Z');
    $iso = static fn(int $d, array $z, int $addHours = 0): string => sprintf('2027-01-%02dT%02d:%s:00%s', $d, (int) substr($z[1], 0, 2) + $addHours, substr($z[1], 3, 2), $z[2]);
    // The words of the refusal where a change that re-placed the series holds no record of the day.
    // $change: the Activity entry's summary (for a redo, the summary of the change the undo took back).
    $unplaced = static fn(string $what, bool $redo, string $change): string => "Can't " . ($redo ? 'redo' : 'undo') . " \"$change\". This repeating event $what after that, and Better-Cal can no longer tell which of its days the change was on. Make the change again on the day you meant.";
    $summary = static fn(int $entry): string => (string) $db->scalar('SELECT summary FROM mutations WHERE id = ?', [$entry]);
    $whatOf = ['form' => 'changed between timed and all-day', 'zone' => 'changed its time zone'];
    $stale = 'Newer changes exist for this item; undoing would overwrite them';

    // What the series holds here, by the local day each row's key names under the series as it stands:
    // day => [tags, its notes when they are not the series', whether it has reminders, and the day it shows on
    // when that is another day than its key's], and the skipped days.
    $held = static function (int $masterId) use ($db, $utc): array {
        $m = $db->one('SELECT * FROM events WHERE id = ?', [$masterId]);
        $day = static function (string $key) use ($m, $utc): string {
            $t = new DateTimeImmutable($key, $utc);
            return ((int) $m['all_day'] === 1 ? $t : $t->setTimezone(new DateTimeZone((string) $m['tzid'])))->format('j M');
        };
        $rows = [];
        foreach ($db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY recurrence_instance_utc', [$masterId]) as $r) {
            $rows[$day((string) $r['recurrence_instance_utc'])] = [
                array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [(int) $r['id']]), 'name'),
                $r['description'] === $m['description'] ? null : $r['description'], $r['reminders_json'] !== null,
                ...(($shown = (new DateTimeImmutable((string) $r['start_utc'], $utc))->setTimezone(new DateTimeZone((int) $r['all_day'] === 1 ? 'UTC' : (string) $r['tzid']))->format('j M')) === $day((string) $r['recurrence_instance_utc']) ? [] : ['shown ' . $shown]),
            ];
        }
        return ['rows' => $rows, 'skips' => array_map($day, json_decode((string) ($m['exdates_json'] ?? '[]'), true) ?: [])];
    };
    // Every table an undo may write, as it stands: for "nothing was written".
    $tables = static fn(int $u): array => [
        $db->all('SELECT * FROM events WHERE user_id = ? ORDER BY id', [$u]),
        $db->all('SELECT et.* FROM event_tags et JOIN events e ON e.id = et.event_id WHERE e.user_id = ? ORDER BY et.event_id, et.tag_id', [$u]),
        $db->all('SELECT id, undone, summary FROM mutations WHERE user_id = ? ORDER BY id', [$u]),
        (int) $db->scalar('SELECT COUNT(*) FROM mutation_touches t JOIN mutations m ON m.id = t.mutation_id WHERE m.user_id = ?', [$u]),
        $db->all('SELECT id, status FROM google_pending WHERE user_id = ? ORDER BY id', [$u]),
    ];

    /**
     * One case on a Google calendar. $c: `z` the zone; `allDay` the form the series starts in; `before` the steps
     * before the change; `change` ('form', 'zone' or 'none') and `by` ('app' or 'poll'); `between` the steps after it;
     * then the entry the last `undo` step recorded (a redo), or else the entry of the last step of `before` (an undo),
     * is undone. `expect`: `result` ('done', 'stale', 'unplaced' or an error code), `rows`, `skips`, and `sent`, the
     * writes Google was sent as [method, the day's id suffix].
     */
    $google = static function (array $c) use ($db, $keyOf, $suffixOf, $iso, $unplaced, $summary, $whatOf, $stale, $held, $tables, $first, $tokyoEvening): void {
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        $name = $c['name'];
        try {
            $e = $s->events;
            $undo = new Undo($db, null, $s->writer);
            // The series as it stands, for naming a day in a request: its form, zone and clock.
            $now = (object) ['allDay' => $c['allDay'], 'z' => $c['z']];
            $times = static fn(bool $allDay, array $z): array => $allDay
                ? ['start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true, 'tzid' => 'UTC']
                : ['start' => $iso(4, $z), 'end' => $iso(4, $z, 1), 'allDay' => false, 'tzid' => $z[0]];
            $at = static fn(int $day): string => $now->allDay ? sprintf('2027-01-%02d', $day) : $iso($day, $now->z);
            if (!empty($c['guests'])) {
                // A series the account is a guest of, made at Google.
                $s->fake->seed(['id' => 'smplinvite', 'iCalUID' => 'smplinvite@google.com', 'summary' => 'Sample practice', 'description' => 'Sample notes',
                    'start' => ['dateTime' => $iso(4, $c['z']), 'timeZone' => $c['z'][0]], 'end' => ['dateTime' => $iso(4, $c['z'], 1), 'timeZone' => $c['z'][0]],
                    'recurrence' => ['RRULE:FREQ=DAILY;COUNT=10'], 'organizer' => ['email' => 'host@example.test'],
                    'attendees' => [['email' => 'host@example.test', 'responseStatus' => 'accepted'], ['email' => 'owner@example.test', 'self' => true, 'responseStatus' => 'needsAction']]]);
                $s->poll();
                $id = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ?', [$s->calendarId, 'smplinvite']);
            } else {
                $id = (int) $e->create($u, ['calendarId' => $s->calendarId, 'title' => 'Sample practice', 'description' => 'Sample notes', 'rrule' => 'FREQ=DAILY;COUNT=10'] + $times($c['allDay'], $c['z']))['eventId'];
            }
            $gid = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
            $this1 = static function (int $day, array $in) use ($e, $u, $id, $at): void {
                $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $at($day)] + $in);
                $e->throwDeferred();
            };
            $all = static function (array $in) use ($e, $u, $id, $at): void {
                planned($e, $u, $id, ['scope' => 'all', 'instanceStart' => $at(4)] + $in);
                $e->throwDeferred();
            };
            $remote = static function (array $body) use ($s, $gid): void {
                $answer = ($s->fake->transport())('PATCH', 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(GoogleStand::CALENDAR) . '/events/' . rawurlencode($gid),
                    ['Authorization: Bearer sample-access', 'X-Harness: remote'], \BetterCal\GoogleProbe\FakeGoogle::asSet($body));
                if ((int) $answer['status'] !== 200) {
                    throw new RuntimeException('the stand-in refused the change made at Google: ' . $answer['body']);
                }
                $s->poll();
            };
            $bodyOf = static fn(bool $allDay, array $z): array => $allDay ? ['start' => ['date' => '2027-01-04'], 'end' => ['date' => '2027-01-05']]
                : ['start' => ['dateTime' => $iso(4, $z), 'timeZone' => $z[0]], 'end' => ['dateTime' => $iso(4, $z, 1), 'timeZone' => $z[0]]];
            $target = null;
            $undone = null; // for a redo, the change the undo took back
            $redo = false;
            $chosen = false; // once the steps before the change are done, the entry acted on is fixed
            $step = static function (string $do) use (&$target, &$undone, &$redo, &$chosen, $this1, $all, $remote, $bodyOf, $times, $now, $e, $u, $id, $gid, $at, $undo, $db, $s, $first, $iso, $keyOf, $suffixOf, $c, $tokyoEvening): void {
                [$do, $arg] = explode(':', $do, 2) + [1 => ''];
                switch ($do) {
                    case 'tag': $this1(6, ['tagNames' => ['sample-day']]); break;
                    case 'tag-back': $this1(6, ['useSeries' => ['tags']]); break;
                    case 'notes': $this1(6, ['description' => $first]); break;
                    case 'notes-back': $this1(6, ['useSeries' => ['description']]); break;
                    case 'reminder': $this1(6, ['reminders' => [['minutes' => 10]]]); break;
                    case 'newer-notes': $this1(6, ['description' => 'Newer notes']); break;
                    case 'other-day-notes': $this1((int) $arg, ['description' => 'Notes of another day']); break;
                    // 21:00 New York on the 6th, held in Berlin's zone: a time and a zone of the day's own.
                    case 'own-time': $this1(6, ['start' => '2027-01-07T03:00:00+01:00', 'end' => '2027-01-07T04:00:00+01:00', 'tzid' => 'Europe/Berlin']); break;
                    case 'own-all-day': $this1(6, ['allDay' => true, 'start' => '2027-01-06', 'end' => '2027-01-07']); break;
                    // Moved to the 8th at the series' clock: a date of the day's own.
                    case 'own-date': $this1(6, ['start' => $iso(8, $now->z), 'end' => $iso(8, $now->z, 1)]); break;
                    case 'delete':
                        $e->deleteEvent($u, $id, 'this', $at(6));
                        $e->throwDeferred();
                        break;
                    case 'answer':
                        $session = bin2hex(random_bytes(16));
                        $db->insert('sessions', ['token_hash' => hash('sha256', $session), 'user_id' => $u, 'csrf' => 'x', 'authenticated_at' => Time::nowDb(), 'expires_at' => '2030-01-01 00:00:00']);
                        $row = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$id]);
                        (new Rsvp($db, new EmailSender([]), [], $s->writer))->answer($u, $row, 'accepted', $session);
                        break;
                    case 'undo':
                        $undone = lastEntry($db, $u);
                        $undo->undoById($u, $undone);
                        $target = lastEntry($db, $u);
                        $redo = true;
                        break;
                    // The series' clock an hour later, for all events, made here.
                    case 'clock':
                        $z = [$now->z[0], sprintf('%02d:%s', (int) substr($now->z[1], 0, 2) + 1, substr($now->z[1], 3, 2)), $now->z[2]];
                        $all($times(false, $z));
                        $now->z = $z;
                        break;
                    case 'form':
                        $arg === 'app' ? $all($times(!$now->allDay, $c['z'])) : $remote($bodyOf(!$now->allDay, $c['z']));
                        $now->allDay = !$now->allDay;
                        break;
                    // The same clock time in Tokyo, the form kept.
                    case 'zone':
                        $z = [$tokyoEvening[0], $now->z[1], $tokyoEvening[2]];
                        $arg === 'app' ? $all($times(false, $z)) : $remote($bodyOf(false, $z));
                        $now->z = $z;
                        break;
                    default: throw new LogicException("no step $do");
                }
                if ($do !== 'undo' && !$redo && !$chosen) {
                    $target = lastEntry($db, $u);
                }
            };
            foreach ($c['before'] as $do) {
                $step($do);
            }
            $chosen = true;
            if (($c['change'] ?? 'none') !== 'none') {
                $step($c['change'] . ':' . $c['by']);
            }
            foreach ($c['between'] ?? [] as $do) {
                $step($do);
            }
            $was = $tables($u);
            $s->fake->requests = [];
            $error = null;
            try {
                $undo->undoById($u, (int) $target, !empty($c['force']));
            } catch (HttpError $thrown) {
                $error = $thrown;
            }
            $x = $c['expect'];
            $result = $error === null ? 'done' : match (true) {
                $error->errorCode === 'stale_undo' && $error->getMessage() === $stale => 'stale',
                // The change that re-placed the series with no record of the day says what it did: the case's own change.
                $error->errorCode === 'not_undoable' && ($error->extra['reason'] ?? null) === 'day_unplaced' && $error->getMessage() === $unplaced($whatOf[$c['change'] ?? ''] ?? '', $redo, $summary($redo ? (int) $undone : (int) $target)) => 'unplaced',
                default => $error->errorCode . ': ' . $error->getMessage(),
            };
            checkEq("$name: " . ['done' => 'it goes through', 'stale' => 'it is refused as stale, a newer change of the same thing standing',
                'unplaced' => 'it is refused, in the words that say what changed and that the day is no longer certain'][$x['result']] ?? "$name: " . $x['result'],
                $x['result'], $result);
            if ($x['result'] === 'unplaced' && $result === 'unplaced') {
                // Force passes staleness, never this.
                $forced = null;
                try {
                    $undo->undoById($u, (int) $target, true);
                } catch (HttpError $thrown) {
                    $forced = $thrown->getMessage();
                }
                checkEq("$name: forced, the same refusal", $error->getMessage(), $forced);
            }
            $writes = [];
            foreach ($s->fake->requests as $r) {
                if ($r['method'] !== 'GET') {
                    $writes[] = [$r['method'], substr(basename((string) $r['path']), strlen($gid) + 1)];
                }
            }
            if ($x['result'] === 'done') {
                checkEq("$name: Google is sent " . ($x['sent'] === [] ? 'nothing' : 'one write, to that day\'s id as the series stands'), $x['sent'], $writes);
            } else {
                checkEq("$name: refused before anything: no request to Google of any kind, and every table as it was", [[], true], [array_map(static fn(array $r): string => $r['method'], $s->fake->requests), $was === $tables($u)]);
            }
            $has = $held($id);
            if (!empty($c['anyPlace'])) {
                // A day at a date or time of its own shows where the redo's own values put it: only whose row it is is asserted.
                $has['rows'] = array_map(static fn(array $r): array => array_slice($r, 0, 3), $has['rows']);
            }
            checkEq("$name: " . $x['says'], ['rows' => $x['rows'], 'skips' => $x['skips'] ?? []], $has);
            checkEq("$name: no day row is left without a key", 0, (int) $db->scalar('SELECT COUNT(*) FROM events WHERE user_id = ? AND recurrence_parent_id IS NOT NULL AND recurrence_instance_utc IS NULL', [$u]));
        } finally {
            GoogleStand::forget($db, $u);
        }
    };

    $tag = [['sample-day'], null, false];
    $notes = [[], $first, false];
    $refused = static fn(array $rows = [], string $says = 'nothing changes'): array => ['result' => 'unplaced', 'rows' => $rows, 'says' => $says];
    foreach ($zones as $zoneName => $z) {
        foreach (['made all-day' => false, 'made timed' => true] as $direction => $startsAllDay) {
            $to = !$startsAllDay;
            // 6 January's id suffix in the new form: where every write of these cases must go.
            $sixth = $suffixOf($to, $keyOf($to, 6, $z));
            foreach (['here' => 'app', 'at Google' => 'poll'] as $where => $by) {
                $case = static fn(string $what, array $before, array $expect, array $between = []): array => [
                    'name' => "$zoneName $direction $where, $what", 'z' => $z, 'allDay' => $startsAllDay, 'before' => $before, 'change' => 'form', 'by' => $by, 'between' => $between, 'expect' => $expect];
                $cases = [
                    // The entry's own row is still there when it is undone: the day is that row, wherever the change carried it.
                    $case('a tag given, then undone', ['tag'], ['result' => 'done', 'sent' => [], 'rows' => [], 'says' => 'the tag is gone and the day is ordinary again']),
                    $case('a tag, then notes given and undone, then redone', ['tag', 'notes', 'undo'], ['result' => 'done', 'sent' => [['PATCH', $sixth]], 'rows' => ['6 Jan' => [['sample-day'], $first, false]], 'says' => 'the notes are back on 6 January beside its tag']),
                    // The same, where a change made here keeps the row and Google, changing the form itself, drops its record: the poll
                    // takes the row away, nothing recorded where the day went, and the undo is refused.
                    $case('notes given, then undone', ['notes'], $by === 'app'
                        ? ['result' => 'done', 'sent' => [['PATCH', $sixth]], 'rows' => [], 'says' => 'the notes are gone from 6 January']
                        : $refused([], 'Google dropped the notes with the old form, and nothing holds them')),
                    // A delete's own skip: carried by a change made here (its move recorded, or its key the same), dropped by Google with the old form.
                    $case('the day deleted, then that undone', ['delete'], $by === 'app'
                        ? ['result' => 'done', 'sent' => [['PATCH', $sixth]], 'rows' => [], 'says' => '6 January is a day again']
                        : $refused([], 'Google dropped the skip with the old form, and 6 January is a day already')),
                    $case('notes given, the day deleted, then the delete undone', ['notes', 'delete'], $by === 'app'
                        ? ['result' => 'done', 'sent' => [['PATCH', $sixth]], 'rows' => ['6 Jan' => $notes], 'says' => '6 January is back with its notes, on 6 January']
                        : $refused([], 'Google dropped the skip with the old form, and 6 January is a day already')),
                    // The day has neither a row nor a skip when the form changes: nothing recorded where it went. Refused in
                    // every zone, 00:00 UTC included, where the equal key happens to be the day.
                    $case('a tag given and undone, then redone', ['tag', 'undo'], $refused()),
                    $case('notes given and undone, then redone', ['notes', 'undo'], $refused()),
                    $case('notes given back to the series, then that undone', ['notes', 'notes-back'], $refused()),
                    $case('the day deleted and that undone, then the delete redone', ['delete', 'undo'], $refused([], 'no day is skipped')),
                    // The gate comes before staleness: newer notes on the same day stand, and the refusal is the gate's.
                    $case('notes given and undone, newer notes given to the same day, then the first redone', ['notes', 'undo'],
                        $refused(['6 Jan' => [[], 'Newer notes', false]], 'the newer notes stand on 6 January'), ['newer-notes']),
                ];
                // West of UTC an old key is the string of the neighbouring day's new key: that day's notes stay its own.
                if ($zoneName === '19:00 New York') {
                    $other = $to ? 7 : 5;
                    $cases[] = $case("notes given and undone, notes given to $other January whose new key is the old key of the 6th, then the first redone", ['notes', 'undo'],
                        $refused([$other . ' Jan' => [[], 'Notes of another day', false]], "the other day's notes are on $other January, and 6 January holds none"), ["other-day-notes:$other"]);
                }
                foreach ($cases as $c) {
                    scenario('undo form day, ' . $c['name'], fn() => $google($c));
                }
            }
        }
    }

    // The neighbour: the series kept timed and moved to another zone at the same clock time (19:00 New York to
    // 19:00 Tokyo). 6 January at 19:00 Tokyo is 10:00 UTC on the 6th.
    $ny = $zones['19:00 New York'];
    $sixthTokyo = $suffixOf(false, $keyOf(false, 6, $tokyoEvening));
    foreach (['here' => 'app', 'at Google' => 'poll'] as $where => $by) {
        $case = static fn(string $what, array $before, array $expect, array $between = []): array => [
            'name' => "19:00 New York moved to 19:00 Tokyo $where, $what", 'z' => $ny, 'allDay' => false, 'before' => $before, 'change' => 'zone', 'by' => $by, 'between' => $between, 'expect' => $expect];
        foreach ([
            $case('a tag given, then undone', ['tag'], ['result' => 'done', 'sent' => [], 'rows' => [], 'says' => 'the tag is gone and the day is ordinary again']),
            $case('a tag, then notes given and undone, then redone', ['tag', 'notes', 'undo'], ['result' => 'done', 'sent' => [['PATCH', $sixthTokyo]], 'rows' => ['6 Jan' => [['sample-day'], $first, false]], 'says' => 'the notes are back on 6 January beside its tag']),
            $case('a tag given and undone, then redone', ['tag', 'undo'], $refused()),
            $case('notes given and undone, then redone', ['notes', 'undo'], $refused()),
            $case('notes given back to the series, then that undone', ['notes', 'notes-back'], $refused()),
            $case('the day deleted and that undone, then the delete redone', ['delete', 'undo'], $refused([], 'no day is skipped')),
            $case('notes given and undone, newer notes given to the same day, then the first redone', ['notes', 'undo'],
                $refused(['6 Jan' => [[], 'Newer notes', false]], 'the newer notes stand on 6 January'), ['newer-notes']),
        ] as $c) {
            scenario('undo form day, ' . $c['name'], fn() => $google($c));
        }
    }

    // Shapes of a day and of the journal beyond the plain ones, 19:00 New York.
    $sixthDay = $suffixOf(true, $keyOf(true, 6, $ny));
    $shapes = [
        // A day with a time, a form or a date of its own, its change undone (so it has no row), then the series moved: refused like any other.
        ['name' => 'a day given a time and a zone of its own and that undone, the series moved to Tokyo here, then redone', 'z' => $ny, 'allDay' => false,
            'before' => ['own-time', 'undo'], 'change' => 'zone', 'by' => 'app', 'expect' => $refused()],
        ['name' => 'a day given a time and a zone of its own and that undone, the series moved to Tokyo at Google, then redone', 'z' => $ny, 'allDay' => false,
            'before' => ['own-time', 'undo'], 'change' => 'zone', 'by' => 'poll', 'expect' => $refused()],
        ['name' => 'a day made all-day in a timed series and that undone, the series moved to Tokyo here, then redone', 'z' => $ny, 'allDay' => false,
            'before' => ['own-all-day', 'undo'], 'change' => 'zone', 'by' => 'app', 'expect' => $refused()],
        ['name' => 'a day moved to the 8th and that undone, the series moved to Tokyo here, then redone', 'z' => $ny, 'allDay' => false,
            'before' => ['own-date', 'undo'], 'change' => 'zone', 'by' => 'app', 'expect' => $refused()],
        // A recorded move, then a change with no record: the tagged day's notes are undone, the series' clock moves an
        // hour here (the row is carried and its move recorded), the tag goes back to the series (the row goes), then
        // Google makes the series all-day. The first change is followed; the second holds nothing for the day.
        ['name' => 'notes undone on a tagged day, the clock moved here, the tag given back, made all-day at Google, then the notes redone', 'z' => $ny, 'allDay' => false,
            'before' => ['tag', 'notes', 'undo', 'clock', 'tag-back'], 'change' => 'form', 'by' => 'poll', 'expect' => $refused()],
        // Two changes of form in a row leave the series as it was, and still nothing recorded where the day went through them.
        ['name' => 'notes given and undone, made all-day here and timed again here, then redone', 'z' => $ny, 'allDay' => false,
            'before' => ['notes', 'undo'], 'change' => 'form', 'by' => 'app', 'between' => ['form:app'], 'expect' => $refused()],
        ['name' => 'notes given and undone, made all-day at Google and timed again at Google, then redone', 'z' => $ny, 'allDay' => false,
            'before' => ['notes', 'undo'], 'change' => 'form', 'by' => 'poll', 'between' => ['form:poll'], 'expect' => $refused()],
        ['name' => 'a tag given and undone, made all-day at Google, then made 19:00 Tokyo at Google, then redone', 'z' => $ny, 'allDay' => false,
            'before' => ['tag', 'undo'], 'change' => 'form', 'by' => 'poll', 'between' => ['form:poll', 'zone:poll'], 'expect' => $refused()],
        // An answer to an invitation for one day is about that day's row: the row is still there, carried with the series.
        ['name' => 'an invitation answered for one day with notes, made all-day here, then the answer undone', 'z' => $ny, 'allDay' => false, 'guests' => true,
            'before' => ['notes', 'answer'], 'change' => 'form', 'by' => 'app',
            'expect' => ['result' => 'done', 'sent' => [['PATCH', $sixthDay]], 'rows' => ['6 Jan' => $notes], 'says' => 'the notes stay on 6 January, where the answer was']],
    ];
    foreach ($shapes as $c) {
        scenario('undo form day, 19:00 New York, ' . $c['name'], fn() => $google($c));
    }

    // An ICS feed's calendar: an undo there acts on the row it names, and records nothing to redo. A tag given to
    // 6 January, the feed's series changing form, the tag undone: gone from 6 January, with a reminder kept where one was.
    $feedText = static function (bool $allDay, array $z): string {
        return implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sample//Feed//EN', 'BEGIN:VEVENT', 'UID:sample-undo-form@example.test', 'DTSTAMP:20270101T000000Z',
            ...($allDay ? ['DTSTART;VALUE=DATE:20270104', 'DTEND;VALUE=DATE:20270105']
                : ['DTSTART;TZID=' . $z[0] . ':20270104T' . str_replace(':', '', $z[1]) . '00', 'DURATION:PT1H']),
            'RRULE:FREQ=DAILY;COUNT=10', 'SUMMARY:Sample game', 'END:VEVENT', 'END:VCALENDAR', '']);
    };
    foreach (['made all-day' => false, 'made timed' => true] as $direction => $startsAllDay) {
        foreach (['a tag given, then undone' => false, 'a reminder, then a tag given, then the tag undone' => true] as $what => $reminder) {
            scenario("undo form day, an ICS feed at 19:00 New York $direction, $what", function () use ($db, $feedText, $keyOf, $held, $ny, $startsAllDay, $reminder, $direction, $what): void {
                $u = freshUser($db);
                $d = domains($db);
                $calendarId = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample league', 'kind' => 'subscribed', 'color' => '#336699']);
                $feeds = new Feeds($db);
                $sync = static fn(string $body) => $feeds->sync($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]), Ics::parse($body, $ny[0]));
                $sync($feedText($startsAllDay, $ny));
                $id = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NULL', [$calendarId]);
                $instance = str_replace(' ', 'T', $keyOf($startsAllDay, 6, $ny)) . '+00:00';
                if ($reminder) {
                    $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => $instance, 'reminders' => [['minutes' => 10]]]);
                }
                $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => $instance, 'tagNames' => ['sample-day']]);
                $entry = lastEntry($db, $u);
                $sync($feedText(!$startsAllDay, $ny));
                $last = lastEntry($db, $u);
                $d->undo->undoById($u, $entry);
                checkEq("an ICS feed $direction, $what: the tag is gone from 6 January" . ($reminder ? ', its reminder still there' : ' and the day is ordinary again'),
                    ['rows' => $reminder ? ['6 Jan' => [[], null, true]] : [], 'skips' => []], $held($id));
                checkEq("an ICS feed $direction, $what: an undo there records no entry, so there is nothing to redo", $last, lastEntry($db, $u));
            });
        }
        // No undo there brings a row back: a tag taken off again leaves the day's row (the day owns its tags, and has
        // none), the feed's change of form carries that row to its day, and the undo of the taking off is by the row too.
        scenario("undo form day, an ICS feed at 19:00 New York $direction, a tag given and taken off, then the taking off undone", function () use ($db, $feedText, $keyOf, $held, $ny, $startsAllDay, $direction): void {
            $u = freshUser($db);
            $d = domains($db);
            $calendarId = (int) $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample league', 'kind' => 'subscribed', 'color' => '#336699']);
            $feeds = new Feeds($db);
            $sync = static fn(string $body) => $feeds->sync($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]), Ics::parse($body, $ny[0]));
            $sync($feedText($startsAllDay, $ny));
            $id = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND recurrence_instance_utc IS NULL', [$calendarId]);
            $instance = str_replace(' ', 'T', $keyOf($startsAllDay, 6, $ny)) . '+00:00';
            $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => $instance, 'tagNames' => ['sample-day']]);
            $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => $instance, 'tagNames' => []]);
            $entry = lastEntry($db, $u);
            checkEq("an ICS feed $direction, taken off: the day keeps its row, with no tag, before the feed changes", ['rows' => ['6 Jan' => [[], null, false]], 'skips' => []], $held($id));
            $sync($feedText(!$startsAllDay, $ny));
            $d->undo->undoById($u, $entry);
            checkEq("an ICS feed $direction, taken off: undone, the tag is back on 6 January and on no other day", ['rows' => ['6 Jan' => [['sample-day'], null, false]], 'skips' => []], $held($id));
            $row = $db->one('SELECT all_day, recurrence_instance_utc FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$id]);
            checkEq("an ICS feed $direction, taken off: and its row is in the series' form now, at 6 January's key",
                [(int) !$startsAllDay, $keyOf(!$startsAllDay, 6, $ny)], [(int) ($row['all_day'] ?? -1), $row['recurrence_instance_utc'] ?? null]);
        });
    }

    // A calendar of the account's own: a snapshot is of the series as it was, so the row a change removed comes back
    // nowhere once the series' form changed, forced or not, and the answer is never "stale", which force would pass.
    foreach (['made all-day' => false, 'made timed' => true] as $direction => $startsAllDay) {
        scenario("undo form day, an own calendar at 19:00 New York $direction, a tag given back to the series then that undone: refused, forced or not", function () use ($db, $iso, $held, $ny, $startsAllDay, $direction): void {
            $u = freshUser($db);
            $d = domains($db);
            $e = $d->events;
            $calendarId = (int) Settings::defaultCalendarId($db, $u);
            $timed = ['start' => $iso(4, $ny), 'end' => $iso(4, $ny, 1), 'allDay' => false, 'tzid' => $ny[0]];
            $days = ['start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true, 'tzid' => 'UTC'];
            $at = static fn(int $day): string => $startsAllDay ? sprintf('2027-01-%02d', $day) : $iso($day, $ny);
            $id = (int) $e->create($u, ['calendarId' => $calendarId, 'title' => 'Sample practice', 'rrule' => 'FREQ=DAILY;COUNT=10'] + ($startsAllDay ? $days : $timed))['eventId'];
            $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $at(6), 'tagNames' => ['sample-day']]);
            $e->patch($u, $id, ['scope' => 'this', 'instanceStart' => $at(6), 'useSeries' => ['tags']]);
            $entry = lastEntry($db, $u);
            planned($e, $u, $id, ['scope' => 'all', 'instanceStart' => $at(4)] + ($startsAllDay ? $timed : $days));
            $words = "Can't undo \"" . (string) $db->scalar('SELECT summary FROM mutations WHERE id = ?', [$entry]) . "\". This repeating event changed between timed and all-day after that, so the saved copy of that day no longer fits it. Make the change again on the day.";
            $refusals = [];
            foreach ([false, true] as $force) {
                try {
                    $d->undo->undoById($u, $entry, $force);
                    $refusals[] = null;
                } catch (HttpError $error) {
                    $refusals[] = [$error->errorCode, $error->getMessage()];
                }
            }
            checkEq("an own calendar $direction: refused, forced or not, in words that say what changed", [['not_undoable', $words], ['not_undoable', $words]], $refusals);
            checkEq("an own calendar $direction: no row is brought back on any day", ['rows' => [], 'skips' => []], $held($id));
        });
    }
};
