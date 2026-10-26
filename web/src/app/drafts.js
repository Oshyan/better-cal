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
// Closing the tab is a deliberate act; an unexpected reload is not. In the
// installed app, localStorage, as resume.js does: a phone (iOS especially)
// closes a Home Screen app in the background, and it starts again with a new
// session, so a draft typed before switching to another app would be lost.
// There a draft lasts a day; one older than that is dropped, not restored.
//
// Which storage depends on the window doing the writing, so anything that
// must reach every draft (logout, the stale-draft sweep at boot) goes through
// both, the way resume-storage.js does. A draft field added later must mean
// "as before" when it is missing: a draft can be restored by a newer version.

import { state, set, toast } from './store.js';
import { saveResume, UPDATE_FLAG } from './resume.js';

const EDITOR_KEY = 'bc-editor-draft';
const QUICKADD_KEY = 'bc-quickadd-draft';

const INSTALLED_MAX_AGE_MS = 24 * 3600000;
const installed = () => { try { return matchMedia('(display-mode: standalone)').matches; } catch { return false; } };
const store = () => (installed() ? localStorage : sessionStorage);

function read(key) {
  try {
    const raw = store().getItem(key);
    const value = raw ? JSON.parse(raw) : null;
    if (value && installed() && !(value.at && Date.now() - value.at <= INSTALLED_MAX_AGE_MS)) {
      store().removeItem(key);
      return null;
    }
    return value;
  } catch {
    return null;
  }
}
function write(key, value) {
  try {
    if (value == null) store().removeItem(key);
    else store().setItem(key, JSON.stringify(value));
  } catch { /* private mode or quota: the draft simply is not durable */ }
}

// Every draft, in both storages: logging out from a browser tab also takes
// what the installed app left in localStorage (shared on Android and
// computers).
export function clearAllDrafts() {
  for (const name of ['localStorage', 'sessionStorage']) {
    try {
      const storage = globalThis[name];
      if (storage) for (const key of [EDITOR_KEY, QUICKADD_KEY]) storage.removeItem(key);
    } catch { /* storage unavailable */ }
  }
}

// At boot, from any window: an installed-app draft past its day is removed,
// not left until the installed app next opens (which may be never).
export function sweepStaleDrafts() {
  try {
    for (const key of [EDITOR_KEY, QUICKADD_KEY]) {
      const raw = localStorage.getItem(key);
      if (raw == null) continue;
      let value = null;
      try { value = JSON.parse(raw); } catch { /* unreadable: remove */ }
      if (!(value && value.at && Date.now() - value.at <= INSTALLED_MAX_AGE_MS)) localStorage.removeItem(key);
    }
  } catch { /* storage unavailable */ }
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
  // Back to the event the editor was opened from, if any (actions.editorReturn).
  const back = state.editor && state.editor.returnTo;
  set({ editor: null, editorDirty: false, ...(back ? { popover: back } : {}) });
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
  sweepStaleDrafts();
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

// --- reloading into a new version -----------------------------------------

// A new version is active behind this page. The banner (updates.js) offers
// the reload; one left alone applies when the app goes out of sight, so
// coming back finds the new version. Drafts survive a reload, and so does
// where you were (resume.js). An idle-time reload used to land under people
// who were only reading.
let armed = false;

// A fresh navigation rather than location.reload(), so the document is asked
// for again, not reused; the new document's versioned script addresses
// (server/src/Http/AppShell.php) then load the new version. iPhone Safari had
// kept running the old scripts after a reload. main.js takes the marker off
// the address again.
export const UPDATED_PARAM = 'updated';

export function reloadForUpdate() {
  saveResume();
  try { localStorage.setItem(UPDATE_FLAG, '1'); } catch { /* the start log just says reload */ }
  location.replace('/?' + UPDATED_PARAM + '=' + Date.now().toString(36));
}

export function armBackgroundReload() {
  if (armed) return;
  armed = true;
  // Out of sight already (the half-hourly check found it in a background
  // tab): apply it now, so coming back finds the new version.
  if (document.visibilityState === 'hidden') {
    reloadForUpdate();
    return;
  }
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') reloadForUpdate();
  });
}
