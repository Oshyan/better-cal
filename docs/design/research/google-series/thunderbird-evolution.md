# Thunderbird and Evolution: how they handle recurring Google events

## Sources read

- Thunderbird Google provider: the "Provider for Google Calendar" add-on is still the maintained path. It was never folded into comm-central (there is no `calendar/providers/gdata` there today). It is now a MailExtension (`kewisch/gdata-provider`, version 139.9.7 in `src/manifest.json`), commit `7a78662ac7317f60964a34f31365b53d08008660` (2026-10-24). The pre-rewrite XPCOM code survives under `src/legacy/modules/old/` for older Thunderbird builds.
- Thunderbird core and its built-in CalDAV provider: `mozilla/releases-comm-central` mirror, commit `ab60cc5ea7300498991dfaaa84c33204bdb482e4` (2026-11-07).
- Evolution Data Server: `GNOME/evolution-data-server` mirror, commit `15755335e9c99b24f11139b1c65db76ba385ea94` (2026-11-05, version 3.63.1). Evolution client: `GNOME/evolution` commit `e004ed39d4d1db8a78b72b87f87c1079750f0679`.
- Issue trackers: GitHub issues for gdata-provider, bugzilla.mozilla.org (product Calendar), and GNOME GitLab issues for evolution-data-server and evolution, all searched on 2026-11-07.

## Summary

- Thunderbird's Google provider talks to the Calendar API v3. Edits to one occurrence go out as a PATCH to the instance id (`<eventId>_<RECURRENCE-ID in UTC>`). Every series-level change, skipping an occurrence included, is a PATCH on the master that resends the whole `recurrence` array (RRULE, RDATE, EXDATE). It syncs with `syncToken`, sends `If-Match: <etag>` on every write, asks the user what to do on a 412, and drops everything for a full re-list on 410. Thunderbird has no "this and following" at all: the button exists but is hidden, and the code path throws `NOT_IMPLEMENTED`.
- Its weak spot is the read side. An incremental sync that returns a changed master without its exceptions writes that master into the cache as a whole new item, so the cached exceptions are dropped (inferred from code, not tested). When the series start changes, Thunderbird core re-keys local exception RECURRENCE-IDs, but the provider never tells Google and then rebuilds the item from the old cached exceptions.
- Evolution has no Calendar API backend. Google calendars go through the generic CalDAV backend at `https://apidata.googleusercontent.com/caldav/v2/`, with a few Google-specific hacks. `gtasks` covers Google Tasks only (no recurrence). Every edit rewrites the whole iCalendar resource (master plus all overrides) with a PUT. EDS does implement "this and future": it puts UNTIL (or a shortened COUNT) on the old series and creates a new event with a new UID. But it leaves the old series' later overrides in place, and by default it PUTs with no `If-Match` at all, so the last writer wins without anyone noticing.
- When a change comes from Google, both CalDAV paths simply replace the whole resource in the cache. That is correct, because Google's CalDAV resource carries the master and its overrides together.

## A. Thunderbird: Provider for Google Calendar (Calendar API v3)

### 1. The five exception kinds

- **One occurrence moved.** Thunderbird core passes the occurrence itself to `modifyItem`. `calGoogleCalendar.onItemUpdated` (`src/background/calendar.js:267`) builds the URI from `getItemPath` (`src/background/utils.js:56`), which appends `_` plus the RECURRENCE-ID converted to UTC (`toICALString`). `patchEvent` (`src/background/items.js:431`) diffs the new and old instance and sends only the changed `start`/`end`, plus `recurringEventId` and `originalStartTime` (items.js:529-531). The method is PATCH with `If-Match`. On read, `jsonToEvent` turns `originalStartTime` into RECURRENCE-ID (items.js:766), and `ItemSaver.processException` attaches it to the parent as a sub-VEVENT (items.js ~1012).
- **One occurrence skipped.** Core does not delete the instance. `calendar-views-utils.js:191` calls `parent.recurrenceInfo.removeOccurrenceAt(rid)`, which adds an EXDATE and removes any override, then modifies the parent. The provider sees a recurrence-set change on the master. `convertRecurrence` (items.js:350) converts every EXDATE/RDATE to UTC, drops the TZID, and sends the full `recurrence` array in a master PATCH. On read, a `status: "cancelled"` instance becomes an EXDATE on the parent (items.js:1022-1028).
- **One occurrence, field only (title).** This is the same path as a move, but only `summary` is in the PATCH body to the instance id.
- **This and following.** Refused. The prompt's "this and all following" button is `hidden="hidden"` with the comment "Button is hidden until all following is implemented" (`calendar/base/content/dialogs/calendar-occurrence-prompt.xhtml`), and `promptOccurrenceModification` throws `NS_ERROR_NOT_IMPLEMENTED` for `MODIFY_FOLLOWING` (`calendar/base/content/item-editing/calendar-item-editing.js:721`). Bugzilla 320178, opened 2005, is still NEW.
- **Rule or start of the whole series.** This is a master PATCH carrying the changed `start`/`end` and the full `recurrence` array if the set changed. Recurring events get `timeZone: "UTC"` added when the start is a bare UTC time, because Google returns 400 otherwise (items.js:543-549, the latest commit). Nothing is sent for existing exceptions.
- **Existing exceptions when the master changes.** Locally, core's `CalRecurrenceInfo.onStartDateChange` (`calendar/base/src/CalRecurrenceInfo.sys.mjs:763`) shifts EXDATEs, RDATEs, UNTIL and every exception's RECURRENCE-ID by the start delta, and leaves the exceptions' own start/end where they were (bug 1890975). The provider's PATCH carries only the shifted EXDATEs. Google is never told about the re-keyed overrides. After the PATCH, `onItemUpdated` (calendar.js:336-350) re-attaches the exceptions from the cached copy, which is the pre-edit version with the old RECURRENCE-IDs. So after a start change, the local overrides no longer line up with the new occurrences until the next sync reconciles. This is inferred from code, not tested. What Google does with its own overrides after a master start change is not visible in this code.

### 2. Edits it makes and edits it refuses

- It makes: instance PATCH (move, field edit, reminders, attendees), master PATCH (rule, start, fields, EXDATE), create (POST, or `events/import` for invitations), and delete (DELETE with `If-Match`).
- It refuses:
  - "This and following" (hidden in core, see above).
  - Setting status to cancelled through an edit: `patchEvent` throws `NS_ERROR_LOSS_OF_SIGNIFICANT_DATA` (items.js:561).
  - Changing the organizer: it throws "Changing organizer requires a move, which is not implemented" (items.js ~585).
  - Read-only and freeBusy calendars, and the birthday calendar: these get `capabilities.mutable = false`, so the UI greys out editing (calendar.js:470-476).

### 3. Change detection, 410 and 412

- Events sync incrementally with `syncToken` (pref `eventSyncToken`), with `showDeleted=true` once a token exists (calendar.js:480-508). Tasks use `updatedMin` from the response `Date` header.
- 410, 404, and 400 "Invalid sync token value." all raise `ResourceGoneError` (`src/background/request.js:158-170`). `onSync` catches it, runs `onResetSync`, which clears the token and the whole cache, and retries once (calendar.js:541-550).
- Every write sends `If-Match` with the item's stored etag, or `*` when forced (`getItemEtag`, utils.js:79). 409, 412 and 400 "Invalid sequence value." raise `ConflictError` (request.js:165-172). The experiment's `modifyItem` and `deleteItem` then call `cal.provider.promptOverwrite`. If the user agrees, it retries with `force: true` (`If-Match: *`). If not, the operation is cancelled and the calendar refreshes (`src/experiments/calendar/parent/ext-calendar-provider.js:405-412, 454-461`).
- A DELETE that returns 404 or 410 counts as success (calendar.js:389).

### 4. Changes from Google to a series with local exceptions

- `ItemSaver.parseEventStream` (items.js:942) sorts each page into parents and exceptions. Exceptions whose parent is not in the delta are looked up in the cache (`complete`, items.js:1055). If the parent cannot be found anywhere and the exception is not cancelled, it builds a fake master from the exception (DTSTART and one RDATE at the RECURRENCE-ID, `X-MOZ-FAKED-MASTER:1`), which covers being invited to a single instance.
- A parent that *is* in the delta goes to `messenger.calendar.items.create`, which calls `modifyItem(item, null)` on the cache (`ext-calendar-items.js:73-88`) and replaces the stored item whole. Only exceptions that came in the same delta are attached. Exceptions changed earlier and not resent are lost from the cache. That happens, for example, when another client PATCHes only the master's title. Inferred from code, not tested.
- A cancelled parent is deleted from the cache. Exceptions of a cancelled parent are ignored.

### 5. Open tracker items about recurring events on Google

- gdata-provider GitHub: no open issue is about recurrence. The 12 open issues are about sync volume, invitations, colors, CPU use and imports. Open #994 "Beta: Updating invitation fails" is the nearest.
- Bugzilla 320178 (NEW, 2005): no "this and future" edit or delete.
- Bugzilla 1819907 (UNCONFIRMED, CalDAV): edits to one occurrence of a Google recurring event silently do not save.
- Bugzilla 2086042 (UNCONFIRMED, CalDAV): Google keeps a PARTSTAT change from a non-organizer but drops their RRULE change, which loops the "Accept" invitation bar forever.
- Bugzilla 1880222 (UNCONFIRMED): copy and paste of recurring events is broken on Google and iCloud.
- Bugzilla 2077452 (NEW): the cache grows without bound from duplicate DESCRIPTION;ALTREP parameters on recurring Google CalDAV events.
- Bugzilla 2007922 (UNCONFIRMED): CalDAV `RANGE=THISANDFUTURE` overrides display wrong. Bugzilla 851041 (UNCONFIRMED): RANGE=THISANDFUTURE is not honored.

### Thunderbird over CalDAV instead (built-in, comm-central)

- Discovery: `CalDavProvider.attemptGoogleOauth` (`calendar/providers/caldav/CalDavProvider.sys.mjs:250`) detects Google by domain or MX record and uses `https://apidata.googleusercontent.com/caldav/v2/<user>/user` with OAuth.
- Writes: `doModifyItem` (`CalDavCalendar.sys.mjs:735`) clones the parent, merges an edited occurrence in with `recurrenceInfo.modifyException`, and PUTs the whole resource with `If-Match: <etag>`. So all five kinds are one resource rewrite. Skip means an EXDATE in the PUT body. "This and following" is unavailable for the same core reason as above.
- Conflicts: 409 or 412 trigger `promptOverwrite`, which offers overwrite (PUT without etag) or reload (`CalDavRequest.sys.mjs:318`, CalDavCalendar.sys.mjs:779).
- Change detection: a WebDAV sync token when the collection advertises sync-collection, otherwise `CS:getctag` plus an etag listing (CalDavCalendar.sys.mjs:1364-1404). On any 4xx to a sync REPORT with a token held, it resets the token and refreshes fully. A 403 with an empty `DAV:error`, or a 429, is treated as a rate limit and skipped (`CalDavRequestHandlers.sys.mjs:533-557`).
- Changes from Google: the whole resource is downloaded again and replaces the cached item, overrides included.

### 6. Copy and avoid

Worth copying:
- Address instances by `<id>_<RID in UTC>` and PATCH only the changed fields.
- Send `If-Match` on every write. Map 412 and "Invalid sequence value" to a conflict the user resolves. Map 410 and "Invalid sync token" to one full re-list with a single retry.
- Turn cancelled instances into EXDATEs on read.
- Build a faked master for an orphan instance.
- Add `timeZone` for recurring events whose start is a bare UTC time.

Gets wrong:
- It replaces a cached series whole from a partial delta, which drops exceptions.
- On a start change it re-keys overrides only locally, then rebuilds from the stale cache.
- It has no "this and following" at all.
- Skip resends the entire recurrence array instead of cancelling the instance (`status: cancelled` on the instance id). That races with other clients editing the rule.

## B. Evolution Data Server

### Which path is current

- `src/modules/google-backend/module-google-backend.c:500-512` configures Google calendars as CalDAV: `https://apidata.googleusercontent.com/caldav/v2/` with OAuth2, or `https://www.google.com/calendar/dav/` with GOA or passwords. No Calendar API backend exists under `src/calendar/backends/`.
- `src/calendar/backends/gtasks/e-cal-backend-gtasks.c` handles Google Tasks only. It advertises `NO_THISANDFUTURE` and `NO_THISANDPRIOR` (line 381), and its comment at line 629 notes that Google does not bump `updated` or `etag` on some changes.
- The CalDAV backend sets `is_google` by host (`src/calendar/backends/caldav/e-cal-backend-caldav.c:104`) and uses it only for href guessing, username domain and one error swallow (see section 2).

### 1. The five exception kinds

All edits go through `ECalMetaBackend` (`src/calendar/libedata-cal/e-cal-meta-backend.c`), which rebuilds the full instance list for the UID, then `ecb_caldav_save_component_sync` (e-cal-backend-caldav.c:1785). That function merges the list into one VCALENDAR and PUTs it.

- **One occurrence moved, or field only.** With `E_CAL_OBJ_MOD_THIS`, `ecmb_modify_object_sync` (e-cal-meta-backend.c:1911) replaces or appends the detached instance and PUTs master plus all overrides. Read-back is the whole resource.
- **One occurrence skipped.** `ecmb_remove_object_sync` with THIS calls `e_cal_util_remove_instances_ex` (e-cal-meta-backend.c:2267), which adds an EXDATE to the master and removes that instance's override. Then it PUTs the resource. If the skipped occurrence was the last remaining one, it becomes a DELETE of the whole resource (`e_cal_util_check_may_remove_all`).
- **This and following.** `E_CAL_OBJ_MOD_THIS_AND_FUTURE` (e-cal-meta-backend.c:1943-2010):
  - When the edited occurrence is the first one, the edit is treated as a whole-series edit.
  - Otherwise `e_cal_util_split_at_instance_ex` (`src/calendar/libecal/e-cal-util.c:1993`) clones the series starting at that occurrence, and `ecmb_create_object_sync` creates the clone as a new resource with a fresh UID.
  - Then `e_cal_util_remove_instances_ex` truncates the old rule: it sets UNTIL, or shortens COUNT when the rule used COUNT, and removes EXDATEs and RDATEs past the split. The old master is PUT afterwards.
  - The two writes are separate and not atomic.
  - The modify path does not remove the old series' overrides after the split. They stay in the old resource, past its new UNTIL, and are not copied to the new series. The delete-this-and-future path does remove them (e-cal-meta-backend.c:2305-2340).
- **Rule or start of the whole series.** `E_CAL_OBJ_MOD_ALL` replaces the master component and keeps every existing override untouched (e-cal-meta-backend.c:1926-1940). Nothing re-keys RECURRENCE-IDs when DTSTART moves. Evolution issue 1748 (open since 2021) asks for exactly that. I found no such logic in Evolution's `e-comp-editor.c` or `comp-util.c` either.

### 2. Edits it makes and edits it refuses

- It makes: THIS, THIS_AND_FUTURE, and ALL for both modify and remove. The CalDAV backend advertises only `NO_THISANDPRIOR` (e-cal-backend-caldav.c:2783), so Evolution's recurrence dialog offers "this and future" for Google (`evolution/src/calendar/gui/e-cal-dialogs.c:1162-1190`).
- It refuses: "Cannot modify all instances from a detached instance" when a detached instance is sent with MOD_ALL (e-cal-meta-backend.c:1928). THISANDPRIOR is not offered.
- Silent failure: an HTTP 400 on overwrite is turned into success, with the change kept only in the local cache. The comment says "Google can reject saving for events organized by someone else" (e-cal-backend-caldav.c:1940-1946). That applies to *any* 400, recurrence errors included, and the next refresh does not repair it unless the server copy changes. Evolution issue 3364 (open, 2026) is a 400 on PUT for events created on Android.

### 3. Change detection, 410 and 412

- `ecb_caldav_get_changes_sync` (e-cal-backend-caldav.c:1090) uses `sync-collection` if the server advertises it and no download-days limit is set. A 412, or a 403 with `valid-sync-token`, restarts with no token, which means a full listing. Otherwise it checks `getctag`, then lists etags and compares them to the cache. Any other sync failure falls back to the full listing.
- The default write mode is overwrite. Evolution passes `E_CAL_OPERATION_FLAG_NONE` (`evolution/src/calendar/gui/e-cal-ops.c:278`), which `e_cal_util_operation_flags_to_conflict_resolution` maps to `KEEP_LOCAL` (e-cal-util.c:3401-3413). `KEEP_LOCAL` sets `force_write`, so the PUT carries no `If-Match` (e-cal-backend-caldav.c:1858-1868, `e_webdav_session_put_data_sync`). Concurrent edits from Google are overwritten with no prompt.
- Offline changes are uploaded during refresh with `KEEP_SERVER` (e-cal-meta-backend.c:752). Those PUTs do carry `If-Match`. On a 412 the local change is dropped "as success" and the server copy wins (e-cal-backend-caldav.c:1947-1955). Only callers that pass `CONFLICT_FAIL` get `E_CLIENT_ERROR_OUT_OF_SYNC`.

### 4. Changes from Google to a series with local exceptions

- A changed href is downloaded whole. `ecmb_put_instances` (e-cal-meta-backend.c:1020) writes every component in the new resource and removes any cached instance for that UID that is no longer in it. Google's overrides are taken as they are. Local overrides that Google dropped are removed. Nothing is re-keyed or merged.

### 5. Open tracker items

- evolution 1748 (open, 2021): when DTSTART of a recurring event changes, RECURRENCE-IDs of the remaining overrides are not updated.
- evolution 3364 (open, 2026): Google CalDAV returns 400 when modifying events created on Android.
- evolution 840 (open, enhancement): the recurring-edit dialog offers three choices even for the first instance, where two would do.
- evolution 3371 (open, 2026): recurring events should show the first instance (a display issue).
- evolution-data-server: no open issue matches Google plus recurrence.

### 6. Copy and avoid

Worth copying:
- It offers "this and following" and splits it as UNTIL on the old series plus a new series with a new UID. COUNT rules are shortened correctly.
- It turns a skip of the last remaining occurrence into a delete of the whole event.
- On a sync-token 412 or 403 `valid-sync-token`, it falls back to a full listing.
- It treats the server resource as the truth for which overrides exist.

Gets wrong:
- It overwrites with no etag by default (silent lost updates).
- It turns any 400 into local-only success.
- On a split it leaves overrides on the old series past UNTIL.
- It never re-keys overrides when the series start moves.
- The split is two non-atomic writes, new series first.

## Table

| Handler | Moved occurrence | Skipped | Field-only | This and following | Rule or start change | Change detection | Other-side change |
|---|---|---|---|---|---|---|---|
| Thunderbird, Google provider (API v3) | handled: PATCH to `id_RID`, start/end only | handled: master PATCH with the full recurrence array plus EXDATE | handled: instance PATCH with only the changed field | refused: button hidden, code throws NOT_IMPLEMENTED | wrong: master PATCH; overrides re-keyed locally only, then rebuilt from the stale cache | handled: syncToken, If-Match, 412 prompt, 410 full reset | wrong: master in a delta replaces the cache item and drops unsent exceptions (inferred from code) |
| Thunderbird over CalDAV | handled: whole-resource PUT with If-Match | handled: EXDATE in the PUT | handled: whole-resource PUT | refused: same hidden button | wrong: same local re-key, then a PUT of the whole resource | handled: sync token or ctag plus etags, 409/412 prompt | handled: resource replaced whole |
| Evolution Data Server (CalDAV) | handled: whole-resource PUT | handled: EXDATE plus removing the override | handled: whole-resource PUT | wrong: UNTIL plus a new UID works, but old overrides past UNTIL are kept | wrong: master replaced, overrides never re-keyed (issue 1748) | wrong: sync token or ctag plus etags is fine, but PUTs carry no If-Match and any 400 becomes local-only success | handled: resource replaced whole, stale overrides removed |
