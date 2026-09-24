# Better-Cal Architecture Contract

This document is the binding contract between backend and frontend work. Deviations require updating this doc in the same change. What the product does lives in `FEATURES.md` and `PRD.md`; what is planned lives in the GitHub issues. This file is about how it is built.

## Stack

- Backend: PHP 8.3 or later (production runs 8.4; CI runs the runner's stock PHP), no framework. Composer deps: `sabre/dav` and `sabre/vobject` (CalDAV server, ICS parse/serialize, RRULE expansion), `phpmailer` (reminder email and iMIP replies), `minishlink/web-push` (push reminders). Everything else is in-repo.
- Database: MySQL 8 (Percona 8.4 in production), utf8mb4, FULLTEXT for search. Tests run against SQLite in memory where they need a database at all.
- Frontend: Preact + HTM as vendored single-file ESM modules under `web/vendor/`, pinned by sha256 in `web/vendor/manifest.json`. No build step, no package manager; ES2022 modules served as written. The only generated artifacts are the modulepreload block and the service worker's shell list, both produced by `scripts/gen-preload.mjs` from the import graph.
- Deployment: rsync the repository to a host, `composer install` there, run migrations, symlink the web server's document root to `server/public`, and run `server/bin/worker.php` from cron every minute as the app user. `scripts/deploy.sh` does all of that for the host named in `scripts/deploy.env` (see `deploy.env.example`), after running every test suite locally and refusing to ship a red tree. `docs/install.md` has the web server blocks.

## Repo layout

```
server/
  public/            # document root: index.php front controller, dav.php (CalDAV), /assets symlink to ../../web
  src/               # PHP classes, PSR-4 "BetterCal\" via composer autoload
    Http/            # Router, Request, Response, HttpError, StaticFiles, Controllers/ (one per API area)
    Domain/          # The application: Events, Calendars, Recurrence, Feeds, Search, Undo, Activity,
                     #   People, Trips, Reminders, Settings, SavedViews, Filters, Ranking, QuickAdd,
                     #   Google (Auth, Sync, Writer), MailIngest, ReviewQueue, Plugins, Proposals, ...
    Dav/             # sabre/dav backends: auth, principals, calendars, change log, size limits
    Plugin/          # PluginHost (the sandbox a plugin runs in) and PluginInterface
    Infra/           # Db (PDO wrapper), HttpClient (SSRF-safe, budgeted), LlmGateway + transports,
                     #   JobQueue, Notifier/PushSender/EmailSender, MailFetcher, Secrets (libsodium), Throttle
    Support/         # Time, Ids, Limits, ClientIp: pure helpers with no dependencies
  bin/               # worker.php (cron), migrate.php, seed.php, smoke.php, token.php, vapid.php,
                     #   import-takeout.php, profile-*.php
  config/            # config.php reads env / .env file; every key is read in exactly one place
  data/              # generated static tables (IATA airports)
  migrations/        # NNN_name.sql, applied in order by migrate.php, tracked in schema_migrations
  tests/             # run.php: plain PHP, no PHPUnit, no MySQL, no network
web/
  index.html         # app shell (served by the PHP front controller at /)
  sw.js              # service worker: cache-first shell, network-first API with cache fallback
  vendor/            # preact, htm, squire, dompurify, leaflet as single-file ESM
  src/ui/            # bettercal-ui: grids, chips, agenda, drag. NO imports from src/app (enforced by review)
  src/app/           # app shell: store, api client, actions, pages, overlays, keyboard, commands
  src/lib/           # pure utilities: dates, colour, context tokens, relationship filter, rich text, ...
  styles/app.css     # one stylesheet, custom properties, light and dark
  tests/             # smoke.mjs (logic, any time zone), static.mjs (module graph and HTML/CSS cross-check)
tools/
  mcp/               # MCP server exposing the API to agents, with its own test
  gen-iata.py        # regenerates server/data/iata-airports.php from OurAirports
extension/           # Chrome extension: Google Calendar add-links open in your Better-Cal
scripts/             # deploy.sh, deploy-dev.sh, gen-preload.mjs, vendor.mjs
docs/                # this file, install, api-contract, agent-api, caldav, email-ingest, google-calendar,
                     #   relationships, migration, plugins/ (authoring), design notes, security review
```

## Conventions

- All timestamps are stored UTC in DATETIME columns; events carry `tzid` for display and recurrence math. The API exchanges ISO 8601 with a zone offset, and so do logs.
- IDs are BIGINT auto-increment internally; events also keep an iCal `uid` for interop with feeds, CalDAV and Google.
- API: JSON under `/api/v1`. Session cookie auth (`bc_session`, httpOnly, Secure, SameSite=Lax); every non-GET request carries an `X-CSRF` header matching the session's token (returned by `/me`). A second cookie, `bc_device`, only ever sent to `/api/v1/auth`, remembers a browser that has signed in so it can get past the sign-in brake during a distributed attack (`Domain/TrustedDevices`, issue #59). Personal access tokens (`Authorization: Bearer bc_...`, sha256-hashed in `api_tokens`, CSRF-exempt) serve agents and scripts; token management itself is session-only. `docs/api-contract.md` is the contract, `docs/agent-api.md` the agent view, `tools/mcp/` the MCP server over it.
- Errors: `{"error": {"code": "string", "message": "human"}}` with the proper HTTP status. Codes are stable identifiers the client switches on; messages are plain language.
- Recurrence: the RRULE lives on the master event; the API always returns expanded occurrences for the requested window, each with `eventId` and `instanceId` (`eventId:YYYYMMDDTHHMMSSZ`). Any change to one occurrence asks for a scope: `this`, `following` or `all`. Overrides are rows with `recurrence_parent_id`; a user's own overrides survive feed and Google polls.
- Calendars have a `kind` (local, subscribed, plugin), a `provider` (ics or google) and a `role` (mine, opportunities, context). Feed and plugin events are read-only except the person's own marks (attendance, tags, reminders, relationship). Events on a writable Google calendar write through to Google and say so, before and after, because there is no undo across that boundary. `docs/relationships.md` and `docs/google-calendar.md`.
- Frontend `src/ui` is pure: takes data and emits intents through callbacks, never fetches, never reads the store. `src/app` owns state (`store.js`), the API client (`api.js`) and every side effect (`actions.js`).
- Undo: every local mutation records an inverse in `mutations` and shows a toast; `POST /api/v1/undo` reverts the latest, `Activity` lists and reverts by id. Writes that go to Google are journalled as log-only and offer no undo.
- Outbound HTTP (feeds, geocoding, push, Google, plugins) goes through one `HttpClient` that refuses private, loopback, link-local and cloud-metadata addresses, caps bodies and redirects, and charges every request to a per-run budget.
- LLM: `LlmGateway` fronts one provider (Gemini; the model is `BETTERCAL_GEMINI_MODEL`) for quick-add parsing, search translation, ranking and plugin completions. A deterministic parser handles the common absolute and relative dates; an LLM failure never blocks creating an event.
- Plugins run server-side under `PluginHost` with declared permissions, their own calendars, a request budget and the SSRF-safe client; `docs/plugins/authoring.md`.
- Configuration is environment only, read once in `server/config/config.php` from real variables or the `.env` file at the app root. `.env.example` lists every key with what it is for and is the source of truth; only the database, base URL and session secret are required.
- Secrets at rest (Google refresh tokens, mail passwords) are sealed with libsodium under a key derived from the session secret (`Infra\Secrets`); nothing in the repository holds a credential.
- Tests: `php server/tests/run.php`, `node web/tests/smoke.mjs` (run under several time zones on deploy), `node --experimental-vm-modules web/tests/static.mjs`, `node tools/mcp/test.mjs`. CI runs all four on every push and pull request; the deploy script runs them before shipping.
