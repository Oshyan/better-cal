<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

use BetterCal\Domain\Recurrence;
use BetterCal\Support\Time;

/**
 * "This and following", delete following and an answer from a day on, on
 * the series model (series-model.md, Every Kind of Edit). One structure for
 * all three: a split at the day acted on is
 *
 *  - a bound change on the old series: its UNTIL at its last raw occurrence
 *    before the split, written in its own form, with the skips before the
 *    split and the edited rows keyed before it, untouched wherever they show;
 *  - and the part from the split as a series of its own (the old pattern,
 *    started on the day acted on, the rest of its COUNT or its UNTIL, the
 *    skips and edited rows keyed from the split on), which the model plans
 *    as a whole series under the edit. Every exception of the part is
 *    mapped by member there, as for any whole-series edit.
 *
 * Delete following is the first half alone: the part's edited rows are
 * deleted and its skips dropped. An answer from a day on is the split with
 * no fields (the part's plan maps every key to itself), the answer written
 * on the part afterwards.
 *
 * Pure, like Model: rows in, a plan and its writes out.
 */
final class Split
{
    public const EDIT = 'edit';
    public const DELETE = 'delete';
    public const ANSWER = 'answer';

    public const REASONS = [
        'unreadable' => Model::REASONS['unreadable'],
        'shape' => 'This repeat can\'t be split on this day: started there, it wouldn\'t give the same days. Change only this one, or all events.',
        'empty' => Model::REASONS['empty'],
    ];

    /**
     * The split's plan. $edit is the model's edit (acted: the day acted on,
     * required); $mode is EDIT, DELETE or ANSWER.
     *
     * Returns ['whole' => true] when the day acted on is the series' first
     * (the change is the whole series'), else ['plan', 'writes', 'missing',
     * 'refused']: writes['old'] the old master's columns, writes['part'] the
     * new series' columns before its plan (null for a delete), writes['rows']
     * the ids of the rows from the split on, writes['plan'] the part's plan
     * writes (null for a delete).
     */
    public static function plan(array $master, array $overrides, array $edit, array $choices, array $opts, string $mode): array
    {
        $acted = (string) ($edit['acted'] ?? '');
        $allDay = (int) ($master['all_day'] ?? 0) === 1;
        $tz = $allDay ? 'UTC' : (string) ($master['tzid'] ?? 'UTC');
        if (!Calc::reads((string) $master['rrule'])) {
            return self::refused($master, $opts, 'unreadable');
        }
        try {
            $old = Calc::fromRow($master);
        } catch (WalkIncomplete) {
            return self::refused($master, $opts, 'unreadable');
        }
        $actedDn = $old->dnOf($acted);
        // The old part: every raw occurrence keyed before the split (a
        // rule several times a day counts each of its times).
        $before = array_values(array_filter($old->keys($actedDn), static fn(array $k): bool => $k[1] < $acted));
        if ($before === []) {
            return ['whole' => true];
        }
        $last = $before[count($before) - 1][1];
        // UNTIL at the old part's last occurrence, in the series' own form:
        // a date for an all-day series, its instant in UTC for a timed one.
        $until = $allDay ? Time::fromDb($last)->format('Ymd') : Time::fromDb($last)->format('Ymd\THis\Z');
        $oldRule = Recurrence::inForm($old->storedRule()->withBound(null, $until)->text(), $allDay, $tz);
        $skips = Recurrence::decodeExdates($master['exdates_json'] ?? null);
        $oldSkips = array_values(array_filter($skips, static fn(string $k): bool => $k < $acted));
        $partSkips = array_values(array_filter($skips, static fn(string $k): bool => $k >= $acted));
        $kept = [];
        $partRows = [];
        foreach ($overrides as $row) {
            if ((string) $row['recurrence_instance_utc'] < $acted) {
                $kept[] = $row;
            } else {
                $partRows[] = $row;
            }
        }

        // The part from the split: the same pattern started on the day acted
        // on. Its clock is the series' (a start in a skipped hour is stored
        // the period before, as every start is, Calc); a rule several times
        // a day starts at the time acted on, its period's own. What is left
        // of a COUNT is the raw occurrences from the split on.
        $rule = $old->rule;
        $count = $rule->count !== null ? $rule->count - count($before) : null;
        $partRule = $rule->withBound($count, $rule->until);
        $clock = $rule->several && !$allDay ? self::clockOf(Time::fromDb($acted)->setTimezone(Time::zone($tz))) : $old->clock;
        $part = new Calc($partRule, $actedDn, $tz, $allDay, $clock, $old->length, true, Time::fromDb($acted));
        // The part read from its own start gives the old series' keys from
        // the split on: a check on the structure above, never a reading.
        $want = [];
        foreach ($old->keysFrom($actedDn) as $k) {
            if ($k[1] >= $acted) {
                $want[] = $k[1];
            }
            if (count($want) >= 40) {
                break;
            }
        }
        if (array_slice(array_column($part->keys(PHP_INT_MAX, 40), 1), 0, count($want)) !== $want) {
            return self::refused($master, $opts, 'shape');
        }
        $start = $part->storedStart();
        if ($part->anchor !== null) {
            $partSkips[] = Time::toDb($start);
            sort($partSkips);
        }
        $len = max(0, Time::fromDb((string) $master['end_utc'])->getTimestamp() - Time::fromDb((string) $master['start_utc'])->getTimestamp());
        $partCols = [
            'start_utc' => Time::toDb($start),
            'end_utc' => Time::toDb($start->modify('+' . $len . ' seconds')),
            'rrule' => Recurrence::inForm($part->storedRule()->text(), $allDay, $tz),
            'exdates_json' => $partSkips === [] ? null : json_encode(array_values(array_unique($partSkips))),
        ];
        $oldCols = ['rrule' => $oldRule, 'exdates_json' => $oldSkips === [] ? null : json_encode($oldSkips)];
        $oldCols = array_filter($oldCols, static fn($v, string $c): bool => (string) ($master[$c] ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH);

        $split = [
            'at' => self::keyOut($acted),
            'day' => Cal::date($actedDn),
            // The old part as it will be: its rule and bound, and what it keeps.
            'before' => [
                'rule' => ['rrule' => $oldRule, 'start' => self::inZone(Time::fromDb((string) $master['start_utc']), $allDay ? null : $tz), 'tz' => $allDay ? null : $tz, 'allDay' => $allDay],
                'bound' => ['kind' => 'until', 'until' => $until, 'events' => count($before), 'last' => Cal::date($old->dnOf($last))],
                'from' => ['kind' => $rule->count !== null ? 'count' : ($rule->until !== null ? 'until' : 'none')]
                    + ($rule->count !== null ? ['count' => $rule->count] : []) + ($rule->until !== null ? ['until' => $rule->until] : []),
                'edited' => count($kept),
                'skipped' => count(array_diff($oldSkips, $old->anchor !== null ? [(string) $master['start_utc']] : [])),
            ],
        ];
        $hashOf = static fn(array $parts): string => substr(sha1(json_encode($parts)), 0, 10);
        $seen = [self::row($master), array_map([self::class, 'row'], $kept), self::sets($opts), $mode, $acted];

        if ($mode === self::DELETE) {
            $days = [];
            foreach ($partRows as $row) {
                $days[] = ['key' => self::keyOut((string) $row['recurrence_instance_utc']), 'newKey' => null, 'kind' => 'edited',
                    'title' => (string) $row['title'], 'outcome' => 'deleted', 'day' => Cal::date($old->dnOf((string) $row['recurrence_instance_utc'])),
                    'kept' => OwnFields::decode($row['override_fields'] ?? null) ?? [], 'takes' => []];
            }
            foreach ($partSkips as $k) {
                if ($part->anchor !== null && $k === Time::toDb($start)) {
                    continue;
                }
                $days[] = ['key' => self::keyOut($k), 'newKey' => null, 'kind' => 'skipped', 'outcome' => 'dropped', 'why' => 'outside', 'side' => 'end',
                    'shows' => Cal::date($old->dnOf($k)), 'kept' => [], 'takes' => []];
            }
            $plan = [
                'scope' => 'following', 'action' => 'delete', 'direct' => false, 'split' => $split + ['after' => null],
                'groups' => [], 'days' => $days, 'next' => [], 'nextMore' => false, 'refusals' => [], 'notes' => [],
                'summary' => "Ended '" . (string) $master['title'] . "' before " . Words::date($actedDn, self::thisYear($opts)) . '.',
            ];
            $plan = ['hash' => $hashOf(array_merge($seen, [array_map([self::class, 'row'], $partRows)]))] + $plan;
            return ['plan' => $plan, 'writes' => ['old' => $oldCols, 'part' => null, 'rows' => array_map(static fn(array $r): int => (int) $r['id'], $partRows), 'plan' => null],
                'missing' => [], 'refused' => false];
        }

        // The part as a series of its own, planned as a whole series: the
        // edit for "this and following", no fields for an answer. Its words
        // say its reach, the day it starts from.
        $partMaster = $partCols + $master;
        $opts = ['splitDay' => $actedDn] + $opts;
        $partEdit = $mode === self::ANSWER ? ['acted' => $acted] : $edit;

        // The rule sent (decisions.md, a split's sent rule): compared with
        // the series' rule as shown, which is what the editor sent back, so
        // an unchanged rule is no rule and the part keeps the rest of the
        // series' count. A changed UNTIL is the part's end as sent; one
        // before the split leaves the part nothing, refused as an end before
        // the start is. A changed COUNT is the series' whole count, the part
        // taking what is left of it, with that many from this day on as the
        // other reading.
        $counts = null;
        $sentBound = null;
        if ($mode === self::EDIT && isset($edit['rrule']) && $edit['rrule'] !== '') {
            [$delta, $sent] = Model::sentRule($master, $overrides, $edit, $opts);
            if ($delta === null) {
                unset($partEdit['rrule']);
            } elseif ($delta === 'bound' && $sent->until !== null) {
                if (self::untilBefore((string) $sent->until, $acted, $actedDn)) {
                    return self::refused($master, $opts, 'empty');
                }
                $partEdit['boundSent'] = true;
                $sentBound = ['kind' => 'until', 'until' => (string) $sent->until];
            } elseif ($delta === 'bound' && $sent->count !== null) {
                $counts = ['whole' => $sent->count - count($before), 'part' => $sent->count];
                // Each reading is the rule as sent with its count, never the
                // series' reading of it: that reading drops the clock parts
                // of a series made all-day, and the part, comparing it with
                // its own rule, would take it for a new pattern and lose the
                // drag sent with it.
                $sentCount = Rule::parse((string) $edit['rrule'], $actedDn);
            }
        }
        if ($counts === null) {
            $result = Model::plan($partMaster, $partRows, $partEdit, $choices, $opts);
            $boundGroup = null;
        } else {
            [$result, $boundGroup, $n] = self::countReadings($partMaster, $partRows, $partEdit, $choices, $opts, $sentCount, $counts, $actedDn);
            $sentBound = ['kind' => 'count', 'count' => $n];
        }
        $plan = $result['plan'];
        if ($boundGroup !== null) {
            array_unshift($plan['groups'], $boundGroup);
            $plan['refusals'] = array_merge($plan['refusals'], $boundGroup['refusals']);
            unset($plan['groups'][0]['refusals']);
            if (!in_array($choices['bound'] ?? null, array_column(array_filter($boundGroup['options'], static fn(array $o): bool => empty($o['disabled'])), 'id'), true)) {
                $result['missing'][] = 'bound';
            }
        }
        $plan['scope'] = 'following';
        $plan['action'] = $mode === self::ANSWER ? 'answer' : 'edit';
        // A split changes the old series' bound, so it is never direct.
        $plan['direct'] = false;
        $plan['split'] = $split + ['after' => [
            'start' => self::inZone($start, $allDay ? null : $tz),
            'bound' => $sentBound ?? (['kind' => $count !== null ? 'count' : ($rule->until !== null ? 'until' : 'none')] + ($count !== null ? ['count' => $count] : []) + ($rule->until !== null ? ['until' => $rule->until] : [])),
            'edited' => count($partRows),
            'skipped' => count(array_diff($partSkips, $part->anchor !== null ? [Time::toDb($start)] : [])),
        ]];
        $plan['hash'] = $hashOf(array_merge($seen, [$plan['hash']], $boundGroup !== null ? [$boundGroup['selected']] : []));
        return [
            'plan' => $plan,
            'writes' => $result['writes'] === null ? null : ['old' => $oldCols, 'part' => $partCols, 'rows' => array_map(static fn(array $r): int => (int) $r['id'], $partRows), 'plan' => $result['writes']],
            'missing' => $result['missing'],
            'refused' => $result['refused'],
            'refusedChoice' => $result['refusedChoice'] ?? false,
        ];
    }

    /**
     * A COUNT changed on a split, read two ways (decisions.md, a split's
     * sent rule): `whole`, the series' whole count, the part taking what is
     * left of it, preselected since the number shown was the series'; and
     * `part`, that many from this day on. Each is the part planned with its
     * count as sent, and its option shows its bound and first events. A
     * reading that leaves the part nothing is disabled with the reason
     * every such reading has.
     *
     * @param array{whole:int,part:int} $counts
     * @return array{0:array,1:array,2:int} the chosen reading's result, the group, its count
     */
    private static function countReadings(array $partMaster, array $partRows, array $partEdit, array $choices, array $opts, Rule $sent, array $counts, int $actedDn): array
    {
        $labels = [
            'whole' => Words::events($counts['part']) . ' in the whole series',
            'part' => Words::events($counts['part']) . ' from ' . Cal::date($actedDn) . ' on',
        ];
        $alts = [];
        $options = [];
        $refusals = [];
        $partChoices = array_diff_key($choices, ['bound' => true]);
        foreach ($counts as $id => $n) {
            $option = ['id' => $id, 'label' => $labels[$id]];
            if ($n >= 1) {
                $alts[$id] = Model::plan($partMaster, $partRows, ['rrule' => $sent->withBound($n, null)->text(), 'boundSent' => true] + $partEdit, $partChoices, $opts);
                $alt = $alts[$id]['plan'];
                $option += ['bound' => $alt['bound'], 'next' => array_map(static fn(array $e): array => array_intersect_key($e, ['start' => 1, 'allDay' => 1, 'tz' => 1]), array_slice($alt['next'], 0, 3))];
            } else {
                $option += ['disabled' => true, 'reason' => Model::REASONS['empty']];
                $refusals[] = ['group' => 'bound', 'option' => $id, 'code' => 'empty', 'words' => Model::REASONS['empty']];
            }
            $options[] = $option;
        }
        $selected = isset($alts[$choices['bound'] ?? '']) ? (string) $choices['bound'] : (isset($alts['whole']) ? 'whole' : 'part');
        $group = ['id' => 'bound', 'label' => 'Bound', 'selected' => $selected, 'count' => $counts['part'], 'at' => Cal::date($actedDn), 'options' => $options, 'refusals' => $refusals];
        return [$alts[$selected], $group, $counts[$selected]];
    }

    /** The year whose dates the words leave without one: today's (opts today), as Model reads it. */
    private static function thisYear(array $opts): int
    {
        return (int) substr((string) ($opts['today'] ?? Time::nowUtc()->format('Y-m-d')), 0, 4);
    }

    /** Whether a sent UNTIL ends before the day acted on: a date against its day, an instant against its key. */
    private static function untilBefore(string $until, string $acted, int $actedDn): bool
    {
        if (strlen($until) === 8) {
            return Cal::dn((int) substr($until, 0, 4), (int) substr($until, 4, 2), (int) substr($until, 6, 2)) < $actedDn;
        }
        $at = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $until, Time::utc());
        return $at !== false && $at < Time::fromDb($acted);
    }

    /** A split the series can't take, with its reason and the series as it is. */
    private static function refused(array $master, array $opts, string $code): array
    {
        $plan = Model::refusedPlan($master, 'dayset', 'following', $opts);
        $plan['refusals'] = [['code' => $code, 'words' => self::REASONS[$code]]];
        $plan['summary'] = 'Not changed: ' . self::REASONS[$code];
        $plan['split'] = null;
        $plan['hash'] = substr(sha1(json_encode([self::row($master), $code])), 0, 10);
        return ['plan' => $plan, 'writes' => null, 'missing' => [], 'refused' => true];
    }

    private static function row(array $r): array
    {
        ksort($r);
        return array_map(static fn($v) => $v === null ? null : (string) $v, $r);
    }

    private static function sets(array $opts): array
    {
        $out = [];
        foreach (['masterTags', 'masterPeople', 'rowTags', 'rowPeople', 'rowLinks'] as $k) {
            $v = $opts[$k] ?? [];
            if (is_array($v)) {
                ksort($v);
            }
            $out[$k] = $v;
        }
        return $out;
    }

    private static function keyOut(string $dbKey): string
    {
        return str_replace(' ', 'T', $dbKey) . 'Z';
    }

    private static function inZone(\DateTimeImmutable $at, ?string $tz): string
    {
        return $tz === null ? Time::dayOf($at, true, null) : Time::iso($at->setTimezone(Time::zone($tz)));
    }

    /** @return array{0:int,1:int,2:int} */
    private static function clockOf(\DateTimeImmutable $t): array
    {
        return [(int) $t->format('G'), (int) $t->format('i'), (int) $t->format('s')];
    }
}
