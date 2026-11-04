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
                // A rule outside the drag table keeps its days (c-days): a row stays on its day while the series has it.
                $day = Clock::dayOf((string) $ov['recurrence_instance_utc'], $ctx['tz1']);
                if (!self::isNewDay($after, $ctx, $day)) {
                    $fates[$id] = ($choices['outside'] ?? 'keep') === 'keep' ? 'leaves' : 'goes';
                    continue;
                }
                $fates[$id] = self::ownAfter($id, $before, $edit, $choices, ['key' => $keyOn($day)] + $placedAt) === [] ? 'goes' : 'stays';
                continue;
            }
            $r = $out['rows'][$id] ?? null;
            if ($r === null || $r['inside']) {
                $k = $keyOn($r['img'] ?? null);
                $fates[$id] = $k !== null && self::ownAfter($id, $before, $edit, $choices, ['key' => $k] + $placedAt) === [] ? 'goes' : 'stays';
                continue;
            }
            $fates[$id] = ($r['acted'] && $ctx['special']) || ($choices['outside'] ?? 'keep') === 'keep' ? 'leaves' : 'goes';
        }
        return $fates;
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
        $timeEdit = $edit['sent'] !== null || $edit['rule'] === 'new' || $edit['boundSent'] !== null;
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
                $allowed = array_merge($book, $timeEdit ? ['start_utc', 'end_utc', 'all_day', 'tzid', 'rrule', 'exdates_json'] : [], $edit['trip'] ? ['is_container'] : []);
            } elseif (isset($fates[$id])) {
                $role = 'edited row ' . $fates[$id];
                $allowed = array_merge($book, $timeEdit || $id === $edit['fromRow'] ? self::PLACEMENT_COLUMNS : [], $edit['trip'] ? ['is_container'] : [], $fates[$id] === 'leaves' ? self::DETACH : []);
            } else {
                $role = in_array($id, $this->controls, true) ? ((int) $r0['calendar_id'] === $this->calendarId ? 'control, same calendar' : 'control, another calendar') : 'other row';
                $allowed = $book;
            }
            $this->ran('c-untouched', $role);
            foreach (self::FIELD_COLUMNS as $f => $col) {
                if (isset($fates[$id]) || $id === $master ? $takes($id, $f) : false) {
                    $allowed[] = $col;
                }
            }
            if (isset($fates[$id]) && array_filter($fields, static fn(string $f): bool => $takes($id, $f)) !== []) {
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
            // The own list: placement entries where the edit sent a time, a rule or a bound; a field's entry where the row takes the field.
            if (isset($fates[$id]) && $fates[$id] === 'stays') {
                $l0 = json_decode((string) ($r0['override_fields'] ?? '[]'), true) ?: [];
                $l1 = json_decode((string) ($r1['override_fields'] ?? '[]'), true) ?: [];
                foreach (array_merge(array_diff($l0, $l1), array_diff($l1, $l0)) as $entry) {
                    $ok = in_array($entry, self::PLACEMENT_OWN, true) ? ($timeEdit || $id === $edit['fromRow']) : $takes($id, (string) $entry);
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
        // Insertions: the row created for the day acted on, the event of its own it became; nothing else.
        $createdActed = 0;
        $actedOwn = 0;
        if ($edit['sent'] !== null && $edit['fromRow'] === null && $x->kind !== 'newrule' && $out !== null) {
            if (!$out['actedInside']) {
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
