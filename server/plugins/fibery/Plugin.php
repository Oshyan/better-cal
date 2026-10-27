<?php

declare(strict_types=1);

use BetterCal\Plugin\EditablePlugin;
use BetterCal\Plugin\PluginEditRefused;
use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;

/**
 * Fibery tasks and projects on the calendar, and their dates back to Fibery.
 *
 * Every minute the sync job reads the open, dated items of the chosen
 * databases (one batched request) and replaces the calendar's snapshot, so
 * completions, deletions and date changes made in Fibery all arrive the same
 * way. Moving an event here calls applyEdit, which writes the new date to
 * Fibery while the person waits.
 *
 * Dates follow Fibery's own rules (its guide, "Timezones" and "Date Ranges in
 * Formulas"): date-times are UTC instants shown in the viewer's local zone, so
 * they're read in the person's Home zone here too; date-only fields and date
 * ranges are never converted; a date range's stored end is one day past the
 * last day shown (an exclusive end, like the calendar's), and a date-time
 * range's is one second past the shown end.
 */
return new class implements PluginInterface, EditablePlugin {
    /** The two kinds of item, and the settings that configure each. */
    private const SLOTS = [
        'task' => ['db' => 'taskDatabase', 'date' => 'taskDateField', 'details' => 'taskDetails', 'hide' => 'taskHideWhen',
            'calendar' => 'Fibery tasks', 'color' => '#3f8f6b', 'options' => 'task'],
        'project' => ['db' => 'projectDatabase', 'date' => 'projectDateField', 'details' => 'projectDetails', 'hide' => 'projectHideWhen',
            'calendar' => 'Fibery projects', 'color' => '#7a5cc7', 'options' => 'project'],
    ];
    private const DATE_TYPES = ['fibery/date', 'fibery/date-time', 'fibery/date-range', 'fibery/date-time-range'];
    private const PRIMITIVE_TYPES = ['fibery/text', 'fibery/int', 'fibery/decimal', 'fibery/bool', 'fibery/email', 'fibery/url',
        'fibery/emoji', 'fibery/date', 'fibery/date-time', 'fibery/date-range', 'fibery/date-time-range'];
    private const SKIP_TYPES = ['Collaboration~Documents/Document', 'Collaboration~Documents/Reference', 'fibery/file',
        'fibery/view', 'comments/comment', 'fibery/rank', 'fibery/uuid'];
    private const SCHEMA_TTL = 600;          // seconds a cached schema is trusted
    private const PAGE = 1000;               // entities per query page (Fibery's recommendation)
    private const MAX_PAGES = 3;             // per database per run
    private const DESCRIPTION_CHARS = 1500;
    private const TIMED_MINUTES = 30;        // length of a timed task
    private const FINISHED_LOOKBACK_DAYS = 90;

    public function validateSettings(array $values): array
    {
        if (array_key_exists('workspace', $values) && $values['workspace'] !== null && $values['workspace'] !== ''
            && self::workspaceHost((string) $values['workspace']) === null) {
            return ['workspace' => 'Enter your Fibery address, like yourteam.fibery.io'];
        }
        return [];
    }

    // ---- sync -------------------------------------------------------------

    public function runJob(PluginHost $host, string $jobId): void
    {
        $s = $host->settings();
        $ws = self::workspaceHost((string) ($s['workspace'] ?? ''));
        $token = (string) ($s['token'] ?? '');
        if ($ws === null || $token === '') {
            $host->replaceWarnings([['severity' => 'info', 'message' => 'Add your Fibery workspace and API key in the plugin settings.']]);
            return;
        }
        $schema = $this->schema($host, $ws, $token);
        $this->publishOptions($host, $schema, $s);

        $warnings = [];
        $tz = $host->timezone();
        $calendars = [];
        foreach ($host->calendars() as $c) {
            if (($c['kind'] ?? '') === 'plugin') {
                $calendars[(string) $c['name']] = (int) $c['id'];
            }
        }
        $queries = [];
        $plans = [];
        foreach (self::SLOTS as $slot => $keys) {
            $plan = $this->plan($schema, $s, $slot);
            if (is_string($plan)) {
                if ($plan !== '') {
                    $warnings[] = ['severity' => 'warn', 'message' => $plan];
                }
                // An unconfigured slot empties a calendar it made earlier.
                if (isset($calendars[$keys['calendar']])) {
                    $host->syncEvents($calendars[$keys['calendar']], []);
                }
                continue;
            }
            $plans[$slot] = $plan;
            $queries[$slot] = $this->query($plan, $s, null);
        }
        if ($plans === []) {
            $host->replaceWarnings($warnings === [] ? [['severity' => 'info', 'message' => 'Choose a task or project database in the plugin settings.']] : $warnings);
            return;
        }

        $rows = $this->fetchAll($host, $ws, $token, $plans, $queries, $s);
        $docs = !empty($s['includeDescription']) ? $this->descriptions($host, $ws, $token, $plans, $rows) : [];

        foreach ($plans as $slot => $plan) {
            $events = [];
            foreach ($rows[$slot] as $row) {
                $ev = $this->event($plan, $row, $s, $tz, $ws, $docs);
                if ($ev !== null) {
                    $events[] = $ev;
                }
            }
            $calId = $host->ensureCalendar(self::SLOTS[$slot]['calendar'], self::SLOTS[$slot]['color'], 'mine');
            [$added, $updated, $removed] = $host->syncEvents($calId, $events);
            $host->log($slot . ': ' . count($events) . ' items (' . $added . ' added, ' . $updated . ' updated, ' . $removed . ' removed)');
        }
        $host->replaceWarnings($warnings);
    }

    /**
     * What one slot needs to query: its database, date field and the fields
     * to show, checked against the schema. A string is the reason it can't
     * run ('' when the slot simply isn't set up).
     *
     * @return array<string,mixed>|string
     */
    private function plan(array $schema, array $s, string $slot): array|string
    {
        $keys = self::SLOTS[$slot];
        $db = (string) ($s[$keys['db']] ?? '');
        if ($db === '') {
            return '';
        }
        $type = $schema['types'][$db] ?? null;
        if ($type === null) {
            return 'The ' . $slot . ' database "' . $db . '" is no longer in Fibery. Choose it again in the settings.';
        }
        $dateField = (string) ($s[$keys['date']] ?? '');
        if ($dateField === '') {
            return 'Choose which date field puts a ' . $slot . ' on the calendar.';
        }
        $date = $type['fields'][$dateField] ?? null;
        if ($date === null || !in_array($date['type'], self::DATE_TYPES, true)) {
            return 'The ' . $slot . ' date field "' . self::label($dateField) . '" is no longer a date field in Fibery. Choose another.';
        }
        $details = [];
        foreach ((array) ($s[$keys['details']] ?? []) as $name) {
            $f = $type['fields'][(string) $name] ?? null;
            $kind = $f !== null ? $this->detailKind($schema, (string) $name, $f, $type, $dateField) : null;
            if ($kind !== null) {
                $details[(string) $name] = $kind;
            }
        }
        $hide = (string) ($s[$keys['hide']] ?? '');
        return [
            'slot' => $slot,
            'db' => $db,
            'title' => $type['title'],
            'dateField' => $dateField,
            'dateType' => $date['type'],
            'workflow' => isset($type['fields']['workflow/state']),
            'hide' => $hide !== '' && ($type['fields'][$hide]['type'] ?? '') === 'fibery/bool' ? $hide : null,
            'details' => $details,
            'doc' => $this->documentField($type),
        ];
    }

    /** The query for one slot (all open dated items), or for one entity when $id is given. */
    private function query(array $plan, array $s, ?string $id): array
    {
        $select = ['fibery/id', 'fibery/public-id', $plan['title'], $plan['dateField']];
        if ($plan['workflow']) {
            $select[] = ['workflow/state' => ['enum/name', 'workflow/Final']];
        }
        foreach ($plan['details'] as $name => $kind) {
            $select[] = $kind['select'];
        }
        if ($plan['doc'] !== null && !empty($s['includeDescription'])) {
            $select[] = [$plan['doc'] => ['Collaboration~Documents/secret', 'Collaboration~Documents/modification-date']];
        }
        $where = [['=', ['q/null?', [$plan['dateField']]], '$no']];
        $params = ['$no' => false];
        if ($id !== null) {
            $where = [['=', ['fibery/id'], '$id']];
            $params = ['$id' => $id];
        } else {
            if ($plan['workflow'] && !empty($s['hideFinished'])) {
                $where[] = ['=', ['workflow/state', 'workflow/Final'], '$no'];
            } elseif (in_array($plan['dateType'], ['fibery/date', 'fibery/date-time'], true)) {
                // Showing finished items too: only recent and future ones,
                // or every task ever done would land on the calendar.
                $where[] = ['>=', [$plan['dateField']], '$since'];
                $since = new \DateTimeImmutable('-' . self::FINISHED_LOOKBACK_DAYS . ' days');
                $params['$since'] = $plan['dateType'] === 'fibery/date' ? $since->format('Y-m-d') : gmdate('Y-m-d\TH:i:s.000\Z', $since->getTimestamp());
            }
            if ($plan['hide'] !== null) {
                $where[] = ['!=', [$plan['hide']], '$yes'];
                $params['$yes'] = true;
            }
        }
        return [
            'q/from' => $plan['db'],
            'q/select' => $select,
            'q/where' => count($where) === 1 ? $where[0] : array_merge(['q/and'], $where),
            'q/order-by' => [[['fibery/id'], 'q/asc']],
            'q/limit' => $id !== null ? 1 : self::PAGE + 1,
            '_params' => $params,
        ];
    }

    /** @return array<string,list<array>> rows per slot */
    private function fetchAll(PluginHost $host, string $ws, string $token, array $plans, array $queries, array $s): array
    {
        $rows = array_fill_keys(array_keys($plans), []);
        $after = array_fill_keys(array_keys($plans), null);
        $open = array_keys($plans);
        for ($page = 0; $page < self::MAX_PAGES && $open !== []; $page++) {
            $commands = [];
            foreach ($open as $slot) {
                $q = $queries[$slot];
                $params = $q['_params'];
                unset($q['_params']);
                if ($after[$slot] !== null) {
                    $q['q/where'] = ['q/and', $q['q/where'], ['>', ['fibery/id'], '$after']];
                    $params['$after'] = $after[$slot];
                }
                $commands[] = ['command' => 'fibery.entity/query', 'args' => ['query' => $q, 'params' => $params]];
            }
            $results = $this->commands($host, $ws, $token, $commands);
            $next = [];
            foreach ($open as $i => $slot) {
                $batch = $results[$i];
                $more = count($batch) > self::PAGE;
                $batch = array_slice($batch, 0, self::PAGE);
                array_push($rows[$slot], ...$batch);
                if ($more && $batch !== []) {
                    $after[$slot] = (string) end($batch)['fibery/id'];
                    $next[] = $slot;
                }
            }
            $open = $next;
        }
        if ($open !== []) {
            $host->log('more items than one run reads; showing the first ' . (self::PAGE * self::MAX_PAGES) . ' per database');
        }
        return $rows;
    }

    /** Plain-text descriptions, by document secret, fetched only when changed. */
    private function descriptions(PluginHost $host, string $ws, string $token, array $plans, array $rows): array
    {
        $cache = $host->kvGet('descriptions', []);
        $cache = is_array($cache) ? $cache : [];
        $want = [];
        $keep = [];
        foreach ($plans as $slot => $plan) {
            if ($plan['doc'] === null) {
                continue;
            }
            foreach ($rows[$slot] as $row) {
                $doc = $row[$plan['doc']] ?? null;
                $secret = is_array($doc) ? (string) ($doc['Collaboration~Documents/secret'] ?? '') : '';
                if ($secret === '') {
                    continue;
                }
                $modified = (string) ($doc['Collaboration~Documents/modification-date'] ?? '');
                $keep[$secret] = true;
                if (($cache[$secret]['m'] ?? null) !== $modified) {
                    $want[$secret] = $modified;
                }
            }
        }
        foreach (array_chunk(array_keys($want), 100) as $i => $chunk) {
            if ($i >= 3 || $host->budgetRemaining() < 15) {
                break; // the rest arrive on the next runs
            }
            $docs = $this->post($host, 'https://' . $ws . '/api/documents/commands?format=plain-text', $token,
                ['command' => 'get-documents', 'args' => array_map(static fn($x) => ['secret' => $x], $chunk)]);
            foreach (is_array($docs) ? $docs : [] as $d) {
                $secret = (string) ($d['secret'] ?? '');
                if ($secret !== '' && isset($want[$secret])) {
                    $cache[$secret] = ['m' => $want[$secret], 't' => mb_substr(trim((string) ($d['content'] ?? '')), 0, self::DESCRIPTION_CHARS)];
                }
            }
        }
        $cache = array_intersect_key($cache, $keep);
        $host->kvSet('descriptions', $cache);
        return array_map(static fn($c) => (string) ($c['t'] ?? ''), $cache);
    }

    /** One calendar event for a Fibery entity, or null when it has no usable date. */
    private function event(array $plan, array $row, array $s, \DateTimeZone $tz, string $ws, array $docs): ?array
    {
        $id = (string) ($row['fibery/id'] ?? '');
        $final = $plan['workflow'] && !empty($row['workflow/state']['workflow/Final']);
        $shown = $this->displaySpan($plan, $row[$plan['dateField']] ?? null, $s, $tz, $final);
        if ($id === '' || $shown === null) {
            return null;
        }
        [$span, $overdueSince] = $shown;
        // Very long projects (an ongoing area, a year-long goal) would run
        // across every week of the calendar; the person sets the limit.
        $maxDays = (int) ($s['projectMaxDays'] ?? 0);
        if ($plan['slot'] === 'project' && $maxDays > 0 && $span['allDay']
            && (new \DateTimeImmutable($span['start']))->diff(new \DateTimeImmutable($span['end']))->days > $maxDays) {
            return null;
        }
        $lines = [];
        if ($overdueSince !== null) {
            $lines[] = '<div><b>Overdue:</b> was due ' . self::esc($overdueSince) . '</div>';
        }
        foreach ($plan['details'] as $name => $kind) {
            $text = $this->detailText($kind, $row[$name] ?? null, $tz);
            if ($text !== '') {
                $lines[] = '<div><b>' . self::esc(self::label($name)) . ':</b> ' . self::esc($text) . '</div>';
            }
        }
        $doc = $plan['doc'] !== null ? ($row[$plan['doc']]['Collaboration~Documents/secret'] ?? null) : null;
        if (is_string($doc) && ($docs[$doc] ?? '') !== '') {
            $lines[] = '<p>' . nl2br(self::esc($docs[$doc]), false) . '</p>';
        }
        $title = trim((string) ($row[$plan['title']] ?? ''));
        return $span + [
            'sourceKey' => $plan['slot'] . ':' . $id,
            'title' => $title !== '' ? $title : 'Untitled',
            'description' => $lines === [] ? null : implode('', $lines),
            'url' => self::entityUrl($ws, $plan['db'], (string) ($row['fibery/public-id'] ?? '')),
        ];
    }

    /**
     * Where an item shows: {start, end, allDay} plus, for an unfinished task
     * moved onto today, the day it was due. Null for a missing date.
     *
     * @return array{0:array{start:string,end:string,allDay:bool},1:?string}|null
     */
    private function displaySpan(array $plan, mixed $value, array $s, \DateTimeZone $tz, bool $final): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        try {
            switch ($plan['dateType']) {
                case 'fibery/date':
                    $day = new \DateTimeImmutable((string) $value, $tz);
                    $span = self::allDay($day, $day->modify('+1 day'));
                    break;
                case 'fibery/date-time':
                    $at = (new \DateTimeImmutable((string) $value))->setTimezone($tz);
                    if (($s['taskTimes'] ?? 'allday') === 'timed' && $plan['slot'] === 'task' && $at->format('H:i') !== '00:00') {
                        $span = ['start' => $at->format(DATE_ATOM), 'end' => $at->modify('+' . self::TIMED_MINUTES . ' minutes')->format(DATE_ATOM), 'allDay' => false];
                    } else {
                        $day = new \DateTimeImmutable($at->format('Y-m-d'), $tz);
                        $span = self::allDay($day, $day->modify('+1 day'));
                    }
                    break;
                case 'fibery/date-range':
                    if (!is_array($value) || empty($value['start'])) {
                        return null;
                    }
                    $start = new \DateTimeImmutable((string) $value['start'], $tz);
                    $end = !empty($value['end']) ? new \DateTimeImmutable((string) $value['end'], $tz) : $start->modify('+1 day');
                    $span = self::allDay($start, $end > $start ? $end : $start->modify('+1 day'));
                    break;
                case 'fibery/date-time-range':
                    if (!is_array($value) || empty($value['start'])) {
                        return null;
                    }
                    $start = (new \DateTimeImmutable((string) $value['start']))->setTimezone($tz);
                    $end = !empty($value['end']) ? (new \DateTimeImmutable((string) $value['end']))->setTimezone($tz)->modify('-1 second') : $start;
                    $end = $end > $start ? $end : $start->modify('+' . self::TIMED_MINUTES . ' minutes');
                    $span = ['start' => $start->format(DATE_ATOM), 'end' => $end->format(DATE_ATOM), 'allDay' => false];
                    break;
                default:
                    return null;
            }
        } catch (\Throwable) {
            return null;
        }
        // An unfinished task whose day has passed shows on today.
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $single = in_array($plan['dateType'], ['fibery/date', 'fibery/date-time'], true);
        $day = $span['allDay'] ? $span['start'] : (new \DateTimeImmutable($span['start']))->setTimezone($tz)->format('Y-m-d');
        if ($plan['slot'] === 'task' && $single && !$final && !empty($s['overdueOnToday']) && $day < $today) {
            $todayStart = new \DateTimeImmutable($today, $tz);
            return [self::allDay($todayStart, $todayStart->modify('+1 day')), (new \DateTimeImmutable($day, $tz))->format('D, M j')];
        }
        return [$span, null];
    }

    // ---- writing back -------------------------------------------------------

    public function applyEdit(PluginHost $host, array $edit): array
    {
        $s = $host->settings();
        $ws = self::workspaceHost((string) ($s['workspace'] ?? ''));
        $token = (string) ($s['token'] ?? '');
        [$slot, $id] = array_pad(explode(':', (string) ($edit['sourceKey'] ?? ''), 2), 2, '');
        if ($ws === null || $token === '' || !isset(self::SLOTS[$slot]) || $id === '') {
            throw new PluginEditRefused('This Fibery item can no longer be changed from here. Check the Fibery plugin settings.');
        }
        try {
            $schema = $this->schema($host, $ws, $token);
            $plan = $this->plan($schema, $s, $slot);
            if (is_string($plan)) {
                throw new PluginEditRefused($plan !== '' ? $plan : 'The Fibery ' . $slot . ' database is no longer set up.');
            }
            $current = $this->commands($host, $ws, $token, [[
                'command' => 'fibery.entity/query',
                'args' => (static function (array $q): array {
                    $params = $q['_params'];
                    unset($q['_params']);
                    return ['query' => $q, 'params' => $params];
                })($this->query($plan, ['includeDescription' => false] + $s, $id)),
            ]])[0][0] ?? null;
            if ($current === null) {
                throw new PluginEditRefused('That item is no longer in Fibery.');
            }
            $tz = $host->timezone();
            $value = $this->newValue($plan, $current[$plan['dateField']] ?? null, (array) $edit['after'], $tz);
            $this->commands($host, $ws, $token, [[
                'command' => 'fibery.entity/update',
                'args' => ['type' => $plan['db'], 'entity' => ['fibery/id' => $id, $plan['dateField'] => $value]],
            ]]);
        } catch (PluginEditRefused $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw new PluginEditRefused('Fibery didn\'t take the change: ' . $e->getMessage());
        }
        $final = $plan['workflow'] && !empty($current['workflow/state']['workflow/Final']);
        $shown = $this->displaySpan($plan, $value, $s, $tz, $final);
        return $shown !== null ? $shown[0] : (array) $edit['after'];
    }

    /**
     * The value to store for a new time, in the field's own form. A date-time
     * moved to another day keeps its time of day (in the person's zone), so a
     * task due at midnight stays at midnight.
     */
    private function newValue(array $plan, mixed $current, array $after, \DateTimeZone $tz): string|array
    {
        $allDay = !empty($after['allDay']);
        $start = $allDay ? new \DateTimeImmutable((string) $after['start'], $tz) : (new \DateTimeImmutable((string) $after['start']))->setTimezone($tz);
        $end = $allDay ? new \DateTimeImmutable((string) $after['end'], $tz) : (new \DateTimeImmutable((string) $after['end']))->setTimezone($tz);
        $utc = static fn(\DateTimeImmutable $t): string => $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        switch ($plan['dateType']) {
            case 'fibery/date':
                return $start->format('Y-m-d');
            case 'fibery/date-time':
                if (!$allDay) {
                    return $utc($start);
                }
                $clock = '00:00:00.000';
                if (is_string($current) && $current !== '') {
                    $clock = (new \DateTimeImmutable($current))->setTimezone($tz)->format('H:i:s.v');
                }
                return $utc(new \DateTimeImmutable($start->format('Y-m-d') . ' ' . $clock, $tz));
            case 'fibery/date-range':
                $endDay = $allDay ? $end : ($end->format('H:i:s') === '00:00:00' ? $end : $end->modify('+1 day'));
                $endDay = new \DateTimeImmutable($endDay->format('Y-m-d'), $tz);
                $startDay = new \DateTimeImmutable($start->format('Y-m-d'), $tz);
                return ['start' => $startDay->format('Y-m-d'), 'end' => ($endDay > $startDay ? $endDay : $startDay->modify('+1 day'))->format('Y-m-d')];
            case 'fibery/date-time-range':
                return ['start' => $utc($start), 'end' => $utc($end->modify('+1 second'))];
        }
        throw new PluginEditRefused('That Fibery field isn\'t a date.');
    }

    // ---- schema and choices ---------------------------------------------------

    /**
     * The workspace's databases and fields, reduced to what this plugin uses
     * and cached for ten minutes: {types: {name: {title, fields: {name: {type,
     * collection, formula}}}}}.
     */
    private function schema(PluginHost $host, string $ws, string $token): array
    {
        $cached = $host->kvGet('schema');
        if (is_array($cached) && ($cached['ws'] ?? '') === $ws && time() - (int) ($cached['at'] ?? 0) < self::SCHEMA_TTL) {
            return $cached;
        }
        $raw = $this->commands($host, $ws, $token, [['command' => 'fibery.schema/query']])[0];
        $types = [];
        $enums = [];
        foreach ((array) ($raw['fibery/types'] ?? []) as $t) {
            $name = (string) ($t['fibery/name'] ?? '');
            $meta = (array) ($t['fibery/meta'] ?? []);
            if ($name === '' || !empty($meta['fibery/deleted?'])) {
                continue;
            }
            if (!empty($meta['fibery/enum?']) || str_starts_with($name, 'workflow/state_')) {
                $enums[$name] = true;
            }
            $fields = [];
            $title = null;
            foreach ((array) ($t['fibery/fields'] ?? []) as $f) {
                $fm = (array) ($f['fibery/meta'] ?? []);
                $fname = (string) ($f['fibery/name'] ?? '');
                if ($fname === '' || !empty($fm['fibery/deleted?'])) {
                    continue;
                }
                $fields[$fname] = [
                    'type' => (string) ($f['fibery/type'] ?? ''),
                    'collection' => !empty($fm['fibery/collection?']),
                    'formula' => !empty($fm['formula/formula?']),
                ];
                if (!empty($fm['ui/title?'])) {
                    $title = $fname;
                }
            }
            $types[$name] = ['title' => $title, 'fields' => $fields, 'domain' => !empty($meta['fibery/domain?'])];
        }
        $schema = ['ws' => $ws, 'at' => time(), 'types' => [], 'enums' => array_keys($enums), 'titles' => []];
        foreach ($types as $name => $t) {
            // Titles of every type, for showing a related item by name.
            if ($t['title'] !== null) {
                $schema['titles'][$name] = $t['title'];
            }
            if ($t['domain'] && $t['title'] !== null && !isset($enums[$name]) && !str_starts_with($name, 'fibery/')) {
                $schema['types'][$name] = ['title' => $t['title'], 'fields' => $t['fields']];
            }
        }
        ksort($schema['types']);
        $host->kvSet('schema', $schema);
        return $schema;
    }

    /** Fill the settings' choices: databases, and each chosen database's fields. */
    private function publishOptions(PluginHost $host, array $schema, array $s): void
    {
        $host->setSettingOptions('databases', array_map(
            static fn(string $n) => ['value' => $n, 'label' => str_replace('/', ' / ', $n)],
            array_keys($schema['types'])
        ));
        foreach (self::SLOTS as $slot => $keys) {
            $type = $schema['types'][(string) ($s[$keys['db']] ?? '')] ?? null;
            $dates = [];
            $bools = [];
            $details = [];
            foreach ($type['fields'] ?? [] as $name => $f) {
                $option = ['value' => $name, 'label' => self::label($name)];
                if (in_array($f['type'], self::DATE_TYPES, true) && !$f['formula'] && !str_starts_with($name, 'fibery/')) {
                    $dates[] = ['value' => $name, 'label' => self::label($name) . ' (' . self::dateKindLabel($f['type']) . ')'];
                }
                if ($f['type'] === 'fibery/bool' && !$f['formula']) {
                    $bools[] = $option;
                }
                if ($this->detailKind($schema, $name, $f, $type, (string) ($s[$keys['date']] ?? '')) !== null) {
                    $details[] = $option;
                }
            }
            $byLabel = static function (array $a, array $b): int {
                return strcasecmp($a['label'], $b['label']);
            };
            usort($dates, $byLabel);
            usort($bools, $byLabel);
            usort($details, $byLabel);
            // No database chosen: the fields aren't known yet, not empty.
            $host->setSettingOptions($keys['options'] . 'DateFields', $type === null ? null : $dates);
            $host->setSettingOptions($keys['options'] . 'BoolFields', $type === null ? null : $bools);
            $host->setSettingOptions($keys['options'] . 'Fields', $type === null ? null : $details);
        }
    }

    /**
     * How to read and show one field as a detail, or null when it isn't
     * something to show (the title, the date that places the item, files,
     * documents, internal fields).
     */
    private function detailKind(array $schema, string $name, array $f, ?array $type, string $dateField): ?array
    {
        if ($type === null || $name === $type['title'] || $name === $dateField || in_array($f['type'], self::SKIP_TYPES, true)
            || in_array($name, ['fibery/id', 'fibery/rank', 'fibery/public-id'], true)) {
            return null;
        }
        if (in_array($f['type'], self::PRIMITIVE_TYPES, true)) {
            return ['kind' => 'value', 'type' => $f['type'], 'select' => $name];
        }
        if ($f['type'] === 'fibery/user') {
            return $f['collection']
                ? ['kind' => 'many', 'select' => [$name => ['q/select' => ['user/name'], 'q/limit' => 20]], 'field' => 'user/name']
                : ['kind' => 'one', 'select' => [$name => ['user/name']], 'field' => 'user/name'];
        }
        $isEnum = in_array($f['type'], $schema['enums'], true);
        $titleField = $isEnum ? 'enum/name' : ($schema['titles'][$f['type']] ?? null);
        if ($titleField === null) {
            return null;
        }
        return $f['collection']
            ? ['kind' => 'many', 'select' => [$name => ['q/select' => [$titleField], 'q/limit' => 20]], 'field' => $titleField]
            : ['kind' => 'one', 'select' => [$name => [$titleField]], 'field' => $titleField];
    }

    private function detailText(array $kind, mixed $value, \DateTimeZone $tz): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }
        switch ($kind['kind']) {
            case 'one':
                return is_array($value) ? trim((string) ($value[$kind['field']] ?? '')) : '';
            case 'many':
                $names = [];
                foreach (is_array($value) ? $value : [] as $item) {
                    $n = is_array($item) ? trim((string) ($item[$kind['field']] ?? '')) : '';
                    if ($n !== '') {
                        $names[] = $n;
                    }
                }
                return implode(', ', $names);
        }
        // A plain value: dates shown in the person's zone, booleans in words.
        try {
            switch ($kind['type']) {
                case 'fibery/bool':
                    return $value ? 'Yes' : 'No';
                case 'fibery/date':
                    return (new \DateTimeImmutable((string) $value))->format('D, M j, Y');
                case 'fibery/date-time':
                    return (new \DateTimeImmutable((string) $value))->setTimezone($tz)->format('D, M j, Y g:i A');
                case 'fibery/date-range':
                    $end = (new \DateTimeImmutable((string) ($value['end'] ?? $value['start'])))->modify('-1 day');
                    return (new \DateTimeImmutable((string) $value['start']))->format('M j') . ' to ' . $end->format('M j, Y');
                case 'fibery/date-time-range':
                    return (new \DateTimeImmutable((string) $value['start']))->setTimezone($tz)->format('M j g:i A') . ' to '
                        . (new \DateTimeImmutable((string) ($value['end'] ?? $value['start'])))->setTimezone($tz)->modify('-1 second')->format('M j g:i A');
            }
        } catch (\Throwable) {
            return '';
        }
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** The rich-text field to read descriptions from: one named Description, else the first. */
    private function documentField(array $type): ?string
    {
        $first = null;
        foreach ($type['fields'] as $name => $f) {
            if ($f['type'] !== 'Collaboration~Documents/Document') {
                continue;
            }
            if (strcasecmp(self::label($name), 'description') === 0) {
                return $name;
            }
            $first ??= $name;
        }
        return $first;
    }

    // ---- talking to Fibery ------------------------------------------------------

    /**
     * Run commands in one request and return each one's result. Throws a
     * RuntimeException with a plain reason (key refused, rate limit, a
     * command Fibery rejected).
     *
     * @return list<mixed>
     */
    private function commands(PluginHost $host, string $ws, string $token, array $commands): array
    {
        $out = $this->post($host, 'https://' . $ws . '/api/commands', $token, $commands);
        if (!is_array($out) || count($out) !== count($commands)) {
            throw new \RuntimeException('Fibery sent an unexpected answer');
        }
        $results = [];
        foreach ($out as $r) {
            if (!is_array($r) || empty($r['success'])) {
                $msg = is_array($r) && is_array($r['result'] ?? null) ? (string) ($r['result']['message'] ?? '') : '';
                throw new \RuntimeException($msg !== '' ? mb_substr($msg, 0, 200) : 'Fibery refused the request');
            }
            $results[] = $r['result'];
        }
        return $results;
    }

    private function post(PluginHost $host, string $url, string $token, array $payload): mixed
    {
        $r = $host->http()->json('POST', $url, $payload, ['Authorization: Token ' . $token]);
        $status = (int) $r['status'];
        if ($status === 401 || $status === 403) {
            throw new \RuntimeException('Fibery didn\'t accept the API key; check it in the plugin settings');
        }
        if ($status === 429) {
            throw new \RuntimeException('Fibery asked for a pause (too many requests); trying again shortly');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Fibery answered with error ' . $status);
        }
        $data = json_decode((string) $r['body'], true);
        if ($data === null) {
            throw new \RuntimeException('Fibery sent an answer that isn\'t JSON');
        }
        return $data;
    }

    // ---- small helpers ------------------------------------------------------------

    /** "yourteam.fibery.io" from that, "yourteam", or a full address; null otherwise. */
    private static function workspaceHost(string $input): ?string
    {
        $v = strtolower(trim($input));
        if ($v === '') {
            return null;
        }
        if (str_contains($v, '://')) {
            $v = (string) parse_url($v, PHP_URL_HOST);
        }
        $v = rtrim(explode('/', $v)[0], '.');
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $v) === 1) {
            $v .= '.fibery.io';
        }
        return preg_match('/^[a-z0-9][a-z0-9-]{0,62}\.fibery\.io$/', $v) === 1 ? $v : null;
    }

    private static function entityUrl(string $ws, string $db, string $publicId): ?string
    {
        if ($publicId === '' || !str_contains($db, '/')) {
            return null;
        }
        [$space, $name] = explode('/', $db, 2);
        $part = static fn(string $x): string => rawurlencode(str_replace(' ', '_', $x));
        return 'https://' . $ws . '/' . $part($space) . '/' . $part($name) . '/' . rawurlencode($publicId);
    }

    /** "Work/Due Date" reads as "Due Date"; "workflow/state" as "State". */
    private static function label(string $field): string
    {
        $tail = str_contains($field, '/') ? substr($field, strrpos($field, '/') + 1) : $field;
        // Fibery's own fields are written creation-date; people's keep their hyphens.
        if (str_starts_with($field, 'fibery/')) {
            $tail = str_replace('-', ' ', $tail);
        }
        return ucfirst($tail);
    }

    private static function dateKindLabel(string $type): string
    {
        return match ($type) {
            'fibery/date' => 'date',
            'fibery/date-time' => 'date and time',
            'fibery/date-range' => 'date range',
            'fibery/date-time-range' => 'date and time range',
            default => $type,
        };
    }

    /** @return array{start:string,end:string,allDay:bool} */
    private static function allDay(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'allDay' => true];
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
};
