<?php
// The preselection check (README, The Preselection): for every group a
// whole-series plan offers, the option it preselects is the one the design
// states for that change. The design's rules are written into the table and
// the functions below, from series-model.md (Open Decisions 1, Bounds: Start,
// End and Days Outside, Zones, DST and All-Day Days, The Day Acted On Comes
// First) and series-model-examples.md (A New Rule, and Who Owns What; the
// grid's cases 9, 11 and 12); nothing here reads a preselection from the
// plan or from the order Expect lists its readings in. It reads the baseline
// plan, the one previewed with no choice named.
declare(strict_types=1);

namespace BetterCal\EditHarness;

trait PlanPreselect
{
    /** Groups whose preselection is one option whatever the change (the design's words beside each). */
    private static array $preselectFixed = [
        'zone' => 'clock',          // Zones: "The same clock time in the new zone (preselected, what the editor sends)"
        'outside' => 'keep',        // Bounds, Days outside: "keep it as an event of its own (preselected: no edit is lost)"
        'movedDays' => 'keep',      // Open Decisions 1, a day moved on its own: "Keep Fri 26 Mar"
        'ownTitle' => 'keep',       // Own fields, the grid's case 12: the own title kept (preselected)
        'ownLocation' => 'keep',
        'ownDescription' => 'keep',
        'ownUrl' => 'keep',
        'ownReminders' => 'keep',
        'ownStatus' => 'keep',
        'ownTags' => 'keep',
        'ownPeople' => 'keep',
    ];

    /**
     * The readings in the design's order of preference for a change, the first offered and enabled being the
     * preselected one (Open Decisions 1, Naming the Landed Day, Bounds: Nothing left):
     * - a new rule: the old days shifted ("Move edited and skipped days with their events, as a drag would", the
     *   examples' A New Rule), then keeping the keys; the other when the first leaves nothing;
     * - a list: the member ("Move every Wednesday to Thursday"), then the whole pattern;
     * - a monthly or yearly day: a day counted from the end keeps counting from the end first; a day the rule wrote
     *   on the 29th to 31st keeps its own form, skipping months without one, first; otherwise "on the 30th, or the last
     *   day in shorter months" (the last day for a 31st), then skipping; a yearly 29 February, the last day of February.
     */
    private static function readingPreference(Shape $s, bool $newRule): array
    {
        if ($newRule) {
            return ['map', 'keep'];
        }
        $form = $s->forms[0] ?? null;
        $fromEnd = is_array($form) && $form[0] === 'd' && $form[1] < 0;
        $ownSkip = is_array($form) && $form[0] === 'd' && $form[1] >= 29 && $s->freq === 'MONTHLY';
        $monthEnd = $ownSkip ? ['skip', 'clamp', 'last'] : ['clamp', 'last', 'skip'];
        return array_merge(['follow', 'member', 'pattern'], $fromEnd ? ['fromEnd'] : [], $monthEnd, ['lastFeb', 'leap', 'day', 'nth', 'position', 'same'], $fromEnd ? [] : ['fromEnd']);
    }

    /** Each group of the baseline plan against the design's preselection for it. */
    private function checkPreselection(array $plan, array $before, array $edit): void
    {
        $selected = [];
        foreach ($plan['groups'] ?? [] as $g) {
            $selected[$g['id']] = $g['selected'];
        }
        foreach ($plan['groups'] ?? [] as $g) {
            [$want, $role, $why] = $this->designPreselects($g, $selected, $before, $edit);
            if ($want === null) {
                $this->unoracled[] = "the design's preselection for the $role group";
                continue;
            }
            $this->ran('c-preselect-design', $role);
            $this->check('c-preselect-design', $g['selected'] === $want, "the preselected option is the design's for this change: $why", ['group' => $g['id'], 'selected' => $g['selected'], 'design' => $want, 'offered' => array_column($g['options'], 'id')]);
        }
    }

    /**
     * The design's preselection for one group, its role in the tally, and the rule's words: [option or null, role, why].
     * A group the table doesn't know, or a rule the harness can't work out for this change, is null (unoracled).
     */
    private function designPreselects(array $g, array $selected, array $before, array $edit): array
    {
        $id = $g['id'];
        $enabled = array_values(array_map(static fn(array $o): string => $o['id'], array_filter($g['options'], static fn(array $o): bool => empty($o['disabled']))));
        if (isset(self::$preselectFixed[$id])) {
            $role = str_starts_with($id, 'own') ? 'own field' : $id;
            return [self::$preselectFixed[$id], $role, "$id: " . self::$preselectFixed[$id]];
        }
        if (!$before['shape']->supported()) {
            return [null, $id, ''];
        }
        $x = (new Expect($before, $edit + ['adopt' => $selected]))->withZone($selected['zone'] ?? null);
        if (in_array($id, ['day', 'time', 'length'], true)) {
            // Open Decisions 1, from an edited day: the day adopted when that moves every event by less than one period
            // of the rule's frequency, the time always, the length when typed (the case's `typed` share).
            $want = (new Expect($before, $edit))->adoptions[$id]['preselect'] ?? null;
            return [$want, "adopt $id", $id === 'day' ? 'adopting the day when it moves every event by less than one period' : ($id === 'time' ? 'adopting the time always' : 'adopting a typed length, the same way for a drag')];
        }
        if ($id === 'reading') {
            $newRule = $x->kind === 'newrule';
            foreach (self::readingPreference($before['shape'], $newRule) as $r) {
                if (in_array($r, $enabled, true)) {
                    return [$r, $newRule ? 'reading (new rule)' : 'reading (drag)', $newRule ? 'a new rule that is the old one shifted moves edited and skipped days as a drag would' : 'the drag table\'s first reading for this family and landing'];
                }
            }
            return [null, 'reading', ''];
        }
        // The bound and the edge are worked out with the other choices as selected (Bounds, One plan per combination).
        $reading = null;
        foreach ($x->readings() as $r) {
            if ($r['id'] === ($selected['reading'] ?? null) || (!isset($selected['reading']) && $reading === null && $r['enabled'])) {
                $reading = $r;
            }
        }
        if ($reading === null || !$reading['enabled']) {
            return [null, $id, ''];
        }
        if ($id === 'bound') {
            if ($before['shape']->count === null) {
                return ['end', 'bound', 'an UNTIL keeps its end'];
            }
            if ($x->kind !== 'drag') {
                return ['count', 'bound', 'a COUNT keeps its count when no day is dragged'];
            }
            $among = $x->outcome($reading, ['bound' => 'count'] + $selected)['actedInside'] ?? false;
            return [$among ? 'count' : 'end', 'bound', $among ? '"keep N events" when the dragged day is among the N' : '"keep the end" when a count would end before the dragged day'];
        }
        if ($id === 'edge') {
            // "Don't add" whenever leaving the day out keeps every old occurrence's image with nothing else lost or split off.
            $w = $x->outcome($reading, ['edge' => 'without'] + $selected);
            $a = $x->outcome($reading, ['edge' => 'add'] + $selected);
            if (!empty($w['empty']) || !empty($a['empty'])) {
                return ['add', 'edge', 'leaving the day out leaves nothing'];
            }
            $inW = array_fill_keys(array_values(array_diff($w['days'], $w['gen'])), true);
            $inA = array_fill_keys(array_values(array_diff($a['days'], $a['gen'])), true);
            $lost = !$w['actedInside'] && $a['actedInside'];
            $skipped = array_fill_keys($before['exdates'], true);
            foreach ($w['images'] as $key => $img) {
                $lost = $lost || ($img !== null && !isset($skipped[$key]) && !isset($inW[$img]) && isset($inA[$img]));
            }
            foreach ($w['rows'] as $rid => $r) {
                $lost = $lost || (!$r['inside'] && ($a['rows'][$rid]['inside'] ?? false));
            }
            return [$lost ? 'add' : 'without', 'edge', $lost ? '"Add" when leaving the day out loses or splits off another' : '"Don\'t add" when leaving the day out keeps every image'];
        }
        return [null, $id, ''];
    }
}
