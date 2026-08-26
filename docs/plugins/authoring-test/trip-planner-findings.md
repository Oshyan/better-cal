# Authoring friction log — `trip-planner` (agent B)

Written as I go. Timestamps are local (US Pacific).

---

## 2026-08-07T09:35-07:00 — before writing any code

### The per-run HTTP request budget is a number I am never told
**Needed:** To decide my fetch strategy. I want 1 climate-normals request + 1 forecast request + possibly a geocode. I need to know whether that fits, and what happens when it does not.
**Docs said:** `http(): HttpClient` — "...caps at 5 MB / 20 s / 3 redirects, and enforces a per-run request budget." Every other cap in that sentence has a number. This one does not.
**Reality:** Unknown until I trip it. I also do not know the failure mode - exception, `null`, or a silently-empty body - so I cannot write the right `catch`.
**Cost:** A guess at design time. Designing defensively (batch everything into as few requests as possible) and will probe empirically.
**Fix:** State the number and the failure mode: "...and a budget of N requests per run; the (N+1)th throws X." Same for the 20 s and 5 MB caps - what does exceeding them raise?

### GOOD: the enforced-permissions sentence
**Docs said:** "For the four capabilities that reach outside your own data (`people`, `notify`, `llm`, `propose`) the declaration is enforced - calling them undeclared throws."
**Reality:** Clear, and it told me to declare `llm`/`propose`/`notify` up front instead of discovering it as a failed run. Positive finding.

### Manifest key `settings` comes back from the API as `settingsSchema`
**Needed:** To verify the host parsed my manifest.
**Docs said:** manifest uses `settings` / `calendarSettings` / `eventSettings`. `GET /plugins` is "its manifest as the host parsed it".
**Reality:** The API renames them to `settingsSchema` / `calendarSettingsSchema` / `eventSettingsSchema`, and `settings` on the same object means the stored values.
**Cost:** 30 seconds reading a JSON dump.
**Fix:** One line in the verification section naming the response keys.

### `GET /plugins/ranges` with no params returns a 500, not a 400
**Needed:** To learn the wire format of a range before writing `replaceRanges()`.
**Docs said:** HOWTO lists `GET /plugins/ranges` with no mention of required parameters.
**Reality:** `GET /api/v1/plugins/ranges` -> `{"error":{"code":"internal_error","message":"Internal server error"}}`. With `?start=...&end=...` it works fine. A missing required query param should be a 400 with the param name.
**Cost:** One confusing request; I guessed that it wanted the same start/end as `/events` and was right.
**Fix:** HOWTO should show the params (`GET /plugins/ranges?start=<iso>&end=<iso>`); the host should 400 rather than 500. (Host bug, not fixing it - I am a third-party author.)

### `replaceRanges()` start/end format is unspecified
**Needed:** To draw multi-day bands.
**Docs said:** "Range: `{sourceKey, start, end, label?, color?, detailHtml?}`" - and nothing about the format, even though `syncEvents` two lines above explicitly documents "`start`/`end` are `YYYY-MM-DD` for all-day (end exclusive) or ISO instants."
**Reality:** Had to read another plugin's *output* through `GET /plugins/ranges` to learn that ranges come back as UTC instants (`2026-08-07T17:50:00+00:00`). Still do not know whether a date-only string is accepted, or whether a band spanning many days renders sanely.
**Cost:** API archaeology against someone else's plugin output. An author without a populated instance could not have done this.
**Fix:** Say it: "`start`/`end` are ISO instants; date-only strings are/are not accepted; bands may span multiple days."

## 2026-08-07T10:20-07:00 — first deploy, settings probes

### SEVERE: `validateSettings()` receives only the keys being changed, not the effective settings
**Needed:** Cross-field validation ("shortest trip must not exceed longest trip") and a required-field rule ("you need a destination, or a sentence describing one").
**Docs said:** "`public function validateSettings(array $values): array` — Return {key: error}. Empty = accept. Runs synchronously on save." Nothing about what `$values` contains.
**Reality:** `$values` is **only the patch body**. Stored settings are not merged in first. Reproduction, against a plugin whose stored `idea` is a non-empty string:
```
PATCH /plugins/trip-planner/settings  {"minDays":30}
-> 400 {"error":{"code":"invalid_request",
        "message":"Invalid settings: {\"destination\":\"Give me a destination, or describe the trip in the box above.\"}"}}
```
My rule is `if idea === '' && destination is not a place -> error`. `idea` *is* set in storage; it was absent from `$values`, so the rule saw an empty string and fired. The same probe also proves my `minDays > maxDays` rule did **not** fire (stored `maxDays` is 21), because `maxDays` was not in `$values` either.
The consequences are worse than a false positive: with a required-field rule in place, **no single-field PATCH can ever succeed**, so the plugin becomes unconfigurable through the API. And a cross-field rule is silently dead rather than loudly wrong, which is the bad failure mode.
**Cost:** 6 probe requests and a rewrite of `validateSettings`. I had to delete the required-destination rule entirely and move it to a runtime warning, and guard every cross-field rule with `isset()` on both keys.
**Fix:** The docs must say one sentence either way. If partial is intended: "`$values` contains only the fields being saved - guard cross-field rules with `isset()`, and do not write required-field rules here; enforce those in `runJob` as a warning." If it is a host bug, merge stored values before calling.

### GOOD: the host validates the manifest schema before my validator runs
**Reality:** All three of these are rejected by the host with useful messages, so I did not have to write any of it:
- `{"minDays":999}` -> `{"minDays":"maximum 90"}` (manifest `min`/`max` enforced)
- `{"units":"Kelvin"}` -> `{"units":"must be one of F, C"}` (`select` options enforced)
- `{"candidates":"three"}` -> `{"candidates":"must be a number"}` (types enforced)
This is genuinely nice and the docs undersell it. Worth one line: "the host enforces `type`, `min`, `max` and `options` before `validateSettings` is called, so validate only what the schema cannot express."

### `PATCH /settings` returns the patch, `GET /plugins` returns the merge
**Reality:** `PATCH` echoes back `{"settings":{"idea":"...","debug":true}}` - just what I sent. `GET /plugins` shows the full 15-key object with manifest defaults applied. Only stored keys are persisted; defaults are overlaid on read. Confirms `settings()` in a job gets defaults, which the docs do say ("stored over manifest defaults") - that part was accurate and I relied on it.

### `installed: false` and there is no documented install step
**Needed:** Get from "rsynced the directory" to "running".
**Docs said:** HOWTO lists `enable`/`disable`/`uninstall`. authoring.md says "Drop it in, open Manage -> Plugins, and enable it."
**Reality:** A freshly rsynced plugin shows up in `GET /plugins` with `"installed": false, "enabled": false`. `POST /enable` returned `{"ok":true}` and flipped **both** to true. That worked, but I could not tell from the docs whether I needed an install call first, and there is an `uninstall` endpoint with no `install` counterpart to pair with.
**Cost:** A guess that happened to work.
**Fix:** "Enabling installs. `uninstall` is the inverse of enable-plus-install and purges your data."

### HOST BUG: a `location` setting accepts out-of-range coordinates
**Needed:** To trust `{name, lat, lng}` from a `location` field enough to put it straight into a weather API URL.
**Docs said:** "`location` (place picker -> `{name, lat, lng}`)".
**Reality:** The host does validate the *shape* - `{"destination":"Kailua-Kona"}` and `{"destination":{"name":"x","lat":19.64}}` both give `400 {"destination":"must be a place (name + coordinates)"}`, which is good. But it does **not** validate the range:
```
PATCH {"destination":{"name":"Bad","lat":991,"lng":-155}}  ->  200, stored as-is
```
An author who trusts the field builds a URL with `latitude=991`. I now range-check lat/lng myself in both `validateSettings` and `runJob`.
**Cost:** One probe. Cheap because I went looking; an author who assumed the picker was the only writer would not have.
**Fix:** Clamp/reject in the host, and until then say in the docs that `location` values are shape-checked but not range-checked.

### GOOD: `calendarSettings` enforcement and error messages
**Reality:** `PATCH /plugins/:id/calendar-settings/11 {"travel":"Whatever"}` -> `400 {"travel":"must be one of Auto, Blocks travel, Soft conflict only, Ignore"}`, and an unknown calendar id -> `400 "Unknown calendar"`. Both exactly right. Also: `select` `options` are free-form strings, so human-readable option labels ("Soft conflict only") work as values - the docs' only example is `["F","C"]`, which made me unsure whether options had to be terse tokens.

### `budgetRemaining()` unit and `logTail` length were both guesses
**Needed:** To write a "stop when the budget runs low" loop, and to know how much I could log.
**Docs said:** "`log(msg)` / `budgetRemaining()` / `overBudget()` - the 60 s soft budget; long loops should check and stop."
**Reality:** `budgetRemaining()` returned `59.9993679523468` at the top of the job - float **seconds**. `logTail` keeps multiple lines joined with `\n`, not just the last one. Neither is stated; both are things you have to log-and-look to learn.
**Cost:** One diagnostic run. Trivial, but it is the kind of thing that should not need a run.
**Fix:** "`budgetRemaining()` returns seconds as a float." "`log()` lines are newline-joined and the last N appear as `lastRun.logTail`."

### The host writes its own lines into MY run log
**Reality:** `lastRun.logTail` for my job contained `[18:48:28] llm not configured; using fallback` - a line I never wrote, emitted by the host when `llmJson()` cannot run. That is genuinely useful (the docs promise the *return value* is indistinguishable between "unconfigured" and "failed", and it is - but the log tells you which). It is also surprising: my log is not only mine, and a plugin that parses its own log would break.
**Fix:** Mention that the host may add lines to a job's log, and that `llmJson()` leaves a breadcrumb there.

## 2026-08-07T10:50-07:00 — first real runs, and the sanitizer

### SEVERE: the HTML allowlist for `detailHtml` / `rationaleHtml` is undocumented, and it is not the one you would guess
**Needed:** Structured, legible reasoning in a proposal - headings, a small weather table, a link to the event that blocked a window.
**Docs said:** "All text you pass through `syncEvents`, `replaceRanges`, or `replaceWarnings` is sanitized by the host (**the same allowlist as event descriptions**), server-side at write and again client-side at render. You ship data, never markup - `detailHtml` is the one rich field and it is sanitized both ways."
That sentence points at an allowlist I have no way to read. I am a third-party author; "the same as event descriptions" is a pointer into source I do not have.
**Reality:** My `<h4>` headings silently vanished (text kept, tag dropped) and the proposal read as a wall of unstructured text. I had to measure the allowlist by shipping a probe string through `detailHtml` and reading it back via `GET /plugins/ranges`. Result:

| | outcome |
|---|---|
| kept | `p`, `div`, `ul`, `ol`, `li`, `strong`, `b`, `em`, `i`, `u`, `br`, `a` |
| **tag stripped, inner text kept** | `h1`-`h5`, `span`, `section`, `s`, `small`, `code`, `pre`, `blockquote`, `dl`/`dt`/`dd`, `table`/`tr`/`th`/`td` |
| removed entirely | `hr`, `script`, `img` |
| attributes | `style`, `class`, `id`, `title` all stripped; only `href` survives, and the host adds `target="_blank" rel="noopener noreferrer"` |
| `<a href="/local">` | **the anchor itself is stripped** - relative URLs do not survive, only absolute ones |

The `div`-yes/`h4`-no and `ol`-yes/`table`-no combination is not guessable. Neither is the relative-link rule, which matters: a plugin cannot link from its own detail card to the event that caused the finding.
**Cost:** One wasted deploy that shipped flattened, unstructured HTML to a real proposal, plus a purpose-built probe deploy to measure the list. Two extra deploy/run cycles.
**Fix:** Publish the list. Literally the table above, in authoring.md. And state the relative-href rule explicitly, because `warnings[].eventId` implies deep-linking is a thing the platform does.

### AUTHOR TRAP (my bug, but the docs invite it): do not put HTML entities through your own escaper
**Reality:** I wrote `$this->esc($a . ' &ndash; ' . $b)` and shipped `2016&amp;ndash;2025` to a live proposal. Obvious in hindsight; easy to do when the docs tell you everything is sanitized and you reflexively over-escape. The host's own sanitizer decodes `&mdash; &ndash; &middot; &times; &plusmn; &deg; &nbsp; &rarr; &hellip;` correctly and re-encodes `& < "` properly, so entities are fine - just not double-escaped ones.
**Fix:** One line: "The host decodes named entities and re-escapes on output; pass literal UTF-8 or plain entities, and do not `htmlspecialchars()` your own markup."

### `propose()` return value is undocumented
**Docs said:** "`propose(array $proposal): array`".
**Reality:** It returns the whole stored proposal record: `{"id":8,"pluginId":"trip-planner","sourceKey":...,"title":...,"summary":...,"rationaleHtml":...,"plan":...,"status":"open","createdAt":...,"decidedAt":null}`. Useful - the `id` is the only way to correlate with `GET /proposals` - and impossible to know without calling it.
**Fix:** Show the return shape, and say that `id` is what `GET /proposals` will show.

### GOOD: the timezone warning was worth its weight
**Docs said:** "`timezone(): DateTimeZone` - **the user's** zone... `date_default_timezone_get()` is the server's zone and may be a continent away." And: all-day occurrences "come back as `YYYY-MM-DDT00:00:00+00:00`... Do **not** timezone-convert it; converting slides Saturday into Friday west of UTC."
**Reality:** Both true and both load-bearing. My run logged `tz=America/Los_Angeles serverTz=Europe/Berlin`. And my band boundaries came out as `2026-11-02T08:00:00+00:00` for the window starting 2 Nov (PST, -08:00) while the previous band ended `2026-10-12T07:00:00+00:00` (PDT, -07:00) - correct across the DST change, first time, because I built every local midnight through `timezone()`. This is the single best part of the docs. Without those two paragraphs I would have shipped an off-by-one-day bug.

## 2026-08-07T11:05-07:00 — contract probes

### SEVERE: a date-only `start` in a proposal's `plan.events` silently becomes a TIMED midnight-to-midnight event
**Needed:** All-day travel-day markers in the proposed plan. I have no idea what time the flight is, so inventing "09:00-17:00" is a lie the user has to correct.
**Docs said:** For `syncEvents`: "`start`/`end` are `YYYY-MM-DD` for all-day (end exclusive) or ISO instants." For proposals: "each needs a `title` and a `start`" and the example shows ISO instants. Nothing about whether the `syncEvents` date convention carries over.
**Reality:** `propose()` **accepts** `{'title':..., 'start':'2027-02-23', 'end':'2027-02-24'}` without complaint. On Accept it materialises as:
`10293 | 2027-02-23T00:00:00-08:00 -> 2027-02-24T00:00:00-08:00 | allDay false`
- a 24-hour *timed* event. The same string means "all-day" in `syncEvents` and "midnight to midnight, timed" in `plan.events`. There appears to be **no way to propose an all-day event** except via the single `trip` key.
**Cost:** A deliberate probe deploy plus an accept/undo cycle. Without probing I would have shipped either wrong-looking 24h blocks or (as I did) invented clock times with a "these are placeholders" apology in the description.
**Fix:** State it: "`plan.events` are always timed; a date-only `start` is interpreted as local midnight. Only `trip` is created all-day." And ideally add an `allDay` flag to plan events.

### `propose()` accepts a plan 400 days in the past without comment
**Reality:** `{'start':'2025-07-03T10:00:00-07:00'}` proposed and accepted fine. Not necessarily wrong, but it means every sanity check on dates is the author's job, and the "Enforced plan rules" list reads like it is exhaustive when it only covers count, title, start, and the trip triple.
**Fix:** Say the enforced list is exhaustive and that nothing else - including whether the dates are in the future - is checked.

### The exception type thrown by `propose()` is undocumented, and it is an HTTP class
**Reality:** A 51-event plan throws `BetterCal\Http\HttpError: Invalid plan: plan.events exceeds 50`. The documented rule is real and the message is good. But the class is `BetterCal\Http\HttpError` inside a worker job, which is surprising, and the docs name no exception type at all - so the only safe catch is `\Throwable`.
**Fix:** Name the exception class(es) the host API can throw. Authors need it to distinguish "my plan is malformed" from "the network is down".

### GOOD: the SSRF client does exactly what it claims
**Reality:** Probed it directly. Every one is refused with a legible message:
```
http://127.0.0.1/                        -> Refused private/internal address for 127.0.0.1 (127.0.0.1)
http://localhost/                        -> Refused private/internal address for localhost (127.0.0.1)
http://169.254.169.254/latest/meta-data/ -> Refused private/internal address for 169.254.169.254
http://192.168.1.1/                      -> Refused private/internal address for 192.168.1.1
http://[::1]/                            -> DNS resolution failed for [::1]
```
The one wrinkle: a bracketed IPv6 literal fails at DNS rather than at the address check, so the message is misleading even though the outcome is right.

### The "per-run request budget" is not a real constraint, and the sentence made me design around a ghost
**Needed:** To know whether I could afford a forecast call as well as a climate-archive call.
**Docs said:** "...and enforces a per-run request budget."
**Reality:** **60 sequential HTTP requests in one run all succeeded** (24.8 s, `outcome=ok`). Whatever the budget is, it is above 60 and is not something a normal plugin will hit. I spent real design effort collapsing 10 years of climate data into a single request because that sentence made me think requests were scarce. (The single-request design is still better, but I should have chosen it for latency reasons, not fear.)
**Fix:** Either give the number or drop the clause. As written it is a cost with no information.

### `budgetRemaining()` measured across a long run
**Reality:** Confirmed float seconds and it decrements in real time: `59.99` at start, `47.6` after 30 HTTP calls. Useful and it works. Still undocumented as to units.

## 2026-08-07T11:25-07:00 — proposal lifecycle

### SEVERE: there is no way to withdraw a proposal, and the two available key strategies are both broken
**Needed:** Keep exactly one live suggestion. My natural `sourceKey` was `trip-<dest>-<startDate>-<len>`, because that is what identifies a window.
**Docs said:** "`sourceKey` - stable: re-running REPLACES your own open proposal" and "on a proposal the user already decided `propose()` silently no-ops and returns the old one - so check `myProposals(null)` first and skip decided keys".
**Reality:** Those two sentences describe the trap without naming it. With a window-derived key:
- nudge the horizon from 120 to 90 days, and "Oct 12–Nov 1" becomes "Oct 15–Nov 4" - a **new** key, so the old proposal stays open forever. After an afternoon of ordinary setting changes I had **five** open Trip Planner proposals suggesting overlapping trips, and no API to retract any of them. The `open` ones are dead weight the user must reject by hand.
- with a *stable* key instead, one rejection permanently silences the plugin, because `propose()` no-ops on a decided key for good.
There is no `withdraw()`/`retract()`/`replaceProposals()`. This is the one place the platform's otherwise-consistent "wholesale replace" model is missing: `replaceRanges` and `replaceWarnings` are wholesale, `propose` is an upsert with no delete.
**Cost:** A full redesign late in the build. I ended up with a **generation counter in kv** - `sourceKey = trip-plan-g<N>` - so there is never more than one open proposal; a rejection burns generation N and the next run offers the next-best window under N+1. I also had to keep every offered window's dates in kv myself, because `myProposals()` returns only `{sourceKey, status}`, so "is this new window basically the one they already said no to?" is unanswerable from the host API alone.
**Fix:** Add `withdrawProposal(sourceKey)` or make proposals wholesale like ranges. Failing that, document the generation-counter pattern explicitly - it is not obvious and every proposal-emitting plugin needs it.

### `GET /proposals` hides decided proposals by default, which is not stated
**Reality:** After accepting, `GET /proposals` returned nothing for my plugin and I briefly thought the record had been deleted. `?status=accepted` and `?status=all` both work. Neither parameter is mentioned in HOWTO's endpoint list.
**Fix:** `GET /proposals[?status=open|accepted|rejected|all]` - default `open`.

### `undo` returns a proposal to `open`, which is a fourth state transition nobody documents
**Docs said:** "**Statuses are `open`, `accepted`, `rejected`.**"
**Reality:** `POST /proposals/8/undo` -> `{"undone":5,"skipped":0}`, the 3 created events vanish, and the proposal goes back to **`open`** - so it will be re-proposed and can be accepted again. Sensible, but it is a real state machine edge that changes how you write the "have I already handled this?" check.
**Fix:** "Undo reverses the mutations and returns the proposal to `open`."

### Uninstall purges proposals (undocumented) but leaves `calendarSettings` behind (also undocumented)
**Docs said:** "Uninstall always purges your ranges, warnings, runs, and KV."
**Reality:** `POST /uninstall` -> `{"ok":true,"purged":{"events":0,"calendars":0,"ranges":2,"warnings":14,"kv":7}}`. Two things the list does not cover:
- **Proposals are purged too** - `GET /proposals?status=all` went from 10 trip-planner rows to zero - but they are not in the `purged` breakdown, so the receipt under-reports what was destroyed.
- **Per-calendar settings survive.** After uninstalling, `GET /calendars` still shows `"pluginSettings": {"trip-planner": {"travel": "Ignore"}}` on two calendars, while plugin-scope settings were reset to manifest defaults. So half my configuration is orphaned on the calendars forever.
**Fix:** Add proposals to both the sentence and the `purged` breakdown, and say explicitly that `calendarSettings` are kept (or purge them).

## 2026-08-07T11:45-07:00 — the brief versus the platform

### The request's headline feature - "a separate AI chat mode" - is structurally unbuildable, and the docs say so clearly enough that I knew within five minutes
**Docs said:** "Your plugin's code runs in exactly two places: inside a worker job (`runJob`), and inside the settings-validation hook (`validateSettings`)... It never runs during a page request. ...it is enforced structurally - there is no hook that runs your code on the render path."
**Reality:** Correct, unambiguous, and it saved me from building the wrong thing. But it means a plugin can never be conversational: no request handler, no UI surface of its own, no way to render a chat box, no way to answer a user turn-by-turn. What a plugin *can* do is take a stored question and answer it on a schedule.
So I mapped the request onto what exists:

| The request | What the platform gives | Verdict |
|---|---|---|
| "A separate AI chat mode" | `settings` fields only; no request-path hook | **Not buildable.** Approximated by a free-text `idea` field parsed with `llmJson()` on the next run - one turn, not a conversation. |
| "calendar-aware… evaluate the calendar" | `eventsWindow()` | Fully buildable. |
| "understand the difference between calendar types" | `calendars()` `kind` + `calendarSettings` | Fully buildable, and the nicest fit in the whole brief. |
| "additional calendar settings like 'blocking for travel'" | `calendarSettings` select | Fully buildable, exactly as described. |
| "call the weather calendar plugin" | **nothing** | **Not buildable** - see below. |
| "what will the weather be like" | `http()` to any keyless API | Buildable, but by duplicating the Weather plugin's job, not by asking it. |
| "a markdown note/plan that's downloadable" | no file/asset/route API | **Half.** The note exists as Markdown in the trip's description and as HTML in the proposal; nothing is downloadable. |
| "optional (with user go-ahead) insertion" | `propose()` | Fully buildable, and this part of the platform is genuinely excellent. |

### SEVERE: plugins cannot see or call each other, and nothing in the docs says so
**Needed:** The request literally says "can e.g. call the weather calendar plugin to get weather from a specific location". More modestly: read the Weather plugin's settings to default my destination to the user's home town, or reuse its forecast instead of fetching my own.
**Docs said:** Nothing. There is no negative statement anywhere. `calendars()` returns plugin-owned calendars and even labels them `kind === 'plugin'`, and the eventsWindow section warns about the Weather plugin *by name* - so the docs establish that other plugins exist and are visible, then never say the relationship is read-only-through-the-calendar.
**Reality:** The `PluginHost` surface has no `plugin(id)`, no cross-plugin settings read, no service registry. The only cross-plugin channel is: another plugin's materialised events show up in my `eventsWindow()`, as opaque titles. I could technically scrape "Weather" calendar event titles for a forecast; that is a string-parsing hack against a private format and I refused to build it. So I fetch Open-Meteo myself - which means on a machine with the Weather plugin installed, two plugins independently hit the same API for the same place.
This is the gap between what people will ask for and what the platform's shape allows, and it is invisible until you go looking.
**Fix:** State it in Trust or in the host API section: "Plugins cannot call or read each other. The only shared surface is the calendar itself." If cross-plugin reads are ever wanted, a declared `reads: ["weather"]` permission with a `$host->pluginData('weather')` accessor is the obvious shape.

### `calendars()` returns less than the calendar API does, and the missing field is the one I needed
**Needed:** Sensible defaults for "does this calendar block travel?" - the whole point of the requested feature.
**Docs said:** "`calendars(): array` - the user's calendars (`id`, `name`, `kind`, `visible`)."
**Reality:** Accurate, and `kind` carries real signal (`local` / `subscribed` / `plugin`), which let me default plugin calendars to ignored and subscribed feeds to non-blocking. But the REST API's calendar objects also carry `folderIds`, `tagNames`, `sourceUrl` and `position` - and a plugin sees none of them. On this instance the user has a "Travel Planning" calendar of speculative trips and a "Todoist" calendar with **2741 chore occurrences in 120 days**; both are `kind: local`, indistinguishable from "Personal". I had to write an auto-quiet heuristic ("this calendar has something on 118 of 120 days, so it is not telling me anything") to stop the task calendar destroying every score.
**Cost:** Real design work, and a wrong first run. The docs *do* warn about this ("recurring all-day chores are all-day events too, and they are not what 'this day is taken' means") - that warning was accurate and valuable, it just does not come with the data you would need to act on it automatically.
**Fix:** Either expose `tagNames`/`folderIds` on `calendars()`, or say plainly that `kind` is the only signal and every other distinction must come from `calendarSettings`.

### `kvGet`/`kvSet` have no documented signature, value type, or size limit
**Docs said:** "`kvGet/kvSet/kvDelete` - namespaced storage that survives between runs; dropped on uninstall."
**Reality:** No parameter list, no return type, no statement about whether values may be arrays or must be strings, and no size cap. I JSON-encoded everything defensively. It happily stored a ~40 KB JSON blob (366 days of derived climate normals), and `kvGet` on a missing key returns null. All fine - but every bit of that was a guess I had to verify by running.
**Fix:** `kvSet(string $key, string $value): void` / `kvGet(string $key): ?string`, plus the size cap, plus whether keys are truncated like `setEventData`'s 120 chars.

### `geocode()` has no documented return shape, and on this instance it returns null
**Docs said:** "`geocode(string $query): ?array` - the host geocoder (cached)."
**Reality:** `?array` of *what*? I guessed `{name, lat, lng}` to match the `location` field and wrote a fallback to Open-Meteo's geocoder. Just as well: `$host->geocode('Hawaii')` returned **null** on the live instance, so the host geocoder never actually served me. I never found out what a successful call looks like.
**Fix:** Document the returned keys, and say what makes it return null (no result? not configured? rate-limited?).

### `POST /plugins/:id/run` runs every job; you cannot ask for one
**Reality:** `{"queued":["plan"]}` - an array. With one job it does not matter, but a plugin with a cheap job and an expensive one cannot trigger just the cheap one from the API, which made probing awkward.
**Fix:** Mention it, or accept `{"job":"refresh"}`.

### GOOD: the parts that were right, and that I leaned on
- **The two timezone paragraphs.** Best-written thing in the doc. Both warnings were real and both would have cost me a day.
- **"The window includes plugin-owned calendars."** Named the exact failure mode (Weather's one-all-day-event-per-day) *and* prescribed the fix (`kind === 'plugin'`, plus offer a toggle). I implemented it straight from that paragraph and it was correct on first run: Weather contributed 6 occurrences and Tides 57 to my window, both correctly ignored.
- **"Host coercions you will not be told about."** A table of silent clamps, in the docs, before I hit any of them. That is exactly the right instinct; the section just needs to be twice as long (the HTML allowlist and the plan-event date rule belong in it).
- **The proposals model itself.** "Generating a proposal never touches the calendar - only the user pressing Accept does, and acceptance materializes the whole plan atomically under one run id." All true. Accept created a trip container plus two linked events; undo removed all five mutations in one call. This is the single best-designed capability in the platform and it is exactly what the brief's "nothing reaches my calendar without me saying yes" needs.
- **`llmJson()` returning null indistinguishably**, stated up front. It meant I designed the deterministic fallback first rather than bolting it on - which turned out to matter, because the model is not configured on this instance and **every run of this plugin has been in fallback mode**. The plugin has never once needed the model to produce its output.
- **Circuit breaker and `consecutiveFailures`** exposed in `GET /plugins`. I stayed at 0 throughout, but knowing the number was visible made me willing to probe destructive edges.

## 2026-08-07T12:15-07:00 — last pass

### `decoration.icon` has no documented value space
**Docs said:** "`decoration` is optional: `{icon, color, animation}` where animation is `none`, `pulse`, or `shimmer`."
**Reality:** `animation` gets its three legal values spelled out; `icon` gets nothing. Is it an emoji? An icon-set name? An SVG path? A URL? I shipped `"icon": "plane"` on a guess. The host accepted it (`errors: []`) and echoed it back verbatim in `GET /plugins`, so it did not validate it either - which means a wrong value fails silently at render time, and I cannot see the render.
**Cost:** A guess I still cannot confirm.
**Fix:** Name the icon set, or the allowed strings, and reject unknown ones at manifest parse so `errors[]` catches them.

### AUTHOR TRAP: `date('Y')` inside a plugin is the SERVER's year
**Reality:** My own bug, caught by an extreme-settings run. I formatted a date span as "Tue 27 Oct – Sun 24 Jan" with no year, because the check was `substr($start,0,4) !== date('Y')` - and `date('Y')` is Europe/Berlin's year, not the user's. Around New Year, west of UTC, those differ. The docs warn about this for `date_default_timezone_get()` but the warning is attached to *timezone objects*; the same trap is sitting in every bare `date()`/`mktime()`/`strtotime()` call an author writes.
**Fix:** Widen the existing warning: "`timezone()` is the user's zone. Every bare `date()`, `mktime()` and `strtotime()` in your plugin runs in the server's zone - derive dates from `timezone()` instead."

### Not verifiable headlessly (stated as a result, per HOWTO)
Everything below is written and shipped, but I have no way to confirm how it looks:
- whether `decoration.icon: "plane"` renders as anything
- whether a **multi-day** overlay band draws sensibly - the only other range-emitting plugin (`tides`) makes 3-hour intraday bands, so mine at 21 days is an untested aspect ratio. My bands are correct as data: adjacent, non-overlapping, DST-correct (`…-10-12T00:00:00-07:00` and `…-11-02T00:00:00-08:00`).
- whether `warnings[].fix` is displayed at all, and where
- whether the `select` calendar setting renders its four human-readable options legibly in the calendar gear panel
- whether `notify()` reached anything - it returned without throwing, and the docs say it is "silent if no channel is configured", so success and no-op are indistinguishable to me. Same problem as `llmJson()`, but without the compensating log line.

### Summary of how much of this had to be discovered by running
Things I could not have got right from the docs alone, in the order they cost me time:
1. the HTML allowlist for `detailHtml`/`rationaleHtml` (measured with a probe)
2. `validateSettings($values)` being a partial patch (6 probes)
3. no proposal withdrawal + `propose()` no-op on decided keys (redesign)
4. `plan.events` date-only meaning "timed midnight-to-midnight" (probe + accept/undo)
5. `GET /proposals` defaulting to `status=open`
6. `undo` returning a proposal to `open`
7. uninstall purging proposals but not `calendarSettings`
8. `budgetRemaining()` unit; `logTail` being multi-line; the host writing into my log
9. the HTTP request budget being effectively unlimited (60 succeeded)
10. `propose()`'s return shape and exception class
11. `geocode()`'s return shape (still unknown - it returned null every time)
12. `kvGet`/`kvSet` signatures and value type
