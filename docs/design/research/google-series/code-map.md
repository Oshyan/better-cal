# Google write path, sync path and Undo: code map for #172

Read-only survey of the tree at main (1.5.3 changelog head). Paths are relative to the repo root. `E` = server/src/Domain/Events.php, `SP` = server/src/Domain/SeriesPlans.php, `GW` = server/src/Domain/GoogleWriter.php, `GS` = server/src/Domain/GoogleSync.php, `U` = server/src/Domain/Undo.php, `F` = server/src/Domain/Feeds.php, `M` = server/src/Domain/Series/Model.php.

## 1. The write path today

Shared plumbing, used by every row below:

- Writable test: `GW::writable` GW:40-46 (provider google, a calendar id, an account, role writer or owner). `E::googleCalendarFor` E:281-285 returns the calendar row or null; every branch below starts from it, and only for rows with `source = 'feed'` (E:1431, E:1928, SP:161, SP:206).
- HTTP: `GW::call` GW:246-289. Base `https://www.googleapis.com/calendar/v3/calendars/{calId}/events` (GW:26). Every non-GET gets `sendUpdates=none` (GW:259-261). No `If-Match` header, no etag anywhere. 204, or 410 on DELETE, returns `[]` (GW:276-278); other non-2xx is `google_write_failed` 502 (403 stays 403) (GW:280-287). A test seam: the `jsonTransport` closure `(method, url, payload) => ['status','body']` (GW:35, GW:270-272).
- Body: `GW::body` GW:56-92 builds a full resource from a DB row: `summary`, `description`, `location`, `status` (`tentative` or `confirmed`), `source {url,title}` when the row has a web link, `start`/`end` (`{date}` all-day, `{dateTime, timeZone}` timed), and always `recurrence` (RRULE plus EXDATE lines from `exdates_json`, or `[]` for a non-series) (GW:89-90, GW:98-117). `GW::instanceBody` GW:138-143 drops `recurrence`, but the "this occurrence" path does not use it (see below); only GoogleMove does (GoogleMove.php:278).
- Instance ids: `GW::instanceId` GW:125-132 = `{masterGoogleId}_{YYYYMMDDTHHMMSSZ}` for timed, `_{YYYYMMDD}` in the series zone for all-day.
- Storing the answer: `GW::apply` GW:221-230. It reads every live row of the calendar back into the parsed shape (`GS::rowsToParsed`), merges Google's resources and any tombstones in with `GS::applyChanges`, and runs `Feeds::sync(..., journal: false)`. So what is stored is exactly what a poll would store from that resource; a patch to the master stores the master only, and the exception rows stay as cached until a poll brings them.
- Split of fields: `E::splitGoogleFields` E:330-336. Google-owned columns are `GW::GOOGLE_COLUMNS` GW:29 (title, description, location, start_utc, end_utc, all_day, tzid, rrule, exdates_json, status). `url` is dropped from Google fields. Everything else (reminders, trip flag, coordinates, override_fields) is written locally after `apply`, inside `E::googleCommit` E:312-318 (the credential is checked again there; `E::googleBeforeSend` E:306-309 checks it just before each call).
- Missing Google id: `E::requireGoogleId` E:320-327, 409 `google_unsynced`.
- Activity: `E::googleJournal` E:338-344 records `Undo::record(entity 'event', op 'update', before null, after null, summary "<what> on Google calendar '<name>'", touches [[events:<id>, '*']])`. Source is the request's `ActivityContext` (web, api, quickadd: ActivityContext.php:13-29); there is no `google` source (Activity.php:23). Before and after are null, so the entry is log-only (Undo `undoById` U:1146-1151 refuses it `not_undoable`; `undoLatest` U:1109-1124 answers "The last change can't be undone here: <summary>" rather than undoing an older one). The `'*'` touch makes any older undo of that event stale (U:929-932).

| Edit | Function (file:lines) | Sent to Google | Stored locally | Activity | Undo |
|---|---|---|---|---|---|
| Create | `googleCreate` E:346-371, reached from `create` E:1045, E:1129-1130 | `POST /events`, `GW::body(row)` (GW:193-196). No client-chosen `id`. | `apply([resp])`, row found by `google_event_id` (GW:232-238); then local columns (reminders, trip flag, coordinates), tags, people (E:356-363). 502 if the row didn't come back (E:366-368). | googleJournal "Created '...'" | No |
| Copy onto a Google calendar | `copyTo` E:1158 goes through `create()` (comment E:1152-1156) | as Create | as Create | as Create | No |
| Single event, any field or time | `googlePatchAll` E:373-398, dispatched E:1546-1557 (`all` on a non-series) | `PATCH /events/{google_event_id}` with `GW::body(event + google fields)`: the whole resource, not just changed fields | `apply([resp])`, then local fields, tags, people (E:383-394) | googleJournal "Updated '...'" only when a Google field changed (E:395-397) | No |
| This occurrence of a series, occurrence already has a row | `googlePatchThis` E:400-469, branch E:414-424 | `PATCH /events/{row.google_event_id}`, or `{instanceId}` when the row is local-only (an attendance-only override has no Google id, E:417-421). Body is `GW::body(existing + fields)`, so it carries `recurrence: []` (the row has no rrule). | `apply([resp])`; local fields, tags, people on that row (E:440-462). No `override_fields` written by this path; ownership comes from the sync (section 2). | "Updated one occurrence of '...'" on the master id (E:468) | No |
| This occurrence, no row yet | same, E:424-439 | `PATCH /events/{instanceId}` with `GW::body(override built from master + patch)` (E:427-438): `recurrence: []` again | `apply`, row found by `resp.id` or the instance id (E:446-450); master's tags and people copied to it (E:452-454) | as above | No |
| This and following (edit) | E:1551-1552 calls `splitPlanned` SP:685; `Split::plan` refuses on Google (Split.php:75-79) unless the day is the first, when it is `whole` (Split.php:71-73) and runs `googlePatchAllPlanned` | Nothing (422 `series_refused`, `checkedPlan` SP:599-609) | Nothing | Nothing | n/a |
| All events, recurring master | `googlePatchAllPlanned` SP:865-896, dispatched E:1553-1554 | `committedPlan` SP:569 (plan with `google: true`), master writes only with `exdates_json` removed (SP:870-871); `PATCH /events/{master id}` with `GW::body(event + google fields)`, recurrence carrying the stored EXDATEs | `apply([resp])` (master only), local fields, tags, people (SP:881-892). Edited days are left to the next poll (SP:861-863). | "Updated '...'" when a Google field changed (SP:893-895) | No |
| Delete one occurrence (from the master) | `googleDeleteThis` E:487-504, dispatched E:1966 | `withExdate` checked first (E:491-493); `DELETE /events/{override google id or instanceId}` | tombstone `(uid, instanceUtc)` through `apply`: the override row goes and the date becomes an EXDATE on the master (GS:458-461, GS:472-478) | "Deleted one occurrence of '...'" | No |
| Delete an override row directly | `googleDeleteOverride` E:506-517, dispatched E:1938-1942 | `DELETE /events/{override.google_event_id}` (needs one: `requireGoogleId`) | tombstone as above | "Deleted one occurrence of '...'" on the override id | No |
| Delete this and following | E:1967-1968: `splitPlanned(..., Split::DELETE)`; refused on Google except from the first day, which is delete all | Nothing (422) | Nothing | Nothing | n/a |
| Delete all / a single event | `googleDeleteAll` E:480-485, E:1969 | `DELETE /events/{master id}` | tombstone `(uid, null)`: every row of that uid goes (GS:453-457) | "Deleted '...'" | No |
| Relationship planned/maybe on my own Google calendar | `setRelationship` E:99-107, `relationshipChange` E:124-157 turns it into a `status` patch (E:144-151), so it is one of the rows above | `status` in the full body | as the patch | as the patch | No |
| My attendance (Planned/Maybe on a non-mine role, or an invitation's local choice) | `setAttendance` E:2048-2068, `answerAttendance` E:2111-2147 | Nothing: attendance is local (E:2040-2046) | `attendance`, and `override_fields` on a day's own row (E:2117-2122) | `Undo::record` with snapshots (E:2145-2147), but `recordCalendarRefs` empties them because the calendar is Google (U:1033-1040) | No (log-only) |
| Attendance this and following | E:2085-2101, `splitPlanned(Split::ANSWER)`; refused on Google except from the first day | Nothing (422) | Nothing | Nothing | n/a |
| Answer a Google invitation | `Rsvp::answerAtGoogle` server/src/Domain/Rsvp.php:371-426, from `answerLocked` Rsvp.php:241-305 | `GET /events/{id}`, then `PATCH /events/{id}` with only `{attendees: [...]}` (Google's current list, own `responseStatus` changed) (Rsvp.php:400-416) | `apply([resp])`, attendance reset to none when Planned/Maybe (Rsvp.php:417-424) | Source `rsvp`, log-only with touches on invite_json, status, attendance (Rsvp.php:282-290) | No |
| Move an event onto or off a Google calendar | refused: `google_move` E:1435-1436, E:1466-1471; trip move `google_trip_move` E:1438-1439 | Nothing | Nothing | Nothing | n/a |
| Move a whole local calendar to Google | `GoogleMove::run` server/src/Domain/GoogleMove.php:203-344 | masters `POST /events/import` with `iCalUID` and a private extended property `betterCalMove` marker (GW:150-167, GoogleMove.php:256-265); changed occurrences `PATCH /events/{instanceId}` with `instanceBody` (GoogleMove.php:266-280) | `google_event_id` per row (GoogleMove.php:377-387); cutover flips the calendar to subscribed/google, sets `source='feed'` on every row, and `endUndoOf` empties every earlier entry of that calendar (GoogleMove.php:305-321, 355-363); first poll right after (GoogleMove.php:327) | "Moved calendar '...' to Google (N events)", log-only (GoogleMove.php:324-325) | No; it also ends Undo for that calendar's history |

Notes on the table:

- A local-only override (made by an attendance answer) on a Google series is addressed at Google by its instance id when it is first edited (E:417-421).
- Docs drift: docs/google-calendar.md:91 still says "this and following" ends the series at Google with UNTIL and inserts a new one. The code refuses it (Split.php:77-78). docs/google-calendar.md:95 and the client chip title (web/src/app/scopes.js:42) say Google writes can't be undone, which matches the code.
- The comment at E:181 says plugin edits have no undo; they do (E:256-262, U:1219-1265). Stale comment.

## 2. The sync path

Entry: `Feeds::poll` F:35-55 sends a Google calendar to `GoogleSync::poll` GS:39-172. No local ActivityContext is set there; the poll's roll-up entry is recorded under `ActivityContext::with('feed', ...)` inside `Feeds::sync` (F:441-447).

Fetch:
- `GS::listEvents` GS:204-279: `GET /events?showDeleted=true&singleEvents=false&maxResults=...` plus `syncToken` after the first poll (GS:220-230). Series come as masters with `recurrence`, exceptions as their own resources with `originalStartTime`. A 410 restarts with a full list (GS:102-116, GS:256-258).
- No transport seam in GoogleSync: it builds its own `HttpClient` (GS:83-89, GS:233). Only the token refresh has one (`GoogleAuth` constructor's `postFormTransport`, server/src/Domain/GoogleAuth.php:39).

Shape (`GS::toParsed` GS:321-413), per resource:
- Key: `uid` = `iCalUID` (or `id`), `recurrence_instance_utc` = `originalStartTime` as UTC (GS:330-341). The key used everywhere is `uid|instance` (GS:540-543, F:258).
- Kept: `google_event_id` = the resource `id` (GS:345-347, GS:406), title, description, location, url (`source.url` else `htmlLink`, GS:386-389), start/end, all_day, tzid (from `start.timeZone`, else UTC), rrule, EXDATEs (GS:365-376, GS:653-681), status (confirmed or tentative), and `invite_json` only when `attendees` is present (GS:407-412).
- Dropped: `etag`, `updated`, `sequence`, `recurringEventId`, `iCalUID` vs `id` distinction beyond the uid, `originalStartTime.timeZone`, attendees beyond the invite block, reminders (`reminders => []`).
- Cancelled: a resource with `status: cancelled` becomes a tombstone `{cancelled, uid, recurrence_instance_utc}` (GS:342-344).

Merge (`GS::applyChanges` GS:425-486):
- A cancelled master (`instance` null) removes every key of that uid (GS:453-457).
- A cancelled instance removes its key and adds its date to the master's `exdates` (GS:458-461, GS:472-478), only if the master is in the snapshot.
- Anything else replaces its key whole (GS:465-470). A changed master therefore replaces the cached master's `exdates` with the EXDATE lines Google sends, and the dates added earlier from cancelled instances are kept only if they are in Google's lines or arrive again in the same batch (reading of the code; see question Q6).
- Incremental polls start from `GS::rowsToParsed` of every live row (GS:125-131, GS:495-538), which includes local-only overrides and their `google_event_id`.

Write (`GS::poll` GS:137-171, inside `Undo::write`): a binding check (GS:141-144, GS:188-196), then `editedDuringFetch` (GS:149-151, GS:180-185): if the calendar's `synctoken` moved while fetching (any write here, including a write-through's own `apply`), the result is dropped and the old sync token kept, so the next poll re-fetches from before the edit. Then `Feeds::sync` (GS:166) and the new token (GS:168).

`Feeds::sync` F:240-478 on that snapshot:
- Upsert by key; `recurrence_parent_id` from the master's id (F:325-331); new rows get `source 'feed'`, `created_via 'feed'` (F:346-352). Changed columns are compared with `sameValue` (F:198-218; exdates compare as sets) and only the differences are written, each recorded as a touch (F:366-397).
- Own fields (Rows from Elsewhere, docs/design/series-model.md:204-212): for every instance row, `OwnFields::afterSync(stored, incoming, parsedMaster)` (F:340-345; server/src/Domain/Series/OwnFields.php:255-271). Unchanged field: keeps its ownership. Changed at Google: own if it differs from the master's value in the same snapshot, inherited if equal. New row or unknown list: inferred by equality with the master. The masters come from the same snapshot (F:290-296).
- Removal: any stored row whose key isn't in the snapshot is hard-deleted, except a per-occurrence row the person made (`created_via != 'feed'`) while its series is still there (F:401-414). Local-only overrides survive that way on a full list.
- Activity: one log-only roll-up "Feed '<name>': N added, N updated, N removed" with every touch (F:439-448). Source `feed`. A write-through's `apply` passes `journal: false` (GW:229), so no roll-up.
- Answer reset: a changed Google answer clears Planned/Maybe (F:378-380, F:227-232).

Echo of a write made here: the write's `apply` already stored Google's answer, so the next poll sees equal values and changes nothing (GW:213-216 says so). `sameValue` finds no difference, `afterSync` sees the field unchanged and keeps its ownership. When a poll overlapped the write, `editedDuringFetch` drops it. For "all events" on a series, edited days are not sent (SP:870-871, M guards below), so the plan never wrote inherited values locally; whatever Google did to the exception instances arrives as changed instance resources in a later poll and is judged by `afterSync` as a change made at Google. That is the series-model.md:212 rule: "where the app doesn't carry inherited values there (Google before 1.5.3), it doesn't write them locally either".

Ownership of rows edited through the Google "this occurrence" path is not set by the edit (unlike the local `patchThis`, which writes `override_fields` at E:1837-1840): `googlePatchThis` leaves it to `apply` -> `Feeds::sync` -> `afterSync`, which for an existing row marks changed fields own when they differ from the master, and for a new row infers by equality. The local-only part (`override_fields` is not a Google column) would only be written if `columnPatch` put it there; it doesn't in this path.

## 3. Where the series model refuses or allows on Google

The flag: `seriesInput(..., bool $google)` SP:470-560 passes `'google' => $google` in `$opts` (SP:552); Model reads it at M:170. Split reads `$opts['google']` at Split.php:77.

| Code | Where | Condition |
|---|---|---|
| `google` (reading) | M:1082-1089 | a reading whose rule text differs from the stored rule (`textKey()` compare) or the `member` reading; disabled with `REASONS['google']` (M:69) and listed in `refusals` with `group: reading` (M:1100-1103) |
| `google` (whole plan) | M:1106-1115 | every reading disabled: the Google one is chosen as the plan's refusal reason |
| `google` (capability) | M:1277-1279 | a new rule (`newRule()`) on Google |
| `google` (split) | Split.php:75-79, words Split.php:38 | any "this and following" edit, delete or answer from a day after the series' first; `refused()` Split.php:306-313 |
| 422 `series_refused` | SP:599-609 | any refused plan or a disabled option chosen, at commit |
| `google_move`, `google_trip_move` | E:1435-1439, E:1466-1471 | calendar change or trip move |
| `google_unsynced` 409 | E:320-327 | no Google id yet |
| `recurrence_exception_limit` 409 | GW:84-88, GW:103-107 | EXDATE list over the limit |

What Google plans leave out (allowances, all "keeps the rule text"):
- No outcome for days outside the new series, the day acted on included (M:2159-2171).
- No own-field choices: the content-field loop breaks at once (M:2322-2326).
- Generated skips are not rewritten (M:2382), no anchoring (M:1949), no gains at the edges (M:2016), no rejoin when a day owns nothing (M:2473), no acted-day row creation (M:2276).
- The note: `GOOGLE_NOTE` M:73, added at M:2690-2692: "Google decides what happens to edited days, and Better-Cal hasn't checked what it does yet. This can't be undone."
- Docs: docs/design/series-model.md:399-404 lists the allowed edits (a time, length or zone keeping the clock; a drag that keeps the rule text; a field change for all events to the master alone; Ends; delete this; delete all) and everything refused.

The dialog (web/src/app/scopes.js):
- `seriesScopes` scopes.js:24-28: a Google calendar gets `['this', 'all']`, "This and following" left out, its reason from `unofferedWords('google')` (web/src/lib/planwords.js:900, "This and following comes later on Google calendars").
- Drop chip title "Repeating event (goes to Google; can't be undone)" (scopes.js:42).
- The refusal words mirror the server: planwords.js:881 (reading), planwords.js:893 (split).

## 4. The Undo shape for plugin edits, and what Google lacks

Recording (`Events::pluginReschedule` E:202-263): the plugin is called first (`Plugins::applyEdit`, E:228-235), then inside `Undo::write` the confirmed time is stored and the entry recorded (E:248-262):

- `entity 'plugin_edit'`, `entity_id` the event, `op 'update'`.
- `before_json` and `after_json` are `{"tables":[]}` (passed `[]`, encoded by `encodeTables` U:432-435), so the entry is not log-only.
- `details_json`: `{pluginId, span}`, where `span` is what to put back: the plugin's own `undo` span if it gave one, else the time shown before (E:259-262). Span shape `{start, end, allDay}` from `Events::pluginSpan` E:192-199.
- `calendarIds`: the plugin calendar; touches: `Undo::timeTouches` U:1268-1271 (start_utc, end_utc, all_day, tzid on that event).

Reversing (`Undo::undoById` U:1134-1160): log-only refused (U:1146-1151); for `plugin_edit` the stale check runs first (U:1152-1156) and then `undoPluginEdit` U:1219-1265: re-reads the event, checks the calendar is still that plugin's and reschedulable (U:1230-1233), sends `applyEdit` with `before` = the row's current span and `after` = the stored span (U:1237-1246), then inside `write()` stores what the plugin confirms and marks the entry undone (U:1252-1264). Not inside one transaction with the remote call.

Stale check (`staleFor` U:857-904, `conflicts` U:929-959): purely local. It looks for later entries (not undone) whose `mutation_touches` conflict with this entry's touches. A change made at the source only counts once a sync has recorded it as a touch (a Google poll does: F:395-397 and F:441-447). Nothing compares against the remote state at undo time.

Why Google entries can't use it today:
- `googleJournal` passes null snapshots (E:343): log-only by construction.
- `recordCalendarRefs` empties the snapshots of any entry that names a Google calendar (U:1033-1040), on purpose (comment U:1035-1037). A Google entry would need a kind that keeps its payload, as `plugin_edit` does (its calendar isn't Google).
- `apply()` refuses any Google calendar (U:1296-1309), and `GoogleMove::endUndoOf` empties entries at cutover (GoogleMove.php:355-363).

What a Google equivalent needs that the code lacks:
- A before side: today nothing of the event's prior Google-owned state is kept. For a series edit, the master's before state and each affected exception instance's before state (fields, `override_fields`, tags, people, links), keyed by `originalStartTime` (docs/design/series-model.md:411 asks for this before any step that can remove an exception).
- An etag (or `updated`/`sequence`) per Google resource, recorded after each step, to check before writing back (docs/design/series-model.md:411-412). Nothing stores one (section 5).
- Instance ids of exceptions the change touched: derivable from the master id and the key (`GW::instanceId`), but an exception moved at Google keeps its original id while the master's id or start may change; the before snapshot should keep `google_event_id` per row as stored, not recompute it.
- A conditional write: `GW::call` sends no `If-Match` (GW:246-289) and maps 412 to a generic 502.
- A read-back: no `get` follows a patch except in Rsvp (GW:198-201 exists, used only at Rsvp.php:402).
- Idempotent creates: `insert` sends no client-chosen `id` (GW:193-196), so a retry after a lost response could duplicate (the move uses the private-property marker instead, GW:157-191).
- A delete has no before at all locally after `apply` removes the rows, so an undo of a delete needs the full rows (and links) captured before the call; re-creating at Google gets a new `id` unless the app chooses ids (open question Q9).

## 5. State held per Google event

`events` columns that matter (migrations 025, 063, 013, 024):

| Column | Source | Notes |
|---|---|---|
| `uid` | `iCalUID` (or `id`) | key part 1 (GS:330-337) |
| `recurrence_instance_utc` | `originalStartTime` as UTC | key part 2 (GS:339-341); null on masters |
| `recurrence_parent_id` | local master id | F:327-329 |
| `google_event_id` | resource `id` | server/migrations/025_google_writes.sql:10-11; an exception's is the instance-id form |
| Google-owned columns | resource | GW:29 |
| `override_fields` | `OwnFields::afterSync` | server/migrations/063_override_fields.php:33-34 |
| `created_via`, `source` | `feed` for synced rows | F:346-352; distinguishes person-made overrides (F:404-406) |
| `invite_json` | attendees | GS:407-412 |
| `updated_at` | local clock | not Google's `updated` |

`calendars`: `google_account_id`, `google_calendar_id`, `google_sync_token` (024), `google_access_role` (025), `google_binding_version` (server/migrations/042_google_move_integrity.php:88), and the CalDAV `synctoken` counter that `editedDuringFetch` reads (GS:180-185).

Not stored: Google `etag`, `updated`, `sequence`, `recurringEventId`, `originalStartTime.timeZone`, the raw `recurrence` lines (EXDATEs are normalised into `exdates_json`; RRULE through `Recurrence::safeRrule`, GS:370), and whether a local EXDATE came from an EXDATE line or a cancelled instance. For an etag-checked undo, the minimum missing piece is an `etag` per event row (master and every exception), written by `Feeds::sync` from `toParsed` and on every `apply`. For a read-back comparison: the fields already stored are enough for a field compare, but the plan's per-exception expectation keyed by `originalStartTime` has nowhere to live; a pending-plan row (docs/design/series-model.md:411) does not exist yet.

## 6. Tests and stand-ins for Google

All stand-ins are hand-written PHP closures; no recorded responses, no fixture files, no fake server.

- GoogleWriter transport closure: `fn(string $method, string $url, ?array $payload): array{status, body}` passed as GW's fourth constructor argument. The usual one echoes the payload plus an `id` and `iCalUID` (server/tests/db/series-split.test.php:244-247; server/tests/db/undo-trips.test.php:411-414). The fullest one is server/tests/run.php:8713-8730: records every method, can run a hook while "in flight", answers 204 to DELETE, `g-new-N` ids for POST, the path's last segment for PATCH, uid per id from a map.
- GoogleAuth token closure: third constructor argument, `fn(string $url, array $fields, ?float $deadline) => ['status' => 200, 'body' => '{"access_token":...}']` (series-split.test.php:240-243; run.php:8731).
- Account and calendar rows inserted directly: series-split.test.php:251-255, undo-trips.test.php:418-422, tools/edit-harness/Sequences.php:403-404, server/tests/db/completeness.test.php:460-462.
- Rsvp with a Google writer: server/tests/credentials-mysql.php:146-168 (GET then PATCH, race modes).
- What they cover: refusal before any call (series-split.test.php:235-269: split edit and delete following give 422 `series_refused` with code `google`, no call, nothing written; undo-trips.test.php:398-438: a refused trip flag sends nothing, then one PATCH); credential revoked before or during each op (create, copy, edit, edit one occurrence, mark maybe, delete, delete one occurrence, delete a changed occurrence: run.php:8667-8790); undoLatest refusing a log-only Google-style entry (server/tests/db/undo.test.php:332-335); the move dropping undo pieces (undo.test.php:553-582).
- Pure shape tests: `toParsed`, `toParsedList`, cancelled instances, `applyChanges` merge and removal, `rowsToParsed`, `body`, `recurrenceLines`, `instanceId`, `importBody`, `instanceBody`, `writable`, `tombstone` (run.php:11593-11797); `sameBinding`, `editedDuringFetch` (run.php:10205-10215); `recoveredImport` (run.php:10185-10193).
- The poll itself is never run against anything: GS has no seam; completeness.test.php:454-466 runs the poll's write (`Feeds::sync` of a parsed ICS plus the token) inside `Undo::write` instead.
- Series model on Google: server/tests/series-model.php:405-414, 804-806, 1385-1394 (`smPlan(..., ['google' => true])`).
- Harness: tools/edit-harness/Driver.php:999 computes a Google plan for even-numbered "all" edits via reflection on `seriesInput` (Driver.php:1075-1092); tools/edit-harness/Invariants.php:1091-1116 is check `i-google` (rule-text changes refused, rule-keeping edits offered as the local plan with no edited-day claims); Sequences.php:385-400 is `google-split-refused` (`e-google`); tools/edit-harness/PlanUntouched.php:26 lists `google_event_id` among detach columns; mutations at tools/edit-harness/mutations/list.php:87 and 374 target the Google guards. The harness README (tools/edit-harness/README.md:139) says it makes no Google calendar beyond that fixed case.

For a probe harness: the GW transport closure is the seam to wrap (log real requests and responses, or replay them), and the GoogleAuth token closure for auth. A poll needs a new seam in `GS::listEvents` (GS:233) or a recorded-response replay at the `HttpClient` level.

## 7. Questions the code can't answer (for the probe)

Q1. A master PATCH that changes `start`/`end` (time of day, length, zone) on a series with exception instances: do the exceptions keep their `originalStartTime` and own times, get moved, or get dropped? Each kind on its own: a moved date, an own time, a title-only edit, a cancelled instance, one beyond a shortened end.

Q2. A master PATCH with a changed RRULE (COUNT/UNTIL, BYDAY, a clamped rule, `BYMONTHDAY=-1`): what rule text comes back (form, order of parts, UNTIL format), and what happens to exceptions no longer on the rule?

Q3. A master PATCH changing `summary` (or description, location, status): do exception instances whose own value equalled the old master value take the new one? Do those with their own value keep it? Does the incremental sync list the exceptions it changed?

Q4. Our master PATCH always sends `recurrence` with EXDATE lines built from local `exdates_json`, including dates that came from cancelled instances. Does Google accept that and keep both the EXDATE line and the cancelled instance, or merge them? Does it change the instance's `etag`?

Q5. "This occurrence" sends `GW::body` with `recurrence: []` to an instance id (E:422, E:438). Does Google ignore it, reject it, or detach the instance? Is `instanceBody` what it should send?

Q6. When a master changes at Google after an instance was cancelled, does the incremental sync re-send the cancelled instance? If not, `applyChanges` (GS:465-478) replaces the master's merged `exdates` with Google's lines and the skipped day reappears locally. Does Google's master `recurrence` ever carry an EXDATE for an instance cancelled through `DELETE /events/{instanceId}`?

Q7. Etags: is an event's `etag` stable across reads, and does it change for an exception when only the master changes? Does `If-Match` work on PATCH and DELETE of instances, and is the failure 412? Do instance resources from `singleEvents=false` lists carry their own etag?

Q8. Instance ids: after a master start change, do existing exceptions keep their old instance id (old original start) and new occurrences get ids from the new start? Is `GW::instanceId`'s form (timed UTC basic with Z; all-day as the date in the series zone) what Google uses for every case, including a series whose zone differs from the calendar's?

Q9. Undo of a delete: can a deleted event (status cancelled, still listed with `showDeleted`) be brought back by PATCHing `status: confirmed`, keeping its `id`, or must it be re-inserted? Can an insert carry a client-chosen `id` (for idempotent retries and for putting back a deleted event under the same id)? Same for a cancelled instance: can an instance be un-cancelled by PATCH?

Q10. Ordering and atomicity: how many requests does a series plan need (master, then each stranded exception), and does Google ever answer a master PATCH with changes already made to instances (visible only on the next sync)? How soon is a change visible to `syncToken` polling, and does a write made here show up in the next incremental list (the echo GS relies on)?

Q11. `sendUpdates=none` on a series with attendees: confirmed that no mail goes out for master and instance patches, and for a status-only change?

Q12. All-day series: does Google key all-day instances by the date in the event's zone or the calendar's zone, and how are all-day EXDATEs (`VALUE=DATE`) read back?
