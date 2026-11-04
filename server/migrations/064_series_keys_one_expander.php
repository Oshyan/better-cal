<?php

declare(strict_types=1);

use BetterCal\Dav\ChangeLog;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Series\Cal;
use BetterCal\Domain\Series\RuleNotReadable;
use BetterCal\Domain\Series\WalkIncomplete;
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
 * exactly one occurrence, moves to that occurrence. An edited day whose
 * time is inherited (not one of its own fields) moves with its key, so it
 * shows where the series does that day; one that owns its time stays where
 * it shows. Every other key is left as it is: one of the new expansion, a
 * day the new one gives several occurrences or none (counted as left), or a
 * key no expander gave. Timed series only: an all-day series' keys are its
 * UTC dates, which no clock rule moves.
 *
 * Idempotent: a moved key is an occurrence of the new expansion, so a second
 * run finds nothing to move. With $dryRun nothing is written and the summary
 * says how many rows it would change.
 */
return static function (Db $db, bool $dryRun = false): string {
    $driver = (string) $db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $hasTable = static fn(string $t): bool => $driver === 'sqlite'
        ? (int) $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$t]) > 0
        : (int) $db->scalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]) > 0;
    if (!$hasTable('events') || !class_exists(\Sabre\VObject\Recur\RRuleIterator::class)) {
        return 'nothing to read';
    }

    // The old reading, for the frequencies the old expander left to sabre:
    // not MINUTELY or SECONDLY (its own steps, the new expander's), not a
    // rule with BYSETPOS or a yearly one naming its days by number (read a
    // period at a time, as the new one does). Null: no old reading to undo.
    $oldKeys = static function (array $master, string $through): ?array {
        $parts = Recurrence::rruleParts((string) $master['rrule']);
        $freq = $parts['FREQ'] ?? '';
        if (!in_array($freq, ['HOURLY', 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true) || isset($parts['BYSETPOS'])
            || ($freq === 'YEARLY' && (isset($parts['BYWEEKNO']) || isset($parts['BYYEARDAY'])))) {
            return null;
        }
        $start = Time::fromDb((string) $master['start_utc'])->setTimezone(Time::zone((string) $master['tzid']));
        $out = [];
        foreach (new \Sabre\VObject\Recur\RRuleIterator((string) $master['rrule'], $start) as $t) {
            $key = Time::toDb(\DateTimeImmutable::createFromInterface($t));
            if ($key > $through) {
                break;
            }
            $out[$key] = true;
        }
        return $out;
    };
    // The new expansion through a key, by local day in the series' zone, and
    // the local day of its first occurrence after that, if it has one.
    $newByDay = static function (array $master, string $through): array {
        $zone = Time::zone((string) $master['tzid']);
        $out = [];
        $end = Time::fromDb($through)->getTimestamp();
        foreach (Recurrence::iterator($master) as $t) {
            $dn = Cal::dnOf($t->setTimezone($zone));
            if ($t->getTimestamp() > $end) {
                return [$out, $dn];
            }
            $out[$dn][] = Time::toDb($t);
        }
        return [$out, null];
    };

    $series = $keysMoved = $skipsMoved = $left = $unread = 0;
    $after = 0;
    while (true) {
        $masters = $db->all("SELECT * FROM events WHERE id > ? AND rrule IS NOT NULL AND rrule <> '' AND recurrence_parent_id IS NULL AND all_day = 0 ORDER BY id LIMIT 200", [$after]);
        if ($masters === []) {
            break;
        }
        $after = (int) end($masters)['id'];
        foreach ($masters as $master) {
            $id = (int) $master['id'];
            $edited = $db->all('SELECT id, recurrence_instance_utc, start_utc, end_utc, override_fields FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc IS NOT NULL', [$id]);
            try {
                $skips = Recurrence::decodeExdates($master['exdates_json'] ?? null);
            } catch (\InvalidArgumentException) {
                $skips = []; // quarantined over its limit: no path reads it
            }
            $stored = array_merge(array_column($edited, 'recurrence_instance_utc'), $skips);
            if ($stored === []) {
                continue;
            }
            $through = Time::toDb(Time::fromDb(max($stored))->modify('+2 days'));
            try {
                [$new, $later] = $newByDay($master, $through);
            } catch (RuleNotReadable|WalkIncomplete) {
                $unread++;
                continue; // a rule the expander can't read names no key to move
            }
            $isNew = [];
            foreach ($new as $keys) {
                foreach ($keys as $k) {
                    $isNew[$k] = true;
                }
            }
            $zone = Time::zone((string) $master['tzid']);
            $dayOf = static fn(string $key): int => Cal::dnOf(Time::fromDb($key)->setTimezone($zone));
            // A key that could move: no new occurrence, one on its day, and a
            // later day with one, so the old reading's walk to it ends (sabre
            // searches on forever where a rule has no further day).
            $lastDay = $later ?? ($new === [] ? PHP_INT_MIN : max(array_keys($new)));
            $candidates = array_values(array_filter(array_unique($stored), static fn(string $k): bool => !isset($isNew[$k]) && count($new[$dayOf($k)] ?? []) === 1 && $dayOf($k) < $lastDay));
            if ($candidates === []) {
                $left += count(array_filter($stored, static fn(string $k): bool => !isset($isNew[$k])));
                continue;
            }
            try {
                $old = $oldKeys($master, max($candidates));
            } catch (\Sabre\VObject\InvalidDataException) {
                $old = null;
            }
            // Where a key moves: the one new occurrence on its day, when the
            // old reading gave it and the new one doesn't; else null.
            $target = static function (string $key) use ($new, $old, $candidates, $dayOf): ?string {
                return $old !== null && isset($old[$key]) && in_array($key, $candidates, true) ? $new[$dayOf($key)][0] : null;
            };
            $taken = array_fill_keys(array_column($edited, 'recurrence_instance_utc'), true);
            $writes = [];
            foreach ($edited as $row) {
                $key = (string) $row['recurrence_instance_utc'];
                $to = $target($key);
                if ($to === null || isset($taken[$to])) {
                    $left += isset($isNew[$key]) ? 0 : 1;
                    continue;
                }
                unset($taken[$key]);
                $taken[$to] = true;
                $own = json_decode((string) ($row['override_fields'] ?? ''), true);
                // An inherited time follows its key; an own one stays. With no
                // list (rows older than 063), a row at its key inherits.
                $inherits = is_array($own) ? !in_array('time', $own, true) && !in_array('date', $own, true) : (string) $row['start_utc'] === $key;
                $shift = $inherits ? Time::fromDb($to)->getTimestamp() - Time::fromDb($key)->getTimestamp() : 0;
                $writes[] = ['UPDATE events SET recurrence_instance_utc = ?, start_utc = ?, end_utc = ? WHERE id = ?', [
                    $to, Time::toDb(Time::fromDb((string) $row['start_utc'])->modify(($shift >= 0 ? '+' : '') . $shift . ' seconds')),
                    Time::toDb(Time::fromDb((string) $row['end_utc'])->modify(($shift >= 0 ? '+' : '') . $shift . ' seconds')), (int) $row['id'],
                ]];
                $keysMoved++;
            }
            $newSkips = [];
            $changed = false;
            foreach ($skips as $key) {
                $to = $target($key);
                if ($to === null || in_array($to, $skips, true) || in_array($to, $newSkips, true)) {
                    $left += isset($isNew[$key]) ? 0 : 1;
                    $newSkips[] = $key;
                    continue;
                }
                $newSkips[] = $to;
                $changed = true;
                $skipsMoved++;
            }
            if ($changed) {
                $writes[] = ['UPDATE events SET exdates_json = ? WHERE id = ?', [json_encode($newSkips), $id]];
            }
            if ($writes === []) {
                continue;
            }
            $series++;
            if ($dryRun) {
                continue;
            }
            $db->tx(static function () use ($db, $writes): void {
                foreach ($writes as [$sql, $params]) {
                    $db->run($sql, $params);
                }
            });
            // CalDAV clients and open tabs fetch the series again.
            ChangeLog::record($db, (int) $master['calendar_id'], (string) $master['uid'], ChangeLog::OP_MODIFY);
            ChangeLog::touchAccount($db, (int) $master['user_id']);
        }
    }
    return sprintf('%s%d series, %d edited-day key%s and %d skip%s %s; %d key%s left on no occurrence; %d series unread',
        $dryRun ? 'dry run: ' : '', $series, $keysMoved, $keysMoved === 1 ? '' : 's', $skipsMoved, $skipsMoved === 1 ? '' : 's',
        $dryRun ? 'would move' : 'moved', $left, $left === 1 ? '' : 's', $unread);
};
