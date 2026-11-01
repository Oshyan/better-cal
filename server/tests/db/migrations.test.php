<?php

declare(strict_types=1);

// Migrations that change data, run again over rows made the way older
// versions made them.

use BetterCal\Domain\MailIngest;
use BetterCal\Domain\Undo;
use BetterCal\Infra\Db;

return static function (Db $db): void {
    $migration = static fn(string $name) => require dirname(__DIR__, 2) . '/migrations/' . $name;

    // 055: each folder starts in the order it showed (the calendars'), and
    // lists by hand.
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

    // 058: old placements log only, what recent entries touched filled in,
    // a default calendar for every account.
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
};
