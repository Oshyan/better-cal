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
     * A rule sent for a series: validated, except that a series whose
     * stored rule the expander can't read (RSCALE, X- parts) takes the sent
     * rule to the model as it is, which refuses anything but its details
     * with its own words (422 series_refused, acceptance case 6) instead of
     * a 400 about the rule's syntax.
     */
    private static function validatedSeriesRule(array $event, string $rrule, bool $wholeSeries): string
    {
        if ($wholeSeries && !empty($event['rrule']) && !Calc::sabreReads((string) $event['rrule'])) {
            try {
                return Recurrence::validateRrule($rrule);
            } catch (\Throwable) {
                return trim($rrule);
            }
        }
        return Recurrence::validateRrule($rrule);
    }

    /** "This and following" and delete following on a rule the expander can't read: refused, saying so. */
    private static function refuseUnreadableSplit(array $event): void
    {
        if (!empty($event['rrule']) && !Calc::sabreReads((string) $event['rrule'])) {
            throw new HttpError('series_refused', Model::REASONS['unreadable'], 422, [
                'plan' => Model::refusedPlan($event, 'unreadable', 'following'),
            ]);
        }
    }

    /**
     * An edit with all events sent to an edited day's own row is the
     * series' edit acting on that day's occurrence (Changing the Series
     * from an Edited Day): its key names the occurrence, and the body goes
     * as it was sent. The model measures every delta from where that
     * occurrence shows, whichever row the request came through, so there is
     * nothing to translate here.
     *
     * @return array{0:array,1:array} the master and the edit for it
     */
    private function routeToMaster(int $userId, array $row, array $in): array
    {
        $master = $this->get($userId, (int) $row['recurrence_parent_id']);
        $key = Time::fromDb((string) $row['recurrence_instance_utc']);
        $in['scope'] = 'all';
        $in['instanceStart'] = (int) $master['all_day'] === 1 ? $key->format('Y-m-d') : Time::iso($key);
        return [$master, $in];
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
        } elseif (!empty($event['recurrence_parent_id']) && (string) ($in['scope'] ?? '') === 'all') {
            // Any other edit with all events from an edited day's own row:
            // the series' edit acting on that day's occurrence.
            [$event, $in] = $this->routeToMaster($userId, $event, $in);
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
            $in['rrule'] = self::validatedSeriesRule($event, (string) $in['rrule'], true);
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
        // Read as the model reads a stored series: from its first shown day.
        $calc = Calc::fromRow($row);
        $rule = $calc->rule;
        $dn = $calc->startDn;
        $clock = (int) $row['all_day'] === 1 ? null : $calc->clock;
        $year = (int) Time::nowUtc()->setTimezone(Time::zone($homeTz ?? $tz))->format('Y');
        // A walk that can't be completed shows no dates and no end, as a
        // rule the expander can't read, never a series that ends.
        try {
            $first = $calc->occurrences(PHP_INT_MAX, 4);
            $next = array_map(static fn(int $d): array => ['start' => Time::iso($calc->instant($d)->setTimezone(Time::zone($tz)))], $first);
            $bound = 'No end';
            if ($calc->bounded()) {
                $all = $calc->all();
                $bound = Words::events(count($all)) . ', ends ' . Words::dayMonth((int) end($all), $year);
            }
        } catch (\BetterCal\Domain\Series\WalkIncomplete) {
            [$next, $bound] = [[], ''];
        }
        $askedDn = Cal::dnOf(Time::fromDb((string) $asked['start_utc'])->setTimezone(Time::zone($tz)));
        $plan = [
            'rule' => ['after' => (string) $row['rrule'], 'words' => Words::rule($rule, $clock)],
            'start' => Time::iso($calc->instant($dn)->setTimezone(Time::zone($tz))),
            'next' => $next,
            'bound' => ['words' => $bound],
        ];
        if ($askedDn !== $dn) {
            // "Starts Tue 2 Mar, the first Tuesday on or after the date you typed" (Creating a Series).
            $first = 'its first day';
            if ($rule->kind === 'weekly' && count($rule->members) === 1 && $rule->interval === 1) {
                $first = 'the first ' . Words::day((int) $rule->members[0]);
            } elseif ($rule->kind === 'monthly' && count($rule->members) === 1 && $rule->members[0]['t'] === 'day' && (int) $rule->members[0]['n'] > 0) {
                $first = 'the first ' . Words::ordinal((int) $rule->members[0]['n']);
            }
            $plan['startWords'] = 'Starts ' . Words::date($dn, $year) . ', ' . $first . ' on or after the date you typed';
        }
        return $plan;
    }

    /**
     * A new series starts on a day of its rule, for every family (Creating
     * a Series): the first occurrence of its rule on or after the start
     * asked for, at the clock asked for. A first day in a skipped hour is
     * stored as an edit stores it (Zones, DST and All-Day Days): from the
     * rule's day before, that day a skip and COUNT one higher. A rule
     * outside the drag table is taken as written.
     */
    private static function seriesStart(array $row): array
    {
        if (empty($row['rrule'])) {
            return [];
        }
        $allDay = (int) $row['all_day'] === 1;
        $tz = $allDay ? 'UTC' : (string) $row['tzid'];
        $start = Time::fromDb((string) $row['start_utc'])->setTimezone(Time::zone($tz));
        $dn = Cal::dnOf($start);
        $rule = Rule::parse((string) $row['rrule'], $dn);
        if (!$rule->supported()) {
            return [];
        }
        $first = $dn;
        if (!$rule->bare) {
            $p = $rule->periodOf($dn);
            $found = null;
            for ($i = 0; $i < 4800 && $found === null; $i++) {
                foreach ($rule->periodDays($p) as [$d]) {
                    if ($d >= $dn) {
                        $found = $d;
                        break;
                    }
                }
                $p = $rule->periodPlus($p, $rule->interval);
            }
            $first = $found ?? $dn;
        }
        $clock = $allDay ? [0, 0, 0] : [(int) $start->format('G'), (int) $start->format('i'), (int) $start->format('s')];
        $length = Recurrence::durationSeconds($row);
        try {
            $calc = new Calc($rule, $first, $tz, $allDay, $clock, $length);
            if ($first === $dn && $calc->anchor === null) {
                return [];
            }
            $at = $calc->storedStart();
        } catch (\BetterCal\Domain\Series\WalkIncomplete) {
            return []; // a walk that can't be completed: the rule as written
        }
        $out = [
            'start_utc' => Time::toDb($at),
            'end_utc' => Time::toDb($allDay ? $at->modify('+' . max(1, (int) round($length / 86400)) . ' days') : $at->modify('+' . $length . ' seconds')),
        ];
        if ($calc->anchor !== null) {
            if ($rule->count !== null) {
                $out['rrule'] = $calc->storedRule()->text();
            }
            $skips = Recurrence::decodeExdates($row['exdates_json'] ?? null);
            $skips[] = Time::toDb($at);
            sort($skips);
            $out['exdates_json'] = json_encode(array_values(array_unique($skips)));
        }
        return $out;
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
            // Where the request puts the occurrence acted on, read against
            // it as it shows (its edited row when it has one): the model
            // measures the deltas from there.
            $occ = Model::occurrence($master, $rows, $edit['acted'] ?? null);
            $f = $this->columnPatch($occ, $timeKeys, null, true);
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
        // The trip flag rides along as a series column (Model::SERIES_COLUMNS): no edited day owns it.
        $content = array_intersect_key($in, array_flip(['title', 'description', 'location', 'url', 'locationLat', 'locationLng', 'locationSource', 'locationProvider', 'status', 'reminders', 'isContainer']));
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
        // What refers to each edited row (its trip, either direction): the
        // day's own, so a row named by a link never goes back to the series.
        $rowLinks = self::linksNaming($this->db, array_map(static fn(array $r): int => (int) $r['id'], $rows));
        $opts = [
            'google' => $google,
            'today' => Time::nowUtc()->setTimezone(Time::zone($this->userTzid($userId)))->format('Y-m-d'),
            'masterTags' => $labels['tags'][$id] ?? [],
            'masterPeople' => $people[$id] ?? [],
            'rowTags' => $rowTags,
            'rowPeople' => $rowPeople,
            'rowLinks' => $rowLinks,
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
        $createdEdited = [];
        $this->db->tx(function () use ($w, $id, $userId, $master, $byId, $in, &$created, &$createdEdited): void {
            $now = Time::nowDb();
            if ($w['master'] !== []) {
                $this->db->update('events', $w['master'] + ['updated_at' => $now], 'id = ?', [$id]);
            }
            // Keys are unique per calendar and uid, and a new key may be one
            // another row holds now (edited days moved by the distance between
            // them, or a day moving onto the key of one leaving the series):
            // every row that leaves the series or its key leaves it first,
            // then the new keys are taken.
            foreach ($w['delete'] as $rid) {
                $this->db->run('DELETE FROM events WHERE id = ?', [$rid]);
            }
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
            // An occurrence the plan makes an edited day (the day acted on,
            // where it keeps a placement of its own): its row, as "this event"
            // makes one, from the series as written, with its tags and people.
            foreach ($w['createEdited'] ?? [] as $cols) {
                $row = $this->copyForChild($after);
                $row['recurrence_parent_id'] = $id;
                $row['rrule'] = null;
                $row['exdates_json'] = null;
                $row['created_via'] = ActivityContext::get();
                $newId = $this->db->insert('events', $this->withOwnColumn(array_merge($row, $cols)));
                $this->db->run($this->db->insertIgnore() . ' INTO event_tags (event_id, tag_id) SELECT ?, tag_id FROM event_tags WHERE event_id = ?', [$newId, $id]);
                $this->db->run($this->db->insertIgnore() . ' INTO event_people (event_id, person_id) SELECT ?, person_id FROM event_people WHERE event_id = ?', [$newId, $id]);
                $createdEdited[] = $newId;
            }
            foreach ($w['tags'] as $target => $names) {
                $this->labels->setEventTags($userId, $target === 'master' ? $id : (int) $target, $names);
            }
            foreach (array_keys($w['people']) as $target) {
                $this->applyPeoplePatch($userId, $target === 'master' ? $id : (int) $target, $in);
            }
            foreach (array_merge($created, $createdEdited) as $newId) {
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
        foreach (array_merge($touchedIds, $created, $createdEdited) as $rid) {
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
            ['events' => $afterRows] + Undo::linksOf($this->db, array_merge([$id], $touchedIds, $created, $createdEdited)),
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
        // After the plan is read (the flag is part of what it compares),
        // before Google is sent anything.
        $this->tripRules($id, $localFields);
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
        // A move within its day is a new time only; a new day at the same clock a date only.
        $out = OwnFields::changedFields($before, $after);
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
     * The event_links naming each row, as a trip or as one of a trip's
     * stops, by row id.
     *
     * @param list<int> $ids
     * @return array<int,list<string>>
     */
    private static function linksNaming(\BetterCal\Infra\Db $db, array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            [$in, $params] = \BetterCal\Infra\Db::in($chunk);
            foreach ($db->all("SELECT id, container_id, event_id FROM event_links WHERE event_id IN $in OR container_id IN $in ORDER BY id", array_merge($params, $params)) as $l) {
                foreach ([(int) $l['container_id'], (int) $l['event_id']] as $rid) {
                    if (in_array($rid, $chunk, true)) {
                        $out[$rid][] = (string) $l['id'];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * "Use series value": the fields named go back to the series' values
     * and are no longer the day's own. A day left with nothing of its own,
     * no field and no trip, is an occurrence again, and its row goes (its
     * key in Undo).
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
            $rowAllDay = (int) $row['all_day'] === 1;
            $rowTz = Time::zone($rowAllDay ? 'UTC' : (string) $row['tzid']);
            $allDay = in_array('allDay', $left, true) ? $rowAllDay : (int) $master['all_day'] === 1;
            if ($allDay !== $rowAllDay) {
                // A day given the series' all-day setting leaves its own
                // clock and length behind: they belonged to the other kind.
                $left = array_values(array_diff($left, ['time', 'length']));
            }
            $shown = Time::fromDb((string) $row['start_utc'])->setTimezone($rowTz);
            $ownTime = in_array('time', $left, true) && !$allDay;
            $day = in_array('date', $left, true) ? $shown->format('Y-m-d') : $key->setTimezone($tz)->format('Y-m-d');
            // Its own time is its clock in its own zone; otherwise the series' (Own Fields).
            $clock = $allDay ? '00:00:00' : ($ownTime ? $shown->format('H:i:s') : $key->setTimezone($tz)->format('H:i:s'));
            $zone = $allDay ? Time::utc() : ($ownTime ? $rowTz : $tz);
            // The instant it came from stays that instant (its own time's, or
            // its key's for the series' time): a repeated hour's wall clock
            // never turns into the other of its two instants.
            [$h, $m, $sec] = array_map('intval', explode(':', $clock));
            $start = Calc::at(Cal::dnOf(new \DateTimeImmutable($day, Time::utc())), [$h, $m, $sec], $zone->getName(), $ownTime ? $shown : $key);
            // The series' length, unless it's the day's own or the day isn't of the series' kind (all-day or timed).
            $length = in_array('length', $left, true) || $allDay !== ((int) $master['all_day'] === 1) ? Recurrence::durationSeconds($row) : $len;
            $set['start_utc'] = Time::toDb($start);
            $set['end_utc'] = Time::toDb($start->modify('+' . $length . ' seconds'));
            $set['all_day'] = $allDay ? 1 : 0;
            $set['tzid'] = in_array('allDay', $left, true) && $allDay ? (string) $row['tzid'] : ($ownTime ? (string) $row['tzid'] : (string) $master['tzid']);
        }
        $id = (int) $row['id'];
        $links = Undo::linksOf($this->db, [$id]);
        // The write rule every writer shares: a row goes only when nothing of
        // its own is left, its trip included (OwnFields::own).
        $gone = OwnFields::own($left, !empty(self::linksNaming($this->db, [$id])[$id])) === [];
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
