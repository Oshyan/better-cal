<?php

declare(strict_types=1);

// A series plan carried to a Google calendar (#172, stage two: GoogleSeries),
// one scenario per row of the contract (docs/design/series-model.md, Google,
// as a Contract), against the probe's stand-in Google (tools/google-probe),
// which behaves as the probe of 2026-11-07 found Google behaves. Each
// scenario checks the same three things: what the stand-in holds after the
// carrying equals the plan, the rows here equal what the stand-in holds,
// and a second sync changes nothing. Then recovery: a 412 mid-way leaves a
// pending row Review lists, Finish completes it, Put back reverses it, and
// an insert whose answer was lost is retried and read back. Invented data.

use BetterCal\Domain\Events;
use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleSync;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\ReviewQueue;
use BetterCal\GoogleProbe\FakeGoogle;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

require_once dirname(__DIR__, 3) . '/tools/google-probe/lib/FakeGoogle.php';

return static function (Db $db): void {
    $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
    $tz = 'America/Los_Angeles';
    $la = new DateTimeZone($tz);
    $utc = new DateTimeZone('UTC');
    $at = static fn(string $local): DateTimeImmutable => new DateTimeImmutable($local, $la);
    $iso = static fn(string $local): string => $at($local)->format('Y-m-d\TH:i:sP');
    $key = static fn(string $local): string => Time::toDb($at($local));
    $when = static fn(string $local): array => ['dateTime' => $iso($local), 'timeZone' => $tz];

    // The stand-in's occurrences come from the product's expander: what is
    // checked here is the carrying, not the expansion (the harness checks
    // that against its own evaluator).
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

    // The series the probe used (invented): weekly Mondays 10:00 Los Angeles
    // from 1 March 2027, ten of them, with occurrence 3 moved a day later,
    // 4 at its own time, 5 with its own title and 6 cancelled.
    $seedSeries = static function (FakeGoogle $fake) use ($when): void {
        $fake->seed(['id' => 'smpl1weekly', 'iCalUID' => 'smpl1weekly@google.com', 'summary' => 'Sample weekly', 'description' => 'Sample notes', 'location' => 'Sample room 1',
            'start' => $when('2027-03-01 10:00'), 'end' => $when('2027-03-01 11:00'), 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=10']]);
        // Daylight time from 14 March 2027: 10:00 in Los Angeles is 17:00Z.
        $record = static fn(string $suffix, string $orig, array $more): array => $more + ['id' => 'smpl1weekly_' . $suffix, 'iCalUID' => 'smpl1weekly@google.com', 'recurringEventId' => 'smpl1weekly',
            'summary' => 'Sample weekly', 'description' => 'Sample notes', 'location' => 'Sample room 1', 'originalStartTime' => $when($orig)];
        $fake->seed($record('20270315T170000Z', '2027-03-15 10:00', ['start' => $when('2027-03-16 10:00'), 'end' => $when('2027-03-16 11:00')]));
        $fake->seed($record('20270322T170000Z', '2027-03-22 10:00', ['start' => $when('2027-03-22 15:00'), 'end' => $when('2027-03-22 16:00')]));
        $fake->seed($record('20270329T170000Z', '2027-03-29 10:00', ['start' => $when('2027-03-29 10:00'), 'end' => $when('2027-03-29 11:00'), 'summary' => 'Sample weekly, special']));
        $fake->seed($record('20270405T170000Z', '2027-04-05 10:00', ['start' => $when('2027-04-05 10:00'), 'end' => $when('2027-04-05 11:00'), 'status' => 'cancelled']));
    };

    $setup = static function () use ($db, $cfg, $expand, $seedSeries): object {
        $u = freshUser($db);
        $d = domains($db);
        $fake = new FakeGoogle('team@group.calendar.google.com');
        $fake->token = null;
        $fake->zone = 'America/Los_Angeles';
        $fake->expand = $expand;
        $seedSeries($fake);
        $t = new class {
            /** @var list<array{0:string,1:string,2:?array,3:?string}> method, path, body, If-Match */
            public array $calls = [];
            /** The write (1-based, PATCH, POST and DELETE counted) that answers 412 instead of reaching the stand-in. */
            public ?int $staleAt = null;
            /** The first POST reaches the stand-in and its answer is lost. */
            public bool $loseFirstPost = false;
            /** A PATCH on this id is answered as sent without reaching the stand-in (Google keeping something else). */
            public ?string $swallowPatchOf = null;
            /** Run once, right after the first PATCH reaches the stand-in (another client writing between a master write and its read-back). */
            public ?\Closure $afterPatch = null;
            public int $writes = 0;
        };
        $inner = $fake->writerTransport();
        $transport = static function (string $method, string $url, ?array $payload, array $headers = []) use ($t, $inner): array {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $ifMatch = null;
            foreach ($headers as $h) {
                if (stripos($h, 'If-Match:') === 0) {
                    $ifMatch = trim(substr($h, 9));
                }
            }
            $t->calls[] = [$method, rawurldecode(basename($path)), $payload, $ifMatch];
            if ($method !== 'GET') {
                $t->writes++;
                if ($t->staleAt === $t->writes) {
                    return ['status' => 412, 'body' => json_encode(['error' => ['code' => 412, 'message' => 'Precondition Failed']])];
                }
                if ($method === 'PATCH' && $t->swallowPatchOf !== null && rawurldecode(basename($path)) === $t->swallowPatchOf) {
                    $t->swallowPatchOf = null;
                    return ['status' => 200, 'body' => json_encode(($payload ?? []) + ['id' => rawurldecode(basename($path)), 'etag' => '"swallowed"'])];
                }
            }
            $answer = $inner($method, $url, $payload, array_merge(['Authorization: Bearer sample-access'], $headers));
            if ($method === 'PATCH' && $t->afterPatch !== null) {
                $after = $t->afterPatch;
                $t->afterPatch = null;
                $after();
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
        $events = new Events($db, new Recurrence(), $d->undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $d->undo, new BetterCal\Infra\JobQueue($db)), new BetterCal\Domain\Trips($db, $d->undo), null, $writer);
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => GoogleAuth::SCOPES]);
        $calendarId = $db->insert('calendars', ['user_id' => $u, 'name' => 'Sample team', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'team@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $calendar = $db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]);
        // The first poll brings the series here as Google holds it.
        $sync->poll($calendar);
        $t->calls = [];
        $t->writes = 0;
        return (object) ['u' => $u, 'd' => $d, 'fake' => $fake, 't' => $t, 'writer' => $writer, 'sync' => $sync, 'events' => $events, 'calendarId' => $calendarId];
    };
    $calendar = static fn(object $s): array => $db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]);
    $masterId = static fn(object $s, string $googleId = 'smpl1weekly'): int => (int) $db->scalar('SELECT id FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, $googleId]);

    // What the stand-in holds for a series: the master's rule, start and end, every live record by its
    // originalStartTime key with its title, start and end, and the cancelled ones; what the rows here hold
    // for it, in the same shape; and the calendar's rows without their timestamps (for the second sync).
    $held = static function (object $s, string $googleId) use ($tz): array {
        $m = $s->fake->events[$googleId];
        $out = ['rule' => $m['recurrence'][0] ?? null, 'start' => $m['start']['dateTime'] ?? $m['start']['date'], 'end' => $m['end']['dateTime'] ?? $m['end']['date'], 'title' => $m['summary'], 'status' => $m['status'] ?? 'confirmed', 'records' => [], 'cancelled' => []];
        foreach ($s->fake->records($googleId) as $k => $r) {
            if (($r['status'] ?? '') === 'cancelled') {
                $out['cancelled'][] = $k;
            } else {
                $out['records'][$k] = [$r['summary'], $r['start']['dateTime'] ?? $r['start']['date'], $r['end']['dateTime'] ?? $r['end']['date'], $r['status'] ?? 'confirmed'];
            }
        }
        sort($out['cancelled']);
        ksort($out['records']);
        return $out;
    };
    $stored = static function (object $s, string $googleId) use ($db, $tz): array {
        $m = $db->one('SELECT * FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, $googleId]);
        $local = static fn(string $dbUtc): string => Time::fromDb($dbUtc)->setTimezone(new DateTimeZone($tz))->format('Y-m-d\TH:i:sP');
        $suffix = static fn(string $dbUtc): string => Time::fromDb($dbUtc)->format('Ymd\THis\Z');
        $out = ['rule' => 'RRULE:' . $m['rrule'], 'start' => $local($m['start_utc']), 'end' => $local($m['end_utc']), 'title' => $m['title'], 'status' => $m['status'], 'records' => [], 'cancelled' => []];
        foreach ($db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [(int) $m['id']]) as $r) {
            $out['records'][$suffix($r['recurrence_instance_utc'])] = [$r['title'], $local($r['start_utc']), $local($r['end_utc']), $r['status']];
        }
        foreach (Recurrence::decodeExdates($m['exdates_json']) as $ex) {
            $out['cancelled'][] = $suffix($ex);
        }
        sort($out['cancelled']);
        ksort($out['records']);
        return $out;
    };
    $rows = static function (object $s) use ($db): array {
        $out = [];
        foreach ($db->all('SELECT * FROM events WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY uid, recurrence_instance_utc', [$s->calendarId]) as $r) {
            unset($r['updated_at'], $r['id'], $r['recurrence_parent_id']);
            $out[] = $r;
        }
        return $out;
    };
    $secondSync = static function (object $s) use ($calendar, $rows): array {
        $before = $rows($s);
        $s->sync->poll($calendar($s));
        return [$before, $rows($s)];
    };
    $pending = static fn(object $s): array => $db->all('SELECT status, steps_json FROM google_pending WHERE user_id = ?', [$s->u]);
    $ifMatches = static fn(object $s): array => array_map(static fn(array $c): ?string => $c[3], array_values(array_filter($s->t->calls, static fn(array $c): bool => $c[0] !== 'GET')));

    scenario('google series: a clock change for all events, over a moved day, one at its own time, one with its own title and a skipped day', function () use ($setup, $masterId, $held, $stored, $secondSync, $pending, $iso, $ifMatches): void {
        $s = $setup();
        $id = $masterId($s);
        $etag0 = $s->fake->events['smpl1weekly']['etag'];
        planned($s->events, $s->u, $id, ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'start' => $iso('2027-03-01 11:00'), 'end' => $iso('2027-03-01 12:00')]);
        $want = ['rule' => 'RRULE:FREQ=WEEKLY;COUNT=10', 'start' => $iso('2027-03-01 11:00'), 'end' => $iso('2027-03-01 12:00'), 'title' => 'Sample weekly', 'status' => 'confirmed', 'records' => [
            // The moved day keeps its date and takes the new clock; the own time stays; the own title takes the new clock.
            '20270315T180000Z' => ['Sample weekly', $iso('2027-03-16 11:00'), $iso('2027-03-16 12:00'), 'confirmed'],
            '20270322T180000Z' => ['Sample weekly', $iso('2027-03-22 15:00'), $iso('2027-03-22 16:00'), 'confirmed'],
            '20270329T180000Z' => ['Sample weekly, special', $iso('2027-03-29 11:00'), $iso('2027-03-29 12:00'), 'confirmed'],
        ], 'cancelled' => ['20270405T180000Z']];
        checkEq('the stand-in holds the plan: the master at 11:00 and every exception and skip under its new instance id', $want, $held($s, 'smpl1weekly'));
        checkEq('the rows here hold what the stand-in holds', $want, $stored($s, 'smpl1weekly'));
        checkEq('the sequence: the series read (what the master write should leave), the master with If-Match, a read-back, one PATCH per edited or skipped day, a read-back; no pending row left', [
            ['GET', 'PATCH', 'GET', 'PATCH', 'PATCH', 'PATCH', 'PATCH', 'GET'], $etag0, [],
        ], [array_column($s->t->calls, 0), $ifMatches($s)[0], $pending($s)]);
        check('every write after the first carries the etag the write before it answered with', count(array_filter($ifMatches($s), static fn(?string $m): bool => $m !== null)) === 5);
        [$before, $after] = $secondSync($s);
        checkEq('a second sync changes nothing', $before, $after);
    });

    scenario('google series: a field change for all events, over a day with its own value for it', function () use ($setup, $masterId, $held, $stored, $secondSync, $pending): void {
        $s = $setup();
        $id = $masterId($s);
        planned($s->events, $s->u, $id, ['scope' => 'all', 'title' => 'Sample weekly, renamed']);
        $h = $held($s, 'smpl1weekly');
        checkEq('the series is renamed; the day with its own title keeps it (Google had overwritten it), the others take the new one; the skip stays', [
            'Sample weekly, renamed', ['Sample weekly, renamed', 'Sample weekly, renamed', 'Sample weekly, special'], ['20270405T170000Z'],
        ], [$h['title'], array_values(array_map(static fn(array $r): string => $r[0], $h['records'])), $h['cancelled']]);
        checkEq('the rows here hold what the stand-in holds', $h, $stored($s, 'smpl1weekly'));
        checkEq('the own title\'s PATCH is the one step after the master', ['GET', 'PATCH', 'GET', 'PATCH', 'GET'], array_column($s->t->calls, 0));
        [$before, $after] = $secondSync($s);
        checkEq('a second sync changes nothing', [$before, []], [$after, $pending($s)]);
    });

    scenario('google series: a status change for all events re-cancels the skipped day Google un-cancelled', function () use ($setup, $masterId, $held, $stored, $secondSync): void {
        $s = $setup();
        $id = $masterId($s);
        $s->events->setRelationship($s->u, $id, 'maybe', 'all');
        $h = $held($s, 'smpl1weekly');
        checkEq('every row is tentative and the skipped day is a cancelled record again', ['tentative', ['tentative', 'tentative', 'tentative'], ['20270405T170000Z']],
            [$h['status'], array_values(array_map(static fn(array $r): string => $r[3], $h['records'])), $h['cancelled']]);
        checkEq('the rows here hold what the stand-in holds', $h, $stored($s, 'smpl1weekly'));
        [$before, $after] = $secondSync($s);
        checkEq('a second sync changes nothing', $before, $after);
    });

    scenario('google series: a shorter count leaves the exceptions it keeps alone and makes the day past the end an event of its own at Google', function () use ($setup, $masterId, $held, $stored, $secondSync, $db, $iso): void {
        $s = $setup();
        $id = $masterId($s);
        planned($s->events, $s->u, $id, ['scope' => 'all', 'rrule' => 'FREQ=WEEKLY;COUNT=4']);
        $h = $held($s, 'smpl1weekly');
        checkEq('the series ends after four: the moved day and the own time kept untouched, the own-title day and the skip gone', [
            'RRULE:FREQ=WEEKLY;COUNT=4', ['20270315T170000Z', '20270322T170000Z'], [],
        ], [$h['rule'], array_keys($h['records']), $h['cancelled']]);
        $single = array_values(array_filter($s->fake->events, static fn(array $e): bool => empty($e['recurringEventId']) && empty($e['recurrence']) && ($e['status'] ?? '') !== 'cancelled'));
        checkEq('the day past the end is a single event at Google under an id the app chose, with its own title at its time', [1, 'Sample weekly, special', $iso('2027-03-29 10:00'), true],
            [count($single), $single[0]['summary'] ?? null, $single[0]['start']['dateTime'] ?? null, preg_match('/^[0-9a-v]{26}$/', (string) ($single[0]['id'] ?? '')) === 1]);
        $row = $db->one('SELECT * FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, (string) ($single[0]['id'] ?? '')]);
        checkEq('its row here is a Google event of its own: its uid Google\'s, its etag stored, no series', [true, $single[0]['iCalUID'] ?? null, $single[0]['etag'] ?? null, null, 'feed'],
            [$row !== null, $row['uid'] ?? null, $row['google_etag'] ?? null, $row['recurrence_parent_id'] ?? null, $row['source'] ?? null]);
        checkEq('the rows here hold what the stand-in holds', $h, $stored($s, 'smpl1weekly'));
        [$before, $after] = $secondSync($s);
        checkEq('a second sync changes nothing', $before, $after);
    });

    scenario('google series: a weekday added brings the skipped day back at Google, and it is cancelled again', function () use ($setup, $masterId, $held, $stored, $secondSync): void {
        $s = $setup();
        $id = $masterId($s);
        planned($s->events, $s->u, $id, ['scope' => 'all', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=20']);
        $h = $held($s, 'smpl1weekly');
        checkEq('Mondays and Wednesdays: the exceptions kept with their keys, the skip a cancelled record again', [
            'RRULE:FREQ=WEEKLY;BYDAY=MO,WE;COUNT=20', ['20270315T170000Z', '20270322T170000Z', '20270329T170000Z'], ['20270405T170000Z'],
        ], [$h['rule'], array_keys($h['records']), $h['cancelled']]);
        checkEq('the one step after the master is the skip\'s', ['GET', 'PATCH', 'GET', 'PATCH', 'GET'], array_column($s->t->calls, 0));
        checkEq('the rows here hold what the stand-in holds', $h, $stored($s, 'smpl1weekly'));
        [$before, $after] = $secondSync($s);
        checkEq('a second sync changes nothing', $before, $after);
    });

    scenario('google series: a split with exceptions on both sides of the cut', function () use ($setup, $masterId, $held, $stored, $secondSync, $db, $iso, $pending): void {
        $s = $setup();
        $id = $masterId($s);
        planned($s->events, $s->u, $id, ['scope' => 'following', 'instanceStart' => $iso('2027-03-22 10:00'), 'title' => 'Sample weekly, spring']);
        $old = $held($s, 'smpl1weekly');
        checkEq('the old series ends before the cut and keeps the moved day; the exceptions after the cut are gone from it', [
            'RRULE:FREQ=WEEKLY;UNTIL=20270315T170000Z', ['20270315T170000Z'], [],
        ], [$old['rule'], array_keys($old['records']), $old['cancelled']]);
        $newIds = array_keys(array_filter($s->fake->events, static fn(array $e): bool => !empty($e['recurrence']) && $e['id'] !== 'smpl1weekly'));
        checkEq('one new series was inserted under an id the app chose', [1, true], [count($newIds), preg_match('/^[0-9a-v]{26}$/', (string) ($newIds[0] ?? '')) === 1]);
        $newId = (string) $newIds[0];
        $new = $held($s, $newId);
        checkEq('the new series starts on the day acted on with the rest of the count, renamed, its exceptions after the cut carried and its skip cancelled', [
            'RRULE:FREQ=WEEKLY;COUNT=7', $iso('2027-03-22 10:00'), 'Sample weekly, spring',
            ['20270322T170000Z' => ['Sample weekly, spring', $iso('2027-03-22 15:00'), $iso('2027-03-22 16:00'), 'confirmed'], '20270329T170000Z' => ['Sample weekly, special', $iso('2027-03-29 10:00'), $iso('2027-03-29 11:00'), 'confirmed']],
            ['20270405T170000Z'],
        ], [$new['rule'], $new['start'], $new['title'], $new['records'], $new['cancelled']]);
        checkEq('the rows here hold both series as the stand-in holds them', [$old, $new], [$stored($s, 'smpl1weekly'), $stored($s, $newId)]);
        $newRow = $db->one('SELECT * FROM events WHERE calendar_id = ? AND google_event_id = ?', [$s->calendarId, $newId]);
        checkEq('the new series\' row carries Google\'s uid and id, and the split answered with it', [$s->fake->events[$newId]['iCalUID'], (int) $newRow['id'], []],
            [$newRow['uid'], $s->events->splitEventId(), $pending($s)]);
        [$before, $after] = $secondSync($s);
        checkEq('a second sync changes nothing', $before, $after);
    });

    scenario('google series: a 412 mid-way leaves the change half done, listed in Review; Finish completes it', function () use ($setup, $masterId, $held, $stored, $secondSync, $db, $iso, $pending, $rows): void {
        $s = $setup();
        $id = $masterId($s);
        $s->t->staleAt = 3; // the master's PATCH and the first exception's go through; the second exception's answers 412
        $caught = null;
        try {
            planned($s->events, $s->u, $id, ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'start' => $iso('2027-03-01 11:00'), 'end' => $iso('2027-03-01 12:00')]);
        } catch (HttpError $e) {
            $caught = $e;
        }
        checkEq('the request fails as a conflict that names the half-done state, with the pending row', ['409 google_conflict', true, true],
            [$caught === null ? null : $caught->status . ' ' . $caught->errorCode, $caught !== null && str_contains($caught->getMessage(), 'half done at Google'), isset($caught->extra['pending'])]);
        $p = $pending($s);
        checkEq('one pending row, half done, with the two steps done recorded', [1, 'half', ['master', 'exception']],
            [count($p), $p[0]['status'] ?? null, array_column(json_decode((string) ($p[0]['steps_json'] ?? '[]'), true) ?: [], 'kind')]);
        $h = $held($s, 'smpl1weekly');
        checkEq('what Google has is what the rows here show: the master at 11:00, one exception carried, the rest dropped', [$iso('2027-03-01 11:00'), 1, []], [$h['start'], count($h['records']), $h['cancelled']]);
        checkEq('the rows here hold what the stand-in holds', $h, $stored($s, 'smpl1weekly'));
        $listed = (new ReviewQueue($db, $s->events))->googleHalfDone($s->u);
        checkEq('Review lists it as half done at Google, with the error and the steps done', [1, 'Sample weekly', 2, true],
            [count($listed), $listed[0]['title'] ?? null, $listed[0]['stepsDone'] ?? null, str_contains((string) ($listed[0]['error'] ?? ''), 'changed at Google')]);
        $s->t->calls = [];
        $out = $s->events->googleFinish($s->u, (int) $listed[0]['id']);
        $s->events->throwDeferred();
        $want = ['rule' => 'RRULE:FREQ=WEEKLY;COUNT=10', 'start' => $iso('2027-03-01 11:00'), 'end' => $iso('2027-03-01 12:00'), 'title' => 'Sample weekly', 'status' => 'confirmed', 'records' => [
            '20270315T180000Z' => ['Sample weekly', $iso('2027-03-16 11:00'), $iso('2027-03-16 12:00'), 'confirmed'],
            '20270322T180000Z' => ['Sample weekly', $iso('2027-03-22 15:00'), $iso('2027-03-22 16:00'), 'confirmed'],
            '20270329T180000Z' => ['Sample weekly, special', $iso('2027-03-29 11:00'), $iso('2027-03-29 12:00'), 'confirmed'],
        ], 'cancelled' => ['20270405T180000Z']];
        checkEq('Finish carries the rest from Google\'s state: the plan holds at Google and here, the pending row is gone', [true, $want, $want, []],
            [$out['ok'], $held($s, 'smpl1weekly'), $stored($s, 'smpl1weekly'), $pending($s)]);
        checkEq('Finish read the series afresh, sent no master PATCH again (nothing on it differed), only the steps left', ['GET', 'GET', 'PATCH', 'PATCH', 'PATCH', 'GET'], array_column($s->t->calls, 0));
        [$before, $after] = $secondSync($s);
        checkEq('a second sync changes nothing', $before, $after);
    });

    scenario('google series: a 412 mid-way, then Put back restores the series as it was', function () use ($setup, $masterId, $held, $stored, $secondSync, $db, $iso, $pending, $rows): void {
        $s = $setup();
        $id = $masterId($s);
        $was = $held($s, 'smpl1weekly');
        $wasRows = $rows($s);
        $s->t->staleAt = 3;
        try {
            planned($s->events, $s->u, $id, ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'start' => $iso('2027-03-01 11:00'), 'end' => $iso('2027-03-01 12:00')]);
        } catch (HttpError) {
        }
        $listed = (new ReviewQueue($db, $s->events))->googleHalfDone($s->u);
        $s->t->calls = [];
        $out = $s->events->googlePutBack($s->u, (int) $listed[0]['id']);
        $s->events->throwDeferred();
        checkEq('Put back: the series at Google is as it was, every exception and the skip back under its old instance id, nothing left behind', [true, [], $was, []],
            [$out['ok'], $out['left'], $held($s, 'smpl1weekly'), $pending($s)]);
        checkEq('the rows here hold what the stand-in holds', $was, $stored($s, 'smpl1weekly'));
        // The own lists are the plan's befores: the moved day owns its date, the one at its own time its time, the titled one its title.
        $own = array_column($db->all('SELECT recurrence_instance_utc, override_fields FROM events WHERE recurrence_parent_id = ? ORDER BY recurrence_instance_utc', [$masterId($s)]), 'override_fields', 'recurrence_instance_utc');
        checkEq('every exception keeps the own list it had', ['["date"]', '["time"]', '["title"]'], array_values($own));
        [$before, $after] = $secondSync($s);
        checkEq('a second sync changes nothing', $before, $after);
    });

    scenario('google series: Put back puts nothing back when a series changed at Google since, and names it', function () use ($setup, $masterId, $held, $db, $iso, $pending): void {
        $s = $setup();
        $id = $masterId($s);
        $s->t->staleAt = 3;
        try {
            planned($s->events, $s->u, $id, ['scope' => 'all', 'instanceStart' => $iso('2027-03-01 10:00'), 'start' => $iso('2027-03-01 11:00'), 'end' => $iso('2027-03-01 12:00')]);
        } catch (HttpError) {
        }
        // Someone renames the series at Google meanwhile.
        $s->fake->events['smpl1weekly']['summary'] = 'Sample weekly, theirs';
        $s->fake->events['smpl1weekly']['etag'] = '"elsewhere"';
        $listed = (new ReviewQueue($db, $s->events))->googleHalfDone($s->u);
        $out = $s->events->googlePutBack($s->u, (int) $listed[0]['id']);
        $error = null;
        try {
            $s->events->throwDeferred();
        } catch (HttpError $e) {
            $error = $e;
        }
        checkEq('nothing is put back: the answer names the series and Finish; Google keeps the other edit; the change stays in Review', [false, 'google_changed_since', true, true, 'Sample weekly, theirs', $iso('2027-03-01 11:00'), 1],
            [$out['ok'], $error?->errorCode, str_contains((string) $error?->getMessage(), "changed at Google since"), str_contains((string) $error?->getMessage(), 'Finish'),
                $s->fake->events['smpl1weekly']['summary'], $held($s, 'smpl1weekly')['start'], count((new ReviewQueue($db, $s->events))->googleHalfDone($s->u))]);
    });

    scenario('google series: an insert whose answer was lost is retried, found by its id and carried on', function () use ($setup, $masterId, $held, $stored, $secondSync, $db, $iso, $pending): void {
        $s = $setup();
        $id = $masterId($s);
        $s->t->loseFirstPost = true;
        $caught = null;
        try {
            planned($s->events, $s->u, $id, ['scope' => 'following', 'instanceStart' => $iso('2027-03-22 10:00'), 'title' => 'Sample weekly, spring']);
        } catch (HttpError $e) {
            $caught = $e;
        }
        $newIds = array_keys(array_filter($s->fake->events, static fn(array $e): bool => !empty($e['recurrence']) && $e['id'] !== 'smpl1weekly'));
        checkEq('the request failed as unreachable; the new series exists at Google under the chosen id; the change is half done', ['502 google_write_failed', 1, 'half'],
            [$caught === null ? null : $caught->status . ' ' . $caught->errorCode, count($newIds), $pending($s)[0]['status'] ?? null]);
        $listed = (new ReviewQueue($db, $s->events))->googleHalfDone($s->u);
        $s->t->calls = [];
        $out = $s->events->googleFinish($s->u, (int) $listed[0]['id']);
        $s->events->throwDeferred();
        $posts = array_values(array_filter($s->t->calls, static fn(array $c): bool => $c[0] === 'POST'));
        $gets = array_values(array_filter($s->t->calls, static fn(array $c): bool => $c[0] === 'GET' && $c[1] === $newIds[0]));
        checkEq('Finish inserted again with the same id, was told it exists (409), read it back and carried on (read by its id twice: Finish finds it to rebase on it, the insert after the 409)', [true, 1, $newIds[0], 2],
            [$out['ok'], count($posts), $posts[0][2]['id'] ?? null, count($gets)]);
        $new = $held($s, (string) $newIds[0]);
        checkEq('the new series holds the plan: its exceptions after the cut and its skip', [['20270322T170000Z', '20270329T170000Z'], ['20270405T170000Z'], 'Sample weekly, spring', []],
            [array_keys($new['records']), $new['cancelled'], $new['title'], $pending($s)]);
        checkEq('the rows here hold both series as the stand-in holds them', [$held($s, 'smpl1weekly'), $new], [$stored($s, 'smpl1weekly'), $stored($s, (string) $newIds[0])]);
        [$before, $after] = $secondSync($s);
        checkEq('a second sync changes nothing', $before, $after);
    });

    scenario('google series: a difference Google keeps goes into the Activity entry and a Review notice', function () use ($setup, $masterId, $db, $iso): void {
        $s = $setup();
        $id = $masterId($s);
        // The own title's PATCH is answered as sent without reaching the stand-in: Google "keeps" the series' new title on that day.
        $s->t->swallowPatchOf = 'smpl1weekly_20270329T170000Z';
        planned($s->events, $s->u, $id, ['scope' => 'all', 'title' => 'Sample weekly, renamed']);
        $entry = $db->one('SELECT summary FROM mutations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$s->u]);
        $notice = $db->one("SELECT title, payload_json FROM review_items WHERE user_id = ? AND kind = 'google_differs'", [$s->u]);
        $lines = json_decode((string) ($notice['payload_json'] ?? '{}'), true)['lines'] ?? [];
        $day = BetterCal\Domain\Series\Words::date(BetterCal\Domain\Series\Cal::dn(2027, 3, 29), (int) gmdate('Y'));
        checkEq('the Activity entry and the notice say what Google kept, in words naming the day', [true, 'Sample weekly', ["Google kept 'Sample weekly, renamed' on $day; the plan had 'Sample weekly, special'."]],
            [str_contains((string) ($entry['summary'] ?? ''), 'Google kept something else'), $notice['title'] ?? null, $lines]);
        // Google holds the day at the series' own values, so here it is an ordinary occurrence again: no row, not the plan's.
        checkEq('the rows here show what Google stores, not the plan: the day has no row of its own', null, $db->scalar('SELECT title FROM events WHERE calendar_id = ? AND google_event_id = ?', [$s->calendarId, 'smpl1weekly_20270329T170000Z']));
    });

    scenario('google series: a day holding only a tag, renamed at Google during a series rename, keeps the tag as its own, and a later clock change at Google keeps the day and its tag', function () use ($setup, $masterId, $db, $iso, $calendar): void {
        $s = $setup();
        $id = $masterId($s);
        $s->events->patch($s->u, $id, ['scope' => 'this', 'instanceStart' => $iso('2027-03-08 10:00'), 'tagNames' => ['sample-tag']]);
        $s->events->throwDeferred();
        $dayId = 'smpl1weekly_20270308T180000Z';
        $row = static fn(): ?array => $db->one('SELECT * FROM events WHERE calendar_id = ? AND google_event_id = ? AND deleted_at IS NULL', [$s->calendarId, $dayId]);
        $tags = static fn(int $eventId): array => array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [$eventId]), 'name');
        $own = static function (?array $r): ?array {
            $list = $r === null ? null : json_decode((string) $r['override_fields'], true);
            if (is_array($list)) {
                sort($list);
            }
            return $list;
        };
        $other = $s->fake->writerTransport();
        $url = static fn(string $gid): string => 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode('team@group.calendar.google.com') . '/events/' . rawurlencode($gid);
        checkEq('the day has a row of its own holding only its tag', [['tags'], ['sample-tag']], [$own($row()), $tags((int) $row()['id'])]);
        // Another client renames that day between the series' master write and its read-back.
        $s->t->afterPatch = static fn() => $other('PATCH', $url($dayId), ['summary' => 'Sample remote day'], ['Authorization: Bearer sample-access']);
        planned($s->events, $s->u, $id, ['scope' => 'all', 'title' => 'Sample weekly, renamed']);
        $r = $row();
        checkEq('right after the read-back: the remote title is the day\'s own, and its tag is still its own, with the tag on it', [['tags', 'title'], 'Sample remote day', ['sample-tag']],
            [$own($r), $r['title'] ?? null, $r !== null ? $tags((int) $r['id']) : null]);
        // A clock change at Google drops the day's record; a delta poll reads it.
        $m = $s->fake->events['smpl1weekly'];
        $shift = static fn(array $w): array => ['dateTime' => (new DateTimeImmutable($w['dateTime']))->modify('+1 hour')->format('Y-m-d\\TH:i:sP'), 'timeZone' => $w['timeZone']];
        $other('PATCH', $url('smpl1weekly'), ['start' => $shift($m['start']), 'end' => $shift($m['end'])], ['Authorization: Bearer sample-access']);
        $s->sync->poll($calendar($s));
        $kept = $db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$id]);
        $tagged = array_values(array_filter($kept, static fn(array $k): bool => $tags((int) $k['id']) === ['sample-tag']));
        checkEq('after the delta poll the day keeps its row and its tag, now at the new clock', [1, ['tags']], [count($tagged), $own($tagged[0] ?? null)]);
    });

    scenario('google series: an insert whose answer was lost, the event then cancelled at Google: Finish makes it live again by the one conditional write, its body only what differs from the read, If-Match that read\'s etag', function () use ($setup, $masterId, $held, $stored, $secondSync, $db, $iso, $pending): void {
        $s = $setup();
        $id = $masterId($s);
        $s->t->loseFirstPost = true;
        try {
            planned($s->events, $s->u, $id, ['scope' => 'following', 'instanceStart' => $iso('2027-03-22 10:00'), 'title' => 'Sample weekly, spring']);
        } catch (HttpError) {
        }
        $newIds = array_keys(array_filter($s->fake->events, static fn(array $e): bool => !empty($e['recurrence']) && $e['id'] !== 'smpl1weekly'));
        $newId = (string) ($newIds[0] ?? '');
        // Another client cancels the new series at Google.
        ($s->fake->writerTransport())('PATCH', 'https://www.googleapis.com/calendar/v3/calendars/team%40group.calendar.google.com/events/' . $newId, ['status' => 'cancelled'], ['Authorization: Bearer sample-access']);
        checkEq('the new series is cancelled at Google, the change half done', ['cancelled', 'half'], [$s->fake->events[$newId]['status'] ?? null, $pending($s)[0]['status'] ?? null]);
        $listed = (new ReviewQueue($db, $s->events))->googleHalfDone($s->u);
        $s->t->calls = [];
        $out = $s->events->googleFinish($s->u, (int) $listed[0]['id']);
        $s->events->throwDeferred();
        $calls = $s->t->calls;
        $at = null;
        foreach ($calls as $i => $c) {
            if ($c[0] === 'PATCH' && $c[1] === $newId) {
                $at = $i;
                break;
            }
        }
        $patch = $at === null ? null : $calls[$at];
        $read = null;
        for ($i = ($at ?? 0) - 1; $i >= 0; $i--) {
            if ($calls[$i][0] === 'GET' && $calls[$i][1] === $newId) {
                $read = $i;
                break;
            }
        }
        $keys = array_keys($patch[2] ?? []);
        sort($keys);
        checkEq('Finish ok; the revive is a PATCH whose keys are all on the allowed list, with status confirmed, and none the read already holds (the title, the notes)', [true, true, [], 'confirmed', false, false],
            [$out['ok'], $patch !== null, array_values(array_diff($keys, BetterCal\Domain\GoogleWriter::allowedKeys())), $patch[2]['status'] ?? null,
                array_key_exists('summary', $patch[2] ?? []), array_key_exists('description', $patch[2] ?? [])]);
        checkEq('its If-Match is an etag Google gave for the event (a read compared with the rows here first)', true, $patch !== null && $read !== null && is_string($patch[3]) && $patch[3] !== '');
        $new = $held($s, $newId);
        checkEq('the new series is live with the plan\'s title and its days; the rows here hold it as Google does; no pending row', ['confirmed', 'Sample weekly, spring', true, []],
            [$new['status'], $new['title'], $stored($s, $newId) === $new, $pending($s)]);
        [$before, $after] = $secondSync($s);
        checkEq('a second sync changes nothing', $before, $after);
    });

    // A day's row the sync makes starts with its series' tags and people, as a writer's does (2026-11-09).
    scenario('google series: a tagged series, a poll bringing a record of its own for one day: the day\'s row starts with the series\' tag, not its own; the record set back to the series\' values, the row goes', function () use ($setup, $masterId, $db): void {
        $s = $setup();
        $id = $masterId($s);
        $s->events->patch($s->u, $id, ['scope' => 'all', 'tagNames' => ['Sample series tag']]);
        $s->events->throwDeferred();
        $atGoogle = static fn(array $body) => ($s->fake->writerTransport())('PATCH', 'https://www.googleapis.com/calendar/v3/calendars/team%40group.calendar.google.com/events/smpl1weekly_20270308T180000Z',
            $body, ['Authorization: Bearer sample-access']);
        $poll = static fn() => $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$s->calendarId]));
        $dayRow = static fn(): ?array => $db->one('SELECT * FROM events WHERE calendar_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$s->calendarId, '2027-03-08 18:00:00']);
        $atGoogle(['summary' => 'Sample weekly, renamed at Google']);
        $poll();
        $row = $dayRow();
        checkEq('the day\'s row: Google\'s title, its own list the title alone, the series\' tag as a copy', ['Sample weekly, renamed at Google', '["title"]', ['Sample series tag']],
            [$row['title'] ?? null, $row['override_fields'] ?? null, array_column($db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ?', [(int) ($row['id'] ?? 0)]), 'name')]);
        $atGoogle(['summary' => 'Sample weekly']);
        $poll();
        checkEq('the record back at the series\' values: the copy holds nothing here alone, so the day is an ordinary occurrence again (no row)', null, $dayRow());
    });
};
