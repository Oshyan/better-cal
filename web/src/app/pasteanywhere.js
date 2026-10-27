// Paste anywhere on the calendar: Cmd/Ctrl+V while not typing in a field
// opens quick add with what was pasted, already read, so Enter adds it. An
// event page's link, a friend's "party tomorrow at 8pm", a Google Calendar
// link (opens as that event) or a calendar's address (opens Subscribe) all
// go where sharing them would (handoff.js openSharedText). Nothing is saved
// without the card: a paste that missed a text box costs one Esc.

import { state, toast } from './store.js';
import { openSharedText } from './handoff.js';

import { pastedText } from '../lib/pastetext.js';

// Quick add's own limit (MODEL_QUICKADD_CHARS on the server).
const MAX_CHARS = 4000;

// Only on the calendar itself, with nothing else in front of it: not while
// typing, editing, moving an event, or with any dialog or menu open.
function pasteWanted(e) {
  const el = document.activeElement;
  if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || el.isContentEditable)) return false;
  const t = e.target;
  if (t && t.closest && t.closest('input, textarea, select, [contenteditable="true"]')) return false;
  if (!state.authed || state.route !== 'calendar') return false;
  if (state.quickAddOpen || state.editor || state.createDrawer || state.moveDialog || state.reschedule
    || state.paletteOpen || state.jumpOpen || state.searchOpen || state.shortcutsOpen || state.welcomeOpen) return false;
  if (document.querySelector('[aria-modal="true"]')) return false;
  return true;
}

export function installPasteAnywhere() {
  const onPaste = (e) => {
    if (!pasteWanted(e)) return;
    const data = e.clipboardData;
    if (!data) return;
    const text = pastedText(data.getData('text/plain'), data.getData('text/html'));
    if (!text) return; // an image or a file: nothing to read yet
    e.preventDefault();
    if (text.length > MAX_CHARS) {
      toast('That\'s too long to read as one event. Paste just the part about it.', { error: true });
      return;
    }
    openSharedText(text).catch((err) => toast((err && err.message) || 'Could not read that', { error: true }));
  };
  document.addEventListener('paste', onPaste);
  return () => document.removeEventListener('paste', onPaste);
}
