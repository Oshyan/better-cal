# Google Probe

Runs the probe the [Google series survey](../../docs/design/google-series-survey.md#the-probe) specifies: writes recurring events to one throwaway Google calendar, reads back what Google stored after each write, and writes a report. It answers what the docs leave open (what a master change does to exceptions, the instance id forms, etags, what a delta lists, what a split leaves behind) from Google's own answers, and can be rerun whenever Google changes. A maintainers' tool, like [tools/compare](../compare/), not product code.

**It writes to the calendar you name.** Make a new, empty calendar in the Google account for it. The probe refuses the account's main calendar, a calendar the account may only read, and a calendar with events it didn't make (unless `--allow-other-events`, and even then it never touches them). It calls nothing that writes to any other calendar.

## Running It

On an install with a connected Google account (Settings, Connections), from the repository root:

```bash
php tools/google-probe/run.php --calendar <googleCalendarId> --account <google_accounts id or email>
```

- `--calendar`: the throwaway calendar's id, from its settings in Google Calendar ("Integrate calendar", Calendar ID). It doesn't need to be connected in Better-Cal, and it's quieter if it isn't.
- `--account`: the connected account, by its `google_accounts` id or its address as stored. The probe takes its access token through `GoogleAuth`, as the worker does.
- `--only 1,3`: run some of the twelve groups. `--only 12` runs only the probes still owed.
- `--guest <address>`: runs group 8 (mail), inviting that address. Use a mailbox you own and can read; it is the only case that leaves out `sendUpdates=none`, once, to see the default.
- `--report <dir>`: where the report goes; `workfolder/google-probe/` by default (gitignored).
- `--allow-other-events`: run on a calendar that has events the probe didn't make.

It loads the app's config and database the way `server/bin/worker.php` does, so run it where the app's `.env` is. A full run makes about a thousand requests, pauses a quarter second before each write and takes a few minutes. Every write sends `sendUpdates=none` except the one mail case.

## What It Does

Twelve groups: the nine the survey lists (rule forms, each exception kind alone (timed and all-day, plus Pacific/Auckland and Asia/Tokyo for the instance keys), master changes over exceptions, the split, etags and If-Match, deletes and their reverse, sync, mail, and the two live checks from the audit), and group 10, what an undo of a change to all events would meet (its first case waits ten minutes on purpose), and group 11, whether a list by an event's iCalUID carries a write at once (thirty inserts, thirty conditional PATCHes of a single event and of one day of a series, ten deletes, each listed at once and re-listed up to five times a second apart where it differs; it reports counts and milliseconds), and group 12, the probes still owed (P3, P7 and P8 from the conflict choice's section 8, the last day of a series deleted on its own from issue 272, six questions from issue 275, and 12.11, whether a list by one iCalUID ever holds two events after events.import twice, over a deleted event, or events.insert with an iCalUID already held), each read through the list by iCalUID before and after its writes; its one guest is invented, at a reserved domain. Each case starts from a state it makes itself, reads it back (the master, `events.instances`, the `singleEvents=false` list of the series), takes a sync token, writes, then reads back again and lists the sync-token delta from that token, and finally runs a second delta to see whether anything still moves. The finding is written only from what came back. Group 8 can't read a mailbox, so its findings give the times to check against. Group 9 runs through the app, not the API, and the report gives its steps to do by hand.

A read-back is retried on a 404 or on a delta that doesn't yet show the write, a bounded number of times with backoff, and the report says how many tries it took. Nothing waits on a timer for correctness.

Every event the probe makes carries a marker: a private extended property `betterCalProbe` and `[better-cal-probe]` in its description. Each case deletes what it made; the run starts and ends by deleting any marked event, so a rerun cleans up after a run that was stopped.

## What It Writes

`probe-<date>.md`: a section per group and a table per group (case, what was sent, what came back, finding). `probe-<date>.json`: every request and response, raw, by case and phase (setup, before, settle, write, delta, after, second delta, cleanup). Both are brought up to date after every case, so a stopped run leaves what it got.

Neither holds the account's address, the calendar id, a guest's address, tokens, event links (Google's `htmlLink` encodes the calendar's address), display names or sync tokens: each is replaced by a placeholder. An event the probe did not make (with `--allow-other-events`) is cut to its id and status wherever an answer carries it (a list, a delta, instances, a GET), decided by the probe's own record of what it made, never by what the event holds. It reads nothing from any other calendar. Event titles are invented ("Probe weekly", "Probe all-day").

The findings go into [the model's Google section](../../docs/design/series-model.md#google-as-a-contract) by hand, and into the harness's Google stand-in.

## Tests

```bash
php tools/google-probe/test.php
```

Runs the whole probe against a fake API (`lib/FakeGoogle.php`) that models ids, instance ids, etags, If-Match, sync tokens and cancelled instances, and checks the probe's own plumbing: every group runs, the report is written and scrubbed, cleanup deletes what was made and what an aborted run left, a 412 is reported as a conflict, only the mail case leaves out `sendUpdates`. The fake models none of Google's series behaviour, so the test says nothing about Google.

## Replaying the App's Requests

The probe's cases are written by hand. The app's tests run against the stand-in (`lib/FakeGoogle.php`), and twice the stand-in was more forgiving than Google and a fault shipped. The replay closes that loop: it records every request the app itself sends the stand-in in a scenario, with the stand-in's answer, then sends the same requests to Google and compares each answer with the recorded one.

What it proves: for each recorded request, that Google answers as the stand-in did, after the normalizing listed below, up to the first request where they differ, which it reports with both answers and the difference. What it doesn't prove: anything about requests the app didn't send in the recorded scenarios, a request after a scenario's first divergence (later requests depend on it), what an answer holds in a field the normalizing leaves out, or that the app does the right thing with an answer. A scenario that answers the same on Google is evidence the stand-in models that path; a divergence is a place where it doesn't, to be judged before the stand-in or the app changes.

### Recording

A trace is one scenario's requests and answers, in order, as JSON (`lib/Trace.php` has the format). `GOOGLE_TRACE=<file.json>` makes the edit harness's stand-in (`tools/edit-harness/GoogleStand.php`) record through a wrapper around its transports; nothing in the stand-in changes. The trace set comes from one process per scenario, so each trace holds its scenario alone: the five scenarios in `record-app.php` (a timed single event and an all-day one created, changed and deleted, a series deleted whole, plain and over an edited and a deleted day, and a calendar moved to Google whose two series each hold a day of the other form), the harness's fixed cases whose names start with `google-`, every sequence, and drawn cases 1 to 15 of seed 7, a poll after each write. On the test database:

```bash
BC_TEST_DB=127.0.0.1:33308 php tools/google-probe/record.php --out workfolder/google-traces --zone America/Los_Angeles
```

`--zone` is the stand-in calendar's own zone (UTC by default), which should be the zone of the calendar the set will replay on; it changes nothing the app sends, only the top-level `timeZone` of list answers, since every timed event the app sends names its own zone. It prints how many scenarios and requests it kept (49 and 1,147 when it was written), what kinds of write they cover, and any kind it should cover and doesn't. It also checks that every write the app sent to an event (POST, PATCH, PUT, DELETE) carries `sendUpdates=none`, so Google mails no guest, over every scenario it ran, kept or not, and exits 1 naming each one that doesn't. A change the harness makes to the stand-in outside a request (its adopt of a series it just made, a rule written straight into the row) is recorded with the PATCH that repeats it, which the replay sends first; a change no request can repeat moves the trace to `unreplayable/`.

### Replaying on the Stand-In

```bash
php tools/google-probe/replay.php --trace workfolder/google-traces --target fake
```

Each trace goes to a fresh stand-in set as it was recorded (its zone, list settings and occurrences). Every scenario must answer the same; anything else is a fault in the replay or the recorder. `php tools/google-probe/replay-test.php` checks the tool itself: traces of every probe group and of hand-made scenarios replay with no divergence, a stand-in with one behaviour changed diverges at the request the trace says it must, a tampered answer diverges there, ids, etags and tokens map, and everything made is marked and deleted, also after an error.

### Replaying on Google

The maintainer runs this, on the same kind of throwaway calendar the probe uses, in the zone the traces were recorded in (record with `--zone` set to the calendar's; a trace from another zone is skipped and listed):

```bash
php tools/google-probe/replay.php --trace workfolder/google-traces --calendar <googleCalendarId> --account <google_accounts id or email>
```

It refuses the main calendar, a calendar it can't write and one with events neither it nor the probe made (unless `--allow-other-events`), deletes what an earlier replay left, and goes through `lib/Api.php` (the token, `sendUpdates=none` on every write, a pause between writes, retries on rate limits). Every event it creates carries the probe's marker in its private extended properties (`betterCalProbe`, a value starting `replay-`), and each scenario deletes what it created when it ends, also after an error. `--only <text>` replays the scenarios whose names contain it; `--read-tries N` (5 by default) is how often a read that differs is asked again. The report goes to `workfolder/google-replay/` as `replay-<time>.md` and `.json`, scrubbed as the probe's is. Exit 0 when every scenario answered as recorded.

### What Is Mapped and Normalized

Each of these is a place a real difference could hide; any other difference is a divergence.

- **Ids.** An event id is learned from each insert's answer; an insert with an id of the app's own goes out with a fresh one (Google never takes an id twice), the same fresh one each time that id is sent. An instance id (`<id>_<YYYYMMDD>`, `<id>_<YYYYMMDDTHHMMSSZ>`) maps through its series id with the suffix kept, so a suffix Google forms differently is a divergence. iCalUIDs map the same way.
- **Etags.** If-Match maps by the event and the value, then by the value alone, to the etag the target gave in the answer that showed the same event. A value the stand-in never gave goes as recorded, and the report notes it.
- **Tokens.** Sync and page tokens map to the target's from the same answer; one never seen goes as recorded, noted.
- **Left out on both sides:** `etag`, `updated`, `created` (times of writing), `sequence` (the app counts it as Google's own bookkeeping, `GoogleSeries::VOLATILE`), `htmlLink`, `creator`, `organizer` (the account and calendar differ between targets); the replay's marker in `extendedProperties.private` (and that object when nothing else is in it). What Google writes on its own and the stand-in can model (default `reminders`, `eventType`, a `dateTime`'s offset, empty fields left out) is the stand-in's to answer alike, not the replay's to ignore: see [replay-2026-11-13.md](../../docs/design/research/google-series/replay-2026-11-13.md).
- **Read as equal:** an `RRULE` line's parts in any order (`EXDATE` and `RDATE` lines are compared as text); keys in any order.
- **Lists.** Items compared as a set, matched by id and status, in any order. Items of events the scenario doesn't know (by id or series id), which a whole read of the calendar lists from other scenarios, are left out and counted. Whether there is a further page is compared; a target that splits a list the stand-in gave whole is read to its end and compared as one, and the report notes it. Nothing else at a list's top level is compared (its zone is checked once against the trace's).
- **Cancelled items.** On an item both sides list as cancelled, `id`, `status`, `recurringEventId` and `originalStartTime` are compared strictly; any other field that differs is listed as a shape note beside the result, not a divergence.
- **Status and errors.** Every answer's status must be the same (200 against 204 is a divergence); for an error, then `error.message`. An error's reason is not compared: the stand-in gives Google's own reason for the statuses a live answer has shown (400 `invalid`, 404 `notFound`, 410 `deleted`, a PATCH's 412 `failedPrecondition`) and `fake` for any other.
- **The calendar and its list entry:** the status alone.
- **Reads that lag.** A GET whose answer differs is asked again (`--read-tries`, 2 seconds apart) before it counts; the divergence shown is the last try's. A write is never sent twice.
- **Setup.** The PATCH that repeats a harness change outside a request is not compared, only learned from; a target that refuses it makes that scenario unreplayable there, with the target's reason.

## The Access Probe

What Google answers the connected account when access to a calendar shared with it is lowered step by step and then taken away: which request says "may read but not write", which says "may not see", which says "deleted", and whether a write is refused with 403 and which reason. Nobody has seen these answers yet; Better-Cal's rule for a calendar it may no longer change waits on them.

It needs a second Google account, which owns a scratch calendar and shares it with the account Better-Cal is connected to. Each run is one stage and sends the same fixed set (`lib/AccessProbe.php` lists it): the account's calendar list entry, the calendar, two event lists (one from the sync token the stage before saved), an insert, a read and a title change of each event the first stage made, and a read of an event that never existed. The first stage, `writer`, also makes a single event and a weekly series of four with its second day renamed; nothing is deleted until the calendar is.

### The Owner's Steps

1. In the second Google account, create a new calendar named "Access probe". Add one event to it by hand, named "Owner event", any day next week.
2. Share it with the address of the account Better-Cal is connected to, with "Make changes to events".
3. In the connected account, open the sharing mail and add the calendar, so it shows in that account's list of calendars.
4. Give the manager the calendar's id (the calendar's settings, "Integrate calendar", Calendar ID).
5. When the manager asks, change the sharing to "See all event details".
6. When asked, change it to "See only free/busy (hide details)".
7. When asked, remove the connected account from the sharing.
8. When asked, share it again with "Make changes to events" (and add it again from the mail if Google sends one).
9. When asked, delete the calendar.

Wait for the manager's run between steps. A sharing change can take a minute to reach the API; if a stage looks unchanged from the one before, run it again (the report keeps both).

### The Manager's Commands

Where the app's `.env` is, one per step, with the same `--report` folder every time (the default, `workfolder/google-access/`, is gitignored):

```bash
php tools/google-probe/access.php --calendar <id> --account <google_accounts id or email> --stage writer     # after step 4
php tools/google-probe/access.php --calendar <id> --account <google_accounts id or email> --stage reader     # after step 5
php tools/google-probe/access.php --calendar <id> --account <google_accounts id or email> --stage freebusy   # after step 6
php tools/google-probe/access.php --calendar <id> --account <google_accounts id or email> --stage unshared   # after step 7
php tools/google-probe/access.php --calendar <id> --account <google_accounts id or email> --stage restored   # after step 8
php tools/google-probe/access.php --calendar <id> --account <google_accounts id or email> --stage deleted    # after step 9
```

`writer` refuses, writing nothing, when the calendar isn't in the account's list, when the account owns it, when Google doesn't say `writer`, or when it holds more than the owner's one event (`--allow-other-events` overrides the last). `--stage cleanup` sends only the deletes of what the state file lists and says what it could not delete; it is needed only when the calendar is kept instead of deleted. Requests per stage, with the owner's event: 18 for `writer`, 15 for each later stage (13 and 16 without it); a retry Google asks for adds one.

Each run adds a table to `access-<hash>.md` (and `.json`, every exchange raw), where the hash stands for the calendar id; the state (event ids, their last etags, the last sync token and the stage it came from) is `access-state-<hash>.json` beside them and holds the raw token. The report opens with a table of every request's status by stage and ends with the questions to answer from it, left blank. It is scrubbed as the probe's is (the owner's own event and any other the probe did not make are cut to their id and status, the state file's record of what earlier stages made deciding which), and any other address in it is replaced too. Every write sends `sendUpdates=none`, and every event made carries the probe's marker.

### Round 2

A second live round, run with `--round 2` on every stage and a fresh `--report` folder so the state starts empty; the owner's steps are the same up to step 8 (the calendar can be deleted, or kept and cleaned up). What it adds to the fixed set, each a row like the others:

- At `writer`, after what it makes today: three sets of deletables (a single timed event, and a weekly series of four with its second day renamed), one each for `reader`, `freebusy` and `unshared`, each read back for its current etag (the renamed day's record its own). The owner's event's etag after the stage is kept as the one stored at writer.
- At `reader`, `freebusy` and `unshared`, in this order, each conditional on the etag stored: D1 deletes that stage's single event, D2 its renamed day's record, D3 its series, D4 the owner's own event (on the etag stored at writer). A delete that is not refused is noted and followed by a GET. None of these is sent at `writer` or `restored`.
- At every stage: U1 and U2 list the single event and the series the writer stage made by iCalUID (`singleEvents=false`, `showDeleted=true`): item count, `accessRole`, and each item's key names (the items stay raw in the JSON). Request 4's row adds the key names of the first of the probe's own items. I inserts an event under the id of the writer stage's single event (409, 403 or another answer).
- At `restored`, first of all, R0 PATCHes the single event's title once a second until Google takes it or 180 seconds pass, each try a row, and a note gives the try and the seconds from the stage's start to the first one taken. At its end, R9 lists what of the deletables still exists, by iCalUID with `showDeleted`.

`--stage cleanup` deletes the deletables too.

```bash
php tools/google-probe/access.php --calendar <id> --account <google_accounts id or email> --report workfolder/google-access-2 --round 2 --stage writer     # after step 4
php tools/google-probe/access.php --calendar <id> --account <google_accounts id or email> --report workfolder/google-access-2 --round 2 --stage reader     # after step 5
php tools/google-probe/access.php --calendar <id> --account <google_accounts id or email> --report workfolder/google-access-2 --round 2 --stage freebusy   # after step 6
php tools/google-probe/access.php --calendar <id> --account <google_accounts id or email> --report workfolder/google-access-2 --round 2 --stage unshared   # after step 7
php tools/google-probe/access.php --calendar <id> --account <google_accounts id or email> --report workfolder/google-access-2 --round 2 --stage restored   # after step 8, at once
php tools/google-probe/access.php --calendar <id> --account <google_accounts id or email> --report workfolder/google-access-2 --round 2 --stage cleanup    # when the calendar is kept
```

### Stage listread

Whether a list by iCalUID (`GET /events?iCalUID=<uid>&singleEvents=false`, with `showDeleted=true` and `false`) returns the same resource a GET by id does, which decides whether a write's first read can be that list and bring the calendar's `accessRole` with it. It runs on any calendar the account may write (owner or writer, the probe's own throwaway calendar included; not the main one), needs no owner's event, and leaves the other stages' state alone:

```bash
php tools/google-probe/access.php --calendar <id> --account <google_accounts id or email> --stage listread
```

It makes its own marked events and compares, field by field, for: a timed single event and an all-day one, inserted; one made with `events.import` and an iCalUID of its own (the request `GoogleWriter::import` sends); a second import with the same iCalUID and another start (what Google answers, and if it makes a second event, what the list returns and in what order); a single event deleted; a single event renamed (whether the list's etag is the PATCH answer's, and whether If-Match on the list's etag is accepted); a weekly series of three with one day renamed, read by its iCalUID against a GET of the master and of the day; and an id and an iCalUID that never existed. Each list row gives the list's `accessRole`, its item count, both etags and every field that differs or is missing on either side. Everything it made is deleted at the end, also after an error; what a run that died left is listed in the state file and deleted first by the next run. 41 requests on Google when the second import makes an event.

`php tools/google-probe/access-test.php` checks the plumbing against the stand-in behind a gate that plays each stage. What the gate answers in each state is invented, so the test says nothing about Google. The test skips listread's import cases (31 requests); the stand-in has had an `events.import` route since 12.11 and the move scenario, modelled only as far as listread's L3 and L4 showed.

## Files

`run.php` (the command line and the real transport), `lib/Api.php` (every request: token, `sendUpdates=none`, retries, the exchange record), `lib/Probe.php` (the groups and cases, the read-backs, the findings, cleanup), `lib/Report.php` (the report and its scrubbing), `lib/FakeGoogle.php` and `test.php` (the tests). The replay: `lib/Trace.php` (the recorder and the coverage kinds), `lib/Replay.php` (mapping, normalizing, comparing, cleanup, and the two targets), `replay.php` (the command line), `record.php` and `record-app.php` (the trace set), `replay-test.php` (its tests). The access probe: `access.php` (the command line), `lib/AccessProbe.php` (the fixed set, the state file, the report), `access-test.php` (its tests).
