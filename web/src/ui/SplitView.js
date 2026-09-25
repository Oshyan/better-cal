// Split view: a strip of dense weeks over a continuous list, scrolled as one.
//
// The weeks are the month grid (titles, spanning bars, "+N"), held at their
// comfortable row height; a handle trades weeks for list, anywhere from part
// of a week to the whole pane, remembered per device. The list is the agenda
// with every day present, empty runs folded to one line each.
//
// One position in time drives both. Scrolling the list moves it; so does
// scrolling over the weeks, which scrubs through the days (one row's height
// of travel is one week) rather than sliding the weeks themselves. Either
// way the weeks hold still through a week, with the day being read
// outlined, and glide one row as that day passes Saturday into Sunday: the
// current week never slides half out of view. With three or more weeks
// showing, the current one sits in the middle row, a week of context above
// it; with fewer, at the top. Navigation (Today, the arrows, jump to date,
// tapping a day) goes through the list, and the weeks follow it.

import { html, useRef, useState, useEffect, useLayoutEffect, useCallback } from '../../vendor/index.js';
import { MonthGrid } from './MonthGrid.js';
import { AgendaList } from './AgendaList.js';
import { epochDayOfKey, keyOfEpochDay } from '../lib/dates.js';
import { rowIndexOfEpochDay, firstEpochDayOfRow } from './monthmath.js';
import { noteSplitDay } from '../lib/splitday.js';

const ROWS_KEY = 'bc-split-rows';
const DEFAULT_ROWS = 2;
const MIN_ROWS = 0.6;


function readRows() {
  try {
    const v = parseFloat(localStorage.getItem(ROWS_KEY));
    return v > 0 ? v : DEFAULT_ROWS;
  } catch { return DEFAULT_ROWS; }
}

export function SplitView({ gridProps, listProps, scrollSeq }) {
  const boxRef = useRef(null);
  const gridWrapRef = useRef(null);
  const grid = useRef(null); // {el, rowH, minWeek} from MonthGrid
  const list = useRef(null); // {el, groups} from AgendaList
  const lead = useRef('list');
  const curCell = useRef(null);
  const curKey = useRef(null);
  const [rows, setRows] = useState(readRows);
  const rowsRef = useRef(rows);
  rowsRef.current = rows;

  // --- positions in time, as fractional epoch days ---------------------------

  // The day at the list's top edge, and how far into it.
  const listDay = useCallback(() => {
    const L = list.current;
    if (!L || !L.el || !L.groups.length) return null;
    const gs = L.groups;
    const s = L.el.scrollTop;
    let lo = 0, hi = gs.length - 1;
    while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (gs[mid].top <= s) lo = mid; else hi = mid - 1; }
    const g = gs[lo];
    const days = g.gap ? epochDayOfKey(g.gapTo) - epochDayOfKey(g.dayKey) + 1 : 1;
    const f = Math.min(1, Math.max(0, (s - g.top) / (g.height || 1)));
    return epochDayOfKey(g.dayKey) + f * days;
  }, []);

  const listTopOf = useCallback((ed) => {
    const gs = list.current.groups;
    if (!gs.length) return 0;
    const day = Math.floor(ed);
    let lo = 0, hi = gs.length - 1;
    while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (epochDayOfKey(gs[mid].dayKey) <= day) lo = mid; else hi = mid - 1; }
    const g = gs[lo];
    const g0 = epochDayOfKey(g.dayKey);
    if (day < g0) return g.top;
    const days = g.gap ? epochDayOfKey(g.gapTo) - g0 + 1 : 1;
    return g.top + Math.min(1, (ed - g0) / days) * g.height;
  }, []);

  // Which row the current week sits in: the middle one (one above centre
  // when the count is even: the weeks ahead matter more) once three or more
  // show, else the top.
  const leadRows = () => {
    const G = grid.current;
    const shown = Math.floor(G.el.clientHeight / G.rowH + 0.05);
    return shown >= 3 ? Math.floor((shown - 1) / 2) : 0;
  };

  // Hold through the week, glide across Saturday into Sunday.
  const gridTopOf = useCallback((ed) => {
    const G = grid.current;
    const row = rowIndexOfEpochDay(Math.floor(ed), 7);
    const p = ed - firstEpochDayOfRow(row, 7);
    return Math.max(0, (row - G.minWeek - leadRows()) * G.rowH + G.rowH * Math.min(1, Math.max(0, p - 6)));
  }, []);

  // Outline the day being read. The weeks render only rows near the view, so
  // the cell comes and goes as they scroll; the observer in the wiring below
  // puts the outline back whenever they redraw.
  const mark = useCallback((ed) => {
    if (ed == null) return;
    const key = keyOfEpochDay(Math.floor(ed));
    curKey.current = key;
    noteSplitDay(key);
    const G = grid.current;
    if (!G || !G.el) return;
    const cell = G.el.querySelector(`.bc-cell[data-day="${key}"]`);
    if (cell === curCell.current && cell && cell.classList.contains('is-split-cur')) return;
    if (curCell.current) curCell.current.classList.remove('is-split-cur');
    if (cell) cell.classList.add('is-split-cur');
    curCell.current = cell;
  }, []);

  const gridFromList = useCallback(() => {
    // Until the list has landed on its day it sits at its top, which can be
    // years back; following it there would load those years instead.
    // Nor while it waits for a navigation's day to load: the weeks sit on
    // that day meanwhile, which is what loads it.
    if (!list.current || !list.current.anchored || list.current.pending) return;
    const ed = listDay();
    const G = grid.current;
    if (ed == null || !G || !G.el) return;
    const top = gridTopOf(ed);
    if (Math.abs(G.el.scrollTop - top) > 0.5) G.el.scrollTop = top;
    mark(ed);
  }, [listDay, gridTopOf, mark]);

  // Scrubbing over the weeks: move the day being read by a distance in
  // pixels, one row's height per week, through the list (the weeks follow).
  const scrubEd = useRef(null);
  const scrubBy = useCallback((dy) => {
    const L = list.current;
    const G = grid.current;
    if (!L || !L.el || !G || !L.groups.length) return false;
    if (L.pending) L.cancelPending();
    if (scrubEd.current == null) scrubEd.current = listDay();
    if (scrubEd.current == null) return false;
    const gs = L.groups;
    const first = epochDayOfKey(gs[0].dayKey);
    const lastG = gs[gs.length - 1];
    const last = epochDayOfKey(lastG.gapTo || lastG.dayKey) + 0.999;
    scrubEd.current = Math.min(last, Math.max(first, scrubEd.current + (dy / G.rowH) * 7));
    lead.current = 'list';
    L.el.scrollTop = listTopOf(scrubEd.current);
    return true;
  }, [listDay, listTopOf]);

  // --- wiring --------------------------------------------------------------

  useEffect(() => {
    const ge = grid.current && grid.current.el;
    const le = list.current && list.current.el;
    if (!ge || !le) return undefined;
    const onList = () => { if (lead.current === 'list') gridFromList(); };
    // The weeks never scroll on their own: anything that moves them (their
    // own re-layout when the handle changes their height, their anchoring on
    // a navigation) is put straight back, before they load a window for the
    // wrong place. Except while a navigation waits for its day to load:
    // then they sit on that day, which is what loads it.
    const onGrid = () => { gridFromList(); };
    const takeList = () => {
      lead.current = 'list';
      stopFling();
      scrubEd.current = null;
      if (list.current && list.current.pending) list.current.cancelPending();
    };
    const opts = { passive: true, capture: true };
    le.addEventListener('scroll', onList, { passive: true });
    ge.addEventListener('scroll', onGrid, { passive: true });

    // Scrubbing over the weeks. A wheel or trackpad already brings its own
    // momentum; a finger gets a fling that slows to a stop.
    let fling = 0;
    const stopFling = () => { cancelAnimationFrame(fling); fling = 0; };
    const dragging = () => document.body.classList.contains('bc-dragging');
    const onWheel = (e) => {
      if (dragging()) return;
      e.preventDefault();
      stopFling();
      scrubEd.current = null;
      const unit = e.deltaMode === 1 ? 16 : e.deltaMode === 2 ? ge.clientHeight : 1;
      scrubBy(e.deltaY * unit);
    };
    // The rest of a touch is followed on the element it started on: the
    // weeks render only rows near the view, so a row under the finger can
    // be replaced as they glide, and a phone keeps sending that touch to the
    // replaced element, where it would never bubble up to the grid.
    let touch = null;
    const onTouchStart = (e) => {
      stopFling();
      scrubEd.current = null;
      if (touch) release();
      if (e.touches.length !== 1) return;
      const y = e.touches[0].clientY;
      touch = { y, t: performance.now(), v: 0, el: e.target };
      touch.el.addEventListener('touchmove', onTouchMove, { passive: false });
      touch.el.addEventListener('touchend', onTouchEnd, { passive: true });
      touch.el.addEventListener('touchcancel', onTouchEnd, { passive: true });
    };
    const release = () => {
      if (!touch) return;
      touch.el.removeEventListener('touchmove', onTouchMove, { passive: false });
      touch.el.removeEventListener('touchend', onTouchEnd, { passive: true });
      touch.el.removeEventListener('touchcancel', onTouchEnd, { passive: true });
    };
    const onTouchMove = (e) => {
      if (!touch || dragging()) return;
      const y = e.touches[0].clientY;
      const now = performance.now();
      const dy = touch.y - y;
      const dt = Math.max(1, now - touch.t);
      touch.v = 0.8 * (dy / dt) + 0.2 * touch.v;
      touch.y = y;
      touch.t = now;
      if (e.cancelable) e.preventDefault();
      scrubBy(dy);
    };
    const onTouchEnd = () => {
      if (!touch) return;
      release();
      if (dragging()) { touch = null; return; }
      let v = touch.v; // px per ms
      touch = null;
      if (Math.abs(v) < 0.05) return;
      let last = performance.now();
      const step = (now) => {
        const dt = now - last;
        last = now;
        if (!scrubBy(v * dt)) { fling = 0; return; }
        v *= Math.pow(0.995, dt);
        fling = Math.abs(v) > 0.02 ? requestAnimationFrame(step) : 0;
      };
      fling = requestAnimationFrame(step);
    };
    ge.addEventListener('wheel', onWheel, { passive: false });
    ge.addEventListener('touchstart', onTouchStart, { passive: true });
    // Rows arrive after the weeks scroll (they render what is near the view):
    // re-find the outlined day's cell each time rows are added or replaced.
    // Only child-list changes, so the outline's own class change never loops.
    const remark = () => {
      const key = curKey.current;
      if (!key) return;
      const cell = ge.querySelector(`.bc-cell[data-day="${key}"]`);
      if (!cell || cell.classList.contains('is-split-cur')) return;
      if (curCell.current) curCell.current.classList.remove('is-split-cur');
      cell.classList.add('is-split-cur');
      curCell.current = cell;
    };
    const mo = new MutationObserver(remark);
    mo.observe(ge, { childList: true, subtree: true });
    for (const ev of ['pointerdown', 'wheel', 'touchstart']) le.addEventListener(ev, takeList, opts);
    return () => {
      le.removeEventListener('scroll', onList);
      ge.removeEventListener('scroll', onGrid);
      mo.disconnect();
      stopFling();
      ge.removeEventListener('wheel', onWheel, { passive: false });
      ge.removeEventListener('touchstart', onTouchStart, { passive: true });
      release();
      for (const ev of ['pointerdown', 'wheel', 'touchstart']) le.removeEventListener(ev, takeList, opts);
    };
  }, [gridFromList, scrubBy, listDay, mark]);

  // Navigation goes through the list: AgendaList scrolls itself to the
  // anchor (its effect runs before this one), or waits for that day to load
  // while the weeks, left free, sit on it and load it. Either way the list
  // leads from here; its scroll event brings the weeks along when it lands.
  useEffect(() => {
    lead.current = 'list';
    gridFromList();
  }, [scrollSeq, gridFromList]);

  // --- the handle ----------------------------------------------------------

  const maxRows = () => {
    const box = boxRef.current;
    const G = grid.current;
    const head = gridWrapRef.current && gridWrapRef.current.querySelector('.bc-month-head');
    if (!box || !G) return 8;
    const handle = box.querySelector('.bc-split-handle');
    const room = box.clientHeight - (handle ? handle.offsetHeight : 18) - (head ? head.offsetHeight : 0);
    return Math.max(MIN_ROWS, room / G.rowH);
  };

  const applyHeight = useCallback(() => {
    const wrap = gridWrapRef.current;
    const G = grid.current;
    if (!wrap || !G) return;
    const head = wrap.querySelector('.bc-month-head');
    const r = Math.min(maxRows(), Math.max(MIN_ROWS, rowsRef.current));
    wrap.style.height = Math.round((head ? head.offsetHeight : 0) + r * G.rowH) + 'px';
  }, []);

  useLayoutEffect(() => { applyHeight(); gridFromList(); });
  useEffect(() => {
    const box = boxRef.current;
    if (!box) return undefined;
    const ro = new ResizeObserver(() => applyHeight());
    ro.observe(box);
    return () => ro.disconnect();
  }, [applyHeight]);

  const save = (r) => { try { localStorage.setItem(ROWS_KEY, String(Math.round(r * 100) / 100)); } catch { /* per-device only */ } };
  const drag = useRef(null);
  const onHandleDown = (e) => {
    if (e.button !== undefined && e.button !== 0) return;
    e.preventDefault();
    lead.current = 'list';
    drag.current = { y: e.clientY, rows: rowsRef.current };
    e.currentTarget.setPointerCapture(e.pointerId);
  };
  const onHandleMove = (e) => {
    if (!drag.current || !grid.current) return;
    const r = Math.min(maxRows(), Math.max(MIN_ROWS, drag.current.rows + (e.clientY - drag.current.y) / grid.current.rowH));
    setRows(r);
  };
  const onHandleUp = () => {
    if (!drag.current) return;
    drag.current = null;
    save(rowsRef.current);
  };
  const onHandleKey = (e) => {
    const step = e.key === 'ArrowDown' ? 0.5 : e.key === 'ArrowUp' ? -0.5 : 0;
    if (!step) return;
    e.preventDefault();
    const r = Math.min(maxRows(), Math.max(MIN_ROWS, rowsRef.current + step));
    setRows(r);
    save(r);
  };

  // A real height from the very first render. Unbounded for even one
  // layout, the grid measured itself as tall as its whole ten-year scroll,
  // took all of it as visible and asked for twenty years of events at once.
  // The layout effect then sets the exact height from the measured row.
  const estRow = grid.current ? grid.current.rowH : 110;
  const firstH = Math.round(28 + Math.max(MIN_ROWS, rows) * estRow);

  return html`<div class="bc-split" ref=${boxRef}>
    <div class="bc-split-grid" ref=${gridWrapRef} style=${`height:${firstH}px`}>
      <${MonthGrid} ...${gridProps} fixedRows=${true} glide=${false} apiRef=${grid} />
    </div>
    <div
      class="bc-split-handle" role="separator" aria-orientation="horizontal" tabindex="0"
      aria-label="Drag to show more weeks or more of the list"
      title="Drag to show more weeks or more of the list"
      onPointerDown=${onHandleDown} onPointerMove=${onHandleMove}
      onPointerUp=${onHandleUp} onPointerCancel=${onHandleUp} onKeyDown=${onHandleKey}
    ><span></span></div>
    <div class="bc-split-list">
      <${AgendaList} ...${listProps} gaps=${true} sortMode="time" apiRef=${list} />
    </div>
  </div>`;
}
