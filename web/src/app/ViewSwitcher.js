// ViewSwitcher: saved views ("modes") popover at the far left of the toolbar.
// Lists saved views, save-as, update-current, and a manage link. Switching
// away from a modified view prompts an inline save/discard confirm inside the
// popover (never window.confirm). A dot on the name marks a modified view.
//
// The picking logic (which view is active, whether it was modified, the
// save/discard/cancel step) lives in useSavedViewPicker, shared with the
// phone's View sheet (BottomBar.js) so both behave the same.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { useStore, set, shallowEq } from './store.js';
import {
  applySavedView, saveViewAs, updateSavedView, viewConfigMatches, captureViewConfig, applyDefaultView,
} from './actions.js';
import { trapFocus } from '../ui/DayExpand.js';
import { Icon } from '../ui/icons.js';
import { onOutsidePress, insideAny } from '../ui/outside.js';

// onDone runs after a view is applied (or the pending choice resolves).
export function useSavedViewPicker(onDone) {
  const s = useStore(
    (st) => ({
      savedViews: st.savedViews, activeViewId: st.activeViewId, defaultViewId: st.settings && st.settings.defaultViewId,
      view: st.view, calendars: st.calendars,
      collapsedFolders: st.collapsedFolders, filterText: st.filterText,
    }),
    shallowEq,
  );
  const [pendingView, setPendingView] = useState(null); // target view awaiting save/discard
  const active = s.savedViews.find((v) => v.id === s.activeViewId) || null;
  const modified = active ? !viewConfigMatches(active.config) : false;
  const defaultView = s.savedViews.find((v) => v.id === s.defaultViewId) || null;

  const finish = () => { setPendingView(null); onDone(); };
  const go = (target) => (target.isDefault ? applyDefaultView() : applySavedView(target));

  const selectView = (view) => {
    if (view.id === s.activeViewId && !modified) { finish(); return; }
    if (active && modified) { setPendingView(view); return; }
    applySavedView(view);
    finish();
  };
  const selectDefault = () => {
    if (active && modified) { setPendingView({ id: null, isDefault: true }); return; }
    applyDefaultView();
    finish();
  };
  const confirmSave = async () => {
    const target = pendingView;
    if (active) await updateSavedView(active, { config: captureViewConfig() });
    if (target) go(target);
    finish();
  };
  const confirmDiscard = () => {
    if (pendingView) go(pendingView);
    finish();
  };
  return {
    savedViews: s.savedViews, activeViewId: s.activeViewId, active, modified, defaultView,
    pendingView, cancelPending: () => setPendingView(null), resetPending: () => setPendingView(null),
    selectView, selectDefault, confirmSave, confirmDiscard,
  };
}

export function ViewSwitcher() {
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [name, setName] = useState('');
  const panelRef = useRef(null);
  const rootRef = useRef(null);
  const picker = useSavedViewPicker(() => close());
  const { active, modified } = picker;

  function close() { setOpen(false); setSaving(false); setName(''); picker.resetPending(); }

  // Document-level listeners; torn down on unmount, not just close.
  useEffect(() => {
    if (!open) return undefined;
    // Inside is the whole widget (trigger + panel), so the trigger's own
    // click can toggle the popover closed.
    const onKey = (e) => {
      if (e.key === 'Escape') { e.stopPropagation(); close(); }
      if (e.key === 'Tab') trapFocus(panelRef.current, e);
    };
    const stop = onOutsidePress(insideAny(rootRef), () => close());
    document.addEventListener('keydown', onKey, true);
    return () => {
      stop();
      document.removeEventListener('keydown', onKey, true);
    };
  }, [open]); // eslint-disable-line

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
      <button type="button" class="bc-views-item bc-views-default" title="Month, today, no filters (0). Mark a saved view as default in Manage views." onClick=${picker.selectDefault}>
        <span class="bc-views-label">Default view${picker.defaultView ? ': ' + picker.defaultView.name : ''}</span>
        <kbd class="bc-ov-key">0</kbd>
      </button>
      <div class="bc-views-sep"></div>
      ${picker.savedViews.length === 0 && html`<div class="bc-views-empty">No saved views yet</div>`}
      ${picker.savedViews.map((v) => html`<button
        key=${v.id} type="button"
        class="bc-views-item${v.id === picker.activeViewId ? ' is-active' : ''}"
        onClick=${() => picker.selectView(v)}
      >
        <span class="bc-views-label">${v.name}</span>
        ${v.id === picker.activeViewId && modified && html`<span class="bc-views-dot" role="img" aria-label="View modified"></span>`}
      </button>`)}
      ${picker.pendingView && html`<div class="bc-views-confirm">
        <span>Save changes to ${active ? active.name : 'this view'}?</span>
        <div class="bc-views-confirm-row">
          <button type="button" class="bc-btn bc-btn-primary" onClick=${picker.confirmSave}>Save</button>
          <button type="button" class="bc-btn" onClick=${picker.confirmDiscard}>Discard</button>
          <button type="button" class="bc-btn" onClick=${picker.cancelPending}>Cancel</button>
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
