// Pure math for MonthGrid virtualization and multi-day segmentation.
// No DOM, no preact: unit-testable.

import {
  epochDayOfKey, keyOfEpochDay, weekIndexOfEpochDay, firstEpochDayOfWeek,
  dayKeyOfISO, parseISO, dayKeyOf, addDaysDate, toISOWithOffset, dateOfDayKey,
  pad,
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
// all-day events use an exclusive end date per iCal convention. All-day
// occurrences carry literal dates (see occDayKey) and get pure string/day math,
// no timezone conversion.
export function occurrenceDaySpan(occ) {
  if (occ.allDay) {
    const startKey = occ.start.slice(0, 10);
    let endEpoch = epochDayOfKey(occ.end.slice(0, 10)) - 1;
    if (endEpoch < epochDayOfKey(startKey)) endEpoch = epochDayOfKey(startKey);
    return { startKey, endKey: keyOfEpochDay(endEpoch) };
  }
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

// --- reschedule mode math (PRD 5.9) ----------------------------------------

// The 2*radius+1 consecutive {year, month} entries centered on a month
// (month 1-12), rolling across year boundaries.
export function monthsAround(year, month, radius = 12) {
  const out = [];
  const base = year * 12 + (month - 1);
  for (let k = base - radius; k <= base + radius; k++) {
    out.push({ year: Math.floor(k / 12), month: ((k % 12) + 12) % 12 + 1 });
  }
  return out;
}

// Mini-month descriptor for the film-strip navigator: the first day key plus
// how many Monday-started week rows the month spans (4-6).
export function miniMonthGrid(year, month) {
  const firstKey = year + '-' + pad(month) + '-01';
  const nextFirst = month === 12 ? (year + 1) + '-01-01' : year + '-' + pad(month + 1) + '-01';
  const lastEpoch = epochDayOfKey(nextFirst) - 1;
  const weekCount = weekIndexOfEpochDay(lastEpoch) - weekIndexOfEpochDay(epochDayOfKey(firstKey)) + 1;
  return { year, month, firstKey, weekCount };
}

// Day-cell drop resolution: move the occurrence so its first grid day becomes
// targetKey, preserving time-of-day and duration (multi-day events keep their
// length; the drop sets the START day). Mirrors the MonthGrid drag-move math.
export function dayDropDates(occ, targetKey) {
  const { startKey } = occurrenceDaySpan(occ);
  const delta = epochDayOfKey(targetKey) - epochDayOfKey(startKey);
  const s = addDaysDate(parseISO(occ.start), delta);
  const e = addDaysDate(parseISO(occ.end), delta);
  return { newStart: toISOWithOffset(s), newEnd: toISOWithOffset(e), delta };
}

// Time-slot drop resolution (week/day views): snap the pointer minute to 15,
// keep the duration, and clamp so the event still starts inside the day.
export function timeDropDates(occ, dayKey, minute) {
  const s0 = parseISO(occ.start);
  const e0 = parseISO(occ.end);
  const durMin = Math.max(15, (e0.getTime() - s0.getTime()) / 60000);
  const startMin = Math.max(0, Math.min(1440 - durMin, Math.round(minute / 15) * 15));
  const base = dateOfDayKey(dayKey);
  const s = new Date(base.getFullYear(), base.getMonth(), base.getDate(), 0, startMin);
  return { newStart: toISOWithOffset(s), newEnd: toISOWithOffset(new Date(s.getTime() + durMin * 60000)) };
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
