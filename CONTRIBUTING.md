# Contributing

Thanks for looking. Better-Cal is one person's daily calendar that happens to be developed in the open (see "What this is, and isn't" in the README), so contributions are welcome with that in mind: they will be judged on whether they make the calendar better to live with, not on whether they add a feature.

## What to send

- **Bug fixes and small improvements**: open a pull request directly. Say what was wrong and how you checked the fix.
- **Anything larger** (a new feature, a change to how something works, a new plugin in the repo): open an issue first and describe the problem you are solving, not just the solution. That avoids work that will not be taken. Existing design decisions are documented in `PRD.md`, `docs/`, and often in a comment at the top of the file that owns them; a proposal that argues with one of those should say so.
- **Security problems**: not here. See `SECURITY.md`.

Honest answers are the promise. Some things will be declined because they are not wanted in this project, and that will be said plainly rather than left to sit.

## Running it locally

- Requirements and setup: `docs/install.md`. Copy `.env.example` to `.env`; only the database, base URL and session secret are required.
- Layout and conventions: `docs/architecture.md`. Plain PHP 8.3+ with no framework on the server, Preact + HTM with no build step on the client. Read that before adding a dependency; the bar for one is high.
- Writing a plugin: `docs/plugins/authoring.md`.

## Before you open a pull request

Every suite must pass; CI runs them on every pull request and the deploy script refuses to ship if they fail.

```bash
php server/tests/run.php
TZ=America/Los_Angeles node web/tests/smoke.mjs
node --experimental-vm-modules web/tests/static.mjs
node tools/mcp/test.mjs
```

The smoke suite is run under several time zones on deploy, so a test that only passes in yours will be caught. Add a test with any behaviour change: the server suite is plain PHP with no database (SQLite in memory where one is needed), the smoke suite is plain Node.

A few habits the codebase keeps:

- A comment explains why, never what. The what is the code.
- User-facing text is plain, direct and free of jargon; the app never says "error occurred" when it can say what happened.
- Timestamps are ISO 8601 with a zone offset, everywhere.
- Anything that writes to Google says so and says it cannot be undone, before and after.
- A migration for every schema change, numbered after the last one in `server/migrations/`, and documented in `docs/api-contract.md` if it changes what the API returns.

## Versions

Maintainers bump `VERSION` and add a `CHANGELOG.md` entry when cutting a release; pull requests do not need to.

## Licence

By contributing you agree that your contribution is licensed under the MIT licence in `LICENSE`, like the rest of the project.
