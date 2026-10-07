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
| H1 | Google connections and moves stayed active after compromise recovery | [x] | [x] | [ ] |
| M1 | Public feeds and custom reminder email survived sign-out recovery | [x] | [x] | [ ] |
| M2 | Very frequent repeat rules could overload the server | Suppressed after installed-library validation | [x] | Not required |
| M3 | Very large recurrence skip lists could bypass safe work limits | [x] | [x] | [ ] |
| M4 | Far-away event links could make too many browser requests | [x] | [x] | [ ] |
| M5 | API-key-created subscriptions kept updating after key revocation | [x] | [x] | [ ] |
| M6 | Location-lookup redirects could reach private network addresses | [x] | [x] | [ ] |

Round 1 (H1) and Round 2 (M1/M5) shipped to `main` in `f58c682`. Phase 3
(M3/M4) shipped in `8a3b7d3`. Phase 4 (M6) shipped in `d750f7d`. Its deployed
exercises remain deliberately open.

## Completed local evidence

This section is the technical record for reviewers; it is not a manual to-do
list for the owner.

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
- [x] M6 geocoding goes through one HTTPS-only transport with vetted and pinned
  DNS answers at every redirect, direct egress that ignores ambient proxy
  variables, an absolute DNS/connect/transfer deadline, per-response and
  aggregate byte caps, and partial batch success.
- [x] M6 hostile controls cover private and mixed DNS answers, HTTP downgrade,
  proxy bypass, slow DNS, response budgets, IPv6 literal syntax, all-address
  fallback and reflected-host log sanitization. Real Photon and Open-Meteo
  requests succeeded; a public redirect succeeded while loopback and downgrade
  redirects were refused before their destination connection.
- [x] Geocoder failures use sanitized reason categories in the PHP error log.
  The first failure appears in Settings, the second creates one Activity item,
  the third causes an owner-facing launch notice, and the existing job alert
  sweep emails a persistent three-failure streak after one hour. Recovery is
  recorded once. Health transitions use a locked transaction so concurrent
  requests cannot lose counts or duplicate Activity transitions.
- [x] Server suite: 1,898 passed, 0 failed.
- [x] Frontend smoke suite: 652 passed, 0 failed in each of
  `America/Los_Angeles`, `UTC` and `Pacific/Auckland`.
- [x] Frontend static checks: 339 passed, 0 failed across 103 modules.
- [x] MCP suite: 63 passed, 0 failed.
- [x] Time-zone harness: 3,840 generated cases and zero changed occurrence
  windows after export and re-import.
- [x] Independent read-only adversarial review of each implemented round.

## Remaining practical checks

This is intentionally short. The automated suites already cover the unusual
payload sizes, race conditions, private-address variants and parser edge cases.
Those are engineering evidence, not chores for the owner to repeat by hand.

Use a disposable account or non-production installation for the checks that
deliberately revoke access or force failures. Keep tokens and private calendar
contents out of saved evidence.

### Google account recovery (H1)

- [ ] After the ten-minute confirmation window expires, verify that changing a
  Google connection or starting/retrying a calendar move asks for the Better-Cal
  password. Ordinary calendar use and background updates should not ask.
- [ ] Start a Google connection in one signed-in browser and verify a second
  browser cannot finish that first browser's connection.
- [ ] With a disposable Google account, begin a test move, use the documented
  compromise-reset command, and verify the connection pauses, unfinished work
  stops, and already-cached events remain visible. Reconnecting the same Google
  account should restore the existing calendar rather than create a duplicate.
  Check the disposable Google calendar for events uploaded before the reset and
  note any cleanup they still need; a reset cannot pull back completed uploads.

### Sign-out and API-key cleanup (M1/M5)

- [ ] From one browser, use **Sign out everywhere else**. Verify the other
  browser is signed out, the initiating browser stays signed in, old public-feed
  links stop working, the replacement link works, and any custom reminder
  address returns to the account address.
- [ ] Add a test subscription with a disposable API key, revoke the key, and
  verify updates pause without removing saved events. In Review, try the three
  owner choices on disposable feeds: keep updating, make local, and delete.

### Recurrence and distant links (M3/M4)

The automated suite covers oversized repeat-rule inputs; normal release smoke
testing of an imported repeating event is enough for that part.

- [ ] Open one far-future or stale event link with the browser network panel
  visible. Verify Better-Cal makes at most one small request around the target
  date, not requests for every month between now and then.

### Location lookup failures (M6)

- [x] A normal public lookup and redirect work, while unsafe redirects, large
  responses and slow responses are refused within their limits.
- [ ] In a controlled environment, force three consecutive provider failures.
  Verify the first appears in **Settings > System**, the second creates one
  Activity entry, and the third shows an owner notice after relaunch. Confirm
  the log and UI do not reveal the search text or provider URL.
- [ ] If email alerts are configured, leave that test failure active for an
  hour and run the alert job. Verify one failure email and one recovery email,
  with no repeated messages for the same incident.

## Deployment closeout

- [x] Deploy the exact reviewed commit with migrations 036 and 037.
- [ ] Confirm the running Settings version and deployed Git revision.
- [x] Run the application smoke check and inspect worker, PHP and web-server
  logs for new recurrence, Google, subscription or deep-link errors.
- [ ] Complete the short practical checks above and record pass, fail, partial
  or blocked, plus only the environment details needed to reproduce a failure.
- [ ] Recheck rollback: application rollback must not roll database migrations
  backward or silently re-enable quarantined Google/subscription state.
- [ ] Update `SECURITY.md` only when the claimed deployed/live verification has
  actually been completed.

## Evidence record template

- Check, date and tester:
- Environment and deployed commit:
- Expected and observed result:
- Sanitized evidence or cleanup notes, if needed:
- Result: pass, fail, partial or blocked

## Production evidence — 2026-10-06/07

- Commit: `196fb2c5ccdc70426701e1d679d2f8cf99b609cf` (`main` and
  `origin/main` before deploy); application version: 0.9.17.
- Deployment backup completed, migrations 036 and 037 applied, and 10 existing
  ICS subscriptions were placed into owner review as designed.
- Deploy preflight passed: server 1,898/0, frontend smoke 652/0 in five time
  zones, frontend static 339/0, and the pinned vendor manifest.
- Production smoke passed: health/database, 789-occurrence window, single-event
  serialization and 22-calendar serialization.
- Every changed runtime file under `server/` and `web/`, plus `VERSION`, matched
  the local release commit by a combined SHA-256 manifest after deployment.
- The deployed `VERSION` file reports 0.9.17. The Settings-screen check remains
  open because it was not inspected through a signed-in browser in this pass.
- A production lookup for a public landmark returned three results. A public
  HTTPS redirect completed with HTTP 200. Controlled policy probes refused a
  loopback destination and an HTTPS-to-HTTP redirect, stopped after the redirect
  cap, stopped a 2 KiB response at a 1 KiB cap, and stopped a delayed response
  after about 0.5 seconds.
- The deployed PHP runtime exposes `proc_open`, `PHP_BINDIR/php` is executable,
  and no `HTTP_PROXY`, `HTTPS_PROXY` or `ALL_PROXY` variables were present.
- Worker output remained healthy after deploy; the PHP and nginx error-log tails
  were empty. No private calendar data or credentials were retained as evidence.
- The broader private-address redirect matrix, forced provider-failure
  notifications, concurrent MySQL threshold transitions and one-hour email
  alert/recovery exercise remain open.
