# Client performance review (web/), 2026-11-14

Read-only review of the web client (web/src, web/vendor, web/styles, web/sw.js, web/index.html) at 3702b514, with the server read only where it decides what a call costs. No build, test suite, browser or server was run. Numbers marked "bench" come from small node scripts that import the client's own pure modules read-only and time them on synthetic occurrences on the dev Mac (scripts kept outside the tree, in the session scratchpad). Numbers marked "phone est." multiply the bench by 4, a common gap between a desktop CPU and a mid-range Android phone; they are unverified until recorded on the phone.

## How the client is built and loaded (context)

- No build step, on purpose: 154 ES modules (150 under src/, 4 vendor) are served as written; `server/src/Http/AppShell.php` fills a modulepreload block and an import map into index.html and the shell list into sw.js. Every module is reached by a static import from main.js; there is no dynamic import() anywhere in the graph.
- Sizes on disk: JS graph 1,665,307 bytes raw; about 572 kB gzip summed per file (388 kB brotli if concatenated); about 423 kB of the raw bytes are comments. app.css 291 kB raw, 68 kB gzip. Lazy vendor (loaded by script tag after the window lands): Squire 145 kB + DOMPurify 30 kB, Leaflet 147 kB + 15 kB CSS. Largest modules: actions.js 81 kB, TimeGrid.js 74 kB, EditorDrawer.js 71 kB, SettingsPage.js 64 kB, App.js 64 kB, MonthGrid.js 60 kB, planwords.js 54 kB.
- The service worker serves every app file cache-first from a per-version cache, so after the first visit a start downloads nothing for the shell; a deploy refetches the whole shell once per device. API calls go network-first with a 5 s fallback to a cached copy.
- The events window is already in good shape on the wire: list responses carry a trimmed grid shape (contract, "Two shapes, one type"), a held stretch is revalidated by ETag and answered 304 with no body (decisions.md, "The events window is validated by its change cursor"), and gaps are widened to whole months and merged into one request (api.js `monthGaps`), so a cold month view is one request, not five.
- Rendering model: one global store (store.js), `useStore(selector)` per component, Preact without memo anywhere in the tree. The occurrence cache (`state.occ`) keeps everything fetched up to a ceiling of 25,000 occurrences (store.js:295) and only prunes past it. Every `occVersion` bump (a window landing, any patch, an event's record arriving) re-runs several passes over the whole cache, not over what is on screen. That is the root of most findings below.

## Findings

### F1. Day view: the all-day pass walks the whole cache once per day in the window

- Where: `web/src/ui/TimeGrid.js:459-484` (`vAllDay`). Reached by opening Day view (phone: tap a date, or Day in the view sheet), by every window recentre while scrolling the day stack (`setVWin` at :702, about every 18 days scrolled; window is V_BEFORE 7 + V_AFTER 21 = 29 days), and by every `occVersion` bump while Day view is open (a month landing, opening an event and its record arriving, a drag, a change from elsewhere).
- What: `days.map(...)` runs, for each of the 29 days, `[...occurrences.filter(!isBand), ...occurrences.filter(isBand)]` and then `occurrenceDaySpan` for every occurrence in the cache. That is 29 full passes with two date parses and local-time conversions each. The horizontal path in the same file (`timedByDay`, :427-455) already does it in one pass.
- Bench: 102 ms for 3,000 cached occurrences, 338 ms for 10,000 (desktop). Phone est.: 0.4 s and 1.4 s per trigger.
- Change: one pass over the occurrences that buckets each all-day or multi-day item into the days it covers (events before bands, as now), then cut each day's list to its cap. Same output, O(N + items shown).
- Expected benefit: the 29x factor goes; 338 ms becomes about 12 ms at 10k on desktop (one pass), less with F2. Confidence high on the cost (measured on the real function shape), medium on the phone figure.
- Risk: low. The order (events, then bands) and `overflow` counts must match; covered by the browser checks in `web/tests/viewchecks.mjs` ("day view shows them in its header", "day view: month follows a far step", "day view: the arrows land on their day..."). No design principle touched.
- Measure: DevTools Performance on the phone, record "open Day view, scroll down three weeks"; look for the long task under TimeGrid render; or count with `console.time` around the memo.

### F2. An occurrence's day span and instants are recomputed on every pass, by every reader

- Where: `occurrenceDaySpan` (`web/src/ui/monthmath.js:58`, 38 call sites) and `startMs`/`byStart` (`web/src/lib/dates.js:43-50`, which parse the ISO string on every comparison). Whole-cache readers that run on each `occVersion`: MonthGrid `indexOccurrences` (`MonthGrid.js:83-121`, memo at :300, per-day sort with `byStart` at :119), MiniMonth dots (`MiniMonth.js:52-67`, desktop sidebar), TimeGrid (:427, :459, :497), `groupOccurrences` (`grouping.js:35`), `dayStepList` (`daysteps.js:44`, called in EventPopover's render at `EventPopover.js:448`, no memo), `mergeWindow`'s overlap scan (`store.js:394-449`), the agenda's inline filter (`App.js:1065`).
- What: each pass re-parses ISO strings and converts to local day keys per occurrence (about 1.2 µs each on desktop), and the per-day sorts re-parse both sides of every comparison.
- Bench (10,000 occurrences): `indexOccurrences`-shaped pass 22.2 ms parsing each time vs 2.2 ms with a per-object cache (10x). `occurrenceDaySpan` alone 11.8 ms; per-day `byStart` sort 12.5 ms vs 2.3 ms with parsed keys. At 25,000 the full MonthGrid index was 58 ms (phone est. 230 ms) per load landing.
- Change (one rule): an occurrence's span and start/end instants are computed once per occurrence object, inside `occurrenceDaySpan` itself (a WeakMap keyed by the object, also holding start and end ms), and every reader, `byStart` included, reads that. Occurrence objects are replaced, never mutated, on every change (`patchOccurrence` builds a new object; no in-place write of start, end or allDay exists in src/), so identity is a sound key. The one input besides the object is the device's time zone: key the cache on a zone epoch bumped where the app already notices a zone change (`session.js` visibilitychange handler).
- Expected benefit: every whole-cache pass above drops by roughly 5-10x; per `occVersion` bump at 10k cached, about 40-50 ms of desktop work (index + MiniMonth + popover list + sorts) becomes about 5-8 ms; phone est. from about 200 ms to about 30 ms. Confidence high on the ratio (measured), medium on the phone total.
- Risk: low to medium. A stale span after a zone change is the failure mode (wrong day for a timed event); the zone epoch covers it. Tests: the `occurrenceDaySpan` and `byStart` cases in `web/tests/smoke.mjs`, the clock-change checks in viewchecks.mjs. No principle touched.
- Measure: `const m = await import('/assets/src/app/store.js'); m.state.occ.size` in the phone's remote console after a normal session (the import map resolves to the same module instance), then a Performance recording of "open an event" before and after.

### F3. The month grid and 3-day ribbon re-render every row about every tenth of a row scrolled

- Where: `web/src/ui/MonthGrid.js:380-381` (`setRange` returns a new range whenever `topOffset` moved 0.1 of a row), the reporting effect at :591-654 whose deps include `range.topOffset`, and `WeekRow` (:1016), which is a plain function component. The comment at :126 says "the row component is memoized on it"; nothing in the tree is memoized (no memo, shouldComponentUpdate or PureComponent anywhere in src/, and Preact core has no memo).
- Reached: every scroll of the month view (desktop) and of the phone's 3-day ribbon, which is the same component with 3 or 5 columns. The most frequent action in the app.
- What: with rows of about 100-140 px, a new range object every 10-14 px means a MonthGrid render on nearly every animation frame of a scroll. Each render re-runs all rendered rows (visible rows plus 3 either side, about 11-14 rows): `weekLanes`, `planCell` per cell, and a vnode for every chip, DayNum, Icon and button, with `EventChip` doing colour maths and a time format per chip. The reporting effect also runs each time (`onVisibleMonthChange`, `loadWindow`), cheap but not free.
- Change (one rule): the grid renders when its set of rows changes, not when the position inside a row changes. (a) `setRange` only when `first`, `last` or `top` change, plus a boolean for "top row more than half off screen", which is the only use of `topOffset` in the month label (:613); in fit mode, the pixel-weighted month (`dominantMonthOfWeeks`) is computed and reported from the rAF callback itself, which does not need a render. (b) Wrap `WeekRow` in a shallow-props memo (a ten-line class with `shouldComponentUpdate`, added to vendor/index.js or a ui helper). Its props are already stable between scroll renders: `rowEntries` keeps array identity per index, the callbacks are `useCallback`s, App's handlers are stable.
- Expected benefit: renders during a scroll fall from about one per frame to one per row crossed (about 10x fewer), and each remaining render touches the one or two rows entering, not all twelve (another 6-10x). Under fakedom, a re-render of 8 rows with no chips drawn took about 2 ms on desktop; with chips on a dense month it will be several times that, and phone est. 15-40 ms per frame, which is over budget. Confidence: high on the mechanism, medium on the size until recorded.
- Risk: medium-low. The month label and "arrived" logic (decisions.md, "When a move has arrived, and what a month step starts from"; "A glide under a load") depend on this reporting; covered by smoke.mjs "next month:" and "prev month:" cases and the viewchecks "month view: a glide finishes when data lands during it", "month view: a glide arrives after a load that changes no heights", "switching views keeps the scrolled-to place". A memo goes stale only if a prop is mutated in place; `idx.rowCache` arrays are created once per index, so that holds. Continuity, density and scrollbars unaffected.
- Measure: Performance recording on the phone of a two-second fling in the 3-day ribbon and on desktop in month view; count MonthGrid and WeekRow renders with a temporary counter, and read dropped frames in the Frames track. Also fix the comment at :126 either way.

### F4. Opening and closing an event re-runs the whole pipeline three times

- Where: `App.js:326-348`: the `occurrences` memo lists `openId` (the open panel's instanceId) among its deps, so opening and closing a panel each rebuild the visible list over the whole cache, which gives MonthGrid a new array and rebuilds its index, `dayInfo`, `occById` and every rendered row. Then the record fetch (`api.js:707-735` `ensureFullOccurrence` → `store.js:628` `patchOccurrence`) bumps `occVersion`: a third full run. On desktop the hover prefetch (`prefetch.js`, 120 ms intent, two at a time) does the same for every chip hovered whose record isn't held.
- What: `openId` only matters for one occurrence: a `_held` one (opened from outside any window) is drawn only while open. Nothing else in the list depends on it.
- Change: build the list without `openId`; add the held open occurrence, if any, after the memo (one lookup). Separately, and only after measuring: a record arriving that changes no field the list shape already had need not bump `occVersion` (EventPopover would then need its own signal; `styleJson` and location can arrive this way and are drawn, so the rule is "bump when a drawn field changed", computed by comparing, not by listing fields).
- Expected benefit: two of three whole-cache runs per open/close go away now (the `openId` part). Per open at 10k cached: about 2 x (index + App list + row renders), phone est. 2 x 100-200 ms today, 2 x 20-40 ms after F2. The record-bump part saves the third run and the hover-driven runs on desktop. Confidence high on the `openId` part.
- Risk: `openId` part low (a filter-hidden event opened from a notification must still draw while open: smoke's #184 cases around held occurrences, App.js comment at :333-336). The record-bump part medium: a field drawn by a chip or agenda row arriving with the record must still redraw; leave it in "measure first".
- Measure: Performance recording of "tap an event in the 3-day ribbon, close it" on the phone; count `indexOccurrences` calls.

### F5. Agenda: every day scrolled rebuilds the whole list and re-renders the whole app

- Where: `App.js:157` puts `agendaTop` (the agenda's top day) in App's own selector, so every top-day change re-renders App and everything under it (Sidebar, Toolbar, the view). `App.js:1054-1068` computes `agendaOccs` inline, with no memo (two `parseISO` per occurrence plus the held-stretch filter), so AgendaList gets a new array each App render and its memos (`AgendaList.js:181-221`: `contextByDay`, `dayLabelsByDay`, `buildAgendaGroups`, `spanPixelRanges`, `railRanges`, `washRects`) all recompute. Day headers are drawn for every group in the stretch, visible or not (`AgendaList.js:498`, only rows are gated by `visible`); the stretch is about 210 days and grows by 120 days per near-end load up to two years. The scroll handler is attached directly (`:319`, not rAF-throttled) and re-renders AgendaList every 200 px.
- Bench: `buildAgendaGroups` about 2.6-3.2 µs per occurrence on desktop (7.8 ms at 3,000, 28.8 ms at 10,000). Phone est. 30-120 ms per day scrolled for the rebuild alone, plus the App tree render and a few hundred header components.
- Change: memoize `agendaOccs` on what it reads (the occurrence list, the held stretch's start and end, the cutoff, show-past, sort); the held stretch does not change while scrolling within it, so the list keeps its identity. Draw a group's heading only when the group is within the rendered band, as rows already are (its `section` keeps its height). Optional, later: move the agenda's window and band effects into a small component so `agendaTop` leaves App's selector.
- Expected benefit: removes one full agenda rebuild per day scrolled (phone est. 30-120 ms each) and cuts each 200 px render from all headers to the dozen on screen. Confidence high on the mechanism.
- Risk: low. The scroll restore keyed on `groups` (`AgendaList.js:345-386`) runs less often, which is what it wants. Off-screen headings disappearing from the DOM changes nothing a person sees (rows are already absent there) but does drop them from find-in-page; worth a sentence in the change. Tests: smoke.mjs agenda cases ("agenda washes stop at their independent render cap" and the agenda month-label cases), viewchecks month/agenda navigation.
- Measure: Performance recording on the phone, Agenda view, slow scroll through two weeks; count `buildAgendaGroups` calls and App renders.

### F6. The calendar's first window waits for eight unrelated requests

- Where: `main.js:41-52` and `session.js:14-27`. Boot is /me, then `Promise.all` of ten items (calendars, views, config, people, system health, plugins, review count, updates, and two setting writes when the zone changed), then `booted: true`, then the calendar mounts, measures, and only then asks for its window (`MonthGrid.js:647-651` → `loadWindow`).
- What: three server stages in series before events can appear: /me, the slowest of eight, then /events. On shared PHP hosting each of the eight is a full PHP start and database connection, and a host with few PHP workers queues them.
- Change: as soon as /me lands (settings known: view, week start), ask for the stretch around the anchor through `ensureRangeLoaded` (not `loadWindow`, so the view's window stays the view's); `ensure` already joins a later request for the same months to the one in flight, so the grid's own load waits on it rather than asking twice. Optionally set `booted` once /me and /calendars are in, letting people, views, plugins, health and updates land into a drawn app.
- Expected benefit: /events overlaps the eight instead of following them; time to a drawn month on a warm start shortens by roughly the slowest of the eight, guessed at 150-400 ms on the phone. Confidence low-medium until the waterfall is read.
- Risk: medium for the booted part (People in the sidebar and plugin bands appear a moment later; they must read as loading, not empty, per the partial-data principle). Low for the prefetch part alone. Tests: smoke.mjs boot and window cases ("etag:" cases exercise the window path), viewchecks first-open checks.
- Measure: on the phone with a warm service worker, DevTools Network waterfall of a start; note when /me ends, when the last of the eight ends, and when /events starts and ends.

### F7. Every app file at start is looked up with ignoreSearch

- Where: `web/sw.js` `cacheFirst`: `caches.match(request, { ignoreSearch: true })`, for each of about 170 files on every start (every module carries `?v=`), and the document.
- What: the shell is stored under paths without a query (`SHELL` entries), so an exact lookup of the path would hit. As far as I know Chromium's Cache Storage takes its slow path for `ignoreSearch` (it cannot use the exact-URL key and enumerates entries); with about 170 entries and about 170 lookups that is up to about 29,000 entry reads at each start. I have not verified this on the phone; treat it as a hypothesis.
- Change: in the worker, open `SHELL_CACHE` and match the request's URL with the query stripped, exactly; fall back to the network as now.
- Expected benefit: unknown; from nothing to tens or a few hundred ms of a warm start on Android. Confidence low-medium.
- Risk: low (keys already have no query). Offline behaviour and the version swap are unchanged. Covered by `web/tests/static.mjs` where it reads sw.js, and by a manual offline open.
- Measure: in the phone's remote console after a warm start, `performance.getEntriesByType('resource').filter(e => e.name.includes('/assets/src/')).map(e => e.responseStart - e.workerStart)`; or in the worker's console, time 170 `caches.match(u, {ignoreSearch: true})` against 170 exact `cache.match(path)`.

### F8. Bundle size and splitting

- Where: the whole graph is static (AppShell.php `graph()`; "a dynamic import() is deferred on purpose" in its docblock), so every page (Settings 64 kB, Plugins, Activity, People, Organize, Filters, Review, Welcome, Move to Google...) is fetched, parsed and evaluated at start.
- What it costs: network only on a first visit and once per deploy per device (the worker refetches the full shell with `cache: 'reload'`, about 2.1 MB raw, roughly 700 kB compressed, since the static ETag is size+mtime and could falsely match). On a warm start the files come from the worker's cache and Chrome keeps compiled code for scripts the worker cached at install, so what remains is evaluation and whatever compile the code cache doesn't cover.
- Change: none now. Minifying would need a build step the project chose not to have, and saves mostly first-visit bytes; splitting rarely used pages needs AppShell to put dynamically imported modules in the shell too, or offline opens of those pages break.
- Expected benefit: unknown until measured; likely tens of ms of evaluate time on the phone, not hundreds. Confidence low.
- Measure: Performance recording of a warm start on the phone; sum "Compile Code", "Compile Module" and "Evaluate Module" before first paint. Only if that passes about 150 ms is splitting worth a design.

### F9. Smaller items checked and found not to matter

- The minute tick (`App.js:243-251`) re-renders the whole tree once a minute (past dimming, now line). Once a minute is fine.
- `useStore` runs every subscriber's selector on every `set()` (about 60 mounted); microseconds per set.
- The on-page filter re-computes `dimSet` and redraws rows per keystroke; a few ms at 10k; a debounce would be a timed wait, which the project rules out.
- Week view (infinite TimeGrid) runs a whole-cache pass per column scrolled (`TimeGrid.js:427`, deps include `days`); about 12 ms at 10k on desktop today, about 1-2 ms after F2. No separate change needed.
- The worker stores a copy of every cacheable API answer, including each distinct `/search?q=` (sw.js `networkFirstWithTimeout`), until the next deploy or sign-out. This runs in the worker, off the page's thread; storage grows slowly. Not worth a change.
- `mergeWindow` scans the whole cache with two date parses per occurrence (`store.js:430-436`): 2.4 ms at 10k, 6 ms at 25k on desktop. F2 covers it.
- app.css is one 291 kB file with 21 `:has()` rules, including `.bc-app:has(.bc-evpanel)` on the root. Style recalculation cost during scrolling is unmeasured and probably small; look at "Recalculate Style" in the F3 recording before doing anything.
- The cache ceiling (25,000, `store.js:295`) bounds every whole-cache pass. After F2 a pass costs about 0.2-0.3 µs per occurrence, so even a full cache is single-digit ms on desktop. Lowering the ceiling trades refetches (a pruned stretch loses its tag and comes back in full) for that; only worth it if `state.occ.size` in real sessions sits near the ceiling and F2 isn't enough.

## Ranking

Worth doing now (cheap, clear cause in this code, low risk):

1. F1, Day view all-day pass: one pass instead of 29. Biggest single cost found (bench 338 ms at 10k on desktop per trigger).
2. F2, span and instants once per occurrence object: a 5-10x cut on every whole-cache pass, one function changed, every reader benefits.
3. F3, month grid renders per row set, not per scroll offset, plus a memoized row: the most frequent action in the app, likely the main scroll cost on the phone.
4. F4 (openId part only): drop `openId` from the occurrence memo; two of three full rebuilds per event open and close go away.
5. F5, agenda: memoize `agendaOccs` and draw only on-screen headings.

Worth measuring first:

6. F6, start the window fetch with the boot requests: needs the phone's network waterfall to know the gain.
7. F7, exact shell lookups in the worker: one quick timing in the worker's console decides it; if the slow path shows, it becomes a five-line change.
8. F4 (record-arrival part): skipping the `occVersion` bump when no drawn field changed; worth it only if, after F2 and F3, an open still shows a long task.
9. F8, splitting rarely used pages: only if warm-start evaluate time on the phone passes about 150 ms.

Not worth it:

- A build step or minification: repeat starts are served from the worker's cache; the saving is first-visit and per-deploy bytes, against a deliberate no-build design.
- Revalidating the shell on deploy instead of `cache: 'reload'`: the static ETag (size+mtime) can falsely match a replaced file, the exact failure the AppShell signature guards against; the saving is one download per deploy.
- Lowering the cache ceiling, debouncing the filter, throttling the minute tick, trimming the worker's API cache: costs found to be small, or the fix conflicts with a project rule (no timed waits), see F9.

## What I could not determine without measurement

- Real `state.occ.size` in a normal session (sets the size of F2, F4 and F9).
- Time per MonthGrid and WeekRow render with chips on the phone, and how many frames a fling drops today (F3); fakedom has no layout, so the node render timing undercounts chips.
- The phone-to-desktop CPU ratio behind every "phone est." figure.
- The boot waterfall: how long the eight boot requests take on the deployed host and how much of /events could overlap them (F6).
- Whether Chromium's `ignoreSearch` lookups are as slow as suspected on the phone (F7).
- Warm-start compile and evaluate time for the 154 modules, and whether Chrome's code cache covers the worker-cached modules (F8).
- Style recalculation cost of app.css during scrolls (F9).
- Size and server time of a cold month-view `/events` answer on a dense account (the other agent's side; it bounds time to a usable calendar more than anything client-side may).
