<?php

declare(strict_types=1);

// Migrations that change data, run again over rows made the way older
// versions made them. Each block is a scenario(): a throw fails that block,
// and the next still runs.

use BetterCal\Domain\MailIngest;
use BetterCal\Domain\Undo;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $migration = static fn(string $name) => require dirname(__DIR__, 2) . '/migrations/' . $name;

    // 057 numbers the places 055 leaves, so the two are one block. 055's
    // rows are made with columns dropped that the migration puts back: a
    // throw before it runs leaves the schema without them, and the blocks
    // after fail on that too.
    // 055: each folder starts in the order it showed (the calendars'), and
    // lists by hand.
    scenario("migration 055 and 057: a folder's order", function () use ($db, $migration): void {
        $u = freshUser($db);
        $d = domains($db);
        $c1 = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$u]);
        $c2 = (int) $d->calendars->create($u, ['name' => 'Second'])['id'];
        $c3 = (int) $d->calendars->create($u, ['name' => 'Third'])['id'];
        $f1 = (int) $d->folders->create($u, ['name' => 'A'])['id'];
        $f2 = (int) $d->folders->create($u, ['name' => 'B'])['id'];
        $db->run('ALTER TABLE calendar_folders DROP COLUMN position');
        $db->run('ALTER TABLE folders DROP COLUMN sort');
        foreach ([[$c1, $f1], [$c2, $f1], [$c3, $f1], [$c1, $f2], [$c3, $f2]] as [$c, $f]) {
            $db->run('INSERT INTO calendar_folders (calendar_id, folder_id) VALUES (?, ?)', [$c, $f]);
        }
        foreach ([$c1 => 2, $c2 => 0, $c3 => 1] as $c => $p) {
            $db->run('UPDATE calendars SET position = ? WHERE id = ?', [$p, $c]);
        }
        $migration('055_folder_order.php')($db);
        $migration('055_folder_order.php')($db);
        $inFolder = static fn(int $f) => array_map('intval', array_column($db->all('SELECT calendar_id FROM calendar_folders WHERE folder_id = ? ORDER BY position', [$f]), 'calendar_id'));
        checkEq('migration 055: folders keep the order they showed, and run twice it changes nothing', [[$c2, $c3, $c1], [$c3, $c1], ['manual', 'manual']],
            [$inFolder($f1), $inFolder($f2), array_column($db->all('SELECT sort FROM folders WHERE id IN (?, ?) ORDER BY id', [$f1, $f2]), 'sort')]);
        // Cut off after the column was added (that commits on its own) and
        // before any folder was numbered: run again, it numbers them.
        $db->run('ALTER TABLE calendar_folders DROP COLUMN position');
        $db->run('ALTER TABLE calendar_folders ADD COLUMN position INT NOT NULL DEFAULT 0');
        $migration('055_folder_order.php')($db);
        checkEq('migration 055: run again after one cut off before numbering, the folders are numbered', [[$c2, $c3, $c1], [$c3, $c1]], [$inFolder($f1), $inFolder($f2)]);
        // Left half numbered by an older build of this migration (no transaction
        // then): members at 0, 1, 0. Run again, the folder is numbered through
        // in the order it showed; the folder that was numbered is left alone.
        $db->run('UPDATE calendar_folders SET position = 0 WHERE folder_id = ? AND calendar_id IN (?, ?)', [$f1, $c2, $c1]);
        $db->run('UPDATE calendar_folders SET position = 1 WHERE folder_id = ? AND calendar_id = ?', [$f1, $c3]);
        $db->run('UPDATE calendar_folders SET position = 1 WHERE folder_id = ? AND calendar_id = ?', [$f2, $c1]);
        $db->run('UPDATE calendar_folders SET position = 0 WHERE folder_id = ? AND calendar_id = ?', [$f2, $c3]);
        $migration('055_folder_order.php')($db);
        checkEq('migration 055: a folder left half numbered is numbered through; one numbered is left alone', [[$c2, $c3, $c1], [$c3, $c1]], [$inFolder($f1), $inFolder($f2)]);

        // 057: places a step apart, in the same order; again, nothing changes.
        $migration('057_sparse_order.php')($db);
        $migration('057_sparse_order.php')($db);
        $places = static fn(string $sql, array $params) => array_map(static fn($r) => array_map('intval', array_values($r)), $db->all($sql, $params));
        checkEq('migration 057: places a step apart in the same order, and again changes nothing', [
            [[$c2, 1024], [$c3, 2048], [$c1, 3072]], [[$c2, 1024], [$c3, 2048], [$c1, 3072]],
        ], [
            $places('SELECT id, position FROM calendars WHERE user_id = ? ORDER BY position', [$u]),
            $places('SELECT calendar_id, position FROM calendar_folders WHERE folder_id = ? ORDER BY position', [$f1]),
        ]);
    });

    // 058: old placements log only, what recent entries touched filled in,
    // a default calendar for every account.
    // 060 reruns 058 over what 058's block made, with its helpers: one block.
    scenario('migration 058 and 060: what undo stands on, and the default calendar', function () use ($db): void {
        $u = freshUser($db);
        $d = domains($db);
        $first = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$u]);
        $hidden = (int) $d->calendars->create($u, ['name' => 'Hidden first'])['id'];
        $shown = (int) $d->calendars->create($u, ['name' => 'Shown'])['id'];
        $db->run('UPDATE calendars SET visible = 0, position = 1 WHERE id IN (?, ?)', [$hidden, $first]);
        $db->run('UPDATE calendars SET position = 2 WHERE id = ?', [$shown]);
        $db->run('UPDATE users SET settings_json = ? WHERE id = ?', ['{"defaultCalendarId":null}', $u]);
        // Two more accounts: one whose invitations calendar comes first in the
        // list, one with only the invitations calendar.
        $v = freshUser($db);
        $vOwn = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$v]);
        $db->run('UPDATE calendars SET position = ? WHERE id = ?', [5, MailIngest::ensureInviteCalendar($db, $v)]);
        $db->run('UPDATE calendars SET position = 9 WHERE id = ?', [$vOwn]);
        $db->run('UPDATE users SET settings_json = ? WHERE id = ?', ['{}', $v]);
        $w = freshUser($db);
        $db->run('DELETE FROM calendars WHERE user_id = ?', [$w]);
        MailIngest::ensureInviteCalendar($db, $w);
        $db->run('UPDATE users SET settings_json = ? WHERE id = ?', ['{}', $w]);

        $insert = static function (string $entity, int $entityId, ?array $before, ?array $after) use ($db, $u): int {
            return $db->insert('mutations', [
                'user_id' => $u, 'entity' => $entity, 'entity_id' => $entityId, 'op' => 'update', 'source' => 'web',
                'before_json' => $before === null ? null : json_encode(['tables' => $before]),
                'after_json' => $after === null ? null : json_encode(['tables' => $after]),
            ]);
        };
        $place = $insert('calendar_place', $shown, ['calendars' => [['id' => $shown, 'position' => 2]]], ['calendars' => [['id' => $shown, 'position' => 1]]]);
        $rename = $insert('calendar', $shown, ['calendars' => [['id' => $shown, 'name' => 'Old']]], ['calendars' => [['id' => $shown, 'name' => 'New']]]);
        $google = $insert('event', 4242, null, null);
        // Older than the week, as when the worker's prune never ran: one that
        // still has its snapshots can be undone, so it's filled in too; one
        // without can't be, and is left.
        $old = $insert('event', 777, ['events' => [['id' => 777, 'title' => 'Was']]], ['events' => [['id' => 777, 'title' => 'Is']]]);
        $gone = $insert('event', 778, null, null);
        $db->run('UPDATE mutations SET created_at = ? WHERE id IN (?, ?)', [gmdate('Y-m-d H:i:s', time() - 30 * 86400), $old, $gone]);
        $db->run('DELETE FROM mutation_touches WHERE mutation_id IN (?, ?, ?, ?, ?)', [$place, $rename, $google, $old, $gone]);

        $said = (require dirname(__DIR__, 2) . '/migrations/058_undo_ground.php')($db);
        $touches = static fn(int $id): array => array_map(static fn($r) => [$r['row_key'], $r['col']],
            $db->all('SELECT row_key, col FROM mutation_touches WHERE mutation_id = ? ORDER BY row_key, col', [$id]));
        checkEq('migration 058: a placement recorded the old way is log only', [null, null],
            array_values($db->one('SELECT before_json, after_json FROM mutations WHERE id = ?', [$place])));
        checkEq('migration 058: what a recent entry touched is filled in from its snapshots', Undo::touchesOf(
            ['calendars' => [['id' => $shown, 'name' => 'Old']]], ['calendars' => [['id' => $shown, 'name' => 'New']]]), $touches($rename));
        checkEq('migration 058: one that kept no snapshots touched all of its item', [['events:4242', '*']], $touches($google));
        checkEq('migration 058: an older entry that still has its snapshots is filled in; one without is left', [Undo::touchesOf(
            ['events' => [['id' => 777, 'title' => 'Was']]], ['events' => [['id' => 777, 'title' => 'Is']]]), []], [$touches($old), $touches($gone)]);
        $stored = static fn(int $userId): mixed => json_decode((string) $db->scalar('SELECT settings_json FROM users WHERE id = ?', [$userId]), true)['defaultCalendarId'] ?? null;
        checkEq('migration 058: the default calendar is the one the app was picking: the first shown in the list', $shown, $stored($u));
        checkEq('migration 058: never the invitations calendar: the own one behind it in the list, or nothing when it is the only one', [$vOwn, null], [$stored($v), $stored($w)]);
        $rows = (int) $db->scalar('SELECT COUNT(*) FROM mutation_touches');
        $again = (require dirname(__DIR__, 2) . '/migrations/058_undo_ground.php')($db);
        check('migration 058: run again, nothing changes', $rows === (int) $db->scalar('SELECT COUNT(*) FROM mutation_touches')
            && str_contains($again, '(0 entries filled in') && str_contains($again, '0 old placements') && str_contains($again, '(0 set)'), $again);
        check('migration 058: says what it did', str_contains($said, 'old placements'), $said);
        // 060 is 058 again, for installs that ran it from a development build:
        // an entry it left without touches gets them; where it ran as released, nothing changes.
        $late = $insert('event', 779, ['events' => [['id' => 779, 'title' => '01']]], ['events' => [['id' => 779, 'title' => '1']]]);
        $db->run('DELETE FROM mutation_touches WHERE mutation_id = ?', [$late]);
        $rows = (int) $db->scalar('SELECT COUNT(*) FROM mutation_touches');
        $againSaid = (require dirname(__DIR__, 2) . '/migrations/060_undo_ground_again.php')($db);
        checkEq('migration 060: an entry 058 left without touches on a development build gets them, and nothing else changes',
            [[['events:779', 'title']], $rows + 1, true], [$touches($late), (int) $db->scalar('SELECT COUNT(*) FROM mutation_touches'), str_contains($againSaid, 'stands on')]);
    });

    // 061: the newest published version beside the verified one; run again, the column is still one.
    scenario('migration 061: the newest version column', function () use ($db, $migration): void {
        $newestSaid = $migration('061_update_newest.php')($db);
        $migration('061_update_newest.php')($db);
        checkEq('migration 061: adds the newest version column once and says so', [1, true], [
            (int) $db->scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_update_state' AND COLUMN_NAME = 'newest_version'"),
            str_contains($newestSaid, 'step towards'),
        ]);
    });

    // 062: pieces as large as the snapshots; where a development build made
    // the column smaller, run again it is widened, and again changes nothing.
    scenario('migration 062: pieces as large as the snapshots', function () use ($db, $migration): void {
        $rowsType = static fn(): array => array_values($db->one("SELECT DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutation_rows' AND COLUMN_NAME = 'rows_json'") ?? []);
        $fresh = $rowsType();
        $db->run('ALTER TABLE mutation_rows MODIFY rows_json MEDIUMTEXT NOT NULL');
        $migration('062_mutation_rows.php')($db);
        $widened = $rowsType();
        $migration('062_mutation_rows.php')($db);
        checkEq('migration 062: rows_json is LONGTEXT, a MEDIUMTEXT one from a development build is widened, and run again nothing changes',
            [['longtext', 'NO'], ['longtext', 'NO'], ['longtext', 'NO']], [$fresh, $widened, $rowsType()]);
    });

    // 064: keys the library's readings wrote, moved onto the app's own
    // expander. Rows made the way 1.4 made them: a weekday list at 02:30 in
    // Los Angeles kept the spring gap's 03:30 from 15 Mar 2027 on (10:30Z,
    // now 09:30Z), and a yearly 02:30 in Berlin read 31 Oct 2027's repeated
    // clock at its second instant (01:30Z, now 00:30Z).
    scenario("migration 064: keys moved onto the app's own expander", function () use ($db, $migration): void {
        $u = freshUser($db);
        $d = domains($db);
        $home = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$u]);
        $series = static fn(string $title, string $start, string $end, string $rrule, string $tz): int => (int) $d->events->create($u, ['calendarId' => $home, 'title' => $title, 'start' => $start, 'end' => $end, 'allDay' => false, 'tzid' => $tz, 'rrule' => $rrule])['eventId'];
        $old = static function (int $master, string $newKey, string $oldKey, ?string $start = null, string $own = '["title"]') use ($d, $db, $u): int {
            $d->events->patch($u, $master, ['scope' => 'this', 'instanceStart' => str_replace(' ', 'T', $newKey) . 'Z', 'title' => 'Sample, edited']);
            $id = (int) $db->scalar('SELECT id FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ?', [$master, $newKey]);
            $start ??= $oldKey;
            $db->run('UPDATE events SET recurrence_instance_utc = ?, start_utc = ?, end_utc = ?, override_fields = ? WHERE id = ?',
                [$oldKey, $start, \BetterCal\Support\Time::toDb(\BetterCal\Support\Time::fromDb($start)->modify('+1 hour')), $own, $id]);
            return $id;
        };
        $run = $series('Sample run', '2027-03-01T02:30:00-08:00', '2027-03-01T03:30:00-08:00', 'FREQ=DAILY;BYDAY=MO,WE,FR', 'America/Los_Angeles');
        $inherits = $old($run, '2027-03-17 09:30:00', '2027-03-17 10:30:00');
        $owns = $old($run, '2027-03-24 09:30:00', '2027-03-24 10:30:00', '2027-03-24 16:00:00', '["time","title"]');
        $stray = $old($run, '2027-03-29 09:30:00', '2027-03-29 12:00:00', null, '["time","title"]');
        $db->run('UPDATE events SET exdates_json = ? WHERE id = ?', [json_encode(['2027-03-19 10:30:00', '2027-03-01 10:30:00']), $run]);
        $fold = $series('Sample fold', '2026-10-31T02:30:00+01:00', '2026-10-31T03:30:00+01:00', 'FREQ=YEARLY;COUNT=3', 'Europe/Berlin');
        $foldRow = $old($fold, '2027-10-31 00:30:00', '2027-10-31 01:30:00');
        $row = static fn(int $id): array => array_values($db->one('SELECT recurrence_instance_utc, start_utc FROM events WHERE id = ?', [$id]));
        $before = [$row($inherits), $row($owns), $row($stray), $row($foldRow), $db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$run])];
        $dry = $migration('064_series_keys_one_expander.php')($db, true);
        checkEq('migration 064: the dry run counts what it would move and writes nothing', [true, $before],
            [str_contains($dry, 'dry run: 2 series, 3 edited-day keys and 1 skip would move'), [$row($inherits), $row($owns), $row($stray), $row($foldRow), $db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$run])]]);
        $said = $migration('064_series_keys_one_expander.php')($db);
        checkEq('migration 064: each old key moves to its day\'s occurrence, an inherited time with it, an own time and a stray key staying, the skip moved and the series\' own skip kept', [
            ['2027-03-17 09:30:00', '2027-03-17 09:30:00'], ['2027-03-24 09:30:00', '2027-03-24 16:00:00'], ['2027-03-29 12:00:00', '2027-03-29 12:00:00'],
            ['2027-10-31 00:30:00', '2027-10-31 00:30:00'], ['2027-03-19 09:30:00', '2027-03-01 10:30:00'], true,
        ], [$row($inherits), $row($owns), $row($stray), $row($foldRow), json_decode((string) $db->scalar('SELECT exdates_json FROM events WHERE id = ?', [$run]), true),
            // Other accounts' series in this database add to what is left, never to what moves.
            str_contains($said, '2 series, 3 edited-day keys and 1 skip moved; ')]);
        $again = $migration('064_series_keys_one_expander.php')($db, true);
        checkEq('migration 064: run again, nothing is left to move', true, str_contains($again, 'dry run: 0 series, 0 edited-day keys and 0 skips would move'));
        $shown = array_column((new \BetterCal\Domain\Recurrence())->expand($db->one('SELECT * FROM events WHERE id = ?', [$run]), $db->all('SELECT * FROM events WHERE recurrence_parent_id = ?', [$run]),
            \BetterCal\Support\Time::fromDb('2027-03-16 00:00:00'), \BetterCal\Support\Time::fromDb('2027-03-20 00:00:00')), 'instanceUtc');
        checkEq('migration 064: the edited day shows once, in place of its occurrence, and the skip skips', ['2027-03-17 09:30:00'], $shown);
    });

    // 072: an edited day stored where its own list puts it. A monthly series
    // at 02:30 in Los Angeles; its 14 Mar 2027 shows at 03:30 (the spring
    // gap). Moved by its date alone to 17 Mar, "this event" now stores it at
    // the series' 02:30 (09:30Z) with its date its own; the version before
    // stored the 03:30 the request showed (10:30Z), which the migration
    // places again. A day that owns its time stays where it is.
    scenario('migration 072: an edited day placed by its own list', function () use ($db, $migration): void {
        $u = freshUser($db);
        $d = domains($db);
        $cal = (int) $db->scalar('SELECT id FROM calendars WHERE user_id = ?', [$u]);
        $id = (int) $d->events->create($u, ['calendarId' => $cal, 'title' => 'Sample monthly', 'start' => '2027-01-14T02:30:00-08:00', 'end' => '2027-01-14T03:30:00-08:00',
            'allDay' => false, 'tzid' => 'America/Los_Angeles', 'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=14'])['eventId'];
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-03-14T03:30:00-07:00', 'start' => '2027-03-17T03:30:00-07:00', 'end' => '2027-03-17T04:30:00-07:00']);
        $d->events->patch($u, $id, ['scope' => 'this', 'instanceStart' => '2027-04-14T02:30:00-07:00', 'start' => '2027-04-14T05:00:00-07:00', 'end' => '2027-04-14T06:00:00-07:00']);
        $row = static fn(string $key): array => array_values($db->one('SELECT start_utc, end_utc, override_fields FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ?', [$id, $key]) ?? []);
        checkEq('"this event": a gap day moved by its date alone is stored at the series\' clock on its new date, its date its own', ['2027-03-17 09:30:00', '2027-03-17 10:30:00', '["date"]'], $row('2027-03-14 10:30:00'));
        checkEq('"this event": a day given its own clock keeps it', ['2027-04-14 12:00:00', '2027-04-14 13:00:00', '["time"]'], $row('2027-04-14 09:30:00'));
        // The row as the version before stored it.
        $db->run('UPDATE events SET start_utc = ?, end_utc = ? WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ?', ['2027-03-17 10:30:00', '2027-03-17 11:30:00', $id, '2027-03-14 10:30:00']);
        $said = $migration('072_edited_days_at_their_own_list.php')($db);
        checkEq('migration 072: the day stored at the shown clock is placed at the series\' clock, the day of its own time stays', [
            ['2027-03-17 09:30:00', '2027-03-17 10:30:00', '["date"]'], ['2027-04-14 12:00:00', '2027-04-14 13:00:00', '["time"]'], true,
        ], [$row('2027-03-14 10:30:00'), $row('2027-04-14 09:30:00'), (bool) preg_match('/^[1-9]\d* edited days? placed by/', $said)]);
        $again = $migration('072_edited_days_at_their_own_list.php')($db);
        checkEq('migration 072: run again, it places nothing', true, str_starts_with($again, '0 edited days placed'));
    });
};
