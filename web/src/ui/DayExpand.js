// DayExpand: in-place expanded day. Desktop: overlay panel anchored to the
// day cell; mobile: bottom sheet. Esc or click-out closes. Focus is trapped
// while open.

import { html, useRef, useEffect } from '../../vendor/index.js';
import { dateOfDayKey, fmtDayLong, parseISO, fmtTime, epochDayOfKey, byStart } from '../lib/dates.js';
import { EventChip, EventBar } from './EventChip.js';
import { occurrenceDaySpan } from './monthmath.js';

export const MOBILE_QUERY = '(max-width: 640px)';

export function isMobile() {
  return window.matchMedia(MOBILE_QUERY).matches;
}

// Clamp an anchored panel position to the viewport with a margin.
export function anchorPanel(anchorRect, panelW, panelH, margin = 8) {
  let left = anchorRect.left - 8;
  let top = anchorRect.top - 8;
  if (left + panelW > window.innerWidth - margin) left = window.innerWidth - margin - panelW;
  if (top + panelH > window.innerHeight - margin) top = window.innerHeight - margin - panelH;
  return { left: Math.max(margin, left), top: Math.max(margin, top) };
}

export function DayExpand({ dayKey, anchorRect, occurrences, calendars, dimSet, nowMs, onOpenEvent, onOpenDetail, onClose }) {
  const panelRef = useRef(null);

  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Escape') { e.stopPropagation(); onClose(); }
      if (e.key === 'Tab') trapFocus(panelRef.current, e);
    };
    document.addEventListener('keydown', onKey, true);
    if (panelRef.current) {
      const first = panelRef.current.querySelector('button, [tabindex]');
      if (first) first.focus();
    }
    return () => document.removeEventListener('keydown', onKey, true);
  }, [onClose]);

  const d = dateOfDayKey(dayKey);
  const ed = epochDayOfKey(dayKey);
  const sorted = [...occurrences].sort((a, b) => {
    if (a.allDay !== b.allDay) return a.allDay ? -1 : 1;
    return byStart(a, b);
  });

  const mobile = isMobile();
  let style = '';
  if (!mobile && anchorRect) {
    // Size to the content up to ~85% of the viewport; a scrollbar only
    // appears when the day genuinely cannot fit on screen.
    const w = Math.max(280, Math.min(380, anchorRect.width * 2));
    const maxH = Math.round(window.innerHeight * 0.85);
    const h = Math.min(maxH, 64 + sorted.length * 34 + 40);
    const p = anchorPanel(anchorRect, w, h);
    style = `left:${p.left}px;top:${p.top}px;width:${w}px;max-height:${maxH}px`;
  }

  return html`<div class="bc-overlay" onClick=${(e) => { if (e.target === e.currentTarget) onClose(); }}>
    <div
      class="bc-dayexpand${mobile ? ' bc-sheet' : ''}"
      style=${style}
      ref=${panelRef}
      role="dialog"
      aria-modal="true"
      aria-label=${'Events on ' + fmtDayLong(d)}
    >
      <div class="bc-dayexpand-head">
        <span class="bc-dayexpand-title">${fmtDayLong(d)}</span>
        <button type="button" class="bc-icon-btn" aria-label="Close" onClick=${onClose}>${'✕'}</button>
      </div>
      <div class="bc-dayexpand-list">
        ${sorted.length === 0 && html`<div class="bc-empty">No events this day</div>`}
        ${sorted.map((occ) => {
          // All-day and multi-day events render as filled bars whose ends are
          // angled where the event continues past this day — in a single-day
          // list that arrow is the only cue that it started earlier or runs
          // later. Boxes stay flush left (the angle is a clip, not an inset),
          // so single-day and continuing bars line up.
          const span = occurrenceDaySpan(occ);
          const bar = occ.allDay || span.startKey !== span.endKey;
          const seg = bar
            ? { contLeft: epochDayOfKey(span.startKey) < ed, contRight: epochDayOfKey(span.endKey) > ed }
            : null;
          const openOpts = { dayKey };
          return html`<div key=${occ.instanceId} class="bc-dayexpand-row">
            <span class="bc-dayexpand-time">${occ.allDay ? 'all day' : fmtTime(parseISO(occ.start))}</span>
            ${bar
              ? html`<${EventBar}
                  occ=${occ} cal=${calendars[occ.calendarId]} seg=${seg}
                  dimmed=${dimSet && dimSet.has(occ.instanceId)} nowMs=${nowMs}
                  onOpen=${(id, rect) => onOpenEvent && onOpenEvent(id, rect, openOpts)}
                />`
              : html`<${EventChip}
                  occ=${occ} cal=${calendars[occ.calendarId]} showTime=${false}
                  dimmed=${dimSet && dimSet.has(occ.instanceId)} nowMs=${nowMs}
                  onOpen=${(id, rect) => onOpenEvent && onOpenEvent(id, rect, openOpts)}
                />`}
            ${onOpenDetail && !occ.isGroup && html`<button
              type="button" class="bc-icon-btn bc-dayexpand-open"
              title="Open details" aria-label=${'Open details for ' + (occ.title || 'event')}
              onClick=${() => onOpenDetail(occ.instanceId)}
            >↗</button>`}
          </div>`;
        })}
      </div>
    </div>
  </div>`;
}

// Minimal focus trap: keep Tab cycling inside the panel.
export function trapFocus(panel, e) {
  if (!panel) return;
  const focusables = panel.querySelectorAll('button, a[href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
  if (!focusables.length) return;
  const first = focusables[0];
  const last = focusables[focusables.length - 1];
  if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
  else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
}
