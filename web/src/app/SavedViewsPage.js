// Saved views management: apply, rename, delete. Views are created from the
// toolbar views menu ("Save current as...").

import { html, useState } from '../../vendor/index.js';
import { useStore, set } from './store.js';
import { applySavedView, updateSavedView, deleteSavedView } from './actions.js';

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

  return html`<div class="bc-page">
    <div class="bc-page-head">
      <h1>Saved views</h1>
      <button type="button" class="bc-btn" onClick=${() => set({ route: 'calendar' })}>Back to calendar</button>
    </div>
    <p class="bc-page-note">A saved view captures view type, visible calendars, folder collapse and filter text. Save one from the views menu in the toolbar.</p>

    ${savedViews.length === 0 && html`<div class="bc-empty">No saved views yet</div>`}
    ${savedViews.length > 0 && html`<div class="bc-outfeed-list">
      ${savedViews.map((v) => html`<div key=${v.id} class="bc-outfeed-row">
        ${editingId === v.id
          ? html`<form class="bc-views-saveform" onSubmit=${(e) => submitRename(v, e)}>
              <input value=${name} autofocus onInput=${(e) => setName(e.target.value)} aria-label="View name" />
              <button type="submit" class="bc-btn bc-btn-primary">Save</button>
              <button type="button" class="bc-btn" onClick=${() => setEditingId(null)}>Cancel</button>
            </form>`
          : html`<div class="bc-outfeed-main">
              <strong>${v.name}</strong>
              <span class="bc-outfeed-scope">${(v.config && v.config.viewType) || 'month'}</span>
            </div>`}
        <button type="button" class="bc-btn" onClick=${() => { applySavedView(v); set({ route: 'calendar' }); }}>Apply</button>
        <button type="button" class="bc-btn" onClick=${() => startRename(v)}>Rename</button>
        <button type="button" class="bc-btn bc-btn-danger" onClick=${() => deleteSavedView(v)}>Delete</button>
      </div>`)}
    </div>`}
  </div>`;
}
