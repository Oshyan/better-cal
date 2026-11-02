# The Series Model

Where every occurrence of a repeating series goes when the series is edited, written down before the 1.5 rewrite starts (2026-11-02). This is the table the roadmap's "Decisions to make first" asks for. Every example is invented; the cases come from the series harness ([tools/edit-harness](../../tools/edit-harness/README.md)), the review rounds since 1.4.0, and probes run against 976f49a for this document.

## What This Is

One function decides where each occurrence goes, and every path that changes a series asks it: a whole-series edit, "this and following", a delete (this, following, all), an attendance answer, a Google calendar, Undo, and the saves of the editor and quick add. It takes the series as stored and the edit, and returns the series as it will be: its rule, start, bound, zone and all-day flag, every skipped and edited day on its new key, or a refusal with a reason. The paths only write what it returns.

Why one model: since 1.4.0 the series code has been fixed one reviewer's finding at a time. Monthly drags across a month line (the external review's F1), the last day of the month, an UNTIL left behind, list days, nth weekdays and yearly rules that didn't follow, the fifth weekday, "this and following" dropping edited days, the split day missing its edit, an attendance answer writing two entries, and two edited days colliding on a drag. Each fix added a branch (rebaseSeriesEdit's rule shift, alignedStart's snap, seriesKeyShift's ordinal mapping and its `$sameDays` escape, movedUntil, the split day's own patch), and each round's next bugs came from the branch the round before had added. patchAll and patchFollowing work out the same things separately, the Google paths work out less, and Undo restores whatever they wrote. The harness that shipped with 1.4.6 named twelve causes still open after that (a thirteenth, the key collision, is fixed since). Run again at 976f49a (3,000 cases, seed 1, 192 seconds) it has 2,011 failing checks in 378 signatures, every one under the eleven keys it lists for those twelve causes: stale-skips 639, keep-snap 378, google-parity 342, editor-rule 249, rule-change-orphans 139, biweekly-list 133, plain-rule 59, count-span-or-month-multiple 36, yearly-nth-start 22, until-type 10, until-first-phantom 4.

I'm keeping the higher bar rather than Thunderbird's and Nextcloud's fixed shift (move every exception by the same number of days and leave the rule alone): the rule follows the drag for every kind of rule, an edited day lands on the occurrence it belongs to, and the end date moves too. Where a rule can't follow a drag without losing an occurrence, the drag is refused and says why. Nothing is silently dropped, and nothing is silently ignored.

## Vocabulary

- **Series:** a master row (`rrule` and no `recurrence_parent_id`) with its rule, start, zone (`tzid`), all-day flag, length, skipped days and edited days.
- **Rule:** the pattern (FREQ, INTERVAL, the BY parts, WKST) plus the bound. `Recurrence::pattern` is the rule without its bound.
- **Rule family:** the shape of a pattern that decides how it follows a drag. The rows of the table.
- **Occurrence:** a day the rule produces, named by its **key**: the UTC instant the expander (`Recurrence::sabreExpand`, sabre/vobject) yields for it; for an all-day series, its date as a UTC midnight. The key is the occurrence's RECURRENCE-ID (RFC 5545 3.8.4.4) and Google's `originalStartTime`.
- **Raw occurrences:** what the rule produces with no skips applied. COUNT counts them, skipped ones included (RFC 5545 3.8.5.3).
- **Period:** what the rule repeats in: a day, a week (starting on WKST, Monday by default), a month or a year, all read in the series' zone.
- **Ordinal:** an occurrence's place. For a daily or weekly rule, its distance in days from the day acted on. For a monthly or yearly rule, its period and its position among the rule's raw occurrences in that period (first, second, ...), counted over the whole period.
- **Exception:** a skipped day (an EXDATE in `exdates_json`) or an edited day (an override row keyed by `recurrence_instance_utc`). An exception belongs to the occurrence its key names, wherever it shows.
- **Deviation:** what an edited day has of its own. In place (its start is its key) or moved: its distance in days from its occurrence's day, its own clock time and zone, and its length. Plus its own fields: title, place, notes, tags, people, answer, reminders.
- **Bound:** COUNT, UNTIL (inclusive; a date for an all-day series) or none.
- **Follow:** the rule is rewritten to name the day the dragged occurrence landed on, so every occurrence moves with it ("every Monday" dragged to Tuesday is "every Tuesday").
- **Realign:** what `alignedStart` does today when a rule can't follow: the rule is kept and the start snaps to the next day the rule names, losing whatever came before. The model never realigns an existing series. It follows or it refuses. Only a new series is aligned (its start moves to its first occurrence).
- **The day acted on:** the occurrence the edit was made from (`instanceStart`), the dragged one for a drag. For an edited day, the occurrence its key names.
- **Image:** the occurrence an old occurrence becomes. A **loss** is an old occurrence with no image; a **gain** is a new occurrence that is no old one's image (every 31st moved to the 1st gains the months without a 31st).
- **Δ:** whole days from the day acted on to the day it was dropped on, each read in its own zone (old zone before, new zone after). **k:** the months (or years) between their months (or years). **δ:** for a list, the change of the dragged occurrence's day within its month.

## The Table

Three parts. The first is the drag, which is where families differ. The second is every kind of edit, in terms of the first, and holds for every family unless it says otherwise. The third is the whole grid, family by edit, in short words, with the cells where today's code differs marked †.

### What a Drag Does, by Family

What holds for every row: the new start is the image of the old start (never snapped); the day acted on lands where it was dropped, since it is the image of its own key; COUNT is unchanged unless the drag changes how many periods have an occurrence (open decision 3); an UNTIL becomes the image of the last raw occurrence, written as that occurrence; skipped and edited days go to the images of their keys, edited days keeping their deviation. "Refused" means nothing is written, the drag snaps back, and the reason is shown.

| Family (who writes it) | Rule after the drag | Refused when | Exceptions map by | Today |
|---|---|---|---|---|
| DAILY, any INTERVAL (editor, quick add) | Unchanged | Never | Days: key + Δ | Follows |
| WEEKLY bare (start's weekday) | Unchanged | Never | Days | Follows |
| WEEKLY one day (editor, quick add) | BYDAY + Δ, modulo the week | Never | Days | Follows |
| WEEKLY day list | Every BYDAY + Δ | Never | Days | Follows |
| WEEKLY INTERVAL=2 list (quick add "every other Sat and Sun") | Every BYDAY + Δ, and WKST + Δ, written | Never | Days | † Days that cross the week start land in the off weeks, exceptions on no day (cause 4, biweekly-list). Probed: `INTERVAL=2;BYDAY=SA,SU` from Sat 5 Dec dragged a day gives Sun 6, Mon 14, Sun 20 Dec; with `WKST=TU` it gives Sun 6, Mon 7, Sun 20, Mon 21 |
| MONTHLY bare (editor's "monthly on this day", quick add "quarterly") | Read as BYMONTHDAY of its start, then as the next two rows; written back bare when it names the new start's day, as `BYMONTHDAY=-1` for the last day | As the next two rows | Ordinal, k months | † The start moves by Δ days and the rule stays bare, so across months of other lengths it lands on another day (cause 2, plain-rule). Probed: every 3 months from 15 Jan, 15 Apr dragged to 15 May, runs on the 14th |
| MONTHLY BYMONTHDAY 1 to 28 (editor preset, quick add "every 15th") | The landed day: 1 to 28 as that day; the 31st as the last day, `-1` (decision 2); the 29th or 30th as that day | The 29th or 30th when a February in the series' span would have no occurrence (decision 1) | Ordinal, k months | † The 29th to 31st are written as is and the months without them lose their day. Probed: every 15th dragged to 31 Jan is every 31st (five months a year lost); to 30 Jan, every 30th, and the edited 15 Feb is keyed on 2 Mar beside nothing. A drag by whole months moves the start but not the exceptions or UNTIL (cause 11) |
| MONTHLY BYMONTHDAY 29 to 31 (or bare from the 29th to 31st) | The same naming | When an old occurrence's month lacks the new day (every 29th, with 29 Feb 2028 in its span, to the 30th) | Ordinal, k months; gains the months the old day skipped | † With a COUNT, a drag to a day every month has ends months earlier and the late exceptions fall past the end (cause 10, count-span) |
| MONTHLY negative BYMONTHDAY, `-1` and `-2` (imports, "last day") | Counted from the end while the landed day is in its month's last seven days (`-1` to `-7`), by its number when earlier | Never | Ordinal, k months | † The rule is kept and the start realigns: a forward drag loses the days before it (cause 3, keep-snap), a backward one is ignored. Probed: the last day, 31 Mar dragged to 30 Mar, changed nothing |
| MONTHLY BYMONTHDAY list, `1,15` (quick add "1st and 15th") | Every member + δ; a drag by whole months (δ = 0) keeps the list | When a member would leave 1 to 28, or the list has a 29th to 31st or a negative member and δ isn't 0 (decision 4) | Ordinal, k months | † Rule kept, start snapped (cause 3). Probed: 1 Feb dragged back to 31 Jan changed nothing |
| MONTHLY nth weekday, 1 to 4 and -1, as `2TU` or `TU;BYSETPOS=2` (editor, quick add, Google) | The landed weekday and its count in the same direction; a fifth is the last (`-1`), a fifth from the end the first | Never | Ordinal, k months (one a month) | † Drags by whole months move the start but not the exceptions or UNTIL (cause 11, month-multiple); otherwise follows since 1.4.6 |
| MONTHLY nth weekday list, one weekday, `1MO,3MO` (quick add "1st and 3rd Monday") | Every member takes the landed weekday, and every count moves by the same amount as the dragged one's | When a count would leave 1 to 4 (or -1 to -4), or the members name different weekdays | Ordinal, k months | † Rule kept, start snapped to the dragged day's old date: the first occurrence is lost and the dragged day doesn't move (probe). Not generated by the harness |
| MONTHLY BYSETPOS over a set of days, `BYDAY=MO,TU,WE,TH,FR;BYSETPOS=1,3` (quick add "1st and 3rd weekday") | Every position moves by the landed day's change of position in the set | When the landed day isn't in the set (a Saturday for "weekday"), or a position wouldn't exist in every month | Ordinal, k months | † Ignored: the start realigns back (probed with an imported "last weekday"). Not generated by the harness |
| YEARLY bare (editor, quick add "every year") | Read as BYMONTH and BYMONTHDAY of its start, then as the next row; written back bare when it names the new start's month and day | As the next row | Ordinal, k years | † From 29 Feb (and its neighbors) lands on the wrong day (cause 2) |
| YEARLY BYMONTH + BYMONTHDAY (Google, imports) | The landed month and day; 29 February as the last day of February, `BYMONTH=2;BYMONTHDAY=-1` (decision 2) | Never | Ordinal, k years | † Count-span and month-multiple variants (causes 10, 11) |
| YEARLY BYMONTH + nth weekday (Google "annually on the second Tuesday of March") | The landed month, weekday and count (a fifth is the last) | Never | Ordinal, k years | † The start moves by Δ days and is left off its pattern: a stray first occurrence that counts toward COUNT (cause 7, yearly-nth-start) |
| YEARLY BYMONTH list, `BYMONTH=3,9` with a day or an nth weekday | Every month moves by the dragged one's change of month; the day or count as the single-month rows | When a month would cross the year, or the day or count would be refused | Ordinal, k years | † Rule kept, start snapped (cause 3). Probed: 15 Mar dragged to 16 Mar opens on 15 Sep; March is lost |
| Imported, never written: BYWEEKNO, BYYEARDAY, BYHOUR, HOURLY and finer, DAILY with BYDAY, nth lists over several weekdays, BYMONTHDAY with BYDAY, WEEKLY with BYMONTH, anything not above | Unchanged | Any drag that moves days (decision 5). A new time, zone (keeping the days), length or all-day flag goes through | Same days | † The start moves by Δ and the rule stays. Probed: `FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR` with Wed dragged to Thu loses its first Monday; an imported "last weekday" drag is ignored |

### Every Kind of Edit

What each edit does with the parts, for every family unless a cell says otherwise. "As a drag" means the table above, with its refusals.

| Edit | Rule | Start | Bound | Skipped and edited days | The day acted on | Today |
|---|---|---|---|---|---|---|
| Whole-series drag (scope all; days move, maybe the time too) | As a drag | Image of the start | COUNT kept (decision 3); UNTIL to the image of the last occurrence | To their images; edited days keep their deviation (decision 7 for their fields) | Lands where dropped | † Causes 2, 3, 4, 7, 10, 11 by family |
| Time of day or length (no day moves) | Unchanged | Same day, new time | COUNT kept; UNTIL becomes the last occurrence at its new time, since a timed UNTIL at the old time would cut it off | Same day at the new time; in place ones follow, moved ones keep their own time | Takes the new time | Follows (movedUntil) |
| All-day toggle | Unchanged; days read in the series' zone | Same day | UNTIL changes type: the date of the last occurrence (to all-day), its instant (to timed) | Keys become the dates of their days, or those days at the new time; edited days take the flag and keep their day offset (decision 8) | Same | † The UNTIL keeps its type and the last day is lost (cause 12, until-type). Probed: a day moved to its own time stays timed inside the all-day series, and the date series gets a timed UNTIL |
| Zone change | Unchanged when the days stay; when the new zone puts the occurrence on another local day (the instant kept across a date line), as a drag by that Δ | As a drag | As a drag | Each key's local day + Δ, at the new clock time in the new zone | Where sent | Follows. Probed: Monday 18:00 Los Angeles moved to Berlin keeping the instant is every Tuesday 03:00 there, the edit with it |
| "This and following" drag | Split at the day acted on, then the part from it is dragged as a whole series | Old part: unchanged. New part: the day acted on, where dropped | Old part: UNTIL at its last occurrence before the split. New part: what is left of COUNT, or the image of UNTIL | Old part keeps those keyed before the split; the new part gets the rest, mapped | Lands where dropped, takes the edit's fields | † The old part keeps the skips from the split on (cause 6, stale-skips); its UNTIL, a second before the split, shows a phantom day on a 1st (cause 13, until-first-phantom) |
| "This and following" field edit (rename, place, tags) | Split; the new part takes the fields | New part: the day acted on | As above | Carried to the new part on the same keys; edited days keep their own fields (decision 7) | Takes the edit's fields | † Causes 6 and 13 |
| Delete following | Old part ends before the day acted on | Unchanged | UNTIL at the last occurrence before it | Old part keeps those keyed before; edited days from the day on are deleted (their tags, people and links into Undo); skips from the day on dropped | Deleted | † Causes 6 and 13 |
| Delete this | Unchanged | Unchanged | Unchanged | Its EXDATE added; its edited row deleted | Skipped | Follows |
| Delete all | The series and its edited days deleted | | | | | Follows |
| Attendance following | Split as a field edit with no fields | As above | As above | Carried, and answered with the new part | Answered | † Cause 6 (one Undo entry since round three) |
| Editor save, rule unchanged (the same pattern and bound sent back) | The sent rule is ignored; then as a drag if the date changed, as a field edit if not | As that edit | As that edit | As that edit | As that edit | † The sent rule is taken as given: its UNTIL stays and the last day is lost; a following save keeps the whole COUNT on the new part and snaps its start, losing the edited day (cause 5, editor-rule) |
| Editor save, new rule (the pattern changed) | The sent rule | The first occurrence of the new rule on or after the sent start | As sent | A key on a day the new rule produces moves to that day's occurrence; skips elsewhere are dropped; edited days elsewhere become events of their own (decision 6) | No special place | † Old keys are left where they were and the edited days show beside the series (cause 9, rule-change-orphans) |
| Any edit from an edited day with scope all or following ([#189](https://github.com/Oshyan/better-cal/issues/189)) | The series changes by what changed for that day, measured from where it showed: its own day and clock time to where it was dropped; the fields sent go to the series | As that edit | As that edit | As that edit | Lands where dropped, keeps its deviation from its occurrence, takes the edit's fields (decision 9) | † The client sends the edit to the day's own row, which changes alone (probed: a rename and a day later with "All events" moved only that day). Sent to the master with the key, the change is measured from the key: a day moved to 09:00 and dragged to the next day at 09:00 moved the whole series to 09:00 |

### The Grid

Every family by every edit, in short words: "follows" is the drag table's rule, "same days" means keys keep their days, "split" is the following rows above, "refused if ..." names the drag table's refusal. † marks a cell where today's code differs.

| Family | Drag | Time | All-day | Zone | Following drag | Following edit | Delete following | Editor, same rule | Editor, new rule | Delete this | Attendance following |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Daily | follows | same days | same days † | follows | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Weekly bare | follows | same days | same days † | follows | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Weekly one day | follows | same days | same days † | follows | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Weekly list | follows | same days | same days † | follows | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Weekly INTERVAL=2 list | follows † | same days | same days † | follows † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Monthly bare | follows †, refused as the day rows | same days | same days † | follows † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Monthly day 1 to 28 | follows †, refused to a 29th or 30th | same days | same days † | follows † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Monthly day 29 to 31 | follows †, refused if a month lacks the new day | same days | same days † | follows † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Monthly from the end | follows † | same days | same days † | follows † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Monthly day list | follows †, refused across the month | same days | same days † | follows †, refused as drag | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Monthly nth weekday | follows † | same days | same days † | follows † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Monthly nth list | follows †, refused past the 4th | same days | same days † | follows †, refused as drag | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Monthly set position | follows †, refused off the set | same days | same days † | follows †, refused as drag | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Yearly bare | follows † | same days | same days † | follows † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Yearly month and day | follows † | same days | same days † | follows † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Yearly nth weekday | follows † | same days | same days † | follows † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Yearly month list | follows †, refused across the year | same days | same days † | follows †, refused as drag | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Imported | refused † | same days | same days † | same days, refused if days move † | refused † | split † | split † | as edit † | new rule † | skip | split † |

A split works for every family, imported ones included: a rule restarted at one of its own occurrences gives the same days from there on. Probed with the 1st and 3rd weekday, every other Sat and Sun, every two months on the 10th and 20th, March and September's second Tuesday, and the last weekend day, each restarted mid-period.

### The Cases, Resolved

The harness's twelve open causes, each by the cell that settles it:

- **Cause 2, plain-rule.** `FREQ=MONTHLY` from 30 Jan, 30 Apr dragged to 1 May. Monthly bare row: read as day 30, the landed 1st is every 1st, k = 1. Start 1 Feb, days 1 Feb, 1 Mar, 1 Apr, 1 May; today every 31st from 31 Jan.
- **Cause 3, keep-snap.** `BYMONTHDAY=1,15` from 1 Dec, 15 Dec dragged to 16 Dec: day list row, δ = 1, every 2nd and 16th from 2 Dec, nothing lost. `BYMONTHDAY=-2` dragged from 30 Aug to 5 Sep: from-the-end row, 5 Sep isn't in the last week, so every 5th, k = 1. `BYMONTH=3,9` dragged from March into April's 16th: yearly list row, every 16 April and October.
- **Cause 4, biweekly-list.** Every other Sat and Sun dragged a day: `BYDAY=MO,SU;WKST=TU`, days 6, 7, 20, 21 Dec (probed).
- **Cause 5, editor-rule.** `FREQ=WEEKLY;BYDAY=MO;COUNT=4`, following save from 11 Jan to 12 Jan with the same rule sent: the rule-unchanged row ignores it, so the old part is 4 Jan, the new part Tuesdays from 12 Jan with COUNT=3. The whole-series form keeps the last third Thursday.
- **Cause 6, stale-skips.** `FREQ=DAILY;COUNT=10` with 5 Mar skipped, renamed from 3 Mar: the old part's skips are only those before 3 Mar.
- **Cause 7, yearly-nth-start.** `BYMONTH=11;BYDAY=3SU;COUNT=15` from 15 Nov 2026, 19 Nov 2028 dragged to 17 Dec: yearly nth row, k = 0, start 20 Dec 2026 (the image of the old start), fifteen third Sundays of December, the 2034 and 2040 skips on theirs.
- **Cause 8, google-parity.** The Google path sends the same plan the local path writes (Google section below), so a bounded daily series dragged a month keeps its last day there too.
- **Cause 9, rule-change-orphans.** Every 19th changed to Tuesdays and Thursdays in the editor: the edited 19 Jun 2028 (a Monday) becomes an event of its own on that day (decision 6), not an exception beside the series.
- **Cause 10, count-span.** `BYMONTHDAY=31;COUNT=6` from 31 Jan with 31 Oct edited, 31 Mar dragged to 1 Apr: every 1st, k = 1, start 1 Feb. The six old days map to 1 Feb, 1 Apr, 1 Jun, 1 Aug, 1 Sep, 1 Nov; with decision 3 the COUNT becomes 10 (1 Feb to 1 Nov) and the edit is on 1 Nov, inside the series.
- **Cause 11, month-multiple.** `BYMONTHDAY=1;COUNT=11` from 1 Aug with 1 Aug moved to 31 Jul, 1 Feb dragged to 1 Mar: k = 1, start 1 Sep, the moved day keyed on 1 Sep (showing 31 Aug), the same whichever occurrence was dragged. `BYDAY=4SU;UNTIL=20270725`, 23 May dragged to 27 Jun: k = 1, every fourth Sunday from 28 Mar to 22 Aug, six occurrences as before.
- **Cause 12, until-type.** An all-day `BYDAY=4TU;UNTIL=20271228` made timed at 10:00 Auckland: UNTIL becomes the last occurrence's instant, so 25 Dec 2027 10:00 is kept.
- **Cause 13, until-first-phantom.** Every 2nd (all-day) split at 2 Jun 2028: the old part's UNTIL is 20280502, its last occurrence, and expands without the phantom 1 Jun (probed: `UNTIL=20270601` yields 1 Jun, `UNTIL=20270502` doesn't).

The reviewers' hand-found cases:

- **External F1.** Every 30th dragged from 30 Jan to 1 Feb, 30 Apr edited, 30 Jun skipped: day row, k = 1, the edit on 1 May, the skip on 1 Jul. Every 1st gains 1 Mar (no 30 Feb before it).
- **Round one, finding 1.** Every last day dragged from 31 Jan to 1 Feb: the from-the-end row, so every 1st from 1 Feb; the edited 31 Mar is the 1 Apr edit, the skipped 30 Apr the skipped 1 May. Today it opens on 28 Feb and 31 Jan is gone.
- **Round one, finding 2.** `BYMONTHDAY=28;UNTIL=20270528T235959Z` dragged to 1 Mar: UNTIL becomes the image of 28 May, 1 Jun, written as that occurrence.
- **Round one, finding 3.** The 1st and 15th dragged from 1 Jan to 2 Jan with 15 Jan edited: every 2nd and 16th, the edit on 16 Jan.
- **Rounds one and two, nth weekdays.** `2TU` dragged a day is `2WE`; `4TH` dragged to Fri 29 Jan is `-1FR`; yearly `BYMONTH=3;BYDAY=2TU` dragged a day is `2WE` from 10 Mar 2027 (the start is the image, so no year is lost).
- **Round two, finding 4, and round three, finding 1.** "This and following" carries the later edited days and the split day takes the edit's fields: the following rows.
- **Round two, finding 5, and round three, finding 3.** The editor's rule: the rule-unchanged row.
- **Round three, finding 4.** One entry for the split and the answer: attendance following.
- **Probes for this document.** A day moved earlier than the split but keyed after it goes with the following part (decision 11). A rename with "All events" leaves an edited day that only moved its time called by the old name (decision 7).

## Mapping, Defined

### The Function

Given the old series (rule, start, bound, zone, all-day flag, length) and the edit (the day acted on, where it was seen, where it was dropped, its new clock time, zone, all-day flag and length, the fields, and any rule sent), the model returns the new series and a map from every old key to a new key or to none, or a refusal. In order:

1. **A sent rule that is unchanged is no rule.** The sent rule is unchanged when its pattern equals the stored one and its bound names the same end. Patterns are compared after dropping INTERVAL=1, sorting BYDAY lists, reading a monthly BYMONTHDAY equal to the start's day (a yearly BYMONTH and BYMONTHDAY equal to the start's) as bare, and dropping WKST where INTERVAL is 1. Bounds are equal when the COUNTs are, or when both UNTILs have the same last raw occurrence at or before them (the editor writes 23:59:59 of the end date in the event's zone; the model writes the occurrence itself; both end on the same day). So it no longer matters that the editor sends the rule on every save ([#199](https://github.com/Oshyan/better-cal/issues/199)). A sent rule with the same pattern and a different bound changes only the bound, which is then taken as sent; the pattern still follows a drag. A different pattern is a new rule.
2. **The drag is measured.** Δ, k and δ from the day acted on to where it was dropped. From an edited day, from where that day showed (its own start), not from its key (#189).
3. **The rule follows** by its row, or the edit is refused.
4. **Every old raw occurrence is mapped** (below). If any occurrence inside the old bound has no image, the edit is refused. For an unbounded series this is checked over four years from the day acted on, which covers every month length and a leap February. Gains are allowed.
5. **The start, bound, skipped days and edited days** are the images, as below.

### Correspondence, by Family

- **Daily and weekly:** an occurrence's image is the new rule's occurrence on its local day + Δ in the new zone. Shifting every listed weekday and the week start by Δ keeps each occurrence's place in its week and its week's place in the interval, so this is exact for every daily and weekly row, every other week included.
- **Monthly:** an occurrence is the nth raw occurrence of its month under the old pattern (the rule without its bound, counted from the month's first day, so a key near the end still finds its place). Its image is the nth raw occurrence of the month k on under the new pattern. A list keeps its order under δ, so n is the same member; a single-day or single-nth rule has one a month, so n is 0.
- **Yearly:** the same, by year and k years. A month list keeps its order because a drag across the year is refused.
- **Imported:** same days only.
- **When the nth doesn't exist** in the target period: no image. That is a loss, so a drag is refused (decision 1 is the one place it happens with rules the app writes). A new rule chosen in the editor has no correspondence at all; its handling is decision 6.

Every new key is looked up among the new rule's raw occurrences (sabreExpand), never computed by adding seconds or days to an instant. So a key is always an occurrence, and a clock time inside a spring-forward gap comes out as the expander gives it (probed: every Sunday 02:30 in Los Angeles yields 03:30 on 14 Mar 2027).

### Naming the Landed Day

- **The fifth weekday is the last.** The 29th to 31st are the fifth of their weekday, which most months lack: a positive count landing there is written `-1`, and a count from the end landing on the fifth from the end is written `1`. This is what the editor's buildRrule writes and what shiftBymonthday has done since round two.
- **The 31st is the last day, and so is 29 February** (decision 2). A monthly day landing on the 31st is `BYMONTHDAY=-1`; a yearly day landing on 29 February is `BYMONTH=2;BYMONTHDAY=-1`, which sabre expands as 29 Feb in leap years and 28 Feb otherwise (probed).
- **From the end while near the end.** A from-the-end day stays from the end while the landed day is in its month's last seven days; earlier, it is named by its number.
- **Bare rules** are read as their start's day (monthly) or month and day (yearly) and written back bare when the new rule names the new start's day; so a bare series dragged stays one the editor reads as "monthly on day N" (and the series sentence keeps describing it).

### The Start, the Bound and the Exceptions

- **Start:** the image of the old start. It is never none, since a loss refuses the edit, and it is always an occurrence, so no series opens on a day off its own pattern.
- **UNTIL:** the image of the last raw occurrence at or before the old UNTIL (skipped or not), written as that occurrence: its instant in UTC for a timed series, its date in the series' zone for an all-day one. Never an instant the rule doesn't produce: sabre yields a monthly UNTIL on the first of a month at the series' time as a phantom occurrence (cause 13), and an occurrence can't be one.
- **COUNT:** unchanged. When a drag gains occurrences (decision 3), it is recounted from the new start through the image of the last occurrence, so the series still ends where its last occurrence went.
- **Skipped days:** each to its image.
- **Edited days:** the key to its image. One in place (start equal to its key) starts at the new key, keeping its length. One moved keeps its distance in days from its occurrence's day (the occurrence read in the series' zone, the row in its own), its own clock time in its own zone, and its length. An all-day toggle gives every edited day the series' flag and keeps its day offset (decision 8). Fields: decision 7.
- **The day acted on:** where it was dropped. When it has a row of its own, that row takes the edit's fields too, and keeps its deviation from its occurrence when the edit came from it (#189).

### Zones, DST and All-Day Days

A series' days are its days on its own wall clock: every local day is read in the series' zone, and a zone change re-reads them in the new one. Moving the clock from Los Angeles to Berlin while keeping the instant can move every occurrence to the next day, and the rule follows that as a drag. All-day series are stored in UTC (0.9.16), so their local day is the UTC date; a timed series made all-day takes the dates of its days in its old zone.

### What the Harness Proves

The invariants it has, and the cells each one proves:

- **0** (start is the first raw occurrence and a day of its rule): the Start column, every family; the yearly nth weekday stops being an exception to it.
- **1** (rule follow, COUNT, UNTIL stays UNTIL, time and zone as sent): the "Rule after the drag" column and the Bound column. Its oracle reads the drag table: the `keep` families (from the end, day lists, month lists) become following ones, and COUNT is unchanged except under decision 3.
- **2** (exceptions attached, none past the end): the key lookup and the UNTIL rule.
- **3** (ordinal preserved): the "Exceptions map by" column.
- **4** (deviation preserved): the edited-days rule, with decisions 7 and 8 written into it.
- **5** (following split): the following rows, including the old part's skips.
- **6** (Undo identity): the Undo section below.
- **7** (delete following): the delete rows.
- **8** (Google parity): the Google rows; it becomes "Google is sent the plan that was stored".

The invariants it should gain:

- **No loss.** Every raw occurrence inside the old bound has an image after an accepted edit.
- **A refusal changes nothing.** A refused edit leaves every row and the journal as they were, and answers its reason code.
- **UNTIL is an occurrence.** Every UNTIL the model writes is a raw occurrence of its rule (the date of one for an all-day series).
- **Gains are plain.** A new occurrence that is no image carries no exception.
- **The editor's rule doesn't matter.** An edit sent with the stored rule gives the same rows as the same edit without it.
- **From an edited day** (#189): an edit with scope all or following made from an edited day, sent as the client sends it (to the day's own row, with its own start), moves the series by the change from the day's own start and leaves the day where it was dropped.
- **No orphans after a new rule.** No edited row is keyed on a day its series' rule doesn't produce.
- **Fields follow** (if decision 7 is taken): an edited day's field that equaled the series' old value has the new one.
- **Refusals are the table's.** Every family in the drag table, including the nth weekday list, the set position rules and an imported rule, refuses exactly where its row says and nowhere else.

## The Paths on the Model

The model is a pure function (a `SeriesModel` with no database: it reads the master, its edited rows and the edit, and returns a plan of row writes or a refusal). A writer applies a plan in one transaction; the Google path sends one; Undo snapshots one. Nothing else in Events.php works out where an occurrence goes.

- **patchAll:** asks for the plan with the day acted on and writes it: the master's columns, every edited row's key and times (parked first, then set, the two passes from 1.4.6, since edited days moved by the distance between them land on each other's old keys), the skipped days, tags and people.
- **patchFollowing:** the plan is a split and a whole-series plan of the part from the day acted on. The old master gets its UNTIL at its last occurrence before the split and only the skips before it. A new master gets a new uid, the target calendar if the edit moves calendars, the rest of COUNT, the skips from the split on, and the edit. Edited rows keyed from the split on are re-parented to it (uid and calendar with them) and mapped. The day acted on's row takes the edit's fields, tags and people; the later rows keep theirs (decision 7). Rows keyed before the split stay, wherever they show (decision 11). From the first day there is no split: it is patchAll.
- **deleteFollowing:** the split's old part as above; the edited rows from the split on are deleted. From the first day it is deleteAll.
- **deleteEvent this (and deleting an edited row):** no mapping: an EXDATE and the row removed, as now.
- **setAttendance following:** a split with no fields, then the answer on the new master and the rows it carries, in one Undo group as now.
- **An edit from an edited day:** the controller routes scope all or following on an override row to its master, with the override's key as the day acted on and the override's own start as where it was seen. Scope this, or none, edits the row alone, as now. The editor already offers the choice; with this the choice shown is the change made (#189).
- **googlePatchAll:** sends the plan's master (rule, start, end, EXDATE lines from the mapped skips) instead of rebaseSeriesEdit's half of it. What Google decides itself: its instance ids (series id plus `originalStartTime`), what becomes of its exception instances when the master's start or rule changes, whether invitees are notified, and how it stores a skipped day (as a cancelled instance). What the app sends: the master, and for each edited day the plan moved, the day as a patch to its new instance id with the old one deleted, if decision 10 says to carry them. Then Google's answer is applied as now.
- **googlePatchFollowing and googleDeleteFollowing:** the same split, sent as two patches and an insert: the old master's recurrence with the UNTIL at its last occurrence (not a second before the split), the new master with the plan's rule and start (today it takes the dragged start with the old rule), and the carried edited days if decision 10 says so.
- **Undo:** what a series edit snapshots is every row the plan writes, before and after, plus the tags, people and links of rows whose links change or that are created or deleted: the master (and the new master, after only), every edited row it re-keys, re-parents, deletes or detaches, and the standalone rows decision 6 creates. apply() deletes the rows the edit created, parks the key of every row whose calendar, uid or key changed, then restores the changed columns and the deleted rows whole: exact, as the harness's invariant 6 checks. One entry per action; a refusal records none.
- **Google Undo ([#172](https://github.com/Oshyan/better-cal/issues/172)):** the plan is data, so the undo of a Google series edit is the before side sent back through the same writer, after checking the event's etag hasn't moved (as undoPluginEdit does for plugin calendars). A deleted series comes back with a new Google id, and invitees may hear about it; #172 lists those.
- **The editor:** still sends only what changed (#199), since a smaller payload is a better contract, but the server no longer depends on it. It needs "on the last day" as a monthly choice (decisions 1 and 2), and should read WKST as part of a pattern it keeps rather than treating such a rule as one it can't say. The series sentence (web/src/lib/repeat.js) doesn't yet describe `-2` to `-7` or yearly rules with BYMONTH; those read as "Repeats" until it does.
- **Quick add and create:** a new series starts on its rule's first occurrence on or after the start asked for, for every family (the yearly nth weekday is no longer left off its pattern: at creation nothing is lost by moving it). A created rule is taken as written: "every fifth Friday" stays a fifth Friday. Only a drag names days.
- **A refused drag** answers 422 `series_cannot_follow` with the reason ("Not every month has a 30th, so this series can't move there. Open it to choose its days."). moveEvent and resizeEvent already put the occurrence back and show the message.

What goes from Events.php when this lands: rebaseSeriesEdit, alignedStart, daysChanged (no callers already), dayDelta, seriesKeyShift, occurrencesIn, movedUntil, movedOverride, followingRrule and exdatesFrom, plus the halves of patchAll, patchFollowing, deleteFollowing, googlePatchAll, googlePatchFollowing and googleDeleteFollowing that work out rules, starts, bounds and keys (each keeps only its write). From Recurrence.php: shiftByday, shiftBymonthday, setUntil, splitUntil, and daysToFirstByday (no callers outside one test). parkInstanceKeys stays, shared by the writer and Undo. RepeatParser::firstOn and matches stay for quick add.

### Floating Times

A floating series ([#167](https://github.com/Oshyan/better-cal/issues/167)) is one row of the table, not a new kind: the model already works on the series' own wall clock, and a floating series is one whose wall clock has no zone. What the model needs, decided: a way to know a series is floating; its keys stored as its wall-clock times written as if UTC (the storage #167 leans toward), so arithmetic on them is plain UTC arithmetic with no DST; and a zone change between floating and pinned keeps every occurrence's wall clock (Δ = 0, same days at the same clock time). Left open, for #167 rather than this model: whether that is a flag on the row or a new column, how the events window widens for floating rows, reminders on the device's last zone, and what a Google calendar gets (Google can't hold a floating time: pinned there with the flag kept here, or floating events kept off Google).

## Open Decisions

1. **A drag to the 29th or 30th** when the series' span has a February without one. Recommended: refuse with the reason, and add "on the last day" to the editor's monthly choices. The owner's bar asks for exactly this, and the editor can still make "monthly on day 30" on purpose. Alternatives: accept it and skip those Februaries (today, and what Google and Thunderbird mean by "on day 30"), or write the clamped form `BYMONTHDAY=28,29,30;BYSETPOS=-1`, which sabre expands correctly (probed) but nothing in the app can show or edit.
2. **A drag to the 31st is the last day, and 29 February the last day of February.** Recommended: yes. It's the fifth weekday's rule again, and the literal reading loses five months a year (or three years in four).
3. **COUNT when a drag gains occurrences** (a day the 29th to 31st, or 29 February, moved to a day every period has). Recommended: recount so the series still ends where its last occurrence went; no edited day falls past the end, and the bound stays a COUNT. Alternative: keep COUNT, end months earlier, and refuse when an edited day would fall past the end.
4. **Lists move together.** Recommended: every member moves the same way within its period, and a drag that would push one out of its period is refused. Weekly lists already move this way, and the alternative (only the dragged member moves, so the 1st and 15th dragged from the 15th becomes the 1st and 16th) makes dragging the 1st and dragging the 15th mean different things.
5. **Imported rules refuse drags that move days**, and take every other change. Recommended, over Thunderbird's fixed shift (start moved, rule kept), which loses or adds days on exactly these rules (probed with an imported weekday rule).
6. **Edited days a new rule doesn't produce** become events of their own on the same calendar, named in the toast; skips there are dropped. Recommended: no edit is lost, nothing shows as part of a series it isn't part of, and Undo puts them back. Alternatives: keep them keyed where they are (today: they show beside the series), or delete them.
7. **A whole-series edit reaches the edited days' unedited fields.** Recommended: a field an edited day has equal to the series' old value takes the new value (title, place, notes, length, reminders); a field it changed stays. Today nothing reaches an edited row, so renaming "Choir" leaves last week's moved Monday called "Choir" (probed). This changes the harness's invariant 4.
8. **An all-day toggle and a day moved to its own time.** Recommended: the day takes the series' flag and keeps its day offset; the toggle is about the whole series, and an all-day series with one timed day is a state the editor can't show as part of it. Alternative: it keeps its own clock time (today).
9. **"All events" and "This and following" from an edited day** (#189). Recommended: the series changes by what changed for that day, measured from where it showed; the day lands where it was dropped, keeps its own edits and takes the edit's fields. This is #189's first option. Alternative: offer only "This event" from an edited day.
10. **Google's exceptions after a series change.** Recommended: before coding the Google release, try it on a test calendar: change a master's start and rule with edited instances on it and read back what Google kept. If it drops or strands them, carry them ourselves (one call per edited day). Alternative: send the master only and accept what Google does.
11. **Which edited days go with "from this day on".** Recommended: by the occurrence they were made from (their key), wherever they show, as today, as RFC 5545's RECURRENCE-ID and Google's `originalStartTime` mean it. Alternative: by the day they show on, which would make the same row belong to different parts depending on how far it was moved.

## Acceptance

- **The harness is clean with its known list empty.** 3,000 cases with seed 1 and again with a second seed, `Known::ROOT_CAUSES` empty and its README's list gone, with the invariants above added and its oracle reading the drag table; the database suite's 200-case slice (`server/tests/db/edits.test.php`) then fails on any failure at all.
- **The hand-written series tests stay.** Those whose cells the table changes get new expectations, each naming its cell: in server/tests/run.php "the last day of the month dragged from 31 Jan to 1 Feb opens on 28 Feb ..." (now every 1st from 1 Feb, the edit and the skip mapped), "the 1st and 15th dragged from 1 Jan to 2 Jan opens on the edited 15 Jan ..." (now the 2nd and 16th), "its 15 Jan dragged to 16 Feb opens on 15 Feb ..." (now the 2nd and 16th from 2 Feb), "an end sent with the edit is taken as given" (now the same end sent back moves with the days), and the editor-sent-rule cases. The model gets its own pure tests: every row of the drag table with an edited and a skipped day and a refusal, each undone. The time zone cross-check (tools/tz-harness) stays at zero unexplained.
- **An independent review before each merge**, by a reviewer who reads this table and the code, not a summary of it.
- **Landing order, each its own release in the 1.5 line** (other 1.5 work can land between them under its own numbers):
  - **1.5.0, the model and whole-series edits:** the plan function, patchAll on it (drags, time, all-day, zone), refusals, the editor's unchanged rule, a new rule (decision 6), "All events" from an edited day, and creation aligned for every family. Closes causes 2, 3, 4, 7, 9, 10, 11 and 12, and cause 5 for whole-series saves.
  - **1.5.1, "this and following" and deletes:** patchFollowing, deleteFollowing and attendance following on the split. Closes causes 5, 6 and 13.
  - **1.5.2, Google:** the Google paths sending the plan, after decision 10's probe, and Undo for Google series ([#172](https://github.com/Oshyan/better-cal/issues/172)). Closes cause 8.
  - **1.5.3, floating times** ([#167](https://github.com/Oshyan/better-cal/issues/167)) on the model, once its open questions are answered.
