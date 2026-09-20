// The relationship quick filter (docs/relationships.md): which kinds of
// event the views show. Pure helpers so the views and the tests agree.

import { occDayKey, dayKeyOf, parseISO, addDaysKey } from './dates.js';

export const REL_KINDS = ['planned', 'maybe', 'available', 'context'];

/** showRel from a saved view's config: every kind on except those in
 *  hideRel. A view saved before the filter existed has no hideRel and
 *  applies as "show all". */
export function showRelFromConfig(config) {
  const hide = new Set(Array.isArray(config && config.hideRel) ? config.hideRel : []);
  const out = {};
  for (const k of REL_KINDS) out[k] = !hide.has(k);
  return out;
}

/** True when the filter lets this occurrence through. Hidden-by-choice rows
 *  (relationship 'hidden') are never "filtered": they are already gone. */
export function relShown(occ, showRel) {
  return !(occ.relationship && showRel[occ.relationship] === false);
}

/**
 * Day key -> how many occurrences the kind filter is hiding on that day, so
 * every view can mark the day and nothing disappears silently. Multi-day
 * occurrences count on each day they cover (capped, so a runaway span
 * cannot flood the map). All-day ends are exclusive dates; a timed end on
 * the stroke of midnight belongs to the day before.
 */
export function hiddenDayCounts(occurrences, showRel) {
  const out = new Map();
  for (const occ of occurrences) {
    if (relShown(occ, showRel)) continue;
    const start = occDayKey(occ);
    let end = occ.allDay
      ? addDaysKey(occ.end.slice(0, 10), -1)
      : dayKeyOf(new Date(parseISO(occ.end).getTime() - 1));
    if (end < start) end = start;
    for (let k = start, i = 0; k <= end && i < 62; k = addDaysKey(k, 1), i++) {
      out.set(k, (out.get(k) || 0) + 1);
    }
  }
  return out;
}
