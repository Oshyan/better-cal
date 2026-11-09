# Roadmap

This is where I think Better-Cal is headed, from the next release to 2.0. It's a work in progress and it *will* change: the order is my current guess, every release also picks up fixes and smaller things that aren't listed, and anything here can move or drop out.

Each item links to its issue, and that's the best place to comment on it. For the plan as a whole (what's missing, what's in the wrong place, what 2.0 should be), there's a [roadmap feedback issue](https://github.com/Oshyan/better-cal/issues/175).

## The big question: what is 2.0 about?

A major version should mean something you can say in one sentence. I have three candidates and haven't picked one yet:

- **What a calendar item is.** An item can be more than an event: tasks, markers (on a day without taking up time) and blocks (time you defend rather than time you owe someone). Decided once in the data model, then used by views, filters, plugins and the API. It starts with a decision in [#38](https://github.com/Oshyan/better-cal/issues/38).
- **Any calendar service.** Better-Cal sits in front of whatever calendars you already have: CalDAV services like iCloud, Fastmail and Nextcloud, then Microsoft, with the same write-through and undo Google has today ([#137](https://github.com/Oshyan/better-cal/issues/137), [#172](https://github.com/Oshyan/better-cal/issues/172), [#83](https://github.com/Oshyan/better-cal/issues/83)).
- **Works offline.** What you've loaded stays readable with no connection, and the app tells you how fresh it is. That's the groundwork for making changes offline later ([#16](https://github.com/Oshyan/better-cal/issues/16), [#17](https://github.com/Oshyan/better-cal/issues/17)).

The releases below assume the first one, since it's the only change big enough to need a major version. Either of the others would move its issues forward and push the item work later.

## Released

What has shipped lives in the [changelog](CHANGELOG.md), one section per version with the fixes included. This list only says what each version was for, so the plan below can stay about what is next.

- [1.5.4](CHANGELOG.md#154-2026-11-07) (2026-11-07): Google calendars on the series model after a probe of Google's own behavior, undo for every change made to a Google calendar ([#172](https://github.com/Oshyan/better-cal/issues/172)), and events that keep their clock time in any zone, floating times in iCalendar's word ([#167](https://github.com/Oshyan/better-cal/issues/167)).
- [1.5.3](CHANGELOG.md#153-2026-11-07) (2026-11-07): one rule per area in place of the layered fixes ([#190](https://github.com/Oshyan/better-cal/issues/190)), an unbounded series read one whole cycle past a change ([#205](https://github.com/Oshyan/better-cal/issues/205)), the events window answering "unchanged" from the change cursor ([#16](https://github.com/Oshyan/better-cal/issues/16)), and eight small follow-ups from the audit of closed issues.
- [1.5.2](CHANGELOG.md#152-2026-11-06) (2026-11-06): search in tiers (a phrase, every word, word starts; the title before the description; no OR and no address), the measured comparison with 1.3 and 1.4.0 ([#203](https://github.com/Oshyan/better-cal/issues/203)), failure emails to the reminders address, a failed model call that says why, and the fixes the comparison found.
- [1.5.1](CHANGELOG.md#151-2026-11-05) (2026-11-05): "this and following", deletes and answers from a day on as splits on the series model; an edited day's date, time or length as the series' own; the typed clock; the six Low security findings closed by three rules; CalDAV time ranges from the app's own expander; memory bounds for small hosts.
- [1.5.0](CHANGELOG.md#150-2026-11-04) (2026-11-04): one engine under repeating events. Every whole-series change goes through one model that says what it will do before it does it, and the series harness measures it on every push. Also the loading rewrite ([#190](https://github.com/Oshyan/better-cal/issues/190)) and undo for very large calendar deletes ([#191](https://github.com/Oshyan/better-cal/issues/191)).
- [1.4.6](CHANGELOG.md#146-2026-11-01) (2026-11-01): quick add reads more ([#193](https://github.com/Oshyan/better-cal/issues/193)), emailed one-day changes in any order ([#192](https://github.com/Oshyan/better-cal/issues/192)), updating across a signing-key change ([#194](https://github.com/Oshyan/better-cal/issues/194)), series drags that keep their edited days ([#188](https://github.com/Oshyan/better-cal/issues/188)), and the first series harness.
- [1.4.5](CHANGELOG.md#145-2026-11-01) (2026-11-01): sidebar folders ([#187](https://github.com/Oshyan/better-cal/issues/187)), moving a series from the editor ([#186](https://github.com/Oshyan/better-cal/issues/186)), a more careful Undo.
- [1.4.4](CHANGELOG.md#144-2026-10-31) (2026-10-31): fixes from the release review of 1.4.1 to 1.4.3 ([#185](https://github.com/Oshyan/better-cal/issues/185)).
- [1.4.3](CHANGELOG.md#143-2026-10-30) (2026-10-30): calendar marks as discs, the sidebar's width remembered, the second review of every view ([#184](https://github.com/Oshyan/better-cal/issues/184)).
- [1.4.2](CHANGELOG.md#142-2026-10-30) and [1.4.1](CHANGELOG.md#141-2026-10-29) (2026-10-29 and 30): the two rounds of the view review ([#183](https://github.com/Oshyan/better-cal/issues/183)), undo toasts that say what they would undo, plugin runs that wait for the worker's lock.
- [1.4.0](CHANGELOG.md#140-2026-10-27) (2026-10-27): month rows that grow to fit ([#171](https://github.com/Oshyan/better-cal/issues/171)), event page links as quick-add drafts ([#105](https://github.com/Oshyan/better-cal/issues/105)), quick add suggestions ([#169](https://github.com/Oshyan/better-cal/issues/169)), jump to a view of a date ([#12](https://github.com/Oshyan/better-cal/issues/12)), hiding cancelled events per calendar ([#135](https://github.com/Oshyan/better-cal/issues/135)), approved senders as text ([#151](https://github.com/Oshyan/better-cal/issues/151)), a number on the app icon ([#170](https://github.com/Oshyan/better-cal/issues/170)).
- Everything before 1.4.0 is in the changelog too, back to 0.1.

## 1.5: One engine under the calendar

The fixes since 1.4.0 were made one reviewer's finding at a time, and each round's bugs came from the layer the round before had added. 1.5 replaces the layers with one rule per area, each with a test that measures it rather than a reviewer who happens to find a case. The heart of it is repeating events: a whole-series edit, "this and following", a delete, an attendance answer, a Google calendar and Undo used to work out for themselves where every occurrence goes when a series moves, and they didn't agree. 1.5 gives them one model, and the series harness decides when it's done.

I'm keeping the higher bar rather than copying what other calendars do. Thunderbird and Nextcloud move a series' edited days by the same number of days as the series and leave the repeat rule alone; Better-Cal follows the drag for every kind of rule ("the second Tuesday" dragged to Wednesday becomes "the second Wednesday", an edited day lands on the occurrence it belongs to, the end date moves too). That is what the harness tests.

**1.5.0 shipped** (2026-11-04, above): the model under every whole-series edit, with its plan shown first, and the harness clean on every seed for those.

**1.5.1 shipped** (2026-11-05, above; [#202](https://github.com/Oshyan/better-cal/issues/202) closed with every line accounted for): the splits on the same model, the adoption readings ([#189](https://github.com/Oshyan/better-cal/issues/189)), the typed clock, the security rules, CalDAV ranges, and the harness driving every path with every option, clean on six seeds after two rounds of external review.

**1.5.4 shipped** (2026-11-07, above): Google calendars on the series model after a probe of what Google does with recurring events, every change to a Google calendar undoable ([#172](https://github.com/Oshyan/better-cal/issues/172)), the survey of other handlers ([#225](https://github.com/Oshyan/better-cal/issues/225)), and events that keep their clock time in any zone (floating times) ([#167](https://github.com/Oshyan/better-cal/issues/167)).

**1.5.3 shipped** (2026-11-07, above): one rule per area in place of the layered fixes ([#190](https://github.com/Oshyan/better-cal/issues/190)), an unbounded series read one whole cycle past a change with its losses and gains by class ([#205](https://github.com/Oshyan/better-cal/issues/205)), the events window answering "unchanged" from the change cursor ([#16](https://github.com/Oshyan/better-cal/issues/16)), and eight of the audit's follow-ups.

**Still to come in 1.5:**

- **After 1.5.1 ships: an honest comparison with 1.3 and 1.4.0** ([#203](https://github.com/Oshyan/better-cal/issues/203)). What was broken then, in which situations and actions, that now works, measured rather than argued: the same harness seeds and fixed cases run against a checkout of each old version through its own API (the driver speaks the plan API, so the old versions get a thin adapter that sends the same edits the old way), the same invented scenarios committed on dev installs of each version and their rows compared, and the issues the releases closed replayed on each. The report says what the dialog alone gained (the plan shown and a choice offered where there is one) apart from what the engine gained (occurrences that now land where the rule says, edited days that stay on their occurrence, Undo that puts back what was there), so the under-the-hood work is judged by results and not by its size. It also accounts for what the work cost: the rounds and gate runs 1.5.0 and 1.5.1 took, before and after the harness was sharded, against the same for 1.3 and 1.4. If the engine's column turns out thin, that is the finding. **Done 2026-11-06:** [the comparison](docs/design/comparison-1.5.md). The engine's column is not thin: on 1.3 and 1.4.0, nine in ten whole-series and split edits end in a state no reading of the design produces, the dialog's own gain is about two in a hundred, and 1.5.1 passes 18,306 generated cases with none failing.
- The 2026-11-05 audit of every closed issue filed fourteen small follow-ups ([#207](https://github.com/Oshyan/better-cal/issues/207) to [#220](https://github.com/Oshyan/better-cal/issues/220)). Eight shipped in 1.5.3; the rest wait on a device or a decision ([#213](https://github.com/Oshyan/better-cal/issues/213), [#216](https://github.com/Oshyan/better-cal/issues/216), [#219](https://github.com/Oshyan/better-cal/issues/219)) or are small features for a later 1.x ([#206](https://github.com/Oshyan/better-cal/issues/206), [#208](https://github.com/Oshyan/better-cal/issues/208), [#211](https://github.com/Oshyan/better-cal/issues/211), [#214](https://github.com/Oshyan/better-cal/issues/214)).

Each piece ships as its own release with an independent review before it, so nothing waits on everything.

## 1.6: Filters, views and your own layer

Filters that say what they do and can do more, a note of your own on any event, and the views you want where you want them. The filters and notes are the base for automations later.

- Filters show what each kind matches, and gain organizer, people, calendar, link and time conditions ([#63](https://github.com/Oshyan/better-cal/issues/63))
- Filters can dim or highlight context items, and highlight a whole day ([#72](https://github.com/Oshyan/better-cal/issues/72))
- Weather tags days with their conditions: rain, snow, hot, and so on ([#73](https://github.com/Oshyan/better-cal/issues/73))
- Mark a calendar as day labels (holidays, birthdays, closures) ([#65](https://github.com/Oshyan/better-cal/issues/65))
- Your own note on any event, separate from its description ([#64](https://github.com/Oshyan/better-cal/issues/64))
- Edit a plugin's fields from Better-Cal, starting with Fibery's status (a Done button, then the full field), then priority, project and more ([#178](https://github.com/Oshyan/better-cal/issues/178))
- Turn views on or off per device, and set their order ([#61](https://github.com/Oshyan/better-cal/issues/61))
- Saved views in the view switcher, with their own shortcuts ([#99](https://github.com/Oshyan/better-cal/issues/99))
- Quick add previews the draft on the calendar while you type ([#87](https://github.com/Oshyan/better-cal/issues/87))
- A sidebar that slides out on hover at the left edge ([#115](https://github.com/Oshyan/better-cal/issues/115))
- See which events have a call, and Join when it's time ([#98](https://github.com/Oshyan/better-cal/issues/98))
- Undo on a calendar here moves onto the deltas model a Google series undo uses, so staleness reads one way everywhere (decisions.md, stale undo)

## 1.7: Places and context

- Your most frequent places come first in place search ([#166](https://github.com/Oshyan/better-cal/issues/166))
- The forecast for an event's own place, in its details ([#85](https://github.com/Oshyan/better-cal/issues/85))
- Climate averages past the forecast range ([#35](https://github.com/Oshyan/better-cal/issues/35), on a shared provider library, [#37](https://github.com/Oshyan/better-cal/issues/37))
- Trip as a place: weather, sun, tides and air quality for a trip's place and dates ([#173](https://github.com/Oshyan/better-cal/issues/173))
- A folder per place for its linked calendars ([#174](https://github.com/Oshyan/better-cal/issues/174))

## 1.8: Connections and offline

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
- The series model for 1.5: for each kind of repeat rule and each kind of edit, what happens to the rule, the start, the end, and every edited or skipped day. Written down as a table before the code, with the harness's cases as its examples
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
