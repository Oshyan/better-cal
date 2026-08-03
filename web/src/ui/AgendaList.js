// AgendaList: day-grouped agenda with windowed rendering.
// Windowing is by day group: only groups intersecting the scroll viewport
// (plus buffer) render their rows; others render fixed-height placeholders,
// which is enough for a few thousand items.
//
// Multi-day treatment (rail concept): buildAgendaGroups synthesizes groups
// for every multi-day occurrence's start and end day, railRanges yields one
// continuous colored bar per span running from the start row's center to the
// end row's center, with its right edge tucked 2px under the flush-left
// boundary pills so bar and pills physically connect (overlapping spans
// shift further left by lane and give up pill contact), and covered day
// headers gain quiet colored title suffixes. All the math lives in agendarails.js; this file
// only renders it. Match-sort flat mode keeps the historical
// single-row-per-occurrence behavior (no rails, no markers).

import { html, useState, useRef, useMemo, useEffect, useCallback } from '../../vendor/index.js';
import {
  parseISO, fmtTime, dateOfDayKey, fmtDayLong, occDayKey, todayKey,
  timeState,
} from '../lib/dates.js';
import { EventChip } from './EventChip.js';
import { ThumbIcon, TripBadge, PinIcon } from './icons.js';
import {
  buildAgendaGroups, railRanges, headerSuffixes, dayOfSpanLabel,
  AGENDA_ROW_H as ROW_H, AGENDA_HEAD_H as HEAD_H,
} from './agendarails.js';

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

function calColorOf(calendars, occ) {
  return (calendars[occ.calendarId] && calendars[occ.calendarId].color) || '#888';
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
      const rows = occurrences
        .filter((occ) => occ.attendance !== 'hidden')
        .map((occ) => ({ kind: 'normal', occ }));
      return rows.length === 0 ? [] : [{ dayKey: null, rows, top: 0, height: rows.length * ROW_H }];
    }
    return buildAgendaGroups(occurrences);
  }, [occurrences, flat]);

  // Rails and header suffixes only exist in day-grouped mode; match mode
  // reorders rows, so a vertical span would connect unrelated positions.
  const rails = useMemo(
    () => (flat ? [] : railRanges(groups, { colorOf: (occ) => calColorOf(calendars, occ) })),
    [groups, flat, calendars],
  );
  const suffixes = useMemo(() => (flat ? new Map() : headerSuffixes(groups)), [groups, flat]);

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

  const openDetail = useCallback((instanceId) => (e) => {
    e.stopPropagation();
    if (onOpenEvent) onOpenEvent(instanceId, e.currentTarget.getBoundingClientRect(), { detail: true });
  }, [onOpenEvent]);

  const tKey = todayKey();
  const buffer = 600;
  const visStart = win.top - buffer;
  const visEnd = win.top + win.height + buffer;

  return html`<div class="bc-agenda" ref=${scrollRef} onScroll=${onScroll}>
    ${groups.length === 0 && html`<div class="bc-empty bc-agenda-empty">${emptyLabel || 'No events in this range'}</div>`}
    <div class="bc-agenda-spacer" style=${`height:${totalH}px`}>
      ${rails.map((r) => {
        if (r.topPx > visEnd || r.topPx + r.heightPx < visStart) return null;
        // Type-to-filter: a filtered-out trip's boundary rows hide via their
        // chips, so its rail must not linger either.
        if (dimSet && dimSet.has(r.instanceId)) return null;
        return html`<div
          key=${'rail:' + r.instanceId}
          class="bc-agenda-rail${r.isTrip ? ' is-trip' : ''}"
          style=${`top:${r.topPx}px;height:${r.heightPx}px;left:${-14 - r.lane * 6}px`}
          aria-hidden="true" title=${r.title}
          onClick=${openDetail(r.instanceId)}
        ><span class="bc-agenda-rail-line" style=${`background:${r.color}`}></span></div>`;
      })}
      ${groups.map((g) => {
        const visible = g.top + g.height >= visStart && g.top <= visEnd;
        const sfx = g.dayKey !== null ? suffixes.get(g.dayKey) : undefined;
        return html`<section
          key=${g.dayKey === null ? 'match' : g.dayKey}
          class="bc-agenda-group${g.dayKey === tKey ? ' is-today' : ''}"
          style=${`top:${g.top}px;height:${g.height}px`}
        >
          ${g.dayKey !== null && html`<h3 class="bc-agenda-day">
            ${fmtDayLong(dateOfDayKey(g.dayKey))}
            ${sfx && sfx.items.filter((occ) => !(dimSet && dimSet.has(occ.instanceId))).map((occ) => html`<button
              key=${'sfx:' + occ.instanceId} type="button" class="bc-agenda-daysfx"
              style=${`color:${calColorOf(calendars, occ)}`}
              title=${occ.title || '(untitled)'}
              onClick=${openDetail(occ.instanceId)}
            > · ${occ.title || '(untitled)'}</button>`)}
            ${sfx && sfx.more > 0 && html`<span class="bc-agenda-daysfx-more"> · +${sfx.more} more</span>`}
          </h3>`}
          ${visible && g.rows.map((row) => {
            const occ = row.occ;
            // Row-level time state so the time and location columns dim with
            // the chip; the chip carries the same class itself via nowMs.
            const ts = nowMs ? timeState(occ, nowMs) : null;
            const trip = !!occ.isContainer;
            const start = row.kind === 'start';
            const end = row.kind === 'end';
            // Boundary rows of multi-day occurrences lead with the title chip
            // so both ends read the same: [chip][Day i/N][Trip badge]. All-day
            // starts and every end marker put the chip flush left in the
            // gutter column, where the rail bar meets the pill (position + no
            // time implies all-day; the label is omitted). Timed multi-day
            // starts keep the start time in the gutter with the chip in the
            // body.
            const gutterChip = (end || (start && occ.allDay)) && !flat;
            const gutter = flat
              ? fmtDayShort(occ) + ' · ' + (occ.allDay ? 'all day' : fmtTime(parseISO(occ.start)))
              : occ.allDay ? 'all day'
              : fmtTime(parseISO(occ.start)) + (occ.end && !start ? ' to ' + fmtTime(parseISO(occ.end)) : '');
            const chip = html`<${EventChip}
              occ=${occ} cal=${calendars[occ.calendarId]} showTime=${false}
              dimmed=${dimSet && dimSet.has(occ.instanceId)} nowMs=${nowMs}
              onOpen=${onOpenEvent}
            />`;
            return html`<div
              key=${row.kind + ':' + occ.instanceId}
              class="bc-agenda-row${ts === 'past' ? ' is-past' : ts === 'now' ? ' is-now' : ''}${trip ? ' is-trip' : ''}"
              style=${`height:${ROW_H}px`}
            >
            ${trip && !start && !end && html`<span
              class="bc-agenda-tripedge" aria-hidden="true"
              style=${`background:${calColorOf(calendars, occ)}`}
            ></span>`}
            ${gutterChip
              ? html`<span class="bc-agenda-time bc-agenda-gutterchip">${chip}</span>`
              : html`<span class="bc-agenda-time">${gutter}</span>`}
            ${!gutterChip && chip}
            ${(start || end) && !flat && html`<span class="bc-agenda-dayn">${dayOfSpanLabel(occ, g.dayKey)}</span>`}
            ${trip && html`<${TripBadge} />`}
            ${!end && occ.source === 'feed' && onSetAttendance && html`<${TriageCluster} occ=${occ} onSetAttendance=${onSetAttendance} onFeedback=${onFeedback} />`}
            ${!end && occ.location && html`<span class="bc-agenda-loc"><${PinIcon} size=${11} />${occ.location}</span>`}
          </div>`;
          })}
        </section>`;
      })}
    </div>
  </div>`;
}
