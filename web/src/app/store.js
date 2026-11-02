// Tiny reactive store: single state object, shallow-merge set, subscribers.
// Components use useStore(selector, equals) to re-render only when their
// selected slice changes.

import { COMPACT_QUERY, COARSE_QUERY, PHONE_WIDE_QUERY } from '../lib/breakpoints.js';
import { useState, useEffect, useRef } from '../../vendor/index.js';
import { todayKey } from '../lib/dates.js';

const listeners = new Set();

// Which sidebar groups are folded (calendar folders, All calendars, People),
// kept per device across reloads (0.6.4): a phone and a desktop are set up
// differently, the way the Manage section already is ('bc-manage-collapsed').
const COLLAPSE_KEY = 'bc-sidebar-collapse';
function savedCollapse() {
  try {
    const v = JSON.parse(localStorage.getItem(COLLAPSE_KEY) || 'null');
    return v && typeof v === 'object' ? v : {};
  } catch { return {}; }
}
const collapse0 = savedCollapse();

export const state = {
  booted: false,
  serverState: null, // 'database_update' | 'maintenance': the server said so (503), see ServerNotice
  pendingHandoff: null, // a deep link or share that arrived signed out; run after sign-in
  authed: false,
  user: null,
  csrf: null,
  offlineReadOnly: false,

  calendars: [],
  folders: [],
  tags: [],
  collapsedFolders: collapse0.folders && typeof collapse0.folders === 'object' ? collapse0.folders : {},
  editorDirty: false, // editor drawer has unsaved entered data (confirm on close)
  collapsedAllCals: !!collapse0.allCals,
  collapsedPeople: !!collapse0.people,

  // People directory (sidebar folder, editor autocomplete, People page).
  people: [],         // [{id, name, notes, showOnCalendar, eventCount, currentSpan, nextSpan, ...}]
  peopleFocus: null,  // person name to auto-expand when the People page opens
  organizeFocus: null, // calendar id whose settings open on Calendars & folders
  // Generalized "New <thing>" slide-in (CreateDrawer):
  // {kind: 'calendar'|'subscribe'|'import'|'folder'|'person', folderId?, url?}
  createDrawer: null,
  quickAddSeed: null,      // text to prefill quick add with on next open (share target)
  pendingImportFile: null, // File handed over by the OS (PWA file_handlers)
  peopleSolo: null,   // {personId, saved: {id: bool}} while "only this person" is active
  peopleVisCustom: null, // remembered per-person selection for the Custom visibility mode
  availSpans: [],
  plugins: [],        // ops listing from GET /plugins
  pluginRanges: {},   // plugin id -> {truncated, ranges: [...]} for the loaded window
  pluginSeq: 0,       // bump to force a plugin ranges/list refetch
  pluginFocus: null,  // plugin id whose settings the Plugins page opens on arrival
  pluginCalendarFocus: null, // one of its calendars to open there instead
  confirmAsk: null,   // the open "are you sure?" (ConfirmDialog.js), or null
  activitySeq: 0,     // bumped by the change poll; an open Activity page adds new entries
  reviewCount: 0,     // everything awaiting a decision, including paused calendar updates (sidebar badge)
  undoSeq: 0,         // bumped by an undo: pages that keep their own list reload it (lists.js)
  systemHealth: null, // GET /system/health: {rows, emailAlerts}; Settings panel, boot notices, device banner
  updates: null,      // checked release metadata and this user's banner decision
  appUpdateReady: false, // a new version is active behind this page: the reload banner (updates.js)
  availSeq: 0,        // visible people's availability spans refetch trigger        // bumped on span/visibility changes to refetch availSpans

  // User settings (contract defaults until /me or /settings answers).
  // overviewMode null = unset: the device default applies (3day when the
  // viewport is narrow or the pointer is coarse, month otherwise).
  settings: {
    defaultView: 'month', weekStart: 'sun', timeFormat: '12',
    defaultCalendarId: null, theme: 'system', nlParseMode: 'smart',
    updateNotifications: 'all',
    sidebarActiveOnly: false,
    pluginHidden: {},
    overviewMode: null,
    reminderTimed: [{ minutes: 10 }], reminderAllDay: [{ daysBefore: 1, time: '18:00' }],
    homeLat: null, homeLng: null, homeLabel: null,
    mapStyle: 'streets-v2',
    // Desktop: tuck the sidebar away while an event's panel is open.
    panelTucksSidebar: true,
    // Month, 3-week and 2-week views: the day they go to sits a row or two
    // below the top ('context') or at the top ('top', #91).
    gridAnchor: 'context',
    monthRows: 'fixed',
  },
  // Public-safe server config (GET /config), fetched once at boot.
  config: { maptilerKey: null, placeSearch: null },
  // Folder visibility modes: {folderId: {mode: 'all'|'none'|'custom', custom: [calId]}}.
  // Mirrored server-side in settings under the folderVisibility key.
  folderVisibility: {},

  // Occurrence cache: instanceId -> occurrence. occVersion bumps on change
  // so memos can key off it cheaply.
  occ: new Map(),
  occVersion: 0,
  // [{start, end, gen}] ms epochs: each stretch held and the generation it
  // was asked for at (dataGeneration); fresh when that's the current one
  // (docs/design/loading-model.md).
  loadedRanges: [],
  // Event window loading as the view needs to show it: null (nothing to say),
  // {kind:'loading'} on a cold load, {kind:'failed', offline} when the view's
  // window failed and is owed a reload (reloads.js), or {kind:'limited',
  // message} when the requested range exceeded a server work budget and must
  // be narrowed rather than retried unchanged (api.js).
  windowStatus: null,

  view: 'month', // month | weeks3 | weeks2 | week | day | agenda | split
  // Responsive roster inputs (kept in sync by App via matchMedia listeners).
  viewportNarrow: typeof window !== 'undefined' && window.matchMedia
    ? window.matchMedia(COMPACT_QUERY).matches : false,
  coarsePointer: typeof window !== 'undefined' && window.matchMedia
    ? window.matchMedia(COARSE_QUERY).matches : false,
  // A phone turned sideways (lib/breakpoints.js PHONE_WIDE_QUERY).
  phoneWide: typeof window !== 'undefined' && window.matchMedia
    ? window.matchMedia(PHONE_WIDE_QUERY).matches : false,
  // What is actually showing, light or dark: the theme setting when pinned,
  // else the OS. settings.js keeps it current; the parts that pick colours
  // outside CSS (map tiles, calendar colour used as ink) read it.
  darkMode: typeof document !== 'undefined' && (document.documentElement.dataset.theme === 'dark'
    || (document.documentElement.dataset.theme !== 'light' && !!window.matchMedia
      && window.matchMedia('(prefers-color-scheme: dark)').matches)),
  // Responsive fallback flag: true when the calendar view area itself (not
  // the window) is too narrow for the 7-column month grid. Fed by a
  // ResizeObserver in App; on desktop it flips the month slot to the 3-day
  // ribbon with no user setting involved.
  viewAreaNarrow: false,
  anchor: todayKey(),
  scrollSeq: 0,
  // The scrollSeq whose navigation glides (actions.js glideTo); others jump.
  glideSeq: 0,
  // Current epoch minute (Date.now() / 60000, floored), bumped by the single
  // global tick installed in App. Views read it so time-relative styling
  // (past dim, active gold ring) and the now-line advance as time passes.
  nowMinute: Math.floor(Date.now() / 60000),
  visibleMonth: null, // {year, month}
  visibleDays: null, // {start, end} day keys on screen in the month grid (MiniMonth keeps the selected day in view)
  anchorSeen: -1, // the scrollSeq after which the view showed the anchor (actions.js hereKey)
  filterText: '',
  agendaShowPast: false,
  agendaSort: 'time', // 'time' | 'match' (rank score, PRD 5.8)
  // Which phone bottom-bar sheet is open: 'view' | 'filter' | 'new' | null.
  phoneSheet: null,

  savedViews: [],     // [{id, name, config, position}]
  activeViewId: null, // saved view currently applied, if any

  // Bumped after every trip link change (attach/detach/trip delete) so open
  // trip detail views refetch their member list.
  tripLinksSeq: 0,

  route: 'calendar', // calendar | organize | outfeeds | filters | views | settings
  // Settings is one page with tabs. It opens on General unless something
  // sent you to a particular tab (a boot notice, the OAuth callback, a
  // /settings/<tab> link, handoff.js), and the page resets it on leaving.
  settingsTab: null, // Settings section; null = General on a desktop, the section list on a phone (0.8.0)
  settingsFocus: null, // a part of the section to scroll to on arrival ('cron', from the stopped-worker banner)

  quickAddOpen: false,
  quickAddWindow: false, // quick add on its own, the extension's key (/new#window, QuickAddWindow.js)
  lastCreated: null, // {occ, message} for the newest event this page created (createEvent)
  paletteOpen: false, // command palette (Cmd/Ctrl-K)
  dropChoice: null,  // {x, y, title, options: [{label, value}], cb} — chip at a drop point
  pluginCard: null,  // {occ, anchorRect} — plugin band info card
  searchOpen: false,
  searchRestore: false,
  welcomeOpen: false, // the first-run welcome, reopened from Settings (0.9.2)
  welcomeLater: false, // put aside with Back on a phone until the next launch // reopen search with the results an event was opened from (0.7.3)
  jumpOpen: false,   // jump-to-date popover (toolbar date label / g)
  shortcutsOpen: false, // keyboard shortcuts cheat sheet (?)
  moveDialog: null, // {calendarId}: the Move to Google dialog (MoveToGoogle.js)
  popover: null,     // {instanceId, anchorRect}
  // Which relationships the views show (Relationship.js): a quick filter that
  // applies to every view, remembered per browser. Off = that kind is hidden.
  showRel: (() => {
    const all = { planned: true, maybe: true, available: true, context: true };
    try { return { ...all, ...(JSON.parse(localStorage.getItem('bc-show-rel') || '{}')) }; } catch { return all; }
  })(),
  deletePrompt: null, // instanceId of a recurring occurrence whose delete is asking which scope (DeleteScope)
  attendPrompt: null, // {instanceId, attendance} for a recurring occurrence whose triage is asking which scope
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

// Write the fold state when it changes (any path: the sidebar, a saved view).
let lastCollapse = JSON.stringify({ folders: state.collapsedFolders, allCals: state.collapsedAllCals, people: state.collapsedPeople });
let lastRefs = [state.collapsedFolders, state.collapsedAllCals, state.collapsedPeople];
listeners.add((st) => {
  if (st.collapsedFolders === lastRefs[0] && st.collapsedAllCals === lastRefs[1] && st.collapsedPeople === lastRefs[2]) return;
  lastRefs = [st.collapsedFolders, st.collapsedAllCals, st.collapsedPeople];
  const now = JSON.stringify({ folders: st.collapsedFolders, allCals: st.collapsedAllCals, people: st.collapsedPeople });
  if (now === lastCollapse) return;
  lastCollapse = now;
  try { localStorage.setItem(COLLAPSE_KEY, now); } catch { /* private mode: folds last for this visit */ }
});

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

// The fields the single-event record adds to a list occurrence, kept across
// window refreshes so an event you opened five minutes ago opens instantly
// again. Same list as api.js DETAIL_FIELDS; `full` marks the merge.
const RECORD_FIELDS = ['description', 'rrule', 'reminders', 'reminderSource', 'createdAt', 'updatedAt', 'tzid', 'uid', 'url', 'location', 'locationLat', 'locationLng', 'invite', 'styleJson'];

// Forget fetched record fields: for one event (after editing it), or for all
// of them (undo, or the change cursor moved: something changed and it could
// be any of them). The next open refetches; until then the list shape is
// shown, which is exactly what a first open shows.
//
// For all of them, the fields stay and are only marked stale: the next
// ensureFullOccurrence refetches and replaces them, and an open event view
// refetches at once (EventPopover). Stripping them meant a description or the
// event's own time zone vanished from an open view whenever anything changed
// on any device, until something happened to refetch it.
// Bumped whenever fetched records are marked out of date: a record asked
// for before then may hold what the change replaced (api.js).
let recordGen = 0;
export const recordGeneration = () => recordGen;

export function invalidateRecords(eventId = null) {
  recordGen++;
  if (eventId === null) {
    for (const [id, occ] of state.occ) {
      if (occ.full && !occ.stale) state.occ.set(id, { ...occ, stale: true });
    }
    return;
  }
  for (const [id, occ] of state.occ) {
    if (occ.full && occ.eventId === eventId) {
      const next = { ...occ };
      delete next.full;
      for (const k of RECORD_FIELDS) if (k in next && !(k in LIST_KEEP)) delete next[k];
      state.occ.set(id, next);
    }
  }
}
// Record fields that ALSO ride the list shape (when non-null); dropping them
// on invalidate would blank a location the list itself supplied.
const LIST_KEEP = { url: 1, location: 1, locationLat: 1, locationLng: 1, invite: 1, styleJson: 1 };
const OCCURRENCE_CACHE_MAX = 25000;

// The generation (docs/design/loading-model.md): this tab's name for the
// newest state of the user's events it knows the server has. A request is
// asked at the generation current then; what it brings is fresh only while
// that's still the current one. Moved by a save's refresh (api.js
// refreshWindow) and by the change check seeing the events part move
// (changecheck.js); everything held then stays held but is asked for again
// when next needed.
let generation = 0;
export const dataGeneration = () => generation;
export function newGeneration() { generation++; }

// Local changes not yet in a fetched answer (a drag, a resize, a Maybe, a
// delete): instanceId -> {until, start, end}, where the occurrence sits (or
// sat, for a removal). until is PENDING while its save is on its way, then
// the generation an answer must be asked at to carry it (localSaved). An
// answer asked earlier leaves the local copy, or its absence, alone: it
// would put the old version back, or bring a deleted one back to life (#183).
const PENDING = Infinity;
const local = new Map();
function markLocal(instanceId, occ) {
  local.set(instanceId, { until: PENDING, start: occ.start, end: occ.end, allDay: occ.allDay });
  recordGen++; // a record asked before this edit may hold what it replaced
}

/** These local changes' saves landed: an answer asked from the next generation carries them. */
export function localSaved(instanceIds) {
  for (const id of instanceIds) {
    const m = local.get(id);
    if (m && m.until === PENDING) m.until = generation + 1;
  }
}

// Overlap as the server judges it, by instants (Events.php window): an
// all-day date is stored and sent as UTC midnight (0.9.16), so its "+00:00"
// start and end are exactly the instants the server compares. Anything
// wider (by local days, say) drops events the answer never carried, for any
// window not on local midnights (#184).
function overlaps(occ, s, e) {
  const t = new Date(occ.start).getTime();
  const end = occ.end ? new Date(occ.end).getTime() : occ.allDay ? t + 86400000 : t;
  return t < e && end > s;
}

/**
 * Merge a window answer asked at generation `gen`. Dropped, returning
 * false, when any of its stretch is held at a newer generation: it's older
 * than what's there. Otherwise everything held overlapping the stretch is
 * replaced by the answer (or gone, deleted elsewhere), except local changes
 * it can't carry, and the stretch is held at `gen`.
 */
export function mergeWindow(startISO, endISO, events, gen = generation) {
  const s = new Date(startISO).getTime();
  const e = new Date(endISO).getTime();
  if (state.loadedRanges.some((r) => r.gen > gen && r.start < e && r.end > s)) return false;
  // A local change this answer carries ends here; one it can't stays.
  for (const [id, m] of local) if (m.until <= gen && overlaps(m, s, e)) local.delete(id);
  // Drop cached occurrences overlapping the window, then reinsert, carrying
  // fetched record fields over from the previous copy (invalidate first
  // when the refresh follows an edit of that event, see actions.js).
  // An event opened from outside the window (Activity, a notification, a
  // trip) can be on a hidden calendar, which the window never returns:
  // dropping it here left its open panel blank. It stays while it is open.
  const open = state.popover && state.popover.instanceId;
  const kept = open && state.occ.get(open)?._held && !events.some((ev) => ev.instanceId === open) ? open : null;
  const prior = new Map();
  for (const [id, occ] of state.occ) {
    if (id === kept || local.has(id)) continue;
    if (overlaps(occ, s, e)) {
      if (occ.full) prior.set(id, occ);
      state.occ.delete(id);
    }
  }
  for (const ev of events) {
    if (local.has(ev.instanceId)) continue;
    const p = prior.get(ev.instanceId);
    if (p) {
      const merged = { ...ev, full: true };
      if (p.stale) merged.stale = true; // carried fields still need their refetch
      for (const k of RECORD_FIELDS) if (k in p && !(k in ev)) merged[k] = p[k];
      state.occ.set(ev.instanceId, merged);
    } else {
      state.occ.set(ev.instanceId, ev);
    }
  }
  holdRange(s, e, gen);
  set({ occVersion: state.occVersion + 1 });
  return true;
}

// [s, e) is now held at gen, in place of whatever covered it before.
function holdRange(s, e, gen) {
  const out = [];
  for (const r of state.loadedRanges) {
    for (const piece of missingRanges(r.start, r.end, [{ start: s, end: e }])) out.push({ ...piece, gen: r.gen });
  }
  out.push({ start: s, end: e, gen });
  state.loadedRanges = mergeRanges(out);
}

// The view cache used to grow for the lifetime of the tab as someone scrolled
// through time. Every top-level render then mapped and sorted that complete
// history. Once the hard ceiling is crossed, keep the just-completed window
// (plus local changes not yet in an answer) and make older dates fetchable
// again. The server admits fewer rows than this for one window, so normal
// navigation retains its nearby cache and never reaches the fallback
// trimming loop. What stays marked loaded is what was held of the window.
export function pruneOccurrenceCache(startISO, endISO, {
  max = OCCURRENCE_CACHE_MAX,
  activeWindow = null,
} = {}) {
  if (activeWindow && (activeWindow.start !== startISO || activeWindow.end !== endISO)) return 'stale';
  if (state.occ.size <= max) return false;
  const s = new Date(startISO).getTime();
  const e = new Date(endISO).getTime();
  let active = 0;
  for (const [id, occ] of state.occ) {
    if (local.has(id)) { active++; continue; }
    const a = new Date(occ.start).getTime();
    const b = new Date(occ.end).getTime();
    if (a < e && b > s) active++;
  }
  // Never silently evict part of a range and then call that range complete.
  // This is defensive against a stale/newer server with a looser response
  // cap; current servers admit far fewer than this in one client window.
  if (active > max) {
    for (const id of [...state.occ.keys()]) if (!local.has(id)) state.occ.delete(id);
    state.loadedRanges = [];
    set({ occVersion: state.occVersion + 1 });
    return 'limited';
  }
  for (const [id, occ] of state.occ) {
    if (local.has(id)) continue;
    const a = new Date(occ.start).getTime();
    const b = new Date(occ.end).getTime();
    if (!(a < e && b > s)) state.occ.delete(id);
  }
  state.loadedRanges = state.loadedRanges
    .map((range) => ({ start: Math.max(s, range.start), end: Math.min(e, range.end), gen: range.gen }))
    .filter((range) => range.end > range.start);
  set({ occVersion: state.occVersion + 1 });
  return 'pruned';
}

/** The stretch held around this instant, however recently each part of it was fetched; null when it isn't held. */
export function heldStretch(t) {
  const sorted = [...state.loadedRanges].sort((a, b) => a.start - b.start);
  let cur = null;
  for (const r of sorted) {
    if (cur && r.start <= cur.end) cur.end = Math.max(cur.end, r.end);
    else if (cur && cur.start <= t && cur.end > t) break;
    else cur = { start: r.start, end: r.end };
  }
  return cur && cur.start <= t && cur.end > t ? cur : null;
}

/** What's held and current: the loaded stretches asked for at this generation. Fetching asks for the rest. */
export function freshRanges() {
  return state.loadedRanges.filter((r) => r.gen === generation).map((r) => ({ start: r.start, end: r.end }));
}

/**
 * The parts of [s, e) not already covered by `have` (ms epochs).
 *
 * Scrolling asks for a fresh window centred on wherever the viewport landed,
 * so consecutive demands overlap almost completely — a five-month window
 * shifted by one month is 80% of what we just fetched. Asking only for the
 * gap turns each of those into roughly a single month, which is what keeps a
 * fling from queueing a dozen multi-thousand-occurrence responses and
 * starving the frame loop (GH #14).
 *
 * Pure and exported for tests: `have` is any list of {start, end}, and the
 * result is the ascending, non-overlapping remainder.
 */
export function missingRanges(s, e, have) {
  if (!(e > s)) return [];
  const sorted = [...have].filter((r) => r.end > s && r.start < e).sort((a, b) => a.start - b.start);
  const gaps = [];
  let cursor = s;
  for (const r of sorted) {
    if (r.start > cursor) gaps.push({ start: cursor, end: Math.min(r.start, e) });
    cursor = Math.max(cursor, r.end);
    if (cursor >= e) break;
  }
  if (cursor < e) gaps.push({ start: cursor, end: e });
  return gaps;
}

// Loaded stretches touching or overlapping at the same generation become one.
function mergeRanges(ranges) {
  const sorted = [...ranges].sort((a, b) => a.start - b.start);
  const out = [];
  for (const r of sorted) {
    const last = out[out.length - 1];
    if (last && r.start <= last.end && r.gen === last.gen) last.end = Math.max(last.end, r.end);
    else out.push({ ...r });
  }
  return out;
}

// Insert one occurrence fetched outside the window pipeline (notification
// deep links). Deliberately does NOT register a loaded range: the occurrence
// may come from an includeHidden fetch, and views must still do a full load
// for that range later.
// An occurrence fetched on its own, to open (see mergeWindow).
export function insertOccurrence(occ) {
  // Already on the calendar from a window: refresh its fields and keep it
  // there. Only one the windows don't hold (another stretch, or hidden by a
  // filter) is "held", shown just while its panel is open (App.js).
  const cur = state.occ.get(occ.instanceId);
  state.occ.set(occ.instanceId, cur && !cur._held ? { ...cur, ...occ } : { ...occ, _held: true });
  set({ occVersion: state.occVersion + 1 });
}

// edit: false for what isn't a change (an event's full record arriving,
// map coordinates found): those must not hold the occurrence against the
// next refresh (#184: hovering an event froze it until the next change).
// An edit is a local change until its save lands (localSaved) and an answer
// carries it, or it fails and restoreOccurrence puts back what was there.
export function patchOccurrence(instanceId, patch, { edit = true } = {}) {
  const occ = state.occ.get(instanceId);
  if (!occ) return null;
  const before = { ...occ };
  const next = { ...occ, ...patch };
  state.occ.set(instanceId, next);
  if (edit) markLocal(instanceId, next);
  set({ occVersion: state.occVersion + 1 });
  return before;
}

// A save that failed: what was there goes back. It's held against answers
// until one asked after the next change: an older answer could carry an
// earlier edit's save that hasn't landed either.
export function restoreOccurrence(instanceId, before) {
  if (before) state.occ.set(instanceId, before);
  else state.occ.delete(instanceId);
  if (before) local.set(instanceId, { until: generation + 1, start: before.start, end: before.end, allDay: before.allDay });
  else local.delete(instanceId);
  set({ occVersion: state.occVersion + 1 });
}

/** One occurrence taken off here, before its save: a local change until an answer carries it. */
export function removeOccurrence(instanceId) {
  const occ = state.occ.get(instanceId);
  if (!occ) return null;
  state.occ.delete(instanceId);
  markLocal(instanceId, occ);
  set({ occVersion: state.occVersion + 1 });
  return occ;
}

export function removeOccurrencesOfEvent(eventId) {
  const removed = [];
  for (const [id, occ] of state.occ) {
    if (occ.eventId === eventId) { removed.push(occ); state.occ.delete(id); markLocal(id, occ); }
  }
  set({ occVersion: state.occVersion + 1 });
  return removed;
}

let toastSeq = 0;
// opts: title (the item's name, a first line above text), undo (the
// journal entries its Undo undoes), error, duration, and an optional action prompt
// (actionLabel + onAction, with dismissLabel replacing the ✕ dismiss).
// How long a toast stays (0.6.2): ten seconds when it offers Undo, so there
// is time to read it and reach the button; six for a plain notice. The toast
// draws its countdown and leaves when that animation ends (Toasts.js); the
// timer below is the backstop for a browser that runs no animation.
// opts.undo: the journal entries the toast's change made (entriesOf on its
// request's answer); its Undo undoes exactly those, and shows only when
// there are some.
export function toast(text, opts = {}) {
  const id = ++toastSeq;
  const undo = Array.isArray(opts.undo) ? opts.undo : [];
  const duration = opts.duration || (undo.length ? 10000 : 6000);
  set({
    toasts: [...state.toasts, {
      id, text, duration,
      title: opts.title || null,
      undo,
      undoable: undo.length > 0,
      // Shown where Undo would be, for a write that has no undo (Google).
      note: opts.note || null,
      error: !!opts.error,
      actionLabel: opts.actionLabel || null,
      onAction: opts.onAction || null,
      // Multiple choices in one toast: [{label, run}]. Rendered before the
      // dismiss control; each dismisses the toast, then runs.
      actions: Array.isArray(opts.actions) ? opts.actions : null,
      dismissLabel: opts.dismissLabel || null,
    }],
  });
  setTimeout(() => {
    set({ toasts: state.toasts.filter((t) => t.id !== id) });
  }, duration + 1500);
  return id;
}

export function dismissToast(id) {
  set({ toasts: state.toasts.filter((t) => t.id !== id) });
}

// CalendarMeta map for bettercal-ui: {id: {color, name, visible}}.
export function calendarMeta() {
  const meta = {};
  // editable and provider are what the grids' drags check: whether an event
  // can be copied with Option/Alt or dropped onto a sidebar calendar or
  // person. Without them every event read as a feed's and those drops did
  // nothing (broken when Google write-through arrived). reschedulable says a
  // read-only calendar's events can still be moved (a plugin's). kind and pluginId
  // let an event from a calendar subscribed by web address wear its link.
  for (const c of state.calendars) {
    meta[c.id] = { color: c.color, name: c.name, visible: c.visible, editable: !!c.editable, reschedulable: !!c.reschedulable, provider: c.provider || null, kind: c.kind, pluginId: c.pluginId || null };
  }
  return meta;
}
