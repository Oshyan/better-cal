<?php
// c-placement (README, The Plan's Data): every day of a committed plan, by
// outcome, against the rows before and after and the oracle. The outcome is
// the harness's own comparison of each row's instant and kind; identity days
// are read in the old series' zone, placements by the zone rule (PlanChecks).
declare(strict_types=1);

namespace BetterCal\EditHarness;

trait PlanPlacement
{
    private const PLACEMENT_KEYS = ['date', 'at', 'tz', 'kind'];

    /** A placement after, with its new kind when that differs from before. */
    private static function placedAfter(array $row, array $was, ?string $zone): array
    {
        $p = self::placed($row, $zone);
        if ((int) $row['all_day'] !== (int) $was['all_day']) {
            $p['kind'] = (int) $row['all_day'] === 1 ? 'all-day' : 'timed';
        }
        return $p;
    }

    private function checkPlacement(array $plan, array $before, array $after, array $edit, array $choices, array $ctx): void
    {
        $zone = $ctx['zone'];
        $tz0 = $ctx['tz0'];
        $out = $ctx['out'];
        $m0 = $before['row'];
        $identity = static fn(string $key): string => Days::date(Clock::dayOf($key, $tz0));
        $rowsByKey = [];
        foreach ($before['overrides'] as $id => $ov) {
            $rowsByKey[(string) $ov['recurrence_instance_utc']] = $id;
        }
        $alone = [];
        foreach ($ctx['standalones'] as $s) {
            $alone[(int) $s['id']] = $s;
        }
        $seen = [];
        $actedDays = 0;
        foreach ($plan['days'] ?? [] as $d) {
            $key = self::dbKey($d['key'] ?? null);
            $kind = (string) ($d['kind'] ?? '');
            if ($kind === 'skipped') {
                $this->placeSkip($d, $key, $after, $ctx, $identity);
                continue;
            }
            $id = $key === null ? null : ($rowsByKey[$key] ?? null);
            if ($id !== null) {
                $seen[$id] = true;
                $this->placeRow($d, $id, $before, $after, $edit, $ctx, $alone, $identity);
                continue;
            }
            if ($kind === 'acted') {
                $actedDays++;
                $this->placeActed($d, $key, $before, $edit, $ctx, $identity);
                continue;
            }
            $this->expect('c-placement', 'an edited day of the plan is a row before the edit', 'edited day is a row', true, false, ['day' => $d]);
        }
        foreach ($before['overrides'] as $id => $ov) {
            $this->expect('c-placement', 'every edited day before the edit is a day of the plan', 'edited day listed', true, isset($seen[$id]), ['key' => $ov['recurrence_instance_utc']]);
        }
        // The day acted on with no row: a row the commit created for it, or an event of its own, is a day of the plan.
        $actedMade = ($ctx['created'] !== null ? 1 : 0) + ($edit['fromRow'] === null && $ctx['actedAlone'] !== null ? 1 : 0);
        $this->expect('c-placement', 'the day acted on is a day of the plan exactly when the commit made it a row', 'acted listed', $actedMade, $actedDays);
        // plan.acted: the day the edit started from, when it has something of its own.
        $fromRow = $edit['fromRow'] !== null ? ($before['overrides'][$edit['fromRow']] ?? null) : null;
        $a = $plan['acted'] ?? null;
        $this->expect('c-placement', 'plan.acted is there exactly when the day acted on has a row of its own', 'plan.acted', $fromRow !== null, $a !== null);
        if ($fromRow !== null && is_array($a)) {
            $own = in_array('date', $before['own'][$edit['fromRow']] ?? [], true);
            $want = ['key' => self::dbKey((string) $fromRow['recurrence_instance_utc']), 'day' => $identity((string) $fromRow['recurrence_instance_utc']), 'before' => self::placed($fromRow, $zone), 'ownDate' => $own ? self::ownPlaced($fromRow, $zone) : null];
            $got = ['key' => self::dbKey($a['key'] ?? null), 'day' => $a['day'] ?? null, 'before' => self::norm($a['before'] ?? null, self::PLACEMENT_KEYS), 'ownDate' => self::norm($a['ownDate'] ?? null, self::PLACEMENT_KEYS)];
            foreach ($want as $f => $v) {
                $this->expect('c-placement', "plan.acted.$f is the day the edit started from", "plan.acted $f", $v, $got[$f]);
            }
        }
        $this->placeMovedDays($plan, $before, $after, $choices, $ctx, $rowsByKey, $alone);
        $this->placeBound($plan, $before, $after);
        $this->placeStart($plan, $before, $after);
        if ($out !== null) {
            $this->placeZoneStarts($plan, $before, $edit, $choices, $ctx);
            $this->placeLossesGains($plan, $before, $after, $edit, $ctx, $choices);
            $this->placeReadings($plan, $before, $choices, $ctx);
        }
        $this->placeNotes($plan, $after, $edit, $ctx);
    }

    /** A skipped day: its identity, and the day its image is in the new series; a generated one, a stored skip on its day. */
    private function placeSkip(array $d, ?string $key, array $after, array $ctx, \Closure $identity): void
    {
        $outcome = (string) ($d['outcome'] ?? '');
        if ($outcome === 'generated') {
            $days = array_map(static fn(string $k): string => Days::date(Clock::dayOf($k, $ctx['tz1'])), $after['exdates']);
            $this->expect('c-placement', 'a generated skip is a stored skipped day on its day', 'to (generated)', true, in_array($d['to'] ?? null, $days, true), ['to' => $d['to'] ?? null]);
            return;
        }
        if ($outcome === 'anchor' || $key === null) {
            return; // an anchor is no day of the old series (c-shape names its key)
        }
        $this->expect('c-placement', 'a skipped day shows on its identity day', "shows (skipped $outcome)", $identity($key), $d['shows'] ?? null, ['key' => $key]);
        if (!in_array($outcome, ['moves', 'stays'], true)) {
            return; // dropped: no day in the new series to go to
        }
        $img = $ctx['out']['images'][$key] ?? null;
        if ($ctx['out'] === null || $img === null) {
            $this->unoracled[] = 'c-placement: a skipped day\'s to where the oracle has no image for it';
        } else {
            $this->expect('c-placement', 'a skipped day goes to its image\'s day', "to (skipped $outcome)", Days::date((int) $img), $d['to'] ?? null, ['key' => $key]);
        }
    }

    /**
     * An edited row: the day it is about, the date set on it, where it shows
     * before and after, and the outcome by the harness's comparison of its
     * instant and kind (separate when its row left the series, rejoins or
     * deleted when it went, by whether the oracle keeps its day inside).
     */
    private function placeRow(array $d, int $id, array $before, array $after, array $edit, array $ctx, array $alone, \Closure $identity): void
    {
        $zone = $ctx['zone'];
        $row0 = $before['overrides'][$id];
        $row1 = $after['overrides'][$id] ?? null;
        // Under a rule outside the drag table a row is inside while its key has a new key (newKeyByPlace).
        $placeKey = $ctx['out'] === null ? self::newKeyByPlace($before, $after, $ctx, (string) $row0['recurrence_instance_utc']) : null;
        $inside = $ctx['out'] === null ? $placeKey !== null : (bool) ($ctx['out']['rows'][$id]['inside'] ?? true);
        $where = null;
        if ($row1 !== null) {
            $fate = (string) $row0['start_utc'] === (string) $row1['start_utc'] && (int) $row0['all_day'] === (int) $row1['all_day'] ? 'stays' : 'moves';
            $where = $row1;
        } elseif (isset($alone[$id])) {
            $fate = 'separate';
            $where = $alone[$id];
        } elseif ($inside) {
            $fate = 'rejoins';
            $img = $ctx['out']['rows'][$id]['img'] ?? null;
            $k = $ctx['out'] === null ? $placeKey : ($img === null ? null : ($ctx['byDay'][$img] ?? null));
            $where = $k === null ? null : self::occurrenceRow($after['row'], $k);
        } else {
            $fate = 'deleted';
        }
        $role = ($id === $edit['fromRow'] ? 'acted row ' : '') . $fate;
        $own = in_array('date', $before['own'][$id] ?? [], true);
        $this->expect('c-placement', 'an edited day\'s outcome is the comparison of its instant and kind before and after', "outcome ($role)", $fate, $d['outcome'] ?? null, ['key' => $row0['recurrence_instance_utc'], 'was' => [$row0['start_utc'], $row0['end_utc'], $row0['all_day']], 'now' => $row1 === null ? null : [$row1['start_utc'], $row1['end_utc'], $row1['all_day']]]);
        $this->expect('c-placement', 'an edited day is about its old key\'s day in the old zone', "day ($role)", $identity((string) $row0['recurrence_instance_utc']), $d['day'] ?? null);
        $this->expect('c-placement', 'an edited day that owned its date carries it, in its own zone', "ownDate ($role)", $own ? self::ownPlaced($row0, $zone) : null, self::norm($d['ownDate'] ?? null, self::PLACEMENT_KEYS));
        $this->expect('c-placement', 'an edited day shows before where its row was, by the zone rule', "before ($role)", self::placed($row0, $zone), self::norm($d['before'] ?? null, self::PLACEMENT_KEYS));
        $this->expect('c-placement', 'an edited day\'s shows is the date it showed on before', "shows ($role)", self::placed($row0, $zone)['date'], $d['shows'] ?? null);
        if ($fate === 'deleted') {
            $this->expect('c-placement', 'a deleted day has no after', "after ($role)", null, $d['after'] ?? null);
            return;
        }
        if ($where === null) {
            $this->unoracled[] = 'c-placement: the occurrence a rejoining day goes back to is past the window read';
            return;
        }
        $placedAfter = self::placedAfter($where, $row0, $zone);
        $this->expect('c-placement', 'an edited day shows after where the commit put it, by the zone rule', "after ($role)", $placedAfter, self::norm($d['after'] ?? null, self::PLACEMENT_KEYS), ['stored' => $where['start_utc']]);
        $this->expect('c-placement', 'an edited day\'s to is the date it shows on after', "to ($role)", $placedAfter['date'], $d['to'] ?? null);
        if (in_array($fate, ['stays', 'moves'], true) && isset($placedAfter['at'])) {
            $this->expect('c-placement', 'a timed edited day that stays or moves has at (and its zone) where it shows after', "at ($role)", [$placedAfter['at'], $placedAfter['tz'] ?? null], [self::norm($d, ['at'])['at'] ?? null, $d['tz'] ?? null]);
        }
    }

    /** The day acted on with no row: where its occurrence was, and the row created for it or the event of its own it became. */
    private function placeActed(array $d, ?string $key, array $before, array $edit, array $ctx, \Closure $identity): void
    {
        $zone = $ctx['zone'];
        $actedKey = (string) $edit['actedKey'];
        $was = self::occurrenceRow($before['row'], $actedKey);
        $made = $ctx['created'] ?? ($ctx['actedAlone'] ?? null);
        $fate = $made === null ? 'missing' : ($ctx['created'] === null ? 'separate' : ((string) $made['start_utc'] === $actedKey && (int) $made['all_day'] === (int) $was['all_day'] ? 'stays' : 'moves'));
        $role = 'acted ' . ($ctx['created'] !== null ? 'created' : 'separate');
        $this->expect('c-placement', 'the day acted on is named by its key', "key ($role)", $actedKey, $key);
        $this->expect('c-placement', 'the day acted on\'s outcome is the comparison of its instant and kind', "outcome ($role)", $fate, $d['outcome'] ?? null);
        $this->expect('c-placement', 'the day acted on is about its old key\'s day in the old zone', "day ($role)", $identity($actedKey), $d['day'] ?? null);
        $this->expect('c-placement', 'the day acted on showed at its occurrence', "before ($role)", self::placed($was, $zone), self::norm($d['before'] ?? null, self::PLACEMENT_KEYS));
        $this->expect('c-placement', 'the day acted on\'s shows is the date its occurrence showed on', "shows ($role)", self::placed($was, $zone)['date'], $d['shows'] ?? null);
        if ($made !== null) {
            $this->expect('c-placement', 'the day acted on shows after at the row the commit wrote', "after ($role)", self::placedAfter($made, $was, $zone), self::norm($d['after'] ?? null, self::PLACEMENT_KEYS));
            $this->expect('c-placement', 'the day acted on\'s to is the date the row the commit wrote shows on', "to ($role)", self::placed($made, $zone)['date'], $d['to'] ?? null);
        }
    }

    /** The moved-day choices of one day: each option's date where the harness places the day for it, the chosen one where the commit put it. */
    private function placeMovedDays(array $plan, array $before, array $after, array $choices, array $ctx, array $rowsByKey, array $alone): void
    {
        $x = $ctx['x'];
        $zone = $ctx['zone'];
        foreach ($plan['groups'] ?? [] as $g) {
            if (($g['id'] ?? '') !== 'movedDays') {
                continue;
            }
            if (!isset($g['day'])) {
                // A choice about several days names no day, and its options no date to check.
                $this->unoracled[] = 'c-placement: a moved-day choice about several days (no day, no dates)';
                continue;
            }
            $id = $rowsByKey[self::dbKey((string) $g['day'])] ?? null;
            $this->expect('c-placement', 'a day\'s moved-day choice names a row that owned its date', 'movedDays day', true, $id !== null && in_array('date', $before['own'][$id] ?? [], true), ['day' => $g['day']]);
            if ($id === null) {
                continue;
            }
            $row0 = $before['overrides'][$id];
            $row1 = $after['overrides'][$id] ?? ($alone[$id] ?? null);
            $img = $ctx['out']['rows'][$id]['img'] ?? null;
            $key = $img === null ? null : ($ctx['byDay'][$img] ?? null);
            foreach ($g['options'] ?? [] as $o) {
                if (!array_key_exists('date', $o)) {
                    continue;
                }
                if (($o['id'] ?? null) === ($choices['movedDays'] ?? $g['selected'] ?? null)) {
                    $this->expect('c-placement', 'the chosen moved-day option names the day the commit put it on', 'movedDays chosen', $row1 === null ? null : self::placed($row1, $zone)['date'], $o['date'], ['option' => $o['id']]);
                    continue;
                }
                if ($ctx['out'] === null || $img === null || $key === null) {
                    $this->unoracled[] = 'c-placement: a moved-day option other than the chosen one, where the oracle has no image for its day';
                    continue;
                }
                [$start, , $allDay, $tz] = self::placeExpected($x, $before, $row0, $key, (int) $img, ($o['id'] ?? '') === 'move');
                $p = self::placed(['start_utc' => $start, 'all_day' => $allDay ? 1 : 0, 'tzid' => $tz], $allDay ? null : ($zone ?? $tz));
                $this->expect('c-placement', 'a moved-day option names the day the harness places the day on for it', 'movedDays other', $p['date'], $o['date'], ['option' => $o['id']]);
            }
        }
    }

    /** The bound's days and UNTILs: the last occurrence stored after and before, and each rule's UNTIL. */
    private function placeBound(array $plan, array $before, array $after): void
    {
        $b = $plan['bound'] ?? [];
        $last = static function (array $st): ?string {
            if (!$st['bounded'] || $st['through'] !== null || $st['raw'] === []) {
                return null;
            }
            return Days::date(end($st['raw'])['dn']);
        };
        $until = static fn(array $row): ?string => Shape::parts((string) $row['rrule'])['UNTIL'] ?? null;
        // A bounded series read only in part (more occurrences than the expander gives at once) has a last day the harness hasn't read.
        foreach (['bound.last' => [$after, $b['last'] ?? null], 'bound.from.last' => [$before, $b['from']['last'] ?? null]] as $role => [$st, $got]) {
            if ($st['shape']->cap === 'unreadable') {
                continue;
            }
            if ($st['bounded'] && $st['through'] !== null) {
                $this->unoracled[] = "c-placement: $role of a bounded series past the window read";
                continue;
            }
            $this->expect('c-placement', "$role is the last occurrence's day of the series as stored " . ($role === 'bound.last' ? 'after' : 'before') . ' the edit', $role, $last($st), $got);
        }
        $this->expect('c-placement', 'bound.until is the stored rule\'s UNTIL', 'bound.until', $until($after['row']), $b['until'] ?? null);
        $this->expect('c-placement', 'bound.from.until is the rule\'s UNTIL before the edit', 'bound.from.until', $until($before['row']), $b['from']['until'] ?? null);
    }

    /** plan.start and each rule's start: the stored master's start, each in its own zone. */
    private function placeStart(array $plan, array $before, array $after): void
    {
        foreach (['to' => $after['row'], 'from' => $before['row']] as $side => $m) {
            $s = $plan['rule'][$side]['start'] ?? null;
            $this->expect('c-placement', "rule.$side.start is the stored master's start", "rule.$side.start", (string) $m['start_utc'], is_string($s) ? self::dbKey($s) : null);
        }
        $s = $plan['start'] ?? null;
        $this->expect('c-placement', 'plan.start is the stored master\'s start', 'plan.start', (string) $after['row']['start_utc'], is_string($s) ? self::dbKey($s) : null);
    }

    /**
     * Each zone option's start: the series' start after the change under it.
     * The option chosen is the plan's rule.to.start (checked against the
     * stored master by placeStart); the other is the harness's own, the
     * oracle's first day under that promise (Expect::withZone: the landed
     * clock in the new zone for "clock", the moment's for "moment") at its
     * clock in the new zone, under the same reading. A first day whose clock
     * falls in a gap (stored a period earlier) or a reading that promise
     * doesn't offer is counted as unoracled.
     */
    private function placeZoneStarts(array $plan, array $before, array $edit, array $choices, array $ctx): void
    {
        $group = null;
        foreach ($plan['groups'] ?? [] as $g) {
            if (($g['id'] ?? '') === 'zone') {
                $group = $g;
            }
        }
        if ($group === null) {
            return;
        }
        $chosen = $choices['zone'] ?? $group['selected'] ?? 'clock';
        $x0 = new Expect($before, $edit + ['adopt' => $choices]);
        foreach ($group['options'] ?? [] as $o) {
            $id = (string) ($o['id'] ?? '');
            if (!empty($o['disabled'])) {
                continue; // no series under it (c-shape: no start)
            }
            $got = is_string($o['start'] ?? null) ? self::dbKey($o['start']) : null;
            if ($id === $chosen) {
                $want = is_string($plan['rule']['to']['start'] ?? null) ? self::dbKey($plan['rule']['to']['start']) : null;
                $this->expect('c-placement', 'the chosen zone option\'s start is the plan\'s rule.to.start', 'zone option start (chosen)', $want, $got, ['option' => $id]);
                continue;
            }
            $xz = $x0->withZone($id);
            $readings = array_values(array_filter($xz->readings(), static fn(array $r): bool => $r['enabled']));
            if ($xz->momentOpen) {
                $this->unoracled[] = 'the same moment carrying a monthly or yearly drag across a day boundary';
                continue;
            }
            $byId = array_column($readings, null, 'id');
            $rid = $choices['reading'] ?? Expect::preselected($readings);
            if ($rid === null || !isset($byId[$rid])) {
                $this->unoracled[] = 'c-placement: a zone option\'s start under a reading that promise doesn\'t offer';
                continue;
            }
            $out = $xz->outcome($byId[$rid], $choices);
            if (!empty($out['empty']) || !isset($out['start']) || Clock::inGap((int) $out['start'], $xz->clock1, $xz->tz1)) {
                $this->unoracled[] = 'c-placement: a zone option\'s start on a day whose clock falls in a gap, or with no days';
                continue;
            }
            $at = Clock::at((int) $out['start'], $xz->clock1, $xz->tz1);
            if ($xz->kind === 'newrule' && isset($edit['ruleText'])) {
                // The option writes the rule sent: its own clock parts set the start's clock on that day.
                $first = Rfc::of((string) $edit['ruleText'], $at->getTimestamp(), $xz->allDay1 ? 'UTC' : $xz->tz1, $xz->allDay1)->firstOnStartDay();
                if ($first === null || Clock::inGap((int) $out['start'], $first[1], $xz->tz1)) {
                    $this->unoracled[] = 'c-placement: a zone option\'s start under a new rule the evaluator gives no clock on that day, or one in a gap';
                    continue;
                }
                $at = new \DateTimeImmutable('@' . $first[0]);
            }
            $want = Clock::db($at);
            $this->expect('c-placement', 'the other zone option\'s start is the start of the series that option writes: the oracle\'s first day under that promise, at the clock its rule gives there in the new zone', 'zone option start (other)', $want, $got, ['option' => $id]);
        }
    }

    /** Whether a day is one of the stored series' occurrences, looked for past the window read. */
    private static function isNewDay(array $after, array $ctx, int $dn): bool
    {
        if (isset($ctx['byDay'][$dn])) {
            return true;
        }
        if ($after['through'] === null || $dn < Clock::dayOf($after['through'], $ctx['tz1']) - 1) {
            return false;
        }
        foreach (Truth::keysFrom($after['row'], Days::date($dn - 1) . ' 00:00:00', 3) as $k) {
            if (Clock::dayOf($k, $ctx['tz1']) === $dn) {
                return true;
            }
        }
        return false;
    }

    /**
     * Losses and gains by class (series-model.md, Bounds: Losses and gains
     * by class), worked out the harness's own way over the span the design
     * names: all of a bounded series; of one with no end, from the start
     * through the latest day the change names (the day acted on, where it
     * landed, the start, every exception's day, and the new start), then one
     * whole cycle of the rule's periods (one period daily and weekly,
     * 4,800 / gcd(n, 4,800) monthly, 400 / gcd(n, 400) yearly), then on by
     * the reach, the furthest any image of the cycle moves: old days through
     * the cycle plus twice the reach, new days through the cycle plus the
     * reach. A loss is an old occurrence whose image is no day of the stored
     * series (skipped days, edited days and the day acted on left out, none
     * for a new rule), classed by why (`noday`: no image, or one the new
     * series has no day on; `start`: before the new start; `end`: past the
     * new end) and, for `noday`, by its member; a gain is a stored day that
     * is no old occurrence's image and no stored skip, classed by its
     * member. Each listed class must be one of the harness's: its dates the
     * class's first days (five at most), its count the whole class for a
     * bounded series and null with no end, its rate the class's count over
     * one cycle in calendar periods, reduced, absent when the cycle has
     * none, a member named exactly for `noday` and for every gain; and every
     * class of the harness's must be listed. A rule outside the drag table
     * is read through the days named alone, every day its own image.
     */
    private function placeLossesGains(array $plan, array $before, array $after, array $edit, array $ctx, array $choices): void
    {
        $x = $ctx['x'];
        $tz0 = $ctx['tz0'];
        $newRule = $x->kind === 'newrule';
        $actedDn = Clock::dayOf((string) $edit['actedKey'], $tz0);
        // The day acted on is left out where an outcome names it: a row made for it, or an event of its own.
        $outcomeDays = $ctx['actedAlone'] !== null || $ctx['created'] !== null ? [$actedDn => true] : [];
        foreach (array_merge($before['exdates'], array_map(static fn(array $r): string => (string) $r['recurrence_instance_utc'], $before['overrides'])) as $k) {
            $outcomeDays[Clock::dayOf($k, $tz0)] = true;
        }
        $t = $this->readingOf($x, $choices);
        $s0 = $before['shape'];
        $s1 = $after['shape'];
        $newStart = (int) $after['startDn'];
        $last = $after['bounded'] ? $after['lastDn'] : null;
        // A daily rule with a weekday list repeats by the week (its cycle is one), so the week is its period.
        $unit = $s0->dailyList ? 'week' : $s0->unit();
        // The old occurrences, each with its member: the oracle's (the
        // evaluator's, through its window), then the pattern's own days,
        // through the span the design names and no further.
        $oldDays = [];
        foreach ($before['raw'] as $o) {
            $oldDays[$o['dn']] = $s0->supported() ? $s0->memberOf($o['dn']) : null;
        }
        $cycle = null;
        $gainTo = null;
        if (!$before['bounded'] && $s0->supported() && $s1->supported() && !$newRule && $before['through'] !== null && $oldDays !== []) {
            $named = max(array_merge([$actedDn, $x->landedDn, $before['startDn'], $newStart], array_keys($outcomeDays)));
            [$end, $len, $periods] = self::cycleAfter($s0, $before['startDn'], $named);
            $reach = 0;
            foreach (self::patternDays($s0, $before['startDn'], $named + 1, $end) as $dn => $m) {
                $img = $x->image($t, $dn);
                if ($img !== null) {
                    $reach = max($reach, abs($img - $dn));
                }
            }
            $readTo = $end + 2 * $reach;
            $gainTo = $end + $reach;
            $cycle = ['end' => $end, 'len' => $len, 'periods' => $periods];
            $lastRaw = (int) array_key_last($oldDays);
            foreach (self::patternDays($s0, $before['startDn'], $lastRaw + 1, $readTo) as $dn => $m) {
                $oldDays[$dn] = $m;
            }
            $oldDays = array_filter($oldDays, static fn(int $dn): bool => $dn <= $readTo, ARRAY_FILTER_USE_KEY);
        }
        $inNew = function (int $dn) use ($after, $ctx, $s1, $newStart): bool {
            if (isset($ctx['byDay'][$dn]) || $dn === $newStart) {
                return true;
            }
            if ($after['through'] !== null && $dn >= Clock::dayOf($after['through'], $ctx['tz1']) - 1 && $s1->supported()) {
                return $dn >= $newStart && $s1->has($dn, $newStart);
            }
            return self::isNewDay($after, $ctx, $dn);
        };
        $images = [];
        $lossClasses = [];
        foreach ($oldDays as $dn => $m) {
            $img = $x->image($t, $dn);
            if ($img !== null) {
                $images[$img] = true;
            }
            if ($newRule || isset($outcomeDays[$dn]) || ($img !== null && $inNew($img))) {
                continue;
            }
            $why = $img === null ? 'noday' : ($img < $newStart ? 'start' : ($last !== null && $img > $last ? 'end' : 'noday'));
            self::classAdd($lossClasses, $why === 'noday' ? "noday:$m" : $why, $why, $dn, $cycle === null ? null : [$cycle['end'] - $cycle['len'], $cycle['end']]);
        }
        $this->placeClasses('losses', $plan['losses'] ?? [], $lossClasses, $before['bounded'], $cycle, $unit, true);
        // Gains: a day of the stored series that is no old occurrence's image and no stored skip, through the latest image of a
        // bounded series, through the cycle plus the reach of one with no end.
        $vals = array_keys($images);
        $latest = $vals === [] ? null : max($vals);
        $to = $gainTo ?? $latest;
        $newDays = [];
        foreach ($after['raw'] as $o) {
            $newDays[$o['dn']] = $s1->supported() ? $s1->memberOf($o['dn']) : null;
        }
        if ($cycle !== null && $after['through'] !== null && $newDays !== []) {
            foreach (self::patternDays($s1, $newStart, (int) array_key_last($newDays) + 1, (int) $to) as $dn => $m) {
                $newDays[$dn] = $m;
            }
        }
        $skipped = array_fill_keys(array_map(static fn(string $k): int => Clock::dayOf($k, $ctx['tz1']), $after['exdates']), true);
        $gainClasses = [];
        foreach ($newDays as $dn => $m) {
            if ($newRule || $to === null || $dn > $to || $dn < $newStart || isset($images[$dn]) || isset($skipped[$dn])) {
                continue;
            }
            self::classAdd($gainClasses, (string) $m, 'gain', $dn, $cycle === null ? null : [$to - $cycle['len'], $to]);
        }
        $this->placeClasses('gains', $plan['gains'] ?? [], $gainClasses, $before['bounded'], $cycle, $unit, false);
    }

    /** Adds a day to a class: its first five days, its count, and how many fall in the cycle's span (after $span[0] through $span[1]). */
    private static function classAdd(array &$classes, string $key, string $why, int $dn, ?array $span): void
    {
        $classes[$key] ??= ['why' => $why, 'days' => [], 'count' => 0, 'inCycle' => 0];
        if (count($classes[$key]['days']) < 5) {
            $classes[$key]['days'][] = Days::date($dn);
        }
        $classes[$key]['count']++;
        if ($span !== null && $dn > $span[0] && $dn <= $span[1]) {
            $classes[$key]['inCycle']++;
        }
    }

    /** The plan's classes of one list against the harness's: each listed one is one of the harness's, with its days, count, rate and member; each of the harness's is listed. */
    private function placeClasses(string $list, array $listed, array $classes, bool $bounded, ?array $cycle, string $unit, bool $lossList): void
    {
        $byFirst = [];
        foreach ($classes as $key => $c) {
            $byFirst[$c['days'][0]] = $key;
        }
        $seen = [];
        foreach ($listed as $i => $l) {
            $first = $l['dates'][0] ?? null;
            $key = is_string($first) ? ($byFirst[$first] ?? null) : null;
            $c = $key === null ? null : $classes[$key];
            $this->expect('c-placement', "a listed $list class begins with a day that is one" . ($lossList ? ', lost for the reason listed' : ''), "$list member", true,
                $c !== null && (!$lossList || $c['why'] === ($l['why'] ?? null)), ['class' => $l]);
            if ($c === null) {
                continue;
            }
            $seen[$key] = true;
            $this->expect('c-placement', "a $list class lists its first days, five at most", "$list dates", $c['days'], $l['dates'] ?? null, ['class' => $i]);
            $this->expect('c-placement', "a $list class counts every one of a bounded series, null with no end", "$list count", $bounded ? $c['count'] : null, array_key_exists('count', $l) ? $l['count'] : 'absent', ['class' => $i]);
            $every = null;
            if ($cycle !== null && $c['inCycle'] > 0) {
                $g = self::gcd($c['inCycle'], $cycle['periods']);
                $every = ['count' => intdiv($c['inCycle'], $g), 'span' => intdiv($cycle['periods'], $g), 'unit' => $unit];
            }
            $got = isset($l['every']) && is_array($l['every']) ? ['count' => $l['every']['count'] ?? null, 'span' => $l['every']['span'] ?? null, 'unit' => $l['every']['unit'] ?? null] : null;
            $this->expect('c-placement', "a $list class's rate is its count over one cycle in calendar periods, reduced, absent when the cycle has none", "$list every", $every, $got, ['class' => $i]);
            $named = !$lossList || $c['why'] === 'noday';
            $this->expect('c-placement', "a $list class names its member exactly when it is lost for want of a day or gained, with the rule's period", "$list member name", $named,
                isset($l['member']) && is_string($l['member']) && $l['member'] !== '' && ($l['period'] ?? null) === $unit, ['class' => $i]);
        }
        foreach ($classes as $key => $c) {
            $this->expect('c-placement', "every $list class is listed", "$list complete", true, isset($seen[$key]), ['class' => $c]);
        }
    }

    /**
     * One whole cycle of a rule's periods after $dn's period (series-model.md,
     * Bounds: one cycle per family): its last day, its length in days, and
     * its length in calendar periods.
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function cycleAfter(Shape $s, int $startDn, int $dn): array
    {
        $counted = match ($s->freq) {
            'MONTHLY' => intdiv(4800, self::gcd($s->interval, 4800)),
            'YEARLY' => intdiv(400, self::gcd($s->interval, 400)),
            default => 1,
        };
        $p0 = self::periodOfCycle($s, $startDn);
        $p = self::periodOfCycle($s, $dn) + 1;
        while (!$s->counted($p, $p0)) {
            $p++;
        }
        $first = self::periodFirst($s, $p);
        $end = self::periodFirst($s, $p + $counted * $s->interval) - 1;
        return [$end, $end - $first + 1, $counted * $s->interval];
    }

    /** The period a day is in for the cycle's purposes: a daily rule with a weekday list repeats by the week, as its cycle says. */
    private static function periodOfCycle(Shape $s, int $dn): int
    {
        if ($s->dailyList) {
            $base = $dn - ($s->wkst - 3);
            return intdiv($base - (($base % 7) + 7) % 7, 7);
        }
        return $s->periodOf($dn);
    }

    /** The first day of a period of the harness's reading of a rule. */
    private static function periodFirst(Shape $s, int $p): int
    {
        if ($s->freq === 'MONTHLY') {
            [$y, $m] = Days::fromMi($p);
            return Days::dn($y, $m, 1);
        }
        if ($s->freq === 'WEEKLY' || $s->dailyList) {
            return $p * 7 + ($s->wkst - 3);
        }
        return $s->freq === 'YEARLY' ? Days::dn($p, 1, 1) : $p;
    }

    /**
     * A pattern's days from $from through $to in the periods counted from
     * $startDn, each with its member.
     *
     * @return array<int,?int> day => member
     */
    private static function patternDays(Shape $s, int $startDn, int $from, int $to): array
    {
        $out = [];
        $p0 = $s->periodOf($startDn);
        for ($p = $s->periodOf($from), $lastP = $s->periodOf($to); $p <= $lastP; $p++) {
            if (!$s->counted($p, $p0)) {
                continue;
            }
            foreach ($s->periodDays($p) as [$d, $i]) {
                if ($d >= $from && $d <= $to) {
                    $out[$d] = $i;
                }
            }
        }
        return $out;
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return max(1, $a);
    }

    /**
     * A reading's lists (api-contract, The plan): `dates`, its rule's dates
     * from the day acted on through its first one that differs from the
     * other reading's (three at least, or the first three and `then`, the
     * last shared and the first different, when that is further on); and
     * `earlier`, where its events before the day acted on first differ, event
     * by event by instant and kind, from the series as it is today, the earlier of the two by
     * instant, named by its series. The contract leaves three things open:
     * whether a reading's days are its rule unbounded or its series as
     * bounded, whether a gain at an edge (added, or left out as a generated skip) is one
     * of them, and whether "before the day acted on" is before its old day
     * or before where it landed. Every reading of those is worked out, and
     * a value is asserted only where they all agree; elsewhere the case is
     * counted as one the design leaves open.
     */
    private function placeReadings(array $plan, array $before, array $choices, array $ctx): void
    {
        $x = $ctx['x'];
        $group = null;
        foreach ($plan['groups'] ?? [] as $g) {
            if (($g['id'] ?? '') === 'reading') {
                $group = $g;
            }
        }
        if ($group === null) {
            return;
        }
        $byId = [];
        foreach ($x->readings() as $r) {
            $byId[$r['id']] = $r;
        }
        $clock = $x->ruleClock();
        $instant = static fn(int $d): int => $x->allDay1 ? Clock::parse(Days::date($d) . ' 00:00:00')->getTimestamp() : Clock::at($d, $clock, $x->tz1)->getTimestamp();
        $entry = static fn(int $d): array => $x->allDay1
            ? ['start' => Days::date($d) . 'T00:00:00+00:00', 'allDay' => true]
            : ['start' => Clock::iso(Clock::at($d, $clock, $x->tz1)), 'allDay' => false];
        // The series as it is today, before the day acted on: each event's instant, kind and day in the old zone.
        $actedAt = Clock::parse($x->actedKey())->getTimestamp();
        $old = [];
        foreach ($before['raw'] as $o) {
            if (Clock::parse($o['key'])->getTimestamp() < $actedAt) {
                $old[] = ['ts' => Clock::parse($o['key'])->getTimestamp(), 'allDay' => $before['allDay'], 'date' => Days::date($o['dn'])];
            }
        }
        // Each enabled option's days, in each of the open readings.
        $seqs = [];
        foreach ($group['options'] ?? [] as $o) {
            $r = $byId[$o['id'] ?? ''] ?? null;
            if ($r === null || !$r['enabled'] || !empty($o['disabled'])) {
                continue;
            }
            $outR = $x->outcome($r, ['reading' => $r['id']] + $choices);
            if (!empty($outR['empty'])) {
                continue;
            }
            // A gain at an edge, added or left out (a generated skip), is one of its days or not: the contract leaves it open.
            $gen = array_fill_keys(array_merge($outR['gen'], $outR['edge']), true);
            $walk = $x->readingDays($r, (int) $outR['start']);
            $noGen = static fn(array $l): array => array_values(array_filter($l, static fn(int $d): bool => !isset($gen[$d])));
            $seqs[$o['id']] = ['o' => $o, 'r' => $r, 'start' => (int) $outR['start'], 'gen' => $gen,
                'v' => ['rule' => $walk, 'rule without edge gains' => $noGen($walk), 'series' => $outR['days'], 'series without edge gains' => $noGen($outR['days'])]];
        }
        foreach ($seqs as $id => $s) {
            if (!array_key_exists('earlier', $s['o'])) {
                continue; // c-shape says where an option must carry it
            }
            // Event by event, by instant and kind, never by day number (two
            // zones' day numbers name different days): the first index where
            // the reading's events before the day acted on and the series'
            // today differ, the earlier of the two, named by its own series.
            $wants = [];
            foreach ($s['v'] as $days) {
                foreach ([$actedAt, $instant($x->landedDn)] as $edge) {
                    $mine = [];
                    foreach ($days as $d) {
                        if ($instant($d) < $edge) {
                            $mine[] = ['ts' => $instant($d), 'allDay' => $x->allDay1, 'date' => Days::date($d)];
                        }
                    }
                    $want = [null, null];
                    for ($j = 0, $n = max(count($mine), count($old)); $j < $n; $j++) {
                        $m = $mine[$j] ?? null;
                        $w = $old[$j] ?? null;
                        if ($m === null || $w === null || $m['ts'] !== $w['ts'] || $m['allDay'] !== $w['allDay']) {
                            $newFirst = $m !== null && ($w === null || $m['ts'] < $w['ts']);
                            $want = $newFirst ? [$m['date'], 'new'] : [$w['date'], 'old'];
                            break;
                        }
                    }
                    $wants[json_encode($want)] = $want;
                }
            }
            if (count($wants) > 1) {
                $this->unoracled[] = 'c-placement: a reading\'s earlier where the contract\'s open readings disagree';
                continue;
            }
            $o = $s['o'];
            $this->expect('c-placement', 'a reading\'s earlier is where its events before the day acted on first differ from the series today, by instant and kind, the earlier of the two, named by its own series', 'reading earlier', reset($wants), [$o['earlier'] ?? null, $o['earlierSeries'] ?? null], ['option' => $id]);
        }
        if (count($seqs) !== 2) {
            if (count($seqs) > 2) {
                $this->unoracled[] = 'c-placement: a reading\'s dates against "the other reading\'s" with more than two readings offered';
            }
            return;
        }
        $ids = array_keys($seqs);
        // How far two readings' rules are compared: Finding the Readings
        // merges two only when their occurrences are the same "for as long as
        // the rule could run (the walk below, past the bound, not just the
        // series' span)", and the walk is No Loss, Proved per Family's, by
        // periods of the rule: "that many periods ... finding no loss or merge
        // there proves there is none anywhere" (Expect::readingCycle). So the
        // rules are compared by their days, past every day that isn't
        // periodic (both starts, the day acted on, every gain at an edge),
        // through one whole cycle of both rules: walks that agree over it
        // agree for all time, so `then` can name nothing past it. The end of
        // year 9999 ends every rule. The days are compared a stretch at a
        // time and nothing else ends a comparison; the walk reads on three
        // cycles past it only to list a reading's first three dates.
        $cycles = array_map(static fn(string $sid): ?int => $x->readingCycle($seqs[$sid]['r']), $ids);
        if (in_array(null, $cycles, true)) {
            $this->unoracled[] = 'c-placement: a reading\'s dates where a rule\'s periods can\'t be read, so its comparison with the other reading has no bound';
            return;
        }
        [$g, $b] = $cycles;
        while ($b !== 0) {
            [$g, $b] = [$b, $g % $b];
        }
        $far = max($x->landedDn, $seqs[$ids[0]]['start'], $seqs[$ids[1]]['start']);
        foreach ($ids as $sid) {
            foreach (array_keys($seqs[$sid]['gen']) as $d) {
                $far = max($far, $d + 1);
            }
        }
        $cycle = intdiv($cycles[0], $g) * $cycles[1];
        $end = Days::dn(9999, 12, 31);
        $far = min($far + $cycle - 1, $end);
        $list = min($far + 3 * $cycle, $end);
        // The plan's own list bounds how many days a comparison keeps.
        $keep = array_map(static fn(string $sid): int => max(3, count($seqs[$sid]['o']['dates'] ?? [])), $ids);
        $forms = [[], []];
        foreach (array_keys($seqs[$ids[0]]['v']) as $v) {
            $streams = [];
            foreach ($ids as $sid) {
                $sq = $seqs[$sid];
                $streams[] = str_starts_with($v, 'rule')
                    ? (static function () use ($x, $sq, $v, $list): \Generator {
                        foreach ($x->readingStream($sq['r'], max($sq['start'], $x->landedDn), $list) as $d) {
                            if ($v === 'rule' || !isset($sq['gen'][$d])) {
                                yield $d;
                            }
                        }
                    })()
                    : (static function () use ($x, $sq, $v): \Generator {
                        foreach ($sq['v'][$v] as $d) {
                            if ($d >= $x->landedDn) {
                                yield $d;
                            }
                        }
                    })();
            }
            foreach (self::compareDays($streams[0], $streams[1], $keep, str_starts_with($v, 'rule') ? $far : null) as $k => $form) {
                $forms[$k][json_encode(array_diff_key($form, ['prefix' => 1]))] = $form;
            }
        }
        foreach ($ids as $k => $id) {
            if (count($forms[$k]) > 1) {
                $this->unoracled[] = 'c-placement: a reading\'s dates where the contract\'s open readings disagree';
                continue;
            }
            $form = reset($forms[$k]);
            $o = $seqs[$id]['o'];
            $dates = array_map(static fn(array $e): array => self::norm($e, ['start', 'allDay', 'tz']), $o['dates'] ?? []);
            $then = array_map(static fn(array $e): array => self::norm($e, ['start', 'allDay', 'tz']), $o['then'] ?? []);
            // Through the first different date, or the first three and then: the contract's "further on" reads either way from the fourth date on.
            $through = $then === [] && count($dates) === $form['length'] && $dates === array_map($entry, array_slice($form['prefix'], 0, $form['length']));
            $split = $form['split'] !== null && $dates === array_map($entry, $form['split'][0]) && $then === array_map($entry, $form['split'][1]);
            $want = ['differs' => $form['differs'], 'shared' => $form['shared'], 'length' => $form['length'], 'first' => array_map($entry, array_slice($form['prefix'], 0, min(6, $form['length']))),
                'split' => $form['split'] === null ? null : [array_map($entry, $form['split'][0]), array_map($entry, $form['split'][1])]];
            $this->expect('c-placement', 'a reading\'s dates run from the day acted on through its first date that differs from the other\'s, or the first three and then', 'reading dates', true, $through || $split, ['option' => $id, 'want' => $want, 'dates' => $dates, 'then' => $then]);
        }
    }

    /**
     * Two readings' days compared in order, one day at a time. For each
     * side: whether it has a day where they part (`differs`), how many days
     * they share before it, its `dates` through that day (`length` of them,
     * three at least, by a digest, with the first $keep of them kept as
     * `prefix`), and `split`, its first three with `then` (the last shared
     * day and its first different one) when they part past the third.
     *
     * Two rules are compared through $bound and agree for all time when
     * they agree that far; their days past it list their first three.
     *
     * @param array{0:int,1:int} $keep
     * @return array{0:array,1:array}
     */
    private static function compareDays(\Generator $a, \Generator $b, array $keep, ?int $bound): array
    {
        $got = [[], []];
        $hash = [hash_init('sha1'), hash_init('sha1')];
        $take = static function (int $k, int $d) use (&$got, &$hash, $keep): void {
            hash_update($hash[$k], pack('q', $d));
            if (count($got[$k]) < $keep[$k]) {
                $got[$k][] = $d;
            }
        };
        $shared = 0;
        $last = null;
        $past = static fn(\Generator $s): bool => $bound !== null && (!$s->valid() || $s->current() > $bound);
        while ($a->valid() && $b->valid() && $a->current() === $b->current() && !$past($a)) {
            $last = $a->current();
            $take(0, $last);
            $take(1, $last);
            $shared++;
            $a->next();
            $b->next();
        }
        $agreed = $past($a) && $past($b);
        $out = [];
        foreach ([$a, $b] as $k => $s) {
            if ($agreed || !$s->valid()) {
                // Nothing past what they share: its first three, read on past the bound where they agree.
                while ($agreed && $s->valid() && count($got[$k]) < 3) {
                    $take($k, $s->current());
                    $s->next();
                }
                $h = hash_init('sha1');
                foreach (array_slice($got[$k], 0, 3) as $d) {
                    hash_update($h, pack('q', $d));
                }
                $out[$k] = ['differs' => false, 'shared' => null, 'length' => min(3, count($got[$k])), 'digest' => hash_final($h), 'prefix' => $got[$k], 'split' => null];
                continue;
            }
            $first = $s->current();
            $length = $shared;
            while ($s->valid() && $length < max(3, $shared + 1)) {
                $take($k, $s->current());
                $length++;
                $s->next();
            }
            $out[$k] = ['differs' => true, 'shared' => $shared, 'length' => $length, 'digest' => hash_final(hash_copy($hash[$k])), 'prefix' => $got[$k],
                'split' => $shared >= 3 ? [array_slice($got[$k], 0, 3), [$last, $first]] : null];
        }
        return $out;
    }

    /**
     * The instants a wall clock names on a day in a zone, by its transitions
     * (tzdata), whatever their size: none in a gap, two where the clock
     * happens twice, one otherwise.
     *
     * @return list<int>
     */
    private static function wallInstants(int $dn, int $secs, string $tz): array
    {
        $zone = Clock::zone($tz);
        $wall = Clock::parse(Days::date($dn) . ' 00:00:00')->getTimestamp() + $secs;
        $offsets = [];
        foreach ($zone->getTransitions($wall - 2 * 86400, $wall + 2 * 86400) ?: [] as $t) {
            $offsets[(int) $t['offset']] = true;
        }
        $out = [];
        foreach (array_keys($offsets) as $off) {
            $t = $wall - $off;
            if ($zone->getOffset(new \DateTimeImmutable('@' . $t)) === $off) {
                $out[] = $t;
            }
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /**
     * Notes on a clock that doesn't exist or happens twice, by tzdata: every
     * day of the stored series among the dates the plan shows (coming up) or
     * from the cutoff through a year (series-model.md, the clock-notes
     * sentence) on whose clock
     * names no instant (gap) or two (overlap) must have its note, and every
     * note must be such a day, at that occurrence's own instant (its key, for
     * a gap and an overlap alike), in the series' zone. An overlap note
     * carries `fold` (the instant's place among the wall clock's instants)
     * and `daylight` (by tzdata), each checked on its own.
     */
    private function placeNotes(array $plan, array $after, array $edit, array $ctx): void
    {
        $m1 = $after['row'];
        $listed = [];
        foreach ($plan['notes'] ?? [] as $n) {
            if (in_array($n['kind'] ?? '', ['gap', 'overlap'], true)) {
                $listed[$n['kind'] . ' ' . ($n['date'] ?? '')] = $n;
            }
        }
        $want = [];
        if ((int) $m1['all_day'] === 0 && $after['shape']->cap !== 'unreadable') {
            $tz = (string) $m1['tzid'];
            $clock = Clock::secsOf(Clock::local((string) $m1['start_utc'], $tz));
            $cut = self::cutoff($after, $edit, $ctx);
            // The design's window: the dates the plan shows (coming up, as c-next assembles it from the stored rows) and a year from the cutoff.
            $shown = [];
            foreach (array_slice($this->eligible($after, $edit, $ctx, $cut), 0, self::NEXT_LIMIT) as $item) {
                $shown[$item['dn']] = true;
            }
            $to = max([$cut + 366, ...array_keys($shown)]);
            // From two days before the cutoff's UTC midnight: a zone east of UTC starts its day the evening before.
            foreach (Truth::keysFrom($m1, Days::date($cut - 2) . ' 00:00:00', $to - $cut + 4) as $k) {
                $dn = Clock::dayOf($k, $tz);
                if ($dn < $cut || ($dn > $cut + 366 && !isset($shown[$dn]))) {
                    continue;
                }
                $at = self::wallInstants($dn, $clock, $tz);
                $kind = match (count($at)) {
                    0 => 'gap',
                    1 => null,
                    default => 'overlap',
                };
                if ($kind !== null) {
                    // The series' own instant on that day, the key the expander yields, for a gap and an overlap alike.
                    $t = Clock::parse($k)->getTimestamp();
                    $local = static fn(int $i): \DateTimeImmutable => (new \DateTimeImmutable('@' . $i))->setTimezone(Clock::zone($tz));
                    $w = ['at' => Clock::iso($local($t))];
                    if ($kind === 'overlap') {
                        // Its place among the wall clock's instants that day, and whether it is in daylight time by tzdata:
                        // false when it isn't but the other instant is, null when neither is.
                        $pos = array_search($t, $at, true);
                        if ($pos === false) {
                            continue; // the series' instant that day is neither of the clock's (a clock carried elsewhere): nothing to note
                        }
                        $other = $at[$pos === 0 ? 1 : 0];
                        $w['fold'] = match ($pos) {
                            0 => 'first',
                            1 => 'second',
                            default => null,
                        };
                        $w['daylight'] = $local($t)->format('I') === '1' ? true : ($local($other)->format('I') === '1' ? false : null);
                    }
                    $want[$kind . ' ' . Days::date($dn)] = $w;
                }
            }
        }
        foreach ($want as $id => $w) {
            $kind = strstr($id, ' ', true);
            $this->expect('c-placement', "a day of the stored series whose clock " . ($kind === 'gap' ? 'doesn\'t exist' : 'happens twice') . ' there has its note', "notes ($kind) present", true, isset($listed[$id]), ['day' => $id, 'cutoff' => Days::date($cut), 'listed' => array_keys($listed)]);
            if (!isset($listed[$id])) {
                continue;
            }
            $n = $listed[$id];
            $this->expect('c-placement', 'a note\'s at is the series\' own instant on that day, the key the expander yields', "notes at ($kind)", $w['at'], self::norm($n, ['at'])['at'] ?? null, ['note' => $n]);
            if ($kind === 'overlap') {
                $this->expect('c-placement', 'an overlap note carries fold and daylight', 'notes (overlap) fold and daylight', true, array_key_exists('fold', $n) && array_key_exists('daylight', $n), ['note' => $n]);
                $this->expect('c-placement', 'an overlap note\'s fold is the instant\'s place among the wall clock\'s instants that day, by tzdata', 'notes (overlap) fold', $w['fold'], $n['fold'] ?? null, ['note' => $n]);
                $this->expect('c-placement', 'an overlap note\'s daylight says whether its instant is in daylight time by tzdata (false when only the other is, null when neither)', 'notes (overlap) daylight', $w['daylight'], $n['daylight'] ?? null, ['note' => $n]);
            }
        }
        foreach ($listed as $id => $n) {
            $this->expect('c-placement', 'a note names a day of the stored series whose clock doesn\'t exist or happens twice there, by tzdata', 'notes (' . $n['kind'] . ')', true, isset($want[$id]), ['note' => $n, 'cutoff' => $cut === null ? null : Days::date($cut), 'derived' => $want]);
        }
    }
}
