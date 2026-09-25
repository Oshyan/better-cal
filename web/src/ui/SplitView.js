// Split view: a strip of dense weeks over a continuous list, scrolled as one.
//
// The weeks are the month grid (titles, spanning bars, "+N"), held at their
// comfortable row height; a handle trades weeks for list, anywhere from part
// of a week to the whole pane, remembered per device. The list is the agenda
// with every day present, empty runs folded to one line each.
//
// Whichever part you touch leads and the other follows. Scrolling the list,
// the weeks hold still through a week and glide to the next as the list
// passes Saturday into Sunday: always moving with it, never jumping. The day
// at the top of the list is outlined in the weeks. Navigation (Today, the
// arrows, jump to date, tapping a day) goes through the list, and the weeks
// follow it.

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

  // Hold through the week, glide across Saturday into Sunday.
  const gridTopOf = useCallback((ed) => {
    const G = grid.current;
    const row = rowIndexOfEpochDay(Math.floor(ed), 7);
    const p = ed - firstEpochDayOfRow(row, 7);
    return (row - G.minWeek) * G.rowH + G.rowH * Math.min(1, Math.max(0, p - 6));
  }, []);

  const gridDay = useCallback(() => {
    const G = grid.current;
    const rf = G.el.scrollTop / G.rowH;
    const row = G.minWeek + Math.floor(rf);
    return firstEpochDayOfRow(row, 7) + (rf - Math.floor(rf)) * 7;
  }, []);

  // Outline the day being read. Re-applied on every sync: the weeks render
  // only rows near the view, so the cell comes and goes as they scroll.
  const mark = useCallback((ed) => {
    if (ed == null) return;
    const key = keyOfEpochDay(Math.floor(ed));
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

  const listFromGrid = useCallback(() => {
    const L = list.current;
    const G = grid.current;
    if (!L || !L.el || !G || !G.el || !L.groups.length) return;
    const ed = gridDay();
    L.el.scrollTop = listTopOf(ed);
    mark(ed);
  }, [gridDay, listTopOf, mark]);

  // --- wiring --------------------------------------------------------------

  useEffect(() => {
    const ge = grid.current && grid.current.el;
    const le = list.current && list.current.el;
    if (!ge || !le) return undefined;
    const onList = () => { if (lead.current === 'list') gridFromList(); };
    // The list leads unless the weeks are being touched: anything else that
    // moves them (their own re-layout when the handle changes their height)
    // is put straight back, before they load a window for the wrong place.
    const onGrid = () => { if (lead.current === 'grid') listFromGrid(); else gridFromList(); };
    const takeGrid = () => { lead.current = 'grid'; };
    const takeList = () => { lead.current = 'list'; };
    const opts = { passive: true, capture: true };
    le.addEventListener('scroll', onList, { passive: true });
    ge.addEventListener('scroll', onGrid, { passive: true });
    for (const ev of ['pointerdown', 'wheel', 'touchstart']) {
      ge.addEventListener(ev, takeGrid, opts);
      le.addEventListener(ev, takeList, opts);
    }
    // The grid re-lays itself out when its height changes (the handle, a
    // window resize); follow the list again once it has.
    let raf = 0;
    const settle = () => {
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(() => { raf = requestAnimationFrame(() => { if (lead.current === 'list') gridFromList(); }); });
    };
    const ro = new ResizeObserver(settle);
    ro.observe(ge);
    return () => {
      le.removeEventListener('scroll', onList);
      ge.removeEventListener('scroll', onGrid);
      for (const ev of ['pointerdown', 'wheel', 'touchstart']) {
        ge.removeEventListener(ev, takeGrid, opts);
        le.removeEventListener(ev, takeList, opts);
      }
      ro.disconnect();
      cancelAnimationFrame(raf);
    };
  }, [gridFromList, listFromGrid, listDay, mark]);

  // Navigation goes through the list (AgendaList scrolls itself to the
  // anchor); the weeks follow it, overriding the grid's own anchor placement.
  useEffect(() => {
    lead.current = 'list';
    const t = setTimeout(gridFromList, 0);
    return () => clearTimeout(t);
  }, [scrollSeq, gridFromList]);

  // The list's layout changes as events load; keep the weeks on its day.
  useEffect(() => {
    if (lead.current !== 'list') return undefined;
    const raf = requestAnimationFrame(gridFromList);
    return () => cancelAnimationFrame(raf);
  }, [listProps.occurrences, gridFromList]);

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

  useLayoutEffect(() => { applyHeight(); });
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
