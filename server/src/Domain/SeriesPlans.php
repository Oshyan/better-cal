<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Dav\ChangeLog;
use BetterCal\Domain\Series\Model;
use BetterCal\Domain\Series\OwnFields;
use BetterCal\Domain\Series\Rule;
use BetterCal\Domain\Series\Split;
use BetterCal\Domain\Series\Cal;
use BetterCal\Domain\Series\Calc;
use BetterCal\Domain\Series\Wall;
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
 * Part of Events. "This and following", delete following and an answer
 * from a day on are splits on the model (Series\Split, 1.5.1): the same
 * preview and commit, their plan the old part's new bound with the part
 * from the day acted on planned as a whole series.
 */
trait SeriesPlans
{
    /**
     * Whole-series edits made here without a dialog take their plan's
     * preselected options: an invitation's update accepted in Review.
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
        if ($wholeSeries && !empty($event['rrule']) && !Calc::reads((string) $event['rrule'])) {
            // A stored rule the expander can't read keeps its text for a field
            // change (Imported Rules: field changes only); validateRrule's one
            // refusal is HttpError, anything else is a defect.
            try {
                return Recurrence::validateRrule($rrule);
            } catch (HttpError) {
                return trim($rrule);
            }
        }
        return Recurrence::validateRrule($rrule);
    }

    /** "This and following" and delete following on a rule the expander can't read: refused, saying so. */
    private static function refuseUnreadableSplit(array $event): void
    {
        if (!empty($event['rrule']) && !Calc::reads((string) $event['rrule'])) {
            throw new HttpError('series_refused', Model::REASONS['unreadable'], 422, [
                'plan' => Model::refusedPlan($event, 'unreadable', 'following'),
            ]);
        }
    }

    /**
     * An edit with all events (or this and following) sent to an edited
     * day's own row is the series' edit acting on that day's occurrence
     * (Changing the Series from an Edited Day): its key names the
     * occurrence, and the body goes as it was sent. The model measures
     * every delta from where that occurrence shows, whichever row the
     * request came through, so there is nothing to translate here.
     *
     * @return array{0:array,1:array} the master and the edit for it
     */
    private function routeToMaster(int $userId, array $row, array $in): array
    {
        $master = $this->get($userId, (int) $row['recurrence_parent_id']);
        $key = Time::fromDb((string) $row['recurrence_instance_utc']);
        $in['scope'] = (string) ($in['scope'] ?? 'all') === 'following' ? 'following' : 'all';
        $in['instanceStart'] = (int) $master['all_day'] === 1 ? $key->format('Y-m-d') : Time::iso($key);
        return [$master, $in];
    }

    /**
     * A change with all events, or this and following, sent to an edited
     * day's own row is the series' change at that day's key, whatever day
     * the request names: the one routing every series change from an
     * edited day takes (routeToMaster), for a delete and an answer as for
     * an edit, preview and commit alike. Any other row or scope ("this", or
     * none, which is that day) comes back as it was sent.
     *
     * @return array{0:array,1:?string} the row the change acts on and its instanceStart
     */
    private function seriesFrom(int $userId, array $event, ?string $scope, ?string $instanceStart): array
    {
        if (!in_array($scope, ['all', 'following'], true) || empty($event['recurrence_parent_id'])) {
            return [$event, $instanceStart];
        }
        [$master, $in] = $this->routeToMaster($userId, $event, ['scope' => $scope]);
        return [$master, (string) $in['instanceStart']];
    }

    /**
     * The plan for a whole-series edit, written nowhere (preview: true).
     * The edit is the PATCH body; plan.choices may name options.
     */
    public function previewPatch(int $userId, int $id, array $in): array
    {
        $event = $this->get($userId, $id);
        $in = $this->typedTimes($userId, $in, (string) $event['tzid']);
        if (!empty($event['recurrence_parent_id']) && (string) ($in['scope'] ?? '') === 'all' && isset($in['calendarId'])) {
            // An edited day moved to another calendar with all events: the
            // series' move, as patchLocked sends it to the master.
            $instance = Time::iso(Time::fromDb((string) $event['recurrence_instance_utc']));
            $in = ['calendarId' => $in['calendarId'], 'scope' => 'all', 'instanceStart' => $instance] + (isset($in['plan']) ? ['plan' => $in['plan']] : []);
            $event = $this->get($userId, (int) $event['recurrence_parent_id']);
        } elseif (!empty($event['recurrence_parent_id']) && in_array((string) ($in['scope'] ?? ''), ['all', 'following'], true)) {
            // Any other edit with all events, or this and following, from an
            // edited day's own row: the series' edit acting on that day's occurrence.
            [$event, $in] = $this->routeToMaster($userId, $event, $in);
        }
        $scope = (string) ($in['scope'] ?? '');
        if (empty($event['rrule']) || !empty($event['recurrence_parent_id']) || !in_array($scope, ['all', 'following'], true)) {
            throw HttpError::badRequest('preview is for changes to all events, or this and following, of a repeating series', 'preview_unavailable');
        }
        $google = $event['source'] === 'feed' ? $this->googleCalendarFor((int) $event['calendar_id']) : null;
        if ($event['source'] === 'feed' && $google === null) {
            throw HttpError::forbidden('feed_readonly', 'Feed events are read-only except attendance, tags and reminders');
        }
        if (isset($in['rrule']) && $in['rrule'] !== null && $in['rrule'] !== '') {
            $in['rrule'] = self::validatedSeriesRule($event, (string) $in['rrule'], $scope === 'all');
        }
        if ($scope === 'following') {
            return $this->previewSplit($userId, $event, $in, Split::EDIT);
        }
        $choices = is_array($in['plan']['choices'] ?? null) ? $in['plan']['choices'] : [];
        unset($in['plan'], $in['preview']);
        [$master, $rows, $edit, $opts] = $this->seriesInput($userId, $event, $in, false, $google !== null);
        return Model::plan($master, $rows, $edit, $choices, $opts)['plan'];
    }

    /** The plan of delete following (DELETE with preview), written nowhere. */
    public function previewDelete(int $userId, int $id, ?string $scope, ?string $instanceStart, array $choices = []): array
    {
        [$event, $instanceStart] = $this->seriesFrom($userId, $this->get($userId, $id), $scope, $instanceStart);
        if ($scope !== 'following' || empty($event['rrule']) || !empty($event['recurrence_parent_id'])) {
            throw HttpError::badRequest('preview is for deleting this and following events of a repeating series', 'preview_unavailable');
        }
        return $this->previewSplit($userId, $event, ['instanceStart' => $instanceStart, 'plan' => ['choices' => $choices]], Split::DELETE);
    }

    /** The plan of an answer from a day on (attendance with preview), written nowhere. */
    public function previewAttendance(int $userId, int $id, string $attendance, ?string $scope, ?string $instanceStart, array $choices = []): array
    {
        unset($attendance);
        [$event, $instanceStart] = $this->seriesFrom($userId, $this->get($userId, $id), $scope, $instanceStart);
        if ($scope !== 'following' || empty($event['rrule']) || !empty($event['recurrence_parent_id'])) {
            throw HttpError::badRequest('preview is for an answer from a day on in a repeating series', 'preview_unavailable');
        }
        return $this->previewSplit($userId, $event, ['instanceStart' => $instanceStart, 'plan' => ['choices' => $choices]], Split::ANSWER);
    }

    /**
     * A split's plan, written nowhere. From the series' first day the
     * change is the whole series': an edit's plan is the whole series'
     * plan; a delete or an answer from there is delete all or the answer
     * for all events, which need no plan (`whole: true, direct: true`).
     */
    private function previewSplit(int $userId, array $event, array $in, string $mode): array
    {
        $google = $event['source'] === 'feed' ? $this->googleCalendarFor((int) $event['calendar_id']) : null;
        $choices = is_array($in['plan']['choices'] ?? null) ? $in['plan']['choices'] : [];
        unset($in['plan'], $in['preview']);
        [$master, $rows, $edit, $opts] = $this->seriesInput($userId, $event, $in, false, $google !== null);
        $result = Split::plan($master, $rows, $edit, $choices, $opts, $mode);
        if (!empty($result['whole'])) {
            if ($mode === Split::EDIT) {
                return Model::plan($master, $rows, $edit, $choices, $opts)['plan'];
            }
            return ['hash' => null, 'scope' => 'all', 'action' => $mode, 'whole' => true, 'direct' => true, 'groups' => [], 'days' => [], 'refusals' => [], 'notes' => [], 'next' => [], 'nextMore' => false];
        }
        return $result['plan'];
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
        $in = $this->typedTimes($userId, $in, null);
        $tzid = isset($in['tzid']) ? Time::normalizeTzid((string) $in['tzid']) : $this->userTzid($userId);
        $allDay = filter_var($in['allDay'] ?? false, FILTER_VALIDATE_BOOL);
        if ($allDay) {
            $tzid = 'UTC';
        }
        [$startUtc, $endUtc] = $this->parseTimes($in, $allDay, $tzid, null);
        $row = ['rrule' => $rrule, 'start_utc' => $startUtc, 'end_utc' => $endUtc, 'all_day' => $allDay ? 1 : 0, 'tzid' => $tzid];
        $aligned = array_merge($row, self::seriesStart($row, self::typedClock($in, $tzid, $allDay)));
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
     * outside the drag table is taken as written. $typedClock is the clock
     * the person typed (typedClock()), which a start in a skipped hour has
     * and its instant can't hold.
     *
     * @param array{0:int,1:int,2:int}|null $typedClock
     */
    private static function seriesStart(array $row, ?array $typedClock = null): array
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
            // Through the rule's cycle (Rule::cycle); with no day in it the
            // series is its start alone (the start is the first occurrence).
            for ($i = 0, $n = $rule->cycle(); $i < $n && $found === null; $i++) {
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
        $clock = $allDay ? [0, 0, 0] : ($typedClock ?? [(int) $start->format('G'), (int) $start->format('i'), (int) $start->format('s')]);
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
     * A request's typed clock (api-contract.md, Writing Events): `typed:
     * {start, end, tz}` are the wall clocks the person typed, with no
     * offset, in the zone they were typed in. The start is the instant of
     * its clock by the one wall-clock rule (Calc::at: a skipped clock is the
     * clock plus the gap, a repeated one its first instant), and its clock
     * is kept beside it as `typed.clock`, since 02:30 on the spring change
     * day is a clock its instant (03:30) can't hold. The end is its clock
     * moved by the push the gap gave the start, then read by the same rule
     * (Wall::typedEnd), so 02:30 to 03:30 there is an hour, 03:30 to 04:30,
     * and an end at or before the start on the clocks is refused. An instant
     * the request gives beside the clocks (`start`, `end`) stays that instant
     * where it is one the clock names, as any instant given does (Calc::at):
     * a two-hour event dragged to end in the hour the fall change repeats
     * names the later 02:30, and keeps its two hours. `tz` defaults to the
     * request's `tzid`, then the event's zone ($eventTz), then the person's.
     */
    private function typedTimes(int $userId, array $in, ?string $eventTz): array
    {
        if (!is_array($in['typed'] ?? null)) {
            unset($in['typed']);
            return $in;
        }
        $t = $in['typed'];
        $wall = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/';
        if (!is_string($t['start'] ?? null) || preg_match($wall, $t['start'], $s) !== 1
            || (isset($t['end']) && (!is_string($t['end']) || preg_match($wall, $t['end'], $e) !== 1))) {
            throw HttpError::badRequest('typed.start and typed.end are wall clocks, YYYY-MM-DDTHH:MM[:SS] with no offset', 'invalid_typed');
        }
        $tz = Time::normalizeTzid((string) ($t['tz'] ?? $in['tzid'] ?? $eventTz ?? $this->userTzid($userId)));
        $zone = Time::zone($tz);
        $asUtc = static fn(array $m): int => Wall::asUtc(Cal::dn((int) $m[1], (int) $m[2], (int) $m[3]), [(int) $m[4], (int) $m[5], (int) ($m[6] ?? 0)]);
        $given = static function (mixed $iso): ?\DateTimeImmutable {
            try {
                return is_string($iso) && $iso !== '' ? Time::parseIso($iso) : null;
            } catch (\Throwable) {
                return null;
            }
        };
        $start = Calc::at(Cal::dn((int) $s[1], (int) $s[2], (int) $s[3]), [(int) $s[4], (int) $s[5], (int) ($s[6] ?? 0)], $tz, $given($in['start'] ?? null));
        $in['start'] = Time::iso($start->setTimezone($zone));
        if (isset($e)) {
            $end = Wall::typedEnd($start, $asUtc($s), $asUtc($e), $zone, $given($in['end'] ?? null));
            if ($end === null) {
                throw HttpError::badRequest('end must be after start');
            }
            $in['end'] = Time::iso($end->setTimezone($zone));
        }
        $in['typed'] = ['clock' => [(int) $s[4], (int) $s[5], (int) ($s[6] ?? 0)], 'tz' => $tz];
        return $in;
    }

    /** The typed clock of a request for a series in $tz, or null: a clock typed in another zone is its instant there. */
    private static function typedClock(array $in, string $tz, bool $allDay): ?array
    {
        $t = $in['typed'] ?? null;
        return !$allDay && is_array($t) && is_array($t['clock'] ?? null) && Time::normalizeTzid((string) $t['tz']) === Time::normalizeTzid($tz) ? $t['clock'] : null;
    }

    /**
     * The rule as the API says it: its COUNT the events the series shows.
     * A series whose start is stored one period earlier as a generated skip
     * (a start in a skipped hour, Zones, DST and All-Day Days) stores one
     * more, which is the model's, never the person's; every COUNT the API
     * reads (here) or writes (Model, a sent COUNT) counts the events shown.
     */
    public static function shownRrule(array $row): string
    {
        $rrule = (string) ($row['rrule'] ?? '');
        if (!preg_match('/(^|;)COUNT=(\d+)/i', $rrule, $m) || !str_contains((string) ($row['exdates_json'] ?? ''), (string) $row['start_utc'])) {
            return $rrule;
        }
        try {
            $anchored = Calc::fromRow($row)->anchor !== null;
        } catch (\Throwable) {
            return $rrule;
        }
        return $anchored ? (string) preg_replace('/(^|;)COUNT=\d+/i', '${1}COUNT=' . max(1, (int) $m[2] - 1), $rrule, 1) : $rrule;
    }

    /**
     * The start and end as the API says them: a series' first shown day.
     * Like its COUNT (shownRrule), a series stored one period earlier as a
     * generated skip (a start in a skipped hour) is stored from a day it
     * never shows, which is the model's, never the person's; every start
     * the API reads for a series is its first shown day, at the row's
     * length. Any other row is its stored start and end. The stored form
     * stays for the rows, ICS, CalDAV (DTSTART and EXDATE spell the same
     * series), the plans and Undo, which read rows, not API output.
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable} UTC
     */
    public static function shownSpan(array $row): array
    {
        $start = Time::fromDb((string) $row['start_utc']);
        $end = Time::fromDb((string) $row['end_utc']);
        if (empty($row['rrule']) || !str_contains((string) ($row['exdates_json'] ?? ''), (string) $row['start_utc'])) {
            return [$start, $end];
        }
        try {
            $calc = Calc::fromRow($row);
            if ($calc->anchor === null) {
                return [$start, $end];
            }
            $first = $calc->instant($calc->startDn);
        } catch (\Throwable) {
            return [$start, $end];
        }
        return [$first, $first->modify('+' . ($end->getTimestamp() - $start->getTimestamp()) . ' seconds')];
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
            // The clock typed (typedTimes): the value a person typed, which
            // the model reads as one (a typed clock, an assignment).
            if (is_array($in['typed'] ?? null)) {
                $edit['typed'] = true;
                $clock = self::typedClock($in, $edit['landed']['tzid'], $edit['landed']['all_day'] === 1);
                if ($clock !== null) {
                    $edit['landed']['clock'] = $clock;
                }
            }
        }
        if (array_key_exists('rrule', $in)) {
            $edit['rrule'] = ($in['rrule'] === null || $in['rrule'] === '') ? null : (string) $in['rrule'];
        }
        // The trip flag and floating ride along as series columns (Model::SERIES_COLUMNS): no edited day owns them.
        $content = array_intersect_key($in, array_flip(['title', 'description', 'location', 'url', 'locationLat', 'locationLng', 'locationSource', 'locationProvider', 'status', 'reminders', 'isContainer', 'floating']));
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
        // What refers to each edited row (its trip, either direction, its
        // duplicate decisions, its plugin data): the day's own, so a row
        // something names never goes back to the series.
        $rowLinks = self::referencesNaming($this->db, array_map(static fn(array $r): int => (int) $r['id'], $rows));
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
        return $this->checkedPlan(Model::plan($master, $rows, $edit, $choices, $opts), $sent, $choices);
    }

    /**
     * A split's plan for this commit (Series\Split), checked as every plan
     * is; ['whole' => true] when the day acted on is the series' first.
     */
    private function committedSplit(int $userId, array $event, array $in, string $mode): array
    {
        $sent = $in['plan'] ?? null;
        $choices = is_array($sent['choices'] ?? null) ? $sent['choices'] : [];
        unset($in['plan'], $in['preview']);
        $google = $event['source'] === 'feed' ? $this->googleCalendarFor((int) $event['calendar_id']) : null;
        [$master, $rows, $edit, $opts] = $this->seriesInput($userId, $event, $in, true, $google !== null);
        $result = Split::plan($master, $rows, $edit, $choices, $opts, $mode);
        return !empty($result['whole']) ? $result : $this->checkedPlan($result, $sent, $choices);
    }

    /**
     * One rule for every plan a commit names (series-model.md, The API):
     * refused is 422, a disabled option chosen 422, no plan sent 409 unless
     * the plan is direct, a stale hash 409, a group left out 409.
     */
    private function checkedPlan(array $result, mixed $sent, array $choices): array
    {
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
        $this->db->tx($this->seriesPlanWriter($userId, $id, $w, $in, $byId, $created, $createdEdited));
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
     * A split on its plan (Series\Split): the old series' new bound, and
     * for an edit or an answer a new series from the day acted on, with the
     * rows keyed from there re-parented to it and its own plan written on
     * it; for a delete, those rows deleted. One transaction, one Undo entry
     * (invariant 6: Undo removes the new series and puts every row back).
     * Returns the new series' id, null for a delete, or false when the day
     * acted on is the series' first (the change is the whole series').
     */
    private function splitPlanned(int $userId, array $event, array $in, string $mode, ?RequestAuthority $authority = null): int|false|null
    {
        $result = $this->committedSplit($userId, $event, $in, $mode);
        if (!empty($result['whole'])) {
            return false;
        }
        $google = $event['source'] === 'feed' ? $this->googleCalendarFor((int) $event['calendar_id']) : null;
        if ($google !== null) {
            return $this->googleSplitPlanned($userId, $google, $event, $in, $result, $authority);
        }
        $w = $result['writes'];
        $id = (int) $event['id'];
        $master = $this->db->one('SELECT * FROM events WHERE id = ?', [$id]);
        $byId = [];
        foreach ($this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id', [$id]) as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $partIds = $w['rows'];
        $beforeRows = [$master];
        foreach ($partIds as $rid) {
            $beforeRows[] = $byId[$rid];
        }
        $beforeLinks = Undo::linksOf($this->db, array_merge([$id], $partIds));
        $created = [];
        $createdEdited = [];
        $newId = $this->db->tx($this->splitWriter($userId, $id, $w, $in, $master, $byId, $created, $createdEdited));
        $afterRows = [$this->db->one('SELECT * FROM events WHERE id = ?', [$id])];
        if ($newId !== null) {
            $afterRows[] = $this->db->one('SELECT * FROM events WHERE id = ?', [$newId]);
        }
        foreach (array_merge($partIds, $created, $createdEdited) as $rid) {
            $row = $this->db->one('SELECT * FROM events WHERE id = ?', [$rid]);
            if ($row !== null) {
                $afterRows[] = $row;
            }
        }
        $afterIds = array_merge([$id], $newId === null ? [] : [$newId], $partIds, $created, $createdEdited);
        // The skips from the split on leave the old series with their
        // occurrences, so its skipped dates are part of what this changed:
        // a skip made on it earlier, or later, stands on this (stale_undo).
        $this->undo->record($userId, 'event', $id, 'update', ['events' => $beforeRows] + $beforeLinks,
            ['events' => $afterRows] + Undo::linksOf($this->db, $afterIds), mb_substr((string) $result['plan']['summary'], 0, 300));
        ChangeLog::record($this->db, (int) $master['calendar_id'], (string) $master['uid'], ChangeLog::OP_MODIFY);
        foreach (array_merge($newId === null ? [] : [$newId], array_keys($w['plan']['detach'] ?? []), $created) as $rid) {
            $row = $this->db->one('SELECT calendar_id, uid FROM events WHERE id = ?', [(int) $rid]);
            if ($row !== null) {
                ChangeLog::record($this->db, (int) $row['calendar_id'], (string) $row['uid'], ChangeLog::OP_ADD);
            }
        }
        if ($newId !== null) {
            $this->splitEventId = $newId;
        }
        return $newId;
    }

    /**
     * The row writes of a split (Series\Split) on series $id, as a closure
     * the caller runs in its transaction: the old series' new bound, and
     * for an edit or an answer the new series (the old one's row from the
     * day acted on, its own plan's master columns on top, a moved calendar
     * included), the rows keyed from the split on re-parented to it, and
     * its plan written on it; for a delete, those rows deleted. Returns the
     * new series' id, null for a delete. $identity names the new series'
     * uid and Google id when they are Google's (GoogleSeries), else the
     * series gets a uid of its own.
     */
    private function splitWriter(int $userId, int $id, array $w, array $in, array $master, array $byId, array &$created, array &$createdEdited, array $identity = []): \Closure
    {
        $partIds = $w['rows'];
        return function () use ($w, $id, $userId, $master, $byId, $partIds, $in, $identity, &$created, &$createdEdited): ?int {
            if ($w['old'] !== []) {
                $this->db->update('events', $w['old'] + ['updated_at' => Time::nowDb()], 'id = ?', [$id]);
            }
            if ($w['part'] === null) {
                foreach ($partIds as $rid) {
                    $this->db->run('DELETE FROM events WHERE id = ?', [$rid]);
                }
                return null;
            }
            $pw = $w['plan'];
            $row = $this->copyForChild($master);
            $row['uid'] = (string) ($identity['uid'] ?? Ids::ulid());
            $row['recurrence_parent_id'] = null;
            $row['recurrence_instance_utc'] = null;
            $row['google_event_id'] = $identity['google_event_id'] ?? null;
            if (array_key_exists('google_etag', $row) || isset($identity['google_etag'])) {
                $row['google_etag'] = $identity['google_etag'] ?? null;
            }
            $row['override_fields'] = null;
            $row['created_via'] = ActivityContext::get();
            $row = array_merge($row, $w['part'], $pw['master']);
            $newId = $this->db->insert('events', $this->withOwnColumn($row));
            $this->db->run($this->db->insertIgnore() . ' INTO event_tags (event_id, tag_id) SELECT ?, tag_id FROM event_tags WHERE event_id = ?', [$newId, $id]);
            $this->db->run($this->db->insertIgnore() . ' INTO event_people (event_id, person_id) SELECT ?, person_id FROM event_people WHERE event_id = ?', [$newId, $id]);
            // The rows keyed from the split on belong to it (a new uid, so
            // no key of theirs meets one of the old series').
            $moved = [];
            foreach ($partIds as $rid) {
                $set = ['recurrence_parent_id' => $newId, 'uid' => $row['uid'], 'calendar_id' => (int) $row['calendar_id']];
                $this->db->update('events', $set, 'id = ?', [$rid]);
                $moved[$rid] = $set + $byId[$rid];
            }
            ($this->seriesPlanWriter($userId, $newId, ['master' => []] + $pw, $in, $moved, $created, $createdEdited))();
            return $newId;
        };
    }

    /**
     * The row writes of a whole-series plan on series $id, as a closure
     * the caller runs in its transaction: the master's columns, the rows that leave or
     * change their key first, then the new keys, the rows it creates, tags
     * and people. $byId: the series' edited rows as read.
     */
    private function seriesPlanWriter(int $userId, int $id, array $w, array $in, array $byId, array &$created, array &$createdEdited): \Closure
    {
        return function () use ($w, $id, $userId, $byId, $in, &$created, &$createdEdited): void {
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
        };
    }

    // ---- Google (GoogleSeries) --------------------------------------------------

    private ?GoogleSeries $googleSeriesSvc = null;
    /**
     * A Google change that stopped half way: its error, thrown by the public
     * entry point (patch, deleteEvent, setAttendance: Events::throwDeferred)
     * once the account's transaction has committed, so the pending row and
     * what Google has (GoogleSeries) survive the failure the request reports.
     */
    private ?HttpError $googleDeferred = null;

    private function googleSeries(): GoogleSeries
    {
        return $this->googleSeriesSvc ??= new GoogleSeries($this->db, $this->google(), new Feeds($this->db));
    }

    /** The error a Google change left for after the transaction, thrown once; nothing when there is none. */
    public function throwDeferred(): void
    {
        if ($this->googleDeferred !== null) {
            $e = $this->googleDeferred;
            $this->googleDeferred = null;
            throw $e;
        }
    }

    /**
     * A whole-series plan on a Google calendar (GoogleSeries): the plan
     * carried to Google and read back, then the plan's rows written here
     * and what Google holds synced on top of them, so every value is
     * Google's and every row keeps its identity, tags, people and links. A
     * difference goes into the Activity entry and a Review notice; a
     * failure mid-way leaves the pending row for Review and fails the
     * request once this transaction has committed.
     */
    private function googlePatchAllPlanned(int $userId, array $calendar, array $event, array $in, ?RequestAuthority $authority = null): void
    {
        $result = $this->committedPlan($userId, $event, $in, true);
        $id = (int) $event['id'];
        unset($in['plan'], $in['preview']);
        $w = $result['writes'];
        $master = $this->db->one('SELECT * FROM events WHERE id = ?', [$id]);
        $rows = $this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id', [$id]);
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $beforeTables = $this->googleSeriesTables($calendar, [(string) $master['uid']]);
        // After the plan is read (the flag is part of what it compares),
        // before Google is sent anything: the trip rules over the rows as
        // the commit will leave them, the flag on every row the plan puts
        // it on (a series column), so a refusal sends Google nothing.
        [, $localFields] = self::splitGoogleFields($w['master']);
        $this->flagPlannedRows($w['overrides']);
        $this->tripRules($id, $localFields);
        self::requireGoogleId($master);
        $target = GoogleSeries::targetOf($master, $rows, $w);
        $summary = mb_substr((string) $result['plan']['summary'], 0, 300);
        try {
            $out = $this->googleSeries()->apply($userId, $calendar, [$target], (string) $master['title'], $summary, $id, $this->googleBeforeSend($authority),
                // `was`: the master's own columns before this change wrote any of them here
                // (the trip rules write the flag first), for Finish's "changed here since".
                ['kind' => 'series', 'eventId' => $id, 'writes' => $w, 'input' => $in, 'was' => array_diff_key($master, array_flip(GoogleWriter::GOOGLE_COLUMNS))]);
        } catch (HttpError $e) {
            $this->googleDeferred = $e;
            return;
        }
        $created = [];
        $createdEdited = [];
        $this->googleCommit($authority, function () use ($userId, $id, $w, $in, $byId, $calendar, $target, $out, &$created, &$createdEdited): void {
            ($this->seriesPlanWriter($userId, $id, $w, $in, $byId, $created, $createdEdited))();
            $this->googleSeries()->adoptSingles($target, $out['inserted'], $created);
            $this->googleSeries()->store($calendar, $out['readbacks'], $out['targets']);
            $this->googleSeries()->settle($out['pending']);
        });
        $this->googleAfter($userId, $calendar, $id, (string) $master['title'], $summary, $out['differences'],
            [$out['befores'], $beforeTables, $this->googleSeriesTables($calendar, [(string) $master['uid']], array_merge(array_keys($w['detach'] ?? []), $created))]);
    }

    /**
     * A split on a Google calendar: the old series' new bound and the new
     * series inserted with an id chosen here, its exceptions after the cut
     * carried onto it (GoogleSeries), then the local split with Google's
     * identity for the new series and what Google holds synced on top.
     */
    private function googleSplitPlanned(int $userId, array $calendar, array $event, array $in, array $result, ?RequestAuthority $authority): ?int
    {
        $w = $result['writes'];
        $id = (int) $event['id'];
        unset($in['plan'], $in['preview']);
        $master = $this->db->one('SELECT * FROM events WHERE id = ?', [$id]);
        $rows = $this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id', [$id]);
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $beforeTables = $this->googleSeriesTables($calendar, [(string) $master['uid']]);
        if ($w['part'] !== null) {
            // The trip rules over the part's rows as the commit will leave them, before Google is sent anything.
            [, $localFields] = self::splitGoogleFields($w['plan']['master'] ?? []);
            $this->flagPlannedRows($w['plan']['overrides'] ?? []);
            $this->tripRules(null, $localFields);
        }
        self::requireGoogleId($master);
        [$oldTarget, $newTarget] = GoogleSeries::splitTargets($master, $rows, $w);
        $targets = $newTarget === null ? [$oldTarget] : [$oldTarget, $newTarget];
        $summary = mb_substr((string) $result['plan']['summary'], 0, 300);
        try {
            $out = $this->googleSeries()->apply($userId, $calendar, $targets, (string) $master['title'], $summary, $id, $this->googleBeforeSend($authority),
                ['kind' => 'split', 'eventId' => $id, 'writes' => $w, 'input' => $in]);
        } catch (HttpError $e) {
            $this->googleDeferred = $e;
            return null;
        }
        $identity = self::splitIdentity($out);
        $created = [];
        $createdEdited = [];
        $newId = $this->googleCommit($authority, function () use ($userId, $id, $w, $in, $master, $byId, $identity, $calendar, $out, $newTarget, &$created, &$createdEdited): ?int {
            $newId = ($this->splitWriter($userId, $id, $w, $in, $master, $byId, $created, $createdEdited, $identity))();
            if ($newTarget !== null) {
                $this->googleSeries()->adoptSingles($out['targets'][1], $out['inserted'], $created);
            }
            $this->googleSeries()->store($calendar, $out['readbacks'], $out['targets']);
            $this->googleSeries()->settle($out['pending']);
            return $newId;
        });
        $this->googleAfter($userId, $calendar, $id, (string) $master['title'], $summary, $out['differences'],
            [$out['befores'], $beforeTables, $this->googleSeriesTables($calendar, [(string) $master['uid'], (string) ($identity['uid'] ?? '')],
                array_merge(array_keys($w['plan']['detach'] ?? []), $created))]);
        if ($newId !== null) {
            $this->splitEventId = $newId;
        }
        return $newId;
    }

    /** The new series' identity at Google, from what the split's run returned (none for a delete). */
    private static function splitIdentity(array $out): array
    {
        $new = $out['targets'][1] ?? null;
        return $new === null ? [] : [
            'uid' => (string) ($new['uid'] ?? ''),
            'google_event_id' => (string) $new['googleId'],
            'google_etag' => $out['inserted'][(string) $new['googleId']]['etag'] ?? null,
        ];
    }

    /**
     * Finish's part here (GoogleSeries::finish): the plan a change kept
     * with its pending row, written by the writer the change itself uses,
     * on the rows as they were read for it (put back where they were
     * first), then the events of their own it made given Google's identity.
     */
    private function googleLocalPlan(int $userId): \Closure
    {
        return function (array $plan, array $out, array $local = []) use ($userId): array {
            $id = (int) $plan['eventId'];
            $w = (array) $plan['writes'];
            $in = (array) $plan['input'];
            $master = $this->db->one('SELECT * FROM events WHERE id = ?', [$id]);
            if ($master === null) {
                throw HttpError::notFound('The series this change was for is no longer here');
            }
            $words = [];
            if (($plan['kind'] ?? '') !== 'split') {
                [$w, $in, $words] = self::localDeltasOver($w, $in, $local, $master, $this->db, (array) ($plan['was'] ?? []));
            }
            $byId = [];
            foreach ($this->db->all('SELECT * FROM events WHERE recurrence_parent_id = ? AND deleted_at IS NULL ORDER BY id', [$id]) as $r) {
                $byId[(int) $r['id']] = $r;
            }
            $created = [];
            $createdEdited = [];
            if (($plan['kind'] ?? '') === 'split') {
                ($this->splitWriter($userId, $id, $w, $in, $master, $byId, $created, $createdEdited, self::splitIdentity($out)))();
                if (isset($out['targets'][1])) {
                    $this->googleSeries()->adoptSingles($out['targets'][1], $out['inserted'], $created);
                }
                return $words;
            }
            ($this->seriesPlanWriter($userId, $id, $w, $in, $byId, $created, $createdEdited))();
            $this->googleSeries()->adoptSingles($out['targets'][0], $out['inserted'], $created);
            return $words;
        };
    }

    /**
     * Rule A for the part here: the plan's fields held here alone (a flag,
     * reminders, an icon, tags, people) applied over the rows as they stand,
     * each left out where the row it sets changed in that field here since
     * the change's snapshot ($local), and named. The series' columns a plan
     * carries onto its edited days go with the master's. Pure but for the
     * reads of tags and people.
     *
     * @return array{0:array,1:array,2:list<string>} the writes, the input, and the words
     */
    private static function localDeltasOver(array $w, array $in, array $local, array $master, \BetterCal\Infra\Db $db, array $before = []): array
    {
        $id = (int) $master['id'];
        $snap = [];
        foreach ($local['events'] ?? [] as $r) {
            $snap[(int) $r['id']] = $r;
        }
        $was = isset($snap[$id]) ? array_merge($snap[$id], $before) : null;
        if ($was === null) {
            return [$w, $in, []];
        }
        $names = [];
        foreach (array_keys((array) ($w['master'] ?? [])) as $col) {
            if (in_array($col, GoogleWriter::GOOGLE_COLUMNS, true) || !array_key_exists($col, $was) || !array_key_exists($col, $master)) {
                continue;
            }
            // Changed since: neither as the change found it nor as it sets it.
            if ((string) $master[$col] !== (string) $was[$col] && (string) $master[$col] !== (string) $w['master'][$col]) {
                unset($w['master'][$col]);
                foreach ($w['overrides'] ?? [] as $rid => $cols) {
                    if (array_key_exists($col, Model::SERIES_COLUMNS)) {
                        unset($w['overrides'][$rid][$col]);
                    }
                }
                $names[] = match (true) {
                    isset(Model::SERIES_COLUMNS[$col]) => strtolower(Model::SERIES_COLUMNS[$col]) . ' flag',
                    default => OwnFields::fieldOf($col) ?? $col,
                };
            }
        }
        $setOf = static function (array $rows, string $col) use ($id): array {
            $out = [];
            foreach ($rows as $r) {
                if ((int) ($r['event_id'] ?? 0) === $id) {
                    $out[] = (int) $r[$col];
                }
            }
            sort($out);
            return $out;
        };
        foreach (['tags' => ['event_tags', 'tag_id', ['tagNames']], 'people' => ['event_people', 'person_id', ['personIds', 'personNames']]] as $field => [$table, $col, $keys]) {
            if (!isset($w[$field]['master'])) {
                continue;
            }
            $now = array_map('intval', array_column($db->all("SELECT `$col` FROM `$table` WHERE event_id = ? ORDER BY `$col`", [$id]), $col));
            if ($now !== $setOf($local[$table] ?? [], $col)) {
                unset($w[$field]['master']);
                foreach ($keys as $k) {
                    unset($in[$k]);
                }
                $names[] = $field;
            }
        }
        $words = $names === [] ? [] : ["The " . implode(', ', $names) . " of '" . (string) $master['title'] . "' changed here while this change was half done; left as they are."];
        return [$w, $in, $words];
    }

    /**
     * The trip flag a plan writes on its edited rows (Model::SERIES_COLUMNS),
     * written before the Google calls so the trip rules read the end state
     * (Trips::assertRules); a refusal rolls the transaction back with
     * nothing sent, and the commit writes the same value again.
     */
    private function flagPlannedRows(array $overrides): void
    {
        foreach ($overrides as $rid => $cols) {
            if (array_key_exists('is_container', $cols)) {
                $this->db->update('events', ['is_container' => (int) $cols['is_container']], 'id = ?', [(int) $rid]);
            }
        }
    }

    /**
     * The Activity entry of a Google series change, and a Review notice
     * when Google kept something else. $undo ([befores, before tables,
     * after tables]) makes the entry undoable (googleSeriesJournal); Review's
     * Finish and Put back pass none, being the way back themselves.
     */
    private function googleAfter(int $userId, array $calendar, ?int $id, string $title, string $summary, array $differences, ?array $undo = null): void
    {
        $words = $summary;
        if ($differences !== []) {
            $words .= ' Google kept something else: ' . implode(' ', $differences);
        }
        if ($undo !== null && $id !== null) {
            $this->googleSeriesJournal($userId, mb_substr($words, 0, 900), $calendar, $id, $undo[0], $undo[1], $undo[2]);
        } else {
            $this->googleJournal($userId, mb_substr($words, 0, 900), $calendar, $id);
        }
        if ($differences !== []) {
            (new ReviewQueue($this->db, $this))->holdGoogleDifferences($userId, $id, $title, (string) $calendar['name'], $differences);
        }
    }

    /**
     * Review's Finish on a change half done at Google: the same targets
     * from Google's state (GoogleSeries::finish), the rows here then
     * Google's. A failure is deferred as the change's was.
     */
    public function googleFinish(int $userId, int $pendingId, ?RequestAuthority $authority = null): array
    {
        return $this->googleRecover($userId, $pendingId, $authority, 'finish');
    }

    /** Review's Put back on a change half done at Google (GoogleSeries::putBack). */
    public function googlePutBack(int $userId, int $pendingId, ?RequestAuthority $authority = null): array
    {
        return $this->googleRecover($userId, $pendingId, $authority, 'putBack');
    }

    private function googleRecover(int $userId, int $pendingId, ?RequestAuthority $authority, string $how): array
    {
        return $this->undo->write($userId, function () use ($userId, $pendingId, $authority, $how): array {
            $authority?->assertValid($this->db);
            $row = $this->googleSeries()->pendingOf($userId, $pendingId);
            if ($row === null) {
                throw HttpError::notFound('No such half-done Google change');
            }
            $calendar = CalendarMoveGuard::lockCalendar($this->db, (int) $row['calendar_id']);
            if ($calendar === null || !GoogleWriter::writable($calendar)) {
                throw HttpError::conflict('google_calendar_gone', 'The Google calendar this change was for is no longer one this account can edit.');
            }
            try {
                $out = $how === 'finish'
                    ? $this->googleSeries()->finish($userId, $calendar, $pendingId, $this->googleBeforeSend($authority), $this->googleLocalPlan($userId))
                    : $this->googleSeries()->putBack($userId, $calendar, $pendingId, $this->googleBeforeSend($authority));
            } catch (HttpError $e) {
                $this->googleDeferred = $e;
                return ['ok' => false];
            }
            $left = $out['left'] ?? [];
            $what = ($how === 'finish' ? "Finished '" . (string) $row['title'] . "' at Google" : "Put '" . (string) $row['title'] . "' back at Google")
                . ($left !== [] ? ' ' . implode(' ', $left) : '');
            $this->googleAfter($userId, $calendar, $row['event_id'] !== null ? (int) $row['event_id'] : null, (string) $row['title'], $what, $out['differences']);
            return ['ok' => true, 'differences' => $out['differences'], 'left' => $left];
        });
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

    /**
     * A "this event" edit's column patch with the start the own list it
     * leaves implies (OwnFields::placed): an inherited clock is the series'
     * on the day's date, whatever clock the request showed. Returns the
     * patch and the fields the edit changed, read from the request before
     * any placement, so a date moved alone stays the one field changed.
     *
     * @return array{0:array,1:list<string>}
     */
    private function placedThis(array $row, array $patch, array $master, array $in): array
    {
        $after = array_merge($row, $patch);
        $changed = self::changedOwnFields($row, $after, $in);
        $known = OwnFields::decode($row['override_fields'] ?? null);
        if ($known === null && !empty($row['id'])) {
            $known = OwnFields::infer($row, $master);
        }
        $placed = OwnFields::placed($after, $master, OwnFields::normalize(array_merge($known ?? [], $changed)));
        foreach (['start_utc', 'end_utc', 'tzid'] as $col) {
            if ((string) ($placed[$col] ?? '') !== (string) ($after[$col] ?? '')) {
                $patch[$col] = $placed[$col];
            }
        }
        return [$patch, $changed];
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
     * What refers to each row (its trip links, duplicate decisions and
     * plugin data), by row id, as "kind:reference" entries: the one reading
     * of OwnFields::REFERENCES.
     *
     * @param list<int> $ids
     * @return array<int,list<string>>
     */
    private static function referencesNaming(\BetterCal\Infra\Db $db, array $ids): array
    {
        return OwnFields::references($db, $ids);
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
            $tz = Time::dayZone((int) $master['all_day'] === 1, (string) $master['tzid']);
            $rowAllDay = (int) $row['all_day'] === 1;
            $rowTz = Time::dayZone($rowAllDay, (string) $row['tzid']);
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
        // its own is left, nor anything referring to it (OwnFields::own).
        $gone = OwnFields::own($left, self::referencesNaming($this->db, [$id])[$id] ?? []) === [];
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
