// Shared inline icons: 16 grid, 1.4px rounded stroke (fills where a
// silhouette reads better at small sizes). Icon carries the named map used
// by the sidebar manage rows, calendar gears, and the organize page, so
// every surface renders the same glyphs.

import { html } from '../../vendor/index.js';

export function ThumbIcon({ dir = 'up', size = 13 }) {
  const d = dir === 'up'
    ? 'M4.8 12.9V7.3l2.9-5c.9 0 1.6.7 1.6 1.6 0 .2 0 .3-.1.5l-.5 1.9h3.5c.9 0 1.6.8 1.4 1.7l-.6 3.5c-.1.7-.7 1.2-1.4 1.2zM4.8 7.3H2.4v5.6h2.4'
    : 'M4.8 3.1v5.6l2.9 5c.9 0 1.6-.7 1.6-1.6 0-.2 0-.3-.1-.5l-.5-1.9h3.5c.9 0 1.6-.8 1.4-1.7l-.6-3.5c-.1-.7-.7-1.2-1.4-1.2zM4.8 8.7H2.4V3.1h2.4';
  return html`<svg
    viewBox="0 0 16 16" width=${size} height=${size} aria-hidden="true"
    fill="none" stroke="currentColor" stroke-width="1.4"
    stroke-linecap="round" stroke-linejoin="round"
  ><path d=${d} /></svg>`;
}

// Calendar color dot, kind-aware: local calendars fill solid; subscribed
// feeds render hollow (2px ring of the calendar color, transparent center)
// with a "Subscribed feed" tooltip. One shared treatment for every surface
// that shows a calendar dot.
export function CalDot({ cal, color }) {
  const c = (cal && cal.color) || color || '#888';
  const feed = cal && cal.kind === 'subscribed';
  return feed
    ? html`<span class="bc-cal-dot is-feed" title="Subscribed feed" style=${`border-color:${c}`}></span>`
    : html`<span class="bc-cal-dot" style=${`background:${c}`}></span>`;
}

// Filled cog silhouette: disc r5 with 8 rectangular teeth to r7.4 and a
// r2.1 center hole. Teeth wind with the disc and the hole winds against it,
// so the default nonzero fill unions the teeth and cuts the hole cleanly.
// Reads unmistakably as a gear down to 13px, unlike a spoked circle which
// scans as a sun or star.
const GEAR_PATH =
  'M13 8A5 5 0 1 0 3 8A5 5 0 1 0 13 8Z' +
  'M12.44 9.19L15.29 9.28L15.29 6.72L12.44 6.81Z' +
  'M10.3 11.98L12.24 14.06L14.06 12.24L11.98 10.3Z' +
  'M6.81 12.44L6.72 15.29L9.28 15.29L9.19 12.44Z' +
  'M4.02 10.3L1.94 12.24L3.76 14.06L5.7 11.98Z' +
  'M3.56 6.81L0.71 6.72L0.71 9.28L3.56 9.19Z' +
  'M5.7 4.02L3.76 1.94L1.94 3.76L4.02 5.7Z' +
  'M9.19 3.56L9.28 0.71L6.72 0.71L6.81 3.56Z' +
  'M11.98 5.7L14.06 3.76L12.24 1.94L10.3 4.02Z' +
  'M10.1 8A2.1 2.1 0 1 1 5.9 8A2.1 2.1 0 1 1 10.1 8Z';

// Bodies are built per call (never hoisted): preact mutates vnodes, so a
// shared constant rendered in two places at once would corrupt the tree.
export function Icon({ name, size = 15 }) {
  const body = {
    settings: html`<path d=${GEAR_PATH} fill="currentColor" stroke="none" />`,
    outfeeds: html`<path d="M3 8.6a4.4 4.4 0 0 1 4.4 4.4M3 4.6a8.4 8.4 0 0 1 8.4 8.4" />
      <circle cx="3.7" cy="12.3" r="1.1" fill="currentColor" stroke="none" />`,
    filters: html`<path d="M2 3h12l-4.6 5.4V13l-2.8-1.5V8.4z" />`,
    // Plus: "new thing here" tools. An SVG (not a text "+") so it shares the
    // gear's optical box and the two align in a row.
    plus: html`<path d="M8 3.4v9.2M3.4 8h9.2" />`,
    // The former emoji/glyph set, redrawn as line art on the same 16px grid
    // and 1.4 stroke as everything else. Emoji carry their own weight, color
    // and metrics, so they never sat on the same optical baseline as the
    // custom icons around them.
    search: html`<circle cx="7" cy="7" r="4.6" /><path d="M10.4 10.4l3.2 3.2" />`,
    // Quick add: a bolt for "fastest way in". The toolbar tints it amber
    // (see .bc-qa-btn) — the one spot of colour that earns attention.
    quickadd: html`<path d="M9.1 1.8L3.6 9.1h3.8l-.5 5.1 5.5-7.3H8.6z" />`,
    close: html`<path d="M4 4l8 8M12 4l-8 8" />`,
    chevronLeft: html`<path d="M10 3.5L5.5 8l4.5 4.5" />`,
    chevronRight: html`<path d="M6 3.5L10.5 8 6 12.5" />`,
    chevronUp: html`<path d="M3.5 10.5L8 6l4.5 4.5" />`,
    // Arrow leaving toward the top-right: external links and "open this".
    arrowUpRight: html`<path d="M5.2 10.8L10.8 5.2M6.4 5.2h4.4v4.4" />`,
    check: html`<path d="M3.2 8.4l3.2 3.2 6.4-7" />`,
    star: html`<path d="M8 2.2l1.8 3.7 4 .6-2.9 2.8.7 4-3.6-1.9-3.6 1.9.7-4L2.2 6.5l4-.6z" />`,
    // Camera body + lens: a location that is really a video call link.
    video: html`<rect x="1.6" y="4.2" width="9" height="7.6" rx="1.4" />
      <path d="M10.6 7.4l3.8-2.2v5.6l-3.8-2.2z" />`,
    warning: html`<path d="M7.1 2.9L1.7 12.3c-.4.7.1 1.5.9 1.5h10.8c.8 0 1.3-.8.9-1.5L8.9 2.9c-.4-.7-1.4-.7-1.8 0z" />
      <path d="M8 6.2v3.1M8 11.4h.01" />`,
    // Two offset pages: a stack of near-duplicate events.
    stack: html`<rect x="2.2" y="4.8" width="8" height="8" rx="1.3" />
      <path d="M5.4 4.8V3.6c0-.7.6-1.3 1.3-1.3h5.5c.7 0 1.3.6 1.3 1.3v5.5c0 .7-.6 1.3-1.3 1.3h-1.2" />`,
    // Hamburger: sidebar toggle.
    menu: html`<path d="M2.5 4.2h11M2.5 8h11M2.5 11.8h11" />`,
    // Small caret for dropdown triggers.
    chevronDown: html`<path d="M3.5 6l4.5 4.5L12.5 6" />`,
    // Brand mark: calendar page with filled header band and a bold check.
    brand: html`<rect x="1.8" y="2.8" width="12.4" height="11.4" rx="2.4" fill="none" stroke-width="1.6" />
      <path d="M1.8 6.4h12.4V5.2c0-1.3-1.1-2.4-2.4-2.4H4.2c-1.3 0-2.4 1.1-2.4 2.4z" fill="currentColor" stroke="none" />
      <path d="M5.2 10.4l2 2 3.6-3.8" fill="none" stroke-width="1.7" />`,
    // Clock face with a counter-clockwise history arrow: the Activity log.
    activity: html`<path d="M2.6 8A5.4 5.4 0 1 0 8 2.6c-1.6 0-3 .7-4 1.8M2.6 2.4v2.4H5" />
      <path d="M8 5.4V8l1.9 1.1" />`,
    views: html`<path d="M4.5 2h7v12l-3.5-2.7L4.5 14z" />`,
    folder: html`<path d="M1.8 4.2c0-.6.4-1 1-1h3.4l1.4 1.6h5.6c.6 0 1 .4 1 1v6c0 .6-.4 1-1 1H2.8c-.6 0-1-.4-1-1z" />`,
    mixed: html`<circle cx="8" cy="8" r="5.2" />
      <path d="M8 2.8a5.2 5.2 0 0 1 0 10.4z" fill="currentColor" stroke="none" />`,
    // Visibility modes: all (filled), none (struck through).
    visAll: html`<circle cx="8" cy="8" r="5.2" fill="currentColor" stroke="none" />`,
    visNone: html`<circle cx="8" cy="8" r="5.2" />
      <path d="M4.3 11.7l7.4-7.4" />`,
    // Reschedule: a full-size calendar with the move arrow INSIDE its body.
    // An arrow alongside the page collided with the calendar's own bottom
    // border at 14px and turned to mush; inside, it sits in clear space and
    // the arrow carries its own slightly heavier stroke. Deliberately not a
    // clock-with-arrow (that reads as history, and is the Activity mark) nor
    // plain move arrows (which mean drag-anything).
    reschedule: html`<rect x="1.7" y="3" width="12.6" height="11.3" rx="1.8" />
      <path d="M1.7 6.4h12.6M5 1.7v2.6M11 1.7v2.6" />
      <path d="M4.9 10.4h5.9M8.7 8.1l2.5 2.3-2.5 2.3" stroke-width="1.6" />`,
    pencil: html`<path d="M9.6 3.6l2.8 2.8M3.2 10l6.9-6.9c.4-.4 1-.4 1.4 0l1.4 1.4c.4.4.4 1 0 1.4L6 12.8l-3.5.7z" />`,
    trash: html`<path d="M2.5 4h11M6.5 4V2.8c0-.4.3-.8.8-.8h1.4c.5 0 .8.4.8.8V4M4 4l.7 9.4c0 .5.4.8.8.8h5c.4 0 .8-.3.8-.8L12 4M6.5 7v4M9.5 7v4" />`,
    arrowLeft: html`<path d="M13.5 8h-10M7.3 3.8L3.1 8l4.2 4.2" />`,
    // Note: page with folded corner + text lines. Person note indicator.
    note: html`<path d="M3 2.6h7.4L13 5.2v8.2c0 .6-.4 1-1 1H3c-.6 0-1-.4-1-1V3.6c0-.6.4-1 1-1z" transform="translate(0.5 0)" />
      <path d="M10.4 2.6v2.6H13M5 7.4h5.4M5 9.8h5.4M5 12h3.4" transform="translate(0.5 0)" />`,
    // Keyboard: body + key dots + spacebar. Toolbar shortcuts button.
    keyboard: html`<rect x="1.6" y="4.2" width="12.8" height="7.6" rx="1.4" />
      <path d="M4 6.6h.01M6.7 6.6h.01M9.4 6.6h.01M12.1 6.6h.01M4 9.4h.6M11.4 9.4h.6M6.4 9.4h3.2" />`,
    // Expand (popover -> full detail): arrows pushing out to opposite
    // corners. A single arrow leaving a box reads as "open in a new window";
    // two opposed arrows read as "make this bigger". The box is dropped on
    // purpose — enclosing the diagonals crowds them at button size.
    expand: html`<path d="M9.6 2.4h4v4M13.6 2.4L9.5 6.5" />
      <path d="M6.4 13.6h-4v-4M2.4 13.6l4.1-4.1" />`,
    // Bell: reminders line.
    bell: html`<path d="M8 2.3c-2.1 0-3.5 1.5-3.5 3.7 0 2.7-.9 3.6-1.6 4.3h10.2c-.7-.7-1.6-1.6-1.6-4.3 0-2.2-1.4-3.7-3.5-3.7z" />
      <path d="M6.7 12.5a1.4 1.4 0 0 0 2.6 0" />`,
    // Shortened list beside a tick: "show only the checked rows". A list that
    // visibly loses its last line says compaction; the tick says which rows
    // survive. Deliberately not the filter funnel — that means the Filters
    // feature, which is a different thing sitting a few rows below it.
    activeOnly: html`<path d="M2.4 4.4h6.4M2.4 8h6.4M2.4 11.6h3.4" />
      <path d="M9.6 11.4l1.6 1.7 3-3.4" />`,
    // Padlock, closed and open: the duration lock in the editor. The shackle
    // is the only thing that changes between the two, so the toggle reads as
    // one object in two states rather than two different glyphs.
    lock: html`<rect x="3.2" y="7" width="9.6" height="7" rx="1.5" />
      <path d="M5.6 7V5.2a2.4 2.4 0 0 1 4.8 0V7" />`,
    unlock: html`<rect x="3.2" y="7" width="9.6" height="7" rx="1.5" />
      <path d="M5.6 7V5.2a2.4 2.4 0 0 1 4.8 0" />`,
    // Small calendar page: binding rings + top rule. Marks calendar names.
    calendar: html`<rect x="2.2" y="3.4" width="11.6" height="10.4" rx="1.4" />
      <path d="M2.2 6.6h11.6M5.4 1.8v3M10.6 1.8v3" />`,
    // Calendar page with a filled dot: "go to today". The dot is the mark;
    // rings are dropped so it stays clean at palette size (14px).
    today: html`<rect x="2.2" y="3.4" width="11.6" height="10.4" rx="1.4" />
      <path d="M2.2 6.6h11.6" />
      <circle cx="8" cy="10.2" r="1.5" fill="currentColor" stroke="none" />`,
    // View glyphs: one outer page, different internal divisions. Month is a
    // grid, week is columns, the multi-week strips are row bands, day is a
    // single tall column, agenda is a dotted list. Same family on purpose so
    // the palette's Views group reads as variations of one object.
    viewMonth: html`<rect x="2.2" y="3" width="11.6" height="11" rx="1.4" />
      <path d="M6.1 3v11M9.9 3v11M2.2 8.5h11.6" />`,
    viewWeek: html`<rect x="2.2" y="3" width="11.6" height="11" rx="1.4" />
      <path d="M6.1 3v11M9.9 3v11" />`,
    viewWeeks3: html`<rect x="2.2" y="3" width="11.6" height="11" rx="1.4" />
      <path d="M2.2 6.7h11.6M2.2 10.3h11.6" />`,
    viewWeeks2: html`<rect x="2.2" y="3" width="11.6" height="11" rx="1.4" />
      <path d="M2.2 8.5h11.6" />`,
    viewDay: html`<rect x="5.2" y="2.6" width="5.6" height="11.2" rx="1.2" />
      <path d="M5.2 5.4h5.6" />`,
    viewAgenda: html`<path d="M5.6 4.2h8M5.6 8h8M5.6 11.8h8" />
      <path d="M2.5 4.2h.01M2.5 8h.01M2.5 11.8h.01" />`,
    // Two heads-and-shoulders for the People page.
    people: html`<circle cx="5.6" cy="5.6" r="2.3" />
      <path d="M1.8 13.2c0-2.1 1.7-3.8 3.8-3.8s3.8 1.7 3.8 3.8" />
      <path d="M10.8 3.5a2.3 2.3 0 0 1 0 4.3M11.4 9.6c1.7.4 2.9 1.9 2.9 3.6" />`,
    // Suitcase: handle, body, two strap seams. Marks trip containers.
    trip: html`<rect x="2.8" y="4.8" width="10.4" height="8.8" rx="1.6" />
      <path d="M6.2 4.8V3.6c0-.6.4-1 1-1h1.6c.6 0 1 .4 1 1v1.2M5.6 4.8v8.8M10.4 4.8v8.8" />`,
  }[name === 'organize' ? 'folder' : name];
  return html`<svg
    viewBox="0 0 16 16" width=${size} height=${size} aria-hidden="true"
    fill="none" stroke="currentColor" stroke-width="1.4"
    stroke-linecap="round" stroke-linejoin="round"
  >${body}</svg>`;
}

// Map pin preceding location text (agenda rows, event popover, detail view):
// teardrop outline with a punched circle, muted via the bc-pin class so the
// address stays the focus. aria-hidden because the text carries the meaning.
export function PinIcon({ size = 12 }) {
  return html`<svg
    class="bc-pin" viewBox="0 0 16 16" width=${size} height=${size} aria-hidden="true"
    fill="none" stroke="currentColor" stroke-width="1.4"
    stroke-linecap="round" stroke-linejoin="round"
  ><path d="M12.7 6.7c0 3.5-4.7 7.6-4.7 7.6S3.3 10.2 3.3 6.7a4.7 4.7 0 0 1 9.4 0z" />
    <circle cx="8" cy="6.7" r="1.9" /></svg>`;
}

// Link glyph for "Part of: <trip>" markers: an SVG like every other UI icon,
// so it inherits the accent color and centers with its text — the colored
// link emoji it replaced sat on a different baseline and clashed with the
// icon set.
export function LinkIcon({ size = 12 }) {
  return html`<svg
    viewBox="0 0 16 16" width=${size} height=${size} aria-hidden="true"
    fill="none" stroke="currentColor" stroke-width="1.5"
    stroke-linecap="round" stroke-linejoin="round"
  ><path d="M6.5 9.5l3-3" />
    <path d="M7.6 4.6l1.1-1.1a2.4 2.4 0 0 1 3.4 3.4l-1.1 1.1" />
    <path d="M8.4 11.4l-1.1 1.1a2.4 2.4 0 0 1-3.4-3.4l1.1-1.1" /></svg>`;
}

// Trip badge: suitcase glyph plus label in one quiet accent-tinted pill (the
// accent, not the calendar color, so it reads as a container marker). One
// shared treatment for agenda boundary rows, the trip detail header, and any
// other surface that marks a container.
export function TripBadge() {
  return html`<span class="bc-badge bc-trip-badge"><${Icon} name="trip" size=${11} />Trip</span>`;
}
