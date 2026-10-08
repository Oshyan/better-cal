// DayExpand: in-place expanded day. Desktop: overlay panel anchored to the
// day cell; mobile: bottom sheet. Esc or click-out closes. Focus is trapped
// while open.

import { PHONE_QUERY } from '../lib/breakpoints.js';
import { html, useRef, useState, useMemo, useEffect } from '../../vendor/index.js';
import { dateOfDayKey, fmtDayLong, parseISO, fmtTime, epochDayOfKey, byStart } from '../lib/dates.js';
import { EventChip } from './EventChip.js';
import { occurrenceDaySpan } from './monthmath.js';
import { Icon } from './icons.js';
import { TokenIcon, DayLabel } from './ContextStrip.js';
import { isContext, isDayLabel, contextToken, contextText, contextTitle } from '../lib/context.js';

export const MOBILE_QUERY = PHONE_QUERY;
const EVENTS_PER_PAGE = 200;
const CONTEXT_PER_PAGE = 100;

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

export function DayExpand({ dayKey, anchorRect, occurrences, calendars, dimSet, nowMs, onOpenEvent, onOpenDetail, onNew, onClose }) {
  const panelRef = useRef(null);
  const [eventPage, setEventPage] = useState(0);
  const [contextPage, setContextPage] = useState(0);

  useEffect(() => {
    setEventPage(0);
    setContextPage(0);
  }, [dayKey]);

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
  // Context (weather, sunset, tides) sits in its own section on top: the
  // state of the day first, then what is planned.
  const labels = occurrences.filter((o) => isDayLabel(o, calendars));
  const ctxAll = useMemo(() => occurrences.filter((o) => isContext(o) && !isDayLabel(o, calendars)).sort((a, b) => {
    if (a.allDay !== b.allDay) return a.allDay ? -1 : 1;
    return byStart(a, b);
  }), [occurrences, calendars]);
  const sortedAll = useMemo(() => occurrences.filter((o) => !isContext(o)).sort((a, b) => {
    if (a.allDay !== b.allDay) return a.allDay ? -1 : 1;
    return byStart(a, b);
  }), [occurrences]);
  const eventPages = Math.max(1, Math.ceil(sortedAll.length / EVENTS_PER_PAGE));
  const contextPages = Math.max(1, Math.ceil(ctxAll.length / CONTEXT_PER_PAGE));
  const safeEventPage = Math.min(eventPage, eventPages - 1);
  const safeContextPage = Math.min(contextPage, contextPages - 1);
  const sorted = sortedAll.slice(safeEventPage * EVENTS_PER_PAGE, (safeEventPage + 1) * EVENTS_PER_PAGE);
  const ctx = ctxAll.slice(safeContextPage * CONTEXT_PER_PAGE, (safeContextPage + 1) * CONTEXT_PER_PAGE);

  const mobile = isMobile();
  let style = '';
  if (!mobile && anchorRect) {
    // Size to the content up to ~85% of the viewport; a scrollbar only
    // appears when the day genuinely cannot fit on screen.
    const w = Math.max(280, Math.min(380, anchorRect.width * 2));
    const maxH = Math.round(window.innerHeight * 0.85);
    const h = Math.min(maxH, 64 + sorted.length * 34 + 40 + (ctx.length ? 14 + ctx.length * 26 : 0));
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
        <span class="bc-dayexpand-title">${fmtDayLong(d)}<${DayLabel} occs=${labels} onOpen=${(id, rect) => onOpenEvent && onOpenEvent(id, rect, { dayKey })} sep=${true} /></span>
        ${onNew && html`<button type="button" class="bc-btn bc-dayexpand-new" title=${'New event on ' + fmtDayLong(d)} onClick=${onNew}><${Icon} name="plus" size=${14} />New</button>`}
        <button type="button" class="bc-icon-btn" aria-label="Close" onClick=${onClose}><${Icon} name="close" size=${14} /></button>
      </div>
      <div class="bc-dayexpand-list">
        ${ctx.length > 0 && html`<div class="bc-dayexpand-ctx" role="list" aria-label="Context for this day">
          ${ctx.map((occ) => {
            const cal = calendars[occ.calendarId];
            const tk = contextToken(occ, cal, 60);
            return html`<button
              key=${occ.instanceId} type="button" role="listitem" class="bc-dayexpand-ctxrow" title=${contextTitle(occ)}
              onClick=${(e) => onOpenEvent && onOpenEvent(occ.instanceId, e.currentTarget.getBoundingClientRect(), { dayKey })}
            >
              <${TokenIcon} token=${tk} cal=${cal} size=${12} />
              <span class="bc-dayexpand-ctxtext">${contextText(occ) || '(untitled)'}</span>
              ${tk.time && html`<span class="bc-dayexpand-ctxtime">${tk.time}${tk.zone ? ' ' + tk.zone : ''}</span>`}
            </button>`;
          })}
          ${contextPages > 1 && html`<div class="bc-dayexpand-pager">
            <button type="button" class="bc-link-btn" disabled=${safeContextPage === 0} onClick=${() => setContextPage(safeContextPage - 1)}>Previous context</button>
            <span>${safeContextPage * CONTEXT_PER_PAGE + 1}–${Math.min(ctxAll.length, (safeContextPage + 1) * CONTEXT_PER_PAGE)} of ${ctxAll.length}</span>
            <button type="button" class="bc-link-btn" disabled=${safeContextPage + 1 >= contextPages} onClick=${() => setContextPage(safeContextPage + 1)}>Next context</button>
          </div>`}
        </div>`}
        ${sortedAll.length === 0 && html`<div class="bc-empty">${ctxAll.length ? 'Nothing planned' : 'No events this day'}</div>`}
        ${sorted.map((occ) => {
          // All-day and multi-day events keep the tinted full-width chip (dot
          // + title) and gain angled ends where the event continues past this
          // day — in a single-day list that arrow is the only cue that it
          // started earlier or runs later. The angle is a clip, not an inset,
          // so every row still lines up on the same left edge.
          const span = occurrenceDaySpan(occ);
          const multi = occ.allDay || span.startKey !== span.endKey;
          const seg = multi
            ? { contLeft: epochDayOfKey(span.startKey) < ed, contRight: epochDayOfKey(span.endKey) > ed }
            : null;
          const openOpts = { dayKey };
          return html`<div key=${occ.instanceId} class="bc-dayexpand-row">
            <span class="bc-dayexpand-time">${occ.allDay ? 'all day' : fmtTime(parseISO(occ.start))}</span>
            <${EventChip}
              occ=${occ} cal=${calendars[occ.calendarId]} showTime=${false} seg=${seg}
              dimmed=${dimSet && dimSet.has(occ.instanceId)} nowMs=${nowMs}
              onOpen=${(id, rect) => onOpenEvent && onOpenEvent(id, rect, openOpts)}
            />
            ${onOpenDetail && !occ.isGroup && html`<button
              type="button" class="bc-icon-btn bc-dayexpand-open"
              title="Open details" aria-label=${'Open details for ' + (occ.title || 'event')}
              onClick=${() => onOpenDetail(occ.instanceId)}
            ><${Icon} name="arrowUpRight" size=${13} /></button>`}
          </div>`;
        })}
        ${eventPages > 1 && html`<div class="bc-dayexpand-pager">
          <button type="button" class="bc-link-btn" disabled=${safeEventPage === 0} onClick=${() => setEventPage(safeEventPage - 1)}>Previous events</button>
          <span>${safeEventPage * EVENTS_PER_PAGE + 1}–${Math.min(sortedAll.length, (safeEventPage + 1) * EVENTS_PER_PAGE)} of ${sortedAll.length}</span>
          <button type="button" class="bc-link-btn" disabled=${safeEventPage + 1 >= eventPages} onClick=${() => setEventPage(safeEventPage + 1)}>Next events</button>
        </div>`}
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
