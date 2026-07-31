// Mutations with optimistic apply + rollback, and view/navigation actions.

import {
  state, set, toast, patchOccurrence, restoreOccurrence,
  removeOccurrencesOfEvent, mergeWindow,
} from './store.js';
import { api, refreshWindow, undo, loadCalendars } from './api.js';
import { adoptSettings } from './settings.js';
import { localTz, todayKey, addDaysKey, occDayKey, pad } from '../lib/dates.js';

export const VIEWS = ['month', 'weeks3', 'weeks2', 'week', 'day', 'agenda'];

// The roster the toolbar actually shows: narrow viewports drop the multiweek
// views ([Overview] [Week] [Day] [Agenda]). v-cycle and the 1-6 keys follow
// this list, not the full VIEWS constant.
export function rosterViews() {
  return state.viewportNarrow ? VIEWS.filter((v) => v !== 'weeks3' && v !== 'weeks2') : VIEWS;
}

// Overview mode for the month slot: full month grid or the 3-day ribbon.
// Unset (null) falls back to the device default: 3day on mobile-ish devices
// (coarse pointer or narrow viewport), month on desktop.
export function effectiveOverviewMode() {
  const m = state.settings.overviewMode;
  if (m === 'month' || m === '3day') return m;
  return (state.viewportNarrow || state.coarsePointer) ? '3day' : 'month';
}

// Pick an overview mode from the dropdown: optimistic local apply, persisted
// through the settings endpoint (overviewMode).
export function setOverviewMode(mode) {
  set({
    settings: { ...state.settings, overviewMode: mode },
    view: 'month',
    scrollSeq: state.scrollSeq + 1,
  });
  api('/settings', { method: 'PATCH', body: { overviewMode: mode } })
    .catch((e) => toast('Could not save view choice: ' + e.message, { error: true }));
}

// --- navigation -------------------------------------------------------------

export function setView(view) {
  set({ view, scrollSeq: state.scrollSeq + 1 });
}

export function cycleView() {
  const roster = rosterViews();
  const i = roster.indexOf(state.view);
  setView(roster[(i + 1) % roster.length]);
}

export function goToday() {
  set({ anchor: todayKey(), scrollSeq: state.scrollSeq + 1 });
}

export function navigate(days) {
  set({ anchor: addDaysKey(state.anchor, days), scrollSeq: state.scrollSeq + 1 });
}

// Toolbar chevron step, sized to the current view: month and agenda step by
// month, multiweek by its week count, week by week, day by day.
export function stepAnchor(dir) {
  const v = state.view;
  if (v === 'day') return navigate(dir);
  if (v === 'week') return navigate(7 * dir);
  if (v === 'weeks2') return navigate(14 * dir);
  if (v === 'weeks3') return navigate(21 * dir);
  const vm = state.visibleMonth ||
    { year: Number(state.anchor.slice(0, 4)), month: Number(state.anchor.slice(5, 7)) };
  const k = vm.year * 12 + (vm.month - 1) + dir;
  const y = Math.floor(k / 12);
  const m = ((k % 12) + 12) % 12 + 1;
  set({ anchor: y + '-' + pad(m) + '-01', scrollSeq: state.scrollSeq + 1 });
}

export function jumpToDate(dayKey, flashId) {
  set({
    anchor: dayKey,
    scrollSeq: state.scrollSeq + 1,
    searchOpen: false,
    flashId: flashId || null,
  });
  if (flashId) {
    // Give the view a moment to render the target, then flash it.
    setTimeout(() => {
      const el = document.querySelector(`[data-instance="${CSS.escape(flashId)}"]`);
      if (el) {
        el.classList.add('bc-flash');
        setTimeout(() => el.classList.remove('bc-flash'), 2100);
      }
      set({ flashId: null });
    }, 220);
  }
}

export function closeOverlays() {
  if (state.popover || state.detail || state.groupPopover || state.editor || state.expandedDay ||
      state.searchOpen || state.quickAddOpen || state.jumpOpen || state.shortcutsOpen) {
    set({
      popover: null, detail: null, groupPopover: null, editor: null, expandedDay: null,
      searchOpen: false, quickAddOpen: false, jumpOpen: false, shortcutsOpen: false,
    });
    return true;
  }
  return false;
}

// Open the full detail view for one occurrence, replacing lighter overlays.
export function openDetail(instanceId) {
  set({ detail: { instanceId }, popover: null, groupPopover: null, expandedDay: null });
}

// Chronological list of one day's visible occurrences (all-day first),
// anchored on occ's day. Shared by the detail view's prev/next chevrons and
// the [ ] hotkeys so both walk the same order.
export function sameDayList(occ) {
  const dayKey = occDayKey(occ);
  const visible = new Set(state.calendars.filter((c) => c.visible).map((c) => c.id));
  const list = [];
  for (const o of state.occ.values()) {
    if (!visible.has(o.calendarId) && o.instanceId !== occ.instanceId) continue;
    if (o.attendance === 'hidden' && o.instanceId !== occ.instanceId) continue;
    if (occDayKey(o) !== dayKey) continue;
    list.push(o);
  }
  list.sort((a, b) => {
    if (a.allDay !== b.allDay) return a.allDay ? -1 : 1;
    if (a.start !== b.start) return a.start < b.start ? -1 : 1;
    return (a.title || '') < (b.title || '') ? -1 : (a.title || '') > (b.title || '') ? 1 : 0;
  });
  return list;
}

// Step the open detail view to the previous/next event of the same day.
export function stepDetailSameDay(dir) {
  if (!state.detail) return false;
  const occ = state.occ.get(state.detail.instanceId);
  if (!occ) return false;
  const list = sameDayList(occ);
  const i = list.findIndex((o) => o.instanceId === occ.instanceId);
  const target = i >= 0 ? list[i + dir] : null;
  if (!target) return false;
  set({ detail: { instanceId: target.instanceId } });
  return true;
}

// --- reschedule mode (PRD 5.9) -----------------------------------------------

// Enter the dedicated reschedule mode for one occurrence: the event is
// immediately grabbed into a pointer-following ghost and the popover closes.
// Feed events are read-only upstream, so the mode only opens for local events.
// Recurring events are rescheduled as this occurrence only (scope "this",
// applied by moveEvent on confirm).
export function enterReschedule(instanceId) {
  const occ = state.occ.get(instanceId);
  if (!occ) return;
  const cal = state.calendars.find((c) => c.id === occ.calendarId);
  if (cal && cal.kind === 'subscribed') return;
  set({
    reschedule: { instanceId, grabbed: true },
    popover: null,
    detail: null,
    groupPopover: null,
    expandedDay: null,
    editor: null,
  });
}

export function exitReschedule() {
  if (state.reschedule) set({ reschedule: null });
}

// --- event mutations --------------------------------------------------------

function scopeFields(occ) {
  // Recurring edits from direct manipulation always target this occurrence.
  // instanceStart is the occurrence's own start value (never derived from
  // the opaque instanceId).
  return occ.recurring ? { scope: 'this', instanceStart: occ.start } : {};
}

export async function moveEvent({ instanceId, newStart, newEnd }) {
  const occ = state.occ.get(instanceId);
  if (!occ) return;
  const before = patchOccurrence(instanceId, { start: newStart, end: newEnd, _optimistic: true });
  try {
    await api('/events/' + occ.eventId, {
      method: 'PATCH',
      body: { start: newStart, end: newEnd, ...scopeFields(occ) },
    });
    patchOccurrence(instanceId, { _optimistic: false });
    toast('Event moved', { undoable: true });
    refreshWindow();
  } catch (e) {
    restoreOccurrence(instanceId, before);
    toast('Move failed: ' + e.message, { error: true });
  }
}

export async function resizeEvent({ instanceId, newStart, newEnd }) {
  const occ = state.occ.get(instanceId);
  if (!occ) return;
  const before = patchOccurrence(instanceId, { start: newStart, end: newEnd, _optimistic: true });
  try {
    await api('/events/' + occ.eventId, {
      method: 'PATCH',
      body: { start: newStart, end: newEnd, ...scopeFields(occ) },
    });
    patchOccurrence(instanceId, { _optimistic: false });
    toast('Event resized', { undoable: true });
    refreshWindow();
  } catch (e) {
    restoreOccurrence(instanceId, before);
    toast('Resize failed: ' + e.message, { error: true });
  }
}

export async function createEvent(fields) {
  const body = { tzid: localTz(), ...fields };
  try {
    const occ = await api('/events', { method: 'POST', body });
    if (occ && occ.instanceId) {
      state.occ.set(occ.instanceId, occ);
      set({ occVersion: state.occVersion + 1 });
    }
    toast('Event created', { undoable: true });
    refreshWindow();
    return occ;
  } catch (e) {
    toast('Create failed: ' + e.message, { error: true });
    return null;
  }
}

export async function updateEvent(occ, fields, scope) {
  const body = { ...fields };
  if (occ.recurring) {
    body.scope = scope || 'this';
    body.instanceStart = occ.start;
  }
  try {
    await api('/events/' + occ.eventId, { method: 'PATCH', body });
    toast('Event updated', { undoable: true });
    await refreshWindow();
    return true;
  } catch (e) {
    toast('Update failed: ' + e.message, { error: true });
    return false;
  }
}

export async function deleteEvent(occ, scope) {
  const removed = occ.recurring && (scope || 'this') === 'this'
    ? [state.occ.get(occ.instanceId)].filter(Boolean)
    : removeOccurrencesOfEvent(occ.eventId);
  if (occ.recurring && (scope || 'this') === 'this') {
    state.occ.delete(occ.instanceId);
    set({ occVersion: state.occVersion + 1 });
  }
  set({ popover: null, editor: null });
  try {
    const body = occ.recurring ? { scope: scope || 'this', instanceStart: occ.start } : {};
    await api('/events/' + occ.eventId, { method: 'DELETE', body });
    toast('Event deleted', { undoable: true });
    refreshWindow();
  } catch (e) {
    for (const r of removed) state.occ.set(r.instanceId, r);
    set({ occVersion: state.occVersion + 1 });
    toast('Delete failed: ' + e.message, { error: true });
  }
}

const ATTENDANCE_CYCLE = ['none', 'interested', 'going', 'hidden'];
const ATTENDANCE_TOASTS = {
  none: 'Attendance cleared',
  interested: 'Marked interested',
  going: 'Marked going',
  hidden: 'Event hidden',
};

export async function cycleAttendance(occ) {
  const next = ATTENDANCE_CYCLE[(ATTENDANCE_CYCLE.indexOf(occ.attendance || 'none') + 1) % ATTENDANCE_CYCLE.length];
  return setAttendance(occ, next);
}

// Triage toggle: clicking the active state resets to none.
export async function triageAttendance(occ, attendance) {
  return setAttendance(occ, occ.attendance === attendance ? 'none' : attendance);
}

export async function setAttendance(occ, attendance) {
  const before = patchOccurrence(occ.instanceId, { attendance });
  try {
    await api('/events/' + occ.eventId + '/attendance', { method: 'POST', body: { attendance } });
    toast(ATTENDANCE_TOASTS[attendance] || 'Attendance updated', { undoable: true });
    // Hidden events leave the window payload on the next fetch; refresh so
    // the cache agrees with the server (undo refreshes again to restore).
    if (attendance === 'hidden') refreshWindow();
    return attendance;
  } catch (e) {
    restoreOccurrence(occ.instanceId, before);
    toast('Failed: ' + e.message, { error: true });
    return null;
  }
}

// Thumbs feedback on feed events: pure training signal for ranking (PRD 5.8).
// Optimistic: toast immediately, fire-and-forget the write; no undo needed.
export function sendFeedback(occ, signal) {
  toast(signal === 'up' ? 'Noted: more like this' : 'Noted: less like this', { duration: 2500 });
  api('/events/' + occ.eventId + '/feedback', { method: 'POST', body: { signal } })
    .catch((e) => toast('Feedback failed: ' + e.message, { error: true }));
}

export { undo, refreshWindow };

// --- quick add ---------------------------------------------------------------

export async function quickAddParse(text) {
  const data = await api('/quickadd', { method: 'POST', body: { text, tz: localTz() } });
  return data.draft || null;
}

// Create from the quick-add card's structured strip (the strip is the source
// of truth, so edits always win over the parse). Jumps to and flashes the
// new event on success.
export async function quickAddCreate(fields) {
  const occ = await createEvent(fields);
  if (occ) {
    set({ quickAddOpen: false });
    if (occ.instanceId) jumpToDate(occDayKey(occ), occ.instanceId);
  }
  return occ;
}

// --- saved views --------------------------------------------------------------

// Snapshot the current mode. anchor collapses to "today" when the user is on
// today so applying the view later follows the calendar, not a stale date.
export function captureViewConfig() {
  return {
    viewType: state.view,
    visibleCalendarIds: state.calendars.filter((c) => c.visible).map((c) => c.id),
    folderCollapse: { ...state.collapsedFolders },
    filterText: state.filterText,
    anchor: state.anchor === todayKey() ? 'today' : state.anchor,
  };
}

// Modified check for the active view. anchor is deliberately excluded:
// navigating around inside a mode is not a change to the mode.
export function viewConfigMatches(config) {
  if (!config) return false;
  const cur = captureViewConfig();
  const ids = (a) => [...(a || [])].sort((x, y) => x - y).join(',');
  const collapse = (o) => Object.keys(o || {}).filter((k) => o[k]).sort().join(',');
  return cur.viewType === (config.viewType || 'month') &&
    ids(cur.visibleCalendarIds) === ids(config.visibleCalendarIds) &&
    collapse(cur.folderCollapse) === collapse(config.folderCollapse) &&
    (cur.filterText || '') === (config.filterText || '');
}

// Apply a saved view to the store: view type, calendar visibility (client
// state only), folder collapse, filter text, anchor.
export function applySavedView(view) {
  const config = view.config || {};
  let calendars = state.calendars;
  if (Array.isArray(config.visibleCalendarIds)) {
    const idSet = new Set(config.visibleCalendarIds);
    calendars = calendars.map((c) => (c.visible === idSet.has(c.id) ? c : { ...c, visible: idSet.has(c.id) }));
  }
  let viewType = VIEWS.includes(config.viewType) ? config.viewType : state.view;
  // Multiweek views are not in the mobile roster: fall back to Overview.
  if ((viewType === 'weeks2' || viewType === 'weeks3') && !rosterViews().includes(viewType)) {
    toast((viewType === 'weeks2' ? '2-week' : '3-week') + ' view is desktop-only');
    viewType = 'month';
  }
  set({
    view: viewType,
    calendars,
    collapsedFolders: { ...(config.folderCollapse || {}) },
    filterText: config.filterText || '',
    anchor: !config.anchor || config.anchor === 'today' ? todayKey() : config.anchor,
    activeViewId: view.id,
    scrollSeq: state.scrollSeq + 1,
  });
}

export async function saveViewAs(name) {
  try {
    const view = await api('/views', { method: 'POST', body: { name, config: captureViewConfig() } });
    set({ savedViews: [...state.savedViews, view], activeViewId: view.id });
    toast('View saved', { undoable: true });
    return view;
  } catch (e) {
    toast('Save failed: ' + e.message, { error: true });
    return null;
  }
}

export async function updateSavedView(view, fields) {
  try {
    const updated = await api('/views/' + view.id, { method: 'PATCH', body: fields });
    set({ savedViews: state.savedViews.map((v) => (v.id === view.id ? updated : v)) });
    toast('View updated', { undoable: true });
    return updated;
  } catch (e) {
    toast('Update failed: ' + e.message, { error: true });
    return null;
  }
}

export async function deleteSavedView(view) {
  try {
    await api('/views/' + view.id, { method: 'DELETE' });
    set({
      savedViews: state.savedViews.filter((v) => v.id !== view.id),
      activeViewId: state.activeViewId === view.id ? null : state.activeViewId,
    });
    toast('View deleted', { undoable: true });
  } catch (e) {
    toast('Delete failed: ' + e.message, { error: true });
  }
}

// --- settings -----------------------------------------------------------------

// Save one setting immediately (per-control, no debounce) and apply it
// client-side on success. scrollSeq bumps so grids re-anchor if week math
// changed while the calendar stayed mounted.
export async function saveSetting(key, value) {
  try {
    const data = await api('/settings', { method: 'PATCH', body: { [key]: value } });
    adoptSettings(data.settings || { [key]: value });
    set({ scrollSeq: state.scrollSeq + 1 });
    toast('Setting saved', { duration: 2000 });
    return true;
  } catch (e) {
    toast('Save failed: ' + e.message, { error: true });
    return false;
  }
}

// --- calendar mutations -------------------------------------------------------

export async function toggleCalendarVisible(cal) {
  const calendars = state.calendars.map((c) => (c.id === cal.id ? { ...c, visible: !c.visible } : c));
  set({ calendars });
  noteFolderCustomToggle(cal);
  try {
    await api('/calendars/' + cal.id, { method: 'PATCH', body: { visible: !cal.visible } });
  } catch (e) {
    set({ calendars: state.calendars.map((c) => (c.id === cal.id ? { ...c, visible: cal.visible } : c)) });
    toast('Failed: ' + e.message, { error: true });
  }
}

export async function updateCalendar(cal, fields) {
  try {
    const updated = await api('/calendars/' + cal.id, { method: 'PATCH', body: fields });
    set({ calendars: state.calendars.map((c) => (c.id === cal.id ? { ...c, ...updated } : c)) });
    toast('Calendar updated', { duration: 2500 });
    return updated;
  } catch (e) {
    toast('Failed: ' + e.message, { error: true });
    return null;
  }
}

export async function refreshCalendar(cal) {
  try {
    const res = await api('/calendars/' + cal.id + '/refresh', { method: 'POST' });
    toast('Feed refreshed: ' + ((res && res.imported) || 0) + ' events imported');
    await loadCalendars();
    refreshWindow();
    return true;
  } catch (e) {
    toast('Refresh failed: ' + e.message, { error: true });
    return false;
  }
}

export async function deleteCalendar(cal) {
  try {
    await api('/calendars/' + cal.id, { method: 'DELETE' });
    toast('Calendar deleted', { undoable: true });
    await loadCalendars();
    refreshWindow();
    return true;
  } catch (e) {
    toast('Delete failed: ' + e.message, { error: true });
    return false;
  }
}

// --- folders ------------------------------------------------------------------

export async function createFolder(name) {
  try {
    await api('/folders', { method: 'POST', body: { name } });
    toast('Folder created');
    await loadCalendars();
    return true;
  } catch (e) {
    toast('Failed: ' + e.message, { error: true });
    return false;
  }
}

export async function renameFolder(folder, name) {
  try {
    await api('/folders/' + folder.id, { method: 'PATCH', body: { name } });
    set({ folders: state.folders.map((f) => (f.id === folder.id ? { ...f, name } : f)) });
    toast('Folder renamed');
    return true;
  } catch (e) {
    toast('Failed: ' + e.message, { error: true });
    return false;
  }
}

// Delete only applies to empty folders; the caller checks and explains.
export async function deleteFolder(folder) {
  try {
    await api('/folders/' + folder.id, { method: 'DELETE' });
    toast('Folder deleted');
    await loadCalendars();
    return true;
  } catch (e) {
    toast('Failed: ' + e.message, { error: true });
    return false;
  }
}

// --- folder visibility modes (issue #6) ---------------------------------------
// Each folder has a visibility mode: all (every calendar shown), none (all
// hidden, folder stays present), custom (the user's own per-calendar set).
// All/none act by batch-setting the calendars' real visible flags, so every
// downstream consumer (event filtering, saved views) is untouched. The last
// custom selection is remembered per folder and restored on switching back.
// State shape, mirrored to the server under the folderVisibility settings key:
// {"<folderId>": {"mode": "all"|"none"|"custom", "custom": [calendarId,...]}}

export function folderMode(folderId) {
  const entry = state.folderVisibility[folderId];
  return (entry && entry.mode) || 'custom';
}

function folderCalendars(folderId) {
  return state.calendars.filter((c) => (c.folderIds || []).includes(folderId));
}

// Fire-and-forget server mirror. The backend allowlist may not accept the
// key yet; in that case the mode still works for the session, silently.
function persistFolderVisibility(next) {
  set({ folderVisibility: next });
  api('/settings', { method: 'PATCH', body: { folderVisibility: next } })
    .catch(() => { /* unknown_setting until the backend allowlists the key */ });
}

// An individual calendar toggle while a containing folder is in all/none mode
// flips that folder to custom; toggles in custom mode update the remembered
// set. Called with the calendar's pre-toggle object, after the store flip.
function noteFolderCustomToggle(cal) {
  if (!cal.folderIds || cal.folderIds.length === 0) return;
  const next = { ...state.folderVisibility };
  for (const fid of cal.folderIds) {
    const visibleIds = folderCalendars(fid).filter((c) => c.visible).map((c) => c.id);
    next[fid] = { mode: 'custom', custom: visibleIds };
  }
  persistFolderVisibility(next);
}

export function setFolderVisibilityMode(folderId, mode) {
  const cals = folderCalendars(folderId);
  const entry = state.folderVisibility[folderId] || { mode: 'custom', custom: null };
  const currentVisibleIds = cals.filter((c) => c.visible).map((c) => c.id);
  const nextEntry = { ...entry, mode };

  let wantVisible;
  if (mode === 'all' || mode === 'none') {
    // Leaving custom (or a never-recorded state): remember today's selection.
    if (entry.mode === 'custom' || entry.custom == null) nextEntry.custom = currentVisibleIds;
    wantVisible = () => mode === 'all';
  } else {
    const remembered = new Set(entry.custom == null ? currentVisibleIds : entry.custom);
    wantVisible = (c) => remembered.has(c.id);
  }

  const changed = cals.filter((c) => c.visible !== wantVisible(c));
  if (changed.length > 0) {
    const flip = new Map(changed.map((c) => [c.id, !c.visible]));
    set({ calendars: state.calendars.map((c) => (flip.has(c.id) ? { ...c, visible: flip.get(c.id) } : c)) });
  }
  persistFolderVisibility({ ...state.folderVisibility, [folderId]: nextEntry });

  // Persist the flag flips; on failure reload to resync rather than tracking
  // per-calendar rollbacks across a batch.
  for (const c of changed) {
    api('/calendars/' + c.id, { method: 'PATCH', body: { visible: !c.visible } })
      .catch((e) => { toast('Failed: ' + e.message, { error: true }); loadCalendars(); });
  }
}
