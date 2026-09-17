# Better-Cal MCP server

A zero-dependency MCP server (stdio transport, newline-delimited JSON-RPC 2.0) that exposes the Better-Cal REST API as tools. Requires Node >= 20.

## Configuration

Two environment variables:

- `BETTERCAL_URL`: base URL of your deployment, e.g. `https://cal.example.com` (no trailing slash needed).
- `BETTERCAL_TOKEN`: a personal access token (`bc_...`). Create one on the server with `php server/bin/token.php --create --name="Claude Code"`, or via `POST /api/v1/tokens` from a logged-in session. The token value is shown once.

**The token is a long-lived credential for the whole account. It never belongs in a file that can be committed.** Keep it in your shell environment (or a secret manager that exports it) and let the MCP config refer to it by name:

```sh
# ~/.zshenv, ~/.profile, or wherever your shell keeps private exports
export BETTERCAL_URL=https://cal.example.com
export BETTERCAL_TOKEN=bc_...
```

## Claude Code setup

```sh
claude mcp add better-cal \
  --env BETTERCAL_URL="$BETTERCAL_URL" \
  --env BETTERCAL_TOKEN="$BETTERCAL_TOKEN" \
  -- node /path/to/better-cal/tools/mcp/server.mjs
```

This writes to your personal Claude Code config, outside any repository. Passing `"$BETTERCAL_TOKEN"` rather than the literal value keeps the token out of your shell history.

Or as a project-level `.mcp.json`. Copy [`mcp.example.json`](mcp.example.json): it names the variables and contains no secret, because Claude Code expands `${VAR}` from the environment when it starts the server.

```json
{
  "mcpServers": {
    "better-cal": {
      "command": "node",
      "args": ["/path/to/better-cal/tools/mcp/server.mjs"],
      "env": {
        "BETTERCAL_URL": "${BETTERCAL_URL}",
        "BETTERCAL_TOKEN": "${BETTERCAL_TOKEN}"
      }
    }
  }
}
```

Do not paste the token itself into `.mcp.json`. This repository ignores `.mcp.json` as a backstop, but a copy in another project is only as private as that project. If a token does leak, revoke it (`php server/bin/token.php --list`, then `--revoke=ID`, or `DELETE /api/v1/tokens/:id` from a logged-in session) and mint a new one.

## Scope of the tools

Every call's arguments are validated against the tool's schema before any request is made: ids must be positive integers, undeclared fields are refused, and the event tools can only ever address `/events/...`. The token itself is still account-wide on the REST API, so treat whoever can run this server as able to act as you.

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
