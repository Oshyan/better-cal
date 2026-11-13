# A Change to All Events Under "Last Write Wins"

A written model, not yet built. It replaces the way `GoogleSeries::run()` reads Google during a change to all events of a repeating event on a Google calendar, and the way Finish and Put back compare. The rule it follows is the owner's: while one of our own changes is being sent, the last write wins; between changes, Google is read first and a change made there is reported, and a change to a field we are not changing is no conflict. Evidence: [probe 2026-11-13](research/google-series/probe-2026-11-13.md) (both runs), [replay round 4](research/google-series/replay-2026-11-13.md), the outside read of `5f60dd8b..14a17871` and its probes (`server/tests/db/probe-fix-*.test.php` on `google-156-read-fixes`).

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

| The day at Google | Proven with no If-Match | Proven only with its own etag | Case to settle |
|---|---|---|---|
| Ordinary, no record: its fields | 2.1 to 2.3 (timed and all-day), 2.7 (the app's whole body), 2.8 and 2.9 (east of UTC), the setup of every group 3 and 10 case | | |
| Ordinary, no record: cancelled | 2.5 (`PATCH` status), 2.4 (`DELETE`) | | |
| A live record (an edited day) written again | | 3.36 and 3.40 change 2, 10.2, 10.3 | 3.66 |
| A revived record (dropped, then a day again) | | 3.36, 3.37, 10.2, 10.3 | 3.66 |
| A cancelled record made a live edited day | | none at instance level (10.4 is the master's) | 3.66 |
| A record put back to the series' values | | 10.2 (8 sent again) | 3.66 |
| A live record cancelled | | 3.36 (occurrence 3 after revival) | 3.66 |
| A day hidden by a switch at a meeting key | 404 (3.48) | 412 (3.48) | none needed: named |
| A day past the 730-occurrence cap | 404 (contract) | | |

Unproven until 3.66 answers: every state with a record. Unproven and not cased: a day of a series someone deleted inside the window (10.5 shows its instances read 200 `cancelled`; whether a `PATCH` with no If-Match revives one is unknown; the final read shows the master cancelled and the change names it).

`GoogleWriter::call` refuses a `PATCH` or `DELETE` with no If-Match ("none goes without the etag of a read"). The day steps of `run()` need an explicit exemption there, named for what it is; every other write stays conditional.

## Cause 1 under this model

The planner's refusal (a switch between timed and all-day while an edited or deleted day sits at an instant that is also a day of the new form) stays the one guard, from the rows here, before anything is sent. A second check in `run()` over the first read's records is needed only if probe 3.65 shows that a record at the series' values (which the rows here don't show: an ordinary day to the app) is hidden too; it would cost no request, using step 1's read. If 3.65 shows such a day survives, the second check is not needed: a day hidden by some other path answers 404 to its unconditional write and is named, not passed over in silence.

The refusal in `Model::compute()` does not make the mutation `refused-default-stays-selected` killable: `compute()`'s refusals reach the option-disabling loop in `run()` (each option's plan computed and disabled with the reason), not the loop in `mapHeld()`, which only a refused plan from `map()` reaches. Placed in `map()`, the refusal would let `mapHeld()` pick another bound or edge to avoid it, silently cutting a person's series; it belongs in `compute()`. So the `mapHeld()` loop stays unreachable and should go with its mutation.

## Tests whose expectation changes

- `google-series.test.php`, "Another client renames that day between the series' master write and its read-back": the day is put back to the series' new title at Google (last write wins); its tag stays here. Today the remote title is kept.
- `google-series.test.php`, "Put back puts nothing back when a series changed at Google since": the stranger's title is not something Put back sends (it sends the time), so Put back goes ahead, keeps the title and names it. Today it is refused.
- `google-series-recovery.test.php`, F1 and the "another part of the series changed elsewhere" and "only the zone changed elsewhere" cases: each goes ahead where what changed since is not something Put back sends, keeping and naming it; refused only where both touch the same day or master group. F2, F3, F5 and F6 stand.
- `google-series-back-again.test.php`: the two "inside the window" cases go ahead as now, on the stop's read instead of the guess; "an ordinary day renamed at Google" after a clock change is still refused (its key goes away with Put back's master write), now naming the day.
- The reader's `probe-fix-stops`: Put back with the network down through the stop goes ahead with the no-baseline sentence; no false refusals.
- `google-etag.test.php`, "a write is conditional on a read made just before it": the master's write is; a day's is not.
- The edit harness: `x-readback` ("kept or named") is replaced by the check below; its oracle probe "a day renamed at Google during a series write is put back to the series without a word" becomes the correct outcome, and the injected damage becomes "kept"; the Interleave mode's exclusion of a raced day from the undo comparison goes.
- The unit tests of `afterMaster` and `rebase` go with them.

## The check

**After a change to all events that the app reports complete, the stand-in holds on every day of each series the change's target names exactly what the target says (its own fields for an edited day, cancelled for a deleted day, no record or a record at the series' values for an ordinary day) and the master the target's master, except days the change names as refused by Google, whatever the stand-in or a stranger did between the master write and the final read.**

The harness computes it from its own records: the target from what was asked and the rows before (its spec oracle, nothing in `server/src`), against the stand-in's store after the run. It must catch, each injected: the stand-in clearing a kept day's notes on a master write that leaves them out (cause 2) with the app not sending them again; a stranger's rename of a day between the master write and the read-back kept; a revived record left (a deleted day live again, an edited day at the series' values); any one day step dropped (a mutation skipping the k-th write, or one kind); a 404 not named.

## Owner choices this leaves

- **Finish anyway.** When a recovery and a change made since in Google Calendar touch the same day or field, should Finish offer to write the plan over it (last write wins by the person's choice), or only name it and leave the change in Review? The second can leave a change half done for good.
- **No baseline.** The model goes ahead with a sentence. The alternative, refusing both Finish and Put back, leaves no way out after a network loss.

## Size of the build

- `server/src/Domain/GoogleSeries.php`: `run()` (steps 3 to 5, the stop's read stored), `finish()` and `putBack()` (their comparison halves), one new comparison and adopt function, the deletions above.
- `server/src/Domain/GoogleWriter.php`: `call()` (the day steps' exemption from If-Match).
- `server/src/Domain/Series/Model.php`: `compute()` (cause 1's refusal), `map()` (cause 3's count), and the dead loop in `mapHeld()` removed; `Split.php`'s two `empty` checks.
- One migration (the stop's read column).
- Tests and harness as listed; `tools/google-probe/lib/FakeGoogle.php` (cause 2's clearing, cause 1's hidden day, cause 3's cancelled master) so the check can fail on the stand-in.
- Docs: `docs/google-calendar.md` ("the notice says what differs" made true; day writes unconditional inside the window), `docs/design/series-model.md` (about line 431), the probe README's error-reason sentence, and a `decisions.md` entry.
