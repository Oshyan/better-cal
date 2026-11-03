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

    /** @var list<array> */
    private array $records = [];
    private array $spec = [];
    private ?Rng $rng = null;
    private int $firstId = 0;
    /** Parts of the design the oracle had no answer for in this case. */
    public array $unoracled = [];

    public function __construct(
        private readonly Db $db,
        private readonly Events $events,
        private readonly Undo $undo,
        private readonly int $userId,
        private readonly int $calendarId,
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
        $this->spec = $spec;
        $this->rng = $r;
        $this->firstId = (int) $this->db->scalar('SELECT COALESCE(MAX(id), 0) + 1 FROM events');
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
        $spec = $this->spec;
        if ($st['raw'] === [] || !isset($st['raw'][$spec['acted']])) {
            return $this->result('none');
        }
        Prof::mark('exceptions');
        try {
            $this->applyExceptions($id, $st, $spec['exceptions']);
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
        $acted = $before['raw'][$spec['acted']] ?? $before['raw'][0];
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
        try {
            $sent = $this->applyEdit($id, $before, $acted, $v, $s0, 'edit');
        } catch (\Throwable $e) {
            $this->fail('api-crash', 'the edit failed with ' . ($e instanceof HttpError ? $e->errorCode : get_class($e)), ['message' => mb_substr($e->getMessage(), 0, 300)]);
            return $this->result($path);
        }
        if ($sent === null) {
            return $this->result($path); // refused, checked
        }
        Prof::mark('state');
        $after = $this->state($id);
        Prof::mark('snapshot');
        $s1 = $this->snapshot();
        Prof::mark('checks');
        $this->checkEdit($before, $after, $acted, $v, $sent, $s0);
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

    private function result(string $path): array
    {
        Prof::mark('');
        return ['spec' => $this->spec, 'records' => $this->records, 'path' => $path, 'unoracled' => $this->unoracled];
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

    private function instanceArg(array $st, string $key): string
    {
        return $st['allDay'] ? substr($key, 0, 10) : Clock::iso(Clock::local($key, $st['tz']));
    }

    /** Edited and skipped days, each as a "this event" edit or delete; each edit's own list must name what it changed. */
    private function applyExceptions(int $id, array $st, array $exceptions): void
    {
        foreach ($exceptions as $i => $ex) {
            $occ = $st['raw'][$ex['at']] ?? null;
            if ($occ === null) {
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
        $exp = $live ? Truth::expand($row) : ['occ' => [], 'through' => null, 'phantom' => null];
        $start = $row === [] ? null : Clock::local((string) $row['start_utc'], $tz);
        $startDn = $start === null ? 0 : Clock::dnOf($start);
        $overrides = [];
        foreach ($this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id', [$id]) as $ov) {
            $overrides[(int) $ov['id']] = $ov;
        }
        $own = [];
        foreach ($overrides as $oid => $ov) {
            $own[$oid] = json_decode((string) ($ov['override_fields'] ?? '[]'), true) ?: [];
        }
        $ids = array_merge([$id], array_keys($overrides));
        [$tags, $people] = $this->labels($ids);
        $shape = Shape::parse((string) ($row['rrule'] ?? ''), $startDn);
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
            'phantom' => $exp['phantom'],
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
        if (!$viaRow) {
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
                    $at = Clock::at($dS + $v['date'], $v['toClock'], $tz);
                    $l = 3600; // an hour, as the editor gives a day made timed
                    $in += ['allDay' => false, 'tzid' => $tz];
                } else {
                    $tz = $v['zone'] ?? $from['tz'];
                    $c = $cS + $v['clock'];
                    if ($c < 0 || $c >= 86400) {
                        $c = $cS - $v['clock'];
                    }
                    $at = Clock::at($dS + $v['date'], $c, $tz);
                    $l = max(900, $len + $v['length']);
                    if ($v['zone'] !== null) {
                        $in['tzid'] = $tz;
                    }
                }
                $end = Clock::plus($at, $l);
                $in += ['start' => Clock::iso($at), 'end' => Clock::iso($end)];
                $sent = ['start' => Clock::db($at), 'end' => Clock::db($end), 'allDay' => false, 'tz' => isset($in['tzid']) ? $tz : null];
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
        if ($v['rule'] !== 'absent' || $newBound !== null) {
            $pattern = $v['rule'] === 'new' ? $v['newRule'] : Shape::pattern((string) $master['rrule']);
            if ($v['rule'] === 'same' && $v['sameForm'] === 'editor') {
                $pattern = $this->editorForm($pattern);
            }
            $boundText = $newBound['text'] ?? ($v['rule'] === 'same' && $v['sameForm'] === 'editor' ? $this->editorBound($st, $oldBound) : $oldBound);
            $in['rrule'] = $pattern . $boundText;
            $ruleText = $v['rule'] === 'new' ? $in['rrule'] : null;
            $boundSent = $newBound['oracle'] ?? null;
            if ($boundSent !== null && $v['rule'] !== 'new' && $this->sameBound($st, $boundSent)) {
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
            'rule' => $v['rule'] === 'new' ? 'new' : ($v['rule'] === 'same' ? 'same' : 'absent'),
            'ruleText' => $ruleText,
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
        $last = null;
        foreach ($st['raw'] as $o) {
            if ($o['key'] <= $limit) {
                $last = $o['key'];
            }
        }
        return $last === end($st['raw'])['key'];
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
                $this->events->deleteEvent($this->userId, $id, 'following', $inst);
                return ['kind' => 'deleteFollowing', 'instanceStart' => $inst];
            case 'attendAll':
                $this->events->setAttendance($this->userId, $id, 'going', 'all', $inst);
                return ['kind' => 'attendAll', 'instanceStart' => $inst];
            case 'attendFollowing':
                $this->events->setAttendance($this->userId, $id, 'going', 'following', $inst);
                return ['kind' => 'attendFollowing', 'instanceStart' => $inst];
        }
        [$target, $in, $edit] = $this->body($st, $acted, $v, $v['scope']);
        $this->spec['request'][$slot] = ['target' => $target === $id ? 'master' : 'edited day', 'body' => $in];
        if ($v['scope'] === 'following') {
            $plan = null;
            if ($acted['key'] === (string) $st['row']['start_utc'] && $slot === 'edit' && !$replay) {
                // From the first day it is the whole series with its preselected options: the plan it takes.
                try {
                    $plan = $this->events->previewPatch($this->userId, $target, ['scope' => 'all'] + $in);
                } catch (HttpError) {
                    $plan = null;
                }
            }
            try {
                $this->events->patch($this->userId, $target, $in);
            } catch (HttpError $e) {
                if ($e->errorCode === 'series_refused') {
                    $this->same('e-refused', $s0, $this->snapshot(), 'a refused edit writes nothing');
                    return null;
                }
                throw $e;
            }
            $choices = [];
            foreach ($plan['groups'] ?? [] as $g) {
                $choices[$g['id']] = $g['selected'];
            }
            return ['kind' => 'following', 'in' => $in, 'edit' => $edit, '_plan' => $plan, '_choices' => $choices, 'target' => $target];
        }
        // Whole series: preview, pick one option per group, commit with the hash.
        $choices = $this->spec['choices'][$slot] ?? null;
        $preview = fn(array $c): array => $this->events->previewPatch($this->userId, $target, $in + ['plan' => ['choices' => $c]]);
        $plan = $preview($choices ?? []);
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
        if (!$replay) {
            $this->same('e-preview', $s0, $this->snapshot(), 'a preview writes nothing');
        }
        $google = $slot === 'edit' && !$replay && ($this->spec['n'] ?? 0) % 2 === 0 ? $this->googlePlan($target, $in, $choices) : null;
        try {
            $this->events->patch($this->userId, $target, $in + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices]]);
        } catch (HttpError $e) {
            if ($e->errorCode === 'series_refused') {
                $this->same('e-refused', $s0, $this->snapshot(), 'a refused edit writes nothing');
                if ($slot === 'edit') {
                    $this->checkRefused($st, $edit, $plan, $choices, $e);
                }
                return null;
            }
            throw $e;
        }
        return ['kind' => 'all', 'in' => $in, 'edit' => $edit, '_plan' => $plan, '_choices' => $choices, '_google' => $google, 'target' => $target];
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
