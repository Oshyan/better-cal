// Changing one occurrence of a series is a decision, not a confirmation:
// this one, this and every later one, or the whole series. Undo cannot turn
// one answer into another, so the question is asked inline where the change
// was made rather than defaulting silently to "this occurrence". Grid drags
// ask the same thing at the drop point (App.js promptScopeAt); this is the
// version for a surface that has room for a row of buttons.

import { html } from '../../vendor/index.js';
import { describeRrule } from './EventDetail.js';
import { deleteEvent, googleBacked, GOOGLE_NO_UNDO } from './actions.js';

/** @param {{occ:object, verb:string, danger?:boolean, onPick:(scope:string)=>void, onCancel:()=>void, compact?:boolean}} props */
export function ScopeChoice({ occ, verb, danger, onPick, onCancel, compact, note }) {
  const series = occ.rrule ? describeRrule(occ.rrule) : 'Repeats';
  return html`<div class=${'bc-delscope' + (compact ? ' is-compact' : '')} role="group" aria-label=${verb + ' which occurrences'}>
    <span class="bc-delscope-q">${verb}: <span class="bc-delscope-series">${series}</span>${note && html` <span class="bc-delscope-note">${note}</span>`}</span>
    <button type="button" class="bc-btn" onClick=${() => onPick('this')}>This occurrence</button>
    <button type="button" class="bc-btn" onClick=${() => onPick('following')}>This and following</button>
    <button type="button" class=${'bc-btn' + (danger ? ' bc-btn-danger' : '')} onClick=${() => onPick('all')}>All in series</button>
    <button type="button" class="bc-link-btn" onClick=${onCancel}>Cancel</button>
  </div>`;
}

export function DeleteScope({ occ, onDone, onCancel, compact }) {
  const run = (scope) => {
    if (onDone) onDone();
    deleteEvent(occ, scope);
  };
  const google = googleBacked(occ);
  const note = google ? GOOGLE_NO_UNDO : null;
  if (!occ.recurring) {
    // Only reached for a Google event: everything else deletes at once with
    // Undo, but this one cannot be undone, so it is asked first.
    return html`<div class=${'bc-delscope' + (compact ? ' is-compact' : '')} role="group" aria-label="Confirm delete">
      <span class="bc-delscope-q">Delete at Google? <span class="bc-delscope-note">Can't be undone.</span></span>
      <button type="button" class="bc-btn bc-btn-danger" onClick=${() => run(undefined)}>Delete</button>
      <button type="button" class="bc-link-btn" onClick=${onCancel}>Cancel</button>
    </div>`;
  }
  return html`<${ScopeChoice} occ=${occ} verb="Delete" danger note=${note} onPick=${run} onCancel=${onCancel} compact=${compact} />`;
}
