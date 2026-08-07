# Writing a Better-Cal plugin

A plugin is a directory under `server/plugins/<id>/` with two files: `plugin.json` (the manifest) and `Plugin.php` (the code). Drop it in, open Manage → Plugins, and enable it. No build step, no registration, no autoloader coordination.

This guide is the contract. The three bundled plugins (`weather`, `tides`, `lint`) are working references for the three output shapes.

## The one rule

Your plugin's code runs in exactly two places: inside a worker job (`runJob`), and inside the settings-validation hook (`validateSettings`) when a user saves settings. It never runs during a page request. Request handlers read the tables your jobs wrote. This is what keeps the calendar fast no matter what a plugin does, and it is enforced structurally — there is no hook that runs your code on the render path, so there is no way to slow the window down.

Practically: do your fetching, computing, and writing in `runJob`. Everything the user sees is a side effect of a completed run.

## Manifest (`plugin.json`)

```json
{
  "id": "weather",
  "name": "Weather",
  "version": "0.1.0",
  "minHost": "0.1.0",
  "description": "One line shown on the ops page.",
  "permissions": ["http", "events:write", "warnings"],
  "jobs": [{ "id": "refresh", "interval": "PT3H" }],
  "settings": [
    { "key": "location", "type": "location", "label": "Forecast location" },
    { "key": "units", "type": "select", "label": "Units", "options": ["F", "C"], "default": "F" }
  ],
  "calendarSettings": [
    { "key": "exclude", "type": "toggle", "label": "Skip this calendar", "default": false }
  ]
}
```

- `id` must be lowercase kebab-case and match the directory name.
- `version` is semver. `minHost` (optional) refuses install on an older host.
- `permissions` is disclosure, shown on the ops page: `http`, `events:write`, `ranges`, `warnings`, `geocode`. It is not a sandbox (see Trust, below) — declare what you use so the operator can see it.
- `jobs[].interval` is an ISO 8601 duration (`PT3H`, `PT12H`, `P1D`). Staleness is judged from the last run, so a failing job still waits its interval.
- `settings` / `calendarSettings` are declarative field schemas the host renders. Field types: `text`, `number` (`min`/`max`), `select` (`options`), `toggle`, `location` (place picker → `{name, lat, lng}`), `person` (name string). Every field may have a `default`.

## Code (`Plugin.php`)

`require` returns an instance implementing `PluginInterface`:

```php
<?php
declare(strict_types=1);
use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;

return new class implements PluginInterface {
    public function validateSettings(array $values): array {
        // Return {key: error}. Empty = accept. Runs synchronously on save.
        if (($values['location'] ?? null) === null) return ['location' => 'required'];
        return [];
    }

    public function runJob(PluginHost $host, string $jobId): void {
        $s = $host->settings();                          // your plugin-scope settings
        $data = $host->http()->getJson('https://…');     // policied fetch (SSRF-safe)
        $cal = $host->ensureCalendar('Weather', '#5b8dd9');
        $host->syncEvents($cal, [/* {sourceKey, title, start, allDay, …} */]);
        $host->log('done');                              // shows on the ops page
    }
};
```

## The host API (`PluginHost`)

- `settings(): array` — plugin-scope values (stored over manifest defaults).
- `calendarSettings(int $calendarId): array` — per-calendar values for your plugin.
- `calendars(): array` — the user's calendars (`id`, `name`, `kind`, `visible`).
- `http(): HttpClient` — `get($url)` / `getJson($url)`. Refuses private/loopback/link-local/metadata addresses (resolved before connect, pinned against DNS rebind), caps at 5 MB / 20 s / 3 redirects, and enforces a per-run request budget.
- `geocode(string $query): ?array` — the host geocoder (cached).
- `ensureCalendar(name, color): int` — find-or-create a calendar YOU own.
- `syncEvents(calId, events): [added, updated, removed]` — feed-style upsert by `sourceKey` into an owned calendar; disappeared keys are deleted. Event: `{sourceKey, title, start, end?, allDay?, description?, location?}`. `start`/`end` are `YYYY-MM-DD` for all-day (end exclusive) or ISO instants.
- `replaceRanges(ranges): int` — wholesale-replace your overlay bands. Range: `{sourceKey, start, end, label?, color?, detailHtml?}`.
- `replaceWarnings(warnings): int` — wholesale-replace your findings. Warning: `{message, severity?, eventId?, fix?}`.
- `eventsWindow(startIso, endIso): array` — read the user's occurrences through the real pipeline (for audits). Worker-side only.
- `kvGet/kvSet/kvDelete` — namespaced storage that survives between runs; dropped on uninstall.
- `log(msg)` / `budgetRemaining()` / `overBudget()` — the 60 s soft budget; long loops should check and stop.

All text you pass through `syncEvents`, `replaceRanges`, or `replaceWarnings` is sanitized by the host (the same allowlist as event descriptions), server-side at write and again client-side at render. You ship data, never markup — `detailHtml` is the one rich field and it is sanitized both ways.

## Output shapes

1. **Materialized events** (`syncEvents`) → a read-only calendar that behaves like a subscribed feed: visibility toggle, normal rendering, zero window-payload cost. See `weather`.
2. **Overlay bands** (`replaceRanges`) → non-event ranges drawn by the band machinery; clicking one opens a host info card with your `detailHtml`. Gets a sidebar visibility toggle. See `tides`.
3. **Warnings** (`replaceWarnings`) → findings on the ops page, optionally deep-linked to an event. No calendar objects. See `lint`.

## Lifecycle and health

- **Enable** schedules your jobs; the first run lands on the next worker tick (~1 min) or immediately via "Run now".
- **Circuit breaker**: 5 consecutive failed/timed-out runs auto-disable the plugin with the reason shown on the ops page. A successful run resets it.
- **Uninstall** always purges your ranges, warnings, runs, and KV. Owned calendars are either deleted (with events) or archived (hidden local calendars, events kept) — the user chooses.

## Trust

This is a single-user, self-hosted system. Installing a plugin runs its PHP with the app's privileges; there is no in-process sandbox and the manifest's permission list is disclosure, not enforcement. What the host *does* enforce: outbound HTTP goes through the SSRF-safe client, event writes are restricted to calendars your plugin owns, and all plugin text is sanitized before it reaches the DOM. Review a plugin's code before enabling it, exactly as you would any code you run on your own server.
