# Better-Cal

**An opinionated calendar that questions the status quo (because the status quo is a page-flipping grid from 1995).**

Built for power users, and inspired by a question: can calendars actually be made better *and* easier to use?

[![tests](https://github.com/Oshyan/better-cal/actions/workflows/tests.yml/badge.svg)](https://github.com/Oshyan/better-cal/actions/workflows/tests.yml)

It covers what I used Google Calendar for day to day, and adds a lot GCal doesn't:

- **Continuous scrolling.** Months and weeks scroll instead of flipping pages.
- **Undo for any change**, from an activity log, for a week afterwards.
- **Trips and people as real objects.** Type "Sam is away next week" and it shows as a band across the calendar.
- **Saved views.** A view and its filters, back in one click.
- **Plugins.** Weather (with air quality) and sunrise and sunset come included and show right in each day's header, no API key needed. A few earlier-stage ones ship too (tides, travel time, simple planners).
- **Filters you write in plain English**, and subscribed calendars treated like an inbox instead of read-only wallpaper.
- **Built for AI agents too.** A REST API and an MCP server, with everything an agent does labeled in the activity log.

Self-hosting is table stakes: plain PHP and MySQL on the server, a no-build Preact app in the browser, and CalDAV for the calendar apps on your phone and desktop.

**[Full feature list → FEATURES.md](FEATURES.md)**

<table>
<tr>
<td align="center"><a href="docs/screenshots/month.webp"><img src="docs/screenshots/month.webp" height="200" alt="Month view: five calendars, repeating and one-off events, a four-day trip, and two people's away and busy times shown across the days they cover"></a></td>
<td align="center"><a href="docs/screenshots/phone-month.webp"><img src="docs/screenshots/phone-month.webp" height="200" alt="The same month on a phone, three days to a row, with the bottom bar for views, search, new, filter and review"></a></td>
<td align="center"><a href="docs/screenshots/command-palette.webp"><img src="docs/screenshots/command-palette.webp" height="200" alt="The command palette: type to find a view, a calendar or an action, or create an event from the text"></a></td>
</tr>
<tr>
<td align="center"><sub>Month view</sub></td>
<td align="center"><sub>Phone</sub></td>
<td align="center"><sub>Command palette</sub></td>
</tr>
</table>

<sub>Click a screenshot for full size. The data is invented; `php server/bin/demo-seed.php` loads the same into a fresh install.</sub>

## What this is, and isn't

I wanted infinite scroll in a calendar. That's really the whole origin story: Google's (and almost everyone else's) month and week views have never had it, and I've always found pagination needlessly jarring and limiting. So I sent Claude (Fable 5) on an overnight, one-shot mission to build a calendar app from scratch (something that used to be notoriously difficult, hence libraries like FullCalendar), with infinite scroll as the headline differentiator. By morning there was a working one. Within a week it had replaced Google Calendar for me, and by then it did quite a few things GCal doesn't (and probably never will): undo for any change, not just the last one, trips and people as real objects, a relationship to every event that tells "what am I doing" apart from "what could I do" and "what's the weather", and feeds that are actually pleasant to live with.

So it's built for one person's daily use, and it runs my real calendar every day. It's single-user by design: one login, one owner, and everyone else reaches it through CalDAV, shared feeds and invitations. It's sized for one person's calendar too, not a team's or a very heavy user's; [Scale and limits](docs/limits.md) has the numbers. I develop it in the open because there's no reason not to, not because it's a product. That means no support commitment, no roadmap promises and no compatibility guarantee between versions (though every update brings its own migrations).

What I focused on:

- **Desktop first, phones close behind.** I mostly use it on a laptop with a keyboard, so that's where the polish started: hotkeys for everything, a command palette, drag and drop that behaves. Phones got a pass of their own from 0.4 to 0.8: an installable app, a split month (dense weeks over a list that scrolls with them), an event sheet you can step through, a full-screen editor, and Back that works everywhere. I use it daily on Android.
- **Questioning defaults.** Wherever Google Calendar does something just because it always has, I asked whether it's actually good. Sometimes it is, and I kept it. Often it isn't, and that's where infinite scroll, the Show filter, context in the day header and the honest "can't be undone" on Google edits come from.
- **Other people's calendars as first-class.** Most of what's on my calendar didn't start with me, so feeds and shared calendars aren't a grudging import.
- **Honesty in the interface.** The app tells you what it did, what it can't undo, and what it's hiding.

It hasn't been tried on an iPhone yet, so if you have one, I'd especially like to hear how it goes. Issues and pull requests are welcome; expect honest answers about what I will and won't take on.

## Security

For a one-person project, taking security seriously means doing the work in public rather than making promises:

- **Three full scans** of the whole codebase so far (Codex Security in August and September 2026, Claude Security on 2026-09-23), with every finding independently verified.
- **Every finding fixed**, deployed and checked on the reference install, and the fix commit names the finding it closes. The 2026-09-23 round found 29 issues (none High or Critical), all fixed the same day.
- **The fixes reviewed too.** Three more passes looked for incomplete fixes, ways around them and anything they broke, and those were fixed as well (0.1.6 to 0.1.8).
- **Published advisories.** The 2026-09-23 findings were published as GitHub security advisories; [SECURITY.md](SECURITY.md#past-advisories) lists each with the affected and fixed versions.
- **Tests** run on every change and before every deploy, including regression tests for each security fix.

[SECURITY.md](SECURITY.md) has the details: the risks I accepted rather than fixed, the trade-offs made on purpose (staying signed in on your own devices, for example, and what to do if one is lost), and how to report a problem privately.

## Versions

Releases are tagged (`v0.1.1`) and listed on GitHub, `CHANGELOG.md` says what changed and why, and Settings shows the version you're running. Until 1.0, minor versions bring features and behavior changes, and patch versions bring fixes.

## License

MIT (see [LICENSE](LICENSE)). Use it, fork it, host it, sell it. If you build something on it, a link back here is appreciated but not required. Bundled third-party software keeps its own licenses, listed in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

## Stack

- **Server:** PHP 8.4+, MySQL 8 or MariaDB 10.6+, no framework. CalDAV via sabre/dav. A cron worker polls feeds, reads mail, sends reminders, ranks events and prunes old data.
- **Web:** Preact and HTM served as-is (no build step), installable as an app.
- **Optional:** an LLM provider key for plain-language help, prompt filters and ranking; a MapTiler key for map tiles; SMTP for reminder email and RSVP replies.

## Docs

- [Installing](docs/install.md): requirements, steps, and web server setup for Nginx and Apache (both tested), on MySQL or MariaDB. Shared hosting should work (the `.htaccess` routing is tested on Apache), but it hasn't been tried on a real shared host yet.
- [Architecture](docs/architecture.md)
- [API contract](docs/api-contract.md) and [agent API](docs/agent-api.md)
- [Calendar roles and event relationships](docs/relationships.md): planned, maybe, available and context, i.e. what a calendar is to you and what that makes its events
- [CalDAV](docs/caldav.md)
- [Email ingest](docs/email-ingest.md)
- [Moving from Google Calendar](docs/migration.md): try it without changing anything at Google, run both for a while, switch fully, or go back
- [Scale and limits](docs/limits.md): who it's sized for, and the limits you might run into
- Roadmap: the [GitHub issues](https://github.com/Oshyan/better-cal/issues). There's no separate roadmap document.

## Configuration

Copy [`.env.example`](.env.example) to `.env` in the app root and fill it in. It lists every setting the server reads and what each is for; only the database, base URL and session secret are required. Create your account with `php server/bin/seed.php --email=...`, which asks for the password (or reads `BETTERCAL_SEED_PASSWORD` when run from a script). Running it again for an existing account resets the password and signs out every browser. After a suspected compromise, add `--revoke-tokens` to revoke all API tokens as well.

## Development

- **Deploy:** `./scripts/deploy.sh` (tests, backup, rsync, composer, migrations, smoke check).
- **Tests:** `php server/tests/run.php` (server, pure PHP), `node web/tests/smoke.mjs` (frontend logic; it passes in any time zone, and the deploy runs it under five), `node --experimental-vm-modules web/tests/static.mjs` (module graph) and `node tools/mcp/test.mjs` (MCP server). GitHub runs all of them on every push, plus a fresh install from the lock file on PHP 8.4, the minimum. For changes to repeating events, time zones or import and export, also run `tools/tz-harness/run.sh`.
- **Chrome extension** (sends Google Calendar "add to calendar" links here instead): `extension/`.
