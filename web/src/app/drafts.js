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
import { pageVersion } from './appversion.js';
import { writesPending } from './api.js';
import { questionPending } from './ConfirmDialog.js';
import { openLayers } from '../ui/outside.js';

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

// A new version is active behind this page (main.js, the worker's
// 'sw-updated'). The rule (#323): when nothing would be lost, the page
// reloads itself as soon as the new version is ready, in sight or not. When
// something would be lost, the banner (updates.js) offers the reload, and
// the page takes it by itself at the first moment nothing would be lost any
// more: a layer closes, a toast's Undo runs out, a form is saved, a field
// is left, the app is hidden or shown, a page changes. Each of those is an
// event; nothing here waits on a timer. Drafts survive a reload, and so
// does where you were (resume.js), but an open editor still holds it: the
// person is in the middle of it.
//
// Never a loop: the version a page reloaded for is written down in this
// tab's session, and a page reloads by itself at most once for a version.
// If it comes back still on the old one, or is told of that version again,
// it offers instead.

// Forms on a page with unsaved changes (a plugin's settings, a key half
// pasted) hold the reload: settings, unlike event drafts, aren't saved
// across one.
const reloadHolds = new Set();

export function holdReload(key, on) {
  if (on) reloadHolds.add(key); else reloadHolds.delete(key);
  reloadIfReady();
}

const isTextField = (el) => !!el && (el.isContentEditable
  ? !!(el.textContent || '').trim()
  : (el.tagName === 'TEXTAREA' || (el.tagName === 'INPUT' && !/^(button|checkbox|radio|range|color|file|submit|reset|image|hidden)$/.test(el.type || ''))) && !!el.value);

/** What would be lost to a reload now: the page's own state, read where it lives. */
export function pageNow() {
  let layers = [];
  let moving = false;
  let typing = false;
  try {
    // The toast list is a top layer whatever its toasts say: a toast counts
    // only when it offers a choice or reports an error (below).
    layers = openLayers({ top: true }).filter((el) => !el.classList.contains('bc-toasts'));
    const body = document.body && document.body.classList;
    moving = !!(body && (body.contains('bc-dragging') || body.contains('bc-rescheduling'))) || !!document.querySelector('.is-dragging');
    typing = isTextField(document.activeElement);
  } catch { /* no document: nothing open in it */ }
  return {
    layers: layers.length,
    forms: reloadHolds.size,
    question: questionPending(),
    toasts: (state.toasts || []).filter((t) => t.error || t.undoable || t.actionLabel || (t.actions && t.actions.length)).length,
    moving: moving || !!state.reschedule,
    typing,
    writes: writesPending(),
    starting: !state.booted,
  };
}

/**
 * The one rule for "nothing would be lost": the reasons a reload now would
 * lose something, empty when it would lose nothing. Every input is a count
 * or a flag in `now` (pageNow):
 *   layers   a layer open above the page: the editor, quick add, search, the
 *            palette, a drawer, a dialog, a menu, the day sheet, the
 *            welcome, a confirm or password step-up (ui/outside.js)
 *   forms    a page form with unsaved changes (holdReload)
 *   question a question open or about to be asked (ConfirmDialog.js: the
 *            Google "Cancel or Overwrite" among them), or a yes given whose
 *            asker has not yet run to its first wait (its write not counted)
 *   toasts   a toast still offering Undo or another choice, or reporting an
 *            error or a refusal, until it is dismissed or runs out
 *   moving   a drag or reschedule in progress
 *   typing   a text field with text in it has the focus
 *   writes   a write sent and not yet answered (api.js)
 *   starting the app has not finished starting: a link or a share it was
 *            opened with is not yet where it goes
 */
export function whatWouldBeLost(now = pageNow()) {
  return Object.keys(now).filter((k) => !!now[k]);
}

// The version this tab last reloaded for, by itself or from the banner.
const RELOADED_FOR = 'bc-reloaded-for';
const reloadedFor = () => { try { return sessionStorage.getItem(RELOADED_FOR); } catch { return null; } };

/**
 * What to do about `version` being active: 'none' when this page already
 * runs it, 'offer' when the page has reloaded for it once already or a
 * reload would lose something, 'reload' otherwise.
 */
export function decideReload({ version, page = pageVersion, tried = reloadedFor(), lost = whatWouldBeLost() }) {
  if (version && page && version === page) return 'none';
  if (tried != null && tried === String(version || '')) return 'offer';
  return lost.length ? 'offer' : 'reload';
}

let readyVersion = null; // the version active behind this page, once one is
let offered = false;
let leaving = false; // the reload has begun: what happens until the page unloads asks nothing
let watching = false;

// A fresh navigation rather than location.reload(), so the document is asked
// for again, not reused; the new document's versioned script addresses
// (server/src/Http/AppShell.php) then load the new version. iPhone Safari had
// kept running the old scripts after a reload. main.js takes the marker off
// the address again. The banner's Reload and the reload by itself both come
// here: where you were is written down first (resume.js), as on any reload.
export const UPDATED_PARAM = 'updated';

export function reloadForUpdate() {
  saveResume();
  try { localStorage.setItem(UPDATE_FLAG, '1'); } catch { /* the start log just says reload */ }
  try { sessionStorage.setItem(RELOADED_FOR, String(readyVersion || '')); } catch { /* no guard here: the banner's Reload only */ }
  location.replace('/?' + UPDATED_PARAM + '=' + Date.now().toString(36));
}

/**
 * Apply the rule now, if a new version is ready: reload, or offer it once.
 * Called by every event that can change the answer (watch below), and by a
 * page change (App.js).
 */
export function reloadIfReady() {
  if (!readyVersion || leaving) return;
  const d = decideReload({ version: readyVersion });
  if (d === 'reload') { leaving = true; reloadForUpdate(); return; }
  if (d === 'offer' && !offered) {
    offered = true;
    set({ appUpdateReady: true });
  }
}

// The events after which a reload may lose nothing any more: the page
// changed (a layer closed, a toast left, a drag ended), a field was left,
// the app was hidden or came back into view.
function watch() {
  if (watching) return;
  watching = true;
  try {
    if (typeof MutationObserver === 'function') new MutationObserver(reloadIfReady).observe(document.body, { childList: true, subtree: true });
    // Focus leaving for another field is not a field left: mid-move, the
    // page reads as having none focused.
    document.addEventListener('focusout', (e) => { if (!e.relatedTarget) reloadIfReady(); });
    document.addEventListener('visibilitychange', reloadIfReady);
  } catch { /* no document: the banner's Reload is the way */ }
}

/** main.js: `version` is active behind this page. */
export function newVersionActive(version) {
  readyVersion = version || readyVersion || 'unknown';
  offered = false;
  watch();
  reloadIfReady();
}

/**
 * main.js, at boot: this page is the reload for a new version (the address
 * carried the marker). If it still runs another version, the new one did not
 * take: offer it, never reload again.
 */
export function checkReloadTook() {
  const tried = reloadedFor();
  if (!tried || !pageVersion || tried === pageVersion) return;
  readyVersion = tried;
  offered = true;
  set({ appUpdateReady: true });
}
