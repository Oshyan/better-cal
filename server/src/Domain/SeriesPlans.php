<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Dav\ChangeLog;
use BetterCal\Domain\Series\Model;
use BetterCal\Domain\Series\OwnFields;
use BetterCal\Domain\Series\Rule;
use BetterCal\Domain\Series\Cal;
use BetterCal\Domain\Series\Calc;
use BetterCal\Domain\Series\Words;
use BetterCal\Http\HttpError;
use BetterCal\Support\Ids;
use BetterCal\Support\Time;

/**
 * Whole-series edits on the series model (1.5.0, docs/design/series-model.md):
 * the plan worked out from the series' rows and the edit, previewed without
 * writing, and committed only when the plan the caller saw is still the
 * plan (its hash) and every choice is named, or when it is `direct`. The
 * rows the plan writes go through Undo::write in one entry. Edited days'
 * own fields (events.override_fields, migration 063) are kept here too.
 *
 * Part of Events; the following, delete and attendance paths stay on their
 * own code until 1.5.1.
 */
trait SeriesPlans
{
    /**
     * Whole-series edits made here without a dialog take their plan's
     * preselected options: "this and following" from the first day until
     * 1.5.1, and an invitation's update accepted in Review.
     */
    private bool $preselected = false;

    /** Run $fn with whole-series edits taking their preselected options. */
    public function withPreselectedPlan(callable $fn): mixed
    {
        $was = $this->preselected;
        $this->preselected = true;
        try {
            return $fn();
        } finally {
            $this->preselected = $was;
        }
    }

    /** Whether events has override_fields (a database before 063 doesn't). */
    private function ownFieldsColumn(): bool
    {
        return OwnFields::hasColumn($this->db);
    }

    /** $cols without override_fields where the database has no such column. */
    private function withOwnColumn(array $cols): array
    {
        if (!$this->ownFieldsColumn()) {
            unset($cols['override_fields']);
        }
        return $cols;
    }

    /**
     * The plan for a whole-series edit, written nowhere (preview: true).
     * The edit is the PATCH body; plan.choices may name options.
     */
    public function previewPatch(int $userId, int $id, array $in): array
    {
        $event = $this->get($userId, $id);
        if (!empty($event['recurrence_parent_id']) && (string) ($in['scope'] ?? '') === 'all' && isset($in['calendarId'])) {
            // An edited day moved to another calendar with all events: the
            // series' move, as patchLocked sends it to the master.
            $instance = Time::iso(Time::fromDb((string) $event['recurrence_instance_utc']));
            $in = ['calendarId' => $in['calendarId'], 'scope' => 'all', 'instanceStart' => $instance] + (isset($in['plan']) ? ['plan' => $in['plan']] : []);
            $event = $this->get($userId, (int) $event['recurrence_parent_id']);
        }
        if (empty($event['rrule']) || !empty($event['recurrence_parent_id']) || (string) ($in['scope'] ?? '') !== 'all') {
            throw HttpError::badRequest('preview is for changes to all events of a repeating series', 'preview_unavailable');
        }
        $google = $event['source'] === 'feed' ? $this->googleCalendarFor((int) $event['calendar_id']) : null;
        if ($event['source'] === 'feed' && $google === null) {
            throw HttpError::forbidden('feed_readonly', 'Feed events are read-only except attendance, tags and reminders');
        }
        $choices = is_array($in['plan']['choices'] ?? null) ? $in['plan']['choices'] : [];
        unset($in['plan'], $in['preview']);
        if (isset($in['rrule']) && $in['rrule'] !== null && $in['rrule'] !== '') {
            $in['rrule'] = Recurrence::validateRrule((string) $in['rrule']);
        }
        [$master, $rows, $edit, $opts] = $this->seriesInput($userId, $event, $in, false, $google !== null);
        return Model::plan($master, $rows, $edit, $choices, $opts)['plan'];
    }

    /**
     * The creation plan for a new series (POST /events with preview): the
     * rule in words, the start on its first day, the first dates, the bound.
     */
    public function previewCreate(int $userId, array $in): array
    {
        if (empty($in['rrule'])) {
            throw HttpError::badRequest('preview is for a new repeating event; send its rrule', 'preview_unavailable');
        }
        $rrule = Recurrence::validateRrule((string) $in['rrule']);
        $tzid = isset($in['tzid']) ? Time::normalizeTzid((string) $in['tzid']) : $this->userTzid($userId);
        $allDay = filter_var($in['allDay'] ?? false, FILTER_VALIDATE_BOOL);
        if ($allDay) {
            $tzid = 'UTC';
        }
        [$startUtc, $endUtc] = $this->parseTimes($in, $allDay, $tzid, null);
        $row = ['rrule' => $rrule, 'start_utc' => $startUtc, 'end_utc' => $endUtc, 'all_day' => $allDay ? 1 : 0, 'tzid' => $tzid];
        $aligned = array_merge($row, self::seriesStart($row));
        return self::creationPlan($aligned, $row, $userId === 0 ? null : $this->userTzid($userId));
    }

    /** The creation plan's words for a row as it will be stored ($asked: as sent). */
    public static function creationPlan(array $row, array $asked, ?string $homeTz = null): array
    {
        $tz = (int) $row['all_day'] === 1 ? 'UTC' : (string) $row['tzid'];
        $start = Time::fromDb((string) $row['start_utc'])->setTimezone(Time::zone($tz));
        $dn = Cal::dnOf($start);
        $rule = Rule::parse((string) $row['rrule'], $dn);
        $clock = (int) $row['all_day'] === 1 ? null : [(int) $start->format('G'), (int) $start->format('i'), (int) $start->format('s')];
        $calc = new Calc($rule, $dn, $tz, (int) $row['all_day'] === 1, $clock ?? [0, 0, 0], Recurrence::durationSeconds($row));
        $year = (int) Time::nowUtc()->setTimezone(Time::zone($homeTz ?? $tz))->format('Y');
        $first = $calc->occurrences(PHP_INT_MAX, 4);
        $next = array_map(static fn(int $d): array => ['start' => Time::iso($calc->instant($d)->setTimezone(Time::zone($tz)))], $first);
        $bound = 'No end';
        if ($calc->bounded()) {
            $all = $calc->all();
            $bound = Words::events(count($all)) . ', ends ' . Words::dayMonth((int) end($all), $year);
        }
        $askedDn = Cal::dnOf(Time::fromDb((string) $asked['start_utc'])->setTimezone(Time::zone($tz)));
        $plan = [
            'rule' => ['after' => (string) $row['rrule'], 'words' => Words::rule($rule, $clock)],
            'start' => Time::iso($start),
            'next' => $next,
            'bound' => ['words' => $bound],
        ];
        if ($askedDn !== $dn) {
            $plan['startWords'] = 'Starts ' . Words::date($dn, $year) . ', the first day it repeats on after the date you typed';
        }
        return $plan;
    }

    /**
     * A new series starts on a day of its rule, for every family (Creating
     * a Series): the first occurrence of its rule on or after the start
     * asked for. A rule outside the drag table is taken as written.
     */
    private static function seriesStart(array $row): array
    {
        if (empty($row['rrule'])) {
            return [];
        }
        $tz = (int) $row['all_day'] === 1 ? 'UTC' : (string) $row['tzid'];
        $start = Time::fromDb((string) $row['start_utc'])->setTimezone(Time::zone($tz));
        $dn = Cal::dnOf($start);
        $rule = Rule::parse((string) $row['rrule'], $dn);
        if (!$rule->supported() || $rule->bare) {
            return [];
        }
        $p = $rule->periodOf($dn);
        $first = null;
        for ($i = 0; $i < 4800 && $first === null; $i++) {
            foreach ($rule->periodDays($p) as [$d]) {
                if ($d >= $dn) {
                    $first = $d;
                    break;
                }
            }
            $p = $rule->periodPlus($p, $rule->interval);
        }
        if ($first === null || $first === $dn) {
            return [];
        }
        $n = $first - $dn;
        return [
            'start_utc' => Time::toDb($start->modify('+' . $n . ' days')),
            'end_utc' => Time::toDb(Time::fromDb((string) $row['end_utc'])->setTimezone(Time::zone($tz))->modify('+' . $n . ' days')),
        ];
    }

    /**
     * The series' rows and the edit, as the model reads them.
     *
     * @return array{0:array,1:list<array>,2:array,3:array}
     */
    private function seriesInput(int $userId, array $event, array $in, bool $lock, bool $google): array
    {
        $suffix = $lock ? $this->db->forUpdate() : '';
        $id = (int) $event['id'];
        $master = $this->db->one('SELECT * FROM events WHERE id = ?' . $suffix, [$id]) ?? $event;
        $rows = $this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id' . $suffix, [$id]);
        $edit = [];
        if (!empty($in['instanceStart'])) {
            $edit['acted'] = $this->requireInstance($in, $master);
        }
        $timeKeys = array_intersect_key($in, array_flip(['start', 'end', 'allDay', 'tzid']));
        if ($timeKeys !== []) {
            $key = $edit['acted'] ?? (string) $master['start_utc'];
            $occ = ['start_utc' => $key, 'end_utc' => Time::toDb(Time::fromDb($key)->add(new \DateInterval('PT' . Recurrence::durationSeconds($master) . 'S')))] + $master;
            $f = $this->columnPatch($occ, $timeKeys);
            $edit['landed'] = [
                'start_utc' => (string) ($f['start_utc'] ?? $occ['start_utc']),
                'end_utc' => (string) ($f['end_utc'] ?? $occ['end_utc']),
                'all_day' => (int) ($f['all_day'] ?? $occ['all_day']),
                'tzid' => (string) ($f['tzid'] ?? $occ['tzid']),
            ];
        }
        if (array_key_exists('rrule', $in)) {
            $edit['rrule'] = ($in['rrule'] === null || $in['rrule'] === '') ? null : (string) $in['rrule'];
        }
        $content = array_intersect_key($in, array_flip(['title', 'description', 'location', 'url', 'locationLat', 'locationLng', 'locationSource', 'locationProvider', 'status', 'reminders']));
        $edit['columns'] = $content === [] ? [] : $this->columnPatch($master, $content);
        $ids = array_merge([$id], array_map(static fn(array $r): int => (int) $r['id'], $rows));
        $labels = $this->labels->forEvents($ids);
        if (array_key_exists('tagNames', $in) && is_array($in['tagNames'])) {
            $names = array_values(array_unique(array_filter(array_map(static fn($n): string => trim((string) $n), $in['tagNames']), static fn(string $n): bool => $n !== '')));
            sort($names);
            $edit['tags'] = $names;
        }
        $peopleOf = function (array $eventIds): array {
            $out = [];
            if ($eventIds !== []) {
                [$inSql, $params] = \BetterCal\Infra\Db::in($eventIds);
                foreach ($this->db->all("SELECT event_id, person_id FROM event_people WHERE event_id IN $inSql", $params) as $r) {
                    $out[(int) $r['event_id']][] = (string) $r['person_id'];
                }
            }
            return $out;
        };
        $people = $peopleOf($ids);
        if ((array_key_exists('personIds', $in) && is_array($in['personIds'])) || (array_key_exists('personNames', $in) && is_array($in['personNames']))) {
            $sent = array_map('strval', is_array($in['personIds'] ?? null) ? $this->labels->ownedPersonIds($userId, $in['personIds']) : []);
            if (is_array($in['personNames'] ?? null)) {
                foreach ($in['personNames'] as $name) {
                    $found = $this->db->scalar('SELECT id FROM people WHERE user_id = ? AND name = ?', [$userId, trim((string) $name)]);
                    $sent[] = $found !== null ? (string) $found : 'new:' . trim((string) $name);
                }
            }
            sort($sent);
            $edit['people'] = array_values(array_unique($sent));
        }
        if (isset($in['calendarId']) && self::isCalendarChange($master, $in)) {
            $edit['calendarId'] = (int) $in['calendarId'];
        }
        $rowTags = [];
        $rowPeople = [];
        foreach ($rows as $r) {
            $rowTags[(int) $r['id']] = $labels['tags'][(int) $r['id']] ?? [];
            $rowPeople[(int) $r['id']] = $people[(int) $r['id']] ?? [];
        }
        $opts = [
            'google' => $google,
            'today' => Time::nowUtc()->setTimezone(Time::zone($this->userTzid($userId)))->format('Y-m-d'),
            'masterTags' => $labels['tags'][$id] ?? [],
            'masterPeople' => $people[$id] ?? [],
            'rowTags' => $rowTags,
            'rowPeople' => $rowPeople,
        ];
        return [$master, $rows, $edit, $opts];
    }

    /**
     * The plan for this commit, checked: refused (422 series_refused), the
     * plan changed since the caller saw it (409 series_plan_changed), or a
     * plan needed and none sent, or a group left out (409
     * series_plan_required). Every error carries the plan.
     */
    private function committedPlan(int $userId, array $event, array $in, bool $google): array
    {
        $sent = $in['plan'] ?? null;
        $choices = is_array($sent['choices'] ?? null) ? $sent['choices'] : [];
        unset($in['plan'], $in['preview']);
        [$master, $rows, $edit, $opts] = $this->seriesInput($userId, $event, $in, true, $google);
        $result = Model::plan($master, $rows, $edit, $choices, $opts);
        $plan = $result['plan'];
        if ($result['refused']) {
            throw new HttpError('series_refused', $plan['refusals'][0]['words'] ?? 'This change can\'t be made to the series.', 422, ['plan' => $plan]);
        }
        foreach ($plan['groups'] as $group) {
            foreach ($group['options'] as $o) {
                if (($choices[$group['id']] ?? null) === $o['id'] && !empty($o['disabled'])) {
                    throw new HttpError('series_refused', (string) ($o['reason'] ?? 'That choice isn\'t available.'), 422, ['plan' => $plan]);
                }
            }
        }
        if (!is_array($sent)) {
            if (!$plan['direct'] && !$this->preselected) {
                throw new HttpError('series_plan_required', 'This change to the series needs its plan confirmed: preview it, then send plan {hash, choices}.', 409, ['plan' => $plan]);
            }
            return $result;
        }
        if ((string) ($sent['hash'] ?? '') !== $plan['hash']) {
            throw new HttpError('series_plan_changed', 'The series changed since this plan was made. Review the new plan and try again.', 409, ['plan' => $plan]);
        }
        if ($result['missing'] !== []) {
            throw new HttpError('series_plan_required', 'Name an option for every group of the plan: ' . implode(', ', $result['missing']) . '.', 409, ['plan' => $plan]);
        }
        return $result;
    }

    /** patchAll on the plan: every row it writes, in one transaction and one Undo entry. */
    private function patchAllPlanned(int $userId, array $event, array $in): void
    {
        $result = $this->committedPlan($userId, $event, $in, false);
        $w = $result['writes'];
        $id = (int) $event['id'];
        $master = $this->db->one('SELECT * FROM events WHERE id = ?', [$id]);
        $rows = $this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id', [$id]);
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $touchedIds = array_values(array_unique(array_merge(array_keys($w['overrides']), array_keys($w['detach']), $w['delete'],
            array_filter(array_keys($w['tags']), 'is_int'), array_filter(array_keys($w['people']), 'is_int'))));
        $beforeRows = [$master];
        foreach ($touchedIds as $rid) {
            if (isset($byId[$rid])) {
                $beforeRows[] = $byId[$rid];
            }
        }
        $beforeLinks = Undo::linksOf($this->db, array_merge([$id], $touchedIds));
        $created = [];
        $this->db->tx(function () use ($w, $id, $userId, $master, $byId, $in, &$created): void {
            $now = Time::nowDb();
            if ($w['master'] !== []) {
                $this->db->update('events', $w['master'] + ['updated_at' => $now], 'id = ?', [$id]);
            }
            // Edited days moved by the distance between them land on each
            // other's old keys (unique per calendar and uid): they all leave
            // their keys first, then take the new ones.
            $park = [];
            foreach ($w['overrides'] as $rid => $cols) {
                if (array_key_exists('recurrence_instance_utc', $cols) || isset($cols['calendar_id'])) {
                    $park[] = $rid;
                }
            }
            $park = array_merge($park, array_keys($w['detach']));
            self::parkInstanceKeys($this->db, $park);
            foreach ($w['detach'] as $rid => $cols) {
                $this->db->update('events', $this->withOwnColumn([
                    'recurrence_parent_id' => null, 'recurrence_instance_utc' => null, 'uid' => Ids::ulid(), 'rrule' => null,
                    'exdates_json' => null, 'google_event_id' => null, 'override_fields' => null, 'source' => 'local', 'updated_at' => $now,
                ] + $cols), 'id = ?', [$rid]);
            }
            foreach ($w['overrides'] as $rid => $cols) {
                $cols = $this->withOwnColumn($cols);
                if (!array_key_exists('recurrence_instance_utc', $cols) && in_array($rid, $park, true)) {
                    $cols['recurrence_instance_utc'] = $byId[$rid]['recurrence_instance_utc'];
                }
                $this->db->update('events', $cols + ['updated_at' => $now], 'id = ?', [$rid]);
            }
            foreach ($w['delete'] as $rid) {
                $this->db->run('DELETE FROM events WHERE id = ?', [$rid]);
            }
            $after = $this->db->one('SELECT * FROM events WHERE id = ?', [$id]);
            foreach ($w['create'] as $placed) {
                $row = $this->copyForChild($after);
                $row['uid'] = Ids::ulid();
                $row['recurrence_parent_id'] = null;
                $row['recurrence_instance_utc'] = null;
                $row['rrule'] = null;
                $row['exdates_json'] = null;
                $row['google_event_id'] = null;
                $row['override_fields'] = null;
                $row['created_via'] = ActivityContext::get();
                $newId = $this->db->insert('events', $this->withOwnColumn(array_merge($row, $placed)));
                $this->db->run($this->db->insertIgnore() . ' INTO event_tags (event_id, tag_id) SELECT ?, tag_id FROM event_tags WHERE event_id = ?', [$newId, $id]);
                $this->db->run($this->db->insertIgnore() . ' INTO event_people (event_id, person_id) SELECT ?, person_id FROM event_people WHERE event_id = ?', [$newId, $id]);
                $created[] = $newId;
            }
            foreach ($w['tags'] as $target => $names) {
                $this->labels->setEventTags($userId, $target === 'master' ? $id : (int) $target, $names);
            }
            foreach (array_keys($w['people']) as $target) {
                $this->applyPeoplePatch($userId, $target === 'master' ? $id : (int) $target, $in);
            }
            foreach ($created as $newId) {
                // A created row takes the series' new tags and people too.
                if (isset($w['tags']['master'])) {
                    $this->labels->setEventTags($userId, $newId, $w['tags']['master']);
                }
                if (isset($w['people']['master'])) {
                    $this->applyPeoplePatch($userId, $newId, $in);
                }
            }
        });
        $afterMaster = $this->get($userId, $id);
        $afterRows = [$afterMaster];
        foreach (array_merge($touchedIds, $created) as $rid) {
            $row = $this->db->one('SELECT * FROM events WHERE id = ?', [$rid]);
            if ($row !== null) {
                $afterRows[] = $row;
            }
        }
        $this->undo->record(
            $userId,
            'event',
            $id,
            'update',
            ['events' => $beforeRows] + $beforeLinks,
            ['events' => $afterRows] + Undo::linksOf($this->db, array_merge([$id], $touchedIds, $created)),
            mb_substr((string) $result['plan']['summary'], 0, 300)
        );
        ChangeLog::recordUpdate($this->db, $master, $afterMaster);
        foreach ($w['detach'] + array_flip($created) as $rid => $_) {
            $row = $this->db->one('SELECT calendar_id, uid FROM events WHERE id = ?', [(int) $rid]);
            if ($row !== null) {
                ChangeLog::record($this->db, (int) $row['calendar_id'], (string) $row['uid'], ChangeLog::OP_ADD);
            }
        }
    }

    /**
     * A Google series for all events, until 1.5.2: only edits that keep the
     * rule text (the plan refuses the rest), sent as the master alone; what
     * Google does with edited days is read back by the next sync.
     */
    private function googlePatchAllPlanned(int $userId, array $calendar, array $event, array $in, ?RequestAuthority $authority = null): void
    {
        $result = $this->committedPlan($userId, $event, $in, true);
        $id = (int) $event['id'];
        unset($in['plan'], $in['preview']);
        $fields = $result['writes']['master'];
        unset($fields['exdates_json']);
        [$googleFields, $localFields] = self::splitGoogleFields($fields);
        $resp = null;
        if ($googleFields !== []) {
            $googleId = self::requireGoogleId($event);
            $resp = $this->google()->patch($calendar, $googleId, GoogleWriter::body(array_merge($event, $googleFields)), $this->googleBeforeSend($authority));
        }
        $this->googleCommit($authority, function () use ($calendar, $resp, $id, $userId, $localFields, $in): void {
            if ($resp !== null) {
                $this->google()->apply($calendar, [$resp]);
            }
            if ($localFields !== []) {
                $this->db->update('events', $localFields + ['updated_at' => Time::nowDb()], 'id = ?', [$id]);
            }
            if (array_key_exists('tagNames', $in) && is_array($in['tagNames'])) {
                $this->labels->setEventTags($userId, $id, $in['tagNames']);
            }
            $this->applyPeoplePatch($userId, $id, $in);
        });
        if ($googleFields !== []) {
            $this->googleJournal($userId, "Updated '" . (string) $event['title'] . "'", $calendar, $id);
        }
    }

    // ---- own fields for "this event" edits -----------------------------------

    /**
     * The fields a "this event" edit changed on a row, as own fields: what
     * differs between the row before ($before; for a new row, the master's
     * values at its occurrence) and after, plus tags and people when sent.
     *
     * @return list<string>
     */
    private static function changedOwnFields(array $before, array $after, array $in): array
    {
        $out = OwnFields::changedFields($before, $after);
        // A move within its day is a new time only; a new day is a date.
        if (in_array('date', $out, true) && in_array('time', $out, true)) {
            $tzB = Time::zone((int) $before['all_day'] === 1 ? 'UTC' : (string) $before['tzid']);
            $tzA = Time::zone((int) $after['all_day'] === 1 ? 'UTC' : (string) $after['tzid']);
            $b = Time::fromDb((string) $before['start_utc'])->setTimezone($tzB);
            $a = Time::fromDb((string) $after['start_utc'])->setTimezone($tzA);
            if ($b->format('Y-m-d') === $a->format('Y-m-d')) {
                $out = array_values(array_diff($out, ['date']));
            }
            if ($b->format('H:i:s') === $a->format('H:i:s') && (string) $before['tzid'] === (string) $after['tzid']) {
                $out = array_values(array_diff($out, ['time']));
            }
        }
        if (in_array('length', $out, true)) {
            $len = static fn(array $r): int => Time::fromDb((string) $r['end_utc'])->getTimestamp() - Time::fromDb((string) $r['start_utc'])->getTimestamp();
            if ($len($before) === $len($after)) {
                $out = array_values(array_diff($out, ['length']));
            }
        }
        // A content field sent is the day's own, even typed equal to the
        // series' value: the person chose it (the editor sends only what changed).
        foreach (['title' => 'title', 'location' => 'location', 'description' => 'description', 'url' => 'url', 'reminders' => 'reminders', 'status' => 'status', 'icon' => 'icon'] as $key => $field) {
            if (array_key_exists($key, $in)) {
                $out[] = $field;
            }
        }
        if (array_key_exists('tagNames', $in) && is_array($in['tagNames'])) {
            $out[] = 'tags';
        }
        if ((array_key_exists('personIds', $in) && is_array($in['personIds'])) || (array_key_exists('personNames', $in) && is_array($in['personNames']))) {
            $out[] = 'people';
        }
        return OwnFields::normalize($out);
    }

    /** A row's own list after a "this event" edit added $fields to it. */
    private function ownAfterThis(array $row, array $master, array $fields): ?string
    {
        if (!$this->ownFieldsColumn()) {
            return null;
        }
        $known = OwnFields::decode($row['override_fields'] ?? null);
        if ($known === null && !empty($row['id'])) {
            $known = OwnFields::infer($row, $master);
        }
        return OwnFields::encode(array_merge($known ?? [], $fields));
    }

    /**
     * "Use series value": the fields named go back to the series' values
     * and are no longer the day's own. A day left with none of its own is
     * an occurrence again, and its row goes (its key in Undo).
     */
    private function useSeriesValues(int $userId, array $master, array $row, array $fields): void
    {
        $fields = OwnFields::normalize(array_map('strval', $fields));
        $own = OwnFields::decode($row['override_fields'] ?? null) ?? OwnFields::infer($row, $master);
        $left = array_values(array_diff($own, $fields));
        $set = [];
        foreach ($fields as $field) {
            foreach (OwnFields::COLUMNS[$field] ?? [] as $col) {
                if (array_key_exists($col, $master)) {
                    $set[$col] = $master[$col];
                }
            }
        }
        $key = Time::fromDb((string) $row['recurrence_instance_utc']);
        $len = Recurrence::durationSeconds($master);
        if (array_intersect($fields, ['date', 'time', 'length', 'allDay']) !== []) {
            $tz = Time::zone((int) $master['all_day'] === 1 ? 'UTC' : (string) $master['tzid']);
            $rowTz = Time::zone((int) $row['all_day'] === 1 ? 'UTC' : (string) $row['tzid']);
            $shown = Time::fromDb((string) $row['start_utc'])->setTimezone($rowTz);
            $day = in_array('date', $left, true) ? $shown->format('Y-m-d') : $key->setTimezone($tz)->format('Y-m-d');
            $clock = in_array('time', $left, true) ? $shown->format('H:i:s') : $key->setTimezone($tz)->format('H:i:s');
            $zone = in_array('time', $left, true) ? $rowTz : $tz;
            $start = new \DateTimeImmutable($day . ' ' . $clock, $zone);
            $length = in_array('length', $left, true) ? Recurrence::durationSeconds($row) : $len;
            $set['start_utc'] = Time::toDb($start);
            $set['end_utc'] = Time::toDb($start->modify('+' . $length . ' seconds'));
            if (!in_array('allDay', $left, true)) {
                $set['all_day'] = (int) $master['all_day'];
                $set['tzid'] = (string) $master['tzid'];
            }
        }
        $id = (int) $row['id'];
        $links = Undo::linksOf($this->db, [$id]);
        $gone = $left === [];
        $this->db->tx(function () use ($id, $set, $left, $gone, $fields, $master, $userId): void {
            if ($gone) {
                $this->db->run('DELETE FROM events WHERE id = ?', [$id]);
                return;
            }
            $this->db->update('events', $this->withOwnColumn($set + ['override_fields' => OwnFields::encode($left), 'updated_at' => Time::nowDb()]), 'id = ?', [$id]);
            if (in_array('tags', $fields, true)) {
                $this->db->run('DELETE FROM event_tags WHERE event_id = ?', [$id]);
                $this->db->run($this->db->insertIgnore() . ' INTO event_tags (event_id, tag_id) SELECT ?, tag_id FROM event_tags WHERE event_id = ?', [$id, (int) $master['id']]);
            }
            if (in_array('people', $fields, true)) {
                $this->db->run('DELETE FROM event_people WHERE event_id = ?', [$id]);
                $this->db->run($this->db->insertIgnore() . ' INTO event_people (event_id, person_id) SELECT ?, person_id FROM event_people WHERE event_id = ?', [$id, (int) $master['id']]);
            }
            unset($userId);
        });
        $after = $gone ? null : $this->db->one('SELECT * FROM events WHERE id = ?', [$id]);
        $this->undo->record($userId, 'event', $id, 'update', ['events' => [$row]] + $links, ['events' => $after === null ? [] : [$after]] + ($after === null ? [] : Undo::linksOf($this->db, [$id])),
            "Used the series' " . Words::andList(array_map(static fn(string $f): string => $f === 'location' ? 'place' : $f, $fields)) . " for '" . (string) $row['title'] . "'");
        ChangeLog::record($this->db, (int) $master['calendar_id'], (string) $master['uid'], ChangeLog::OP_MODIFY);
    }
}
