# Calendar roles and event relationships

Most calendars know one thing about an event: it's on your calendar. Better-Cal knows what the event is *to you*. Every event has a relationship, every calendar has a role, and the role gives an event its relationship until you say otherwise.

## The Five Relationships

| Relationship | Meaning | How it's drawn |
|---|---|---|
| **Planned** | I'm doing this. | The normal look, no mark. |
| **Maybe** | Tentative. I might do this. | The normal look plus a dashed outline (in the calendar's color on a chip, in the text color on a filled bar or block). |
| **Available** | An opportunity I haven't picked up. | Quiet: muted title, normal size. |
| **Context** | Information, not a plan: sunset, tides, holidays, a friend's schedule. | Never a chip. Small tokens in the day's header; a timed one is a dashed hairline on the timeline. See [Where Context Is Drawn](#where-context-is-drawn). |
| **Hidden** | I don't want to see this. | Not drawn. |

Planned is the default for your own calendars, Available for things you subscribe to. Context is never chosen per event: it's what a whole calendar is.

## The Three Calendar Roles

A role says what a calendar is to you, and sets the default relationship for every event on it.

| Role | What it means | Default relationship | Typical calendars |
|---|---|---|---|
| **Mine** | Things I do. | Planned (Maybe if the event is tentative) | Your own calendars, a shared trip you're on, tasks |
| **Opportunities** | Things I could do. | Available | Event feeds, community calendars, Luma, Partiful, Meetup |
| **Context** | Information. | Context | Weather, tides, sunset, holidays, other people's schedules |

New local calendars are Mine, subscriptions are Opportunities, and plugin calendars are Context. A connected Google calendar starts by what it is: your own as Mine, one shared with you or copied from a feed as Opportunities, Google's holidays and birthdays as Context. Change any of them in the calendar's settings under "What this calendar is". Every event on the calendar follows the new role immediately; nothing is rewritten.

## Changing One Event

Open an event (popover or full detail). The **For me** dropdown lists what it can be, depending on its calendar's role:

- On a **Mine** calendar: Planned or Maybe.
- On an **Opportunities** calendar: Available, Maybe, Planned or Hidden.
- On a **Context** calendar: nothing to choose.

On a repeating event it asks which occurrences, as every other change to a series does: this one, this and following, or the whole series.

In the agenda view, each row from a subscribed calendar has three small buttons (Maybe, Planned, Hide); on a phone, swipe the row left or tap its grip.

Thumbs up and down on feed events are separate. They train the ranking and say nothing about whether you're going.

## What Stays Private and What Leaves the App

The relationship is your side only. Nobody is notified and nothing is RSVP'd; replying to an organizer is a separate action, labeled RSVP.

One thing does travel. On a **Mine** calendar, Maybe is stored as the event's status, `TENTATIVE` in iCalendar terms, and Planned as `CONFIRMED`. That's the standard way to say "tentative", so:

- CalDAV clients (Apple Calendar, Thunderbird, DAVx5) see it and can set it.
- On a Google calendar you write to, Maybe becomes tentative at Google (drawn hatched there). Like every Google write, it can't be undone from Better-Cal, and the prompt says so.

On an **Opportunities** calendar the relationship is a private mark on your copy of the event. Feeds and Google polls never overwrite it.

## The Show Filter

The toolbar's **Show: all** menu has a checkbox per relationship. Any combination works, in every view. The button names the current state ("Show: no context", "Show: planned only", "Show: planned + maybe") and tints when a filter is on. The choice is remembered per browser, and a saved view captures it along with the text filter and visible calendars, so "only planned, work calendars" can be one saved mode.

Hotkeys toggle each kind: **p** planned, **m** maybe, **a** available, **x** context. The command palette has the same four plus "Show only planned events" and "Show all events".

A day where the filter hid something shows a small half-filled circle after its day number, in every view, with the count in its tooltip. In month and agenda views, clicking it clears the filter. Nothing disappears silently.

## Where Context Is Drawn

Context is consulted, not attended: the weather, the AQI, when the sun sets, whether the tide is low at a useful hour. It's the first thing to read about a day, so it lives where the day is named rather than in the list of things to do, and it never takes an event's slot.

- **Month:** as many tokens as fit beside the day number, then "+N", which opens the expanded day with context in its own section on top. A token is an icon for the kind of information plus the value: a cloud and "72/58" for a "72/58 fog" forecast, an air icon and "54" for "AQI 54 (Moderate)", a sunset icon and "7:10p". Hover for the full title, click for the event. On a phone the full month has no room for tokens, so the expanded day lists them (the 3-day view shows the first one).
- **Week and day:** all-day context as tokens in the column header. A timed context event is a dashed hairline at its minute with a small label, never a block: sunset at 7:10 is a moment, not a forty-minute appointment.
- **Agenda:** the day's tokens ride on the day heading.
- **Holidays:** all-day context from a calendar whose name says holiday is a label on the date, wherever a date is written ("Mon, Jan 1 · New Year's Day"), and a stripe across the top of the day's header in that calendar's color, so the day stands out even where its name doesn't fit. On a phone's full month and 3-day view the name is a small flag after the day number; tap it to see the holiday.
- **Other time zones:** a moment on a calendar in another zone (a sunset in a city you're not in) reads in its own zone with the zone named, "7:10p PDT", while its place on the timeline is still the true instant.
- **Icons:** a plugin sets one per event (`icon` in `syncEvents`; the host set includes sun, sunrise, sunset, air, tideHigh, tideLow, cloud, rain, snow, storm, thermometer, moon, flag, or any emoji). Feeds carry no icon, so the common shapes are recognized from the title (a high/low pair with a condition word, AQI, sunset, sunrise, high or low tide, a holiday calendar); anything else shows the calendar's hollow square with the words.

## Reading an Event's Look

Besides its relationship, an event's look can carry a few other states, and they combine (a maybe that already ended is outlined and faded).

| Look | Means |
|---|---|
| Normal, no mark | Planned, upcoming. |
| Dashed outline | A maybe. |
| Muted title | Available: an opportunity you haven't picked. |
| Faded italic | Context: information, not a plan. |
| Faded | Already ended. It comes back to full strength under the pointer. |
| Gold ring | Happening now. |
| "New" pill | Arrived on its own (a feed, email, import, an agent or a plugin) in the last day. |
| Struck through | Cancelled. |
| Title underlined in a color | Highlighted by one of your filters, in the color the filter names (the accent color if it names none). |
| Grayed out | Dimmed by one of your filters. |
| Stack icon and a count | Several similar events shown as one (near-duplicate grouping, on calendars where it's on). |
| Outlined, tinted band | A trip: it holds the events during it. |

With a mouse or trackpad, hovering an event shows a card with its full title, when it is, its calendar and its place, then a short italic line for each state that applies. For a highlighted or dimmed event, that line names the filter and where it matched: "Underlined: highlighted by your "dinner" filter (matched in the description)". A new keyword filter matches titles only unless you tick more fields, so words in a long imported description don't surprise you.

## Why It Works This Way

- **"What am I actually doing?"** is the question a calendar exists to answer, and with feeds and shared calendars it stopped being answerable at a glance. Roles and relationships make "only planned" one keystroke.
- **Maybe is a real state.** Not a reply to someone, not a note in a title: a status that survives sync and that other clients understand.
- **Information shouldn't look like plans.** Sunset and tides are the first thing to read about a day and noise in the list of things to do.
- **A default isn't a decision.** The role gives every event a sensible relationship the moment a calendar is added, so you only touch the events that differ.

## For Integrators

Every occurrence in the API carries `relationship`, and calendars carry `role`. See [api-contract.md](api-contract.md) for `POST /events/:id/relationship` and `PATCH /calendars/:id {role}`. The derivation is one pure function, `Events::relationship(attendance, status, role, answer)`, with these rules in order:

1. A context calendar is always `context`.
2. A hidden mark wins next.
3. A going or interested mark gives `planned` or `maybe`.
4. On a Mine calendar, your answer to a Google invitation decides when there is one (accepted is `planned`, anything else `maybe`); otherwise the event's status does: tentative is `maybe`, anything else `planned`.
5. Otherwise `available`.
