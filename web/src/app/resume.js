// Where you were, across a reload.
//
// A reload (a new version applying itself, or a phone discarding the app in
// the background) used to open on today in the default view: the place you
// were looking at, the view, the saved view and the filter text were gone.
// Now the page writes that down whenever it is hidden or about to unload, and
// the next boot in the same tab session opens there again. Drafts (the
// editor, quick add) already survive on their own (drafts.js).
//
// sessionStorage, like the drafts: it belongs to this tab's session, so a
// fresh launch still opens fresh. And only for a while: coming back hours
// later should open on today, as a calendar normally does.

import { state, set } from './store.js';
import { applySavedView } from './actions.js';

const KEY = 'bc-resume';
const MAX_AGE_MS = 30 * 60000;

// The day at the grid's anchor line: MonthGrid lands an anchor row 40% of
// the way down, so restoring this day puts the grid back where it was.
function dayAtAnchorLine() {
  const sc = document.querySelector('.bc-month-scroll');
  if (!sc) return null;
  const box = sc.getBoundingClientRect();
  const cell = sc.querySelector('.bc-cell');
  const rowH = cell ? cell.getBoundingClientRect().height : 0;
  if (!rowH || !box.height) return null;
  const y = box.top + Math.max(0, (box.height - rowH) * 0.4) + rowH / 2;
  let best = null;
  for (const c of sc.querySelectorAll('.bc-cell')) {
    const r = c.getBoundingClientRect();
    if (r.top <= y && y < r.bottom && (!best || r.left < best.left)) best = { left: r.left, day: c.dataset.day };
  }
  return best && best.day;
}

export function saveResume() {
  if (!state.authed || state.route !== 'calendar') return;
  const snap = {
    at: Date.now(),
    view: state.view,
    day: dayAtAnchorLine() || state.anchor,
    filterText: state.filterText || '',
    activeViewId: state.activeViewId || null,
  };
  try { sessionStorage.setItem(KEY, JSON.stringify(snap)); } catch { /* not durable here */ }
}

export function installResumeSaving() {
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') saveResume();
  });
  window.addEventListener('pagehide', saveResume);
}

// Called once at boot, after settings and saved views have loaded. Returns
// true when it put the app back where it was.
export function restoreResume() {
  let snap = null;
  try {
    snap = JSON.parse(sessionStorage.getItem(KEY) || 'null');
    sessionStorage.removeItem(KEY);
  } catch { return false; }
  if (!snap || !snap.at || Date.now() - snap.at > MAX_AGE_MS) return false;
  const saved = snap.activeViewId && state.savedViews.find((v) => v.id === snap.activeViewId);
  // A saved view brings its calendars and Show choices back with it; the
  // place and the typed filter are then put back on top.
  if (saved) applySavedView(saved);
  set({
    view: snap.view || state.view,
    anchor: snap.day || state.anchor,
    filterText: snap.filterText || '',
    scrollSeq: state.scrollSeq + 1,
  });
  return true;
}
