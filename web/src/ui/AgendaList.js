// AgendaList: day-grouped agenda with windowed rendering.
// Windowing is by day group: only groups intersecting the scroll viewport
// (plus buffer) render their rows; others render fixed-height placeholders,
// which is enough for a few thousand items.

import { html, useState, useRef, useMemo, useEffect, useCallback } from '../../vendor/index.js';
import {
  parseISO, fmtTime, dateOfDayKey, fmtDayLong, dayKeyOfISO, occDayKey, todayKey,
  timeState,
} from '../lib/dates.js';
import { EventChip } from './EventChip.js';
import { ThumbIcon } from './icons.js';

const ROW_H = 36;
const HEAD_H = 40;

// One compact segmented group per feed row: triage (interested / going /
// hide) plus, past a thin divider, thumbs feedback for the trainable
// ranking (PRD 5.8). Quiet by design: revealed on row hover/focus on
// desktop (space is reserved so rows never shift), always visible on touch,
// and kept visible when a triage state is active.
const TRIAGE_BUTTONS = [
  ['interested', '☆', 'Interested'],
  ['going', '✓', 'Going'],
  ['hidden', '✕', 'Hide'],
];
const FEEDBACK_BUTTONS = [
  ['up', 'More like this'],
  ['down', 'Less like this'],
];

function TriageCluster({ occ, onSetAttendance, onFeedback }) {
  const hasOn = TRIAGE_BUTTONS.some(([value]) => occ.attendance === value);
  return html`<span
    class="bc-triage bc-agenda-triage${hasOn ? ' has-on' : ''}"
    role="group" aria-label="Triage and feedback"
  >
    ${TRIAGE_BUTTONS.map(([value, glyph, label]) => html`<button
      key=${value} type="button"
      class="bc-triage-btn${occ.attendance === value ? ' is-on' : ''}"
      title=${label} aria-label=${label} aria-pressed=${occ.attendance === value}
      onClick=${(e) => { e.stopPropagation(); onSetAttendance(occ, value); }}
    >${glyph}</button>`)}
    ${onFeedback && html`<span class="bc-triage-sep" aria-hidden="true"></span>`}
    ${onFeedback && FEEDBACK_BUTTONS.map(([value, label]) => html`<button
      key=${value} type="button" class="bc-triage-btn bc-triage-thumb"
      title=${label} aria-label=${label}
      onClick=${(e) => { e.stopPropagation(); onFeedback(occ, value); }}
    ><${ThumbIcon} dir=${value} size=${12} /></button>`)}
  </span>`;
}

function fmtDayShort(occ) {
  return dateOfDayKey(occDayKey(occ)).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

// props: sortMode 'time' (default: day-grouped) | 'match' (flat, caller-ordered
// by score); onFeedback(occ, 'up'|'down') optional thumbs intent;
// scrollKey/scrollSeq: anchor day key + a monotonically bumped sequence — each
// new seq scrolls the list to the anchor's day group (nearest following group
// when the exact day has no events).
export function AgendaList({ occurrences, calendars, dimSet, nowMs, sortMode, scrollKey, scrollSeq, onOpenEvent, onSetAttendance, onFeedback, onRequestWindow, emptyLabel }) {
  const scrollRef = useRef(null);
  const [win, setWin] = useState({ top: 0, height: 800 });
  const flat = sortMode === 'match';

  const groups = useMemo(() => {
    if (flat) {
      // Match order: one flat section preserving the caller's ranked order.
      const items = occurrences.filter((occ) => occ.attendance !== 'hidden');
      return items.length === 0 ? [] : [{ dayKey: null, items, top: 0, height: items.length * ROW_H }];
    }
    const byDay = new Map();
    for (const occ of occurrences) {
      if (occ.attendance === 'hidden') continue;
      const k = occDayKey(occ);
      let g = byDay.get(k);
      if (!g) byDay.set(k, (g = []));
      g.push(occ);
    }
    const keys = [...byDay.keys()].sort();
    let offset = 0;
    return keys.map((k) => {
      const items = byDay.get(k).sort((a, b) => {
        if (a.allDay !== b.allDay) return a.allDay ? -1 : 1;
        return a.start < b.start ? -1 : 1;
      });
      const height = HEAD_H + items.length * ROW_H;
      const g = { dayKey: k, items, top: offset, height };
      offset += height;
      return g;
    });
  }, [occurrences, flat]);

  const totalH = groups.length ? groups[groups.length - 1].top + groups[groups.length - 1].height : 0;

  const onScroll = useCallback(() => {
    const el = scrollRef.current;
    if (!el) return;
    setWin((prev) => {
      const next = { top: el.scrollTop, height: el.clientHeight };
      return Math.abs(prev.top - next.top) > 200 || prev.height !== next.height ? next : prev;
    });
  }, []);

  useEffect(() => { onScroll(); }, [groups.length, onScroll]);

  // Anchor-aware scroll: apply each scrollSeq once, retrying as groups fill in
  // (the window load is async) but never re-yanking after it has applied.
  const appliedSeqRef = useRef(null);
  useEffect(() => {
    if (flat || !scrollSeq || !scrollKey) return;
    if (appliedSeqRef.current === scrollSeq) return;
    const el = scrollRef.current;
    if (!el || groups.length === 0) return;
    const target = groups.find((g) => g.dayKey >= scrollKey) || groups[groups.length - 1];
    el.scrollTop = target.top;
    appliedSeqRef.current = scrollSeq;
    onScroll();
  }, [scrollSeq, scrollKey, groups, flat, onScroll]);

  const tKey = todayKey();
  const buffer = 600;
  const visStart = win.top - buffer;
  const visEnd = win.top + win.height + buffer;

  return html`<div class="bc-agenda" ref=${scrollRef} onScroll=${onScroll}>
    ${groups.length === 0 && html`<div class="bc-empty bc-agenda-empty">${emptyLabel || 'No events in this range'}</div>`}
    <div class="bc-agenda-spacer" style=${`height:${totalH}px`}>
      ${groups.map((g) => {
        const visible = g.top + g.height >= visStart && g.top <= visEnd;
        return html`<section
          key=${g.dayKey === null ? 'match' : g.dayKey}
          class="bc-agenda-group${g.dayKey === tKey ? ' is-today' : ''}"
          style=${`top:${g.top}px;height:${g.height}px`}
        >
          ${g.dayKey !== null && html`<h3 class="bc-agenda-day">${fmtDayLong(dateOfDayKey(g.dayKey))}</h3>`}
          ${visible && g.items.map((occ) => {
            // Row-level time state so the time and location columns dim with
            // the chip; the chip carries the same class itself via nowMs.
            const ts = nowMs ? timeState(occ, nowMs) : null;
            const trip = !!occ.isContainer;
            return html`<div
              key=${occ.instanceId}
              class="bc-agenda-row${ts === 'past' ? ' is-past' : ts === 'now' ? ' is-now' : ''}${trip ? ' is-trip' : ''}"
              style=${`height:${ROW_H}px`}
            >
            ${trip && html`<span
              class="bc-agenda-tripedge" aria-hidden="true"
              style=${`background:${(calendars[occ.calendarId] && calendars[occ.calendarId].color) || '#888'}`}
            ></span>`}
            <span class="bc-agenda-time">${flat
              ? fmtDayShort(occ) + ' · ' + (occ.allDay ? 'all day' : fmtTime(parseISO(occ.start)))
              : occ.allDay ? 'all day' : fmtTime(parseISO(occ.start)) + (occ.end ? ' to ' + fmtTime(parseISO(occ.end)) : '')}</span>
            <${EventChip}
              occ=${occ} cal=${calendars[occ.calendarId]} showTime=${false}
              dimmed=${dimSet && dimSet.has(occ.instanceId)} nowMs=${nowMs}
              onOpen=${onOpenEvent}
            />
            ${trip && html`<span class="bc-badge bc-trip-badge">Trip</span>`}
            ${occ.source === 'feed' && onSetAttendance && html`<${TriageCluster} occ=${occ} onSetAttendance=${onSetAttendance} onFeedback=${onFeedback} />`}
            ${occ.location && html`<span class="bc-agenda-loc">${occ.location}</span>`}
          </div>`;
          })}
        </section>`;
      })}
    </div>
  </div>`;
}
