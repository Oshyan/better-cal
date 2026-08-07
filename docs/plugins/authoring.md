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
- `permissions` is shown on the ops page: `http`, `events:write`, `ranges`, `warnings`, `geocode`, `people`, `notify`, `llm`, `propose`. For the four capabilities that reach outside your own data (`people`, `notify`, `llm`, `propose`) the declaration is **enforced** — calling them undeclared throws. The rest are disclosure (see Trust, below).
- `jobs[].interval` is an ISO 8601 duration (`PT3H`, `PT12H`, `P1D`). Staleness is judged from the last run, so a failing job still waits its interval.
- `settings` / `calendarSettings` / `eventSettings` are declarative field schemas the host renders — at plugin scope (ops page), per calendar (calendar gear panel), and per event (event detail view).
- `decoration` is optional: `{icon, color, animation}` where animation is `none`, `pulse`, or `shimmer`.
- `settings` / `calendarSettings` / `eventSettings` field details: Field types: `text`, `number` (`min`/`max`), `select` (`options`), `toggle`, `location` (place picker → `{name, lat, lng}`), `person` (name string). Every field may have a `default`.

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
- `eventsWindow(startIso, endIso): array` — read the user's occurrences through the real pipeline (for audits). Worker-side only. **Read the semantics below before doing day math with these.**
- `kvGet/kvSet/kvDelete` — namespaced storage that survives between runs; dropped on uninstall.
- `log(msg)` / `budgetRemaining()` / `overBudget()` — the 60 s soft budget; long loops should check and stop.

### What `eventsWindow()` returns

Each occurrence has exactly these keys:

```
eventId, calendarId, title, start, end, allDay, location, lat, lng, isContainer, recurring
```

Three things about the timestamps that will bite you otherwise:

- **`start`/`end` carry each EVENT'S OWN timezone offset**, not UTC and not a viewer's zone. Never compare them as strings — `"...T09:00:00-07:00"` sorts before `"...T10:00:00-04:00"` lexically but is the *later* instant. Sort by parsed instant (`strtotime`, `DateTimeImmutable`).
- **All-day occurrences come back as `YYYY-MM-DDT00:00:00+00:00`** — a literal calendar date pinned at `+00:00`, not an instant. Do **not** timezone-convert it; converting slides Saturday into Friday west of UTC. Its `end` is exclusive.
- `lat`/`lng` are renamed from the event's `locationLat`/`locationLng`, and are null unless the event has resolved coordinates.

**The window includes plugin-owned calendars.** Anything judging "is this day free?" must skip `kind === 'plugin'` from `calendars()`, or the Weather plugin's one-all-day-event-per-day makes every day look occupied. Consider also offering a `calendarSettings` toggle so the user can exclude their own chore or task calendars — recurring all-day chores are all-day events too, and they are not what "this day is taken" means.

### v2 capabilities

- `eventData(int $eventId): array` — your keyed values for one event.
- `setEventData(int $eventId, string $key, mixed $value): void` — write one (null deletes). Only for events the user owns.
- `eventsWithData(string $key): array` — `eventId => value` for every event carrying that key, for batch work.
- `people(): array` — `[{id, name}]`. Requires `people`.
- `availability(string $startIso, string $endIso): array` — `[{personId, name, kind, start, end}]` overlapping the window. Requires `people`.
- `notify(string $title, string $body, string $url = '/'): array` — one notification now, through the user's configured channel; silent if no channel is configured. Requires `notify`.
- `llmJson(string $prompt, array $schemaHint = []): ?array` — ask the model for JSON. **Returns null when unconfigured or on failure, indistinguishably** — always have a deterministic fallback. Requires `llm`.
- `propose(array $proposal): array` — offer a plan (below). Requires `propose`.
- `myProposals(?string $status = 'open'): array` — `[{sourceKey, status}]`, so a re-run can see what it already offered.
- `runId(): ?string` — the id grouping this run's mutations.
- `timezone(): DateTimeZone` — **the user's** zone. Use this for any local time you build; `date_default_timezone_get()` is the server's zone and may be a continent away.

### Host coercions you will not be told about

The output methods sanitize and clamp silently. Plan for it rather than discovering it:

| method | silently does |
|---|---|
| `syncEvents` | `title` → 500 chars, `location` → 300, description through the HTML sanitizer; rows without a `sourceKey` are skipped |
| `replaceRanges` | drops a `color` that is not `#rrggbb`, `label` → 200 chars, `sourceKey` → 160, whole array capped at 2000 |
| `replaceWarnings` | array capped at 500, `message` → 500 chars, `fix` → 300; `severity` accepts only `info` or `warn` (anything else becomes `warn`) |
| `setEventData` | key → 120 chars; passing `null` deletes the key |

Two lifetime rules that differ from each other:

- `replaceRanges` and `replaceWarnings` are **wholesale replacements** — whatever you don't include this run is gone.
- `setEventData` is a plain **upsert that persists forever**. If you write a computed value (a travel estimate, say), you own clearing it when it goes stale; otherwise an event that moved keeps yesterday's answer. `eventsWithData($key)` exists so you can sweep your own leftovers.

**`eventSettings` keys and keys you write share one namespace** under your plugin id. Keep them distinct or a job will clobber the user's own choice.

Budget note: `llmJson()` allows up to 45 s against the 60 s run budget, so **two model calls cannot fit in one job**.

### Per-event data and event-scope controls (C7/C8)

Declare `eventSettings` in the manifest and the host renders those controls in the event detail view; the user's answers land in `event_plugin_data` under your plugin id and are readable via `eventData()`. Your job can also write values there itself (a computed travel time, say) and the detail view will show them.

**This data rides the event-detail fetch, never the events window.** That is deliberate and load-bearing: the window serves thousands of occurrences at ~793 bytes each, and per-event plugin data on that path would undo the entire payload budget. There is no API to put anything on the window.

### Proposals (C11)

For anything that suggests a plan rather than performing it. Generating a proposal **never touches the calendar** — only the user pressing Accept does, and acceptance materializes the whole plan atomically under one run id, so undoing it reverses everything at once.

```php
$host->propose([
    'sourceKey' => 'weekend-2026-09-05',   // stable: re-running REPLACES your own open proposal
    'title' => 'A day in Point Reyes',
    'summary' => 'Low tide at 1:40 PM, clear, Marcus is around.',
    'rationaleHtml' => '<p>Why these times…</p>',   // sanitized by the host
    'plan' => [
        'trip' => ['title' => 'Point Reyes day', 'start' => '2026-09-05', 'end' => '2026-09-06'],
        'events' => [
            ['title' => 'Drive out', 'start' => '2026-09-05T09:00:00-07:00', 'end' => '2026-09-05T10:30:00-07:00'],
            ['title' => 'Tidepools', 'start' => '2026-09-05T13:00:00-07:00', 'end' => '2026-09-05T15:00:00-07:00', 'location' => 'Point Reyes'],
        ],
    ],
]);
```

The `plan` is data the host executes through the ordinary Events domain, so accepted events get the same validation, activity attribution, and undo snapshots as anything created by hand. A `trip` key wraps the events in a Trip container and links them.

Enforced plan rules — breaking one throws out of `propose()`, which fails the job and counts toward the circuit breaker, so validate before you call:

- `events` must be non-empty and at most 50; each needs a `title` and a `start`.
- `trip`, if present, needs `title`, `start`, and `end`. It is always created all-day and as a container.
- Events land on the user's default local calendar unless you pass `calendarId`.

**Statuses are `open`, `accepted`, `rejected`.** `myProposals($status)` returns `[{sourceKey, status}]`; pass `null` for all of them. Re-proposing the same `sourceKey` replaces an **open** proposal in place, but on a proposal the user already decided `propose()` **silently no-ops and returns the old one** — so check `myProposals(null)` first and skip decided keys, or a daily job will spend a model call every day re-proposing a day the user already rejected.

`myProposals()` deliberately returns no content, so "has anything actually changed since I last proposed?" is yours to answer — keep a fingerprint of your inputs in `kvSet` and compare before doing expensive work.

`notify()` does **not** dedupe. A job on a 6-hour interval that keeps finding the same conflict will notify every run; track what you already said in `kvSet` and stay quiet.

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
