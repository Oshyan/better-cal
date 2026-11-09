<?php

declare(strict_types=1);

// Undo of a whole-series change on a Google calendar (#172, stage three):
// a plan for all events, a split, a new repeating event and a series
// deleted whole each record an undoable entry; undoing it sends the series
// as it was back through GoogleSeries, puts the rows' own parts back here
// (tags, people, own lists) and stores what Google read back on top; the
// undo undoes back. A series changed at Google since refuses the undo with
// nothing written; a failure after a step reached Google leaves the change
// half done in Review and the entry to undo. Against the probe's stand-in
// Google (tools/google-probe), with invented data.

use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Undo;
use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

require_once dirname(__DIR__, 3) . '/tools/google-probe/lib/FakeGoogle.php';

return static function (Db $db): void {
    $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
    $tz = 'America/Los_Angeles';
    $la = new DateTimeZone($tz);
    $at = static fn(string $local): DateTimeImmutable => new DateTimeImmutable($local, $la);
    $iso = static fn(string $local): string => $at($local)->format('Y-m-d\TH:i:sP');
    $when = static fn(string $local): array => ['dateTime' => $iso($local), 'timeZone' => $tz];

    $expand = static function (array $master): array {
        $allDay = isset($master['start']['date']);
        $zone = $allDay ? 'UTC' : (string) ($master['start']['timeZone'] ?? 'UTC');
        $start = $allDay ? new DateTimeImmutable($master['start']['date'] . ' 00:00:00', new DateTimeZone('UTC')) : new DateTimeImmutable((string) $master['start']['dateTime']);
        $end = $allDay ? new DateTimeImmutable($master['end']['date'] . ' 00:00:00', new DateTimeZone('UTC')) : new DateTimeImmutable((string) $master['end']['dateTime']);
        $rrule = null;
        foreach ((array) ($master['recurrence'] ?? []) as $line) {
            if (str_starts_with((string) $line, 'RRULE:')) {
                $rrule = substr((string) $line, 6);
            }
        }
        if ($rrule === null) {
            return [];
        }
        $row = ['start_utc' => Time::toDb($start), 'end_utc' => Time::toDb($end), 'all_day' => $allDay ? 1 : 0, 'tzid' => $zone, 'rrule' => $rrule];
        return array_map(static fn(array $o): DateTimeImmutable => $o['start']->setTimezone(new DateTimeZone($zone)), Recurrence::occurrences($row, $start, $start->modify('+30 years'), null, 730));
    };

    // Invented: weekly Mondays 10:00 Los Angeles from 1 March 2027, ten of
    // them, occurrence 3 moved a day later, 4 at its own time, 5 with its
    // own title and 6 cancelled.
    $seedSeries = static function (FakeGoogle $fake) use ($when): void {
        $fake->seed(['id' => 'smpl2weekly', 'iCalUID' => 'smpl2weekly@google.com', 'summary' => 'Sample weekly', 'description' => 'Sample notes', 'location' => 'Sample room 1',
            'start' => $when('2027-03-01 10:00'), 'end' => $when('2027-03-01 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=10']]);
        $record = static fn(string $suffix, string $orig, array $more): array => $more + ['id' => 'smpl2weekly_' . $suffix, 'iCalUID' => 'smpl2weekly@google.com', 'recurringEventId' => 'smpl2weekly',
            'summary' => 'Sample weekly', 'description' => 'Sample notes', 'location' => 'Sample room 1', 'originalStartTime' => $when($orig)];
        $fake->seed($record('20270315T170000Z', '2027-03-15 10:00', ['start' => $when('2027-03-16 10:00'), 'end' => $when('2027-03-16 11:00')]));
        $fake->seed($record('20270322T170000Z', '2027-03-22 10:00', ['start' => $when('2027-03-22 15:00'), 'end' => $when('2027-03-22 16:00')]));
        $fake->seed($record('20270329T170000Z', '2027-03-29 10:00', ['start' => $when('2027-03-29 10:00'), 'end' => $when('2027-03-29 11:00'), 'summary' => 'Sample weekly, special']));
        $fake->seed($record('20270405T170000Z', '2027-04-05 10:00', ['start' => $when('2027-04-05 10:00'), 'end' => $when('2027-04-05 11:00'), 'status' => 'cancelled']));
    };

    $setup = static function () use ($db, $cfg, $expand, $seedSeries): object {
        $u = freshUser($db);
        $d = domains($db);
        $fake = new FakeGoogle('crew@group.calendar.google.com');
        $fake->token = null;
        $fake->zone = 'America/Los_Angeles';
        $fake->expand = $expand;
        $seedSeries($fake);
        $t = new class {
            /** @var list<array{0:string,1:string,2:?array,3:?string}> method, id, body, If-Match */
            public array $calls = [];
            /** The write (1-based) that answers 412 instead of reaching the stand-in. */
            public ?int $staleAt = null;
            public int $writes = 0;
            /** A change made at Google right after the next write the stand-in accepts (another client between a write and its read-back): [Google id, body]. */
            public ?array $race = null;
        };
        $inner = $fake->writerTransport();
        $transport = static function (string $method, string $url, ?array $payload, array $headers = []) use ($t, $inner): array {
            $ifMatch = null;
            foreach ($headers as $h) {
                if (stripos($h, 'If-Match:') === 0) {
                    $ifMatch = trim(substr($h, 9));
                }
            }
            $t->calls[] = [$method, rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))), $payload, $ifMatch];
            if ($method !== 'GET') {
                $t->writes++;
                if ($t->staleAt === $t->writes) {
                    return ['status' => 412, 'body' => json_encode(['error' => ['code' => 412, 'message' => 'Precondition Failed']])];
                }
            }
            $answer = $inner($method, $url, $payload, array_merge(['Authorization: Bearer sample-access'], $headers));
            if ($method !== 'GET' && $t->race !== null && (int) ($answer['status'] ?? 500) < 400) {
                [$raceId, $raceBody] = $t->race;
                $t->race = null;
                $inner('PATCH', dirname($url) . '/' . rawurlencode($raceId), $raceBody, ['Authorization: Bearer sample-access']);
            }
            return $answer;
        };
        $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
            => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
        $feeds = new Feeds($db);
        $writer = new GoogleWriter($db, $auth, $feeds, $transport);
        $syncTransport = $fake->syncTransport();
        $sync = new GoogleSync($db, $auth, $feeds, static fn(string $url, array $headers): array => $syncTransport($url, array_merge(['Authorization: Bearer sample-access'], $headers)));
        $events = new Events($db, new Recurrence(), $d->undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $d->undo, new BetterCal\Infra\JobQueue($db)), new BetterCal\Domain\Trips($db, $d->undo), null, $writer);
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $calendarId = $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample crew', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'crew@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]));
        $t->calls = [];
        $t->writes = 0;
        return (object) ['u' => $u, 'fake' => $fake, 't' => $t, 'sync' => $sync, 'events' => $events, 'calendarId' => $calendarId,
            'undo' => new Undo($db, null, $writer), 'labels' => new BetterCal\Domain\Labels($db)];
    };
    $masterId = static fn(object $s, string $googleId = 'smpl2weekly'): int => (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, $googleId]);
    $last = static fn(object $s): array => $db->one('SELECT * FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$s->u]);
    $google = static fn(array $entry): ?array => json_decode((string) $entry['details_json'], true)['google'] ?? null;
    $undone = static fn(int $id): int => (int) $db->scalar('SELECT undone FROM mutations WHERE id = ?', [$id]);
    $pending = static fn(object $s): array => $db->all('SELECT status, steps_json FROM google_pending WHERE user_id = ?', [$s->u]);

    // What the stand-in holds for a series, and the rows here in the same shape.
    $held = static function (object $s, string $googleId): array {
        $m = $s->fake->events[$googleId];
        $out = ['rule' => $m['recurrence'][0] ?? null, 'start' => $m['start']['dateTime'] ?? $m['start']['date'], 'title' => $m['summary'], 'status' => $m['status'] ?? 'confirmed', 'records' => [], 'cancelled' => []];
        foreach ($s->fake->records($googleId) as $k => $r) {
            if (($r['status'] ?? '') === 'cancelled') {
                $out['cancelled'][] = $k;
            } else {
                $out['records'][$k] = [$r['summary'], $r['start']['dateTime'] ?? $r['start']['date']];
            }
        }
        sort($out['cancelled']);
        ksort($out['records']);
        return $out;
    };
    $stored = static function (object $s, string $googleId) use ($db, $tz): ?array {
        $m = $db->one('SELECT * FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, $googleId]);
        if ($m === null) {
            return null;
        }
        $local = static fn(string $dbUtc): string => Time::fromDb($dbUtc)->setTimezone(new DateTimeZone($tz))->format('Y-m-d\TH:i:sP');
        $suffix = static fn(string $dbUtc): string => Time::fromDb($dbUtc)->format('Ymd\THis\Z');
        $out = ['rule' => 'RRULE:' . $m['rrule'], 'start' => $local($m['start_utc']), 'title' => $m['title'], 'status' => $m['status'], 'records' => [], 'cancelled' => []];
        foreach ($db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [(int) $m['id']]) as $r) {
            $out['records'][$suffix($r['recurrence_instance_utc'])] = [$r['title'], $local($r['start_utc'])];
        }
        foreach (Recurrence::decodeExdates($m['exdates_json']) as $ex) {
            $out['cancelled'][] = $suffix($ex);
        }
        sort($out['cancelled']);
        ksort($out['records']);
        return $out;
    };
    // The rows' own parts: each row's tags, people and own list, by its occurrence key ('' for a master).
    $own = static function (object $s, string $uid) use ($db): array {
        $out = [];
        foreach ($db->all('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND deleted_at IS NULL ORDER BY recurrence_instance_utc', [$s->calendarId, $uid]) as $r) {
            $out[(string) ($r['recurrence_instance_utc'] ?? '')] = [
                array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [(int) $r['id']]), 'name'),
                array_column($db->all('SELECT p.name FROM event_people ep JOIN people p ON p.id = ep.person_id WHERE ep.event_id = ? ORDER BY p.name', [(int) $r['id']]), 'name'),
                $r['override_fields'],
            ];
        }
        return $out;
    };
    $sameAfterPoll = static function (object $s) use ($db): bool {
        $rows = static fn(): array => array_map(static function (array $r): array {
            unset($r['updated_at']);
            return $r;
        }, $db->all('SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY id', [$s->calendarId]));
        $before = $rows();
        $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
        return $before === $rows();
    };
    // A tag on the series and a person on its day with its own title: what Google never holds.
    $ownParts = static function (object $s) use ($db, $masterId): void {
        $s->labels->setEventTags($s->u, $masterId($s), ['sample-tag']);
        $person = $db->insert('people', ['user_id' => $s->u, 'name' => 'Alex Example']);
        $day = (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ?', [$s->calendarId, 'smpl2weekly_20270329T170000Z']);
        $db->insert('event_people', ['event_id' => $day, 'person_id' => $person]);
    };

    scenario('google series undo: a clock change for all events is undone, the series, its exceptions and skip back at Google and here, own parts kept; the undo undoes back', function () use ($setup, $masterId, $last, $google, $undone, $held, $stored, $own, $ownParts, $sameAfterPoll, $pending, $iso, $db): void {
        $s = $setup();
        $ownParts($s);
        $id = $masterId($s);
        $wasHeld = $held($s, 'smpl2weekly');
        $wasOwn = $own($s, 'smpl2weekly@google.com');
        planned($s->events, $s->u, $id, ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'start' => $iso('2027-03-01 11:00'), 'end' => $iso('2027-03-01 12:00')]);
        $entry = $last($s);
        $g = $google($entry);
        $changedHeld = $held($s, 'smpl2weekly');
        checkEq('the change is at Google; its entry is a series entry with the series as it was and the rows\' snapshots', [
            $iso('2027-03-01 11:00'), 'series', 'smpl2weekly', 1, 3, ['2027-04-05 17:00:00'], true, true,
        ], [$changedHeld['start'], $g['kind'] ?? null, $g['googleId'] ?? null, count($g['before'] ?? []), count($g['before'][0]['exceptions'] ?? []), $g['before'][0]['skips'] ?? null,
            $entry['before_json'] !== '{"tables":[]}', str_contains((string) $entry['summary'], "on Google calendar 'Sample crew'")]);
        $listed = (new BetterCal\Domain\Activity($db))->list($s->u, [])['entries'][0];
        check('Activity offers its undo', $listed['undoable'] === true);

        $stored0 = (string) $db->scalar('SELECT google_etag FROM events WHERE id = ?', [$masterId($s)]);
        $s->t->calls = [];
        $s->undo->undoById($s->u, (int) $entry['id']);
        $firstWrite = array_values(array_filter($s->t->calls, static fn(array $c): bool => $c[0] !== 'GET'))[0] ?? null;
        checkEq('the first write is the master\'s PATCH, conditional on the etag stored now', ['PATCH', 'smpl2weekly', $stored0], [$firstWrite[0] ?? null, $firstWrite[1] ?? null, $firstWrite[3] ?? null]);
        checkEq('the series is as it was at Google and here; the entry is undone; no pending row', [$wasHeld, $wasHeld, 1, []],
            [$held($s, 'smpl2weekly'), $stored($s, 'smpl2weekly'), $undone((int) $entry['id']), $pending($s)]);
        checkEq('the tag, the person and every own list are as they were', $wasOwn, $own($s, 'smpl2weekly@google.com'));
        check('a poll after changes nothing', $sameAfterPoll($s));

        $back = $last($s);
        checkEq('the undo is an undoable series entry of its own', ["Undid: " . $entry['summary'], 'series'], [$back['summary'], $google($back)['kind'] ?? null]);
        $s->undo->undoById($s->u, (int) $back['id']);
        checkEq('undoing the undo puts the change back at Google and here', [$changedHeld, $changedHeld, (string) $entry['summary']],
            [$held($s, 'smpl2weekly'), $stored($s, 'smpl2weekly'), $last($s)['summary']]);
        checkEq('the tag and the person are still there', [['sample-tag'], ['Alex Example']],
            [$own($s, 'smpl2weekly@google.com')[''][0] ?? null, $own($s, 'smpl2weekly@google.com')['2027-03-29 18:00:00'][1] ?? null]);
    });

    scenario('google series undo: a split is undone, the new series cancelled at Google and gone here, the old series whole again', function () use ($setup, $masterId, $last, $google, $held, $stored, $own, $ownParts, $sameAfterPoll, $pending, $iso, $db): void {
        $s = $setup();
        $ownParts($s);
        $wasHeld = $held($s, 'smpl2weekly');
        $wasOwn = $own($s, 'smpl2weekly@google.com');
        planned($s->events, $s->u, $masterId($s), ['scope' => 'following', 'instanceStart' => $iso('2027-03-22 10:00'), 'title' => 'Sample weekly, spring']);
        $newIds = array_keys(array_filter($s->fake->events, static fn(array $e): bool => !empty($e['recurrence']) && $e['id'] !== 'smpl2weekly'));
        $newId = (string) ($newIds[0] ?? '');
        $entry = $last($s);
        $g = $google($entry);
        checkEq('the split made a new series; its entry names the old series as it was and the new one\'s removal', [1, 'series', [false, true], $newId],
            [count($newIds), $g['kind'] ?? null, array_map(static fn(array $t): bool => (bool) $t['remove'], $g['before'] ?? []), $g['before'][1]['googleId'] ?? null]);
        $s->undo->undoById($s->u, (int) $entry['id']);
        checkEq('the new series is cancelled at Google and gone here; the old one holds its bound and exceptions again, at Google and here', [
            'cancelled', null, $wasHeld, $wasHeld, [],
        ], [$s->fake->events[$newId]['status'] ?? null, $stored($s, $newId), $held($s, 'smpl2weekly'), $stored($s, 'smpl2weekly'), $pending($s)]);
        checkEq('the own parts are as they were', $wasOwn, $own($s, 'smpl2weekly@google.com'));
        check('a poll after changes nothing', $sameAfterPoll($s));
        // And back: the new series is made again under its id.
        $s->undo->undoById($s->u, (int) $last($s)['id']);
        checkEq('undoing the undo brings the new series back under its id, the old one cut again', ['confirmed', 'Sample weekly, spring', 'RRULE:FREQ=WEEKLY;UNTIL=20270315T170000Z'],
            [$s->fake->events[$newId]['status'] ?? null, $stored($s, $newId)['title'] ?? null, $stored($s, 'smpl2weekly')['rule'] ?? null]);
    });

    scenario('google series undo: an answer from a day on (a split, then the answer) is one entry, undone as one', function () use ($setup, $masterId, $last, $google, $held, $stored, $own, $ownParts, $iso, $db): void {
        $s = $setup();
        $ownParts($s);
        $wasHeld = $held($s, 'smpl2weekly');
        $wasOwn = $own($s, 'smpl2weekly@google.com');
        $id = $masterId($s);
        $plan = $s->events->previewAttendance($s->u, $id, 'going', 'following', $iso('2027-03-22 10:00'));
        $s->events->setAttendance($s->u, $id, 'going', 'following', $iso('2027-03-22 10:00'), ['hash' => $plan['hash'], 'choices' => []]);
        $entry = $last($s);
        $newIds = array_keys(array_filter($s->fake->events, static fn(array $e): bool => !empty($e['recurrence']) && $e['id'] !== 'smpl2weekly'));
        $newId = (string) ($newIds[0] ?? '');
        checkEq('the split is at Google and the new series is marked going; one series entry for both', [1, 'going', 'series'],
            [count($newIds), $db->scalar('SELECT attendance FROM events WHERE calendar_id = ? AND google_event_id = ?', [$s->calendarId, $newId]), $google($entry)['kind'] ?? null]);
        $s->undo->undoById($s->u, (int) $entry['id']);
        checkEq('undone: the new series cancelled at Google and gone here, the old one whole, its own parts and answers as they were', ['cancelled', null, $wasHeld, $wasHeld, $wasOwn],
            [$s->fake->events[$newId]['status'] ?? null, $stored($s, $newId), $held($s, 'smpl2weekly'), $stored($s, 'smpl2weekly'), $own($s, 'smpl2weekly@google.com')]);
    });

    scenario('google series undo: a new repeating event is undone by its removal, and comes back on the undo\'s undo', function () use ($setup, $last, $google, $stored, $db): void {
        $s = $setup();
        $s->events->create($s->u, ['calendarId' => $s->calendarId, 'title' => 'Sample practice', 'start' => '2027-05-04T18:00:00+00:00', 'end' => '2027-05-04T19:00:00+00:00',
            'allDay' => false, 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=WEEKLY;COUNT=4', 'tagNames' => ['sample-tag']]);
        $row = $db->one("SELECT * FROM events WHERE calendar_id = ? AND title = 'Sample practice' AND recurrence_parent_id IS NULL", [$s->calendarId]);
        $googleId = (string) ($row['google_event_id'] ?? '');
        $entry = $last($s);
        $g = $google($entry);
        checkEq('the series is at Google; its entry\'s before is its removal', ['confirmed', 'series', true, $googleId], [$s->fake->events[$googleId]['status'] ?? null, $g['kind'] ?? null, $g['before'][0]['remove'] ?? null, $g['googleId'] ?? null]);
        $s->undo->undoById($s->u, (int) $entry['id']);
        checkEq('undone: cancelled at Google, gone here', ['cancelled', null], [$s->fake->events[$googleId]['status'] ?? null, $stored($s, $googleId)]);
        $s->undo->undoById($s->u, (int) $last($s)['id']);
        $tags = array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id JOIN events e ON e.id = et.event_id WHERE e.google_event_id = ? AND e.calendar_id = ?', [$googleId, $s->calendarId]), 'name');
        checkEq('undoing the undo makes it again under its id, with its tag', ['confirmed', 'Sample practice', 'RRULE:FREQ=WEEKLY;COUNT=4', ['sample-tag']],
            [$s->fake->events[$googleId]['status'] ?? null, $stored($s, $googleId)['title'] ?? null, $stored($s, $googleId)['rule'] ?? null, $tags]);
    });

    scenario('google series undo: a series deleted whole comes back with its exceptions, skip and tag', function () use ($setup, $masterId, $last, $google, $held, $stored, $own, $ownParts, $sameAfterPoll): void {
        $s = $setup();
        $ownParts($s);
        $wasHeld = $held($s, 'smpl2weekly');
        $wasOwn = $own($s, 'smpl2weekly@google.com');
        $s->events->deleteEvent($s->u, $masterId($s), 'all', null);
        $entry = $last($s);
        checkEq('deleted at Google and here; the entry is a series entry', ['cancelled', null, 'series'], [$s->fake->events['smpl2weekly']['status'], $stored($s, 'smpl2weekly'), $google($entry)['kind'] ?? null]);
        $s->undo->undoById($s->u, (int) $entry['id']);
        checkEq('the series is back at Google and here as it was, with its own parts', [$wasHeld, $wasHeld, $wasOwn],
            [$held($s, 'smpl2weekly'), $stored($s, 'smpl2weekly'), $own($s, 'smpl2weekly@google.com')]);
        check('a poll after changes nothing', $sameAfterPoll($s));
        $s->undo->undoById($s->u, (int) $last($s)['id']);
        checkEq('undoing the undo deletes it again', ['cancelled', null], [$s->fake->events['smpl2weekly']['status'], $stored($s, 'smpl2weekly')]);
    });

    scenario('google series undo: two changes undone newest first, the second by its entry, the undo\'s own entry making nothing stale', function () use ($setup, $masterId, $last, $google, $undone, $held, $stored): void {
        $s = $setup();
        $wasHeld = $held($s, 'smpl2weekly');
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'title' => 'Sample weekly, first']);
        $first = $last($s);
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'title' => 'Sample weekly, second']);
        $second = $last($s);
        $s->undo->undoById($s->u, (int) $second['id']);
        $undid = $last($s);
        checkEq('the undo of the second is an entry of its own naming the entry it undid', [(int) $second['id'], 0], [$google($undid)['undoes'] ?? null, $undone((int) $undid['id'])]);
        $s->undo->undoById($s->u, (int) $first['id']);
        checkEq('the first then undoes by its entry, not stale: the series as it was before both, at Google and here', [$wasHeld, $wasHeld, 1],
            [$held($s, 'smpl2weekly'), $stored($s, 'smpl2weekly'), $undone((int) $first['id'])]);
    });

    scenario('google series undo: an older change undone by its entry keeps a newer one in another field', function () use ($setup, $masterId, $last, $held, $stored, $iso): void {
        $s = $setup();
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'rrule' => 'FREQ=WEEKLY;COUNT=8']);
        $end = $last($s);
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'title' => 'Sample weekly, renamed']);
        $s->undo->undoById($s->u, (int) $end['id']);
        $now = $held($s, 'smpl2weekly');
        checkEq('the end is undone and the rename made after it stays, at Google and here', ['RRULE:FREQ=WEEKLY;COUNT=10', 'Sample weekly, renamed', $now],
            [$now['rule'], $now['title'], $stored($s, 'smpl2weekly')]);
    });

    scenario('google series undo: a series changed at Google since refuses the undo with the conflict, nothing written', function () use ($setup, $masterId, $last, $undone, $pending, $db): void {
        $s = $setup();
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'title' => 'Sample weekly, renamed']);
        $entry = $last($s);
        // Renamed at Google meanwhile, not yet polled here: its etag is new.
        $s->fake->events['smpl2weekly']['summary'] = 'Sample weekly, theirs';
        $s->fake->events['smpl2weekly']['etag'] = '"elsewhere"';
        $state = static fn(): array => [$db->all('SELECT * FROM events WHERE calendar_id = ? ORDER BY id', [$s->calendarId]),
            $db->all('SELECT id, undone FROM mutations WHERE user_id = ? ORDER BY id', [$s->u])];
        $before = $state();
        $caught = null;
        try {
            $s->undo->undoById($s->u, (int) $entry['id']);
        } catch (HttpError $e) {
            $caught = $e->status . ' ' . $e->errorCode . ': ' . $e->getMessage();
        }
        checkEq('refused as the conflict; rows, Activity and the stand-in as they were; no pending row', [
            '409 google_conflict: Newer changes exist for this item; undoing would overwrite them. This event changed at Google since you last saw it.',
            $before, 'Sample weekly, theirs', [], 0,
        ], [$caught, $state(), $s->fake->events['smpl2weekly']['summary'], $pending($s), $undone((int) $entry['id'])]);
    });

    scenario('google series undo: a failure after a step reached Google leaves the undo half done in Review, the entry not undone; Finish completes it', function () use ($setup, $masterId, $last, $undone, $pending, $held, $stored, $iso, $db): void {
        $s = $setup();
        $wasHeld = $held($s, 'smpl2weekly');
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'start' => $iso('2027-03-01 11:00'), 'end' => $iso('2027-03-01 12:00')]);
        $entry = $last($s);
        $s->t->writes = 0;
        $s->t->staleAt = 2; // the master's PATCH goes through; the first exception's answers 412
        $caught = null;
        try {
            $s->undo->undoById($s->u, (int) $entry['id']);
        } catch (HttpError $e) {
            $caught = $e;
        }
        $p = $pending($s);
        checkEq('the undo fails naming the half-done state; the pending row is half done; the entry is not undone; the rows show what Google has', [
            '409 google_conflict', true, 1, 'half', 0, true,
        ], [$caught === null ? null : $caught->status . ' ' . $caught->errorCode, $caught !== null && str_contains($caught->getMessage(), 'half done at Google'),
            count($p), $p[0]['status'] ?? null, $undone((int) $entry['id']), $held($s, 'smpl2weekly') === $stored($s, 'smpl2weekly')]);
        // Review's Finish carries the rest to Google, as for a change half done.
        $s->t->staleAt = null;
        $listed = (new BetterCal\Domain\ReviewQueue($db, $s->events))->googleHalfDone($s->u);
        $s->events->googleFinish($s->u, (int) $listed[0]['id']);
        $s->events->throwDeferred();
        checkEq('Finish completes the undo at Google and here; the pending row is gone', [$wasHeld, $wasHeld, []], [$held($s, 'smpl2weekly'), $stored($s, 'smpl2weekly'), $pending($s)]);
    });

    // An undo of a series change is an ordinary series write: what it asks is the inverse of what the change asked,
    // over the series as it stands, and what Google changed meanwhile, during the change or during the undo, stays.
    $day = 'smpl2weekly_20270308T180000Z'; // 8 March 2027, an ordinary occurrence
    $row = static fn(object $s, string $googleId): ?array => $db->one('SELECT * FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, $googleId]);

    scenario('google series undo: a day renamed at Google during a rename and again during its undo keeps the newer title, at Google and here', function () use ($setup, $masterId, $last, $pending, $sameAfterPoll, $iso, $row, $day): void {
        $s = $setup();
        $s->t->race = [$day, ['summary' => 'Sample remote A']];
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'title' => 'Sample renamed']);
        $entry = $last($s);
        checkEq('the rename keeps the day renamed at Google during it', ['Sample remote A', 'Sample remote A'], [$s->fake->events[$day]['summary'] ?? null, $row($s, $day)['title'] ?? null]);
        $s->t->race = [$day, ['summary' => 'Sample remote B']];
        $s->undo->undoById($s->u, (int) $entry['id']);
        $here = $row($s, $day);
        checkEq('the undo puts the series title back and the day keeps the newer title from Google, there and here, owned; nothing pending', [
            'Sample weekly', 'Sample weekly', 'Sample remote B', 'Sample remote B', true, [],
        ], [$s->fake->events['smpl2weekly']['summary'], $row($s, 'smpl2weekly')['title'] ?? null, $s->fake->events[$day]['summary'] ?? null, $here['title'] ?? null,
            in_array('title', json_decode((string) ($here['override_fields'] ?? '[]'), true) ?: [], true), $pending($s)]);
        check('a poll after changes nothing', $sameAfterPoll($s));
    });

    scenario('google series undo: a series renamed at Google during a place change keeps that title when the place change is undone', function () use ($setup, $masterId, $last, $pending, $sameAfterPoll, $iso, $row): void {
        $s = $setup();
        $s->t->race = ['smpl2weekly', ['summary' => 'Sample remote series']];
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'location' => 'Sample room 2']);
        $entry = $last($s);
        checkEq('the place change keeps the title from Google', ['Sample remote series', 'Sample room 2', 'Sample remote series'],
            [$s->fake->events['smpl2weekly']['summary'], $s->fake->events['smpl2weekly']['location'] ?? null, $row($s, 'smpl2weekly')['title'] ?? null]);
        $s->t->calls = [];
        $s->undo->undoById($s->u, (int) $entry['id']);
        $sent = array_values(array_filter($s->t->calls, static fn(array $c): bool => $c[0] === 'PATCH' && $c[1] === 'smpl2weekly'));
        $m = $row($s, 'smpl2weekly');
        checkEq('the undo sends the place back and no title; the series keeps the title from Google, there and here; nothing pending', [
            [['Sample room 1', false]], 'Sample remote series', 'Sample room 1', 'Sample remote series', 'Sample room 1', [],
        ], [array_map(static fn(array $c): array => [$c[2]['location'] ?? null, array_key_exists('summary', (array) $c[2])], $sent), $s->fake->events['smpl2weekly']['summary'], $s->fake->events['smpl2weekly']['location'] ?? null,
            $m['title'] ?? null, $m['location'] ?? null, $pending($s)]);
        check('a poll after changes nothing', $sameAfterPoll($s));
    });

    scenario('google undo: a day\'s own title undone after a rename for all events inherits the series\' title as it is now', function () use ($setup, $masterId, $last, $sameAfterPoll, $iso, $row, $day): void {
        $s = $setup();
        $s->events->patch($s->u, $masterId($s), ['scope' => 'this', 'instanceStart' => $iso('2027-03-08 10:00'), 'title' => 'Sample day title']);
        $s->events->throwDeferred();
        $entry = $last($s);
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'title' => 'Sample renamed']);
        checkEq('the rename keeps the day\'s own title', ['Sample day title', 'Sample day title'], [$s->fake->events[$day]['summary'] ?? null, $row($s, $day)['title'] ?? null]);
        $s->undo->undoById($s->u, (int) $entry['id']);
        $here = $row($s, $day);
        checkEq('the day follows the series again: the series\' title now at Google and here, not owned', [
            'Sample renamed', 'Sample renamed', false,
        ], [$s->fake->events[$day]['summary'] ?? null, $here['title'] ?? 'Sample renamed', in_array('title', json_decode((string) ($here['override_fields'] ?? '[]'), true) ?: [], true)]);
        check('a poll after changes nothing', $sameAfterPoll($s));
    });

    scenario('google undo: a tag on a day redone after its undo and a notes change for all events shows the series\' notes as they are now', function () use ($setup, $masterId, $last, $db, $sameAfterPoll, $iso, $day): void {
        $s = $setup();
        $s->events->patch($s->u, $masterId($s), ['scope' => 'this', 'instanceStart' => $iso('2027-03-08 10:00'), 'tagNames' => ['sample-day-tag']]);
        $s->events->throwDeferred();
        $s->undo->undoLatest($s->u);
        $redo = $last($s);
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'description' => 'Sample newer notes']);
        $s->t->calls = [];
        $s->undo->undoById($s->u, (int) $redo['id']);
        $here = $db->one('SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$masterId($s), '2027-03-08 18:00:00']);
        $tags = $here === null ? [] : array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ?', [(int) $here['id']]), 'name');
        checkEq('the redo sends Google nothing; the row is back with its tag over the series\' notes now', [
            [], 'Sample newer notes', '["tags"]', ['sample-day-tag'],
        ], [array_values(array_filter($s->t->calls, static fn(array $c): bool => $c[0] !== 'GET')), $here['description'] ?? null, $here['override_fields'] ?? null, $tags]);
        check('a poll after changes nothing', $sameAfterPoll($s));
    });
};
