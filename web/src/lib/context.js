// Context events (docs/relationships.md) are information about a day, not
// plans: weather, AQI, sunset, tides, a holiday, someone being away. They
// are consulted, not attended, so the views draw them as small tokens in
// the day's header (and as hairline moments on the timeline) instead of
// spending event slots on them. This module decides what a token says.

import { fmtTime, parseISO, occDayKey, addDaysKey } from './dates.js';
import { occurrenceDaySpan } from '../ui/monthmath.js';

export function isContext(occ) {
  return occ.relationship === 'context';
}

/** "8:30 PM" -> "8:30p", "12:00 PM" -> "12p", "20:30" stays (24h setting). */
export function compactTime(d) {
  return fmtTime(d).replace(':00', '').replace(/\s?AM$/i, 'a').replace(/\s?PM$/i, 'p');
}

function clip(s, max) {
  return s.length > max ? s.slice(0, max - 1).trimEnd() + '…' : s;
}

/**
 * The token: a short text and, for a timed moment, its time. A feed title
 * that names its source first ("Oakland, CA: 72/58 fog") drops the source,
 * since the calendar colour already says where it came from.
 */
export function contextLabel(occ, max = 14) {
  let t = (occ.title || '').trim();
  const i = t.indexOf(': ');
  if (i > 0 && i <= 24 && i < t.length - 2) t = t.slice(i + 2);
  if (occ.allDay) return { text: clip(t, max), time: null };
  return { text: clip(t, Math.max(6, max - 4)), time: compactTime(parseISO(occ.start)) };
}

/** The full story for a tooltip: title, and the time for a moment. */
export function contextTitle(occ) {
  const t = (occ.title || '').trim() || '(untitled)';
  if (occ.allDay) return t;
  const s = parseISO(occ.start);
  const e = occ.end ? parseISO(occ.end) : null;
  const brief = !e || (e.getTime() - s.getTime()) <= 15 * 60000;
  return t + ' · ' + fmtTime(s) + (brief || !e ? '' : ' to ' + fmtTime(e));
}

/**
 * Day key -> the context occurrences that speak for that day, all-day first
 * (the state of the day), then moments by time. A multi-day all-day span
 * counts on each day it covers (capped so a runaway span cannot flood).
 */
export function contextByDay(occurrences) {
  const out = new Map();
  for (const occ of occurrences) {
    if (!isContext(occ) || occ.attendance === 'hidden') continue;
    if (!occ.allDay) {
      const k = occDayKey(occ);
      if (!out.has(k)) out.set(k, []);
      out.get(k).push(occ);
      continue;
    }
    const { startKey, endKey } = occurrenceDaySpan(occ);
    for (let k = startKey, i = 0; k <= endKey && i < 62; k = addDaysKey(k, 1), i++) {
      if (!out.has(k)) out.set(k, []);
      out.get(k).push(occ);
    }
  }
  const order = (a, b) => (Number(!a.allDay) - Number(!b.allDay)) || (a.start < b.start ? -1 : a.start > b.start ? 1 : 0) || (a.title || '').localeCompare(b.title || '');
  for (const list of out.values()) list.sort(order);
  return out;
}

/** Everything that is not context: what the views draw as events. */
export function withoutContext(occurrences) {
  return occurrences.filter((o) => !isContext(o));
}
