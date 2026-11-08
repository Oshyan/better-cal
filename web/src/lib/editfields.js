// What an editor save sends for an existing event: only the fields the
// person changed (#199), and the series panel's line for each of them, both
// from one table (EDIT_FIELDS), so a field the editor can send is a field
// the panel can name. The editor used to send every field, the repeat rule
// included, so a rename "changed" the rule as far as the server could tell.
// Pure, no DOM; EditorDrawer.js builds both sides with the same mapping,
// from the form as it opened and the form as it is.

import { remindersWords } from './reminders.js';

const or = (s) => s || 'none';
const was_now = (a, b) => or(a) + ', now ' + or(b);

/**
 * Every field an editor save can send, as rows: `keys`, the request fields
 * that only mean something together (a new time is its start, end, all-day
 * flag, zone and the clock typed; a new place its text and coordinates),
 * sent together when any of them changed; `label`, the panel's name for
 * it; `words(was, now, ctx)`, how its change reads ("Choir, now Chorus"),
 * or null when another sentence of the panel says it (the repeat rule is
 * the Change sentence's). ctx: calendarName(id), personName(id),
 * timeWords(was, now).
 */
export const EDIT_FIELDS = [
  { keys: ['start', 'end', 'allDay', 'tzid', 'typed'], label: 'Time', words: (a, b, ctx) => (ctx.timeWords ? ctx.timeWords(a, b) : null) },
  { keys: ['title'], label: 'Title', words: (a, b) => was_now(a.title, b.title) },
  { keys: ['calendarId'], label: 'Calendar', words: (a, b, ctx) => (ctx.calendarName ? was_now(ctx.calendarName(a.calendarId), ctx.calendarName(b.calendarId)) : null) },
  { keys: ['location', 'locationLat', 'locationLng', 'locationProvider'], label: 'Place', words: (a, b) => was_now(a.location, b.location) },
  { keys: ['reminders'], label: 'Reminder', words: (a, b) => was_now(remindersWords(a.reminders), remindersWords(b.reminders)) },
  { keys: ['tagNames'], label: 'Tags', words: (a, b) => was_now((a.tagNames || []).join(', '), (b.tagNames || []).join(', ')) },
  { keys: ['personIds'], label: 'People', words: (a, b, ctx) => (ctx.personName ? was_now((a.personIds || []).map(ctx.personName).join(', '), (b.personIds || []).map(ctx.personName).join(', ')) : null) },
  { keys: ['url'], label: 'Link', words: (a, b) => was_now(a.url, b.url) },
  { keys: ['status'], label: 'For me', words: (a, b) => (b.status === 'tentative' ? 'now Maybe' : 'now Planned') },
  { keys: ['description'], label: 'Notes', words: (a, b) => (b.description ? 'changed' : 'removed') },
  // The trip flag: the series' value for every event. The plan says when
  // the days differed (seriesplan.js reads its `field`).
  { keys: ['isContainer'], label: 'Trip', field: 'isContainer', words: (a, b) => (a.isContainer ? 'yes' : 'no') + ', now ' + (b.isContainer ? 'yes' : 'no') },
  // Floating (#167): the same clock time in any zone, the series' value for
  // every event, as the trip flag is.
  { keys: ['floating'], label: 'Floating', field: 'floating', words: (a, b) => (a.floating ? 'yes' : 'no') + ', now ' + (b.floating ? 'yes' : 'no') },
  { keys: ['rrule'], label: 'Repeat', words: () => null },
];

const ROW_OF = new Map(EDIT_FIELDS.flatMap((row) => row.keys.map((k) => [k, row])));
/** Whether the table has a row for a request field, so the panel can name it. */
export const editField = (key) => ROW_OF.get(key) || null;

const same = (a, b) => JSON.stringify(a === undefined ? null : a) === JSON.stringify(b === undefined ? null : b);

/**
 * The fields of `now` that differ from `was`, with the rest of their row
 * when any of it changed. Only fields of the table are sent: one it lacks
 * has no line in the panel, so it is never sent unsaid. The repeat rule
 * goes only when the repeat controls were touched (rruleTouched): an
 * unchanged rule is no rule.
 */
export function changedFields(now, was, { rruleTouched = false } = {}) {
  const out = {};
  for (const k of Object.keys(now)) {
    if (k === 'rrule' || !ROW_OF.has(k)) continue;
    if (!same(now[k], was[k])) out[k] = now[k];
  }
  for (const row of EDIT_FIELDS) {
    if (row.keys.some((k) => k in out)) for (const k of row.keys) if (k in now) out[k] = now[k];
  }
  if (rruleTouched && 'rrule' in now) out.rrule = now.rrule;
  return out;
}

/**
 * The editor save's lines in the series dialog, one per changed row of the
 * table: [{label, words}] as "Title  Choir, now Chorus", in the table's
 * order. A row whose words another sentence says (the rule) has no line.
 */
export function fieldLines(changed, was, ctx = {}) {
  const lines = [];
  for (const row of EDIT_FIELDS) {
    if (!row.keys.some((k) => k in changed)) continue;
    const words = row.words(was, changed, ctx);
    if (words == null) continue;
    lines.push(row.field ? { label: row.label, field: row.field, words } : { label: row.label, words });
  }
  return lines;
}
