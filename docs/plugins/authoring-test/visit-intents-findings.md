# Findings , building `visit-intents` as an external author

Written as I went, in order encountered.

---

### docs/README.md links to three documents that were not shipped
**Needed:** Orientation before the contract , README is item 3 in my stated reading order.
**Docs said:** "Read in order: 1. idea-evaluation.md ... 2. idea-ranking.md ... 3. prd-v1.md"
**Reality:** None of those exist. `docs/` contains exactly `README.md` and `authoring.md`.
**Cost:** One `find`. Near-zero for me since `authoring.md` is self-contained, but an external author following the reading order hits three dead links in minute one and reasonably concludes the doc set is partial.
**Fix:** Replace `docs/README.md` with a one-paragraph index pointing at `authoring.md` as the contract, or ship the planning docs.

---

### `validateSettings()` gets only the PATCHed keys, but the store merges , the docs' own example is broken by this
**Needed:** Change one setting (`color`) without resending the others.
**Docs said:** `validateSettings(array $values)` , "Return {key: error}. Empty = accept. Runs synchronously on save." The example is `if (($values['location'] ?? null) === null) return ['location' => 'required'];` Nothing says whether `$values` is the delta or the merged result.
**Reality:** It is the **delta**. `PATCH /plugins/visit-intents/settings -d '{"color":"purple"}'` called my validator with a `$values` that had no `wishlist` key, so my required-field check fired and the save was rejected with an error about a field the user never touched. Meanwhile the *write* path merges , a successful PATCH of two keys left the other eleven intact. So validation sees a partial object and persistence sees a whole one. This makes the documented example actively harmful: copy it, set `location` once, and from then on every PATCH that does not resend `location` fails with "location: required". The user can never change `units` on its own.
**Cost:** Four probing PATCHes to pin down, plus a rewrite of my validator. I found it only because I was deliberately trying to break the contract; the natural path is to ship the docs' pattern and have a user report "I can't save my settings".
**Fix:** State it explicitly and fix the example. Two sentences: "`$values` contains only the keys being saved, not the merged settings. Guard every check with `array_key_exists()` , a required-field check that fires on absence will reject unrelated partial saves." Better still, pass the merged view, since that is what actually gets persisted.

### Host-level field validation runs first and short-circuits (good, but undocumented)
**Needed:** To know whether I have to defend against out-of-range `number` values in `runJob`.
**Docs said:** `number` (`min`/`max`), `select` (`options`) , described as field schema, with no statement about enforcement.
**Reality:** Enforced, well, and before my code: `{"horizonDays":9999}` → `400 {"horizonDays":"maximum 60"}`, `{"allDayPolicy":"banana"}` → `400 {"allDayPolicy":"must be one of away, all, none"}`. When host validation fails, `validateSettings()` is not called at all (the 9999 response contained only the host's error, not my validator's). Unknown keys are dropped silently.
**Cost:** One batch of probes. No damage , I had already written defensive clamps , but I would have skipped them had the docs said this.
**Fix:** One line under the field-types list: "`min`/`max`/`options` are enforced by the host before `validateSettings()` runs; values reaching `runJob` are always in range." And say that unknown keys are discarded.

### `POST /plugins/:id/run` queues, it does not run
**Needed:** Run the plugin and read the result.
**Docs said:** HOWTO: "`POST /plugins/:id/run` , run now".
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

### A `text` setting is silently truncated to 500 characters on save , and the coercion table does not mention settings at all
**Needed:** Store a wishlist of places. There is no list or multi-line field type, so the only container available is `text`.
**Docs said:** The coercion table ("Host coercions you will not be told about") covers `syncEvents`, `replaceRanges`, `replaceWarnings` and `setEventData`. **Settings are not in it.** Field types are listed as "`text`, `number` (`min`/`max`), `select` (`options`), `toggle`, `location`, `person`" with no length note anywhere.
**Reality:** An overlong text setting is silently shortened on save, while a manifest default can reach the job in full. A list stored in that text can therefore lose entries while the job still produces plausible output. Reject overlong input with a clear error instead of silently discarding user data.
**Cost:** Silent truncation requires authors to add their own boundary tests and data-loss checks.
**Fix:** Two things. (1) Add settings to the coercion table: "`text` values are truncated to 500 characters on save; defaults from the manifest are not." (2) Better, truncation should be an error, not a silent clamp , this one is user data, unlike a label the host is drawing. And if plugins are expected to hold anything list-shaped, `text` needs either a documented larger cap or a `textarea`/`list` field type; 500 characters is about six lines.
**Workaround I shipped:** three `text` fields (`wishlist`, `wishlist2`, `wishlist3`) concatenated with newlines, giving 1500 characters. This is a bad control surface that exists only to route around the cap, and it is exactly what a third-party author will be forced to invent.

### No multi-line or list field type, so any list-shaped setting has to be smuggled through `text`
**Needed:** "Let me keep a list of places I want to visit", each with name, location, duration, hours, deadline, URL.
**Docs said:** Field types: `text`, `number`, `select`, `toggle`, `location`, `person`.
**Reality:** Nothing repeating and nothing multi-line. I had to invent a pipe-delimited line format and a parser for it, plus tolerate `;;` as a record separator in case `text` renders as a single-line `<input>` where a user cannot type a newline , which I still do not know, because I have no browser. Newlines do survive the API round-trip, so at least the storage is fine.
**Cost:** Roughly a third of the plugin's code is wishlist parsing and the error reporting around it. That is not domain logic, it is a workaround.
**Fix:** Either add a `textarea` and a repeating `list` field type, or state plainly that structured settings are out of scope and plugins should expect to parse a blob. Also say whether `text` renders as `input` or `textarea`, because it decides whether newlines are even typeable.

---

### `geocode()` needs explicit nullable-result guidance

The return type alone does not describe successful keys or distinguish an unavailable service, no match and a failed lookup. Document the returned shape and require a fallback when it returns null.

### `llmJson()` fallback guidance is useful

The contract says null can mean an unavailable model or a failed call. A deterministic fallback should be implemented before relying on model output. The host's diagnostic log can help explain which path ran.

### `kvGet`/`kvSet` have no documented TTL, expiry, or value-type contract
**Needed:** Cache an hours lookup, including negative results, so a 6-hourly job does not re-hammer a flaky third-party endpoint forever.
**Docs said:** "`kvGet/kvSet/kvDelete` , namespaced storage that survives between runs; dropped on uninstall."
**Reality:** Works, and round-trips arrays fine (I store `{spec, from, at}` and get an array back). But nothing documents: whether values must be scalars or are JSON-encoded, whether there is a size cap, whether keys expire, or whether there is any TTL. I had to build expiry by hand, storing a timestamp inside the value. Given the docs elsewhere push kv hard as *the* mechanism for "has anything changed since last time" and notification dedupe, its contract is thinner than its importance.
**Cost:** A guess that happened to work (arrays round-trip). A wrong guess here fails at runtime on a live instance, not at deploy.
**Fix:** State the accepted value types, whether there is a size or key-count cap, and that there is no TTL so callers must age their own entries.

### External services can spend the whole job budget

A per-request timeout is not a budget for the whole job. Several permitted requests can exhaust it. Cache misses as well as hits, keep requests bounded, and handle unavailable services without losing already useful results.

---

### A plugin cannot withdraw its own proposal, only replace one
**Needed:** Offer "the single best opening right now". Yesterday's best is stale today.
**Docs said:** `propose()`, `myProposals(?string $status)`. "Re-proposing the same `sourceKey` replaces an **open** proposal in place." Statuses are `open`, `accepted`, `rejected`. Nothing about retracting.
**Reality:** Without withdrawal, a changed window creates a new proposal and leaves the old one open. Reusing a stable key also fails after an owner decision if decided keys cannot be proposed again. The contract needs a way to retire obsolete suggestions without losing new ones.
**Cost:** Two rewrites of the proposal-selection logic and three manual rejections through the API to clean up. Ultimately unsolvable inside the contract , I settled for "always offer the top N, count the orphans, log them, and keep N at 1".
**Fix:** Either add `withdraw(string $sourceKey)`, or auto-expire open proposals whose plan is entirely in the past, or say plainly in the docs: "there is no way to retract a proposal; key them on something stable and keep the count per run very low, because every key you stop offering leaves an open item the user must dismiss by hand."

### A failed run keeps everything it already wrote , there is no rollback, and the docs never say so
**Needed:** To know whether a job that throws halfway leaves the calendar in a half-updated state.
**Docs said:** Circuit breaker, lifetimes of `replaceRanges`/`replaceWarnings`/`setEventData`, and that acceptance of a proposal "materializes the whole plan atomically under one run id". Atomicity is promised for *proposal acceptance* and for nothing else.
**Reality:** Writes made before a job throws are not rolled back. Store deduplication fingerprints only after the side effects they describe have succeeded, or a retry can incorrectly skip unfinished work.
**Cost:** One deliberate failure to discover, then a restructure of the job's tail.
**Fix:** State it: "a run that throws keeps whatever it already wrote; only proposal acceptance is atomic. Commit `kv` bookkeeping after the side effects it describes, not before."

### `notify()` cannot be verified without the UI
**Needed:** Confirm a notification actually went out.
**Docs said:** "one notification now, through the user's configured channel; silent if no channel is configured."
**Reality:** A successful `notify()` call does not distinguish delivery from an unconfigured channel. Without a result shape or notification log, headless callers cannot confirm delivery.
**Fix:** Describe the return shape (something like `['sent' => bool, 'channel' => string|null]` would make it self-checking), or expose a notification log endpoint.

### The HTTP twin of `setEventData` does not honour `null` as delete
**Needed:** Clear a value I had written, to re-test the write path.
**Docs said:** For `setEventData`: "passing `null` deletes the key".
**Reality:** The PHP method treats null as deletion, but the HTTP counterpart ignores it while reporting success. The two entry points need the same documented semantics.
**Cost:** One confused verification step where I thought my sweep had failed.
**Fix:** Make the HTTP endpoint match, or document that it ignores nulls.

### `installed` and `enabled` are separate states and only one of them is documented
**Reality:** A freshly rsynced plugin appears in `GET /plugins` with `"installed": false, "enabled": false`. There is no `install` endpoint (HOWTO lists enable/disable/**uninstall**). `POST /enable` turns out to do both. It worked, but for a few minutes I was looking for the install step I had missed.
**Fix:** One sentence: "enable installs on first use; there is no separate install call."

### `decoration.icon` has no documented vocabulary
**Docs said:** "`decoration` is optional: `{icon, color, animation}` where animation is `none`, `pulse`, or `shimmer`." The three animation values are enumerated; `icon` is not.
**Reality:** I guessed `"map-pin"`. The host accepted it with `errors: []` and echoed it back verbatim, so it is not validated , which means a typo is silent and I cannot tell whether it renders as an icon, as a broken icon, or as nothing. Not verifiable headlessly.
**Fix:** List the icon set, or say it is a free-form key into a named icon library and which one.

### No run history: `lastRun` only, so evidence of an earlier run is gone
**Reality:** A single `lastRun` record is overwritten by the next run. Authors cannot compare an earlier side effect with a later deduplication result without separate evidence.
**Fix:** A `GET /plugins/:id/runs` returning the last N would cost little and would make "did the dedupe work" directly checkable.

### Unstated: whether `eventsWindow()` caps its result set
**Needed:** Certainty that "no event overlaps this stretch" means there is genuinely no event. Every answer this plugin gives depends on it.
**Docs said:** Nothing about a cap. `replaceRanges` documents a 2000-row cap, and `GET /plugins/ranges` exposes a `truncated` flag, so caps clearly exist elsewhere in the system.
**Reality:** A matching count in a sample window does not prove there is no cap. The contract must state its limits and how callers learn that a window is incomplete.
**Cost:** A deliberate experiment. Cheap, but it is the kind of thing an author should be able to read rather than measure , a silent cap would make a free/busy plugin confidently wrong rather than visibly broken.
**Fix:** State the cap if there is one and how truncation is signalled, or state that there is none.

---

## Where the docs were good

These are not padding , each of these measurably saved me work.

- **The timestamp section prevents date shifts.** Keep all-day values as dates instead of converting their midnight representation to another zone. The user's zone and the server's zone need not match.
- **Generated context is not necessarily busy time.** Weather, recurring reminders and similar context need explicit per-calendar choices before a plugin uses them for availability.
- **Use the user's timezone.** A server's zone is not a safe default for user-facing date calculations.
- **The model's null-result warning.** A deterministic fallback should be ready before the first run.
- **The "Host coercions you will not be told about" table** is the right idea and the right tone. It is only wrong by omission (settings are missing from it), and it is the section I re-read most.
- **The `sourceKey` / wholesale-replace vs upsert distinction** (`replaceRanges` replaces everything, `setEventData` persists forever and you own clearing it) is stated crisply and is exactly the distinction that bites. `eventsWithData($key)` "exists so you can sweep your own leftovers" told me how to build the sweep before I needed it.
- **The `eventSettings` namespace warning** , "keys you write share one namespace … keep them distinct or a job will clobber the user's own choice" , made me pick `matched` against the user's `notBusy`. Verified live: both coexist on the same event and my job's write leaves the user's toggle alone.
- **Permission enforcement.** An undeclared capability is refused and recorded as a failed run; a later successful run clears the consecutive-failure count.
- **`PluginInterface` really is just the two methods.** `return new class implements PluginInterface` with `validateSettings` + `runJob` loaded first time, no autoloader surprises, no third method to discover. The "drop it in, no build step" claim is true.
- **The sanitizer is well-behaved and does not fight you.** `<a href>`, `<ul>/<li>`, `<strong>`, `<em>` and an `&amp;`-escaped query string all survive intact, and it *hardened* my markup by rewriting `rel="noopener"` to `rel="noopener noreferrer"`. "You ship data, never markup , `detailHtml` is the one rich field" is accurate.
- **Changing a manifest on an already-installed plugin hot-reloads the schema.** I added four settings keys mid-session; they appeared in `settingsSchema` with their defaults populated on the next API read, with the user's existing override preserved. No re-enable, no reinstall. Undocumented, but a genuinely nice behaviour.
- **Deleting an event cascades its plugin data.** After a proposal undo removed the event, `eventsWithData('matched')` no longer listed it and nothing leaked.
