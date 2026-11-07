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
- `--only 1,3`: run some of the nine groups.
- `--guest <address>`: runs group 8 (mail), inviting that address. Use a mailbox you own and can read; it is the only case that leaves out `sendUpdates=none`, once, to see the default.
- `--report <dir>`: where the report goes; `workfolder/google-probe/` by default (gitignored).
- `--allow-other-events`: run on a calendar that has events the probe didn't make.

It loads the app's config and database the way `server/bin/worker.php` does, so run it where the app's `.env` is. A full run makes about a thousand requests, pauses a quarter second before each write and takes a few minutes. Every write sends `sendUpdates=none` except the one mail case.

## What It Does

Nine groups, as the survey lists them: rule forms, each exception kind alone (timed and all-day, plus Pacific/Auckland and Asia/Tokyo for the instance keys), master changes over exceptions, the split, etags and If-Match, deletes and their reverse, sync, mail, and the two live checks from the audit. Each case starts from a state it makes itself, reads it back (the master, `events.instances`, the `singleEvents=false` list of the series), takes a sync token, writes, then reads back again and lists the sync-token delta from that token, and finally runs a second delta to see whether anything still moves. The finding is written only from what came back. Group 8 can't read a mailbox, so its findings give the times to check against. Group 9 runs through the app, not the API, and the report gives its steps to do by hand.

A read-back is retried on a 404 or on a delta that doesn't yet show the write, a bounded number of times with backoff, and the report says how many tries it took. Nothing waits on a timer for correctness.

Every event the probe makes carries a marker: a private extended property `betterCalProbe` and `[better-cal-probe]` in its description. Each case deletes what it made; the run starts and ends by deleting any marked event, so a rerun cleans up after a run that was stopped.

## What It Writes

`probe-<date>.md`: a section per group and a table per group (case, what was sent, what came back, finding). `probe-<date>.json`: every request and response, raw, by case and phase (setup, before, settle, write, delta, after, second delta, cleanup). Both are brought up to date after every case, so a stopped run leaves what it got.

Neither holds the account's address, the calendar id, a guest's address, tokens, event links (Google's `htmlLink` encodes the calendar's address), display names or sync tokens: each is replaced by a placeholder. It reads nothing from any other calendar. Event titles are invented ("Probe weekly", "Probe all-day").

The findings go into [the model's Google section](../../docs/design/series-model.md#google-as-a-contract) by hand, and into the harness's Google stand-in.

## Tests

```bash
php tools/google-probe/test.php
```

Runs the whole probe against a fake API (`lib/FakeGoogle.php`) that models ids, instance ids, etags, If-Match, sync tokens and cancelled instances, and checks the probe's own plumbing: every group runs, the report is written and scrubbed, cleanup deletes what was made and what an aborted run left, a 412 is reported as a conflict, only the mail case leaves out `sendUpdates`. The fake models none of Google's series behaviour, so the test says nothing about Google.

## Files

`run.php` (the command line and the real transport), `lib/Api.php` (every request: token, `sendUpdates=none`, retries, the exchange record), `lib/Probe.php` (the groups and cases, the read-backs, the findings, cleanup), `lib/Report.php` (the report and its scrubbing), `lib/FakeGoogle.php` and `test.php` (the tests).
