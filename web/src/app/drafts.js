// Durable drafts, and what they make possible.
//
// The editor's form and quick add's text are written to sessionStorage as
// they change and cleared when saved or deliberately dropped. That one fact
// replaces two conventions:
//
//   - "Discard this event? Entered details will be lost." A confirm dialog is
//     a cruder undo. Closing a dirty editor now just closes it, with a toast
//     whose Undo puts the editor back exactly as it was.
//   - "Updated. Reload to get the latest." Asking permission to reload only
//     existed because a reload could throw away typing. With drafts
//     surviving a reload, a new version can apply itself at the next quiet
//     moment and nobody has to read a toast.
//
// sessionStorage, not localStorage: a draft belongs to this tab's session.
// Closing the tab is a deliberate act; an unexpected reload is not.

import { state, set, toast } from './store.js';

const EDITOR_KEY = 'bc-editor-draft';
const QUICKADD_KEY = 'bc-quickadd-draft';

function read(key) {
  try {
    const raw = sessionStorage.getItem(key);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}
function write(key, value) {
  try {
    if (value == null) sessionStorage.removeItem(key);
    else sessionStorage.setItem(key, JSON.stringify(value));
  } catch { /* private mode or quota: the draft simply is not durable */ }
}

// --- editor -----------------------------------------------------------------

// The editor descriptor minus anything that would nest on restore.
function bareEditor(editor) {
  if (!editor) return null;
  const { restore, ...rest } = editor;
  return rest;
}

/** Called by the drawer whenever the form is dirty (and cleared when not). */
export function saveEditorDraft({ editor, form, nlText, initialSnap }) {
  write(EDITOR_KEY, { editor: bareEditor(editor), form, nlText: nlText || '', initialSnap: initialSnap || null, at: Date.now() });
}
export function clearEditorDraft() {
  write(EDITOR_KEY, null);
}
export function loadEditorDraft() {
  return read(EDITOR_KEY);
}

// Close a dirty editor without asking; offer to put it back.
export function discardEditorWithUndo() {
  const draft = loadEditorDraft() || (state.editor ? { editor: bareEditor(state.editor), form: null } : null);
  clearEditorDraft();
  set({ editor: null, editorDirty: false });
  if (!draft || !draft.form) return;
  toast('Draft discarded', {
    actionLabel: 'Undo',
    onAction: () => set({ editor: { ...draft.editor, restore: { form: draft.form, nlText: draft.nlText, initialSnap: draft.initialSnap } } }),
    duration: 10000,
  });
}

// --- quick add --------------------------------------------------------------

export function saveQuickAddText(text) {
  write(QUICKADD_KEY, text && text.trim() ? { text, at: Date.now() } : null);
}
export function clearQuickAddText() {
  write(QUICKADD_KEY, null);
}
export function loadQuickAddText() {
  const d = read(QUICKADD_KEY);
  return d && d.text ? d.text : null;
}

// --- after boot -------------------------------------------------------------

// Put back whatever was in progress when the page last unloaded: an editor
// with its form, or quick add with its text. An editor wins if both exist.
export function restoreDraftsAfterBoot() {
  const ed = loadEditorDraft();
  if (ed && ed.form && ed.editor) {
    set({ editor: { ...ed.editor, restore: { form: ed.form, nlText: ed.nlText, initialSnap: ed.initialSnap } } });
    return true;
  }
  const qa = loadQuickAddText();
  if (qa) {
    set({ quickAddOpen: true, quickAddSeed: qa });
    return true;
  }
  return false;
}

// --- quiet reload -----------------------------------------------------------

// A new version is active behind this page. Apply it at the next quiet
// moment: the tab is hidden, or nothing has been touched for QUIET_MS and no
// gesture or modal flow is mid-way. Drafts survive, so nothing is lost; the
// only thing a reload interrupts is reading, and it waits for that to pause.
const QUIET_MS = 20000;
let lastInput = Date.now();
let pointerDown = false;
let armed = false;

export function noteInput() {
  lastInput = Date.now();
}

export function armQuietReload() {
  if (armed) return;
  armed = true;
  const tryReload = () => {
    if (!armed) return;
    const quiet = document.visibilityState === 'hidden'
      || (Date.now() - lastInput > QUIET_MS && !pointerDown && !state.reschedule && !state.dropChoice);
    if (quiet) {
      armed = false;
      location.reload();
    }
  };
  document.addEventListener('visibilitychange', tryReload);
  setInterval(tryReload, 5000);
}

export function installActivityTracking() {
  const bump = () => noteInput();
  for (const ev of ['pointerdown', 'keydown', 'wheel', 'touchstart']) document.addEventListener(ev, bump, { passive: true, capture: true });
  document.addEventListener('pointerdown', () => { pointerDown = true; }, { passive: true, capture: true });
  document.addEventListener('pointerup', () => { pointerDown = false; }, { passive: true, capture: true });
  document.addEventListener('pointercancel', () => { pointerDown = false; }, { passive: true, capture: true });
}
