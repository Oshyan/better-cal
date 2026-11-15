// A change or delete of one event, and what Google Calendar did to it since
// it was shown (docs/design/google-conflict-choice.md, slice 1). Every request
// that changes or deletes one event goes out through sendEventChange:
//
// - a time is one value: start, end, all-day and zone together (timeOf). A
//   request that changes the time of an event in no series sends it whole,
//   and `over` names it whole; a part is never left for the server to fill
//   from what it stores;
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
//   open on the event keeps the person's fields, and its base becomes what
//   the question showed (shownAfter), so its next Save goes through unless
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

import { adoptEvent, state, set } from './store.js';
import { localTz } from '../lib/dates.js';
import { api, ApiError, refreshWindow } from './api.js';
import { confirmTurn } from './ConfirmDialog.js';
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
  status: 'status', relationship: 'status', start: 'time', end: 'time', allDay: 'time', tzid: 'time',
};
const FIELDS = ['title', 'description', 'location', 'url', 'status', 'time'];
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
 * The `over` of a request: for each field it changes (every field, for a
 * delete), the value the occurrence `occ` showed, under the keys the API
 * serves. Only an event in no series: a day of a repeating event shows its
 * own start, not the row's, and the server compares only single events.
 * The time goes whole (timeOf), its four keys. A field the occurrence
 * doesn't hold (the notes of one shown from the window, without its
 * record) is left out, and the server reads its base from the row.
 */
export function overOf(occ, body, { remove = false } = {}) {
  if (!occ || occ.recurring) return {};
  const fields = remove ? FIELDS : [...new Set(Object.keys(body || {}).map((k) => FIELD_OF_KEY[k]).filter(Boolean))];
  const over = {};
  for (const f of fields) {
    if (f === 'time') Object.assign(over, timeOf(occ) || {});
    else if (f in occ && occ[f] !== undefined) over[f] = occ[f];
  }
  return over;
}

/**
 * The event as shown, with the zone of its time: the window's copy of a
 * timed event doesn't carry it (the list shape leaves `tzid` to the
 * record; api-contract.md, Occurrences), so it is read from the record
 * once, when a request about its time is to go. A record stored all-day
 * holds UTC, no clock for the timed time shown: then the device's, the
 * clock the shown times were read on. Only an event in no series; any
 * other is returned as it is.
 */
async function withZone(occ, needed) {
  if (!needed || !occ || occ.recurring || occ.allDay || occ.tzid) return occ;
  const d = await api('/events/' + occ.eventId + '/occurrence');
  const rec = (d && d.occurrence) || {};
  return { ...occ, tzid: !rec.allDay && rec.tzid ? rec.tzid : localTz() };
}

/**
 * A request's body with its time whole (timeOf over the event as shown):
 * an event in no series only. A series' requests keep their own shapes (a
 * change of the series' start alone means something to its plan).
 */
function wholeBody(occ, body) {
  if (!occ || occ.recurring || !touchesTime(body)) return body;
  const t = timeOf(occ, body);
  if (!t) throw new Error('The time of this change has no zone; nothing was sent.');
  return { ...body, ...t };
}

/**
 * Overwrite: the same request again, for each asked field said to have
 * seen what Google holds now (`over` from its `now`) and sending what the
 * question showed as the person's (the body's keys from its `yours`; a
 * time its four keys, whole, through timeOf). A delete's `yours` is null: its body stays.
 * Everything else in the body (a scope, tags) is as it was.
 */
function overwriteOf(send, fields) {
  const body = { ...send };
  for (const f of fields || []) if (f.yours != null) Object.assign(body, valueOf(f, f.yours));
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
 * An editor open on the event when a question shows: its form stays the
 * person's (nothing here touches it; EditorDrawer seeds once per opening,
 * lib/editfields.js sameOpening), and what it has been shown, the base its
 * next Save sends as `over` (editorBase), takes the question's values.
 */
function editorShown(occ, fields) {
  const ed = state.editor;
  if (!ed || !ed.occ || ed.occ.eventId !== occ.eventId || ed.occ.recurring) return;
  set({ editor: { ...ed, shown: shownAfter(ed.shown || ed.occ, fields) } });
}

const askedFields = (e) => (e && e.code === 'google_changed' && e.data && e.data.error && Array.isArray(e.data.error.fields) && e.data.error.fields.length
  ? e.data.error.fields : null);

/**
 * What the server stored when it asked, shown: the event fetched again and
 * put in place of every copy held here, after `revert` takes the caller's
 * local change off (a drag's moved block, a Maybe's mark). The window is
 * fetched again too. Never the copy from before the action: Google may
 * hold something else now. If the event can't be read, the window still
 * is, and shows what it holds.
 */
async function showStored(occ, revert) {
  let stored = null;
  try {
    const d = await api('/events/' + occ.eventId + '/occurrence');
    stored = d && d.occurrence && !d.occurrence.recurring ? d.occurrence : null;
  } catch { /* the window's answer shows it */ }
  if (revert) revert();
  if (stored) adoptEvent(stored);
  refreshWindow();
}

/**
 * Send one change or delete of the event `occ` showed: `body` with its
 * `over`. Resolves to the answer, or CANCELLED when the server asked and the
 * person chose Cancel. At a question the event shows as the server stored
 * it before the question opens (showStored), whatever the answer. `back`:
 * the surface the change was made in stays open holding it (the editor, the
 * panel's time fields), so Cancel takes the person back to it. Any other
 * failure throws, as api() does; a google_changed without its fields throws
 * too, its message whole (failWords).
 */
export async function sendEventChange(occ, path, method, body = {}, { remove = false, revert = null, back = false } = {}) {
  const zoned = await withZone(occ, remove || touchesTime(body));
  const whole = wholeBody(zoned, body);
  const over = overOf(zoned, whole, { remove });
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
        const ask = googleChangedAsk({ name: occ.title, fields, remove, back });
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

/** A 200's `kept`, for the caller's toast (store.js toast words it); null when nothing stayed. */
export const keptOf = (answer) => (answer && Array.isArray(answer.kept) && answer.kept.length ? answer.kept : null);

/** A failed change in words: a google_changed's or a client_outdated's own sentence whole (each says what happened and what to do), else the prefix and the error. */
export function failWords(prefix, e) {
  return e instanceof ApiError && (e.code === 'google_changed' || e.code === CLIENT_OUTDATED) ? e.message : prefix + (e && e.message);
}
