// MonthGrid: infinite vertically-scrolling month/multi-week view.
//
// Virtualization: one big scroll container with a spacer sized for the whole
// supported range (about 10 years either side of today). Week rows are
// absolutely positioned at weekIndex * rowH and only the visible window
// (plus a small buffer) is rendered. Scrolling is continuous; months are
// marked by gutter labels and alternating cell backgrounds, never page jumps.
//
// Drag interactions manipulate DOM classes directly (no re-render per move)
// and emit intents on drop.

import { html, useState, useRef, useMemo, useEffect, useLayoutEffect, useCallback } from '../../vendor/index.js';
import {
  keyOfEpochDay, epochDayOfKey, weekIndexOfKey, firstEpochDayOfWeek,
  dayKeysOfWeek, dateOfDayKey, addDaysDate, parseISO, toISOWithOffset,
  todayKey, fmtMonthShort, dayKeyOfISO, getWeekStart,
} from '../lib/dates.js';
import {
  visibleWeekRange, weekTop, totalHeight, occurrenceDaySpan, segmentSpan,
  monthStartsInRange, dominantMonthOfWeek,
} from './monthmath.js';
import { assignLanes } from './layout.js';
import { EventChip, EventBar } from './EventChip.js';
import { startPointerDrag, cloneAsGhost } from './DragController.js';

const WEEK_SPAN = 522; // weeks either side of today (~10 years)
const CHIP_ROW = 22;   // px per chip/bar lane
const CELL_HEAD = 24;  // px reserved for the day number row
const GUTTER_W = 44;   // px month-label gutter

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

// Index occurrences into per-week multi-day bars and per-day single chips.
function indexOccurrences(occurrences) {
  const byDay = new Map();
  const barsByWeek = new Map();
  for (const occ of occurrences) {
    if (occ.attendance === 'hidden') continue;
    const { startKey, endKey } = occurrenceDaySpan(occ);
    if (startKey === endKey && !occ.allDay) {
      let list = byDay.get(startKey);
      if (!list) byDay.set(startKey, (list = []));
      list.push(occ);
    } else {
      for (const seg of segmentSpan(startKey, endKey)) {
        let list = barsByWeek.get(seg.weekIndex);
        if (!list) barsByWeek.set(seg.weekIndex, (list = []));
        list.push({ occ, seg });
      }
    }
  }
  for (const list of byDay.values()) list.sort((a, b) => a.start < b.start ? -1 : 1);
  return { byDay, barsByWeek };
}

export function MonthGrid({
  occurrences, calendars, visibleRows = 6, scrollKey, scrollSeq = 0, dimSet,
  onRequestWindow, onVisibleMonthChange, onOpenEvent, onExpandDay,
  onCreateRange, onMoveEvent, onResizeEvent,
}) {
  const scrollRef = useRef(null);
  const [viewH, setViewH] = useState(600);
  const [range, setRange] = useState({ first: 0, last: 0 });
  const centerWeek = useMemo(() => weekIndexOfKey(todayKey()), []);
  const minWeek = centerWeek - WEEK_SPAN;
  const maxWeek = centerWeek + WEEK_SPAN;
  const rowH = Math.max(64, Math.floor(viewH / visibleRows));

  const idx = useMemo(() => indexOccurrences(occurrences), [occurrences]);

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
  // not move the user in time: restore scrollTop from the last known top week
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
  // by the top-week restore effect above. rowH is read fresh via a ref so this
  // effect does not re-fire (and yank the user back) when geometry changes.
  const rowHRef = useRef(rowH);
  rowHRef.current = rowH;
  useLayoutEffect(() => {
    const el = scrollRef.current;
    if (!el || !scrollKey) return;
    const w = weekIndexOfKey(scrollKey);
    el.scrollTop = weekTop(w, minWeek, rowHRef.current);
    topWeekRef.current = w;
    recompute();
  }, [scrollSeq]); // eslint-disable-line

  // Report visible month + demand data for the visible window.
  useEffect(() => {
    if (range.last <= range.first) return;
    if (onVisibleMonthChange) onVisibleMonthChange(dominantMonthOfWeek(range.top != null ? range.top : range.first));
    if (onRequestWindow) {
      const start = dateOfDayKey(keyOfEpochDay(firstEpochDayOfWeek(range.first - 4)));
      const end = dateOfDayKey(keyOfEpochDay(firstEpochDayOfWeek(range.last + 5)));
      onRequestWindow({ start: toISOWithOffset(start), end: toISOWithOffset(end) });
    }
  }, [range.first, range.last, range.top]);

  // --- drag helpers ---------------------------------------------------------

  const dropRef = useRef({ key: null, els: [] });

  const dayKeyAtPoint = useCallback((pt) => {
    const el = scrollRef.current;
    if (!el) return null;
    const r = el.getBoundingClientRect();
    const colW = (r.width - GUTTER_W) / 7;
    const col = Math.max(0, Math.min(6, Math.floor((pt.x - r.left - GUTTER_W) / colW)));
    const yContent = pt.y - r.top + el.scrollTop;
    const wi = Math.max(minWeek, Math.min(maxWeek, minWeek + Math.floor(yContent / rowH)));
    return keyOfEpochDay(firstEpochDayOfWeek(wi) + col);
  }, [minWeek, maxWeek, rowH]);

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

  const dragCreate = useCallback((ev) => {
    if (ev.target !== ev.currentTarget) return; // only empty cell space
    if (ev.pointerType === 'touch') return; // touch: long-press-lift on events only, scroll wins
    const originKey = ev.currentTarget.dataset.day;
    let moved = false;
    startPointerDrag(ev, {
      scrollEl: scrollRef.current,
      onMove: (pt) => {
        moved = true;
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
        const a = Math.min(epochDayOfKey(originKey), epochDayOfKey(k));
        const b = Math.max(epochDayOfKey(originKey), epochDayOfKey(k));
        if (!moved || a === b) {
          // Plain click: quick create a 1-hour event at 9am that day.
          const base = dateOfDayKey(keyOfEpochDay(a));
          const s = new Date(base.getFullYear(), base.getMonth(), base.getDate(), 9, 0);
          onCreateRange({ start: toISOWithOffset(s), end: toISOWithOffset(new Date(s.getTime() + 3600000)), allDay: false });
        } else {
          onCreateRange({
            start: toISOWithOffset(dateOfDayKey(keyOfEpochDay(a))),
            end: toISOWithOffset(dateOfDayKey(keyOfEpochDay(b + 1))),
            allDay: true,
          });
        }
      },
      onCancel: () => {
        highlightDays([]);
        // Treat an unmoved tap-cancel as nothing; click create handled in onDrop.
      },
    });
  }, [dayKeyAtPoint, highlightDays, onCreateRange]);

  // --- render ---------------------------------------------------------------

  const weeks = [];
  const tKey = todayKey();
  const capacity = Math.max(1, Math.floor((rowH - CELL_HEAD - 4) / CHIP_ROW));
  for (let wi = range.first; wi <= range.last; wi++) {
    weeks.push(html`<${WeekRow}
      key=${wi} weekIndex=${wi} top=${weekTop(wi, minWeek, rowH)} rowH=${rowH}
      byDay=${idx.byDay} bars=${idx.barsByWeek.get(wi)} calendars=${calendars}
      capacity=${capacity} todayKey=${tKey} dimSet=${dimSet}
      onOpenEvent=${onOpenEvent} onExpandDay=${onExpandDay}
      dragMoveOcc=${dragMoveOcc} dragResizeOcc=${dragResizeOcc} dragCreate=${dragCreate}
    />`);
  }

  const labels = monthStartsInRange(range.first, range.last).map(({ weekIndex, year, month }) => html`<div
    key=${'m' + year + '-' + month}
    class="bc-gutter-label"
    style=${`top:${weekTop(weekIndex, minWeek, rowH) + 2}px`}
  >${fmtMonthShort(new Date(year, month - 1, 1))}<span class="bc-gutter-year">${month === 1 ? year : ''}</span></div>`);

  return html`<div class="bc-month" style=${`--bc-gutter-w:${GUTTER_W}px`}>
    <div class="bc-month-head">
      <div class="bc-month-head-gutter"></div>
      ${weekdayNames().map((w) => html`<div key=${w} class="bc-month-head-day">${w}</div>`)}
    </div>
    <div class="bc-month-scroll" ref=${scrollRef}>
      <div class="bc-month-spacer" style=${`height:${totalHeight(minWeek, maxWeek, rowH)}px`}>
        ${labels}
        ${weeks}
      </div>
    </div>
  </div>`;
}

function WeekRow({
  weekIndex, top, rowH, byDay, bars, calendars, capacity, todayKey: tKey,
  dimSet, onOpenEvent, onExpandDay, dragMoveOcc, dragResizeOcc, dragCreate,
}) {
  const keys = dayKeysOfWeek(weekIndex);
  const barList = bars || [];
  const lanes = assignLanes(barList.map((b) => ({ id: b.occ.instanceId + ':' + b.seg.startCol, startCol: b.seg.startCol, endCol: b.seg.endCol })));
  let barLaneCount = 0;
  for (const l of lanes.values()) barLaneCount = Math.max(barLaneCount, l + 1);
  const maxBarLanes = Math.min(barLaneCount, Math.max(1, capacity - 1));
  const chipStartLane = Math.min(barLaneCount, maxBarLanes);
  // Hidden bar segments count toward each covered day's overflow.
  const extraByCol = new Array(7).fill(0);
  const visibleBars = [];
  for (const b of barList) {
    const lane = lanes.get(b.occ.instanceId + ':' + b.seg.startCol);
    if (lane < maxBarLanes) visibleBars.push({ ...b, lane });
    else for (let c = b.seg.startCol; c <= b.seg.endCol; c++) extraByCol[c]++;
  }

  const cells = keys.map((k, col) => {
    const [y, m, d] = k.split('-').map(Number);
    const singles = byDay.get(k) || [];
    const room = Math.max(0, capacity - chipStartLane);
    const overflowFromBars = extraByCol[col];
    const needsMore = singles.length + overflowFromBars > room;
    const shown = needsMore ? Math.max(0, room - 1) : singles.length;
    const hidden = singles.length - shown + overflowFromBars;
    return html`<div
      key=${k}
      class="bc-cell${m % 2 === 0 ? ' alt-month' : ''}${k === tKey ? ' is-today' : ''}${d === 1 ? ' is-month-start' : ''}"
      data-day=${k}
      onPointerDown=${dragCreate}
    >
      <button
        type="button" class="bc-daynum" aria-label=${'Expand day ' + k}
        onClick=${(e) => { e.stopPropagation(); if (onExpandDay) onExpandDay(k); }}
      >${d === 1 ? fmtMonthShort(dateOfDayKey(k)) + ' 1' : d}</button>
      <div class="bc-cell-chips" style=${`top:${CELL_HEAD + chipStartLane * 22}px`}>
        ${singles.slice(0, shown).map((occ) => html`<${EventChip}
          key=${occ.instanceId} occ=${occ} cal=${calendars[occ.calendarId]}
          dimmed=${dimSet && dimSet.has(occ.instanceId)}
          onOpen=${onOpenEvent}
          onPointerDown=${(e) => dragMoveOcc(occ, e)}
        />`)}
        ${hidden > 0 && html`<button
          type="button" class="bc-more"
          onClick=${(e) => { e.stopPropagation(); if (onExpandDay) onExpandDay(k); }}
          onPointerDown=${(e) => e.stopPropagation()}
        >+${hidden} more</button>`}
      </div>
    </div>`;
  });

  return html`<div class="bc-week" style=${`top:${top}px;height:${rowH}px`}>
    <div class="bc-week-gutter"></div>
    <div class="bc-week-days">
      ${cells}
      <div class="bc-week-bars" style=${`top:${CELL_HEAD}px`}>
        ${visibleBars.map(({ occ, seg, lane }) => html`<div
          key=${occ.instanceId + ':' + seg.startCol}
          class="bc-bar-slot"
          style=${`left:${(seg.startCol / 7) * 100}%;width:${((seg.endCol - seg.startCol + 1) / 7) * 100}%;top:${lane * 22}px`}
        >
          <${EventBar}
            occ=${occ} cal=${calendars[occ.calendarId]} seg=${seg}
            dimmed=${dimSet && dimSet.has(occ.instanceId)}
            onOpen=${onOpenEvent}
            onPointerDown=${(e) => dragMoveOcc(occ, e)}
            onEdgePointerDown=${(edge, e) => dragResizeOcc(occ, edge, e)}
          />
        </div>`)}
      </div>
    </div>
  </div>`;
}
