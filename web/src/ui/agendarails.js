// Pure math for the agenda's multi-day treatment (rail concept): day groups
// with synthesized start/end days, split member rows, rail pixel ranges with
// lane assignment, and day-of-span helpers. No DOM, no preact: unit-testable.
//
// Multi-day occurrences (occurrenceDaySpan startKey != endKey) get a start
// row on their first day, a compact end marker on their last day, and a
// continuous vertical rail between the two. Both boundary days always exist
// as groups (synthesized when empty); intermediate empty days get nothing,
// the rail alone carries continuity across them.

import { occurrenceDaySpan } from './monthmath.js';
import { epochDayOfKey, keyOfEpochDay, startMs } from '../lib/dates.js';

export const AGENDA_ROW_H = 36;
export const AGENDA_HEAD_H = 40;
export const AGENDA_MONTH_SEP_H = 30;
// A folded run of empty days (the gaps option).
export const AGENDA_GAP_H = 30;
export const MAX_RAIL_LANES = 3;

// Inclusive day count of an occurrence's span (1 for single-day).
export function spanDayCount(occ) {
  const { startKey, endKey } = occurrenceDaySpan(occ);
  return epochDayOfKey(endKey) - epochDayOfKey(startKey) + 1;
}

// "Day 2/5" for a day inside the span (quiet suffix and end-marker gutter).
export function dayOfSpanLabel(occ, dayKey) {
  const { startKey, endKey } = occurrenceDaySpan(occ);
  const n = epochDayOfKey(endKey) - epochDayOfKey(startKey) + 1;
  const i = epochDayOfKey(dayKey) - epochDayOfKey(startKey) + 1;
  return 'Day ' + i + '/' + n;
}

// Chip-order comparator shared with the other views: all-day first, then by
// start (matches the historical agenda sort).
function chronological(a, b) {
  if (a.allDay !== b.allDay) return a.allDay ? -1 : 1;
  return startMs(a) - startMs(b);
}

// Day groups for the agenda, including synthesized boundary-day groups.
// Hidden occurrences are dropped. Each group:
//   {dayKey, rows: [{kind: 'start'|'end'|'normal', occ}], top, height}
// Row order within a group: starts and single-day rows merged
// chronologically (all-day first), then end markers last, so a span bounds
// its final day (the rail bar runs down past that day's events to the end
// pill). Heights and tops are pixel values (rowH per row, headH per header)
// so the windowed renderer and railRanges share one coordinate space.
function dayRows(b) {
  b.ends.sort(chronological);
  const merged = [
    ...b.starts.map((occ) => ({ kind: 'start', occ })),
    ...b.normals.map((occ) => ({ kind: 'normal', occ })),
  ].sort((x, y) => chronological(x.occ, y.occ));
  return [...merged, ...b.ends.map((occ) => ({ kind: 'end', occ }))];
}

// The gaps option: every day appears, and each run of days with no rows is
// one gap group
//   {dayKey (first day), gapTo (last day), gap: true, covered, unloaded, rows: []}
// so the list is continuous in time and free stretches are visible instead
// of silently skipped. A run of loaded days never crosses a month boundary,
// which keeps the month separator on the group that opens the month.
// covered: a multi-day event runs through the gap (its rail passes by), so
// the days are not empty, just free of anything else.
// loaded ([[firstEpochDay, lastEpochDay], ...]): the days whose events are
// held. A run outside them is one "not loaded" group, never "nothing on": an
// empty day and a day not fetched yet are different claims. The list covers
// the loaded days too, not only the days that have events, so a day with
// nothing on it at the edge of what's loaded is still a place to land.
function normalizeLoaded(loaded) {
  if (!loaded) return null;
  const sorted = loaded
    .filter((r) => Array.isArray(r) && Number.isFinite(r[0]) && Number.isFinite(r[1]) && r[0] <= r[1])
    .map(([a, b]) => [Math.trunc(a), Math.trunc(b)])
    .sort((a, b) => a[0] - b[0]);
  const out = [];
  for (const range of sorted) {
    const last = out[out.length - 1];
    if (last && range[0] <= last[1] + 1) last[1] = Math.max(last[1], range[1]);
    else out.push(range);
  }
  return out;
}

function visibleDayRows(bucket, maxRowsPerDay) {
  const all = dayRows(bucket);
  if (!Number.isFinite(maxRowsPerDay) || all.length <= maxRowsPerDay) {
    return { rows: all, overflow: 0 };
  }
  return { rows: all.slice(0, maxRowsPerDay), overflow: all.length - maxRowsPerDay };
}

function withGaps(keys, byDay, occurrences, { rowH, headH, sepH, gapH, loaded, maxRowsPerDay }) {
  const ranges = normalizeLoaded(loaded);
  let first = keys.length ? epochDayOfKey(keys[0]) : Infinity;
  let last = keys.length ? epochDayOfKey(keys[keys.length - 1]) : -Infinity;
  if (ranges) {
    if (ranges.length === 0) return [];
    // Loaded windows, not attacker-controlled event endpoints, own the Split
    // list's finite extent. Boundary rows were clipped to these same edges.
    first = ranges[0][0];
    last = ranges[ranges.length - 1][1];
  }
  if (!Number.isFinite(first) || !Number.isFinite(last)) return [];
  const spans = [];
  for (const occ of occurrences) {
    if (occ.attendance === 'hidden') continue;
    const { startKey, endKey } = occurrenceDaySpan(occ);
    const a = Math.max(first, epochDayOfKey(startKey));
    const b = Math.min(last, epochDayOfKey(endKey));
    if (a < b) spans.push([a, b]);
  }
  let rangeIndex = 0;
  const heldRange = (d) => {
    if (!ranges) return null;
    while (rangeIndex < ranges.length && ranges[rangeIndex][1] < d) rangeIndex++;
    const range = ranges[rangeIndex];
    return range && range[0] <= d && d <= range[1] ? range : null;
  };
  const keyDays = keys.map(epochDayOfKey);
  let keyIndex = 0;
  const entries = [];
  let d = first;
  while (d <= last) {
    while (keyIndex < keyDays.length && keyDays[keyIndex] < d) keyIndex++;
    const k = keyOfEpochDay(d);
    if (byDay.has(k)) {
      entries.push({ dayKey: k, ...visibleDayRows(byDay.get(k), maxRowsPerDay) });
      d++;
      continue;
    }
    const range = heldRange(d);
    const isHeld = !ranges || range !== null;
    let e = d;
    if (!isHeld && ranges) {
      // One unloaded row can cover millennia; jump to the next loaded/event
      // boundary instead of testing every intervening date.
      const nextLoaded = ranges[rangeIndex]?.[0] ?? (last + 1);
      const nextEvent = keyDays[keyIndex] ?? (last + 1);
      e = Math.min(last, nextLoaded - 1, nextEvent - 1);
    } else {
      const heldEnd = range ? range[1] : last;
      while (e < heldEnd) {
        const nk = keyOfEpochDay(e + 1);
        if (byDay.has(nk)) break;
        if (nk.slice(0, 7) !== k.slice(0, 7)) break;
        e++;
      }
    }
    const covered = isHeld && spans.some(([a, b]) => a < d && b > e);
    entries.push({ dayKey: k, gapTo: keyOfEpochDay(e), gap: true, unloaded: !isHeld, covered, rows: [] });
    d = e + 1;
  }
  let offset = 0;
  let prevEnd = null;
  for (const g of entries) {
    g.monthStart = prevEnd !== null && prevEnd.slice(0, 7) !== g.dayKey.slice(0, 7);
    g.height = (g.gap ? gapH : headH + (g.rows.length + (g.overflow ? 1 : 0)) * rowH) + (g.monthStart ? sepH : 0);
    g.top = offset;
    offset += g.height;
    prevEnd = g.gapTo || g.dayKey;
  }
  return entries;
}

export function buildAgendaGroups(occurrences, opts = {}) {
  const rowH = opts.rowH ?? AGENDA_ROW_H;
  const headH = opts.headH ?? AGENDA_HEAD_H;
  const byDay = new Map(); // dayKey -> {starts, ends, normals}
  const bucket = (k) => {
    let b = byDay.get(k);
    if (!b) byDay.set(k, (b = { starts: [], ends: [], normals: [] }));
    return b;
  };
  const ranges = opts.gaps ? normalizeLoaded(opts.loaded) : null;
  const loadedFirst = ranges && ranges.length ? ranges[0][0] : null;
  const loadedLast = ranges && ranges.length ? ranges[ranges.length - 1][1] : null;
  for (const occ of occurrences) {
    if (occ.attendance === 'hidden') continue;
    const { startKey, endKey } = occurrenceDaySpan(occ);
    let startDay = epochDayOfKey(startKey);
    let endDay = epochDayOfKey(endKey);
    if (loadedFirst !== null && loadedLast !== null) {
      if (endDay < loadedFirst || startDay > loadedLast) continue;
      startDay = Math.max(startDay, loadedFirst);
      endDay = Math.min(endDay, loadedLast);
    }
    const shownStart = keyOfEpochDay(startDay);
    const shownEnd = keyOfEpochDay(endDay);
    if (occ.end && startKey !== endKey) {
      bucket(shownStart).starts.push(occ);
      bucket(shownEnd).ends.push(occ);
    } else {
      bucket(shownStart).normals.push(occ);
    }
  }
  const keys = [...byDay.keys()].sort();
  const sepH = opts.sepH ?? AGENDA_MONTH_SEP_H;
  const maxRowsPerDay = opts.maxRowsPerDay ?? Infinity;
  if (opts.gaps) return withGaps(keys, byDay, occurrences, {
    rowH,
    headH,
    sepH,
    gapH: opts.gapH ?? AGENDA_GAP_H,
    loaded: opts.loaded,
    maxRowsPerDay,
  });
  let offset = 0;
  return keys.map((k, i) => {
    const { rows, overflow } = visibleDayRows(byDay.get(k), maxRowsPerDay);
    // A month separator is a real band inside the group that opens the month,
    // not an overlay: its height has to be in the layout or it draws on top
    // of the previous day's last row.
    const monthStart = i > 0 && keys[i - 1].slice(0, 7) !== k.slice(0, 7);
    const height = headH + (rows.length + (overflow ? 1 : 0)) * rowH + (monthStart ? sepH : 0);
    const g = { dayKey: k, rows, overflow, top: offset, height, monthStart };
    offset += height;
    return g;
  });
}

// Rail pixel ranges: one per multi-day occurrence, from the vertical center
// of its start row to the vertical center of its end marker row, so each end
// of the bar terminates under its pill and the two pills physically join the
// bar (pill, bar, pill reads as one object).
// Overlapping rails pack into lanes (lowest free lane by pixel range);
// occurrences past maxLanes keep their start/end rows but get no rail.
// opts.colorOf(occ) resolves the calendar color (the math stays
// presentation-free without it).
// Returns [{instanceId, calendarId, title, isTrip, topPx, heightPx, lane, color}].
// Pixel range of every multi-day span, start pill top to end pill bottom.
// Shared by the rails and background washes, which apply independent render
// caps. Returns [{occ, topPx, heightPx}], top-down.
export function spanPixelRanges(groups, opts = {}) {
  const rowH = opts.rowH ?? AGENDA_ROW_H;
  const headH = opts.headH ?? AGENDA_HEAD_H;
  const sepH = opts.sepH ?? AGENDA_MONTH_SEP_H;
  const ends = new Map();
  for (const group of groups) {
    group.rows.forEach((row, index) => {
      if (row.kind === 'end') ends.set(row.occ.instanceId, { group, index });
    });
  }
  const spans = [];
  for (const g of groups) {
    g.rows.forEach((row, i) => {
      if (row.kind !== 'start') return;
      const occ = row.occ;
      const end = ends.get(occ.instanceId);
      if (!end) return; // boundary row omitted or missing: rows only, no rail
      const eg = end.group;
      const ei = end.index;
      // Full pill coverage: the 20px chip is centered in the 36px row (8px
      // insets), so the bar runs from the TOP of the start pill to the BOTTOM
      // of the end pill. Anything shorter visibly stops mid-pill because chip
      // backgrounds are translucent.
      const pillInset = (rowH - 20) / 2;
      // Groups that open a month carry the separator band above their header,
      // so their rows start that much lower.
      const topPx = g.top + (g.monthStart ? sepH : 0) + headH + i * rowH + pillInset;
      const bottomPx = eg.top + (eg.monthStart ? sepH : 0) + headH + ei * rowH + (rowH - pillInset);
      spans.push({ occ, topPx, heightPx: bottomPx - topPx });
    });
  }
  spans.sort((a, b) => a.topPx - b.topPx || (b.topPx + b.heightPx) - (a.topPx + a.heightPx));
  return spans;
}

export function railRanges(groups, opts = {}) {
  const maxLanes = opts.maxLanes ?? MAX_RAIL_LANES;
  const spans = opts.spans ?? spanPixelRanges(groups, opts);
  const laneBottoms = []; // per-lane occupied bottom px
  const out = [];
  for (const s of spans) {
    let lane = laneBottoms.findIndex((bottom) => bottom <= s.topPx);
    if (lane === -1 && laneBottoms.length < maxLanes) { lane = laneBottoms.length; laneBottoms.push(0); }
    if (lane === -1) continue;
    laneBottoms[lane] = s.topPx + s.heightPx;
    out.push({
      instanceId: s.occ.instanceId,
      calendarId: s.occ.calendarId,
      title: s.occ.title || '(untitled)',
      isTrip: !!s.occ.isContainer,
      topPx: s.topPx,
      heightPx: s.heightPx,
      lane,
      color: opts.colorOf ? opts.colorOf(s.occ) : undefined,
    });
  }
  return out;
}

// Background washes: one full-width band per span, bounded by the span's own
// start and end pills rather than by whole days. That is what produces the
// three tones the eye expects when two spans overlap — first span alone,
// both blended, second span alone — and it stops a timed span from tinting
// the hours before it began on its first day.
// Returns [{instanceId, topPx, heightPx, color}], longest-first so shorter
// spans layer above longer ones.
export function washRects(groups, opts = {}) {
  const maxRects = opts.maxRects ?? 100;
  return (opts.spans ?? spanPixelRanges(groups, opts))
    .map((s) => ({
      instanceId: s.occ.instanceId,
      topPx: s.topPx,
      heightPx: s.heightPx,
      color: opts.colorOf ? opts.colorOf(s.occ) : undefined,
    }))
    .sort((a, b) => b.heightPx - a.heightPx)
    .slice(0, maxRects);
}
