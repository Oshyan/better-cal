# Contributing

Thanks for looking. Better-Cal is one person's daily calendar that happens to be developed in the open (see [What this is, and isn't](README.md#what-this-is-and-isnt)), so contributions are judged on whether they make the calendar better to live with, not on whether they add a feature.

## What to Send

- **Bug fixes and small improvements:** open a pull request. Say what was wrong and how you checked the fix.
- **Anything larger** (a new feature, a change to how something works, a new plugin in the repo): open an issue first and describe the problem you're solving, not just the solution, so you don't do work that won't be taken. Existing design decisions are in `PRD.md`, `docs/`, and often a comment at the top of the file that owns them; a proposal that argues with one should say so.
- **Security problems:** not here. See [SECURITY.md](SECURITY.md).

Some things will be declined because they aren't wanted in this project, and I'll say so plainly rather than leave them to sit.

## Running It Locally

- **Setup:** [docs/install.md](docs/install.md). The installer writes `.env.php`; by hand, copy `.env.example` to `.env.php`, keep its first line, and fill in the database, base URL and session secret (the only required settings).
- **Layout and conventions:** [docs/architecture.md](docs/architecture.md). Plain PHP 8.4+ with no framework on the server, Preact + HTM with no build step in the browser. Read it before adding a dependency; the bar for one is high.
- **Writing a plugin:** [docs/plugins/authoring.md](docs/plugins/authoring.md).

## Before You Open a Pull Request

Every suite must pass. CI runs them all on every push and pull request, and the deploy script refuses to ship unless the server, frontend, deploy-security and vendor checks pass.

```bash
php server/tests/run.php                              # server
BC_TEST_DB=127.0.0.1:3306 php server/tests/db/run.php # server, against MariaDB
TZ=America/Los_Angeles node web/tests/smoke.mjs       # frontend logic
node --experimental-vm-modules web/tests/static.mjs   # module graph, HTML/CSS cross-check
node tools/mcp/test.mjs                               # MCP server
bash scripts/tests/deploy-security.sh                 # deploy privilege boundaries
bash scripts/tests/release-security.sh                # release and signing checks
```

### Gates: Three Levels

`tools/gates.sh` runs the suites above at the level the moment needs, side by side, one line per gate. Run what fits; running the heaviest level at every step only slows the work down.

| Level | When | What it runs | About |
|---|---|---|---|
| `tools/gates.sh quick [db-test ...]` | While working, by a person or an agent | The unit suite, the frontend suites, and only the database test files you name (`undo`, `google-series`, ...): the ones near your change, and your new one | 1 to 2 minutes |
| `tools/gates.sh merge [--since REF]` | After a merge into your branch or `main` | Every suite. With `--since REF`: also the mutations aimed at files changed since REF, and one seed in each harness mode when the repeating-event engine or the Google write path changed | 3 minutes, about 8 with seeds |
| `tools/gates.sh full` | Once, on the final tree, before a push to `main` | Every suite, the oracle probes, one recorded seed and one fresh seed in each harness mode, and the whole mutation list | 10 to 15 minutes |

- **A commit that changes only documentation needs no gate,** on a branch or before a push to `main`: Markdown under `docs/`, and the README, the roadmap, this file and the other Markdown files at the root. The one exception is `CHANGELOG.md`, which the unit suite reads (it checks that the current `VERSION` has an entry): run `quick` for a commit that touches it. Anything else in the commit (code, a test, a migration, a script, a settings example) and it is not a documentation commit. CI still runs on the push; read its result.
- A worker on one change runs `quick`. It does not run seeds or the mutation list, and it does not re-run everything after merging `main`: the merge into `main` is gated there, once.
- The harness seeds run **as shipped**. A setting that is off in the release (today: undo of a change to all events on a Google calendar) is off in the gates; measuring it with the setting on is for the work that will turn it on, not a gate for this release.
- The harness is a randomized test. A recorded seed run again is a regression test and finds nothing new, so the gates carry one; the fresh seed is where it can find something, and its number is printed so a failure replays. Wider runs (more seeds, more cases) are worth doing in the background while other work goes on; they are not gates, and a failure they find is a finding to triage, not a blocked push.
- When the fresh seed fails, triage it before anything else: replay the case alone (the gate prints the seed; `--case N` replays one), and run it against the previous release with `--product`. A fault the change being pushed introduced blocks the push. One that fails the same way on the previous release is filed with its seed and case and does not block; a check that is wrong is fixed with its case pinned. A fresh seed must not turn every push into a hunt for old, rare faults.
- What the gates don't replace: before a release, an independent adversarial read of the change by a reviewer who has not seen the work (a person, or an agent from another model), with a runnable probe for each finding. In the 1.5.x rounds that is what found the faults that mattered, after every gate had passed.

`BC_TEST_DB` is read as below; `GATES_LOG` chooses where each gate's output goes, and `GATES_SEED` fixes the fresh seed.

- The deploy runs the smoke suite under five time zones, so a test that only passes in yours will be caught. Try another `TZ` before sending.
- Add a test with any behavior change. The server has two suites:
  - **`server/tests/run.php`** is plain PHP with no database server, for logic: parsing, recurrence, time zones, serializers, validation, anything that doesn't depend on how the database behaves. Where it needs a table it uses SQLite in memory, so PHP needs `pdo_sqlite` (`php8.4-sqlite3` on Debian and Ubuntu). The CalDAV and iCalendar checks also need `composer install` in `server/` and are skipped without it.
  - **`server/tests/db/`** runs against MariaDB with the schema the migrations build, for anything that depends on the database: undo and the journal, foreign keys and what cascades, ordering, transactions and row locks, two connections at once, and migrations that change data. It needs Composer's libraries (`composer install` in `server/`). Each run makes its own database and drops it, so it needs a server where it may create databases: `BC_TEST_DB` (host:port), `BC_TEST_DB_USER` and `BC_TEST_DB_PASS` (root, no password by default). A throwaway one: `docker run -d --rm -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 -p 127.0.0.1:3306:3306 mariadb:10.11`. Its files run at once, each in its own process and database, as many as the machine has CPUs; `BC_TEST_JOBS=N` sets how many, and `BC_TEST_JOBS=1` runs them one after another in one database. Tests are `*.test.php` files there, each returning a function of the database. After every `scenario()` block and every file it checks the link rule over all data by raw SQL (`linkViolations` in `lib.php`: a live day row holds its own tags and people when its own list names them, else exactly its series'; no NULL list; no day row outliving its series) and provider identity (`identityViolations`: a live day row's Google id is its key's id under its series' form, all-day `<series>_<YYYYMMDD>` or timed `<series>_<UTC basic time>Z`; no all-day row in a zone; no clock in a key or skip of an all-day series), failing the block that left a violation; a block that builds an older or broken form on purpose names its reason as `scenario()`'s third argument, which covers both rules.
  - When a test needs a hand-written SQLite schema, or app code would need a branch for SQLite to pass it, it belongs in the database suite instead. Tests that touch the database move there area by area.
- Anything that changes what undo knows (events, calendars, folders, people, trips, tags on events, saved views, filters) goes through `Undo::write`: one transaction, the account's row locked first (the full lock order is in its comment), its journal entry recorded inside. On the paths they run, both suites fail on a journal entry recorded anywhere else and on a `write()` begun inside a transaction opened elsewhere (production logs them). `server/tests/db/completeness.test.php` checks that each operation's entry says everything it changed; a new writer gets a line there. A change that removes rows by the thousands (a calendar's events) records its entry first and adds them a page at a time with `Undo::recordRemovedEvents`, which keeps them apart from the entry in pieces (`mutation_rows`), inside `write()` and never inside `group()` (it throws there). In any entry over a megabyte, `record()` puts the made and removed rows of the events, tags, people and trip-link tables into pieces of at most 512 KB; kept or changed rows stay inline.
- The smoke suite is plain Node.
- Changing repeating events, time zones, or import and export (`Recurrence`, `Ics`, `Time`, `web/src/lib/dates.js`)? Also run `tools/tz-harness/run.sh`, which checks about 3,800 series against two independent calendar engines ([its README](tools/tz-harness/README.md)). It needs Composer's libraries in `server/vendor`, uv and Node, so it isn't part of the deploy.

A few habits the codebase keeps:

- A comment explains why, never what. The what is the code.
- User-facing text is plain, direct and free of jargon; the app never says "error occurred" when it can say what happened.
- Timestamps are ISO 8601 with a zone offset, everywhere.
- Anything that writes to Google says so, and says it can't be undone, before and after.
- Changing how anything is stored or compared? Read [AGENTS.md](AGENTS.md) first: a change to a stored form is a format version bump, never a migration that rewrites old rows.
- A migration for every schema change, numbered after the last one in `server/migrations/`, and documented in [docs/api-contract.md](docs/api-contract.md) if it changes what the API returns.

## Versions

Maintainers bump `VERSION` and add a `CHANGELOG.md` entry when cutting a release; pull requests don't need to. Releases follow [docs/releasing.md](docs/releasing.md).

## License

By contributing you agree that your contribution is licensed under the MIT license in `LICENSE`, like the rest of the project.
