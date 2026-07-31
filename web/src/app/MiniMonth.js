// Sidebar mini-month: always-visible orientation plus one-click navigation
// (a Google Calendar staple). Follows the main view's visible month; the
// arrows step locally and re-sync when the main view moves. Clicking a day
// jumps the calendar there; clicking the title opens the jump popover.
// Days with events on visible calendars get a subtle dot. Desktop only:
// hidden in the mobile drawer via CSS.

import { html, useState, useMemo, useEffect } from '../../vendor/index.js';
import { useStore, set, state, shallowEq } from './store.js';
import { jumpToDate } from './actions.js';
import { monthWeeks, stepMonthOf, weekdayHeads } from '../lib/minimonth.js';
import {
  todayKey, dateOfDayKey, fmtMonthYear, fmtDayLong, pad,
  epochDayOfKey, keyOfEpochDay,
} from '../lib/dates.js';
import { occurrenceDaySpan } from '../ui/monthmath.js';

export function MiniMonth() {
  const s = useStore(
    (st) => ({
      anchor: st.anchor, visibleMonth: st.visibleMonth,
      occVersion: st.occVersion, calendars: st.calendars,
    }),
    shallowEq,
  );
  const base = s.visibleMonth ||
    { year: Number(s.anchor.slice(0, 4)), month: Number(s.anchor.slice(5, 7)) };
  const [disp, setDisp] = useState(null); // local stepping override

  // The main view moved: drop any local stepping and follow it again.
  useEffect(() => { setDisp(null); }, [base.year * 12 + base.month]); // eslint-disable-line

  const shown = disp || base;
  const weeks = useMemo(() => monthWeeks(shown.year, shown.month), [shown.year, shown.month]);

  // Days (within the shown grid) that have at least one visible occurrence.
  const dotDays = useMemo(() => {
    const firstEd = epochDayOfKey(weeks[0][0]);
    const lastEd = epochDayOfKey(weeks[weeks.length - 1][6]);
    const visible = new Set(s.calendars.filter((c) => c.visible).map((c) => c.id));
    const dots = new Set();
    for (const occ of state.occ.values()) {
      if (!visible.has(occ.calendarId) || occ.attendance === 'hidden') continue;
      const { startKey, endKey } = occurrenceDaySpan(occ);
      let ed = Math.max(epochDayOfKey(startKey), firstEd);
      const end = Math.min(epochDayOfKey(endKey), lastEd);
      for (; ed <= end; ed++) dots.add(keyOfEpochDay(ed));
    }
    return dots;
  }, [weeks, s.occVersion, s.calendars]);

  const tKey = todayKey();
  const monthPrefix = shown.year + '-' + pad(shown.month);

  return html`<div class="bc-minimonth">
    <div class="bc-mm-head">
      <button
        type="button" class="bc-mm-title"
        title="Jump to date (g)" aria-haspopup="dialog"
        onClick=${() => set({ jumpOpen: true })}
      >${fmtMonthYear(dateOfDayKey(monthPrefix + '-01'))}</button>
      <button type="button" class="bc-icon-btn bc-mm-step" aria-label="Previous month" onClick=${() => setDisp(stepMonthOf(shown.year, shown.month, -1))}>‹</button>
      <button type="button" class="bc-icon-btn bc-mm-step" aria-label="Next month" onClick=${() => setDisp(stepMonthOf(shown.year, shown.month, 1))}>›</button>
    </div>
    <div class="bc-mm-grid" role="grid" aria-label=${'Days of ' + fmtMonthYear(dateOfDayKey(monthPrefix + '-01'))}>
      ${weekdayHeads().map((w, i) => html`<span key=${'h' + i} class="bc-mm-dow" aria-hidden="true">${w}</span>`)}
      ${weeks.map((row) => row.map((key) => html`<button
        key=${key} type="button"
        class=${'bc-mm-day' +
          (key.startsWith(monthPrefix) ? '' : ' is-out') +
          (key === tKey ? ' is-today' : '') +
          (key === s.anchor ? ' is-sel' : '') +
          (dotDays.has(key) ? ' has-dot' : '')}
        aria-label=${'Go to ' + fmtDayLong(dateOfDayKey(key))}
        onClick=${() => jumpToDate(key)}
      >${Number(key.slice(8, 10))}</button>`))}
    </div>
  </div>`;
}
