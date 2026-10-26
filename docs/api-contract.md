# Better-Cal API Contract (v1)

Base path `/api/v1`. JSON everywhere. Auth is the `bc_session` cookie, and every non-GET request also needs the `X-CSRF` header (the token comes from `GET /me`). The alternative is a personal access token, `Authorization: Bearer bc_...`, which is CSRF-exempt (see [Personal Access Tokens](#personal-access-tokens)). Errors are `{"error":{"code","message"}}`.

"Session only" below means a bearer token gets 403 `session_required`. "Recent password confirmation" means a password sign-in or `POST /auth/step-up` in the last 10 minutes; without it the answer is 403 `step_up_required`.

## Auth

- `POST /auth/login` `{email, password}` → `{ok:true}` and sets the session cookie.
  - 401 `invalid_credentials` on failure, with the same answer in the same time whether or not the account exists.
  - 429 `too_many_attempts`, with a `Retry-After` header and `error.retryAfter` seconds, when one network address has sent too many wrong passwords: 10 in 15 minutes by default (`BETTERCAL_LOGIN_MAX_FAILURES`), counted together with CalDAV Basic-auth failures, which get the same 429. Accounts are never locked, and a blocked attempt is refused before the password is checked.
  - Overall brake: when failures from all addresses pass 60 in 15 minutes (from 10 or more addresses), addresses that haven't signed in successfully in the last 30 days are refused until it subsides. Known addresses aren't, and neither is a browser presenting a valid `bc_device` cookie (the refusal message then says new devices are paused).
  - `bc_device` is a random cookie set on each successful sign-in (one year, `Path=/api/v1/auth`, httpOnly, SameSite=Strict, stored hashed), replaced on each sign-in and kept on sign-out. It authenticates nothing by itself; it only exempts a browser from the overall brake, and stops doing so after 3 wrong passwords sent with it in 15 minutes. A password reset forgets them all.
  - The client address is the connecting peer unless `BETTERCAL_TRUSTED_PROXIES` lists it (then the rightmost untrusted `X-Forwarded-For` entry). IPv6 addresses count per /64.
- `POST /auth/logout` `{pushEndpointHash?}` → `{ok:true}`. Session only. `pushEndpointHash` is this browser's SHA-256 push endpoint identity; when sent, that one reminder device is removed together with the session. The browser's push subscription itself stays, so signing in again restores reminders without another permission prompt.
- `POST /auth/step-up` `{password}` → `{ok:true}`: confirms the current session for sensitive actions for 10 minutes. Session + CSRF required. 403 `invalid_credentials`, 403 `session_required`, or the shared 429 `too_many_attempts`. A fresh password sign-in already counts as confirmed.
- `GET /auth/sessions` → `{others, version, subscriptions:[{id,name,host,addedAt,fingerprint}]}`: how many other browsers are signed in, and the calendar subscriptions other browser sessions added (subscriptions from before Better-Cal recorded who added them aren't listed). Session only.
- `POST /auth/sign-out-others` `{keepPushHash?, subscriptionReview:{version, choice:"keep"|"pause", reviewed:[fingerprint]}}` → `{sessions, devices, pushDevices, feedsRotated, notifyEmailReset, subscriptionsPaused, subscriptionsChanged}`. Session only.
  - Signs out every other browser, forgets other remembered browsers, removes other push devices (`keepPushHash` keeps this browser's), gives session-created public feeds new URLs, resets a reminder address set from a browser, and applies the subscription choice.
  - It always signs out. The choice applies to the reviewed fingerprints (a fingerprint covers feed, address and creating browser; renaming doesn't change it). An affected subscription that wasn't reviewed as it now is (added or repointed after the preview) is paused and counted in `subscriptionsChanged`. Without `reviewed`, a matching `version` covers every listed subscription and a stale one pauses them all; with no review or choice, all are paused.
  - Keeping confirms ownership. Pausing keeps the events and lists the feeds in Review. The calling browser, API tokens and token-created channels are unchanged. Ordinary logout and session expiry pause nothing.
- `GET /me` → `{user:{id,email,displayName,settings}, csrf}` or 401. `csrf` is null under bearer auth. `settings` is the same merged object `GET /settings` returns.
- `POST /auth/change-password` `{currentPassword, newPassword, everything?}` → `{sessions, tokens, pushDevices, trustedDevices, feedsRotated, notifyEmailReset, subscriptionsPaused, googleAccounts, googleMoves, pluginsDisabled}` and a new session cookie. Changes the password, signs out every browser and signs the calling one back in (it must reload for its new CSRF token). `everything: true` also does what `seed.php --revoke-tokens` does. 403 `invalid_credentials` when the current password is wrong or this session already ended; 400 `invalid_password` outside 8 to 1,024 characters; 429 under the sign-in limits. Session only; written to Activity.

## Personal Access Tokens

A token is `bc_` plus 43 url-safe base64 characters, stored hashed and shown once at creation. Bearer requests skip CSRF. Token management is session only, and creating a token also needs recent password confirmation.

Tokens are broad account keys. Allowed: calendar data, duplicate decisions, plugin configuration, enabling and disabling plugins, and queueing plugin jobs. Session only: plugin uninstall and run undo, invitation decisions and replies, proposals, dismissing Review notices, tokens, sessions and passwords, Google connection management and moves, keeping a paused subscription, reminder-device listing and removal, exports, updates, the private-files check, turning the worker off, and `POST /system/client-event`. `PATCH /settings` refuses `workerOffAt` and `updateNotifications: "off"` with 403 `session_required` and applies none of that patch (`"all"` and `"security"` are allowed). Plugin secret fields are write-only for every caller. Token system-health responses omit private host and destination details but keep counts and cleaned diagnostic reasons. The agent-facing view is [agent-api.md](agent-api.md); the MCP server is in `tools/mcp/`.

- `GET /tokens` → `{tokens:[{id,name,createdAt,lastUsedAt}]}`.
- `POST /tokens` `{name}` (at most 120 characters) → `{id,name,token}` (201; the token is shown once).
- `DELETE /tokens/:id` → `{ok:true, subscriptionsPaused, pluginsDisabled}`.
- CLI: `php server/bin/token.php --create --name=X | --list | --revoke=ID`. In the app: Settings, Account, API keys (the value is shown once; revoking is immediate).

What a token creates belongs to that exact token and stops with it, whether it's revoked or expires:

- ICS subscriptions pause, keeping their cached events for the owner to review. A token sees a subscription's `sourceUrl` only if it created it.
- Outbound feeds are deleted (an expired token's feed stops answering). A token sees `url` only for feeds it created.
- Push devices are removed (an expired token's devices aren't sent to).
- A plugin enabled or configured with the token is turned off (`disabledReason` says why; an expired token does the same at the plugin's next run), its waiting jobs are cancelled, and a `plugin` item appears in Review with Turn back on and Leave off. Enabling it from a browser clears the tie.
- A `notifyEmail` set with the token is remembered with it (`notifyEmailToken`), and reminder mail goes back to the account address.

## Calendars and Folders

- `GET /calendars` → `{calendars:[calendar], folders:[{id,name,position}], tags:[{id,name}]}`. A calendar is `{id, name, color, kind, role, provider, sourceUrl, googleCalendarId, googleAccessRole, googleAccountEmail, editable, pluginId, pluginSettings, visible, position, pollIntervalMinutes, staleAfterDays, folderIds:[], groupSimilar, reminderDefaults, subscriptionAuthorization, health}`.
  - `kind`: `local`, `subscribed` (both ICS and Google) or `plugin`.
  - `role` is what the calendar is to the person: `mine` (events are planned unless tentative), `opportunities` (events are available until picked) or `context` (information: never planned, never busy, drawn quietly). Defaults: local → mine, subscribed → opportunities, plugin → context.
  - `provider` is `ics` (an address the server fetches; `sourceUrl` set) or `google` (through a connected Google account; `googleCalendarId` and `googleAccessRole` set, `sourceUrl` null). A bearer token gets an ICS `sourceUrl` only for a subscription that exact token created, here and in calendar mutation responses; otherwise it's null.
  - `editable`: whether events can be created, edited and deleted here. True for local calendars and for Google calendars whose role is `writer` or `owner` (written through to Google, see [google-calendar.md](google-calendar.md)); false for feeds, view-only Google calendars and plugin calendars. Gate editing on `editable`, not `kind`.
  - `subscriptionAuthorization`: `{status:"active"|"paused", origin:"owner"|"token"|"legacy_review"|"session_review", reason}` for an ICS subscription, else null. `session_review` follows a pause from `POST /auth/sign-out-others` or a password reset.
  - `groupSimilar`: whether the client groups near-duplicate events on this calendar. Default `true` for subscribed, `false` for local. The server only stores it; see [Frontend State Contract](#frontend-state-contract).
  - `reminderDefaults`: see `PATCH` below.
  - `health`: `{lastPolledAt, status, error, content, eventCount, stale}`. `status` (`ok|error|never`) and `error` say whether the last fetch worked. `content` describes the last *successful* fetch: `active` (has events, or changed recently), `empty` (no events and never had any: a fresh or quiet feed, not a problem), `emptied` (no events now but had some before, which is how expired tokens and broken sources fail, since they keep returning a valid empty calendar) or `stale` (unchanged for `staleAfterDays` since it last changed or was subscribed, and nothing upcoming). `eventCount` is the last successful poll's event count. `stale` is true when `content` is `stale`. Local calendars have `content: active` and the rest null or `never`.
- `POST /calendars` `{name, color, role?, folderIds?}` → calendar (201).
- `PATCH /calendars/:id` (any of `name, color, visible, position, role, folderIds, pollIntervalMinutes, staleAfterDays, groupSimilar, reminderDefaults`) → calendar. `pollIntervalMinutes` is at least 5, `staleAfterDays` at least 1.
  - `reminderDefaults` is the calendar's own `{timed:[{minutes:int}], allDay:[{daysBefore:int, time:"HH:MM"}]}` (a missing key or empty list means none for that kind of event; both empty means no reminders), `"defaults"` (follow the global defaults whatever the role), or `null` (the role decides: Mine follows the global defaults, Opportunities and Context have none). See [Reminders and Web Push](#reminders-and-web-push).
- `DELETE /calendars/:id` → `{ok:true}`. Deletes the calendar and its events; undoable from Activity within 7 days.
- `POST /calendars/:id/adopt` → calendar. Turns a subscribed calendar (ICS or Google) into a local one, one way: the source link goes, polling and write-through stop, every event becomes an editable local event as last synced, and an `opportunities` role becomes `mine`. 400 for a calendar that isn't subscribed.
- `POST /calendars/subscribe` `{url, name?, color?}` → calendar (201). Fetched immediately, then polled hourly by default. A subscription created with a bearer token stays active only while that token is live.
- `POST /calendars/:id/refresh` → `{ok:true, imported:N}`: poll now. 502 `feed_fetch_failed` when the fetch fails; 409 `subscription_authorization_revoked` for a paused subscription, without fetching.
- `POST /calendars/:id/claim-subscription` → calendar. Session plus recent password confirmation, rechecked when saved. Only a paused ICS subscription (legacy, revoked-token or browser-review) can be claimed; an active one returns 409 `subscription_already_authorized`. It then belongs to the confirming browser session and resumes polling with its cached events. Not undoable.
- Undo keeps current subscription authority and plugin credentials: it can't restore an older subscription source or a deleted public feed's old URL. Create a new outbound feed instead.
- `POST /calendars/import`, multipart file `ics` plus `name?` → calendar plus `{imported:N, alreadyThere:0}` (201).
  - With `calendarId`, one file goes into that local calendar instead (200; a subscribed or Google calendar is 400 `import_target`). Events it already has, by UID and occurrence, are left alone and counted in `alreadyThere`.
  - A zip in the same field (a Google Calendar export or Takeout, recognized by its `PK` signature or a `.zip` name) imports each `.ics` in it as its own calendar → `{calendars:[calendar + {imported}], skipped:[{file, reason}], imported:N}`, 201 when any calendar came in, 200 when none did. `name` is ignored; each calendar is named from its file (the readable part of Google's `Name_id@…` filenames) or its `X-WR-CALNAME`, and one whose name already exists is skipped, so importing the same export twice adds nothing. The whole zip is refused (413) when it holds more `.ics` files than `TAKEOUT_FILES` or they unpack to more than `TAKEOUT_TOTAL_BYTES`. Each file then meets the single-file limits on its own; one that fails is listed in `skipped` and the rest still import. 400 `zip_unavailable` where PHP lacks the zip extension; 400 `invalid_zip` when it isn't a readable zip or holds no `.ics` files.
  - 413 `import_too_large` when a file is over `IMPORT_BYTES` (25 MiB), holds more events than `IMPORT_EVENTS` (20,000), or has more skipped occurrences (EXDATE values) than `EXDATE_VALUES_PER_EVENT` (1,024 per event) or `EXDATE_VALUES_PER_INPUT` (4,096 per file), checked before parsing. The event cap is lowered to what PHP's `memory_limit` can hold, and the message says which limit applied.
  - On every ingest path (this one, feeds, CalDAV, mail and `POST`/`PATCH /events`), descriptions over 65,535 characters and URLs over 2,048 are cut, and control characters never enter a UID or URL.
  - All these budgets have `BETTERCAL_LIMIT_*` overrides; the two EXDATE budgets can be raised only to 2,048 and 8,192. See [limits.md](limits.md).
- `POST /folders` `{name}` (201), `PATCH /folders/:id`, `DELETE /folders/:id`.
- `GET /export` → a zip with one `.ics` file per calendar, every live event whatever its date. `GET /calendars/:id/export` → that calendar's `.ics`. Both session only, sent with `Content-Disposition: attachment`.

## Google Calendar

The Google connector is described in [google-calendar.md](google-calendar.md). Consent is a browser round trip, not an XHR.

- `GET /google/status` → `{configured, accounts:[{id, email, status:"ok"|"error", reauthRequired, error, connectedAt, canCreateCalendars, unfinishedMoves:[{calendarName, status, done, total}]}]}`. `configured` is false until the server has `BETTERCAL_GOOGLE_CLIENT_ID` and `_SECRET`. `reauthRequired` means a compromise reset quarantined the credential: cached calendars stay, but polling and writes pause until it's reconnected. `unfinishedMoves` lists queued, running or failed moves to Google on that account; disconnecting cancels them.
- `POST /google/connect` → `{url}` for Google's consent screen (calendar read and event write scopes, plus email). Session, CSRF and recent password confirmation. `GET` also works, as a redirect, with the same rule.
- `GET /google/callback?code&state` → 302 to `/google?connected=1` or `/google?error=<code>` (`denied|google|state|failed`; the client shows fixed text per code, never words from the URL), a handoff path the app turns into Settings, Connections plus a toast. Needs the cookie session that started it; `state` is bound to that session and expires in 10 minutes, and the session is rechecked before the credential is stored, so a concurrent password reset wins. Reconnecting an email that's already connected replaces its token and clears its quarantine, keeping its account id and calendar links.
- `POST /google/accounts/:id/disconnect` → `{ok:true}`. Session plus recent password confirmation. Revokes at Google (best effort), then rechecks the session and grant before deleting locally; a replaced grant returns 409 `google_connection_changed`. Google may still process a revocation already sent. A grant Google later rejects is marked `reauthRequired`, and Settings says **Reconnect needed**. Cached calendars stay visible.
- `GET /google/accounts/:id/calendars` → `{calendars:[{id, name, accessRole, primary, color, kind, calendarId, reattachId}]}`: the account's Google calendar list, sorted by `kind` (`yours|shared|feed|google`, derived from the calendar id and role). `calendarId` is our calendar id when already subscribed; `reattachId` is our calendar left from a disconnected account that subscribing will re-attach. Session only. 502 `google_unavailable` when Google doesn't answer.
- `POST /google/accounts/:id/subscribe` `{googleCalendarId, name?, color?, folderIds?, accessRole?}` → calendar (201), synced immediately. `accessRole` (from the list) decides `editable`. When a calendar here followed that Google calendar until its account was disconnected, it's re-attached instead (200, `reattached: true`): it keeps its events and everything added to them, takes the new account and access role, and resumes from its sync position. 409 `already_subscribed`, or 409 `session_revoked` when a concurrent compromise reset ended the session. Session plus recent password confirmation, rechecked when saved.
- `POST /calendars/:id/move-to-google` `{accountId, googleCalendarId?}` → move `{id, googleAccountId, status:"queued"|"running"|"done"|"failed", cancelled, stoppedByOwner, stoppedByUpgrade, total, done, error, createdNew, googleCalendarId}`. Session plus recent password confirmation, rechecked when saved.
  - Moves a local calendar into the account: creates a Google calendar (or uses `googleCalendarId`, which the account must own or be able to edit and which isn't already a calendar here), uploads every event with Google's import call, then makes the calendar Google-backed (write-through). The request may do a first slice; larger moves continue in the worker.
  - Retrying is safe: after a lost response it continues the same move rather than creating another Google calendar or importing events twice. Calling it again after an ordinary failure continues with the original account and target.
  - A compromise-reset cancellation sets `cancelled: true` and is never resumed implicitly; configuring a move again starts a new attempt. `stoppedByUpgrade` marks a move an upgrade cancelled rather than resume from uncertain state.
  - 409 `google_reconnect_needed` when the account is quarantined or creating needs the `calendar.app.created` scope it hasn't granted (reconnect it); 409 `google_calendar_in_use`; 409 `session_revoked` if a concurrent reset ended the session before the move was saved.
  - While a move is queued, running, or failed but retryable, writes that change the calendar's events are refused with 409 `calendar_moving`. Local-only metadata such as tags and reminders stays writable.
- `DELETE /calendars/:id/move-to-google` → the cancelled move. Stops a failed move and makes the local calendar editable again; a partial copy already at Google is left for the owner to review or remove there. Session plus recent password confirmation.
- `GET /calendars/:id/move-to-google` → `{move}`: the calendar's latest move, or null.
- Writes to an `editable` Google calendar use `POST /events`, `PATCH /events/:id` and `DELETE /events/:id` as usual and reach Google before they return. Errors: 502 `google_write_failed` (Google refused or is unreachable; nothing changed), 403 `google_write_failed` (the account can't edit that calendar), 409 `google_unsynced` (the row has no Google id yet; refresh the calendar), 403 `google_move` (moving between a Google calendar and another calendar), 403 `google_trip_move`, 409 `google_disconnected`, 409 `google_reconnect_needed` while quarantined.
- Google calendars poll every 5 minutes by default (one sync-token request when nothing changed) and refresh through `POST /calendars/:id/refresh` like any feed.

## Events

- `GET /events?start=ISO&end=ISO&calendars=1,2&q=&includeHidden=0` → `{events:[occurrence], capped?:[eventId]}`. `start`/`end` are ISO 8601 with offset.
  - A window longer than two years is 400; ask for longer spans in pieces. A repeating series expands to at most 1,000 occurrences per request; one with more in the window shows its first 1,000 and its master event id is listed in `capped`, which is present only then ([limits.md](limits.md)).
  - `q` matches title, description and location as a substring, and tag names; a `q` equal to or starting with `#` (`#work`) matches tags only.
  - `includeHidden=1` also returns what normal responses omit: `attendance:"hidden"` events and occurrences an enabled `hide` filter would drop. Reminders fire regardless of filters, so the notification handler uses this to find the occurrence a notification points at.
- `GET /events/:id/occurrence` → `{occurrence}`: one event's full record (used by Activity's jump-to-event).

### Occurrences

An occurrence is `{instanceId, eventId, calendarId, uid, title, description, location, locationLat, locationLng, geocodedAt, locationSource, locationProvider, locationPlacedAt, url, start, end, allDay, tzid, recurring, rrule, source, attendance, status, relationship, icon, score, feedback, reminders:[], reminderSource, tags:[], people:[], isContainer, containers:[{eventId,title}], invite, dupes, styleJson, createdAt, updatedAt, isNew}`.

**Two shapes, one type.** Single-event responses (`GET /events/:id/occurrence`, `POST /events`) carry the full record. List responses (`GET /events`, `/search`, `/people/:id/events`, `/events/:id/links`) carry the grid shape: the full record minus `uid`, `createdAt`, `updatedAt`, `reminders`, `reminderSource`, `rrule`, `description`, `tzid`, `geocodedAt`, `locationSource`, `locationProvider` and `locationPlacedAt`, with null fields and empty lists omitted (a missing key reads as null or `[]`; `false`, `0` and `""` are still sent). `recurring` stays, so a row can say it repeats without carrying the rule, and `hasReminders` and `hasDescription` are sent as `true` only when true, so a client can reserve space for what the record will fill in. A client that shows an omitted field fetches the single-event record on open and merges just those fields; for a recurring instance that record's `start`, `end` and `instanceId` are the master's, not the occurrence's. A timed occurrence with `relationship: context` keeps `tzid` in the list shape, so a client can show a place-bound moment (a sunset, a tide) in its own zone.

- `instanceId` is `eventId + ":" + occurrenceStartUtc` in compact UTC (`YYYYMMDDTHHMMSSZ`), e.g. `42:20260801T190000Z`. The format is frozen, but treat it as opaque; when an endpoint needs `instanceStart`, send the occurrence's `start`. Recurring events arrive expanded.
- `rrule` is the master's raw RRULE or null: present on every expanded occurrence of a series, null on overrides. Use it to describe the repeat in words.
- `locationLat`/`locationLng` are numbers or null. `geocodedAt` is when the background sweep last tried the address; set with `locationLat` null means it couldn't place it. `locationSource` says where coordinates came from: `picked` (a person chose them, from `/geocode/search` or sent with the event), `lookup` (Better-Cal's geocoder resolved the text) or `supplied` (from the event's own data: a feed, Google, an import, CalDAV, a plugin). `locationProvider` is the geocoder that answered (`photon`, `locationiq`, `maptiler`, `stadia`, `open-meteo`, `ourairports`, …), `locationPlacedAt` when they were set. All null when unknown.
- `reminders` is the event's *effective* list (its override, else the calendar's defaults, else the global defaults) and `reminderSource` says which: `event|calendar|default`. Entries are `{minutes:int}` (before the start; before local midnight for all-day events) or, for inherited all-day defaults, `{daysBefore:int, time:"HH:MM"}`.
- `relationship` is `planned|maybe|available|context|hidden` ([relationships.md](relationships.md)), derived from the calendar's `role` and the event's `attendance` and `status`: context calendars are always `context`; attendance going, interested or hidden wins; on a `mine` calendar a Google invitation's answer decides next (accepted is `planned`, anything else `maybe`), then a tentative event is `maybe` and the rest `planned`; on an opportunities calendar the rest is `available`.
- `icon` is a host icon name or one glyph, set per event by a plugin through `syncEvents` and shown on context tokens ([relationships.md](relationships.md#where-context-is-drawn)). Null for events from feeds and the editor.
- `score` is 0 to 1 or null: the background ranking score (feed events only; null until scored or while fewer than 5 feedback signals exist). Clients sort by it themselves; the API's order doesn't change. `feedback` is `up`, `down` or null: the event's current thumbs state (its newest signal, from thumbs or attendance triage), for feed events.
- `isContainer` marks a trip; `containers` lists the trips this event is attached to. See [Trips](#trips).
- `isNew` is true for 24 hours after an event arrived on its own (a feed, the API, an import, mail or a plugin), except during a calendar's first load.
- Filter fields, absent otherwise: `dimmed: true` for an enabled `dim` filter match, `highlighted: true` for a `highlight` match (and on `/events`, `highlightColor` when the winning filter has a `color`). A dimmed or highlighted occurrence on `/events` also carries `filterReason` for the first matching filter: `{type:"keyword"|"regex", pattern, field:"title"|"description"|"location"|"tags"}` or `{type:"prompt", pattern}` (the prompt; absent when empty), with `pattern` cut to 80 characters. Occurrences matching an enabled `hide` filter are left out of `/events` and `/search`. See [Filters](#filters).
- `dupes` (omitted when empty) is `[{pairId, eventId, calendarId, groupId}]`: the same event on other calendars, linked by series for an override. `groupId` identifies the whole linked group even when its hub is outside the window. Show each group once among what's visible, using the copy from the most live source (Google, then local, then feeds, then a booking read from mail; ties go to the one with a location and description). Of a linked group only one copy's reminders fire: the best-ranked one that has any.
  - A background scan (every 10 minutes) links copies with the same UID on two calendars, or the same title at the same moment on two calendars (not a lone generic word like "Lunch"). Looser matches (near in time, similar titles, one calendar holding the same thing twice) wait in Review. Copy to marks the copy and its source as distinct.
  - `POST /duplicates/:pairId` `{status:"linked"|"dismissed"}` → `{ok:true}`: the owner's word on a pair. `dismissed` shows both again and the scan never proposes them again. Allowed for tokens.
- `invite` (nullable) is `{kind:"invitation"|"booking", via:"mail"|"google", method, organizer:{email,name}|null, attendees:[{email,name,partstat}], sequence, myPartstat, canReply, why, whyCode, reply?}` ([email-ingest.md](email-ingest.md), [google-calendar.md](google-calendar.md#invitations)). A `booking` (a reservation or ticket read from mail) has nothing to answer. When an invitation can't be answered here, `canReply` is false with `why` (text) and `whyCode` (`cancelled|not_request|no_organizer|not_configured|address_mismatch|own_invitation`). `reply` is the last mail reply's outcome `{partstat, sent, reason, message, at}`.

### Writing Events

- `POST /events` `{calendarId, title, start, end, allDay?, tzid?, description?, location?, locationLat?, locationLng?, locationSource?, locationProvider?, url?, status?, rrule?, tagNames?, personIds?, personNames?, isContainer?, reminders?}` → occurrence (the first instance), 201.
  - `status`: `confirmed` (default), `tentative` or `cancelled`.
  - A weekly `rrule` whose `BYDAY` doesn't include the start's weekday moves the start (and end) forward to the first listed day, keeping the time.
  - `locationLat`/`locationLng` store coordinates directly (when the client picked a place from `GET /geocode/search`). Omitted or null means the background sweep resolves them: it runs every minute, fills in any event (local or feed) with a location and no coordinates, upcoming first, at most one lookup per second, identical addresses once. A feed poll that changes a location clears its coordinates for another lookup.
  - **People: prefer `personIds`** (ids from `GET /people`). It sets the event's people exactly and is what the app's editor sends. `personNames` upserts by name, creating anyone it doesn't recognize; it exists for callers that only have names (ICS import, mail, quick add). Sending both unions them, which is how a client adds known people plus one genuinely new name. Ids the caller doesn't own are dropped. Names are lossy across a rename (a name-keyed save can't tell a new person from a stale copy of a name, and creates a duplicate), so a client that caches occurrences, which carry people as display names, must map back to ids before writing.
  - `description` is one field holding plain text or HTML (the editor writes HTML). Markup is sanitized on create and update to an allowlist (`p, br, b, strong, i, em, u, a[href http/https], ul, ol, li, div`; every attribute, style, class and script-bearing tag is stripped, unknown tags are unwrapped to their text). Plain text is stored as sent. Render a description containing tags as sanitized HTML, otherwise as text with line breaks.
- `PATCH /events/:id`: the same fields, plus `{scope:"this"|"following"|"all", instanceStart?}` (scope required for a recurring event) → `{ok:true}`.
  - A `calendarId` change on a series follows the scope: `this` detaches that occurrence as a standalone event on the target calendar (new uid; the series skips the day), `following` splits the series and the new part lives on the target, `all` moves the series and its exceptions together. An override patched with `calendarId` detaches the same way.
  - `scope:"all"` with `instanceStart` (what the web editor sends when a series is edited from one occurrence) changes the series by what changed for that occurrence: the same number of days (a weekly `BYDAY` shifts with it unless the edit sends its own `rrule`), plus the new time of day, zone and length. Without `instanceStart`, `start`/`end` set the series' own first occurrence.
  - When a series moves (start, zone or all-day flag) with its repeat days unchanged, its skipped dates and edited occurrences keep their places in it, as does a timed `UNTIL` unless the edit sends its own `rrule`. When its repeat days change, it restarts on its first listed day.
  - `reminders`: a list of `{minutes:int}` (0 to 40,320, at most 5, made unique and ascending) sets the event's own override; `[]` means explicitly none; `null` goes back to inherited defaults. Like attendance and tags, it's accepted on feed events and survives polls.
  - `isContainer` sets or clears the trip flag. Clearing needs the trip to have no members, else 400 `trip_has_members`. Feed events can't become trips (403 `feed_readonly`), though they can be members.
  - `moveMembers: true` with a new `start` on a trip moves the whole trip; see [Trips](#trips).
- `DELETE /events/:id` `{scope?, instanceStart?}` (body or query) → `{ok:true}`. `scope` defaults to `all` on a series. Feed events refuse with 403 `feed_readonly` (hide them instead). Deleting a trip detaches its members, which stay on their own calendars. The app's "Delete trip and its events" deletes each member first, then the trip.
- `POST /events/:id/copy` `{calendarId, scope?:"this"|"all", instanceStart?, duplicate?, start?, end?}` → the new event's occurrence (201). A new event on the target calendar with the same content, tags, people, reminders and coordinates and, for `scope:"all"` on a series, the repeat rule and skipped dates; `scope:"this"` copies one occurrence as a plain event. Fresh uid, no Google link. `duplicate: true` allows a copy on the event's own calendar (`calendarId` may then be left out), as the app's Duplicate and Option/Alt-drag do. `start` and `end` together place the copy at another time; for a series that needs `scope:"this"` with `instanceStart`. Goes through the create path, so a Google-backed target is written through and a non-editable one is refused (`feed_readonly`). To move an event across the Google boundary, copy it, then delete the original.
- `POST /events/:id/relationship` `{relationship:"planned"|"maybe"|"available"|"hidden", scope?, instanceStart?}` → `{ok:true}`. The one endpoint a client needs for this. On a `mine` calendar only planned and maybe are valid, and they set the event's `status` (confirmed or tentative), which CalDAV clients and Google see. On an opportunities calendar they set the private attendance mark. Context calendars refuse.
- `POST /events/:id/attendance` `{attendance:"none"|"interested"|"going"|"hidden" (or planned|maybe|available), scope?:"this"|"following"|"all", instanceStart?}` → `{ok:true}`. On a series, `this` gives that occurrence its own override (feed and Google polls leave a person's own overrides alone), `following` splits the series there, and `all` (default) sets the master and every override. A hidden override hides just its day. On feed events, `going` or `interested` also records an up ranking signal and `hidden` a down one (`none` records nothing).
- `POST /events/:id/feedback` `{signal:"up"|"down"}` → `{ok:true, feedback}` (the event's feedback after the call). Explicit thumbs, separate from attendance, used to train ranking. The newest signal is the current one, so repeating it is ignored and the other thumb switches it. Not undoable.
- `POST /events/:id/rsvp` `{answer:"accepted"|"tentative"|"declined", retryUncertain?}` → `{myPartstat, sent, reason, message}`. Session only. By mail, the answer is recorded whatever happens; `sent:false` comes with `reason` (a `whyCode`, `send_failed`, or `email_limit` when the notification email allowance is used up) and `message`. For `via: google` the answer is set at Google, and a failure is an error response with nothing changed. 400 for a booking. 409 `rsvp_busy` while another reply is in progress; 409 `rsvp_uncertain` after an interrupted attempt that may have sent, until the owner retries with `retryUncertain: true`.

### Time Zones and All-Day Events

Every event has a `tzid` (IANA zone). For a timed event it's the clock the event lives on: `start`/`end` are serialized with that zone's offset, and a repeating event keeps its wall-clock time in it across DST changes. `start`/`end` on writes are instants (send an offset), so `tzid` never changes *when* a single event happens, only which clock it's expressed and repeated on.

- `POST` without `tzid` uses the owner's Home zone (the `tz` setting). The web client sends the device's zone, or the one picked in the editor.
- `PATCH` leaves `tzid` alone unless it's sent. The web editor sends it only when the owner picks a zone, and then reads the typed start and end as wall-clock time there.
- All-day events are dates, stored as UTC midnights with `tzid` `UTC` whatever a write sends. Making a timed event all-day sets `tzid` to `UTC`; making an all-day event timed without a `tzid` gives it the Home zone.
- On a timed event, `UTC` is what imports write when the source gave no zone, so treat it as unknown rather than a place.
- All-day `start`/`end` on writes (`POST /events`, `PATCH /events/:id`, and `instanceStart` for an all-day series) are dates: send `"YYYY-MM-DD"`, end exclusive. Any ISO 8601 string is accepted and only its date is read, so the server's own `"<date>T00:00:00+00:00"` can be sent straight back. A non-midnight time that sits on a UTC midnight is read as the UTC date.

## Quick Add

- `POST /quickadd` `{text, tz, commit?, calendarId?}` → `{draft:{title, start, end, allDay, location, personNames:[], rrule, reminders, tags:[], calendarId, calendarFromText, confidence, source:"llm"|"fallback", when:{date,time}, modelLimit?}}`. With `commit: true` it also creates the event and returns `{event}`, with 201. See [quick-add.md](quick-add.md) for what it reads.
  - `start` and `end` are always filled.
  - `rrule` is the repeat the text asked for ("every Tuesday until Dec 15", "first Monday of the month", "10 times") without the `RRULE:` prefix, or null; `start` is then its first occurrence. The deterministic parser's rule wins over the model's, and repeats are daily or longer.
  - `reminders` is the alerts the text asked for, in minutes before the start ("alert 30 minutes" gives `[30]`; "Remind me … to call the bank" gives `[0]` and the title "Call the bank"), or null. `commit: true` saves them as the event's own reminders.
  - Markers state parts outright: `/name` puts it on a calendar you can add to (the same name, else the first whose name or a word in it starts that way; `calendarFromText` is then true and it wins over `calendarId`), `#tag` adds to `tags` (needs at least one letter, so "Room #5" stays text), `@Name` keeps the name in the title and adds the person to `personNames` as the directory knows them ("@Sam" is "Sam Sample" when there's one Sam), and `"quoted text"` is title text neither parser reads as a date or time. A `/word` that names no calendar stays in the title.
  - `when` says whether the text named a date and a time (from the deterministic parser, whichever wrote the draft), so a client with a chosen day or range keeps it unless the text says otherwise.
  - Parsing is deterministic first, and the model is asked per the `nlParseMode` setting: `smart` (default) only when the deterministic parse is incomplete (no date and time, or date and all-day) or its confidence is below 0.75; `always` model first, deterministic on failure; `never` deterministic only. A model failure never blocks; the fallback draft comes back.
  - Previews and commits share persistent model budgets per account and per token ([limits.md](limits.md)). At capacity, `modelLimit:{code, message, retryAt}` says why the draft used the basic parser.
  - Text over 4,000 characters (the configurable default) is 413 `quickadd_text_too_long`, before any parsing.
- A pasted Google Calendar template link (`calendar.google.com/calendar/render?...` or `/r/eventedit?...`) is parsed from its own parameters (`text, dates, details, location, ctz, recur`) into the draft, with `source:"gcal-link"`, `description` and `rrule`. `commit: true` creates it.
- Text that reads as availability ("NAME is away|busy|out|gone|traveling DATES", NAME matching an existing person exactly) returns `{draft:{intent:"availability", personId, personName, kind, start, end, allDay, confidence, source}}`. With `commit: true` the span is created and returned as `availability` (201), and no event is made.

## Trips

A trip ([design-containers.md](design-containers.md)) is a normal event with `isContainer: true` that groups other events. Attaching is a relationship, not ownership: members keep their own calendar, colors, times and lifecycle. Feed events can be members (the link is user-local, like tags and reminders, and survives polls), and same-calendar membership works like cross-calendar. One level only: a trip can't be attached to another trip. The API allows an event in several trips; the app attaches each to one. Growing a trip's span is up to the client (the app asks "Extend trip to include this?"); the server never grows it silently.

- `POST /events/:id/links` `{eventId}` → `{ok:true}` (201): attaches `eventId` to trip `:id`. Idempotent when already attached. 400 `trip_invalid` when `:id` isn't a trip, `eventId` is a trip, or on self-attach; 404 when either event is missing or not yours; 409 `event_changed` when either event moved calendars mid-request (reload and retry).
- `DELETE /events/:id/links/:eventId` → `{ok:true}`; 404 when the event isn't attached.
- `GET /events/:id/links` → `{events:[occurrence]}`: every member, by start time, regardless of any window. Recurring members appear once, as their master occurrence. 400 `trip_invalid` when `:id` isn't a trip.
- `PATCH /events/:id` on a trip with `moveMembers: true` and a new `start` moves the whole trip: every member shifts by the same number of days (plus any change in the trip's time of day) on its own calendar, keeping its wall-clock times, in one undo entry. 403 `google_trip_move` on a Google calendar.
- Attach and detach are undoable (entity `event_links`) and tell CalDAV clients to refresh both events.
- ICS and CalDAV export: a trip carries one `RELATED-TO;RELTYPE=CHILD:<member-uid>` line per member, and each member `RELATED-TO;RELTYPE=PARENT:<trip-uid>` (RFC 5545), in outbound feeds too. Import ignores `RELATED-TO`: trips are made only through this API.

## Search

- `GET /search?q=text&limit=50&when=all&calendar=` → `{results:[occurrence]}`: full-text, past included, ranked by relevance, then newest first. `limit` is clamped to 1 to 200. `when` is `all` (default), `upcoming` or `past`, applied before the limit; `calendar` narrows to one calendar id. Each result carries `upcoming` (a series counts until its `UNTIL`, and an upcoming one shows its next occurrence); `when=upcoming` also returns `pastCount`. Tag and people names match too, tag matches after text matches; `#term` searches tags only (newest first), `@term` people only.

## People and Availability

See [design-availability.md](design-availability.md).

- `GET /people` → `{people:[{id, name, notes, eventCount, nextStart, showOnCalendar, currentSpan, nextSpan}]}`: everyone, linked to events or not, alphabetical. `eventCount` counts undeleted events; `nextStart` is the soonest future start (UTC ISO) or null; `currentSpan` is the away or busy span covering now, or null. People are created by `POST /people`, or implicitly by `personNames` on events and quick add's "with X". The app's editor always creates explicitly, then links by `personIds`, so a name it doesn't recognize becomes a question rather than a silent new person.
- `POST /people` `{name, notes?}` → `{id, name, notes, created}`: 201 when new; when the name is taken, the existing person with `created: false`.
- `PATCH /people/:id` `{name?, notes?, showOnCalendar?}` → `{id, name, notes, showOnCalendar}`. Renaming onto an existing person's name merges them: links move to the survivor, the renamed row is deleted, and the survivor is returned.
- `DELETE /people/:id` → `{ok:true}`: removes the person and their event links; events are untouched.
- `GET /people/:id/events` → `{results:[occurrence]}`: that person's undeleted events, newest first, at most 200, shaped like `/search` results.
- `GET /people/:id/availability` → `{spans:[{id, personId, start, end, kind:"away"|"busy", note}]}` (UTC ISO, end exclusive).
- `POST /people/:id/availability` `{start, end, kind?, note?}` → span (201; `kind` defaults to away).
- `PATCH /people/:id/availability/:spanId` `{start?, end?, kind?, note?}` → span (`note` null or blank clears it). `DELETE /people/:id/availability/:spanId`.
- `GET /availability?start&end` → `{spans:[span + name]}` for people with `showOnCalendar` (the calendar's bands).
- `GET /availability/check?start&end&names=A,B` → `{conflicts:[span + name]}`: overlapping spans for the named people, whatever their visibility (the editor's scheduling hint).

## Change Cursor

- `GET /changes/cursor` → `{cursor, review, status}`, three opaque strings. Poll it (about every 30 s while visible, immediately on focus or coming online) and refetch only what the part that moved covers.
  - `cursor` moves when the user's events change (from the API, CalDAV, feed polls, mail, RSVP, plugin syncs, or background work such as filter verdicts and duplicate links) or availability spans or people are added or removed. Editing a span or renaming a person doesn't move it. Refetch the window, people and availability.
  - `review` moves when Review items, plugin proposals or possible duplicates are added or decided. Reload `/review/count` (also on a `cursor` move).
  - `status` moves on background state changes: a job, device or feed starting or stopping failing, a Google account needing reconnecting, a plugin enabled, disabled or failing, new plugin ranges or warnings, a newer release found or a notice dismissed. Reload `/calendars`, `/system/health`, `/plugins` and plugin ranges, and `/updates`.

## Geocode

How the services are chosen and set up is in [geocoding.md](geocoding.md).

- `GET /geocode?q=free+text&lat=&lng=&tz=` → `{lat, lng, display, kind, provider}`, or all null when nothing was found (`kind` is the provider's place type). One best result. Empty `q` is 400.
  - Photon (photon.komoot.io, no key) answers by default. When `BETTERCAL_PLACE_LOOKUP` (default: `BETTERCAL_PLACE_SEARCH`) names a keyed service with its key, its full-text search is asked first (LocationIQ preferring the region around the bias, or Stadia focused on it), ranked by order blended with distance under the same far-guess rule; Photon answers when it finds nothing or can't be reached. `provider` says which answered.
  - Bias follows the same order as `/geocode/search`. With a bias, up to 20 candidates are re-ranked by order and distance, and a winner over 1,500 km from the bias point for a comma-less description of four or more words, unless it's itself a place-level result, counts as not found. Without a bias, same-named places rank by significance, and when Photon finds nothing or its best answer is a city or smaller, Open-Meteo is asked too and wins only with a place of 100,000 or more people. When the full text finds nothing, simpler forms are tried (bracketed parts dropped, then from a later street-number part on).
  - A `q` of exactly three uppercase ASCII letters is an IATA airport code, resolved from the bundled OurAirports table (about 9,000 airports, offline), falling back to a normal biased query when the code is unknown.
  - Answers, including "nothing found", are cached permanently per normalized query and integer-degree bias cell, so the same text can resolve differently by region. Transport failures come back as not found and aren't cached.
  - The app calls this when an event opens with a location and no coordinates (sending `homeLat`/`homeLng` when set, else the event's `tzid`), then saves the result onto local events; feed events keep it only for display.
- `GET /geocode/search?q=&lat=&lng=&tz=&limit=6` → `{results:[{name, address, lat, lng, display, fill, city, kind, distanceKm, far, provider}], credits:[{label, url}]}`: place autocomplete for location pickers. Uncached; `limit` clamped to 1 to 10, default 6; empty `q` is 400; transport failures return `{results:[]}`, never an error.
  - `address` joins street and house number, city, state and country (name excluded, repeats removed); `display` is `name, address`; `fill` is the same without the country, which the app puts in the location for a nearby pick (a far one gets `display`), so a picked town keeps its state. `city` is nullable.
  - Bias: explicit `lat`/`lng` (the app sends `homeLat`/`homeLng` when set), else `tz` (an IANA zone mapped to an approximate centroid; about 60 major zones, unknown zones mean no bias), else none. With a bias, results blend the provider's order with distance and carry `distanceKm`, plus `far: true` beyond 500 km; without one, `distanceKm` is null and the provider's order is kept.
  - Whatever the provider: a `q` starting with a house number is an address, and only results containing that number come back; with a bias, address results containing every typed word come first, each group nearest first, and a bare number never returns a far place. A city named exactly what was typed is pinned first. A three-letter uppercase IATA code also pins the airport above the biased results (with `distanceKm` and `far` still computed).
  - The provider gets the request's first `Accept-Language` preference and uses it where it has names in that language.
  - `credits` names who answered, as their terms ask (the app shows it under the list).
  - `BETTERCAL_PLACE_SEARCH` is a comma list tried in order from `locationiq`, `maptiler` and `stadia`, each with its key (`BETTERCAL_LOCATIONIQ_KEY`, `BETTERCAL_MAPTILER_GEOCODING_KEY`, `BETTERCAL_STADIA_KEY`), with Photon always last; empty or `photon` means Photon alone. Photon answers what a keyed service doesn't take (LocationIQ: a house number with under four letters of the street; Stadia: a bare number; MapTiler takes everything) and whenever it fails, is rate limited, times out ([geocoding.md](geocoding.md#time-limits)) or finds nothing. `provider` on each row says which answered.

## Filters

Keyword, regex and prompt filters, applied server-side to `/events` and `/search`. Keyword is a case-insensitive substring; regex is PCRE, case-insensitive. Scope `global` applies to everything, `folder` to every calendar in the folder, `calendar` to that calendar (`scopeId` required for those two). Action `hide` removes matching occurrences, `dim` adds `"dimmed": true`, `highlight` adds `"highlighted": true`. Across all filter types the strongest action wins: hide beats dim beats highlight.

Regex filters run within a budget, since the text comes from feeds and invitations: each match has a reduced backtracking limit, a pattern that hits it is skipped for the rest of the request, and one request runs at most `REGEX_EVALS` (20,000) matches. Giving up means no match, so a `hide` filter fails open (the event shows) rather than stalling the calendar; it's logged on the server.

- `GET /filters` → `{filters:[{id, scope:"global"|"folder"|"calendar", scopeId, type:"keyword"|"regex"|"prompt", config, action:"hide"|"dim"|"highlight", enabled}]}`. `config` for keyword and regex: `{pattern, fields:["title"|"description"|"location"|"tags", ...], color?}` (`tags` matches each tag name). For prompt: `{prompt, negativePrompt?, threshold?, color?}`. `color`, a highlight filter's glow, must be one of `#5b7fd4`, `#c95d5d`, `#4f9d69`, `#c98c3d`, `#8e6cc0`, `#3d9dc9`, `#c9569b`, `#6b8e23`, `#b0713a`, `#5d6dc9`.
- `POST /filters` `{scope, scopeId?, type, config, action?, enabled?}` → filter (201). `config.fields` defaults to title only (description, location and tags are opt-in). An invalid regex is 400 `filter_invalid_regex`. Prompt filters need `config.prompt` (at most 2,000 characters); `threshold` is an optional number 0 to 1 (when set, the model's score decides at that cutoff instead of its yes/no).
- `PATCH /filters/:id` (any of `scope, scopeId, type, config, action, enabled`) → filter. A config sent without `fields` keeps the filter's own; a stored filter with no field list matches title, description and location. Changing a prompt filter's type or config drops its cached verdicts and re-evaluates in the background.
- `DELETE /filters/:id` → `{ok:true}`.

Prompt filters are never evaluated during a request. A background job scores feed events against the prompt and negative prompt in model batches of up to 25 and caches a verdict per filter and event; `/events` and `/search` apply the filter's action to events whose verdict is fail. Coverage is all in-scope feed events from 2 years back to 3 years ahead (repeating series always), so past months are filtered too. Evaluation runs on create, update or enable, after each feed poll that changes events, nightly, and when `GET /events` returns covered feed events with no verdict yet (up to 300 per request, even outside the window). Missing verdicts count as pass: model failures never hide events or block responses. Separately, a ranking job (hourly and after polls) uses feedback signals (at least 5) to fill `score` on upcoming feed events.

## Reminders and Web Push

Reminders must fire with the app closed, so they're delivered by Web Push. Every minute the worker expands upcoming occurrences (35 days ahead, repeats included), works out each reminder's time, and sends what's due since the last minute (with an hour's grace). An older missed reminder still fires once, late, while its event is ahead, up to 6 days late, unless its calendar was added after the reminder's time (so a new subscription or import doesn't set off a burst). Each reminder is sent once per occurrence and offset, whatever the channel, so a retry never double-sends.

The service worker shows the notification (`tag` is the `instanceId`, so a resend replaces rather than stacks). Clicking it opens `/?event=instanceId&at=ISO`, where `at` is the occurrence's start (UTC `YYYY-MM-DDTHH:MM:SSZ`). The app opens that event, fetching it with `GET /events` (36 hours either side of `at`, `includeHidden=1`) when it isn't already loaded.

**Which reminders apply:** the event's `reminders` override (`[]` means none) > the calendar's own `reminderDefaults` > the global `reminderTimed`/`reminderAllDay` settings. A calendar with no `reminderDefaults` follows its role: Mine calendars use the global defaults (whether local, Google or a feed), while Opportunities and Context calendars have none, so subscribing to a busy feed or turning on sunsets doesn't flood notifications (`reminderSource` is then `calendar`). `"defaults"` makes any calendar follow the global defaults. Events with `attendance: hidden` or `status: cancelled` never remind.

**When they fire:** `{minutes}` is minutes before the start. `{daysBefore, time}` (all-day defaults) is that wall-clock time, that many days before the event's date (`{daysBefore:1, time:"18:00"}` is 6 PM the evening before). For all-day events the date comes from the event and the clock (midnight for `{minutes}`, `time` otherwise) is the owner's Home zone (`tz`); the event's own `tzid` is used only when no Home zone is stored. So an all-day event imported as UTC reminds at 6 PM where the owner lives, not 18:00 UTC.

**Channels:** the `notifyChannel` setting. `push` (default) is Web Push only; `email` is a reminder email only (to `notifyEmail` when set, else the account address); `both` is both; `push-fallback` pushes, and emails only when no working device exists or every push that attempt was rejected or gone. A push the service accepted but never displayed (a device offline past the TTL) can't be detected, so fallback doesn't cover it. Email failures are logged and never block the scan. Reminder emails carry the title, local time, location and a button linking to the event (absolute against `BETTERCAL_BASE_URL`). Sending limits are in [limits.md](limits.md).

**ICS:** importing a file turns display VALARMs with before-start triggers into per-event overrides; export (outbound feeds, CalDAV) writes a display VALARM per override entry. Inherited defaults and explicit none export nothing. Feed polls don't sync VALARMs, so a person's own overrides survive.

**Server setup:** VAPID keys go in `.env.php` (`BETTERCAL_VAPID_PUBLIC`, `BETTERCAL_VAPID_PRIVATE`, `BETTERCAL_VAPID_SUBJECT`), generated once with `php server/bin/vapid.php --generate`. Email is optional and separate: `BETTERCAL_SMTP_HOST`, `BETTERCAL_SMTP_PORT` (default 587 with STARTTLS; 465 is implicit TLS), `BETTERCAL_SMTP_USER`/`BETTERCAL_SMTP_PASS` (only when the relay needs auth) and `BETTERCAL_SMTP_FROM` (the sender address; display name "Better-Cal"). Email stays off while host or from is empty. Sending uses the Composer packages `minishlink/web-push` and `phpmailer/phpmailer`.

- `GET /push/key` → `{key}`: the VAPID public key, or null when not configured.
- `GET /push/status` → `{subscribed, vapidConfigured, emailConfigured}`.
- `POST /push/subscribe` `{endpoint, keys:{p256dh, auth}, label?, resync?}` → `{ok:true}`. Upserts by endpoint; subscribing again clears failure state. `label` is what the device calls itself (cleaned, at most 80 characters). `resync: true` is the quiet re-registration the app does on opening: it restores a device a password reset cleared and refreshes its label, but never brings back a device the owner removed or clears a failing state. A device registered with a token belongs to that token, and a token can't take over a browser-owned or other-token device at the same endpoint (409 `push_subscription_owned_elsewhere`). A device new to the account is written to Activity. A password reset removes every device; the app registers its own again on sign-in. Device count limit: [limits.md](limits.md#trips-and-reminder-devices).
- `GET /push/devices` → `{devices:[{id, service, label, endpointHash, createdAt, lastUsedAt, failing, viaToken}]}`; `DELETE /push/devices/:id` → `{ok:true}`. Session only. `endpointHash` is the SHA-256 of the endpoint, so a browser can find itself; the endpoint is never returned.
- `POST /push/unsubscribe` `{endpoint}` → `{ok:true}`. A session can remove any matching endpoint on its account; a token only one it created. A mismatch is a quiet no-op.
- `POST /push/test` → `{ok, sent, failed}`; 400 `push_not_configured` or `no_subscription`.
- `POST /push/test-email` → `{ok:true, to}`: a test reminder email to the reminder address. 501 `email_not_configured` without SMTP; 400 `email_send_failed` when the relay rejects it. The two test endpoints share a limit of 5 sends per 10 minutes per account, then 429 `too_many_requests`.
- Endpoints that answer 404 or 410 are marked failing; the worker deletes subscriptions failing for 3 or more days.

## System Health

- `GET /health` (no auth) → `{ok:true, db}`; with a valid session cookie also `time` and `version`.
- `GET /system/health` → `{rows, worker, privateFiles, backupsReady, cron, emailAlerts:{configured, systemTo, accountTo}}`: whether background work is working.
  - `rows` is `[{subject, kind:"job"|"feed"|"push", label, status:"ok"|"failing", off, firstFailedAt, lastFailedAt, lastOkAt, consecutiveFailures, lastError, alertedAt, endpointHash?}]`, one per subject: the worker itself (`worker`), its job types (system-wide: prompt filter evaluation, ranking, mail import, reminder scan, geocoding, activity retention, alert emails, duplicate detection), each geocoding service in use (`geocoder:<provider>`, kind `job`), each subscribed feed (`feed:<calendarId>`) and each push device (`push:<subscriptionId>`, with `endpointHash` so the app can tell its own device).
  - `off` is `"off"` for work the owner turned off (the worker, place lookups), `"not set up"` for a service this server isn't configured for (mail import, prompt filters and ranking without an AI key, alert emails without SMTP), else null.
  - The `worker` row is failing after 15 minutes without a worker run, noticed by the next request (checked at most every two minutes), with one email at once. `worker` is `{state:"running"|"never"|"off"|"stopped", lastRunAt, offAt}`: only `stopped` (it ran, then didn't for 15 minutes) is an error; `never` isn't set up yet, and `off` was turned off on purpose and lasts until the worker runs again.
  - `privateFiles` is the last private-files status (null before the first check); `backupsReady` is false when the server has no session secret to encrypt backups with.
  - `cron` is the worker's cron job as this server needs it: `command` alone (for panels with their own schedule fields), the full crontab `line`, and `followsServerPhp` (whether it runs the server's plain `php`, which follows its upgrades, or names this site's PHP directly).
  - Changes between working and failing are written to Activity once each, under source `system`. A failing streak sends **one** email once it's real (jobs: an hour and 3 consecutive failures; feeds: a day; a device: 6 hours), at most one per subject per day while it lasts, and one on recovery. Per-user subjects email the account address; system-wide ones `BETTERCAL_ALERT_EMAIL`, else the first user. Without SMTP, `emailAlerts.configured` is false and failures show only here and in Activity.
  - The app interrupts for three things: a banner while reminders can't reach *this* device, and one toast at boot each when prompt filters aren't being evaluated or a visible calendar's feed is failing.
  - A token's view drops `cron.command`, `cron.line`, both email addresses and `endpointHash`, cleans `lastError` to a recognized reason, and shows every push row as `push` / "Push delivery".
- `POST /system/worker-off` → `{worker}`: marks the worker as turned off on purpose. Session only.
- `GET /system/private-files` → `{result, wait, backupWarning, storageWarnings}`: the last private-files check (`result` null before it has run) and the seconds until another may run. `result` is `{status:"safe"|"probably"|"exposed"|"unchecked", kind:"refused"|"guarded"|"probably"|"unlikely"|"php-off"|"exposed"|"unchecked", title, message, notes:[string], checkedAt}`, ready to show. `backupWarning` is `{message, settingsLine}` when backups can't be encrypted, else null; `storageWarnings` lists problems with private export storage. `POST /system/private-files/check` runs the check now (it asks the main domain for a probe file in the app folder, a few seconds) and answers the same shape; 429 `too_soon` within a minute of the last run. Both session only.
- `POST /system/client-event` `{kind:"boot-stalled", at, file?}` → `{ok:true}`. Session only. The app reports, once, a start on this browser that never reached its first render (a white screen): `at` is an ISO 8601 instant within the last 30 days, `file` an optional `/assets/...` path that failed to load. Nothing is stored; the server writes one fixed-format line to its error log. Anything else is 400.

## Updates

All session only.

- `GET /updates` → `{currentVersion, latestVersion, priority, minimumSecureVersion, releaseUrl, releasedAt, checkedAt, lastError, preference, available, securityRequired, showBanner, dismissible}`. `preference` is the `updateNotifications` setting. `securityRequired` is true when this version is below the minimum secure version.
- `POST /updates/check` → the same, after checking for a release now; 502 `update_check_failed` when the check fails.
- `POST /updates/dismiss` → the same, with the latest version's notice dismissed. A required security update can't be dismissed (400).

## Settings

Preferences are stored per user. The server stores and validates them; the client enforces them, except for the keys whose notes say the server uses them. Reads return stored values merged over defaults, so unset keys come back as their defaults. Settings changes aren't undoable.

- `GET /settings` → `{settings:{...}}` with every key in the table.
- `PATCH /settings` (any subset of the writable keys) → `{settings:{...}}`, the full merged object. Unknown or read-only keys are 400 `unknown_setting`; invalid values are 400.

| Key | Values | Default | Notes |
|---|---|---|---|
| `defaultView` | `month`\|`weeks3`\|`weeks2`\|`week`\|`day`\|`agenda`\|`split` | `month` | view opened on load |
| `defaultViewId` | saved view id or null | `null` | what "Default view" (hotkey 0) applies; must be one of the user's saved views; null is the built-in reset (month, today, no text filter, every kind shown, calendar visibility untouched) |
| `weekStart` | `mon`\|`sun` | `sun` | first day of the week in grids |
| `timeFormat` | `"12"`\|`"24"` | `"12"` | accepted as string or number, returned as string |
| `defaultCalendarId` | id of an owned `local` calendar, or `null` | `null` | checked for ownership and kind |
| `theme` | `system`\|`light`\|`dark` | `system` | |
| `nlParseMode` | `always`\|`smart`\|`never` | `smart` | used by `/quickadd` (see [Quick Add](#quick-add)) |
| `notifyChannel` | `push`\|`email`\|`both`\|`push-fallback` | `push` | reminder delivery, used by the reminder scan (see [Reminders and Web Push](#reminders-and-web-push)) |
| `notifyEmail` | email address (at most 254 characters) or `null` | `null` | where reminder and test emails go; null or blank means the account email (the SMTP sender mailbox) |
| `notifyEmailToken` | read-only | `null` | the API token that set `notifyEmail`, or null when it was set from a browser |
| `updateNotifications` | `all`\|`security`\|`off` | `all` | which release notices the app shows; tokens can't set `off` |
| `mailEventMode` | `any`\|`approved`\|`review` | `approved` | which emailed events are added ([email-ingest.md](email-ingest.md)) |
| `mailApprovedSenders` | list of exact addresses, domains or `*.example.com` subdomain rules | `[]` | stored in canonical form; common shared mailbox domains are refused unless given as exact addresses; replacing the list doesn't decide existing Review items |
| `overviewMode` | `month`\|`3day` or `null` | `null` | layout for the overview slot; null lets the client use its device default |
| `folderVisibility` | object: folder id → `{mode:"all"\|"none"\|"custom", custom:[calendarId]}` (at most 200 folders, 500 ids each) | `{}` | per-folder calendar visibility; meaning is up to the client |
| `sidebarActiveOnly` | boolean | `false` | sidebar lists only checked calendars and people; display only |
| `panelTucksSidebar` | boolean | `true` | desktop: the sidebar tucks away while an event's panel is open; display only |
| `gridAnchor` | `context`\|`top` | `context` | where month, 3-week and 2-week views put the day they go to (today, a jump): a row or two below the top, or at the top; display only |
| `removeAcceptedCancellations` | boolean | `false` | accepting an emailed cancellation in Review deletes the event (with Undo) instead of keeping it struck through; the accept response carries `removed: true` |
| `pluginHidden` | object: plugin id → boolean | `{}` | which plugins' overlay bands are hidden |
| `tz` | IANA zone id or `null` | `null` (the client stores the browser's zone on first load) | the owner's **Home** zone. The screen always follows the device; the server uses this where there's no device to ask: all-day reminder times, events created without a `tzid` (accepted proposals, agent calls), email times that carry no zone, the zone plugins get, times in failure-alert emails, and a last-resort bias for place search with no home location. The client mentions it, once per pairing, when the device's clock differs. |
| `hereTz` | IANA zone id or `null` | `null` | where the device last was, reported by the app at boot; email times that name no place are read on this clock, and reminder text (push and email) shows times in it, else in `tz` |
| `reminderTimed` | list of `{minutes:int}` (0 to 40,320, at most 5) | `[{minutes:10}]` | global default reminders for timed events; used by the reminder scan |
| `reminderAllDay` | list of `{daysBefore:int, time:"HH:MM"}` (0 to 28 days, at most 5) | `[{daysBefore:1, time:"18:00"}]` | global default reminders for all-day events, on the Home-zone clock |
| `homeLat` | number -90 to 90, or `null` | `null` | home latitude; with `homeLng`, the bias point for `/geocode/search` |
| `homeLng` | number -180 to 180, or `null` | `null` | home longitude |
| `homeLabel` | string (at most 200) or `null` | `null` | display name for the home location (Settings page only) |
| `mapStyle` | `streets-v2`\|`dataviz`\|`outdoor-v2`\|`bright-v2` | `streets-v2` | MapTiler style for the event mini-map; used only when the server has `BETTERCAL_MAPTILER_KEY` (see `GET /config`) |
| `placePastEvents` | boolean | `false` | the background geocoder also looks up events that have already ended (a running series counts as upcoming either way) |
| `welcomeDone` | boolean | `true` | first-run welcome finished (`seed.php` creates new accounts with `false`) |
| `welcomeSteps` | list of `subscribe`\|`import`\|`google`\|`install`\|`reminders`\|`plugins` | `[]` | the welcome's "where to start" items taken so far |
| `workerOffAt` | database time or `null` | `null` | when the worker was turned off on purpose (set through `POST /system/worker-off`); tokens can't set it |
| `mailSenderMigrationDone` | read-only | `false` | internal marker |

## Saved Views

Named snapshots of client view state. `config` is client-defined: `{viewType, visibleCalendarIds, folderCollapse, filterText, hideRel, anchor:"today"|dayKey}`. `hideRel` lists the relationships the Show filter switches off (`planned|maybe|available|context`); absent or empty shows all.

- `GET /views` → `{views:[{id, name, config, position}]}`.
- `POST /views` `{name, config}` → view (201).
- `PATCH /views/:id` `{name?, config?, position?}` → view.
- `DELETE /views/:id` → `{ok:true}`.

## Outbound Feeds

A feed URL reads events without signing in. Creation is written to Activity. Feeds made with a token belong to it (see [Personal Access Tokens](#personal-access-tokens)). `seed.php --revoke-tokens` gives every remaining feed a new address, and `POST /auth/sign-out-others` does so for every feed created from a browser.

- `GET /outfeeds` → `{feeds:[{id, name, url, viaToken, scope, description, eventCount, eventLimit}]}`. A token sees `url` only for feeds it created (others are `url: null`). `eventCount` is how many events an all or calendar feed covers (null for a search feed); past `eventLimit` (`BETTERCAL_LIMIT_OUTFEED_EVENTS`, 5,000 by default; 500 for a search feed) the oldest past events are left off first.
- `POST /outfeeds` `{name, scope:{type:"all"|"calendar"|"search", calendarId?, q?}, description?}` → `{id, name, url, viaToken, scope, description}` (201).
- `DELETE /outfeeds/:id`. A browser session can remove any feed on the account; a token only one it created. Other and nonexistent ids both return 404.
- `GET /feed/{token}.ics` (public, no auth): the feed, with `X-WR-CALNAME` and description embedded.
- ICS export (outbound feeds and CalDAV): an HTML description is written as `DESCRIPTION` in plain text plus `X-ALT-DESC;FMTTYPE=text/html` with the sanitized HTML; plain text is written as is. A timed event is written in its own zone (`DTSTART;TZID=...` local time) with a `VTIMEZONE` for each zone used (yearly rules from 1970, from PHP's current zone data). UTC events are written with `Z`, all-day events as `VALUE=DATE`, and a time in the repeated hour when clocks go back as UTC. Import reads descriptions as text.

## Plugins and Proposals

The operations side of plugins (writing one: [plugins/authoring.md](plugins/authoring.md)). Nothing here runs plugin code except a plugin's own settings validation on save. Tokens may read, configure, enable, disable and queue jobs; uninstall and run undo are session only. Each change rechecks the requesting credential when it's saved. Fields declared `secret`, in all three scopes, read back only as `{set: true}` or `{set: false}`; leaving one out keeps it and null clears it. Plugins with secrets show generic diagnostic text instead of free-form logs, errors and warning details.

- `GET /plugins` → `{plugins:[{id, name, version, description, permissions, jobs, settingsSchema, calendarSettingsSchema, eventSettingsSchema, decoration, requires, optional, unmetRequires, errors, installed, enabled, disabledReason, consecutiveFailures, settings, lastRun:{job, at, durationMs, outcome, logTail, runId, undoableMutations}|null}]}`.
- `POST /plugins/:id/enable`, `POST /plugins/:id/disable` → `{ok:true}`.
- `POST /plugins/:id/uninstall` `{deleteCalendars?}` → `{ok:true, purged}`. Session only.
- `PATCH /plugins/:id/settings` (values per `settingsSchema`) → `{settings}`. `PATCH /plugins/:id/calendar-settings/:calendarId` (per `calendarSettingsSchema`) → `{settings}`.
- `POST /plugins/:id/run?job=` → `{queued:[jobId], alreadyQueued:[jobId]}`: queues every job, or the one named, for the worker's next run. Matching pending or running jobs coalesce. 409 for a disabled plugin, 400 for an unknown job, 429 when run limits are used up ([limits.md](limits.md)).
- `GET /plugins/:id/warnings` → `{warnings:[{id, eventId, severity, message, fix, ...}]}`.
- `POST /plugins/runs/:runId/undo` → `{undone, skipped}`: reverses every change one run made, newest first. Session only.
- `GET /plugins/ranges?start&end` → `{plugins:{<id>:{truncated, ranges:[{id, start, end, label, color, detailHtml}]}}}`: overlay bands of enabled plugins, at most 500 per plugin per request (`truncated` says when there are more).
- `GET /events/:id/plugin-data` → `{data:{<pluginId>:{key:value}}}`. `PATCH /events/:id/plugin-data/:pluginId` (values per the plugin's `eventSettingsSchema`; null removes a key) → `{saved}`.
- `GET /proposals?status=open` → `{proposals:[{id, pluginId, sourceKey, title, summary, rationaleHtml, plan, destinations:{trip?, events:[]}, acceptAllowed, acceptError, reviewToken, status, createdAt, decidedAt}]}`. Destinations are resolved local calendars and part of the reviewed revision.
- `POST /proposals/:id/accept` `{reviewToken}` → `{proposal, created, runId}`: creates the local events (and the trip, if any) in one step, only if that exact revision is still open. `POST /proposals/:id/reject` `{reviewToken}` → `{proposal}`. A changed revision is 409 `proposal_changed`, with nothing decided or written. `POST /proposals/:id/undo` → `{undone, skipped}`; the proposal reopens only after a complete reversal. All session only.

## Review Queue

Everything waiting on the owner's decision, in one list of ten kinds:

- `invite_new`: a new emailed event held by the mail preference.
- `invite_change`: an emailed REQUEST or CANCEL for an invitation already on the calendar, held rather than applied ([email-ingest.md](email-ingest.md)).
- `mail_error`: a notice about an unreadable message, with sender, subject and reason, kept until dismissed.
- `mail_limit`: a bounded notice that public-mail automation hit a safety or paid-model limit.
- `model_limit`: a bounded prompt-filter capacity notice for one calendar.
- `rsvp`: an invitation with `myPartstat: NEEDS-ACTION` that can be answered here (from mail or a Google guest list), still ahead and not cancelled.
- `proposal`: an open plugin proposal.
- `duplicate`: two events that may be one.
- `subscription`: an ICS feed whose updates are paused for an owner decision.
- `plugin`: a plugin turned off because the token that enabled or configured it was revoked.

`invite_new`, `invite_change`, `mail_error`, `mail_limit` and `model_limit` are stored by the queue; the others are read from where they live.

- `GET /review?status=open|all&before=<stored-item-id>` → `{count, nextBefore, items:[{key, kind, status, title, summary, createdAt, eventId, link, detail, actions}]}`. Open items first, then newest first.
  - `key` is `"<kind>:<id>"`. `status` is `open|accepted|dismissed|gone` (`gone`: the event was deleted; superseded items aren't listed). `link` is `{instanceId, at}` for opening the event (the pair a reminder notification carries) or null.
  - `detail` by kind: invite_new `{method, isInvitation, from, organizer, sequence, start, end, allDay, recurring, location, decidedAt}`; invite_change `{method:"REQUEST"|"CANCEL", from, organizer, sequence, diff:[{field, label, from, to}], decidedAt}` (start and end values are ISO instants, or `YYYY-MM-DD` for all-day; descriptions are plain-text previews); mail_limit `{reason, count, retryAt, latestSubject, latestFrom}` (the latest message only, not every refusal); model_limit `{reason, count, retryAt, calendarId, calendarName, latestBatchSize}`; rsvp `{eventId, title, start, end, allDay, recurring, location, organizer, via, invitedCount}`; proposal is the `/proposals` object; duplicate `{pairId, a:{eventId, title, calendar, allDay, start}, b:{...}}`; subscription `{calendarId, origin}`; plugin `{pluginId}`.
  - `actions` is `[{name, label, method, path, body?, quiet?}]` (paths relative to `/api/v1`): send `body` (or `{}`) to `path`. Decided items have none (an accepted proposal offers `undo`). Proposal accept and dismiss bodies carry the displayed `reviewToken`; don't substitute a later token without reviewing its plan. Duplicates offer Same event and Not the same (`POST /duplicates/:pairId`); rsvp offers Accept, Maybe and Decline (`POST /events/:id/rsvp`) plus a quiet Dismiss; subscriptions offer Keep updating (needs recent password confirmation), Keep a local copy and Remove; plugin items offer Turn back on and Leave off.
  - Tokens get actions for duplicates only; every other decision is session only.
  - Stored items come in pages of 200: pass `nextBefore` as `before` for older ones, and deduplicate other kinds by `key` when appending pages. `/review/count` has the complete open count.
- `GET /review/count` → `{count, byKind:{invite_new, invite_change, mail_error, mail_limit, model_limit, rsvp, proposal, duplicate, subscription, plugin}}` (the sidebar badge).

Decisions, all session only:

- `POST /review/invitations/:id/accept` `{approveSender?:"address"|"domain", answer?:"accepted"}` → `{item, eventId, settings?, reply?}`. Adds the event and, when asked, approves the stored sender (in approved-senders mode) in the same step. Nothing is answered unless `answer` asks for a reply; bookings have no reply. Only stale or redundant competing revisions close; ones still actionable stay as invitation changes. Approving a broad shared-provider domain is refused.
- `POST /review/invitations/:id/dismiss` → `{item}`. No event is created.
- `POST /review/invite-changes/:id/accept` → `{item, eventId}`. Rechecked against the event as it is now and applied like `PATCH /events/:id` under source `review` (written to Activity, undoable; `scope:"all"` for a series), storing the invitation and its `SEQUENCE` in the same step. 409 `review_changed` means the owner edited the event after the email arrived: the calendar is untouched, the item's differences are refreshed, and the owner can review and try again. 409 `review_gone` (the event was deleted) and 409 `review_stale` (a newer version was already applied) close the item. 409 `review_decided` if it was already decided.
- `POST /review/invite-changes/:id/dismiss` → `{item}`. The calendar is unchanged; written to Activity.
- `POST /review/replies/:eventId/dismiss` → `{eventId}`: takes an `rsvp` item out of the queue without answering. 404 when the event has no unanswered invitation.
- `POST /review/mail-errors/:id/dismiss` → `{item}`: closes the notice; the original email isn't deleted.
- `POST /review/mail-limits/:id/dismiss`, `POST /review/model-limits/:id/dismiss` → `{item}`: close an informational notice; neither changes the calendar.

## Activity

Every change is written to the activity log, tagged with where it came from: `web` (default), `api` (a token), `caldav`, `quickadd`, `rsvp`, `import`, `feed` (poll summaries, log only), `mail:imip|markup|gcal-link|llm` (email import), `geocode`, `system`, `plugin:<id>`, `review` and `dedup`.

- `GET /activity?before=&limit=&sources=&q=` → `{entries:[{id, at, source, entity, entityId, op, summary, details, undone, undoable}], nextBefore}`. Newest first. `before` is a cursor (an entry id); `limit` is at most 100 (default 50). `sources` is a comma list from `web, api, caldav, feed, mail, quickadd, rsvp, import, geocode, system, plugin, review, dedup` (`mail` and `plugin` match every `mail:*` and `plugin:*` source). `q` matches summaries as a substring. `undoable` means it has snapshots, isn't undone, and is within 7 days.
- `POST /undo` → `{ok:true, undone:{entity, op}}`, or 404 `nothing_to_undo`. Undoes the most recent create, update or delete not yet undone, passing over log-only entries (changes sent to Google, feed-poll summaries).
- `POST /activity/:id/undo` `{force?}` → `{ok:true, undone:{entity, op}}`. Errors: `already_undone`; `not_undoable` (a log-only entry: feed summaries, RSVP notes, or snapshots older than 7 days); `stale_undo` (newer changes to the same thing exist; retry with `force: true` to overwrite them deliberately).
- Retention: snapshots are cleared after 7 days (the entry becomes log only), and entries are deleted after 90.
- A feed poll with changes makes one entry, `Feed 'Name': N added, M updated, K removed`, with `details.addedTitles` (at most 20). Log only, since the next poll would redo anything undone.

## Misc

- `GET /config` → `{maptilerKey, mapStyle, placeSearch}`: public-safe client configuration, fetched once at boot. Signed in.
  - `maptilerKey` is the optional MapTiler tile key from `BETTERCAL_MAPTILER_KEY` (null when unset; the mini-map then uses OSM tiles). `mapStyle` echoes the user's setting.
  - `placeSearch` is `{requested, active, name, fallback, problem, note, notes, termsUrl, healthSubjects, downAdvice}`. `requested` and `active` are comma lists of the services `BETTERCAL_PLACE_SEARCH` lists and those in use (`active` is `photon` when no keyed service is usable; a listed one without its key, or an unknown name, is skipped and `problem` says why). `name` is their display names in order, `fallback` is `"Photon"` when Photon stands behind them, and for Stadia `note` and `termsUrl` carry its storage terms. `notes` lists every such note (`{text, url, link}`), `healthSubjects` the System health subjects of the services in use (Photon always among them; the app interrupts only when all of them are failing), and `downAdvice` what to say when they are (set only when Photon is the only service). Never a key.
- App handoff paths (not API endpoints): `/add` feeds the quick-add parser (the browser extension's redirect target); `/subscribe#url=` prefills the subscribe drawer (the webcal handler links there); `/share` is the Web Share Target (a `POST` of `title`, `text`, `url`, answered by the service worker; the server answers a stray `POST /share` with a 303 to `/`); `/import` and the manifest file handler accept `.ics` files; `/settings/<tab>` opens a Settings tab. None of these payloads is read from the address bar: before loading assets the app moves the path, fragment and any query into `sessionStorage` and rewrites the address to `/`, and the service worker parks a share and redirects to `/`. So a fragment-form link or a share never puts its payload in a request line or Referer; an old `?url=` style link still reaches the server once, in that first request. The HTML is served with `Referrer-Policy: strict-origin-when-cross-origin` (also as a `<meta>`).

## Frontend State Contract

For the bettercal-ui components (`web/src/ui/`).

- Settings is one page with tabs (`general`, `notifications`, `location`, `connections`, `account`, `about`, `system`). `state.settingsTab` picks one (General unless something set it; reset on leaving the page).
- Components receive plain occurrence objects plus a `CalendarMeta` map `{id:{color, name, visible}}`, and emit intents: `onCreateRange({start, end, allDay})`, `onMoveEvent({instanceId, newStart, newEnd})`, `onResizeEvent({instanceId, newStart, newEnd, edge})`, `onOpenEvent(instanceId, anchorRect, opts?)` (double-click passes `opts={detail:true}` for the full detail view), `onExpandDay(dateISO)`, `onRequestWindow({start, end})` (infinite scroll's data demand), `onSetAttendance(occ, attendance)` (agenda triage on feed events) and `onFeedback(occ, "up"|"down")` (thumbs on feed events). DayExpand also accepts `onOpenDetail(instanceId)` for its per-row open button. AgendaList also accepts `sortMode: "time"|"match"` (match renders the caller-ordered flat list; see `sortByMatch` in `web/src/lib/rank.js`) and `scrollKey`/`scrollSeq` (each new seq scrolls the day-grouped list to the anchor day). Drag math lives in the library; persistence in the app layer.
- Near-duplicate grouping is client-side: the app runs `web/src/ui/grouping.js` over occurrences from calendars with `groupSimilar: true`, replacing same-day, same-calendar duplicates by title with group items (`{isGroup:true, instanceId:"group:...", title, count, members:[occurrence]}`). Components draw those as stack chips, never drag them, and send their `onOpenEvent` clicks to a group list popover.
