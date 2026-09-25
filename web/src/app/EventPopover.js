// EventPopover: anchored details popover (bottom sheet on mobile).
// Prefers the right side of the anchor, flips left on viewport overflow,
// clamps with a gap. Inline title/time editing; edit / delete / attendance.
// Mobile sheet puts what/when/where above the fold.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { useStore, set, state } from './store.js';
import { ensureFullOccurrence } from './api.js';
import { CopyTo } from './CopyTo.js';
import { DeleteScope, ScopeChoice } from './DeleteScope.js';
import { RelationshipControl } from './Relationship.js';
import { prefetchMap } from './prefetch.js';
import { Skeleton } from './PageShell.js';
import { updateEvent, deleteEvent, setRelationship, sendFeedback, enterReschedule, openDetail, sameDayList, openTripByEventId, googleBacked, GOOGLE_NO_UNDO } from './actions.js';
import { describeRrule } from './EventDetail.js';
import { isMobile, trapFocus, MOBILE_QUERY } from '../ui/DayExpand.js';
import { ThumbIcon, PinIcon, LinkIcon, Icon } from '../ui/icons.js';
import { DurationSuffix } from '../ui/EventChip.js';
import {
  parseISO, dateOfDayKey, fmtRange, eventDuration, toInputValue, fromInputValue, toISOWithOffset, occDayKey,
  allDayFields, allDayInputs,
} from '../lib/dates.js';
import { fmtReminder } from '../lib/reminders.js';
import { ink } from '../lib/color.js';
import { stripToText, hasHtml, sanitizeHtml } from '../lib/richtext.js';
import { gmapsUrl, isPendingLocation } from '../lib/maps.js';
import { onOutsidePress, insideAny } from '../ui/outside.js';

const GAP = 10;
const WIDTH = 360;

// Anchor placement. The rect is first clipped to the viewport: multi-day
// bars and week-spanning segments routinely start off-screen, and anchoring
// to their true (negative) left threw the popover to the far edge, nowhere
// near the part the user clicked. Wide anchors (long bars) get the popover
// below their visible span rather than flipped to one side, which reads as
// arbitrary when the anchor is most of the row.
function place(anchorRect) {
  const h = 300; // estimate; clamped below anyway
  const vw = window.innerWidth;
  const left0 = Math.max(GAP, anchorRect.left);
  const right0 = Math.min(vw - GAP, anchorRect.right);
  const wide = right0 - left0 > vw * 0.4;

  let left;
  if (wide) {
    left = left0;
  } else {
    left = right0 + GAP;
    if (left + WIDTH > vw - GAP) left = left0 - GAP - WIDTH; // flip left
  }
  left = Math.max(GAP, Math.min(left, vw - GAP - WIDTH));

  let top = wide ? anchorRect.bottom + GAP : anchorRect.top;
  top = Math.max(GAP, Math.min(top, window.innerHeight - GAP - h));
  return { left, top };
}

export function EventPopover() {
  const popover = useStore((s) => s.popover);
  useStore((s) => s.occVersion);
  const deletePrompt = useStore((s) => s.deletePrompt);
  const attendPrompt = useStore((s) => s.attendPrompt);
  const occ = popover ? state.occ.get(popover.instanceId) : null;
  const panelRef = useRef(null);
  const [editingTime, setEditingTime] = useState(false);
  // Hooks stay above the early return below: a hook after it would shift
  // slots between renders and read another hook's state.
  const [copyOpen, setCopyOpen] = useState(false);
  const [timeScope, setTimeScope] = useState(null); // {start, end} awaiting a scope for a series

  // Opens instantly from the cached occurrence; description, cadence and
  // reminders arrive a round trip later from the single-event record (#15).
  // This is also the prefetch for the editor: an Edit from here finds the
  // record already merged and opens without waiting.
  useEffect(() => {
    if (!popover) return;
    ensureFullOccurrence(popover.instanceId);
    prefetchMap(state.occ.get(popover.instanceId)); // the detail view's map, warmed now
  }, [popover && popover.instanceId]); // eslint-disable-line

  useEffect(() => {
    setEditingTime(false);
  }, [popover && popover.instanceId]);

  // Document-level listeners; torn down on unmount, not just close.
  useEffect(() => {
    // A press anywhere outside the card closes it and does nothing else, the
    // card's own event included (ui/outside.js): opening a different event
    // takes a second, deliberate press.
    const onKey = (e) => {
      if (e.key === 'Tab') trapFocus(panelRef.current, e);
    };
    let stop = null;
    if (popover) {
      stop = onOutsidePress(insideAny(panelRef), () => set({ popover: null }));
      document.addEventListener('keydown', onKey, true);
    }
    return () => {
      if (stop) stop();
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
  // Read-only content: a feed, a plugin calendar, a Google calendar the
  // account can only view. The server's `editable` is the word on it.
  const isFeed = cal ? !cal.editable : occ.source === 'feed';
  const mobile = isMobile();
  const pos = !mobile && popover.anchorRect ? place(popover.anchorRect) : null;

  // Mobile sheet only: same-day prev/next in the header row, sharing the
  // detail view's list builder. Navigating swaps the popover's occurrence.
  // The desktop popover stays anchored to its chip and is unchanged.
  // The browsed day is pinned once and carried through navigation, so
  // stepping onto a multi-day event never re-derives the day from that
  // event's start and jumps the list to another date.
  const navDay = popover.dayKey || occDayKey(occ);
  let nav = null;
  if (mobile) {
    const list = sameDayList(occ, navDay);
    nav = { list, index: list.findIndex((o) => o.instanceId === occ.instanceId) };
  }
  const goSheet = (idx) => {
    const target = nav && nav.list[idx];
    if (target) set({ popover: { ...popover, instanceId: target.instanceId, dayKey: navDay } });
  };

  const saveTime = async (startVal, endVal) => {
    const s = fromInputValue(startVal);
    const e = fromInputValue(endVal);
    if (isNaN(s) || isNaN(e) || e <= s) return;
    setEditingTime(false);
    const fields = { start: toISOWithOffset(s), end: toISOWithOffset(e) };
    // A series asks which occurrences the new time is for (ScopeChoice below).
    if (occ.recurring) { setTimeScope(fields); return; }
    await updateEvent(occ, fields);
  };

  // All-day occurrences carry literal dates at +00:00; anchor them to local
  // midnight for display so the date never shifts across timezones.
  const s = occ.allDay ? dateOfDayKey(occ.start.slice(0, 10)) : parseISO(occ.start);
  const e = occ.allDay ? dateOfDayKey(occ.end.slice(0, 10)) : parseISO(occ.end);

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
      ${mobile && nav && html`<div class="bc-sheet-nav" role="group" aria-label="Previous and next event this day">
        <button
          type="button" class="bc-icon-btn bc-sheet-chev" aria-label="Previous event this day"
          disabled=${nav.index <= 0} onClick=${() => goSheet(nav.index - 1)}
        ><${Icon} name="chevronLeft" size=${16} /></button>
        <button
          type="button" class="bc-icon-btn bc-sheet-chev" aria-label="Next event this day"
          disabled=${nav.index < 0 || nav.index >= nav.list.length - 1} onClick=${() => goSheet(nav.index + 1)}
        ><${Icon} name="chevronRight" size=${16} /></button>
        ${nav.list.length > 1 && html`<span class="bc-sheet-count">${nav.index + 1} of ${nav.list.length}</span>`}
      </div>`}
      <div class="bc-pop-titlerow">
        <h2 class="bc-pop-title${occ.status === 'cancelled' ? ' is-cancelled' : ''}" onClick=${() => openDetail(occ.instanceId)} title="Open full details">
          ${occ.title || '(untitled)'}${occ.isNew ? html` <span class="bc-new-pill">new</span>` : ''}
        </h2>
        <span class="bc-pop-iconrow" role="group" aria-label="Event actions">
          <button type="button" class="bc-icon-btn" title="Open full details" aria-label="Open full details" onClick=${() => openDetail(occ.instanceId)}><${Icon} name="expand" size=${14} /></button>
          ${!isFeed && html`<button type="button" class="bc-icon-btn" title="Reschedule (r)" aria-label="Reschedule" onClick=${() => enterReschedule(occ.instanceId)}><${Icon} name="reschedule" size=${14} /></button>`}
          ${!isFeed && html`<button type="button" class="bc-icon-btn" title="Edit" aria-label="Edit" onClick=${() => set({ popover: null, editor: { mode: 'edit', occ } })}><${Icon} name="pencil" size=${14} /></button>`}
          ${!isFeed && html`<button type="button" class="bc-icon-btn bc-pop-trash" title="Delete" aria-label="Delete" onClick=${() => (occ.recurring || googleBacked(occ) ? set({ deletePrompt: occ.instanceId }) : deleteEvent(occ))}><${Icon} name="trash" size=${14} /></button>`}
          <button type="button" class=${'bc-icon-btn' + (copyOpen ? ' is-active' : '')} title="Copy to another calendar" aria-label="Copy to another calendar" aria-expanded=${copyOpen} onClick=${() => setCopyOpen(!copyOpen)}><${Icon} name="stack" size=${14} /></button>
          <button type="button" class="bc-icon-btn" aria-label="Close" onClick=${() => set({ popover: null })}><${Icon} name="close" size=${14} /></button>
        </span>
      </div>
      ${copyOpen && html`<${CopyTo} occ=${occ} compact onDone=${() => set({ popover: null })} />`}
      ${deletePrompt === occ.instanceId && html`<${DeleteScope} occ=${occ} compact onDone=${() => set({ deletePrompt: null, popover: null })} onCancel=${() => set({ deletePrompt: null })} />`}
      ${timeScope && html`<${ScopeChoice} occ=${occ} verb="New time for" compact note=${googleBacked(occ) ? GOOGLE_NO_UNDO : null} onPick=${(scope) => { const f = timeScope; setTimeScope(null); updateEvent(occ, f, scope); }} onCancel=${() => setTimeScope(null)} />`}
      ${attendPrompt && attendPrompt.instanceId === occ.instanceId && html`<${ScopeChoice} occ=${occ} verb=${attendPrompt.label + ' for'} compact note=${cal && cal.role === 'mine' && googleBacked(occ) ? GOOGLE_NO_UNDO : null}
        onPick=${async (scope) => { const v = attendPrompt.relationship; set({ attendPrompt: null }); const r = await setRelationship(occ, v, scope); if (r === 'hidden') set({ popover: null }); }}
        onCancel=${() => set({ attendPrompt: null })} />`}
      ${occ.containers && occ.containers.length > 0 && html`<button
        type="button" class="bc-partof" title="Open this trip"
        onClick=${() => { set({ popover: null }); openTripByEventId(occ.containers[0].eventId); }}
      ><${LinkIcon} size=${12} /> Part of: ${occ.containers[0].title}</button>`}
      ${editingTime
        ? html`<${TimeEditor} start=${s} end=${e} allDay=${occ.allDay} onSave=${saveTime} onCancel=${() => setEditingTime(false)} />`
        : html`<div class="bc-pop-when" onClick=${() => !isFeed && setEditingTime(true)} title=${isFeed ? '' : 'Click to edit time'}>
            <span class="bc-date-duration">${fmtRange(s, e, occ.allDay)} <${DurationSuffix} occ=${occ} expanded /></span>
            ${occ.recurring && html`<span class="bc-pop-recur">${describeRrule(occ.rrule)}</span>`}
          </div>`}
      ${!occ.full && occ.hasReminders && !occ.reminders && html`<div class="bc-pop-rem bc-pop-skel">
        <${Icon} name="bell" size=${12} /><${Skeleton} rows=${1} compact=${true} />
      </div>`}
      ${occ.reminders && occ.reminders.length > 0 && html`<div class=${'bc-pop-rem' + (occ.full ? ' bc-late' : '')}>
        <${Icon} name="bell" size=${12} />${occ.reminders.map(fmtReminder).join(', ')}
      </div>`}
      ${occ.location && isPendingLocation(occ.location) && html`<div class="bc-pop-where is-pending" title=${occ.location}>
        <${Icon} name="lock" size=${12} />Location after RSVP
      </div>`}
      ${occ.location && !isPendingLocation(occ.location) && html`<div class="bc-pop-where">
        <${PinIcon} size=${12} />${occ.location}
        <a
          class="bc-maplink" href=${gmapsUrl(occ.location, occ.locationLat, occ.locationLng)}
          target="_blank" rel="noopener noreferrer" title="Open in Google Maps"
        >Map <${Icon} name="arrowUpRight" size=${10} /></a>
      </div>`}
      ${((occ.people && occ.people.length > 0) || (occ.tags && occ.tags.length > 0)) && html`<div class="bc-pop-meta">
        ${occ.people && occ.people.length > 0 && html`<span class="bc-pop-people">
          <${Icon} name="people" size=${12} />
          ${occ.people.map((n, i) => html`<span key=${n}>${i > 0 && ', '}<button
            type="button" class="bc-person-link" title=${'Open ' + n + ' in People'}
            onClick=${() => set({ popover: null, route: 'people', peopleFocus: n })}
          >${n}</button></span>`)}
        </span>`}
        ${occ.tags && occ.tags.length > 0 && html`<span class="bc-pop-tags">${occ.tags.map((t) => '#' + t).join(' ')}</span>`}
      </div>`}
      ${!occ.full && occ.hasDescription && !occ.description && html`<div class="bc-pop-descwrap bc-pop-skel">
        <${Skeleton} rows=${2} compact=${true} />
      </div>`}
      ${occ.description && (() => {
        // Preview only: the popover is a summary card, so the description is
        // line-clamped with a "More" link into the detail view. It never
        // scrolls internally — a scrollbar inside a hover card is a trap.
        const rich = hasHtml(occ.description);
        const text = rich ? '' : stripToText(occ.description);
        if (!rich && !text) return null;
        const long = rich ? true : text.length > 160;
        return html`<div class=${'bc-pop-descwrap' + (occ.full ? ' bc-late' : '')}>
          ${rich
            ? html`<div class="bc-pop-desc bc-pop-desc-rich bc-rich" dangerouslySetInnerHTML=${{ __html: sanitizeHtml(occ.description) }}></div>`
            : html`<div class="bc-pop-desc">${text}</div>`}
          ${long && html`<button
            type="button" class="bc-pop-more" onClick=${() => openDetail(occ.instanceId)}
          >More</button>`}
        </div>`;
      })()}
      ${occ.url && html`<a class="bc-pop-url" href=${occ.url} target="_blank" rel="noopener">Event link <${Icon} name="arrowUpRight" size=${10} /></a>`}
      <div class="bc-pop-calline" title=${'Calendar: ' + ((cal && cal.name) || 'Calendar')}>
        <span class="bc-pop-calicon" style=${`color:${ink((cal && cal.color) || '#888')}`}><${Icon} name="calendar" size=${12} /></span>
        ${(cal && cal.name) || 'Calendar'}
      </div>
      ${!(cal && cal.role === 'context') && html`<div class="bc-pop-actions">
        <${RelationshipControl} occ=${occ} cal=${cal} compact onHidden=${() => set({ popover: null })} />
        ${isFeed && html`<div class="bc-seg" role="group" aria-label="Feedback">
          ${[['up', 'More like this'], ['down', 'Less like this']].map(([value, label]) => html`<button
            key=${value} type="button" class="bc-seg-btn bc-seg-icon"
            title=${label} aria-label=${label}
            onClick=${() => sendFeedback(occ, value)}
          ><${ThumbIcon} dir=${value} /></button>`)}
        </div>`}
      </div>`}
    </div>
  </div>`;
}

function TimeEditor({ start, end, allDay, onSave, onCancel }) {
  const [sv, setSv] = useState(toInputValue(start));
  const [ev, setEv] = useState(toInputValue(end));
  const duration = eventDuration({ start: sv, end: ev, allDay });
  // All-day: two date fields, the second being the last day (the stored end
  // is the day after; #34). The values kept here stay datetime-local, so the
  // save path is the same for both kinds.
  const days = allDay ? allDayFields(sv, ev) : null;
  const setDays = (first, last) => {
    const v = allDayInputs(first, last);
    setSv(v.start);
    setEv(v.end);
  };
  return html`<div class="bc-pop-timeedit">
    ${days
      ? html`<input type="date" value=${days.start} onInput=${(x) => x.target.value && setDays(x.target.value, days.end < x.target.value ? x.target.value : days.end)} aria-label="First day" />
        <span>to</span>
        <input type="date" value=${days.end} min=${days.start} onInput=${(x) => x.target.value && setDays(days.start, x.target.value)} aria-label="Last day" />`
      : html`<input type="datetime-local" value=${sv} onInput=${(x) => setSv(x.target.value)} aria-label="Start" />
        <span>to</span>
        <input type="datetime-local" value=${ev} onInput=${(x) => setEv(x.target.value)} aria-label="End" />`}
    ${duration && html`<span class="bc-duration-exact">${duration.exact} total</span>`}
    <button type="button" class="bc-btn bc-btn-primary" onClick=${() => onSave(sv, ev)}>Save</button>
    <button type="button" class="bc-btn" onClick=${onCancel}>Cancel</button>
  </div>`;
}
