<?php
// The fixed action sequences (README, The Sequences): hand-written edits on
// one series, run one after another through the same driver, each step
// checked by every check a case runs (the oracle and the invariants for an
// edit, b-this, g and h for a "this event" edit) and against what the step's
// spec states, worked out by hand from the design: the first events shown,
// in a zone the step names, and their number. An Undo step must put back
// every row exactly as it was before the step it undoes. The spec files are
// in sequences/, and nothing in them is read from the product.
declare(strict_types=1);

namespace BetterCal\EditHarness;

use BetterCal\Http\HttpError;

trait Sequences
{
    /** What a step of a sequence leaves out of its vector. */
    private const STEP_VECTOR = [
        'scope' => 'all', 'date' => 0, 'clock' => 0, 'zone' => null, 'length' => 0, 'allDay' => false, 'toZone' => 'UTC', 'toClock' => 0,
        'trip' => false, 'fields' => [], 'rule' => 'absent', 'sameForm' => 'text', 'newRule' => '', 'bound' => null, 'via' => 'master',
    ];

    /** The step a record was made at, for its detail. */
    private int $step = 0;

    /** @return array{spec:array,records:list<array>,path:string} */
    public function runSequence(array $spec): array
    {
        $this->records = [];
        $this->unoracled = [];
        $this->runs = [];
        $this->wide0 = null;
        $spec += ['n' => 0, 'seed' => 0, 'exceptions' => [], 'everyOption' => true];
        $this->spec = $spec;
        $this->rng = null;
        $this->controls = $this->makeControls();
        $this->firstId = (int) $this->db->scalar('SELECT COALESCE(MAX(id), 0) + 1 FROM events');
        $this->fixtures = [];
        $this->tripLinks = [];
        $this->tripsDrawn = [];
        $this->nests = false;
        $this->step = 0;
        $id = $this->createSeries($spec['series']);
        $this->checkSeries('g-created', $this->state($id));
        try {
            $this->applyExceptions($id, $this->state($id), $spec['exceptions']);
        } catch (HttpError $e) {
            $this->fail('b-this', 'a "this event" edit was refused: ' . $e->errorCode, ['message' => $e->getMessage()]);
            return $this->result('sequence');
        }
        $this->checkExpect(['step' => 0] + ($spec['expect'] ?? []));
        $undoable = [];
        $master = $id;
        foreach ($spec['steps'] as $i => $step) {
            $this->step = $i + 1;
            $n0 = count($this->records);
            $before = $this->state($master);
            $s0 = $this->snapshot();
            try {
                switch ($step['do']) {
                    case 'edit':
                        $acted = $this->occurrenceOn($before, (string) $step['acted']);
                        if ($acted === null) {
                            $this->fail('s-step', "step {$this->step}: the series has no occurrence on {$step['acted']} to act on", []);
                            break;
                        }
                        $v = $step['vector'] + self::STEP_VECTOR;
                        $this->spec['vector'] = $v;
                        $this->spec['choices']['edit'] = $step['choices'] ?? [];
                        $this->spec['preselected']['edit'] = ($step['choices'] ?? []) === [];
                        $this->spec['path'] = 'sequence';
                        $this->wide0 = $v['scope'] === 'all' ? $this->wide() : null;
                        $sent = $this->applyEdit($master, $before, $acted, $v, $s0, 'edit');
                        $this->checkTripRule('b-trip');
                        if ($sent !== null) {
                            $this->checkEdit($before, $this->state($master), $acted, $v, $sent, $s0);
                            $this->checkTripLinks($before, $acted, $sent);
                            $undoable[] = $s0;
                        }
                        $this->check('s-step', ($sent === null) === !empty($step['refused']), "step {$this->step}: the edit is " . (!empty($step['refused']) ? 'refused' : 'applied') . ' as the sequence states', []);
                        break;
                    case 'this':
                        $acted = $this->occurrenceOn($before, (string) $step['acted']);
                        $at = $acted === null ? null : array_search($acted['key'], array_column($before['raw'], 'key'), true);
                        if ($at === false || $at === null) {
                            $this->fail('s-step', "step {$this->step}: the series has no occurrence on {$step['acted']} to act on", []);
                            break;
                        }
                        $this->applyExceptions($master, $before, [['at' => $at] + $step['exception'] + ['kind' => 'edit', 'own' => [], 'days' => 0, 'mins' => 0, 'zone' => 'UTC', 'len' => 0]]);
                        $after = $this->state($master);
                        $this->checkSeries('g', $after);
                        $this->checkAttached('h', $after);
                        $undoable[] = $s0;
                        break;
                    case 'undo':
                        $want = array_pop($undoable);
                        if ($want === null) {
                            $this->fail('s-undo', "step {$this->step}: the sequence undoes more steps than it made", []);
                            break;
                        }
                        try {
                            $this->undo->undoLatest($this->userId);
                            $this->same('s-undo', $want, $this->snapshot(), "step {$this->step}: Undo puts back every row as it was before the step it undoes");
                        } catch (\Throwable $e) {
                            $this->fail('s-undo', "step {$this->step}: Undo failed: " . ($e instanceof HttpError ? $e->errorCode : get_class($e)), ['message' => mb_substr($e->getMessage(), 0, 300)]);
                        }
                        break;
                    default:
                        throw new \RuntimeException("unknown step {$step['do']}");
                }
            } catch (HttpError $e) {
                $this->fail('api-crash', "step {$this->step}: failed with {$e->errorCode}", ['message' => mb_substr($e->getMessage(), 0, 300)]);
            }
            $this->checkExpect(['step' => $this->step] + ($step['expect'] ?? []));
            for ($r = $n0; $r < count($this->records); $r++) {
                $this->records[$r]['detail']['step'] = $this->step;
            }
            $master = $this->liveMaster($id) ?? $id;
        }
        $this->spec['path'] = 'sequence';
        return $this->result('sequence');
    }

    /**
     * A split on a Google calendar the account can write is refused until 1.5.2 (api-contract, This and following):
     * "this and following" from a day after the first, delete following and an answer from a day on each preview a plan
     * whose refusal is `google`, and the commit with its hash is 422 series_refused, writing nothing and calling no one
     * (`e-google`). The calendar and its series are written as a Google sync writes them; no Google stand-in is needed,
     * since nothing may reach it.
     */
    public function runGoogleSplit(array $spec): array
    {
        $this->records = [];
        $this->unoracled = [];
        $this->runs = [];
        $this->spec = $spec + ['n' => 0, 'seed' => 0];
        $this->firstId = (int) $this->db->scalar('SELECT COALESCE(MAX(id), 0) + 1 FROM events');
        $account = $this->db->insert('google_accounts', ['user_id' => $this->userId, 'email' => 'owner@example.test', 'refresh_token_enc' => 'sealed-sample', 'scopes' => 'calendar']);
        $cal = $this->db->insert('calendars', ['user_id' => $this->userId, 'name' => 'Google sample', 'color' => '#336699', 'kind' => 'subscribed', 'provider' => 'google',
            'google_account_id' => $account, 'google_calendar_id' => 'sample@group.calendar.google.com', 'google_access_role' => 'owner', 'subscription_authority' => 'owner',
            'position' => \BetterCal\Domain\Order::endOfList($this->db, $this->userId), 'role' => 'mine']);
        $now = \BetterCal\Support\Time::nowDb();
        $s = $spec['series'];
        $id = $this->db->insert('events', ['user_id' => $this->userId, 'calendar_id' => $cal, 'uid' => 'sample-series@google', 'title' => 'Sample series',
            'start_utc' => $s['start'] . ':00', 'end_utc' => date('Y-m-d H:i:s', strtotime($s['start'] . ':00 UTC') + 60 * (int) $s['length']), 'all_day' => 0, 'tzid' => 'UTC',
            'rrule' => $s['rrule'], 'source' => 'feed', 'google_event_id' => 'g-sample-series', 'created_at' => $now, 'updated_at' => $now]);
        $at = (string) $spec['actedAt'];
        $s0 = $this->snapshot();
        $refused = function (string $what, callable $preview, callable $commit) use ($s0): void {
            try {
                $plan = $preview();
            } catch (\Throwable $e) {
                $this->fail('e-google', "$what: the preview failed with " . ($e instanceof HttpError ? $e->errorCode : get_class($e)), ['message' => mb_substr($e->getMessage(), 0, 300)]);
                return;
            }
            $codes = array_column($plan['refusals'] ?? [], 'code');
            $this->check('e-google', in_array('google', $codes, true), "$what: the plan refuses a split on a Google calendar (google)", ['refusals' => $codes]);
            $choices = [];
            foreach ($plan['groups'] ?? [] as $g) {
                $choices[$g['id']] = $g['selected'];
            }
            $got = null;
            try {
                $commit(['hash' => $plan['hash'] ?? null, 'choices' => $choices]);
            } catch (HttpError $e) {
                $got = $e->status . ' ' . $e->errorCode;
            } catch (\Throwable $e) {
                $got = get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200);
            }
            $this->check('e-google', $got === '422 series_refused', "$what: the commit is 422 series_refused", ['got' => $got]);
            $this->same('e-google', $s0, $this->snapshot(), "$what: a refused split writes nothing");
        };
        $edit = ['scope' => 'following', 'instanceStart' => $at, 'title' => 'Sample series, renamed'];
        $refused('this and following', fn() => $this->events->previewPatch($this->userId, $id, $edit), fn(array $p) => $this->events->patch($this->userId, $id, $edit + ['plan' => $p]));
        $refused('delete following', fn() => $this->events->previewDelete($this->userId, $id, 'following', $at), fn(array $p) => $this->events->deleteEvent($this->userId, $id, 'following', $at, null, $p));
        $refused('an answer from a day on', fn() => $this->events->previewAttendance($this->userId, $id, 'going', 'following', $at), fn(array $p) => $this->events->setAttendance($this->userId, $id, 'going', 'following', $at, $p));
        return $this->result('google split');
    }

    /** The raw occurrence of a series on a local date in its own zone, or null. */
    private function occurrenceOn(array $st, string $date): ?array
    {
        $dn = Days::ofDate($date);
        foreach ($st['raw'] as $o) {
            if ($o['dn'] === $dn) {
                return $o;
            }
        }
        return null;
    }

    /**
     * A step's stated expectations: `shows`, the first events the case's rows show, in order, each as "Y-m-d H:i Title"
     * in the zone `zone` (a date and title for an all-day one), and `events`, how many they show in all (for a series
     * that ends). What shows: each live series' occurrences (the harness's own evaluator) without its skipped days and
     * edited days' keys, its edited days where they show, and every event of its own the case's rows made.
     */
    private function checkExpect(array $expect): void
    {
        if (!isset($expect['shows']) && !isset($expect['events'])) {
            return;
        }
        $zone = (string) ($expect['zone'] ?? 'UTC');
        $rows = $this->db->all('SELECT * FROM events WHERE user_id = ? AND id >= ? AND deleted_at IS NULL ORDER BY id', [$this->userId, $this->firstId]);
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $items = [];
        $complete = true;
        $show = static function (string $startUtc, bool $allDay, string $title) use ($zone): array {
            $t = Clock::parse($startUtc);
            return [$t->getTimestamp(), ($allDay ? substr($startUtc, 0, 10) : $t->setTimezone(Clock::zone($zone))->format('Y-m-d H:i')) . ' ' . $title];
        };
        foreach ($rows as $r) {
            if (in_array((int) $r['id'], $this->fixtures, true)) {
                continue;
            }
            $parent = $r['recurrence_parent_id'] === null ? null : ($byId[(int) $r['recurrence_parent_id']] ?? null);
            if ($r['recurrence_parent_id'] !== null) {
                if ($parent !== null) {
                    $items[] = $show((string) $r['start_utc'], (int) $r['all_day'] === 1, (string) $r['title']);
                }
                continue;
            }
            if ($r['rrule'] === null) {
                $items[] = $show((string) $r['start_utc'], (int) $r['all_day'] === 1, (string) $r['title']);
                continue;
            }
            $exp = Truth::expand($r);
            $complete = $complete && $exp['through'] === null;
            $hidden = array_fill_keys(\BetterCal\Domain\Recurrence::decodeExdates($r['exdates_json'] ?? null), true);
            foreach ($rows as $ov) {
                if ((int) ($ov['recurrence_parent_id'] ?? 0) === (int) $r['id']) {
                    $hidden[(string) $ov['recurrence_instance_utc']] = true;
                }
            }
            foreach ($exp['occ'] as $o) {
                if (!isset($hidden[$o['key']])) {
                    $items[] = $show($o['key'], (int) $r['all_day'] === 1, (string) $r['title']);
                }
            }
        }
        usort($items, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $got = array_column($items, 1);
        if (isset($expect['shows'])) {
            $first = array_slice($got, 0, count($expect['shows']));
            $this->check('s-step', $first === $expect['shows'], "step {$expect['step']}: the first events shown are the ones the sequence states", ['expected' => $expect['shows'], 'shown' => $first]);
        }
        if (isset($expect['events'])) {
            $this->check('s-step', $complete && count($got) === (int) $expect['events'], "step {$expect['step']}: the events shown number what the sequence states", ['expected' => $expect['events'], 'shown' => $complete ? count($got) : 'no end']);
        }
    }
}
