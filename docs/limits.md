# Scale and limits

## Who it's for

Better-Cal is meant to replace a standard, individual, one-person Google Calendar. It isn't meant for enterprise use or for extremely heavy users (thousands of events a week, dozens of busy shared calendars, a whole team on one install). If you need that, feel free to fork it; the limits below are where you'd start.

For a sense of what "one person" means here: on the calendar I run it on every day, a five-month month-view window holds roughly 3,700 occurrences and comes back from the server in about 160 ms.

## Limits you might notice

- **1,000 occurrences per repeating series per request.** That covers a daily event across the widest window the app ever asks for (two years), so normal use never hits it. A series that does (something hourly, say) shows its first 1,000 occurrences in that range, and the app tells you which series was cut short instead of quietly showing fewer. API callers get the same report as a `capped` list of event ids.
- **Two years per request.** The events API refuses a window longer than two years instead of cutting it short; ask for longer spans in pieces. The app does this on its own.
- **About ten years either way of today in the scrolling views.** Month, week and day views scroll roughly ten years back and forward from today. Jumping to a date further out stops at the edge. Search and the API aren't limited this way.
- **Busy days in month view.** A day shows about seven events on a tall window (fewer on a short one or a phone), then "+N more"; trips and people's away times stack two deep per week before they overflow into the day's expanded list. Nothing is dropped, it just takes a click.
- **Outbound feeds hold 5,000 events.** A feed you publish covers the past year onward (plus every repeating series), in date order, up to 5,000 events. Past that, the furthest-out events are left off, and the feed doesn't say so yet. A feed made from a saved search holds up to 500 matches.
- **Repeating rules:** an interval up to 1,000 and a COUNT up to 100,000.
- **Reminders:** up to five per event, at most four weeks ahead.

## Limits you can change

Imports, subscribed feeds, CalDAV objects, incoming email, skipped occurrences, description length and filter work all have caps that protect the server from oversized or hostile input. Each has a sensible default for one person and can be raised in `.env`; [`.env.example`](../.env.example) lists them (the `BETTERCAL_LIMIT_*` settings) with their defaults. The main ones:

- An uploaded `.ics` file: 25 MiB and 20,000 events.
- One subscribed feed: 20,000 events per poll (and 20 MiB, which isn't configurable).
- One CalDAV object: 1 MiB, with up to 500 changed occurrences.
- One incoming email: 5 MiB; up to 200 events in an emailed invitation.
- An event description: 65,535 characters, after which it's cut.

An import that runs into one of these says which setting raises it.

## Background work

The worker runs every minute and spreads slow work across runs: up to ten emails per run, and plain-language filters and ranking a few LLM calls at a time. On a busy day that means a new invitation can take a few minutes to appear, not that anything is skipped.
