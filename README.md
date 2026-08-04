# Better-Cal

A self-hosted calendar built to fully replace Google Calendar: everything GCal does day-to-day, plus an activity log with undo, email-to-event ingest with real RSVP replies, trips and people as first-class objects, natural-language everything, and an agent-native API. Plain PHP + MySQL backend, no-build Preact frontend, CalDAV for native clients.

**[Full feature list → FEATURES.md](FEATURES.md)**

## Stack

- Server: PHP 8.4, MySQL, no framework. CalDAV via sabre/dav. Cron worker for feed polls, mail ingest, reminders, ranking, and retention.
- Web: Preact + HTM served directly (no build step), installable PWA.
- Optional: LLM provider key for natural-language assist, prompt filters, and ranking; MapTiler key for map tiles; SMTP for reminder email and iMIP RSVP replies.

## Docs

- [Architecture](docs/architecture.md)
- [API contract](docs/api-contract.md) and [agent API](docs/agent-api.md)
- [CalDAV](docs/caldav.md)
- [Email ingest](docs/email-ingest.md)
- [Migration from Google Calendar](docs/migration.md)
- [Deployment notes](docs/deployment-notes.md)
- [Roadmap](docs/roadmap.md)

## Development

- Deploy: `./scripts/deploy.sh` (rsync, composer, migrations, smoke check).
- Tests: `php server/tests/run.php` (server, pure PHP) and `node web/tests/smoke.mjs` (frontend).
- Chrome extension (redirects Google Calendar add-links): `extension/`.
