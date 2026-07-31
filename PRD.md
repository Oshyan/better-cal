# Better-Cal: Self-Hosted Personal Calendar System

**Status:** Draft PRD v1, 2026-07-30
**Owner:** Oshyan
**Goal:** A self-hosted calendar that reaches parity with Google Calendar for personal use, then surpasses it in the areas that actually matter day to day: speed of input, speed of editing, event discovery, and decision support.

## 1. Vision

Google Calendar is a container for events. Better-Cal is a tool for deciding what to do with your time. It holds your own events, ingests every external event source you care about (Luma, Partiful, venue pages, community calendars, ICS feeds), and gives you fast, opinionated tools for triaging, planning, and rescheduling. It is self-hosted on a standard LAMP stack, standards-based where standards are adequate (ICS, CalDAV), and custom where they are not.

The underlying conviction, from the source notes: the hard part of a schedule is not recording events, it is choosing among them. Most calendar features here (ranking, trainable filters, saved planning modes, venue views) exist to reduce the cost of deciding.

## 2. Source Material

Assembled and deduplicated from audio note transcripts plus one written note:

- 2026-03-11 "My own calendar tool" (Easy Voice Recorder; the original note: infinite scroll, day expansion, folders and LLM filters, Google sync-back, day quality coloring, NL input, location autocomplete, travel planning, tides/weather/AQI generators)
- 2026-03-13 "Misc tool ideas" (Easy Voice Recorder; person-linked events without invitations, per-person availability tracking)
- 2026-05-30 "Calendar Tool Feature Ideas and UI Critiques" (day view value, recently-added surfacing, NL search idea, mobile event-detail priority)
- 2026-05-31 "Critique of Edge OS App and Calendar Features" (past-event filtering, list view performance, ICS subscription feeds, external RSVP links)
- 2026-07-10 "Ideas for a Custom Calendar Application" (the core note: saved views/modes, folders and tags, trainable filters, reschedule interactions, feed ingestion service, venue view, shared UI library)
- 2026-07-16 "Prioritizing Work on Hangs and Edge Citizens Apps" (create event from URL: Luma, Partiful, Eventbrite)
- 2026-07-22 "Indecision is the Core Problem, Not Scheduling" (decision support as design driver)
- Written note: "Fancier Calendar Items, Dynamic, Styling" (dynamic event content, weather integration, custom per-event styling)

**UI reference implementation:** the Discourse Events Calendar plugin at `/Users/oshyan/Projects/Coding/EdgeTech/discourse-events-calendar` (with `discourse-events-calendar-prd.md`, `discourse-events-calendar-implementation-guide.md`, and `discourse-event-and-calendar-plugin-evaluation.md` alongside it in EdgeTech). Its UI/UX was developed through extensive testing against a FullCalendar benchmark implementation that was evaluated and rejected. The language/framework will differ here, but its view layouts, interaction patterns, and the evaluation doc's findings are the starting point for `bettercal-ui` design decisions.

## 3. Product Principles

1. **Input and editing must be faster than Google Calendar.** Natural language creation, single-keystroke actions, drag interactions that do the right thing. Any edit reachable in one or two interactions.
2. **The calendar is a decision-support tool.** Ranking, filtering, and planning modes are first-class, not bolted on.
3. **Smart defaults over configuration.** Past events filtered out automatically. Sensible views out of the box. Options exist but are not required.
4. **Performance is a feature.** No unbounded list loads (the EdgeOS failure). Windowed queries, virtualized rendering, lazy loading everywhere. Interactions render in under 100ms.
5. **Standards first, custom when justified.** ICS and CalDAV for interop. Custom formats and fields only where the standard is genuinely inadequate (dynamic content, styling), with graceful degradation when exported.
6. **Own the UI layer.** FullCalendar was evaluated in the Discourse plugin work and discarded. Better-Cal gets a custom rendering engine, structured as a standalone library that can eventually be shared with the Discourse calendar plugin.
7. **Desktop and mobile are both primary.** Mobile is not a degraded afterthought; event details, triage, and quick edits must be excellent on a phone.

## 4. Architecture and Stack

### 4.1 Backend

- PHP 8.3+ on the existing LAMP server. No Docker required.
- MariaDB/MySQL as the primary store, with FULLTEXT indexes for search. (SQLite acceptable for dev.)
- API-first: everything the UI does goes through a JSON REST API. This is also the agent/automation surface and the future mobile-app surface.
- Composer dependencies (server-side only, no runtime build complexity): `sabre/vobject` for ICS parse/serialize, `sabre/dav` for the CalDAV server (Phase 2), a recurrence library (`simshaun/recurr` or sabre's own RRULE iteration) for expansion.
- Background work (feed polling, dynamic content refresh, LLM jobs) via cron-driven PHP workers. No daemons required; a per-minute cron dispatcher with a jobs table is enough and is fully LAMP-portable.
- LLM calls (NL parsing, NL search, ranking assist) go through a single internal `LlmGateway` class with pluggable providers: Anthropic API, Gemini, or a local/CLI backend. Cheap fast models are sufficient for parsing tasks. Every feature using the LLM must have a deterministic fallback.

### 4.2 Frontend

- Single-page app in modern vanilla JS (ES modules) with a thin reactive helper (Preact + HTM or lit-html, no build step required; esbuild optional for production bundling). No heavy framework.
- **`bettercal-ui`**: the custom calendar rendering and interaction library, developed as a distinct module inside the repo with no app-specific imports, so it can later be extracted and shared with the Discourse plugin. It owns: grid layout, event placement and overlap resolution, virtualized infinite scroll, drag/resize/reschedule interactions, and view definitions.
- PWA from day one: manifest, service worker, installable, cached shell, web push notifications (Phase 2).
- CSS custom properties for theming; light and dark.

### 4.3 Data Model (core entities)

- **Calendar**: name, color, type (local | subscribed), source config (feed URL, poll interval, health status), default styling.
- **Folder**: named grouping; a calendar can belong to multiple folders (n:m).
- **Tag**: freeform labels applicable to calendars and to individual events.
- **Event**: standard iCal fields (UID, title, times with timezone, all-day flag, RRULE recurrence, location, description, URL, alarms), plus Better-Cal extensions: styling block, dynamic-provider binding, attendance status, score, source metadata, created/updated timestamps for recency features.
- **View (saved)**: named snapshot of enabled calendars/folders/tags, active filters, view type and zoom, sort. See 5.4.
- **Filter**: rule attached at global, folder, or calendar scope; types: structured (field match), regex, and LLM (trainable). Recursion is prevented by validation.
- **FeedbackSignal**: per-event thumbs up/down/hide used to train LLM filters and ranking.
- **Subscription (outbound)**: generated ICS feed with its scope (all events, one calendar, or a saved search), token URL, and a human-readable description embedded in the feed.
- **Person**: lightweight contact (name, optional link to a contacts source), associable with events (n:m) and with availability sources. No invitation semantics.

## 5. Feature Specification

### Phase 1: Core (parity target)

The goal of Phase 1 is that Google Calendar can be abandoned for daily personal use.

**5.1 Views**

- Month, week, multi-week (2 and 3 week), day, and agenda/list views. The multi-week view is the flagship default: the notes identify 2 to 3 weeks as the ideal planning horizon, and the original note observes that full-month views are mostly a workaround for calendars that cannot scroll.
- **Smooth infinite vertical scroll** through time in month/multi-week views: no month-page jumps; month names render in a fixed gutter on the left as you scroll, month boundaries are visible but continuous, and events can be dragged across month boundaries in one motion. Windowed fetch, virtualized DOM; never load unbounded ranges. This is the original note's founding feature ("I don't know why nobody's ever implemented this").
- **On-demand day expansion**: any day with overflow can be expanded in place without leaving the current view. Hover (desktop) or an expand affordance grows the cell to show all events; modifier-click (or hotkey) opens a lightbox agenda of that day. Events can be dragged out of the expanded container, which auto-collapses so the event can be dropped on another day in the same gesture. Instant single-day inspection without navigating to day view.
- Day view renders overlapping events side by side so partial-overlap tradeoffs are visible at a glance ("attend 75% of this, then jump to that").
- Agenda view hides past events by default (toggle to show); all views load lazily.
- Recency surfacing: events added within a configurable window (default 48h) get a subtle "new" treatment (outline or pill); agenda view supports sort-by-recently-added and a recency filter.

**5.2 Event creation and editing (the speed centerpiece)**

- **Natural language quick-add**: a single always-available input (hotkey: `c` or `/`) that accepts "Dinner with Sam next Thursday 7pm at Zuni" and creates the event. LLM-parsed with a deterministic chrono-style parser fallback; parse preview shown inline before commit; sub-second round trip. NL parsing also works inside the title field of the normal editor.
- **Location autocomplete**: the location field autocompletes against a places API (Google Places, or Nominatim/Photon for a free tier) and stores the resolved name plus address. The original note singles out Google Calendar's absence of this as "insane".
- Click-drag on any grid to create; single click for default-duration event with inline title editing (no modal for the common case).
- Drag to move; drag either end of any event, including multi-day events in month/multi-week views, to extend or contract it in place. This is an explicit fix for Google Calendar's forced length preservation.
- Duration lock: when editing dates in the detail editor, a small lock toggle (default on) preserves duration; unlocked, each end edits independently.
- Inline editing of title, time, and calendar from the event popover; full detail editor one click deeper.
- Undo for every mutation (toast with undo, plus a mutation log).
- Keyboard-first: arrow navigation, `e` edit, `d` delete, `r` reschedule, `/` search, `v` cycle views.
- Full recurrence support (RRULE), including "this event / this and following / all" edit semantics.

**5.3 Search**

- Instant full-text search over title, description, and location (MariaDB FULLTEXT), with filters for calendar, tag, date range, and attendance status. Results in under 100ms for tens of thousands of events. Historical search across all past events is first-class, not an afterthought.
- **On-page dynamic filter**: a type-to-filter box that live-dims/hides non-matching events in the current view without leaving it, distinct from full search.
- **Natural language search** (may slip to Phase 2): "events related to health added in the last week" is LLM-translated into a structured query, shown as editable filter chips so the translation is transparent and correctable.

**5.4 Calendars, folders, tags, filters**

- Multiple calendars with colors; folders group calendars (a calendar can live in several folders); tags apply to calendars and events (e.g. life / work / travel categories).
- Sidebar toggles for calendars and whole folders; global filter chips that apply across everything and can be toggled on/off quickly.
- Structured, regex, and keyword filters attachable at global, folder, or calendar scope.
- **LLM prompt filters**: a filter can be a freeform prompt describing what you want ("dance and live music events, small venues") plus a negative prompt ("no X-type events"), evaluated against feed events by a cheap model on ingest, with results cached per event. Most powerful at folder level: ten venue feeds in, the handful of events per month you actually care about out. (Phase 1 ships the structured/regex/keyword tiers; prompt filters may land with 5.8 in Phase 2 since they share the evaluation pipeline.)

**5.5 ICS interop**

- Import .ics files; subscribe to external ICS feed URLs with configurable poll intervals, per-feed health status, and staleness/failure flagging in the UI. Staleness includes content-level signals: flag feeds with no events, or no events passing their filters, in a configurable window (30/60 days), so the source list stays relevant.
- Outbound ICS: a feed for everything, per-calendar feeds, and feeds from saved searches. Filtered feeds embed a clear description of their scope ("This feed contains only events matching: ..."), and a management page lists every feed you have created, per the 05-31 note.

**5.6 Mobile web**

- Fully responsive; bottom-sheet event details on mobile with information priority: what/when/where and description visible without scrolling; secondary actions collapsed. This directly addresses the 05-30 critique of both EdgeOS and the Discourse plugin.
- Touch drag to move/resize with long-press initiation; quick-add prominent.
- Installable PWA.

**5.6a Google Calendar bidirectional sync**

- Better-Cal is the primary interface; Google Calendar becomes a downstream mirror for interop (people who invite you, devices not yet on CalDAV, sharing free/busy with others).
- Per-calendar and per-folder sync-back rules, including **filtered sync-back**: push only the events that pass a folder's filters to a designated Google calendar. Ten subscribed feeds can appear in Google as one clean curated calendar.
- Inbound sync from existing Google calendars covers migration and any events that continue to arrive there (invitations). Google API OAuth, incremental sync tokens, webhook or polling refresh.
- This may be the single most important adoption feature: it removes the switching cliff. It can begin life as one-way (Better-Cal to Google) in Phase 1 or 2 and go bidirectional when stable.

### Phase 2: Differentiators (past parity)

**5.7 Saved views / modes**

- Named modes capturing: enabled calendars/folders/tags, active filters, view type and position, sort. Examples from the notes: "Weekend planning" (my events + high-interest feeds + friends), "Travel mode", "Default".
- One interaction to switch; on leaving a modified mode, prompt to save or discard changes to it.

**5.8 Triage, ranking, and trainable filters (decision support)**

- Feed events (things you have not committed to) support a personal attendance state: none / interested / going / hidden. This is your side only; it does not RSVP anywhere.
- Thumbs up/down and hide actions accumulate as training signal. An LLM filter uses your signal history plus event content to score incoming feed events; scores drive ranking, de-emphasis, or auto-hide (configurable threshold). Runs as a background job on feed refresh, never in the render path.
- LLM filters are attachable at calendar or folder scope like any other filter.
- "What should I do this weekend" flow: weekend planning mode + score-ranked agenda of uncommitted events = a shortlist instead of a wall of listings. This is the direct answer to the 07-22 note: the tool's job is to shrink the decision space.
- **Day quality coloring**: days get an at-a-glance tint or indicator computed from (a) your committed busyness, including calendars currently toggled off, and (b) the supply of filter-passing uncommitted events that day. A free Saturday with five high-scoring options reads as a "green day", optionally with a subtle animated marker: make plans here. Recomputed as feeds refresh.

**5.9 Reschedule mode**

- A dedicated `reschedule` action (button and hotkey) enters a modal mode with the event highlighted and grabbed.
- Drop anywhere in view; drag to screen edge to auto-scroll time (scroll speed proportional to edge proximity); drag to the right edge to open a film-strip of month thumbnails, hover to flip months, drop into any day, confirm or cancel explicitly, then return to the origin view.
- Optional LLM assist inside reschedule mode: a small text box accepting "move to the week after Labor Day" or "suggest the least conflicting slot next week", with suggestions rendered as ghost placements to accept or refuse.

**5.10 CalDAV server**

- Expose calendars over CalDAV via sabre/dav so iOS Calendar, Android clients, Thunderbird, etc. sync natively (see section 7 for why this is the mobile strategy linchpin).
- Read/write for local calendars; read-only for subscribed feeds.

**5.11 Notifications**

- Event alarms via web push (PWA) and email fallback. Digest option: "new events added to your feeds today", which pairs with the recency features.

**5.12 Dynamic event content ("fancier calendar items")**

- A server-side **dynamic provider** plugin API: a provider binds to an event or a calendar and refreshes its content/appearance on a schedule. Reference providers:
  - **Weather**: forecast events or day-header badges with proper condition icons (custom icon set, not generic emoji), auto-updating.
  - **Tides / sun / AQI / surf**: port the existing TideCal and WeatherCal project concepts in as native providers, replacing the clunky parameterized-ICS approach the original note complains about. Users subscribe to exactly the data layers they want.
  - **Countdown / live status**: e.g. days-until on deadline events.
  - **Birthdays / contacts**: import from CardDAV or Google Contacts as a generated calendar.
- Providers manage their own API budgets: batch fetches (a week of forecast per call), delta updates, per-provider rate limits, so third-party API credits are used efficiently.
- Provider output is stored as regular event data plus a styling block, so rendering stays dumb and fast.
- Portability: dynamic bindings and styling serialize to `X-BETTERCAL-*` ICS properties so they round-trip between Better-Cal instances, and degrade to plain events elsewhere. The notes are right that this cannot be made portable to other clients; we do not try.

**5.13 Custom event styling**

- Per-calendar and per-event styling beyond a color: accent/gradient, icon, image thumbnail, and an optional scoped custom CSS hook (a class namespace per event, with sanitized user CSS applied only inside event chips/cards).
- A few built-in "distinctive" presets so styling is usable without writing CSS.

**5.13a People: person-linked events and availability**

- Events can be tagged with one or more people. This is deliberately not an invitation: no emails, no attendee status, just association. The 03-13 note's insight is that "who is this event connected to" is a common need while actually inviting someone is rare; Google Calendar only offers the heavyweight guest model.
- Person becomes a search and filter dimension: "everything with Sam", upcoming events per person on a small person page, NL quick-add captures people ("dinner with Sam" links Sam automatically once known).
- **Availability layers**: each person can have availability sources, mix and match per person: (a) manually tracked away/busy periods you enter (the "blanket events on a friends calendar" pattern, but structured and person-linked), and (b) a subscribed shared calendar or free/busy feed if they publish one. Rendered as an overlay when planning; feeds the day quality computation.
- Manual availability entry is Phase 2 and trivially cheap; subscribed free/busy joins the 5.18 sharing work.

**5.14 Create event from URL**

- Paste a Luma, Partiful, or Eventbrite URL into quick-add; the server fetches and extracts structured event data (JSON-LD/OpenGraph first, LLM extraction fallback) and pre-fills the editor. Extensible extractor registry per domain.

### Phase 3: Ambitious / future

**5.15 Feed-fetcher companion service ("any page becomes a calendar")**

- A separate, well-maintained service whose sole job is turning arbitrary event listing pages (the Mellow Kava Bars and Art Labs of the world) into reliable ICS feeds by any means necessary: JSON-LD, microdata, per-site scrapers, LLM page extraction.
- Maintains per-source fetch strategies, verifies feeds still work, flags breakage, and serves normalized ICS that Better-Cal (or anything else) subscribes to.
- Separate deliverable with its own PRD; Better-Cal only needs its ICS output. Candidate for general public usefulness.

**5.16 Venue / location view**

- For dense event days: lay out uncommitted events grouped by venue, venues sorted by distance from you, with a ghost/shadow treatment showing time overlap across venues. Exploratory; build behind a flag and evaluate with real data.

**5.17 External RSVP links and ticket availability**

- Events carry an optional external RSVP URL (from their source platform); one tap opens it. Deeper integrations (pulling RSVP state via per-platform API keys) only if a concrete need returns.
- **Availability checking** (speculative, high value): a background checker probes Partiful/Eventbrite/Luma pages for sold-out or waitlist status and reflects it on the event (badge, de-rank, or auto-hide per preference). Builds on the same per-domain extractor registry as create-from-URL and the feed-fetcher service.

**5.18 Sharing, free/busy, and multi-user**

- Single-user first. Architecture keeps a `user_id` on all rows so read-only shared views and a second user are cheap later; full multi-tenant collaboration is explicitly out of scope for now.
- Subscribe to other people's free/busy (Google/Apple published feeds) as overlay calendars, attached to Person records from 5.13a; the original note's "see they're free, propose an event" flow is the long-term multi-user north star.

**5.18a Travel planning and source statistics** (speculative)

- Travel assist: "when is a good window for a trip" suggestions from schedule busyness plus historical/average weather at the destination, using the weather provider pointed at any location and date range.
- Source statistics: per-calendar and per-folder time series of raw vs filter-passing event counts, feeding stale-source detection now and, long-term, seasonality hints ("these event types cluster in winter, plan around it"). Cheap to record from day one (a counts table written on each poll), so record from day one and defer all analysis UI.

**5.18b Email-to-event ingest** (user request 2026-07-31)

- CC or forward an email to a dedicated address (calendar@oshyan.com or a subaddress) and Better-Cal parses it with the LLM to create the appropriate event or events from the text and context. Example: "we're all set for the campground June 1-12th" creates that multi-day event; correspondents mentioned or addressed (Mick, identified by his email address) are linked as People on the event, and optionally receive an invitation.
- Ingest path: IMAP polling of the mailbox from the worker (mxroute-compatible), or a forwarding pipe later. Confidence gating: low-confidence parses land in an inbox/review state rather than silently creating events.
- People matching by email address becomes part of the Person record (add email column when building this).

**5.18c Agent access: API tokens and MCP** (user request 2026-07-31; tokens shipped in v0)

- The REST API is the agent surface: personal access tokens (Bearer) authenticate non-browser clients such as Claude Code, Codex, and cron scripts, with the same capabilities as the UI.
- An MCP server wraps the REST API (list/search/create/update events, quickadd, calendars) so LLM agents can drive the calendar natively; runs locally (stdio) against the remote API.

**5.19 Native mobile app**

- See section 7. A Capacitor wrapper around the PWA for reliable notifications is the likely first native step, Android first.

## 6. Non-Goals (v1)

- Meeting scheduling / availability polling (Calendly territory).
- Team/organization features, delegation, rooms.
- Hosting other people's RSVPs (the Discourse plugin already covers community events; see 6.1).
- Email invitation (iTIP/iMIP) processing.
- Replacing the Discourse calendar plugin; long-term the shared `bettercal-ui` library is the bridge between the two, not a merge.

## 7. Mobile Strategy (discussion)

The request: build so a cross-platform mobile app is possible later, unless that forces bad tradeoffs, and note platform realities.

**The key platform fact: iOS does not allow replacing the system default calendar app.** No third-party app can be "the default calendar" on iOS in the way that matters (system-wide event handling, Siri, watch complications all favor Apple Calendar). Android is more permissive but there is also no formal "default calendar app" role to win; calendar apps just read the shared provider store.

This reframes the problem nicely, and the answer is already in the architecture:

1. **CalDAV (Phase 2) is the native integration path.** Once Better-Cal speaks CalDAV, Apple Calendar and Android clients sync it natively: your events appear in the OS calendar, on your watch, in Siri, in widgets, with zero app-store work. "Default calendar app" stops being a goal worth fighting for; the OS calendar becomes a free native client for viewing and quick edits.
2. **The PWA is the rich client.** All the differentiating UX (modes, triage, reschedule mode, dynamic items) lives in the web app, which installs to the home screen on both platforms.
3. **A Capacitor shell is the escape hatch**, if and when PWA notification reliability disappoints (a repeated pain point in the notes, especially on Android). Wrapping the existing SPA costs little because the app is API-first and the UI is already touch-capable; it adds reliable push, and nothing about the current design blocks it.

Conclusion: no design tradeoffs need to be made now for mobile. API-first + PWA + CalDAV covers every realistic mobile outcome, and the only future mobile work (Capacitor wrapper) is additive. This is worth revisiting only if a hard requirement emerges for deep OS integration (e.g. being a share-sheet target for "add to calendar" flows, which Capacitor also solves).

## 8. Implementation Plan

Recent experience (full Discourse calendar plugin with custom UI in days) sets the calibration: these are aggressive but genuine estimates for AI-assisted development with subagents, assuming roughly full-time focus bursts.

**Milestone 1 (2 to 4 days): Skeleton that replaces nothing yet.** Schema, REST API, auth (single user), ICS import, month + agenda views with virtualized scroll, event CRUD with drag move/resize, calendars/colors/sidebar.

**Milestone 2 (2 to 3 days): Daily-driver parity.** NL quick-add, recurrence, week/multi-week/day views with overlap layout, search, feed subscriptions with polling and health flags, outbound ICS, past-event defaults, recency surfacing, undo, keyboard map.

**Milestone 3 (2 to 3 days): Mobile + PWA polish.** Responsive pass, bottom-sheet details with information priority, touch drag, installable PWA. **Gate: switch off Google Calendar for daily use here.** Migrate via ICS export.

**Milestone 4 (3 to 5 days): Differentiators.** Saved views/modes, triage states + prompt/trainable filters + ranking, day quality coloring, person linking + manual availability, reschedule mode with film strip, CalDAV via sabre/dav, notifications, Google sync (one-way out first).

**Milestone 5 (2 to 4 days): Fancy.** Dynamic providers (weather, tides, AQI), custom styling, create-from-URL, location autocomplete if it slipped, NL search if it slipped, Google sync bidirectional.

**Later:** feed-fetcher service, availability checking, venue view, travel assist, Capacitor shell, sharing/free-busy.

Dogfooding is the test plan: the milestone 3 gate forces real usage early, and every subsequent feature gets validated against actual daily behavior. Automated tests focus on the recurrence engine, feed ingestion, ICS round-tripping, and API contracts; UI correctness is covered by a small Playwright smoke suite.

## 9. Decisions (resolved 2026-07-30)

1. **DB**: MariaDB/MySQL on the Hetzner box (CloudPanel-managed; use whichever MySQL-compatible server CloudPanel provides, FULLTEXT works on both). Root access available.
2. **LLM provider**: Gemini Flash via existing API key to start; `LlmGateway` keeps Anthropic API and headless Claude CLI as swappable backends (Claude CLI could be installed on the box later).
3. **Auth/tenancy**: single user per instance, password + long-lived session. Vision is self-host for everyone, open source eventually; instances interchange via calendar subscriptions, not shared multi-user hosting. Schema keeps `user_id` so multi-user is never foreclosed.
4. **Geocoding**: start free (Photon/Nominatim or a free commercial tier), swappable behind an interface. Reference the Discourse Places plugin in EdgeTech for provider experience already gathered.
5. **Discourse plugin reference**: mine `/Users/oshyan/Projects/Coding/EdgeTech/discourse-events-calendar` for view layouts and interaction patterns (hard-won, tested against FullCalendar and won), but do not inherit Discourse-specific constraints or unpolished areas; port judgment, not cruft.
6. **Google sync**: one-way out first; bidirectional is required well within the first year, slotted as Phase 2/3.

## 10. Deployment Target

- Host: Oshyan's Hetzner box (CloudPanel), deployed as `cal.oshyan.com` (DNS via hcloud / Hetzner DNS).
- The implementing agent has hcloud CLI and server access; deploys and live-tests its own work end to end.
