// A change or delete of one event, and what Google Calendar did to it since
// it was shown (docs/design/google-conflict-choice.md, slice 1). Every request
// that changes or deletes one event goes out through sendEventChange:
//
// - it carries `over`, for each field the request changes, the value the app
//   showed (overOf: from the record or occurrence on screen, never from the
//   editor's form), so the server compares Google's value with what the
//   person saw, not with what a poll stored meanwhile;
// - a 409 google_changed becomes the one question (googleChangedAsk in
//   lib/planwords.js): Overwrite sends the same request again with `over`
//   set to what Google holds now and each asked field's value to what the
//   question showed as the person's, and a second google_changed asks again
//   with the newer values; Cancel sends nothing;
// - a 200's `kept` is said on the caller's toast (keptLine).
//
// With the setting off (as shipped) no answer carries google_changed or
// kept, and nothing here shows.

import { state, invalidateRecords } from './store.js';
import { api, ApiError, refreshWindow } from './api.js';
import { askConfirm } from './ConfirmDialog.js';
import { googleChangedAsk, keptWords } from '../lib/planwords.js';

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
  const over = { ...(send.over || {}) };
  const body = { ...send };
  for (const f of fields || []) {
    const keys = overKeys(f.field);
    const part = (v, k) => (keys.length > 1 ? (v || {})[k] : v);
    for (const k of keys) {
      over[k] = part(f.now, k);
      if (f.yours != null) body[k] = part(f.yours, k);
    }
  }
  return { ...body, over };
}

const askedFields = (e) => (e && e.code === 'google_changed' && e.data && e.data.error && Array.isArray(e.data.error.fields) && e.data.error.fields.length
  ? e.data.error.fields : null);

/**
 * Send one change or delete of the event `occ` showed: `body` with its
 * `over`. Resolves to the answer, or CANCELLED when the server asked and the
 * person chose Cancel; then `revert` (the caller's own local copy put back)
 * runs, and the event and the window are fetched again so it shows as
 * Google has it. Any other failure throws, as api() does; a google_changed
 * without its fields throws too, its message whole (failWords).
 */
export async function sendEventChange(occ, path, method, body = {}, { remove = false, revert = null } = {}) {
  const over = overOf(occ, body, { remove });
  let send = Object.keys(over).length ? { ...body, over } : body;
  for (;;) {
    try {
      return await api(path, { method, body: send });
    } catch (e) {
      const fields = askedFields(e);
      if (!fields) throw e;
      // An editor open on this event keeps the person's change on Cancel.
      const back = !!(state.editor && state.editor.occ && state.editor.occ.eventId === occ.eventId);
      const ask = googleChangedAsk({ name: occ.title, fields, remove, back });
      if (!(await askConfirm(ask))) {
        if (revert) revert();
        invalidateRecords(occ.eventId);
        refreshWindow();
        return CANCELLED;
      }
      send = overwriteOf(send, fields);
    }
  }
}

/** The toast's line for what stayed from Google Calendar, from a 200's `kept`; null when nothing did. */
export const keptLine = (answer) => keptWords(answer && answer.kept);

/** A failed change in words: a google_changed's own sentence whole (it says what happened), else the prefix and the error. */
export function failWords(prefix, e) {
  return e instanceof ApiError && e.code === 'google_changed' ? e.message : prefix + (e && e.message);
}
