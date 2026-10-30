// Pure math for MonthGrid virtualization and multi-day segmentation.
// No DOM, no preact: unit-testable.

import {
  epochDayOfKey, keyOfEpochDay, weekIndexOfEpochDay, firstEpochDayOfWeek,
  dayKeyOfISO, parseISO, dayKeyOf, addDaysDate, toISOWithOffset, dateOfDayKey,
  pad, addDaysInZone, sameClock, localTz,
} from '../lib/dates.js';

// Which of a day's single events a month or 3-day cell shows, and what its
// "+N" counts. Quiet events (dimmed by the filter box or a dim filter) come
// after the others and only fill slots that are left; "+N" counts the others
// that don't fit, plus hiddenBars (multi-day bars and trip bands of the same
// kind that found no lane). So a filtered day never hides its matches behind
// grayed-out events, and "+3" means three matches. With nothing quiet this
// is the plain layout: as many as fit, the last slot given to "+N" when it's
// needed. Returns {shown, hidden, more}: the events to draw in order, the
// "+N" count, and the hidden events (for the phone's colour dots).
export function planCell(singles, isQuiet, room, hiddenBars = 0) {
  const loud = [];
  const quiet = [];
  for (const o of singles) (isQuiet(o) ? quiet : loud).push(o);
  const needsMore = loud.length + hiddenBars > room;
  const shownLoud = needsMore ? Math.max(0, room - 1) : loud.length;
  const hidden = loud.length - shownLoud + hiddenBars;
  const left = needsMore ? 0 : Math.max(0, room - shownLoud - (hidden > 0 ? 1 : 0));
  return {
    shown: [...loud.slice(0, shownLoud), ...quiet.slice(0, left)],
    hidden,
    more: loud.slice(shownLoud),
  };
}

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

// --- generalized row math (7-column month rows and N-day ribbon rows) -------
// Rows with columns === 7 are calendar weeks (week-start aware). Any other
// column count chunks the epoch-day line into consecutive fixed-size rows
// anchored at epoch day 0 (floor(epochDay / columns)): stable boundaries that
// never depend on the week-start setting or the viewport.

export function rowIndexOfEpochDay(ed, columns = 7) {
  if (columns === 7) return weekIndexOfEpochDay(ed);
  return Math.floor(ed / columns);
}

export function rowIndexOfDayKey(key, columns = 7) {
  return rowIndexOfEpochDay(epochDayOfKey(key), columns);
}

// First epoch day of a row index.
export function firstEpochDayOfRow(ri, columns = 7) {
  if (columns === 7) return firstEpochDayOfWeek(ri);
  return ri * columns;
}

export function dayKeysOfRow(ri, columns = 7) {
  const first = firstEpochDayOfRow(ri, columns);
  const out = [];
  for (let i = 0; i < columns; i++) out.push(keyOfEpochDay(first + i));
  return out;
}

// Saturday/Sunday check on the epoch-day line (1970-01-01 was a Thursday).
export function isWeekendEpochDay(ed) {
  const dow = (((ed + 4) % 7) + 7) % 7; // 0 = Sunday .. 6 = Saturday
  return dow === 0 || dow === 6;
}

// Split a [startKey..endKey] (inclusive) day span into per-row segments for a
// grid with the given column count. Each segment:
// {rowIndex, startCol, endCol, contLeft, contRight} with columns
// 0..columns-1 and continuation flags at row edges.
export function rowSpanSegments(startKey, endKey, columns = 7) {
  const s = epochDayOfKey(startKey);
  const e = Math.max(s, epochDayOfKey(endKey));
  const firstRow = rowIndexOfEpochDay(s, columns);
  const lastRow = rowIndexOfEpochDay(e, columns);
  const segs = [];
  for (let ri = firstRow; ri <= lastRow; ri++) {
    const rowStart = firstEpochDayOfRow(ri, columns);
    const segStart = Math.max(s, rowStart);
    const segEnd = Math.min(e, rowStart + columns - 1);
    segs.push({
      rowIndex: ri,
      startCol: segStart - rowStart,
      endCol: segEnd - rowStart,
      contLeft: s < rowStart,
      contRight: e > rowStart + columns - 1,
    });
  }
  return segs;
}

// --- very long spans -----------------------------------------------------------
// rowSpanSegments makes one object per row the span touches, which is right
// for a trip or a conference and wrong for an event whose dates are a typo or
// an attack: a feed event running from the year 1000 to 2000 is 52,000 week
// rows, allocated up front although perhaps six are ever on screen (BC-15).
// Spans over LONG_SPAN_ROWS are therefore NOT pre-segmented. The grid keeps
// one record for them and asks rowSegmentAt() for just the rows it draws.

// How far down the scroller a day gone to sits, as a share of the room
// above a row: a row or two of context before it (MonthGrid; resume.js reads
// the same line back).
export const ANCHOR_LINE = 0.4;

export const LONG_SPAN_ROWS = 60; // about 14 months of week rows

// How many rows a [startKey..endKey] span touches. O(1).
export function spanRowCount(startKey, endKey, columns = 7) {
  const s = epochDayOfKey(startKey);
  const e = Math.max(s, epochDayOfKey(endKey));
  return rowIndexOfEpochDay(e, columns) - rowIndexOfEpochDay(s, columns) + 1;
}

// The one segment of a span that falls in a given row, in exactly the shape
// rowSpanSegments produces, or null when the span does not reach that row. O(1).
export function rowSegmentAt(startKey, endKey, rowIndex, columns = 7) {
  const s = epochDayOfKey(startKey);
  const e = Math.max(s, epochDayOfKey(endKey));
  const rowStart = firstEpochDayOfRow(rowIndex, columns);
  const rowEnd = rowStart + columns - 1;
  if (e < rowStart || s > rowEnd) return null;
  return {
    rowIndex,
    startCol: Math.max(s, rowStart) - rowStart,
    endCol: Math.min(e, rowEnd) - rowStart,
    contLeft: s < rowStart,
    contRight: e > rowEnd,
  };
}

// Clamp an inclusive epoch-day range to another; null when they do not meet.
// Used so drag highlighting walks the days that are rendered, not every day
// of the span being dragged.
export function clampDayRange(a, b, lo, hi) {
  const from = Math.max(a, lo);
  const to = Math.min(b, hi);
  return from <= to ? [from, to] : null;
}

// Week-row segmentation (columns = 7), kept as the historical shape with
// weekIndex naming. Delegates to rowSpanSegments.
export function segmentSpan(startKey, endKey) {
  return rowSpanSegments(startKey, endKey, 7).map((s) => ({
    weekIndex: s.rowIndex,
    startCol: s.startCol,
    endCol: s.endCol,
    contLeft: s.contLeft,
    contRight: s.contRight,
  }));
}

// Is an occurrence multi-day on the grid?
/**
 * A timed event that runs past midnight into the next morning (10 PM to
 * 1 AM, an overnight flight landing by noon). It's a timed event, not a
 * span: month shows it as a chip on the day it starts, week and day as a
 * block in each day it touches. As a bar it lost its time (#183). One that
 * runs on into the next afternoon (10 PM to 9 PM, or a day shortened by a
 * clock change) is mostly the second day's, so it stays a span.
 */
export function isOvernight(occ) {
  if (occ.allDay || occ.isContainer) return false;
  const s = parseISO(occ.start);
  const e = parseISO(occ.end);
  if (!(e - s < 86400000)) return false;
  const { startKey, endKey } = occurrenceDaySpan(occ);
  if (epochDayOfKey(endKey) !== epochDayOfKey(startKey) + 1) return false;
  return e.getHours() * 60 + e.getMinutes() <= 12 * 60;
}

export function isMultiDay(occ) {
  const { startKey, endKey } = occurrenceDaySpan(occ);
  return endKey !== startKey;
}

// Month label info for the left gutter: for each row in [firstRow, lastRow]
// that contains the 1st of a month, return {weekIndex, year, month, col}
// (month 1-12; col is the 1st's column in that row). weekIndex is the row
// index (historical name; 7-column callers see real week indexes).
export function monthStartsInRange(firstRow, lastRow, columns = 7) {
  const out = [];
  for (let ri = firstRow; ri <= lastRow; ri++) {
    const start = firstEpochDayOfRow(ri, columns);
    for (let i = 0; i < columns; i++) {
      const key = keyOfEpochDay(start + i);
      if (key.endsWith('-01')) {
        const [y, m] = key.split('-').map(Number);
        out.push({ weekIndex: ri, year: y, month: m, col: i });
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
// Move an occurrence by whole days; the ONE place that knows all-day events
// are dates and timed events are instants.
//
// All-day: pure day-key arithmetic, sent as bare dates ("2026-06-03", end
// exclusive). Their serialized start is "<date>T00:00:00+00:00", and pushing
// that through a local Date lands on the evening before anywhere west of UTC:
// from Los Angeles a move to June 3 went out as "2026-06-02T17:00:00-07:00".
// Timed: wall-clock math, so the time of day survives a DST change; in the
// event's own zone when it keeps one that reads differently from this
// device (0.9.15: a London 9:00 dragged a week from Los Angeles across the
// spring change landed at 8:00 London).
export function shiftOccurrenceDays(occ, deltaDays) {
  if (occ.allDay) {
    const { startKey, endKey } = occurrenceDaySpan(occ);
    return {
      newStart: keyOfEpochDay(epochDayOfKey(startKey) + deltaDays),
      newEnd: keyOfEpochDay(epochDayOfKey(endKey) + 1 + deltaDays), // exclusive
    };
  }
  const own = occ.tzid && occ.tzid !== 'UTC' && !sameClock(occ.tzid, localTz()) ? occ.tzid : null;
  const shift = (iso) => (own ? addDaysInZone(parseISO(iso), deltaDays, own) : addDaysDate(parseISO(iso), deltaDays));
  return {
    newStart: toISOWithOffset(shift(occ.start)),
    newEnd: toISOWithOffset(shift(occ.end)),
  };
}

/**
 * A bar's start or end dragged to another day (month and week views). The
 * other end stays put, and an end can't pass the other one. All-day: pure
 * day-key arithmetic, sent as literal dates (routing these through a local
 * Date shifted the untouched edge back a day west of UTC, since the +00:00
 * date parses to the prior local evening and the server floors the literal
 * date). Timed: the moved end keeps its clock time on its new day.
 */
export function resizeOccurrenceDays(occ, edge, targetKey) {
  const { startKey, endKey } = occurrenceDaySpan(occ);
  const t = epochDayOfKey(targetKey);
  if (occ.allDay) {
    let sKey = startKey;
    let eKeyIncl = endKey;
    if (edge === 'end') eKeyIncl = keyOfEpochDay(Math.max(t, epochDayOfKey(startKey)));
    else sKey = keyOfEpochDay(Math.min(t, epochDayOfKey(endKey)));
    return { newStart: sKey, newEnd: keyOfEpochDay(epochDayOfKey(eKeyIncl) + 1) }; // exclusive
  }
  const s0 = parseISO(occ.start);
  const e0 = parseISO(occ.end);
  let s = s0, e = e0;
  if (edge === 'end') {
    const base = dateOfDayKey(keyOfEpochDay(Math.max(t, epochDayOfKey(startKey))));
    // Ending at midnight, its last day is the one before: dropped on Thursday
    // it ends as Thursday does, at Friday 00:00 (it stopped a day short).
    const atMidnight = e0.getHours() === 0 && e0.getMinutes() === 0;
    e = atMidnight
      ? new Date(base.getFullYear(), base.getMonth(), base.getDate() + 1)
      : new Date(base.getFullYear(), base.getMonth(), base.getDate(), e0.getHours(), e0.getMinutes());
    if (e <= s) e = new Date(s.getTime() + 30 * 60000);
  } else {
    const base = dateOfDayKey(keyOfEpochDay(Math.min(t, epochDayOfKey(endKey))));
    s = new Date(base.getFullYear(), base.getMonth(), base.getDate(), s0.getHours(), s0.getMinutes());
    if (s >= e) s = new Date(e.getTime() - 30 * 60000);
  }
  return { newStart: toISOWithOffset(s), newEnd: toISOWithOffset(e) };
}

export function dayDropDates(occ, targetKey) {
  const { startKey } = occurrenceDaySpan(occ);
  const delta = epochDayOfKey(targetKey) - epochDayOfKey(startKey);
  return { ...shiftOccurrenceDays(occ, delta), delta };
}

// Time-slot drop resolution (week/day views): snap the pointer minute to 15,
// keep the duration, and clamp so the event still starts inside the day.
export function timeDropDates(occ, dayKey, minute) {
  const s0 = parseISO(occ.start);
  const e0 = parseISO(occ.end);
  const durMin = Math.max(15, (e0.getTime() - s0.getTime()) / 60000);
  // The start stays on the day dropped on; the end may run past midnight
  // (an overnight event dropped at 10 PM stays at 10 PM; it was pulled back
  // so it ended by midnight, #183).
  const startMin = Math.max(0, Math.min(1440 - 15, Math.round(minute / 15) * 15));
  const base = dateOfDayKey(dayKey);
  const s = new Date(base.getFullYear(), base.getMonth(), base.getDate(), 0, startMin);
  return { newStart: toISOWithOffset(s), newEnd: toISOWithOffset(new Date(s.getTime() + durMin * 60000)) };
}

// Dominant month of a row (the month owning the most of its days).
// Used for the toolbar's current-month label and alternating backgrounds.
export function dominantMonthOfRow(rowIndex, columns = 7) {
  const start = firstEpochDayOfRow(rowIndex, columns);
  const counts = new Map();
  for (let i = 0; i < columns; i++) {
    const [y, m] = keyOfEpochDay(start + i).split('-').map(Number);
    const k = y * 12 + (m - 1);
    counts.set(k, (counts.get(k) || 0) + 1);
  }
  let best = null, bestN = 0;
  for (const [k, n] of counts) if (n > bestN) { best = k; bestN = n; }
  return { year: Math.floor(best / 12), month: (best % 12) + 1 };
}

export function dominantMonthOfWeek(weekIndex) {
  return dominantMonthOfRow(weekIndex, 7);
}

// Dominant month across a whole visible window of rows: the month owning the
// most day cells in rows [firstRow, firstRow + rowCount). Ties go to the
// later month (scrolling forward should never keep naming the month that is
// leaving). Unlike dominantMonthOfRow (top row only), this is what the
// toolbar label and mini-month should follow, so the named month is always
// the one the user mostly sees.
export function dominantMonthOfRows(firstRow, rowCount, columns = 7) {
  const start = firstEpochDayOfRow(firstRow, columns);
  const n = Math.max(1, rowCount) * columns; // rows are consecutive on the epoch-day line
  const counts = new Map();
  for (let i = 0; i < n; i++) {
    const [y, m] = keyOfEpochDay(start + i).split('-').map(Number);
    const k = y * 12 + (m - 1);
    counts.set(k, (counts.get(k) || 0) + 1);
  }
  let best = null, bestN = 0;
  for (const [k, n2] of counts) {
    if (n2 > bestN || (n2 === bestN && (best === null || k > best))) { best = k; bestN = n2; }
  }
  return { year: Math.floor(best / 12), month: (best % 12) + 1 };
}

// --- Row geometry -------------------------------------------------------
// Where each week row of the month grid sits. Fixed rows are all one height,
// so a week's top is (index - minWeek) * rowH: exactly the arithmetic the grid
// has always used. Rows that fit their contents (#171) each have their own
// height, kept in a running total so top() is a lookup and weekAt() a binary
// search. Both answer the same four questions, and the grid asks only these.

export function fixedGeometry(minWeek, maxWeek, rowH) {
  return {
    fit: false,
    top: (wi) => (wi - minWeek) * rowH,
    height: () => rowH,
    weekAt: (y) => Math.max(minWeek, Math.min(maxWeek, minWeek + Math.floor(y / rowH))),
    total: () => (maxWeek - minWeek + 1) * rowH,
  };
}

// heights: Map weekIndex -> px for the weeks taller than baseH; every other
// week is baseH.
export function fitGeometry(minWeek, maxWeek, baseH, heights) {
  const n = maxWeek - minWeek + 1;
  const tops = new Float64Array(n + 1);
  for (let i = 0; i < n; i++) tops[i + 1] = tops[i] + (heights.get(minWeek + i) ?? baseH);
  const clampIdx = (i) => Math.max(0, Math.min(n, i));
  return {
    fit: true,
    top: (wi) => tops[clampIdx(wi - minWeek)],
    height: (wi) => heights.get(wi) ?? baseH,
    // The week whose rows cover y: the last top at or above it.
    weekAt: (y) => {
      let lo = 0, hi = n - 1;
      while (lo < hi) {
        const mid = (lo + hi + 1) >> 1;
        if (tops[mid] <= y) lo = mid; else hi = mid - 1;
      }
      return minWeek + lo;
    },
    total: () => tops[n],
  };
}

// The rows to render around a scroll position, with any row geometry.
export function weekRangeIn(geo, scrollTop, viewportH, minWeek, maxWeek, buffer = 3) {
  return {
    first: Math.max(minWeek, geo.weekAt(scrollTop) - buffer),
    last: Math.min(maxWeek, geo.weekAt(scrollTop + viewportH) + buffer),
  };
}

// The month covering most of the screen when rows differ in height: each
// week's days count by how much of that week is on screen, so one tall week
// of the old month doesn't outvote the screenful of the new one. weeks:
// [{weekIndex, px}].
export function dominantMonthOfWeeks(weeks, columns = 7) {
  const counts = new Map();
  for (const { weekIndex, px } of weeks) {
    if (!(px > 0)) continue;
    const start = firstEpochDayOfRow(weekIndex, columns);
    for (let i = 0; i < columns; i++) {
      const [y, m] = keyOfEpochDay(start + i).split('-').map(Number);
      const k = y * 12 + (m - 1);
      counts.set(k, (counts.get(k) || 0) + px);
    }
  }
  let best = null, bestN = 0;
  for (const [k, n2] of counts) {
    if (n2 > bestN || (n2 === bestN && (best === null || k > best))) { best = k; bestN = n2; }
  }
  if (best === null) return dominantMonthOfRows(weeks.length ? weeks[0].weekIndex : 0, 1, columns);
  return { year: Math.floor(best / 12), month: (best % 12) + 1 };
}
