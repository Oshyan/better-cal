<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Http\InstallPages;
use BetterCal\Infra\Db;
use BetterCal\Infra\HttpClient;
use BetterCal\Infra\JobQueue;
use BetterCal\Infra\Updater;
use BetterCal\Plugin\EditablePlugin;
use BetterCal\Plugin\OwnCalendarsPlugin;
use BetterCal\Plugin\PluginEditRefused;
use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;
use BetterCal\Support\Ids;
use BetterCal\Support\Maintenance;
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
    private const FIELD_TYPES = ['text', 'textarea', 'secret', 'number', 'select', 'checklist', 'toggle', 'time', 'location', 'person'];
    /** Most choices one setting may offer, and most a checklist may hold. */
    public const OPTIONS_MAX = 500;
    /** The kv prefix a plugin's discovered choices live under (PluginHost::setSettingOptions). */
    public const OPTIONS_KV_PREFIX = '__options.';
    /** Most choices a searchable setting may offer (the list stays on the server; the form asks for matches). */
    public const SEARCH_OPTIONS_MAX = 10000;
    /** Matches one search of a searchable setting returns. */
    public const SEARCH_RESULTS = 20;
    /** The kv prefix of the status line a plugin writes for one of its calendars. */
    public const CALENDAR_STATUS_KV_PREFIX = '__calstatus.';
    /** The kv prefix of the place a plugin says one of its calendars is about (PluginHost::setCalendarPlace). */
    public const CALENDAR_PLACE_KV_PREFIX = '__calplace.';
    /** How many calendars a place may be followed through (a sun calendar following a tide calendar following a trip). */
    private const PLACE_LINK_DEPTH = 3;
    /** Most calendars a person may add to one plugin, when its manifest sets no lower limit. */
    public const OWN_CALENDARS_MAX = 50;
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
        'video', 'warning', 'stack', 'menu', 'chevronDown', 'brand', 'activity', 'grip',
        'views', 'folder', 'mixed', 'visAll', 'visNone', 'reschedule', 'pencil',
        'trash', 'arrowLeft', 'note', 'keyboard', 'expand', 'bell', 'activeOnly',
        'lock', 'unlock', 'calendar', 'today', 'viewMonth', 'viewWeek',
        'viewWeeks3', 'viewWeeks2', 'viewDay', 'viewAgenda', 'viewSplit', 'gapDays', 'jumpDate', 'plugins', 'rowsFit',
        'proposals', 'review', 'mail', 'arrowRight', 'people', 'trip', 'google',
        // Information icons, for context events (a plugin sets one per event).
        'sun', 'sunrise', 'sunset', 'air', 'tideHigh', 'tideLow', 'cloud', 'rain', 'snow', 'storm', 'thermometer', 'moon', 'flag', 'more', 'clock', 'copy', 'link', 'globe', 'eyeOff', 'repeat',
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
            if (!in_array($p, ['http', 'events:write', 'events:edit', 'ranges', 'warnings', 'geocode', 'people', 'notify', 'llm', 'propose'], true)) {
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
        // Calendars the person adds one at a time, each with its own settings
        // (a tide station per calendar, a place per weather calendar).
        if (isset($m['ownCalendars'])) {
            $oc = $m['ownCalendars'];
            if (!is_array($oc) || array_is_list($oc)) {
                $errs[] = 'ownCalendars must be an object';
            } else {
                if (!is_string($oc['noun'] ?? null) || trim($oc['noun']) === '' || mb_strlen($oc['noun']) > 40) {
                    $errs[] = 'ownCalendars.noun is required (what one calendar is called, e.g. "tide calendar")';
                }
                if (isset($oc['name']) && (!is_string($oc['name']) || mb_strlen($oc['name']) > 160)) {
                    $errs[] = 'ownCalendars.name must be text (a name, optionally with {fieldKey} placeholders)';
                }
                if (isset($oc['color']) && (!is_string($oc['color']) || preg_match('/^#[0-9a-fA-F]{6}$/', $oc['color']) !== 1)) {
                    $errs[] = 'ownCalendars.color must be #rrggbb';
                }
                if (isset($oc['role']) && !in_array($oc['role'], ['mine', 'opportunities', 'context'], true)) {
                    $errs[] = 'ownCalendars.role must be mine, opportunities or context';
                }
                if (isset($oc['max']) && (!is_int($oc['max']) || $oc['max'] < 1 || $oc['max'] > self::OWN_CALENDARS_MAX)) {
                    $errs[] = 'ownCalendars.max must be 1-' . self::OWN_CALENDARS_MAX;
                }
            }
        }
        if (isset($m['ownCalendarSettings']) && !isset($m['ownCalendars'])) {
            $errs[] = 'ownCalendarSettings needs ownCalendars';
        }
        foreach (['settings', 'calendarSettings', 'eventSettings', 'ownCalendarSettings'] as $section) {
            foreach (($m[$section] ?? []) as $f) {
                if (!is_array($f) || !is_string($f['key'] ?? null) || !is_string($f['label'] ?? null)) {
                    $errs[] = $section . ': each field needs key + label';
                    continue;
                }
                if (!in_array($f['type'] ?? null, self::FIELD_TYPES, true)) {
                    $errs[] = $section . '.' . $f['key'] . ': type must be one of ' . implode('/', self::FIELD_TYPES);
                }
                if (($f['type'] ?? '') === 'secret' && array_key_exists('default', $f)) {
                    $errs[] = $section . '.' . $f['key'] . ': secret fields cannot have defaults';
                }
                if ($f['key'] === PluginSecrets::PRIVATE_DIAGNOSTICS) {
                    $errs[] = $section . ': reserved field key';
                }
                if (isset($f['optionsFrom']) && (!is_string($f['optionsFrom']) || preg_match('/^[a-zA-Z][a-zA-Z0-9._-]{0,80}$/', $f['optionsFrom']) !== 1)) {
                    $errs[] = $section . '.' . $f['key'] . ': optionsFrom must be a short name (letters, digits, . _ -)';
                }
                if (!empty($f['place']) && (($f['type'] ?? null) !== 'select' || !isset($f['optionsFrom']))) {
                    $errs[] = $section . '.' . $f['key'] . ': place is for a select whose options come from optionsFrom';
                }
                if (!empty($f['searchable']) && (($f['type'] ?? null) !== 'select' || !isset($f['optionsFrom']))) {
                    $errs[] = $section . '.' . $f['key'] . ': searchable is for a select whose options come from optionsFrom';
                }
                if (in_array($f['type'] ?? null, ['select', 'checklist'], true) && !isset($f['optionsFrom'])
                    && (!isset($f['options']) || !is_array($f['options']) || $f['options'] === [])) {
                    $errs[] = $section . '.' . $f['key'] . ': ' . $f['type'] . ' needs options or optionsFrom';
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
        if (isset($m['messages'])) {
            if (!is_array($m['messages']) || array_is_list($m['messages']) && $m['messages'] !== []) {
                $errs[] = 'messages must be an object of code: text';
            } else {
                foreach ($m['messages'] as $code => $text) {
                    if (!is_string($code) || preg_match('/^[a-z][a-z0-9-]{0,63}$/', $code) !== 1 || !is_string($text) || $text === '' || mb_strlen($text) > 300) {
                        $errs[] = 'messages: each needs a kebab-case code and up to 300 characters of text';
                        break;
                    }
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
    /** @var ?list<array> one directory scan per request */
    private ?array $scanned = null;

    public function scan(): array
    {
        if ($this->scanned !== null) {
            return $this->scanned;
        }
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
        return $this->scanned = $out;
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
            $privateDiagnostics = $this->privateDiagnostics($id, $m, $st);
            $lastRun = $this->db->one('SELECT * FROM plugin_runs WHERE plugin_id = ? ORDER BY id DESC LIMIT 1', [$id]);
            $out[] = [
                'id' => $id,
                'name' => (string) ($m['name'] ?? $id),
                'version' => (string) ($m['version'] ?? '0.0.0'),
                'description' => (string) ($m['description'] ?? ''),
                'permissions' => array_values($m['permissions'] ?? []),
                'jobs' => array_values($m['jobs'] ?? []),
                'settingsSchema' => PluginSecrets::schema($this->resolveSchema($id, $m['settings'] ?? [])),
                'calendarSettingsSchema' => PluginSecrets::schema($this->resolveSchema($id, $m['calendarSettings'] ?? [])),
                'eventSettingsSchema' => PluginSecrets::schema($m['eventSettings'] ?? []),
                'ownCalendars' => $this->ownCalendarsDecl($m),
                'ownCalendarSettingsSchema' => PluginSecrets::schema($this->resolveSchema($id, $m['ownCalendarSettings'] ?? [])),
                'calendars' => array_map(static fn($r) => [
                    'id' => (int) $r['id'],
                    'name' => (string) $r['name'],
                    'color' => (string) $r['color'],
                ], $this->db->all("SELECT id, name, color FROM calendars WHERE user_id = ? AND kind = 'plugin' AND plugin_id = ? ORDER BY position, id", [$userId, $id])),
                'decoration' => is_array($m['decoration'] ?? null) ? $m['decoration'] : null,
                'requires' => array_values($m['requires'] ?? []),
                'optional' => array_values($m['optional'] ?? []),
                // Shown on the ops page so an unmet dependency reads as a
                // reason rather than as a mysterious refusal to enable.
                'unmetRequires' => $this->unmetRequirements($id),
                // Required settings still empty: the plugin waits for them
                // (no runs) and its card says what to fill in.
                'needsSetup' => $this->missingRequired($id, $m, $st),
                'errors' => $p['errors'],
                'installed' => $st !== null,
                'enabled' => $st !== null && (int) $st['enabled'] === 1,
                'disabledReason' => $st !== null && $st['disabled_reason'] !== null ? ($privateDiagnostics ? PluginSecrets::HIDDEN_DIAGNOSTIC : $st['disabled_reason']) : null,
                'consecutiveFailures' => $st !== null ? (int) $st['consecutive_failures'] : 0,
                'settings' => PluginSecrets::project($m['settings'] ?? [], $this->settingsValues($id, $m, $st)),
                'lastRun' => $lastRun === null ? null : [
                    'job' => (string) $lastRun['job_id'],
                    'at' => Time::iso(Time::fromDb((string) $lastRun['started_at'])),
                    'durationMs' => $lastRun['duration_ms'] !== null ? (int) $lastRun['duration_ms'] : null,
                    'outcome' => (string) $lastRun['outcome'],
                    'logTail' => $privateDiagnostics ? PluginSecrets::HIDDEN_DIAGNOSTIC : (string) ($lastRun['log_tail'] ?? ''),
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

    /**
     * The labels of plugin settings marked `required` that are still empty
     * (a secret counts once it's stored). Until they're filled the plugin
     * doesn't run: nothing to do yet, and nothing to fail at every minute.
     *
     * @return list<string>
     */
    public function missingRequired(string $id, ?array $manifest = null, ?array $stateRow = null): array
    {
        $manifest ??= $this->manifest($id) ?? [];
        $stateRow ??= $this->db->one('SELECT settings_json FROM plugins WHERE id = ?', [$id]);
        $values = $this->settingsValues($id, $manifest, $stateRow);
        $missing = [];
        foreach (($manifest['settings'] ?? []) as $f) {
            if (empty($f['required'])) {
                continue;
            }
            $v = $values[(string) $f['key']] ?? null;
            if ($v === null || (is_string($v) && trim($v) === '') || $v === []) {
                $missing[] = (string) $f['label'];
            }
        }
        return $missing;
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

    public function publicValues(string $id, string $scope, array $values): array
    {
        return PluginSecrets::project(($this->manifest($id) ?? [])[$scope] ?? [], $values);
    }

    public function sealValues(string $id, string $scope, array $values): array
    {
        $this->rememberPrivateDiagnostics($id);
        return PluginSecrets::seal($id, $scope, ($this->manifest($id) ?? [])[$scope] ?? [], $values, (string) ($this->cfg['session_secret'] ?? ''));
    }

    private function rememberPrivateDiagnostics(string $id): void
    {
        if (!PluginSecrets::hasSecrets($this->manifest($id) ?? [])) return;
        $this->db->tx(function () use ($id): void {
            $raw = $this->db->scalar('SELECT settings_json FROM plugins WHERE id = ?' . $this->db->forUpdate(), [$id]);
            $values = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
            if (!empty($values[PluginSecrets::PRIVATE_DIAGNOSTICS])) return;
            $values[PluginSecrets::PRIVATE_DIAGNOSTICS] = true;
            $this->db->update('plugins', ['settings_json' => json_encode($values)], 'id = ?', [$id]);
        });
    }

    private function privateDiagnostics(string $id, array $manifest, ?array $row = null): bool
    {
        if (PluginSecrets::hasSecrets($manifest)) return true;
        $row ??= $this->db->one('SELECT settings_json FROM plugins WHERE id = ?', [$id]);
        $stored = json_decode((string) ($row['settings_json'] ?? '{}'), true);
        return !empty($stored[PluginSecrets::PRIVATE_DIAGNOSTICS]);
    }

    public function withAuthority(RequestAuthority $authority, callable $write): mixed
    {
        return $this->db->tx(function () use ($authority, $write): mixed {
            $authority->assertValid($this->db);
            return $write();
        });
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

    /** disabled_reason when revoking an API key turned the plugin off; Review lists these. */
    public const TOKEN_REVOKED = 'Turned off because the API key that enabled or configured it was revoked or expired.';

    /** $tokenId: the API key doing this, or null from a browser (which clears any earlier key). */
    public function enable(string $id, ?int $tokenId = null): void
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
            'INSERT INTO plugins (id, version, enabled, consecutive_failures, disabled_reason, changed_by_token_id)
             VALUES (?, ?, 1, 0, NULL, ?)
             ON DUPLICATE KEY UPDATE enabled = 1, version = VALUES(version), consecutive_failures = 0, disabled_reason = NULL, changed_by_token_id = VALUES(changed_by_token_id)',
            [$id, (string) $m['version'], $tokenId]
        );
    }

    public function disable(string $id, ?string $reason = null): void
    {
        $this->db->run('UPDATE plugins SET enabled = 0, disabled_reason = ?, changed_by_token_id = NULL WHERE id = ?', [$reason, $id]);
        $this->cancelPendingJobs($id, 'cancelled because the plugin was disabled');
    }

    /**
     * Uninstall: purge ranges/warnings/runs/kv always; owned calendars are
     * deleted (with their events) or archived to kind 'local' + hidden, per
     * the caller's choice. The activity journal keeps its history either way.
     */
    public function uninstall(int $userId, string $id, bool $deleteCalendars): array
    {
        $this->cancelPendingJobs($id, 'cancelled because the plugin was uninstalled');
        $impact = $this->impact($userId, $id);
        $cals = $this->db->all("SELECT id, name FROM calendars WHERE user_id = ? AND kind = 'plugin' AND plugin_id = ?", [$userId, $id]);
        foreach ($cals as $cal) {
            $cid = (int) $cal['id'];
            if ($deleteCalendars) {
                $this->db->run('DELETE FROM events WHERE calendar_id = ?', [$cid]);
                $this->db->run('DELETE FROM calendars WHERE id = ?', [$cid]);
            } else {
                // Keep: an ordinary local calendar named for its origin, as
                // visible as it was (the person chose to keep it). It no
                // longer syncs; turning the plugin on again starts a new one.
                $this->db->run(
                    "UPDATE calendars SET kind = 'local', plugin_id = NULL, name = CONCAT(name, ' (archived)') WHERE id = ?",
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

    private function cancelPendingJobs(string $id, string $reason): void
    {
        $extract = $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "JSON_EXTRACT(payload_json, '$.plugin')"
            : "JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.plugin'))";
        $this->db->run(
            "UPDATE jobs SET status = 'failed', last_error = ?
             WHERE type = 'plugin_job' AND status = 'pending' AND $extract = ?",
            [$reason, $id]
        );
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

    /** @return list<string> the values a select or checklist offers */
    private static function optionValues(array $field): array
    {
        return array_map(
            static fn($o) => is_array($o) ? (string) ($o['value'] ?? '') : (string) $o,
            is_array($field['options'] ?? null) ? $field['options'] : []
        );
    }

    /**
     * Fill in the choices of fields whose options a plugin discovers
     * (optionsFrom: a name the plugin wrote with setSettingOptions). Fields
     * with nothing discovered yet get an empty list.
     */
    public function resolveSchema(string $id, array $schema): array
    {
        foreach ($schema as &$field) {
            if (!is_array($field) || !is_string($field['optionsFrom'] ?? null)) {
                continue;
            }
            $list = $this->optionList($id, (string) $field['optionsFrom']);
            if (!empty($field['searchable'])) {
                // Too many to send with the form (thousands of stations):
                // the form asks searchOptions for matches as the person types.
                $field['options'] = [];
                $field['optionsLoaded'] = is_array($list);
                $field['optionCount'] = is_array($list) ? count($list) : 0;
                continue;
            }
            $field['options'] = is_array($list) ? $list : [];
            // Discovered and empty (a database with no checkbox fields) reads
            // differently from not discovered yet.
            $field['optionsLoaded'] = is_array($list);
        }
        unset($field);
        return $schema;
    }

    /** @return ?list<array{value:string,label:string}> a plugin's published choices, null when not published yet */
    private function optionList(string $id, string $name): ?array
    {
        $raw = $this->db->scalar('SELECT v_json FROM plugin_kv WHERE plugin_id = ? AND k = ?', [$id, self::OPTIONS_KV_PREFIX . $name]);
        $list = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($list) ? array_values(array_filter($list, static fn($o) => is_array($o) && isset($o['value']))) : null;
    }

    /**
     * Matches for a searchable setting (a select over thousands of choices).
     * Reads the list the plugin published; runs no plugin code. A query
     * matches the start of the value (a station id) or any part of the label;
     * labels that start with it come first. $value looks one up exactly, so
     * a form can show the label of what is already chosen.
     *
     * @return list<array{value:string,label:string}>
     */
    public function searchOptions(string $id, string $field, string $query, ?string $value = null): array
    {
        $m = $this->manifest($id);
        $f = null;
        foreach (['settings', 'calendarSettings', 'ownCalendarSettings'] as $scope) {
            foreach (($m[$scope] ?? []) as $candidate) {
                if (is_array($candidate) && ($candidate['key'] ?? null) === $field && !empty($candidate['searchable'])) {
                    $f = $candidate;
                    break 2;
                }
            }
        }
        if ($f === null) {
            throw HttpError::notFound('No such searchable setting', 'unknown_setting');
        }
        $list = $this->optionList($id, (string) $f['optionsFrom']) ?? [];
        if ($value !== null) {
            foreach ($list as $o) {
                if ((string) $o['value'] === $value) {
                    return [['value' => (string) $o['value'], 'label' => (string) ($o['label'] ?? $o['value'])]];
                }
            }
            return [];
        }
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return [];
        }
        $first = [];
        $rest = [];
        foreach ($list as $o) {
            $label = (string) ($o['label'] ?? $o['value']);
            $low = mb_strtolower($label);
            $item = ['value' => (string) $o['value'], 'label' => $label];
            if (str_starts_with($low, $q) || str_starts_with(mb_strtolower((string) $o['value']), $q)) {
                $first[] = $item;
            } elseif (str_contains($low, $q)) {
                $rest[] = $item;
            }
            if (count($first) >= self::SEARCH_RESULTS) {
                break;
            }
        }
        $byLabel = static fn($a, $b) => strcmp($a['label'], $b['label']);
        usort($first, $byLabel);
        usort($rest, $byLabel);
        return array_slice([...$first, ...$rest], 0, self::SEARCH_RESULTS);
    }

    /**
     * Refuse a searchable value the plugin's list doesn't hold. Before the
     * plugin has published its list (its first run), any value is kept and
     * the plugin checks it when it runs.
     *
     * @return array<string,string> errors by key
     */
    private function checkSearchable(string $id, array $schema, array $clean): array
    {
        $errs = [];
        foreach ($schema as $f) {
            $k = (string) ($f['key'] ?? '');
            if (empty($f['searchable']) || !isset($clean[$k])) {
                continue;
            }
            $list = $this->optionList($id, (string) $f['optionsFrom']);
            if ($list !== null && !in_array((string) $clean[$k], array_map(static fn($o) => (string) $o['value'], $list), true)) {
                $errs[$k] = 'is not one of the choices';
            }
        }
        return $errs;
    }

    public static function validateAgainstSchema(array $schema, array $values, bool $allowPlaceLinks = false): array
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
                case 'secret':
                    if (!is_string($v) || $v === '' || strlen($v) > 16384) {
                        $errs[$k] = 'must be nonempty text of at most 16384 bytes, or null to clear';
                    } else {
                        $clean[$k] = $v;
                    }
                    break;
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
                case 'time':
                    // A time of day, 24-hour HH:MM.
                    if (!is_string($v) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) !== 1) {
                        $errs[$k] = 'must be a time of day (HH:MM)';
                    } else {
                        $clean[$k] = $v;
                    }
                    break;
                case 'select':
                    if (!empty($f['searchable'])) {
                        // Checked against the plugin's stored list by
                        // checkSearchable (the schema carries no options).
                        if (!is_scalar($v) || (string) $v === '' || mb_strlen((string) $v) > self::TEXT_MAX) {
                            $errs[$k] = 'must be text';
                        } else {
                            $clean[$k] = (string) $v;
                        }
                        break;
                    }
                    $opts = self::optionValues($f);
                    // Choices a plugin discovers (optionsFrom) may not be
                    // loaded yet: then any short text is kept, and the
                    // plugin checks it when it runs.
                    if (!is_scalar($v) || ($opts === [] && isset($f['optionsFrom']) ? mb_strlen((string) $v) > self::TEXT_MAX : !in_array((string) $v, $opts, true))) {
                        $errs[$k] = $opts === [] ? 'must be text' : 'must be one of ' . implode(', ', array_slice($opts, 0, 20));
                    } else {
                        $clean[$k] = (string) $v;
                    }
                    break;
                case 'checklist':
                    $opts = self::optionValues($f);
                    if (!is_array($v) || !array_is_list($v) || count($v) > self::OPTIONS_MAX) {
                        $errs[$k] = 'must be a list of at most ' . self::OPTIONS_MAX . ' choices';
                        break;
                    }
                    $picked = [];
                    foreach ($v as $item) {
                        if (!is_scalar($item) || mb_strlen((string) $item) > self::TEXT_MAX || ($opts !== [] && !in_array((string) $item, $opts, true))) {
                            $errs[$k] = 'has a choice that is not offered';
                            break 2;
                        }
                        $picked[(string) $item] = true;
                    }
                    // PHP turns numeric keys ("6") into ints; choices stay text.
                    $clean[$k] = array_map('strval', array_keys($picked));
                    break;
                case 'location':
                    // {link: calendarId}: the place another calendar is
                    // about, followed as it changes (own calendar settings
                    // only; Plugins::calendarPlace resolves it).
                    if ($allowPlaceLinks && is_array($v) && array_key_exists('link', $v)) {
                        if (!is_int($v['link']) && !(is_string($v['link']) && ctype_digit($v['link']))) {
                            $errs[$k] = 'must link to a calendar id';
                        } else {
                            $clean[$k] = ['link' => (int) $v['link']];
                        }
                        break;
                    }
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

    public function saveSettings(string $id, array $values, ?RequestAuthority $authority = null): array
    {
        $m = $this->manifest($id);
        if ($m === null) {
            throw HttpError::badRequest('Unknown plugin');
        }
        $authority?->assertValid($this->db, false);
        [$clean, $errs] = self::validateAgainstSchema($this->resolveSchema($id, $m['settings'] ?? []), $values);
        $errs += $this->checkSearchable($id, $m['settings'] ?? [], $clean);
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
            throw HttpError::badRequest($this->privateDiagnostics($id, $m) ? 'Plugin settings were refused. Check the values you entered; private diagnostic details are not returned.' : 'Invalid settings: ' . json_encode($errs));
        }
        return $this->db->tx(function () use ($id, $m, $clean, $authority): array {
            $authority?->assertValid($this->db);
            $row = $this->db->one('SELECT settings_json FROM plugins WHERE id = ?' . $this->db->forUpdate(), [$id]);
            if ($row === null) {
                throw HttpError::badRequest('Plugin is not installed (enable it first)');
            }
            $stored = is_string($row['settings_json']) ? (json_decode((string) $row['settings_json'], true) ?: []) : [];
            $merged = $this->sealValues($id, 'settings', array_merge($stored, $clean));
            if (PluginSecrets::hasSecrets($m)) $merged[PluginSecrets::PRIVATE_DIAGNOSTICS] = true;
            $this->db->run(
                'UPDATE plugins SET settings_json = ? WHERE id = ?',
                [json_encode($merged), $id]
            );
            $this->markTokenChange($id, $authority);
            return $this->publicValues($id, 'settings', $merged);
        });
    }

    /** Calendar-scope values for one plugin, validated, merged into settings_json.plugins.<id>. */
    public function saveCalendarSettings(int $userId, int $calendarId, string $id, array $values, ?RequestAuthority $authority = null): array
    {
        $m = $this->manifest($id);
        if ($m === null) {
            throw HttpError::badRequest('Unknown plugin');
        }
        [$clean, $errs] = self::validateAgainstSchema($this->resolveSchema($id, $m['calendarSettings'] ?? []), $values);
        $errs += $this->checkSearchable($id, $m['calendarSettings'] ?? [], $clean);
        if ($errs !== []) {
            throw HttpError::badRequest('Invalid settings: ' . json_encode($errs));
        }
        return $this->db->tx(function () use ($userId, $calendarId, $id, $clean, $authority): array {
            $authority?->assertValid($this->db);
            $raw = $this->db->scalar('SELECT settings_json FROM calendars WHERE id = ? AND user_id = ?' . $this->db->forUpdate(), [$calendarId, $userId]);
            if ($raw === null && $this->db->one('SELECT id FROM calendars WHERE id = ? AND user_id = ?', [$calendarId, $userId]) === null) {
                throw HttpError::badRequest('Unknown calendar');
            }
            $all = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
            $all['plugins'][$id] = $this->sealValues($id, 'calendarSettings', array_merge($all['plugins'][$id] ?? [], $clean));
            $this->db->run('UPDATE calendars SET settings_json = ? WHERE id = ?', [json_encode($all), $calendarId]);
            $this->markTokenChange($id, $authority);
            return $this->publicValues($id, 'calendarSettings', $all['plugins'][$id]);
        });
    }

    // ---- calendars the person adds (ownCalendars) -------------------------

    /** The manifest's ownCalendars declaration with its defaults filled in, or null. */
    public function ownCalendarsDecl(array $m): ?array
    {
        $oc = $m['ownCalendars'] ?? null;
        if (!is_array($oc)) {
            return null;
        }
        return [
            'noun' => (string) $oc['noun'],
            'name' => (string) ($oc['name'] ?? ($m['name'] ?? '')),
            'color' => (string) ($oc['color'] ?? '#5b8dd9'),
            'role' => (string) ($oc['role'] ?? 'context'),
            'max' => (int) ($oc['max'] ?? self::OWN_CALENDARS_MAX),
        ];
    }

    /** Stored values over manifest defaults, still sealed where secret. */
    private static function withDefaults(array $schema, array $stored): array
    {
        $out = [];
        foreach ($schema as $f) {
            $k = (string) $f['key'];
            $out[$k] = array_key_exists($k, $stored) ? $stored[$k] : ($f['default'] ?? null);
        }
        return $out;
    }

    /** @return array<string,mixed> one calendar's own settings as stored (sealed) */
    private static function storedOwn(?string $settingsJson): array
    {
        $all = is_string($settingsJson) ? (json_decode($settingsJson, true) ?: []) : [];
        return is_array($all['own'] ?? null) ? $all['own'] : [];
    }

    /** One calendar's own settings for the calendars payload (secrets as set/unset only). */
    public function ownSettingsPublic(string $id, ?string $settingsJson): ?array
    {
        $schema = ($this->manifest($id) ?? [])['ownCalendarSettings'] ?? null;
        if (!is_array($schema) || $schema === []) {
            return null;
        }
        return PluginSecrets::project($schema, self::withDefaults($schema, self::storedOwn($settingsJson)));
    }

    /** The status line a plugin last wrote for one of its calendars: {text, severity, at} or null. */
    public function calendarStatus(string $id, int $calendarId): ?array
    {
        $raw = $this->db->scalar('SELECT v_json FROM plugin_kv WHERE plugin_id = ? AND k = ?', [$id, self::CALENDAR_STATUS_KV_PREFIX . $calendarId]);
        $v = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($v) && is_string($v['text'] ?? null) ? $v : null;
    }

    /**
     * Check a calendar's complete settings: schema types, required fields,
     * searchable choices, then the plugin's own validateCalendarSettings.
     * Returns the plain values to store.
     *
     * @param array<string,mixed> $current the stored values, opened
     * @return array<string,mixed>
     */
    private function validatedOwn(int $userId, ?int $selfId, string $id, array $m, array $current, array $patch): array
    {
        $schema = $this->resolveSchema($id, $m['ownCalendarSettings'] ?? []);
        [$clean, $errs] = self::validateAgainstSchema($schema, $patch, true);
        foreach ($clean as $k => $v) {
            if (!is_array($v) || !isset($v['link'])) {
                continue;
            }
            $target = $this->db->one("SELECT id FROM calendars WHERE id = ? AND user_id = ? AND kind = 'plugin'", [$v['link'], $userId]);
            if ($target === null || $v['link'] === $selfId) {
                $errs[$k] = 'must follow another of your plugin calendars';
            } elseif ($selfId !== null && $this->followsPlace($userId, $v['link'], $selfId)) {
                $errs[$k] = 'would follow itself in a circle';
            }
        }
        $errs += $this->checkSearchable($id, $m['ownCalendarSettings'] ?? [], $clean);
        $merged = self::withDefaults($schema, array_merge($current, $clean));
        foreach ($schema as $f) {
            $k = (string) $f['key'];
            if (!empty($f['required']) && !isset($errs[$k]) && ($merged[$k] === null || $merged[$k] === '' || $merged[$k] === [])) {
                $errs[$k] = 'is required';
            }
        }
        if ($errs === []) {
            try {
                $instance = $this->load($id);
                $errs = $instance instanceof OwnCalendarsPlugin ? $instance->validateCalendarSettings($merged) : [];
            } catch (\Throwable $e) {
                $errs = ['_' => 'plugin validation failed: ' . $e->getMessage()];
            }
        }
        if ($errs !== []) {
            $labels = [];
            foreach ($schema as $f) $labels[(string) $f['key']] = (string) $f['label'];
            $parts = [];
            foreach ($errs as $k => $e) $parts[] = (isset($labels[$k]) ? $labels[$k] . ': ' : '') . $e;
            throw new HttpError('invalid_settings', implode('. ', $parts), 400);
        }
        return $merged;
    }

    /** What a {key} placeholder in a calendar name shows: the chosen option's label, a place's name, or the text. */
    private function displayValue(string $id, array $field, mixed $v, int $userId = 0): string
    {
        if (is_array($v) && isset($v['link'])) {
            return (string) ($this->calendarPlace($userId, (int) $v['link'])['name'] ?? '');
        }
        if (is_array($v)) {
            return (string) ($v['name'] ?? '');
        }
        if ($v === null || is_bool($v)) {
            return '';
        }
        if (($field['type'] ?? '') === 'select') {
            $options = !empty($field['searchable'])
                ? $this->searchOptions($id, (string) $field['key'], '', (string) $v)
                : ($this->resolveSchema($id, [$field])[0]['options'] ?? []);
            foreach ($options as $o) {
                if (is_array($o) && (string) ($o['value'] ?? '') === (string) $v) {
                    return (string) ($o['label'] ?? $v);
                }
            }
        }
        return (string) $v;
    }

    // ---- places: what a calendar is about, for others to follow ------------

    /**
     * The place one of the person's plugin calendars is about: what its
     * plugin published (a tide station's coordinates), else the first place
     * among its own settings, following a link to another calendar's place.
     * {name, lat, lng, via?} or null.
     */
    public function calendarPlace(int $userId, int $calendarId, int $depth = 0): ?array
    {
        if ($depth > self::PLACE_LINK_DEPTH) {
            return null;
        }
        $cal = $this->db->one("SELECT id, plugin_id, settings_json FROM calendars WHERE id = ? AND user_id = ? AND kind = 'plugin'", [$calendarId, $userId]);
        if ($cal === null || empty($cal['plugin_id'])) {
            return null;
        }
        $pluginId = (string) $cal['plugin_id'];
        $raw = $this->db->scalar('SELECT v_json FROM plugin_kv WHERE plugin_id = ? AND k = ?', [$pluginId, self::CALENDAR_PLACE_KV_PREFIX . $calendarId]);
        $published = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($published) && is_numeric($published['lat'] ?? null) && is_numeric($published['lng'] ?? null)) {
            return ['name' => (string) ($published['name'] ?? ''), 'lat' => (float) $published['lat'], 'lng' => (float) $published['lng']];
        }
        $own = self::storedOwn($cal['settings_json'] !== null ? (string) $cal['settings_json'] : null);
        $schema = (($this->manifest($pluginId) ?? [])['ownCalendarSettings'] ?? []);
        foreach ($schema as $f) {
            if (($f['type'] ?? '') === 'location') {
                return $this->resolvePlace($userId, $own[(string) $f['key']] ?? ($f['default'] ?? null), $depth);
            }
        }
        // A chosen option that is a place (a tide station with coordinates),
        // until the plugin's first run says where the calendar is.
        foreach ($schema as $f) {
            if (empty($f['place']) || !isset($own[(string) $f['key']])) {
                continue;
            }
            foreach ($this->optionList($pluginId, (string) $f['optionsFrom']) ?? [] as $o) {
                if ((string) $o['value'] === (string) $own[(string) $f['key']] && isset($o['lat'], $o['lng'])) {
                    return ['name' => (string) ($o['label'] ?? $o['value']), 'lat' => (float) $o['lat'], 'lng' => (float) $o['lng']];
                }
            }
        }
        return null;
    }

    /** A location setting's value as a place: as entered, or the linked calendar's place now. */
    public function resolvePlace(int $userId, mixed $v, int $depth = 0): ?array
    {
        if (!is_array($v)) {
            return null;
        }
        if (isset($v['link'])) {
            $place = $this->calendarPlace($userId, (int) $v['link'], $depth + 1);
            return $place === null ? null : $place + ['via' => (int) $v['link']];
        }
        return is_numeric($v['lat'] ?? null) && is_numeric($v['lng'] ?? null)
            ? ['name' => (string) ($v['name'] ?? ''), 'lat' => (float) $v['lat'], 'lng' => (float) $v['lng']]
            : null;
    }

    /** Does calendar $from reach $to by following place links? (A circle would never resolve.) */
    private function followsPlace(int $userId, int $from, int $to, int $depth = 0): bool
    {
        if ($from === $to) {
            return true;
        }
        if ($depth > self::PLACE_LINK_DEPTH) {
            return false;
        }
        $raw = $this->db->scalar("SELECT settings_json FROM calendars WHERE id = ? AND user_id = ? AND kind = 'plugin'", [$from, $userId]);
        foreach (self::storedOwn(is_string($raw) ? $raw : null) as $v) {
            if (is_array($v) && isset($v['link']) && $this->followsPlace($userId, (int) $v['link'], $to, $depth + 1)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{id:int,plugin:string}> the person's plugin calendars whose settings follow this calendar's place */
    public function placeFollowers(int $userId, int $calendarId): array
    {
        $out = [];
        foreach ($this->db->all("SELECT id, plugin_id, settings_json FROM calendars WHERE user_id = ? AND kind = 'plugin' AND id <> ?", [$userId, $calendarId]) as $r) {
            foreach (self::storedOwn($r['settings_json'] !== null ? (string) $r['settings_json'] : null) as $v) {
                if (is_array($v) && (int) ($v['link'] ?? 0) === $calendarId) {
                    $out[] = ['id' => (int) $r['id'], 'plugin' => (string) $r['plugin_id']];
                    break;
                }
            }
        }
        return $out;
    }

    /** The jobs that fill a new or changed calendar: those marked runOnSave, or every job when none is. */
    public function calendarJobIds(string $id): array
    {
        $jobs = ($this->manifest($id) ?? [])['jobs'] ?? [];
        $onSave = array_values(array_map(static fn($j) => (string) $j['id'], array_filter($jobs, static fn($j) => !empty($j['runOnSave']))));
        return $onSave !== [] ? $onSave : array_values(array_map(static fn($j) => (string) $j['id'], $jobs));
    }

    /**
     * Refill the calendars that follow this one's place (its place just
     * changed), each with a run for that calendar alone. Followers of
     * followers follow on their own runs.
     */
    public function queuePlaceFollowers(int $userId, int $calendarId, \BetterCal\Infra\JobQueue $queue): void
    {
        foreach ($this->placeFollowers($userId, $calendarId) as $f) {
            $jobs = $this->calendarJobIds($f['plugin']);
            if ($jobs !== []) {
                $queue->enqueuePluginJobs($userId, $f['plugin'], $jobs, false, null, $f['id']);
            }
        }
    }

    /**
     * Add one of a plugin's calendars, with its own settings (a tide
     * calendar for one station, a weather calendar for one place). The
     * name may be left out: the manifest's name template fills it from the
     * settings ("Tides, {station}"). Undoable like any new calendar.
     *
     * @param array<string,mixed> $values
     */
    public function createOwnCalendar(int $userId, string $id, ?string $name, ?string $color, array $values, ?RequestAuthority $authority = null): int
    {
        $m = $this->manifest($id);
        $decl = $m !== null ? $this->ownCalendarsDecl($m) : null;
        if ($decl === null) {
            throw HttpError::badRequest('This plugin has no calendars to add', 'no_own_calendars');
        }
        $authority?->assertValid($this->db, false);
        if ((int) ($this->db->scalar('SELECT enabled FROM plugins WHERE id = ?', [$id]) ?? 0) !== 1) {
            throw HttpError::conflict('plugin_disabled', 'Turn ' . (string) $m['name'] . ' on before adding a ' . $decl['noun'] . '.');
        }
        $plain = $this->validatedOwn($userId, null, $id, $m, [], $values);
        $name = trim((string) $name);
        // A name made from the settings follows them (a new station renames
        // it) until the person names the calendar themselves.
        $autoName = $name === '';
        if ($autoName) {
            $name = $this->templateName($userId, $id, $m, $plain);
        }
        $color = $color !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : $decl['color'];
        $calId = $this->db->tx(function () use ($userId, $id, $m, $decl, $name, $color, $plain, $authority, $autoName): int {
            $authority?->assertValid($this->db);
            $count = (int) $this->db->scalar("SELECT COUNT(*) FROM calendars WHERE user_id = ? AND kind = 'plugin' AND plugin_id = ?", [$userId, $id]);
            if ($count >= $decl['max']) {
                throw HttpError::conflict('calendar_limit', (string) $m['name'] . ' allows ' . $decl['max'] . ' calendars.');
            }
            $settings = ['own' => $this->sealValues($id, 'ownCalendarSettings', $plain)] + ($autoName ? ['autoName' => true] : []);
            if ($decl['role'] === 'mine') {
                // As ensureCalendar: a plugin's Mine calendar starts without reminders.
                $settings['reminderDefaults'] = ['timed' => [], 'allDay' => []];
            }
            $calId = $this->db->insert('calendars', [
                'role' => $decl['role'],
                'user_id' => $userId,
                'name' => mb_substr($name, 0, 160),
                'color' => $color,
                'kind' => 'plugin',
                'plugin_id' => $id,
                'subscription_authority' => SubscriptionAuthority::OWNER,
                'settings_json' => json_encode($settings),
            ]);
            $this->markTokenChange($id, $authority);
            return $calId;
        });
        (new Undo($this->db))->record($userId, 'calendar', $calId, 'create', null, null, "Added " . $decl['noun'] . " '" . $name . "'");
        return $calId;
    }

    /**
     * Change one of a plugin's calendars' own settings (only the keys sent;
     * a secret left out keeps its value). Returns the public values.
     *
     * @param array<string,mixed> $values
     */
    public function saveOwnCalendarSettings(int $userId, int $calendarId, string $id, array $values, ?RequestAuthority $authority = null): array
    {
        $m = $this->manifest($id);
        if ($m === null || $this->ownCalendarsDecl($m) === null) {
            throw HttpError::badRequest('This plugin has no calendars of its own', 'no_own_calendars');
        }
        $authority?->assertValid($this->db, false);
        $cal = $this->db->one("SELECT settings_json FROM calendars WHERE id = ? AND user_id = ? AND kind = 'plugin' AND plugin_id = ?", [$calendarId, $userId, $id]);
        if ($cal === null) {
            throw HttpError::notFound('No such ' . (string) $m['name'] . ' calendar');
        }
        $secret = (string) ($this->cfg['session_secret'] ?? '');
        $current = PluginSecrets::open($id, 'ownCalendarSettings', self::storedOwn($cal['settings_json'] !== null ? (string) $cal['settings_json'] : null), $secret);
        $plain = $this->validatedOwn($userId, $calendarId, $id, $m, $current, $values);
        return $this->db->tx(function () use ($userId, $calendarId, $id, $plain, $authority): array {
            $authority?->assertValid($this->db);
            $raw = $this->db->scalar('SELECT settings_json FROM calendars WHERE id = ? AND user_id = ?' . $this->db->forUpdate(), [$calendarId, $userId]);
            $all = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
            $all['own'] = $this->sealValues($id, 'ownCalendarSettings', $plain);
            $this->db->run('UPDATE calendars SET settings_json = ? WHERE id = ?', [json_encode($all), $calendarId]);
            $this->markTokenChange($id, $authority);
            $this->refreshAutoName($userId, $calendarId);
            return $this->publicValues($id, 'ownCalendarSettings', $all['own']);
        });
    }

    /** The manifest's name template filled from these settings ("Tides, {station}" -> "Tides, Sample Harbor"). */
    private function templateName(int $userId, string $id, array $m, array $plain): string
    {
        $byKey = [];
        foreach ($m['ownCalendarSettings'] ?? [] as $f) $byKey[(string) $f['key']] = $f;
        $template = (string) (($m['ownCalendars']['name'] ?? null) ?: ($m['name'] ?? $id));
        $name = trim((string) preg_replace_callback('/\{([A-Za-z0-9_.-]+)\}/', fn($x) => isset($byKey[$x[1]]) ? $this->displayValue($id, $byKey[$x[1]], $plain[$x[1]] ?? null, $userId) : '', $template));
        return mb_substr(trim((string) preg_replace('/[\s,:;-]+$/u', '', $name)) ?: (string) ($m['name'] ?? $id), 0, 160);
    }

    /**
     * Rename a calendar whose name came from its settings, after they (or
     * the place it follows) changed. A calendar the person named keeps it.
     */
    public function refreshAutoName(int $userId, int $calendarId): void
    {
        $cal = $this->db->one("SELECT name, plugin_id, settings_json FROM calendars WHERE id = ? AND user_id = ? AND kind = 'plugin'", [$calendarId, $userId]);
        $settings = $cal !== null && is_string($cal['settings_json']) ? (json_decode($cal['settings_json'], true) ?: []) : [];
        if (empty($settings['autoName'])) {
            return;
        }
        $id = (string) $cal['plugin_id'];
        $m = $this->manifest($id);
        if ($m === null) {
            return;
        }
        $plain = PluginSecrets::open($id, 'ownCalendarSettings', self::withDefaults($m['ownCalendarSettings'] ?? [], self::storedOwn((string) $cal['settings_json'])), (string) ($this->cfg['session_secret'] ?? ''));
        $name = $this->templateName($userId, $id, $m, $plain);
        if ($name !== (string) $cal['name']) {
            $this->db->run('UPDATE calendars SET name = ? WHERE id = ?', [$name, $calendarId]);
        }
    }

    /**
     * A key that changes what a plugin does owns it until a browser enables it
     * again: revoking that key turns the plugin off (review of 0.9.87).
     */
    private function markTokenChange(string $id, ?RequestAuthority $authority): void
    {
        $tokenId = $authority?->tokenId();
        if ($tokenId !== null) {
            $this->db->run('UPDATE plugins SET changed_by_token_id = ? WHERE id = ?', [$tokenId, $id]);
        }
    }

    /**
     * Turn off the plugins a revoked key enabled or configured. Runs inside
     * the revocation's transaction, so no job starts under a key that is gone.
     *
     * @return list<string> plugin ids turned off
     */
    public static function disableForRevokedToken(Db $db, int $tokenId): array
    {
        $ids = array_map('strval', array_column($db->all('SELECT id FROM plugins WHERE enabled = 1 AND changed_by_token_id = ?', [$tokenId]), 'id'));
        // disabled_reason first: MySQL assigns left to right and would see enabled = 0.
        $db->run('UPDATE plugins SET disabled_reason = CASE WHEN enabled = 1 THEN ? ELSE disabled_reason END, enabled = 0, changed_by_token_id = NULL WHERE changed_by_token_id = ?', [self::TOKEN_REVOKED, $tokenId]);
        if ($ids !== []) {
            $extract = $db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? "JSON_EXTRACT(payload_json, '$.plugin')"
                : "JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.plugin'))";
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $db->run(
                "UPDATE jobs SET status = 'failed', last_error = ? WHERE type = 'plugin_job' AND status = 'pending' AND $extract IN ($marks)",
                ['cancelled because the API key that enabled the plugin was revoked', ...$ids]
            );
        }
        return $ids;
    }

    /** Plugins revoking a key turned off, for Review: [{id, name}]. */
    public function turnedOffByRevokedKey(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT id FROM plugins WHERE enabled = 0 AND disabled_reason = ?', [self::TOKEN_REVOKED]) as $r) {
            $m = $this->manifest((string) $r['id']);
            if ($m !== null) $out[] = ['id' => (string) $r['id'], 'name' => (string) ($m['name'] ?? $r['id'])];
        }
        return $out;
    }

    // ---- rescheduling plugin events ---------------------------------------

    /** @var array<string,bool> plugin id => may its calendars be rescheduled (per request) */
    private array $editableMemo = [];

    /**
     * Can this calendar's events be rescheduled? A plugin calendar whose
     * plugin is enabled and declares `events:edit` (it implements
     * EditablePlugin; applyEdit checks that before calling it).
     */
    public function calendarReschedulable(array $calendar): bool
    {
        if (($calendar['kind'] ?? '') !== 'plugin' || empty($calendar['plugin_id'])) {
            return false;
        }
        $id = (string) $calendar['plugin_id'];
        if (!array_key_exists($id, $this->editableMemo)) {
            $enabled = (int) ($this->db->scalar('SELECT enabled FROM plugins WHERE id = ?', [$id]) ?? 0) === 1;
            $m = $enabled ? $this->manifest($id) : null;
            $this->editableMemo[$id] = $m !== null && in_array('events:edit', $m['permissions'] ?? [], true);
        }
        return $this->editableMemo[$id];
    }

    /** The plugin's display name, for messages like "Moved in Fibery". */
    public function displayName(string $id): string
    {
        $m = $this->manifest($id);
        return (string) ($m['name'] ?? $id);
    }

    /**
     * Ask the plugin that owns an event to apply a new time in its source
     * system (EditablePlugin). Runs while the person waits, like a Google
     * write. Returns the span the source now holds. A refusal becomes a 422
     * with the plugin's sentence; any other failure a 502 that says only
     * which plugin failed, since a plugin with credentials keeps its
     * diagnostics private.
     *
     * @param array<string,mixed> $edit
     * @return array{start:string,end:string,allDay:bool}
     */
    public function applyEdit(int $userId, string $id, array $edit): array
    {
        $state = $this->db->one('SELECT * FROM plugins WHERE id = ? AND enabled = 1', [$id]);
        $m = $this->manifest($id);
        $name = (string) ($m['name'] ?? $id);
        if ($state === null || $m === null || !in_array('events:edit', $m['permissions'] ?? [], true)) {
            throw HttpError::forbidden('plugin_readonly', $name . ' events are read-only here');
        }
        $instance = $this->load($id);
        if (!$instance instanceof EditablePlugin) {
            throw HttpError::forbidden('plugin_readonly', $name . ' events are read-only here');
        }
        $host = new PluginHost($this->db, $id, $userId, new HttpClient(requestBudget: 10, totalTimeoutMs: 10_000, absoluteDeadline: microtime(true) + 20.0), $m, $this->cfg, null);
        try {
            $span = ActivityContext::with('plugin:' . $id, fn() => $instance->applyEdit($host, $edit));
        } catch (PluginEditRefused $e) {
            throw new HttpError('plugin_edit_refused', mb_substr(trim($e->getMessage()), 0, 300) ?: $name . ' refused the change', 422);
        } catch (HttpError $e) {
            throw $e;
        } catch (\Throwable $e) {
            $detail = $this->privateDiagnostics($id, $m, $state) ? '' : ': ' . mb_substr($e->getMessage(), 0, 200);
            throw new HttpError('plugin_edit_failed', $name . " couldn't make the change" . $detail, 502);
        }
        if (!is_array($span) || !is_string($span['start'] ?? null) || !is_string($span['end'] ?? null)) {
            throw new HttpError('plugin_edit_failed', $name . ' gave back no time for the event', 502);
        }
        $out = ['start' => $span['start'], 'end' => $span['end'], 'allDay' => !empty($span['allDay'])];
        $undo = $span['undo'] ?? null;
        if (is_array($undo) && is_string($undo['start'] ?? null) && is_string($undo['end'] ?? null)) {
            $out['undo'] = ['start' => $undo['start'], 'end' => $undo['end'], 'allDay' => !empty($undo['allDay'])];
        }
        return $out;
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
    public function runJob(int $userId, string $id, string $jobId, ?int $calendarId = null): array
    {
        $this->rememberPrivateDiagnostics($id);
        $state = $this->db->one('SELECT * FROM plugins WHERE id = ? AND enabled = 1', [$id]);
        $m = $this->manifest($id);
        if ($state === null || $m === null) {
            return ['outcome' => 'error', 'error' => 'plugin not enabled or invalid'];
        }
        if ($this->missingRequired($id, $m, $state) !== []) {
            // Queued before its settings were cleared, say: nothing to do.
            return ['outcome' => 'skipped', 'error' => null];
        }
        // A key can also stop being valid without a revocation (it expired):
        // the plugin it enabled stops the same way, on its next run.
        $tokenId = $state['changed_by_token_id'] ?? null;
        if ($tokenId !== null && !ApiTokens::stillValid($this->db, (int) $tokenId)) {
            $this->db->tx(fn() => self::disableForRevokedToken($this->db, (int) $tokenId));
            return ['outcome' => 'error', 'error' => 'plugin turned off: the API key that enabled it is no longer valid'];
        }
        $instance = $this->load($id);
        if ($instance === null) {
            return ['outcome' => 'error', 'error' => 'Plugin.php missing or does not return a PluginInterface'];
        }
        $runId = Ids::ulid();
        $host = new PluginHost($this->db, $id, $userId, new HttpClient(), $m, $this->cfg, $runId, $calendarId);
        if (isset($m['ownCalendars'])) {
            // A calendar named after the place it follows takes the new name
            // when that place changed (a tide calendar moved to a new station).
            foreach ($this->db->all("SELECT id FROM calendars WHERE user_id = ? AND kind = 'plugin' AND plugin_id = ?" . ($calendarId !== null ? ' AND id = ?' : ''), $calendarId !== null ? [$userId, $id, $calendarId] : [$userId, $id]) as $c) {
                $this->refreshAutoName($userId, (int) $c['id']);
            }
        }
        $t0 = microtime(true);
        $startedAt = Time::nowDb();
        $outcome = 'ok';
        $error = null;
        try {
            // Every mutation this run makes carries $runId, so the ops page can
            // offer one "Undo this run" that reverses the batch in one act.
            ActivityContext::withRun('plugin:' . $id, $runId, fn() => $instance->runJob($host, $jobId));
            if ($host->overBudget() && !$host->committedSyncWithinBudget()) {
                $outcome = 'timeout';
                $error = 'exceeded ' . PluginHost::RUN_BUDGET_SECONDS . 's soft budget';
            }
        } catch (\Throwable $e) {
            $outcome = 'error';
            $error = $this->privateDiagnostics($id, $m, $state) ? PluginSecrets::HIDDEN_DIAGNOSTIC : mb_substr($e->getMessage(), 0, 300);
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

    /** Can a queued job run straight after the response, in this request's process (PHP-FPM, LiteSpeed)? */
    public static function canRunAfterResponse(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    /**
     * Run plugin jobs just queued (Run now, a save, a new calendar) as soon
     * as the response has been sent, instead of at the worker's next
     * minute. The person isn't kept waiting: the page follows the run. Each
     * job is claimed from the queue first, so the worker can't run it too;
     * where the server can't finish a response early, the worker runs them
     * as before. Returns whether they'll run now.
     *
     * @param list<int> $jobIds
     */
    public function runAfterResponse(array $jobIds): bool
    {
        if ($jobIds === [] || !self::canRunAfterResponse()
            || Maintenance::active((string) ($this->cfg['app_root'] ?? dirname(__DIR__, 3)))) {
            return false;
        }
        register_shutdown_function(function () use ($jobIds): void {
            try {
                ignore_user_abort(true);
                @set_time_limit(PluginHost::RUN_BUDGET_SECONDS * count($jobIds) + 30);
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } else {
                    litespeed_finish_request();
                }
                $this->runQueuedNow($jobIds);
            } catch (\Throwable $e) {
                error_log('Better-Cal: running a plugin job now: ' . $e->getMessage());
            }
        });
        return true;
    }

    /** How long a post-response run waits for a worker run that's under way. */
    public const AFTER_RESPONSE_LOCK_WAIT = 10;

    /**
     * The post-response run itself (runAfterResponse), serialized like the
     * worker: it holds the worker's lock, which bin/update.php and the
     * database update page also take, and rechecks for maintenance and a
     * pending database update once it has the lock. So a run can't overlap an
     * update's backup, code swap or migration. Anything that stops it (the
     * lock busy past a short wait, an update under way, no lock to be had)
     * leaves the jobs queued for the worker. Returns how many ran.
     *
     * @param list<int> $jobIds
     */
    public function runQueuedNow(array $jobIds): int
    {
        $dsn = (string) ($this->cfg['db']['dsn'] ?? '');
        if ($jobIds === [] || $dsn === '') {
            return 0;
        }
        $lock = Updater::workerLockName($dsn);
        try {
            if ((int) $this->db->scalar('SELECT GET_LOCK(?, ?)', [$lock, self::AFTER_RESPONSE_LOCK_WAIT]) !== 1) {
                return 0;
            }
        } catch (\PDOException) {
            return 0; // no named locks here: the worker runs them
        }
        try {
            clearstatcache();
            $root = (string) ($this->cfg['app_root'] ?? dirname(__DIR__, 3));
            if (Maintenance::active($root) || InstallPages::needsDatabaseUpdate($this->cfg)) {
                return 0;
            }
            $queue = new JobQueue($this->db);
            $ran = 0;
            foreach ($jobIds as $jobId) {
                $job = $queue->claimById($jobId);
                if ($job === null) {
                    continue; // the worker took it meanwhile
                }
                $ran++;
                try {
                    $payload = json_decode((string) ($job['payload_json'] ?? '{}'), true) ?: [];
                    $this->runJob((int) ($payload['userId'] ?? 0), (string) ($payload['plugin'] ?? ''), (string) ($payload['job'] ?? ''), isset($payload['calendarId']) ? (int) $payload['calendarId'] : null);
                    $queue->markDone((int) $job['id']);
                } catch (\Throwable $e) {
                    $queue->markFailed($job, $e->getMessage());
                }
            }
            return $ran;
        } finally {
            try {
                $this->db->scalar('SELECT RELEASE_LOCK(?)', [$lock]);
            } catch (\PDOException) {
                // the connection closing releases it too
            }
        }
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
            if ($m === null || $this->missingRequired($id, $m) !== []) {
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
            // A band tied to a calendar goes with it: a deleted calendar's
            // bands stop showing (and return if the delete is undone).
            $rows = $this->db->all(
                'SELECT r.id, r.source_key, r.start_utc, r.end_utc, r.label, r.color, r.detail_html, r.calendar_id FROM plugin_ranges r
                 LEFT JOIN calendars c ON c.id = r.calendar_id
                 WHERE r.plugin_id = ? AND r.start_utc < ? AND r.end_utc > ? AND (r.calendar_id IS NULL OR c.id IS NOT NULL)
                 ORDER BY r.start_utc LIMIT ' . (self::RANGE_RESPONSE_CAP + 1),
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
                    'calendarId' => $r['calendar_id'] !== null ? (int) $r['calendar_id'] : null,
                ], $rows),
            ];
        }
        return $out;
    }

    public function warnings(string $id): array
    {
        $manifest = $this->manifest($id) ?? [];
        $private = $this->privateDiagnostics($id, $manifest);
        // Messages the manifest declares are fixed text: shown either way.
        $declared = array_values(array_filter(is_array($manifest['messages'] ?? null) ? $manifest['messages'] : [], 'is_string'));
        return array_map(static fn($r) => [
            'id' => (int) $r['id'],
            'eventId' => $r['event_id'] !== null ? (int) $r['event_id'] : null,
            'severity' => (string) $r['severity'],
            'message' => $private && !in_array((string) $r['message'], $declared, true) ? PluginSecrets::HIDDEN_DIAGNOSTIC : (string) $r['message'],
            'fix' => $private ? null : ($r['fix_text'] !== null ? (string) $r['fix_text'] : null),
        ], $this->db->all('SELECT * FROM plugin_warnings WHERE plugin_id = ? AND dismissed_at IS NULL ORDER BY severity DESC, id', [$id]));
    }
}
