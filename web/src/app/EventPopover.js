// EventPopover: anchored details popover (bottom sheet on mobile).
// Prefers the right side of the anchor, flips left on viewport overflow,
// clamps with a gap. Inline title/time editing; edit / delete / attendance.
// Mobile sheet puts what/when/where above the fold.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { useStore, set, state } from './store.js';
import { updateEvent, deleteEvent, triageAttendance } from './actions.js';
import { isMobile, trapFocus, MOBILE_QUERY } from '../ui/DayExpand.js';
import {
  parseISO, fmtRange, toInputValue, fromInputValue, toISOWithOffset,
} from '../lib/dates.js';

const GAP = 10;
const WIDTH = 320;

function place(anchorRect) {
  const h = 300; // estimate; clamped below anyway
  let left = anchorRect.right + GAP;
  if (left + WIDTH > window.innerWidth - GAP) left = anchorRect.left - GAP - WIDTH; // flip left
  left = Math.max(GAP, Math.min(left, window.innerWidth - GAP - WIDTH));
  let top = Math.max(GAP, Math.min(anchorRect.top, window.innerHeight - GAP - h));
  return { left, top };
}

export function EventPopover() {
  const popover = useStore((s) => s.popover);
  const occ = popover ? state.occ.get(popover.instanceId) : null;
  const panelRef = useRef(null);
  const [editingTitle, setEditingTitle] = useState(false);
  const [editingTime, setEditingTime] = useState(false);
  const [title, setTitle] = useState('');

  useEffect(() => {
    setEditingTitle(false);
    setEditingTime(false);
    setTitle(occ ? occ.title : '');
  }, [popover && popover.instanceId]);

  // Document-level listeners; torn down on unmount, not just close.
  useEffect(() => {
    const onDoc = (e) => {
      if (panelRef.current && !panelRef.current.contains(e.target)) set({ popover: null });
    };
    const onKey = (e) => {
      if (e.key === 'Tab') trapFocus(panelRef.current, e);
    };
    if (popover) {
      document.addEventListener('pointerdown', onDoc, true);
      document.addEventListener('keydown', onKey, true);
    }
    return () => {
      document.removeEventListener('pointerdown', onDoc, true);
      document.removeEventListener('keydown', onKey, true);
    };
  }, [popover]);

  // Anchored panel and bottom sheet position with different rules; crossing
  // the breakpoint would leave a stale layout, so close instead.
  useEffect(() => {
    if (!popover) return undefined;
    const mq = window.matchMedia(MOBILE_QUERY);
    const onChange = () => set({ popover: null });
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, [popover]);

  if (!popover || !occ) return null;

  const cal = state.calendars.find((c) => c.id === occ.calendarId);
  const isFeed = cal && cal.kind === 'subscribed';
  const mobile = isMobile();
  const pos = !mobile && popover.anchorRect ? place(popover.anchorRect) : null;

  const saveTitle = async () => {
    setEditingTitle(false);
    if (title !== occ.title) await updateEvent(occ, { title });
  };

  const saveTime = async (startVal, endVal) => {
    const s = fromInputValue(startVal);
    const e = fromInputValue(endVal);
    if (isNaN(s) || isNaN(e) || e <= s) return;
    setEditingTime(false);
    await updateEvent(occ, { start: toISOWithOffset(s), end: toISOWithOffset(e) });
  };

  const s = parseISO(occ.start);
  const e = parseISO(occ.end);

  return html`<div
    class="bc-popover${mobile ? ' bc-sheet' : ''}"
    style=${pos ? `left:${pos.left}px;top:${pos.top}px;width:${WIDTH}px` : ''}
    ref=${panelRef}
    role="dialog"
    aria-modal="true"
    aria-label="Event details"
  >
    <div class="bc-pop-color" style=${`background:${(cal && cal.color) || '#888'}`}></div>
    <div class="bc-pop-body">
      ${editingTitle
        ? html`<input
            class="bc-pop-title-input" value=${title} autofocus
            onInput=${(ev) => setTitle(ev.target.value)}
            onKeyDown=${(ev) => { if (ev.key === 'Enter') saveTitle(); if (ev.key === 'Escape') { ev.stopPropagation(); setEditingTitle(false); setTitle(occ.title); } }}
            onBlur=${saveTitle}
            aria-label="Event title"
          />`
        : html`<h2 class="bc-pop-title${occ.status === 'cancelled' ? ' is-cancelled' : ''}" onClick=${() => !isFeed && setEditingTitle(true)} title=${isFeed ? '' : 'Click to edit title'}>
            ${occ.title || '(untitled)'}${occ.isNew ? html` <span class="bc-new-pill">new</span>` : ''}
          </h2>`}
      ${editingTime
        ? html`<${TimeEditor} start=${s} end=${e} onSave=${saveTime} onCancel=${() => setEditingTime(false)} />`
        : html`<div class="bc-pop-when" onClick=${() => !isFeed && setEditingTime(true)} title=${isFeed ? '' : 'Click to edit time'}>
            ${fmtRange(s, e, occ.allDay)}${occ.recurring ? ' · repeats' : ''}
          </div>`}
      ${occ.location && html`<div class="bc-pop-where">${occ.location}</div>`}
      <div class="bc-pop-cal">
        <span class="bc-cal-dot" style=${`background:${(cal && cal.color) || '#888'}`}></span>
        ${(cal && cal.name) || 'Calendar'}
        ${occ.tags && occ.tags.length > 0 && html`<span class="bc-pop-tags">${occ.tags.map((t) => '#' + t).join(' ')}</span>`}
      </div>
      ${occ.description && html`<div class="bc-pop-desc">${occ.description.length > 280 ? occ.description.slice(0, 280) + '…' : occ.description}</div>`}
      ${occ.url && html`<a class="bc-pop-url" href=${occ.url} target="_blank" rel="noopener">Event link ↗</a>`}
      <div class="bc-pop-actions">
        ${!isFeed && html`<button type="button" class="bc-btn" onClick=${() => set({ popover: null, editor: { mode: 'edit', occ } })}>Edit</button>`}
        ${!isFeed && html`<button type="button" class="bc-btn bc-btn-danger" onClick=${() => deleteEvent(occ)}>Delete</button>`}
        ${isFeed && html`<div class="bc-seg" role="group" aria-label="Attendance">
          ${[['interested', 'Interested'], ['going', 'Going'], ['hidden', 'Hide']].map(([value, label]) => html`<button
            key=${value} type="button"
            class="bc-seg-btn${occ.attendance === value ? ' is-active' : ''}"
            aria-pressed=${occ.attendance === value}
            onClick=${async () => {
              const result = await triageAttendance(occ, value);
              if (result === 'hidden') set({ popover: null });
            }}
          >${label}</button>`)}
        </div>`}
        <button type="button" class="bc-icon-btn bc-pop-close" aria-label="Close" onClick=${() => set({ popover: null })}>✕</button>
      </div>
    </div>
  </div>`;
}

function TimeEditor({ start, end, onSave, onCancel }) {
  const [sv, setSv] = useState(toInputValue(start));
  const [ev, setEv] = useState(toInputValue(end));
  return html`<div class="bc-pop-timeedit">
    <input type="datetime-local" value=${sv} onInput=${(x) => setSv(x.target.value)} aria-label="Start" />
    <span>to</span>
    <input type="datetime-local" value=${ev} onInput=${(x) => setEv(x.target.value)} aria-label="End" />
    <button type="button" class="bc-btn bc-btn-primary" onClick=${() => onSave(sv, ev)}>Save</button>
    <button type="button" class="bc-btn" onClick=${onCancel}>Cancel</button>
  </div>`;
}
