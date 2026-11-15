// A change or delete of one event, and what Google Calendar did to it since
// it was shown (docs/design/google-conflict-choice.md, slice 1). Every request
// that changes or deletes one event goes out through sendEventChange:
//
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
// - a 200 that carries the event as saved (an event in no series) puts it in
//   place of every copy held before the caller goes on, so what shows after
//   an answer is the answer, not what a later refetch of the window brings;
// - a 200's `kept` goes on the caller's toast (keptOf; store.js toast says it).
//
// The setting is on as shipped; with it off for an account (an operator's
// switch) no answer carries google_changed or kept, and nothing here shows.

import { adoptEvent, state, set } from './store.js';
import { api, ApiError, refreshWindow } from './api.js';
import { askConfirm } from './ConfirmDialog.js';
import { googleChangedAsk } from '../lib/planwords.js';

/** What sendEventChange resolves to when the person chose Cancel at the question: nothing was sent. */
export const CANCELLED = Symbol('cancelled');

// The fields the server compares, and the request keys that change each.
// Planned or Maybe (`relationship`) is the event's status.
const FIELD_OF_KEY = {
  title: 'title', description: 'description', location: 'location', url: 'url',
  status: 'status', relationship: 'status', start: 'time', end: 'time', allDay: 'time',
};
const FIELDS = ['title', 'description', 'location', 'url', 'status', 'time'];
// A field's keys in `over`, as the API serves them for the event.
const OVER_KEYS = { time: ['start', 'end', 'allDay'] };
const overKeys = (field) => OVER_KEYS[field] || [field];

/**
 * The `over` of a request: for each field it changes (every field, for a
 * delete), the value the occurrence `occ` showed, under the keys the API
 * serves. Only an event in no series: a day of a repeating event shows its
 * own start, not the row's, and the server compares only single events.
 * A field the occurrence doesn't hold (the notes of one shown from the
 * window, without its record) is left out, and the server reads its base
 * from the row.
 */
export function overOf(occ, body, { remove = false } = {}) {
  if (!occ || occ.recurring) return {};
  const fields = remove ? FIELDS : [...new Set(Object.keys(body || {}).map((k) => FIELD_OF_KEY[k]).filter(Boolean))];
  const over = {};
  for (const f of fields) {
    const keys = overKeys(f);
    if (keys.every((k) => k in occ && occ[k] !== undefined)) for (const k of keys) over[k] = occ[k];
  }
  return over;
}

/**
 * Overwrite: the same request again, for each asked field said to have
 * seen what Google holds now (`over` from its `now`) and sending what the
 * question showed as the person's (the body's keys from its `yours`; a
 * time all three keys, whole). A delete's `yours` is null: its body stays.
 * Everything else in the body (a scope, tags) is as it was.
 */
function overwriteOf(send, fields) {
  const body = { ...send };
  for (const f of fields || []) {
    if (f.yours != null) for (const k of overKeys(f.field)) body[k] = partOf(f, f.yours, k);
  }
  return { ...body, over: { ...(send.over || {}), ...nowOf(fields) } };
}

// One key's part of a field's value: a time's three keys from its object.
const partOf = (f, v, k) => (overKeys(f.field).length > 1 ? (v || {})[k] : v);

/** What a question showed as Google's, under the keys the API serves: each asked field's `now`. */
function nowOf(fields) {
  const out = {};
  for (const f of fields || []) for (const k of overKeys(f.field)) out[k] = partOf(f, f.now, k);
  return out;
}

/**
 * The event `occ` as the person has been shown it once a question showed
 * `fields`: each asked field as Google holds it now, the rest as `occ` had
 * it. Overwrite's `over` is the same values (overwriteOf).
 */
export function shownAfter(occ, fields) {
  return { ...occ, ...nowOf(fields) };
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
  const over = overOf(occ, body, { remove });
  let send = Object.keys(over).length ? { ...body, over } : body;
  let shown = false;
  for (;;) {
    let answer;
    try {
      answer = await api(path, { method, body: send });
    } catch (e) {
      const fields = askedFields(e);
      if (!fields) throw e;
      await showStored(occ, shown ? null : revert);
      shown = true;
      editorShown(occ, fields);
      const ask = googleChangedAsk({ name: occ.title, fields, remove, back });
      if (!(await askConfirm(ask))) return CANCELLED;
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
}

/** A 200's `kept`, for the caller's toast (store.js toast words it); null when nothing stayed. */
export const keptOf = (answer) => (answer && Array.isArray(answer.kept) && answer.kept.length ? answer.kept : null);

/** A failed change in words: a google_changed's own sentence whole (it says what happened), else the prefix and the error. */
export function failWords(prefix, e) {
  return e instanceof ApiError && e.code === 'google_changed' ? e.message : prefix + (e && e.message);
}
