# Better-Cal Architecture Contract

This document is the binding contract between backend and frontend work. Deviations require updating this doc in the same change. What the product does lives in `FEATURES.md` and `PRD.md`; what is planned lives in the GitHub issues. This file is about how it is built.

## Stack

- Backend: PHP 8.4.1 or later (production runs 8.4; CI installs and tests on 8.4 in the official PHP container), no framework. Composer deps: `sabre/dav` and `sabre/vobject` (CalDAV server, ICS parse/serialize, RRULE expansion), `phpmailer` (reminder email and iMIP replies), `minishlink/web-push` (push reminders), `webklex/php-imap` (reading the ingest mailbox). Everything else is in-repo.
- Database: MySQL 8 (Percona 8.4 in production), utf8mb4, FULLTEXT for search. Tests run against SQLite in memory where they need a database at all.
- Frontend: Preact + HTM as vendored single-file ESM modules under `web/vendor/`, pinned by sha256 in `web/vendor/manifest.json`. No build step, no package manager; ES2022 modules served as written. Nothing is generated into the repository. The two things that depend on the whole import graph, the modulepreload block in `index.html` and the service worker's `VERSION` and shell list, are filled in by the server as it serves those files (`server/src/Http/AppShell.php`), cached until a file under `web/` changes.
- Deployment: rsync the repository to a host, `composer install` there, run migrations, symlink the web server's document root to `server/public`, and run `server/bin/worker.php` from cron every minute as the app user. `scripts/deploy.sh` does all of that for the host named in `scripts/deploy.env` (see `deploy.env.example`), shipping the last commit rather than the working tree, after running the server and frontend test suites locally (it refuses to ship a red tree), and backs up the app and database on the host before changing anything. `docs/install.md` has the web server blocks.

## Repo layout

```
server/
  public/            # document root: index.php front controller, dav.php (CalDAV), /assets symlink to ../../web
  src/               # PHP classes, PSR-4 "BetterCal\" via composer autoload
    Http/            # Router, Request, Response, HttpError, StaticFiles, AppShell, Controllers/ (one per API area)
    Domain/          # The application: Events, Calendars, Recurrence, Feeds, Search, Undo, Activity,
                     #   People, Trips, Reminders, Settings, SavedViews, Filters, Ranking, QuickAdd,
                     #   Google (Auth, Sync, Writer), MailIngest, ReviewQueue, Plugins, Proposals, ...
    Dav/             # sabre/dav backends: auth, principals, calendars, change log, size limits, ICS mapping
    Plugin/          # PluginHost (the sandbox a plugin runs in) and PluginInterface
    Infra/           # Db (PDO wrapper), HttpClient (SSRF-safe, budgeted), LlmGateway + transports,
                     #   geocoder transports, JobQueue, Notifier/PushSender/EmailSender, MailFetcher,
                     #   Secrets (libsodium), Throttle
    Support/         # Time, Ids, Limits, ClientIp: pure helpers with no dependencies; Patterns (reads
                     #   web/src/lib/patterns.js, the text patterns both sides share)
  bin/               # worker.php (cron), migrate.php, seed.php, demo-seed.php, smoke.php, token.php, vapid.php,
                     #   import-takeout.php, profile-*.php
  config/            # config.php reads env / .env file; every key is read in exactly one place
  data/              # generated static tables (IATA airports)
  plugins/           # bundled plugins, one folder each (plugin.json + Plugin.php): weather, sun, tides, ...
  migrations/        # NNN_name.sql (or .php returning fn(Db), for data changes that need app code), applied in order by migrate.php, tracked in schema_migrations
  tests/             # run.php (+ plugins.php): plain PHP, no PHPUnit, no MySQL, no network
web/
  index.html         # app shell (served by the PHP front controller at /)
  sw.js              # service worker: cache-first shell, network-first API with cache fallback
  vendor/            # preact, htm as single-file ESM; squire, dompurify, leaflet as scripts loaded on demand
  src/ui/            # bettercal-ui: grids, chips, agenda, drag. NO imports from src/app (enforced by review)
  src/app/           # app shell: store, api client, actions, pages, overlays, keyboard, commands
  src/lib/           # pure utilities: dates, colour, context tokens, relationship filter, rich text, shared text patterns, ...
  styles/app.css     # one stylesheet, custom properties, light and dark
  tests/             # smoke.mjs (logic, any time zone), static.mjs (module graph and HTML/CSS cross-check)
tools/
  mcp/               # MCP server exposing the API to agents, with its own test
  gen-iata.py        # regenerates server/data/iata-airports.php from OurAirports
  tz-harness/        # time zone cross-check against independent calendar engines
extension/           # Chrome extension: Google Calendar add-links open in your Better-Cal
scripts/             # deploy.sh, deploy-dev.sh, deploy-lib.sh, vendor.mjs
docs/                # this file, install, api-contract, agent-api, caldav, email-ingest, google-calendar,
                     #   relationships, migration, limits, benchmarking, plugins/ (authoring), design notes, security review
```

## Conventions

- All timestamps are stored UTC in DATETIME columns; events carry `tzid` for display and recurrence math. An all-day event is stored as its dates at UTC midnight with `tzid` UTC, however it arrived. The API exchanges ISO 8601 with a zone offset, and so do logs.
- IDs are BIGINT auto-increment internally; events also keep an iCal `uid` for interop with feeds, CalDAV and Google.
- API: JSON under `/api/v1`. Session cookie auth (`bc_session`, httpOnly, Secure, SameSite=Lax); every non-GET request carries an `X-CSRF` header matching the session's token (returned by `/me`). A second cookie, `bc_device`, only ever sent to `/api/v1/auth`, remembers a browser that has signed in so it can get past the sign-in brake during a distributed attack (`Domain/TrustedDevices`, issue #59). Personal access tokens (`Authorization: Bearer bc_...`, sha256-hashed in `api_tokens`, CSRF-exempt) serve agents and scripts; token management itself is session-only. `docs/api-contract.md` is the contract, `docs/agent-api.md` the agent view, `tools/mcp/` the MCP server over it.
- Errors: `{"error": {"code": "string", "message": "human"}}` with the proper HTTP status. Codes are stable identifiers the client switches on; messages are plain language.
- Recurrence: the RRULE lives on the master event; the API always returns expanded occurrences for the requested window, each with `eventId` and `instanceId` (`eventId:YYYYMMDDTHHMMSSZ`). Any change to one occurrence asks for a scope: `this`, `following` or `all`. Overrides are rows with `recurrence_parent_id`; a user's own overrides survive feed and Google polls.
- Calendars have a `kind` (local, subscribed, plugin), a `provider` (ics or google) and a `role` (mine, opportunities, context). Feed and plugin events are read-only except the person's own marks (attendance, tags, reminders, relationship). Events on a writable Google calendar write through to Google and say so, before and after, because there is no undo across that boundary. `docs/relationships.md` and `docs/google-calendar.md`.
- Frontend `src/ui` is pure: takes data and emits intents through callbacks, never fetches, never reads the store. `src/app` owns state (`store.js`), the API client (`api.js`) and every side effect (`actions.js`).
- Undo: every local mutation records an inverse in `mutations` and shows a toast; `POST /api/v1/undo` reverts the latest, `Activity` lists and reverts by id. Writes that go to Google are journalled as log-only and offer no undo.
- Outbound HTTP (feeds, geocoding, Google, plugins) goes through one `HttpClient` that refuses private, loopback, link-local and cloud-metadata addresses, caps bodies and redirects, and charges every request to a per-run budget. Push goes out through the web-push library instead, and only to the known browser push services (`BETTERCAL_PUSH_EXTRA_HOSTS` adds more).
- Place search: the location dropdown asks a `PlaceProvider` chosen by `BETTERCAL_PLACE_SEARCH` (`PlaceProviders`): Photon by default, free and keyless; LocationIQ or Stadia Maps with their key, each in front of Photon (`FallbackPlaces`), which answers what the keyed service can't and whenever it fails. Each provider keeps its own workarounds; `PlaceSearch` holds the rules for all of them. The single-pin lookup (`Geocode`) stays on Photon and Open-Meteo.
- LLM: `LlmGateway` fronts one provider (Gemini; the model is `BETTERCAL_GEMINI_MODEL`) for quick-add parsing, email extraction, prompt filters, ranking and plugin completions. A deterministic parser handles the common absolute and relative dates; an LLM failure never blocks creating an event.
- Plugins run server-side under `PluginHost` with declared permissions, their own calendars, a request budget and the SSRF-safe client; `docs/plugins/authoring.md`.
- Configuration is environment only, read once in `server/config/config.php` from real variables or the `.env` file at the app root. `.env.example` lists every key with what it is for and is the source of truth; only the database, base URL and session secret are required.
- Secrets at rest (Google refresh tokens) are sealed with libsodium under a key derived from the session secret (`Infra\Secrets`); nothing in the repository holds a credential.
- Tests: `php server/tests/run.php`, `node web/tests/smoke.mjs` (run under several time zones on deploy), `node --experimental-vm-modules web/tests/static.mjs`, `node tools/mcp/test.mjs`. CI runs all four on every push and pull request; the deploy script runs the first three, plus a check of the vendored files against their pins, before shipping.
