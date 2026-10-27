# Better-Cal Agent API

How an agent (Claude Code, Codex, a cron script) drives the calendar with an API key. Every endpoint, field and error is in [api-contract.md](api-contract.md); the MCP server is in [tools/mcp](../tools/mcp/README.md).

## Authentication for agents

Send a personal access token in the `Authorization` header instead of the browser's session cookie:

```
Authorization: Bearer bc_<43 url-safe chars>
```

Bearer requests don't need the `X-CSRF` header (no cookie is involved, so there's nothing to forge). Otherwise data operations work the same as in a browser. Something only a signed-in browser may do answers a token with 403 `session_required`.

The token is stored hashed and shown exactly once, when it's created. Tokens don't expire, so revoke one when you're done with it.

### What a key can and can't do

An API key is a broad account key, not a read-only or per-calendar one. That's deliberate: it lets an agent do real work.

**A key can:** read and edit events, calendars, folders, people and availability; search; undo; change settings; decide possible duplicates; enable, disable, configure and run installed plugins, including their per-calendar and per-event fields. Plugin jobs and settings can have effects outside Better-Cal, through what the plugin does.

**A key can't:**

- manage keys, sign-ins or the password: `/tokens`, `/auth/step-up`, `/auth/sessions`, `/auth/sign-out-others`, `/auth/change-password` (so a leaked key can't mint or revoke keys or lock you out).
- connect, disconnect or browse Google accounts, subscribe to their calendars, or move a calendar to Google.
- keep a paused subscription updating (`POST /calendars/:id/claim-subscription`), or list or remove reminder devices (`/push/devices`).
- mark the worker as intentionally off (`POST /system/worker-off`, `workerOffAt`) or turn update notices off (`updateNotifications: "off"`), since those are how you'd notice something wrong. Switching notices between `"all"` and `"security"` is fine. A settings patch that includes a forbidden change is refused whole, with nothing applied.
- uninstall a plugin or undo its run; decide, dismiss or answer invitations; accept, reject or undo proposals; dismiss Review notices (unreadable mail, mail and paid-AI limits); export; check for or dismiss updates; run or read the private-files check; report a stalled browser start (`POST /system/client-event`).
- read a plugin setting marked `secret`. Responses say only `{set: true}` or `{set: false}`; leaving the field out keeps the stored value, and `null` clears it.
- see server details in system health. Keys get the status of each check, failure counts and a cleaned-up reason (a recognized cause and the web host involved, never the original error text), without cron commands, server paths, notification addresses, push endpoints or device names.
- see the source address of a calendar subscription, or the URL of a public feed, that it didn't create, or delete feeds and reminder devices it didn't create.

**When you revoke a key,** what it set up stops with it: public calendar feeds and push devices it created are removed, calendar subscriptions it created are paused (their saved events stay) for you to keep or remove, plugins it turned on or configured are turned off and listed in Review (one click turns them back on), and a reminder email address it set goes back to the account's own. Keeping a subscription is done from its calendar settings or Review, with your password. A notification is checked against the key just before sending, but one already handed to the delivery service can't be recalled. The details are in [api-contract.md](api-contract.md#personal-access-tokens).

### Creating a token

In the app: Settings, Account, API keys. From the server shell (simplest for provisioning):

```sh
php server/bin/token.php --create --name="Claude Code"   # prints the token once
php server/bin/token.php --list
php server/bin/token.php --revoke=3
```

Or over HTTP from a signed-in browser session (the session cookie, the `X-CSRF` header, and a password confirmation in the last ten minutes):

```sh
curl -s https://cal.example.com/api/v1/tokens -X POST \
  -b cookies.txt -H "X-CSRF: $CSRF" -H 'Content-Type: application/json' \
  -d '{"name":"cron-digest"}'
# -> {"id":3,"name":"cron-digest","token":"bc_..."}  (token shown once)

curl -s https://cal.example.com/api/v1/tokens -b cookies.txt          # list: id, name, createdAt, lastUsedAt
curl -s https://cal.example.com/api/v1/tokens/3 -X DELETE -b cookies.txt -H "X-CSRF: $CSRF"
```

## curl examples

Keep the token in a private header file outside any repository, so it never appears in a shell command or process list. Create the file with private permissions, then open it in a text editor and put `Authorization: Bearer ` followed by your token on its only line. Don't paste the token into a shell command or turn on shell tracing.

```sh
BC=https://cal.example.com/api/v1
umask 077
AUTH_FILE="$HOME/.bettercal-auth"
(set -C; : > "$AUTH_FILE")
chmod 600 "$AUTH_FILE"
```

The file is a long-lived credential for the whole account. Keep it out of web roots and repositories, readable only by your account. That keeps it out of process arguments, not away from other processes running as you or from the host's administrator. Revoke the token and delete the file when you no longer need them.

Create an event:

```sh
curl -s "$BC/events" -X POST -H "@$AUTH_FILE" -H 'Content-Type: application/json' -d '{
  "calendarId": 1,
  "title": "Dinner with Sam",
  "start": "2026-08-06T19:00:00-07:00",
  "end": "2026-08-06T21:00:00-07:00",
  "location": "Example Trattoria"
}'
```

Expanded occurrences in a window:

```sh
curl -s -H "@$AUTH_FILE" "$BC/events?start=2026-08-01T00:00:00-07:00&end=2026-08-08T00:00:00-07:00&calendars=1,2"
```

Full-text search (past included):

```sh
curl -s -H "@$AUTH_FILE" "$BC/search?q=dinner&limit=20"
```

Natural-language quick add (leave out `commit` to preview the parsed draft first):

```sh
curl -s "$BC/quickadd" -X POST -H "@$AUTH_FILE" -H 'Content-Type: application/json' \
  -d '{"text":"Standup tomorrow 9:30am for 15 minutes","tz":"America/Los_Angeles","commit":true}'
```

When the AI model's budget is used up, quick add still answers with the basic parser, and `draft.modelLimit` says when the model is available again.

Undo the last change:

```sh
curl -s "$BC/undo" -X POST -H "@$AUTH_FILE"
```

Update and delete (updating a recurring event needs `scope`, a delete defaults to `all`, and `this` or `following` also need `instanceStart`, the occurrence's `start`):

```sh
curl -s "$BC/events/42" -X PATCH -H "@$AUTH_FILE" -H 'Content-Type: application/json' \
  -d '{"title":"Dinner (moved)","start":"2026-08-06T20:00:00-07:00","end":"2026-08-06T22:00:00-07:00","scope":"this","instanceStart":"2026-08-06T19:00:00-07:00"}'

curl -s "$BC/events/42" -X DELETE -H "@$AUTH_FILE" -H 'Content-Type: application/json' -d '{"scope":"all"}'
```

## MCP setup

`tools/mcp/server.mjs` is a zero-dependency MCP server (Node 20 or later, over stdio) that wraps these endpoints. Its [README](../tools/mcp/README.md) has the tool list and a secret-free `.mcp.json`, and explains how to keep the token out of config files and command lines.

## Capabilities map

| Capability | REST | MCP tool | Notes |
|---|---|---|---|
| Read events in a window | `GET /events?start=&end=` | `list_events` | Recurring events arrive expanded; at most two years per request, and a series past 1,000 occurrences in the window is named in `capped` ([limits.md](limits.md)) |
| Search all events | `GET /search?q=` | `search_events` | Full text, past included |
| Create event | `POST /events` | `create_event` | `rrule` for repeats |
| Quick add | `POST /quickadd` | `quick_add` | Draft by default; `commit: true` creates |
| Edit event | `PATCH /events/:id` | `update_event` | `scope` required when recurring |
| Delete event | `DELETE /events/:id` | `delete_event` | `scope`/`instanceStart` for occurrences |
| Attendance (going, interested, hide) | `POST /events/:id/attendance` | `set_attendance` | Works on read-only feed events; the MCP tool sets the whole series |
| Review queue | `GET /review` | `list_review` | Keys get actions for possible duplicates only |
| Decide duplicates | `POST /duplicates/:id` | `resolve_duplicate` | `linked` or `dismissed` |
| Plugins and health | `GET /plugins`, `GET /system/health` | `list_plugins`, `get_system_health` | Secrets show only set or unset; health hides server details |
| Configure plugins | `PATCH /plugins/:id/settings`, `PATCH /plugins/:id/calendar-settings/:calendarId`, `PATCH /events/:id/plugin-data/:pluginId` | `update_plugin_settings`, `update_plugin_calendar_settings`, `update_event_plugin_data` | Can change what the plugin does outside Better-Cal |
| Add plugin calendars | `POST /plugins/:id/calendars`, `PATCH /plugins/:id/calendars/:calendarId`, `GET /plugins/:id/options/:field?q=` | `add_plugin_calendar`, `update_plugin_calendar`, `search_plugin_options` | A tide calendar per station, a weather calendar per place; the plugin then fetches for it |
| Enable or run plugins | `POST /plugins/:id/enable`, `/disable`, `/run` | `set_plugin_enabled`, `run_plugin` | Can have outside effects; runs are queued for the worker |
| List calendars | `GET /calendars` | `list_calendars` | Includes folders, tags and feed health |
| Undo | `POST /undo` | `undo` | Each call undoes the most recent change not yet undone, so repeating it steps further back (within 7 days) |
| Manage calendars and folders | `POST/PATCH/DELETE /calendars`, `/folders` | none | REST only |
| Outbound feeds, push devices, reminder email address | `/outfeeds`, `POST /push/subscribe`, `POST /push/unsubscribe`, `PATCH /settings` | none | REST only; what a key creates belongs to it |
| Manage tokens | `GET/POST/DELETE /tokens` | none | Signed-in browser only |

## Cron script sketch

```sh
#!/bin/sh
# Tomorrow's agenda, e.g. piped into a notifier.
BC=https://cal.example.com/api/v1
AUTH_FILE="$HOME/.bettercal-auth"
START=$(date -v+1d +%Y-%m-%dT00:00:00%z)   # GNU date: date -d tomorrow +%Y-%m-%dT00:00:00%z
END=$(date -v+2d +%Y-%m-%dT00:00:00%z)     # GNU date: date -d '2 days' +%Y-%m-%dT00:00:00%z
curl -s -G -H "@$AUTH_FILE" "$BC/events" --data-urlencode "start=$START" --data-urlencode "end=$END"
```
