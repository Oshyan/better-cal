// The app's one "are you sure?" for things that can't be undone (adopting a
// calendar, sending a reply again, deleting saved events). Where an action
// can be undone it isn't asked at all: the toast's Undo is the better answer
// (drafts.js). The browser's own confirm() put OK first and named neither
// choice; here Cancel has the focus, the confirm button says what it does,
// and Esc or a click outside cancels.
//
//   if (!(await askConfirm({ title, body, confirmLabel, danger }))) return;

import { html, useEffect, useRef } from '../../vendor/index.js';
import { set, state, useStore } from './store.js';

// Questions are answered one at a time, in the order they were asked: the
// dialog is a turn, held by one asker at a time, and the others wait in
// line. Nothing replaces an open question: every question asked is
// answered exactly once, by the person, and every turn is given back on
// every exit (an answer, or the asker giving it back unasked), so an
// asker's own in-flight state (a delete's guard) always ends. Each
// question names what it is about, so one that follows another says which
// it is; an asker whose words could have gone stale while it waited
// (googlechange.js) reads them again when its turn comes: `waited` says
// it waited.
const line = [];
let holder = null;

function grantNext() {
  if (holder || !line.length) return;
  const { grant, waited } = line.shift();
  const turn = {
    waited: waited || false,
    // Show one question with this turn; resolves with the answer, and gives the turn back.
    ask(q) {
      if (holder !== turn) return Promise.resolve(false);
      return new Promise((resolve) => {
        let settled = false;
        const entry = { ...q, lines: q.lines || [], body: q.body || '', foot: q.foot || '', danger: !!q.danger };
        entry.resolve = (yes) => {
          if (settled) return;
          settled = true;
          if (state.confirmAsk === entry) set({ confirmAsk: null });
          turn.release();
          resolve(!!yes);
        };
        set({ confirmAsk: entry });
      });
    },
    // Give the turn back without asking (or after): the next asker's turn.
    release() {
      if (holder !== turn) return;
      holder = null;
      grantNext();
    },
  };
  holder = turn;
  grant(turn);
}

/**
 * The dialog's next turn: resolves to { waited, ask(q), release() } once
 * every question asked before it is answered. `waited`: another question
 * was open or waiting when this turn was asked for. The holder asks once
 * (ask gives the turn back) or releases it; until then nobody else asks.
 */
export function confirmTurn() {
  return new Promise((grant) => {
    line.push({ grant, waited: !!holder || line.length > 0 });
    grantNext();
  });
}

/** Resolves true when the person confirms, false for Cancel, Esc or a click outside. */
// A question with several values (googlechange.js) adds `lines`, one each,
// under the body, and `foot`, what each button does, above the buttons.
export async function askConfirm({ title, body = '', lines = [], foot = '', confirmLabel, danger = false }) {
  const turn = await confirmTurn();
  return turn.ask({ title, body, lines, foot, confirmLabel, danger });
}

export function ConfirmHost() {
  const ask = useStore((s) => s.confirmAsk);
  const cancelRef = useRef(null);
  useEffect(() => { if (ask && cancelRef.current) cancelRef.current.focus(); }, [ask]);
  if (!ask) return null;
  const finish = (yes) => ask.resolve(yes);
  return html`<div class="bc-stepup-backdrop" data-layer="top" onClick=${(e) => { if (e.target === e.currentTarget) finish(false); }}
    onKeyDown=${(e) => { if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); finish(false); } }}>
    <div class="bc-stepup" role="alertdialog" aria-modal="true" aria-labelledby="bc-confirm-title" aria-describedby=${ask.body ? 'bc-confirm-body' : undefined}>
      <h2 id="bc-confirm-title">${ask.title}</h2>
      ${ask.body && html`<p id="bc-confirm-body">${ask.body}</p>`}
      ${ask.lines && ask.lines.length > 0 && html`<ul class="bc-confirm-lines">${ask.lines.map((l) => html`<li key=${l}>${l}</li>`)}</ul>`}
      ${ask.foot && html`<p class="bc-confirm-foot">${ask.foot}</p>`}
      <div class="bc-stepup-actions">
        <button type="button" class="bc-btn" ref=${cancelRef} onClick=${() => finish(false)}>Cancel</button>
        <button type="button" class=${'bc-btn ' + (ask.danger ? 'bc-btn-danger' : 'bc-btn-primary')} onClick=${() => finish(true)}>${ask.confirmLabel}</button>
      </div>
    </div>
  </div>`;
}
