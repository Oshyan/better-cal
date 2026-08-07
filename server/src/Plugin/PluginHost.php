<?php

declare(strict_types=1);

namespace BetterCal\Plugin;

use BetterCal\Domain\ActivityContext;
use BetterCal\Domain\Geocode;
use BetterCal\Domain\Sanitize;
use BetterCal\Domain\Undo;
use BetterCal\Infra\Db;
use BetterCal\Infra\HttpClient;
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
    ) {
        $this->startedAt = microtime(true);
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
        $undo = new Undo($this->db);
        $events = new \BetterCal\Domain\Events(
            $this->db,
            new \BetterCal\Domain\Recurrence(),
            $undo,
            new \BetterCal\Domain\Labels($this->db),
            new \BetterCal\Domain\Filters($this->db, $undo, new \BetterCal\Infra\JobQueue($this->db)),
            new \BetterCal\Domain\Trips($this->db, $undo),
        );
        $occs = $events->window(
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

    /** Geocode a place query via the host's geocoder (cached). */
    public function geocode(string $query): ?array
    {
        try {
            $hits = (new Geocode($this->db))->lookup($query);
            return $hits[0] ?? null;
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
