# Interleaving Invariants

The series harness draws one edit at a time, and the series model has stayed clean through every review because of it. Google recovery, undo on Google calendars and the sync's treatment of rows with local-only data have had hand-written tests only, and every review and sweep since 1.5.4 found a neighbour of the last fix there. So the harness gets an interleaving mode (`run.sh --interleave`): a Google-backed series, then 3 to 8 random steps mixing writes here, changes made at Google by someone else, polls, failures injected into a write, Finish, Put back, undo and redo.

This file is that mode's oracle, written before its generator. Each invariant is a statement of the form "after step X, Y holds", derived from a sentence in the docs and cited, with the steps it applies to and what "equal" means for it. As everywhere in the harness, the checks share nothing with the product: what each side holds is read from the rows and from the stand-in, what a step changed is the harness's own record of the request it sent, and an expectation comes from these statements, never from the product's answer. Seven readings the docs left open were settled by the owner on 2026-11-08 (Settled Readings, below, and decisions.md); the oracle treats them as rules.

## Steps

Each step has a code the invariants and the findings use.

- **Writes here** (through `Events`, the plan API and Undo, as the client sends them): `w-all` a whole-series edit; `w-follow` this and following; `w-delfollow` delete following; `w-answer` an answer (Planned or Maybe) from a day on; `w-this-g` a "this event" edit of fields Google holds (title, place, notes, link, status, date, time, length); `w-this-l` a "this event" edit of local-only fields (tags, people, reminders, attendance, icon, coordinates); `w-this-m` both at once; `w-del1` one occurrence deleted; `w-create` a new event or series.
- **Changes at Google** (`r-*`, made through the stand-in's own API as another client, no `If-Match`, so Google's behaviour from the probe applies): `r-rename`, `r-time`, `r-zone`, `r-length`, `r-rule`, `r-bound` (COUNT or UNTIL), `r-skip+` and `r-skip-` (a day cancelled or brought back), `r-exc+` and `r-exc-` (an exception made, or put back to the series), `r-gonly` (a field only Google holds: attendees, colorId, transparency, visibility, Google's own reminders), `r-delete` (the series deleted).
- **Polls**: `p-delta` (the sync token), `p-whole` (a full list), `p-410` (the token refused, then the full list).
- **Failures**: `f-lost` (the stand-in takes write k, its answer is lost), `f-before` (Google refuses write k before taking it: a permission 403, a 4xx the app does not retry and that tells it nothing was written (a rate-limit 403 or a 429 is "Google is busy" and retried, rule 8); a transport error before sending would be a lost answer to the app, which can't tell the two apart), `f-fatal` (the stand-in takes write k, then an `Error` unwinds the process's transaction, as the recovery tests' `fatalAt` does). Each is a write step with its failure point drawn.
- **Recovery**: `finish`, `putback` (Review's two actions on the `google_half` item).
- **Undo**: `u-entry` (`POST /activity/:id/undo` of a chosen entry), `u-bare` (`POST /undo`), `redo` (an undo's own entry undone by its id).

"A write" below means any `w-*` or `f-*` step, an undo, a redo, Finish or Put back. "Any step" includes the `r-*` steps and polls.

## What Equal Means

- **A day** is a series' identity (its uid, or for the part a split made, the new uid) with the date its occurrence shows on in the series' zone. A day keeps its identity when a change moves its key within that date (a new clock or zone: "a key the series' clock or zone moved goes to the one occurrence on its day", decisions.md, "A local-only 'this event' edit of an ordinary day sends Google nothing"), and when a drag moves it to its image under the oracle (Expect). A day after a split's cut belongs to the new series.
- **Held here alone** (`alone(row)`): what Google never holds, read from the rows and the tables that hang off them: tags (by name, as a set), people (by id, as a set), reminders (the normalized list, null and `[]` distinct), attendance, icon, coordinates and their provenance (`location_lat`, `location_lng`, `location_source`, `location_provider`, `location_placed_at`), trip links (`event_links`, each as its role and the other end's day), the trip flag, the floating flag, duplicate decisions and plugin data. Sources: google-calendar.md, What Writes Send ("Not sent: attendees, reminders and color. Tags, people, reminders and the trip flag stay local and survive polls"), the floating paragraph under it ("keeps the setting here"), series-model.md, Own Fields (`Series\OwnFields::REFERENCES`: trip links, duplicate decisions, plugin data). Coordinates are held here alone, kept while Google's place text is unchanged and dropped when the text changes (A1).
- **The Google digest** of a series (`gdigest`), from the comparison the recovery rule defines (decisions.md, "'Nothing changed since' includes the series' zone": "start and end, the all-day flag, the rule, the content, and the zone of a timed event as the string Google holds"; series-model.md, Google as a Contract, Recovery: "compared field by field with the etag aside"): the master's start and end instants, all-day flag, zone string when timed, rule as parts (list values as sets, `INTERVAL=1` dropped, EXDATE lines as a set of instants), title, description, location text, link (`source.url`) and status; and each instance record by its `originalStartTime`, with the same fields, a cancelled record as a skip. A record whose fields are the series' own values at its occurrence is left out ("an ordinary occurrence here, no edited day", series-model.md, Google as a Contract). Etag, sequence, `updated` and the fields only Google holds are not in it.
- **The full Google state** (`gfull`): the digest plus every field Google holds that the app never sends (attendees, colorId, transparency, visibility, reminders, guest permissions), by series and by record.
- **The digest here** (`ldigest`): the same fields read from the rows of each live series (the master, and each edited row by its key), in the same shape, so `gdigest = ldigest` is a comparison of like with like. It extends `GoogleStand::held` and `stored` with the zone and the rows' zones; both stay the harness's own.
- **The state here** (`lstate`): `ldigest` plus `alone(row)` and `override_fields` (as a set) for every row, by day. Row ids are left out except where an invariant says an id is kept. `updated_at`, `google_etag` and the bookkeeping tables c-untouched names are left out.
- **Named**: a value removed by a step is named when an Activity entry the step wrote (its `summary` or `details`) carries the removed row's id and its shown date in its `details`, with words in its `summary`, or, for Finish and Put back, when an item of their answer's `differences` names it (A5).
- **What a step changed** (`asked(step)`): the harness's record of its own request: the fields it sent with their values, on which rows (a "this event" edit: the day; a whole-series edit: the series and every row not owning the field, with the choices committed; a placement change: the oracle's expectation for it, Expect), and for an undo the fields its entry's step asked. It is never read from the product's snapshots.

## The Invariants

### x-alone: nothing held here alone goes without a word

After any step, every value in `alone(row)` that a day had before the step is still on a row standing for that day, or the step removed it on purpose (it is in `asked(step)`, or the step deleted that day or its series and recorded the removal in its own undoable entry), or the step named it.

Applies after: every step. It is the mode's main check, and the one every sweep shape so far has failed.

From: google-calendar.md, What Writes Send ("Tags, people, reminders and the trip flag stay local and survive polls"; "either way the edited days keep their tags, people, reminders and trip links", of Finish and Put back); How Syncing Works ("if the series no longer has the day ... the day and its tags go, named in the poll's Activity entry"); decisions.md, "Recovery removes only what it made" ("a day with no row left for it is put back as it was and named, never dropped silently"); "A local-only 'this event' edit of an ordinary day sends Google nothing" ("A day the series no longer has is removed with its tags, people and reminders, named in the poll's Activity entry").

### x-sync-silent: a poll sends Google nothing

After `p-delta`, `p-whole` or `p-410`, the stand-in took no POST, PATCH or DELETE during the step, and `gfull` is as it was before it.

From: google-calendar.md, intro ("write-through, not two-way sync: each change goes to Google first"); How Syncing Works (a poll lists and stores); decisions.md, "Outside connections do only what changes".

### x-local-silent: a change here alone sends Google nothing

After `w-this-l`, after a change for all events of only fields held here alone, and after an undo or redo of an entry that was such a change, the stand-in took no write and `gfull` is unchanged; the row a `w-this-l` makes for an ordinary day is under its instance key and survives the next poll while the series has the day. A change is local only when it leaves every Google-held field of every series as it is: "this and following" and an answer from a later day are splits at Google whatever fields they carry ("'this and following' ends the old series at Google and inserts the new one", google-calendar.md, What Writes Send; an answer from a day on is among the changes to a whole repeating series that undo by a second write to Google, google-calendar.md, Activity), so x-local-silent doesn't apply to them; from the series' first day they are the whole series' change and it does.

From: decisions.md, "A local-only 'this event' edit of an ordinary day sends Google nothing"; "A change made here alone to a Google event undoes like any local change" ("keep their snapshots and undo here, sending Google nothing"); api-contract.md, Activity ("A change made here alone to an event on a Google calendar ... undoes here as on a local calendar, sending Google nothing").

### x-write-asked: a write changes at Google only what was asked

After a write that succeeds, for every field of `gdigest` that `asked(step)` doesn't reach, Google's value is the one it had before the step (an exception Google dropped on a master change and the app sent again counts as unchanged when it ends equal). Every field of `gfull` outside `gdigest` is unchanged on every series and record, whatever the step, and no request body the stand-in took carries `attendees`, `reminders` or `colorId`.

Applies after: `w-*` but `w-this-l`, `u-entry`, `u-bare`, `redo`, `finish`, `putback`.

From: google-calendar.md, What Writes Send (the list of what is sent; "Not sent: attendees (they would mail people), reminders (per-user at Google) and color"); decisions.md, "The undo of a Google edit sends back only the fields that edit changed"; series-model.md, Google as a Contract, What the app sends ("the fields that changed"). Put back's case is x-putback's.

### x-agree: the rows are what Google holds

After any write that succeeds and after any poll, `ldigest = gdigest` for every live series of the case, and a poll right after (`p-delta`, then `p-whole`) changes no row. After an `r-*` step and before a poll the two may differ, and nothing is asserted.

From: google-calendar.md, intro ("Google's answer is saved here the same way a poll would save it, so there's never a local-only version of a Google event"); series-model.md, Google as a Contract ("Acceptance is the read-back and the sync after it ... a second sync must change nothing"; "Local rows are written from what Google returned, never from the plan"); decisions.md, the local-only rule ("under a whole read and a delta read alike"). A write that Google kept differently is still equal here (the rows are Google's), and x-honest checks that it was named.

### x-follow: a row of local things follows its series

After a poll, a row whose own fields are all held here alone exists exactly when the series, as stored after the poll, has its key (or, for a key the series' clock or zone moved, the one occurrence on its date), and each field it doesn't own (title, place, notes, link, status, the time from its key, all-day, zone) equals the series' value at that occurrence. A row the series no longer has is gone and named (x-alone).

Applies after: `p-*`, chiefly after `r-time`, `r-zone`, `r-length`, `r-rule`, `r-bound`, `r-skip+`, `r-rename`, `r-delete`.

From: decisions.md, "A local-only 'this event' edit of an ordinary day sends Google nothing" ("after the poll it exists only while the series, as stored then, has its key ... and every field it doesn't own takes the series' value"); google-calendar.md, How Syncing Works ("its title, place, notes and time reach that day on the next poll, the day moves with the series' new clock time").

### x-dropped: a dropped record keeps what is held here

After a poll that reads a record of Google's as gone or as plain (dropped by `r-time`, `r-zone` or `r-rule`, overwritten by `r-rename`, put back by `r-exc-`), a day that had a row with values held here alone keeps exactly those values on a row for that day, its Google-held fields now the series' (owned no longer), while the series has the day; else x-follow's removal applies.

From: decisions.md, the local-only rule ("the same rule keeps an edited day whose own fields are all held here ... when a feed, Google or ICS, drops its record of that day"); series-model.md, Rows from Elsewhere ("Changed at the other end: ... inherited when it equals it"; "A person's local changes to such a row ... are its own and kept across polls"); Google as a Contract ("unless that row owns something Google doesn't hold").

### x-structure: one row per day, on the series' days

After any step, every live series of the case has at most one live row per key; every edited row's key and every skip is an occurrence of its series as stored (the harness's evaluator, `Rfc`); no live row has a master that is gone; an undo or redo makes no row for a day its series doesn't have.

From: series-model.md, Own Fields; the harness's invariant h (README); api-contract.md, Activity ("Something the change belonged to deleted since makes it `not_undoable`"); series-model.md, Google as a Contract ("no edited day outlives its series or comes back by a poll").

### x-own: an edited day follows the series in what it doesn't own

After any write that succeeds and after any poll, every field of an edited row outside its `override_fields` (the date, time, length and all-day placement included, by `OwnFields::placed`'s rule as the harness reads it) equals the series' value at its occurrence. After a poll, ownership follows Rows from Elsewhere: a field unchanged at Google keeps its ownership, one changed to the master's value becomes inherited, one changed to another value its own.

From: series-model.md, Own Fields ("A series-wide change reaches every field an edited day doesn't own; its own fields stay"); Rows from Elsewhere (the three rules).

### x-conflict: a stale etag is refused whole

After a write of fields Google holds, or an undo of one, sent while Google changed that series since the last poll or write here (an `r-*` step not yet polled), the answer is 409 `google_conflict`, the stand-in took no write that landed, and `lstate` is as before the step.

From: api-contract.md, Google Calendar ("every edit and delete is sent with the event's stored Google etag as `If-Match`, Google answered that the event changed since, and nothing was changed here or there"); Activity ("409 `google_conflict` when the event or series changed at Google since, with nothing written"); series-model.md, Google as a Contract ("the first write is conditional on the stored etag").

### x-pending: a failure leaves the change listed, or nothing at all

After `f-before` at the first write: an error, the stand-in unchanged, `lstate` unchanged, no `google_pending` row and no `google_half` item. After any write to a series while a change of it is half done (from an earlier step): refused with the pending reason, pointing to Review, nothing written here or at Google (A6). After any other failure (`f-lost`, `f-fatal`, or `f-before` after an earlier write landed): the answer fails with `pending`; Review lists one `google_half` item for it whose `stepsDone` covers every write the stand-in took, the one whose answer was lost included; x-alone holds.

From: series-model.md, Google as a Contract, Recovery ("The pending row is written on a connection of its own, so it is committed before the first request whatever the account's transaction does after"; "A transaction rolled back with nothing sent takes it too"; "The request that stopped fails with the error and `pending`"); api-contract.md, Review (`google_half`, `stepsDone`: "a step whose answer never came included").

### x-finish: Finish completes the change over what stands now

After `finish` that answers ok: for every field the pending change asked (`asked` of the failed step, the local part included: tags, people, reminders and flags, review R3), the rows and Google hold the change's value; for every other field, they hold the value from just before Finish; every place where either side departs from that is named in `differences`. A row made here since the failure keeps its id and what it holds, with the plan applied over it; a row the change's own stores made is gone, or, holding something held here alone since, has handed it to the row left for its day. The pending row and the Review item are gone. After `finish` that answers 409 `google_finish_local`: nothing held here alone is lost and the item stays listed.

"The change's value" for a field change is the value sent; for a placement change (time, zone, rule, bound, a split) it is Expect's expectation for the edit, read from the series as it was when the change was planned, and is asserted only when nothing at Google changed since; with a change at Google since, the digest comparison is equal-or-named.

From: google-calendar.md, What Writes Send ("Finish (the rest of it, from what Google has now, then its part here: tags, people, reminders and flags)"); series-model.md, Recovery ("Finish runs the same targets from Google's state, then writes the change's part here ... a row made here since ... stays with what it has, and the plan is applied over it"); decisions.md, "Put back is all or nothing" ("Finish writes the local part of a mixed change from the plan kept with the pending row"); "Recovery removes only what it made".

### x-putback: Put back is all or nothing, and the harness knows which

The harness decides on its own whether a target series changed at Google since the failure: for a series whose last step was answered, any `r-*` step on it since (Google changes the etag on every write, probe 5.1); for one whose last step's answer was lost, an `r-*` step since that changed its `gdigest`.

After `putback` with a series changed since: 409 `google_changed_since`, `left` names exactly the series changed since, the stand-in took no write, `lstate` is unchanged and the item stays listed. With none changed: ok with `left: []`; every target series' `gfull` at Google is what it was before the failed change (an inserted series cancelled); the rows here are as they were before the change, but for what was made here since, which stays (a row a store made that was given something held here alone hands it to the day's row); the pending row and the item are gone.

From: google-calendar.md, What Writes Send ("Put back (every step sent, reversed, a step whose answer never came included, all or nothing: only while nothing changed at Google since; if anything did, even in a series this change inserted, nothing is put back"); api-contract.md, Review (`put_back`, `left`); decisions.md, "Put back is all or nothing"; "'Nothing changed since' includes the series' zone". A change at Google to a field only Google holds is A2.

### x-undo-fields: an undo takes back only what its entry changed

After `u-entry`, `u-bare` or `redo` that answers ok, every field in `asked` of the entry's step is back at its value from before that step (for redo, from after it), and every other field, here and at Google, is as it stood before the undo (a change made since in another field, a remote one read by a poll included, stays). When a later entry not undone asked the same field on the same row (or, for a repeating event, changed its times where this entry deleted, edited or split off a date, or the other way round), the answer is `stale_undo` and nothing is written anywhere. When the trip rules refuse what it puts back, `undo_conflict` and nothing is written.

Since the 2026-11-09 review the check is exact, against the rows as they were before the edit: an undo of a field edit puts each field the edit set back at the value it had then (on a day the edit made its own, the series' value as it stands now when the day didn't own the field before), a redo puts the edit's value back, and every other field of every row, and every row but the edit's own day, is as it was before the undo or redo. Before, the check asked only that the field differ from the value the edit set, and never looked at a redo's content (review of 56e62890 to 9de4e3dc). From: decisions.md, "The undo of a Google edit sends back only the fields that edit changed" ("a field a later entry changed again is stale as on a local calendar; redo carries the same fields"); api-contract.md, Activity (`stale_undo`, `undo_conflict`; a `series` entry "each series as putting the entry's snapshots back here leaves it, so what changed since in other fields stays"); series-model.md, Undo for Google series.

Since the review of 9de4e3dc to 8e568077 the check is judged against states recorded when the entry was made, never inferred from the chain of entries. Each Activity entry's rows are recorded by identity (a series by its Google id, a day by its series and key, a single event by its Google id; row ids are ignored, since a recreated row takes a new one) before and after the step that made it. An undo of that entry is expected to leave what the entry changed as it was before (rows it made gone, rows it removed back with their values then, each field it changed at its value then, the own lists as the flipped entry implies) and a redo as it was after; every other row's own fields and own list, and every row the entry never touched, are as they stood before the undo or redo. It is stale (`stale_undo`, nothing written) when any field the entry changed differs now from the entry's recorded after state for an undo (its before state for a redo), when a row it made has changed since in any field, inherited ones included, or in its own list (a newer change stands on the item it made), when a day on a date it skipped or brought back has been made, removed or changed since, or when the series of a day it changed has gone. From: api-contract.md, `stale_undo` ("changed the same field, removed or made the same item, added something to an item this one made, or ... changed its times where this deleted, edited or split off a date, or the other way round"); the first runs of seeds 307 and 419 with the recorded states found the oracle reading only the fields an entry changed, and 51 correct refusals reported as failures. Fixed cases: `a-tagged-day-then-a-place-for-all-then-its-undo-is-stale`, `a-titled-day-then-tags-for-all-then-its-undo-is-stale`, `a-day-deleted-undone-edited-then-the-delete-redone`. A day a `w-race` step's change at Google touched is someone else's change and no part of the step's entry. Whether an entry is an undo, for the bare Undo, follows from how many `undoes` hops lead back to a plain change: an odd number is an undo's own entry, which the bare Undo passes over. Fixed cases: `a-day-title-edited-undone-redone-undone-redone`, `a-series-rename-undone-redone-undone-redone`, `a-day-title-undone-redone-then-the-bare-undo`, `two-days-titled-then-one-undone`. From: decisions.md, "The undo of a Google edit sends back only the fields that edit changed"; api-contract.md, Activity and `POST /undo`.

### x-undo-bare: the bare Undo steps back one change at a time

After `u-bare`, the entry undone is the newest one from the session's sources that is neither undone nor an undo's own entry, exactly as on a local calendar; a redo's entry (one that undoes an undo's) is a change of its own, so the bare Undo after an edit, its undo and a redo takes the redo back (decisions.md, the same entry, settled 2026-11-09 when the harness asked); an undo's entry names the entry it undid (`details.google.undoes`), and a change with its undo beside it makes no older entry stale.

From: decisions.md, "The bare Undo steps one change further back on a Google calendar as it does locally"; api-contract.md, `POST /undo` ("so each call steps one change further back, on a Google calendar as on a local one").

### x-honest: an answer says what happened

After every write: an answer that says ok holds what it claims (the rows and Google carry `asked`, or each departure is in a `google_differs` Review item, or for Finish and Put back in `differences`); a `google_differs` item appears exactly when Google kept something other than the plan; an error answer without `pending` leaves `lstate` and `gfull` as before; an error with `pending` is x-pending's. Every write that succeeds leaves one Activity entry, undoable unless it is Finish or Put back (log only).

From: api-contract.md, Series Plans, Google calendars ("a difference goes into the Activity entry and a Review notice (`google_differs`)"); Google Calendar ("502 `google_write_failed` (Google refused or is unreachable; nothing changed)"); Activity (`not_undoable` for "Review's Finish and Put back"); google-calendar.md, Activity paragraph.

## Which Invariants Run After Which Steps

| Step | Invariants |
|---|---|
| `w-all`, `w-follow`, `w-delfollow`, `w-answer`, `w-this-g`, `w-this-m`, `w-del1`, `w-create` | x-alone, x-write-asked, x-agree, x-structure, x-own, x-conflict, x-honest |
| `w-this-l` | x-alone, x-local-silent, x-agree, x-structure, x-own, x-honest |
| `r-*` | x-alone (rows here untouched by the step itself), x-structure |
| `p-delta`, `p-whole`, `p-410` | x-alone, x-sync-silent, x-agree, x-follow, x-dropped, x-structure, x-own |
| `f-lost`, `f-before`, `f-fatal` | x-alone, x-pending, x-structure, x-honest |
| `finish` | x-alone, x-finish, x-write-asked, x-agree, x-structure, x-own, x-honest |
| `putback` | x-alone, x-putback, x-agree, x-structure, x-own, x-honest |
| `u-entry`, `u-bare`, `redo` | x-alone, x-undo-fields, x-undo-bare (`u-bare`), x-local-silent (local-only entries), x-write-asked, x-conflict, x-agree, x-structure, x-own, x-honest |

The harness's existing checks for a single edit (a to i, c-untouched, 8-google, f-undo) still run on every `w-all` and `w-follow` step whose series nothing else touched since the last poll, as in `--google`.

## Added for the 2026-11-09 Fix Round

The first two 500-case runs found where the rules are thin; these steps and checks measure the round's rules directly. Each has a fixed case under `cases/interleave/`, named after what it shows.

**New steps.**

- `r-etag`: the series stored again at Google as it is, so its etag moves and nothing else does.
- `w-race`: a rename for all events, with a day renamed at Google between the app's master write and its read-back (the stand-in's `race` knob).
- `p-empty`: a whole read, or a 410 reset and its whole read, that lists nothing for a calendar holding rows (`emptyNext`).
- `p-overlap`: a delta poll during whose fetch a day is tagged here (`duringFetch`).
- `p-410-pending`: a poll after a 410 while a series has changes not yet at Google (skipped when none has).
- `u-redo` (2026-11-09, after the external review): an undo of a chosen entry, then a redo of the newest undo entry, drawn as those two steps, so a redo nearly always has an entry to redo; the separate `redo` stays.
- `p-budget`: a whole read stopped after one page of two items (`pageSize` and `GOOGLE_SYNC_PAGES` set to 1), then the read that completes it. A calendar whose listing fits one page completes the first read and the step judges nothing.

**x-conditional: every request that changes something at Google is conditional.** After any step, every PATCH and DELETE the app sent carries `If-Match`. A day with no row here is no exception: its instance is read first and that read's etag sent. From: api-contract.md, Google Calendar ("every edit and delete is sent with the event's stored Google etag as `If-Match`"); series-model.md, Google as a Contract ("the first write is conditional on the stored etag"); decisions.md, the round's rule that every Google request the app sends is conditional. Fixed case: `an-ordinary-day-edited-after-a-rename-at-google-is-conditional`.

**x-conflict and x-pending, extended: a refusal leaves everything here.** "Nothing written here" now includes every row's `google_etag` and the calendar's `google_sync_token`, beside the rows as compared, wherever a check says a refusal or a failure before anything was sent changed nothing (x-conflict, x-pending, x-honest, x-undo-fields, x-putback). A series deleted at Google since the last poll is refused with `google_deleted` (a 404 or 410 on the target) rather than `google_conflict`, by the round's rule that Google's refusals are classified by Google's reason; a rate-limit 403 or a 429 is retried and, when it persists, `google_busy`. Since rule 1 landed, x-conflict asks that a change to a series changed at Google since the last poll be refused and write nothing, whatever the refusal's reason: a refusal decided here before Google is asked (`stale_undo` for an undo whose field changed since, `invalid_recurrence_instance` for a day past the editing horizon) protects the change at Google as well as `google_conflict` does, and the code goes in the tally. From: api-contract.md, Activity (`stale_undo`, "nothing is written") and Google Calendar ("nothing was changed here or there"). A series commit or a Finish that reached Google stores what Google holds after its read-back, so for its series the harness resets "changed at Google since the last poll" as a poll would (series-model.md, Google as a Contract: "the rows stored are what Google holds after the last read-back"). A change at Google only to fields the rows here don't hold (guests, colour, visibility, transparency: `r-gonly`) is no conflict for a write: the write is expected to go through, and x-write-asked checks that Google keeps those fields' values; Put back still counts it as a change since (x-putback, A2). From: decisions.md, "A write is conditional on what is shown here; Put back on everything" (settled 2026-11-09). "Changed since" is read the same way for every remote step: a write is expected refused when the series' digest at Google differs from the rows here, so remote steps that cancel out (a day skipped, then brought back) expect no refusal. From: api-contract.md, Google Calendar ("nothing was changed here or there"); decisions.md, the round's rule that a refusal records nothing here.

**x-conflict, the digest's rule: an etag-only change is no conflict.** After `r-etag` and before the next poll, a change to that series is not refused with `google_conflict`, since its content at Google is the content here. From: decisions.md, "A Google series' state is one digest of every field" and the 2026-11-09 decision that nothing-changed-since is the digest for acknowledged steps too. Fixed case: `an-etag-only-change-at-google-is-no-conflict`.

**x-write-asked, extended: no key outside the writer's list.** Every request body carries only `summary`, `description`, `location`, `source`, `start`, `end`, `status`, `recurrence` and a resource's identity (`id`, `iCalUID`, `recurringEventId`, `originalStartTime`): the fields Google holds of an edited day (OwnFields' list less `HELD_HERE`) and the series' rule. From: google-calendar.md, What Writes Send; series-model.md, Own Fields; decisions.md, the round's rule that a request body is built from the writer's allowed-field list. When the product names its list, the harness keeps its own and the two are compared, never one read from the other. Fixed case: `series-and-day-writes-send-only-the-writers-keys`.

**x-pending, extended to undo and redo, and the poll rule.** An undo or redo of an entry of a series with changes not yet at Google is refused with the pending reason, writing nothing, as any write is (the entry's series read from its own Google id when the harness made no record of its step). After any poll while a series has changes not yet at Google, that series' rows are as they were: the poll records what Google holds for Finish to rebase over and neither removes nor rewrites them. From: decisions.md, "A write to a series whose change is half done is refused" ("undo and redo included"), and the round's poll rule for a series with changes not yet at Google. Fixed cases: `an-undo-while-a-change-is-half-done-is-refused`, `a-410-while-a-change-is-half-done-leaves-its-rows`.

**x-empty: an empty listing never deletes.** After any poll whose whole read (the first, or after a 410: `p-empty`, `p-whole`, `p-410`, and the polls x-agree makes) listed nothing for a calendar holding rows, no row changed, the sync position did not move to the empty listing's token, and Review holds one open `google_empty` notice for the calendar; the poll's refusal is this check's expected outcome, never x-honest. After a poll whose whole read listed events, no such notice is open. From: google-calendar.md, How Syncing Works ("A full listing (the first, or after a 410) that comes back with no events for a calendar that has some here is not applied: nothing is removed, the poll shows the reason, Review lists the calendar, and the next poll lists it again"); decisions.md, the round's rule that an empty listing never deletes (vdirsyncer's empty-storage guard; Google names permission changes among a 410's causes). A series deleted at Google on purpose still goes through a delta poll, which this check doesn't judge. Fixed cases: `an-empty-whole-read-deletes-nothing`, `an-empty-listing-after-a-410-deletes-nothing`.

**x-overlap: a write during a poll's fetch survives the poll.** After `p-overlap` that went through (a refused write is tallied apart), the tag given during the fetch is on its day, under whatever key the day has after the poll (a key the series' clock or zone moved goes to the one occurrence on its day, decisions.md, "A feed's change to a day keeps what is held here"). Whether the poll drops its result or applies it over the write is the product's; that the write survives is the rule. From: google-calendar.md, What Writes Send ("Tags, people, reminders and the trip flag stay local and survive polls"). Fixed case: `a-tag-given-while-a-poll-fetches-survives-it`.

**x-budget: a stopped read applies nothing.** After a `p-budget` whose first read was stopped, the rows are as before it; the read that completes it is judged by x-agree. From: google-calendar.md, How Syncing Works ("If a response is too large or stops making progress, Better-Cal keeps the last successful events and sync token"). Fixed case: `a-whole-read-stopped-by-its-page-budget-applies-nothing`.

**x-readback: a change at Google during a write is kept or named.** After `w-race`, the day renamed at Google keeps that title there, or the step was refused, or a `google_differs` notice names it. From: series-model.md, Google as a Contract ("a 412 at any step is a change made elsewhere since, shown as a conflict and never overwritten"); decisions.md, "Finish applies the change's deltas over what holds now" ("leaves every field and day the change did not change exactly as it finds them"). The commit's own carrying step reads otherwise today ("each record the plan has no exception for put back to the series' values at its occurrence"), so this check asks whether a commit follows Finish's rule; the owner decides which reading stands. Fixed case: `a-day-renamed-at-google-during-a-series-write-is-kept`.

**x-putback, by identity.** Put back is judged on the rows of the target series by identity, not by row id: a master Put back recreates keeps its new id, with what hung off it reattached, so the rows compared are each series' and day's fields and own list, ids left out (review of 9de4e3dc to 8e568077, case 454 of seed 419). From: series-model.md, Recovery ("a row the recovery brings back under a new id ... takes back its id, its own columns and what hung off it").

**x-conflict, a removal of a series Google has already deleted.** Deleting a series here (`delfollow` from its first day, `del1` of a single event) after the series was deleted at Google and before the next poll is not expected refused: the removal is idempotent and sends nothing that could overwrite a change at Google; the run tallies it as "removal of a series deleted at Google" (review of 9de4e3dc to 8e568077, case 303 of seed 419). From: api-contract.md, Google Calendar (`google_conflict` protects "a change made at Google since the last poll"; a removal of what Google already removed overwrites none). The docs name `google_deleted` for an edit whose event is gone at Google and say nothing of a removal of it, so either answer, the removal done here or `google_deleted` with nothing written, passes; the reading is the review's, and a decisions.md entry is owed if the owner wants one answer.

## Oracle Probes

A check that passes says something only if it fails on what it claims to catch. Since the review of 9de4e3dc to 8e568077 every check has at least one probe in `tools/edit-harness/OracleProbes.php`: a fixed case that passes, and damage injected after one of its steps into what that step's checks read (the rows, the requests the stand-in took, the answer, the Activity entries, Review), the kind the check's contract names: a renamed or deleted unrelated row, a lost own field, a stale field, a missing `If-Match`, a write carrying a key only Google holds, an advanced sync token, a missing notice, a refused write answered ok, an undo entry left not undone. `run.sh --interleave --oracle-probes` runs each probe's case clean and damaged and passes a probe when its check fails more often on the damaged run. A check with no probe that passes is unvalidated, and every interleaving run's summary lists the checks no probe covers.

## Settled Readings

The first draft listed seven readings the docs left open; the owner settled each on 2026-11-08, and the product-facing ones are in decisions.md under Sync and Storage. The oracle checks them as rules.

- **A1, coordinates.** Coordinates are held here alone: kept while Google's place text is unchanged, dropped when the text changes. A step after which the day shows another place text may take them, whoever changed the text: the step's own request, or Google dropping the day's record so the day shows its series' text again (x-alone and x-dropped exempt them then); a poll that brings the same text back may not. From decisions.md, "A feed's change to a day keeps what is held here": the row keeps "coordinates picked for the place text it shows", and "a new place text clears them". Until 2026-11-09 the checks exempted coordinates only when the step's own request changed the place, which reported a day whose record Google dropped as a loss.
- **A2, what "nothing changed since" covers.** Every field of the Google event resource except Google's own volatile metadata (etag, updated, sequence, htmlLink, iCalUID, kind, creator and organizer stamps), so a change to attendees, colorId, transparency or visibility at Google since a lost answer refuses Put back. The harness counts any change made at Google to a series of the change after the failure as a change since (x-putback).
- **A3, an undo after a poll brought a remote change of the same field.** `stale_undo`, as a later local change would make it (x-undo-fields).
- **A4, Finish over a change made at Google since.** Finish keeps a remote change to any day or field the change did not touch; it never overwrites it, and names only true conflicts (x-finish).
- **A5, what "named" means.** The row's id and its shown date in the entry's `details`, with words in its `summary`; for Finish and Put back, an item of the answer's `differences`. A removal named by its date alone is reported as such.
- **A6, a write while a change is half done.** A write to a series while a change of it is half done is refused with the pending reason, pointing to Review (x-pending).
- **A7, a process killed outright.** Stays with the database suite's kill test; the harness throws an `Error` after the stand-in took the write, as the recovery tests' `fatalAt` does (`f-fatal`).

## As Built

The generator follows the statements above with these narrowings, each a choice of what to draw rather than a weaker check:

- Placement changes (a whole-series time or length change, a COUNT) are drawn from the series' first day only and checked by the relational invariants (x-alone, x-agree, x-structure, x-own); x-finish asserts the change's values for field changes (title, notes, place, link, tags), where its value is the value sent. Expect's placement oracle is the edit harness's, not repeated here.
- x-agree runs after a write or poll only when no series of the case changed at Google unpolled and no change is half done; the poll it adds (a delta, then a whole read) is the second sync the contract names.
- x-structure, x-own and x-follow skip a series changed at Google since the last poll, which the next poll settles.
- x-alone is deferred on a failure that leaves a change half done (the pending row holds what it took) and judged at Finish or Put back against the rows before the change: Put back may remove nothing, Finish what the change's own request reaches.

## Not Covered

A day past the 730th occurrence of a series with no end (the product leaves it as Google has it and names it; the `--google` mode covers it), invitations organized by someone else (their answer is Google's `responseStatus`, a different path), a series several times a day and a rule the expander can't read (neither exists on a Google calendar, `GoogleStand::unheld`), and moves between calendars (refused on Google).
