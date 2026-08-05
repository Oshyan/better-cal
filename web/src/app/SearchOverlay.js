// SearchOverlay: `/` opens; instant results grouped past/future; Enter or
// click jumps the calendar to the event's date and flashes it. The user's
// tags render as clickable chips beneath the input; clicking one runs a
// tag-only search ("#tag", server-side), and result rows show their tags.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { CalDot, Icon } from '../ui/icons.js';
import { useStore, set, state } from './store.js';
import { search } from './api.js';
import { jumpToDate, openDetail } from './actions.js';
import { trapFocus } from '../ui/DayExpand.js';
import { parseISO, fmtDateFull, fmtTime, occDayKey } from '../lib/dates.js';

export function SearchOverlay() {
  const open = useStore((s) => s.searchOpen);
  const userTags = useStore((s) => s.tags);
  const [q, setQ] = useState('');
  const [results, setResults] = useState(null);
  const [sel, setSel] = useState(0);
  const inputRef = useRef(null);
  const panelRef = useRef(null);
  const timerRef = useRef(0);

  useEffect(() => {
    if (open && inputRef.current) {
      inputRef.current.focus();
      setQ('');
      setResults(null);
      setSel(0);
    }
  }, [open]);

  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Tab') trapFocus(panelRef.current, e); };
    if (open) document.addEventListener('keydown', onKey, true);
    return () => document.removeEventListener('keydown', onKey, true);
  }, [open]);

  useEffect(() => () => clearTimeout(timerRef.current), []);

  if (!open) return null;

  const runSearch = async (query) => {
    try {
      const r = await search(query);
      if (r !== null) setResults(r); // null = superseded by newer request
    } catch { setResults([]); }
  };

  const onInput = (e) => {
    const v = e.target.value;
    setQ(v);
    setSel(0);
    clearTimeout(timerRef.current);
    if (!v.trim()) { setResults(null); return; }
    timerRef.current = setTimeout(() => runSearch(v.trim()), 250);
  };

  // Tag chip click: tag-only search ("#name" matches tags, not text).
  const searchTag = (name) => {
    const query = '#' + name;
    setQ(query);
    setSel(0);
    clearTimeout(timerRef.current);
    runSearch(query);
    if (inputRef.current) inputRef.current.focus();
  };

  const flat = results || [];
  const now = Date.now();
  const future = flat.filter((o) => parseISO(o.start).getTime() >= now);
  const past = flat.filter((o) => parseISO(o.start).getTime() < now);
  const ordered = [...future, ...past];

  const go = (occ) => {
    if (!state.occ.has(occ.instanceId)) {
      state.occ.set(occ.instanceId, occ);
      set({ occVersion: state.occVersion + 1 });
    }
    jumpToDate(occDayKey(occ), occ.instanceId);
    openDetail(occ.instanceId); // land on the full detail view after the jump
  };

  const onKeyDown = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setSel((s) => Math.min(s + 1, ordered.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setSel((s) => Math.max(s - 1, 0)); }
    else if (e.key === 'Enter' && ordered[sel]) { e.preventDefault(); go(ordered[sel]); }
  };

  const renderGroup = (label, items, offset) => items.length > 0 && html`<div class="bc-search-group">
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
        ${occ.location && html`<span class="bc-search-loc">${occ.location}</span>`}
        <span class="bc-search-date">${fmtDateFull(parseISO(occ.start))}${occ.allDay ? '' : ' ' + fmtTime(parseISO(occ.start))}</span>
      </button>`;
    })}
  </div>`;

  return html`<div class="bc-overlay bc-search-overlay" onClick=${(e) => { if (e.target === e.currentTarget) set({ searchOpen: false }); }}>
    <div class="bc-search" ref=${panelRef} role="dialog" aria-modal="true" aria-label="Search events">
      <div class="bc-search-top">
        <input
          ref=${inputRef}
          class="bc-search-input"
          placeholder="Search all events"
          value=${q}
          onInput=${onInput}
          onKeyDown=${onKeyDown}
          aria-label="Search all events"
        />
        <button type="button" class="bc-icon-btn bc-search-close" aria-label="Close" onClick=${() => set({ searchOpen: false })}><${Icon} name="close" size=${15} /></button>
      </div>
      ${userTags && userTags.length > 0 && html`<div class="bc-search-tags" role="group" aria-label="Search by tag">
        ${userTags.map((t) => html`<button
          key=${t.id} type="button" class="bc-tag-chip bc-tag-chip-btn"
          onClick=${() => searchTag(t.name)}
        >#${t.name}</button>`)}
      </div>`}
      <div class="bc-search-results">
        ${results !== null && ordered.length === 0 && html`<div class="bc-empty">No matches for "${q}"</div>`}
        ${renderGroup('Upcoming', future, 0)}
        ${renderGroup('Past', past, future.length)}
      </div>
    </div>
  </div>`;
}
