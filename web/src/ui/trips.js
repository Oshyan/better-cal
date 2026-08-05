// Pure trip (container event) math: span labels, backdrop band segmentation,
// attach-candidate filtering, and span-extension math (docs/design-containers.md).
// No DOM, no preact: unit-testable from the smoke suite.

import { occurrenceDaySpan, rowSpanSegments } from './monthmath.js';
import {
  epochDayOfKey, keyOfEpochDay, dateOfDayKey, addDaysKey, addDaysDate,
  toISOWithOffset, parseISO, startMs,
} from '../lib/dates.js';
import { dayRangeLabel } from '../lib/quickcreate.js';

// Inclusive day span plus length of a trip (or any occurrence).
export function tripSpan(occ) {
  const { startKey, endKey } = occurrenceDaySpan(occ);
  return { startKey, endKey, days: epochDayOfKey(endKey) - epochDayOfKey(startKey) + 1 };
}

// "Jun 1 to 12, 12 days" (single day: "Jun 1, 1 day").
export function tripSpanLabel(occ) {
  const { startKey, endKey, days } = tripSpan(occ);
  return dayRangeLabel(startKey, endKey) + ', ' + days + (days === 1 ? ' day' : ' days');
}

// Per-row segments for the month/ribbon backdrop band. Deliberately the same
// segmentation the normal multi-day bars use (rowSpanSegments), so bands and
// bars always agree on row boundaries and continuation flags.
export function tripBandSegments(occ, columns = 7) {
  const { startKey, endKey } = occurrenceDaySpan(occ);
  return rowSpanSegments(startKey, endKey, columns);
}

// Do two inclusive day spans overlap, or abut within slackDays of each other?
export function spansOverlapOrAbut(aStartKey, aEndKey, bStartKey, bEndKey, slackDays = 1) {
  const aS = epochDayOfKey(aStartKey);
  const aE = epochDayOfKey(aEndKey);
  const bS = epochDayOfKey(bStartKey);
  const bE = epochDayOfKey(bEndKey);
  return aS <= bE + slackDays && bS <= aE + slackDays;
}

// Chip/bar-style chronological order: all-day first, then by start.
function chronological(a, b) {
  if (!!a.allDay !== !!b.allDay) return a.allDay ? -1 : 1;
  if (startMs(a) !== startMs(b)) return startMs(a) - startMs(b);
  return (a.title || '') < (b.title || '') ? -1 : 1;
}

// Containers whose span overlaps or abuts (within slackDays) the event's own
// span: the "Trip" selector list on the event side. Deduped by eventId
// (recurring masters vs instances), chronological; the event itself and
// synthetic group items never qualify.
export function candidateTrips(occ, occurrences, slackDays = 1) {
  const { startKey, endKey } = occurrenceDaySpan(occ);
  const seen = new Set();
  const out = [];
  for (const o of occurrences) {
    if (!o || !o.isContainer || o.isGroup) continue;
    if (o.eventId === occ.eventId || seen.has(o.eventId)) continue;
    const span = occurrenceDaySpan(o);
    if (!spansOverlapOrAbut(span.startKey, span.endKey, startKey, endKey, slackDays)) continue;
    seen.add(o.eventId);
    out.push(o);
  }
  out.sort(chronological);
  return out;
}

// Non-container events overlapping the trip's span: the "Add events" picker
// list on the trip side. Excludes the trip itself, other containers,
// synthetic groups and hidden occurrences; deduped by eventId; chronological.
export function attachableInSpan(tripOcc, occurrences) {
  const span = occurrenceDaySpan(tripOcc);
  const seen = new Set();
  const out = [];
  for (const o of occurrences) {
    if (!o || o.isContainer || o.isGroup) continue;
    if (o.eventId === tripOcc.eventId || seen.has(o.eventId)) continue;
    if (o.attendance === 'hidden') continue;
    const s = occurrenceDaySpan(o);
    if (!spansOverlapOrAbut(s.startKey, s.endKey, span.startKey, span.endKey, 0)) continue;
    seen.add(o.eventId);
    out.push(o);
  }
  out.sort(chronological);
  return out;
}

// Span-extension math (design decision #4: prompt, never silent). Returns
// the {start, end} PATCH dates that grow tripOcc's span to cover memberOcc,
// or null when the member already fits inside the span. All-day trips keep
// all-day semantics: literal local dates with the iCal exclusive end date.
// Timed trips keep their times of day on the new boundary days (a midnight
// end stays "midnight after the last covered day").
export function extendTripSpan(tripOcc, memberOcc) {
  const t = occurrenceDaySpan(tripOcc);
  const m = occurrenceDaySpan(memberOcc);
  const newStart = Math.min(epochDayOfKey(t.startKey), epochDayOfKey(m.startKey));
  const newEnd = Math.max(epochDayOfKey(t.endKey), epochDayOfKey(m.endKey));
  if (newStart === epochDayOfKey(t.startKey) && newEnd === epochDayOfKey(t.endKey)) return null;
  const startKey = keyOfEpochDay(newStart);
  const endKey = keyOfEpochDay(newEnd);
  if (tripOcc.allDay) {
    return {
      start: toISOWithOffset(dateOfDayKey(startKey)),
      end: toISOWithOffset(dateOfDayKey(addDaysKey(endKey, 1))), // exclusive end
    };
  }
  const s0 = parseISO(tripOcc.start);
  const e0 = parseISO(tripOcc.end);
  const sBase = dateOfDayKey(startKey);
  let s = new Date(sBase.getFullYear(), sBase.getMonth(), sBase.getDate(), s0.getHours(), s0.getMinutes());
  const eBase = dateOfDayKey(endKey);
  let e = new Date(eBase.getFullYear(), eBase.getMonth(), eBase.getDate(), e0.getHours(), e0.getMinutes());
  // An end at exactly midnight means "through the end of endKey": push it to
  // the following midnight so the span still covers endKey.
  if (e0.getHours() === 0 && e0.getMinutes() === 0) e = addDaysDate(e, 1);
  if (e <= s) e = addDaysDate(s, 1);
  return { start: toISOWithOffset(s), end: toISOWithOffset(e) };
}
