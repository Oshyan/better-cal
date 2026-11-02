// A change to a whole series, through its plan (docs/design/series-model.md,
// Preview and Confirm). The change is asked for with `preview: true`, the
// dialog (SeriesDialog.js) shows the plan, and Confirm sends the same change
// with `plan: {hash, choices}`. Nothing is written until then.
//
// A drag waits for the dialog: its block stays where it was dropped, drawn
// as pending, and nothing else on the calendar moves. Cancel puts it back.
// A 409 series_plan_changed brings the new plan and asks again; a 422
// series_refused brings the plan with its reasons. "This event" and, until
// 1.5.1, "This and following" keep their old path (the caller's apply).

import { state, set, toast, patchOccurrence, restoreOccurrence, localSaved, invalidateRecords } from './store.js';
import { api, entriesOf, refreshWindow } from './api.js';
import { commitPlan, chosen, planRefusal } from '../lib/seriesplan.js';

let seq = 0;      // the newest request; an older answer is dropped
let dialogs = 0;  // each dialog's id, so an answer for a closed one is dropped

const NO_UNDO = "Can't be undone";
const GOOGLE_NO_UNDO = "Goes to Google; can't be undone";

/**
 * Whether the server works out series plans (/config `seriesPlans: true`).
 * Every `preview: true` request waits for it: a server without it would
 * take the preview for the change itself. Without it, every path is the
 * one from before 1.5.0.
 */
export function seriesPlans() {
  return !!(state.config && state.config.seriesPlans === true);
}

// The plan an error answer carries, wherever the envelope puts it.
export function planOfError(e) {
  const d = e && e.data;
  return (d && ((d.error && d.error.plan) || d.plan)) || null;
}

function google(occ) {
  const cal = state.calendars.find((c) => c.id === occ.calendarId);
  return !!(cal && cal.provider === 'google');
}

function current(id) {
  const sc = state.seriesChange;
  return sc && sc.id === id ? sc : null;
}

function update(id, patch) {
  const sc = current(id);
  if (sc) set({ seriesChange: { ...sc, ...patch } });
}

// The request for this change: its fields with the scope and the day acted on.
function requestBody(sc, extra) {
  return { ...sc.body, scope: 'all', instanceStart: sc.occ.start, ...extra };
}

/**
 * Open the dialog for a change to all events and ask for its plan.
 * kind: 'drag' | 'resize' | 'editor'. body: the change's fields. at: the
 * drop point {x, y} (desktop card), null for the editor. pending: for a
 * drag, {start, end} to draw the block at while it waits. apply(scope):
 * the old path for 'this' and 'following', resolving true when it worked.
 * onDone(): after a commit. quiet: open only once the plan says it isn't
 * direct (the editor: a rename no edited day owns saves at once).
 */
export function openSeriesChange({ kind, occ, body, at = null, pending = null, apply = null, onDone = null, fields = [], quiet = false }) {
  if (!occ || !seriesPlans()) return null;
  const id = ++dialogs;
  const before = pending ? patchOccurrence(occ.instanceId, { start: pending.start, end: pending.end, seriesPending: true }) : null;
  set({
    seriesChange: {
      id, kind, occ, body, at, before, apply, onDone, fields,
      title: occ.title || 'Untitled event',
      tz: occ.tzid || null,
      google: google(occ),
      scope: 'all',
      plan: null,
      choices: {},
      status: 'loading',
      changed: false,
      hidden: !!quiet,
    },
  });
  requestPlan(id);
  return id;
}

async function requestPlan(id) {
  const sc = current(id);
  if (!sc) return;
  const my = ++seq;
  const choices = Object.keys(sc.choices).length ? { plan: { choices: sc.choices } } : {};
  let plan = null;
  try {
    const res = await api('/events/' + sc.occ.eventId, { method: 'PATCH', body: requestBody(sc, { preview: true, ...choices }) });
    plan = res && res.plan;
  } catch (e) {
    if (my !== seq || !current(id)) return;
    plan = planOfError(e);
    if (!plan) { fail(id, e); return; }
  }
  if (my !== seq || !current(id)) return;
  if (!plan) { fail(id, new Error('No plan came back')); return; }
  const now = current(id);
  // A field change nothing else depends on: no question to ask.
  if (now.hidden && plan.direct && !(plan.groups || []).length && !planRefusal(plan)) {
    set({ seriesChange: { ...now, plan, status: 'ready' } });
    confirmSeriesChange();
    return;
  }
  set({ seriesChange: { ...now, plan, status: 'ready', hidden: false } });
}

// The change can't be made or previewed: the block goes back, the error shows.
function fail(id, e) {
  const sc = current(id);
  if (!sc) return;
  if (sc.before) restoreOccurrence(sc.occ.instanceId, sc.before);
  set({ seriesChange: null });
  const what = sc.kind === 'drag' ? 'Move' : sc.kind === 'resize' ? 'Resize' : 'Update';
  toast(what + ' failed: ' + (e && e.message ? e.message : 'unknown error'), { error: true });
}

/** A reading picked: the rest of the plan is for it, so ask again. */
export function pickSeriesOption(groupId, optionId) {
  const sc = state.seriesChange;
  if (!sc || sc.status === 'committing') return;
  if (sc.choices[groupId] === optionId) return;
  set({ seriesChange: { ...sc, choices: { ...sc.choices, [groupId]: optionId }, status: 'refreshing', changed: false } });
  requestPlan(sc.id);
}

/** The scope switch. "This and following" keeps its old path until 1.5.1. */
export function setSeriesScope(scope) {
  const sc = state.seriesChange;
  if (!sc || sc.status === 'committing' || sc.scope === scope) return;
  set({ seriesChange: { ...sc, scope } });
}

/** Confirm: the commit, with the plan's hash and an option for every group. */
export async function confirmSeriesChange() {
  const sc = state.seriesChange;
  if (!sc || sc.status === 'committing') return false;
  if (sc.scope === 'following') return useOldPath('following');
  const plan = sc.plan;
  if (sc.status !== 'ready' || !plan || !plan.hash || planRefusal(plan)) return false;
  const id = sc.id;
  set({ seriesChange: { ...sc, status: 'committing' } });
  try {
    const res = await api('/events/' + sc.occ.eventId, { method: 'PATCH', body: requestBody(sc, { plan: commitPlan(plan, sc.choices) }) });
    if (!current(id)) return false;
    if (sc.before) {
      // Drawn solid, and held so until an answer carries the change: the
      // solid copy is a save of its own, so nothing puts the pending one back.
      const solid = patchOccurrence(sc.occ.instanceId, { seriesPending: false });
      localSaved([sc.before, solid]);
    }
    set({ seriesChange: null });
    const verb = sc.kind === 'drag' ? 'Moved all events'
      : sc.kind === 'resize' ? 'Changed all events'
        : 'rrule' in sc.body ? 'Changed how it repeats' : 'Saved for all events';
    toast(verb + (sc.google ? ' at Google' : ''), {
      title: sc.body.title || sc.title,
      undo: entriesOf(res),
      note: sc.google ? GOOGLE_NO_UNDO : NO_UNDO,
    });
    invalidateRecords(sc.occ.eventId);
    refreshWindow();
    if (sc.onDone) sc.onDone(res);
    return true;
  } catch (e) {
    if (!current(id)) return false;
    const next = planOfError(e);
    if (next && (e.code === 'series_plan_changed' || e.code === 'series_plan_required')) {
      // The series changed since the plan was made (another tab, a sync):
      // the new plan, asked again.
      update(id, { plan: next, status: 'ready', changed: true, choices: chosenStill(next, current(id).choices) });
      return false;
    }
    if (next && e.code === 'series_refused') {
      update(id, { plan: next, status: 'ready' });
      return false;
    }
    fail(id, e);
    return false;
  }
}

// The person's picks that are still options in a new plan.
function chosenStill(plan, choices) {
  const keep = {};
  const now = chosen(plan, choices);
  for (const k of Object.keys(choices)) if (now[k] === choices[k]) keep[k] = choices[k];
  return keep;
}

/** Cancel: nothing written; a dragged block goes back. */
export function cancelSeriesChange() {
  const sc = state.seriesChange;
  if (!sc || sc.status === 'committing') return;
  seq++;
  if (sc.before) restoreOccurrence(sc.occ.instanceId, sc.before);
  set({ seriesChange: null });
}

/** "Only this one": the change applied to the one day, as "This event" does. */
export function onlyThisOne() {
  return useOldPath('this');
}

function useOldPath(scope) {
  const sc = state.seriesChange;
  if (!sc || sc.status === 'committing') return false;
  seq++;
  if (sc.before) restoreOccurrence(sc.occ.instanceId, sc.before);
  set({ seriesChange: null });
  return sc.apply ? sc.apply(scope) : false;
}
