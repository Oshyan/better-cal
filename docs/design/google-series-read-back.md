# A Change to All Events: Read Back, Compare, Send What Differs

A written model, built for 1.5.6 (written 2026-11-14 before the build, amended with it; the decisions it rests on are in [decisions.md](decisions.md), from "After the series' write, the days are set from what Google holds" on). It covers one mechanism: what Better-Cal does between the series' own write to Google and the end of a change to all events of a repeating event. It is narrower than [the model of a change under "last write wins"](google-change-under-last-write-wins.md), which also redesigns how Finish and Put back compare. Nothing here contradicts that model, and what is left to it is listed under [What This Does Not Cover](#what-this-does-not-cover) and carried there by number.

## The Fault

A change to all events is one conditional write of the series, a read back, then a write for each day that needs one. Before this model the read back went to `GoogleSeries::rebase`, which compared it with a prediction of what Google does to the days it keeps (`afterMaster`). Whatever the prediction lacked was read as someone else's change and kept in place of what the person asked.

Google does things the prediction lacks. It puts a kept day's start back on its occurrence and leaves its end where it was (probe 3.17 for a zone change at the same moments, 3.67 for a start moved along its pattern, replay round 5 for a zone and rule change), so a day with its own time ended wrong and nothing was said. Predicting that effect too would fix one case. Probe 3.68 shows no list can be complete: a write of one private property, with no rule in it, cleared a day's own notes.

## The Rule

1. Before anything is sent, the plan says what the series and each of its days should be when the change is done.
2. After the series' own write, Better-Cal reads what Google holds and sends every day that is not as the plan has it, once, with no condition.
3. It does not guess what Google did, and it keeps nothing it sees in place of the plan while the change is being sent.
4. The series itself is written once: a change to the series that arrives after that write was the last write, and it stands.
5. The change ends with a read. The rows here are set from that read, and what is said about the change comes from comparing what was held before, what was asked and what that read shows.

This is "compute from the data, never from a list of cases", applied to the two owner decisions: while one of our own changes is being sent the last write wins and nothing detects someone else's change inside it; and for this fault, read back and send every day that is not as planned.

## The Plan

The plan is the change's target for each series (`GoogleSeries::targetOf` over the planner's result; `stateOf` for a Put back's before; `splitTargets`; an undo's inverse): the series' own fields and rule, each edited day by its key with every field Google holds (its own fields at its own values, every other at the series' new value at that key), the deleted days, and every other day ordinary. It comes from what the person asked and the rows here, which the read before the change has just matched against Google. It is journaled with the pending row before the first request.

**The journaled plan is written once.** Its content is never replaced, by a run of either side. A run of the change's own side writes into it only what identifies a thing at Google: the UID of a series or of an event of its own that it inserted. A Put back run writes nothing there (its targets are the befores, journaled with their ids). Before this model a run that stopped journaled its own targets over the plan, so a Put back that failed part way left the state before the change as the plan and a later Finish had nothing of the change to finish; that fault is in 1.5.5 too, hidden wherever the mid-change reading kept what it saw. A change stopped by an older version keeps the plan that version journaled. No row is rewritten and no stored form changes.

## How a Day Is Compared

Each read is one list by iCalendar UID with deleted records: the series and every record of it. One comparison (`GoogleSeries::days`), by day key, between the plan and a read.

- **The plan has a day as** edited (its fields), deleted, or ordinary.
- **Google has it as** no day (the key is not an occurrence of the series Google holds, whatever records sit there), deleted (a cancelled record or an EXDATE line), a record with its fields, or ordinary (an occurrence with no record: the series' values there). A dropped record says nothing, and a record at the series' own values is an ordinary day.
- **Fields, in one normal form** (`fieldsOf`): title (trimmed), notes (as Better-Cal cleans them), place (trimmed), status, link, the start instant, the end instant, timed or all-day, and a timed day's zone name. A day's own time and length are its start and its end, each compared exactly, which is the whole fix for the kept end. Fields no column holds (guests, colour, reminders) are not compared.
- **To a day the plan deleted, deleted and no day are the same:** nothing shows there, so it is as planned.
- An edited day of the plan is sent when Google holds no record of it, even where its values equal the series' (the row here stands on that record).
- **What a day takes from its series is the series as Google holds it.** Where the series reads as planned that is the plan. Where a field group of the series (title, notes, place, status, link, time) is not as planned, because someone's write came after ours (rule 4), an ordinary day and the fields an edited day does not own follow Google's series there. Else a day would be sent back at a value the series no longer has, and would read from then on as a day with its own value.

## What Is Sent

For each day not as planned, one `PATCH` of its instance id with no If-Match (Google takes it in every state a change meets, probe 3.66): an edited day whole, a deleted day as cancelled, an ordinary day Google holds a record or a cancellation for as the series' values. Never one field alone, and nothing to a key that is no day of the series Google holds. A write answered 404 (a day past the 730 occurrences Google expands, or one it hides) is not sent again and does not stop the rest. Then the last read; when no day was sent, the read back is the last read.

The series is not written a second time. When the read back shows it otherwise than the plan (a field, its rule, its end, its form) and no longer as it was before our write, someone's write came after ours. It stands, the days of the plan that are still days of it are sent, and the difference is recorded. The change is not left half done for it.

No mechanism waits for a list that lags a write: a list by iCalendar UID carried the write at once after 100 of 100 writes, 91 to 346 ms after the write's answer (probe group 11, 2026-11-14: inserts, repeated conditional writes of one event and of one day's record, deletes). One stop is left, for the case that probe never saw: a read back that still shows the series as it was before our write (the same etag, and the old form or no series at all) can't be compared with anything, so the change stops half done and Finish reads again.

## What Is Refused, and What Is Said

Refused before anything is sent, as before: a series that changed at Google since the last poll ("This event changed at Google since you last saw it. Refresh and try again."), and a switch between timed and all-day that Google would hide days with ("Google Calendar has a fault here: ..."). The second needs a prediction and keeps it: it is a check before sending, not an explanation after.

No sentence is made from a step that was sent, skipped or refused. These went: every "... was changed in Google Calendar while this change was being sent; Google's ... was kept", "... is not a day of this event in Google Calendar now, so this change's own version of it was not sent there", "Google Calendar would not take this change for ...", and "... is still on the calendar: this change deleted it, but Google Calendar kept it" for a day where nothing shows.

## The Record

The owner's rule (2026-11-14): the last write wins, "and the way to be clear about it is in the post-Google-send where we presumably read back from Google what actually landed: then we can update/correct our local record of what actually happened and leave it as 'last write won', with an accurate local record/note/message."

- **The read the change already ends with is enough,** and adds no request: it lists the series and every record. The rows here are set from it, never from the plan (`store`).
- **One function compares three states** (`outcome`: before, plan, last read) and returns data: for the series by field group and for each day, what it was, what was asked, what Google holds. The Activity entry, the Review notice and Finish's answer are composed from that data in one place (`outcomeWords`).
- **Where the last read equals the plan,** the entry says what was done and nothing else is said.
- **Where it differs,** a line says what Google holds and what was asked, with the values, and never who or what caused it: "On Mon 29 Mar 2027 Google Calendar holds the title 'Sample weekly, renamed'; this change asked for the title 'Sample weekly, special'." "Google Calendar holds the event starting Mon 1 Mar 2027, 15:00 to 16:00; this change asked for Mon 1 Mar 2027, 11:00 to 12:00." "Mon 15 Mar 2027 is on the calendar in Google Calendar (15:00 to 16:00); this change deleted it." "The event's title is 'Zebra crossing' in Google Calendar; it was 'Sample series', and this change did not change it."
- **A day is named by what a person sees.** A day whose key is no day of the series Google holds is named with the key's time ("Mon 8 Mar 2027 at 11:00 is not a day of this event in Google Calendar; this change asked for 'Own title', 11:00 to 12:00 there."), since the series may show that date at another time. A day the plan deleted is recorded by its date as well as its key: where the series Google holds shows that date at another time, the day is on the calendar, and that is said.
- An answer to an invitation uses the same lines with its own words for what was asked ("before your answer it held ...").
- The lines are stored where they were before (the entry's and the Review notice's), as text.

## At a Stop

**A step's expectation is what the run last read after it.** Every read the run makes after a write of its own (after the series' write, at its end, where it stops) becomes the expectation of the series' last step that takes one: a write to the series, an insert of one, a day's write. Whether a prediction was journaled with that step before its request makes no difference. So what Put back and Finish compare with is a read wherever the run got one.

What a stop stores, in the form it had before (a step's `expect`, a digest; nothing new is stored and no older row is rewritten):

- Before each request, the step as sent, with a prediction of what the series holds once it lands where one can be made: an insert (the series as sent), a day's write (that day as sent over the last read), and the series' write where the series has a row here, a UID and a first read (`afterMaster`). A series' write with none of those journals no expectation. A removal and an event of its own take none: a removal is read by whether what it removed is still gone, an event of its own by its etag.
- After each read, that read's digest in place of whatever the series' last such step carried.

**Finish and Put back ask one question first** (`asLeft`): does Google hold each series exactly as this change last read it?

- **Yes.** Nothing changed since. Put back runs with the series as it was before as its plan: the series written back, read back, every day not as it was sent. Finish runs the plan as journaled: a change stopped between the series' write and its day writes is finished by sending every day that is not as planned, whatever Google did to it.
- **No, and Put back:** refused, as before, pointing to Finish.
- **No, and Finish, the series' own write not landed** (no field group the change changes has moved from its value before): nothing of Google's own doing is in what it holds, so the plan is read over it by three states (before, now, plan), data only. A day or field changed there since in something the change changes is kept and named; anything else changed there stays.
- **No, and Finish, the series' own write landed:** what Google did to the days with that write can't be told from a change made there since. The days are the plan's. Finish's answer says so under one lead sentence, "This change had stopped part way, and Google Calendar no longer held the event as it was left.", then for each day Finish changed what Google held at Finish's first read and what Finish set, with the values: "On Mon 8 Mar 2027 Google Calendar held the notes 'Sample remote note'; Finish set the notes 'Sample notes', as this change has it." Those lines come from Finish's first read and its last and from nothing else (`replaced`), and none says that someone changed anything. A field of the series the change did not change stays as Google has it.

**The fall-back.** A step that takes an expectation and carries none is answered by "is Google as it was before the change", by content. It is reached in two cases only: the run made no read after a step that journaled none (a write to a series with no row here, with the network or the process gone right after it), and a pending row written by an older version whose step carries no expectation. A row written by 1.5.5 is read exactly as it was. Where the fall-back answers no, Put back's refusal says what is true: "Better-Cal has no record of 'Sample series' as Google Calendar held it after this change's last write, so it can't tell whether it changed there since."

**What a stop still can't tell apart.** A step whose expectation is the prediction journaled before its request, no read having followed (the network down right after the series' write), from a change made at Google since: the digest does not say whether it came from a read or a prediction. There Put back is refused as changed since, which may not be so, and Finish sets the plan's days and names what it replaced. Telling the two apart needs one more stored fact, and stays with the larger model (A2 there), as does the half of the owner's choice that depends on it: Finish offering first, with a list, when Google really changed after the stop's read. **Until then, a day changed in Google Calendar between a stop and Finish, on a change whose series write had landed, is replaced by Finish and named in the record.**

## Request Count

One change to one series: the read, the series' write, the read back, N day writes, the last read: N + 4, where N is the days not as planned after the series' write. With N = 0 the read back is the last read: 3. Measured by the check over a series with a renamed day, a day at its own time, a day with its own notes and a deleted day:

| Change to all events | Day writes before | Days not as planned after the write |
|---|---|---|
| Title | 2 | 2 |
| Notes set; notes cleared | 1 | 1 |
| Time an hour later | 4 | 4 |
| Length | 2 | 2 |
| Zone at the same moments (alone, or with a weekday added) | 1 | 2 |
| First day a week later, along the pattern | 5 | 5 |
| A weekday added | 1 | 1 |
| Timed to all-day | 4 | 3, and the day at its own time as a record: 4 |

The one row that grows is the fault itself: the day at its own time was not sent, and ended wrong. An answer to an invitation cost four requests before (the event, its series, the write, a list of etags). It costs three now: the list that is its first read (since [the access rule](google-access-detect-and-inform.md) made every write's first read a list, which took the place of the read of the event and the list of its series), the write, and the read back, which takes the etag list's place; plus one per day to put back and one last read when there is any.

## Every Site That Writes a Series at Google

| Site | What it writes | Under the rule |
|---|---|---|
| `GoogleSeries::run`, the series step: a change to all events, the old half of "this and following", Finish, Put back, undo and redo of a change to all events | `PATCH` of the series | Built: one path (`sendDays`, `outcome`) |
| `GoogleSeries::run`, the insert step, with `insertChosen`'s path for a cancelled series made live again | `POST`, or `PATCH` to confirmed | Follows: read back and compared with the plan, nothing interpreted |
| `GoogleSeries::run`, the removal step | `DELETE` | Follows: read back, every record still live cancelled |
| `Rsvp::answerAtGoogle`, an answer for all events | `PATCH` of the guest list | Built: `GoogleSeries::writeBesideDays`, its plan the series as it was |
| `GoogleUndo::undo`, an answer's undo | `PATCH` of the guest list | Built, the same function |
| `Events::googleCreate`; `GoogleMove` | A new series | No record exists yet to compare |

Checked, and not series writes: `Events::googlePatchAll` and its undo (a single event only; a repeating event's goes through the plan), `googlePatchThis`, `googleDeleteThis`, a day's "use series value", and the undo of a create or a delete (a series' are series entries and go through `run`).

## What Went and What Stays

- **Went:** the mid-change call of `rebase` in `run()` and `$found`, which only fed it; `rebase`'s use of the prediction as values it took for explained (its two calls of `afterMaster`), and with it `afterMaster`'s two parameters for a record kept either way; `rebase`'s forced mode and its words, which no caller used; the sentences made from steps; `differences()`, two states worded "Google kept"; the etag list after an answer; a stopped run's write of its own targets over the journaled plan; the stop for a series in another form after a later write.
- **Stays, needed before sending or for the preview:** the hidden-days predicate; `named` (is this key a day of this series: the comparison's "no day", and Finish's "no day of the series as it will be"); the planner; `masterBody` and the EXDATE lines it carries.
- **Stays, with one reader each, and read by nothing while a change is being sent:**
  - `afterMaster`, every clause (identity through `named`; a status sent making a cancelled record live; an EXDATE line carried; proof through `unproven`; a survivor following a changed start or length; a title, notes, place, status or link sent written into every survivor; the notes cleared on a series with none): read by the expectation a series step journals before its request (`run()`), which is compared with only where the run never read after that write.
  - `unproven` and `addsWeekdays`: read by `afterMaster` alone.
  - `googleOwn` (reminders set by Google once a series is all-day): read by `afterMaster` and by the insert step's expectation.
  - `heldAfter`: read by a day step's expectation (`sendDays`).
  - `rebase` with `threeWay`, `dayIn`, `ownPart`, `planOver`, `rekeyed`, `notKept`, `sinceWords`, `listed` and `were`: read by Finish alone, where Google does not hold a series as the change last read it. It holds no prediction: it compares the series before, the plan and what Google holds.
  - All of the first four go when a stop stores what it read (the larger model, B4 there).

## What This Does Not Cover

Each is an entry in [the larger model](google-change-under-last-write-wins.md#carried-in-from-156), by the number given.

- Telling "the run never read after its last write" from "someone changed it since" needs one more stored fact, a stored-form change; with it comes Finish asking first when Google really changed (A2).
- Storing in full what Google held before a change, for Put back (A1).
- Notes are sent back as Better-Cal's cleaned copy, not as Google held them (B1).
- An answer to an invitation has no pending row: if the read after it can't be made, the answer stands, nothing is compared, and the next poll stores what Google holds (B3).
- The last read after day writes is not held to showing the last of them: a list that lagged would be stored a write behind until the next poll. Probe group 11 saw no lag in 100 writes, so nothing is built for it (B5).
- The words "Google kept something else:" that open this part of an Activity line stay, since stored entries are cut on them (B6).
- A change of one day, and a single event: one request each, no series step. Not carried: nothing is put off.

## What It Follows

Every CalDAV client in [the survey](google-series-survey.md) writes a repeating event as one `PUT` of the whole resource, conditional on the ETag it read, and merges nothing from the server into it afterwards (Thunderbird over CalDAV, Evolution, Nextcloud). This rule is the same on a provider that needs several requests: the whole plan asserted, the series conditionally and then each day. It differs from every Google client surveyed, none of which reads back and sends days again at Google. The survey's own line for the build, "exceptions are carried, not trusted", is this rule without the reading of intent that was added on top.

## The Check

`server/tests/db/google-series-read-back.test.php`, written before any product change. Its expectations are worked out in the test by date arithmetic from what was asked; it calls nothing in `server/src` to say what is expected. It has 100 cases in four parts:

- **Kinds of change by effects (66).** Eleven kinds of change to all events, crossed with six things the stand-in does to every record a series write keeps or revives (`FakeGoogle::$masterEffect`, off by default): nothing beyond its own rules, the start on its occurrence with the end left, the notes cleared, the series' title written in, and two nobody has seen (the end an hour later; a deleted day live again and a live one deleted). At the end Google and the rows equal the plan, Review says nothing, a poll changes nothing, and no request is added.
- **Stops (24).** Two kinds stopped at the first day write three ways, then Finish and, apart, Put back.
- **The answer (4)** to a repeating invitation, and its undo.
- **The series changed right after its own write (6):** the rows equal Google's end, each day of the plan still in the series is as planned, the series is written once, and nothing said claims a cause, speaks of a deleted day where nothing shows, or leaves out the value that landed. The words are checked as text, so this part catches only what it names; the comparison's data has an exact check in `server/tests/google-series.php`, which generates every combination of the three states for a day and for a field of the series and holds each line to the data alone.

On the tree before the build 70 of the 100 cases failed (193 of 1,349 checks): 40 of the 66, the kept end as the stand-in itself has it among them, 22 stops, 3 answers and 5 series changes. On the built tree all pass.

## The Three Choices the Two Decisions Did Not Settle

Each was put to the owner's side before the build and is recorded in [decisions.md](decisions.md).

1. **Finish, when the series' own write has landed and Google is not as the run last read it:** the plan's days, each day Finish changes named with what Google held and what Finish set, under one lead sentence, with no blanket sentence and no claim that someone changed it ("Finish sets the plan's days where it can't tell its own change from another"). Ten assertions that promised Finish keeps a day changed in Google Calendar after a stop were restated.
2. **The answer to an invitation and its undo:** through the same function ("An answer to a repeating invitation leaves its days as they were").
3. **A series in another form after our write:** it stands and is recorded, and the change is not left half done ("After the series' write, the days are set from what Google holds").
