# Lessons from the Discourse Events Calendar Plugin (reference review, 2026-07-30)

Distilled from a deep review of `/Users/oshyan/Projects/Coding/EdgeTech/discourse-events-calendar` and its docs corpus. This is the transferable knowledge base for bettercal-ui and the Better-Cal backend. File/line references point into the plugin repo.

## Why FullCalendar was rejected (avoid the same traps)

Source: `docs/fullcalendar-vs-custom-evaluation-2026-04-30.md` (a reversal of the pro-FullCalendar PRD position, reached by actually building it; FullCalendar was fully deleted 2026-05-24, commit b45deda).

1. The Premium licensing wall sat on exactly the differentiating feature (resource/venue views). Never adopt an engine whose paid tier covers your differentiator.
2. The default visual model was generic; the restyling tax exceeded the engine savings.
3. Its time-grid overlap layout wasted horizontal space and needed tuning anyway.
4. Its list view was structurally wrong and reset scroll position; list/agenda is the view users actually live in and generic engines do it worst.
5. Popover UX, loading-indicator placement, and scroll preservation were all custom work regardless.

What FullCalendar was genuinely ahead on (build these carefully ourselves): date navigation/view-state sync, month-cell packing, time-grid overlap mechanics, DST/date-math edge cases.

## Conventions that prevent whole bug classes

- **Local-date string keys**: all internal day keys are local-time `YYYY-MM-DD` strings, never Date objects, never ISO-with-Z. Build via getFullYear/getMonth/getDate; parse via `new Date(y, m-1, d)`. `new Date("2026-05-01")` parses UTC and shifts a day west of Greenwich.
- **Occurrence identity is frozen**: `eventId:occurrenceStartUtc`. ICS UIDs and anything RSVP-like depend on it byte-for-byte. DST-correct wall-clock recurrence must be right from day one because fixing it later changes identities (the plugin deliberately preserved a DST drift bug rather than orphan identities; see `docs/recurrence-engine-refactor.md`, 409 lines, required reading).
- **One recurrence engine**, one horizon cap enforced in one place. The plugin shipped two divergent engines (materializer + live expander), neither a superset, and paid with production bugs.
- **Bound recurrence at the source** was the plugin's fix (12-week default finish date) because it materialized occurrence rows ahead of time. NOT adopted in Better-Cal: we expand lazily per query window with an instance cap, so unbounded RRULEs are safe, and personal calendars genuinely need perpetual events (birthdays, standing weekly). The transferable lesson is only: never let expansion be unbounded in any single query.
- **Request-race guard**: monotonic id per fetch family (`const id = ++counter; ... if (id !== counter) return;`). The plugin needed five of these.
- **State-signature dedupe**: join all filter/range dimensions into one string; skip refetch when it matches last-loaded or in-flight.
- **Timezone normalization**: alias PDT/PST/etc to IANA zones, validate via Intl probe, cascade to UTC. Real data contains abbreviations.

## Layout algorithms (portable, from `controllers/discourse-events-calendar.js`)

- **Time-grid overlap packer** (`:753-855`): clamp to visible window → enforce 15-min interaction floor and separate 30-min minimum VISUAL height (short events stay clickable) → sort start asc, end desc (longest first) → cluster via running end high-water mark (using inflated visual end in collision detection) → greedy lane reuse (first lane with end <= start) → emit percentage top/height and lane-based left/width. No column expansion into free space; predictable but leaves whitespace, extend if desired.
- **Block geometry** (`:670-679`), pointer→minute snap (`:652-668`), drag-draft normalization with inverted-drag handling (`:687-732`).
- **Day fan-out**: multi-day events pushed into every day bucket they overlap (`:1798-1854`). NOTE: the plugin renders multi-day events as per-day chips with NO spanning bars; Better-Cal is building true week-row segment spanning, which goes beyond the reference.
- **All-day heuristic** (`:734-751`): all_day flag OR >=23h fully covering the day.
- **Deterministic per-day sort**: [max(start, dayStart), start desc-end, title]; client and server comparators must match or overflow counts desync.

## Performance patterns

- **Server-side month compaction** (the headline win): in month mode the server sends only 4 events/day plus true per-day overflow counts; clicking "+N" does a day-scoped fetch merged by identity. Adopt as a first-class API mode when feed density warrants (post-M3 follow-up; our virtualized window loading covers M1-3).
- Hour/quarter-hour lines painted with CSS gradients, zero DOM.
- `content-visibility: auto` + `contain-intrinsic-size` on off-screen groups, behind @supports.
- 700ms search debounce proved necessary in practice.
- Perf harness metric set worth reimplementing (Playwright): totalDomNodes, eventNodes, per-view time-to-selector, longtasks.

## Scrolling and CSS traps

- Any ancestor that becomes a scroll container (including `overflow-x: hidden`) silently kills descendant `position: sticky`. Use `overflow-x: clip` to contain horizontal scrollers without creating a scroll container.
- `overscroll-behavior: contain` on inner scrollers (prevents back-swipe and scroll chaining); `scrollbar-gutter: stable` so grids don't jump.
- `touch-action: pan-x pan-y` on drag-createable surfaces so native panning coexists with pointer handlers.
- Scroll-into-view-once on mount: stamp a signature into the element's dataset so re-renders don't stomp the user's scroll position.

## Interaction patterns

- Preview popover: prefer right of anchor, flip on overflow, clamp to viewport; keyed show/hide timers (~220ms show / ~320ms hide) so stale timers can't fire for the wrong event; tag the popover with its source (hover vs pinned vs overflow) so dismissal rules differ per origin; bottom sheet on mobile; ALWAYS tear down document-level listeners on view unmount, not just popover close (the plugin leaked listeners on navigate-away).
- Touch: tap = pinned preview; long-press (~560ms) = navigate; >10px movement cancels; suppress synthetic click after touch; suppress contextmenu; drag-create bails on touch pointerType.
- Respect modifier keys: bail from custom click handling on meta/ctrl/shift/alt/middle-click; events are real anchors.
- Two-step drag-create confirmation (draft block with Create/Cancel buttons instead of instantly opening an editor) was an explicit, tested product decision.
- Mobile month cells: event pills collapse to dots + "+N" text chip at <=600px; proven density signal.
- Swipe left/right on an open event popover pages through adjacent events ("3 of 12"), 48px threshold. Nice-to-have for Better-Cal M4+.

## Anti-patterns observed (do not replicate)

- 7,429-line controller / 9,034-line stylesheet: split date/layout utils, view-model derivation, and interaction handling from day one.
- Location modeled three ways simultaneously (free text + FK + room.location_record) with OR-joins everywhere: decide the location model once (Better-Cal: free text + optional lat/lng now, structured places later).
- A "safe formatting wrapper" shipped as a silent no-op fallback and nobody noticed hardcoded English at 16+ call sites: fallbacks must be loud in dev.
- Vestigial renderer flags, orphan CSS clusters, duplicate getters where the second silently wins.
