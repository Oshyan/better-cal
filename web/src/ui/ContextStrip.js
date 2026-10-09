// The context strip: one small token per context event in a day's header
// (month cell, week column, day panel, agenda heading), and the hairline
// moment for a timed context event on the timeline. See lib/context.js for
// what a token says and docs/relationships.md for why context lives here.

import { html, useState, useLayoutEffect, useRef } from '../../vendor/index.js';
import { Icon } from './icons.js';
import { contextToken, contextTitle, HOST_ICON, dayLabelText, dayLabelLines } from '../lib/context.js';

const CONTEXT_RENDER_MAX = 100;
const CONTEXT_OVERFLOW_TITLE_MAX = 20;

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
// (week column, day panel), and buttons cannot nest. They name their event
// for the hover card (ui/HoverCard.js) rather than carry a title: the
// browser's own tooltip showed for some tokens and not others.
function Token({ occ, cal, onOpen, zone = true, overflow = false }) {
  const tk = contextToken(occ, cal);
  return html`<span
    role="button" tabindex=${overflow ? -1 : 0} aria-hidden=${overflow ? 'true' : undefined}
    class=${'bc-ctx-token' + (overflow ? ' is-overflow' : '')}
    data-ctx-instance=${overflow ? undefined : occ.instanceId} aria-label=${contextTitle(occ)}
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
  const key = occs && occs.length
    ? `${occs.length}:${occs[0].instanceId}:${occs[occs.length - 1].instanceId}`
    : '';
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
  const rendered = occs.slice(0, CONTEXT_RENDER_MAX);
  const limit = Math.min(rendered.length, fit !== null ? (fitCount ?? rendered.length) : max);
  const rest = occs.length - limit;
  const overflowTitles = occs.slice(limit, limit + CONTEXT_OVERFLOW_TITLE_MAX).map((o) => contextTitle(o));
  if (rest > overflowTitles.length) overflowTitles.push(`…and ${rest - overflowTitles.length} more`);
  return html`<span class="bc-ctxstrip" role="list" aria-label="Context for this day" ref=${ref}>
    ${rendered.map((occ, i) => (fit !== null || i < limit) && html`<${Token} key=${occ.instanceId} occ=${occ} cal=${calendars && calendars[occ.calendarId]} onOpen=${onOpen} zone=${zone} overflow=${i >= limit} />`)}
    ${more && rest > 0 && html`<span
      role="button" tabindex="0" class="bc-ctx-more"
      data-hovertext=${overflowTitles.join('\n')} aria-label=${overflowTitles.join(', ')}
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
      role="button" tabindex="0" class="bc-ctx-mark-label"
      data-ctx-instance=${occ.instanceId} aria-label=${contextTitle(occ)}
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
  const lines = dayLabelLines(occs).join('\n');
  if (flag) {
    return html`<span
      role="button" tabindex="0" class="bc-daylabel is-flag" data-hovertext=${lines} aria-label=${text}
      onPointerDown=${(e) => e.stopPropagation()}
      onClick=${(e) => open(onOpen, occs[0], e)}
      onKeyDown=${(e) => keyOpen(onOpen, occs[0], e)}
    ><${Icon} name="flag" size=${11} /></span>`;
  }
  return html`<span
    role="button" tabindex="0" class=${'bc-daylabel' + (sep ? ' is-sep' : '')} data-hovertext=${lines}
    onPointerDown=${(e) => e.stopPropagation()}
    onClick=${(e) => open(onOpen, occs[0], e)}
    onKeyDown=${(e) => keyOpen(onOpen, occs[0], e)}
  >${text}</span>`;
}
