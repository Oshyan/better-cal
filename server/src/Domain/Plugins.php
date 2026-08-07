<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\HttpClient;
use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;
use BetterCal\Support\Time;

/**
 * Plugin registry, lifecycle, settings, and the job runner
 * (docs/plugins/prd-v1.md). A plugin is a directory under server/plugins/
 * with plugin.json + Plugin.php; installing means the directory exists,
 * enabling is a DB flag. Plugin code executes only through runJob (worker)
 * and validateSettings (explicit save) — never on a page-render path.
 */
final class Plugins
{
    public const HOST_VERSION = '0.1.0';
    public const FAILURE_DISABLE_THRESHOLD = 5;
    public const RANGE_RESPONSE_CAP = 500; // per plugin per window request
    private const FIELD_TYPES = ['text', 'number', 'select', 'toggle', 'location', 'person'];

    public function __construct(
        private readonly Db $db,
        private readonly ?string $pluginsDir = null,
    ) {
    }

    private function dir(): string
    {
        return $this->pluginsDir ?? dirname(__DIR__, 2) . '/plugins';
    }

    // ---- manifests ------------------------------------------------------

    /**
     * Validate a decoded manifest. Returns a list of problems; empty = valid.
     * Pure; unit-tested.
     *
     * @return list<string>
     */
    public static function manifestErrors(mixed $m): array
    {
        $errs = [];
        if (!is_array($m)) {
            return ['manifest is not an object'];
        }
        $id = $m['id'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9-]{1,62}[a-z0-9]$/', $id) !== 1) {
            $errs[] = 'id must be lowercase kebab-case (3-64 chars)';
        }
        if (!is_string($m['name'] ?? null) || trim((string) ($m['name'] ?? '')) === '') {
            $errs[] = 'name is required';
        }
        if (!is_string($m['version'] ?? null) || preg_match('/^\d+\.\d+\.\d+$/', (string) ($m['version'] ?? '')) !== 1) {
            $errs[] = 'version must be semver (x.y.z)';
        }
        if (isset($m['minHost']) && version_compare(self::HOST_VERSION, (string) $m['minHost'], '<')) {
            $errs[] = 'requires host >= ' . $m['minHost'] . ' (this is ' . self::HOST_VERSION . ')';
        }
        foreach (($m['permissions'] ?? []) as $p) {
            if (!in_array($p, ['http', 'events:write', 'ranges', 'warnings', 'geocode'], true)) {
                $errs[] = 'unknown permission: ' . (is_string($p) ? $p : gettype($p));
            }
        }
        $jobs = $m['jobs'] ?? [];
        if (!is_array($jobs)) {
            $errs[] = 'jobs must be an array';
        } else {
            foreach ($jobs as $j) {
                if (!is_array($j) || !is_string($j['id'] ?? null) || preg_match('/^[a-z][a-z0-9-]*$/', (string) ($j['id'] ?? '')) !== 1) {
                    $errs[] = 'each job needs a kebab-case id';
                    continue;
                }
                try {
                    new \DateInterval((string) ($j['interval'] ?? ''));
                } catch (\Throwable) {
                    $errs[] = 'job ' . $j['id'] . ': interval must be an ISO 8601 duration (e.g. PT3H)';
                }
            }
        }
        foreach (['settings', 'calendarSettings'] as $section) {
            foreach (($m[$section] ?? []) as $f) {
                if (!is_array($f) || !is_string($f['key'] ?? null) || !is_string($f['label'] ?? null)) {
                    $errs[] = $section . ': each field needs key + label';
                    continue;
                }
                if (!in_array($f['type'] ?? null, self::FIELD_TYPES, true)) {
                    $errs[] = $section . '.' . $f['key'] . ': type must be one of ' . implode('/', self::FIELD_TYPES);
                }
                if (($f['type'] ?? null) === 'select' && (!isset($f['options']) || !is_array($f['options']) || $f['options'] === [])) {
                    $errs[] = $section . '.' . $f['key'] . ': select needs options';
                }
            }
        }
        return $errs;
    }

    /**
     * Scan the plugins directory. Each entry: manifest fields + install/run
     * state + validity. Invalid manifests are listed (with errors) so the ops
     * page can show WHY a directory is not installable, not silently skipped.
     *
     * @return list<array<string,mixed>>
     */
    public function scan(): array
    {
        $out = [];
        foreach (glob($this->dir() . '/*/plugin.json') ?: [] as $file) {
            $dirId = basename(dirname($file));
            $decoded = json_decode((string) @file_get_contents($file), true);
            $errs = self::manifestErrors($decoded);
            if (is_array($decoded) && ($decoded['id'] ?? null) !== $dirId) {
                $errs[] = 'id must match directory name (' . $dirId . ')';
            }
            if (!is_file(dirname($file) . '/Plugin.php')) {
                $errs[] = 'Plugin.php missing';
            }
            $out[] = [
                'id' => $dirId,
                'manifest' => is_array($decoded) ? $decoded : [],
                'errors' => $errs,
            ];
        }
        usort($out, static fn($a, $b) => strcmp((string) $a['id'], (string) $b['id']));
        return $out;
    }

    public function manifest(string $id): ?array
    {
        foreach ($this->scan() as $p) {
            if ($p['id'] === $id && $p['errors'] === []) {
                return $p['manifest'];
            }
        }
        return null;
    }

    // ---- state + ops listing -------------------------------------------

    /** Full ops-page listing: manifests joined with state, runs, counts. */
    public function listForOps(int $userId): array
    {
        $states = [];
        foreach ($this->db->all('SELECT * FROM plugins') as $r) {
            $states[(string) $r['id']] = $r;
        }
        $out = [];
        foreach ($this->scan() as $p) {
            $id = (string) $p['id'];
            $st = $states[$id] ?? null;
            $m = $p['manifest'];
            $lastRun = $this->db->one('SELECT * FROM plugin_runs WHERE plugin_id = ? ORDER BY id DESC LIMIT 1', [$id]);
            $out[] = [
                'id' => $id,
                'name' => (string) ($m['name'] ?? $id),
                'version' => (string) ($m['version'] ?? '0.0.0'),
                'description' => (string) ($m['description'] ?? ''),
                'permissions' => array_values($m['permissions'] ?? []),
                'jobs' => array_values($m['jobs'] ?? []),
                'settingsSchema' => array_values($m['settings'] ?? []),
                'calendarSettingsSchema' => array_values($m['calendarSettings'] ?? []),
                'errors' => $p['errors'],
                'installed' => $st !== null,
                'enabled' => $st !== null && (int) $st['enabled'] === 1,
                'disabledReason' => $st !== null ? $st['disabled_reason'] : null,
                'consecutiveFailures' => $st !== null ? (int) $st['consecutive_failures'] : 0,
                'settings' => $this->settingsValues($id, $m, $st),
                'lastRun' => $lastRun === null ? null : [
                    'job' => (string) $lastRun['job_id'],
                    'at' => Time::iso(Time::fromDb((string) $lastRun['started_at'])),
                    'durationMs' => $lastRun['duration_ms'] !== null ? (int) $lastRun['duration_ms'] : null,
                    'outcome' => (string) $lastRun['outcome'],
                    'logTail' => (string) ($lastRun['log_tail'] ?? ''),
                ],
                'counts' => $this->impact($userId, $id),
            ];
        }
        return $out;
    }

    /** @return array{events:int,calendars:int,ranges:int,warnings:int,kv:int} */
    public function impact(int $userId, string $id): array
    {
        $calIds = array_map(
            static fn($r) => (int) $r['id'],
            $this->db->all("SELECT id FROM calendars WHERE user_id = ? AND kind = 'plugin' AND plugin_id = ?", [$userId, $id])
        );
        $events = 0;
        foreach ($calIds as $cid) {
            $events += (int) $this->db->one('SELECT COUNT(*) c FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$cid])['c'];
        }
        return [
            'events' => $events,
            'calendars' => count($calIds),
            'ranges' => (int) $this->db->one('SELECT COUNT(*) c FROM plugin_ranges WHERE plugin_id = ?', [$id])['c'],
            'warnings' => (int) $this->db->one('SELECT COUNT(*) c FROM plugin_warnings WHERE plugin_id = ? AND dismissed_at IS NULL', [$id])['c'],
            'kv' => (int) $this->db->one('SELECT COUNT(*) c FROM plugin_kv WHERE plugin_id = ?', [$id])['c'],
        ];
    }

    private function settingsValues(string $id, array $manifest, ?array $stateRow): array
    {
        $stored = $stateRow !== null && is_string($stateRow['settings_json'])
            ? (json_decode((string) $stateRow['settings_json'], true) ?: [])
            : [];
        $out = [];
        foreach (($manifest['settings'] ?? []) as $f) {
            $k = (string) $f['key'];
            $out[$k] = array_key_exists($k, $stored) ? $stored[$k] : ($f['default'] ?? null);
        }
        return $out;
    }

    // ---- lifecycle ------------------------------------------------------

    public function enable(string $id): void
    {
        $m = $this->manifest($id);
        if ($m === null) {
            throw HttpError::badRequest('Unknown or invalid plugin: ' . $id);
        }
        $this->db->run(
            'INSERT INTO plugins (id, version, enabled, consecutive_failures, disabled_reason)
             VALUES (?, ?, 1, 0, NULL)
             ON DUPLICATE KEY UPDATE enabled = 1, version = VALUES(version), consecutive_failures = 0, disabled_reason = NULL',
            [$id, (string) $m['version']]
        );
    }

    public function disable(string $id, ?string $reason = null): void
    {
        $this->db->run('UPDATE plugins SET enabled = 0, disabled_reason = ? WHERE id = ?', [$reason, $id]);
    }

    /**
     * Uninstall: purge ranges/warnings/runs/kv always; owned calendars are
     * deleted (with their events) or archived to kind 'local' + hidden, per
     * the caller's choice. The activity journal keeps its history either way.
     */
    public function uninstall(int $userId, string $id, bool $deleteCalendars): array
    {
        $impact = $this->impact($userId, $id);
        $cals = $this->db->all("SELECT id, name FROM calendars WHERE user_id = ? AND kind = 'plugin' AND plugin_id = ?", [$userId, $id]);
        foreach ($cals as $cal) {
            $cid = (int) $cal['id'];
            if ($deleteCalendars) {
                $this->db->run('DELETE FROM events WHERE calendar_id = ?', [$cid]);
                $this->db->run('DELETE FROM calendars WHERE id = ?', [$cid]);
            } else {
                // Archive: an ordinary hidden local calendar named for its origin.
                $this->db->run(
                    "UPDATE calendars SET kind = 'local', plugin_id = NULL, visible = 0, name = CONCAT(name, ' (archived)') WHERE id = ?",
                    [$cid]
                );
            }
        }
        foreach (['plugin_ranges', 'plugin_warnings', 'plugin_runs', 'plugin_kv'] as $t) {
            $this->db->run("DELETE FROM {$t} WHERE plugin_id = ?", [$id]);
        }
        $this->db->run('DELETE FROM plugins WHERE id = ?', [$id]);
        ActivityContext::with('plugin:' . $id, function () use ($userId, $id, $impact, $deleteCalendars): void {
            (new Undo($this->db))->record(
                $userId,
                'calendar',
                0,
                'delete',
                null,
                null,
                "Uninstalled plugin '" . $id . "' (" . $impact['events'] . ' events '
                    . ($deleteCalendars ? 'deleted' : 'archived') . ', ' . $impact['ranges'] . ' ranges purged)'
            );
        });
        return $impact;
    }

    // ---- settings -------------------------------------------------------

    /**
     * Validate values against a schema (shared by plugin- and calendar-scope).
     * Returns [cleanValues, errors]. Pure; unit-tested.
     *
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    public static function validateAgainstSchema(array $schema, array $values): array
    {
        $clean = [];
        $errs = [];
        foreach ($schema as $f) {
            $k = (string) $f['key'];
            if (!array_key_exists($k, $values)) {
                continue;
            }
            $v = $values[$k];
            switch ($f['type']) {
                case 'number':
                    if (!is_numeric($v)) {
                        $errs[$k] = 'must be a number';
                        break;
                    }
                    $v = $v + 0;
                    if (isset($f['min']) && $v < $f['min']) {
                        $errs[$k] = 'minimum ' . $f['min'];
                    } elseif (isset($f['max']) && $v > $f['max']) {
                        $errs[$k] = 'maximum ' . $f['max'];
                    } else {
                        $clean[$k] = $v;
                    }
                    break;
                case 'toggle':
                    $clean[$k] = filter_var($v, FILTER_VALIDATE_BOOL);
                    break;
                case 'select':
                    $opts = array_map(
                        static fn($o) => is_array($o) ? (string) ($o['value'] ?? '') : (string) $o,
                        $f['options'] ?? []
                    );
                    if (!in_array((string) $v, $opts, true)) {
                        $errs[$k] = 'must be one of ' . implode(', ', $opts);
                    } else {
                        $clean[$k] = (string) $v;
                    }
                    break;
                case 'location':
                    // {name, lat, lng} from the place picker, or null to clear.
                    if ($v === null) {
                        $clean[$k] = null;
                    } elseif (is_array($v) && is_numeric($v['lat'] ?? null) && is_numeric($v['lng'] ?? null)) {
                        $clean[$k] = [
                            'name' => mb_substr((string) ($v['name'] ?? ''), 0, 200),
                            'lat' => (float) $v['lat'],
                            'lng' => (float) $v['lng'],
                        ];
                    } else {
                        $errs[$k] = 'must be a place (name + coordinates)';
                    }
                    break;
                case 'person':
                    $clean[$k] = $v === null ? null : mb_substr((string) $v, 0, 120);
                    break;
                default: // text
                    $clean[$k] = mb_substr((string) $v, 0, 500);
            }
        }
        return [$clean, $errs];
    }

    public function saveSettings(string $id, array $values): array
    {
        $m = $this->manifest($id);
        if ($m === null) {
            throw HttpError::badRequest('Unknown plugin');
        }
        [$clean, $errs] = self::validateAgainstSchema($m['settings'] ?? [], $values);
        if ($errs === []) {
            // Plugin-level validation hook (short, synchronous, explicit save).
            try {
                $instance = $this->load($id);
                $errs = $instance ? $instance->validateSettings($clean) : [];
            } catch (\Throwable $e) {
                $errs = ['_' => 'plugin validation failed: ' . $e->getMessage()];
            }
        }
        if ($errs !== []) {
            throw HttpError::badRequest('Invalid settings: ' . json_encode($errs));
        }
        $row = $this->db->one('SELECT settings_json FROM plugins WHERE id = ?', [$id]);
        if ($row === null) {
            throw HttpError::badRequest('Plugin is not installed (enable it first)');
        }
        $stored = is_string($row['settings_json']) ? (json_decode((string) $row['settings_json'], true) ?: []) : [];
        $this->db->run(
            'UPDATE plugins SET settings_json = ? WHERE id = ?',
            [json_encode(array_merge($stored, $clean)), $id]
        );
        return array_merge($stored, $clean);
    }

    /** Calendar-scope values for one plugin, validated, merged into settings_json.plugins.<id>. */
    public function saveCalendarSettings(int $userId, int $calendarId, string $id, array $values): array
    {
        $m = $this->manifest($id);
        if ($m === null) {
            throw HttpError::badRequest('Unknown plugin');
        }
        [$clean, $errs] = self::validateAgainstSchema($m['calendarSettings'] ?? [], $values);
        if ($errs !== []) {
            throw HttpError::badRequest('Invalid settings: ' . json_encode($errs));
        }
        $raw = $this->db->scalar('SELECT settings_json FROM calendars WHERE id = ? AND user_id = ?', [$calendarId, $userId]);
        if ($raw === null && $this->db->one('SELECT id FROM calendars WHERE id = ? AND user_id = ?', [$calendarId, $userId]) === null) {
            throw HttpError::badRequest('Unknown calendar');
        }
        $all = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $all['plugins'][$id] = array_merge($all['plugins'][$id] ?? [], $clean);
        $this->db->run('UPDATE calendars SET settings_json = ? WHERE id = ?', [json_encode($all), $calendarId]);
        return $all['plugins'][$id];
    }

    // ---- execution ------------------------------------------------------

    private function load(string $id): ?PluginInterface
    {
        $file = $this->dir() . '/' . basename($id) . '/Plugin.php';
        if (!is_file($file)) {
            return null;
        }
        $instance = require $file;
        return $instance instanceof PluginInterface ? $instance : null;
    }

    /**
     * Run one job now (worker lane and "run now"). Records the run, feeds the
     * circuit breaker, auto-disables after FAILURE_DISABLE_THRESHOLD
     * consecutive failures.
     */
    public function runJob(int $userId, string $id, string $jobId): array
    {
        $state = $this->db->one('SELECT * FROM plugins WHERE id = ? AND enabled = 1', [$id]);
        $m = $this->manifest($id);
        if ($state === null || $m === null) {
            return ['outcome' => 'error', 'error' => 'plugin not enabled or invalid'];
        }
        $instance = $this->load($id);
        if ($instance === null) {
            return ['outcome' => 'error', 'error' => 'Plugin.php missing or does not return a PluginInterface'];
        }
        $host = new PluginHost($this->db, $id, $userId, new HttpClient(), $m);
        $t0 = microtime(true);
        $startedAt = Time::nowDb();
        $outcome = 'ok';
        $error = null;
        try {
            ActivityContext::with('plugin:' . $id, fn() => $instance->runJob($host, $jobId));
            if ($host->overBudget()) {
                $outcome = 'timeout';
                $error = 'exceeded ' . PluginHost::RUN_BUDGET_SECONDS . 's soft budget';
            }
        } catch (\Throwable $e) {
            $outcome = 'error';
            $error = mb_substr($e->getMessage(), 0, 300);
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $this->db->insert('plugin_runs', [
            'plugin_id' => $id,
            'job_id' => $jobId,
            'started_at' => $startedAt,
            'duration_ms' => $ms,
            'outcome' => $outcome,
            'log_tail' => mb_substr(trim($host->logTail() . ($error !== null ? "\nERROR: " . $error : '')), 0, 4000),
        ]);
        $this->db->run('DELETE FROM plugin_runs WHERE plugin_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM plugin_runs WHERE plugin_id = ? ORDER BY id DESC LIMIT 20) keep)', [$id, $id]);

        if ($outcome === 'ok') {
            $this->db->run('UPDATE plugins SET consecutive_failures = 0 WHERE id = ?', [$id]);
        } else {
            $fails = (int) $state['consecutive_failures'] + 1;
            $this->db->run('UPDATE plugins SET consecutive_failures = ? WHERE id = ?', [$fails, $id]);
            if ($fails >= self::FAILURE_DISABLE_THRESHOLD) {
                $this->disable($id, 'auto-disabled after ' . $fails . ' consecutive failures (' . $error . ')');
            }
        }
        return ['outcome' => $outcome, 'error' => $error, 'durationMs' => $ms];
    }

    /**
     * Which jobs are due? Consulted by the worker each tick; staleness is
     * judged from plugin_runs so a failing job still respects its interval.
     *
     * @return list<array{plugin:string,job:string}>
     */
    public function dueJobs(): array
    {
        $due = [];
        $now = Time::nowUtc();
        foreach ($this->db->all('SELECT id FROM plugins WHERE enabled = 1') as $row) {
            $id = (string) $row['id'];
            $m = $this->manifest($id);
            if ($m === null) {
                continue;
            }
            foreach (($m['jobs'] ?? []) as $job) {
                $jobId = (string) $job['id'];
                $last = $this->db->scalar(
                    'SELECT started_at FROM plugin_runs WHERE plugin_id = ? AND job_id = ? ORDER BY id DESC LIMIT 1',
                    [$id, $jobId]
                );
                try {
                    $interval = new \DateInterval((string) $job['interval']);
                } catch (\Throwable) {
                    continue;
                }
                if ($last === null || Time::fromDb((string) $last)->add($interval) <= $now) {
                    $due[] = ['plugin' => $id, 'job' => $jobId];
                }
            }
        }
        return $due;
    }

    // ---- read paths (request handlers; no plugin code) ------------------

    /** Overlay ranges for a window: one indexed query, capped per plugin. */
    public function rangesForWindow(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $enabled = array_map(static fn($r) => (string) $r['id'], $this->db->all('SELECT id FROM plugins WHERE enabled = 1'));
        $out = [];
        foreach ($enabled as $id) {
            $rows = $this->db->all(
                'SELECT id, source_key, start_utc, end_utc, label, color, detail_html FROM plugin_ranges
                 WHERE plugin_id = ? AND start_utc < ? AND end_utc > ? ORDER BY start_utc LIMIT ' . (self::RANGE_RESPONSE_CAP + 1),
                [$id, Time::toDb($end), Time::toDb($start)]
            );
            $truncated = count($rows) > self::RANGE_RESPONSE_CAP;
            if ($truncated) {
                array_pop($rows);
            }
            $out[$id] = [
                'truncated' => $truncated,
                'ranges' => array_map(static fn($r) => [
                    'id' => (int) $r['id'],
                    'start' => Time::iso(Time::fromDb((string) $r['start_utc'])),
                    'end' => Time::iso(Time::fromDb((string) $r['end_utc'])),
                    'label' => (string) $r['label'],
                    'color' => $r['color'] !== null ? (string) $r['color'] : null,
                    'detailHtml' => $r['detail_html'] !== null ? (string) $r['detail_html'] : null,
                ], $rows),
            ];
        }
        return $out;
    }

    public function warnings(string $id): array
    {
        return array_map(static fn($r) => [
            'id' => (int) $r['id'],
            'eventId' => $r['event_id'] !== null ? (int) $r['event_id'] : null,
            'severity' => (string) $r['severity'],
            'message' => (string) $r['message'],
            'fix' => $r['fix_text'] !== null ? (string) $r['fix_text'] : null,
        ], $this->db->all('SELECT * FROM plugin_warnings WHERE plugin_id = ? AND dismissed_at IS NULL ORDER BY severity DESC, id', [$id]));
    }
}
