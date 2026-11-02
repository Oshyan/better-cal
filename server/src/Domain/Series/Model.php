<?php

declare(strict_types=1);

namespace BetterCal\Domain\Series;

use BetterCal\Support\Time;

/**
 * The series model (docs/design/series-model.md): one pure function from a
 * series' rows and a whole-series edit to a plan. No database: the caller
 * passes the master, its edited rows (with override_fields) and the edit,
 * and writes only what the plan's `writes` say.
 *
 * $edit:
 *  - acted: the day acted on, as its key (UTC db string); the series' start when null
 *  - landed: ['start_utc','end_utc','all_day','tzid'] for the day acted on after the edit, or null
 *  - rrule: present when a rule was sent (a string, or null for "does not repeat")
 *  - columns: the content columns the edit sets (title, location..., calendar_id), already normalized
 *  - tags, people: the series' new tag names or person ids, when sent
 *  - calendarId: a move to another calendar
 *
 * $opts: google (bool), today ('Y-m-d', for the years in words), masterTags,
 * masterPeople, rowTags [id => list], rowPeople [id => list].
 *
 * Returns ['plan' => the plan as the API sends it, 'writes' => what the
 * writer applies, 'selected' => [group => option], 'groups' => list of
 * group ids, 'refused' => bool].
 */
final class Model
{
    public const CONTENT_FIELDS = ['title', 'location', 'description', 'url', 'reminders', 'status', 'icon', 'attendance', 'tags', 'people'];

    private const OWN_WORDS = [
        'date' => 'date', 'time' => 'time', 'length' => 'length', 'allDay' => 'all-day setting', 'title' => 'title', 'location' => 'place',
        'description' => 'notes', 'url' => 'link', 'reminders' => 'reminders', 'status' => 'status', 'attendance' => 'answer',
        'tags' => 'tags', 'people' => 'people', 'icon' => 'icon',
    ];

    public const REASONS = [
        'unreadable' => 'This repeat came from another app in a form Better-Cal can\'t read, so only its details can change here.',
        'several' => 'This repeat happens several times a day, and on a day the clocks change two of its times can fall on one moment, so it can\'t be moved or given another zone as a series. Move one event, or change its other details.',
        'severalAllDay' => 'This repeat happens several times a day, so it can\'t be made all-day.',
        'weekno' => 'This repeat counts weeks of the year, so its days can\'t follow a drag.',
        'overlap' => 'Two of its days are the same day in some months, so its days can\'t follow a drag.',
        'dayset' => 'This repeat came from another app and picks its days in a way a drag can\'t follow. Move only this one, or open the series to choose its days.',
        'google' => 'On a Google calendar, changing which days this repeats on comes in a later version. Move only this one, or change its time or details for all events.',
        'empty' => 'This would leave the series with no events. Delete it instead?',
    ];

    public const GOOGLE_NOTE = 'Google decides what happens to edited days, and Better-Cal hasn\'t checked what it does yet. This can\'t be undone.';

    private Rule $r0;
    private Calc $old;
    private string $tz0;
    private bool $allDay0;
    /** @var array{0:int,1:int,2:int} */
    private array $clock0;
    private int $len0;
    private string $acted;
    private int $actedDn0;
    private string $tz1;
    private bool $allDay1;
    /** @var array{0:int,1:int,2:int} */
    private array $clock1;
    private int $len1;
    private int $landedDn;
    /** fields, time, drag, bound, newrule or norule */
    private string $kind = 'fields';
    private ?Rule $sentRule = null;
    private bool $sentBound = false;
    /** @var list<string> */
    private array $exdates;
    private int $thisYear;
    private bool $google;
    /** @var array<string,array<string,mixed>> content field => columns it sets */
    private array $changed = [];
    private bool $readable;
    /** @var array<int,array<string,mixed>> */
    private array $rows = [];

    // Per computation.
    private array $choices = [];
    private array $groups = [];
    private array $refusals = [];
    private array $notes = [];

    private function __construct(private array $master, array $overrides, private array $edit, private array $opts)
    {
        foreach ($overrides as $row) {
            $this->rows[(int) $row['id']] = $row;
        }
        ksort($this->rows);
        $this->google = !empty($opts['google']);
        $this->thisYear = (int) substr((string) ($opts['today'] ?? Time::nowUtc()->format('Y-m-d')), 0, 4);
        $this->tz0 = (string) ($master['tzid'] ?? 'UTC');
        $this->allDay0 = (int) ($master['all_day'] ?? 0) === 1;
        $start = Time::fromDb((string) $master['start_utc'])->setTimezone(Time::zone($this->tz0));
        $startDn = Cal::dnOf($start);
        $this->clock0 = $this->allDay0 ? [0, 0, 0] : [(int) $start->format('G'), (int) $start->format('i'), (int) $start->format('s')];
        $this->len0 = max(0, Time::fromDb((string) $master['end_utc'])->getTimestamp() - $start->getTimestamp());
        $this->r0 = Rule::parse((string) $master['rrule'], $startDn);
        $this->readable = $this->r0->kind !== 'unreadable' && Calc::sabreReads((string) $master['rrule']);
        if (!$this->readable) {
            $this->r0->kind = 'unreadable';
        }
        $this->old = new Calc($this->r0, $startDn, $this->tz0, $this->allDay0, $this->clock0, $this->len0);
        $this->clock0 = $this->old->clock;
        try {
            $this->exdates = \BetterCal\Domain\Recurrence::decodeExdates($master['exdates_json'] ?? null);
        } catch (\Throwable) {
            $this->exdates = [];
        }
        sort($this->exdates);

        $this->acted = (string) ($edit['acted'] ?? $master['start_utc']);
        $this->actedDn0 = $this->old->dnOf($this->acted);
        $landed = $edit['landed'] ?? null;
        $this->tz1 = $this->tz0;
        $this->allDay1 = $this->allDay0;
        $this->clock1 = $this->clock0;
        $this->len1 = $this->len0;
        $this->landedDn = $this->actedDn0;
        if (is_array($landed)) {
            $this->tz1 = (string) ($landed['tzid'] ?? $this->tz0);
            $this->allDay1 = (int) ($landed['all_day'] ?? ($this->allDay0 ? 1 : 0)) === 1;
            $at = Time::fromDb((string) $landed['start_utc'])->setTimezone(Time::zone($this->tz1));
            $this->landedDn = Cal::dnOf($at);
            $this->clock1 = $this->allDay1 ? [0, 0, 0] : [(int) $at->format('G'), (int) $at->format('i'), (int) $at->format('s')];
            $this->len1 = max(0, Time::fromDb((string) $landed['end_utc'])->getTimestamp() - Time::fromDb((string) $landed['start_utc'])->getTimestamp());
            // A timed series made all-day arrives as dates already
            // (Events::columnPatch dates it in its old zone).
        }

        $content = [];
        foreach ((array) ($edit['columns'] ?? []) as $col => $value) {
            $field = OwnFields::fieldOf((string) $col);
            if ($field !== null && (string) ($master[$col] ?? '') !== (string) ($value ?? '')) {
                $content[$field] = true;
            }
        }
        foreach ((array) ($edit['columns'] ?? []) as $col => $value) {
            $field = OwnFields::fieldOf((string) $col);
            if ($field !== null && isset($content[$field])) {
                $this->changed[$field][$col] = $value;
            }
        }
        if (array_key_exists('tags', $edit) && $edit['tags'] !== null && self::set((array) $edit['tags']) !== self::set((array) ($opts['masterTags'] ?? []))) {
            $this->changed['tags'] = ['names' => array_values((array) $edit['tags'])];
        }
        if (array_key_exists('people', $edit) && $edit['people'] !== null && self::set((array) $edit['people']) !== self::set((array) ($opts['masterPeople'] ?? []))) {
            $this->changed['people'] = ['ids' => array_values((array) $edit['people'])];
        }

        if (array_key_exists('rrule', $edit)) {
            $sent = $edit['rrule'];
            if ($sent === null || $sent === '') {
                $this->kind = 'norule';
            } else {
                $rebased = $startDn + ($this->landedDn - $this->actedDn0);
                $parsed = Rule::parse((string) $sent, $startDn);
                if ($this->readable && $parsed->comparable() === $this->r0->comparable()) {
                    if (!$this->sameBound($parsed)) {
                        $this->sentRule = $parsed;
                        $this->sentBound = true;
                    }
                } elseif (!$this->readable && strtoupper(trim((string) $sent)) === strtoupper(trim((string) $master['rrule']))) {
                    // The unreadable rule sent back as it is.
                } else {
                    $this->sentRule = Rule::parse((string) $sent, $rebased);
                    $this->kind = 'newrule';
                }
            }
        }
        if ($this->kind === 'fields') {
            $timeChanged = $this->clock1 !== $this->clock0 || Time::normalizeTzid($this->tz1) !== Time::normalizeTzid($this->tz0)
                || $this->allDay1 !== $this->allDay0 || $this->len1 !== $this->len0;
            if ($this->landedDn !== $this->actedDn0) {
                $this->kind = 'drag';
            } elseif ($timeChanged) {
                $this->kind = 'time';
            } elseif ($this->sentBound) {
                $this->kind = 'bound';
            }
        }
    }

    public static function plan(array $master, array $overrides, array $edit, array $choices = [], array $opts = []): array
    {
        $model = new self($master, $overrides, $edit, $opts);
        return $model->run($choices);
    }

    private function run(array $choices): array
    {
        $result = $this->compute($choices);
        $plan = $result['plan'];
        foreach ($plan['groups'] as $gi => $group) {
            foreach ($group['options'] as $oi => $option) {
                if (!empty($option['disabled'])) {
                    continue;
                }
                $alt = $option['id'] === $group['selected'] ? $result : $this->compute([$group['id'] => $option['id']] + $result['selected']);
                $plan['groups'][$gi]['options'][$oi]['next'] = array_slice($alt['nextDates'], 0, 3);
                $plan['groups'][$gi]['options'][$oi]['bound'] = ['words' => $alt['plan']['bound']['words']];
                if ($option['id'] !== $group['selected']) {
                    $plan['groups'][$gi]['options'][$oi]['differs'] = $this->differs($alt, $result);
                }
            }
        }
        $plan['hash'] = $this->hash($result['selected']);
        $missing = [];
        foreach ($plan['groups'] as $group) {
            $sent = $choices[$group['id']] ?? null;
            $valid = false;
            foreach ($group['options'] as $o) {
                if ($o['id'] === $sent && empty($o['disabled'])) {
                    $valid = true;
                }
            }
            if (!$valid) {
                $missing[] = $group['id'];
            }
        }
        return [
            'plan' => ['hash' => $plan['hash']] + $plan,
            'writes' => $result['writes'],
            'selected' => $result['selected'],
            'missing' => $missing,
            'refused' => $plan['refusals'] !== [] && $result['writes'] === null,
            'refusedChoice' => $result['refusedChoice'] ?? false,
        ];
    }

    private function hash(array $selected): string
    {
        $rows = [];
        foreach ($this->rows as $id => $row) {
            $rows[] = [$id, $row['recurrence_instance_utc'] ?? null, $row['start_utc'] ?? null, $row['end_utc'] ?? null, $row['updated_at'] ?? null, $row['override_fields'] ?? null];
        }
        ksort($selected);
        $m = $this->master;
        return substr(sha1(json_encode([
            [$m['id'] ?? null, $m['rrule'] ?? null, $m['start_utc'] ?? null, $m['end_utc'] ?? null, $m['tzid'] ?? null, $m['all_day'] ?? null, $m['exdates_json'] ?? null, $m['updated_at'] ?? null, $m['title'] ?? null],
            $rows, $this->edit, $selected, $this->google,
        ])), 0, 10);
    }

    /** Where a reading parts from the selected one: its first dates that differ, past the bound when they agree up to it. */
    private function differs(array $alt, array $sel): array
    {
        [$ab, $ap] = $alt['walk'];
        [$bb, $bp] = $sel['walk'];
        if ($ab !== $bb) {
            $n = min(count($ab ?? []), count($bb ?? []));
            for ($i = 0; $i < $n; $i++) {
                if ($ab[$i] !== $bb[$i]) {
                    return ['dates' => [Cal::date($ab[$i])], 'words' => ($i >= 3 ? 'the same ' . $i . ' dates; ' : '') . 'parts on ' . Words::dayMonth(min($ab[$i], $bb[$i]), $this->thisYear)];
                }
            }
            return ['dates' => [], 'words' => count($ab ?? []) > count($bb ?? []) ? 'more events' : 'fewer events'];
        }
        $same = $ab !== null ? 'the same ' . count($ab) . ' dates; if extended, ' : '';
        $n = min(count($ap), count($bp));
        for ($i = 0; $i < $n; $i++) {
            if ($ap[$i] !== $bp[$i]) {
                return ['dates' => [Cal::date($ap[$i])], 'words' => $same . 'parts on ' . Words::dayMonth(min($ap[$i], $bp[$i]), $this->thisYear)];
            }
        }
        return ['dates' => [], 'words' => 'the same dates; its edited and skipped days differ'];
    }

    // ---- one plan for one set of choices ----------------------------------

    private function compute(array $choices): array
    {
        $this->choices = $choices;
        $this->groups = [];
        $this->refusals = [];
        $this->notes = [];
        $selected = [];

        $whole = $this->capability();
        if ($whole !== null) {
            return $this->refused($whole);
        }
        $delta = $this->landedDn - $this->actedDn0;
        $clock1 = $this->clock1;
        // A zone change: the same clock time, or the same moment.
        if (Time::normalizeTzid($this->tz1) !== Time::normalizeTzid($this->tz0) && !$this->allDay0 && !$this->allDay1
            && $delta === 0 && $this->clock1 === $this->clock0 && $this->kind === 'time') {
            $moment = Time::fromDb($this->acted)->setTimezone(Time::zone($this->tz1));
            $mDelta = Cal::dnOf($moment) - $this->actedDn0;
            $mClock = [(int) $moment->format('G'), (int) $moment->format('i'), (int) $moment->format('s')];
            if ($mDelta !== 0 || $mClock !== $clock1) {
                $momentOption = ['id' => 'moment', 'label' => 'The same moment as now'];
                if ($mDelta !== 0 && ($this->r0->fixedDays !== null || !$this->r0->supported())) {
                    $momentOption += ['disabled' => true, 'reason' => self::REASONS[$this->r0->fixedDays ?? 'dayset']];
                }
                $zone = $this->pick('zone', 'Zone', [
                    ['id' => 'clock', 'label' => sprintf('%02d:%02d in %s', $clock1[0], $clock1[1], self::zoneName($this->tz1))],
                    $momentOption,
                ], 'clock');
                if ($zone === 'moment') {
                    $delta = $mDelta;
                    $clock1 = $mClock;
                }
            }
        }
        $landedDn = $this->actedDn0 + $delta;
        $readings = $this->readings($delta, $landedDn);
        if ($readings === []) {
            return $this->refused('dayset');
        }
        $options = [];
        $default = null;
        foreach ($readings as $r) {
            $opt = ['id' => $r['id'], 'label' => $r['label']];
            // On Google until 1.5.2: only a reading that keeps the rule text
            // as written (a list only as its whole-pattern shift).
            if ($this->google && ($r['rule']->pattern() !== $this->r0->pattern() || $r['id'] === 'member') && ($this->kind === 'drag' || $delta !== 0)) {
                $r['disabled'] = true;
                $r['code'] = 'google';
                $r['reason'] = self::REASONS['google'];
            }
            if (!empty($r['disabled'])) {
                $opt += ['disabled' => true, 'reason' => $r['reason']];
                $this->refusals[] = ['group' => 'reading', 'option' => $r['id'], 'code' => $r['code'], 'words' => $r['reason']];
            } elseif ($default === null) {
                $default = $r['id'];
            }
            $options[] = $opt;
        }
        if ($default === null) {
            $code = $readings[0]['code'] ?? 'dayset';
            return $this->refused($code, $readings[0]['reason'] ?? null);
        }
        $readings = $this->merged($readings, $delta, $clock1, $landedDn);
        $options = array_values(array_filter($options, static function (array $o) use ($readings): bool {
            foreach ($readings as $r) {
                if ($r['id'] === $o['id']) {
                    return true;
                }
            }
            return false;
        }));
        $pickedId = count($options) > 1 ? $this->pick('reading', $this->kind === 'newrule' ? 'Edited and skipped days' : 'How it repeats', $options, $default) : $default;
        $reading = null;
        foreach ($readings as $r) {
            if ($r['id'] === $pickedId) {
                $reading = $r;
            }
        }
        $out = $this->map($reading, $delta, $clock1, $landedDn);
        if ($out === null) {
            return $this->refused('empty');
        }
        return $out;
    }

    /** A refusal of the whole edit, with the series as it is. */
    private function refused(string $code, ?string $words = null): array
    {
        $this->refusals[] = ['code' => $code, 'words' => $words ?? self::REASONS[$code] ?? $code];
        $before = Words::rule($this->r0, $this->allDay0 ? null : $this->clock0);
        return [
            'plan' => [
                'scope' => 'all',
                'direct' => false,
                'rule' => ['before' => (string) $this->master['rrule'], 'after' => (string) $this->master['rrule'], 'wordsBefore' => $before, 'words' => $before],
                'start' => Time::iso(Time::fromDb((string) $this->master['start_utc'])->setTimezone(Time::zone($this->tz0))),
                'bound' => ['kind' => 'none', 'words' => ''],
                'next' => [], 'groups' => [], 'days' => [], 'losses' => [], 'gains' => [],
                'refusals' => $this->refusals, 'notes' => $this->notes, 'fields' => [],
                'summary' => 'Not changed: ' . ($words ?? self::REASONS[$code] ?? $code),
            ],
            'writes' => null, 'selected' => [], 'nextDates' => [], 'walk' => [null, []],
        ];
    }

    /** Why this edit can't apply to this rule at all, or null (Imported Rules, by Capability). */
    private function capability(): ?string
    {
        $moves = $this->landedDn !== $this->actedDn0;
        $zone = Time::normalizeTzid($this->tz1) !== Time::normalizeTzid($this->tz0);
        $timeOrDays = $this->kind !== 'fields';
        if (!$this->readable) {
            return $timeOrDays ? 'unreadable' : null;
        }
        if ($this->kind === 'newrule' || $this->kind === 'norule') {
            return $this->google ? 'google' : null;
        }
        if ($this->r0->several) {
            if ($this->allDay1 !== $this->allDay0) {
                return 'severalAllDay';
            }
            if ($moves || $zone || $this->clock1 !== $this->clock0) {
                return 'several';
            }
        }
        if ($moves && $this->r0->fixedDays !== null) {
            return $this->r0->fixedDays;
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    private function readings(int $delta, int $landedDn): array
    {
        if ($this->kind === 'newrule' || $this->kind === 'norule') {
            return $this->newRuleReadings($delta);
        }
        if ($delta === 0) {
            $same = Readings::same($this->r0);
            $rule = clone $this->r0;
            if (($this->r0->hour !== null || $this->r0->minute !== null)) {
                if ($this->allDay1) {
                    $rule->hour = null;
                    $rule->minute = null;
                } else {
                    $rule->hour = $this->r0->hour !== null ? $this->clock1[0] : null;
                    $rule->minute = $this->r0->minute !== null ? $this->clock1[1] : null;
                }
                $same['rule'] = $rule;
            }
            return [$same];
        }
        return Readings::forDrag($this->r0, $this->actedDn0, $landedDn, $this->old->startDn);
    }

    /** A new pattern sent from the editor (or none): keys on its days stay; a weekly shift offers the drag's mapping. */
    private function newRuleReadings(int $delta): array
    {
        $rebased = $this->old->startDn + $delta;
        $rule = $this->kind === 'norule' ? Rule::parse('FREQ=DAILY;COUNT=1', $rebased) : $this->sentRule;
        if (!$rule->supported() && $this->kind !== 'norule') {
            $start = $rebased;
        } else {
            $start = $this->kind === 'norule' ? $rebased : self::firstOn($rule, $rebased);
        }
        $pattern = $rule->withBound(null, null);
        $probe = new Calc($pattern, $start, $this->tz1, $this->allDay1, $this->clock1, $this->len1);
        $keep = [
            'id' => 'keep', 'label' => 'Keep them where they are, as events of their own', 'rule' => $pattern, 'start' => $start,
            'image' => static fn(int $dn): ?int => $probe->patternHas($dn) ? $dn : null, 'textKept' => false, 'newRule' => $rule,
        ];
        $out = [$keep];
        if ($this->r0->kind === 'weekly' && $rule->kind === 'weekly' && count($this->r0->members) === 1 && $rule->interval === $this->r0->interval) {
            foreach ([1, -1, 2, -2, 3, -3, 4, -4, 5, -5, 6, -6] as $d) {
                $shifted = Readings::forDrag($this->r0, $this->old->startDn, $this->old->startDn + $d, $this->old->startDn)[0]['rule'] ?? null;
                if ($shifted !== null && $shifted->comparable() === $rule->comparable()) {
                    array_unshift($out, [
                        'id' => 'map', 'label' => 'Move edited and skipped days with their events, as a drag would', 'rule' => $pattern,
                        'image' => static fn(int $dn): int => $dn + $d, 'textKept' => false, 'newRule' => $rule,
                    ]);
                    break;
                }
            }
        }
        return $out;
    }

    /** The first day on or after $from that a supported rule gives, counted from $from's period. */
    private static function firstOn(Rule $rule, int $from): int
    {
        $probe = new Calc($rule->withBound(null, null), $from, 'UTC', true, [0, 0, 0], 86400);
        $p0 = $rule->periodOf($from);
        $p = $p0;
        for ($i = 0; $i < 4800; $i++) {
            foreach ($rule->periodDays($p) as [$d]) {
                if ($d >= $from) {
                    return $d;
                }
            }
            $p = $rule->periodPlus($p, $rule->interval);
        }
        unset($probe);
        return $from;
    }

    /** Readings whose whole plans are equal merge into the first (Finding the Readings). */
    private function merged(array $readings, int $delta, array $clock1, int $landedDn): array
    {
        if (count($readings) < 2) {
            return $readings;
        }
        $seen = [];
        $out = [];
        foreach ($readings as $r) {
            if (!empty($r['disabled'])) {
                $out[] = $r;
                continue;
            }
            $saved = [$this->groups, $this->refusals, $this->notes, $this->choices];
            $this->groups = [];
            $this->choices = [];
            $m = $this->map($r, $delta, $clock1, $landedDn, true);
            [$this->groups, $this->refusals, $this->notes, $this->choices] = $saved;
            $sig = $m === null ? 'none' : json_encode([$m['walk'], $m['writes']['master']['start_utc'] ?? null, $m['boundKey'], $m['writes']['overrides'], $m['writes']['exdates']]);
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * A group of choices: its options, the one selected (the caller's when
     * it named an enabled one, else the default). A group with one option
     * is no choice and isn't listed.
     */
    private function pick(string $id, string $label, array $options, string $default, array $extra = []): string
    {
        $enabled = array_values(array_filter($options, static fn(array $o): bool => empty($o['disabled'])));
        if ($enabled === []) {
            return $default;
        }
        $ids = array_map(static fn(array $o): string => $o['id'], $enabled);
        $sel = in_array($this->choices[$id] ?? null, $ids, true) ? $this->choices[$id] : (in_array($default, $ids, true) ? $default : $ids[0]);
        if (count($options) > 1) {
            $this->groups[] = ['id' => $id, 'label' => $label, 'selected' => $sel, 'options' => $options] + $extra;
        }
        return $sel;
    }

    // ---- mapping -----------------------------------------------------------

    /**
     * Everything for one reading under the current choices: the rule, start
     * and bound, every exception's outcome, losses and gains, the words and
     * the row writes. Null when nothing would be left.
     */
    private function map(array $reading, int $delta, array $clock1, int $landedDn, bool $probe = false): ?array
    {
        $image = $reading['image'];
        $r1p = $reading['rule']->withBound(null, null);
        $newRule = $this->kind === 'newrule' || $this->kind === 'norule';
        $identity = $reading['id'] === 'same';
        $timeSame = $clock1 === $this->clock0 && Time::normalizeTzid($this->tz1) === Time::normalizeTzid($this->tz0) && $this->allDay1 === $this->allDay0;

        // The old raw occurrences this needs: all of a bounded series; of an
        // unbounded one, through its last exception and the day acted on, and
        // a few periods on.
        $exDns = array_map(fn(string $k): int => $this->old->dnOf($k), array_merge($this->exdates, array_map(static fn(array $r): string => (string) $r['recurrence_instance_utc'], $this->rows)));
        $span = match ($this->r0->kind) {
            'daily' => 3 * $this->r0->interval + 14,
            'weekly' => 21 * $this->r0->interval + 14,
            'monthly' => 366 * $this->r0->interval + 62,
            'yearly' => 1100 * $this->r0->interval + 400,
            default => 400,
        };
        $horizon = max([$this->actedDn0, $this->old->startDn, ...$exDns]) + $span;
        $oldOcc = $this->old->bounded() ? $this->old->all() : $this->old->occurrences($horizon);
        if (!$newRule && !$this->old->bounded() && $this->r0->supported()) {
            // Sparse losses: keep walking until an image turns up.
            $extra = $horizon;
            $step = $span * 4;
            while (count(array_filter(array_map(fn(int $d): ?int => $image($d, $this->r0->memberOf($d)), $oldOcc), static fn($x): bool => $x !== null)) === 0 && $extra < $horizon + 400 * 366) {
                $extra += $step;
                $step *= 2;
                $oldOcc = $this->old->occurrences($extra);
            }
        }
        $images = [];
        foreach ($oldOcc as $dn) {
            $images[$dn] = $image($dn, $this->r0->supported() ? $this->r0->memberOf($dn) : null);
        }
        if (!$newRule) {
            $images[$this->actedDn0] = $landedDn;
        }
        $imageSet = array_filter($images, static fn($x): bool => $x !== null);
        if (isset($reading['start'])) {
            $newStart = (int) $reading['start'];
        } elseif ($imageSet === []) {
            return null;
        } else {
            $newStart = min($imageSet);
        }
        if ($identity && $timeSame && !$this->sentBound) {
            $newStart = $this->old->startDn;
        }
        $calcP = new Calc($r1p, $newStart, $this->tz1, $this->allDay1, $clock1, $this->len1);
        $latest = $imageSet === [] ? null : max($imageSet);

        // Gains: occurrences of the new rule that are no old one's image.
        $edgeGains = [];
        $gains = [];
        if (!$newRule && $imageSet !== []) {
            $to = (int) $latest;
            $periodsWithImage = [];
            foreach ($imageSet as $d) {
                $periodsWithImage[$r1p->periodOf($d)] = true;
            }
            $inImages = array_flip(array_values($imageSet));
            foreach ($calcP->occurrences($to) as $d) {
                if (!isset($inImages[$d])) {
                    if ($r1p->supported() && isset($periodsWithImage[$r1p->periodOf($d)])) {
                        $edgeGains[] = $d;
                    } else {
                        $gains[] = $d;
                    }
                }
            }
        }
        $genSkips = [];
        if ($edgeGains !== [] && !$this->google) {
            $second = array_values(array_unique($imageSet));
            sort($second);
            $atStart = $edgeGains[0] < ($second[1] ?? PHP_INT_MAX);
            $gainWords = Words::andList(array_map(fn(int $d): string => Words::date($d, $this->thisYear), $edgeGains));
            // Leaving a gained day out keeps every old event's image with
            // nothing else lost, so it comes first (fourth review).
            $edge = $this->pick('edge', $atStart ? 'First period' : 'Last period', [
                ['id' => 'without', 'label' => 'Don\'t add ' . $gainWords],
                ['id' => 'add', 'label' => 'Add ' . $gainWords],
            ], 'without');
            if ($edge === 'without') {
                $genSkips = $edgeGains;
            }
        }

        // The bound.
        $boundWords = '';
        $boundKind = 'none';
        $separate = false;
        $r1 = $r1p;
        $oldCount = $this->r0->count;
        if ($newRule) {
            $nr = $reading['newRule'];
            $r1 = $r1p->withBound($nr->count, $nr->until);
        } elseif ($this->sentBound) {
            $r1 = $r1p->withBound($this->sentRule->count, $this->sentRule->until);
        } elseif ($identity && $timeSame) {
            $r1 = clone $this->r0;
        } elseif ($this->r0->count !== null || $this->r0->until !== null) {
            $toLatest = $calcP->occurrences((int) $latest);
            $endCount = count($toLatest);
            $wantN = $this->r0->count ?? count($oldOcc);
            // Keeping N: N shown, the generated skips not counted.
            $rawN = 0;
            $shown = 0;
            $lastUnderCount = null;
            foreach ($calcP->occurrences(PHP_INT_MAX, $wantN + count($genSkips)) as $d) {
                $rawN++;
                if (!in_array($d, $genSkips, true)) {
                    $shown++;
                }
                $lastUnderCount = $d;
                if ($shown >= $wantN) {
                    break;
                }
            }
            $untilAt = fn(int $d): string => $this->allDay1 ? str_replace('-', '', Cal::date($d)) : Calc::at($d, $clock1, $this->tz1)->format('Ymd\THis\Z');
            if ($rawN === $endCount) {
                $r1 = $this->r0->count !== null ? $r1p->withBound($rawN, null) : $r1p->withBound(null, $untilAt((int) $latest));
            } else {
                $splits = $landedDn > (int) $lastUnderCount && !$identity;
                $countLabel = 'Keep ' . Words::events($wantN) . ($splits ? ' + this one on its own' : '');
                $default = $this->r0->count !== null && !$splits ? 'count' : 'end';
                $boundOptions = [['id' => 'count', 'label' => $countLabel], ['id' => 'end', 'label' => 'Keep the end']];
                if ($default === 'end') {
                    $boundOptions = array_reverse($boundOptions);
                }
                $bound = $this->pick('bound', 'Bound', $boundOptions, $default);
                if ($bound === 'count') {
                    $r1 = $r1p->withBound($rawN, null);
                    $separate = $splits;
                } else {
                    $r1 = $this->r0->count !== null ? $r1p->withBound($endCount, null) : $r1p->withBound(null, $untilAt((int) $latest));
                }
            }
        }
        $calc1 = new Calc($r1, $newStart, $this->tz1, $this->allDay1, $clock1, $this->len1);
        $all1 = $calc1->bounded() ? $calc1->all() : null;
        $untilAt1 = $calc1->untilInstant();
        // An end before the start leaves nothing (the expander would still show the start).
        if (($all1 !== null && count(array_diff($all1, $genSkips)) === 0) || ($untilAt1 !== null && $untilAt1 < $calc1->instant($newStart))) {
            return null;
        }
        if (!$newRule && !$identity && !$calc1->isOccurrence($landedDn)) {
            $separate = true;
        }
        $last1 = $calc1->last();
        $inside = fn(?int $d): bool => $d !== null && $calc1->isOccurrence($d);
        // A generated skip past the new end needs nothing (Bounds, gains at the edges).
        $genSkips = array_values(array_filter($genSkips, $inside));

        // Skipped days.
        $newExdates = [];
        $days = [];
        if (!$this->google) {
            foreach ($this->exdates as $key) {
                $odn = $this->old->dnOf($key);
                $img = $newRule ? $image($odn) : $image($odn, $this->r0->supported() ? $this->r0->memberOf($odn) : null);
                if ($inside($img)) {
                    $nk = $calc1->key((int) $img);
                    $newExdates[] = $nk;
                    $days[] = ['key' => $key, 'newKey' => $nk, 'kind' => 'skipped', 'outcome' => $nk === $key ? 'stays' : 'moves',
                        'words' => 'Skipped ' . Words::dayMonth($odn, $this->thisYear) . ($img === $odn ? ' stays skipped' : ' moves to ' . Words::dayMonth((int) $img, $this->thisYear))];
                } else {
                    $days[] = ['key' => $key, 'newKey' => null, 'kind' => 'skipped', 'outcome' => 'dropped',
                        'words' => 'Skipped ' . Words::dayMonth($odn, $this->thisYear) . ' is forgotten' . ($img === null ? ': the new series has no ' . Words::dayMonth($odn, $this->thisYear) : ': it falls outside the series')];
                }
            }
            foreach ($genSkips as $g) {
                $nk = $calc1->key($g);
                $newExdates[] = $nk;
                $days[] = ['key' => null, 'newKey' => $nk, 'kind' => 'skipped', 'outcome' => 'generated',
                    'words' => Words::date($g, $this->thisYear) . ' skipped, so the series ' . ($g < (int) $latest ? 'starts as before' : 'ends as before')];
            }
        }
        $newExdates = array_values(array_unique($newExdates));
        sort($newExdates);

        // Edited days: inside, outside, or the day acted on.
        $placeRows = [];
        $outsideRows = [];
        $actedRowId = null;
        if (!$this->google) {
            foreach ($this->rows as $id => $row) {
                $key = (string) $row['recurrence_instance_utc'];
                $odn = $this->old->dnOf($key);
                if (!$newRule && $key === $this->acted) {
                    $actedRowId = $id;
                    if (!$separate) {
                        $placeRows[$id] = $landedDn;
                    }
                    continue;
                }
                $img = $newRule ? $image($odn) : $image($odn, $this->r0->supported() ? $this->r0->memberOf($odn) : null);
                if ($inside($img)) {
                    $placeRows[$id] = (int) $img;
                } else {
                    $outsideRows[$id] = $img;
                }
            }
        }
        $outside = 'keep';
        if ($outsideRows !== []) {
            $one = count($outsideRows) === 1;
            $outside = $this->pick('outside', 'Edited days outside the new series', [
                ['id' => 'keep', 'label' => $one ? 'Keep it as an event of its own' : 'Keep them as events of their own'],
                ['id' => 'delete', 'label' => $one ? 'Delete it' : 'Delete them'],
            ], 'keep', $one ? ['day' => str_replace(' ', 'T', (string) $this->rows[array_key_first($outsideRows)]['recurrence_instance_utc']) . 'Z'] : []);
        }
        $movedRows = [];
        foreach ($placeRows as $id => $img) {
            $own = $this->ownOf($this->rows[$id]);
            if ($id !== $actedRowId && in_array('date', $own, true) && $img !== $this->old->dnOf((string) $this->rows[$id]['recurrence_instance_utc'])) {
                $movedRows[] = $id;
            }
        }
        $moveOwnDates = false;
        if ($movedRows !== []) {
            $one = count($movedRows) === 1 ? $this->rows[$movedRows[0]] : null;
            $shownDn = $one !== null ? $this->shownDn($one) : 0;
            $toDn = $one !== null ? $shownDn + $placeRows[$movedRows[0]] - $this->old->dnOf((string) $one['recurrence_instance_utc']) : 0;
            $moveOwnDates = $this->pick('movedDays', 'Days moved on their own', [
                ['id' => 'keep', 'label' => $one !== null ? 'Keep ' . Words::date($shownDn, $this->thisYear) : 'Keep their dates'],
                ['id' => 'move', 'label' => $one !== null ? 'Move to ' . Words::date($toDn, $this->thisYear) : 'Move them with their events'],
            ], 'keep', $one !== null ? ['day' => str_replace(' ', 'T', (string) $one['recurrence_instance_utc']) . 'Z'] : []) === 'move';
        }

        // Content fields: inherited ones follow; own ones are a choice.
        $give = [];
        foreach ($this->changed as $field => $cols) {
            if ($this->google) {
                break;
            }
            $owners = array_values(array_filter(array_keys($placeRows), fn(int $id): bool => $id !== $actedRowId && in_array($field, $this->ownOf($this->rows[$id]), true)));
            if ($owners === []) {
                continue;
            }
            $word = self::OWN_WORDS[$field];
            $one = count($owners) === 1;
            $give[$field] = $this->pick('own' . ucfirst($field), ($one ? '1 edited day keeps its own ' : count($owners) . ' edited days keep their own ') . $word, [
                ['id' => 'keep', 'label' => $one ? 'Keep its own ' . $word : 'Keep their own ' . $word],
                ['id' => 'give', 'label' => $one ? 'Give it the new ' . $word . ' too' : 'Give them the new ' . $word . ' too'],
            ], 'keep') === 'give';
        }

        // The writes.
        $masterCols = [];
        $newRruleText = $this->kind === 'norule' ? null : $r1->text();
        $start1 = $calc1->instant($newStart);
        $end1 = $this->endFrom($start1, $this->allDay1, $this->len1, $this->tz1);
        $set = function (string $col, mixed $value) use (&$masterCols): void {
            if ((string) ($this->master[$col] ?? '') !== (string) ($value ?? '') || ($value === null) !== ($this->master[$col] === null)) {
                $masterCols[$col] = $value;
            }
        };
        if (!($identity && $timeSame && !$this->sentBound)) {
            $set('rrule', $newRruleText);
            $set('start_utc', Time::toDb($start1));
            $set('end_utc', Time::toDb($end1));
            $set('all_day', $this->allDay1 ? 1 : 0);
            $set('tzid', $this->allDay1 ? 'UTC' : $this->tz1);
            if (!$this->google) {
                $set('exdates_json', $newExdates === [] ? null : json_encode($newExdates));
            }
        } elseif ($this->kind === 'time' && $this->len1 !== $this->len0) {
            $set('end_utc', Time::toDb($this->endFrom(Time::fromDb((string) $this->master['start_utc']), $this->allDay1, $this->len1, $this->tz1)));
        }
        if ($this->kind === 'norule') {
            $masterCols['rrule'] = null;
            $masterCols['exdates_json'] = null;
        }
        foreach ($this->changed as $field => $cols) {
            if ($field === 'tags' || $field === 'people') {
                continue;
            }
            foreach ($cols as $col => $v) {
                $masterCols[$col] = $v;
            }
        }
        if (isset($this->edit['calendarId'])) {
            $masterCols['calendar_id'] = (int) $this->edit['calendarId'];
        }

        $overrideWrites = [];
        $detach = [];
        $delete = [];
        $create = [];
        $tags = isset($this->changed['tags']) ? ['master' => $this->changed['tags']['names']] : [];
        $people = isset($this->changed['people']) ? ['master' => $this->changed['people']['ids']] : [];
        $title = (string) ($this->master['title'] ?? '');
        foreach ($placeRows as $id => $img) {
            $row = $this->rows[$id];
            $own = $this->ownOf($row);
            // The day acted on moves the way the series does: its own date by
            // the drag's days (where it shows, so it lands where dropped).
            [$start, $end, $allDay, $tz] = $this->place($row, $own, (int) $img, $calc1, $clock1, $moveOwnDates || $id === $actedRowId);
            $cols = [];
            $put = function (string $col, mixed $value) use (&$cols, $row): void {
                if ((string) ($row[$col] ?? '') !== (string) ($value ?? '')) {
                    $cols[$col] = $value;
                }
            };
            $put('recurrence_instance_utc', $calc1->key((int) $img));
            $put('start_utc', Time::toDb($start));
            $put('end_utc', Time::toDb($end));
            $put('all_day', $allDay ? 1 : 0);
            $put('tzid', $tz);
            $ownAfter = $own;
            foreach ($this->changed as $field => $fcols) {
                $inherits = !in_array($field, $own, true) || !empty($give[$field]);
                if (!$inherits) {
                    continue;
                }
                $ownAfter = array_values(array_diff($ownAfter, [$field]));
                if ($field === 'tags') {
                    $tags[$id] = $fcols['names'];
                } elseif ($field === 'people') {
                    $people[$id] = $fcols['ids'];
                } else {
                    foreach ($fcols as $col => $v) {
                        $put($col, $v);
                    }
                }
            }
            $encoded = OwnFields::encode($ownAfter);
            if ((string) ($row['override_fields'] ?? '') !== $encoded) {
                $cols['override_fields'] = $encoded;
            }
            if (isset($masterCols['calendar_id'])) {
                $cols['calendar_id'] = $masterCols['calendar_id'];
            }
            if ($cols !== []) {
                $overrideWrites[$id] = $cols;
            }
            $odn = $this->old->dnOf((string) $row['recurrence_instance_utc']);
            $moved = Time::toDb($start) !== (string) $row['start_utc'] || Time::toDb($end) !== (string) $row['end_utc'];
            $name = in_array('title', $own, true) ? "'" . (string) $row['title'] . "' " : '';
            $keeps = array_values(array_intersect(['title', 'location', 'description', 'url', 'icon', 'tags', 'people', 'reminders', 'status', 'attendance'], $ownAfter));
            $keepsWords = $keeps === [] ? '' : ', keeps its ' . Words::andList(array_map(static fn(string $f): string => self::OWN_WORDS[$f], $keeps));
            $wasShown = $this->shownDn($row);
            if (in_array('date', $own, true) && $wasShown !== $odn) {
                $words = Words::date($odn, $this->thisYear) . ', moved to ' . Words::date($wasShown, $this->thisYear) . ': ' . ($moveOwnDates ? 'moves to ' . Words::date(Cal::dnOf($start->setTimezone(Time::zone($allDay ? 'UTC' : $tz))), $this->thisYear) : 'stays there');
            } else {
                $words = $name . Words::date($odn, $this->thisYear) . ($moved ? ' moves to ' . Words::date((int) $img, $this->thisYear) : ' stays') . $keepsWords;
            }
            $days[] = ['key' => (string) $row['recurrence_instance_utc'], 'newKey' => $calc1->key((int) $img), 'kind' => 'edited',
                'title' => (string) $row['title'], 'outcome' => $moved ? 'moves' : 'stays', 'words' => $words];
        }
        foreach ($outsideRows as $id => $img) {
            $row = $this->rows[$id];
            $odn = $this->old->dnOf((string) $row['recurrence_instance_utc']);
            $label = "'" . (string) $row['title'] . "' " . Words::date($this->shownDn($row), $this->thisYear);
            if ($outside === 'delete') {
                $delete[] = $id;
                $days[] = ['key' => (string) $row['recurrence_instance_utc'], 'newKey' => null, 'kind' => 'edited', 'title' => (string) $row['title'], 'outcome' => 'deleted', 'words' => $label . ' is deleted'];
            } else {
                $detach[$id] = [];
                $days[] = ['key' => (string) $row['recurrence_instance_utc'], 'newKey' => null, 'kind' => 'edited', 'title' => (string) $row['title'], 'outcome' => 'separate', 'words' => $label . ' becomes an event of its own'];
            }
            unset($odn, $img);
        }
        if ($separate && !$newRule && !$identity) {
            $landedStart = Calc::at($landedDn, $clock1, $this->allDay1 ? 'UTC' : $this->tz1);
            $landedEnd = $this->endFrom($landedStart, $this->allDay1, $this->len1, $this->tz1);
            $placed = ['start_utc' => Time::toDb($landedStart), 'end_utc' => Time::toDb($landedEnd), 'all_day' => $this->allDay1 ? 1 : 0, 'tzid' => $this->allDay1 ? 'UTC' : $this->tz1];
            if ($actedRowId !== null) {
                $detach[$actedRowId] = $placed;
            } else {
                $create[] = $placed;
            }
            $days[] = ['key' => $this->acted, 'newKey' => null, 'kind' => $actedRowId !== null ? 'edited' : 'acted', 'outcome' => 'separate',
                'words' => 'This one, ' . Words::date($landedDn, $this->thisYear) . ', becomes an event of its own'];
        }

        // Words and the rest of the plan.
        $wordsBefore = Words::rule($this->r0, $this->allDay0 ? null : $this->clock0);
        $words = $this->kind === 'norule' ? 'Does not repeat' : Words::rule($r1p, $this->allDay1 ? null : $clock1);
        $walkCalc = $calc1;
        $walk = $this->walk($walkCalc, $genSkips);
        $boundKey = [$r1->count, $r1->until];
        [$boundKind, $boundWords] = $this->boundWords($r1, $calc1, $oldOcc, $separate, $outside === 'keep' ? count($outsideRows) : 0);
        if ($all1 !== null && $newExdates !== []) {
            // Skipped days count toward COUNT (RFC 5545 3.8.5.3): the line says how many show.
            $skippedIn = count(array_intersect(array_map(static fn(int $d): string => $calc1->key($d), $all1), $newExdates));
            if ($skippedIn > 0) {
                $boundWords .= '; ' . (count($all1) - $skippedIn) . ' scheduled occurrence' . (count($all1) - $skippedIn === 1 ? '' : 's') . ', ' . $skippedIn . ' skipped';
            }
        }
        $losses = [];
        foreach ($images as $dn => $img) {
            if ($img === null || !$calc1->isOccurrence($img)) {
                if (!$newRule && $dn !== $this->actedDn0) {
                    $losses[] = ['date' => Cal::date($dn), 'words' => Words::dayMonth($dn, $this->thisYear) . ($img === null ? ' has no place in the new series' : ' falls after the new end')];
                }
            }
        }
        $gainList = array_map(fn(int $d): array => ['date' => Cal::date($d), 'words' => 'Adds ' . Words::dayMonth($d, $this->thisYear)], array_values(array_diff(array_merge($gains, $genSkips === [] ? $edgeGains : []), [])));
        $next = $this->next($calc1, $newExdates, $placeRows, $overrideWrites, $landedDn, $separate);
        $nextDates = array_map(static fn(array $n): string => $n['start'], $next);
        if ($this->google) {
            $this->notes[] = ['kind' => 'google', 'words' => self::GOOGLE_NOTE];
        }
        $this->clockNotes($calc1, $landedDn);
        $fieldsWords = [];
        foreach ($this->changed as $field => $cols) {
            $fieldsWords[] = ['field' => $field, 'words' => $this->fieldWords($field, $cols)];
        }
        $groupsOut = $this->groups;
        $direct = $this->kind === 'fields' && $groupsOut === [] && $this->refusals === [] && !isset($this->edit['calendarId']);
        $verb = match ($this->kind) {
            'drag' => 'Moved', 'newrule', 'norule' => 'Changed how ' . $title . ' repeats', default => 'Changed',
        };
        $summary = ($this->kind === 'newrule' || $this->kind === 'norule' ? $verb : $verb . ' ' . $title) . ' (all events): '
            . (in_array($this->kind, ['drag', 'newrule', 'norule'], true) || $words !== $wordsBefore ? Words::lower($wordsBefore) . ', now ' . Words::lower($words) . ', from ' . Words::dayMonth($newStart, $this->thisYear) : Words::lower($words) . ', as before')
            . '.';
        $detachCount = count(array_filter($days, static fn(array $d): bool => $d['outcome'] === 'separate' && $d['kind'] === 'edited'));
        $ownership = null;
        if ($detachCount > 0) {
            $names = array_values(array_map(fn(array $d): string => "'" . ($d['title'] ?? '') . "' on " . Words::dayMonth($this->old->dnOf(str_replace(['T', 'Z'], [' ', ''], (string) $d['key'])), $this->thisYear),
                array_filter($days, static fn(array $d): bool => $d['outcome'] === 'separate' && $d['kind'] === 'edited')));
            $summary .= ' ' . ($detachCount === 1 ? 'One edited day became an event of its own: ' : Words::count($detachCount) . ' edited days became events of their own: ') . Words::andList($names) . '.';
            // The dialog's sentence (series-model.md, The Plan, line 7).
            $ownership = ($detachCount === 1 ? 'One edited day becomes an event of its own: ' : Words::count($detachCount) . ' edited days become events of their own: ') . Words::andList($names) . '.';
        }

        // Keys in the plan read as instants: 2027-04-14T02:00:00Z.
        $iso = static fn(?string $k): ?string => $k === null ? null : str_replace(' ', 'T', $k) . 'Z';
        $days = array_map(static fn(array $d): array => ['key' => $iso($d['key']), 'newKey' => $iso($d['newKey'])] + $d, $days);
        $selected = [];
        foreach ($groupsOut as $g) {
            $selected[$g['id']] = $g['selected'];
        }
        $plan = [
            'scope' => 'all',
            'direct' => $direct,
            'rule' => ['before' => (string) $this->master['rrule'], 'after' => $newRruleText ?? '', 'wordsBefore' => $wordsBefore, 'words' => $words],
            'start' => Time::iso($start1->setTimezone(Time::zone($this->allDay1 ? 'UTC' : $this->tz1))),
            'bound' => ['kind' => $boundKind, 'words' => $boundWords] + ($r1->count !== null ? ['count' => $r1->count] : []) + ($r1->until !== null ? ['until' => $r1->until] : []),
            'next' => $next,
            'groups' => $groupsOut,
            'days' => $days,
            'losses' => $losses,
            'gains' => $gainList,
            'refusals' => $this->refusals,
            'notes' => $this->notes,
            'fields' => $fieldsWords,
            'summary' => $summary,
        ] + ($ownership !== null ? ['ownership' => $ownership] : []);
        return [
            'plan' => $plan,
            'writes' => [
                'master' => $masterCols,
                'overrides' => $overrideWrites,
                'detach' => $detach,
                'delete' => $delete,
                'create' => $create,
                'tags' => $tags,
                'people' => $people,
                'exdates' => $newExdates,
            ],
            'selected' => $selected,
            'nextDates' => $nextDates,
            'walk' => $walk,
            'boundKey' => $boundKey,
        ];
    }

    /** The occurrences a plan shows, for comparing readings past the bound (the walk). @return list<int> */
    private function walk(Calc $calc, array $skips): array
    {
        $r = $calc->rule;
        if (!$r->supported()) {
            return [null, array_slice($calc->occurrences(PHP_INT_MAX, 60), 0, 60)];
        }
        $unbounded = $calc->withoutBound();
        $periods = match ($r->kind) {
            'monthly' => intdiv(4800, self::gcd($r->interval, 4800)),
            'yearly' => intdiv(400, self::gcd($r->interval, 400)),
            default => 8,
        };
        $out = [];
        $p = $r->periodOf($calc->startDn);
        for ($i = 0; $i < $periods && $p <= ($r->kind === 'yearly' ? 9999 : PHP_INT_MAX); $i++) {
            foreach ($r->periodDays($p) as [$d]) {
                if ($d >= $calc->startDn && !in_array($d, $skips, true)) {
                    $out[] = $d;
                }
            }
            $p = $r->periodPlus($p, $r->interval);
        }
        unset($unbounded);
        return [$calc->bounded() ? array_values(array_diff($calc->all(), $skips)) : null, $out];
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return max(1, $a);
    }

    /** @return array{0:string,1:string} the bound's kind and words */
    private function boundWords(Rule $r1, Calc $calc1, array $oldOcc, bool $separate, int $kept): array
    {
        $extra = ($separate ? 1 : 0) + $kept;
        $plus = $extra > 0 ? ' (' . $r1->count . ' in the series + ' . $extra . ' separate)' : '';
        if ($r1->count === null && $r1->until === null) {
            return ['none', $this->r0->count === null && $this->r0->until === null ? 'No end, as before' : 'No end'];
        }
        $last = $calc1->last();
        $was = $this->old->bounded() ? $this->old->last() : null;
        $n = count($calc1->all());
        $ends = $last !== null ? 'ends ' . Words::dayMonth($last, $this->thisYear) : '';
        $wasWords = $was !== null && $last !== null && $was !== $last ? ' (was ' . Words::dayMonth($was, $this->thisYear) . ')' : '';
        if ($r1->count !== null) {
            $oldN = $this->r0->count ?? count($oldOcc);
            $plus = $extra > 0 ? '; ' . $r1->count . ' in the series + ' . $extra . ' separate' : '';
            if ($this->r0->count !== null && $oldN !== $r1->count) {
                return ['count', 'Changes ' . Words::events($oldN) . ' to ' . $r1->count . ', ' . $ends . $plus];
            }
            return ['count', Words::events($r1->count) . ', ' . $ends . $wasWords . $plus];
        }
        $oldN = count($oldOcc);
        $why = '';
        if ($wasWords !== '' && $was !== null) {
            $wasImage = null;
            $why = '';
        }
        unset($wasImage);
        return ['until', Words::upper($ends) . $wasWords . ($n !== $oldN && $this->r0->until !== null ? '; ' . Words::events($n) . ($oldN !== $n ? ' (was ' . $oldN . ')' : '') : '') . $why . ($extra > 0 ? '; ' . $extra . ' separate' : '')];
    }

    /** The next four events as they will show, from the day acted on. */
    private function next(Calc $calc1, array $exdates, array $placeRows, array $overrideWrites, int $fromDn, bool $separate): array
    {
        $skip = array_flip($exdates);
        $byKey = [];
        foreach ($placeRows as $id => $img) {
            $row = $this->rows[$id];
            $start = $overrideWrites[$id]['start_utc'] ?? $row['start_utc'];
            $tz = (int) ($overrideWrites[$id]['all_day'] ?? $row['all_day']) === 1 ? 'UTC' : (string) ($overrideWrites[$id]['tzid'] ?? $row['tzid']);
            $own = $this->ownOf($row);
            $byKey[$calc1->key($img)] = ['start' => Time::dbToIso((string) $start, $tz), 'kind' => 'edited']
                + (in_array('date', $own, true) && $img !== $fromDn ? ['words' => 'its own date'] : []);
        }
        $out = [];
        $span = 400;
        for ($tries = 0; $tries < 6 && count($out) < 4; $tries++) {
            $out = [];
            foreach ($calc1->occurrences($fromDn + $span) as $d) {
                if ($d < $fromDn) {
                    continue;
                }
                $key = $calc1->key($d);
                if (isset($skip[$key])) {
                    continue;
                }
                $tz = $this->allDay1 ? 'UTC' : $this->tz1;
                $item = $byKey[$key] ?? ['start' => Time::iso($calc1->instant($d)->setTimezone(Time::zone($tz))), 'kind' => 'occurrence'];
                if ($d === $fromDn && $item['kind'] === 'occurrence') {
                    $item['kind'] = 'acted';
                }
                $out[] = $item;
                if (count($out) >= 8) {
                    break;
                }
            }
            $span *= 4;
            if ($calc1->bounded()) {
                break;
            }
        }
        if ($separate && $this->kind === 'drag') {
            $out[] = ['start' => Time::iso(Calc::at($fromDn, $calc1->clock, $this->allDay1 ? 'UTC' : $this->tz1)->setTimezone(Time::zone($this->allDay1 ? 'UTC' : $this->tz1))), 'kind' => 'acted', 'words' => 'on its own'];
        }
        usort($out, static fn(array $a, array $b): int => strcmp((string) Time::parseIso($a['start'])->format('U'), (string) Time::parseIso($b['start'])->format('U')) ?: 0);
        usort($out, static fn(array $a, array $b): int => Time::parseIso($a['start'])->getTimestamp() <=> Time::parseIso($b['start'])->getTimestamp());
        return array_slice($out, 0, 4);
    }

    /** A clock time missing or doubled on a day the plan shows, or within a year (Zones, DST and All-Day Days). */
    private function clockNotes(Calc $calc, int $fromDn): void
    {
        if ($this->allDay1 || $calc->tz === 'UTC') {
            return;
        }
        $tz = Time::zone($calc->tz);
        foreach ($calc->occurrences($fromDn + 366, 400) as $d) {
            if ($d < $fromDn) {
                continue;
            }
            $wall = sprintf('%s %02d:%02d:%02d', Cal::date($d), ...$calc->clock);
            $at = new \DateTimeImmutable($wall, $tz);
            if ($at->format('Y-m-d H:i:s') !== $wall) {
                $this->notes[] = ['kind' => 'gap', 'words' => sprintf('No %02d:%02d on %s in %s: that one is at %s', $calc->clock[0], $calc->clock[1], Words::dayMonth($d, $this->thisYear), self::zoneName($calc->tz), $at->format('H:i'))];
                return;
            }
            $later = $at->modify('+1 hour');
            if ($later->getOffset() !== $at->getOffset() && $later->modify('-1 hour')->format('H:i') === $at->format('H:i') && (new \DateTimeImmutable('@' . ($at->getTimestamp() + 3600)))->setTimezone($tz)->format('H:i:s') === $at->format('H:i:s')) {
                $this->notes[] = ['kind' => 'overlap', 'words' => sprintf('%02d:%02d happens twice on %s: this is the first, in daylight time', $calc->clock[0], $calc->clock[1], Words::dayMonth($d, $this->thisYear))];
                return;
            }
        }
    }

    private static function zoneName(string $tz): string
    {
        $parts = explode('/', $tz);
        return str_replace('_', ' ', (string) end($parts));
    }

    private function fieldWords(string $field, array $cols): string
    {
        $name = Words::upper(self::OWN_WORDS[$field]);
        if ($field === 'tags') {
            return 'Tags: ' . implode(', ', $cols['names']);
        }
        if ($field === 'people') {
            return 'People changed';
        }
        $col = array_key_first($cols);
        $was = (string) ($this->master[$col] ?? '');
        $now = (string) ($cols[$col] ?? '');
        if ($field === 'description' || $field === 'reminders') {
            return $name . ' changed';
        }
        return $name . ': ' . ($was === '' ? 'none' : $was) . ', now ' . ($now === '' ? 'none' : $now);
    }

    /** An edited row's own fields: as stored, or by equality when it has no list yet. @return list<string> */
    private function ownOf(array $row): array
    {
        $known = OwnFields::decode($row['override_fields'] ?? null);
        if ($known !== null) {
            return $known;
        }
        $id = (int) $row['id'];
        return OwnFields::infer($row, $this->master, $this->opts['rowTags'][$id] ?? null, $this->opts['masterTags'] ?? null, $this->opts['rowPeople'][$id] ?? null, $this->opts['masterPeople'] ?? null);
    }

    /** The local day an edited row shows on. */
    private function shownDn(array $row): int
    {
        $tz = (int) $row['all_day'] === 1 ? 'UTC' : (string) $row['tzid'];
        return Cal::dnOf(Time::fromDb((string) $row['start_utc'])->setTimezone(Time::zone($tz)));
    }

    /**
     * Where an edited row shows after the edit: its own date, time, length
     * and all-day flag kept, the rest taken from the series' new values.
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable,2:bool,3:string}
     */
    private function place(array $row, array $own, int $img, Calc $calc1, array $clock1, bool $moveOwnDates): array
    {
        $rowAllDay = (int) $row['all_day'] === 1;
        $rowTz = $rowAllDay ? 'UTC' : (string) $row['tzid'];
        $shown = Time::fromDb((string) $row['start_utc'])->setTimezone(Time::zone($rowTz));
        $shownDn = Cal::dnOf($shown);
        $odn = $this->old->dnOf((string) $row['recurrence_instance_utc']);
        $date = in_array('date', $own, true) ? ($moveOwnDates ? $shownDn + ($img - $odn) : $shownDn) : $img;
        $allDay = in_array('allDay', $own, true) ? $rowAllDay : $this->allDay1;
        $rowLen = max(0, Time::fromDb((string) $row['end_utc'])->getTimestamp() - Time::fromDb((string) $row['start_utc'])->getTimestamp());
        if ($allDay) {
            $start = Calc::at($date, [0, 0, 0], 'UTC');
            $len = in_array('length', $own, true) || !$this->allDay1 ? $rowLen : $this->len1;
            if (!$rowAllDay && !$this->allDay1) {
                $len = 86400;
            }
            return [$start, $this->endFrom($start, true, $len, 'UTC'), true, 'UTC'];
        }
        if (in_array('time', $own, true) && !$rowAllDay) {
            $tz = (string) $row['tzid'];
            $clock = [(int) $shown->format('G'), (int) $shown->format('i'), (int) $shown->format('s')];
        } else {
            $tz = $this->allDay1 ? (string) $row['tzid'] : $this->tz1;
            $clock = $this->allDay1 ? [(int) $shown->format('G'), (int) $shown->format('i'), (int) $shown->format('s')] : $clock1;
        }
        $len = in_array('length', $own, true) || $this->allDay1 ? $rowLen : $this->len1;
        if ($rowAllDay && !in_array('length', $own, true)) {
            $len = $this->allDay1 ? $rowLen : $this->len1;
        }
        $start = Calc::at($date, $clock, $tz);
        return [$start, $this->endFrom($start, false, $len, $tz), false, $tz];
    }

    private function endFrom(\DateTimeImmutable $start, bool $allDay, int $len, string $tz): \DateTimeImmutable
    {
        if ($allDay) {
            $days = max(1, (int) round($len / 86400));
            return $start->setTimezone(Time::utc())->modify('+' . $days . ' days');
        }
        return $start->modify('+' . max(0, $len) . ' seconds');
    }

    private function sameBound(Rule $sent): bool
    {
        if ($sent->count !== null || $this->r0->count !== null) {
            return $sent->count === $this->r0->count && $sent->until === null && $this->r0->until === null;
        }
        if ($sent->until === null || $this->r0->until === null) {
            return $sent->until === $this->r0->until;
        }
        $a = new Calc($sent, $this->old->startDn, $this->tz0, $this->allDay0, $this->clock0, $this->len0);
        return $a->last() === $this->old->last();
    }

    /** @param list<mixed> $items */
    private static function set(array $items): string
    {
        $items = array_map('strval', $items);
        sort($items);
        return json_encode(array_values(array_unique($items)));
    }
}
