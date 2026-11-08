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
 * what refers to the row, as "kind:reference" entries: its trip links,
 * duplicate decisions and plugin data (SeriesPlans::referencesNaming, one
 * reading of OwnFields::REFERENCES)].
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
        'duplicates' => 'duplicate decision', 'pluginData' => 'plugin data',
    ];

    public const REASONS = [
        'unreadable' => 'This repeat came from another app in a form Better-Cal can\'t read, so only its details can change here.',
        'several' => 'This repeat happens several times a day, and on a day the clocks change two of its times can fall on one moment, so it can\'t be moved or given another zone as a series. Move one event, or change its other details.',
        'severalAllDay' => 'This repeat happens several times a day, so it can\'t be made all-day.',
        'weekno' => 'This repeat counts weeks of the year, so its days can\'t follow a drag.',
        'overlap' => 'Two of its days are the same day in some months, so its days can\'t follow a drag.',
        'dayset' => 'This repeat came from another app and picks its days in a way a drag can\'t follow. Move only this one, or open the series to choose its days.',
        'googleAllDay' => 'At Google, a day of an all-day series can\'t have a time of its own, so this change can\'t be made while an edited day keeps one. Give that day the series\' all-day setting first, or change only this one.',
        'empty' => 'This would leave the series with no events. Delete it instead?',
    ];

    public const GOOGLE_NOTE = 'This change is sent to Google and read back from it: the calendar shows what Google stores. It can\'t be undone yet.';

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
    /**
     * The deltas measured from where the day acted on shows (the 1.5.0
     * reading, "the series moves the same way"): [landed day, series clock,
     * series length]. compute() takes these, or an adoption's, per choice.
     *
     * @var array{0:int,1:array{0:int,1:int,2:int},2:int}
     */
    private array $sameWay;
    /** @var array<string,array<string,mixed>> the adoptions this edit offers, by part (self::ADOPTIONS) */
    private array $adoptable = [];
    /** The values were typed into the editor: a typed value names the value wanted, a drag a delta. */
    private bool $typed = false;

    /**
     * The adoption readings (Changing the Series from an Edited Day): a part
     * the day acted on owns (its date, time or length) is measured either
     * from where the day shows, so the series moves the same way and the day
     * keeps its own value, or from its occurrence, so the series takes the
     * day's new value as its own and the day's own value is cleared. The
     * second is the first plus the day's own offset for that part, nothing
     * else. One row per part: its group, the own field it reads, and which
     * option is preselected (open decision 1, and Typed Values as Assignment
     * in the design): `period` adopts when that moves every event by less
     * than one period of the rule, `always` adopts, `typed` adopts for a
     * value typed into the editor and moves the same way for a drag.
     */
    private const ADOPTIONS = [
        'day' => ['own' => 'date', 'label' => 'Its day', 'adopt' => 'This becomes the series\' day', 'same' => 'The series moves the same way', 'preselect' => 'period'],
        'time' => ['own' => 'time', 'label' => 'Its time', 'adopt' => 'This becomes the series\' time', 'same' => 'The series moves the same way', 'preselect' => 'always'],
        'length' => ['own' => 'length', 'label' => 'Its length', 'adopt' => 'This becomes the series\' length', 'same' => 'The series changes the same way', 'preselect' => 'typed'],
    ];

    // Per computation.
    private array $choices = [];
    private array $groups = [];
    private array $refusals = [];
    private array $notes = [];
    /**
     * Under "the same moment", the clock the rows showed on: the series'
     * old zone and its clock there after any drag ([tz, clock]), where a
     * row's inherited time is moved; the carry to the new zone relabels
     * days and moves no instant. Null otherwise.
     */
    private ?array $shownFrame = null;

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
        // An all-day series' rule is read without clock parts (Recurrence::inForm).
        $this->r0 = Rule::parse($this->allDay0 ? \BetterCal\Domain\Recurrence::inForm((string) $master['rrule'], true, 'UTC') : (string) $master['rrule'], $startDn);
        $this->rule0 = $this->r0;
        // A stored rule whose walk could not be completed (WalkIncomplete,
        // see plan()) is read as one the expander can't read.
        // A skip list quarantined over its limit (decodeExdates refuses it)
        // takes the same path, never a series read with no skips.
        try {
            $this->exdates = \BetterCal\Domain\Recurrence::decodeExdates($master['exdates_json'] ?? null);
            $skipsRead = true;
        } catch (\InvalidArgumentException) {
            $this->exdates = [];
            $skipsRead = false;
        }
        $this->readable = $skipsRead && $this->r0->kind !== 'unreadable' && empty($opts['walkIncomplete']) && Calc::reads((string) $master['rrule']);
        if (!$this->readable) {
            $this->r0->kind = 'unreadable';
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
        // The clock the request put it at: the one typed when the request
        // says (a typed clock in a skipped hour is that clock, which the
        // instant can't hold: 02:30 there is the instant of 03:30), else
        // its instant's in its zone.
        $this->typed = !empty($edit['typed']);
        $typedClock = is_array($edit['landed']['clock'] ?? null) ? array_map('intval', array_values($edit['landed']['clock'])) + [0, 0, 0] : null;
        $lClock = $lAll ? null : ($typedClock ?? self::clockOf($lAt));
        $sClock = $sAll ? null : self::clockOf($sAt);
        [$this->landedDn, $this->clock1] = $this->landing(Cal::dnOf($lAt) - Cal::dnOf($sAt), $sClock, $lClock);

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

        // The adoptions (self::ADOPTIONS): for each part the day acted on
        // owns and the request changed, the day's own offset for it, read in
        // the series' own zone and kind (a day of another zone or kind than
        // the series has no offset to read, and a zone or all-day change is
        // its own reading). Adopting adds the offset to the same-way delta.
        $sameFrame = !$this->zoneChanged && $sAll === $lAll && $sAll === $this->allDay0 && ($sAll || Time::normalizeTzid($sTz) === Time::normalizeTzid($this->tz0));
        if ($actedRow !== null && $sameFrame) {
            $secs = static fn(array $c): int => $c[0] * 3600 + $c[1] * 60 + $c[2];
            $offsets = [
                'day' => [Cal::dnOf($sAt) - $this->actedDn0, Cal::dnOf($lAt) !== Cal::dnOf($sAt)],
                'time' => $sAll ? [0, false] : [$secs($sClock) - $secs($this->clock0), $lClock !== $sClock],
                'length' => [$sLen - $this->len0, $lLen !== $sLen],
            ];
            foreach (self::ADOPTIONS as $part => $row) {
                [$offset, $changedPart] = $offsets[$part];
                if ($offset !== 0 && $changedPart && in_array($row['own'], $actedOwn, true)) {
                    $this->adoptable[$part] = ['offset' => $offset];
                }
            }
            if ($this->adoptable !== []) {
                $this->adoptable['_frame'] = ['days' => Cal::dnOf($lAt) - Cal::dnOf($sAt), 'seen' => $sClock, 'landed' => $lClock, 'lLen' => $lLen, 'sLen' => $sLen];
            }
        }

        // The rule's delta (The Function, 1): an unchanged rule is none, the
        // same pattern with another end a bound, anything else a new rule.
        if (array_key_exists('rrule', $edit)) {
            $sent = $edit['rrule'];
            if ($sent === null || $sent === '') {
                $this->ruleDelta = 'none';
            } else {
                // A COUNT sent counts the events the series shows, as every
                // COUNT the API reads or writes does (Events::shownRrule): the
                // stored day of an anchored start is the model's, never the
                // caller's, and the writer adds it back (Calc::storedRule).
                $rebased = $startDn + ($this->landedDn - $this->actedDn0);
                $parsed = Rule::parse((string) $sent, $startDn);
                if ($this->readable && $parsed->comparable() === $this->r0->comparable()) {
                    // A split's part takes a bound the person changed as sent
                    // (Split): compared there with the whole series' rule, so
                    // the part's own may happen to equal it.
                    if (!empty($edit['boundSent']) || !$this->sameBound($parsed)) {
                        $this->sentRule = $parsed;
                        $this->ruleDelta = 'bound';
                    }
                } elseif (!$this->readable && strtoupper(trim((string) $sent)) === strtoupper(trim((string) $master['rrule']))) {
                    // The unreadable rule sent back as it is.
                } else {
                    $this->sentRule = Rule::parse((string) $sent, $rebased);
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
        $this->sameWay = [$this->landedDn, $this->clock1, $this->len1];
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
        $model = self::read(fn(array $opts): self => new self($master, $key === null ? [] : $rows, $key === null ? [] : ['acted' => $key], $opts), []);
        return self::occurrenceOf($master, $key === null ? [] : $rows, $model->acted, $model->len0);
    }

    /** The plan of a refusal for a series as it is (a split of a rule the expander can't read). */
    public static function refusedPlan(array $master, string $code, string $scope = 'all', array $opts = []): array
    {
        $plan = self::read(fn(array $o): array => (new self($master, [], [], $o))->refused($code)['plan'], $opts);
        $plan['scope'] = $scope;
        return $plan;
    }

    /**
     * What a sent rule changes in this series as shown (The Function, 1):
     * null when it is the series' rule (an unchanged rule is no rule),
     * 'bound' for the same pattern with another end, 'pattern' for another
     * pattern, 'none' for no repeat; with the rule sent as read. A split
     * compares the rule sent with the whole series' rule this way, since
     * that is what the editor showed and sent back (Split).
     *
     * @return array{0:?string,1:?Rule}
     */
    public static function sentRule(array $master, array $overrides, array $edit, array $opts = []): array
    {
        return self::read(function (array $o) use ($master, $overrides, $edit): array {
            $m = new self($master, $overrides, $edit, $o);
            return [$m->ruleDelta, $m->sentRule];
        }, $opts);
    }

    public static function plan(array $master, array $overrides, array $edit, array $choices = [], array $opts = []): array
    {
        return self::read(fn(array $o): array => (new self($master, $overrides, $edit, $o))->run($choices), $opts);
    }

    /**
     * Reads a series through $f. A walk that can't be completed is never a
     * series that ends: it is read again as a rule the expander can't read
     * (next empty, the bound read from the rule's text).
     */
    private static function read(\Closure $f, array $opts): mixed
    {
        try {
            return $f($opts);
        } catch (WalkIncomplete) {
            return $f(['walkIncomplete' => true] + $opts);
        }
    }

    private function run(array $choices): array
    {
        $result = $this->compute($choices);
        $plan = $result['plan'];
        foreach ($plan['groups'] as $gi => $group) {
            $alts = [];
            foreach ($group['options'] as $oi => $option) {
                if (!empty($option['disabled'])) {
                    continue;
                }
                $alt = $option['id'] === $group['selected'] ? $result : $this->compute([$group['id'] => $option['id']] + $result['selected']);
                if ($alt['writes'] === null) {
                    // An option whose own plan is refused (a timed day kept
                    // under an all-day series at Google) can't be chosen: it
                    // is disabled with the refusal's reason, never offered
                    // with a refused plan's empty data behind it.
                    $r = $alt['plan']['refusals'][0] ?? ['code' => 'refused', 'words' => ''];
                    $plan['groups'][$gi]['options'][$oi] += ['disabled' => true, 'code' => (string) $r['code'], 'reason' => (string) $r['words']];
                    continue;
                }
                $alts[$oi] = $alt;
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
                // A zone option's label is the series' clock under it, never
                // a day coming up (which can be an edited day at its own
                // time): its series' start, written in this plan's zone as
                // rule.to.start is (an option's plan writes its rule.to in
                // its own series' zone; a refused one is disabled above).
                // An adoption's option names what it makes the series: its
                // rule (a day) and its start (a time, whose clock it says).
                if ($group['id'] === 'zone' || $group['id'] === 'time') {
                    $to = (string) $alt['plan']['rule']['to']['start'];
                    $plan['groups'][$gi]['options'][$oi]['start'] = $plan['zone'] !== null && str_contains($to, 'T') ? self::inZoneOut(Time::parseIso($to), $plan['zone']) : $to;
                }
                if ($group['id'] === 'day') {
                    $plan['groups'][$gi]['options'][$oi]['rrule'] = (string) $alt['plan']['rule']['to']['rrule'];
                }
            }
            // A disabled zone option has no series under it to start.
            if ($group['id'] === 'zone') {
                foreach ($group['options'] as $oi => $option) {
                    if (!isset($alts[$oi])) {
                        $plan['groups'][$gi]['options'][$oi]['start'] = null;
                    }
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
     * @return array<int,list<array{start:string,allDay:bool,tz?:string}>>|null
     */
    private function partingDates(array $alts): ?array
    {
        $seqs = [];
        $tos = [];
        foreach ($alts as $oi => $alt) {
            $calc = $alt['meta']['calc'] ?? null;
            if (!$calc instanceof Calc) {
                return null;
            }
            [$bounded, $periods] = $alt['walk'];
            $seqs[$oi] = ['days' => $bounded ?? $periods, 'walked' => $bounded === null, 'calc' => $calc, 'landed' => (int) $alt['meta']['landed']];
            $tos[$oi] = (int) ($alt['walkTo'] ?? PHP_INT_MAX);
        }
        // Walked days compare through the days every walk read; past that
        // is where a walk ended, never a difference.
        $to = min($tos);
        foreach ($seqs as $oi => $s) {
            if ($s['walked']) {
                $seqs[$oi]['days'] = array_values(array_filter($s['days'], static fn(int $d): bool => $d <= $to));
            }
        }
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
                    $out[$oi] = array_map(fn(int $d): array => $this->patternInstantOut($seqs[$oi]['calc'], $d), array_slice($a, $i, 3));
                }
                return $out;
            }
        }
        unset($first);
        $ends = [];
        foreach ($seqs as $oi => $s) {
            $ends[$oi] = array_map(fn(int $d): array => $this->patternInstantOut($s['calc'], $d), array_slice($s['days'], -3));
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
     * included), and `earlierSeries`, whose day that is: "old" or "new".
     *
     * @param array<int, array> $alts
     * @return array<int, array{dates: list<array>, then: list<array>, earlier: ?string, earlierSeries: ?string}>
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
            $seqs[$oi] = ['days' => array_values($periods ?? []), 'calc' => $calc, 'landed' => (int) $alt['meta']['landed'], 'to' => (int) ($alt['walkTo'] ?? PHP_INT_MAX)];
        }
        if (!isset($seqs[$selected])) {
            $selected = (int) array_key_first($seqs);
        }
        // The series as it is today, before the day acted on.
        $oldBefore = $this->readable ? array_values(array_filter($this->walk($this->old, [], $this->actedDn0)[1], fn(int $d): bool => $d < $this->actedDn0)) : [];
        $out = [];
        foreach ($seqs as $oi => $s) {
            $ref = $oi === $selected ? $seqs[array_key_first(array_diff_key($seqs, [$selected => 1]))] : $seqs[$selected];
            // Compared through the days both walks read: a day past the
            // other's walk is where that walk ended (its cycle, year 9999),
            // never a difference (decisions.md, A walk that can't complete).
            $to = min($s['to'], $ref['to']);
            $onward = array_values(array_filter($s['days'], static fn(int $d): bool => $d >= $s['landed'] && $d <= $to));
            $refOnward = array_values(array_filter($ref['days'], static fn(int $d): bool => $d >= $ref['landed'] && $d <= $to));
            $i = 0;
            while ($i < count($onward) && $i < count($refOnward) && $onward[$i] === $refOnward[$i]) {
                $i++;
            }
            $differs = $i < count($onward) && ($i < count($refOnward) || count($refOnward) > 0);
            $dates = array_slice($onward, 0, $differs ? max(3, $i + 1) : 3);
            $then = [];
            if ($differs && $i >= 4) {
                $dates = array_slice($onward, 0, 3);
                $then = array_slice($onward, $i - 1, 2);
            }
            // Against the series as it is today, for every reading: where the
            // two first differ, the one that comes first, a day of its own
            // series. Each day number is in its own series' zone, so two days
            // are the same only when they are the same instant of the same
            // kind, and are ordered by their instants; only the day found is
            // named, in its own series.
            $earlier = ['earlier' => null, 'earlierSeries' => null];
            $before = array_values(array_filter($s['days'], static fn(int $d): bool => $d < $s['landed']));
            $n = max(count($before), count($oldBefore));
            for ($j = 0; $j < $n; $j++) {
                $mine = $before[$j] ?? null;
                $was = $oldBefore[$j] ?? null;
                if ($mine !== null && $was !== null && $s['calc']->instant($mine) == $this->old->instant($was) && $s['calc']->allDay === $this->old->allDay) {
                    continue;
                }
                $new = $mine !== null && ($was === null || $s['calc']->instant($mine) < $this->old->instant($was));
                $earlier = $new
                    ? ['earlier' => self::patternOut($s['calc'], $mine), 'earlierSeries' => 'new']
                    : ['earlier' => self::patternOut($this->old, (int) $was), 'earlierSeries' => 'old'];
                break;
            }
            $out[$oi] = [
                'dates' => array_map(fn(int $d): array => $this->patternInstantOut($s['calc'], $d), $dates),
                'then' => array_map(fn(int $d): array => $this->patternInstantOut($s['calc'], $d), $then),
            ] + $earlier;
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
        // A year of days from where it landed: the zone's changes repeat
        // yearly, so every day whose instant differs is among them, however
        // many occurrences that is.
        foreach ($calc->occurrences($from + 366) as $d) {
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
        $this->shownFrame = null;

        [$this->landedDn, $this->clock1, $this->len1] = $this->adopted();
        $whole = $this->capability();
        if ($whole !== null) {
            return $this->refused($whole);
        }
        // Where the occurrence acted on lands: its day, the series' clock,
        // and its own columns there.
        $land = ['dn' => $this->landedDn, 'clock' => $this->clock1, 'cols' => $this->landedCols];
        // A zone change: the same clock time, or the same moment (Zones,
        // DST and All-Day Days). The moment keeps every row's own instant:
        // the request's wall clock is read on the clock it showed on (the
        // old zone), any drag in it is measured in that zone alone, and the
        // result is carried to the new zone, where each instant falls on
        // whatever local day it has. No day read in one zone is subtracted
        // from one read in the other.
        $carry = null;
        if ($this->zoneChanged && !$this->allDay1) {
            $sTz = $this->seen['tzid'];
            $lTz = $this->landedCols['tzid'];
            $sAt = Time::fromDb($this->seen['start_utc'])->setTimezone(Time::zone($sTz));
            $lAt = Time::fromDb($this->landedCols['start_utc'])->setTimezone(Time::zone($lTz));
            $put = Calc::at(Cal::dnOf($lAt), self::clockOf($lAt), $sTz);
            $putOld = $put->setTimezone(Time::zone($sTz));
            // The drag in the old zone: its days and the series' clock there.
            [$oDn, $oClock] = $this->landing(Cal::dnOf($putOld) - Cal::dnOf($sAt), self::clockOf($sAt), self::clockOf($putOld));
            // Carried to the new zone by the two zones' offsets at that
            // instant: the series' clock there, and the days its instants
            // move on the new zone's calendar. Measured on the series' clock,
            // so a day whose clock a skipped hour shifted carries the series
            // as any other day does.
            $off = $put->setTimezone(Time::zone($lTz))->getOffset() - $put->setTimezone(Time::zone($sTz))->getOffset();
            $sec = $oClock[0] * 3600 + $oClock[1] * 60 + $oClock[2] + $off;
            $days = intdiv($sec - ((($sec % 86400) + 86400) % 86400), 86400);
            $sec -= $days * 86400;
            $mDn = $oDn + $days;
            $mClock = [intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60];
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
                        'start_utc' => Time::toDb($put), 'end_utc' => Time::toDb($put->modify('+' . self::lengthOf($this->landedCols) . ' seconds')),
                    ] + $this->landedCols];
                    $carry = [$oDn, $mDn - $oDn];
                    $this->shownFrame = [$this->tz0, $oClock];
                }
            }
        }
        // Under the moment, the drag's readings are the old zone's, each
        // carried by the days the instant moves on the new zone's calendar.
        // A new rule sent is the person's days in the series' new zone, so
        // nothing of the old zone's calendar is carried into it.
        $readings = $carry === null || $this->newRule()
            ? $this->readings($land['dn'] - $this->actedDn0, $land['dn'], $land['clock'])
            : $this->carried($this->readings($carry[0] - $this->actedDn0, $carry[0], $land['clock']), $carry[0], $carry[1]);
        if ($readings === []) {
            return $this->refused('dayset');
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
     * The landed day, the series' clock and its length under the adoption
     * choices (self::ADOPTIONS): each part the edit can adopt is a group of
     * two options, and an adopted part's delta is the same-way delta plus
     * the day's own offset for it. A new rule gives the day acted on no
     * special place, and a start moved alone names no day, so neither
     * offers one.
     *
     * @return array{0:int,1:array{0:int,1:int,2:int},2:int}
     */
    private function adopted(): array
    {
        [$dn, $clock, $len] = $this->sameWay;
        if ($this->adoptable === [] || $this->newRule() || $this->startTo !== null || !$this->readable) {
            return [$dn, $clock, $len];
        }
        $f = $this->adoptable['_frame'];
        $take = [];
        foreach (self::ADOPTIONS as $part => $row) {
            if (!isset($this->adoptable[$part])) {
                continue;
            }
            $offset = (int) $this->adoptable[$part]['offset'];
            $adopt = ['id' => 'adopt', 'label' => $row['adopt']];
            $data = [];
            if ($part === 'day') {
                // Adopted, the drag runs from the day's occurrence to where it
                // was put; refused where that drag is, with that reason.
                $to = $this->actedDn0 + $f['days'] + $offset;
                $why = $this->r0->fixedDays ?? (!$this->r0->supported() ? 'dayset' : null);
                $enabled = $why === null ? array_filter(Readings::forDrag($this->r0, $this->actedDn0, $to, $this->old->startDn), static fn(array $r): bool => empty($r['disabled'])) : [];
                if ($enabled === []) {
                    $adopt += ['disabled' => true, 'reason' => self::REASONS[$why ?? 'dayset']];
                }
                $data = ['shift' => $f['days'], 'by' => $f['days'] + $offset];
                $default = $row['preselect'] === 'period' && $this->withinPeriod($this->actedDn0, $to) ? 'adopt' : 'same';
            } else {
                $data = $part === 'time'
                    ? ['shift' => ($f['landed'][0] * 3600 + $f['landed'][1] * 60 + $f['landed'][2]) - ($f['seen'][0] * 3600 + $f['seen'][1] * 60 + $f['seen'][2])]
                    : ['shift' => $f['lLen'] - $f['sLen'], 'length' => $f['lLen']];
                $default = $row['preselect'] === 'always' || ($row['preselect'] === 'typed' && $this->typed) ? 'adopt' : 'same';
            }
            $options = [$adopt, ['id' => 'same', 'label' => $row['same']]];
            if ($default === 'same') {
                $options = array_reverse($options);
            }
            $take[$part] = $this->pick($part, $row['label'], $options, $default, $data) === 'adopt' ? $offset : 0;
        }
        if (($take['day'] ?? 0) !== 0 || ($take['time'] ?? 0) !== 0) {
            [$dn, $clock] = $this->landing($f['days'] + ($take['day'] ?? 0), $f['seen'] === null ? null : self::clockMinus($f['seen'], $take['time'] ?? 0), $f['landed']);
        }
        return [$dn, $clock, $len + ($take['length'] ?? 0)];
    }

    /** A clock less some seconds (across midnight it wraps: landing() carries the day). @return array{0:int,1:int,2:int} */
    private static function clockMinus(array $c, int $secs): array
    {
        $s = ((($c[0] * 3600 + $c[1] * 60 + $c[2] - $secs) % 86400) + 86400) % 86400;
        return [intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60];
    }

    /**
     * Whether $to lies within one period of the rule's frequency of $from
     * (a day, a week, a month or a year either way, exclusive), read from
     * the frequency as written (Rule::frequency), never the family: a DAILY
     * list's period is a day. An adoption that moves every event by less
     * reads as "this is the day now".
     */
    private function withinPeriod(int $from, int $to): bool
    {
        $frequency = $this->r0->frequency();
        $months = match ($frequency) {
            'MONTHLY' => 1,
            'YEARLY' => 12,
            default => null,
        };
        if ($months === null) {
            $days = $frequency === 'WEEKLY' ? 7 : 1;
            return abs($to - $from) < $days;
        }
        $shift = static function (int $dn, int $k): int {
            [, , $d] = Cal::ymd($dn);
            [$y, $m] = Cal::fromMi(Cal::miOf($dn) + $k);
            return Cal::dn($y, $m, min($d, Cal::monthLen($y, $m)));
        };
        return $to > $shift($from, -$months) && $to < $shift($from, $months);
    }

    /**
     * A rule as data for the client's sentences: its text, its start (ISO in
     * its zone, or a date for an all-day rule, which has none), its zone and
     * whether it is all-day.
     */
    private function ruleData(string $rrule, \DateTimeImmutable $start, string $tz, bool $allDay): array
    {
        $zone = $allDay ? null : $tz;
        return ['rrule' => $rrule, 'start' => self::inZoneOut($start, $zone), 'tz' => $zone, 'allDay' => $allDay];
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
                // The series' start as it stays, by its kind: a date for an
                // all-day series, else in its zone.
                'start' => self::inZoneOut(Time::fromDb((string) $this->master['start_utc']), $this->allDay0 ? null : $this->tz0),
                'bound' => ['kind' => 'none', 'words' => '', 'events' => null, 'skipped' => 0, 'last' => null, 'separate' => 0],
                'next' => [], 'nextMore' => false, 'counts' => ['events' => null, 'edited' => 0, 'skipped' => 0],
                'groups' => [], 'days' => [], 'losses' => [], 'gains' => [],
                'refusals' => $this->refusals, 'notes' => $this->notes, 'fields' => [],
                'summary' => 'Not changed: ' . ($words ?? self::REASONS[$code] ?? $code),
            ],
            'writes' => null, 'selected' => [], 'nextDates' => [], 'walk' => [null, []], 'walkTo' => PHP_INT_MAX,
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
            return null;
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
     * Readings of a drag measured in the old zone, carried to the new one
     * under "the same moment": each reading's days are its old days read on
     * the new zone's calendar, so every member moves by the days the
     * instant moves ($days, from the landed day $at), never as a drag of
     * one member. The carry is the drag table's whole-pattern shift by
     * those days (its first enabled reading where the family has no
     * pattern reading, the month-end readings' preselection among them);
     * a rule that can't take it is the reason of that reading's refusal.
     *
     * @param list<array<string,mixed>> $readings
     * @return list<array<string,mixed>>
     */
    private function carried(array $readings, int $at, int $days): array
    {
        // Carried by no days, each reading is its own drag: a row placed on
        // its own moves by it alone, never by its new key read on the old
        // zone's calendar, which a day acted on in a zone of its own puts
        // on another day than the drag does.
        if ($days === 0) {
            return array_map(static fn(array $r): array => ['drag' => $r['image']] + $r, $readings);
        }
        $out = [];
        foreach ($readings as $r) {
            if (!empty($r['disabled'])) {
                $out[] = $r;
                continue;
            }
            /** @var Rule $rule */
            $rule = $r['rule'];
            $first = $r['image'];
            $startImg = $first($this->old->startDn, $this->r0->supported() ? $this->r0->memberOf($this->old->startDn) : null) ?? $at;
            $shifts = $rule->supported() ? Readings::forDrag($rule, $at, $at + $days, $startImg) : [];
            $pick = null;
            foreach ($shifts as $s) {
                if (empty($s['disabled']) && ($pick === null || ($s['id'] === 'pattern' && $pick['id'] !== 'pattern'))) {
                    $pick = $s;
                }
            }
            if ($pick === null) {
                $why = $shifts[0] ?? ['code' => 'dayset', 'reason' => self::REASONS['dayset']];
                $out[] = ['disabled' => true, 'code' => $why['code'] ?? 'dayset', 'reason' => $why['reason'] ?? self::REASONS['dayset']] + $r;
                continue;
            }
            $second = $pick['image'];
            // 'drag': where the occurrence goes on the old zone's calendar,
            // by which a row placed on its own (its own time or date) moves:
            // the carry relabels days, it moves no instant.
            $out[] = ['rule' => $pick['rule'], 'textKept' => false, 'drag' => $first,
                'image' => static fn(int $dn, ?int $m = null): ?int => ($i = $first($dn, $m)) === null ? null : $second($i, $rule->memberOf($i)),
            ] + $r;
        }
        return $out;
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
        // Two rules of one kind and interval whose days agree over one whole
        // cycle's length from a day (Bounds, one cycle per family) agree
        // for all time. Their first two periods are compared first, so a
        // candidate that differs at once costs no cycle.
        $same = function (Rule $a) use ($rule): bool {
            $from = self::firstOn($a, $this->old->startDn);
            $p = $a->periodOf($from);
            foreach ([$a->periodFirst($a->periodPlus($p, 2 * $a->interval)) - 1, $from + self::cycleAfter($a, $from, $from)[1] - 1] as $hi) {
                $days = self::patternDays($a, $from, $hi);
                if ($days !== self::patternDays($rule, $from, $hi)) {
                    return false;
                }
            }
            return $days !== [];
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
        for ($p = $rule->periodOf($from); $rule->periodFirst($p) <= $hi; $p = $rule->periodPlus($p, $rule->interval)) {
            foreach ($rule->periodDays($p) as [$d]) {
                if ($d >= $from && $d <= $hi) {
                    $out[] = $d;
                }
            }
        }
        return $out;
    }

    /**
     * Whether a supported rule counted from $start gives $dn: the start
     * itself always (the expander yields DTSTART whatever the rule), else a
     * day of its pattern in a period the rule counts from the start's.
     */
    private static function patternGives(Rule $rule, int $start, int $dn): bool
    {
        if ($dn === $start) {
            return true;
        }
        if ($dn < $start || !$rule->counted($rule->periodOf($dn), $rule->periodOf($start))) {
            return false;
        }
        foreach ($rule->periodDays($rule->periodOf($dn)) as [$d]) {
            if ($d === $dn) {
                return true;
            }
        }
        return false;
    }

    /**
     * One whole cycle of a supported rule's periods after $dn's period
     * (Bounds, How far an unbounded series is read): the last day of the
     * cycle's last counted period, the cycle's length in days, and its
     * length in calendar periods. Daily and weekly rules repeat every
     * counted period; monthly and yearly ones with the calendar's 400-year
     * cycle, 4,800 / gcd(n, 4,800) or 400 / gcd(n, 400) of their periods.
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function cycleAfter(Rule $r, int $startDn, int $dn): array
    {
        $counted = match ($r->kind) {
            'monthly' => intdiv(4800, self::gcd($r->interval, 4800)),
            'yearly' => intdiv(400, self::gcd($r->interval, 400)),
            default => 1,
        };
        $p0 = $r->periodOf($startDn);
        $p = $r->periodPlus($r->periodOf($dn), 1);
        while (!$r->counted($p, $p0)) {
            $p = $r->periodPlus($p, 1);
        }
        $first = $r->periodFirst($p);
        $end = $r->periodFirst($r->periodPlus($p, $counted * $r->interval)) - 1;
        return [$end, $end - $first + 1, $counted * $r->interval];
    }

    /**
     * What a cycle walk gave, by everything it depends on: the old pattern's
     * days from its start, a reading's images over the cycle, and the new
     * rule's days and gains. A plan asks for each again for every choice
     * combination and every option's plan, and a series is planned again
     * with each choice, so the last few are kept across plans.
     *
     * @var array<string,mixed>
     */
    private static array $walks = [];
    private const WALKS_KEPT = 8;

    /** @return mixed what $make gave for $key, made once */
    private static function walked(string $key, callable $make): mixed
    {
        if (!array_key_exists($key, self::$walks)) {
            if (count(self::$walks) >= self::WALKS_KEPT) {
                unset(self::$walks[array_key_first(self::$walks)]);
            }
            self::$walks[$key] = $make();
        }
        return self::$walks[$key];
    }

    /**
     * The old rule's raw occurrences from its start through $hi by its own
     * arithmetic, each with the member that gives it: the start itself
     * first (an import's start off its rule is still its first occurrence,
     * with no member), then every day of its pattern in the periods it
     * counts. Read once per plan, as far as any reading needs.
     *
     * @return list<array{0:int,1:?int}>
     */
    private function oldDays(int $hi): array
    {
        $start = $this->old->startDn;
        $r = $this->r0;
        $key = json_encode(['old', $r->text(), $start]);
        $have = self::$walks[$key] ?? null;
        if ($have === null || $have[0] < $hi) {
            unset(self::$walks[$key]);
            $have = self::walked($key, static function () use ($r, $start, $hi): array {
                $out = [];
                for ($p = $r->periodOf($start); $r->periodFirst($p) <= $hi; $p = $r->periodPlus($p, $r->interval)) {
                    foreach ($r->periodDays($p) as [$d, $m]) {
                        if ($d >= $start && $d <= $hi) {
                            $out[] = [$d, $m];
                        }
                    }
                }
                if ($out === [] || $out[0][0] !== $start) {
                    array_unshift($out, [$start, null]);
                }
                return [$hi, $out];
            });
        }
        $days = $have[1];
        if ($have[0] === $hi) {
            return $days;
        }
        $out = [];
        foreach ($days as $dm) {
            if ($dm[0] > $hi) {
                break;
            }
            $out[] = $dm;
        }
        return $out;
    }

    /** How many first days a class lists as examples. */
    private const EXAMPLES = 5;

    /**
     * Adds a day to its class of losses or gains (Bounds, Losses and gains
     * by class): its first days as examples, its count, and how many of
     * them fall in one cycle, the days after $span[0] through $span[1].
     *
     * @param array<string,array{why:string,member:?int,days:list<int>,count:int,inCycle:int}> $classes
     * @param ?array{0:int,1:int} $span
     */
    private static function classAdd(array &$classes, string $key, string $why, ?int $member, int $dn, ?array $span): void
    {
        $classes[$key] ??= ['why' => $why, 'member' => $member, 'days' => [], 'count' => 0, 'inCycle' => 0];
        if (count($classes[$key]['days']) < self::EXAMPLES) {
            $classes[$key]['days'][] = $dn;
        }
        $classes[$key]['count']++;
        if ($span !== null && $dn > $span[0] && $dn <= $span[1]) {
            $classes[$key]['inCycle']++;
        }
    }

    /**
     * A class as the plan reports it: why (a loss's), the member concerned
     * by name (a loss with no day: the new form that gives none; a gain:
     * the new member that gives it), the rule's period, the count (every
     * one for a bounded series, and for the days before a moved start,
     * which are finite whatever the end; null otherwise), its first days,
     * and for a series with no end its rate over the cycle in calendar
     * periods, reduced ("5 in every 12 months"); and the words an API
     * caller reads.
     *
     * @param array{why:string,member:?int,days:list<int>,count:int,inCycle:int} $c
     * @param ?array{end:int,days:int,periods:int,gainTo:int} $cycle
     * @param array<int,?int> $images old occurrence => image
     * @param array<int,?int> $memberAt old occurrence => member
     */
    private function classOut(array $c, ?array $cycle, Rule $r1p, array $images, array $memberAt, bool $gain): array
    {
        $y = $this->thisYear;
        $unit = self::unitOf($this->r0);
        $out = $gain ? [] : ['why' => $c['why']];
        $out['count'] = $cycle === null || $c['why'] === 'start' ? $c['count'] : null;
        $out['dates'] = array_map(static fn(int $d): string => Cal::date($d), $c['days']);
        $name = null;
        if ($gain) {
            $name = $c['member'] === null || !$r1p->supported() ? null : self::memberName($r1p, (int) $c['member']);
        } elseif ($c['why'] === 'noday' && $c['member'] !== null && $r1p->supported()) {
            // The member's new form: where any day of the member has an
            // image, the new rule's member giving that image is it (a
            // bounded series' days carry no member until asked).
            foreach ($images as $dn => $img) {
                if ($img !== null && ($memberAt[$dn] ?? $this->r0->memberOf($dn)) === $c['member'] && ($idx = $r1p->memberOf($img)) !== null) {
                    $name = self::memberName($r1p, $idx);
                    break;
                }
            }
            $name ??= self::memberName($this->r0, (int) $c['member']);
        }
        if ($name !== null) {
            $out['member'] = $name;
            $out['period'] = $unit;
        }
        if ($cycle !== null && $c['inCycle'] > 0) {
            $g = self::gcd($c['inCycle'], $cycle['periods']);
            $out['every'] = ['count' => intdiv($c['inCycle'], $g), 'span' => intdiv($cycle['periods'], $g), 'unit' => $unit];
        }
        $shown = count($c['days']);
        $names = array_map(static fn(int $d): string => Words::dayMonth($d, $y), $c['days']);
        if ($out['count'] !== null && $c['count'] > $shown) {
            $names[] = ($c['count'] - $shown) . ' more';
        }
        $examples = Words::andList($names);
        $one = $shown === 1 && ($out['count'] === null || $c['count'] === 1);
        $after = isset($out['every'])
            ? ', and ' . ($out['every']['span'] === 1 ? ($out['every']['count'] === 1 ? '' : $out['every']['count'] . ' ') . 'every ' . $unit : $out['every']['count'] . ' in every ' . $out['every']['span'] . ' ' . $unit . 's') . ' after ' . ($one ? 'it' : 'them')
            : '';
        $out['words'] = match (true) {
            $gain => 'Adds ' . $examples . $after,
            $c['why'] === 'start' => $examples . ($one ? ' falls' : ' fall') . ' before the new start',
            $c['why'] === 'end' => $examples . ($one ? ' falls' : ' fall') . ' after the new end',
            default => ($name !== null ? 'No ' . $name . ' in some ' . $unit . 's, so ' : '') . $examples . ($one ? ' has' : ' have') . ' no event' . $after,
        };
        return $out;
    }

    /** The rule's period as a noun: day, week, month or year. */
    private static function unitOf(Rule $r): string
    {
        return match ($r->kind) {
            'daily' => 'day', 'weekly' => 'week', 'yearly' => 'year', default => 'month',
        };
    }

    /**
     * A member's short name, as the rule's own words name it, without the
     * article or a clause about other months: "31st", "fifth Tuesday",
     * "second weekday", "29 February", "last day of February", "Monday".
     */
    private static function memberName(Rule $r, int $idx): string
    {
        $strip = static fn(string $w): string => preg_replace('/^the |, (skipping months without (one|them)|or the last day( in shorter months)?)$/', '', $w) ?? $w;
        if ($r->kind === 'yearly') {
            $mem = $r->members[0] ?? null;
            $month = $r->months[$idx] ?? null;
            if ($mem === null || $month === null) {
                return '';
            }
            return $mem['t'] === 'day' && (int) $mem['n'] > 0
                ? (int) $mem['n'] . ' ' . Words::monthName((int) $month)
                : $strip(Words::member($mem)) . ' of ' . Words::monthName((int) $month);
        }
        if ($r->kind === 'monthly') {
            $one = clone $r;
            $one->members = [$r->members[$idx]];
            return $strip($strip(Words::monthMembers($one)));
        }
        if ($r->kind === 'weekly') {
            return Words::day((int) $r->members[$idx]);
        }
        return 'day';
    }

    /**
     * The first day on or after $from that a supported rule gives, counted
     * from $from's period, searched through the rule's cycle (Rule::cycle);
     * a rule with no day in it has none ever, and its series is its start
     * alone, which is $from (the start is always the first occurrence).
     */
    private static function firstOn(Rule $rule, int $from): int
    {
        $p = $rule->periodOf($from);
        for ($i = 0, $n = $rule->cycle(); $i < $n; $i++) {
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
        // The day a row's own values were set against, read so that its
        // image less it is how far its occurrence moved on the calendar the
        // rows showed on: under the moment that is the old zone's, since the
        // carry relabels days and moves no instant. The image there is the
        // reading's drag before the carry where it has one, else (a new
        // rule, whose days are the new zone's) its new key read on that
        // calendar; never a day of the new zone less one of the old.
        $fromOf = function (string $key, ?string $newKey = null) use ($reading, $member, $imageOf): int {
            $odn = $this->old->dnOf($key);
            $img = $imageOf($odn);
            $dragged = isset($reading['drag']) ? ($reading['drag'])($odn, $member($odn)) : null;
            if ($img !== null && $dragged === null && $this->shownFrame !== null && $newKey !== null && $newKey !== '') {
                $zone = Time::zone($this->shownFrame[0]);
                $dragged = $odn + Cal::dnOf(Time::fromDb($newKey)->setTimezone($zone)) - Cal::dnOf(Time::fromDb($key)->setTimezone($zone));
            }
            return $img === null || $dragged === null ? $odn : $odn + ($img - $dragged);
        };
        // Nothing about the expansion changes (the reading moves no day, and
        // the rule, start, zone, all-day flag and clock stay): the new series
        // is the stored one, and each key is its own image.
        $unchanged = $reading['id'] === 'same' && !$newRule && $this->ruleDelta === null && $this->startTo === null && $clock1 === $this->clock0
            && Time::normalizeTzid($this->tz1) === Time::normalizeTzid($this->tz0) && $this->allDay1 === $this->allDay0;

        // The old raw occurrences this needs, each with its image (Bounds,
        // How far an unbounded series is read): all of a bounded series; of
        // an unbounded one, from the start through the latest day the change
        // names, then one whole cycle of the rule's periods, then on by the
        // change's reach (old occurrences through the cycle plus twice the
        // reach, gains looked for through the cycle plus the reach), by the
        // rule's own arithmetic over its periods, never an expansion. A rule
        // outside the drag table has one reading, every day its own image,
        // and is read through the days named alone; so is a new rule's
        // "keep" reading, whose start is named and whose days are no drag's
        // images (a new rule reports no loss or gain).
        $exDns = array_map(fn(string $k): int => $this->old->dnOf($k), array_merge($this->exdates, array_map(static fn(array $r): string => (string) $r['recurrence_instance_utc'], $this->rows)));
        $named = max([$this->actedDn0, $landedDn, $this->old->startDn, $reading['start'] ?? $this->old->startDn, ...$exDns]);
        $gainTo = PHP_INT_MAX;
        /** @var ?array{end:int,days:int,gainTo:int} one cycle: its last day, its length in days, and how far gains are looked for */
        $cycle = null;
        /** @var array<int,int> old occurrence => its member, where the rule has members */
        $memberAt = [];
        $images = [];
        if (!$this->readable) {
            $oldOcc = [];
        } elseif ($this->old->bounded()) {
            $oldOcc = $this->old->all();
            foreach ($oldOcc as $dn) {
                $images[$dn] = $imageOf($dn);
            }
        } elseif ($this->r0->supported() && $reading['id'] !== 'keep') {
            // Once per reading of a landing: the images are a function of
            // the reading, where the day landed and the days named, and the
            // plan asks for them again for each choice combination.
            $imagesKey = json_encode(['images', $this->r0->text(), $this->old->startDn, $this->actedDn0, $reading['id'], $reading['rule']->text(), $land['dn'], $land['clock'], $reading['start'] ?? null, $named]);
            [$images, $memberAt, $oldOcc, $gainTo, $cycle] = self::walked($imagesKey, function () use ($reading, $image, $named, &$images, &$memberAt): array {
                // The new start is a day the change names too: a reading's
                // own start is among the days named above, and when the
                // earliest image falls after them (every occurrence before it
                // has none) the cycle is taken after it, so the new rule's
                // days are looked at over a whole cycle from where it starts.
                $from = $named;
                $reach = 0;
                $periodsMoved = 0;
                $r0 = $this->r0;
                do {
                    [$cycleEnd, $cycleDays, $cyclePeriods] = self::cycleAfter($this->r0, $this->old->startDn, $from);
                    $earliest = null;
                    foreach ($this->oldDays($cycleEnd) as [$dn, $m]) {
                        if (!array_key_exists($dn, $images)) {
                            $images[$dn] = $image($dn, $m);
                            $memberAt[$dn] = $m;
                        }
                        $img = $images[$dn];
                        if ($img !== null) {
                            $reach = max($reach, abs($img - $dn));
                            $periodsMoved = max($periodsMoved, abs($r0->periodsBetween($r0->periodOf($dn), $r0->periodOf($img))));
                            $earliest = $earliest === null ? $img : min($earliest, $img);
                        }
                    }
                    $again = $earliest !== null && $earliest > $from;
                    $from = $earliest ?? $from;
                } while ($again);
                foreach ($this->oldDays($cycleEnd + 2 * $reach) as [$dn, $m]) {
                    if (!array_key_exists($dn, $images)) {
                        $images[$dn] = $image($dn, $m);
                        $memberAt[$dn] = $m;
                    }
                }
                return [$images, $memberAt, array_keys($images), $cycleEnd + $reach, ['end' => $cycleEnd, 'days' => $cycleDays, 'periods' => $cyclePeriods, 'gainTo' => $cycleEnd + $reach, 'moved' => $periodsMoved]];
            });
        } else {
            $oldOcc = $this->old->occurrences($named);
            foreach ($oldOcc as $dn) {
                $images[$dn] = $imageOf($dn);
            }
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
        $anchor = $this->ruleDelta !== 'none';
        $calcP = new Calc($r1p, $newStart, $this->tz1, $this->allDay1, $clock1, $this->len1, $anchor, $this->old->storedStart());
        $latest = $imageSet === [] ? null : max($imageSet);

        // Gains: occurrences of the new rule that are no old one's image.
        $edgeGains = [];
        $gains = [];
        $edgeAt = [];
        /** @var array<int,int> gained day => the new rule's member that gives it */
        $gainMember = [];
        /** @var ?array<int,int> the new rule's days through $newDaysTo, each with its member, when read by arithmetic */
        $newDays = null;
        $newDaysTo = PHP_INT_MAX;
        if (!$newRule && !$unchanged && $imageSet !== []) {
            // Through the latest image of a bounded series (the bound ends
            // there); through the cycle plus the reach of one with no end,
            // where every new day has its preimages read (Bounds).
            $to = $cycle === null ? (int) $latest : $gainTo;
            $gainsKey = json_encode(['gains', $imagesKey ?? null, $r1p->text(), $newStart, $to]);
            $makeGains = static function () use ($imageSet, $r1p, $newStart, $to, $calcP): array {
                $gainSet = [];
                $gainMember = [];
                $newDays = null;
                $newDaysTo = PHP_INT_MAX;
                $inImages = array_flip(array_values($imageSet));
                $gainSet = [];
                // The new rule's days by its own arithmetic (the images are),
                // each with its member, the expander's only for a rule outside
                // the table.
                if ($r1p->supported()) {
                    $newDays = [];
                    $newDaysTo = $to;
                    for ($p = $r1p->periodOf($newStart); $r1p->periodFirst($p) <= $to; $p = $r1p->periodPlus($p, $r1p->interval)) {
                        foreach ($r1p->periodDays($p) as [$d, $m]) {
                            if ($d >= $newStart && $d <= $to) {
                                $newDays[$d] = $m;
                                if (!isset($inImages[$d])) {
                                    $gainSet[$d] = true;
                                    $gainMember[$d] = $m;
                                }
                            }
                        }
                    }
                } else {
                    foreach ($calcP->occurrences($to) as $d) {
                        if (!isset($inImages[$d])) {
                            $gainSet[$d] = true;
                        }
                    }
                }
                return [$gainSet, $gainMember, $newDays, $newDaysTo];
            };
            [$gainSet, $gainMember, $newDays, $newDaysTo] = isset($imagesKey) ? self::walked($gainsKey, $makeGains) : $makeGains();
            // A gain at an edge is the image of a day of the old pattern
            // outside the series, before its start or after its end (Bounds:
            // the first or last k periods of a member moved k periods); any
            // other gain is a day the new form gives where the old gave none.
            $edgeAt = $this->edgeSources($gainSet, $image, $images, $cycle['moved'] ?? null);
            foreach (array_keys($gainSet) as $d) {
                if (isset($edgeAt[$d])) {
                    $edgeGains[] = $d;
                } else {
                    $gains[] = $d;
                }
            }
        }
        $genSkips = [];
        if ($edgeGains !== []) {
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

        // The bound. $actedFirst: the plan's own bound (a kept count) cuts
        // off the day acted on, which it keeps on its own (The Day Acted On
        // Comes First). A bound the person sent is their own statement, so a
        // day acted on it cuts off is an ordinary day outside it.
        $actedFirst = false;
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
            // N without a COUNT is the bounded series' own occurrences (its UNTIL's).
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
                    $actedFirst = $splits;
                } else {
                    $r1 = $this->r0->count !== null ? $r1p->withBound($endCount, null) : $r1p->withBound(null, $untilAt((int) $latest));
                }
            }
        }
        // A rule the change writes is in the series' own form after it
        // (Recurrence::inForm): UNTIL in the start's form, and no clock parts
        // on an all-day series. An UNTIL written on a timed series' clock is
        // read in the zone it was written in when the series becomes all-day.
        if (!$unchanged) {
            $inForm = \BetterCal\Domain\Recurrence::inForm($r1->text(), $this->allDay1, $this->allDay1 ? $this->tz0 : $this->tz1);
            if ($inForm !== $r1->text()) {
                $r1 = Rule::parse($inForm, $newStart);
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
        // Whether a day is one of the new series: a bounded series' days are
        // read whole; a series with no end whose rule is in the table is
        // asked by the rule's arithmetic, as its images and gains are (an
        // anchored start's stored day is before the first day, so outside).
        $inside = fn(?int $d): bool => $d !== null && ($all1 !== null || !$calc1->rule->supported()
            ? $calc1->isOccurrence($d)
            : ($newDays !== null && $d <= $newDaysTo && $calc1->startDn === $newStart
                ? isset($newDays[$d])
                : $d >= $calc1->startDn && self::patternGives($calc1->rule, $calc1->startDn, $d)));
        // An old key's new key (Correspondence, by Member): the new series'
        // key on its image day, at its place among that day's keys (a rule
        // several times a day has several), as the expander gives it; null
        // when the new series has none there. In the same expansion on the
        // same day, that is the key itself. A key the new series has on its
        // image day is its own new key (a new pattern keeps the keys on its
        // days), and a key at its place that another key of the old day
        // keeps that way is that key's, so no two old keys share one: a
        // rule several times a day replaced by one with fewer keys a day
        // keeps every key the new one shares.
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
            if (in_array($key, $on, true)) {
                return $key;
            }
            $oldOn = $this->old->keysOn($odn);
            $at = array_search($key, $oldOn, true);
            $placed = $on[$at === false ? 0 : $at] ?? null;
            return $placed !== null && in_array($placed, $oldOn, true) ? null : $placed;
        };
        // A generated skip past the new end needs nothing (Bounds, gains at the edges).
        $genSkips = array_values(array_filter($genSkips, $inside));
        // Days outside the new series (Bounds: Start, End and Days Outside):
        // the edited days, and the day acted on where the request put it,
        // that have no new key. The day acted on that the plan's own bound
        // cut off is kept on its own (The Day Acted On Comes First); every
        // other day outside, the day acted on under a bound the person sent
        // included, takes the outside group's choice.
        $actedNk = $newKey($this->acted, $actedImg);
        $actedOut = $actedPlaced && $actedNk === null;
        $grouped = [];
        foreach ($this->rows as $id => $row) {
            $key = (string) $row['recurrence_instance_utc'];
            if (!($id === $this->actedRowId && $actedPlaced) && $newKey($key, $imageOf($this->old->dnOf($key))) === null) {
                $grouped[] = $key;
            }
        }
        if ($actedOut && !$actedFirst) {
            $grouped[] = $this->acted;
        }
        $outside = 'keep';
        if ($grouped !== []) {
            $one = count($grouped) === 1;
            $outside = $this->pick('outside', 'Edited days outside the new series', [
                ['id' => 'keep', 'label' => $one ? 'Keep it as an event of its own' : 'Keep them as events of their own'],
                ['id' => 'delete', 'label' => $one ? 'Delete it' : 'Delete them'],
            ], 'keep', ['count' => count($grouped)] + ($one ? ['day' => self::keyOut($grouped[0])] : []));
        }
        // Each day's one outcome, from this one function: in the series on
        // its new key ('in'), or outside it an event of its own ('separate')
        // or deleted ('deleted'). So no day is in two of the plan's lists.
        $outcomeOf = function (string $key, ?int $img, bool $acted) use ($newKey, $actedFirst, $outside): array {
            $nk = $newKey($key, $img);
            return [$nk, match (true) {
                $nk !== null => 'in',
                $acted && $actedFirst, $outside === 'keep' => 'separate',
                default => 'deleted',
            }];
        };
        [, $actedOutcome] = $outcomeOf($this->acted, $actedImg, true);
        $separate = $actedOut && $actedOutcome === 'separate';
        $actedDeleted = $actedOut && $actedOutcome === 'deleted';

        // Skipped days.
        $newExdates = [];
        $days = [];
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
        foreach ($this->rows as $id => $row) {
            $key = (string) $row['recurrence_instance_utc'];
            $odn = $this->old->dnOf($key);
            $img = $imageOf($odn);
            $isActed = $id === $this->actedRowId && $actedPlaced;
            [$nk, $outcome] = $outcomeOf($key, $img, $isActed);
            $ownNow[$id] = $this->ownOf($row);
            if ($isActed && $outcome !== 'in') {
                continue; // the day acted on's own outcome, below
            }
            if ($isActed) {
                $asSet[$id] = array_merge($row, $land['cols']);
                $ownNow[$id] = OwnFields::normalize(array_merge(array_diff($ownNow[$id], self::PLACEMENT), $this->placementOwn($asSet[$id], $nk)));
            }
            $newKeys[$id] = $nk;
            if ($outcome === 'in') {
                $placeRows[$id] = (int) $img;
            } else {
                $outsideRows[$id] = $outcome;
            }
        }
    
        // The occurrence acted on with no row of its own is an exception like
        // any other, its row the occurrence as it showed: where the request
        // put it is its placement, its own where that isn't what its image
        // gives (a day in a skipped hour, whose key the expander shifts). A
        // placement of its own makes it an edited day, so the plan creates its
        // row (Changing the Series from an Edited Day, 3).
        $createEdited = null;
        if ($actedPlaced && $this->actedRowId === null && ($nk = $actedNk) !== null) {
            $occ = ['override_fields' => '[]', 'recurrence_instance_utc' => $this->acted] + self::occurrenceOf($this->master, [], $this->acted, $this->len0);
            $put = array_merge($occ, $land['cols']);
            $own = OwnFields::normalize($this->placementOwn($put, $nk));
            if ($own !== []) {
                [$cs, $ce, $cAll, $cTz] = $this->place($put, $own, (int) $actedImg, $nk, false, (int) $actedImg, $clock1, $clock1);
                $createEdited = [
                    'recurrence_instance_utc' => $nk, 'start_utc' => Time::toDb($cs), 'end_utc' => Time::toDb($ce),
                    'all_day' => $cAll ? 1 : 0, 'tzid' => $cTz, 'override_fields' => OwnFields::encode($own),
                ];
                // Where it showed, the occurrence at its old key; where it was put, the row created.
                $sides = $this->rowSides($occ, $createEdited);
                $days[] = ['key' => $this->acted, 'newKey' => $nk, 'kind' => 'acted', 'outcome' => 'moves', 'kept' => $own, 'takes' => []] + $sides
                    + Words::dayParts('This one, ' . Words::date(self::dayOf($sides['after']), $this->thisYear), 'keeps where it was put', ', ');
            }
        }
        // Days on a date of their own from before this edit: kept, or moved
        // with their events this once. The acted day's date is the one the
        // request just set, so it is no such day.
        $movedRows = [];
        foreach ($placeRows as $id => $img) {
            if (!isset($asSet[$id]) && in_array('date', $ownNow[$id], true) && $img !== $fromOf((string) $this->rows[$id]['recurrence_instance_utc'], $newKeys[$id] ?? null)) {
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
                    [$s, , $all, $sTz] = $this->place($one, $ownNow[$oneId], (int) $placeRows[$oneId], (string) $newKeys[$oneId], $move, $fromOf((string) $one['recurrence_instance_utc'], (string) $newKeys[$oneId]), $clock1, $this->clock0);
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
        if ($newRruleText !== null) {
            $newRruleText = \BetterCal\Domain\Recurrence::inForm($newRruleText, $this->allDay1, $this->allDay1 ? $this->tz0 : $this->tz1);
        }
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
        if ($newExdates !== $storedSkips) {
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
        // At Google an instance of an all-day series can't be given a clock
        // time (probe 2.2a): a day that stays timed under an all-day master
        // can't be carried there, so the change is refused with its reason.
        $timedUnderAllDay = [];
        if ($this->google && $this->allDay1 && $createEdited !== null && (int) $createEdited['all_day'] === 0) {
            $timedUnderAllDay[] = 0;
        }
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
                : $this->place($row, $own, (int) $img, $newKey, $moveOwnDates, $fromOf((string) $row['recurrence_instance_utc'], $newKey), $clock1, $this->clock0);
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
            if ($this->google && $this->allDay1 && !$allDay) {
                $timedUnderAllDay[] = $id;
            }
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
            if (OwnFields::normalize($ownAfter) === []) {
                // Left with nothing of its own, no field and no trip (a row
                // exists for what refers to it too), it is an ordinary
                // occurrence again (Own Fields): its row goes, its key in
                // Undo, and the series shows the day at its new key.
                $delete[] = $id;
                $rejoined[$id] = true;
                unset($tags[$id], $people[$id]);
                $name = in_array('title', $ownBefore, true) ? "'" . (string) $row['title'] . "' " : '';
                $sides = $this->rowSides($row, self::rowOf($start, $allDay, $tz));
                $days[] = ['key' => (string) $row['recurrence_instance_utc'], 'newKey' => $newKey, 'kind' => 'edited',
                    'title' => (string) $row['title'], 'outcome' => 'rejoins'] + $sides
                    + Words::dayParts($name . Words::date($odn, $this->thisYear), 'goes back to the series, on ' . Words::date(self::dayOf($sides['after']), $this->thisYear))
                    + ['kept' => [], 'takes' => OwnFields::normalize($edited)];
                continue;
            }
            if ($cols !== []) {
                $overrideWrites[$id] = $cols;
            }
            // Its outcome is its instant and kind compared, never its dates
            // and never its end: a day that keeps its start and kind and
            // only grows or shrinks stays where it is.
            $moved = Time::toDb($start) !== (string) $row['start_utc'] || $allDay !== ((int) $row['all_day'] === 1);
            // The days it showed on before and after, and where it shows after
            // as an instant, each a placement by the zone rule: a date and a
            // clock of the same instant in one zone.
            $sides = $this->rowSides($row, self::rowOf($start, $allDay, $tz));
            $shownBefore = self::dayOf($sides['before']);
            $newShown = self::dayOf($sides['after']);
            $newClock = substr((string) ($sides['after']['at'] ?? ''), 11, 5);
            $name = in_array('title', $ownBefore, true) ? "'" . (string) $row['title'] . "' " : '';
            // What it keeps, in words from the one table of own fields
            // (OWN_WORDS): every field it can own but its placement, which
            // its outcome says, references (trip, duplicates, plugin data) included.
            $keeps = array_values(array_intersect(array_diff(array_keys(self::OWN_WORDS), self::PLACEMENT), $ownAfter));
            $keepWords = array_map(static fn(string $f): string => self::OWN_WORDS[$f], $keeps);
            // A timed day at its own time while the series' time changes says
            // the time it keeps ("keeps its own time at 09:00", the examples' all-day case).
            // The clock is the one it shows at, before and after (its placements).
            if ($seriesTime && !$allDay && (int) $row['all_day'] === 0 && array_intersect(['time', 'allDay'], $own) !== []
                && substr((string) $sides['before']['at'], 11, 5) === $newClock) {
                $keepWords[] = 'own time at ' . $newClock;
            }
            $keepsWords = $keepWords === [] ? '' : ', keeps its ' . Words::andList($keepWords);
            // A field it owned that it takes now (from its own row, or "Give it the new title too").
            $taken = array_values(array_intersect(array_keys($this->changed), array_diff($ownBefore, $ownAfter)));
            if (in_array('title', $taken, true)) {
                $keepsWords .= ", takes '" . (string) ($this->changed['title']['title'] ?? '') . "'";
                $taken = array_values(array_diff($taken, ['title']));
            }
            if ($taken !== []) {
                $keepsWords .= ', takes the new ' . Words::andList(array_map(static fn(string $f): string => self::OWN_WORDS[$f], $taken));
            }
            // Named by the day it is about, and, on a date of its own, the date set on it.
            if (isset($sides['ownDate']) && self::dayOf($sides['ownDate']) !== $odn) {
                // Where it shows after: another day, the same day at another time, or unchanged.
                $clockMoved = !$allDay && Time::toDb($start) !== (string) $row['start_utc'];
                $where = $newShown !== $shownBefore ? 'moves to ' . Words::date($newShown, $this->thisYear) : ($clockMoved ? 'moves to ' . $newClock : 'stays there');
                $said = Words::dayParts($name . Words::date($odn, $this->thisYear) . ', moved to ' . Words::date(self::dayOf($sides['ownDate']), $this->thisYear), $where . $keepsWords, ': ');
            } else {
                // A day that keeps its date and changes its time says the time.
                $to = $newShown === $shownBefore && !$allDay ? $newClock : Words::date($newShown, $this->thisYear);
                $said = Words::dayParts($name . Words::date($odn, $this->thisYear), ($moved ? 'moves to ' . $to : 'stays') . $keepsWords);
            }
            // As data beside the words: the own fields it keeps, and the fields
            // this edit changes that it takes from the series.
            $days[] = ['key' => (string) $row['recurrence_instance_utc'], 'newKey' => $newKey, 'kind' => 'edited',
                'title' => (string) $row['title'], 'outcome' => $moved ? 'moves' : 'stays'] + $sides
                // Where it shows after, as an instant (timed days), as `after` gives it: beside newKey,
                // the series' own time there; in an all-day plan, in its own zone, named.
                + array_intersect_key($sides['after'], ['at' => true, 'tz' => true]) + $said + [
                'kept' => OwnFields::normalize($ownAfter), 'takes' => OwnFields::normalize(array_diff($edited, $ownAfter))];
        }
        if ($timedUnderAllDay !== []) {
            return $this->refused('googleAllDay');
        }
        // The day acted on as an event of its own, where the plan's days
        // say it shows ('separate', whichever way it got that outcome): what
        // `next` lists for it, so the two parts of the plan agree. Other days
        // kept on their own leave the series and aren't listed.
        $actedAlone = null;
        foreach ($outsideRows as $id => $outcome) {
            $row = $this->rows[$id];
            // Deleted, it has no after; detached as it is, its after is the row itself.
            $sides = $this->rowSides($row, $outcome === 'deleted' ? null : $row);
            $label = "'" . (string) $row['title'] . "' " . Words::date($this->old->dnOf((string) $row['recurrence_instance_utc']), $this->thisYear);
            if ($outcome === 'deleted') {
                $delete[] = $id;
                $days[] = ['key' => (string) $row['recurrence_instance_utc'], 'newKey' => null, 'kind' => 'edited', 'title' => (string) $row['title'], 'outcome' => 'deleted'] + $sides + Words::dayParts($label, 'is deleted') + ['kept' => $this->ownOf($row)];
            } else {
                $detach[$id] = [];
                if ($id === $this->actedRowId && !$newRule) {
                    // The day acted on as it is (the request didn't place it), outside a bound the person sent.
                    // Under a new pattern it has no special place: an edited day like any other.
                    $actedAlone = $row;
                }
                $days[] =['key' => (string) $row['recurrence_instance_utc'], 'newKey' => null, 'kind' => 'edited', 'title' => (string) $row['title'], 'outcome' => 'separate'] + $sides + Words::dayParts($label, 'becomes an event of its own') + ['kept' => $this->ownOf($row)];
            }
        }
        if ($actedDeleted && $this->actedRowId !== null) {
            // Cut off by a bound the person sent and deleted by the outside
            // choice: its row goes, as any day outside does. With no row it
            // is an occurrence past the new end, a loss.
            $row = $this->rows[$this->actedRowId];
            $delete[] = $this->actedRowId;
            $label = "'" . (string) $row['title'] . "' " . Words::date($this->actedDn0, $this->thisYear);
            $days[] = ['key' => $this->acted, 'newKey' => null, 'kind' => 'edited', 'title' => (string) $row['title'], 'outcome' => 'deleted'] + $this->rowSides($row, null) + Words::dayParts($label, 'is deleted') + ['kept' => $this->ownOf($row)];
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
            $actedAlone = $placed;
            // Where it showed (its row, or the occurrence at its key) and the
            // standalone row the commit writes.
            $occ = $this->actedRowId !== null ? $this->rows[$this->actedRowId] : ['override_fields' => '[]', 'recurrence_instance_utc' => $this->acted] + self::occurrenceOf($this->master, [], $this->acted, $this->len0);
            $sides = $this->rowSides($occ, $placed);
            $days[] = ['key' => $this->acted, 'newKey' => null, 'kind' => $this->actedRowId !== null ? 'edited' : 'acted', 'outcome' => 'separate'] + $sides
                + ($this->actedRowId !== null ? ['title' => (string) $this->rows[$this->actedRowId]['title']] : [])
                + Words::dayParts('This one, ' . Words::date(self::dayOf($sides['after']), $this->thisYear), 'becomes an event of its own', ', ');
        }

        // Words and the rest of the plan.
        $wordsBefore = Words::rule($this->r0, $this->allDay0 ? null : $this->clock0);
        $words = $this->ruleDelta === 'none' ? 'Does not repeat' : Words::rule($r1p, $this->allDay1 ? null : $clock1);
        [$walkBounded, $walkDays, $walkTo] = $this->readable ? $this->walk($calc1, $genSkips, $landedDn) : [null, [], PHP_INT_MAX];
        $walk = [$walkBounded, $walkDays];
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
        if ($separate || ($actedDeleted && $this->actedRowId !== null)) {
            $outcomeDays[$this->actedDn0] = true;
        }
        // A removed day is no event to lose: its skip's outcome names it.
        foreach ($this->exdates as $key) {
            $outcomeDays[$this->old->dnOf($key)] = true;
        }
        // Each by its class (Bounds, Losses and gains by class): why it is
        // lost, with the member concerned, counted with its first days as
        // examples and, for a series with no end, how many fall in one cycle.
        $lossDays = [];
        $lossClasses = [];
        $last1 = $all1 === null ? null : $calc1->last();
        $lossSpan = $cycle === null ? null : [$cycle['end'] - $cycle['days'], $cycle['end']];
        // Which old days have no day in the new series, and why: once per
        // reading and new series when the images were walked over a cycle
        // (then the exceptions' own outcomes are the only thing per plan).
        $lost = [];
        if (!$newRule && !$unchanged) {
            $lostKey = isset($imagesKey, $gainsKey) ? json_encode(['lost', $gainsKey, $calc1->rule->text(), $calc1->startDn, $last1]) : null;
            $findLost = static function () use ($images, $inside, $newStart, $last1): array {
                $out = [];
                foreach ($images as $dn => $img) {
                    if (!$inside($img)) {
                        $out[] = [$dn, $img, $img === null ? 'noday' : ($img < $newStart ? 'start' : ($last1 !== null && $img > $last1 ? 'end' : 'noday'))];
                    }
                }
                return $out;
            };
            $lost = $lostKey === null ? $findLost() : self::walked($lostKey, $findLost);
        }
        foreach ($lost as [$dn, $img, $why]) {
            if (isset($outcomeDays[$dn])) {
                continue;
            }
            if ($img !== null) {
                $lossDays[] = $img;
            }
            $m = $memberAt[$dn] ??= $member($dn);
            self::classAdd($lossClasses, $why === 'noday' ? $why . ':' . $m : $why, $why, $m, $dn, $lossSpan);
        }
        $losses = array_values(array_map(fn(array $c): array => $this->classOut($c, $cycle, $r1p, $images, $memberAt, false), $lossClasses));
        [$boundKind, $boundWords, $boundData] = $this->boundWords($r1, $calc1, $oldOcc, $separate, $outside === 'keep' ? count($outsideRows) : 0, count($genSkips), $personSkipped, $lossDays, $allKeys);
        // A gain past the new end is no gain (P2-6: "Keep 6 events" adds nothing after its sixth).
        $gainClasses = [];
        $gainSpan = $cycle === null ? null : [$cycle['gainTo'] - $cycle['days'], $cycle['gainTo']];
        foreach (array_filter(array_merge($gains, $genSkips === [] ? $edgeGains : []), $inside) as $d) {
            $m = $gainMember[$d] ?? ($r1p->supported() ? $r1p->memberOf($d) : null);
            self::classAdd($gainClasses, (string) $m, 'gain', $m, $d, $gainSpan);
        }
        $gainList = array_values(array_map(fn(array $c): array => $this->classOut($c, $cycle, $r1p, $images, $memberAt, true), $gainClasses));
        // The next events from the day acted on: from where it lands, or from
        // where the request put it when that is earlier (an own date, or a
        // zone kept at its moment), so nothing between them goes unlisted. A
        // start moved alone names no day: the request put the series' start,
        // and that is where it lands (an edited day keyed after it but shown
        // before it isn't next, as any day shown before the cutoff).
        $landsDn = $this->startTo ?? $landedDn;
        $putDn = $this->placed ? self::dayOf($this->placementOut($this->landedCols, $this->displayZone())) : $landsDn;
        // A rule the expander can't read lists nothing, and goes on past
        // that unless it has an end.
        [$next, $nextMore, $cutDn] = $this->readable ? $this->next($calc1, $newExdates, array_diff_key($placeRows, $rejoined), $overrideWrites, min($landsDn, $putDn), $actedAlone, $landedDn, $createEdited === null ? [] : [$createEdited]) : [[], $boundKind === 'none', $landedDn];
        // An option's next events: the same entries, without what only the plan's list says.
        $nextDates = array_map(static fn(array $n): array => array_intersect_key($n, ['start' => true, 'allDay' => true, 'tz' => true]), $next);
        if ($this->google) {
            $this->notes[] = ['kind' => 'google', 'words' => self::GOOGLE_NOTE];
        }
        if ($this->readable) {
            // From coming up's own cutoff through the later of a year on and
            // the last date it shows, so every date it shows is scanned.
            $lastShown = array_reduce($next, static fn(int $m, array $e): int => max($m, self::dayOf(['date' => substr((string) $e['start'], 0, 10)])), $cutDn);
            $this->clockNotes($calc1, $cutDn, max($cutDn + 366, $lastShown));
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
        // The Activity entry: what was changed, its reach (every event, or
        // the part from the day a split starts on, which Split passes), then
        // the rule where it changes and each field changed. A zone changed
        // is named beside each clock, with the choice between the clock
        // time and the moment when one was offered.
        $reach = isset($this->opts['splitDay']) ? 'from ' . Words::date((int) $this->opts['splitDay'], $this->thisYear) . ' on' : 'all events';
        $zoneMoved = !$this->allDay0 && !$this->allDay1 && Time::normalizeTzid($this->tz0) !== Time::normalizeTzid($this->tz1);
        $ruleFrom = $zoneMoved ? Words::rule($this->r0, $this->clock0, self::zoneName($this->tz0)) : $wordsBefore;
        $ruleTo = $zoneMoved && $this->ruleDelta !== 'none' ? Words::rule($r1p, $clock1, self::zoneName($this->tz1)) : $words;
        $zoneGroup = array_values(array_filter($this->groups, static fn(array $g): bool => $g['id'] === 'zone'))[0] ?? null;
        $kept = $zoneGroup === null ? '' : ($zoneGroup['selected'] === 'moment' ? ', keeping the same moment' : ', keeping the clock time');
        $ruleClause = match (true) {
            $startAlone => 'starts ' . Words::dayMonth($newStart, $this->thisYear) . ' (was ' . Words::dayMonth($this->old->startDn, $this->thisYear) . '), ' . Words::lower($words) . ', as before',
            in_array($kind, ['drag', 'start', 'newrule', 'norule'], true) || $ruleTo !== $ruleFrom => Words::lower($ruleFrom) . ', now ' . Words::lower($ruleTo) . $kept . ', from ' . Words::dayMonth($newStart, $this->thisYear),
            default => null,
        };
        $clauses = $ruleClause === null ? [] : [$ruleClause];
        foreach ($this->changed as $field => $cols) {
            $clauses[] = $this->fieldClause($field, $cols);
        }
        foreach ($this->seriesCols as $col => $v) {
            $clauses[] = Words::lower(self::SERIES_COLUMNS[$col]) . ' turned ' . ((int) $v === 1 ? 'on' : 'off');
        }
        // Nothing else to say (a calendar move alone): the rule, as before.
        $summary = ($kind === 'newrule' || $kind === 'norule' ? $verb : $verb . ' ' . $title) . ' (' . $reach . '): '
            . ($clauses === [] ? Words::lower($words) . ', as before' : implode('; ', $clauses)) . '.';
        $detachCount = count(array_filter($days, static fn(array $d): bool => $d['outcome'] === 'separate' && $d['kind'] === 'edited'));
        $ownership = null;
        if ($detachCount > 0) {
            // Each named by the day it is about, then where it goes when that
            // is another day (its after placement, the standalone row).
            $names = array_values(array_map(function (array $d): string {
                $day = self::dayOf(['date' => $d['day']]);
                $went = self::dayOf($d['after']);
                return "'" . ($d['title'] ?? '') . "' on " . Words::dayMonth($day, $this->thisYear) . ($went !== $day ? ', moved to ' . Words::dayMonth($went, $this->thisYear) : '');
            }, array_filter($days, static fn(array $d): bool => $d['outcome'] === 'separate' && $d['kind'] === 'edited')));
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
        // The series' start: an anchored series' is its stored start, no
        // occurrence of it, so a key; any other's in the plan's zone (a date
        // for an all-day series).
        $startIso = $calc1->anchor !== null ? self::keyOut(Time::toDb($start1)) : self::inZoneOut($start1, $this->planZone());
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
                // The day it is about, the date set on it, and where it showed (shows, its date).
                ? ['key' => $iso($this->acted), 'title' => (string) $this->rows[$this->actedRowId]['title']] + $this->rowSides($this->rows[$this->actedRowId], null)
                    + ['kept' => OwnFields::normalize($this->ownOf($this->rows[$this->actedRowId]))]
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
            'walkTo' => $walkTo,
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
     * that day lies. Every image lies some periods from its day, the most
     * being the day acted on's member's (every reading moves it or every
     * member by the same count), so only the old pattern's periods within
     * twice that of the series' first and last can land a day inside it:
     * those are walked, the first and last period themselves among them.
     *
     * @param array<int,true> $gainSet
     * @param array<int,?int> $images old occurrence => its image
     * @param ?int $k the most periods any image moves, when the cycle walk counted it
     * @return array<int,string>
     */
    private function edgeSources(array $gainSet, callable $image, array $images, ?int $k): array
    {
        if ($gainSet === [] || !$this->r0->supported()) {
            return [];
        }
        $r = $this->r0;
        if ($k === null) {
            $k = 0;
            foreach ($images as $dn => $img) {
                if ($img !== null) {
                    $k = max($k, abs($r->periodsBetween($r->periodOf($dn), $r->periodOf($img))));
                }
            }
        }
        $start = $this->old->startDn;
        $last = $this->old->bounded() ? $this->old->last() : null;
        $p0 = $r->periodOf($start);
        $sides = [['start', $r->periodPlus($p0, -2 * $k * $r->interval), $p0]];
        if ($last !== null) {
            $pl = $r->periodOf($last);
            $sides[] = ['end', $pl, $r->periodPlus($pl, 2 * $k * $r->interval)];
        }
        $out = [];
        foreach ($sides as [$side, $from, $to]) {
            for ($p = $from; $p <= $to; $p = $r->periodPlus($p, $r->interval)) {
                foreach ($r->periodDays($p) as [$d, $m]) {
                    if ($d >= $start && ($last === null || $d <= $last)) {
                        continue;
                    }
                    $img = $image($d, $m);
                    if ($img !== null && isset($gainSet[$img]) && !isset($out[$img])) {
                        $out[$img] = $side;
                    }
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

    /**
     * The occurrences a plan shows, for comparing readings past the bound
     * (the walk): from the series' start to the end of a span of periods
     * past $through (the day acted on, or where it landed), so a day acted
     * on long after the start is compared over as many periods as one near
     * it, and every reading walked to the same day ends on the same day.
     *
     * @return list<int>
     */
    private function walk(Calc $calc, array $skips, ?int $through = null): array
    {
        $r = $calc->rule;
        if (!$r->supported()) {
            // A rule outside the drag table has one reading, so nothing is
            // compared: its first 60 days are what the plan lists of it.
            $first = array_slice($calc->occurrences(PHP_INT_MAX, 60), 0, 60);
            return [null, $first, count($first) === 60 ? $first[59] : PHP_INT_MAX];
        }
        $unbounded = $calc->withoutBound();
        // Readings differ by member, the same in every daily or weekly
        // period, so a few periods show any difference; months and years
        // differ by length and first weekday, so their whole cycle.
        $periods = match ($r->kind) {
            'monthly' => intdiv(4800, self::gcd($r->interval, 4800)),
            'yearly' => intdiv(400, self::gcd($r->interval, 400)),
            default => 8,
        };
        $out = [];
        $p = $r->periodOf($calc->startDn);
        // Walked to a day, the same for every reading walked to the same
        // one: the end of the span of periods after it. Counted from each
        // reading's own start, two readings with different starts ended in
        // different periods, and the longer one's last days read as a
        // difference.
        $limit = PHP_INT_MAX;
        if ($through !== null) {
            $limit = $r->periodFirst($r->periodPlus($r->periodOf(max($calc->startDn, $through)), $periods * $r->interval)) - 1;
            $periods = PHP_INT_MAX;
        }
        for ($i = 0; $i < $periods && $p <= ($r->kind === 'yearly' ? 9999 : PHP_INT_MAX) && $r->periodFirst($p) <= $limit; $i++) {
            foreach ($r->periodDays($p) as [$d]) {
                if ($d >= $calc->startDn && $d <= $limit && !in_array($d, $skips, true)) {
                    $out[] = $d;
                }
            }
            $p = $r->periodPlus($p, $r->interval);
        }
        unset($unbounded);
        // The last day the walk read: the day before the first period it
        // didn't reach, or the limit it was walked to.
        $reached = $p > 9999 && $r->kind === 'yearly' ? PHP_INT_MAX : $r->periodFirst($p) - 1;
        return [$calc->bounded() ? array_values(array_diff($calc->all(), $skips)) : null, $out, min($limit, $reached)];
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
     * edited day by the day it shows on (by the zone rule), wherever
     * its key is, and the occurrences with no row at their keys. With them,
     * whether any event of those comes after the four.
     *
     * @return array{0:list<array>,1:bool,2:int} the list, whether more come, and the day it starts from (the cutoff)
     */
    private function next(Calc $calc1, array $exdates, array $placeRows, array $overrideWrites, int $fromDn, ?array $separate, int $actedDn, array $createdRows = []): array
    {
        $skip = array_flip($exdates);
        $byKey = [];
        // Each where it shows after the change (placementEntryOut, by the
        // zone rule), with the day it shows on.
        $entry = function (array $row, string $kind): array {
            $e = $this->placementEntryOut($row, $this->displayZone());
            return $e + ['kind' => $kind, 'dn' => self::dayOf(['date' => substr($e['start'], 0, 10)])];
        };
        if ($separate !== null) {
            // The list starts where the day acted on shows on its own, when that is earlier.
            $fromDn = min($fromDn, $entry($separate, 'acted')['dn']);
        }
        foreach ($createdRows as $c) {
            $byKey[(string) $c['recurrence_instance_utc']] = $entry($c, 'acted');
            $fromDn = min($fromDn, $byKey[(string) $c['recurrence_instance_utc']]['dn']);
        }
        foreach ($placeRows as $id => $img) {
            $row = $this->rows[$id];
            $own = $this->ownOf($row);
            $key = (string) ($overrideWrites[$id]['recurrence_instance_utc'] ?? $row['recurrence_instance_utc']);
            $byKey[$key] = $entry(($overrideWrites[$id] ?? []) + $row, 'edited')
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
                $rows[] = $item + ['key' => $key];
            }
        }
        // Every key the expander gives from the first day listed (several on
        // a day for a rule several times a day; read from the series' start,
        // as a rule's days follow from it, and yielded from that day on, so
        // no cap counted from the start cuts it short), up to one more than the
        // list shows: the plain occurrences are in the order they show, so
        // with every row above beside them, the first five of these hold the
        // first five of all.
        $plain = [];
        foreach ($calc1->keysFrom($fromDn) as [$d, $key]) {
            if (isset($skip[$key]) || isset($byKey[$key])) {
                continue;
            }
            $plain[] = $this->placementEntryOut(['start_utc' => $key, 'all_day' => $calc1->allDay ? 1 : 0, 'tzid' => $calc1->tz], $this->displayZone()) + ['kind' => $d === $actedDn ? 'acted' : 'occurrence', 'key' => $key];
            if (count($plain) > self::NEXT_SHOWN) {
                break;
            }
        }
        $out = array_merge($rows, $plain);
        if ($separate !== null) {
            // The day acted on, where it shows on its own.
            $out[] = $this->placementEntryOut($separate, $this->displayZone()) + ['kind' => 'acted', 'words' => 'on its own', 'key' => ''];
        }
        // In the order they show: by the day each shows on, an all-day one
        // first on its day, then by instant, then by key.
        $order = static fn(array $e): array => [substr($e['start'], 0, 10), $e['allDay'] ? 0 : 1, Time::parseIso($e['start'])->getTimestamp(), $e['key']];
        usort($out, static fn(array $a, array $b): int => $order($a) <=> $order($b));
        $shown = array_map(static function (array $e): array {
            unset($e['key']);
            return $e;
        }, array_slice($out, 0, self::NEXT_SHOWN));
        return [$shown, count($out) > self::NEXT_SHOWN, $fromDn];
    }

    /** Every day coming up shows, or within a year from its first day, whose clock time is missing or doubled gets its note (Zones, DST and All-Day Days). */
    private function clockNotes(Calc $calc, int $fromDn, int $toDn): void
    {
        if ($this->allDay1 || $calc->tz === 'UTC') {
            return;
        }
        $tz = Time::zone($calc->tz);
        // From the cutoff through $toDn (a year on, or the last date coming
        // up shows when that is later), read from the cutoff: however many
        // occurrences come before it, the scan is bounded by those days alone.
        $seen = null;
        foreach ($calc->keysFrom($fromDn) as [$d]) {
            if ($d > $toDn) {
                break;
            }
            if ($d === $seen) {
                continue;
            }
            $seen = $d;
            $wall = sprintf('%s %02d:%02d:%02d', Cal::date($d), ...$calc->clock);
            $at = new \DateTimeImmutable($wall, $tz);
            // Each instant the wall clock names that day, by the zone's
            // transitions: none in a gap, two in a repeated span however
            // long it is.
            $twice = Calc::instantsAt($d, $calc->clock, $calc->tz);
            if ($twice === []) {
                $this->notes[] = ['kind' => 'gap', 'date' => self::patternOut($calc, $d), 'clock' => sprintf('%02d:%02d', $calc->clock[0], $calc->clock[1]), 'at' => self::inZoneOut($at, $calc->tz), 'tz' => $calc->tz, 'words' => sprintf('No %02d:%02d on %s in %s: that one is at %s', $calc->clock[0], $calc->clock[1], Words::dayMonth($d, $this->thisYear), self::zoneName($calc->tz), $at->format('H:i'))];
                continue;
            }
            if (count($twice) > 1) {
                // The note is about the series' own instant that day, which an
                // instant given keeps (the stored start on its day, the
                // expander's key on later ones): its place among the clock's
                // instants (`fold`), and what tzdata says of it (`daylight`:
                // true in daylight time, false when only the other one is,
                // null when neither is, a plain change of offset). A day with
                // no key at this clock has nothing here to note.
                $keys = array_map(static fn(string $k): int => Time::fromDb($k)->getTimestamp(), $calc->keysOn($d));
                $fold = null;
                foreach ($twice as $i => $t) {
                    if (in_array($t->getTimestamp(), $keys, true)) {
                        $fold = $i;
                        break;
                    }
                }
                if ($fold === null) {
                    continue;
                }
                $own = $twice[$fold];
                $otherDaylight = array_filter($twice, static fn(\DateTimeImmutable $t): bool => $t->getTimestamp() !== $own->getTimestamp() && $t->format('I') === '1') !== [];
                $daylight = $own->format('I') === '1' ? true : ($otherDaylight ? false : null);
                $foldName = $fold === 0 ? 'first' : 'second';
                $this->notes[] = ['kind' => 'overlap', 'date' => self::patternOut($calc, $d), 'at' => self::inZoneOut($own->setTimezone($tz), $calc->tz), 'tz' => $calc->tz, 'fold' => $foldName, 'daylight' => $daylight,
                    'words' => sprintf('%02d:%02d happens twice on %s: this is the %s', $calc->clock[0], $calc->clock[1], Words::dayMonth($d, $this->thisYear), $foldName)
                        . ($daylight === null ? '' : ', in ' . ($daylight ? 'daylight' : 'standard') . ' time')];
                continue;
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
        $p = $this->fieldParts($field, $cols);
        if (!isset($p['was'])) {
            return Words::upper($p['name']) . ($p['text'] === '' ? ' changed' : ': ' . $p['text']);
        }
        $now = $field === 'reminders' && str_ends_with($p['was'], ' before') && str_ends_with($p['now'], ' before') ? substr($p['now'], 0, -7) : $p['now'];
        return Words::upper($p['name']) . ': ' . $p['was'] . ', now ' . $now;
    }

    /** A changed field in the Activity entry: "title from 'Choir' to 'Chorus'", "tags now Music, Weekly", "people changed". */
    private function fieldClause(string $field, array $cols): string
    {
        $p = $this->fieldParts($field, $cols);
        if (!isset($p['was'])) {
            return $p['name'] . ($p['text'] === '' ? ' changed' : ' now ' . $p['text']);
        }
        $q = static fn(string $v): string => $field === 'title' && $v !== 'none' ? "'" . $v . "'" : $v;
        return $p['name'] . ' from ' . $q($p['was']) . ' to ' . $q($p['now']);
    }

    /**
     * A changed field's name and values as words, for its line and the
     * Activity entry: ['name', 'was', 'now'], or ['name', 'text'] for tags
     * (their names now) and people (no text).
     *
     * @return array{name:string,was?:string,now?:string,text?:string}
     */
    private function fieldParts(string $field, array $cols): array
    {
        if ($field === 'tags') {
            return ['name' => 'tags', 'text' => implode(', ', $cols['names'])];
        }
        if ($field === 'people') {
            return ['name' => 'people', 'text' => ''];
        }
        $col = array_key_first($cols);
        $was = $this->master[$col] ?? null;
        $now = $cols[$col] ?? null;
        if ($field === 'reminders') {
            $one = count(\BetterCal\Domain\Reminders::decode($was) ?? [0]) <= 1 && count(\BetterCal\Domain\Reminders::decode($now) ?? [0]) <= 1;
            return ['name' => $one ? 'reminder' : 'reminders', 'was' => self::reminderWords($was), 'now' => self::reminderWords($now)];
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
        return ['name' => self::OWN_WORDS[$field], 'was' => $short($was === null ? null : (string) $was), 'now' => $short($now === null ? null : (string) $now)];
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
     * it has no list yet, and each kind of thing that refers to it, its trip,
     * duplicate decisions and plugin data (opts rowLinks, OwnFields::own).
     *
     * @return list<string>
     */
    private function ownOf(array $row): array
    {
        $id = (int) $row['id'];
        $fields = OwnFields::decode($row['override_fields'] ?? null)
            ?? OwnFields::infer($row, $this->master, $this->opts['rowTags'][$id] ?? null, $this->opts['masterTags'] ?? null, $this->opts['rowPeople'][$id] ?? null, $this->opts['masterPeople'] ?? null);
        return OwnFields::own($fields, $this->opts['rowLinks'][$id] ?? []);
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

    /**
     * A day of the series $calc names (old or new): a date. A labelling
     * writer: the day number is already in that series' own zone, so $calc
     * converts nothing; it names at each call site whose day this is, so a
     * day of one series is never written as another's.
     */
    private static function patternOut(Calc $calc, int $dn): string
    {
        unset($calc);
        return Cal::date($dn);
    }

    /**
     * A rule's UNTIL as the rule writes it: basic format, read in that rule's
     * zone. A labelling writer: the text is already in its form, so it passes
     * through unchanged and only marks the role.
     */
    private static function boundOut(string $until): string
    {
        return $until;
    }

    /** A value that names its zone beside itself: ISO in $tz, or a date when it has none (all-day). */
    private static function inZoneOut(\DateTimeImmutable $at, ?string $tz): string
    {
        return $tz === null ? Time::dayOf($at, true, null) : Time::iso($at->setTimezone(Time::zone($tz)));
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
        return Time::fromDb((string) $row['start_utc'])->setTimezone(Time::dayZone((int) $row['all_day'] === 1, $zone ?? self::rowZone($row)));
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

    /**
     * Where a row is after the change, as it shows (placementOut), with its
     * `kind` when that isn't what it was before: "all-day" or "timed".
     */
    private function afterOut(array $before, array $after): array
    {
        $all = (int) $after['all_day'] === 1;
        return $this->placementOut($after, $this->displayZone()) + ($all !== ((int) $before['all_day'] === 1) ? ['kind' => $all ? 'all-day' : 'timed'] : []);
    }

    /**
     * An edited row's days and places (series-model.md, The Plan): `day`, the
     * day it is about; `ownDate`, the date set on it in its own zone, when it
     * owns its date before the change; `before` and `after` (null $after: it
     * is deleted), where it shows; and `shows` and `to`, their dates, for API
     * callers.
     */
    private function rowSides(array $row, ?array $after): array
    {
        $before = $this->placementOut($row, $this->displayZone());
        $out = ['day' => $this->identityOut((string) $row['recurrence_instance_utc'])]
            + (in_array('date', $this->ownOf($row), true) ? ['ownDate' => $this->placementOut($row, self::rowZone($row))] : [])
            + ['before' => $before, 'shows' => $before['date']];
        if ($after !== null) {
            $out['after'] = $this->afterOut($row, $after);
            $out['to'] = $out['after']['date'];
        }
        return $out;
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
                // The change is read on the clock the day showed on: under
                // the same moment, the old zone's, where the series' clock
                // moves only by a drag, so its instant is kept as an own
                // time's is, and the zone it is written in is the series'.
                if ($this->shownFrame !== null && Time::normalizeTzid($rowTz) === Time::normalizeTzid($this->shownFrame[0])) {
                    [$atTz, $seriesClock] = $this->shownFrame;
                }
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
        $start = Calc::at($date, $clock, $atTz ?? $tz, $given);
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
        // A quarantined list is never written: its series reads as one the
        // expander can't read (the constructor), whose plan writes no skips.
        try {
            $skips = \BetterCal\Domain\Recurrence::decodeExdates($this->master['exdates_json'] ?? null);
        } catch (\InvalidArgumentException) {
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
