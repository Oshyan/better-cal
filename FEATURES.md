# Better-Cal Features

Better-Cal is a self-hosted calendar built to replace Google Calendar outright: what most people use GCal for day to day, plus the things GCal never shipped. This document is the living feature reference. The parity summary is deliberately short; the differentiators and detail sections are the point.

## Google Calendar parity, briefly

Month, 3-week, 2-week, week, day, and agenda views. Multiple color-coded calendars with show/hide. Drag to create, move, and resize events. All-day and multi-day events. Full recurrence (rules, exceptions, "this event / all events" edits). Reminders with push and email delivery. ICS calendar subscriptions, file import, and export feeds. Fast search. Timezone-aware events. Keyboard shortcuts. Light/dark/system theme. Installable mobile PWA. Events read from forwarded email, and invitation replies (Accept / Maybe / Decline) that reach the organizer. Undo after an edit or delete. What it doesn't do (for now): send invitations to guests, offer booking pages, or create a video-call link for an event the way Google adds a Meet link (a meeting link you paste in works, with a Join button). It answers invitations, and other people see your calendars through feeds, CalDAV, or a calendar moved to Google.

Multi-day events show their total duration beside the title on every visible segment, including trip bands and agenda entries. Labels use exact days, exact whole weeks, or `1mo` for 28–31 days (including 28); other lengths never round to weeks or months. Event and trip details place the same duration beside the dates with a middle dot, spelling out units except `mo`; hover cards and screen-reader labels give the exact duration, as do date editors. All-day events count calendar dates; timed events qualify at 24 elapsed hours and retain remaining hours/minutes/seconds. Labels update from dates without changing the saved title.

## What sets it apart

1. **An interface rebuilt around flow, not pages.** Time is continuous, so the calendar is too: the month view scrolls seamlessly across months and years instead of flipping pages, and the week view scrolls horizontally the same way. A dedicated reschedule mode grabs an event as a ghost under your pointer and lets you drop it anywhere, even years away, while the calendar scrolls beneath it. Calendars organize into folders with collapse and folder-level visibility rather than one flat list. A live filter dims non-matching events in place as you type, and saved views capture a whole working state for one-click recall. On mobile, the month becomes a 3-day-wide ribbon so the overview survives a small screen with every title readable.

2. **A full activity log, with undo for any change for 7 days.** Every change, whether you made it or an automation did, is journaled with its source (web, phone sync, feed poll, email ingest, agent API, import) and shown newest-first with filters and live text search. Any entry with a snapshot (your edits, phone sync, agent and email changes) can be undone for 7 days, individually, with a guard against clobbering newer edits; feed-poll summaries and edits that went to a connected Google calendar are log-only. Google Calendar has an undo for the last action and a trash for deleted events, but no record of what changed or what changed it, and no way to reverse last Tuesday's edit.

3. **Trips and people are first-class objects.** Events can belong to a trip (a container spanning its members), and people are entities, not text: link them to events, click through to everything you share with someone, and keep track of when they're away or busy, separate from your events. Type "Sam is away next week" and it becomes away time for Sam, drawn as a band across the calendar, not a fake event. It's your own record of what you know about someone's plans, not a view of their calendar, so it works for anyone, with or without a calendar you could see.

4. **Natural language as a layer over everything, not just event entry.** Quick-add parses "Dinner with Alex fri 7pm at Luca's" instantly with a rule-based parser, escalating to an LLM only when needed and always guarded against LLM mistakes (past dates, dropped people, mangled titles). The same layer extends to organization: prompt filters take a rule in plain English ("hide corporate networking events") and apply it as a real filter alongside the live and folder-based filtering above. Availability statements, pasted event links, and a trainable ranker (learning from your thumbs and attendance) round it out.

5. **Leaving Google without breaking your life.** Every "Add to Google Calendar" link on the web can land in Better-Cal instead: a Chrome extension redirects them, the /add deep link and paste-a-GCal-URL quick-add parse them, and the installed app is a share target on phones (share a page or text to it) and, in desktop Chrome and Edge, opens webcal links and .ics files. One server command imports your entire Google export, subscribed feeds can be adopted as local calendars, and a calendar you still need Google users to see can be moved to Google and kept in place here. What Gmail did for you carries over too: forwarded event email becomes events, and RSVP replies go out from your own address.

6. **Agent-native and open by design.** A REST API with personal access tokens (no OAuth app to register) and an MCP server mean AI assistants can read and write your calendar as a peer, and their actions are labeled in the activity log and undoable like yours. CalDAV serves native phone and desktop clients, and an outbound ICS feed can share any slice of your data, down to a search result. It is plain PHP and MySQL on your own server: your data, your queries, no lock-in.

7. **Subscribed calendars treated as real calendars.** Per-calendar poll intervals you control, a "new" mark on events that arrived in the last day, triage (Planned / Maybe / Hide) right in the agenda, near-duplicate grouping so same-day lookalike listings collapse into one entry, and match-ranked ordering. GCal treats subscriptions as second-class read-only wallpaper; Better-Cal treats them as an inbox you can work.

8. **Every event says what it is to you.** Each event is Planned, Maybe, Available (an opportunity you haven't picked) or Context (information, like weather or sunset), set by its calendar's role and changeable per event. The Show filter (`p`, `m`, `a`, `x`) turns each kind on or off, so "what am I doing" and "what could I do" are one keypress apart. Context stays out of the way as small tokens in each day's header, which is where the bundled weather and sunrise/sunset plugins put their data.

## Feature detail

### Views and navigation

- Month, 3-week, 2-week, week, day, agenda, and Split views (right-aligned view dropdown that never shifts with the date label), with infinite scroll in the month slot, horizontal week scrolling, and day-to-day vertical scrolling in the day view, plus ISO week numbers in the month gutter and stable prev/next chevrons for rapid stepping.
- GCal-informed event rendering: all-day events are filled bars (with angled ends where they continue past the visible row or day), timed events are quiet dot + time rows, so density stays readable.
- Overlapping events in day and week views cascade at full opacity rather than shrinking into translucent slivers, and hovering brings any one of them to the front, so every title stays readable, including the ones underneath.
- Sidebar state is legible at a glance: hidden calendars and people are dimmed with a light strikethrough, while an away person is italic (a distinct channel, so "away" never looks like "hidden").
- Day numbers open the day view; the strip beside them expands the day in place; a hover + creates an event. Week and day headers click through to the day view too.
- Hover any event (mouse or trackpad) for a card with its full title, when, calendar and place, and a short line for each reason it looks the way it does: maybe, ended, happening now, new, cancelled, or which filter highlighted or dimmed it and where it matched.
- Collapsible sidebar (persisted), hover-revealed visibility checkboxes with dimmed hidden calendars, and a collapsible all-day lane in day/week views.
- Sidebar mini-month for orientation and fast jumps; month dividers keep long scrolls legible.
- Split: a strip of weeks over a day list that runs on without a break, moving together as you scroll; empty days fold into one line, and a handle trades weeks for list.
- Quick jump (press `g`): type a date or a natural phrase and land there.
- Command palette (Cmd/Ctrl-K): type to find a view, a calendar or an action, go to a date, or create an event from the text.
- Full hotkey coverage (navigation, view switching, the Show filter, creating, editing and deleting events, search, filter) with a `?` cheat sheet generated from the live bindings.
- Each day's context (weather, air quality, sunrise and sunset, tides) sits in its header, as many tokens as fit the width with "+N" for the rest; holidays are written with the date.
- Coming back within half an hour of a reload or an update puts you where you were: the view, the day, the filter and saved view, an open event or search, and the page you were on.
- Day expand: click a crowded day to see everything, with per-row open buttons.
- Saved views capture a mode plus filter state and restore it in one click; any one can be the default view.
- Live view filter: type to dim non-matching events in place without a reload.

### On phones

- An installable app with a phone layout of its own: navigation along the top (menu, previous and next, the month, Today), view and find along the bottom (View, Search, New, Filter, Review).
- Split: dense weeks with every title over a continuous day list, moving together as you scroll, with a handle to trade one for the other. Full month, 3 day, week (pinch for day width), day and agenda too.
- Events open in a sheet: step through the day, pull it up for everything (map, full description, invitation replies, plugin data), and trips open the same way.
- A full-screen editor with typed dates and times ("fri", "7p") and quarter-hour lists, and quick add from the New button. In week and day views, tap an empty time to create, or press and hold to draw an event or pick one up and move it.
- Swipe a suggested event in a list to triage it (Maybe, Planned, Hide, More like this, Less like this).
- Back works on every surface (the sheet, search, the editor, the drawer, every page), and the sidebar drawer swipes in from the edge.
- In the drawer, each calendar is listed once in its folder; press and hold one to show only it or open its settings.
- Push reminders with Map and Join buttons.

### Creating and editing

- Natural-language quick add with a structured confirmation strip (the parse fills it, your edits win), Enter to commit, click-outside to dismiss.
- Quick add from outside the app: a "New event" shortcut on the installed app's icon, and an optional key in the Chrome extension (you choose it) that opens it from any app.
- Deterministic parser first; LLM assist only when the parse is incomplete, with merge guards so the LLM can never move an event into the past, drop companions, or rewrite the title. Parse mode (smart / always / never) is a setting.
- Paste a Google Calendar template link into quick-add and it becomes the event, recurrence and all.
- Drag-create opens the editor pre-filled; drag and resize existing events to reschedule.
- Dedicated reschedule mode (press `r`, or Move on an open event): the event becomes a pointer-following ghost, you scroll or jump anywhere on the calendar (months or years away), and click to drop it. Recurring events move this occurrence only.
- Full editor: rich text descriptions, location with geocoded place search (biased to where your calendar puts you that day, then your device and Home), people, tags, per-event reminders, recurrence editor, calendar picker with each calendar's colour, trip membership, and For me (Planned, or Maybe, which saves the event as tentative).
- Typed dates and times in the editor ("fri", "10/5", "7p", "noon"), a quarter-hour list for times, and an end that keeps the event's length when the start moves.
- Per-event time zone: enter a time as it reads somewhere else ("3 PM New York" from a laptop in Lisbon). The editor shows what that is on your device before you save, the detail view shows both clocks afterwards, and a repeating event keeps its time in its own zone across DST changes. All-day events are dates and stay on the same day in every zone.
- Dirty-state confirmation so entered details are never silently lost.
- Events open in a side panel on a desktop (the sheet on a phone) that holds everything: when, the invitation reply, where with Directions or Join, reminders, people, map, description, plugin data and the source. A fixed toolbar (Edit, Move, More, For me) and `[` / `]` to step through the day's events.
- Copy an event to another calendar from More.
- Undo toast after destructive actions, backed by the same journal as the activity log.

### Trips (container events)

- A trip is an event that contains other events; members show a trip indicator and link back to the container.
- Trip detail lists members with dates; deleting offers "trip only" or "trip and members" with undo-ordering that restores cleanly.
- Members keep their own calendars and colors; the trip spans them visually.
- A trip opens where an event does (side panel or sheet) with its dates and length, its place with Directions, its events as rows, and a map numbering their places. Opening one of its events keeps the way back to the trip.
- Add events offers your planned and maybe events during the trip, with feed suggestions on request.

### People and availability

- People are entities linked to events via the editor or quick-add "with X" clauses.
- People page: usage stats, next event, notes, rename (renaming onto an existing person merges), delete, and a per-person event list that jumps the calendar.
- Away and busy times per person, which you keep yourself (not read from their calendar), created in the UI or by typing "Sam away Aug 10-15" into quick-add.
- They render as labeled bands across the calendar; a sidebar People section controls whose bands show (all / none / custom), with per-person solo.
- Conflict awareness: the editor warns when you schedule an event with someone who is away.
- Availability check API for agents ("is Sam free Thursday?").

### Calendars, folders, and subscriptions

- Local calendars with color, default reminders, and per-calendar settings.
- Calendar roles: Mine (things you do), Opportunities (things you could do) or Context (information). The role sets each event's default For me (Planned, Available or Context), and changing it re-reads every event at once.
- Folders group calendars with collapse and folder-level visibility; a calendar can live in multiple folders.
- ICS subscriptions with a per-calendar poll interval (every 15 minutes to every 24 hours, hourly by default), manual refresh, feed health in the sidebar, and adopt-as-local (converts a feed into an editable calendar).
- A "new" mark on events that arrived on their own (feed, email, import, agent, plugin) in the last 24 hours, never on a calendar's initial load.
- Near-duplicate grouping: on calendars where it's turned on, same-day listings whose titles differ only by a trailing parenthetical collapse into one stacked chip with a member popover.
- The same event, shown once: when an event reaches you by two routes (a feed and a forwarded confirmation, a Takeout import and its Google calendar), the most live copy shows, with "Also on" naming the others. Near-matches go to Review as possible duplicates. Nothing is merged or deleted, and "Not the same" undoes a wrong match.
- Triage on feed events (Planned / Maybe / Hide) directly from the agenda, with More like this / Less like this beside it.
- Tags on events (and on calendars through the API) for cross-cutting organization.

### Search, filters, and ranking

- Instant search across events with click-through that jumps to and flashes the result. Upcoming (the default), All or Past, and a calendar, are chosen under the box and applied before the result limit; a repeating series shows its next date. Tag chips run a tag-only search.
- Prompt filters: describe a rule in plain English ("hide corporate networking events"), an LLM evaluates it over events, results apply as filters.
- Trainable ranking: thumbs up/down and attendance feed a ranker that orders busy feeds by predicted interest; agenda has a match-sort mode. Thumbs show which one you chose.
- On-page quick filter dims non-matching events live.
- The Show filter switches Planned, Maybe, Available and Context events on and off (`p`, `m`, `a`, `x`); a mark on a day says when it's hiding some of that day's events.

### Activity log and undo

- Every mutation is journaled with source, human-readable summary, and before/after snapshots.
- Sources: web UI, quick-add, CalDAV (phone/desktop sync), RSVP, Review decisions, agent API, feed polls, email ingest (with tier), bulk import, geocoding, plugins, duplicate matching, and system events (sign-in blocks, new reminder devices, provider failures).
- Activity page: newest-first, All / Manual / Automated groups, per-source filter chips, live text filter, load-more pagination.
- Feed polls log one rollup entry per poll with counts and added titles; zero-change polls log nothing.
- Event entries click through to the event on the calendar.
- Per-entry undo within a 7-day snapshot window, with a staleness guard (undoing an old edit warns before overwriting newer ones). Log entries persist 90 days. Feed-poll summaries and edits to connected Google calendars are log-only; the app says "can't be undone" before a Google edit.

### Email ingest and RSVP

- Dedicated ingest mailbox polled every 2 minutes; forward or filter-forward event emails to it.
- Tiered extraction ladder: iMIP invite parsing, schema.org JSON-LD, embedded add-to-Google links (which survive forwarding), then a hardened LLM pass over flattened HTML.
- Forward-preamble stripping and date-evidence guards so a forward timestamp can never become the event date and no event is invented without one.
- A Review queue instead of silent changes: when an organizer emails a change or a cancellation for something already on your calendar, it waits for you with a from/to view of exactly what would change. Email is not authenticated, so nothing an email says can move or cancel your event until you accept it; accepting is an ordinary, undoable edit. Forged organizers and replayed old versions are refused outright and logged.
- One inbox for every decision: held invitation changes, invitations you have not answered (reply inline), possible duplicates, and plans your plugins suggest, with a count in the sidebar and a notification when something arrives. Agents get the same list, each item carrying its own actions.
- RSVP buttons send real iMIP replies via your own SMTP (e.g. your Gmail), so responses come from you. They appear only where a reply can go; otherwise the event says why (no organizer, no account to send from, or you weren't the invited address). A failed send keeps your answer and offers Retry.
- Invitations on a connected Google calendar are answered at Google, which tells the organizer.
- Bookings (a reservation, ticket or confirmation) read "Booking via" the site instead of offering replies that could never send.
- Emailed times are read on the clock of the place they're about ("3 PM" for a Tokyo booking is Tokyo time), else the zone your device last reported, then Home.
- Ingested events carry the invitation panel (organizer, attendees, your status) and land on an Invitations calendar.
- Per-message ingest log (a database table) with tier and outcome for debugging.

### Leaving Google: link capture and migration

- `/add` deep link accepts Google Calendar template URLs; a Chrome extension (MV3, declarative redirect) rewrites "Add to Google Calendar" links to it automatically.
- Quick-add paste and the share target work on phones; the webcal and .ics handlers work in desktop Chrome and Edge (Android doesn't hand those links to installed web apps).
- `/subscribe` deep link for one-tap feed subscriptions.
- Bulk importer (a server command) for the Google Calendar settings export (or Takeout): one calendar per file, names cleaned from filename patterns, full recurrence preserved. Proven on a 10-calendar, 8,000+ event migration. In the app, any single .ics file imports from the sidebar or the welcome.
- Feed adoption converts a subscribed calendar into a local editable one when you are ready to cut the cord.

### Notifications and reminders

- Per-event, per-calendar, and global default reminders with a clear precedence chain.
- Web Push to any installed PWA or browser, email delivery, or both, including a push-with-email-fallback mode for unreachable devices.
- Timed and all-day reminder defaults are separately configurable (e.g. all-day events remind the evening before).
- A Home time zone setting: all-day reminders fire on your home clock whatever zone an event was imported or created in, and the app tells you once when the device you are on keeps a different clock.
- Reminders give the time on the clock where your device is (and the event's own clock when it keeps a different zone), in your 12- or 24-hour setting.
- Settings lists every device reminders go to, by name ("Mac · Chrome"), with Remove for any you don't recognise.
- Test buttons for both channels in Settings.

### Sync, API, and integrations

- CalDAV server (sabre/dav) for native iOS, Android (DAVx5), macOS, and Thunderbird clients, with proper sync tokens.
- REST API with personal access tokens; the endpoints the UI uses are available to agents, apart from account-security steps that need you signed in with your password (linking Google, signing out other browsers), and agent writes are tagged in the activity log. MCP wrapper for AI assistants.
- Outbound ICS feeds: share all events, one calendar, or a search result as a standing URL.
- Google connector (optional; each install sets up its own free Google Cloud client): connect a Google account to read the calendars you own, subscribe to or have been shared, including private shared ones no iCal address reaches. Where you can edit, changes go to Google first (write-through, not two-way sync).
- Move a calendar to Google: a calendar that started here is created in your Google account with every event, no one is mailed, and it keeps its tags, people, reminders and trips. "Adopt as local calendar" brings it back.
- Auto-refresh: open clients poll a cheap change cursor every 30 seconds, and at once when you come back to the tab, so changes from any device or automation show up without a reload.
- Installable PWA with offline shell, share target, a webcal protocol handler and an .ics file handler (desktop Chrome and Edge), and a "New event" shortcut on its icon.

### Plugins

- Bundled server-side plugins, each switched on and set up on the Plugins page, which shows its permissions, last run, what it has made, any warnings, Run now, and uninstall with a preview of what goes.
- Weather: a daily forecast from Open-Meteo (no API key) with an icon for the conditions and the high/low, plus the day's air quality (US AQI); severe weather and unhealthy air raise warnings.
- Sun: sunrise and sunset for a place, computed on the server (no network, no key), in each day's header and as a line on the timeline.
- Earlier-stage ones: Tides (NOAA predictions for US stations, with daylight low-tide windows), Travel Time (leave-by bands before events you travel to, and warnings when back-to-back events are too far apart), Calendar Lint (a daily audit of the next 30 days for impossible travel, events outside your preferred hours and suspected duplicates), Day Planner and Trip Planner (propose an outing on an open weekend day, or the best free window for a longer trip using the destination's climate normals), and Visit Intents (draws free, open-hours stretches for places you mean to go, with links out to booking).
- Proposals wait in Review; nothing reaches your calendar until you press Accept.

### Self-hosting and privacy

- Plain PHP 8.4+ with MySQL or MariaDB on your own server (Nginx and Apache tested; shared hosting should work but hasn't been tried on a real shared host yet); no framework, no build step (Preact + HTM served as-is), no telemetry.
- A first-run welcome confirms the basics from your device (Home time zone, week start, 12- or 24-hour clock, theme, the first calendar's name) and offers places to start: subscribe, import, connect Google, install the app, turn on reminders, browse plugins. Skippable at every step; Settings can show it again.
- `php server/bin/demo-seed.php` fills an empty account with invented calendars, events and people around today, for a first look.
- Your data is ordinary SQL you can query, back up, and take with you.
- LLM features are optional, provider-keyed, and degrade gracefully; the deterministic paths always work without them.
- Single-user by design today, hardened with session auth, CSRF protection, and revocable, expiring API tokens; feeds, devices and subscriptions an API token created stop with it.
- Sign out everywhere else (Settings, Account), for a lost device: signs out every other browser, stops their reminders, gives the outbound feeds you made while signed in new URLs, and returns a custom reminder address to the account email. Connecting Google or moving a calendar there asks for your password again if this browser hasn't confirmed it in the last ten minutes.
- Password guessing is limited per network address across the login form and CalDAV together, without ever locking the account: an attacker cannot lock you out of your own calendar, and addresses you have signed in from keep working while others are being refused. Blocks show up in Activity.
