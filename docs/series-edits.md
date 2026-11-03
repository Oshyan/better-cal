# Changing a Repeating Event

Changing every event in a series moves days you can't see, so Better-Cal shows what the change will do before it does it. This is the one place the app asks first; everywhere else the toast's Undo is the answer. The model behind it is in [design/series-model.md](design/series-model.md).

## When It Asks

- **Drag or resize with All events.** Drop an event of a series and pick **All events…** on the chip. The block stays where you dropped it, drawn as an outline, and nothing else on the calendar moves until you confirm.
- **Save in the editor with All events in series.** A change nothing else depends on (a new title when no edited day has its own) saves at once. Anything else shows the same panel, beside the editor.
- **This event** acts at once, as before. **This and following** and the deletes keep their old behavior for now; their preview comes in 1.5.1.

## What the Panel Says

The panel opens in one place every time: at the bottom right of the calendar on a desktop (beside the editor or the event panel when one is open), and as a sheet at the bottom of the screen on a phone. The dropped event stays where you put it, drawn as an outline. Each part of the plan has its label at the left:

- **Change:** the rule before and after, with its end: "Weekly on Tuesday at 19:00, now weekly on Wednesday at 19:00, no end, as before". When a change can mean more than one thing (dragging Wednesday's class to Thursday in a Monday, Wednesday and Friday series can move every Wednesday, or shift the whole pattern a day), each choice is a row under it with its own next dates and what it drops or adds; a choice of time zone says which events change their clock. The first one is what Enter does. A choice between keeping the count and keeping the end works the same way.
- **Next:** the next dates as they'll show, with edited days marked.
- **Size:** how many events there will be and how many of them are skipped and edited: "6 events, 1 skipped, 2 edited", or "All events, ..." for a series with no end.
- **Edited:** every edited day is named, one row each: the day, then what happens to it ("moves to Wed 14 Apr; keeps its title and place"), then its choice when it has one. Days that do something of their own come first. Days that go with their events the same way share one row, "38 edited days, Tue 6 Apr to Tue 4 Jan 2028", and **Show 38 days** lists each.
- **Skipped:** each skipped day and where it goes.
- **Refusals:** a change the series can't take says why, and its button is **Move only this one**, to change just the day you dragged. A reading that can't apply is shown greyed out with its reason.

Every date names its weekday, and its year only when it isn't this one. The button says what it does: **Move 6 events**, **Rename 6 events**, **Change 6 events** (or "all events" with no end); it stays off until the plan has arrived. **Cancel** or Esc puts everything back, and a press outside the panel does the same. **Change scope** at the top goes back to the choice of This event, This + following or All events. If the series changed meanwhile (another tab, a sync), the panel shows the new plan and asks again.

## Creating a Series

Quick add and the editor's Repeat row show the rule in words with its first dates and its end: "Monthly on the 30th, or the last day · Sat 30 Jan, Sun 28 Feb, Tue 30 Mar · 6 events". When the first event isn't on the date you typed (a series on Tuesdays started on a Saturday), they say where it starts.

## Edited Days

A day you changed on its own keeps what you changed (its date, title, place and so on) when the series changes later. The editor lists those fields under the title for that day ("Edited day of Choir (weekly on Tuesday). Its own: date, title"). Click one to give it back to the series, or **Use series values** for all of them; that changes only this day and can be undone. The toast names the fields that went back; if the server didn't change the day, it says so instead. A day with nothing left of its own is an ordinary event of the series again.

A change to an edited day from another app (a phone's calendar over CalDAV, a feed) counts only what it changed: moving the day to Thursday there makes its date its own and leaves what it already had, its own time say, as it was.

## Google Calendars

Until 1.5.2, a series on a Google calendar takes only changes that keep its rule as written: a new time, length or end, or new details for all events. A drag that would change which days it repeats on says so and offers **Move only this one**. What Google does with edited days on those changes hasn't been checked yet, and they can't be undone.
