<?php

declare(strict_types=1);

use BetterCal\Dav\ChangeLog;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Series\Cal;
use BetterCal\Infra\Db;
use BetterCal\Support\Time;

/**
 * Stored keys on the app's own expander (1.5.0). An edited day's key
 * (events.recurrence_instance_utc) and a skip (a master's exdates_json) name
 * an occurrence by its instant. Up to 1.5.0 the expander was sabre/vobject's
 * stepping, whose readings the new expander doesn't share: a weekday list
 * kept the clock a spring gap pushed it to for the rest of the series, a
 * rule naming its hour drifted by a half-hour change, a yearly or monthly
 * clock in a repeated hour was its second instant, a weekly or daily rule
 * with BYHOUR left its BYMINUTE unread. A key such a reading wrote names no
 * occurrence now, so the edited day would show as well as the occurrence it
 * edits, and a skip would skip nothing.
 *
 * The rule: a key that is an occurrence of the old reading (sabre's stepping
 * from the stored start, for the frequencies the old expander left to it)
 * and not of the new one, on a local day where the new expansion has
 * exactly one occurrence, moves to that occurrence. Every key a series
 * stores is a candidate, its first and last occurrences included; the old
 * reading is walked from the start to the last of them, and no further than
 * the series' own COUNT or UNTIL. An edited day whose time is inherited (not
 * one of its own fields) moves with its key, so it shows where the series
 * does that day; one that owns its time stays where it shows. Two keys
 * moving to one: an edited day stays where it is (two rows can't share a
 * key), a skip is the same skip once. Every other key is left as it is: one
 * of the new expansion, an old one on a day the new one gives several
 * occurrences or none (stranded), or a key no expander gave. Timed series
 * only: an all-day series' keys are its UTC dates, which no clock rule moves.
 *
 * The keys are rewritten wherever they are stored: the live rows, and the
 * undo journal's snapshots of them (an entry's before and after, and the
 * rows it keeps in pieces), by the same mapping, in the series' own
 * transaction, so an undo puts back keys of the new reading. A snapshot's
 * keys are read by the series row as it stood for that snapshot (a rule
 * since changed, or made all-day, reads as it was then): the entry's own
 * when it keeps one, else the journal's (the state the latest earlier entry
 * left, else the one the next later entry found, a calendar delete's
 * included), else the live one. An entry with a stranded key in a
 * snapshot, or with days of a series no snapshot or live row keeps, is made
 * log-only, saying why: its undo would put back an identity no occurrence
 * has, or one never read.
 *
 * Idempotent and restartable: a moved key is an occurrence of the new
 * expansion, so a run again (after a full run, or one cut off after some
 * series) moves nothing it moved already. With $dryRun nothing is written
 * and the summary says how many rows it would change. $afterSeries (a test
 * seam) is called with each series id once its transaction commits.
 */
return static function (Db $db, bool $dryRun = false, ?callable $afterSeries = null): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $hasTable = static fn(string $t): bool => $driver === 'sqlite'
        ? (int) $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$t]) > 0
        : (int) $db->scalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]) > 0;
    if (!$hasTable('events') || !class_exists(\Sabre\VObject\Recur\RRuleIterator::class)) {
        return 'nothing to read';
    }
    $hasJournal = $hasTable('mutations');
    $hasPieces = $hasTable('mutation_rows');
    $jsonFlags = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    // A series row: a timed master with a rule. Its keys: its skips.
    // A master with a rule, timed or all-day: the state a snapshot's days are
    // read by (an all-day one moves none of them).
    $isMaster = static fn(array $row): bool => empty($row['recurrence_parent_id']) && trim((string) ($row['rrule'] ?? '')) !== '' && isset($row['id']);
    $isSeries = static fn(array $row): bool => $isMaster($row) && (int) ($row['all_day'] ?? 0) === 0 && isset($row['start_utc']);
    $skipsOf = static function (array $row): array {
        try {
            return Recurrence::decodeExdates($row['exdates_json'] ?? null);
        } catch (\InvalidArgumentException) {
            return []; // quarantined over its limit: no path reads it
        }
    };
    $readingKey = static fn(array $m): string => json_encode([(string) $m['start_utc'], (string) ($m['tzid'] ?? ''), (string) $m['rrule']]);

    // The old reading, for the frequencies the old expander left to sabre:
    // not MINUTELY or SECONDLY (its own steps, the new expander's), not a
    // rule with BYSETPOS or a yearly one naming its days by number (read a
    // period at a time, as the new one does). Walked from the start to
    // $through and no further than the rule's own COUNT (UNTIL ends sabre's
    // walk itself), never asking for an occurrence after the last one
    // wanted: sabre searches on far where a rule has no further day. Null:
    // no old reading to undo.
    $oldKeys = static function (array $master, string $through): ?array {
        $parts = Recurrence::rruleParts((string) $master['rrule']);
        $freq = $parts['FREQ'] ?? '';
        if (!in_array($freq, ['HOURLY', 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true) || isset($parts['BYSETPOS'])
            || ($freq === 'YEARLY' && (isset($parts['BYWEEKNO']) || isset($parts['BYYEARDAY'])))) {
            return null;
        }
        $count = isset($parts['COUNT']) ? max(1, (int) $parts['COUNT']) : null;
        $start = Time::fromDb((string) $master['start_utc'])->setTimezone(Time::zone((string) $master['tzid']));
        $it = new \Sabre\VObject\Recur\RRuleIterator((string) $master['rrule'], $start);
        $out = [];
        for ($it->rewind(), $n = 1; $it->valid(); $it->next(), $n++) {
            $key = Time::toDb(\DateTimeImmutable::createFromInterface($it->current()));
            if ($key > $through) {
                break;
            }
            $out[$key] = true;
            if ($key === $through || ($count !== null && $n >= $count)) {
                break;
            }
        }
        return $out;
    };

    // The reading of a series over the keys it stores: where each key goes.
    // ['to' => key => the occurrence it moves to, 'stranded' => key => true
    // for an old one with no single new occurrence on its day, 'isNew' =>
    // key => true]. Null: a rule neither expander reads, which names no key
    // to move.
    $reading = static function (array $master, array $keys) use ($oldKeys): ?array {
        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            return ['to' => [], 'stranded' => [], 'isNew' => []];
        }
        $zone = Time::zone((string) $master['tzid']);
        $dayOf = static fn(string $key): int => Cal::dnOf(Time::fromDb($key)->setTimezone($zone));
        // The new expansion, by local day, through two days past the last
        // key, so its day is whole.
        $end = Time::fromDb(max($keys))->modify('+2 days')->getTimestamp();
        $byDay = [];
        $isNew = [];
        try {
            foreach (Recurrence::iterator($master) as $t) {
                if ($t->getTimestamp() > $end) {
                    break;
                }
                $k = Time::toDb($t);
                $byDay[Cal::dnOf($t->setTimezone($zone))][] = $k;
                $isNew[$k] = true;
            }
        } catch (\Throwable) {
            return null; // RuleNotReadable, WalkIncomplete, a rule over its limits
        }
        $others = array_values(array_filter($keys, static fn(string $k): bool => !isset($isNew[$k])));
        $to = [];
        $stranded = [];
        if ($others !== []) {
            try {
                $old = $oldKeys($master, max($others));
            } catch (\Throwable) {
                $old = null;
            }
            foreach ($others as $k) {
                if ($old === null || !isset($old[$k])) {
                    continue; // no expander gave it: not an identity this rewrites
                }
                $day = $byDay[$dayOf($k)] ?? [];
                if (count($day) === 1) {
                    $to[$k] = $day[0];
                } else {
                    $stranded[$k] = true;
                }
            }
        }
        return ['to' => $to, 'stranded' => $stranded, 'isNew' => $isNew];
    };

    // The one mapping both paths apply: a series' edited-day rows and skips
    // as the reading moves them. Rows (each with id, recurrence_instance_utc,
    // start_utc, end_utc, override_fields) come back changed, by id; skips
    // come back as a list when they changed, else null. 'stranded': old keys
    // left on no occurrence. 'left': keys on no new occurrence, left as they
    // are. Pure.
    $move = static function (array $reading, array $rows, array $skips): array {
        $to = $reading['to'];
        $changedRows = [];
        $stranded = [];
        $left = 0;
        $taken = array_fill_keys(array_map(static fn(array $r): string => (string) $r['recurrence_instance_utc'], $rows), true);
        foreach ($rows as $row) {
            $key = (string) $row['recurrence_instance_utc'];
            $target = $to[$key] ?? null;
            if ($target === null || isset($taken[$target])) {
                if (!isset($reading['isNew'][$key])) {
                    $left++;
                    if ($target !== null || isset($reading['stranded'][$key])) {
                        $stranded[] = $key;
                    }
                }
                continue;
            }
            unset($taken[$key]);
            $taken[$target] = true;
            $own = json_decode((string) ($row['override_fields'] ?? ''), true);
            // An inherited time follows its key; an own one stays. With no
            // list (rows older than 063), a row at its key inherits.
            $inherits = is_array($own) ? !in_array('time', $own, true) && !in_array('date', $own, true) : (string) $row['start_utc'] === $key;
            $shift = $inherits ? Time::fromDb($target)->getTimestamp() - Time::fromDb($key)->getTimestamp() : 0;
            $by = ($shift >= 0 ? '+' : '') . $shift . ' seconds';
            $changedRows[$row['id']] = ['recurrence_instance_utc' => $target,
                'start_utc' => Time::toDb(Time::fromDb((string) $row['start_utc'])->modify($by)),
                'end_utc' => Time::toDb(Time::fromDb((string) $row['end_utc'])->modify($by))] + $row;
        }
        $newSkips = [];
        $skipsMoved = 0;
        foreach ($skips as $key) {
            $target = $to[$key] ?? $key;
            if ($target !== $key) {
                $skipsMoved++;
            } elseif (!isset($reading['isNew'][$key])) {
                $left++;
                if (isset($reading['stranded'][$key])) {
                    $stranded[] = $key;
                }
            }
            if (!in_array($target, $newSkips, true)) {
                $newSkips[] = $target; // the same skip once
            }
        }
        return ['rows' => $changedRows, 'skips' => $newSkips === $skips ? null : $newSkips, 'skipsMoved' => $skipsMoved, 'stranded' => $stranded, 'left' => $left];
    };

    // An entry's event rows, a chunk at a time: its before and after, and
    // each piece it keeps apart. $fn(side, chunk id, rows) returns the
    // chunk's rows changed, or null.
    $chunks = static function (array $entry, callable $fn) use ($db, $hasPieces): array {
        $changed = [];
        foreach (['before' => 'before_json', 'after' => 'after_json'] as $side => $col) {
            $tables = json_decode((string) ($entry[$col] ?? ''), true);
            if (is_array($tables['tables']['events'] ?? null)) {
                $rows = $fn($side, $col, $tables['tables']['events']);
                if ($rows !== null) {
                    $tables['tables']['events'] = $rows;
                    $changed[$col] = $tables;
                }
            }
        }
        if ($hasPieces) {
            foreach ($db->all("SELECT seq, side FROM mutation_rows WHERE mutation_id = ? AND table_name = 'events' ORDER BY seq", [(int) $entry['id']]) as $piece) {
                $rows = json_decode((string) $db->scalar('SELECT rows_json FROM mutation_rows WHERE mutation_id = ? AND seq = ?', [(int) $entry['id'], (int) $piece['seq']]), true);
                if (is_array($rows)) {
                    $out = $fn((string) $piece['side'], (int) $piece['seq'], $rows);
                    if ($out !== null) {
                        $changed[(int) $piece['seq']] = $out;
                    }
                }
            }
        }
        return $changed;
    };

    // The series rows an entry keeps, by side and id: what its snapshots'
    // keys are read by.
    $entryMasters = static function (array $entry) use ($chunks, $isMaster): array {
        $masters = ['before' => [], 'after' => []];
        $chunks($entry, static function (string $side, $chunk, array $rows) use (&$masters, $isMaster): ?array {
            foreach ($rows as $row) {
                if (is_array($row) && $isMaster($row)) {
                    $masters[$side][(int) $row['id']] = $row;
                }
            }
            return null;
        });
        return $masters;
    };

    $liveMaster = static function (int $id) use ($db, $isMaster): ?array {
        $row = $db->one('SELECT * FROM events WHERE id = ?', [$id]);
        return $row !== null && $isMaster($row) ? $row : null;
    };

    // Series $id as it stood at entry $entryId, from the journal's own
    // snapshots: the state the latest earlier entry left it in, else the
    // state the next later one found it in (a delete's before included).
    // Null: no entry keeps it.
    $stateAt = static function (int $id, int $entryId) use (&$history): ?array {
        $found = null;
        foreach ($history[$id] ?? [] as [$at, $side, $row]) {
            if ($at < $entryId && $side === 'after') {
                $found = $row;
            } elseif ($at > $entryId && $side === 'before' && $found === null) {
                return $row;
            }
        }
        return $found;
    };

    // Series $id's keys in an entry, each with the series row it reads by
    // ($byOf(side)): [reading key => [series row, keys]].
    $entryKeys = static function (array $entry, int $id, array $masters, callable $byOf) use ($chunks, $skipsOf, $readingKey, $isSeries): array {
        $out = [];
        $chunks($entry, static function (string $side, $chunk, array $rows) use (&$out, $id, $masters, $byOf, $skipsOf, $readingKey, $isSeries): ?array {
            $by = $byOf($side);
            if ($by === null || !$isSeries($by)) {
                return null; // no state to read by (the entry is closed), or an all-day one
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $keys = (int) ($row['id'] ?? 0) === $id && isset($masters[$side][$id]) ? $skipsOf($row)
                    : ((int) ($row['recurrence_parent_id'] ?? 0) === $id && !empty($row['recurrence_instance_utc']) ? [(string) $row['recurrence_instance_utc']] : []);
                if ($keys !== []) {
                    $k = $readingKey($by);
                    $out[$k][0] = $by;
                    $out[$k][1] = array_merge($out[$k][1] ?? [], $keys);
                }
            }
            return null;
        });
        return $out;
    };

    // The journal's undoable entries that keep a key of a series, by series
    // id, and every state of a series row its snapshots keep, by series id
    // in entry order ([entry id, side, row]). Read once, a page at a time.
    $journal = [];
    $history = [];
    if ($hasJournal) {
        $afterId = 0;
        while (true) {
            $page = $db->all('SELECT id, before_json, after_json FROM mutations WHERE id > ? AND undone = 0 AND (before_json IS NOT NULL OR after_json IS NOT NULL) ORDER BY id LIMIT 200', [$afterId]);
            if ($page === []) {
                break;
            }
            $afterId = (int) end($page)['id'];
            foreach ($page as $entry) {
                $masters = $entryMasters($entry);
                foreach ($masters as $side => $rows) {
                    foreach ($rows as $masterId => $row) {
                        $history[$masterId][] = [(int) $entry['id'], $side, $row];
                    }
                }
                $chunks($entry, static function (string $side, $chunk, array $rows) use (&$journal, $entry, $masters): ?array {
                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        if (isset($masters[$side][(int) ($row['id'] ?? 0)])) {
                            $journal[(int) $row['id']][(int) $entry['id']] = true;
                        } elseif (!empty($row['recurrence_parent_id']) && !empty($row['recurrence_instance_utc'])) {
                            $journal[(int) $row['recurrence_parent_id']][(int) $entry['id']] = true;
                        }
                    }
                    return null;
                });
            }
        }
    }

    $series = $keysMoved = $skipsMoved = $left = $unread = $entriesKept = $entriesClosed = 0;
    $seen = [];
    $process = static function (int $id, ?array $live) use (
        $db, $dryRun, $afterSeries, $jsonFlags, &$journal, &$series, &$keysMoved, &$skipsMoved, &$left, &$unread, &$entriesKept, &$entriesClosed,
        $skipsOf, $readingKey, $reading, $move, $chunks, $entryMasters, $entryKeys, $stateAt, $isSeries
    ): void {
        // The live rows read by the live series row; a snapshot by the series
        // row as it stood for that snapshot: its own side's, the entry's
        // other side's, the journal's state at that entry, else the live one.
        $master = $live !== null && $isSeries($live) ? $live : null;
        $byOf = static fn(array $entry, array $masters, string $side): ?array => $masters[$side][$id]
            ?? $masters[$side === 'before' ? 'after' : 'before'][$id] ?? $stateAt($id, (int) $entry['id']) ?? $live;
        $entryIds = array_keys($journal[$id] ?? []);
        $edited = $master === null ? [] : $db->all('SELECT id, recurrence_instance_utc, start_utc, end_utc, override_fields FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc IS NOT NULL', [$id]);
        $skips = $master === null ? [] : $skipsOf($master);
        if ($edited === [] && $skips === [] && $entryIds === []) {
            return;
        }
        // Every key the series stores, live and in the journal, by the
        // series row each is read by: one reading for each.
        $groups = [];
        if ($master !== null) {
            $groups[$readingKey($master)] = [$master, array_merge(array_column($edited, 'recurrence_instance_utc'), $skips)];
        }
        $entries = [];
        foreach ($entryIds as $entryId) {
            $entry = $db->one('SELECT id, before_json, after_json, details_json FROM mutations WHERE id = ? AND undone = 0 AND (before_json IS NOT NULL OR after_json IS NOT NULL)', [$entryId]);
            if ($entry === null) {
                continue;
            }
            $masters = $entryMasters($entry);
            $entries[] = [$entry, $masters];
            foreach ($entryKeys($entry, $id, $masters, static fn(string $side): ?array => $byOf($entry, $masters, $side)) as $k => [$by, $keys]) {
                $groups[$k][0] = $by;
                $groups[$k][1] = array_merge($groups[$k][1] ?? [], $keys);
            }
        }
        $readings = [];
        foreach ($groups as $k => [$by, $keys]) {
            $readings[$k] = $reading($by, $keys);
        }

        $writes = [];
        $liveChanged = false;
        if ($master !== null) {
            $r = $readings[$readingKey($master)] ?? null;
            if ($r === null) {
                $unread++;
            } else {
                $m = $move($r, $edited, $skips);
                foreach ($m['rows'] as $rowId => $row) {
                    $writes[] = ['UPDATE events SET recurrence_instance_utc = ?, start_utc = ?, end_utc = ? WHERE id = ?', [$row['recurrence_instance_utc'], $row['start_utc'], $row['end_utc'], (int) $rowId]];
                }
                if ($m['skips'] !== null) {
                    $writes[] = ['UPDATE events SET exdates_json = ? WHERE id = ?', [json_encode($m['skips']), $id]];
                }
                $keysMoved += count($m['rows']);
                $skipsMoved += $m['skipsMoved'];
                $left += $m['left'];
                $liveChanged = $writes !== [];
            }
        }

        // The journal, by the same mapping: each side's rows of this series
        // together (two edited days can't take one key), then put back into
        // the chunk each came from.
        foreach ($entries as [$entry, $masters]) {
            $bySide = ['before' => [], 'after' => []];
            $skipRows = [];
            $chunks($entry, static function (string $side, $chunk, array $rows) use (&$bySide, &$skipRows, $id, $masters): ?array {
                foreach ($rows as $i => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if ((int) ($row['id'] ?? 0) === $id && isset($masters[$side][$id])) {
                        $skipRows[$side] = [$chunk, $i];
                    } elseif ((int) ($row['recurrence_parent_id'] ?? 0) === $id && !empty($row['recurrence_instance_utc'])) {
                        $bySide[$side][] = $row;
                    }
                }
                return null;
            });
            $changedRows = [];
            $changedSkips = [];
            $stranded = false;
            $orphan = false;
            $any = false;
            foreach (['before', 'after'] as $side) {
                $by = $byOf($entry, $masters, $side);
                if ($by === null) {
                    // Days of a series no snapshot or live row says the rule
                    // of: not to be read, and not to be put back unread.
                    $orphan = $orphan || $bySide[$side] !== [];
                    continue;
                }
                $r = $isSeries($by) ? ($readings[$readingKey($by)] ?? null) : null;
                if ($r === null) {
                    continue; // all-day then (its keys are dates), or a rule neither expander reads
                }
                $m = $move($r, $bySide[$side], isset($masters[$side][$id]) ? $skipsOf($masters[$side][$id]) : []);
                $stranded = $stranded || $m['stranded'] !== [];
                $changedRows[$side] = $m['rows'];
                if ($m['skips'] !== null) {
                    $changedSkips[$side] = json_encode($m['skips']);
                }
                $any = $any || $m['rows'] !== [] || $m['skips'] !== null;
            }
            if ($stranded || $orphan) {
                $entriesClosed++;
                $details = json_decode((string) ($entry['details_json'] ?? ''), true);
                $details = is_array($details) ? $details : [];
                $details['notUndoable'] = $stranded
                    ? "This change can't be undone: the upgrade to 1.5.0 reads this series' dates anew, and a date it kept has no occurrence now"
                    : "This change can't be undone: the upgrade to 1.5.0 reads this series' dates anew, and the series it changed a day of is no longer on record";
                $writes[] = ['UPDATE mutations SET before_json = NULL, after_json = NULL, details_json = ? WHERE id = ?', [json_encode($details, $jsonFlags), (int) $entry['id']]];
                $writes[] = ['DELETE FROM mutation_rows WHERE mutation_id = ?', [(int) $entry['id']]];
                continue;
            }
            if (!$any) {
                continue;
            }
            $entriesKept++;
            $out = $chunks($entry, static function (string $side, $chunk, array $rows) use ($id, $changedRows, $changedSkips, $skipRows): ?array {
                $changed = false;
                foreach ($rows as $i => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if ((int) ($row['recurrence_parent_id'] ?? 0) === $id && isset($changedRows[$side][$row['id'] ?? ''])) {
                        $rows[$i] = $changedRows[$side][$row['id']];
                        $changed = true;
                    } elseif (isset($changedSkips[$side]) && ($skipRows[$side] ?? null) === [$chunk, $i]) {
                        $rows[$i]['exdates_json'] = $changedSkips[$side];
                        $changed = true;
                    }
                }
                return $changed ? $rows : null;
            });
            foreach ($out as $chunk => $value) {
                $writes[] = is_string($chunk)
                    ? ["UPDATE mutations SET $chunk = ? WHERE id = ?", [json_encode($value, $jsonFlags), (int) $entry['id']]]
                    : ['UPDATE mutation_rows SET rows_json = ? WHERE mutation_id = ? AND seq = ?', [json_encode($value, $jsonFlags), (int) $entry['id'], $chunk]];
            }
        }
        if ($writes === []) {
            return;
        }
        $series++;
        if ($dryRun) {
            return;
        }
        $db->tx(static function () use ($db, $writes, $liveChanged, $master): void {
            foreach ($writes as [$sql, $params]) {
                $db->run($sql, $params);
            }
            if ($liveChanged) {
                // CalDAV clients and open tabs fetch the series again.
                ChangeLog::record($db, (int) $master['calendar_id'], (string) $master['uid'], ChangeLog::OP_MODIFY);
            }
        });
        if ($liveChanged) {
            ChangeLog::touchAccount($db, (int) $master['user_id']);
        }
        if ($afterSeries !== null) {
            $afterSeries($id);
        }
    };

    $after = 0;
    while (true) {
        $masters = $db->all("SELECT * FROM events WHERE id > ? AND rrule IS NOT NULL AND rrule <> '' AND recurrence_parent_id IS NULL AND all_day = 0 ORDER BY id LIMIT 200", [$after]);
        if ($masters === []) {
            break;
        }
        $after = (int) end($masters)['id'];
        foreach ($masters as $master) {
            $seen[(int) $master['id']] = true;
            $process((int) $master['id'], $master);
        }
    }
    // Series the journal keeps that are no timed series now (gone, or made
    // all-day since): their snapshots read by the series rows of their time.
    foreach (array_keys($journal) as $id) {
        if (!isset($seen[$id])) {
            $process($id, $liveMaster($id));
        }
    }
    return sprintf('%s%d series, %d edited-day key%s and %d skip%s %s; %d key%s left on no occurrence; %d series unread; %d undo entr%s %s, %d made log-only',
        $dryRun ? 'dry run: ' : '', $series, $keysMoved, $keysMoved === 1 ? '' : 's', $skipsMoved, $skipsMoved === 1 ? '' : 's',
        $dryRun ? 'would move' : 'moved', $left, $left === 1 ? '' : 's', $unread,
        $entriesKept, $entriesKept === 1 ? 'y' : 'ies', $dryRun ? 'would be rewritten' : 'rewritten', $entriesClosed);
};
