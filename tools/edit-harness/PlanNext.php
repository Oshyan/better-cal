<?php
// c-next (README, The Plan's Data): coming up, assembled by the harness from
// what the commit stored, never from the returned list. The eligible set is
// the stored series' occurrences, its edited rows and the event of its own
// the day acted on became (rows an "outside" choice kept on their own are
// not in it); the cutoff is the least of the landed day, the request's day,
// the acted row's day after and the created row's day, each in the display
// zone; the list is that set's prefix in the order days show.
declare(strict_types=1);

namespace BetterCal\EditHarness;

trait PlanNext
{
    /** The eligible set from the cutoff on, in the order days show: by day, all-day first, then instant, then key. */
    private function eligible(array $after, array $edit, array $ctx, int $cut): array
    {
        $zone = $ctx['zone'];
        $m1 = $after['row'];
        $items = [];
        $overKeys = [];
        $item = function (array $row, string $kind, string $key) use ($zone): array {
            return ['dn' => self::shownDn($row, $zone), 'allDay' => (int) $row['all_day'] === 1 ? 0 : 1, 'ts' => Clock::parse((string) $row['start_utc'])->getTimestamp(), 'key' => $key,
                'entry' => self::entryOf($row, $zone) + ['kind' => $kind]];
        };
        // The day acted on, where it isn't an edited row of the series from
        // before: its occurrence at its new key, the row made for it, or the
        // event of its own it became. Under a new rule it has no special place.
        $special = $ctx['x']->kind !== 'newrule';
        $created = $ctx['created'] === null ? null : (int) $ctx['created']['id'];
        foreach ($after['overrides'] as $id => $ov) {
            $overKeys[(string) $ov['recurrence_instance_utc']] = true;
            $items[] = $item($ov, $special && $id === $created ? 'acted' : 'edited', (string) $ov['recurrence_instance_utc']);
        }
        if ($ctx['actedAlone'] !== null) {
            $items[] = $item($ctx['actedAlone'], 'acted', (string) $ctx['actedAlone']['start_utc']);
        }
        $skipped = array_fill_keys($after['exdates'], true);
        $keys = array_column($after['raw'], 'key');
        if ($after['through'] !== null) {
            // Unbounded: read the occurrences from the cutoff on, as far as it takes to have one past the limit.
            $from = Days::date($cut - 2) . ' 00:00:00';
            // As far as 800 years: a sparse rule (a week number on a 29 February) has one occurrence in 28 years.
            for ($span = 8; $span <= 292194; $span *= 2) {
                $keys = Truth::keysFrom($m1, $from, $span);
                $n = 0;
                foreach ($keys as $k) {
                    $n += !isset($skipped[$k]) && !isset($overKeys[$k]) && self::shownDn(self::occurrenceRow($m1, $k), $zone) >= $cut ? 1 : 0;
                }
                if ($n > self::NEXT_LIMIT + 1) {
                    break;
                }
            }
        }
        // An occurrence is listed as one, or as the day acted on: the
        // contract names no rule for which occurrence that is, so either kind
        // is the occurrence's (checkNext).
        foreach ($keys as $k) {
            if (!isset($skipped[$k]) && !isset($overKeys[$k])) {
                $items[] = $item(self::occurrenceRow($m1, $k), 'occurrence', $k);
            }
        }
        $items = array_values(array_filter($items, static fn(array $i): bool => $i['dn'] >= $cut));
        usort($items, static fn(array $a, array $b): int => [$a['dn'], $a['allDay'], $a['ts'], $a['key']] <=> [$b['dn'], $b['allDay'], $b['ts'], $b['key']]);
        return $items;
    }

    /**
     * The eligible set in an order the contract allows, as close to the
     * listed one as it allows. The contract orders coming up by the day each
     * shows on, an all-day event first on its day, then by instant, and
     * states no further tie-break (api-contract, The plan), so items that tie
     * on all three may come in any order: within each run of tied items, the
     * ones the list names at those positions are put first in the list's
     * order, and the rest follow in the harness's. Nothing else moves, so the
     * set, every stated order and the cut at the limit are still compared
     * exactly. With $kind, an occurrence matches an entry listed as an
     * occurrence or as the day acted on, as checkNext reads them.
     */
    private static function tiesAsListed(array $items, array $listed, bool $kind): array
    {
        $tie = static fn(array $i): array => [$i['dn'], $i['allDay'], $i['ts']];
        $same = static function (array $want, mixed $got) use ($kind): bool {
            if (!is_array($got)) {
                return false;
            }
            if (!$kind) {
                return array_diff_key($want, ['kind' => 1]) === $got;
            }
            if ($want['kind'] === 'occurrence' && in_array($got['kind'] ?? null, ['occurrence', 'acted'], true)) {
                $got['kind'] = 'occurrence';
            }
            return $want === $got;
        };
        $out = [];
        $n = count($items);
        for ($i = 0; $i < $n;) {
            $j = $i;
            while ($j < $n && $tie($items[$j]) === $tie($items[$i])) {
                $j++;
            }
            $run = array_slice($items, $i, $j - $i);
            for ($p = $i; $p < $j && $run !== []; $p++) {
                foreach ($run as $r => $item) {
                    if ($same($item['entry'], $listed[$p] ?? null)) {
                        $out[] = $item;
                        unset($run[$r]);
                        continue 2;
                    }
                }
                break;
            }
            array_push($out, ...array_values($run));
            $i = $j;
        }
        return $out;
    }

    /** The cutoff, in the display zone: the least of the landed day, the request's day, the acted row's day after and the created row's day. */
    private static function cutoff(array $after, array $edit, array $ctx): int
    {
        $zone = $ctx['zone'];
        $days = [$ctx['x']->landedDn];
        $sent = $edit['sent'];
        if ($sent !== null) {
            $days[] = $sent['allDay'] ? Days::ofDate(substr((string) $sent['start'], 0, 10)) : Clock::dayOf((string) $sent['start'], $zone ?? 'UTC');
        }
        if ($edit['fromRow'] !== null) {
            $row = $after['overrides'][$edit['fromRow']] ?? $ctx['actedAlone'];
            if ($row !== null) {
                $days[] = self::shownDn($row, $zone);
            }
        }
        if ($ctx['created'] !== null) {
            $days[] = self::shownDn($ctx['created'], $zone);
        }
        return min($days);
    }

    private function checkNext(array $plan, array $before, array $after, array $edit, array $ctx): void
    {
        $m1 = $after['row'];
        $next = array_map(static fn(mixed $e): mixed => self::norm($e, ['start', 'allDay', 'tz', 'kind']), $plan['next'] ?? []);
        if ($after['shape']->cap === 'unreadable') {
            $p = Shape::parts((string) $m1['rrule']);
            $this->expect('c-next', 'a rule the expander can\'t read lists nothing coming up', 'unreadable next', [], $next);
            $this->expect('c-next', 'for a rule the expander can\'t read, nextMore says whether it has no end', 'unreadable nextMore', !isset($p['COUNT']) && !isset($p['UNTIL']), $plan['nextMore'] ?? null);
            return;
        }
        $cut = self::cutoff($after, $edit, $ctx);
        $items = $this->eligible($after, $edit, $ctx, $cut);
        $want = array_map(static fn(array $i): array => $i['entry'], array_slice(self::tiesAsListed($items, $next, true), 0, self::NEXT_LIMIT));
        foreach ($want as $i => $e) {
            if ($e['kind'] === 'occurrence' && in_array($next[$i]['kind'] ?? null, ['occurrence', 'acted'], true)) {
                $next[$i]['kind'] = 'occurrence';
            }
        }
        $role = ($ctx['zone'] === null ? 'all-day plan' : 'timed plan') . ($ctx['x']->kind === 'newrule' ? ', new rule' : '');
        $this->expect('c-next', 'coming up is the eligible set\'s prefix from the cutoff, in the order days show', "next ($role)", $want, $next, ['cutoff' => Days::date($cut)]);
        foreach ($want as $i => $e) {
            $this->ran('c-next', 'entry ' . $e['kind'] . (isset($e['tz']) ? ', own zone' : '') . ($i > 0 && Days::ofDate(substr($e['start'], 0, 10)) === Days::ofDate(substr($want[$i - 1]['start'], 0, 10)) ? ', same day as the one before' : ''));
        }
        $more = count($items) > self::NEXT_LIMIT;
        $this->expect('c-next', 'nextMore says whether the eligible set has an item past the limit', 'nextMore ' . ($more ? 'true' : 'false'), $more, $plan['nextMore'] ?? null);
        // The chosen reading's next events are the same list, its first three.
        foreach ($plan['groups'] ?? [] as $g) {
            if (($g['id'] ?? '') !== 'reading') {
                continue;
            }
            foreach ($g['options'] ?? [] as $o) {
                if (($o['id'] ?? null) === $g['selected'] && isset($o['next'])) {
                    $strip = static fn(array $e): array => array_diff_key($e, ['kind' => 1]);
                    $got = array_map(static fn(mixed $e): mixed => self::norm($e, ['start', 'allDay', 'tz']), $o['next']);
                    $first = array_map(static fn(array $i): array => $strip($i['entry']), array_slice(self::tiesAsListed($items, $got, false), 0, 3));
                    $this->expect('c-next', 'the chosen reading\'s next events are the first three coming up', 'chosen reading next', $first, $got);
                }
            }
        }
    }
}
