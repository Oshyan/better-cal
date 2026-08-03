// Global keyboard map. The bindings themselves live in hotkeys.js (one
// source of truth shared with the shortcuts cheat sheet); this file wires
// each entry id to its handler. A handler returning false means "not
// applicable right now" (wrong context): the key falls through untouched.
// Esc inside reschedule mode is handled by RescheduleOverlay (capture phase).

import { state, set } from './store.js';
import {
  rosterViews, setView, cycleView, goToday, navigate, closeOverlays,
  enterReschedule, openDetail, deleteEvent, stepDetailSameDay,
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
  const cal = state.calendars.find((c) => c.id === occ.calendarId);
  return cal ? cal.kind === 'subscribed' : occ.source === 'feed';
}

const handlers = {
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
  escape: () => closeOverlays(), // dispatched before the typing guard below
};

export function installKeyboard() {
  const onKey = (e) => {
    if (e.key === 'Escape') {
      if (handlers.escape()) e.preventDefault();
      return;
    }
    if (isTyping() || e.metaKey || e.ctrlKey || e.altKey) return;
    const entry = HOTKEYS.find((h) => h.keys.includes(e.key));
    if (!entry) return;
    const run = handlers[entry.id];
    if (!run) return;
    if (run(e) !== false) e.preventDefault();
  };
  window.addEventListener('keydown', onKey);
  return () => window.removeEventListener('keydown', onKey);
}
