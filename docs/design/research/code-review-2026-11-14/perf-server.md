# Server performance review, 2026-11-14 (head 3702b514)

Scope: server/src, migrations, the worker, server scripts. Read-only. Nothing here was run against a database or a server; the numbers marked "measured here" come from DB-free PHP 8.5 micro-benchmarks on the development Mac (opcache on, scripts kept in the session scratchpad, not the repo), and the rest are arithmetic from code and schema, said as such. The client, and pure memory/disk/request-count economy, belong to other reviews.

## What already exists and what it says

- Tools: `server/bin/profile-events.php` (phases of `Events::window`), `server/bin/profile-recurrence.php` (per-master expansion), and `tools/compare/perf/` (bench.php, seed.php, report.php; php-cgi per request, query counts from SHOW SESSION STATUS). Results recorded in `docs/design/comparison-1.5.md` and `workfolder/reviews/comparison-1.5/perf.md`.
- Recorded 1.5.1 baseline on the bench seed: month view 99 ms and 27 queries for 1,165 occurrences; week 78 ms; CalDAV week report 161 ms and 284 queries; CalDAV year report 264 ms and 413 queries; ordinary series drag 95 ms (preview 40 + commit 56); large series new rule 688 ms.
- The bench seed is recurrence-heavy and small: 205 series, 741 event rows in all. It has no bulk of single past events, so it cannot show anything that scales with history (findings 5 and 6 below). The target install has tens of thousands of rows.
- `profile-recurrence.php` is stale: it times sabre's `EventIterator`, which the window stopped using in 1.5.0 (decision "The expander is our own"). Its numbers say nothing about today's expander. `profile-events.php` is still useful: its total line calls the real `Events::window`; its phase replay uses a simpler SQL than production (no floating widening, no budget chunking) but the same `Recurrence::expand`.
- Decisions that bound this review: the window ETag/304 from the change cursor (#16), the two-step budgeted row load (`rowsWithinBudget`, a memory bound), the search limit reading every matching series, feed conditional GET plus body hash (migration 073), Google polls by sync token, every write checked against its credential with a fixed bookkeeping table list (`RequestAuthority::BOOKKEEPING`), and the expander being the app's own and its most verified code.

## Measured here (DB-free)

- Recurrence expansion over a one-month window, per series per request: daily since 2018, open: 255 us for 30 occurrences (about 40 us fixed plus 7 us per occurrence; 1 day 41 us, 7 days 82 us, 3 months 720 us). Weekly MO,WE,FR since 2018: 147 us. Monthly BYSETPOS since 2019: 112 us. Yearly all-day: 26 us. Weekly with an UNTIL in 2021 (ended): 25 us.
- COUNT-bounded series walk from their start on every request (`RuleIterator::jumpTo`: "A COUNT is of the occurrences passed, so a rule with one walks on from where it stands"): daily COUNT=30 ended 2019: 203 us; daily COUNT=400 ended 2021: 2.4 ms with nothing shown; weekly COUNT=500 from 2018: 3.9 ms; daily COUNT=3000 from 2020 (still running): 15.5 ms. About 6.5 to 8.5 us per elapsed occurrence.
- `Calc::reads` (a throwaway `RuleIterator` per master before the real one): 4 to 7 us per series.
- Serialization of one occurrence, list shape (`Events::serialize`): 6.2 us. For the month view's 1,165 occurrences, about 7 ms. `json_encode` of those: 0.4 ms (426 KB). The window's `usort`: 2.3 ms.
- CalDAV per-object range test (`DavTimeRange::occurrences`): a past single event 4.2 us, a weekly series since 2019 64 us, per object per range.
- `Ics::parse`: 500 events (170 KB) 35 ms, 8 MB peak; 2,000 events (686 KB) 92 ms, 30 MB peak. A sha256 of the body with DTSTAMP lines removed: 0.7 ms and 2.1 ms.
- PHP 8.4+ default bcrypt cost is 12: one `password_verify` took 213 ms here.
- Without opcache, bootstrap plus the API's class graph (78 files) took 30 ms to compile here, per request.

## Findings

### 1. CalDAV calendar-query fetches every hit one by one (N+1)

- Where: `server/src/Dav/CalendarBackend.php:252` `calendarQuery` returns URIs; sabre's `CalDAV\Plugin::calendarQueryReport` (vendor/sabre/dav/lib/CalDAV/Plugin.php around line 600) then calls `getPropertiesForPath` per URI, which reaches `Calendar::getChild` -> `getCalendarObject` (CalendarBackend.php:171) -> `getMultipleCalendarObjects` with one UID: one `SELECT * FROM events WHERE calendar_id = ? AND uid IN (?)`, plus two `Trips::relatedUidMap` queries (Trips.php:242), plus `DavIcs::buildObject` for the body. Runs on every calendar-query REPORT, which Apple clients send per calendar whenever the ctag moves.
- Evidence: the bench recorded 284 queries for a week report and 413 for a year; three per matching object plus a fixed handful fits both.
- Change (rule): a calendar-query answers its hits from one batched read. `calendarQuery` keeps a per-request map uri -> object filled by `getMultipleCalendarObjects($calendarId, $uris)` in chunks of 250 (one events query and two link queries per chunk), and `getCalendarObject` serves from that map before querying. sabre's later per-path calls then find their object without a query. The map is cleared at the end of the request (it lives on the backend instance, which is per request).
- Expected benefit: queries on the bench week report about 284 -> 20; at 0.2 to 0.4 ms per local query that is 55 to 110 ms of the recorded 161 ms, so the report roughly halves. Confidence: high on the query count, medium on wall time (the body build per hit stays; how much of the 161 ms is queries vs bodies is not split yet).
- Risk: low. Bodies and etags are built by the same function as before; a hit whose object vanished between the query and the fetch is simply absent from the map and falls through to today's path. Memory: bodies of all hits held at once (a year report over a large calendar is the worst case; bounded by the existing expansion budget and by chunking, and the bodies are already built one by one today). Covered by `server/tests/dav-query.php`, `server/tests/db/caldav-kept-here.test.php`, `server/tests/db/dav-journal.test.php`. No stored form. Not in code the Google rebuild (#274) replaces.
- Measure: `php tools/compare/perf/bench.php --checkout <tree> --label after --work <dir> --only dav.week,dav.month,dav.year --count` before and after, then `--reps 7 --warmup 1 --idle` alone for wall time; read queries and the median.

### 2. The window's edited days look up their series one query each

- Where: `Events::serializeFields` (Events.php:2775 and 2777) calls `parentTitle` and `parentAllDay` -> `parentSeriesRow` (Events.php:882), one `SELECT title, all_day FROM events WHERE id = ?` per distinct series that has an edited day in the answer. Reached from `GET /events` (every month/week load not answered 304), search results and `serializeRows`.
- The series rows are already in memory: `Events::window` loaded every master with `SELECT *` (Events.php:1022) a few lines earlier.
- Change (rule): the window seeds `$this->parentSeries` from the masters it loaded before serializing; the lookup stays for rows whose series was not loaded (filtered by calendar or query).
- Expected benefit: one query per distinct series with an edited day in the window. 0 to 3 on the bench seed (536 edited days over nine years); on an install with Google calendars, where edited days are common, plausibly 10 to 40 per month view, that is 3 to 15 ms. Confidence: high that the queries go; low on how many a real month has (the count query below settles it).
- Risk: very low; the cached values are the same columns of the same rows read in the same request. Covered by `server/tests/db/window-tag.test.php`, `server/tests/db/floating.test.php`, `server/tests/db/capped.test.php`, and every test that reads `seriesTitle`/`seriesKey`. No stored form.
- Measure: `SELECT COUNT(DISTINCT recurrence_parent_id) FROM events WHERE user_id = 1 AND deleted_at IS NULL AND recurrence_parent_id IS NOT NULL AND start_utc < '<month end>' AND end_utc > '<month start>';` on the install, and the bench's `read.month` query count before and after.

### 3. The worker's job checks scan the jobs table without an index

- Where: `server/bin/worker.php:468` `bc_enqueue_if_stale`: `SELECT id FROM jobs WHERE type = ? AND created_at > ? LIMIT 1`, nine calls per worker run (every minute). The jobs table has one secondary index, `idx_jobs_status_run (status, run_after)` (001_init.sql:216), which this query cannot use, so it reads in primary-key order from the oldest row until it meets a recent row of that type. Also `JobQueue::prune` (JobQueue.php:281, every run): `DELETE ... WHERE status IN ('done','failed') AND updated_at < ?`, which reads every done row through the status index to find the week-old ones.
- Table size by arithmetic: finished jobs are kept 7 days. Per day about 1,440 reminder_scan, 1,440 geocode_sweep (places on), 720 mail_ingest (enqueued whether or not mail is set up), about 1,440 duplicate_scan slices, 144 system_alerts, 24 rank_events, and one feed_poll per subscribed calendar per poll interval (30 calendars hourly: 720). About 6,000 a day, 40,000 rows held.
- Change: an index-only migration, `(type, created_at)` for the staleness checks and `(status, updated_at)` for prune.
- Expected benefit: about ten scans of roughly 40,000 rows a minute become index seeks; tens to a few hundred ms of database time per worker minute, on a shared database. Background only: no request gets faster. Confidence: medium (table size is reasoned, not read).
- Risk: very low; an index changes no result. Migrations must also run on SQLite for the unit suites (precedent: 066 checks information_schema before adding). No stored form.
- Measure: `SELECT COUNT(*), MIN(created_at) FROM jobs;` then `ANALYZE FORMAT=JSON SELECT id FROM jobs WHERE type = 'reminder_scan' AND created_at > NOW() - INTERVAL 50 SECOND LIMIT 1;` and read `r_rows` and `r_total_time_ms`.

### 4. Nothing checks that opcache is on

- Where: no mention of opcache in server/src, docs/install.md or docs/deploying.md. Every API request constructs the whole object graph in `bc_handle_api` (entry-web.php:95), so it loads Events (3.3k lines), Undo (3.7k), GoogleSeries (4.7k), Plugins (2k) and the rest.
- Measured here: 30 ms of compile per request with opcache off, on a fast machine; expect 60 to 100 ms on shared hosting CPUs. With opcache on, near zero.
- Change: one line in the health diagnostics (`HealthDiagnostics` / `SystemHealth`, read-only): opcache enabled or not, and a sentence in install.md. Not an optimization of the code; insurance for the hosting target.
- Expected benefit: zero where hosts enable it (most do for PHP 8.x), 30 to 100 ms per request where they don't. Confidence: high on the cost if off, unknown on how often it is off.
- Risk: none to data. No stored form.
- Measure: on a host, `php -r 'var_dump(function_exists("opcache_get_status") && opcache_get_status(false));'` is CLI only; the web SAPI's value is what matters, so the check belongs in a page served by the app.

### 5. Every series ever is loaded and expanded on every window, and COUNT series walk from their start each time

- Where: the masters query (`Events::window`, Events.php:1011, via `Floating::overlapSql(..., series: true)`, Floating.php:321) selects every series master with `start_utc` before the window's end, whether or not it ended years ago, then `SELECT *` (rowsWithinBudget) and `Recurrence::expand` each. `Recurrence::occurrences` (Recurrence.php:698) jumps to the window, which is cheap for an open or UNTIL rule (`seekPeriod`) but walks every elapsed occurrence for a COUNT rule (RuleIterator.php:266). The same selection runs in the reminder scan every minute when push or email is set up (`Reminders::dueForUserSlice`, Reminders.php:531), and in CalDAV (finding 6).
- Measured here: ended UNTIL series cost about 25 us each; ended COUNT series cost their whole count each time (2.4 ms for a daily COUNT=400 that ended in 2021, showing nothing); a running COUNT series pays its elapsed count (15.5 ms for a daily COUNT=3000 from 2020); open series 100 to 260 us for a month. "This and following" splits leave an UNTIL-ended old half behind every time, and Google and ICS feeds carry COUNT rules ("ends after N times").
- Estimate: 300 series masters, half of them ended, ten COUNT series with a few hundred elapsed occurrences each: roughly 10 ms of fixed expansion plus 20 to 40 ms of COUNT walks per window request, and the same every minute in the reminder scan. The real counts decide it.
- Change, three candidates, cheapest risk first: (a) a memo keyed by the series' own form (hash of rrule, start, zone, all-day) giving the instant of its last occurrence, filled on first need; an ended series is skipped before expansion. It cannot go stale (the key is the content), but a request writing it means adding a table to `RequestAuthority::BOOKKEEPING`, which is a recorded decision to amend. (b) A derived column on the master, the end of its last occurrence (NULL when unbounded), filled from the row's own state (a data correction by AGENTS.md's line) and used in SQL to drop ended series before they are loaded; every writer of rrule/start/zone must keep it, so it needs the writer inventory AGENTS.md asks for, and a missed writer hides a series. (c) COUNT reached by arithmetic inside `RuleIterator` (count candidates per period without making instants); fastest, but it touches the expander, the most verified code here.
- Expected benefit: on the estimate above, 20 to 50 ms off a month view and the same off each reminder scan run. Confidence: low until the counts below are read.
- Risk: (a) low, (b) medium (writers), (c) high (expander). Tests: `server/tests/expander.php`, `server/tests/series-model.php`, `server/tests/db/capped.test.php`, `server/tests/db/floating.test.php`, the reminder tests in `server/tests/db/run.php`, the series harness. Not in #274's code (the window and reminder scan are shared by every calendar).
- Measure: `SELECT COUNT(*) AS series, SUM(rrule LIKE '%COUNT=%') AS by_count, SUM(rrule LIKE '%UNTIL=%') AS by_until FROM events WHERE user_id = 1 AND deleted_at IS NULL AND recurrence_parent_id IS NULL AND rrule IS NOT NULL;` then, on the server, a corrected `profile-recurrence.php` that times `Recurrence::occurrences($master, monthStart, monthEnd)` per master and prints the top 15 with their rule (replace its sabre block; the rest of the script stands). `php server/bin/profile-events.php --months=1 --runs=5` gives the total and the expansion phase alongside.

### 6. The window's SQL reads every past row of the account, twice per request

- Where: masters (Events.php:1011) and edited days (Recurrence.php:218) both filter `user_id = ? AND start_utc < <window end> AND (...)`. The only index that serves it is `idx_events_user_start (user_id, start_utc)` (001_init.sql:89); the lower bound (`end_utc > <window start>`) sits inside an OR with the series branch, so no index can use it. The range is therefore every row of the account that starts before the window's end: all of history, soft-deleted rows included (they are not purged). The reminder scan's masters query (Reminders.php:533) has the same shape plus `ORDER BY id`, every minute. CalDAV's calendar-query (CalendarBackend.php:258) reads every row of the calendar with no time bound at all and tests each in PHP (4 us per single, 64 us per series, measured here).
- Estimate: 30,000 rows, 80% in the past: about 24,000 index entries plus a clustered-row lookup each, twice per window request. At 0.5 to 1.5 us per entry warm, 25 to 70 ms of database time per month request; much more on a cold buffer pool on shared hosting. A CalDAV report on a 10,000-event calendar adds 40 ms of PHP plus the transfer of 10,000 rows. The bench cannot show any of this (741 rows).
- Change (rule): one function says which stored rows can show in a window, in a form an index can bound on both ends. Single rows by `end_utc > start AND start_utc < end` served by a new `(user_id, end_utc)` index; series masters by `rrule IS NOT NULL` served by an index that finds them without the history (a prefix index on rrule, or the derived end column of finding 5b, which also drops ended series); the two read as a UNION. `Floating::overlapSql` already is the one function; it would return the two halves, and every caller (window, edited days, reminder scan, search's near-now, out-feeds, CalDAV ranges) takes them.
- Expected benefit: rows examined fall from all history to the rows ending after the window start plus the series. For a current-month view, likely 25 to 70 ms per request on 30,000 rows; nothing on a small install. Confidence: low until ANALYZE shows the real `r_rows` and time.
- Risk: medium. The floating widening (`Floating::widened`) must be kept exactly; a wrong bound drops events silently. Tests: `server/tests/db/floating.test.php`, `server/tests/floating.php`, `server/tests/db/window-tag.test.php`, `server/tests/db/search.test.php`, `server/tests/dav-query.php`, reminder cases in `server/tests/db/run.php`. Index migrations only, no stored form, unless 5b is taken.
- Measure: on the install, `ANALYZE FORMAT=JSON` of the masters query exactly as `Events::window` builds it (log it once with its parameters, or rebuild it from Events.php:1011 and Floating.php:321 for this month); read `r_rows` against the rows returned and `r_total_time_ms`. Then `SELECT COUNT(*), SUM(start_utc < NOW()), SUM(deleted_at IS NOT NULL) FROM events WHERE user_id = 1;`. For the bench, add a size to `tools/compare/perf/seed.php` with about 30,000 invented single events from 2016 to 2027 over ten calendars, some soft-deleted, and compare `read.month` and `dav.week`.

### 7. A feed that restamps itself on every fetch is parsed and synced in full every poll

- Where: `Feeds::poll` (Feeds.php:138) skips a feed whose body hash matches the last read. Many publishers write DTSTAMP as the time of the request, so the body differs on every fetch and the skip never fires; the poll then runs `Ics::parse` and `Feeds::sync`, which loads every row of the calendar (`SELECT * FROM events WHERE calendar_id = ?`, Feeds.php:1266) and settles them. Better-Cal stores nothing from DTSTAMP (it is only in Ics.php's list of date properties).
- Measured here: parse 35 ms for 500 events, 92 ms for 2,000 (30 MB peak); sync not measured (it is database work).
- Change (rule): the poll skips when what it would store is unchanged. Keep the body hash (cheapest), and add a hash of the parsed rows to `feed_reads` (a cache table: an empty or mismatched value only means one full read), compared after parsing and before syncing. This needs no list of properties to ignore, and also covers reordered or re-serialized feeds. The parse still runs; the sync does not.
- Expected benefit: for each restamping feed, the sync's database time per poll (unmeasured; likely 50 to 500 ms for a 2,000-event feed) on every poll. Background only, but it competes with the reminder scan for the worker's 50-second minute and for the shared database. Confidence: medium on the mechanism, unknown on how many of the install's feeds restamp.
- Risk: low; a cache column, full read at least daily stays (`FULL_READ_SECONDS`). The parsed hash must cover everything sync reads, including `$skipped`. Covered by `server/tests/db/feed-reads.test.php`, `server/tests/sync-standing.php`. No stored form of calendar data.
- Measure: `SELECT c.id, c.poll_interval_minutes, c.last_polled_at, r.full_at FROM calendars c JOIN feed_reads r ON r.calendar_id = c.id WHERE c.kind = 'subscribed';` (a feed whose `full_at` tracks `last_polled_at` is read in full every time), and for one such feed fetch twice and `diff -I '^DTSTAMP'` the bodies. Time `Feeds::sync` on an unchanged parsed feed on a dev install.

### 8. CalDAV with the account password runs bcrypt on every request

- Where: `Dav\AuthBackend::passwordOrTokenMatches` (AuthBackend.php) calls `password_verify` per request, plus the LoginGuard reads and writes (`begin`, `succeeded`). An API key (`bc_...`) takes the fast path (`tokenMatches`, two indexed queries).
- Measured here: 213 ms per verify at PHP's default cost 12. A phone syncing every 15 minutes across several calendars sends dozens of requests per sync.
- docs/caldav.md already says a key is recommended. The remaining lever is telling the person: an Activity or Review line when a CalDAV client signs in with the password, suggesting a key. Caching a verified password is a security trade and not proposed here.
- Confidence: high on the cost per request; unknown on how many installs use the password. Owner's call.

## Ranking

Worth doing now (each is small, with a clear bound on what it changes):

1. CalDAV calendar-query batch fetch (finding 1): about 284 -> 20 queries on the week report, est. 55 to 110 ms of 161 ms; high confidence on queries; low risk.
2. Window seeds the series cache from loaded masters (finding 2): one query per distinct series with an edited day, 3 to 15 ms on a Google-heavy month; high confidence it goes, low on the count; very low risk.
3. Jobs indexes `(type, created_at)` and `(status, updated_at)` (finding 3): ten table scans a minute become seeks; background only; confirm the table size with one COUNT first.
4. Opcache line in health diagnostics and install docs (finding 4): 30 to 100 ms per request where it is off, nothing where it is on.

Worth measuring first (the numbers depend on the install's data, and the changes touch shared machinery):

5. Ended and COUNT series on every window and reminder scan (finding 5): 20 to 50 ms per request on the estimate; read the series counts and run a corrected profile-recurrence first; memo (a) is the low-risk path.
6. Window SQL over all history (finding 6): 25 to 70 ms per request at 30,000 rows on the estimate; ANALYZE the real query first; the fix goes through `Floating::overlapSql` for every caller.
7. Feed polls defeated by restamping (finding 7): a sync per poll per such feed; check `feed_reads.full_at` first.
8. CalDAV password auth (finding 8): 213 ms per request when used; a nudge, the owner's decision.

Not worth it (so nobody rediscovers them):

- `Calc::reads` building a throwaway iterator per series (Recurrence.php:314): 4 to 7 us each, 1 to 2 ms at 300 series.
- Precomputing sort keys for the window's `usort`: 2.3 ms for 1,165 occurrences, perhaps 1.5 ms saved.
- Memoizing the row-constant part of `serialize` (reminders, style, invite, own fields per row instead of per occurrence): 6.2 us per occurrence, about 3 to 4 ms saved on a month view.
- `json_encode`: 0.4 ms per month.
- The per-request migration check (`InstallPages::needsDatabaseUpdate`: two globs, a hash, a marker file): sub-millisecond.
- The change cursor and status polls (`ChangeCursor`): small indexed aggregates; request frequency is the request-count review's question.
- The two-step budgeted row load and its transaction (`Events::rowsWithinBudget`): about 1 ms, and it is a deliberate memory bound.
- Building every controller per API request (entry-web.php:95): 1 to 3 ms with opcache on; lazy construction is not worth the churn.
- `SET time_zone` at connect: one round trip. Prepares are emulated already (one round trip per query).
- Series edits (preview plus commit, the model run twice): ordinary edits measured at 95 ms; large-series rule changes 688 ms are rare and on the verified engine, and the Google series path is due for the #274 rebuild. Revisit after the rebuild, not now.
- Feed sync's `SELECT *` of the whole calendar on a changed poll: memory economy, the other review's.
- Outbound feeds with no validator (`OutFeedsController::publicFeed`): subscribers poll every few hours and the feed is capped at 500 events.
- Google polls: already incremental by sync token.
- The duplicate scan's continuous rescan (one 500-row slice a minute, forever): background; making it incremental is a mechanism with its own model, not a tweak.
- The search path: deliberately reads every matching series (decision "The limit keeps the first of the exact order", 300 series about 40 ms); fulltext indexes exist on title and description.

## Not determined without measurement

- How the month view's 99 ms divides between process start, auth, SQL and PHP on the real install; the PHP-side pieces measured here add to about 20 to 30 ms on the bench's data.
- Real counts: rows per account, share in the past, soft-deleted rows, series masters, ended series, COUNT series and their elapsed occurrences, edited days per month.
- Rows examined and time of the window's SQL on real data (ANALYZE), and whether the optimizer's choice matches the reasoning above.
- How the CalDAV report's 161 ms splits between queries and body building.
- The jobs table's real size and the cost of its scans.
- How many subscribed feeds restamp on every fetch, and the cost of one unchanged sync.
- Whether the hosting target runs with opcache on.

## Side notes

- ROADMAP.md lists "Feed refreshes skip feeds that haven't changed (#179)" under 1.8; conditional GET plus body hash shipped with migration 073. Either #179 means more than that or the roadmap line is stale.
- `server/bin/profile-recurrence.php` should time `Recurrence::occurrences` rather than sabre's iterator before anyone relies on it again.
