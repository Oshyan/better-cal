// Changing one occurrence of a series is a decision, not a confirmation:
// this one, this and every later one, or the whole series. Undo cannot turn
// one answer into another, so the question is asked inline where the change
// was made rather than defaulting silently to "this occurrence". Grid drags
// ask the same thing at the drop point (App.js promptScopeAt); this is the
// version for a surface that has room for a row of buttons.

import { html } from '../../vendor/index.js';
import { describeRrule } from './EventDetail.js';
import { deleteEvent } from './actions.js';

/** @param {{occ:object, verb:string, danger?:boolean, onPick:(scope:string)=>void, onCancel:()=>void, compact?:boolean}} props */
export function ScopeChoice({ occ, verb, danger, onPick, onCancel, compact }) {
  const series = occ.rrule ? describeRrule(occ.rrule) : 'Repeats';
  return html`<div class=${'bc-delscope' + (compact ? ' is-compact' : '')} role="group" aria-label=${verb + ' which occurrences'}>
    <span class="bc-delscope-q">${verb}: <span class="bc-delscope-series">${series}</span></span>
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
  return html`<${ScopeChoice} occ=${occ} verb="Delete" danger onPick=${run} onCancel=${onCancel} compact=${compact} />`;
}
