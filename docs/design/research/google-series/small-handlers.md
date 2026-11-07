# Small Google Calendar handlers: recurrence survey

Read 2026-11-07 from the public repositories. Versions and commits:

- gcalcli: `insanum/gcalcli` main at `405639c2` (2025-10-25), latest release v4.5.1 (ChangeLog head says v4.5.2, unreleased).
- Home Assistant: `home-assistant/core` dev at `1cc3e51c` (2026-11-07), release 2026.11.0; `homeassistant/components/google/manifest.json` pins `gcal-sync==9.2.0`, `ical==14.3.1`.
- gcal_sync: `allenporter/gcal_sync` main at `ff541985` (2026-11-06), version 9.2.1.
- vdirsyncer: `pimutils/vdirsyncer` main at `ceee70d1` (2026-09-03).
- KDE: `KDE/kdepim-runtime` master at `a7f4ad28` (`resources/google-groupware/`), `KDE/libkgapi` master at `1f3858b9` (`src/calendar/`, `src/core/`), `KDE/korganizer` master at `a404a7e5` (`src/calendarview.cpp`), all 2026-11-07 (GitHub mirrors of invent.kde.org).

"From reading" marks a conclusion drawn from the source that I did not run.

## Summary

None of the four handles all five exception kinds in both directions.

- gcalcli works only on expanded instances (`singleEvents=True`), so single-occurrence edits and deletes work through Google's own instance ids. It has no series-level edit, no "this and following", and no change tracking.
- Home Assistant (through gcal_sync) is the most careful reader: it syncs masters and exceptions with a sync token, handles 410 correctly, and expands recurrence locally while subtracting Google's exceptions. It writes very little: create (one RRULE), delete one occurrence (as a cancelled instance), delete this and following (by truncating UNTIL), delete the series. No edits at all.
- vdirsyncer speaks CalDAV only. Google is reached through its CalDAV endpoint with OAuth. It moves whole iCalendar resources by etag and knows nothing about exception kinds. Editing an existing recurring event and pushing it to Google fails with 409 (open bug #963).
- KDE's Akonadi Google resource is the only real two-way API client of the four, and has the most recurrence bugs: cancelled occurrences from Google are dropped (open bug 518138), timed EXDATEs are lost when writing, RDATE is written with a typo, new exceptions are POSTed as new events, writes use `If-Match: *` (blind overwrite), and a 410 resync never removes stale items.

## 1. gcalcli (Python CLI)

Model: no local store. Every command lists live with `singleEvents=True` (`gcalcli/gcal.py`, `GoogleCalendarInterface._GetAllEvents`), so the user only ever sees and targets instance ids (`<seriesId>_<start>`), never the master. Cancelled items are skipped in the listing.

- Moved occurrence: `edit` then `[w]hen`/`len[g]th` (`_edit_event`, `_SetEventStartEnd`) PATCHes `start`/`end` on the instance id; Google creates the exception. Open #678 reports that editing "when" on a daily event copies the event instead. Also `_SetEventStartEnd` has a typo, `event['e'] - parse(end)` (subtraction, not assignment), so the local end value is never set.
- Skipped: `delete` (`_delete_event` -> `delete`) sends DELETE on the instance id; Google records a cancelled instance. `agendaupdate` with `action=delete` does the same (`actions.py`, `delete`).
- Field-only: `edit` saves with PATCH of `summary, location, start, end, reminders, description, colorId` on the instance id (`_edit_event`, the `s` branch). `agendaupdate` `patch` (`actions.py`, `patch`) PATCHes whatever row id it is given.
- This and following: not offered. Open #157: deleting a series deletes each instance one by one inside the search window and leaves the master.
- Rule or start change: not offered. `add` has no recurrence option (open #312). `import` (`ImportICS`, `ics.py` `CreateEventFromVOBJ`) copies only `RRULE` into `recurrence`; EXDATE, RDATE and RECURRENCE-ID are not read, so an override VEVENT is imported as its own body with the shared `iCalUID` (via `events.import` when the calendar owner is an attendee, else `events.insert`).
- Existing exceptions when the master changes: not applicable (it cannot change a master).
- Refusals: none explicit; missing features simply do not exist. `actions.py` raises `ReadonlyError` for read-only fields on insert and `NotImplementedError` when `length` and an end are both given.
- Change detection: none. Each run re-lists. `updates` (`UpdatesQuery`) re-lists a window and filters by the event's `updated` timestamp. The only cache is the calendar list (pickle, `_get_cached`). No etag, no If-Match, no sync token. No 410 or 412 handling; `_retry_with_backoff` retries only 403 rate limits, and it compares `error.get('code') == '403'` (a string) with what is an integer in Google's error body, so from reading it never retries.
- Change from Google to a series with local exceptions: no local state, so it always shows Google's current expansion.
- Open recurring bugs: #157 deleting a recurring event deletes every instance separately; #181 importing some ended recurring events fails; #678 editing an instance's time copies the event; #51 reminders cannot be added when importing an RRULE event; #312 no way to add a recurring event (feature request).

## 2. Home Assistant `google` integration and gcal_sync

Model: `gcal_sync/sync.py` `CalendarEventSyncManager` stores every Google event (masters, exceptions, cancelled tombstones) by id, listed without `singleEvents` (`api.py` `SyncEventsRequest`: "does not expand recurring events"). HA creates it with `SyncEventsRequest(start_time=now - 90 days)` (`homeassistant/components/google/calendar.py`, `SYNC_EVENT_MIN_TIME`), polling every 15 minutes (`coordinator.py`, `MIN_TIME_BETWEEN_UPDATES`). Search-filtered and free/busy calendars use a plain re-list instead (`CalendarQueryUpdateCoordinator`) and are forced read-only. `DESIGN.md` states the non-goal: "No bi-directional sync".

Expansion (`gcal_sync/timeline.py`, `calendar_timeline`): any event with `recurring_event_id` and `original_start_time` adds that original start to a per-series skip set; cancelled events are then dropped; masters are expanded with `ical`'s rrule (`model.py` `Event.rrule`, which honors RRULE, RDATE, EXDATE) through `FilteredIterable` minus the skip set; non-cancelled exceptions are shown as plain events. Synthetic instance ids copy Google's format (`model.py` `SyntheticEventId.event_id`: `<id>_<UTC yyyymmddThhmmssZ>` or `<id>_<yyyymmdd>`). `model.py` also repairs Google rules (`_validate_rrule`, `_adjust_rrule`: YEARLY+BYMONTHDAY gets BYMONTH; UNTIL/EXDATE coerced to the DTSTART type).

- Moved occurrence: read handled (skip set plus the exception shown at its new time). Write refused: the entity offers only `CREATE_EVENT | DELETE_EVENT` (`calendar.py`, `GoogleCalendarEntity.__init__`), so the frontend reports update as unsupported. gcal_sync has no store-level update; open #704 proposes `async_update_event`.
- Skipped: read handled (cancelled instance goes into the skip set; EXDATE via `ical`). Write handled: `CalendarEventStoreService.async_delete_event(..., Range.NONE)` PATCHes the instance id with `status: cancelled` (`api.py`).
- Field-only: read handled; write refused (same as moved).
- This and following: delete handled, edit refused. `Range.THIS_AND_FUTURE` goes to `_async_truncate_series`, which PATCHes the master's `recurrence` with `COUNT` removed and `UNTIL = instance start - 1 day` (UTC). It raises `ValueError` unless there is exactly one RRULE. It does not touch RDATEs or exceptions after the cut. From reading: the one-day margin assumes nothing recurs more often than daily (the code comment says so); an hourly rule would lose the instances in the day before the cut. Choosing the first instance with THIS_AND_FUTURE deletes the whole series (`_async_resolve_event`).
- Rule or start change: refused (no update path). Create sends only `RRULE:<rule>` from the frontend (`async_create_event`). On read, `_get_calendar_event` reports `rrule` only when `recurrence` has exactly one line, so a master with an EXDATE or RDATE is shown with no rule.
- Existing exceptions when the master changes: gcal_sync stores whatever Google sends. An exception whose original start no longer matches the new rule keeps showing as a plain event if Google still returns it confirmed (from reading).
- Refusals: `ValueError` for unknown or cancelled targets, mismatched iCalUID, or a series not in the store (`_async_resolve_event`); HA turns `ApiException` into `HomeAssistantError`. Read-only calendars (non-writer role or read-only feature access) get no write features (`_get_entity_descriptions`).
- Change detection: sync token (`sync.py` `_run_sync`). On 410 (`auth.py` `_raise_for_status` maps `HTTPStatus.GONE` to `InvalidSyncTokenException`) it clears the token and all stored items and runs a full sync again. A `VERSION` constant bump also wipes the store. No etags and no If-Match on writes, so no 412; writes are last-writer-wins.
- Change from Google to a series with local exceptions: there are no local exceptions (read-only mirror). After each write it calls `coordinator.async_refresh()` to re-sync.
- Open recurring bugs: no open HA issue for Google recurrence (the only open issue labeled `integration: google` is a code cleanup, #179291). gcal_sync: #704 (open) adds update, and asks whether THIS_AND_FUTURE edit should be refused or done by truncate plus new series. Recently closed: HA #158753 (every-3-weeks custom rules on wrong days, a WKST bug in `ical`, fixed).

## 3. vdirsyncer

vdirsyncer speaks CalDAV only. Its `google_calendar` storage (`vdirsyncer/storage/google.py`, `GoogleCalendarStorage`) is `dav.CalDAVStorage` pointed at `https://apidata.googleusercontent.com/caldav/v2/` with an OAuth session (`GoogleSession`). It never calls the Calendar API.

- Unit of sync is one iCalendar resource per UID (master plus its RECURRENCE-ID overrides), compared by hash and etag. There is no notion of exception kinds; all five travel as "the resource changed" (`vdirsyncer/sync/__init__.py`).
- Writes: `dav.py` `_put` sends `If-Match: <etag>` on update and `If-None-Match: *` on create; `delete` sends `If-Match`.
- Change detection: a full etag listing every run (PROPFIND depth 1, or `calendar-query` with a time-range when `start_date`/`end_date` are set; `CalDAVStorage.list`). It does not use `sync-collection` or the ctag, although Google's guide says clients "must switch to this mode of operation after the initial sync".
- 412: `http.py` maps it to `PreconditionFailed` (subclasses `WrongEtagError`, `AlreadyExistingError`, `NotFoundError`); it is not caught, so that collection's sync errors out. 404 and 410 map to `NotFoundError`. 409 is raised generically.
- Change from Google to a series with local exceptions: the whole resource is downloaded and replaces the local file. If both sides changed, `conflict_resolution` picks a whole item (`"a wins"`, `"b wins"`, or an external command); there is no per-occurrence merge (`ResolveConflict`).
- Docs: `docs/config.rst` "Google" says only that VTODO is rejected; nothing on recurrence.

What Google's CalDAV endpoint supports, from the CalDAV API Developer's Guide (developers.google.com/workspace/calendar/caldav/v2/guide):
- Methods GET, PUT, HEAD, DELETE, POST, OPTIONS, PROPFIND, PROPPATCH, REPORT (all reports except free-busy-query). No MKCALENDAR, LOCK, COPY, MOVE.
- "the If* header (except for If-Match)" is not supported. So vdirsyncer's `If-None-Match: *` on create is outside what Google documents.
- RFC 6578 sync-collection and ctag are supported. No VTODO or VJOURNAL. Trivial always-empty scheduling inbox.
- The guide says nothing about RRULE, EXDATE, RECURRENCE-ID or RANGE.

What the record shows for recurrence through that endpoint:
- RECURRENCE-ID overrides in the same resource: work. The DAVx5 team's ical4j 4 test against Google (bitfireAT/synctools #285, closed 2026-04-01) checked "Recurring event with exception syncs fine both ways".
- Modifying an existing recurring resource: vdirsyncer #963 (open) gets HTTP 409 when an edited recurring event is pushed to Google; a commenter narrowed it to "altering recurring events locally", while creating and deleting recurring events, and remote edits, sync fine. That covers adding an EXDATE or an override to an existing series.
- EXDATE: no documentation either way; #963 suggests editing a series by PUT, which is how an EXDATE gets added, can be refused.
- RANGE=THISANDFUTURE: undocumented, and I found no report of it working. Google represents its own "this and following" as a second UID: vdirsyncer #1095 shows resources `<id>@google.com.ics` and `<id>_R20230710@google.com.ics` listed by a time-range query, then missing from the multiget. Treat RANGE as unsupported; a client has to end the old series and create a new UID itself.
- 412 with Google as a backend: DAVx5 #897 (closed as stale) reports edited or deleted instances reverting, with 412s in the log, reproduced by a commenter on Google.

Open recurring bugs: #963 409 when pushing an edited recurring event to Google; #1095 a whole sync aborts when Google lists a split-series resource that the multiget then does not return (a user patch skips it).

## 4. KDE Akonadi Google resource (kdepim-runtime + libkgapi)

Why this one: it is a long-lived, real two-way client of the Calendar API (not CalDAV) that stores masters and exceptions as separate items, so it has to handle every exception kind in both directions. The comparison with gcal_sync is direct. (Thunderbird's Google provider is the other obvious pick; I left it out because a separate survey covers Thunderbird.)

Model: `calendarhandler.cpp` `CalendarHandler::retrieveItems` uses libkgapi `EventFetchJob` without `singleEvents`, so masters and exceptions come back as separate events; each becomes an Akonadi item with remote id = Google event id and remote revision = etag (`slotItemsRetrieved`). libkgapi `calendarservice.cpp` `JSONToEvent` maps `originalStartTime` to `RECURRENCE-ID` and `status: cancelled` to `setDeleted(true)`; `recurringEventId` is declared but never read. KOrganizer joins exceptions to their master by shared UID (`iCalUID`).

- Moved occurrence: read handled (the exception arrives as its own item with RECURRENCE-ID; bug 518138 confirms moved occurrences show correctly). Write: editing an occurrence that is already a Google exception PUTs to its instance id (`itemChanged` -> `EventModifyJob`), which works. Making a new exception goes through KOrganizer `CalendarView::dissociateOccurrence` -> `KCalendarCore::Calendar::createException` -> a new item -> `itemAdded` -> `EventCreateJob`, which POSTs to `events` (or `events/import` for others' invitations) a body with the series' `iCalUID` and `originalStartTime` but no `recurringEventId` (`eventToJSON`). From reading, Google will refuse that as a duplicate iCalUID or make a separate event; it is not how the API creates an exception (PATCH on the instance id). Untested.
- Skipped: read wrong. A cancelled instance becomes a removed item keyed by its instance id; no local item has that id, so nothing changes and the master keeps the occurrence (bug 518138, open, cause traced in its latest comment). Google's events.list docs say cancelled instances are returned when `showDeleted` and `singleEvents` are both false, so a full sync gets them too and drops them the same way. Write wrong for timed events: KOrganizer "delete only this occurrence" calls `addExDateTime` for timed series (`calendarview.cpp`, `ItemActions::Current`), but libkgapi `eventToJSON` serializes only `recurrence()->exDates()` as `EXDATE;VALUE=DATE:yyyyMMdd`, so a timed exclusion is not sent (from reading). All-day exclusions are sent. On read, `parseRDate` turns timed EXDATEs into dates through `QDateTime::fromString(..., Qt::ISODate)` (`core/utils.cpp`), which from reading does not parse the basic `20250108T100000` form, so those dates come back invalid.
- Field-only: same as moved: fine on an existing exception, wrong when creating one.
- This and following: edit wrong: `dissociateOccurrence(..., thisAndFuture=true)` creates an exception with THISANDFUTURE and POSTs it like a moved one; libkgapi has no RANGE field, so the range is lost (from reading). Delete handled: "also future" calls `recur->setEndDate(itemDate.addDays(-1))` on the master and PUTs it.
- Rule or start change: handled as a full PUT of the master (`EventModifyJob`), with two serialization bugs from reading: RDATE is written as `RDATE;VALUE=DATA:` (typo for DATE, `eventToJSON`), and timed EXDATEs are dropped as above, so editing any field of a series with timed exclusions can erase them on Google. The resource does nothing to the local exception items when the master changes; it waits for Google's next sync.
- Refusals: collections from non-editable calendars are `Collection::ReadOnly` (`setupCollection`). Errors surface through `handleError`; 409 is reported as "Conflict. Remote resource is newer than local." (`core/job.cpp`).
- Change detection: sync token kept in the collection's remote revision, with `showDeleted=true` on incremental runs; first sync uses `timeMin` from the "events since" setting. On 410 `EventFetchJob::handleError` clears the token and silently restarts a full fetch, but `slotItemsRetrieved` decides "incremental" from the new token, which is always set after a fetch, so the full result is applied with `itemsRetrievedIncremental` and events deleted during the gap are never removed locally (from reading). 412 cannot happen: `core/modifyjob.cpp` sets `If-Match: *` on every PUT unless a caller set it, and the calendar jobs never do, so writes blindly overwrite Google's newer version even though the etag is stored.
- Change from Google to a series with local exceptions: changes to existing exceptions arrive by instance id and replace the item; cancellations are lost (above); a local exception that never reached Google stays as an orphan item.
- Open recurring bugs (bugs.kde.org, Akonadi / Google Resource): 518138 a single occurrence deleted in Google still shows in KDE; 401881 deleting one occurrence in Google removes the whole series in KOrganizer (older report, may predate the current code); 334569 changes to one occurrence made in Google are not reflected in KOrganizer (2014, reopened); 316312 duplicate events when accepting an invitation.

## Comparison

| Handler | Moved occurrence | Skipped | Field-only | This and following | Rule or start change | Change detection | Other-side change |
|---|---|---|---|---|---|---|---|
| gcalcli | handled: PATCH on instance id (#678 open) | handled: DELETE on instance id | handled: PATCH on instance id | refused: not offered; deletes instances one by one (#157) | refused: no series edit; import keeps RRULE only | wrong: full re-list each run, no etag or token, no 410/412 handling | handled: no local state, shows live data |
| Home Assistant + gcal_sync | refused: no update; read handled | handled: PATCH instance to cancelled; read via skip set | refused: no update; read handled | handled for delete (UNTIL truncate, one RRULE only); refused for edit | refused: create with one RRULE only | handled: sync token, 410 wipes store and resyncs; no If-Match | handled: read-only mirror of Google's masters and exceptions |
| vdirsyncer (CalDAV) | wrong: whole-resource PUT, 409 on edited series (#963) | wrong: same PUT path (#963) | wrong: same PUT path (#963) | wrong: RANGE undocumented by Google; client must split UIDs | wrong: same PUT path (#963) | handled: etag re-list each run, If-Match; 412 aborts the collection; no sync-collection | handled: whole-item replace, whole-item conflict choice |
| KDE Akonadi Google | wrong: new exception POSTed as new event; existing exception PUT works | wrong: Google cancellations dropped (518138); timed EXDATE not written | wrong: same as moved; existing exception PUT works | wrong for edit (RANGE lost on POST); handled for delete (end date) | wrong: full PUT drops timed EXDATEs, RDATE typo | wrong: sync token, but 410 resync keeps stale items; If-Match: * blind overwrite | wrong: cancellations lost; orphan local exceptions kept |
