// JumpPopover: the go-anywhere date picker behind the toolbar date label and
// the `g` hotkey. Text input with deterministic parsing (jumpparse.js), a
// compact mini month grid with month/year steppers, and a months-of-year grid
// toggle. Enter or clicking a day jumps the calendar. Esc closes (global
// keyboard handler via closeOverlays); click-out closes; ✕ always visible.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { useStore, set, state } from './store.js';
import { jumpToDate } from './actions.js';
import { trapFocus } from '../ui/DayExpand.js';
import { parseJumpText } from '../lib/jumpparse.js';
import {
  todayKey, dateOfDayKey, epochDayOfKey, keyOfEpochDay, pad,
  fmtMonthYear, fmtDayLong, fmtMonthShort,
  weekIndexOfEpochDay, firstEpochDayOfWeek, getWeekStart,
} from '../lib/dates.js';

// Week rows (configured week start) covering a month, padded with
// adjacent-month days.
function monthWeeks(year, month) {
  const firstEd = epochDayOfKey(year + '-' + pad(month) + '-01');
  const gridStart = firstEpochDayOfWeek(weekIndexOfEpochDay(firstEd));
  const nextFirstEd = epochDayOfKey(month === 12
    ? (year + 1) + '-01-01'
    : year + '-' + pad(month + 1) + '-01');
  const weeks = [];
  for (let ed = gridStart; ed < nextFirstEd; ed += 7) {
    const row = [];
    for (let i = 0; i < 7; i++) row.push(keyOfEpochDay(ed + i));
    weeks.push(row);
  }
  return weeks;
}

// Single-letter day headers in week-start order, cached per start day.
let headCache = { start: null, heads: [] };
function weekdayHeads() {
  const start = getWeekStart();
  if (headCache.start !== start) {
    const fmt = new Intl.DateTimeFormat(undefined, { weekday: 'narrow' });
    const first = firstEpochDayOfWeek(weekIndexOfEpochDay(epochDayOfKey('2024-01-08')));
    headCache = {
      start,
      heads: Array.from({ length: 7 }, (_, i) => fmt.format(dateOfDayKey(keyOfEpochDay(first + i)))),
    };
  }
  return headCache.heads;
}

export function JumpPopover() {
  const open = useStore((s) => s.jumpOpen);
  const [text, setText] = useState('');
  const [disp, setDisp] = useState(null);     // {year, month} shown in the grid
  const [yearMode, setYearMode] = useState(false);
  const panelRef = useRef(null);
  const inputRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    setText('');
    setYearMode(false);
    const vm = state.visibleMonth;
    setDisp(vm
      ? { year: vm.year, month: vm.month }
      : { year: Number(state.anchor.slice(0, 4)), month: Number(state.anchor.slice(5, 7)) });
    if (inputRef.current) inputRef.current.focus();
    const onDoc = (e) => {
      if (panelRef.current && !panelRef.current.contains(e.target) &&
          !e.target.closest('.bc-toolbar-date')) set({ jumpOpen: false });
    };
    const onKey = (e) => { if (e.key === 'Tab') trapFocus(panelRef.current, e); };
    document.addEventListener('pointerdown', onDoc, true);
    document.addEventListener('keydown', onKey, true);
    return () => {
      document.removeEventListener('pointerdown', onDoc, true);
      document.removeEventListener('keydown', onKey, true);
    };
  }, [open]);

  if (!open || !disp) return null;

  const tKey = todayKey();
  const parsed = text.trim() ? parseJumpText(text, tKey) : null;

  const go = (dayKey) => {
    set({ jumpOpen: false });
    jumpToDate(dayKey);
  };

  const stepMonth = (n) => {
    const k = disp.year * 12 + (disp.month - 1) + n;
    setDisp({ year: Math.floor(k / 12), month: ((k % 12) + 12) % 12 + 1 });
  };
  const stepYear = (n) => setDisp({ year: disp.year + n, month: disp.month });

  const onKeyDown = (e) => {
    if (e.key === 'Enter' && parsed) { e.preventDefault(); go(parsed); }
  };

  const weeks = monthWeeks(disp.year, disp.month);
  const monthPrefix = disp.year + '-' + pad(disp.month);

  return html`<div class="bc-jump-pop" ref=${panelRef} role="dialog" aria-label="Jump to date">
    <div class="bc-jump-inputrow">
      <input
        ref=${inputRef}
        class="bc-jump-input"
        placeholder="June 2027, next tuesday, 8/15"
        value=${text}
        onInput=${(e) => setText(e.target.value)}
        onKeyDown=${onKeyDown}
        aria-label="Jump to date"
      />
      <button type="button" class="bc-icon-btn bc-jump-close" aria-label="Close" onClick=${() => set({ jumpOpen: false })}>✕</button>
    </div>
    <div class="bc-jump-preview" aria-live="polite">
      ${text.trim()
        ? (parsed ? html`Enter jumps to <strong>${fmtDayLong(dateOfDayKey(parsed))}</strong>` : 'No date recognized')
        : 'Type a date, or pick one below'}
    </div>

    <div class="bc-jump-head">
      <button type="button" class="bc-icon-btn bc-jump-step" aria-label="Previous year" onClick=${() => stepYear(-1)}>«</button>
      <button type="button" class="bc-icon-btn bc-jump-step" aria-label="Previous month" onClick=${() => stepMonth(-1)}>‹</button>
      <button
        type="button" class="bc-jump-title"
        aria-expanded=${yearMode}
        title=${yearMode ? 'Back to days' : 'Pick a month'}
        onClick=${() => setYearMode(!yearMode)}
      >${fmtMonthYear(dateOfDayKey(monthPrefix + '-01'))}</button>
      <button type="button" class="bc-icon-btn bc-jump-step" aria-label="Next month" onClick=${() => stepMonth(1)}>›</button>
      <button type="button" class="bc-icon-btn bc-jump-step" aria-label="Next year" onClick=${() => stepYear(1)}>»</button>
    </div>

    ${yearMode
      ? html`<div class="bc-jump-months" role="grid" aria-label=${'Months of ' + disp.year}>
          ${Array.from({ length: 12 }, (_, i) => html`<button
            key=${i} type="button"
            class="bc-jump-monthbtn${i + 1 === disp.month ? ' is-sel' : ''}"
            onClick=${() => { setDisp({ year: disp.year, month: i + 1 }); setYearMode(false); }}
          >${fmtMonthShort(new Date(disp.year, i, 1))}</button>`)}
        </div>`
      : html`<div class="bc-jump-grid" role="grid" aria-label="Days">
          ${weekdayHeads().map((w, i) => html`<span key=${'h' + i} class="bc-jump-dow" aria-hidden="true">${w}</span>`)}
          ${weeks.map((row) => row.map((key) => html`<button
            key=${key} type="button"
            class="bc-jump-day${key.startsWith(monthPrefix) ? '' : ' is-out'}${key === tKey ? ' is-today' : ''}${key === state.anchor ? ' is-sel' : ''}"
            aria-label=${'Jump to ' + fmtDayLong(dateOfDayKey(key))}
            onClick=${() => go(key)}
          >${Number(key.slice(8, 10))}</button>`))}
        </div>`}

    <div class="bc-jump-foot">
      <button type="button" class="bc-link-btn" onClick=${() => go(tKey)}>Today</button>
      <button type="button" class="bc-btn bc-jump-closefoot" onClick=${() => set({ jumpOpen: false })}>Close</button>
    </div>
  </div>`;
}
