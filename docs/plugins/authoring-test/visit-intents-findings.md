# Findings — building `visit-intents` as an external author

Written as I went, in order encountered.

---

### docs/README.md links to three documents that were not shipped
**Needed:** Orientation before the contract — README is item 3 in my stated reading order.
**Docs said:** "Read in order: 1. idea-evaluation.md ... 2. idea-ranking.md ... 3. prd-v1.md"
**Reality:** None of those exist. `docs/` contains exactly `README.md` and `authoring.md`.
**Cost:** One `find`. Near-zero for me since `authoring.md` is self-contained, but an external author following the reading order hits three dead links in minute one and reasonably concludes the doc set is partial.
**Fix:** Replace `docs/README.md` with a one-paragraph index pointing at `authoring.md` as the contract, or ship the planning docs.

---

### `validateSettings()` gets only the PATCHed keys, but the store merges — the docs' own example is broken by this
**Needed:** Change one setting (`color`) without resending the others.
**Docs said:** `validateSettings(array $values)` — "Return {key: error}. Empty = accept. Runs synchronously on save." The example is
`if (($values['location'] ?? null) === null) return ['location' => 'required'];`
Nothing says whether `$values` is the delta or the merged result.
**Reality:** It is the **delta**. `PATCH /plugins/visit-intents/settings -d '{"color":"purple"}'` called my validator with a `$values` that had no `wishlist` key, so my required-field check fired and the save was rejected with an error about a field the user never touched. Meanwhile the *write* path merges — a successful PATCH of two keys left the other eleven intact. So validation sees a partial object and persistence sees a whole one.
This makes the documented example actively harmful: copy it, set `location` once, and from then on every PATCH that does not resend `location` fails with "location: required". The user can never change `units` on its own.
**Cost:** Four probing PATCHes to pin down, plus a rewrite of my validator. I found it only because I was deliberately trying to break the contract; the natural path is to ship the docs' pattern and have a user report "I can't save my settings".
**Fix:** State it explicitly and fix the example. Two sentences: "`$values` contains only the keys being saved, not the merged settings. Guard every check with `array_key_exists()` — a required-field check that fires on absence will reject unrelated partial saves." Better still, pass the merged view, since that is what actually gets persisted.

### Host-level field validation runs first and short-circuits (good, but undocumented)
**Needed:** To know whether I have to defend against out-of-range `number` values in `runJob`.
**Docs said:** `number` (`min`/`max`), `select` (`options`) — described as field schema, with no statement about enforcement.
**Reality:** Enforced, well, and before my code: `{"horizonDays":9999}` → `400 {"horizonDays":"maximum 60"}`, `{"allDayPolicy":"banana"}` → `400 {"allDayPolicy":"must be one of away, all, none"}`. When host validation fails, `validateSettings()` is not called at all (the 9999 response contained only the host's error, not my validator's). Unknown keys are dropped silently.
**Cost:** One batch of probes. No damage — I had already written defensive clamps — but I would have skipped them had the docs said this.
**Fix:** One line under the field-types list: "`min`/`max`/`options` are enforced by the host before `validateSettings()` runs; values reaching `runJob` are always in range." And say that unknown keys are discarded.

### `POST /plugins/:id/run` queues, it does not run
**Needed:** Run the plugin and read the result.
**Docs said:** HOWTO: "`POST /plugins/:id/run` — run now".
**Reality:** Returns `{"queued":["scan"]}` immediately. `lastRun` stays `null` until a worker tick picks it up. Harmless once you know, but "run now" plus an instant 200 reads like the job already ran, and my first instinct was that the job had silently produced nothing.
**Cost:** One confused round-trip.
**Fix:** Call it "queue a run" and show the response shape.

### `GET /plugins/ranges` 500s instead of 400ing when start/end are missing
**Needed:** Read back the bands I had written.
**Docs said:** HOWTO lists `GET /plugins/ranges` with no parameters.
**Reality:** `GET /plugins/ranges` → `500 {"code":"internal_error"}`. With `?start=&end=` it works. An internal error for a missing required parameter sent me looking for a fault in my own plugin first.
**Cost:** Three probes to work out the endpoint needed a window at all.
**Fix:** Document the required `start`/`end`, and make the missing-parameter case a 400.

---

### A `text` setting is silently truncated to 500 characters on save — and the coercion table does not mention settings at all
**Needed:** Store a wishlist of places. There is no list or multi-line field type, so the only container available is `text`.
**Docs said:** The coercion table ("Host coercions you will not be told about") covers `syncEvents`, `replaceRanges`, `replaceWarnings` and `setEventData`. **Settings are not in it.** Field types are listed as "`text`, `number` (`min`/`max`), `select` (`options`), `toggle`, `location`, `person`" with no length note anywhere.
**Reality:** `PATCH /plugins/visit-intents/settings` with a 1386-character `wishlist` returned **HTTP 200** and stored 500 characters. Boundary probed: 499→499, 500→500, 501→500, 600→500. It is 500 *characters*, not bytes — 500 CJK characters stored as 1392 bytes. No error, no warning, no `errors[]` entry, and the 200 response body echoes the truncated value, which is easy to skim past.
The failure it produces downstream is silent and wrong rather than loud: my run went from 9 places to 3, logged `3 places (3 with hours)` as if that were the user's list, and drew a perfectly plausible set of bands off two thirds of the data. Nothing anywhere says data was lost.
Inconsistent with defaults, too: the same 1386-character string as a manifest `default` reached `runJob` in full on the first run (7 places parsed). So a plugin can ship a default its user can never re-save.
**Cost:** One deploy plus one live run that produced quietly wrong output, then four boundary probes. I only caught it because I counted the places in my own log line. Without that log line I would have shipped it.
**Fix:** Two things. (1) Add settings to the coercion table: "`text` values are truncated to 500 characters on save; defaults from the manifest are not." (2) Better, truncation should be an error, not a silent clamp — this one is user data, unlike a label the host is drawing. And if plugins are expected to hold anything list-shaped, `text` needs either a documented larger cap or a `textarea`/`list` field type; 500 characters is about six lines.
**Workaround I shipped:** three `text` fields (`wishlist`, `wishlist2`, `wishlist3`) concatenated with newlines, giving 1500 characters. This is a bad control surface that exists only to route around the cap, and it is exactly what a third-party author will be forced to invent.

### No multi-line or list field type, so any list-shaped setting has to be smuggled through `text`
**Needed:** "Let me keep a list of places I want to visit", each with name, location, duration, hours, deadline, URL.
**Docs said:** Field types: `text`, `number`, `select`, `toggle`, `location`, `person`.
**Reality:** Nothing repeating and nothing multi-line. I had to invent a pipe-delimited line format and a parser for it, plus tolerate `;;` as a record separator in case `text` renders as a single-line `<input>` where a user cannot type a newline — which I still do not know, because I have no browser. Newlines do survive the API round-trip, so at least the storage is fine.
**Cost:** Roughly a third of the plugin's code is wishlist parsing and the error reporting around it. That is not domain logic, it is a workaround.
**Fix:** Either add a `textarea` and a repeating `list` field type, or state plainly that structured settings are out of scope and plugins should expect to parse a blob. Also say whether `text` renders as `input` or `textarea`, because it decides whether newlines are even typeable.

---

### `geocode()` returns null for everything on this instance, and the docs give no hint that is possible
**Needed:** Coordinates for a wishlist place, to search OpenStreetMap around it.
**Docs said:** "`geocode(string $query): ?array` — the host geocoder (cached)." The `?array` is the only clue.
**Reality:** Null for every form of a perfectly ordinary address, in ~150 ms — so it is not even attempting a lookup:
```
geocode "Tartine Manufactory, 595 Alabama St, San Francisco CA" → null
geocode "595 Alabama St, San Francisco CA"                      → null
geocode "Tartine Manufactory"                                   → null
```
The docs describe `llmJson()` unconfigured-vs-failed ambiguity in detail and tell you to always have a deterministic fallback. `geocode()` gets no such treatment, so I assumed it was a core capability and built a code path on it. It also gives no way to tell "no such place" from "no geocoder configured" — the same ambiguity the docs bothered to warn about for `llmJson`.
**Cost:** Two deploy-run-read cycles plus a diagnostic logging pass, then a redesign of the OSM lookup to work without coordinates at all (searching inside a named city instead).
**Fix:** Say that `geocode()` can be unconfigured and returns null indistinguishably, in the same sentence-shape used for `llmJson()`. Also worth saying what the return shape is — I guessed `{name, lat, lng}` from the `location` field type description and never got to confirm it.

### `llmJson()` unconfigured: the docs' warning was right, and the host log line saved me
**Needed:** Normalise "open every day from sunrise until sunset" into `opening_hours` syntax.
**Docs said:** "Returns null when unconfigured or on failure, indistinguishably — always have a deterministic fallback."
**Reality:** Exactly as documented — returned null. **And the host wrote `llm not configured; using fallback` into my own run log**, which is more than the docs promise and is what told me which of the two cases I was in.
**Cost:** None. This is the docs at their best: I had the fallback written before I ever ran it, purely because of that sentence.
**Fix:** None needed, but mention that the host adds that log line — it is the one signal that disambiguates, and knowing it exists would stop authors building their own detection.

### `kvGet`/`kvSet` have no documented TTL, expiry, or value-type contract
**Needed:** Cache an hours lookup, including negative results, so a 6-hourly job does not re-hammer a flaky third-party endpoint forever.
**Docs said:** "`kvGet/kvSet/kvDelete` — namespaced storage that survives between runs; dropped on uninstall."
**Reality:** Works, and round-trips arrays fine (I store `{spec, from, at}` and get an array back). But nothing documents: whether values must be scalars or are JSON-encoded, whether there is a size cap, whether keys expire, or whether there is any TTL. I had to build expiry by hand, storing a timestamp inside the value. Given the docs elsewhere push kv hard as *the* mechanism for "has anything changed since last time" and notification dedupe, its contract is thinner than its importance.
**Cost:** A guess that happened to work (arrays round-trip). A wrong guess here fails at runtime on a live instance, not at deploy.
**Fix:** State the accepted value types, whether there is a size or key-count cap, and that there is no TTL so callers must age their own entries.

### The free Overpass API is not a safe dependency inside a 60-second run budget (context, not a host bug)
**Reality:** `overpass-api.de` took **19.8 s** for one query through the host client, and from my own machine returned `504` on four of six attempts, once after 99 s. `overpass.kumi.systems` 504'd after 99 s. When it did answer, OpenStreetMap simply had no `opening_hours` for the venue.
This is not the host's fault, but it interacts badly with the 60 s soft budget and with the circuit breaker: two slow lookups plus normal work could time a job out, and 5 timed-out runs auto-disable the plugin. The docs mention the 20 s per-request cap and the 60 s budget separately; they never point out that **three permitted HTTP requests at the permitted 20 s each exceed the entire run budget**.
**Fix:** One line in the budget note: "the HTTP client's 20 s cap means three slow requests will exhaust the 60 s run budget; treat any third-party endpoint as at most one call per run and cache misses, not just hits."

---

### A plugin cannot withdraw its own proposal, only replace one
**Needed:** Offer "the single best opening right now". Yesterday's best is stale today.
**Docs said:** `propose()`, `myProposals(?string $status)`. "Re-proposing the same `sourceKey` replaces an **open** proposal in place." Statuses are `open`, `accepted`, `rejected`. Nothing about retracting.
**Reality:** There is no `withdraw()`/`retract()`/`close()`. A proposal keyed on a date (`visit-fort-point-2026-08-08`) can only be replaced by re-using that exact key, which is impossible once that opening no longer exists — the date is baked into the key that made it stable in the first place. So every time the ranking shifts, a new open proposal is minted and the old one sits in the user's queue forever until they manually reject it. I watched my own open count go 1 → 2 → 3 across four runs purely from ranking churn.
Worse, my first attempt at a fix made it actively harmful: keeping already-open proposals alive so they would not multiply meant a genuinely urgent new opportunity (a show closing in nine days) could never get proposed, because the slate was full of stale ones. There is no policy that is both non-accumulating and correct.
**Cost:** Two rewrites of the proposal-selection logic and three manual rejections through the API to clean up. Ultimately unsolvable inside the contract — I settled for "always offer the top N, count the orphans, log them, and keep N at 1".
**Fix:** Either add `withdraw(string $sourceKey)`, or auto-expire open proposals whose plan is entirely in the past, or say plainly in the docs: "there is no way to retract a proposal; key them on something stable and keep the count per run very low, because every key you stop offering leaves an open item the user must dismiss by hand."

### A failed run keeps everything it already wrote — there is no rollback, and the docs never say so
**Needed:** To know whether a job that throws halfway leaves the calendar in a half-updated state.
**Docs said:** Circuit breaker, lifetimes of `replaceRanges`/`replaceWarnings`/`setEventData`, and that acceptance of a proposal "materializes the whole plan atomically under one run id". Atomicity is promised for *proposal acceptance* and for nothing else.
**Reality:** I deliberately made a run throw (undeclared `propose`) after it had already called `replaceRanges` and `replaceWarnings`. Both writes survived: `counts` still showed `ranges: 10, warnings: 5` after `outcome=error`. Runs are not transactional.
That is a live correctness trap for the pattern the docs themselves recommend. They tell you to "keep a fingerprint of your inputs in `kvSet` and compare before doing expensive work", and to "track what you already said in `kvSet` and stay quiet". If you write that fingerprint where it reads naturally — right after computing it — and the run later throws, you have permanently recorded "I already told them about this" for a notification that was never sent. I had exactly this bug and moved both `kvSet` calls to the last two lines of the job.
**Cost:** One deliberate failure to discover, then a restructure of the job's tail.
**Fix:** State it: "a run that throws keeps whatever it already wrote; only proposal acceptance is atomic. Commit `kv` bookkeeping after the side effects it describes, not before."

### `notify()` cannot be verified without the UI
**Needed:** Confirm a notification actually went out.
**Docs said:** "one notification now, through the user's configured channel; silent if no channel is configured."
**Reality:** My runs report `1 notify` and the call does not throw, but there is no read side anywhere — `/notifications`, `/notify`, `/plugins/visit-intents/notifications`, `/notification-log`, `/settings/notifications` all 404. `notify()`'s return value is documented as `array` with no description of what is in it. So "did the user get told, or was the channel unconfigured and it silently no-op'd" is unanswerable headlessly, and unanswerable *in-plugin* too. **Not verifiable headlessly** — flagging as HOWTO asks.
**Fix:** Describe the return shape (something like `['sent' => bool, 'channel' => string|null]` would make it self-checking), or expose a notification log endpoint.

### The HTTP twin of `setEventData` does not honour `null` as delete
**Needed:** Clear a value I had written, to re-test the write path.
**Docs said:** For `setEventData`: "passing `null` deletes the key".
**Reality:** From PHP, null does delete — I watched my sweep clear keys (`event marks +0 -1`). But `PATCH /events/1/plugin-data/visit-intents -d '{"matched":null}'` returned `{"saved":[]}` **200** and left the value untouched. Two doors onto the same table, two different semantics for null, and the HTTP one reports success while doing nothing.
**Cost:** One confused verification step where I thought my sweep had failed.
**Fix:** Make the HTTP endpoint match, or document that it ignores nulls.

### `installed` and `enabled` are separate states and only one of them is documented
**Reality:** A freshly rsynced plugin appears in `GET /plugins` with `"installed": false, "enabled": false`. There is no `install` endpoint (HOWTO lists enable/disable/**uninstall**). `POST /enable` turns out to do both. It worked, but for a few minutes I was looking for the install step I had missed.
**Fix:** One sentence: "enable installs on first use; there is no separate install call."

### `decoration.icon` has no documented vocabulary
**Docs said:** "`decoration` is optional: `{icon, color, animation}` where animation is `none`, `pulse`, or `shimmer`." The three animation values are enumerated; `icon` is not.
**Reality:** I guessed `"map-pin"`. The host accepted it with `errors: []` and echoed it back verbatim, so it is not validated — which means a typo is silent and I cannot tell whether it renders as an icon, as a broken icon, or as nothing. Not verifiable headlessly.
**Fix:** List the icon set, or say it is a free-form key into a named icon library and which one.

### No run history: `lastRun` only, so evidence of an earlier run is gone
**Reality:** `GET /plugins` exposes a single `lastRun`. My very first live run created a proposal and fired a notification; by the time I looked, a second run had already overwritten `lastRun` with "unchanged, stayed quiet" and the evidence of the first was unrecoverable. On a plugin whose whole design is "behave differently on the second run", being unable to see run N-1 makes verification awkward.
**Fix:** A `GET /plugins/:id/runs` returning the last N would cost little and would make "did the dedupe work" directly checkable.

### Unstated: whether `eventsWindow()` caps its result set
**Needed:** Certainty that "no event overlaps this stretch" means there is genuinely no event. Every answer this plugin gives depends on it.
**Docs said:** Nothing about a cap. `replaceRanges` documents a 2000-row cap, and `GET /plugins/ranges` exposes a `truncated` flag, so caps clearly exist elsewhere in the system.
**Reality:** I measured it rather than trusting it. At a 60-day horizon `eventsWindow()` returned **1515** occurrences, and the REST API over byte-identical ISO bounds returned **1515**. No truncation, no flag, at that size. I still do not know where the ceiling is.
**Cost:** A deliberate experiment. Cheap, but it is the kind of thing an author should be able to read rather than measure — a silent cap would make a free/busy plugin confidently wrong rather than visibly broken.
**Fix:** State the cap if there is one and how truncation is signalled, or state that there is none.

---

## Where the docs were good

These are not padding — each of these measurably saved me work.

- **The `eventsWindow()` timestamp section is the best part of the document, and it is load-bearing.** All three warnings were real. "All-day occurrences come back as `YYYY-MM-DDT00:00:00+00:00` … do **not** timezone-convert it; converting slides Saturday into Friday west of UTC" is exactly the bug I would have shipped: the user is US Pacific, the server is Europe/Berlin, and every all-day event on this calendar would have landed on the wrong day. I wrote `substr($start, 0, 10)` on the first pass because of that paragraph and never had the bug.
- **"The window includes plugin-owned calendars … or the Weather plugin's one-all-day-event-per-day makes every day look occupied."** This is precisely my plugin's failure mode and it is called out by name. On the live instance that would have been 63 tide and weather occurrences poisoning the free/busy answer. The follow-on suggestion — offer a `calendarSettings` toggle for chore calendars — turned out to be worth 501 excluded occurrences from one hidden Todoist calendar.
- **`timezone()`: "the user's zone … `date_default_timezone_get()` is the server's zone and may be a continent away."** Blunt, correct, and it made me route every single time construction through `$host->timezone()`. Verified live: the job emitted `-07:00` timestamps from a box running Europe/Berlin.
- **The `llmJson()` null warning.** "Returns null when unconfigured or on failure, indistinguishably — always have a deterministic fallback." The model is not configured on this instance. I never saw a failure, because the fallback was written before the first run purely on the strength of that sentence.
- **The "Host coercions you will not be told about" table** is the right idea and the right tone. It is only wrong by omission (settings are missing from it), and it is the section I re-read most.
- **The `sourceKey` / wholesale-replace vs upsert distinction** (`replaceRanges` replaces everything, `setEventData` persists forever and you own clearing it) is stated crisply and is exactly the distinction that bites. `eventsWithData($key)` "exists so you can sweep your own leftovers" told me how to build the sweep before I needed it.
- **The `eventSettings` namespace warning** — "keys you write share one namespace … keep them distinct or a job will clobber the user's own choice" — made me pick `matched` against the user's `notBusy`. Verified live: both coexist on the same event and my job's write leaves the user's toggle alone.
- **Permission enforcement matches the documentation exactly**, with an error message better than most: `Plugin 'visit-intents' used the 'propose' capability without declaring it in plugin.json`. `consecutiveFailures` went 0 → 1 and back to 0 after a good run, as documented.
- **`PluginInterface` really is just the two methods.** `return new class implements PluginInterface` with `validateSettings` + `runJob` loaded first time, no autoloader surprises, no third method to discover. The "drop it in, no build step" claim is true.
- **The sanitizer is well-behaved and does not fight you.** `<a href>`, `<ul>/<li>`, `<strong>`, `<em>` and an `&amp;`-escaped query string all survive intact, and it *hardened* my markup by rewriting `rel="noopener"` to `rel="noopener noreferrer"`. "You ship data, never markup — `detailHtml` is the one rich field" is accurate.
- **Changing a manifest on an already-installed plugin hot-reloads the schema.** I added four settings keys mid-session; they appeared in `settingsSchema` with their defaults populated on the next API read, with the user's existing override preserved. No re-enable, no reinstall. Undocumented, but a genuinely nice behaviour.
- **Deleting an event cascades its plugin data.** After a proposal undo removed the event, `eventsWithData('matched')` no longer listed it and nothing leaked.
