# Series Edit Harness

Builds thousands of random repeating series with skipped and edited days, applies random edits to them through the real domain code (`Events`, `Undo`) against a migrated database, and checks invariants against its own reading of the rules rather than hand-written expectations. Run it after changing anything in the series model (`Domain/Series`, `SeriesPlans`), `Events` (patchFollowing, deleteThis, deleteFollowing, setAttendance and their helpers), `Recurrence` or `Undo`.

```bash
BC_TEST_DB=127.0.0.1:3306 ./tools/edit-harness/run.sh [--cases N] [--seed S] [--family F] [--edit E] [--case N] [--no-shrink] [--quiet]
```

It needs PHP with `server/vendor` installed and a MariaDB or MySQL server it may create databases on (`BC_TEST_DB` as host:port, `BC_TEST_DB_USER` and `BC_TEST_DB_PASS`, root with no password by default), exactly as the database suite does. Each run makes a database of its own and drops it. The default 3,000 cases take about four minutes; a seed names a run, so `--seed 1 --case 116` replays one case. It writes `findings.json` here (ignored by git): every failing check with the case that produced it, and one shrunk reproduction per failure signature. It runs from the command line only.

A seeded slice of 200 cases runs with the database suite on every push (`server/tests/db/edits.test.php`, about fifteen seconds) and fails on any failure signature outside the known root causes below. `HARNESS_SLOW=seconds` names each case that took longer.

## What It Does

1. `Generator::series` draws a series the way the app writes them: every rule family (daily with an interval; weekly plain, one day, a list of days, every other week; monthly plain, one day of the month including the 28th to 31st, the last or second-last day, a list of days, an nth weekday as `2TU` and as `TU;BYSETPOS=2`; yearly plain, a month and day, a month and nth weekday, a list of months), unbounded or with a COUNT or an UNTIL (the editor's 23:59:59 form or the last start, a date for an all-day series), timed in one of five zones (two with northern DST, one southern, one half-hour, UTC) or all-day, starting on or off its own pattern, around month ends and the turn of the year.
2. `Driver` creates it through `Events::create` (with a tag and a person), reads the days it has, then `Generator::plan` places up to three edited days (a new title, its own time the same day, another day, another zone) and up to two skipped days before, at and after the day the edit will act on, including the first and the last day. They are made through `Events::patch` with scope `this` and `Events::deleteEvent`.
3. The edit, sent as the client sends it: a whole-series drag of -3 to +45 days with or without a time change, a time-only change, an all-day toggle both ways, a zone change, an editor save (the same rule sent back with the drag), a rule change; "this and following" as a drag from an ordinary, an edited, the first or the last day, a rename, an editor save; a delete of the following days or of one day; attendance from a day on. Then Undo, the same edit again, and for some cases a second edit on top of it undone twice.
4. The invariants below are checked on the rows and on `Recurrence::sabreExpand` of the stored rules (the expander the app uses, read with no skipped days applied, so it says which days a rule produces and nothing about the app's own mapping). A bounded series is read through its COUNT or UNTIL, so its list is the whole series; an unbounded one is read over a window from its start, a skipped or edited day beyond that window is looked for on its own day, and two unbounded series compare over the window both were read over.
5. Every failure is recorded with the case; the first of each signature (invariant, family, edit) is shrunk by dropping the second edit, each exception, the time change and the drag distance as far as the same invariant still fails.

## The Invariants

The oracle decides what a family does with a drag. Daily and weekly rules move their days by the drag. A monthly or yearly rule that names one day (or none, the start's) takes the day the dragged occurrence landed on, in every period, and the series ends in the same month or year as before, moved with the start; an exception keyed on the nth occurrence of its month (year) is expected on the nth of the month the series moved to. A list of days, a day counted from the end or a list of months keeps its days and takes the new time, and the start snaps forward to the next listed day.

- **0** The stored start is the first raw occurrence and a day of its rule (create() aligns every family, series-model.md).
- **1** For a whole-series edit, the commit wrote the plan it previewed (`P-commit`, `P-days`: the stored rule, start, skipped days and edited keys are the plan's), at the time, zone and all-day flag sent; the dragged day shows where it was dropped, in the series or as an event of its own (`1-acted`); an UNTIL is the last occurrence (`1-until`). Each case picks one option per group of the plan, the preselected one in half the cases and any enabled one otherwise; a refused change writes nothing (`R-refused`). "This and following" keeps the 1.4.6 oracle until 1.5.1: `1-lead` counts a start that snapped forward past earlier days.
- **2** Exceptions attached: every edited day's key and every skipped day is a raw day of its rule, on the series and on a split-off one; no two edited rows for one day. `-past-end` marks a key beyond a bounded series' last day.
- **3** Images by member, read by the harness itself where they are plain: for daily and weekly rules each skipped and edited day still in the series moved by the drag (only the dragged weekday's under "move every Monday"); for a rule with one day a period, the period's occurrence k periods on. Lists of month days and of months are checked through the plan (`P-days`) and attachment (2).
- **4** Own fields: an edited day keeps the fields it owns (unless the case chose "Give it the new title too"); on its own date it stays there (unless "Move" was chosen; the dragged day itself moves by the drag), at its own time it keeps it; a day that inherits its date and time is at its occurrence.
- **5** Following split: the old series keeps exactly the days before the split, its earlier exceptions untouched and its own fields; a new series holds the days from the split on as the family expects, what is left of COUNT, the skipped and edited days from there on at their place and with its uid and calendar; the day acted on is where it was dragged to and takes the edit's fields; from the first day there is no split.
- **6** Undo identity: after Undo every row of events, event_tags, event_people and event_links this case made is byte-identical to before the edit (updated timestamps aside); the edit applied again gives the same rows under new ids; a second edit undone twice gives the state after the first, then before either.
- **7** Delete this and following: the day is skipped (its edited row removed), or the series ends before the split day and the later edited rows go; nothing else moves.
- **8** Google until 1.5.2: the same edit on a Google calendar is offered only when it keeps the rule text (never a list's member reading), and then with the local plan's rule and start and no outcome for edited days; anything else is refused.

## What "Clean" Means

The summary ends with `Clean: no signature outside the known root causes.` when every failure falls under a root cause listed here (`Known::ROOT_CAUSES` in `lib.php`); each is a defect or an accepted limitation already reported, with a reproduction in the findings report. A new signature is a regression or a defect nobody has looked at: `findings.json` has its shrunk reproduction. Once a root cause is fixed, delete its line from `Known::ROOT_CAUSES` and from this list so the harness and the database suite guard the fix.

## Known Root Causes

The first run (3,000 cases, seed 1, 2026-11-01) found twelve causes; the series model (1.5.0) closed every one it covers for whole-series edits (plain-rule, keep-snap, biweekly-list, yearly-nth-start, google-parity as invariant 8 now defines it, rule-change-orphans, count-span-or-month-multiple, until-type, and editor-rule for whole-series saves). Each line left is a key in `Known::ROOT_CAUSES`:

- **until-first-phantom**: the expander (sabre/vobject 4.6.1) yields a monthly rule's UNTIL as an occurrence when it falls on the first of a month at the series' time (a date for an all-day series), which a split of an all-day monthly series at an occurrence on the 2nd writes. Defect (a library quirk the app has to guard); 1.5.1.
- **stale-skips**: after a "this and following" split or delete, the old series keeps the skipped days from the split on, now past its end. Harmless to expansion; 1.5.1.
- **following-1.5.1**: "this and following" (drag, editor save, rename, answer, delete) stays on its 1.4.6 code until 1.5.1 moves it onto the model: a split's rule that can't follow keeps snapping, a following save takes the editor's rule, and so on. The signatures are those of the old causes, limited to following edits.

## Known, Accepted Differences

- The harness sends the instance key as `instanceStart`. The client sends an occurrence's own start, which for a day moved to its own time is not the key; the server refuses that (noted in earlier reviews), and the harness does not exercise it.
- A series stored on a day off its pattern is only generated for the yearly nth weekday (create aligns every other family). Rows stored off their pattern by older code are not generated.
- Two edited days landing on one day by a drag (an edited day moved to the day of another) is allowed by the app and by the harness.
- The oracle compares days, not rule strings: a rule rewritten in another equivalent form passes.
