// Quick add's strip (date, start, end, all day) -> the {start, end} it saves.
// Pure, so the smoke tests can cover it.

import { parseISO, dayKeyOf, dateOfDayKey, addDaysKey, toISOWithOffset } from './dates.js';

// Strip values -> {start, end} ISO strings, or null when incomplete. A parse
// that ends on a later day keeps that day while the date is untouched: the
// strip shows one date.
export function buildRange(form, draft, dateTouched) {
  if (!form.dateKey) return null;
  if (form.allDay) {
    let endKey = addDaysKey(form.dateKey, 1);
    // Preserve a parsed multi-day all-day span while the date is untouched.
    if (draft && draft.allDay && draft.end && !dateTouched) {
      const parsedEnd = draft.end.slice(0, 10);
      if (parsedEnd > endKey) endKey = parsedEnd;
    }
    return {
      start: toISOWithOffset(dateOfDayKey(form.dateKey)),
      end: toISOWithOffset(dateOfDayKey(endKey)),
    };
  }
  if (!form.startTime || !form.endTime) return null;
  const base = dateOfDayKey(form.dateKey);
  const [sh, sm] = form.startTime.split(':').map(Number);
  const [eh, em] = form.endTime.split(':').map(Number);
  // Preserve a parsed end on a later day ("Fri 5pm to Sun 2pm") the same way.
  let days = 0;
  if (draft && !draft.allDay && draft.start && draft.end && !dateTouched) {
    days = Math.round((dateOfDayKey(dayKeyOf(parseISO(draft.end))) - dateOfDayKey(dayKeyOf(parseISO(draft.start)))) / 86400000);
  }
  const s = new Date(base.getFullYear(), base.getMonth(), base.getDate(), sh, sm);
  let e = new Date(base.getFullYear(), base.getMonth(), base.getDate() + Math.max(0, days), eh, em);
  if (e <= s) e = new Date(base.getFullYear(), base.getMonth(), base.getDate() + 1, eh, em); // overnight
  return { start: toISOWithOffset(s), end: toISOWithOffset(e) };
}
