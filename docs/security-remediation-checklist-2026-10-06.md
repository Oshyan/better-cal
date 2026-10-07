# Security remediation checklist — 2026-10-06 scan

This is the working verification ledger for remediation selected from the
2026-10-06 repository-wide security scan. A checked source item means the fix
and its automated regression coverage are present in Git; it does **not** mean
the behavior has been exercised against the deployed application or a live
provider. Keep those two evidence layers separate.

The checklist currently covers the high finding and the six medium findings
selected for current-HEAD validation. It does not mark the rest of the full
scan report as fixed; those findings still need triage and remediation rounds.

## Remediation status

| ID | Finding | Source remediation | Automated verification | Live/deployed verification |
|---|---|---:|---:|---:|
| H1 | Google credentials and moves survived compromise recovery | [x] | [x] | [ ] |
| M1 | Session-created feed and reminder-email channels survived sign-out recovery | [x] | [x] | [ ] |
| M2 | High-frequency recurrence denial of service | Suppressed after installed-Sabre validation | [x] | Not required |
| M3 | EXDATE cardinality bypassed parser and durable-work budgets | [x] | [x] | [ ] |
| M4 | Distant deep links created unbounded authenticated request fanout | [x] | [x] | [ ] |
| M5 | API-token-created subscriptions survived token revocation | [x] | [x] | [ ] |
| M6 | Geocoding redirects bypass the shared outbound-address policy | [ ] | [ ] | [ ] |

Round 1 (H1) and Round 2 (M1/M5) shipped to `main` in `f58c682`. Phase 3
(M3/M4) is in the commit containing this checklist. M6 is the next unresolved
finding in the focused validation set.

## Completed local evidence

- [x] H1 regression coverage for recent-password authorization, exact-session
  OAuth state, compromise quarantine, move cancellation, cached-calendar
  preservation, session/reset races, reconnect identity and migration 036.
- [x] M1/M5 regression coverage for channel rotation, subscription provenance,
  token revocation/expiry, owner adoption and migration 037.
- [x] M3 hostile controls for packed, folded, repeated, duplicate, mixed-case
  and grouped EXDATE properties; the former 5,000-value payload is refused
  before Sabre parsing, while a 607-value representative control passes.
- [x] M4 year-9999 control produces no broad window and one 72-hour target
  window; the legitimate 28-day reminder control keeps its existing preload.
- [x] Server suite: 1,872 passed, 0 failed.
- [x] Frontend smoke suite: 652 passed, 0 failed in each of
  `America/Los_Angeles`, `UTC` and `Pacific/Auckland`.
- [x] Frontend static checks: 337 passed, 0 failed across 103 modules.
- [x] MCP suite: 63 passed, 0 failed.
- [x] Time-zone harness: 3,840 generated cases and zero changed occurrence
  windows after export and re-import.
- [x] Independent read-only adversarial review of each implemented round.

## Live end-to-end exercises

Use a controlled non-production installation first. Record the deployed commit,
application version, migration state, PHP/MySQL versions, browser, time zone and
provider account used. Do not attach tokens, cookies, event contents or other
private calendar data to the evidence.

### H1 — Google compromise recovery

- [ ] In browser A, let the ten-minute confirmation window expire and verify
  that connect/reconnect, add-calendar and move start/retry request the
  Better-Cal password; routine browsing and background sync must not prompt.
- [ ] Start OAuth in browser A and confirm that browser B cannot complete A's
  callback, even when both browsers are signed in as the same Better-Cal user.
- [ ] Connect a disposable Google account, subscribe to one calendar and begin
  a test move. Run `server/bin/seed.php --revoke-tokens` while work is pending.
- [ ] Confirm that the Google account becomes paused, unfinished moves stay
  cancelled, cached events remain visible/read-only, and no poll or write
  resumes from already-queued work.
- [ ] Confirm that reconnecting the same Google email restores the existing
  account/calendar identity rather than duplicating it.
- [ ] Inspect the disposable Google calendar for already-uploaded events from a
  partial move and document any manual cleanup required.

### M1 and M5 — standing-channel revocation

- [ ] From browser A, create a public outbound feed and set a custom reminder
  address. From browser B, use **Sign out everywhere else**.
- [ ] Confirm that the old public-feed URL no longer works, a replacement URL
  is shown, the custom reminder destination returns to the account email, and
  browser B remains signed in as documented.
- [ ] Create an ICS subscription with a disposable API token, verify one poll,
  then revoke or expire the token and run the worker. The subscription must
  pause without deleting its cached events or organization.
- [ ] Exercise each owner decision on a paused token-created subscription:
  keep it as account-owned, adopt the cached events as local, and delete it.
  Confirm that only the selected action occurs and that polling authority
  matches the resulting state.

### M3 — EXDATE work budgets

- [ ] Upload/import a series at the configured per-event limit and confirm it
  succeeds; repeat one value above the limit and confirm a clear refusal with
  no partially created calendar.
- [ ] Sync a controlled ICS feed successfully, then serve an over-limit update.
  Confirm the poll records an actionable error and the last good cached events
  remain visible. Restore a safe response and confirm polling recovers.
- [ ] PUT an over-limit CalDAV object and confirm the client receives a bounded
  failure while the prior object remains unchanged.
- [ ] Deliver an over-limit iMIP fixture and confirm the mail worker stays
  healthy, creates no partial event/review state and continues to later mail.
- [ ] Exercise a controlled Google sync batch over the aggregate limit and
  confirm the prior snapshot and sync token remain authoritative.
- [ ] In a disposable database, insert a legacy over-limit stored series.
  Confirm recurrence expansion shows only its safe first occurrence, editing
  is refused before any Google write, and replacing it with a bounded series
  restores normal behavior. Remove the fixture afterward.
- [ ] Verify operator overrides below the hard ceilings take effect and values
  above 2,048 per event or 8,192 per input are clamped.

### M4 — deep-link request bounds

- [ ] Click a real notification link up to 28 days early and confirm it opens
  the correct occurrence with the normal nearby calendar preload.
- [ ] Open far-future items from Review and from restored/resume state. Confirm
  the correct occurrence opens and browser network logs show no requests for
  intervening date ranges.
- [ ] Open a far target already present in the occurrence cache and confirm no
  event-window request is made before the detail view opens.
- [ ] Open malformed and stale distant links and confirm each makes at most one
  bounded target-window request before showing the existing not-found message.

### M6 — geocoding egress (after remediation)

- [ ] Confirm a normal public-provider redirect still succeeds.
- [ ] Confirm redirects to loopback, RFC1918, link-local, IPv6-local,
  cloud-metadata and configured NAT64-private destinations are refused after
  final DNS resolution.
- [ ] Confirm redirect-count, response-byte and timeout limits fail cleanly and
  do not stall a PHP worker.

## Deployment closeout

- [ ] Deploy the exact reviewed commit with migrations 036 and 037.
- [ ] Confirm the running Settings version and deployed Git revision.
- [ ] Run the application smoke check and inspect worker, PHP and web-server
  logs for new recurrence, Google, subscription or deep-link errors.
- [ ] Complete the live exercises above, recording result, date, environment,
  tester and sanitized evidence for each item.
- [ ] Recheck rollback: application rollback must not roll database migrations
  backward or silently re-enable quarantined Google/subscription state.
- [ ] Update `SECURITY.md` only when the claimed deployed/live verification has
  actually been completed.

## Evidence record template

For each live exercise, record:

- Finding and checklist item:
- Date and tester:
- Environment and deployed commit:
- Browser/client/provider and time zone:
- Expected result:
- Observed result:
- Sanitized evidence location:
- Cleanup performed:
- Disposition: pass, fail, partial or blocked
