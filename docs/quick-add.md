# Quick add

Quick add turns a line of text into an event. Open it with **c** or the Quick add button on desktop, or **New** on a phone. The card shows what it understood as you type, and you can fix anything there before you press Create, or open the full editor with **More options**.

When it has to assume something you didn't type, the card says so in muted text beside the date or time it set: "at 7 read as 7:00 PM", "Mar 3 read as next year", "tomorrow, as 9:00 AM has passed today", or "no time read, so the next hour". If it guessed wrong, change that date or time right there; nothing is shown when nothing was assumed.

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
| `Meditation 7am floating` | 7am wherever you are, on any device's clock ("7am local time" reads the same) |
| `Vitamins every day 8am local time` | Daily at 8am on whatever clock you're on |

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
- **Time ranges and lengths:** 7-9pm, 11am-1pm, 9am-noon, 6pm to midnight, 10pm to 6am, for 2 hours, for 90 min.
- **Zones:** EST, PT, GMT, UTC, CET and others, after a time. Two-letter ones only in capitals, and not before an ordinary word ("3pm CT scan"); "3pm ET with Sam" is fine.
- **All day:** "all day", or any date with no time.
- **Several days:** Aug 3-7, 3-7 Aug, Dec 30 to Jan 2, Mon-Fri, 3 weeks long, week-long, a length at the end ("Lisbon trip 2 weeks"), Fri 5pm to Sun 2pm, Mon-Fri 9am-5pm (one stretch, Monday morning to Friday evening, when the end time is later in the day than the start). A date range with a time range reads the same way: "Conference Aug 3-7 9am-5pm" is one stretch, and "Shift Aug 3-7 10pm-6am" repeats each night from the 3rd to the 7th. With one time ("Hotel Aug 9-12 check-in 3pm") the range stays all day.
- **Repeats:** daily, weekdays, weekends, every Tuesday, Mondays and Thursdays, every other week, every 3 days, monthly, yearly, first Monday of the month, every 1st and 3rd Monday, every 1st and 15th, last day of the month, every Jan 27. A day range with one time repeats ("Standup Mon-Fri 9am"), so does one with a time range that crosses midnight ("Shift Mon-Fri 10pm-6am", each night to the next morning), and so does "daily" with a range.
- **Repeat start and end:** starting Nov 3, until Dec 15, for 6 weeks, 10 times.
- **Alerts:** alert 30 minutes, remind me 15 min before, with a 1 hour reminder. Up to four weeks.
- **Same clock time in any zone:** "local time" or "floating" right after the time ("6:30am local time", "7am floating", the word iCalendar and Apple's Calendar use) keeps the event at that clock time wherever you are: a routine at 7 is at 7 at home and at 7 on a trip, and so are its reminders. A calendar can give this to all its new timed events (its settings, "New timed events keep their clock time in any zone"). Never all-day events or invitations.
- **People and places:** "with Sam", "w/ Sam and Alex", and "at Luna" or "@ Luna" at the end for the place.
- **Away and busy times:** "Sam is away Aug 10-15", "Sam busy tomorrow".
- **Links:** an event page's link, pasted or shared from a phone, becomes that event. See [Event Links](#event-links).

## Paste Anywhere

On a computer you don't have to open quick add first: copy an event's link, or a message like "party at mine tomorrow 8pm", and press Cmd+V (Ctrl+V on Windows) anywhere on the calendar. Quick add opens with it already read; Enter adds it, Esc leaves it. A Google Calendar link opens as that event in the editor, and a calendar's `.ics` or `webcal://` address opens Subscribe. Copy part of a web page or an email whose title links to the event, and that link comes along too.

It only listens on the calendar itself: a paste into a text box, or while the editor or a dialog is open, does what it always did. On a phone, paste into quick add's box, or share the link or text to Better-Cal.

## Event Links

Paste or share an event's link into quick add, alone or with a few words around it, and Better-Cal reads the page and fills in the title, time, place and the event's own link for you to check. Nothing is saved until you do.

These are the links it handles on purpose, and how each was last checked:

| Site | Link looks like | Read from | Last checked |
|---|---|---|---|
| Luma | `lu.ma/…`, `luma.com/…` | the event data on the page | 2026-10-27 |
| Partiful | `partiful.com/e/…` | the event data on the page | 2026-10-27 |
| Eventbrite | `eventbrite.com/e/…` | the event data on the page | 2026-10-27 |
| Meetup | `meetup.com/<group>/events/…` | the event data on the page | 2026-10-27 |
| Pie | `plan.getpie.app/plans/…` | Pie's public details for the plan (its pages carry no date) | 2026-10-27 |
| Google Calendar | `calendar.google.com/calendar/render?action=TEMPLATE…` | the link itself, including repeats | 2026-10-27 |

Any other page works the same way when it publishes standard event data (schema.org `Event`, which most ticketing and events sites do) or has an "Add to Google Calendar" link on it. When it has neither and an AI model is set up, the model reads the page's text, but only if there's a date in it. A page behind a sign-in has nothing to read, and the card says so.

Not events: a calendar's `.ics` or `webcal://` address opens Subscribe instead.

## Limits

- An ambiguous phrase gets a best guess, shown on the card. "Retreat Fri-Sun 4pm" repeats at 4 on those days; for one event, write "Retreat Fri 4pm to Sun".
- "In 2 hours" isn't read; use a clock time.
- "9-5" isn't read as hours; write "9am-5pm".
- A short day name or a day range at the very start stays in the title ("Wed planning", "Mon-Fri schedule review") unless a time follows it.
