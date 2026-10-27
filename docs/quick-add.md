# Quick add

Quick add turns a line of text into an event. Open it with **c** or the Quick add button on desktop, or **New** on a phone. The card shows what it understood as you type, and you can fix anything there before you press Create, or open the full editor with **More options**.

It works without AI. If you've set up an AI model, Settings → General → **Natural language parsing** decides when it's asked: **Smart** (only when unsure, the default), **Always** or **Never**. Markers always win.

## Examples

| Type this | You get |
|---|---|
| `Dinner with Sam next Thursday 7pm at Luna` | Next Thursday 7 to 8pm, Sam linked, Luna as the place |
| `Dentist Friday 10am with a 1 hour reminder` | Friday 10am, alert an hour before |
| `Remind me tomorrow at 3pm to call the bank` | "Call the bank" tomorrow at 3pm, alert at 3 |
| `Flight Friday 9:40am EST` | 9:40 Eastern, shown on your clock |
| `Lisbon trip Aug 3-7` | All-day, August 3 to 7 |
| `New York trip 3 weeks long starting August 12th` | All-day, three weeks from August 12 |
| `Camping Fri 5pm to Sun 2pm` | One event, Friday evening to Sunday afternoon |
| `Workshop Mon-Fri next week` | All-day, Monday to Friday next week |
| `Standup Mon-Fri 9am` | Repeats every weekday at 9 |
| `Piano every Tuesday 4pm until Dec 15` | Weekly until December 15 |
| `Book club first Monday of the month 7pm` | Monthly on the first Monday |
| `Dinner with @Sam on Tuesday #food /friends` | Friends calendar, tagged food, Sam linked |
| `"Wacky Wednesday" Friday 8pm` | A Friday event called Wacky Wednesday |
| `Sam is away Aug 10-15` | An away time for Sam (someone in People) |

## Markers

Markers say something outright. Each counts only at the start of a word, so `7/31`, `w/ Sam`, web and email addresses are left alone. The full editor's text box takes them too.

Typing a marker lists what it can name, narrowing as you type: `/` your calendars, `#` your tags, `@` your people. Arrow keys move, Enter or Tab fills one in, Esc closes the list; Enter creates the event as usual when no list is showing. On a phone the choices sit in a row under the box.

| Marker | Example | What it does |
|---|---|---|
| `/calendar` | `/friends`, `/w` | Puts it on the calendar with that name, or the first one starting with it. A `/word` matching none stays in the title. |
| `#tag` | `#food` | Adds a tag. Needs a letter, so `Room #5` stays text. |
| `@person` | `@Sam`, `@"Sam Sample"` | Links a person and keeps the name in the title. A unique first name is enough; quote the whole name when two people share one. Someone new is asked about first. `@noon`, `@home` and `@work` mean "at". |
| `"quotes"` | `"Wacky Wednesday"` | Title text that's never read as a date or time. |

## What It Reads

- **Days:** today, tomorrow, tonight, Friday, next Friday, Fri, Aug 3, 8/3, 2026-08-15, the 15th. A date already past this year means next year.
- **Counted from now:** in 2 weeks, 3 days from now, 3 weeks from Monday, Friday after next, end of the month, this weekend, next weekend.
- **Times:** 7pm, 7:30 pm, 19:00, noon, midnight, "at 7" (1 to 7 are afternoon, 8 to 11 morning), this evening, Sunday evening.
- **Time ranges and lengths:** 7-9pm, 11am-1pm, 10pm to 6am, for 2 hours, for 90 min.
- **Zones:** EST, PT, GMT, UTC, CET and others, after a time. Two-letter ones only in capitals.
- **All day:** "all day", or any date with no time.
- **Several days:** Aug 3-7, Dec 30 to Jan 2, Mon-Fri, 3 weeks long, week-long, a length at the end ("Lisbon trip 2 weeks"), Fri 5pm to Sun 2pm.
- **Repeats:** daily, weekdays, weekends, every Tuesday, Mondays and Thursdays, every other week, every 3 days, monthly, yearly, first Monday of the month, last day of the month, every Jan 27. A day range with one time repeats ("Standup Mon-Fri 9am"), and so does "daily" with a range.
- **Repeat start and end:** starting Nov 3, until Dec 15, for 6 weeks, 10 times.
- **Alerts:** alert 30 minutes, remind me 15 min before, with a 1 hour reminder. Up to four weeks.
- **People and places:** "with Sam", "w/ Sam and Alex", and "at Luna" or "@ Luna" at the end for the place.
- **Away and busy times:** "Sam is away Aug 10-15", "Sam busy tomorrow".
- **Links:** a pasted Google Calendar event link becomes that event.

## Limits

- An ambiguous phrase gets a best guess, shown on the card. "Retreat Fri-Sun 4pm" repeats at 4 on those days; for one event, write "Retreat Fri 4pm to Sun".
- "In 2 hours" isn't read; use a clock time.
- "9-5" isn't read as hours; write "9am-5pm".
- A short day name or a day range at the very start stays in the title ("Wed planning", "Mon-Fri schedule review") unless a time follows it.
