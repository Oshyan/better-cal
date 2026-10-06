// The context strip: one small token per context event in a day's header
// (month cell, week column, day panel, agenda heading), and the hairline
// moment for a timed context event on the timeline. See lib/context.js for
// what a token says and docs/relationships.md for why context lives here.

import { html, useState, useLayoutEffect, useRef } from '../../vendor/index.js';
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
function Token({ occ, cal, onOpen, zone = true, overflow = false }) {
  const tk = contextToken(occ, cal);
  return html`<span
    role="button" tabindex=${overflow ? -1 : 0} aria-hidden=${overflow ? 'true' : undefined}
    class=${'bc-ctx-token' + (overflow ? ' is-overflow' : '')} title=${contextTitle(occ)}
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
 * @param {{occs:object[], calendars:object, max?:number, fit?:number|null, onOpen?:Function, onMore?:Function}} props
 * occs: this day's context (lib/context.js contextByDay). max: tokens
 * before "+N" (none when more is false); onMore opens the day.
 * fit (month cells, #108): instead of a fixed count, as many as the header
 * has room for, keeping `fit` px free at its end (the space the hover
 * buttons take, so hovering never changes the count), then "+N". Every token
 * is rendered so each can be measured; the ones past the room are hidden.
 * Measured again whenever the header changes size.
 */
export function ContextStrip({ occs, calendars, max = Infinity, fit = null, more = true, onOpen, onMore, zone = true }) {
  const ref = useRef(null);
  const [fitCount, setFitCount] = useState(null);
  const key = occs ? occs.map((o) => o.instanceId).join('|') : '';
  useLayoutEffect(() => {
    if (fit === null || !ref.current) return undefined;
    const strip = ref.current;
    const head = strip.parentElement;
    const measure = () => {
      const tokens = [...strip.querySelectorAll('.bc-ctx-token')];
      let others = 0;
      for (const el of head.children) if (el !== strip && el.offsetParent !== null) others += el.offsetWidth + 2;
      const avail = head.clientWidth - fit - others;
      const moreW = 22; // "+N" at this size, with its gap
      let used = 0;
      let k = 0;
      for (; k < tokens.length; k++) {
        const w = tokens[k].offsetWidth + (k > 0 ? 2 : 0);
        if (used + w + (k + 1 < tokens.length ? moreW : 0) > avail) break;
        used += w;
      }
      setFitCount(k);
    };
    measure();
    const ro = new ResizeObserver(measure);
    ro.observe(head);
    return () => ro.disconnect();
  }, [fit, key]); // eslint-disable-line
  if (!occs || occs.length === 0) return null;
  const limit = Math.min(occs.length, fit !== null ? (fitCount ?? occs.length) : max);
  const rest = occs.length - limit;
  return html`<span class="bc-ctxstrip" role="list" aria-label="Context for this day" ref=${ref}>
    ${occs.map((occ, i) => (fit !== null || i < limit) && html`<${Token} key=${occ.instanceId} occ=${occ} cal=${calendars && calendars[occ.calendarId]} onOpen=${onOpen} zone=${zone} overflow=${i >= limit} />`)}
    ${more && rest > 0 && html`<span
      role="button" tabindex="0" class="bc-ctx-more"
      title=${occs.slice(limit).map((o) => contextTitle(o)).join('\n')}
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
