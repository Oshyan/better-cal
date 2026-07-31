// ShortcutsSheet: the ? cheat sheet. Centered modal rendered straight from
// the hotkeys.js table (grouped Navigation / Views / Events / Overlays), so
// it always matches the live bindings. Esc and click-out close (Esc via the
// global keyboard map's closeOverlays); focus is trapped while open.

import { html, useRef, useEffect } from '../../vendor/index.js';
import { useStore, set } from './store.js';
import { trapFocus } from '../ui/DayExpand.js';
import { HOTKEYS, HOTKEY_GROUPS } from './hotkeys.js';

export function ShortcutsSheet() {
  const open = useStore((s) => s.shortcutsOpen);
  const panelRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => { if (e.key === 'Tab') trapFocus(panelRef.current, e); };
    document.addEventListener('keydown', onKey, true);
    if (panelRef.current) panelRef.current.focus();
    return () => document.removeEventListener('keydown', onKey, true);
  }, [open]);

  if (!open) return null;

  const close = () => set({ shortcutsOpen: false });

  return html`<div class="bc-overlay bc-shortcuts-overlay" onClick=${(e) => { if (e.target === e.currentTarget) close(); }}>
    <div class="bc-shortcuts" ref=${panelRef} role="dialog" aria-modal="true" aria-label="Keyboard shortcuts" tabindex="-1">
      <div class="bc-shortcuts-head">
        <h2>Keyboard shortcuts</h2>
        <button type="button" class="bc-icon-btn" aria-label="Close" onClick=${close}>✕</button>
      </div>
      <div class="bc-shortcuts-grid">
        ${HOTKEY_GROUPS.map((g) => html`<section key=${g} class="bc-sc-group">
          <h3>${g}</h3>
          ${HOTKEYS.filter((h) => h.group === g).map((h) => html`<div key=${h.id} class="bc-sc-row">
            <span class="bc-sc-keys">${(h.display || h.keys).map((k, i) => html`<kbd key=${i}>${k}</kbd>`)}</span>
            <span class="bc-sc-label">${h.label}</span>
          </div>`)}
        </section>`)}
      </div>
    </div>
  </div>`;
}
