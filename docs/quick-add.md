# Quick add

Quick add turns a line of plain text into an event: `Dinner with Sam next Thursday 7pm at Luna`. Open it with **c** or the Quick add button on desktop, or **New** on a phone. The card shows what it understood as you type (the date, times, repeat, alert, people, tags and calendar), and anything it got wrong can be changed right there before you press Create, or in the full editor with **More options**.

Better-Cal reads the text itself, with no AI needed. If you've set up an AI model, the **Natural language parsing** setting (Settings, General, Quick add) decides when it's asked: **Smart** (the default) only when the built-in reading is incomplete or unsure, **Always**, or **Never**. Either way, the parts below are read the same way, and the markers always win.

## A Few That Work

| Type this | You get |
|---|---|
| `Dinner with Sam next Thursday 7pm at Luna` | Dinner with Sam at Luna, next Thursday 7 to 8pm, Sam linked, Luna as the place |
| `Dentist Friday 10am with a 1 hour reminder` | Friday 10am, with an alert an hour before |
| `Remind me tomorrow at 3pm to call the bank` | "Call the bank" tomorrow at 3pm, with an alert at 3 |
| `Flight Friday 9:40am EST` | 9:40 Eastern, shown on your own clock |
| `Lisbon trip Aug 3-7` | One all-day event, August 3 to 7 |
| `New York trip 3 weeks long starting August 12th` | All-day, August 12 for three weeks |
| `Camping Fri 5pm to Sun 2pm` | One event from Friday evening to Sunday afternoon |
| `Workshop Mon-Fri next week` | One all-day event, Monday to Friday next week |
| `Standup Mon-Fri 9am` | Repeats every weekday at 9 |
| `Piano every Tuesday 4pm until Dec 15` | Repeats weekly until December 15 |
| `Book club first Monday of the month 7pm` | Repeats monthly on the first Monday |
| `Dinner with @Sam on Tuesday #food /friends` | On the Friends calendar, tagged food, Sam linked |
| `"Wacky Wednesday" Friday 8pm` | A Friday event called Wacky Wednesday |
| `Sam is away Aug 10-15` | An away time for Sam (someone already in People), not an event |

## Markers

When you want to say something outright instead of leaving it to the reading, use a marker. Each one counts only at the start of a word, so dates like `7/31`, `w/ Sam`, web addresses and email addresses are left alone.

| Marker | Example | What it does |
|---|---|---|
| `/calendar` | `/friends`, `/w` | Puts the event on that calendar: the same name, or the first whose name (or a word in it) starts with what you typed. Only calendars you can add to. A `/word` that matches none stays in the title. |
| `#tag` | `#food`, `#date-night` | Adds a tag. It needs at least one letter, so `Room #5` stays text. |
| `@person` | `@Sam` | Links a person and keeps the name in the title. `@Sam` finds Sam Sample when there's only one Sam; someone new is asked about before they're added. `@noon`, `@home` and `@work` read as "at". `@Sam's place` links Sam. |
| `"quoted"` | `"Wacky Wednesday"` | Title text that's never read as a date or time. Straight or curly quotes. |

The full editor's text box (the line at the top of a new event) takes the same markers.

## Everything It Reads

### Dates

- **Named days:** today, tomorrow, yesterday, the day after tomorrow, and tonight (this evening at 6).
- **Days of the week:** Friday, next Friday, this Friday, next week Thursday, and short names (Fri, Sat., thurs). A short name that starts a word in a name stays put ("Sun Valley", "Sat Nav"), and so does one that starts the title ("Wed planning") unless a time follows it ("Sat 10am brunch").
- **Dates:** Aug 3, August 3rd, 3 Aug, 8/3, 2026-08-15, the 15th. A date already past this year means next year.
- **Counted from now:** in 2 weeks, in 3 days, 3 days from now, 3 weeks from Monday, a week from Friday, Friday after next, the Saturday after next, end of the month, end of next month.
- **Weekends and weeks:** this weekend, next weekend, the weekend, all week next week.
- **"on" goes with its date:** "Party on Saturday" is "Party".

### Times

- **Clock times:** 7pm, 7:30 pm, 19:00, noon, midnight.
- **An hour on its own after "at":** "at 7" is 7pm, "at 11" is 11am (1 to 7 are the afternoon, 8 to 11 the morning, 12 is noon), and "at 2:30" is 2:30pm. The text saying morning or breakfast keeps it in the morning ("Breakfast at 7:30").
- **Time ranges:** 7-9pm, 11am-1pm, 11-1pm, 19:00-21:30, 10pm to 6am (overnight).
- **Times of day:** this evening, in the morning, and a time-of-day word at the end ("call mom Sunday evening"): morning is 9, afternoon 2pm, evening 6pm, night 8pm.
- **Lengths:** for 2 hours, for 90 min.
- **Zones:** EST, EDT, ET, CST, CT, MST, MT, PST, PT, AKST, HST, GMT, UTC, BST, CET and their summer forms, after a time ("9:40am EST"). The event is saved at that moment and shown on your own clock. Two-letter zones (ET, CT, MT, PT) count only in capitals and not before a word, so "3pm CT scan" is a scan.
- **All day:** "all day" makes it all-day even with a time in the text.

### Several Days

- **Date ranges:** Aug 3-7, Aug 3 to Aug 7, Dec 30 to Jan 2, from Aug 9 to Aug 18. En and em dashes work too.
- **Day ranges:** Mon-Fri, Monday through Friday, Fri-Mon, Thu-Fri next week. With no time it's one event over those days; with one time it repeats on those days (see Repeats). A range that starts the title ("Mon-Fri schedule review") stays in it unless a time follows right after it.
- **How long it lasts:** 3 weeks long, for 90 days, week-long, 2 months, and a length at the end ("Lisbon trip 2 weeks"). A length before a noun describes the thing ("2 week sprint review"). With "starting Aug 12" or "from Jan 5" it starts then.
- **A day and time to another:** Fri 5pm to Sun 2pm, Aug 3 9am to Aug 5 noon, Thu 10pm to Fri 6am.

### Repeats

- **Daily:** every day, daily, everyday, every morning (9am), every evening (6pm).
- **Weekdays and weekends:** every weekday, weekdays, workdays, every weekend, weekends.
- **Days of the week:** every Tuesday, Mondays and Thursdays, every Mon, Wed and Fri, every Monday to Friday, every Mon-Fri. A day range with one time repeats too ("Standup Mon-Fri 9am"), and "daily" with a day range repeats on those days ("Daily standup Mon-Fri").
- **Intervals:** every other Tuesday, every 2 weeks, every 2 weeks on Mon and Thu, every 3 days, weekly, biweekly, fortnightly, monthly, quarterly, yearly, annually, and `:weekly` (or `:daily`, `:monthly`, `:yearly`) as a shortcut.
- **In the month:** first Monday of the month, last weekday of every month, the 3rd Friday of each month, last day of the month, monthly on the 15th, the 15th of every month.
- **In the year:** every year on 12/23, every Jan 27.
- **Start and end:** starting Nov 3, beginning Monday, from Jan 21 to May 23, until Dec 15, ending on Friday, for 6 weeks, 10 times, and "next week" with a day range.

### Alerts

- **How long before:** alert 30 minutes, remind me 15 min before, notify me 2 hours ahead, with a 1 hour reminder, a 2 day alert. Up to four weeks; a longer one stays in the title.
- **Remind me to:** "Remind me tomorrow at 3pm to call the bank" makes the event "Call the bank" with an alert at its time.
- An alert from the text is the event's own reminder; otherwise the calendar's or your default reminders apply.

### People and Places

- **People:** "with Sam", "w/ Sam and Alex", "with the Examples" (a group keeps its article). Names that match someone in People link to them; a new name is asked about before it's added.
- **Places:** "at Luna Trattoria" or "@ Luna" (with a space) at the end becomes the place, and stays in the title as what the event is called. A number first ("at 5 Main St") is part of the title.
- **Away and busy times:** "Sam is away Aug 10-15", "Sam busy tomorrow", for someone already in People.

### Links

A pasted Google Calendar event link (the "add to calendar" kind) is read as that event: its title, times, place and description.

## Known Limits

- **Ambiguous phrases:** quick add makes a choice and shows it, rather than asking. "Retreat Fri-Sun 4pm" reads as a repeat at 4pm on those days; for one event that starts Friday at 4, write "Retreat Fri 4pm to Sun".
- **Hours from now:** "in 2 hours" isn't read; use a clock time.
- **Times without am or pm:** "9-5" isn't read as hours ("9am-5pm" is).
- **@ means a person:** for a place, use "at" or "@ " with a space ("Dinner @ Luna").
