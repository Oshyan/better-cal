# Design: Trips and Event Relationships (GH #2)

Status: draft for review, 2026-07-31. Feedback wanted before implementation.

## The need (from the issue)

"I am going on a trip to Hawaii. I have my flight out and back. I want both of those events to be linked to or somehow contained within the Hawaii Trip event." Same shape: multi-day conferences containing sessions, a wedding weekend containing ceremony/reception/brunch, a project sprint containing milestones.

## Product concept: call it a Trip, model it as a container

The user-facing concept is deliberately NOT "event that contains events" (confusing) but a distinct thing with a plain name. Proposal: **"Trip"** as the primary label with the generic term **"group"** in code, because trips are the motivating case and the word carries the right mental model (a span of days that other things happen inside). A conference is comfortably "a trip" in this model; if that wording grates in testing, the label can become "Plan" without schema change.

What a container is:
- An event row like any other (title, span, description, location, calendar) with `is_container = 1`. It is usually multi-day and all-day, but neither is required.
- Other events can be attached to it. Attachment is a relationship, not ownership: attached events keep their own calendar, times, and lifecycle.

What we deliberately do NOT build now: arbitrary relationship types ("blocks", "depends on"), cross-user links, nesting containers inside containers (one level only; a container cannot be attached to another container). Each cut keeps the UX explainable in one sentence.

## Schema

```sql
ALTER TABLE events ADD COLUMN is_container TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE event_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  container_id BIGINT UNSIGNED NOT NULL,   -- events.id with is_container=1
  event_id BIGINT UNSIGNED NOT NULL,       -- the attached event
  position INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_link (container_id, event_id),
  CONSTRAINT fk_link_container FOREIGN KEY (container_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_link_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

A plain link table (not `parent_event_id` on events) because: an event can later belong to two containers if that proves useful (schema permits, UX can restrict to one initially), links carry ordering, and deleting a container cascades links without touching member events.

## API (additive)

- Occurrences gain `isContainer: boolean` and, for attached events, `containers: [{eventId, title}]` (usually 0 or 1).
- `POST /events` accepts `isContainer: true`.
- `POST /events/:id/links {eventId}` / `DELETE /events/:id/links/:eventId` attach/detach (403 if target is itself a container).
- `GET /events/:id/links` -> attached events as occurrences (containers need their member list in the detail view regardless of the visible window).
- CalDAV: containers export as normal VEVENTs plus `RELATED-TO` properties (standard iCal, `RELTYPE=PARENT`/`CHILD`), so the relationship survives round-trips with clients that preserve unknown properties, degrades harmlessly elsewhere.

## UX

Creation:
- Editor drawer gains a small "This is a trip" toggle (visible when the span is multi-day or all-day; always available under More).
- Fast path: select nothing, create "Hawaii Trip June 1-12" via quick add, open it, toggle trip.

Attachment (three paths, all cheap):
1. In an event's detail view / editor: "Part of trip" selector listing containers whose span overlaps or abuts the event (plus "any trip" search). One tap to attach.
2. In a container's detail view: "Add events" opens a picker of events within its span (multi-select), plus a "New event in this trip" shortcut that pre-fills the editor with the trip's first day and attaches on save.
3. Drag an event chip onto a container's backdrop band (later polish, not v1).

Display:
- The container renders in month/multiweek views as a **backdrop band**: a soft tinted strip along the top of its day span (under normal bars, above the cell background), labeled at its start segment. Not a regular bar fighting for lane space; it reads as context, like a highlighted region.
- Attached events get a small link glyph and a "Part of: Hawaii Trip" line in popover/detail (tap navigates to the trip).
- The trip's detail view lists members chronologically (flights first and last, naturally) with open/detach affordances, and shows the day-by-day span.
- Agenda: a subtle "Hawaii Trip" running label on days inside a trip span (grouped header suffix), skippable in v1.

Deletion semantics: deleting a trip detaches members (they survive); explicit "Delete trip and its events" is a second, clearly-worded option in the confirm.

## Open questions for Oshyan

1. Label: "Trip" everywhere, or a neutral "Plan"/"Group" with Trip as an example? (Recommend Trip.)
2. Should a container's own calendar matter for member display (e.g. tint members with the trip color), or keep members visually on their own calendars? (Recommend: members keep their calendar colors; the band carries the trip color.)
3. Single-container restriction in UX v1 (one trip per event) acceptable? (Recommend yes.)
4. Should trip spans auto-grow when a member falls outside the span (flight lands a day after "trip end")? (Recommend: prompt "Extend trip to include this?" rather than silent growth.)
