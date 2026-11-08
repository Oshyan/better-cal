<?php

declare(strict_types=1);

use BetterCal\Dav\ChangeLog;
use BetterCal\Domain\Series\OwnFields;
use BetterCal\Infra\Db;

/**
 * Edited days placed by their own lists (docs/design/series-model.md, Own
 * Fields). A stored edited day starts on its own date where it owns one,
 * else its occurrence's day, at its own clock where it owns its time, else
 * the series' clock, read in its zone by the gap and repeated-hour rule
 * (OwnFields::placed). Up to 1.5.4 a "this event" edit stored the clock the
 * request showed: an occurrence in a spring-forward gap, shown at the clock
 * plus the gap, moved by its date alone to an ordinary day was stored an
 * hour off the series' clock while its list said its time was inherited.
 *
 * Every timed edited day of a timed series on a local calendar whose list
 * is known and leaves out its time is placed again by the rule; the rows
 * already where it puts them are left alone, so a database with none is
 * unchanged, and a run again changes nothing. Rows on Google or plugin
 * calendars are left as they are: their own lists come from the provider's
 * answer, and a migration sends nothing there. The undo journal is not
 * rewritten: an undo puts back the row as it was before the edit it undoes.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $hasTable = static fn(string $t): bool => $driver === 'sqlite'
        ? (int) $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$t]) > 0
        : (int) $db->scalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]) > 0;
    if (!$hasTable('events') || !$hasTable('calendars')) {
        return 'nothing to read';
    }
    $placed = 0;
    $read = 0;
    $after = 0;
    while (true) {
        $rows = $db->all(
            "SELECT e.* FROM events e JOIN calendars c ON c.id = e.calendar_id
              WHERE e.recurrence_parent_id IS NOT NULL AND e.deleted_at IS NULL AND e.all_day = 0
                AND e.override_fields IS NOT NULL AND c.kind = 'local' AND e.id > ?
              ORDER BY e.id LIMIT 500",
            [$after]
        );
        if ($rows === []) {
            break;
        }
        $after = (int) end($rows)['id'];
        $read += count($rows);
        $masterIds = array_values(array_unique(array_map(static fn(array $r): int => (int) $r['recurrence_parent_id'], $rows)));
        [$in, $params] = Db::in($masterIds);
        $masters = [];
        foreach ($db->all("SELECT * FROM events WHERE id IN $in AND deleted_at IS NULL", $params) as $m) {
            $masters[(int) $m['id']] = $m;
        }
        $db->tx(static function () use ($db, $rows, $masters, &$placed): void {
            $touched = [];
            foreach ($rows as $row) {
                $master = $masters[(int) $row['recurrence_parent_id']] ?? null;
                $own = OwnFields::decode($row['override_fields']);
                if ($master === null || (int) $master['all_day'] === 1 || $own === null || in_array('time', $own, true)) {
                    continue;
                }
                $to = OwnFields::placed($row, $master, $own);
                if ($to['start_utc'] === $row['start_utc'] && $to['end_utc'] === $row['end_utc'] && $to['tzid'] === $row['tzid']) {
                    continue;
                }
                $db->run('UPDATE events SET start_utc = ?, end_utc = ?, tzid = ? WHERE id = ?', [$to['start_utc'], $to['end_utc'], $to['tzid'], (int) $row['id']]);
                $placed++;
                $touched[(int) $master['id']] = $master;
            }
            foreach ($touched as $master) {
                ChangeLog::record($db, (int) $master['calendar_id'], (string) $master['uid'], ChangeLog::OP_MODIFY);
            }
        });
    }
    return sprintf('%d edited day%s placed by %s own list (of %d read)', $placed, $placed === 1 ? '' : 's', $placed === 1 ? 'its' : 'their', $read);
};
