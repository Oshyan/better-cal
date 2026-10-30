// Bands: a person's away or busy time, or a plugin's range, drawn across the
// days it covers. Not events: they open their own cards and only some move.
// Pure (no DOM, no preact) so the tests can load it.

import { parseISO, dayKeyOf, addDaysKey } from './dates.js';

/** A band, not an event: a person's away/busy time or a plugin's range. */
export function isBand(occ) {
  return !!occ.availKind || String(occ.instanceId).startsWith('plg:');
}

/**
 * Whether dragging can move or resize this item. A plugin band belongs to its
 * plugin's job; a feed's or a view-only Google calendar's events can't be
 * changed (a plugin calendar that takes moves says reschedulable). They used
 * to drag, preview a new time, then fail with "Move failed" (#183).
 */
export function canReschedule(occ, cal) {
  if (String(occ.instanceId).startsWith('plg:')) return false;
  return !cal || !!cal.editable || !!cal.reschedulable;
}

/**
 * The all-day start and end of a band running from one instant to another
 * (the end exclusive, as stored): the local day of the start, and the day
 * after the last day it touches. A midnight end doesn't reach into the next
 * day. Written as the last day instead, the band drew a day short (#183).
 */
export function bandDays(startISO, endISO) {
  const startKey = dayKeyOf(parseISO(startISO));
  const endKey = dayKeyOf(new Date(parseISO(endISO).getTime() - 60000));
  return {
    start: startKey + 'T00:00:00+00:00',
    end: addDaysKey(endKey >= startKey ? endKey : startKey, 1) + 'T00:00:00+00:00',
  };
}
