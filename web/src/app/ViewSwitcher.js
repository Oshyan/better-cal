// ViewSwitcher: saved views ("modes") popover at the far left of the toolbar.
// Lists saved views, save-as, update-current, and a manage link. Switching
// away from a modified view prompts an inline save/discard confirm inside the
// popover (never window.confirm). A dot on the name marks a modified view.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { useStore, set, shallowEq } from './store.js';
import {
  applySavedView, saveViewAs, updateSavedView, viewConfigMatches, captureViewConfig,
} from './actions.js';
import { trapFocus } from '../ui/DayExpand.js';
import { Icon } from '../ui/icons.js';

export function ViewSwitcher() {
  const s = useStore(
    (st) => ({
      savedViews: st.savedViews, activeViewId: st.activeViewId,
      view: st.view, calendars: st.calendars,
      collapsedFolders: st.collapsedFolders, filterText: st.filterText,
    }),
    shallowEq,
  );
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [name, setName] = useState('');
  const [pendingView, setPendingView] = useState(null); // target view awaiting save/discard
  const panelRef = useRef(null);
  const rootRef = useRef(null);

  const active = s.savedViews.find((v) => v.id === s.activeViewId) || null;
  const modified = active ? !viewConfigMatches(active.config) : false;

  const close = () => { setOpen(false); setSaving(false); setPendingView(null); setName(''); };

  // Document-level listeners; torn down on unmount, not just close.
  useEffect(() => {
    if (!open) return undefined;
    const onDoc = (e) => {
      // Test against the whole widget (trigger + panel): closing on a
      // pointerdown that lands on the trigger makes the follow-up click
      // reopen it, so the button can never toggle the popover closed.
      if (rootRef.current && !rootRef.current.contains(e.target)) close();
    };
    const onKey = (e) => {
      if (e.key === 'Escape') { e.stopPropagation(); close(); }
      if (e.key === 'Tab') trapFocus(panelRef.current, e);
    };
    document.addEventListener('pointerdown', onDoc, true);
    document.addEventListener('keydown', onKey, true);
    return () => {
      document.removeEventListener('pointerdown', onDoc, true);
      document.removeEventListener('keydown', onKey, true);
    };
  }, [open]); // eslint-disable-line

  const selectView = (view) => {
    if (view.id === s.activeViewId && !modified) { close(); return; }
    if (active && modified) { setPendingView(view); return; }
    applySavedView(view);
    close();
  };

  const confirmSave = async () => {
    const target = pendingView;
    if (active) await updateSavedView(active, { config: captureViewConfig() });
    if (target) applySavedView(target);
    close();
  };

  const confirmDiscard = () => {
    if (pendingView) applySavedView(pendingView);
    close();
  };

  const submitSaveAs = async (e) => {
    e.preventDefault();
    const trimmed = name.trim();
    if (!trimmed) return;
    await saveViewAs(trimmed);
    close();
  };

  const updateCurrent = async () => {
    if (active) await updateSavedView(active, { config: captureViewConfig() });
    close();
  };

  return html`<div class="bc-views" ref=${rootRef}>
    <button
      type="button" class="bc-btn bc-views-btn"
      aria-haspopup="dialog" aria-expanded=${open} title="Saved views"
      onClick=${() => (open ? close() : setOpen(true))}
    >
      <span class="bc-views-label">${active ? active.name : 'Saved views'}</span>
      ${active && modified && html`<span class="bc-views-dot" role="img" aria-label="View modified" title="View modified"></span>`}
      <span class="bc-views-caret" aria-hidden="true"><${Icon} name="chevronDown" size=${12} /></span>
    </button>
    ${open && html`<div class="bc-views-pop" ref=${panelRef} role="dialog" aria-label="Saved views">
      ${s.savedViews.length === 0 && html`<div class="bc-views-empty">No saved views yet</div>`}
      ${s.savedViews.map((v) => html`<button
        key=${v.id} type="button"
        class="bc-views-item${v.id === s.activeViewId ? ' is-active' : ''}"
        onClick=${() => selectView(v)}
      >
        <span class="bc-views-label">${v.name}</span>
        ${v.id === s.activeViewId && modified && html`<span class="bc-views-dot" role="img" aria-label="View modified"></span>`}
      </button>`)}
      ${pendingView && html`<div class="bc-views-confirm">
        <span>Save changes to ${active ? active.name : 'this view'}?</span>
        <div class="bc-views-confirm-row">
          <button type="button" class="bc-btn bc-btn-primary" onClick=${confirmSave}>Save</button>
          <button type="button" class="bc-btn" onClick=${confirmDiscard}>Discard</button>
          <button type="button" class="bc-btn" onClick=${() => setPendingView(null)}>Cancel</button>
        </div>
      </div>`}
      <div class="bc-views-sep"></div>
      ${saving
        ? html`<form class="bc-views-saveform" onSubmit=${submitSaveAs}>
            <input value=${name} placeholder="View name" autofocus onInput=${(e) => setName(e.target.value)} aria-label="View name" />
            <button type="submit" class="bc-btn bc-btn-primary" disabled=${!name.trim()}>Save</button>
          </form>`
        : html`<button type="button" class="bc-views-item" onClick=${() => setSaving(true)}>Save current as...</button>`}
      ${active && modified && html`<button type="button" class="bc-views-item" onClick=${updateCurrent}>Update current view</button>`}
      <button type="button" class="bc-views-item" onClick=${() => { close(); set({ route: 'views' }); }}>Manage views</button>
    </div>`}
  </div>`;
}
