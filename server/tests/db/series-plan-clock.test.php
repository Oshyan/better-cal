<?php

declare(strict_types=1);

// A series plan's hash covers what the plan read and what the request asks for, never what the write stamps from the
// clock (decisions.md, "A plan's hash never moves by itself"). A change for all events, or this and following, that sets
// coordinates (a picked place) is previewed in one second and committed in a later one, as a person's confirmation
// always is: it goes through, and the commit writes the place's stamp with its own time. A real change to the series
// between the preview and the commit is still refused as stale. The later second is an event the test makes
// (Time::$testOffsetSeconds), never a wait. Local calendars and Google's stand-in. Invented data.

use BetterCal\EditHarness\GoogleStand;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    $first = '2027-07-19T21:00:00-04:00';
    $later = '2027-07-21T21:00:00-04:00';

    // A daily series of eight at 21:00 New York, at Sample Hall: on the account's own calendar, or on Google's stand-in.
    $series = static function (bool $google) use ($db, $first): object {
        $u = freshUser($db);
        $d = domains($db);
        $s = $google ? GoogleStand::make($db, $d->undo, $u) : null;
        $events = $s?->events ?? $d->events;
        $cal = $s?->calendarId ?? (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$u]);
        $id = (int) $events->create($u, ['calendarId' => $cal, 'title' => 'Sample series', 'location' => 'Sample Hall', 'start' => $first,
            'end' => '2027-07-19T22:00:00-04:00', 'tzid' => 'America/New_York', 'rrule' => 'FREQ=DAILY;COUNT=8'])['eventId'];
        return (object) ['u' => $u, 'd' => $d, 's' => $s, 'events' => $events, 'id' => $id];
    };
    $done = static function (object $t) use ($db): void {
        if ($t->s !== null) {
            GoogleStand::forget($db, $t->u);
        }
    };
    // The preview (and its preselected options), then the clock moved on by $seconds, then the commit: [error code or
    // null, the commit's own time]. The clock is put back after.
    $previewThenCommit = static function (object $t, array $in, int $seconds, ?callable $between = null): array {
        $plan = $t->events->previewPatch($t->u, $t->id, $in + ['plan' => ['choices' => []]]);
        $choices = [];
        foreach ($plan['groups'] as $g) {
            $choices[$g['id']] = $g['selected'];
        }
        if ($between !== null) {
            $between();
        }
        Time::$testOffsetSeconds += $seconds;
        try {
            $at = Time::nowDb();
            try {
                $t->events->patch($t->u, $t->id, $in + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices]]);
                $t->events->throwDeferred();
                return [null, $at];
            } catch (HttpError $e) {
                return [$e->errorCode, $at];
            }
        } finally {
            Time::$testOffsetSeconds -= $seconds;
        }
    };
    $place = static fn(object $t): array => array_values($db->one('SELECT location, ROUND(location_lat, 5) AS lat, ROUND(location_lng, 5) AS lng, location_source, location_placed_at FROM events WHERE id = ?', [$t->id]));

    $coordinates = ['locationLat' => 37.5, 'locationLng' => -122.2];
    $changes = [
        'coordinates alone, all events' => ['scope' => 'all', 'instanceStart' => $first] + $coordinates,
        'coordinates alone, this and following' => ['scope' => 'following', 'instanceStart' => $later] + $coordinates,
        'a new place with its coordinates and an hour later, all events' => ['scope' => 'all', 'instanceStart' => $first, 'location' => 'Sample Annex',
            'start' => '2027-07-19T22:00:00-04:00', 'end' => '2027-07-19T23:00:00-04:00'] + $coordinates,
    ];
    foreach (['a calendar here' => false, 'a Google calendar' => true] as $where => $google) {
        foreach ($changes as $name => $in) {
            scenario("plan clock, $where, $name: previewed, committed a second later, goes through with the stamp of its commit", function () use ($series, $done, $previewThenCommit, $place, $db, $where, $name, $in): void {
                $t = $series(str_contains($where, 'Google'));
                [$code, $at] = $previewThenCommit($t, $in, 1);
                // A split's new part carries the coordinates; the whole series' change, the series' own row.
                $row = $in['scope'] === 'following'
                    ? $db->one('SELECT id FROM events WHERE id <> ? AND user_id = ? AND recurrence_parent_id IS NULL AND rrule IS NOT NULL AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$t->id, $t->u])
                    : ['id' => $t->id];
                $stamp = $db->scalar('SELECT location_placed_at FROM events WHERE id = ?', [(int) ($row['id'] ?? 0)]);
                checkEq("plan clock, $where, $name: no stale refusal; the coordinates written, stamped with the commit's second",
                    [null, ['37.5', '-122.2', 'picked'], $at],
                    [$code, array_map('strval', array_values($db->one('SELECT ROUND(location_lat, 5) AS lat, ROUND(location_lng, 5) AS lng, location_source FROM events WHERE id = ?', [(int) ($row['id'] ?? 0)]) ?? [])), $stamp]);
                $done($t);
            });
        }

        // The check is not blind: another change to the series between the preview and the commit is still refused as
        // stale, nothing written; a new preview then, committed a second later again, goes through.
        scenario("plan clock, $where: a change made between preview and commit is still stale, and the new plan goes through a second later", function () use ($series, $done, $previewThenCommit, $place, $where, $changes): void {
            $t = $series(str_contains($where, 'Google'));
            $in = $changes['a new place with its coordinates and an hour later, all events'];
            $before = $place($t);
            [$stale] = $previewThenCommit($t, $in, 1, fn() => planned($t->events, $t->u, $t->id, ['scope' => 'all', 'instanceStart' => '2027-07-19T21:00:00-04:00', 'title' => 'Sample renamed']));
            $kept = $place($t);
            [$again, $at] = $previewThenCommit($t, $in, 2);
            checkEq("plan clock, $where: stale after another change, nothing of it written; the new plan then goes through, stamped with its commit's second",
                ['series_plan_changed', $before, null, ['Sample Annex', '37.5', '-122.2', 'picked', $at]],
                [$stale, $kept, $again, array_map('strval', $place($t))]);
            $done($t);
        });
    }
};
