// Shared event rendering: chips (month/agenda) and blocks (timegrid).
// Pure presentation: data in, intent callbacks out.
//
// Synthetic group items (occ.isGroup, from grouping.js) render as a stack
// chip "Title · N": no drag, no resize handles, no time; a click emits the
// same onOpen intent (the app layer routes group ids to the group popover).
// Double-click on a real event asks for the full detail view via
// onOpen(instanceId, rect, {detail: true}).

import { html } from '../../vendor/index.js';
import { contrastText, withAlpha, ink, DEFAULT_COLOR } from '../lib/color.js';
import { parseISO, fmtTime, timeState, eventDuration } from '../lib/dates.js';
import { Icon, PluginGlyph } from './icons.js';

// Bail out of custom click handling when modifier keys are pressed so
// browser-native behaviors are never hijacked.
function hasModifier(e) {
  return e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1;
}

function calColor(cal) {
  return (cal && cal.color) || DEFAULT_COLOR;
}

// Tooltip text. The accent ring an is-highlighted event wears is otherwise
// unexplained — one event outlined and its neighbours not, with nothing on
// screen saying why — so the tooltip names the cause.
// A highlight filter may name its own colour; the glow reads --hl and falls
// back to the accent when no filter chose one.
function hlVar(occ) {
  return occ.highlighted && occ.highlightColor ? `;--hl:${occ.highlightColor}` : '';
}

function chipTitle(occ) {
  const duration = eventDuration(occ);
  const base = occ.isGroup ? `${occ.title} (${occ.count} similar)`
    : (occ.title || '(untitled)') + (duration ? `\n${duration.exact} total` : '');
  if (occ.highlighted) return `${base}\nHighlighted by one of your filters`;
  return base;
}

function stateClasses(occ, dimmed, nowMs) {
  let c = '';
  // Relationship: planned is the norm and carries no mark; maybe is an
  // outline, available is quiet, context quieter (Relationship.js).
  if (occ.relationship === 'maybe') c += ' is-maybe';
  else if (occ.relationship === 'available') c += ' is-available';
  else if (occ.relationship === 'context') c += ' is-context';
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

const DURATION_UNITS = { w: 'week', d: 'day', h: 'hour', m: 'minute', s: 'second' };

export function DurationSuffix({ occ, expanded = false }) {
  const duration = eventDuration(occ);
  if (!duration) return null;
  // Details have room to spell out units; months retain the shared 1mo
  // shorthand. The exact day count stays available even for weeks/months.
  const label = expanded
    ? duration.compact.replace(/(\d+(?:\.\d+)?)([wdhms])\b/g,
      (_, n, unit) => `${n} ${DURATION_UNITS[unit]}${Number(n) === 1 ? '' : 's'}`)
    : duration.compact;
  return html`<span class="bc-event-duration" role="img" aria-label=${duration.exact + ' total'} title=${duration.exact + ' total'}
    ><span aria-hidden="true">· ${label}</span></span>`;
}

export function NewPill() {
  return html`<span class="bc-new-pill" aria-label="recently added">new</span>`;
}

// Small stack glyph marking a near-duplicate group chip.
function StackGlyph() {
  return html`<span class="bc-stack-glyph" aria-hidden="true"><${Icon} name="stack" size=${11} /></span>`;
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
// `seg` is optional and only used by list contexts (the day-expand panel):
// {contLeft, contRight} angles the matching end to show the event continues
// beyond the day being listed.
export function EventChip({ occ, cal, dimmed, nowMs, showTime = true, seg = null, onOpen, onPointerDown }) {
  const color = calColor(cal);
  const interested = occ.relationship === 'maybe';
  // GCal semantics: all-day (and group) chips are filled with the calendar
  // tint; timed events are quiet dot + time + title rows. The tint rides a
  // CSS variable so the mobile pill layout can re-fill dot chips.
  const dotStyle = !occ.allDay && !occ.isGroup && !interested;
  const style = interested
    ? `border-color:${ink(color)};color:${ink(color)};background:transparent`
    : `--tint:${withAlpha(color, 0.16)};color:var(--fg)`;
  const timed = !occ.allDay && showTime && !occ.isGroup;
  const { onClick, onDblClick } = openHandlers(occ, onOpen);
  return html`<button
    type="button"
    class="bc-chip${dotStyle ? ' is-dotstyle' : ''}${seg && seg.contLeft ? ' cont-l' : ''}${seg && seg.contRight ? ' cont-r' : ''}${stateClasses(occ, dimmed, nowMs)}"
    style=${style + hlVar(occ)}
    data-instance=${occ.instanceId}
    onPointerDown=${occ.isGroup ? undefined : onPointerDown}
    onClick=${onClick}
    onDblClick=${onDblClick}
    title=${chipTitle(occ)}
  >
    <span class="bc-chip-dot" style=${`background:${color}`}></span>
    ${timed && html`<span class="bc-chip-time">${fmtTime(parseISO(occ.start))}</span>`}
    ${occ.isGroup && html`<${StackGlyph} />`}
    <span class="bc-chip-title">${occ.title || '(untitled)'}${occ.isGroup ? ` · ${occ.count}` : ''}</span>
    <${DurationSuffix} occ=${occ} />
    ${occ.isNew && !occ.isGroup && html`<${NewPill} />`}
  </button>`;
}

// Solid segment bar for multi-day events in month/week rows.
// props: occ, cal, seg {contLeft, contRight}, dimmed, nowMs, onOpen,
//        onPointerDown, onEdgePointerDown(edge, ev)
export function EventBar({ occ, cal, seg, dimmed, nowMs, onOpen, onPointerDown, onEdgePointerDown }) {
  const color = calColor(cal);
  const interested = occ.relationship === 'maybe';
  const trip = !!occ.isContainer;
  // Containers get a distinct outlined variant (is-trip): tinted, not solid,
  // and never draggable or resizable in v1.
  const style = trip
    ? `border:1.5px solid ${color};color:var(--fg);background:${withAlpha(color, 0.1)}`
    : interested
      ? `border:1px solid ${ink(color)};color:${ink(color)};background:transparent`
      : `background:${color};color:${contrastText(color)}`;
  const { onClick, onDblClick } = openHandlers(occ, onOpen);
  const edges = occ.isGroup || trip ? null : onEdgePointerDown;
  return html`<div
    class="bc-bar${stateClasses(occ, dimmed, nowMs)}${seg.contLeft ? ' cont-l' : ''}${seg.contRight ? ' cont-r' : ''}"
    style=${style + hlVar(occ)}
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
    title=${chipTitle(occ)}
  >
    ${!seg.contLeft && edges && html`<span class="bc-bar-handle l" onPointerDown=${(e) => edges('start', e)}></span>`}
    ${!seg.contLeft && occ.isGroup && html`<${StackGlyph} />`}
    <span class="bc-chip-title">${occ.title || '(untitled)'}${occ.isGroup ? ` · ${occ.count}` : ''}</span>
    <${DurationSuffix} occ=${occ} />
    ${occ.isNew && !occ.isGroup && !seg.contLeft && html`<${NewPill} />`}
    ${!seg.contRight && edges && html`<span class="bc-bar-handle r" onPointerDown=${(e) => edges('end', e)}></span>`}
  </div>`;
}

// Positioned block for the time grid.
// props: occ, cal, rect {top,height,leftPct,widthPct}, dimmed, nowMs, compact,
//        onOpen, onPointerDown, onEdgePointerDown(edge, ev)
export function EventBlock({ occ, cal, rect, dimmed, nowMs, onOpen, onPointerDown, onEdgePointerDown }) {
  const color = calColor(cal);
  const interested = occ.relationship === 'maybe';
  const trip = !!occ.isContainer;
  const bg = trip
    ? `border:1.5px solid ${color};color:var(--fg);background:${withAlpha(color, 0.1)}`
    : interested
      ? `border:1.5px solid ${ink(color)};color:${ink(color)};background:var(--bg-raised)`
      // Opaque, not translucent: cascaded blocks overlap, and a see-through
      // fill let the block underneath bleed through so neither title was
      // readable. Hover raises one to the front when you need the other.
      : `background:${color};color:${contrastText(color)};border-left:3px solid ${color}`;
  const s = parseISO(occ.start);
  const e = parseISO(occ.end);
  const showMeta = rect.height > 34 && !occ.isGroup;
  const showLoc = rect.height > 52 && occ.location;
  const { onClick, onDblClick } = openHandlers(occ, onOpen);
  const edges = occ.isGroup || trip ? null : onEdgePointerDown;
  return html`<div
    class="bc-block${stateClasses(occ, dimmed, nowMs)}"
    title=${chipTitle(occ)}
    style=${`top:${rect.top}px;height:${rect.height}px;left:${rect.leftPct}%;width:${rect.widthPct}%;${rect.z ? `z-index:${rect.z};` : ''}${bg}${hlVar(occ)}`}
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
      ${occ.isGroup && html`<${StackGlyph} />`}
      <span class="bc-chip-title">${occ.title || '(untitled)'}${occ.isGroup ? ` · ${occ.count}` : ''}</span>
      <${DurationSuffix} occ=${occ} />
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
export function TripBand({ occ, cal, seg, dimmed, nowMs, onOpen, onPointerDown, onEdgePointerDown }) {
  // Availability pseudo-occurrences carry no calendar: away is a neutral
  // slate, busy a warm amber, here a present green — all distinct from any
  // calendar color.
  const color = occ.availKind
    ? (occ.availKind === 'away' ? '#8b93a4' : occ.availKind === 'busy' ? '#d19a38' : '#3f9d6e')
    : (occ.pluginColor || calColor(cal));
  const open = (e) => {
    if (hasModifier(e)) return;
    e.stopPropagation();
    if (onOpen) onOpen(occ.instanceId, e.currentTarget.getBoundingClientRect(), { detail: true });
  };
  return html`<div
    class="bc-band${occ.availKind ? ' is-avail' : ''}${occ.pluginId ? ' is-pluginband' : ''}${occ.pluginAnim && occ.pluginAnim !== 'none' ? ' is-anim-' + occ.pluginAnim : ''}${stateClasses(occ, dimmed, nowMs)}${seg.contLeft ? ' cont-l' : ''}${seg.contRight ? ' cont-r' : ''}"
    style=${occ.availKind
      ? `background:${withAlpha(color, 0.09)};border-top:2px dashed ${withAlpha(color, 0.45)};color:${withAlpha(ink(color), 0.9)}`
      : `background:${withAlpha(color, 0.13)};box-shadow:inset 0 2px 0 ${color}`}
    data-instance=${occ.instanceId}
    role="button"
    tabindex="0"
    title=${occ.availKind || occ.pluginId ? (occ.title || '(untitled)') : chipTitle(occ)}
    onPointerDown=${onPointerDown}
    onClick=${open}
    onKeyDown=${(e) => {
      if ((e.key === 'Enter' || e.key === ' ') && onOpen) {
        e.preventDefault();
        onOpen(occ.instanceId, e.currentTarget.getBoundingClientRect(), { detail: true });
      }
    }}
  >
    ${!seg.contLeft && onEdgePointerDown && html`<span class="bc-bar-handle l" onPointerDown=${(e) => { e.stopPropagation(); onEdgePointerDown('start', e); }}></span>`}
    ${(occ.pluginIcon || occ.pluginIconPath) && !seg.contLeft && html`<span class="bc-band-icon"
      >${PluginGlyph({ icon: occ.pluginIcon, iconPath: occ.pluginIconPath })}</span>`}
    <span class="bc-band-label">${seg.contLeft ? '‹ ' : ''}${occ.title || '(untitled)'}</span>
    ${!occ.availKind && !occ.pluginId && html`<${DurationSuffix} occ=${occ} />`}
    ${!seg.contRight && onEdgePointerDown && html`<span class="bc-bar-handle r" onPointerDown=${(e) => { e.stopPropagation(); onEdgePointerDown('end', e); }}></span>`}
  </div>`;
}
