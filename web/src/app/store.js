// Tiny reactive store: single state object, shallow-merge set, subscribers.
// Components use useStore(selector, equals) to re-render only when their
// selected slice changes.

import { useState, useEffect, useRef } from '../../vendor/index.js';
import { todayKey } from '../lib/dates.js';

const listeners = new Set();

export const state = {
  booted: false,
  authed: false,
  user: null,
  csrf: null,

  calendars: [],
  folders: [],
  tags: [],
  collapsedFolders: {},
  editorDirty: false, // editor drawer has unsaved entered data (confirm on close)
  collapsedAllCals: false,
  collapsedPeople: false,

  // People directory (sidebar folder, editor autocomplete, People page).
  people: [],         // [{id, name, notes, showOnCalendar, eventCount, currentSpan, nextSpan, ...}]
  peopleFocus: null,  // person name to auto-expand when the People page opens
  // Generalized "New <thing>" slide-in (CreateDrawer):
  // {kind: 'calendar'|'subscribe'|'import'|'folder'|'person', folderId?, url?}
  createDrawer: null,
  quickAddSeed: null,      // text to prefill quick add with on next open (share target)
  pendingImportFile: null, // File handed over by the OS (PWA file_handlers)
  peopleSolo: null,   // {personId, saved: {id: bool}} while "only this person" is active
  peopleVisCustom: null, // remembered per-person selection for the Custom visibility mode
  availSpans: [],     // visible people's availability spans for the loaded window
  availSeq: 0,        // bumped on span/visibility changes to refetch availSpans

  // User settings (contract defaults until /me or /settings answers).
  // overviewMode null = unset: the device default applies (3day when the
  // viewport is narrow or the pointer is coarse, month otherwise).
  settings: {
    defaultView: 'month', weekStart: 'sun', timeFormat: '12',
    defaultCalendarId: null, theme: 'system', nlParseMode: 'smart',
    sidebarActiveOnly: false,
    overviewMode: null,
    reminderTimed: [{ minutes: 10 }], reminderAllDay: [{ daysBefore: 1, time: '18:00' }],
    homeLat: null, homeLng: null, homeLabel: null,
    mapStyle: 'streets-v2',
  },
  // Public-safe server config (GET /config), fetched once at boot.
  config: { maptilerKey: null },
  // Folder visibility modes: {folderId: {mode: 'all'|'none'|'custom', custom: [calId]}}.
  // Mirrored server-side in settings under the folderVisibility key.
  folderVisibility: {},

  // Occurrence cache: instanceId -> occurrence. occVersion bumps on change
  // so memos can key off it cheaply.
  occ: new Map(),
  occVersion: 0,
  loadedRanges: [], // [{start, end}] ms epochs, merged

  view: 'month', // month | weeks3 | weeks2 | week | day | agenda
  // Responsive roster inputs (kept in sync by App via matchMedia listeners).
  viewportNarrow: typeof window !== 'undefined' && window.matchMedia
    ? window.matchMedia('(max-width: 800px)').matches : false,
  coarsePointer: typeof window !== 'undefined' && window.matchMedia
    ? window.matchMedia('(pointer: coarse)').matches : false,
  // Responsive fallback flag: true when the calendar view area itself (not
  // the window) is too narrow for the 7-column month grid. Fed by a
  // ResizeObserver in App; on desktop it flips the month slot to the 3-day
  // ribbon with no user setting involved.
  viewAreaNarrow: false,
  anchor: todayKey(),
  scrollSeq: 0,
  // Current epoch minute (Date.now() / 60000, floored), bumped by the single
  // global tick installed in App. Views read it so time-relative styling
  // (past dim, active gold ring) and the now-line advance as time passes.
  nowMinute: Math.floor(Date.now() / 60000),
  visibleMonth: null, // {year, month}
  filterText: '',
  agendaShowPast: false,
  agendaSort: 'time', // 'time' | 'match' (rank score, PRD 5.8)

  savedViews: [],     // [{id, name, config, position}]
  activeViewId: null, // saved view currently applied, if any

  // Bumped after every trip link change (attach/detach/trip delete) so open
  // trip detail views refetch their member list.
  tripLinksSeq: 0,

  route: 'calendar', // calendar | organize | outfeeds | filters | views | settings

  quickAddOpen: false,
  searchOpen: false,
  jumpOpen: false,   // jump-to-date popover (toolbar date label / g)
  shortcutsOpen: false, // keyboard shortcuts cheat sheet (?)
  popover: null,     // {instanceId, anchorRect}
  detail: null,      // {instanceId} full event detail view (modal/sheet)
  groupPopover: null, // {group, anchorRect} near-duplicate group list
  editor: null,      // {mode, occ?, draft}
  expandedDay: null, // {dayKey, anchorRect}
  flashId: null,
  reschedule: null,  // {instanceId, grabbed} dedicated reschedule mode (PRD 5.9)

  toasts: [], // {id, text, undoable}
};

export function get() {
  return state;
}

export function set(patch) {
  Object.assign(state, typeof patch === 'function' ? patch(state) : patch);
  for (const fn of listeners) fn(state);
}

export function subscribe(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

export function useStore(selector, equals) {
  const sel = selector || ((s) => s);
  const eq = equals || ((a, b) => a === b);
  const [, force] = useState(0);
  const valRef = useRef(sel(state));
  valRef.current = sel(state);
  useEffect(() => {
    const check = (s) => {
      const next = sel(s);
      if (!eq(valRef.current, next)) {
        valRef.current = next;
        force((n) => n + 1);
      }
    };
    const unsub = subscribe(check);
    // State may have changed between render and effect flush (fast fetches
    // resolve before preact schedules effects); catch up immediately.
    check(state);
    return unsub;
  }, []); // eslint-disable-line
  return valRef.current;
}

export function shallowEq(a, b) {
  if (a === b) return true;
  if (!a || !b || typeof a !== 'object' || typeof b !== 'object') return false;
  const ka = Object.keys(a), kb = Object.keys(b);
  if (ka.length !== kb.length) return false;
  for (const k of ka) if (a[k] !== b[k]) return false;
  return true;
}

// --- occurrence cache helpers ----------------------------------------------

export function mergeWindow(startISO, endISO, events) {
  const s = new Date(startISO).getTime();
  const e = new Date(endISO).getTime();
  // Drop cached occurrences whose start falls in the window, then reinsert.
  for (const [id, occ] of state.occ) {
    const t = new Date(occ.start).getTime();
    if (t >= s && t < e && !occ._optimistic) state.occ.delete(id);
  }
  for (const ev of events) state.occ.set(ev.instanceId, ev);
  state.loadedRanges = mergeRanges([...state.loadedRanges, { start: s, end: e }]);
  set({ occVersion: state.occVersion + 1 });
}

export function rangeCovered(startISO, endISO) {
  const s = new Date(startISO).getTime();
  const e = new Date(endISO).getTime();
  return state.loadedRanges.some((r) => r.start <= s && r.end >= e);
}

function mergeRanges(ranges) {
  const sorted = [...ranges].sort((a, b) => a.start - b.start);
  const out = [];
  for (const r of sorted) {
    const last = out[out.length - 1];
    if (last && r.start <= last.end) last.end = Math.max(last.end, r.end);
    else out.push({ ...r });
  }
  return out;
}

// Insert one occurrence fetched outside the window pipeline (notification
// deep links). Deliberately does NOT register a loaded range: the occurrence
// may come from an includeHidden fetch, and views must still do a full load
// for that range later.
export function insertOccurrence(occ) {
  state.occ.set(occ.instanceId, occ);
  set({ occVersion: state.occVersion + 1 });
}

export function patchOccurrence(instanceId, patch) {
  const occ = state.occ.get(instanceId);
  if (!occ) return null;
  const before = { ...occ };
  state.occ.set(instanceId, { ...occ, ...patch });
  set({ occVersion: state.occVersion + 1 });
  return before;
}

export function restoreOccurrence(instanceId, before) {
  if (before) state.occ.set(instanceId, before);
  else state.occ.delete(instanceId);
  set({ occVersion: state.occVersion + 1 });
}

export function removeOccurrencesOfEvent(eventId) {
  const removed = [];
  for (const [id, occ] of state.occ) {
    if (occ.eventId === eventId) { removed.push(occ); state.occ.delete(id); }
  }
  set({ occVersion: state.occVersion + 1 });
  return removed;
}

let toastSeq = 0;
// opts: undoable, error, duration, and an optional action prompt
// (actionLabel + onAction, with dismissLabel replacing the ✕ dismiss).
export function toast(text, opts = {}) {
  const id = ++toastSeq;
  set({
    toasts: [...state.toasts, {
      id, text,
      undoable: !!opts.undoable,
      error: !!opts.error,
      actionLabel: opts.actionLabel || null,
      onAction: opts.onAction || null,
      dismissLabel: opts.dismissLabel || null,
    }],
  });
  setTimeout(() => {
    set({ toasts: state.toasts.filter((t) => t.id !== id) });
  }, opts.duration || 6000);
  return id;
}

export function dismissToast(id) {
  set({ toasts: state.toasts.filter((t) => t.id !== id) });
}

// CalendarMeta map for bettercal-ui: {id: {color, name, visible}}.
export function calendarMeta() {
  const meta = {};
  for (const c of state.calendars) meta[c.id] = { color: c.color, name: c.name, visible: c.visible };
  return meta;
}
