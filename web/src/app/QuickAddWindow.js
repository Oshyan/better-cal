// Quick add on its own (1.2.0): what the Chrome extension's new-event key
// opens, at /new#window, in a small window of its own. Just the card, no
// calendar loading behind it. Create shows what was made, then the window
// closes itself; Cancel and Esc close it; More options turns the window into
// the full editor, and closing that closes the window too.
//
// Chrome lets a window the extension opened close itself. Anywhere else
// (the address typed into a normal tab) window.close() does nothing, so the
// page then says it's done and links back to the calendar.

import { html, useState, useEffect, useRef } from '../../vendor/index.js';
import { useStore, set, shallowEq } from './store.js';
import { QuickAdd } from './QuickAdd.js';
import { EditorDrawer } from './EditorDrawer.js';
import { CreateDrawer } from './CreateDrawer.js';
import { Toasts } from './Toasts.js';
import { Icon } from '../ui/icons.js';
import { occDayKey, dateOfDayKey, parseISO } from '../lib/dates.js';

const SHOW_DONE_MS = 1600;

/** sessionStorage key: this window is quick add's own (main.js reads it on a reload). */
export const QA_WINDOW_KEY = 'bc-quickadd-window';

// "Sat, Oct 31, 7:00 PM", or the date alone for an all-day event.
function whenOf(occ) {
  if (!occ || !occ.start) return '';
  if (occ.allDay) return dateOfDayKey(occDayKey(occ)).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
  return parseISO(occ.start).toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

export function QuickAddWindow() {
  const s = useStore((st) => ({
    open: st.quickAddOpen, editor: st.editor, created: st.lastCreated,
  }), shallowEq);
  const [phase, setPhase] = useState('adding'); // adding | done | closed
  const [done, setDone] = useState(null);       // the lastCreated it showed
  const startCreated = useRef(s.created);       // anything made before this window
  const closeTimer = useRef(0);
  const shown = useRef(false); // quick add or the editor has been up: closing means something

  const closeWindow = () => {
    clearTimeout(closeTimer.current);
    window.close();
    // Still here: not a window this page may close.
    setPhase('closed');
  };

  useEffect(() => {
    if (s.open || s.editor) shown.current = true;
    if (phase !== 'adding' || s.open || s.editor || !shown.current) return;
    if (s.created && s.created !== startCreated.current && s.created !== done) {
      setDone(s.created);
      setPhase('done');
      return;
    }
    closeWindow(); // Cancel, Esc, or the editor closed without saving
  }, [s.open, s.editor, s.created, phase]); // eslint-disable-line

  // The confirmation stays only briefly; a key or a click keeps it.
  useEffect(() => {
    if (phase !== 'done') return undefined;
    closeTimer.current = setTimeout(closeWindow, SHOW_DONE_MS);
    const keep = () => clearTimeout(closeTimer.current);
    window.addEventListener('pointerdown', keep, true);
    window.addEventListener('keydown', keep, true);
    return () => {
      clearTimeout(closeTimer.current);
      window.removeEventListener('pointerdown', keep, true);
      window.removeEventListener('keydown', keep, true);
    };
  }, [phase]); // eslint-disable-line

  const addAnother = () => {
    clearTimeout(closeTimer.current);
    setPhase('adding');
    set({ quickAddOpen: true });
  };
  const eventLink = done && done.occ && done.occ.instanceId
    ? '/?event=' + encodeURIComponent(done.occ.instanceId) + (done.occ.start ? '&at=' + encodeURIComponent(new Date(done.occ.start).toISOString().replace(/\.\d{3}Z$/, 'Z')) : '')
    : '/';

  return html`<div class="bc-app bc-qa-window">
    ${phase === 'adding' && html`<${QuickAdd} /><${EditorDrawer} /><${CreateDrawer} />`}
    ${phase === 'done' && html`<div class="bc-qa-done" role="status">
      <span class="bc-qa-done-icon"><${Icon} name="check" size=${20} /></span>
      <p class="bc-qa-done-title">${done.occ.title}<span>${whenOf(done.occ)}</span></p>
      <p class="bc-qa-done-text">${done.message}</p>
      <div class="bc-qa-done-actions">
        <button type="button" class="bc-btn bc-btn-primary" onClick=${addAnother}>Add another</button>
        <a class="bc-btn" href=${eventLink} target="_blank" rel="noopener" onClick=${() => setTimeout(closeWindow, 0)}>Open in Better-Cal</a>
        <button type="button" class="bc-btn" onClick=${closeWindow}>Close</button>
      </div>
    </div>`}
    ${phase === 'closed' && html`<div class="bc-qa-done">
      <p class="bc-qa-done-text">${done ? done.message : 'Nothing was added.'}</p>
      <div class="bc-qa-done-actions">
        <button type="button" class="bc-btn bc-btn-primary" onClick=${addAnother}>Add an event</button>
        <a class="bc-btn" href="/" onClick=${() => { try { sessionStorage.removeItem(QA_WINDOW_KEY); } catch { /* fine */ } }}>Go to the calendar</a>
      </div>
    </div>`}
    <${Toasts} />
  </div>`;
}
