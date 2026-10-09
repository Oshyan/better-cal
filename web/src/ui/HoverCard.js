// Hover card for events: the full title (cut short in a narrow month cell),
// when, which calendar and where, then a smaller italic line for each reason
// the event looks the way it does (lib/eventwhy.js). Replaces the browser's
// own title tooltip, which showed unreliably and only after a long pause.
//
// One card for the whole app, driven by delegated pointer events on any
// element carrying data-instance: mouse and trackpad only (a touch has no
// hover), shown after a short rest, gone on leave, press, scroll or keypress.
// Context items get it too: a token or timeline mark names its event with
// data-ctx-instance (not data-instance, which would make it draggable), and a
// "+N" or holiday label that stands for several gives its lines in
// data-hovertext, one per line.

import { html, render } from '../../vendor/index.js';
import { state } from '../app/store.js';
import { parseISO, fmtRange, eventDuration } from '../lib/dates.js';
import { isPendingLocation } from '../lib/maps.js';
import { formattingReasons } from '../lib/eventwhy.js';

export const HOVER_DELAY_MS = 700;
const TARGETS = '.bc-chip[data-instance], .bc-bar[data-instance], .bc-block[data-instance], [data-ctx-instance], [data-hovertext]';

function Card({ occ, cal, at, nowMs }) {
  const s = parseISO(occ.start);
  const e = occ.end ? parseISO(occ.end) : s;
  const reasons = formattingReasons(occ, nowMs);
  const place = occ.location && !isPendingLocation(occ.location) ? occ.location : null;
  const duration = eventDuration(occ); // multi-day: the exact total, as the old tooltip gave it
  return html`<div class="bc-hovercard" role="tooltip" style=${`left:${at.left}px;top:${at.top}px`}>
    <div class="bc-hovercard-title">${occ.title || '(untitled)'}${occ.isGroup ? ` (${occ.count} similar)` : ''}</div>
    <div class="bc-hovercard-meta">${fmtRange(s, e, occ.allDay)}${duration ? ` (${duration.exact} total)` : ''}${cal && html` · <span class="bc-hovercard-cal"><i style=${`background:${cal.color}`}></i>${cal.name}</span>`}</div>
    ${place && html`<div class="bc-hovercard-meta">${place}</div>`}
    ${reasons.length > 0 && html`<ul class="bc-hovercard-why">${reasons.map((r) => html`<li key=${r}>${r}</li>`)}</ul>`}
  </div>`;
}

function TextCard({ lines, at }) {
  return html`<div class="bc-hovercard" role="tooltip" style=${`left:${at.left}px;top:${at.top}px`}>
    ${lines.map((l, i) => html`<div key=${i} class=${i === 0 ? 'bc-hovercard-line' : 'bc-hovercard-line is-next'}>${l}</div>`)}
  </div>`;
}

/** Mount once; returns a function that stops it (tests, hot paths). */
export function startHoverCards(doc = document) {
  if (!doc.defaultView || !doc.defaultView.matchMedia('(hover: hover) and (pointer: fine)').matches) return () => {};
  const host = doc.createElement('div');
  doc.body.appendChild(host);
  let timer = null;
  let current = null;

  const hide = () => {
    if (timer) { clearTimeout(timer); timer = null; }
    if (current) { current = null; render(null, host); }
  };
  const show = (el) => {
    if (!el.isConnected) return;
    const text = el.dataset.hovertext;
    const occ = text ? null : state.occ.get(el.dataset.instance || el.dataset.ctxInstance);
    if (!text && !occ) return;
    const r = el.getBoundingClientRect();
    const view = doc.defaultView;
    // Below the event, or above it near the bottom edge; kept on screen.
    const left = Math.max(8, Math.min(r.left, view.innerWidth - 328));
    const below = r.bottom + 6;
    const top = below + 120 > view.innerHeight ? Math.max(8, r.top - 6 - 120) : below;
    current = el;
    if (text) {
      render(html`<${TextCard} lines=${text.split('\n')} at=${{ left, top }} />`, host);
      return;
    }
    const cal = state.calendars.find((c) => c.id === occ.calendarId) || null;
    render(html`<${Card} occ=${occ} cal=${cal} at=${{ left, top }} nowMs=${Date.now()} />`, host);
  };

  const onOver = (e) => {
    if (e.pointerType && e.pointerType !== 'mouse') return;
    const el = e.target && e.target.closest ? e.target.closest(TARGETS) : null;
    if (!el || el === current) return;
    hide();
    timer = setTimeout(() => { timer = null; show(el); }, HOVER_DELAY_MS);
  };
  const onOut = (e) => {
    const el = e.target && e.target.closest ? e.target.closest(TARGETS) : null;
    if (!el) return;
    const to = e.relatedTarget;
    if (to && el.contains(to)) return; // still inside the same event
    hide();
  };
  const opts = { capture: true, passive: true };
  doc.addEventListener('pointerover', onOver, opts);
  doc.addEventListener('pointerout', onOut, opts);
  for (const t of ['pointerdown', 'wheel', 'scroll', 'keydown']) doc.addEventListener(t, hide, opts);
  return () => {
    hide();
    doc.removeEventListener('pointerover', onOver, opts);
    doc.removeEventListener('pointerout', onOut, opts);
    for (const t of ['pointerdown', 'wheel', 'scroll', 'keydown']) doc.removeEventListener(t, hide, opts);
    host.remove();
  };
}
