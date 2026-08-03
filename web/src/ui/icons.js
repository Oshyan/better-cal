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
    views: html`<path d="M4.5 2h7v12l-3.5-2.7L4.5 14z" />`,
    folder: html`<path d="M1.8 4.2c0-.6.4-1 1-1h3.4l1.4 1.6h5.6c.6 0 1 .4 1 1v6c0 .6-.4 1-1 1H2.8c-.6 0-1-.4-1-1z" />`,
    mixed: html`<circle cx="8" cy="8" r="5.2" />
      <path d="M8 2.8a5.2 5.2 0 0 1 0 10.4z" fill="currentColor" stroke="none" />`,
    pencil: html`<path d="M9.6 3.6l2.8 2.8M3.2 10l6.9-6.9c.4-.4 1-.4 1.4 0l1.4 1.4c.4.4.4 1 0 1.4L6 12.8l-3.5.7z" />`,
    trash: html`<path d="M2.5 4h11M6.5 4V2.8c0-.4.3-.8.8-.8h1.4c.5 0 .8.4.8.8V4M4 4l.7 9.4c0 .5.4.8.8.8h5c.4 0 .8-.3.8-.8L12 4M6.5 7v4M9.5 7v4" />`,
    arrowLeft: html`<path d="M13.5 8h-10M7.3 3.8L3.1 8l4.2 4.2" />`,
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
