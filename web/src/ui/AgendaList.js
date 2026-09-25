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

import { html, useState, useRef, useMemo, useEffect, useLayoutEffect, useCallback } from '../../vendor/index.js';
import {
  parseISO, fmtTime, dateOfDayKey, fmtDayLong, occDayKey, todayKey,
  timeState, addDaysKey, fmtDateShort, dayKeyOf, epochDayOfKey,
} from '../lib/dates.js';
import { EventChip, HiddenMark } from './EventChip.js';
import { ContextStrip } from './ContextStrip.js';
import { contextByDay, withoutContext } from '../lib/context.js';
import { ThumbIcon, TripBadge, PinIcon, Icon } from './icons.js';
import { gmapsUrl } from '../lib/maps.js';
import {
  buildAgendaGroups, railRanges, washRects, dayOfSpanLabel,
  AGENDA_ROW_H as ROW_H, AGENDA_HEAD_H as HEAD_H,
} from './agendarails.js';
import { withAlpha } from '../lib/color.js';

// One compact segmented group per feed row: triage (interested / going /
// hide) plus, past a thin divider, thumbs feedback for the trainable
// ranking (PRD 5.8). Quiet by design: revealed on row hover/focus on
// desktop (space is reserved so rows never shift), always visible on touch,
// and kept visible when a triage state is active.
const TRIAGE_BUTTONS = [
  ['maybe', 'star', 'Maybe'],
  ['planned', 'check', 'Planned'],
  ['hidden', 'close', 'Hide'],
];
const FEEDBACK_BUTTONS = [
  ['up', 'More like this'],
  ['down', 'Less like this'],
];

function TriageCluster({ occ, onSetAttendance, onFeedback }) {
  const hasOn = TRIAGE_BUTTONS.some(([value]) => occ.relationship === value);
  return html`<span
    class="bc-triage bc-agenda-triage${hasOn ? ' has-on' : ''}"
    role="group" aria-label="Triage and feedback"
  >
    ${TRIAGE_BUTTONS.map(([value, iconName, label]) => html`<button
      key=${value} type="button"
      class="bc-triage-btn${occ.relationship === value ? ' is-on' : ''}"
      title=${label} aria-label=${label} aria-pressed=${occ.relationship === value}
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

// A folded run's dates: "Thu, Oct 22", with the weekday so the skipped days
// read as days of the week, not only numbers.
const gapDayFmt = new Intl.DateTimeFormat(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
// A stretch not loaded can run for years, so it names them.
const gapYearFmt = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
function gapRange(g) {
  const fmt = g.unloaded ? gapYearFmt : gapDayFmt;
  const a = fmt.format(dateOfDayKey(g.dayKey));
  return g.gapTo === g.dayKey ? a : a + ' to ' + fmt.format(dateOfDayKey(g.gapTo));
}

function calColorOf(calendars, occ) {
  return (calendars[occ.calendarId] && calendars[occ.calendarId].color) || '#888';
}

// props: sortMode 'time' (default: day-grouped) | 'match' (flat, caller-ordered
// by score); onFeedback(occ, 'up'|'down') optional thumbs intent;
// scrollKey/scrollSeq: anchor day key + a monotonically bumped sequence — each
// new seq scrolls the list to the anchor's day group (nearest following group
// when the exact day has no events).
// gaps: every day appears, runs of empty days folded to one line each (the
// Split view); loaded: the held windows ([{start, end}] ms), so a run never
// fetched reads "not loaded yet" rather than "nothing on". apiRef: receives
// {el, groups, ...} for a caller that links another scroller to this one.
export function AgendaList({ occurrences, calendars, dimSet, nowMs, sortMode, scrollKey, scrollSeq, onOpenEvent, onSetAttendance, onFeedback, onCreateDay, onRequestWindow, onVisibleMonthChange, emptyLabel, gapFrom, hiddenDays, onShowHidden, gaps = false, loaded = null, apiRef }) {
  const scrollRef = useRef(null);
  const [win, setWin] = useState({ top: 0, height: 800 });
  const flat = sortMode === 'match';

  // A span's rail, wash and boundary rows highlight together, whichever one
  // the pointer is over. Done by toggling classes on the matching nodes, NOT
  // through state: hover on a list of this size must not re-render every
  // group (that was visible as scroll jank).
  const linkedRef = useRef([]);
  const setLinked = useCallback((instanceId) => {
    for (const el of linkedRef.current) el.classList.remove('is-linked');
    linkedRef.current = [];
    const root = scrollRef.current;
    if (!root || !instanceId) return;
    const els = root.querySelectorAll(`[data-span="${CSS.escape(instanceId)}"]`);
    for (const el of els) el.classList.add('is-linked');
    linkedRef.current = [...els];
  }, []);

  // The day's context (weather, sunset, tides) rides on its heading: the
  // first thing to read for a day, and never a row in the list.
  const ctxByDay = useMemo(() => contextByDay(occurrences), [occurrences]);
  const groups = useMemo(() => {
    if (flat) {
      // Match order: one flat section preserving the caller's ranked order.
      const rows = occurrences
        .filter((occ) => occ.attendance !== 'hidden')
        .map((occ) => ({ kind: 'normal', occ }));
      return rows.length === 0 ? [] : [{ dayKey: null, rows, top: 0, height: rows.length * ROW_H }];
    }
    if (!gaps) return buildAgendaGroups(withoutContext(occurrences));
    const heldDays = loaded
      ? loaded.map((r) => [epochDayOfKey(dayKeyOf(new Date(r.start))), epochDayOfKey(dayKeyOf(new Date(r.end - 1)))])
      : null;
    return buildAgendaGroups(withoutContext(occurrences), { gaps: true, loaded: heldDays });
  }, [occurrences, flat, gaps, loaded]);

  // Rails and header suffixes only exist in day-grouped mode; match mode
  // reorders rows, so a vertical span would connect unrelated positions.
  const rails = useMemo(
    () => (flat ? [] : railRanges(groups, { colorOf: (occ) => calColorOf(calendars, occ) })),
    [groups, flat, calendars],
  );
  const washes = useMemo(
    () => (flat ? [] : washRects(groups, { colorOf: (occ) => calColorOf(calendars, occ) })),
    [groups, flat, calendars],
  );

  const totalH = groups.length ? groups[groups.length - 1].top + groups[groups.length - 1].height : 0;

  // Applied navigation sequence; declared here because the restore effect
  // below must not fight a pending explicit navigation.
  const appliedSeqRef = useRef(null);
  // anchored: the list has landed on a navigation target at least once, so
  // its position means something (before that it sits at its very top).
  if (apiRef) {
    apiRef.current = {
      get el() { return scrollRef.current; },
      groups,
      get anchored() { return appliedSeqRef.current != null; },
      // A navigation this list has not landed on yet (its day still loading).
      get pending() { return !!scrollSeq && appliedSeqRef.current !== scrollSeq; },
      // You scrolled the list yourself: that outranks a navigation still
      // waiting for its day, which is then dropped where it stands.
      cancelPending() { appliedSeqRef.current = scrollSeq; },
    };
  }

  // Where the user is IN TIME, not in pixels: the day at the top of the
  // viewport plus how far into it they are. Toggling "show past" rebuilds the
  // group list with a year of earlier days prepended (or removed), so every
  // group's top moves by thousands of pixels — holding scrollTop across that
  // landed the reader in August 2025 one way and November 2026 the other.
  const topPosRef = useRef(null);
  const groupsRef = useRef(groups);
  groupsRef.current = groups;
  const noteTopPos = useCallback(() => {
    const el = scrollRef.current;
    const gs = groupsRef.current;
    if (!el || !gs.length || gs[0].dayKey === null) return;
    let g = gs[0];
    for (const cur of gs) { if (cur.top <= el.scrollTop) g = cur; else break; }
    topPosRef.current = { dayKey: g.dayKey, within: el.scrollTop - g.top };
  }, []);

  const onScroll = useCallback(() => {
    const el = scrollRef.current;
    if (!el) return;
    noteTopPos();
    setWin((prev) => {
      const next = { top: el.scrollTop, height: el.clientHeight };
      return Math.abs(prev.top - next.top) > 200 || prev.height !== next.height ? next : prev;
    });
  }, [noteTopPos]);

  useEffect(() => { onScroll(); }, [groups.length, onScroll]);

  // Restore that day after the group set changes. When nothing shifted, the
  // recomputed offset equals the current scrollTop, so the common case (the
  // minute tick rebuilding groups) moves nothing at all.
  useLayoutEffect(() => {
    const el = scrollRef.current;
    const pos = topPosRef.current;
    if (!el || flat || !pos || groups.length === 0) return;
    if (appliedSeqRef.current !== scrollSeq) return; // an explicit navigation owns this pass
    const g = groups.find((x) => (x.gapTo || x.dayKey) >= pos.dayKey) || groups[groups.length - 1];
    const want = Math.max(0, g.top + (g.dayKey === pos.dayKey ? pos.within : 0));
    if (Math.abs(el.scrollTop - want) > 1) el.scrollTop = want;
  }, [groups]); // eslint-disable-line

  // Anchor-aware scroll: apply each scrollSeq once, retrying as groups fill in
  // (the window load is async) but never re-yanking after it has applied.
  // With gaps (Split) the list covers every loaded day, so it lands exactly
  // when the target day's window has arrived, and not before: landing on the
  // nearest loaded day instead would leave the list, and the weeks that
  // follow it, somewhere else entirely. No timer: if the load fails, the
  // failure note shows and the retry that follows brings the day in.
  useEffect(() => {
    if (flat || !scrollSeq || !scrollKey) return;
    if (appliedSeqRef.current === scrollSeq) return;
    const el = scrollRef.current;
    if (!el || groups.length === 0) return;
    const holds = (g) => !g.unloaded && g.dayKey <= scrollKey && scrollKey <= (g.gapTo || g.dayKey);
    if (gaps && !groups.some(holds)) return;
    // A day inside a folded run lands on its own place along that run's line,
    // so the day read off the list (and outlined in Split's weeks) is that
    // day, not the run's first.
    const target = groups.find((g) => (g.gapTo || g.dayKey) >= scrollKey) || groups[groups.length - 1];
    let into = 0;
    if (target.gap && target.gapTo !== target.dayKey && scrollKey > target.dayKey) {
      const days = epochDayOfKey(target.gapTo) - epochDayOfKey(target.dayKey) + 1;
      into = Math.round(((epochDayOfKey(scrollKey) - epochDayOfKey(target.dayKey)) / days) * target.height);
    }
    el.scrollTop = target.top + into;
    appliedSeqRef.current = scrollSeq;
    onScroll();
  }, [scrollSeq, scrollKey, groups, flat, onScroll, gaps]);

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

  // The list starts at the first day that HAS events, which silently skips
  // empty days at the front — opening on "Saturday, August 8" when today is
  // the 5th reads at a glance like the 5th through 7th are missing rather
  // than empty. Name the gap so the jump is obviously deliberate.
  const leadGap = (() => {
    if (flat || !gapFrom || groups.length === 0) return null;
    const first = groups[0].dayKey;
    if (!first || first <= gapFrom) return null;
    const lastEmpty = addDaysKey(first, -1);
    const f = (k) => fmtDateShort(dateOfDayKey(k));
    return gapFrom === lastEmpty
      ? `No events ${f(gapFrom)}`
      : `No events ${f(gapFrom)} – ${f(lastEmpty)}`;
  })();

  return html`<div class="bc-agenda" ref=${scrollRef} onScroll=${onScroll}>
    ${groups.length === 0 && html`<div class="bc-empty bc-agenda-empty">${emptyLabel || 'No events in this range'}</div>`}
    ${leadGap && html`<div class="bc-agenda-leadgap">${leadGap}</div>`}
    <div class="bc-agenda-spacer" style=${`height:${totalH}px`}>
      ${washes.map((r) => {
        if (r.topPx > visEnd || r.topPx + r.heightPx < visStart) return null;
        if (dimSet && dimSet.has(r.instanceId)) return null;
        // Fades out to the right so a wide window does not read as a heavy
        // block of colour; overlapping bands blend where they cross.
        const strong = withAlpha(r.color, 0.16);
        const mid = withAlpha(r.color, 0.05);
        return html`<div
          key=${'wash:' + r.instanceId}
          class="bc-agenda-wash"
          data-span=${r.instanceId}
          style=${`top:${r.topPx}px;height:${r.heightPx}px;`
            + `background:linear-gradient(90deg, ${strong} 0%, ${mid} 55%, transparent 100%)`}
          aria-hidden="true"
        ></div>`;
      })}
      ${rails.map((r) => {
        if (r.topPx > visEnd || r.topPx + r.heightPx < visStart) return null;
        // Type-to-filter: a filtered-out trip's boundary rows hide via their
        // chips, so its rail must not linger either.
        if (dimSet && dimSet.has(r.instanceId)) return null;
        return html`<div
          key=${'rail:' + r.instanceId}
          class="bc-agenda-rail${r.isTrip ? ' is-trip' : ''}"
          data-span=${r.instanceId}
          style=${`top:${r.topPx}px;height:${r.heightPx}px;left:${-14 - r.lane * 6}px`}
          aria-hidden="true" title=${r.title}
          onPointerEnter=${() => setLinked(r.instanceId)}
          onPointerLeave=${() => setLinked(null)}
          onClick=${openDetail(r.instanceId)}
        ><span class="bc-agenda-rail-line" style=${`background:${r.color}`}></span></div>`;
      })}
      ${groups.map((g) => {
        const visible = g.top + g.height >= visStart && g.top <= visEnd;
        return html`<section
          key=${g.dayKey === null ? 'match' : g.dayKey}
          class="bc-agenda-group${g.dayKey === tKey ? ' is-today' : ''}${g.gap ? ' is-gap' : ''}"
          style=${`top:${g.top}px;height:${g.height}px`}
        >
          ${g.monthStart && html`<div class="bc-agenda-monthsep"><span>${monthLabelOf(g.dayKey)}</span></div>`}
          ${visible && g.gap && html`<p class="bc-agenda-gap${g.covered ? ' is-covered' : ''}${g.unloaded ? ' is-unloaded' : ''}">
            ${!g.unloaded && html`<${Icon} name="gapDays" size=${14} />`}
            <span class="bc-agenda-gap-when">${gapRange(g)}</span>
            <span class="bc-agenda-gap-what">${g.unloaded ? 'not loaded yet' : g.covered ? 'nothing else on' : 'nothing on'}</span>
          </p>`}
          ${g.dayKey !== null && !g.gap && html`<h3 class="bc-agenda-day">
            ${fmtDayLong(dateOfDayKey(g.dayKey))}
            ${hiddenDays && hiddenDays.get(g.dayKey) && html`<${HiddenMark} count=${hiddenDays.get(g.dayKey)} onShow=${onShowHidden} />`}
            ${ctxByDay.get(g.dayKey) && html`<${ContextStrip} occs=${ctxByDay.get(g.dayKey)} calendars=${calendars} onOpen=${onOpenEvent} />`}
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
            // A timed span says WHICH end of itself each row is: "from" on the
            // start day, "until" on the last. Bare times read as if the event
            // ran those hours on both days, which is not what a span means.
            const spanTime = span && !occ.allDay
              ? (start ? 'from ' : 'until ') + fmtTime(parseISO(start ? occ.start : occ.end))
              : null;
            const gutter = flat
              ? fmtDayShort(occ) + ' · ' + (occ.allDay ? 'all day' : fmtTime(parseISO(occ.start)))
              : span ? dayOfSpanLabel(occ, g.dayKey) + (spanTime ? ' · ' + spanTime : '')
              : occ.allDay ? 'all day'
              : fmtTime(parseISO(occ.start)) + (occ.end ? ' to ' + fmtTime(parseISO(occ.end)) : '');
            const color = calColorOf(calendars, occ);
            return html`<div
              key=${row.kind + ':' + occ.instanceId}
              class="bc-agenda-row${ts === 'past' ? ' is-past' : ts === 'now' ? ' is-now' : ''}${trip ? ' is-trip' : ''}"
              data-span=${span ? occ.instanceId : undefined}
              style=${`height:${ROW_H}px`}
              onPointerEnter=${span ? () => setLinked(occ.instanceId) : undefined}
              onPointerLeave=${span ? () => setLinked(null) : undefined}
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
            ${occ.location && html`<${AgendaLocation} location=${occ.location} lat=${occ.locationLat} lng=${occ.locationLng} />`}
          </div>`;
          })}
        </section>`;
      })}
    </div>
  </div>`;
}
