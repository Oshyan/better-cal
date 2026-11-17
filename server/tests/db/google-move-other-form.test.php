<?php

declare(strict_types=1);

// A calendar moved to Google holding a day of the other form: a timed weekly series with one day made all-day, and an
// all-day weekly series with one day given a clock time. GoogleMove::run imports each series, then sends each such day
// over the occurrence the import made.
//
// Expectations from the rule, not from the code under test (decisions.md, "One day of a repeating event on Google may
// take the other form"): the day goes as one PATCH on its instance id with the held form's fields null, and Google takes
// it (probe 3.31, 2026-11-10: a timed series' day made all-day, its clock fields sent null; probe 3.38, 2026-11-13: an
// all-day series' day given 15:00, its date sent null). Then the day is held at Google in its own form, the first poll
// keeps it so here, and nothing else is sent. Instance ids by Google's forms (probe groups 2 and 3): a timed series'
// day by its original start in UTC basic form, an all-day series' day by its date.
//
// The stand-in for Google is the edit harness's (tools/edit-harness/GoogleStand.php). The move runs against its
// calendar with the stand-in's own calendar row removed here, so one calendar here is bound to it. The move's own first
// poll is given no Google configuration, so it stops before any request (GoogleAuth::requireConfigured) and the test
// polls through the stand-in instead; nothing goes to the network. Invented data.

use BetterCal\Domain\Feeds;
use BetterCal\Domain\GoogleAuth;
use BetterCal\Domain\GoogleMove;
use BetterCal\EditHarness\GoogleStand;
use BetterCal\Infra\Db;
use BetterCal\Infra\JobQueue;

require_once dirname(__DIR__, 3) . '/tools/edit-harness/lib.php';

return static function (Db $db): void {
    scenario('google move: a timed series with an all-day day and an all-day series with a timed day, each day sent in its own form', function () use ($db): void {
        $la = 'America/Los_Angeles';
        $u = freshUser($db);
        seriesUndo($db, $u, false);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        try {
            $local = (int) $db->scalar("SELECT id FROM calendars WHERE user_id = ? AND kind = 'local' ORDER BY id LIMIT 1", [$u]);
            check('the account has a calendar of its own here', $local > 0);
            $e = $d->events;
            // Timed: Mondays 10:00 Los Angeles from 1 March 2027, four; the second (8 March, 18:00 UTC) all-day.
            $timed = (int) $e->create($u, ['calendarId' => $local, 'title' => 'Sample timed weekly', 'start' => '2027-03-01T10:00:00-08:00', 'end' => '2027-03-01T11:00:00-08:00',
                'allDay' => false, 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=4'])['eventId'];
            $e->patch($u, $timed, ['scope' => 'this', 'instanceStart' => '2027-03-08T10:00:00-08:00', 'allDay' => true, 'start' => '2027-03-08', 'end' => '2027-03-09']);
            // All-day: Tuesdays from 2 March 2027, four; the second (9 March) 15:00 to 16:00 Los Angeles (23:00 to 24:00 UTC).
            $allDay = (int) $e->create($u, ['calendarId' => $local, 'title' => 'Sample all-day weekly', 'start' => '2027-03-02', 'end' => '2027-03-03',
                'allDay' => true, 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=4'])['eventId'];
            $e->patch($u, $allDay, ['scope' => 'this', 'instanceStart' => '2027-03-09', 'allDay' => false, 'start' => '2027-03-09T15:00:00-08:00', 'end' => '2027-03-09T16:00:00-08:00', 'tzid' => $la]);
            $day = static fn(int $parent): ?array => $db->one('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL', [$parent]);
            checkEq('before the move: each series has its day of the other form here', [1, '2027-03-08 18:00:00', 0, '2027-03-09 00:00:00'],
                [(int) ($day($timed)['all_day'] ?? -1), $day($timed)['recurrence_instance_utc'] ?? null, (int) ($day($allDay)['all_day'] ?? -1), $day($allDay)['recurrence_instance_utc'] ?? null]);

            // The move, as GoogleMove::start leaves it for run() with a calendar the person picked (create_new 0).
            $db->run('DELETE FROM calendars WHERE id = ?', [$s->calendarId]);
            $account = (int) $db->scalar('SELECT id FROM google_accounts WHERE user_id = ?', [$u]);
            $moveId = $db->insert('calendar_moves', ['user_id' => $u, 'calendar_id' => $local, 'google_account_id' => $account, 'google_calendar_id' => GoogleStand::CALENDAR,
                'create_new' => 0, 'remote_marker' => bin2hex(random_bytes(16)), 'remote_reconciled_at' => gmdate('Y-m-d H:i:s'),
                'total' => (int) $db->scalar('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$local])]);
            $offline = ['session_secret' => (string) (getenv('BETTERCAL_SESSION_SECRET') ?: 'sample-session-secret'), 'google' => []];
            $move = new GoogleMove($db, new GoogleAuth($db, $offline), $s->writer, new Feeds($db, null, $offline), $d->undo, new JobQueue($db));
            $base = count($s->fake->requests);
            $move->run((int) $moveId, 120);
            $row = $db->one('SELECT status, error FROM calendar_moves WHERE id = ?', [$moveId]);
            checkEq('the move is done, with no error', ['done', null], [$row['status'] ?? null, $row['error'] ?? null]);

            $gT = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$timed]);
            $gA = (string) $db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$allDay]);
            $sent = array_values(array_filter(array_slice($s->fake->requests, $base), static fn(array $r): bool => $r['method'] !== 'GET'));
            $prefix = '/calendars/' . rawurlencode(GoogleStand::CALENDAR) . '/events';
            checkEq('sent: two imports, then one PATCH for each day, on its instance id', [['POST', "$prefix/import"], ['POST', "$prefix/import"], ['PATCH', "$prefix/{$gT}_20270308T180000Z"], ['PATCH', "$prefix/{$gA}_20270309"]],
                array_map(static fn(array $r): array => [$r['method'], $r['path']], $sent));
            $bodyT = $sent[2]['body'] ?? [];
            $bodyA = $sent[3]['body'] ?? [];
            $nullKeys = static fn(array $w, array $keys): bool => array_filter($keys, static fn(string $k): bool => !array_key_exists($k, $w) || $w[$k] !== null) === [];
            check('the timed series\' day goes as its dates, its clock fields sent null (3.31)',
                ($bodyT['start']['date'] ?? null) === '2027-03-08' && ($bodyT['end']['date'] ?? null) === '2027-03-09' && $nullKeys($bodyT['start'] ?? [], ['dateTime', 'timeZone']) && $nullKeys($bodyT['end'] ?? [], ['dateTime', 'timeZone']),
                json_encode($bodyT));
            $instant = static fn(?string $t): ?string => $t === null ? null : (new DateTimeImmutable($t))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
            check('the all-day series\' day goes as its clock time, 15:00 to 16:00 Los Angeles, its date sent null (3.38)',
                $instant($bodyA['start']['dateTime'] ?? null) === '2027-03-09 23:00' && $instant($bodyA['end']['dateTime'] ?? null) === '2027-03-10 00:00'
                && ($bodyA['start']['timeZone'] ?? null) === $la && $nullKeys($bodyA['start'] ?? [], ['date']) && $nullKeys($bodyA['end'] ?? [], ['date']),
                json_encode($bodyA));
            checkEq('Google took every write', [200, 200, 200, 200], array_map(static fn(array $r): int => (int) ($r['status'] ?? 0), $sent));
            checkEq('every write sends sendUpdates=none', [], array_values(array_filter($sent, static fn(array $r): bool => ($r['query']['sendUpdates'] ?? null) !== 'none')));

            $recT = $s->fake->records($gT)['20270308T180000Z'] ?? [];
            $recA = $s->fake->records($gA)['20270309'] ?? [];
            checkEq('Google holds each day in its own form: the timed series\' 8 March as a date, the all-day series\' 9 March at 15:00 Los Angeles',
                ['2027-03-08', '2027-03-09', '2027-03-09 23:00', '2027-03-10 00:00'],
                [$recT['start']['date'] ?? null, $recT['end']['date'] ?? null, $instant($recA['start']['dateTime'] ?? null), $instant($recA['end']['dateTime'] ?? null)]);

            // The first poll, through the stand-in (the move's own stopped before any request).
            $cal = $db->one('SELECT * FROM calendars WHERE id = ?', [$local]);
            checkEq('the calendar is a Google calendar now, bound to the stand-in\'s', ['google', GoogleStand::CALENDAR], [$cal['provider'] ?? null, $cal['google_calendar_id'] ?? null]);
            $s->sync->poll($cal);
            $s->sync->poll($db->one('SELECT * FROM calendars WHERE id = ?', [$local]));
            $rows = $db->all('SELECT id, recurrence_parent_id, recurrence_instance_utc, all_day, start_utc, end_utc, google_event_id FROM events WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY id', [$local]);
            checkEq('after two polls the calendar still holds the two series and their two days, each with its Google id', [4, 0],
                [count($rows), count(array_filter($rows, static fn(array $r): bool => (string) ($r['google_event_id'] ?? '') === ''))]);
            $dt = $day($timed);
            $da = $day($allDay);
            checkEq('the timed series\' day is still all-day on 8 March here, at its original key',
                [1, '2027-03-08 00:00:00', '2027-03-09 00:00:00', '2027-03-08 18:00:00', $timed], [(int) ($dt['all_day'] ?? -1), $dt['start_utc'] ?? null, $dt['end_utc'] ?? null, $dt['recurrence_instance_utc'] ?? null, (int) ($dt['recurrence_parent_id'] ?? 0)]);
            checkEq('the all-day series\' day is still 15:00 to 16:00 Los Angeles on 9 March here, at its original key',
                [0, '2027-03-09 23:00:00', '2027-03-10 00:00:00', '2027-03-09 00:00:00', $allDay], [(int) ($da['all_day'] ?? -1), $da['start_utc'] ?? null, $da['end_utc'] ?? null, $da['recurrence_instance_utc'] ?? null, (int) ($da['recurrence_parent_id'] ?? 0)]);
            checkEq('the series themselves keep their form', [0, 1], [(int) $db->scalar('SELECT all_day FROM events WHERE id = ?', [$timed]), (int) $db->scalar('SELECT all_day FROM events WHERE id = ?', [$allDay])]);
            checkEq('nothing is half done', [], $db->all('SELECT id FROM google_pending WHERE user_id = ?', [$u]));
        } finally {
            GoogleStand::forget($db, $u);
        }
    });
};
