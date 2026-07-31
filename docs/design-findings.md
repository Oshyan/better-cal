# Design Findings Log

Running log from critical live-review passes (own-taste review, Google Calendar as benchmark). Items move to waves as they are scheduled. Started 2026-07-31.

## Open

- Agenda triage clutter: every feed row carries seven raw glyph buttons (star/check/x + up/down) in boxed clusters that read as debug UI. Direction: reveal on hover/focus (desktop), collapse into one compact segmented control with proper iconography, always-visible but quieter on touch. (Wave 3.5)
- Dim readability floor: dimmed agenda rows at 45% opacity on light theme approach illegibility, especially the green feed color on white. Dim should reduce emphasis, not readability: consider desaturation + reduced contrast with a floor, or collapse dimmed items into a "N dimmed" expander per day. (Wave 3/3.5)
- Quick-add is NL-only: no structured reference while typing (user-flagged; Google Calendar's quick create shows structured fields). Spec in roadmap Wave 3.5.
- No sidebar mini-month: a Google Calendar staple that doubles as orientation + navigation. (Wave 3.5)
- Week/day views scroll to fixed 7am; Google scrolls to now when viewing today. (Wave 3.5)
- Day view column header ("FRI 31" pill) is redundant with the toolbar's full date and undersized as an anchor; consider a stronger day header or drop it in day view. (Wave 3.5)
- Empty timegrid days give no affordance hint; fine for mouse users, invisible to newcomers. Low priority.
- Hour label column starts visually at 1 AM; 12 AM label clipped by the all-day lane border. Cosmetic. (Wave 3.5)

## Addressed or delegated

- Filters page (sent to Wave 2 mid-flight): naked unlabeled enable checkbox; prompt text rendered as grey debug lozenge with raw metadata string; dense label-less create form; content stranded in full-width void; no designed empty states. Applies to all management pages.
- Editor drawer translucency: was a z-index stacking bug, fixed.
- Month-start cues, chip title priority, mobile dot coherence: earlier waves / Wave 3.
