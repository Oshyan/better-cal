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
import { epochDayOfKey, startMs } from '../lib/dates.js';

export const AGENDA_ROW_H = 36;
export const AGENDA_HEAD_H = 40;
export const AGENDA_MONTH_SEP_H = 30;
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
export function buildAgendaGroups(occurrences, opts = {}) {
  const rowH = opts.rowH ?? AGENDA_ROW_H;
  const headH = opts.headH ?? AGENDA_HEAD_H;
  const byDay = new Map(); // dayKey -> {starts, ends, normals}
  const bucket = (k) => {
    let b = byDay.get(k);
    if (!b) byDay.set(k, (b = { starts: [], ends: [], normals: [] }));
    return b;
  };
  for (const occ of occurrences) {
    if (occ.attendance === 'hidden') continue;
    const { startKey, endKey } = occurrenceDaySpan(occ);
    if (occ.end && startKey !== endKey) {
      bucket(startKey).starts.push(occ);
      bucket(endKey).ends.push(occ);
    } else {
      bucket(startKey).normals.push(occ);
    }
  }
  const keys = [...byDay.keys()].sort();
  const sepH = opts.sepH ?? AGENDA_MONTH_SEP_H;
  let offset = 0;
  return keys.map((k, i) => {
    const b = byDay.get(k);
    b.ends.sort(chronological);
    const merged = [
      ...b.starts.map((occ) => ({ kind: 'start', occ })),
      ...b.normals.map((occ) => ({ kind: 'normal', occ })),
    ].sort((x, y) => chronological(x.occ, y.occ));
    const rows = [...merged, ...b.ends.map((occ) => ({ kind: 'end', occ }))];
    // A month separator is a real band inside the group that opens the month,
    // not an overlay: its height has to be in the layout or it draws on top
    // of the previous day's last row.
    const monthStart = i > 0 && keys[i - 1].slice(0, 7) !== k.slice(0, 7);
    const height = headH + rows.length * rowH + (monthStart ? sepH : 0);
    const g = { dayKey: k, rows, top: offset, height, monthStart };
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
// Shared by the rails (which add lanes and a cap) and the background washes
// (which take all of them). Returns [{occ, topPx, heightPx}], top-down.
export function spanPixelRanges(groups, opts = {}) {
  const rowH = opts.rowH ?? AGENDA_ROW_H;
  const headH = opts.headH ?? AGENDA_HEAD_H;
  const sepH = opts.sepH ?? AGENDA_MONTH_SEP_H;
  const byKey = new Map(groups.map((g) => [g.dayKey, g]));
  const spans = [];
  for (const g of groups) {
    g.rows.forEach((row, i) => {
      if (row.kind !== 'start') return;
      const occ = row.occ;
      const { endKey } = occurrenceDaySpan(occ);
      const eg = byKey.get(endKey);
      if (!eg) return; // boundary group missing: rows only, no rail
      const ei = eg.rows.findIndex((r) => r.kind === 'end' && r.occ.instanceId === occ.instanceId);
      if (ei < 0) return;
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
  const spans = spanPixelRanges(groups, opts);
  const laneBottoms = []; // per-lane occupied bottom px
  const out = [];
  for (const s of spans) {
    let lane = laneBottoms.findIndex((bottom) => bottom <= s.topPx);
    if (lane === -1) { lane = laneBottoms.length; laneBottoms.push(0); }
    laneBottoms[lane] = s.topPx + s.heightPx;
    if (lane >= maxLanes) continue; // overflow: no rail for this one
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
  return spanPixelRanges(groups, opts)
    .map((s) => ({
      instanceId: s.occ.instanceId,
      topPx: s.topPx,
      heightPx: s.heightPx,
      color: opts.colorOf ? opts.colorOf(s.occ) : undefined,
    }))
    .sort((a, b) => b.heightPx - a.heightPx);
}

