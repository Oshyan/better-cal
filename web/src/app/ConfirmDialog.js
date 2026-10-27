// The app's one "are you sure?" for things that can't be undone (adopting a
// calendar, sending a reply again, deleting saved events). Where an action
// can be undone it isn't asked at all: the toast's Undo is the better answer
// (drafts.js). The browser's own confirm() put OK first and named neither
// choice; here Cancel has the focus, the confirm button says what it does,
// and Esc or a click outside cancels.
//
//   if (!(await askConfirm({ title, body, confirmLabel, danger }))) return;

import { html, useEffect, useRef } from '../../vendor/index.js';
import { set, useStore } from './store.js';

/** Resolves true when the person confirms, false for Cancel, Esc or a click outside. */
export function askConfirm({ title, body = '', confirmLabel, danger = false }) {
  return new Promise((resolve) => {
    set({ confirmAsk: { title, body, confirmLabel, danger, resolve } });
  });
}

export function ConfirmHost() {
  const ask = useStore((s) => s.confirmAsk);
  const cancelRef = useRef(null);
  useEffect(() => { if (ask && cancelRef.current) cancelRef.current.focus(); }, [ask]);
  if (!ask) return null;
  const finish = (yes) => { set({ confirmAsk: null }); ask.resolve(yes); };
  return html`<div class="bc-stepup-backdrop" onClick=${(e) => { if (e.target === e.currentTarget) finish(false); }}
    onKeyDown=${(e) => { if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); finish(false); } }}>
    <div class="bc-stepup" role="alertdialog" aria-modal="true" aria-labelledby="bc-confirm-title" aria-describedby=${ask.body ? 'bc-confirm-body' : undefined}>
      <h2 id="bc-confirm-title">${ask.title}</h2>
      ${ask.body && html`<p id="bc-confirm-body">${ask.body}</p>`}
      <div class="bc-stepup-actions">
        <button type="button" class="bc-btn" ref=${cancelRef} onClick=${() => finish(false)}>Cancel</button>
        <button type="button" class=${'bc-btn ' + (ask.danger ? 'bc-btn-danger' : 'bc-btn-primary')} onClick=${() => finish(true)}>${ask.confirmLabel}</button>
      </div>
    </div>
  </div>`;
}
