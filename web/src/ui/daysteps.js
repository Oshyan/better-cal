// The list the event sheet and panel step through for one day (#27): what
// the views show that day, in order. Pure, so the smoke suite can check it;
// actions.js sameDayList feeds it the store.
//
// Only what the views show: visible calendars, not hidden, not a kind the
// Show filter has switched off, and not context (weather, sunset: information
// in the day's header, not an event to step onto). The same event on two
// visible calendars draws once in the views (ui/duplicates.js, #9), so it is
// one step here too. Membership is span-based (an event covering the day
// belongs to it), matching the day-expand list.
//
// `keep`: the open event and the one the sheet was opened on (0.6.10) always
// stay, even when the views hide them (a search result on a hidden calendar)
// or when the views draw their twin instead, so stepping back always works.

import { epochDayOfKey, startMs } from '../lib/dates.js';
import { occurrenceDaySpan } from './monthmath.js';
import { collapseDuplicates } from './duplicates.js';

/**
 * @param {Iterable<object>} occs every loaded occurrence
 * @param {{dayKey:string, calendars:object[], showRel?:object, keep?:string[]}} opts
 * @returns {object[]} all-day first, then by start, then title
 */
export function dayStepList(occs, { dayKey, calendars, showRel = {}, keep = [] }) {
  const ed = epochDayOfKey(dayKey);
  const visible = new Set(calendars.filter((c) => c.visible).map((c) => c.id));
  const kept = new Set(keep.filter(Boolean));
  const list = [];
  for (const o of occs) {
    if (!kept.has(o.instanceId)) {
      if (!visible.has(o.calendarId)) continue;
      if (o.attendance === 'hidden') continue;
      if (o.relationship === 'context') continue;
      if (o.relationship && showRel[o.relationship] === false) continue;
    }
    const span = occurrenceDaySpan(o);
    if (epochDayOfKey(span.startKey) > ed || epochDayOfKey(span.endKey) < ed) continue;
    list.push(o);
  }
  const calById = new Map(calendars.map((c) => [c.id, c]));
  const collapsed = collapseDuplicates(list, calById);
  // A kept copy the collapse folded into its twin takes the twin's place.
  const out = collapsed.map((o) => {
    const swap = (o.alsoOn || []).find((x) => kept.has(x.instanceId));
    return swap && !kept.has(o.instanceId) ? list.find((x) => x.instanceId === swap.instanceId) : o;
  });
  out.sort((a, b) => {
    if (a.allDay !== b.allDay) return a.allDay ? -1 : 1;
    if (startMs(a) !== startMs(b)) return startMs(a) - startMs(b);
    return (a.title || '') < (b.title || '') ? -1 : (a.title || '') > (b.title || '') ? 1 : 0;
  });
  return out;
}
