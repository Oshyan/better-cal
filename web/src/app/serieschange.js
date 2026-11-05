// A change to a whole series, through its plan (docs/design/series-model.md,
// Preview and Confirm). The change is asked for with `preview: true`, the
// dialog (SeriesDialog.js) shows the plan, and Confirm sends the same change
// with `plan: {hash, choices}`. Nothing is written until then.
//
// A drag waits for the dialog: its block stays where it was dropped, drawn
// as pending, and nothing else on the calendar moves. Cancel puts it back.
// A 409 series_plan_changed brings the new plan and asks again; a 422
// series_refused brings the plan with its reasons. "This and following" is
// the same flow with scope 'following' (a split: its plan says what the
// events before the day become, then the part from it), and so are delete
// following and an answer from a day on, each through its own route.
// "This event" keeps its old path, from the scope chooser.

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

// The request for this change: its fields with the scope and the day acted on.
function requestBody(sc, extra) {
  return { ...sc.body, scope: sc.scope, instanceStart: sc.occ.start, ...extra };
}

// Where the change goes: PATCH the event, or the route the caller named
// (DELETE for delete following, POST .../attendance for an answer).
function send(sc, extra) {
  const route = sc.route || {};
  return api(route.path || '/events/' + sc.occ.eventId, { method: route.method || 'PATCH', body: requestBody(sc, extra) });
}

/**
 * Open the dialog for a change to all events and ask for its plan.
 * kind: 'drag' | 'resize' | 'editor'. body: the change's fields. pending:
 * for a drag, {start, end} to draw the block at while it waits.
 * apply(scope): the old path for 'this' and 'following', resolving true
 * when it worked. rescope(): back to the scope chooser ("Change scope":
 * the drop chip again after a drag, the editor's Apply to select). onDone():
 * after a commit. quiet: the panel stays hidden while the plan needs no one
 * (the editor: a rename no edited day owns saves at once); see receivePlan.
 * Every date and time in the panel is in the plan's zone (planZone), never
 * the occurrence's. scope: 'all' or 'following'; route: {method, path} for
 * a change that isn't a PATCH of the event (kind 'delete' or 'answer');
 * done: the toast's words after a commit.
 */
export function openSeriesChange({ kind, occ, body, pending = null, apply = null, rescope = null, onDone = null, fields = [], quiet = false, scope = 'all', route = null, done = null }) {
  if (!occ || !seriesPlans()) return null;
  const id = ++dialogs;
  const before = pending ? patchOccurrence(occ.instanceId, { start: pending.start, end: pending.end, seriesPending: true }) : null;
  set({
    seriesChange: {
      id, kind, occ, body, before, apply, rescope, onDone, fields,
      // The series' name, also when the change is made from an edited day
      // with a title of its own (its occurrence names its series).
      title: occ.seriesTitle || occ.title || 'Untitled event',
      google: google(occ),
      scope, route, done,
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
    const res = await send(sc, { preview: true, ...choices });
    plan = res && res.plan;
  } catch (e) {
    if (my !== seq || !current(id)) return;
    plan = planOfError(e);
    if (!plan) { fail(id, e); return; }
  }
  if (my !== seq || !current(id)) return;
  if (!plan) { fail(id, new Error('No plan came back')); return; }
  receivePlan(id, plan);
}

/** Whether a plan asks something of the person: it isn't direct, it has a choice, or it refuses something. */
const needsAttention = (plan) => !plan.direct || (plan.groups || []).length > 0 || (plan.refusals || []).length > 0;

// Every plan that arrives, from a preview or as a commit's 409 or 422, the
// same way: a quiet change stays hidden only while its plan needs no one,
// and is then committed; any plan that needs someone shows the panel, which
// stays shown from then on. A split from the series' first day that is the
// whole series' delete or answer (`whole`) needs no plan at all.
function receivePlan(id, plan, patch = {}) {
  const now = current(id);
  if (!now) return;
  const hidden = (now.hidden || !!plan.whole) && !needsAttention(plan);
  set({ seriesChange: { ...now, ...patch, plan, status: 'ready', hidden } });
  if (hidden) confirmSeriesChange();
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

/**
 * "Change scope": the preview cancelled (a dragged block goes back) and
 * the scope chooser again, where the change was asked for.
 */
export function changeSeriesScope() {
  const sc = state.seriesChange;
  if (!sc || sc.status === 'committing') return;
  cancelSeriesChange();
  if (sc.rescope) sc.rescope();
}

/** Confirm: the commit, with the plan's hash and an option for every group. */
export async function confirmSeriesChange() {
  const sc = state.seriesChange;
  if (!sc || sc.status === 'committing') return false;
  const plan = sc.plan;
  if (sc.status !== 'ready' || !plan || (!plan.hash && !plan.whole) || planRefusal(plan)) return false;
  const id = sc.id;
  set({ seriesChange: { ...sc, status: 'committing' } });
  try {
    const res = await send(sc, plan.whole ? {} : { plan: commitPlan(plan, sc.choices) });
    if (!current(id)) return false;
    if (sc.before) {
      // Drawn solid, and held so until an answer carries the change: the
      // solid copy is a save of its own, so nothing puts the pending one back.
      const solid = patchOccurrence(sc.occ.instanceId, { seriesPending: false });
      localSaved([sc.before, solid]);
    }
    set({ seriesChange: null });
    const which = sc.scope === 'following' ? 'this and following' : 'all events';
    const verb = sc.done || (sc.kind === 'drag' ? 'Moved ' + which
      : sc.kind === 'resize' ? 'Changed ' + which
        : 'rrule' in sc.body ? 'Changed how it repeats' : 'Saved for ' + which);
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
      receivePlan(id, next, { changed: true, choices: chosenStill(next, current(id).choices) });
      return false;
    }
    if (next && e.code === 'series_refused') {
      receivePlan(id, next);
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

/** "Only this one", a refused plan's action: the change applied to the one day, as "This event" does. */
export function onlyThisOne() {
  const sc = state.seriesChange;
  if (!sc || sc.status === 'committing') return false;
  seq++;
  if (sc.before) restoreOccurrence(sc.occ.instanceId, sc.before);
  set({ seriesChange: null });
  return sc.apply ? sc.apply('this') : false;
}
