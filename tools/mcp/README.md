# Better-Cal MCP Server

A zero-dependency MCP server (stdio, newline-delimited JSON-RPC 2.0) that exposes the Better-Cal REST API as tools. It needs Node 20 or later. For what a token can and can't do, see [the agent guide](../../docs/agent-api.md#authentication-for-agents).

## Configuration

The server reads two environment variables:

- `BETTERCAL_URL`: the HTTPS base URL of your install, e.g. `https://cal.example.com` (a trailing slash is fine). Plain HTTP is accepted only for loopback development addresses, and a URL with credentials, a query string or a fragment is refused, so the token can't go to an unintended origin.
- `BETTERCAL_TOKEN`: a personal access token (`bc_...`), shown once when created. Create one with `php server/bin/token.php --create --name="Claude Code"` on the server; the agent guide covers the other ways to create, list and revoke tokens.

**The token is a long-lived credential for the whole account, and it never belongs in a file that can be committed.** Keep it in your shell environment (or a secret manager that exports it), and let the MCP config refer to it by name:

```sh
# ~/.zshenv, ~/.profile, or wherever your shell keeps private exports
export BETTERCAL_URL=https://cal.example.com
export BETTERCAL_TOKEN=bc_...
```

Add those exports with a text editor, not by typing the token into a shell command. Keep private startup files readable only by your account, and keep shell tracing off.

## Claude Code Setup

Copy [`mcp.example.json`](mcp.example.json) into your `.mcp.json`. It names the variables and holds no secret, because Claude Code expands `${VAR}` from the environment when it starts the server:

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

- Don't paste the token into `.mcp.json`. This repository ignores `.mcp.json` as a backstop, but a copy in another project is only as private as that project.
- Don't pass the token with `--env`: even `"$BETTERCAL_TOKEN"` expands into process arguments. The server reads its environment directly, so the token never needs to be an argument.
- If a token leaks, revoke it (`php server/bin/token.php --list`, then `--revoke=ID`) and create a new one.

## Tools

Every call's arguments are checked against the tool's schema before any request: ids must be positive integers, undeclared fields are refused, and the event tools can only reach `/events/...`. The token is still account-wide on the REST API, so treat anyone who can run this server as able to act as you.

| Tool | Arguments | Does |
|---|---|---|
| `list_events` | `start`, `end`, `calendars?`, `q?` | Expanded occurrences in a window |
| `search_events` | `q`, `limit?` | Full-text search, past included |
| `create_event` | `calendarId`, `title`, `start`, `end`, `allDay?`, `location?`, `description?`, `rrule?`, `tzid?`, `url?` | Create an event |
| `quick_add` | `text`, `commit?`, `calendarId?`, `tz?` | Natural-language parse; `commit: true` creates |
| `update_event` | `id`, patch fields, `scope?`, `instanceStart?` | Patch an event (`scope` required when recurring) |
| `delete_event` | `id`, `scope?`, `instanceStart?` | Delete an event or instances |
| `set_attendance` | `id`, `attendance` | `none`, `interested`, `going` or `hidden` |
| `list_calendars` | none | Calendars, folders, tags |
| `list_review` | `status?` | Read Review; tokens get actions for duplicates only |
| `list_plugins` | none | Plugin schemas and settings; secrets show only set/unset |
| `get_system_health` | none | Worker and failure status, without private host and destination details |
| `update_plugin_settings` | `pluginId`, `values` | Save global plugin fields |
| `update_plugin_calendar_settings` | `pluginId`, `calendarId`, `values` | Save calendar-specific plugin fields |
| `add_plugin_calendar` | `pluginId`, `settings`, `name?`, `color?` | Add one of a plugin's calendars (a tide station, a weather place) |
| `update_plugin_calendar` | `pluginId`, `calendarId`, `values` | Change one of those calendars' own settings |
| `search_plugin_options` | `pluginId`, `field`, `query` | Search a long choice list, such as tide stations |
| `set_plugin_enabled` | `pluginId`, `enabled` | Enable or disable an installed plugin |
| `run_plugin` | `pluginId`, `job?` | Queue plugin jobs for the worker |
| `update_event_plugin_data` | `pluginId`, `id`, `values` | Save event-specific plugin fields |
| `resolve_duplicate` | `id`, `status` | Link or dismiss a duplicate pair |
| `undo` | none | Revert the latest change |

`update_event` patch fields are `title`, `start`, `end`, `allDay`, `location`, `description`, `rrule`, `calendarId`, `tzid` and `url`. Datetimes are ISO 8601 with an offset (`2026-08-01T19:00:00-07:00`). `id` is the numeric `eventId` from an occurrence, and `instanceStart` is that occurrence's `start`.

- Each tool carries MCP annotations, so a client can ask before running one that's destructive or can reach an outside service (event writes that go through to a Google-backed calendar, and plugin settings, enabling and runs, which can affect the plugin's external services).
- Results are labeled as containing third-party text (feed and invitation titles, descriptions, locations, organizer names): data, never instructions.
- Plugin secrets are write-only. Omit one to keep it, pass `null` to clear it, and never put a real secret into a saved conversation or example.

## Testing

```sh
node tools/mcp/test.mjs
```

The test runs the server as a child process against an in-process mock HTTP server; it never touches a real install.
