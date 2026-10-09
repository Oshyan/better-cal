# Calendar roles and event relationships

Most calendars know one thing about an event: it is on your calendar. Better-Cal knows what the event is *to you*. Every event has a relationship, every calendar has a role, and the role is what gives an event its relationship until you say otherwise.

This page explains both, what they look like, how to change them, and what leaves the app.

## The five relationships

| Relationship | Meaning | How it is drawn |
|---|---|---|
| **Planned** | I am doing this. | The normal look. No mark at all. |
| **Maybe** | Tentative. I might do this. | The normal look plus a dashed outline (in the calendar's color on a chip, in the text color on a filled bar or block). |
| **Available** | An opportunity I have not picked up. | Quiet: muted title, normal size. |
| **Context** | Information, not a plan. Sunset, tides, holidays, a friend's schedule. | Never a chip. Small tokens in the day's header (month cell, week column, day panel, agenda heading), italic with a hollow rounded square in the calendar's colour; a timed context event is a dashed hairline at its minute on the timeline. See "Where context is drawn". |
| **Hidden** | I do not want to see this. | Not drawn. |

Planned is the default for your own calendars. Available is the default for things you subscribe to. Context is never chosen per event: it is what a whole calendar is.

## The three calendar roles

A role says what a calendar is to you. It sets the default relationship for every event on it.

| Role | What it means | Default relationship | Typical calendars |
|---|---|---|---|
| **Mine** | Things I do. | Planned (Maybe if the event is tentative) | Your own calendars, a shared trip you are on, tasks |
| **Opportunities** | Things I could do. | Available | Event feeds, community calendars, Luma, Partiful, Meetup |
| **Context** | Information. | Context | Weather, tides, sunset, holidays, other people's schedules |

Defaults when a calendar is created: local calendars are Mine, subscriptions are Opportunities, plugin calendars are Context. A connected Google calendar starts by what it is: your own as Mine, one shared with you or copied from a feed as Opportunities, Google's holidays and birthdays as Context. Change any of them in the calendar's settings under "What this calendar is". Changing a role re-reads every event on the calendar immediately; nothing is rewritten.

## Changing one event

Open an event (popover or full detail). The **For me** dropdown lists what that event can be, which depends on its calendar's role:

- On a **Mine** calendar: Planned or Maybe.
- On an **Opportunities** calendar: Available, Maybe, Planned or Hidden.
- On a **Context** calendar: nothing to choose. Information is information.

On a repeating event the dropdown asks which occurrences: this one, this and following, or the whole series, the same question every other change to a series asks.

The agenda view puts three small buttons (Maybe, Planned, Hide) on each row from a subscribed calendar; on a phone, swipe the row left or tap its grip.

Thumbs up and down on feed events are separate. They train the ranking and say nothing about whether you are going.

## What stays private and what leaves the app

The relationship is your side only. Nobody is notified, nothing is RSVP'd. Sending a reply to an organizer is a different action (RSVP) and is labelled as such.

One thing does travel. On a **Mine** calendar, Maybe is stored as the event's status, `TENTATIVE` in iCalendar terms, and Planned as `CONFIRMED`. That is the standard way to say "tentative", so:

- CalDAV clients (Apple Calendar, Thunderbird, DAVx5) see and can set it.
- On a Google calendar you write to, Maybe becomes tentative at Google, drawn hatched there. Like every Google write, this one cannot be undone from Better-Cal, and the prompt says so.

On an **Opportunities** calendar the relationship is a private mark on your copy of the event. Feeds and Google polls never overwrite it.

## The Show filter

The toolbar has one menu, **Show: all** by default, with a checkbox per relationship. Any combination works and applies to every view. The button reads as the current state ("Show: no context", "Show: planned only", "Show: planned + maybe") and tints when a filter is on. The choice is remembered per browser, and a saved view captures it along with the text filter and visible calendars, so "only planned, work calendars" can be one saved mode.

Hotkeys toggle each kind: **p** planned, **m** maybe, **a** available, **x** context. The command palette has the same four plus "Show only planned" and "Show all".

A day where the filter hid something shows a small half-filled circle after its day number, in every view, with the count in its tooltip. In month and agenda views a click on it clears the filter. Nothing disappears silently.

## Where context is drawn

Context is consulted, not attended: the weather, the AQI, when the sun sets, whether the tide is low at a useful hour. That is the first thing to read about a day and something you come back to, so it lives where the day is named rather than in the list of things to do.

- **Month**: as many tokens as fit beside the day number, then "+N" which opens the expanded day, where context has its own section on top. A token is an icon for the kind of information plus the value: a cloud and "72/58" for "Asheville, NC: 72/58 fog", an air icon and "54" for "AQI 54 (Moderate)", a sunset icon and "7:10p". Hover for the full title, click for the event. The two hover controls of a day, expand (chevron) and add (+), sit together at the cell's top right. Context never takes a chip slot, so a day full of information still shows all its plans. On a phone the full month has no room for tokens, so the expanded day lists them (the 3-day view shows one, the first).
- **Icons**: a plugin sets one per event (`icon` in `syncEvents`; the host set includes sun, sunrise, sunset, air, tideHigh, tideLow, cloud, rain, snow, storm, thermometer, moon, flag, or any emoji). For feeds, which carry no icon, the host recognizes the common shapes in a title (a high/low pair with a condition word, AQI, sunset, sunrise, high or low tide, a holiday calendar) and otherwise shows the calendar's hollow square with the words.
- **Holidays**: all-day context from a calendar whose name says holiday is a day label, not a token: its name is written after the date wherever a date is written ("Mon, Jan 1 · New Year's Day"). On the phone's full month it is a small flag after the day number.
- **Times keep their place**: a moment on a calendar in another zone (a Seattle sunset while you are in Lisbon) reads in its own zone with the zone named, "7:10p PDT". Its position on the timeline is still the true instant.
- **Week and day**: all-day context as tokens in the column header; a timed context event is a dashed hairline at its minute with a small label, never a block. Sunset at 7:10 is a moment, not a forty-minute appointment.
- **Agenda**: the day's tokens ride on the day heading.

## Reading an event's look

Besides its relationship, an event's look can carry a few other states, and several can combine (a maybe that already ended is outlined and faded).

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

Hover over an event with a mouse or trackpad and a card shows its full title (a narrow month cell cuts long ones short), when it is, its calendar and its place, then a short italic line for each of these states that applies. For a highlighted or dimmed event, that line names the filter and where it matched: "Underlined: highlighted by your "dinner" filter (matched in the description)". A new keyword filter matches titles only unless you tick more fields, so words in a long imported description don't surprise you.

## Why it works this way

- **What am I actually doing?** is the question a calendar exists to answer. With feeds and shared calendars it stopped being answerable at a glance. Roles and relationships make "only planned" one keystroke.
- **Maybe is a real state.** Not a reply to someone, not a private note in a title: a status that survives sync and that other clients understand.
- **Information should not look like plans.** Sunset and tides are the first thing to read about a day and noise in the list of things to do. Context lives in the day's header and on the timeline as moments, and never spends an event slot.
- **A default is not a decision.** The role gives every event a sensible relationship the moment a calendar is added; you only touch the events that differ.

## For integrators

Every occurrence in the API carries `relationship`. Calendars carry `role`. See `docs/api-contract.md` for `POST /events/:id/relationship` and `PATCH /calendars/:id {role}`. The derivation is one pure function, `Events::relationship(attendance, status, role)`, and its rules are:

1. A context calendar is always `context`.
2. A hidden mark wins next.
3. A going or interested mark gives `planned` or `maybe`.
4. On a Mine calendar the event's status decides: tentative is `maybe`, anything else `planned`.
5. Otherwise `available`.
