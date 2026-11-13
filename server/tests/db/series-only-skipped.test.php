<?php

declare(strict_types=1);

// A plan that leaves a series no day but deleted ones is refused as leaving no events (decisions.md, "A plan that
// leaves no undeleted day is refused as leaving no events"; replay round 4, cause 3; probe 2026-11-13, 3.62: Google
// cancels such a series for good). On every calendar: a change to all events, and "this and following" whose earlier
// part would be left only deleted days. Expectations by date. Invented data.

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

require_once __DIR__ . '/google-hunt-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    $weekly = ['start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=3'];
    $codeOf = static function (callable $fn): ?string {
        try {
            $fn();
            return null;
        } catch (HttpError $e) {
            return $e->errorCode;
        }
    };
    $refusalCodes = static fn(array $plan): array => array_values(array_unique(array_column($plan['refusals'] ?? [], 'code')));

    scenario('only skipped, local: a rule change leaving only a deleted day is refused as empty, nothing changes', function () use ($db, $weekly, $codeOf, $refusalCodes): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = (int) $d->calendars->create($u, ['name' => 'Sample local'])['id'];
        $id = (int) $d->events->create($u, ['calendarId' => $cal, 'title' => 'Sample series'] + $weekly)['eventId'];
        $d->events->deleteEvent($u, $id, 'this', '2027-03-01T10:00:00-08:00');
        $body = ['scope' => 'all', 'rrule' => 'FREQ=WEEKLY;COUNT=1'];
        $plan = $d->events->previewPatch($u, $id, $body + ['plan' => ['choices' => []]]);
        checkEq('the plan is refused as leaving no events', ['empty'], $refusalCodes($plan));
        $rule = $db->scalar('SELECT rrule FROM events WHERE id = ?', [$id]);
        checkEq('the commit is refused and the rule stays', ['series_refused', $rule], [$codeOf(static fn() => $d->events->patch($u, $id, $body + ['plan' => ['hash' => $plan['hash'], 'choices' => []]])), $db->scalar('SELECT rrule FROM events WHERE id = ?', [$id])]);
        // Control: COUNT=2 keeps the second day.
        $plan2 = $d->events->previewPatch($u, $id, ['scope' => 'all', 'rrule' => 'FREQ=WEEKLY;COUNT=2', 'plan' => ['choices' => []]]);
        checkEq('control: a rule that keeps one undeleted day is not refused', [], $refusalCodes($plan2));
    });

    scenario('only skipped, Google: the same change is refused before anything is sent; the series stays live', function () use ($db, $weekly, $refusalCodes): void {
        $t = H::make($db, $weekly);
        try {
            H::skip($t, '2027-03-01T10:00:00-08:00');
            $plan = $t->e->previewPatch($t->u, $t->id, ['scope' => 'all', 'rrule' => 'FREQ=WEEKLY;COUNT=1', 'plan' => ['choices' => []]]);
            checkEq('the plan is refused as leaving no events', ['empty'], $refusalCodes($plan));
            $before = count($t->s->fake->requests);
            [, $error] = H::tryChange($t, ['scope' => 'all', 'rrule' => 'FREQ=WEEKLY;COUNT=1']);
            checkEq('the change is refused, nothing sent, the series live at Google', [true, [], 'confirmed'], [$error !== null, H::writes($t, $before), $t->s->fake->events[$t->gid]['status'] ?? null]);
        } finally {
            H::done($t);
        }
    });

    scenario('only skipped, this and following: a split whose earlier part would keep only deleted days is refused as empty', function () use ($db, $weekly, $codeOf): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = (int) $d->calendars->create($u, ['name' => 'Sample local'])['id'];
        $id = (int) $d->events->create($u, ['calendarId' => $cal, 'title' => 'Sample series'] + $weekly)['eventId'];
        $d->events->deleteEvent($u, $id, 'this', '2027-03-01T10:00:00-08:00');
        $plan = $d->events->previewDelete($u, $id, 'following', '2027-03-08T10:00:00-08:00');
        checkEq('delete following from the second day, the first deleted: refused as leaving no events', ['empty'], array_values(array_unique(array_column($plan['refusals'] ?? [], 'code'))));
    });
};
