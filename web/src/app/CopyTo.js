// Copy an event onto another calendar. A picker of the calendars that can
// take events (local ones, and Google calendars the account may edit),
// minus the one it is on; a recurring event asks whether to copy the whole
// series or just this occurrence. The copy is a new event: same content,
// tags, people and reminders; a fresh identity. It is how something moves
// across the Google boundary today (copy, then delete the original if you
// meant to move), since a true move across systems is refused (docs).

import { html, useState } from '../../vendor/index.js';
import { state } from './store.js';
import { copyEventTo } from './actions.js';

export function CopyTo({ occ, onDone, compact }) {
  const [target, setTarget] = useState('');
  const [busy, setBusy] = useState(false);
  const targets = state.calendars.filter((c) => c.editable && c.id !== occ.calendarId);
  const run = async (scope) => {
    setBusy(true);
    const ok = await copyEventTo(occ, Number(target), scope);
    setBusy(false);
    if (ok && onDone) onDone();
  };
  const choose = (id) => {
    setTarget(id);
    if (id && !occ.recurring) run('all');
  };
  if (targets.length === 0) {
    return html`<div class="bc-copyto"><span class="bc-copyto-note">No other calendar can take a copy.</span></div>`;
  }
  return html`<div class=${'bc-copyto' + (compact ? ' is-compact' : '')}>
    <select aria-label="Copy to calendar" value=${target} disabled=${busy} onChange=${(e) => choose(e.target.value)}>
      <option value="">Copy to…</option>
      ${targets.map((c) => html`<option key=${c.id} value=${String(c.id)}>${c.name}${c.provider === 'google' ? ' (Google)' : ''}</option>`)}
    </select>
    ${occ.recurring && target && html`
      <button type="button" class="bc-btn" disabled=${busy} onClick=${() => run('this')}>This occurrence</button>
      <button type="button" class="bc-btn" disabled=${busy} onClick=${() => run('all')}>Whole series</button>`}
    ${busy && html`<span class="bc-copyto-note">Copying…</span>`}
  </div>`;
}
