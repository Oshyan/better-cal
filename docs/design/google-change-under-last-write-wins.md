# A Change to All Events Under "Last Write Wins"

A written model, not yet built as a whole; 1.5.6 built the part of it that concerns the days after the series' write ([the read-back model](google-series-read-back.md)). Its build must answer every entry of [Carried In From 1.5.6](#carried-in-from-156) by number, and its independent reader checks that it did. It replaces the way `GoogleSeries::run()` reads Google during a change to all events of a repeating event on a Google calendar, and the way Finish and Put back compare. The rule it follows is the owner's: while one of our own changes is being sent, the last write wins; between changes, Google is read first and a change made there is reported, and a change to a field we are not changing is no conflict. Evidence: [probe 2026-11-13](research/google-series/probe-2026-11-13.md) (both runs), [replay round 4](research/google-series/replay-2026-11-13.md), the outside read of `5f60dd8b..14a17871` and its probes (on `google-156-read-fixes`; on this branch the regular tests google-series-stops, google-series-request-count, google-exdate-kept-through-changes, google-day-other-form-paths, google-upgrade-from-155).

## Why

Today, after the master write, `run()` reads Google back and hands the read to `rebase`, which compares it with a prediction of Google's own effect (`afterMaster`). Anything Google did that the prediction lacks reads as someone else's change and is kept in place of the plan. Every effect we fail to predict becomes a silent loss: replay round 4's cause 2 is one (a master PATCH that leaves out the notes clears every kept day's own notes on a series with none, six kinds of six, probe 3.52 to 3.57); the reader found `unproven()` still predicting records dropped on a link and on a zone change at the same moments, which 3.17, 3.32 and 3.52 to 3.61 show kept. Then `dayCondition` reads the whole series and the day again before each day's write, so a change with N edited days sends 3N+4 requests, stops half done on a stranger's change the documented rule says we don't catch, and stops on any one lost read.

Predicting one more effect fixes one case. The class goes when the run stops interpreting: inside our own change, what Google holds after our master write is not a claim about anyone's intent, only the state we now write the days over.

## The run, step by step

For each series the change sends anything to:

1. **Read and check (Rule 1, as today).** One list read by iCalendar UID with `showDeleted`. If it differs from the rows here (`condition`, `sameAsStored`), nothing is sent and the change is refused, naming what differs. Its master etag is the If-Match of step 2.
2. **The master write, conditional (as today).** `PATCH` the master with `masterBody` (the fields that differ, the EXDATE lines Google holds carried, issue 252), If-Match from step 1. A 412 refuses the change with nothing sent.
3. **Read back.** One list read, repeated (up to 4 tries, as `readBack` does) until it shows the master's new etag. If the master is in the other form (timed or all-day) than the plan's, the change stops half done (as today: no day of the plan can be named there).
4. **Send every day that is not as the plan says**, by `stepsFor(target, read)` as it is today: an edited day not held as the target has it is sent whole, a deleted day not cancelled is cancelled, a record the target has as ordinary is put back to the series' values. Each is one `PATCH` of the day's id, **with no If-Match and no read before it**, in the order `stepsFor` gives, each once. A 404 (a day past the 730-occurrence cap; a hidden day, cause 1) is not retried and is named in the answer as a day Google would not write.
5. **Read back once more and store.** One list read. What it shows differing from the target is named to the person (`differences`). This read is the **stop's read** (below).

Nothing in steps 3 to 5 is interpreted as someone else's change, and nothing Google did is kept in place of the plan: a day whose notes Google cleared is sent again by step 4 because it is not as the target says, with no rule about notes. A stranger's change to the series inside the window is written over where the plan sets something, which is what "last write wins" says and the user docs already state.

When the change sends no master write (nothing in `masterBody`), steps 2 and 3 go and the first read is the one step 4 compares with.

## Request count

For one series and N days not as the plan says after the master write (for a change over N edited days that Google drops or alters, N is those days):

- With a master write: **N + 4** (read, master `PATCH`, read-back, N day `PATCH`es, final read), the same as before the issue 256 fix and down from 3N + 4 now. With N = 0 the final read is the read-back, so 3.
- With no master write: N + 2.
- Each read is one list request while a series has fewer than 2,500 resources (one page); the read-back in step 3 may be asked up to 3 more times while Google's list lags the master write. A split is two series, each counted this way (the new one starts at its `POST`, with no read before it).

## What is deleted

- In `run()`: the mid-run `rebase` call and `$found`, which only feeds it; the call to `dayCondition` and the `$now`/`heldAfter` chain that fed its check; the `expect` digests on the master step (`afterMaster`) and day steps (`heldAfter`), and `$settled`, which overwrote them with a digest.
- `dayCondition()` whole, and with it the conflict it raises mid-run.
- `afterMaster()`, `unproven()`, `addsWeekdays()`, `keying()`, `patternOf()`, `heldAfter()` and `named()` (its two callers are `afterMaster` and `rebase`). `afterMaster` has three callers (the master step's `expect`, and `rebase`'s P and Q); all go.
- `rebase()` and what only it uses (`rekeyed`, `notKept`, `sinceWords`, `threeWay`, `ownPart`, `dayIn`, `listed`, `were`), replaced for Finish by the comparison below. `groupsOf` stays (the undo's `inverseEdit` uses it).
- In `putBack()`: `$answered`, `$asBefore`/`$unmoved`/`$unmovedNone`, `unchangedSince()` and `landed()` for series (the lost-answer reasoning by digest), replaced by the comparison below. The single-event lost-answer check (`fieldsOf` of the event as sent) stays.
- The unit tests of `afterMaster` and `rebase` in `server/tests/google-series.php`, their uses in `google-hunt-lib.php` and the mutations aimed at them.

About 700 lines of `GoogleSeries.php` go; what comes in is the stop's read stored, one comparison function, and Finish's adopt step (below).

**Is the prediction needed anywhere a person could be hurt, once the mid-run reading is gone?** No. It was used for two things: the mid-run reading (gone) and the baseline Put back compares with when the stop's read never happened. In the second it can only refuse a Put back that was safe (the reader's network-down probe shows this happening) or let one through over a change it mispredicted; it never protects a person's data that the honest fallback below doesn't. So it goes whole.

## The stop's read

At the end of a series' run (step 5), and in the `catch` when a change stops half done (the best-effort read already made there), what Google held is stored with the pending row as content: the series' resources as `held()` reads them, every field, not a digest. It is "what our change left", by definition, whether every step landed or not: our own writes all happened before it.

It is stored in a new nullable column of `google_pending` (a migration that adds it; no row is rewritten). A row with none, because the read failed (the network was down at the stop, which is how most changes stop) or because the row is older than the column, has **no baseline**. That is a state the model handles, not a reading of an older form.

## Finish and Put back

Both are changes between changes: Google is read first. One function serves both, with the recovery's target (the change's targets for Finish; the befores for Put back):

1. **Read** each series (one list read; `refresh` stores it here as today).
2. **Compare with the stop's read.** Since: what differs between the stop's read and now, per day (by key) and per master field group (title, notes, place, status, link, time, rule). To send: what differs between the stop's read and the recovery's target, in the same units, plus any day changed since whose key is not a day of the target (its key goes away with the recovery's master write).
   - Something both changed since and to send: **nothing is sent**; the notice names each day and field that changed in Google Calendar since. Put back then offers Finish, as today; Finish offers to finish anyway, writing the plan over it (an owner choice: see below).
   - Changed since but not to send: **kept**. The target takes Google's value for that day (its record, its cancellation, or none) or that master group before the run, and the notice names it as left as changed in Google Calendar.
   - **No baseline**: the read of step 1 stands in for it. The notice says so in plain words: Better-Cal couldn't read the event when the change stopped, so a change made in Google Calendar since then can't be told from this change's own, and is replaced on the days and fields this change sets.
3. **Run** the target as above (steps 2 to 5; step 1's read is this read, so no request is added). Finish with its master step already done sends no master write.

No prediction is needed: whatever our change did is already in the stop's read, so what differs from it now is someone else's.

## What Google accepts with no If-Match

| The day at Google | With no If-Match | Also with its own etag |
|---|---|---|
| Ordinary, no record: its fields | 2.1 to 2.3 (timed and all-day), 2.7 (the app's whole body), 2.8 and 2.9 (east of UTC), the setup of every group 3 and 10 case | |
| Ordinary, no record: cancelled | 2.5 (`PATCH` status), 2.4 (`DELETE`) | |
| A live record (an edited day) written again whole | 3.66: 200, its new values | 3.36 and 3.40 change 2, 10.2, 10.3 |
| A revived record (dropped, then a day again) | 3.66: 200, its own values | 3.36, 3.37, 10.2, 10.3 |
| A cancelled record made a live edited day | 3.66: 200, `confirmed`, listed live with its own values | |
| A record put back to the series' values | 3.66: 200, the series' title and notes | 10.2 (8 sent again) |
| A live record cancelled | 3.66: 200, `cancelled` | 3.36 (occurrence 3 after revival) |
| Ordinary, no record: cancelled again in the same run | 3.66: 200 | |
| Days sent after a clock change and after a switch (the run's own order) | 3.63, 3.64: every day write 200 | |
| A day hidden by a switch at a meeting key | 404 (3.48, 3.65) | 412 (3.48) |
| A day past the 730-occurrence cap | 404 (contract) | |

Every state a run meets is now proven with no If-Match (live run of 2026-11-13T20:56:40+01:00). Still unproven and not cased: a day of a series someone deleted inside the window (10.5 shows its instances read 200 `cancelled`; whether a `PATCH` with no If-Match revives one is unknown; the final read shows the master cancelled and the change names it).

`GoogleWriter::call` refuses a `PATCH` or `DELETE` with no If-Match ("none goes without the etag of a read"). The day steps of `run()` need an explicit exemption there, named for what it is; every other write stays conditional.

## Cause 1 under this model

What hides a day is a record (live or cancelled, with its `originalStartTime`) at an instant that is also a day of the new form, at the moment of the switch. A dropped record there does not (3.63, 3.64), and a record at the series' values does (3.65). Two guards, one predicate:

- The planner's refusal, from the rows here, before anything is sent: an edited or deleted day at such an instant.
- A second check in `run()`, over step 1's read: any record of the series at such an instant, a record at the series' values included. The rows here can't show that one (it is an ordinary day to the app), and 3.65 shows it is hidden too, so this check is needed. It costs no request. It refuses before the master write, with the same sentence.

The refusal in `Model::compute()` does not make the mutation `refused-default-stays-selected` killable: `compute()`'s refusals reach the option-disabling loop in `run()` (each option's plan computed and disabled with the reason), not the loop in `mapHeld()`, which only a refused plan from `map()` reaches. Placed in `map()`, the refusal would let `mapHeld()` pick another bound or edge to avoid it, silently cutting a person's series; it belongs in `compute()`. So the `mapHeld()` loop stays unreachable and should go with its mutation (it went with the narrow path: `map()` is called directly).

## Tests whose expectation changes

- `google-series.test.php`, "Another client renames that day between the series' master write and its read-back": the day is put back to the series' new title at Google (last write wins); its tag stays here. Today the remote title is kept.
- `google-series.test.php`, "Put back puts nothing back when a series changed at Google since": the stranger's title is not something Put back sends (it sends the time), so Put back goes ahead, keeps the title and names it. Today it is refused.
- `google-series-recovery.test.php`, F1 and the "another part of the series changed elsewhere" and "only the zone changed elsewhere" cases: each goes ahead where what changed since is not something Put back sends, keeping and naming it; refused only where both touch the same day or master group. F2, F3, F5 and F6 stand.
- `google-series-back-again.test.php`: the two "inside the window" cases go ahead as now, on the stop's read instead of the guess; "an ordinary day renamed at Google" after a clock change is still refused (its key goes away with Put back's master write), now naming the day.
- The reader's stops probe (google-series-stops): Put back with the network down through the stop goes ahead with the no-baseline sentence; no false refusals.
- `google-etag.test.php`, "a write is conditional on a read made just before it": the master's write is; a day's is not.
- The edit harness: `x-readback` ("kept or named") is replaced by the check below; its oracle probe "a day renamed at Google during a series write is put back to the series without a word" becomes the correct outcome, and the injected damage becomes "kept"; the Interleave mode's exclusion of a raced day from the undo comparison goes.
- The unit tests of `afterMaster` and `rebase` go with them.

## The check

**After a change to all events that the app reports complete, the stand-in holds on every day of each series the change's target names exactly what the target says (its own fields for an edited day, cancelled for a deleted day, no record or a record at the series' values for an ordinary day) and the master the target's master, except days the change names as refused by Google, whatever the stand-in or a stranger did between the master write and the final read.**

The harness computes it from its own records: the target from what was asked and the rows before (its spec oracle, nothing in `server/src`), against the stand-in's store after the run. It must catch, each injected: the stand-in clearing a kept day's notes on a master write that leaves them out (cause 2) with the app not sending them again; a stranger's rename of a day between the master write and the read-back kept; a revived record left (a deleted day live again, an edited day at the series' values); any one day step dropped (a mutation skipping the k-th write, or one kind); a 404 not named.

## Owner choices

- **Finish anyway.** Decided: when a recovery and a change made since in Google Calendar touch the same day or field, Finish offers to finish anyway, with a list of what it would write over (last write wins by the person's choice).
- **No baseline.** Decided: with no stop's read, Finish and Put back go ahead and say so in plain words.
- **What Google had before, in full.** The owner's requirement, in his words: "We should definitely just record, in full, what Google (and any remote!) had before our change, so we can put it back." So the redesign stores Google's whole answer for each series it changes as it was read before the change (step 1's read: the master and every record, every field Google gave, not only the fields the rows here hold), beside the stop's read. Put back sends that state back; nothing of it is rebuilt from the rows here or from a prediction. The same applies to any remote the app writes to (a CalDAV server's resource as fetched, not as parsed), so a recovery never depends on what the app kept of it.

## Size of the build

- `server/src/Domain/GoogleSeries.php`: `run()` (steps 3 to 5, the stop's read stored), `finish()` and `putBack()` (their comparison halves), one new comparison and adopt function, the deletions above.
- `server/src/Domain/GoogleWriter.php`: `call()` (the day steps' exemption from If-Match).
- `server/src/Domain/GoogleSeries.php` also: one predicate for cause 1 (a record at an instant that is a day of the new form), used by the planner and by `run()`'s second check.
- `server/src/Domain/Series/Model.php`: `compute()` (cause 1's refusal), `map()` (cause 3's count), and the dead loop in `mapHeld()` removed; `Split.php`'s two `empty` checks.
- One migration (the stop's read column).
- Tests and harness as listed; `tools/google-probe/lib/FakeGoogle.php` (cause 2's clearing, cause 1's hidden day, cause 3's cancelled master) so the check can fail on the stand-in.
- Docs: `docs/google-calendar.md` ("the notice says what differs" made true; day writes unconditional inside the window), `docs/design/series-model.md` (about line 431), the probe README's error-reason sentence, and a `decisions.md` entry.

3.63 to 3.66 change the size only by cause 1's second check, which is now needed (one call in `run()`, the predicate shared); 3.66 removed the one risk the build carried (unconditional writes over records).

## The narrow path

If the redesign waits one release, the current branch can ship with three narrow fixes and the reader's small items that don't depend on it.

The three fixes:

- **Cause 1, refused.** One predicate in `GoogleSeries` (a record at an instant that is also a day of the new form), called by `Model::compute()` from the rows here (edited and deleted days) and by `run()` over the first read (every record, 3.65), each refusing with the sentence in the probe's third section. The way out it offers is proven (3.63, 3.64).
- **Cause 2, by prediction.** `afterMaster()`: when the write doesn't send `description` and the series has no notes, every kept record's notes are predicted cleared, so the edited day's step sends them again and `rebase` doesn't read it as someone else's change. The stand-in (`FakeGoogle::patchMaster`) clears them the same way first, so the test can fail.
- **Cause 3, refused.** `Model::map()` counts the new series' days less its skips and refuses `empty` at none; `Split.php`'s two `empty` checks the same.

The reader's small items:

- `dayCondition()` reads the day by a GET alone and drops the series list and its content check: a day costs a GET and a `PATCH`, 2N + 4 for N days, and the mid-run conflict the docs say we don't make goes.
- The untrue sentences: `docs/google-calendar.md` ("the notice says what differs"; "doesn't try to catch" becomes true with the item above), `docs/design/series-model.md` about line 431, the probe README's "the stand-in's error reason is always fake".
- The dead loop in `Model::mapHeld()` and the mutation `refused-default-stays-selected` removed.
- The toast's "Undo failed: " in front of the deleted-series sentence (`web/src/app/ActivityPage.js`).
- The baseline test in `google-series-back-again.test.php` made to stop with the read up, so it tests the stop's read and not the guess.
- `unproven()` narrowed: the link (3.32, 3.52, 3.58, 3.61) and a zone change at the same moments (3.17, 3.54, 3.60) keep records; a zone change that moves the instants stays. `sameAsStored`'s `UNWRITTEN` (the link) follows the same evidence.

Size: about 8 code files and 12 functions (`GoogleSeries`: the predicate, `run()`, `afterMaster()`, `unproven()`, `dayCondition()`, `UNWRITTEN`; `Model`: `compute()`, `map()`, `mapHeld()`, `REASONS`; `Split`: its two checks; `FakeGoogle::patchMaster()` for causes 1 to 3; `ActivityPage.js`; the mutation list), with tests for each cause, the baseline test, and four docs. No migration.

What it leaves that the redesign fixes: any other effect Google has that `afterMaster` doesn't predict is still kept in silence as someone else's change, mid-run and in Finish; a day costs two requests, not one (2N + 4, not N + 4), and one lost read still stops a change; Put back after a stop whose read failed still compares with the guess and can refuse falsely; the notices still name no field.

## Carried In From 1.5.6

What the 1.5.6 work put off to this model, kept from 2026-11-14 and checked against the tree of the read-back build ([its model](google-series-read-back.md)). An entry is not a wish: it says what was put off, why it could not be done in 1.5.6, what is known (with the probe case, test or commit that shows it), what is not known, and how this model's build shows it is done. Where the sections above say otherwise than an entry here (what goes, what a stop stores, which tests change), the entry is the later word: 1.5.6 built part of this model, and the sections above were written before it.

### A. What the Owner Decided That This Model Must Deliver

**A1. Store, in full, what Google held before a change, so it can be put back.**

- The owner, 2026-11-13: "We should definitely just record, in full, what Google (and any remote!) had before our change, so we can put it back."
- Why not in 1.5.6: it is a new stored form (what a pending change and an undo entry hold), which is a format number and a gate, and it changes Put back and undo together.
- Known: one list by iCalendar UID with deleted records returns the series and every record of it, and equals a GET of each (the access probe's stage `listread`, `tools/google-probe/access.php`, 2026-11-13). That list carried a write at once after 100 of 100 writes (probe group 11, 2026-11-14). So one request captures the whole state. Today Put back's plan is the before as the rows here held it (`google_pending.befores_json`, from `stateOf`): the fields Better-Cal has a column for, the notes as its cleaned copy.
- Not known: how large such a stored state gets for a long series with many edited days; what "any remote" means for CalDAV (not connected yet, issue 137).
- Done when: for every kind of change in the generator of `google-series-read-back.test.php`, Put back from the stored state leaves Google equal, field by field, to the stored state, the fields Better-Cal has no column for included.

**A2. Finish asks first, with a list, when Google really changed after a stop; goes ahead and says so when it did not.**

- The owner accepted both halves on 2026-11-13 (Finish offers to finish anyway with a list; with no stop's read, go ahead and say so).
- 1.5.6 does only the second half, for both cases. Finish first asks whether Google holds each series as the change last read it. Where it does not and the series' own write had landed, Finish sets each day as the change has it and its answer names each day it changed, with what Google held and what Finish set, under one lead sentence ([decisions.md](decisions.md), "Finish sets the plan's days where it can't tell its own change from another"). A day changed in Google Calendar between a stop and Finish is replaced there and named, not kept, and Finish does not ask.
- Why: a step's stored expectation is a digest. It does not say whether it came from a read of Google or from a prediction journaled before the request, so "someone changed it since" can't be told from "the run never read after its last write". Telling them apart needs one more stored fact, a change to a stored form.
- Known: every read a run makes after a write of its own is now the expectation ([decisions.md](decisions.md), "A step's expectation is what the run last read after it"), so the two are confused only where no read followed the last write: the network down right after the series' write, where the days at Google carry Google's own effects (the kept end, cleared notes) and the journaled prediction lacks them (the stop cases of `google-series-read-back.test.php`; `google-series-stops.test.php`). There Put back is refused as "changed at Google since", which may not be so. Where a step carries no expectation at all, Put back's refusal says there is no record (`google-put-back-fails.test.php`).
- The assertions 1.5.6 restated, to restate again for the two cases: `google-series-recovery.test.php` 4 (a note on an ordinary day, a place on an edited day, a day deleted while the change was half done, a note made after the rename landed), `google-finish-first-write.test.php` 3 (its control block, the series' write landed), `google-finish-drag.test.php` 2 (a day deleted at Google since), `google-series-recovery-grounds.test.php` 1 (in its words only); and in the edit harness's interleaving mode the A4 part of `x-finish`, with the fixed cases `a-split-stopped-then-a-day-given-an-exception-at-google-then-finished-is-replaced-and-named` and `a-split-stopped-then-a-day-given-an-exception-and-cancelled-at-google-then-finished-is-put-back-and-named`.
- Done when: the stop stores what it read, or that it read nothing; a Finish after a real change at Google shows the days and values it would replace and offers Finish anyway or Cancel; a Finish after no read goes ahead and records what it set; Put back is never refused as changed since where nothing was read; those assertions are restated for the two cases.

**A3. Undo of a change to all events, in two kinds.**

- The owner, 2026-11-13: plain fields (title, notes, place) are a forward change back, asking only where the same field changed since; times, rule, form and splits restore the recorded state, with a list and Cancel or Overwrite. Merge as a later option. Comparison by field.
- 1.5.6: this undo stays off (the hidden setting `google_series_undo`).
- Why: the second kind needs A1. A strict build was stopped by its stop rule (branch `google-156-undo`, `121c2b9d`; its second model `ae261bef`).
- Known: with the setting on, the undo left a day half an hour short because Google keeps a record's end. `google-undo-kept-end.test.php` shows it; it passes since the read-back build (the undo is a series write through `run()`, and the day is sent because it is not as planned) and stays as a regression test. A day renamed at Google during a rename and again during its undo is the change's each time (`google-series-undo.test.php`).
- Not known: what else the undo gets wrong with the setting on. Beyond those two tests no check written from the docs covers it; the read-back check covers an answer's undo and no other.
- Done when: the undo model has its own generator and an oracle derived from the docs (not probes), covering undo, redo and undo of redo for every kind in the read-back check, on real Google through the replay tool.

**A4. On a conflict: say what changed with values; Cancel, Overwrite, and later Merge.**

- 1.5.6 builds the single-event slice (Cancel and Overwrite). A change to all events does not ask: it is last write wins while it is being sent, and what Google holds at the end against what was asked is said with both values (`GoogleSeries::outcome`).
- Carried: Merge (issue 268), and the same choice for a change to all events between changes (before the first write), per field. The refusal there still does not name what changed ("This event changed at Google since you last saw it. Refresh and try again.").
- Known: the shared three-way function over a normalised field map exists as a model only (branch `google-156-conflict-choice`, `bf67bef2`). `GoogleSeries::outcome` compares three states by field group for the record, and `rebase` does for Finish; neither is that function.

**A5. Recovery after a stop must follow from stored state alone.** (Added 2026-11-14.)

- The owner's requirement: however many times a change is stopped, finished, put back or fails again, the end result must be evident from the design. Finish ends at what the change asked for, Put back at what was there before, a refusal changes nothing and says something true.
- Why 1.5.6 does not have it: recovery there is the older machinery, a journal of steps, each with an expectation that is sometimes a prediction and sometimes a read, plus a fall-back. Three faults in it surfaced on 2026-11-14 while the forward path was rebuilt. A failed Put back wrote the state before the change over the journaled plan. A failed Finish wrote its own reading of the plan over it. A step with no journaled expectation left a second Put back refused, in words that were not true, where the first had brought a deleted series back and stopped. Each was closed by removing a condition, not by a new design: the plan is written once ([decisions.md](decisions.md), "The journaled plan is written once"), and a step's expectation is what the run last read after it.
- Known: all three are in `google-put-back-fails.test.php` (a Put back that fails part way after a rename, after a time change and after the whole event is deleted, each then finished and, apart, put back again; a Finish that fails part way after the place was set at Google, then finished again and, apart, put back; the same delete with nothing read after Put back's series write; a row with its expectations taken out). The interleaving mode already generates failed writes, Finish and Put back in sequence, and did not surface these.
- What 1.5.6's recovery consults, each stored thing by name, besides the plan (`google_pending.targets_json`) and the before (`befores_json`). The last read is not stored as content at all: only its digest is, on a step.
  1. `steps_json`, each step's `series`, `kind`, `id`, `key`, `state` (sent or done), `etag`, `back` (the change's side or Put back's) and `expect` with `extras`. Read to decide which series anything was sent for (Put back's and Finish's first loop), which steps of the same side are done and are skipped (`stepDone`), which step is the last and whether it was answered (`asLeft`), the etag a removal or an event of its own left, and the expectation itself, which is a read's digest or a prediction's and does not say which.
  2. The prediction behind an expectation journaled before a request: `afterMaster` (with `unproven`, `addsWeekdays`, `googleOwn`) for the series' write, `heldAfter` for a day's write, the series as sent for an insert. Computed from the rows here and the first read, then stored in `expect`.
  3. The fall-back for a step with no expectation: Google compared by content with `befores_json`, and for a series the change inserted the etag journaled with its target.
  4. `local_json`: the rows here as they were before the change with what hangs off them (tags, people, reminders, trip links, own lists). Read by Finish to put rows back before its part here and by Put back to bring rows back (`rewind`, `reattach`, `bringBack`, `placesBack`).
  5. `plan_json`: the change's part here (the planner's writes and the request's fields) and, for an undo or a redo, the entry it completes. Read by Finish (`commitLocal`) and by both for an undo (`GoogleUndo::entryToFinish`).
  6. `made_json`: the rows the change's own stores made here. Read by Finish (`rewind`) and by Put back (`dropMade`).
  7. The rows here at the moment of the recovery. Each recovery first stores what Google holds (`refresh`), then reads those rows: for the ids of the plan's days (`withStoredIds`), for what the series' write must send (`masterBody` against the stored series), for the prediction of item 2, and for the condition before its first write.
  8. `status` and `error`, and whether a run holds the row (a row still marked running whose run holds no lock is half done).
- Not known: whether other holes of the same kind remain in 1.5.6's recovery. There is no evidence either way beyond the independent read of the read-back build.
- Done when: this model derives the outcome of Finish and of Put back from three stored things only (the plan, what Google held before, the last read), with no step-by-step expectations and no fall-back, so that a sequence of recoveries has nothing to accumulate; items 1 to 3 above are gone and its reader confirms no fourth stored thing decides what is sent to Google (items 4 to 6 are the change's part here, not a reading of Google, and are named in the model if they stay). No new test tool is asked for: the interleaving mode, with a failure allowed inside Finish and Put back, is enough if the design holds.

### B. Faults and Limits 1.5.6 Ships With

**B1. A day's notes sent back to Google are Better-Cal's cleaned copy, not what Google held** (F7 of the second independent read, 2026-11-14): a non-breaking space becomes a space, links gain attributes, text in angle brackets is lost.

- Why: only the cleaned copy is stored. The read-back rule sends a day whole whenever it is not as planned, so more days are sent than before, each with the cleaned notes. Fixed by A1 (what Google held, raw).
- Known: the 1.5.6 changelog names the limit.
- Done when: a day's notes with markup survive a change to all events byte for byte at Google.

**B2. A change of access between two requests of one change** can store an older answer after a newer one (reasoned by the second read, no probe), and Google's answers are mixed for seconds after access is restored (the access probe's second round, 2026-11-14: a write taken about a second after, an insert still refused and one list not found seconds later).

- 1.5.6: a known window, corrected by the next poll or attempt. Not touched by the read-back build.
- Carried only if this model touches how the access level is stored; otherwise it stays a documented window.

**B3. An answer to an invitation has no half-done state.** The answer and its undo go through the read-back rule (`GoogleSeries::writeBesideDays`: the series' write, a read back, each day not as it was sent once, a last read). If the read after the write can't be made, the answer stands, nothing is compared, and the next poll stores what Google holds; a day's write that fails after it is not journaled either.

- Known: any write to a series can clear a day's own notes, even one private property with no rule (probe 3.68, 2026-11-14); `google-answer-notes.test.php` holds the answer and its undo to leaving the day's notes. Not known: what a write of the guest list alone does at Google (never probed; the probes use no guests).
- Done when: either the answer gets the same stop handling as a change, or a live guest case shows it is not needed.

**B4. Predictions of what Google does to kept days keep one reader each.** After the read-back build nothing reads them while a change is being sent, and nothing is sent, kept or said on them.

- `afterMaster`, every clause (identity through `named`; a status sent making a cancelled record live; an EXDATE line carried; proof through `unproven`; a survivor following a changed start or length; a title, notes, place, status or link sent written into every survivor; the notes cleared on a series with none): read by the expectation a series step journals before its request (`run()`), compared with only where the run never read after that write.
- `unproven` and `addsWeekdays`: read by `afterMaster` alone. `googleOwn`: read by `afterMaster` and by the insert step's expectation. `heldAfter`: read by a day step's expectation (`sendDays`).
- `rebase` holds no prediction any more (its two calls of `afterMaster` and its forced mode went). It stays, with `threeWay`, `dayIn`, `ownPart`, `planOver`, `rekeyed`, `notKept`, `sinceWords`, `listed` and `were`, read by Finish alone where Google does not hold a series as the change last read it.
- Known wrong and left so: `afterMaster` does not leave a kept record's end where it was when the start moves (3.17, 3.67). Where that prediction is the expectation, Put back is refused (A2).
- Done when: A2's stored read exists and the first two items are deleted; `rebase` goes with A5.

**B5. No read is held to showing the write before it, beyond the series' own.** The read back after the series' write is asked again, up to four times, until it shows the write's etag (`readBack`, as before 1.5.6). The last read after day writes is not held to showing the last of them, so a list that lagged would be stored a write behind until the next poll.

- Known: a list by iCalendar UID agreed with the write at once after 100 of 100 writes, 91 to 346 ms after the write's answer (probe group 11, 2026-11-14: inserts, repeated conditional writes of one event and of one day's record, deletes). So 1.5.6 builds nothing for a lag. One stop is left: a read back that still shows the series as it was before the write stops the change half done.
- Not known: whether a lag exists under load, or for a series with more than one page of records.
- Done when: this model says whether the repeated read back stays, with a measurement, or goes.

**B6. The words "Google kept something else:" still open the part of an Activity line that says what Google holds.** The lines under them now say what Google Calendar holds and what was asked, and never a cause; the opening words stay because stored entries are cut on them (`GoogleUndo::KEPT`).

- Done when: the words change with a format number for the entries cut on them, or the cut no longer depends on words.

### C. Measurements This Model Must Make Before It Claims Anything

**C1. Speed.** Series edits became 2 to 30 times slower in 1.5 and the owner wants them faster. 1.5.6 holds a change to all events at N + 4 requests for N days not as planned after the series' write, and 3 when none is (`google-series-request-count.test.php`; the table in the read-back model). This model states requests per kind of change before and after, measured alone, and compares with 1.3 and 1.4.0 as owed since 1.5.1.

**C2. Real Google, not the stand-in.** Each rule this model relies on cites a live case. The read-back rule itself needs no list of Google's effects, and its check gives the stand-in two effects nobody has seen (`FakeGoogle::$masterEffect`); what it relies on is that a day's write with no condition is taken in every state (3.66) and that the list carries a write at once (group 11). Unproven today, to probe first: a guest-list write over a day with notes; guests answering (needs a second account as a guest); a day's write to a series someone deleted inside the window; deleting the last remaining day of a series (issue 272); the extra occurrence that appears a day early after switching back from all-day (issue 266); a series made non-repeating at Google and what its iCalendar UID then lists.

**C3. Replay.** Five live replay rounds are recorded ([replay of 2026-11-13](research/google-series/replay-2026-11-13.md)); the sixth follows the read-back build, whose traces differ (a day at its own time is sent where it was not, and a change that sends no day makes one read fewer). This model re-records and replays before its independent read, and a scenario that differs is classified, never adjusted to agree.

### D. Related, With Their Own Issues

Not this model, listed so they are not mistaken for it.

- Issue 266: notice when a switch made in Google Calendar hides days, and say so.
- Issue 267: check Better-Cal's days against Google's own list after an event changes; weigh showing Google's list.
- Issue 273: the five older Review kinds onto the one dismiss rule.
- Issues 269, 270, 271 and 272: a zero-length repeating event; a split stopped by a lost connection; a switch left half done by 1.5.5; deleting the last remaining day.

The whole of this section is tracked as issue 274.
