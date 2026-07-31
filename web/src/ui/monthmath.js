// Pure math for MonthGrid virtualization and multi-day segmentation.
// No DOM, no preact: unit-testable.

import {
  epochDayOfKey, keyOfEpochDay, weekIndexOfEpochDay, firstEpochDayOfWeek,
  dayKeyOfISO, parseISO, dayKeyOf,
} from '../lib/dates.js';

// Visible week window for a virtualized scroller.
// scrollTop/viewportH in px, rowH px per week row, buffer rows on each side.
// Weeks are addressed by absolute week index; the spacer covers
// [minWeek, maxWeek]. Returns {first, last} absolute week indexes to render.
export function visibleWeekRange(scrollTop, viewportH, rowH, minWeek, maxWeek, buffer = 3) {
  const first = Math.max(minWeek, minWeek + Math.floor(scrollTop / rowH) - buffer);
  const last = Math.min(maxWeek, minWeek + Math.ceil((scrollTop + viewportH) / rowH) + buffer);
  return { first, last };
}

export function weekTop(weekIndex, minWeek, rowH) {
  return (weekIndex - minWeek) * rowH;
}

export function totalHeight(minWeek, maxWeek, rowH) {
  return (maxWeek - minWeek + 1) * rowH;
}

// The [startKey, endKeyInclusive] day span an occurrence covers on the grid.
// Timed events ending exactly at midnight do not spill into the next day;
// all-day events use an exclusive end date per iCal convention.
export function occurrenceDaySpan(occ) {
  const startKey = dayKeyOfISO(occ.start);
  const end = parseISO(occ.end);
  let endKey = dayKeyOf(new Date(end.getTime() - 1));
  if (end.getTime() <= parseISO(occ.start).getTime()) endKey = startKey;
  if (epochDayOfKey(endKey) < epochDayOfKey(startKey)) endKey = startKey;
  return { startKey, endKey };
}

// Split a [startKey..endKey] (inclusive) day span into per-week segments.
// Each segment: {weekIndex, startCol, endCol, contLeft, contRight} with
// columns 0..6 (Monday..Sunday) and continuation flags at week edges.
export function segmentSpan(startKey, endKey) {
  const s = epochDayOfKey(startKey);
  const e = Math.max(s, epochDayOfKey(endKey));
  const firstWeek = weekIndexOfEpochDay(s);
  const lastWeek = weekIndexOfEpochDay(e);
  const segs = [];
  for (let wi = firstWeek; wi <= lastWeek; wi++) {
    const weekStart = firstEpochDayOfWeek(wi);
    const segStart = Math.max(s, weekStart);
    const segEnd = Math.min(e, weekStart + 6);
    segs.push({
      weekIndex: wi,
      startCol: segStart - weekStart,
      endCol: segEnd - weekStart,
      contLeft: segStart > weekStart ? false : s < weekStart,
      contRight: e > weekStart + 6,
    });
  }
  return segs;
}

// Is an occurrence multi-day on the grid?
export function isMultiDay(occ) {
  const { startKey, endKey } = occurrenceDaySpan(occ);
  return endKey !== startKey;
}

// Month label info for the left gutter: for each week in [firstWeek, lastWeek]
// that contains the 1st of a month, return {weekIndex, year, month} (month 1-12).
export function monthStartsInRange(firstWeek, lastWeek) {
  const out = [];
  for (let wi = firstWeek; wi <= lastWeek; wi++) {
    const start = firstEpochDayOfWeek(wi);
    for (let i = 0; i < 7; i++) {
      const key = keyOfEpochDay(start + i);
      if (key.endsWith('-01')) {
        const [y, m] = key.split('-').map(Number);
        out.push({ weekIndex: wi, year: y, month: m });
      }
    }
  }
  return out;
}

// Dominant month of a week (the month owning >= 4 of its 7 days).
// Used for the toolbar's current-month label and alternating backgrounds.
export function dominantMonthOfWeek(weekIndex) {
  const start = firstEpochDayOfWeek(weekIndex);
  const counts = new Map();
  for (let i = 0; i < 7; i++) {
    const [y, m] = keyOfEpochDay(start + i).split('-').map(Number);
    const k = y * 12 + (m - 1);
    counts.set(k, (counts.get(k) || 0) + 1);
  }
  let best = null, bestN = 0;
  for (const [k, n] of counts) if (n > bestN) { best = k; bestN = n; }
  return { year: Math.floor(best / 12), month: (best % 12) + 1 };
}
