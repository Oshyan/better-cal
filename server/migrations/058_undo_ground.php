<?php

declare(strict_types=1);

use BetterCal\Domain\Settings;
use BetterCal\Domain\Undo;
use BetterCal\Infra\Db;

return static function (Db $db): string {
    // A calendar's place recorded the old way (entity calendar_place, an
    // unreleased build): undo can't put it back any more. Log only.
    $placed = $db->run("UPDATE mutations SET before_json = NULL, after_json = NULL WHERE entity = 'calendar_place' AND (before_json IS NOT NULL OR after_json IS NOT NULL)")->rowCount();

    // What each entry that can still be undone changed, as undo records it
    // now: for one from before undo recorded it (056) all of it, for one
    // since then anything an earlier build left out. An older entry's undo
    // is stale on these as on any later change. One from before 056 that
    // kept no snapshots (a change sent to Google) is taken to have changed
    // all of its item, as undo assumed then. Every entry of the last week,
    // and any older one that still has its snapshots (the worker's prune
    // hasn't run): with no touches it would undo over anything.
    $tables = ['event' => 'events', 'calendar' => 'calendars', 'folder' => 'folders', 'person' => 'people',
        'tag' => 'tags', 'filter' => 'filters', 'saved_view' => 'saved_views', 'availability' => 'availability', 'outfeed' => 'out_feeds'];
    $since = gmdate('Y-m-d H:i:s', time() - 8 * 86400);
    $filled = 0;
    $after = 0;
    while (true) {
        $rows = $db->all(
            'SELECT m.id, m.entity, m.entity_id, m.before_json, m.after_json,
                    EXISTS (SELECT 1 FROM mutation_touches t WHERE t.mutation_id = m.id) AS has_touches
             FROM mutations m WHERE m.id > ? AND (m.created_at >= ? OR m.before_json IS NOT NULL OR m.after_json IS NOT NULL)
             ORDER BY m.id LIMIT 200',
            [$after, $since]
        );
        if ($rows === []) {
            break;
        }
        foreach ($rows as $m) {
            $after = (int) $m['id'];
            $decode = static function (?string $json): ?array {
                $d = $json !== null ? json_decode($json, true) : null;
                return is_array($d) && is_array($d['tables'] ?? null) ? $d['tables'] : null;
            };
            $before = $decode($m['before_json']);
            $afterTables = $decode($m['after_json']);
            $touches = Undo::touchesOf($before, $afterTables);
            if ((string) $m['entity'] === 'plugin_edit') {
                $touches = Undo::timeTouches((int) $m['entity_id']);
            } elseif ((int) $m['has_touches'] === 1) {
                // Recorded since 056: what its snapshots show is there; this
                // adds only what an earlier build left out (what rows belong to).
            } elseif ($touches === [] && $before === null && $afterTables === null && isset($tables[(string) $m['entity']]) && (int) $m['entity_id'] > 0) {
                $touches = [[Undo::keyOf($tables[(string) $m['entity']], ['id' => (int) $m['entity_id']]), '*']];
            }
            $unique = [];
            foreach ($touches as [$row, $col]) {
                $unique[$row . "\n" . $col] = [mb_substr($row, 0, 160), mb_substr($col, 0, 64)];
            }
            $added = 0;
            foreach (array_chunk(array_values($unique), 200) as $chunk) {
                $params = [];
                foreach ($chunk as [$row, $col]) {
                    array_push($params, (int) $m['id'], $row, $col);
                }
                $added += $db->run($db->insertIgnore() . ' INTO mutation_touches (mutation_id, row_key, col) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?)')), $params)->rowCount();
            }
            if ($added > 0) {
                $filled++; // not one that had these already (a rerun)
            }
        }
    }

    // Where new events go, set for every account that has a local calendar
    // (it's always set from now on): the one the app was picking, the
    // first shown local calendar in the list, unless one was chosen.
    $set = 0;
    foreach ($db->all('SELECT id, settings_json FROM users') as $user) {
        $settings = is_string($user['settings_json']) ? json_decode($user['settings_json'], true) : null;
        $chosen = is_array($settings) ? ($settings['defaultCalendarId'] ?? null) : null;
        if (is_numeric($chosen) && $db->scalar("SELECT id FROM calendars WHERE id = ? AND user_id = ? AND kind = 'local'", [(int) $chosen, (int) $user['id']]) !== null) {
            continue;
        }
        $pick = $db->scalar("SELECT id FROM calendars WHERE user_id = ? AND kind = 'local' ORDER BY visible DESC, position, id LIMIT 1", [(int) $user['id']]);
        if ($pick !== null) {
            Settings::setDefaultCalendar($db, (int) $user['id'], (int) $pick);
            $set++;
        }
    }

    return "Undo knows what each change stands on ($filled entries filled in, $placed old placements made log-only); every account has a default calendar ($set set).";
};
