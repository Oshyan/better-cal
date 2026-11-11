# Google Calendar and Repeating Events: What It Does, and What Better-Cal Does Instead

> **Draft, work in progress** (started 2026-11-10). Two parts of this page are not settled:
>
> - **What Better-Cal promises on a Google calendar.** What it will deliver, and what it explicitly won't, is being decided in [#243](https://github.com/Oshyan/better-cal/issues/243) before any more Google code is written, and other work may change it further. The two Better-Cal sections below describe 1.5.5 as shipped and should be read as a snapshot, not a promise.
> - **The research.** Google's issue tracker has not been searched in depth, and several outside reports here are single sources I haven't tested. [Still to Do](#still-to-do) lists what's missing.
> - **The scope.** This page starts with Google because that's where the measurements are. The aim is wider: to show where Better-Cal improves on what's out there, including Thunderbird and CalDAV itself, which have known limits of their own. See [Beyond Google](#beyond-google). The page may be renamed or split when that's written.

This page lists what Google Calendar actually does with repeating events when another app edits them, where that differs from what you'd expect, and what Better-Cal does about it.

I wrote it because I couldn't find this anywhere. Google's documentation says little about what happens to the days you edited or deleted when the event they belong to changes, and what it has said over the years contradicts itself. So I measured it: a script makes events on a throwaway calendar, changes them, and reads back what Google stored. Most of this page comes from those read-backs.

It's for people who use Better-Cal with a Google calendar and wonder why it works the way it does, and for anyone building against Google's Calendar API. It's about the API. Google's own apps sometimes do something different with what looks like the same click (Google [said so itself in 2013](https://developers.googleblog.com/en/changes-to-all-following-behavior-in-google-calendar/)), and I haven't measured those.

## How Each Claim Was Checked

Every claim below carries one of three labels, with a date:

- **Measured:** the probe in [tools/google-probe](../tools/google-probe/README.md) sent the request and read back the result. Two runs so far: [2026-11-07](design/research/google-series/probe-2026-11-07.md) (66 cases, numbered 1.1 to 9.2) and [2026-11-10](design/research/google-series/probe-2026-11-10.md) (19 more, numbered 3.17 to 3.35). The case number is given so the request and the answer can be looked up.
- **Documented:** Google says so on a page that was live when read (2026-11-07 or 2026-11-10), linked beside the claim.
- **Reported:** someone else wrote it up and I haven't tested it. Linked beside the claim, read 2026-11-10. Treat these as leads.

Two cautions. The measurements are from one account on two days, and Google changes behavior without notice (it removed a section of its own guide during 2026, see below). And I use Google's terms once here so they can be searched: a repeating event is a *recurring event*, one day of it is an *instance*, an edited day is an *exception*, and a deleted day is a *cancelled* instance.

## What Google Gets Right

It's only fair to start here, since the rest is a list of complaints.

- **Conditional writes work.** Send the version tag you last saw and Google refuses the write if anything changed since (Measured 2026-11-07, cases 5.4 to 5.7; Documented in [the versioning guide](https://developers.google.com/workspace/calendar/api/guides/version-resources)). This is what makes safe editing possible at all.
- **A delete can be reversed.** A deleted event, or a deleted day, comes back under the same id by setting its status back (Measured 2026-11-07, cases 6.2, 6.5, 6.6).
- **A retry is safe.** An app can choose an event's id, so a request that got no answer can be sent again without making a duplicate (Measured 2026-11-07, cases 6.3, 6.4; Documented in [the events reference](https://developers.google.com/workspace/calendar/api/v3/reference/events)).
- **Changes show up at once.** A write appears in the very next "what changed" answer (Measured 2026-11-07, case 7.1).
- **It accepts nearly every repeat rule,** including "last day of the month" and "second weekday of the month", and expands them as written (Measured 2026-11-07, group 1). Refused: an end date written as a local time, and hourly repeats.
- **"Send no mail" means no mail.** With the notify setting off, a guest got nothing for any change tried (Measured 2026-11-07, cases 8.1 to 8.3).

## How Google Stores a Repeating Event

Most of what follows comes from one design choice, so it's worth a few paragraphs.

Google stores the repeating event as one record (its time, its rule) and each edited or deleted day as a separate record of its own. A day's record is named by the event's id plus the day's *original* start time, and neither can be changed afterwards (Documented, [events reference](https://developers.google.com/workspace/calendar/api/v3/reference/events); the id form Measured 2026-11-07, group 2). A deleted day isn't a line in the rule ("not on the 5th"); it's a record marked cancelled (Measured 2026-11-07, cases 2.4, 2.5).

Two things follow. First, an app that wants to change the event and keep its edited days has to send several requests, and can be interrupted between them. Google does have a way to send many requests in one call, but each gets its own result and they run in no promised order (Documented, [batch guide](https://developers.google.com/workspace/calendar/api/guides/batch)); nothing says such a call can be all-or-nothing, and I haven't measured it. The iCalendar standard ([RFC 5545](https://www.rfc-editor.org/rfc/rfc5545)), and so CalDAV ([RFC 4791](https://www.rfc-editor.org/rfc/rfc4791)), keeps an event and all its edited days in one file, written in one step. Google's choice is its own.

Second, when the event's start time changes, every day's original start time changes with it, so the old day records no longer name any day. Google's answer is to drop them.

## What Happens to Edited and Deleted Days When the Whole Event Changes

All Measured, on the date in the last column. "Dropped" means the day goes back to being an ordinary day of the event: your title, time or move is gone. "Come back" means days you deleted are on the calendar again.

| Change to all events | Days you edited | Days you deleted | Cases | Measured |
|---|---|---|---|---|
| A new clock time | Dropped | Come back | 3.1 | 2026-11-07 |
| A new time zone that moves the event's moments | Dropped | Come back | 3.3 | 2026-11-07 |
| A new time zone, same moments | Kept, but a day's own time is reset | Stay deleted | 3.17 | 2026-11-10 |
| The start moved to a day off the pattern | Dropped | Come back | 3.4 | 2026-11-07 |
| The start moved along the pattern (a week later, on a weekly event) | Kept, but a day's own date and time are reset | Stay deleted | 3.5 | 2026-11-07 |
| Different repeat days (Monday becomes Tuesday) | Dropped | Come back | 3.6, 3.11 | 2026-11-07 |
| A repeat day added (Monday becomes Monday and Wednesday) | Kept | Come back | 3.7 | 2026-11-07 |
| A new rule, for days still on it | Kept | Stay deleted | 3.33, 3.34 | 2026-11-10 |
| A new rule, for days no longer on it | Dropped | Come back | 3.34 | 2026-11-10 |
| A new length | Kept, their ends follow | Stay deleted | 3.2 | 2026-11-07 |
| A new end (a count or an end date) | Kept, except those past the new end | Stay deleted | 3.8 to 3.10 | 2026-11-07 |
| Timed to all-day, or back | Dropped | Come back | 3.19 to 3.25 | 2026-11-10 |
| A new title, description or location | Kept, but **overwritten**: a day with its own title takes the event's new one | Stay deleted | 3.12 to 3.14 | 2026-11-07 |
| A new link | Kept, the link written into each | Stay deleted | 3.32 | 2026-11-10 |
| A new status (confirmed to tentative, say) | Kept, the status written into each | Come back | 3.15 | 2026-11-07 |
| Split at a day ("this and following") | Kept before the split, dropped after it | Come back after it (none before it was tried) | 4.1, 4.2 | 2026-11-07 |

What has been said about this elsewhere:

- Google, [December 2009](https://workspaceupdates.googleblog.com/2009/12/improvements-to-recurring-events-and.html): "Changing the time or frequency of any series still resets all information in the series", and for other changes Calendar "will intelligently keep track of what fields you override, and not change those fields".
- Google, [December 2011](https://gsuite-developers.googleblog.com/2011/12/calendar-v3-best-practices-recurring.html): changes to single days are "preserved even if the recurring event itself is updated".
- Google, [December 2013](https://developers.googleblog.com/en/changes-to-all-following-behavior-in-google-calendar/): after a "this and following" change, changes to single days are preserved "in many cases".
- A hosted calendar service's [guide](https://developer.nylas.com/docs/cookbook/calendar/google-calendar-recurring-events/) (Reported): updating the event keeps the edited days that still fit the new rule.

Set against the table: the 2009 time rule still holds, the 2009 promise about overridden fields doesn't hold through the API, the 2011 sentence is true only for some changes, and the hosted service's line is right for a rule change and wrong for a time change. Google's [current guide](https://developers.google.com/workspace/calendar/api/guides/recurringevents) says none of it.

## Other Things Worth Knowing

- **Switching an event between timed and all-day fails unless you clear the old form.** Google merges the new start into the old one field by field, ends up holding both a date and a clock time, and refuses with "Invalid start time". Sending the old form's fields as empty values works, for a single event, one day, or a whole repeating event (Measured 2026-11-10, cases 3.18 to 3.31). The error is widely reported, for instance in [this unanswered thread from 2026](https://discuss.google.dev/t/google-calendar-api-disallowing-start-end-date-updates-for-a-recurring-event/329113) (which may or may not be the same cause); I found no page that gives the cause or the fix. Google's [patch reference](https://developers.google.com/workspace/calendar/api/v3/reference/events/patch) doesn't mention it.
- **A change can report success and do nothing.** After a repeating event is split in Google's own app, a later request to extend the first half's end date returns success with the event unchanged (Reported, [developer forum, 2025](https://discuss.google.dev/t/google-calendar-api-sometimes-silently-fails-to-update-recurrence-rules/269516), by one developer; no reply from Google's engineers).
- **Any write to a repeating event changes the version tag of every day in it** (Measured 2026-11-07, cases 5.2, 5.3). So a version tag can tell you that something in the event changed, never which day.
- **Dropped days leave records behind.** After a change that drops edited days, their records are still listed, marked cancelled and stripped of their original start time (Measured 2026-11-07, cases 3.1, 3.3, 3.4, 3.6; and 2026-11-10, cases 3.19 to 3.25). I suspect these get in the way of later writes ([#256](https://github.com/Oshyan/better-cal/issues/256), seen on a live install on 2026-11-10), but haven't confirmed the cause.
- **One day of an all-day event may not be able to take a clock time. Not settled.** The request was refused with "Invalid start time" (Measured 2026-11-07, case 2.2a), but it was sent before the cause above was known, without clearing the day's date, so it may be the same merge problem and not a real limit. The reverse, an all-day day inside a timed event sent with the clock fields cleared, is accepted (Measured 2026-11-10, case 3.31). Better-Cal 1.5.5 refuses the first case on the strength of the 2026-11-07 result; it needs measuring again.
- **A repeating event stops at 730 occurrences.** A count of 1,000 is accepted, stored as 1,000, and shows 730 (Measured 2026-11-07, cases 7.3 to 7.5). The limit is in Google's [help for end users](https://support.google.com/calendar/answer/37115) and not in its developer documentation.
- **Leave the notify setting out and guests get mail.** A change to an event with a guest, sent without saying whether to notify, mailed the guest an "Updated invitation" (Measured 2026-11-07, case 8.4). The [reference](https://developers.google.com/workspace/calendar/api/v3/reference/events/update) lists the setting's values and gives no clear default.
- **A "this and following" delete skips the trash.** A deleted event stays in the trash for 30 days, but after "This and following events" the deleted events "are not moved to the trash and can't be restored" (Documented, [help center](https://support.google.com/calendar/answer/37113)).
- **There is no "this and following" in the API.** An app has to shorten the event and create a second one. Google's guide used to give that recipe, with a warning that it resets edited days after the split. The section was removed between May and September 2026 (compare [the archived copy](https://web.archive.org/web/20260802102559/https://developers.google.com/workspace/calendar/api/guides/recurringevents) with [the live page](https://developers.google.com/workspace/calendar/api/guides/recurringevents)), and the current page only says not to do it day by day.
- **A repeat rule comes back with its parts in a different order** (Measured 2026-11-07, group 1), so comparing rules as text gives false differences.
- **An event can't keep the same clock time in every zone.** A repeating event must name one time zone (Documented, [events reference](https://developers.google.com/workspace/calendar/api/v3/reference/events)). One calendar app reports such events showing hours early or late at Google (Reported, [BusyCal](https://www.busymac.com/docs/faqs/google-calendar-limitations)).
- **"Something changed" notifications expire** (after seven days by default) and don't renew themselves (Documented, [push guide](https://developers.google.com/workspace/calendar/api/guides/push)).
- **A calendar Google follows by its address refreshes slowly,** with no way to ask for a refresh. Figures from 8 to 24 hours are given (Reported, for instance [here](https://knowledge.cheqroom.com/helpcenter/how-often-does-google-update-its-calendar-feeds)); I found no figure from Google, and this needs a better source.
- **Over CalDAV, Google documents nothing about repeating events.** Its [CalDAV guide](https://developers.google.com/workspace/calendar/caldav/v2/guide) lists what isn't supported and says nothing on repeat rules or edited days. A sync tool has an [open report since 2022](https://github.com/pimutils/vdirsyncer/issues/963) of Google refusing an event that changed on the other side (Reported). One calendar app lists what doesn't cross: the link field, separate start and end zones, attachments (Reported, [BusyCal](https://www.busymac.com/docs/faqs/google-calendar-limitations)).

## Bug, Choice or Limit

Not everything above is a bug, and I'd rather sort it honestly than call it all broken. This sorting is my opinion.

**Hard to defend.** A request that returns success and changes nothing. Documentation that contradicts itself and is then removed instead of corrected. A title change for all events overwriting a title someone gave to one day on purpose. Deleted days coming back because the status or the repeat days changed. A "this and following" delete skipping the trash a single delete gets.

**A consequence of how days are named.** Because a day's record is named by its original start time, moving the event leaves the records naming nothing. Dropping them is the simplest thing Google could do, and it's consistent. But a meeting moved by half an hour is, to the person who moved it, the same meeting with the same cancelled week and the same renamed session. Google could carry the days across. It doesn't.

**A limit, not a fault.** The 730 occurrences, the slow refresh of followed calendars, one time zone per event, and several requests where one would do. These look like the costs of running a calendar at Google's size. They still shape what an app on top can promise.

## What Other Calendar Apps Do

Before building, I read how the open-source Google Calendar clients handle the same edits ([the survey](design/google-series-survey.md), read from their source on 2026-11-07; nothing was run, and it names each project, file and bug). In short: none of them carries edited days across at Google when a repeating event's start moves. Two re-key the days in their own copy and leave Google with a different picture. Only one writes conditionally and shows a conflict. Two lose deleted days outright. One hides "this and following" entirely, under [a bug open since 2005](https://bugzilla.mozilla.org/show_bug.cgi?id=320178). And a widely used Android sync app's [FAQ](https://www.davx5.com/faq/recurring-events) calls editing a repeating event that has edited days "generally error-prone", and suggests recreating the event.

So the common answer to Google's behavior is to accept it: send the one change, take what comes back. That is a reasonable answer, and it has a real advantage: one request can't stop halfway.

## What Better-Cal Does Instead

> **Work in progress.** This describes 1.5.5 (2026-11-10) as shipped. What Better-Cal will promise on a Google calendar, and what it explicitly won't, is open until [#243](https://github.com/Oshyan/better-cal/issues/243) is decided, and other work may change it too. Expect this section to be rewritten, and some of it to get smaller where a smaller promise is a surer one.

- **It tells you first.** A change to all events, or to this and following, shows what will happen to each edited and deleted day before anything is sent.
- **It carries your edited and deleted days across.** After changing the event, Better-Cal reads back what Google kept and re-sends each edited or deleted day Google dropped or overwrote, one request per day. Then it reads the event again and compares it with what you asked for. I think a day changed on purpose should survive a change to the event's time, which is why it does the extra work.
- **It never overwrites a change made elsewhere.** Every write carries the version tag last seen. If the event changed at Google in the meantime, you get a conflict to look at.
- **It records the change before sending it.** Several requests can stop partway (a lost connection, a refusal at step three). The change is written down first, and Review offers **Finish** or **Put back**.
- **A day keeps what only Better-Cal holds.** Tags, people, reminders and the rest stay on a day whatever Google does to its record.
- **It always says "send no mail"** on its own writes, so an edit never mails a guest list by accident.
- **It refuses what the probe showed Google refusing,** with the reason, before sending anything: a timed day inside an all-day event, for one (though that result needs measuring again, see above).
- **An event that keeps the same clock time in any zone** is sent to Google pinned to your Home zone, and keeps the setting here.
- **Every write can be undone,** by a second conditional write that puts back what was there, with one exception below.

[The connector's own page](google-calendar.md) has the detail.

## Where Better-Cal Still Falls Short

> **Work in progress,** as above: these are 1.5.5's limits. Some will be fixed, and some may become things Better-Cal says plainly it doesn't do, depending on [#243](https://github.com/Oshyan/better-cal/issues/243).

Carrying days across is several requests where Google's way is one, and most of what's listed here comes from that.

- **Undo of a change to all events on a Google calendar is off** until 1.5.6 ([#235](https://github.com/Oshyan/better-cal/issues/235)). Whether it comes back at all is one of the questions for #243.
- **Switching a repeating event with a deleted day back to a form it had before stops half done.** Finish completes it correctly, but it shouldn't need Finish ([#256](https://github.com/Oshyan/better-cal/issues/256)).
- **A half-done change that can be neither finished nor put back** has no "leave it as Google has it" yet ([#244](https://github.com/Oshyan/better-cal/issues/244)).
- **Better-Cal doesn't notice when your access to a calendar is taken away at Google** ([#245](https://github.com/Oshyan/better-cal/issues/245)).
- **A change made in Google Calendar while one of ours is half done** is kept only when ours leaves every day where it was. Otherwise it's dropped, and named.
- **A repeating event that starts in the hour a spring clock change skips** gains an extra first day when a field changes for all events ([#252](https://github.com/Oshyan/better-cal/issues/252)). That one is Better-Cal's bug, not Google's.

The [changelog](../CHANGELOG.md) has each release's known limits in full.

## Beyond Google

> **Planned, not written.** What's below is a starting list taken from [the survey](design/google-series-survey.md) (read from source and documentation on 2026-11-07; nothing was run). Each item needs its own check, and its own links, before it's stated here as fact.

Google isn't the only place where repeating events get mishandled, and the point of this page is what Better-Cal does better than what's out there, not Google alone. The focus will be on what most people actually meet: the standard, the big products, and the most used open clients. Smaller independent calendar apps appear on this page only as witnesses to what Google does, not as targets.

Where the survey already points:

- **CalDAV and the iCalendar standard.** The standard has a way to say "this and following" inside one event ([RFC 5545](https://www.rfc-editor.org/rfc/rfc5545), the `RANGE` parameter), but of the servers surveyed only one applies it, and no client surveyed writes it; they all split the event in two. What happens to edited days after the split differs by client: dropped, left behind in the old event, or not carried. The standard also doesn't say what a deleted day means when the same day was edited, and servers differ.
- **Thunderbird.** "This and following" isn't offered at all, under [a bug open since 2005](https://bugzilla.mozilla.org/show_bug.cgi?id=320178). When a repeating event's start moves, edited days are re-keyed in Thunderbird's own copy and not at Google.
- **A field change for all events.** No client surveyed carries it into the edited days that had simply inherited the old value, since each edited day stores its own copy of every field.
- **Outlook and Apple Calendar.** Both split for "this and following". Outlook says a pattern change cancels changed days. Neither was measured, only read about.

What Better-Cal does on its own calendars, where nothing outside limits it: edited days follow the event when it moves, a field change reaches the days that inherited it and leaves alone the ones with their own value, and every edit can be undone. That's the comparison this section should end up making, case by case.

## Checking This Yourself

The probe is a script, so it can be run again when Google changes something. It works on a calendar you make for the purpose, marks everything it creates, and deletes it afterwards. [Its README](../tools/google-probe/README.md) says how. If you run it and get a different answer from the table above, I'd like to know: that's either Google changing or a mistake here, and both are worth an issue.

## Still to Do

This page isn't finished. What's missing, as of 2026-11-10:

- **Search [Google's issue tracker](https://issuetracker.google.com) in depth** for each behavior above: whether it's filed, what Google answered, and whether any is marked as intended. Nothing here has been checked against it yet.
- **Widen the page beyond Google:** CalDAV and the iCalendar standard, Thunderbird, and the big products (Outlook, Apple Calendar), each checked and linked the way the Google findings are. Decide then whether this stays one page or becomes several.
- **Rewrite the two Better-Cal sections** once [#243](https://github.com/Oshyan/better-cal/issues/243) settles what is promised, what is refused and what is dropped with a sentence.
- **Test the Reported claims** worth testing, starting with the success-that-changes-nothing after a split, or drop them.
- **Find a better source for the refresh time of followed calendars,** ideally Google's own.
- **Find a live source for deleted days coming back over Google's CalDAV.** A calendar app's help page said so and called it a bug in Google's CalDAV; that page no longer resolved on 2026-11-10, so the claim is left out above.
- **Measure again whether one day of an all-day event can take a clock time,** this time with the day's date cleared (case 2.2a was sent without). If it can, a refusal in Better-Cal goes away.
- **Measure whether a batch call can be all-or-nothing,** and what a failure in the middle of one leaves behind.
- **Measure Google's own apps,** web and phone, for the same changes as the table. The API and the apps are known to differ.
- **Confirm the cause of [#256](https://github.com/Oshyan/better-cal/issues/256)** with a probe case (switch, switch back, with a deleted day).
- **Look for more write-ups:** Stack Overflow answers, other sync tools' issue trackers, and calendar vendors' engineering posts were only skimmed.
- **Rerun the probe** before this page is published, and again whenever it's revised, and update the dates.

## Sources

Every link above, in one place. Google's pages were read on 2026-11-07 or 2026-11-10; the others on 2026-11-10.

Google:

- [Recurring events guide](https://developers.google.com/workspace/calendar/api/guides/recurringevents), and [the archived copy](https://web.archive.org/web/20260802102559/https://developers.google.com/workspace/calendar/api/guides/recurringevents) that still has the "this and following" recipe
- [Events reference](https://developers.google.com/workspace/calendar/api/v3/reference/events), with [patch](https://developers.google.com/workspace/calendar/api/v3/reference/events/patch) and [update](https://developers.google.com/workspace/calendar/api/v3/reference/events/update)
- [Versioning guide](https://developers.google.com/workspace/calendar/api/guides/version-resources), [batch guide](https://developers.google.com/workspace/calendar/api/guides/batch), [push guide](https://developers.google.com/workspace/calendar/api/guides/push), [CalDAV guide](https://developers.google.com/workspace/calendar/caldav/v2/guide)
- [Improvements to recurring events](https://workspaceupdates.googleblog.com/2009/12/improvements-to-recurring-events-and.html) (2009-12-17)
- [Calendar v3 best practices: recurring events](https://gsuite-developers.googleblog.com/2011/12/calendar-v3-best-practices-recurring.html) (2011-12-09)
- [Changes to "all following" behavior](https://developers.googleblog.com/en/changes-to-all-following-behavior-in-google-calendar/) (2013-12-05)
- Help center: [delete an event](https://support.google.com/calendar/answer/37113), [repeating events](https://support.google.com/calendar/answer/37115)
- [Issue tracker](https://issuetracker.google.com) (not yet searched)

Standards:

- [RFC 5545](https://www.rfc-editor.org/rfc/rfc5545) (iCalendar), [RFC 4791](https://www.rfc-editor.org/rfc/rfc4791) (CalDAV)

Others:

- [Google Calendar API sometimes silently fails to update recurrence rules](https://discuss.google.dev/t/google-calendar-api-sometimes-silently-fails-to-update-recurrence-rules/269516) (developer forum, 2025-09-30)
- [Google Calendar API disallowing start/end date updates for a recurring event](https://discuss.google.dev/t/google-calendar-api-disallowing-start-end-date-updates-for-a-recurring-event/329113) (developer forum, 2026-02-09)
- [Google Calendar limitations with BusyCal](https://www.busymac.com/docs/faqs/google-calendar-limitations)
- [DAVx5 FAQ: recurring events](https://www.davx5.com/faq/recurring-events)
- [Nylas cookbook: Google Calendar recurring events](https://developer.nylas.com/docs/cookbook/calendar/google-calendar-recurring-events/)
- [vdirsyncer issue 963](https://github.com/pimutils/vdirsyncer/issues/963) (opened 2022-01-18)
- [Thunderbird bug 320178](https://bugzilla.mozilla.org/show_bug.cgi?id=320178)
- [How often does Google update its calendar feeds](https://knowledge.cheqroom.com/helpcenter/how-often-does-google-update-its-calendar-feeds)

Ours:

- [Probe report, 2026-11-07](design/research/google-series/probe-2026-11-07.md) and [findings, 2026-11-10](design/research/google-series/probe-2026-11-10.md)
- [The survey of other clients](design/google-series-survey.md), read 2026-11-07
