<?php
// A product without the series plan API (Product, 1.3.0 and 1.4.0): the same
// edit sent the old way, and its outcome read against every option
// combination of the design's choices (README, Other Products). 1.5 asks
// where a choice exists (the adoptions, the zone's two promises, the
// readings, the bound, an edge gain, a day outside, a day moved on its own,
// an own field); the old versions decided silently, so an outcome that
// matches the preselected combination, another combination, or none says
// what the dialog gained (the asking) apart from what the engine gained (a
// defensible outcome at all). The combinations are the harness's own: the
// design's groups worked out by Expect and the preselections by
// PlanPreselect's table, never read from a product.
declare(strict_types=1);

namespace BetterCal\EditHarness;

use BetterCal\Http\HttpError;

trait OptionMatch
{
    /** This case's product tallies (Runner::count): match, other, none, noPlan, na, combos. */
    private array $product = [];

    /** @var array<int,list<string>> what each "this event" edit changed, by row id, for a product with no own list */
    private array $ownMade = [];

    /** The most option combinations a case is checked against, the fewest departures from the preselected first. */
    private const MAX_COMBOS = 64;

    /**
     * The edit sent the old way (api-contract at 1.3.0 and 1.4.0: `PATCH /events/:id` with `scope` and `instanceStart`;
     * `scope:"all"` with `instanceStart` changes the series by what changed for that occurrence), as EventsController
     * passes the body on: the same body the plan API gets, without `plan`. A 400 (InvalidArgumentException, which the
     * controller turns into one) or another refusal writes nothing and is judged against the design as 1.5's is.
     */
    private function oldEdit(int $target, array $in, array $edit, array $st, array $v, array $s0, string $slot, bool $replay): ?array
    {
        $this->spec['choices'][$slot] ??= [];
        if ($slot === 'edit' && !$replay) {
            // The 1.4 client sent an edit of an edited day to that day's own id (its occurrence's eventId), as the harness does in half the cases.
            $this->tally('target', $target === (int) $st['row']['id'] ? 'the series, with instanceStart' : "the edited day's own row");
        }
        try {
            $this->events->patch($this->userId, $target, $in);
        } catch (HttpError | \InvalidArgumentException $e) {
            if ($replay || ($e instanceof HttpError && $e->errorCode === 'trip_nested')) {
                throw $e; // a replay's refusal is f-redo's (run), a trip's the case's own (run)
            }
            $this->same('e-refused', $s0, $this->snapshot(), 'a refused edit writes nothing');
            if ($slot === 'edit') {
                $err = $e instanceof HttpError ? $e : HttpError::badRequest($e->getMessage());
                $whole = $v['scope'] === 'all' || self::isFirstDay($st, $edit['actedKey']);
                if ($whole) {
                    $this->checkRefused($st, $edit, null, [], $err);
                } else {
                    $this->fail('e-refusal', 'the edit from a day on was refused: ' . $err->errorCode . ' ' . mb_substr($err->getMessage(), 0, 200), []);
                }
            }
            return null;
        }
        return ['kind' => $v['scope'] === 'following' ? 'following' : 'all', 'in' => $in, 'edit' => $edit, '_plan' => null, '_choices' => [], '_google' => null, 'target' => $target];
    }

    /**
     * checkEdit for a product with no plan: the checks that read no choice once, then the whole-series or split checks
     * for each option combination of the design's choices, the preselected one first. The case is recorded as matching
     * the preselected combination, another (named), or none, and only the last records failures: the preselected
     * combination's.
     */
    private function matchOptions(array $before, array $after, array $acted, array $v, array $sent, array $s0): void
    {
        if (!in_array($sent['kind'], ['all', 'following'], true)) {
            $this->checkEdit($before, $after, $acted, $v, $sent, $s0);
            $this->tally('match', 'no choice (delete or answer)');
            return;
        }
        $newId = $this->newMaster((int) $before['row']['id']);
        $new = $newId === null ? null : $this->state($newId);
        $n0 = count($this->records);
        $this->checkSeries('g', $after);
        $this->checkAttached('h', $after);
        if ($new !== null) {
            $this->checkSeries('g', $new);
            $this->checkAttached('h', $new);
        }
        // Failures of the checks no option reads (the stored start, the keys, the occurrences): they fail every combination.
        $once = array_unique(array_column(array_slice($this->records, $n0), 'invariant'));
        $first = self::isFirstDay($before, $acted['key']);
        $split = $sent['kind'] === 'following' && !$first;
        $base = $split ? $this->partOf($before, $acted) : $before;
        $remainder = $split ? count($before['raw']) - count($base['raw']) : 0;
        $combos = $this->designCombos($base, $sent['edit'], $split, $remainder);
        $this->tally('combos', (string) count($combos));
        $saved = [$this->records, $this->unoracled, $this->runs, $this->spec['preselected']['edit'] ?? null];
        $this->spec['preselected']['edit'] = false;
        $results = [];
        $match = null;
        foreach ($combos as $i => $c) {
            $this->records = [];
            $this->unoracled = [];
            $this->runs = [];
            $s = ['_choices' => $c, '_plan' => null] + $sent;
            $this->checkUntouched($before, $after, $v, $s, $new);
            if ($split) {
                $this->checkFollowing($before, $after, $acted, $s, $new);
            } else {
                $this->checkWhole($before, $after, $s, $new);
            }
            $results[$i] = [$this->records, $this->unoracled, $this->runs];
            if ($this->records === []) {
                $match = $i;
                break;
            }
        }
        $use = $match ?? 0;
        [$recs, $un, $runs] = $results[$use];
        $this->records = array_merge($saved[0], $recs);
        $this->unoracled = array_merge($saved[1], $un);
        $this->runs = $saved[2];
        foreach ($runs as $check => $byRole) {
            foreach ($byRole as $role => $n) {
                $this->runs[$check][$role] = ($this->runs[$check][$role] ?? 0) + $n;
            }
        }
        $this->spec['preselected']['edit'] = $saved[3];
        $label = self::departures($combos[0], $combos[$use]);
        $this->spec['matched'] = $match === null ? 'none' : ($match === 0 ? 'preselected' : $label);
        $but = $once === [] ? '' : ', but a check no option reads failed (' . implode(', ', $once) . ')';
        if ($match === 0) {
            $this->tally('match', (count($combos) > 1 ? 'the preselected option' : 'the one outcome (no choice)') . $but);
        } elseif ($match !== null) {
            $this->tally('match', 'another option' . $but);
            $this->tally('other', $label);
        } else {
            $this->tally('match', 'no option');
            foreach (array_unique(array_column($recs, 'invariant')) as $inv) {
                $this->tally('none', $inv);
            }
        }
    }

    private function tally(string $kind, string $key): void
    {
        $this->product[$kind][$key] = ($this->product[$kind][$key] ?? 0) + 1;
    }

    /** The groups a combination departs from the preselected one in, as group=option. */
    private static function departures(array $pre, array $c): string
    {
        $out = [];
        foreach ($c as $k => $opt) {
            if (($pre[$k] ?? null) !== $opt) {
                $out[] = "$k=$opt";
            }
        }
        return $out === [] ? 'preselected' : implode(', ', $out);
    }

    /** The part from the day acted on, as a series of its own (checkFollowing's reading of it). */
    private function partOf(array $before, array $acted): array
    {
        $split = $acted['key'];
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
        return $part;
    }

    /**
     * Every combination of the design's options for this change, each group's preselected option first, so the first
     * combination is the preselected one; ordered by how many groups depart from it, at most MAX_COMBOS. The groups are
     * the design's (README, The Preselection, Every Option): the adoptions (day, time, length), the zone, the readings
     * (in the design's order of preference), the bound, an edge gain, a day outside, a day moved on its own, an own field.
     *
     * @return list<array<string,string>>
     */
    private function designCombos(array $st, array $edit, bool $split, int $before): array
    {
        if (!$st['shape']->supported() || $st['shape']->cap === 'unreadable') {
            return [[]];
        }
        $add = static function (array $list, string $id, array $opts): array {
            $out = [];
            foreach ($list as $c) {
                foreach ($opts as $o) {
                    $out[] = $c + [$id => $o];
                }
            }
            return $out;
        };
        $x0 = new Expect($st, $edit);
        $outer = [[]];
        foreach ($x0->adoptions as $part => $a) {
            $other = $a['preselect'] === 'adopt' ? 'same' : 'adopt';
            $outer = $add($outer, $part, $a['disabled'] ? [$a['preselect']] : [$a['preselect'], $other]);
        }
        if ($x0->zoneGroup) {
            $outer = $add($outer, 'zone', ['clock', 'moment']);
        }
        $fromRow = $edit['fromRow'] ?? null;
        $movedOwn = false;
        $ownFields = [];
        foreach ($st['overrides'] as $id => $ov) {
            if ($id === $fromRow) {
                continue;
            }
            $own = $st['own'][$id] ?? [];
            $movedOwn = $movedOwn || in_array('date', $own, true);
            foreach (array_keys($edit['fields'] ?? []) as $f) {
                if (in_array($f, $own, true)) {
                    $ownFields['own' . ucfirst($f)] = true;
                }
            }
        }
        $this->splitCount = null;
        $combos = [];
        foreach ($outer as $c) {
            $x = (new Expect($st, $edit + ['adopt' => $c]))->withZone($c['zone'] ?? null);
            if ($x->refusal() !== null) {
                $combos[] = $c;
                continue;
            }
            $readings = $x->readings();
            $enabled = [];
            foreach ($readings as $r) {
                if ($r['enabled']) {
                    $enabled[$r['id']] = $r;
                }
            }
            if ($x->momentOpen || $enabled === []) {
                $combos[] = $c;
                continue;
            }
            $order = array_values(array_filter(self::readingPreference($st['shape'], $x->kind === 'newrule'), static fn(string $id): bool => isset($enabled[$id])));
            foreach (array_keys($enabled) as $id) {
                if (!in_array($id, $order, true)) {
                    $order[] = $id;
                }
            }
            foreach ($order as $rid) {
                $reading = $enabled[$rid];
                $sel = $c + ['reading' => $rid];
                $inner = [count($enabled) > 1 ? $sel : $c];
                if ($split && isset($edit['boundSent']['count']) && $edit['rule'] !== 'new') {
                    $remainder = (int) $edit['boundSent']['count'] - $before;
                    $inner = $add($inner, 'bound', $remainder > 0 ? ['whole', 'part'] : ['part']);
                } elseif ($x->boundDiffers($reading, $sel)) {
                    $want = $this->designPreselects(['id' => 'bound', 'options' => [['id' => 'count'], ['id' => 'end']]], $sel, $st, $edit)[0];
                    $inner = $add($inner, 'bound', $want === 'end' ? ['end', 'count'] : ['count', 'end']);
                }
                $out = $x->outcome($reading, $sel);
                if (empty($out['empty']) && array_intersect($out['edge'], $out['days']) !== []) {
                    $want = $this->designPreselects(['id' => 'edge', 'options' => [['id' => 'without'], ['id' => 'add']]], $sel, $st, $edit)[0];
                    $inner = $add($inner, 'edge', $want === 'add' ? ['add', 'without'] : ['without', 'add']);
                }
                $outside = false;
                foreach ($out['rows'] ?? [] as $r) {
                    $outside = $outside || (!$r['inside'] && (!$r['acted'] || $edit['boundSent'] !== null));
                }
                if ($outside) {
                    $inner = $add($inner, 'outside', ['keep', 'delete']);
                }
                array_push($combos, ...$inner);
            }
        }
        if ($movedOwn) {
            $combos = $add($combos, 'movedDays', ['keep', 'move']);
        }
        foreach (array_keys($ownFields) as $g) {
            $combos = $add($combos, $g, ['keep', 'give']);
        }
        $pre = $combos[0];
        $n = array_map(static fn(array $c): int => count(array_filter(array_keys($c + $pre), static fn(string $k): bool => ($c[$k] ?? null) !== ($pre[$k] ?? null))), $combos);
        $idx = array_keys($combos);
        usort($idx, static fn(int $a, int $b): int => [$n[$a], $a] <=> [$n[$b], $b]);
        return array_map(static fn(int $i): array => $combos[$i], array_slice($idx, 0, self::MAX_COMBOS));
    }
}
