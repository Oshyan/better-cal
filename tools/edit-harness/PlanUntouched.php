<?php
// c-untouched (README, The Plan's Data): what a whole-series commit may
// change, by row and column. The series' placement columns, skips and the
// placement entries of own lists may change where the edit sends a time, a
// rule or a bound; content columns, tags and people where the request
// changed the field and the row takes it (by its own list and the chosen
// options); a row leaves or goes only where the oracle says it does. The
// bookkeeping allowlisted by name may change anywhere. Control events in the
// same calendar and in another, and every other row, must not change, and
// nothing outside the set is inserted or deleted.
declare(strict_types=1);

namespace BetterCal\EditHarness;

use BetterCal\Infra\Db;

trait PlanUntouched
{
    /**
     * Bookkeeping a commit may change anywhere, by name (the undo tables and
     * dav_changes are not read). calendars.updated_at moves with synctoken,
     * in the same write (ChangeLog), so it is bookkeeping too.
     */
    private const BOOKKEEPING = ['events' => ['updated_at'], 'calendars' => ['synctoken', 'updated_at']];
    /** What leaving the series writes on a row kept as an event of its own. */
    private const DETACH = ['recurrence_parent_id', 'recurrence_instance_utc', 'uid', 'rrule', 'exdates_json', 'google_event_id', 'override_fields', 'source'];
    private const PLACEMENT_COLUMNS = ['start_utc', 'end_utc', 'all_day', 'tzid', 'recurrence_instance_utc'];
    private const PLACEMENT_OWN = ['date', 'time', 'length', 'allDay'];
    private const FIELD_COLUMNS = ['title' => 'title', 'location' => 'location', 'description' => 'description', 'url' => 'url', 'reminders' => 'reminders_json', 'status' => 'status'];

    /** @var ?array the wide snapshot before a whole-series edit (wide()) */
    private ?array $wide0 = null;
    /** @var list<int> control events: one in the case's calendar, one in another, made before the case's own rows */
    private array $controls = [];

    /** Everything c-untouched compares: this case's rows and the control events, their labels and links, the account's calendars and label names, the newest event id. */
    private function wide(): array
    {
        $events = [];
        foreach ($this->db->all('SELECT * FROM events WHERE user_id = ? AND (id >= ? OR id IN (' . implode(',', array_map('intval', $this->controls ?: [0])) . ')) ORDER BY id', [$this->userId, $this->firstId]) as $r) {
            $events[(int) $r['id']] = $r;
        }
        $out = ['events' => $events, 'event_tags' => [], 'event_people' => [], 'event_links' => [], 'calendars' => [], 'tags' => [], 'people' => []];
        if ($events !== []) {
            [$in, $params] = Db::in(array_keys($events));
            foreach ($this->db->all("SELECT event_id, tag_id FROM event_tags WHERE event_id IN $in", $params) as $r) {
                $out['event_tags'][(int) $r['event_id']][] = (int) $r['tag_id'];
            }
            foreach ($this->db->all("SELECT event_id, person_id FROM event_people WHERE event_id IN $in", $params) as $r) {
                $out['event_people'][(int) $r['event_id']][] = (int) $r['person_id'];
            }
            foreach ($this->db->all("SELECT * FROM event_links WHERE event_id IN $in OR container_id IN $in ORDER BY id", array_merge($params, $params)) as $r) {
                $out['event_links'][(int) $r['id']] = $r;
            }
        }
        foreach (['event_tags', 'event_people'] as $t) {
            foreach ($out[$t] as &$l) {
                sort($l);
            }
            unset($l);
        }
        foreach ($this->db->all('SELECT * FROM calendars WHERE user_id = ? ORDER BY id', [$this->userId]) as $r) {
            $out['calendars'][(int) $r['id']] = $r;
        }
        $out['tags'] = array_map('strval', array_column($this->db->all('SELECT name FROM tags WHERE user_id = ? ORDER BY name', [$this->userId]), 'name'));
        $out['people'] = array_map('strval', array_column($this->db->all('SELECT name FROM people WHERE user_id = ? ORDER BY name', [$this->userId]), 'name'));
        $out['maxId'] = (int) $this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM events');
        return $out;
    }

    /**
     * Where the oracle puts each edited row of a whole-series edit: in the
     * series ('stays'), out of it as an event of its own ('leaves'), or gone
     * ('goes': deleted, or back to the series with nothing of its own).
     *
     * @return array<int,string>
     */
    private function fates(array $before, array $after, array $edit, array $choices, array $ctx): array
    {
        $x = $ctx['x'];
        $out = $ctx['out'];
        $placedAt = $this->placedAt($x, $edit, $choices);
        $keyOn = static fn(?int $img): ?string => $img === null ? null : ($ctx['byDay'][$img] ?? null);
        $fates = [];
        foreach ($before['overrides'] as $id => $ov) {
            if ($out === null) {
                // A rule outside the drag table keeps its days (c-days): a row stays while its key has a new key (newKeyByPlace).
                $k = self::newKeyByPlace($before, $after, $ctx, (string) $ov['recurrence_instance_utc']);
                if ($k === null) {
                    $fates[$id] = ($choices['outside'] ?? 'keep') === 'keep' ? 'leaves' : 'goes';
                    continue;
                }
                $fates[$id] = self::ownAfter($id, $before, $edit, $choices, ['key' => $k] + $placedAt) === [] ? 'goes' : 'stays';
                continue;
            }
            $r = $out['rows'][$id] ?? null;
            if ($r === null || $r['inside']) {
                $k = $keyOn($r['img'] ?? null);
                $fates[$id] = $k !== null && self::ownAfter($id, $before, $edit, $choices, ['key' => $k] + $placedAt) === [] ? 'goes' : 'stays';
                continue;
            }
            // The day acted on leaves on its own where the plan's kept count cuts it off; cut off by a bound the
            // person sent it is a day outside like any other (decisions.md), kept or deleted by the outside choice.
            $fates[$id] = ($r['acted'] && $ctx['special'] && $edit['boundSent'] === null) || ($choices['outside'] ?? 'keep') === 'keep' ? 'leaves' : 'goes';
        }
        return $fates;
    }

    /**
     * The series' placement columns the oracle changes: none for a change of
     * fields alone, the rule for a bound (and its skips where the oracle's differ), and for a change of time, days or
     * pattern the start, rule and skips, the end where the start or the
     * length moves, the all-day flag and zone where they change.
     *
     * @return list<string>
     */
    private function masterPlacement(array $before, Expect $x, ?array $out): array
    {
        // A bound drops the skips past the new end: the skips change where the oracle's differ from those stored.
        $skips = array_map(static fn(string $k): int => Clock::dayOf($k, $before['tz']), $before['exdates']);
        sort($skips);
        if ($x->kind === 'bound' && $out === null) {
            $this->unoracled[] = 'c-untouched: the skips of a bound change to a rule outside the drag table';
        }
        $skipsChange = $out === null || $out['skips'] !== $skips;
        $cols = match ($x->kind) {
            'fields' => [],
            'bound' => $skipsChange ? ['rrule', 'exdates_json'] : ['rrule'],
            default => ['start_utc', 'end_utc', 'rrule', 'exdates_json'],
        };
        if ($x->allDay1 !== $before['allDay']) {
            $cols = array_merge($cols, ['all_day', 'tzid', 'start_utc', 'end_utc']);
        }
        if (!$x->allDay1 && !$before['allDay'] && $x->tz1 !== $before['tz']) {
            $cols[] = 'tzid';
        }
        if ($x->len1 !== $before['len']) {
            $cols[] = 'end_utc';
        }
        return array_values(array_unique($cols));
    }

    /**
     * An edited row's placement columns the oracle changes, and whether its
     * own placement entries may: where it stays, each column the harness's
     * placement of it (its image's key, placeExpected) gives another value
     * than it had; the day acted on as the request put it; a row kept on its
     * own where it shows, none. Where the oracle has no image for it, its
     * placement can't be judged: recorded as unoracled.
     *
     * @return array{0:list<string>,1:bool}
     */
    private function rowPlacement(int $id, string $fate, array $before, array $after, array $edit, array $choices, array $ctx): array
    {
        if ($id === $edit['fromRow'] && $edit['sent'] !== null) {
            return [self::PLACEMENT_COLUMNS, true]; // where the request put it (placedAt), checked by d-acted and c-edited
        }
        if ($fate !== 'stays') {
            return [[], false];
        }
        $row0 = $before['overrides'][$id];
        $img = $ctx['out']['rows'][$id]['img'] ?? null;
        $key = $img === null ? null : self::keyOnDay($after, $ctx, (int) $img);
        if ($ctx['out'] === null || $key === null) {
            $this->unoracled[] = 'c-untouched: an edited row\'s placement where the oracle has no image for it';
            return [self::PLACEMENT_COLUMNS, true];
        }
        [$start, $len, $allDay, $zone] = self::placeExpected($ctx['x'], $before, $row0, $key, (int) $img, ($choices['movedDays'] ?? 'keep') === 'move');
        $end = Clock::db(Clock::plus(Clock::parse($start), $len));
        $cols = [];
        if ($key !== (string) $row0['recurrence_instance_utc']) {
            $cols[] = 'recurrence_instance_utc';
        }
        if ($start !== (string) $row0['start_utc']) {
            $cols[] = 'start_utc';
        }
        if ($end !== (string) $row0['end_utc'] || $start !== (string) $row0['start_utc']) {
            $cols[] = 'end_utc';
        }
        $kind = $allDay !== ((int) $row0['all_day'] === 1);
        if ($kind) {
            $cols = array_merge($cols, ['all_day', 'tzid']);
        } elseif (!$allDay && $zone !== (string) $row0['tzid']) {
            $cols[] = 'tzid';
        }
        $this->ran('c-untouched', 'edited row placement from the oracle');
        // Its own placement entries change only where its kind does (an own clock or length of the other kind doesn't carry over).
        return [$cols, $kind];
    }

    /** The stored series' occurrence key on a day, looked for past the window read. */
    private static function keyOnDay(array $after, array $ctx, int $dn): ?string
    {
        if (isset($ctx['byDay'][$dn])) {
            return $ctx['byDay'][$dn];
        }
        if ($after['through'] === null) {
            return null;
        }
        foreach (Truth::keysFrom($after['row'], Days::date($dn - 1) . ' 00:00:00', 3) as $k) {
            if (Clock::dayOf($k, $ctx['tz1']) === $dn) {
                return $k;
            }
        }
        return null;
    }

    /** The ids of the account's tags or people by name, sorted as wide() lists a row's. */
    private function labelIds(string $table, array $names): array
    {
        if ($names === []) {
            return [];
        }
        [$in, $params] = Db::in(array_values(array_unique(array_map('strval', $names))));
        $ids = array_map('intval', array_column($this->db->all("SELECT id FROM $table WHERE user_id = ? AND name IN $in", array_merge([$this->userId], $params)), 'id'));
        sort($ids);
        return $ids;
    }

    private function untouchedFail(string $what, array $detail): void
    {
        $this->records[] = ['invariant' => 'c-untouched', 'what' => $what, 'detail' => $detail];
    }

    private function checkUntouchedSet(array $before, array $after, array $edit, array $choices, array $ctx): void
    {
        $w0 = $this->wide0;
        $w1 = $this->wide();
        $x = $ctx['x'];
        $out = $ctx['out'];
        $master = (int) $before['row']['id'];
        $fields = array_keys($edit['fields']);
        $fates = $this->fates($before, $after, $edit, $choices, $ctx);
        $takes = function (int $id, string $f) use ($before, $edit, $choices, $master): bool {
            if (!in_array($f, array_keys($edit['fields']), true)) {
                return false;
            }
            return $id === $master || $id === $edit['fromRow'] || !in_array($f, $before['own'][$id] ?? [], true) || ($choices['own' . ucfirst($f)] ?? 'keep') === 'give';
        };
        $book = self::BOOKKEEPING['events'];
        foreach ($w0['events'] as $id => $r0) {
            $r1 = $w1['events'][$id] ?? null;
            if ($id === $master) {
                $role = 'master';
                $allowed = array_merge($book, $this->masterPlacement($before, $x, $out), $edit['trip'] || !empty($edit['untrip']) ? ['is_container'] : []);
            } elseif (isset($fates[$id])) {
                $role = 'edited row ' . $fates[$id];
                [$placed, $ownPlacement] = $this->rowPlacement($id, $fates[$id], $before, $after, $edit, $choices, $ctx);
                $allowed = array_merge($book, $placed, $edit['trip'] || !empty($edit['untrip']) ? ['is_container'] : [], $fates[$id] === 'leaves' ? self::DETACH : []);
            } else {
                $role = in_array($id, $this->controls, true) ? ((int) $r0['calendar_id'] === $this->calendarId ? 'control, same calendar' : 'control, another calendar') : 'other row';
                $allowed = $book;
            }
            $this->ran('c-untouched', $role);
            if (!isset($fates[$id])) {
                $ownPlacement = false;
            }
            foreach (self::FIELD_COLUMNS as $f => $col) {
                if (isset($fates[$id]) || $id === $master ? $takes($id, $f) : false) {
                    $allowed[] = $col;
                }
            }
            // The own list may change where an entry of it may (checked entry by entry below).
            if (isset($fates[$id]) && (($ownPlacement ?? false) || array_filter($fields, static fn(string $f): bool => $takes($id, $f)) !== [])) {
                $allowed[] = 'override_fields';
            }
            if ($r1 === null || $r1['deleted_at'] !== null && $r0['deleted_at'] === null) {
                if (($fates[$id] ?? null) !== 'goes') {
                    $this->untouchedFail('a row the edit has no reason to remove is gone', ['row' => $id, 'role' => $role]);
                }
                continue;
            }
            foreach ($r0 as $col => $v) {
                if ((string) $v !== (string) ($r1[$col] ?? '') && !in_array($col, $allowed, true)) {
                    $this->untouchedFail("a column the edit has no reason to change changed: $col", ['row' => $id, 'role' => $role, 'was' => $v, 'now' => $r1[$col] ?? null]);
                }
            }
            // The own list: placement entries where the oracle changes the row's placement fields (rowPlacement); a field's entry where the row takes the field.
            if (isset($fates[$id]) && $fates[$id] === 'stays') {
                $l0 = json_decode((string) ($r0['override_fields'] ?? '[]'), true) ?: [];
                $l1 = json_decode((string) ($r1['override_fields'] ?? '[]'), true) ?: [];
                foreach (array_merge(array_diff($l0, $l1), array_diff($l1, $l0)) as $entry) {
                    $ok = in_array($entry, self::PLACEMENT_OWN, true) ? ($ownPlacement ?? false) : $takes($id, (string) $entry);
                    if (!$ok) {
                        $this->untouchedFail("an own-list entry the edit has no reason to change changed: $entry", ['row' => $id, 'was' => $l0, 'now' => $l1]);
                    }
                }
            }
            foreach (['event_tags' => 'tags', 'event_people' => 'people'] as $table => $f) {
                if (($w0[$table][$id] ?? []) !== ($w1[$table][$id] ?? []) && !((isset($fates[$id]) || $id === $master) && $takes($id, $f))) {
                    $this->untouchedFail("the $f of a row the edit has no reason to change changed", ['row' => $id, 'role' => $role, 'was' => $w0[$table][$id] ?? [], 'now' => $w1[$table][$id] ?? []]);
                }
            }
        }
        foreach ($w0['event_links'] as $lid => $l) {
            $gone = ($fates[(int) $l['event_id']] ?? null) === 'goes' || ($fates[(int) $l['container_id']] ?? null) === 'goes';
            $this->ran('c-untouched', 'link');
            if (($w1['event_links'][$lid] ?? null) != $l && !$gone) {
                $this->untouchedFail('a link the edit has no reason to change changed', ['link' => $l, 'now' => $w1['event_links'][$lid] ?? null]);
            }
        }
        // A link present only afterwards is an insertion, and no whole-series commit makes a link: the rows it creates
        // (the day acted on's own row, the event of its own it became) are in no trip, and only Trips writes links.
        $this->ran('c-untouched', 'links inserted');
        foreach (array_diff_key($w1['event_links'], $w0['event_links']) as $l) {
            $this->untouchedFail('a link was inserted, and no series edit makes one', ['link' => $l]);
        }
        // Insertions: the row created for the day acted on, the event of its own it became; nothing else.
        $createdActed = 0;
        $actedOwn = 0;
        if ($edit['sent'] !== null && $edit['fromRow'] === null && $x->kind !== 'newrule') {
            // A rule outside the drag table keeps its days: the day acted on is inside while the stored series has its day.
            $inside = $out === null ? self::isNewDay($after, $ctx, $x->landedDn) : $out['actedInside'];
            if (!$inside) {
                $actedOwn = 1;
            } elseif (!$x->allDay1) {
                $k = $ctx['byDay'][$x->landedDn] ?? null;
                if ($k === null && $after['through'] !== null) {
                    foreach (Truth::keysFrom($after['row'], Days::date($x->landedDn - 1) . ' 00:00:00', 3) as $kk) {
                        if (Clock::dayOf($kk, $ctx['tz1']) === $x->landedDn) {
                            $k = $kk;
                        }
                    }
                }
                $createdActed = $k !== null && $k !== $ctx['putAt'] ? 1 : 0;
            }
        }
        $new = ['override' => 0, 'standalone' => 0];
        foreach ($w1['events'] as $id => $r1) {
            if (isset($w0['events'][$id])) {
                continue;
            }
            $this->ran('c-untouched', 'insertion');
            // A created row takes the series' tags and people: the ones the request sent, else the series' own before the edit.
            foreach (['event_tags' => ['tags', 'tags'], 'event_people' => ['people', 'people']] as $table => [$f, $names]) {
                $want = isset($edit['fields'][$f]) ? $this->labelIds($names, $edit['fields'][$f]) : ($w0[$table][$master] ?? []);
                if (($w1[$table][$id] ?? []) !== $want) {
                    $this->untouchedFail("an inserted row's $f are not the series'", ['row' => $id, 'want' => $want, 'now' => $w1[$table][$id] ?? []]);
                }
            }
            if ((int) ($r1['recurrence_parent_id'] ?? 0) === $master) {
                $new['override']++;
            } elseif ($r1['recurrence_parent_id'] === null && $r1['rrule'] === null) {
                $new['standalone']++;
            } else {
                $this->untouchedFail('a row of a kind the edit never makes was inserted', ['row' => $id]);
            }
        }
        if ($new['override'] > $createdActed || $new['standalone'] > $actedOwn) {
            $this->untouchedFail('rows were inserted outside the set (the day acted on\'s own row, or the event of its own it became)', ['inserted' => $new, 'permitted' => ['override' => $createdActed, 'standalone' => $actedOwn]]);
        }
        $this->ran('c-untouched', 'other accounts');
        if ($w1['maxId'] > $w0['maxId']) {
            $foreign = $this->db->scalar('SELECT COUNT(*) FROM events WHERE id > ? AND user_id <> ?', [$w0['maxId'], $this->userId]);
            if ((int) $foreign > 0) {
                $this->untouchedFail('an event of another account was inserted', ['count' => $foreign]);
            }
        }
        foreach ($w0['calendars'] as $cid => $c0) {
            $this->ran('c-untouched', 'calendar');
            $c1 = $w1['calendars'][$cid] ?? null;
            foreach ($c0 as $col => $v) {
                if ($c1 === null || ((string) $v !== (string) ($c1[$col] ?? '') && !in_array($col, self::BOOKKEEPING['calendars'], true))) {
                    $this->untouchedFail("a calendar's $col changed", ['calendar' => $cid, 'was' => $v, 'now' => $c1[$col] ?? null]);
                }
            }
        }
        foreach (array_diff_key($w1['calendars'], $w0['calendars']) as $cid => $c1) {
            $this->untouchedFail('a calendar was inserted', ['calendar' => $cid]);
        }
        $this->ran('c-untouched', 'label names');
        foreach (['tags', 'people'] as $f) {
            $added = array_values(array_diff($w1[$f], $w0[$f]));
            $sentNames = isset($edit['fields'][$f]) ? array_map('strval', $edit['fields'][$f]) : [];
            if (array_diff($w0[$f], $w1[$f]) !== [] || array_diff($added, $sentNames) !== []) {
                $this->untouchedFail("$f names changed beyond the ones the request names", ['was' => $w0[$f], 'now' => $w1[$f]]);
            }
        }
    }
}
