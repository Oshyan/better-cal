# PRD: Better-Cal plugin system v1

Status: preliminary, for review. Grounded in [idea-evaluation.md](idea-evaluation.md) and [idea-ranking.md](idea-ranking.md); supersedes nothing, implements GH #4.

## Summary

A small plugin system for a single-user, self-hosted calendar. Plugins are PHP packages that run scheduled jobs in the existing worker, store their own data in namespaced tables, and contribute to the UI through three declarative output classes: materialized events in plugin-owned calendars, overlay ranges drawn by the existing band machinery, and warnings. Configuration is declarative schemas rendered by the host at plugin scope and per-calendar scope. Plugins ship no frontend code, run no code during page requests, and touch nothing per-occurrence.

The system is deliberately a generalization of four seams already proven in production: feed materialization (`Feeds::sync`), availability overlays (the `avail:` pseudo-occurrence path), per-calendar settings (`calendars.settings_json` + the gear panel), and the worker job queue. v1 ships with three demo plugins that each exercise a different output class: Weather (events), Tides (ranges), and Calendar Lint (warnings).

## Why this shape

The full idea list in #4 spans five extension shapes with wildly different costs. The evaluation found a clean split: everything data-provider-shaped and warning-shaped is a cheap generalization of existing code, while the planner, booking, solver, and arbitrary-UI families each cost more than the whole core and carry the worst failure modes. This PRD builds the first group, stages the middle (per-event data, automation, proposals) behind named v2/v3 gates, and refuses the heavy families outright so the architecture never has to carry hooks for them.

## Trust and security model

Stated plainly, because pretending otherwise would be worse than the reality:

- Installing a plugin means running its PHP with the same privileges as the app. There is no in-process sandbox for PHP worth pretending about. This is a self-hosted single-user system; the operator sets their own risk level, exactly as the issue said.
- The permissions block in a plugin's manifest is disclosure, not enforcement. It exists so the ops page can show what a plugin declares it does (network, events:write, notifications, llm), and so a reader can spot a weather plugin that asks for mail access. Enforcement-grade sandboxing is explicitly out of scope.
- What IS enforced, because the host owns these paths: all plugin-supplied text that reaches the DOM goes through the existing `Sanitize` allowlist on both server and client; plugins ship no markup, no CSS, and no JS; all plugin HTTP goes through a policied client (below); plugin event writes are restricted to calendars the plugin owns.
- The policied HTTP client denies private, link-local, and metadata address ranges after DNS resolution, enforces connect/total timeouts and a response size cap, limits redirects, and applies a per-plugin hourly request budget. This client also becomes the fix for the open BC-01/BC-02 SSRF findings by routing core fetchers (feeds, geocoding, push) through it.
- A plugin that fails repeatedly is automatically disabled (circuit breaker) and the failure is visible on the ops page. A disabled or uninstalled plugin can never strand invisible data: everything it owns is enumerable and removable.

## Goals

- One person can install a plugin by dropping a directory into `server/plugins/` and enabling it in the UI, with no build step, matching the codebase philosophy.
- The three demo plugins run on the production box with zero measurable change to the hot-path baselines (window 167ms median, month scroll 16.6ms median frame, 793 bytes/occurrence payload).
- A misbehaving plugin cannot break the calendar: worst case is stale plugin data plus a visible error on the ops page.
- The People availability overlay becomes the first consumer of the generalized client-side seam (provider registry for pseudo-occurrences, click routing, sidebar sections), so the plugin path is exercised by existing production behavior. People's data and management UI stay where they are.
- Every plugin action is attributed in the activity log under `plugin:<id>`.

## Non-goals (explicit, with reasons)

These are refusals, not deferrals, unless marked otherwise. The architecture should not carry accommodations for them.

- No auto-scheduling solver of any kind: no automatic placement of flexible items, no Reclaim-style habits or time defense, no SkedPal-style time-map enforcement, no route optimization. This is a distinct product domain that would permanently dominate the complexity budget. The surviving fragment is the proposal pattern (machine proposes, user accepts), staged for v3.
- No booking-write or booking-manage adapters: nothing that reserves, cancels, or spends on the user's behalf. Link-only booking handoff is the ceiling. The marquee reservation providers expose no public APIs, and external side effects with money attached are the wrong risk class for this system.
- No arbitrary plugin UI code: no plugin JS, no iframes, no sandbox. Declarative contributions rendered by the host, with sanitized rich text where prose is needed. Revisit only if a real plugin demonstrably cannot be expressed declaratively.
- No per-occurrence server hooks and no plugin data on the events-window response, ever. Plugins contribute ranges or materialize events in jobs; per-event plugin data (v2) rides the detail fetch only.
- No plugin code execution during page requests. Jobs and explicit user actions (settings save, "run now", uninstall) are the only entry points. Request handlers read plugin-written tables.
- No unbundling of People. It stays a built-in feature; other plugins reach people data through host services, not by importing People internals.
- No in-app scraping engine. Extraction belongs in companion services emitting feeds, or in custom-source plugins (v2) whose brittleness is their author's problem.
- No wearable or capacity ingest.
- No multi-party features (external overlays, shared availability); the product is single-user.
- No marketplace, dependency resolver, or auto-update. Installation is a directory and a git pull; "requires" in the manifest is checked at enable time against installed plugin ids and host version, nothing more.

## Architecture

### Execution model, the one rule that matters

Plugin code runs in exactly two places: worker jobs, and short synchronous handlers for explicit user actions (validate/save settings, "run now", uninstall hooks). All rendering-path data (events, ranges, warnings, decorations) is written to tables by jobs and read back by ordinary indexed queries in request handlers. Nothing a plugin does can add latency to the events window, the range fetch, or a scroll frame, because plugin code is structurally absent from those paths.

### Package layout and manifest

A plugin is a directory under `server/plugins/<id>/`:

```
server/plugins/weather/
  plugin.json
  Plugin.php
```

`plugin.json` (illustrative, not final):

```json
{
  "id": "weather",
  "name": "Weather",
  "version": "0.1.0",
  "minHost": "0.1.0",
  "permissions": ["http", "events:write", "ranges", "warnings"],
  "jobs": [{ "id": "refresh", "interval": "PT3H" }],
  "settings": [
    { "key": "units", "type": "select", "label": "Units", "options": ["F", "C"], "default": "F" },
    { "key": "days", "type": "number", "label": "Forecast days", "min": 1, "max": 14, "default": 10 }
  ],
  "calendarSettings": [
    { "key": "location", "type": "location", "label": "Forecast location" }
  ],
  "decoration": { "icon": "weather", "color": "#5b8dd9" }
}
```

`Plugin.php` implements a small interface. Sketch:

```php
interface BetterCalPlugin {
    public function runJob(PluginHost $host, string $jobId): void;
    public function validateSettings(array $values): array;   // returns errors, optional
    public function onUninstall(PluginHost $host): void;      // optional cleanup beyond host-managed data
}
```

### Host services (`PluginHost`)

Constructor-injected facade, in the codebase's existing DI style. v1 surface:

- `storage()`: namespaced KV (get/set/delete/list under the plugin id) plus `dropAll()` used by uninstall.
- `http()`: the policied client. The only sanctioned way for a plugin to reach the network.
- `events()`: feed-style batch upsert into a calendar this plugin owns (`ensureCalendar(name, color)` creates it with `kind: 'plugin'`), with stable source keys, disappearance deletion, ChangeLog, and activity attribution, all reusing the `Feeds::sync` mechanics.
- `ranges()`: replace-by-window or upsert-by-source-key writes into `plugin_ranges`.
- `warnings()`: replace-run writes into `plugin_warnings`, optionally keyed to an event id.
- `settings()`: read plugin-scope values and per-calendar values (stored under `plugins.<id>` in `calendars.settings_json`).
- `calendars()`: enumerate the user's calendars (id, name, kind, visibility) so providers can offer per-calendar behavior.
- `log(level, message)`: lands in the run record shown on the ops page.
- `geocode(query)`: pass-through to the existing geocoder, so location settings resolve consistently.

Deliberately absent in v1: raw DB handle (KV plus owned-calendar events cover the demos; per-plugin migrations are a v2 question), mail access, notification send (v2, permissioned), LLM (v2, permissioned), people write access (read-only people/availability queries considered for v2 alongside the travel and visit use cases).

### Output classes

1. Materialized events. Plugin-owned calendars behave like subscribed feeds: read-only in the UI, refreshable, deletable as a unit, visibility-toggled like any calendar, occurrences flow through the normal pipeline with zero schema changes. A weather day is just an event titled "72°/55°" with the plugin calendar's color and icon.
2. Overlay ranges. Rows in `plugin_ranges` fetched by one generic endpoint (`GET /api/v1/plugins/ranges?start&end`, indexed query, capped at 500 spans per plugin per request with the cap surfaced, not silent). The client synthesizes pseudo-occurrences with instanceId `plg:<plugin>:<row id>` through the same provider registry that availability migrates onto. Clicking a band opens a host-rendered info card: title, span, and sanitized rich text from the row's payload. Bands respect a per-plugin sidebar visibility section.
3. Warnings. Rows in `plugin_warnings` (plugin, optional event id, severity, message, optional suggested-fix text). Surfaced as a count on the ops page and a listing panel; warnings tied to events deep-link to them. Editor-inline lint is v2.

### Decorations

Declarative only: an icon from the host's icon set or a single emoji, plus a color, applied at the plugin-calendar level (rides existing calendar styling, zero per-occurrence bytes). Animated presets (pulse, shimmer) are a v2 addition to the same contract. No plugin-supplied SVG, CSS, or markup.

### Scheduling, budgets, health

- Manifest jobs are enqueued by the worker's existing `bc_enqueue_if_stale` mechanism as a `plugin_job` lane with payload `{plugin, job}`.
- Each run records start/duration/outcome/log tail to `plugin_runs` (or the job row), shown on the ops page.
- Soft wall-clock budget of 60s per run, checked by host helpers between units of work; hard caps live in the HTTP client (connect 5s, total 20s, 5MB response) so a hung fetch cannot hang the loop. The worker is a single loop guarded by GET_LOCK, so plugin jobs run after core jobs (mail, reminders) in each tick and are capped per tick.
- Circuit breaker: N consecutive failures (default 5) auto-disables the plugin, writes a warning row, and shows prominently on the ops page. Re-enabling resets the counter.

### Storage

New tables, all small at single-user scale:

- `plugins` (id, version, enabled, installed_at, settings_json, consecutive_failures, disabled_reason).
- `plugin_kv` (plugin_id, k, v_json, updated_at; PK plugin_id+k).
- `plugin_ranges` (id, plugin_id, source_key, start_utc, end_utc, label, color, payload_html_sanitized, updated_at; indexed plugin_id+start_utc and plugin_id+end_utc, unique plugin_id+source_key).
- `plugin_runs` (id, plugin_id, job_id, started_at, duration_ms, outcome, log_tail).
- `plugin_warnings` (id, plugin_id, event_id nullable FK, severity, message, fix_text nullable, created_at, dismissed_at nullable).

Per-calendar plugin values live under `plugins.<id>` inside the existing `calendars.settings_json`. Plugin-owned calendars are ordinary `calendars` rows with `kind: 'plugin'` and an owner column or KV marker.

### Lifecycle and uninstall

- Install: directory appears; host scans manifests on worker tick and settings-page load; plugin shows on the ops page disabled.
- Enable: manifest validated (id, version, minHost, requires), jobs scheduled, settings become editable.
- Disable: jobs stop, contributions stay visible but frozen, banner on ops page.
- Uninstall: impact preview first (counts of events, ranges, warnings, KV rows), then a choice for owned calendars: delete, or archive (calendar hidden and marked read-only-orphaned, exportable as ICS, matching the survey's refuse/archive/migrate requirement). Ranges, warnings, runs, and KV are always purged. The mutations journal keeps its attributed history either way.

### Activity integration

All plugin mutations run inside `ActivityContext::with('plugin:<id>', ...)`. The Activity page source chips gain a Plugins group. Materialized-event syncs summarize like feed polls do today (counts plus added titles), not one entry per event.

### Ops page

Manage → Plugins. Per plugin: name, version, enabled toggle, declared permissions as chips, last run (time, duration, outcome), next run, error tail, object counts (events, ranges, warnings), buttons for run now, settings, uninstall. This page is the whole observability story and ships in v1, not later.

## Performance budget (hard rules)

- Zero plugin code on request paths. Enforced structurally, not by review.
- Zero bytes added to the events-window payload. Decorations ride calendar-level styling; per-event plugin data, when it arrives in v2, rides the detail fetch only.
- `plugin_ranges` endpoint: one indexed query, 500-span cap per plugin per window, cap surfaced in the response so trimming is visible.
- Worker: 60s soft budget per run, HTTP hard caps, plugin jobs last in each tick, circuit breaker on repeated failure.
- HTTP: per-plugin hourly request budget (default 60), so a bad loop cannot hammer a provider or the network.
- Acceptance check before v1 ships: re-run the benchmarking baselines with all three demos enabled and live; any hot-path number moving more than the documented 30% noise threshold blocks release (see docs/benchmarking.md for the traps, especially the layout-dependent-code lesson: the overlay-registry refactor must re-test month stepping, the toolbar label, and window demand).

## v1 scope checklist

- [ ] `plugins`, `plugin_kv`, `plugin_ranges`, `plugin_runs`, `plugin_warnings` migrations
- [ ] Manifest scan, validation, enable/disable/uninstall with impact preview
- [ ] `PluginHost` with storage, http (policied, SSRF-safe), events (feed-style sync into owned calendars), ranges, warnings, settings, calendars, geocode, log
- [ ] Worker `plugin_job` lane with budget, per-tick cap, circuit breaker, run records
- [ ] Declarative settings renderer (text, number, select, toggle, location, person) at plugin scope (Settings page section) and calendar scope (gear panel section), server-validated
- [ ] Generic overlay provider registry client-side; availability migrates onto it; `plg:` click routing to host info cards; per-plugin sidebar visibility sections
- [ ] Decorations v1 (icon/emoji + color at calendar level)
- [ ] Ops page
- [ ] Activity source `plugin:<id>` plus Activity page chip group
- [ ] Route core fetchers (feeds, geocode, push) through the policied HTTP client (BC-01/BC-02 remediation)
- [ ] Demo: Weather. Open-Meteo, per-calendar location, daily forecast events 10 days out in an owned "Weather" calendar, refresh every 3h, icon+temps in title, severe-weather warning rows
- [ ] Demo: Tides. NOAA CO-OPS station per settings, high/low extremes as timed events and low-tide daylight windows as ranges, refresh daily
- [ ] Demo: Calendar Lint. Declarative rule config (preferred-hours window, max distance between back-to-back events via haversine, duplicate-title-same-day), daily audit writing warnings
- [ ] Docs: plugin author guide (manifest, host API, the execution-model rule, sanitization expectations, uninstall behavior)
- [ ] Tests: manifest validation, host sync/dedup behavior, range caps, circuit breaker, sanitizer on payloads, settings validation; smoke coverage for the overlay registry refactor

## Staged next (named gates, not promises)

- v2, each pulled individually when wanted: per-event keyed data on the detail path plus event-scope declarative controls; editor-inline lint; run-grouped undo plus the automation-rules plugin (dry-run mandatory); plugin notifications; LLM permission; animated decoration presets; custom-source plugin type (fetch+parse into materialization); travel-time/leave-by plugin (heuristic first); time accounting plugin; read-only people/availability queries for plugins.
- v3, gated on consumers: proposal objects built once for the AI Trip Planner (#3) with preview/accept/reject and atomic materialization into a Trip; Visit Intents v1 (wishlist, hours-aware opportunity ranges, link-only booking) as the second range-heavy consumer; capability/provider-query indirection only if two plugins actually need to share provider data.

## Risks

- Worker contention: single loop means a slow plugin job delays core jobs within a tick; budgets and ordering mitigate, subprocess isolation is the future hardening if it proves insufficient in practice.
- Overlay-registry refactor: it touches App.js, Sidebar, and click routing; the month-navigation regression from the row-height change is the cautionary tale, so everything that reasons about layout gets re-tested, not just the new path.
- Provider drift: Open-Meteo and NOAA schemas can change; demos should fail into warnings and stale-data banners, never into broken calendars.
- Scope creep: the idea list is a product line; the system stays a seam collection. The Non-goals section exists to be pointed at.

## Open questions

- Job isolation: accept soft budgets in-process for v1, or spawn plugin jobs as short-lived subprocesses from the start (cleaner kill semantics, more moving parts)?
- Range info cards: payload-only in v1 (current plan), or allow a fetch-on-open hook for live detail at the cost of a plugin-code request path exception?
- Archived-orphan calendars: keep indefinitely with an ops-page nudge, or auto-expire after N months?
- Where per-plugin secrets (API keys for keyed providers) live: plugin settings marked `secret` rendered as password fields, presumably, but storage-at-rest treatment should be decided deliberately.
- Whether `kind: 'plugin'` calendars appear in CalDAV like subscribed calendars do (probably yes, read-only, but confirm client behavior).

## Success criteria

- All three demos live on cal.oshyan.com with hot-path baselines unchanged within the documented noise threshold.
- Killing, breaking, or uninstalling any demo plugin leaves the core calendar fully functional with no orphaned invisible data.
- The availability overlay runs through the generic registry with no user-visible change.
- A third party can write a working provider plugin from the author guide without reading host source.
- BC-01/BC-02 are closed by the policied HTTP client rollout.
