# Better-Cal Roadmap (issue-driven)

Updated 2026-07-31. Maps GitHub issues and review findings to implementation waves. PRD.md holds the full product spec; this file tracks sequencing.

## Done

- GH #1 Quick jump: `g` hotkey + jump popover (mini month/year picker + deterministic natural language). Shipped in Wave 1b.

## In flight

- Wave 2 (building): Settings page, per-calendar settings (color, poll, groupSimilar, delete), folder management UI, styled Manage section, and GH #6 folder tri-state visibility (All / None / Custom with a remembered custom set, persisted in settings under `folderVisibility`).
- Wave 3 (next): full event detail view with mini-map (vendored Leaflet + OSM), prev/next event navigation within a day (detail view and mobile sheet), holiday/near-duplicate grouping chips (uses the `groupSimilar` calendar flag), unified mobile month rendering, full-width month boundary rule, agenda anchor-awareness.
- Wave 3.5 Google Calendar parity + design-review pass (self-review with taste, then fixes):
  - Quick-add rework: the quick-create surface gains a compact structured strip (date, start/end time, calendar) below the NL input, live-filled by parsing and directly editable, plus "More options" opening the full editor. NL-only input is not parity; the structured reference helps while typing (user feedback).
  - Sidebar mini-month navigator (Google Calendar staple; doubles as always-visible jump).
  - GH #5 hotkeys: fill gaps (structured new event, open detail, delete from popover focus, etc.) and add a `?` shortcut cheat-sheet overlay.
  - Scroll week/day views to now (not fixed 7am) on today; keyboard focus ring audit; empty states; whatever else the critical pass finds.

## Planned next (design docs first, then build)

- GH #2 Container events / event relationships: a lightweight "trip/container" concept (e.g. Hawaii Trip) that other events (flights, conference sessions) attach to. Likely `event_links` table with typed relations expressed in plain UX ("Part of: Hawaii Trip", container detail lists its events, container spans render as a subtle backdrop band). Needs a short design doc before code; naming and primitives matter more than the schema here.
- GH #4 Plugin system: user-authored plugins (single-user, self-hosted, user-set risk tolerance) that can inject calendar items, including fancier/animated ones. Server-side provider API (cron-driven generators writing to plugin-owned calendars) + client-side render hooks for custom chip styling/animation. Weather and Tides ship as the demo plugins (subsumes PRD 5.12 dynamic providers).
- GH #3 AI trip-planning chat: calendar-aware chat mode that knows which calendars block travel (new per-calendar setting "blocking for travel"), can query the weather plugin for location/date-range forecasts and averages, proposes open slots, outputs a downloadable markdown plan, and (with explicit go-ahead) inserts the planned range as a container event (ties into GH #2). Depends on #4 (weather plugin) and benefits from #2 (containers), so sequenced after both.

## Standing quality bar

Live browser evaluation happens as a critical design/UX reviewer, not just spec verification: judge against Google Calendar (the benchmark being improved upon) and general design taste; fix or queue anything that looks or feels below par without waiting for user reports.
