# Maintainability: what makes changes slower or riskier

Written 2026-11-14 at 3702b514. Read-only: no tracked file changed, no test, gate, harness, build or database run. The question is narrow: for an agent arriving cold, where is it hard to find the one place a behaviour lives, to change it without missing a sibling, or to know which test will tell it something broke. Findings point at the next planned work where they can (ROADMAP: #268 conflict on Google single events, then plain-fields undo on Google, then #274 the rebuild of a change to all events on stored state).

## What I read and what I sampled

- Read in full: AGENTS.md, CONTRIBUTING.md, tools/gates.sh, .github/workflows/tests.yml, docs/architecture.md, the post-mortem (workfolder/reviews/postmortem-15/report.md), the deploy test stages of scripts/deploy.sh and scripts/deploy-dev.sh, server/tests/db/lib.php helpers, web/src/app/scopes.js.
- Outlined, not read line by line: docs/design/decisions.md (every entry's title and status, a handful read whole), ROADMAP.md (top half), the method lists of GoogleSeries, Undo, Events, GoogleUndo.
- Measured by script: file sizes for server/src, web and the test trees; the 40 longest PHP functions (PHP tokenizer); PHP methods whose name occurs once across all PHP; PHP classes never referenced outside their file (none); client exports nothing imports; API routes against docs/api-contract.md (both directions); code comments that cite decisions entries by title against the register; hand-written SQLite schemas in the unit suite; construction sites of the main domain classes; which server/tests files any runner includes.
- Sampled for stale docs and comments: api-contract.md around the Google errors, architecture.md, decisions entries with status planned, replaced or probed, comments that name 1.5.6, comments that name migrations or legacy forms (grep for legacy, older form, before migration, saved before).
- Not looked at: PRD.md, FEATURES.md, the plugin folders, the extension, the MCP server beyond its test hook, CSS (static.mjs already cross-checks HTML and CSS classes), dependency freshness beyond composer.json and the hold list.

## Findings

Each: where, what it costs, the fix, size in agent time and lines, risk, what verifies it.

### 1. The Google series-undo switch ships off and is tested on

- Where: server/tests/db/lib.php:347-350 (freshUser turns `google_series_undo` on for every test account, "as it will ship in 1.5.6"); tools/edit-harness/Product.php:22-29 (`$seriesUndo = true`, "as 1.5.6 will ship it"); .github/workflows/tests.yml:120,131,142 (the three harness steps run without `--series-undo off`); against tools/gates.sh:283-284, which passes `--series-undo off`; Settings.php:265 ships it false. 1.5.6 shipped with it off (google-behavior.md:137, ROADMAP: it comes back with #274).
- Cost: the database suite and CI measure a configuration no install runs, and the shipped path (the refusal, `GoogleUndo::SERIES_UNDO_OFF`) is checked by one scenario. Every Google change still has to keep the disabled whole-series undo green (GoogleUndo::undoSeries, finishUndo, pendingRedo, and the pending-undo handling in GoogleSeries around 3273 and 4286), which is the mechanism the post-mortem says never converged and that #274 will rebuild. CI and the local gate disagree on what "the harness passed" means, the same shape as post-mortem cause 2 (a check silently not measuring what it was assumed to).
- Fix: an owner choice. (a) Flip the defaults to off in freshUser and Product.php, opt the series-undo test files in explicitly (`seriesUndo($db, $u, true)`), and add `--series-undo off` to the CI harness steps so CI equals the gate. (b) Delete the whole-series undo path until #274 rebuilds it, leaving the refusal. (a) is the cheap one; (b) removes code nobody runs but touches Finish and Put back.
- Size: (a) 1 to 2 agent hours, about 20 lines plus one opt-in line per test file that needs it (23 db test files mention the switch). (b) 6 to 10 agent hours; measure the path before quoting lines.
- Risk: (a) low: a test that relied on the default fails loudly and gets its opt-in. (b) medium.
- Verifies: `gates.sh quick` with the series-undo files named, then CI green with the same harness flags as `gates.sh full`.

### 2. Four suite lists that disagree: the gate, CI, deploy and dev deploy

- Where: tools/gates.sh:257-272; .github/workflows/tests.yml; scripts/deploy.sh:50-90; scripts/deploy-dev.sh:163-172; CONTRIBUTING.md:59-67.
- What differs: the three Google stand-in suites (tools/google-probe test.php, replay-test.php, access-test.php) run only in gates.sh, never in CI or a deploy, though the 1.5.6 register entry "The stand-in is checked against Google by replaying the app's own requests" rests on replay-test. The script tests (vendor verify, CI pins, dependency watch, release manifest, release notes) run in CI and deploy, never in gates.sh. The smoke suite runs in 1 zone in every gate level, 4 in CI, 6 in deploy and dev deploy; CONTRIBUTING says the deploy uses five. CONTRIBUTING's suite list has 7 entries, the gate runs 10. Harness flags differ (finding 1).
- Cost: "every gate passed" before a push does not mean CI will pass, and CI passing does not mean the stand-in still matches Google. The post-mortem's cause 2, and the earlier stretch where CI was red for five merges unnoticed, are both this shape. A zone bug passes `gates.sh full` and is first seen in CI or at deploy.
- Fix: make gates.sh the one list. Add the script tests and a zone loop for smoke to `suites_light`/`suites_all`; have CI jobs and both deploy scripts call `tools/gates.sh` levels (or a `gates.sh list` that prints the commands) instead of carrying their own; CONTRIBUTING points at the script instead of repeating the list.
- Size: 2 to 3 agent hours, about 80 lines changed, net fewer.
- Risk: low; CI wall time may grow slightly from the stand-in suites.
- Verifies: the CI log shows the same gate names `gates.sh full` prints; a deliberately broken replay fixture fails CI.

### 3. Docs that contradict the code where the next work lands

- docs/api-contract.md:98: the Google errors paragraph contains a merge leftover. After `google_pending` it repeats "Refresh and try again." and an older `google_conflict` description (the stored etag as If-Match), then repeats `google_move`, `google_trip_move`, `google_disconnected`, `google_reconnect_needed`. It also says "nothing is ever sent to Google unconditionally", while GoogleSeries.php:25 sends each day "unconditional" and decisions.md:215 records that choice. #268 changes exactly `google_conflict`, so the next agent reads two versions of it.
- docs/design/decisions.md: line 65 ("Google calendars", status probed) still says "every write is conditional"; line 66 ("Floating times", status planned) is built per line 125; line 156 ("A write to a series whose change is half done is refused", status planned) is built per line 148; line 182 still says series undo is "planned for 1.5.6".
- docs/architecture.md, which calls itself the binding contract: line 7 says sabre/vobject does RRULE expansion (the expander is `Series\RuleIterator`, decisions.md:35); line 8 says tests run against SQLite (there is a MariaDB suite of 113 files); line 62 says there is "no undo across that boundary" for Google, two lines above the bullet describing Google undo; the layout omits Domain/Series/, tools/edit-harness, tools/google-probe and tools/compare, which is where the hardest code and its checks live.
- Comments: GoogleSync.php:126-133 says a NULL own list "stays null ... every reader of the list treats null the same way", but since migration 078 a NULL list is a defect report (OwnFields::stored, OwnFields.php:93); web/src/app/scopes.js:8-12 says a Google calendar takes every scope, and the code below has a Google branch (which, finding 6, is never taken); tools/edit-harness/Product.php:24 and lib.php:349 (finding 1); four harness comments cite the register entry "waits for 1.5.6" by a title that is now false (Driver.php:1772, Interleave.php:1512 and 2156, Product.php:27).
- Cost: a cold agent trusts the contract and the architecture doc first; three of the statements above are about the Google write path that #268, plain-fields undo and #274 change.
- Fix: correct each; in architecture.md add the series and Google subsystems and their checks to the layout. Documentation only, no gate.
- Size: 1 to 2 agent hours, about 40 lines.
- Risk: none to code.
- Verifies: re-read against the cited code lines; the decisions title check in finding 4 for any renamed entry.

### 4. The decisions register is too big to read before every storage change

- Where: docs/design/decisions.md is 351,896 bytes in 248 lines: 193 entries, of which "Sync and Storage" (lines 110-227) is about 300 KB, with single entries up to 11 KB (lines 191-196). docs/design/series-model.md is another 168 KB. The repo's CLAUDE.md tells every agent to "check docs/design/decisions.md before changing how anything is stored or compared".
- Cost: roughly 90,000 tokens to read the register once, which an agent either spends (and loses working context) or skips and skims. Superseded entries stay in place beside their replacements (lines 160, 207 replaced; 65, 66, 156, 182 stale per finding 3), so a skim can land on the old rule. AGENTS.md's rule only works if the register is findable.
- Fix: keep the file as the archive and add an index: one line per current entry (title, status, the function that holds it, the check that verifies it), grouped by area, superseded entries listed apart. Point CLAUDE.md and AGENTS.md at the index. Move, don't rewrite; the long entries stay where they are.
- Size: 3 to 5 agent hours, documentation only. A 20-line script that checks every code comment citing an entry by title still resolves (39 cited titles today; 37 match exactly, 2 differ only in quote style) keeps the index honest.
- Risk: low; the risk is an index line that misstates an entry, so it quotes the entry's own first sentence.
- Verifies: the title script; a cold agent asked "what is the rule for X" answers from the index alone.

### 5. Five MySQL test scripts that nothing runs

- Where: server/tests/files-live.php (176 lines, last edit 2026-11-07), resources-live.php (183, 10-19), credentials-mysql.php (174, 10-20), mail-mysql.php (139, 11-01), plugin-lock-mysql.php (70, 10-27). Each wants its own disposable database under its own environment variable and database name. Searched gates.sh, every workflow, every script and CONTRIBUTING: none runs them; scripts/tests/mail-security-live.sh and plugin-lock-live.sh wrap two of them and are themselves called from nowhere. (planner-equivalence.php is a hand comparison against an older checkout and is fine as is.)
- Cost: about 740 lines of checks on credentials, resource limits, mail and plugin locks, the security boundaries, that rot unseen. A change to RequestAuthority, Limits or MailIngest gets no signal from them, and an agent cannot tell whether a failure there is new.
- Fix: port each into server/tests/db as `*.test.php` (that runner already makes and drops a database per file and checks the data rules after each scenario); delete any part the db suite already covers; delete the two orphan shell wrappers.
- Size: 3 to 5 agent hours, net fewer lines.
- Risk: low; some may already fail, which is the point.
- Verifies: they run in `gates.sh merge`; one injected break per file trips it.

### 6. Dead code and dead guards, with the evidence

- Unreferenced PHP methods (name occurs once across all PHP, then confirmed by grep over server, tools, scripts, web, docs): `Ranking::recordSignal` (Ranking.php:144), `Plugins::dependentsOf` (Plugins.php:624), `Trips::linkRows` (Trips.php:325), `Labels::unknownPersonNames` (Labels.php:94). recordSignal matters more than its size: it is a second writer of `feedback_signals` that skips `RequestAuthority::checkedTx`, which the live writer (Events::recordFeedback, Events.php:2539) uses; an agent wiring thumbs somewhere new could pick the unchecked one.
- Unused client exports (exported, no other file under web/ or tools/mcp mentions them, confirmed by grep): `cycleAttendance` (web/src/app/actions.js:795), `addMinutesDate`, `fmtHour`, `snapDate` (web/src/lib/dates.js:242, 356, 625).
- A branch never taken: web/src/app/scopes.js:25 removes "This and following" for Google calendars when `unofferedWords('google')` is non-null, and `UNOFFERED` (planwords.js:898) is `{}`. The decisions entry "Scopes a calendar kind cannot take are not offered" justifies keeping the hook; the comment should say it is empty, or the branch goes.
- Guards for code ahead of its migrations, which AGENTS.md calls dead (the app refuses to serve with migrations pending): TrustedDevices.php:54-57 (catches the missing table before migration 030), SubscriptionAuthority.php:35-38 (defaults a missing column "before the migration has run", migration 037, added 2026-10-06, before 1.0 on 10-20), and the comment at Throttle.php:18 (its catch-all stays for its other reason, "a failure to count must never become a failure to sign in"; only the migration sentence goes).
- A pre-1.0 client fallback: Time.php:115-121 reads a malformed all-day value from "clients from before this fix" (added 2026-09-17) and says "the branch can go once no such client can exist"; every tab from September has reloaded.
- Size: 1 to 2 agent hours, about 150 to 200 lines removed. Risk: low. Verifies: `gates.sh quick`; the grep commands above return only the definitions before deletion and nothing after.

### 7. GoogleSeries.php: 4,749 lines, three jobs, and #274 rewrites one of them

- Where: server/src/Domain/GoogleSeries.php. Seams by method list: sentences (`named` at 2002 through `rekeyed` at 2670: outcomeWords, replacedWords, sinceWords, hiddenWords, dayWords, about 670 lines, all static and pure); request bodies and the state digest (insertBody 1372 through afterMaster 1802); the run (`run`, 312 lines at 893); and recovery: pending rows, Finish, Put back, reattach (halfDoneOf 3260 to the end, about 1,500 lines).
- Cost: #274 is "Finish and Put back follow from three stored things alone". Done in place, that rebuild is an edit across the back third of a 4,700-line file whose private helpers the front two thirds also call, which is how a rebuild ends up as layered fixes (post-mortem cause 4).
- Fix: as the first commit of #274, move recovery into its own class and the sentences into another, with no behaviour change; the rebuild then replaces the recovery class.
- Size: 3 to 4 agent hours for the moves. The mutation list carries 6 mutations on this file keyed by exact text; they keep working if the text moves with its file path updated.
- Risk: medium (shared private helpers become package-level). Verifies: the mutation runner (every mutation still finds its text and trips), and both harness modes plus interleave at the gate counts.

### 8. Events.php carries the single-event Google write path

- Where: server/src/Domain/Events.php:291-882 (googleCalendarFor, checkGoogleAccess, googleCreate, googlePatchAll, googlePatchThis at 171 lines, googleDeleteAll, googleDeleteThis, googleDeleteOverride, the journal helpers), inside a 3,258-line class that also holds the window (983-1335), serialization (2612-2830) and local writes.
- Cost: #268 (on a conflict at Google, say what changed and offer Cancel or Overwrite, single events first) and plain-fields undo both land in these 590 lines; the agent must hold the rest of Events to be sure nothing else calls them.
- Fix: move the Google write-through into its own class (Events keeps calling it) as #268's first commit.
- Size: 2 to 3 agent hours. Risk: medium (Events' private state the Google methods read). Verifies: the google-* db test files and the Google harness mode.

### 9. Object wiring repeated everywhere, with hidden defaults

- Where: `new Events(` appears in 41 places (2 in src, 4 in bin, 32 in tests, 3 in tools), `new Feeds(` in 65, `new ReviewQueue(` in 65, `new Undo(` 82 times inside server/src alone. GoogleWriter is assembled five ways, two of them lazily from the global config: Events.php:378 and GoogleUndo.php:1091 (`??= new GoogleWriter($db, new GoogleAuth($db, config()), new Feeds($db, null, config()))`); Plugins likewise at Events.php:189, Calendars.php:750, Undo.php:2411. server/tests/db/lib.php:379 has a `domains()` helper, and 20-plus test files build Events by hand anyway (most often with the same seven-argument expression).
- Cost: adding a constructor argument to Events, Feeds or GoogleWriter is 40 to 65 edits; and which GoogleWriter (or none) a path gets depends on where its object was built, which a test injecting the stand-in can miss.
- Fix: one factory for the object graph (per Db and config) that entry-web, entry-dav, the worker, bin scripts and the db tests all call; the lazy `config()` fallbacks go.
- Size: 4 to 6 agent hours, a few hundred lines touched, mostly mechanical. Risk: medium; some entry points may differ on purpose (dav builds Undo without Google). Do it the next time one of these constructors changes. Verifies: `gates.sh merge`.

### 10. The unit suite: one 14,732-line file, 293 hand-written schemas, SQLite branches in the product

- Where: server/tests/run.php is 14,732 lines in one process; it hand-writes 293 `CREATE TABLE` statements (users 47 times, calendars 37, events 33; of the 33 events schemas, 5 carry the `floating` column added in 1.5.4). Product code branches on SQLite for the tests' sake: JobQueue.php:157, 178, 192; Plugins.php:755, 1544; Db.php:133, 286, 295; SystemHealth.php:512; Rsvp.php:31. 53 calls into private methods by reflection.
- Cost: a column a shared read path starts using breaks or silently bypasses dozens of hand schemas (b33feb06, floating times, had to edit them); the product carries test-only branches, which CONTRIBUTING.md:92 already says belong in the database suite; and "which test covers X" means searching one huge file. Reflection tests break on a rename that changes no behaviour.
- Fix: what CONTRIBUTING already prescribes, area by area: when a change touches an area whose run.php section builds an events, calendars or users schema, move that section to the db suite, then drop the SQLite branch it needed. Not a project of its own.
- Size: 10-plus agent hours in total, 1 to 2 per area. Risk: low per area. Verifies: the area's checks pass in the db suite and the SQLite branch is gone.

### 11. Three readings of "can this entry be undone"

- Where: entry-web.php:425-436 (the `X-Undo-Entries` header: raw SQL plus `GoogleUndo::seriesUndoOff`); Activity.php:136 (cutoff, older format, held back); Undo.php:2186 (`undoLatest` passing over entries). Overlaps the special-cases study; noted here because plain-fields undo and #274 change exactly this predicate.
- Fix: one `Undo::undoable(entry)` the three call. 1 to 2 agent hours. Risk: low. Verifies: the toast, Activity and bare-undo tests agree on a held-back and an older-format entry.

### 12. The gate's "engine changed" test is a hand list

- Where: tools/gates.sh:295-297 decides whether `merge --since` runs harness seeds from a regex of file names. It omits Recurrence.php and Trips.php, both of which have mutations in tools/edit-harness/mutations/list.php (2 and 3), so a change to the window's expander gets its mutations but no seed at merge.
- Fix: derive the list from the mutation list's files plus the engine folders. 0.5 agent hours, 5 lines. Risk: none. Verifies: `git diff --name-only` on a Recurrence-only branch triggers seeds.

### 13. Duplicated sentence generators kept equal by comments

- Where: Series\Words (server) and web/src/lib/repeat.js `describeRepeat` follow the same table "by hand" (Words.php:13); GoogleSeries::hiddenWords and planwords.js `hiddenWords` say "change both together" (GoogleSeries.php:1960, planwords.js:899). Each side is tested separately against docs/design/series-model-examples.md.
- Fix: the precedent already exists: server/tests/fixtures/yearly-words.json is read by both series-model.php and smoke.mjs. Extend shared fixtures to the other rule sentences and the hidden-days sentence. 1 to 2 agent hours. Do it when either side's words next change.

## Gate levels against what they cover (from gates.sh and CONTRIBUTING, not run)

| Level | Time stated | Runs | Does not run |
|---|---|---|---|
| quick | 1 to 2 min | unit (whole 14.7k file), smoke in one zone, static, MCP, the three stand-in suites, named db files | the db suite unless named; other zones; script tests |
| merge | 3 min, about 8 with seeds | every suite; with `--since`, mutations on changed files and one recorded seed per mode when the regex matches | other zones; seeds for Recurrence or Trips changes (finding 12) |
| full | 10 to 15 min | every suite, oracle probes, all mutations, recorded and fresh seed per mode with series undo off | other zones; script tests; series undo as the db suite runs it (on) |
| CI | not stated | unit, db, mutations, oracle probes, seed 7 per mode with series undo on, fresh install, smoke in 4 zones, script tests | the three stand-in suites |
| deploy | not stated | smoke in 6 zones, unit, db (or CI's result), script tests | harness, stand-in suites |
| nothing | | tools/tz-harness (the independent check of the expander, last touched 2026-10-26), the five MySQL scripts (finding 5) | |

## Ranked

Worth doing (high benefit, small cost):

1. Finding 1, series-undo switch tested on, shipped off: option (a), 1 to 2 h, low risk.
2. Finding 3, docs that contradict the code on the Google path: 1 to 2 h, docs only.
3. Finding 2, one suite list for gate, CI and deploy: 2 to 3 h, low risk.
4. Finding 6, dead methods, exports and migration guards: 1 to 2 h, low risk.
5. Finding 12, gate engine regex: 0.5 h.
6. Finding 5, port the five ungated MySQL scripts: 3 to 5 h, low risk.
7. Finding 4, an index for the decisions register: 3 to 5 h, docs only.

When next touched:

8. Finding 7, split GoogleSeries as #274's first commit.
9. Finding 8, move Events' Google write path out as #268's first commit.
10. Finding 11, one undoable predicate, with plain-fields undo or #274.
11. Finding 9, one object factory, on the next constructor change of Events, Feeds or GoogleWriter.
12. Finding 10, move run.php areas to the db suite as each area is touched.
13. Finding 13, shared word fixtures when either side's words change.

Leave alone:

- Series\Model::map, 1,045 lines (Model.php:2037), the longest function in the code. It is the engine every later review left standing and the harness measures it; no planned change touches it. Split only when the engine itself changes.
- The name `Recurrence::sabreExpand` (Recurrence.php:670) no longer uses sabre, but the harness's `--product` mode calls older checkouts by that name, so a rename costs the comparisons with old releases. Fixing architecture.md (finding 3) removes the misleading part.
- webklex/php-imap brings about 15 of the 51 locked packages (illuminate/*, nesbot/carbon, symfony/translation, doctrine/inflector, voku) for one 392-line MailFetcher. Replacing it means writing IMAP handling. Revisit only if a lock or PHP-floor problem traced to them recurs.
- The large Preact components: App() 1,047 lines, EditorDrawer() 1,058, TimeGrid() 1,309 (18 effects, 18 refs), MonthGrid() 771. Their logic in closures is out of the smoke suite's reach, but nothing planned before 1.6 views touches them; then pull pure geometry into lib modules first (monthmath.js is the precedent).
- Feeds::sync (382 lines), bc_handle_api (358 lines, 140 routes inline), FallbackParser::parse (333) and ReviewController::index (316): long but each one job, and no planned change runs through them.
- API contract against routes: 140 routes, 142 contract mentions. One route is not written as METHOD PATH in the contract (`GET /google/connect`), and three contract mentions (`POST /share`, `GET /feed/:token.ics`, `PATCH /events` as prose) are served outside the API router. Nothing to fix beyond finding 3.
