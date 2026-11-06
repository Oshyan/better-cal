# 1.5.1 Against 1.3.0 and 1.4.0: What the Engine Work Was Worth

The roadmap promised an honest comparison after 1.5.1 shipped ([#203](https://github.com/Oshyan/better-cal/issues/203)): what was broken in 1.3 and 1.4.0, in which situations and actions, that now works, measured rather than argued, with what the dialog alone gained reported apart from what the engine gained, and what the work cost. This is that report, written 2026-11-06. Every event, series, name and address in the measurements is invented; the tools and their data live in `tools/compare/` and `tools/edit-harness/`.

The short version: the engine's column is not thin. Across every instrument, most of what 1.5 fixed is where occurrences land, which edited days survive, and what Undo puts back, not the choices the dialog offers. The dialog's own gain is real but small. The cost was high: 1.5.0 took about three times the agent-hours per changelog line of any other release, and the engine is heavier on large series edits. What it bought is a product where 1.5.1 passes 18,306 generated cases with no failures, where 1.3 and 1.4.0 fail 15,834 of them.

## What Was Measured

Five instruments, each run against a checkout of v1.3.0, v1.4.0 and v1.5.1 through that version's own code:

1. **The series harness** (`tools/edit-harness`), 51 fixed cases and sequences plus six seeds of 3,000 generated cases, driven through each old version's API by a thin adapter. Checks that need the plan API are counted "no plan", never failures. Every whole-series or split edit's outcome is compared against every option combination 1.5.1 would offer, so a result that matches a choice 1.5.1 offers but did not preselect counts as a dialog gain, and one that matches no option counts as an engine failure.
2. **Fifty-five scenarios** (`tools/compare/scenarios`): the same invented edits committed on each version, their rows and occurrences compared, Undo checked within each version, and each difference classified with a citation to the old version's own contract or to the decision that changed.
3. **Twelve closed issues replayed** (`tools/compare/issues`): the series issues the 1.4.x, 1.5.0 and 1.5.1 releases closed, reproduced on all three versions.
4. **Resource use** (`tools/compare/perf`): memory, process size and query and row counts per request on identical seeded data, plus wall time taken alone.
5. **Development cost**: rounds, findings, gate runs, test counts and agent-hours per release, from the repository, the review reports and the session transcripts.

1.3.0 and 1.4.0 behaved identically in every instrument. The series code between the two tags differs only in request-authority plumbing, so the rest of this report says "1.4" for both.

## The Two Columns

The question was whether the engine (occurrences land where the rule says, edited days stay on their occurrence, Undo puts back what was there) or the dialog (the plan shown first, a choice offered where there is one) is where the gain lies.

| Instrument | Engine gain | Dialog-only gain | Same on both |
|---|---|---|---|
| Harness, whole-series and split edits judged against every option (13,436) | 11,923 match no option 1.5.1 offers | 233 match an option 1.5.1 offers but did not preselect | 1,280 match the preselected or only outcome |
| Harness, series created on a day of their rule (6,752) | 5,387 | 134 | 1,231 |
| Scenarios (55) | 16 wrong by the old contract, 9 where the decision changed | 6 | 24 |
| Issues replayed (12) | 10 (1.4.x 1, 1.5.0 3, 1.5.1 6) | 2 (1.4.x 1, 1.5.1 1) | none regressed |

The dialog-only column in the harness is the 233 edits where 1.4 silently took a reading 1.5.1 now offers as a choice: keeping the instant across a zone change (131), shifting a whole weekly list when one member is dragged (79), keeping keys under a new rule (13), and a handful of bound, edge and outside readings. None of those 233 fails any outcome check. They are the cases where the old version was defensible and the new version asks.

Everything else in the engine column fails outright on 1.4: no reading the design offers produces its rows.

## What Was Broken, by Situation and Action

The harness's 1,884 failing signatures on 1.4 group into these causes. Counts are failing cases out of 18,306; the second number, where given, is the subset whose series started on a day of its rule, since many generated series start off-rule and 1.4 aligned only weekly starts.

**Drags and new rules put days in the wrong places** (c-days 6,888 / 1,825). A monthly or yearly drag moved the start and left the rule's day, so only the first occurrence moved. A rule sent back unchanged in the editor's own form (an INTERVAL=1 added, a BYDAY reordered) was read as a new rule and restarted the series. A new rule did not move the start to its first day. All three are wrong by 1.4's own contract, which says a drag changes the series by what changed for that occurrence and a series moved with its repeat days unchanged keeps its places. The scenarios show the same: a monthly second-Tuesday, a 15th-to-30th, a first-weekday, a last-day and a leap-day series each moved only their first day under 1.4.

**Edited and skipped days left on keys that are no longer occurrences** (c-edited 5,091 / 1,292; skips 2,998 / 1,095; placed 2,661). After a drag, 1.4 left an edited day's row and a skipped date on the old key, so the day showed in the wrong place, or twice, or an extra occurrence appeared where the skip no longer applied.

**Series-wide field edits never reached edited days, and an edit from an edited day's row changed only that day** (b-fields 5,494). A reminder, tag or location change for all events skipped every day the person had edited. This matches no option: it is model and engine, not dialog.

**"This and following" lost the edited days after the cut** (c-outside 2,420 / 600; the split family about 4,000). Every "this and following" edit, time change, answer, relationship change or calendar move in 1.3 through 1.4.5 hard-deleted the edited days from that day on. Undo could put them back for seven days. 1.4.6 keeps them; 1.5.1 also carries the series' tags and people onto the new part. The scenarios confirm it in five cases (a rename, a time change, an answer, a relationship, a calendar move from a day on), and the one with tags and people shows the new part dropping both before 1.5.1. This is the finding that produced the notice in the README and on the 1.4.6 and 1.5.1 releases.

**The clock, length or zone sent was not the one stored** (c-clock 829, c-length 569). A drag of one day back could change the event's length.

**Occurrences the expander got wrong** (c-occurrences 612 / 494). Rules several times a day, day sets, hourly rules, week numbers, set positions, the spring-forward gap, leap day and the half-hour zone with a half-hour gap. Seven of the eight fixed occurrence cases fail on 1.4. These are 1.5.0's own expander replacing the library's readings.

**Edits the design refuses were applied** (e-refusal 566): rules several times a day, day sets and week numbers, and results that leave no events or cross a year.

**Crashes** (api-crash 22): nineteen duplicate-key errors from the database, two bad instance references, one validation limit. One reproduction: an every-six-hours series in the half-hour zone made all-day.

**Undo did not put back what was there** (f-undo 3,342 / 1,692). Never on whole-series edits, but on every split, delete-following, answer from a day on and delete-this: tags, people and trip links were gone after Undo, and on 1,083 cases rows were too. The resource benchmark found the same from the other side: on 1.4 the toast's single Undo after an attendance change for "this" or "following" undoes only the newest of several journal entries, leaving the override row or the split's end date in place. 1.5.1 restored every operation exactly in both tools.

**Trips** (b-trip 625 / 315): the trip flag did not reach edited days, a trip could end up inside a trip, and a day's link was lost when a different day was deleted.

**Series created off their rule** (g-created 8,826). 1.4 aligned only a weekly start, so a monthly, yearly or daily-weekday series created on another day kept a stray first occurrence. This hits 48% of the generated cases and spills into every later check, which is why the on-rule subset is reported beside the totals. Whether 1.4's own editor could create such a start was not measured; it usually derived the rule's day from the start date, so a real user would have met this less often than the generator does.

## What 1.5.1 Gets Wrong

The 1.5.1 control run is clean: zero failures on every seed, on the fixed cases and on the sequences, with every required category exercised. The scenarios match the design in all 55. The twelve issues all pass, with no regression against anything 1.4 got right.

The comparison still found three things, all fixed on main the same day: a one-hour event typed inside the skipped hour was refused on all three versions ([#222](https://github.com/Oshyan/better-cal/issues/222)); the Activity line for a "this and following" edit read as a whole-series change and a zone change read "as before" ([#223](https://github.com/Oshyan/better-cal/issues/223)); and 1.5.0's recorded reading of a gap day ends a count-bounded series one day earlier than 1.4 did, which is the decided behaviour, not a regression.

## Resource Cost

Identical seeded data on all three versions: 205 series across daily, weekly, monthly and yearly rules in five zones, 536 edited days, 233 skipped, five large series of 1,100 to 2,600 occurrences each, 82,267 rule occurrences through 2027. Every request is its own PHP process through the real entry files.

| Operation | 1.4 | 1.5.1 |
|---|---|---|
| Month view, peak memory | 16 MB | 14 MB |
| Month view, queries | 21 | 27 |
| CalDAV week report, queries | 899 | 284 |
| CalDAV week report, memory used | 7.7 MB | 6.4 MB |
| Large series drag, queries | 149 | 190 (plus a 14-query preview) |
| Large series drag, rows written | 67 | 314 |
| Large series drag, peak memory | 12 MB | 16 MB |
| Large series new rule, queries | 26 | 363 |
| Large series new rule, rows written | 6 | 403 |
| Ordinary series drag, queries | 33 | 74 |
| Ordinary series edit, peak memory | 12 MB | 14 MB |

Reads got lighter: window reads use up to 2 MB less, and CalDAV time-range reports run about three times fewer queries because 1.5.1 answers them from the app's own expander instead of fetching and parsing every object. Edits got heavier, and on a large series much heavier: the model plans over every old occurrence from the stored start through the last edited day, runs twice (preview, then inside the commit's transaction), rewrites the edited-day rows the plan touches, and journals rows in pieces so Undo can restore them exactly. Ordinary series stay under 80 queries and 14 MB per edit, which is small in absolute terms. The 1.4 row counts for the undo of an attendance change are for an incomplete undo, so the comparison there flatters 1.4.

Wall-clock timings, taken alone, one version at a time, after every other run on the machine had finished (median of seven requests after one warm-up; 1.5.1 edits are the preview plus the commit):

| Operation | 1.4 | 1.5.1 |
|---|---|---|
| Week view | 110 ms | 78 ms |
| Month view | 124 ms | 99 ms |
| A year as twelve month requests | 1,514 ms | 1,194 ms |
| CalDAV week report | 643 ms | 161 ms |
| CalDAV year report | 692 ms | 264 ms |
| Large series drag | 63 ms | 194 ms (64 preview, 128 commit) |
| Large series zone change | 62 ms | 263 ms |
| Large series new rule | 20 ms | 688 ms (271 preview, 420 commit) |
| Large series this and following | 28 ms | 133 ms |
| Large series undo of a drag | 41 ms | 59 ms |
| Ordinary series drag | 22 ms | 95 ms (40 preview, 56 commit) |
| Ordinary series this and following | 21 ms | 62 ms |
| Ordinary series undo of a drag | 17 ms | 24 ms |

Reads are faster on 1.5.1 everywhere but the single large-series window (50 ms against 62 ms), and CalDAV reports are three to four times faster. Edits are slower, two to four times on an ordinary series and up to thirty times on a large series' new rule, where 1.4's 20 ms bought a result the harness shows to be wrong on most such edits. Every ordinary edit stays under 100 ms end to end, about what one month view costs to answer.

## Development Cost

| | 1.3.0 | 1.4.0 | 1.4.1 to 1.4.6 | 1.5.0 | 1.5.1 |
|---|---|---|---|---|---|
| Span, tag to tag | 6.8 h | 18.4 h | 5.3 days | 65.8 h | 30.6 h |
| Review rounds on product code | 1 | 1 | about 30 | about 18, plus 7 on the design | 3 |
| Findings | unknown | unknown | about 165 | about 88 | 2 to 3 |
| Harness seed runs | 0 | 0 | 13 | about 290 | about 85 |
| Gate runs | 0 | 0 | 0 | 7 | 6 |
| Agent-hours, main plus subagents | 6.3 | 5.0 | 38.0 | 57.0 | 19.1 |
| Agent-hours per changelog line | 0.7 | 0.3 | 0.9 | 3.8 | 0.9 |
| Database suite checks at the tag | none | none | 450 | 855 | 1,012 |
| Harness in the release gate | none | none | a slice | 4 seeds | 6 seeds, every option |

The cost the roadmap meant is 1.4.1 to 1.4.6: thirty reviewer rounds over five days, each finding five or six more, about 165 findings landing one at a time, with each round's bugs coming from the layer the round before had added. 1.5.0 cost more per changelog line than anything before it, because what it produced was an engine and the checks around it rather than individual fixes. 1.5.1, built on that engine, delivered 21 changelog lines for 19 agent-hours and three review rounds, two of which found nothing new; its one new product finding came from the harness, not a reviewer.

Sharding the harness took the whole release gate from about thirty minutes serial to four with four shards per seed, before 1.5.1 grew it to six seeds with every option tried (seven to eight minutes contended, inside the gate). Measured alone on the machine at the end of this comparison, one 3,000-case seed of today's harness (every option tried, sequences included) takes 887 s serial and 253 s on four shards, a 3.5 times gain; the historical serial figure of about five minutes was for the smaller 1.5.0 harness before every-option trials, so the two are not the same run.

## Caveats

- The harness could not check on 1.4 what did not exist there: a stored list of a day's own fields (24,507 "this event" checks), typed wall clocks, Google refusals and a held stale plan. These are counted as not applicable, not as failures or passes.
- Option combinations are capped at 64 per case; a case with more could match one past the cap. How often the cap was reached was not counted.
- The adapter sends half of the edits of an edited day to that day's own row, as 1.4's own client did, and judges a 400 from the old version against the design. No check takes an expectation from the product under test.
- The scenarios try one option at a time, not combinations, and test only the "after" state against variants.
- Wall-clock timings were taken with nothing else running, but other agents' non-database work on the same machine cannot be ruled out for the historical gate figures.

## Verdict

The 1.5 work produced real value, and most of it is in the engine. On 1.4, nine out of ten whole-series and split edits that the harness generates end up in a state no reading of the design would produce, and the failures fall on the edits people actually make: dragging a monthly series, changing a reminder on a series with an edited day, "this and following" on anything, and undoing any of those. The dialog's own contribution, where 1.4 took a defensible reading silently and 1.5.1 now asks, is about two in a hundred.

The price was the heaviest release this project has had and a heavier write path on large series. The thing to watch is the large-series edit cost, which is the one number in this report that moved the wrong way; reading an unbounded series one cycle past the change ([#205](https://github.com/Oshyan/better-cal/issues/205)) is the open item that bears on it.
