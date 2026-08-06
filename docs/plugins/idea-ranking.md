# Plugin ideas: ranking

Every idea from the GH #4 thread, bucketed by feasibility and cost/benefit. Grounding for each call is in [idea-evaluation.md](idea-evaluation.md); capability numbers (C1-C24) refer to its inventory. Ideas that decompose are split across buckets deliberately, because "Visit Planner" the wishlist and "Visit Planner" the booking robot are different projects wearing one name.

## Must Build

The v1 core plus the demos that prove it. Everything here generalizes code already running in production.

| Idea | Why it must be built |
|---|---|
| Plugin core v1 (registry, worker jobs with budgets, namespaced storage, declarative settings at plugin and calendar scope, materialized-event calendars, overlay ranges, audit warnings, policied HTTP client, ops page, uninstall semantics) | This is the actual deliverable of #4; every seam is a generalization of the feed, availability, settings, or job-queue machinery, and the policied HTTP client retires the open BC-01 SSRF class for core code as a side effect |
| Weather provider | The original ask; exercises materialized events, per-calendar location settings, scheduled fetch, and decorations on a free keyless API (Open-Meteo) |
| Tides provider | The original ask's second half; proves the overlay-range output class and that the provider contract is not secretly weather-shaped (NOAA CO-OPS, free, keyless) |
| Calendar Lint (audit form) | Third output class (warnings, no generated objects) at near-zero cost; immediately useful rules like distant back-to-back events need nothing but haversine |
| Chip decorations, declarative (icon/emoji + color) | Directly answers the original issue's "fancier" items without opening the arbitrary-rendering door |

## Promising

Real value, moderate cost, no dangerous dependencies. Build when pulled by actual use, roughly in this order.

| Idea | Why it is promising and what gates it |
|---|---|
| Travel-time / leave-by layer | Highest daily utility per unit cost of any idea in the thread; needs only ranges + warnings plus a haversine heuristic, with real routing as an optional plugin-internal upgrade (v2) |
| Per-event plugin data + event-scope controls (C7/C8) | The backbone for enrichment, booking records, and visit modes; safe because it rides the detail fetch, never the window payload (v2) |
| Event automation rules + run-grouped undo (C10) | Deterministic import routing/tagging with dry-run and one-click run rollback; the undo requirement is what makes it trustworthy (v2) |
| Custom-source plugins (the honest residue of Event Aggregator) | Fetch+parse in a worker job feeding the existing materialization path; scraping brittleness stays the author's problem (v2) |
| Time accounting page | Read-only aggregation the DB handles trivially; captures the SkedPal/GCal-Insights value without any budget-enforcement solver (v2) |
| Additional environment providers (AQI, surf, moon, daylight) | Marginal cost near zero once Weather/Tides land; good first-community-plugin fodder (v2, or community) |
| Plugin notifications (C17) | Thin permissioned wrapper over existing push/email; unlocks severe-weather and leave-by alerts (v2) |
| Animated decoration presets | A few canned CSS effects (pulse, shimmer) close the "animated" ask safely (v2) |
| AI Trip Planner as proposal consumer (#3) | Already on the roadmap; builds the proposal seam (C11) once, for a real consumer, with atomic accept into a Trip (v3) |
| Visit Intents v1 (wishlist, hours-aware opportunity bands, link-only booking) | The strong core of the Place Visit Planner; needs v1 + C7 + a management surface, and Google Places hours data is sufficient for it (v3) |
| Trip booking records via email-ingest reconciliation | The ingest ladder already reads confirmation mail; attaching structured records to trips is incremental (v2/v3) |
| LLM access for plugins (C18) | Wiring over the existing gateway, permission-gated; needed by the planner anyway (v2/v3) |

## Questionable / needs justification

Not refused, but each needs either a proven consumer, a missing external precondition, or a demand signal before it earns its complexity.

| Idea | What it needs to justify itself |
|---|---|
| Generalized proposal framework | A second consumer beyond the Trip Planner; until then C11 stays purpose-built |
| Capability registry / cross-plugin provider queries (C12/C13) | Two real consumers of the same provider data; the informal KV-convention version covers experimentation first |
| Inventory watcher (reservation/ticket sniping) | A legitimate data source; the marquee reservation platforms have no public availability APIs, so today this is scraping wearing a trench coat |
| Conditional visits, visit chains, errand batching | Each reintroduces solver-lite placement or cross-plugin queries; park until Visit Intents v1 demonstrates demand |
| Task completion write-back | Per-provider auth and conflict semantics for an unproven need; read-only task display already works via feeds |
| Live trip monitoring (flights, gates) | Paid APIs plus polling budgets plus notification fatigue; booking records via email ingest deliver most of the value free |
| Context-activated saved views | The achievable time-of-day version is a small settings feature, not a plugin capability; the location/Focus version is not possible in a self-hosted web app |
| Event preparation briefs | Nice composition of existing data but not system-shaping; fine as a community plugin once v2 seams exist |
| Planning rituals (Sunsama-style) | Same: a guided page over time-accounting queries, community-buildable, not core |
| Custom full plugin pages (C15) | Real management UIs (Visit Intents) will eventually want this; the moment it is truly needed is the moment to design it, because it drags plugin frontend code with it |

## Not Now / Don't Build

Refused explicitly so the architecture never has to accommodate them. Each is individually larger than the entire v1 core, carries the worst failure modes, or contradicts the product's stated scope.

| Idea | Why it is refused |
|---|---|
| Auto-scheduling solver family (flexible-commitment auto-placement, Reclaim-style habits and time defense, SkedPal Time Maps/Zones as enforcement, route optimization) | A product domain that funded companies exist to solve; it would dominate the complexity budget permanently, and the valuable fragment (propose, preview, accept) survives in the proposal contract |
| Booking-write / manage adapters | External side effects with money attached, per-provider auth, and no stable public APIs at the providers that matter; link-only and email reconciliation deliver the safe majority of the value |
| Wearable / personal capacity ingest | New ingest domain plus privacy surface, and its useful outputs are solver behaviors that are already refused |
| SavvyCal-style recipient overlay | Requires a second party; the product is explicitly single-user |
| Unbundling People into a removable plugin | Quick-add, search, the editor, and the palette depend on it; conversion is all cost and no user-visible benefit, so People stays bundled and its seams become the generic machinery instead |
| Arbitrary plugin UI code (JS bundles, iframes, sandboxing) | The one capability that converts every other risk from "worker misbehaves" to "hostile code in the page"; declarative rendering plus sanitized text covers the actual demos, and the thread's own constraint says declarative first |
| In-app scraping engine | Brittleness and legal gray zones concentrated inside the calendar; companion services emitting feeds, or custom-source plugins, carry it outside |
| Per-occurrence server hooks, plugin data on the window payload | Banned by measured arithmetic: 167ms window and 793 bytes/occurrence do not survive per-occurrence anything |
