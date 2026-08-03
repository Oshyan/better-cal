# Person availability — approved design decisions

Status: designed, not yet built. Decisions below were confirmed by Oshyan (2026-08-03); implementation is queued behind (or alongside) the plugin system per prioritization at build time. The `availability` table (001_init: person_id, start_utc, end_utc, kind away|busy, note) has been dormant since day one and is the storage this feature activates.

## 1. Semantics: both kinds, "away" is primary

Away spans are the primary use case (Oshyan consults an "away" note before inviting people to events today). Busy is also supported, distinguished visually — color/iconography/pill status, not separate features. The schema's `kind ENUM('away','busy')` already models this. If UI budget forces a choice at any layer, away wins.

Rendering direction: person spans render like quiet trip-style backdrop bands on the calendar (opt-in per person, see §3), always visible on the person's card in the People page, and reflected as status where the person's name appears (e.g. a small "away" pill on people rows).

## 2. Entry: manual + natural language, command palette later

- Manual: add/edit spans on the person's card in the People page (date range, kind, note).
- NL: quick-add-style parsing — "John is away X to Y" — deterministic-first like event quick add (reuse FallbackParser date-range machinery; "PERSON is away/busy/gone/traveling/out DATE-RANGE" patterns), LLM assist behind the same merge-guard.
- Future (separate feature, don't block on it): an all-purpose command palette on a hotkey. Two interaction styles both wanted: (a) quick-search a dedicated action ("Person away") then fill a mini-form, and (b) free-text parse of the whole command. Availability entry becomes one of its actions; design the availability API so the palette can call it cleanly (`POST /people/:id/availability`, plus a text-parse endpoint).

## 3. People as a virtual sidebar folder

- A "People" folder in the left sidebar, sibling to the calendar folders, collapsible ("All calendars" should also become collapsible, noted as part of this work).
- Per-person visibility checkbox (controls whether that person's availability bands render on the calendar) and a per-person "solo" affordance matching the calendar rows' pattern.
- Visibility state persists into that person's settings (server-side, not local-only), so it round-trips to Person Settings in the People page.
- A person who is **currently away** renders slightly dimmed in this sidebar folder (at-a-glance who's around).

## 4. Scheduling assist

When an event is being created/edited "with" a person during one of their away/busy spans, warn inline in the editor ("Sam is away then"), non-blocking. This is the payoff feature. Also applies to quick add (a note in the parsed strip/draft).

## API sketch (to finalize at build time)

- `GET /people/:id/availability` → spans; included in `GET /people` list payload (current + next span for status pills/dimming).
- `POST /people/:id/availability` `{start, end, kind, note?}`; `PATCH/DELETE /people/:id/availability/:spanId`.
- Person settings: `PATCH /people/:id` grows `{showOnCalendar?: bool}`.
- Occurrence-window endpoint gains (or a parallel endpoint provides) visible people's spans for band rendering.
