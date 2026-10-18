# Better-Cal Agent API

How an agent (Claude Code, Codex, a cron script) drives the calendar. The full endpoint reference is in [api-contract.md](api-contract.md); this doc covers the token-based access path and the MCP server.

## Authentication for agents

Agents authenticate with a personal access token in the `Authorization` header instead of the browser session cookie:

```
Authorization: Bearer bc_<43 url-safe chars>
```

Bearer requests are CSRF-exempt (no cookie is involved, so there is nothing to forge). Most data operations are identical to session auth. Endpoints that need a person signed in with a password answer a bearer token with 403 `session_required`: token management itself (`/api/v1/tokens`, so a leaked token cannot mint or revoke tokens), account recovery (`/auth/step-up`, `/auth/sessions`, `/auth/sign-out-others`), Google account linking, remote calendar discovery and moves, keeping a paused subscription (`POST /calendars/:id/claim-subscription`), and reporting a browser start that never finished (`POST /system/client-event`). Calendar responses redact stored ICS source capabilities except for a subscription that exact token created. A token can delete only the outbound feeds and reminder devices it created.

Tokens are stored sha256-hashed; the plaintext value is shown exactly once at creation. Optional `expires_at` is supported in the schema (NULL = never); tokens created via the API or CLI currently don't expire, so revoke them when done.

An API key remains a broad account key, not a read-only or per-calendar permission. It can edit calendar data, resolve duplicates, enable or disable plugins, configure plugin and calendar settings, save event plugin fields, and queue plugin jobs. Jobs and configuration can have outside effects through an installed plugin. All ordinary settings remain writable except `workerOffAt` and `updateNotifications: "off"`, which need a signed-in browser; `"all"` and `"security"` are allowed. A mixed settings patch containing a forbidden change is refused as a whole.

Browser-only actions also include plugin uninstall and run undo, invitation decisions and replies, proposals, paid-model and mail-limit dismissals, exports, updates and the private-files check. Secret plugin fields are write-only for everyone: responses contain `{set: true}` or `{set: false}`, omission preserves the value, and `null` clears it. System health keeps failure counts and cleaned reasons, but omits cron commands, absolute paths, notification addresses, push endpoints and hashes, and device labels. Cleaning keeps a recognized failure reason and URL host, not the original error text.

Durable channels follow the token too. A public outbound feed or push device created by a token is removed when it is revoked; an inbound ICS subscription created by a token is paused, preserving its cached events for the owner to review. Direct refresh and background polling both stop. The owner can explicitly keep a paused subscription from its calendar settings or Review, after recent password confirmation. An active token-owned feed cannot be detached from its token this way. Revocation is checked again before a notification send, but a message already handed to its provider cannot be recalled.

### Creating a token

From the server shell (simplest for provisioning):

```sh
php server/bin/token.php --create --name="Claude Code"   # prints the token once
php server/bin/token.php --list
php server/bin/token.php --revoke=3
```

Or over HTTP from a logged-in session (session cookie + `X-CSRF` header and password confirmation within the last ten minutes required):

```sh
curl -s https://cal.example.com/api/v1/tokens -X POST \
  -b cookies.txt -H "X-CSRF: $CSRF" -H 'Content-Type: application/json' \
  -d '{"name":"cron-digest"}'
# -> {"id":3,"name":"cron-digest","token":"bc_..."}  (token shown once)

curl -s https://cal.example.com/api/v1/tokens -b cookies.txt          # list: id, name, createdAt, lastUsedAt
curl -s https://cal.example.com/api/v1/tokens/3 -X DELETE -b cookies.txt -H "X-CSRF: $CSRF"
```

## curl examples

Create a private header file outside your repository once. Use a text editor to put `Authorization: Bearer ` followed by your token on its only line. Do not paste the token into a shell command or enable shell tracing. These Bash commands create the file with private permissions before you edit it:

```sh
BC=https://cal.example.com/api/v1
umask 077
AUTH_FILE="$HOME/.bettercal-auth"
(set -C; : > "$AUTH_FILE")
chmod 600 "$AUTH_FILE"
```

Create an event:

```sh
curl -s "$BC/events" -X POST -H "@$AUTH_FILE" -H 'Content-Type: application/json' -d '{
  "calendarId": 1,
  "title": "Dinner with Sam",
  "start": "2026-08-06T19:00:00-07:00",
  "end": "2026-08-06T21:00:00-07:00",
  "location": "Luna Trattoria"
}'
```

Window of expanded occurrences:

```sh
curl -s -H "@$AUTH_FILE" "$BC/events?start=2026-08-01T00:00:00-07:00&end=2026-08-08T00:00:00-07:00&calendars=1,2"
```

Full-text search (past included):

```sh
curl -s -H "@$AUTH_FILE" "$BC/search?q=dinner&limit=20"
```

Natural-language quick add (omit `commit` to preview the parsed draft first):

```sh
curl -s "$BC/quickadd" -X POST -H "@$AUTH_FILE" -H 'Content-Type: application/json' \
  -d '{"text":"Standup tomorrow 9:30am for 15 minutes","tz":"America/Los_Angeles","commit":true}'
```

Preview and commit calls use the same per-account/per-token model budget when AI parsing is needed. At capacity the request still succeeds with the basic parser; `draft.modelLimit` explains the temporary fallback and its retry time.

Undo the last mutation:

```sh
curl -s "$BC/undo" -X POST -H "@$AUTH_FILE"
```

Update / delete (an update to a recurring event needs `scope`, a delete defaults to `all`; `this`/`following` also need `instanceStart`):

```sh
curl -s "$BC/events/42" -X PATCH -H "@$AUTH_FILE" -H 'Content-Type: application/json' \
  -d '{"title":"Dinner (moved)","start":"2026-08-06T20:00:00-07:00","end":"2026-08-06T22:00:00-07:00","scope":"this","instanceStart":"2026-08-06T19:00:00-07:00"}'

curl -s "$BC/events/42" -X DELETE -H "@$AUTH_FILE" -H 'Content-Type: application/json' -d '{"scope":"all"}'
```

## MCP setup

`tools/mcp/server.mjs` is a zero-dependency Node (>= 20) MCP server over stdio that wraps the endpoints above. See [tools/mcp/README.md](../tools/mcp/README.md) for the tool list and `.mcp.json` snippet.

Use the secret-free configuration in that guide. The Node process reads `BETTERCAL_TOKEN` from its environment itself. Do not pass a token with a command-line `--env` option: shell expansion still puts the value in process arguments.

## Capabilities map

| Capability | REST | MCP tool | Notes |
|---|---|---|---|
| Read events in a window | `GET /events?start=&end=` | `list_events` | Recurring events pre-expanded; at most two years per request, and a series past 1,000 occurrences in the window is named in `capped` ([limits.md](limits.md)) |
| Search all events | `GET /search?q=` | `search_events` | FULLTEXT, past included |
| Create event | `POST /events` | `create_event` | Supports `rrule` for recurrence |
| NL quick add | `POST /quickadd` | `quick_add` | Draft by default; `commit:true` creates |
| Edit event | `PATCH /events/:id` | `update_event` | `scope` required when recurring |
| Delete event | `DELETE /events/:id` | `delete_event` | `scope`/`instanceStart` for instances |
| Attendance (going / interested / hide) | `POST /events/:id/attendance` | `set_attendance` | Works on read-only feed events; the MCP tool sets the whole series |
| Review queue (held invitation changes, unanswered invitations, plugin proposals, possible duplicates) | `GET /review` | `list_review` | Tokens receive duplicate actions only. Invitations, dismissals, replies and proposals need a browser. |
| Plugins and health | `GET /plugins`, `GET /system/health` | `list_plugins`, `get_system_health` | Secret fields report only set/unset; health hides private host and destination details |
| Configure plugins | `PATCH /plugins/:id/settings`, `PATCH /plugins/:id/calendar-settings/:calendarId`, `PATCH /events/:id/plugin-data/:pluginId` | `update_plugin_settings`, `update_plugin_calendar_settings`, `update_event_plugin_data` | Omit a secret to keep it, send null to clear; configuration can change outside behavior |
| Enable or run plugins | `POST /plugins/:id/enable`, `/disable`, `/run` | `set_plugin_enabled`, `run_plugin` | Can have outside effects; runs are queued for the worker |
| Decide duplicates | `POST /duplicates/:id` | `resolve_duplicate` | `linked` or `dismissed` |
| List calendars | `GET /calendars` | `list_calendars` | Includes folders, tags and feed health; stored ICS source capabilities are redacted unless this token created the subscription |
| Undo last change | `POST /undo` | `undo` | Per user; each call undoes the most recent create, update or delete not yet undone, so repeating it steps further back (7-day snapshots); log-only entries are passed over |
| Manage calendars/folders | `POST/PATCH/DELETE /calendars`, `/folders` | — | REST only for now |
| Outbound feeds, push subscriptions, reminder email address | `/outfeeds`, `POST /push/subscribe`, `POST /push/unsubscribe`, `PATCH /settings` | — | REST; anything a token creates belongs to that exact token and goes when the token is revoked. A token cannot list or remove browser-owned reminder devices; `/push/devices` is session-only. A token sees and can delete only the outbound feeds it created. Google account linking and remote calendar discovery need a signed-in person. |
| Manage tokens | `GET/POST/DELETE /tokens` | — | Session auth only, never bearer; creation requires recent password confirmation |

## Cron script sketch

```sh
#!/bin/sh
# Tomorrow's agenda, e.g. piped into a notifier.
BC=https://cal.example.com/api/v1
AUTH_FILE="$HOME/.bettercal-auth"
START=$(date -v+1d +%Y-%m-%dT00:00:00%z)
END=$(date -v+2d +%Y-%m-%dT00:00:00%z)
curl -s -H "@$AUTH_FILE" "$BC/events?start=$START&end=$END"
```

The header file is a long-lived account credential. Keep it outside web roots and repositories, readable only by your account. This avoids process-argument exposure, not access by another process running as you or by the host administrator. Revoke the token and remove the file when it is no longer needed.
