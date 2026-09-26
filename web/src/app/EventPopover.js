// EventPopover: anchored details popover (bottom sheet on mobile).
// Prefers the right side of the anchor, flips left on viewport overflow,
// clamps with a gap. Inline title/time editing; edit / delete / attendance.
//
// On phones it is the event sheet (0.5.0): one fixed height for every event,
// so stepping through a day never moves its edge; a grab handle; drag or tap
// the handle (or the title, or More) to grow it to full height, drag down to
// shrink and then close; swipe sideways to step through the day; the phone's
// back gesture steps it down too. The event it shows is kept in view above
// it in the calendar, outlined, and the outline follows the stepping.

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
import { onOutsidePress, insideAny, swallowClickOfThisPress } from '../ui/outside.js';
import { EventSheetBody } from './EventSheetBody.js';

const GAP = 10;
const WIDTH = 360;
// Sheet gestures (phones): movement before a touch counts as a drag or a
// swipe, and how far a release must travel to change the sheet.
const SLOP = 10;
const SNAP = 64;
const SWIPE = 56;

// Which of an event's pieces on screen stands for the day being browsed: a
// multi-day event appears on several days (agenda rows, month bar segments),
// and the sheet points at the one on the day you are stepping through, not
// its first. Inside the day's own element first (an agenda day, a month
// cell, a day column), then a piece lying over that day (a bar segment over
// its cell), then the agenda's rail beside that day (the list shows a
// multi-day event as rows where it starts and ends, and a rail between),
// then one across the day's column (a week's all-day bar).
// Returns {mark, scrollTo}: what to outline, and what to bring into view.
function focusTarget(instanceId, dayKey, panel) {
  const shown = (n) => !panel.contains(n) && n.getClientRects().length > 0;
  const esc = CSS.escape(instanceId);
  const cands = [...document.querySelectorAll('[data-instance="' + esc + '"]')].filter(shown);
  const days = dayKey ? [...document.querySelectorAll('[data-day="' + CSS.escape(dayKey) + '"]')].filter(shown) : [];
  const one = (el) => (el ? { mark: el, scrollTo: el } : null);
  if (!days.length) return one(cands[0]);
  for (const d of days) {
    const inner = cands.find((c) => d.contains(c));
    if (inner) return one(inner);
  }
  const x = (r, dr) => r.left < dr.right && r.right > dr.left;
  const y = (r, dr) => r.top < dr.bottom && r.bottom > dr.top;
  for (const d of days) {
    const dr = d.getBoundingClientRect();
    const hit = cands.find((c) => { const r = c.getBoundingClientRect(); return x(r, dr) && y(r, dr); });
    if (hit) return one(hit);
  }
  const rails = [...document.querySelectorAll('.bc-agenda-rail[data-span="' + esc + '"]')].filter(shown); // the line, not the tint behind the span
  for (const d of days) {
    const dr = d.getBoundingClientRect();
    const rail = rails.find((c) => y(c.getBoundingClientRect(), dr));
    if (rail) return { mark: rail, scrollTo: d };
  }
  for (const d of days) {
    const dr = d.getBoundingClientRect();
    const hit = cands.find((c) => x(c.getBoundingClientRect(), dr));
    if (hit) return one(hit);
  }
  return one(cands[0]);
}

// The nearest ancestor that scrolls vertically, to bring an event into view.
function scrollParent(el) {
  for (let n = el.parentElement; n && n !== document.body; n = n.parentElement) {
    const oy = getComputedStyle(n).overflowY;
    if ((oy === 'auto' || oy === 'scroll') && n.scrollHeight > n.clientHeight + 1) return n;
  }
  return null;
}

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
  // Phone sheet: quick height or full; refs for the gesture and back-gesture
  // handlers, which live across renders.
  const [full, setFull] = useState(false);
  const fullRef = useRef(false);
  fullRef.current = full;
  const bodyRef = useRef(null);
  const navRef = useRef(null); // {go(i), index, count} of the day being stepped
  const poppedRef = useRef(false);
  const open = !!popover;
  const sheet = open && isMobile();

  useEffect(() => { if (!open) setFull(false); }, [open]);
  // One bar at the bottom at a time: while the sheet is open its actions
  // take the place of the app's bottom bar, which slides down under it and
  // back when the sheet closes.
  useEffect(() => {
    if (!sheet) return undefined;
    document.documentElement.classList.add('bc-sheet-open');
    return () => document.documentElement.classList.remove('bc-sheet-open');
  }, [sheet]);

  // The phone's back gesture steps the sheet down: full to quick, quick to
  // closed. One history entry stands for the open sheet; closing it any
  // other way takes that entry back off.
  useEffect(() => {
    if (!sheet) return undefined;
    history.pushState({ bcSheet: 1 }, '');
    const onPop = () => {
      if (fullRef.current) { setFull(false); history.pushState({ bcSheet: 1 }, ''); return; }
      poppedRef.current = true;
      set({ popover: null });
    };
    window.addEventListener('popstate', onPop);
    return () => {
      window.removeEventListener('popstate', onPop);
      if (!poppedRef.current && history.state && history.state.bcSheet) history.back();
      poppedRef.current = false;
    };
  }, [sheet]);

  // Outline the event where the day being browsed shows it, and keep that
  // outline as the views behind re-render (virtualized rows come and go).
  const focusDay = popover && (popover.dayKey || (occ ? occDayKey(occ) : null));
  useEffect(() => {
    if (!sheet || !popover) return undefined;
    const panel = panelRef.current;
    if (!panel) return undefined;
    let raf = 0;
    const mark = () => {
      raf = 0;
      const t = focusTarget(popover.instanceId, focusDay, panel);
      const el = t && t.mark;
      for (const n of document.querySelectorAll('.bc-sheet-focus')) if (n !== el) n.classList.remove('bc-sheet-focus');
      if (el) el.classList.add('bc-sheet-focus');
    };
    mark();
    const mo = new MutationObserver(() => { if (!raf) raf = requestAnimationFrame(mark); });
    mo.observe(document.body, { childList: true, subtree: true });
    return () => {
      mo.disconnect();
      cancelAnimationFrame(raf);
      for (const n of document.querySelectorAll('.bc-sheet-focus')) n.classList.remove('bc-sheet-focus');
    };
  }, [sheet, popover && popover.instanceId, focusDay]); // eslint-disable-line

  // Keep the event in view above the sheet, in whatever view is behind it:
  // scroll its nearest scroller just enough, as the sheet opens and at each
  // step through the day.
  useEffect(() => {
    if (!sheet || !popover) return;
    const panel = panelRef.current;
    if (!panel) return;
    const t = focusTarget(popover.instanceId, focusDay, panel);
    const el = t && t.scrollTo;
    if (!el) return;
    const sc = scrollParent(el);
    if (!sc) return;
    const sheetTop = window.innerHeight - panel.offsetHeight; // not its rect: it may be mid-animation
    const top = Math.max(0, sc.getBoundingClientRect().top) + 8;
    const r = el.getBoundingClientRect();
    let delta = 0;
    if (r.bottom > sheetTop - 12) delta = r.bottom - (sheetTop - 12);
    if (r.top - delta < top) delta = r.top - top;
    if (Math.abs(delta) > 1) sc.scrollBy({ top: delta, behavior: 'smooth' });
  }, [sheet, popover && popover.instanceId, focusDay]); // eslint-disable-line

  // Touch on the sheet: up or down on the handle row (or down on the body
  // when it is scrolled to its top) drags the sheet; sideways steps through
  // the day; anything else scrolls the body as usual.
  useEffect(() => {
    if (!sheet) return undefined;
    const panel = panelRef.current;
    if (!panel) return undefined;
    let g = null;
    const onStart = (e) => {
      if (e.touches.length !== 1) { g = null; return; }
      const t = e.touches[0];
      const head = e.target.closest && e.target.closest('.bc-evsheet-head');
      const body = bodyRef.current;
      g = { x: t.clientX, y: t.clientY, mode: null, head: !!head, atTop: !body || body.scrollTop <= 0, h: panel.offsetHeight };
    };
    const onMove = (e) => {
      if (!g || e.touches.length !== 1) return;
      const t = e.touches[0];
      const dx = t.clientX - g.x;
      const dy = t.clientY - g.y;
      if (!g.mode) {
        if (Math.abs(dx) < SLOP && Math.abs(dy) < SLOP) return;
        if (Math.abs(dx) > Math.abs(dy) * 1.3) g.mode = 'swipe';
        else if (g.head || (dy > 0 && g.atTop)) g.mode = 'drag';
        else { g = null; return; } // the body scrolls
        panel.classList.add('is-gesture');
      }
      if (e.cancelable) e.preventDefault();
      if (g.mode === 'drag') {
        const maxH = window.innerHeight - 8;
        const h = Math.min(maxH, g.h - dy);
        if (h >= g.h || fullRef.current) panel.style.height = h + 'px';
        else panel.style.transform = 'translateY(' + (g.h - h) + 'px)';
      } else {
        const body = bodyRef.current;
        if (body) body.style.transform = 'translateX(' + dx * 0.35 + 'px)';
      }
    };
    const onEnd = (e) => {
      if (!g || !g.mode) { g = null; return; }
      const t = e.changedTouches[0];
      const dx = t.clientX - g.x;
      const dy = t.clientY - g.y;
      panel.classList.remove('is-gesture');
      panel.style.height = '';
      panel.style.transform = '';
      if (bodyRef.current) bodyRef.current.style.transform = '';
      swallowClickOfThisPress(); // a drag or swipe that ends on a button is not a press of it
      if (g.mode === 'drag') {
        if (dy < -SNAP) setFull(true);
        else if (dy > SNAP) { if (fullRef.current) setFull(false); else set({ popover: null }); }
      } else if (Math.abs(dx) > SWIPE && navRef.current) {
        navRef.current.go(navRef.current.index + (dx < 0 ? 1 : -1));
      }
      g = null;
    };
    panel.addEventListener('touchstart', onStart, { passive: true });
    panel.addEventListener('touchmove', onMove, { passive: false });
    panel.addEventListener('touchend', onEnd, { passive: true });
    panel.addEventListener('touchcancel', onEnd, { passive: true });
    return () => {
      panel.removeEventListener('touchstart', onStart, { passive: true });
      panel.removeEventListener('touchmove', onMove, { passive: false });
      panel.removeEventListener('touchend', onEnd, { passive: true });
      panel.removeEventListener('touchcancel', onEnd, { passive: true });
    };
  }, [sheet]);

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
      // On the phone sheet, a tap on another event in the calendar opens it
      // here (the press goes through to it) instead of only closing the
      // sheet; a tap anywhere else closes it and does nothing more. Controls
      // inside a row (its swipe actions) never take a press through.
      const inPanel = insideAny(panelRef);
      const toEvent = (t) => isMobile() && t && t.closest
        && t.closest('[data-instance]') && !t.closest('.bc-agenda-acts, .bc-agenda-grip');
      stop = onOutsidePress((t) => inPanel(t) || toEvent(t), () => set({ popover: null }));
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
  navRef.current = nav ? { go: goSheet, index: nav.index, count: nav.list.length } : null;
  // On the phone the full view is the same sheet grown; elsewhere, the
  // separate full view. Once grown, the expand button still opens the
  // separate view, for what the sheet doesn't hold yet (map, invitation
  // replies, source); 0.5.2 brings those into the sheet.
  const openFull = () => (mobile ? setFull(true) : openDetail(occ.instanceId));

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

  const color = (cal && cal.color) || '#888';
  return html`<div
    class="bc-popover${mobile ? ' bc-sheet bc-evsheet' : ''}${mobile && full ? ' is-full' : ''}"
    style=${pos ? `left:${pos.left}px;top:${pos.top}px;width:${WIDTH}px` : (mobile ? `--cal:${color}` : '')}
    ref=${panelRef}
    role="dialog"
    aria-modal="true"
    aria-label="Event details"
  >
    ${!mobile && html`<div class="bc-pop-color" style=${`background:${color}`}></div>`}
    ${mobile && html`<div class="bc-evsheet-head">
      <button
        type="button" class="bc-evsheet-grab"
        aria-label=${full ? 'Show less' : 'Show all details'} aria-expanded=${full}
        onClick=${() => setFull(!full)}
      ><i></i></button>
      ${nav && html`<div class="bc-sheet-nav" role="group" aria-label="Previous and next event this day">
        <button
          type="button" class="bc-icon-btn bc-sheet-chev" aria-label="Previous event this day"
          disabled=${nav.index <= 0} onClick=${() => goSheet(nav.index - 1)}
        ><${Icon} name="chevronLeft" size=${20} /></button>
        <span class="bc-sheet-where">
          <span class="bc-sheet-day">${dateOfDayKey(navDay).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })}${nav.list.length > 1 ? ' \u00b7 ' + (nav.index + 1) + ' of ' + nav.list.length : ''}</span>
          ${nav.list.length > 1 && nav.list.length <= 12 && html`<span class="bc-sheet-dots" aria-hidden="true">${nav.list.map((o, i) => html`<i key=${o.instanceId} class=${i === nav.index ? 'is-on' : ''}></i>`)}</span>`}
        </span>
        <button
          type="button" class="bc-icon-btn bc-sheet-chev" aria-label="Next event this day"
          disabled=${nav.index < 0 || nav.index >= nav.list.length - 1} onClick=${() => goSheet(nav.index + 1)}
        ><${Icon} name="chevronRight" size=${20} /></button>
      </div>`}
    </div>`}
    ${mobile ? html`<${EventSheetBody}
      occ=${occ} cal=${cal} isFeed=${isFeed} full=${full} onFull=${() => setFull(true)} bodyRef=${bodyRef}
      copyOpen=${copyOpen} setCopyOpen=${setCopyOpen} timeScope=${timeScope} setTimeScope=${setTimeScope}
      deletePrompt=${deletePrompt} attendPrompt=${attendPrompt} s=${s} e=${e}
    />` : html`<div class="bc-pop-body" ref=${bodyRef}>
      <div class="bc-pop-titlerow">
        <h2 class="bc-pop-title${occ.status === 'cancelled' ? ' is-cancelled' : ''}" onClick=${openFull} title="Open full details">
          ${occ.title || '(untitled)'}${occ.isNew ? html` <span class="bc-new-pill">new</span>` : ''}
        </h2>
        <span class="bc-pop-iconrow" role="group" aria-label="Event actions">
          <button type="button" class="bc-icon-btn" title="Open full details" aria-label="Open full details" onClick=${mobile && full ? () => openDetail(occ.instanceId) : openFull}><${Icon} name="expand" size=${14} /></button>
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
          ${long && !(mobile && full) && html`<button
            type="button" class="bc-pop-more" onClick=${openFull}
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
    </div>`}
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
