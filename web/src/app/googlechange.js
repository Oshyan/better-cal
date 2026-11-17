// A change or delete of one event, or of one day of a repeating event, and
// what Google Calendar did to it since it was shown
// (docs/design/google-conflict-choice.md, slices 1 and 2). Every request that
// changes or deletes one event or one day goes out through sendEventChange:
//
// - a time is one value: start, end, all-day and zone together (timeOf). A
//   request that changes the time of an event in no series, or of one day
//   (scope 'this'), sends it whole, and `over` names it whole; a part is
//   never left for the server to fill from what it stores. A change to more
//   than one day of a repeating event (scope 'all' or 'following', or an
//   answer with no scope) keeps its own shape and sends no `over`;
// - it carries `over`, for each field the request changes, the value the app
//   showed (overOf: from the record or occurrence on screen, never from the
//   editor's form), so the server compares Google's value with what the
//   person saw, not with what a poll stored meanwhile;
// - a 409 google_changed means the server has stored what Google holds: the
//   event is fetched and shown as stored (adoptEvent) before the question
//   opens, so every view behind it shows what Google holds, and the
//   caller's own local copy (revert) is gone from view, not put back;
// - then the one question (googleChangedAsk in lib/planwords.js): Overwrite
//   sends the same request again with `over` set to what Google holds now
//   and each asked field's value to what the question showed as the
//   person's, and a second google_changed asks again with the newer values;
//   Cancel sends nothing, and what shows stays what was stored; an editor
//   open on the event or day keeps the person's fields and its identity
//   (sameItem), and its base becomes what the question showed (shownAfter),
//   so its next Save names the same event and day and goes through unless
//   Google changed again;
// - questions are asked one at a time, in the order they came
//   (ConfirmDialog.js confirmTurn): one that came while another was open
//   sends its request again when its turn comes, and asks from that answer,
//   so it never shows words that went stale while it waited;
// - a 200 that carries the event as saved (an event in no series) puts it in
//   place of every copy held before the caller goes on, so what shows after
//   an answer is the answer, not what a later refetch of the window brings;
// - a 200's `kept` goes on the caller's toast (keptOf; store.js toast says it).
//
// The setting is on as shipped; with it off for an account (an operator's
// switch) no answer carries google_changed or kept, and nothing here shows.

import { adoptEvent, state, set, toast } from './store.js';
import { api, ApiError, refreshWindow, afterUndo, undoFailedWords } from './api.js';
import { confirmTurn, askConfirm } from './ConfirmDialog.js';
import { googleChangedAsk } from '../lib/planwords.js';

/** What sendEventChange resolves to when the person chose Cancel at the question: nothing was sent. */
export const CANCELLED = Symbol('cancelled');

// The server's answer to a request from a page older than it (a time sent
// in part): 400, with a sentence that says to reload and make the change again.
const CLIENT_OUTDATED = 'client_outdated';

// The fields the server compares, and the request keys that change each.
// Planned or Maybe (`relationship`) is the event's status.
const FIELD_OF_KEY = {
  title: 'title', description: 'description', location: 'location', url: 'url',
  status: 'status', relationship: 'status', showAs: 'showAs', start: 'time', end: 'time', allDay: 'time', tzid: 'time',
};
// "Use series value" names a day's own fields (docs/design/series-model.md,
// Own Fields), not request keys: its date, time, length and all-day setting
// are all the time.
const FIELD_OF_OWN = {
  title: 'title', description: 'description', location: 'location', url: 'url', status: 'status', showAs: 'showAs',
  date: 'time', time: 'time', length: 'time', allDay: 'time',
};
// Free or busy (`showAs`) is served with the record only, not in the
// window's list shape: a copy from the window leaves it out of `over`, as
// it does the notes, and the server reads its base from what it stores.
const FIELDS = ['title', 'description', 'location', 'url', 'status', 'showAs', 'time'];
// The time's keys, as the API serves them for the event: one value.
// `floating` (same clock time in any zone) is a field of its own beside it,
// as the API writes it (api-contract.md, Floating times), never a part.
const TIME_KEYS = ['start', 'end', 'allDay', 'tzid'];

/**
 * A time as one value, {start, end, allDay, tzid}: the one builder of every
 * time the app sends, in a request and in `over`. `shown` is a time as the
 * person was shown it (an occurrence, a question's value); `change`, what
 * the change sets of it (a drag its start and end, the editor all of it).
 * Each part is the change's where it sets it, else the shown one: a drag
 * keeps the kind and the zone it was shown with. An all-day time is dates,
 * its zone UTC (api-contract.md, Time Zones), and an all-day time's zone is
 * no clock for a timed one, so a change of kind to timed carries its own
 * zone. Returns null when the time is not whole (a timed time with no zone):
 * never sent so.
 */
export function timeOf(shown, change = {}) {
  const from = shown || {};
  const has = (k) => k in change && change[k] !== undefined;
  const part = (k) => (has(k) ? change[k] : from[k]);
  const allDay = !!part('allDay');
  const tzid = allDay ? 'UTC' : (has('tzid') ? change.tzid : (from.allDay ? undefined : from.tzid));
  const t = { start: part('start'), end: part('end'), allDay, tzid };
  return t.start && t.end && t.tzid ? t : null;
}

const touchesTime = (body) => TIME_KEYS.some((k) => k in (body || {}));

/**
 * Whether a request is compared with what was shown: a change or delete of
 * an event in no series, or of one day of a repeating event (scope 'this').
 * Anything else sent for a repeating event (all events, this and following,
 * an answer with no scope) keeps its own shape and sends no `over`.
 */
export const comparedOne = (occ, body) => !!occ && (!occ.recurring || (body || {}).scope === 'this');

/** The fields a request changes: its keys' fields, and the fields "Use series value" names. */
const fieldsOf = (body) => [...new Set([
  ...Object.keys(body || {}).map((k) => FIELD_OF_KEY[k]),
  ...(Array.isArray((body || {}).useSeries) ? body.useSeries.map((n) => FIELD_OF_OWN[n]) : []),
].filter(Boolean))];

/**
 * The `over` of a request: for each field it changes (every field, for a
 * delete), the value the occurrence `occ` showed, under the keys the API
 * serves. An event in no series, or one day of a repeating event (its
 * occurrence shows the day's own values and time); none for a change to
 * more than one day (comparedOne). The time goes whole (timeOf), its four
 * keys. A field the occurrence doesn't hold (the notes of one shown from
 * the window, without its record) is left out, and the server reads its
 * base from what it stores.
 */
export function overOf(occ, body, { remove = false } = {}) {
  if (!comparedOne(occ, body)) return {};
  const fields = remove ? FIELDS : fieldsOf(body);
  const over = {};
  for (const f of fields) {
    if (f === 'time') Object.assign(over, shownTime(occ));
    else if (f in occ && occ[f] !== undefined) over[f] = occ[f];
  }
  return over;
}

/**
 * The time `occ` showed, whole. Every copy of an event the server serves
 * names its zone (`tzid`, list shape included), so the zone of what was
 * shown is on the copy that was shown, and is taken from nowhere else: a
 * timed copy without one is a fault in whatever made the copy, said as
 * such, and nothing is sent.
 */
function shownTime(occ) {
  const t = timeOf(occ);
  if (!t) throw new Error('This event was shown without its time zone; nothing was sent. Reload the page and make the change again.');
  return t;
}

/**
 * A request's body with its time whole (timeOf over the event or day as
 * shown), beside whatever else it carries (a day's scope and instanceStart
 * stay as they are). A change to more than one day keeps its own shape (a
 * change of the series' start alone means something to its plan).
 */
function wholeBody(occ, body) {
  if (!comparedOne(occ, body) || !touchesTime(body)) return body;
  shownTime(occ);
  const t = timeOf(occ, body);
  if (!t) throw new Error('The time of this change has no zone; nothing was sent.');
  return { ...body, ...t };
}

/**
 * Overwrite: the same request again, for each asked field said to have
 * seen what Google holds now (`over` from its `now`) and sending what the
 * question showed as the person's (the body's keys from its `yours`; a
 * time its four keys, whole, through timeOf), for each field the body
 * sets. A delete's `yours` is null, and "Use series value" sets no value
 * (it names fields): their bodies stay, and only `over` changes.
 * Everything else in the body (a scope and a day's instanceStart, tags) is
 * as it was.
 */
function overwriteOf(send, fields) {
  const body = { ...send };
  const sets = new Set(Object.keys(send).map((k) => FIELD_OF_KEY[k]).filter(Boolean));
  for (const f of fields || []) if (f.yours != null && sets.has(f.field)) Object.assign(body, valueOf(f, f.yours));
  return { ...body, over: { ...(send.over || {}), ...nowOf(fields) } };
}

// A field's value under the keys the API serves: a time whole (timeOf) from
// its object, as the question gave it; any other field under its own name.
const valueOf = (f, v) => (f.field === 'time' ? timeOf(v) || {} : { [f.field]: v });

/** What a question showed as Google's, under the keys the API serves: each asked field's `now`. */
function nowOf(fields) {
  const out = {};
  for (const f of fields || []) Object.assign(out, valueOf(f, f.now));
  return out;
}

/**
 * The event `occ` as the person has been shown it once a question showed
 * `fields`: each asked field as Google holds it now, the rest as `occ` had
 * it. Overwrite's `over` is the same values (overwriteOf).
 */
export function shownAfter(occ, fields) {
  const after = { ...occ, ...nowOf(fields) };
  // Planned or Maybe is shown as the event's relationship, which on one of
  // my calendars is its status (Events::relationship): the editor reads it
  // from there, so the status the question showed is shown there too.
  if (after.status !== occ.status) after.relationship = after.status === 'tentative' ? 'maybe' : 'planned';
  return after;
}

/**
 * Whether two copies are of the same thing being changed: its identity,
 * which event and, for a repeating event, which day (the id a request goes
 * to and the day's instanceStart, its start as the copy was given). A
 * single event's identity is its id alone. An editor's identity is fixed
 * for its opening (state.editor.occ); what it has been shown is its base
 * (editorBase), and only a question moves that.
 */
export const sameItem = (a, b) => !!a && !!b && a.eventId === b.eventId && (!a.recurring || a.start === b.start);

/**
 * An editor open on the event or day when a question about it shows: its
 * form stays the person's (nothing here touches it; EditorDrawer seeds once
 * per opening, lib/editfields.js sameOpening), and what it has been shown,
 * the base its next Save sends as `over` (editorBase), takes the question's
 * values. Its identity stays: the next Save names the same event and day.
 */
function editorShown(occ, fields) {
  const ed = state.editor;
  if (!ed || !sameItem(ed.occ, occ)) return;
  set({ editor: { ...ed, shown: shownAfter(ed.shown || ed.occ, fields) } });
}

const askedFields = (e) => (e && e.code === 'google_changed' && e.data && e.data.error && Array.isArray(e.data.error.fields) && e.data.error.fields.length
  ? e.data.error.fields : null);
// The day a question is about, as it was shown ({start, end, allDay, tzid}):
// only a question about one day of a repeating event carries one.
const askedDay = (e) => (e.data.error.day && e.data.error.day.start ? e.data.error.day : null);

/**
 * What the server stored when it asked, shown: the event fetched again and
 * put in place of every copy held here, after `revert` takes the caller's
 * local change off (a drag's moved block, a Maybe's mark). The window is
 * fetched again too. Never the copy from before the action: Google may
 * hold something else now. If the event can't be read, the window still
 * is, and shows what it holds. A day of a repeating event is not adopted
 * from its record (one record serves many days, each its own start). When
 * no record was put in place, the window's answer is what shows the event
 * as stored, so the question waits for it: a dragged day's block is where
 * Google Calendar has it once the question opens, never where it was
 * before the drag. With a record in place, the refetch only confirms.
 */
async function showStored(occ, revert) {
  let stored = null;
  try {
    const d = await api('/events/' + occ.eventId + '/occurrence');
    stored = d && d.occurrence && !d.occurrence.recurring ? d.occurrence : null;
  } catch { /* the window's answer shows it */ }
  if (revert) revert();
  if (stored) adoptEvent(stored);
  const refreshed = refreshWindow();
  if (!stored) await refreshed;
}

/**
 * Send one change or delete of the event or day `occ` names: `body` with
 * its `over`, from `base`, what the person was shown of it (the editor's
 * base after a question, editorBase), else `occ` itself. `occ` is the
 * identity (the event, and for a repeating event the day, the request is
 * about): it is what an open editor is matched by and what is shown again
 * at a question. Resolves to the answer, or CANCELLED when the server asked and the
 * person chose Cancel. At a question the event shows as the server stored
 * it before the question opens (showStored), whatever the answer. `back`:
 * the surface the change was made in stays open holding it (the editor, the
 * panel's time fields), so Cancel takes the person back to it. Any other
 * failure throws, as api() does; a google_changed without its fields throws
 * too, its message whole (failWords).
 */
export async function sendEventChange(occ, path, method, body = {}, { remove = false, revert = null, back = false, base = null } = {}) {
  const seen = base || occ;
  const whole = wholeBody(seen, body);
  const over = overOf(seen, whole, { remove });
  let send = Object.keys(over).length ? { ...whole, over } : whole;
  let shown = false;
  // The dialog's turn (ConfirmDialog.js confirmTurn): its place in line
  // taken when the server asks, held until the question is asked, and given
  // back on every way out.
  let turn = null;
  let inLine = null;
  try {
    for (;;) {
      let answer;
      try {
        answer = await api(path, { method, body: send });
      } catch (e) {
        // A page older than the server (a tab left open across an update)
        // sent a time the server no longer reads: the update banner's
        // Reload is offered (updates.js AppReloadBanner), the caller undoes
        // its local change as on any failure, and its toast says the
        // server's sentence whole (failWords). Nothing is sent again.
        if (e && e.code === CLIENT_OUTDATED) set({ appUpdateReady: true });
        const fields = askedFields(e);
        if (!fields) throw e;
        if (!turn) inLine = confirmTurn();
        await showStored(occ, shown ? null : revert);
        shown = true;
        editorShown(occ, fields);
        if (inLine) {
          turn = await inLine;
          inLine = null;
          // Another question was open or waiting when the server asked: by
          // now Google may hold something else, or the event may be gone.
          // The same request goes again with the dialog held, and its answer
          // decides: the question from what the server says now, the change
          // itself if Google holds again what was shown, or the failure.
          if (turn.waited) continue;
        }
        const ask = googleChangedAsk({ name: seen.title, fields, remove, back, day: askedDay(e) });
        const yes = await turn.ask(ask);
        turn = null; // the answer gave it back
        if (!yes) return CANCELLED;
        send = overwriteOf(send, fields);
        continue;
      }
      // The event as saved, when the answer carries it (PATCH of an event in
      // no series): in place of every copy held, as a change that landed, so
      // the caller's toast, a closing editor and the grid show the same thing
      // at once, and a refetch asked before it can't put the old copy back.
      // A change of the caller's still on its way (a drag's own copy) stands:
      // adoptEvent leaves the event to the refetch then.
      const saved = answer && answer.occurrence;
      if (saved && !saved.recurring) adoptEvent(saved, { saved: true });
      return answer;
    }
  } finally {
    if (turn) turn.release();
    if (inLine) inLine.then((t) => t.release());
  }
}

/**
 * Undo one Activity entry. The undo of an edit to one event or one day on a
 * Google calendar is a change like any (docs/design/google-conflict-choice.md,
 * "Slice 3a"): when a field it puts back was changed again since, the server
 * asks (409 google_changed) and the person chooses here, Cancel or Undo
 * anyway. Undo anyway sends the undo again with `over`, what the question
 * showed as it is now, so a still newer change asks again; there is no force
 * past a question. `force` is Activity's answer to its own stale question
 * and passes only that. Resolves to the answer, or CANCELLED; any other
 * refusal throws, as api() does. The window is fetched again when the
 * server asks: it stored what Google Calendar holds.
 */
export async function sendUndo(id, { force = false } = {}) {
  let body = force ? { force: true } : {};
  for (;;) {
    try {
      return await api('/activity/' + id + '/undo', { method: 'POST', body });
    } catch (e) {
      const fields = askedFields(e);
      if (!fields) throw e;
      refreshWindow();
      if (!await askConfirm(googleChangedAsk({ fields, day: askedDay(e), undo: true }))) return CANCELLED;
      body = { ...body, over: nowOf(fields) };
    }
  }
}

// A toast's Undo: just the entries its change made, newest first. One a
// later change stands on isn't overwritten from a toast: the log offers
// that, with a warning. A question (sendUndo) is asked here; Cancel stops
// with what was undone before it.
export async function undoEntries(ids) {
  try {
    const kept = [];
    let undone = 0;
    for (const id of [...ids].sort((a, b) => b - a)) {
      const answer = await sendUndo(id);
      if (answer === CANCELLED) break;
      undone += 1;
      kept.push(...(keptOf(answer) || []));
    }
    if (undone) toast('Undone', { kept: kept.length ? kept : null });
  } catch (e) {
    toast(e.code === 'stale_undo' ? 'Not undone: this was changed again after that. To undo it anyway and overwrite the newer change, use Undo on the Activity page.' : undoFailedWords(e), { error: true });
  }
  await afterUndo();
}

/** A 200's `kept`, for the caller's toast (store.js toast words it); null when nothing stayed. */
export const keptOf = (answer) => (answer && Array.isArray(answer.kept) && answer.kept.length ? answer.kept : null);

/** A failed change in words: a google_changed's or a client_outdated's own sentence whole (each says what happened and what to do), else the prefix and the error. */
export function failWords(prefix, e) {
  return e instanceof ApiError && (e.code === 'google_changed' || e.code === CLIENT_OUTDATED) ? e.message : prefix + (e && e.message);
}
