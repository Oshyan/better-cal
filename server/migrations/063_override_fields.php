<?php

declare(strict_types=1);

use BetterCal\Domain\Series\OwnFields;
use BetterCal\Infra\Db;

/**
 * An edited day's own fields (docs/design/series-model.md, Own Fields):
 * events.override_fields, the JSON list of the fields an override row set
 * for its day ("date", "time", "title", ...), NULL on masters and on events
 * that don't repeat. A series-wide change reaches every field an edited day
 * doesn't own.
 *
 * Every existing override row is filled once by comparing it with its
 * master: what differs is its own. No value changes, so nothing shows
 * differently. Runs in batches, and only on rows whose list is still NULL,
 * so it can be run again.
 */
return static function (Db $db): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sqlite = $driver === 'sqlite';
    $hasTable = static fn(string $t): bool => $sqlite
        ? (int) $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$t]) > 0
        : (int) $db->scalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]) > 0;
    if (!$hasTable('events')) {
        return 'no events table';
    }
    $columns = $sqlite
        ? array_column($db->all('PRAGMA table_info(`events`)'), 'name')
        : array_column($db->all('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['events']), 'name');
    $added = false;
    if (!in_array('override_fields', $columns, true)) {
        $db->run('ALTER TABLE `events` ADD COLUMN `override_fields` ' . ($sqlite ? 'TEXT NULL' : 'TEXT NULL DEFAULT NULL'));
        $added = true;
    }
    $tags = $hasTable('event_tags');
    $people = $hasTable('event_people');
    $ids = static function (string $table, string $col, array $eventIds) use ($db): array {
        $out = [];
        if ($eventIds === []) {
            return $out;
        }
        [$in, $params] = Db::in($eventIds);
        foreach ($db->all("SELECT event_id, $col AS v FROM $table WHERE event_id IN $in", $params) as $r) {
            $out[(int) $r['event_id']][] = (string) $r['v'];
        }
        return $out;
    };
    $filled = 0;
    $after = 0;
    while (true) {
        $rows = $db->all(
            'SELECT * FROM events WHERE recurrence_parent_id IS NOT NULL AND override_fields IS NULL AND id > ? ORDER BY id LIMIT 500',
            [$after]
        );
        if ($rows === []) {
            break;
        }
        $after = (int) end($rows)['id'];
        $masterIds = array_values(array_unique(array_map(static fn(array $r): int => (int) $r['recurrence_parent_id'], $rows)));
        [$in, $params] = Db::in($masterIds);
        $masters = [];
        foreach ($db->all("SELECT * FROM events WHERE id IN $in", $params) as $m) {
            $masters[(int) $m['id']] = $m;
        }
        $all = array_merge($masterIds, array_map(static fn(array $r): int => (int) $r['id'], $rows));
        $tagSets = $tags ? $ids('event_tags', 'tag_id', $all) : [];
        $peopleSets = $people ? $ids('event_people', 'person_id', $all) : [];
        $db->tx(static function () use ($db, $rows, $masters, $tags, $people, $tagSets, $peopleSets, &$filled): void {
            foreach ($rows as $row) {
                $master = $masters[(int) $row['recurrence_parent_id']] ?? null;
                if ($master === null) {
                    $own = [];
                } else {
                    $id = (int) $row['id'];
                    $mid = (int) $master['id'];
                    $own = OwnFields::infer(
                        $row, $master,
                        $tags ? ($tagSets[$id] ?? []) : null, $tags ? ($tagSets[$mid] ?? []) : null,
                        $people ? ($peopleSets[$id] ?? []) : null, $people ? ($peopleSets[$mid] ?? []) : null,
                    );
                }
                // The list only: updated_at stays, so nothing reads as changed.
                $db->run('UPDATE events SET override_fields = ? WHERE id = ? AND override_fields IS NULL', [OwnFields::encode($own), (int) $row['id']]);
                $filled++;
            }
        });
    }
    return ($added ? 'added events.override_fields' : 'events.override_fields already present') . '; filled ' . $filled . ' edited day' . ($filled === 1 ? '' : 's');
};
