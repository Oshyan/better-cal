# Series Model Examples

The worked examples for [series-model.md](series-model.md): what the confirmation dialog says for each kind of change, the full grid of family by edit, the harness's open causes and the reviewers' cases resolved under the revised model, and the prediction cases. Every series, title, place and person here is invented. Dates are 2027 unless they say otherwise; times are Los Angeles time unless they say otherwise. Sabre's answers were probed for the forms they name.

In the dialog lines below, `(•)` is the preselected reading and `( )` another; the preselections are open decision 1's recommendations.

## A Drag, Start to Finish

"Choir", weekly on Tuesday at 19:00 from Tue 5 Jan, no end. Its Tue 13 Apr is "Rehearsal" at Sample Hall (own title and place, in place); its Tue 23 Mar was moved to Fri 26 Mar (own date); Tue 30 Mar is skipped. The person drags Tue 9 Mar to Wed 10 Mar and picks "All events…" on the drop chip.

```
Move all events · Choir                         [This and following | All events]  Only this one
Weekly on Tuesday at 19:00, now weekly on Wednesday at 19:00                       No end, as before
Next     Wed 10 Mar · Wed 17 Mar · Fri 26 Mar (its own date) · Wed 7 Apr
Edited   'Rehearsal' Tue 13 Apr moves to Wed 14 Apr, keeps its title and place
         Tue 23 Mar, moved to Fri 26 Mar          (•) Keep Fri 26 Mar  ( ) Move to Sat 27 Mar
Skipped  30 Mar moves to 31 Mar
                                                                    Cancel   [Move all events]
```

Written on Confirm: the rule `FREQ=WEEKLY;BYDAY=WE`, the start Wed 6 Jan (the earliest image, here the old start's), the 13 Apr row re-keyed to Wed 14 Apr in place, the 23 Mar row re-keyed to Wed 24 Mar and still showing on Fri 26 Mar, the skip on 31 Mar. With "Move to Sat 27 Mar", the 23 Mar row would show on Sat 27 Mar, three days after its occurrence as before. Either way its date stays its own: the choice is for this change only, and the next series change asks again. The toast: "Choir · moved all events", with Undo.

### A Plan as JSON

The plan the dialog above was drawn from, trimmed:

```json
{"plan": {
  "hash": "9f2c41d07a",
  "scope": "all",
  "direct": false,
  "rule": {"before": "FREQ=WEEKLY;BYDAY=TU", "after": "FREQ=WEEKLY;BYDAY=WE",
           "wordsBefore": "Weekly on Tuesday at 19:00", "words": "Weekly on Wednesday at 19:00"},
  "start": "2027-01-06T19:00:00-08:00",
  "bound": {"kind": "none", "words": "No end, as before"},
  "next": [{"start": "2027-03-10T19:00:00-08:00", "kind": "acted"},
           {"start": "2027-03-17T19:00:00-07:00", "kind": "occurrence"},
           {"start": "2027-03-26T19:00:00-07:00", "kind": "edited", "words": "its own date"},
           {"start": "2027-04-07T19:00:00-07:00", "kind": "occurrence"}],
  "groups": [{"id": "movedDays", "label": "Days moved on their own", "selected": "keep",
              "options": [{"id": "keep", "label": "Keep Fri 26 Mar"},
                          {"id": "move", "label": "Move to Sat 27 Mar"}]}],
  "days": [{"key": "2027-04-14T02:00:00Z", "newKey": "2027-04-15T02:00:00Z", "kind": "edited",
            "title": "Rehearsal", "outcome": "moves", "words": "'Rehearsal' Tue 13 Apr moves to Wed 14 Apr, keeps its title and place"},
           {"key": "2027-03-24T02:00:00Z", "newKey": "2027-03-25T02:00:00Z", "kind": "edited",
            "outcome": "stays", "words": "Tue 23 Mar, moved to Fri 26 Mar: stays there"},
           {"key": "2027-03-31T02:00:00Z", "newKey": "2027-04-01T02:00:00Z", "kind": "skipped", "outcome": "moves"}],
  "losses": [], "gains": [], "refusals": [], "notes": [],
  "summary": "Moved Choir (all events): weekly on Tuesday, now Wednesday, from 6 Jan."
}}
```

The commit sends the same PATCH with `"plan": {"hash": "9f2c41d07a", "choices": {"movedDays": "keep"}}`.

### An Editor Save

Same series; in the editor from Tue 9 Mar, the title changed to "Chorus" and the reminder to 30 minutes, "All events in series".

```
Save for all events · Choir
Title     Choir, now Chorus            Reminder  1 hour before, now 30 minutes
Repeats weekly on Tuesday at 19:00, as before                                      No end, as before
Own title 'Rehearsal' Tue 13 Apr keeps its own title            [ ] Give it the new title too
                                                                     Cancel   [Save for all events]
```

### Delete Following

From Tue 9 Mar, "This and following":

```
Delete this and following · Choir
Ends Tue 2 Mar (was no end) · deletes every event from Tue 9 Mar on
Edited   'Rehearsal' Tue 13 Apr and Tue 23 Mar (moved to Fri 26 Mar) are deleted with them
                                                                     Cancel   [Delete from 9 Mar on]
```

### A Call Without a Plan

An agent renames the same series with `PATCH /events/:id {"scope": "all", "title": "Chorus"}` and no `plan`. 'Rehearsal' owns its title, so the plan has a group ("Give it the new title too") and isn't `direct`: the answer is 409 `series_plan_required` with the plan, and the agent commits it with `"choices": {"ownTitle": "keep"}` or `"give"`. The same rename on a series where no edited day owns its title is `direct`: applied at once, 200. A drag sent without a plan is never `direct`; a commit that leaves out a group (counting on its preselected option) is 409 again.

## Month Ends

"Book club", monthly on the 15th at 19:00 from 15 Jan, no end.

15 Mar dragged to 30 Mar (March has 31 days, so the 30th isn't its last). The two readings agree on their next three dates, so each row says where they part:

```
(•) On the 30th, or the last day in shorter months   30 Mar · 30 Apr · 30 May   in February: 28 Feb, and 29 Feb 2028
( ) On the 30th, skipping months without one         30 Mar · 30 Apr · 30 May   none in February: drops 15 Feb's event, then every February
```

15 Mar dragged to 31 Mar (the clamped reading is the last day for all time, so they merge):

```
(•) On the last day                           31 Mar · 30 Apr · 31 May
( ) On the 31st, skipping months without one  31 Mar · 31 May · 31 Jul   drops 5 months a year, first 15 Feb's
```

15 Apr dragged to 30 Apr (April's last day, so all three):

```
(•) On the 30th, or the last day in shorter months   30 Apr · 30 May · 30 Jun   in February: 28 Feb, and 29 Feb 2028
( ) On the 30th, skipping months without one         30 Apr · 30 May · 30 Jun   drops every February
( ) On the last day                                  30 Apr · 31 May · 30 Jun   the 31st in longer months
```

The rules written: `BYMONTHDAY=28,29,30;BYSETPOS=-1`, `BYMONTHDAY=30`, `BYMONTHDAY=-1`. Probed: the clamped 30th from 30 Jan runs 30 Jan, 28 Feb, 30 Mar, 30 Apr, and on to 30 Jan 2028, 29 Feb 2028, 30 Mar 2028; the clamped 29th gives 28 Feb 2027 and 29 Feb 2028.

The same dates now, apart later: "Book swap", monthly on the 15th, 3 events from 15 Mar. 15 Mar dragged to 30 Mar. Both readings give 30 Mar, 30 Apr and 30 May (probed), but they're different rules, so neither is merged away:

```
(•) On the 30th, or the last day in shorter months   30 Mar · 30 Apr · 30 May   the same 3 dates; if extended, 28 or 29 Feb
( ) On the 30th, skipping months without one         30 Mar · 30 Apr · 30 May   the same 3 dates; if extended, none in February
```

Picked and later extended in the editor to 12 events, the clamped rule ends on 29 Feb 2028 and the skipping one on 30 Mar 2028 (probed). The rule written is the one picked, and an extension keeps it.

From the end: "Rent due", all-day, monthly on the last day from 31 Jan. 31 Mar dragged to 30 Mar, the second-last day of March:

```
(•) On the second-last day                           30 Mar · 29 Apr · 30 May
( ) On the 30th, or the last day in shorter months   30 Mar · 30 Apr · 30 May
( ) On the 30th, skipping months without one         30 Mar · 30 Apr · 30 May   drops every February
```

Dragged instead to 5 Mar (the 27th-last day): one reading, "monthly on the 5th".

Written as "every 31st": "Salon night", `BYMONTHDAY=31`, made in the editor as "on day 31, skipping months without one" (probed: 31 Jan, 31 Mar, 31 May, 31 Jul, 31 Aug, 31 Oct, 31 Dec). Dragged from 31 Jan to 1 Feb: one reading, "monthly on the 1st", each 31st moving to the 1st of the next month, and the plan says "adds 1 Mar, 1 May, 1 Jul, 1 Oct and 1 Dec", the months after one without a 31st. It's never rewritten into the last day unless the person picks that reading.

29 February: "Leap party", yearly on 28 Feb from 2028, dragged to 29 Feb 2028:

```
(•) On the last day of February           29 Feb 2028 · 28 Feb 2029 · 28 Feb 2030
( ) On 29 February, only in leap years    29 Feb 2028 · 29 Feb 2032 · 29 Feb 2036   drops three years in four
```

## A Count

"Piano lesson with Alex Example", monthly on the 31st at 16:00, 6 events from 31 Jan (31 Jan, 31 Mar, 31 May, 31 Jul, 31 Aug, 31 Oct, probed). Its 31 Oct is "Recital at Sample Hall". 31 Mar dragged to 1 Apr, "All events".

```
Move all events · Piano lesson with Alex Example
Monthly on the 31st at 16:00, now monthly on the 1st
(•) Keep 6 events    1 Feb · 1 Mar · 1 Apr · 1 May · 1 Jun · 1 Jul    ends 1 Jul (was 31 Oct)
    After the new end: 'Recital at Sample Hall' 31 Oct   (•) Keep it as an event of its own  ( ) Delete it
    6 in the series + 1 separate
( ) Keep the end     Changes 6 events to 10, 1 Feb to 1 Nov; adds 1 Mar, 1 May, 1 Jul and 1 Oct;
                     the recital moves with its event to 1 Nov
                                                                    Cancel   [Move all events]
```

With "Keep 6 events", the old 31 Jul, 31 Aug and 31 Oct have no place in the series, and 1 Mar, 1 May and 1 Jul are new; the line says so when opened. Written: `FREQ=MONTHLY;BYMONTHDAY=1;COUNT=6` from 1 Feb, and the recital as an event of its own on 31 Oct with its title, place, tags and people: seven events in all, which the total line says, so the count isn't a surprise of its own. With "Delete it", "6 events" alone. With "Keep the end", `COUNT=10` (probed: 1 Feb to 1 Nov).

An UNTIL series in the same drag keeps the end by default (UNTIL the latest image) and offers "Keep 6 events" second.

## Lists

"Gym", weekly on Monday, Wednesday and Friday at 07:00 from Mon 4 Jan. Wed 10 Mar dragged to Thu 11 Mar:

```
(•) Move every Wednesday to Thursday: Monday, Thursday and Friday    Thu 11 Mar · Fri 12 Mar · Mon 15 Mar
( ) Shift the whole weekly pattern: Tuesday, Thursday and Saturday    Thu 11 Mar · Sat 13 Mar · Tue 16 Mar
```

Wed 10 Mar dragged to Fri 12 Mar: "Move every Wednesday to Friday" is a disabled row ("Friday is already one of its days"), and "Shift the whole weekly pattern: Wednesday, Friday and Sunday" is the only reading.

A member crossing another, and the start: "Swim", Monday and Wednesday at 07:00, 6 events from Mon 4 Jan (4, 6, 11, 13, 18 and 20 Jan). Mon 11 Jan dragged to Thu 14 Jan:

```
(•) Move every Monday to Thursday: Wednesday and Thursday   Thu 14 Jan · Wed 20 Jan · Thu 21 Jan   starts Wed 6 Jan (was Mon 4 Jan); ends Thu 21 Jan
( ) Shift the whole weekly pattern: Thursday and Saturday   Thu 14 Jan · Sat 16 Jan · Thu 21 Jan   starts Thu 7 Jan; ends Sat 23 Jan
```

The start is the earliest image: Wed 6 Jan stays where it was, Mon 4 Jan goes to Thu 7 Jan. Probed: `BYDAY=WE,TH;COUNT=6` from Wed 6 Jan gives 6, 7, 13, 14, 20 and 21 Jan, each old occurrence's image; from Thu 7 Jan, the old start's image, it would lose 6 Jan and run on to 27 Jan.

A gain at the edge: the same Swim from Wed 6 Jan with no end (no Monday in its first week), Mon 11 Jan dragged to Fri 15 Jan, "Move every Monday to Friday": Wednesday and Friday from Wed 6 Jan, and the plan says "adds Fri 8 Jan" (probed: 6, 8, 13, 15 Jan).

Every other week: "Lab", every other Monday and Wednesday from Mon 4 Jan. Mon 1 Mar dragged to Sun 28 Feb, "Move every Monday to Sunday": the rule becomes `INTERVAL=2;BYDAY=SU,WE;WKST=SU` from Sun 3 Jan, so each Sunday shares a week with its Wednesday (probed: 3, 6, 17, 20 Jan). Without WKST=SU the Wednesdays would land in the off weeks (probed: 3, 13, 17, 27 Jan).

"Payday", all-day, monthly on the 1st and 15th from 1 Jan. 15 Mar dragged to 16 Mar:

```
(•) Move every 15th to the 16th: the 1st and 16th     16 Mar · 1 Apr · 16 Apr
( ) Shift the whole monthly pattern: the 2nd and 16th  16 Mar · 2 Apr · 16 Apr
```

A member crossing another: the same Payday, its 15 Apr "Payday, bonus" (own title). 1 Mar dragged to 20 Mar:

```
Move all events · Payday
Monthly on the 1st and 15th, now on the 15th and 20th                    Starts Fri 15 Jan, the first 15th (was 1 Jan)
(•) Move every 1st to the 20th: the 15th and 20th    15 Mar · 20 Mar · 15 Apr
( ) Shift the whole monthly pattern                  the 15th would move past the end of its month
Edited   'Payday, bonus' Thu 15 Apr stays on 15 Apr
```

Each 1st goes to the 20th of its month and each 15th stays (probed: `BYMONTHDAY=15,20` from 15 Jan gives 15 Jan, 20 Jan, 15 Feb, 20 Feb; from 20 Jan, the old start's image, it would lose 15 Jan). By sorted position the edited 15th, the old second member, would have gone to the new second, the 20th.

"Book group", monthly on the first and third Monday (`1MO,3MO`). The third Monday, 15 Mar, dragged to Tue 16 Mar: "Move every third Monday to the third Tuesday" (`1MO,3TU`, probed) or "Shift the whole monthly pattern: the first and third Tuesday".

## From an Edited Day

The first review's example: "Book club", monthly on the 1st at 19:00, 6 events from 1 Jan, its 1 Jan moved to 31 Jan, dragged from 31 Jan to 1 Feb, "All events". Adopting the day moves every event a whole month, so the same way is first:

```
Move all events · Book club                                                         Only this one
(•) Every event moves a day: monthly on the 2nd     Mon 1 Feb (January's, its own date) · Tue 2 Feb · Tue 2 Mar
( ) The 1st becomes its day: every event a month later   1 Feb · 1 Mar · 1 Apr   from 1 Feb to 1 Jul (was 1 Jan to 1 Jun)
                                                                    Cancel   [Move all events]
```

Probed: `BYMONTHDAY=1;COUNT=6` from 1 Feb runs 1 Feb to 1 Jul. The first revision didn't offer the second reading (1 Jan and 1 Feb are in different months); it's there now with its effect on the start and end in view.

"Choir", weekly on Tuesday at 19:00 from 5 Jan, its Tue 9 Mar moved to Thu 11 Mar, dragged to Fri 12 Mar, "All events". The same way, Δ is one day: weekly on Wednesday from 6 Jan, and this day on Fri 12 Mar, its own date, keyed on Wed 10 Mar. Adopting runs the drag from Tue 9 Mar to Fri 12 Mar, three days, less than a week, so it's first:

```
(•) Friday becomes its day: weekly on Friday       Fri 12 Mar · Fri 19 Mar · Fri 26 Mar   starts Fri 8 Jan
( ) Every event moves a day: weekly on Wednesday   Fri 12 Mar (this one, its own date) · Wed 17 Mar · Wed 24 Mar
```

Across a week boundary: "Sunday run", weekly on Sunday at 08:00 from Sun 3 Jan (weeks start on Monday). Its Sun 7 Mar was moved to Mon 8 Mar, and is dragged on to Tue 9 Mar with "All events". Sun 7 Mar and Tue 9 Mar are in different weeks, which the first revision took as a reason not to offer adoption:

```
(•) Tuesday becomes its day: weekly on Tuesday   Tue 9 Mar · Tue 16 Mar · Tue 23 Mar   every event 2 days later; starts Tue 5 Jan (was Sun 3 Jan)
( ) Every event moves a day: weekly on Monday    Tue 9 Mar (this one, its own date) · Mon 15 Mar · Mon 22 Mar   starts Mon 4 Jan
```

Clock time: "Choir" at 19:00, its Tue 16 Mar at its own 19:30 and Tue 6 Apr at its own 18:00. The 16 Mar event dragged to 20:00 the same day, "All events":

```
(•) 20:00 becomes its time: every event at 20:00
( ) Every event moves half an hour: 19:30, this one at 20:00
    Either way, Tue 6 Apr stays at its own 18:00
```

The date and the time apart: "Choir", its Tue 9 Mar moved to Thu 11 Mar at 19:30 (own date and time), dragged to Fri 12 Mar at 20:00. Two groups, each its own choice:

```
Day    (•) Friday becomes its day: weekly on Friday     ( ) Every event moves a day: weekly on Wednesday
Time   (•) 20:00 becomes its time                       ( ) Every event moves half an hour, to 19:30
```

Picking "Every event moves a day" with "20:00 becomes its time": weekly on Wednesday at 20:00, and this day on Fri 12 Mar, its date its own and its time inherited. Wanting the new clock time doesn't drag the exceptional date along with it.

## Own Fields

The backfill, on invented rows of "Choir" (renamed "Chorus" on 1 Mar, which reached none of its edited days, as today):

| Edited day | What differs from the series | Own fields after the migration | Right? |
|---|---|---|---|
| Tue 9 Mar, moved to Thu 11 Mar | its day | `date` | Yes |
| Tue 16 Mar at 19:30 | its clock time | `time` | Yes |
| Tue 13 Apr, "Rehearsal" at Sample Hall | title, place | `title`, `location` | Yes |
| Tue 2 Feb, still called "Choir" | title (the rename never reached it) | `title` | Can't know; the next rename's plan offers "Give it the new title too" |
| Tue 20 Apr, renamed "Chorus" on its own before the series was | nothing | none | Can't know; it now follows the series' title |

"Use series value": the editor on Tue 13 Apr shows "Edited day of Chorus (weekly on Tuesday). Its own: title, place. Use series values", and a reset icon beside Title and Place. Resetting the place leaves `title` its own; resetting both deletes the row, and the day is an ordinary occurrence again.

From elsewhere, the same series on a calendar another app also writes. Each row is a sync, field by field, against the row as stored:

| What arrives | Before | After | Why |
|---|---|---|---|
| The 13 Apr "Rehearsal" echoed unchanged, after the series here was renamed "Rehearsal" too | `title` own | `title` own | Unchanged: ownership kept, though the values are equal |
| The 13 Apr place changed there to "Sample Annex" | `location` own | `location` own | Changed, and differs from the master's |
| The master renamed "Practice" there, and the 16 Mar title changed to "Practice" in the same sync | `title` inherited | `title` inherited | Changed, and equals the master's new value |
| The 2 Feb title changed there from "Choir" to the master's "Chorus" | `title` own | `title` inherited | Put back to the series' value there |
| A new edited day, Tue 27 Apr at 19:30, made in the other app | new row | `time` | Never seen: by equality, as the backfill |

All-day: "Open studio", all-day, weekly on Saturday, with Sat 13 Mar made a timed 09:00 to 12:00 "Sample Hall visit". The series made timed at 10:00: every inherited Saturday at 10:00, and the plan says "1 edited day keeps its own time: 'Sample Hall visit' on 13 Mar at 09:00". Made all-day again: the visit stays timed.

## Bounds and Days Outside

Ends shortened: "Choir", 10 events from Tue 5 Jan (to 9 Mar), its 23 Feb "Rehearsal at Sample Hall", 2 Mar skipped. In the editor, Ends after 6:

```
Save for all events · Choir
Ends after 6 events, on 9 Feb (was 10, on 9 Mar)
After the new end: 'Rehearsal at Sample Hall' Tue 23 Feb   (•) Keep it as an event of its own  ( ) Delete it
1 skipped day after the end (2 Mar) is forgotten
```

An end with no image: "Tutoring", monthly on the 15th from 15 Dec 2026 until 15 Feb (15 Dec, 15 Jan, 15 Feb). 15 Jan dragged to 30 Jan:

```
(•) On the 30th, or the last day in shorter months   30 Dec · 30 Jan · 28 Feb   ends 28 Feb (was 15 Feb)
( ) On the 30th, skipping months without one         30 Dec · 30 Jan            ends 30 Jan (was 15 Feb): February has no 30th
    Bound    (•) Keep the end: 2 events      ( ) Keep 3 events: 30 Dec, 30 Jan, 30 Mar
```

Under the skipping reading the old last occurrence has no image, so "keep the end" ends at the latest image there is (probed: `BYMONTHDAY=30;UNTIL=20270130` from 30 Jan gives 30 Jan alone; with `COUNT=2`, 30 Jan and 30 Mar).

A start with no image: "Tutoring" from 15 Feb instead, its 15 Feb "First session" (own title). 15 Mar dragged to 30 Mar with the skipping reading picked:

```
Starts 30 Mar (was 15 Feb): 15 Feb has no 30th to move to
'First session' 15 Feb   (•) Keep it as an event of its own  ( ) Delete it
```

Under the clamped reading it starts on 28 Feb, and "First session" moves to 28 Feb with its title.

A sparse interval: "Survey", every 61 months on the 15th from 15 Jan (probed: 15 Feb 2032, 15 Mar 2037, 15 Apr 2042). Dragged to 30 Jan:

```
(•) On the 30th, or the last day in shorter months   30 Jan · 29 Feb 2032 · 30 Mar 2037
( ) On the 30th, skipping months without one         30 Jan · 30 Mar 2037 · 30 Apr 2042   drops 15 Feb 2032's, then 1 in 12
```

The first draft's four-year check passed this drag and lost February 2032. The walk checks 4,800 periods (61 and 4,800 share no factor), each a month-length lookup.

Four years of days: "Water plants", daily at 08:00 from 1 Jan 2027 until 31 Dec 2030, 1,461 events. 5 Mar dragged to 6 Mar with "All events": daily from 2 Jan 2027 until 1 Jan 2031, still 1,461 events, the end found by arithmetic. The shipped movedUntil read the end from an expansion capped at 1,001 occurrences, whose last is 27 Sep 2029, and moved that a day: the series ended on 28 Sep 2029 and lost 460 days, with nothing said.

A split past the cap: "Stretch", daily from 1 Jan 2027, 1,500 events (to 8 Feb 2031). Renamed "Morning stretch" with "This and following" from 15 Apr 2030, its 1,201st: the old part has 1,200 events, to 14 Apr 2030, and the new part `COUNT=300`, 15 Apr 2030 to 8 Feb 2031. The shipped followingRrule counted the occurrences before the split in a capped expansion, found 1,001, and wrote `COUNT=499`: the new part ran to 26 Aug 2031, 199 days past the series' end.

## Capabilities

- `FREQ=WEEKLY;BYDAY=MO,WE;BYHOUR=9;BYMINUTE=30` from another app, moved to 10:00 with "All events": written as `BYHOUR=10;BYMINUTE=0`, the dialog saying "Weekly on Monday and Wednesday at 09:30, now at 10:00". Probed: a rule with BYHOUR=9 and a 10:00 start yields 10:00 once and 09:00 after, which is why the hour has to be rewritten.
- `FREQ=YEARLY;BYWEEKNO=1;BYDAY=MO;BYHOUR=9;WKST=SU`, its parts composed: moved to 10:00, accepted, `BYHOUR=10` written (the fixed hour allows it, the week number doesn't object); dragged a day, refused, "This repeat counts weeks of the year, so its days can't follow a drag" (the week number refuses, and a refusal wins). A new length or a new end: accepted, WKST kept.
- `FREQ=DAILY;BYHOUR=9,17`: made all-day, refused, "This repeat happens twice a day (09:00 and 17:00), so it can't be made all-day" (probed: two occurrences on 1 Mar). Dragged a day or given another zone, refused with the reason below. Renamed for all events or given an end: accepted.
- `FREQ=DAILY;BYHOUR=2,3;BYMINUTE=30` in Los Angeles: on 14 Mar sabre yields one occurrence, 03:30 PDT, where 13 and 15 Mar have two (probed), because the 02:30 moves forward onto the 03:30. A drag or zone change: "This repeat happens several times a day, and on a day the clocks change two of its times can fall on one moment, so it can't be moved or given another zone as a series. Move one event, or change its other details." `FREQ=HOURLY` steps by elapsed time and has no collision (probed: 01:30 PST, then 03:30 PDT), but refuses the same edits.

## Zones

"Team call", weekly on Monday at 18:00 in Los Angeles from 1 Mar. In the editor, zone changed to Berlin, time left at 18:00:

```
(•) 18:00 in Berlin           every Monday at 18:00 Berlin time; 09:00 in Los Angeles, 10:00 on 15 and 22 Mar and 1 Nov
( ) The same moment as now    every Tuesday at 03:00 Berlin time; 18:00 Monday in Los Angeles, 19:00 on 15 and 22 Mar and 1 Nov
```

Probed for the second: 03:00 Berlin time is 02:00 UTC through 23 Mar and 01:00 UTC from 30 Mar, while Los Angeles moved its clock on 14 Mar.

A gap: "Night shift", weekly on Sunday at 02:30 from 7 Mar. Its plan notes "No 02:30 on 14 Mar in Los Angeles: that one is at 03:30" (probed: 03:30 PDT). An overlap: weekly on Sunday at 01:30 from 31 Oct notes "01:30 happens twice on 7 Nov: this is the first, in daylight time" (probed: 01:30 PDT, 08:30 UTC).

## A New Rule, and Who Owns What

"Choir", weekly on Tuesday, its 13 Apr and 11 May "Rehearsal" (own titles), 20 Apr skipped. In the editor, Monday and Thursday ticked and Tuesday cleared, "All events":

```
Save for all events · Choir
Weekly on Tuesday at 19:00, now weekly on Monday and Thursday at 19:00, from Thu 7 Jan
Two edited days become events of their own: 'Rehearsal' on 13 Apr and 'Rehearsal' on 11 May
                       (•) Keep them as events of their own   ( ) Delete them
1 skipped day (20 Apr) is forgotten: the new rule has no 20 Apr
```

The Activity entry: "Changed how Choir repeats (all events): weekly on Tuesday, now on Monday and Thursday. Two edited days became events of their own: 'Rehearsal' on 13 Apr and 'Rehearsal' on 11 May." The toast: "Choir · changed how it repeats".

Tuesday changed to Thursday instead (the old rule with its days shifted): a reading group appears, `(•) Move edited and skipped days with their events, as a drag would` and `( ) Keep them where they are, as events of their own`.

## Google

Until 1.5.2, "Choir" on a Google calendar, Tue 9 Mar dragged to Wed 10 Mar with "All events…": that drag rewrites the rule, so the dialog says what's unavailable and what isn't:

```
Move all events · Choir
On a Google calendar, changing which days this repeats on comes in a later version.
Move only this one, or change its time or details for all events.
                                                              Only this one    Cancel
```

The same series moved from 19:00 to 19:30 for all events is a plain edit Google applies itself: "Weekly on Tuesday, now at 19:30 · Edited days are left to Google, and this can't be undone."

From 1.5.2, half done: "Choir" on a Google calendar, "This and following" from Tue 9 Mar dragged to Wed 10 Mar. The pending row records the plan, its steps, and the complete state of "Rehearsal" on 13 Apr before anything that could remove it at Google. The steps: insert the new series (Wednesdays from 10 Mar, with an id the app chose), end the old one (UNTIL 2 Mar), move "Rehearsal" from 13 Apr to the new series' 14 Apr. Google refuses the second. The app reads Google back, stores what's there, and Review shows:

```
Half done at Google · Choir
Wednesdays from 10 Mar are at Google, but the Tuesday series wasn't ended, so from 9 Mar both show.
'Rehearsal' on 13 Apr hasn't moved yet.
                                                                         [Finish]   [Put back]
```

Had the insert's answer been lost, the retry would have found the series under its chosen id instead of making a second. Had someone renamed the new Wednesday series at Google since, Put back would leave it as it is and say so.

## The Grid

Every family by every edit, in short words: "follows" is the drag table's rule, "readings" means the dialog offers its row's readings, "same days" means keys keep their days, "split" is the following rows, "own kept" means edited days keep what they own. † marks a cell where today's code differs. On a Google calendar until 1.5.2, only the cells that keep the rule as written are offered (Google, as a Contract).

| Family | Drag | Time | All-day | Zone | Following drag | Following edit | Delete following | Editor, same rule | Editor, new rule | Delete this | Attendance following |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Daily | follows | same days, own kept | own kept † | two readings | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Weekly bare | follows | same days, own kept | own kept † | two readings | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Weekly one day | follows | same days, own kept | own kept † | two readings | follows † | split † | split † | as edit † | new rule, drag reading † | skip | split † |
| Weekly list | follows, two readings | same days, own kept | own kept † | two readings | follows, two readings † | split † | split † | as edit † | new rule † | skip | split † |
| Weekly INTERVAL=2 list | follows †, two readings | same days, own kept | own kept † | two readings † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Monthly bare | follows †, month-end readings | same days, own kept | own kept † | two readings † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Monthly day 1 to 28 | follows †, month-end readings | same days, own kept | own kept † | two readings † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Monthly day 29 to 31 | follows †, gains named | same days, own kept | own kept † | two readings † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Monthly clamped | follows, readings | same days, own kept | own kept | two readings | follows | split | split | as edit | new rule | skip | split |
| Monthly from the end | follows †, two readings | same days, own kept | own kept † | two readings † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Monthly day list | follows †, two readings | same days, own kept | own kept † | two readings † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Monthly nth weekday | follows † | same days, own kept | own kept † | two readings † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Monthly nth list | follows †, two readings | same days, own kept | own kept † | two readings † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Monthly set position | follows †, refused off the set | same days, own kept | own kept † | two readings † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Yearly bare | follows † | same days, own kept | own kept † | two readings † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Yearly month and day | follows †, 29 Feb readings | same days, own kept | own kept † | two readings † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Yearly nth weekday | follows † | same days, own kept | own kept † | two readings † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Yearly month list | follows †, refused across the year | same days, own kept | own kept † | two readings † | follows † | split † | split † | as edit † | new rule † | skip | split † |
| Other capabilities | by its parts † | by its parts | by its parts † | clock: same days; moment: by its parts † | by its parts † | split † | split † | as edit † | new rule † | skip | split † |

A split works for every family, other capabilities included: a rule restarted at one of its own occurrences gives the same days from there on. Probed with the 1st and 3rd weekday, every other Sat and Sun, every two months on the 10th and 20th, March and September's second Tuesday, and the last weekend day, each restarted mid-period.

## The Harness's Causes, Resolved

The harness's twelve open causes, each by the cell that settles it:

- **Cause 2, plain-rule.** `FREQ=MONTHLY` from 30 Jan, 30 Apr dragged to 1 May: the monthly bare row, read as day 30; the landed 1st is one reading, every 1st, k = 1. Start 1 Feb, days 1 Feb, 1 Mar, 1 Apr, 1 May, and the plan says it adds every 1 Mar (February had no 30th to move there); today every 31st from 31 Jan.
- **Cause 3, keep-snap.** `BYMONTHDAY=1,15` from 1 Dec, 15 Dec dragged to 16 Dec: "Move every 15th to the 16th" (the 1st and 16th) or "Shift the whole monthly pattern" (the 2nd and 16th), nothing lost either way. `BYMONTHDAY=-2` dragged from 30 Aug to 5 Sep: the 26th-last day is past the seventh-last, so one reading, every 5th, k = 1. `BYMONTH=3,9` dragged from 15 Mar to 16 Apr: every 16 April and October.
- **Cause 4, biweekly-list.** Every other Sat and Sun from Sat 5 Dec 2026 dragged a day: "Move every Saturday to Sunday" lands on Sunday, already listed, and is disabled; the shift reading is `BYDAY=MO,SU;WKST=TU`, days 6, 7, 20, 21 Dec (probed).
- **Cause 5, editor-rule.** `FREQ=WEEKLY;BYDAY=MO;COUNT=4`, a following save from 11 Jan to 12 Jan with the same rule sent: the unchanged-rule row ignores it, so the old part is 4 Jan, the new part Tuesdays from 12 Jan with COUNT=3.
- **Cause 6, stale-skips.** `FREQ=DAILY;COUNT=10` with 5 Mar skipped, renamed from 3 Mar: the old part's skips are only those before 3 Mar.
- **Cause 7, yearly-nth-start.** `BYMONTH=11;BYDAY=3SU;COUNT=15` from 15 Nov 2026, 19 Nov 2028 dragged to 17 Dec: the yearly nth row, k = 0, start 20 Dec 2026 (the earliest image, the old start's), fifteen third Sundays of December, the 2034 and 2040 skips on theirs.
- **Cause 8, google-parity.** Until 1.5.2 a Google series is offered only the edits Google applies itself, so no rule-rewriting plan is sent to Google; from 1.5.2 the read-back confirms Google holds the plan, so a bounded daily series dragged a month keeps its last day there too.
- **Cause 9, rule-change-orphans.** Every 19th changed to Tuesdays and Thursdays in the editor: the edited 19 Jun 2028 (a Monday) becomes an event of its own on that day, named in the plan, or is deleted if the person picks that.
- **Cause 10, count-span.** `BYMONTHDAY=31;COUNT=6` from 31 Jan with 31 Oct edited, 31 Mar dragged to 1 Apr: every 1st, k = 1, start 1 Feb. Keep 6 events (preselected): ends 1 Jul, and the edited 31 Oct, outside, becomes an event of its own, "6 in the series + 1 separate". Keep the end: COUNT=10, the edit on 1 Nov (A Count, above).
- **Cause 11, month-multiple.** `BYMONTHDAY=1;COUNT=11` from 1 Aug with 1 Aug moved to 31 Jul, 1 Feb dragged to 1 Mar: k = 1, start 1 Sep, the moved day keyed on 1 Sep and still showing on its own 31 Jul ("Keep 31 Jul"; with "Move to 31 Aug", there). `BYDAY=4SU;UNTIL=20270725`, 23 May dragged to 27 Jun: k = 1, every fourth Sunday from 28 Mar to 22 Aug, six occurrences as before.
- **Cause 12, until-type.** An all-day `BYDAY=4TU;UNTIL=20271228` made timed at 10:00 Auckland: UNTIL becomes the last occurrence's instant, so 28 Dec 2027 10:00 is kept.
- **Cause 13, until-first-phantom.** Every 2nd (all-day) split at 2 Jun 2028: the old part's UNTIL is 20280502, its last occurrence, and expands without the phantom 1 Jun (probed: `UNTIL=20270601` yields 1 Jun, `UNTIL=20270502` doesn't).

## The Reviewers' Cases

- **External F1.** Every 30th dragged from 30 Jan to 1 Feb, 30 Apr edited, 30 Jun skipped: one reading, every 1st, k = 1, the edit on 1 May, the skip on 1 Jul, and the plan names the gain: every 1 Mar (there was no 30 Feb before it).
- **Round one, finding 1.** Every last day dragged from 31 Jan to 1 Feb: 1 Feb is the 28th-last day, so one reading, every 1st from 1 Feb; the edited 31 Mar is the 1 Apr edit, the skipped 30 Apr the skipped 1 May. Today it opens on 28 Feb and 31 Jan is gone.
- **Round one, finding 2.** `BYMONTHDAY=28;UNTIL=20270528T235959Z` dragged to 1 Mar: UNTIL becomes the latest image, 1 Jun, written as that occurrence; the count is unchanged, so there's no bound choice.
- **Round one, finding 3.** The 1st and 15th dragged from 1 Jan to 2 Jan with 15 Jan edited: "Move every 1st to the 2nd" gives the 2nd and 15th with the edit still on 15 Jan; "Shift the whole monthly pattern" gives the 2nd and 16th with the edit on 16 Jan.
- **Rounds one and two, nth weekdays.** `2TU` dragged a day is `2WE`; `4TH` dragged to Fri 29 Jan is `-1FR`; yearly `BYMONTH=3;BYDAY=2TU` dragged a day is `2WE` from 10 Mar 2027.
- **Round two, finding 4, and round three, finding 1.** "This and following" carries the later edited days and the split day takes the edit's fields: the following rows.
- **Round two, finding 5, and round three, finding 3.** The editor's rule: the unchanged-rule row.
- **Round three, finding 4.** One entry for the split and the answer: attendance following.
- **Probes for the first draft.** A day moved earlier than the split but keyed after it goes with the following part, and the plan says so. A rename with "All events" left an edited day that only moved its time called by the old name; with own fields its title is inherited, and it takes the new one.
- **Second design review.** Member identity: the Payday 1st moved to the 20th and the Swim Mondays moved to Thursdays (Lists). Boundaries with no image: the Tutoring end and start (Bounds). Readings with today's dates in common: Book swap (Month Ends). Adoption across a boundary, and the date apart from the time: Sunday run, Book club, Choir (From an Edited Day). Ownership across a sync: the table in Own Fields. Composed capabilities and two times colliding on one moment (Capabilities). Google until 1.5.2, and its recovery (Google). The corrections it found: "6 in the series + 1 separate", 29 Feb 2028 for the clamped 30th, and the months a "every 31st" moved to the 1st gains (1 Mar, 1 May, 1 Jul, 1 Oct, 1 Dec).
- **The display cap.** A moved UNTIL and a split's COUNT read from an expansion capped at 1,001 occurrences: Water plants and Stretch (Bounds).

## Prediction Cases

The cases for the prediction exercise (Acceptance in the design). For each: show the series on a month, describe or perform the change with the dialog hidden, and ask what the next three dates will be and what happens to any edited day; then show the dialog and ask whether it says what they expected. "The dialog says" is the model's answer, preselected reading first, and is the wording the dialog must use; the last column is filled in when the exercise is run, with the number of people and what most expected.

| # | Series shown | The change | The dialog says | People expected |
|---|---|---|---|---|
| 1 | Choir, weekly on Tuesday at 19:00 | Tue 9 Mar dragged to Wed 10 Mar, All events | Weekly on Wednesday at 19:00. Next Wed 10 Mar, Wed 17 Mar, Wed 24 Mar | To be run |
| 2 | Choir, with 'Rehearsal' on Tue 13 Apr | The same drag | 'Rehearsal' Tue 13 Apr moves to Wed 14 Apr, keeps its title and place | To be run |
| 3 | Water plants, every 2 days from Mon 1 Mar | 5 Mar dragged to 6 Mar, All events | Every 2 days, from 2 Mar. Next 6 Mar, 8 Mar, 10 Mar | To be run |
| 4 | Gym, weekly on Monday, Wednesday and Friday | Wed 10 Mar dragged to Thu 11 Mar | (•) Move every Wednesday to Thursday: Monday, Thursday and Friday ( ) Shift the whole weekly pattern: Tuesday, Thursday and Saturday | To be run |
| 5 | Book club, monthly on the 15th | 15 Mar dragged to 30 Mar | (•) On the 30th, or the last day in shorter months ( ) On the 30th, skipping months without one | To be run |
| 6 | Book club, monthly on the 15th | 15 Mar dragged to 31 Mar | (•) On the last day ( ) On the 31st, skipping months without one | To be run |
| 7 | Rent due, monthly on the last day | 31 Mar dragged to 30 Mar | (•) On the second-last day ( ) On the 30th, or the last day in shorter months ( ) On the 30th, skipping months without one | To be run |
| 8 | Payday, the 1st and 15th | 15 Mar dragged to 16 Mar | (•) Move every 15th to the 16th: the 1st and 16th ( ) Shift the whole monthly pattern: the 2nd and 16th | To be run |
| 9 | Board meeting, monthly on the second Tuesday | Tue 9 Feb dragged to Wed 10 Feb | Monthly on the second Wednesday. Next 10 Feb, 10 Mar, 14 Apr | To be run |
| 10 | Dinner club, monthly on the fourth Thursday | Thu 28 Jan dragged to Fri 29 Jan | Monthly on the last Friday. Next 29 Jan, 26 Feb, 26 Mar | To be run |
| 11 | Leap party, yearly on 28 Feb | 28 Feb 2028 dragged to 29 Feb | (•) On the last day of February ( ) On 29 February, only in leap years | To be run |
| 12 | Book club, monthly on the 1st, its 1 Jan moved to 31 Jan | 31 Jan dragged to 1 Feb, All events | (•) Monthly on the 2nd, January's on Mon 1 Feb ( ) Monthly on the 1st, every event a month later | To be run |
| 13 | Choir, its Tue 9 Mar moved to Thu 11 Mar | Thu 11 Mar dragged to Fri 12 Mar, All events | (•) Weekly on Friday ( ) Weekly on Wednesday, this one on Fri 12 Mar | To be run |
| 14 | Choir at 19:00, its 16 Mar at its own 19:30 | 16 Mar dragged to 20:00, All events | (•) Every event at 20:00 ( ) Every event half an hour later, this one at 20:00 | To be run |
| 15 | Piano lesson with Alex Example, monthly on the 31st, 6 events, 31 Oct a recital | 31 Mar dragged to 1 Apr | (•) Keep 6 events, ends 1 Jul: 6 in the series + 1 separate (the recital) ( ) Changes 6 events to 10, ends 1 Nov | To be run |
| 16 | Team call, Monday 18:00 Los Angeles | Zone changed to Berlin | (•) 18:00 in Berlin ( ) The same moment: Tuesday 03:00 in Berlin | To be run |
| 17 | Choir, weekly on Tuesday, two Rehearsals | Changed to Monday and Thursday | Two edited days become events of their own: 'Rehearsal' on 13 Apr and 'Rehearsal' on 11 May | To be run |
| 18 | Choir, weekly on Tuesday | Tue 9 Mar dragged to Wed 10 Mar, This and following | Weekly on Tuesday until 2 Mar, then weekly on Wednesday from 10 Mar | To be run |
| 19 | Choir, weekly on Tuesday, its Tue 23 Mar moved to Fri 26 Mar | Tue 9 Mar dragged to Wed 10 Mar, All events | Weekly on Wednesday. (•) Keep Fri 26 Mar ( ) Move to Sat 27 Mar | To be run |
| 20 | Payday, the 1st and 15th, its 15 Apr 'Payday, bonus' | 1 Mar dragged to 20 Mar, All events | Move every 1st to the 20th: the 15th and 20th, starts 15 Jan. 'Payday, bonus' stays on 15 Apr. Shifting the whole pattern is disabled: the 15th would move past the end of its month | To be run |
| 21 | Sunday run, weekly on Sunday, its Sun 7 Mar moved to Mon 8 Mar | Mon 8 Mar dragged to Tue 9 Mar, All events | (•) Tuesday becomes its day: every event 2 days later, from Tue 5 Jan ( ) Weekly on Monday, this one on Tue 9 Mar | To be run |
