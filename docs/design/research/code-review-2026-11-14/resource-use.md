# Resource use: where Better-Cal uses more than it needs (2026-11-14)

Read-only review of `main` at 3702b514. Nothing was run against a database or a server; every figure below is derived from the code, the schema and the configured intervals, and says what it assumes. Speed is another review's subject; a saving that costs speed is listed apart.

## Assumed scale

One owner account on shared LAMP hosting (128 to 256 MB `memory_limit`, cron every minute). About 30 ICS subscriptions at the default 60-minute interval (`calendars.poll_interval_minutes` default 60, minimum 5, `Calendars.php:305`), about 5 Google calendars at their default 5 minutes (`Calendars.php:239`), push or email reminders configured, 30,000 event rows in all, one or two CalDAV clients. Where a figure depends heavily on a different scale it says so.

## What is already lean (no change proposed)

- ICS polls are conditional and skip the parse on an unchanged body: validators sent back, 304 or same SHA-256 skips parse and sync, one full read a day (`Feeds.php:101-141`, decision "An unchanged feed is not parsed again"). `feed_reads` keeps hashes and validators only, never a copy of a body.
- Google polls use sync tokens; an empty delta stores the token and syncs nothing (`GoogleSync.php:135-200`). One stored access token per account. The read-before-write on every Google change is deliberate certainty (decisions "Every Google request the app sends is conditional", "Exceptions are carried, not trusted") and is not questioned here.
- `GET /events` answers 304 from the change cursor before reading an event (decisions "The events window is validated by its change cursor"); the client checks `/changes/cursor` every 30 s only while visible (`web/src/app/App.js:256-266`).
- The client's in-memory occurrence store is capped (`pruneOccurrenceCache`, `web/src/app/store.js:511`); localStorage holds small per-device preferences (the resume list is capped at 20).
- The PHP error log is capped at 2 MiB, keeping the newer half (`Support/ErrorLog.php:21`). The worker's own output goes to /dev/null.
- Self-update keeps 2 previous app copies and 2 database backups and removes leftovers (`Infra/Updater.php:342`); the owner's deploy script keeps 5 of each (`scripts/deploy-backup.sh:152`). The downloaded zip is deleted after unpacking (`bin/update.php:272`).
- Activity log 90 days, undo snapshots 7 days, pieces of large snapshots with them (`Domain/Activity.php:169-188`). `notified_instances` 7 days, `rate_events`, `model_admissions`, `external_action_admissions`, `mail_admissions`, `mail_ingest` (90 days), `http_cache` (expired rows, daily), sessions and trusted devices by expiry, `plugin_runs` 20 per plugin.
- `DbBackup::dump` streams row by row with an unbuffered query (`Infra/DbBackup.php:104-125`), so a backup's memory does not grow with the database.

## Findings

### F1. The job queue keeps a week of no-op rows, and the scheduler scans them nine times a minute

- Where: `bin/worker.php:129-130, 417-466` enqueues routine jobs every tick; `Infra/JobQueue.php:281-287` deletes done and failed rows after 7 days; `bin/worker.php:468-477` (`bc_enqueue_if_stale`) runs `SELECT id FROM jobs WHERE type = ? AND created_at > ?` once per routine type per tick. The only index on `jobs` is `(status, run_after)` (`migrations/001_init.sql:206-216`), so that query reads the whole table: about 9 full scans every minute.
- Rows a day at the assumed scale: `reminder_scan` 1,440 roots plus a continuation per slice (masters phase, then overrides phase: `Reminders.php:459-462`), so 2,880 to 4,300; `geocode_sweep` 1,440 (enqueued every tick when place lookup is on, the handler returns at once when `hasCandidates()` is false, `worker.php:335-341, 439-441`); `mail_ingest` 720 (enqueued even when IMAP is not configured; the handler checks `isConfigured()` only after claiming, `worker.php:216-218, 432`); `feed_poll` 720 (ICS) + 1,440 (Google); `duplicate_scan` up to 1,440 (one slice a minute, see F5); `system_alerts` 144; `rank_events` 24 or more; a few daily ones. Total roughly 9,000 to 11,000 rows a day, so 63,000 to 77,000 rows held at 7 days. At about 250 bytes a row with its index and InnoDB page fill, that is on the order of 15 to 20 MB, the largest bookkeeping table, nearly all of it `done` rows of jobs that did nothing.
- Change, as two rules: (1) a done job is kept only as long as the scheduler reads it, which is the longest `bc_enqueue_if_stale` interval (1 day): `prune()` deletes `done` rows older than 1 day and keeps `failed` rows 7 days for diagnosis. Nothing else reads finished rows (searched: the only other reader is `bin/disable-missing-plugins.php`, pending rows only). (2) A routine job is enqueued only when its configuration lets it do anything: `mail_ingest` only when `MailFetcher::isConfigured()`, `reminder_scan` only when push or email is configured (the scan's own early return, `Reminders.php:328-331`), `rank_events` and `filter_eval` only when the model is configured. Data checks (has candidates, has due reminders) stay in the handlers.
- Effect on speed: none a person notices. The scheduler's scans get 7 times smaller; nothing runs later.
- Risk: low. Nothing a person sees is pruned; failed jobs keep their week. `bc_enqueue_if_stale` reads `created_at`, and a row whose `updated_at` is older than a day has an older `created_at`, so no routine job is enqueued early. Not a stored form. InnoDB keeps the table file at its high-water mark until `OPTIMIZE TABLE`; the freed pages are reused, so the file stops growing rather than shrinking. Covered by the worker tests in `server/tests/run.php` (queue prune) and `server/tests/db/`; a test should assert a done row older than a day goes and a failed one stays.
- Alternative or addition: an index `(type, created_at)` makes the nine scans index reads whatever the retention. A schema index only, no row rewritten.
- Confirm: `SELECT type, status, COUNT(*), MIN(created_at) FROM jobs GROUP BY type, status ORDER BY 3 DESC;` and `SELECT ROUND((data_length+index_length)/1048576,1) mb, table_rows FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = 'jobs';`

### F2. A failing ICS feed is fetched about 2.7 times as often as a working one, forever

- Where: `bin/worker.php:362-378`: a `feed_poll` that throws goes to `JobQueue::markFailed`, which re-queues it after 5, 10, 15 and 20 minutes (`JobQueue.php:244-256`, 5 attempts). `Feeds::pollFailed` also sets `last_polled_at` (`Feeds.php:330-341`), and `hasActiveFeedPoll` holds new polls back while the retry is pending, so after the fifth failure the next poll is a full interval later.
- Amount: a dead 60-minute feed (404, 410, 401, a host gone) is fetched at minutes 0, 5, 15, 30, 50, then again at 110: 5 fetches every 110 minutes, about 65 a day instead of 24. Each is a request to someone else's server that has already said no, plus a failure write to `calendars`, `system_health` and `jobs`. For a Google calendar the multiplier is below 1 (its interval is 5 minutes), so this is an ICS problem.
- Change, as a rule: a feed poll's retry is its interval. A failed `feed_poll` is marked failed without queue retries (`markFailed($job, $msg, false)`, the parameter already exists), and the poll interval brings the next attempt. Stall recovery (`reapStalled`) is unchanged.
- Effect on speed: a transient blip (a timeout, a 503) on a 60-minute feed is retried at the next interval instead of 5 minutes later, so a change on that feed can show up to an interval later after a blip. If that matters, keep queue retries for transport errors and 5xx only and drop them for 4xx; that is still one rule ("retry only what a retry can fix") rather than a list of statuses per site, and the decision belongs to the owner.
- Risk: low. Nothing stored changes form; health streaks and alert mail are counted per poll, so a dead feed still reaches failing and still emails (fewer attempts reach `JOURNAL_AFTER` more slowly in wall-clock time; check `SystemHealth` thresholds). Tests: worker failure handling in `server/tests/run.php`, feed failure scenarios in `server/tests/db/`.
- Confirm: `SELECT JSON_EXTRACT(payload_json,'$.calendarId') cal, COUNT(*) jobs, SUM(attempts) fetches FROM jobs WHERE type='feed_poll' AND created_at > NOW() - INTERVAL 1 DAY GROUP BY cal ORDER BY fetches DESC;` beside `SELECT subject, consecutive_failures, first_failed_at, last_error FROM system_health WHERE kind='feed' AND status='failing';`

### F3. Every Google poll reads every stored row's text before it knows anything changed

- Where: `GoogleSync::poll` calls `assertCachedSnapshotFits` before any request (`GoogleSync.php:65-68`); that is an aggregate of `LENGTH()` over 19 text and JSON columns of every row of the calendar, deleted rows included (`GoogleSync.php:969-1002`). It runs again where rows are actually loaded (`GoogleSync.php:143` for a delta, `:218` before `Feeds::sync`).
- Amount: once per poll per Google calendar, 288 times a day each at the 5-minute default; 1,440 a day for 5 calendars. Most polls are empty deltas that load nothing. For a 5,000-row calendar with descriptions this reads every description value (`description` is MEDIUMTEXT, often stored off-page), on the order of 5 to 20 MB of row data per poll. It is the most frequent sizeable read in the system.
- Change, as a rule: the size check runs where rows are materialised, not before the request. Drop the call at line 68; the two later calls stay, so nothing is ever loaded unchecked.
- Effect on speed: none, or slightly faster; an empty delta poll becomes one HTTP request and a token write.
- Risk: low. A calendar too large to load now spends one `events.list` request per poll before refusing (Google quota is not a concern at one request per 5 minutes), and its refusal still comes before any row is read, with the token and rows left as they were. Not a stored form. Check whether a test asserts "refused before any remote request" for an oversized cached calendar (`server/tests/run.php` around the `googleSnapshotMemoryProblem` checks, line 9775, and the Google scenarios in `server/tests/db/`).
- Confirm: time the aggregate from `GoogleSync.php:972-988` for the largest Google calendar (`EXPLAIN ANALYZE` or the client's timing), and count Google calendars: `SELECT id, poll_interval_minutes, (SELECT COUNT(*) FROM events e WHERE e.calendar_id = c.id) n FROM calendars c WHERE provider='google';`

### F4. A change on a Google calendar loads the whole calendar into PHP two or three times, in a web request

- Where: every create, edit, delete and answer on a Google calendar applies Google's answer through `GoogleWriter::apply` (`GoogleWriter.php:899-908`; callers `Events.php:510, 565, 686, 810, 851, 870`, `Rsvp.php:440`, `GoogleUndo.php:544-667`). `apply` reads `SELECT * FROM events WHERE calendar_id = ?`, converts it (`rowsToParsed`), applies the change, and hands the whole snapshot to `Feeds::sync`, which reads `SELECT * FROM events WHERE calendar_id = ?` again and indexes it (`Feeds.php:1266-1288`). A series change's `GoogleSeries::store` does the same (`GoogleSeries.php:3114`). A Google delta poll that brings one changed event does the same in the worker (`GoogleSync.php:145-151` then `:221`).
- Amount: three representations of every row of the calendar at once (PDO rows, the parsed snapshot, `Feeds::sync`'s rows and index), for one changed event. The code's own estimate is about 3 KiB a row for two shapes plus descriptions (`Support/Limits.php:284-293`), so a 10,000-row Google calendar is roughly 60 to 100 MB of peak memory per edit. The poll path is guarded by `assertCachedSnapshotFits`; the web path (`apply`) has no such guard, so on a 128 MB host a large Google calendar can fail an edit with a fatal out-of-memory instead of a reasoned refusal.
- Change, as a rule: a write's read-back is settled against the rows of the series it names, not the calendar. That is a new sync mode for `Feeds::sync` (rows of other uids are not compared and not removed), which is a mechanism change: AGENTS.md requires a written model (what it reads, what it compares, what it refuses) and a check that can fail on injected damage before any code. Short of that, the cheap part is a guard: `apply` asks the same memory question the poll asks and refuses with words before loading.
- Effect on speed: faster edits on large Google calendars (the speed review may find the same thing from the other side).
- Risk: high for the rule (the whole-list compare is what removes days a series no longer has; a scoped compare must still do that for the named series), low for the guard. Touches no stored form. Covered by the Google scenarios in `server/tests/db/` and both modes of `tools/edit-harness`.
- Confirm: on a test install holding a Takeout-sized Google calendar, log `memory_get_peak_usage(true)` at the end of a request that renames one event; and `SELECT calendar_id, COUNT(*), SUM(LENGTH(description)) FROM events GROUP BY calendar_id ORDER BY 2 DESC LIMIT 10;` for the real sizes.

### F5. Duplicate detection reads the whole account continuously, whether or not anything changed

- Where: `worker.php:448` starts a cycle when no `duplicate_scan` row was created in 10 minutes; a cycle walks every master event of the account in 500-row slices (`Duplicates::discoverUidSlice`, `Duplicates.php:294-309`), then events from 14 days back to 400 days ahead (`discoverTimeSlice`, `:364-388`), one slice per worker minute (`worker.php:182-189`). Each continuation is a new `jobs` row, so `bc_enqueue_if_stale` never sees a 10-minute gap while a cycle runs.
- Amount: with 30,000 rows, about 60 uid slices plus the time slices: a cycle takes over an hour, and the next starts as soon as one ends. In effect one slice every minute, all day, each reading 500 rows and up to 20,000 comparisons (`Limits.php:153-161`), with nothing changed in between for most of those minutes. That is steady CPU and database load, which shared hosts meter.
- Change, as a rule: a cycle starts when the account's change cursor (`ChangeCursor`, the same one `GET /events` validates by) has moved since the last cycle started, or a day has passed (the time window moves a day a day, so a daily cycle still finds pairs that enter it with nothing changing). Store the cursor and the start time of the last cycle with the job's payload or in `system_health`'s row for `job:duplicate_scan_budget`.
- Effect on speed: none. A new or changed event still starts a cycle within 10 minutes.
- Risk: low to medium. A duplicate that only appears because time passed waits up to a day instead of up to a cycle. Nothing pruned, no stored form. Tests: `server/tests/run.php` duplicate scan and the duplicates scenarios in `server/tests/db/`.
- Confirm: `SELECT DATE(created_at) d, COUNT(*) FROM jobs WHERE type='duplicate_scan' GROUP BY d;` (slices a day), and run `php server/bin/worker.php` by hand once on the install: its `duplicate_scan rows= comparisons=` line prints to the terminal.

### F6. The CalDAV change journal is never pruned

- Where: `Dav/ChangeLog.php:46-88` writes one `dav_changes` row per event change from every source (app, CalDAV, every feed or Google poll that changes a row, migrations, undo); nothing deletes from the table except the cascade when a calendar is deleted. `CalendarBackend::getChangesForCalendar` (`Dav/CalendarBackend.php:521-557`) answers from any token back to the calendar's first.
- Amount: one row (about 150 to 250 bytes with its index) per changed event. Ordinary use is small: a few hundred rows a month. It becomes large on a feed whose content churns: a 500-event feed that rewrites every event on each poll at 60 minutes writes 12,000 rows a day, 4.4 million a year. Whether any subscription here churns cannot be known without counting; a feed that changes UIDs on every read (some generators do) also churns events and touches (F7).
- Change, as a rule: the journal keeps a fixed age (say 90 days); a client token older than the oldest kept change gets the answer sabre defines for an unknown token (`null`, the client does an initial sync). That needs the floor stored per calendar (a column `dav_floor`, the highest synctoken pruned plus one) and one comparison in `getChangesForCalendar`, so a token below it is never answered with a partial list. Run from the daily `activity_prune`.
- Effect on speed: none for current clients; a device offline longer than the retention resyncs once in full.
- Risk: medium. The floor check is what keeps a client from silently missing changes; without it, pruning is wrong. A new column, no rewritten rows. Tests: the DAV tests in `server/tests/db/` (sync-collection) need a case for a token under the floor.
- Confirm: `SELECT calendar_id, COUNT(*), MIN(created_at), MAX(created_at) FROM dav_changes GROUP BY calendar_id ORDER BY 2 DESC LIMIT 10;` and `SELECT DATE(created_at) d, COUNT(*) FROM dav_changes WHERE created_at > NOW() - INTERVAL 30 DAY GROUP BY d;`. If no calendar exceeds tens of thousands of rows, this is not worth doing yet.

### F7. Undo touches outlive any undo by 83 days

- Where: `Undo::recordTouches` (`Undo.php:1322-1341`) writes a `mutation_touches` row per (row, column) a change touched, feed and Google polls included (`Feeds.php:1302`, recorded with the poll's log-only entry). Rows go only when their entry goes at 90 days (FK cascade). Undo is possible for 7 days.
- Amount: proportional to churn. A feed calendar that changes 200 events a day across 3 columns adds about 600 rows a day, 54,000 held; the churning feed of F6 would add hundreds of thousands. Ordinary personal use is small.
- Change: none proposed now. Touches of entries older than the undo window look dead, but one reader still uses them: the bare Undo passes over a log-only entry that touched nothing and stops with "can't be undone" at one that touched rows (`Undo.php:2186-2191`). Dropping touches at 7 days would change what the bare Undo says about an old entry and make it walk back through up to 90 days of entries a query at a time. Pruning them needs that rule restated first (for example: an entry past the undo window ends the walk, whatever it touched). Measure before deciding.
- Confirm: `SELECT m.source, COUNT(*) touches, SUM(m.created_at < NOW() - INTERVAL 7 DAY) past_undo FROM mutation_touches t JOIN mutations m ON m.id = t.mutation_id GROUP BY m.source;`

### F8. The reminder scan reads 35 days of events every minute

- Where: `Reminders::dueForUserSlice` selects masters overlapping 27 hours back to 35 days ahead (`Reminders.php:494-495`, `SCAN_LOOKAHEAD = 'P35D'` because `MAX_MINUTES` is 4 weeks, `:45-49`) and their overrides, every minute, in slices of 250 and 500, each slice a job row. Events with no effective reminder are skipped before expansion (`:629-645`, the one function `effective`), so the cost is the read, not the expansion.
- Amount: every master in that window and every recurring series still running, read 1,440 times a day. With 30 public-event feeds that is likely a few thousand rows a minute, most on calendars whose effective reminders are empty.
- Change, as a rule: the lookahead is the largest lead time in use, computed from the data (the global defaults, every calendar's defaults, and the largest per-event `reminders_json` entry) plus the grace margin, capped at 35 days. One function computes it, from the same `effective` inputs. A second, separate saving: select only calendars whose effective default can remind, plus rows with their own `reminders_json`, with the calendar list computed in PHP by `effective` (not a second rule in SQL).
- Effect on speed: none; reminders fire at the same time.
- Risk: medium. Too small a lookahead misses a reminder; the computation must include every source `effective` reads, and a lead time saved after the scan began. Floating events widen the window (`Floating::widened`). No stored form. Tests: reminder scan tests in `server/tests/run.php` and `server/tests/db/`.
- Confirm: `SELECT COUNT(*) FROM events WHERE deleted_at IS NULL AND recurrence_parent_id IS NULL AND (rrule IS NOT NULL OR (end_utc > NOW() - INTERVAL 28 HOUR AND start_utc < NOW() + INTERVAL 35 DAY));` against the same with 2 days; and the largest lead time in use: `SELECT reminders_json FROM events WHERE reminders_json IS NOT NULL AND reminders_json <> '[]' GROUP BY reminders_json;` plus the settings' `reminderTimed`/`reminderAllDay`.

### F9. Published feeds are rebuilt in full on every fetch

- Where: `OutFeedsController.php:47-66` answers a published feed with the whole ICS and `Cache-Control: private, max-age=300`; there is no ETag or Last-Modified, so a subscriber's conditional request always gets a fresh build (`OutFeeds::renderByToken`, up to 5,000 events and 16 MiB, `Limits.php` `OUTFEED_*`).
- Amount: one full build and body per fetch. Calendar apps poll a subscription anywhere from every 5 minutes (Apple, if set so) to every day (Google). A 2,000-event feed polled every 15 minutes is about 96 builds and roughly 100 to 200 MB of outbound body a day, for content that changes a few times.
- Change, as a rule: the published feed is validated as the events window is: an ETag from the change cursor of the calendars in its scope, the feed's own settings, the code version and today's date (the feed leaves past events off by the current time when over its cap, so its content can change with the date alone), and a 304 before any event is read. The same soundness rule as decisions "The events window is validated by its change cursor": every write that changes what the feed returns moves a part of the tag.
- Effect on speed: faster for subscribers; none for the owner.
- Risk: medium. A tag that misses an input hides a change from every subscriber; filters or settings that change membership must be in it. No stored form. Needs tests beside the out-feed tests.
- Confirm: count fetches per feed path in the web server's access log for a day (`grep -c` on the feed's path, by user agent). If subscribers fetch a few times a day, this is not worth doing.

### F10. Releases ship the maintainer's test tools to every install

- Where: `scripts/release-assemble.sh:28-30` excludes `server/tests`, `web/tests`, `scripts/tests` and `tools/tz-harness`, but ships the rest of `tools/`: `edit-harness` (243 files, 1.9 MB), `compare` (78 files, 0.4 MB), `google-probe` (16 files, 0.6 MB), `gates.sh`. Nothing in `server/src`, `server/bin` or the deploy scripts reads them.
- Amount: about 3 MB and 340 files per install, times three on a host that keeps two previous copies (`Updater::prune(2)`), about 1,000 inodes where shared hosts limit file counts (the reason `Updater::prune` gives).
- Change: exclude the maintainer-only tools in the same `git archive` pathspec list (keep `tools/mcp` if installs are meant to use it).
- Effect on speed: none. Risk: none at runtime; check the docs do not tell installs to run a shipped tool.
- Confirm: `unzip -Z1 better-cal-<version>.zip | grep -c '^tools/'`.

### F11. Smaller items, wasteful on paper, small in practice

- `geocode_sweep` asks `hasCandidates()` every minute (`GeocodeSweep.php:92-101`). The index `(location_lat, geocoded_at)` (`migrations/020`) does not help much because most events have no location and so a null latitude; when there is nothing to place, the query reads most of `events`. Tens of milliseconds a minute at 30,000 rows. Folding it into F1's rule (enqueue when a write or poll brought a row with an address, plus daily for retries) removes it, but user saves would then need to enqueue a sweep; not worth a separate change.
- Each unchanged ICS poll still writes `calendars`, `feed_stats`, two `system_health` transactions and `feed_reads` (delete and insert) (`Feeds.php:185-209, 298-327`). Eight or so statements per poll; `last_ok_at` is what the health and staleness views read, so they buy something. Irrelevant at this scale.
- The worker's idle tick is on the order of 50 to 80 statements (lock, schema marker file, health write, stall check, the due-poll join, nine `jobs` scans, the plugin due list, then the reminder and geocode jobs with their claim, done and health writes): a few thousand statements an hour. Within any shared host's limits; F1 makes the largest part of it cheaper.
- The service worker's API cache keeps one entry per distinct `/events` window and per `/search` query until the app version changes (`web/sw.js:106-118, 179-238`). Old versions' caches are deleted on activate, so it is bounded by the time between updates. Dropping `/search` from the cacheable list (an offline search of a past query is rarely useful) is free; capping `/events` entries trades offline coverage. Not worth it now.
- `server/vendor` is 22 MB, a quarter of it `nesbot/carbon` pulled in by the IMAP library. Disk on the order of tens of MB; replacing the IMAP library is not a resource decision.
- Soft-deleted event rows are never removed (`Events.php:2324`, `CalendarBackend.php:348`; feeds hard-delete). They are tombstones with meaning: a deleted feed event stays deleted when the feed brings it again, and invitations and imports look them up. They are also loaded by `Feeds::sync` and counted by F3's aggregate. Inventory only.
- The database backup (`DbBackup::dump`) and the deploy backup include caches and bookkeeping (`jobs`, `http_cache` bodies, `rate_events`). After F1 the difference is small; leaving cache tables' rows out of a backup is possible but makes a restore differ from the source in ways nobody needs to reason about. Not worth it.

## Trades speed (listed apart, not recommended without the owner)

- Google poll interval: 5 minutes is 288 `events.list` requests a day per calendar (`Calendars.php:239`). An interval that backs off while a calendar is quiet (say up to 30 minutes after a day without a change, back to 5 on any change or when the app is open) cuts requests by up to 6 times, at the cost of a change made at Google showing up later. Quota is not the problem (well under Google's per-project limits); this only matters to someone counting requests.
- Backoff for a feed failing for days: after a day failing, poll at most every 6 hours. Cuts requests to a dead host further than F2, at the cost of a recovered feed updating up to 6 hours late.
- Mail check every 2 minutes is 720 IMAP logins a day (`worker.php:432`); 5 minutes is 288. Invitations would arrive up to 5 minutes later.
- The client's change check every 30 s while visible (`App.js:266`): 120 PHP requests an hour per open tab. 60 s halves it; changes made elsewhere would show up to a minute later.

## Ranking

### Worth doing now

| # | Where | Resource, amount | Change | Speed | Risk |
|---|---|---|---|---|---|
| F1 | `worker.php:417-477`, `JobQueue.php:281` | `jobs` rows: ~10k a day, ~70k held, ~15-20 MB; 9 full-table scans a minute | Done rows kept 1 day, failed 7; routine jobs enqueued only when configured to do something; optionally index `(type, created_at)` | none | low, nothing a person sees |
| F2 | `worker.php:362-378`, `JobQueue.php:244` | Dead ICS feed fetched ~65 times a day instead of 24 | A feed poll's retry is its interval (or: no queue retry for 4xx) | transient blip retried at next interval | low |
| F3 | `GoogleSync.php:68, 969` | Full-calendar text aggregate before every Google poll: 1,440 a day for 5 calendars | Check size only where rows are loaded | none (slightly faster) | low; one extra request per poll on an oversized calendar |
| F10 | `release-assemble.sh:28-30` | ~3 MB, ~340 files per install, x3 with kept copies | Exclude maintainer tools from the zip | none | none |

### Worth measuring first

| # | Where | Resource, amount | Change | Speed | Risk |
|---|---|---|---|---|---|
| F5 | `worker.php:448`, `Duplicates.php:243-388` | One 500-row slice and up to 20k comparisons every minute, all day, on a large account | Start a cycle only when the change cursor moved, or daily | none | low-medium; a time-only duplicate waits up to a day |
| F4 | `GoogleWriter.php:899`, `Feeds.php:1266`, `GoogleSync.php:145` | Whole Google calendar in memory 2-3 times per edit; ~60-100 MB at 10k rows; no memory guard on the web path | Guard now; scoped settle by series needs a written model | faster | guard low; scoped sync high |
| F8 | `Reminders.php:49, 494-560` | Every master in a 35-day window read each minute | Lookahead from the largest lead time in use; only calendars that can remind | none | medium; must include every source of lead times |
| F6 | `ChangeLog.php`, `CalendarBackend.php:521` | `dav_changes` unbounded; small normally, millions a year on a churning feed | Age retention with a per-calendar floor; older tokens resync | none (one resync for a long-offline device) | medium; floor check is essential |
| F9 | `OutFeedsController.php:47-66` | Full rebuild and body per subscriber fetch | ETag from the scoped change cursor plus date; 304 | faster | medium; tag must cover every input |
| F7 | `Undo.php:1322`, `:2186` | `mutation_touches` held 90 days, used for 7 | Restate the bare-Undo walk rule, then prune with snapshots | none | medium; changes what bare Undo says about old entries |

### Not worth it

- Unchanged-poll bookkeeping writes, the idle tick's statement count, the geocode candidate check, the service worker's API cache, vendor size, leaving caches out of backups (F11): real on paper, below anything a shared host would notice at this scale.
- Pruning tombstones (soft-deleted events): they carry meaning (a deleted feed event stays deleted).
- `feed_stats`: one row per calendar per day forever (about 11,000 a year for 30 feeds, under 1 MB), and `Calendars.php:594` reads the all-time maximum, so pruning would change the "went empty" reading.
- `geocode_cache`, `review_items`, `feedback_signals`, `calendar_moves`, `event_duplicates` dismissals, `system_health`: unbounded or slow-growing but tiny, and each row is either a cache that saves an outside request or a decision a person made.
- Streaming the ICS parse: memory is already budgeted per poll (`Limits::feedEventBudget`), and an unchanged feed is not parsed at all.
- Keeping 1 previous copy instead of 2 on self-update: saves one app copy (~25 MB, a few thousand files) and loses a second rollback step; the owner's call, not a waste.

## Everything that grows without bound

| Table or store | Grows by | What limits it today |
|---|---|---|
| `events` (live rows) | the calendars themselves | nothing, by design; feed rows removed when the feed drops them |
| `events` soft-deleted rows | each delete in the app or over CalDAV | nothing; tombstones with meaning |
| `dav_changes` | one row per changed event, every source | nothing (cascade on calendar delete) |
| `mutations` | one per change and per changing poll | rows 90 days, snapshots 7 days (`Activity::prune`, daily) |
| `mutation_touches` | (row, column) per change, polls included | cascade with its entry at 90 days |
| `mutation_rows` | pieces of large snapshots | with snapshots at 7 days (`Undo::dropUnusedPieces`) |
| `mutation_calendar_refs` | one per entry per calendar | cascade at 90 days |
| `jobs` | ~10k rows a day at the assumed scale | done and failed rows after 7 days, each worker run |
| `feed_stats` | one row per subscribed calendar per day | nothing (cascade on calendar delete) |
| `geocode_cache` | one row per distinct location text looked up | nothing |
| `http_cache` | one row per URL fetched by plugins and event pages | expired rows, daily, up to 20,000 a run |
| `review_items` | one per held change or state notice | nothing; decided rows stay |
| `event_duplicates` | one per pair found | automatic pairs of deleted events pruned each cycle; dismissals kept |
| `feedback_signals` | one per thumbs up or down | cascade with the event |
| `filter_evals` | one per (filter, event) | removed with the filter or the event |
| `system_health` | one per subject (job, feed, push device, geocoder) | some subjects removed when gone (`SystemHealth.php:493`) |
| `plugin_kv` | whatever plugins store | per-key deletes, uninstall; expired public entries are not swept |
| `plugin_proposals` | plugin suggestions | pruned by `Proposals.php:173` |
| `calendar_moves`, `user_update_notices`, `google_pending` | rare events | `google_pending` removed on Finish and Put back and `closeLeftBehind` |
| `notified_instances`, `rate_events`, `model_admissions`, `external_action_admissions`, `mail_admissions`, `mail_ingest`, `sessions`, `trusted_devices`, `plugin_runs` | per action | time or count retention (7 days to 90 days, expiry, 20 a plugin) |
| PHP error log (`.runtime-cache/php-errors.php`) | errors | 2 MiB, newer half kept |
| Update copies and backups beside the app | each self-update | 2 previous copies, 2 database backups |
| Deploy backups (owner's server) | each deploy | 5 app, 5 database |
| Service worker API cache | one entry per `/events` window and `/search` query URL | deleted when the app version changes, on 401 and sign-out |
| Service worker shell cache | app files of one version | old versions deleted on activate |
| Client memory (occurrences) | windows visited | `OCCURRENCE_CACHE_MAX` |

## What could not be determined without measurement

- Real table sizes, and so whether F6 and F7 matter at all: the queries above, plus `SELECT table_name, table_rows, ROUND(data_length/1048576,1) data_mb, ROUND(index_length/1048576,1) idx_mb, ROUND(data_free/1048576,1) free_mb FROM information_schema.TABLES WHERE table_schema = DATABASE() ORDER BY data_length + index_length DESC;`
- Whether any subscription churns (rewrites events or UIDs on every read): the per-calendar `dav_changes` and `mutation_touches` counts.
- Peak memory of an edit on the largest Google calendar (F4), and how close it is to the host's `memory_limit`.
- How many masters the reminder scan reads a minute, and the largest lead time actually in use (F8).
- Duplicate-scan slices a day and their row and comparison counts (F5).
- How often subscribers fetch published feeds (F9): access logs.
- How long the F3 aggregate takes on the real data.
- The LLM ranking and prompt-filter jobs, and plugins' own outbound requests (weather, tides, the Fibery plugin), were not examined in depth; ranking and filters return early when no model is configured, and plugin fetches go through `http_cache` with per-plugin TTLs.
