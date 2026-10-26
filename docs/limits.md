# Scale and Limits

## Who It's For

Better-Cal is meant to replace one person's Google Calendar. It isn't meant for enterprise use or for extremely heavy users (thousands of events a week, dozens of busy shared calendars, a whole team on one install). If you need that, feel free to fork it; the limits below are where you'd start.

For a sense of scale: a normal multi-month view may hold thousands of occurrences, and the defaults leave plenty of room above that while still stopping pathological input.

## Limits You Might Notice

- **1,000 occurrences per repeating series per request.** That covers a daily event across the widest window the app ever asks for (two years). A series that goes past it (something hourly, say) shows its first 1,000 occurrences in that range, and the app tells you which series was cut short; API callers get the same as a `capped` list of event ids.
- **Up to 700 days per load.** The events API has a ceiling of about two years; the app keeps one window below 700 days and asks you to narrow a wider one.
- **About ten years either side of today in the scrolling views.** Month, week and day views stop there, and a jump further out stops at the edge. Search and the API aren't limited this way.
- **Busy days in month view.** A day shows about seven events on a tall window (fewer on a short one or a phone), then "+N more"; trips and people's away times stack two deep per week before they overflow into the day's expanded list. Nothing is dropped; it just takes a click.
- **Dense windows fail whole, not partly.** One request may visit 5,000 repeating series, generate 10,000 occurrences, spend eight seconds on recurrence work and read 32 MiB of stored event text by default. Past any of those, the app asks for narrower dates or fewer calendars rather than show an incomplete window as complete.
- **Outbound feeds hold 5,000 events and 16 MiB.** A feed you publish covers the past year onward (plus every repeating series). Past 5,000 events, the oldest past events are left off first, never upcoming ones, and the feed's card in Settings says so; `BETTERCAL_LIMIT_OUTFEED_EVENTS` raises the cap. A feed that would be larger than 16 MiB isn't generated (a `413` instead). A feed made from a saved search holds the search's top 500 matches.
- **Repeating rules from outside** (feeds, imports, CalDAV, Google, email): an interval up to 1,000 and a COUNT up to 100,000. A rule past that arrives as a single event rather than a broken series.
- **Editing one occurrence of a repeating event** works for a real occurrence of the series within about 20 years of today. Whole-series edits aren't limited this way.
- **Reminders:** up to five per event, at most four weeks ahead. Reminder, notification and test emails share caps of 100 per account per hour and 500 per day (with recipient and install-wide ceilings too), and one reminder scan sends at most 50 emails. Scheduled email past the caps is dropped and reported in Settings, System; push isn't affected. Test email also has its own limit of five per ten minutes. Very busy calendars are scanned in slices, so a long scan carries on in the next worker run.
- **Plugins:** `eventsWindow()` returns at most 5,000 occurrences by default, and one `syncEvents()` snapshot is at most 2,000 events and 8 MiB (a refusal keeps the previous snapshot). **Run now** queues at most 30 jobs an hour and 100 a day, with at most 16 plugin jobs waiting or running; asking again for a job that's already queued doesn't add another.

## Limits You Can Change

Imports, subscribed feeds, CalDAV, incoming email, skipped occurrences, description length and filter work all have caps that protect the server from oversized or hostile input. Each has a default sized for one person and can be raised in `.env.php` up to a hard ceiling; [`.env.example`](../.env.example) lists them (the `BETTERCAL_LIMIT_*` settings) with their defaults and ceilings. An import that runs into one says which setting raises it. The main ones:

- **An uploaded `.ics` file:** 25 MiB and 20,000 events.
- **A command-line Takeout migration:** 1,000 `.ics` files and 1 GiB in all by default, with the per-file limits still applied. Each calendar commits separately, so after fixing a failed file you can run the command again without redoing finished calendars.
- **One JSON API request:** 1 MiB (up to 4 MiB).
- **One ICS subscription:** 20,000 events per poll, and 20 MiB (not configurable).
- **One Google calendar poll:** 20,000 events, 32 MiB of responses, 20 pages and 40 seconds. The event count is lowered automatically when PHP's `memory_limit` is too small for that many. A refused poll keeps the last good copy and sync position and records the reason on the calendar. `BETTERCAL_LIMIT_GOOGLE_SYNC_*` settings raise these.
- **One Google account's calendar list:** 5,000 calendars, 5 MiB, 20 pages and 20 seconds (`BETTERCAL_LIMIT_GOOGLE_CALENDAR_LIST_*`).
- **CalDAV:** one object is at most 1 MiB, with up to 500 changed occurrences. Other CalDAV requests may send at most 1 MiB, and a PUT one object plus 64 KiB; anything larger gets a 413. Normal events, even with long descriptions, are far smaller. The web server may have its own request size limit.
- **One incoming email:** 5 MiB (up to 25 MiB), with at most 1 MiB and 200 events in its calendar part, and 512 KiB of text handed to the markup and AI readers (`BETTERCAL_LIMIT_MAIL_*`).
- **Email in all:** 100 new events or first invitations per rolling day, 25 per claimed sender, 500 future or repeating events created by mail, and at most 100 open emailed invitation decisions in Review (a hard cap, which you can lower). AI mail reading is 20 calls an hour, 50 a day and 20 per claimed sender a day. These are account-wide, so a new From, Message-ID or event UID doesn't reset them. Review shows one notice when a limit is reached, and the email stays in the mailbox. Changes to an existing invitation have their own daily and per-sender limits (100 and 25), and one event keeps at most three waiting changes (a fourth replaces the newest). Unreadable emails are listed one by one up to 20, then counted in one notice.
- **Quick add AI help:** 120 calls an hour and 500 a day for the account, 30 an hour and 150 a day for each API token, and at most four at once (two per token). Previews count. Rule-based parsing still works when the budget runs out. Quick add text is at most 4,000 characters.
- **Prompt filters:** 40 AI batches an hour and 200 a day for the account, and 20 an hour and 100 a day for one subscribed calendar; one batch judges up to 25 events. Work past that waits, and Review says the events not yet judged stay visible.
- **Ranking:** a separate AI budget with the same numbers: 40 calls an hour and 200 a day for the account, 20 an hour and 100 a day per calendar. When it runs out, Review shows a notice, existing scores stay, and ranking resumes once there's room.
- **One AI response:** at most 1 MiB; a larger one is dropped and the feature falls back as it would on any AI failure.
- **An event description:** 65,535 characters, after which it's cut.

### ZIP Imports and Malformed Dates

A ZIP may hold 10,000 entries (ignored files count), 1 GiB unpacked, 1,000 calendar files and 250,000 events in all (`BETTERCAL_LIMIT_IMPORT_ZIP_ENTRIES`, `BETTERCAL_LIMIT_IMPORT_ZIP_TOTAL_BYTES`, `BETTERCAL_LIMIT_TAKEOUT_FILES` and `BETTERCAL_LIMIT_IMPORT_ZIP_EVENTS`; ceilings 50,000 entries, 4 GiB, 5,000 files and 1,000,000 events). The per-file limits still apply. That's room for a heavy export with 50 calendars, 50,000 events and about 100 MiB of text, if each calendar fits the host's memory.

The whole ZIP is checked before any calendar is created, by what each file actually unpacks to rather than what the archive claims, and duplicate names are refused. A bad calendar file is listed as skipped while the others import. Each calendar commits separately, so if the host stops a long request, importing again skips calendars whose names are already there. To recover events rejected from a partly imported ZIP, extract that .ics file, fix its dates and import it into the calendar already created.

A date field that should hold one date can't hold a comma-separated list, and no line may hold more than 1,000 comma-separated values (categories, extra dates, parameter lists); commas inside quotes still count, but plain commas in a title, description or location don't. Such an event is rejected, and good events in the same input continue: imports list it as skipped, feeds show a warning in their settings and in Settings, System (keeping the previous copy of that event), and mail leaves a notice in Review. A whole calendar may hold 200,000 such values; past that, the file is refused and a feed keeps its last good copy. A malformed line outside every event (in the calendar header or a time zone) refuses the whole file. CalDAV refuses a malformed object whole, and there plain commas in descriptions count toward the calendar-wide limit too. The settings are `BETTERCAL_LIMIT_ICS_LIST_VALUES_PER_LINE` and `BETTERCAL_LIMIT_ICS_LIST_VALUES_PER_INPUT` (ceilings 10,000 and 1,000,000). Fix the dates in the original calendar and try again.

You can't import into a calendar while it's moving to Google, or into one that has finished moving.

### Trips and Reminder Devices

Adding many events to a trip stays cheap, but opening a trip still costs work in proportion to its members. Trip Planner stops a run that runs out of budget and keeps its previous suggestions.

An account may register 100 push devices by default (`BETTERCAL_LIMIT_PUSH_DEVICES_PER_ACCOUNT`, up to 500); the same device counts once. Devices already registered keep refreshing at the limit, and none are removed because an account is over it. Remove old ones in Settings, Notifications to make room; if a new device's registration conflicts with an old one, the error asks you to remove that one first. Reminders still go to devices one at a time, so many unreachable devices can hold up the worker ([SECURITY.md](../SECURITY.md#what-has-been-reviewed) lists this as a known residual).

## Known Hosting Limits

These don't affect security; they can only stop one feature on an unusual setup.

- **Exports on network filesystems.** Export takes a file lock so two can't overlap. On NFS and some other network mounts that lock may never succeed, so every export answers "An export is already running". Keep the app folder on a local disk.
- **Backups and hard links.** Restores refuse a backup file with more than one hard link, since another name could change it. Snapshot tools that hard-link unchanged files (rsync `--link-dest`, some NAS snapshots) produce exactly that; copy the backup out of the snapshot before restoring it.
- **Approved senders after updating from before 0.9.87.** That update approved past senders, which can include your own forwarding address; review the list if you forward mail into the app ([Email ingest](email-ingest.md#choose-what-gets-added)).

## Background Work

The worker runs every minute and spreads slow work across runs: up to ten emails per run, one prompt-filter AI call per job, and a few ranking calls at a time. On a busy day a new invitation or filter verdict can take a few minutes to appear.
