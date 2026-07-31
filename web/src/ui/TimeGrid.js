// TimeGrid: day and week views. Hour rows (painted with CSS gradients, not
// DOM), all-day lane at top, now-line, side-by-side overlap layout, drag to
// create/move/resize with 15-minute snap.

import { html, useState, useRef, useMemo, useEffect, useLayoutEffect, useCallback } from '../../vendor/index.js';
import {
  parseISO, toISOWithOffset, dayKeyOf, dayKeyOfISO, occDayKey, dateOfDayKey, todayKey,
  fmtWeekdayShort, fmtTime, epochDayOfKey, keyOfEpochDay,
} from '../lib/dates.js';
import { layoutOverlaps, assignLanes } from './layout.js';
import { occurrenceDaySpan } from './monthmath.js';
import { EventBlock, EventBar } from './EventChip.js';
import { startPointerDrag, cloneAsGhost } from './DragController.js';

const HOUR_H = 48;   // px per hour
const SNAP_MIN = 15;
const MINUTES_DAY = 1440;

function minutesOfDay(d) {
  return d.getHours() * 60 + d.getMinutes();
}

function dateAt(dayKey, minutes) {
  const base = dateOfDayKey(dayKey);
  return new Date(base.getFullYear(), base.getMonth(), base.getDate(), 0, minutes);
}

function snapMin(m) {
  return Math.round(m / SNAP_MIN) * SNAP_MIN;
}

export function TimeGrid({
  days, occurrences, calendars, dimSet,
  onCreateRange, onMoveEvent, onResizeEvent, onOpenEvent,
}) {
  const scrollRef = useRef(null);
  const [, forceTick] = useState(0);
  const [draft, setDraft] = useState(null); // {dayIdx, startMin, endMin} while drag-creating

  // Split occurrences into all-day-lane items and per-day timed items,
  // clamped to the visible day window.
  const { allDayBars, timedByDay } = useMemo(() => {
    const dayIdx = new Map(days.map((k, i) => [k, i]));
    const firstDay = epochDayOfKey(days[0]);
    const lastDay = epochDayOfKey(days[days.length - 1]);
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

  // Initial scroll: when the range includes today, land just above the
  // now-line (Google-style); otherwise fall back to 7am. Ticks the now-line
  // every 30s.
  useLayoutEffect(() => {
    const el = scrollRef.current;
    if (!el) return;
    if (days.includes(todayKey())) {
      const mins = minutesOfDay(new Date());
      el.scrollTop = Math.max(0, (mins / 60) * HOUR_H - 90);
    } else {
      el.scrollTop = 7 * HOUR_H;
    }
  }, []); // eslint-disable-line
  useEffect(() => {
    const t = setInterval(() => forceTick((n) => n + 1), 30000);
    return () => clearInterval(t);
  }, []);

  // --- pointer helpers ------------------------------------------------------

  const pointToSlot = useCallback((pt) => {
    const el = scrollRef.current;
    if (!el) return null;
    const r = el.getBoundingClientRect();
    const gutter = 52;
    const colW = (r.width - gutter) / days.length;
    const dayIdx = Math.max(0, Math.min(days.length - 1, Math.floor((pt.x - r.left - gutter) / colW)));
    const min = Math.max(0, Math.min(MINUTES_DAY, ((pt.y - r.top + el.scrollTop) / HOUR_H) * 60));
    return { dayIdx, min };
  }, [days]);

  const dragCreate = useCallback((ev) => {
    if (ev.target !== ev.currentTarget) return;
    if (ev.pointerType === 'touch') return; // scroll wins on touch
    const origin = pointToSlot({ x: ev.clientX, y: ev.clientY });
    if (!origin) return;
    const startSnap = Math.floor(origin.min / SNAP_MIN) * SNAP_MIN;
    let current = null;
    startPointerDrag(ev, {
      scrollEl: scrollRef.current,
      onLift: () => setDraft({ dayIdx: origin.dayIdx, startMin: startSnap, endMin: startSnap + 30 }),
      onMove: (pt) => {
        const s = pointToSlot(pt);
        if (!s) return;
        const m = snapMin(s.min);
        current = {
          dayIdx: origin.dayIdx,
          startMin: Math.min(startSnap, m),
          endMin: Math.max(startSnap + SNAP_MIN, m),
        };
        setDraft(current);
      },
      onDrop: () => {
        setDraft(null);
        const c = current || { dayIdx: origin.dayIdx, startMin: startSnap, endMin: startSnap + 60 };
        if (onCreateRange) {
          onCreateRange({
            start: toISOWithOffset(dateAt(days[c.dayIdx], c.startMin)),
            end: toISOWithOffset(dateAt(days[c.dayIdx], c.endMin)),
            allDay: false,
          });
        }
      },
      onCancel: () => {
        setDraft(null);
        // Plain click on empty grid: 1-hour draft at the clicked slot.
        if (onCreateRange) {
          onCreateRange({
            start: toISOWithOffset(dateAt(days[origin.dayIdx], startSnap)),
            end: toISOWithOffset(dateAt(days[origin.dayIdx], startSnap + 60)),
            allDay: false,
          });
        }
      },
    });
  }, [pointToSlot, days, onCreateRange]);

  const previewRef = useRef(null); // DOM node moved directly during drags

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
    startPointerDrag(ev, {
      makeGhost: () => cloneAsGhost(src),
      ghostOffset: { x: 10, y: -8 },
      scrollEl: scrollRef.current,
      onLift: () => src.classList.add('is-drag-source'),
      onMove: (pt) => {
        const slot = pointToSlot(pt);
        if (!slot) return;
        const startMin = Math.max(0, Math.min(MINUTES_DAY - durMin, snapMin(slot.min - grabOffset)));
        target = { dayIdx: slot.dayIdx, startMin };
        showPreview(previewRef, scrollRef, days.length, target.dayIdx, startMin, durMin);
      },
      onDrop: () => {
        src.classList.remove('is-drag-source');
        hidePreview(previewRef);
        if (!target || !onMoveEvent) return;
        const ns = dateAt(days[target.dayIdx], target.startMin);
        const ne = new Date(ns.getTime() + durMin * 60000);
        if (ns.getTime() === s0.getTime()) return;
        onMoveEvent({ instanceId: occ.instanceId, newStart: toISOWithOffset(ns), newEnd: toISOWithOffset(ne) });
      },
      onCancel: () => { src.classList.remove('is-drag-source'); hidePreview(previewRef); },
    });
  }, [pointToSlot, days, onMoveEvent]);

  const dragResize = useCallback((occ, edge, ev) => {
    ev.stopPropagation();
    const s0 = parseISO(occ.start);
    const e0 = parseISO(occ.end);
    const dayIdx = days.indexOf(occDayKey(occ));
    if (dayIdx === -1) return;
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
        showPreview(previewRef, scrollRef, days.length, dayIdx, sMin, eMin - sMin);
      },
      onDrop: () => {
        hidePreview(previewRef);
        if (!result || !onResizeEvent) return;
        onResizeEvent({
          instanceId: occ.instanceId,
          newStart: toISOWithOffset(dateAt(days[dayIdx], result.sMin)),
          newEnd: toISOWithOffset(dateAt(days[dayIdx], result.eMin)),
          edge,
        });
      },
      onCancel: () => hidePreview(previewRef),
    });
  }, [pointToSlot, days, onResizeEvent]);

  // --- render ---------------------------------------------------------------

  const tKey = todayKey();
  const now = new Date();
  const nowKey = dayKeyOf(now);
  const hours = [];
  // h=0 renders too (12 AM sits below the top edge instead of being clipped
  // by the all-day lane border).
  for (let h = 0; h < 24; h++) {
    hours.push(html`<div key=${h} class="bc-hour-label${h === 0 ? ' is-first' : ''}" style=${`top:${h * HOUR_H}px`}>${fmtTime(new Date(2000, 0, 1, h, 0)).replace(':00', '')}</div>`);
  }

  // Day view gets a stronger header (weekday + big day number); the shared
  // toolbar date alone is a weak anchor for a single column.
  const single = days.length === 1;

  return html`<div class="bc-timegrid">
    <div class="bc-tg-head${single ? ' is-single' : ''}">
      <div class="bc-tg-gutter"></div>
      ${days.map((k) => {
        const d = dateOfDayKey(k);
        return html`<div key=${k} class="bc-tg-head-day${k === tKey ? ' is-today' : ''}">
          <span class="bc-tg-dow">${fmtWeekdayShort(d)}</span>
          <span class="bc-tg-dom">${d.getDate()}</span>
        </div>`;
      })}
    </div>
    ${(allDayBars.length > 0) && html`<div class="bc-tg-allday" style=${`height:${Math.max(1, barLaneCount) * 24 + 4}px`}>
      <div class="bc-tg-gutter bc-tg-allday-label">all day</div>
      <div class="bc-tg-allday-lane">
        ${allDayBars.map(({ occ, seg }) => html`<div
          key=${occ.instanceId}
          class="bc-bar-slot"
          style=${`left:${(seg.startCol / days.length) * 100}%;width:${((seg.endCol - seg.startCol + 1) / days.length) * 100}%;top:${barLanes.get(occ.instanceId) * 24}px`}
        >
          <${EventBar} occ=${occ} cal=${calendars[occ.calendarId]} seg=${seg}
            dimmed=${dimSet && dimSet.has(occ.instanceId)} onOpen=${onOpenEvent} />
        </div>`)}
      </div>
    </div>`}
    <div class="bc-tg-scroll" ref=${scrollRef}>
      <div class="bc-tg-body" style=${`height:${24 * HOUR_H}px`}>
        <div class="bc-tg-gutter bc-tg-hours">${hours}</div>
        ${days.map((k, i) => html`<div
          key=${k}
          class="bc-tg-col${k === tKey ? ' is-today' : ''}"
          data-day=${k}
          onPointerDown=${dragCreate}
        >
          ${layoutByDay[i].map((item) => {
            const top = (item.startMin / 60) * HOUR_H;
            const height = Math.max(((item.visualEnd - item.startMin) / 60) * HOUR_H - 2, 18);
            const widthPct = 100 / item.cols;
            return html`<${EventBlock}
              key=${item.id}
              occ=${item.occ} cal=${calendars[item.occ.calendarId]}
              rect=${{ top, height, leftPct: item.col * widthPct, widthPct: widthPct * (item.cols > 1 ? 0.96 : 1) }}
              dimmed=${dimSet && dimSet.has(item.occ.instanceId)}
              onOpen=${onOpenEvent}
              onPointerDown=${(e) => dragMove(item.occ, e)}
              onEdgePointerDown=${(edge, e) => dragResize(item.occ, edge, e)}
            />`;
          })}
          ${draft && draft.dayIdx === i && html`<div
            class="bc-tg-draft"
            style=${`top:${(draft.startMin / 60) * HOUR_H}px;height:${((draft.endMin - draft.startMin) / 60) * HOUR_H}px`}
          >${fmtTime(dateAt(k, draft.startMin))} to ${fmtTime(dateAt(k, draft.endMin))}</div>`}
          ${k === nowKey && html`<div class="bc-nowline" style=${`top:${(minutesOfDay(now) / 60) * HOUR_H}px`}><span class="bc-nowline-dot"></span></div>`}
        </div>`)}
        <div class="bc-tg-preview" ref=${previewRef} style="display:none"></div>
      </div>
    </div>
  </div>`;
}

// Drop preview rectangle moved via direct DOM writes (no re-render per move).
function showPreview(previewRef, scrollRef, dayCount, dayIdx, startMin, durMin) {
  const el = previewRef.current;
  const scroll = scrollRef.current;
  if (!el || !scroll) return;
  const gutter = 52;
  const bodyW = scroll.clientWidth - gutter;
  const colW = bodyW / dayCount;
  el.style.display = 'block';
  el.style.left = (gutter + dayIdx * colW) + 'px';
  el.style.width = (colW - 4) + 'px';
  el.style.top = (startMin / 60) * HOUR_H + 'px';
  el.style.height = (durMin / 60) * HOUR_H + 'px';
}

function hidePreview(previewRef) {
  if (previewRef.current) previewRef.current.style.display = 'none';
}
