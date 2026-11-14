<?php

declare(strict_types=1);

// A plan that leaves a series no day but deleted ones is refused as leaving no events (decisions.md, "A plan that
// leaves no undeleted day is refused as leaving no events"; replay round 4, cause 3; probe 2026-11-13, 3.62: Google
// cancels such a series for good). On every calendar. And "this and following" from the first day still showing,
// whose earlier days are all deleted, is the whole series' change, never refused. Expectations by date. Invented data.

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

    // "This and following" from the first day still showing is the whole series, whether or not earlier days were
    // deleted (decisions.md, "From the first day still showing, this and following is the whole series"; the second
    // read of the 1.5.6 branch, F2). A weekly series of three from 1 March, 1 March deleted, each change from 8 March:
    // what the calendar shows after it, by date, on a local calendar and on Google.
    $shown = static function (BetterCal\Domain\Events $events, int $u, int $cal) use ($la): array {
        $utc = new DateTimeZone('UTC');
        $out = [];
        foreach ($events->window($u, new DateTimeImmutable('2027-02-20 00:00:00', $utc), new DateTimeImmutable('2027-04-01 00:00:00', $utc), [$cal], null, true) as $x) {
            $out[] = (new DateTimeImmutable((string) $x['start']))->setTimezone(new DateTimeZone($la))->format('m-d H:i') . ' ' . $x['title'];
        }
        sort($out);
        return $out;
    };
    $second = '2027-03-08T10:00:00-08:00';
    $changes = [
        'renamed from 8 March on' => [['scope' => 'following', 'instanceStart' => $second, 'title' => 'Sample series, renamed'], ['03-08 10:00 Sample series, renamed', '03-15 10:00 Sample series, renamed']],
        'an hour later from 8 March on' => [['scope' => 'following', 'instanceStart' => $second, 'start' => '2027-03-08T11:00:00-08:00', 'end' => '2027-03-08T12:00:00-08:00'], ['03-08 11:00 Sample series', '03-15 11:00 Sample series']],
        'deleted from 8 March on' => [null, []],
    ];
    foreach (['local', 'Google'] as $kind) {
        foreach ($changes as $what => [$body, $want]) {
            scenario("first day still showing, $kind: 1 March deleted, then $what: the whole series, no refusal", function () use ($db, $weekly, $kind, $body, $want, $what, $second, $shown, $refusalCodes): void {
                if ($kind === 'local') {
                    $u = freshUser($db);
                    $d = domains($db);
                    $cal = (int) $d->calendars->create($u, ['name' => 'Sample local'])['id'];
                    $events = $d->events;
                    $id = (int) $events->create($u, ['calendarId' => $cal, 'title' => 'Sample series'] + $weekly)['eventId'];
                    $t = null;
                } else {
                    $t = H::make($db, $weekly);
                    [$u, $cal, $events, $id] = [$t->u, $t->s->calendarId, $t->e, $t->id];
                }
                try {
                    $events->deleteEvent($u, $id, 'this', '2027-03-01T10:00:00-08:00');
                    $events->throwDeferred();
                    if ($body === null) {
                        $plan = $events->previewDelete($u, $id, 'following', $second);
                        checkEq("$kind, $what: the preview is the whole series' delete, no refusal", [true, []], [!empty($plan['whole']), $refusalCodes($plan)]);
                        plannedDelete($events, $u, $id, $second);
                    } else {
                        $plan = $events->previewPatch($u, $id, $body + ['plan' => ['choices' => []]]);
                        checkEq("$kind, $what: the preview is the whole series' plan, no refusal", ['all', []], [$plan['scope'] ?? null, $refusalCodes($plan)]);
                        planned($events, $u, $id, $body);
                    }
                    $events->throwDeferred();
                    checkEq("$kind, $what: what the calendar shows after", $want, $shown($events, $u, $cal));
                    if ($t !== null) {
                        checkEq("$kind, $what: nothing half done", [], H::halfDone($t));
                    }
                } finally {
                    if ($t !== null) {
                        H::done($t);
                    }
                }
            });
        }
    }

    // A change that truly leaves no events from its day on is refused, in words for this and following.
    scenario('this and following with an end before its day: refused, the words say from this day on and name the way out', function () use ($db, $weekly): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = (int) $d->calendars->create($u, ['name' => 'Sample local'])['id'];
        $id = (int) $d->events->create($u, ['calendarId' => $cal, 'title' => 'Sample series'] + $weekly)['eventId'];
        $plan = $d->events->previewPatch($u, $id, ['scope' => 'following', 'instanceStart' => '2027-03-08T10:00:00-08:00', 'rrule' => 'FREQ=WEEKLY;UNTIL=20270302T000000Z', 'plan' => ['choices' => []]]);
        checkEq('refused as empty, in the split\'s words', [['empty', 'This would leave no events from this day on. Delete this and following instead?']],
            array_map(static fn(array $r): array => [$r['code'] ?? '', $r['words'] ?? ''], $plan['refusals'] ?? []));
    });
};
