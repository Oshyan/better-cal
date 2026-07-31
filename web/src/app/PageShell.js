// Shared chrome for the management pages (Settings, Outbound feeds, Filters,
// Saved views): scrollable page with a centered content column, a common
// title row with "Back to calendar", and an optional intro note.

import { html } from '../../vendor/index.js';
import { set } from './store.js';

export function PageShell({ title, note, children }) {
  return html`<div class="bc-page">
    <div class="bc-page-col">
      <div class="bc-page-head">
        <h1>${title}</h1>
        <button type="button" class="bc-btn" onClick=${() => set({ route: 'calendar' })}>Back to calendar</button>
      </div>
      ${note && html`<p class="bc-page-note">${note}</p>`}
      ${children}
    </div>
  </div>`;
}

// Designed empty state: one sentence of what this is plus an optional action.
export function EmptyState({ text, actionLabel, onAction }) {
  return html`<div class="bc-emptystate">
    <p>${text}</p>
    ${actionLabel && html`<button type="button" class="bc-btn bc-btn-primary" onClick=${onAction}>${actionLabel}</button>`}
  </div>`;
}
