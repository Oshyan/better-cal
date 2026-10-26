# Better-Cal Features

Better-Cal is a self-hosted calendar built to replace Google Calendar outright: what most people use GCal for day to day, plus the things GCal never shipped. This is the feature reference. The parity summary is short on purpose; the differentiators and the detail sections are the point.

## Google Calendar Parity, Briefly

Month, 3-week, 2-week, week, day and agenda views. Multiple color-coded calendars with show and hide. Drag to create, move and resize events. All-day and multi-day events. Full recurrence (rules, exceptions, "this event / all events" edits). Reminders by push and email. ICS subscriptions, file import and export feeds. Fast search. Time zone aware events. Keyboard shortcuts. Light, dark and system themes. An installable phone app. Events read from forwarded email, and invitation replies (Accept, Maybe, Decline) that reach the organizer. Undo after an edit or delete.

What it doesn't do (for now): send invitations to guests, offer booking pages, or create a video-call link the way Google adds a Meet link (a meeting link you paste in works, with a Join button). It answers invitations, and other people see your calendars through feeds, CalDAV, or a calendar moved to Google.

## What Sets It Apart

1. **An interface built around flow, not pages.** Time is continuous, so the calendar is too: the month view scrolls across months and years instead of flipping pages, and the week view scrolls sideways the same way. Reschedule mode carries an event under your pointer anywhere, even years away, while the calendar scrolls beneath it. Folders, a live filter and saved views keep a busy calendar workable, and on a phone the month becomes a three-day-wide ribbon with every title readable.
2. **A full activity log, with undo for any change for 7 days.** Every change, yours or an automation's, is logged with its source and can be undone on its own, with a guard against clobbering newer edits. Google Calendar undoes your last action and keeps a trash, but has no record of what changed or what changed it, and no way to reverse last Tuesday's edit.
3. **Trips and people are real objects.** Events can belong to a trip that spans them, and people are entities, not text: link them to events, see everything you share with someone, and keep track of when they're away or busy. Type "Sam is away next week" and it becomes away time for Sam, drawn as a band across the calendar, not a fake event. It's your own record of someone's plans, not a view of their calendar, so it works for anyone.
4. **Natural language over everything, not just event entry.** Quick add parses "Dinner with Alex fri 7pm at Luca's" instantly with rules, asking an LLM only when it needs to and guarding against its mistakes. Prompt filters take a rule in plain English ("hide corporate networking events") and apply it as a real filter. Availability statements, pasted event links and a ranker that learns from your thumbs and attendance round it out.
5. **Leaving Google without breaking your life.** "Add to Google Calendar" links anywhere on the web can land in Better-Cal instead, one server command imports your whole Google export, and a calendar Google users still need to see can be moved to Google and kept in place here. What Gmail did for you carries over too: forwarded event email becomes events, and RSVP replies go out from your own address.
6. **Agent-native and open.** A REST API with personal access tokens (no OAuth app to register) and an MCP server let AI assistants read and write your calendar as a peer, with their actions labeled in the activity log and undoable like yours. CalDAV serves phone and desktop calendar apps, and an outbound ICS feed can share any slice of your data, down to a search result. It's plain PHP and MySQL on your own server: your data, your queries, no lock-in.
7. **Subscribed calendars treated as real calendars.** Poll intervals you control, a "new" mark on fresh arrivals, triage (Planned, Maybe, Hide) right in the agenda, same-day lookalike listings collapsed into one, and match-ranked ordering. GCal treats subscriptions as read-only wallpaper; Better-Cal treats them as an inbox you can work.
8. **Every event says what it is to you.** Each event is Planned, Maybe, Available (an opportunity you haven't picked) or Context (information, like weather or sunset), set by its calendar's role and changeable per event. The Show filter (`p`, `m`, `a`, `x`) turns each kind on or off, so "what am I doing" and "what could I do" are one keypress apart. Context sits as small tokens in each day's header, where the bundled weather and sunrise and sunset plugins put their data.

## Feature Detail

### Views and Navigation

- Month, 3-week, 2-week, week, day, agenda and Split views. The month slot scrolls infinitely, the week view scrolls sideways and the day view scrolls day to day. ISO week numbers in the month gutter, month dividers in long scrolls, and prev and next chevrons that stay put for rapid stepping.
- Split: a strip of weeks over a day list that runs on without a break, the two moving together as you scroll. Empty days fold into one line, and a handle trades weeks for list.
- Event rendering follows GCal's lead: all-day events are filled bars (with angled ends where they continue past the row or day), timed events are quiet dot-and-time rows, so dense days stay readable.
- Overlapping events in day and week views cascade at full opacity rather than shrinking into translucent slivers, and hovering brings one to the front, so every title stays readable.
- Multi-day events show their length beside the title on every segment, trip bands and agenda entries included, and beside the dates in event and trip details: exact days, exact whole weeks, or `1mo` for 28 to 31 days, never rounded otherwise. All-day events count calendar dates; a timed event counts as a day once it runs 24 hours, keeping the leftover hours and minutes. Hover cards, screen-reader labels and date editors give the exact length. The label isn't part of the saved title.
- Hover any event for a card with its full title, when, calendar and place, and a short line for each reason it looks the way it does: maybe, ended, happening now, new, cancelled, or which filter highlighted or dimmed it and where it matched.
- Day numbers open the day view, the strip beside them expands a crowded day in place (with an open button per row), and a hover + creates an event. Week and day headers click through to the day view too.
- Each day's context (weather, air quality, sunrise and sunset, tides) sits in its header, as many tokens as fit with "+N" for the rest; holidays are written with the date.
- Sidebar: collapsible (remembered), with a mini-month for fast jumps and hover-revealed visibility checkboxes. Hidden calendars and people are dimmed with a light strikethrough; an away person is italic, so "away" never looks like "hidden". Day and week views have a collapsible all-day lane.
- Quick jump (`g`): type a date or a phrase and land there. Command palette (Cmd/Ctrl-K): find a view, a calendar or an action, go to a date, or create an event from the text.
- Hotkeys for navigation, views, the Show filter, creating, editing and deleting events, search and filter, with a `?` cheat sheet built from the live bindings.
- Saved views capture a view and its filters and restore them in one click; any one can be the default.
- Coming back within half an hour of a reload or an update puts you where you were: the view, the day, the filter and saved view, an open event or search, and the page you were on.

### On Phones

- An installable app with its own layout: navigation along the top (menu, previous and next, the month, Today), view and find along the bottom (View, Search, New, Filter, Review).
- Split (above) with every title in the weeks, plus full month, 3 day, week (pinch for day width), day and agenda.
- Events open in a sheet: step through the day, pull it up for everything (map, full description, invitation replies, plugin data). Trips open the same way.
- A full-screen editor with typed dates and times and quarter-hour lists, and quick add from the New button. In week and day views, tap an empty time to create, or press and hold to draw an event or pick one up and move it.
- Swipe a suggested event in a list to triage it (Maybe, Planned, Hide, More like this, Less like this).
- Back works everywhere (the sheet, search, the editor, the drawer, every page), and the sidebar drawer swipes in from the edge. In the drawer, each calendar is listed once in its folder; press and hold one to show only it or open its settings.
- Push reminders with Map and Join buttons.

### Creating and Editing

- Natural-language quick add with a confirmation strip the parse fills in (your edits win), Enter to commit, click outside to dismiss. [Quick add](docs/quick-add.md) has what it understands.
- A rule-based parser first; the LLM helps only when the parse is incomplete, with guards so it can never move an event into the past, drop companions or rewrite the title. Parse mode (smart, always, never) is a setting.
- Quick add from outside the app: a "New event" shortcut on the installed app's icon, and an optional key (you choose it) in the Chrome extension that opens it from any app.
- Paste a Google Calendar template link into quick add and it becomes the event, recurrence and all.
- Drag to create (opens the editor pre-filled), drag and resize to reschedule.
- Reschedule mode (`r`, or Move on an open event): the event follows your pointer as a ghost while you scroll or jump anywhere (months or years away), and a click drops it. For a repeating event it moves this occurrence only.
- The editor: rich text descriptions, location with place search (biased to where your calendar puts you that day, then your device and Home), people, tags, per-event reminders, recurrence, a calendar picker in each calendar's color, trip membership, and For me (Planned, or Maybe, which saves the event as tentative).
- Typed dates and times ("fri", "10/5", "7p", "noon"), a quarter-hour list for times, and an end that keeps the event's length when the start moves.
- Per-event time zone: enter a time as it reads somewhere else ("3 PM New York" from a laptop in Lisbon). The editor shows what that is on your device before you save, the detail view shows both clocks, and a repeating event keeps its own zone's time across DST changes. All-day events are dates and stay on the same day in every zone.
- A confirmation before unsaved details are lost.
- Events open in a side panel on a desktop (the sheet on a phone) with everything: when, the invitation reply, where with Directions or Join, reminders, people, map, description, plugin data and the source. A fixed toolbar (Edit, Move, More, For me), and `[` / `]` to step through the day's events.
- Copy an event to another calendar from More.
- An undo toast after destructive actions, backed by the activity log.

### Trips

- A trip is an event that contains other events. Members keep their own calendars and colors, show a trip indicator and link back; the trip spans them on the calendar.
- A trip opens where an event does (side panel or sheet) with its dates and length, its place with Directions, its events as rows, and a map numbering their places. Opening one of its events keeps the way back.
- Add events offers your planned and maybe events during the trip, with feed suggestions on request.
- Deleting offers "trip only" or "trip and members", and undo restores either cleanly.

### People and Availability

- People are linked to events in the editor or with "with X" in quick add.
- People page: usage stats, next event, notes, rename (renaming onto an existing person merges them), delete, and a per-person event list that jumps the calendar.
- Away and busy times per person, which you keep yourself (not read from their calendar), entered in the UI or by typing "Sam away Aug 10-15" into quick add.
- They show as labeled bands across the calendar; the sidebar's People section controls whose bands show (all, none, custom), with per-person solo.
- The editor warns when you schedule an event with someone who's away.
- An availability check in the API for agents ("is Sam free Thursday?").

### Calendars, Folders and Subscriptions

- Local calendars with color, default reminders and per-calendar settings.
- Calendar roles: Mine (things you do), Opportunities (things you could do) or Context (information). The role sets each event's default For me (Planned, Available or Context), and changing it re-reads every event at once. [Calendar roles and event relationships](docs/relationships.md) explains them.
- Folders group calendars with collapse and folder-level visibility; a calendar can live in several folders.
- ICS subscriptions with a poll interval per calendar (every 15 minutes to every 24 hours, hourly by default; the API allows 5 minutes), manual refresh, feed health in the sidebar, and adopt-as-local, which turns a feed into an editable calendar.
- A "new" mark on events that arrived on their own (feed, email, import, agent, plugin) in the last 24 hours, never on a calendar's first load.
- Near-duplicate grouping, where you turn it on: same-day listings whose titles differ only by a trailing parenthetical collapse into one stacked chip with a popover of members.
- The same event, shown once: when an event reaches you by two routes (a feed and a forwarded confirmation, a Takeout import and its Google calendar), the most live copy shows, with "Also on" naming the others. Near-matches go to Review as possible duplicates. Nothing is merged or deleted, and "Not the same" undoes a wrong match.
- Triage on feed events (Planned, Maybe, Hide) right in the agenda, with More like this and Less like this beside it.
- Tags on events (and on calendars through the API).

### Search, Filters and Ranking

- Instant search with click-through that jumps to the result and flashes it. Upcoming (the default), All or Past, and a calendar, are chosen under the box and applied before the result limit; a repeating series shows its next date. Tag chips run a tag-only search.
- The on-page filter works as you type: it hides non-matching events and rings the matches. In month and 3-day views (and the all-day row of week view) the matches take the day's slots, and "+N" counts only matches that didn't fit. A dim filter grays events out instead; they stay visible after the rest, fill only leftover slots and aren't counted in "+N".
- Prompt filters: describe a rule in plain English and an LLM applies it to events as a filter.
- Trainable ranking: thumbs up and down and attendance teach a ranker that orders busy feeds by predicted interest; the agenda has a match-sort mode.
- The Show filter switches Planned, Maybe, Available and Context events on and off (`p`, `m`, `a`, `x`); a mark on a day says when it's hiding some of that day's events.

### Activity Log and Undo

- Every change is logged with its source, a readable summary, and before and after snapshots.
- Sources: web UI, quick add, CalDAV (phone and desktop sync), RSVP, Review decisions, agent API, feed polls, email ingest (with tier), bulk import, geocoding, plugins, duplicate matching, and system events (sign-in blocks, new reminder devices, provider failures).
- The Activity page: newest first, All, Manual and Automated groups, a filter chip per source, live text filter, and load-more. Event entries click through to the event.
- Feed polls log one entry per poll with counts and added titles; a poll that changed nothing logs nothing.
- Undo per entry for 7 days, with a guard that warns before an old edit overwrites newer ones. Log entries are kept 90 days. Feed-poll summaries and edits to connected Google calendars are log-only, and the app says "can't be undone" before a Google edit.

### Email Ingest and RSVP

- A dedicated ingest mailbox, read every 2 minutes; forward event emails to it, by hand or with a filter. [Email ingest](docs/email-ingest.md) has the setup.
- Events are read by the most reliable route a message offers: invitation files, schema.org data, embedded add-to-Google links (which survive forwarding), then an LLM pass over the text.
- Guards so a forward's own timestamp can never become the event date, and no event is invented without a date in the message.
- A Review queue instead of silent changes: when an organizer emails a change or a cancellation for something already on your calendar, it waits with a from/to view of exactly what would change. Email isn't authenticated, so nothing an email says can move or cancel your event until you accept it, and accepting is an ordinary, undoable edit. Forged organizers and replayed old versions are refused and logged.
- One inbox for every decision: held invitation changes, unanswered invitations (reply inline), possible duplicates and plans your plugins suggest, with a count in the sidebar and a notification when something arrives. Agents get the same list, each item with its own actions.
- RSVP buttons send real invitation replies through your own SMTP (your Gmail, say), so responses come from you. They appear only where a reply can go; otherwise the event says why (no organizer, no account to send from, or you weren't the invited address). A failed send keeps your answer and offers Retry.
- Invitations on a connected Google calendar are answered at Google, which tells the organizer.
- Bookings (a reservation, ticket or confirmation) read "Booking via" the site instead of offering replies that could never send.
- Emailed times are read on the clock of the place they're about ("3 PM" for a Tokyo booking is Tokyo time), else the zone your device last reported, then Home.
- Ingested events carry the invitation panel (organizer, attendees, your status) and land on an Invitations calendar.
- A per-message ingest log (a database table) with tier and outcome, for debugging.

### Leaving Google: Link Capture and Migration

- The `/add` link takes Google Calendar template URLs, and the Chrome extension redirects "Add to Google Calendar" links to it automatically.
- On phones, the installed app is a share target (share a page or text to it), and pasting a GCal link into quick add works. In desktop Chrome and Edge it also opens webcal links and .ics files (Android doesn't hand those to installed web apps).
- A `/subscribe` link for one-tap feed subscriptions.
- A bulk importer (a server command) for the Google Calendar export (or Takeout): one calendar per file, names cleaned up, full recurrence kept. Proven on a migration of more than 8,000 events across 10 calendars. In the app, any single .ics file imports from the sidebar or the welcome.
- Feed adoption turns a subscribed calendar into a local, editable one when you're ready to cut the cord.
- [Moving from Google Calendar](docs/migration.md) walks through the options.

### Notifications and Reminders

- Per-event, per-calendar and default reminders, each overriding the next.
- Each calendar chooses its reminders: your defaults (they follow Settings), none, or its own. Until you choose, its role decides: your own calendars use your defaults wherever they live (here, Google, a feed), and Opportunities and Context calendars stay quiet, so a busy feed or sunsets don't all remind.
- Web Push to the installed app or any browser, email, or both, including push with email as a fallback for unreachable devices.
- Separate defaults for timed and all-day reminders (all-day events can remind the evening before, say).
- A Home time zone: all-day reminders fire on your home clock whatever zone an event came in with, and the app tells you once when the device you're on keeps a different clock.
- Reminders give the time on your device's clock (and the event's own clock when it keeps a different zone), in your 12- or 24-hour setting.
- Settings lists every device reminders go to by name ("Mac · Chrome"), with Remove for any you don't recognize, and test buttons for push and email.

### Sync, API and Integrations

- A CalDAV server (sabre/dav) for iOS, Android (DAVx5), macOS and Thunderbird, with proper sync tokens. [CalDAV](docs/caldav.md) has the setup.
- A REST API with personal access tokens. Agents get the endpoints the UI uses, apart from account-security steps that need you signed in with your password (linking Google, signing out other browsers), and their writes are tagged in the activity log. An MCP server wraps it for AI assistants ([agent API](docs/agent-api.md)).
- Outbound ICS feeds: share all events, one calendar or a search result at a standing URL.
- A Google connector (optional; each install sets up its own free Google Cloud client): connect a Google account to read the calendars you own, subscribe to or have been shared, including private shared ones no iCal address reaches. Where you can edit, changes go to Google first (write-through, not two-way sync). [Google Calendar connector](docs/google-calendar.md) has the setup.
- Move a calendar to Google: a calendar that started here is created in your Google account with every event, no one is mailed, and it keeps its tags, people, reminders and trips. "Adopt as local calendar" brings it back.
- Open browsers check for changes every 30 seconds, and at once when you come back to the tab, so changes from any device or automation show up without a reload.
- The installable app opens without a connection (showing what it last loaded), is a share target, opens webcal links and .ics files (desktop Chrome and Edge), and has a "New event" shortcut on its icon.

### Plugins

- Bundled server-side plugins, each turned on and set up on the Plugins page, which shows its permissions, last run, what it has made, any warnings, Run now, and uninstall with a preview of what goes.
- Weather: a daily forecast from Open-Meteo (no API key) with an icon and the high and low, plus the day's air quality (US AQI); severe weather and unhealthy air raise warnings.
- Sun: sunrise and sunset for a place, computed on the server (no network, no key), in each day's header and as a line on the timeline.
- Earlier-stage ones: Tides (NOAA predictions for US stations, with daylight low-tide windows), Travel Time (leave-by bands before events you travel to, and warnings when back-to-back events are too far apart), Calendar Lint (a daily check of the next 30 days for impossible travel, events outside your preferred hours and suspected duplicates), Day Planner and Trip Planner (propose an outing on an open weekend day, or the best free window for a longer trip using the destination's climate normals), and Visit Intents (shows free, open-hours stretches for places you mean to go, with links out to booking).
- Proposals wait in Review; nothing reaches your calendar until you press Accept.
- [Writing a plugin](docs/plugins/authoring.md) is the contract for your own.

### Self-Hosting and Privacy

- Plain PHP 8.4+ with MySQL or MariaDB on your own server (Nginx and Apache tested, and ordinary shared hosting, with a setup page for hosts without SSH); no framework, no build step, no telemetry. [Installing](docs/install.md) has the steps.
- A first-run welcome confirms the basics from your device (Home time zone, week start, 12- or 24-hour clock, theme, the first calendar's name) and offers places to start: subscribe, import, connect Google, install the app, turn on reminders, browse plugins. Every step can be skipped, and Settings can show it again.
- `php server/bin/demo-seed.php` fills an empty account with invented calendars, events and people around today, for a first look.
- Your data is ordinary SQL you can query, back up and take with you.
- LLM features are optional and need a provider key; the rule-based paths always work without them.
- Single-user by design, with session sign-in, CSRF protection, and revocable, expiring API tokens; feeds, devices and subscriptions a token created stop with it.
- Sign out everywhere else (Settings, Account), for a lost device, and password guessing limited per network address without ever locking you out of your own account. Connecting Google or moving a calendar there asks for your password again if this browser hasn't confirmed it in the last ten minutes. [SECURITY.md](SECURITY.md) has what each does and what to do if a device is lost.
