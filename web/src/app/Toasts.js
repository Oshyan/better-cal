// Undo toasts: shown after every mutation, Undo calls POST /undo and
// refreshes the affected window.

import { html } from '../../vendor/index.js';
import { useStore, dismissToast } from './store.js';
import { undo } from './api.js';

export function Toasts() {
  const toasts = useStore((s) => s.toasts);
  if (!toasts.length) return null;
  return html`<div class="bc-toasts" role="status" aria-live="polite">
    ${toasts.map((t) => html`<div key=${t.id} class="bc-toast${t.error ? ' is-error' : ''}">
      <span>${t.text}</span>
      ${t.undoable && html`<button type="button" class="bc-toast-undo" onClick=${async () => { dismissToast(t.id); await undo(); }}>Undo</button>`}
      <button type="button" class="bc-icon-btn" aria-label="Dismiss" onClick=${() => dismissToast(t.id)}>✕</button>
    </div>`)}
  </div>`;
}
