// Quick add's strip (date, start, end, all day) -> the {start, end} it saves.
// Pure, so the smoke tests can cover it.

import { parseISO, dayKeyOf, dateOfDayKey, addDaysKey, toISOWithOffset, fmtTime } from './dates.js';

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

// The clocks the strip says, for a timed range (api-contract.md, `typed`):
// the wall clocks typed, with no offset, in this device's zone, so 02:30 on
// a spring change day reaches the server as that clock and a series starts
// there through the anchor rule, never at the hour its instant shifted to.
// Null for an all-day range or an incomplete one.
export function typedRange(form, range, tz) {
  if (!range || form.allDay || !form.startTime || !form.endTime) return null;
  const endDay = dayKeyOf(parseISO(range.end));
  return { start: form.dateKey + 'T' + form.startTime, end: endDay + 'T' + form.endTime, tz };
}

// Quick add's other reading of a parse's days (#198): the server's
// `draft.other`, one stretch against an event each day. Its chip's words,
// as a person reads them, and its title (the longer why).
export function otherReadingWords(other) {
  if (!other) return null;
  return other.reading === 'each'
    ? { label: 'Each day instead', title: 'Make it an event on each of the ' + other.days + ' days, at these times' }
    : { label: 'One event instead', title: 'Make it one event, from the first start to the last end' };
}

// The parse with its other reading taken: the draft's days become the
// other reading's, and the reading it had becomes the other one, so a
// second tap puts it back. Its series plan was for the reading it had.
export function takeOtherReading(draft) {
  const o = draft && draft.other;
  if (!o) return draft;
  return {
    ...draft, start: o.start, end: o.end, allDay: o.allDay, rrule: o.rrule || null, series: null,
    other: { reading: o.reading === 'each' ? 'one' : 'each', start: draft.start, end: draft.end, allDay: !!draft.allDay, rrule: draft.rrule || null, days: o.days },
  };
}

// What the parse assumed where the text left it open (the server's
// `draft.assumed`), in words for beside the field it set: "at 7 read as
// 7:00 PM", "Mar 3 read as next year". Clocks in the user's 12- or 24-hour
// setting.
const clockWords = (hhmm) => {
  const [h, m] = String(hhmm).split(':').map(Number);
  return fmtTime(new Date(2000, 0, 1, h, m));
};
export function assumedWords(a) {
  if (!a) return '';
  if (a.kind === 'clock') return a.typed + ' read as ' + clockWords(a.start) + (a.end ? ' to ' + clockWords(a.end) : '');
  if (a.kind === 'year') return a.typed + ' read as next year';
  if (a.kind === 'rolled') return 'tomorrow, as ' + clockWords(a.start) + ' has passed today';
  if (a.kind === 'default') return 'no time read, so the next hour';
  return '';
}

// Every assumption about `field` ('date' or 'time') in words, once each,
// or '' when there is none.
export function assumedFor(draft, field) {
  const list = draft && Array.isArray(draft.assumed) ? draft.assumed : [];
  return [...new Set(list.filter((a) => a.field === field).map(assumedWords).filter(Boolean))].join('; ');
}
