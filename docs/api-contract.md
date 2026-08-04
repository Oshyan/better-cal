# Better-Cal API Contract (v1)

Base path `/api/v1`. JSON everywhere. Auth: `bc_session` cookie; non-GET requires `X-CSRF` header. Alternative: `Authorization: Bearer bc_...` personal access token (CSRF-exempt; see Tokens). Errors: `{"error":{"code","message"}}`.

## Auth
- `POST /auth/login` `{email, password}` → `{ok:true}` + sets cookie. 401 on failure.
- `POST /auth/logout` → `{ok:true}`.
- `GET /me` → `{user:{id,email,displayName,settings}, csrf}` or 401. (`csrf` is null under bearer auth.) `settings` is the same merged object returned by `GET /settings` (stored values over defaults).

## Personal access tokens
Token value: `bc_` + 43 url-safe base64 chars; stored sha256-hashed, shown once at creation. Bearer requests skip CSRF. Management endpoints are session-auth only: a bearer token gets 403 `session_required`.
- `GET /tokens` → `{tokens:[{id,name,createdAt,lastUsedAt}]}`.
- `POST /tokens` `{name}` → `{id,name,token}` (token shown once).
- `DELETE /tokens/:id` → `{ok:true}`.
- CLI equivalent: `php server/bin/token.php --create --name=X | --list | --revoke=ID`.
- Agent-facing guide: [agent-api.md](agent-api.md); MCP server in `tools/mcp/`.

## Calendars & structure
- `GET /calendars` → `{calendars:[{id,name,color,kind,sourceUrl,visible,position,pollIntervalMinutes,staleAfterDays,folderIds:[],tagNames:[],groupSimilar:boolean,reminderDefaults:object|null,health:{lastPolledAt,status,error,stale:boolean}}], folders:[{id,name,position}], tags:[{id,name}]}`
- `POST /calendars` `{name,color,folderIds?,tagNames?}` → calendar object.
- `PATCH /calendars/:id` (any of name,color,visible,position,folderIds,tagNames,pollIntervalMinutes,staleAfterDays,groupSimilar,reminderDefaults) → calendar object.
- `reminderDefaults` (per calendar, persisted in `calendars.settings_json`): `{timed:[{minutes:int}], allDay:[{daysBefore:int, time:"HH:MM"}]}` or `null` to clear (fall through to the global defaults in Settings). A missing key inside the object means "none" for that event type. See Reminders below for how defaults resolve.
- `groupSimilar` (boolean, per calendar, persisted in `calendars.settings_json`): whether the client should visually group near-duplicate events on this calendar. Default when never set: `true` for `kind=subscribed`, `false` for `kind=local`. The server only stores and returns the flag; the grouping itself is client-side.
- `DELETE /calendars/:id` → `{ok:true}` (soft: deletes calendar + events).
- `POST /calendars/subscribe` `{url,name?,color?}` → calendar object; server fetches immediately, then polls hourly.
- `POST /calendars/:id/refresh` → `{ok:true, imported:N}` (force poll now).
- `POST /calendars/import` multipart file `ics` + `name?` → calendar object + `{imported:N}`.
- `POST /folders` `{name}` / `PATCH /folders/:id` / `DELETE /folders/:id`.

## Events
- `GET /events?start=ISO&end=ISO&calendars=1,2&q=&includeHidden=0` → `{events:[occurrence...]}`
  Occurrence: `{instanceId, eventId, calendarId, uid, title, description, location, locationLat, locationLng, url, start, end, allDay, tzid, recurring:boolean, rrule, source, attendance, status, score, reminders:[], reminderSource, tags:[], people:[], isContainer:boolean, containers:[{eventId,title}], styleJson, createdAt, updatedAt, isNew:boolean}`
  `isContainer` marks trip events; `containers` lists the trips this event is attached to as `{eventId, title}` pairs (usually 0 or 1 — the schema is n:m but v1 UX keeps one trip per event). Both are batch-loaded server-side (like tags); see Trips below.
  `reminders` is the event's *effective* reminder list (override if set, else the calendar default, else the global default) and `reminderSource` says where it came from: `"event"|"calendar"|"default"`. Entries are `{minutes:int}` (offset before start; before local midnight for all-day events) or, for inherited all-day defaults, `{daysBefore:int, time:"HH:MM"}`. See Reminders below.
  `locationLat`/`locationLng` are numbers or null (stored coordinates for the free-text location; the client stores them directly when the user picks a candidate from `GET /geocode/search`, and otherwise resolves them lazily via `GET /geocode` and PATCHes them back onto local events). `rrule` is the master's raw RRULE string or null (present on every expanded occurrence of a recurring master; null on overrides) — clients use it for the human-readable recurrence description in the detail view.
  `score` is a number 0-1 or null: the background trainable-ranking score (feed events only; null until the worker has scored the event or while fewer than 5 feedback signals exist). Clients sort by it client-side; the API's order is unchanged.
  Additive fields: occurrences matching an enabled `dim` filter carry `"dimmed": true`, occurrences matching an enabled `highlight` filter carry `"highlighted": true`; the fields are absent otherwise. Occurrences matching an enabled `hide` filter are omitted from `/events` and `/search` responses entirely. Prompt-filter verdicts (see Filters) feed the same mechanics with the same precedence (hide beats dim beats highlight).
  `includeHidden=1` returns occurrences normal responses omit: `attendance:"hidden"` events AND occurrences an enabled `hide` filter (keyword/regex or prompt) would drop. Reminders fire regardless of filters, so the notification deep-link handler uses this to resolve the occurrence a notification points at.
  `q=` matches title/description/location as a substring and also tag names; a `q` equal to or prefixed with `#` (e.g. `#work`) matches tags only. Same treatment as `/search`.
  `start`/`end` are ISO8601 with offset. Recurring events arrive pre-expanded; `instanceId = eventId + ":" + occurrenceStartUtc` where occurrenceStartUtc uses compact UTC basic format `YYYYMMDDTHHMMSSZ` (e.g. `42:20260801T190000Z`). This format is FROZEN; clients treat instanceId as opaque and use the occurrence's `start` field when an API call needs `instanceStart`.
- `POST /events` `{calendarId,title,start,end,allDay?,tzid?,description?,location?,locationLat?,locationLng?,url?,rrule?,reminders?,tagNames?,personNames?,isContainer?}` → occurrence (first instance). `locationLat`/`locationLng` (numbers or null) store coordinates directly when the client picked a place from `GET /geocode/search`; omitted/null means "resolve lazily later".
  `description` is ONE field holding either plain text or rich HTML (the editor writes HTML). The server sanitizes any markup on create/PATCH through an allowlist (`p, br, b, strong, i, em, u, a[href http/https], ul, ol, li, div`; all attributes, styles, classes and script-bearing tags stripped, unknown tags unwrapped to their text). Plain text without markup is stored verbatim. Clients render descriptions containing tags as sanitized HTML and plain text with line breaks otherwise.
- `PATCH /events/:id` body same fields plus `{locationLat?, locationLng?}` (numbers or null) and `{scope:"this"|"following"|"all", instanceStart?}` (scope required when event is recurring) → `{ok:true}`.
  `reminders`: list of `{minutes:int}` (0-40320, max 5, normalized unique/ascending) sets a per-event override; `[]` means explicitly no reminders; `null` clears the override back to inherited defaults. Like attendance and tags, `reminders` is accepted on feed events (it is user-local metadata and survives feed polls).
  `isContainer` (boolean) sets or clears the trip flag. Clearing requires the trip to have no attached members, else 400 `trip_has_members` (detach them first). Feed events cannot become trips (`isContainer` counts as a content edit → 403 `feed_readonly`), though they can be trip members.
- `DELETE /events/:id` `{scope?,instanceStart?}` → `{ok:true}`. Deleting a trip detaches its members — they survive on their own calendars; only the links go. "Delete trip and its events" is a client-side two-step: the client deletes each member first (after the user picks that option in the confirm), then the trip itself.
- `POST /events/:id/attendance` `{attendance:"none"|"interested"|"going"|"hidden"}` → `{ok:true}`. On feed events an attendance change also auto-records a ranking feedback signal (`going`/`interested` → up, `hidden` → down; `none` records nothing).
- `POST /events/:id/feedback` `{signal:"up"|"down"}` → `{ok:true}`. Explicit thumbs feedback (distinct from attendance); accumulates as training signal for background ranking. Not undoable.
- `POST /quickadd` `{text, tz, commit?:boolean, calendarId?}` → `{draft:{title,start,end,allDay,location,personNames:[],calendarId,confidence,source:"llm"|"fallback"}}`; if `commit:true` also creates and returns `{event}`.
  Parsing is deterministic-first: the fallback parser always runs, and the LLM is consulted only per the `nlParseMode` setting (see Settings). `smart` (default): the LLM runs only when the deterministic parse is incomplete (no resolved date+time / date+allDay) or its confidence is below 0.75 — a complete confident parse returns immediately with `source:"fallback"`. `always`: LLM-first with deterministic fallback on failure. `never`: deterministic only. LLM failure never blocks; the fallback draft is returned.
- `POST /undo` → `{ok:true, undone:{entity,op}}` or 404 if nothing to undo.

## Trips (event links)
A trip (docs/design-containers.md) is a normal event with `isContainer:true` that groups other events via the `event_links` table. Attachment is a relationship, not ownership: members keep their own calendar, colors, times and lifecycle. Feed events can be members (the link is user-local metadata, like tags and reminders, and survives feed polls). Same-calendar membership works exactly like cross-calendar membership. One nesting level only: a trip can never be attached to another trip. The schema permits an event in several trips; v1 UX restricts to one. Span growth is a client concern: attaching an event outside the trip's dates prompts "Extend trip to include this?" — the server never grows the span silently.
- `POST /events/:id/links` `{eventId}` → `{ok:true}` (201). Attaches `eventId` to trip `:id`. 400 `trip_invalid` when `:id` is not a trip, when `eventId` is itself a trip, or on self-attach; idempotent no-op when already attached; 404 when either event is missing or not yours.
- `DELETE /events/:id/links/:eventId` → `{ok:true}`; 404 when the event is not attached.
- `GET /events/:id/links` → `{events:[occurrence...]}` — every member of the trip, chronological by start (flights out and back land first and last naturally). Deliberately non-windowed: the trip detail view needs the full list regardless of the visible range. Recurring members appear once as their master occurrence, not expanded. 400 `trip_invalid` when `:id` is not a trip.
- Attach/detach are undoable via `POST /undo` (entity `event_links`) and journal a CalDAV MODIFY on both the trip's and the member's calendar objects so DAV clients refresh their `RELATED-TO` lines.
- CalDAV/ICS export: a trip VEVENT carries one `RELATED-TO;RELTYPE=CHILD:<member-uid>` line per member, and each member VEVENT carries `RELATED-TO;RELTYPE=PARENT:<trip-uid>` (RFC 5545; survives round-trips with clients that preserve the property, degrades harmlessly elsewhere). Outbound `/feed/{token}.ics` gets the same treatment. ICS/feed **import ignores `RELATED-TO`** for now: trip membership is created only through this API, never from inbound calendar data.

## Search
- `GET /changes/cursor` → `{cursor}` — opaque string that changes whenever the user's events (via calendar synctokens: API, CalDAV, feed polls, mail ingest, RSVP), availability spans, or people change. Clients poll it (~30s while visible, immediately on focus/online) and refetch the window + people + availability only when it moves.
- `GET /search?q=text&limit=50` → `{results:[occurrence...]}` (FULLTEXT, past included, ranked, newest window first). Tag names and people names match too; `#term` searches tags only, `@term` searches people only.
- `GET /people` → `{people:[{id, name, notes, eventCount, nextStart}]}` — everyone linked to events (alphabetical; `eventCount` counts undeleted events, `nextStart` is the soonest future start in UTC ISO or null). People rows are created implicitly by `personNames` on event create/patch and quick-add "with X" clauses; there is no explicit create.
- `PATCH /people/:id` `{name?, notes?}` → `{id, name, notes}`. Renaming onto an existing person's name MERGES the two (links move to the survivor, the renamed row is deleted, the survivor is returned).
- `DELETE /people/:id` → `{ok:true}` — removes the person and their event links; events are untouched.
- `GET /people/:id/events` → `{results:[occurrence...]}` — that person's events (undeleted, newest first, limit 200), same shape as `/search` results.
- `POST /people` `{name, notes?}` → `{id, name, notes, created}` (201 when new; returns the existing person with `created:false` when the name is already taken — idempotent explicit creation for the People page and New menu).
- People availability (docs/design-availability.md): `GET /people` rows also carry `showOnCalendar`, `currentSpan` (the away/busy span covering now, or null) and `nextSpan`; `PATCH /people/:id` accepts `showOnCalendar`. Spans: `GET /people/:id/availability` → `{spans:[{id, personId, start, end, kind:"away"|"busy", note}]}` (start/end UTC ISO, end exclusive); `POST /people/:id/availability` `{start, end, kind?, note?}` → span (kind defaults to away); `DELETE /people/:id/availability/:spanId`. `GET /availability?start&end` → `{spans:[span + name]}` for people with `showOnCalendar` (calendar bands). `GET /availability/check?start&end&names=A,B` → `{conflicts:[span + name]}` overlapping spans for the named people regardless of visibility (editor scheduling assist).
- Occurrences carry `invite` (nullable): `{method, organizer:{email,name}, attendees:[{email,name,partstat}], sequence, myPartstat}` for mail-ingested invitations (docs/email-ingest.md). `POST /events/:id/rsvp` `{answer: accepted|tentative|declined}` → `{myPartstat, sent}` — records the answer and emails an iMIP REPLY to the organizer (best-effort; `sent:false` when no organizer or no SMTP).
- `POST /quickadd` with a pasted Google Calendar template link (`calendar.google.com/calendar/render?...` or `/r/eventedit?...`) parses the link's own parameters (`text/dates/details/location/ctz/recur`) into the draft (`source:"gcal-link"`, includes `description` and `rrule`); `commit:true` creates it. The `/add` SPA path feeds the same parser (browser-extension redirect target), `/subscribe?url=` prefills the subscribe drawer (webcal handled), `/share` is the Web Share Target, `/import` + the manifest file handler accept .ics files.
- `POST /quickadd` with text reading as an availability statement ("NAME is away|busy|out|gone|traveling DATES", NAME matching an existing person exactly) returns `{draft:{intent:"availability", personId, personName, kind, start, end, allDay, confidence, source}, event:null, availability}`; with `commit:true` the span is created (`availability` carries it) and no event is made.
  Tag names match too (case-insensitive substring over the user's tags, joined via `event_tags`): tag-matched events are included after text-relevance matches. A `q` equal to or prefixed with `#` (e.g. `#work`) searches tags only and returns tag-matched events newest first.

## Geocode
- `GET /geocode?q=free+text+location&lat=&lng=&tz=` → `{lat, lng, display}` (numbers + string, or all null when nothing was found). Proxies photon.komoot.io (no key, 3s timeout, User-Agent `Better-Cal/0.1 (self-hosted)`), single best result. Location bias uses the same precedence as `/geocode/search` (explicit `lat`/`lng` > `tz` centroid > none); with a bias the top 5 candidates are re-ranked by the PlaceSearch order-plus-distance blend before picking the winner. A `q` of exactly three uppercase ASCII letters is treated as an IATA airport code and resolved against the vendored OurAirports table (`server/data/iata-airports.php`, ~9k airports, offline and authoritative — Photon cannot resolve IATA codes), falling back to a plain biased query when the code is unknown. Results are cached permanently in `geocode_cache` keyed by a sha256 of the lowercased whitespace-normalized query plus the integer-degree bias cell (same text can resolve differently per region); provider "no result" answers are cached as negative rows, transport failures are returned as not-found but never cached. Empty `q` → 400. The client calls this lazily when the event detail view opens with a location and no stored `locationLat`/`locationLng` (sending `homeLat`/`homeLng` when set, else the event's `tzid`), then PATCHes resolved coordinates onto local events (feed events keep the result transient).
- `GET /geocode/search` with a `q` of exactly three uppercase ASCII letters additionally pins the IATA-table airport above the biased general results (with `distanceKm`/`far` still computed against the bias).
- `GET /geocode/search?q=&lat=&lng=&tz=&limit=6` → `{results:[{name, address, lat, lng, display, city, distanceKm, far}]}` — multi-candidate place autocomplete for location pickers (same Photon proxy, timeout and UA; uncached, limit clamped 1-10, default 6). `address` is a concise composition of street+housenumber, city, state, country (name excluded, repeats deduped); `display` = `name, address`; `city` is nullable. Location bias precedence: explicit `lat`/`lng` (the client sends the `homeLat`/`homeLng` settings when set) > `tz` (an IANA zone id resolved server-side to an approximate centroid; ~60 major zones, unknown zones mean no bias) > none. With a bias, candidates are re-ranked by blending Photon's order with distance and each carries `distanceKm` plus `far: true` beyond 500 km so the UI can flag matches half a world away; without a bias `distanceKm` is null and Photon's order is kept. Transport failures return `{results:[]}`, never an error.

## Filters
Keyword/regex/prompt filters applied server-side to `/events` and `/search`. Keyword = case-insensitive substring; regex = PCRE, evaluated case-insensitively. Scope `global` applies to everything, `folder` to every calendar in the folder, `calendar` to that calendar (`scopeId` required for folder/calendar scope). Action `hide` removes matching occurrences; `dim` adds `"dimmed": true` to them; `highlight` adds `"highlighted": true` (accent emphasis client-side). Across all filter types the strongest action wins: `hide` beats `dim` beats `highlight` (an event matching both a hide and a highlight filter is hidden).
- `GET /filters` → `{filters:[{id, scope:"global"|"folder"|"calendar", scopeId, type:"keyword"|"regex"|"prompt", config, action:"hide"|"dim"|"highlight", enabled:boolean}]}`.
  `config` by type: keyword/regex `{pattern, fields:["title"|"description"|"location"|"tags", ...]}`; prompt `{prompt, negativePrompt?, threshold?}`. The `tags` field matches the pattern against each of the event's tag names.
- `POST /filters` `{scope, scopeId?, type, config, action?, enabled?}` → filter (201). `config.fields` defaults to title/description/location (`tags` is opt-in). Invalid regex → 400 `filter_invalid_regex`. Prompt: `config.prompt` required (max 2000 chars), `negativePrompt` optional, `threshold` optional number 0-1 (when set, the LLM score decides pass/fail at that cutoff instead of the model's boolean).
- `PATCH /filters/:id` (any of scope, scopeId, type, config, action, enabled) → filter. Changing a prompt filter's type/config invalidates its cached verdicts and re-evaluates in the background.
- `DELETE /filters/:id` → `{ok:true}`.

Prompt filters are never evaluated in the request path. A background worker job (`filter_eval`) scores feed events against the prompt/negative prompt in Gemini batches (≤25 events per call) and caches per-(filter, event) verdicts in `filter_evals`; `/events` and `/search` join that cache and apply the filter's action to events whose verdict is `fail`. Coverage is all in-scope feed events within a wide window — 2 years back to 3 years forward (recurring masters always qualify) — not just future events, so browsing past months is filtered too. Sweeps process up to 500 events per filter per pass and chain follow-up jobs until the window is drained. Evaluation triggers: prompt-filter create/update/enable (catch-up sweep over the whole window), each feed poll that changes events, a nightly catch-up, and on-read healing: when `GET /events` returns feed events that an enabled prompt filter covers but has no cached verdict for, the server enqueues one targeted `filter_eval` job for those event ids (capped at 300 ids per enqueue, deduped by a payload hash against pending/running jobs; works even outside the sweep window). A missing or not-yet-computed verdict is treated as pass — LLM failures never hide events or block responses. Related: a `rank_events` job (hourly and after polls) uses accumulated feedback signals (≥5 required) to score future feed events onto `occurrence.score`.

## Reminders & Web Push

Reminders are a core feature and must fire with the app closed, so delivery is Web Push: the cron worker enqueues a `reminder_scan` job every minute that expands upcoming occurrences (35-day lookahead, recurrence-aware), computes each reminder's fire time, and sends a push to every subscription of the event's owner for reminders due since the last minute (1-hour catch-up grace; nothing older fires). Sent reminders are deduplicated in `notified_instances` keyed `eventId:occurrenceStartUtc:offsetMinutes` (rows pruned after 7 days). The service worker shows the notification (`tag` = instanceId, so re-sends replace rather than stack) and clicking it opens `/?event=instanceId&at=ISO` (`at` = the occurrence's start instant, UTC `YYYY-MM-DDTHH:MM:SSZ`, additive since the `event`-only form), which the app resolves to that event's detail view: it loads a window around `at` and, if the occurrence is still missing (concurrent view load, hidden attendance, or a hide filter), retries once with a tight `GET /events` window around `at` with `includeHidden=1`.

Resolution order per event: the event's `reminders` override (`[]` = explicitly none) > the calendar's `reminderDefaults` > the global `reminderTimed`/`reminderAllDay` settings. Exception: subscribed (feed) calendars never inherit the global defaults — feeds only remind when the calendar has explicit `reminderDefaults` or an event has an override, so subscribing to a busy feed does not flood notifications. Events with `attendance:"hidden"` or `status:"cancelled"` never remind.

Fire-time semantics: `{minutes}` = minutes before the start instant (for all-day events, before local midnight of the event date in its tzid). `{daysBefore, time}` (all-day defaults) = that wall-clock time in the event's tzid, daysBefore days before the event date (e.g. `{daysBefore:1, time:"18:00"}` = 6 PM the evening before).

Delivery channels: the `notifyChannel` setting (see Settings) picks how each due reminder is delivered. `push` (default) = Web Push only; `email` = a reminder email only (to the `notifyEmail` setting when set, else the account address); `both` = both; `push-fallback` = push normally, with an email only when no non-failing subscription exists or every push send that attempt was rejected/gone (a push the service *accepted* but never displayed — device unreachable past the TTL — cannot be detected, so fallback email does not cover it). One `notified_instances` row marks the reminder sent regardless of channel, so retries never double-send after a partial success. Email failures are logged and never block the scan. Reminder emails carry the event title, the local time string, location, and a button deep link (absolute against `BETTERCAL_BASE_URL`).

ICS: file import maps display VALARMs with before-start relative triggers into per-event overrides; export (outbound feeds, CalDAV objects) writes a display VALARM per override entry. Inherited defaults and explicit-none export nothing. Feed polls do not sync VALARMs (user-local overrides must survive polls).

Server config: VAPID keys in `.env` (`BETTERCAL_VAPID_PUBLIC`, `BETTERCAL_VAPID_PRIVATE`, `BETTERCAL_VAPID_SUBJECT`), generated once with `php server/bin/vapid.php --generate`. Sending uses `minishlink/web-push` (composer). Email delivery is optional and separate: SMTP via `BETTERCAL_SMTP_HOST`, `BETTERCAL_SMTP_PORT` (default 587, STARTTLS; 465 = implicit TLS), `BETTERCAL_SMTP_USER`/`BETTERCAL_SMTP_PASS` (only when the relay needs auth), `BETTERCAL_SMTP_FROM` (sender address, display name "Better-Cal"); sending uses `phpmailer/phpmailer` (composer). All SMTP vars optional — email stays off gracefully while host or from is empty.

- `GET /push/key` → `{key:string|null}` (VAPID public key; null when unconfigured).
- `GET /push/status` → `{subscribed:boolean, vapidConfigured:boolean, emailConfigured:boolean}`.
- `POST /push/subscribe` `{endpoint, keys:{p256dh, auth}}` → `{ok:true}` (upsert by endpoint; re-subscribing clears failure state).
- `POST /push/unsubscribe` `{endpoint}` → `{ok:true}`.
- `POST /push/test` → `{ok, sent, failed}`; 400 `push_not_configured` / `no_subscription`.
- `POST /push/test-email` → `{ok:true, to}` (test reminder email to the account address); 501 `email_not_configured` when SMTP is not set up, 400 `email_send_failed` when the relay rejects the send.
- Failure handling: gone endpoints (404/410) mark `failing_since`; subscriptions failing for 3+ days are deleted by the worker.

## Settings
User preferences stored in `users.settings_json`. The server stores and validates; enforcement is client-side except `nlParseMode`, which the server applies in `/quickadd` (see Events). Reads always return stored values merged over defaults; keys never set come back as their defaults. Settings changes are not undoable via `POST /undo`.
- `GET /settings` → `{settings:{defaultView, weekStart, timeFormat, defaultCalendarId, theme, nlParseMode, notifyChannel, notifyEmail, folderVisibility, reminderTimed, reminderAllDay, homeLat, homeLng, homeLabel, mapStyle}}`.
- `PATCH /settings` (any subset of the keys below) → `{settings:{...}}` (the full merged object). Unknown keys → 400 `unknown_setting`; invalid values → 400.

| Key | Values | Default | Notes |
|---|---|---|---|
| `defaultView` | `month`\|`multiweek`\|`week`\|`day`\|`agenda` | `month` | view opened on load |
| `weekStart` | `mon`\|`sun` | `sun` | first day of week in grids |
| `timeFormat` | `"12"`\|`"24"` | `"12"` | accepted as string or number, stored/returned as string |
| `defaultCalendarId` | id of an owned `local` calendar, or `null` | `null` | validated for ownership + kind; `null` clears |
| `theme` | `system`\|`light`\|`dark` | `system` | |
| `nlParseMode` | `always`\|`smart`\|`never` | `smart` | server-enforced in `/quickadd`: `smart` = LLM only when the deterministic parse is incomplete/low-confidence, `always` = LLM-first, `never` = deterministic only |
| `notifyChannel` | `push`\|`email`\|`both`\|`push-fallback` | `push` | reminder delivery channel, server-enforced by the reminder scan (see Reminders: Delivery channels) |
| `notifyEmail` | email address (≤254, `FILTER_VALIDATE_EMAIL`) or `null` | `null` | where reminder and test emails are sent; `null`/blank = the account email (the SMTP sender mailbox) |
| `reminderTimed` | list of `{minutes:int}` (0-40320, max 5) | `[{minutes:10}]` | global default reminders for timed events; server-enforced by the reminder scan (see Reminders) |
| `reminderAllDay` | list of `{daysBefore:int, time:"HH:MM"}` (0-28 days, max 5) | `[{daysBefore:1, time:"18:00"}]` | global default reminders for all-day events, fired in the event's tzid |
| `homeLat` | number -90..90 or `null` | `null` | home location latitude; with `homeLng`, the bias point for `/geocode/search` |
| `homeLng` | number -180..180 or `null` | `null` | home location longitude |
| `homeLabel` | string (≤200) or `null` | `null` | display name for the home location (Settings page only) |
| `mapStyle` | `streets-v2`\|`dataviz`\|`outdoor-v2`\|`bright-v2` | `streets-v2` | MapTiler raster style for the event detail mini-map; only used when the server has `BETTERCAL_MAPTILER_KEY` configured (see `GET /config`) |

## Saved views
Named snapshots of client view state; `config` is client-defined: `{viewType, visibleCalendarIds, folderCollapse, filterText, anchor:"today"|dayKey}`.
- `GET /views` → `{views:[{id, name, config, position}]}`.
- `POST /views` `{name, config}` → view (201).
- `PATCH /views/:id` `{name?, config?, position?}` → view.
- `DELETE /views/:id` → `{ok:true}`.

## Outbound feeds
- `GET /outfeeds` → `{feeds:[{id,name,url,scope,description}]}` (envelope key is `feeds`). `POST /outfeeds` `{name, scope:{type:"all"|"calendar"|"search", calendarId?, q?}, description?}` → `{id,name,url,scope,description}`.
- `DELETE /outfeeds/:id`.
- Public: `GET /feed/{token}.ics` (no auth; X-WR-CALNAME + description embedded).
- ICS export (outbound feeds, CalDAV feed objects built by `Ics::buildCalendar`): rich (HTML) descriptions write `DESCRIPTION` as tag-stripped plain text plus `X-ALT-DESC;FMTTYPE=text/html` carrying the sanitized HTML; plain text descriptions export exactly as before. ICS import is unchanged (descriptions imported as text).

## Activity log
- Every mutating code path journals to `mutations` via `Undo::record()`, tagged with a request-scoped source (`ActivityContext`): `web` (default), `api` (bearer-token), `caldav`, `quickadd`, `rsvp`, `import`, `feed` (poll rollups, log-only), `mail:imip|markup|gcal-link|llm` (ingest tiers).
- `GET /activity?before=&limit=&sources=&q=` → `{entries:[{id,at,source,entity,entityId,op,summary,details,undone,undoable}], nextBefore}`. Latest-first; `before` is a cursor (entry id), `limit` ≤ 100 (default 50). `sources` is comma-separated from `web,api,caldav,feed,mail,quickadd,rsvp,import` (`mail` matches all `mail:*` tiers). `q` substring-matches summaries. `undoable` = has snapshots, not undone, within the 7-day window.
- `POST /activity/:id/undo` body `{force?:bool}` → `{ok,undone:{entity,op}}`. Errors: `already_undone`, `not_undoable` (log-only entry: feed rollups, RSVP notes, or snapshots pruned past 7 days), `stale_undo` (newer un-undone mutations exist for the same entity — retry with `force:true` to overwrite them deliberately).
- `GET /events/:id/occurrence` → `{occurrence}` (single serialized occurrence; the Activity page's jump-to-event).
- Retention (worker `activity_prune`, daily): snapshots cleared after 7 days (entries become log-only), rows deleted after 90.
- Feed-poll rollups are one entry per poll with changes: `Feed 'Name': N added, M updated, K removed`, `details.addedTitles` (≤20). Log-only by design — undoing a feed import would be redone by the next poll.

## Misc
- `GET /health` (no auth) → `{ok:true,time,db:true,version}`.
- `GET /config` (auth) → `{maptilerKey, mapStyle}` — public-safe client configuration, fetched once at boot. `maptilerKey` is the optional MapTiler tile key from `BETTERCAL_MAPTILER_KEY` (null when unset; the mini-map falls back to OSM tiles); `mapStyle` echoes the requesting user's map style setting.

## Frontend state contract (bettercal-ui props)

bettercal-ui components receive plain occurrence objects (above) plus a `CalendarMeta` map `{id:{color,name,visible}}`, and emit intents: `onCreateRange({start,end,allDay})`, `onMoveEvent({instanceId,newStart,newEnd})`, `onResizeEvent({instanceId,newStart,newEnd,edge})`, `onOpenEvent(instanceId,anchorRect,opts?)` (double-click passes `opts={detail:true}` asking for the full detail view), `onExpandDay(dateISO)`, `onRequestWindow({start,end})` (infinite scroll data demand), `onSetAttendance(occ, attendance)` (agenda triage on feed events), `onFeedback(occ, "up"|"down")` (thumbs training signal on feed events). DayExpand additionally accepts `onOpenDetail(instanceId)` for its per-row open affordance. AgendaList also accepts `sortMode: "time"|"match"` (match mode renders the caller-ordered flat list, see `web/src/lib/rank.js` sortByMatch) and `scrollKey`/`scrollSeq` (each new seq scrolls the day-grouped list to the anchor day). All drag math lives in the library; persistence lives in the app layer.

Near-duplicate grouping is client-side: the app layer runs `web/src/ui/grouping.js` over occurrences from calendars with `groupSimilar=true`, replacing same-day same-calendar title-base duplicates with synthetic group items (`{isGroup:true, instanceId:"group:...", title, count, members:[occurrence...]}`); components render those as stack chips, never drag them, and route their `onOpenEvent` clicks to a group list popover.
