// RescheduleMode: the dedicated move-an-event mode (PRD 5.9).
// Three pure ui components driven by props from the app layer:
//   RescheduleBanner  - status strip under the toolbar (role=status)
//   RescheduleStrip   - film-strip month navigator docked right of the grid
//   RescheduleOverlay - pointer-following ghost, drop capture over the grid,
//                       and the two-step confirm chip
// No store access here: data in, intents out (onExit, onJumpMonth,
// onDropConfirmed), matching the rest of ui/.
//
// Interaction model: entering the mode lifts the event into a ghost that
// follows the pointer with no button held (pointer moves the ghost, click
// drops). Wheel scrolling stays native; holding the pointer near the grid's
// top/bottom edges auto-scrolls via the shared DragController edge logic.
// A drop stages a confirm chip at the drop point (two-step confirm, per the
// reference plugin lesson); Esc cancels the chip first, then the mode.

import { html, useState, useRef, useMemo, useEffect, useLayoutEffect } from '../../vendor/index.js';
import {
  epochDayOfKey, keyOfEpochDay, dateOfDayKey, parseISO,
  fmtMonthShort, fmtDayMedium, fmtTime,
} from '../lib/dates.js';
import {
  occurrenceDaySpan, monthsAround, miniMonthGrid, dayDropDates, timeDropDates,
} from './monthmath.js';
import { edgeScrollDy } from './DragController.js';

const DWELL_MS = 300;    // hover pause before a mini month navigates the grid
const STRIP_RADIUS = 12; // months either side of the entry month
const CHIP_W = 280;      // confirm chip clamp estimate

// Selectors for reschedule UI that keeps its native click behavior while the
// grid itself is captured for drops.
const UI_SELECTOR = '.bc-resched-banner, .bc-strip, .bc-confirm-chip';

// --- banner -----------------------------------------------------------------

export function RescheduleBanner({ occ, onExit }) {
  return html`<div class="bc-resched-banner" role="status">
    <span class="bc-resched-label">Rescheduling: <strong>${occ.title || '(untitled)'}</strong></span>
    ${occ.recurring && html`<span class="bc-resched-scope">This occurrence only</span>`}
    <span class="bc-resched-hint">Drop the event on a new day, or use the month strip on the right</span>
    <button type="button" class="bc-btn bc-resched-cancel" onClick=${onExit}>
      Cancel<kbd>Esc</kbd>
    </button>
  </div>`;
}

// --- film-strip month navigator ---------------------------------------------

// props: year/month = strip center (frozen at mode entry),
// currentYear/currentMonth = the month currently visible in the main grid,
// onJumpMonth(firstDayKey).
export function RescheduleStrip({ year, month, currentYear, currentMonth, onJumpMonth }) {
  const months = useMemo(
    () => monthsAround(year, month, STRIP_RADIUS).map((m) => miniMonthGrid(m.year, m.month)),
    [year, month],
  );
  const listRef = useRef(null);
  const dwellTimer = useRef(0);
  const hoverY = useRef(null);

  // Center the entry month on mount.
  useLayoutEffect(() => {
    const el = listRef.current;
    if (!el) return;
    const base = el.querySelector('.is-base');
    if (base) el.scrollTop = base.offsetTop - el.clientHeight / 2 + base.offsetHeight / 2;
  }, [year, month]);

  // Hovering near the strip's own top/bottom auto-scrolls it (same edge
  // curve as grid drags).
  useEffect(() => {
    const el = listRef.current;
    if (!el) return;
    let raf = 0;
    const loop = () => {
      if (hoverY.current != null) {
        const dy = edgeScrollDy(el, hoverY.current);
        if (dy) el.scrollTop += dy;
      }
      raf = requestAnimationFrame(loop);
    };
    raf = requestAnimationFrame(loop);
    const onMove = (e) => { hoverY.current = e.clientY; };
    const onLeave = () => { hoverY.current = null; };
    el.addEventListener('pointermove', onMove);
    el.addEventListener('pointerleave', onLeave);
    return () => {
      cancelAnimationFrame(raf);
      el.removeEventListener('pointermove', onMove);
      el.removeEventListener('pointerleave', onLeave);
    };
  }, []);

  useEffect(() => () => clearTimeout(dwellTimer.current), []);

  const dwellEnter = (firstKey) => {
    clearTimeout(dwellTimer.current);
    dwellTimer.current = setTimeout(() => onJumpMonth(firstKey), DWELL_MS);
  };
  const dwellLeave = () => clearTimeout(dwellTimer.current);

  // Arrow keys walk the mini months; Enter/Space activate (native button).
  const onKeyDown = (e) => {
    if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
    e.preventDefault();
    e.stopPropagation();
    const btns = [...listRef.current.querySelectorAll('.bc-strip-month')];
    const i = btns.indexOf(document.activeElement);
    const next = btns[Math.max(0, Math.min(btns.length - 1, i === -1 ? 0 : i + (e.key === 'ArrowDown' ? 1 : -1)))];
    if (next) {
      next.focus();
      next.scrollIntoView({ block: 'nearest' });
    }
  };

  return html`<div
    class="bc-strip" ref=${listRef}
    role="group" aria-label="Reschedule month navigator"
    onKeyDown=${onKeyDown}
  >
    ${months.map((m) => {
      const isBase = m.year === year && m.month === month;
      const isCurrent = m.year === currentYear && m.month === currentMonth;
      return html`<button
        key=${m.firstKey} type="button"
        class="bc-strip-month${isBase ? ' is-base' : ''}${isCurrent ? ' is-current' : ''}"
        aria-current=${isCurrent ? 'true' : 'false'}
        onClick=${() => { clearTimeout(dwellTimer.current); onJumpMonth(m.firstKey); }}
        onPointerEnter=${() => dwellEnter(m.firstKey)}
        onPointerLeave=${dwellLeave}
      >
        <span class="bc-strip-name">${fmtMonthShort(dateOfDayKey(m.firstKey))}<span class="bc-strip-year">${m.year}</span></span>
        <span class="bc-strip-grid" aria-hidden="true">
          ${Array.from({ length: m.weekCount }, (_, i) => html`<span key=${i} class="bc-strip-wk"></span>`)}
        </span>
      </button>`;
    })}
  </div>`;
}

// --- overlay: ghost, drop capture, confirm chip --------------------------------

// props: occ (target occurrence), cal ({color,name,visible}),
// onDropConfirmed({instanceId,newStart,newEnd,targetKey}), onExit().
export function RescheduleOverlay({ occ, cal, onDropConfirmed, onExit }) {
  const [pending, setPending] = useState(null); // {instanceId,newStart,newEnd,targetKey,label,x,y}
  const ghostRef = useRef(null);
  const occRef = useRef(occ);
  occRef.current = occ;
  const pendingRef = useRef(null);
  pendingRef.current = pending;
  const exitRef = useRef(onExit);
  exitRef.current = onExit;

  // A staged drop belongs to the occurrence it was staged for.
  useEffect(() => { setPending(null); }, [occ.instanceId]);

  useEffect(() => {
    const pt = { x: null, y: null };
    const highlighted = [];
    let raf = 0;
    let scrollRaf = 0;

    const clearHighlight = () => {
      for (const el of highlighted) el.classList.remove('bc-drop-target');
      highlighted.length = 0;
    };

    // Resolve what is under the pointer: reschedule UI, a month day cell, or
    // a time-grid column (with the minute at that y).
    const targetInfo = (x, y) => {
      const el = document.elementFromPoint(x, y);
      if (!el) return null;
      if (el.closest(UI_SELECTOR)) return { ui: true };
      const dayEl = el.closest('[data-day]');
      if (!dayEl || !el.closest('.bc-view')) return null;
      const key = dayEl.dataset.day;
      if (dayEl.classList.contains('bc-tg-col')) {
        const r = dayEl.getBoundingClientRect();
        return { key, dayEl, minute: ((y - r.top) / r.height) * 1440 };
      }
      return { key, dayEl };
    };

    const applyHighlight = (info) => {
      clearHighlight();
      if (!info || info.ui || !info.key) return;
      if (info.minute != null) {
        info.dayEl.classList.add('bc-drop-target');
        highlighted.push(info.dayEl);
        return;
      }
      // Month cells: highlight the whole day span the event would cover.
      const { startKey, endKey } = occurrenceDaySpan(occRef.current);
      const spanDays = epochDayOfKey(endKey) - epochDayOfKey(startKey);
      const scope = info.dayEl.closest('.bc-month-scroll') || document;
      const startEd = epochDayOfKey(info.key);
      for (let i = 0; i <= spanDays; i++) {
        const cell = scope.querySelector(`[data-day="${keyOfEpochDay(startEd + i)}"]`);
        if (cell) {
          cell.classList.add('bc-drop-target');
          highlighted.push(cell);
        }
      }
    };

    const tick = () => {
      if (pt.x == null) return;
      const g = ghostRef.current;
      if (g && !pendingRef.current) {
        g.style.display = 'flex';
        g.style.transform = 'translate(' + (pt.x + 10) + 'px,' + (pt.y + 12) + 'px)';
      }
      if (pendingRef.current) {
        clearHighlight();
        return;
      }
      applyHighlight(targetInfo(pt.x, pt.y));
    };

    const onMove = (e) => {
      pt.x = e.clientX;
      pt.y = e.clientY;
      if (raf) return;
      raf = requestAnimationFrame(() => { raf = 0; tick(); });
    };

    // Continuous edge auto-scroll of the active grid scroller (targets shift
    // under a stationary pointer, so re-tick after each scroll step).
    const scrollLoop = () => {
      if (pt.x != null && !pendingRef.current) {
        const scroller = document.querySelector('.bc-month-scroll, .bc-tg-scroll');
        if (scroller) {
          const r = scroller.getBoundingClientRect();
          if (pt.x >= r.left && pt.x <= r.right) {
            const dy = edgeScrollDy(scroller, pt.y);
            if (dy) {
              scroller.scrollTop += dy;
              tick();
            }
          }
        }
      }
      scrollRaf = requestAnimationFrame(scrollLoop);
    };
    scrollRaf = requestAnimationFrame(scrollLoop);

    // Grid pointerdowns are captured so the normal drag/create/popover
    // handlers stay quiet during the mode. stopPropagation only: native
    // scrollbar drags and wheel scrolling keep working.
    const onPointerDown = (e) => {
      if (!e.target.closest) return;
      if (e.target.closest(UI_SELECTOR)) return;
      if (e.target.closest('.bc-view')) e.stopPropagation();
    };

    // Click = drop. Clicks on reschedule UI pass through; a click anywhere
    // else while the confirm chip is open cancels the chip (mode stays).
    const onClick = (e) => {
      if (!e.target.closest) return;
      if (e.target.closest(UI_SELECTOR)) return;
      if (pendingRef.current) {
        setPending(null);
        if (e.target.closest('.bc-view')) {
          e.stopPropagation();
          e.preventDefault();
        }
        return;
      }
      if (!e.target.closest('.bc-view')) return;
      e.stopPropagation();
      e.preventDefault();
      const info = targetInfo(e.clientX, e.clientY);
      if (!info || info.ui || !info.key) return;
      const o = occRef.current;
      const timed = info.minute != null && !o.allDay;
      const drop = timed
        ? timeDropDates(o, info.key, info.minute)
        : dayDropDates(o, info.key);
      if (parseISO(drop.newStart).getTime() === parseISO(o.start).getTime()) return; // dropped in place
      const label = 'Move to ' + fmtDayMedium(dateOfDayKey(info.key)) +
        (timed ? ', ' + fmtTime(parseISO(drop.newStart)) : '') + '?';
      clearHighlight();
      if (ghostRef.current) ghostRef.current.style.display = 'none';
      setPending({
        instanceId: o.instanceId,
        newStart: drop.newStart,
        newEnd: drop.newEnd,
        targetKey: info.key,
        label,
        x: e.clientX,
        y: e.clientY,
      });
    };

    // Esc cancels the confirm chip first, then exits the mode. Esc typed
    // inside another open overlay (search, quick add, drawer) keeps its
    // normal close-that-overlay meaning.
    const onKey = (e) => {
      if (e.key !== 'Escape') return;
      if (e.target.closest && e.target.closest('.bc-search, .bc-quickadd-wrap, .bc-drawer, .bc-jump-pop')) return;
      e.preventDefault();
      e.stopPropagation();
      if (pendingRef.current) setPending(null);
      else exitRef.current();
    };

    document.body.classList.add('bc-rescheduling');
    window.addEventListener('pointermove', onMove, { passive: true });
    window.addEventListener('pointerdown', onPointerDown, true);
    window.addEventListener('click', onClick, true);
    window.addEventListener('keydown', onKey, true);
    return () => {
      document.body.classList.remove('bc-rescheduling');
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerdown', onPointerDown, true);
      window.removeEventListener('click', onClick, true);
      window.removeEventListener('keydown', onKey, true);
      cancelAnimationFrame(raf);
      cancelAnimationFrame(scrollRaf);
      clearHighlight();
    };
  }, []); // mounted only while the mode is active

  const color = (cal && cal.color) || '#888';
  // Highlight the target occurrence wherever it renders via a generated
  // attribute-selector rule (survives virtualized re-renders; keyframes and
  // reduced-motion handling live with it).
  const sel = '[data-instance=' + JSON.stringify(occ.instanceId) + ']';
  const pulseCss =
    sel + '{outline:2px solid var(--accent);outline-offset:1px;animation:bc-resched-pulse 1.5s ease-in-out infinite;}' +
    '@media (prefers-reduced-motion: reduce){' + sel + '{animation:none;}}';

  let chip = null;
  if (pending) {
    const left = Math.max(8, Math.min(pending.x, window.innerWidth - CHIP_W));
    const top = Math.max(8, Math.min(pending.y + 10, window.innerHeight - 52));
    chip = html`<div class="bc-confirm-chip" style=${`left:${left}px;top:${top}px`} role="dialog" aria-label="Confirm move">
      <span>${pending.label}</span>
      <button
        type="button" class="bc-btn bc-btn-primary"
        ref=${(el) => el && el.focus()}
        onClick=${() => onDropConfirmed(pending)}
      >Move</button>
      <button type="button" class="bc-btn" onClick=${() => setPending(null)}>Cancel</button>
    </div>`;
  }

  return html`<div>
    <style>${pulseCss}</style>
    <div class="bc-drag-ghost bc-resched-ghost" ref=${ghostRef} style="display:none">
      <span class="bc-chip-dot" style=${`background:${color}`}></span>
      <span class="bc-chip-title">${occ.title || '(untitled)'}</span>
    </div>
    ${chip}
  </div>`;
}
