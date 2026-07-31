// Shared mini month-grid math for the jump popover and the sidebar
// mini-month. Pure day-key/epoch-day arithmetic (no DOM, no preact), so the
// smoke test exercises it directly. Respects the configured week start.

import {
  pad, epochDayOfKey, keyOfEpochDay, dateOfDayKey,
  weekIndexOfEpochDay, firstEpochDayOfWeek, getWeekStart,
} from './dates.js';

// Week rows (configured week start) covering a month, padded with
// adjacent-month days. Each row is 7 day keys.
export function monthWeeks(year, month) {
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

// {year, month} stepped by n months, rolling across year boundaries.
export function stepMonthOf(year, month, n) {
  const k = year * 12 + (month - 1) + n;
  return { year: Math.floor(k / 12), month: ((k % 12) + 12) % 12 + 1 };
}

// Single-letter day headers in week-start order, cached per start day.
let headCache = { start: null, heads: [] };
export function weekdayHeads() {
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
