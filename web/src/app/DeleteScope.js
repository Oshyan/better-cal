// Deleting one occurrence of a series is a decision, not a confirmation:
// this one, this and every later one, or the whole series. Undo cannot turn
// one answer into another, so the question is asked, inline where the delete
// was clicked, rather than defaulting silently to "this occurrence" (which is
// what the trash icon did until now). One-off events still delete at once,
// with Undo on the toast.

import { html } from '../../vendor/index.js';
import { describeRrule } from './EventDetail.js';
import { deleteEvent } from './actions.js';

export function DeleteScope({ occ, onDone, onCancel, compact }) {
  const run = (scope) => {
    if (onDone) onDone();
    deleteEvent(occ, scope);
  };
  const series = occ.rrule ? describeRrule(occ.rrule) : 'Repeats';
  return html`<div class=${'bc-delscope' + (compact ? ' is-compact' : '')} role="group" aria-label="Delete which occurrences">
    <span class="bc-delscope-q">Delete: <span class="bc-delscope-series">${series}</span></span>
    <button type="button" class="bc-btn" onClick=${() => run('this')}>This occurrence</button>
    <button type="button" class="bc-btn" onClick=${() => run('following')}>This and following</button>
    <button type="button" class="bc-btn bc-btn-danger" onClick=${() => run('all')}>All in series</button>
    <button type="button" class="bc-link-btn" onClick=${onCancel}>Cancel</button>
  </div>`;
}
