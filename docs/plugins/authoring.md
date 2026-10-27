# Writing a Better-Cal Plugin

A plugin is a directory under `server/plugins/<id>/` with two files: `plugin.json` (the manifest) and `Plugin.php` (the code). Drop it in, open Manage → Plugins and enable it. There's no build step, registration or autoloader.

This guide is the contract. The bundled plugins are working references: `weather` and `sun` for materialized events with per-event icons (`sun` needs no network), `tides` for events plus overlay bands, `lint` for warnings, `fibery` for an authenticated API, discovered settings and two-way dates, and `dayplanner`, `travel`, `trip-planner` and `visit-intents` for proposals, cross-plugin reads and heavier logic.

## The One Rule

Your code runs in a worker job (`runJob`) and inside `validateSettings` when a user saves plugin-scope settings. The one exception is `applyEdit` (see [Rescheduling From the Calendar](#rescheduling-from-the-calendar)), which runs when the person moves one of your events. Nothing runs on the render path; request handlers only read the tables your jobs wrote, so a plugin can't slow the calendar down.

So do your fetching, computing and writing in `runJob`. Everything the user sees is a side effect of a completed run.

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

- `id`: lowercase kebab-case, 3-64 characters, starting with a letter, and matching the directory name. `name` is required.
- `version`: semver (`x.y.z`). `minHost` (optional) refuses install on an older host.
- `permissions`, shown on the ops page: `http`, `events:write`, `events:edit`, `ranges`, `warnings`, `geocode`, `people`, `notify`, `llm`, `propose`. The four that reach outside your own data (`people`, `notify`, `llm`, `propose`) are **enforced**: calling them undeclared throws. `events:edit` makes your calendars reschedulable. The rest are disclosure (see Trust).
- `jobs`: each has a kebab-case `id` and an ISO 8601 `interval` (`PT1M`, `PT3H`, `P1D`). Staleness is judged from the last run, so a failing job still waits its interval. `"runOnSave": true` also runs it right after the user saves your settings.
- `settings`, `calendarSettings`, `eventSettings`: field schemas the host renders on the ops page, in the calendar gear panel, and in the event detail view.
- `requires` / `optional`: lists of plugin ids (see Working With Other Plugins).
- `decoration` (optional): `{icon, iconPath, color, animation}`, where `animation` is `none`, `pulse` or `shimmer`. The icon appears on your overlay bands and replaces the color dot on your calendar's sidebar row.
- `messages` (optional): `{"code": "text"}`, the warnings you'll raise, up to 300 characters each. A warning that names one by `code` always shows its text, even when your diagnostics are hidden (see Secrets).

### Settings Fields

Each field needs a `key`, a `label` and a `type`:

- `text`: 500 characters.
- `textarea`: multi-line, 10,000 characters; `rows` sets its height. There's no list type, so use a `textarea` with one item per line.
- `secret`: a write-only credential, at most 16,384 bytes (see Secrets).
- `number`: optional `min` / `max`.
- `select`: `options` as strings or `{value, label}` objects, or `optionsFrom` (below).
- `checklist`: several choices, stored as a list; `options` or `optionsFrom`.
- `toggle`.
- `location`: a place picker that stores `{name, lat, lng}`; coordinates are range-checked.
- `person`: a name picked from the user's People.

`help` adds a short line under the label, and `section` puts a heading above the field that starts a group. **Discovered choices:** `"optionsFrom": "name"` fills a `select` or `checklist` with what your job found, written with `$host->setSettingOptions('name', $options)` (a service's databases, say, or the fields of the one the user picked). Pass `null` when nothing is known yet; the form then shows the field's `pendingText`. Pair it with a `runOnSave` job so the choices appear within a minute of saving.

Every field except `secret` may have a `default`. `text` and `textarea` accept `maxLength` to lower their ceiling (a `textarea` can't go above 10,000). A `default` longer than its own field is refused at install.

### Icons

`decoration.icon` is **either** a host icon name **or** a single emoji (`"⛅"`, `"🌊"`). Anything shaped like an identifier is treated as a host name and **refused at install if it doesn't exist**, so a plausible guess like `"map-pin"` fails loudly instead of rendering nothing. The host set:

`settings outfeeds filters plus search quickadd close chevronLeft chevronRight chevronUp chevronDown arrowUpRight arrowLeft arrowRight check star video warning stack menu brand activity views folder mixed visAll visNone reschedule pencil trash note keyboard expand bell activeOnly lock unlock calendar today viewMonth viewWeek viewWeeks3 viewWeeks2 viewDay viewAgenda viewSplit gapDays jumpDate plugins proposals review mail people trip google more clock copy link globe eyeOff repeat`

Plus the information icons, meant for context events: `sun sunrise sunset air tideHigh tideLow cloud rain snow storm thermometer moon flag`. If none fits, use an emoji.

`decoration.iconPath` ships your own icon as SVG path data: `{"iconPath": "M2 8 L8 2 L14 8"}`. You supply only the `d` string; the host builds the `<svg>` with its own 16x16 viewBox, sizing and `currentColor` stroke, so you never supply markup. The string may contain only path commands (`MmLlHhVvCcSsQqTtAaZz`), digits, `.,-+eE` and whitespace, must begin with a moveto, and is capped at 2,000 characters; anything else is refused at install. `iconPath` wins over `icon` when both are set.

### Secrets

Declare a credential as `{"key": "credential", "label": "Credential", "type": "secret"}` in any of the three setting scopes.

- API reads and saves return only `{set: true}` or `{set: false}` for that key, for browsers and tokens alike. An omitted field keeps the stored value; `null` clears it. The browser shows an empty replacement field, never the saved value.
- The host encrypts new values with `BETTERCAL_SESSION_SECRET` and decrypts them for your runtime settings and event-data reads. Changing that secret without migrating the encrypted values makes them unreadable.
- An existing plain-text field changed to `secret` is hidden immediately and encrypted the next time it's saved.
- A plugin with any secret field gets generic validation and failure messages, and its free-form logs, warning text and fix details are suppressed, including earlier run tails. This stays in effect after a secret is cleared. You lose detailed diagnostics, but the host doesn't have to recognize every way a credential could be printed. Declared `messages` still show, since they're fixed text: use them for anything the person must act on (a missing setting, a refused key).
- Don't fail a run for an outage. Five failed runs in a row turn a plugin off, and a job that runs every minute would get there in five minutes; raise a declared warning and return instead.
- Never copy a secret into event titles, ordinary fields, proposals or other visible output. Plugins run as trusted native PHP, and the host can't sandbox malicious code.

## Code (`Plugin.php`)

The file is `require`d and must return an instance implementing `PluginInterface`:

```php
<?php
declare(strict_types=1);
use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;

return new class implements PluginInterface {
    public function validateSettings(array $values): array {
        // Return {key: error}. Empty = accept. Runs synchronously on save.
        // $values holds ONLY the keys being saved right now, not the merged
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

## The Host API (`PluginHost`)

Settings and context:

- `settings(): array`: plugin-scope values (stored over manifest defaults). Only keys your current manifest declares are returned.
- `calendarSettings(int $calendarId): array`: your per-calendar values.
- `calendars(): array`: the user's calendars (`id`, `name`, `kind`, `visible`).
- `timezone(): DateTimeZone`: **the user's** zone. Use it for any local time you build; `date_default_timezone_get()` is the server's zone and may be a continent away.
- `runId(): ?string`: the id grouping this run's changes, so the ops page can undo a run in one step.

Reading the calendar:

- `eventsWindow(startIso, endIso): array`: the user's occurrences through the real pipeline (recurrence expanded, filters applied). **Read [What `eventsWindow()` Returns](#what-eventswindow-returns) before doing day math with these.**
- `eventData(int $eventId): array`: your keyed values for one event.
- `eventsWithData(string $key): array`: `eventId => value` for every event carrying that key.
- `people(): array`: `[{id, name}]`. Requires `people`.
- `availability(string $startIso, string $endIso): array`: `[{personId, name, kind, start, end}]` overlapping the window. Requires `people`.

Network and location:

- `http(): HttpClient`: `get($url, $headers)` / `getJson($url, $headers)`, and `json($method, $url, $payload, $headers)` for an API that takes JSON (POST, PATCH and so on; no redirects, and you judge the status it returns). Headers are lines like `'Authorization: Token ' . $key`. Refuses private, loopback, link-local and metadata addresses (checked before connecting and pinned against DNS rebinding), caps each request at 5 MB, 20 s and 3 redirects, and allows **60 requests per run** (each redirect hop counts). Three slow requests at 20 s each already use the whole 60 s run budget, so the clock usually binds first.
- `httpCached(url, ttl)` / `getJsonCached(url, ttl)`: the same client through a cache shared by every plugin (see Working With Other Plugins). `getJsonCached` throws on non-2xx or non-JSON.
- `geocode(string $query, ?float $biasLat = null, ?float $biasLng = null): ?array`: the host geocoder (cached). Returns `{lat, lng, display, kind}`, or null (see [Geocoding](#geocoding)).
- `llmJson(string $prompt, array $schemaHint = []): ?array`: ask the model for JSON. **Returns null when unconfigured or on failure, indistinguishably**, so always have a deterministic fallback. One call may take up to 45 s of the 60 s budget, so **two model calls can't fit in one job**. Requires `llm`.

Writing:

- `ensureCalendar(name, color, role = 'context'): int`: find or create a calendar your plugin owns (name up to 160 characters; a color that isn't `#rrggbb` becomes `#5b8dd9`). `role` is what a new one starts as: `context` for information, `mine` for things the person does (tasks), `opportunities`. A `mine` one starts with no reminders.
- `syncEvents(calId, events): [added, updated, removed]`: feed-style upsert by `sourceKey` into a calendar you own; keys that disappear are deleted. See [Materialized Events](#materialized-events).
- `replaceRanges(ranges): int`: **wholesale-replace** your overlay bands; whatever you leave out this run is gone. Range: `{sourceKey, start, end, label?, color?, detailHtml?}`, with `start`/`end` as `YYYY-MM-DD` (end exclusive) or ISO instants. You may write up to 2,000, but **one window request returns at most 500 per plugin** (with a `truncated` flag the client reads), so ranges past that are stored and never seen. If you produce more than a few hundred, emit fewer.
- `replaceWarnings(warnings): int`: **wholesale-replace** your findings, the same way. Warning: `{message, severity?, eventId?, fix?}`, or `{code, severity?, eventId?}` for one of your declared `messages`.
- `setEventData(int $eventId, string $key, mixed $value): void`: write one per-event value (null deletes), only for events the user owns. It's an **upsert that persists forever**: if you write a computed value (a travel estimate, say), you clear it when it goes stale, or an event that moved keeps yesterday's answer. Sweep your leftovers with `eventsWithData()`.
- `notify(string $title, string $body, string $url = '/'): array`: one notification now, through the user's configured channel; silent if none is configured. It does **not** dedupe: a 6-hourly job that keeps finding the same conflict notifies every run, so record what you've said in `kvSet` and stay quiet. Requires `notify`.
- `propose(array $proposal): array`, `withdrawProposal(string $sourceKey): bool`, `myProposals(?string $status = 'open'): array`: see [Proposals](#proposals). All three require `propose`.

Storage, sharing and bookkeeping:

- `kvGet(key, default)` / `kvSet(key, value)` / `kvDelete(key)`: namespaced storage that survives between runs; dropped on uninstall. Keep keys under 160 characters (longer ones are cut on write).
- `hasPlugin(id)`, `publish(key, value, ttl?)`, `readPublished(pluginId, key)`, `publishedKeys(pluginId)`, `unpublish(key)`: see Working With Other Plugins.
- `log(msg)`: shows on the ops page. Each line is cut to 300 characters and only the last 100 lines are kept.
- `budgetRemaining()` / `overBudget()`: the 60 s soft run budget. Long loops should check and stop.

### What `eventsWindow()` Returns

Each occurrence has exactly these keys:

```
eventId, calendarId, title, start, end, allDay, location, lat, lng, isContainer, recurring
```

Three things about the timestamps that will bite you otherwise:

- **`start`/`end` carry each event's own time zone offset**, not UTC and not the viewer's zone. Never compare them as strings: `"...T09:00:00-07:00"` sorts before `"...T10:00:00-04:00"` but is the *later* instant. Sort by parsed instant (`strtotime`, `DateTimeImmutable`).
- **All-day occurrences come back as `YYYY-MM-DDT00:00:00+00:00`**, a calendar date pinned at `+00:00`, not an instant. **Don't** convert it to another zone; that slides Saturday into Friday west of UTC. Its `end` is exclusive.
- `lat`/`lng` are null unless the event has resolved coordinates.

The window returns every occurrence in the range, up to the shared recurrence budget and the 5,000-occurrence plugin window limit (`BETTERCAL_LIMIT_PLUGIN_WINDOW_EVENTS`, see [limits.md](../limits.md)). A larger result throws instead of returning partially, so ask for a narrower range and continue in another call.

**The window includes plugin-owned calendars.** Anything judging "is this day free?" must skip calendars with `kind === 'plugin'` from `calendars()`, or the Weather plugin's one all-day event per day makes every day look busy. Consider a `calendarSettings` toggle too, so the user can exclude chore or task calendars: recurring all-day chores are all-day events, and they aren't what "this day is taken" means.

### Geocoding

`geocode()` returns `{lat, lng, display, kind}`. `kind` is the provider's word for what it found (`city`, `town`, `state`, `country`, `restaurant`, ...) or null, and it's how you tell a region from a destination without geocoding again.

- It returns **null when the place isn't found, the provider is unreachable, or the server has place lookups turned off**, and you can't tell which. Treat null as "no answer", not "no such place".
- Without a bias the provider ranks globally, so a bare venue name lands on the best string match: a restaurant named after a local landmark can resolve to a same-named place on another continent. A bias only reranks the provider's candidates; it can't find a venue the provider doesn't hold, and then picks the nearest wrong answer instead of the far one.
- Treat any geocode of a user-typed venue name as a guess: check `display` before acting on the coordinates, and prefer a full street address as the query.

**Don't build your own geocoder.** The host's ranks same-named places by significance and asks a second provider when the first returns a small settlement (which is what stops "Lisbon" landing in Iowa). A plugin-local one starts from scratch and gives the user a second set of answers to reconcile. If the host gets something wrong, report it as a host bug.

### Silent Coercions

The output methods sanitize and clamp without telling you:

| method | silently does |
|---|---|
| `syncEvents` | `title` → 500 chars, `location` → 300, description through the HTML sanitizer, `sourceKey` → 100; rows without a `sourceKey` and repeated keys are skipped; a bad `icon` is dropped |
| `replaceRanges` | drops a `color` that isn't `#rrggbb`, `label` → 200 chars, `sourceKey` → 160; rows without a `sourceKey` and repeated keys are skipped; whole array capped at 2,000 |
| `replaceWarnings` | array capped at 500, `message` → 500 chars (empty messages are skipped), `fix` → 300; `severity` accepts only `info` or `warn` (anything else becomes `warn`) |
| `setEventData` | key → 120 chars; `null` deletes the key |
| **saving settings** | `person` → 120 chars, `location.name` → 200. Over-long `text`/`textarea`, non-scalar values and out-of-range coordinates are **refused with a 400**, not coerced. |

### Settings Pitfalls

- **`validateSettings($values)` receives only the keys being saved**, not the effective settings; the host merges them over what's stored afterward. A rule like `if (!isset($values['location'])) return ['location' => 'required'];` makes every single-field save fail on a field the user never touched, and cross-field rules never fire on a partial save. Validate present keys only, and enforce "required" in `runJob`, where `settings()` gives you the merged result.
- **Renaming or removing a settings key strands what the user stored under it.** `settings()` only returns keys your current manifest declares, so the old value becomes unreachable while still sitting in the database. If you reshape your settings, keep reading the old keys for a version or two and write the merged result back before dropping them.
- **`eventSettings` keys and keys you write with `setEventData` share one namespace** under your plugin id. Keep them distinct or a job will overwrite the user's own choice.

### Per-Event Data

Declare `eventSettings` and the host renders those controls in the event detail view; the user's answers are stored under your plugin id and readable with `eventData()`. Your job can write values there too (a computed travel time, say) and the detail view shows them.

This data rides the event-detail fetch, never the events window, so it can't appear on the calendar grid. The window serves thousands of occurrences and has to stay small, so there's no API to put plugin data on it.

## Working With Other Plugins

Plugins share data, never control. No plugin runs inside another's job and there's no way to call another plugin's code, so there's no budget to split, no exception crossing a boundary, no permission to inherit and no load order. If you want another plugin's answer, have it publish the answer.

**Check whether another plugin is there.** `hasPlugin(string $id): bool` is true when that plugin is installed *and* enabled. That's how you build an optional dependency: fold in what a weather plugin publishes when it's present, say less when it isn't, and never duplicate its work or fail without it.

**Declare dependencies in the manifest.** `"requires": ["open-meteo"]` is enforced: the host refuses to enable your plugin until every listed id is installed and enabled, and says which one is missing. `"optional": ["weather"]` is disclosure only, shown on the ops page so the user can see what your plugin would use. A plugin can't depend on itself.

**Publish data for others to read.**

```php
$host->publish('normals.v1', $normals, 86400 * 30);   // key, value, optional TTL seconds
$host->readPublished('weather', 'normals.v1');        // null if absent, disabled, or expired
$host->publishedKeys('weather');                      // [key => lastUpdatedIso], to discover a surface
$host->unpublish('normals.v1');                       // stays yours, stops being public
```

`publish` makes one of your own kv entries readable by any other enabled plugin; the rest of your kv stays private. `readPublished` returns null when the plugin is absent or disabled, never published that key, or the value expired. You can't tell which, on purpose: the right response to all four is to do without it.

**What you publish is a public contract.** Version the key (`normals.v1`) instead of changing a shape other plugins already read, since you can't see who depends on you.

**Share fetches through the host cache.** When two plugins want the same upstream data, the expensive part is the request:

```php
$data = $host->getJsonCached('https://archive-api.open-meteo.com/…', 86400 * 30);
```

`httpCached(url, ttl)` / `getJsonCached(url, ttl)` use the same SSRF-safe client but a cache **shared by every plugin**, keyed by the exact URL (default TTL one hour). A hit costs nothing against your request budget, a miss is billed normally, and non-2xx responses are never cached. Two plugins asking for the same coordinates pay for one fetch, with no dependency to declare.

## Proposals

For anything that suggests a plan rather than carrying it out. Proposing **never touches the calendar**; only the user pressing Accept does, and acceptance creates the whole plan at once under one run id, so undoing it reverses everything together.

```php
$host->propose([
    'sourceKey' => 'weekend-2026-09-05',   // stable: re-running REPLACES your own open proposal
    'title' => 'A day at the coast',
    'summary' => 'Low tide at 1:40 PM, clear, Alex is around.',
    'rationaleHtml' => '<p>Why these times…</p>',   // sanitized by the host
    'plan' => [
        'trip' => ['title' => 'Coast day', 'start' => '2026-09-05', 'end' => '2026-09-06'],
        'events' => [
            ['title' => 'Drive out', 'start' => '2026-09-05T09:00:00-07:00', 'end' => '2026-09-05T10:30:00-07:00'],
            ['title' => 'Tidepools', 'start' => '2026-09-05T13:00:00-07:00', 'end' => '2026-09-05T15:00:00-07:00', 'location' => 'Harbor Beach'],
        ],
    ],
]);
```

The host creates accepted events through the normal event code, so they get the same validation, activity attribution and undo as anything made by hand. A `trip` key wraps the events in a Trip and links them.

Breaking one of these rules throws out of `propose()`, which fails the job and counts toward the circuit breaker, so validate first:

- `sourceKey` is required.
- `events` must be non-empty and at most 50; each needs a `title` and a `start`.
- `trip`, if present, needs `title`, `start` and `end`. It's always created all-day, as a container.

A plan event takes `{title, start, end?, allDay?, location?, description?, calendarId?}`. Events land on the user's default local calendar unless `calendarId` names another of the owner's **local** calendars; acceptance never writes to a subscribed, Google-backed or plugin calendar. The Review card shows every destination before the owner decides. **`allDay` defaults to false**, so a date-only `start` like `'2026-09-05'` becomes a *timed* midnight-to-midnight event. Unlike `syncEvents`, it doesn't infer all-day from the date shape, so pass `allDay => true`.

### Statuses and Re-Proposing

- **Statuses are `open`, `accepted` and `rejected`.** Undoing an accepted proposal returns it to `open`, so a key you'd stood down on can come back.
- `myProposals($status)` returns `[{sourceKey, status}]` with no content; pass `null` for all of them.
- Re-proposing a `sourceKey` replaces your **open** proposal in place. On one the user already decided, `propose()` **silently does nothing and returns the old one**. Check `myProposals(null)` first and skip decided keys, or a daily job will spend a model call every day re-proposing a day the user rejected.
- `withdrawProposal($sourceKey)` retracts one of your **open** proposals and returns true if it removed one. Decided ones are left alone: an accepted plan is on the calendar and a rejection is the user's answer. Use it when your answer changes, such as a weekend that's no longer free.

That leaves choosing keys. A key derived from the answer (`trip-2026-11-02`) piles up stale proposals unless you withdraw them, while a fixed key (`next-trip`) is silenced forever by one rejection. The pattern that works is a **generation counter in `kvSet`**: use one key like `plan-g3`, bump the generation when the user rejects, and keep your own record of what each generation offered.

Since `myProposals()` returns no content, "has anything changed since I last proposed?" is yours to answer: keep a fingerprint of your inputs in `kvSet` and compare before doing expensive work.

## Output Shapes

1. **Materialized events** (`syncEvents`): a read-only calendar that behaves like a subscribed feed, with a visibility toggle, normal rendering and no cost to the events window. See `weather`.
2. **Overlay bands** (`replaceRanges`): ranges drawn behind the calendar; clicking one opens a host info card with your `detailHtml`. They get a sidebar visibility toggle. See `tides`.
3. **Warnings** (`replaceWarnings`): findings on the ops page, optionally linked to an event with `eventId`. No calendar objects. See `lint`.

### Materialized Events

An event is `{sourceKey, title, start, end?, allDay?, description?, location?, icon?, url?}`, where `url` links to the item in its source. `start`/`end` are `YYYY-MM-DD` for all-day (end exclusive) or ISO instants; a date-only `start` is all-day unless you say otherwise.

- One sync is all or nothing. A snapshot over 2,000 events or 8 MiB of event data is refused (it throws and the previous snapshot is kept; see [limits.md](../limits.md)), and if the run budget runs out mid-sync, every change from that call is rolled back.
- `icon` is optional, per event, and takes a host icon name or one glyph exactly like `decoration.icon`. On a context calendar (the role a plugin calendar gets by default) it's what the day header shows beside the value: a weather plugin says `rain` on the rainy day and `sun` on the sunny one, an air-quality plugin `air`, a tides plugin `tideLow`/`tideHigh`, a sun plugin `sunrise`/`sunset`. Without one, the host guesses from the title (numbers like `72/58 fog`, the words AQI, sunset, sunrise, high/low tide) and otherwise shows the calendar's square.

### Rescheduling From the Calendar

A plugin that declares `events:edit` and also implements `EditablePlugin` makes its calendars reschedulable: the person can drag, resize or Move your events, and the API and MCP can change their times. Titles and everything else stay yours. The host calls:

```php
public function applyEdit(PluginHost $host, array $edit): array
```

`$edit` is `{sourceKey, eventId, calendarId, title, before, after}`, where `before` and `after` are `{start, end, allDay}` spans in the shapes `syncEvents` takes. Write `after` to your source and return the span it now holds (usually `after`). Throw `PluginEditRefused` with one plain sentence when the source says no: the event goes back and the person reads your sentence. Any other exception reads as "<your plugin> couldn't make the change".

It runs while the person waits, like a Google calendar's write, so keep it to the write itself. Your next sync then finds the source already changed. Undo writes the earlier time back through `applyEdit` too. Return `undo` alongside the span when the source's previous value isn't simply `before` (Fibery shows an overdue task on today, but undo must restore its real due date). See `fibery` for a working example.

### Text and HTML

All text you pass through `syncEvents`, `replaceRanges`, `replaceWarnings` and `propose` is sanitized on the server when written and again in the browser when shown. You ship data, never markup. `description`, `detailHtml` and `rationaleHtml` are the rich fields, and the allowlist is exactly these tags:

```
p  br  b  strong  i  em  u  a  ul  ol  li  div
```

- Other tags are unwrapped to their text, including **headings** (so `<h4>` titles collapse a structured card into one block of prose) and **tables** (a `<table>` becomes run-together cell text). Use `<div>` + `<b>` for headings and `<ul>` for anything tabular. Script, style, form and embed tags are removed with their contents.
- **Every attribute is dropped except `href` on `<a>`**, and only absolute `http(s)` URLs survive. A relative link loses its anchor, so you can't link to the event behind a finding; use the warning's `eventId` for that.

## Lifecycle and Health

- **Enabling** schedules your jobs; the first run happens on the next worker tick (about a minute) or right away with "Run now".
- **Circuit breaker**: 5 failed or timed-out runs in a row turn the plugin off, with the reason on the ops page. A successful run resets the count.
- **A run isn't a transaction.** Throwing partway through `runJob` marks the run failed but **doesn't** undo what you already wrote: ranges, warnings, events, kv and proposals from the first half all stay. This matters most for an "already announced this" fingerprint guarding `notify()`: write it on the **last** line, after the side effect it guards, or a later throw suppresses a notification that never went out.
- **Turning off** (the checkbox) pauses a plugin and keeps everything. **Delete data** (the trash can) turns it off and deletes its settings, ranges, warnings, runs, proposals, per-event data, per-calendar settings and kv, and the receipt shows what was removed. The user chooses whether your calendars are deleted with their events or kept as ordinary local calendars, renamed "(archived)", that no longer sync. Turning the plugin on again starts fresh beside them.

## Trust

Better-Cal is a single-user, self-hosted system. Installing a plugin runs its PHP with the app's privileges, with no sandbox, and most of the manifest's permission list is disclosure (only `people`, `notify`, `llm` and `propose` are checked). What the host *does* enforce: outbound HTTP goes through the SSRF-safe client, event writes only reach calendars your plugin owns, and all plugin text is sanitized before it reaches the page. Review a plugin's code before enabling it, as you would any code you run on your own server.
