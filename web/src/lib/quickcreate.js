// Pure selection-range math for the two-step create flow (confirm chips in
// the month/3-day and week views). Kept DOM-free so the smoke test covers it.

import {
  epochDayOfKey, keyOfEpochDay, dateOfDayKey, toISOWithOffset, addDaysDate,
  fmtMonthShort, fmtTime,
} from './dates.js';

// Order two day keys into an inclusive range (drag direction agnostic).
export function normalizeDayRange(aKey, bKey) {
  const a = epochDayOfKey(aKey);
  const b = epochDayOfKey(bKey);
  const lo = Math.min(a, b);
  const hi = Math.max(a, b);
  return { startKey: keyOfEpochDay(lo), endKey: keyOfEpochDay(hi), days: hi - lo + 1 };
}

// Editor draft for a day-range selection: single day keeps the existing
// default (1-hour timed event at 9am); multi-day becomes an all-day span
// with the exclusive end the editor expects.
export function dayRangeDraft(startKey, endKey) {
  if (startKey === endKey) {
    const base = dateOfDayKey(startKey);
    const s = new Date(base.getFullYear(), base.getMonth(), base.getDate(), 9, 0);
    return {
      start: toISOWithOffset(s),
      end: toISOWithOffset(new Date(s.getTime() + 3600000)),
      allDay: false,
    };
  }
  return {
    start: toISOWithOffset(dateOfDayKey(startKey)),
    end: toISOWithOffset(addDaysDate(dateOfDayKey(endKey), 1)),
    allDay: true,
  };
}

// Human label for the chip: "Aug 4", "Mar 1 to 15", "Mar 28 to Apr 2".
export function dayRangeLabel(startKey, endKey) {
  const s = dateOfDayKey(startKey);
  const head = fmtMonthShort(s) + ' ' + s.getDate();
  if (startKey === endKey) return head;
  const e = dateOfDayKey(endKey);
  const sameMonth = s.getMonth() === e.getMonth() && s.getFullYear() === e.getFullYear();
  return head + ' to ' + (sameMonth ? '' : fmtMonthShort(e) + ' ') + e.getDate();
}

// Human label for a timed draft: "2:00 PM to 3:30 PM" (honors the time
// format setting through fmtTime).
export function timeRangeLabel(dayKey, startMin, endMin) {
  const base = dateOfDayKey(dayKey);
  const at = (m) => new Date(base.getFullYear(), base.getMonth(), base.getDate(), 0, m);
  return fmtTime(at(startMin)) + ' to ' + fmtTime(at(endMin));
}

// Clamp a chip of size w x h anchored near pointer (x, y) into the viewport.
export function chipPosition(x, y, w, h, vw, vh, margin = 8) {
  return {
    x: Math.max(margin, Math.min(x, vw - w - margin)),
    y: Math.max(margin, Math.min(y, vh - h - margin)),
  };
}
