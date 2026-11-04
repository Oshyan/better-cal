<?php
// The plan's data against persisted rows and the oracle (README, The Plan's
// Data): c-shape (every field by its path and role, the zone derived here),
// c-placement (every day's identity, own date, placements and outcome, the
// moved-day choices, losses, gains, the bound, readings and notes), c-next
// (coming up assembled from the stored rows) and c-untouched (what a commit
// may change, by row and column). Nothing here calls Domain/Series or takes
// a value from the plan as the expectation of another value in it.
declare(strict_types=1);

namespace BetterCal\EditHarness;

trait PlanChecks
{
    /** @var array<string,array<string,int>> executions of each new check, by role and outcome */
    private array $runs = [];
    /** The new checks: a failure of one is never under a known cause. */
    public const NEW_CHECKS = ['c-shape', 'c-placement', 'c-next', 'c-untouched'];
    /** How many events coming up lists, and so where nextMore starts counting (the model's tests: "the next four"). */
    private const NEXT_LIMIT = 4;

    private function ran(string $check, string $what, int $n = 1): void
    {
        $this->runs[$check][$what] = ($this->runs[$check][$what] ?? 0) + $n;
    }

    /** One comparison of a new check: counted, and recorded when the values differ. */
    private function expect(string $check, string $what, string $category, mixed $expected, mixed $actual, array $detail = []): void
    {
        $this->ran($check, $category);
        if ($expected !== $actual) {
            $this->records[] = ['invariant' => $check, 'what' => $what, 'detail' => ['role' => $category, 'expected' => $expected, 'actual' => $actual] + $detail];
        }
    }

    // ---- the zone rule, applied by the harness -------------------------------

    /** The plan's zone by the zone rule, from the series as stored after the commit: its zone when timed, none when all-day. */
    private static function derivedZone(array $m1): ?string
    {
        return (int) $m1['all_day'] === 1 ? null : (string) $m1['tzid'];
    }

    /**
     * Where a row shows by the zone rule: an all-day row's date as itself;
     * a timed row's date and clock of its instant, in the plan's zone, or in
     * its own zone named beside it when the plan has none.
     */
    private static function placed(array $row, ?string $zone): array
    {
        if ((int) $row['all_day'] === 1) {
            return ['date' => substr((string) $row['start_utc'], 0, 10)];
        }
        $tz = $zone ?? (string) $row['tzid'];
        $t = Clock::local((string) $row['start_utc'], $tz);
        return ['date' => $t->format('Y-m-d'), 'at' => Clock::iso($t)] + ($zone === null ? ['tz' => $tz] : []);
    }

    /** The date set on a row, in its own zone, named when that isn't the plan's. */
    private static function ownPlaced(array $row, ?string $zone): array
    {
        if ((int) $row['all_day'] === 1) {
            return ['date' => substr((string) $row['start_utc'], 0, 10)];
        }
        $tz = (string) $row['tzid'];
        $t = Clock::local((string) $row['start_utc'], $tz);
        return ['date' => $t->format('Y-m-d'), 'at' => Clock::iso($t)] + ($tz !== $zone ? ['tz' => $tz] : []);
    }

    /** A list entry for a row: an all-day row's date at UTC midnight, a timed row's instant in the plan's zone or its own, named. */
    private static function entryOf(array $row, ?string $zone): array
    {
        if ((int) $row['all_day'] === 1) {
            return ['start' => substr((string) $row['start_utc'], 0, 10) . 'T00:00:00+00:00', 'allDay' => true];
        }
        $tz = $zone ?? (string) $row['tzid'];
        return ['start' => Clock::iso(Clock::local((string) $row['start_utc'], $tz)), 'allDay' => false] + ($zone === null ? ['tz' => $tz] : []);
    }

    /** The day a row shows on by the zone rule (a day number). */
    private static function shownDn(array $row, ?string $zone): int
    {
        return Days::ofDate(self::placed($row, $zone)['date']);
    }

    /** A plan's placement or entry as compared: its own fields, an offset of zero written as +00:00. */
    private static function norm(mixed $v, array $keys): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $v)) {
                $out[$k] = is_string($v[$k]) && in_array($k, ['at', 'start'], true) && str_ends_with($v[$k], 'Z') ? substr($v[$k], 0, -1) . '+00:00' : $v[$k];
            }
        }
        return $out;
    }

    private static function sameInstant(string $a, string $b): bool
    {
        return (new \DateTimeImmutable($a))->getTimestamp() === (new \DateTimeImmutable($b))->getTimestamp();
    }

    /** An occurrence of the series as stored, as a row (for placing and listing it). */
    private static function occurrenceRow(array $m, string $key): array
    {
        return ['start_utc' => $key, 'all_day' => $m['all_day'], 'tzid' => $m['tzid']];
    }

    // ---- context ---------------------------------------------------------------

    /**
     * What the plan checks read, from the rows and the oracle: the derived
     * zone, the oracle's rows (null for a rule outside the drag table), the
     * new series' days by day, the row the day acted on became when it left
     * the series, the row the commit created for it, and where the request
     * put it.
     */
    private function planContext(array $before, array $after, array $sent, Expect $x, ?array $out, array $choices): array
    {
        $edit = $sent['edit'];
        $m1 = $after['row'];
        $tz1 = Truth::tzOf($m1);
        $byDay = [];
        foreach ($after['raw'] as $o) {
            $byDay[$o['dn']] ??= $o['key'];
        }
        $putAt = null;
        if ($edit['sent'] !== null) {
            $putAt = ($choices['zone'] ?? null) === 'moment' && $x->zoneGroup && $x->momentAt !== null ? Clock::db($x->momentAt) : (string) $edit['sent']['start'];
        }
        $standalones = $this->standalones();
        // The day acted on as an event of its own: its own row detached, or
        // a row made where the request put it. Only where the request put it
        // somewhere and sent no new pattern (Every Kind of Edit): otherwise
        // its row is an edited day like any other, kept on its own by the
        // outside choice.
        $special = $edit['sent'] !== null && $x->kind !== 'newrule';
        $actedAlone = null;
        foreach ($special ? $standalones : [] as $s) {
            $sid = (int) $s['id'];
            if (($edit['fromRow'] !== null && $sid === $edit['fromRow']) || ($edit['fromRow'] === null && !isset($before['overrides'][$sid]) && $putAt !== null && (string) $s['start_utc'] === $putAt)) {
                $actedAlone = $s;
            }
        }
        $created = null;
        foreach ($after['overrides'] as $id => $ov) {
            if (!isset($before['overrides'][$id])) {
                $created = $ov;
            }
        }
        return [
            'zone' => self::derivedZone($m1), 'tz0' => Truth::tzOf($before['row']), 'tz1' => $tz1, 'byDay' => $byDay,
            'putAt' => $putAt, 'special' => $special, 'standalones' => $standalones, 'actedAlone' => $actedAlone, 'created' => $created, 'out' => $out, 'x' => $x,
        ];
    }

    /**
     * An old key's new key under a rule outside the drag table, which keeps
     * its days (series-model.md, Mapping, Defined): the key itself when the
     * new series has it on that day, else the new day's key at the old key's
     * place among its own day's keys, unless another key of the old day keeps
     * that key as itself (no two old keys share one); null when there is none,
     * and the day is outside.
     */
    private static function newKeyByPlace(array $before, array $after, array $ctx, string $oldKey): ?string
    {
        $dn = Clock::dayOf($oldKey, $ctx['tz0']);
        $old = [];
        foreach ($before['raw'] as $o) {
            if ($o['dn'] === $dn) {
                $old[] = $o['key'];
            }
        }
        $new = [];
        $keys = $after['through'] !== null && $dn >= Clock::dayOf($after['through'], $ctx['tz1']) - 1
            ? Truth::keysFrom($after['row'], Days::date($dn - 1) . ' 00:00:00', 3) : array_column($after['raw'], 'key');
        foreach ($keys as $k) {
            if (Clock::dayOf($k, $ctx['tz1']) === $dn) {
                $new[] = $k;
            }
        }
        sort($old);
        sort($new);
        if (in_array($oldKey, $new, true)) {
            return $oldKey;
        }
        $i = array_search($oldKey, $old, true);
        $k = $i === false ? null : ($new[$i] ?? null);
        return $k !== null && !in_array($k, $old, true) ? $k : null;
    }

    /** The new checks for a committed whole-series plan. */
    private function checkPlanData(array $plan, array $before, array $after, array $sent, Expect $x, ?array $out, array $choices): void
    {
        $ctx = $this->planContext($before, $after, $sent, $x, $out, $choices);
        $this->checkShape($plan, $ctx['zone'], false, null, self::anchored($after), [$after['shape']->cap === 'unreadable', $before['shape']->cap === 'unreadable']);
        $this->checkPlacement($plan, $before, $after, $sent['edit'], $choices, $ctx);
        $this->checkNext($plan, $before, $after, $sent['edit'], $ctx);
        if ($sent['kind'] === 'all' && $this->wide0 !== null) {
            $this->checkUntouchedSet($before, $after, $sent['edit'], $choices, $ctx);
        }
    }

    // ---- c-shape ---------------------------------------------------------------

    private const KEY_RE = '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/';
    private const DATE_RE = '/^\d{4}-\d\d-\d\d$/';
    private const ISO_RE = '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d([+-]\d\d:\d\d|Z)$/';
    private const UNTIL_RE = '/^\d{8}(T\d{6}Z)?$/';
    private const ALLDAY_START_RE = '/^\d{4}-\d\d-\d\dT00:00:00\+00:00$/';

    /** Fields by path: what may appear, and what must. */
    private const SHAPE = [
        '' => ['hash', 'scope', 'direct', 'rule', 'zone', 'start', 'bound', 'next', 'nextMore', 'counts', 'groups', 'days', 'losses', 'gains', 'refusals', 'notes', 'fields', 'summary', 'acted', 'ownership'],
        'rule' => ['before', 'after', 'wordsBefore', 'words', 'from', 'to'],
        'rule.from' => ['rrule', 'start', 'tz', 'allDay'],
        'bound' => ['kind', 'count', 'until', 'events', 'skipped', 'last', 'separate', 'from', 'words'],
        'bound.from' => ['kind', 'count', 'until', 'events', 'last'],
        'next[]' => ['start', 'allDay', 'tz', 'kind', 'words'],
        'counts' => ['events', 'edited', 'skipped'],
        'groups[]' => ['id', 'label', 'selected', 'day', 'days', 'count', 'field', 'value', 'dates', 'side', 'splits', 'counted', 'from', 'to', 'options'],
        'options[]' => ['id', 'label', 'rrule', 'date', 'next', 'dates', 'then', 'earlier', 'earlierSeries', 'bound', 'differs', 'words', 'disabled', 'reason'],
        'entry' => ['start', 'allDay', 'tz'],
        'days[]' => ['key', 'newKey', 'kind', 'title', 'day', 'ownDate', 'before', 'after', 'shows', 'to', 'at', 'tz', 'why', 'side', 'outcome', 'head', 'outcomeWords', 'words', 'kept', 'takes'],
        'placement' => ['date', 'at', 'tz', 'kind'],
        'acted' => ['key', 'title', 'day', 'ownDate', 'before', 'shows', 'kept'],
        'losses[]' => ['date', 'words'],
        'notes[]' => ['kind', 'date', 'clock', 'at', 'tz', 'fold', 'daylight', 'words'],
        'refusals[]' => ['group', 'option', 'code', 'words'],
        'fields[]' => ['field', 'words', 'was', 'now', 'mixed'],
    ];
    /** Fields every plan has; the refused plan (a refusal of the whole change) has no zone, acted or bound.from. */
    private const REQUIRED = ['hash', 'scope', 'direct', 'rule', 'start', 'bound', 'next', 'nextMore', 'counts', 'groups', 'days', 'losses', 'gains', 'refusals', 'notes', 'fields', 'summary'];

    private function shapeFail(string $path, string $what, mixed $value): void
    {
        $this->records[] = ['invariant' => 'c-shape', 'what' => $what, 'detail' => ['path' => $path, 'value' => $value]];
    }

    /** Fields outside a path's schema. */
    private function shapeKeys(string $path, string $schema, mixed $v): void
    {
        if (!is_array($v)) {
            $this->shapeFail($path, 'an object is expected', $v);
            return;
        }
        $extra = array_values(array_diff(array_keys($v), self::SHAPE[$schema]));
        $this->ran('c-shape', 'fields of ' . $schema);
        if ($extra !== []) {
            $this->shapeFail($path, 'fields the schema does not name: ' . implode(', ', $extra), array_intersect_key($v, array_flip($extra)));
        }
    }

    private function role(string $role, string $path, mixed $v, bool $nullable = false): void
    {
        $this->ran('c-shape', $role);
        if ($v === null) {
            if (!$nullable) {
                $this->shapeFail($path, "a $role is expected, not null", $v);
            }
            return;
        }
        $ok = match ($role) {
            'key' => is_string($v) && preg_match(self::KEY_RE, $v) === 1 && \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $v, Clock::utc()) !== false,
            'date' => is_string($v) && preg_match(self::DATE_RE, $v) === 1 && Days::date(Days::ofDate($v)) === $v,
            'bound instant' => is_string($v) && preg_match(self::UNTIL_RE, $v) === 1,
            'string' => is_string($v),
            'bool' => is_bool($v),
            'int' => is_int($v),
            'list' => is_array($v) && array_is_list($v),
        };
        if (!$ok) {
            $this->shapeFail($path, "not a $role", $v);
        }
    }

    /** An ISO instant whose offset is its zone's at that instant. */
    private function isoIn(string $path, mixed $v, ?string $tz, string $role): void
    {
        $this->ran('c-shape', $role);
        if (!is_string($v) || preg_match(self::ISO_RE, $v) !== 1) {
            $this->shapeFail($path, "not an ISO instant ($role)", $v);
            return;
        }
        if ($tz === null) {
            $this->shapeFail($path, "a timed $role with no zone to read it in", $v);
            return;
        }
        $t = new \DateTimeImmutable($v);
        $want = (new \DateTimeImmutable('@' . $t->getTimestamp()))->setTimezone(Clock::zone($tz))->format('P');
        $got = str_ends_with($v, 'Z') ? '+00:00' : $t->format('P');
        if ($got !== $want) {
            $this->shapeFail($path, "the offset is not its zone's at that instant ($tz, $want)", $v);
        }
    }

    /** A placement: an all-day date, or a date and clock of one instant in the plan's zone or the named one. */
    private function shapePlacement(string $path, mixed $p, ?string $zone, bool $own = false): void
    {
        $this->shapeKeys($path, 'placement', $p);
        if (!is_array($p)) {
            return;
        }
        $this->role('date', "$path.date", $p['date'] ?? null);
        if (isset($p['kind'])) {
            $this->ran('c-shape', 'placement kind');
            if (!in_array($p['kind'], ['all-day', 'timed'], true)) {
                $this->shapeFail("$path.kind", 'a kind is all-day or timed', $p['kind']);
            }
        }
        if (!isset($p['at'])) {
            $this->ran('c-shape', 'all-day placement');
            if (isset($p['tz'])) {
                $this->shapeFail("$path.tz", 'an all-day placement names no zone', $p['tz']);
            }
            return;
        }
        $tz = $p['tz'] ?? $zone;
        $this->isoIn("$path.at", $p['at'], $tz, 'placement');
        if (is_string($p['at']) && isset($p['date']) && substr($p['at'], 0, 10) !== $p['date']) {
            $this->shapeFail($path, 'a placement\'s date and clock are of one instant in one zone', $p);
        }
        $this->ran('c-shape', 'placement zone');
        if ($zone === null && !isset($p['tz'])) {
            $this->shapeFail("$path.tz", 'a timed placement in a plan with no zone names its zone', $p);
        }
        if (isset($p['tz']) && $p['tz'] === $zone) {
            $this->shapeFail("$path.tz", 'a placement names its zone only when that is not the plan\'s', $p);
        }
        if (!$own && $zone !== null && isset($p['tz'])) {
            $this->shapeFail("$path.tz", 'in a timed plan every timed placement is in the plan\'s zone', $p);
        }
    }

    /** A list entry {start, allDay, tz?}: an all-day date at UTC midnight, or an instant in the plan's zone or the named one. */
    private function shapeEntry(string $path, mixed $e, ?string $zone, string $schema = 'entry'): void
    {
        $this->shapeKeys($path, $schema, $e);
        if (!is_array($e)) {
            return;
        }
        $this->role('bool', "$path.allDay", $e['allDay'] ?? null);
        if (($e['allDay'] ?? null) === true) {
            $this->ran('c-shape', 'all-day entry');
            if (!is_string($e['start'] ?? null) || preg_match(self::ALLDAY_START_RE, $e['start']) !== 1) {
                $this->shapeFail("$path.start", 'an all-day entry is its date at T00:00:00+00:00', $e['start'] ?? null);
            }
            if (isset($e['tz'])) {
                $this->shapeFail("$path.tz", 'an all-day entry names no zone', $e['tz']);
            }
            return;
        }
        $this->isoIn("$path.start", $e['start'] ?? null, $e['tz'] ?? $zone, 'timed entry');
        $this->ran('c-shape', 'entry zone');
        if ($zone === null && !isset($e['tz'])) {
            $this->shapeFail("$path.tz", 'a timed entry in a plan with no zone names its zone', $e);
        }
        if (isset($e['tz']) && $e['tz'] === $zone) {
            $this->shapeFail("$path.tz", 'an entry names its zone only when that is not the plan\'s', $e);
        }
    }

    /**
     * c-shape: the plan by exact field path and role. $zone is the zone the
     * harness derived (never the plan's); a refused plan is checked by its
     * own shape, in the old series' zone ($tz0, null for an all-day series, whose start is a date).
     */
    private function checkShape(array $plan, ?string $zone, bool $refused, ?string $tz0 = null, bool $anchored = false, array $unreadable = [false, false]): void
    {
        $this->ran('c-shape', $refused ? 'refused plan' : 'plan');
        $this->shapeKeys('plan', '', $plan);
        foreach (self::REQUIRED as $k) {
            if (!array_key_exists($k, $plan)) {
                $this->shapeFail("plan.$k", 'a field every plan has is missing', null);
            }
        }
        if ($refused) {
            foreach (['zone', 'acted'] as $k) {
                if (array_key_exists($k, $plan)) {
                    $this->shapeFail("plan.$k", 'the refused plan has no ' . $k, $plan[$k]);
                }
            }
            if (array_key_exists('from', $plan['bound'] ?? [])) {
                $this->shapeFail('plan.bound.from', 'the refused plan has no bound.from', $plan['bound']['from']);
            }
            $this->ran('c-shape', 'refused start');
            $start = $plan['start'] ?? null;
            if ($tz0 === null) {
                // An all-day series' start is a bare date, in the refused plan too.
                $this->role('date', 'plan.start', $start);
            } else {
                $this->isoIn('plan.start', $start, $tz0, 'refused start');
            }
        } else {
            foreach (['zone', 'acted'] as $k) {
                if (!array_key_exists($k, $plan)) {
                    $this->shapeFail("plan.$k", 'a committed plan has ' . $k, null);
                }
            }
            $this->expect('c-shape', 'plan.zone is the zone the zone rule gives for the series as stored', 'plan.zone', $zone, array_key_exists('zone', $plan) ? $plan['zone'] : 'missing');
        }
        $this->role('string', 'plan.hash', $plan['hash'] ?? null);
        $this->role('bool', 'plan.direct', $plan['direct'] ?? null);
        $this->role('bool', 'plan.nextMore', $plan['nextMore'] ?? null);
        $this->role('string', 'plan.summary', $plan['summary'] ?? null);
        // The rules, each start in its own zone (a date when it has none).
        $this->shapeKeys('plan.rule', 'rule', $plan['rule'] ?? null);
        foreach (['from', 'to'] as $side) {
            $r = $plan['rule'][$side] ?? null;
            $this->shapeKeys("plan.rule.$side", 'rule.from', $r);
            if (!is_array($r)) {
                continue;
            }
            $this->role('bool', "plan.rule.$side.allDay", $r['allDay'] ?? null);
            $this->ran('c-shape', 'zone-qualified');
            if (($r['tz'] ?? null) === null) {
                $this->role('date', "plan.rule.$side.start", $r['start'] ?? null);
                if (($r['allDay'] ?? null) !== true) {
                    $this->shapeFail("plan.rule.$side.tz", 'a timed rule names its zone', $r);
                }
            } else {
                $this->isoIn("plan.rule.$side.start", $r['start'] ?? null, (string) $r['tz'], 'zone-qualified');
            }
        }
        if (!$refused && array_key_exists('zone', $plan)) {
            $this->expect('c-shape', 'rule.to.tz is the plan\'s zone', 'rule.to.tz', $zone, array_key_exists('tz', $plan['rule']['to'] ?? []) ? $plan['rule']['to']['tz'] : 'missing');
        }
        // The series' start: a date when all-day, a key when anchored, else in the plan's zone.
        if (!$refused) {
            $start = $plan['start'] ?? null;
            if ($anchored) {
                // The stored start is the skipped day before a skipped hour (anchored): a key, exactly then.
                $this->role('key', 'plan.start', $start);
                $this->ran('c-shape', 'plan.start (anchored)');
            } elseif ($zone === null) {
                $this->role('date', 'plan.start', $start);
                $this->ran('c-shape', 'plan.start (all-day)');
            } else {
                $this->isoIn('plan.start', $start, $zone, 'plan.start');
                $this->ran('c-shape', 'plan.start (timed)');
                if (is_string($start) && preg_match(self::KEY_RE, $start) === 1) {
                    $this->shapeFail('plan.start', 'a key only for an anchored series', $start);
                }
            }
        }
        // The bound.
        $bound = $plan['bound'] ?? null;
        $this->shapeKeys('plan.bound', 'bound', $bound);
        if (is_array($bound)) {
            $this->shapeBound('plan.bound', $bound, $unreadable[0]);
            if (isset($bound['from'])) {
                $this->shapeKeys('plan.bound.from', 'bound.from', $bound['from']);
                $this->shapeBound('plan.bound.from', $bound['from'], $unreadable[1]);
            }
        }
        $this->shapeKeys('plan.counts', 'counts', $plan['counts'] ?? null);
        // Coming up.
        $this->role('list', 'plan.next', $plan['next'] ?? null);
        foreach ($plan['next'] ?? [] as $i => $e) {
            $this->shapeEntry("plan.next[$i]", $e, $zone, 'next[]');
            $this->ran('c-shape', 'next kind');
            if (!in_array($e['kind'] ?? null, ['acted', 'occurrence', 'edited'], true)) {
                $this->shapeFail("plan.next[$i].kind", 'a next entry is acted, occurrence or edited', $e['kind'] ?? null);
            }
        }
        // The day acted on.
        if (isset($plan['acted'])) {
            $a = $plan['acted'];
            $this->shapeKeys('plan.acted', 'acted', $a);
            $this->role('key', 'plan.acted.key', $a['key'] ?? null);
            $this->role('date', 'plan.acted.day', $a['day'] ?? null);
            $this->shapePlacement('plan.acted.before', $a['before'] ?? null, $zone);
            if (isset($a['ownDate'])) {
                $this->shapePlacement('plan.acted.ownDate', $a['ownDate'], $zone, true);
            }
            $this->role('date', 'plan.acted.shows', $a['shows'] ?? null);
        }
        // Groups and their options.
        foreach ($plan['groups'] ?? [] as $gi => $g) {
            $gp = "plan.groups[$gi]";
            $this->shapeKeys($gp, 'groups[]', $g);
            if (isset($g['day'])) {
                $this->role('key', "$gp.day", $g['day']);
            }
            foreach ($g['days'] ?? [] as $i => $k) {
                $this->role('key', "$gp.days[$i]", $k);
            }
            if (($g['id'] ?? '') === 'edge') {
                foreach ($g['dates'] ?? [] as $i => $d) {
                    $this->role('date', "$gp.dates[$i]", $d);
                }
            }
            $enabled = count(array_filter($g['options'] ?? [], static fn(array $o): bool => empty($o['disabled'])));
            foreach ($g['options'] ?? [] as $oi => $o) {
                $op = "$gp.options[$oi]";
                if (($g['id'] ?? '') === 'reading' && empty($o['disabled']) && $enabled >= 2) {
                    $this->ran('c-shape', 'reading option lists');
                    $missing = array_values(array_diff(['dates', 'then', 'earlier', 'earlierSeries'], array_keys($o)));
                    if ($missing !== []) {
                        $this->shapeFail($op, 'a reading option beside another carries dates, then, earlier and earlierSeries; missing: ' . implode(', ', $missing), $o['id'] ?? null);
                    }
                }
                $this->shapeKeys($op, 'options[]', $o);
                if (array_key_exists('date', $o)) {
                    $this->role('date', "$op.date", $o['date']);
                }
                foreach (['next', 'dates', 'then'] as $list) {
                    if (!array_key_exists($list, $o)) {
                        continue;
                    }
                    $this->role('list', "$op.$list", $o[$list]);
                    foreach ($o[$list] ?? [] as $i => $e) {
                        $this->shapeEntry("$op.{$list}[$i]", $e, $zone);
                    }
                }
                if (array_key_exists('earlier', $o) || array_key_exists('earlierSeries', $o)) {
                    $this->role('date', "$op.earlier", $o['earlier'] ?? null, true);
                    $this->ran('c-shape', 'earlierSeries');
                    $es = array_key_exists('earlierSeries', $o) ? $o['earlierSeries'] : 'missing';
                    if (($o['earlier'] ?? null) === null ? $es !== null : !in_array($es, ['old', 'new'], true)) {
                        $this->shapeFail("$op.earlierSeries", 'earlierSeries is old or new beside a date, null beside none', $o);
                    }
                }
                foreach ($o['differs']['dates'] ?? [] as $i => $d) {
                    $this->role('date', "$op.differs.dates[$i]", $d);
                }
                if (isset($o['bound'])) {
                    $this->shapeKeys("$op.bound", 'bound', $o['bound']);
                    $this->shapeBound("$op.bound", $o['bound']);
                }
            }
        }
        // Days.
        foreach ($plan['days'] ?? [] as $i => $d) {
            $dp = "plan.days[$i]";
            $this->shapeKeys($dp, 'days[]', $d);
            // A skip the change writes (generated, or an anchor before a skipped hour) has no old occurrence: its key is null.
            $generated = in_array($d['outcome'] ?? '', ['generated', 'anchor'], true);
            if (($d["key"] ?? null) === null && !$generated) {
                $this->shapeFail("$dp.key", "a day other than a skip the change writes is named by its key", array_diff_key($d, ["head" => 1, "outcomeWords" => 1, "words" => 1]));
            }
            $this->role("key", "$dp.key", $d["key"] ?? null, true);
            $this->role('key', "$dp.newKey", $d['newKey'] ?? null, true);
            foreach (['day', 'shows', 'to'] as $f) {
                if (array_key_exists($f, $d)) {
                    $this->role('date', "$dp.$f", $d[$f]);
                }
            }
            if (isset($d['ownDate'])) {
                $this->shapePlacement("$dp.ownDate", $d['ownDate'], $zone, true);
            }
            foreach (['before', 'after'] as $f) {
                if (isset($d[$f])) {
                    $this->shapePlacement("$dp.$f", $d[$f], $zone);
                }
            }
            if (isset($d['at'])) {
                $this->isoIn("$dp.at", $d['at'], $d['tz'] ?? $zone, 'day at');
            }
        }
        foreach (['losses', 'gains'] as $list) {
            foreach ($plan[$list] ?? [] as $i => $l) {
                $this->shapeKeys("plan.{$list}[$i]", 'losses[]', $l);
                $this->role('date', "plan.{$list}[$i].date", $l['date'] ?? null);
            }
        }
        foreach ($plan['notes'] ?? [] as $i => $n) {
            $np = "plan.notes[$i]";
            $this->shapeKeys($np, 'notes[]', $n);
            if (isset($n['date'])) {
                $this->role('date', "$np.date", $n['date']);
            }
            if (isset($n['at'])) {
                $this->isoIn("$np.at", $n['at'], $n['tz'] ?? null, 'note at');
                if (!$refused) {
                    $this->expect('c-shape', 'a note\'s zone is the plan\'s derived zone', 'notes[].tz', $zone, $n['tz'] ?? null);
                }
            }
        }
        foreach ($plan['refusals'] ?? [] as $i => $r) {
            $this->shapeKeys("plan.refusals[$i]", 'refusals[]', $r);
        }
        foreach ($plan['fields'] ?? [] as $i => $f) {
            $this->shapeKeys("plan.fields[$i]", 'fields[]', $f);
        }
    }

    /**
     * Whether a series as stored is anchored on the day before a skipped
     * hour: its stored start is a skipped day, and its clock doesn't exist
     * on the next day of its expansion (series-model.md, Zones, DST and
     * All-Day Days).
     */
    private static function anchored(array $st): bool
    {
        $raw = $st['raw'];
        return !$st['allDay'] && count($raw) >= 2 && $raw[0]['key'] === (string) $st['row']['start_utc'] && in_array($raw[0]['key'], $st['exdates'], true)
            && Clock::inGap($raw[1]['dn'], $st['clock'], $st['tz']);
    }

    /**
     * A bound: its kind, UNTIL and last day. Its events and last day are null
     * with no end, and for a rule the expander can't read (its kind, count and
     * UNTIL read from the text), which $unreadable says from the stored rule
     * as the harness's own Shape reads it, never from the plan.
     */
    private function shapeBound(string $path, array $b, bool $unreadable = false): void
    {
        $open = ($b['kind'] ?? '') === 'none' || $unreadable;
        if (array_key_exists('events', $b)) {
            $this->ran('c-shape', $unreadable ? 'bound events (unreadable)' : 'bound events');
            if ($b['events'] === null ? !$open : !is_int($b['events'])) {
                $this->shapeFail("$path.events", 'a bound\'s events are a number, null only with no end or for a rule the expander can\'t read', $b['events']);
            }
        }
        $this->ran('c-shape', 'bound kind');
        if (!in_array($b['kind'] ?? null, ['none', 'count', 'until'], true)) {
            $this->shapeFail("$path.kind", 'a bound is none, count or until', $b['kind'] ?? null);
        }
        if (array_key_exists('until', $b)) {
            $this->role('bound instant', "$path.until", $b['until']);
        }
        if (array_key_exists('last', $b)) {
            $this->role('date', "$path.last", $b['last'], $open);
        }
    }
}
