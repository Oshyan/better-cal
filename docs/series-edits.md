# Changing a Repeating Event

Changing every event in a series moves days you can't see, so Better-Cal shows what the change will do before it does it. This is the one place the app asks first; everywhere else the toast's Undo is the answer. The model behind it is in [design/series-model.md](design/series-model.md).

## When It Asks

- **Drag or resize with All events.** Drop an event of a series and pick **All events…** on the chip. The block stays where you dropped it, drawn as an outline, and nothing else on the calendar moves until you confirm.
- **Save in the editor with All events in series.** A change nothing else depends on (a new title when no edited day has its own) saves at once. Anything else shows the same card over the editor's buttons.
- **This event** acts at once, as before. **This and following** and the deletes keep their old behavior for now; their preview comes in 1.5.1.

## What the Card Says

- **The rule, before and after:** "Weekly on Tuesday at 19:00, now weekly on Wednesday at 19:00", with the end beside it ("No end, as before", "6 events, ends 1 Jul").
- **Readings,** when a change can mean more than one thing: dragging Wednesday's class to Thursday in a Monday, Wednesday and Friday series can move every Wednesday, or shift the whole pattern a day. Each choice shows its own next dates and end, and under it what it drops or adds; a choice of time zone says which events change their clock. The first one is what Enter does. A choice between keeping the count and keeping the end works the same way.
- **The next dates** as they'll show, with edited days marked. Dates name their year only when it isn't this one.
- **Edited and skipped days:** every one is named. A day that does something of its own (stays at its own date, becomes an event of its own, is deleted) or has a choice gets its own line, first, with the choice on that line. Days that go with their events the same way share one line, "38 edited days from 6 Apr to 4 Jan 2028 move to Wednesday; each keeps its title and place", and **show them** lists each. Other choices (a gain at the start, own titles for a rename) sit on their own line below.
- **Refusals:** a change the series can't take says why, with **Only this one** to change just the day you dragged. A reading that can't apply is shown greyed out with its reason.

**Confirm** stays off until the plan has arrived. **Cancel** or Esc puts everything back, and a press outside the card does the same. The switch at the top changes between **This and following** and **All events**. If the series changed meanwhile (another tab, a sync), the card shows the new plan and asks again.

On a desktop the card opens beside the drop point, below the dragged block or above or beside it when there's no room, so the block stays in sight. On a phone the card is a sheet at the bottom of the screen and scrolls inside.

## Creating a Series

Quick add and the editor's Repeat row show the rule in words with its first dates and its end: "Monthly on the 30th, or the last day · Sat 30 Jan, Sun 28 Feb, Tue 30 Mar · 6 events". When the first event isn't on the date you typed (a series on Tuesdays started on a Saturday), they say where it starts.

## Edited Days

A day you changed on its own keeps what you changed (its date, title, place and so on) when the series changes later. The editor lists those fields under the title for that day ("Edited day of Choir (weekly on Tuesday). Its own: date, title"). Click one to give it back to the series, or **Use series values** for all of them; that changes only this day and can be undone. The toast names the fields that went back; if the server didn't change the day, it says so instead. A day with nothing left of its own is an ordinary event of the series again.

A change to an edited day from another app (a phone's calendar over CalDAV, a feed) counts only what it changed: moving the day to Thursday there makes its date its own and leaves what it already had, its own time say, as it was.

## Google Calendars

Until 1.5.2, a series on a Google calendar takes only changes that keep its rule as written: a new time, length or end, or new details for all events. A drag that would change which days it repeats on says so and offers **Only this one**. What Google does with edited days on those changes hasn't been checked yet, and they can't be undone.
