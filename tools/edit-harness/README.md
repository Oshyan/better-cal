# Series Edit Harness

Builds thousands of random repeating series with skipped and edited days, applies random edits to them through the real domain code (`Events`, `Undo`) against a migrated database, and checks invariants against its own reading of the rules rather than hand-written expectations. Run it after changing anything in `Events` (patchAll, patchFollowing, deleteThis, deleteFollowing, setAttendance, seriesKeyShift, movedOverride, movedUntil, alignedStart, rebaseSeriesEdit), `Recurrence` (shiftByday, shiftBymonthday, setUntil) or `Undo`.

```bash
BC_TEST_DB=127.0.0.1:3306 ./tools/edit-harness/run.sh [--cases N] [--seed S] [--family F] [--edit E] [--case N] [--no-shrink] [--quiet]
```

It needs PHP with `server/vendor` installed and a MariaDB or MySQL server it may create databases on (`BC_TEST_DB` as host:port, `BC_TEST_DB_USER` and `BC_TEST_DB_PASS`, root with no password by default), exactly as the database suite does. Each run makes a database of its own and drops it. The default 3,000 cases take about four minutes; a seed names a run, so `--seed 1 --case 116` replays one case. It writes `findings.json` here (ignored by git): every failing check with the case that produced it, and one shrunk reproduction per failure signature. It runs from the command line only.

A seeded slice of 200 cases runs with the database suite on every push (`server/tests/db/edits.test.php`, about fifteen seconds) and fails on any failure signature outside the known root causes below.

## What It Does

1. `Generator::series` draws a series the way the app writes them: every rule family (daily with an interval; weekly plain, one day, a list of days, every other week; monthly plain, one day of the month including the 28th to 31st, the last or second-last day, a list of days, an nth weekday as `2TU` and as `TU;BYSETPOS=2`; yearly plain, a month and day, a month and nth weekday, a list of months), unbounded or with a COUNT or an UNTIL (the editor's 23:59:59 form or the last start, a date for an all-day series), timed in one of five zones (two with northern DST, one southern, one half-hour, UTC) or all-day, starting on or off its own pattern, around month ends and the turn of the year.
2. `Driver` creates it through `Events::create` (with a tag and a person), reads the days it has, then `Generator::plan` places up to three edited days (a new title, its own time the same day, another day, another zone) and up to two skipped days before, at and after the day the edit will act on, including the first and the last day. They are made through `Events::patch` with scope `this` and `Events::deleteEvent`.
3. The edit, sent as the client sends it: a whole-series drag of -3 to +45 days with or without a time change, a time-only change, an all-day toggle both ways, a zone change, an editor save (the same rule sent back with the drag), a rule change; "this and following" as a drag from an ordinary, an edited, the first or the last day, a rename, an editor save; a delete of the following days or of one day; attendance from a day on. Then Undo, the same edit again, and for some cases a second edit on top of it undone twice.
4. The invariants below are checked on the rows and on `Recurrence::sabreExpand` of the stored rules (the expander the app uses, read with no skipped days applied, so it says which days a rule produces and nothing about the app's own mapping).
5. Every failure is recorded with the case; the first of each signature (invariant, family, edit) is shrunk by dropping the second edit, each exception, the time change and the drag distance as far as the same invariant still fails.

## The Invariants

The oracle decides what a family does with a drag. Daily and weekly rules move their days by the drag. A monthly or yearly rule that names one day (or none, the start's) takes the day the dragged occurrence landed on, in every period, and the series ends in the same month or year as before, moved with the start; an exception keyed on the nth occurrence of its month (year) is expected on the nth of the month the series moved to. A list of days, a day counted from the end or a list of months keeps its days and takes the new time, and the start snaps forward to the next listed day.

- **0** The stored start is the first raw occurrence and (every family but the yearly nth weekday, which the app leaves) a day of its rule.
- **1** Rule follow: the days after the edit are the days the family is expected to have; COUNT unchanged; an UNTIL stays an UNTIL; every occurrence at the time and zone sent, with the all-day flag sent. `1-lead` counts a start that snapped forward past earlier days.
- **2** Exceptions attached: every edited day's key and every skipped day is a raw day of its rule, on the series and on a split-off one; no two edited rows for one day. `-past-end` marks a key beyond a bounded series' last day.
- **3** Ordinal preserved: each skipped and edited day is where the family's mapping puts it.
- **4** Deviation preserved: an edited day keeps its title, place, notes and answer; one edited in place is still at its occurrence, in the series' zone, its length kept; one moved to its own time keeps that time, its zone and its distance in days from its occurrence.
- **5** Following split: the old series keeps exactly the days before the split, its earlier exceptions untouched and its own fields; a new series holds the days from the split on as the family expects, what is left of COUNT, the skipped and edited days from there on at their place and with its uid and calendar; the day acted on is where it was dragged to and takes the edit's fields; from the first day there is no split.
- **6** Undo identity: after Undo every row of events, event_tags, event_people and event_links this case made is byte-identical to before the edit (updated timestamps aside); the edit applied again gives the same rows under new ids; a second edit undone twice gives the state after the first, then before either.
- **7** Delete this and following: the day is skipped (its edited row removed), or the series ends before the split day and the later edited rows go; nothing else moves.
- **8** Google parity: the rule and start `googlePatchAll` would send (its own helpers, `rebaseSeriesEdit` and `columnPatch`, on the same input) expand to the days the local path stored.

## What "Clean" Means

The summary ends with `Clean: no signature outside the known root causes.` when every failure falls under a root cause listed here (`Known::ROOT_CAUSES` in `lib.php`); each is a defect or an accepted limitation already reported, with a reproduction in the findings report. A new signature is a regression or a defect nobody has looked at: `findings.json` has its shrunk reproduction. Once a root cause is fixed, delete its line from `Known::ROOT_CAUSES` and from this list so the harness and the database suite guard the fix.

## Known Root Causes

Found by the first run (3,000 cases, seed 1, 2026-11-01; the full report is in the project's review notes). Each line is the key in `Known::ROOT_CAUSES`.

- **key-collision**: a whole-series drag by the distance between two edited days (a weekly series with two consecutive edited days dragged a week) fails with a duplicate-key error, since the edited rows are re-keyed one at a time; Undo restores rows one at a time and can fail the same way. Defect.
- **until-first-phantom**: the expander (sabre/vobject 4.6.1) yields a monthly rule's UNTIL as an occurrence when it falls on the first of a month at the series' time (a date for an all-day series), which a split of an all-day monthly series at an occurrence on the 2nd writes, and a moved UNTIL can land on. Defect (a library quirk the app has to guard).
- **stale-skips**: after a "this and following" split or delete, the old series keeps the skipped days from the split on, now past its end. Harmless to expansion; a defect in what the row says.
- **keep-snap**: a rule that cannot follow a drag (a list of days, the last day of the month, a list of months) keeps its days but moves its start by the drag and snaps it forward, so a forward drag loses the earlier days and the exceptions on them; from a day on, the dragged day itself. The suite asserts the snap. Reported as a defect to decide on.
- **plain-rule**: a bare `FREQ=MONTHLY` or `FREQ=YEARLY` (what the editor writes for "monthly on this day") repeats on the start's day, so a drag whose start lands in a month of another length ends on another day than the one dragged to. Defect.
- **biweekly-list**: an every-other-week list of days dragged across the week start (Sunday to Monday, or back) changes which weeks the series falls in, and its exceptions come loose. Defect, niche.
- **editor-rule**: a save from the editor sends the series' rule back; with a drag the app keeps that rule's UNTIL and COUNT as given, so the last day is lost, a following save keeps the whole COUNT on the new series, and a following drag with the rule takes the "new days" branch and leaves the exceptions behind. Earlier reviews listed this; left as is by decision until now.
- **yearly-nth-start**: a yearly nth-weekday series dragged by more than its week keeps a start off its pattern (the app leaves such starts), which is a stray first occurrence, counts toward COUNT and pushes the mapped exceptions past the end. Defect.
- **google-parity**: the Google path neither moves an UNTIL with the days nor aligns the start, so a bounded series loses its last day there and a rule that could not follow gets a stray start. Defect, Google-backed series only.
- **rule-change-orphans**: new days chosen in the editor leave the old skipped and edited days keyed on days the new rule does not produce; an edited day then shows beside the series. Accepted for now (the edits are not lost), to document.
- **count-span-or-month-multiple**: two findings share these signatures. A monthly series on a sparse day (the 29th to 31st) dragged to a day every month has, with a COUNT, spans fewer months, and the exceptions near its end map past it; and a monthly series dragged a whole number of months (landing on the same day of the month) moves its start but leaves its exceptions and its UNTIL where they were. Both defects.
- **until-type**: an all-day series made timed keeps a date UNTIL and loses its last day; a timed one made all-day keeps a timed UNTIL. Defect.

## Known, Accepted Differences

- The harness sends the instance key as `instanceStart`. The client sends an occurrence's own start, which for a day moved to its own time is not the key; the server refuses that (noted in earlier reviews), and the harness does not exercise it.
- A series stored on a day off its pattern is only generated for the yearly nth weekday (create aligns every other family). Rows stored off their pattern by older code are not generated.
- Two edited days landing on one day by a drag (an edited day moved to the day of another) is allowed by the app and by the harness.
- The oracle compares days, not rule strings: a rule rewritten in another equivalent form passes.
