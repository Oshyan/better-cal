# Design Findings Log

Running log from critical live-review passes (own-taste review, Google Calendar as benchmark). Items move to waves as they are scheduled. Started 2026-07-31.

## Open

- Dim readability floor: dimmed agenda rows at 45% opacity on light theme approach illegibility, especially the green feed color on white. Dim should reduce emphasis, not readability: consider desaturation + reduced contrast with a floor, or collapse dimmed items into a "N dimmed" expander per day. (Wave 3/3.5)
- Empty timegrid days give no affordance hint; fine for mouse users, invisible to newcomers. Low priority.

## Addressed or delegated

- Agenda triage clutter (Wave 3.5): the two boxed glyph clusters collapsed into one quiet segmented group per feed row (star/check/x, thin divider, up/down), borderless until hover with a muted palette and an accent-tinted active state. Revealed on row hover/focus-within on desktop with space reserved so rows never shift; always visible on touch (pointer: coarse) and whenever a triage state is active.
- Quick-add NL-only (Wave 3.5, user-flagged): the bar is now a compact create card. NL input on top, always-visible structured strip below (date, start/end time or all day, calendar), live-filled from the parse with the flash affordance and directly editable; a touched field is only overwritten when a later parse actually changes it. Create builds from the strip (source of truth), Enter creates from anywhere in the card (flushing a pending parse first), Esc cancels, "More options" transfers everything into the editor drawer.
- No sidebar mini-month (Wave 3.5): compact month grid at the top of the desktop sidebar. Weekday initials, today ring, anchor highlight, subtle event dots, prev/next arrows, day click jumps, title click opens the jump popover. Follows the main view's visible month, respects the week start setting, shares its grid math with the jump popover (web/src/lib/minimonth.js), and is not rendered in the mobile drawer.
- Week/day scroll to fixed 7am (Wave 3.5): when the visible range includes today the grid now opens at the now-line minus ~90px; other ranges keep 7am.
- Weak day-view header (Wave 3.5): day view gets a strengthened GCal-style header (weekday label + large day number, larger today pill).
- 12 AM hour label clipped (Wave 3.5): the midnight label now renders, nudged below the all-day lane border instead of half-clipped.
- Hotkey gaps + no cheat sheet (GH #5, Wave 3.5): added n (new event), e (edit from popover/detail), o/Enter (open detail from popover), Delete/Backspace (delete with confirm, local events), [ and ] (prev/next event that day in detail view), and a ? cheat-sheet modal. Bindings live in one table (web/src/app/hotkeys.js) that both keyboard.js and the sheet consume, with smoke-test integrity checks.
- Filters page (sent to Wave 2 mid-flight): naked unlabeled enable checkbox; prompt text rendered as grey debug lozenge with raw metadata string; dense label-less create form; content stranded in full-width void; no designed empty states. Applies to all management pages.
- Editor drawer translucency: was a z-index stacking bug, fixed.
- Month-start cues, chip title priority, mobile dot coherence: earlier waves / Wave 3.
