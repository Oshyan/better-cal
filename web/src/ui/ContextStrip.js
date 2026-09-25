// The context strip: one small token per context event in a day's header
// (month cell, week column, day panel, agenda heading), and the hairline
// moment for a timed context event on the timeline. See lib/context.js for
// what a token says and docs/relationships.md for why context lives here.

import { html } from '../../vendor/index.js';
import { Icon } from './icons.js';
import { contextToken, contextTitle, HOST_ICON, dayLabelText } from '../lib/context.js';

function open(onOpen, occ, e) {
  e.stopPropagation();
  if (onOpen) onOpen(occ.instanceId, e.currentTarget.getBoundingClientRect());
}

function keyOpen(onOpen, occ, e) {
  if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(onOpen, occ, e); }
}

/** The token's icon: a host icon, a literal glyph, or the calendar's hollow square. */
export function TokenIcon({ token, cal, size = 10 }) {
  if (token.icon && HOST_ICON.test(token.icon)) return html`<span class="bc-ctx-icon"><${Icon} name=${token.icon} size=${size} /></span>`;
  if (token.icon) return html`<span class="bc-ctx-emoji">${token.icon}</span>`;
  return html`<span class="bc-ctx-glyph" style=${`border-color:${(cal && cal.color) || 'var(--fg-muted)'}`}></span>`;
}

// Tokens are spans with the button role: a header is often itself a button
// (week column, day panel), and buttons cannot nest.
function Token({ occ, cal, onOpen, zone = true }) {
  const tk = contextToken(occ, cal);
  return html`<span
    role="button" tabindex="0" class="bc-ctx-token" title=${contextTitle(occ)}
    onPointerDown=${(e) => e.stopPropagation()}
    onClick=${(e) => open(onOpen, occ, e)}
    onKeyDown=${(e) => keyOpen(onOpen, occ, e)}
  >
    <${TokenIcon} token=${tk} cal=${cal} />
    ${tk.text && html`<span class="bc-ctx-token-text">${tk.text}</span>`}
    ${tk.time && html`<span class="bc-ctx-token-time">${tk.time}${zone && tk.zone ? ' ' + tk.zone : ''}</span>`}
  </span>`;
}

/**
 * @param {{occs:object[], calendars:object, max?:number, onOpen?:Function, onMore?:Function}} props
 * occs: this day's context (lib/context.js contextByDay). max: tokens
 * before "+N" (none when more is false); onMore opens the day.
 */
export function ContextStrip({ occs, calendars, max = Infinity, more = true, onOpen, onMore, zone = true }) {
  if (!occs || occs.length === 0) return null;
  const shown = occs.slice(0, max);
  const rest = occs.length - shown.length;
  return html`<span class="bc-ctxstrip" role="list" aria-label="Context for this day">
    ${shown.map((occ) => html`<${Token} key=${occ.instanceId} occ=${occ} cal=${calendars && calendars[occ.calendarId]} onOpen=${onOpen} zone=${zone} />`)}
    ${more && rest > 0 && html`<span
      role="button" tabindex="0" class="bc-ctx-more"
      title=${occs.slice(max).map((o) => contextTitle(o)).join('\n')}
      onPointerDown=${(e) => e.stopPropagation()}
      onClick=${(e) => { e.stopPropagation(); if (onMore) onMore(); }}
      onKeyDown=${(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); if (onMore) onMore(); } }}
    >+${rest}</span>`}
  </span>`;
}

/** A timed context event on the timeline: a dashed hairline at its minute with a small label, never a block. */
export function ContextMark({ occ, cal, top, onOpen }) {
  const tk = contextToken(occ, cal, 18);
  // At hairline size the horizon glyphs turn to mud; the plain sun reads.
  if (tk.icon === 'sunrise' || tk.icon === 'sunset') tk.icon = 'sun';
  const color = (cal && cal.color) || 'var(--fg-muted)';
  return html`<div class="bc-ctx-mark" style=${`top:${top}px`}>
    <span class="bc-ctx-mark-line" style=${`border-color:${color}`}></span>
    <span
      role="button" tabindex="0" class="bc-ctx-mark-label" title=${contextTitle(occ)}
      onPointerDown=${(e) => e.stopPropagation()}
      onClick=${(e) => open(onOpen, occ, e)}
      onKeyDown=${(e) => keyOpen(onOpen, occ, e)}
    ><${TokenIcon} token=${tk} cal=${cal} size=${11} />${tk.text && html`<span class="bc-ctx-token-text">${tk.text}</span>`}${tk.time && html`<span class="bc-ctx-token-time">${tk.time}${tk.zone ? ' ' + tk.zone : ''}</span>`}</span>
  </div>`;
}

/**
 * A day's label (a holiday), written after the date: plain words, never an
 * icon, cut short with an ellipsis where the header is tight. `sep` puts a
 * middle dot before it, for headings that read "Mon, Oct 12 · Columbus Day".
 * Opens the (first) holiday like any event.
 *
 * `flag`: the phone's full month, where a cell has room for a letter or two
 * of the name and nothing more. A small flag after the day number instead;
 * the holidays everyone knows need no spelling out, and a tap names the rest.
 */
export function DayLabel({ occs, onOpen, sep = false, flag = false }) {
  if (!occs || occs.length === 0) return null;
  const text = dayLabelText(occs);
  if (flag) {
    return html`<span
      role="button" tabindex="0" class="bc-daylabel is-flag" title=${text} aria-label=${text}
      onPointerDown=${(e) => e.stopPropagation()}
      onClick=${(e) => open(onOpen, occs[0], e)}
      onKeyDown=${(e) => keyOpen(onOpen, occs[0], e)}
    ><${Icon} name="flag" size=${11} /></span>`;
  }
  return html`<span
    role="button" tabindex="0" class=${'bc-daylabel' + (sep ? ' is-sep' : '')} title=${text}
    onPointerDown=${(e) => e.stopPropagation()}
    onClick=${(e) => open(onOpen, occs[0], e)}
    onKeyDown=${(e) => keyOpen(onOpen, occs[0], e)}
  >${text}</span>`;
}
