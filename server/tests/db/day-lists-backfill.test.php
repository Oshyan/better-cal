<?php

declare(strict_types=1);

// Migration 078: every edited day's row a feed poll in 1.5.0 to 1.5.4 left
// with no own list gets the list migration 063 would have given it, by
// 063's own logic (equality with its series' row, tags and people
// compared; [] where that row is missing), live rows and deleted ones,
// twice over without a change the second time, and in bounded memory on a
// large calendar. The rows are built by hand (raw SQL): that 1.5.x path
// needs a feed whose series' record is unreadable, and no writer here
// leaves such a row now (link-ownership's poll blocks). Invented data.

use BetterCal\Domain\Settings;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

return static function (Db $db): void {
    $fill = require dirname(__DIR__, 2) . '/migrations/078_day_lists_filled.php';
    $listOf = static fn(int $id): ?string => $db->scalar('SELECT override_fields FROM events WHERE id = ?', [$id]);
    $weekly = static fn(int $u, int $cal, string $title, array $more = []): int => (int) domains($db)->events->create($u, ['calendarId' => $cal, 'title' => $title,
        'start' => '2027-01-04T09:00:00Z', 'end' => '2027-01-04T10:00:00Z', 'tzid' => 'UTC', 'rrule' => 'FREQ=WEEKLY;COUNT=8'] + $more)['eventId'];

    scenario('day lists backfill: each day left with no own list gets the list 063 would have given it, live or deleted, and a second run changes nothing', function () use ($db, $fill, $listOf, $weekly): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $id = $weekly($u, $cal, 'Sample weekly', ['tagNames' => ['Sample A'], 'personNames' => ['Alex Example']]);
        $day = static function (string $key, array $in) use ($d, $u, $id, $db): int {
            $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => str_replace(' ', 'T', $key) . 'Z'] + $in);
            return (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ?', [$id, $key]);
        };
        $equal = $day('2027-01-11 09:00:00', ['description' => 'Sample notes']);
        $db->run("UPDATE events SET description = (SELECT description FROM (SELECT description FROM events WHERE id = ?) m) WHERE id = ?", [$id, $equal]);
        $ownTags = $day('2027-01-18 09:00:00', ['tagNames' => ['Sample B']]);
        $ownPeople = $day('2027-01-25 09:00:00', ['personNames' => ['Sam Sample']]);
        $ownTitle = $day('2027-02-01 09:00:00', ['title' => 'Sample own day']);
        $deleted = $day('2027-02-08 09:00:00', ['title' => 'Sample deleted day']);
        $db->run('UPDATE events SET deleted_at = ? WHERE id = ?', ['2027-01-01 00:00:00', $deleted]);
        // A series deleted with its day, and a day whose series row is gone (no foreign key keeps it).
        $gone = $weekly($u, $cal, 'Sample gone weekly');
        $d->events->patch($u, $gone, ['scope' => 'this', 'instanceStart' => '2027-01-11T09:00:00Z', 'title' => 'Sample orphan day']);
        $orphan = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$gone]);
        $underDeleted = $weekly($u, $cal, 'Sample deleted weekly');
        $d->events->patch($u, $underDeleted, ['scope' => 'this', 'instanceStart' => '2027-01-11T09:00:00Z', 'title' => 'Sample day of a deleted series']);
        $dayOfDeleted = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ?', [$underDeleted]);
        $db->run('UPDATE events SET deleted_at = ? WHERE id IN (?, ?)', ['2027-01-01 00:00:00', $underDeleted, $dayOfDeleted]);
        $all = [$equal, $ownTags, $ownPeople, $ownTitle, $deleted, $orphan, $dayOfDeleted];
        $stamps = [];
        foreach ($all as $r) {
            $stamps[$r] = $db->scalar('SELECT updated_at FROM events WHERE id = ?', [$r]);
        }
        [$in, $params] = Db::in($all);
        $db->run("UPDATE events SET override_fields = NULL, updated_at = updated_at WHERE id IN $in", $params);
        // The orphan's series row goes after the lists are cleared, as a hard delete under a carried day left it.
        $db->run('DELETE FROM events WHERE id = ?', [$gone]);

        $said = $fill($db);
        checkEq('day lists backfill: by equality with its series, tags and people compared; [] where the series row is missing; a deleted day and a day of a deleted series too', [
            '[]', '["tags"]', '["people"]', '["title"]', '["title"]', '[]', '["title"]',
        ], array_map($listOf, $all));
        check('day lists backfill: it says how many it filled', preg_match('/filled \d+ edited day/', $said) === 1, $said);
        $unchanged = [];
        foreach ($all as $r) {
            $unchanged[$r] = $db->scalar('SELECT updated_at FROM events WHERE id = ?', [$r]);
        }
        checkEq('day lists backfill: nothing reads as changed (updated_at kept)', $stamps, $unchanged);
        checkEq('day lists backfill: a second run fills nothing and changes nothing', ['filled 0 edited days', array_map($listOf, $all)], [preg_replace('/^.*(filled \d+ edited days?)$/', '$1', $fill($db)), array_map($listOf, $all)]);
        // The orphan stands under a series row that is gone, which the suite's structure check names: removed here, as the case is made.
        $db->run('DELETE FROM events WHERE id = ?', [$orphan]);
    });

    // A 1.5.5 development build could record an entry over a day row that had
    // no list yet; after 078 its snapshot is the one place that form is left.
    // Built here by editing an entry's snapshot by hand: its undo is refused,
    // with the reason, before anything is written.
    scenario('day lists backfill: an entry whose snapshot holds a day row with no own list is refused, with its reason, and changes nothing', function () use ($db, $weekly): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $id = $weekly($u, $cal, 'Sample weekly');
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-11T09:00:00Z', 'title' => 'Sample day']);
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-01-11T09:00:00Z', 'description' => 'Sample notes']);
        $entry = lastEntry($db, $u);
        $before = json_decode((string) $db->scalar('SELECT before_json FROM mutations WHERE id = ?', [$entry]), true);
        foreach ($before['tables']['events'] as $i => $r) {
            if (!empty($r['recurrence_parent_id'])) {
                $before['tables']['events'][$i]['override_fields'] = null;
            }
        }
        $db->run('UPDATE mutations SET before_json = ? WHERE id = ?', [json_encode($before), $entry]);
        $rows = $db->all('SELECT * FROM events WHERE user_id = ? ORDER BY id', [$u]);
        $refused = null;
        try {
            $d->undo->undoById($u, $entry);
        } catch (BetterCal\Http\HttpError $e) {
            $refused = [$e->errorCode, $e->getMessage()];
        }
        checkEq('day lists backfill: the undo is refused with the reason', ['not_undoable', BetterCal\Domain\Undo::BEFORE_LISTS], $refused);
        checkEq('day lists backfill: nothing changed', $rows, $db->all('SELECT * FROM events WHERE user_id = ? ORDER BY id', [$u]));
    });

    scenario('day lists backfill: a large calendar of days with no list fills in bounded memory', function () use ($db, $fill, $weekly): void {
        $u = freshUser($db);
        $cal = Settings::defaultCalendarId($db, $u);
        $masters = [];
        for ($m = 0; $m < 40; $m++) {
            $masters[] = $weekly($u, $cal, 'Sample weekly ' . $m, ['tagNames' => ['Sample A']]);
        }
        // 20,000 day rows with no list, 500 under each series, at keys of their own.
        $made = 0;
        foreach ($masters as $mi => $mid) {
            $master = $db->one('SELECT * FROM events WHERE id = ?', [$mid]);
            for ($chunk = 0; $chunk < 5; $chunk++) {
                $values = [];
                $params = [];
                for ($k = 0; $k < 100; $k++) {
                    $n = $chunk * 100 + $k;
                    $start = (new DateTimeImmutable('2027-01-04 09:00:00', new DateTimeZone('UTC')))->modify('+' . $n . ' days');
                    $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)';
                    array_push($params, $u, $cal, $master['uid'], 'Sample day ' . $n, Time::toDb($start), Time::toDb($start->modify('+1 hour')), 0, 'UTC',
                        $mid, Time::toDb($start), 'local', 'web', '2027-01-01 00:00:00');
                    $made++;
                }
                $db->run('INSERT INTO events (user_id, calendar_id, uid, title, start_utc, end_utc, all_day, tzid, recurrence_parent_id, recurrence_instance_utc, source, override_fields, created_via, updated_at) VALUES ' . implode(', ', $values), $params);
            }
        }
        unset($values, $params);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $said = $fill($db);
        $peak = memory_get_peak_usage() - $base;
        check('day lists backfill: every one of 20,000 days got its list', (int) $db->scalar('SELECT COUNT(*) FROM events WHERE user_id = ? AND recurrence_parent_id IS NOT NULL AND override_fields IS NULL', [$u]) === 0, $said);
        check('day lists backfill: 20,000 days fill within 16 MB of memory above where it started', $peak < 16 * 1024 * 1024, 'peak ' . round($peak / 1048576, 1) . ' MB');
        echo 'day lists backfill: 20,000 days, peak ' . round($peak / 1048576, 2) . " MB above the start\n";
        checkEq('day lists backfill: each day owns its title, and its tags, which differ from its series\' (it has none)', '["title","tags"]', $db->scalar('SELECT override_fields FROM events WHERE user_id = ? AND recurrence_parent_id IS NOT NULL LIMIT 1', [$u]));
        unset($made);
    });
};
