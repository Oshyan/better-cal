// Global keyboard map. The bindings themselves live in hotkeys.js (one
// source of truth shared with the shortcuts cheat sheet); this file wires
// each entry id to its handler. A handler returning false means "not
// applicable right now" (wrong context): the key falls through untouched.
// Esc inside reschedule mode is handled by RescheduleOverlay (capture phase).

import { state, set } from './store.js';
import {
  rosterViews, setView, cycleView, goToday, navigate, closeOverlays,
  enterReschedule, openDetail, deleteEvent, stepDetailSameDay, googleBacked,
} from './actions.js';
import { HOTKEYS } from './hotkeys.js';

function isTyping() {
  const el = document.activeElement;
  if (!el) return false;
  const tag = el.tagName;
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
}

// The occurrence the open popover or detail view is showing, if any.
function focusedOcc() {
  const src = state.detail || state.popover;
  if (!src) return null;
  return state.occ.get(src.instanceId) || null;
}

function isFeedOcc(occ) {
  // Feed AND plugin calendars are read-only content: no edit/delete hotkeys.
  const cal = state.calendars.find((c) => c.id === occ.calendarId);
  return cal ? !cal.editable : occ.source === 'feed';
}

// Exported so the command palette runs these exact functions rather than a
// parallel copy of them — a palette entry and its keyboard shortcut can then
// never disagree about what the action does.
export const handlers = {
  today: goToday,
  jump: () => set({ jumpOpen: true }),
  prevDay: () => navigate(-1),
  nextDay: () => navigate(1),
  prevWeek: () => navigate(-7),
  nextWeek: () => navigate(7),
  cycleView,
  // Number keys map onto the visible roster (1-4 on mobile, 1-6 on desktop);
  // keys past the roster fall through untouched.
  setView: (e) => {
    const v = rosterViews()[Number(e.key) - 1];
    if (!v) return false;
    setView(v);
    return true;
  },
  quickAdd: () => set({ quickAddOpen: true }),
  newEvent: () => set({ editor: { mode: 'create', draft: {} }, popover: null, detail: null }),
  openDetail: () => {
    if (!state.popover) return false;
    openDetail(state.popover.instanceId);
    return true;
  },
  editEvent: () => {
    const occ = focusedOcc();
    if (!occ || isFeedOcc(occ)) return false;
    set({ popover: null, detail: null, editor: { mode: 'edit', occ } });
    return true;
  },
  reschedule: () => {
    if (!state.popover) return false;
    enterReschedule(state.popover.instanceId);
    return true;
  },
  deleteEvent: () => {
    const occ = focusedOcc();
    if (!occ || isFeedOcc(occ)) return false;
    if (occ.recurring) {
      // Which occurrences is a decision (DeleteScope), asked on the open surface.
      set({ deletePrompt: occ.instanceId });
      return true;
    }
    if (googleBacked(occ)) {
      set({ deletePrompt: occ.instanceId }); // asks on the open surface, saying it cannot be undone
      return true;
    }
    if (window.confirm('Delete "' + (occ.title || 'this event') + '"?')) {
      set({ detail: null });
      deleteEvent(occ);
    }
    return true; // key consumed either way
  },
  detailPrev: () => (state.detail ? (stepDetailSameDay(-1), true) : false),
  detailNext: () => (state.detail ? (stepDetailSameDay(1), true) : false),
  search: () => set({ searchOpen: true }),
  filter: () => {
    const el = document.querySelector('.bc-filter-input');
    if (!el) return false;
    el.focus();
    return true;
  },
  shortcuts: () => set({ shortcutsOpen: true }),
  palette: () => set({ paletteOpen: !state.paletteOpen }),
  escape: () => closeOverlays(), // dispatched before the typing guard below
};

export function installKeyboard() {
  const onKey = (e) => {
    if (e.key === 'Escape') {
      if (handlers.escape()) e.preventDefault();
      return;
    }
    // Cmd/Ctrl bindings, and unlike every other one they must work while
    // typing — that is the point of a palette. Checked before both guards.
    if ((e.metaKey || e.ctrlKey) && !e.altKey) {
      const mod = HOTKEYS.find((h) => h.mod && h.keys.includes(e.key.toLowerCase()));
      const runMod = mod && handlers[mod.id];
      if (runMod && runMod(e) !== false) e.preventDefault();
      return;
    }
    if (isTyping() || e.altKey) return;
    const entry = HOTKEYS.find((h) => !h.mod && h.keys.includes(e.key));
    if (!entry) return;
    const run = handlers[entry.id];
    if (!run) return;
    if (run(e) !== false) e.preventDefault();
  };
  window.addEventListener('keydown', onKey);
  return () => window.removeEventListener('keydown', onKey);
}
