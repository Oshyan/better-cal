# CalDAV servers and clients: recurring-event exceptions and "this and following"

Survey date: 2026-11-07. Sources are RFC text, project source code at its current default branch, and bug trackers. Code references give the repository file and function. Where no primary source exists (Apple's closed clients, iCloud, Google's CalDAV internals), the claim is marked unverified instead of guessed.

## Summary

- The standard has two ways to say "this and following": a `RECURRENCE-ID;RANGE=THISANDFUTURE` override inside the same resource (RFC 5545), or a split: truncate the old series with `UNTIL`/`COUNT` and start a new series under a new UID. RFC 5545 itself suggests the split for anything the RANGE delta model cannot express, and JSCalendar (RFC 8984) makes the split mandatory.
- No server surveyed honors RANGE=THISANDFUTURE when expanding, except Apple's CalendarServer. sabre/dav (Nextcloud, Baikal) says so in its code; Cyrus and Radicale have no RANGE handling at all; Google documents nothing. Everyone stores the bytes, so a RANGE override survives a round trip but is shown as a single-instance override by most readers.
- Every client surveyed that offers "this and following" writes a split, not RANGE: Nextcloud web (calendar-js), Evolution, the AOSP/Etar calendar on Android (and so DAVx5 uploads), and by every secondhand account Apple Calendar. Thunderbird has no "this and following" at all (the code path throws "not implemented"). Evolution is the one client that emits RANGE=THISANDFUTURE, and only in the iTIP CANCEL it sends attendees, not in stored data.
- On a split, clients disagree about overrides after the split point: calendar-js drops them by design; Evolution leaves them in the old resource past its new UNTIL (code reading); AOSP does not carry them to the new series.
- On a master start change, Thunderbird and calendar-js re-key existing overrides (and EXDATEs, RDATEs, UNTIL) by the start delta. Evolution's backend and the Android provider do not re-key. Apple's CalendarServer repairs orphaned overrides on write by adding an RDATE for each one, and removes any EXDATE that names an override.
- On a master field change ("all occurrences"), no surveyed client pushes the change into existing overrides; overrides keep their own stored copy of every field. Nextcloud's own tracker shows this biting attendees (PR #8456).
- Sync is uniform: strong ETags with `If-Match` and 412, plus RFC 6578 sync-token where offered, with getctag as fallback. Only Cyrus and CalendarServer implement RFC 6638 `Schedule-Tag` merging.

## 1. The standard

### RFC 5545 (iCalendar)

- RECURRENCE-ID names an instance by its *original* start: "if the intent is to change a Friday meeting to Thursday, the DATE-TIME is still set to the original Friday meeting." Its value type MUST match DTSTART's, and it is floating if and only if DTSTART is floating. https://www.rfc-editor.org/rfc/rfc5545#section-3.8.4.4
- RANGE: the only allowed value is THISANDFUTURE; THISANDPRIOR is deprecated and "MUST NOT be generated." Absent RANGE means one instance. https://www.rfc-editor.org/rfc/rfc5545#section-3.2.13
- What THISANDFUTURE means (3.8.4.4): the override applies to its instance and all later ones, where "later" is judged by RECURRENCE-ID, not current start. If the override's start moves, every later instance moves by the same delta; if its duration changes, every later instance gets that duration. Changed properties apply forward. "Subsequent instances defined in separate components are not impacted by the given recurrence instance": an existing single override later in the series keeps its own data.
- The same section concedes the limit: RANGE cannot express "move only the future Mondays of a Mon/Wed series to Tuesday"; for that, "the calendar application could simply truncate the unbounded recurring calendar component (i.e., with the COUNT or UNTIL rule parts), and create two new unbounded recurring calendar components." https://www.rfc-editor.org/rfc/rfc5545#section-3.8.4.4
- Recurrence set: DTSTART is the first instance; the set is RRULE plus RDATE minus EXDATE, and "EXDATE ... take precedence over" RRULE and RDATE. Multiple RRULEs are undefined; a DTSTART that does not match the rule gives an undefined set. https://www.rfc-editor.org/rfc/rfc5545#section-3.8.5.1 and #section-3.8.5.3
- The text says nothing explicit about an EXDATE that names an instance which also has an override. Implementations split on this (see CalendarServer and sabre below).
- UNTIL MUST have DTSTART's value type; for a TZID or UTC DTSTART, UNTIL MUST be UTC; for floating, floating. https://www.rfc-editor.org/rfc/rfc5545#section-3.3.10

### Emulating "this and following" without RANGE (the split)

- Old series: set `UNTIL` to just before the split instance (or reduce `COUNT`), and drop RDATEs, EXDATEs and overrides at or after the split point. New series: a new UID, DTSTART at the split instance, the remaining rule (COUNT reduced by the instances already used), and new values for the changed fields.
- Common UNTIL choice: split instance minus one second, in UTC. calendar-js says "That's also how macOS does it" (`removeThisOccurrence`, https://github.com/nextcloud/calendar-js/blob/main/src/components/root/abstractRecurringComponent.js). Apple's split extension uses minus one second for DATE-TIME and minus one day for DATE (caldav-recursplit, below).
- Linking the halves: RFC 8984 (JSCalendar) requires the split for "this and future": "the original object MUST be truncated to end at the previous occurrence before this split, and a new object is created," with `relatedTo` "next" on the old and "first" on the new. https://www.rfc-editor.org/rfc/rfc8984#section-4.1.3. RFC 9253 adds the iCalendar equivalents `RELTYPE=FIRST` and `RELTYPE=NEXT` (https://www.rfc-editor.org/rfc/rfc9253#section-5). In practice clients use other links: calendar-js `RELTYPE=SIBLING`, CalendarServer `RELTYPE=X-CALENDARSERVER-RECURRENCE-SET`.
- JSCalendar overrides are patches, so an override inherits every field it does not patch, and `excluded: true` is the EXDATE equivalent (https://www.rfc-editor.org/rfc/rfc8984#section-4.3.5). iCalendar overrides are full components, so a master field edit does not reach them unless the client copies it.

### RFC 4791 (CalDAV)

- All components with one UID live in one calendar object resource, and a resource may hold only overrides without the master ("without also including the master recurring component"). https://www.rfc-editor.org/rfc/rfc4791#section-4.1
- PUT preconditions: `CALDAV:no-uid-conflict` (the server SHOULD report the href already using the UID) and `CALDAV:valid-calendar-object-resource`. So a split's new half must use a new UID or the PUT fails. https://www.rfc-editor.org/rfc/rfc4791#section-5.3.2.1
- Strong ETags are required on calendar object resources; updates use `If-Match`. https://www.rfc-editor.org/rfc/rfc4791#section-5.3.4
- Queries: the server MUST expand recurrences for time-range tests, and an override matches on its current *or original* time. `limit-recurrence-set` must also return RANGE overrides that affect instances inside the range, even if the override itself lies outside it. https://www.rfc-editor.org/rfc/rfc4791#section-9.6.6 and #section-7.8.2

### RFC 5546 (iTIP) and RFC 6638 (CalDAV scheduling)

- CANCEL of "this and following" can be sent as one component with `RECURRENCE-ID;RANGE=THISANDFUTURE`, or as one component per cancelled instance. https://www.rfc-editor.org/rfc/rfc5546#section-3.2.5
- The iTIP example "Change All Future Instances" writes `RECURRENCE-ID;THISANDFUTURE:` without `RANGE=`, which is malformed. Don't copy it. https://www.rfc-editor.org/rfc/rfc5546#section-4.4.5
- With server scheduling, the server compares old and new data "on a per-instance basis" to decide REQUEST/CANCEL per attendee. https://www.rfc-editor.org/rfc/rfc6638#section-3.2.1.2
- Attendees only see instances they are invited to; an attendee removed from one override receives an EXDATE in the master instead. https://www.rfc-editor.org/rfc/rfc6638#section-3.2.6
- Scheduling cost of a split: truncating the old series sends REQUESTs with the shortened rule, and the new UID is a brand-new invitation. Attendees lose PARTSTAT for the future part and must answer again. Apple's split extension exists because of this ("attendee loose all their status for the ongoing instances") (caldav-recursplit, below). calendar-js sets RSVP=TRUE on every attendee of the new half for the same reason.
- `Schedule-Tag` / `If-Schedule-Tag-Match` lets a client write over an attendee-reply change without a 412. https://www.rfc-editor.org/rfc/rfc6638#section-3.2.10

### Later RFCs

- RFC 7986 (new iCalendar properties) does not touch recurrence (no recurrence text in it).
- RFC 7529 (RSCALE) extends RRULE for non-Gregorian calendars and adds SKIP for dates that don't exist (Feb 29, Jan 31 + 1 month). https://www.rfc-editor.org/rfc/rfc7529
- RFC 8984 and RFC 9253, as above.
- draft-ietf-calext-icalendar-series (series with per-instance entities, split linking) expired in 2021. https://datatracker.ietf.org/doc/draft-ietf-calext-icalendar-series/

## 2. Servers

### sabre/dav and sabre/vobject (Nextcloud, Baikal)

- RANGE: stored as written, but not applied when expanding. The class docblock says "The recurrence iterator also does not yet support THISANDFUTURE." https://github.com/sabre-io/vobject/blob/master/lib/Recur/EventIterator.php (class `EventIterator` docblock). A RANGE override expands as a single-instance override. PUT validation (`validateICalendar` in https://github.com/sabre-io/dav/blob/master/lib/CalDAV/Plugin.php) does not reject it.
- Storage: one row per resource with the raw iCalendar text; the master and overrides stay together. `EventIterator::__construct` puts every RECURRENCE-ID into one `exceptions` map (the same map EXDATEs use, keyed by timestamp) and keeps the override components separately. If the master is missing it promotes the first override ("This may not always give the desired result").
- Override plus EXDATE on the same instance: the EXDATE only suppresses the rule-generated date. The override is emitted from its own list, so the override still shows (from `EventIterator::next`).
- Master change with overrides: the server rewrites nothing. Overrides whose RECURRENCE-ID no longer matches the rule are still emitted at their own DTSTART. Nextcloud stores first/last occurrence columns from the master's range, so a moved override outside that range drops out of time-range queries: nextcloud/server #65979 https://github.com/nextcloud/server/issues/65979
- Scheduling: `Broker::parseEvent` / `parseEventForOrganizer` diff old and new per instance and emit REQUEST/CANCEL, merging EXDATEs for removed attendees. https://github.com/sabre-io/vobject/blob/master/lib/ITip/Broker.php. There is no Schedule-Tag merging (not found in `Schedule/Plugin.php`).
- Sync: sync-token from the `calendarchanges` table (`getChangesForCalendar`); getctag derives from the same counter (`getCalendarsForUser`). https://github.com/sabre-io/dav/blob/master/lib/CalDAV/Backend/PDO.php. ETag is stored with the object (an MD5 of the data). `If-Match` is checked by the sabre/dav server core and fails with 412.

### Cyrus IMAP (Fastmail)

- RANGE: no handling. "THISANDFUTURE" appears nowhere in `imap/http_caldav.c`, `imap/ical_support.c`, `imap/caldav_util.c`, `imap/http_caldav_sched.c` or `imap/caldav_db.c` (master branch, checked 2026-11-07). Data is stored as sent, one resource per UID. Expansion (`expand` support in `http_caldav.c`) treats overrides individually.
- Master change: no rewriting found. Cyrus issue #3264 proposes rejecting resources that hold only a RECURRENCE-ID component, which RFC 4791 allows. https://github.com/cyrusimap/cyrus-imapd/issues/3264
- Fastmail's own UI speaks JMAP; JSCalendar requires the split (RFC 8984 4.1.3). Whether Fastmail's UI follows that for "this and following" is unverified.
- Sync and conflicts: sync-collection REPORT, getctag, ETags with 412 in `caldav_check_precond`, which also implements `If-Schedule-Tag-Match` (with a special case for Apple clients sending an empty value). https://github.com/cyrusimap/cyrus-imapd/blob/master/imap/http_caldav.c

### Apple CalendarServer (CCS), and iCloud

- RANGE: honored. `Component.getRange` detects it (https://github.com/apple/ccs-calendarserver/blob/master/twistedcaldav/ical.py), and `InstanceList._addOverrideComponent` applies the start shift to later instances (https://github.com/apple/ccs-calendarserver/blob/master/twistedcaldav/instance.py; THISANDPRIOR is a TODO).
- Repair on write: `Component.validRecurrenceIDs` removes any EXDATE that matches an existing override ("Removed EXDATE for valid override"), drops EXDATEs before DTSTART, and adds an RDATE for every override whose RECURRENCE-ID the rule no longer produces ("Added RDATE for invalid occurrence"). So after a master start or rule change, orphaned overrides are kept by turning them into extra dates, not dropped. Same file.
- Split extension: `POST <resource>?action=split&rid=<split point>[&uid=<uid for the past part>]`, advertised as `calendarserver-recurrence-split` in DAV. The server carves out the *past* instances into a new resource with a new UID; the ongoing series keeps the original UID and attendee data. Both halves get `RELATED-TO;RELTYPE=X-CALENDARSERVER-RECURRENCE-SET`. Spec: https://github.com/apple/ccs-calendarserver/blob/master/doc/Extensions/caldav-recursplit.txt. Code: `iCalSplitter.split` (https://github.com/apple/ccs-calendarserver/blob/master/txdav/caldav/datastore/scheduling/icalsplitter.py) and `POST_handler_split` (https://github.com/apple/ccs-calendarserver/blob/master/twistedcaldav/storebridge.py). The server also splits large organizer events on its own (`Splitting` in `twistedcaldav/stdconfig.py`: over 100 KB, split 14 days back).
- Sync: ctag (CCS invented it), sync-collection, ETags plus Schedule-Tag (weak-ETag handling for implicit scheduling in `storebridge.py`).
- iCloud: descends from this lineage but is closed. Whether it honors RANGE, repairs orphans, or offers the split action is unverified.

### Radicale

- RANGE: no handling anywhere in `radicale/` (non-test code, master branch). Expansion is `_expand` in https://github.com/Kozea/Radicale/blob/master/radicale/app/report.py. Filters use vobject's `getrruleset` and skip dates that have overrides (`radicale/item/filter.py`).
- Storage: one file per resource, as sent. `_split_overridden_vevents` raises an error when an override's RECURRENCE-ID value type differs from DTSTART's (RFC 5545 3.8.4.4), and when there are two masters. Same file.
- Master change: no rewriting.
- Sync and conflicts: sync-token (`storage/multifilesystem/sync.py`), getctag, `If-Match` and `If-None-Match: *` give 412 in `do_PUT` (https://github.com/Kozea/Radicale/blob/master/radicale/app/put.py), with an optional strict mode that refuses a PUT over an existing item without If-Match. `no-uid-conflict` gives 409.

### Google's CalDAV endpoint

- Documented: ctag ("changes when anything in the calendar has changed"), a trivial always-empty scheduling inbox, `If-Match` as the only supported If-header, no free-busy, no VTODO/VJOURNAL, no MKCALENDAR. https://developers.google.com/workspace/calendar/caldav/v2/guide
- Not documented: RANGE, how exceptions are grouped into resources, what happens to overrides on a master change. Google's own model splits a series for "all following" (see the Google survey). Any CalDAV behavior beyond the guide needs a live test.

## 3. Clients

### Apple Calendar (macOS, iOS)

- Closed source. Secondhand evidence only: calendar-js says macOS sets UNTIL to the split instance minus one second, and CalendarServer's split spec describes truncating the rule plus a new resource as the usual client approach. No primary source says whether Apple Calendar calls `action=split` on servers that advertise it, writes RANGE, or what it does with overrides on a master change. All Apple cells in the table are unverified.

### Thunderbird (CalDAV provider)

- "This and following": not implemented. `promptOccurrenceModification` throws `NS_ERROR_NOT_IMPLEMENTED` for MODIFY_FOLLOWING ("TODO tbd in a different bug"). https://github.com/mozilla/releases-comm-central/blob/master/calendar/base/content/item-editing/calendar-item-editing.js. Tracked as bug 320178 (https://bugzilla.mozilla.org/show_bug.cgi?id=320178).
- RANGE on read: not honored, bug 851041 (https://bugzilla.mozilla.org/show_bug.cgi?id=851041) and bug 2007922 (https://bugzilla.mozilla.org/show_bug.cgi?id=2007922).
- Moved or field-only occurrence: an exception item keyed by its original RECURRENCE-ID, which never changes even if its DTSTART does (comment above `modifyException`). Each exception is a full item.
- Skipped: EXDATE (negative rules in `calculateDates`). An EXDATE of type DATE also matches a DATE-TIME DTSTART (bug 734245, cited in `calculateDates`). Exceptions are added before negative rules are applied, so an EXDATE does not hide an override.
- Master start change: `onStartDateChange` shifts RDATEs, EXDATEs, UNTIL and every exception's RECURRENCE-ID by the start delta, tracking DST in the DTSTART or RDATE zone. The exceptions' own DTSTARTs are not shifted. https://github.com/mozilla/releases-comm-central/blob/master/calendar/base/src/CalRecurrenceInfo.sys.mjs
- Rule change: exceptions are kept. `calculateDates` puts every exception into the result first, whether or not the new rule still produces its RECURRENCE-ID, so orphans still show. Master field changes do not propagate into exceptions (none found in the code; exceptions are full copies).
- Sync and conflicts: sync-token (webdav-sync) when the server offers it, else getctag; ETag `If-Match`; on a conflict `doModifyItem` calls `promptOverwrite`, which asks the user. https://github.com/mozilla/releases-comm-central/blob/master/calendar/providers/caldav/CalDavCalendar.sys.mjs

### DAVx5 with the Android calendar provider

- Mapping (synctools, now inside https://github.com/bitfireAT/davx5-ose): each override becomes its own provider row with `ORIGINAL_INSTANCE_TIME`. `OriginalInstanceTimeBuilder.build` rewrites a RECURRENCE-ID whose value type differs from the master's DTSTART (DATE-TIME to DATE for all-day, DATE to DATE-TIME using DTSTART's time and zone). On upload, `AndroidEventHandler.mapToVEvents` turns a `STATUS_CANCELED` exception row into an EXDATE on the master (with VALUE=DATE for all-day), and other exception rows into RECURRENCE-ID components.
- RANGE: no handling in synctools, so a RANGE override arrives as a single-instance exception.
- "This and following" on Android (AOSP, kept in Etar): `EditEventHelper.saveEvent` with MODIFY_ALL_FOLLOWING calls `updatePastEvents` to cap the old rule (UNTIL, or COUNT reduced in both halves) and inserts a new event row. DAVx5 uploads that row as a new UID. https://github.com/Etar-Group/Etar-Calendar/blob/master/app/src/main/java/com/android/calendar/event/EditEventHelper.java. The provider's `handleInsertException` does the same split when given an RRULE ("Cap the original event, and create a new recurring event"); the new event "is not tied to the previous event in any way," so overrides are not carried over. https://github.com/LineageOS/android_packages_providers_CalendarProvider/blob/lineage-22.2/src/com/android/providers/calendar/CalendarProvider2.java
- Master start or rule change: the provider's `handleUpdateEvents` recomputes LAST_DATE but does not touch exception rows (code reading), so exceptions keep their old `ORIGINAL_INSTANCE_TIME`. The DAVx5 FAQ: "Changing a master event that has exceptions is error-prone." https://www.davx5.com/faq/recurring-events
- Sync and conflicts: DAVx5 uses sync-token or ctag and ETag `If-Match`. Not re-read for this survey; see the DAVx5 manual.

### Evolution (evolution-data-server CalDAV backend)

- The UI offers "This Instance Only", "This and Future Instances" and "All Instances". The CalDAV backend advertises `NO_THISANDPRIOR` but not `NO_THISANDFUTURE`, so the future option shows. `e_cal_dialogs_recur_component` in https://github.com/GNOME/evolution/blob/master/src/calendar/gui/e-cal-dialogs.c; capabilities in https://github.com/GNOME/evolution-data-server/blob/master/src/calendar/backends/caldav/e-cal-backend-caldav.c
- "This and following" storage: a split. `ecmb_modify_object_sync` with `E_CAL_OBJ_MOD_THIS_AND_FUTURE` calls `e_cal_util_split_at_instance_ex`, truncates the master with `e_cal_util_remove_instances_ex` (UNTIL, or a recounted COUNT), gives the future part a new UID, and creates it as a new object. At the first instance it just replaces the master. https://github.com/GNOME/evolution-data-server/blob/master/src/calendar/libedata-cal/e-cal-meta-backend.c, and https://github.com/GNOME/evolution-data-server/blob/master/src/calendar/libecal/e-cal-util.c
- Overrides after the split point: the modify path does not filter the detached instances, so they stay in the old resource past its new end (code reading; the *remove* path does drop detached instances on or after the point).
- RANGE: Evolution writes `RANGE=THISANDFUTURE` only in the iTIP CANCEL for "delete this and future" with attendees (`cal_comp_util_remove_component`, https://github.com/GNOME/evolution/blob/master/src/calendar/gui/comp-util.c). On the receiving side, `ecmb_receive_object_sync` maps an incoming RANGE=THISANDFUTURE to the split mode. Stored data never carries RANGE.
- "All Instances" (`E_CAL_OBJ_MOD_ALL`): replaces the master only. Detached instances are kept unchanged and not re-keyed on a start change. "Cannot modify all instances from a detached instance."
- Sync and conflicts: sync-collection with getctag fallback; PUT with the stored ETag as If-Match. `E_CONFLICT_RESOLUTION_KEEP_LOCAL` sends no ETag (a blind overwrite). A 412 on a stale sync token triggers a full resync. `ecb_caldav_save_component_sync`, `ecb_caldav_get_changes_sync`.

### Nextcloud web calendar (calendar-js)

- RANGE on read: honored. `RecurrenceManager` keeps a set of RANGE overrides, and `_getOccurrenceAtRecurrenceId` forks later instances from them with the start difference. https://github.com/nextcloud/calendar-js/blob/main/src/recurrence/recurrenceManager.js
- RANGE on write: never. In `createRecurrenceException(thisAndAllFuture)`: "The overall support for RANGE=THISANDFUTURE is really bad. Instead, we have to create a new event ... and set an until date on the old one." The new half gets a new UID, `RELATED-TO;RELTYPE=SIBLING` both ways, no RDATE/EXDATE/RECURRENCE-ID, SEQUENCE 0, and RSVP=TRUE on all attendees. https://github.com/nextcloud/calendar-js/blob/main/src/components/root/abstractRecurringComponent.js
- Future overrides on a split are dropped by design: "this replaces all future occurrence modifications, including recurrence-exceptions, RDATES and EXDATES" (same function). `removeThisOccurrence(true)` sets UNTIL to the split minus one second (UTC) and deletes RDATEs, EXDATEs and overrides at or after the split.
- Single occurrence: a moved or edited occurrence becomes an override with RRULE/RDATE/EXDATE stripped. A skip adds an EXDATE, or removes the RDATE if the instance came from one, and deletes the override if there was one.
- "This and all future" from the first occurrence (`_overridePrimaryItem`): replaces the master's properties in place. Existing overrides are not touched, so master field changes do not reach them; Nextcloud calendar PR #8456 (draft) patches ATTENDEE/ORGANIZER into overrides after this path (https://github.com/nextcloud/calendar/pull/8456). On a start change, `updateStartDateOfMasterItem` shifts EXDATEs (not RDATEs), every rule-based override's RECURRENCE-ID, and UNTIL by the delta.
- Background on RANGE and the split choice: https://github.com/nextcloud/calendar/issues/7
- Sync: the web app talks to its own sabre backend with ETags.

## 4. Known interop bugs

- Mozilla 851041: Thunderbird ignores `RANGE=THISANDFUTURE`. https://bugzilla.mozilla.org/show_bug.cgi?id=851041
- Mozilla 2007922: CalDAV THISANDFUTURE recurrences shown wrong in Thunderbird. https://bugzilla.mozilla.org/show_bug.cgi?id=2007922
- Mozilla 320178: no "this and future" edit or delete in Thunderbird. https://bugzilla.mozilla.org/show_bug.cgi?id=320178
- Mozilla 734245: an EXDATE of type DATE must match a DATE-TIME DTSTART (all-day vs timed key). Cited in `CalRecurrenceInfo.calculateDates`.
- nextcloud/server #44600: all-day EXDATEs are not applied to timed recurring events (same key mismatch, server side). https://github.com/nextcloud/server/issues/44600
- nextcloud/server #65979: a moved occurrence whose RECURRENCE-ID is outside the master's range is missing from time-range queries. https://github.com/nextcloud/server/issues/65979
- nextcloud/server #54095: CalDAV `expand` returns instances with unchanged DTSTART and RRULE and no RECURRENCE-ID. https://github.com/nextcloud/server/issues/54095
- nextcloud/server #66379: iTIP paired changed instances by array position instead of UID/RECURRENCE-ID (fix PR). https://github.com/nextcloud/server/pull/66379
- nextcloud/server #60452: a PARTSTAT change on one occurrence re-invites all other attendees. https://github.com/nextcloud/server/issues/60452
- nextcloud/calendar #5602: "This recurrence rule does not generate any valid instances" breaks events (sabre throws when every instance is excluded). https://github.com/nextcloud/calendar/issues/5602
- nextcloud/calendar PR #8456: "this and all future" on the master leaves overrides without the new ATTENDEE/ORGANIZER. https://github.com/nextcloud/calendar/pull/8456
- nextcloud/calendar #6655: cancelling one occurrence showed the whole event as cancelled to attendees. https://github.com/nextcloud/calendar/issues/6655
- sabre/vobject #18: a recurring event with its first instance modified shows twice. https://github.com/sabre-io/vobject/issues/18
- sabre/vobject #112 and #113: the iTIP broker dropped the time zone from RECURRENCE-ID in replies. https://github.com/sabre-io/vobject/issues/112
- sabre/vobject #36: time-range checks fail when only overrides are stored (no master). https://github.com/sabre-io/vobject/issues/36
- Radicale #1979: wrong RECURRENCE-ID comparison on all-day events. https://github.com/Kozea/Radicale/issues/1979
- bitfireAT/synctools #406: all-day EXDATE uploaded without VALUE=DATE, so the server answers 415 and the event stays dirty (#328 is the related UTC EXDATE case). https://github.com/bitfireAT/synctools/issues/406
- KDE 296489: Android-style cancelled-VEVENT exceptions instead of EXDATE confuse other clients. https://bugs.kde.org/show_bug.cgi?id=296489
- Cyrus #3264: resources holding only a RECURRENCE-ID component (legal per RFC 4791) cause trouble; proposal to reject them. https://github.com/cyrusimap/cyrus-imapd/issues/3264
- Cyrus #2345: Evolution copying CalDAV events hits `no-uid-conflict`. https://github.com/cyrusimap/cyrus-imapd/issues/2345
- RFC 5546 4.4.5 example: `RECURRENCE-ID;THISANDFUTURE:` without `RANGE=`. A parser that accepts the example will accept malformed data.
- RECURRENCE-ID vs DTSTART time zone mismatch producing duplicates, fixed server side in DAViCal (mailing list thread). https://lists.inf-it.com/hyperkitty/list/davclients@lists.inf-it.com/thread/ZM4OBW7VR65FZNP5SAJOM4CEMRLXX3WP

## Table

"handled" = correct per the standard; "refused" = not offered or rejected; "wrong" = data lost or misread; "unverified" = no primary source. For servers, the edit columns describe what the server does with what a client writes.

| Product | Moved occurrence | Skipped | Field-only | This and following | Rule or start change with overrides | Sync and conflict |
|---|---|---|---|---|---|---|
| sabre/dav (Nextcloud, Baikal) | handled: override stored and expanded; wrong for time-range queries when moved outside the master range (#65979) | handled: EXDATE; an override on an EXDATEd date still shows | handled: stored as is | wrong: RANGE stored but expanded as one instance; splits handled as two UIDs | handled (stored as is, no rewrite); orphan overrides still emitted | handled: sync-token, getctag, ETag If-Match 412; no Schedule-Tag |
| Cyrus (Fastmail) | handled: stored as is | handled: EXDATE | handled | wrong: RANGE not applied (no code for it); splits handled | handled: no rewrite | handled: sync-collection, getctag, If-Match 412, Schedule-Tag |
| Apple CalendarServer | handled | handled: also removes an EXDATE that names an override | handled | handled: RANGE expanded, plus server split action (past part gets the new UID) | handled: orphans kept as RDATEs on write | handled: ctag, sync-collection, ETag plus Schedule-Tag |
| iCloud | unverified | unverified | unverified | unverified | unverified | unverified (closed; CCS lineage) |
| Radicale | handled: rejects a RECURRENCE-ID type that doesn't match DTSTART in reports | handled: EXDATE | handled | wrong: RANGE not applied | handled: no rewrite | handled: sync-token, getctag, If-Match 412, optional strict If-Match |
| Google CalDAV | unverified | unverified | unverified | unverified (RANGE undocumented) | unverified | handled: ctag, If-Match only |
| Apple Calendar | unverified | unverified | unverified | unverified: secondhand reports of a split with UNTIL minus 1 s | unverified | unverified |
| Thunderbird | handled: RECURRENCE-ID fixed, own DTSTART | handled: EXDATE (DATE matches DATE-TIME) | handled; master field edits do not reach exceptions | refused: path throws "not implemented"; inbound RANGE wrong (851041) | handled for start: RECURRENCE-IDs, EXDATE, RDATE, UNTIL re-keyed; rule change keeps orphans visible | handled: sync-token or ctag, If-Match, user prompt on conflict |
| DAVx5 + Android | handled: rewrites RECURRENCE-ID type to DTSTART's | handled: cancelled row uploaded as EXDATE | handled | handled as split (AOSP/Etar UNTIL plus new UID), future overrides not carried; inbound RANGE wrong (ignored) | wrong: provider does not re-key exceptions; DAVx5 FAQ calls it error-prone | handled: sync-token or ctag, If-Match (not re-read) |
| Evolution | handled | handled: EXDATE | handled; "All" does not touch detached instances | handled as split with new UID; wrong: detached instances after the point stay in the old resource; RANGE only in iTIP CANCEL | wrong: master replaced, detached instances not re-keyed | handled: sync-collection or getctag, If-Match; KEEP_LOCAL overwrites blind |
| Nextcloud web (calendar-js) | handled | handled: EXDATE, or RDATE removed | handled; master edits do not reach overrides (PR #8456) | handled as split (UNTIL minus 1 s, new UID, SIBLING link); future overrides dropped by design; RANGE read correctly | handled for start: EXDATEs, override IDs and UNTIL shifted (RDATEs not) | handled: ETag via its sabre server |
