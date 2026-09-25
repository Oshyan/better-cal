<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\HttpClient;
use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;
use BetterCal\Support\Ids;
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
    private const FIELD_TYPES = ['text', 'textarea', 'number', 'select', 'toggle', 'location', 'person'];
    public const TEXT_MAX = 500;       // single-line
    public const TEXTAREA_MAX = 10000; // list-shaped settings live here
    /** Declarative chip animations a plugin may request (host-provided CSS). */
    public const ANIMATIONS = ['none', 'pulse', 'shimmer'];

    /**
     * The host icon set a plugin's `decoration.icon` may name. Mirrors the keys
     * in web/src/ui/icons.js; a test asserts the two stay in step, because a
     * silently-unrenderable icon name is exactly the failure this validation
     * exists to prevent.
     */
    public const ICON_NAMES = [
        'settings', 'outfeeds', 'filters', 'plus', 'search', 'quickadd', 'close',
        'chevronLeft', 'chevronRight', 'chevronUp', 'arrowUpRight', 'check', 'star',
        'video', 'warning', 'stack', 'menu', 'chevronDown', 'brand', 'activity',
        'views', 'folder', 'mixed', 'visAll', 'visNone', 'reschedule', 'pencil',
        'trash', 'arrowLeft', 'note', 'keyboard', 'expand', 'bell', 'activeOnly',
        'lock', 'unlock', 'calendar', 'today', 'viewMonth', 'viewWeek',
        'viewWeeks3', 'viewWeeks2', 'viewDay', 'viewAgenda', 'viewSplit', 'gapDays', 'jumpDate', 'plugins',
        'proposals', 'review', 'mail', 'arrowRight', 'people', 'trip', 'google',
        // Information icons, for context events (a plugin sets one per event).
        'sun', 'sunrise', 'sunset', 'air', 'tideHigh', 'tideLow', 'cloud', 'rain', 'snow', 'storm', 'thermometer', 'moon', 'flag',
    ];

    /**
     * Validate a decoration icon: either a name from the host set, or a literal
     * glyph (an emoji). Anything shaped like an identifier is treated as a name
     * and must exist, so "map-pin" fails loudly instead of rendering nothing.
     *
     * Returns null when valid, else the reason.
     */
    /** Longest SVG path a plugin may ship, in characters. */
    public const ICON_PATH_MAX = 2000;

    /**
     * Validate a plugin-supplied icon as SVG PATH DATA, never as markup.
     *
     * A plugin ships the `d` string and the host builds the <svg> around it
     * with its own viewBox, sizing and currentColor stroke. That keeps the
     * architecture's "declarative only, plugins ship data and never markup"
     * rule intact: there is no element to carry a <script>, a <foreignObject>,
     * an xlink:href, or an external reference, because the plugin never
     * supplies an element. The grammar below is the entire SVG path alphabet
     * plus numbers, so anything else — including angle brackets, quotes,
     * ampersands and parentheses — cannot survive.
     *
     * The coordinate space is a 16x16 box, matching the host icon set; the
     * viewBox clips anything drawn outside it.
     */
    public static function iconPathError(mixed $path): ?string
    {
        if ($path === null) {
            return null;
        }
        if (!is_string($path) || trim($path) === '') {
            return 'must be an SVG path string';
        }
        $path = trim($path);
        if (strlen($path) > self::ICON_PATH_MAX) {
            return 'is longer than ' . self::ICON_PATH_MAX . ' characters';
        }
        if (preg_match('/^[MmLlHhVvCcSsQqTtAaZz0-9,.\-+eE\s]+$/', $path) !== 1) {
            return 'may contain only SVG path commands and numbers';
        }
        if (preg_match('/^[Mm]/', $path) !== 1) {
            return 'must begin with a moveto (M or m)';
        }
        return null;
    }

    public static function iconError(mixed $icon): ?string
    {
        if ($icon === null) {
            return null;
        }
        if (!is_string($icon) || trim($icon) === '') {
            return 'must be a host icon name or a single glyph';
        }
        $icon = trim($icon);
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $icon) === 1) {
            return in_array($icon, self::ICON_NAMES, true)
                ? null
                : 'is not a host icon: ' . $icon . ' (see docs/plugins/authoring.md for the set)';
        }
        // A literal glyph. Allow ZWJ sequences and variation selectors, but not
        // a whole label smuggled in as "text".
        return mb_strlen($icon) <= 8 ? null : 'glyph is too long';
    }

    public function __construct(
        private readonly Db $db,
        private readonly ?string $pluginsDir = null,
        private readonly array $cfg = [],
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
            if (!in_array($p, ['http', 'events:write', 'ranges', 'warnings', 'geocode', 'people', 'notify', 'llm', 'propose'], true)) {
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
        // Declared dependencies on other plugins. `requires` is enforced at
        // enable time; `optional` is disclosure, for a plugin that degrades
        // gracefully (uses another's published data when present, does its own
        // work when not).
        foreach (['requires', 'optional'] as $rel) {
            if (!isset($m[$rel])) {
                continue;
            }
            if (!is_array($m[$rel]) || !array_is_list($m[$rel])) {
                $errs[] = $rel . ' must be a list of plugin ids';
                continue;
            }
            foreach ($m[$rel] as $dep) {
                if (!is_string($dep) || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $dep) !== 1) {
                    $errs[] = $rel . ': "' . (is_string($dep) ? $dep : gettype($dep)) . '" is not a plugin id';
                } elseif ($dep === ($m['id'] ?? null)) {
                    $errs[] = $rel . ': a plugin cannot depend on itself';
                }
            }
        }
        foreach (['settings', 'calendarSettings', 'eventSettings'] as $section) {
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
                // A default longer than its own field could never be re-saved
                // by the user once they edited it, so refuse it at install.
                if (in_array($f['type'] ?? null, ['text', 'textarea'], true)
                    && is_string($f['default'] ?? null)
                    && mb_strlen($f['default']) > self::textLimit($f)) {
                    $errs[] = $section . '.' . $f['key'] . ': default exceeds its own ' . self::textLimit($f) . '-character limit';
                }
            }
        }
        if (isset($m['decoration'])) {
            $d = $m['decoration'];
            if (!is_array($d)) {
                $errs[] = 'decoration must be an object';
            } else {
                if (isset($d['animation']) && !in_array($d['animation'], self::ANIMATIONS, true)) {
                    $errs[] = 'decoration.animation must be one of ' . implode('/', self::ANIMATIONS);
                }
                $iconErr = self::iconError($d['icon'] ?? null);
                if ($iconErr !== null) {
                    $errs[] = 'decoration.icon ' . $iconErr;
                }
                $pathErr = self::iconPathError($d['iconPath'] ?? null);
                if ($pathErr !== null) {
                    $errs[] = 'decoration.iconPath ' . $pathErr;
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
                'eventSettingsSchema' => array_values($m['eventSettings'] ?? []),
                'decoration' => is_array($m['decoration'] ?? null) ? $m['decoration'] : null,
                'requires' => array_values($m['requires'] ?? []),
                'optional' => array_values($m['optional'] ?? []),
                // Shown on the ops page so an unmet dependency reads as a
                // reason rather than as a mysterious refusal to enable.
                'unmetRequires' => $this->unmetRequirements($id),
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
                    'runId' => $lastRun['run_id'] !== null ? (string) $lastRun['run_id'] : null,
                    // How many undoable mutations that run made: the ops page
                    // only offers "Undo this run" when there is something to
                    // undo (most runs are idempotent syncs that changed nothing).
                    'undoableMutations' => $lastRun['run_id'] === null ? 0 : (int) $this->db->one(
                        'SELECT COUNT(*) c FROM mutations WHERE run_id = ? AND undone = 0 AND before_json IS NOT NULL',
                        [(string) $lastRun['run_id']]
                    )['c'],
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
            // Uninstall purges these too; the receipt used to omit them, so an
            // open proposal disappeared without ever being counted.
            'proposals' => (int) $this->db->one("SELECT COUNT(*) c FROM plugin_proposals WHERE plugin_id = ? AND status = 'open'", [$id])['c'],
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

    /**
     * Which of this plugin's declared `requires` are not usable right now.
     * Returns [id => reason]; empty means the dependency set is satisfied.
     */
    public function unmetRequirements(string $id): array
    {
        $m = $this->manifest($id);
        $unmet = [];
        foreach (($m['requires'] ?? []) as $dep) {
            $dep = (string) $dep;
            if ($this->manifest($dep) === null) {
                $unmet[$dep] = 'not installed';
                continue;
            }
            $row = $this->db->one('SELECT enabled FROM plugins WHERE id = ?', [$dep]);
            if ($row === null || (int) $row['enabled'] !== 1) {
                $unmet[$dep] = 'installed but not enabled';
            }
        }
        return $unmet;
    }

    /** Enabled plugins that declare a `requires` on $id. */
    public function dependentsOf(string $id): array
    {
        $enabled = [];
        foreach ($this->db->all('SELECT id FROM plugins WHERE enabled = 1') as $r) {
            $enabled[(string) $r['id']] = true;
        }
        $out = [];
        foreach ($this->scan() as $p) {
            $pid = (string) $p['id'];
            if (isset($enabled[$pid]) && in_array($id, $p['manifest']['requires'] ?? [], true)) {
                $out[] = $pid;
            }
        }
        return $out;
    }

    public function enable(string $id): void
    {
        $m = $this->manifest($id);
        if ($m === null) {
            throw HttpError::badRequest('Unknown or invalid plugin: ' . $id);
        }
        // A plugin that declared a hard dependency should refuse to start
        // rather than fail its first run in a way the user has to decode.
        $unmet = $this->unmetRequirements($id);
        if ($unmet !== []) {
            $parts = [];
            foreach ($unmet as $dep => $why) {
                $parts[] = $dep . ' (' . $why . ')';
            }
            throw HttpError::badRequest(
                $m['name'] . ' requires ' . implode(', ', $parts) . '. Enable it first.'
            );
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
        foreach (['plugin_ranges', 'plugin_warnings', 'plugin_runs', 'plugin_kv', 'event_plugin_data', 'plugin_proposals'] as $t) {
            $this->db->run("DELETE FROM {$t} WHERE plugin_id = ?", [$id]);
        }
        // Per-calendar settings live inside each calendar's settings_json under
        // plugins.<id>, so they survived uninstall and reappeared, still set,
        // if the plugin was ever installed again.
        $withSettings = $this->db->all(
            "SELECT id, settings_json FROM calendars WHERE user_id = ? AND settings_json IS NOT NULL",
            [$userId]
        );
        foreach ($withSettings as $row) {
            $cfg = json_decode((string) $row['settings_json'], true);
            if (!is_array($cfg) || !isset($cfg['plugins'][$id])) {
                continue;
            }
            unset($cfg['plugins'][$id]);
            if (($cfg['plugins'] ?? null) === []) {
                unset($cfg['plugins']);
            }
            $this->db->run('UPDATE calendars SET settings_json = ? WHERE id = ?', [json_encode($cfg), (int) $row['id']]);
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
    /**
     * Character ceiling for one text-ish field. A `textarea` may raise its own
     * limit via `maxLength`, because the settings schema has no list type and
     * list-shaped settings (a wishlist, a rule set) have to live in one field.
     */
    public static function textLimit(array $field): int
    {
        $isArea = ($field['type'] ?? 'text') === 'textarea';
        $ceiling = $isArea ? self::TEXTAREA_MAX : self::TEXT_MAX;
        $asked = isset($field['maxLength']) ? (int) $field['maxLength'] : $ceiling;
        return max(1, min($asked, $ceiling));
    }

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
            // null means "clear this key" for every type, matching the
            // documented setEventData(null) behaviour. Coercing it per-type
            // used to turn a clear into an empty string or a false.
            if ($v === null) {
                $clean[$k] = null;
                continue;
            }
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
                    // {name, lat, lng} from the place picker.
                    if (!is_array($v) || !is_numeric($v['lat'] ?? null) || !is_numeric($v['lng'] ?? null)) {
                        $errs[$k] = 'must be a place (name + coordinates)';
                        break;
                    }
                    $lat = (float) $v['lat'];
                    $lng = (float) $v['lng'];
                    // Coordinates were accepted unchecked, so lat 991 stored
                    // fine and only failed much later inside a plugin's maths.
                    if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                        $errs[$k] = 'coordinates out of range (lat -90..90, lng -180..180)';
                        break;
                    }
                    $clean[$k] = [
                        'name' => mb_substr((string) ($v['name'] ?? ''), 0, 200),
                        'lat' => $lat,
                        'lng' => $lng,
                    ];
                    break;
                case 'person':
                    if (!is_scalar($v)) {
                        $errs[$k] = 'must be text';
                        break;
                    }
                    $clean[$k] = mb_substr((string) $v, 0, 120);
                    break;
                default: // text, textarea
                    // (string) on an array yields the literal "Array", so a
                    // structured value sent to a text field used to store that
                    // word instead of being refused.
                    if (!is_scalar($v)) {
                        $errs[$k] = 'must be text';
                        break;
                    }
                    // Truncating silently returned 200 with a shortened value,
                    // so an over-long setting looked saved and the plugin then
                    // ran on partial data. Refuse it instead, and let a
                    // list-shaped field ask for the room it needs.
                    $max = self::textLimit($f);
                    if (mb_strlen((string) $v) > $max) {
                        $errs[$k] = 'must be ' . $max . ' characters or fewer';
                        break;
                    }
                    $clean[$k] = (string) $v;
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
        $runId = Ids::ulid();
        $host = new PluginHost($this->db, $id, $userId, new HttpClient(), $m, $this->cfg, $runId);
        $t0 = microtime(true);
        $startedAt = Time::nowDb();
        $outcome = 'ok';
        $error = null;
        try {
            // Every mutation this run makes carries $runId, so the ops page can
            // offer one "Undo this run" that reverses the batch in one act.
            ActivityContext::withRun('plugin:' . $id, $runId, fn() => $instance->runJob($host, $jobId));
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
            'run_id' => $runId,
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
        return ['outcome' => $outcome, 'error' => $error, 'durationMs' => $ms, 'runId' => $runId];
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
