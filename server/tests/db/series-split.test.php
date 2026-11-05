<?php

declare(strict_types=1);

// "This and following", delete following and an answer from a day on, on
// the series model (1.5.1, docs/design/series-model.md, Every Kind of
// Edit): a split is a bound change on the old series plus the part from
// the day acted on planned as a whole series. Each goes through the plan
// API (preview, then commit with the hash and a choice for every group),
// a plan-less or stale commit is a 409 with the plan, and Undo puts every
// row back. Every expectation is hand-listed.

use BetterCal\Domain\Settings;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $u = freshUser($db);
    $d = domains($db);
    $home = (int) Settings::defaultCalendarId($db, $u);

    $rows = static function (int ...$ids) use ($db): array {
        $out = [];
        foreach ($ids as $id) {
            foreach ($db->all('SELECT * FROM events WHERE id = ? OR recurrence_parent_id = ? ORDER BY id', [$id, $id]) as $row) {
                unset($row['updated_at']);
                $out[] = $row;
            }
        }
        return $out;
    };
    $error = static function (callable $fn): array {
        try {
            $fn();
            return [null, null];
        } catch (HttpError $e) {
            return [$e->status . ' ' . $e->errorCode, $e->extra['plan'] ?? null];
        }
    };
    $series = static function (string $title, string $rrule, string $day = '2027-01-05', array $more = []) use ($d, $u, $home): int {
        return (int) $d->events->create($u, eventAt($home, $title, $day, ['rrule' => $rrule] + $more))['eventId'];
    };
    // The plan's groups with their preselected options, or $pick's.
    $choices = static function (array $plan, array $pick = []): array {
        $out = [];
        foreach ($plan['groups'] as $g) {
            $out[$g['id']] = $pick[$g['id']] ?? $g['selected'];
        }
        return $out;
    };
    $newest = static fn(int $old): int => (int) $db->scalar('SELECT MAX(id) FROM events WHERE user_id = ? AND recurrence_parent_id IS NULL AND rrule IS NOT NULL AND id <> ?', [$u, $old]);
    $col = static fn(int $id, string $c) => $db->scalar("SELECT $c FROM events WHERE id = ?", [$id]);

    scenario('split: a rename from a day on, previewed and committed with its choice, then undone', function () use ($d, $u, $db, $rows, $error, $series, $choices, $newest, $col): void {
        $choir = $series('Choir', 'FREQ=WEEKLY;BYDAY=TU;COUNT=10');
        $d->events->patch($u, $choir, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'title' => 'Choir, early']);
        $d->events->patch($u, $choir, ['scope' => 'this', 'instanceStart' => '2027-02-02T18:00:00+00:00', 'title' => 'Concert']);
        $d->events->deleteEvent($u, $choir, 'this', '2027-01-26T18:00:00+00:00');
        $edit = ['scope' => 'following', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'title' => 'Choir, spring term'];
        $before = $rows($choir);
        $entries = (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$u]);

        checkEq('a split sent without its plan is 409 series_plan_required with the plan, and writes nothing', ['409 series_plan_required', 'following', $before],
            [$error(fn() => $d->events->patch($u, $choir, $edit))[0], $error(fn() => $d->events->patch($u, $choir, $edit))[1]['scope'] ?? null, $rows($choir)]);
        $plan = $d->events->previewPatch($u, $choir, $edit + ['preview' => true]);
        checkEq('the preview writes nothing and journals nothing', [$before, $entries], [$rows($choir), (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$u])]);
        checkEq('the preview has both parts: the old one ends on 12 Jan after 2 events, the new one has the 8 left from 19 Jan, and the own-title choice for Concert', [
            'following', '2027-01-19T18:00:00Z', ['kind' => 'until', 'until' => '20270112T180000Z', 'events' => 2, 'last' => '2027-01-12'], 1,
            ['kind' => 'count', 'count' => 8], 1, 1, ['ownTitle'], 'FREQ=WEEKLY;BYDAY=TU;COUNT=8',
        ], [
            $plan['scope'], $plan['split']['at'], $plan['split']['before']['bound'], $plan['split']['before']['edited'],
            $plan['split']['after']['bound'], $plan['split']['after']['edited'], $plan['split']['after']['skipped'],
            array_column($plan['groups'], 'id'), $plan['rule']['after'],
        ]);
        checkEq('a commit with a stale hash is 409 series_plan_changed and writes nothing', ['409 series_plan_changed', $before],
            [$error(fn() => $d->events->patch($u, $choir, $edit + ['plan' => ['hash' => 'stale', 'choices' => $choices($plan)]]))[0], $rows($choir)]);
        checkEq('a commit missing a group is 409 series_plan_required', '409 series_plan_required',
            $error(fn() => $d->events->patch($u, $choir, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => []]]))[0]);

        $d->events->patch($u, $choir, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices($plan, ['ownTitle' => 'keep'])]]);
        $new = $newest($choir);
        checkEq('the old part ends on 12 Jan with its earlier edited day and no skip; the new part is the rest, renamed, with the skip and Concert', [
            'FREQ=WEEKLY;BYDAY=TU;UNTIL=20270112T180000Z', null, 'Choir', 'Choir, early',
            'FREQ=WEEKLY;BYDAY=TU;COUNT=8', '2027-01-19 18:00:00', '["2027-01-26 18:00:00"]', 'Choir, spring term',
            [['Concert', '2027-02-02 18:00:00']], $d->events->splitEventId(),
        ], [
            $col($choir, 'rrule'), $col($choir, 'exdates_json'), $col($choir, 'title'),
            $db->scalar('SELECT title FROM events WHERE recurrence_parent_id = ?', [$choir]),
            $col($new, 'rrule'), $col($new, 'start_utc'), $col($new, 'exdates_json'), $col($new, 'title'),
            array_map(static fn(array $r): array => [$r['title'], $r['recurrence_instance_utc']], $db->all('SELECT title, recurrence_instance_utc FROM events WHERE recurrence_parent_id = ?', [$new])),
            $new,
        ]);
        $d->undo->undoLatest($u);
        checkEq('one Undo puts every row back as it was and removes the new part', [$before, 0],
            [$rows($choir), (int) $db->scalar('SELECT COUNT(*) FROM events WHERE id = ?', [$new])]);
    });

    scenario('split: a drag from a day on moves the part as a whole series', function () use ($d, $u, $series, $choices, $newest, $col): void {
        $walk = $series('Walk', 'FREQ=WEEKLY;BYDAY=TU');
        $edit = ['scope' => 'following', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'start' => '2027-01-20T18:00:00+00:00', 'end' => '2027-01-20T19:00:00+00:00'];
        $plan = $d->events->previewPatch($u, $walk, $edit + ['preview' => true]);
        $d->events->patch($u, $walk, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices($plan)]]);
        $new = $newest($walk);
        checkEq('Tuesdays to 12 Jan, then Wednesdays from 20 Jan', ['FREQ=WEEKLY;BYDAY=TU;UNTIL=20270112T180000Z', 'FREQ=WEEKLY;BYDAY=WE', '2027-01-20 18:00:00'],
            [$col($walk, 'rrule'), $col($new, 'rrule'), $col($new, 'start_utc')]);
    });

    scenario('split: a rule naming its hour is written in step with the new part\'s start', function () use ($d, $u, $series, $choices, $newest, $col): void {
        $class = $series('Sample practice', 'FREQ=WEEKLY;BYDAY=TU;BYHOUR=18;BYMINUTE=0');
        $edit = ['scope' => 'following', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'start' => '2027-01-19T14:30:00+00:00', 'end' => '2027-01-19T15:30:00+00:00'];
        $plan = $d->events->previewPatch($u, $class, $edit + ['preview' => true]);
        $d->events->patch($u, $class, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices($plan)]]);
        $new = $newest($class);
        checkEq('the new part names 14:30 in its rule and starts at 14:30', ['FREQ=WEEKLY;BYDAY=TU;BYHOUR=14;BYMINUTE=30', '2027-01-19 14:30:00'],
            [$col($new, 'rrule'), $col($new, 'start_utc')]);
    });

    scenario('split: a part made all-day drops its clock parts, and one several times a day is refused', function () use ($d, $u, $db, $rows, $error, $series, $choices, $newest, $col): void {
        $daily = $series('Sample stretch', 'FREQ=DAILY;BYHOUR=18;BYMINUTE=0');
        $edit = ['scope' => 'following', 'instanceStart' => '2027-01-08T18:00:00+00:00', 'allDay' => true, 'start' => '2027-01-08', 'end' => '2027-01-09'];
        $plan = $d->events->previewPatch($u, $daily, $edit + ['preview' => true]);
        $d->events->patch($u, $daily, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices($plan)]]);
        $new = $newest($daily);
        checkEq('the all-day part repeats daily with no hour or minute, from 8 Jan', ['FREQ=DAILY', 1, '2027-01-08 00:00:00'],
            [$col($new, 'rrule'), (int) $col($new, 'all_day'), $col($new, 'start_utc')]);

        $often = $series('Sample reading', 'FREQ=MINUTELY;INTERVAL=30;COUNT=10');
        $before = $rows($often);
        $edit = ['scope' => 'following', 'instanceStart' => '2027-01-05T19:00:00+00:00', 'allDay' => true, 'start' => '2027-01-05', 'end' => '2027-01-06'];
        [$code, $refused] = $error(fn() => $d->events->previewPatch($u, $often, $edit + ['preview' => true]));
        $plan = $refused ?? $d->events->previewPatch($u, $often, $edit + ['preview' => true]);
        checkEq('a part several times a day is refused being made all-day, and nothing is written', ['severalAllDay', '422 series_refused', $before], [
            $plan['refusals'][0]['code'] ?? null,
            $error(fn() => $d->events->patch($u, $often, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => []]]))[0],
            $rows($often),
        ]);
        unset($code);
    });

    scenario('split: delete following, previewed, committed and undone', function () use ($d, $u, $db, $rows, $error, $series, $col): void {
        $swim = $series('Swim', 'FREQ=WEEKLY;BYDAY=TU;COUNT=6');
        $d->events->patch($u, $swim, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'title' => 'Swim, early']);
        $d->events->patch($u, $swim, ['scope' => 'this', 'instanceStart' => '2027-01-26T18:00:00+00:00', 'title' => 'Swim test']);
        $d->events->deleteEvent($u, $swim, 'this', '2027-02-02T18:00:00+00:00');
        $before = $rows($swim);
        checkEq('delete following without its plan is 409 series_plan_required', '409 series_plan_required',
            $error(fn() => $d->events->deleteEvent($u, $swim, 'following', '2027-01-19T18:00:00+00:00'))[0]);
        $plan = $d->events->previewDelete($u, $swim, 'following', '2027-01-19T18:00:00+00:00');
        checkEq('its plan ends the series on 12 Jan and names Swim test as deleted and the skip as dropped', [
            'delete', ['kind' => 'until', 'until' => '20270112T180000Z', 'events' => 2, 'last' => '2027-01-12'], null,
            [['2027-01-26T18:00:00Z', 'edited', 'deleted'], ['2027-02-02T18:00:00Z', 'skipped', 'dropped']], $before,
        ], [
            $plan['action'], $plan['split']['before']['bound'], $plan['split']['after'],
            array_map(static fn(array $x): array => [$x['key'], $x['kind'], $x['outcome']], $plan['days']), $rows($swim),
        ]);
        $d->events->deleteEvent($u, $swim, 'following', '2027-01-19T18:00:00+00:00', null, ['hash' => $plan['hash'], 'choices' => []]);
        checkEq('the series ends on 12 Jan, keeps Swim, early and drops the rest', ['FREQ=WEEKLY;BYDAY=TU;UNTIL=20270112T180000Z', null, ['Swim, early']], [
            $col($swim, 'rrule'), $col($swim, 'exdates_json'),
            array_column($db->all('SELECT title FROM events WHERE recurrence_parent_id = ?', [$swim]), 'title'),
        ]);
        $d->undo->undoLatest($u);
        checkEq('one Undo puts the series back whole', $before, $rows($swim));
    });

    scenario('split: an answer from a day on is the split with no fields, then the answer, in one entry', function () use ($d, $u, $db, $rows, $error, $series, $newest, $col): void {
        $club = $series('Book club', 'FREQ=WEEKLY;BYDAY=TU');
        $d->events->patch($u, $club, ['scope' => 'this', 'instanceStart' => '2027-01-26T18:00:00+00:00', 'location' => 'Sample Hall']);
        $before = $rows($club);
        checkEq('an answer from a day on without its plan is 409 series_plan_required', '409 series_plan_required',
            $error(fn() => $d->events->setAttendance($u, $club, 'going', 'following', '2027-01-19T18:00:00+00:00'))[0]);
        $plan = $d->events->previewAttendance($u, $club, 'going', 'following', '2027-01-19T18:00:00+00:00');
        $entries = (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$u]);
        $d->events->setAttendance($u, $club, 'going', 'following', '2027-01-19T18:00:00+00:00', ['hash' => $plan['hash'], 'choices' => []]);
        $new = $newest($club);
        checkEq('the old part keeps its answer; the new one and its edited day answer going; one journal entry', [
            'answer', 'none', 'going', ['going'], 'FREQ=WEEKLY;BYDAY=TU', '2027-01-19 18:00:00', $entries + 1,
        ], [
            $plan['action'], $col($club, 'attendance'), $col($new, 'attendance'),
            array_column($db->all('SELECT attendance FROM events WHERE recurrence_parent_id = ?', [$new]), 'attendance'),
            $col($new, 'rrule'), $col($new, 'start_utc'), (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$u]),
        ]);
        $d->undo->undoLatest($u);
        checkEq('one Undo makes the series whole again', $before, $rows($club));
    });

    scenario('split: a trip flag from a day on reaches the part\'s edited days, so a day that is a stop refuses it', function () use ($d, $u, $db, $home, $rows, $error, $series, $choices): void {
        $club = $series('Sample club', 'FREQ=WEEKLY;BYDAY=TU');
        $d->events->patch($u, $club, ['scope' => 'this', 'instanceStart' => '2027-01-26T18:00:00+00:00', 'title' => 'Sample club, away day']);
        $day = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$club]);
        $trip = (int) $d->events->create($u, eventAt($home, 'Sample trip', '2027-01-25'))['eventId'];
        $d->events->patch($u, $trip, ['isContainer' => true]);
        (new BetterCal\Domain\Trips($db, $d->undo))->attach($u, $trip, $day);
        $edit = ['scope' => 'following', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'isContainer' => true];
        $before = $rows($club);
        $plan = $d->events->previewPatch($u, $club, $edit + ['preview' => true]);
        checkEq('the part from 19 Jan made a trip, its edited day a stop of another trip: 400 trip_nested, nothing written', ['400 trip_nested', $before],
            [$error(fn() => $d->events->patch($u, $club, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices($plan)]]))[0], $rows($club)]);
    });

    scenario('split: from the first day it is the whole series\' change and its plan', function () use ($d, $u, $db, $series, $choices, $col): void {
        $run = $series('Run', 'FREQ=WEEKLY;BYDAY=TU');
        $edit = ['scope' => 'following', 'instanceStart' => '2027-01-05T18:00:00+00:00', 'title' => 'Run, longer'];
        $plan = $d->events->previewPatch($u, $run, $edit + ['preview' => true]);
        $d->events->patch($u, $run, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices($plan)]]);
        checkEq('the series itself is renamed, no part is made', ['all', 'Run, longer', 0], [$plan['scope'], $col($run, 'title'), (int) $db->scalar('SELECT COUNT(*) FROM events WHERE id > ? AND recurrence_parent_id IS NULL', [$run])]);
    });

    scenario('split: from an edited day\'s own row with a calendar change, the preview\'s plan is what the commit writes, and Undo puts it back', function () use ($d, $u, $db, $rows, $series, $choices, $newest, $col): void {
        $choir = $series('Sample choir', 'FREQ=WEEKLY;BYDAY=TU;COUNT=8');
        $d->events->patch($u, $choir, ['scope' => 'this', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'title' => 'Sample choir, guest night']);
        $d->events->patch($u, $choir, ['scope' => 'this', 'instanceStart' => '2027-02-02T18:00:00+00:00', 'title' => 'Sample concert']);
        $day = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND title = ?', [$choir, 'Sample choir, guest night']);
        $later = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND title = ?', [$choir, 'Sample concert']);
        $two = (int) $d->calendars->create($u, ['name' => 'Sample second'])['id'];
        $edit = ['scope' => 'following', 'calendarId' => $two];
        $before = $rows($choir);
        $plan = $d->events->previewPatch($u, $day, $edit + ['preview' => true]);
        checkEq('the preview from the edited day is the series\' split at its key: the old part ends on 12 Jan after 2 events, the new one takes both edited days', [
            'following', '2027-01-19T18:00:00Z', ['kind' => 'until', 'until' => '20270112T180000Z', 'events' => 2, 'last' => '2027-01-12'], 2, $before,
        ], [$plan['scope'], $plan['split']['at'], $plan['split']['before']['bound'], $plan['split']['after']['edited'], $rows($choir)]);
        $d->events->patch($u, $day, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices($plan)]]);
        $new = $newest($choir);
        checkEq('the commit writes that plan: the old series bounded on its own calendar, the new part and both edited days on the new calendar', [
            'FREQ=WEEKLY;BYDAY=TU;UNTIL=20270112T180000Z', (int) $before[0]['calendar_id'], $two, '2027-01-19 18:00:00', 'FREQ=WEEKLY;BYDAY=TU;COUNT=6',
            [[$day, $two, 'Sample choir, guest night'], [$later, $two, 'Sample concert']],
        ], [
            $col($choir, 'rrule'), (int) $col($choir, 'calendar_id'), (int) $col($new, 'calendar_id'), $col($new, 'start_utc'), $col($new, 'rrule'),
            array_map(static fn(array $r): array => [(int) $r['id'], (int) $r['calendar_id'], $r['title']], $db->all('SELECT id, calendar_id, title FROM events WHERE recurrence_parent_id = ? ORDER BY id', [$new])),
        ]);
        $d->undo->undoLatest($u);
        checkEq('one Undo puts every row back on its calendar and removes the new part', [$before, 0],
            [$rows($choir), (int) $db->scalar('SELECT COUNT(*) FROM events WHERE id = ?', [$new])]);
    });

    scenario('split: on a Google calendar it is 422 series_refused with the reason google, and nothing is sent or written', function () use ($db, $error): void {
        $u = freshUser($db);
        $d = domains($db);
        $calls = [];
        $cfg = ['session_secret' => 'sample-session-secret', 'google' => ['client_id' => 'sample-client', 'client_secret' => 'sample-client-secret']];
        $auth = new BetterCal\Domain\GoogleAuth($db, $cfg, static function (string $url, array $fields, ?float $deadline) use (&$calls): array {
            $calls[] = 'token';
            return ['status' => 200, 'body' => json_encode(['access_token' => 'sample-access', 'expires_in' => 3600])];
        });
        $transport = static function (string $method, string $url, ?array $payload) use (&$calls): array {
            $calls[] = $method;
            return ['status' => 200, 'body' => json_encode(($payload ?? []) + ['id' => 'g-sample-series', 'iCalUID' => 'sample-series@google', 'status' => 'confirmed'])];
        };
        $events = new BetterCal\Domain\Events($db, new BetterCal\Domain\Recurrence(), $d->undo, new BetterCal\Domain\Labels($db),
            new BetterCal\Domain\Filters($db, $d->undo, new BetterCal\Infra\JobQueue($db)), new BetterCal\Domain\Trips($db, $d->undo), null,
            new BetterCal\Domain\GoogleWriter($db, $auth, new BetterCal\Domain\Feeds($db), $transport));
        $account = $db->insert('google_accounts', ['user_id' => $u, 'email' => 'owner@example.test',
            'refresh_token_enc' => BetterCal\Infra\Secrets::seal('sample-refresh', $cfg['session_secret']), 'scopes' => BetterCal\Domain\GoogleAuth::SCOPES]);
        $googleCal = $db->insert('calendars', ['user_id' => $u, 'name' => 'Google sample', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'sample@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($db, $u), 'role' => 'mine']);
        $now = BetterCal\Support\Time::nowDb();
        $weekly = $db->insert('events', ['user_id' => $u, 'calendar_id' => $googleCal, 'uid' => 'sample-series@google', 'title' => 'Sample standup',
            'start_utc' => '2027-01-05 18:00:00', 'end_utc' => '2027-01-05 19:00:00', 'all_day' => 0, 'tzid' => 'UTC', 'source' => 'feed',
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=6', 'google_event_id' => 'g-sample-series', 'created_at' => $now, 'updated_at' => $now]);
        $state = static fn(): array => [$db->all('SELECT * FROM events WHERE id = ? OR recurrence_parent_id = ?', [$weekly, $weekly]), (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$u])];
        $before = $state();
        $edit = ['scope' => 'following', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'title' => 'Sample standup, new room'];
        $plan = $events->previewPatch($u, $weekly, $edit + ['preview' => true]);
        [$code, $refused] = $error(fn() => $events->patch($u, $weekly, $edit + ['plan' => ['hash' => $plan['hash'] ?? '', 'choices' => []]]));
        [$deleteCode, $deleteRefused] = $error(fn() => $events->deleteEvent($u, $weekly, 'following', '2027-01-19T18:00:00+00:00', null, ['hash' => '', 'choices' => []]));
        checkEq('the preview names the refusal, and the commit of an edit or a delete from a later day is 422 series_refused with the reason google; no call, nothing written', [
            ['google'], '422 series_refused', ['google'], '422 series_refused', ['google'], [], $before,
        ], [
            array_column($plan['refusals'], 'code'), $code, array_column($refused['refusals'] ?? [], 'code'),
            $deleteCode, array_column($deleteRefused['refusals'] ?? [], 'code'), $calls, $state(),
        ]);
    });

    scenario('split: a relationship from a day on, previewed and committed, is the status edit on an own calendar and the answer on an opportunities one', function () use ($db, $d, $u, $series, $choices, $newest, $col): void {
        $own = $series('Sample rehearsal', 'FREQ=WEEKLY;BYDAY=TU');
        $plan = $d->events->previewRelationship($u, $own, 'maybe', 'following', '2027-01-19T18:00:00+00:00');
        $d->events->setRelationship($u, $own, 'maybe', 'following', '2027-01-19T18:00:00+00:00', null, ['hash' => $plan['hash'], 'choices' => $choices($plan)]);
        $new = $newest($own);
        checkEq('own calendar: the plan is the status edit\'s split, the new part from 19 Jan is tentative (maybe), the old part keeps its status', [
            'following', 'edit', 'confirmed', 'tentative', '2027-01-19 18:00:00', 'FREQ=WEEKLY;BYDAY=TU;UNTIL=20270112T180000Z',
        ], [$plan['scope'], $plan['action'], $col($own, 'status'), $col($new, 'status'), $col($new, 'start_utc'), $col($own, 'rrule')]);

        $offers = (int) $d->calendars->create($u, ['name' => 'Sample offers', 'role' => 'opportunities'])['id'];
        // A request of its own, as the app makes it: an Events reads its calendars once.
        $d = domains($db);
        $talk = (int) $d->events->create($u, eventAt($offers, 'Sample talks', '2027-01-05', ['rrule' => 'FREQ=WEEKLY;BYDAY=TU']))['eventId'];
        $plan = $d->events->previewRelationship($u, $talk, 'planned', 'following', '2027-01-19T18:00:00+00:00');
        $d->events->setRelationship($u, $talk, 'planned', 'following', '2027-01-19T18:00:00+00:00', null, ['hash' => $plan['hash'], 'choices' => $choices($plan)]);
        $new = $newest($talk);
        checkEq('opportunities calendar: the plan is the answer\'s split, the new part from 19 Jan is going (planned), the old part keeps no answer', [
            'following', 'answer', 'none', 'going', '2027-01-19 18:00:00',
        ], [$plan['scope'], $plan['action'], $col($talk, 'attendance'), $col($new, 'attendance'), $col($new, 'start_utc')]);
    });

    scenario('split: delete following and an answer from the first day preview as the whole series\', with no plan to commit', function () use ($d, $u, $db, $series, $col): void {
        $whole = ['whole' => true, 'direct' => true, 'hash' => null];
        $gone = $series('Sample yoga', 'FREQ=WEEKLY;BYDAY=TU;COUNT=4');
        $plan = $d->events->previewDelete($u, $gone, 'following', '2027-01-05T18:00:00+00:00');
        $d->events->deleteEvent($u, $gone, 'following', '2027-01-05T18:00:00+00:00', null, null);
        checkEq('delete following from the first day: {whole, direct, hash null}, and the commit with no plan deletes the series', [$whole, true],
            [array_replace($whole, array_intersect_key($plan, $whole)), $col($gone, 'deleted_at') !== null]);

        $answered = $series('Sample walk', 'FREQ=WEEKLY;BYDAY=TU;COUNT=4');
        $plan = $d->events->previewAttendance($u, $answered, 'going', 'following', '2027-01-05T18:00:00+00:00');
        $d->events->setAttendance($u, $answered, 'going', 'following', '2027-01-05T18:00:00+00:00', null);
        checkEq('an answer from the first day: {whole, direct, hash null}, and the commit with no plan answers the whole series, no part made', [$whole, 'going', 0], [
            array_replace($whole, array_intersect_key($plan, $whole)), $col($answered, 'attendance'),
            (int) $db->scalar('SELECT COUNT(*) FROM events WHERE id > ? AND user_id = ? AND recurrence_parent_id IS NULL', [$answered, $u]),
        ]);
    });

    scenario('split: a typed clock from a day on is read in the series\' zone, and the day acted on\'s own time and length are adoption groups of the part', function () use ($d, $u, $db, $home, $choices, $newest, $col): void {
        $class = (int) $d->events->create($u, ['calendarId' => $home, 'title' => 'Sample class', 'start' => '2027-01-05T18:00:00+01:00', 'end' => '2027-01-05T19:00:00+01:00',
            'allDay' => false, 'tzid' => 'Europe/Berlin', 'rrule' => 'FREQ=WEEKLY;BYDAY=TU'])['eventId'];
        $edit = ['scope' => 'following', 'instanceStart' => '2027-01-12T17:00:00+00:00', 'typed' => ['start' => '2027-01-12T09:30', 'end' => '2027-01-12T10:30']];
        $plan = $d->events->previewPatch($u, $class, $edit + ['preview' => true]);
        $d->events->patch($u, $class, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices($plan)]]);
        $new = $newest($class);
        checkEq('typed 09:30 to 10:30 in Berlin: the new part starts at 08:30 UTC on 12 Jan and ends an hour later, the old one ends on 5 Jan', [
            '2027-01-12 08:30:00', '2027-01-12 09:30:00', 'Europe/Berlin', 'FREQ=WEEKLY;BYDAY=TU;UNTIL=20270105T170000Z',
        ], [$col($new, 'start_utc'), $col($new, 'end_utc'), $col($new, 'tzid'), $col($class, 'rrule')]);

        $lab = (int) $d->events->create($u, eventAt($home, 'Sample lab', '2027-01-05', ['rrule' => 'FREQ=WEEKLY;BYDAY=TU']))['eventId'];
        $d->events->patch($u, $lab, ['scope' => 'this', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'start' => '2027-01-19T19:00:00+00:00', 'end' => '2027-01-19T21:00:00+00:00']);
        $day = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$lab]);
        $edit = ['scope' => 'following', 'typed' => ['start' => '2027-01-19T19:30', 'end' => '2027-01-19T20:30', 'tz' => 'UTC']];
        $plan = $d->events->previewPatch($u, $day, $edit + ['preview' => true]);
        $groups = [];
        foreach ($plan['groups'] as $g) {
            $groups[$g['id']] = [$g['selected'], array_column($g['options'], 'id')];
        }
        checkEq('from the edited day that owns its time and length, the part\'s plan has a time group preselecting adopt and a length group preselecting adopt for a typed value', [
            'following', ['adopt', ['adopt', 'same']], ['adopt', ['adopt', 'same']],
        ], [$plan['scope'], $groups['time'] ?? null, $groups['length'] ?? null]);
        $d->events->patch($u, $day, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices($plan)]]);
        $new = $newest($lab);
        checkEq('adopted: the new part is at 19:30 for an hour from 19 Jan, and the day keeps neither its own time nor its length', [
            '2027-01-19 19:30:00', '2027-01-19 20:30:00', [],
        ], [$col($new, 'start_utc'), $col($new, 'end_utc'), array_values(array_intersect(['time', 'length'], json_decode((string) ($db->scalar('SELECT override_fields FROM events WHERE id = ?', [$day]) ?? '[]'), true) ?? []))]);
    });

    scenario('split: the series\' rule sent back unchanged is no rule, so the part keeps the rest of the count', function () use ($d, $u, $db, $series, $choices, $newest, $col): void {
        $club = $series('Sample club', 'FREQ=WEEKLY;BYDAY=TU;COUNT=6');
        // The editor sends the rule it showed, spelled its own way, with a drag of 19 Jan to Wednesday.
        $edit = ['scope' => 'following', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'start' => '2027-01-20T18:00:00+00:00', 'end' => '2027-01-20T19:00:00+00:00',
            'rrule' => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=TU;COUNT=6'];
        $plan = $d->events->previewPatch($u, $club, $edit + ['preview' => true]);
        checkEq('the preview: no bound group, the part has the 4 events left from 20 Jan, ending 10 Feb', [[], ['kind' => 'count', 'count' => 4], 4, '2027-02-10', 'FREQ=WEEKLY;BYDAY=WE;COUNT=4'],
            [array_column($plan['groups'], 'id'), $plan['split']['after']['bound'], $plan['bound']['events'] ?? null, $plan['bound']['last'] ?? null, $plan['rule']['after']]);
        $d->events->patch($u, $club, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices($plan)]]);
        $new = $newest($club);
        checkEq('committed: Tuesdays 5 and 12 Jan, then 4 Wednesdays from 20 Jan, 6 events as the series showed', ['FREQ=WEEKLY;BYDAY=TU;UNTIL=20270112T180000Z', 'FREQ=WEEKLY;BYDAY=WE;COUNT=4', '2027-01-20 18:00:00'],
            [$col($club, 'rrule'), $col($new, 'rrule'), $col($new, 'start_utc')]);
    });

    scenario('split: a changed count is the whole series\' (preselected) or that many from this day on, each committed as it says', function () use ($d, $u, $db, $rows, $error, $series, $choices, $newest, $col): void {
        $club = $series('Sample club', 'FREQ=WEEKLY;BYDAY=TU;COUNT=6');
        $edit = ['scope' => 'following', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'title' => 'Sample club, spring', 'rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=8'];
        $before = $rows($club);
        $plan = $d->events->previewPatch($u, $club, $edit + ['preview' => true]);
        $bound = null;
        foreach ($plan['groups'] as $g) {
            if ($g['id'] === 'bound') {
                $bound = $g;
            }
        }
        checkEq('the bound group: 8 in the whole series (preselected: 6 from 19 Jan, ending 23 Feb) or 8 from 19 Jan on (ending 9 Mar), and the plan is the whole-count one', [
            'whole', ['whole', 'part'], 8, [[6, '2027-02-23'], [8, '2027-03-09']], ['2027-01-19T18:00:00+00:00', '2027-01-26T18:00:00+00:00', '2027-02-02T18:00:00+00:00'],
            ['kind' => 'count', 'count' => 6], 6,
        ], [
            $bound['selected'] ?? null, array_column($bound['options'] ?? [], 'id'), $bound['count'] ?? null,
            array_map(static fn(array $o): array => [$o['bound']['events'] ?? null, $o['bound']['last'] ?? null], $bound['options'] ?? []),
            array_column($bound['options'][0]['next'] ?? [], 'start'), $plan['split']['after']['bound'], $plan['bound']['events'] ?? null,
        ]);
        checkEq('a commit naming no bound reading is 409 series_plan_required', '409 series_plan_required',
            $error(fn() => $d->events->patch($u, $club, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => array_diff_key($choices($plan), ['bound' => 1])]]))[0]);
        $d->events->patch($u, $club, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices($plan)]]);
        $new = $newest($club);
        checkEq('the whole count committed: 2 before the split and 6 from 19 Jan, 8 in all', ['FREQ=WEEKLY;BYDAY=TU;UNTIL=20270112T180000Z', 'FREQ=WEEKLY;BYDAY=TU;COUNT=6', 'Sample club, spring'],
            [$col($club, 'rrule'), $col($new, 'rrule'), $col($new, 'title')]);
        $d->undo->undoLatest($u);
        checkEq('undone: every row as before', $before, $rows($club));
        $part = $d->events->previewPatch($u, $club, $edit + ['preview' => true, 'plan' => ['choices' => ['bound' => 'part']]]);
        checkEq('8 from this day on previewed: its plan has 8 events ending 9 Mar', ['part', 8, '2027-03-09'], [$part['groups'][0]['selected'], $part['bound']['events'] ?? null, $part['bound']['last'] ?? null]);
        $d->events->patch($u, $club, $edit + ['plan' => ['hash' => $part['hash'], 'choices' => $choices($part)]]);
        $new = $newest($club);
        checkEq('8 from this day on committed: the part has COUNT=8 from 19 Jan', ['FREQ=WEEKLY;BYDAY=TU;UNTIL=20270112T180000Z', 'FREQ=WEEKLY;BYDAY=TU;COUNT=8', '2027-01-19 18:00:00'],
            [$col($club, 'rrule'), $col($new, 'rrule'), $col($new, 'start_utc')]);
        // A whole count the old part already reaches leaves the part nothing: that reading is disabled, the other selected.
        $short = $series('Sample short club', 'FREQ=WEEKLY;BYDAY=TU;COUNT=6');
        $plan = $d->events->previewPatch($u, $short, ['scope' => 'following', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=2', 'preview' => true]);
        checkEq('COUNT=2 sent at the third day: "2 from this day on" is selected, with 2 events from 19 Jan', ['part', 2, '2027-01-26'],
            [$plan['groups'][0]['selected'] ?? null, $plan['bound']['events'] ?? null, $plan['bound']['last'] ?? null]);
        checkEq('the disabled whole-count reading carries the empty reason and code', [true, 'This would leave the series with no events. Delete it instead?', ['group' => 'bound', 'option' => 'whole', 'code' => 'empty']],
            [$plan['groups'][0]['options'][0]['disabled'] ?? false, $plan['groups'][0]['options'][0]['reason'] ?? null,
                array_values(array_map(static fn(array $r): array => array_intersect_key($r, ['group' => 1, 'option' => 1, 'code' => 1]), array_filter($plan['refusals'], static fn(array $r): bool => ($r['group'] ?? null) === 'bound')))[0] ?? null]);
    });

    scenario('split: a sent end before the split day is refused as an end before the start is, and writes nothing', function () use ($d, $u, $db, $rows, $error, $series): void {
        $walk = $series('Sample hike', 'FREQ=WEEKLY;BYDAY=TU;UNTIL=20270209T180000Z');
        $edit = ['scope' => 'following', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'start' => '2027-01-19T19:00:00+00:00', 'end' => '2027-01-19T20:00:00+00:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU;UNTIL=20270112T235959Z'];
        $before = $rows($walk);
        $entries = (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$u]);
        $whole = $d->events->previewPatch($u, $walk, ['scope' => 'all', 'rrule' => 'FREQ=WEEKLY;BYDAY=TU;UNTIL=20261229T235959Z', 'preview' => true]);
        $plan = $d->events->previewPatch($u, $walk, $edit + ['preview' => true]);
        checkEq('the preview refuses it with the whole series\' reason for an end before the start, the same code and words', [
            [['code' => 'empty', 'words' => 'This would leave the series with no events. Delete it instead?']], $whole['refusals'], [],
        ], [$plan['refusals'], array_map(static fn(array $r): array => array_intersect_key($r, ['code' => 1, 'words' => 1]), $whole['refusals']), $plan['groups']]);
        [$code, $errPlan] = $error(fn() => $d->events->patch($u, $walk, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => []]]));
        checkEq('the commit is 422 series_refused with the plan, and every row and the Undo list are as before', ['422 series_refused', 'empty', $before, $entries],
            [$code, $errPlan['refusals'][0]['code'] ?? null, $rows($walk), (int) $db->scalar('SELECT COUNT(*) FROM mutations WHERE user_id = ?', [$u])]);
        checkEq('no part was made', 0, (int) $db->scalar("SELECT COUNT(*) FROM events WHERE user_id = ? AND title = 'Sample hike' AND id <> ?", [$u, $walk]));
        // From the series' last day, whose part is that one day: an end sent before it is the same refusal, never read as the end it has.
        $last = $series('Sample last hike', 'FREQ=WEEKLY;BYDAY=TU;UNTIL=20270119T180000Z');
        $before = $rows($last);
        $edit = ['scope' => 'following', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'start' => '2027-01-19T19:00:00+00:00', 'end' => '2027-01-19T20:00:00+00:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU;UNTIL=20270112T235959Z'];
        $plan = $d->events->previewPatch($u, $last, $edit + ['preview' => true]);
        [$code] = $error(fn() => $d->events->patch($u, $last, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => []]]));
        checkEq('from the last day: refused as empty, 422, nothing written and no part made', [['empty'], '422 series_refused', $before, 0], [
            array_column($plan['refusals'], 'code'), $code, $rows($last),
            (int) $db->scalar("SELECT COUNT(*) FROM events WHERE user_id = ? AND title = 'Sample last hike' AND id <> ?", [$u, $last]),
        ]);
    });
};
