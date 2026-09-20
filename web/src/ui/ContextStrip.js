// The context strip: one small token per context event in a day's header
// (month cell, week column, day panel, agenda heading), and the hairline
// moment for a timed context event on the timeline. See lib/context.js for
// what a token says and docs/relationships.md for why context lives here.

import { html } from '../../vendor/index.js';
import { PluginGlyph } from './icons.js';
import { contextLabel, contextTitle } from '../lib/context.js';

function open(onOpen, occ, e) {
  e.stopPropagation();
  if (onOpen) onOpen(occ.instanceId, e.currentTarget.getBoundingClientRect());
}

function keyOpen(onOpen, occ, e) {
  if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(onOpen, occ, e); }
}

// Tokens are spans with the button role: a header is often itself a button
// (week column, day panel), and buttons cannot nest.
function Token({ occ, cal, onOpen }) {
  const { text, time } = contextLabel(occ);
  const color = (cal && cal.color) || 'var(--fg-muted)';
  return html`<span
    role="button" tabindex="0" class="bc-ctx-token" title=${contextTitle(occ)}
    onPointerDown=${(e) => e.stopPropagation()}
    onClick=${(e) => open(onOpen, occ, e)}
    onKeyDown=${(e) => keyOpen(onOpen, occ, e)}
  >
    ${occ.pluginIcon || occ.pluginIconPath
      ? html`<${PluginGlyph} icon=${occ.pluginIcon} iconPath=${occ.pluginIconPath} size=${10} />`
      : html`<span class="bc-ctx-glyph" style=${`border-color:${color}`}></span>`}
    <span class="bc-ctx-token-text">${text}</span>
    ${time && html`<span class="bc-ctx-token-time">${time}</span>`}
  </span>`;
}

/**
 * @param {{occs:object[], calendars:object, max?:number, onOpen?:Function, onMore?:Function}} props
 * occs: this day's context (lib/context.js contextByDay). max: tokens
 * before "+N" (the month cell has room for three); onMore opens the day.
 */
export function ContextStrip({ occs, calendars, max = Infinity, onOpen, onMore }) {
  if (!occs || occs.length === 0) return null;
  const shown = occs.slice(0, max);
  const rest = occs.length - shown.length;
  return html`<span class="bc-ctxstrip" role="list" aria-label="Context for this day">
    ${shown.map((occ) => html`<${Token} key=${occ.instanceId} occ=${occ} cal=${calendars && calendars[occ.calendarId]} onOpen=${onOpen} />`)}
    ${rest > 0 && html`<span
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
  const { text, time } = contextLabel(occ, 18);
  const color = (cal && cal.color) || 'var(--fg-muted)';
  return html`<div class="bc-ctx-mark" style=${`top:${top}px`}>
    <span class="bc-ctx-mark-line" style=${`border-color:${color}`}></span>
    <span
      role="button" tabindex="0" class="bc-ctx-mark-label" title=${contextTitle(occ)}
      onPointerDown=${(e) => e.stopPropagation()}
      onClick=${(e) => open(onOpen, occ, e)}
      onKeyDown=${(e) => keyOpen(onOpen, occ, e)}
    >${text}${time && html` <span class="bc-ctx-token-time">${time}</span>`}</span>
  </div>`;
}
