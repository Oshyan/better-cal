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
import { epochDayOfKey } from '../lib/dates.js';

export const AGENDA_ROW_H = 36;
export const AGENDA_HEAD_H = 40;
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
  return a.start < b.start ? -1 : 1;
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
  let offset = 0;
  return keys.map((k) => {
    const b = byDay.get(k);
    b.ends.sort(chronological);
    const merged = [
      ...b.starts.map((occ) => ({ kind: 'start', occ })),
      ...b.normals.map((occ) => ({ kind: 'normal', occ })),
    ].sort((x, y) => chronological(x.occ, y.occ));
    const rows = [...merged, ...b.ends.map((occ) => ({ kind: 'end', occ }))];
    const height = headH + rows.length * rowH;
    const g = { dayKey: k, rows, top: offset, height };
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
export function railRanges(groups, opts = {}) {
  const rowH = opts.rowH ?? AGENDA_ROW_H;
  const headH = opts.headH ?? AGENDA_HEAD_H;
  const maxLanes = opts.maxLanes ?? MAX_RAIL_LANES;
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
      const topPx = g.top + headH + i * rowH + rowH / 2; // vertical center of the start pill's row
      const bottomPx = eg.top + headH + ei * rowH + rowH / 2; // vertical center of the end pill's row
      spans.push({ occ, topPx, heightPx: bottomPx - topPx });
    });
  }
  spans.sort((a, b) => a.topPx - b.topPx || (b.topPx + b.heightPx) - (a.topPx + a.heightPx));
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

// Header suffixes: for each group whose day falls inside a multi-day span
// (inclusive of both boundary days), the first `max` covering occurrences
// plus an overflow count. Returns Map dayKey -> {items: [occ], more: K}.
export function headerSuffixes(groups, opts = {}) {
  const max = opts.max ?? 2;
  const multi = [];
  for (const g of groups) {
    for (const r of g.rows) {
      if (r.kind !== 'start') continue;
      const { startKey, endKey } = occurrenceDaySpan(r.occ);
      multi.push({ occ: r.occ, s: epochDayOfKey(startKey), e: epochDayOfKey(endKey) });
    }
  }
  const out = new Map();
  if (multi.length === 0) return out;
  multi.sort((a, b) => a.s - b.s || chronological(a.occ, b.occ));
  for (const g of groups) {
    const ed = epochDayOfKey(g.dayKey);
    const covering = multi.filter((m) => m.s <= ed && ed <= m.e).map((m) => m.occ);
    if (covering.length === 0) continue;
    out.set(g.dayKey, { items: covering.slice(0, max), more: Math.max(0, covering.length - max) });
  }
  return out;
}
