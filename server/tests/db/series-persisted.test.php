<?php

declare(strict_types=1);

// Whole-series plans against what is persisted (docs/design/series-model.md):
// a field-only edit keeps every key, shown time and skip as stored, for a
// rule several times a day and for DAILY with BYDAY across a spring change;
// a generated reading that would merge two members is refused before
// anything is written; a rule that names its clock takes the new one, and
// the plan's next dates are the stored series' expansion; a drag of no days
// and no time writes nothing.

use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Settings;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

return static function (Db $db): void {
    $u = freshUser($db);
    $d = domains($db);
    $home = (int) Settings::defaultCalendarId($db, $u);

    // Every row of a series as stored, without the timestamps a write touches.
    $rows = static function (int $id) use ($db): array {
        $out = [];
        foreach ($db->all('SELECT * FROM events WHERE id = ? OR recurrence_parent_id = ? ORDER BY id', [$id, $id]) as $row) {
            unset($row['updated_at']);
            $out[] = $row;
        }
        return $out;
    };
    $keys = static fn(int $id): array => $db->all('SELECT id, recurrence_instance_utc, start_utc, end_utc FROM events WHERE recurrence_parent_id = ? ORDER BY id', [$id]);
    $skips = static fn(int $id): array => json_decode((string) $db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$id]), true) ?: [];
    $create = static function (string $title, string $start, string $end, string $rrule, string $tz = 'UTC') use ($d, $u, $home): int {
        return (int) $d->events->create($u, ['calendarId' => $home, 'title' => $title, 'start' => $start, 'end' => $end, 'allDay' => false, 'tzid' => $tz, 'rrule' => $rrule])['eventId'];
    };
    // The stored series' occurrences from an instant on, skips applied, as instants.
    $expanded = static function (int $id, int $fromTs, int $n) use ($db): array {
        $m = $db->one('SELECT * FROM events WHERE id = ?', [$id]);
        $skip = array_flip(Recurrence::decodeExdates($m['exdates_json'] ?? null));
        $out = [];
        foreach (Recurrence::sabreExpand($m, Time::fromDb((string) $m['start_utc'])->modify('-1 day'), Time::fromDb((string) $m['start_utc'])->modify('+2 years')) as $o) {
            if (!isset($skip[Time::toDb($o['start'])]) && $o['start']->getTimestamp() >= $fromTs) {
                $out[] = $o['start']->getTimestamp();
            }
        }
        return array_slice($out, 0, $n);
    };

    scenario('series persisted: a rename keeps every key of a rule twice a day (R1)', function () use ($d, $u, $db, $rows, $keys, $skips, $create): void {
        $drills = $create('Sample drills', '2027-03-01T09:00:00+00:00', '2027-03-01T10:00:00+00:00', 'FREQ=DAILY;BYHOUR=9,17;COUNT=60');
        $d->events->patch($u, $drills, ['scope' => 'this', 'instanceStart' => '2027-03-15T17:00:00+00:00', 'title' => 'Evening drill']);
        $d->events->deleteEvent($u, $drills, 'this', '2027-03-20T17:00:00+00:00');
        $before = $rows($drills);
        $was = $keys($drills);
        $plan = planned($d->events, $u, $drills, ['scope' => 'all', 'title' => 'Sample practice'], ['ownTitle' => 'keep']);
        checkEq('one exception at 17:00: renamed around, it stays at 17:00 with its title, the skip at 17:00, 60 events', [
            $was, ['2027-03-20 17:00:00'], 'Evening drill', 'Sample practice', '60 scheduled occurrences, 1 skipped, ends 30 Mar',
        ], [
            $keys($drills), $skips($drills), $db->scalar('SELECT title FROM events WHERE recurrence_parent_id = ?', [$drills]),
            $db->scalar('SELECT title FROM events WHERE id = ?', [$drills]), preg_replace('/ \d{4}$/', '', (string) ($plan['bound']['words'] ?? '')),
        ]);
        $d->undo->undoLatest($u);
        checkEq('undone, every row is as before', $before, $rows($drills));

        $d->events->patch($u, $drills, ['scope' => 'this', 'instanceStart' => '2027-03-15T09:00:00+00:00', 'title' => 'Morning drill']);
        $before = $rows($drills);
        $was = $keys($drills);
        planned($d->events, $u, $drills, ['scope' => 'all', 'title' => 'Sample practice'], ['ownTitle' => 'keep']);
        checkEq('two exceptions on one day: the rename commits, both keep their keys and titles', [$was, ['Evening drill', 'Morning drill']], [
            $keys($drills), array_column($db->all('SELECT title FROM events WHERE recurrence_parent_id = ? ORDER BY id', [$drills]), 'title'),
        ]);
        $d->undo->undoLatest($u);
        checkEq('undone, every row is as before', $before, $rows($drills));
    });

    scenario('series persisted: a rename keeps DAILY with BYDAY on the expander\'s keys after a spring change (R2)', function () use ($d, $u, $db, $rows, $keys, $skips, $create): void {
        $run = $create('Sample run', '2027-03-01T02:30:00-08:00', '2027-03-01T03:30:00-08:00', 'FREQ=DAILY;BYDAY=MO,WE,FR;COUNT=12', 'America/Los_Angeles');
        $d->events->patch($u, $run, ['scope' => 'this', 'instanceStart' => '2027-03-15T03:30:00-07:00', 'title' => 'Sample race']);
        $d->events->deleteEvent($u, $run, 'this', '2027-03-19T03:30:00-07:00');
        $before = $rows($run);
        $was = $keys($run);
        $plan = planned($d->events, $u, $run, ['scope' => 'all', 'title' => 'Sample jog'], ['ownTitle' => 'keep']);
        $master = $db->one('SELECT * FROM events WHERE id = ?', [$run]);
        $occ = array_map(static fn(array $o): string => Time::toDb($o['start']), Recurrence::sabreExpand($master, Time::fromDb('2027-03-14 00:00:00'), Time::fromDb('2027-03-16 23:00:00')));
        checkEq('the 03:30 exception keeps its key and time, the skip its key; the rule as it was, so 15 Mar shows once', [
            $was, ['2027-03-19 10:30:00'], 'FREQ=DAILY;BYDAY=MO,WE,FR;COUNT=12', ['2027-03-15 10:30:00'], '12 scheduled occurrences, 1 skipped, ends 26 Mar',
        ], [$keys($run), $skips($run), $master['rrule'], $occ, preg_replace('/ \d{4}$/', '', (string) ($plan['bound']['words'] ?? ''))]);
        $d->undo->undoLatest($u);
        checkEq('undone, every row is as before', $before, $rows($run));
    });

    scenario('series persisted: a reading whose members coincide in some month is refused before anything is written (R3)', function () use ($d, $u, $rows, $create): void {
        foreach (['ordinary occurrences' => false, 'two edited rows at the colliding members' => true] as $what => $edited) {
            $billing = $create('Sample billing', '2027-01-01T09:00:00+00:00', '2027-01-01T10:00:00+00:00', 'FREQ=MONTHLY;BYMONTHDAY=1,-1;COUNT=4');
            if ($edited) {
                $d->events->patch($u, $billing, ['scope' => 'this', 'instanceStart' => '2027-02-01T09:00:00+00:00', 'title' => 'Sample first']);
                $d->events->patch($u, $billing, ['scope' => 'this', 'instanceStart' => '2027-02-28T09:00:00+00:00', 'title' => 'Sample last']);
            }
            $before = $rows($billing);
            $drag = ['scope' => 'all', 'instanceStart' => '2027-01-01T09:00:00+00:00', 'start' => '2027-01-28T09:00:00+00:00', 'end' => '2027-01-28T10:00:00+00:00'];
            $plan = $d->events->previewPatch($u, $billing, $drag);
            $code = null;
            try {
                $d->events->patch($u, $billing, $drag + ['plan' => ['hash' => $plan['hash'], 'choices' => ['reading' => 'member']]]);
            } catch (HttpError $e) {
                $code = $e->status . ' ' . $e->errorCode;
            }
            $member = array_values(array_filter($plan['refusals'], static fn(array $r): bool => ($r['option'] ?? '') === 'member'));
            checkEq("$what: the member reading disabled with its reason, the commit 422, nothing written", [
                'the 28th and the last day would be one day in some months', '422 series_refused', $before,
            ], [$member[0]['words'] ?? null, $code, $rows($billing)]);
        }
    });

    scenario('series persisted: a rule naming its clock takes the new one, and the plan\'s next is the stored expansion (R4)', function () use ($d, $u, $db, $create, $expanded): void {
        foreach ([
            'hour and minute' => ['FREQ=DAILY;BYHOUR=9;BYMINUTE=30;COUNT=4', 'FREQ=DAILY;BYHOUR=10;BYMINUTE=45;COUNT=4'],
            'hour' => ['FREQ=DAILY;BYHOUR=9;COUNT=4', 'FREQ=DAILY;BYHOUR=10;COUNT=4'],
            'minute' => ['FREQ=DAILY;BYMINUTE=30;COUNT=4', 'FREQ=DAILY;BYMINUTE=45;COUNT=4'],
        ] as $what => [$rrule, $written]) {
            foreach (['moved a day and to 10:45' => '2027-01-03', 'to 10:45 the same day' => '2027-01-02'] as $how => $day) {
                $standup = $create('Sample standup', '2027-01-01T09:30:00+00:00', '2027-01-01T10:30:00+00:00', $rrule);
                $plan = planned($d->events, $u, $standup, ['scope' => 'all', 'instanceStart' => '2027-01-02T09:30:00+00:00', 'start' => $day . 'T10:45:00+00:00', 'end' => $day . 'T11:45:00+00:00']);
                $next = array_map(static fn(array $n): int => Time::parseIso($n['start'])->getTimestamp(), $plan['next'] ?? []);
                checkEq("$what, $how: the rule says 10:45, and the plan's next is what the stored series shows", [$written, true, $next], [
                    $db->scalar('SELECT rrule FROM events WHERE id = ?', [$standup]), count($next) >= 3, $expanded($standup, $next[0] ?? 0, count($next)),
                ]);
            }
        }
    });

    scenario('series persisted: a drag of no days and no time keeps an UNTIL that is no occurrence (T1)', function () use ($d, $u, $home, $rows): void {
        $swim = (int) $d->events->create($u, ['calendarId' => $home, 'title' => 'Sample swim', 'start' => '2027-02-12', 'end' => '2027-02-13', 'allDay' => true,
            'rrule' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=FR;UNTIL=20270709'])['eventId'];
        $before = $rows($swim);
        $plan = planned($d->events, $u, $swim, ['scope' => 'all', 'instanceStart' => '2027-03-12', 'start' => '2027-03-12', 'end' => '2027-03-13', 'allDay' => true]);
        checkEq('nothing about the series is written', $before, $rows($swim));
        check('and a plan, when there was one, names its real end', $plan === [] || str_contains((string) ($plan['bound']['words'] ?? ''), '2 Jul'), json_encode($plan['bound'] ?? null));
    });
};
