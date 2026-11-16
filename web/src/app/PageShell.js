// Shared chrome for the management pages (Settings, Organize, Outbound
// feeds, Filters, Saved views): scrollable page with a centered content
// column, a breadcrumb-style "Back to calendar" link above the title, and an
// optional intro note.

import { html } from '../../vendor/index.js';
import { set } from './store.js';
import { Icon } from '../ui/icons.js';

// back: {label, onClick} for a page inside a page (a Settings section on a
// phone, 0.8.0); otherwise Back goes to the calendar.
export function PageShell({ title, note, children, back = null }) {
  return html`<div class="bc-page">
    <div class="bc-page-col">
      <button type="button" class="bc-page-back" onClick=${back ? back.onClick : () => set({ route: 'calendar' })}>
        <${Icon} name="arrowLeft" size=${13} /><span>${back ? back.label : 'Back to calendar'}</span>
      </button>
      <h1 class="bc-page-title">${title}</h1>
      ${note && html`<p class="bc-page-note">${note}</p>`}
      ${children}
    </div>
  </div>`;
}

// A page's inline confirm (Delete folder, Delete tag, Merge) as it opens:
// scrolled fully into view, clear of the toasts at the bottom of the screen.
// The page keeps room for them below its content and as its scroll padding
// (.bc-page, --toast-room), so `nearest` stops above them. Pass as the
// confirm's ref: one function, so it runs when the confirm mounts, not on
// every render.
export function revealConfirm(el) {
  if (el && el.scrollIntoView) requestAnimationFrame(() => el.isConnected && el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
}

// Loading placeholder shaped like what is coming: a few shimmering lines of
// varying width. Same wall time as "Loading…", read differently: text says
// "wait", a shape says "almost".
export function Skeleton({ rows = 3, compact = false }) {
  const widths = [72, 88, 60, 80, 66, 90];
  return html`<div class=${'bc-skel' + (compact ? ' is-compact' : '')} aria-busy="true" aria-label="Loading">
    ${Array.from({ length: rows }, (_, i) => html`<div key=${i} class="bc-skel-line" style=${`width:${widths[i % widths.length]}%`}></div>`)}
  </div>`;
}

// An empty view (#214): one line of what it is for, and the starts that
// fill it as small inline links, never a box of its own. `actions` is a
// list of [label, run]; `actionLabel` and `onAction` are one of them.
export function EmptyState({ text, actions = [], actionLabel, onAction }) {
  const all = actionLabel ? [...actions, [actionLabel, onAction]] : actions;
  return html`<div class="bc-emptystate">
    <p>${text}</p>
    ${all.length > 0 && html`<span class="bc-emptystate-acts">
      ${all.map(([label, run]) => html`<button key=${label} type="button" class="bc-link-btn" onClick=${run}>${label}</button>`)}
    </span>`}
  </div>`;
}
