// Near-duplicate grouping: pure math, no DOM, no preact (unit-testable).
//
// Calendars flagged groupSimilar collapse same-day occurrences whose titles
// share a base after stripping one trailing parenthetical ("Juneteenth
// (Alabama)" -> "Juneteenth") into one synthetic group item. Groups form only
// within a single calendar and a single day; singletons pass through
// untouched; hidden occurrences never join a group (so counts exclude them).
// A group is dimmed (server dim flag) only when every member is dimmed.

import { occurrenceDaySpan } from './monthmath.js';

// Title base: strip one trailing parenthetical (and surrounding whitespace).
// A title that is nothing but a parenthetical keeps its original form.
export function baseTitle(title) {
  const t = (title || '').trim();
  const stripped = t.replace(/\s*\([^()]*\)\s*$/, '').trim();
  return stripped === '' ? t : stripped;
}

// Synthetic-group instanceId prefix; the app layer uses it to route clicks
// to the group popover instead of the event popover.
export const GROUP_PREFIX = 'group:';

export function isGroupId(instanceId) {
  return typeof instanceId === 'string' && instanceId.startsWith(GROUP_PREFIX);
}

// Collapse near-duplicates in a flat occurrence list.
// groupFlags: {calendarId: boolean} (true = calendar has groupSimilar on).
// Returns a new array preserving input order: each group replaces its first
// member in place; later members are dropped; everything else passes through.
// Group items: {isGroup, instanceId, calendarId, title, count, start, end,
// allDay, dimmed, attendance:'none', members}.
export function groupOccurrences(occurrences, groupFlags) {
  const flags = groupFlags || {};
  const buckets = new Map(); // key -> [occ]
  for (const occ of occurrences) {
    if (!flags[occ.calendarId]) continue;
    if (occ.attendance === 'hidden') continue;
    const { startKey, endKey } = occurrenceDaySpan(occ);
    if (startKey !== endKey) continue; // multi-day events never group
    const key = occ.calendarId + '|' + startKey + '|' + baseTitle(occ.title).toLowerCase();
    let list = buckets.get(key);
    if (!list) buckets.set(key, (list = []));
    list.push(occ);
  }

  const memberToKey = new Map(); // instanceId -> group key (>=2 members only)
  const groupByKey = new Map();
  for (const [key, members] of buckets) {
    if (members.length < 2) continue;
    const sorted = [...members].sort((a, b) => {
      if (a.allDay !== b.allDay) return a.allDay ? -1 : 1;
      if (a.start !== b.start) return a.start < b.start ? -1 : 1;
      return (a.title || '') < (b.title || '') ? -1 : 1;
    });
    const first = sorted[0];
    const last = sorted[sorted.length - 1];
    groupByKey.set(key, {
      isGroup: true,
      instanceId: GROUP_PREFIX + key,
      calendarId: first.calendarId,
      title: baseTitle(first.title),
      count: sorted.length,
      start: first.start,
      end: last.end > first.end ? last.end : first.end,
      allDay: sorted.every((m) => m.allDay),
      dimmed: sorted.every((m) => !!m.dimmed),
      attendance: 'none',
      members: sorted,
    });
    for (const m of members) memberToKey.set(m.instanceId, key);
  }

  if (groupByKey.size === 0) return occurrences;

  const emitted = new Set();
  const out = [];
  for (const occ of occurrences) {
    const key = memberToKey.get(occ.instanceId);
    if (key === undefined) {
      out.push(occ);
      continue;
    }
    if (!emitted.has(key)) {
      emitted.add(key);
      out.push(groupByKey.get(key));
    }
  }
  return out;
}

// Client-filter dimming for a display list item: a group dims only when no
// member matches. matches(occ) is the caller's predicate over real events.
export function itemMatchesFilter(item, matches) {
  if (item && item.isGroup) return item.members.some(matches);
  return matches(item);
}
