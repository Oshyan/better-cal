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
                $out = $recover($s, $how);
                $after = $rowAt($s, $key);
                checkEq("$how, failure at write $n: the day is its own row again with its id, tag, person, reminders and trip link; Google and the rows agree; no pending row",
                    [true, $day, $before, true, $how === 'finish' ? $finished : $was, []],
                    [$out['ok'], (int) ($after['id'] ?? 0), $parts((int) ($after['id'] ?? 0)), $held($s, 'smpl3weekly') === $stored($s, 'smpl3weekly'), $held($s, 'smpl3weekly'), $pending($s)]);
            }
        }
    });

    // ---- R2: a write whose answer was lost is put back only while nothing changed since

    scenario('google recovery: a split whose insert answer was lost, the new series renamed elsewhere since, Put back leaves it and says so; untouched, it is cancelled', function () use ($setup, $masterId, $held, $pending, $recover, $iso): void {
        foreach (['renamed elsewhere' => true, 'untouched' => false] as $what => $edited) {
            $s = $setup();
            $was = $held($s, 'smpl3weekly');
            $s->t->loseFirstPost = true;
            try {
                planned($s->events, $s->u, $masterId($s), ['scope' => 'following', 'instanceStart' => $iso('2027-03-22 10:00'), 'title' => 'Sample split']);
            } catch (HttpError) {
            }
            $new = array_values(array_filter(array_keys($s->fake->events), static fn(string $k): bool => !empty($s->fake->events[$k]['recurrence']) && $k !== 'smpl3weekly'))[0];
            if ($edited) {
                $s->fake->seed(array_replace($s->fake->events[$new], ['summary' => 'Sample renamed elsewhere']));
            }
            $out = $recover($s, 'putBack');
            checkEq("$what: the new series " . ($edited ? 'stands as it was edited, named in left' : 'is cancelled, nothing left') . '; the old series is put back; no pending row',
                [true, $edited ? 1 : 0, $edited ? 'confirmed' : 'cancelled', $edited ? 'Sample renamed elsewhere' : 'Sample split', $was, []],
                [$out['ok'], count($out['left']), $s->fake->events[$new]['status'] ?? 'confirmed', $s->fake->events[$new]['summary'], $held($s, 'smpl3weekly'), $pending($s)]);
            if ($edited) {
                check("$what: left names the series and why", str_contains($out['left'][0] ?? '', "'Sample split' changed at Google since"), (string) json_encode($out['left']));
            }
        }
    });

    scenario('google recovery: a PATCH whose answer was lost, then another part of the series changed elsewhere: Put back leaves the series as Google has it and says so', function () use ($setup, $masterId, $move, $held, $pending, $recover, $when, $db): void {
        // An edited day's PATCH lost, then the series' place changed elsewhere.
        $s = $setup();
        $s->t->loseAt = 2;
        try {
            $move($s);
        } catch (HttpError) {
        }
        $s->fake->seed(array_replace($s->fake->events['smpl3weekly'], ['location' => 'Sample room elsewhere']));
        $there = $held($s, 'smpl3weekly');
        $out = $recover($s, 'putBack');
        checkEq("an edited day's answer lost, the place changed since: nothing sent back, the series named in left, Google keeps the other edit; no pending row",
            [true, 1, $there, 'Sample room elsewhere', []],
            [$out['ok'], count($out['left']), $held($s, 'smpl3weekly'), $s->fake->events['smpl3weekly']['location'] ?? null, $pending($s)]);

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
        $out = $recover($s, 'putBack');
        checkEq("the master's answer lost, a day edited since: the series is left as Google has it and named; the rename stands at Google and here; no pending row",
            [true, 1, $there, 'Sample weekly, renamed', []],
            [$out['ok'], count($out['left']), $held($s, 'smpl3weekly'), $db->scalar('SELECT title FROM events WHERE id = ?', [$masterId($s)]), $pending($s)]);

        // The same rename, its answer lost, nothing changed since: put back.
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
};
