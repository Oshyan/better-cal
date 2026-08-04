# Better-Cal Features

Better-Cal is a self-hosted calendar built to replace Google Calendar outright: everything you expect from GCal, plus the things GCal never shipped. This document is the living feature reference. The parity summary is deliberately short; the differentiators and detail sections are the point.

## Google Calendar parity, briefly

Month, 3-week, 2-week, week, day, and agenda views. Multiple color-coded calendars with show/hide. Drag to create, move, and resize events. All-day and multi-day events. Full recurrence (rules, exceptions, "this event / all events" edits). Reminders with push and email delivery. ICS calendar subscriptions, file import, and export feeds. Fast search. Timezone-aware events. Keyboard shortcuts. Light/dark/system theme. Installable mobile PWA. If Google Calendar does it day-to-day, Better-Cal does it.

## What sets it apart

1. **An interface rebuilt around flow, not pages.** Time is continuous, so the calendar is too: the month view scrolls seamlessly across months and years instead of flipping pages, and the week view scrolls horizontally the same way. A dedicated reschedule mode grabs an event as a ghost under your pointer and lets you drop it anywhere, even years away, while the calendar scrolls beneath it. Calendars organize into folders with collapse and folder-level visibility rather than one flat list. A live filter dims non-matching events in place as you type, and saved views capture a whole working state for one-click recall. On mobile, the month becomes a 3-day-wide ribbon so the overview survives a small screen instead of collapsing into dots.

2. **A full activity log with undo, for everything.** Every change, whether you made it or an automation did, is journaled with its source (web, phone sync, feed poll, email ingest, agent API, import) and shown newest-first with filters and live text search. Any entry with a snapshot can be undone for 7 days, individually, with a guard against clobbering newer edits. Google Calendar is famously opaque here: things appear and vanish with no record and no recourse.

3. **Trips and people are first-class objects.** Events can belong to a trip (a container spanning its members), and people are entities, not text: link them to events, click through to everything you share with someone, and track availability. Type "Sam is away next week" and it becomes an availability span rendered as a band across the calendar, not a fake event.

4. **Natural language as a layer over everything.** Quick-add parses "Dinner with Alex Fri 7pm at Zuni" instantly with a rule-based parser, escalating to an LLM only when needed and always guarded against LLM mistakes (past dates, dropped people, mangled titles). The same layer extends to organization: prompt filters take a rule in plain English ("hide corporate networking events") and apply it as a real filter alongside the live and folder-based filtering above. Availability statements, pasted event links, and a trainable ranker (learning from your thumbs and attendance) round it out.

5. **Leaving Google without breaking your life.** Every "Add to Google Calendar" link on the web can land in Better-Cal instead: a Chrome extension redirects them, the /add deep link and paste-a-GCal-URL quick-add parse them, and the PWA registers as the webcal/ICS handler and share target on mobile (the thing Google never fixed on Android). One command imports your entire Google export, subscribed feeds can be adopted as local calendars, and forwarded event email becomes events with genuine iMIP RSVP replies sent from your own address, so even Gmail's invite handling has a path over.

6. **Agent-native and open by design.** A clean REST API with personal access tokens means AI assistants can read and write your calendar as a peer, and their actions are labeled in the activity log. CalDAV serves native phone and desktop clients. Outbound ICS feeds share any slice of your data. It is plain PHP and MySQL on your own server: your data, your queries, no lock-in.

7. **Subscribed calendars treated as real calendars.** Per-calendar poll intervals you control, new-event badges since your last look, attendance triage (going / interested / skip) right in the agenda, near-duplicate grouping so multi-venue listings collapse into one entry, and match-ranked ordering. GCal treats subscriptions as second-class read-only wallpaper; Better-Cal treats them as an inbox you can work.

## Feature detail

### Views and navigation

- Month, 3-week, 2-week, week, day, and agenda views, with infinite scroll in the month slot and horizontal week scrolling.
- Mobile-aware overview: a 3-day ribbon replaces the month grid on narrow screens (configurable).
- Sidebar mini-month for orientation and fast jumps; month dividers keep long scrolls legible.
- Quick jump (press `g`): type a date or a natural phrase and land there.
- Full hotkey coverage (navigation, view switching, creation, search, filter, undo) with a `?` cheat sheet generated from the live bindings.
- Day expand: click a crowded day to see everything, with per-row open buttons.
- Saved views capture a mode plus filter state and restore it in one click.
- Live view filter: type to dim non-matching events in place without a reload.

### Creating and editing

- Natural-language quick add with a structured confirmation strip (the parse fills it, your edits win), Enter to commit, click-outside to dismiss.
- Deterministic parser first; LLM assist only when the parse is incomplete, with merge guards so the LLM can never move an event into the past, drop companions, or rewrite the title. Parse mode (smart / always / never) is a setting.
- Paste a Google Calendar template link into quick-add and it becomes the event, recurrence and all.
- Drag-create opens the editor pre-filled; drag and resize existing events to reschedule.
- Dedicated reschedule mode (press `r` or the clock icon): the event becomes a pointer-following ghost, you scroll or jump anywhere on the calendar (months or years away), and click to drop it. Recurring events move this occurrence only.
- Full editor: rich text descriptions, location with geocoded place search (biased to your home area), people, tags, per-event reminders, recurrence editor, calendar picker, trip membership.
- Dirty-state confirmation so entered details are never silently lost.
- Event detail view with mini-map, prev/next same-day navigation, and inline action icon bar.
- Undo toast after destructive actions, backed by the same journal as the activity log.

### Trips (container events)

- A trip is an event that contains other events; members show a trip indicator and link back to the container.
- Trip detail lists members with dates; deleting offers "trip only" or "trip and members" with undo-ordering that restores cleanly.
- Members keep their own calendars and colors; the trip spans them visually.

### People and availability

- People are entities linked to events via the editor or quick-add "with X" clauses.
- People page: usage stats, next event, notes, rename (renaming onto an existing person merges), delete, and a per-person event list that jumps the calendar.
- Availability spans (away / busy) per person, created in the UI or by typing "Marcus away Aug 10-15" into quick-add.
- Spans render as labeled bands across the calendar; a sidebar People section controls whose bands show (all / none / custom), with per-person solo.
- Conflict awareness: the editor warns when you schedule an event with someone who is away.
- Availability check API for agents ("is Sam free Thursday?").

### Calendars, folders, and subscriptions

- Local calendars with color, default reminders, and per-calendar settings.
- Folders group calendars with collapse and folder-level visibility; a calendar can live in multiple folders.
- ICS subscriptions with per-calendar poll interval (default 60 min, min 5), manual refresh, and adopt-as-local (converts a feed into an editable calendar).
- New-event badges on subscribed calendars since your last visit, suppressed on the initial import.
- Near-duplicate grouping: same-day lookalike listings collapse into one stacked chip with a member popover.
- Attendance triage on feed events (going / interested / skip) directly from the agenda.
- Tags on calendars and events for cross-cutting organization.

### Search, filters, and ranking

- Instant search across events with click-through that jumps to and flashes the result.
- Prompt filters: describe a rule in plain English ("hide corporate networking events"), an LLM evaluates it over events, results apply as filters.
- Trainable ranking: thumbs up/down and attendance feed a ranker that orders busy feeds by predicted interest; agenda has a match-sort mode.
- On-page quick filter dims non-matching events live.

### Activity log and undo

- Every mutation is journaled with source, human-readable summary, and before/after snapshots.
- Sources: web UI, quick-add, CalDAV (phone/desktop sync), RSVP, agent API, feed polls, email ingest (with tier), bulk import.
- Activity page: newest-first, All / Manual / Automated groups, per-source filter chips, live text filter, load-more pagination.
- Feed polls log one rollup entry per poll with counts and added titles; zero-change polls log nothing.
- Event entries click through to the event on the calendar.
- Per-entry undo within a 7-day snapshot window, with a staleness guard (undoing an old edit warns before overwriting newer ones). Log entries persist 90 days.

### Email ingest and RSVP

- Dedicated ingest mailbox polled every 2 minutes; forward or filter-forward event emails to it.
- Tiered extraction ladder: iMIP invite parsing, schema.org JSON-LD, embedded add-to-Google links (which survive forwarding), then a hardened LLM pass over flattened HTML.
- Forward-preamble stripping and date-evidence guards so a forward timestamp can never become the event date and no event is invented without one.
- iMIP invites upsert by UID: updates and cancellations track the organizer's changes.
- RSVP buttons send real iMIP replies via your own SMTP (e.g. your Gmail), so responses come from you.
- Ingested events carry the invitation panel (organizer, attendees, your status) and land on an Invitations calendar.
- Per-message ingest log with tier and outcome for debugging.

### Leaving Google: link capture and migration

- `/add` deep link accepts Google Calendar template URLs; a Chrome extension (MV3, declarative redirect) rewrites calendar.google.com event links to it automatically.
- Quick-add paste, PWA share target, and webcal/ICS protocol handlers cover mobile, where GCal never let you subscribe by link.
- `/subscribe` deep link for one-tap feed subscriptions.
- Bulk importer for the Google Calendar settings export (or Takeout): one calendar per file, names cleaned from filename patterns, full recurrence preserved. Proven on a 10-calendar, 8,000+ event migration.
- Feed adoption converts a subscribed calendar into a local editable one when you are ready to cut the cord.

### Notifications and reminders

- Per-event, per-calendar, and global default reminders with a clear precedence chain.
- Web Push to any installed PWA or browser, email delivery, or both, including a push-with-email-fallback mode for unreachable devices.
- Timed and all-day reminder defaults are separately configurable (e.g. all-day events remind the evening before).
- Test buttons for both channels in Settings.

### Sync, API, and integrations

- CalDAV server (sabre/dav) for native iOS, Android (DAVx5), macOS, and Thunderbird clients, with proper sync tokens.
- REST API with personal access tokens; every endpoint the UI uses is available to agents, and agent writes are tagged in the activity log. MCP wrapper for AI assistants.
- Outbound ICS feeds: share all events, one calendar, or a search result as a standing URL.
- Auto-refresh: open clients poll a cheap change cursor and update within seconds of any change from any device or automation.
- Installable PWA with offline shell, share target, and protocol handlers.

### Self-hosting and privacy

- Plain PHP 8.4 + MySQL on your own server; no framework, no build step (Preact + HTM served as-is), no telemetry.
- Your data is ordinary SQL you can query, back up, and take with you.
- LLM features are optional, provider-keyed, and degrade gracefully; the deterministic paths always work without them.
- Single-user by design today, hardened with session auth, CSRF protection, and scoped API tokens.
