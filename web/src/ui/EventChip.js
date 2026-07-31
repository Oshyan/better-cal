// Shared event rendering: chips (month/agenda) and blocks (timegrid).
// Pure presentation: data in, intent callbacks out.

import { html } from '../../vendor/index.js';
import { contrastText, withAlpha, DEFAULT_COLOR } from '../lib/color.js';
import { parseISO, fmtTime } from '../lib/dates.js';

// Bail out of custom click handling when modifier keys are pressed so
// browser-native behaviors are never hijacked.
function hasModifier(e) {
  return e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1;
}

function calColor(cal) {
  return (cal && cal.color) || DEFAULT_COLOR;
}

function stateClasses(occ, dimmed) {
  let c = '';
  if (occ.attendance === 'interested') c += ' is-interested';
  if (occ.attendance === 'going') c += ' is-going';
  if (occ.status === 'cancelled') c += ' is-cancelled';
  if (occ.isNew) c += ' is-new';
  if (occ.dimmed) c += ' is-filter-dimmed';
  if (dimmed) c += ' is-dimmed';
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

// Compact chip for month cells and agenda rows.
// props: occ, cal, dimmed, showTime, onOpen(instanceId, anchorRect), onPointerDown
export function EventChip({ occ, cal, dimmed, showTime = true, onOpen, onPointerDown }) {
  const color = calColor(cal);
  const interested = occ.attendance === 'interested';
  const style = interested
    ? `border-color:${color};color:${color};background:transparent`
    : `background:${withAlpha(color, 0.16)};color:var(--fg)`;
  const timed = !occ.allDay && showTime;
  return html`<button
    type="button"
    class="bc-chip${stateClasses(occ, dimmed)}"
    style=${style}
    data-instance=${occ.instanceId}
    onPointerDown=${onPointerDown}
    onClick=${(e) => {
      if (hasModifier(e)) return;
      e.stopPropagation();
      if (onOpen) onOpen(occ.instanceId, e.currentTarget.getBoundingClientRect());
    }}
    title=${occ.title}
  >
    <span class="bc-chip-dot" style=${`background:${color}`}></span>
    ${timed && html`<span class="bc-chip-time">${fmtTime(parseISO(occ.start))}</span>`}
    <${GoingCheck} occ=${occ} />
    <span class="bc-chip-title">${occ.title || '(untitled)'}</span>
    ${occ.isNew && html`<${NewPill} />`}
  </button>`;
}

// Solid segment bar for multi-day events in month/week rows.
// props: occ, cal, seg {contLeft, contRight}, dimmed, onOpen, onPointerDown,
//        onEdgePointerDown(edge, ev)
export function EventBar({ occ, cal, seg, dimmed, onOpen, onPointerDown, onEdgePointerDown }) {
  const color = calColor(cal);
  const interested = occ.attendance === 'interested';
  const style = interested
    ? `border:1px solid ${color};color:${color};background:transparent`
    : `background:${color};color:${contrastText(color)}`;
  return html`<div
    class="bc-bar${stateClasses(occ, dimmed)}${seg.contLeft ? ' cont-l' : ''}${seg.contRight ? ' cont-r' : ''}"
    style=${style}
    data-instance=${occ.instanceId}
    role="button"
    tabindex="0"
    onPointerDown=${onPointerDown}
    onClick=${(e) => {
      if (hasModifier(e)) return;
      e.stopPropagation();
      if (onOpen) onOpen(occ.instanceId, e.currentTarget.getBoundingClientRect());
    }}
    onKeyDown=${(e) => {
      if ((e.key === 'Enter' || e.key === ' ') && onOpen) {
        e.preventDefault();
        onOpen(occ.instanceId, e.currentTarget.getBoundingClientRect());
      }
    }}
    title=${occ.title}
  >
    ${!seg.contLeft && onEdgePointerDown && html`<span class="bc-bar-handle l" onPointerDown=${(e) => onEdgePointerDown('start', e)}></span>`}
    ${!seg.contLeft && html`<${GoingCheck} occ=${occ} />`}
    <span class="bc-chip-title">${seg.contLeft ? '‹ ' : ''}${occ.title || '(untitled)'}${seg.contRight ? ' ›' : ''}</span>
    ${occ.isNew && !seg.contLeft && html`<${NewPill} />`}
    ${!seg.contRight && onEdgePointerDown && html`<span class="bc-bar-handle r" onPointerDown=${(e) => onEdgePointerDown('end', e)}></span>`}
  </div>`;
}

// Positioned block for the time grid.
// props: occ, cal, rect {top,height,leftPct,widthPct}, dimmed, compact,
//        onOpen, onPointerDown, onEdgePointerDown(edge, ev)
export function EventBlock({ occ, cal, rect, dimmed, onOpen, onPointerDown, onEdgePointerDown }) {
  const color = calColor(cal);
  const interested = occ.attendance === 'interested';
  const bg = interested
    ? `border:1.5px solid ${color};color:${color};background:var(--bg-raised)`
    : `background:${withAlpha(color, 0.85)};color:${contrastText(color)};border-left:3px solid ${color}`;
  const s = parseISO(occ.start);
  const e = parseISO(occ.end);
  const showMeta = rect.height > 34;
  const showLoc = rect.height > 52 && occ.location;
  return html`<div
    class="bc-block${stateClasses(occ, dimmed)}"
    style=${`top:${rect.top}px;height:${rect.height}px;left:${rect.leftPct}%;width:${rect.widthPct}%;${bg}`}
    data-instance=${occ.instanceId}
    role="button"
    tabindex="0"
    onPointerDown=${onPointerDown}
    onClick=${(ev) => {
      if (hasModifier(ev)) return;
      ev.stopPropagation();
      if (onOpen) onOpen(occ.instanceId, ev.currentTarget.getBoundingClientRect());
    }}
    onKeyDown=${(ev) => {
      if ((ev.key === 'Enter' || ev.key === ' ') && onOpen) {
        ev.preventDefault();
        onOpen(occ.instanceId, ev.currentTarget.getBoundingClientRect());
      }
    }}
  >
    ${onEdgePointerDown && html`<span class="bc-block-handle t" onPointerDown=${(ev) => onEdgePointerDown('start', ev)}></span>`}
    <span class="bc-block-line">
      <${GoingCheck} occ=${occ} />
      <span class="bc-chip-title">${occ.title || '(untitled)'}</span>
      ${occ.isNew && html`<${NewPill} />`}
    </span>
    ${showMeta && html`<span class="bc-block-time">${fmtTime(s)} to ${fmtTime(e)}</span>`}
    ${showLoc && html`<span class="bc-block-loc">${occ.location}</span>`}
    ${onEdgePointerDown && html`<span class="bc-block-handle b" onPointerDown=${(ev) => onEdgePointerDown('end', ev)}></span>`}
  </div>`;
}
