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
import { gridMove } from './gridmove.js';
import {
  keyOfEpochDay, epochDayOfKey, weekIndexOfKey,
  dayKeysOfWeek, dateOfDayKey, addDaysDate, parseISO, toISOWithOffset,
  todayKey, fmtMonthShort, fmtWeekdayShort, getWeekStart, byStart, fmtDayLong,
} from '../lib/dates.js';
import {
  visibleWeekRange, weekTop, totalHeight, occurrenceDaySpan, isOvernight, rowSpanSegments, shiftOccurrenceDays, resizeOccurrenceDays,
  spanRowCount, rowSegmentAt, clampDayRange, LONG_SPAN_ROWS,
  rowIndexOfDayKey, firstEpochDayOfRow, dayKeysOfRow, isWeekendEpochDay,
  monthStartsInRange, dominantMonthOfRows, ANCHOR_LINE,
  planCell, fixedGeometry, fitGeometry, weekRangeIn, dominantMonthOfWeeks,
} from './monthmath.js';
import { assignLanes } from './layout.js';
import { EventChip, EventBar, TripBand, HiddenMark } from './EventChip.js';
import { canReschedule, isBand } from '../lib/bands.js';
import { ContextStrip, DayLabel } from './ContextStrip.js';
import { contextByDay, dayLabelsByDay, isContext } from '../lib/context.js';
import { Icon } from './icons.js';
import { useLongPress, RowMenu } from './rowmenu.js';
import { PHONE_QUERY } from '../lib/breakpoints.js';
import { startPointerDrag, cloneAsGhost, externalDropTarget, setDropRowHighlight, coveredAt } from './DragController.js';
import { normalizeDayRange, dayRangeDraft } from '../lib/quickcreate.js';

const WEEK_SPAN = 522; // weeks either side of today (~10 years)
const CHIP_ROW = 22;   // px per chip/bar lane
const CHIP_ROW_MOBILE = 15; // compact single-line pills at <=600px
const CELL_HEAD = 24;  // px reserved for the day number row
// Events a day cell should hold before "+N more" takes over. THE tuning knob
// for how the grid gives way as the window shrinks: it is the row height the
// grid refuses to squash below while it still has a row it could clip
// instead. Higher means rows hold their height longer and the sixth row goes
// sooner — which is the priority, since a squashed row hides events on every
// day at once while a clipped row hides nothing (it is one scroll away).
//
// Seven events puts the crossover at a ~1090px scroller: a tall window still
// shows six rows, and everything below that trades the sixth row away before
// it starts shrinking the other five. At four events the crossover sat near
// 700px, which left a wide band squashing rows for no benefit.
const COMFORT_CHIPS = 7;
const GUTTER_W = 44;   // px month-label gutter
const MOBILE_QUERY = PHONE_QUERY;
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
  // Spans too long to pre-segment (monthmath LONG_SPAN_ROWS): one record each,
  // segmented per drawn row by rowEntries() below.
  const longBars = [];
  const longBands = [];
  const place = (byRow, long, occ, startKey, endKey) => {
    if (spanRowCount(startKey, endKey, columns) > LONG_SPAN_ROWS) {
      long.push({ occ, startKey, endKey });
      return;
    }
    for (const seg of rowSpanSegments(startKey, endKey, columns)) {
      let list = byRow.get(seg.rowIndex);
      if (!list) byRow.set(seg.rowIndex, (list = []));
      list.push({ occ, seg });
    }
  };
  for (const occ of occurrences) {
    if (occ.attendance === 'hidden') continue;
    // Context is drawn in the day's head as tokens (ContextStrip), never as
    // a chip or bar, so it costs the day no event slots.
    if (isContext(occ)) continue;
    const { startKey, endKey } = occurrenceDaySpan(occ);
    if (occ.isContainer) {
      place(bandsByRow, longBands, occ, startKey, endKey);
      continue;
    }
    if (!occ.allDay && (startKey === endKey || isOvernight(occ))) {
      let list = byDay.get(startKey);
      if (!list) byDay.set(startKey, (list = []));
      list.push(occ);
    } else {
      place(barsByRow, longBars, occ, startKey, endKey);
    }
  }
  for (const list of byDay.values()) list.sort(byStart);
  return { byDay, barsByRow, bandsByRow, longBars, longBands, rowCache: new Map() };
}

// What one row draws: its pre-segmented entries plus the segment of any very
// long span that reaches it. Cached per index and row so the array keeps its
// identity between renders (the row component is memoized on it); the cache
// dies with the index, which is rebuilt whenever the occurrences change.
function rowEntries(idx, kind, wi, columns) {
  const byRow = kind === 'bars' ? idx.barsByRow : idx.bandsByRow;
  const long = kind === 'bars' ? idx.longBars : idx.longBands;
  if (long.length === 0) return byRow.get(wi);
  const key = kind + ':' + wi;
  if (idx.rowCache.has(key)) return idx.rowCache.get(key);
  let out = byRow.get(wi);
  for (const l of long) {
    const seg = rowSegmentAt(l.startKey, l.endKey, wi, columns);
    if (seg) out = [...(out || []), { occ: l.occ, seg }];
  }
  idx.rowCache.set(key, out);
  return out;
}

// One row's lanes: trip bands (at most MAX_BAND_LANES) along the top, then
// multi-day bars (at most barLaneLimit(bandOffset) lanes), what didn't get a
// lane counted against each day it covers, and where each day's single chips
// start. The row draws from this, and rows that fit their contents measure
// from it, so the two can't disagree about a row's height.
//
// The filter box hides events that don't match (dimSet, drawn with
// display:none), so they take no lane and no slot: an invisible bar used to
// keep its lane and leave an empty row above the matches. Quiet events
// (grayed out by a dim filter) yield their lanes to the rest and don't count
// toward "+N" (planCell, #126).
function weekLanes(bars, bands, dimSet, columns, barLaneLimit, chipRow, bandH) {
  const shownByFilter = (o) => !(dimSet && dimSet.has(o.occ ? o.occ.instanceId : o.instanceId));
  const isQuiet = (occ) => !!occ.dimmed;
  const bandList = (bands || []).filter(shownByFilter);
  const bandLanes = assignLanes(
    // Trips before people's away times and plugin bands, as week view orders
    // them: two away times pushed a trip into "+N" (#184). Those still count
    // in "+N" when they don't fit; only the order changes.
    bandList.map((b) => ({ id: b.occ.instanceId + ':' + b.seg.startCol, startCol: b.seg.startCol, endCol: b.seg.endCol, quiet: isQuiet(b.occ) || isBand(b.occ) })),
    MAX_BAND_LANES,
  );
  let bandLaneCount = 0;
  for (const l of bandLanes.values()) if (l < MAX_BAND_LANES) bandLaneCount = Math.max(bandLaneCount, l + 1);
  const bandOffset = Math.min(bandLaneCount, MAX_BAND_LANES) * bandH;
  const bandExtraByCol = new Array(columns).fill(0);
  const visibleBands = [];
  for (const b of bandList) {
    const lane = bandLanes.get(b.occ.instanceId + ':' + b.seg.startCol);
    if (lane < MAX_BAND_LANES) visibleBands.push({ ...b, lane });
    else if (!isQuiet(b.occ)) for (let c = b.seg.startCol; c <= b.seg.endCol; c++) bandExtraByCol[c]++;
  }
  // Lanes are reserved PER COLUMN, not per row: a band or bar on Wednesday
  // must not push Monday's chips down. Multi-day segments still align across
  // the columns they actually cover, because their lane index is shared.
  const bandLanesByCol = new Array(columns).fill(0);
  for (const b of visibleBands) {
    for (let c = b.seg.startCol; c <= b.seg.endCol; c++) bandLanesByCol[c] = Math.max(bandLanesByCol[c], b.lane + 1);
  }
  const barList = (bars || []).filter(shownByFilter);
  const maxBarLanes = barLaneLimit(bandOffset);
  const lanes = assignLanes(
    barList.map((b) => ({ id: b.occ.instanceId + ':' + b.seg.startCol, startCol: b.seg.startCol, endCol: b.seg.endCol, quiet: isQuiet(b.occ) })),
    maxBarLanes,
  );
  // Hidden bar segments count toward each covered day's overflow, unless
  // they're quiet.
  const extraByCol = new Array(columns).fill(0);
  const visibleBars = [];
  for (const b of barList) {
    const lane = lanes.get(b.occ.instanceId + ':' + b.seg.startCol);
    if (lane < maxBarLanes) visibleBars.push({ ...b, lane });
    else if (!isQuiet(b.occ)) for (let c = b.seg.startCol; c <= b.seg.endCol; c++) extraByCol[c]++;
  }
  const barLanesByCol = new Array(columns).fill(0);
  for (const b of visibleBars) {
    for (let c = b.seg.startCol; c <= b.seg.endCol; c++) barLanesByCol[c] = Math.max(barLanesByCol[c], b.lane + 1);
  }
  // Chips begin below whatever actually covers THIS day. Bars share one
  // row-wide top (they span columns), so a day carrying a bar clears the band
  // block too; a day with only bands clears just its own bands; a day with
  // neither starts flush under the date number.
  const chipTopByCol = barLanesByCol.map((n, col) => (n > 0
    ? CELL_HEAD + bandOffset + n * chipRow
    : CELL_HEAD + bandLanesByCol[col] * bandH));
  return { visibleBands, bandExtraByCol, bandOffset, visibleBars, extraByCol, chipTopByCol, shownByFilter, isQuiet };
}

// The height a week needs to show everything (rows that fit their contents,
// #171): every bar its own lane, every day's chips below them, and a slot
// for "+N" only on a day with trip bands beyond the two that show.
function weekNeed(idx, wi, columns, dimSet, chipRow, bandH) {
  const L = weekLanes(rowEntries(idx, 'bars', wi, columns), rowEntries(idx, 'bands', wi, columns), dimSet, columns, () => Infinity, chipRow, bandH);
  let need = CELL_HEAD + L.bandOffset + 4;
  dayKeysOfRow(wi, columns).forEach((k, col) => {
    const singles = (idx.byDay.get(k) || []).filter(L.shownByFilter).length;
    const more = L.bandExtraByCol[col] > 0 ? 1 : 0;
    need = Math.max(need, L.chipTopByCol[col] + (singles + more) * chipRow + 4);
  });
  return need;
}

// ISO 8601 week number (GCal's gutter numbering). Thursday-anchored.
function isoWeekOfDate(d) {
  const t = new Date(d.getFullYear(), d.getMonth(), d.getDate());
  t.setDate(t.getDate() + 3 - ((t.getDay() + 6) % 7));
  const jan4 = new Date(t.getFullYear(), 0, 4);
  return 1 + Math.round(((t - jan4) / 86400000 - 3 + ((jan4.getDay() + 6) % 7)) / 7);
}

export function MonthGrid({
  occurrences, calendars, visibleRows = 6, minRows = visibleRows, columns = 7,
  scrollKey, scrollSeq = 0, glide = false, dimSet, nowMs,
  onRequestWindow, onVisibleMonthChange, onOpenEvent, onExpandDay, onOpenDay,
  onCreateRange, onMoveEvent, onResizeEvent,
  onMoveSpan, onMoveTrip, onDropToCalendar, onDropToPerson, onDropToTrip,
  hiddenDays, onShowHidden,
  // Split view: rows keep their comfortable height whatever the grid's own
  // height (the handle adds or removes rows, it never squashes them), and
  // apiRef receives {el, rowH, minWeek} so the list can drive the scroll.
  fixedRows = false, apiRef,
  // The day gone to sits at the very top instead of a little above centre (#91).
  anchorAtTop = false,
  // Each week row as tall as its busiest day needs (#171). The desktop month
  // only: phones, the 3-day ribbon and Split view keep fixed rows.
  // onToggleFit puts the switch for it in the corner above the week numbers.
  fitRows = false, onToggleFit = null,
}) {
  const scrollRef = useRef(null);
  const [viewH, setViewH] = useState(600);
  const [range, setRange] = useState({ first: 0, last: 0 });
  // Same ~10-year span regardless of row width.
  const rowSpan = useMemo(() => Math.ceil((WEEK_SPAN * 7) / columns), [columns]);
  const centerRow = useMemo(() => rowIndexOfDayKey(todayKey(), columns), [columns]);
  const minWeek = centerRow - rowSpan;
  const maxWeek = centerRow + rowSpan;

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

  // Row height. Dividing the viewport by visibleRows unconditionally meant a
  // short window squashed every row to fit six — and six is a month's WORST
  // case, not its usual one. At 600px that left three events per day before
  // "+N more" took over, on a grid where the typical month needs five rows.
  //
  // This grid is not paginated by month: it is a continuous week ribbon with
  // its own scroll, and the toolbar names the dominant month rather than
  // framing one. So a row that does not fit is one scroll away, not lost, and
  // holding row height is worth more than fitting the sixth row.
  //
  // Three bands, continuous and never growing as the window shrinks:
  //   tall   — all visibleRows fit at a comfortable height, so use it
  //   medium — hold the comfortable height and let rows clip
  //   short  — compress, but keep minRows on screen, down to a hard floor
  // A view whose row count IS its definition (2 weeks, 3 weeks) passes
  // minRows === visibleRows, which collapses the middle band and leaves the
  // original behaviour exactly.
  const comfortH = CELL_HEAD + COMFORT_CHIPS * chipRow + 4;
  const fitAll = Math.floor(viewH / visibleRows);
  const rowH = fixedRows ? comfortH : fitAll >= comfortH
    ? fitAll
    : (viewH >= minRows * comfortH ? comfortH : Math.max(64, Math.floor(viewH / minRows)));
  if (apiRef) apiRef.current = { get el() { return scrollRef.current; }, rowH, minWeek };

  // Read by settleAnchor, which runs inside effects that must compare against
  // the height THIS render's rowH came from, not a stale closure's.
  const viewHRef = useRef(viewH);
  viewHRef.current = viewH;

  const idx = useMemo(() => indexOccurrences(occurrences, columns), [occurrences, columns]);

  // Rows that fit their contents. A week's height comes from its events as
  // they're laid out (weekNeed), never from the DOM, so it's known before
  // paint. Never shorter than the fixed row (a light week looks as it always
  // has) and never taller than the screen, so a whole week can always be
  // seen; past that, the day shows "+N more" again (capped).
  const fit = fitRows && !mobile && columns === 7 && !fixedRows;
  const bandHpx = mobile ? BAND_H_MOBILE : BAND_H;
  const maxFitH = Math.max(rowH, viewH);
  const fitHeights = useMemo(() => {
    if (!fit) return null;
    const weeks = new Set([...idx.barsByRow.keys(), ...idx.bandsByRow.keys()]);
    for (const k of idx.byDay.keys()) weeks.add(rowIndexOfDayKey(k, columns));
    const heights = new Map();
    const capped = new Set();
    for (const wi of weeks) {
      if (wi < minWeek || wi > maxWeek) continue;
      const need = weekNeed(idx, wi, columns, dimSet, chipRow, bandHpx);
      if (need <= rowH) continue;
      heights.set(wi, Math.min(need, maxFitH));
      if (need > maxFitH) capped.add(wi);
    }
    return { heights, capped };
  }, [fit, idx, dimSet, columns, chipRow, bandHpx, rowH, maxFitH, minWeek, maxWeek]);
  const geo = useMemo(
    () => (fitHeights ? fitGeometry(minWeek, maxWeek, rowH, fitHeights.heights) : fixedGeometry(minWeek, maxWeek, rowH)),
    [fitHeights, minWeek, maxWeek, rowH],
  );
  // The day's context tokens and its label (a holiday), which needs the
  // calendars to tell a holiday calendar.
  const dayInfo = useMemo(() => ({
    ctxByDay: contextByDay(occurrences, calendars),
    labelsByDay: dayLabelsByDay(occurrences, calendars),
  }), [occurrences, calendars]);

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
  // How far into that top row the scroll sits (fixed rows): a height change
  // (a phone keyboard, the URL bar, a resize) kept the row but dropped this,
  // snapping the grid to the row's edge (#184).
  const topFracRef = useRef(0);
  // Geometry lives in a ref so recompute has ONE stable identity for the whole
  // component life. A version with rowH captured in its closure can be invoked
  // late (rAF-throttled scroll events) after rowH changed, computing the range
  // against the wrong geometry and blanking the grid.
  const geomRef = useRef({ rowH, minWeek, maxWeek, geo });
  geomRef.current = { rowH, minWeek, maxWeek, geo };
  const recompute = useCallback(() => {
    const el = scrollRef.current;
    if (!el) return;
    const g = geomRef.current;
    let r;
    if (g.geo.fit) {
      r = weekRangeIn(g.geo, el.scrollTop, el.clientHeight, g.minWeek, g.maxWeek, 3);
      r.top = g.geo.weekAt(el.scrollTop);
      r.topOffset = (el.scrollTop - g.geo.top(r.top)) / g.geo.height(r.top);
    } else {
      r = visibleWeekRange(el.scrollTop, el.clientHeight, g.rowH, g.minWeek, g.maxWeek, 3);
      const exact = el.scrollTop / g.rowH;
      r.top = Math.max(g.minWeek, Math.min(g.maxWeek, g.minWeek + Math.floor(exact)));
      // How much of that top row is scrolled off, 0..1. The month label samples
      // rows from r.top, and a row that is 90% above the fold should not get an
      // equal vote in deciding which month you are looking at — with tall rows
      // that one stale row was enough to keep naming the previous month, which
      // in turn made the Next-month button appear to do nothing (it steps from
      // the reported month, so it kept recomputing the month already on screen).
      r.topOffset = exact - Math.floor(exact);
    }
    topWeekRef.current = r.top;
    topFracRef.current = r.topOffset || 0;
    setRange((prev) => (prev.first === r.first && prev.last === r.last && prev.top === r.top
      && Math.abs((prev.topOffset ?? 0) - r.topOffset) < 0.1 ? prev : r));
  }, []);

  // Rows that fit their contents change height as events load, which moves
  // every row below. Where you are is kept as a week and how far into it the
  // screen's top is (fitAnchorRef): scrolling sets it, going to a day sets it
  // to that day's week at its line, and a height change puts scrollTop back on
  // it, so what you're looking at doesn't move. A scroll this code made
  // itself (setTopRef) doesn't count as you scrolling.
  //
  // The distance into the week is kept in pixels while only other weeks
  // change, and scaled with the week when every row changes (a window
  // resize changes the base height): pixels would overshoot a shorter week
  // and land in the next one.
  const fitAnchorRef = useRef(null);
  const fitAnchorAt = (g, week, delta) => ({ week, delta, h: g.geo.height(week), base: g.rowH });
  const fitAnchorTop = (g, a) => g.geo.top(a.week) + (a.base === g.rowH ? a.delta : a.delta * (g.geo.height(a.week) / a.h));
  const setTopRef = useRef(null);
  const setTop = useCallback((y) => {
    const el = scrollRef.current;
    if (!el) return;
    el.scrollTop = y;
    setTopRef.current = el.scrollTop;
  }, []);

  // A glide under way (Today, the arrows): the row it's heading for. Until it
  // arrives, a geometry change re-aims it instead of setting scrollTop, which
  // cancelled the smooth scroll part way when a window load landed (#183).
  // It ends on arrival, or when you scroll yourself.
  const glideRef = useRef(null);
  const anchorTopRef = useRef(null);

  // Scroll handling via rAF throttle.
  useEffect(() => {
    const el = scrollRef.current;
    if (!el) return;
    let raf = 0;
    const endGlide = () => { glideRef.current = null; };
    const onScroll = () => {
      const gl = glideRef.current;
      if (gl && anchorTopRef.current) {
        // Arrived (or as near as the scroller goes), or moving away from the
        // target: a glide only ever closes in, so that's you scrolling (a
        // scrollbar drag, Page Down; Safari has no scrollend to say so).
        const dist = Math.abs(el.scrollTop - Math.min(anchorTopRef.current(gl.row), el.scrollHeight - el.clientHeight));
        if (dist <= 1 || (gl.dist != null && dist > gl.dist + 1)) glideRef.current = null;
        else gl.dist = dist;
      }
      const g = geomRef.current;
      if (g.geo.fit && (setTopRef.current == null || Math.abs(el.scrollTop - setTopRef.current) >= 1)) {
        const w = g.geo.weekAt(el.scrollTop);
        fitAnchorRef.current = fitAnchorAt(g, w, el.scrollTop - g.geo.top(w));
        setTopRef.current = null;
      }
      if (raf) return;
      raf = requestAnimationFrame(() => { raf = 0; recompute(); });
    };
    el.addEventListener('scroll', onScroll, { passive: true });
    const yours = ['wheel', 'touchstart', 'pointerdown', 'keydown', 'scrollend'];
    for (const t of yours) el.addEventListener(t, endGlide, { passive: true });
    return () => {
      el.removeEventListener('scroll', onScroll);
      for (const t of yours) el.removeEventListener(t, endGlide);
      cancelAnimationFrame(raf);
    };
  }, [recompute]);

  // Geometry changes (viewport resize, layout switch, container remount) must
  // not move the user in time: restore scrollTop from the last known top row
  // rather than trusting pixel positions across a rowH change.
  // A pending anchor outranks the top-row restore: on mount (and on a view
  // switch) this effect runs before the ResizeObserver has measured the real
  // viewport, so the first centring uses a placeholder height. Re-applying it
  // once the geometry settles is what keeps the anchor day mid-view instead
  // of wherever the stale height put it.
  const pendingRowRef = useRef(null);
  // True once the scroller has been positioned on an anchor at least once.
  // Before that, scrollTop is 0, which is the grid's EARLIEST week (~10 years
  // back), and any range computed from it is garbage. On a mount where the
  // scroller has no height yet, the navigation effect cannot apply the anchor
  // but still recomputes, and the demand effect below dutifully requested a
  // window from 2016 on the boot critical path before asking for today.
  const anchoredRef = useRef(false);
  // Where the scroller sits to show a row a little above centre, or at the
  // very top when the owner asked for that (gridAnchor 'top', #91).
  const anchorTop = useCallback((row) => {
    const el = scrollRef.current;
    const h = (el && el.clientHeight) || 0;
    const g = geomRef.current.geo;
    const lead = anchorAtTop ? 0 : Math.max(0, Math.round((h - g.height(row)) * ANCHOR_LINE));
    return Math.max(0, g.top(row) - lead);
  }, [anchorAtTop]);
  anchorTopRef.current = anchorTop;
  const applyAnchor = useCallback((row) => {
    const el = scrollRef.current;
    if (!el) return;
    const g = geomRef.current.geo;
    setTop(anchorTop(row));
    topWeekRef.current = Math.max(minWeek, g.weekAt(el.scrollTop));
    // The day gone to stays at its line while the weeks around it load.
    if (g.fit) fitAnchorRef.current = fitAnchorAt(geomRef.current, row, el.scrollTop - g.top(row));
    anchoredRef.current = true;
  }, [minWeek, anchorTop, setTop]);

  // An anchor is only SETTLED once the box we positioned against is the same
  // height that rowH was derived from. Until the ResizeObserver has measured
  // the scroller, viewH is still its 600px placeholder, so rowH is short and
  // weekTop() underestimates the anchor row's offset by tens of pixels PER
  // ROW — scrolling to a point far above today and leaving the previous month
  // filling the screen with today pushed to the bottom. Both the geometry
  // effect and the navigation effect clear through here so they cannot
  // disagree about when the anchor is done.
  const settleAnchor = useCallback(() => {
    const el = scrollRef.current;
    if (el && Math.abs(el.clientHeight - viewHRef.current) <= 2) pendingRowRef.current = null;
  }, []);

  // The geometry the scroller was last positioned in: switching rows between
  // fixed and fit keeps the same week, and the same part of it, on screen.
  const prevGeoRef = useRef(geo);
  useLayoutEffect(() => {
    const el = scrollRef.current;
    if (!el) return;
    const prev = prevGeoRef.current;
    prevGeoRef.current = geo;
    if (glideRef.current && pendingRowRef.current == null) {
      glideRef.current.dist = null; // re-aimed: the distance starts over
      el.scrollTo({ top: anchorTop(glideRef.current.row), behavior: 'smooth' });
      recompute();
      return;
    }
    if (pendingRowRef.current != null && el.clientHeight > 0) {
      applyAnchor(pendingRowRef.current);
      settleAnchor();
    } else if (prev !== geo && prev.fit !== geo.fit) {
      const w = prev.weekAt(el.scrollTop);
      const frac = (el.scrollTop - prev.top(w)) / prev.height(w);
      setTop(geo.top(w) + frac * geo.height(w));
      topWeekRef.current = w;
      fitAnchorRef.current = geo.fit ? fitAnchorAt(geomRef.current, w, el.scrollTop - geo.top(w)) : null;
    } else if (geo.fit && fitAnchorRef.current) {
      const g = geomRef.current;
      const a = fitAnchorRef.current;
      setTop(fitAnchorTop(g, a));
      // Kept in this geometry's terms, so the next change starts from here.
      fitAnchorRef.current = fitAnchorAt(g, a.week, scrollRef.current.scrollTop - geo.top(a.week));
    } else if (topWeekRef.current != null) {
      if (geo.fit) setTop(geo.top(topWeekRef.current));
      else el.scrollTop = weekTop(topWeekRef.current, minWeek, rowH) + topFracRef.current * rowH;
    }
    recompute();
  }, [rowH, viewH, minWeek, recompute, applyAnchor, settleAnchor, geo, setTop]);

  // Programmatic scroll to an anchor date (today button, arrows, search jump).
  // Fires only on explicit navigation (scrollSeq); geometry changes are handled
  // by the top-row restore effect above. rowH is read fresh via a ref so this
  // effect does not re-fire (and yank the user back) when geometry changes.
  const rowHRef = useRef(rowH);
  rowHRef.current = rowH;
  useLayoutEffect(() => {
    const el = scrollRef.current;
    if (!el || !scrollKey) return;
    // Land the anchor row above centre rather than pinned to the top edge:
    // the focused day needs a row or two of context before it, and a day at
    // the very top (or bottom) reads as "nothing came before".
    const w = rowIndexOfDayKey(scrollKey, columns);
    // Today and the arrows glide when the target is near, so the days in
    // between pass by (continuity) instead of the grid cutting to a new
    // place. Only once settled on real geometry, and not over long distances
    // or for people who asked for less motion.
    if (glide && anchoredRef.current && el.clientHeight > 0
        && Math.abs(el.clientHeight - viewHRef.current) <= 2
        && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      const top = anchorTop(w);
      if (Math.abs(top - el.scrollTop) <= el.clientHeight * 2.5) {
        pendingRowRef.current = null;
        // Already there (Today on today): nothing will scroll, so no glide
        // to end; one left set would pull you back on the next load.
        glideRef.current = Math.abs(top - el.scrollTop) > 1 ? { row: w, dist: null } : null;
        el.scrollTo({ top, behavior: 'smooth' });
        return;
      }
    }
    glideRef.current = null;
    pendingRowRef.current = w;
    if (el.clientHeight > 0) {
      applyAnchor(w);
      settleAnchor(); // NOT an unconditional clear: see settleAnchor
    }
    recompute();
  }, [scrollSeq]); // eslint-disable-line

  // Report visible month + demand data for the visible window.
  useEffect(() => {
    if (range.last <= range.first) return;
    // A range computed before the anchor was ever applied describes the top
    // of the scroll span, not anything the user is looking at. Neither name
    // that month nor fetch it; the anchor's own recompute re-runs this effect
    // with a real range moments later. (applyAnchor sets anchoredRef; it runs
    // from the navigation effect once the scroller has height, and from the
    // geometry effect whenever an anchor is still pending.)
    if (!anchoredRef.current) return;
    if (onVisibleMonthChange) {
      // Dominant month over the WHOLE visible window, not just the top row:
      // with the tail of an old month as the top row, the window is mostly
      // the next month and the toolbar/mini-month should say so.
      //
      // Start from the first row that is at least half on screen. Counting a
      // row that is 90% scrolled off gave the outgoing month a full vote, and
      // once rows grew tall enough that only ~4 fit, that single phantom row
      // could carry the count — the label kept naming the previous month, and
      // Next-month (which steps from the reported month) recomputed the month
      // already on screen and appeared to do nothing at all.
      const topRaw = range.top != null ? range.top : range.first;
      const top = Math.min(range.last, topRaw + ((range.topOffset ?? 0) > 0.5 ? 1 : 0));
      let rows;
      let month;
      if (geo.fit) {
        // Rows differ in height: count the weeks actually on screen, and
        // weigh each by how much of it shows, so one tall week of the old
        // month doesn't outvote the new month filling the rest.
        const el = scrollRef.current;
        const st = el ? el.scrollTop : geo.top(topRaw);
        const lastW = Math.min(range.last, geo.weekAt(st + viewH - 1));
        const weeks = [];
        for (let w = topRaw; w <= lastW; w++) {
          const t = geo.top(w);
          weeks.push({ weekIndex: w, px: Math.min(t + geo.height(w), st + viewH) - Math.max(t, st) });
        }
        rows = Math.max(1, lastW - top + 1);
        month = anchorAtTop ? dominantMonthOfRows(top, 1, columns) : dominantMonthOfWeeks(weeks, columns);
      } else {
        rows = Math.min(
          Math.max(1, Math.round(viewH / rowH)), // rows the viewport shows
          range.last - top + 1,                  // never beyond rendered rows
        );
        // With the day gone to pinned at the top (gridAnchor 'top', #91) the
        // top row is where you are: it names the month, so today's month
        // stays the label until its row scrolls away, even with the next
        // month filling the rest of the screen.
        month = dominantMonthOfRows(top, anchorAtTop ? 1 : rows, columns);
      }
      // The days on screen, so the mini-month can keep the selected day in
      // view (MiniMonth.js).
      const days = {
        start: keyOfEpochDay(firstEpochDayOfRow(top, columns)),
        end: keyOfEpochDay(firstEpochDayOfRow(top + rows, columns) - 1),
      };
      onVisibleMonthChange(month, days);
    }
    if (onRequestWindow) {
      const start = dateOfDayKey(keyOfEpochDay(firstEpochDayOfRow(range.first, columns) - 28));
      const end = dateOfDayKey(keyOfEpochDay(firstEpochDayOfRow(range.last + 1, columns) + 28));
      onRequestWindow({ start: toISOWithOffset(start), end: toISOWithOffset(end) });
    }
  }, [range.first, range.last, range.top, range.topOffset, columns, viewH, rowH, anchorAtTop, geo]);

  // --- drag helpers ---------------------------------------------------------

  const dropRef = useRef({ key: null, els: [] });

  // The day under a point, or null off the grid: a drag let go over the
  // sidebar, the toolbar or the event panel has no day to land on. Clamped to
  // the nearest column, it moved the event to a row's first or last day.
  const dayKeyAtPoint = useCallback((pt) => {
    const el = scrollRef.current;
    if (!el) return null;
    const r = el.getBoundingClientRect();
    if (pt.x < r.left + GUTTER_W || pt.x > r.right || pt.y < r.top || pt.y > r.bottom) return null;
    // Under the event panel or the phone sheet is not the calendar (#184).
    if (coveredAt(pt, el)) return null;
    const colW = (r.width - GUTTER_W) / columns;
    const col = Math.max(0, Math.min(columns - 1, Math.floor((pt.x - r.left - GUTTER_W) / colW)));
    const yContent = pt.y - r.top + el.scrollTop;
    // The geometry as it is now: with fitted rows it changes as months load
    // or a refresh lands during a drag, which kept mapping the pointer by
    // the row heights from when the drag began (#184).
    const wi = geomRef.current.geo.weekAt(yContent);
    return keyOfEpochDay(firstEpochDayOfRow(wi, columns) + col);
  }, [columns]);

  // The day keys of [a..b] that have a cell on screen. Drag highlighting used
  // to build a key, and run a DOM query, for EVERY day of the span being
  // dragged: a multi-century event meant hundreds of thousands per pointer
  // move (BC-15). Only rendered rows have cells, so only they are walked.
  const rangeRef = useRef(range);
  rangeRef.current = range;
  const renderedDayKeys = useCallback((a, b) => {
    const r = rangeRef.current;
    const clamped = clampDayRange(a, b, firstEpochDayOfRow(r.first - 1, columns), firstEpochDayOfRow(r.last + 2, columns) - 1);
    const keys = [];
    if (clamped) for (let i = clamped[0]; i <= clamped[1]; i++) keys.push(keyOfEpochDay(i));
    return keys;
  }, [columns]);

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

  // instanceId -> occurrence, for resolving drop targets (trip bands).
  const occByIdRef = useRef(new Map());
  occByIdRef.current = useMemo(() => new Map(occurrences.map((o) => [o.instanceId, o])), [occurrences]);

  // `from` is set when the drag starts outside the grid (the expanded day,
  // through gridMove): the day it was picked up from, and the caller's hooks.
  const dragMoveOcc = useCallback((occ, ev, from = null) => {
    if (String(occ.instanceId).startsWith('plg:')) return; // plugin bands are job-owned
    ev.stopPropagation();
    const src = ev.currentTarget;
    const isAvail = !!occ.availKind;
    const isTrip = !!occ.isContainer && !isAvail;
    // Plain events can also land on sidebar rows and trip bands; bands
    // themselves only reposition. Feed events are read-only content-wise, so
    // they can neither change calendar nor take people.
    const draggedCal = calendars[occ.calendarId];
    const isFeed = !!draggedCal && !draggedCal.editable;
    const isGoogle = !!draggedCal && draggedCal.provider === 'google';
    // A read-only event can still go onto a trip band, but not to another day.
    const fixed = !canReschedule(occ, draggedCal);
    const extKinds = (!isAvail && !isTrip) ? {
      cal: !isFeed && !isGoogle ? (id) => id !== occ.calendarId : false,
      person: !isFeed,
      instance: (iid) => {
        // Trips only: a person's away time or a plugin band has no event to add to.
        if (iid === occ.instanceId || /^(avail|plg):/.test(String(iid))) return false;
        const t = occByIdRef.current.get(iid);
        return !!(t && t.isContainer && !t.availKind);
      },
    } : null;
    const { startKey, endKey } = occurrenceDaySpan(occ);
    const spanDays = epochDayOfKey(endKey) - epochDayOfKey(startKey);
    const grabKey = (from && from.grabKey) || dayKeyAtPoint({ x: ev.clientX, y: ev.clientY }) || startKey;
    const grabOffset = epochDayOfKey(grabKey) - epochDayOfKey(startKey);
    // Every drag starts with no target. A touch long-press held still sends
    // no move, and its release used the previous drag's day (#183).
    dropRef.current.key = null;
    dropRef.current.ext = null;
    startPointerDrag(ev, {
      makeGhost: () => cloneAsGhost(src),
      scrollEl: scrollRef.current,
      // Option/Alt copies an ordinary event (not a trip, a person's away
      // time or a plugin band) on a calendar that takes new events.
      copyable: () => !isFeed && !occ.isContainer && !/^(avail|plg):/.test(String(occ.instanceId))
        && !(dropRef.current.ext && dropRef.current.ext.kind !== 'cal'),
      onLift: from && from.onLift ? () => from.onLift() : undefined,
      onMove: (pt) => {
        if (extKinds) {
          const ext = externalDropTarget(pt, extKinds);
          dropRef.current.ext = ext;
          if (ext) {
            setDropRowHighlight(ext.el);
            highlightDays([]);
            dropRef.current.key = null;
            return;
          }
          setDropRowHighlight(null);
        }
        const k = fixed ? null : dayKeyAtPoint(pt);
        if (!k) { dropRef.current.key = null; highlightDays([]); return; }
        const newStart = epochDayOfKey(k) - grabOffset;
        dropRef.current.key = keyOfEpochDay(newStart);
        highlightDays(renderedDayKeys(newStart, newStart + spanDays));
      },
      onDrop: (pt) => {
        const ext = dropRef.current.ext;
        dropRef.current.ext = null;
        setDropRowHighlight(null);
        highlightDays([]);
        if (from && from.onEnd) from.onEnd();
        if (ext) {
          if (ext.kind === 'cal' && onDropToCalendar) onDropToCalendar(occ, ext.id, pt);
          else if (ext.kind === 'person' && onDropToPerson) onDropToPerson(occ, ext.name, pt);
          else if (ext.kind === 'instance' && onDropToTrip) {
            const tripOcc = occByIdRef.current.get(ext.instanceId);
            if (tripOcc) onDropToTrip(occ, tripOcc);
          }
          return;
        }
        const target = dropRef.current.key;
        if (!target) return;
        const delta = epochDayOfKey(target) - epochDayOfKey(startKey);
        if (delta === 0) return;
        if (isAvail) {
          if (onMoveSpan) onMoveSpan({ spanId: occ.spanId, deltaDays: delta });
          return;
        }
        if (isTrip) {
          // The choice (trip only / trip + events) is the caller's UI; hand
          // over the drop point so the chip lands where the pointer was.
          if (onMoveTrip) onMoveTrip({ occ, deltaDays: delta, at: pt });
          return;
        }
        // All-day stays day math, timed stays wall-clock math: see
        // shiftOccurrenceDays.
        const { newStart, newEnd } = shiftOccurrenceDays(occ, delta);
        // `at` lets the caller raise the repeating-scope chip at the pointer.
        if (onMoveEvent) onMoveEvent({ instanceId: occ.instanceId, newStart, newEnd, at: pt });
      },
      onCancel: () => { setDropRowHighlight(null); dropRef.current.ext = null; highlightDays([]); if (from && from.onEnd) from.onEnd(); },
    });
  }, [dayKeyAtPoint, highlightDays, renderedDayKeys, onMoveEvent, onMoveSpan, onMoveTrip, onDropToCalendar, onDropToPerson, onDropToTrip, calendars]);
  // The expanded day's rows drag through this (gridmove.js).
  useEffect(() => {
    gridMove.start = dragMoveOcc;
    return () => { if (gridMove.start === dragMoveOcc) gridMove.start = null; };
  }, [dragMoveOcc]);

  const dragResizeOcc = useCallback((occ, edge, ev) => {
    if (!canReschedule(occ, calendars[occ.calendarId])) return; // plugin bands, read-only calendars
    ev.stopPropagation();
    const { startKey, endKey } = occurrenceDaySpan(occ);
    dropRef.current.key = null;
    dropRef.current.ext = null;
    startPointerDrag(ev, {
      scrollEl: scrollRef.current,
      onLift: () => {},
      onMove: (pt) => {
        const k = dayKeyAtPoint(pt);
        if (!k) { dropRef.current.key = null; highlightDays([]); return; }
        dropRef.current.key = k;
        const a = edge === 'end' ? epochDayOfKey(startKey) : Math.min(epochDayOfKey(k), epochDayOfKey(endKey));
        const b = edge === 'end' ? Math.max(epochDayOfKey(k), epochDayOfKey(startKey)) : epochDayOfKey(endKey);
        highlightDays(renderedDayKeys(a, b));
      },
      onDrop: (pt) => {
        const target = dropRef.current.key;
        highlightDays([]);
        if (!target) return;
        if (onResizeEvent) onResizeEvent({ instanceId: occ.instanceId, ...resizeOccurrenceDays(occ, edge, target), edge, at: pt });
      },
      onCancel: () => highlightDays([]),
    });
  }, [dayKeyAtPoint, highlightDays, renderedDayKeys, onResizeEvent, calendars]);

  // Drag across cells selects a day range; on release the confirm chip
  // appears at the pointer (Create opens the editor, Cancel dismisses).
  // Plain clicks never lift the drag, so they arrive via the cell's onClick
  // (cellClickSelect) instead; touch taps reach the same handler.
  const dragCreate = useCallback((ev) => {
    if (ev.target !== ev.currentTarget) return; // only empty cell space
    if (ev.pointerType === 'touch') return; // touch: long-press-lift on events only, scroll wins
    const originKey = ev.currentTarget.dataset.day;
    dropRef.current.key = null;
    dropRef.current.ext = null;
    startPointerDrag(ev, {
      scrollEl: scrollRef.current,
      onMove: (pt) => {
        // Off the grid, no range: letting go there creates nothing.
        const k = dayKeyAtPoint(pt);
        if (!k) { dropRef.current.key = null; highlightDays([]); return; }
        const a = Math.min(epochDayOfKey(originKey), epochDayOfKey(k));
        const b = Math.max(epochDayOfKey(originKey), epochDayOfKey(k));
        dropRef.current.key = k;
        highlightDays(renderedDayKeys(a, b));
      },
      onDrop: () => {
        highlightDays([]);
        if (!onCreateRange) return;
        const k = dropRef.current.key;
        if (!k) return;
        const { startKey, endKey } = normalizeDayRange(originKey, k);
        // Straight into the editor (GCal-style): dismissing it is the cancel.
        onCreateRange(dayRangeDraft(startKey, endKey));
      },
      onCancel: () => highlightDays([]),
    });
  }, [dayKeyAtPoint, highlightDays, renderedDayKeys, onCreateRange]);

  // Plain click (mouse) or tap (touch) on empty cell space: straight into
  // the editor for that day. Real drags never reach here (the drag
  // controller swallows the synthetic click after a lift), and taps that
  // scrolled produce no click.
  const cellClickSelect = useCallback((ev) => {
    if (ev.target !== ev.currentTarget) return;
    if (!onCreateRange) return;
    // A tap in the day's header band (its date, holiday flag and context
    // tokens) that missed them does nothing: it was aimed at one of them.
    // New event from the header is the "+" or, on a phone, a press and hold
    // on the date.
    if (ev.clientY - ev.currentTarget.getBoundingClientRect().top < CELL_HEAD) return;
    onCreateRange(dayRangeDraft(ev.currentTarget.dataset.day, ev.currentTarget.dataset.day));
  }, [onCreateRange]);

  // Hover/focus "+" in a cell corner: same path.
  const quickCreateDay = useCallback((k) => {
    if (onCreateRange) onCreateRange(dayRangeDraft(k, k));
  }, [onCreateRange]);

  // Press and hold a date (phones): New event or Day view for that day. A
  // tap on the date still opens its day view, and a tap in the day's empty
  // space still starts a new event.
  const [dayMenu, setDayMenu] = useState(null);

  // --- render ---------------------------------------------------------------

  const ribbon = columns !== 7;
  const weeks = [];
  const tKey = todayKey();
  // Every lane the row height affords, phones included: a fixed three-lane
  // cap on the phone's month showed "+2" with two lanes of room still empty
  // below. Dots and "+N" take the last lane only when a day truly overflows.
  const capacity = Math.max(1, Math.floor((rowH - CELL_HEAD - 4) / chipRow));
  const sel = null; // selection tint retired with the two-step confirm chip
  for (let wi = range.first; wi <= range.last; wi++) {
    weeks.push(html`<${WeekRow}
      key=${wi} weekIndex=${wi} columns=${columns} ribbon=${ribbon}
      top=${geo.top(wi)} rowH=${geo.height(wi)}
      fit=${geo.fit} capped=${!!(fitHeights && fitHeights.capped.has(wi))}
      byDay=${idx.byDay} bars=${rowEntries(idx, 'bars', wi, columns)} bands=${rowEntries(idx, 'bands', wi, columns)} calendars=${calendars}
      hiddenDays=${hiddenDays} onShowHidden=${onShowHidden} ctxByDay=${dayInfo.ctxByDay} labelsByDay=${dayInfo.labelsByDay}
      capacity=${geo.fit ? Infinity : capacity} chipRow=${chipRow} mobile=${mobile} todayKey=${tKey} dimSet=${dimSet} nowMs=${nowMs}
      sel=${sel}
      onOpenEvent=${onOpenEvent} onExpandDay=${onExpandDay} onOpenDay=${onOpenDay} onDayMenu=${mobile ? setDayMenu : null}
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
    const top = geo.top(weekIndex);
    const bottom = next ? geo.top(next.weekIndex) : geo.top(weekIndex + lookRows);
    return html`<div
      key=${'m' + year + '-' + month}
      class="bc-gutter-month"
      style=${`top:${top}px;height:${bottom - top}px`}
    >
      <div class="bc-gutter-label">${fmtMonthShort(new Date(year, month - 1, 1))}<span class="bc-gutter-year">${month === 1 ? year : ''}</span></div>
    </div>`;
  });

  // Where one month meets the next. Every month has the same background;
  // this line alone marks the change: across the top of the new month's days
  // in the row holding the 1st, down the left of the 1st, and under the old
  // month's days in that row (a straight line when the 1st starts the row).
  // Three pieces, overlapping at the corners so they join; each sits on the
  // cell borders. The line stays out of the gutter: the month label there
  // marks the month, and the line marks exactly where the days change.
  const colX = (c) => `(var(--bc-gutter-w) + (100% - var(--bc-gutter-w)) * ${c} / ${columns})`;
  const rules = monthStarts.flatMap(({ weekIndex, year, month, col }) => {
    const top = geo.top(weekIndex);
    const h = geo.height(weekIndex);
    const key = 'r' + year + '-' + month;
    if (col === 0) {
      return [html`<div key=${key} class="bc-month-rule" style=${`top:${top}px;left:var(--bc-gutter-w);right:0`}></div>`];
    }
    return [
      html`<div key=${key + 't'} class="bc-month-rule" style=${`top:${top}px;left:calc(${colX(col)} - 1px);right:0`}></div>`,
      html`<div key=${key + 'v'} class="bc-month-rule" style=${`top:${top}px;height:${h + 2}px;width:2px;left:calc(${colX(col)} - 1px)`}></div>`,
      html`<div key=${key + 'b'} class="bc-month-rule" style=${`top:${top + h}px;left:var(--bc-gutter-w);width:calc(${colX(col)} - var(--bc-gutter-w) + 1px)`}></div>`,
    ];
  });

  return html`<div
    class="bc-month${ribbon ? ' is-ribbon' : ''}${geo.fit ? ' is-fit' : ''}"
    style=${`--bc-gutter-w:${GUTTER_W}px;--chip-h:${chipRow - 2}px;--bc-cols:${columns}`}
  >
    ${!ribbon && html`<div class="bc-month-head">
      <div class="bc-month-head-gutter">${onToggleFit && !mobile && columns === 7 && !fixedRows && html`<button
        type="button" class=${'bc-fit-toggle' + (geo.fit ? ' is-on' : '')} aria-pressed=${geo.fit}
        aria-label="Rows fit their days"
        title=${geo.fit ? 'Rows fit their days. Click for rows all the same height (Shift+H)' : 'Rows all the same height. Click to fit each row to its days (Shift+H)'}
        onClick=${onToggleFit}
      ><${Icon} name="rowsFit" size=${18} /></button>`}</div>
      ${weekdayNames().map((w) => html`<div key=${w} class="bc-month-head-day">${w}</div>`)}
    </div>`}
    <div class="bc-month-scroll" ref=${scrollRef}>
      <div class="bc-month-spacer" style=${`height:${geo.total()}px`}>
        ${rules}
        ${labels}
        ${weeks}
      </div>
    </div>
    ${dayMenu && html`<${RowMenu}
      head=${fmtDayLong(dateOfDayKey(dayMenu))} color=${null}
      onClose=${() => setDayMenu(null)}
      items=${[
        onCreateRange && ['plus', 'New event', () => quickCreateDay(dayMenu)],
        (onOpenDay || onExpandDay) && ['viewDay', 'Day view', () => (onOpenDay ? onOpenDay(dayMenu) : onExpandDay(dayMenu))],
      ]}
    />`}
  </div>`;
}

// The date in a day's header: a tap opens its day view; on a phone a press
// and hold opens New event and Day view for it.
function DayNum({ k, label, onOpenDay, onExpandDay, onMenu }) {
  const press = useLongPress(() => { if (onMenu) onMenu(k); });
  const hold = onMenu ? press : {};
  return html`<button
    type="button" class="bc-daynum" aria-label=${'Open day view for ' + k}
    title="Open day view"
    ...${hold}
    onPointerDown=${(e) => { e.stopPropagation(); if (hold.onPointerDown) hold.onPointerDown(e); }}
    onClick=${(e) => { e.stopPropagation(); if (onOpenDay) onOpenDay(k); else if (onExpandDay) onExpandDay(k); }}
  >${label}</button>`;
}

function WeekRow({
  weekIndex, columns, ribbon, top, rowH, byDay, bars, bands, calendars, capacity, chipRow, mobile,
  todayKey: tKey, dimSet, nowMs, sel, onOpenEvent, onExpandDay, onOpenDay, onDayMenu, dragMoveOcc, dragResizeOcc, dragCreate,
  cellClickSelect, quickCreateDay, hiddenDays, onShowHidden, ctxByDay, labelsByDay, fit = false, capped = false,
}) {
  const keys = dayKeysOfRow(weekIndex, columns);
  // Week-of-year in the gutter (7-col rows only): taken from the row's
  // midpoint so week-start settings never straddle the ISO boundary oddly.
  const weekNum = !ribbon && keys.length === 7 ? isoWeekOfDate(dateOfDayKey(keys[3])) : null;

  // Trip backdrop bands: stacked under the day-number strip, capped at
  // MAX_BAND_LANES (extras overflow into the day expand via the +N counter).
  // Bars and chips shift down by the band block so bands never eat lanes.
  const bandH = mobile ? BAND_H_MOBILE : BAND_H;
  // Context tokens (weather, sun, tides) beside the day number. On a phone
  // the full month's cells have no room for them: none there, and one in the
  // 3-day view (the first is the weather, all-day items sort first), with no
  // "+N"; the day itself lists them all.
  // On a desktop they fit the cell's width (#108): a wide screen shows them
  // all, not two and "+N".
  const ctxMax = mobile ? (columns >= 7 ? 0 : 1) : Infinity;
  // Rows carrying bands have less chip room; bars get the lanes the rest of
  // the row allows, keeping one for "+N" (never below one lane). A row that
  // fits its contents is tall enough for every bar, so it keeps none back,
  // unless it was capped at the screen's height.
  const laneLimit = (bandOffset) => {
    const cap = Math.min(capacity, Math.max(1, Math.floor((rowH - CELL_HEAD - bandOffset - 4) / chipRow)));
    return fit && !capped ? Infinity : Math.max(1, cap - 1);
  };
  const L = weekLanes(bars, bands, dimSet, columns, laneLimit, chipRow, bandH);
  const { visibleBands, bandExtraByCol, bandOffset, visibleBars, extraByCol, chipTopByCol, shownByFilter, isQuiet } = L;

  const cells = keys.map((k, col) => {
    const [y, m, d] = k.split('-').map(Number);
    const singles = (byDay.get(k) || []).filter(shownByFilter);
    const chipTop = chipTopByCol[col];
    const room = Math.max(0, Math.min(capacity, Math.floor((rowH - chipTop - 4) / chipRow)));
    const plan = planCell(singles, isQuiet, room, extraByCol[col] + bandExtraByCol[col]);
    const hidden = plan.hidden;
    // Ribbon rows have no weekday header, so each cell labels itself
    // ("Mon 3"; month name on month start: "Aug 1"). Weekends get a subtle
    // tint for orientation.
    const ed = epochDayOfKey(k);
    const weekend = ribbon && isWeekendEpochDay(ed);
    const selected = sel && ed >= sel.a && ed <= sel.b;
    const label = d === 1
      ? fmtMonthShort(dateOfDayKey(k)) + ' 1'
      : (ribbon ? fmtWeekdayShort(dateOfDayKey(k)) + ' ' + d : d);
    // A holiday tints the day's header in its calendar's colour.
    const holiday = labelsByDay && labelsByDay.get(k);
    const holidayCal = holiday && holiday.length ? calendars[holiday[0].calendarId] : null;
    return html`<div
      key=${k}
      class="bc-cell${weekend ? ' is-weekend' : ''}${k === tKey ? ' is-today' : ''}${d === 1 ? ' is-month-start' : ''}${selected ? ' bc-drop-target' : ''}${holiday && holiday.length ? ' is-holiday' : ''}"
      style=${holidayCal && holidayCal.color ? '--bc-holiday:' + holidayCal.color : null}
      data-day=${k}
      onPointerDown=${dragCreate}
      onClick=${cellClickSelect}
    >
      <div class="bc-cell-head">
        <${DayNum} k=${k} label=${label} onOpenDay=${onOpenDay} onExpandDay=${onExpandDay} onMenu=${onDayMenu} />
        ${hiddenDays && hiddenDays.get(k) && html`<${HiddenMark} count=${hiddenDays.get(k)} onShow=${onShowHidden} />`}
        ${labelsByDay && html`<${DayLabel} occs=${labelsByDay.get(k)} onOpen=${onOpenEvent} flag=${mobile} />`}
        ${ctxByDay && ctxByDay.get(k) && ctxMax > 0 && html`<${ContextStrip}
          occs=${ctxByDay.get(k)} calendars=${calendars} max=${ctxMax} fit=${mobile ? null : 4} more=${!mobile} zone=${false}
          onOpen=${onOpenEvent} onMore=${() => { if (onExpandDay) onExpandDay(k); }}
        />`}
      </div>
      <button
        type="button" class="bc-cell-headstrip" aria-label=${'Show all events on ' + k + ' in full'}
        title="Show all of this day's events in full"
        onPointerDown=${(e) => e.stopPropagation()}
        onClick=${(e) => { e.stopPropagation(); if (onExpandDay) onExpandDay(k); }}
      ><span class="bc-headstrip-glyph" aria-hidden="true"><${Icon} name="chevronDown" size=${13} /></span></button>
      <button
        type="button" class="bc-cell-add" aria-label=${'New event on ' + k}
        title="New event"
        onPointerDown=${(e) => e.stopPropagation()}
        onClick=${(e) => { e.stopPropagation(); if (quickCreateDay) quickCreateDay(k); }}
      ><${Icon} name="plus" size=${13} /></button>
      <div class="bc-cell-chips" style=${`top:${chipTop}px`}>
        ${plan.shown.map((occ) => html`<${EventChip}
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
          ${mobile && plan.more.slice(0, 3).map((o) => html`<span
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
            onPointerDown=${(e) => dragMoveOcc(occ, e)}
            onEdgePointerDown=${canReschedule(occ, calendars[occ.calendarId]) ? (edge, e) => dragResizeOcc(occ, edge, e) : null}
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
            onEdgePointerDown=${canReschedule(occ, calendars[occ.calendarId]) ? (edge, e) => dragResizeOcc(occ, edge, e) : null}
          />
        </div>`)}
      </div>
    </div>
  </div>`;
}
