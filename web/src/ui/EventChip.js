// Shared event rendering: chips (month/agenda) and blocks (timegrid).
// Pure presentation: data in, intent callbacks out.
//
// Synthetic group items (occ.isGroup, from grouping.js) render as a stack
// chip "Title · N": no drag, no resize handles, no time; a click emits the
// same onOpen intent (the app layer routes group ids to the group popover).
// Double-click on a real event asks for the full detail view via
// onOpen(instanceId, rect, {detail: true}).

import { html } from '../../vendor/index.js';
import { contrastText, withAlpha, DEFAULT_COLOR } from '../lib/color.js';
import { parseISO, fmtTime, timeState } from '../lib/dates.js';

// Bail out of custom click handling when modifier keys are pressed so
// browser-native behaviors are never hijacked.
function hasModifier(e) {
  return e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1;
}

function calColor(cal) {
  return (cal && cal.color) || DEFAULT_COLOR;
}

function stateClasses(occ, dimmed, nowMs) {
  let c = '';
  if (occ.attendance === 'interested') c += ' is-interested';
  if (occ.attendance === 'going') c += ' is-going';
  if (occ.status === 'cancelled') c += ' is-cancelled';
  if (occ.isNew) c += ' is-new';
  if (occ.isGroup) c += ' is-group';
  if (occ.isContainer) c += ' is-trip';
  if (occ.dimmed) c += ' is-filter-dimmed';
  // Time-relative state (from the global minute tick): ended events dim,
  // currently running events carry the gold ring.
  if (nowMs) {
    const ts = timeState(occ, nowMs);
    if (ts === 'past') c += ' is-past';
    else if (ts === 'now') c += ' is-now';
  }
  // Server highlight filters: accent ring + slight saturation boost.
  if (occ.highlighted) c += ' is-highlighted';
  // On-page type-to-filter: non-matching events are removed from view while
  // the filter text is non-empty (the `dimmed` prop carries that flag).
  if (dimmed) c += ' is-page-hidden';
  return c;
}

export function NewPill() {
  return html`<span class="bc-new-pill" aria-label="recently added">new</span>`;
}

// Solid check glyph before the title on events marked going.
function GoingCheck({ occ }) {
  if (occ.attendance !== 'going') return null;
  return html`<span class="bc-chip-check" aria-label="going">✓</span>`;
}

// Small stack glyph marking a near-duplicate group chip.
function StackGlyph() {
  return html`<span class="bc-stack-glyph" aria-hidden="true">⧉</span>`;
}

function openHandlers(occ, onOpen) {
  return {
    onClick: (e) => {
      if (hasModifier(e)) return;
      e.stopPropagation();
      // Containers skip the popover: a single click always opens the trip
      // detail view (the popover's inline edits make no sense for a trip).
      if (onOpen) onOpen(occ.instanceId, e.currentTarget.getBoundingClientRect(), occ.isContainer ? { detail: true } : undefined);
    },
    onDblClick: (e) => {
      if (hasModifier(e) || occ.isGroup) return;
      e.stopPropagation();
      if (onOpen) onOpen(occ.instanceId, e.currentTarget.getBoundingClientRect(), { detail: true });
    },
  };
}

// Compact chip for month cells and agenda rows.
// props: occ, cal, dimmed, nowMs, showTime,
//        onOpen(instanceId, anchorRect, opts?), onPointerDown
export function EventChip({ occ, cal, dimmed, nowMs, showTime = true, onOpen, onPointerDown }) {
  const color = calColor(cal);
  const interested = occ.attendance === 'interested';
  const style = interested
    ? `border-color:${color};color:${color};background:transparent`
    : `background:${withAlpha(color, 0.16)};color:var(--fg)`;
  const timed = !occ.allDay && showTime && !occ.isGroup;
  const { onClick, onDblClick } = openHandlers(occ, onOpen);
  return html`<button
    type="button"
    class="bc-chip${stateClasses(occ, dimmed, nowMs)}"
    style=${style}
    data-instance=${occ.instanceId}
    onPointerDown=${occ.isGroup ? undefined : onPointerDown}
    onClick=${onClick}
    onDblClick=${onDblClick}
    title=${occ.isGroup ? `${occ.title} (${occ.count} similar)` : occ.title}
  >
    <span class="bc-chip-dot" style=${`background:${color}`}></span>
    ${timed && html`<span class="bc-chip-time">${fmtTime(parseISO(occ.start))}</span>`}
    ${occ.isGroup ? html`<${StackGlyph} />` : html`<${GoingCheck} occ=${occ} />`}
    <span class="bc-chip-title">${occ.title || '(untitled)'}${occ.isGroup ? ` · ${occ.count}` : ''}</span>
    ${occ.isNew && !occ.isGroup && html`<${NewPill} />`}
  </button>`;
}

// Solid segment bar for multi-day events in month/week rows.
// props: occ, cal, seg {contLeft, contRight}, dimmed, nowMs, onOpen,
//        onPointerDown, onEdgePointerDown(edge, ev)
export function EventBar({ occ, cal, seg, dimmed, nowMs, onOpen, onPointerDown, onEdgePointerDown }) {
  const color = calColor(cal);
  const interested = occ.attendance === 'interested';
  const trip = !!occ.isContainer;
  // Containers get a distinct outlined variant (is-trip): tinted, not solid,
  // and never draggable or resizable in v1.
  const style = trip
    ? `border:1.5px solid ${color};color:var(--fg);background:${withAlpha(color, 0.1)}`
    : interested
      ? `border:1px solid ${color};color:${color};background:transparent`
      : `background:${color};color:${contrastText(color)}`;
  const { onClick, onDblClick } = openHandlers(occ, onOpen);
  const edges = occ.isGroup || trip ? null : onEdgePointerDown;
  return html`<div
    class="bc-bar${stateClasses(occ, dimmed, nowMs)}${seg.contLeft ? ' cont-l' : ''}${seg.contRight ? ' cont-r' : ''}"
    style=${style}
    data-instance=${occ.instanceId}
    role="button"
    tabindex="0"
    onPointerDown=${occ.isGroup || trip ? undefined : onPointerDown}
    onClick=${onClick}
    onDblClick=${onDblClick}
    onKeyDown=${(e) => {
      if ((e.key === 'Enter' || e.key === ' ') && onOpen) {
        e.preventDefault();
        onOpen(occ.instanceId, e.currentTarget.getBoundingClientRect(), trip ? { detail: true } : undefined);
      }
    }}
    title=${occ.isGroup ? `${occ.title} (${occ.count} similar)` : occ.title}
  >
    ${!seg.contLeft && edges && html`<span class="bc-bar-handle l" onPointerDown=${(e) => edges('start', e)}></span>`}
    ${!seg.contLeft && (occ.isGroup ? html`<${StackGlyph} />` : html`<${GoingCheck} occ=${occ} />`)}
    <span class="bc-chip-title">${seg.contLeft ? '‹ ' : ''}${occ.title || '(untitled)'}${occ.isGroup ? ` · ${occ.count}` : ''}${seg.contRight ? ' ›' : ''}</span>
    ${occ.isNew && !occ.isGroup && !seg.contLeft && html`<${NewPill} />`}
    ${!seg.contRight && edges && html`<span class="bc-bar-handle r" onPointerDown=${(e) => edges('end', e)}></span>`}
  </div>`;
}

// Positioned block for the time grid.
// props: occ, cal, rect {top,height,leftPct,widthPct}, dimmed, nowMs, compact,
//        onOpen, onPointerDown, onEdgePointerDown(edge, ev)
export function EventBlock({ occ, cal, rect, dimmed, nowMs, onOpen, onPointerDown, onEdgePointerDown }) {
  const color = calColor(cal);
  const interested = occ.attendance === 'interested';
  const trip = !!occ.isContainer;
  const bg = trip
    ? `border:1.5px solid ${color};color:var(--fg);background:${withAlpha(color, 0.1)}`
    : interested
      ? `border:1.5px solid ${color};color:${color};background:var(--bg-raised)`
      : `background:${withAlpha(color, 0.85)};color:${contrastText(color)};border-left:3px solid ${color}`;
  const s = parseISO(occ.start);
  const e = parseISO(occ.end);
  const showMeta = rect.height > 34 && !occ.isGroup;
  const showLoc = rect.height > 52 && occ.location;
  const { onClick, onDblClick } = openHandlers(occ, onOpen);
  const edges = occ.isGroup || trip ? null : onEdgePointerDown;
  return html`<div
    class="bc-block${stateClasses(occ, dimmed, nowMs)}"
    style=${`top:${rect.top}px;height:${rect.height}px;left:${rect.leftPct}%;width:${rect.widthPct}%;${bg}`}
    data-instance=${occ.instanceId}
    role="button"
    tabindex="0"
    onPointerDown=${occ.isGroup || trip ? undefined : onPointerDown}
    onClick=${onClick}
    onDblClick=${onDblClick}
    onKeyDown=${(ev) => {
      if ((ev.key === 'Enter' || ev.key === ' ') && onOpen) {
        ev.preventDefault();
        onOpen(occ.instanceId, ev.currentTarget.getBoundingClientRect(), trip ? { detail: true } : undefined);
      }
    }}
  >
    ${edges && html`<span class="bc-block-handle t" onPointerDown=${(ev) => edges('start', ev)}></span>`}
    <span class="bc-block-line">
      ${occ.isGroup ? html`<${StackGlyph} />` : html`<${GoingCheck} occ=${occ} />`}
      <span class="bc-chip-title">${occ.title || '(untitled)'}${occ.isGroup ? ` · ${occ.count}` : ''}</span>
      ${occ.isNew && !occ.isGroup && html`<${NewPill} />`}
    </span>
    ${showMeta && html`<span class="bc-block-time">${fmtTime(s)} to ${fmtTime(e)}</span>`}
    ${showLoc && html`<span class="bc-block-loc">${occ.location}</span>`}
    ${edges && html`<span class="bc-block-handle b" onPointerDown=${(ev) => edges('end', ev)}></span>`}
  </div>`;
}

// Backdrop band for container (trip) events in month/ribbon rows: a soft
// tinted strip along the top of the trip's day span, under the normal bars
// and above the cell background. The inline style carries the calendar tint
// plus the stronger 2px top edge. Not draggable in v1; click or Enter opens
// the trip detail. The title labels each row's segment, small and truncated.
export function TripBand({ occ, cal, seg, dimmed, nowMs, onOpen }) {
  const color = calColor(cal);
  const open = (e) => {
    if (hasModifier(e)) return;
    e.stopPropagation();
    if (onOpen) onOpen(occ.instanceId, e.currentTarget.getBoundingClientRect(), { detail: true });
  };
  return html`<div
    class="bc-band${stateClasses(occ, dimmed, nowMs)}${seg.contLeft ? ' cont-l' : ''}${seg.contRight ? ' cont-r' : ''}"
    style=${`background:${withAlpha(color, 0.13)};box-shadow:inset 0 2px 0 ${color}`}
    data-instance=${occ.instanceId}
    role="button"
    tabindex="0"
    title=${occ.title || '(untitled)'}
    onClick=${open}
    onKeyDown=${(e) => {
      if ((e.key === 'Enter' || e.key === ' ') && onOpen) {
        e.preventDefault();
        onOpen(occ.instanceId, e.currentTarget.getBoundingClientRect(), { detail: true });
      }
    }}
  >
    <span class="bc-band-label">${seg.contLeft ? '‹ ' : ''}${occ.title || '(untitled)'}</span>
  </div>`;
}
