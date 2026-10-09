// Where you were, across a reload.
//
// A reload (a new version applying itself, or a phone discarding the app in
// the background) used to open on today in the default view: the place you
// were looking at, the view, the saved view and the filter text were gone.
// Now the page writes that down whenever it is hidden or about to unload, and
// the next boot in the same tab session opens there again. Drafts (the
// editor, quick add) already survive on their own (drafts.js).
//
// sessionStorage in a browser tab, like the drafts: it belongs to this tab's
// session, so a fresh launch still opens fresh. In the installed app,
// localStorage (0.9.1): a phone that kills the app in the background starts
// it again with a new session, and that is the case this exists for. Either
// way only for a while: coming back hours later should open on today, as a
// calendar normally does.
//
// 0.9.1 puts back exactly what was on screen: the page (and Settings
// section), an open event (and where it was opened from), an open search
// with its query, and the time of day in week and day view.

import { state, set } from './store.js';
import { applySavedView } from './actions.js';
import { splitDay } from '../lib/splitday.js';
import { setResumeTime } from '../lib/resumetime.js';
import { openOccurrence } from './push.js';
import { searchSnapshot, reopenSearch, primeSearchBack } from './SearchOverlay.js';
import { RESUME_KEY, clearResume } from './resume-storage.js';

const MAX_AGE_MS = 30 * 60000;

const installed = () => { try { return matchMedia('(display-mode: standalone)').matches; } catch { return false; } };
const store = () => { try { return installed() ? localStorage : sessionStorage; } catch { return null; } };

// The time of day on screen in week or day view.
function timeOfDay() {
  const sc = document.querySelector('.bc-tg-scroll');
  if (!sc) return null;
  if (sc.closest('.bc-tg-vstacked')) {
    let top = null;
    for (const p of sc.querySelectorAll('[data-vday]')) {
      if (p.offsetTop <= sc.scrollTop + 1) top = p;
      else break;
    }
    return top ? { day: top.dataset.vday, within: Math.round(sc.scrollTop - top.offsetTop) } : null;
  }
  return { within: Math.round(sc.scrollTop) };
}

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
  if (!state.authed) return;
  const onCalendar = state.route === 'calendar';
  const time = onCalendar ? timeOfDay() : null;
  const pop = onCalendar && state.popover ? state.popover : null;
  const occ = pop ? state.occ.get(pop.instanceId) : null;
  const search = searchSnapshot();
  const snap = {
    at: Date.now(),
    view: state.view,
    day: (time && time.day) || (onCalendar && ((state.view === 'split' && splitDay()) || dayAtAnchorLine())) || state.anchor,
    filterText: state.filterText || '',
    activeViewId: state.activeViewId || null,
    route: state.route || 'calendar',
    settingsTab: state.settingsTab || null,
    time,
    event: occ ? { instanceId: pop.instanceId, at: occ.start, dayKey: pop.dayKey || null, pin: pop.pin || null, backTo: pop.backTo || null } : null,
    search: search.open && search.q ? { q: search.q, peek: search.peek } : null,
    searchBack: pop && pop.backTo && pop.backTo.search && search.last ? search.last : null,
  };
  const st = store();
  try { if (st) st.setItem(RESUME_KEY, JSON.stringify(snap)); } catch { /* not durable here */ }
}

// --- why the app started (0.9.1) -------------------------------------------
//
// A short local log, shown in Settings, System: a new version applying itself
// (drafts.js marks it just before reloading), the browser discarding the page
// in the background, a reload, or a launch, which counts as "started again"
// when a fresh snapshot shows the app was in use minutes ago (the phone ended
// it in the background, or it was swiped away). Never sent anywhere.

const STARTS_KEY = 'bc-starts';
export const UPDATE_FLAG = 'bc-start-update';

export function recordStart() {
  let reason = 'launch';
  try {
    if (localStorage.getItem(UPDATE_FLAG)) { reason = 'update'; localStorage.removeItem(UPDATE_FLAG); }
  } catch { /* no storage: no log either */ }
  if (reason === 'launch') {
    const nav = (performance.getEntriesByType && performance.getEntriesByType('navigation')[0]) || null;
    if (document.wasDiscarded) reason = 'discarded';
    else if (nav && nav.type === 'reload') reason = 'reload';
    else if (hasFreshResume()) reason = 'restarted';
  }
  try {
    const list = JSON.parse(localStorage.getItem(STARTS_KEY) || '[]');
    list.unshift({ at: Date.now(), reason, installed: installed() });
    localStorage.setItem(STARTS_KEY, JSON.stringify(list.slice(0, 20)));
  } catch { /* fine */ }
}

export function recentStarts() {
  try { return JSON.parse(localStorage.getItem(STARTS_KEY) || '[]'); } catch { return []; }
}

/** A fresh snapshot exists (the app is coming back, not starting cold). */
export function hasFreshResume() {
  try {
    const st = store();
    const snap = JSON.parse((st && st.getItem(RESUME_KEY)) || 'null');
    const fresh = !!(snap && snap.at && Date.now() - snap.at <= MAX_AGE_MS);
    if (!fresh && st) st.removeItem(RESUME_KEY);
    return fresh;
  } catch {
    clearResume();
    return false;
  }
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
    const st = store();
    snap = JSON.parse((st && st.getItem(RESUME_KEY)) || 'null');
    if (st) st.removeItem(RESUME_KEY);
  } catch {
    clearResume();
    return false;
  }
  if (!snap || !snap.at || Date.now() - snap.at > MAX_AGE_MS) return false;
  const saved = snap.activeViewId && state.savedViews.find((v) => v.id === snap.activeViewId);
  // A saved view brings its calendars and Show choices back with it; the
  // place and the typed filter are then put back on top.
  if (saved) applySavedView(saved);
  if (snap.time) setResumeTime(snap.time);
  set({
    view: snap.view || state.view,
    anchor: snap.day || state.anchor,
    filterText: snap.filterText || '',
    scrollSeq: state.scrollSeq + 1,
    ...(snap.route && snap.route !== 'calendar' ? { route: snap.route, settingsTab: snap.settingsTab || null } : {}),
  });
  // A notification tapped to open the app names its own event; that wins.
  const linked = (() => { try { return new URLSearchParams(location.search).has('event'); } catch { return false; } })();
  if (linked) return true;
  if (snap.search) reopenSearch(snap.search.q, snap.search.peek);
  else if (snap.event) {
    const ev = snap.event;
    if (snap.searchBack) primeSearchBack(snap.searchBack.q, snap.searchBack.peek);
    // The event's window loads first (the notification link's path), then
    // the sheet or panel opens on it as it was: the day it was opened on,
    // and the way back to a trip or to search.
    openOccurrence(ev.instanceId, ev.at, { quiet: true }).then(() => {
      if (state.popover && state.popover.instanceId === ev.instanceId) {
        set({ popover: { ...state.popover, dayKey: ev.dayKey || state.popover.dayKey, pin: ev.pin || undefined, backTo: ev.backTo || undefined } });
      }
    }).catch(() => { /* gone since: the calendar is still where it was */ });
  }
  return true;
}
