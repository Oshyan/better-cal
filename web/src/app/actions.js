// Mutations with optimistic apply + rollback, and view/navigation actions.

import {
  state, set, toast, patchOccurrence, restoreOccurrence,
  removeOccurrencesOfEvent, mergeWindow,
} from './store.js';
import { api, refreshWindow, undo } from './api.js';
import { localTz, todayKey, addDaysKey, dayKeyOfISO } from '../lib/dates.js';

export const VIEWS = ['month', 'weeks3', 'weeks2', 'week', 'day', 'agenda'];

// --- navigation -------------------------------------------------------------

export function setView(view) {
  set({ view, scrollSeq: state.scrollSeq + 1 });
}

export function cycleView() {
  const i = VIEWS.indexOf(state.view);
  setView(VIEWS[(i + 1) % VIEWS.length]);
}

export function goToday() {
  set({ anchor: todayKey(), scrollSeq: state.scrollSeq + 1 });
}

export function navigate(days) {
  set({ anchor: addDaysKey(state.anchor, days), scrollSeq: state.scrollSeq + 1 });
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
  if (state.popover || state.editor || state.expandedDay || state.searchOpen || state.quickAddOpen) {
    set({ popover: null, editor: null, expandedDay: null, searchOpen: false, quickAddOpen: false });
    return true;
  }
  return false;
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

export { undo, refreshWindow };

// --- quick add ---------------------------------------------------------------

export async function quickAddParse(text) {
  const data = await api('/quickadd', { method: 'POST', body: { text, tz: localTz() } });
  return data.draft || null;
}

export async function quickAddCommit(text) {
  try {
    const data = await api('/quickadd', { method: 'POST', body: { text, tz: localTz(), commit: true } });
    if (data.event && data.event.instanceId) {
      state.occ.set(data.event.instanceId, data.event);
      set({ occVersion: state.occVersion + 1, quickAddOpen: false });
      toast('Event created: ' + (data.event.title || ''), { undoable: true });
      jumpToDate(occDayKey(data.event), data.event.instanceId);
      refreshWindow();
    } else {
      set({ quickAddOpen: false });
      toast('Event created', { undoable: true });
      refreshWindow();
    }
    return true;
  } catch (e) {
    toast('Quick add failed: ' + e.message, { error: true });
    return false;
  }
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
  set({
    view: VIEWS.includes(config.viewType) ? config.viewType : state.view,
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

// --- calendar mutations -------------------------------------------------------

export async function toggleCalendarVisible(cal) {
  const calendars = state.calendars.map((c) => (c.id === cal.id ? { ...c, visible: !c.visible } : c));
  set({ calendars });
  try {
    await api('/calendars/' + cal.id, { method: 'PATCH', body: { visible: !cal.visible } });
  } catch (e) {
    set({ calendars: state.calendars.map((c) => (c.id === cal.id ? { ...c, visible: cal.visible } : c)) });
    toast('Failed: ' + e.message, { error: true });
  }
}
