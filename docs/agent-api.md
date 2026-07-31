# Better-Cal Agent API

How an agent (Claude Code, Codex, a cron script) drives the calendar. The full endpoint reference is in [api-contract.md](api-contract.md); this doc covers the token-based access path and the MCP server.

## Authentication for agents

Agents authenticate with a personal access token in the `Authorization` header instead of the browser session cookie:

```
Authorization: Bearer bc_<43 url-safe chars>
```

Bearer requests are CSRF-exempt (no cookie is involved, so there is nothing to forge). Everything else about the API is identical to session auth, with one exception: the token management endpoints themselves (`/api/v1/tokens`) refuse bearer auth, so a leaked token cannot mint or revoke tokens.

Tokens are stored sha256-hashed; the plaintext value is shown exactly once at creation. Optional `expires_at` is supported in the schema (NULL = never); tokens created via the API or CLI currently don't expire, so revoke them when done.

### Creating a token

From the server shell (simplest for provisioning):

```sh
php server/bin/token.php --create --name="Claude Code"   # prints the token once
php server/bin/token.php --list
php server/bin/token.php --revoke=3
```

Or over HTTP from a logged-in session (session cookie + `X-CSRF` header required):

```sh
curl -s https://cal.oshyan.com/api/v1/tokens -X POST \
  -b cookies.txt -H "X-CSRF: $CSRF" -H 'Content-Type: application/json' \
  -d '{"name":"cron-digest"}'
# -> {"id":3,"name":"cron-digest","token":"bc_..."}  (token shown once)

curl -s https://cal.oshyan.com/api/v1/tokens -b cookies.txt          # list: id, name, createdAt, lastUsedAt
curl -s https://cal.oshyan.com/api/v1/tokens/3 -X DELETE -b cookies.txt -H "X-CSRF: $CSRF"
```

## curl examples

Set up once:

```sh
BC=https://cal.oshyan.com/api/v1
AUTH="Authorization: Bearer bc_yourtokenhere"
```

Create an event:

```sh
curl -s "$BC/events" -X POST -H "$AUTH" -H 'Content-Type: application/json' -d '{
  "calendarId": 1,
  "title": "Dinner with Sam",
  "start": "2026-08-06T19:00:00-07:00",
  "end": "2026-08-06T21:00:00-07:00",
  "location": "Zuni"
}'
```

Window of expanded occurrences:

```sh
curl -s -H "$AUTH" "$BC/events?start=2026-08-01T00:00:00-07:00&end=2026-08-08T00:00:00-07:00&calendars=1,2"
```

Full-text search (past included):

```sh
curl -s -H "$AUTH" "$BC/search?q=dinner&limit=20"
```

Natural-language quick add (omit `commit` to preview the parsed draft first):

```sh
curl -s "$BC/quickadd" -X POST -H "$AUTH" -H 'Content-Type: application/json' \
  -d '{"text":"Standup tomorrow 9:30am for 15 minutes","tz":"America/Los_Angeles","commit":true}'
```

Undo the last mutation:

```sh
curl -s "$BC/undo" -X POST -H "$AUTH"
```

Update / delete (recurring events need `scope`, and `instanceStart` for `this`/`following`):

```sh
curl -s "$BC/events/42" -X PATCH -H "$AUTH" -H 'Content-Type: application/json' \
  -d '{"title":"Dinner (moved)","start":"2026-08-06T20:00:00-07:00","end":"2026-08-06T22:00:00-07:00","scope":"this","instanceStart":"2026-08-06T19:00:00-07:00"}'

curl -s "$BC/events/42" -X DELETE -H "$AUTH" -H 'Content-Type: application/json' -d '{"scope":"all"}'
```

## MCP setup

`tools/mcp/server.mjs` is a zero-dependency Node (>= 20) MCP server over stdio that wraps the endpoints above. See [tools/mcp/README.md](../tools/mcp/README.md) for the tool list and `.mcp.json` snippet.

```sh
claude mcp add better-cal \
  --env BETTERCAL_URL=https://cal.oshyan.com \
  --env BETTERCAL_TOKEN=bc_yourtokenhere \
  -- node /path/to/better-cal/tools/mcp/server.mjs
```

## Capabilities map

| Capability | REST | MCP tool | Notes |
|---|---|---|---|
| Read events in a window | `GET /events?start=&end=` | `list_events` | Recurring events pre-expanded |
| Search all events | `GET /search?q=` | `search_events` | FULLTEXT, past included |
| Create event | `POST /events` | `create_event` | Supports `rrule` for recurrence |
| NL quick add | `POST /quickadd` | `quick_add` | Draft by default; `commit:true` creates |
| Edit event | `PATCH /events/:id` | `update_event` | `scope` required when recurring |
| Delete event | `DELETE /events/:id` | `delete_event` | `scope`/`instanceStart` for instances |
| RSVP / hide | `POST /events/:id/attendance` | `set_attendance` | Works on read-only feed events |
| List calendars | `GET /calendars` | `list_calendars` | Includes folders, tags, feed health |
| Undo last change | `POST /undo` | `undo` | One level, per user |
| Manage calendars/folders/feeds | `POST/PATCH/DELETE /calendars`, `/folders`, `/outfeeds` | — | REST only for now |
| Manage tokens | `GET/POST/DELETE /tokens` | — | Session auth only, never bearer |

## Cron script sketch

```sh
#!/bin/sh
# Tomorrow's agenda, e.g. piped into a notifier.
BC=https://cal.oshyan.com/api/v1
AUTH="Authorization: Bearer $BETTERCAL_TOKEN"
START=$(date -v+1d +%Y-%m-%dT00:00:00%z)
END=$(date -v+2d +%Y-%m-%dT00:00:00%z)
curl -s -H "$AUTH" "$BC/events?start=$START&end=$END"
```
