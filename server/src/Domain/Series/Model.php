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
 * $edit, the occurrence acted on and what the request sets on it:
 *  - acted: the occurrence's key (UTC db string), whichever row id the
 *    request came through; null when the request names none, and then the
 *    edit acts on the series' own values at its first occurrence
 *  - landed: ['start_utc','end_utc','all_day','tzid'] where the request put
 *    that occurrence, or null when it sent no time
 *  - rrule: present when a rule was sent (a string, or null for "does not repeat")
 *  - columns: the content columns the edit sets (title, location...), already normalized,
 *    and the series columns (SERIES_COLUMNS: the trip flag), which no edited day owns
 *  - tags, people: the new tag names or person ids, when sent
 *  - calendarId: a move to another calendar
 *
 * Every part is read as a delta against the occurrence acted on as it
 * shows (occurrence()): its edited row when it has one, the series'
 * values at its key otherwise. Nothing here knows which route the request
 * took.
 *
 * $opts: google (bool), today ('Y-m-d', for the years in words), masterTags,
 * masterPeople, rowTags [id => list], rowPeople [id => list], rowLinks [id =>
 * the ids of the event_links naming the row, as a trip or as a stop].
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

    /** How many events the plan's coming-up list shows. */
    private const NEXT_SHOWN = 4;

    /** The fields that place an occurrence: its day, clock with zone, length and all-day flag. */
    private const PLACEMENT = ['date', 'time', 'length', 'allDay'];

    private const OWN_WORDS = [
        'date' => 'date', 'time' => 'time', 'length' => 'length', 'allDay' => 'all-day setting', 'title' => 'title', 'location' => 'place',
        'description' => 'notes', 'url' => 'link', 'reminders' => 'reminders', 'status' => 'status', 'attendance' => 'answer',
        'tags' => 'tags', 'people' => 'people', 'icon' => 'icon', 'trip' => 'trip',
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
    /** The occurrence acted on: its key, its local day, its edited row (when it has one). */
    private string $acted;
    private int $actedDn0;
    private ?int $actedRowId = null;
    /** @var array{start_utc:string,end_utc:string,all_day:int,tzid:string} where it shows, and where the request put it */
    private array $seen;
    private array $landedCols;
    private string $tz1;
    private bool $allDay1;
    /** @var array{0:int,1:int,2:int} */
    private array $clock1;
    private int $len1;
    private int $landedDn;
    /** Where a request naming no day starts the series, on a day its pattern gives: a bound, the days unmoved. */
    private ?int $startTo = null;
    /** The request put the occurrence acted on somewhere else (any placement delta). */
    private bool $placed = false;
    /** Both sides timed, in different zones: the zone readings apply. */
    private bool $zoneChanged = false;
    /** The rule's delta: null (none), 'bound', 'pattern' (a new rule) or 'none' (does not repeat). */
    private ?string $ruleDelta = null;
    private ?Rule $sentRule = null;
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
        $start = Time::fromDb((string) $master['start_utc'])->setTimezone(Time::zone($this->allDay0 ? 'UTC' : $this->tz0));
        $startDn = Cal::dnOf($start);
        $this->clock0 = $this->allDay0 ? [0, 0, 0] : self::clockOf($start);
        $this->len0 = max(0, Time::fromDb((string) $master['end_utc'])->getTimestamp() - $start->getTimestamp());
        $this->r0 = Rule::parse((string) $master['rrule'], $startDn);
        $this->rule0 = $this->r0;
        $this->readable = $this->r0->kind !== 'unreadable' && Calc::sabreReads((string) $master['rrule']);
        if (!$this->readable) {
            $this->r0->kind = 'unreadable';
        }
        try {
            $this->exdates = \BetterCal\Domain\Recurrence::decodeExdates($master['exdates_json'] ?? null);
        } catch (\Throwable) {
            $this->exdates = [];
        }
        sort($this->exdates);
        // The series as stored, expanded as stored. One stored from the
        // period before a start in a skipped hour (Zones, DST and All-Day
        // Days) is read from its first shown day, its COUNT without the
        // stored day, which holds the clock and isn't one of its days.
        $this->old = Calc::fromRow($master, $this->r0);
        $this->clock0 = $this->old->clock;
        if ($this->old->anchor !== null) {
            $this->anchored = true;
            $this->exdates = array_values(array_diff($this->exdates, [(string) $master['start_utc']]));
            $this->r0 = $this->old->rule;
            $startDn = $this->old->startDn;
        }

        // The occurrence acted on, as it shows (Changing the Series from an
        // Edited Day): the one the request names, its edited row when it has
        // one; a request naming none acts on the series' own values at its
        // first occurrence. Every delta below is measured from it, whichever
        // route the request came by.
        $named = isset($edit['acted']) && $edit['acted'] !== '';
        $this->acted = $named ? (string) $edit['acted'] : $this->old->key($this->old->startDn);
        $this->actedDn0 = $this->old->dnOf($this->acted);
        foreach ($named ? $this->rows : [] as $id => $row) {
            if ((string) $row['recurrence_instance_utc'] === $this->acted) {
                $this->actedRowId = $id;
            }
        }
        $occ = $this->actedRowId !== null ? $this->rows[$this->actedRowId] : self::occurrenceOf($master, [], $this->acted, $this->len0);
        $cols = static fn(array $r): array => [
            'start_utc' => (string) $r['start_utc'], 'end_utc' => (string) $r['end_utc'], 'all_day' => (int) ($r['all_day'] ?? 0), 'tzid' => (string) ($r['tzid'] ?? 'UTC'),
        ];
        $this->seen = $cols($occ);
        $this->landedCols = is_array($edit['landed'] ?? null) ? $cols($edit['landed'] + $this->seen) : $this->seen;

        // The placement deltas (The Function, 3): the days it moved, its
        // clock change, its zone, its length change, its all-day change. All
        // day and zone are deltas, changed or not: the series takes the new
        // value only when the occurrence's changed. A clock change is
        // measured between two clock times (an all-day side has none, and a
        // series made timed takes the clock it lands at); a length change
        // between two of a kind (a series that changes kind takes the length
        // it lands with).
        $sAll = $this->seen['all_day'] === 1;
        $lAll = $this->landedCols['all_day'] === 1;
        $sTz = $sAll ? 'UTC' : $this->seen['tzid'];
        $lTz = $lAll ? 'UTC' : $this->landedCols['tzid'];
        $sAt = Time::fromDb($this->seen['start_utc'])->setTimezone(Time::zone($sTz));
        $lAt = Time::fromDb($this->landedCols['start_utc'])->setTimezone(Time::zone($lTz));
        $sLen = self::lengthOf($this->seen);
        $lLen = self::lengthOf($this->landedCols);
        $this->placed = $this->landedCols['start_utc'] !== $this->seen['start_utc'] || $this->landedCols['end_utc'] !== $this->seen['end_utc']
            || $sAll !== $lAll || (!$sAll && Time::normalizeTzid($sTz) !== Time::normalizeTzid($lTz));
        $this->zoneChanged = !$sAll && !$lAll && Time::normalizeTzid($sTz) !== Time::normalizeTzid($lTz);
        $this->allDay1 = $sAll !== $lAll ? $lAll : $this->allDay0;
        $kindChanges = $this->allDay1 !== $this->allDay0;
        $this->tz1 = match (true) {
            $this->allDay1 => $this->allDay0 ? $this->tz0 : 'UTC',
            $this->allDay0, $this->zoneChanged => $lTz,
            default => $this->tz0,
        };
        $this->len1 = match (true) {
            $kindChanges => $lLen,
            $sAll === $lAll => max(0, $this->len0 + $lLen - $sLen),
            default => $this->len0,
        };
        [$this->landedDn, $this->clock1] = $this->landing(Cal::dnOf($lAt) - Cal::dnOf($sAt), $sAll ? null : self::clockOf($sAt), $lAll ? null : self::clockOf($lAt));

        // Content fields, tags and people: each measured against the
        // occurrence's own value where it owns the field, the series'
        // where it inherits it; a series column against the series' value,
        // which every row has since none owns it.
        $actedRow = $this->actedRowId !== null ? $this->rows[$this->actedRowId] : null;
        $actedOwn = $actedRow !== null ? $this->ownOf($actedRow) : [];
        $valueOf = function (string $col) use ($actedRow, $actedOwn, $master): mixed {
            $field = OwnFields::fieldOf($col);
            return $actedRow !== null && $field !== null && in_array($field, $actedOwn, true) ? ($actedRow[$col] ?? null) : ($master[$col] ?? null);
        };
        $content = [];
        foreach ((array) ($edit['columns'] ?? []) as $col => $value) {
            $field = OwnFields::fieldOf((string) $col);
            if ($field !== null && (string) ($valueOf((string) $col) ?? '') !== (string) ($value ?? '')) {
                $content[$field] = true;
            }
            // A series column has one value for the whole series; the request
            // names it for every row, so it changes when any row differs from it.
            if (isset(self::SERIES_COLUMNS[$col])) {
                $differs = static fn(array $r): bool => (string) ($r[$col] ?? '') !== (string) ($value ?? '');
                if ($differs($master) || array_filter($this->rows, $differs) !== []) {
                    $this->seriesCols[$col] = $value;
                }
            }
        }
        foreach ((array) ($edit['columns'] ?? []) as $col => $value) {
            $field = OwnFields::fieldOf((string) $col);
            if ($field !== null && isset($content[$field])) {
                $this->changed[$field][$col] = $value;
            }
        }
        $setOf = function (string $field, string $key) use ($actedOwn): array {
            return $this->actedRowId !== null && in_array($field, $actedOwn, true)
                ? (array) ($this->opts[$key === 'masterTags' ? 'rowTags' : 'rowPeople'][$this->actedRowId] ?? [])
                : (array) ($this->opts[$key] ?? []);
        };
        if (array_key_exists('tags', $edit) && $edit['tags'] !== null && self::set((array) $edit['tags']) !== self::set($setOf('tags', 'masterTags'))) {
            $this->changed['tags'] = ['names' => array_values((array) $edit['tags'])];
        }
        if (array_key_exists('people', $edit) && $edit['people'] !== null && self::set((array) $edit['people']) !== self::set($setOf('people', 'masterPeople'))) {
            $this->changed['people'] = ['ids' => array_values((array) $edit['people'])];
        }

        // The rule's delta (The Function, 1): an unchanged rule is none, the
        // same pattern with another end a bound, anything else a new rule.
        if (array_key_exists('rrule', $edit)) {
            $sent = $edit['rrule'];
            if ($sent === null || $sent === '') {
                $this->ruleDelta = 'none';
            } else {
                $rebased = $startDn + ($this->landedDn - $this->actedDn0);
                $parsed = $this->withoutAnchor(Rule::parse((string) $sent, $startDn));
                if ($this->readable && $parsed->comparable() === $this->r0->comparable()) {
                    if (!$this->sameBound($parsed)) {
                        $this->sentRule = $parsed;
                        $this->ruleDelta = 'bound';
                    }
                } elseif (!$this->readable && strtoupper(trim((string) $sent)) === strtoupper(trim((string) $master['rrule']))) {
                    // The unreadable rule sent back as it is.
                } else {
                    $this->sentRule = $this->withoutAnchor(Rule::parse((string) $sent, $rebased));
                    $this->ruleDelta = 'pattern';
                }
            }
            // An all-day series has no clock: a rule written for one names
            // none, whichever rule it is (the stored one in readings()).
            if ($this->sentRule !== null && $this->allDay1) {
                $this->sentRule->hour = null;
                $this->sentRule->minute = null;
            }
        }

        // A request naming no day moves the series' start, which is a bound
        // (Bounds: Start, End and Days Outside). Put on a day the series'
        // own pattern gives, it changes nothing about the days: every
        // occurrence is its own image, and the series starts there, so the
        // days before it, and their exceptions, are outside it. Put on a day
        // the pattern doesn't give, the pattern changes too, and the first
        // occurrence moves there as a drag.
        if (!$named && $this->readable && !$this->r0->several && !$this->newRule() && !$this->zoneChanged && $this->allDay1 === $this->allDay0
            && $this->landedDn !== $this->actedDn0 && $this->old->patternHas($this->landedDn)) {
            $this->startTo = $this->landedDn;
            $this->landedDn = $this->actedDn0;
        }
    }

    /**
     * The landed day of the occurrence acted on and the series' new clock,
     * from the days it moved and its clocks before and after (null: all
     * day). The series takes the clock change on its own clock; a clock
     * pushed past midnight carries into the days.
     *
     * @return array{0:int,1:array{0:int,1:int,2:int}}
     */
    private function landing(int $days, ?array $seenClock, ?array $landedClock): array
    {
        if ($this->allDay1) {
            return [$this->actedDn0 + $days, [0, 0, 0]];
        }
        $secs = static fn(array $c): int => $c[0] * 3600 + $c[1] * 60 + $c[2];
        $change = match (true) {
            $landedClock === null => 0,
            $seenClock !== null => $secs($landedClock) - $secs($seenClock),
            $this->allDay0 => $secs($landedClock),
            default => 0,
        };
        $sec = ($this->allDay0 ? 0 : $secs($this->clock0)) + $change;
        $carry = intdiv($sec - ((($sec % 86400) + 86400) % 86400), 86400);
        $sec -= $carry * 86400;
        return [$this->actedDn0 + $days + $carry, [intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60]];
    }

    /** What the edit is, for its words and `direct`: nothing else reads it. */
    private function kind(): string
    {
        return match (true) {
            $this->ruleDelta === 'none' => 'norule',
            $this->ruleDelta === 'pattern' => 'newrule',
            $this->startTo !== null => 'start',
            $this->landedDn !== $this->actedDn0 => 'drag',
            $this->seriesTimeChanged() || $this->placed => 'time',
            $this->ruleDelta === 'bound' => 'bound',
            default => 'fields',
        };
    }

    private function seriesTimeChanged(): bool
    {
        return $this->clock1 !== $this->clock0 || Time::normalizeTzid($this->tz1) !== Time::normalizeTzid($this->tz0)
            || $this->allDay1 !== $this->allDay0 || $this->len1 !== $this->len0;
    }

    private function newRule(): bool
    {
        return $this->ruleDelta === 'pattern' || $this->ruleDelta === 'none';
    }

    private static function lengthOf(array $r): int
    {
        return max(0, Time::fromDb((string) $r['end_utc'])->getTimestamp() - Time::fromDb((string) $r['start_utc'])->getTimestamp());
    }

    /**
     * The occurrence a key names, as it shows: its edited row when it has
     * one, else the series' values at that key.
     */
    private static function occurrenceOf(array $master, array $rows, string $key, int $length): array
    {
        foreach ($rows as $row) {
            if ((string) ($row['recurrence_instance_utc'] ?? '') === $key) {
                return $row;
            }
        }
        $allDay = (int) ($master['all_day'] ?? 0) === 1;
        $end = $allDay ? Time::fromDb($key)->modify('+' . max(1, (int) round($length / 86400)) . ' days') : Time::fromDb($key)->modify('+' . $length . ' seconds');
        return ['start_utc' => $key, 'end_utc' => Time::toDb($end)] + $master;
    }

    /**
     * The occurrence acted on for a request (SeriesPlans reads where it is
     * put from it): the row keyed $key when there is one, else the series'
     * values there. With no key, the series' own values at its first
     * occurrence.
     */
    public static function occurrence(array $master, array $rows, ?string $key): array
    {
        $model = new self($master, $key === null ? [] : $rows, $key === null ? [] : ['acted' => $key], []);
        return self::occurrenceOf($master, $key === null ? [] : $rows, $model->acted, $model->len0);
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
            // A reading's dates for the client: from the day acted on until
            // its first date that differs from the other reading's, and the
            // first earlier date it changes (series-model.md, The Plan).
            $apart = $group['id'] === 'reading' && count($alts) > 1 ? $this->readingApart($alts, (int) array_search($group['selected'], array_column($group['options'], 'id'), true)) : [];
            foreach ($group['options'] as $oi => $option) {
                if (!isset($alts[$oi])) {
                    if ($consequence) {
                        $plan['groups'][$gi]['options'][$oi]['words'] = '';
                    }
                    continue;
                }
                $alt = $alts[$oi];
                $plan['groups'][$gi]['options'][$oi]['next'] = $nexts[$oi];
                $plan['groups'][$gi]['options'][$oi]['bound'] = $alt['plan']['bound'];
                if ($option['id'] !== $group['selected']) {
                    $plan['groups'][$gi]['options'][$oi]['differs'] = $this->differs($alt, $result);
                }
                if (isset($apart[$oi])) {
                    $plan['groups'][$gi]['options'][$oi] += $apart[$oi];
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
     * Each reading's dates against the other's: `dates`, its dates from the
     * day acted on through its first date that differs from the other
     * reading's (three at least; when that is further on, the first three and
     * `then`, the last date they share and its first different one), and
     * `earlier`, the first date before the day acted on where it differs
     * from the series as it is today (for every reading, the preselected one
     * included).
     *
     * @param array<int, array> $alts
     * @return array<int, array{dates: list<string>, then: list<string>, earlier: ?string}>
     */
    private function readingApart(array $alts, int $selected): array
    {
        $seqs = [];
        foreach ($alts as $oi => $alt) {
            $calc = $alt['meta']['calc'] ?? null;
            if (!$calc instanceof Calc) {
                return [];
            }
            [, $periods] = $alt['walk'];
            $seqs[$oi] = ['days' => array_values($periods ?? []), 'calc' => $calc, 'landed' => (int) $alt['meta']['landed']];
        }
        if (!isset($seqs[$selected])) {
            $selected = (int) array_key_first($seqs);
        }
        $iso = fn(Calc $c, int $d): string => Time::iso($c->instant($d)->setTimezone(Time::zone($this->allDay1 ? 'UTC' : $c->tz)));
        // The series as it is today, before the day acted on.
        $oldBefore = $this->readable ? array_values(array_filter($this->walk($this->old, [])[1], fn(int $d): bool => $d < $this->actedDn0)) : [];
        $out = [];
        foreach ($seqs as $oi => $s) {
            $ref = $oi === $selected ? $seqs[array_key_first(array_diff_key($seqs, [$selected => 1]))] : $seqs[$selected];
            $mine = array_values(array_filter($s['days'], static fn(int $d): bool => $d >= $s['landed']));
            $theirs = array_values(array_filter($ref['days'], static fn(int $d): bool => $d >= $ref['landed']));
            $i = 0;
            while ($i < count($mine) && $i < count($theirs) && $mine[$i] === $theirs[$i]) {
                $i++;
            }
            $differs = $i < count($mine) && ($i < count($theirs) || count($theirs) > 0);
            $dates = array_slice($mine, 0, $differs ? max(3, $i + 1) : 3);
            $then = [];
            if ($differs && $i >= 4) {
                $dates = array_slice($mine, 0, 3);
                $then = array_slice($mine, $i - 1, 2);
            }
            // Against the series as it is today, for every reading.
            $earlier = null;
            $before = array_values(array_filter($s['days'], static fn(int $d): bool => $d < $s['landed']));
            $n = max(count($before), count($oldBefore));
            for ($j = 0; $j < $n; $j++) {
                if (($before[$j] ?? null) !== ($oldBefore[$j] ?? null)) {
                    $earlier = Cal::date(min(array_filter([$before[$j] ?? null, $oldBefore[$j] ?? null], static fn($x): bool => $x !== null)));
                    break;
                }
            }
            $out[$oi] = [
                'dates' => array_map(fn(int $d): string => $iso($s['calc'], $d), $dates),
                'then' => array_map(fn(int $d): string => $iso($s['calc'], $d), $then),
                'earlier' => $earlier,
            ];
        }
        return $out;
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
            $rows[] = [$row($r), $set($this->opts['rowTags'][$id] ?? null), $set($this->opts['rowPeople'][$id] ?? null), $set($this->opts['rowLinks'][$id] ?? null)];
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
                    return ['dates' => [self::patternOut($alt['meta']['calc'], $ab[$i])], 'words' => ($i >= 3 ? 'the same ' . $i . ' dates; ' : '') . 'parts on ' . Words::dayMonth(min($ab[$i], $bb[$i]), $this->thisYear)];
                }
            }
            if ($ab !== null && $bb !== null && $ab !== [] && $bb !== []) {
                // One ends where the other goes on: say where each ends.
                $last = $ab[count($ab) - 1];
                $separate = !empty($alt['meta']['separate']) ? ', and ' . Words::dayMonth((int) $alt['meta']['landed'], $this->thisYear) . ' separately' : '';
                return count($ab) < count($bb)
                    ? ['dates' => [self::patternOut($alt['meta']['calc'], $last)], 'words' => 'ends ' . Words::dayMonth($last, $this->thisYear) . $separate]
                    : ['dates' => array_map(static fn(int $d): string => self::patternOut($alt['meta']['calc'], $d), array_slice($ab, count($bb), 3)), 'words' => 'on to ' . Words::dayMonth($last, $this->thisYear) . $separate];
            }
            return ['dates' => [], 'words' => count($ab ?? []) > count($bb ?? []) ? 'more events' : 'fewer events'];
        }
        $same = $ab !== null ? 'the same ' . count($ab) . ' dates; if extended, ' : '';
        $n = min(count($ap), count($bp));
        for ($i = 0; $i < $n; $i++) {
            if ($ap[$i] !== $bp[$i]) {
                return ['dates' => [self::patternOut($alt['meta']['calc'], $ap[$i])], 'words' => $same . 'parts on ' . Words::dayMonth(min($ap[$i], $bp[$i]), $this->thisYear)];
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

        $whole = $this->capability();
        if ($whole !== null) {
            return $this->refused($whole);
        }
        // Where the occurrence acted on lands: its day, the series' clock,
        // and its own columns there.
        $land = ['dn' => $this->landedDn, 'clock' => $this->clock1, 'cols' => $this->landedCols];
        // A zone change: the same clock time, or the same moment. The moment
        // reading is the same request with its wall clock read in the old
        // zone, so the occurrence keeps its instant; its deltas are measured
        // and taken as any others.
        if ($this->zoneChanged && !$this->allDay1) {
            $sTz = $this->seen['tzid'];
            $lTz = $this->landedCols['tzid'];
            $sAt = Time::fromDb($this->seen['start_utc'])->setTimezone(Time::zone($sTz));
            $lAt = Time::fromDb($this->landedCols['start_utc'])->setTimezone(Time::zone($lTz));
            $moment = Calc::at(Cal::dnOf($lAt), self::clockOf($lAt), $sTz)->setTimezone(Time::zone($lTz));
            [$mDn, $mClock] = $this->landing(Cal::dnOf($moment) - Cal::dnOf($sAt), self::clockOf($sAt), self::clockOf($moment));
            if ($mDn !== $land['dn'] || $mClock !== $land['clock']) {
                $momentOption = ['id' => 'moment', 'label' => 'The same moment as now'];
                if ($mDn !== $this->actedDn0 && ($this->r0->fixedDays !== null || !$this->r0->supported())) {
                    $momentOption += ['disabled' => true, 'reason' => self::REASONS[$this->r0->fixedDays ?? 'dayset']];
                }
                $zone = $this->pick('zone', 'Zone', [
                    ['id' => 'clock', 'label' => sprintf('%02d:%02d in %s', $land['clock'][0], $land['clock'][1], self::zoneName($this->tz1))],
                    $momentOption,
                ], 'clock', ['from' => $this->tz0, 'to' => $this->tz1]);
                if ($zone === 'moment') {
                    $land = ['dn' => $mDn, 'clock' => $mClock, 'cols' => [
                        'start_utc' => Time::toDb($moment), 'end_utc' => Time::toDb($moment->modify('+' . self::lengthOf($this->landedCols) . ' seconds')),
                    ] + $this->landedCols];
                }
            }
        }
        $delta = $land['dn'] - $this->actedDn0;
        $readings = $this->readings($delta, $land['dn'], $land['clock']);
        if ($readings === []) {
            return $this->refused('dayset');
        }
        foreach ($readings as $ri => $r) {
            // On Google until 1.5.2: only a reading that keeps the rule text
            // as written (a list only as its whole-pattern shift), whatever
            // moves: a new time written into BYHOUR changes the text too.
            // Marked on the list itself, so a refusal of every reading gives
            // this reason.
            if ($this->google && ($r['rule']->textKey() !== $this->r0->textKey() || $r['id'] === 'member')) {
                $readings[$ri] = ['disabled' => true, 'code' => 'google', 'reason' => self::REASONS['google']] + $r;
            }
        }
        // Each reading's plan on its own: readings with equal plans merge,
        // and one that leaves no events is refused as any refused reading is.
        $readings = $this->merged($readings, $land);
        $options = [];
        $default = null;
        foreach ($readings as $r) {
            $opt = ['id' => $r['id'], 'label' => $r['label'], 'rrule' => $r['rule']->text()];
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
        $pickedId = count($options) > 1 ? $this->pick('reading', $this->kind() === 'newrule' ? 'Edited and skipped days' : 'How it repeats', $options, $default) : $default;
        $reading = null;
        foreach ($readings as $r) {
            if ($r['id'] === $pickedId) {
                $reading = $r;
            }
        }
        $out = $this->map($reading, $land);
        if ($out === null) {
            return $this->refused('empty');
        }
        return $out;
    }

    /**
     * A rule as data for the client's sentences: its text, its start (ISO,
     * in its zone), its zone and whether it is all-day.
     */
    private function ruleData(string $rrule, \DateTimeImmutable $start, string $tz, bool $allDay): array
    {
        return ['rrule' => $rrule, 'start' => Time::iso($start->setTimezone(Time::zone($allDay ? 'UTC' : $tz))), 'tz' => $allDay ? null : $tz, 'allDay' => $allDay];
    }

    /** The bound before the change as data: its kind, count or until, its last day and its events. */
    private function boundFrom(array $oldOcc): array
    {
        $kind = $this->r0->count !== null ? 'count' : ($this->r0->until !== null ? 'until' : 'none');
        $bounded = $kind !== 'none' && $this->readable && $this->old->bounded();
        $last = $bounded ? $this->old->last() : null;
        return ['kind' => $kind] + ($this->r0->count !== null ? ['count' => $this->r0->count] : []) + ($this->r0->until !== null ? ['until' => self::boundOut($this->r0->until)] : [])
            + ['events' => $kind === 'none' ? null : count($oldOcc), 'last' => $last !== null ? self::patternOut($this->old, $last) : null];
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
                'rule' => ['before' => (string) $this->master['rrule'], 'after' => (string) $this->master['rrule'], 'wordsBefore' => $before, 'words' => $before,
                    'from' => $this->ruleData((string) $this->master['rrule'], Time::fromDb((string) $this->master['start_utc']), $this->tz0, $this->allDay0),
                    'to' => $this->ruleData((string) $this->master['rrule'], Time::fromDb((string) $this->master['start_utc']), $this->tz0, $this->allDay0)],
                'start' => self::inZoneOut(Time::fromDb((string) $this->master['start_utc']), $this->tz0),
                'bound' => ['kind' => 'none', 'words' => '', 'events' => null, 'skipped' => 0, 'last' => null, 'separate' => 0],
                'next' => [], 'nextMore' => false, 'counts' => ['events' => null, 'edited' => 0, 'skipped' => 0],
                'groups' => [], 'days' => [], 'losses' => [], 'gains' => [],
                'refusals' => $this->refusals, 'notes' => $this->notes, 'fields' => [],
                'summary' => 'Not changed: ' . ($words ?? self::REASONS[$code] ?? $code),
            ],
            'writes' => null, 'selected' => [], 'nextDates' => [], 'walk' => [null, []],
        ];
    }

    /**
     * Why this edit can't apply to this rule at all, or null (Imported
     * Rules, by Capability): the capability table over the edit's deltas
     * and the rule's parts.
     */
    private function capability(): ?string
    {
        $moves = $this->landedDn !== $this->actedDn0;
        $zone = Time::normalizeTzid($this->tz1) !== Time::normalizeTzid($this->tz0);
        $fieldsOnly = $this->ruleDelta === null && !$this->placed && !$this->seriesTimeChanged();
        if (!$this->readable) {
            return $fieldsOnly ? null : 'unreadable';
        }
        if ($this->newRule()) {
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

    /** The drag table's readings for the days delta (a new rule's own readings when the rule changed). @return list<array<string,mixed>> */
    private function readings(int $delta, int $landedDn, array $clock1): array
    {
        if ($this->newRule()) {
            return $this->newRuleReadings($delta);
        }
        $readings = $delta === 0 ? [Readings::same($this->r0) + ($this->startTo !== null ? ['start' => $this->startTo] : [])] : Readings::forDrag($this->r0, $this->actedDn0, $landedDn, $this->old->startDn);
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

    /**
     * A new pattern sent from the editor (or none): keys on its days stay.
     * When the new pattern is what some drag of the old rule would make
     * (its days shifted: Tuesday to Thursday, the 15th to the 16th, Monday
     * and Wednesday to Tuesday and Thursday), that drag's correspondence by
     * member is offered as a reading too.
     */
    private function newRuleReadings(int $delta): array
    {
        $rebased = $this->old->startDn + $delta;
        $rule = $this->ruleDelta === 'none' ? Rule::parse('FREQ=DAILY;COUNT=1', $rebased) : $this->sentRule;
        if (!$rule->supported() && $this->ruleDelta !== 'none') {
            $start = $rebased;
        } else {
            $start = $this->ruleDelta === 'none' ? $rebased : self::firstOn($rule, $rebased);
        }
        $pattern = $rule->withBound(null, null);
        $probe = new Calc($pattern, $start, $this->tz1, $this->allDay1, $this->clock1, $this->len1, true, $this->old->storedStart());
        $keep = [
            'id' => 'keep', 'label' => 'Keep them where they are, as events of their own', 'rule' => $pattern, 'start' => $start,
            'image' => static fn(int $dn): ?int => $probe->patternHas($dn) ? $dn : null, 'textKept' => false, 'newRule' => $rule,
        ];
        $out = [$keep];
        $map = $this->ruleDelta === 'pattern' ? $this->dragMaking($rule) : null;
        if ($map !== null) {
            array_unshift($out, [
                'id' => 'map', 'label' => 'Move edited and skipped days with their events, as a drag would', 'rule' => $pattern,
                'image' => $map['image'], 'textKept' => false, 'newRule' => $rule,
            ]);
        }
        return $out;
    }

    /**
     * The drag of the old rule, within one of its periods, whose reading is
     * the new pattern, or null: each member's first occurrence dragged to
     * each other day of its period, read by the drag table.
     */
    private function dragMaking(Rule $rule): ?array
    {
        if (!$this->r0->supported() || !$rule->supported() || $rule->kind !== $this->r0->kind || $rule->interval !== $this->r0->interval || $this->r0->kind === 'daily') {
            return null;
        }
        // Members in their order, each one's first occurrence dragged to each
        // day of its period from the period's first: the earliest drag whose
        // reading gives the new pattern's days is "the old one with its days
        // shifted" (Every Kind of Edit, editor save with a new rule).
        $first = [];
        foreach ($this->old->occurrences(PHP_INT_MAX, 4 * max(1, count($this->r0->members)) * max(1, count($this->r0->months))) as $dn) {
            $m = $this->r0->memberOf($dn);
            if ($m !== null) {
                $first[$m] ??= $dn;
            }
        }
        ksort($first);
        $span = match ($this->r0->kind) {
            'weekly' => 7, 'monthly' => 31, 'yearly' => 366, default => 1,
        };
        $hi = $this->old->startDn + 40 * $span * $this->r0->interval;
        $same = function (Rule $a) use ($rule, $hi): bool {
            $from = self::firstOn($a, $this->old->startDn);
            $days = self::patternDays($a, $from, $hi);
            return $days !== [] && $days === self::patternDays($rule, $from, $hi);
        };
        foreach ($first as $dn) {
            $p = $this->r0->periodOf($dn);
            $lo = $this->r0->periodFirst($p);
            $hi = $this->r0->kind === 'weekly' ? $lo + 6 : ($this->r0->kind === 'monthly' ? $lo + Cal::monthLen(...array_slice(Cal::ymd($lo), 0, 2)) - 1 : Cal::dn(Cal::ymd($lo)[0], 12, 31));
            for ($to = $lo; $to <= $hi; $to++) {
                if ($to === $dn) {
                    continue;
                }
                foreach (Readings::forDrag($this->r0, $dn, $to, $this->old->startDn) as $r) {
                    if (empty($r['disabled']) && $same($r['rule'])) {
                        return $r;
                    }
                }
            }
        }
        return null;
    }

    /** The raw COUNT that gives $n scheduled occurrences, the generated skips among the raw ones not counted. */
    private function rawCount(Calc $calc, int $n, array $genSkips): int
    {
        $raw = 0;
        $shown = 0;
        foreach ($calc->occurrences(PHP_INT_MAX, $n + count($genSkips)) as $d) {
            $raw++;
            if (!in_array($d, $genSkips, true)) {
                $shown++;
            }
            if ($shown >= $n) {
                break;
            }
        }
        return max($raw, $n);
    }

    /**
     * A supported rule's days from $from (its start, which carries the
     * phase) through $hi, by its own arithmetic.
     *
     * @return list<int>
     */
    private static function patternDays(Rule $rule, int $from, int $hi): array
    {
        $out = [];
        $p = $rule->periodOf($from);
        for ($i = 0; $i < 20000 && $rule->periodFirst($p) <= $hi; $i++, $p = $rule->periodPlus($p, $rule->interval)) {
            foreach ($rule->periodDays($p) as [$d]) {
                if ($d >= $from && $d <= $hi) {
                    $out[] = $d;
                }
            }
        }
        return $out;
    }

    /** The first day on or after $from that a supported rule gives, counted from $from's period. */
    private static function firstOn(Rule $rule, int $from): int
    {
        $p = $rule->periodOf($from);
        for ($i = 0; $i < 4800; $i++) {
            foreach ($rule->periodDays($p) as [$d]) {
                if ($d >= $from) {
                    return $d;
                }
            }
            $p = $rule->periodPlus($p, $rule->interval);
        }
        return $from;
    }

    /** Readings whose whole plans are equal merge into the first (Finding the Readings); one whose plan leaves no events is refused. */
    private function merged(array $readings, array $land): array
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
            $m = $this->map($r, $land, true);
            [$this->groups, $this->refusals, $this->notes, $this->choices] = $saved;
            if ($m === null) {
                // Nothing left (Bounds): refused, and the rest of the plan stands.
                $out[] = ['disabled' => true, 'code' => 'empty', 'reason' => self::REASONS['empty']] + $r;
                continue;
            }
            $sig = json_encode([$m['walk'], $m['writes']['master']['start_utc'] ?? null, $m['boundKey'], $m['writes']['overrides'], $m['writes']['exdates'], $m['writes']['createEdited'], $m['writes']['detach'], $m['writes']['delete']]);
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
     *
     * Every exception goes the same way: its key's image by member, its new
     * key the new series' key on that day at its place among the day's keys
     * (read from the expander), inside the new series or given an outcome.
     * The occurrence acted on is one of them: what the request set on it
     * (where it was put, the fields sent) are its values now, and like any
     * exception's own values they are kept.
     *
     * @param array{dn:int,clock:array{0:int,1:int,2:int},cols:array} $land
     */
    private function map(array $reading, array $land, bool $probe = false): ?array
    {
        $landedDn = $land['dn'];
        $clock1 = $land['clock'];
        $image = $reading['image'];
        $r1p = $reading['rule']->withBound(null, null);
        $newRule = $this->newRule();
        // The request's placement is the acted day's own (the priority rule):
        // under a new rule it has no special place (Every Kind of Edit).
        // A start moved alone names no day, so no day has a place of its own.
        $actedPlaced = $this->placed && !$newRule && $this->startTo === null;
        $member = fn(int $dn): ?int => $this->r0->supported() ? $this->r0->memberOf($dn) : null;
        $imageOf = fn(int $dn): ?int => $image($dn, $member($dn));
        // Nothing about the expansion changes (the reading moves no day, and
        // the rule, start, zone, all-day flag and clock stay): the new series
        // is the stored one, and each key is its own image.
        $unchanged = $reading['id'] === 'same' && !$newRule && $this->ruleDelta === null && $this->startTo === null && $clock1 === $this->clock0
            && Time::normalizeTzid($this->tz1) === Time::normalizeTzid($this->tz0) && $this->allDay1 === $this->allDay0;

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
        $oldOcc = !$this->readable ? [] : ($this->old->bounded() ? $this->old->all() : $this->old->occurrences($horizon));
        if (!$newRule && !$unchanged && !$this->old->bounded() && $this->r0->supported()) {
            // Sparse losses: keep walking until an image turns up.
            $extra = $horizon;
            $step = $span * 4;
            while (count(array_filter(array_map($imageOf, $oldOcc), static fn($x): bool => $x !== null)) === 0 && $extra < $horizon + 400 * 366) {
                $extra += $step;
                $step *= 2;
                $oldOcc = $this->old->occurrences($extra);
            }
        }
        $images = [];
        foreach ($oldOcc as $dn) {
            $images[$dn] = $imageOf($dn);
        }
        // An unbounded series is read to a horizon, so the new rule's days are
        // only known to correspond to old ones up to there. Read on by the
        // furthest an image moved (and a span more), so every old occurrence
        // whose image falls before the horizon is among those read, and look
        // for gains only up to the horizon: past it a day of the new rule may
        // be the image of an old occurrence not read (Gains at the edges: an
        // unbounded series has no last period).
        $gainTo = PHP_INT_MAX;
        if (!$newRule && !$unchanged && !$this->old->bounded() && $this->readable) {
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
                $images[$dn] = $imageOf($dn);
            }
            $gainTo = $readTo;
        }
        $actedImg = $images[$this->actedDn0] ?? $imageOf($this->actedDn0);
        $imageSet = array_filter($images, static fn($x): bool => $x !== null);
        if ($unchanged) {
            $newStart = $this->old->startDn;
        } elseif (isset($reading['start'])) {
            $newStart = (int) $reading['start'];
        } elseif ($imageSet === []) {
            return null;
        } else {
            $newStart = min($imageSet);
        }
        $anchor = !$this->google && $this->ruleDelta !== 'none';
        $calcP = new Calc($r1p, $newStart, $this->tz1, $this->allDay1, $clock1, $this->len1, $anchor, $this->old->storedStart());
        $latest = $imageSet === [] ? null : max($imageSet);

        // Gains: occurrences of the new rule that are no old one's image.
        $edgeGains = [];
        $gains = [];
        $edgeAt = [];
        if (!$newRule && !$unchanged && $imageSet !== []) {
            $to = min((int) $latest, $gainTo);
            $inImages = array_flip(array_values($imageSet));
            $gainSet = [];
            foreach ($calcP->occurrences($to) as $d) {
                if (!isset($inImages[$d])) {
                    $gainSet[$d] = true;
                }
            }
            // A gain at an edge is the image of a day of the old pattern
            // outside the series, before its start or after its end (Bounds:
            // the first or last k periods of a member moved k periods); any
            // other gain is a day the new form gives where the old gave none.
            $edgeAt = $this->edgeSources($gainSet, $image, $images);
            foreach (array_keys($gainSet) as $d) {
                if (isset($edgeAt[$d])) {
                    $edgeGains[] = $d;
                } else {
                    $gains[] = $d;
                }
            }
        }
        $genSkips = [];
        if ($edgeGains !== [] && !$this->google) {
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
            ], 'without', ['dates' => array_map(static fn(int $d): string => self::patternOut($calcP, $d), $edgeGains), 'side' => count($sides) > 1 ? 'both' : $sides[0]]);
            if ($edge === 'without') {
                $genSkips = $edgeGains;
            }
        }

        // The bound.
        $separate = false;
        $r1 = $r1p;
        if ($unchanged) {
            $r1 = $this->r0;
        } elseif ($newRule) {
            $nr = $reading['newRule'];
            $r1 = $r1p->withBound($nr->count, $nr->until);
        } elseif ($this->ruleDelta === 'bound') {
            // A sent bound is applied as sent; a sent COUNT counts scheduled
            // occurrences, the skips this change generates not among them.
            $sc = $this->sentRule->count;
            $r1 = $sc !== null ? $r1p->withBound($this->rawCount($calcP, $sc, $genSkips), null) : $r1p->withBound(null, $this->sentRule->until);
        } elseif ($this->r0->count !== null || $this->r0->until !== null) {
            $kept = $r1p->withBound($this->r0->count, $this->r0->until);
            $keptCalc = new Calc($kept, $newStart, $this->tz1, $this->allDay1, $clock1, $this->len1, $anchor, $this->old->storedStart());
        }
        $instants = static fn(Calc $c): array => array_column($c->keys(PHP_INT_MAX), 1);
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
            $rawN = $this->rawCount($calcP, $wantN, $genSkips);
            $under = $calcP->occurrences(PHP_INT_MAX, $rawN);
            $lastUnderCount = $under === [] ? null : $under[count($under) - 1];
            $untilAt = fn(int $d): string => $this->allDay1 ? str_replace('-', '', Cal::date($d)) : $calcP->instant($d)->format('Ymd\THis\Z');
            if ($rawN === $endCount) {
                $r1 = $this->r0->count !== null ? $r1p->withBound($rawN, null) : $r1p->withBound(null, $untilAt((int) $latest));
            } else {
                // A count that would end before the day acted on keeps it
                // separately (The Day Acted On Comes First).
                $splits = $actedPlaced && $actedImg !== null && $actedImg > (int) $lastUnderCount;
                $countLabel = 'Keep ' . Words::events($wantN) . ($splits ? ' + this one on its own' : '');
                $default = $this->r0->count !== null && !$splits ? 'count' : 'end';
                $boundOptions = [['id' => 'count', 'label' => $countLabel], ['id' => 'end', 'label' => 'Keep the end']];
                if ($default === 'end') {
                    $boundOptions = array_reverse($boundOptions);
                }
                $bound = $this->pick('bound', 'Bound', $boundOptions, $default, ['count' => $wantN, 'splits' => $splits, 'counted' => $this->r0->count !== null]);
                if ($bound === 'count') {
                    $r1 = $r1p->withBound($rawN, null);
                } else {
                    $r1 = $this->r0->count !== null ? $r1p->withBound($endCount, null) : $r1p->withBound(null, $untilAt((int) $latest));
                }
            }
        }
        $calc1 = $unchanged ? $this->old : new Calc($r1, $newStart, $this->tz1, $this->allDay1, $clock1, $this->len1, $anchor, $this->old->storedStart());
        if ($calc1 !== $this->old && $calc1->signature() === $this->old->signature()) {
            // The same stored series: the same expansion, so the same keys.
            $calc1 = $this->old;
        }
        $all1 = $this->readable && $calc1->bounded() ? $calc1->all() : null;
        $untilAt1 = $calc1->untilInstant();
        // An end before the start leaves nothing (the expander would still show the start).
        if (!$unchanged && (($all1 !== null && count(array_diff($all1, $genSkips)) === 0) || ($untilAt1 !== null && $untilAt1 < $calc1->instant($newStart)))) {
            return null;
        }
        $inside = fn(?int $d): bool => $d !== null && $calc1->isOccurrence($d);
        // An old key's new key (Correspondence, by Member): the new series'
        // key on its image day, at its place among that day's keys (a rule
        // several times a day has several), as the expander gives it; null
        // when the new series has none there. In the same expansion on the
        // same day, that is the key itself.
        $newKey = function (string $key, ?int $img) use ($calc1): ?string {
            if ($img === null) {
                return null;
            }
            $odn = $this->old->dnOf($key);
            if ($calc1 === $this->old && $img === $odn) {
                return $key;
            }
            $on = $calc1->keysOn($img);
            if ($on === []) {
                return null;
            }
            $at = array_search($key, $this->old->keysOn($odn), true);
            return $on[$at === false ? 0 : $at] ?? null;
        };
        // A generated skip past the new end needs nothing (Bounds, gains at the edges).
        $genSkips = array_values(array_filter($genSkips, $inside));
        // The occurrence acted on, where the request put it: in the series
        // on its image, or, when that falls outside, an event of its own
        // (never deleted: it is where the person just put it).
        // On Google no exception gets an outcome, the day acted on included
        // (Google, as a Contract).
        if (!$this->google && $actedPlaced && $newKey($this->acted, $actedImg) === null) {
            $separate = true;
        }

        // Skipped days.
        $newExdates = [];
        $days = [];
        if (!$this->google) {
            foreach ($this->exdates as $key) {
                $odn = $this->old->dnOf($key);
                $img = $imageOf($odn);
                $nk = $newKey($key, $img);
                if ($nk !== null) {
                    $newExdates[] = $nk;
                    $days[] = ['key' => $key, 'newKey' => $nk, 'kind' => 'skipped', 'outcome' => $nk === $key ? 'stays' : 'moves', 'shows' => $this->identityOut($key), 'to' => self::patternOut($calc1, (int) $img)]
                        + Words::dayParts(Words::date($odn, $this->thisYear), $img === $odn ? 'stays skipped' : 'moves to ' . Words::date((int) $img, $this->thisYear), ' ', 'Skipped ');
                } else {
                    // A skip belongs to the occurrence it removed: when the
                    // new series has no such occurrence (no image, or one
                    // before its start or past its end), it goes with it.
                    $side = $img === null ? null : ($img < $newStart ? 'start' : 'end');
                    $days[] = ['key' => $key, 'newKey' => null, 'kind' => 'skipped', 'outcome' => 'dropped', 'shows' => $this->identityOut($key), 'why' => $img === null ? 'noday' : 'outside']
                        + ($side !== null ? ['side' => $side] : [])
                        + Words::dayParts(Words::date($odn, $this->thisYear), match ($side) {
                            null => 'is forgotten: the new series has no ' . Words::date($odn, $this->thisYear),
                            'start' => 'is no longer needed: it is before the new start',
                            default => 'is no longer needed: it is past the new end',
                        }, ' ', 'Skipped ');
                }
            }
            foreach ($genSkips as $g) {
                $nk = $calc1->key($g);
                $newExdates[] = $nk;
                $days[] = ['key' => null, 'newKey' => $nk, 'kind' => 'skipped', 'outcome' => 'generated', 'to' => self::patternOut($calc1, $g), 'side' => $edgeAt[$g] ?? 'start']
                    + Words::dayParts(Words::date($g, $this->thisYear), ($edgeAt[$g] ?? 'start') === 'start' && $newStart === $this->old->startDn
                        ? 'skipped, so the series starts as before'
                        : 'skipped: no event of the series was on it');
            }
        }
        $newExdates = array_values(array_unique($newExdates));
        sort($newExdates);

        // Edited days: each on its image's key, or outside. The row of the
        // occurrence acted on is the row as the request left it (where it
        // was put), its placement fields its own where that isn't what it
        // would inherit there.
        $placeRows = [];
        $newKeys = [];
        $outsideRows = [];
        /** @var array<int,array> $asSet the acted row as the request left it */
        $asSet = [];
        $ownNow = [];
        if (!$this->google) {
            foreach ($this->rows as $id => $row) {
                $key = (string) $row['recurrence_instance_utc'];
                $odn = $this->old->dnOf($key);
                $img = $imageOf($odn);
                $nk = $newKey($key, $img);
                $ownNow[$id] = $this->ownOf($row);
                if ($id === $this->actedRowId && $actedPlaced) {
                    if ($nk === null) {
                        continue;
                    }
                    $asSet[$id] = array_merge($row, $land['cols']);
                    $ownNow[$id] = OwnFields::normalize(array_merge(array_diff($ownNow[$id], self::PLACEMENT), $this->placementOwn($asSet[$id], $nk)));
                }
                $newKeys[$id] = $nk;
                if ($nk !== null) {
                    $placeRows[$id] = (int) $img;
                } else {
                    $outsideRows[$id] = $img;
                }
            }
        }
        // The occurrence acted on with no row of its own is an exception like
        // any other, its row the occurrence as it showed: where the request
        // put it is its placement, its own where that isn't what its image
        // gives (a day in a skipped hour, whose key the expander shifts). A
        // placement of its own makes it an edited day, so the plan creates its
        // row (Changing the Series from an Edited Day, 3).
        $createEdited = null;
        if (!$this->google && $actedPlaced && $this->actedRowId === null && ($nk = $newKey($this->acted, $actedImg)) !== null) {
            $occ = ['override_fields' => '[]'] + self::occurrenceOf($this->master, [], $this->acted, $this->len0);
            $put = array_merge($occ, $land['cols']);
            $own = OwnFields::normalize($this->placementOwn($put, $nk));
            if ($own !== []) {
                [$cs, $ce, $cAll, $cTz] = $this->place($put, $own, (int) $actedImg, $nk, false, (int) $actedImg, $clock1, $clock1);
                $createEdited = [
                    'recurrence_instance_utc' => $nk, 'start_utc' => Time::toDb($cs), 'end_utc' => Time::toDb($ce),
                    'all_day' => $cAll ? 1 : 0, 'tzid' => $cTz, 'override_fields' => OwnFields::encode($own),
                ];
                $putAt = $this->planDn($cs, $cAll, $cTz);
                $days[] = ['key' => $this->acted, 'newKey' => $nk, 'kind' => 'acted', 'outcome' => 'moves', 'kept' => $own, 'takes' => [], 'shows' => Cal::date($putAt), 'to' => Cal::date($putAt)]
                    + Words::dayParts('This one, ' . Words::date($putAt, $this->thisYear), 'keeps where it was put', ', ');
            }
        }
        $outside = 'keep';
        if ($outsideRows !== []) {
            $one = count($outsideRows) === 1;
            $outside = $this->pick('outside', 'Edited days outside the new series', [
                ['id' => 'keep', 'label' => $one ? 'Keep it as an event of its own' : 'Keep them as events of their own'],
                ['id' => 'delete', 'label' => $one ? 'Delete it' : 'Delete them'],
            ], 'keep', ['count' => count($outsideRows)] + ($one ? ['day' => self::keyOut((string) $this->rows[array_key_first($outsideRows)]['recurrence_instance_utc'])] : []));
        }
        // Days on a date of their own from before this edit: kept, or moved
        // with their events this once. The acted day's date is the one the
        // request just set, so it is no such day.
        $movedRows = [];
        foreach ($placeRows as $id => $img) {
            if (!isset($asSet[$id]) && in_array('date', $ownNow[$id], true) && $img !== $this->old->dnOf((string) $this->rows[$id]['recurrence_instance_utc'])) {
                $movedRows[] = $id;
            }
        }
        $moveOwnDates = false;
        if ($movedRows !== []) {
            $one = count($movedRows) === 1 ? $this->rows[$movedRows[0]] : null;
            // Each choice's date is where the day comes out, placed as the
            // commit places it (kept or moved), by the zone rule (planDn).
            $dates = [];
            if ($one !== null) {
                $oneId = $movedRows[0];
                foreach (['keep' => false, 'move' => true] as $choice => $move) {
                    [$s, , $all, $sTz] = $this->place($one, $ownNow[$oneId], (int) $placeRows[$oneId], (string) $newKeys[$oneId], $move, $this->old->dnOf((string) $one['recurrence_instance_utc']), $clock1, $this->clock0);
                    $dates[$choice] = $this->placementOut(self::rowOf($s, $all, $sTz), $this->displayZone());
                }
            }
            $moveOwnDates = $this->pick('movedDays', 'Days moved on their own', [
                ['id' => 'keep', 'label' => $one !== null ? 'Keep ' . Words::date(self::dayOf($dates['keep']), $this->thisYear) : 'Keep their dates'] + ($one !== null ? ['date' => $dates['keep']['date']] : []),
                ['id' => 'move', 'label' => $one !== null ? 'Move to ' . Words::date(self::dayOf($dates['move']), $this->thisYear) : 'Move them with their events'] + ($one !== null ? ['date' => $dates['move']['date']] : []),
            ], 'keep', ['count' => count($movedRows)] + ($one !== null ? ['day' => self::keyOut((string) $one['recurrence_instance_utc'])] : [])) === 'move';
        }

        // Content fields: inherited ones follow; own ones are a choice, except
        // on the occurrence acted on, whose value is the one the request set.
        $give = [];
        foreach ($this->changed as $field => $cols) {
            if ($this->google) {
                break;
            }
            $owners = array_values(array_filter(array_keys($placeRows), fn(int $id): bool => $id !== $this->actedRowId && in_array($field, $ownNow[$id], true)));
            if ($owners === []) {
                continue;
            }
            $word = self::OWN_WORDS[$field];
            $one = count($owners) === 1;
            $give[$field] = $this->pick('own' . ucfirst($field), ($one ? '1 edited day keeps its own ' : count($owners) . ' edited days keep their own ') . $word, [
                ['id' => 'keep', 'label' => $one ? 'Keep its own ' . $word : 'Keep their own ' . $word],
                ['id' => 'give', 'label' => $one ? 'Give it the new ' . $word . ' too' : 'Give them the new ' . $word . ' too'],
            ], 'keep', ['field' => $field, 'count' => count($owners), 'days' => array_map(fn(int $id): string => self::keyOut((string) $this->rows[$id]['recurrence_instance_utc']), $owners)]
                + ($field === 'title' ? ['value' => (string) ($cols['title'] ?? '')] : [])) === 'give';
        }

        // The series as stored: a first day in a skipped hour is stored from
        // the day before it, skipped, so the start keeps the clock (Calc).
        $stored = $calc1->storedRule();
        $start1 = $this->ruleDelta === 'none' ? Calc::at($newStart, $clock1, $this->allDay1 ? 'UTC' : $this->tz1, Time::fromDb($this->landedCols['start_utc'])) : $calc1->storedStart();
        if ($calc1->anchor !== null && $calc1 !== $this->old) {
            $newExdates[] = Time::toDb($start1);
            sort($newExdates);
            $days[] = ['key' => null, 'newKey' => Time::toDb($start1), 'kind' => 'skipped', 'outcome' => 'anchor'] + Words::dayParts('', '');
        } elseif ($calc1 === $this->old && $this->anchored) {
            $stored = $this->rule0;
            $start1 = Time::fromDb((string) $this->master['start_utc']);
            $newExdates[] = (string) $this->master['start_utc'];
            sort($newExdates);
            $days[] = ['key' => (string) $this->master['start_utc'], 'newKey' => (string) $this->master['start_utc'], 'kind' => 'skipped', 'outcome' => 'anchor'] + Words::dayParts('', '');
        }

        // The writes.
        $masterCols = [];
        // A rule equal to the stored one, pattern and bound, is written as
        // it was stored (The Function, 1); a pattern whose text is the stored
        // one's keeps it as written, with its new bound.
        $newRruleText = match (true) {
            $this->ruleDelta === 'none' => null,
            $stored->comparable() === $this->rule0->comparable() && $stored->count === $this->rule0->count && $stored->until === $this->rule0->until => (string) $this->master['rrule'],
            $stored->textKey() === $this->rule0->textKey() => $this->rule0->withBound($stored->count, $stored->until)->text(),
            default => $stored->text(),
        };
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
        if ($this->ruleDelta === 'none') {
            $masterCols['rrule'] = null;
            $masterCols['exdates_json'] = null;
        }
        foreach ($this->changed as $field => $cols) {
            if ($field === 'tags' || $field === 'people') {
                continue;
            }
            foreach ($cols as $col => $v) {
                $set($col, $v);
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
        $rejoined = [];
        $create = [];
        $tags = isset($this->changed['tags']) ? ['master' => $this->changed['tags']['names']] : [];
        $people = isset($this->changed['people']) ? ['master' => $this->changed['people']['ids']] : [];
        $title = (string) ($this->master['title'] ?? '');
        $seriesTime = $this->seriesTimeChanged();
        $edited = array_merge(array_keys($this->changed), array_keys(array_filter([
            'date' => $landedDn !== $this->actedDn0,
            'time' => $clock1 !== $this->clock0 || Time::normalizeTzid($this->tz1) !== Time::normalizeTzid($this->tz0),
            'length' => $this->len1 !== $this->len0,
            'allDay' => $this->allDay1 !== $this->allDay0,
        ])));
        foreach ($placeRows as $id => $img) {
            $row = $this->rows[$id];
            $ownBefore = $this->ownOf($row);
            $own = $ownNow[$id];
            $newKey = (string) $newKeys[$id];
            // Own values kept, inherited ones from the series at the new key;
            // the acted row's own values are where the request put it, which
            // is already on its image.
            $actedRow = isset($asSet[$id]);
            [$start, $end, $allDay, $tz] = $actedRow
                ? $this->place($asSet[$id], $own, (int) $img, $newKey, false, (int) $img, $clock1, $clock1)
                : $this->place($row, $own, (int) $img, $newKey, $moveOwnDates, $this->old->dnOf((string) $row['recurrence_instance_utc']), $clock1, $this->clock0);
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
                // The occurrence acted on takes the request's values (Own
                // Fields: an edit for all events from that day clears the
                // fields it sets); the rest by what they own.
                $inherits = $id === $this->actedRowId || !in_array($field, $own, true) || !empty($give[$field]);
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
            $odn = $this->old->dnOf((string) $row['recurrence_instance_utc']);
            if (!$this->google && OwnFields::normalize($ownAfter) === []) {
                // Left with nothing of its own, no field and no trip (a row
                // exists for what refers to it too), it is an ordinary
                // occurrence again (Own Fields): its row goes, its key in
                // Undo, and the series shows the day at its new key.
                $delete[] = $id;
                $rejoined[$id] = true;
                unset($tags[$id], $people[$id]);
                $name = in_array('title', $ownBefore, true) ? "'" . (string) $row['title'] . "' " : '';
                $shows = $this->planDn($start, $allDay, $tz);
                $days[] = ['key' => (string) $row['recurrence_instance_utc'], 'newKey' => $newKey, 'kind' => 'edited',
                    'title' => (string) $row['title'], 'outcome' => 'rejoins', 'shows' => Cal::date($this->rowDn($row)), 'to' => Cal::date($shows)]
                    + Words::dayParts($name . Words::date($odn, $this->thisYear), 'goes back to the series, on ' . Words::date($shows, $this->thisYear))
                    + ['kept' => [], 'takes' => OwnFields::normalize($edited)];
                continue;
            }
            if ($cols !== []) {
                $overrideWrites[$id] = $cols;
            }
            // Its outcome is its instant and kind compared, never its dates.
            $moved = Time::toDb($start) !== (string) $row['start_utc'] || Time::toDb($end) !== (string) $row['end_utc'] || $allDay !== ((int) $row['all_day'] === 1);
            $name = in_array('title', $ownBefore, true) ? "'" . (string) $row['title'] . "' " : '';
            $keeps = array_values(array_intersect(['title', 'location', 'description', 'url', 'icon', 'tags', 'people', 'reminders', 'status', 'attendance', 'trip'], $ownAfter));
            $keepWords = array_map(static fn(string $f): string => self::OWN_WORDS[$f], $keeps);
            // A timed day at its own time while the series' time changes says
            // the time it keeps ("keeps its own time at 09:00", the examples' all-day case).
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
            $taken = array_values(array_intersect(array_keys($this->changed), array_diff($ownBefore, $ownAfter)));
            if (in_array('title', $taken, true)) {
                $keepsWords .= ", takes '" . (string) ($this->changed['title']['title'] ?? '') . "'";
                $taken = array_values(array_diff($taken, ['title']));
            }
            if ($taken !== []) {
                $keepsWords .= ', takes the new ' . Words::andList(array_map(static fn(string $f): string => self::OWN_WORDS[$f], $taken));
            }
            // The days it showed on before and after, and where it shows after
            // as an instant, each by the zone rule (planDn): a date and a clock
            // of the same instant in one zone.
            $shownBefore = $this->rowDn($row);
            $localStart = $start->setTimezone($this->zoneFor($allDay, $tz));
            $newShown = Cal::dnOf($localStart);
            if (in_array('date', $ownBefore, true) && $wasShown !== $odn) {
                // Where it shows after: another day, the same day at another time, or unchanged.
                $clockMoved = !$allDay && Time::toDb($start) !== (string) $row['start_utc'];
                $where = $newShown !== $shownBefore ? 'moves to ' . Words::date($newShown, $this->thisYear) : ($clockMoved ? 'moves to ' . $localStart->format('H:i') : 'stays there');
                $said = Words::dayParts($name . Words::date($odn, $this->thisYear) . ', moved to ' . Words::date($shownBefore, $this->thisYear), $where . $keepsWords, ': ');
            } else {
                // A day that keeps its date and changes its time says the time.
                $to = $newShown === $shownBefore && !$allDay ? $localStart->format('H:i') : Words::date($newShown, $this->thisYear);
                $said = Words::dayParts($name . Words::date($odn, $this->thisYear), ($moved ? 'moves to ' . $to : 'stays') . $keepsWords);
            }
            // As data beside the words: the own fields it keeps, and the fields
            // this edit changes that it takes from the series.
            $days[] = ['key' => (string) $row['recurrence_instance_utc'], 'newKey' => $newKey, 'kind' => 'edited',
                'title' => (string) $row['title'], 'outcome' => $moved ? 'moves' : 'stays',
                'shows' => Cal::date($shownBefore), 'to' => Cal::date($newShown)]
                // Where it shows after, as an instant (timed days): beside newKey, the series' own time
                // there; in an all-day plan, in its own zone, named.
                + ($allDay ? [] : ['at' => Time::iso($localStart)] + $this->tzBeside(false, $tz)) + $said + [
                'kept' => OwnFields::normalize($ownAfter), 'takes' => OwnFields::normalize(array_diff($edited, $ownAfter))];
        }
        foreach ($outsideRows as $id => $img) {
            $row = $this->rows[$id];
            $shown = $this->rowDn($row);
            $label = "'" . (string) $row['title'] . "' " . Words::date($shown, $this->thisYear);
            if ($outside === 'delete') {
                $delete[] = $id;
                $days[] = ['key' => (string) $row['recurrence_instance_utc'], 'newKey' => null, 'kind' => 'edited', 'title' => (string) $row['title'], 'outcome' => 'deleted', 'shows' => Cal::date($shown)] + Words::dayParts($label, 'is deleted') + ['kept' => $this->ownOf($row)];
            } else {
                $detach[$id] = [];
                $days[] = ['key' => (string) $row['recurrence_instance_utc'], 'newKey' => null, 'kind' => 'edited', 'title' => (string) $row['title'], 'outcome' => 'separate', 'shows' => Cal::date($shown), 'to' => Cal::date($shown)] + Words::dayParts($label, 'becomes an event of its own') + ['kept' => $this->ownOf($row)];
            }
            unset($img);
        }
        if ($separate) {
            // Where the request put it, as an event of its own.
            $placed = [
                'start_utc' => $land['cols']['start_utc'], 'end_utc' => $land['cols']['end_utc'],
                'all_day' => $land['cols']['all_day'], 'tzid' => $land['cols']['all_day'] === 1 ? 'UTC' : $land['cols']['tzid'],
            ];
            if ($this->actedRowId !== null) {
                $detach[$this->actedRowId] = $placed;
            } else {
                $create[] = $placed;
            }
            $days[] = ['key' => $this->acted, 'newKey' => null, 'kind' => $this->actedRowId !== null ? 'edited' : 'acted', 'outcome' => 'separate', 'to' => Cal::date($landedDn)]
                + ($this->actedRowId !== null ? ['title' => (string) $this->rows[$this->actedRowId]['title']] : [])
                + Words::dayParts('This one, ' . Words::date($landedDn, $this->thisYear), 'becomes an event of its own', ', ');
        }

        // Words and the rest of the plan.
        $wordsBefore = Words::rule($this->r0, $this->allDay0 ? null : $this->clock0);
        $words = $this->ruleDelta === 'none' ? 'Does not repeat' : Words::rule($r1p, $this->allDay1 ? null : $clock1);
        $walk = $this->readable ? $this->walk($calc1, $genSkips) : [null, []];
        $boundKey = [$r1->count, $r1->until];
        // Skipped days count toward COUNT (RFC 5545 3.8.5.3): the line counts
        // the series' scheduled occurrences (a skip this change generates is
        // no event of the series) and says how many of them were skipped.
        $genKeys = array_flip(array_map(static fn(int $d): string => $calc1->key($d), $genSkips));
        $personSkipped = 0;
        $allKeys = $all1 !== null ? array_column($calc1->keys(PHP_INT_MAX), 1) : [];
        if ($all1 !== null) {
            $skipSet = array_flip($newExdates);
            foreach ($allKeys as $k) {
                if (isset($skipSet[$k]) && !isset($genKeys[$k])) {
                    $personSkipped++;
                }
            }
        }
        // Losses: old occurrences whose image isn't in the new series. One
        // whose exception gets an outcome of its own (an event of its own,
        // deleted) is named by that outcome instead.
        $outcomeDays = [];
        foreach ($outsideRows as $id => $_) {
            $outcomeDays[$this->old->dnOf((string) $this->rows[$id]['recurrence_instance_utc'])] = true;
        }
        if ($separate) {
            $outcomeDays[$this->actedDn0] = true;
        }
        // A removed day is no event to lose: its skip's outcome names it.
        foreach ($this->exdates as $key) {
            $outcomeDays[$this->old->dnOf($key)] = true;
        }
        $lossDays = [];
        $losses = [];
        foreach ($images as $dn => $img) {
            if ($newRule || isset($outcomeDays[$dn]) || ($img !== null && $calc1->isOccurrence($img))) {
                continue;
            }
            if ($img !== null) {
                $lossDays[] = $img;
            }
            $losses[] = ['date' => $this->identityOut($this->old->key($dn)), 'words' => Words::dayMonth($dn, $this->thisYear) . ($img === null ? ' has no place in the new series' : ($img < $newStart ? ' falls before the new start' : ' falls after the new end'))];
        }
        [$boundKind, $boundWords, $boundData] = $this->boundWords($r1, $calc1, $oldOcc, $separate, $outside === 'keep' ? count($outsideRows) : 0, count($genSkips), $personSkipped, $lossDays, $allKeys);
        // A gain past the new end is no gain (P2-6: "Keep 6 events" adds nothing after its sixth).
        $gainList = array_map(fn(int $d): array => ['date' => self::patternOut($calc1, $d), 'words' => 'Adds ' . Words::dayMonth($d, $this->thisYear)],
            array_values(array_filter(array_merge($gains, $genSkips === [] ? $edgeGains : []), $inside)));
        // The next events from the day acted on: from where it lands, or from
        // where the request put it when that is earlier (an own date, or a
        // zone kept at its moment), so nothing between them goes unlisted.
        $putDn = $this->placed ? self::dayOf($this->placementOut($this->landedCols, $this->displayZone())) : $landedDn;
        // A rule the expander can't read lists nothing, and goes on past
        // that unless it has an end.
        [$next, $nextMore] = $this->readable ? $this->next($calc1, $newExdates, array_diff_key($placeRows, $rejoined), $overrideWrites, min($landedDn, $putDn), $separate ? $land['cols'] : null, $landedDn, $createEdited === null ? [] : [$createEdited]) : [[], $boundKind === 'none'];
        $nextDates = array_map(static fn(array $n): string => $n['start'], $next);
        if ($this->google) {
            $this->notes[] = ['kind' => 'google', 'words' => self::GOOGLE_NOTE];
        }
        if ($this->readable) {
            $this->clockNotes($calc1, $landedDn);
        }
        $fieldsWords = [];
        foreach ($this->changed as $field => $cols) {
            $fieldsWords[] = ['field' => $field, 'words' => $this->fieldWords($field, $cols)];
        }
        // A series column changes as one field; when the rows differ in it,
        // the line says the edited days take it too, and it isn't direct
        // (the person sees that more than the series changes).
        $mixed = false;
        $yesNo = static fn(mixed $x): string => (int) $x === 1 ? 'yes' : 'no';
        foreach ($this->seriesCols as $col => $v) {
            $was = (string) ($this->master[$col] ?? '');
            $differ = array_filter($this->rows, static fn(array $r): bool => (string) ($r[$col] ?? '') !== $was);
            $mixed = $mixed || $differ !== [];
            // Before: one value for every row, or one that differs by day. As
            // data beside the words: the master's value, the new one, and
            // whether the rows differed, for the client's own sentence.
            $fieldsWords[] = ['field' => $col, 'words' => self::SERIES_COLUMNS[$col] . ': ' . ($differ !== [] ? $yesNo($was) . ' on some days, now ' . $yesNo($v) . ' for every event' : $yesNo($was) . ', now ' . $yesNo($v)),
                'was' => (int) $was === 1 ? 1 : 0, 'now' => (int) $v === 1 ? 1 : 0, 'mixed' => $differ !== []];
        }
        $groupsOut = $this->groups;
        // Direct (The API): field-only, nothing placed, no group, and no
        // edited day owning a changed field, the one acted on included.
        $actedOwns = $this->actedRowId !== null && array_intersect(array_keys($this->changed), $this->ownOf($this->rows[$this->actedRowId])) !== [];
        $direct = $this->kind() === 'fields' && $groupsOut === [] && $this->refusals === [] && !isset($this->edit['calendarId']) && !$mixed && !$actedOwns;
        $kind = $this->kind();
        $verb = match ($kind) {
            'drag', 'start' => 'Moved', 'newrule', 'norule' => 'Changed how ' . $title . ' repeats', default => 'Changed',
        };
        $startAlone = $kind === 'start' && $words === $wordsBefore;
        $summary = ($kind === 'newrule' || $kind === 'norule' ? $verb : $verb . ' ' . $title) . ' (all events): '
            . ($startAlone
                ? 'starts ' . Words::dayMonth($newStart, $this->thisYear) . ' (was ' . Words::dayMonth($this->old->startDn, $this->thisYear) . '), ' . Words::lower($words) . ', as before'
                : (in_array($kind, ['drag', 'start', 'newrule', 'norule'], true) || $words !== $wordsBefore ? Words::lower($wordsBefore) . ', now ' . Words::lower($words) . ', from ' . Words::dayMonth($newStart, $this->thisYear) : Words::lower($words) . ', as before'))
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
                . Words::dayMonth($separate && $d['key'] === $this->acted ? $landedDn : ($shownOf[(string) $d['key']] ?? $this->old->dnOf((string) $d['key'])), $this->thisYear),
                array_filter($days, static fn(array $d): bool => $d['outcome'] === 'separate' && $d['kind'] === 'edited')));
            $summary .= ' ' . ($detachCount === 1 ? 'One edited day became an event of its own: ' : Words::count($detachCount) . ' edited days became events of their own: ') . Words::andList($names) . '.';
            // The dialog's sentence (series-model.md, The Plan, line 7).
            $ownership = ($detachCount === 1 ? 'One edited day becomes an event of its own: ' : Words::count($detachCount) . ' edited days become events of their own: ') . Words::andList($names) . '.';
        }

        // Keys in the plan read as instants: 2027-04-14T02:00:00Z.
        $iso = static fn(?string $k): ?string => $k === null ? null : self::keyOut($k);
        $days = array_map(static fn(array $d): array => ['key' => $iso($d['key']), 'newKey' => $iso($d['newKey'])] + $d + ['kept' => [], 'takes' => []], $days);
        $selected = [];
        foreach ($groupsOut as $g) {
            $selected[$g['id']] = $g['selected'];
        }
        $startIso = Time::iso($start1->setTimezone(Time::zone($this->allDay1 ? 'UTC' : $this->tz1)));
        $plan = [
            'scope' => 'all',
            'direct' => $direct,
            'rule' => ['before' => (string) $this->master['rrule'], 'after' => $newRruleText ?? '', 'wordsBefore' => $wordsBefore, 'words' => $words,
                'from' => $this->ruleData((string) $this->master['rrule'], Time::fromDb((string) $this->master['start_utc']), $this->tz0, $this->allDay0),
                'to' => $this->ruleData($newRruleText ?? (string) $this->master['rrule'], $start1, $this->tz1, $this->allDay1)],
            'zone' => $this->planZone(),
            'start' => $startIso,
            'bound' => ['kind' => $boundKind, 'words' => $boundWords] + ($r1->count !== null ? ['count' => $r1->count] : []) + ($r1->until !== null ? ['until' => self::boundOut($r1->until)] : [])
                + $boundData + ['from' => $this->boundFrom($oldOcc)],
            'next' => $next,
            'acted' => $this->actedRowId !== null && $this->ownOf($this->rows[$this->actedRowId]) !== []
                ? ['key' => $iso($this->acted), 'title' => (string) $this->rows[$this->actedRowId]['title'], 'shows' => Cal::date($this->rowDn($this->rows[$this->actedRowId])), 'kept' => OwnFields::normalize($this->ownOf($this->rows[$this->actedRowId]))]
                : null,
            'nextMore' => $nextMore,
            'counts' => [
                'events' => $boundData['events'],
                'edited' => count(array_filter($days, static fn(array $d): bool => $d['kind'] === 'edited')),
                'skipped' => count(array_filter($days, static fn(array $d): bool => $d['kind'] === 'skipped' && in_array($d['outcome'], ['moves', 'stays', 'generated'], true))),
            ],
            'groups' => $groupsOut,
            'days' => $days,
            'losses' => $losses,
            'gains' => $gainList,
            'refusals' => $this->refusals,
            'notes' => $this->notes,
            'fields' => $fieldsWords,
            'summary' => $summary,
        ] + ($ownership !== null ? ['ownership' => $ownership] : []);
        unset($probe);
        return [
            'plan' => $plan,
            'writes' => [
                'master' => $masterCols,
                'overrides' => $overrideWrites,
                'detach' => $detach,
                'delete' => $delete,
                'create' => $create,
                'createEdited' => $createEdited === null ? [] : [$createEdited],
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

    /**
     * Which gains are at an edge, and which: each one that is the image of
     * a day the old pattern gives outside the series (in a period it counts,
     * before the start or after the end), with 'start' or 'end' for where
     * that day lies. The old pattern is walked over the gains' span widened
     * by the furthest any image moved.
     *
     * @param array<int,true> $gainSet
     * @param array<int,?int> $images old occurrence => its image
     * @return array<int,string>
     */
    private function edgeSources(array $gainSet, callable $image, array $images): array
    {
        if ($gainSet === [] || !$this->r0->supported()) {
            return [];
        }
        $shift = 0;
        foreach ($images as $dn => $img) {
            if ($img !== null) {
                $shift = max($shift, abs($img - $dn));
            }
        }
        $r = $this->r0;
        $start = $this->old->startDn;
        $last = $this->old->bounded() ? $this->old->last() : null;
        $p0 = $r->periodOf($start);
        $pad = $shift + 400;
        $p = $r->periodOf(min(array_keys($gainSet)) - $pad);
        $hi = max(array_keys($gainSet)) + $pad;
        $out = [];
        for ($i = 0; $i < 200000 && $r->periodFirst($p) <= $hi; $i++, $p = $r->periodPlus($p, 1)) {
            if (!$r->counted($p, $p0)) {
                continue;
            }
            foreach ($r->periodDays($p) as [$d, $m]) {
                if ($d >= $start && ($last === null || $d <= $last)) {
                    continue;
                }
                $img = $image($d, $m);
                if ($img !== null && isset($gainSet[$img]) && !isset($out[$img])) {
                    $out[$img] = $d < $start ? 'start' : 'end';
                }
            }
        }
        return $out;
    }

    /**
     * The placement fields a row is its own in, where it shows now, against
     * what it would inherit on its key: the backfill's equality rule, for
     * the day acted on, whose placement the request just set (Changing the
     * Series from an Edited Day, 3: its date and time are its own where it
     * isn't at its occurrence's image).
     *
     * @return list<string>
     */
    private function placementOwn(array $row, string $newKey): array
    {
        $series = [
            'start_utc' => $newKey, 'end_utc' => Time::toDb($this->endFrom(Time::fromDb($newKey), $this->allDay1, $this->len1, $this->tz1)),
            'all_day' => $this->allDay1 ? 1 : 0, 'tzid' => $this->allDay1 ? 'UTC' : $this->tz1,
        ];
        $own = OwnFields::infer(['recurrence_instance_utc' => $newKey] + $row, $series);
        return array_values(array_intersect($own, self::PLACEMENT));
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
     * @return array{0:string,1:string,2:array<string,mixed>} kind, words, and the bound as data:
     *   events (shown, the person's skips among them; null with no end), skipped, last (date), separate
     */
    private function boundWords(Rule $r1, Calc $calc1, array $oldOcc, bool $separate, int $kept, int $generated, int $personSkipped, array $lossDays, array $allKeys): array
    {
        $extra = ($separate ? 1 : 0) + $kept;
        if (!$this->readable) {
            // A rule the expander can't read: no count or end the model could say.
            return [$r1->count !== null ? 'count' : ($r1->until !== null ? 'until' : 'none'), '', ['events' => null, 'skipped' => $personSkipped, 'last' => null, 'separate' => $extra]];
        }
        if ($r1->count === null && $r1->until === null) {
            return ['none', $this->r0->count === null && $this->r0->until === null ? 'No end, as before' : 'No end', ['events' => null, 'skipped' => $personSkipped, 'last' => null, 'separate' => $extra]];
        }
        $last = $calc1->last();
        $was = $this->old->bounded() ? $this->old->last() : null;
        // Every raw occurrence the expander gives, by key (a rule several
        // times a day counts each of its times: 60 events on 30 days are 60).
        $n = count($allKeys) - $generated;
        $data = ['events' => $n, 'skipped' => $personSkipped, 'last' => $last !== null ? self::patternOut($calc1, $last) : null, 'separate' => $extra];
        $ends = $last !== null ? 'ends ' . Words::dayMonth($last, $this->thisYear) : '';
        $wasWords = $was !== null && $last !== null && $was !== $last ? ' (was ' . Words::dayMonth($was, $this->thisYear) . ')' : '';
        $scheduled = $n . ' scheduled occurrence' . ($n === 1 ? '' : 's') . ', ' . $personSkipped . ' skipped';
        $lost = count($lossDays) === 1 ? '; ' . Words::date($lossDays[0], $this->thisYear) . ' has no place' : '';
        if ($r1->count !== null) {
            $oldN = (int) ($this->r0->count ?? count($oldOcc));
            $plus = $extra > 0 ? '; ' . $n . ' in the series + ' . $extra . ' separate' : '';
            if ($this->r0->count !== null && $oldN !== $n) {
                return ['count', 'Changes ' . Words::events($oldN) . ' to ' . $n . ', ' . $ends . ($personSkipped > 0 ? '; ' . $scheduled : '') . $plus, $data];
            }
            return ['count', ($personSkipped > 0 ? $scheduled : Words::events($n)) . ', ' . $ends . $wasWords . $lost . $plus, $data];
        }
        $oldN = count($oldOcc);
        $counted = $n !== $oldN && $this->r0->until !== null ? '; ' . Words::events($n) . ' (was ' . $oldN . ')' : '';
        return ['until', Words::upper($ends) . $wasWords . $counted . ($personSkipped > 0 ? '; ' . $scheduled : '') . $lost . ($extra > 0 ? '; ' . $extra . ' separate' : ''), $data];
    }

    /**
     * The next four events as they will show, from the day acted on: every
     * edited day by the day it shows on (by the zone rule, planDn), wherever
     * its key is, and the occurrences with no row at their keys. With them,
     * whether any event of those comes after the four.
     *
     * @return array{0:list<array>,1:bool}
     */
    private function next(Calc $calc1, array $exdates, array $placeRows, array $overrideWrites, int $fromDn, ?array $separate, int $actedDn, array $createdRows = []): array
    {
        $skip = array_flip($exdates);
        $byKey = [];
        // Each by the zone rule (an all-day row's start is a date; a timed
        // one in an all-day plan is in its own zone, named beside it).
        $entry = function (string $start, bool $allDay, string $tz, string $kind): array {
            $local = Time::fromDb($start)->setTimezone($this->zoneFor($allDay, $tz));
            return ['start' => Time::iso($local), 'kind' => $kind, 'dn' => Cal::dnOf($local)] + $this->tzBeside($allDay, $tz);
        };
        foreach ($createdRows as $c) {
            $byKey[(string) $c['recurrence_instance_utc']] = $entry((string) $c['start_utc'], (int) $c['all_day'] === 1, (string) $c['tzid'], 'acted');
            $fromDn = min($fromDn, $byKey[(string) $c['recurrence_instance_utc']]['dn']);
        }
        foreach ($placeRows as $id => $img) {
            $row = $this->rows[$id];
            $start = $overrideWrites[$id]['start_utc'] ?? $row['start_utc'];
            $allDay = (int) ($overrideWrites[$id]['all_day'] ?? $row['all_day']) === 1;
            $tz = (string) ($overrideWrites[$id]['tzid'] ?? $row['tzid']);
            $own = $this->ownOf($row);
            $key = (string) ($overrideWrites[$id]['recurrence_instance_utc'] ?? $row['recurrence_instance_utc']);
            $byKey[$key] = $entry((string) $start, $allDay, $tz, 'edited')
                + (in_array('date', $own, true) && $img !== $actedDn ? ['words' => 'its own date'] : []);
            if ($id === $this->actedRowId) {
                // The list starts where the day acted on shows, by the zone
                // rule, when that is earlier than the day it landed on.
                $fromDn = min($fromDn, $byKey[$key]['dn']);
            }
        }
        $rows = [];
        foreach ($byKey as $key => $item) {
            if (!isset($skip[$key]) && $item['dn'] >= $fromDn) {
                unset($item['dn']);
                $rows[] = $item;
            }
        }
        // Every key the expander gives from the first day listed (several on
        // a day for a rule several times a day), up to one more than the
        // list shows: the plain occurrences are in the order they show, so
        // with every row above beside them, the first five of these hold the
        // first five of all.
        $plain = [];
        foreach ($calc1->keysFrom($fromDn) as [$d, $key]) {
            if (isset($skip[$key]) || isset($byKey[$key])) {
                continue;
            }
            $item = ['start' => Time::iso(Time::fromDb($key)->setTimezone($this->zoneFor($this->allDay1, $this->tz1))), 'kind' => $d === $actedDn ? 'acted' : 'occurrence'];
            $plain[] = $item;
            if (count($plain) > self::NEXT_SHOWN) {
                break;
            }
        }
        $out = array_merge($rows, $plain);
        if ($separate !== null) {
            // The day acted on, where it was put, on its own.
            $sAll = (int) $separate['all_day'] === 1;
            $out[] = ['start' => Time::iso(Time::fromDb((string) $separate['start_utc'])->setTimezone($this->zoneFor($sAll, (string) $separate['tzid']))), 'kind' => 'acted', 'words' => 'on its own']
                + $this->tzBeside($sAll, (string) $separate['tzid']);
        }
        usort($out, static fn(array $a, array $b): int => strcmp((string) Time::parseIso($a['start'])->format('U'), (string) Time::parseIso($b['start'])->format('U')) ?: 0);
        usort($out, static fn(array $a, array $b): int => Time::parseIso($a['start'])->getTimestamp() <=> Time::parseIso($b['start'])->getTimestamp());
        return [array_slice($out, 0, self::NEXT_SHOWN), count($out) > self::NEXT_SHOWN];
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
                $this->notes[] = ['kind' => 'gap', 'date' => self::patternOut($calc, $d), 'clock' => sprintf('%02d:%02d', $calc->clock[0], $calc->clock[1]), 'at' => self::inZoneOut($at, $calc->tz), 'tz' => $calc->tz, 'words' => sprintf('No %02d:%02d on %s in %s: that one is at %s', $calc->clock[0], $calc->clock[1], Words::dayMonth($d, $this->thisYear), self::zoneName($calc->tz), $at->format('H:i'))];
                return;
            }
            $later = $at->modify('+1 hour');
            if ($later->getOffset() !== $at->getOffset() && $later->modify('-1 hour')->format('H:i') === $at->format('H:i') && (new \DateTimeImmutable('@' . ($at->getTimestamp() + 3600)))->setTimezone($tz)->format('H:i:s') === $at->format('H:i:s')) {
                $this->notes[] = ['kind' => 'overlap', 'date' => self::patternOut($calc, $d), 'at' => self::inZoneOut($at, $calc->tz), 'tz' => $calc->tz, 'words' => sprintf('%02d:%02d happens twice on %s: this is the first, in daylight time', $calc->clock[0], $calc->clock[1], Words::dayMonth($d, $this->thisYear))];
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

    /**
     * An edited row's own things: its fields as stored, or by equality when
     * it has no list yet, and its trip when a link refers to it (OwnFields::own).
     *
     * @return list<string>
     */
    private function ownOf(array $row): array
    {
        $id = (int) $row['id'];
        $fields = OwnFields::decode($row['override_fields'] ?? null)
            ?? OwnFields::infer($row, $this->master, $this->opts['rowTags'][$id] ?? null, $this->opts['masterTags'] ?? null, $this->opts['rowPeople'][$id] ?? null, $this->opts['masterPeople'] ?? null);
        return OwnFields::own($fields, !empty($this->opts['rowLinks'][$id]));
    }

    /**
     * The plan's zone (One zone): the after side decides. A series timed
     * after the change has its zone, and every timed value of both sides is
     * written in it; a series all-day after the change has none.
     */
    private function planZone(): ?string
    {
        return $this->allDay1 ? null : $this->tz1;
    }

    // ---- the writers -------------------------------------------------------
    //
    // Every date and instant the plan writes goes through one of these, by
    // its role (series-model.md, Dates): the source carries its kind, so no
    // caller chooses a zone or a form.

    /** An occurrence key, the exact identity instant: UTC ISO ending in Z. */
    private static function keyOut(string $dbKey): string
    {
        return str_replace(' ', 'T', $dbKey) . 'Z';
    }

    /** The day an occurrence is about: its old key's pattern day, in the old series' zone. */
    private function identityOut(string $oldKey): string
    {
        return Cal::date($this->old->dnOf($oldKey));
    }

    /** A day of the series $calc names (old or new), a day number in that series' own zone: a date. */
    private static function patternOut(Calc $calc, int $dn): string
    {
        unset($calc);
        return Cal::date($dn);
    }

    /** A rule's UNTIL as the rule writes it: basic format, read in that rule's zone. */
    private static function boundOut(string $until): string
    {
        return $until;
    }

    /** A value that names its zone beside itself: ISO in $tz, or a date when it has none (all-day). */
    private static function inZoneOut(\DateTimeImmutable $at, ?string $tz): string
    {
        return $tz === null ? $at->setTimezone(Time::utc())->format('Y-m-d') : Time::iso($at->setTimezone(Time::zone($tz)));
    }

    /** The zone a shown placement is written in (One zone): the plan's, or none in an all-day plan, where each timed row is in its own. */
    private function displayZone(): ?string
    {
        return $this->planZone();
    }

    /** A row's own zone, for what the person set on it (its own date). */
    private static function rowZone(array $row): string
    {
        return (string) ($row['tzid'] ?? 'UTC');
    }

    /** $row's start as it is written: an all-day row's date as itself; a timed one in $zone, or its own zone when $zone is null. */
    private static function localOf(array $row, ?string $zone): \DateTimeImmutable
    {
        $tz = (int) $row['all_day'] === 1 ? 'UTC' : ($zone ?? self::rowZone($row));
        return Time::fromDb((string) $row['start_utc'])->setTimezone(Time::zone($tz));
    }

    /** The zone named beside a timed value written in $tz: present whenever $tz isn't the plan's zone. */
    private function zoneBeside(string $tz): array
    {
        $plan = $this->planZone();
        return $plan !== null && Time::normalizeTzid($tz) === Time::normalizeTzid($plan) ? [] : ['tz' => $tz];
    }

    /**
     * Where an event is, before or after: an all-day row's literal date, or a
     * timed row's instant with its date and clock built together in $zone
     * (displayZone() for where it shows, rowZone() for what was set on it),
     * the zone named when it isn't the plan's. $row is a stored row or one
     * the plan writes (start_utc, all_day, tzid).
     *
     * @return array{date:string,at?:string,tz?:string}
     */
    private function placementOut(array $row, ?string $zone): array
    {
        $local = self::localOf($row, $zone);
        if ((int) $row['all_day'] === 1) {
            return ['date' => $local->format('Y-m-d')];
        }
        return ['date' => $local->format('Y-m-d'), 'at' => Time::iso($local)] + $this->zoneBeside($zone ?? self::rowZone($row));
    }

    /**
     * A placement as a list entry (coming up): its start (an all-day row's at
     * its date's UTC midnight), its kind, and its zone when it isn't the plan's.
     *
     * @return array{start:string,allDay:bool,tz?:string}
     */
    private function placementEntryOut(array $row, ?string $zone): array
    {
        $allDay = (int) $row['all_day'] === 1;
        return ['start' => Time::iso(self::localOf($row, $zone)), 'allDay' => $allDay] + ($allDay ? [] : $this->zoneBeside($zone ?? self::rowZone($row)));
    }

    /**
     * An occurrence of a reading's rule on a day, as its calc gives it: a list
     * entry in the calc's zone and kind.
     *
     * @return array{start:string,allDay:bool,tz?:string}
     */
    private function patternInstantOut(Calc $calc, int $dn): array
    {
        return $this->placementEntryOut(['start_utc' => $calc->key($dn), 'all_day' => $calc->allDay ? 1 : 0, 'tzid' => $calc->tz], $calc->tz);
    }

    /** A placement the plan writes, as a row: its start, kind and zone. */
    private static function rowOf(\DateTimeImmutable $start, bool $allDay, string $tz): array
    {
        return ['start_utc' => Time::toDb($start), 'all_day' => $allDay ? 1 : 0, 'tzid' => $tz];
    }

    /** The day a placement's date names. */
    private static function dayOf(array $placement): int
    {
        return Cal::dn((int) substr($placement['date'], 0, 4), (int) substr($placement['date'], 5, 2), (int) substr($placement['date'], 8, 2));
    }

    /**
     * The zone a value is written in, by the zone rule: an all-day value is
     * a date, read as itself; a timed one is in the plan's zone, or in an
     * all-day plan in its own row's zone ($tz), which tzBeside names.
     */
    private function zoneFor(bool $allDay, string $tz): \DateTimeZone
    {
        return Time::zone($allDay ? 'UTC' : ($this->planZone() ?? $tz));
    }

    /** The zone written beside a timed value that isn't in the plan's zone: a timed day in an all-day plan. */
    private function tzBeside(bool $allDay, string $tz): array
    {
        return !$allDay && $this->planZone() === null ? ['tz' => $tz] : [];
    }

    /** The day an instant of that kind and zone shows on (zoneFor). */
    private function planDn(\DateTimeImmutable $instant, bool $allDay, string $tz): int
    {
        return Cal::dnOf($instant->setTimezone($this->zoneFor($allDay, $tz)));
    }

    /** The day a row's placement shows on (planDn): a stored row before the change, or where the request put one. */
    private function rowDn(array $row): int
    {
        return $this->planDn(Time::fromDb((string) $row['start_utc']), (int) $row['all_day'] === 1, (string) $row['tzid']);
    }

    /** The local day an edited row shows on, in its own zone. */
    private function shownDn(array $row): int
    {
        $tz = (int) $row['all_day'] === 1 ? 'UTC' : (string) $row['tzid'];
        return Cal::dnOf(Time::fromDb((string) $row['start_utc'])->setTimezone(Time::zone($tz)));
    }

    /**
     * Where an edited row shows after the edit: its own date, time, length
     * and all-day flag kept, the rest taken from the series' new values at
     * its new key. $from and $fromClock are the day and series clock its
     * shown values were set against (its old key's day and the old clock;
     * for the day acted on, its image and the new clock, since the request
     * put it there).
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable,2:bool,3:string}
     */
    private function place(array $row, array $own, int $img, string $newKey, bool $moveOwnDates, int $from, array $seriesClock, array $fromClock): array
    {
        $rowAllDay = (int) $row['all_day'] === 1;
        $rowTz = $rowAllDay ? 'UTC' : (string) $row['tzid'];
        $shown = Time::fromDb((string) $row['start_utc'])->setTimezone(Time::zone($rowTz));
        $shownDn = Cal::dnOf($shown);
        $date = in_array('date', $own, true) ? ($moveOwnDates ? $shownDn + ($img - $from) : $shownDn) : $img;
        $allDay = in_array('allDay', $own, true) ? $rowAllDay : $this->allDay1;
        $rowLen = self::lengthOf($row);
        if ($allDay) {
            $start = Calc::at($date, [0, 0, 0], 'UTC');
            $len = in_array('length', $own, true) || !$this->allDay1 ? $rowLen : $this->len1;
            return [$start, $this->endFrom($start, true, $len, 'UTC'), true, 'UTC'];
        }
        // The instant it shows at now, or for an inherited time on its
        // occurrence's day the new key's: an instant given stays that
        // instant wherever the day, clock and zone come out the same, so
        // neither of the two instants of a repeated hour becomes the other.
        $given = $shown;
        if (in_array('time', $own, true) && !$rowAllDay) {
            // Its own clock in its own zone, on its own local day, moved with
            // its occurrence's days.
            $tz = (string) $row['tzid'];
            $clock = self::clockOf($shown);
            if (!in_array('date', $own, true) || $moveOwnDates) {
                $date = $shownDn + ($img - $from);
            }
        } else {
            // The series' time. On its occurrence's day: the new key's instant
            // as the expander gives it. On a date of its own: the time it shows
            // moved by the series' clock change, as every inherited value takes
            // the edit's delta (a day of the other kind, all-day until now, has
            // no time to move and takes the series' clock). The key's clock is
            // its own day's, which a skipped hour or a carried clock shifts.
            $tz = $this->allDay1 ? (string) $row['tzid'] : $this->tz1;
            $secs = static fn(array $c): int => $c[0] * 3600 + $c[1] * 60 + $c[2];
            if ($this->allDay1) {
                $clock = self::clockOf($shown);
            } elseif (in_array('date', $own, true) && $rowAllDay) {
                $clock = $seriesClock;
            } elseif (in_array('date', $own, true)) {
                $sec = $secs(self::clockOf($shown)) + $secs($seriesClock) - $secs($fromClock);
                $carry = intdiv($sec - ((($sec % 86400) + 86400) % 86400), 86400);
                $sec -= $carry * 86400;
                $date += $carry;
                $clock = [intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60];
            } else {
                $given = Time::fromDb($newKey);
                $clock = self::clockOf($given->setTimezone(Time::zone($this->tz1)));
            }
        }
        $len = in_array('length', $own, true) || $this->allDay1 ? $rowLen : $this->len1;
        if ($rowAllDay && !in_array('length', $own, true)) {
            $len = $this->allDay1 ? $rowLen : $this->len1;
        }
        $start = Calc::at($date, $clock, $tz, $given);
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

    /** A COUNT sent for an anchored series counts the stored day too, as the stored rule does. */
    private function withoutAnchor(Rule $rule): Rule
    {
        return $this->anchored && $rule->count !== null && $rule->count > 1 ? $rule->withBound($rule->count - 1, null) : $rule;
    }

    private function sameBound(Rule $sent): bool
    {
        if ($sent->count !== null || $this->r0->count !== null) {
            return $sent->count === $this->r0->count && $sent->until === null && $this->r0->until === null;
        }
        if ($sent->until === null || $this->r0->until === null) {
            return $sent->until === $this->r0->until;
        }
        // The last raw occurrence at or before the sent UNTIL, each
        // occurrence read in the UNTIL's own form at the coarser of the two:
        // a date against an occurrence's day, an instant against an instant
        // (an all-day occurrence has only its day, so an instant names its
        // day in the zone it was written for). The editor writes the end in
        // the form of the kind the series will be.
        $u = (string) $sent->until;
        $dateForm = strlen($u) === 8;
        if (!$dateForm && !$this->allDay0) {
            $a = new Calc($sent, $this->old->startDn, $this->tz0, $this->allDay0, $this->clock0, $this->len0, $this->old->anchor ?? false, $this->old->storedStart());
            return $a->last() === $this->old->last();
        }
        if ($dateForm) {
            $lastDay = Cal::dn((int) substr($u, 0, 4), (int) substr($u, 4, 2), (int) substr($u, 6, 2));
        } else {
            $at = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $u, Time::utc());
            if ($at === false) {
                return false;
            }
            $lastDay = Cal::dnOf($at->setTimezone(Time::zone($this->allDay1 ? 'UTC' : $this->tz1)));
        }
        $days = $this->old->withoutBound()->occurrences($lastDay);
        return $days !== [] && $days[count($days) - 1] === $this->old->last();
    }

    /** @param list<mixed> $items */
    private static function set(array $items): string
    {
        $items = array_map('strval', $items);
        sort($items);
        return json_encode(array_values(array_unique($items)));
    }
}
