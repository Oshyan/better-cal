# CalDAV Sync

Better-Cal exposes a CalDAV server at `https://cal.oshyan.com/dav`, so Apple Calendar (macOS/iOS), Android clients, Thunderbird, etc. can sync natively — including two-way editing of local calendars.

## Credentials

- **Username:** your Better-Cal account email.
- **Password:** either your account password, or (recommended for devices) a personal access token created in the app or via `php server/bin/token.php --create --name="iPhone CalDAV"`. Paste the full `bc_...` value as the password. Revoking the token cuts that device off without touching your real password.

Auth is HTTP Basic, so it is only safe over TLS. The server is HTTPS-only; never point a client at a plain-http URL.

## Adding the account

**iOS:** Settings → Apps → Calendar → Calendar Accounts → Add Account → Other → Add CalDAV Account. Server: `cal.oshyan.com/dav` (if validation fails, use the full URL `https://cal.oshyan.com/dav`). Username = email, password = token or account password.

**macOS Calendar:** Calendar → Settings → Accounts → + → CalDAV Account → Account Type: Advanced. Server address: `cal.oshyan.com`, Server path: `/dav`, port 443 with SSL. (Account Type "Manual" with address `cal.oshyan.com/dav` usually works too.)

**Android:** stock Android has no CalDAV support; install [DAVx5](https://www.davx5.com/) (Play Store or F-Droid). Add account → "Login with URL and user name" → base URL `https://cal.oshyan.com/dav`, username = email, password = token. DAVx5 syncs the calendars into the regular Android calendar app.

**Thunderbird:** New Calendar → On the Network → enter username and `https://cal.oshyan.com/dav`; it discovers all calendars.

## What syncs

- Every Better-Cal calendar appears as a CalDAV collection (`/dav/calendars/{email}/cal-{id}/`), with its name, color, and order.
- Events sync both ways on **local** calendars: create, edit, move, delete, recurring rules (RRULE), skipped instances (EXDATE), and edited single occurrences (RECURRENCE-ID overrides) all round-trip. Fields carried: title, description, location, URL, status, start/end (all-day supported), timezone, recurrence.
- Changes made via the web app, JSON API, or agents show up on your devices via efficient delta sync (sync-collection/ctag), and undo in the app propagates too.
- Feed subscriptions poll hourly server-side; their events flow out to your devices on the same schedule.

## Read-only rules

- **Subscribed (feed) calendars are read-only.** They are advertised to clients as read-only, and any write attempt is rejected with `403 Forbidden`. Edit or hide those events in the Better-Cal app instead (attendance/tags live only in Better-Cal and do not sync over CalDAV).
- Creating or deleting whole calendars over CalDAV is not supported — do that in the app or API. Renaming a local calendar or changing its color from a client does work.
- Better-Cal-only concepts (tags, people, attendance, filters, scores) do not appear in CalDAV and are preserved untouched when a client edits an event.

## Troubleshooting

- **401 Unauthorized:** username must be the exact account email; if using a token, paste the full `bc_...` value (46 chars). Check the token has not been revoked or expired.
- **Account validates but no calendars appear:** make sure the URL path is `/dav` (some clients silently drop the path). On macOS use the Advanced account type with server path `/dav`.
- **403 on save:** you edited an event on a subscribed (feed) calendar, which is read-only over CalDAV.
- **400 "Object URI must be <UID>.ics":** the client PUT a resource whose filename does not match the VEVENT UID. Mainstream clients (Apple, DAVx5, Thunderbird) always match; if you script against the endpoint, name objects `{uid}.ics`.
- **Sync seems stale:** clients poll; pull-to-refresh (iOS) or force sync (DAVx5) fetches immediately. Server-side feed calendars only update when the hourly poll runs.
- **Plain HTTP:** Basic auth credentials are only sent over TLS; the server does not serve the DAV endpoint over http.
- **Endpoint check:** `curl -u 'you@example.com:bc_...' https://cal.oshyan.com/dav/` should return XML/HTML, not a 404. If nginx has not been configured with the `/dav` location yet, `https://cal.oshyan.com/dav.php/` works as a fallback.
