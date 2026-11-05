<?php
// The fixed action sequences (README, The Sequences): hand-written edits on
// one series, run one after another through the same driver, each step
// checked by every check a case runs (the oracle and the invariants for an
// edit, b-this, g and h for a "this event" edit, the trip rule for a link)
// and against what the step's spec states, worked out by hand from the
// design: the first events shown, in a zone the step names, their number,
// and a refusal by its code. An Undo, of the latest change or of a step's
// own entry, must put back every row that step changed as it was before it
// and leave every other row as it is. The spec files are in sequences/, and
// nothing in them is read from the product.
declare(strict_types=1);

namespace BetterCal\EditHarness;

use BetterCal\Http\HttpError;

trait Sequences
{
    /** What a step of a sequence leaves out of its vector. */
    private const STEP_VECTOR = [
        'scope' => 'all', 'date' => 0, 'clock' => 0, 'zone' => null, 'length' => 0, 'allDay' => false, 'toZone' => 'UTC', 'toClock' => 0,
        'trip' => false, 'untrip' => false, 'fields' => [], 'rule' => 'absent', 'sameForm' => 'text', 'newRule' => '', 'bound' => null, 'via' => 'master',
    ];

    /** The step kinds that write, whose Activity entries an Undo step can take back. */
    private const WRITING_STEPS = ['edit', 'this', 'attach', 'detach'];

    /** The step a record was made at, for its detail. */
    private int $step = 0;

    /** A plan previewed and held for a later step (`preview`, then `commitHeld`): [target, request, plan]. */
    private ?array $held = null;

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
        $this->held = null;
        $id = $this->createSeries($spec['series']);
        if (!empty($spec['series']['trip'])) {
            // A series that is a trip: the flag for all events, sent as the editor sends it (direct).
            $this->events->patch($this->userId, $id, ['scope' => 'all', 'isContainer' => true]);
        }
        $this->checkSeries('g-created', $this->state($id));
        try {
            $this->applyExceptions($id, $this->state($id), $spec['exceptions']);
        } catch (HttpError $e) {
            $this->fail('b-this', 'a "this event" edit was refused: ' . $e->errorCode, ['message' => $e->getMessage()]);
            return $this->result('sequence');
        }
        // The trip a link step attaches the series to: an event of its own far from the series' days, a fixture.
        $trip = null;
        if (array_intersect(array_column($spec['steps'], 'do'), ['attach', 'detach']) !== []) {
            $trip = (int) $this->events->create($this->userId, ['calendarId' => $this->calendarId, 'title' => 'Sample trip',
                'start' => '2090-02-01T10:00:00+00:00', 'end' => '2090-02-08T10:00:00+00:00', 'allDay' => false, 'tzid' => 'UTC', 'isContainer' => true])['eventId'];
            $this->fixtures[] = $trip;
        }
        $this->checkExpect(['step' => 0] + ($spec['expect'] ?? []));
        // Each writing step: the rows before and after it and the Activity entries it made, for an Undo step.
        $done = [];
        $master = $id;
        foreach ($spec['steps'] as $i => $step) {
            $this->step = $i + 1;
            $n0 = count($this->records);
            $before = $this->state($master);
            $s0 = $this->snapshot();
            $m0 = $this->lastEntry();
            $refusal = null;
            try {
                switch ($step['do']) {
                    case 'edit':
                        $refusal = $this->stepEdit($master, $before, $step, $s0);
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
                        break;
                    case 'attach':
                    case 'detach':
                        $trips = new \BetterCal\Domain\Trips($this->db, $this->undo);
                        $step['do'] === 'attach' ? $trips->attach($this->userId, (int) $trip, $master) : $trips->detach($this->userId, (int) $trip, $master);
                        $this->checkTripRule('b-trip');
                        $linked = $this->db->scalar('SELECT id FROM event_links WHERE container_id = ? AND event_id = ?', [$trip, $master]) !== null;
                        $this->check('s-step', $linked === ($step['do'] === 'attach'), "step {$this->step}: the series is " . ($step['do'] === 'attach' ? '' : 'no longer ') . 'a stop of the trip', []);
                        $after = $this->state($master);
                        $this->checkSeries('g', $after);
                        $this->checkAttached('h', $after);
                        break;
                    case 'preview':
                        $this->stepPreview($before, $step, $s0);
                        break;
                    case 'commitHeld':
                        $this->stepCommitHeld($step, $s0);
                        break;
                    case 'undo':
                        $this->stepUndo($done, null, $step);
                        break;
                    case 'undoEntry':
                        $this->stepUndo($done, (int) $step['step'], $step);
                        break;
                    default:
                        throw new \RuntimeException("unknown step {$step['do']}");
                }
            } catch (HttpError $e) {
                if (in_array($step['do'], self::WRITING_STEPS, true) && isset($step['refused']) && $e->errorCode === $step['refused']) {
                    // Refused as the sequence states (a trip rule's 400): nothing written, and the refusal says why.
                    $refusal = $e->errorCode;
                    $this->same('e-refused', $s0, $this->snapshot(), "step {$this->step}: a refused step writes nothing");
                    $this->check('s-step', trim($e->getMessage()) !== '', "step {$this->step}: the refusal says why", []);
                } else {
                    $this->fail('api-crash', "step {$this->step}: failed with {$e->errorCode}", ['message' => mb_substr($e->getMessage(), 0, 300)]);
                }
            }
            if (in_array($step['do'], ['edit', 'attach', 'detach'], true)) {
                $want = $step['refused'] ?? null;
                $this->check('s-step', $refusal === $want, "step {$this->step}: the step is " . ($want === null ? 'applied' : "refused with $want") . ' as the sequence states', ['refused' => $refusal]);
            }
            $entries = $this->entriesSince($m0);
            if (in_array($step['do'], self::WRITING_STEPS, true) && $entries !== []) {
                $done[$this->step] = ['s0' => $s0, 's1' => $this->snapshot(), 'entries' => $entries];
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
     * An edit step: through the plan API with the choices its spec names (none: the preselected ones), checked as a
     * case's edit is. Returns the refusal's code when the plan refused it (series_refused); a trip rule's refusal is
     * thrown, for the step loop to judge. A step stated refused with trip_nested is one whose end state could nest, so
     * the commit of every option is judged the same way (Driver::$nests).
     */
    private function stepEdit(int $master, array $before, array $step, array $s0): ?string
    {
        $acted = $this->occurrenceOn($before, (string) $step['acted']);
        if ($acted === null) {
            $this->fail('s-step', "step {$this->step}: the series has no occurrence on {$step['acted']} to act on", []);
            return null;
        }
        $v = $step['vector'] + self::STEP_VECTOR;
        $this->spec['vector'] = $v;
        $this->spec['choices']['edit'] = $step['choices'] ?? [];
        $this->spec['preselected']['edit'] = ($step['choices'] ?? []) === [];
        $this->spec['path'] = 'sequence';
        $this->nests = ($step['refused'] ?? null) === 'trip_nested';
        $this->wide0 = $v['scope'] === 'all' ? $this->wide() : null;
        $sent = $this->applyEdit($master, $before, $acted, $v, $s0, 'edit');
        $this->checkTripRule('b-trip');
        if ($sent === null) {
            return 'series_refused';
        }
        $this->checkEdit($before, $this->state($master), $acted, $v, $sent, $s0);
        $this->checkTripLinks($before, $acted, $sent);
        return null;
    }

    /** A plan previewed for a step to come (`commitHeld`), the request as an edit step's: it writes nothing. */
    private function stepPreview(array $before, array $step, array $s0): void
    {
        $acted = $this->occurrenceOn($before, (string) $step['acted']);
        if ($acted === null) {
            $this->fail('s-step', "step {$this->step}: the series has no occurrence on {$step['acted']} to act on", []);
            return;
        }
        $v = $step['vector'] + self::STEP_VECTOR;
        [$target, $in] = $this->body($before, $acted, $v, $v['scope']);
        $plan = $this->events->previewPatch($this->userId, $target, $in + ['plan' => ['choices' => $step['choices'] ?? []]]);
        $this->same('e-preview', $s0, $this->snapshot(), "step {$this->step}: a preview writes nothing");
        $this->held = [$target, $in, $plan];
    }

    /**
     * The held plan committed with its hash after the series changed under it (api-contract, Series Plans: the hash
     * covers every column of the master and each edited row as read): 409 with the code the sequence names
     * (series_plan_changed) and the plan as the series is now, which differs from the first and is what a preview gives
     * now, nothing written (`s-stale`). A commit that goes through is undone, so the steps after it start where the
     * sequence says.
     */
    private function stepCommitHeld(array $step, array $s0): void
    {
        if ($this->held === null) {
            $this->fail('s-stale', "step {$this->step}: no plan was previewed to commit", []);
            return;
        }
        [$target, $in, $plan] = $this->held;
        $this->held = null;
        $choices = [];
        foreach ($plan['groups'] as $g) {
            $choices[$g['id']] = $g['selected'];
        }
        try {
            $this->events->patch($this->userId, $target, $in + ['plan' => ['hash' => $plan['hash'], 'choices' => $choices]]);
        } catch (HttpError $e) {
            $want = (string) ($step['refused'] ?? 'series_plan_changed');
            $this->check('s-stale', $e->status === 409 && $e->errorCode === $want, "step {$this->step}: the commit with the first plan's hash is 409 $want", ['got' => $e->status . ' ' . $e->errorCode]);
            $this->same('s-stale', $s0, $this->snapshot(), "step {$this->step}: the refused commit writes nothing");
            $carried = $e->extra['plan'] ?? null;
            $this->check('s-stale', is_array($carried) && isset($carried['hash']), "step {$this->step}: the refusal carries the plan", []);
            if (is_array($carried) && isset($carried['hash'])) {
                $now = $this->events->previewPatch($this->userId, $target, $in + ['plan' => ['choices' => $choices]]);
                $this->check('s-stale', $carried['hash'] !== $plan['hash'], "step {$this->step}: the plan it carries is not the first one", []);
                $this->check('s-stale', $carried['hash'] === $now['hash'], "step {$this->step}: the plan it carries is the series' plan as it is now", ['carried' => $carried['hash'], 'now' => $now['hash']]);
            }
            return;
        }
        $this->fail('s-stale', "step {$this->step}: the commit with the first plan's hash went through after the series changed", []);
        try {
            $this->undo->undoLatest($this->userId);
        } catch (\Throwable $e) {
            $this->fail('s-stale', "step {$this->step}: the stale commit could not be undone: " . ($e instanceof HttpError ? $e->errorCode : get_class($e)), []);
        }
    }

    /**
     * Undo: the latest change (`POST /undo`, $k null: the newest Activity entry not undone, whichever step made it), or a
     * step's own entries by id (`POST /activity/:id/undo`, newest first, as the app's toast undoes a write's entries).
     * Undone, every row the step changed is as it was before it, column by column, and every other row as it is now; a
     * step undone after everything since it was undone is therefore every row exactly as before it (`s-undo`). Stated
     * refused (undo_conflict), it writes nothing, says why, and leaves the step's entries to undo later.
     */
    private function stepUndo(array $done, ?int $k, array $step): void
    {
        if ($k === null) {
            $newest = null;
            foreach ($done as $at => $d) {
                foreach ($d['entries'] as $e) {
                    if (!$this->entryUndone($e) && ($newest === null || $e > $newest[1])) {
                        $newest = [$at, $e];
                    }
                }
            }
            if ($newest === null) {
                $this->fail('s-undo', "step {$this->step}: the sequence undoes more steps than it made", []);
                return;
            }
            $k = $newest[0];
        }
        $d = $done[$k] ?? null;
        if ($d === null) {
            $this->fail('s-undo', "step {$this->step}: step $k made no change to undo", []);
            return;
        }
        $s0 = $this->snapshot();
        $want = $step['refused'] ?? null;
        try {
            if ($step['do'] === 'undo') {
                $this->undo->undoLatest($this->userId);
            } else {
                foreach (array_reverse($d['entries']) as $e) {
                    $this->undo->undoById($this->userId, $e);
                }
            }
        } catch (\Throwable $e) {
            $code = $e instanceof HttpError ? $e->errorCode : get_class($e);
            if ($want !== null && $code === $want) {
                $this->same('s-undo', $s0, $this->snapshot(), "step {$this->step}: the refused Undo writes nothing");
                $this->check('s-undo', str_contains($e->getMessage(), 'Sample series') && str_contains($e->getMessage(), 'trip'), "step {$this->step}: the refusal says what the Undo would break, naming the event and the rule", ['message' => $e->getMessage()]);
                $left = array_filter($d['entries'], fn(int $id): bool => !$this->entryUndone($id));
                $this->check('s-undo', count($left) === count($d['entries']), "step {$this->step}: the entry is left to undo later", ['entries' => $d['entries']]);
                return;
            }
            $this->fail('s-undo', "step {$this->step}: Undo failed: $code", ['message' => mb_substr($e->getMessage(), 0, 300)]);
            return;
        }
        if ($want !== null) {
            $this->fail('s-undo', "step {$this->step}: Undo went through where the sequence states it is refused with $want", []);
            return;
        }
        $this->same('s-undo', self::undoneOf($d['s0'], $d['s1'], $s0), self::keyed($this->snapshot()), "step {$this->step}: Undo puts back every row step $k changed as it was before it, the rest as they are");
        $this->check('s-undo', array_filter($d['entries'], fn(int $id): bool => !$this->entryUndone($id)) === [], "step {$this->step}: every entry of step $k is undone", ['entries' => $d['entries']]);
    }

    /** The account's newest Activity entry. */
    private function lastEntry(): int
    {
        return (int) $this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM mutations WHERE user_id = ?', [$this->userId]);
    }

    /** @return list<int> the Activity entries made after $id, oldest first */
    private function entriesSince(int $id): array
    {
        return array_map('intval', array_column($this->db->all('SELECT id FROM mutations WHERE user_id = ? AND id > ? ORDER BY id', [$this->userId, $id]), 'id'));
    }

    private function entryUndone(int $id): bool
    {
        return (int) $this->db->scalar('SELECT undone FROM mutations WHERE id = ?', [$id]) === 1;
    }

    /** A snapshot keyed by each row's identity, so rows compare by what they are, not by their place. */
    private static function keyed(array $snap): array
    {
        $key = [
            'events' => static fn(array $r): string => (string) $r['id'],
            'event_tags' => static fn(array $r): string => $r['event_id'] . ':' . $r['tag_id'],
            'event_people' => static fn(array $r): string => $r['event_id'] . ':' . $r['person_id'],
            'event_links' => static fn(array $r): string => (string) $r['id'],
        ];
        $out = [];
        foreach ($snap as $table => $rows) {
            $out[$table] = [];
            foreach ($rows as $r) {
                $out[$table][$key[$table]($r)] = $r;
            }
            ksort($out[$table]);
        }
        return $out;
    }

    /**
     * The rows an Undo of one step leaves, from the rows before it ($s0), after it ($s1) and now: a row the step made
     * gone, a row it removed back as it was, a row it changed with each column it changed as before it and the rest as
     * now; every other row as now.
     */
    private static function undoneOf(array $s0, array $s1, array $now): array
    {
        [$a, $b, $out] = [self::keyed($s0), self::keyed($s1), self::keyed($now)];
        foreach ($out as $table => $rows) {
            foreach (array_unique(array_merge(array_keys($a[$table] ?? []), array_keys($b[$table] ?? []))) as $k) {
                $r0 = $a[$table][$k] ?? null;
                $r1 = $b[$table][$k] ?? null;
                if ($r0 === $r1) {
                    continue;
                }
                if ($r0 === null) {
                    unset($rows[$k]);
                } elseif ($r1 === null) {
                    $rows[$k] = $r0;
                } elseif (isset($rows[$k])) {
                    foreach ($r0 as $col => $value) {
                        if ($value !== $r1[$col]) {
                            $rows[$k][$col] = $value;
                        }
                    }
                }
            }
            ksort($rows);
            $out[$table] = $rows;
        }
        return $out;
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
