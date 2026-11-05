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
        'google' => 'On a Google calendar, splitting a series from a day on comes in a later version. Change only this one, or all events.',
        'unreadable' => Model::REASONS['unreadable'],
        'shape' => 'This repeat can\'t be split on this day: started there, it wouldn\'t give the same days. Change only this one, or all events.',
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
        // On a Google calendar until 1.5.2 (series-model.md, Google, as a
        // Contract): a split is unavailable, its reason in place of the plan.
        if (!empty($opts['google'])) {
            return self::refused($master, $opts, 'google');
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
                'summary' => "Ended '" . (string) $master['title'] . "' before " . Cal::date($actedDn) . '.',
            ];
            $plan = ['hash' => $hashOf(array_merge($seen, [array_map([self::class, 'row'], $partRows)]))] + $plan;
            return ['plan' => $plan, 'writes' => ['old' => $oldCols, 'part' => null, 'rows' => array_map(static fn(array $r): int => (int) $r['id'], $partRows), 'plan' => null],
                'missing' => [], 'refused' => false];
        }

        // The part as a series of its own, planned as a whole series: the
        // edit for "this and following", no fields for an answer.
        $partMaster = $partCols + $master;
        $partEdit = $mode === self::ANSWER ? ['acted' => $acted] : $edit;
        $result = Model::plan($partMaster, $partRows, $partEdit, $choices, $opts);
        $plan = $result['plan'];
        $plan['scope'] = 'following';
        $plan['action'] = $mode === self::ANSWER ? 'answer' : 'edit';
        // A split changes the old series' bound, so it is never direct.
        $plan['direct'] = false;
        $plan['split'] = $split + ['after' => [
            'start' => self::inZone($start, $allDay ? null : $tz),
            'bound' => ['kind' => $count !== null ? 'count' : ($rule->until !== null ? 'until' : 'none')] + ($count !== null ? ['count' => $count] : []) + ($rule->until !== null ? ['until' => $rule->until] : []),
            'edited' => count($partRows),
            'skipped' => count(array_diff($partSkips, $part->anchor !== null ? [Time::toDb($start)] : [])),
        ]];
        $plan['hash'] = $hashOf(array_merge($seen, [$plan['hash']]));
        return [
            'plan' => $plan,
            'writes' => $result['writes'] === null ? null : ['old' => $oldCols, 'part' => $partCols, 'rows' => array_map(static fn(array $r): int => (int) $r['id'], $partRows), 'plan' => $result['writes']],
            'missing' => $result['missing'],
            'refused' => $result['refused'],
            'refusedChoice' => $result['refusedChoice'] ?? false,
        ];
    }

    /** A split the series can't take, with its reason and the series as it is. */
    private static function refused(array $master, array $opts, string $code): array
    {
        $plan = Model::refusedPlan($master, 'dayset', 'following', $opts);
        $plan['refusals'] = [['code' => $code === 'google' ? 'google' : $code, 'words' => self::REASONS[$code]]];
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
        return $tz === null ? $at->setTimezone(Time::utc())->format('Y-m-d') : Time::iso($at->setTimezone(Time::zone($tz)));
    }

    /** @return array{0:int,1:int,2:int} */
    private static function clockOf(\DateTimeImmutable $t): array
    {
        return [(int) $t->format('G'), (int) $t->format('i'), (int) $t->format('s')];
    }
}
