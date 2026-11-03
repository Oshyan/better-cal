<?php
// The invariants (README, The Invariants), universal over the delta vector:
// (a) a zero delta leaves its stored thing untouched on every row; (b) a
// non-zero delta reaches every affected row, own fields exempt as the
// design's ownership rules say; (c) the plan equals the persisted series;
// (d) the day acted on shows where it was put; (e) a refusal or a preview
// writes nothing; (f) Undo is byte-identical and redo gives the same rows;
// (g) the start is a raw occurrence and a day of its rule; (h) exceptions
// attach to raw days; (i) a Google calendar is offered only edits that
// keep the rule text. Ground truth is Truth (the expander), the mapping is
// Expect's.
declare(strict_types=1);

namespace BetterCal\EditHarness;

use BetterCal\Http\HttpError;

trait Invariants
{
    /** Content columns of each own-able field. */
    private static function fieldValue(string $f, array $row, array $st): mixed
    {
        $id = (int) $row['id'];
        return match ($f) {
            'title' => (string) $row['title'],
            'location' => (string) ($row['location'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'url' => (string) ($row['url'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'reminders' => self::reminders($row['reminders_json'] ?? null),
            'tags' => $st['tags'][$id] ?? null,
            'people' => $st['people'][$id] ?? null,
            'attendance' => (string) ($row['attendance'] ?? ''),
        };
    }

    private static function reminders(mixed $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $list = json_decode((string) $json, true);
        if (!is_array($list)) {
            return null;
        }
        $out = array_map(static fn($r): int => (int) (is_array($r) ? ($r['minutes'] ?? 0) : $r), $list);
        sort($out);
        return $out;
    }

    private static function sentValue(string $f, mixed $v): mixed
    {
        if ($f === 'tags' || $f === 'people' || $f === 'reminders') {
            $v = array_values($v);
            sort($v);
        }
        return $v;
    }

    // ---- (g) and (h) -----------------------------------------------------

    /** (g) The start is the first raw occurrence and a day of its rule; the harness's reading of the rule agrees with the expander. */
    private function checkSeries(string $inv, array $st): void
    {
        if (!$st['live']) {
            return;
        }
        $row = $st['row'];
        $raw = $st['raw'];
        if ($st['phantom'] !== null) {
            $this->fail('quirk-until-phantom', 'the expander yields the UNTIL as an occurrence (sabre/vobject 4.6.1, a 1st of a month)', ['until' => $st['phantom'], 'rrule' => $row['rrule']]);
        }
        if ($st['shape']->cap === 'unreadable') {
            return;
        }
        $this->check($inv, $raw !== [] && $raw[0]['key'] === (string) $row['start_utc'], 'the stored start is the first raw occurrence', ['start' => $row['start_utc'], 'first' => $raw[0]['key'] ?? null, 'rrule' => $row['rrule']]);
        $shape = $st['shape'];
        if (!$shape->supported() || $raw === []) {
            return;
        }
        $this->check($inv, $shape->memberOf($st['startDn']) !== null, 'the stored start is a day of its rule', ['start' => $row['start_utc'], 'rrule' => $row['rrule']]);
        // The harness's evaluator against the expander, on every series it reads.
        $to = $st['through'] === null ? end($raw)['dn'] : Clock::dayOf($st['through'], $st['tz']) - 1;
        $mine = $shape->days($st['startDn'], $st['startDn'], $to);
        $theirs = array_values(array_unique(array_column($raw, 'dn')));
        if ($shape->memberOf($st['startDn']) === null) {
            array_shift($theirs); // a start off its rule is yielded anyway (invariant g says so); compare the rest
        }
        if ($st['through'] === null) {
            $mine = array_slice($mine, 0, count($theirs));
        } else {
            $theirs = array_values(array_filter($theirs, static fn(int $d): bool => $d <= $to));
        }
        if ($mine !== $theirs) {
            $this->fail('H-evaluator', 'the harness reads the rule\'s days differently from the expander', ['rrule' => $row['rrule'], 'start' => $row['start_utc']] + self::firstDiff($mine, $theirs, true));
        }
    }

    /** (h) Every skipped day and edited day's key is a raw occurrence of its rule; none past a bounded series' end; one edited row per day. */
    private function checkAttached(string $inv, array $st): void
    {
        if (!$st['live'] || $st['shape']->cap === 'unreadable') {
            return;
        }
        $row = $st['row'];
        $last = $st['raw'] === [] ? '' : end($st['raw'])['key'];
        $past = fn(string $k): bool => $st['bounded'] && $last !== '' && $k > $last;
        foreach ($st['exdates'] as $k) {
            if (!Truth::member($st['exp'], $row, $k)) {
                $this->fail($inv . ($past($k) ? '-past-end' : ''), 'a skipped day is no raw occurrence of the rule' . ($past($k) ? ' (past the series\' end)' : ''), ['exdate' => $k, 'rrule' => $row['rrule'], 'start' => $row['start_utc']]);
            }
        }
        $seen = [];
        foreach ($st['overrides'] as $ov) {
            $k = (string) $ov['recurrence_instance_utc'];
            if (!Truth::member($st['exp'], $row, $k)) {
                $this->fail($inv . ($past($k) ? '-past-end' : ''), 'an edited day is keyed on no raw occurrence (it shows beside the series)' . ($past($k) ? ' (past the series\' end)' : ''), ['key' => $k, 'title' => $ov['title'], 'rrule' => $row['rrule'], 'start' => $row['start_utc']]);
            }
            $this->check($inv, !isset($seen[$k]), 'two edited rows for one day', ['key' => $k]);
            $seen[$k] = true;
        }
    }

    // ---- dispatch ----------------------------------------------------------

    private function checkEdit(array $before, array $after, array $acted, array $v, array $sent, array $s0): void
    {
        $newId = $this->newMaster((int) $before['row']['id']);
        $new = $newId === null ? null : $this->state($newId);
        $this->checkSeries('g', $after);
        $this->checkAttached('h', $after);
        if ($new !== null) {
            $this->checkSeries('g', $new);
            $this->checkAttached('h', $new);
        }
        $first = $acted['key'] === (string) $before['row']['start_utc'];
        switch ($sent['kind']) {
            case 'all':
                $this->checkUntouched($before, $after, $v, $sent, $new);
                $this->checkWhole($before, $after, $sent, $new);
                return;
            case 'following':
                $this->checkUntouched($before, $after, $v, $sent, $new);
                if ($first) {
                    $this->checkWhole($before, $after, $sent, $new);
                } else {
                    $this->checkFollowing($before, $after, $acted, $sent, $new);
                }
                return;
            case 'deleteThis':
                $this->checkDeleteThis($before, $after, $acted, $s0);
                return;
            case 'deleteFollowing':
                $this->checkDeleteFollowing($before, $after, $acted, $first);
                return;
            case 'attendAll':
                $this->checkAttend($before, $after, $new, null);
                return;
            case 'attendFollowing':
                $this->checkAttend($before, $after, $new, $first ? null : $acted);
                return;
        }
    }

    /** The series a split made in this case, if any. */
    private function newMaster(int $id): ?int
    {
        $v = $this->db->scalar('SELECT id FROM events WHERE user_id = ? AND id >= ? AND id <> ? AND recurrence_parent_id IS NULL AND rrule IS NOT NULL AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$this->userId, $this->firstId, $id]);
        return $v === null ? null : (int) $v;
    }

    /** Standalone events this case made (no rule, no parent), live. */
    private function standalones(): array
    {
        return $this->db->all('SELECT * FROM events WHERE user_id = ? AND id >= ? AND recurrence_parent_id IS NULL AND rrule IS NULL AND deleted_at IS NULL ORDER BY id', [$this->userId, $this->firstId]);
    }

    // ---- (a) ---------------------------------------------------------------

    /**
     * (a) Every delta that is zero leaves its stored thing untouched on
     * every row, read straight from the rows before and after, with no
     * oracle: content fields, tags, people, the trip flag, zones and
     * all-day flags, lengths, local days, and for an edit that sends no
     * time, no rule and no bound, every key, shown time and skip.
     */
    private function checkUntouched(array $before, array $after, array $v, array $sent, ?array $new): void
    {
        $rows0 = [(int) $before['row']['id'] => $before['row']] + $before['overrides'];
        $rows1 = [(int) $after['row']['id'] => $after['row']] + $after['overrides'];
        $st1 = $after;
        if ($new !== null) {
            $rows1 += [(int) $new['row']['id'] => $new['row']] + $new['overrides'];
            $st1['tags'] += $new['tags'];
            $st1['people'] += $new['people'];
        }
        foreach (Delta::FIELDS as $f) {
            if (array_key_exists($f, $sent['edit']['fields'] ?? $v['fields'])) {
                continue;
            }
            foreach ($rows0 as $id => $r0) {
                if (!isset($rows1[$id])) {
                    continue;
                }
                $a = self::fieldValue($f, $r0, $before);
                $b = self::fieldValue($f, $rows1[$id], $st1);
                $this->check('a-fields', $a === $b, "a field the edit didn't change ($f) is untouched on every row", ['field' => $f, 'row' => $id, 'was' => $a, 'now' => $b]);
            }
        }
        if (!$v['trip']) {
            foreach ($rows0 as $id => $r0) {
                if (isset($rows1[$id])) {
                    $this->check('a-trip', (int) $r0['is_container'] === (int) $rows1[$id]['is_container'], 'the trip flag is untouched on every row when not sent', ['row' => $id]);
                }
            }
        }
        $edit = $sent['edit'];
        $timeSent = $edit['sent'] !== null;
        $ruleOrBound = $edit['rule'] === 'new' || $edit['boundSent'] !== null;
        if (!$timeSent && !$ruleOrBound) {
            // A field-only edit keeps every key, shown time and skip exactly as stored, whatever the rule.
            foreach (['start_utc', 'end_utc', 'tzid', 'all_day', 'rrule', 'exdates_json'] as $col) {
                $this->check('a-time', (string) $before['row'][$col] === (string) $after['row'][$col], "an edit with no time, rule or bound keeps the series' $col", ['was' => $before['row'][$col], 'now' => $after['row'][$col]]);
            }
            foreach ($before['overrides'] as $id => $ov) {
                $ov1 = $after['overrides'][$id] ?? null;
                $this->check('a-time', $ov1 !== null, 'an edit with no time, rule or bound keeps every edited day', ['row' => $id, 'key' => $ov['recurrence_instance_utc']]);
                if ($ov1 !== null) {
                    foreach (['recurrence_instance_utc', 'start_utc', 'end_utc', 'tzid', 'all_day'] as $col) {
                        $this->check('a-time', (string) $ov[$col] === (string) $ov1[$col], "an edit with no time, rule or bound keeps each edited day's $col", ['row' => $id, 'was' => $ov[$col], 'now' => $ov1[$col]]);
                    }
                }
            }
            return;
        }
        $toggled = $v['allDay'];
        foreach ($rows0 as $id => $r0) {
            $r1 = $rows1[$id] ?? null;
            if ($r1 === null || ($r1['rrule'] === null && $r1['recurrence_parent_id'] === null && $r0['recurrence_parent_id'] !== null)) {
                continue; // removed, or an event of its own now (an outcome the plan names)
            }
            if ($v['length'] === 0 && !$toggled) {
                $l0 = Clock::parse((string) $r0['end_utc'])->getTimestamp() - Clock::parse((string) $r0['start_utc'])->getTimestamp();
                $l1 = Clock::parse((string) $r1['end_utc'])->getTimestamp() - Clock::parse((string) $r1['start_utc'])->getTimestamp();
                $this->check('a-length', $l0 === $l1, 'with no length change every row keeps its length', ['row' => $id, 'was' => $l0, 'now' => $l1]);
            }
            if ($v['zone'] === null && !$toggled && $edit['fromRow'] !== $id) {
                $this->check('a-zone', (string) $r0['tzid'] === (string) $r1['tzid'] && (int) $r0['all_day'] === (int) $r1['all_day'], 'with no zone or all-day change every row keeps its zone and all-day flag', ['row' => $id, 'was' => [$r0['tzid'], $r0['all_day']], 'now' => [$r1['tzid'], $r1['all_day']]]);
            }
        }
    }

    // ---- whole series --------------------------------------------------------

    /** The oracle's reading of the state before (Expect's $old). */
    private static function oldFor(array $st): array
    {
        return $st;
    }

    /**
     * A whole-series edit: the plan's readings and groups against the
     * design's, the persisted series against the oracle's expectation for
     * the options chosen, every edited day placed and its fields as its
     * own list says, the plan against what was written, and Google.
     */
    private function checkWhole(array $before, array $after, array $sent, ?array $new): void
    {
        $edit = $sent['edit'];
        $plan = $sent['_plan'];
        $choices = $sent['_choices'] ?? [];
        if (!$before['shape']->supported()) {
            $this->checkUnsupported($before, $after, $edit, $choices, $plan);
            return;
        }
        $x0 = new Expect($before, $edit);
        $x = $x0->withZone($choices['zone'] ?? null);
        $groups = [];
        foreach ($plan['groups'] ?? [] as $g) {
            $groups[$g['id']] = $g;
        }
        $pre = !empty($this->spec['preselected']['edit']) || $sent['kind'] === 'following';
        $this->check('c-groups', isset($groups['zone']) === $x0->zoneGroup, $x0->zoneGroup ? 'a zone change alone offers the same clock or the same moment' : 'a zone reading is offered where the design has none', ['offered' => isset($groups['zone'])]);
        if (isset($groups['zone']) && $pre) {
            $this->check('c-preselect', $groups['zone']['selected'] === 'clock', 'the same clock time is preselected for a zone change', ['selected' => $groups['zone']['selected']]);
        }
        $why = $x->refusal();
        if ($why !== null) {
            $this->fail('e-refusal', 'the design refuses this edit (' . $why . '), and it was applied', ['rrule' => $before['row']['rrule']]);
            return;
        }
        // Readings: the plan's against the design's.
        $readings = $x->readings();
        $byId = [];
        foreach ($readings as $r) {
            $byId[$r['id']] = $r;
        }
        $offered = [];
        foreach ($groups['reading']['options'] ?? [] as $opt) {
            $offered[$opt['id']] = empty($opt['disabled']);
        }
        $enabled = array_values(array_filter($readings, static fn(array $r): bool => $r['enabled']));
        if ($enabled === []) {
            $this->fail('e-refusal', 'the design refuses every reading of this edit (' . ($readings[0]['why'] ?? '') . '), and it was applied', ['readings' => array_column($readings, 'id')]);
            return;
        }
        foreach ($offered as $id => $on) {
            if (!isset($byId[$id])) {
                $this->fail('c-readings', "the plan offers a reading the design doesn't name: $id", ['offered' => array_keys($offered), 'design' => array_column($readings, 'id')]);
            } elseif ($on && !$byId[$id]['enabled']) {
                $this->fail('c-readings', "the plan offers a reading the design refuses ($id: {$byId[$id]['why']})", ['offered' => $offered]);
            } elseif (!$on && $byId[$id]['enabled']) {
                $this->fail('c-readings', "the plan refuses a reading the design allows: $id", ['offered' => $offered]);
            }
        }
        // A reading picked from an earlier preview names nothing when the final
        // plan has no reading group (another zone option left one reading).
        $chosenId = isset($groups['reading']) ? ($choices['reading'] ?? null) : null;
        if ($chosenId === null) {
            // No group: one reading, or readings with one plan (merged).
            $chosenId = Expect::preselected($readings);
            foreach ($enabled as $r) {
                if ($r['id'] !== $chosenId && !$x->samePlans($byId[$chosenId], $r, $choices)) {
                    $this->fail('c-readings', "readings with different plans were merged: $chosenId and {$r['id']}", ['design' => array_column($enabled, 'id')]);
                }
            }
        } else {
            foreach ($enabled as $r) {
                if (!isset($offered[$r['id']])) {
                    $merged = false;
                    foreach ($offered as $oid => $on) {
                        if ($on && isset($byId[$oid]) && $x->samePlans($byId[$oid], $r, $choices)) {
                            $merged = true;
                        }
                    }
                    $this->check('c-readings', $merged, "a reading the design offers is missing and has a plan of its own: {$r['id']}", ['offered' => array_keys($offered)]);
                }
            }
            if ($pre && isset($groups['reading']) && $this->kindIsDrag($x)) {
                $want = null;
                foreach ($readings as $r) {
                    if ($r['enabled'] && isset($offered[$r['id']]) && $offered[$r['id']]) {
                        $want = $r['id'];
                        break;
                    }
                }
                $this->check('c-preselect', $want === null || $groups['reading']['selected'] === $want, 'the preselected reading is the design\'s (open decision 1)', ['selected' => $groups['reading']['selected'], 'design' => $want]);
            }
        }
        $reading = $byId[$chosenId] ?? null;
        if ($reading === null) {
            $this->unoracled[] = "reading $chosenId";
            return;
        }
        $out = $x->outcome($reading, $choices);
        if ($chosenId === 'map' && isset($reading['T']['alt'])) {
            // The design names no direction for "the old days shifted": either way it can be read.
            $other = $reading;
            $other['T']['delta'] = $reading['T']['alt'];
            $alt = $x->outcome($other, $choices);
            if (($after['raw'][0]['dn'] ?? null) === ($alt['days'][0] ?? null)) {
                $out = $alt;
            }
            $this->unoracled[] = 'the drag mapping for a new rule: the design names no direction for the shift, either is accepted';
        }
        if (!empty($out['empty'])) {
            $this->fail('e-refusal', 'the design refuses an edit that leaves no events, and it was applied', []);
            return;
        }
        foreach ($x->unoracled as $u) {
            $this->fail('H-oracle', 'the harness\'s mapping disagrees with itself: ' . $u, []);
        }
        // Groups the outcome calls for.
        if ($x->boundDiffers($reading, $choices)) {
            $this->check('c-groups', isset($groups['bound']), 'keeping the count and keeping the end give different plans, and no bound choice is offered', []);
        }
        if (isset($groups['bound']) && $pre) {
            $countFirst = $before['shape']->count !== null && ($x->outcome($reading, ['bound' => 'count'] + $choices)['actedInside'] ?? false);
            $this->check('c-preselect', $groups['bound']['selected'] === ($countFirst ? 'count' : 'end'), $countFirst ? '"keep N events" is preselected when the dragged day is among the N' : '"keep the end" is preselected', ['selected' => $groups['bound']['selected']]);
        }
        $edgeIn = array_values(array_intersect($out['edge'], $out['days']));
        if ($edgeIn !== []) {
            $this->check('c-groups', isset($groups['edge']), 'the new rule gains a day at an edge and no choice is offered', ['gains' => array_map([Days::class, 'date'], $edgeIn)]);
        }
        $outsideRows = array_filter($out['rows'], static fn(array $r): bool => !$r['inside'] && !$r['acted']);
        if ($outsideRows !== []) {
            $this->check('c-groups', isset($groups['outside']), 'an edited day falls outside the new series and no choice is offered', ['rows' => array_keys($outsideRows)]);
        }
        if (isset($groups['outside']) && $pre) {
            $this->check('c-preselect', $groups['outside']['selected'] === 'keep', 'keeping a day outside as an event of its own is preselected', []);
        }
        // The persisted series against the expectation.
        $this->compareSeries('c', $x, $out, $before, $after, $choices, $plan, $edit);
        $this->checkFieldsReach($before, $after, $edit, $out, $choices);
        if ($plan !== null) {
            $this->checkPlan($plan, $after, $before, $x);
            if (($sent['_google'] ?? null) !== null) {
                $this->checkGoogle($before, $plan, $sent['_google'], $choices, $x);
            }
        }
        $this->check('c-split', $new === null, 'a whole-series edit makes no new series', []);
    }

    /**
     * A rule outside the drag table (several times a day, week numbers,
     * other day sets, unreadable) takes only the edits its capability
     * allows, and they keep its days (Imported Rules, by Capability): the
     * series is the stored pattern from its start at the new clock, under
     * the bound sent or the one it had, read by the expander; every edited
     * and skipped day stays on its day.
     */
    private function checkUnsupported(array $before, array $after, array $edit, array $choices, ?array $plan): void
    {
        $x = new Expect($before, $edit);
        $why = $x->refusal();
        if ($why !== null) {
            $this->fail('e-refusal', 'the design refuses this edit (' . $why . '), and it was applied', ['rrule' => $before['row']['rrule']]);
            return;
        }
        $this->checkFieldsReach($before, $after, $edit, null, $choices);
        if ($plan !== null) {
            $this->checkPlan($plan, $after, $before, $x);
        }
        if ($before['shape']->cap === 'unreadable' || $x->kind === 'newrule') {
            return;
        }
        $virt = $before['row'];
        $virt['all_day'] = $x->allDay1 ? 1 : 0;
        $virt['tzid'] = $x->tz1;
        $start = $x->allDay1 ? Clock::parse(Days::date($before['startDn']) . ' 00:00:00') : Clock::at($before['startDn'], $x->clock1, $x->tz1);
        $virt['start_utc'] = Clock::db($start);
        $virt['end_utc'] = Clock::db(Clock::plus($start, $x->len1));
        $b = $edit['boundSent'];
        $old = $before['shape'];
        $bound = $b === null
            ? ($old->count !== null ? ';COUNT=' . $old->count : ($old->until !== null ? ';COUNT=' . count($before['raw']) : ''))
            : (isset($b['count']) ? ';COUNT=' . $b['count'] : (isset($b['until']) ? ';UNTIL=' . $b['until'] : ''));
        $virt['rrule'] = Shape::pattern((string) $before['row']['rrule']) . $bound;
        $want = Truth::expand($virt);
        $tz1 = Truth::tzOf($virt);
        $cut = PHP_INT_MAX;
        foreach ([$want['through'], $after['through']] as $t) {
            if ($t !== null) {
                $cut = min($cut, Clock::dayOf($t, $tz1) - 1);
            }
        }
        $a = array_values(array_filter(array_column($want['occ'], 'dn'), static fn(int $d): bool => $d <= $cut));
        $g = array_values(array_filter(array_column($after['raw'], 'dn'), static fn(int $d): bool => $d <= $cut));
        $this->check('c-days', $a === $g, 'a rule outside the drag table keeps its days under the edits it takes', ['rrule' => $after['row']['rrule']] + self::firstDiff($a, $g, true));
        $keep = array_fill_keys($a, true);
        foreach ($before['overrides'] as $id => $ov) {
            $day = Clock::dayOf((string) $ov['recurrence_instance_utc'], $before['tz']);
            $ov1 = $after['overrides'][$id] ?? null;
            if (isset($keep[$day]) || $day > $cut) {
                $this->check('c-edited', $ov1 !== null && Clock::dayOf((string) $ov1['recurrence_instance_utc'], $tz1) === $day, 'an edited day of a rule outside the drag table stays on its day', ['row' => $id, 'was' => $ov['recurrence_instance_utc'], 'stored' => $ov1['recurrence_instance_utc'] ?? null]);
            }
        }
    }

    private function kindIsDrag(Expect $x): bool
    {
        return $x->kind === 'drag';
    }

    /**
     * The persisted series against the oracle: raw days (Truth) equal the
     * expected days, at the new clock, zone, all-day flag and length; the
     * start where expected (or a period earlier, skipped, when its clock is
     * in a gap); the skipped days the images of the old ones plus the
     * generated ones; each edited day keyed on its image, or outside with
     * its outcome; the day acted on where it was put.
     */
    private function compareSeries(string $inv, Expect $x, array $out, array $before, array $after, array $choices, ?array $plan, array $edit): void
    {
        $m1 = $after['row'];
        $raw = $after['raw'];
        $tz1 = $x->allDay1 ? 'UTC' : $x->tz1;
        $this->check($inv . '-clock', (int) $m1['all_day'] === ($x->allDay1 ? 1 : 0) && ($x->allDay1 || (string) $m1['tzid'] === $x->tz1), 'the series has the all-day flag and zone sent', ['allDay' => [$m1['all_day'], $x->allDay1], 'tzid' => [$m1['tzid'], $x->tz1]]);
        $len = Clock::parse((string) $m1['end_utc'])->getTimestamp() - Clock::parse((string) $m1['start_utc'])->getTimestamp();
        $this->check($inv . '-length', $len === $x->len1, 'the series has the length sent', ['stored' => $len, 'expected' => $x->len1]);
        $expected = $out['days'];
        $skips = $out['skips'];
        $anchor = null;
        if (!$x->allDay1 && $expected !== [] && Clock::inGap($expected[0], $x->clock1, $tz1)) {
            // A first day in a skipped hour: the stored start a period earlier, skipped (Zones, DST and All-Day Days).
            $t = $this->readingOf($x, $choices);
            $s1 = $t['type'] === 'keep' ? $x->newShape() : null;
            $prior = $s1 !== null ? $s1->days($expected[0], $expected[0] - 800, $expected[0] - 1) : array_keys($x->walk($t, $expected[0] - 400, $expected[0] - 1));
            foreach (array_reverse($prior) as $d) {
                if (!Clock::inGap($d, $x->clock1, $tz1)) {
                    $anchor = $d;
                    break;
                }
            }
            if ($anchor !== null) {
                array_unshift($expected, $anchor);
                $skips[] = $anchor;
                sort($skips);
            }
        }
        $got = array_column($raw, 'dn');
        if ($out['bounded'] && $after['through'] === null) {
            $want = $expected;
        } else {
            $cut = min($out['hi'], $after['through'] === null ? PHP_INT_MAX : Clock::dayOf($after['through'], $tz1) - 1);
            $want = array_values(array_filter($expected, static fn(int $d): bool => $d <= $cut));
            $got = array_values(array_filter($got, static fn(int $d): bool => $d <= $cut));
        }
        $this->check($inv . '-days', $want === $got, 'the series\' days are the images the design gives for the reading chosen', ['reading' => $choices['reading'] ?? null, 'rrule' => $m1['rrule'], 'start' => $m1['start_utc']] + self::firstDiff($want, $got, true));
        if (!$x->allDay1 && $raw !== [] && $anchor === null && !isset(Shape::parts((string) $m1['rrule'])['BYHOUR'])) {
            $this->check($inv . '-clock', $raw[0]['secs'] === $x->clock1 || Clock::inGap($raw[0]['dn'], $x->clock1, $tz1), 'the series runs at the clock time sent', ['stored' => $raw[0]['secs'], 'expected' => $x->clock1]);
        }
        // UNTIL written as the last occurrence when the edit rewrote the series' days or times.
        $p1 = Shape::parts((string) $m1['rrule']);
        if (isset($p1['UNTIL']) && $raw !== [] && $after['through'] === null && in_array($x->kind, ['drag', 'time'], true) && $edit['boundSent'] === null && ((string) $m1['start_utc'] !== (string) $before['row']['start_utc'] || Shape::pattern((string) $m1['rrule']) !== Shape::pattern((string) $before['row']['rrule']))) {
            $last = end($raw);
            $ok = strlen($p1['UNTIL']) === 8 ? str_replace('-', '', Days::date($last['dn'])) === $p1['UNTIL'] : Clock::parse($last['key'])->format('Ymd\THis\Z') === $p1['UNTIL'];
            $this->check($inv . '-until', $ok, 'UNTIL is written as the last occurrence', ['until' => $p1['UNTIL'], 'last' => $last['key']]);
        }
        // Skipped days.
        $byDay = [];
        foreach ($raw as $o) {
            $byDay[$o['dn']] = $o['key'];
        }
        $gotSkips = array_map(static fn(string $k): int => Clock::dayOf($k, $tz1), $after['exdates']);
        sort($gotSkips);
        $cutSkips = static fn(array $l): array => $out['bounded'] ? $l : array_values(array_filter($l, static fn(int $d): bool => $d <= $out['hi']));
        $this->check($inv . '-skips', $cutSkips($skips) === $cutSkips($gotSkips), 'the skipped days are the images of the old ones, with the generated ones', ['expected' => array_map([Days::class, 'date'], $skips), 'stored' => array_map([Days::class, 'date'], $gotSkips)]);
        // Edited days: keyed on their images, or outside with their outcome.
        $standalone = $this->standalones();
        // The day acted on with no row, outside the new series, is an event of
        // its own where it was put (Changing the Series from an Edited Day, 3):
        // that event is no edited day's, even on an edited day's date and title.
        $ofEdited = $standalone;
        if ($edit['sent'] !== null && $edit['fromRow'] === null && $x->kind !== 'newrule' && !$out['actedInside']) {
            $putAt = ($choices['zone'] ?? null) === 'moment' && $x->zoneGroup ? Clock::db($x->momentAt) : (string) $edit['sent']['start'];
            foreach (array_reverse(array_keys($ofEdited)) as $i) {
                if ((string) $ofEdited[$i]['start_utc'] === $putAt && !isset($before['overrides'][(int) $ofEdited[$i]['id']])) {
                    unset($ofEdited[$i]);
                    break;
                }
            }
        }
        $outside = $choices['outside'] ?? 'keep';
        $move = ($choices['movedDays'] ?? 'keep') === 'move';
        foreach ($out['rows'] as $id => $r) {
            $ov0 = $before['overrides'][$id];
            $ov1 = $after['overrides'][$id] ?? null;
            if ($ov1 === null) {
                foreach ($after['overrides'] as $cand) {
                    if ($r['img'] !== null && isset($byDay[$r['img']]) && (string) $cand['recurrence_instance_utc'] === $byDay[$r['img']] && (string) $cand['title'] === (string) $ov0['title']) {
                        $ov1 = $cand;
                    }
                }
            }
            if ($r['inside']) {
                // A day beyond the window the series was read over is looked for where it is (README, The Oracle).
                $key = $byDay[$r['img']] ?? null;
                if ($key === null && $r['img'] !== null && $after['through'] !== null) {
                    foreach (Truth::keysFrom($after['row'], Days::date((int) $r['img'] - 1) . ' 00:00:00', 3) as $k) {
                        if (Clock::dayOf($k, $tz1) === $r['img']) {
                            $key = $k;
                        }
                    }
                }
                $this->check($inv . '-edited', $ov1 !== null && $key !== null && (string) $ov1['recurrence_instance_utc'] === $key, 'an edited day is keyed on its occurrence\'s image', ['row' => $id, 'was' => $ov0['recurrence_instance_utc'], 'image' => $r['img'] === null ? null : Days::date($r['img']), 'stored' => $ov1['recurrence_instance_utc'] ?? null]);
                if ($ov1 !== null && !$r['acted']) {
                    $this->checkPlaced($x, $before, $ov0, $ov1, $key ?? (string) $ov1['recurrence_instance_utc'], $r['img'], $move);
                }
                continue;
            }
            if ($r['acted']) {
                continue;
            }
            $own = null;
            foreach ($ofEdited as $sr) {
                if ((string) $sr['start_utc'] === (string) $ov0['start_utc'] && (string) $sr['title'] === (string) $ov0['title']) {
                    $own = $sr;
                }
            }
            if ($outside === 'keep') {
                $this->check($inv . '-outside', $ov1 === null && $own !== null, 'an edited day outside the new series is kept as an event of its own where it shows', ['row' => $id, 'key' => $ov0['recurrence_instance_utc'], 'image' => $r['img'] === null ? null : Days::date($r['img'])]);
            } else {
                $this->check($inv . '-outside', $ov1 === null && $own === null, 'an edited day outside the new series is deleted', ['row' => $id, 'key' => $ov0['recurrence_instance_utc']]);
            }
        }
        // The day acted on with no row is an exception like any other: where its
        // image's key isn't where it was put (a skipped hour, a carried clock),
        // it owns its time and the plan creates its row (Mapping, Defined: its row).
        $createdActed = 0;
        $put = $edit['sent'];
        if ($put !== null && $edit['fromRow'] === null && $x->kind !== 'newrule' && $out['actedInside'] && !$x->allDay1) {
            $putAt = ($choices['zone'] ?? null) === 'moment' && $x->zoneGroup ? Clock::db($x->momentAt) : (string) $put['start'];
            $k = $byDay[$x->landedDn] ?? null;
            if ($k === null && $after['through'] !== null) {
                foreach (Truth::keysFrom($after['row'], Days::date($x->landedDn - 1) . ' 00:00:00', 3) as $kk) {
                    if (Clock::dayOf($kk, $tz1) === $x->landedDn) {
                        $k = $kk;
                    }
                }
            }
            $createdActed = $k !== null && $k !== $putAt ? 1 : 0;
        }
        $keptRows = count(array_filter($out['rows'], static fn(array $r): bool => $r['inside'])) + $createdActed;
        $this->check($inv . '-edited', count($after['overrides']) === $keptRows, 'the series has exactly the edited days the mapping keeps', ['expected' => $keptRows, 'stored' => count($after['overrides'])]);
        // (d) The day acted on shows where it was put.
        $sent = $edit['sent'];
        if ($sent !== null && $x->kind !== 'newrule' && $edit['boundSent'] === null) {
            if (($choices['zone'] ?? null) === 'moment' && $x->zoneGroup) {
                $sent['start'] = Clock::db($x->momentAt); // the same moment: the wall clock where it was put, read on the clock it showed on
            }
            $shows = $this->showsAt($after, $standalone, $sent, $x->allDay1);
            $this->check('d-acted', $shows, 'the day acted on shows where it was put', ['sent' => $sent, 'inSeries' => $out['actedInside']]);
        }
    }

    /** The chosen reading's transformation (for the walk before an anchored start). */
    private function readingOf(Expect $x, array $choices): array
    {
        $rs = $x->readings();
        foreach ($rs as $r) {
            if ($r['id'] === ($choices['reading'] ?? Expect::preselected($rs))) {
                return $r['T'];
            }
        }
        return $rs[0]['T'];
    }

    /** Whether something the person sees starts where the edit put the day acted on (an occurrence, an edited day, or an event of its own). */
    private function showsAt(array $after, array $standalone, array $sent, bool $allDay): bool
    {
        $start = $sent['start'];
        $skipped = array_fill_keys($after['exdates'], true);
        $overridden = [];
        foreach ($after['overrides'] as $ov) {
            $overridden[(string) $ov['recurrence_instance_utc']] = true;
            if ((string) $ov['start_utc'] === $start) {
                return true;
            }
        }
        foreach ($after['raw'] as $o) {
            if ($o['key'] === $start && !isset($skipped[$o['key']]) && !isset($overridden[$o['key']])) {
                return true;
            }
        }
        foreach ($standalone as $s) {
            if ((string) $s['start_utc'] === $start) {
                return true;
            }
        }
        return false;
    }

    /**
     * (b) An edited day that stays is placed as its own list says: an own
     * date kept (or moved by its occurrence's move, when chosen), an own
     * clock time in its own zone kept, an own length kept, an own all-day
     * flag kept; everything else follows the series. A day that owns none
     * of them is at its key.
     */
    private function checkPlaced(Expect $x, array $before, array $ov0, array $ov1, string $key, int $img, bool $move): void
    {
        $own = $before['own'][(int) $ov0['id']] ?? [];
        $ownDate = in_array('date', $own, true);
        $ownTime = in_array('time', $own, true);
        $ownLen = in_array('length', $own, true);
        $ownAllDay = in_array('allDay', $own, true);
        $tz0 = Truth::tzOf($ov0);
        $len0 = Clock::parse((string) $ov0['end_utc'])->getTimestamp() - Clock::parse((string) $ov0['start_utc'])->getTimestamp();
        $allDay = $ownAllDay ? (int) $ov0['all_day'] === 1 : $x->allDay1;
        $oldKeyDn = Clock::dayOf((string) $ov0['recurrence_instance_utc'], $before['tz']);
        $shownDn = Clock::dayOf((string) $ov0['start_utc'], $tz0);
        $day = $ownDate ? ($move ? $shownDn + ($img - $oldKeyDn) : $shownDn) : $img;
        if (!$ownAllDay && ((int) $ov0['all_day'] === 1) !== $allDay) {
            // It takes the series' kind (all-day or timed): an own clock or length of the other kind doesn't carry over.
            $ownTime = false;
            $kindChanged = $ownLen;
            $ownLen = false;
        }
        $len = $ownLen || $ownAllDay ? $len0 : $x->len1;
        if ($allDay) {
            $start = Days::date($day) . ' 00:00:00';
            if (!$ownLen && !$ownAllDay && !$x->allDay1) {
                $len = $len0;
            }
            if (!$ownLen && !$ownAllDay && $x->allDay1) {
                $len = $x->len1;
            }
        } elseif (!$ownDate && !$ownTime && !$ownAllDay) {
            $start = $key;
        } else {
            $zone = $ownTime || $ownAllDay ? (string) $ov0['tzid'] : $x->tz1;
            $clock = $ownTime || $ownAllDay ? Clock::secsOf(Clock::local((string) $ov0['start_utc'], (string) $ov0['tzid'])) : $x->clock1;
            if (!$ownTime && $x->allDay1 === false && (int) $ov0['all_day'] === 1 && !$ownAllDay) {
                $clock = $x->clock1;
            }
            $start = Clock::db(Clock::at($day, $clock, $zone));
        }
        $this->check('b-placed', (string) $ov1['start_utc'] === $start, 'an edited day shows as its own fields say (own date, time, all-day kept; the rest follows the series)', ['row' => (int) $ov0['id'], 'own' => $own, 'was' => $ov0['start_utc'], 'expected' => $start, 'stored' => $ov1['start_utc'], 'key' => $key, 'move' => $move]);
        $len1 = Clock::parse((string) $ov1['end_utc'])->getTimestamp() - Clock::parse((string) $ov1['start_utc'])->getTimestamp();
        $this->check('b-placed', $len1 === $len || !empty($kindChanged), 'an edited day keeps its own length, or takes the series\'', ['row' => (int) $ov0['id'], 'own' => $own, 'expected' => $len, 'stored' => $len1]);
        $this->check('b-placed', (int) $ov1['all_day'] === ($allDay ? 1 : 0), 'an edited day keeps its own all-day flag, or takes the series\'', ['row' => (int) $ov0['id'], 'own' => $own]);
    }

    /**
     * (b) Every content field the edit changed reaches the master and each
     * edited day that stays: the new value, except where the day owns the
     * field and the case kept its own (ownTitle and the rest: keep); the day
     * acted on takes it. The trip flag reaches every row.
     */
    private function checkFieldsReach(array $before, array $after, array $edit, ?array $out, array $choices): void
    {
        foreach ($edit['fields'] as $f => $value) {
            $want = self::sentValue($f, $value);
            $got = self::fieldValue($f, $after['row'], $after);
            $this->check('b-fields', $got === $want, "the series takes the new $f", ['expected' => $want, 'stored' => $got]);
            $give = ($choices['own' . ucfirst($f)] ?? 'keep') === 'give';
            foreach ($before['overrides'] as $id => $ov0) {
                $ov1 = $after['overrides'][$id] ?? null;
                if ($ov1 === null) {
                    continue;
                }
                $owns = in_array($f, $before['own'][$id] ?? [], true);
                $acted = $id === $edit['fromRow'];
                $expect = !$acted && $owns && !$give ? self::fieldValue($f, $ov0, $before) : $want;
                $now = self::fieldValue($f, $ov1, $after);
                $this->check('b-fields', $now === $expect, $acted ? "the day acted on takes the new $f" : ($owns && !$give ? "an edited day keeps its own $f" : "an edited day that inherits $f takes the new one"), ['row' => $id, 'own' => $before['own'][$id] ?? [], 'expected' => $expect, 'stored' => $now]);
            }
        }
        if ($edit['trip']) {
            foreach ([$after['row']] + $after['overrides'] as $r) {
                $this->check('b-trip', (int) $r['is_container'] === 1, 'the trip flag reaches every row of the series', ['row' => $r['id']]);
            }
        }
    }

    // ---- (c) the plan against what was written -----------------------------

    private static function dbKey(?string $iso): ?string
    {
        if ($iso === null) {
            return null;
        }
        return Clock::db(new \DateTimeImmutable($iso));
    }

    private function checkPlan(array $plan, array $after, array $before, Expect $x): void
    {
        $m1 = $after['row'];
        $this->check('c-plan', (string) ($plan['rule']['after'] ?? '') === (string) $m1['rrule'], 'the stored rule is the plan\'s', ['plan' => $plan['rule']['after'] ?? null, 'stored' => $m1['rrule']]);
        $this->check('c-plan', self::dbKey($plan['start'] ?? null) === (string) $m1['start_utc'], 'the stored start is the plan\'s', ['plan' => $plan['start'] ?? null, 'stored' => $m1['start_utc']]);
        $p1 = Shape::parts((string) $m1['rrule']);
        $kind = isset($p1['COUNT']) ? 'count' : (isset($p1['UNTIL']) ? 'until' : 'none');
        // The bound's events leave out the generated and anchor skips, which COUNT counts (api-contract, Series Plans).
        $notCounted = count(array_filter($plan['days'] ?? [], static fn(array $d): bool => in_array($d['outcome'] ?? '', ['generated', 'anchor'], true)));
        $this->check('c-plan', ($plan['bound']['kind'] ?? null) === $kind && ($kind !== 'count' || in_array((int) ($plan['bound']['count'] ?? -1), [(int) $p1['COUNT'], (int) $p1['COUNT'] - $notCounted], true)), 'the plan\'s bound is the stored one (its COUNT, or its events without the generated and anchor skips)', ['plan' => $plan['bound'] ?? null, 'stored' => $kind . (isset($p1['COUNT']) ? ' ' . $p1['COUNT'] : ''), 'notCounted' => $notCounted]);
        $exdates = array_fill_keys($after['exdates'], true);
        $keys = [];
        foreach ($after['overrides'] as $ov) {
            $keys[(string) $ov['recurrence_instance_utc']] = true;
        }
        $planSkips = [];
        $planKeys = [];
        foreach ($plan['days'] ?? [] as $d) {
            $nk = self::dbKey($d['newKey'] ?? null);
            if ($d['kind'] === 'skipped' && in_array($d['outcome'], ['moves', 'stays', 'generated', 'anchor'], true)) {
                $planSkips[] = $nk;
                $this->check('c-plan', $nk !== null && isset($exdates[$nk]), 'a skipped day the plan keeps is skipped where the plan says', ['day' => $d]);
            }
            if (in_array($d['kind'], ['edited', 'acted'], true) && in_array($d['outcome'], ['moves', 'stays'], true)) {
                $planKeys[] = $nk;
                $this->check('c-plan', $nk !== null && isset($keys[$nk]), 'an edited day the plan keeps is keyed where the plan says', ['day' => $d]);
            }
        }
        $stored = $after['exdates'];
        sort($stored);
        sort($planSkips);
        $this->check('c-plan', $stored === $planSkips, 'every stored skipped day is one the plan names', ['plan' => $planSkips, 'stored' => $stored]);
        $storedKeys = array_keys($keys);
        sort($storedKeys);
        sort($planKeys);
        $this->check('c-plan', $storedKeys === $planKeys, 'every stored edited day is one the plan names', ['plan' => $planKeys, 'stored' => $storedKeys]);
        // The next dates are what the persisted series shows, in a row, in the order of their occurrences.
        $byKey = [];
        $over = [];
        foreach ($after['overrides'] as $ov) {
            $over[(string) $ov['recurrence_instance_utc']] = (string) $ov['start_utc'];
        }
        $next = array_map(static fn(array $n): string => (string) self::dbKey($n['start']), $plan['next'] ?? []);
        $keys = array_column($after['raw'], 'key');
        if ($next !== [] && $after['through'] !== null && max($next) >= $after['through']) {
            // Next dates past the window the series was read over: read them where they are.
            $span = (int) ((Clock::parse(max($next))->getTimestamp() - Clock::parse(min($next))->getTimestamp()) / 86400);
            $keys = Truth::keysFrom($after['row'], Clock::db(Clock::parse(min($next))->modify('-400 days')), $span + 800);
        }
        // From the day acted on (where it landed), the occurrences in order, each where it shows.
        $tz1 = $x->allDay1 ? 'UTC' : $x->tz1;
        foreach ($keys as $k) {
            if (!isset($exdates[$k]) && Clock::dayOf($k, $tz1) >= $x->fromDn()) {
                $byKey[$k . ' 0'] = $over[$k] ?? $k;
            }
        }
        foreach ($this->standalones() as $s) {
            if (Clock::dayOf((string) $s['start_utc'], $tz1) >= $x->fromDn() && in_array((string) $s['start_utc'], $next, true)) {
                $byKey[(string) $s['start_utc'] . ' 1'] = (string) $s['start_utc'];
            }
        }
        // In the order they show (an edited day keyed later may show earlier, on its own date).
        $shown = array_values($byKey);
        sort($shown);
        $shown = array_slice($shown, 0, count($next));
        $sortedNext = $next;
        sort($sortedNext);
        if ($next !== [] && $x->kind !== 'newrule') {
            $this->check('c-next', $shown === $sortedNext, 'the plan\'s next dates are the next events the series shows from the day acted on', ['plan' => $next, 'shown' => $shown, 'from' => Days::date($x->fromDn())]);
        }
    }

    /**
     * (i) Until 1.5.2 a Google calendar is offered only edits that keep the
     * rule text, and then as the local plan with no outcome claimed for
     * edited days; an edit that keeps the text (not a list's member
     * reading, no all-day change) is offered.
     */
    private function checkGoogle(array $before, array $plan, array $google, array $choices, Expect $x): void
    {
        if (isset($google['error'])) {
            $this->fail('i-google', 'the Google plan could not be worked out: ' . $google['error']);
            return;
        }
        $keeps = Shape::pattern((string) $plan['rule']['after']) === Shape::pattern((string) $before['row']['rrule']) && ($choices['reading'] ?? '') !== 'member';
        $whole = array_filter($google['plan']['refusals'] ?? [], static fn(array $r): bool => !isset($r['group']));
        $refused = $google['refused'] || $google['refusedChoice'] || $whole !== [];
        foreach ($google['plan']['groups'] ?? [] as $g) {
            foreach ($g['options'] as $o) {
                if (($choices[$g['id']] ?? null) === $o['id'] && !empty($o['disabled'])) {
                    $refused = true; // the option chosen is refused there: the commit would be 422
                }
            }
        }
        if (!$keeps) {
            $this->check('i-google', $refused, 'a change to the rule text is not offered on a Google calendar', ['rule' => $plan['rule']['after']]);
            return;
        }
        $g = $google['plan'];
        $edited = array_filter($g['days'] ?? [], static fn(array $d): bool => $d['kind'] === 'edited');
        if (!$refused) {
            $this->check('i-google', $g['rule']['after'] === $plan['rule']['after'] && $g['start'] === $plan['start'] && $edited === [], 'an edit that keeps the rule text is offered on Google as the local plan, claiming nothing for edited days', ['google' => [$g['rule']['after'] ?? null, $g['start'] ?? null], 'local' => [$plan['rule']['after'], $plan['start']], 'edited' => count($edited)]);
        } elseif ($x->allDay1 === (bool) $before['allDay'] && $x->kind !== 'newrule') {
            $this->fail('i-google', 'an edit that keeps the rule text is refused on a Google calendar', ['refusals' => $g['refusals'] ?? null, 'kind' => $x->kind]);
        }
    }

    // ---- refusals -------------------------------------------------------------

    /** A commit answered 422: the design must refuse it too (its capability, every reading refused, or nothing left). */
    private function checkRefused(array $st, array $edit, ?array $plan, array $choices, HttpError $e): void
    {
        if ($st['shape']->cap === 'unreadable') {
            return;
        }
        $x = (new Expect($st, $edit))->withZone($choices['zone'] ?? null);
        if ($x->refusal() !== null) {
            return;
        }
        $readings = $x->readings();
        $chosen = null;
        foreach ($readings as $r) {
            if ($r['id'] === ($choices['reading'] ?? Expect::preselected($readings))) {
                $chosen = $r;
            }
        }
        if ($chosen === null || !$chosen['enabled']) {
            return;
        }
        $out = $x->outcome($chosen, $choices);
        if (!empty($out['empty'])) {
            return;
        }
        $this->fail('e-refusal', 'the edit was refused though the design allows it: ' . $e->getMessage(), ['reading' => $chosen['id'], 'refusals' => $plan['refusals'] ?? null, 'rrule' => $st['row']['rrule']]);
    }

    // ---- this and following (the 1.4.6 path until 1.5.1) --------------------

    /**
     * "This and following": the old part keeps exactly the days before the
     * split with its earlier exceptions untouched and none from the split
     * on; the new part is the part from the split dragged as a whole series
     * (Every Kind of Edit), read by the oracle with the design's preselected
     * options, its fields reaching its edited days as the own lists say.
     */
    private function checkFollowing(array $before, array $after, array $acted, array $sent, ?array $new): void
    {
        $split = $acted['key'];
        $keep = array_values(array_filter(array_column($before['raw'], 'dn'), static fn(int $d) => true));
        $beforeDays = [];
        foreach ($before['raw'] as $o) {
            if ($o['key'] < $split) {
                $beforeDays[] = $o['dn'];
            }
        }
        unset($keep);
        $this->check('5-old', array_column($after['raw'], 'dn') === $beforeDays, 'the old part keeps exactly the days before the split', self::firstDiff($beforeDays, array_column($after['raw'], 'dn'), true));
        foreach ($before['overrides'] as $id => $ov) {
            if ((string) $ov['recurrence_instance_utc'] < $split) {
                $ov1 = $after['overrides'][$id] ?? null;
                $a = $ov;
                unset($a['updated_at']);
                $b = $ov1;
                if ($b !== null) {
                    unset($b['updated_at']);
                }
                $this->check('5-old', $a === $b, 'an edited day before the split is untouched', ['row' => $id]);
            }
        }
        $late = array_values(array_filter($after['exdates'], static fn(string $k): bool => $k >= $split));
        $this->check('5-old', $late === [], 'the old part keeps no skipped day from the split on', ['skips' => $late]);
        $this->check('5-new', $new !== null, 'a split makes a new series from the day acted on', []);
        if ($new === null || $before['shape']->cap === 'unreadable') {
            return;
        }
        // The part from the split, as a series of its own, dragged as a whole series.
        $part = $before;
        $part['raw'] = array_values(array_filter($before['raw'], static fn(array $o): bool => $o['key'] >= $split));
        $part['startDn'] = $acted['dn'];
        $part['row']['start_utc'] = $split;
        $part['shape'] = clone $before['shape'];
        if ($part['shape']->count !== null) {
            $part['shape']->count = count($part['raw']);
        }
        $part['exdates'] = array_values(array_filter($before['exdates'], static fn(string $k): bool => $k >= $split));
        $part['overrides'] = array_filter($before['overrides'], static fn(array $ov): bool => (string) $ov['recurrence_instance_utc'] >= $split);
        $edit = $sent['edit'];
        // The day acted on stays the edited row at the split key, its move measured from where it shows.
        $x = new Expect($part, $edit);
        if ($x->refusal() !== null) {
            $this->fail('5-new', 'the design refuses this drag of the part from the split, and it was applied', []);
            return;
        }
        $readings = $x->readings();
        $pre = Expect::preselected($readings);
        if ($pre === null) {
            $this->fail('5-new', 'the design refuses every reading of the part from the split, and it was applied', []);
            return;
        }
        $reading = null;
        foreach ($readings as $r) {
            if ($r['id'] === $pre) {
                $reading = $r;
            }
        }
        $choices = ['reading' => $pre];
        $countFirst = $part['shape']->count !== null && ($x->outcome($reading, ['bound' => 'count'])['actedInside'] ?? false);
        $choices['bound'] = $countFirst || $part['shape']->count === null ? 'count' : 'end';
        $out = $x->outcome($reading, $choices);
        if (!empty($out['empty'])) {
            return;
        }
        // Rows of the new part are matched by their image keys.
        $newRows = [];
        foreach ($out['rows'] as $id => $r) {
            if (isset($part['overrides'][$id])) {
                $newRows[$id] = $r;
            }
        }
        $out['rows'] = $newRows;
        $byKey = [];
        foreach ($new['overrides'] as $ov) {
            $byKey[(string) $ov['recurrence_instance_utc']] = $ov;
        }
        $mapped = $new;
        $mapped['overrides'] = [];
        $dayKey = [];
        foreach ($new['raw'] as $o) {
            $dayKey[$o['dn']] = $o['key'];
        }
        foreach ($out['rows'] as $id => $r) {
            if ($r['img'] !== null && isset($dayKey[$r['img']], $byKey[$dayKey[$r['img']]])) {
                $mapped['overrides'][$id] = $byKey[$dayKey[$r['img']]];
            }
        }
        $this->compareSeries('5', $x, $out, $part, $mapped, $choices, null, $edit);
        $this->checkFieldsReach($part, $mapped + ['tags' => $new['tags'], 'people' => $new['people']], $edit, $out, []);
    }

    // ---- deletes and answers ---------------------------------------------------

    /** Delete this: the day skipped, its edited row gone, every other row byte-identical. */
    private function checkDeleteThis(array $before, array $after, array $acted, array $s0): void
    {
        $want = $before['exdates'];
        $want[] = $acted['key'];
        sort($want);
        $got = $after['exdates'];
        sort($got);
        $this->check('7-this', $want === $got, 'the day is skipped and the other skips stay', ['expected' => $want, 'stored' => $got]);
        $a = $before['row'];
        $b = $after['row'];
        unset($a['updated_at'], $b['updated_at'], $a['exdates_json'], $b['exdates_json']);
        $this->check('7-this', $a === $b, 'the series is otherwise untouched', ['diff' => array_keys(array_diff_assoc(array_map('strval', array_map(static fn($v) => $v ?? '', $a)), array_map('strval', array_map(static fn($v) => $v ?? '', $b))))]);
        foreach ($before['overrides'] as $id => $ov) {
            $ov1 = $after['overrides'][$id] ?? null;
            if ((string) $ov['recurrence_instance_utc'] === $acted['key']) {
                $this->check('7-this', $ov1 === null, 'the edited row of that day is removed', ['row' => $id]);
                continue;
            }
            unset($ov['updated_at']);
            if ($ov1 !== null) {
                unset($ov1['updated_at']);
            }
            $this->check('7-this', $ov === $ov1, 'every other edited day is untouched', ['row' => $id]);
        }
        $this->check('7-this', $this->standalones() === [], 'a delete makes no event of its own', []);
    }

    /** Delete following: the series ends before the day; edited days from it on go; earlier ones untouched. From the first day: the series goes. */
    private function checkDeleteFollowing(array $before, array $after, array $acted, bool $first): void
    {
        if ($first) {
            $this->check('7-following', $after['row']['deleted_at'] !== null, 'from the first day the whole series is deleted', []);
            $this->check('7-following', $after['overrides'] === [], 'its edited days go with it', ['left' => count($after['overrides'])]);
            return;
        }
        $split = $acted['key'];
        $days = [];
        foreach ($before['raw'] as $o) {
            if ($o['key'] < $split) {
                $days[] = $o['dn'];
            }
        }
        $this->check('7-following', array_column($after['raw'], 'dn') === $days, 'the series keeps exactly the days before', self::firstDiff($days, array_column($after['raw'], 'dn'), true) + ['rrule' => $after['row']['rrule']]);
        foreach ($before['overrides'] as $id => $ov) {
            $ov1 = $after['overrides'][$id] ?? null;
            if ((string) $ov['recurrence_instance_utc'] >= $split) {
                $this->check('7-following', $ov1 === null, 'an edited day from the split on is deleted', ['row' => $id]);
            } else {
                unset($ov['updated_at']);
                if ($ov1 !== null) {
                    unset($ov1['updated_at']);
                }
                $this->check('7-following', $ov === $ov1, 'an edited day before the split is untouched', ['row' => $id]);
            }
        }
        $late = array_values(array_filter($after['exdates'], static fn(string $k): bool => $k >= $split));
        $this->check('7-following', $late === [], 'skipped days from the split on are dropped', ['skips' => $late]);
    }

    /** An answer for all events (or from a day on): the series and every edited day answer; nothing else changes. */
    private function checkAttend(array $before, array $after, ?array $new, ?array $acted): void
    {
        $target = $acted === null ? $after : $new;
        if ($target === null) {
            $this->fail('7-attend', 'an answer from a day on makes a new series from that day', []);
            return;
        }
        $this->check('7-attend', (string) $target['row']['attendance'] === 'going', 'the series answers', ['stored' => $target['row']['attendance']]);
        foreach ($target['overrides'] as $id => $ov) {
            $this->check('7-attend', (string) $ov['attendance'] === 'going', 'every edited day takes the series\' answer', ['row' => $id, 'stored' => $ov['attendance']]);
        }
        if ($acted === null) {
            $this->check('7-attend', $new === null, 'an answer for all events makes no new series', []);
            foreach ($before['overrides'] as $id => $ov) {
                $ov1 = $after['overrides'][$id] ?? null;
                $this->check('7-attend', $ov1 !== null, 'an answer keeps every edited day', ['row' => $id]);
                if ($ov1 !== null) {
                    foreach (['recurrence_instance_utc', 'start_utc', 'end_utc', 'title', 'location', 'status'] as $col) {
                        $this->check('7-attend', (string) $ov[$col] === (string) $ov1[$col], "an answer leaves each edited day's $col", ['row' => $id, 'was' => $ov[$col], 'now' => $ov1[$col]]);
                    }
                }
            }
            foreach (['rrule', 'start_utc', 'exdates_json', 'title'] as $col) {
                $this->check('7-attend', (string) $before['row'][$col] === (string) $after['row'][$col], "an answer leaves the series' $col", ['was' => $before['row'][$col], 'now' => $after['row'][$col]]);
            }
        }
    }

    // ---- helpers ------------------------------------------------------------

    /** @param list<int> $a @param list<int> $b */
    private static function firstDiff(array $a, array $b, bool $days = false): array
    {
        $fmt = static fn(array $l): array => $days ? array_map(static fn($d) => is_int($d) ? Days::date($d) : $d, $l) : $l;
        $n = max(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            if (($a[$i] ?? null) !== ($b[$i] ?? null)) {
                return ['at' => $i, 'expected' => $fmt(array_slice($a, max(0, $i - 1), 4)), 'actual' => $fmt(array_slice($b, max(0, $i - 1), 4)), 'counts' => [count($a), count($b)]];
            }
        }
        return [];
    }
}
