// My relationship to an event: what it is to me, on every event, not only
// feed ones. planned (I'm doing it), maybe (tentative), available (on the
// shelf: an opportunity I have not picked), context (information; the
// calendar's role decides this and it is never chosen per event), hidden.
// The calendar's role sets the default (mine → planned, opportunities →
// available, context → context); this control changes one event's. It is
// a private note: nobody is notified. RSVP, which does notify, is separate.

import { html } from '../../vendor/index.js';
import { set } from './store.js';
import { setRelationship } from './actions.js';

export const REL_LABEL = { planned: 'Planned', maybe: 'Maybe', available: 'Available', context: 'Context', hidden: 'Hidden' };
export const REL_ORDER = ['planned', 'maybe', 'available', 'context'];

/** The choices an event on a calendar with this role can take. */
export function relationshipOptions(role) {
  if (role === 'context') return [];
  if (role === 'mine') return ['planned', 'maybe'];
  return ['available', 'maybe', 'planned', 'hidden'];
}

export function RelationshipControl({ occ, cal, onHidden, compact }) {
  const role = (cal && cal.role) || (occ.source === 'feed' ? 'opportunities' : 'mine');
  const options = relationshipOptions(role);
  if (options.length === 0) return null;
  const current = occ.attendance === 'hidden' ? 'hidden' : (occ.relationship || options[0]);
  const choose = async (value) => {
    if (value === current) return;
    if (occ.recurring) {
      // Which occurrences is a decision (ScopeChoice on the open surface).
      set({ attendPrompt: { instanceId: occ.instanceId, relationship: value, label: REL_LABEL[value] } });
      return;
    }
    const r = await setRelationship(occ, value);
    if (r === 'hidden' && onHidden) onHidden();
  };
  return html`<label class=${'bc-rel' + (compact ? ' is-compact' : '')} title="What this event is to you. A private note: nobody is notified.">
    <span class="bc-rel-label">For me</span>
    <select class=${'bc-rel-select is-' + current} aria-label="What this event is to you" value=${current} onChange=${(e) => choose(e.target.value)}>
      ${options.map((v) => html`<option key=${v} value=${v}>${REL_LABEL[v]}</option>`)}
    </select>
  </label>`;
}
