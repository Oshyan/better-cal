// Saved views management: apply, rename, delete. Views are created from the
// toolbar views menu ("Save current as...").

import { html, useState } from '../../vendor/index.js';
import { useStore, set } from './store.js';
import { applySavedView, updateSavedView, deleteSavedView } from './actions.js';
import { relFilterLabel } from './Toolbar.js';
import { showRelFromConfig } from '../lib/relfilter.js';
import { PageShell, EmptyState } from './PageShell.js';

const VIEW_TYPE_LABELS = {
  month: 'Month', weeks3: '3 weeks', weeks2: '2 weeks',
  week: 'Week', day: 'Day', agenda: 'Agenda',
};

export function SavedViewsPage() {
  const savedViews = useStore((s) => s.savedViews);
  const [editingId, setEditingId] = useState(null);
  const [name, setName] = useState('');

  const startRename = (v) => { setEditingId(v.id); setName(v.name); };

  const submitRename = async (v, e) => {
    e.preventDefault();
    const trimmed = name.trim();
    if (trimmed && trimmed !== v.name) await updateSavedView(v, { name: trimmed });
    setEditingId(null);
  };

  const summarize = (v) => {
    const c = v.config || {};
    const parts = [VIEW_TYPE_LABELS[c.viewType] || 'Month'];
    if (Array.isArray(c.visibleCalendarIds)) {
      parts.push(c.visibleCalendarIds.length + ' calendar' + (c.visibleCalendarIds.length === 1 ? '' : 's'));
    }
    if (c.filterText) parts.push('filter "' + c.filterText + '"');
    // The Show filter, in the same words the toolbar button uses.
    if (Array.isArray(c.hideRel) && c.hideRel.length > 0) parts.push(relFilterLabel(showRelFromConfig(c)).toLowerCase());
    return parts;
  };

  return html`<${PageShell}
    title="Saved views"
    note="A saved view captures view type, visible calendars, folder collapse, filter text and the Show filter."
  >
    ${savedViews.length === 0 && html`<${EmptyState}
      text="No saved views yet. Set up the calendar the way you like, then use the views menu in the toolbar to save it as a reusable mode."
      actionLabel="Go to calendar" onAction=${() => set({ route: 'calendar' })}
    />`}
    ${savedViews.length > 0 && html`<div class="bc-card-list">
      ${savedViews.map((v) => html`<div key=${v.id} class="bc-card">
        ${editingId === v.id
          ? html`<form class="bc-views-saveform" onSubmit=${(e) => submitRename(v, e)}>
              <input value=${name} autofocus onInput=${(e) => setName(e.target.value)} aria-label="View name" />
              <button type="submit" class="bc-btn bc-btn-primary">Save</button>
              <button type="button" class="bc-btn" onClick=${() => setEditingId(null)}>Cancel</button>
            </form>`
          : html`<div class="bc-card-main">
              <div class="bc-card-badges">
                <strong class="bc-card-title">${v.name}</strong>
                ${summarize(v).map((p, i) => html`<span key=${i} class="bc-badge">${p}</span>`)}
              </div>
            </div>`}
        <div class="bc-card-actions">
          <button type="button" class="bc-btn bc-btn-primary" onClick=${() => { applySavedView(v); set({ route: 'calendar' }); }}>Apply</button>
          <button type="button" class="bc-btn" onClick=${() => startRename(v)}>Rename</button>
          <button type="button" class="bc-btn bc-btn-danger" onClick=${() => deleteSavedView(v)}>Delete</button>
        </div>
      </div>`)}
    </div>`}
  <//>`;
}
