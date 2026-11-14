# A Change to All Events: Read Back, Compare, Send What Differs

A written model, not yet built (2026-11-14). It covers one mechanism: what Better-Cal does between the series' own write to Google and the end of a change to all events of a repeating event. It is narrower than [the model of a change under "last write wins"](google-change-under-last-write-wins.md), which also redesigns how Finish and Put back compare. Nothing here contradicts that model, and what is left to it is listed under [What This Does Not Cover](#what-this-does-not-cover).

## The Fault

A change to all events is one conditional write of the series, a read back, then a write for each day that needs one. Today the read back goes to `GoogleSeries::rebase`, which compares it with a prediction of what Google does to the days it keeps (`afterMaster`). Whatever the prediction lacks is read as someone else's change and kept in place of what the person asked.

Google does things the prediction lacks. It puts a kept day's start back on its occurrence and leaves its end where it was (probe 3.17 for a zone change at the same moments, 3.67 for a start moved along its pattern, replay round 5 for a zone and rule change), so a day with its own time ends wrong and nothing is said. Predicting that effect too would fix one case. Probe 3.68 shows no list can be complete: a write of one private property, with no rule in it, cleared a day's own notes.

## The Rule

1. Before anything is sent, the plan says what the series and each of its days should be when the change is done.
2. After the series' own write, Better-Cal reads what Google holds and sends every day that is not as the plan has it, once, with no condition.
3. It does not guess what Google did, and it keeps nothing it sees in place of the plan while the change is being sent.
4. The series itself is written once: a change to the series that arrives after that write was the last write, and it stands.
5. The change ends with a read. The rows here are set from that read, and what is said about the change comes from comparing what was held before, what was asked and what that read shows.

This is "compute from the data, never from a list of cases", applied to the two owner decisions: while one of our own changes is being sent the last write wins and nothing detects someone else's change inside it; and for this fault, read back and send every day that is not as planned.

## The Plan

The plan is the change's target for each series (`GoogleSeries::targetOf` over the planner's result; `stateOf` for a Put back's before; `splitTargets`; an undo's inverse): the series' own fields and rule, each edited day by its key with every field Google holds (its own fields at its own values, every other at the series' new value at that key), the deleted days, and every other day ordinary. It comes from what the person asked and the rows here, which the read before the change has just matched against Google. It is journaled with the pending row before the first request.

After that it changes only by what identifies things at Google (a new series' UID). Today the mid-change reading rewrites it and a stop journals the rewritten one; both end. A change stopped by an older version keeps the target that version journaled. No row is rewritten and no stored form changes.

## How a Day Is Compared

Each read is one list by iCalendar UID with deleted records: the series and every record of it. One comparison, by day key, between the plan and a read. It is `stepsFor`'s comparison today, with one state added.

- **The plan has a day as** edited (its fields), deleted, or ordinary.
- **Google has it as** no day (the key is not an occurrence of the series Google holds, whatever records sit there), deleted (a cancelled record or an EXDATE line), a record with its fields, or ordinary (an occurrence with no record: the series' values there). A dropped record says nothing, and a record at the series' own values is an ordinary day.
- **Fields, in one normal form** (`fieldsOf`): title (trimmed), notes (as Better-Cal cleans them), place (trimmed), status, link, the start instant, the end instant, timed or all-day, and a timed day's zone name. A day's own time and length are its start and its end, each compared exactly, which is the whole fix for the kept end. Fields no column holds (guests, colour, reminders) are not compared.
- **To a day the plan deleted, deleted and no day are the same:** nothing shows there, so it is as planned.
- An edited day of the plan is sent when Google holds no record of it, even where its values equal the series' (the row here stands on that record), as today.

## What Is Sent

For each day not as planned, one `PATCH` of its instance id with no If-Match (Google takes it in every state a change meets, probe 3.66): an edited day whole, a deleted day as cancelled, an ordinary day Google holds a record or a cancellation for as the series' values. Never one field alone, and nothing to a key that is no day of the series Google holds. Then the last read.

The series is not written a second time. When the read back shows it otherwise than the plan (a field, its rule, its end, its form) and no longer as it was before our write, someone's write came after ours. It stands, the days of the plan that are still days of it are sent, and the difference is recorded. Today a different form stops the change half done. Under the rule that happens only while the list still shows the series as it was before our write (a list that lags), where nothing can be compared yet.

## What Is Refused, and What Is Said

Refused before anything is sent, as today: a series that changed at Google since the last poll ("This event changed at Google since you last saw it. Refresh and try again."), and a switch between timed and all-day that Google would hide days with ("Google Calendar has a fault here: ..."). The second needs a prediction and keeps it: it is a check before sending, not an explanation after.

No sentence is made from a step that was sent, skipped or refused. These go: every "... was changed in Google Calendar while this change was being sent; Google's ... was kept", "... is not a day of this event in Google Calendar now, so this change's own version of it was not sent there", "Google Calendar would not take this change for ...", and "... is still on the calendar: this change deleted it, but Google Calendar kept it" for a day where nothing shows.

## The Record

The owner's rule (2026-11-14): the last write wins, "and the way to be clear about it is in the post-Google-send where we presumably read back from Google what actually landed: then we can update/correct our local record of what actually happened and leave it as 'last write won', with an accurate local record/note/message."

- **The read the change already ends with is enough,** and adds no request: it lists the series and every record. The rows here are set from it, never from the plan (`store` does this today, and the check finds the rows equal to Google's in every case tried). One weakness, not measured and left alone: after day writes that read is not held to showing the last one, so a lagging list could be stored a write behind until the next poll.
- **One function compares three states** (before, plan, last read) and returns data: for the series and for each day, what it was, what was asked, what Google holds. The Activity entry, the Review notice and Finish's answer are composed from that data in one place.
- **Where the last read equals the plan,** the entry says what was done and nothing else is said.
- **Where it differs,** the entry says what Google holds and what was asked, with the values, and never who or what caused it: "Google Calendar holds this event at 15:00; this change asked for 11:00." "Mon 15 Mar is on the calendar at 15:00; this change deleted it." "The event's title is 'Zebra crossing' in Google Calendar; it was 'Sample series', and this change did not change it."
- The lines are stored where today's are (the entry's and the Review notice's), as text.

## At a Stop

Every read the run makes replaces the expectation journaled before it (today only its last read does). So what Put back and Finish compare with is a read wherever the run got one, after the series' write as well as at the end.

- **Finish** reads the series and asks the question Put back already asks: does Google hold exactly what this change last read or wrote? If so, nothing changed since, the plan stands as journaled, and the run compares and sends. A change stopped between the series' write and its day writes is finished by sending every day that is not as planned, whatever Google did to it.
- **Put back** asks the same question, as today, then runs with the series as it was before as its plan: the series written back, read back, every day not as it was sent.
- **When Google is not as last read** (someone changed it since, or the run never read after its last write; a digest can't tell the two apart), Put back refuses as today and points to Finish. What Finish does then is the first [choice](#choices-the-two-decisions-do-not-settle).

## Request Count

One change to one series: the read, the series' write, the read back, N day writes, the last read: N + 4, where N is the days not as planned after the series' write. Measured by the check on today's tree, over a series with a renamed day, a day at its own time, a day with its own notes and a deleted day:

| Change to all events | Day writes today | Days not as planned after the write |
|---|---|---|
| Title | 2 | 2 |
| Notes set; notes cleared | 1 | 1 |
| Time an hour later | 4 | 4 |
| Length | 2 | 2 |
| Zone at the same moments (alone, or with a weekday added) | 1 | 2 |
| First day a week later, along the pattern | 5 | 5 |
| A weekday added | 1 | 1 |
| Timed to all-day | 4 | 3, and the day at its own time as a record: 4 |

The one row that grows is the fault itself: the day at its own time was not sent, and ended wrong. An answer to an invitation costs four requests today (the event, its series, the write, a list of etags) and four under the rule (the read back takes the etag list's place), plus one per day to put back and one last read when there is any.

## Every Site That Writes a Series at Google

| Site | What it writes | Under the rule |
|---|---|---|
| `GoogleSeries::run`, the series step: a change to all events, the old half of "this and following", Finish, Put back, undo and redo of a change to all events | `PATCH` of the series | Fixed by this change (one path) |
| `GoogleSeries::run`, the insert step, with `insertChosen`'s path for a cancelled series made live again | `POST`, or `PATCH` to confirmed | Follows: read back and compared with the plan, nothing interpreted |
| `GoogleSeries::run`, the removal step | `DELETE` | Follows: read back, every record still live cancelled |
| `Rsvp::answerAtGoogle`, an answer for all events | `PATCH` of the guest list | Fixed by this change: the same function, its plan the series as it was |
| `GoogleUndo::undo`, an answer's undo | `PATCH` of the guest list | Fixed by this change, the same way |
| `Events::googleCreate`; `GoogleMove` | A new series | No record exists yet to compare |

Checked, and not series writes: `Events::googlePatchAll` and its undo (a single event only; a repeating event's goes through the plan), `googlePatchThis`, `googleDeleteThis`, a day's "use series value", and the undo of a create or a delete (a series' are series entries and go through `run`).

## What Goes and What Stays

- **Goes:** the mid-change call of `rebase` in `run()` and `$found`, which only feeds it; the sentences made from steps; `differences()` as a two-state comparison worded "Google kept" (the three-state comparison takes its place); the etag list after an answer.
- **Stays, needed before sending or for the preview:** the hidden-days predicate; `named` (is this key a day of the series Google holds: now also the comparison's "no day"); the planner; `masterBody` and the EXDATE lines it carries.
- **Stays for now, never read during a change again:** `afterMaster` with its notes clause, `unproven`, `addsWeekdays`, `googleOwn`, `heldAfter`, and `rebase` with its helpers. Two readers are left, both between changes: a step's expectation until the run's next read replaces it (it goes with the larger model's stored stop's read), and Finish when Google is not as last read (the first choice below).

## What This Does Not Cover

- Telling "the run never read after its last write" from "someone changed it since" needs one more stored fact, a stored-form change. The larger model's stored stop's read answers it.
- Storing in full what Google held before a change, for Put back (the owner's requirement in the larger model).
- Notes are sent back as Better-Cal's cleaned copy, not as Google held them (F7 of the second read).
- An answer to an invitation has no pending row: if the read after it can't be made, the answer stands, nothing is compared, and the next poll stores what Google holds.
- A change of one day, and a single event: one request each, no series step.

## What It Follows

Every CalDAV client in [the survey](google-series-survey.md) writes a repeating event as one `PUT` of the whole resource, conditional on the ETag it read, and merges nothing from the server into it afterwards (Thunderbird over CalDAV, Evolution, Nextcloud). This rule is the same on a provider that needs several requests: the whole plan asserted, the series conditionally and then each day. It differs from every Google client surveyed, none of which reads back and sends days again at Google. The survey's own line for the build, "exceptions are carried, not trusted", is this rule without the reading of intent that was added on top.

## The Check

`server/tests/db/google-series-read-back.test.php`, written before any product change. Its expectations are worked out in the test by date arithmetic from what was asked; it calls nothing in `server/src` to say what is expected. It has 100 cases in four parts:

- **Kinds of change by effects (66).** Eleven kinds of change to all events, crossed with six things the stand-in does to every record a series write keeps or revives (`FakeGoogle::$masterEffect`, off by default): nothing beyond its own rules, the start on its occurrence with the end left, the notes cleared, the series' title written in, and two nobody has seen (the end an hour later; a deleted day live again and a live one deleted). At the end Google and the rows equal the plan, Review says nothing, a poll changes nothing, and no request is added.
- **Stops (24).** Two kinds stopped at the first day write three ways, then Finish and, apart, Put back.
- **The answer (4)** to a repeating invitation, and its undo.
- **The series changed right after its own write (6):** the rows equal Google's end, each day of the plan still in the series is as planned, the series is written once, and nothing said claims a cause, speaks of a deleted day where nothing shows, or leaves out the value that landed. The words are checked as text, so this part catches only what it names; the comparison's data gets an exact check where it is built.

On the tree before the change 70 of the 100 cases fail (193 of 1,349 checks): 40 of the 66, the kept end as the stand-in itself has it among them, 22 stops, 3 answers and 5 series changes.

## Choices the Two Decisions Do Not Settle

1. **Finish, when the series' own write has landed and Google is not as the run last read it.** Either Finish goes on guessing with the prediction (today), or the days are the plan's there too and Finish says so. Both were tried, uncommitted. With the guess kept, a change whose read back failed (the network down right after the series' write) is finished with the own-time day wrong and nothing said (two cases of `google-series-stops`). With the plan's days those pass, and ten existing assertions change that today promise Finish keeps a day changed in Google Calendar after a stop. Default, by "promise less" and "it does not guess what Google did": the plan's days, with "This change stopped part way, and Google Calendar no longer holds the event as it was left then. Finish set every day as this change has it; anything changed on those days in Google Calendar since was replaced." Series fields the change does not change stay as Google has them (that part guesses nothing). This changes how Finish decides, so it is not built without a yes.
2. **The answer to an invitation and its undo.** Default: fixed through the same function. Probe 3.68 shows any series write clears notes; a write of the guest list alone was not probed, and under the rule it need not be. The other way is to file it until a live case. If a day can't be put back: "Your answer was saved. Google Calendar holds Mon 11 Jan of 'Sample standup' without the notes it had before the answer, and would not take them back."
3. **A series in another form after our write.** Default: it stands and is recorded, as the owner's rule says of the form, and the change is not left half done.
