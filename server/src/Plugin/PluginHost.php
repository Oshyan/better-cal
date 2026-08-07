<?php

declare(strict_types=1);

namespace BetterCal\Plugin;

use BetterCal\Domain\ActivityContext;
use BetterCal\Domain\Events;
use BetterCal\Domain\Filters;
use BetterCal\Domain\Geocode;
use BetterCal\Domain\Labels;
use BetterCal\Domain\Proposals;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Sanitize;
use BetterCal\Domain\Trips;
use BetterCal\Domain\Undo;
use BetterCal\Infra\Db;
use BetterCal\Infra\HttpClient;
use BetterCal\Infra\JobQueue;
use BetterCal\Infra\LlmGateway;
use BetterCal\Infra\Notifier;
use BetterCal\Support\Time;

/**
 * Everything a plugin may touch, in one facade (docs/plugins/prd-v1.md).
 * Handed to runJob(); holds the per-run log buffer and the soft time budget.
 *
 * Write surfaces are deliberately narrow: events go only into calendars this
 * plugin owns (feed-style sync with stable source keys), ranges and warnings
 * go into plugin_* tables replaced per run, and every string that can reach
 * the DOM passes the same sanitizer as event descriptions.
 */
final class PluginHost
{
    public const RUN_BUDGET_SECONDS = 60;
    private const MAX_RANGES_PER_SYNC = 2000;

    /** @var list<string> */
    private array $log = [];
    private float $startedAt;

    public function __construct(
        private readonly Db $db,
        private readonly string $pluginId,
        private readonly int $userId,
        private readonly HttpClient $http,
        private readonly array $manifest,
        private readonly array $cfg = [],
        private readonly ?string $runId = null,
    ) {
        $this->startedAt = microtime(true);
    }

    /**
     * Capability gate. Permissions are declared in the manifest and shown on
     * the ops page; for the capabilities that reach OUTSIDE the plugin's own
     * data (notifying the user, spending LLM budget, reading the people
     * directory) the declaration is also enforced here, so a plugin cannot
     * quietly use a power it never disclosed.
     */
    private function requirePermission(string $perm): void
    {
        if (!in_array($perm, $this->manifest['permissions'] ?? [], true)) {
            throw new \RuntimeException(
                "Plugin '{$this->pluginId}' used the '{$perm}' capability without declaring it in plugin.json"
            );
        }
    }

    /** The id grouping every mutation this run makes (undo-as-a-batch). */
    public function runId(): ?string
    {
        return $this->runId;
    }

    // ---- run bookkeeping ------------------------------------------------

    public function log(string $message): void
    {
        $this->log[] = '[' . date('H:i:s') . '] ' . mb_substr($message, 0, 300);
        if (count($this->log) > 100) {
            array_shift($this->log);
        }
    }

    public function logTail(): string
    {
        return implode("\n", $this->log);
    }

    /** Seconds left in the soft budget. Long loops should check and stop. */
    public function budgetRemaining(): float
    {
        return max(0.0, self::RUN_BUDGET_SECONDS - (microtime(true) - $this->startedAt));
    }

    public function overBudget(): bool
    {
        return $this->budgetRemaining() <= 0.0;
    }

    // ---- config ---------------------------------------------------------

    /** Plugin-scope settings: stored values over manifest defaults. */
    public function settings(): array
    {
        $row = $this->db->one('SELECT settings_json FROM plugins WHERE id = ?', [$this->pluginId]);
        $stored = $row && is_string($row['settings_json']) ? (json_decode($row['settings_json'], true) ?: []) : [];
        $out = [];
        foreach (($this->manifest['settings'] ?? []) as $field) {
            $k = (string) $field['key'];
            $out[$k] = array_key_exists($k, $stored) ? $stored[$k] : ($field['default'] ?? null);
        }
        return $out;
    }

    /** Per-calendar values for this plugin (settings_json.plugins.<id>). */
    public function calendarSettings(int $calendarId): array
    {
        $raw = $this->db->scalar('SELECT settings_json FROM calendars WHERE id = ? AND user_id = ?', [$calendarId, $this->userId]);
        $all = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $mine = $all['plugins'][$this->pluginId] ?? [];
        $out = [];
        foreach (($this->manifest['calendarSettings'] ?? []) as $field) {
            $k = (string) $field['key'];
            $out[$k] = array_key_exists($k, $mine) ? $mine[$k] : ($field['default'] ?? null);
        }
        return $out;
    }

    /**
     * The USER's timezone, not the server's. Plugins that build local times
     * (a 9 AM outing, a leave-by clock) must use this: date_default_timezone
     * is whatever the host machine happens to be set to, which is how a plan
     * for "Saturday morning" ends up an ocean away from Saturday morning.
     */
    public function timezone(): \DateTimeZone
    {
        $raw = $this->db->scalar('SELECT settings_json FROM users WHERE id = ?', [$this->userId]);
        $settings = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $tz = is_string($settings['tz'] ?? null) && $settings['tz'] !== '' ? $settings['tz'] : null;
        // No stored setting (the client knows its zone from the browser and
        // never had to tell the server). The events themselves do know: take
        // the zone the user's own recent events are written in. Falling back
        // to the server's zone instead would put a Bay Area user's "Saturday
        // morning" in Berlin.
        if ($tz === null) {
            // UTC is excluded from the vote: a Takeout import writes thousands
            // of rows as UTC, which means "unknown", not "this user lives in
            // Greenwich". Plugin calendars are excluded for the same reason —
            // they are written by machines, in UTC, and would outvote the user.
            $tz = $this->db->scalar(
                "SELECT e.tzid FROM events e JOIN calendars c ON c.id = e.calendar_id
                 WHERE e.user_id = ? AND e.deleted_at IS NULL AND e.tzid <> '' AND e.tzid <> 'UTC'
                   AND c.kind <> 'plugin'
                 GROUP BY e.tzid ORDER BY COUNT(*) DESC LIMIT 1",
                [$this->userId]
            );
            $tz = is_string($tz) && $tz !== '' ? $tz : null;
        }
        try {
            return new \DateTimeZone($tz ?? date_default_timezone_get());
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }

    /** @return list<array{id:int,name:string,kind:string,visible:bool}> the user's calendars */
    public function calendars(): array
    {
        $rows = $this->db->all('SELECT id, name, kind, visible FROM calendars WHERE user_id = ? ORDER BY position, id', [$this->userId]);
        return array_map(static fn($r) => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'kind' => (string) $r['kind'],
            'visible' => (int) $r['visible'] === 1,
        ], $rows);
    }

    /**
     * Read-only occurrence window through the SAME pipeline the calendar
     * uses (recurrence expanded, filters applied). Worker-only like all
     * plugin code, so its cost never touches a page request. Trimmed to the
     * fields an audit needs.
     *
     * @return list<array<string,mixed>>
     */
    public function eventsWindow(string $startIso, string $endIso): array
    {
        $occs = $this->eventsDomain()->window(
            $this->userId,
            Time::parseIso($startIso),
            Time::parseIso($endIso),
            null,
            null,
            false
        );
        return array_map(static fn(array $o) => [
            'eventId' => $o['eventId'],
            'calendarId' => $o['calendarId'],
            'title' => $o['title'],
            'start' => $o['start'],
            'end' => $o['end'],
            'allDay' => $o['allDay'],
            'location' => $o['location'] ?? null,
            'lat' => $o['locationLat'] ?? null,
            'lng' => $o['locationLng'] ?? null,
            'isContainer' => $o['isContainer'] ?? false,
            'recurring' => $o['recurring'] ?? false,
        ], $occs);
    }

    // ---- per-event data (C7) --------------------------------------------

    /**
     * Keyed values attached to one event. These ride the DETAIL fetch, never
     * the events window — the window is 793 bytes/occurrence over thousands of
     * occurrences, and per-event plugin data on that path would undo the whole
     * payload budget. Read them here (worker side) or let the client fetch
     * them when the user opens an event.
     *
     * @return array<string,mixed>
     */
    public function eventData(int $eventId): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT k, v_json FROM event_plugin_data WHERE event_id = ? AND plugin_id = ?',
            [$eventId, $this->pluginId]
        ) as $r) {
            $out[(string) $r['k']] = json_decode((string) $r['v_json'], true);
        }
        return $out;
    }

    public function setEventData(int $eventId, string $key, mixed $value): void
    {
        // Only for events the user owns; a plugin cannot annotate rows that
        // are not on this calendar account.
        $own = $this->db->one('SELECT id FROM events WHERE id = ? AND user_id = ?', [$eventId, $this->userId]);
        if ($own === null) {
            throw new \RuntimeException('setEventData: unknown event ' . $eventId);
        }
        if ($value === null) {
            $this->db->run(
                'DELETE FROM event_plugin_data WHERE event_id = ? AND plugin_id = ? AND k = ?',
                [$eventId, $this->pluginId, $key]
            );
            return;
        }
        $this->db->run(
            'INSERT INTO event_plugin_data (event_id, plugin_id, k, v_json) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE v_json = VALUES(v_json)',
            [$eventId, $this->pluginId, mb_substr($key, 0, 120), json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE)]
        );
    }

    /**
     * Every event carrying a given key, for batch work (an audit that only
     * cares about annotated events shouldn't walk the whole calendar).
     *
     * @return array<int,mixed> event id => value
     */
    public function eventsWithData(string $key): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT d.event_id, d.v_json FROM event_plugin_data d
             JOIN events e ON e.id = d.event_id AND e.user_id = ? AND e.deleted_at IS NULL
             WHERE d.plugin_id = ? AND d.k = ?',
            [$this->userId, $this->pluginId, $key]
        ) as $r) {
            $out[(int) $r['event_id']] = json_decode((string) $r['v_json'], true);
        }
        return $out;
    }

    // ---- people + availability (read-only) -------------------------------

    /** @return list<array{id:int,name:string}> */
    public function people(): array
    {
        $this->requirePermission('people');
        return array_map(
            static fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $this->db->all('SELECT id, name FROM people WHERE user_id = ? ORDER BY name', [$this->userId])
        );
    }

    /**
     * Availability spans overlapping a window, for planners that need to know
     * who is around.
     *
     * @return list<array{personId:int,name:string,kind:string,start:string,end:string}>
     */
    public function availability(string $startIso, string $endIso): array
    {
        $this->requirePermission('people');
        $rows = $this->db->all(
            'SELECT a.person_id, a.start_utc, a.end_utc, a.kind, p.name
             FROM availability a JOIN people p ON p.id = a.person_id
             WHERE p.user_id = ? AND a.start_utc < ? AND a.end_utc > ?
             ORDER BY a.start_utc',
            [$this->userId, Time::toDb(Time::parseIso($endIso)), Time::toDb(Time::parseIso($startIso))]
        );
        return array_map(static fn($r) => [
            'personId' => (int) $r['person_id'],
            'name' => (string) $r['name'],
            'kind' => (string) $r['kind'],
            'start' => Time::iso(Time::fromDb((string) $r['start_utc'])),
            'end' => Time::iso(Time::fromDb((string) $r['end_utc'])),
        ], $rows);
    }

    // ---- notifications (C17) ---------------------------------------------

    /**
     * Notify the user now, through their configured channel. Silent when no
     * channel is configured — a plugin should not crash because push is off.
     *
     * @return array{push:int,email:bool}
     */
    public function notify(string $title, string $body, string $url = '/'): array
    {
        $this->requirePermission('notify');
        return (new Notifier($this->db, $this->cfg))->send(
            $this->userId,
            $title,
            $body,
            $url,
            'plugin:' . $this->pluginId
        );
    }

    // ---- LLM (C18) --------------------------------------------------------

    /**
     * Ask the model for JSON. Returns null when unconfigured OR when the call
     * fails — indistinguishable on purpose, because a plugin must behave the
     * same either way: fall back to something deterministic.
     *
     * @param array<string,mixed> $schemaHint
     */
    public function llmJson(string $prompt, array $schemaHint = []): ?array
    {
        $this->requirePermission('llm');
        $gateway = new LlmGateway($this->cfg);
        if (!$gateway->isConfigured()) {
            $this->log('llm not configured; using fallback');
            return null;
        }
        return $gateway->completeJson($prompt, $schemaHint);
    }

    // ---- proposals (C11) --------------------------------------------------

    /**
     * Offer a plan for the user to accept or reject. Nothing is written to the
     * calendar here — that is the entire point. Re-running replaces your own
     * open proposal with the same sourceKey; one the user already decided is
     * left alone.
     *
     * @param array{sourceKey:string,title:string,summary?:string,rationaleHtml?:string,plan:array} $proposal
     */
    public function propose(array $proposal): array
    {
        $this->requirePermission('propose');
        $proposals = new Proposals(
            $this->db,
            $this->eventsDomain(),
            new Trips($this->db, new Undo($this->db))
        );
        return $proposals->upsert($this->userId, $this->pluginId, $proposal);
    }

    /** This plugin's proposals, so a re-run can see what it already offered. */
    public function myProposals(?string $status = 'open'): array
    {
        $this->requirePermission('propose');
        $sql = 'SELECT source_key, status FROM plugin_proposals WHERE plugin_id = ? AND user_id = ?';
        $params = [$this->pluginId, $this->userId];
        if ($status !== null && $status !== 'all') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        return array_map(
            static fn($r) => ['sourceKey' => (string) $r['source_key'], 'status' => (string) $r['status']],
            $this->db->all($sql, $params)
        );
    }

    /** The fully-wired Events domain (audit reads, proposal materialization). */
    private function eventsDomain(): Events
    {
        $undo = new Undo($this->db);
        return new Events(
            $this->db,
            new Recurrence(),
            $undo,
            new Labels($this->db),
            new Filters($this->db, $undo, new JobQueue($this->db)),
            new Trips($this->db, $undo),
        );
    }

    // ---- storage --------------------------------------------------------

    public function kvGet(string $key, mixed $default = null): mixed
    {
        $raw = $this->db->scalar('SELECT v_json FROM plugin_kv WHERE plugin_id = ? AND k = ?', [$this->pluginId, $key]);
        if (!is_string($raw)) {
            return $default;
        }
        $v = json_decode($raw, true);
        return $v ?? $default;
    }

    public function kvSet(string $key, mixed $value): void
    {
        $this->db->run(
            'INSERT INTO plugin_kv (plugin_id, k, v_json) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE v_json = VALUES(v_json)',
            [$this->pluginId, mb_substr($key, 0, 160), json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE)]
        );
    }

    public function kvDelete(string $key): void
    {
        $this->db->run('DELETE FROM plugin_kv WHERE plugin_id = ? AND k = ?', [$this->pluginId, $key]);
    }

    // ---- network --------------------------------------------------------

    public function http(): HttpClient
    {
        return $this->http;
    }

    // ---- location -------------------------------------------------------

    /**
     * Geocode a place query via the host's geocoder (cached).
     *
     * Returns ['lat'=>float,'lng'=>float,'display'=>string], or null when the
     * place is not found or the provider is unreachable.
     *
     * Pass biasLat/biasLng to disambiguate. Without a bias the provider ranks
     * globally, so a bare venue name lands wherever the best string match is —
     * "Greens Restaurant Fort Mason" resolves to Toronto. Plugins that geocode
     * user-typed place names should bias toward the region they mean.
     */
    public function geocode(string $query, ?float $biasLat = null, ?float $biasLng = null): ?array
    {
        try {
            // lookup() answers a single {lat,lng,display} map, not a hit list,
            // and signals "not found" by nulling the fields rather than by
            // returning empty. Indexing it like a list yields null every time.
            $hit = (new Geocode($this->db))->lookup($query, $biasLat, $biasLng);
            return isset($hit['lat'], $hit['lng']) ? $hit : null;
        } catch (\Throwable $e) {
            $this->log('geocode failed: ' . $e->getMessage());
            return null;
        }
    }

    // ---- output: owned calendar + events --------------------------------

    /** Find or create this plugin's calendar of the given name. */
    public function ensureCalendar(string $name, string $color): int
    {
        $row = $this->db->one(
            "SELECT id FROM calendars WHERE user_id = ? AND kind = 'plugin' AND plugin_id = ? AND name = ?",
            [$this->userId, $this->pluginId, $name]
        );
        if ($row !== null) {
            return (int) $row['id'];
        }
        $id = $this->db->insert('calendars', [
            'user_id' => $this->userId,
            'name' => mb_substr($name, 0, 160),
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '#5b8dd9',
            'kind' => 'plugin',
            'plugin_id' => $this->pluginId,
        ]);
        (new Undo($this->db))->record(
            $this->userId,
            'calendar',
            $id,
            'create',
            null,
            null,
            "Plugin '" . $this->pluginId . "' created calendar '" . $name . "'"
        );
        return $id;
    }

    /**
     * Feed-style sync into an OWNED calendar: upsert by stable sourceKey,
     * delete rows whose key disappeared. Events: {sourceKey, title, start,
     * end, allDay?, description?, location?}. start/end: 'YYYY-MM-DD' for
     * all-day (end exclusive) or ISO instants. Returns [added, updated,
     * removed].
     *
     * @param list<array<string,mixed>> $events
     * @return array{0:int,1:int,2:int}
     */
    public function syncEvents(int $calendarId, array $events): array
    {
        $cal = $this->db->one(
            "SELECT id FROM calendars WHERE id = ? AND user_id = ? AND kind = 'plugin' AND plugin_id = ?",
            [$calendarId, $this->userId, $this->pluginId]
        );
        if ($cal === null) {
            throw new \RuntimeException('syncEvents: calendar ' . $calendarId . ' is not owned by ' . $this->pluginId);
        }
        $existing = [];
        foreach ($this->db->all('SELECT id, uid, title, start_utc, end_utc, all_day, description, location FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$calendarId]) as $r) {
            $existing[(string) $r['uid']] = $r;
        }
        $added = 0;
        $updated = 0;
        $seen = [];
        foreach ($events as $ev) {
            $key = 'plg-' . $this->pluginId . '-' . mb_substr((string) ($ev['sourceKey'] ?? ''), 0, 100);
            if (($ev['sourceKey'] ?? '') === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            [$startUtc, $endUtc, $allDay] = self::spanToUtc($ev);
            $cols = [
                'title' => mb_substr(Sanitize::toText((string) ($ev['title'] ?? '')), 0, 500),
                'description' => isset($ev['description']) ? Sanitize::description((string) $ev['description']) : null,
                'location' => isset($ev['location']) ? mb_substr(Sanitize::toText((string) $ev['location']), 0, 300) : null,
                'start_utc' => $startUtc,
                'end_utc' => $endUtc,
                'all_day' => $allDay ? 1 : 0,
                'tzid' => 'UTC',
            ];
            $cur = $existing[$key] ?? null;
            if ($cur === null) {
                $this->db->insert('events', $cols + [
                    'user_id' => $this->userId,
                    'calendar_id' => $calendarId,
                    'uid' => $key,
                    'source' => 'local',
                    'created_via' => 'plugin:' . $this->pluginId,
                ]);
                $added++;
            } else {
                $changed = [];
                foreach ($cols as $c => $v) {
                    if ((string) ($cur[$c] ?? '') !== (string) ($v ?? '')) {
                        $changed[$c] = $v;
                    }
                }
                if ($changed !== []) {
                    $changed['updated_at'] = Time::nowDb();
                    $this->db->update('events', $changed, 'id = ?', [(int) $cur['id']]);
                    $updated++;
                }
            }
        }
        $removed = 0;
        foreach ($existing as $uid => $r) {
            if (!isset($seen[$uid])) {
                $this->db->run('DELETE FROM events WHERE id = ?', [(int) $r['id']]);
                $removed++;
            }
        }
        if ($added + $updated + $removed > 0) {
            ActivityContext::with('plugin:' . $this->pluginId, function () use ($calendarId, $added, $updated, $removed): void {
                (new Undo($this->db))->record(
                    $this->userId,
                    'calendar',
                    $calendarId,
                    'update',
                    null,
                    null,
                    ucfirst($this->pluginId) . ' sync: ' . $added . ' added, ' . $updated . ' updated, ' . $removed . ' removed'
                );
            });
        }
        return [$added, $updated, $removed];
    }

    // ---- output: overlay ranges ----------------------------------------

    /**
     * Replace this plugin's ranges wholesale (the common case for a refresh
     * job). Ranges: {sourceKey, start, end, label?, color?, detailHtml?}.
     * start/end 'YYYY-MM-DD' (end exclusive) or ISO instants.
     *
     * @param list<array<string,mixed>> $ranges
     */
    public function replaceRanges(array $ranges): int
    {
        if (count($ranges) > self::MAX_RANGES_PER_SYNC) {
            $this->log('ranges truncated: ' . count($ranges) . ' -> ' . self::MAX_RANGES_PER_SYNC);
            $ranges = array_slice($ranges, 0, self::MAX_RANGES_PER_SYNC);
        }
        $this->db->run('DELETE FROM plugin_ranges WHERE plugin_id = ?', [$this->pluginId]);
        $n = 0;
        $seen = [];
        foreach ($ranges as $r) {
            $key = mb_substr((string) ($r['sourceKey'] ?? ''), 0, 160);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            [$startUtc, $endUtc] = self::rangeToUtc($r);
            $this->db->insert('plugin_ranges', [
                'plugin_id' => $this->pluginId,
                'source_key' => $key,
                'start_utc' => $startUtc,
                'end_utc' => $endUtc,
                'label' => mb_substr(Sanitize::toText((string) ($r['label'] ?? '')), 0, 200),
                'color' => isset($r['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $r['color']) === 1 ? (string) $r['color'] : null,
                'detail_html' => isset($r['detailHtml']) ? Sanitize::html((string) $r['detailHtml']) : null,
            ]);
            $n++;
        }
        return $n;
    }

    // ---- output: warnings ----------------------------------------------

    /**
     * Replace this plugin's warnings (an audit rewrites its findings).
     * Warnings: {message, severity?, eventId?, fix?}.
     *
     * @param list<array<string,mixed>> $warnings
     */
    public function replaceWarnings(array $warnings): int
    {
        $this->db->run('DELETE FROM plugin_warnings WHERE plugin_id = ?', [$this->pluginId]);
        $n = 0;
        foreach (array_slice($warnings, 0, 500) as $w) {
            $msg = mb_substr(Sanitize::toText((string) ($w['message'] ?? '')), 0, 500);
            if ($msg === '') {
                continue;
            }
            $this->db->insert('plugin_warnings', [
                'plugin_id' => $this->pluginId,
                'event_id' => isset($w['eventId']) ? (int) $w['eventId'] : null,
                'severity' => ($w['severity'] ?? 'warn') === 'info' ? 'info' : 'warn',
                'message' => $msg,
                'fix_text' => isset($w['fix']) ? mb_substr(Sanitize::toText((string) $w['fix']), 0, 300) : null,
            ]);
            $n++;
        }
        return $n;
    }

    // ---- helpers --------------------------------------------------------

    /** @return array{0:string,1:string,2:bool} [startUtc, endUtc, allDay] */
    private static function spanToUtc(array $ev): array
    {
        $start = (string) ($ev['start'] ?? '');
        $end = (string) ($ev['end'] ?? '');
        $allDay = (bool) ($ev['allDay'] ?? (strlen($start) === 10));
        if ($allDay) {
            $s = $start . ' 00:00:00';
            $e = (strlen($end) === 10 ? $end : $start) . ' 00:00:00';
            if ($e <= $s) {
                $e = gmdate('Y-m-d', strtotime($start . ' +1 day')) . ' 00:00:00';
            }
            return [$s, $e, true];
        }
        $s = Time::toDb(new \DateTimeImmutable($start));
        $e = $end !== '' ? Time::toDb(new \DateTimeImmutable($end)) : $s;
        return [$s, max($e, $s), false];
    }

    /** @return array{0:string,1:string} */
    private static function rangeToUtc(array $r): array
    {
        $start = (string) ($r['start'] ?? '');
        $end = (string) ($r['end'] ?? '');
        $s = strlen($start) === 10 ? $start . ' 00:00:00' : Time::toDb(new \DateTimeImmutable($start));
        $e = strlen($end) === 10 ? $end . ' 00:00:00' : Time::toDb(new \DateTimeImmutable($end));
        return [$s, max($e, $s)];
    }
}
