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

    scenario('split: from the first day it is the whole series\' change and its plan', function () use ($d, $u, $db, $series, $choices, $col): void {
        $run = $series('Run', 'FREQ=WEEKLY;BYDAY=TU');
        $edit = ['scope' => 'following', 'instanceStart' => '2027-01-05T18:00:00+00:00', 'title' => 'Run, longer'];
        $plan = $d->events->previewPatch($u, $run, $edit + ['preview' => true]);
        $d->events->patch($u, $run, $edit + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices($plan)]]);
        checkEq('the series itself is renamed, no part is made', ['all', 'Run, longer', 0], [$plan['scope'], $col($run, 'title'), (int) $db->scalar('SELECT COUNT(*) FROM events WHERE id > ? AND recurrence_parent_id IS NULL', [$run])]);
    });
};
