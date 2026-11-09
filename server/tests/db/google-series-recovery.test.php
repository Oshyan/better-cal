<?php

declare(strict_types=1);

// Recovery of a series change half done at Google (GoogleSeries; docs/design/
// series-model.md, Google, as a Contract), against the probe's stand-in Google
// with failures injected at the transport: the journal is committed before
// each request whatever the account's transaction does after (a fatal error
// right after Google took a write leaves it, visible from another
// connection, and Review lists it), Put back reconciles a write whose answer
// was lost (an insert found by the id it carried, a PATCH by the read-back),
// and the rows Finish and Put back bring back keep their ids, tags, people,
// reminders and trip links, a failure at each step of the change. Invented data.

use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\ReviewQueue;
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

    // Weekly Mondays 10:00 Los Angeles from 1 March 2027, ten of them: the
    // third a day later, the fourth at its own time, the fifth with its own
    // title, the sixth skipped (the series google-series.test.php uses).
    $seedSeries = static function (FakeGoogle $fake) use ($when): void {
        $fake->seed(['id' => 'smpl3weekly', 'iCalUID' => 'smpl3weekly@google.com', 'summary' => 'Sample weekly', 'description' => 'Sample notes', 'location' => 'Sample room 1',
            'start' => $when('2027-03-01 10:00'), 'end' => $when('2027-03-01 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=10']]);
        $record = static fn(string $suffix, string $orig, array $more): array => $more + ['id' => 'smpl3weekly_' . $suffix, 'iCalUID' => 'smpl3weekly@google.com', 'recurringEventId' => 'smpl3weekly',
            'summary' => 'Sample weekly', 'description' => 'Sample notes', 'location' => 'Sample room 1', 'originalStartTime' => $when($orig)];
        $fake->seed($record('20270315T170000Z', '2027-03-15 10:00', ['start' => $when('2027-03-16 10:00'), 'end' => $when('2027-03-16 11:00')]));
        $fake->seed($record('20270322T170000Z', '2027-03-22 10:00', ['start' => $when('2027-03-22 15:00'), 'end' => $when('2027-03-22 16:00')]));
        $fake->seed($record('20270329T170000Z', '2027-03-29 10:00', ['start' => $when('2027-03-29 10:00'), 'end' => $when('2027-03-29 11:00'), 'summary' => 'Sample weekly, special']));
        $fake->seed($record('20270405T170000Z', '2027-04-05 10:00', ['start' => $when('2027-04-05 10:00'), 'end' => $when('2027-04-05 11:00'), 'status' => 'cancelled']));
    };

    $setup = static function () use ($db, $cfg, $expand, $seedSeries): object {
        $u = freshUser($db);
        $d = domains($db);
        $fake = new FakeGoogle('sample-crew@group.calendar.google.com');
        $fake->token = null;
        $fake->zone = 'America/Los_Angeles';
        $fake->expand = $expand;
        $seedSeries($fake);
        $t = new class {
            /** @var list<array{0:string,1:string}> method, id */
            public array $calls = [];
            public int $writes = 0;
            /** The write (1-based, PATCH, POST and DELETE counted) answered 412 without reaching the stand-in. */
            public ?int $staleAt = null;
            /** The write (1-based) answered 403 without reaching the stand-in. */
            public ?int $forbidAt = null;
            /** The write the stand-in takes, then a fatal error instead of its answer (an Error, not an exception). */
            public ?int $fatalAt = null;
            /** The write the stand-in takes, then its answer lost (a runtime exception). */
            public ?int $loseAt = null;
            /** The first POST the stand-in takes, then its answer lost. */
            public bool $loseFirstPost = false;
        };
        $inner = $fake->writerTransport();
        $transport = static function (string $method, string $url, ?array $payload, array $headers = []) use ($t, $inner): array {
            $t->calls[] = [$method, rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)))];
            $n = null;
            if ($method !== 'GET') {
                $n = ++$t->writes;
                if ($t->staleAt === $n) {
                    return ['status' => 412, 'body' => json_encode(['error' => ['code' => 412, 'message' => 'Precondition Failed']])];
                }
                if ($t->forbidAt === $n) {
                    return ['status' => 403, 'body' => json_encode(['error' => ['code' => 403, 'message' => 'Forbidden']])];
                }
            }
            $answer = $inner($method, $url, $payload, array_merge(['Authorization: Bearer sample-access'], $headers));
            if ($n !== null && $t->fatalAt === $n) {
                $t->fatalAt = null;
                throw new Error('sample fatal error after Google took the write');
            }
            if ($n !== null && $t->loseAt === $n) {
                $t->loseAt = null;
                throw new RuntimeException('the answer was lost');
            }
            if ($method === 'POST' && $t->loseFirstPost) {
                $t->loseFirstPost = false;
                throw new RuntimeException('the answer was lost');
            }
            return $answer;
        };
        $auth = new GoogleAuth($db, $cfg, static fn(string $url, array $fields, ?float $deadline): array
            => ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])]);
        $feeds = new Feeds($db);
        $writer = new GoogleWriter($db, $auth, $feeds, $transport);
        $syncTransport = $fake->syncTransport();
        $sync = new GoogleSync($db, $auth, $feeds, static fn(string $url, array $headers): array => $syncTransport($url, array_merge(['Authorization: Bearer sample-access'], $headers)));
        $trips = new BetterCal\Domain\Trips($db, $d->undo);
        $events = new Events($db, new Recurrence(), $d->undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $d->undo, new BetterCal\Infra\JobQueue($db)), $trips, null, $writer);
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $calendarId = $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample crew', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'sample-crew@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]));
        $t->calls = [];
        $t->writes = 0;
        return (object) ['u' => $u, 'd' => $d, 'fake' => $fake, 't' => $t, 'sync' => $sync, 'events' => $events, 'trips' => $trips, 'calendarId' => $calendarId,
            'undo' => new Undo($db, null, $writer)];
    };
    $masterId = static fn(object $s, string $googleId = 'smpl3weekly'): int => (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, $googleId]);
    $rowAt = static fn(object $s, string $key): ?array => $db->one('SELECT * FROM events WHERE calendar_id = ? AND uid = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$s->calendarId, 'smpl3weekly@google.com', $key]);
    $pending = static fn(object $s): array => $db->all('SELECT status, steps_json FROM google_pending WHERE user_id = ?', [$s->u]);
    // The journal as another connection sees it: committed, whatever the one that wrote the edit did.
    $elsewhere = static function (object $s) use ($db): array {
        $cfg = (fn(): array => $this->cfg)->call($db);
        $other = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $q = $other->prepare('SELECT status, steps_json FROM google_pending WHERE user_id = ?');
        $q->execute([$s->u]);
        return array_map(static fn(array $r): array => [$r['status'], array_map(static fn(array $st): string => $st['kind'] . ':' . $st['state'], json_decode((string) $r['steps_json'], true) ?: [])], $q->fetchAll());
    };
    $listed = static fn(object $s): array => (new ReviewQueue($db, $s->events))->googleHalfDone($s->u);
    // A write to a series with changes not yet at Google is refused (A6, checked first here). Recovery still meets
    // rows changed here since (a pending row from before the gate, or data reaching a row by a path that is not a
    // write to the series), so these cases then make the change with the pending row out of the gate's sight.
    $unguarded = static function (object $s, callable $write) use ($db): void {
        checkEq('the write while the change is not yet at Google is refused with google_pending', 'google_pending', refusal($write));
        $db->run("UPDATE google_pending SET status = 'unseen' WHERE user_id = ? AND status = 'half'", [$s->u]);
        try {
            $write();
        } finally {
            $db->run("UPDATE google_pending SET status = 'half' WHERE user_id = ? AND status = 'unseen'", [$s->u]);
        }
    };
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
    $move = static fn(object $s) => planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'start' => $iso('2027-03-01 11:00'), 'end' => $iso('2027-03-01 12:00')]);
    $recover = static function (object $s, string $how) use ($listed): array {
        $half = $listed($s);
        $out = $how === 'finish' ? $s->events->googleFinish($s->u, (int) $half[0]['id']) : $s->events->googlePutBack($s->u, (int) $half[0]['id']);
        $s->events->throwDeferred();
        return $out;
    };

    // ---- F1: the journal is committed before the request -----------------------

    scenario('google recovery: a fatal error right after Google took the master PATCH leaves the journal, seen from another connection; Review lists it; Put back and Finish complete', function () use ($setup, $masterId, $pending, $elsewhere, $listed, $recover, $db): void {
        foreach (['putBack' => 'Sample weekly', 'finish' => 'Sample weekly, renamed'] as $how => $title) {
            $s = $setup();
            $id = $masterId($s);
            $s->t->fatalAt = 1;
            $caught = null;
            try {
                planned($s->events, $s->u, $id, ['scope' => 'all', 'title' => 'Sample weekly, renamed']);
            } catch (Error $e) {
                $caught = $e;
            }
            checkEq("$how: the fatal error reaches the caller; Google holds the new title, the row here the old (its edit rolled back)", [true, 'Sample weekly, renamed', 'Sample weekly'],
                [$caught !== null, $s->fake->events['smpl3weekly']['summary'], $db->scalar('SELECT title FROM events WHERE id = ?', [$id])]);
            checkEq("$how: another connection sees the pending row, half done, the master's PATCH sent with no answer", [['half', ['master:sent']]], $elsewhere($s));
            $half = $listed($s);
            checkEq("$how: Review lists it, with the step that may have reached Google and words for what stopped", [1, 'Sample weekly', 1, true],
                [count($half), $half[0]['title'] ?? null, $half[0]['stepsDone'] ?? null, ($half[0]['error'] ?? '') !== '']);
            $out = $recover($s, $how);
            checkEq("$how: Google and the row here hold '$title'; nothing is left; the pending row is gone", [true, [], $title, $title, []],
                [$out['ok'], $out['left'] ?? [], $s->fake->events['smpl3weekly']['summary'], $db->scalar('SELECT title FROM events WHERE id = ?', [$masterId($s)]), $pending($s)]);
        }
    });

    scenario('google recovery: a fatal error after each write of a clock change leaves the journal with the steps answered and the one sent', function () use ($setup, $move, $elsewhere, $listed, $recover, $held, $stored, $pending): void {
        $dry = $setup();
        $move($dry);
        $writes = $dry->t->writes;
        check('the clock change takes several writes (the master and its edited days)', $writes >= 4, "writes: $writes");
        for ($n = 1; $n <= $writes; $n++) {
            $s = $setup();
            $was = $held($s, 'smpl3weekly');
            $s->t->fatalAt = $n;
            try {
                $move($s);
            } catch (Error) {
            }
            $seen = $elsewhere($s);
            $states = array_map(static fn(string $k): string => explode(':', $k)[1], $seen[0][1] ?? []);
            checkEq("fatal at write $n: another connection sees one half-done row, $n steps, the last sent, the rest done", [1, 'half', $n, 'sent', array_fill(0, $n - 1, 'done')],
                [count($seen), $seen[0][0] ?? null, count($states), end($states) ?: null, array_slice($states, 0, -1)]);
            checkEq("fatal at write $n: Review lists it", 1, count($listed($s)));
            $out = $recover($s, 'putBack');
            checkEq("fatal at write $n: Put back restores the series at Google and here; nothing left; no pending row", [true, [], $was, $was, []],
                [$out['ok'], $out['left'], $held($s, 'smpl3weekly'), $stored($s, 'smpl3weekly'), $pending($s)]);
        }
    });

    scenario('google recovery: a series undo that stops on a fatal error after Google took its first write leaves the journal; Put back returns the series to the change', function () use ($setup, $move, $elsewhere, $listed, $recover, $held, $stored, $pending, $db): void {
        $s = $setup();
        $move($s);
        $changed = $held($s, 'smpl3weekly');
        $entry = $db->one('SELECT * FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$s->u]);
        $s->t->writes = 0;
        $s->t->fatalAt = 1;
        $caught = null;
        try {
            $s->undo->undoById($s->u, (int) $entry['id']);
        } catch (Error $e) {
            $caught = $e;
        }
        checkEq('the undo stops; another connection sees its pending row, half done, the master sent; the entry is not undone; Review lists it', [true, [['half', ['master:sent']]], 0, 1],
            [$caught !== null, $elsewhere($s), (int) $db->scalar('SELECT undone FROM mutations WHERE id = ?', [(int) $entry['id']]), count($listed($s))]);
        $out = $recover($s, 'putBack');
        checkEq('Put back: the series is as the change left it, at Google and here; the pending row is gone', [true, [], $changed, $changed, []],
            [$out['ok'], $out['left'], $held($s, 'smpl3weekly'), $stored($s, 'smpl3weekly'), $pending($s)]);
    });

    scenario('google recovery: a change refused before anything reached Google leaves no journal (a series undo rolled back whole)', function () use ($setup, $move, $pending, $db): void {
        $s = $setup();
        $move($s);
        $entry = $db->one('SELECT * FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$s->u]);
        $s->t->writes = 0;
        $s->t->staleAt = 1;
        try {
            $s->undo->undoById($s->u, (int) $entry['id']);
        } catch (HttpError) {
        }
        checkEq('the conflict is the undo\'s, nothing was sent, and no pending row is left', [[], 0], [$pending($s), (int) $db->scalar('SELECT undone FROM mutations WHERE id = ?', [(int) $entry['id']])]);
    });

    scenario('google recovery: a change refused at its first write (a 412 or a 403) records nothing: no pending row, the rows and the trip flag as they were, words that say nothing reached Google', function () use ($setup, $masterId, $pending, $held, $db): void {
        foreach (['staleAt' => 'google_conflict', 'forbidAt' => 'google_write_failed'] as $knob => $code) {
            $s = $setup();
            $id = $masterId($s);
            $rows = static fn(): array => $db->all('SELECT * FROM events WHERE calendar_id = ? ORDER BY id', [$s->calendarId]);
            $was = [$rows(), $held($s, 'smpl3weekly'), (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$s->u])];
            $s->t->writes = 0;
            $s->t->$knob = 1;
            $caught = null;
            try {
                planned($s->events, $s->u, $id, ['scope' => 'all', 'title' => 'Sample weekly, renamed', 'isContainer' => true]);
                $s->events->throwDeferred();
            } catch (HttpError $e) {
                $caught = $e;
            }
            $s->t->$knob = null;
            checkEq("$knob at write 1: refused with $code, words without the half-done sentence, no pending named; no pending row; rows, entries and Google as they were",
                [$code, false, false, [], $was],
                [$caught?->errorCode, str_contains((string) $caught?->getMessage(), 'half done'), isset($caught?->extra['pending']), $pending($s),
                    [$rows(), $held($s, 'smpl3weekly'), (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$s->u])]]);
        }
    });

    // ---- F2: Put back reconciles what may have landed ----------------------------

    scenario('google recovery: a split whose new series was inserted with its answer lost is put back whole: the new series cancelled, the old one as it was', function () use ($setup, $masterId, $held, $stored, $pending, $recover, $iso): void {
        $s = $setup();
        $was = $held($s, 'smpl3weekly');
        $s->t->loseFirstPost = true;
        try {
            planned($s->events, $s->u, $masterId($s), ['scope' => 'following', 'instanceStart' => $iso('2027-03-22 10:00'), 'title' => 'Sample split']);
        } catch (HttpError) {
        }
        $new = array_values(array_filter(array_keys($s->fake->events), static fn(string $k): bool => !empty($s->fake->events[$k]['recurrence']) && $k !== 'smpl3weekly'));
        checkEq('the new series landed at Google under its chosen id', 1, count($new));
        $out = $recover($s, 'putBack');
        checkEq('Put back cancels the new series it was never told of, and restores the old one at Google and here; nothing left; no pending row', [true, [], 'cancelled', $was, $was, []],
            [$out['ok'], $out['left'], $s->fake->events[$new[0]]['status'] ?? 'confirmed', $held($s, 'smpl3weekly'), $stored($s, 'smpl3weekly'), $pending($s)]);
    });

    scenario('google recovery: a PATCH whose answer was lost is put back, not left as a change made elsewhere', function () use ($setup, $move, $held, $stored, $pending, $recover): void {
        foreach ([1 => 'the master', 2 => 'an edited day'] as $n => $what) {
            $s = $setup();
            $was = $held($s, 'smpl3weekly');
            $s->t->loseAt = $n;
            try {
                $move($s);
            } catch (HttpError) {
            }
            $out = $recover($s, 'putBack');
            checkEq("$what's answer lost: Put back finds it landed and restores the series at Google and here; nothing left; no pending row", [true, [], $was, $was, []],
                [$out['ok'], $out['left'], $held($s, 'smpl3weekly'), $stored($s, 'smpl3weekly'), $pending($s)]);
        }
    });

    // ---- F3: recovery keeps what hangs off the rows ---------------------------------

    scenario('google recovery: an edited day with a tag, a person, reminders and a trip link keeps them, and its row, through Finish and Put back, a failure at each step', function () use ($setup, $move, $rowAt, $held, $stored, $pending, $recover, $db): void {
        $dry = $setup();
        $move($dry);
        $writes = $dry->t->writes;
        $finished = $held($dry, 'smpl3weekly');
        $parts = static fn(int $id): array => [
            array_map('intval', array_column($db->all('SELECT tag_id FROM event_tags WHERE event_id = ? ORDER BY tag_id', [$id]), 'tag_id')),
            array_map('intval', array_column($db->all('SELECT person_id FROM event_people WHERE event_id = ? ORDER BY person_id', [$id]), 'person_id')),
            $db->scalar('SELECT reminders_json FROM events WHERE id = ?', [$id]),
            array_map('intval', array_column($db->all('SELECT container_id FROM event_links WHERE event_id = ? ORDER BY container_id', [$id]), 'container_id')),
        ];
        foreach (['putBack' => '2027-03-29 17:00:00', 'finish' => '2027-03-29 18:00:00'] as $how => $key) {
            for ($n = 1; $n <= $writes; $n++) {
                $s = $setup();
                $was = $held($s, 'smpl3weekly');
                $day = (int) ($rowAt($s, '2027-03-29 17:00:00')['id'] ?? 0);
                $s->events->patch($s->u, $day, ['tagNames' => ['Sample label']]);
                $person = $db->insert('people', ['user_id' => $s->u, 'name' => 'Sam Sample']);
                $db->insert('event_people', ['event_id' => $day, 'person_id' => $person]);
                $db->update('events', ['reminders_json' => '[{"minutes":30}]'], 'id = ?', [$day]);
                $home = (int) $s->d->calendars->create($s->u, ['name' => 'Sample home'])['id'];
                $trip = (int) $s->events->create($s->u, eventAt($home, 'Sample trip', '2027-03-28', ['isContainer' => true]))['eventId'];
                $s->trips->attach($s->u, $trip, $day);
                $before = $parts($day);
                $s->t->writes = 0;
                $s->t->staleAt = $n;
                try {
                    $move($s);
                } catch (HttpError) {
                }
                $s->t->staleAt = null;
                if ($n === 1) {
                    // Refused at the first write: nothing reached Google, so nothing is recorded, and there is nothing to recover.
                    checkEq("$how, refused at write 1: no pending row; the day's row and what hangs off it as they were; Google as it was",
                        [[], $day, $before, $was], [$pending($s), (int) ($rowAt($s, '2027-03-29 17:00:00')['id'] ?? 0), $parts($day), $held($s, 'smpl3weekly')]);
                    continue;
                }
                $out = $recover($s, $how);
                $after = $rowAt($s, $key);
                checkEq("$how, failure at write $n: the day is its own row again with its id, tag, person, reminders and trip link; Google and the rows agree; no pending row",
                    [true, $day, $before, true, $how === 'finish' ? $finished : $was, []],
                    [$out['ok'], (int) ($after['id'] ?? 0), $parts((int) ($after['id'] ?? 0)), $held($s, 'smpl3weekly') === $stored($s, 'smpl3weekly'), $held($s, 'smpl3weekly'), $pending($s)]);
            }
        }
    });

    // ---- R2: a write whose answer was lost is put back only while nothing changed since

    // Put back that finds a change made at Google since: [its answer's ok, the error's code and words, the change still in Review].
    $refused = static function (object $s) use ($listed): array {
        $out = $s->events->googlePutBack($s->u, (int) $listed($s)[0]['id']);
        $error = null;
        try {
            $s->events->throwDeferred();
        } catch (HttpError $e) {
            $error = $e;
        }
        return [$out['ok'], $error?->errorCode, (string) $error?->getMessage(), count($listed($s))];
    };

    scenario('google recovery: a split whose insert answer was lost, the new series renamed elsewhere since: Put back sends nothing, changes nothing here, and names the series and Finish', function () use ($setup, $masterId, $held, $stored, $elsewhere, $refused, $iso): void {
        $s = $setup();
        $s->t->loseFirstPost = true;
        try {
            planned($s->events, $s->u, $masterId($s), ['scope' => 'following', 'instanceStart' => $iso('2027-03-22 10:00'), 'title' => 'Sample split']);
        } catch (HttpError) {
        }
        $new = array_values(array_filter(array_keys($s->fake->events), static fn(string $k): bool => !empty($s->fake->events[$k]['recurrence']) && $k !== 'smpl3weekly'))[0];
        $s->fake->seed(array_replace($s->fake->events[$new], ['summary' => 'Sample renamed elsewhere']));
        $old = $held($s, 'smpl3weekly');
        $here = $stored($s, 'smpl3weekly');
        $journal = $elsewhere($s);
        $writes = $s->t->writes;
        [$ok, $code, $words, $listedAfter] = $refused($s);
        checkEq('not ok, google_changed_since; the words name the series and Finish; the change is still in Review, its journal as it was',
            [false, 'google_changed_since', true, true, 1, $journal],
            [$ok, $code, str_contains($words, "'Sample split' changed at Google since"), str_contains($words, 'Finish'), $listedAfter, $elsewhere($s)]);
        checkEq("nothing sent; the old series keeps the change's end at Google and here; the new series stands as edited",
            [$writes, $old, $here, true, 'confirmed', 'Sample renamed elsewhere'],
            [$s->t->writes, $held($s, 'smpl3weekly'), $stored($s, 'smpl3weekly'), str_contains((string) $old['rule'], 'UNTIL='), $s->fake->events[$new]['status'] ?? 'confirmed', $s->fake->events[$new]['summary']]);
    });

    scenario('google recovery: an answered step and a step whose answer was lost both unchanged since: Put back reverses the whole change', function () use ($setup, $masterId, $held, $stored, $pending, $elsewhere, $recover, $iso): void {
        $s = $setup();
        $was = $held($s, 'smpl3weekly');
        $s->t->loseFirstPost = true;
        try {
            planned($s->events, $s->u, $masterId($s), ['scope' => 'following', 'instanceStart' => $iso('2027-03-22 10:00'), 'title' => 'Sample split']);
        } catch (HttpError) {
        }
        $states = $elsewhere($s)[0][1] ?? [];
        checkEq("the journal holds the old series' write answered and the insert sent with no answer", [true, 'insert:sent'], [in_array('master:done', $states, true), end($states) ?: null]);
        $new = array_values(array_filter(array_keys($s->fake->events), static fn(string $k): bool => !empty($s->fake->events[$k]['recurrence']) && $k !== 'smpl3weekly'))[0];
        $out = $recover($s, 'putBack');
        checkEq('both reversed: the new series cancelled, the old one as it was at Google and here; nothing left; no pending row', [true, [], 'cancelled', $was, $was, []],
            [$out['ok'], $out['left'], $s->fake->events[$new]['status'] ?? 'confirmed', $held($s, 'smpl3weekly'), $stored($s, 'smpl3weekly'), $pending($s)]);
    });

    scenario('google recovery: a PATCH whose answer was lost, then another part of the series changed elsewhere: Put back sends nothing and names the series', function () use ($setup, $masterId, $move, $held, $refused, $when, $db): void {
        // An edited day's PATCH lost, then the series' place changed elsewhere.
        $s = $setup();
        $s->t->loseAt = 2;
        try {
            $move($s);
        } catch (HttpError) {
        }
        $s->fake->seed(array_replace($s->fake->events['smpl3weekly'], ['location' => 'Sample room elsewhere']));
        $there = $held($s, 'smpl3weekly');
        [$ok, $code, $words, $listedAfter] = $refused($s);
        checkEq("an edited day's answer lost, the place changed since: refused, the series named; Google keeps both; still in Review",
            [false, 'google_changed_since', true, $there, 1], [$ok, $code, str_contains($words, "'Sample weekly' changed at Google since"), $held($s, 'smpl3weekly'), $listedAfter]);

        // The master's PATCH (a rename) lost, then a day edited elsewhere.
        $s = $setup();
        $s->t->loseAt = 1;
        try {
            planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'title' => 'Sample weekly, renamed']);
        } catch (HttpError) {
        }
        $s->fake->seed(['id' => 'smpl3weekly_20270308T180000Z', 'iCalUID' => 'smpl3weekly@google.com', 'recurringEventId' => 'smpl3weekly', 'summary' => 'Sample weekly, renamed',
            'description' => 'Sample notes', 'location' => 'Sample room 2', 'originalStartTime' => $when('2027-03-08 10:00'), 'start' => $when('2027-03-08 10:00'), 'end' => $when('2027-03-08 11:00')]);
        $there = $held($s, 'smpl3weekly');
        $title = $db->scalar('SELECT title FROM events WHERE id = ?', [$masterId($s)]);
        [$ok, $code, , $listedAfter] = $refused($s);
        checkEq("the master's answer lost, a day edited since: refused; Google and the row here as they were; still in Review",
            [false, 'google_changed_since', $there, $title, 1], [$ok, $code, $held($s, 'smpl3weekly'), $db->scalar('SELECT title FROM events WHERE id = ?', [$masterId($s)]), $listedAfter]);
    });

    scenario('google recovery: a rename whose answer was lost, nothing changed since: Put back restores the series', function () use ($setup, $masterId, $held, $recover, $db): void {
        $s = $setup();
        $was = $held($s, 'smpl3weekly');
        $s->t->loseAt = 1;
        try {
            planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'title' => 'Sample weekly, renamed']);
        } catch (HttpError) {
        }
        $out = $recover($s, 'putBack');
        checkEq("the master's answer lost, nothing changed since: Put back restores the series; nothing left", [true, [], $was, 'Sample weekly'],
            [$out['ok'], $out['left'], $held($s, 'smpl3weekly'), $db->scalar('SELECT title FROM events WHERE id = ?', [$masterId($s)])]);
    });

    // ---- N2: the series' zone is part of "nothing changed since" ----------------------

    scenario('google recovery: a step whose answer was lost, then only the zone changed elsewhere at the same first instant: Put back sends nothing and keeps the change for Review', function () use ($setup, $masterId, $move, $held, $elsewhere, $refused, $iso, $db): void {
        // Only the zone changed at Google: the first day's instants stay, every later day's differ.
        $rezone = static function (array $event): array {
            foreach (['start', 'end'] as $k) {
                $event[$k]['timeZone'] = 'America/Phoenix';
            }
            return $event;
        };
        $arms = [
            'the insert of a split' => static function (object $s) use ($masterId, $iso, $rezone): void {
                $s->t->loseFirstPost = true;
                try {
                    planned($s->events, $s->u, $masterId($s), ['scope' => 'following', 'instanceStart' => $iso('2027-03-22 10:00'), 'title' => 'Sample split']);
                } catch (HttpError) {
                }
                $new = array_values(array_filter(array_keys($s->fake->events), static fn(string $k): bool => !empty($s->fake->events[$k]['recurrence']) && $k !== 'smpl3weekly'))[0];
                $s->fake->seed($rezone($s->fake->events[$new]));
            },
            "the master's rename" => static function (object $s) use ($masterId, $rezone): void {
                $s->t->loseAt = 1;
                try {
                    planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'title' => 'Sample weekly, renamed']);
                } catch (HttpError) {
                }
                $s->fake->seed($rezone($s->fake->events['smpl3weekly']));
            },
            "an edited day's PATCH" => static function (object $s) use ($move, $rezone): void {
                $s->t->loseAt = 2;
                try {
                    $move($s);
                } catch (HttpError) {
                }
                $s->fake->seed($rezone($s->fake->events['smpl3weekly']));
            },
        ];
        foreach ($arms as $what => $arm) {
            $s = $setup();
            $arm($s);
            $there = $held($s, 'smpl3weekly');
            $title = $db->scalar('SELECT title FROM events WHERE id = ?', [$masterId($s)]);
            $journal = $elsewhere($s);
            $writes = $s->t->writes;
            [$ok, $code, $words, $listedAfter] = $refused($s);
            checkEq("$what's answer lost, the zone changed since: not ok, google_changed_since, Finish named; nothing sent; Google and the row here as they were; still in Review, its journal as it was",
                [false, 'google_changed_since', true, $writes, $there, $title, 1, $journal],
                [$ok, $code, str_contains($words, 'Finish'), $s->t->writes, $held($s, 'smpl3weekly'), $db->scalar('SELECT title FROM events WHERE id = ?', [$masterId($s)]), $listedAfter, $elsewhere($s)]);
        }
    });

    // ---- R3: Finish writes the change's part here too --------------------------------

    scenario('google recovery: a rename with a part here (a tag, a person, reminders, the trip flag, the clock-time flag), stopped before the commit here, finishes both', function () use ($setup, $masterId, $pending, $recover, $db): void {
        $parts = [
            'a tag' => [['tagNames' => ['Sample new tag']], static fn(int $id): mixed => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ?', [$id]), 'name'), ['Sample new tag']],
            'a person' => [['personNames' => ['Alex Example']], static fn(int $id): mixed => array_column($db->all('SELECT p.name FROM event_people ep JOIN people p ON p.id = ep.person_id WHERE ep.event_id = ?', [$id]), 'name'), ['Alex Example']],
            'reminders' => [['reminders' => [['minutes' => 25]]], static fn(int $id): mixed => array_column(json_decode((string) $db->scalar('SELECT reminders_json FROM events WHERE id = ?', [$id]), true) ?: [], 'minutes'), [25]],
            'the trip flag' => [['isContainer' => true], static fn(int $id): mixed => (int) $db->scalar('SELECT is_container FROM events WHERE id = ?', [$id]), 1],
            'the clock-time flag' => [['floating' => true], static fn(int $id): mixed => (int) $db->scalar('SELECT floating FROM events WHERE id = ?', [$id]), 1],
        ];
        foreach ($parts as $what => [$in, $read, $want]) {
            foreach (['the first answer lost' => 'loseAt', 'a fatal error after the first write' => 'fatalAt'] as $how => $knob) {
                $s = $setup();
                $id = $masterId($s);
                $s->t->{$knob} = 1;
                try {
                    planned($s->events, $s->u, $id, ['scope' => 'all', 'title' => 'Sample weekly, renamed'] + $in);
                } catch (Throwable) {
                }
                $out = $recover($s, 'finish');
                checkEq("$what, $how: Finish leaves the new title at Google and here and $what on the series; nothing left; no pending row",
                    [true, [], 'Sample weekly, renamed', 'Sample weekly, renamed', $want, []],
                    [$out['ok'], $out['left'], $s->fake->events['smpl3weekly']['summary'], $db->scalar('SELECT title FROM events WHERE id = ?', [$masterId($s)]), $read($masterId($s)), $pending($s)]);
            }
        }
    });

    scenario('google recovery: a split with a new title and tag from the day on, stopped at the old series or at the insert, finishes with the new series as planned', function () use ($setup, $masterId, $pending, $recover, $iso, $db): void {
        $arms = [
            "the old series' answer lost" => static function (object $s): void {
                $s->t->loseAt = 1;
            },
            "the insert's answer lost" => static function (object $s): void {
                $s->t->loseFirstPost = true;
            },
        ];
        $newSeries = 'SELECT * FROM events WHERE calendar_id = ? AND recurrence_parent_id IS NULL AND rrule IS NOT NULL AND google_event_id <> ? AND deleted_at IS NULL';
        $tags = static fn(int $id): array => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ?', [$id]), 'name');
        foreach ($arms as $what => $arm) {
            $s = $setup();
            $arm($s);
            try {
                planned($s->events, $s->u, $masterId($s), ['scope' => 'following', 'instanceStart' => $iso('2027-03-22 10:00'), 'title' => 'Sample split', 'tagNames' => ['Sample split tag']]);
            } catch (HttpError) {
            }
            $out = $recover($s, 'finish');
            $new = $db->all($newSeries, [$s->calendarId, 'smpl3weekly']);
            checkEq("$what: Finish leaves one new series here, titled and tagged as planned, held at Google under its id; the old one ends before it, untagged; no pending row",
                [true, 1, 'Sample split', ['Sample split tag'], 'Sample split', true, [], []],
                [$out['ok'], count($new), $new[0]['title'] ?? null, $tags((int) ($new[0]['id'] ?? 0)), $s->fake->events[(string) ($new[0]['google_event_id'] ?? '')]['summary'] ?? null,
                    str_contains((string) $db->scalar('SELECT rrule FROM events WHERE id = ?', [$masterId($s)]), 'UNTIL='), $tags($masterId($s)), $pending($s)]);
        }
    });

    scenario('google recovery: a part here Finish cannot write is reported, and the change stays half done for Review with what Google holds stored', function () use ($setup, $masterId, $listed, $db): void {
        $s = $setup();
        $id = $masterId($s);
        $s->t->loseAt = 1;
        try {
            planned($s->events, $s->u, $id, ['scope' => 'all', 'title' => 'Sample weekly, renamed', 'tagNames' => ['Sample new tag']]);
        } catch (HttpError) {
        }
        $row = $db->one('SELECT id, plan_json FROM google_pending WHERE user_id = ?', [$s->u]);
        $plan = json_decode((string) $row['plan_json'], true);
        $plan['writes']['master']['sample_no_such_column'] = 1;
        $db->update('google_pending', ['plan_json' => json_encode($plan)], 'id = ?', [(int) $row['id']]);
        $out = $s->events->googleFinish($s->u, (int) $listed($s)[0]['id']);
        $code = null;
        try {
            $s->events->throwDeferred();
        } catch (HttpError $e) {
            $code = $e->errorCode;
        }
        $half = $listed($s);
        checkEq('Finish says so (not ok, google_finish_local); Review still lists the change with words for the part here; Google and the row here hold the new title; no tag',
            [false, 'google_finish_local', 1, true, 'Sample weekly, renamed', 'Sample weekly, renamed', 0],
            [$out['ok'], $code, count($half), str_contains($half[0]['error'] ?? '', 'its part here'), $s->fake->events['smpl3weekly']['summary'],
                $db->scalar('SELECT title FROM events WHERE id = ?', [$masterId($s)]), (int) $db->scalar('SELECT COUNT(*) FROM event_tags WHERE event_id = ?', [$masterId($s)])]);
    });

    scenario('google recovery: a shortened series half done, then a day past the new end tagged here: Finish\'s part here fails, its transaction rolls back whole (no savepoint), and what Google holds is stored after it', function () use ($unguarded, $setup, $masterId, $rowAt, $listed, $held, $stored, $iso, $db): void {
        $s = $setup();
        $id = $masterId($s);
        $s->t->loseAt = 1;
        try {
            planned($s->events, $s->u, $id, ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'rrule' => 'FREQ=WEEKLY;COUNT=4']);
        } catch (HttpError) {
        }
        // A day the shortened series no longer has, tagged and given a person here while the change is half done.
        $unguarded($s, fn() => $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => $iso('2027-04-19 10:00'), 'tagNames' => ['Sample later tag'], 'personNames' => ['Alex Example']]));
        $s->events->throwDeferred();
        $day = $rowAt($s, '2027-04-19 17:00:00');
        $out = $s->events->googleFinish($s->u, (int) $listed($s)[0]['id']);
        $error = null;
        try {
            $s->events->throwDeferred();
        } catch (HttpError $e) {
            $error = [$e->status, $e->errorCode, $e->getMessage()];
        }
        $half = $listed($s);
        checkEq('Finish fails with google_finish_local naming the day; Review still lists the change with those words; the day had its row before; Google holds the shortened series and the rows here hold exactly that (the day Google lacks has no row); no transaction left open',
            [[409, 'google_finish_local', true], false, 1, true, true, 'RRULE:FREQ=WEEKLY;COUNT=4', $held($s, 'smpl3weekly'), null, false],
            [[$error[0] ?? null, $error[1] ?? null, str_contains((string) ($error[2] ?? ''), 'changed here after this change stopped')], $out['ok'], count($half),
                str_contains((string) ($half[0]['error'] ?? ''), 'its part here'), $day !== null, $held($s, 'smpl3weekly')['rule'], $stored($s, 'smpl3weekly'),
                $rowAt($s, '2027-04-19 17:00:00'), $db->pdo()->inTransaction()]);
    });

    // ---- N1: recovery removes only the rows it made -----------------------------------

    scenario('google recovery: a rename half done, then a tag, a person or a reminder on an ordinary day here: Finish keeps that day\'s row and what it has, and the rename lands', function () use ($unguarded, $setup, $masterId, $rowAt, $pending, $recover, $iso, $db): void {
        $parts = [
            'a tag' => [['tagNames' => ['Sample later tag']], static fn(int $id): mixed => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ?', [$id]), 'name'), ['Sample later tag']],
            'a person' => [['personNames' => ['Sam Sample']], static fn(int $id): mixed => array_column($db->all('SELECT p.name FROM event_people ep JOIN people p ON p.id = ep.person_id WHERE ep.event_id = ?', [$id]), 'name'), ['Sam Sample']],
            'a reminder' => [['reminders' => [['minutes' => 15]]], static fn(int $id): mixed => array_column(json_decode((string) $db->scalar('SELECT reminders_json FROM events WHERE id = ?', [$id]), true) ?: [], 'minutes'), [15]],
        ];
        foreach ($parts as $what => [$in, $read, $want]) {
            $s = $setup();
            $id = $masterId($s);
            $s->t->loseAt = 1;
            try {
                planned($s->events, $s->u, $id, ['scope' => 'all', 'title' => 'Sample pending rename']);
            } catch (HttpError) {
            }
            $unguarded($s, fn() => $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => $iso('2027-03-08 10:00')] + $in));
            $s->events->throwDeferred();
            $day = (int) ($rowAt($s, '2027-03-08 18:00:00')['id'] ?? 0);
            $out = $recover($s, 'finish');
            $after = $rowAt($s, '2027-03-08 18:00:00');
            checkEq("$what: Finish ok; the day keeps its row, its id and $what, and takes the new title it inherits; the rename at Google and here; no pending row",
                [true, true, $day, $want, 'Sample pending rename', 'Sample pending rename', 'Sample pending rename', []],
                [$out['ok'], $day > 0, (int) ($after['id'] ?? 0), $read($day), $after['title'] ?? null, $s->fake->events['smpl3weekly']['summary'], $db->scalar('SELECT title FROM events WHERE id = ?', [$masterId($s)]), $pending($s)]);
        }
    });

    scenario('google recovery: rows a store of the half-done change made are in its journal, and Finish removes exactly those before the part here', function () use ($setup, $move, $rowAt, $held, $stored, $pending, $recover, $db): void {
        $dry = $setup();
        $move($dry);
        $writes = $dry->t->writes;
        $madeAny = false;
        for ($n = 2; $n <= $writes; $n++) {
            $s = $setup();
            $day = (int) ($rowAt($s, '2027-03-29 17:00:00')['id'] ?? 0);
            $s->t->staleAt = $n;
            try {
                $move($s);
            } catch (HttpError) {
            }
            $s->t->staleAt = null;
            $row = $db->one('SELECT local_json, made_json FROM google_pending WHERE user_id = ?', [$s->u]);
            $snapIds = array_map(static fn(array $r): int => (int) $r['id'], json_decode((string) $row['local_json'], true)['events'] ?? []);
            $made = array_map('intval', json_decode((string) ($row['made_json'] ?? ''), true) ?: []);
            $madeAny = $madeAny || $made !== [];
            $live = $made === [] ? 0 : (int) $db->scalar('SELECT COUNT(*) FROM events WHERE id IN (' . implode(',', $made) . ') AND deleted_at IS NULL');
            checkEq("failure at write $n: every row the journal says a store made is a live row of the series the snapshot lacks", [count($made), []],
                [$live, array_values(array_intersect($made, $snapIds))]);
            $out = $recover($s, 'finish');
            $gone = $made === [] ? 0 : (int) $db->scalar('SELECT COUNT(*) FROM events WHERE id IN (' . implode(',', $made) . ')');
            checkEq("failure at write $n: Finish ok; no row a store made is left; the edited day is at its new key under its own id; Google and the rows agree; no pending row",
                [true, 0, $day, true, []],
                [$out['ok'], $gone, (int) ($rowAt($s, '2027-03-29 18:00:00')['id'] ?? 0), $held($s, 'smpl3weekly') === $stored($s, 'smpl3weekly'), $pending($s)]);
        }
        checkEq('some failure point leaves a row a store made (the case is exercised)', true, $madeAny);
    });

    scenario('google recovery: a row a store of the half-done change made, then tagged here, keeps its tag on the day through Finish and Put back', function () use ($unguarded, $setup, $move, $rowAt, $held, $stored, $pending, $recover, $db): void {
        $dry = $setup();
        $move($dry);
        $writes = $dry->t->writes;
        $was = $held($setup(), 'smpl3weekly');
        $tags = static fn(int $id): array => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ?', [$id]), 'name');
        $ran = 0;
        foreach (['finish', 'putBack'] as $how) {
            for ($n = 2; $n <= $writes; $n++) {
                $s = $setup();
                $s->t->staleAt = $n;
                try {
                    $move($s);
                } catch (HttpError) {
                }
                $s->t->staleAt = null;
                $row = $db->one('SELECT targets_json, made_json FROM google_pending WHERE user_id = ?', [$s->u]);
                $made = array_map('intval', json_decode((string) ($row['made_json'] ?? ''), true) ?: []);
                $day = $made === [] ? null : $db->one('SELECT * FROM events WHERE id IN (' . implode(',', $made) . ') AND recurrence_instance_utc IS NOT NULL AND deleted_at IS NULL ORDER BY id LIMIT 1');
                if ($day === null) {
                    continue;
                }
                $ran++;
                $key = (string) $day['recurrence_instance_utc'];
                // A "this event" tag on that day, as the editor sends it.
                $unguarded($s, fn() => $s->events->patch($s->u, (int) $day['recurrence_parent_id'], ['scope' => 'this', 'instanceStart' => Time::fromDb($key)->format('Y-m-d\TH:i:s\Z'), 'tagNames' => ['Sample later tag']]));
                checkEq("$how, failure at write $n: the tag is the day's own on the row the store made", true, in_array('tags', json_decode((string) $db->scalar('SELECT override_fields FROM events WHERE id = ?', [(int) $day['id']]), true) ?: [], true));
                $s->events->throwDeferred();
                // The day the change's target names at that key: its row in the snapshot.
                $rowId = (int) (json_decode((string) $row['targets_json'], true)[0]['exceptions'][$key]['rowId'] ?? 0);
                $out = $recover($s, $how);
                $there = $how === 'finish' ? $rowAt($s, $key) : $db->one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [$rowId]);
                checkEq("$how, failure at write $n: ok; the day the tagged row stood for keeps the tag on its row; the made row is gone; Google and the rows agree; no pending row",
                    [true, ['Sample later tag'], 0, true, []],
                    [$out['ok'], $tags((int) ($there['id'] ?? 0)), (int) $db->scalar('SELECT COUNT(*) FROM events WHERE id = ? AND id <> ?', [(int) $day['id'], (int) ($there['id'] ?? 0)]),
                        $held($s, 'smpl3weekly') === $stored($s, 'smpl3weekly'), $pending($s)]);
                if ($how === 'putBack') {
                    checkEq("putBack, failure at write $n: the series is as it was at Google", $was, $held($s, 'smpl3weekly'));
                }
            }
        }
        checkEq('the case is exercised for both', true, $ran >= 2);
    });

    // ---- the sweep: Finish applies the change's deltas over what holds now (rule A); Put back's
    // "nothing changed since" is every field of every resource (rule B); a day with only local data
    // is ordinary to Google (rule D); a delete of one day touches that day and the skips -----------

    // Another client's write to Google, straight to the stand-in.
    $elsewhereAtGoogle = static function (object $s, string $id, array $body): void {
        ($s->fake->writerTransport())('PATCH', 'https://www.googleapis.com/calendar/v3/calendars/sample-crew%40group.calendar.google.com/events/' . $id, $body, ['Authorization: Bearer sample-access']);
    };
    $lostRename = static function (object $s, array $more = []) use ($masterId): void {
        $s->t->loseAt = $s->t->writes + 1;
        try {
            planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'title' => 'Sample pending rename'] + $more);
        } catch (HttpError) {
        }
    };
    $day = static function (object $s, string $suffix): ?array {
        $r = $s->fake->records('smpl3weekly')[$suffix] ?? null;
        return $r === null ? null : [$r['summary'] ?? null, $r['status'] ?? 'confirmed', $r['description'] ?? null, $r['location'] ?? null, $r['start']['dateTime'] ?? null];
    };
    $writesOf = static fn(object $s): array => array_values(array_map(static fn(array $c): string => $c[0] . ' ' . $c[1], array_filter($s->t->calls, static fn(array $c): bool => $c[0] !== 'GET')));

    scenario('google recovery sweep F1: a rename whose answer was lost, then a skipped day brought back or an edited day reset elsewhere: Put back sends nothing and names the series', function () use ($setup, $lostRename, $elsewhereAtGoogle, $refused, $held, $iso): void {
        $arms = [
            'the skipped 5 April brought back' => static fn(object $s) => $elsewhereAtGoogle($s, 'smpl3weekly_20270405T170000Z', ['status' => 'confirmed']),
            'the moved 22 March reset to the series\' time' => static fn(object $s) => $elsewhereAtGoogle($s, 'smpl3weekly_20270322T170000Z',
                ['start' => ['dateTime' => $iso('2027-03-22 10:00'), 'timeZone' => 'America/Los_Angeles'], 'end' => ['dateTime' => $iso('2027-03-22 11:00'), 'timeZone' => 'America/Los_Angeles']]),
            'a note on the ordinary 8 March (control)' => static fn(object $s) => $elsewhereAtGoogle($s, 'smpl3weekly_20270308T180000Z', ['description' => 'Sample remote note']),
        ];
        foreach ($arms as $what => $arm) {
            $s = $setup();
            $lostRename($s);
            $arm($s);
            $there = $held($s, 'smpl3weekly');
            $writes = $s->t->writes;
            [$ok, $code, $words, $listedAfter] = $refused($s);
            checkEq("$what: not ok, google_changed_since, the series named; nothing sent; Google as it was; still in Review",
                [false, 'google_changed_since', true, $writes, $there, 1], [$ok, $code, str_contains($words, "'Sample weekly' changed at Google since"), $s->t->writes, $held($s, 'smpl3weekly'), $listedAfter]);
        }
    });

    scenario('google recovery sweep F2: Finish of a rename whose answer was lost keeps what changed elsewhere on days the rename did not change', function () use ($setup, $lostRename, $elsewhereAtGoogle, $recover, $day, $rowAt, $pending, $db): void {
        $s = $setup();
        $lostRename($s);
        $elsewhereAtGoogle($s, 'smpl3weekly_20270308T180000Z', ['description' => 'Sample remote note']);
        $out = $recover($s, 'finish');
        $here = $rowAt($s, '2027-03-08 18:00:00');
        checkEq('a note added elsewhere to the ordinary 8 March: kept at Google with the new title, and here; no difference named; no pending row',
            [true, [], ['Sample pending rename', 'confirmed', 'Sample remote note'], ['Sample pending rename', 'Sample remote note'], []],
            [$out['ok'], $out['differences'], array_slice($day($s, '20270308T180000Z') ?? [], 0, 3), [$here['title'] ?? null, $here['description'] ?? null], $pending($s)]);

        $s = $setup();
        $lostRename($s);
        $elsewhereAtGoogle($s, 'smpl3weekly_20270329T170000Z', ['location' => 'Sample remote room']);
        $out = $recover($s, 'finish');
        checkEq('a place changed elsewhere on the edited 29 March: kept, with the day\'s own title; the series renamed', [true, [], 'Sample weekly, special', 'Sample remote room', 'Sample pending rename'],
            [$out['ok'], $out['differences'], $day($s, '20270329T170000Z')[0] ?? null, $day($s, '20270329T170000Z')[3] ?? null, $s->fake->events['smpl3weekly']['summary']]);
    });

    scenario('google recovery sweep F3: a day deleted here while a rename was half done stays deleted through Finish', function () use ($unguarded, $setup, $lostRename, $recover, $day, $masterId, $iso, $db): void {
        $s = $setup();
        $lostRename($s);
        $unguarded($s, fn() => $s->events->deleteEvent($s->u, $masterId($s), 'this', $iso('2027-03-08 10:00')));
        $s->events->throwDeferred();
        $out = $recover($s, 'finish');
        checkEq('Finish ok; 8 March cancelled at Google and skipped here; the rename landed', [true, 'cancelled', true, 'Sample pending rename'],
            [$out['ok'], $day($s, '20270308T180000Z')[1] ?? null, in_array('2027-03-08 18:00:00', Recurrence::decodeExdates($db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$masterId($s)])), true),
                $s->fake->events['smpl3weekly']['summary']]);
    });

    scenario('google recovery sweep F4: a split whose insert answer was lost, then any field of the new series changed elsewhere: Put back cancels nothing', function () use ($setup, $masterId, $refused, $iso): void {
        foreach (['colorId' => '5', 'attendees' => [['email' => 'guest@example.test', 'responseStatus' => 'needsAction']], 'transparency' => 'transparent',
            'visibility' => 'private', 'reminders' => ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => 45]]], 'guestsCanModify' => true] as $field => $value) {
            $s = $setup();
            $s->t->loseFirstPost = true;
            try {
                planned($s->events, $s->u, $masterId($s), ['scope' => 'following', 'instanceStart' => $iso('2027-03-22 10:00'), 'title' => 'Sample split']);
            } catch (HttpError) {
            }
            $new = array_values(array_filter(array_keys($s->fake->events), static fn(string $k): bool => !empty($s->fake->events[$k]['recurrence']) && $k !== 'smpl3weekly'))[0];
            $s->fake->seed(array_replace($s->fake->events[$new], [$field => $value]));
            $writes = $s->t->writes;
            [$ok, $code, , $listedAfter] = $refused($s);
            checkEq("$field changed elsewhere: refused, nothing sent, the new series live with it; still in Review", [false, 'google_changed_since', $writes, 'confirmed', $value, 1],
                [$ok, $code, $s->t->writes, $s->fake->events[$new]['status'] ?? 'confirmed', $s->fake->events[$new][$field] ?? null, $listedAfter]);
        }
    });

    scenario('google recovery sweep F5: tags set here while a rename with a tag was half done are kept by Finish and named', function () use ($unguarded, $setup, $lostRename, $recover, $masterId, $db): void {
        $s = $setup();
        $lostRename($s, ['tagNames' => ['Sample tag A']]);
        $unguarded($s, fn() => planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'tagNames' => ['Sample tag B']]));
        $s->events->throwDeferred();
        $out = $recover($s, 'finish');
        $tags = array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ?', [$masterId($s)]), 'name');
        checkEq('Finish ok; the later tag kept, the change\'s tag left out and named; the rename landed', [true, ['Sample tag B'], true, 'Sample pending rename'],
            [$out['ok'], $tags, str_contains(implode(' ', $out['differences']), 'tags'), $db->scalar('SELECT title FROM events WHERE id = ?', [$masterId($s)])]);
    });

    scenario('google recovery sweep F6: the trip flag set on the series while a rename was half done reaches every day Finish or Put back brings back', function () use ($unguarded, $setup, $lostRename, $recover, $masterId, $db, $writesOf): void {
        foreach (['finish', 'putBack'] as $how) {
            $s = $setup();
            $lostRename($s);
            $s->t->calls = [];
            $unguarded($s, fn() => planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'isContainer' => true]));
            $s->events->throwDeferred();
            $flagWrites = $writesOf($s);
            $out = $recover($s, $how);
            $flags = array_map('intval', array_column($db->all('SELECT is_container FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY recurrence_instance_utc', [$masterId($s)]), 'is_container'));
            checkEq("$how: the flag edit sent Google nothing; ok; the series and each of its three edited days carry the flag", [[], true, 1, [1, 1, 1]],
                [$flagWrites, $out['ok'], (int) $db->scalar('SELECT is_container FROM events WHERE id = ?', [$masterId($s)]), $flags]);
        }
    });

    scenario('google recovery sweep F7: a record Google holds at the series\' own values is an ordinary day: a rename never writes it nor names it', function () use ($setup, $masterId, $when, $writesOf, $db): void {
        $s = $setup();
        $s->fake->seed(['id' => 'smpl3weekly_20270308T180000Z', 'iCalUID' => 'smpl3weekly@google.com', 'recurringEventId' => 'smpl3weekly', 'summary' => 'Sample weekly', 'description' => 'Sample notes',
            'location' => 'Sample room 1', 'originalStartTime' => $when('2027-03-08 10:00'), 'start' => $when('2027-03-08 10:00'), 'end' => $when('2027-03-08 11:00')]);
        $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
        $s->t->calls = [];
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'title' => 'Sample renamed']);
        $s->events->throwDeferred();
        $summary = (string) $db->scalar('SELECT summary FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$s->u]);
        checkEq('the writes are the master and the day with its own title; Activity names no difference', [['PATCH smpl3weekly', 'PATCH smpl3weekly_20270329T170000Z'], false],
            [$writesOf($s), str_contains($summary, 'Google kept')]);
    });

    scenario('google recovery sweep F8: a day with only a tag is an ordinary day to Google: a rename sends it nothing and its undo keeps the tag', function () use ($setup, $masterId, $iso, $rowAt, $writesOf, $day, $db): void {
        $s = $setup();
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'title' => 'Sample renamed']);
        $entry = lastEntry($db, $s->u);
        $s->events->patch($s->u, $masterId($s), ['scope' => 'this', 'instanceStart' => $iso('2027-03-08 10:00'), 'tagNames' => ['Sample day tag']]);
        $s->t->calls = [];
        $s->undo->undoById($s->u, $entry);
        $row = $rowAt($s, '2027-03-08 18:00:00');
        checkEq('undone: the master and the own-title day written, 8 March not; it keeps its tag and its series\' title back; Google holds no record of it',
            [['PATCH smpl3weekly', 'PATCH smpl3weekly_20270329T170000Z'], 'Sample weekly', 1, null],
            [$writesOf($s), $row['title'] ?? null, (int) $db->scalar('SELECT COUNT(*) FROM event_tags WHERE event_id = ?', [(int) ($row['id'] ?? 0)]), $day($s, '20270308T180000Z')]);

        $s = $setup();
        $s->events->patch($s->u, $masterId($s), ['scope' => 'this', 'instanceStart' => $iso('2027-03-08 10:00'), 'tagNames' => ['Sample day tag']]);
        $s->t->calls = [];
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'title' => 'Sample renamed']);
        checkEq('a rename with the tagged day there writes the master and the own-title day only', ['PATCH smpl3weekly', 'PATCH smpl3weekly_20270329T170000Z'], $writesOf($s));
    });

    scenario('google recovery sweep F9: deleting one day touches that day and the skips, so an earlier rename still undoes and the day stays deleted', function () use ($setup, $masterId, $iso, $day, $db): void {
        $s = $setup();
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'title' => 'Sample renamed']);
        $entry = lastEntry($db, $s->u);
        $s->events->deleteEvent($s->u, $masterId($s), 'this', $iso('2027-03-08 10:00'));
        $s->undo->undoById($s->u, $entry);
        checkEq('the title back at Google and here; 8 March cancelled at Google and skipped here', ['Sample weekly', 'Sample weekly', 'cancelled', true],
            [$s->fake->events['smpl3weekly']['summary'], $db->scalar('SELECT title FROM events WHERE id = ?', [$masterId($s)]), $day($s, '20270308T180000Z')[1] ?? null,
                in_array('2027-03-08 18:00:00', Recurrence::decodeExdates($db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$masterId($s)])), true)]);
    });

    scenario('google recovery sweep P3: undoing an earlier change of a series while a later one is half done names the half-done change', function () use ($setup, $masterId, $lostRename, $db): void {
        $s = $setup();
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'description' => 'Sample new notes']);
        $entry = lastEntry($db, $s->u);
        $lostRename($s);
        checkEq('refused with the real reason: the change half done at Google, and Review', 'google_pending', refusal(fn() => $s->undo->undoById($s->u, $entry)));
    });

    scenario('google A6: every write, undo and redo touching a series with changes not yet at Google is refused with google_pending naming the change, before anything is sent; a poll leaves the series\' rows for Finish', function () use ($setup, $masterId, $lostRename, $rowAt, $listed, $recover, $iso, $when, $day, $db): void {
        $rows = static fn(object $s): array => $db->all('SELECT id, title, description, location, start_utc, end_utc, exdates_json, google_etag, override_fields, attendance, deleted_at FROM events WHERE calendar_id = ? ORDER BY id', [$s->calendarId]);
        $s = $setup();
        // The undo of a change, and an earlier change, to redo and undo below.
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'location' => 'Sample room 3']);
        $s->undo->undoById($s->u, lastEntry($db, $s->u));
        $undid = lastEntry($db, $s->u);
        planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'description' => 'Sample new notes']);
        $earlier = lastEntry($db, $s->u);
        $lostRename($s);
        $pendingId = (int) ($listed($s)[0]['id'] ?? 0);
        $before = $rows($s);
        $writes = $s->t->writes;
        $id = $masterId($s);
        $dayRow = (int) ($rowAt($s, '2027-03-22 17:00:00')['id'] ?? 0);
        $writesAsked = [
            'a field of one day' => fn() => $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => $iso('2027-03-08 10:00'), 'title' => 'Sample one day']),
            'a tag on one day (held here alone)' => fn() => $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => $iso('2027-03-08 10:00'), 'tagNames' => ['Sample tag']]),
            'a tag on an edited day\'s row' => fn() => $s->events->patch($s->u, $dayRow, ['tagNames' => ['Sample tag']]),
            'use series value' => fn() => $s->events->patch($s->u, $dayRow, ['useSeries' => ['title']]),
            'the whole series' => fn() => planned($s->events, $s->u, $id, ['scope' => 'all', 'location' => 'Sample room 2']),
            'this and following' => fn() => planned($s->events, $s->u, $id, ['scope' => 'following', 'instanceStart' => $iso('2027-03-22 10:00'), 'title' => 'Sample later']),
            'delete one day' => fn() => $s->events->deleteEvent($s->u, $id, 'this', $iso('2027-03-08 10:00')),
            'delete the series' => fn() => $s->events->deleteEvent($s->u, $id, 'all', null),
            'an answer to one day' => fn() => $s->events->setAttendance($s->u, $id, 'going', 'this', $iso('2027-03-08 10:00')),
            'undo of an earlier change' => fn() => $s->undo->undoById($s->u, $earlier),
            'redo (the undo of an undo)' => fn() => $s->undo->undoById($s->u, $undid),
        ];
        foreach ($writesAsked as $what => $write) {
            $error = null;
            try {
                $write();
            } catch (HttpError $e) {
                $error = $e;
            }
            checkEq("$what: 409 google_pending naming the change and Review; nothing sent; no row here changed", [409, 'google_pending', $pendingId, true, $writes, $before],
                [$error?->status, $error?->errorCode, (int) ($error?->extra['pending'] ?? 0), str_contains((string) $error?->getMessage(), 'Review'), $s->t->writes, $rows($s)]);
        }

        // A poll while the change is not yet at Google: the series changed at Google elsewhere, and another event added there.
        ($s->fake->writerTransport())('PATCH', 'https://www.googleapis.com/calendar/v3/calendars/sample-crew%40group.calendar.google.com/events/smpl3weekly_20270308T180000Z',
            ['description' => 'Sample remote note'], ['Authorization: Bearer sample-access']);
        $s->fake->seed(['id' => 'smplpizza', 'iCalUID' => 'smplpizza@google.com', 'summary' => 'Pizza night', 'start' => $when('2027-03-10 18:00'), 'end' => $when('2027-03-10 20:00')]);
        $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
        $series = static fn(array $all): array => array_values(array_filter($all, static fn(array $r): bool => $r['title'] !== 'Pizza night'));
        checkEq('the poll stores the other event and leaves every row of the series as it was; still in Review',
            [1, $before, 1], [(int) $db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND title = ? AND deleted_at IS NULL', [$s->calendarId, 'Pizza night']), $series($rows($s)), count($listed($s))]);
        $out = $recover($s, 'finish');
        $here = $rowAt($s, '2027-03-08 18:00:00');
        checkEq('Finish rebases over what Google holds: ok; the note made elsewhere kept at Google and here with the new title; the series renamed here',
            [true, ['Sample pending rename', 'confirmed', 'Sample remote note'], ['Sample pending rename', 'Sample remote note'], 'Sample pending rename', []],
            [$out['ok'], array_slice($day($s, '20270308T180000Z') ?? [], 0, 3), [$here['title'] ?? null, $here['description'] ?? null], $db->scalar('SELECT title FROM events WHERE id = ?', [$masterId($s)]), $listed($s)]);
        checkEq('once finished, a write to the series goes through', null, refusal(fn() => $s->events->patch($s->u, $masterId($s), ['scope' => 'this', 'instanceStart' => $iso('2027-03-08 10:00'), 'tagNames' => ['Sample tag']])));
    });

    scenario('google: "use series value" on a day of a writable Google calendar goes to Google first, by its row or by the master and the day\'s key', function () use ($setup, $masterId, $rowAt, $day, $iso, $db): void {
        $s = $setup();
        $row = $rowAt($s, '2027-03-29 17:00:00');
        $s->events->patch($s->u, (int) $row['id'], ['useSeries' => ['title']]);
        checkEq('by its row: Google\'s 29 March back to the series\' title; no row here (an ordinary day again)', ['Sample weekly', null], [$day($s, '20270329T170000Z')[0] ?? null, $rowAt($s, '2027-03-29 17:00:00')]);
        $s = $setup();
        $s->events->patch($s->u, $masterId($s), ['scope' => 'this', 'instanceStart' => $iso('2027-03-22 10:00'), 'useSeries' => ['time']]);
        checkEq('by the master and the day\'s key: 22 March back at the series\' time at Google', $iso('2027-03-22 10:00'), $day($s, '20270322T170000Z')[4] ?? null);

        // Undone by the fields it changed at Google, and redone by the undo's own entry.
        $s = $setup();
        $id = (int) $rowAt($s, '2027-03-29 17:00:00')['id'];
        $tag = $s->events->patch($s->u, $id, ['tagNames' => ['Sample label']]);
        $s->events->patch($s->u, $id, ['useSeries' => ['title']]);
        $entry = lastEntry($db, $s->u);
        $kind = json_decode((string) $db->scalar('SELECT details_json FROM mutations WHERE id = ?', [$entry]), true)['google']['changed'] ?? null;
        $s->undo->undoById($s->u, $entry);
        $back = $rowAt($s, '2027-03-29 17:00:00');
        $tags = static fn(?array $r): array => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ?', [(int) ($r['id'] ?? 0)]), 'name');
        checkEq('the entry names the title it changed at Google; undone: the own title back at Google and here, the tag kept', [['summary'], 'Sample weekly, special', 'Sample weekly, special', ['Sample label']],
            [$kind, $day($s, '20270329T170000Z')[0] ?? null, $back['title'] ?? null, $tags($back)]);
        $s->undo->undoById($s->u, lastEntry($db, $s->u));
        $again = $rowAt($s, '2027-03-29 17:00:00');
        checkEq('redone: the series\' title again at Google, and the day ordinary again here (its row went, as the first time)', ['Sample weekly', null], [$day($s, '20270329T170000Z')[0] ?? null, $again]);
        unset($tag);
    });

    scenario('google recovery sweep F5, a split: the part here goes over what is there now; a later tag list is kept and named, one equal to the plan\'s is not named', function () use ($unguarded, $setup, $masterId, $recover, $iso, $db): void {
        $tagsOf = static fn(int $id): array => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [$id]), 'name');
        $newSeries = static fn(object $s): ?array => $db->one('SELECT * FROM events WHERE calendar_id = ? AND recurrence_parent_id IS NULL AND rrule IS NOT NULL AND google_event_id <> ? AND deleted_at IS NULL', [$s->calendarId, 'smpl3weekly']);
        foreach (['Sample tag B' => true, 'Sample split tag' => false] as $later => $named) {
            $s = $setup();
            $s->t->loseFirstPost = true;
            try {
                planned($s->events, $s->u, $masterId($s), ['scope' => 'following', 'instanceStart' => $iso('2027-03-22 10:00'), 'title' => 'Sample split', 'tagNames' => ['Sample split tag']]);
            } catch (HttpError) {
            }
            $unguarded($s, fn() => planned($s->events, $s->u, $masterId($s), ['scope' => 'all', 'tagNames' => [$later]]));
            $s->events->throwDeferred();
            $out = $recover($s, 'finish');
            $new = $newSeries($s);
            checkEq("a tag list set on the series meanwhile ($later): Finish ok, the new series takes it as it is now, " . ($named ? 'named' : 'not named'),
                [true, [$later], $named], [$out['ok'], $tagsOf((int) ($new['id'] ?? 0)), str_contains(implode(' ', $out['differences']), 'tags')]);
        }
    });

    scenario('google recovery sweep controls: Finish with nothing changed meanwhile lands everything; Put back of a rename with nothing changed restores it', function () use ($setup, $move, $held, $stored, $recover, $lostRename): void {
        $dry = $setup();
        $move($dry);
        $finished = $held($dry, 'smpl3weekly');
        foreach ([1, 2, 3] as $n) {
            $s = $setup();
            $s->t->loseAt = $n;
            try {
                $move($s);
            } catch (HttpError) {
            }
            $out = $recover($s, 'finish');
            checkEq("the clock change's answer $n lost, nothing changed since: Finish lands the whole change, at Google and here, naming nothing",
                [true, [], $finished, $finished], [$out['ok'], $out['differences'], $held($s, 'smpl3weekly'), $stored($s, 'smpl3weekly')]);
        }
        $s = $setup();
        $was = $held($s, 'smpl3weekly');
        $lostRename($s);
        $out = $recover($s, 'putBack');
        checkEq('a rename\'s answer lost, nothing changed since: Put back restores it', [true, $was], [$out['ok'], $held($s, 'smpl3weekly')]);
    });

    // ---- Put back judges an answered step by content (the digest), 2026-11-09 ----------

    scenario('google recovery: answered steps, then the series written at Google with nothing changed (every etag moves): Put back reverses it; a changed field refuses it', function () use ($setup, $move, $held, $stored, $pending, $recover, $refused): void {
        $dry = $setup();
        $move($dry);
        $writes = $dry->t->writes;
        for ($n = 2; $n <= $writes; $n++) {
            // Writes 1 to n-1 answered, write n refused (412): half done with answered steps only.
            $s = $setup();
            $was = $held($s, 'smpl3weekly');
            $s->t->staleAt = $n;
            try {
                $move($s);
            } catch (HttpError) {
            }
            $etag = $s->fake->events['smpl3weekly']['etag'] ?? null;
            $s->fake->seed($s->fake->events['smpl3weekly']);
            check("stale at write $n: the stand-in moved the series' etag with its content unchanged", ($s->fake->events['smpl3weekly']['etag'] ?? null) !== $etag);
            $out = $recover($s, 'putBack');
            checkEq("stale at write $n, the etag moved, the content not: Put back restores the series at Google and here; nothing left; no pending row", [true, [], $was, $was, []],
                [$out['ok'], $out['left'], $held($s, 'smpl3weekly'), $stored($s, 'smpl3weekly'), $pending($s)]);
        }
        $s = $setup();
        $s->t->staleAt = 2;
        try {
            $move($s);
        } catch (HttpError) {
        }
        $s->fake->seed(array_replace($s->fake->events['smpl3weekly'], ['location' => 'Sample room elsewhere']));
        $there = $held($s, 'smpl3weekly');
        [$ok, $code, $words, $listedAfter] = $refused($s);
        checkEq('an answered step, then the place changed at Google: Put back sends nothing, names the series, and keeps the change in Review',
            [false, 'google_changed_since', true, $there, 1], [$ok, $code, str_contains($words, "'Sample weekly' changed at Google since"), $held($s, 'smpl3weekly'), $listedAfter]);
    });

    // ---- Put back restores every row of the snapshot, a day holding only local data included ----------

    scenario('google recovery: a split or a delete from a later day whose first answer was lost, after a tag on an ordinary day past the cut: Put back brings that day back as it was, its id and tag', function () use ($setup, $masterId, $rowAt, $held, $recover, $iso, $db): void {
        $tagsOf = static fn(int $id): array => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [$id]), 'name');
        $key = '2027-04-19 17:00:00';
        $changes = [
            'a split with a new description' => fn(object $s) => planned($s->events, $s->u, $masterId($s), ['scope' => 'following', 'instanceStart' => $iso('2027-04-12 10:00'), 'description' => 'Sample notes, later']),
            'a delete from a later day' => function (object $s) use ($masterId, $iso): void {
                $plan = $s->events->previewDelete($s->u, $masterId($s), 'following', $iso('2027-04-12 10:00'));
                $s->events->deleteEvent($s->u, $masterId($s), 'following', $iso('2027-04-12 10:00'), null, !empty($plan['whole']) ? null : ['hash' => $plan['hash'], 'choices' => []]);
            },
        ];
        foreach ($changes as $what => $change) {
            $s = $setup();
            $was = $held($s, 'smpl3weekly');
            planned($s->events, $s->u, $masterId($s), ['scope' => 'this', 'instanceStart' => $iso('2027-04-19 10:00'), 'tagNames' => ['Sample tag']]);
            $s->events->throwDeferred();
            $day = $rowAt($s, $key);
            check("$what: the tag on the ordinary day makes its row", $day !== null);
            $s->t->writes = 0;
            $s->t->loseAt = 1;
            try {
                $change($s);
                $s->events->throwDeferred();
            } catch (HttpError) {
            }
            $out = $recover($s, 'putBack');
            $after = $rowAt($s, $key);
            checkEq("$what, its first answer lost: Put back restores the series at Google, and the day past the cut keeps its row, its id and its tag",
                [true, $was, (int) $day['id'], ['Sample tag']], [$out['ok'], $held($s, 'smpl3weekly'), (int) ($after['id'] ?? 0), $tagsOf((int) ($after['id'] ?? 0))]);
        }
    });
};
