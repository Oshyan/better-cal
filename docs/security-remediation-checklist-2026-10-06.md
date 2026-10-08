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

## Phase 11 — emailed invitation trust and pending revision order

This phase shipped in release 0.9.30. It requires no database migration.
First-time emailed invitations now require an owner decision before event
creation; ordinary booking extraction remains automatic. The live end-to-end
invitation exercise remains open below.

| Scan finding | Remediation | Local automated verification | Live/deployed verification |
|---|---|---:|---:|
| F10 | An unknown-UID iMIP REQUEST becomes a Review candidate under rolling admission and a 100-open-decision hard cap; only **Add to calendar** creates the event and establishes the organizer anchor | Passed | [ ] |
| F61 | Trusted existing-invitation revisions are ordered under an account lock; legacy-untrusted claims remain parallel until the owner chooses one; decision rechecks/mutation/closure are atomic | Passed | [ ] |

## Phase 12 — proposal decision integrity

This phase shipped in release 0.9.31. It requires no database migration.
Proposal decisions are bound to the exact reviewed revision and resolved local
destinations; acceptance and grouped undo are all-or-nothing.

| Scan finding | Remediation | Local automated verification | Live/deployed verification |
|---|---|---:|---:|
| F29 | Proposal acceptance, event creation, trip links, mutation records and final state share one transaction and account lock | Passed | [ ] |
| F6 | Proposal destinations are disclosed and restricted to owner-local calendars; external and plugin-managed targets fail closed | Passed | [ ] |
| F56 | Accept and Dismiss require the token for the exact rendered revision; changed proposals return `proposal_changed` | Passed | [ ] |

## Phase 13 — bearer-token authority boundaries

This phase shipped in release 0.9.32. It requires no database migration.

| Scan finding | Remediation | Local automated verification | Live/deployed verification |
|---|---|---:|---:|
| F9 | Remote Google calendar discovery requires an interactive browser session before the stored provider credential is used | Passed | [ ] |
| F11 | Calendar responses disclose a private ICS source address only to the browser owner or the exact token that supplied it | Passed | [ ] |
| F53 | A bearer token can delete only outbound feeds created by that exact token; inaccessible ids use the ordinary not-found response | Passed | [ ] |
| F31 residual | Creating an API key requires recent password confirmation, rechecked under the durable write transaction | Passed | [ ] |

## Phase 14 — Google move and mutation integrity

This phase ships in release 0.9.33 and adds migration 042. Its first rollout
must briefly quiesce request and worker traffic; after the ordinary backup, the
production deploy gracefully stops request traffic, pauses worker scheduling,
waits for an active worker, migrates, restores both, and refuses the first run
unless that one-time pause was explicitly approved. Later deploys have no
added step.

| Scan finding | Remediation | Local automated verification | Live/deployed verification |
|---|---|---:|---:|
| F17 | Calendar/event/CalDAV/trip writers, feed finalization and Undo share canonical calendar locks with move start/cutover; stale provider responses are discarded by a monotonic binding generation | Passed | [ ] |
| F36 | One active move, one leased executor and durable remote calendar/event recovery markers are enforced across browser/worker overlap and response loss | Passed | [ ] |
| F36 rollout boundary | Active unmarked moves are forbidden by the database; ambiguous pre-upgrade work is stopped, and the first migration requires verified quiescence | Passed | [ ] |

## Phase 15 — bounded duplicate detection

This phase is included in release 0.9.36 and requires no database migration. Duplicate
discovery now runs as bounded, resumable worker slices instead of materializing
an attacker-controlled all-pairs graph. The focused live behavior checks remain
open.

| Scan finding | Remediation | Local automated verification | Live/deployed verification |
|---|---|---:|---:|
| F7 | Same-UID copies use a linear spanning graph; different-UID matching has hard row, candidate, comparison, pair and elapsed-time budgets; persistent linked storage, graph traversal and Review suggestions have separate hard ceilings; unfinished pages continue in later worker runs | Passed | [ ] |
| F7 behavior | A shared component id preserves calendar collapse and one-reminder behavior when a sparse hub is outside the current window; soft-deleted automatic edges are pruned and cannot consume invisible Review capacity | Passed | [ ] |
| F7 observability | A completed cycle that encountered a dense/pathological cluster records one bounded System-health failure; later clean cycles recover it through the existing Activity and alert path | Passed | [ ] |

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
- [x] Phase 11 focused controls cover first-arrival hold-without-event, competing same-UID claims, owner-established organizer trust, no implicit RSVP, legacy-untrusted parallel candidates, exact replay coalescing, pending-sequence ordering after trust, stale displayed-diff refresh, double-decision refusal, Google ownership isolation, PUBLISH booking preservation, and a persistent 100-open-decision hard ceiling that does not block ordinary bookings.
- [x] Phase 11 local suites: server 2,080/0 under a 128 MiB memory limit; frontend smoke 653/0 in `America/Los_Angeles`, `UTC`, `Europe/Berlin`, `Asia/Kolkata` and `Pacific/Auckland`; frontend static 349/0 across 103 modules; MCP 64/0. All changed PHP files pass syntax checks and the working-tree diff passes whitespace validation. No time-zone harness was required because this phase does not change repeat rules, time-zone handling, import or export.
- [x] Phase 11 independent read-only post-patch review found no remaining source-backed F10/F61 bypass or adjacent regression after prompting fixes for legacy pending-sequence suppression, unbounded persistent invitation candidates, and acceptance of pre-patch Review rows without the new classification marker. A live MySQL concurrency exercise remains a deployment-layer check, not completed local evidence.
- [x] Phase 12 focused controls cover implicit and explicit local destinations, unsafe legacy targets, stale accept and dismiss tokens, late validation and final-state rollback, changed calendar authority, double decisions, complete grouped undo, expired undo records and owner edits made after acceptance.
- [x] Phase 12 local suites: server 2,110/0 under a 128 MiB memory limit; frontend smoke 653/0 in `America/Los_Angeles`, `UTC`, `Europe/Berlin`, `Asia/Kolkata` and `Pacific/Auckland`; frontend static 353/0 across 103 modules; MCP 67/0; deploy-security 19/0; vendor manifest 10/10. All changed PHP and JavaScript files pass syntax checks, and no time-zone harness was required because this phase does not change repeat rules, time-zone handling, import or export.
- [x] Phase 12 independent read-only post-patch review confirmed the local-calendar restriction and revision binding across REST, Review, browser and MCP paths. It prompted fixes so expired mutation records cannot falsely reopen an accepted proposal and a later owner edit blocks grouped undo instead of being overwritten. A live two-connection MySQL concurrency exercise remains a deployment-layer check, not completed local evidence.
- [x] Phase 13 focused controls cover browser-only Google inventory, creator-specific subscription-address visibility across list and mutation responses, owner/token outbound-feed deletion boundaries, recent-password API-key creation, session revocation before durable creation and unchanged privileged local key creation.
- [x] Phase 13 local suites: server 2,124/0; frontend smoke 653/0 in `America/Los_Angeles`, `UTC`, `Europe/Berlin`, `Asia/Kolkata` and `Pacific/Auckland`; frontend static 353/0 across 103 modules; MCP 67/0. All changed PHP files pass syntax checks, the working-tree diff passes whitespace validation and the repository privacy guard reports no findings. No time-zone harness was required because this phase does not change repeat rules, time-zone handling, import or export.
- [x] Phase 13 independent read-only post-patch review found no remaining source-backed bypass or adjacent regression in the four authorization boundaries. The browser password-prompt interaction and a live MySQL password-reset/token-write race remain deployment-layer checks, not completed local evidence.
- [x] Phase 14 focused controls cover calendar/event/CalDAV/trip mutation exclusion, failed-move freeze and explicit stop, one leased executor, active-move database invariants, exact target reservation, calendar and per-event response-loss recovery, restart repair, stale provider-response rejection, binding-generation ABA protection, account disconnect, and Undo across every referenced calendar.
- [x] Phase 14 local suites: server 2,164/0; frontend smoke 653/0; frontend static 353/0 across 103 modules; deploy-security 21/0. Touched PHP and shell files pass syntax checks, the working-tree diff passes whitespace validation and the repository privacy guard reports no findings.
- [x] Phase 14 independent read-only post-patch review found F17 fixed and the application-level F36 controls complete. Its deployment follow-up now treats service, cron and process-inspection errors as failures rather than evidence of quiescence. Live MySQL concurrency and Google response-loss exercises remain deployment-layer checks.
- [x] Phase 15 reproduced the original quadratic path with generic data: 500 same-time events retained 62,500 pairs and used about 63.5 MiB under a 128 MiB process limit. The bounded implementation retained 199 sparse candidates with about 4 MiB peak memory in the same probe, before persistence and independent review.
- [x] Phase 15 focused controls cover hard operator ceilings, dense distinct-UID comparison/result bounds, durable cursor progress, independently tuned limit progress, sparse exact-match graphs, bounded open-suggestion count and degree, linked-edge storage and read bounds, rotating exact matches, pre-existing dense graphs, hidden sparse hubs, stale soft-deleted candidates, same-UID linear spanning edges, same-UID exclusion from the title phase, duplicate-free continuation storage, one Activity entry per user cycle, and ordinary duplicate matching semantics.
- [x] Phase 15 local suites: server 2,190/0; frontend smoke 654/0 in `America/Los_Angeles`, `UTC`, `Europe/Berlin`, `Asia/Kolkata` and `Pacific/Auckland`; frontend static 353/0 across 103 modules; MCP 67/0; deploy-security 21/0; vendor manifest 10/10. Touched PHP files pass syntax checks, the working-tree diff passes whitespace validation and the repository privacy guard reports no findings.
- [x] Phase 15 independent read-only post-patch review found and prompted fixes for cross-cycle linked-edge growth, bounded reads of pre-existing dense graphs, sparse groups whose hub is outside the visible window, and soft-deleted candidates consuming invisible Review capacity. No remaining single-slice all-pairs bypass was found.
- [x] Phase 17 bounds long-span Split/Move work to loaded or rendered days; activates only absolute HTTP(S) event links; classifies meeting providers by parsed HTTPS host; bounds RRULE structure, values and selector combinations; refuses incomplete feed transfers/snapshots; keeps outbound and imported descriptions inert; contains malformed RRULEs to their event; and rejects impossible coordinates before storage, provider use or map rendering. Migration 043 cleans unsafe legacy URL, recurrence and coordinate fields without removing events.
- [x] Phase 17 local suites: server 2,244/0; frontend smoke 680/0 in `America/Los_Angeles`, `UTC`, `Europe/Berlin`, `Asia/Kolkata` and `Pacific/Auckland`; frontend static 356/0 across 104 modules; MCP 67/0; deploy-security 21/0; vendor manifest 10/10. The 3,840-case time-zone differential harness matched its expected baseline: zero unexplained Python-oracle mismatches, 25 known ical.js differences and zero export/re-import occurrence changes. Touched PHP and JavaScript files pass syntax checks, the working-tree diff passes whitespace validation and the repository privacy guard reports no findings.
- [x] Phase 17 independent read-only review found no material residual in eight of nine boundaries. Its proposed legacy sub-daily recurrence bypass was disproved against the pinned iterator: old secondly, minutely and hourly rules each stopped at the dependency's fixed 3,500-generation ceiling in about 2–5 ms. A committed under-one-second regression guard now detects any future dependency change; recent legitimate hourly recurrences remain supported.
- [x] Phase 18 bounds dense event selection, cumulative recurrence work, browser request/cache/rendering work, published-feed source and output size, Takeout directory admission, plugin window and snapshot replacement, and native share handoff. Refused windows and snapshots fail as a whole rather than being presented or committed partially.
- [x] Phase 18 local suites: server 2,278/0; frontend smoke 711/0; frontend static 356/0 across 104 modules. The 3,840-case time-zone/export-import harness reported zero occurrence changes after export and re-import. Touched PHP files pass syntax checks, the working-tree diff passes whitespace validation, and the repository privacy guard reports no findings.
- [x] Phase 18 independent read-only review passed the published-feed, Takeout, plugin-sync and share-target boundaries. It prompted follow-up fixes for browser gap fan-out, incomplete cache coverage, stale window completion and duplicate reminder cursor chains; focused regression tests cover the corrected boundaries.

## Remaining practical checks

This is intentionally short. The automated suites already cover the unusual payload sizes, race conditions, private-address variants and parser edge cases. Those are engineering evidence, not chores for the owner to repeat by hand.

Use a disposable account or non-production installation for the checks that deliberately revoke access or force failures. Keep tokens and private calendar contents out of saved evidence.

### Google account recovery (H1)

- [ ] After the ten-minute confirmation window expires, verify that changing a Google connection or starting/retrying a calendar move asks for the Better-Cal password. Ordinary calendar use and background updates should not ask.
- [ ] Start a Google connection in one signed-in browser and verify a second browser cannot finish that first browser's connection.
- [ ] With a disposable Google account, begin a test move, use the documented compromise-reset command, and verify the connection pauses, unfinished work stops, and already-cached events remain visible. Reconnecting the same Google account should restore the existing calendar rather than create a duplicate. Check the disposable Google calendar for events uploaded before the reset and note any cleanup they still need; a reset cannot pull back completed uploads.

### Google pagination and worker release (F1/F62)

- [ ] After deployment, open Settings → Connections once and refresh one representative connected Google calendar. Confirm the complete calendar list appears and the calendar returns to a successful “last checked” state. There is no need to manufacture an oversized calendar or broken page-token cycle; those failure paths are covered by the automated controls above.

### Google calendar move integrity (F17/F36)

- [ ] With a disposable local calendar, start one move and confirm it is read-only only while the upload is active, completes once, and then edits normally through Google. In a separate disposable attempt, stop a failed move and confirm local editing returns with the partial-copy warning. Response-loss ambiguity, executor overlap and lock races are covered by automated controls and do not need to be manufactured manually.

### Emailed invitation review (F10/F61)

- [ ] After deployment, use one disposable emailed `REQUEST`: confirm it appears in Review before any event exists, **Add to calendar** creates it without sending a reply, and the ordinary Accept / Maybe / Decline choice appears afterward. There is no need to manufacture forged UIDs, extreme sequences or acceptance races; those paths are covered by automated controls.

### Aggregate work boundaries (Phase 18)

- [ ] In a disposable dense view, confirm overflow remains usable through **+N more** and that a deliberately over-budget window asks for narrower dates or calendars rather than showing a partial result as complete.
- [ ] Observe one real multi-slice reminder cycle and confirm it advances through one continuation chain without duplicate notifications or duplicate queued roots.
- [ ] On an installed device, share an ordinary item and a near-limit multibyte item into the app; confirm each is consumed once and a simultaneous second share receives a retry response.

### Plugin proposal decisions (F29/F6/F56)

- [ ] After deployment, create one disposable plugin proposal and confirm its destination calendar is named before acceptance. Accept it once, then use Proposal **Undo** immediately and confirm the created items are removed together and the proposal reopens. There is no need to manufacture stale plans, expired undo records, owner-edit conflicts or concurrent decisions; those paths are covered by automated controls.

### API-key creation (F31 residual)

- [ ] After the ten-minute confirmation window expires, create one disposable API key and confirm the password prompt retries in place and shows the new key once. Revoke it afterward. The Google, source-URL and outbound-feed authorization matrices are covered by automated tests and do not need separate manual exercises.

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

### Google move integrity (F17/F36)

- [ ] For the first migration-042 rollout only, approve the deploy's explicit quiescence step. Confirm it takes the backup first, then pauses request/worker traffic, waits for any active worker, records migration 042, restores both services and completes the normal smoke check. Later deploys require no added step.
- [ ] After deployment, move one disposable local calendar containing a single disposable event. While it is queued or running, confirm an app edit and a CalDAV edit receive the temporary `calendar_moving`/HTTP 409 refusal; after completion, confirm ordinary Google-backed editing works.
- [ ] In a controlled failure exercise, stop a move after at least one item uploads. Confirm the calendar remains protected while the failed move is retryable, **Try again** resumes it, and **Stop move and keep local** restores local editing while clearly warning that partial Google data may need manual removal.
- [ ] Exercise two simultaneous starts and a browser/worker overlap against a disposable calendar on production MySQL. Confirm one active move row, one executor lease, one remote calendar and one imported copy per event. Use a controlled test environment or network fault; do not kill the shared production worker or disrupt a real calendar merely to manufacture the race.
- [ ] Simulate response loss after remote calendar creation and after one accepted event import, each before local ID persistence. Confirm the same calendar/event is recovered without another create/import. Verify the calendar-description marker is cleared, the private event marker is not displayed as event content, and neither marker nor any account/calendar details appear in retained evidence.

### Phase 19 durable actions and owner decisions (F12/F19/F32/F38/F42/F50/F70)

- [ ] With temporary low notification-email limits, verify the exact persistent cycle/account/recipient/installation cap under concurrent attempts. SMTP failures must stay charged, suppressed email must appear in **Settings > System**, and push must continue independently. Restore the normal limits after the exercise.
- [ ] From an API token, confirm Review still lists items but exposes no actions, and direct Review/RSVP/proposal/duplicate decision requests return `403 session_required` with no side effects. Repeat one disposable decision in the signed-in browser and confirm it succeeds.
- [ ] Run one enabled plugin job twice quickly and confirm the second request reports it as already queued rather than adding a row. Verify a disabled or unknown job queues nothing and an ordinary core worker job is claimed before plugin work. Plugin management with an API token must return `403 session_required`.
- [ ] Review the enabled-plugin list once after deployment and disable or uninstall anything unexpected. Older plugin rows have no reliable record of whether a browser session or API token originally enabled them, so the migration preserves the current enabled state rather than guessing and interrupting known-good automation.
- [ ] On disposable events, confirm a real generated repeat occurrence can be edited while an off-rule or skipped timestamp is rejected without creating an override. Move an all-local disposable trip with its members, then add a disposable subscribed member and confirm the same bulk move is rejected atomically while moving only the trip remains available.
- [ ] Confirm a different-UID external/local exact title-and-time match appears in Review and neither copy is hidden nor loses reminders before confirmation. Confirming **Same event** should then restore the normal single-display/reminder behavior; same-UID copies should continue linking automatically.

### Phase 20 host and credential boundaries (F14/F20/F22/F24/F34/F43/F48/F51/F69/F77)

- [ ] On the first migration-045 rollout only, approve the deploy's explicit quiescence step. Confirm one existing subscribed calendar still shows its address and updates, and one existing published feed keeps the same URL and content. Later deploys require no credential-migration pause.
- [ ] Exercise both documented dev policies once. In the recommended `strict` mode, verify the dev web worker has its own Unix/database/FPM identities and cannot read or query production. In explicitly warned `shared` mode, verify the deploy never stops a shared FPM service or locks/revokes a shared DB account. For either mode, a clone must accept only the dev login, retain and re-encrypt ordinary ICS subscriptions by default, strip production sessions/tokens/push/outbound feeds/Google connections, disable and clear copied plugin integrations/active jobs, and honor the optional subscription-strip and external-service switches. Reconnect or reconfigure an external integration explicitly when testing it in dev. Ordinary later dev deploys stay one command.
- [ ] In a disposable deployment fixture, remove one Git-managed test plugin and retain one server-only custom plugin. Confirm only the removed release plugin is quarantined and disabled. No production plugin deletion exercise is required. App-cache poisoning, NAT64 classification and CLI argument refusal are covered by automated tests.

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
