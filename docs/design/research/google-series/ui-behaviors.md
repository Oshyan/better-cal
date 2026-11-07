# Recurring-event edits in Google, Outlook and Apple: the behavior baseline

All sources read 2026-11-07. "Documented" means a vendor page, API reference or vendor spec says it. "Reported" means a community or third-party source says it. "Undocumented" means I found no source and the cell is a gap, not a claim.

## Summary

- **"This and following" is a split everywhere, never a stored THISANDFUTURE range.** Google's API exposes a separate recurring event after each such edit: the old one ends at the split and the new one gets an id built from the old id plus `_R<split time>` (reported). Microsoft Graph has no "this and following" call at all, so clients end the old series and create a new one. New Outlook's own "Edit this and all following events" also splits, per community reports. Apple's own CalendarServer draft says clients split a series into two UIDs because RANGE is "not always implemented".
- **What happens to exceptions after the split point is barely documented.** Google said in 2013 that instance changes are "in many cases" kept. Apple's server-side split draft keeps future overrides with the future half. Microsoft documents nothing for the split, and new Outlook's dialog warns that changed occurrences "will be cancelled".
- **Changing the series time or rule wipes exceptions in Outlook/Exchange (documented in the ActiveSync spec) and in Google (documented in 2009, not restated since).** A field change (title, location) reaches every occurrence. Google says it skips fields an occurrence overrode. Microsoft Graph sends a separate update mail for each exception when the master changes, which implies exceptions survive a field change. Apple documents none of this.
- **Undo is thin and short-lived.** Google has an Undo snackbar and a `z` shortcut that last only until the next action. A deleted event sits in Trash for 30 days, but "this and following" and "all" deletes skip Trash and can't be restored. Outlook keeps deleted items in Deleted Items, but restoring one doesn't bring it back for attendees who already processed the cancellation. Apple offers app-level Edit > Undo on Mac and documents nothing about undo for invitees. No vendor documents whether undo mails guests again.
- **Rule limits:** Google: 730 occurrences, BYSETPOS rules import but can't be created or edited in the UI, and no floating time for recurring events. Outlook: only a fixed set of rule templates. A rule outside them is split into several series or the whole VEVENT is ignored, RDATE isn't supported, and an occurrence can't move past its neighbor. Apple: floating time is supported (timeZone nil).

## Google Calendar (web, Android, iOS)

**Choices offered**
- Edit: after Save, "Choose which events in the series the updates apply to". The help page doesn't list the names, but the delete page uses "This event", "This and following events" and "All events" (mobile: "Only this instance", "All following", "All events in the series"). https://support.google.com/calendar/answer/37115 , https://support.google.com/calendar/answer/37113
- Delete: same three scopes. "This event" "keeps other future events". "This and following" "Deletes this event and future events". "All events" deletes the series. https://support.google.com/calendar/answer/37113
- I found no current page that breaks the choices down by kind of edit (move a date, change the time, change the title, change the rule). Undocumented.

**What "this and following" stores**
- 2009: an All Following edit made "the future meetings ... a new distinct series" and overrode fields that had been manually overridden. https://workspaceupdates.googleblog.com/2009/12/improvements-to-recurring-events-and.html
- 2013: the UI now "in most cases" keeps the event intact, but "API clients will still see a separate recurring event after each 'all-following' change". Instance changes are kept "in many cases". https://developers.googleblog.com/en/changes-to-all-following-behavior-in-google-calendar/
- Reported (2025-10-01): the new series id is the original id + `_R` + the split timestamp, for example `..._R20251015T021500`. The original refuses later attempts to extend its UNTIL. The API shows no link between the two, and cancelling the original also cancels the new one. https://discuss.google.dev/t/google-calendar-api-sometimes-silently-fails-to-update-recurrence-rules/269516
- Documented (2025-11-19): after a "This and following events" edit, "The original event series will keep the original Meet link" and "the remaining events will automatically generate a new, unique Meet link". So the future part is treated as a new event. https://workspaceupdates.googleblog.com/2025/11/google-meet-link-updates-for-recurring-calendar-events.html
- Reported: a "this and all following" edit "creates new event IDs". https://2sync.com/faq/article/editing-and-resyncing-recurring-events-on-google-calendar
- API: there is no "following" operation. The guide warns: "Do not modify instances individually when you want to modify the entire recurring event, or 'this and following' instances." https://developers.google.com/workspace/calendar/api/guides/recurringevents
- Exceptions after the split point: only the 2013 "in many cases" preserved. Which series they land on is undocumented.

**"All events" and existing exceptions**
- Field change: series-wide title, description, location and attendee changes apply "to all events in the series, including those that have overridden fields". But "Calendar will intelligently keep track of what fields you override, and not change those fields." (2009) https://workspaceupdates.googleblog.com/2009/12/improvements-to-recurring-events-and.html
- Time or rule change: "Changing the time or frequency of any series still resets all information in the series", including occurrences "that have been manually overridden" (2009, same page). I found no later official statement, so current behavior is unconfirmed.
- API identity: `originalStartTime` "uniquely identifies the instance within the recurring event series even if the instance was moved", and it and `recurringEventId` are immutable. Re-keying exceptions to new dates is therefore not modeled. https://developers.google.com/workspace/calendar/api/v3/reference/events

**Delete and undo**
- Deleted events stay in the calendar's Trash for 30 days, but after a "This and following" or "All following" delete, "the deleted events are not moved to the trash and can't be restored". When the creator restores an event, "guests need to RSVP to the event again". https://support.google.com/calendar/answer/37113
- Undo: keyboard shortcut `z` "Undo". https://support.google.com/calendar/answer/37034 . The 2008 launch put the undo link in the change confirmation banner. https://workspaceupdates.googleblog.com/2008/12/google-calendar-adds-event-reminder.html . How long it lasts isn't documented. One university help page says "a brief time or until you begin a new Calendar action" (reported).
- Whether undo emails guests again: undocumented.

**Notifications**
- On save with guests you choose whether to email them. Without the email, "Guests' calendars are still updated". https://support.google.com/calendar/answer/37161
- API `sendUpdates`: `all`, `externalOnly` or `none`. https://developers.google.com/workspace/calendar/api/v3/reference/events/update
- Many per-instance edits send many notifications (the reason for the API guide's warning above).

**Limits and unshowable rules**
- "Repeating events in Google Calendar have a limit of 730 occurrences." https://support.google.com/calendar/answer/37115
- `recurrence[]` accepts RRULE, EXRULE, RDATE and EXDATE lines. For recurring events `timeZone` "is required and specifies the time zone in which the recurrence is expanded", so there is no floating time. https://developers.google.com/workspace/calendar/api/v3/reference/events
- BYSETPOS forms (last weekday of the month, for example) can't be made in the UI, but a hand-written .ics imports. After import "you can't edit it", only delete and recreate it (reported, 2022). https://www.garethjmsaunders.co.uk/2022/03/26/how-to-set-up-recurring-events-on-the-last-working-day-of-the-month-in-google-calendar/
- Maximum exceptions: undocumented.

## Microsoft Outlook (Exchange, Graph, Outlook clients)

**Data model (Graph)**
- `type` is one of `singleInstance`, `occurrence`, `exception` or `seriesMaster`. `seriesMasterId` points to the master. `originalStart` is "the start time of an event when it's initially created as an occurrence or exception". The master carries `cancelledOccurrences` (occurrenceIds) and `exceptionOccurrences` (exceptions are events that differ in "subject, start or end times, or attendees"; cancelled ones don't count). https://learn.microsoft.com/en-us/graph/api/resources/event?view=graph-rest-1.0
- The recurrence is a pattern (daily, weekly, absoluteMonthly, relativeMonthly, absoluteYearly, relativeYearly) plus a range (numbered, endDate, noEnd). https://learn.microsoft.com/en-us/graph/outlook-schedule-recurring-events

**Choices offered**
- New Outlook: "Edit the one event in the series", "Edit series" and "Edit this and all following events". Classic Outlook: "Just this one" or "The entire series", with no "following" option. https://support.microsoft.com/en-us/office/change-an-appointment-meeting-or-event-29b44f7a-8938-4b99-b98d-3efcf45f7613
- Mobile gained "This and all following events" (Android) and "Edit this and all following events" (iOS) in May 2025 (message center MC1057715, published 2025-04-18). https://mc.merill.net/message/MC1057715
- Choices by kind of edit: undocumented beyond the above. A rule change goes through the master only: "Updating the recurrence pattern is only possible with the recurring master." https://learn.microsoft.com/en-us/exchange/client-developer/exchange-web-services/how-to-update-a-recurring-series-by-using-ews-in-exchange

**What "this and following" stores**
- Graph has no operation for it. An answer accepted on Microsoft Q&A (2024-12-27, written in support-rep style) says: "the Graph API can currently only be used to update an entire family of loop events, or to modify individual instances; ... there is currently no support for updating only selected events and subsequent events". https://learn.microsoft.com/en-us/answers/questions/2137307/how-to-edit-selected-and-following-recurrent-outlo
- The community workaround is to end the old series (range endDate) and create a new series. https://learn.microsoft.com/en-us/answers/questions/693011/update-this-and-following-instances-of-a-recurring
- New Outlook's own feature: a community answer says it "splits the recurring meeting into two recurring meetings" (reported). The dialog text quoted there: "if you changed specific appointments in the series, your changes will be cancelled, and those appointments will match the series again" (quoted by a user, 2025-12-10). https://learn.microsoft.com/en-us/answers/questions/5655454/how-to-end-recurring-outlook-series-without-deleti
- Exceptions after the split point: per that dialog, reset to the pattern. Whether they move to the new series or are dropped is undocumented.

**Master changes and existing exceptions**
- Rule change: "Changing the recurrence pattern of a recurring series will delete any exceptions present on the calendar item" (ActiveSync 16.0/16.1 spec). https://learn.microsoft.com/en-us/openspecs/exchange_server_protocols/ms-ascal/dabc38cf-7f14-4f51-8c88-717dace42de5
- Classic Outlook warns: "Any exceptions associated with this recurring appointment will be cancelled. Is this OK?", and "When you set or change the end date, Outlook regenerates the appointment and all notes or changes are lost" (Slipstick, last reviewed 2015-08-05). https://www.slipstick.com/outlook/calendar/changing-date-recurring-appointments/
- User request (2023-05-11, status "New", no Microsoft reply): "When editing a recurring series in Outlook, exceptions (modified occurences) are lost/reverted to the series default." https://techcommunity.microsoft.com/idea/microsoft365developerplatform/retain-exceptions-when-editing-a-recurring-series/3818718
- Field change: EWS sample comment: "Update the location. All occurrences will update to this new location." (EWS update page above). Graph: when the master "has instances that have been updated separately, multiple notification emails will be sent out: one for the master series and one per instance that has been updated". This implies exceptions survive a non-pattern change. Whether an exception's own overridden field is protected, as Google does, is undocumented. https://learn.microsoft.com/en-us/graph/api/event-update?view=graph-rest-1.0
- Re-keying exceptions to new dates: not done. Exceptions are deleted on a pattern change.

**Delete and undo**
- Deleting on the organizer's calendar "sends a cancellation message to the meeting attendees". https://learn.microsoft.com/en-us/graph/api/event-delete?view=graph-rest-1.0
- `cancel` (organizer only) can target a single occurrence by its id, "moves the event to the Deleted Items folder" and takes a custom comment. https://learn.microsoft.com/en-us/graph/api/event-cancel?view=graph-rest-1.0
- Undo: the item can be restored from Deleted Items or Recover Deleted Items, but "If attendees have already processed the cancellation, restoring the meeting on the organizer's calendar does not automatically restore it for them". A fresh update must be sent (reported, third-party guide). https://www.thewindowsclub.com/how-to-cancel-a-meeting-or-restore-a-cancelled-meeting-in-outlook
- Undo of an edit in the client: undocumented.

**Notifications**
- Classic Outlook saves a meeting change only when you send an update. New Outlook can send attendee-only changes "only to added or removed attendees" (support page above).
- Graph: an update that changes only `attendees` goes only to the changed attendees. A master update sends one mail plus one per exception (event-update page above).
- Per-instance edits each mail attendees and mark instances "Out of series" (Q&A 2137307 above).

**Limits and unshowable rules**
- Only RRULEs "fitting the templates" map to Outlook's recurrence, and implementations "SHOULD gracefully fail to map any recurrences that do not fit". The templates allow a single BYMONTHDAY (±), BYSETPOS of -1 or 1 to 4 over a day, weekday or weekend set, and COUNT up to 3 digits. https://learn.microsoft.com/en-us/openspecs/exchange_server_protocols/ms-oxcical/2b9345c9-6cd6-494e-9b28-473d61ac44e4
- Import: "If the RECUR value can be split into a finite number of supported RECUR values, the VEVENT is split into multiple recurring appointments and meetings. If [not], the entire VEVENT is ignored." https://learn.microsoft.com/en-us/openspecs/exchange_standards/ms-stanoical/626c9e29-4871-426e-997b-0df1342da1f2
- Exchange "do[es] not support RDATEs" and doesn't export all-day appointments in floating time. https://learn.microsoft.com/en-us/openspecs/exchange_server_protocols/ms-oxcical/afb90409-bcd2-4a44-9f1e-ca9340ec0508
- An exception can't cross a neighbor: `ErrorOccurrenceCrossingBoundary`, "an occurrence cannot be moved to or before the day of the previous occurrence, and cannot be moved to or after the day of the following occurrence" (event-update page above).
- Reported: Outlook for Mac/iOS mishandles imported BYMONTHDAY=-1 or BYSETPOS=-1 (2025-04). https://techcommunity.microsoft.com/discussions/outlookgeneral/outlook-not-parsing-ics-invites-with-rrule-bymonthday-1-or-bysetpos-1-properly/4403885
- Maximum exceptions: undocumented.

## Apple Calendar (macOS, iOS, iCloud, EventKit)

**Choices offered**
- Mac delete: "Delete Only This Event" (or "Delete Only These Events"), "Delete All Future Events", and from the first occurrence "Delete All". Rule change: change the Repeat menu, then "click Change". https://support.apple.com/guide/calendar/set-up-all-day-multiday-or-repeating-events-icl1018/mac
- iOS save prompt: "Save for This Event Only" or "Save for Future Events" (Apple Community, reported). Some series offer only the future option (reported). https://discussions.apple.com/thread/252354075
- There is no "all events" scope. "Future" from the first occurrence covers the whole series. EventKit has only two cases: `thisEvent` ("affect only this instance") and `futureEvents` ("also affect future instances"). https://developer.apple.com/documentation/eventkit/ekspan
- From iOS 16, saving a detached occurrence with `futureEvents` applies to all future occurrences. Before that it applied only to that instance (TN3132, 2022-08-16). https://developer.apple.com/documentation/technotes/tn3132-changes-eventkit-and-eventkitui-in-ios16
- `isDetached` is true when an occurrence's attributes "have been modified from the repeating event's default attributes". https://developer.apple.com/documentation/eventkit/ekevent/isdetached

**What "future events" stores**
- Apple's own CalendarServer draft (caldav-recursplit-02, 2015-06-26, Apple author) says the "RANGE" behavior is "not always implemented. Instead, clients split the recurring series into two separate events" with different UIDs. A naive split resets attendee PARTSTAT to NEEDS-ACTION. The draft's server-side split keeps the future half in the original resource and UID, gives the past half a new UID, links both with RELATED-TO (RELTYPE X-CALENDARSERVER-RECURRENCE-SET), and keeps per-attendee data. Overrides at or after the split stay with the future half, and earlier ones with the past half. https://raw.githubusercontent.com/apple/ccs-calendarserver/master/doc/Extensions/caldav-recursplit.txt
- Whether iCloud and current Calendar.app use that server split or a client split is undocumented. Reported: an EventKit identifier gained a `/RID=` suffix after an end-date change unless `.futureEvents` was passed. https://github.com/AlbertMontserrat/AMGCalendarManager/issues/1

**"Future events" from the start and existing exceptions**
- Undocumented by Apple for time, rule and field changes alike. `save(_:span:commit:)` says only that unmodified fields refresh from the database. https://developer.apple.com/documentation/eventkit/ekeventstore/save(_:span:commit:)

**Delete and undo**
- `remove(_:span:commit:)` takes the same two spans. https://developer.apple.com/documentation/eventkit/ekeventstore/remove(_:span:commit:)
- Undo: Mac Calendar has Edit > Undo. I found no Apple page on its scope or timing, or on whether it mails invitees again. Undocumented.

**Notifications**
- Undocumented for repeating-event edits. A user reports that Calendar always asks about the repeating event when they change it (reported). https://discussions-kr-prz.apple.com/thread/255856448

**Limits and unshowable rules**
- Floating time is supported: if `timeZone` is nil, "the calendar item is a floating event ... for example, 'lunch at noon.'" https://developer.apple.com/documentation/eventkit/ekcalendaritem/timezone
- Mac Custom repeat supports "On the" patterns (for example last weekday) and an End Repeat setting (icl1018 above). How an RRULE the UI can't show is displayed, and maximum exceptions: undocumented.

## Comparison

| Product | This and following stored as | Exceptions after a split | Exceptions after a master start or rule change | Field change reaches exceptions | Undo | Guest mail |
|---|---|---|---|---|---|---|
| Google | Split: old gets UNTIL, new id `<old>_R<time>` (reported). No RANGE | "In many cases" kept (2013). Which series holds them is undocumented | Reset (2009 doc). Current behavior unconfirmed | Yes, except fields the occurrence overrode | Snackbar or `z`, until next action. Trash 30 days, but not for following or all deletes | Ask on save. API `sendUpdates` all, externalOnly or none. Undo mail undocumented |
| Outlook / Exchange / Graph | Graph: none, client ends old and creates new. New Outlook splits (reported) | Dialog says changes "will be cancelled" and reset to the pattern | Deleted on a pattern change (ActiveSync spec, classic warning) | Yes. Graph sends one mail per exception | Deleted Items restore. Attendees don't get it back without a new send | Organizer delete or cancel mails. Master update mails master plus each exception |
| Apple (iCloud, EventKit) | Split into two UIDs (Apple CalDAV draft). No RANGE. Only thisEvent and futureEvents | Server split: future overrides stay with the future half. Client split undocumented | Undocumented | Undocumented | Edit > Undo on Mac. Scope undocumented | Undocumented |
