# Roadmap

This is where I think Better-Cal is headed, from the next release to 2.0. It's a work in progress and it *will* change: the order is my current guess, every release also picks up fixes and smaller things that aren't listed, and anything here can move or drop out.

Each item links to its issue, and that's the best place to comment on it. For the plan as a whole (what's missing, what's in the wrong place, what 2.0 should be), there's a [roadmap feedback issue](https://github.com/Oshyan/better-cal/issues/175).

## The big question: what is 2.0 about?

A major version should mean something you can say in one sentence. I have three candidates and haven't picked one yet:

- **What a calendar item is.** An item can be more than an event: tasks, markers (on a day without taking up time) and blocks (time you defend rather than time you owe someone). Decided once in the data model, then used by views, filters, plugins and the API. It starts with a decision in [#38](https://github.com/Oshyan/better-cal/issues/38).
- **Any calendar service.** Better-Cal sits in front of whatever calendars you already have: CalDAV services like iCloud, Fastmail and Nextcloud, then Microsoft, with the same write-through and undo Google has today ([#137](https://github.com/Oshyan/better-cal/issues/137), [#172](https://github.com/Oshyan/better-cal/issues/172), [#83](https://github.com/Oshyan/better-cal/issues/83)).
- **Works offline.** What you've loaded stays readable with no connection, and the app tells you how fresh it is. That's the groundwork for making changes offline later ([#16](https://github.com/Oshyan/better-cal/issues/16), [#17](https://github.com/Oshyan/better-cal/issues/17)).

The releases below assume the first one, since it's the only change big enough to need a major version. Either of the others would move its issues forward and push the item work later.

## 1.4.0 (released 2026-10-27)

- Month rows that grow to fit their fullest day, with a switch in the grid's corner ([#171](https://github.com/Oshyan/better-cal/issues/171))
- A shared or pasted event page link becomes a quick-add draft ([#105](https://github.com/Oshyan/better-cal/issues/105)), and Cmd/Ctrl+V on the calendar opens quick add with what you copied
- Quick add suggests calendars, tags and people after `/` `#` `@` ([#169](https://github.com/Oshyan/better-cal/issues/169))
- Jump to date also takes a view, like "day view of June 9" ([#12](https://github.com/Oshyan/better-cal/issues/12))
- A setting per calendar to hide events its source marks cancelled ([#135](https://github.com/Oshyan/better-cal/issues/135))
- Edit the approved senders for email ingest as text, with wildcards ([#151](https://github.com/Oshyan/better-cal/issues/151))
- A number on the installed app's icon: Review items waiting, events today, or events still to come today. Off by default ([#170](https://github.com/Oshyan/better-cal/issues/170))
- Holidays marked with a stripe across the day's header, sidebar groups in any order, and Cmd/Ctrl+Enter to save from the editor
- Plugins wait for their required settings, Run now runs right away, and an open page picks up a new version on its own

The [changelog](CHANGELOG.md) has the full list, fixes included.

## 1.4.x (in progress)

Done so far:

- The sidebar stays beside Settings, Review and the other pages on a computer
- A plugin's first run starts when you turn it on, and its form keeps later fields out of reach until the required ones are filled in
- Plugin runs started by Run now or a settings save wait for the worker's lock, so they can't write during an update (a Low finding from the review of 1.0 to 1.4)

## 1.5: Filters and your own layer

Filters that say what they do and can do more, plus a note of your own on any event. Together they're the base for automations later.

- Filters show what each kind matches, and gain organizer, people, calendar, link and time conditions ([#63](https://github.com/Oshyan/better-cal/issues/63))
- Filters can dim or highlight context items, and highlight a whole day ([#72](https://github.com/Oshyan/better-cal/issues/72))
- Weather tags days with their conditions: rain, snow, hot, and so on ([#73](https://github.com/Oshyan/better-cal/issues/73))
- Mark a calendar as day labels (holidays, birthdays, closures) ([#65](https://github.com/Oshyan/better-cal/issues/65))
- Your own note on any event, separate from its description ([#64](https://github.com/Oshyan/better-cal/issues/64))
- Edit a plugin's fields from Better-Cal, starting with Fibery's status (a Done button, then the full field), then priority, project and more ([#178](https://github.com/Oshyan/better-cal/issues/178))

## 1.6: Views and navigation

- Turn views on or off per device, and set their order ([#61](https://github.com/Oshyan/better-cal/issues/61))
- Saved views in the view switcher, with their own shortcuts ([#99](https://github.com/Oshyan/better-cal/issues/99))
- Quick add previews the draft on the calendar while you type ([#87](https://github.com/Oshyan/better-cal/issues/87))
- A sidebar that slides out on hover at the left edge ([#115](https://github.com/Oshyan/better-cal/issues/115))
- See which events have a call, and Join when it's time ([#98](https://github.com/Oshyan/better-cal/issues/98))

## 1.7: Places and context

- Your most frequent places come first in place search ([#166](https://github.com/Oshyan/better-cal/issues/166))
- The forecast for an event's own place, in its details ([#85](https://github.com/Oshyan/better-cal/issues/85))
- Climate averages past the forecast range ([#35](https://github.com/Oshyan/better-cal/issues/35), on a shared provider library, [#37](https://github.com/Oshyan/better-cal/issues/37))
- Trip as a place: weather, sun, tides and air quality for a trip's place and dates ([#173](https://github.com/Oshyan/better-cal/issues/173))
- A folder per place for its linked calendars ([#174](https://github.com/Oshyan/better-cal/issues/174))

## 1.8: Connections and offline

- Undo for changes made on Google calendars ([#172](https://github.com/Oshyan/better-cal/issues/172))
- The events window answers "unchanged" when nothing moved ([#16](https://github.com/Oshyan/better-cal/issues/16))
- Feed refreshes skip feeds that haven't changed ([#179](https://github.com/Oshyan/better-cal/issues/179))
- What you've loaded stays readable offline ([#17](https://github.com/Oshyan/better-cal/issues/17))
- Connect CalDAV calendars (iCloud, Fastmail, Nextcloud), then Microsoft ([#137](https://github.com/Oshyan/better-cal/issues/137))

## 1.9: Review, mail and running it

- Snooze Review items ([#130](https://github.com/Oshyan/better-cal/issues/130))
- Rename, merge and delete tags ([#134](https://github.com/Oshyan/better-cal/issues/134))
- Outbound feeds with times only, no details ([#84](https://github.com/Oshyan/better-cal/issues/84))
- Let your own devices in during a sign-in attack ([#59](https://github.com/Oshyan/better-cal/issues/59))
- API key scopes: read-only first, then per calendar ([#112](https://github.com/Oshyan/better-cal/issues/112))
- Update from the app, WordPress-style ([#139](https://github.com/Oshyan/better-cal/issues/139))

## 2.0: What a calendar item is

If that's the answer to the big question. It starts with a decision, and the rest builds on it.

- Decide the item taxonomy: which differences are properties of an item and which are kinds of item ([#38](https://github.com/Oshyan/better-cal/issues/38))
- Markers: on a day without taking up time ([#39](https://github.com/Oshyan/better-cal/issues/39))
- Blocks: time you defend ([#40](https://github.com/Oshyan/better-cal/issues/40))
- Native tasks ([#50](https://github.com/Oshyan/better-cal/issues/50))
- Automations, starting with simple rules: tag, annotate, correct a feed's location ([#62](https://github.com/Oshyan/better-cal/issues/62))
- Plugin panels drawn by the app, and an Add-ons group in the sidebar ([#54](https://github.com/Oshyan/better-cal/issues/54))

Whatever 2.0 turns out to be about, it's also a good point to make the worker do less polling and more reacting, still on plain PHP, MySQL and cron ([#179](https://github.com/Oshyan/better-cal/issues/179)). Benchmarking with large, realistic data comes first, and decides which of these are worth it:

- Reminders kept on a schedule instead of found by a scan every minute
- Email that arrives in seconds (an inbound-mail webhook or mail piping), with IMAP as the fallback
- Changes on Google calendars that show up right away, by push

## Decisions to make first

These need an answer before anyone builds them.

- The item taxonomy, and with it native tasks ([#38](https://github.com/Oshyan/better-cal/issues/38), [#50](https://github.com/Oshyan/better-cal/issues/50))
- Floating times: events that keep their clock time in any time zone, and how that works on Google calendars ([#167](https://github.com/Oshyan/better-cal/issues/167))
- A custom date-range view: how it draws, steps and saves ([#13](https://github.com/Oshyan/better-cal/issues/13))
- What "disabled" means for a calendar, as opposed to hidden ([#28](https://github.com/Oshyan/better-cal/issues/28))
- A hosted Better-Cal with sign-ups, or managed single-owner installs ([#57](https://github.com/Oshyan/better-cal/issues/57))
- Whether the per-occurrence plugin data budget is the right size ([#51](https://github.com/Oshyan/better-cal/issues/51))

## Parked

Ideas I like but haven't scheduled. Any of them can move up if they earn it (votes and comments on the issue help).

- **Planning and suggestions:** trip planning chat ([#3](https://github.com/Oshyan/better-cal/issues/3)), suggestion types you can define ([#46](https://github.com/Oshyan/better-cal/issues/46)), day rules ([#75](https://github.com/Oshyan/better-cal/issues/75)), proactive suggestions ([#76](https://github.com/Oshyan/better-cal/issues/76)), seasonal forecasts ([#36](https://github.com/Oshyan/better-cal/issues/36)), plant care ([#47](https://github.com/Oshyan/better-cal/issues/47)), insights ([#90](https://github.com/Oshyan/better-cal/issues/90))
- **Views and editing:** keyboard nudging ([#95](https://github.com/Oshyan/better-cal/issues/95)), an agenda sidebar ([#94](https://github.com/Oshyan/better-cal/issues/94)), a selected day ([#168](https://github.com/Oshyan/better-cal/issues/168)), an event popover ([#116](https://github.com/Oshyan/better-cal/issues/116)), collapsed early and late hours ([#92](https://github.com/Oshyan/better-cal/issues/92)), a second time-zone column ([#89](https://github.com/Oshyan/better-cal/issues/89)), status by shape ([#93](https://github.com/Oshyan/better-cal/issues/93)), context by time or place ([#100](https://github.com/Oshyan/better-cal/issues/100)), Review only when something's waiting ([#101](https://github.com/Oshyan/better-cal/issues/101))
- **Search, filters and tags:** search that respects Show, ranges and saved searches ([#71](https://github.com/Oshyan/better-cal/issues/71)), filters with several conditions ([#74](https://github.com/Oshyan/better-cal/issues/74)), areas ([#41](https://github.com/Oshyan/better-cal/issues/41)), calendar tags ([#111](https://github.com/Oshyan/better-cal/issues/111))
- **People and availability:** contacts sync ([#7](https://github.com/Oshyan/better-cal/issues/7)), clearer People badges ([#160](https://github.com/Oshyan/better-cal/issues/160)), away times as events ([#161](https://github.com/Oshyan/better-cal/issues/161)), painting and sharing free times ([#162](https://github.com/Oshyan/better-cal/issues/162)), scheduling links ([#97](https://github.com/Oshyan/better-cal/issues/97))
- **Mail and invitations:** read Gmail directly ([#69](https://github.com/Oshyan/better-cal/issues/69)), auto-place trusted invitations ([#118](https://github.com/Oshyan/better-cal/issues/118)), DKIM on inbound invitations ([#25](https://github.com/Oshyan/better-cal/issues/25)), events from an image ([#104](https://github.com/Oshyan/better-cal/issues/104))
- **Places and maps:** saved places ([#152](https://github.com/Oshyan/better-cal/issues/152)), venues by address ([#150](https://github.com/Oshyan/better-cal/issues/150)), geocoder choice ([#30](https://github.com/Oshyan/better-cal/issues/30)), time zone search ([#146](https://github.com/Oshyan/better-cal/issues/146))
- **Connections:** task-manager sync ([#49](https://github.com/Oshyan/better-cal/issues/49)), moving events across the Google boundary ([#56](https://github.com/Oshyan/better-cal/issues/56)), a two-way Google bridge ([#83](https://github.com/Oshyan/better-cal/issues/83))
- **Platform:** better phone notifications ([#26](https://github.com/Oshyan/better-cal/issues/26)), native Android and iPhone apps ([#68](https://github.com/Oshyan/better-cal/issues/68)), a native Mac app ([#176](https://github.com/Oshyan/better-cal/issues/176)), localization ([#147](https://github.com/Oshyan/better-cal/issues/147)), undo and log retention ([#45](https://github.com/Oshyan/better-cal/issues/45))

Reference, for reading rather than building: Daybridge ideas ([#44](https://github.com/Oshyan/better-cal/issues/44)), Fantastical ideas ([#103](https://github.com/Oshyan/better-cal/issues/103)), free feeds worth subscribing to ([#102](https://github.com/Oshyan/better-cal/issues/102)).

## Feedback

Thoughts on the plan as a whole go in the [roadmap feedback issue](https://github.com/Oshyan/better-cal/issues/175). Comments on any linked issue are welcome, and so are new issues for things that aren't here. I'd especially like to hear what you think 2.0 should be about.
