# Decisions

A record of the calls Better-Cal makes where two or more readings were each defensible and one had to be codified. Each entry says what was chosen, what else was on the table, why, where the rule lives, and when it was settled, so any of them can be revisited later without digging through commits or reviews. Started 2026-11-04.

What belongs here: a choice between readings of a standard, of a person's intent, or of what a word in the app means, once it has become a rule the code enforces. What doesn't: bugs (one reading was simply wrong), and visual design choices, which keep their own records ([calendar marks](calendar-marks.md), [day marks](day-marks.md)).

Two principles behind most of the entries below. A change previews its consequences and offers the reasonable readings as choices, and where a reading has to be preselected it is the one that loses nothing. And the person's expectation beats the arithmetic: a rule that is mathematically tidy but surprises someone is the wrong rule.

## Repeating Events and the Standard

RFC 5545 leaves some things undefined and says some things a calendar for people shouldn't do. These are the places Better-Cal reads it its own way.

- **A clock time the spring change skips.** Chosen: the occurrence is kept, at the same clock plus the gap (02:30 becomes 03:30). Else: the standard's 3.3.10 says such an instance is ignored and not counted. Why: a weekly event that vanishes one week a year is worse than one an hour later, and it is what 3.3.5 says for a single date-time and what the expander gives. The cost is that a strict client reading the app's ICS may leave that day out. Where: [series model](series-model.md#zones-dst-and-all-day-days). Settled 2026-11-02.
- **A rule that names its hour, on the gap day.** Chosen: no occurrence that day (a daily rule at BYHOUR=2 in a zone that skips 2:00 has nothing on 14 March). Else: shift it like a plain clock. Why: the rule said 2:00, and there is no 2:00; the single-clock series above has a clock the person set, which is different. The policy follows the expander's hour-by-hour stepping. Where: series model, same section. Settled 2026-11-02.
- **A clock time the fall change repeats.** Chosen: a wall clock given with no instant is its first instant, the one in daylight time; an instant given stays that instant, and the plan's note says which of the two the series is at and what the zone data says of it. Else: the second instant (what PHP reads a repeated wall clock as), or always the first. Why: 3.3.5's reading for a date-time, and a stored instant must never move because its clock reads back ambiguously. Where: series model, same section, and the `fold` and `daylight` fields of a note in the [contract](../api-contract.md). Settled 2026-11-02, the note's wording 2026-11-04.
- **A start that would fall in the skipped hour.** Chosen: stored one period earlier, on the rule's latest day before it where the clock exists, as a generated skip, with COUNT raised by one; every sentence names the first day shown. Else: store the shifted clock (which moves the whole series an hour) or refuse. Why: storage holds one instant and the expander takes every day's clock from it. Where: series model, same section. Settled 2026-11-02.
- **HOURLY across a skipped hour.** Chosen: the walk steps by wall clock (an every-3-hours series keeps its 00:00, 03:00, 06:00 pattern after the change day), and a skipped clock resolves by the offset before the gap as 3.3.5 says for a date-time. Else: elapsed time, under which the series drifts an hour for the rest of its life after each change. Why: an hourly series on a calendar is a pattern of clocks, not a timer. The standard leaves it undefined. Where: the harness's RFC evaluator names it as a product rule (`tools/edit-harness/README.md`). Settled 2026-11-04.
- **A rule naming its hour in a zone whose clocks move by half an hour.** Open: read from the start the expander drifts the minute (03:00 becomes 03:30 after the change), read from a later window it doesn't, and keys are stored by the from-start reading. Fixing it changes stored keys for such series. Where: [#202](https://github.com/Oshyan/better-cal/issues/202). Deferred 2026-11-04.
- **COUNT counts skipped days.** Chosen: as 3.8.5.3 says, a skipped day is one of the N. Under "keep N events" the count is raised for each skip the change itself generates, never for a skip the person made earlier. Else: count only shown events. Why: the standard, and a skip the person made was a choice about that day, not a request for one more at the end. Where: series model, Bounds. Settled 2026-11-02.
- **Where a series starts.** Chosen: on a day of its rule; a start the rule doesn't name moves forward to the rule's first day, keeping the time, and the editor says so. Else: keep the typed start as a first occurrence outside the pattern (what the standard allows and some calendars do). Why: one reading of the rule everywhere; a stray first day confuses every later edit. Where: contract, event creation. Settled in 1.5.0.
- **Minute and second rules.** Chosen: expanded as the standard's table says, in elapsed time from the start, by the app's own iterator, since the library has no step for them. Else: refuse them. Why: imports carry them. Where: series model, "The expander is sabre's, with the steps it lacks". Settled 2026-11-02.
- **A walk that can't complete.** Chosen: a rule whose walk reaches no admitted day within the calendar's 400-year cycle, or provably repeats without an occurrence, has ended; a limit of the walk's own (a search budget) is an error the plan reports as a rule it can't read, never a series that ends there. Else: stop silently after a fixed number of tries, which is what 1.5.0's first candidate did and what cut a leap-day series to one event. Why: a limit reading as the rule's end is a wrong answer that looks right. Where: `server/src/Domain/Series/RuleIterator.php`. Settled 2026-11-04.

## Edits to a Series

The [series model](series-model.md) is the full statement; these are its judgment calls, with what each decided against.

- **An edit is measured against the occurrence acted on as it shows.** Chosen: every part of an edit is a delta from the occurrence as it shows (its own values where it owns them), never an absolute value passed through. Else: measure against the series' own values at that key. Why: a person drags what they see. Where: series model, "The Rule", step 3. Settled 2026-11-02.
- **A sent rule equal to the stored one is no rule.** Chosen: lists compare as sets, INTERVAL=1 is dropped, WKST counts only where the standard makes it count, a bare rule equals its one-member form. Else: compare the text. Why: an editor round-trip must not read as a change. Where: series model, step 1. Settled 2026-11-02.
- **Written, not inferred.** Chosen: "every 31st" stays `BYMONTHDAY=31`, never rewritten into the last day, and a reading picked in the dialog is written in its own form. Else: normalize month-end rules to the last day. Why: the person wrote it; what they wrote is what the editor shows them next time. Where: series model, Finding the Readings. Settled 2026-11-02.
- **A drag on the 29th to 31st: which reading first.** Chosen: "on the 30th, or the last day in shorter months" preselected; "skipping months without one" second; a day counted from the end keeps counting from the end first. Else: skipping first, which is what other calendars do. Why: it loses nothing and fits "near the end of each month". Where: series model, Open Decisions 1. Settled 2026-11-02.
- **A list member dragged to another weekday.** Chosen: "Move every Wednesday to Thursday" preselected over shifting the whole pattern. Why: Wednesday's class moved to Thursday most likely means Thursday instead of Wednesday, not every day a day later. Where: same. Settled 2026-11-02.
- **A day moved on its own, when the timetable changes.** Chosen: it stays where it was moved ("Keep Fri 26 Mar"). Else: move it along with the pattern. Why: a class moved for an appointment stays moved. Where: same. Settled 2026-11-02.
- **Changing every event from an edited day.** Chosen in 1.5.0: the series moves the same way and the day keeps its own fields. For 1.5.1: adopting the day as the series' own is preselected when the move is less than a period, the same way first when it is a period or more; the threshold is a judgment and the dialog never states it. Where: same, and [#202](https://github.com/Oshyan/better-cal/issues/202). Settled 2026-11-02, adoption deferred.
- **A day the new rule gains at an edge.** Chosen: left out by default, as a generated skip, with "Add it" offered. Else: add it. Why: nothing appears the person didn't ask for. Where: series model, Bounds, Gains at the edges. Settled 2026-11-02, preselection moved after the fourth review.
- **A count that would end before the day dragged.** Chosen: "keep the end" preselected, the dragged day staying in the series; "keep N events + this one on its own" offered. Why: nothing is split off that the person didn't split. Where: series model, The Day Acted On Comes First. Settled 2026-11-02, preselection moved after the fourth review.
- **An UNTIL after a drag.** Chosen: kept as the end by default, rewritten as the latest image at or before it. Else: keep the count. Where: series model, Bounds. Settled 2026-11-02.
- **No display cap.** Chosen: bounds and splits are worked out over the actual span, with a sanity limit of 400,000 occurrences. Else: the 1,001-occurrence display cap, which cut long series short. Where: series model, Bounds. Settled 2026-11-02.
- **Rules several times a day.** Chosen: every series-wide move of their days, time or zone is refused with its reason; "this event" edits a day by its key. Else: identity machinery to keep two occurrences a day apart through a move. Why: only imports carry such rules. Where: series model, "Several times a day can collide". Settled 2026-11-02.
- **An edited day's all-day flag.** Chosen: a field like the others, inherited unless the day owns it; a day deliberately timed in an all-day series stays timed when the series toggles. Else: every edited day takes the series' flag (the first draft's decision 8, reversed). Where: series model, Own Fields. Settled 2026-11-02.
- **What an outcome is.** Chosen: a day's outcome comes from its start instant and its kind before and after, never its dates; a day whose length alone changes "stays". Where: contract, the plan. Settled 2026-11-04.
- **A skip belongs to the occurrence it removed.** Chosen: it moves with that occurrence and is dropped with it, never carried onto a day the person never removed. Where: series model, Bounds, Days outside. Settled 2026-11-02.
- **The plan's zone.** Chosen: the series' zone after the change, with every timed value of both sides written in it; an all-day series has no plan zone and its timed days are written in their own. Else: each side in its own zone. Why: one zone to read a comparison in. Where: contract, `zone`. Settled 2026-11-04 (revision 6 of the output plan).
- **A rule the expander can't read.** Chosen: its COUNT or UNTIL is still read from the text, so the plan says how it ends, with no dates. Else: refuse every edit of it. Where: contract. Settled 2026-11-04.
- **"This and following" and deletes.** Chosen: 1.5.0 keeps their 1.4.6 code and the drop chip, without the dialog; 1.5.1 moves them onto the model. Why: a plan the code can't keep would be worse than none. Where: series model, Acceptance; [#202](https://github.com/Oshyan/better-cal/issues/202). Settled 2026-11-02.
- **Google calendars.** Chosen: until 1.5.2, only edits that keep the rule's text are offered there, their effect on edited days unchecked, and a plan note says Google decides what happens to edited days. Why: sending Google the plan isn't the same as Google ending up with it, and the probe comes first. Where: series model, Google. Settled 2026-11-02.
- **Floating times.** Chosen for the model, once [#167](https://github.com/Oshyan/better-cal/issues/167) lands: keys stored as the wall clock written as if UTC, and a zone change between floating and pinned keeps every wall clock. Where: series model, Floating Times. Settled 2026-11-02, the rest open on #167.
- **The zone "same moment" choice with a member reading.** Open: the day delta subtracts day numbers read in two zones, and which zone it should be measured in is undecided. Where: [#202](https://github.com/Oshyan/better-cal/issues/202). Deferred 2026-11-04.

## What the Panel Says

The plan is data, and the app composes its sentences in the person's locale and time format. These are the wording rules that were a choice.

- **A date is said once.** Chosen: a date is left out against the same date earlier in the sentence only when both are in the same zone; a date said again because the one before it is in another zone carries its zone on its clock ("moves to Tue, Jan 12 at 8:00 PM in Tokyo"). Where: `workfolder/plans/series-panel-sentences.md`, and `web/src/lib/planwords.js`. Settled 2026-11-04.
- **An all-day setting of its own.** Chosen: read by the day's kind ("with a time of its own", "all-day"), and a day that becomes timed "now starts at" its clock. Settled 2026-11-04.
- **A zone the device can't read.** Chosen: the clock is left out, and with it the clause that needed it. Else: show it in the device's zone. Why: a clock in the wrong zone reads as a fact. Settled 2026-11-04.
- **A repeated clock's note.** Chosen: "this is the second, in standard time", from the note's data; with neither instant in daylight time the clause after the comma is left out. Else: always "the first, in daylight time", which 1.5.0's first candidate said of a series stored at the second instant. Settled 2026-11-04.
- **Rows say what was done earlier, never what is kept**, and days are grouped by outcome. Where: the sentence rules above. Settled 2026-11-03.

## Trips and Links

- **A trip never contains a trip.** Chosen: one end-state rule checked after every write, by the writers and by Undo alike: no trip among a trip's stops, no trip with stops cleared, and Undo refuses a state that would break either and says what it would break. Else: one-direction guards at each writer (what 1.5.0's first candidate had, and what let a nesting through a dormant link). Where: `server/src/Domain/Trips.php`, contract. Settled 2026-11-04.
- **A link whose flags are both clear stays.** Chosen: a link kept from while its trip flag was clear is a relationship the person made, and it stands until they remove it; only the end-state rule judges it. Else: drop links on clearing the flag. Settled 2026-11-04.
- **A row exists for its own fields and for whatever refers to it.** Chosen: a day a trip link names keeps its row when it would otherwise rejoin its series. The same rule for duplicate decisions and plugin data waits in [#202](https://github.com/Oshyan/better-cal/issues/202). Settled 2026-11-04.
- **Trips in ICS and CalDAV.** Chosen: `RELATED-TO;RELTYPE=CHILD` on the trip and `RELTYPE=PARENT` on each member, the standard's own relation. Where: [contract](../api-contract.md), export. Settled before 1.0.

## Quick Add

How typed text is read when more than one reading is plausible. The full list of what it reads is in [quick-add.md](../quick-add.md).

- **A date already past this year means next year.** Settled before 1.0.
- **"At 7".** Chosen: 1 to 7 are afternoon, 8 to 11 morning. Settled before 1.0.
- **A day range with a time range.** Chosen: "Conference Aug 3-7 9am-5pm" is one stretch from the first morning to the last evening when the end is later in the day than the start; "Shift Aug 3-7 10pm-6am" repeats each night, since the end is earlier. A day range with one time repeats ("Standup Mon-Fri 9am"). Where: [#198](https://github.com/Oshyan/better-cal/issues/198), quick-add.md. Settled in 1.5.0.
- **The model never overrides a typed range.** Chosen: a date or time range the text names stays, whatever the AI model answers. Settled in 1.5.0.
- **Two-letter zones.** Chosen: read only in capitals and never before an ordinary word, so "3pm CT scan" is a scan. Settled before 1.0.
- **A date already in the editor.** Chosen: a client with a chosen day keeps it unless the text names one (`when` says whether the text did). Where: contract, quick add. Settled before 1.0.

## Relationships and Review

- **Context is what a calendar is, never what an event is.** Chosen: the relationship of every event on a Context calendar is context; it is never set per event. Where: [relationships.md](../relationships.md). Settled before 1.0.
- **What your answer to a Google invitation means.** Chosen: accepted is planned, anything else is maybe; without an answer the event's status decides, tentative being maybe. Where: relationships.md, For Integrators. Settled before 1.0.
- **A cancellation older than the invitation.** Chosen: an emailed cancellation closes a waiting invitation only when it is at least as new as the invitation. Else: any cancellation closes it (1.4.6 did, and a delayed one closed a newer invitation). Settled in 1.5.0.

## Sync and Storage

- **All-day series are stored in UTC** (since 0.9.16), so their local day is the UTC date, and a timed series made all-day takes the dates of its days in its old zone. Where: series model, Zones. Settled 0.9.16.
- **Too many wrong CalDAV passwords.** Chosen: a 429, not a 401, so the client waits rather than deciding its password is wrong and asking again. Where: [caldav.md](../caldav.md). Settled before 1.0.
- **A refused change touches nothing, a Google calendar included.** Chosen: the trip rule runs before any outward call, so a refusal never reaches Google. Settled 2026-11-04.

## Revisiting One

Change the rule where it lives, say so in the changelog, and update the entry here with the new reading and the date, keeping the old one as "was". A decision reversed should read as a decision, not as a bug fixed.
