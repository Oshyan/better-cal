# Better-Cal MCP server

A zero-dependency MCP server (stdio transport, newline-delimited JSON-RPC 2.0) that exposes the Better-Cal REST API as tools. Requires Node >= 20.

## Configuration

Two environment variables:

- `BETTERCAL_URL` — base URL of the deployment, e.g. `https://cal.oshyan.com` (no trailing slash needed).
- `BETTERCAL_TOKEN` — a personal access token (`bc_...`). Create one on the server with `php server/bin/token.php --create --name="Claude Code"`, or via `POST /api/v1/tokens` from a logged-in session. The token value is shown once.

## Claude Code setup

```sh
claude mcp add better-cal \
  --env BETTERCAL_URL=https://cal.oshyan.com \
  --env BETTERCAL_TOKEN=bc_yourtokenhere \
  -- node /path/to/better-cal/tools/mcp/server.mjs
```

Or as a `.mcp.json` snippet (project-level, checked in or local):

```json
{
  "mcpServers": {
    "better-cal": {
      "command": "node",
      "args": ["/path/to/better-cal/tools/mcp/server.mjs"],
      "env": {
        "BETTERCAL_URL": "https://cal.oshyan.com",
        "BETTERCAL_TOKEN": "bc_yourtokenhere"
      }
    }
  }
}
```

## Tools

| Tool | Arguments | Does |
|---|---|---|
| `list_events` | `start`, `end`, `calendars?`, `q?` | Expanded occurrences in a window |
| `search_events` | `q`, `limit?` | Full-text search, past included |
| `create_event` | `calendarId`, `title`, `start`, `end`, `allDay?`, `location?`, `description?`, `rrule?`, `tzid?`, `url?` | Create an event |
| `quick_add` | `text`, `commit?`, `calendarId?`, `tz?` | Natural-language parse; `commit: true` creates |
| `update_event` | `id`, patch fields, `scope?`, `instanceStart?` | Patch an event (scope required when recurring) |
| `delete_event` | `id`, `scope?`, `instanceStart?` | Delete an event / instance(s) |
| `set_attendance` | `id`, `attendance` | none / interested / going / hidden |
| `list_calendars` | — | Calendars, folders, tags |
| `undo` | — | Revert the latest mutation |

Datetimes are ISO8601 with offset (`2026-08-01T19:00:00-07:00`). `id` is the numeric `eventId` from an occurrence; `instanceStart` is that occurrence's `start` value.

## Testing

```sh
node tools/mcp/test.mjs
```

The test spawns the server as a child process against an in-process mock HTTP server; it never touches a real deployment.
