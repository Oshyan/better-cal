// Shared chrome for the management pages (Settings, Organize, Outbound
// feeds, Filters, Saved views): scrollable page with a centered content
// column, a breadcrumb-style "Back to calendar" link above the title, and an
// optional intro note.

import { html } from '../../vendor/index.js';
import { set } from './store.js';
import { Icon } from '../ui/icons.js';

export function PageShell({ title, note, children }) {
  return html`<div class="bc-page">
    <div class="bc-page-col">
      <button type="button" class="bc-page-back" onClick=${() => set({ route: 'calendar' })}>
        <${Icon} name="arrowLeft" size=${13} /><span>Back to calendar</span>
      </button>
      <h1 class="bc-page-title">${title}</h1>
      ${note && html`<p class="bc-page-note">${note}</p>`}
      ${children}
    </div>
  </div>`;
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

// Designed empty state: one sentence of what this is plus an optional action.
export function EmptyState({ text, actionLabel, onAction }) {
  return html`<div class="bc-emptystate">
    <p>${text}</p>
    ${actionLabel && html`<button type="button" class="bc-btn bc-btn-primary" onClick=${onAction}>${actionLabel}</button>`}
  </div>`;
}
