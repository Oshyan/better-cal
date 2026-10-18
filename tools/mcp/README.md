# Better-Cal MCP server

A zero-dependency MCP server (stdio transport, newline-delimited JSON-RPC 2.0) that exposes the Better-Cal REST API as tools. Requires Node >= 20.

## Configuration

Two environment variables:

- `BETTERCAL_URL`: HTTPS base URL of your deployment, e.g. `https://cal.example.com` (no trailing slash needed). Plain HTTP is accepted only for loopback development addresses; credentials, query strings and fragments are refused so the account token cannot be sent to an unintended origin.
- `BETTERCAL_TOKEN`: a personal access token (`bc_...`). Create one on the server with `php server/bin/token.php --create --name="Claude Code"`, or via `POST /api/v1/tokens` from a logged-in session. The token value is shown once.

**The token is a long-lived credential for the whole account. It never belongs in a file that can be committed.** Keep it in your shell environment (or a secret manager that exports it) and let the MCP config refer to it by name:

```sh
# ~/.zshenv, ~/.profile, or wherever your shell keeps private exports
export BETTERCAL_URL=https://cal.example.com
export BETTERCAL_TOKEN=bc_...
```

## Claude Code setup

Use a secret-free `.mcp.json`. Copy [`mcp.example.json`](mcp.example.json): it names the variables and contains no secret, because Claude Code expands `${VAR}` from the environment when it starts the server. Edit your private shell startup file in an editor to add the exports above, or use a secret manager. Do not type the token into a shell command or pass it with `--env`: even `"$BETTERCAL_TOKEN"` expands into process arguments. Keep private startup files readable only by your account and keep shell tracing off. The Node server reads the environment directly; the token never needs to be an argument.

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
| `list_review` | `status?` | Read Review; tokens receive actions for duplicates only |
| `list_plugins` | none | Plugin schemas and settings; secrets show only set/unset |
| `get_system_health` | none | Worker and failure status with private host and destination details omitted |
| `update_plugin_settings` | `pluginId`, `values` | Save global plugin fields |
| `update_plugin_calendar_settings` | `pluginId`, `calendarId`, `values` | Save calendar-specific plugin fields |
| `set_plugin_enabled` | `pluginId`, `enabled` | Enable or disable an installed plugin |
| `run_plugin` | `pluginId`, `job?` | Queue plugin jobs for the worker |
| `update_event_plugin_data` | `pluginId`, `id`, `values` | Save event-specific plugin fields |
| `resolve_duplicate` | `id`, `status` | Link or dismiss a duplicate pair |
| `undo` | — | Revert the latest mutation |

Datetimes are ISO8601 with offset (`2026-08-01T19:00:00-07:00`). `id` is the numeric `eventId` from an occurrence; `instanceStart` is that occurrence's `start` value.

Plugin mutations are marked as having possible outside effects: changing configuration, enabling a plugin or queueing a run can affect its external services. Secret values are write-only. Omit a secret to preserve it, pass `null` to clear it, and never put a real secret into a saved conversation or example. The API key remains a broad account key; the browser-only exceptions and alert-setting restrictions are listed in [the agent guide](../../docs/agent-api.md#authentication-for-agents).

## Testing

```sh
node tools/mcp/test.mjs
```

The test spawns the server as a child process against an in-process mock HTTP server; it never touches a real deployment.
