// Mutations with optimistic apply + rollback, and view/navigation actions.

import {
  state, set, toast, patchOccurrence, restoreOccurrence,
  removeOccurrencesOfEvent, mergeWindow, invalidateRecords,
} from './store.js';
import { api, refreshWindow, undo, loadCalendars, loadPeople, loadReviewCount } from './api.js';
import { adoptSettings } from './settings.js';
import { discardEditorWithUndo, clearQuickAddText } from './drafts.js';
import { localTz, todayKey, addDaysKey, occDayKey, epochDayOfKey, startMs, pad, parseISO, toISOWithOffset, addDaysDate } from '../lib/dates.js';
import { occurrenceDaySpan, shiftOccurrenceDays } from '../ui/monthmath.js';
import { extendTripSpan } from '../ui/trips.js';

export const VIEWS = ['month', 'weeks3', 'weeks2', 'week', 'day', 'agenda'];

// The roster the toolbar actually shows: narrow viewports drop the multiweek
// views ([Overview] [Week] [Day] [Agenda]). v-cycle and the 1-6 keys follow
// this list, not the full VIEWS constant.
export function rosterViews() {
  return state.viewportNarrow ? VIEWS.filter((v) => v !== 'weeks3' && v !== 'weeks2') : VIEWS;
}

// Overview mode for the month slot: full month grid or the 3-day ribbon.
// Desktop (wide viewport) always renders the full month; the persisted
// overviewMode setting is mobile-only. The single desktop exception is the
// pure responsive fallback: when the calendar area itself is too narrow for
// 7 columns (viewAreaNarrow, fed by a ResizeObserver in App), the ribbon
// takes over automatically. Narrow viewports (<= 800px) honor the setting,
// defaulting to the 3-day ribbon when unset.
export function effectiveOverviewMode() {
  if (!state.viewportNarrow) {
    return state.viewAreaNarrow ? '3day' : 'month';
  }
  return state.settings.overviewMode === 'month' ? 'month' : '3day';
}

// Pick an overview mode from the mobile Overview dropdown: optimistic local
// apply, persisted through the settings endpoint (overviewMode).
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

// Where a jump should actually land, given how much of a date was asked for.
//
// Asking for a whole month and landing on its 1st frames the month badly: the
// grid centres the anchor's ROW, so the 1st sitting mid-view means most of
// what you see is the month before, and the toolbar label — which reports the
// dominant month across the visible rows — can legitimately name that earlier
// month. Anchoring mid-month instead frames the requested month and makes the
// label agree, because now it genuinely is the dominant one.
//
// Only for the grid views. In day/week/agenda "June" means the start of June,
// not the middle of it.
export function jumpAnchorFor(dayKey, granularity) {
  if (granularity !== 'month' && granularity !== 'year') return dayKey;
  if (!['month', 'weeks3', 'weeks2'].includes(state.view)) return dayKey;
  return dayKey.slice(0, 8) + '15';
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
      state.searchOpen || state.quickAddOpen || state.jumpOpen || state.shortcutsOpen ||
      state.createDrawer) {
    // An editor with entered data closes like anything else; the draft is
    // durable and the toast's Undo puts it back. No dialog to read.
    if (state.editor && state.editorDirty) {
      discardEditorWithUndo();
    }
    // Quick add says "Esc to cancel" and means it: a dismissed line must not
    // come back at the next boot (drafts.js restores whatever is still saved).
    if (state.quickAddOpen) clearQuickAddText();
    set({
      popover: null, detail: null, groupPopover: null, editor: null, expandedDay: null, deletePrompt: null, attendPrompt: null,
      searchOpen: false, quickAddOpen: false, jumpOpen: false, shortcutsOpen: false,
      editorDirty: false, createDrawer: null,
    });
    return true;
  }
  return false;
}

// Open the full detail view for one occurrence, replacing lighter overlays.
export function openDetail(instanceId) {
  set({ detail: { instanceId }, popover: null, groupPopover: null, expandedDay: null });
}

// Chronological list of one day's visible occurrences (all-day first).
// `anchorDay` pins the day being browsed: without it, stepping onto a
// multi-day event would re-derive the day from THAT event's start and walk
// off to another day mid-navigation. Membership is span-based (an event
// covering the day belongs to it), matching the day-expand list.
export function sameDayList(occ, anchorDay) {
  const dayKey = anchorDay || occDayKey(occ);
  const ed = epochDayOfKey(dayKey);
  const visible = new Set(state.calendars.filter((c) => c.visible).map((c) => c.id));
  const list = [];
  for (const o of state.occ.values()) {
    if (!visible.has(o.calendarId) && o.instanceId !== occ.instanceId) continue;
    if (o.attendance === 'hidden' && o.instanceId !== occ.instanceId) continue;
    const span = occurrenceDaySpan(o);
    if (epochDayOfKey(span.startKey) > ed || epochDayOfKey(span.endKey) < ed) continue;
    list.push(o);
  }
  list.sort((a, b) => {
    if (a.allDay !== b.allDay) return a.allDay ? -1 : 1;
    if (startMs(a) !== startMs(b)) return startMs(a) - startMs(b);
    return (a.title || '') < (b.title || '') ? -1 : (a.title || '') > (b.title || '') ? 1 : 0;
  });
  return list;
}

// Step the open detail view to the previous/next event of the same day.
export function stepDetailSameDay(dir) {
  if (!state.detail) return false;
  const occ = state.occ.get(state.detail.instanceId);
  if (!occ) return false;
  const dayKey = state.detail.dayKey || occDayKey(occ);
  const list = sameDayList(occ, dayKey);
  const i = list.findIndex((o) => o.instanceId === occ.instanceId);
  const target = i >= 0 ? list[i + dir] : null;
  if (!target) return false;
  set({ detail: { instanceId: target.instanceId, dayKey } });
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
  if (cal && !cal.editable) return;
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

// A write to an event on a Google calendar goes to Google and lands back
// from Google's answer; there is no local snapshot to undo to, and the
// global Undo would skip it and revert something ELSE. So those toasts say
// where the change went and do not offer Undo (server: Events::googleJournal).
export function googleBacked(occ) {
  const cal = state.calendars.find((c) => c.id === occ.calendarId);
  return !!(cal && cal.provider === 'google');
}
export const GOOGLE_NO_UNDO = 'Goes to Google; no undo here';

function scopeFields(occ, scope) {
  // Recurring edits from direct manipulation target this occurrence unless a
  // scope chip chose otherwise. instanceStart is the occurrence's own start
  // value (never derived from the opaque instanceId).
  return occ.recurring ? { scope: scope || 'this', instanceStart: occ.start } : {};
}

// After a move/resize lands, an event that now sits entirely outside its
// trip's span deserves a nudge: it silently stays attached otherwise, and
// both readings ("the trip runs longer than I thought" and "this left the
// trip") are common enough that neither should be assumed.
function maybePromptTripExit(occ, newStart, newEnd) {
  const link = occ.containers && occ.containers[0];
  if (!link) return;
  const trip = [...state.occ.values()].find((o) => o.isContainer && o.eventId === link.eventId);
  if (!trip) return;
  const s = parseISO(newStart).getTime();
  const e = parseISO(newEnd).getTime();
  const ts = parseISO(trip.start).getTime();
  const te = parseISO(trip.end).getTime();
  if (e > ts && s < te) return; // still overlaps the trip
  toast('"' + (occ.title || 'Event') + '" is now outside trip "' + (trip.title || '') + '"', {
    duration: 12000,
    actions: [
      {
        label: 'Extend trip',
        run: () => {
          const ext = extendTripSpan(trip, { ...occ, start: newStart, end: newEnd });
          if (!ext) return;
          api('/events/' + trip.eventId, { method: 'PATCH', body: { start: ext.start, end: ext.end } })
            .then(() => { toast('Trip extended', { undoable: true }); refreshWindow(); })
            .catch((err) => toast('Extend failed: ' + err.message, { error: true }));
        },
      },
      { label: 'Remove from trip', run: () => detachFromTrip(trip.eventId, occ) },
    ],
    dismissLabel: 'Keep',
  });
}

export async function moveEvent({ instanceId, newStart, newEnd, scope }) {
  const occ = state.occ.get(instanceId);
  if (!occ) return false;
  const before = patchOccurrence(instanceId, { start: newStart, end: newEnd, _optimistic: true });
  try {
    await api('/events/' + occ.eventId, {
      method: 'PATCH',
      body: { start: newStart, end: newEnd, ...scopeFields(occ, scope) },
    });
    patchOccurrence(instanceId, { _optimistic: false });
    toast(googleBacked(occ) ? 'Event moved at Google' : 'Event moved', { undoable: !googleBacked(occ) });
    refreshWindow();
    maybePromptTripExit(occ, newStart, newEnd);
    return true;
  } catch (e) {
    restoreOccurrence(instanceId, before);
    toast('Move failed: ' + e.message, { error: true });
    return false;
  }
}

export async function resizeEvent({ instanceId, newStart, newEnd, scope }) {
  const occ = state.occ.get(instanceId);
  if (!occ) return false;
  const before = patchOccurrence(instanceId, { start: newStart, end: newEnd, _optimistic: true });
  try {
    await api('/events/' + occ.eventId, {
      method: 'PATCH',
      body: { start: newStart, end: newEnd, ...scopeFields(occ, scope) },
    });
    patchOccurrence(instanceId, { _optimistic: false });
    toast(googleBacked(occ) ? 'Event resized at Google' : 'Event resized', { undoable: !googleBacked(occ) });
    refreshWindow();
    maybePromptTripExit(occ, newStart, newEnd);
    return true;
  } catch (e) {
    restoreOccurrence(instanceId, before);
    toast('Resize failed: ' + e.message, { error: true });
    return false;
  }
}

// The writable calendar a new event should land on when nothing else says
// otherwise. Visibility is part of "writable" here: falling back to the first
// local calendar in id order lands on whatever happens to be first, and if
// that one is switched off the event is created and instantly invisible.
export function defaultTargetCalendarId() {
  const wanted = state.settings.defaultCalendarId;
  const byId = wanted != null && state.calendars.find((c) => c.id === wanted);
  if (byId) return byId.id;
  const local = state.calendars.filter((c) => c.kind !== 'subscribed');
  return (local.find((c) => c.visible) || local[0] || {}).id;
}

export async function createEvent(fields) {
  const body = { tzid: localTz(), ...fields };
  try {
    const occ = await api('/events', { method: 'POST', body });
    if (occ && occ.instanceId) {
      state.occ.set(occ.instanceId, occ);
      set({ occVersion: state.occVersion + 1 });
    }
    // Never let a create disappear silently. Picking a hidden calendar is a
    // legitimate choice, so don't override it — just say where the event went
    // and offer the one click that makes it visible.
    const cal = occ && state.calendars.find((c) => c.id === occ.calendarId);
    if (cal && !cal.visible) {
      toast(`Created in ${cal.name}, which is hidden`, {
        actionLabel: 'Show it',
        onAction: () => toggleCalendarVisible(cal),
      });
    } else {
      toast('Event created', { undoable: true });
    }
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
    // Tags, people, reminders and attendance are local even on a Google event; only content edits go to Google.
    const wentToGoogle = googleBacked(occ) && Object.keys(fields).some((k) => !['tagNames', 'personIds', 'personNames', 'reminders'].includes(k));
    toast(wentToGoogle ? 'Event updated at Google' : 'Event updated', { undoable: !wentToGoogle });
    invalidateRecords(occ.eventId); // its description/reminders/rule may be what changed
    await refreshWindow();
    return true;
  } catch (e) {
    // Clearing the trip flag while events are still attached: the server
    // refuses (trip_has_members); explain the fix instead of echoing a code.
    const msg = e.code === 'trip_has_members'
      ? "This trip still has events attached. Remove the trip's events first"
      : 'Update failed: ' + e.message;
    toast(msg, { error: true });
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
    toast(googleBacked(occ) ? 'Event deleted at Google' : 'Event deleted', { undoable: !googleBacked(occ) });
    refreshWindow();
  } catch (e) {
    for (const r of removed) state.occ.set(r.instanceId, r);
    set({ occVersion: state.occVersion + 1 });
    toast('Delete failed: ' + e.message, { error: true });
  }
}

// Copy an event (or one occurrence of a series) onto another calendar as a
// new event; the original stays. Returns true when it worked.
export async function copyEventTo(occ, calendarId, scope) {
  const cal = state.calendars.find((c) => c.id === calendarId);
  if (!cal || !cal.editable) return false;
  try {
    const body = { calendarId, scope: occ.recurring ? (scope || 'all') : 'all' };
    if (occ.recurring && body.scope === 'this') body.instanceStart = occ.start;
    const created = await api('/events/' + occ.eventId + '/copy', { method: 'POST', body });
    toast('Copied "' + (created.title || occ.title) + '" to ' + cal.name + (cal.provider === 'google' ? ' (and Google)' : ''), { duration: 4000, undoable: cal.provider !== 'google' });
    refreshWindow();
    return true;
  } catch (e) {
    toast('Copy failed: ' + e.message, { error: true });
    return false;
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
export async function triageAttendance(occ, attendance, scope) {
  return setAttendance(occ, occ.attendance === attendance ? 'none' : attendance, scope);
}

// scope applies to a series: 'this' gives the occurrence its own row,
// 'following' splits the series, 'all' (default) is the whole series.
export async function setAttendance(occ, attendance, scope) {
  const before = patchOccurrence(occ.instanceId, { attendance });
  try {
    const body = { attendance };
    if (occ.recurring) { body.scope = scope || 'all'; body.instanceStart = occ.start; }
    await api('/events/' + occ.eventId + '/attendance', { method: 'POST', body });
    toast(ATTENDANCE_TOASTS[attendance] || 'Attendance updated', { undoable: true });
    // Hidden events leave the window payload on the next fetch, and a series
    // answer changes more than this one occurrence; refresh so the cache
    // agrees with the server (undo refreshes again to restore).
    if (attendance === 'hidden' || occ.recurring) refreshWindow();
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

// --- trips (container events, docs/design-containers.md) ---------------------
// Attach/detach are relationships, not edits: members keep their own
// calendar, colors and lifecycle. Every link change bumps tripLinksSeq (open
// trip detail views refetch their member list) and refreshes the window so
// occurrence containers/isContainer stay current.

function bumpTripLinks() {
  set({ tripLinksSeq: state.tripLinksSeq + 1 });
}

export async function attachToTrip(tripOcc, memberOcc, { silent } = {}) {
  try {
    await api('/events/' + tripOcc.eventId + '/links', {
      method: 'POST',
      body: { eventId: memberOcc.eventId },
    });
    if (!silent) toast('Added to ' + (tripOcc.title || 'trip'), { undoable: true });
    bumpTripLinks();
    refreshWindow();
    maybePromptExtendTrip(tripOcc, memberOcc);
    return true;
  } catch (e) {
    toast('Could not add to trip: ' + e.message, { error: true });
    return false;
  }
}

export async function detachFromTrip(tripEventId, memberOcc, { silent } = {}) {
  try {
    await api('/events/' + tripEventId + '/links/' + memberOcc.eventId, { method: 'DELETE' });
    if (!silent) toast('Removed from trip', { undoable: true });
    bumpTripLinks();
    refreshWindow();
    return true;
  } catch (e) {
    toast('Could not remove from trip: ' + e.message, { error: true });
    return false;
  }
}

// Span growth prompt (design decision #4): attaching an event outside the
// trip's dates asks before the span grows, never silently.
export function maybePromptExtendTrip(tripOcc, memberOcc) {
  const ext = extendTripSpan(tripOcc, memberOcc);
  if (!ext) return;
  toast('Extend trip to include this event?', {
    duration: 12000,
    actionLabel: 'Extend',
    dismissLabel: 'Keep',
    onAction: async () => {
      try {
        await api('/events/' + tripOcc.eventId, {
          method: 'PATCH',
          body: { start: ext.start, end: ext.end, ...(tripOcc.recurring ? { scope: 'all' } : {}) },
        });
        toast('Trip extended', { undoable: true });
        refreshWindow();
      } catch (e) {
        toast('Extend failed: ' + e.message, { error: true });
      }
    },
  });
}

// Open the trip detail for a container by its eventId (member "Part of" links
// carry {eventId, title}, not an instanceId). Trips overlap their members'
// dates, so the trip occurrence is normally already in the loaded window.
export function openTripByEventId(eventId) {
  for (const o of state.occ.values()) {
    if (o.eventId === eventId && o.isContainer) {
      openDetail(o.instanceId);
      return true;
    }
  }
  toast('That trip is outside the loaded date range');
  return false;
}

// "Remove trip only": deleting a trip detaches its members server-side; they
// survive on their own calendars.
export async function deleteTripOnly(occ) {
  const removed = removeOccurrencesOfEvent(occ.eventId);
  set({ detail: null });
  try {
    await api('/events/' + occ.eventId, { method: 'DELETE', body: {} });
    toast('Trip removed, its events kept', { undoable: true });
    bumpTripLinks();
    refreshWindow();
  } catch (e) {
    for (const r of removed) state.occ.set(r.instanceId, r);
    set({ occVersion: state.occVersion + 1 });
    toast('Delete failed: ' + e.message, { error: true });
  }
}

// "Delete trip and its N events": members first, then the trip (api-contract
// order), so repeated undo restores the trip first and then its events.
export async function deleteTripAndMembers(occ, members) {
  set({ detail: null });
  try {
    for (const m of members) {
      await api('/events/' + m.eventId, {
        method: 'DELETE',
        body: m.recurring ? { scope: 'all' } : {},
      });
      removeOccurrencesOfEvent(m.eventId);
    }
    await api('/events/' + occ.eventId, { method: 'DELETE', body: {} });
    removeOccurrencesOfEvent(occ.eventId);
    toast('Trip and its ' + members.length + (members.length === 1 ? ' event deleted' : ' events deleted'), { undoable: true });
    bumpTripLinks();
    refreshWindow();
  } catch (e) {
    toast('Delete failed: ' + e.message, { error: true });
    refreshWindow();
  }
}

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
    clearQuickAddText();
    set({ quickAddOpen: false });
    if (occ.instanceId) jumpToDate(occDayKey(occ), occ.instanceId);
  }
  return occ;
}

// --- drag-and-drop targets ----------------------------------------------------

// Drop an event on a sidebar calendar row: recategorize. Calendar membership
// is a series-level fact, so recurring events always move whole ('all').
// scope on a series: 'this' detaches the occurrence as its own event on the
// target, 'following' splits the series there, 'all' moves the series.
export async function moveEventToCalendar(occ, calendarId, scope) {
  const cal = state.calendars.find((c) => c.id === calendarId);
  const from = state.calendars.find((c) => c.id === occ.calendarId);
  // Not onto a feed, and not across the Google boundary in either direction
  // (a Google event stays Google's; a local one is not copied up).
  if (!cal || !cal.editable || cal.provider === 'google' || (from && from.provider === 'google') || occ.calendarId === calendarId) return;
  try {
    const body = { calendarId };
    if (occ.recurring) { body.scope = scope || 'all'; body.instanceStart = occ.start; }
    await api('/events/' + occ.eventId, { method: 'PATCH', body });
    const what = occ.recurring && body.scope === 'this' ? 'this occurrence of "' : occ.recurring && body.scope === 'following' ? 'the rest of "' : '"';
    toast('Moved ' + what + (occ.title || 'event') + '" to ' + cal.name, { undoable: true });
    refreshWindow();
  } catch (e) {
    toast('Move failed: ' + e.message, { error: true });
  }
}

// Link a person to an event (drop either onto the other). personNames is a
// full-replace field, so append to what the occurrence already carries.
export async function linkPersonToEvent(occ, person, scope) {
  const names = Array.isArray(occ.people) ? occ.people : [];
  const personName = typeof person === 'string' ? person : person.name;
  if (names.includes(personName)) {
    toast(personName + ' is already on this event');
    return;
  }
  // Send ids, not names: setting people by name would recreate any of this
  // event's existing names that have since been renamed, as a duplicate person.
  // occ.people is display text, so map it back through the directory.
  const byName = new Map(state.people.map((p) => [p.name.toLowerCase(), p.id]));
  const ids = names.map((n) => byName.get(n.toLowerCase())).filter((id) => id != null);
  const addId = typeof person === 'string' ? byName.get(personName.toLowerCase()) : person.id;
  if (addId == null) {
    toast('Could not find ' + personName + ' in your people', { error: true });
    return;
  }
  try {
    const body = { personIds: [...ids, addId] };
    if (occ.recurring) { body.scope = scope || 'all'; body.instanceStart = occ.start; }
    await api('/events/' + occ.eventId, { method: 'PATCH', body });
    toast('Added ' + personName + ' to "' + (occ.title || 'event') + '"', { undoable: true });
    refreshWindow();
  } catch (e) {
    toast('Link failed: ' + e.message, { error: true });
  }
}

// Resize an availability band from either end: shift just that edge of the
// underlying span by whole days, preserving its time of day.
export async function resizeAvailabilitySpanDays(spanId, startDeltaDays, endDeltaDays) {
  const sp = state.availSpans.find((x) => x.id === spanId);
  if (!sp || (!startDeltaDays && !endDeltaDays)) return;
  const shift = (iso, d) => toISOWithOffset(addDaysDate(parseISO(iso), d));
  try {
    await api('/people/' + sp.personId + '/availability/' + spanId, {
      method: 'PATCH',
      body: { start: shift(sp.start, startDeltaDays || 0), end: shift(sp.end, endDeltaDays || 0) },
    });
    toast('Adjusted ' + sp.name + "'s " + sp.kind + ' span', { undoable: true });
    set({ availSeq: state.availSeq + 1 });
  } catch (e) {
    toast('Resize failed: ' + e.message, { error: true });
  }
}

// Drag an availability band to another day: shift the underlying span by
// whole days, keeping its times and length.
export async function moveAvailabilitySpan(spanId, deltaDays) {
  const sp = state.availSpans.find((x) => x.id === spanId);
  if (!sp || !deltaDays) return;
  const shift = (iso) => toISOWithOffset(addDaysDate(parseISO(iso), deltaDays));
  try {
    await api('/people/' + sp.personId + '/availability/' + spanId, {
      method: 'PATCH',
      body: { start: shift(sp.start), end: shift(sp.end) },
    });
    toast('Moved ' + sp.name + "'s " + sp.kind + ' span', { undoable: true });
    set({ availSeq: state.availSeq + 1 });
  } catch (e) {
    toast('Move failed: ' + e.message, { error: true });
  }
}

// Drag a trip band: shift the container, optionally carrying every linked
// member with it (server-side, atomic, one undo entry — the client only sees
// members inside its loaded window, so it must not do the loop itself).
export async function moveTrip(occ, deltaDays, withMembers) {
  if (!deltaDays) return;
  // Trips are usually all-day, where a move is date arithmetic, never a Date.
  const { newStart, newEnd } = shiftOccurrenceDays(occ, deltaDays);
  try {
    const body = { start: newStart, end: newEnd };
    if (withMembers) body.moveMembers = true;
    await api('/events/' + occ.eventId, { method: 'PATCH', body });
    toast(withMembers ? 'Trip and its events moved' : 'Trip moved', { undoable: true });
    refreshWindow();
  } catch (e) {
    toast('Move failed: ' + e.message, { error: true });
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

// The "All calendars" group behaves like a folder for visibility purposes but
// has no row in the folders table, so it gets a reserved key of its own in
// folderVisibility. It covers EVERY calendar, matching what that section
// lists — including the ones also filed in a folder.
export const ALL_GROUP_ID = 'loose'; // stored key kept for saved preferences

function folderCalendars(folderId) {
  return folderId === ALL_GROUP_ID
    ? state.calendars.slice()
    : state.calendars.filter((c) => (c.folderIds || []).includes(folderId));
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
  // Every calendar belongs to the All group, plus any folders it is filed in.
  const ids = [ALL_GROUP_ID, ...(cal.folderIds || [])];
  const next = { ...state.folderVisibility };
  for (const fid of ids) {
    const visibleIds = folderCalendars(fid).filter((c) => c.visible).map((c) => c.id);
    next[fid] = { mode: 'custom', custom: visibleIds };
  }
  persistFolderVisibility(next);
}

// List density only: which rows the sidebar draws, never which calendars are
// on. Kept in settings so it survives a reload like the other list prefs.
export function setSidebarActiveOnly(on) {
  set({ settings: { ...state.settings, sidebarActiveOnly: !!on } });
  api('/settings', { method: 'PATCH', body: { sidebarActiveOnly: !!on } })
    .catch(() => { /* the preference still holds for this session */ });
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
  const nextVis = { ...state.folderVisibility, [folderId]: nextEntry };
  // The All group covers calendars that also live in folders, so a change
  // here moves rows those folders' own buttons are reporting on. Carry the
  // mode down rather than leaving a folder button claiming "All" over
  // calendars this just switched off.
  if (folderId === ALL_GROUP_ID) {
    for (const f of state.folders) {
      const inF = folderCalendars(f.id);
      if (inF.length === 0) continue;
      nextVis[f.id] = { mode, custom: inF.filter((c) => wantVisible(c)).map((c) => c.id) };
    }
  }
  persistFolderVisibility(nextVis);

  // Persist the flag flips; on failure reload to resync rather than tracking
  // per-calendar rollbacks across a batch.
  for (const c of changed) {
    api('/calendars/' + c.id, { method: 'PATCH', body: { visible: !c.visible } })
      .catch((e) => { toast('Failed: ' + e.message, { error: true }); loadCalendars(); });
  }
}

// RSVP to a mail-ingested invitation (docs/email-ingest.md).
export async function rsvpEvent(occ, answer) {
  try {
    const result = await api('/events/' + occ.eventId + '/rsvp', { method: 'POST', body: { answer } });
    patchOccurrence(occ.instanceId, { invite: { ...occ.invite, myPartstat: result.myPartstat } });
    loadReviewCount(); // an answered invitation leaves the Review queue
    toast(result.sent
      ? 'RSVP sent to the organizer'
      : 'RSVP recorded (no reply sent — organizer unknown or RSVP mail not configured)');
    return result;
  } catch (e) {
    toast('RSVP failed: ' + e.message, { error: true });
    return null;
  }
}

// --- people availability (docs/design-availability.md) ----------------------

// Toggle whether a person's away/busy spans render as calendar bands.
// Persisted server-side (round-trips to the People page).
export function togglePersonVisible(person) {
  const next = !person.showOnCalendar;
  set({
    people: state.people.map((p) => (p.id === person.id ? { ...p, showOnCalendar: next } : p)),
    availSeq: state.availSeq + 1,
  });
  api('/people/' + person.id, { method: 'PATCH', body: { showOnCalendar: next } })
    .catch((e) => { toast('Failed: ' + e.message, { error: true }); loadPeople(); });
}

// "Only this person": capture everyone's showOnCalendar, leave just the
// target on. Mirrors the calendar solo: real persisted PATCHes, original
// set restored on exit.
export function enterPeopleSolo(person) {
  const saved = Object.fromEntries(state.people.map((p) => [p.id, p.showOnCalendar]));
  set({
    peopleSolo: { personId: person.id, saved },
    people: state.people.map((p) => ({ ...p, showOnCalendar: p.id === person.id })),
    availSeq: state.availSeq + 1,
  });
  for (const p of state.people) {
    const want = p.id === person.id;
    if (saved[p.id] !== want) {
      api('/people/' + p.id, { method: 'PATCH', body: { showOnCalendar: want } }).catch(() => {});
    }
  }
}

export function exitPeopleSolo() {
  const solo = state.peopleSolo;
  if (!solo) return;
  set({
    peopleSolo: null,
    people: state.people.map((p) => ({ ...p, showOnCalendar: !!solo.saved[p.id] })),
    availSeq: state.availSeq + 1,
  });
  for (const p of state.people) {
    if (p.showOnCalendar !== !!solo.saved[p.id]) {
      api('/people/' + p.id, { method: 'PATCH', body: { showOnCalendar: !!solo.saved[p.id] } }).catch(() => {});
    }
  }
}

export async function addAvailabilitySpan(personId, fields) {
  const span = await api('/people/' + personId + '/availability', { method: 'POST', body: fields });
  set({ availSeq: state.availSeq + 1 });
  loadPeople().catch(() => {});
  return span;
}

export async function deleteAvailabilitySpan(personId, spanId) {
  await api('/people/' + personId + '/availability/' + spanId, { method: 'DELETE' });
  set({ availSeq: state.availSeq + 1 });
  loadPeople().catch(() => {});
}

// People folder visibility mode: All / None / Custom, mirroring the calendar
// folders' cycle. "None" is the important one — one click silences every
// availability indicator. Custom remembers the per-person selection made
// while in custom (session-scoped memory; the flags themselves persist).
export function peopleVisibilityMode() {
  const people = state.people;
  if (people.length === 0 || people.every((p) => !p.showOnCalendar)) return 'none';
  if (people.every((p) => p.showOnCalendar)) return 'all';
  return 'custom';
}

export function setPeopleVisibilityMode(next) {
  const current = peopleVisibilityMode();
  if (current === 'custom') {
    set({ peopleVisCustom: state.people.filter((p) => p.showOnCalendar).map((p) => p.id) });
  }
  let wantVisible;
  if (next === 'all') wantVisible = () => true;
  else if (next === 'none') wantVisible = () => false;
  else {
    const remembered = new Set(state.peopleVisCustom || []);
    if (remembered.size === 0) return; // nothing to restore
    wantVisible = (p) => remembered.has(p.id);
  }
  const changed = state.people.filter((p) => p.showOnCalendar !== wantVisible(p));
  if (changed.length === 0) return;
  set({
    people: state.people.map((p) => (wantVisible(p) === p.showOnCalendar ? p : { ...p, showOnCalendar: wantVisible(p) })),
    availSeq: state.availSeq + 1,
  });
  for (const p of changed) {
    api('/people/' + p.id, { method: 'PATCH', body: { showOnCalendar: !p.showOnCalendar } })
      .catch(() => { loadPeople(); });
  }
}
