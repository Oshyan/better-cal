# Security remediation record: 2026-10-16 scan

The final security scan before 1.0: a full Codex Security scan of the repository at revision `b56422b` (0.9.86 and one later commit), reviewed and fixed in six passes, all released in 0.9.87. Each fix was revalidated against the code before changing it, proposed with its effect on owners and the maintainer, and landed with regression tests that fail on the earlier code. SECURITY.md summarizes it; this file records each finding.

54 findings: 5 High, 33 Medium, 16 Low. 47 fixed; 5 reduced, with what remains documented; 2 accepted by design and documented. None was left open.

The six passes:

1. Deployment configuration and shell boundaries (maintainer tooling).
2. Setup codes and the release trust chain.
3. Private files, exports, backups and maintainer hygiene.
4. Credentials, API key capabilities and revocation.
5. Email ingest and outside services.
6. Resource limits and remaining input boundaries.

| Severity | Finding | Pass | Outcome | Notes |
|---|---|---|---|---|
| High | Deployment configuration can inject shell startup and tool-control variables | 1 | Fixed | deploy.env is read as data from an allowlist of keys, and nothing it names is exported to child processes. |
| High | Deployment paths can inject commands into the privileged remote shell | 1 | Fixed | Remote values travel as separately quoted arguments; paths are restricted to plain absolute paths. |
| High | push-settings executes deployment configuration as local shell code | 1 | Fixed | push-settings uses the same data-only loader instead of running the file as shell code. |
| High | Documented install and manual-update paths execute unsigned release code | 2 | Reduced, documented | A fresh install from a downloaded zip is trust on first use. The install guide says so and documents optional local verification with a copy you already trust. update.php verifies every update. |
| High | The release signer can authenticate dependency bytes supplied by the compromised distributor | 2 | Fixed | Before signing, every library must reproduce byte for byte from the Git commit recorded in the lock file. A git-checkout install still runs Composer outside this check (documented). |
| Medium | Dynamic signer dependencies can compromise release integrity | 2 | Fixed | The FIDO signer runs from a prepared, hash-pinned, offline environment. |
| Medium | Flooding invalid setup codes can exhaust PHP workers and block installation or database recovery | 2 | Fixed | Wrong codes no longer hold a request; they are refused at once. |
| Medium | One-time setup code remains replayable and replaces the authorized session | 2 | Fixed | A code works once; the code and session share one locked file. |
| Medium | Release verification extracts the untrusted draft archive before entry and expanded-size checks | 2 | Fixed | The draft zip is size-capped and compared entry by entry without being unpacked. |
| Medium | The release signer signs remotely downloaded security metadata without comparing it to maintainer intent | 2 | Fixed | The downloaded manifest must match the locally written one byte for byte before signing. |
| Medium | A calendar export can exhaust server memory and temporary storage | 3 | Fixed | Exports stream in batches; one export runs at a time; no cap or truncation. |
| Medium | A shared-host neighbor can read complete calendar exports from the system temporary directory | 3 | Fixed | Exports are built in private storage inside the app folder and removed afterwards. |
| Medium | API guidance exposes long-lived account tokens in process arguments | 3 | Fixed | Examples read the token from a private file or the tool's own environment, never a command line. |
| Medium | Database restores can race an already-running worker | 3 | Fixed | Restores take the worker lock first; a stopped restore keeps the site closed. |
| Medium | Developer timezone harnesses are executable through misconfigured web roots | 3 | Fixed | The harnesses refuse to run outside the command line. |
| Medium | Privileged legacy-backup conversion follows application-controlled symlinks | 3 | Fixed | Conversion refuses linked paths and leaves app-owned backups to the app account. |
| Medium | Strict development isolation can pass while the development identity can rewrite production code | 3 | Fixed | Strict mode also checks that the dev account cannot write production files. |
| Medium | Updater follows planted backup-directory symlinks outside its intended root | 3 | Fixed | The backup folder must be a real, owned directory with no links. |
| Medium | An in-flight old-password login can survive a password reset | 4 | Fixed | A session is created only if the password hash is still the one that was verified. |
| Medium | Any browser session can detach an ICS subscription from its API-token revocation boundary | 4 | Fixed | Claiming needs a fresh password and applies only to paused or revoked-token subscriptions. |
| Medium | Bearer API tokens can persistently suppress security and worker alerts | 4 | Fixed | API keys can no longer silence worker warnings or security update notices. |
| Medium | Bearer-readable plugin inventory exposes stored integration credentials | 4 | Fixed | Plugin settings can be marked secret: write-only, never returned or logged. |
| Medium | Google RSVP can outlive calendar cutover or compromise reset | 4 | Fixed | Replies recheck the session and calendar binding before sending and before saving. A request already sent cannot be recalled (documented). |
| Medium | Google account disconnect lacks recent authentication and can commit after the initiating session is revoked | 4 | Fixed | Disconnect needs a fresh password and rechecks the session before and after the call to Google. |
| Medium | Racing an ordinary settings update can restore reminder delivery after session recovery | 4 | Fixed | Every settings write rechecks the session or token in its transaction. |
| Medium | Revoking a CalDAV API token does not stop an already-admitted write | 4 | Fixed | CalDAV writes recheck the token inside the write transaction. |
| Medium | Signing out a stolen browser does not stop its calendar subscription | 4 | Fixed | Signing out other browsers lists subscriptions they added, to keep or pause for Review. |
| Medium | Disabling place lookup does not stop plugin location queries | 5 | Fixed | Plugins get the real configuration, so place lookup off applies to them. |
| Medium | Feed-triggered ranking bypasses persistent paid-model admission | 5 | Fixed | Ranking goes through the persistent paid-model limits. |
| Medium | Forged invitation sequence numbers can suppress genuine organizer updates | 5 | Fixed | Only applied changes set the bar; competing pending versions stay visible side by side. |
| Medium | Malformed messages can indefinitely starve mail ingestion | 5 | Fixed | A bad message is set aside and shown in Review; later mail is not blocked. |
| Medium | RSVP replies bypass persistent outbound-email limits | 5 | Fixed | Replies reserve outbound-email capacity first and cannot be sent twice at once. |
| Medium | Unauthenticated email can create calendar events without owner review | 5 | Reduced, documented | Owner setting: any sender, approved senders (default) or hold everything. Sender approval is a preference, not proof of identity. |
| Medium | CalDAV size enforcement occurs after the request body is materialized | 6 | Fixed | CalDAV stops reading at the size limit, before any parsing. |
| Medium | Calendar ZIP imports lack archive-wide work budgets | 6 | Fixed | The whole archive is budgeted before any calendar is created. |
| Medium | Comma-packed singleton date properties bypass ICS parser work budgets | 6 | Fixed | Such an event is refused on its own; the rest of the feed, email or import goes through. |
| Medium | Trip Planner multiplies admitted feed events into oversized retained candidate maps | 6 | Fixed | Scores are computed without per-candidate copies; suggestions verified identical. |
| Medium | Unlimited push-device enrollment creates unbounded synchronous delivery fanout | 6 | Reduced, documented | One identity per device and a ceiling of 100 by default. Delivery stays sequential, so total delivery time is not hard-bounded. |
| Low | Concurrent first visits can invalidate the displayed setup code | 2 | Fixed | Code issuance runs under one lock with one state file. |
| Low | Editable release notes can spoof trusted updater terminal output | 2 | Fixed | Terminal control characters are stripped and the notes are labelled unsigned. |
| Low | A missing session secret makes new database backups plaintext | 3 | Fixed | New backups are refused without a secret; Settings and the update page say how to add one. |
| Low | A strict-development settings race can redirect production database authentication | 3 | Fixed | Dev clone settings are read once, without following links. |
| Low | Copy-to-calendar can send event details to the previously selected destination | 3 | Fixed | Copy uses the destination just selected. |
| Low | Deployment helper leaves worker metadata in a log without enforced private permissions | 3 | Fixed | The worker log is created private and an existing one is tightened. |
| Low | Tracked documentation exposes live-install context prohibited by repository policy | 3 | Fixed | Install-specific figures were generalized or removed. Git history was not rewritten. |
| Low | A remotely signed-out device keeps private offline data for up to 24 hours | 4 | Accepted, documented | The phone app keeps its offline allowance of up to 24 hours for travel; it clears as soon as it reconnects and learns it was signed out. |
| Low | API tokens can read browser-only host diagnostics | 4 | Fixed | The token view of system health omits server paths, alert addresses and push endpoints, and cleans error text. |
| Low | Revoked token destinations can receive one in-flight notification | 4 | Fixed | Each send rechecks its destination first. A message already handed to a provider cannot be recalled (documented). |
| Low | One malicious event can influence model treatment of other events in its batch | 5 | Reduced, documented | Events stay batched for cost, with stricter output checks; independence of the model's judgment cannot be proven. |
| Low | A crafted trip description can freeze the browser through quadratic tag stripping | 6 | Fixed | Tag stripping uses the existing linear plain-text conversion. |
| Low | Adding many events to one trip can exhaust database storage and request workers | 6 | Fixed | Only the changed link is logged; no member cap. |
| Low | Calendar import bypasses the Google-move write freeze and can lose successfully imported events | 6 | Fixed | Imports take the same calendar lock and move guard as other writes. |
| Low | Distributed failed logins can block correct passwords from new devices | 6 | Accepted, documented | Browsers that signed in before still get in during an attack; a brand-new device may have to wait. The message now says so. |
| Low | Sensitive deep-link payloads enter the initial HTTP request target | 6 | Reduced, documented | New links carry private values in the URL fragment. Old links still work during the transition (Chrome extension 2.1.1). |

"Reduced, documented" means the fix removes the practical risk but cannot remove all of it; SECURITY.md (Known residuals, Design trade-offs and Releases and updates) says what remains and why.
