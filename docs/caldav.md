# CalDAV Sync

Better-Cal is a CalDAV server at `https://cal.example.com/dav`, so Apple Calendar (macOS and iOS), Android clients, Thunderbird and the like can sync with it natively, including two-way editing of local calendars.

## Credentials

- **Username:** your Better-Cal account email.
- **Password:** an API key (recommended) or your account password. Create a key per device in Settings, Account, under **API keys**, or on the server with `php server/bin/token.php --create --name="iPhone CalDAV"`, and paste the full `bc_...` value as the password. Revoking that key cuts the device off without changing your real password.

Sign-in is HTTP Basic, so it's only safe over HTTPS. The server doesn't serve `/dav` over plain HTTP; never point a client at an `http://` URL.

## Adding the Account

**iOS:** Settings → Apps → Calendar → Calendar Accounts → Add Account → Other → Add CalDAV Account. Server: `cal.example.com/dav` (if validation fails, use the full URL `https://cal.example.com/dav`).

**macOS Calendar:** Calendar → Settings → Accounts → + → CalDAV Account → Account Type: Advanced. Server address: `cal.example.com`, Server path: `/dav`, port 443 with SSL. (Account Type "Manual" with address `cal.example.com/dav` usually works too.)

**Android:** stock Android has no CalDAV support, so install [DAVx5](https://www.davx5.com/) (Play Store or F-Droid). Add account → "Login with URL and user name" → base URL `https://cal.example.com/dav`. DAVx5 syncs the calendars into the regular Android calendar app.

**Thunderbird:** New Calendar → On the Network → your username and `https://cal.example.com/dav`; it finds all your calendars.

## What Syncs

- Every Better-Cal calendar appears as a CalDAV collection (`/dav/calendars/{email}/cal-{id}/`), with its name, color and order.
- On **local** calendars, events sync both ways: create, edit, move and delete, plus repeat rules (RRULE), skipped dates (EXDATE) and edited single occurrences (RECURRENCE-ID). Fields carried: title, description, location, URL, status, start and end (all-day included), time zone and recurrence.
- Time zones: a timed event goes out in its own zone (`TZID` plus a `VTIMEZONE`); all-day events, and their skipped or changed dates, go out as plain dates. A time a client sends with no zone is read in your Home zone, and a Windows zone name (Outlook's "Pacific Standard Time") becomes the real zone it stands for.
- A rich description goes out as plain text in `DESCRIPTION` and allowlisted HTML (plain formatting and http(s) links) in `X-ALT-DESC`. One written by a client is cleaned the same way when it arrives.
- Changes made in the web app, the API or by agents reach your devices through delta sync (sync-collection and ctag), and so does Undo in the app.
- Subscribed calendars are polled by the server (an ICS feed hourly by default, a connected Google calendar every 5 minutes; set per calendar under "Check for updates"), and their events reach your devices after each poll.

## Read-Only Rules

- **Subscribed calendars (ICS feeds and connected Google calendars) are read-only over CalDAV,** even a Google calendar you can edit in the app. Clients see them as read-only, and a write gets `403 Forbidden`. Edit or hide those events in the app instead.
- Creating or deleting whole calendars over CalDAV isn't supported; do that in the app or API. Renaming a local calendar or changing its color from a client works.
- Better-Cal-only details (tags, people, attendance, filters, scores) don't appear over CalDAV, and they're kept untouched when a client edits an event.

## Troubleshooting

- **401 Unauthorized:** the username must be the exact account email. With an API key, paste the full `bc_...` value (46 characters), and check the key hasn't been revoked or expired.
- **Account validates but no calendars appear:** make sure the URL path is `/dav` (some clients silently drop the path). On macOS use the Advanced account type with server path `/dav`.
- **403 on save:** you edited an event on a subscribed calendar, which is read-only over CalDAV. A 403 that mentions `max-resource-size` means the event itself was too big: one event with its changed occurrences may be at most 1 MiB and 500 overrides (`BETTERCAL_LIMIT_DAV_OBJECT_BYTES`, `BETTERCAL_LIMIT_DAV_OVERRIDES`; clients are told the size limit as `CALDAV:max-resource-size`).
- **429 Too Many Requests:** this network address sent too many wrong passwords (to CalDAV or the sign-in page; they share a limit). Syncing resumes by itself within 15 minutes. It's a 429 rather than a 401 so the client doesn't decide the saved password is wrong.
- **400 "Object URI must be <UID>.ics":** the client saved an event under a filename that doesn't match its UID. Apple Calendar, DAVx5 and Thunderbird always match; if you script against the server, name objects `{uid}.ics`.
- **Sync seems stale:** clients poll on their own schedule; pull to refresh (iOS) or force a sync (DAVx5) to fetch now. Subscribed calendars only change when their server-side poll runs.
- **Checking the endpoint:** `curl -u 'sam@example.com' https://cal.example.com/dav/` prompts for a password; paste the API key at that prompt, not into the command. It should return XML or HTML, not a 404. If the web server doesn't have the `/dav` rule yet, `https://cal.example.com/dav.php/` works as a fallback.
