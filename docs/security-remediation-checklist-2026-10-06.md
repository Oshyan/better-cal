# Security remediation checklist — 2026-10-06 scan

This is the working verification ledger for remediation selected from the 2026-10-06 repository-wide security scan. A checked source item means the fix and its automated regression coverage are present in Git; it does **not** mean the behavior has been exercised against the deployed application or a live provider. Keep those two evidence layers separate.

The checklist began with the high finding and the six medium findings selected for current-HEAD validation, and now also records later remediation phases from the remaining scan backlog. It does not mark the rest of the full scan report as fixed; those findings still need triage and remediation rounds.

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

Round 1 (H1) and Round 2 (M1/M5) shipped to `main` in `f58c682`. Phase 3 (M3/M4) shipped in `8a3b7d3`. Phase 4 (M6) shipped in `d750f7d`. Its deployed exercises remain deliberately open.

## Phase 5 candidate — push privacy and credential boundaries

This phase is implemented and verified for release 0.9.23. It requires no database migration. Live behavior remains open until the practical check below.

| Scan finding | Remediation | Local automated verification | Live/deployed verification |
|---|---|---:|---:|
| F3 | Ordinary sign-out atomically removes only this browser's server-side reminder destination | Passed | [ ] |
| F37 | Push-device inventory/removal require a browser session; token subscribe/unsubscribe is restricted to the exact creating token | Passed | [ ] |
| F71 | System health returns an endpoint hash, never the raw push-service capability | Passed | [ ] |

## Phase 6 — deployment privilege boundaries

This phase shipped in release 0.9.25. It preserves the one-command production deploy and makes no application or database change. The first live backup and its off-host coverage were verified before the legacy backup directory was removed.

| Scan finding | Remediation | Local automated verification | Live/deployed verification |
|---|---|---:|---:|
| F16 | Deploys do not mutate application, `.env`, development or document-root paths with root authority | Passed | [x] |
| F26 | App-controlled reads run as the app user; MySQL backups are serialized and published with mode `0600` in a validated root-owned directory | Passed | [x] |

## Phase 7 — JSON request admission budget

This phase shipped in release 0.9.26 and is verified in production. It requires no database migration.

| Scan finding | Remediation | Local automated verification | Live/deployed verification |
|---|---|---:|---:|
| F41 | JSON bodies are bounded while they are read, before routing, authentication or decoding; multipart calendar imports retain their separate allowance | Passed | [x] |

## Phase 8 — public mail admission and paid-model budgets

This phase shipped in release 0.9.27 and has been exercised against production IMAP and MySQL. It adds migration 040.

| Scan finding | Remediation | Local automated verification | Live/deployed verification |
|---|---|---:|---:|
| F21 | IMAP UID and `RFC822.SIZE` preflight, transport-key poison checkpoint and Seen flag all precede one-at-a-time header/body materialization | Passed | [x] |
| F28 | Every new-event tier shares persistent account, sender and active-event admission; existing-event changes/cancellations remain outside that gate | Passed | [x] |
| F35 | Mail-only Gemini attempts reserve persistent hourly/daily capacity before dispatch; deterministic tiers remain independent | Passed | [x] |
| Related low finding (retention portion) | Mail receipts and admission accounting are pruned after a bounded replay/diagnostic window | Passed | [x] |

## Phase 9 — model admission and response bounds

This phase is implemented and verified for release 0.9.28. It adds migration
041. Live behavior remains open until the practical checks below.

| Scan finding | Remediation | Local automated verification | Live/deployed verification |
|---|---|---:|---:|
| F5 | Prompt-filter calls reserve persistent account/calendar capacity; poll work is calendar-scoped, fail-open and limited to one model call per worker job | Passed | [ ] |
| F52 | Quick Add model assists, including previews, reserve account/token/concurrency capacity before dispatch; deterministic parsing remains available | Passed | [ ] |
| F73 (Gemini response portion) | Every Gemini caller shares an authoritative streamed response-byte cap | Passed | [x] |

## Phase 10 — Google pagination and worker bounds

This phase was released and deployed in 0.9.29. It has no database migration. The ordinary production application smoke passed, but the Google-specific paths have not yet been exercised against the live Google API.

| Scan finding | Remediation | Local automated verification | Live/deployed verification |
|---|---|---:|---:|
| F1 | One Google event poll, including a stale-token retry, shares cumulative event, memory, response-byte, page and elapsed-time bounds; a controlled refusal keeps the prior snapshot and sync token | Passed | [ ] |
| F62 | Calendar-list pagination uses one HTTP client and one cumulative item, byte, page, time and token-progress budget | Passed | [ ] |

## Completed local evidence

This section is the technical record for reviewers; it is not a manual to-do list for the owner.

- [x] H1 regression coverage for recent-password authorization, exact-session OAuth state, compromise quarantine, move cancellation, cached-calendar preservation, session/reset races, reconnect identity and migration 036.
- [x] M1/M5 regression coverage for channel rotation, subscription provenance, token revocation/expiry, owner adoption and migration 037.
- [x] M3 hostile controls for packed, folded, repeated, duplicate, mixed-case and grouped EXDATE properties; the former 5,000-value payload is refused before Sabre parsing, while a 607-value representative control passes.
- [x] M4 year-9999 control produces no broad window and one 72-hour target window; the legitimate 28-day reminder control keeps its existing preload.
- [x] M6 geocoding goes through one HTTPS-only transport with vetted and pinned DNS answers at every redirect, direct egress that ignores ambient proxy variables, an absolute DNS/connect/transfer deadline, per-response and aggregate byte caps, and partial batch success.
- [x] M6 hostile controls cover private and mixed DNS answers, HTTP downgrade, proxy bypass, slow DNS, response budgets, IPv6 literal syntax, all-address fallback and reflected-host log sanitization. Real Photon and Open-Meteo requests succeeded; a public redirect succeeded while loopback and downgrade redirects were refused before their destination connection.
- [x] Geocoder failures use sanitized reason categories in the PHP error log. The first failure appears in Settings, the second creates one Activity item, the third causes an owner-facing launch notice, and the existing job alert sweep emails a persistent three-failure streak after one hour. Recovery is recorded once. Health transitions use a locked transaction so concurrent requests cannot lose counts or duplicate Activity transitions.
- [x] Server suite: 1,898 passed, 0 failed.
- [x] Frontend smoke suite: 652 passed, 0 failed in each of `America/Los_Angeles`, `UTC` and `Pacific/Auckland`.
- [x] Frontend static checks: 339 passed, 0 failed across 103 modules.
- [x] MCP suite: 63 passed, 0 failed.
- [x] Time-zone harness: 3,840 generated cases and zero changed occurrence windows after export and re-import.
- [x] Independent read-only adversarial review of each implemented round.
- [x] Phase 5 focused relational controls cover exact-device logout, adjacent session/device preservation, no logout tombstone, session-only device administration, and exact-token subscribe/unsubscribe ownership.
- [x] Phase 5 health and frontend controls cover hash-only device identity, current-device sign-out, and non-blocking service-worker registration lookup.
- [x] Phase 5 received an independent read-only post-patch review with no concrete source-backed bypass or regression found.
- [x] Phase 5 local suites: server 1,940/0; frontend smoke 653/0 in `America/Los_Angeles`, `UTC`, `Europe/Berlin`, `Asia/Kolkata` and `Pacific/Auckland`; frontend static 347/0; MCP 63/0; vendor manifest 10/10.
- [x] Phase 6 deploy controls refuse writable and symlinked backup directories, including a symlink disguised by a trailing slash; refuse a planted output symlink without changing its target; preserve `0600` output mode; clean up private temporary files on failure; and assert that neither production nor development deploys perform privileged ownership or mode repair on `.env`.
- [x] Phase 6 uses the committed deploy snapshot for its remote helper, validates a canonical root-owned destination beneath `/home` or `/var/backups`, and runs app archive, `.env` and MySQL source reads as `APP_USER`. The production helper refuses SQLite DSNs instead of copying an app-selected pathname as root.
- [x] A disposable Debian-host integration streamed the real app and MySQL backup through the app identity, produced `root:root` directory/file modes `0700`/`0600`, denied app-user writes, and passed gzip integrity. Separate hostile controls refused a symlinked `.env`, all SQLite DSNs (including a device path), an unsafe existing backup directory, `APP_USER=root`, and a concurrent backup while the publication lock was held. Failure cleanup left no partial output or private temporary file, and all test artifacts were removed without deploying code. A separate disposable rotation check kept exactly the newest five app and five database generations.
- [x] Phase 6 local suites: deploy-security 19/0; server 1,940/0; frontend smoke 653/0 in `America/Los_Angeles`, `UTC`, `Europe/Berlin`, `Asia/Kolkata` and `Pacific/Auckland`; frontend static 347/0; MCP 63/0; vendor manifest 10/10.
- [x] Phase 7 applies a 1 MiB default JSON-body limit with a 4 MiB hard ceiling. `Content-Length` is an early rejection hint, while the authoritative stream read stops after the configured limit plus one byte, so missing, false, overflowing and chunked lengths cannot bypass the bound.
- [x] Phase 7 focused HTTP controls: exactly-at-limit JSON reached normal routing; one byte over and chunked over-limit JSON returned 413; multipart calendar import remained outside the JSON limit and reached authentication.
- [x] Phase 7 server suite: 1,953 passed, 0 failed. Four changed PHP files pass syntax checks. Independent read-only post-patch review found no concrete source-backed bypass or regression.
- [x] Phase 8 focused controls cover IMAP size/checkpoint/Seen/header/body ordering, oversize no-header handling, retry rollback, RFC Message-ID replay, account and claimed-sender event/model ceilings, active-event capacity, failed-create rollback, bounded aggregate Review notices and retention.
- [x] Phase 8 server suite: 1,983 passed, 0 failed; frontend smoke 653/0 in `America/Los_Angeles`, `UTC`, `Europe/Berlin`, `Asia/Kolkata` and `Pacific/Auckland`; frontend static 347/0; MCP 63/0. All changed PHP files pass syntax checks and the working-tree diff passes whitespace validation.
- [x] Phase 8 independent post-patch review exercised all four event-creation tiers under exhausted capacity, model-attempt reservation before failed/null provider returns, and the pinned Webklex message/attachment lifecycle. It found and prompted a generator-lifetime fix; the repeated real-Webklex probe then retained zero decoded messages at each yield with flat memory.
- [x] Phase 9 focused controls cover persistent account, token and calendar windows; active-call leases; account capacity across rotated tokens and calendars; pre-dispatch preview charging; failed provider attempts; exact rolling-window retry times; deterministic fallback; calendar-homogeneous prompt batches; one-call job continuations; fail-open events; bounded Review notices; reminder priority; input length and streamed response-byte limits.
- [x] Phase 9 local suites: server 2,009/0; frontend smoke 653/0 in `America/Los_Angeles`, `UTC`, `Europe/Berlin`, `Asia/Kolkata` and `Pacific/Auckland`; frontend static 347/0; MCP 63/0; deploy-security 19/0; vendor manifest 10/10. A real local HTTP/cURL probe retained an exact-limit Gemini response and rejected a response one byte over the cap.
- [x] Phase 9 independent post-patch review found no remaining security bypass for F5, F52 or the Gemini-response portion of F73. It identified an overly conservative retry timestamp; the implementation now reports the exact oldest rolling-window or active-lease expiry, with regression coverage.
- [x] Phase 10 gives each Google event poll one cumulative item, decompressed- byte, page and elapsed-time budget across every page and an HTTP 410 retry. Opaque page tokens are bounded and cycle-checked, a terminal sync token is required, and over-limit work stops before changing events, access role, health success state or the previous sync position.
- [x] Phase 10 checks both process headroom and the cached calendar's actual stored variable-width payload before either full database materialization. The preflight includes active and soft-deleted rows; a valid-but-large description control is refused rather than exhausting a 128 MiB worker.
- [x] Phase 10 calendar inventory uses one client and cumulative budget for all pages. Access-token refresh is included in the same absolute elapsed-time deadline, and an already-expired owner deadline stops before network access.
- [x] Phase 10 local suites: server 2,051/0 under a 128 MiB memory limit; frontend smoke 653/0 in `America/Los_Angeles`, `UTC`, `Europe/Berlin`, `Asia/Kolkata` and `Pacific/Auckland`; frontend static 347/0; MCP 63/0; deploy-security 19/0; vendor manifest 10/10. All changed PHP files pass syntax checks and the working-tree diff passes whitespace validation.
- [x] Phase 10 independent post-patch review found no concrete remaining F1 or F62 bypass after prompting fixes for cached large-description memory and the token-refresh deadline. The review itself made no live provider request.
- [x] Phase 10 release `0.9.29` (`564699f`) was pushed, tagged, published and deployed. The bounded pre-deploy backup completed, Composer reported no known advisories, no migration was pending, production health and the server-side application smoke passed, and hashes for `VERSION`, the pagination budget and Google sync matched the committed release. GitHub CI completed successfully. The Google-specific practical exercise below remains intentionally unchecked.

## Remaining practical checks

This is intentionally short. The automated suites already cover the unusual payload sizes, race conditions, private-address variants and parser edge cases. Those are engineering evidence, not chores for the owner to repeat by hand.

Use a disposable account or non-production installation for the checks that deliberately revoke access or force failures. Keep tokens and private calendar contents out of saved evidence.

### Google account recovery (H1)

- [ ] After the ten-minute confirmation window expires, verify that changing a Google connection or starting/retrying a calendar move asks for the Better-Cal password. Ordinary calendar use and background updates should not ask.
- [ ] Start a Google connection in one signed-in browser and verify a second browser cannot finish that first browser's connection.
- [ ] With a disposable Google account, begin a test move, use the documented compromise-reset command, and verify the connection pauses, unfinished work stops, and already-cached events remain visible. Reconnecting the same Google account should restore the existing calendar rather than create a duplicate. Check the disposable Google calendar for events uploaded before the reset and note any cleanup they still need; a reset cannot pull back completed uploads.

### Google pagination and worker release (F1/F62)

- [ ] After deployment, open Settings → Connections once and refresh one representative connected Google calendar. Confirm the complete calendar list appears and the calendar returns to a successful “last checked” state. There is no need to manufacture an oversized calendar or broken page-token cycle; those failure paths are covered by the automated controls above.

### Sign-out and API-key cleanup (M1/M5)

- [ ] From one browser, use **Sign out everywhere else**. Verify the other browser is signed out, the initiating browser stays signed in, old public-feed links stop working, the replacement link works, and any custom reminder address returns to the account address.
- [ ] Add a test subscription with a disposable API key, revoke the key, and verify updates pause without removing saved events. In Review, try the three owner choices on disposable feeds: keep updating, make local, and delete.

### Recurrence and distant links (M3/M4)

The automated suite covers oversized repeat-rule inputs; normal release smoke testing of an imported repeating event is enough for that part.

- [ ] Open one far-future or stale event link with the browser network panel visible. Verify Better-Cal makes at most one small request around the target date, not requests for every month between now and then.

### Location lookup failures (M6)

- [x] A normal public lookup and redirect work, while unsafe redirects, large responses and slow responses are refused within their limits.
- [ ] In a controlled environment, force three consecutive provider failures. Verify the first appears in **Settings > System**, the second creates one Activity entry, and the third shows an owner notice after relaunch. Confirm the log and UI do not reveal the search text or provider URL.
- [ ] If email alerts are configured, leave that test failure active for an hour and run the alert job. Verify one failure email and one recovery email, with no repeated messages for the same incident.

### Push lifecycle and authority (F3/F37/F71)

- [ ] On a disposable browser/device with reminders enabled, sign out and use another signed-in browser to confirm that the signed-out device disappears from **Settings > Notifications** and receives no later reminder. Sign back in on the first device and confirm it returns without a new browser permission prompt. Other signed-in devices and email reminders should remain unchanged. A notification already handed to the push service may arrive once.

### Deployment and backups (F16/F26)

- [x] On the first Phase 6 deployment, confirm the new app and database backups are regular files owned by root with mode `0600` under the configured root-owned `BACKUP_DIR`, and that the ordinary deploy still completes without any additional prompt or manual step.
- [x] After verifying one new backup, deliberately archive or remove the legacy app-adjacent backup directory; it is not migrated automatically from an app-controlled pathname.

### JSON request admission (F41)

- [x] After the Phase 7 deployment, verify a bounded over-limit JSON request returns HTTP 413 while ordinary JSON and multipart calendar-import requests continue to reach their normal routes. This is a deploy check, not an owner exercise.

### Public mail admission (F21/F28/F35)

- [x] After migration 040, use disposable messages to verify production IMAP reports UIDVALIDITY and size, an oversize message is marked read without its Subject/From being recorded, an ordinary message ingests, and a forced retryable fetch failure returns the message to unread and removes its started receipt.
- [x] Against production MySQL with a disposable account, exercise concurrent event and model admissions at a low temporary limit. Verify the exact cap is honored, a failed event transaction consumes no event reservation, a failed or null model response does consume its reservation, and rotated From and Message-ID values do not reset account-wide capacity.
- [x] Verify one **Email automation paused** item appears in Review when a limit is reached, later refusals update its bounded count/latest context instead of adding cards, Dismiss closes it, and the original messages remain available in the mailbox for manual handling.

### Quick Add and prompt-filter model admission (F5/F52/F73)

- [ ] After migration 041, use temporary low limits with a disposable account to exercise simultaneous session and API-token Quick Add previews. Verify the exact account/token/concurrency caps, that a failed provider attempt remains charged, token rotation cannot reset account capacity, and the deterministic draft remains available with the **AI paused** explanation.
- [ ] Under a temporary low prompt-filter limit, refresh a disposable feed with enough changed events to cross the limit. Verify one bounded Review notice, fail-open event visibility, a delayed continuation rather than a failed job, and that a due reminder is claimed before the model-work backlog. Restore the normal limits and dismiss the notice after the exercise.
- [x] Confirm the deployed transport and configured response cap match the reviewed release. The real local cURL boundary test is sufficient unless a controlled production provider/proxy fixture can safely return an oversized response; do not disrupt the live provider merely to repeat that case.

## Deployment closeout

- [x] Deploy the exact reviewed commit with migrations 036 and 037.
- [x] Confirm the running version and deployed revision through the production `VERSION` file and hashes of the changed runtime files. The signed-in Settings display remains an optional presentation check, not a deployment-integrity check.
- [x] Run the application smoke check and inspect worker, PHP and web-server logs for new recurrence, Google, subscription or deep-link errors.
- [ ] Complete the short practical checks above and record pass, fail, partial or blocked, plus only the environment details needed to reproduce a failure.
- [ ] Recheck rollback: application rollback must not roll database migrations backward or silently re-enable quarantined Google/subscription state.
- [ ] Update `SECURITY.md` only when the claimed deployed/live verification has actually been completed.

## Evidence record template

- Check, date and tester:
- Environment and deployed commit:
- Expected and observed result:
- Sanitized evidence or cleanup notes, if needed:
- Result: pass, fail, partial or blocked

## Production evidence — 2026-10-06/07

- Commit: `196fb2c5ccdc70426701e1d679d2f8cf99b609cf` (`main` and `origin/main` before deploy); application version: 0.9.17.
- Deployment backup completed, migrations 036 and 037 applied, and existing ICS subscriptions were placed into owner review as designed.
- Deploy preflight passed: server 1,898/0, frontend smoke 652/0 in five time zones, frontend static 339/0, and the pinned vendor manifest.
- Production smoke passed: health/database, a real-data occurrence window, single-event serialization and full calendar-list serialization.
- Every changed runtime file under `server/` and `web/`, plus `VERSION`, matched the local release commit by a combined SHA-256 manifest after deployment.
- The deployed `VERSION` file reports 0.9.17. The Settings-screen check remains open because it was not inspected through a signed-in browser in this pass.
- A production lookup for a public landmark returned three results. A public HTTPS redirect completed with HTTP 200. Controlled policy probes refused a loopback destination and an HTTPS-to-HTTP redirect, stopped after the redirect cap, stopped a 2 KiB response at a 1 KiB cap, and stopped a delayed response after about 0.5 seconds.
- The deployed PHP runtime exposes `proc_open`, `PHP_BINDIR/php` is executable, and no `HTTP_PROXY`, `HTTPS_PROXY` or `ALL_PROXY` variables were present.
- Worker output remained healthy after deploy; the PHP and nginx error-log tails were empty. No private calendar data or credentials were retained as evidence.
- The broader private-address redirect matrix, forced provider-failure notifications, concurrent MySQL threshold transitions and one-hour email alert/recovery exercise remain open.

## Phase 6 production and backup evidence — 2026-10-07

- Release 0.9.25 was deployed from `ab9efa6c4f2f5c1c5d14e4c702c308c15e4a5f04`. The production `VERSION` file reports 0.9.25, and the runtime files later changed by `c466569` still match `ab9efa6`; the newer commit is not deployed.
- The completed deploy created regular app and database archives in the configured root-owned `BACKUP_DIR`. The directory was verified as `root:root` mode `0700`, the archives as `root:root` mode `0600`, and both gzip streams passed integrity checks. Production health, application smoke checks, and recent worker, PHP and web-server logs were clean.
- Off-host backup coverage was extended to the new `BACKUP_DIR` and verified; the deployed pull script matched the reviewed source hash.
- A targeted Better-Cal copy, not another whole-host snapshot, preserved the current app archive, database dump and complete legacy archive in the off-host copy. Source and off-host SHA-256 hashes matched, both tar archives were readable, and the SQL dump had its completion marker.
- Only after that targeted verification, the legacy app-adjacent backup directory was removed as the unprivileged app user. The root-private source remained intact, backup monitoring returned healthy, and no incomplete snapshot or deletion-staging directory remained.

## Phase 7 production evidence — 2026-10-07

- Release 0.9.26 was deployed from `92910bc0cade41ecd7656615d371d2b338249b18`. Production reported 0.9.26 and the SHA-256 hash of `server/src/Http/Request.php` matched the release commit.
- A small unauthenticated JSON request and a multipart calendar-import request both reached the normal authentication boundary and returned 401. An over-limit chunked JSON request returned 413 with the stable `request_too_large` error code.
- The deploy preflight, dependency audit, migration check, health endpoint and real-data smoke checks all passed. No database migration was needed.

## Phase 8 production evidence — 2026-10-07

- Release 0.9.27 was deployed from `3a5335815624f531840bc1d05e6c3017b96a6190`; tag `v0.9.27` points to that release commit. Migration 040 applied, production reported 0.9.27, and the SHA-256 hashes of `VERSION` and every Phase 8 runtime file matched the release commit.
- Deploy preflight passed: server 1,983/0; frontend smoke 653/0 in five time zones; frontend static 347/0; MCP 63/0; deploy-security 19/0; vendor manifest 10/10. Composer reported no known dependency advisories. Production health, migration and real-data smoke checks passed.
- Twelve simultaneous event admissions at a temporary account limit of three allowed exactly three and refused nine with `event_account_day`; three reservations and three distinct senders were recorded. A failed create transaction left zero reservations, and the active-event ceiling refused a new event with `event_active`.
- Ten simultaneous model admissions at a temporary hourly limit of two allowed exactly two and refused eight with `llm_account_hour`, despite rotating sender and message identities. A five-message end-to-end ingest exercise made exactly two provider calls/reservations; the remaining three refusals updated one bounded Review item, whose count, latest context and dismissal behavior all matched the contract.
- A real disposable IMAP exercise reported UIDVALIDITY. An over-limit message was marked Seen without header identification or stored Subject/From. A forced failure after header fetch restored Unseen and removed the checkpoint; the same UID was then redelivered, recorded its sanitized header context and marked Seen. Both messages remained in the mailbox until explicitly deleted after verification.
- A production retention exercise inserted one deliberately expired receipt and admission record. The normal pruning path removed both, reporting one pruned receipt and zero remaining rows.
- All disposable users, database rows, mailbox messages and remote/local test helpers were removed after verification. Worker logs contained no post-deploy mail, quota, database or runtime failure. One unrelated geocoder HTTP failure and one unrelated nginx missing-file error were observed and remain covered by their existing operational handling.

## Phase 9 production evidence — 2026-10-07

- Release 0.9.28 was deployed from `ecc1fad901942056584a75b79b3196faabab0cf1`; tag `v0.9.28` points to that release commit and its GitHub release is published. Production reported 0.9.28, and `VERSION` plus every Phase 9 runtime file matched the release commit by SHA-256.
- Deploy preflight passed: server 2,009/0; frontend smoke 653/0 in five time zones; frontend static 347/0; deploy-security 19/0; vendor manifest 10/10. Composer reported no known dependency advisories. The private application and database backup completed before migration 041 applied.
- Production health and real-data smoke checks passed with a real-data occurrence window, one single-event record and the full calendar list. Migration recheck reported nothing pending, the worker completed a healthy post-deploy cycle, and the site error log was not modified during the deployment window.
- The live schema has all eight expected `model_admissions` columns. Effective limits match the documented defaults: Quick Add 4,000 characters; account 120/hour, 500/day and four concurrent; API token 30/hour, 150/day and two concurrent; prompt filters account 40/hour and 200/day, calendar 20/hour and 100/day; Gemini response 1 MiB; admission retention 90 days.
- The deployed Gemini transport hash and effective response limit match the implementation whose real local cURL boundary test accepted the exact cap and refused one byte over. No live provider was disrupted to manufacture an oversized response. The temporary-low-limit Quick Add and feed-churn exercises remain open.
