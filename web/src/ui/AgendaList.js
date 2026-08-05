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
import { ThumbIcon, TripBadge, PinIcon, Icon } from './icons.js';
import { gmapsUrl } from '../lib/maps.js';
import {
  buildAgendaGroups, railRanges, coveringByDay, dayOfSpanLabel,
  AGENDA_ROW_H as ROW_H, AGENDA_HEAD_H as HEAD_H,
} from './agendarails.js';
import { withAlpha } from '../lib/color.js';

// One compact segmented group per feed row: triage (interested / going /
// hide) plus, past a thin divider, thumbs feedback for the trainable
// ranking (PRD 5.8). Quiet by design: revealed on row hover/focus on
// desktop (space is reserved so rows never shift), always visible on touch,
// and kept visible when a triage state is active.
const TRIAGE_BUTTONS = [
  ['interested', 'star', 'Interested'],
  ['going', 'check', 'Going'],
  ['hidden', 'close', 'Hide'],
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
    ${TRIAGE_BUTTONS.map(([value, iconName, label]) => html`<button
      key=${value} type="button"
      class="bc-triage-btn${occ.attendance === value ? ' is-on' : ''}"
      title=${label} aria-label=${label} aria-pressed=${occ.attendance === value}
      onClick=${(e) => { e.stopPropagation(); onSetAttendance(occ, value); }}
    ><${Icon} name=${iconName} size=${12} /></button>`)}
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

// A location that is really a link (Zoom, Meet, any URL) becomes one; a real
// place gets a maps link. Either way it is clickable from the agenda instead
// of a string you have to copy out by hand.
function AgendaLocation({ location, lat, lng }) {
  const url = /^https?:\/\//i.test(location.trim()) ? location.trim() : null;
  const stop = (e) => e.stopPropagation();
  if (url) {
    let label = url;
    try {
      const u = new URL(url);
      label = u.hostname.replace(/^www\./, '') + (u.pathname !== '/' ? u.pathname : '');
    } catch { /* keep the raw string */ }
    return html`<a
      class="bc-agenda-loc bc-agenda-loclink" href=${url}
      target="_blank" rel="noopener noreferrer" title=${url} onClick=${stop}
    ><${Icon} name="video" size=${11} />${label}</a>`;
  }
  return html`<a
    class="bc-agenda-loc bc-agenda-loclink" href=${gmapsUrl(location, lat, lng)}
    target="_blank" rel="noopener noreferrer" title=${'Open in Google Maps: ' + location} onClick=${stop}
  ><${PinIcon} size=${11} />${location}</a>`;
}

// Month boundaries in the scroll: "August 2026" as a full-width rule.
function monthLabelOf(dayKey) {
  return dateOfDayKey(dayKey).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
}

function calColorOf(calendars, occ) {
  return (calendars[occ.calendarId] && calendars[occ.calendarId].color) || '#888';
}

// props: sortMode 'time' (default: day-grouped) | 'match' (flat, caller-ordered
// by score); onFeedback(occ, 'up'|'down') optional thumbs intent;
// scrollKey/scrollSeq: anchor day key + a monotonically bumped sequence — each
// new seq scrolls the list to the anchor's day group (nearest following group
// when the exact day has no events).
export function AgendaList({ occurrences, calendars, dimSet, nowMs, sortMode, scrollKey, scrollSeq, onOpenEvent, onSetAttendance, onFeedback, onCreateDay, onRequestWindow, onVisibleMonthChange, emptyLabel }) {
  const scrollRef = useRef(null);
  const [win, setWin] = useState({ top: 0, height: 800 });
  // A span's rail and its boundary rows highlight together, whichever one the
  // pointer is over: with several spans running at once there is otherwise no
  // way to tell which bar belongs to which event.
  const [hoverId, setHoverId] = useState(null);
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
  const covering = useMemo(() => (flat ? new Map() : coveringByDay(groups)), [groups, flat]);

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

  // The month at the top of the viewport: drives the sticky header (the only
  // place the YEAR appears in this view) and is reported upward so the
  // mini-month follows an agenda scroll like it does every other view.
  const topMonth = useMemo(() => {
    if (flat || groups.length === 0) return null;
    let current = groups[0];
    for (const g of groups) {
      if (g.top <= win.top + 8) current = g; else break;
    }
    return current.dayKey || null;
  }, [groups, win.top, flat]);

  useEffect(() => {
    if (!topMonth || !onVisibleMonthChange) return;
    const [y, m] = topMonth.split('-').map(Number);
    onVisibleMonthChange({ year: y, month: m });
  }, [topMonth && topMonth.slice(0, 7)]); // eslint-disable-line

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
          class="bc-agenda-rail${r.isTrip ? ' is-trip' : ''}${hoverId === r.instanceId ? ' is-linked' : ''}"
          style=${`top:${r.topPx}px;height:${r.heightPx}px;left:${-14 - r.lane * 6}px`}
          aria-hidden="true" title=${r.title}
          onPointerEnter=${() => setHoverId(r.instanceId)}
          onPointerLeave=${() => setHoverId(null)}
          onClick=${openDetail(r.instanceId)}
        ><span class="bc-agenda-rail-line" style=${`background:${r.color}`}></span></div>`;
      })}
      ${groups.map((g) => {
        const visible = g.top + g.height >= visStart && g.top <= visEnd;
        // One low-opacity wash per span covering this day, layered so
        // overlapping spans blend into a combined tint. A filtered-out span
        // must not keep tinting the days it crossed.
        const spans = (g.dayKey !== null ? covering.get(g.dayKey) : null) || [];
        const washes = spans
          .filter((occ) => !(dimSet && dimSet.has(occ.instanceId)))
          .map((occ) => {
            const c = withAlpha(calColorOf(calendars, occ), hoverId === occ.instanceId ? 0.2 : 0.09);
            return `linear-gradient(${c}, ${c})`;
          });
        return html`<section
          key=${g.dayKey === null ? 'match' : g.dayKey}
          class="bc-agenda-group${g.dayKey === tKey ? ' is-today' : ''}${washes.length ? ' is-spanned' : ''}"
          style=${`top:${g.top}px;height:${g.height}px`
            + (washes.length ? `;background-image:${washes.join(',')}` : '')}
        >
          ${g.monthStart && html`<div class="bc-agenda-monthsep"><span>${monthLabelOf(g.dayKey)}</span></div>`}
          ${g.dayKey !== null && html`<h3 class="bc-agenda-day">
            ${fmtDayLong(dateOfDayKey(g.dayKey))}
            ${onCreateDay && html`<button
              type="button" class="bc-agenda-dayadd"
              title=${'New event on ' + fmtDayLong(dateOfDayKey(g.dayKey))}
              aria-label=${'New event on ' + fmtDayLong(dateOfDayKey(g.dayKey))}
              onClick=${() => onCreateDay(g.dayKey)}
            >+</button>`}
          </h3>`}
          ${visible && g.rows.map((row) => {
            const occ = row.occ;
            // Row-level time state so the time and location columns dim with
            // the chip; the chip carries the same class itself via nowMs.
            const ts = nowMs ? timeState(occ, nowMs) : null;
            const trip = !!occ.isContainer;
            const start = row.kind === 'start';
            const end = row.kind === 'end';
            const span = (start || end) && !flat;
            // ONE column layout for every row: the gutter is always a label
            // and the chip always sits in the body beside every other event.
            // Multi-day boundaries put "Day i/N" in the gutter (where all-day
            // rows say "all day"), tinted with the calendar colour so the
            // label reads as part of the rail running down the left edge.
            // A timed span keeps its clock time beside the day counter: the
            // start row shows when it begins, the end row when it finishes.
            const spanTime = span && !occ.allDay
              ? fmtTime(parseISO(start ? occ.start : occ.end))
              : null;
            const gutter = flat
              ? fmtDayShort(occ) + ' · ' + (occ.allDay ? 'all day' : fmtTime(parseISO(occ.start)))
              : span ? dayOfSpanLabel(occ, g.dayKey) + (spanTime ? ' · ' + spanTime : '')
              : occ.allDay ? 'all day'
              : fmtTime(parseISO(occ.start)) + (occ.end ? ' to ' + fmtTime(parseISO(occ.end)) : '');
            const color = calColorOf(calendars, occ);
            const linked = hoverId === occ.instanceId;
            return html`<div
              key=${row.kind + ':' + occ.instanceId}
              class="bc-agenda-row${ts === 'past' ? ' is-past' : ts === 'now' ? ' is-now' : ''}${trip ? ' is-trip' : ''}${linked ? ' is-linked' : ''}"
              style=${`height:${ROW_H}px`}
              onPointerEnter=${span ? () => setHoverId(occ.instanceId) : undefined}
              onPointerLeave=${span ? () => setHoverId(null) : undefined}
            >
            ${trip && !start && !end && html`<span
              class="bc-agenda-tripedge" aria-hidden="true"
              style=${`background:${color}`}
            ></span>`}
            <span
              class="bc-agenda-time${span ? ' is-span' : ''}"
              style=${span ? `--span-color:${color}` : undefined}
            >${gutter}</span>
            <${EventChip}
              occ=${occ} cal=${calendars[occ.calendarId]} showTime=${false}
              dimmed=${dimSet && dimSet.has(occ.instanceId)} nowMs=${nowMs}
              onOpen=${onOpenEvent}
            />
            ${trip && html`<${TripBadge} />`}
            ${!end && occ.source === 'feed' && onSetAttendance && html`<${TriageCluster} occ=${occ} onSetAttendance=${onSetAttendance} onFeedback=${onFeedback} />`}
            ${!end && occ.location && html`<${AgendaLocation} location=${occ.location} lat=${occ.locationLat} lng=${occ.locationLng} />`}
          </div>`;
          })}
        </section>`;
      })}
    </div>
  </div>`;
}
