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
TZ=America/Los_Angeles node web/tests/smoke.mjs       # frontend logic
node --experimental-vm-modules web/tests/static.mjs   # module graph, HTML/CSS cross-check
node tools/mcp/test.mjs                               # MCP server
bash scripts/tests/deploy-security.sh                 # deploy privilege boundaries
bash scripts/tests/release-security.sh                # release and signing checks
```

- The deploy runs the smoke suite under five time zones, so a test that only passes in yours will be caught. Try another `TZ` before sending.
- Add a test with any behavior change. The server suite is plain PHP with no database server: it uses SQLite in memory where it needs one, so PHP needs `pdo_sqlite` (`php8.4-sqlite3` on Debian and Ubuntu). The CalDAV and iCalendar checks also need `composer install` in `server/` and are skipped without it. The smoke suite is plain Node.
- Changing repeating events, time zones, or import and export (`Recurrence`, `Ics`, `Time`, `web/src/lib/dates.js`)? Also run `tools/tz-harness/run.sh`, which checks about 3,800 series against two independent calendar engines ([its README](tools/tz-harness/README.md)). It needs Composer's libraries in `server/vendor`, uv and Node, so it isn't part of the deploy.

A few habits the codebase keeps:

- A comment explains why, never what. The what is the code.
- User-facing text is plain, direct and free of jargon; the app never says "error occurred" when it can say what happened.
- Timestamps are ISO 8601 with a zone offset, everywhere.
- Anything that writes to Google says so, and says it can't be undone, before and after.
- A migration for every schema change, numbered after the last one in `server/migrations/`, and documented in [docs/api-contract.md](docs/api-contract.md) if it changes what the API returns.

## Versions

Maintainers bump `VERSION` and add a `CHANGELOG.md` entry when cutting a release; pull requests don't need to. Releases follow [docs/releasing.md](docs/releasing.md).

## License

By contributing you agree that your contribution is licensed under the MIT license in `LICENSE`, like the rest of the project.
