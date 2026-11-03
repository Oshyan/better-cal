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

    // A length-only edit from any occurrence: each delta is measured against
    // the occurrence acted on, so the series' clock, start, rule and every
    // key stay, and only the length changes (F2).
    $lengthOnly = function (string $what, int $id, int $target, array $in) use ($d, $u, $db, $rows, $keys): void {
        $before = $rows($id);
        $m0 = $db->one('SELECT * FROM events WHERE id = ?', [$id]);
        $was = $keys($id);
        planned($d->events, $u, $target, $in);
        $m1 = $db->one('SELECT * FROM events WHERE id = ?', [$id]);
        $len = static fn(array $r): int => strtotime((string) $r['end_utc']) - strtotime((string) $r['start_utc']);
        checkEq("$what: the start, rule and skips as stored, two hours long, every key and start kept, every day two hours", [
            $m0['start_utc'], $m0['rrule'], $m0['exdates_json'], 7200, array_map(static fn(array $k): array => [$k['id'], $k['recurrence_instance_utc'], $k['start_utc']], $was),
            array_fill(0, count($was), 7200),
        ], [
            $m1['start_utc'], $m1['rrule'], $m1['exdates_json'], $len($m1), array_map(static fn(array $k): array => [$k['id'], $k['recurrence_instance_utc'], $k['start_utc']], $keys($id)),
            array_map($len, $keys($id)),
        ]);
        $d->undo->undoLatest($u);
        checkEq("$what: undone, every row is as before", $before, $rows($id));
    };

    scenario('series persisted: a length-only edit of a later time of a rule twice a day keeps the series\' clock (F2)', function () use ($d, $u, $db, $create, $lengthOnly): void {
        $drills = $create('Sample drills', '2027-01-01T09:00:00+00:00', '2027-01-01T10:00:00+00:00', 'FREQ=DAILY;BYHOUR=9,17;COUNT=8');
        $lengthOnly('09:00 of 2 Jan to 11:00', $drills, $drills, ['scope' => 'all', 'instanceStart' => '2027-01-02T09:00:00+00:00', 'start' => '2027-01-02T09:00:00+00:00', 'end' => '2027-01-02T11:00:00+00:00']);
        $lengthOnly('17:00 of 2 Jan to 19:00', $drills, $drills, ['scope' => 'all', 'instanceStart' => '2027-01-02T17:00:00+00:00', 'start' => '2027-01-02T17:00:00+00:00', 'end' => '2027-01-02T19:00:00+00:00']);
        $d->events->patch($u, $drills, ['scope' => 'this', 'instanceStart' => '2027-01-03T17:00:00+00:00', 'title' => 'Evening drill']);
        $d->events->deleteEvent($u, $drills, 'this', '2027-01-04T17:00:00+00:00');
        $lengthOnly('with an edited day and a skip, from an ordinary 17:00', $drills, $drills, ['scope' => 'all', 'instanceStart' => '2027-01-02T17:00:00+00:00', 'start' => '2027-01-02T17:00:00+00:00', 'end' => '2027-01-02T19:00:00+00:00']);
        $plan = $d->events->previewPatch($u, $drills, ['scope' => 'all', 'instanceStart' => '2027-01-02T17:00:00+00:00', 'start' => '2027-01-02T17:00:00+00:00', 'end' => '2027-01-02T19:00:00+00:00', 'title' => 'Sample practice']);
        $edited = array_values(array_filter($plan['days'], static fn(array $x): bool => $x['kind'] === 'edited'));
        checkEq('the edited day\'s lists in the plan: it keeps its own title and takes the length', [['title'], ['length']], [$edited[0]['kept'] ?? null, $edited[0]['takes'] ?? null]);
        $day = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$drills]);
        $lengthOnly('from the edited 17:00\'s own row', $drills, $day, ['scope' => 'all', 'start' => '2027-01-03T17:00:00+00:00', 'end' => '2027-01-03T19:00:00+00:00']);
    });

    scenario('series persisted: a length-only edit of DAILY with BYDAY after a spring change keeps earlier days at their time (F2)', function () use ($d, $u, $db, $create, $lengthOnly, $expanded): void {
        $run = $create('Sample run', '2027-03-01T02:30:00-08:00', '2027-03-01T03:30:00-08:00', 'FREQ=DAILY;BYDAY=MO,WE,FR;COUNT=12', 'America/Los_Angeles');
        $before = $expanded($run, 0, 12);
        $lengthOnly('15 Mar 03:30 to 05:30', $run, $run, ['scope' => 'all', 'instanceStart' => '2027-03-15T03:30:00-07:00', 'start' => '2027-03-15T03:30:00-07:00', 'end' => '2027-03-15T05:30:00-07:00']);
        planned($d->events, $u, $run, ['scope' => 'all', 'instanceStart' => '2027-03-15T03:30:00-07:00', 'start' => '2027-03-15T03:30:00-07:00', 'end' => '2027-03-15T05:30:00-07:00']);
        checkEq('committed: 1 Mar at 02:30 and every day shown where it was', ['2027-03-01 10:30:00', $before], [$db->scalar('SELECT start_utc FROM events WHERE id = ?', [$run]), $expanded($run, 0, 12)]);
        $d->undo->undoLatest($u);
        $d->events->patch($u, $run, ['scope' => 'this', 'instanceStart' => '2027-03-17T03:30:00-07:00', 'title' => 'Sample race']);
        $d->events->deleteEvent($u, $run, 'this', '2027-03-19T03:30:00-07:00');
        $lengthOnly('with an edited day and a skip, from 15 Mar', $run, $run, ['scope' => 'all', 'instanceStart' => '2027-03-15T03:30:00-07:00', 'start' => '2027-03-15T03:30:00-07:00', 'end' => '2027-03-15T05:30:00-07:00']);
        $day = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$run]);
        $lengthOnly('from the edited day\'s own row', $run, $day, ['scope' => 'all', 'start' => '2027-03-17T03:30:00-07:00', 'end' => '2027-03-17T05:30:00-07:00']);
        $lengthOnly('from the first day', $run, $run, ['scope' => 'all', 'instanceStart' => '2027-03-01T02:30:00-08:00', 'start' => '2027-03-01T02:30:00-08:00', 'end' => '2027-03-01T04:30:00-08:00']);
    });

    scenario('series persisted: the trip flag for all events reaches every row whatever each had (F1)', function () use ($d, $u, $db, $home, $rows): void {
        $flags = static fn(int $id): array => array_map('intval', array_column($db->all('SELECT is_container FROM events WHERE id = ? OR recurrence_parent_id = ? ORDER BY id', [$id, $id]), 'is_container'));
        $answer = static function (callable $fn): ?string {
            try {
                $fn();
                return null;
            } catch (HttpError $e) {
                return $e->status . ' ' . $e->errorCode;
            }
        };
        $make = static function (bool $trip) use ($d, $u, $db, $home): array {
            $id = (int) $d->events->create($u, eventAt($home, 'Sample trip', '2027-01-05', ['rrule' => 'FREQ=WEEKLY;BYDAY=TU', 'isContainer' => $trip]))['eventId'];
            $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-12T18:00:00+00:00', 'title' => 'Sample trip, day two']);
            $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-19T18:00:00+00:00', 'title' => 'Sample trip, day three']);
            return [$id, array_map('intval', array_column($db->all('SELECT id FROM events WHERE recurrence_parent_id = ? ORDER BY id', [$id]), 'id'))];
        };
        foreach ([[false, true], [true, false]] as [$masterFlag, $dayFlag]) {
            foreach (['the edited day\'s own row' => 'day', 'the master' => 'master'] as $where => $to) {
                [$id, $days] = $make($masterFlag);
                $d->events->patch($u, $days[0], ['isContainer' => $dayFlag]);
                $mixed = [(int) $masterFlag, (int) $dayFlag, (int) $masterFlag];
                checkEq("master $masterFlag, one day $dayFlag: the mixed state", $mixed, $flags($id));
                $before = $rows($id);
                $in = ['scope' => 'all', 'isContainer' => $dayFlag];
                if ($to === 'master') {
                    $in['instanceStart'] = '2027-01-26T18:00:00+00:00';
                }
                $target = $to === 'master' ? $id : $days[0];
                $plan = $d->events->previewPatch($u, $target, $in + ['preview' => true]);
                check("sent to $where: the plan isn't direct and says the edited days take it", !$plan['direct'] && str_contains(implode(' ', array_column($plan['fields'], 'words')), 'edited day'), json_encode([$plan['direct'], $plan['fields']]));
                planned($d->events, $u, $target, $in);
                checkEq("sent to $where with all events: every row takes " . (int) $dayFlag, array_fill(0, 3, (int) $dayFlag), $flags($id));
                $d->undo->undoLatest($u);
                checkEq("sent to $where: undone, every row is as before", $before, $rows($id));
            }
        }
        // Clearing with members on any affected row is refused, the master's flag clear or not.
        [$id, $days] = $make(false);
        $d->events->patch($u, $days[0], ['isContainer' => true]);
        $member = (int) $d->events->create($u, eventAt($home, 'Sample rental pickup', '2027-01-13'))['eventId'];
        (new BetterCal\Domain\Trips($db, $d->undo))->attach($u, $days[0], $member);
        $before = $rows($id);
        foreach (['the master' => [$id, ['instanceStart' => '2027-01-26T18:00:00+00:00']], 'the edited day with the member' => [$days[0], []], 'another edited day' => [$days[1], []]] as $where => [$target, $more]) {
            $code = $answer(fn() => planned($d->events, $u, $target, ['scope' => 'all', 'isContainer' => false] + $more));
            checkEq("a member on an edited day, the master clear, sent to $where: 400 trip_has_members, nothing written", ['400 trip_has_members', $before], [$code, $rows($id)]);
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
