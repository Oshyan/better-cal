// TimeGrid: day and week views. Hour rows (painted with CSS gradients, not
// DOM), all-day lane at top, now-line, side-by-side overlap layout, drag to
// create/move/resize with 15-minute snap. Creating is one step (GCal-style):
// finishing a drag (or a plain click) opens the editor pre-filled with the
// selection; dismissing the editor is the cancel.
//
// Two column modes:
// - Fixed (day view): the `days` prop lists the columns, flex-sized to fill.
// - Infinite (week view, `infinite` prop): day columns are horizontally
//   virtualized inside a native horizontal scroller spanning ~10 years either
//   side of today. Columns are fixed-width (min 110px desktop, ~44vw on
//   narrow viewports so ~2.3 days show), rendered only for the visible window
//   plus a buffer. One vertical scroller owns time; the header and all-day
//   lane follow the horizontal scroll via the counter-translate pattern.
//   Edge fades + chevrons hint at more days off-screen; drags auto-scroll
//   horizontally near the edges.

import { html, useState, useRef, useMemo, useEffect, useLayoutEffect, useCallback } from '../../vendor/index.js';
import {
  parseISO, toISOWithOffset, dayKeyOf, occDayKey, dateOfDayKey, todayKey,
  fmtWeekdayShort, fmtMonthShort, fmtTime, epochDayOfKey, keyOfEpochDay,
} from '../lib/dates.js';
import { layoutOverlaps, assignLanes } from './layout.js';
import { occurrenceDaySpan, isWeekendEpochDay } from './monthmath.js';
import { EventBlock, EventBar } from './EventChip.js';
import { Icon } from './icons.js';
import { startPointerDrag, cloneAsGhost, externalDropTarget, setDropRowHighlight } from './DragController.js';
import {
  dayRangeDraft, allDayRangeDraft, dragCreateMode, normalizeDayRange,
} from '../lib/quickcreate.js';

const HOUR_H = 48;   // px per hour
const SNAP_MIN = 15;
const MINUTES_DAY = 1440;
const GUTTER = 52;   // px hour gutter
const DAY_SPAN = 3653; // days either side of today (~10 years) in infinite mode
const H_BUFFER = 3;    // extra day columns rendered either side
const EDGE_HINT_PX = 24; // scroll distance before an edge hint counts as "more"
const NARROW_QUERY = '(max-width: 800px)';
// Day view (vstack): consecutive days stacked vertically. The window is a
// fixed-size band that recenters on the day you scroll to, so the DOM stays
// bounded while scrolling feels endless.
const V_BEFORE = 7;
const V_AFTER = 21;
const V_EDGE = 3; // days from a window edge that trigger a recenter
// A sticky header must never eat the viewport: a task-heavy day can carry 30+
// all-day items, so the rest overflow into the day-expand list.
const V_ALLDAY_MAX = 3;

function minutesOfDay(d) {
  return d.getHours() * 60 + d.getMinutes();
}

// Scroll offset that opens a day with "now" a little above centre. A fixed
// 90px lead pinned the current hour near the top edge, so on a tall window
// the middle of the screen showed the small hours of the NEXT day instead of
// the part of today still ahead. Sized from the viewport, the current hour
// lands in the same place whatever the window height.
const NOW_LEAD_FRACTION = 0.42;
function nowWithin(viewportH) {
  const nowPx = (minutesOfDay(new Date()) / 60) * HOUR_H;
  const lead = viewportH > 0 ? viewportH * NOW_LEAD_FRACTION : 90;
  return Math.max(0, nowPx - lead);
}

function dateAt(dayKey, minutes) {
  const base = dateOfDayKey(dayKey);
  return new Date(base.getFullYear(), base.getMonth(), base.getDate(), 0, minutes);
}

function snapMin(m) {
  return Math.round(m / SNAP_MIN) * SNAP_MIN;
}

export function TimeGrid({
  days: fixedDays, occurrences, calendars, dimSet, nowMs,
  infinite = false, vstack = false, scrollKey, scrollSeq = 0,
  onRequestWindow, onVisibleMonthChange, onVisibleDay,
  onCreateRange, onMoveEvent, onResizeEvent, onOpenEvent, onOpenDay, onExpandDay,
  onDropToCalendar, onDropToPerson,
}) {
  const rootRef = useRef(null);
  const scrollRef = useRef(null);  // vertical time scroller
  const hscrollRef = useRef(null); // horizontal day-track scroller (infinite)
  const trackRef = useRef(null);
  const headTrackRef = useRef(null);
  const alldayTrackRef = useRef(null);
  const [draft, setDraft] = useState(null); // {dayKey, startMin, endMin} while drag-creating
  const [alldayOpen, setAlldayOpen] = useState(true); // all-day lane expand/collapse

  // --- vertical day stack (day view) ---------------------------------------

  const [vWin, setVWin] = useState(() => {
    const a = epochDayOfKey(scrollKey || todayKey());
    return { first: a - V_BEFORE, last: a + V_AFTER };
  });
  // Scroll anchor honoured after the next render: {dayKey, offset} keeps the
  // day under the viewport top exactly where it was when the window shifts,
  // measured from the DOM so variable panel heights never drift.
  const vPinRef = useRef(null);
  const vDayRef = useRef(scrollKey || todayKey()); // day currently at the top

  const vPanel = useCallback((dayKey) => {
    const root = scrollRef.current;
    return root ? root.querySelector(`[data-vday="${dayKey}"]`) : null;
  }, []);

  // Scroll so `dayKey` sits at the top of the viewport, `within` px into it.
  const vScrollTo = useCallback((dayKey, within) => {
    const el = scrollRef.current;
    const panel = vPanel(dayKey);
    if (!el || !panel) return false;
    el.scrollTop = panel.offsetTop + within;
    return true;
  }, [vPanel]);

  // --- horizontal virtualization (infinite mode) ---------------------------

  const centerDay = useMemo(() => epochDayOfKey(todayKey()), []);
  const minDay = centerDay - DAY_SPAN;
  const maxDay = centerDay + DAY_SPAN;

  const [viewW, setViewW] = useState(0);
  const [narrow, setNarrow] = useState(() => window.matchMedia(NARROW_QUERY).matches);
  useEffect(() => {
    const mq = window.matchMedia(NARROW_QUERY);
    const onChange = () => setNarrow(mq.matches);
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, []);

  // Fixed column width: narrow viewports show ~2.3 columns; desktop divides
  // the track into 7 with a 110px floor.
  const colW = useMemo(() => {
    if (!infinite) return 0;
    const w = viewW || Math.max(280, window.innerWidth - GUTTER);
    return narrow ? Math.max(120, Math.round(w * 0.44)) : Math.max(110, w / 7);
  }, [infinite, viewW, narrow]);

  const [hRange, setHRange] = useState(() => {
    const a = epochDayOfKey(scrollKey || todayKey());
    return { first: a - H_BUFFER, last: a + 9 };
  });

  // Geometry via a ref so recomputeH keeps one identity for the component's
  // life (late rAF calls must never see stale colW).
  const geomH = useRef({ colW, minDay, maxDay });
  geomH.current = { colW, minDay, maxDay };
  const leftDayRef = useRef(null); // fractional epoch day at the viewport's left edge

  const syncTracks = useCallback(() => {
    const el = hscrollRef.current;
    if (!el) return;
    const t = 'translateX(' + (-el.scrollLeft) + 'px)';
    if (headTrackRef.current) headTrackRef.current.style.transform = t;
    if (alldayTrackRef.current) alldayTrackRef.current.style.transform = t;
  }, []);

  const recomputeH = useCallback(() => {
    const el = hscrollRef.current;
    const g = geomH.current;
    if (!el || !g.colW) return;
    leftDayRef.current = g.minDay + el.scrollLeft / g.colW;
    const first = Math.max(g.minDay, g.minDay + Math.floor(el.scrollLeft / g.colW) - H_BUFFER);
    const last = Math.min(g.maxDay, g.minDay + Math.ceil((el.scrollLeft + el.clientWidth) / g.colW) + H_BUFFER);
    setHRange((prev) => (prev.first === first && prev.last === last ? prev : { first, last }));
    // Edge hints: toggled by scroll position with a threshold, per the
    // Discourse-plugin lessons (classes, gradient fades + chevrons in CSS).
    const root = rootRef.current;
    if (root) {
      const maxLeft = el.scrollWidth - el.clientWidth;
      root.classList.toggle('has-left', el.scrollLeft > EDGE_HINT_PX);
      root.classList.toggle('has-right', el.scrollLeft < maxLeft - EDGE_HINT_PX);
    }
  }, []);

  // Measure the horizontal viewport.
  useLayoutEffect(() => {
    if (!infinite) return undefined;
    const el = hscrollRef.current;
    if (!el) return undefined;
    const ro = new ResizeObserver(() => setViewW(el.clientWidth || 0));
    ro.observe(el);
    setViewW(el.clientWidth || 0);
    return () => ro.disconnect();
  }, [infinite]);

  // Column-width changes (resize, narrow flip) keep the leftmost day stable.
  // Before the first anchor positioning (leftDayRef unset) this stays quiet so
  // the mount never computes a range at the track's far-past origin.
  useLayoutEffect(() => {
    if (!infinite) return;
    const el = hscrollRef.current;
    if (!el || !colW || leftDayRef.current == null) return;
    el.scrollLeft = (leftDayRef.current - minDay) * colW;
    syncTracks();
    recomputeH();
  }, [infinite, colW, minDay, syncTracks, recomputeH]);

  // Anchor contract: explicit navigation (today, chevrons, jump) centers the
  // anchor day in the track — the edge fades advertise more days both ways,
  // so landing mid-viewport reads better than pinning to the left edge.
  useLayoutEffect(() => {
    if (!infinite) return;
    const el = hscrollRef.current;
    const g = geomH.current;
    if (!el || !scrollKey || !g.colW) return;
    const ed = Math.max(minDay, Math.min(maxDay, epochDayOfKey(scrollKey)));
    const centerPad = Math.max(0, (el.clientWidth - g.colW) / 2);
    el.scrollLeft = Math.max(0, (ed - minDay) * g.colW - centerPad);
    leftDayRef.current = g.minDay + el.scrollLeft / g.colW;
    syncTracks();
    recomputeH();
  }, [scrollSeq]); // eslint-disable-line

  // Horizontal scroll: counter-translate header/all-day synchronously, then
  // rAF-throttled range recompute.
  useEffect(() => {
    if (!infinite) return undefined;
    const el = hscrollRef.current;
    if (!el) return undefined;
    let raf = 0;
    const onScroll = () => {
      syncTracks();
      if (raf) return;
      raf = requestAnimationFrame(() => { raf = 0; recomputeH(); });
    };
    el.addEventListener('scroll', onScroll, { passive: true });
    return () => { el.removeEventListener('scroll', onScroll); cancelAnimationFrame(raf); };
  }, [infinite, syncTracks, recomputeH]);

  // The all-day lane can remount as bars enter/leave the window; re-apply the
  // counter-translate after every render.
  useLayoutEffect(() => { if (infinite) syncTracks(); });

  // The header and all-day lane are counter-translated clones, not the real
  // horizontal scroller, so a wheel over them hit nothing. Forward both axes
  // to the scrollers that own them.
  const onHeaderWheel = useCallback((e) => {
    if (!infinite) return;
    const h = hscrollRef.current;
    const v = scrollRef.current;
    const dx = e.deltaX || (e.shiftKey ? e.deltaY : 0);
    if (h && dx) {
      h.scrollLeft += dx;
      e.preventDefault();
    } else if (v && e.deltaY) {
      v.scrollTop += e.deltaY;
      e.preventDefault();
    }
  }, [infinite]);

  // Demand data + report the visible month for the toolbar label.
  useEffect(() => {
    if (!infinite) return;
    if (onVisibleMonthChange && leftDayRef.current != null) {
      const [y, m] = keyOfEpochDay(Math.round(leftDayRef.current)).split('-').map(Number);
      onVisibleMonthChange({ year: y, month: m });
    }
    if (onRequestWindow) {
      onRequestWindow({
        start: toISOWithOffset(dateOfDayKey(keyOfEpochDay(hRange.first - 7))),
        end: toISOWithOffset(dateOfDayKey(keyOfEpochDay(hRange.last + 8))),
      });
    }
  }, [infinite, hRange.first, hRange.last]);

  // --- day list + occurrence indexing --------------------------------------

  const days = useMemo(() => {
    const out = [];
    if (vstack) {
      for (let ed = vWin.first; ed <= vWin.last; ed++) out.push(keyOfEpochDay(ed));
      return out;
    }
    if (!infinite) return fixedDays;
    for (let ed = hRange.first; ed <= hRange.last; ed++) out.push(keyOfEpochDay(ed));
    return out;
  }, [vstack, infinite, fixedDays, hRange.first, hRange.last, vWin.first, vWin.last]);

  // Split occurrences into all-day-lane items and per-day timed items,
  // clamped to the rendered day window.
  const { allDayBars, timedByDay } = useMemo(() => {
    const dayIdx = new Map(days.map((k, i) => [k, i]));
    const firstDay = days.length ? epochDayOfKey(days[0]) : 0;
    const lastDay = days.length ? epochDayOfKey(days[days.length - 1]) : -1;
    const bars = [];
    const timed = days.map(() => []);
    for (const occ of occurrences) {
      if (occ.attendance === 'hidden') continue;
      const { startKey, endKey } = occurrenceDaySpan(occ);
      const s = epochDayOfKey(startKey), e = epochDayOfKey(endKey);
      if (e < firstDay || s > lastDay) continue;
      if (occ.allDay || startKey !== endKey) {
        const startCol = Math.max(0, s - firstDay);
        const endCol = Math.min(days.length - 1, e - firstDay);
        bars.push({ occ, seg: { startCol, endCol, contLeft: s < firstDay, contRight: e > lastDay } });
      } else {
        const i = dayIdx.get(startKey);
        if (i != null) timed[i].push(occ);
      }
    }
    return { allDayBars: bars, timedByDay: timed };
  }, [occurrences, days]);

  // Vertical stack: every day owns its all-day bars, angled where the event
  // continues past that day (same cue as the day-expand list).
  const vAllDay = useMemo(() => {
    if (!vstack) return null;
    return days.map((k) => {
      const ed = epochDayOfKey(k);
      const out = [];
      for (const occ of occurrences) {
        if (occ.attendance === 'hidden') continue;
        const { startKey, endKey } = occurrenceDaySpan(occ);
        if (!occ.allDay && startKey === endKey) continue;
        const s = epochDayOfKey(startKey);
        const e = epochDayOfKey(endKey);
        if (s > ed || e < ed) continue;
        out.push({ occ, seg: { contLeft: s < ed, contRight: e > ed } });
      }
      return out;
    });
  }, [vstack, days, occurrences]);

  const barLanes = useMemo(
    () => assignLanes(allDayBars.map((b) => ({ id: b.occ.instanceId, startCol: b.seg.startCol, endCol: b.seg.endCol }))),
    [allDayBars],
  );
  let barLaneCount = 0;
  for (const l of barLanes.values()) barLaneCount = Math.max(barLaneCount, l + 1);

  // Overlap layout per day column (see layout.js for the algorithm).
  const layoutByDay = useMemo(() => timedByDay.map((list) => {
    const items = list.map((occ) => {
      const s = parseISO(occ.start), e = parseISO(occ.end);
      return {
        id: occ.instanceId,
        occ,
        startMin: Math.max(0, minutesOfDay(s)),
        endMin: Math.min(MINUTES_DAY, minutesOfDay(e) || (dayKeyOf(e) !== dayKeyOf(s) ? MINUTES_DAY : minutesOfDay(e))),
      };
    });
    return layoutOverlaps(items);
  }), [timedByDay]);

  // Initial vertical scroll: when anchored on today, land just above the
  // now-line (Google-style); otherwise fall back to 7am. The now-line (and
  // chip time states) advance via the app-level minute tick: App re-renders
  // on store.nowMinute, so this component sees a fresh `new Date()` each
  // minute (the old private 30s interval is gone). Horizontal navigation
  // never resets the time scroll.
  useLayoutEffect(() => {
    const el = scrollRef.current;
    if (!el || vstack) return; // the stack has its own anchor effect below
    const onToday = infinite ? (scrollKey || todayKey()) === todayKey() : fixedDays.includes(todayKey());
    el.scrollTop = onToday ? nowWithin(el.clientHeight) : 7 * HOUR_H;
  }, []); // eslint-disable-line

  // Stack: explicit navigation (today, chevrons, jump, a day-number click)
  // rebuilds the window around the anchor and scrolls it to the top, opening
  // near "now" on today and 7am elsewhere.
  useLayoutEffect(() => {
    if (!vstack) return;
    const key = scrollKey || todayKey();
    const a = epochDayOfKey(key);
    const within = (key === todayKey()
      ? nowWithin(scrollRef.current ? scrollRef.current.clientHeight : 0)
      : 7 * HOUR_H);
    vDayRef.current = key;
    vPinRef.current = { dayKey: key, within };
    setVWin((prev) => (prev.first === a - V_BEFORE && prev.last === a + V_AFTER
      ? prev : { first: a - V_BEFORE, last: a + V_AFTER }));
  }, [scrollSeq, vstack]); // eslint-disable-line

  // Apply a pending anchor once the window it refers to has rendered.
  //
  // Runs after EVERY render, deliberately. Keyed on the window bounds it was
  // skipped whenever the requested window happened to equal the current one
  // — which is exactly what "Today" does when today is already inside the
  // rendered band: the scroll never happened and the un-applied pin then
  // disabled scroll tracking entirely. The pin is also always cleared, so a
  // panel that cannot be found costs one restore rather than wedging.
  useLayoutEffect(() => {
    if (!vstack) return;
    const pin = vPinRef.current;
    if (!pin) return;
    vScrollTo(pin.dayKey, pin.within);
    vPinRef.current = null;
  });

  // Stack scrolling: report the day at the viewport top (drives the toolbar
  // label) and recenter the window before either edge comes into view.
  //
  // The subscription deliberately depends on `vstack` ALONE and reads the
  // live window and callback through refs. Keying it on the window meant
  // re-subscribing on every recenter, and one missed re-subscribe left the
  // listener bound to a node nothing scrolls any more — the calendar simply
  // stopped tracking, which is what "scrolling stops after a while" was.
  const vWinRef = useRef(vWin);
  vWinRef.current = vWin;
  const onVisibleDayRef = useRef(onVisibleDay);
  onVisibleDayRef.current = onVisibleDay;

  useEffect(() => {
    if (!vstack) return undefined;
    const el = scrollRef.current;
    if (!el) return undefined;
    let raf = 0;
    const check = () => {
      raf = 0;
      if (vPinRef.current) return; // mid-restore; positions are not meaningful yet
      const panels = el.querySelectorAll('[data-vday]');
      const top = el.scrollTop;
      let current = null;
      let within = 0;
      for (const p of panels) {
        if (p.offsetTop <= top + 4) { current = p.dataset.vday; within = top - p.offsetTop; }
        else break;
      }
      if (!current) return;
      if (current !== vDayRef.current) {
        vDayRef.current = current;
        if (onVisibleDayRef.current) onVisibleDayRef.current(current);
      }
      const win = vWinRef.current;
      const ed = epochDayOfKey(current);
      if (ed - win.first < V_EDGE || win.last - ed < V_EDGE) {
        vPinRef.current = { dayKey: current, within };
        setVWin({ first: ed - V_BEFORE, last: ed + V_AFTER });
      }
    };
    const onScroll = () => {
      if (raf) return;
      raf = requestAnimationFrame(check);
    };
    el.addEventListener('scroll', onScroll, { passive: true });
    return () => { el.removeEventListener('scroll', onScroll); cancelAnimationFrame(raf); };
  }, [vstack]);

  // Stack: demand data for the rendered band.
  useEffect(() => {
    if (!vstack || !onRequestWindow) return;
    onRequestWindow({
      start: toISOWithOffset(dateOfDayKey(keyOfEpochDay(vWin.first - 3))),
      end: toISOWithOffset(dateOfDayKey(keyOfEpochDay(vWin.last + 4))),
    });
  }, [vstack, vWin.first, vWin.last]); // eslint-disable-line

  // --- pointer helpers ------------------------------------------------------

  const pointToSlot = useCallback((pt) => {
    const el = scrollRef.current;
    if (!el || days.length === 0) return null;
    // Stack mode: the day is whichever panel's column the pointer is over,
    // read from live rects so variable panel heights need no math.
    if (vstack) {
      const cols = el.querySelectorAll('.bc-tg-col[data-day]');
      let best = null;
      for (const c of cols) {
        const r = c.getBoundingClientRect();
        if (pt.y >= r.top && pt.y <= r.bottom) { best = { c, r }; break; }
        if (!best || Math.abs(r.top - pt.y) < Math.abs(best.r.top - pt.y)) best = { c, r };
      }
      if (!best) return null;
      const min = Math.max(0, Math.min(MINUTES_DAY, ((pt.y - best.r.top) / best.r.height) * MINUTES_DAY));
      return { dayKey: best.c.dataset.day, min };
    }
    const sr = el.getBoundingClientRect();
    const min = Math.max(0, Math.min(MINUTES_DAY, ((pt.y - sr.top + el.scrollTop) / HOUR_H) * 60));
    if (infinite) {
      const track = trackRef.current;
      const g = geomH.current;
      if (!track || !g.colW) return null;
      const tr = track.getBoundingClientRect();
      const ed = Math.max(g.minDay, Math.min(g.maxDay, g.minDay + Math.floor((pt.x - tr.left) / g.colW)));
      return { dayKey: keyOfEpochDay(ed), min };
    }
    const colWpx = (sr.width - GUTTER) / days.length;
    const dayIdx = Math.max(0, Math.min(days.length - 1, Math.floor((pt.x - sr.left - GUTTER) / colWpx)));
    return { dayKey: days[dayIdx], min };
  }, [infinite, vstack, days]);

  const previewRef = useRef(null); // DOM node moved directly during drags

  const previewGeom = useCallback(() => ({
    infinite,
    vstack,
    minDay: geomH.current.minDay,
    colW: geomH.current.colW,
    days,
    scrollEl: scrollRef.current,
  }), [infinite, vstack, days]);

  // Drag-create draws a draft block; releasing keeps the draft and asks via
  // the confirm chip. A plain click (never lifted) drafts a 1-hour block at
  // the clicked slot with the same chip; Escape mid-drag cancels outright.
  // A drag that crosses day columns switches the draft to an inclusive day
  // range (spanned columns tinted full-height); confirming that chip creates
  // an all-day multi-day event. Dragging back into the origin column reverts
  // to the timed draft.
  const dragCreate = useCallback((ev) => {
    if (ev.target !== ev.currentTarget) return;
    if (ev.pointerType === 'touch') return; // scroll wins on touch
    const origin = pointToSlot({ x: ev.clientX, y: ev.clientY });
    if (!origin) return;
    const startSnap = Math.floor(origin.min / SNAP_MIN) * SNAP_MIN;
    let current = null;
    let lifted = false;
    startPointerDrag(ev, {
      scrollEl: scrollRef.current,
      hScrollEl: infinite ? hscrollRef.current : null,
      onLift: () => {
        lifted = true;
        setDraft({ dayKey: origin.dayKey, startMin: startSnap, endMin: startSnap + 30 });
      },
      onMove: (pt) => {
        const s = pointToSlot(pt);
        if (!s) return;
        const dm = dragCreateMode(origin.dayKey, s.dayKey);
        if (dm.mode === 'days') {
          current = dm;
        } else {
          const m = snapMin(s.min);
          current = {
            dayKey: origin.dayKey,
            startMin: Math.min(startSnap, m),
            endMin: Math.max(startSnap + SNAP_MIN, m),
          };
        }
        setDraft(current);
      },
      onDrop: () => {
        commitSelection(current || { dayKey: origin.dayKey, startMin: startSnap, endMin: startSnap + 60 });
      },
      onCancel: () => {
        if (lifted) { setDraft(null); return; } // Escape mid-drag: no create
        commitSelection({ dayKey: origin.dayKey, startMin: startSnap, endMin: startSnap + 60 });
      },
    });
  }, [pointToSlot, infinite]);

  // All-day lane create: click or drag selects an inclusive day range with
  // the same confirm chip. Lane selections always draft all-day events, even
  // for a single day (the lane is the all-day surface).
  const dragCreateAllDay = useCallback((ev) => {
    if (ev.target !== ev.currentTarget) return; // only empty lane space
    if (ev.pointerType === 'touch') return; // scroll wins on touch
    const origin = pointToSlot({ x: ev.clientX, y: ev.clientY });
    if (!origin) return;
    const single = { mode: 'days', startKey: origin.dayKey, endKey: origin.dayKey, allDayLane: true };
    let current = null;
    let lifted = false;
    startPointerDrag(ev, {
      hScrollEl: infinite ? hscrollRef.current : null,
      onLift: () => {
        lifted = true;
        setDraft(single);
      },
      onMove: (pt) => {
        const s = pointToSlot(pt);
        if (!s) return;
        const { startKey, endKey } = normalizeDayRange(origin.dayKey, s.dayKey);
        current = { mode: 'days', startKey, endKey, allDayLane: true };
        setDraft(current);
      },
      onDrop: () => {
        commitSelection(current || single);
      },
      onCancel: () => {
        if (lifted) { setDraft(null); return; } // Escape mid-drag: no create
        commitSelection(single);
      },
    });
  }, [pointToSlot, infinite]);

  // One-step create (GCal-style): the finished selection opens the editor
  // pre-filled; dismissing the editor is the cancel.
  const commitSelection = useCallback((p) => {
    setDraft(null);
    if (!p || !onCreateRange) return;
    if (p.mode === 'days') {
      // Lane selections are all-day at any length; column-crossing drags
      // reuse the shared day-range draft (all-day for 2+ days).
      onCreateRange(p.allDayLane
        ? allDayRangeDraft(p.startKey, p.endKey)
        : dayRangeDraft(p.startKey, p.endKey));
    } else {
      onCreateRange({
        start: toISOWithOffset(dateAt(p.dayKey, p.startMin)),
        end: toISOWithOffset(dateAt(p.dayKey, p.endMin)),
        allDay: false,
      });
    }
  }, [onCreateRange]);

  // Navigation clears a mid-drag draft block.
  useEffect(() => { setDraft(null); }, [scrollSeq]);

  const dragMove = useCallback((occ, ev) => {
    ev.stopPropagation();
    const src = ev.currentTarget.closest('.bc-block') || ev.currentTarget;
    const s0 = parseISO(occ.start);
    const e0 = parseISO(occ.end);
    const durMin = Math.max(SNAP_MIN, (e0 - s0) / 60000);
    const grab = pointToSlot({ x: ev.clientX, y: ev.clientY });
    if (!grab) return;
    const grabOffset = grab.min - minutesOfDay(s0);
    let target = null;
    let ext = null;
    // Sidebar rows accept drops here too (recategorize / link a person);
    // feed events are read-only content-wise, so neither applies to them.
    const draggedCal = calendars[occ.calendarId];
    const isFeed = !!draggedCal && draggedCal.kind === 'subscribed';
    const extKinds = !isFeed ? { cal: (id) => id !== occ.calendarId, person: true } : null;
    startPointerDrag(ev, {
      makeGhost: () => cloneAsGhost(src),
      ghostOffset: { x: 10, y: -8 },
      scrollEl: scrollRef.current,
      hScrollEl: infinite ? hscrollRef.current : null,
      onLift: () => src.classList.add('is-drag-source'),
      onMove: (pt) => {
        if (extKinds) {
          ext = externalDropTarget(pt, extKinds);
          if (ext) {
            setDropRowHighlight(ext.el);
            hidePreview(previewRef);
            target = null;
            return;
          }
          setDropRowHighlight(null);
        }
        const slot = pointToSlot(pt);
        if (!slot) return;
        const startMin = Math.max(0, Math.min(MINUTES_DAY - durMin, snapMin(slot.min - grabOffset)));
        target = { dayKey: slot.dayKey, startMin };
        showPreview(previewRef, previewGeom(), target.dayKey, startMin, durMin);
      },
      onDrop: () => {
        src.classList.remove('is-drag-source');
        hidePreview(previewRef);
        setDropRowHighlight(null);
        if (ext) {
          if (ext.kind === 'cal' && onDropToCalendar) onDropToCalendar(occ, ext.id);
          else if (ext.kind === 'person' && onDropToPerson) onDropToPerson(occ, ext.name);
          return;
        }
        if (!target || !onMoveEvent) return;
        const ns = dateAt(target.dayKey, target.startMin);
        const ne = new Date(ns.getTime() + durMin * 60000);
        if (ns.getTime() === s0.getTime()) return;
        onMoveEvent({ instanceId: occ.instanceId, newStart: toISOWithOffset(ns), newEnd: toISOWithOffset(ne) });
      },
      onCancel: () => { src.classList.remove('is-drag-source'); hidePreview(previewRef); setDropRowHighlight(null); },
    });
  }, [pointToSlot, infinite, previewGeom, onMoveEvent, onDropToCalendar, onDropToPerson, calendars]);

  const dragResize = useCallback((occ, edge, ev) => {
    ev.stopPropagation();
    const s0 = parseISO(occ.start);
    const e0 = parseISO(occ.end);
    const dayKey = occDayKey(occ);
    if (!days.includes(dayKey)) return;
    let result = null;
    startPointerDrag(ev, {
      scrollEl: scrollRef.current,
      onMove: (pt) => {
        const slot = pointToSlot(pt);
        if (!slot) return;
        const m = snapMin(slot.min);
        let sMin = minutesOfDay(s0);
        let eMin = minutesOfDay(e0) || MINUTES_DAY;
        if (edge === 'start') sMin = Math.min(m, eMin - SNAP_MIN);
        else eMin = Math.max(m, sMin + SNAP_MIN);
        result = { sMin, eMin };
        showPreview(previewRef, previewGeom(), dayKey, sMin, eMin - sMin);
      },
      onDrop: () => {
        hidePreview(previewRef);
        if (!result || !onResizeEvent) return;
        onResizeEvent({
          instanceId: occ.instanceId,
          newStart: toISOWithOffset(dateAt(dayKey, result.sMin)),
          newEnd: toISOWithOffset(dateAt(dayKey, result.eMin)),
          edge,
        });
      },
      onCancel: () => hidePreview(previewRef),
    });
  }, [pointToSlot, days, previewGeom, onResizeEvent]);

  // --- render ---------------------------------------------------------------

  const tKey = todayKey();
  const now = new Date();
  const nowKey = dayKeyOf(now);
  // Built per call, never hoisted: preact mutates vnodes, so one shared array
  // rendered in several panels at once would corrupt the tree.
  // h=0 renders too (12 AM sits below the top edge instead of being clipped
  // by the all-day lane border).
  const buildHours = () => {
    const out = [];
    for (let h = 0; h < 24; h++) {
      out.push(html`<div key=${h} class="bc-hour-label${h === 0 ? ' is-first' : ''}" style=${`top:${h * HOUR_H}px`}>${fmtTime(new Date(2000, 0, 1, h, 0)).replace(':00', '')}</div>`);
    }
    return out;
  };
  const hours = buildHours();

  // Day view gets a stronger header (weekday + big day number); the shared
  // toolbar date alone is a weak anchor for a single column.
  const single = !infinite && days.length === 1;
  const totalW = (maxDay - minDay + 1) * colW;
  const dayLeft = (k) => (epochDayOfKey(k) - minDay) * colW;

  // Infinite week track: month boundaries and weekend tints mirror the
  // month/ribbon views (2px accent rule on each month's first day column,
  // subtle weekend background + muted-accent weekday label).
  const headCells = days.map((k) => {
    const d = dateOfDayKey(k);
    const weekend = infinite && isWeekendEpochDay(epochDayOfKey(k));
    const monthStart = infinite && d.getDate() === 1;
    return html`<button
      type="button"
      key=${k}
      class="bc-tg-head-day${k === tKey ? ' is-today' : ''}${weekend ? ' is-weekend' : ''}${monthStart ? ' is-month-start' : ''}"
      style=${infinite ? `left:${dayLeft(k)}px;width:${colW}px` : undefined}
      title="Open day view"
      aria-label=${'Open day view for ' + k}
      onClick=${() => { if (onOpenDay) onOpenDay(k); }}
    >
      <span class="bc-tg-dow">${fmtWeekdayShort(d)}</span>
      <span class="bc-tg-dom">${d.getDate()}</span>
      ${infinite && d.getDate() === 1 && html`<span class="bc-tg-month-tag">${fmtMonthShort(d)}</span>`}
    </button>`;
  });

  const barSlot = ({ occ, seg }) => html`<div
    key=${occ.instanceId}
    class="bc-bar-slot"
    style=${infinite
      ? `left:${(epochDayOfKey(days[0]) - minDay + seg.startCol) * colW}px;width:${(seg.endCol - seg.startCol + 1) * colW}px;top:${barLanes.get(occ.instanceId) * 24}px`
      : `left:${(seg.startCol / days.length) * 100}%;width:${((seg.endCol - seg.startCol + 1) / days.length) * 100}%;top:${barLanes.get(occ.instanceId) * 24}px`}
  >
    <${EventBar} occ=${occ} cal=${calendars[occ.calendarId]} seg=${seg}
      dimmed=${dimSet && dimSet.has(occ.instanceId)} nowMs=${nowMs} onOpen=${onOpenEvent} />
  </div>`;

  // Day-range draft (multi-day drag-create or all-day lane selection): the
  // spanned columns carry a full-height tint, clamped to the rendered window.
  const rangeSel = draft && draft.mode === 'days'
    ? { a: epochDayOfKey(draft.startKey), b: epochDayOfKey(draft.endKey) }
    : null;

  const dayCols = days.map((k, i) => {
    const ed = epochDayOfKey(k);
    const inRange = rangeSel && ed >= rangeSel.a && ed <= rangeSel.b;
    return html`<div
    key=${k}
    class="bc-tg-col${k === tKey ? ' is-today' : ''}${infinite && isWeekendEpochDay(ed) ? ' is-weekend' : ''}${infinite && k.endsWith('-01') ? ' is-month-start' : ''}${inRange ? ' is-range-draft' : ''}"
    data-day=${k}
    style=${infinite ? `left:${dayLeft(k)}px;width:${colW}px` : undefined}
    onPointerDown=${dragCreate}
  >
    ${layoutByDay[i].map((item) => {
      const top = (item.startMin / 60) * HOUR_H;
      const height = Math.max(((item.visualEnd - item.startMin) / 60) * HOUR_H - 2, 18);
      // GCal-style cascade: overlapping events keep generous widths and
      // overlap each other (later columns stack above, offset right) instead
      // of shrinking into equal slivers.
      const colPct = 100 / item.cols;
      const leftPct = item.col * colPct;
      const widthPct = item.cols > 1 ? Math.min(100 - leftPct, colPct * 1.7) : 100;
      return html`<${EventBlock}
        key=${item.id}
        occ=${item.occ} cal=${calendars[item.occ.calendarId]}
        rect=${{ top, height, leftPct, widthPct, z: item.col + 1 }}
        dimmed=${dimSet && dimSet.has(item.occ.instanceId)} nowMs=${nowMs}
        onOpen=${onOpenEvent}
        onPointerDown=${(e) => dragMove(item.occ, e)}
        onEdgePointerDown=${(edge, e) => dragResize(item.occ, edge, e)}
      />`;
    })}
    ${draft && draft.dayKey === k && html`<div
      class="bc-tg-draft"
      style=${`top:${(draft.startMin / 60) * HOUR_H}px;height:${((draft.endMin - draft.startMin) / 60) * HOUR_H}px`}
    >${fmtTime(dateAt(k, draft.startMin))} to ${fmtTime(dateAt(k, draft.endMin))}</div>`}
    ${k === nowKey && html`<div class="bc-nowline" style=${`top:${(minutesOfDay(now) / 60) * HOUR_H}px`}><span class="bc-nowline-dot"></span></div>`}
  </div>`;
  });

  const preview = html`<div class="bc-tg-preview" ref=${previewRef} style="display:none"></div>`;

  // --- vertical day stack ---------------------------------------------------
  // Day view scrolls straight into the next day: consecutive day panels with
  // a gap and a sticky dated header between them, each carrying its own
  // all-day bars and hour gutter.
  if (vstack) {
    return html`<div class="bc-timegrid bc-tg-vstacked" ref=${rootRef}>
      <div class="bc-tg-scroll" ref=${scrollRef}>
        <div class="bc-tg-vdays">
          ${days.map((k, i) => {
            const d = dateOfDayKey(k);
            const bars = (vAllDay && vAllDay[i]) || [];
            return html`<section key=${k} class="bc-tg-vday" data-vday=${k}>
              <header class="bc-tg-vday-head${k === tKey ? ' is-today' : ''}">
                <button
                  type="button" class="bc-tg-vday-date"
                  title="Scroll this day to the top"
                  onClick=${() => { vDayRef.current = k; vScrollTo(k, 0); if (onVisibleDay) onVisibleDay(k); }}
                >
                  <span class="bc-tg-dow">${fmtWeekdayShort(d)}</span>
                  <span class="bc-tg-dom">${d.getDate()}</span>
                  <span class="bc-tg-vday-month">${fmtMonthShort(d)}</span>
                </button>
                <div
                  class="bc-tg-vday-allday"
                  onClick=${(ev) => {
                    if (ev.target !== ev.currentTarget || !onCreateRange) return;
                    onCreateRange(allDayRangeDraft(k, k));
                  }}
                >
                  ${bars.slice(0, V_ALLDAY_MAX).map(({ occ, seg }) => html`<${EventBar}
                    key=${occ.instanceId} occ=${occ} cal=${calendars[occ.calendarId]} seg=${seg}
                    dimmed=${dimSet && dimSet.has(occ.instanceId)} nowMs=${nowMs} onOpen=${onOpenEvent}
                  />`)}
                  ${bars.length > V_ALLDAY_MAX && html`<button
                    type="button" class="bc-vday-more"
                    title="List everything on this day"
                    onClick=${() => onExpandDay && onExpandDay(k)}
                  >+${bars.length - V_ALLDAY_MAX} more</button>`}
                </div>
              </header>
              <div class="bc-tg-vday-body">
                <div class="bc-tg-gutter bc-tg-hours">${buildHours()}</div>
                ${dayCols[i]}
              </div>
            </section>`;
          })}
          ${preview}
        </div>
      </div>
    </div>`;
  }

  // The all-day lane is always present in infinite mode so bars entering the
  // window while scrolling never shift the grid vertically.
  const showAllday = infinite || allDayBars.length > 0;

  // Month boundary markers for the all-day lane (rendered before the bars so
  // bars paint above); header and time columns get theirs via is-month-start.
  const alldayMonthLines = infinite
    ? days.filter((k) => k.endsWith('-01')).map((k) => html`<div
        key=${'ml' + k} class="bc-tg-month-line" style=${`left:${dayLeft(k)}px`}
      ></div>`)
    : null;

  return html`<div class="bc-timegrid${infinite ? ' bc-tg-infinite' : ''}" ref=${rootRef}>
    <div class="bc-tg-head${single ? ' is-single' : ''}" onWheel=${onHeaderWheel}>
      <div class="bc-tg-gutter"></div>
      ${infinite
        ? html`<div class="bc-tg-hclip"><div class="bc-tg-htrack" ref=${headTrackRef} style=${`width:${totalW}px`}>${headCells}</div></div>`
        : headCells}
    </div>
    ${showAllday && html`<div class="bc-tg-allday${alldayOpen ? '' : ' is-collapsed'}" onWheel=${onHeaderWheel} style=${`height:${(alldayOpen ? Math.max(1, barLaneCount) : 1) * 24 + (barLaneCount > 1 ? 20 : 6)}px`}>
      <div class="bc-tg-gutter bc-tg-allday-label">
        all day
        ${barLaneCount > 1 && html`<button
          type="button" class="bc-allday-toggle"
          title=${alldayOpen ? 'Collapse all-day events' : 'Show all all-day events'}
          aria-expanded=${alldayOpen}
          onClick=${() => setAlldayOpen(!alldayOpen)}
        >${alldayOpen
          ? html`<${Icon} name="chevronUp" size=${11} />`
          : html`<${Icon} name="chevronDown" size=${11} />${barLaneCount - 1}+`}</button>`}
      </div>
      ${infinite
        ? html`<div class="bc-tg-hclip"><div class="bc-tg-htrack" ref=${alldayTrackRef} style=${`width:${totalW}px`} onPointerDown=${dragCreateAllDay}>${alldayMonthLines}${allDayBars.map(barSlot)}</div></div>`
        : html`<div class="bc-tg-allday-lane" onPointerDown=${dragCreateAllDay}>${allDayBars.map(barSlot)}</div>`}
    </div>`}
    <div class="bc-tg-scroll" ref=${scrollRef}>
      <div class="bc-tg-body" style=${`height:${24 * HOUR_H}px`}>
        <div class="bc-tg-gutter bc-tg-hours">${hours}</div>
        ${infinite
          ? html`<div class="bc-tg-hscroll" ref=${hscrollRef}>
              <div class="bc-tg-track" ref=${trackRef} style=${`width:${totalW}px;height:${24 * HOUR_H}px`}>
                ${dayCols}
                ${preview}
              </div>
            </div>`
          : html`${dayCols}${preview}`}
      </div>
    </div>
    ${infinite && html`<div class="bc-tg-edge l" aria-hidden="true"><span><${Icon} name="chevronLeft" size=${16} /></span></div>`}
    ${infinite && html`<div class="bc-tg-edge r" aria-hidden="true"><span><${Icon} name="chevronRight" size=${16} /></span></div>`}
  </div>`;
}

// Drop preview rectangle moved via direct DOM writes (no re-render per move).
// In infinite mode the preview lives inside the track (px day columns); in
// fixed mode it lives in the body (gutter + flex columns).
function showPreview(previewRef, geom, dayKey, startMin, durMin) {
  const el = previewRef.current;
  if (!el) return;
  let left, width;
  if (geom.vstack) {
    // The preview is a child of the stack container, so the column's offsets
    // must be summed up the offsetParent chain to reach it: the day panel and
    // its body are both positioned, so a bare offsetTop is day-relative and
    // would pin every preview to the top of the stack.
    const scroll = geom.scrollEl;
    const col = scroll && scroll.querySelector(`.bc-tg-col[data-day="${dayKey}"]`);
    const stack = scroll && scroll.querySelector('.bc-tg-vdays');
    if (!col || !stack) return;
    let top = 0;
    let left = 0;
    for (let node = col; node && node !== stack; node = node.offsetParent) {
      top += node.offsetTop;
      left += node.offsetLeft;
    }
    el.style.display = 'block';
    el.style.left = left + 1 + 'px';
    el.style.width = col.offsetWidth - 4 + 'px';
    el.style.top = top + (startMin / 60) * HOUR_H + 'px';
    el.style.height = (durMin / 60) * HOUR_H + 'px';
    return;
  }
  if (geom.infinite) {
    left = (epochDayOfKey(dayKey) - geom.minDay) * geom.colW + 1;
    width = geom.colW - 4;
  } else {
    const scroll = geom.scrollEl;
    if (!scroll) return;
    const idx = Math.max(0, geom.days.indexOf(dayKey));
    const colW = (scroll.clientWidth - GUTTER) / geom.days.length;
    left = GUTTER + idx * colW;
    width = colW - 4;
  }
  el.style.display = 'block';
  el.style.left = left + 'px';
  el.style.width = width + 'px';
  el.style.top = (startMin / 60) * HOUR_H + 'px';
  el.style.height = (durMin / 60) * HOUR_H + 'px';
}

function hidePreview(previewRef) {
  if (previewRef.current) previewRef.current.style.display = 'none';
}
