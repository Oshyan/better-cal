// Undo toasts: shown after every mutation; Undo undoes the entries that
// change made (undoEntries) and refreshes what they touched.

import { html, useRef, useLayoutEffect, useEffect } from '../../vendor/index.js';
import { useStore, dismissToast } from './store.js';
import { undoEntries } from './api.js';
import { Icon } from '../ui/icons.js';

// A readable measure for a toast's message, in its own ems.
const READABLE_EM = 12;

/**
 * The one layout rule for every toast: the message and its choices share a
 * line where the message, beside them, keeps a readable measure (READABLE_EM,
 * or its own width when that is shorter); otherwise the choices take a row
 * of their own under the message, which then has the toast's full width.
 * Read from the toast as it lays out on one line: the width the message is
 * given there, and its width unwrapped (is-measuring, app.css).
 */
function fitToast(el) {
  el.classList.remove('is-stacked');
  const text = el.querySelector('.bc-toast-text');
  if (!text) return;
  const given = text.getBoundingClientRect().width;
  el.classList.add('is-measuring');
  const natural = text.scrollWidth;
  el.classList.remove('is-measuring');
  const readable = READABLE_EM * parseFloat(getComputedStyle(text).fontSize);
  if (given + 0.5 < Math.min(natural, readable)) el.classList.add('is-stacked');
}

export function Toasts() {
  const toasts = useStore((s) => s.toasts);
  const listRef = useRef(null);
  const fitAll = () => { if (listRef.current) for (const el of listRef.current.querySelectorAll('.bc-toast')) fitToast(el); };
  // Before the toasts paint, and again whenever the window changes size.
  useLayoutEffect(() => { fitAll(); }, [toasts]);
  useEffect(() => {
    window.addEventListener('resize', fitAll);
    return () => window.removeEventListener('resize', fitAll);
  }, []);
  if (!toasts.length) return null;
  return html`<div class="bc-toasts" role="status" aria-live="polite" ref=${listRef}>
    ${toasts.map((t) => html`<div key=${t.id} class="bc-toast${t.error ? ' is-error' : ''}">
      <span class=${'bc-toast-text' + (t.title ? ' has-title' : '')} onClick=${() => dismissToast(t.id)}>${t.title
        ? html`<b class="bc-toast-title">${t.title}</b><span class="bc-toast-what">${t.text}</span>`
        : t.text}${t.kept && html`<span class="bc-toast-kept">${t.kept}</span>`}</span>
      <span class="bc-toast-acts">
        ${t.undoable && html`<button type="button" class="bc-toast-undo" onClick=${async () => { dismissToast(t.id); await undoEntries(t.undo); }}>Undo</button>`}
        ${!t.undoable && t.note && html`<span class="bc-toast-note">${t.note}</span>`}
        ${t.actionLabel && html`<button type="button" class="bc-toast-undo" onClick=${() => { dismissToast(t.id); if (t.onAction) t.onAction(); }}>${t.actionLabel}</button>`}
        ${t.actions && t.actions.map((a) => html`<button key=${a.label} type="button" class="bc-toast-undo" onClick=${() => { dismissToast(t.id); a.run(); }}>${a.label}</button>`)}
        ${t.dismissLabel
          ? html`<button type="button" class="bc-toast-undo bc-toast-keep" onClick=${() => dismissToast(t.id)}>${t.dismissLabel}</button>`
          : html`<button type="button" class="bc-icon-btn bc-toast-x" aria-label="Dismiss" onClick=${() => dismissToast(t.id)}><${Icon} name="close" size=${14} /></button>`}
      </span>
      ${t.duration && html`<i
        class="bc-toast-life" aria-hidden="true" style=${'animation-duration:' + t.duration + 'ms'}
        onAnimationEnd=${() => dismissToast(t.id)}
      ></i>`}
    </div>`)}
  </div>`;
}
