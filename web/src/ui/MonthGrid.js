// MonthGrid: infinite vertically-scrolling month/multi-week view, generalized
// over a column count. columns=7 is the classic month grid (week rows,
// weekday header, week-start aware). columns=3 is the "3 day" ribbon: rows
// are consecutive 3-day chunks anchored to stable epoch-day boundaries
// (floor(epochDay / 3), never week-aligned), each cell carries its own
// weekday+date label, and the weekday header row is hidden.
//
// Virtualization: one big scroll container with a spacer sized for the whole
// supported range (about 10 years either side of today). Rows are absolutely
// positioned at rowIndex * rowH and only the visible window (plus a small
// buffer) is rendered. Scrolling is continuous; months are marked by gutter
// labels and alternating cell backgrounds, never page jumps.
//
// Drag interactions manipulate DOM classes directly (no re-render per move)
// and emit intents on drop. Creating is one step (GCal-style): a click or
// drag on empty cell space opens the editor pre-filled with the selection;
// dismissing the editor is the cancel.

import { html, useState, useRef, useMemo, useEffect, useLayoutEffect, useCallback } from '../../vendor/index.js';
import {
  keyOfEpochDay, epochDayOfKey, weekIndexOfKey,
  dayKeysOfWeek, dateOfDayKey, addDaysDate, parseISO, toISOWithOffset,
  todayKey, fmtMonthShort, fmtWeekdayShort, getWeekStart,
} from '../lib/dates.js';
import {
  visibleWeekRange, weekTop, totalHeight, occurrenceDaySpan, rowSpanSegments,
  rowIndexOfDayKey, firstEpochDayOfRow, dayKeysOfRow, isWeekendEpochDay,
  monthStartsInRange, dominantMonthOfRows,
} from './monthmath.js';
import { assignLanes } from './layout.js';
import { EventChip, EventBar, TripBand } from './EventChip.js';
import { startPointerDrag, cloneAsGhost } from './DragController.js';
import { normalizeDayRange, dayRangeDraft } from '../lib/quickcreate.js';

const WEEK_SPAN = 522; // weeks either side of today (~10 years)
const CHIP_ROW = 22;   // px per chip/bar lane
const CHIP_ROW_MOBILE = 15; // compact single-line pills at <=600px
const MOBILE_LANES = 3;     // pill lanes per day on mobile 7-col, then dots + "+N"
const CELL_HEAD = 24;  // px reserved for the day number row
const GUTTER_W = 44;   // px month-label gutter
const MOBILE_QUERY = '(max-width: 600px)';
const BAND_H = 16;        // px per trip backdrop band lane (desktop)
const BAND_H_MOBILE = 12; // compact band lane at <=600px
const MAX_BAND_LANES = 2; // stacked bands per row; more overflow into day expand

// Weekday header labels, in the configured week-start order (settings can
// change it at runtime, so this is computed lazily and cached per start day).
let weekdayCache = { start: null, names: [] };
function weekdayNames() {
  const start = getWeekStart();
  if (weekdayCache.start !== start) {
    const fmt = new Intl.DateTimeFormat(undefined, { weekday: 'short' });
    const base = dayKeysOfWeek(weekIndexOfKey('2024-01-08')); // any reference week
    weekdayCache = { start, names: base.map((k) => fmt.format(dateOfDayKey(k))) };
  }
  return weekdayCache.names;
}

// Index occurrences into per-row multi-day bars, per-day single chips, and
// per-row trip backdrop bands (containers never fight for chip/bar lanes:
// they render as tinted bands along the top of their day span).
function indexOccurrences(occurrences, columns) {
  const byDay = new Map();
  const barsByRow = new Map();
  const bandsByRow = new Map();
  for (const occ of occurrences) {
    if (occ.attendance === 'hidden') continue;
    const { startKey, endKey } = occurrenceDaySpan(occ);
    if (occ.isContainer) {
      for (const seg of rowSpanSegments(startKey, endKey, columns)) {
        let list = bandsByRow.get(seg.rowIndex);
        if (!list) bandsByRow.set(seg.rowIndex, (list = []));
        list.push({ occ, seg });
      }
      continue;
    }
    if (startKey === endKey && !occ.allDay) {
      let list = byDay.get(startKey);
      if (!list) byDay.set(startKey, (list = []));
      list.push(occ);
    } else {
      for (const seg of rowSpanSegments(startKey, endKey, columns)) {
        let list = barsByRow.get(seg.rowIndex);
        if (!list) barsByRow.set(seg.rowIndex, (list = []));
        list.push({ occ, seg });
      }
    }
  }
  for (const list of byDay.values()) list.sort((a, b) => a.start < b.start ? -1 : 1);
  return { byDay, barsByRow, bandsByRow };
}

// ISO 8601 week number (GCal's gutter numbering). Thursday-anchored.
function isoWeekOfDate(d) {
  const t = new Date(d.getFullYear(), d.getMonth(), d.getDate());
  t.setDate(t.getDate() + 3 - ((t.getDay() + 6) % 7));
  const jan4 = new Date(t.getFullYear(), 0, 4);
  return 1 + Math.round(((t - jan4) / 86400000 - 3 + ((jan4.getDay() + 6) % 7)) / 7);
}

export function MonthGrid({
  occurrences, calendars, visibleRows = 6, columns = 7, scrollKey, scrollSeq = 0, dimSet, nowMs,
  onRequestWindow, onVisibleMonthChange, onOpenEvent, onExpandDay, onOpenDay,
  onCreateRange, onMoveEvent, onResizeEvent,
}) {
  const scrollRef = useRef(null);
  const [viewH, setViewH] = useState(600);
  const [range, setRange] = useState({ first: 0, last: 0 });
  // Same ~10-year span regardless of row width.
  const rowSpan = useMemo(() => Math.ceil((WEEK_SPAN * 7) / columns), [columns]);
  const centerRow = useMemo(() => rowIndexOfDayKey(todayKey(), columns), [columns]);
  const minWeek = centerRow - rowSpan;
  const maxWeek = centerRow + rowSpan;
  const rowH = Math.max(64, Math.floor(viewH / visibleRows));

  // Mobile month cells render every event as a compact text pill (same shape
  // for timed and all-day) in tighter lanes; overflow becomes dots + "+N".
  const [mobile, setMobile] = useState(() => window.matchMedia(MOBILE_QUERY).matches);
  useEffect(() => {
    const mq = window.matchMedia(MOBILE_QUERY);
    const onChange = () => setMobile(mq.matches);
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, []);
  const chipRow = mobile ? CHIP_ROW_MOBILE : CHIP_ROW;

  const idx = useMemo(() => indexOccurrences(occurrences, columns), [occurrences, columns]);

  // Measure viewport height.
  useLayoutEffect(() => {
    const el = scrollRef.current;
    if (!el) return;
    const ro = new ResizeObserver(() => setViewH(el.clientHeight || 600));
    ro.observe(el);
    setViewH(el.clientHeight || 600);
    return () => ro.disconnect();
  }, []);

  const topWeekRef = useRef(null);
  // Geometry lives in a ref so recompute has ONE stable identity for the whole
  // component life. A version with rowH captured in its closure can be invoked
  // late (rAF-throttled scroll events) after rowH changed, computing the range
  // against the wrong geometry and blanking the grid.
  const geomRef = useRef({ rowH, minWeek, maxWeek });
  geomRef.current = { rowH, minWeek, maxWeek };
  const recompute = useCallback(() => {
    const el = scrollRef.current;
    if (!el) return;
    const g = geomRef.current;
    const r = visibleWeekRange(el.scrollTop, el.clientHeight, g.rowH, g.minWeek, g.maxWeek, 3);
    r.top = Math.max(g.minWeek, Math.min(g.maxWeek, g.minWeek + Math.floor(el.scrollTop / g.rowH)));
    topWeekRef.current = r.top;
    setRange((prev) => (prev.first === r.first && prev.last === r.last && prev.top === r.top ? prev : r));
  }, []);

  // Scroll handling via rAF throttle.
  useEffect(() => {
    const el = scrollRef.current;
    if (!el) return;
    let raf = 0;
    const onScroll = () => {
      if (raf) return;
      raf = requestAnimationFrame(() => { raf = 0; recompute(); });
    };
    el.addEventListener('scroll', onScroll, { passive: true });
    return () => { el.removeEventListener('scroll', onScroll); cancelAnimationFrame(raf); };
  }, [recompute]);

  // Geometry changes (viewport resize, layout switch, container remount) must
  // not move the user in time: restore scrollTop from the last known top row
  // rather than trusting pixel positions across a rowH change.
  useLayoutEffect(() => {
    const el = scrollRef.current;
    if (!el) return;
    if (topWeekRef.current != null) {
      el.scrollTop = weekTop(topWeekRef.current, minWeek, rowH);
    }
    recompute();
  }, [rowH, viewH, minWeek, recompute]);

  // Programmatic scroll to an anchor date (today button, arrows, search jump).
  // Fires only on explicit navigation (scrollSeq); geometry changes are handled
  // by the top-row restore effect above. rowH is read fresh via a ref so this
  // effect does not re-fire (and yank the user back) when geometry changes.
  const rowHRef = useRef(rowH);
  rowHRef.current = rowH;
  useLayoutEffect(() => {
    const el = scrollRef.current;
    if (!el || !scrollKey) return;
    const w = rowIndexOfDayKey(scrollKey, columns);
    el.scrollTop = weekTop(w, minWeek, rowHRef.current);
    topWeekRef.current = w;
    recompute();
  }, [scrollSeq]); // eslint-disable-line

  // Report visible month + demand data for the visible window.
  useEffect(() => {
    if (range.last <= range.first) return;
    if (onVisibleMonthChange) {
      // Dominant month over the WHOLE visible window, not just the top row:
      // with the tail of an old month as the top row, the window is mostly
      // the next month and the toolbar/mini-month should say so.
      const top = range.top != null ? range.top : range.first;
      const rows = Math.min(
        Math.max(1, Math.round(viewH / rowH)), // rows the viewport shows
        range.last - top + 1,                  // never beyond rendered rows
      );
      onVisibleMonthChange(dominantMonthOfRows(top, rows, columns));
    }
    if (onRequestWindow) {
      const start = dateOfDayKey(keyOfEpochDay(firstEpochDayOfRow(range.first, columns) - 28));
      const end = dateOfDayKey(keyOfEpochDay(firstEpochDayOfRow(range.last + 1, columns) + 28));
      onRequestWindow({ start: toISOWithOffset(start), end: toISOWithOffset(end) });
    }
  }, [range.first, range.last, range.top, columns, viewH, rowH]);

  // --- drag helpers ---------------------------------------------------------

  const dropRef = useRef({ key: null, els: [] });

  const dayKeyAtPoint = useCallback((pt) => {
    const el = scrollRef.current;
    if (!el) return null;
    const r = el.getBoundingClientRect();
    const colW = (r.width - GUTTER_W) / columns;
    const col = Math.max(0, Math.min(columns - 1, Math.floor((pt.x - r.left - GUTTER_W) / colW)));
    const yContent = pt.y - r.top + el.scrollTop;
    const wi = Math.max(minWeek, Math.min(maxWeek, minWeek + Math.floor(yContent / rowH)));
    return keyOfEpochDay(firstEpochDayOfRow(wi, columns) + col);
  }, [minWeek, maxWeek, rowH, columns]);

  const highlightDays = useCallback((keys) => {
    for (const e of dropRef.current.els) e.classList.remove('bc-drop-target');
    dropRef.current.els = [];
    const el = scrollRef.current;
    if (!el) return;
    for (const k of keys) {
      const cell = el.querySelector(`[data-day="${k}"]`);
      if (cell) { cell.classList.add('bc-drop-target'); dropRef.current.els.push(cell); }
    }
  }, []);

  const dragMoveOcc = useCallback((occ, ev) => {
    ev.stopPropagation();
    const src = ev.currentTarget;
    const { startKey, endKey } = occurrenceDaySpan(occ);
    const spanDays = epochDayOfKey(endKey) - epochDayOfKey(startKey);
    const grabKey = dayKeyAtPoint({ x: ev.clientX, y: ev.clientY }) || startKey;
    const grabOffset = epochDayOfKey(grabKey) - epochDayOfKey(startKey);
    startPointerDrag(ev, {
      makeGhost: () => cloneAsGhost(src),
      scrollEl: scrollRef.current,
      onMove: (pt) => {
        const k = dayKeyAtPoint(pt);
        if (!k) return;
        const newStart = epochDayOfKey(k) - grabOffset;
        const keys = [];
        for (let i = 0; i <= spanDays; i++) keys.push(keyOfEpochDay(newStart + i));
        dropRef.current.key = keyOfEpochDay(newStart);
        highlightDays(keys);
      },
      onDrop: () => {
        const target = dropRef.current.key;
        highlightDays([]);
        if (!target) return;
        const delta = epochDayOfKey(target) - epochDayOfKey(startKey);
        if (delta === 0) return;
        const s = addDaysDate(parseISO(occ.start), delta);
        const e = addDaysDate(parseISO(occ.end), delta);
        if (onMoveEvent) onMoveEvent({ instanceId: occ.instanceId, newStart: toISOWithOffset(s), newEnd: toISOWithOffset(e) });
      },
      onCancel: () => highlightDays([]),
    });
  }, [dayKeyAtPoint, highlightDays, onMoveEvent]);

  const dragResizeOcc = useCallback((occ, edge, ev) => {
    ev.stopPropagation();
    const { startKey, endKey } = occurrenceDaySpan(occ);
    startPointerDrag(ev, {
      scrollEl: scrollRef.current,
      onLift: () => {},
      onMove: (pt) => {
        const k = dayKeyAtPoint(pt);
        if (!k) return;
        dropRef.current.key = k;
        const a = edge === 'end' ? epochDayOfKey(startKey) : Math.min(epochDayOfKey(k), epochDayOfKey(endKey));
        const b = edge === 'end' ? Math.max(epochDayOfKey(k), epochDayOfKey(startKey)) : epochDayOfKey(endKey);
        const keys = [];
        for (let i = a; i <= b; i++) keys.push(keyOfEpochDay(i));
        highlightDays(keys);
      },
      onDrop: () => {
        const target = dropRef.current.key;
        highlightDays([]);
        if (!target) return;
        const s0 = parseISO(occ.start);
        const e0 = parseISO(occ.end);
        let s = s0, e = e0;
        if (edge === 'end') {
          const day = Math.max(epochDayOfKey(target), epochDayOfKey(startKey));
          const base = dateOfDayKey(keyOfEpochDay(day));
          e = occ.allDay
            ? addDaysDate(base, 1)
            : new Date(base.getFullYear(), base.getMonth(), base.getDate(), e0.getHours(), e0.getMinutes());
          if (e <= s) e = new Date(s.getTime() + 30 * 60000);
        } else {
          const day = Math.min(epochDayOfKey(target), epochDayOfKey(endKey));
          const base = dateOfDayKey(keyOfEpochDay(day));
          s = occ.allDay
            ? base
            : new Date(base.getFullYear(), base.getMonth(), base.getDate(), s0.getHours(), s0.getMinutes());
          if (s >= e) s = new Date(e.getTime() - 30 * 60000);
        }
        if (onResizeEvent) onResizeEvent({ instanceId: occ.instanceId, newStart: toISOWithOffset(s), newEnd: toISOWithOffset(e), edge });
      },
      onCancel: () => highlightDays([]),
    });
  }, [dayKeyAtPoint, highlightDays, onResizeEvent]);

  // Drag across cells selects a day range; on release the confirm chip
  // appears at the pointer (Create opens the editor, Cancel dismisses).
  // Plain clicks never lift the drag, so they arrive via the cell's onClick
  // (cellClickSelect) instead; touch taps reach the same handler.
  const dragCreate = useCallback((ev) => {
    if (ev.target !== ev.currentTarget) return; // only empty cell space
    if (ev.pointerType === 'touch') return; // touch: long-press-lift on events only, scroll wins
    const originKey = ev.currentTarget.dataset.day;
    startPointerDrag(ev, {
      scrollEl: scrollRef.current,
      onMove: (pt) => {
        const k = dayKeyAtPoint(pt) || originKey;
        const a = Math.min(epochDayOfKey(originKey), epochDayOfKey(k));
        const b = Math.max(epochDayOfKey(originKey), epochDayOfKey(k));
        dropRef.current.key = k;
        const keys = [];
        for (let i = a; i <= b; i++) keys.push(keyOfEpochDay(i));
        highlightDays(keys);
      },
      onDrop: () => {
        highlightDays([]);
        if (!onCreateRange) return;
        const k = dropRef.current.key || originKey;
        const { startKey, endKey } = normalizeDayRange(originKey, k);
        // Straight into the editor (GCal-style): dismissing it is the cancel.
        onCreateRange(dayRangeDraft(startKey, endKey));
      },
      onCancel: () => highlightDays([]),
    });
  }, [dayKeyAtPoint, highlightDays, onCreateRange]);

  // Plain click (mouse) or tap (touch) on empty cell space: straight into
  // the editor for that day. Real drags never reach here (the drag
  // controller swallows the synthetic click after a lift), and taps that
  // scrolled produce no click.
  const cellClickSelect = useCallback((ev) => {
    if (ev.target !== ev.currentTarget) return;
    if (!onCreateRange) return;
    onCreateRange(dayRangeDraft(ev.currentTarget.dataset.day, ev.currentTarget.dataset.day));
  }, [onCreateRange]);

  // Hover/focus "+" in a cell corner: same path.
  const quickCreateDay = useCallback((k) => {
    if (onCreateRange) onCreateRange(dayRangeDraft(k, k));
  }, [onCreateRange]);

  // --- render ---------------------------------------------------------------

  const ribbon = columns !== 7;
  const weeks = [];
  const tKey = todayKey();
  let capacity = Math.max(1, Math.floor((rowH - CELL_HEAD - 4) / chipRow));
  // The mobile lane cap exists for 7 cramped columns; ribbon cells are wide
  // enough to keep every lane the row height affords.
  if (mobile && !ribbon) capacity = Math.min(capacity, MOBILE_LANES);
  const sel = null; // selection tint retired with the two-step confirm chip
  for (let wi = range.first; wi <= range.last; wi++) {
    weeks.push(html`<${WeekRow}
      key=${wi} weekIndex=${wi} columns=${columns} ribbon=${ribbon}
      top=${weekTop(wi, minWeek, rowH)} rowH=${rowH}
      byDay=${idx.byDay} bars=${idx.barsByRow.get(wi)} bands=${idx.bandsByRow.get(wi)} calendars=${calendars}
      capacity=${capacity} chipRow=${chipRow} mobile=${mobile} todayKey=${tKey} dimSet=${dimSet} nowMs=${nowMs}
      sel=${sel}
      onOpenEvent=${onOpenEvent} onExpandDay=${onExpandDay} onOpenDay=${onOpenDay}
      dragMoveOcc=${dragMoveOcc} dragResizeOcc=${dragResizeOcc} dragCreate=${dragCreate}
      cellClickSelect=${cellClickSelect} quickCreateDay=${quickCreateDay}
    />`);
  }

  // Sticky month labels: one absolutely positioned container per month spans
  // that month's rows; the label inside is position: sticky (top: 0), so the
  // current month's abbreviation pins at the gutter top while scrolling until
  // the next month's container pushes it off. The window is widened by enough
  // rows either side to catch the month whose start row is scrolled out of
  // the render range (its label still needs to pin while its rows show).
  const lookRows = Math.ceil(31 / columns) + 1;
  const monthStarts = monthStartsInRange(
    Math.max(minWeek, range.first - lookRows),
    Math.min(maxWeek, range.last + lookRows),
    columns,
  );
  const labels = monthStarts.map(({ weekIndex, year, month }, i) => {
    const next = monthStarts[i + 1];
    const top = weekTop(weekIndex, minWeek, rowH);
    const bottom = next ? weekTop(next.weekIndex, minWeek, rowH) : top + lookRows * rowH;
    return html`<div
      key=${'m' + year + '-' + month}
      class="bc-gutter-month"
      style=${`top:${top}px;height:${bottom - top}px`}
    >
      <div class="bc-gutter-label">${fmtMonthShort(new Date(year, month - 1, 1))}<span class="bc-gutter-year">${month === 1 ? year : ''}</span></div>
    </div>`;
  });

  // Crisp full-width divider (gutter included) at the top of each month's
  // first row; the per-cell accent cue stays as the mid-row marker.
  const rules = monthStarts.map(({ weekIndex, year, month }) => html`<div
    key=${'r' + year + '-' + month}
    class="bc-month-rule"
    style=${`top:${weekTop(weekIndex, minWeek, rowH)}px`}
  ></div>`);

  return html`<div
    class="bc-month${ribbon ? ' is-ribbon' : ''}"
    style=${`--bc-gutter-w:${GUTTER_W}px;--chip-h:${chipRow - 2}px;--bc-cols:${columns}`}
  >
    ${!ribbon && html`<div class="bc-month-head">
      <div class="bc-month-head-gutter"></div>
      ${weekdayNames().map((w) => html`<div key=${w} class="bc-month-head-day">${w}</div>`)}
    </div>`}
    <div class="bc-month-scroll" ref=${scrollRef}>
      <div class="bc-month-spacer" style=${`height:${totalHeight(minWeek, maxWeek, rowH)}px`}>
        ${rules}
        ${labels}
        ${weeks}
      </div>
    </div>
  </div>`;
}

function WeekRow({
  weekIndex, columns, ribbon, top, rowH, byDay, bars, bands, calendars, capacity, chipRow, mobile,
  todayKey: tKey, dimSet, nowMs, sel, onOpenEvent, onExpandDay, onOpenDay, dragMoveOcc, dragResizeOcc, dragCreate,
  cellClickSelect, quickCreateDay,
}) {
  const keys = dayKeysOfRow(weekIndex, columns);
  // Week-of-year in the gutter (7-col rows only): taken from the row's
  // midpoint so week-start settings never straddle the ISO boundary oddly.
  const weekNum = !ribbon && keys.length === 7 ? isoWeekOfDate(dateOfDayKey(keys[3])) : null;

  // Trip backdrop bands: stacked under the day-number strip, capped at
  // MAX_BAND_LANES (extras overflow into the day expand via the +N counter).
  // Bars and chips shift down by the band block so bands never eat lanes.
  const bandList = bands || [];
  const bandH = mobile ? BAND_H_MOBILE : BAND_H;
  const bandLanes = assignLanes(bandList.map((b) => ({ id: b.occ.instanceId + ':' + b.seg.startCol, startCol: b.seg.startCol, endCol: b.seg.endCol })));
  let bandLaneCount = 0;
  for (const l of bandLanes.values()) bandLaneCount = Math.max(bandLaneCount, l + 1);
  const shownBandLanes = Math.min(bandLaneCount, MAX_BAND_LANES);
  const bandOffset = shownBandLanes * bandH;
  const bandExtraByCol = new Array(columns).fill(0);
  const visibleBands = [];
  for (const b of bandList) {
    const lane = bandLanes.get(b.occ.instanceId + ':' + b.seg.startCol);
    if (lane < MAX_BAND_LANES) visibleBands.push({ ...b, lane });
    else for (let c = b.seg.startCol; c <= b.seg.endCol; c++) bandExtraByCol[c]++;
  }
  // Rows carrying bands have less chip room; recompute against the real
  // remaining height (never below one lane).
  const cap = Math.min(capacity, Math.max(1, Math.floor((rowH - CELL_HEAD - bandOffset - 4) / chipRow)));

  const barList = bars || [];
  const lanes = assignLanes(barList.map((b) => ({ id: b.occ.instanceId + ':' + b.seg.startCol, startCol: b.seg.startCol, endCol: b.seg.endCol })));
  let barLaneCount = 0;
  for (const l of lanes.values()) barLaneCount = Math.max(barLaneCount, l + 1);
  const maxBarLanes = Math.min(barLaneCount, Math.max(1, cap - 1));
  const chipStartLane = Math.min(barLaneCount, maxBarLanes);
  // Hidden bar segments count toward each covered day's overflow.
  const extraByCol = new Array(columns).fill(0);
  const visibleBars = [];
  for (const b of barList) {
    const lane = lanes.get(b.occ.instanceId + ':' + b.seg.startCol);
    if (lane < maxBarLanes) visibleBars.push({ ...b, lane });
    else for (let c = b.seg.startCol; c <= b.seg.endCol; c++) extraByCol[c]++;
  }

  const cells = keys.map((k, col) => {
    const [y, m, d] = k.split('-').map(Number);
    const singles = byDay.get(k) || [];
    const room = Math.max(0, cap - chipStartLane);
    const overflowFromBars = extraByCol[col] + bandExtraByCol[col];
    const needsMore = singles.length + overflowFromBars > room;
    const shown = needsMore ? Math.max(0, room - 1) : singles.length;
    const hidden = singles.length - shown + overflowFromBars;
    // Ribbon rows have no weekday header, so each cell labels itself
    // ("Mon 3"; month name on month start: "Aug 1"). Weekends get a subtle
    // tint for orientation.
    const ed = epochDayOfKey(k);
    const weekend = ribbon && isWeekendEpochDay(ed);
    const selected = sel && ed >= sel.a && ed <= sel.b;
    const label = d === 1
      ? fmtMonthShort(dateOfDayKey(k)) + ' 1'
      : (ribbon ? fmtWeekdayShort(dateOfDayKey(k)) + ' ' + d : d);
    return html`<div
      key=${k}
      class="bc-cell${m % 2 === 0 ? ' alt-month' : ''}${weekend ? ' is-weekend' : ''}${k === tKey ? ' is-today' : ''}${d === 1 ? ' is-month-start' : ''}${selected ? ' bc-drop-target' : ''}"
      data-day=${k}
      onPointerDown=${dragCreate}
      onClick=${cellClickSelect}
    >
      <button
        type="button" class="bc-daynum" aria-label=${'Open day view for ' + k}
        title="Open day view"
        onPointerDown=${(e) => e.stopPropagation()}
        onClick=${(e) => { e.stopPropagation(); if (onOpenDay) onOpenDay(k); else if (onExpandDay) onExpandDay(k); }}
      >${label}</button>
      <button
        type="button" class="bc-cell-headstrip" aria-label=${'List events on ' + k}
        title="Show this day's events here"
        onPointerDown=${(e) => e.stopPropagation()}
        onClick=${(e) => { e.stopPropagation(); if (onExpandDay) onExpandDay(k); }}
      ><span class="bc-headstrip-glyph" aria-hidden="true">⌄</span></button>
      <button
        type="button" class="bc-cell-add" aria-label=${'New event on ' + k}
        title="New event"
        onPointerDown=${(e) => e.stopPropagation()}
        onClick=${(e) => { e.stopPropagation(); if (quickCreateDay) quickCreateDay(k); }}
      >+</button>
      <div class="bc-cell-chips" style=${`top:${CELL_HEAD + bandOffset + chipStartLane * chipRow}px`}>
        ${singles.slice(0, shown).map((occ) => html`<${EventChip}
          key=${occ.instanceId} occ=${occ} cal=${calendars[occ.calendarId]}
          dimmed=${dimSet && dimSet.has(occ.instanceId)} nowMs=${nowMs}
          onOpen=${onOpenEvent}
          onPointerDown=${(e) => dragMoveOcc(occ, e)}
        />`)}
        ${hidden > 0 && html`<button
          type="button" class="bc-more"
          onClick=${(e) => { e.stopPropagation(); if (onExpandDay) onExpandDay(k); }}
          onPointerDown=${(e) => e.stopPropagation()}
        >
          ${mobile && singles.slice(shown, shown + 3).map((o) => html`<span
            key=${o.instanceId} class="bc-more-dot"
            style=${`background:${(calendars[o.calendarId] && calendars[o.calendarId].color) || '#888'}`}
          ></span>`)}
          +${hidden}${mobile ? '' : ' more'}
        </button>`}
      </div>
    </div>`;
  });

  return html`<div class="bc-week" style=${`top:${top}px;height:${rowH}px`}>
    <div class="bc-week-gutter">${weekNum != null && html`<span class="bc-weeknum" title=${'Week ' + weekNum}>${weekNum}</span>`}</div>
    <div class="bc-week-days">
      ${cells}
      ${visibleBands.length > 0 && html`<div class="bc-week-bands" style=${`top:${CELL_HEAD}px`}>
        ${visibleBands.map(({ occ, seg, lane }) => html`<div
          key=${'band:' + occ.instanceId + ':' + seg.startCol}
          class="bc-band-slot"
          style=${`left:${(seg.startCol / columns) * 100}%;width:${((seg.endCol - seg.startCol + 1) / columns) * 100}%;top:${lane * bandH}px;height:${bandH}px`}
        >
          <${TripBand}
            occ=${occ} cal=${calendars[occ.calendarId]} seg=${seg}
            dimmed=${dimSet && dimSet.has(occ.instanceId)} nowMs=${nowMs}
            onOpen=${onOpenEvent}
          />
        </div>`)}
      </div>`}
      <div class="bc-week-bars" style=${`top:${CELL_HEAD + bandOffset}px`}>
        ${visibleBars.map(({ occ, seg, lane }) => html`<div
          key=${occ.instanceId + ':' + seg.startCol}
          class="bc-bar-slot"
          style=${`left:${(seg.startCol / columns) * 100}%;width:${((seg.endCol - seg.startCol + 1) / columns) * 100}%;top:${lane * chipRow}px`}
        >
          <${EventBar}
            occ=${occ} cal=${calendars[occ.calendarId]} seg=${seg}
            dimmed=${dimSet && dimSet.has(occ.instanceId)} nowMs=${nowMs}
            onOpen=${onOpenEvent}
            onPointerDown=${(e) => dragMoveOcc(occ, e)}
            onEdgePointerDown=${(edge, e) => dragResizeOcc(occ, edge, e)}
          />
        </div>`)}
      </div>
    </div>
  </div>`;
}
