// The same event on several calendars (#9), shown once. The server links the
// copies (occ.dupes: [{pairId, eventId, calendarId, groupId}]); among the occurrences
// that are visible, each linked group draws one: the copy from the most live
// source (Google, then your own calendars, then feeds, then a booking read
// from mail), which carries `alsoOn` naming the others. Nothing is hidden
// whose copy isn't visible: hide a calendar and its twin elsewhere shows.

import { startMs } from '../lib/dates.js';

/** Mirrors Duplicates::rank on the server. */
export function dupRank(occ, cal) {
  if (cal && cal.provider === 'google') return 4;
  if (occ.invite && occ.invite.kind === 'booking') return 1;
  return cal && cal.kind === 'local' ? 3 : 2;
}

function better(a, b, calById) {
  const ra = dupRank(a, calById.get(a.calendarId));
  const rb = dupRank(b, calById.get(b.calendarId));
  if (ra !== rb) return ra > rb;
  const fa = (a.location ? 1 : 0) + (a.hasDescription ? 1 : 0);
  const fb = (b.location ? 1 : 0) + (b.hasDescription ? 1 : 0);
  if (fa !== fb) return fa > fb;
  return a.eventId < b.eventId;
}

/**
 * @param {Array} occs visible occurrences
 * @param {Map<number, object>} calById
 * @returns {Array} the same list with each linked group drawn once
 */
export function collapseDuplicates(occs, calById) {
  if (!occs.some((o) => o.dupes && o.dupes.length)) return occs;
  // The server's graph is intentionally sparse. groupId names the complete
  // connected component, so two visible spokes still collapse when their
  // shared hub is hidden or outside this window.
  const groupOf = (o) => o.dupes && o.dupes.length
    ? (o.dupes.find((d) => d.groupId != null)?.groupId ?? o.eventId)
    : o.eventId;
  const size = new Map();
  for (const o of occs) {
    if (o.dupes && o.dupes.length) size.set(groupOf(o), (size.get(groupOf(o)) || 0) + 1);
  }
  // A series' copies meet at each instant; single events meet once.
  const keyOf = (o) => groupOf(o) + '|' + (o.recurring ? startMs(o) : '');
  const best = new Map();
  const members = new Map();
  for (const o of occs) {
    if (!o.dupes || !o.dupes.length || (size.get(groupOf(o)) || 0) < 2) continue;
    const k = keyOf(o);
    (members.get(k) || members.set(k, []).get(k)).push(o);
    const cur = best.get(k);
    if (!cur || better(o, cur, calById)) best.set(k, o);
  }
  const out = [];
  for (const o of occs) {
    const k = o.dupes && o.dupes.length ? keyOf(o) : null;
    const group = k !== null ? members.get(k) : null;
    if (!group || group.length < 2) { out.push(o); continue; }
    if (best.get(k) !== o) continue;
    out.push({
      ...o,
      alsoOn: group.filter((x) => x !== o).map((x) => ({ instanceId: x.instanceId, eventId: x.eventId, calendarId: x.calendarId })),
    });
  }
  return out;
}
