# Better-Cal Architecture Contract

This document is the binding contract between backend and frontend work. Deviations require updating this doc in the same change.

## Stack

- Backend: PHP 8.4 (server runs 8.4; do not use 8.5-only features), no framework. Composer deps: `sabre/vobject` (ICS parse/serialize + RRULE expansion). PDO/MySQL against Percona 8.4.
- Frontend: Preact + HTM loaded as vendored ESM modules (files committed under `web/vendor/`), no build step. ES2022 modules.
- DB: MySQL (Percona 8.4), database `bettercal`. utf8mb4. FULLTEXT for search.
- Deployment: rsync repo to `/home/bettercal/app` on the Hetzner box; `htdocs/cal.oshyan.com` is a symlink to `app/server/public`. `composer install` runs on the server. Cron runs `server/bin/worker.php` every minute as user `bettercal`.

## Repo layout

```
server/
  public/            # nginx docroot: index.php front controller, /assets symlink to ../../web
  src/               # PHP classes, PSR-4 "BetterCal\" via composer autoload
    Http/            # Router, Request, Response, Controllers
    Domain/          # Services: Events, Calendars, Ics, Recurrence, QuickAdd, Search, Undo, Feeds
    Infra/           # Db (PDO wrapper), LlmGateway, Config, JobQueue
  bin/               # worker.php (cron), migrate.php, seed.php
  config/            # config.php reads env / .env file
  migrations/        # NNN_name.sql, applied in order by migrate.php, tracked in schema_migrations
  tests/             # PHPUnit-free plain test scripts runnable via php server/tests/run.php
web/
  index.html         # app shell (served by PHP front controller at /)
  vendor/            # preact, htm, etc. as single-file ESM
  src/ui/            # bettercal-ui library: NO imports from src/app
  src/app/           # app shell: store, api client, components
  src/lib/           # shared utils (dates, colors)
  styles/            # CSS (custom properties, light/dark)
  manifest.webmanifest, sw.js
scripts/
  deploy.sh          # rsync + composer + migrate + smoke check
docs/
```

## Conventions

- All timestamps stored UTC in DATETIME columns; events carry `tzid` for display/recurrence math. API exchanges ISO8601 with offset.
- IDs: BIGINT auto-increment internally; events also keep an iCal `uid` (ULID-style string) for interop.
- API: JSON, base path `/api/v1`. Session cookie auth (`bc_session`, httpOnly, Secure, SameSite=Lax). All non-GET requests require `X-CSRF` header matching the session's csrf token (returned by /me).
- Errors: `{"error": {"code": "string", "message": "human"}}` with proper HTTP status.
- Recurrence: RRULE stored on the master event; the API always returns *expanded occurrences* for the requested window (`occurrence_start`/`occurrence_end` per instance plus `event_id`, `instance_id` = `eventId:startUtc`). Edits accept `scope: this|following|all`.
- Feed events (calendar.kind = subscribed) are read-only except `attendance` and tags.
- Frontend bettercal-ui is pure: takes data + emits intents (callbacks); never fetches. App layer owns state and API.
- Undo: every mutating API call records an inverse patch in `mutations`; `POST /api/v1/undo` reverts the latest for the user.
- LLM: `LlmGateway` with `parse_event(text, now, tz)` and `translate_search(text, now, tz)`; provider `gemini` (model gemini-3.6-flash, key from env `BETTERCAL_GEMINI_API_KEY`). Deterministic fallback parser must handle common absolute/relative datetimes; LLM failure never blocks event creation.
- Config via environment (set in `.env` file at app root on server, loaded by config.php): `BETTERCAL_DB_DSN`, `BETTERCAL_DB_USER`, `BETTERCAL_DB_PASS`, `BETTERCAL_GEMINI_API_KEY`, `BETTERCAL_BASE_URL`, `BETTERCAL_SESSION_SECRET`.

## Milestone scope being built now (M1-M3)

Views: month + multi-week (2/3wk) with smooth infinite vertical scroll (virtualized week rows, month labels in left gutter, drag across boundaries), week, day (overlap side-by-side), agenda (past hidden by default, recency sort option). Day expansion: click day number or overflow chip opens in-place expanded day (lightbox on mobile); drag-out supported later (M4 ok).
Editing: click-drag create, single-click quick create with inline title, drag move, drag either end to resize (incl. multi-day in month view), duration lock in editor, popover edit for title/time/calendar, full editor drawer, undo toasts, keyboard map (c quick-add, / search, e edit, d delete, v cycle views, arrows navigate).
Quick-add: NL input with Gemini parse + preview chips + fallback parser.
Search: FULLTEXT instant search + filters; on-page dynamic filter box that dims non-matching events.
Calendars: CRUD, colors, folders (n:m), tags, sidebar toggles, ICS file import, ICS URL subscribe with hourly polling + health/staleness flags, outbound ICS feeds (all / per calendar / saved search) with token URLs and management page.
Recency: "new" pill on events created <48h; agenda recency filter.
PWA: manifest + service worker (shell cache, network-first API), responsive mobile layout with bottom-sheet event details prioritizing what/when/where, touch drag with long-press.
Auth: single user seeded via seed.php (email + password from args), login page, long-lived session.
