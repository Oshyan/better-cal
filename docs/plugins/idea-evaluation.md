# Plugin ideas: evaluation against the existing system

Source material: GH #4 body and all four comments, including the extended survey (Visit Intents, external product concepts) and the closing directive: evaluate performance, data model, and complexity implications for every capability, and defer or reconsider anything that significantly risks them.

Scope frame that governs everything here: Better-Cal is a replacement for one person's Google Calendar, self-hosted, no framework, no build step. It is not an enterprise product and does not want to become a scheduling suite. The issue title says "simple plug-in system" and that adjective is load-bearing.

## 1. What production code already proves

The thread's own observation holds up under inspection: most of the plugin system already exists in hardcoded single-consumer form. These are the seams, verified against current source:

| Seam | Where it lives today | What it proves |
|---|---|---|
| Materialize external data as events | `Feeds::sync` upserts by `(calendar_id, uid)`, deletes disappeared uids, records ChangeLog entries, tags activity with source `feed`, all in one transaction | The entire "plugin generates calendar items" shape: dedup, refresh, disappearance, undo journal, CalDAV coherence |
| Non-event presence on the calendar | Availability spans: `/availability` window endpoint, client synthesizes pseudo-occurrences with `instanceId: 'avail:' + id` and `isContainer: true`, drawn by the existing band machinery | Overlay ranges render fine through the normal pipeline; a handful of spans per window costs nothing measurable |
| Click routing away from the event editor | `onOpenEvent` intercepts the `avail:` prefix and routes to the People page | Prefix-dispatched click routing works; it just needs a registry instead of one hardcoded string |
| Own data + own management UI | `people` and `availability` tables, People page on `PageShell` | Plugin-owned objects with a dedicated UI coexist with the calendar without touching the event model |
| Sidebar visibility control | People section with All/None/Custom, per-person toggles | Per-layer visibility UI generalizes |
| Scheduled background work | Worker with `JobQueue` (enqueue, claim, markFailed with backoff hooks), `bc_enqueue_if_stale` cadences from 50s to 1d, `GET_LOCK` single-runner | The execution substrate for plugin jobs exists; what is missing is registration, budgets, and health capture |
| Per-calendar config | `calendars.settings_json`, per-calendar gear panel (`CalendarSettings.js`) | Calendar-scope plugin settings have a storage location and a UI home |
| Declarative field inputs | Editor and settings already compose text, select, number, toggle; `PlaceInput` (geocoding via `PlaceSearch`/`Geocode`), `PeopleInput` | Every field type a declarative settings schema needs already exists as a component |
| Sanitized untrusted text | `Sanitize.php` allowlist, applied server and client side to descriptions | The pipeline plugin-authored text must ride |
| Action journal | `mutations` table with `source` (ActivityContext takes arbitrary strings), summaries, snapshots, undo, and now log-only `refuse` entries | Plugin actions can be attributed and audited today; per-run grouped undo is the only gap |
| External identity for automation | Bearer tokens + MCP wrapper | Agents already mutate the calendar through the API; plugins are a second automation citizen, not the first |
| LLM access | `LlmGateway` (quick-add tier 3, prompt filters, ranking) | A permission-gated pass-through is wiring, not construction |

Two hot-path facts from `docs/benchmarking.md` constrain everything below: `Events::window` is 167ms median for a 5-month window of 3,585 occurrences, and the payload is 793 bytes per occurrence with #15 aiming to cut it. The thread's constraints (no per-occurrence hooks, no plugin data on the window response) are treated as hard rules, not preferences.

## 2. Capability inventory

The union of everything the idea list would need, with what exists, what is missing, and a verdict. "Verdict" weighs cost against how many ideas the capability unlocks.

| # | Capability | Exists today | Missing | Perf risk | Complexity | Verdict |
|---|---|---|---|---|---|---|
| C1 | Registry + lifecycle (install, enable, disable, uninstall) | Nothing | Manifest scan, `plugins` table, ops page | None | Low | Core v1 |
| C2 | Scheduled jobs in worker | JobQueue, worker loop, backoff | Per-plugin registration, time budget, failure circuit breaker, health capture | Worker contention (single loop; a hung plugin starves mail ingest and reminders) | Low-medium | Core v1, with budget + auto-disable mandatory |
| C3 | Plugin-owned storage | Db layer | Namespaced KV table (`plugin_kv`), uninstall semantics | None at single-user scale | Low | Core v1 |
| C4 | Materialized events in plugin-owned calendars | `Feeds::sync` is 90% of it | Calendar ownership by plugin, write path restricted to owned calendars | None: events are events, already measured | Low-medium | Core v1 |
| C5 | Overlay ranges (non-event bands) | Availability seam, hardcoded | `plugin_ranges` table written by jobs, one generic read endpoint, client provider registry replacing the `avail:` special case | None if job-written and capped; plugin code never runs on the request path | Medium (the refactor touches App.js/Sidebar and needs the month-navigation lesson applied: retest everything that reasons about layout) | Core v1 |
| C6 | Declarative settings UI (plugin scope + calendar scope) | settings_json both levels, all field components | Schema renderer, server-side validation | None | Low-medium | Core v1 |
| C7 | Per-event plugin data (keyed values) | Tags join tables as pattern, detail-fetch path direction in #15 | `event_plugin_data` table, detail-fetch merge, uninstall semantics | None if detail-path only; catastrophic if it ever rides the window response | Medium | v2 |
| C8 | Event-scope declarative controls (popover/editor sections) | Editor composition | Depends on C7 + schema renderer reuse | None | Medium | v2 |
| C9 | Warnings (audit + editor-inline) | Toasts, notifications infra | `plugin_warnings` table written by worker audits; v2 adds debounced editor-inline lint | None (audit is worker-side; editor call is on-demand, single user) | Low-medium | Audit in v1, inline in v2 |
| C10 | Run-grouped atomic undo | `mutations.details_json` can carry a run id | Run id convention, reverse-order batch undo endpoint | None | Medium | v2, paired with automation rules |
| C11 | Proposal objects (preview, accept, reject, regenerate, atomic materialize) | Nothing; Trips give the container to materialize into | New tables, proposal UI, atomic accept | None | Medium-large | v3, built once, for the Trip Planner (#3), not as a generic framework first |
| C12 | Capability discovery / cross-plugin dependencies | Nothing | Interface indirection, "provides/requires" resolution | None | Medium, but speculative | Deferred until two real consumers exist |
| C13 | Queryable provider time-series (weather data other plugins can read) | Nothing | Storage convention + query contract | None | Medium, speculative | Deferred; plugin KV with a documented key convention covers the informal version |
| C14 | Outbound HTTP with SSRF policy | curl in Feeds/Geocode, but BC-01/BC-02 are open findings | Central policied client: deny private/link-local/metadata ranges post-DNS, size + time caps, per-plugin request budget | None | Medium | Core v1, and it retires the BC-01 class for core code too |
| C15 | Custom full pages per plugin | PageShell, route switch | Arbitrary page content means plugin frontend code | None | High relative to value | Deferred; v1 gives settings sections + ops page + sanitized info cards on range click |
| C16 | Chip decorations ("fancier, animated") | Chip styling system | Declarative decoration contract: allowlisted icon/emoji, color, a few canned CSS animation presets | None | Low | Icon/emoji/color in v1, animation presets v2 |
| C17 | Plugin-sent notifications | Reminders, Web Push, email sender | Permission + thin API | None | Low | v2 |
| C18 | LLM access | LlmGateway | Permission gate | Cost control, not perf | Low | v2, permission-gated |
| C19 | Travel-time estimation | Geocode, event locations | Routing provider (or haversine heuristic); entirely a plugin-level concern on top of C5 + C9 | None | Plugin-sized, not system-sized | Enables the travel plugin in v2 with zero new core seams |
| C20 | Booking-write adapters (reserve, cancel, manage) | Nothing | Per-provider auth, liability handling, confirmation UX; marquee providers (OpenTable, Resy) have no public availability/booking APIs | None | High, plus external-reality problems no code solves | Out of scope |
| C21 | Auto-scheduling solver (placement, budgets, defense, routes) | Nothing | A constraint solver plus the interaction model to make its output trustworthy | Solver runtime is the least of it | Very high; a product domain of its own (SkedPal, Reclaim, Motion are whole companies) | Out of scope |
| C22 | Wearable/capacity ingest | Nothing | New ingest domain, provider APIs, privacy handling | None | High | Out of scope |
| C23 | Arbitrary plugin UI code (JS bundles, iframes) | Nothing | Sandboxing, CSP, a plugin frontend toolchain; contradicts the no-build philosophy | None | Very high, and the thread's own constraint says declarative first | Out of scope for v1+, revisit only on demonstrated need |
| C24 | In-app scraping engine | Email ingest ladder exists for mail | Headless fetching, per-site extraction, brittleness management | Worker contention | High | Out of scope in-process; a companion service emitting a feed, or a C4 custom-source plugin, covers it |

The pattern in the verdicts: everything Core v1 is a generalization of code that already runs in production. Everything deferred or out of scope would be net-new construction, and the three largest (C20, C21, C23) are each individually bigger than the whole v1 core.

## 3. Idea-by-idea evaluation

Each idea from the thread, the capabilities it actually needs, and what that costs. Shapes follow the survey's taxonomy: data provider, bundled module, planner/orchestrator, extraction service, sync adapter.

### Environment: Weather, AQI, Tides, Surf (data providers)

Needs: C1-C6, C14, C16. That is exactly the v1 core, which is why they are the right demos. Weather materializes daily forecast events into a plugin-owned calendar (a title like "72°/55°" plus an icon decoration is just an event, zero schema impact). Tides contribute ranges (low-tide windows as bands) and prove the second output class. Per-calendar location uses the declarative location field backed by the existing geocoder. Open-Meteo (forecast, AQI, and marine/surf) and NOAA CO-OPS (US tides) are free and keyless, which matters for self-hosters. AQI and surf are then just additional providers on the identical contract, near-zero marginal system cost.

### People as an optional module (bundled module)

The survey's own caution is correct: quick-add ("NAME is away"), the editor, search, and the command palette all reach into People. Unbundling it buys nothing a user asked for and creates uninstall semantics for a feature nobody would uninstall. The valuable move is the opposite: keep People built in, and make its client-side seams (overlay synthesis, click routing, sidebar section) the first consumers of the generic machinery so the plugin path is exercised by production code from day one. Cost of conversion: high. Value: negative. Evaluated as: do not convert; generalize the seams instead.

### AI Trip Planner (planner/orchestrator)

Already its own issue (#3) and roadmap entry. What it needs from the plugin system: C11 proposals (persistent, preview, atomic accept into a Trip), C18 LLM access, C10 run-grouped undo, and read access to events/people/trips the API already exposes. The survey's key design point stands: first-class output is a proposal, never direct calendar writes. Verdict: the proposal seam gets built once, for this consumer, in v3. Generalizing proposals before a second consumer exists would be speculative structure.

### Event Aggregator (extraction service)

The survey already made the right call: extraction lives outside Better-Cal. Two supported paths, both cheap: a companion service that emits ICS (the existing feed system consumes it today, zero new code), or a v2 "custom source" plugin that runs fetch+parse in a worker job and hands normalized events to the C4 materialization path, inheriting dedup and disappearance semantics from the feed shape. The brittleness of scraping stays the plugin author's problem, not the system's. In-process scraping infrastructure (C24): rejected.

### Task manager integrations (sync adapter)

Decomposes into three very different things. Read-only display of tasks with dates: already works today via ICS feeds (the Todoist calendar in production arrived that way), or via a v2 custom-source plugin for providers without feeds; near-zero cost. Completion write-back: per-provider auth and conflict semantics, medium cost, unclear demand; questionable. Scheduling tasks into calendar blocks: that is the C21 solver; out of scope.

### Restaurant Visit Planner → Place Visit Planner (Visit Intents)

The strongest new product idea in the thread, and the most expensive one. Full formulation needs: C1-C6, C7 (booking records), C11 (opportunities as proposals), C12 (hours provider indirection), C15 (wishlist management page), C19 (travel feasibility), C20 (booking-write), plus People availability queries. That is nearly every capability including two rejected ones. But it decomposes honestly:

- Visit Intents v1 (wishlist with place identity via existing geocoding, desired duration and date window, hours-aware "open ∩ your free time" opportunity bands via C5, link-only booking per the survey's own capability ladder): needs v1 core + C7 + C15-lite. Feasible as the flagship v3 plugin.
- Inventory reading and booking-write: the marquee reservation platforms expose no public APIs (Google's reservation feeds are for merchant partners, not consumers), so "availability-read" is scraping in disguise and "booking-write" inherits C20's rejection. Out.
- The derived ideas (gap-filling, batching/routing, inventory watcher, conditional visits, chains): each quietly reintroduces C21 (solver/optimizer) or C13 (cross-plugin data query) or C20. Individually questionable, collectively a second product.

### Travel-time / leave-by layer

Needs only C5 (shadow ranges) + C9 (warnings) + C19 plugin-side. A haversine-plus-speed heuristic catches the "impossible transition" case with zero external dependencies; a routing provider (OSRM, external API) is an optional upgrade inside the plugin. No new core seams. High daily value for a calendar power user. Strong v2 candidate.

### Flexible commitments, SkedPal Time Maps/budgets, Reclaim habits, Morgen frames

All members of the C21 solver family. The survey's borrowed concepts are genuinely good product thinking, and the honest evaluation is that auto-placement with progressive time defense is the core competency of several funded companies and would dominate Better-Cal's complexity budget forever after. The non-solver fragment worth keeping: the pattern "user-authored structure, machine proposal, explicit acceptance" is exactly the C11 proposal contract, and time *accounting* (below) captures the budget-awareness value read-only. Auto-scheduling itself: out of scope, stated explicitly in the PRD so it does not creep back in.

### Calendar lint / constraint policies

Needs C9 plus declarative rule config via C6. Worker-side audit writes warnings; no generated objects, no hot path. Cheap, immediately useful (distant back-to-back locations via haversine, events outside preferred hours, suspected duplicates, timezone-shift smells), and it validates the third output class the reference set called for. v1 demo (audit form), editor-inline in v2.

### Event automation and enrichment (rules engine)

Needs C2, C6, C10 (the survey's run-ID-plus-atomic-undo requirement is the hard part and the right requirement). Deterministic rules on import/create: route to calendar, tag, add people, attach to trip, add reminders. Dry-run is mandatory. Medium cost, real value for feed-heavy usage. v2, paired with C10.

### Trip operations (bookings, monitoring)

Two halves. Structured booking records attached to trips: C7 data + email-ingest reconciliation (the ingest ladder already parses confirmation mail; reconciling to a trip is incremental) makes a nice v2/v3 plugin. Live flight/gate monitoring: paid flight-status APIs, polling budgets, notification fatigue; questionable until someone actually wants it.

### Event preparation briefs

Composition of things that mostly exist (linked people, location/route, description, reservation codes from ingest) fetched on open, optionally LLM-summarized. No new seams beyond v2's C7/C17/C18. Fine as a community plugin later; not system-shaping. Questionable as a build priority.

### Personal capacity and recovery

C22. New ingest domain, wearable provider APIs, privacy questions, and the useful actions on top of it are C21 solver behaviors. Out of scope.

### Context-activated saved views (Fantastical calendar sets)

Time-of-day activation of saved views is trivial (client-side rule on existing saved views). Location/Focus-mode signals are effectively unavailable to a self-hosted web app. The trivial version is a settings feature, not a plugin capability; the full version is impossible in this deployment model. Questionable, and not plugin-system-relevant.

### SavvyCal-style overlay

Requires a second party interacting with your calendar. Directly violates the single-user scope statement. Out.

### Time accounting (GCal Time Insights, Sunsama-style review)

Read-only aggregation over events/tags/people/trips, rendered on a page. No hot path (its queries run on demand over an 8,400-row table that full-scans in 10ms). Needs C15-lite for display. The budget-*enforcement* half stays out with C21; the reporting half is a cheap, likable v2 plugin. The Sunsama planning-ritual variant layers guided flow on the same queries; fine as a community plugin, not a priority.

## 4. Reading the whole list against the closing directive

Sorting every idea by which capabilities it pulls in produces a clean split:

- Ideas needing only C1-C6 + C9 + C14 + C16: weather, AQI, tides, surf, lint, travel-time. All cheap, all safe, all high-confidence.
- Ideas needing v2 additions (C7, C8, C10, C17, C18): automation rules, per-event enrichment, booking records, time accounting, custom sources, prep briefs. Moderate, each justifiable on its own when pulled.
- Ideas needing C11/C12/C13: trip planner, visit intents, conditional logic. Build C11 once for #3; defer C12/C13 until a second consumer proves the abstraction.
- Ideas needing C20/C21/C22/C23/C24: booking-write, all auto-scheduling, capacity, arbitrary UI, in-app scraping. Each is individually larger than the entire v1 core and each carries the highest failure modes (external side effects, solver trust, sandbox security). These are refused, not deferred, so the architecture is free to ignore them.

That last point is the practical payoff of this evaluation: by refusing the heavy families explicitly, v1 needs no sandbox, no solver, no proposal framework, no per-occurrence hooks, and no plugin frontend code, which is what keeps a "simple plug-in system" actually simple.
