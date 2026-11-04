// What an editor save sends for an existing event: only the fields the
// person changed (#199). The editor used to send every field, the repeat
// rule included, so a rename "changed" the rule as far as the server could
// tell. Pure, no DOM; EditorDrawer.js builds both sides with the same
// mapping, from the form as it opened and the form as it is.

import { remindersWords } from './reminders.js';

// Fields that only mean something together: a new time is its start, end,
// all-day flag and zone; a new place is its text and coordinates.
const TOGETHER = [
  ['start', 'end', 'allDay', 'tzid'],
  ['location', 'locationLat', 'locationLng', 'locationProvider'],
];

const same = (a, b) => JSON.stringify(a === undefined ? null : a) === JSON.stringify(b === undefined ? null : b);

/**
 * The fields of `now` that differ from `was`, with the rest of a group when
 * any of it changed. The repeat rule goes only when the repeat controls
 * were touched (rruleTouched): an unchanged rule is no rule.
 */
export function changedFields(now, was, { rruleTouched = false } = {}) {
  const out = {};
  for (const k of Object.keys(now)) {
    if (k === 'rrule') continue;
    if (!same(now[k], was[k])) out[k] = now[k];
  }
  for (const group of TOGETHER) {
    if (group.some((k) => k in out)) for (const k of group) if (k in now) out[k] = now[k];
  }
  if (rruleTouched && 'rrule' in now) out.rrule = now.rrule;
  return out;
}

/**
 * The editor save's lines in the series dialog, one per changed field:
 * [{label, words}] as "Title  Choir, now Chorus". ctx: calendarName(id),
 * personName(id), timeWords(was, now). Reminders come in either shape the
 * editor holds them (lib/reminders.js remindersWords).
 */
export function fieldLines(changed, was, ctx = {}) {
  const lines = [];
  const now = (label, a, b) => lines.push({ label, words: (a || 'none') + ', now ' + (b || 'none') });
  if ('start' in changed && ctx.timeWords) lines.push({ label: 'Time', words: ctx.timeWords(was, changed) });
  if ('title' in changed) now('Title', was.title, changed.title);
  if ('calendarId' in changed && ctx.calendarName) now('Calendar', ctx.calendarName(was.calendarId), ctx.calendarName(changed.calendarId));
  if ('location' in changed) now('Place', was.location, changed.location);
  if ('reminders' in changed) now('Reminder', remindersWords(was.reminders), remindersWords(changed.reminders));
  if ('tagNames' in changed) now('Tags', (was.tagNames || []).join(', '), (changed.tagNames || []).join(', '));
  if ('personIds' in changed && ctx.personName) now('People', (was.personIds || []).map(ctx.personName).join(', '), (changed.personIds || []).map(ctx.personName).join(', '));
  if ('url' in changed) now('Link', was.url, changed.url);
  if ('status' in changed) lines.push({ label: 'For me', words: changed.status === 'tentative' ? 'now Maybe' : 'now Planned' });
  if ('description' in changed) lines.push({ label: 'Notes', words: changed.description ? 'changed' : 'removed' });
  // The trip flag: the series' value for every event. The plan says when
  // the days differed (seriesplan.js reads its `field`).
  if ('isContainer' in changed) lines.push({ label: 'Trip', field: 'isContainer', words: (was.isContainer ? 'yes' : 'no') + ', now ' + (changed.isContainer ? 'yes' : 'no') });
  return lines;
}
