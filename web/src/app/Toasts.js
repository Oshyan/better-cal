// Undo toasts: shown after every mutation, Undo calls POST /undo and
// refreshes the affected window.

import { html } from '../../vendor/index.js';
import { useStore, dismissToast } from './store.js';
import { undo } from './api.js';
import { Icon } from '../ui/icons.js';

export function Toasts() {
  const toasts = useStore((s) => s.toasts);
  if (!toasts.length) return null;
  return html`<div class="bc-toasts" role="status" aria-live="polite">
    ${toasts.map((t) => html`<div key=${t.id} class="bc-toast${t.error ? ' is-error' : ''}">
      <span>${t.text}</span>
      ${t.undoable && html`<button type="button" class="bc-toast-undo" onClick=${async () => { dismissToast(t.id); await undo(); }}>Undo</button>`}
      ${t.actionLabel && html`<button type="button" class="bc-toast-undo" onClick=${() => { dismissToast(t.id); if (t.onAction) t.onAction(); }}>${t.actionLabel}</button>`}
      ${t.dismissLabel
        ? html`<button type="button" class="bc-toast-undo bc-toast-keep" onClick=${() => dismissToast(t.id)}>${t.dismissLabel}</button>`
        : html`<button type="button" class="bc-icon-btn" aria-label="Dismiss" onClick=${() => dismissToast(t.id)}><${Icon} name="close" size=${13} /></button>`}
    </div>`)}
  </div>`;
}
