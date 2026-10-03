// SearchOverlay: `/` opens; instant results grouped past/future; Enter or
// click jumps the calendar to the event's date and flashes it. The user's
// tags render as clickable chips beneath the input; clicking one runs a
// tag-only search ("#tag", server-side), and result rows show their tags.
//
// 0.7.3 (#60): Upcoming (the default), All or Past, and a calendar, chosen
// under the input, filtered on the server before its result limit and
// remembered per device. An Upcoming search ends with "N past matches".
// An event opened from here offers "‹ Search" (and Back on a phone) to
// return to these results as they were.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { CalDot, Icon } from '../ui/icons.js';
import { useStore, set, state } from './store.js';
import { search } from './api.js';
import { jumpToDate, openDetail } from './actions.js';
import { trapFocus } from '../ui/DayExpand.js';
import { isPendingLocation } from '../lib/maps.js';
import { parseISO, dateOfDayKey, fmtDateFull, fmtDayMedium, fmtTime, occDayKey, todayKey } from '../lib/dates.js';
import { PHONE_QUERY } from '../lib/breakpoints.js';

// "Fri, Oct 9 · 3:00 AM"; the year only when it isn't this one (0.6.9).
function whenText(occ) {
  const d = occ.allDay ? dateOfDayKey(occ.start.slice(0, 10)) : parseISO(occ.start);
  const sameYear = String(d.getFullYear()) === todayKey().slice(0, 4);
  const day = sameYear ? fmtDayMedium(d) : fmtDateFull(d);
  return occ.allDay ? day : day + ' · ' + fmtTime(d);
}

const OPTS_KEY = 'bc-search-opts';
const WHENS = [['upcoming', 'Upcoming'], ['all', 'All'], ['past', 'Past']];

function loadOpts() {
  try {
    const o = JSON.parse(localStorage.getItem(OPTS_KEY) || '{}');
    return { when: WHENS.some(([w]) => w === o.when) ? o.when : 'upcoming', calendar: o.calendar ? String(o.calendar) : '' };
  } catch { return { when: 'upcoming', calendar: '' }; }
}

function saveOpts(o) {
  try { localStorage.setItem(OPTS_KEY, JSON.stringify(o)); } catch { /* per-device convenience */ }
}

// The results an event was opened from, for "‹ Search" to bring back.
let last = null;

export function SearchOverlay() {
  const open = useStore((s) => s.searchOpen);
  const userTags = useStore((s) => s.tags);
  const calendars = useStore((s) => s.calendars);
  const [q, setQ] = useState('');
  const [results, setResults] = useState(null);
  const [pastCount, setPastCount] = useState(0);
  const [opts, setOpts] = useState(loadOpts);
  // "Show them" widens one search to All without changing the remembered choice.
  const [peek, setPeek] = useState(null);
  // Nothing is selected until the keyboard or the pointer picks a row, so
  // the first row never looks chosen by default (0.7.3).
  const [sel, setSel] = useState(-1);
  const inputRef = useRef(null);
  const panelRef = useRef(null);
  const listRef = useRef(null);
  const timerRef = useRef(0);
  const restoreRef = useRef(null);

  const phone = (() => { try { return matchMedia(PHONE_QUERY).matches; } catch { return false; } })();
  const when = peek || opts.when;

  useEffect(() => {
    if (!open) return;
    const back = state.searchRestore && last;
    if (state.searchRestore) set({ searchRestore: false });
    if (back) {
      setQ(back.q);
      setResults(back.results);
      setPastCount(back.pastCount);
      setPeek(back.peek);
      setSel(phone ? -1 : back.sel);
      restoreRef.current = back.scrollTop;
      runSearch(back.q, back.peek || opts.when, opts.calendar); // quietly bring them up to date
      if (!phone && inputRef.current) inputRef.current.focus();
      return;
    }
    setQ('');
    setResults(null);
    setPastCount(0);
    setPeek(null);
    setSel(-1);
    if (inputRef.current) inputRef.current.focus();
  }, [open]); // eslint-disable-line

  // Back to the results: the list where it was left.
  useEffect(() => {
    if (restoreRef.current == null || !listRef.current) return;
    listRef.current.scrollTop = restoreRef.current;
    restoreRef.current = null;
  });

  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Tab') trapFocus(panelRef.current, e); };
    if (open) document.addEventListener('keydown', onKey, true);
    return () => document.removeEventListener('keydown', onKey, true);
  }, [open]);

  useEffect(() => () => clearTimeout(timerRef.current), []);

  // Phone: search is a full screen, so Back closes it like the sheet and
  // the editor (0.6.9). Picking a result leaves the entry to the event sheet.
  // Coming back from that event (0.7.3), the sheet's entry is search's again.
  useEffect(() => {
    if (!open || !phone) return undefined;
    if (history.state && (history.state.bcSearch || history.state.bcSheet)) history.replaceState({ bcSearch: 1 }, '');
    else history.pushState({ bcSearch: 1 }, '');
    let popped = false;
    const onPop = () => { popped = true; set({ searchOpen: false }); };
    window.addEventListener('popstate', onPop);
    return () => {
      window.removeEventListener('popstate', onPop);
      if (!popped && history.state && history.state.bcSearch) {
        if (state.popover) history.replaceState({ bcSheet: 1 }, '');
        else history.back();
      }
    };
  }, [open, phone]);

  if (!open) return null;

  async function runSearch(query, w = when, calendar = opts.calendar) {
    try {
      const r = await search(query, { when: w, calendar });
      if (r !== null) { setResults(r.results); setPastCount(r.pastCount); } // null = superseded by newer request
    } catch { setResults([]); setPastCount(0); }
  }

  const rerun = (w, calendar) => {
    clearTimeout(timerRef.current);
    setSel(-1);
    if (q.trim()) runSearch(q.trim(), w, calendar);
  };

  const pickWhen = (w) => {
    const next = { ...opts, when: w };
    setOpts(next);
    saveOpts(next);
    setPeek(null);
    rerun(w, next.calendar);
  };

  const pickCalendar = (calendar) => {
    const next = { ...opts, calendar };
    setOpts(next);
    saveOpts(next);
    rerun(when, calendar);
  };

  const showPast = () => {
    setPeek('all');
    rerun('all', opts.calendar);
  };

  const onInput = (e) => {
    const v = e.target.value;
    setQ(v);
    setSel(-1);
    setPeek(null);
    clearTimeout(timerRef.current);
    if (!v.trim()) { setResults(null); setPastCount(0); return; }
    timerRef.current = setTimeout(() => runSearch(v.trim(), opts.when), 250);
  };

  // Tag chip click: tag-only search ("#name" matches tags, not text).
  const searchTag = (name) => {
    const query = '#' + name;
    setQ(query);
    setSel(-1);
    setPeek(null);
    clearTimeout(timerRef.current);
    runSearch(query, opts.when);
    if (inputRef.current) inputRef.current.focus();
  };

  const flat = results || [];
  const now = Date.now();
  // The server says which results are still to come (a running series is,
  // whatever its first date); the end decides for anything without the flag.
  const isUpcoming = (o) => (o.upcoming !== undefined ? o.upcoming : parseISO(o.end || o.start).getTime() >= now);
  // Relevance picks which results come back; within each group they read
  // in calendar order, the soonest upcoming first and the latest past first.
  const at = (o) => parseISO(o.start).getTime();
  const future = flat.filter(isUpcoming).sort((a, b) => at(a) - at(b));
  const past = flat.filter((o) => !isUpcoming(o)).sort((a, b) => at(b) - at(a));
  const ordered = [...future, ...past];

  const go = (occ) => {
    last = {
      q, results, pastCount, peek,
      sel: ordered.findIndex((o) => o.instanceId === occ.instanceId),
      scrollTop: listRef.current ? listRef.current.scrollTop : 0,
    };
    if (!state.occ.has(occ.instanceId)) {
      state.occ.set(occ.instanceId, occ);
      set({ occVersion: state.occVersion + 1 });
    }
    jumpToDate(occDayKey(occ), occ.instanceId);
    openDetail(occ.instanceId); // land on the event's full view after the jump
    set({ popover: { ...state.popover, backTo: { search: true, title: 'Search' } }, searchOpen: false });
  };

  const onKeyDown = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setSel((s) => Math.min(s + 1, ordered.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setSel((s) => Math.max(s - 1, 0)); }
    else if (e.key === 'Enter' && ordered.length) { e.preventDefault(); go(ordered[Math.max(sel, 0)]); }
  };

  const renderGroup = (label, items, offset, isPast = false) => items.length > 0 && html`<div class=${'bc-search-group' + (isPast ? ' is-past' : '')}>
    <div class="bc-search-group-label">${label}</div>
    ${items.map((occ, i) => {
      const cal = state.calendars.find((c) => c.id === occ.calendarId);
      const idx = offset + i;
      return html`<button
        key=${occ.instanceId} type="button"
        class="bc-search-row${idx === sel ? ' is-selected' : ''}"
        onClick=${() => go(occ)}
        onMouseEnter=${() => setSel(idx)}
      >
        <${CalDot} cal=${cal} />
        <span class="bc-search-title">${occ.title || '(untitled)'}</span>
        ${occ.tags && occ.tags.length > 0 && html`<span class="bc-search-rowtags">
          ${occ.tags.map((t) => html`<span key=${t} class="bc-tag-chip">#${t}</span>`)}
        </span>`}
        ${occ.location && html`<span class="bc-search-loc" title=${occ.location}>${isPendingLocation(occ.location) ? 'After RSVP' : occ.location}</span>`}
        <span class="bc-search-date">${whenText(occ)}</span>
      </button>`;
    })}
  </div>`;

  const calOptions = [...(calendars || [])].sort((a, b) => (a.name || '').localeCompare(b.name || ''));
  const scopeWord = when === 'upcoming' ? 'upcoming ' : when === 'past' ? 'past ' : '';

  return html`<div class="bc-overlay bc-search-overlay" onClick=${(e) => { if (e.target === e.currentTarget) set({ searchOpen: false }); }}>
    <div class="bc-search" ref=${panelRef} role="dialog" aria-modal="true" aria-label="Search events">
      <div class="bc-search-top">
        <input
          ref=${inputRef}
          class="bc-search-input"
          placeholder="Search events"
          value=${q}
          onInput=${onInput}
          onKeyDown=${onKeyDown}
          aria-label="Search events"
        />
        <button type="button" class="bc-icon-btn bc-search-close" aria-label="Close" onClick=${() => set({ searchOpen: false })}><${Icon} name="close" size=${15} /></button>
      </div>
      <div class="bc-search-opts">
        <div class="bc-seg" role="group" aria-label="When">
          ${WHENS.map(([w, label]) => html`<button key=${w} type="button" class=${'bc-seg-btn' + (when === w ? ' is-active' : '')} aria-pressed=${when === w} onClick=${() => pickWhen(w)}>${label}</button>`)}
        </div>
        <select class="bc-search-cal" aria-label="Calendar" value=${opts.calendar} onChange=${(e) => pickCalendar(e.target.value)}>
          <option value="">All calendars</option>
          ${calOptions.map((c) => html`<option key=${c.id} value=${String(c.id)}>${c.name}</option>`)}
        </select>
      </div>
      ${userTags && userTags.length > 0 && html`<div class="bc-search-tags" role="group" aria-label="Search by tag">
        ${userTags.map((t) => html`<button
          key=${t.id} type="button" class="bc-tag-chip bc-tag-chip-btn"
          onClick=${() => searchTag(t.name)}
        >#${t.name}</button>`)}
      </div>`}
      <div class="bc-search-results" ref=${listRef}>
        ${results !== null && ordered.length === 0 && html`<div class="bc-empty">No ${scopeWord}matches for "${q}"</div>`}
        ${renderGroup('Upcoming', future, 0)}
        ${renderGroup('Past', past, future.length, true)}
        ${results !== null && when === 'upcoming' && pastCount > 0 && html`<button type="button" class="bc-search-more" onClick=${showPast}>
          ${pastCount} past ${pastCount === 1 ? 'match' : 'matches'}<span class="bc-search-more-act">Show them</span>
        </button>`}
      </div>
    </div>
  </div>`;
}
