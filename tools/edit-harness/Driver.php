<?php
// Runs one case against the database as the client does (Events, the plan
// API, Undo) and checks the invariants (Invariants.php) on what it wrote.
declare(strict_types=1);

namespace BetterCal\EditHarness;

use BetterCal\Domain\Events;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Undo;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

final class Driver
{
    use Invariants;
    use PlanChecks;
    use PlanPlacement;
    use PlanNext;
    use PlanUntouched;
    use PlanPreselect;
    use Sequences;

    /**
     * The share of drawn whole-series cases that commit every option of every choice from the same baseline (README,
     * Every Option); the fixed cases and the sequences always do. harness.php --every-option sets it.
     */
    public static float $everyOption = 0.1;

    /** The share of drawn cases whose timed edits send the wall clocks typed (`typed`, README, The Delta Vector); --typed sets it. */
    public static float $typed = 0.3;

    /** The option combination being committed in isolated state (everyOption), or null for the case's own commit. */
    private ?string $trial = null;

    /** A split's baseline plan (applyEdit), for the part's preselections in checkFollowing. */
    private ?array $splitBaseline = null;

    /** Whether the case's trip flag could nest a trip (run): where a trip_nested refusal is right, for every option too. */
    private bool $nests = false;

    /** @var list<array> */
    private array $records = [];
    private array $spec = [];
    private ?Rng $rng = null;
    private int $firstId = 0;
    /** @var list<int> the trips and stops this case made for its edited days (attachTrips): fixtures, never the edit's own events */
    private array $fixtures = [];
    /** @var list<array> the event_links attaching edited days, as made */
    private array $tripLinks = [];
    /** tripsFor's draw for this case: per exception, 'stop', 'trip' or null. */
    private array $tripsDrawn = [];
    /** Parts of the design the oracle had no answer for in this case. */
    public array $unoracled = [];

    public function __construct(
        private readonly Db $db,
        private readonly Events $events,
        private readonly Undo $undo,
        private readonly int $userId,
        private readonly int $calendarId,
        private readonly int $otherCalendarId = 0,
    ) {
    }

    /**
     * A case: a spec with its series (the rest is drawn here when absent)
     * or a complete one (a shrunk reproduction).
     *
     * @return array{spec:array,records:list<array>,path:string}
     */
    public function run(array $spec, Rng $r): array
    {
        $this->records = [];
        $this->unoracled = [];
        $this->runs = [];
        $this->wide0 = null;
        $this->splitBaseline = null;
        $this->spec = $spec;
        $this->rng = $r;
        $this->controls = $this->makeControls();
        $this->firstId = (int) $this->db->scalar('SELECT COALESCE(MAX(id), 0) + 1 FROM events');
        $this->fixtures = [];
        $this->tripLinks = [];
        Prof::mark('create');
        $id = $this->createSeries($spec['series']);
        Prof::mark('state');
        $st = $this->state($id);
        $this->checkSeries('g-created', $st);
        if (!isset($spec['vector'])) {
            $horizon = Clock::db((new \DateTimeImmutable('now', Clock::utc()))->modify('+19 years'));
            $within = array_values(array_filter($st['raw'], static fn(array $o): bool => $o['key'] < $horizon));
            if ($within === []) {
                return $this->result('none');
            }
            $spec += Space::exceptions($r, $spec['series'], $within);
            $spec['vector'] = Delta::draw($r, $spec['series'], count($within));
            $spec['stack'] = Delta::stack($r);
            $this->spec = $spec + $this->spec;
        }
        // Every option committed from the same baseline: always for a fixed case, for a share of the drawn ones chosen
        // from the case's seed and number alone (so the drawn space is untouched), kept in the spec so a shrink replays it.
        $this->spec['everyOption'] ??= isset($this->spec['fixed']) || crc32('every:' . ($this->spec['seed'] ?? 0) . ':' . ($this->spec['n'] ?? 0)) % 10000 < (int) round(self::$everyOption * 10000);
        // The wall clocks typed, for a share of the drawn cases (a fixed case names it), by seed and number alone.
        $this->spec['typed'] ??= !isset($this->spec['fixed']) && crc32('typed:' . ($this->spec['seed'] ?? 0) . ':' . ($this->spec['n'] ?? 0)) % 10000 < (int) round(self::$typed * 10000);
        $spec = $this->spec;
        // A fixed case may act on the stored start itself (a rule the expander can't read has no occurrences to pick from).
        $atStart = !empty($spec['actedStart']);
        if (!$atStart && ($st['raw'] === [] || !isset($st['raw'][$spec['acted']]))) {
            return $this->result('none');
        }
        if (isset($spec['actedAt']) && $this->occurrenceFrom($st['row'], (string) $spec['actedAt']) === null) {
            return $this->result('none');
        }
        Prof::mark('exceptions');
        try {
            $trips = $this->tripsFor($spec);
            $this->tripsDrawn = $trips;
            if (in_array('trip', $trips, true) && !$spec['vector']['trip']) {
                // An edited day that is a trip needs a series that is one.
                $this->events->patch($this->userId, $id, ['scope' => 'all', 'isContainer' => true]);
            }
            $this->applyExceptions($id, $st, $spec['exceptions']);
            $this->attachTrips($id, $st, $spec['exceptions'], $trips);
        } catch (HttpError $e) {
            $this->fail('b-this', 'a "this event" edit was refused: ' . $e->errorCode, ['message' => $e->getMessage()]);
            return $this->result('none');
        }
        Prof::mark('state');
        $before = $this->state($id);
        Prof::mark('snapshot');
        $s0 = $this->snapshot();
        $v = $spec['vector'];
        $scope = $v['scope'];
        $acted = match (true) {
            $atStart => ['key' => (string) $before['row']['start_utc'], 'dn' => $before['startDn'], 'secs' => $before['clock']],
            isset($spec['actedAt']) => $this->occurrenceFrom($before['row'], (string) $spec['actedAt']),
            default => $before['raw'][$spec['acted']] ?? $before['raw'][0],
        };
        $first = $acted['key'] === (string) $before['row']['start_utc'];
        // The code path, decided from what was sent: "this and following",
        // delete following and attendance following from a day after the
        // first run on their 1.4.6 code until 1.5.1.
        $path = match ($scope) {
            'following' => $first ? 'following@first' : 'following',
            'deleteFollowing' => $first ? 'deleteFollowing@first' : 'deleteFollowing',
            'attendFollowing' => $first ? 'attendFollowing@first' : 'attendFollowing',
            default => $scope,
        };
        $this->spec['path'] = $path;
        $sent = null;
        Prof::mark('edit');
        // A trip never contains another trip (Trips, one rule for every
        // writer): after a write no live link has both its trip and its stop
        // flagged, and no trip is its own stop. A refusal is right only where
        // the end state could nest: the flag sent to a whole series (from its
        // first day) with a day in it that is a stop. Otherwise the end state
        // is read from the stored links (checkTripRule).
        $stopAts = [];
        foreach ($spec['exceptions'] ?? [] as $i => $ex) {
            if (($this->tripsDrawn[$i] ?? null) === 'stop') {
                $stopAts[] = (int) $ex['at'];
            }
        }
        // From a day on, the flag reaches the part from that day: refused where a stop is keyed in it (api-contract, This and following).
        $partStops = array_filter($stopAts, static fn(int $at): bool => $at >= (int) ($spec['acted'] ?? 0));
        $nests = $v['trip'] && (($scope === 'all' || $path === 'following@first') ? $stopAts !== [] : ($scope === 'following' && $partStops !== []));
        $this->nests = $nests;
        if ($scope === 'all') {
            $this->wide0 = $this->wide();
        }
        try {
            $sent = $this->applyEdit($id, $before, $acted, $v, $s0, 'edit');
        } catch (\Throwable $e) {
            if ($e instanceof HttpError && $e->errorCode === 'trip_nested') {
                $this->check('b-trip', $nests, 'the trip flag is refused only where the end state could nest: sent to a whole series with a day in it that is a stop', ['scope' => $scope]);
                $this->same('e-refused', $s0, $this->snapshot(), 'a refused edit writes nothing');
                return $this->result($path);
            }
            $this->fail('api-crash', 'the edit failed with ' . ($e instanceof HttpError ? $e->errorCode : get_class($e)), ['message' => mb_substr($e->getMessage(), 0, 300)]);
            return $this->result($path);
        }
        $this->checkTripRule('b-trip');
        if ($sent === null) {
            return $this->result($path); // refused, checked
        }
        Prof::mark('state');
        $after = $this->state($id);
        Prof::mark('snapshot');
        $s1 = $this->snapshot();
        Prof::mark('checks');
        $this->checkEdit($before, $after, $acted, $v, $sent, $s0);
        $this->checkTripLinks($before, $acted, $sent);
        Prof::mark('undo');
        // Undo: every row as it was.
        if ($this->undoLatest('f-undo', 'undo')) {
            $this->same('f-undo', $s0, $this->snapshot(), 'after Undo every row is byte-identical to before the edit');
            try {
                Prof::mark('redo');
                $this->replay($id, $before, $acted, $v, $sent);
                $s1r = $this->snapshot();
                $this->same('f-redo', self::roles($s1, $id), self::roles($s1r, $id), 'the edit applied again gives the same rows under new ids');
                Prof::mark('stack');
                $this->runStack($id, $s0, $s1r);
            } catch (\Throwable $e) {
                $this->fail('f-redo', 'the edit applied again failed: ' . ($e instanceof HttpError ? $e->errorCode : get_class($e)), ['message' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }
        return $this->result($path);
    }

    /** The trip rule's end state, read from the stored links: no live link with both ends flagged, and none from a row to itself. */
    private function checkTripRule(string $inv): void
    {
        foreach ($this->db->all('SELECT l.id, l.container_id, l.event_id, c.is_container AS trip, m.is_container AS stop FROM event_links l JOIN events c ON c.id = l.container_id JOIN events m ON m.id = l.event_id WHERE (l.container_id >= ? OR l.event_id >= ?) AND c.user_id = ? AND c.deleted_at IS NULL AND m.deleted_at IS NULL', [$this->firstId, $this->firstId, $this->userId]) as $l) {
            $this->check($inv, !((int) $l['trip'] === 1 && (int) $l['stop'] === 1), 'after a write no live link has both its trip and its stop flagged (a trip inside a trip)', ['link' => $l]);
            $this->check($inv, (int) $l['container_id'] !== (int) $l['event_id'], 'no trip is its own stop', ['link' => $l]);
        }
    }

    private function result(string $path): array
    {
        Prof::mark('');
        return ['spec' => $this->spec, 'records' => $this->records, 'path' => $path, 'unoracled' => $this->unoracled, 'runs' => $this->runs];
    }

    /** The occurrence on or after a local date (a fixed case acting on a day past the window the oracle reads). */
    private function occurrenceFrom(array $row, string $date): ?array
    {
        $tz = Truth::tzOf($row);
        foreach (Truth::keysFrom($row, $date . ' 00:00:00', 40) as $k) {
            $local = Clock::local($k, $tz);
            return ['key' => $k, 'dn' => Clock::dnOf($local), 'secs' => Clock::secsOf($local)];
        }
        return null;
    }

    /**
     * Control events for c-untouched, one in the case's calendar and one in
     * another, made before the case's own rows so nothing of the case's
     * reaches them by id.
     */
    private function makeControls(): array
    {
        $ids = [];
        foreach (array_filter([$this->calendarId, $this->otherCalendarId]) as $i => $cal) {
            $ids[] = (int) $this->events->create($this->userId, ['calendarId' => $cal, 'title' => 'Sample control ' . ($i + 1), 'location' => 'Sample Hall',
                'start' => '2091-03-0' . ($i + 1) . 'T10:00:00+00:00', 'end' => '2091-03-0' . ($i + 1) . 'T11:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC',
                'tagNames' => ['control'], 'personNames' => ['Alex Example']])['eventId'];
        }
        return $ids;
    }

    // ---- building the case -----------------------------------------------

    private function createSeries(array $s): int
    {
        $in = ['calendarId' => $this->calendarId, 'title' => 'Sample series', 'location' => 'Sample Hall', 'allDay' => $s['allDay'], 'tzid' => $s['tzid'],
            'tagNames' => ['sample'], 'personNames' => ['Alex Example']];
        if ($s['allDay']) {
            $in['start'] = $s['start'];
            $in['end'] = Days::date(Days::ofDate($s['start']) + $s['length']);
        } else {
            $start = new \DateTimeImmutable($s['start'], Clock::zone($s['tzid']));
            $in['start'] = Clock::iso($start);
            $in['end'] = Clock::iso(Clock::plus($start, $s['length'] * 60));
        }
        if ($s['import']) {
            // Stored as an import writes it (Rows from Elsewhere): made, then given its rule as the other app sent it.
            $id = (int) $this->events->create($this->userId, $in + ['rrule' => 'FREQ=DAILY;COUNT=2'])['eventId'];
            $row = $this->row($id);
            $row['rrule'] = $s['rrule'];
            $exp = Shape::parse($s['rrule'], 0)->cap === 'unreadable' ? ['occ' => []] : Truth::expand($row, 3);
            if (count($exp['occ']) >= 2) {
                // Start on a day the rule produces (the expander yields DTSTART first, matching or not).
                $at = Clock::parse($exp['occ'][1]['key']);
                $len = Clock::parse((string) $row['end_utc'])->getTimestamp() - Clock::parse((string) $row['start_utc'])->getTimestamp();
                $row['start_utc'] = Clock::db($at);
                $row['end_utc'] = Clock::db($at->modify('+' . $len . ' seconds'));
            }
            $rrule = $s['rrule'] . $this->boundText($s, $row);
            $this->db->run('UPDATE events SET rrule = ?, start_utc = ?, end_utc = ? WHERE id = ?', [$rrule, $row['start_utc'], $row['end_utc'], $id]);
            $this->spec['create'] = $in + ['rrule' => $rrule, 'stored' => $row['start_utc']];
            return $id;
        }
        $id = (int) $this->events->create($this->userId, $in + ['rrule' => $s['rrule']])['eventId'];
        if ($s['allDay'] && isset($s['legacyZone'])) {
            // An all-day series stored the old way (before 0.9.16): local midnights of its days in a zone of its own
            // (README, Known, Accepted Differences: legacy all-day rows).
            $row = $this->row($id);
            $midnight = static fn(string $db): string => Clock::db(new \DateTimeImmutable(substr($db, 0, 10) . ' 00:00:00', Clock::zone((string) $s['legacyZone'])));
            $this->db->run('UPDATE events SET tzid = ?, start_utc = ?, end_utc = ? WHERE id = ?', [$s['legacyZone'], $midnight((string) $row['start_utc']), $midnight((string) $row['end_utc']), $id]);
        }
        if ($s['bound'] !== 'none') {
            // The bound is written as the editor writes it, read from the rule as stored (its aligned start).
            $row = $this->row($id);
            $rrule = $s['rrule'] . $this->boundText($s, $row);
            $this->db->run('UPDATE events SET rrule = ? WHERE id = ?', [$rrule, $id]);
            $this->spec['create'] = $in + ['rrule' => $rrule];
            return $id;
        }
        $this->spec['create'] = $in + ['rrule' => $s['rrule']];
        return $id;
    }

    /** ;COUNT=n, ;UNTIL= (the editor's 23:59:59 of the last day, its last start, or a date for an all-day series), or ''. */
    private function boundText(array $s, array $row): string
    {
        if ($s['bound'] === 'count' || ($s['bound'] === 'until' && Shape::parse($s['rrule'], 0)->cap === 'unreadable')) {
            return ';COUNT=' . $s['boundAt'];
        }
        if ($s['bound'] !== 'until') {
            return '';
        }
        $occ = Truth::expand($row, $s['boundAt'] + 1)['occ'];
        if ($occ === []) {
            return ';COUNT=' . $s['boundAt'];
        }
        $last = $occ[min($s['boundAt'], count($occ) - 1)];
        if ($s['allDay']) {
            return ';UNTIL=' . str_replace('-', '', Days::date($last['dn']));
        }
        if ($s['untilForm'] === 'exact') {
            return ';UNTIL=' . Clock::parse($last['key'])->format('Ymd\THis\Z');
        }
        return ';UNTIL=' . Clock::at($last['dn'], 86399, $s['tzid'])->setTimezone(Clock::utc())->format('Ymd\THis\Z');
    }

    /**
     * Which edited days are in a trip, worked out from the case's seed and
     * number so the drawn space is untouched: 'stop' (a stop of a trip of
     * its own), 'trip' (a trip with a stop in it; its series is made a trip
     * first) or null, by exception index.
     *
     * @return array<int,?string>
     */
    private function tripsFor(array $spec): array
    {
        $out = [];
        foreach ($spec['exceptions'] ?? [] as $i => $ex) {
            $k = $ex['kind'] === 'skip' ? 0 : crc32('trip:' . ($spec['seed'] ?? 0) . ':' . ($spec['n'] ?? 0) . ':' . $i) % 5;
            $out[$i] = match (true) {
                $k === 1 => 'stop',
                $k === 2 && !$spec['vector']['trip'] => 'trip',
                default => null,
            };
        }
        // In a series that is a trip every edited day is one, and a trip
        // can't be another's stop: its days in trips are trips with stops.
        if (in_array('trip', $out, true)) {
            $out = array_map(static fn(?string $t): ?string => $t === null ? null : 'trip', $out);
        }
        return $out;
    }

    /** The trip links of tripsFor, each through Trips as the app makes them, far from the series' days. */
    private function attachTrips(int $id, array $st, array $exceptions, array $trips): void
    {
        $trip = new \BetterCal\Domain\Trips($this->db, $this->undo);
        foreach ($exceptions as $i => $ex) {
            $occ = $st['raw'][$ex['at']] ?? null;
            if (($trips[$i] ?? null) === null || $occ === null) {
                continue;
            }
            $row = $this->db->one('SELECT id FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$id, $occ['key']]);
            if ($row === null) {
                continue;
            }
            $at = sprintf('2090-01-%02dT10:00:00+00:00', 1 + count($this->fixtures));
            $other = (int) $this->events->create($this->userId, ['calendarId' => $this->calendarId, 'title' => $trips[$i] === 'stop' ? 'Sample trip' : 'Sample stop',
                'start' => $at, 'end' => str_replace('T10:', 'T11:', $at), 'allDay' => false, 'tzid' => 'UTC', 'isContainer' => $trips[$i] === 'stop'])['eventId'];
            $this->fixtures[] = $other;
            [$container, $member] = $trips[$i] === 'stop' ? [$other, (int) $row['id']] : [(int) $row['id'], $other];
            $trip->attach($this->userId, $container, $member);
            $this->tripLinks[] = $this->db->one('SELECT * FROM event_links WHERE container_id = ? AND event_id = ?', [$container, $member]);
        }
    }

    private function instanceArg(array $st, string $key): string
    {
        return $st['allDay'] ? substr($key, 0, 10) : Clock::iso(Clock::local($key, $st['tz']));
    }

    /** Edited and skipped days, each as a "this event" edit or delete; each edit's own list must name what it changed. */
    private function applyExceptions(int $id, array $st, array $exceptions): void
    {
        foreach ($exceptions as $i => $ex) {
            $occ = $st['raw'][$ex['at']] ?? null;
            // A raw occurrence already skipped is no event to act on: the stored
            // day of a start in a skipped hour is one (series-model.md, Zones,
            // DST and All-Day Days: it holds the clock and is never shown).
            if ($occ === null || in_array($occ['key'], $st['exdates'], true)) {
                continue;
            }
            $inst = $this->instanceArg($st, $occ['key']);
            if ($ex['kind'] === 'skip') {
                $this->events->deleteEvent($this->userId, $id, 'this', $inst);
                continue;
            }
            $own = $ex['own'];
            $in = ['scope' => 'this', 'instanceStart' => $inst];
            $want = [];
            foreach ($own as $f) {
                switch ($f) {
                    case 'title':
                        $in['title'] = 'Sample series, day ' . ($i + 1);
                        break;
                    case 'location':
                        $in['location'] = 'Sample Room';
                        break;
                    case 'description':
                        $in['description'] = 'Own notes for the day.';
                        break;
                    case 'url':
                        $in['url'] = 'https://example.test/day';
                        break;
                    case 'reminders':
                        $in['reminders'] = [['minutes' => 10]];
                        break;
                    case 'status':
                        $in['status'] = 'tentative';
                        break;
                    case 'tags':
                        $in['tagNames'] = ['own', 'sample'];
                        break;
                    case 'people':
                        $in['personNames'] = ['Sam Sample'];
                        break;
                }
                if (!in_array($f, ['date', 'time', 'zone', 'length', 'allDay'], true)) {
                    $want[] = $f;
                }
            }
            $timeOwn = array_intersect($own, ['date', 'time', 'zone', 'length', 'allDay']);
            if ($timeOwn !== []) {
                $len = $st['len'];
                if (in_array('allDay', $own, true)) {
                    if ($st['allDay']) {
                        $at = Clock::at($occ['dn'], 9 * 3600, 'Europe/Berlin');
                        $in += ['allDay' => false, 'tzid' => 'Europe/Berlin', 'start' => Clock::iso($at), 'end' => Clock::iso(Clock::plus($at, 3600))];
                        $want = array_merge($want, ['allDay', 'time']);
                    } else {
                        $in += ['allDay' => true, 'start' => Days::date($occ['dn']), 'end' => Days::date($occ['dn'] + 1)];
                        $want[] = 'allDay';
                    }
                } elseif ($st['allDay']) {
                    $day = $occ['dn'] + (in_array('date', $own, true) ? $ex['days'] : 0);
                    $days = max(1, intdiv($len, 86400) + (in_array('length', $own, true) ? 1 : 0));
                    $in += ['start' => Days::date($day), 'end' => Days::date($day + $days)];
                    if (in_array('date', $own, true)) {
                        $want[] = 'date';
                    }
                    if (in_array('length', $own, true)) {
                        $want[] = 'length';
                    }
                } else {
                    $tz = $st['tz'];
                    $local = Clock::local($occ['key'], $tz);
                    if (in_array('date', $own, true)) {
                        $local = $local->modify(sprintf('%+d days', $ex['days']));
                    }
                    if (in_array('time', $own, true)) {
                        $local = $local->modify(sprintf('%+d minutes', $ex['mins']));
                        $want[] = 'time';
                    }
                    if (in_array('zone', $own, true)) {
                        $local = new \DateTimeImmutable($local->format('Y-m-d H:i:s'), Clock::zone($ex['zone']));
                        $in['tzid'] = $ex['zone'];
                        $want[] = 'time';
                    }
                    // Its own date when the day it shows on is another than its occurrence's.
                    if (Clock::dnOf($local) !== $occ['dn']) {
                        $want[] = 'date';
                    }
                    $l = $len + (in_array('length', $own, true) ? $ex['len'] * 60 : 0);
                    if (in_array('length', $own, true)) {
                        $want[] = 'length';
                    }
                    $in += ['start' => Clock::iso($local), 'end' => Clock::iso(Clock::plus($local, max(0, $l)))];
                }
            }
            $this->events->patch($this->userId, $id, $in);
            $row = $this->db->one('SELECT * FROM events WHERE recurrence_parent_id = ? AND recurrence_instance_utc = ? AND deleted_at IS NULL', [$id, $occ['key']]);
            $list = $row === null ? [] : (json_decode((string) ($row['override_fields'] ?? '[]'), true) ?: []);
            $missing = array_values(array_diff(array_unique($want), $list));
            $this->check('b-this', $row !== null && $missing === [], 'a "this event" edit makes the fields it changed the day\'s own', ['changed' => array_values(array_unique($want)), 'own' => $list, 'key' => $occ['key']]);
        }
    }

    // ---- the state -----------------------------------------------------------

    private function row(int $id): array
    {
        return (array) $this->db->one('SELECT * FROM events WHERE id = ?', [$id]);
    }

    /** The series as the oracle reads it: master, raw occurrences (Truth), skipped and edited days, own lists, tags and people. */
    private function state(int $id): array
    {
        $row = $this->row($id);
        $tz = Truth::tzOf($row);
        $live = $row !== [] && $row['deleted_at'] === null && $row['rrule'] !== null;
        $exp = $live ? Truth::expand($row) : ['occ' => [], 'through' => null];
        $start = $row === [] ? null : Clock::local((string) $row['start_utc'], $tz);
        $startDn = $start === null ? 0 : Clock::dnOf($start);
        $overrides = [];
        foreach ($this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id', [$id]) as $ov) {
            $overrides[(int) $ov['id']] = $ov;
        }
        // An edited day's own things (series-model.md, Own Fields): the
        // fields its list names, and its trip when a link names it, since a
        // row exists for what refers to it too.
        $own = [];
        $linked = [];
        if ($overrides !== []) {
            [$in, $params] = Db::in(array_keys($overrides));
            foreach ($this->db->all("SELECT container_id, event_id FROM event_links WHERE event_id IN $in OR container_id IN $in", array_merge($params, $params)) as $l) {
                $linked[(int) $l['container_id']] = true;
                $linked[(int) $l['event_id']] = true;
            }
        }
        foreach ($overrides as $oid => $ov) {
            $own[$oid] = json_decode((string) ($ov['override_fields'] ?? '[]'), true) ?: [];
            if (isset($linked[$oid])) {
                $own[$oid][] = 'trip';
            }
        }
        $ids = array_merge([$id], array_keys($overrides));
        [$tags, $people] = $this->labels($ids);
        $shape = Shape::parse((string) ($row['rrule'] ?? ''), $startDn)->inZone($tz, $start === null || (int) ($row['all_day'] ?? 0) === 1 ? 0 : Clock::secsOf($start), (int) ($row['all_day'] ?? 0) === 1);
        $occ = $exp['occ'];
        return [
            'id' => $id,
            'row' => $row,
            'live' => $live,
            'shape' => $shape,
            'tz' => $tz,
            'allDay' => (int) ($row['all_day'] ?? 0) === 1,
            'startDn' => $startDn,
            'clock' => $start === null || (int) $row['all_day'] === 1 ? 0 : Clock::secsOf($start),
            'len' => $row === [] ? 0 : Clock::parse((string) $row['end_utc'])->getTimestamp() - Clock::parse((string) $row['start_utc'])->getTimestamp(),
            'raw' => $occ,
            'through' => $exp['through'],
            'exp' => $exp,
            'bounded' => $shape->count !== null || $shape->until !== null,
            'lastDn' => $occ === [] ? null : end($occ)['dn'],
            'exdates' => $live ? Recurrence::decodeExdates($row['exdates_json'] ?? null) : [],
            'overrides' => $overrides,
            'own' => $own,
            'tags' => $tags,
            'people' => $people,
        ];
    }

    /** @return array{0:array<int,list<string>>,1:array<int,list<string>>} */
    private function labels(array $ids): array
    {
        $tags = array_fill_keys($ids, []);
        $people = array_fill_keys($ids, []);
        if ($ids !== []) {
            [$in, $params] = Db::in($ids);
            foreach ($this->db->all("SELECT et.event_id, t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id IN $in", $params) as $r) {
                $tags[(int) $r['event_id']][] = (string) $r['name'];
            }
            foreach ($this->db->all("SELECT ep.event_id, p.name FROM event_people ep JOIN people p ON p.id = ep.person_id WHERE ep.event_id IN $in", $params) as $r) {
                $people[(int) $r['event_id']][] = (string) $r['name'];
            }
        }
        foreach ($tags as &$t) {
            sort($t);
        }
        foreach ($people as &$p) {
            sort($p);
        }
        return [$tags, $people];
    }

    /** Every row this case made, link rows included, without the updated timestamps. */
    private function snapshot(): array
    {
        $events = $this->db->all('SELECT * FROM events WHERE user_id = ? AND id >= ? ORDER BY id', [$this->userId, $this->firstId]);
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $events);
        $out = ['events' => [], 'event_tags' => [], 'event_people' => [], 'event_links' => []];
        foreach ($events as $r) {
            unset($r['updated_at']);
            $out['events'][] = $r;
        }
        if ($ids !== []) {
            [$in, $params] = Db::in($ids);
            $out['event_tags'] = $this->db->all("SELECT * FROM event_tags WHERE event_id IN $in ORDER BY event_id, tag_id", $params);
            $out['event_people'] = $this->db->all("SELECT * FROM event_people WHERE event_id IN $in ORDER BY event_id, person_id", $params);
            $out['event_links'] = $this->db->all("SELECT * FROM event_links WHERE event_id IN $in OR container_id IN $in ORDER BY id", array_merge($params, $params));
        }
        return $out;
    }

    /** A snapshot with ids replaced by roles, so a redo's new rows compare with the first run's. */
    private static function roles(array $snap, int $masterId): array
    {
        $role = [];
        foreach ($snap['events'] as $r) {
            $id = (int) $r['id'];
            if ($id === $masterId) {
                $role[$id] = 'M';
            } elseif ($r['recurrence_parent_id'] === null && $r['rrule'] !== null) {
                $role[$id] = 'N';
            } elseif ($r['recurrence_parent_id'] === null) {
                $role[$id] = 'S:' . $r['start_utc'];
            }
        }
        foreach ($snap['events'] as $r) {
            $id = (int) $r['id'];
            if (!isset($role[$id])) {
                $role[$id] = ($role[(int) $r['recurrence_parent_id']] ?? 'X') . ':' . ($r['recurrence_instance_utc'] ?? '');
            }
        }
        $events = [];
        foreach ($snap['events'] as $r) {
            $key = $role[(int) $r['id']];
            unset($r['id'], $r['uid'], $r['created_at'], $r['updated_at'], $r['recurrence_parent_id']);
            $r['deleted_at'] = $r['deleted_at'] === null ? null : 'deleted';
            $events[$key] = $r;
        }
        ksort($events);
        $links = [];
        foreach (['event_tags', 'event_people'] as $t) {
            $rows = [];
            foreach ($snap[$t] as $r) {
                $r['event_id'] = $role[(int) $r['event_id']] ?? 'X';
                $rows[] = $r;
            }
            sort($rows);
            $links[$t] = $rows;
        }
        return ['events' => $events] + $links;
    }

    // ---- the edit -----------------------------------------------------------

    /**
     * The request as the client sends it for the vector: the target row
     * (an edited day's own row for a whole-series edit from it), the body,
     * and what the oracle needs to read it.
     */
    private function body(array $st, array $acted, array $v, string $scope): array
    {
        $master = $st['row'];
        $fromRow = null;
        $shown = null;
        foreach ($st['overrides'] as $oid => $ov) {
            if ((string) $ov['recurrence_instance_utc'] === $acted['key']) {
                $fromRow = $oid;
                $shown = ['start' => (string) $ov['start_utc'], 'end' => (string) $ov['end_utc'], 'tz' => Truth::tzOf($ov), 'allDay' => (int) $ov['all_day'] === 1];
            }
        }
        // An edit of an edited day goes to its own row (whole series only) or to the master with its key;
        // either way every delta is measured from where it shows (S) to where it was put (S').
        $viaRow = $fromRow !== null && $scope === 'all' && ($v['via'] ?? 'row') === 'row';
        $target = $viaRow ? $fromRow : (int) $master['id'];
        $in = ['scope' => $scope];
        if (!$viaRow && empty($this->spec['actedStart'])) {
            // (A fixed case acting on the stored start names no occurrence: the series' own values at its first one.)
            $in['instanceStart'] = $this->instanceArg($st, $acted['key']);
        }
        $from = $shown ?? ['start' => $acted['key'], 'end' => Clock::db(Clock::parse($acted['key'])->modify('+' . $st['len'] . ' seconds')), 'tz' => $st['tz'], 'allDay' => $st['allDay']];
        $sent = null;
        $len = Clock::parse($from['end'])->getTimestamp() - Clock::parse($from['start'])->getTimestamp();
        $timed = $v['date'] !== 0 || $v['clock'] !== 0 || $v['zone'] !== null || $v['length'] !== 0 || $v['allDay'];
        if ($timed) {
            $sLocal = Clock::local($from['start'], $from['tz']);
            $dS = Clock::dnOf($sLocal);
            $cS = Clock::secsOf($sLocal);
            $toAllDay = $v['allDay'] ? !$from['allDay'] : $from['allDay'];
            if ($toAllDay) {
                $day = $dS + $v['date'];
                $days = $from['allDay'] && !$v['allDay'] ? max(1, intdiv($len, 86400) + $v['length']) : 1;
                $in += ['start' => Days::date($day), 'end' => Days::date($day + $days)];
                if ($v['allDay']) {
                    $in['allDay'] = true;
                }
                $sent = ['start' => Days::date($day) . ' 00:00:00', 'end' => Days::date($day + $days) . ' 00:00:00', 'allDay' => true, 'tz' => 'UTC'];
            } else {
                if ($from['allDay']) {
                    // Made timed: a clock and a zone of its own.
                    $tz = $v['toZone'];
                    $c = $v['toClock'];
                    $at = Clock::at($dS + $v['date'], $c, $tz);
                    $l = 3600; // an hour, as the editor gives a day made timed
                    $in += ['allDay' => false, 'tzid' => $tz];
                } else {
                    $tz = $v['zone'] ?? $from['tz'];
                    $c = $cS + $v['clock'];
                    if ($c < 0 || $c >= 86400) {
                        $c = $cS - $v['clock'];
                    }
                    $at = Clock::at($dS + $v['date'], $c, $tz);
                    // A clock that happens twice names two instants: the
                    // request puts the day on the second in half the cases
                    // (by the case's number), so both directions are drawn.
                    // A clock typed names its first instant (api-contract, typed), so a typed request never names the second.
                    foreach (!empty($this->spec['typed']) && $v['zone'] === null ? [] : [3600, 1800] as $back) {
                        $later = Clock::plus($at, $back);
                        if ($later->format('Y-m-d H:i:s') === $at->format('Y-m-d H:i:s') && crc32('fold:' . ($this->spec['seed'] ?? 0) . ':' . ($this->spec['n'] ?? 0)) % 2 === 1) {
                            $at = $later;
                            break;
                        }
                    }
                    $l = max(900, $len + $v['length']);
                    if ($v['zone'] !== null) {
                        $in['tzid'] = $tz;
                    }
                }
                $end = Clock::plus($at, $l);
                $in += ['start' => Clock::iso($at), 'end' => Clock::iso($end)];
                $sent = ['start' => Clock::db($at), 'end' => Clock::db($end), 'allDay' => false, 'tz' => isset($in['tzid']) ? $tz : null];
                // The wall clocks typed (api-contract, typed), for the case's share: the start read from the clock typed,
                // its instant by the one wall-clock rule (a skipped clock the clock plus the gap, a repeated one its first
                // instant), and the series' clock the clock typed. Not with a zone change, whose two promises read the
                // wall clock on the old zone's clock.
                if (!empty($this->spec['typed']) && $v['zone'] === null) {
                    $wall = static fn(int $dn, int $secs): string => sprintf('%sT%02d:%02d:%02d', Days::date($dn), intdiv($secs, 3600), intdiv($secs % 3600, 60), $secs % 60);
                    $endLocal = Clock::local(Clock::db($end), $tz);
                    $in['typed'] = ['start' => $wall($dS + $v['date'], $c), 'end' => $wall(Clock::dnOf($endLocal), Clock::secsOf($endLocal)), 'tz' => $tz];
                    $sent += ['typed' => true, 'typedClock' => $c, 'typedTz' => $tz];
                }
            }
        }
        foreach ($v['fields'] as $f => $value) {
            match ($f) {
                'reminders' => $in['reminders'] = array_map(static fn(int $m): array => ['minutes' => $m], $value),
                'tags' => $in['tagNames'] = $value,
                'people' => $in['personNames'] = $value,
                default => $in[$f] = $value,
            };
        }
        if ($v['trip']) {
            $in['isContainer'] = true;
        }
        // The rule: absent, sent back unchanged (as stored, or as the editor writes it), or a new pattern; a new bound rides on it.
        $ruleText = null;
        $boundSent = null;
        $oldBound = $this->boundOf((string) $master['rrule']);
        // The editor writes an end in the form of the kind the series will be (a date when all-day).
        $toAllDay = $v['allDay'] ? !$st['allDay'] : $st['allDay'];
        $toTz = $toAllDay ? 'UTC' : ($st['allDay'] ? $v['toZone'] : ($v['zone'] ?? $st['tz']));
        $newBound = $v['bound'] === null ? null : $this->newBound($st, $v['bound'], $toAllDay, $toTz);
        if ($toAllDay !== $st['allDay'] && str_starts_with($oldBound, ';UNTIL=')) {
            // The stored end, as the editor writes it for the other kind: its last day as a date, or 23:59:59 of it.
            $u = substr($oldBound, 7);
            $lastDn = strlen($u) === 8 ? Days::ofDate(substr($u, 0, 4) . '-' . substr($u, 4, 2) . '-' . substr($u, 6, 2)) : Clock::dnOf(\DateTimeImmutable::createFromFormat('Ymd\THis\Z', $u, Clock::utc())->setTimezone(Clock::zone($st['tz'])));
            $oldBound = ';UNTIL=' . ($toAllDay ? str_replace('-', '', Days::date($lastDn)) : Clock::at($lastDn, 86399, $toTz)->setTimezone(Clock::utc())->format('Ymd\THis\Z'));
        }
        // A new pattern that is the stored one by the design's comparison (a weekly
        // BYDAY of the start's weekday is the bare rule) is no new rule (Mapping, Defined, step 1).
        $newIsSame = $v['rule'] === 'new' && Shape::samePattern($st['shape'], Shape::parse($v['newRule'], $st['startDn']));
        if ($v['rule'] !== 'absent' || $newBound !== null) {
            $pattern = $v['rule'] === 'new' ? $v['newRule'] : Shape::pattern((string) $master['rrule']);
            if ($v['rule'] === 'same' && $v['sameForm'] === 'editor') {
                $pattern = $this->editorForm($pattern);
            }
            $boundText = $newBound['text'] ?? ($v['rule'] === 'same' && $v['sameForm'] === 'editor' ? $this->editorBound($st, $oldBound) : $oldBound);
            $in['rrule'] = $pattern . $boundText;
            $ruleText = $v['rule'] === 'new' ? $in['rrule'] : null;
            $boundSent = $newBound['oracle'] ?? null;
            if ($boundSent !== null && ($v['rule'] !== 'new' || $newIsSame) && $this->sameBound($st, $boundSent)) {
                $boundSent = null; // the same end, in another form, is no bound change (Mapping, Defined, step 1)
            }
        }
        // A field is changed only where the value sent differs from what the occurrence shows (its edited row's, or the series').
        $shownRow = $fromRow !== null ? $st['overrides'][$fromRow] : $master;
        $changed = [];
        foreach ($v['fields'] as $f => $value) {
            if (self::fieldValue($f, $shownRow, $st) !== self::sentValue($f, $value)) {
                $changed[$f] = $value;
            }
        }
        $edit = [
            'actedKey' => $acted['key'],
            'fromRow' => $fromRow,
            'shown' => $shown,
            'sent' => $sent,
            'rule' => $newIsSame ? 'same' : ($v['rule'] === 'new' ? 'new' : ($v['rule'] === 'same' ? 'same' : 'absent')),
            'ruleText' => $newIsSame ? null : $ruleText,
            'boundSent' => $boundSent,
            'fields' => $changed,
            'trip' => $v['trip'],
        ];
        return [$target, $in, $edit];
    }

    /** Bounds are equal when the COUNTs are, both are none, or both UNTILs have the same last raw occurrence at or before them. */
    private function sameBound(array $st, array $b): bool
    {
        $shape = $st['shape'];
        if (isset($b['none'])) {
            return $shape->count === null && $shape->until === null;
        }
        if (isset($b['count'])) {
            return $shape->count === (int) $b['count'];
        }
        if ($shape->until === null || $st['raw'] === []) {
            return false;
        }
        $u = (string) $b['until'];
        $limit = strlen($u) === 8 ? substr($u, 0, 4) . '-' . substr($u, 4, 2) . '-' . substr($u, 6, 2) . ' 23:59:59' : Clock::db(\DateTimeImmutable::createFromFormat('Ymd\THis\Z', $u, Clock::utc()));
        // The last raw occurrence at or before the sent end, read on the
        // unbounded pattern: the stored series is already cut at its own
        // UNTIL, so an end that admits one more day would read the same.
        $stored = end($st['raw'])['key'];
        $unbounded = ['rrule' => Shape::pattern((string) $st['row']['rrule'])] + $st['row'];
        $from = min(Clock::parse($limit)->getTimestamp(), Clock::parse($stored)->getTimestamp()) - 400 * 86400;
        $days = (int) ceil((max(Clock::parse($limit)->getTimestamp(), Clock::parse($stored)->getTimestamp()) - $from) / 86400) + 2;
        $last = null;
        foreach (Truth::keysFrom($unbounded, Clock::db((new \DateTimeImmutable('@' . max($from, Clock::parse((string) $st['row']['start_utc'])->getTimestamp())))), $days) as $k) {
            if ($k <= $limit && $k >= (string) $st['row']['start_utc']) {
                $last = $k;
            }
        }
        return $last === $stored;
    }

    private function boundOf(string $rrule): string
    {
        $p = Shape::parts($rrule);
        return isset($p['COUNT']) ? ';COUNT=' . $p['COUNT'] : (isset($p['UNTIL']) ? ';UNTIL=' . $p['UNTIL'] : '');
    }

    /** The same pattern as the editor writes it: INTERVAL=1 spelled out, a BYDAY list in another order. */
    private function editorForm(string $pattern): string
    {
        $p = Shape::parts($pattern);
        if (isset($p['BYDAY']) && str_contains($p['BYDAY'], ',') && !preg_match('/\d/', $p['BYDAY'])) {
            $p['BYDAY'] = implode(',', array_reverse(explode(',', $p['BYDAY'])));
        }
        if (!isset($p['INTERVAL']) && in_array($p['FREQ'] ?? '', ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            $p['INTERVAL'] = '1';
        }
        return implode(';', array_map(static fn($k, $v) => "$k=$v", array_keys($p), $p));
    }

    /** The stored bound as the editor writes it: an UNTIL at 23:59:59 of its last day in the series' zone. */
    private function editorBound(array $st, string $bound): string
    {
        if (!str_starts_with($bound, ';UNTIL=') || $st['allDay'] || $st['raw'] === []) {
            return $bound;
        }
        $last = end($st['raw']);
        return ';UNTIL=' . Clock::at($last['dn'], 86399, $st['tz'])->setTimezone(Clock::utc())->format('Ymd\THis\Z');
    }

    /** A new bound as the editor sends it: a count, an end on one of its days (23:59:59 there), or none. */
    private function newBound(array $st, array $b, bool $allDay, string $tz): array
    {
        if (isset($b['count'])) {
            return ['text' => ';COUNT=' . $b['count'], 'oracle' => ['count' => $b['count']]];
        }
        if (isset($b['none'])) {
            return ['text' => '', 'oracle' => ['none' => true]];
        }
        $occ = $st['raw'][min($b['untilAt'], count($st['raw']) - 1)];
        $u = $allDay ? str_replace('-', '', Days::date($occ['dn'])) : Clock::at($occ['dn'], 86399, $tz)->setTimezone(Clock::utc())->format('Ymd\THis\Z');
        return ['text' => ';UNTIL=' . $u, 'oracle' => ['until' => $u]];
    }

    /**
     * Apply the edit. Returns what was sent (with the plan, the choices and
     * the Google plan for a whole-series edit), or null when it was refused
     * (the refusal is checked here).
     */
    private function applyEdit(int $id, array $st, array $acted, array $v, array $s0, string $slot, bool $replay = false): ?array
    {
        $inst = $this->instanceArg($st, $acted['key']);
        switch ($v['scope']) {
            case 'deleteThis':
                $this->events->deleteEvent($this->userId, $id, 'this', $inst);
                return ['kind' => 'deleteThis', 'instanceStart' => $inst];
            case 'deleteFollowing':
            case 'attendFollowing':
                return $this->splitCommit($v['scope'], $id, $inst, $s0, $slot, $replay);
            case 'attendAll':
                $this->events->setAttendance($this->userId, $id, 'going', 'all', $inst);
                return ['kind' => 'attendAll', 'instanceStart' => $inst];
        }
        [$target, $in, $edit] = $this->body($st, $acted, $v, $v['scope']);
        $this->spec['request'][$slot] = ['target' => $target === $id ? 'master' : 'edited day', 'body' => $in];
        // Whole series and "this and following" alike (api-contract, This and following): preview, pick one option per
        // group, commit with the hash. A split's plan is the part's plan with its split; from the series' first day it
        // is the whole series' (scope all), checked as one.
        $choices = $this->spec['choices'][$slot] ?? null;
        $preview = fn(array $c): array => $this->events->previewPatch($this->userId, $target, $in + ['plan' => ['choices' => $c]]);
        $plan = $preview($choices ?? []);
        // The baseline: the plan with no choice named, whose selections are the preselected options.
        $baseline = $choices === null || $choices === [] ? $plan : null;
        if ($choices === null) {
            $choices = [];
            $preselected = $this->rng === null || $this->rng->chance(0.5);
            for ($round = 0; $round < 6; $round++) {
                $added = false;
                foreach ($plan['groups'] as $g) {
                    if (isset($choices[$g['id']])) {
                        continue;
                    }
                    $enabled = array_values(array_map(static fn(array $o): string => $o['id'], array_filter($g['options'], static fn(array $o): bool => empty($o['disabled']))));
                    $choices[$g['id']] = $preselected || $this->rng === null || $enabled === [] ? $g['selected'] : $this->rng->pick($enabled);
                    $added = true;
                }
                if (!$added || $preselected) {
                    break; // the plan previewed is the preselected options' already
                }
                $plan = $preview($choices);
            }
            $this->spec['choices'][$slot] = $choices;
            $this->spec['preselected'][$slot] = $preselected;
        }
        foreach ($plan['groups'] as $g) {
            $choices[$g['id']] ??= $g['selected'];
        }
        $whole = ($plan['scope'] ?? 'all') === 'all';
        if ($slot === 'edit' && !$replay) {
            try {
                $baseline ??= $preview([]);
            } catch (HttpError) {
                $baseline = null;
            }
            if ($baseline !== null) {
                // Each group's preselection against the design's table (PlanPreselect), on the baseline plan; a split's
                // against the part's, in checkFollowing, which plans it as a series of its own.
                if ($whole) {
                    $this->checkPreselection($baseline, $st, $edit);
                } else {
                    $this->splitBaseline = $baseline;
                }
                if (!empty($this->spec['everyOption'])) {
                    $this->everyOption($target, $in, $baseline, $choices, $st, $acted, $v, $s0, $edit);
                }
            }
        }
        if (!$replay) {
            $this->same('e-preview', $s0, $this->snapshot(), 'a preview writes nothing');
        }
        $google = $slot === 'edit' && !$replay && $v['scope'] === 'all' && ($this->spec['n'] ?? 0) % 2 === 0 ? $this->googlePlan($target, $in, $choices) : null;
        try {
            $this->events->patch($this->userId, $target, $in + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices]]);
        } catch (HttpError $e) {
            if ($e->errorCode === 'series_refused') {
                $this->same('e-refused', $s0, $this->snapshot(), 'a refused edit writes nothing');
                if ($slot === 'edit' && $whole) {
                    $this->checkRefused($st, $edit, $plan, $choices, $e);
                    // A refusal of the whole change has its own shape (no zone, acted or bound.from), its start in the old zone.
                    if (array_filter($plan['refusals'] ?? [], static fn(array $r): bool => !isset($r['group'])) !== []) {
                        $this->checkShape($plan, null, true, $st['allDay'] ? null : Truth::tzOf($st['row']));
                    }
                }
                return null;
            }
            throw $e;
        }
        return ['kind' => $v['scope'] === 'following' ? 'following' : 'all', 'in' => $in, 'edit' => $edit, '_plan' => $plan, '_choices' => $choices, '_google' => $google, 'target' => $target];
    }

    /**
     * Delete following and an answer from a day on (api-contract, This and following): the split's preview (DELETE or
     * attendance with preview), then the commit with its hash and an option for every group; from the series' first day
     * the preview is `whole` and `direct`, delete all or the answer for all events, committed with no plan. A preview
     * writes nothing; a refusal (series_refused) writes nothing.
     */
    private function splitCommit(string $scope, int $id, string $inst, array $s0, string $slot, bool $replay): ?array
    {
        $delete = $scope === 'deleteFollowing';
        $named = $this->spec['choices'][$slot] ?? [];
        $plan = $delete
            ? $this->events->previewDelete($this->userId, $id, 'following', $inst, $named)
            : $this->events->previewAttendance($this->userId, $id, 'going', 'following', $inst, $named);
        if (!$replay) {
            $this->same('e-preview', $s0, $this->snapshot(), 'a preview writes nothing');
        }
        $choices = $named;
        foreach ($plan['groups'] ?? [] as $g) {
            $choices[$g['id']] ??= $g['selected'];
        }
        $this->spec['choices'][$slot] = $choices;
        $commit = !empty($plan['whole']) ? null : ['hash' => $plan['hash'], 'choices' => $choices];
        try {
            if ($delete) {
                $this->events->deleteEvent($this->userId, $id, 'following', $inst, null, $commit);
            } else {
                $this->events->setAttendance($this->userId, $id, 'going', 'following', $inst, $commit);
            }
        } catch (HttpError $e) {
            if ($e->errorCode !== 'series_refused') {
                throw $e;
            }
            $this->same('e-refused', $s0, $this->snapshot(), 'a refused edit writes nothing');
            return null;
        }
        return ['kind' => $scope, 'instanceStart' => $inst, '_plan' => $plan, '_choices' => $choices];
    }

    /** What a Google calendar would be offered for the same edit, from the same rows (invariant i). */
    private function googlePlan(int $target, array $in, array $choices): ?array
    {
        try {
            $row = $this->row($target);
            if (!empty($row['recurrence_parent_id'])) {
                // As previewPatch routes an edited day's own row: the series' edit acting on its occurrence.
                $route = new \ReflectionMethod($this->events, 'routeToMaster');
                [$row, $in] = $route->invoke($this->events, $this->userId, $row, $in);
            }
            $input = new \ReflectionMethod($this->events, 'seriesInput');
            [$gm, $grows, $gedit, $gopts] = $input->invoke($this->events, $this->userId, $row, $in, false, true);
            return \BetterCal\Domain\Series\Model::plan($gm, $grows, $gedit, $choices, $gopts);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Every option of every choice committed from the same baseline (README, Every Option): the baseline plan's
     * preselected combination and, for each group, each other enabled option with the rest as preselected, each
     * previewed with that choice, committed inside a transaction of its own, checked by the same checks as the case's
     * own commit (checkEdit, the trip rule and links) and rolled back. The combination the case itself commits is left
     * to it. An option the baseline offers that its own preview no longer offers enabled is unreachable, counted. After
     * them every row must be as before (H-isolation), so the case's own commit starts from the same state.
     */
    private function everyOption(int $target, array $in, array $baseline, array $main, array $st, array $acted, array $v, array $s0, array $edit): void
    {
        Prof::mark('options');
        $sel = [];
        foreach ($baseline['groups'] as $g) {
            $sel[$g['id']] = $g['selected'];
        }
        $combos = ['preselected' => [null, null, $sel]];
        foreach ($baseline['groups'] as $g) {
            foreach ($g['options'] as $o) {
                if (empty($o['disabled']) && $o['id'] !== $g['selected']) {
                    $combos[$g['id'] . '=' . $o['id']] = [$g['id'], $o['id'], [$g['id'] => $o['id']] + $sel];
                }
            }
        }
        $sameAsMain = static function (array $c) use ($main): bool {
            foreach ($c as $k => $x) {
                if (($main[$k] ?? null) !== $x) {
                    return false;
                }
            }
            return true;
        };
        $pdo = $this->db->pdo();
        foreach ($combos as $label => [$gid, $oid, $c]) {
            if ($sameAsMain($c)) {
                continue; // the case's own commit checks this combination
            }
            $n0 = count($this->records);
            $this->trial = $label;
            $this->ran('every-option', $gid === null ? 'preselected' : $gid);
            try {
                // Inside the app's own write (the account locked first, as every write takes it), so the commit nests in
                // it as the app's writes nest; the sentinel rolls it back.
                $this->undo->write($this->userId, function () use ($target, $in, $gid, $oid, $c, $st, $acted, $v, $s0, $edit): void {
                    $this->trialCommit($target, $in, $gid, $oid, $c, $st, $acted, $v, $s0, $edit);
                    throw new TrialRollback();
                });
            } catch (TrialRollback) {
                // rolled back, as every trial is
            } catch (\Throwable $e) {
                $this->fail('api-crash', 'the option ' . $label . ' failed with ' . ($e instanceof HttpError ? $e->errorCode : get_class($e)), ['message' => mb_substr($e->getMessage(), 0, 300)]);
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $this->trial = null;
                for ($i = $n0; $i < count($this->records); $i++) {
                    $this->records[$i]['detail']['option'] = $label;
                }
            }
        }
        $this->same('H-isolation', $s0, $this->snapshot(), 'every option was committed in isolated state: after its rollback every row is as before');
        Prof::mark('edit');
    }

    /** One option's preview and commit, checked as the case's own commit is (everyOption, inside its transaction). */
    private function trialCommit(int $target, array $in, ?string $gid, ?string $oid, array $c, array $st, array $acted, array $v, array $s0, array $edit): void
    {
        $plan = $this->events->previewPatch($this->userId, $target, $in + ['plan' => ['choices' => $c]]);
        if ($gid !== null) {
            $reached = false;
            foreach ($plan['groups'] as $g) {
                foreach ($g['id'] === $gid ? $g['options'] : [] as $o) {
                    $reached = $reached || ($o['id'] === $oid && empty($o['disabled']) && $g['selected'] === $oid);
                }
            }
            if (!$reached) {
                $this->unoracled[] = 'every option: an option the baseline offers is not offered once chosen';
                return;
            }
        }
        foreach ($plan['groups'] as $g) {
            $c[$g['id']] ??= $g['selected'];
        }
        try {
            $this->events->patch($this->userId, $target, $in + ['plan' => ['hash' => $plan['hash'], 'choices' => $c]]);
        } catch (HttpError $e) {
            if ($e->errorCode === 'trip_nested') {
                // As the case's own commit is judged (run): refused only where the end state could nest.
                // That it writes nothing is the app's own transaction's to show, which the trial's holds here: the
                // case's own commit checks it (e-refused), and the trial's rollback undoes what the refusal left.
                $this->check('b-trip', $this->nests, 'the trip flag is refused only where the end state could nest: sent to a whole series with a day in it that is a stop', ['scope' => $v['scope']]);
                return;
            }
            if ($e->errorCode !== 'series_refused') {
                throw $e;
            }
            if (($plan['scope'] ?? 'all') === 'all') {
                $this->checkRefused($st, $edit, $plan, $c, $e);
            }
            return;
        }
        $sent = ['kind' => $v['scope'] === 'following' ? 'following' : 'all', 'in' => $in, 'edit' => $edit, '_plan' => $plan, '_choices' => $c, '_google' => null, 'target' => $target];
        $this->checkTripRule('b-trip');
        $this->checkEdit($st, $this->state($st['id']), $acted, $v, $sent, $s0);
        $this->checkTripLinks($st, $acted, $sent);
    }

    /** The edit applied again after Undo, with the same choices. */
    private function replay(int $id, array $before, array $acted, array $v, array $sent): void
    {
        $this->applyEdit($id, $before, $acted, $v, [], 'edit', true);
    }

    /** A second edit on top, undone twice: the state after the first, then before either. */
    private function runStack(int $id, array $s0, array $s1): void
    {
        $stack = $this->spec['stack'] ?? null;
        if ($stack === null) {
            return;
        }
        $live = $this->liveMaster($id);
        if ($live === null) {
            return;
        }
        $st = $this->state($live);
        $skipped = array_fill_keys($st['exdates'], true);
        $occ = null;
        foreach ($st['raw'] as $o) {
            if (!isset($skipped[$o['key']])) {
                $occ = $o;
                break;
            }
        }
        if ($occ === null) {
            return;
        }
        $v = ['scope' => 'all', 'date' => 0, 'clock' => 0, 'zone' => null, 'length' => 0, 'allDay' => false, 'toZone' => 'UTC', 'toClock' => 0,
            'trip' => false, 'fields' => [], 'rule' => 'absent', 'sameForm' => 'text', 'newRule' => '', 'bound' => null];
        switch ($stack['kind']) {
            case 'rename':
                $v['fields']['title'] = 'Sample series, second name';
                break;
            case 'deleteThis':
                $v['scope'] = 'deleteThis';
                break;
            case 'time':
                if ($st['allDay']) {
                    $v['fields']['title'] = 'Sample series, second name';
                } else {
                    $v['clock'] = $stack['mins'] * 60;
                }
                break;
            default:
                $v['date'] = $stack['days'];
        }
        try {
            $done = $this->applyEdit($live, $st, $occ, $v, $s1, 'stack');
        } catch (\Throwable $e) {
            $this->fail('f-stack', 'the second edit failed: ' . ($e instanceof HttpError ? $e->errorCode : get_class($e)), ['message' => mb_substr($e->getMessage(), 0, 300)]);
            return;
        }
        if ($done === null) {
            return;
        }
        if ($this->undoLatest('f-stack', 'undo of the second edit')) {
            $this->same('f-stack', $s1, $this->snapshot(), 'undoing the second edit gives the state after the first');
            if ($this->undoLatest('f-stack', 'undo of the first edit under the second')) {
                $this->same('f-stack', $s0, $this->snapshot(), 'undoing both gives the state before either');
            }
        }
    }

    /** The series to stack a second edit on: the original, or the one a split made; null when none is live. */
    private function liveMaster(int $id): ?int
    {
        $m = $this->row($id);
        if ($m['deleted_at'] === null && $m['rrule'] !== null && Truth::expand($m, 2)['occ'] !== []) {
            return $id;
        }
        $other = $this->db->scalar('SELECT id FROM events WHERE user_id = ? AND id >= ? AND recurrence_parent_id IS NULL AND rrule IS NOT NULL AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$this->userId, $this->firstId]);
        return $other === null ? null : (int) $other;
    }

    private function undoLatest(string $inv, string $what): bool
    {
        try {
            $this->undo->undoLatest($this->userId);
            return true;
        } catch (\Throwable $e) {
            $this->fail($inv, $what . ' failed: ' . ($e instanceof HttpError ? $e->errorCode : get_class($e)), ['message' => mb_substr($e->getMessage(), 0, 300)]);
        }
        return false;
    }

    // ---- records -------------------------------------------------------------

    private function check(string $invariant, bool $ok, string $what, array $detail = []): void
    {
        if (!$ok) {
            $this->records[] = ['invariant' => $invariant, 'what' => $what, 'detail' => $detail];
        }
    }

    private function fail(string $invariant, string $what, array $detail = []): void
    {
        $this->records[] = ['invariant' => $invariant, 'what' => $what, 'detail' => $detail];
    }

    private function same(string $invariant, array $expected, array $actual, string $what): void
    {
        if ($expected !== $actual) {
            $this->records[] = ['invariant' => $invariant, 'what' => $what, 'detail' => ['diff' => self::diff($expected, $actual)]];
        }
    }

    private static function diff(array $a, array $b): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $table) {
            $ra = $a[$table] ?? [];
            $rb = $b[$table] ?? [];
            if ($ra === $rb) {
                continue;
            }
            $ja = array_map('json_encode', $ra);
            $jb = array_map('json_encode', $rb);
            $out[$table] = ['before' => array_slice(array_values(array_diff($ja, $jb)), 0, 4), 'after' => array_slice(array_values(array_diff($jb, $ja)), 0, 4)];
        }
        return $out;
    }
}

/** Thrown at the end of an option's commit (Driver::everyOption) so the write that holds it rolls back. */
final class TrialRollback extends \RuntimeException
{
}
