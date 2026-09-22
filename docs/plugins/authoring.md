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
- `decoration` is optional: `{icon, color, animation}` where animation is `none`, `pulse`, or `shimmer`. `icon` is **either** a name from the host icon set **or** a single emoji (`"⛅"`, `"🌊"`). A value shaped like an identifier is treated as a host icon name and **refused at install if it does not exist**, so a plausible guess like `"map-pin"` fails loudly rather than rendering nothing. The set is: `settings outfeeds filters plus search quickadd close chevronLeft chevronRight chevronUp arrowUpRight check star video warning stack menu chevronDown brand activity views folder mixed visAll visNone reschedule pencil trash arrowLeft note keyboard expand bell activeOnly lock unlock calendar today viewMonth viewWeek viewWeeks3 viewWeeks2 viewDay viewAgenda plugins proposals people trip`, plus the information icons `sun sunrise sunset air tideHigh tideLow cloud rain snow storm thermometer moon flag`. If none fits, use an emoji. The icon appears on your overlay bands and in place of the colour dot on your calendar's sidebar row.
- `decoration.iconPath` ships **your own icon as SVG path data** when neither a host name nor an emoji will do: `{"iconPath": "M2 8 L8 2 L14 8"}`. You supply only the `d` string; the host builds the `<svg>` around it with its own 16x16 viewBox, sizing and `currentColor` stroke. You never supply markup, so there is no element to carry a script, a `foreignObject`, or an external reference. The string may contain only path commands (`MmLlHhVvCcSsQqTtAaZz`), digits, `.,-+eE` and whitespace, must begin with a moveto, and is capped at 2000 characters; anything else is refused at install. `iconPath` wins over `icon` when both are given.
- `settings` / `calendarSettings` / `eventSettings` field details: Field types: `text` (500 chars), `textarea` (multi-line, 10000 chars, `rows`), `number` (`min`/`max`), `select` (`options`), `toggle`, `location` (place picker → `{name, lat, lng}`, coordinates range-checked), `person` (name string). Every field may have a `default`. `text` and `textarea` accept `maxLength` to lower their own ceiling; `textarea` may also raise it up to 10000. **There is no list field type** — a list-shaped setting goes in a `textarea`, one item per line.

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
        // $values holds ONLY the keys being saved right now — not the merged
        // result. Validate a field when it is present and leave it alone when
        // it is not, or single-field saves will fail on untouched fields.
        if (array_key_exists('units', $values) && !in_array($values['units'], ['F', 'C'], true)) {
            return ['units' => 'must be F or C'];
        }
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
- `http(): HttpClient` — `get($url)` / `getJson($url)`. Refuses private/loopback/link-local/metadata addresses (resolved before connect, pinned against DNS rebind), caps at 5 MB / 20 s / 3 redirects, and allows **60 requests per run** (each redirect hop spends one). Note that three requests at the permitted 20 s each already exceed the 60 s run budget, so the wall clock binds long before the request count does.
- `geocode(string $query, ?float $biasLat = null, ?float $biasLng = null): ?array` — the host geocoder (cached). Returns `{lat, lng, display, kind}` — `kind` is the provider's own word for what it found (`city`, `town`, `state`, `country`, `restaurant`, …) or null if it did not say, and it is how you tell a region from a destination without geocoding again. Returns **null when the place is not found or the provider is unreachable** — the two are indistinguishable, so treat null as "no answer", not "no such place". Without a bias the provider ranks globally and a bare venue name lands wherever the best string match is: "Greens Restaurant Fort Mason" resolves to Toronto. A bias helps when the right place is *somewhere* in the provider's candidates, but it only reranks that list — it cannot conjure a venue the provider does not hold, and it will then pick the nearest wrong answer instead of the far one. Treat any geocode of a user-typed venue name as a guess: check the `display` string you get back before acting on the coordinates, and prefer a full street address as the query.

**Do not build your own geocoder.** The host's already ranks same-named places by significance and consults a second provider when the first returns a small settlement, which is what stops "Lisbon" landing in Iowa; a plugin-local one starts from scratch and gives the user a second set of answers to reconcile. If it is getting something wrong, that is a host bug worth reporting rather than routing around.
- `ensureCalendar(name, color): int` — find-or-create a calendar YOU own.
- `syncEvents(calId, events): [added, updated, removed]` — feed-style upsert by `sourceKey` into an owned calendar; disappeared keys are deleted. Event: `{sourceKey, title, start, end?, allDay?, description?, location?, icon?}`. `start`/`end` are `YYYY-MM-DD` for all-day (end exclusive) or ISO instants. `icon` is optional and per event, a host icon name or a single glyph exactly as `decoration.icon` accepts; a bad one is dropped silently. On a context calendar (the role a plugin calendar gets by default) the icon is what the day's header shows beside the value, so a weather plugin says `rain` on the rainy day and `sun` on the sunny one, an air-quality plugin `air`, a tides plugin `tideLow`/`tideHigh`, a sun plugin `sunrise`/`sunset`. Without an icon the host guesses from the title (numbers like `72/58 fog`, the words AQI, sunset, sunrise, high/low tide) and otherwise shows the calendar's square.
- `replaceRanges(ranges): int` — wholesale-replace your overlay bands. Range: `{sourceKey, start, end, label?, color?, detailHtml?}`. You may write up to 2000, but **any single window request returns at most 500 per plugin** (with a `truncated` flag the client reads), so ranges past that are stored and never seen. If you are producing more than a few hundred bands, narrow what you emit rather than relying on the write cap.
- `replaceWarnings(warnings): int` — wholesale-replace your findings. Warning: `{message, severity?, eventId?, fix?}`.
- `eventsWindow(startIso, endIso): array` — read the user's occurrences through the real pipeline (for audits). Worker-side only. **Read the semantics below before doing day math with these.**
- `kvGet/kvSet/kvDelete` — namespaced storage that survives between runs; dropped on uninstall.
- `hasPlugin(id)` / `publish(key, value, ttl?)` / `readPublished(pluginId, key)` / `publishedKeys(pluginId)` / `unpublish(key)` — see "Working with other plugins".
- `httpCached(url, ttl)` / `getJsonCached(url, ttl)` — policied GET through a cache shared by every plugin.
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

The window is **not capped** — it returns every occurrence in the range you ask for, the same set the calendar itself renders. Ask for the narrowest range you can, since a year-wide window on a busy calendar is several thousand occurrences and you are spending the 60 s run budget to expand them.

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
- `withdrawProposal(string $sourceKey): bool` — retract one of your still-open proposals. Decided ones are untouched. Requires `propose`.
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
| **saving settings** | `person` → 120 chars, `location.name` → 200. Over-long `text`/`textarea`, non-scalar values, and out-of-range coordinates are **refused with a 400**, not silently coerced — a manifest whose `default` overflows its own field is refused at install for the same reason. |

**`validateSettings($values)` receives only the keys being saved**, not the effective settings — the host merges your `$clean` over what is stored afterward. A rule like `if (!isset($values['location'])) return ['location' => 'required'];` therefore makes every other single-field save fail on a field the user never touched, and cross-field rules never fire on a partial save. Validate present keys only; enforce "required" in `runJob`, where you can see the merged result from `settings()`.

**Renaming or removing a settings key strands whatever the user had stored under it.** `settings()` only returns keys your current manifest declares, so a value under a dropped key becomes unreachable while still sitting in the database. If you reshape your settings, read the old keys for a version or two and write the merged result back before you drop them.

Two lifetime rules that differ from each other:

- `replaceRanges` and `replaceWarnings` are **wholesale replacements** — whatever you don't include this run is gone.
- `setEventData` is a plain **upsert that persists forever**. If you write a computed value (a travel estimate, say), you own clearing it when it goes stale; otherwise an event that moved keeps yesterday's answer. `eventsWithData($key)` exists so you can sweep your own leftovers.

**`eventSettings` keys and keys you write share one namespace** under your plugin id. Keep them distinct or a job will clobber the user's own choice.

Budget note: `llmJson()` allows up to 45 s against the 60 s run budget, so **two model calls cannot fit in one job**.

### Per-event data and event-scope controls (C7/C8)

Declare `eventSettings` in the manifest and the host renders those controls in the event detail view; the user's answers land in `event_plugin_data` under your plugin id and are readable via `eventData()`. Your job can also write values there itself (a computed travel time, say) and the detail view will show them.

**This data rides the event-detail fetch, never the events window.** That is deliberate and load-bearing: the window serves thousands of occurrences at ~348 bytes each (down from 793 after #15, which moved everything the grid does not draw onto the single-event record), and unbounded per-event plugin data on that path would undo that. There is no API to put anything on the window today; whether a small, host-capped per-occurrence channel should exist is #51.

### Working with other plugins

Three things exist, and they are deliberately all data rather than control.

**Know whether another plugin is there.** `hasPlugin(string $id): bool` is true when that plugin is installed *and* enabled. This is how you build an optional dependency: fold in what a weather plugin publishes when one is present, say less when it is not, and never duplicate its work or hard-fail without it.

**Declare a dependency in the manifest.** `"requires": ["open-meteo"]` is enforced: the host refuses to enable your plugin until every id listed is installed and enabled, and says which one is missing. `"optional": ["weather"]` is disclosure only, shown on the ops page so a user can see what your plugin would use if they added it. A plugin cannot depend on itself, and both are lists of plugin ids.

**Publish data for others to read.**

```php
$host->publish('normals.v1', $normals, 86400 * 30);   // key, value, optional TTL seconds
$host->readPublished('weather', 'normals.v1');        // null if absent, disabled, or expired
$host->publishedKeys('weather');                      // [key => lastUpdatedIso], to discover a surface
$host->unpublish('normals.v1');                       // stays yours, stops being public
```

`publish` marks one of your own kv entries readable by any other enabled plugin; everything else in your kv stays private. `readPublished` returns null when the plugin is absent, disabled, never published that key, or the value expired — indistinguishable on purpose, because the right response to all four is the same: do without it.

**What you publish is a public contract.** Version the key (`normals.v1`) instead of changing a shape other plugins already read, since you cannot see who depends on you.

**Sharing a fetch is a separate problem, and the host solves it.** If two plugins want the same upstream data, the expensive part is the request, not the parsing:

```php
$data = $host->getJsonCached('https://archive-api.open-meteo.com/…', 86400 * 30);
```

`httpCached(url, ttl)` / `getJsonCached(url, ttl)` go through the same SSRF-safe client but hit a cache **shared by every plugin**, keyed by the exact URL. A hit costs nothing against your request budget; a miss is billed normally; non-2xx is never cached. Two plugins asking Open-Meteo for the same coordinates pay for one fetch, with no dependency to declare and nothing that breaks when one of them is disabled.

There is deliberately **no way for one plugin to call another's code**. If you find yourself wanting that, publish the answer instead.

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

A plan event takes `{title, start, end?, allDay?, location?, description?, calendarId?}`. **`allDay` defaults to false**, so a date-only `start` like `'2026-09-05'` becomes a *timed* midnight-to-midnight event rather than an all-day one — unlike `syncEvents`, which infers all-day from the date shape. Pass `allDay => true` explicitly.

`withdrawProposal(string $sourceKey): bool` retracts one of your own **open** proposals, returning true if it removed one. Decided proposals are left alone: an accepted plan is on the calendar and a rejection is the user's answer, and neither is yours to erase. Use it when your answer moves — a plugin that proposed a weekend which is no longer free should take the suggestion back rather than leave the user to dismiss it.

That still leaves the `sourceKey` question. Re-proposing replaces your own open proposal and silently no-ops on a decided one, so a key derived from the answer (`trip-2026-11-02`) accumulates stale proposals unless you withdraw them, while a fixed key (`next-trip`) is silenced forever by one rejection. The pattern that works: a **generation counter in `kvSet`** — keep one key like `plan-g3`, bump the generation when the user rejects, and keep your own record of what each generation offered, because `myProposals()` returns only `{sourceKey, status}` and no content.

**Statuses are `open`, `accepted`, `rejected`** — plus one transition worth planning for: undoing an accepted proposal returns it to `open`, so a key you had stood down on can come back. `myProposals($status)` returns `[{sourceKey, status}]`; pass `null` for all of them. Re-proposing the same `sourceKey` replaces an **open** proposal in place, but on a proposal the user already decided `propose()` **silently no-ops and returns the old one** — so check `myProposals(null)` first and skip decided keys, or a daily job will spend a model call every day re-proposing a day the user already rejected.

`myProposals()` deliberately returns no content, so "has anything actually changed since I last proposed?" is yours to answer — keep a fingerprint of your inputs in `kvSet` and compare before doing expensive work.

`notify()` does **not** dedupe. A job on a 6-hour interval that keeps finding the same conflict will notify every run; track what you already said in `kvSet` and stay quiet.

All text you pass through `syncEvents`, `replaceRanges`, or `replaceWarnings` is sanitized by the host, server-side at write and again client-side at render. You ship data, never markup — `detailHtml` and `rationaleHtml` are the rich fields and they are sanitized both ways.

The allowlist is exactly these tags:

```
p  br  b  strong  i  em  u  a  ul  ol  li  div
```

Everything else is unwrapped to its text content — including **headings**, so `<h4>` section titles collapse your carefully structured card into one wall of prose, and **tables**, so a `<table>` becomes a run of concatenated cell text. Use `<div>` + `<b>` for headings and `<ul>` for anything tabular. **Every attribute is dropped except `href` on `<a>`**, and only absolute `http(s)` URLs survive: a relative href has its anchor unwrapped, so a plugin cannot link inward to the event that caused its own finding. Use the `eventId` field on a warning for that instead.

## Output shapes

1. **Materialized events** (`syncEvents`) → a read-only calendar that behaves like a subscribed feed: visibility toggle, normal rendering, zero window-payload cost. See `weather`.
2. **Overlay bands** (`replaceRanges`) → non-event ranges drawn by the band machinery; clicking one opens a host info card with your `detailHtml`. Gets a sidebar visibility toggle. See `tides`.
3. **Warnings** (`replaceWarnings`) → findings on the ops page, optionally deep-linked to an event. No calendar objects. See `lint`.

## Lifecycle and health

- **Enable** schedules your jobs; the first run lands on the next worker tick (~1 min) or immediately via "Run now".
- **Circuit breaker**: 5 consecutive failed/timed-out runs auto-disable the plugin with the reason shown on the ops page. A successful run resets it.
- **A run is not a transaction.** Throwing part-way through `runJob` marks the run failed but does **not** roll back what you already wrote — ranges, warnings, events, KV and proposals from the first half all persist. This matters most for the "have I already announced this?" fingerprint the `notify()` note below recommends: write it on the **last** line of the job, after the side effect it guards, or a later throw permanently suppresses a notification that never actually went out.
- **Uninstall** always purges your ranges, warnings, runs, proposals, per-calendar settings, and KV, and the receipt counts each. Owned calendars are either deleted (with events) or archived (hidden local calendars, events kept) — the user chooses.
- **No plugin ever executes inside another's job.** Plugins share *data*, never control: there is no way to call another plugin's code. That is what keeps the model simple — no budget to attribute across a boundary, no exception to propagate, no permission to inherit, and no load order. See "Working with other plugins" below for what you can do.

## Trust

This is a single-user, self-hosted system. Installing a plugin runs its PHP with the app's privileges; there is no in-process sandbox and the manifest's permission list is disclosure, not enforcement. What the host *does* enforce: outbound HTTP goes through the SSRF-safe client, event writes are restricted to calendars your plugin owns, and all plugin text is sanitized before it reaches the DOM. Review a plugin's code before enabling it, exactly as you would any code you run on your own server.
