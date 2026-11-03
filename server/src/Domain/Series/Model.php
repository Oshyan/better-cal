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
 *  - columns: the content columns the edit sets (title, location..., calendar_id), already normalized,
 *    and the series columns (SERIES_COLUMNS: the trip flag), which no edited day owns
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

    /** Columns of the series that no edited day owns: every row takes them (the trip flag). */
    public const SERIES_COLUMNS = ['is_container' => 'Trip'];

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
    /** The rule as stored (r0 drops the stored day of an anchored start). */
    private Rule $rule0;
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
    /** The days the occurrence acted on moved, before any carry of its clock change past midnight. */
    private int $dayDelta = 0;
    /** The occurrence acted on's clock change, in seconds (Changing the Series from an Edited Day, 1). */
    private int $clockDelta = 0;
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
    /** @var array<string,mixed> series columns the edit changes (SERIES_COLUMNS) */
    private array $seriesCols = [];
    private bool $readable;
    /** The stored start is a skipped day before a start in a skipped hour (Zones, DST and All-Day Days). */
    private bool $anchored = false;
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
        $this->rule0 = $this->r0;
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
        // A series stored from the period before a start in a skipped hour
        // (Zones, DST and All-Day Days): that skipped day holds the clock
        // and isn't one of its days. The series is read from its first
        // shown day, its COUNT without that day.
        if (!$this->allDay0 && $this->readable && $this->r0->supported() && in_array((string) $master['start_utc'], $this->exdates, true)) {
            $two = $this->old->occurrences(PHP_INT_MAX, 2);
            if (count($two) === 2 && self::inGap($two[1], $this->clock0, $this->tz0)) {
                $this->anchored = true;
                $this->exdates = array_values(array_diff($this->exdates, [(string) $master['start_utc']]));
                if ($this->r0->count !== null) {
                    $this->r0 = $this->r0->withBound($this->r0->count - 1, null);
                }
                $startDn = $two[1];
                $this->old = new Calc($this->r0, $startDn, $this->tz0, $this->allDay0, $this->clock0, $this->len0);
            }
        }

        $this->acted = (string) ($edit['acted'] ?? $this->old->key($this->old->startDn));
        $this->actedDn0 = $this->old->dnOf($this->acted);
        // The edit is read as deltas, each measured against the occurrence
        // acted on (its key, its clock there, the series' zone, length and
        // all-day flag; an edit from an edited day arrives translated to it,
        // Changing the Series from an Edited Day): the days it moved, its
        // clock change, its zone, its length change, its all-day change.
        // The series takes each delta: its clock moves by the clock change,
        // which for a later time of a rule several times a day or a day after
        // a skipped hour is not the landed clock. An edit with every delta
        // zero moves nothing, so every key, shown time and skip stays.
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
            $this->dayDelta = Cal::dnOf($at) - $this->actedDn0;
            $this->len1 = max(0, Time::fromDb((string) $landed['end_utc'])->getTimestamp() - Time::fromDb((string) $landed['start_utc'])->getTimestamp());
            $secs = static fn(array $c): int => $c[0] * 3600 + $c[1] * 60 + $c[2];
            $actedClock = $this->allDay0 ? [0, 0, 0] : self::clockOf(Time::fromDb($this->acted)->setTimezone(Time::zone($this->tz0)));
            $this->clockDelta = $this->allDay1 ? 0 : $secs(self::clockOf($at)) - $secs($actedClock);
            $sec = $this->allDay1 ? 0 : $secs($this->allDay0 ? [0, 0, 0] : $this->clock0) + $this->clockDelta;
            $carry = intdiv($sec - ((($sec % 86400) + 86400) % 86400), 86400);
            $sec -= $carry * 86400;
            $this->clock1 = [intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60];
            $this->landedDn = $this->actedDn0 + $this->dayDelta + $carry;
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
            // A series column is the value of every row, the master and each
            // edited day: the edit changes it when any of them differs.
            if (isset(self::SERIES_COLUMNS[$col])) {
                foreach (array_merge([$master], $this->rows) as $row) {
                    if ((string) ($row[$col] ?? '') !== (string) ($value ?? '')) {
                        $this->seriesCols[$col] = $value;
                    }
                }
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
                $parsed = $this->withoutAnchor(Rule::parse((string) $sent, $startDn));
                if ($this->readable && $parsed->comparable() === $this->r0->comparable()) {
                    if (!$this->sameBound($parsed)) {
                        $this->sentRule = $parsed;
                        $this->sentBound = true;
                    }
                } elseif (!$this->readable && strtoupper(trim((string) $sent)) === strtoupper(trim((string) $master['rrule']))) {
                    // The unreadable rule sent back as it is.
                } else {
                    $this->sentRule = $this->withoutAnchor(Rule::parse((string) $sent, $rebased));
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

    /** The plan of a refusal for a series as it is (a split of a rule the expander can't read). */
    public static function refusedPlan(array $master, string $code, string $scope = 'all', array $opts = []): array
    {
        $model = new self($master, [], [], $opts);
        $plan = $model->refused($code)['plan'];
        $plan['scope'] = $scope;
        return $plan;
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
            $alts = [];
            foreach ($group['options'] as $oi => $option) {
                if (empty($option['disabled'])) {
                    $alts[$oi] = $option['id'] === $group['selected'] ? $result : $this->compute([$group['id'] => $option['id']] + $result['selected']);
                }
            }
            $consequence = in_array($group['id'], ['reading', 'zone'], true);
            $nexts = [];
            foreach ($alts as $oi => $alt) {
                $nexts[$oi] = array_slice($alt['nextDates'], 0, 3);
            }
            // Options that agree on their next dates show where they part
            // instead: their first dates after the day acted on that differ,
            // or their ends (series-model.md, The Plan, line 3). Readings and
            // zones say it in their words.
            if (!$consequence && count($alts) > 1 && count(array_unique(array_map('json_encode', $nexts))) === 1) {
                $nexts = $this->partingDates($alts) ?? $nexts;
            }
            foreach ($group['options'] as $oi => $option) {
                if (!isset($alts[$oi])) {
                    if ($consequence) {
                        $plan['groups'][$gi]['options'][$oi]['words'] = '';
                    }
                    continue;
                }
                $alt = $alts[$oi];
                $plan['groups'][$gi]['options'][$oi]['next'] = $nexts[$oi];
                $plan['groups'][$gi]['options'][$oi]['bound'] = ['words' => $alt['plan']['bound']['words']];
                if ($option['id'] !== $group['selected']) {
                    $plan['groups'][$gi]['options'][$oi]['differs'] = $this->differs($alt, $result);
                }
                if ($consequence) {
                    $plan['groups'][$gi]['options'][$oi]['words'] = $group['id'] === 'zone' ? $this->zoneWords($alt) : $this->readingWords($alt, $alts);
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

    /**
     * For options with the same next dates: each one's first three dates
     * after the day acted on where they part, or, when they agree from
     * there on, the last three dates of each (its end). Null when even
     * those agree.
     *
     * @param array<int,array> $alts
     * @return array<int,list<string>>|null
     */
    private function partingDates(array $alts): ?array
    {
        $seqs = [];
        foreach ($alts as $oi => $alt) {
            $calc = $alt['meta']['calc'] ?? null;
            if (!$calc instanceof Calc) {
                return null;
            }
            [$bounded, $periods] = $alt['walk'];
            $days = $bounded ?? $periods;
            $seqs[$oi] = ['days' => $days, 'calc' => $calc, 'landed' => (int) $alt['meta']['landed']];
        }
        $iso = fn(Calc $c, int $d): string => Time::iso($c->instant($d)->setTimezone(Time::zone($this->allDay1 ? 'UTC' : $c->tz)));
        $after = [];
        foreach ($seqs as $oi => $s) {
            $after[$oi] = array_values(array_filter($s['days'], static fn(int $d): bool => $d > $s['landed']));
        }
        $first = reset($after);
        $len = max(array_map('count', $after));
        for ($i = 0; $i < $len; $i++) {
            $col = array_map(static fn(array $a): ?int => $a[$i] ?? null, $after);
            if (count(array_unique(array_map('json_encode', $col))) > 1) {
                $out = [];
                foreach ($after as $oi => $a) {
                    $out[$oi] = array_map(fn(int $d): string => $iso($seqs[$oi]['calc'], $d), array_slice($a, $i, 3));
                }
                return $out;
            }
        }
        unset($first);
        $ends = [];
        foreach ($seqs as $oi => $s) {
            $ends[$oi] = array_map(fn(int $d): string => $iso($s['calc'], $d), array_slice($s['days'], -3));
        }
        return count(array_unique(array_map('json_encode', $ends))) > 1 ? $ends : null;
    }

    /**
     * The plan's hash (series-model.md, The API): everything the plan reads
     * and may overwrite, by value. Every column of the master and of each
     * edited row as read, their tags and people, the edit and the chosen
     * options; a write that leaves a row's timestamp alone (a tag-only
     * change, one in the same second) still changes it. Not `today`, which
     * only names years in the words.
     */
    private function hash(array $selected): string
    {
        $row = static function (array $r): array {
            ksort($r);
            return array_map(static fn($v) => $v === null ? null : (string) $v, $r);
        };
        $set = static function (mixed $list): ?array {
            if (!is_array($list)) {
                return null;
            }
            $list = array_map('strval', $list);
            sort($list);
            return $list;
        };
        $rows = [];
        foreach ($this->rows as $id => $r) {
            $rows[] = [$row($r), $set($this->opts['rowTags'][$id] ?? null), $set($this->opts['rowPeople'][$id] ?? null)];
        }
        ksort($selected);
        return substr(sha1(json_encode([
            $row($this->master), $set($this->opts['masterTags'] ?? null), $set($this->opts['masterPeople'] ?? null),
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
            if ($ab !== null && $bb !== null && $ab !== [] && $bb !== []) {
                // One ends where the other goes on: say where each ends.
                $last = $ab[count($ab) - 1];
                $separate = !empty($alt['meta']['separate']) ? ', and ' . Words::dayMonth((int) $alt['meta']['landed'], $this->thisYear) . ' separately' : '';
                return count($ab) < count($bb)
                    ? ['dates' => [Cal::date($last)], 'words' => 'ends ' . Words::dayMonth($last, $this->thisYear) . $separate]
                    : ['dates' => array_map([Cal::class, 'date'], array_slice($ab, count($bb), 3)), 'words' => 'on to ' . Words::dayMonth($last, $this->thisYear) . $separate];
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

    /**
     * A reading's consequence, beside its dates (The Plan, line 3): what it
     * drops or adds that the others don't ("drops 5 months a year, first
     * 15 Feb's", "in February: 28 Feb, and 29 Feb 2028"), and where its
     * dates are the others' too, what else sets it apart (its start, its
     * edited days).
     *
     * @param array<int,array> $alts every enabled reading's plan
     */
    private function readingWords(array $alt, array $alts): string
    {
        $meta = $alt['meta'] ?? null;
        if ($meta === null) {
            return '';
        }
        /** @var Rule $rule */
        $rule = $meta['rule'];
        $start = (int) $meta['start'];
        $y = $this->thisYear;
        $bounded = $alt['walk'][0];
        $others = array_filter($alts, static fn(array $a): bool => $a !== $alt);
        $sameSeries = $bounded !== null && $others !== [] && array_reduce($others, static fn(bool $c, array $a): bool => $c && $a['walk'][0] === $bounded, true);
        $sameNext = $others !== [] && array_reduce($others, static fn(bool $c, array $a): bool => $c && array_slice($a['nextDates'], 0, 3) === array_slice($alt['nextDates'], 0, 3), true);
        $monthEnd = $this->monthEndWords($rule, $meta, $alts, $sameSeries);
        if ($monthEnd !== null) {
            return $sameSeries ? 'the same ' . count($bounded) . ' dates; if extended, ' . $monthEnd : $monthEnd;
        }
        $parts = [];
        $was = $start !== $this->old->startDn;
        if ($sameNext) {
            $parts[] = 'starts ' . Words::date($start, $y);
        } elseif ($was) {
            $parts[] = 'starts ' . Words::date($start, $y) . ' (was ' . Words::date($this->old->startDn, $y) . ')';
        }
        $calc = $meta['calc'];
        $endsApart = $calc instanceof Calc && array_reduce($others, static fn(bool $c, array $a): bool => $c || !($a['meta']['calc'] ?? null) instanceof Calc || $a['meta']['calc']->last() !== $calc->last(), false);
        if ($endsApart && $calc->bounded() && $this->old->bounded() && $calc->last() !== $this->old->last() && $calc->last() !== null) {
            $parts[] = 'ends ' . Words::date((int) $calc->last(), $y);
        }
        // Edited days that land differently under the other readings.
        $edited = [];
        foreach ($alt['plan']['days'] as $d) {
            if ($d['kind'] !== 'edited' || $d['newKey'] === null) {
                continue;
            }
            $differs = false;
            foreach ($others as $o) {
                foreach ($o['plan']['days'] as $od) {
                    if ($od['kind'] === 'edited' && $od['key'] === $d['key'] && $od['newKey'] !== $d['newKey']) {
                        $differs = true;
                    }
                }
            }
            if ($differs && $calc instanceof Calc) {
                $dn = $calc->dnOf(str_replace(['T', 'Z'], [' ', ''], (string) $d['newKey']));
                $name = isset($d['title']) && $d['title'] !== '' ? "'" . $d['title'] . "'" : Words::date($this->old->dnOf(str_replace(['T', 'Z'], [' ', ''], (string) $d['key'])), $y);
                $edited[] = $name . ($d['outcome'] === 'stays' ? ' stays on ' : ' moves to ') . Words::date($dn, $y);
            }
        }
        $text = implode('; ', $parts);
        if ($edited !== []) {
            $text .= ($text === '' ? '' : ', ') . Words::andList($edited);
        }
        return $sameNext && $text !== '' ? 'the same 3 dates; ' . $text : $text;
    }

    /** The month-end readings' consequences (Month Ends in the examples), or null for another reading. */
    private function monthEndWords(Rule $rule, array $meta, array $alts, bool $extended): ?string
    {
        $y = $this->thisYear;
        $mem = $rule->members[0] ?? null;
        if ($mem === null || count($rule->members) !== 1 || !in_array($rule->kind, ['monthly', 'yearly'], true)) {
            return null;
        }
        $t = (string) $mem['t'];
        $n = (int) $mem['n'];
        $from = (int) $meta['start'];
        $lost = (array) $meta['lost'];
        sort($lost);
        $firstLost = $lost !== [] ? Words::dayMonth((int) $lost[0], $y) . '\'s' : null;
        if ($rule->kind === 'yearly') {
            if ($t === 'day' && $n === 29 && $rule->months === [2]) {
                return 'drops three years in four';
            }
            return null;
        }
        if ($t === 'clamp') {
            if ($extended) {
                return '28 or 29 Feb';
            }
            [$fy] = Cal::ymd($from);
            $febs = [];
            for ($yy = $fy; $yy < $fy + 9 && count($febs) < 2; $yy++) {
                $d = $rule->evalMember($mem, $yy, 2);
                $dn = $d === null ? null : Cal::dn($yy, 2, $d);
                if ($dn !== null && $dn >= $from && ($febs === [] || Cal::ymd($febs[0])[2] !== $d)) {
                    $febs[] = $dn;
                }
            }
            return 'in February: ' . implode(', and ', array_map(static fn(int $dn): string => Words::dayMonth($dn, $y), $febs));
        }
        if ($t === 'day' && $n >= 29) {
            $months = match ($n) {
                29 => 'February in three years of four',
                30 => 'every February',
                default => '5 months a year',
            };
            if ($extended) {
                return $n === 31 ? 'none in shorter months' : 'none in February' . ($n === 29 ? ' in three years of four' : '');
            }
            if ($rule->interval > 1) {
                return $firstLost !== null ? 'drops some months\' events, first ' . $firstLost : 'drops the months without a ' . Words::ordinal($n);
            }
            if ($n === 31) {
                return 'drops ' . $months . ($firstLost !== null ? ', first ' . $firstLost : '');
            }
            return $firstLost !== null ? 'none in February: drops ' . $firstLost . ' event, then ' . $months : 'drops ' . $months;
        }
        if ($t === 'day' && $n === -1) {
            // The last day beside a reading on the 30th: it moves to the 31st in longer months.
            foreach ($alts as $a) {
                $m = $a['meta']['rule']->members[0] ?? null;
                if ($m !== null && (int) $m['n'] === 30 && in_array($m['t'], ['day', 'clamp'], true)) {
                    return 'the 31st in longer months';
                }
            }
            return '';
        }
        return null;
    }

    /**
     * A zone option's consequence (Zones, DST and All-Day Days): its clock in
     * the new zone, and in the old one, with the dates whose clock there
     * differs ("every Monday at 18:00 Berlin time; 09:00 in Los Angeles,
     * 10:00 on 15 and 22 Mar and 1 Nov"), over the year from the day acted on.
     */
    private function zoneWords(array $alt): string
    {
        $calc = $alt['meta']['calc'] ?? null;
        if (!$calc instanceof Calc) {
            return '';
        }
        $rule = $alt['meta']['rule'];
        $from = (int) $alt['meta']['landed'];
        $clock = $calc->clock;
        $every = $rule->kind === 'weekly' && $rule->interval === 1
            ? 'every ' . Words::andList(array_map([Words::class, 'day'], Rule::sortedDays($rule->members, 0)))
            : Words::lower(Words::rule($rule));
        $head = sprintf('%s at %02d:%02d %s time', $every, $clock[0], $clock[1], self::zoneName($calc->tz));
        $oldZone = Time::zone($this->tz0);
        $byClock = [];
        $sameDay = true;
        foreach ($calc->occurrences($from + 366, 120) as $d) {
            if ($d < $from) {
                continue;
            }
            $local = $calc->instant($d)->setTimezone($oldZone);
            $key = $local->format('H:i');
            $byClock[$key][] = Cal::dnOf($local);
            if (Cal::dnOf($local) !== $d) {
                $sameDay = false;
                $wd = Words::day(Cal::wd(Cal::dnOf($local)));
            }
        }
        if ($byClock === []) {
            return $head;
        }
        uasort($byClock, static fn(array $a, array $b): int => count($b) <=> count($a));
        $base = (string) array_key_first($byClock);
        $tail = $base . ($sameDay ? '' : ' ' . ($wd ?? '')) . ' in ' . self::zoneName($this->tz0);
        $rest = [];
        foreach ($byClock as $c => $dns) {
            if ($c !== $base) {
                $rest[] = $c . ' on ' . self::datesWords($dns);
            }
        }
        return $head . '; ' . $tail . ($rest === [] ? '' : ', ' . implode(', ', $rest));
    }

    /** "15 and 22 Mar and 1 Nov": dates grouped by month. @param list<int> $dns */
    private static function datesWords(array $dns): string
    {
        sort($dns);
        $groups = [];
        foreach ($dns as $dn) {
            [$yy, $m, $d] = Cal::ymd($dn);
            $groups[sprintf('%04d-%02d', $yy, $m)][] = $d;
        }
        $out = [];
        foreach ($groups as $ym => $days) {
            $out[] = Words::andList(array_map('strval', $days)) . ' ' . substr(Words::monthName((int) substr($ym, 5, 2)), 0, 3);
        }
        return count($out) > 4 ? implode(', ', array_slice($out, 0, 4)) . ' and ' . (count($out) - 4) . ' more months' : Words::andList($out);
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
        $readings = $this->readings($delta, $landedDn, $clock1);
        if ($readings === []) {
            return $this->refused('dayset');
        }
        $options = [];
        $default = null;
        foreach ($readings as $ri => $r) {
            $opt = ['id' => $r['id'], 'label' => $r['label']];
            // On Google until 1.5.2: only a reading that keeps the rule text
            // as written (a list only as its whole-pattern shift). Marked on
            // the list itself, so a refusal of every reading gives this reason.
            if ($this->google && ($r['rule']->pattern() !== $this->r0->pattern() || $r['id'] === 'member') && ($this->kind === 'drag' || $delta !== 0)) {
                $r['disabled'] = true;
                $r['code'] = 'google';
                $r['reason'] = self::REASONS['google'];
                $readings[$ri] = $r;
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
            $why = $readings[0];
            foreach ($readings as $r) {
                if (($r['code'] ?? null) === 'google') {
                    $why = $r;
                    break;
                }
            }
            return $this->refused($why['code'] ?? 'dayset', $why['reason'] ?? null);
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
    private function readings(int $delta, int $landedDn, array $clock1): array
    {
        if ($this->kind === 'newrule' || $this->kind === 'norule') {
            return $this->newRuleReadings($delta);
        }
        $readings = $delta === 0 ? [Readings::same($this->r0)] : Readings::forDrag($this->r0, $this->actedDn0, $landedDn, $this->old->startDn);
        // A rule that names its clock (BYHOUR, BYMINUTE) takes the new one
        // in every reading, a drag across days included: a rule that says
        // 09:30 can't move to 10:45 otherwise (Imported Rules, by Capability).
        if ($this->r0->hour !== null || $this->r0->minute !== null) {
            foreach ($readings as $i => $r) {
                $rule = clone $r['rule'];
                if ($this->allDay1) {
                    $rule->hour = null;
                    $rule->minute = null;
                } else {
                    $rule->hour = $this->r0->hour !== null ? $clock1[0] : null;
                    $rule->minute = $this->r0->minute !== null ? $clock1[1] : null;
                }
                $readings[$i]['rule'] = $rule;
            }
        }
        return $readings;
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
        // An unbounded series is read to a horizon, so the new rule's days are
        // only known to correspond to old ones up to there. Read on by the
        // furthest an image moved (and a span more), so every old occurrence
        // whose image falls before the horizon is among those read, and look
        // for gains only up to the horizon: past it a day of the new rule may
        // be the image of an old occurrence not read (Gains at the edges: an
        // unbounded series has no last period).
        $gainTo = PHP_INT_MAX;
        if (!$newRule && !$this->old->bounded()) {
            $readTo = max($horizon, $oldOcc === [] ? $horizon : (int) max($oldOcc));
            $shift = 0;
            foreach ($images as $dn => $img) {
                if ($img !== null) {
                    $shift = max($shift, abs($img - $dn));
                }
            }
            $oldOcc = $this->old->occurrences($readTo + $shift + $span);
            $images = [];
            foreach ($oldOcc as $dn) {
                $images[$dn] = $image($dn, $this->r0->supported() ? $this->r0->memberOf($dn) : null);
            }
            $gainTo = $readTo;
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
        $calcP = new Calc($r1p, $newStart, $this->tz1, $this->allDay1, $clock1, $this->len1);
        $latest = $imageSet === [] ? null : max($imageSet);

        // Gains: occurrences of the new rule that are no old one's image.
        $edgeGains = [];
        $gains = [];
        if (!$newRule && $imageSet !== []) {
            $to = min((int) $latest, $gainTo);
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
        $edgeAt = [];
        if ($edgeGains !== [] && !$this->google) {
            // An unbounded series has no last period: its gains are at the start.
            $mid = $this->old->bounded() ? intdiv(min($imageSet) + (int) $latest, 2) : PHP_INT_MAX;
            $edgeAt = [];
            foreach ($edgeGains as $g) {
                $edgeAt[$g] = $g <= $mid ? 'start' : 'end';
            }
            $noun = match ($r1p->kind) {
                'daily' => 'day', 'monthly' => 'month', 'yearly' => 'year', default => 'week',
            };
            $sides = array_values(array_unique($edgeAt));
            $edgeLabel = count($sides) > 1 ? 'First and last ' . $noun : ($sides[0] === 'start' ? 'First ' : 'Last ') . $noun;
            $gainWords = Words::andList(array_map(fn(int $d): string => Words::date($d, $this->thisYear), $edgeGains));
            // Leaving a gained day out keeps every old event's image with
            // nothing else lost, so it comes first (fourth review).
            $edge = $this->pick('edge', $edgeLabel, [
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
        } elseif ($this->r0->count !== null || $this->r0->until !== null) {
            $kept = $r1p->withBound($this->r0->count, $this->r0->until);
            $keptCalc = new Calc($kept, $newStart, $this->tz1, $this->allDay1, $clock1, $this->len1);
        }
        $instants = static fn(Calc $c): array => array_map(static fn(int $d): int => $c->instant($d)->getTimestamp(), $c->all());
        if (isset($kept, $keptCalc) && $r1p->comparable() === $this->r0->comparable() && $keptCalc->last() === $latest && $instants($keptCalc) === $instants($this->old)) {
            // A bound equal to the stored one is no new bound (The Function,
            // 1): the same pattern with it gives the same occurrences, every
            // one at the same instant, so the stored COUNT or UNTIL stays as
            // written. A new pattern's end is written from its images.
            $r1 = $kept;
        } elseif (isset($kept)) {
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
            $untilAt = fn(int $d): string => $this->allDay1 ? str_replace('-', '', Cal::date($d)) : $calcP->instant($d)->format('Ymd\THis\Z');
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
        // An old key's new key (Correspondence, by Member): the new
        // series' occurrence on its image, the one at its place among its
        // day's keys (a rule several times a day has several on a day), or
        // null when the new series has none there. With every delta zero
        // the new series is the old one, so each key is itself.
        $keyTo = max($horizon, (int) ($latest ?? $horizon)) + 1;
        $byDay = static function (Calc $c, int $to): array {
            $out = [];
            foreach ($c->keys($to) as [$d, $k]) {
                $out[$d][] = $k;
            }
            return $out;
        };
        $oldOn = null;
        $newOn = null;
        $newKey = function (string $key, ?int $img) use ($calc1, $inside, $byDay, $keyTo, &$oldOn, &$newOn): ?string {
            if (!$inside($img)) {
                return null;
            }
            if ($calc1->rule->supported() && $this->r0->supported()) {
                return $calc1->key((int) $img);
            }
            $oldOn ??= $byDay($this->old, $keyTo);
            $newOn ??= $byDay($calc1, $keyTo);
            $at = array_search($key, $oldOn[$this->old->dnOf($key)] ?? [], true);
            return $at === false ? null : ($newOn[(int) $img][$at] ?? null);
        };
        // A generated skip past the new end needs nothing (Bounds, gains at the edges).
        $genSkips = array_values(array_filter($genSkips, $inside));

        // Skipped days.
        $newExdates = [];
        $days = [];
        if (!$this->google) {
            foreach ($this->exdates as $key) {
                $odn = $this->old->dnOf($key);
                $img = $newRule ? $image($odn) : $image($odn, $this->r0->supported() ? $this->r0->memberOf($odn) : null);
                $nk = $newKey($key, $img);
                if ($nk !== null) {
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
                    'words' => Words::date($g, $this->thisYear) . (($edgeAt[$g] ?? 'start') === 'start' && $newStart === $this->old->startDn
                        ? ' skipped, so the series starts as before'
                        : ' skipped: no event of the series was on it')];
            }
        }
        $newExdates = array_values(array_unique($newExdates));
        sort($newExdates);

        // Edited days: inside, outside, or the day acted on.
        $placeRows = [];
        $newKeys = [];
        $outsideRows = [];
        $actedRowId = null;
        if (!$this->google) {
            foreach ($this->rows as $id => $row) {
                $key = (string) $row['recurrence_instance_utc'];
                $odn = $this->old->dnOf($key);
                if (!$newRule && $this->kind !== 'bound' && $key === $this->acted) {
                    $actedRowId = $id;
                    if (!$separate) {
                        $placeRows[$id] = $landedDn;
                        $newKeys[$id] = $newKey($key, $landedDn);
                    }
                    continue;
                }
                $img = $newRule ? $image($odn) : $image($odn, $this->r0->supported() ? $this->r0->memberOf($odn) : null);
                $newKeys[$id] = $newKey($key, $img);
                if ($newKeys[$id] !== null) {
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

        // A first day in a skipped hour: stored from the day before it,
        // skipped, so the start keeps the clock (Zones, DST and All-Day Days).
        $stored = $r1;
        $start1 = $calc1->instant($newStart);
        if (!$this->allDay1 && !$this->google && $this->kind !== 'norule' && self::inGap($newStart, $clock1, $this->tz1)) {
            $anchor = $this->anchorFor($r1, $newStart, $clock1);
            if ($anchor !== null) {
                $stored = $r1->count !== null ? $r1->withBound($r1->count + 1, null) : $r1;
                $start1 = Calc::at($anchor, $clock1, $this->tz1);
                $newExdates[] = Time::toDb($start1);
                sort($newExdates);
                $days[] = ['key' => null, 'newKey' => Time::toDb($start1), 'kind' => 'skipped', 'outcome' => 'anchor', 'words' => ''];
            }
        }

        // The writes.
        $masterCols = [];
        // A rule equal to the stored one, pattern and bound, is written as
        // it was stored (The Function, 1).
        $newRruleText = $this->kind === 'norule' ? null
            : ($stored->comparable() === $this->rule0->comparable() && $stored->count === $this->rule0->count && $stored->until === $this->rule0->until
                ? (string) $this->master['rrule'] : $stored->text());
        $end1 = $this->endFrom($start1, $this->allDay1, $this->len1, $this->tz1);
        $set = function (string $col, mixed $value) use (&$masterCols): void {
            if ((string) ($this->master[$col] ?? '') !== (string) ($value ?? '') || ($value === null) !== ($this->master[$col] === null)) {
                $masterCols[$col] = $value;
            }
        };
        $set('rrule', $newRruleText);
        $set('start_utc', Time::toDb($start1));
        $set('end_utc', Time::toDb($end1));
        $set('all_day', $this->allDay1 ? 1 : 0);
        $set('tzid', $this->allDay1 ? 'UTC' : $this->tz1);
        $storedSkips = $this->storedSkips();
        if (!$this->google && $newExdates !== $storedSkips) {
            $set('exdates_json', $newExdates === [] ? null : json_encode($newExdates));
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
        foreach ($this->seriesCols as $col => $v) {
            $set($col, $v);
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
            $newKey = (string) $newKeys[$id];
            [$start, $end, $allDay, $tz] = $this->place($row, $own, (int) $img, $newKey, $moveOwnDates || $id === $actedRowId, $id === $actedRowId);
            $cols = [];
            $put = function (string $col, mixed $value) use (&$cols, $row): void {
                if ((string) ($row[$col] ?? '') !== (string) ($value ?? '')) {
                    $cols[$col] = $value;
                }
            };
            $put('recurrence_instance_utc', $newKey);
            $put('start_utc', Time::toDb($start));
            $put('end_utc', Time::toDb($end));
            $put('all_day', $allDay ? 1 : 0);
            $put('tzid', $tz);
            $ownAfter = $own;
            foreach ($this->changed as $field => $fcols) {
                // The day acted on takes the edit's fields: from that day, an
                // edit for all events means the series' value (Own Fields).
                $inherits = ($id === $actedRowId && !empty($this->edit['fromEditedDay'])) || !in_array($field, $own, true) || !empty($give[$field]);
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
            foreach ($this->seriesCols as $col => $v) {
                $put($col, $v);
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
            $keepWords = array_map(static fn(string $f): string => self::OWN_WORDS[$f], $keeps);
            // A timed day at its own time while the series' time changes says
            // the time it keeps ("keeps its own time at 09:00", the examples' all-day case).
            $seriesTime = $this->clock1 !== $this->clock0 || $this->allDay1 !== $this->allDay0 || Time::normalizeTzid($this->tz1) !== Time::normalizeTzid($this->tz0);
            if ($seriesTime && !$allDay && (int) $row['all_day'] === 0 && array_intersect(['time', 'allDay'], $own) !== []) {
                $rowLocal = Time::fromDb((string) $row['start_utc'])->setTimezone(Time::zone((string) $row['tzid']));
                $newLocal = $start->setTimezone(Time::zone($tz));
                if ($rowLocal->format('H:i') === $newLocal->format('H:i')) {
                    $keepWords[] = 'own time at ' . $newLocal->format('H:i');
                }
            }
            $keepsWords = $keepWords === [] ? '' : ', keeps its ' . Words::andList($keepWords);
            $wasShown = $this->shownDn($row);
            // A field it owned that it takes now (from its own row, or "Give it the new title too").
            $taken = array_values(array_intersect(array_keys($this->changed), array_diff($own, $ownAfter)));
            if (in_array('title', $taken, true)) {
                $keepsWords .= ", takes '" . (string) ($this->changed['title']['title'] ?? '') . "'";
                $taken = array_values(array_diff($taken, ['title']));
            }
            if ($taken !== []) {
                $keepsWords .= ', takes the new ' . Words::andList(array_map(static fn(string $f): string => self::OWN_WORDS[$f], $taken));
            }
            if (in_array('date', $own, true) && $wasShown !== $odn) {
                // Where it shows after: another day, the same day at another time, or unchanged.
                $localStart = $start->setTimezone(Time::zone($allDay ? 'UTC' : $tz));
                $newShown = Cal::dnOf($localStart);
                $clockMoved = !$allDay && Time::toDb($start) !== (string) $row['start_utc'];
                $where = $newShown !== $wasShown ? 'moves to ' . Words::date($newShown, $this->thisYear) : ($clockMoved ? 'moves to ' . $localStart->format('H:i') : 'stays there');
                $words = $name . Words::date($odn, $this->thisYear) . ', moved to ' . Words::date($wasShown, $this->thisYear) . ': ' . $where . $keepsWords;
            } else {
                // A day that keeps its date and changes its time says the time.
                $localStart = $start->setTimezone(Time::zone($allDay ? 'UTC' : $tz));
                $to = Cal::dnOf($localStart) === $wasShown && !$allDay ? $localStart->format('H:i') : Words::date((int) $img, $this->thisYear);
                $words = $name . Words::date($odn, $this->thisYear) . ($moved ? ' moves to ' . $to : ' stays') . $keepsWords;
            }
            // As data beside the words: the own fields it keeps, and the fields
            // this edit changes that it takes from the series.
            $edited = array_merge(array_keys($this->changed), array_keys(array_filter([
                'date' => $landedDn !== $this->actedDn0,
                'time' => $clock1 !== $this->clock0 || Time::normalizeTzid($this->tz1) !== Time::normalizeTzid($this->tz0),
                'length' => $this->len1 !== $this->len0,
                'allDay' => $this->allDay1 !== $this->allDay0,
            ])));
            $days[] = ['key' => (string) $row['recurrence_instance_utc'], 'newKey' => $newKey, 'kind' => 'edited',
                'title' => (string) $row['title'], 'outcome' => $moved ? 'moves' : 'stays', 'words' => $words,
                'kept' => OwnFields::normalize($ownAfter), 'takes' => OwnFields::normalize(array_diff($edited, $ownAfter))];
        }
        foreach ($outsideRows as $id => $img) {
            $row = $this->rows[$id];
            $odn = $this->old->dnOf((string) $row['recurrence_instance_utc']);
            $label = "'" . (string) $row['title'] . "' " . Words::date($this->shownDn($row), $this->thisYear);
            if ($outside === 'delete') {
                $delete[] = $id;
                $days[] = ['key' => (string) $row['recurrence_instance_utc'], 'newKey' => null, 'kind' => 'edited', 'title' => (string) $row['title'], 'outcome' => 'deleted', 'words' => $label . ' is deleted', 'kept' => $this->ownOf($row)];
            } else {
                $detach[$id] = [];
                $days[] = ['key' => (string) $row['recurrence_instance_utc'], 'newKey' => null, 'kind' => 'edited', 'title' => (string) $row['title'], 'outcome' => 'separate', 'words' => $label . ' becomes an event of its own', 'kept' => $this->ownOf($row)];
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
            $days[] = ['key' => $this->acted, 'newKey' => null, 'kind' => $actedRowId !== null ? 'edited' : 'acted', 'outcome' => 'separate']
                + ($actedRowId !== null ? ['title' => (string) $this->rows[$actedRowId]['title']] : []) + [
                'words' => 'This one, ' . Words::date($landedDn, $this->thisYear) . ', becomes an event of its own'];
        }

        // Words and the rest of the plan.
        $wordsBefore = Words::rule($this->r0, $this->allDay0 ? null : $this->clock0);
        $words = $this->kind === 'norule' ? 'Does not repeat' : Words::rule($r1p, $this->allDay1 ? null : $clock1);
        $walkCalc = $calc1;
        $walk = $this->walk($walkCalc, $genSkips);
        $boundKey = [$r1->count, $r1->until];
        // Skipped days count toward COUNT (RFC 5545 3.8.5.3): the line counts
        // the series' scheduled occurrences (a skip this change generates is
        // no event of the series) and says how many of them were skipped.
        $genKeys = array_flip(array_map(static fn(int $d): string => $calc1->key($d), $genSkips));
        $personSkipped = 0;
        if ($all1 !== null) {
            $skipSet = array_flip($newExdates);
            $allKeys = $calc1->rule->supported() ? array_map(static fn(int $d): string => $calc1->key($d), $all1) : array_column($calc1->keys(PHP_INT_MAX), 1);
            foreach ($allKeys as $k) {
                if (isset($skipSet[$k]) && !isset($genKeys[$k])) {
                    $personSkipped++;
                }
            }
        }
        $lossDays = [];
        foreach ($images as $dn => $img) {
            if (!$newRule && $img !== null && $dn !== $this->actedDn0 && !$calc1->isOccurrence($img)) {
                $lossDays[] = $img;
            }
        }
        [$boundKind, $boundWords] = $this->boundWords($r1, $calc1, $oldOcc, $separate, $outside === 'keep' ? count($outsideRows) : 0, count($genSkips), $personSkipped, $lossDays);
        $losses = [];
        foreach ($images as $dn => $img) {
            if ($img === null || !$calc1->isOccurrence($img)) {
                if (!$newRule && $dn !== $this->actedDn0) {
                    $losses[] = ['date' => Cal::date($dn), 'words' => Words::dayMonth($dn, $this->thisYear) . ($img === null ? ' has no place in the new series' : ' falls after the new end')];
                }
            }
        }
        // A gain past the new end is no gain (P2-6: "Keep 6 events" adds nothing after its sixth).
        $gainList = array_map(fn(int $d): array => ['date' => Cal::date($d), 'words' => 'Adds ' . Words::dayMonth($d, $this->thisYear)],
            array_values(array_filter(array_merge($gains, $genSkips === [] ? $edgeGains : []), $inside)));
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
        // A series column every row had the same value of changes as one
        // field; one the rows differ in says the edited days take it too,
        // and isn't direct (the person sees that more than the series changes).
        $mixed = false;
        foreach ($this->seriesCols as $col => $v) {
            $was = (string) ($this->master[$col] ?? '');
            $differ = array_filter($this->rows, static fn(array $r): bool => (string) ($r[$col] ?? '') !== $was);
            $mixed = $mixed || $differ !== [];
            $yes = (int) $v === 1;
            $fieldsWords[] = ['field' => $col, 'words' => self::SERIES_COLUMNS[$col] . ': ' . ($was === (string) ($v ?? '')
                ? ($yes ? 'yes' : 'no') . ', now for every edited day too'
                : ($yes ? 'no, now yes' : 'yes, now no') . ($differ !== [] ? ' for every event, edited days included' : ''))];
        }
        $groupsOut = $this->groups;
        $direct = $this->kind === 'fields' && $groupsOut === [] && $this->refusals === [] && !isset($this->edit['calendarId']) && !$mixed;
        $verb = match ($this->kind) {
            'drag' => 'Moved', 'newrule', 'norule' => 'Changed how ' . $title . ' repeats', default => 'Changed',
        };
        $summary = ($this->kind === 'newrule' || $this->kind === 'norule' ? $verb : $verb . ' ' . $title) . ' (all events): '
            . (in_array($this->kind, ['drag', 'newrule', 'norule'], true) || $words !== $wordsBefore ? Words::lower($wordsBefore) . ', now ' . Words::lower($words) . ', from ' . Words::dayMonth($newStart, $this->thisYear) : Words::lower($words) . ', as before')
            . '.';
        $detachCount = count(array_filter($days, static fn(array $d): bool => $d['outcome'] === 'separate' && $d['kind'] === 'edited'));
        $ownership = null;
        if ($detachCount > 0) {
            // Each named where it shows, as its day line names it.
            $shownOf = [];
            foreach ($this->rows as $row) {
                $shownOf[(string) $row['recurrence_instance_utc']] = $this->shownDn($row);
            }
            $names = array_values(array_map(fn(array $d): string => "'" . ($d['title'] ?? '') . "' on "
                . Words::dayMonth($d['key'] === $this->acted ? $landedDn : ($shownOf[(string) $d['key']] ?? $this->old->dnOf((string) $d['key'])), $this->thisYear),
                array_filter($days, static fn(array $d): bool => $d['outcome'] === 'separate' && $d['kind'] === 'edited')));
            $summary .= ' ' . ($detachCount === 1 ? 'One edited day became an event of its own: ' : Words::count($detachCount) . ' edited days became events of their own: ') . Words::andList($names) . '.';
            // The dialog's sentence (series-model.md, The Plan, line 7).
            $ownership = ($detachCount === 1 ? 'One edited day becomes an event of its own: ' : Words::count($detachCount) . ' edited days become events of their own: ') . Words::andList($names) . '.';
        }

        // Keys in the plan read as instants: 2027-04-14T02:00:00Z.
        $iso = static fn(?string $k): ?string => $k === null ? null : str_replace(' ', 'T', $k) . 'Z';
        $days = array_map(static fn(array $d): array => ['key' => $iso($d['key']), 'newKey' => $iso($d['newKey'])] + $d + ['kept' => [], 'takes' => []], $days);
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
            // For the options' consequence words (optionWords).
            'meta' => [
                'reading' => (string) $reading['id'], 'rule' => $r1, 'calc' => $calc1, 'start' => $newStart, 'landed' => $landedDn,
                'lost' => array_keys(array_filter($images, static fn($x): bool => $x === null)),
                'separate' => $separate, 'clock' => $clock1,
            ],
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

    /**
     * The bound's kind and words: "5 events, ends 22 Jan (was 20 Jan)",
     * "Changes 6 events to 10, ends 1 Nov", "5 scheduled occurrences, 1
     * skipped, ends 22 Jan (was 20 Jan)". A skip this change generates is no
     * event of the series, so it isn't counted; a skip the person made is
     * one of the scheduled occurrences (series-model.md, Bounds).
     *
     * @param list<int> $lossDays old events' images the new bound leaves out
     * @return array{0:string,1:string}
     */
    private function boundWords(Rule $r1, Calc $calc1, array $oldOcc, bool $separate, int $kept, int $generated, int $personSkipped, array $lossDays): array
    {
        $extra = ($separate ? 1 : 0) + $kept;
        if (!$this->readable) {
            // A rule the expander can't read: no count or end the model could say.
            return [$r1->count !== null ? 'count' : ($r1->until !== null ? 'until' : 'none'), ''];
        }
        if ($r1->count === null && $r1->until === null) {
            return ['none', $this->r0->count === null && $this->r0->until === null ? 'No end, as before' : 'No end'];
        }
        $last = $calc1->last();
        $was = $this->old->bounded() ? $this->old->last() : null;
        // A rule several times a day is read by day here, so its count is
        // the one it says, not its days (60 events on 30 days stay 60).
        $byDay = $r1->supported() || !$r1->several;
        $n = $byDay ? count($calc1->all()) - $generated : (int) ($r1->count ?? count($calc1->keys(PHP_INT_MAX)));
        $ends = $last !== null ? 'ends ' . Words::dayMonth($last, $this->thisYear) : '';
        $wasWords = $was !== null && $last !== null && $was !== $last ? ' (was ' . Words::dayMonth($was, $this->thisYear) . ')' : '';
        $scheduled = $n . ' scheduled occurrence' . ($n === 1 ? '' : 's') . ', ' . $personSkipped . ' skipped';
        $lost = count($lossDays) === 1 ? '; ' . Words::date($lossDays[0], $this->thisYear) . ' has no place' : '';
        if ($r1->count !== null) {
            $oldN = (int) ($this->r0->count ?? count($oldOcc));
            $plus = $extra > 0 ? '; ' . $n . ' in the series + ' . $extra . ' separate' : '';
            if ($this->r0->count !== null && $oldN !== $n) {
                return ['count', 'Changes ' . Words::events($oldN) . ' to ' . $n . ', ' . $ends . ($personSkipped > 0 ? '; ' . $scheduled : '') . $plus];
            }
            return ['count', ($personSkipped > 0 ? $scheduled : Words::events($n)) . ', ' . $ends . $wasWords . $lost . $plus];
        }
        $oldN = count($oldOcc);
        $counted = $byDay && $n !== $oldN && $this->r0->until !== null ? '; ' . Words::events($n) . ' (was ' . $oldN . ')' : '';
        return ['until', Words::upper($ends) . $wasWords . $counted . ($personSkipped > 0 ? '; ' . $scheduled : '') . $lost . ($extra > 0 ? '; ' . $extra . ' separate' : '')];
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
            $key = (string) ($overrideWrites[$id]['recurrence_instance_utc'] ?? $row['recurrence_instance_utc']);
            $byKey[$key] = ['start' => Time::dbToIso((string) $start, $tz), 'kind' => 'edited']
                + (in_array('date', $own, true) && $img !== $fromDn ? ['words' => 'its own date'] : []);
        }
        $out = [];
        $span = 400;
        $byDay = $calc1->rule->supported();
        for ($tries = 0; $tries < 6 && count($out) < 4; $tries++) {
            $out = [];
            // A rule several times a day lists every time it gives, by key.
            $occ = $byDay ? array_map(static fn(int $d): array => [$d, $calc1->key($d)], $calc1->occurrences($fromDn + $span)) : $calc1->keys($fromDn + $span, 4000);
            foreach ($occ as [$d, $key]) {
                if ($d < $fromDn) {
                    continue;
                }
                if (isset($skip[$key])) {
                    continue;
                }
                $tz = $this->allDay1 ? 'UTC' : $this->tz1;
                $item = $byKey[$key] ?? ['start' => Time::iso(($byDay ? $calc1->instant($d) : Time::fromDb($key))->setTimezone(Time::zone($tz))), 'kind' => 'occurrence'];
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

    /** A changed field's line, before and after: "Title: Choir, now Chorus", "Reminder: 1 hour before, now 30 minutes". */
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
        $was = $this->master[$col] ?? null;
        $now = $cols[$col] ?? null;
        if ($field === 'reminders') {
            $a = self::reminderWords($was);
            $b = self::reminderWords($now);
            $one = count(\BetterCal\Domain\Reminders::decode($was) ?? [0]) <= 1 && count(\BetterCal\Domain\Reminders::decode($now) ?? [0]) <= 1;
            if (str_ends_with($a, ' before') && str_ends_with($b, ' before')) {
                $b = substr($b, 0, -7);
            }
            return ($one ? 'Reminder' : 'Reminders') . ': ' . $a . ', now ' . $b;
        }
        $short = static function (?string $v): string {
            $v = trim(preg_replace('/\s+/', ' ', (string) $v) ?? '');
            if ($v === '') {
                return 'none';
            }
            return mb_strlen($v) > 40 ? rtrim(mb_substr($v, 0, 39)) . '…' : $v;
        };
        if ($field === 'status') {
            $short = static fn(?string $v): string => (string) $v === 'tentative' ? 'maybe' : 'planned';
        }
        return $name . ': ' . $short($was === null ? null : (string) $was) . ', now ' . $short($now === null ? null : (string) $now);
    }

    /** "1 hour before", "30 minutes before and 1 day before at 18:00", "the default", "none". */
    private static function reminderWords(mixed $json): string
    {
        $list = \BetterCal\Domain\Reminders::decode($json);
        if ($list === null) {
            return 'the default';
        }
        if ($list === []) {
            return 'none';
        }
        $out = [];
        foreach ($list as $r) {
            if (is_array($r) && isset($r['daysBefore'], $r['time'])) {
                $d = (int) $r['daysBefore'];
                $out[] = ($d === 0 ? 'the same day' : ($d === 1 ? '1 day before' : $d . ' days before')) . ' at ' . (string) $r['time'];
                continue;
            }
            $m = (int) (is_array($r) ? ($r['minutes'] ?? 0) : $r);
            $unit = static fn(int $n, string $u): string => $n . ' ' . $u . ($n === 1 ? '' : 's') . ' before';
            $out[] = match (true) {
                $m === 0 => 'at the start',
                $m % 10080 === 0 => $unit(intdiv($m, 10080), 'week'),
                $m % 1440 === 0 => $unit(intdiv($m, 1440), 'day'),
                $m % 60 === 0 => $unit(intdiv($m, 60), 'hour'),
                default => $unit($m, 'minute'),
            };
        }
        return Words::andList($out);
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
    private function place(array $row, array $own, int $img, string $newKey, bool $moveOwnDates, bool $acted = false): array
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
        $fromHere = $acted && !empty($this->edit['fromEditedDay']) && !$rowAllDay && !$this->allDay0 && !$this->allDay1;
        if (in_array('time', $own, true) && !$rowAllDay) {
            // Its own clock in its own zone, on its own local day, moved with
            // its occurrence's days; the day acted on also by the clock
            // change, so it shows where it was dropped (S + the change).
            $tz = (string) $row['tzid'];
            $clock = self::clockOf($shown);
            if (!in_array('date', $own, true) || $moveOwnDates) {
                $date = $shownDn + ($img - $odn);
            }
            if ($fromHere) {
                $sec = $clock[0] * 3600 + $clock[1] * 60 + $clock[2] + $this->clockDelta;
                $date = $shownDn + $this->dayDelta + intdiv($sec - (($sec % 86400) + 86400) % 86400, 86400);
                $sec = (($sec % 86400) + 86400) % 86400;
                $clock = [intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60];
            }
        } else {
            // Its occurrence's clock: the new key's, as the expander gives it.
            $tz = $this->allDay1 ? (string) $row['tzid'] : $this->tz1;
            $clock = self::clockOf($this->allDay1 ? $shown : Time::fromDb($newKey)->setTimezone(Time::zone($this->tz1)));
        }
        $len = in_array('length', $own, true) || $this->allDay1 ? $rowLen : $this->len1;
        if ($rowAllDay && !in_array('length', $own, true)) {
            $len = $this->allDay1 ? $rowLen : $this->len1;
        }
        if ($fromHere && in_array('length', $own, true)) {
            // The day acted on takes the length change on its own length.
            $len = $rowLen + $this->len1 - $this->len0;
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

    /** The skipped days as stored, sorted. @return list<string> */
    private function storedSkips(): array
    {
        try {
            $skips = \BetterCal\Domain\Recurrence::decodeExdates($this->master['exdates_json'] ?? null);
        } catch (\Throwable) {
            $skips = [];
        }
        sort($skips);
        return array_values($skips);
    }

    /** @return array{0:int,1:int,2:int} a local time's clock */
    private static function clockOf(\DateTimeImmutable $t): array
    {
        return [(int) $t->format('G'), (int) $t->format('i'), (int) $t->format('s')];
    }

    /** Whether a clock time doesn't exist on a local day (a spring-forward gap). */
    private static function inGap(int $dn, array $clock, string $tz): bool
    {
        return Calc::at($dn, $clock, $tz)->setTimezone(Time::zone($tz))->format('H:i:s') !== sprintf('%02d:%02d:%02d', ...$clock);
    }

    /** A COUNT sent for an anchored series counts the stored day too, as the stored rule does. */
    private function withoutAnchor(Rule $rule): Rule
    {
        return $this->anchored && $rule->count !== null && $rule->count > 1 ? $rule->withBound($rule->count - 1, null) : $rule;
    }

    /**
     * Where a series whose first day falls in a skipped hour is stored
     * (Zones, DST and All-Day Days): its latest day before that one, of the
     * same rule and phase, on which the clock exists, so the stored start
     * keeps the series' clock (the storage holds an instant and a zone, and
     * the expander takes every day's clock from the start). Null when there
     * is no such day or the rule from it gives other days.
     */
    private function anchorFor(Rule $r1, int $newStart, array $clock): ?int
    {
        if (!$r1->supported()) {
            return null;
        }
        $want = (new Calc($r1, $newStart, $this->tz1, false, $clock, $this->len1))->occurrences(PHP_INT_MAX, 40);
        $p = $r1->periodOf($newStart);
        for ($i = 0; $i < 60; $i++) {
            foreach (array_reverse($r1->periodDays($p)) as [$d]) {
                if ($d >= $newStart) {
                    continue;
                }
                if (self::inGap($d, $clock, $this->tz1)) {
                    return null;
                }
                $stored = $r1->count !== null ? $r1->withBound($r1->count + 1, null) : $r1;
                $got = (new Calc($stored, $d, $this->tz1, false, $clock, $this->len1))->occurrences(PHP_INT_MAX, 41);
                return array_slice($got, 1) === $want ? $d : null;
            }
            $p = $r1->periodPlus($p, -$r1->interval);
        }
        return null;
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
