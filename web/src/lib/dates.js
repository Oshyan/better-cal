// Minimal date helpers. No external date library.
// Day keys are 'YYYY-MM-DD' strings in the user's local timezone.
// Epoch days are integer day counts since 1970-01-01 (local calendar days).
// Week indexes are integers over Monday-started weeks; week 0 contains 1969-12-29.

const DAY_MS = 86400000;

export function pad(n, w = 2) {
  return String(n).padStart(w, '0');
}

// Parse ISO8601 (with or without offset) into a Date. Date() handles offsets.
export function parseISO(s) {
  return new Date(s);
}

// Format a Date as ISO8601 with the local timezone offset:
// 2026-07-30T14:30:00-07:00
export function toISOWithOffset(d) {
  const off = -d.getTimezoneOffset();
  const sign = off < 0 ? '-' : '+';
  const abs = Math.abs(off);
  return (
    d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
    'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()) +
    sign + pad(Math.floor(abs / 60)) + ':' + pad(abs % 60)
  );
}

// --- day keys -------------------------------------------------------------

export function dayKeyOf(d) {
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

export function dayKeyOfISO(iso) {
  return dayKeyOf(parseISO(iso));
}

// Day key for an occurrence. All-day events are calendar dates (the server
// serializes them as literal dates at +00:00); read the date portion verbatim,
// never through local-timezone conversion, or the date shifts west of UTC.
export function occDayKey(occ) {
  return occ.allDay ? occ.start.slice(0, 10) : dayKeyOfISO(occ.start);
}

export function todayKey() {
  return dayKeyOf(new Date());
}

// Local midnight Date for a day key.
export function dateOfDayKey(key) {
  const [y, m, d] = key.split('-').map(Number);
  return new Date(y, m - 1, d);
}

// Integer calendar-day count since 1970-01-01 for a day key.
// Uses UTC arithmetic on the key parts so DST cannot skew the count.
export function epochDayOfKey(key) {
  const [y, m, d] = key.split('-').map(Number);
  return Math.round(Date.UTC(y, m - 1, d) / DAY_MS);
}

export function keyOfEpochDay(n) {
  const d = new Date(n * DAY_MS);
  return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
}

export function addDaysKey(key, n) {
  return keyOfEpochDay(epochDayOfKey(key) + n);
}

export function diffDaysKey(a, b) {
  return epochDayOfKey(a) - epochDayOfKey(b);
}

// Add days to a Date preserving wall-clock time (DST-safe for calendar moves).
export function addDaysDate(d, n) {
  const r = new Date(d);
  r.setDate(r.getDate() + n);
  return r;
}

export function addMinutesDate(d, n) {
  return new Date(d.getTime() + n * 60000);
}

// --- weeks (Monday start) -------------------------------------------------

// 1970-01-01 was a Thursday; Monday of that week is 1969-12-29 (epoch day -3).
export function weekIndexOfEpochDay(ed) {
  return Math.floor((ed + 3) / 7);
}

export function weekIndexOfKey(key) {
  return weekIndexOfEpochDay(epochDayOfKey(key));
}

// First (Monday) epoch day of a week index.
export function firstEpochDayOfWeek(wi) {
  return wi * 7 - 3;
}

export function dayKeysOfWeek(wi) {
  const first = firstEpochDayOfWeek(wi);
  const out = [];
  for (let i = 0; i < 7; i++) out.push(keyOfEpochDay(first + i));
  return out;
}

export function startOfWeekKey(key) {
  return keyOfEpochDay(firstEpochDayOfWeek(weekIndexOfKey(key)));
}

// --- formatting via Intl --------------------------------------------------

const timeFmt = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' });
const timeShortFmt = new Intl.DateTimeFormat(undefined, { hour: 'numeric' });
const monthYearFmt = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' });
const monthShortFmt = new Intl.DateTimeFormat(undefined, { month: 'short' });
const weekdayShortFmt = new Intl.DateTimeFormat(undefined, { weekday: 'short' });
const dayLongFmt = new Intl.DateTimeFormat(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
const dayMedFmt = new Intl.DateTimeFormat(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
const dateShortFmt = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' });
const dateFullFmt = new Intl.DateTimeFormat(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });

export function fmtTime(d) { return timeFmt.format(d).replace(/\s/g, ' '); }
export function fmtHour(d) { return timeShortFmt.format(d); }
export function fmtMonthYear(d) { return monthYearFmt.format(d); }
export function fmtMonthShort(d) { return monthShortFmt.format(d); }
export function fmtWeekdayShort(d) { return weekdayShortFmt.format(d); }
export function fmtDayLong(d) { return dayLongFmt.format(d); }
export function fmtDayMedium(d) { return dayMedFmt.format(d); }
export function fmtDateShort(d) { return dateShortFmt.format(d); }
export function fmtDateFull(d) { return dateFullFmt.format(d); }

export function localTz() {
  return Intl.DateTimeFormat().resolvedOptions().timeZone;
}

// Range like "7:00 - 8:30 PM" or "Jul 3 - Jul 5" for the popover.
export function fmtRange(start, end, allDay) {
  const sk = dayKeyOf(start);
  const ek = dayKeyOf(end);
  if (allDay) {
    const lastKey = dayKeyOf(new Date(end.getTime() - 1));
    if (sk === lastKey) return fmtDateFull(start);
    return fmtDateShort(start) + ' to ' + fmtDateShort(dateOfDayKey(lastKey));
  }
  if (sk === ek) return fmtDayMedium(start) + ', ' + fmtTime(start) + ' to ' + fmtTime(end);
  return fmtDayMedium(start) + ' ' + fmtTime(start) + ' to ' + fmtDayMedium(end) + ' ' + fmtTime(end);
}

// datetime-local input value (no offset), local time.
export function toInputValue(d) {
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
    'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}

export function fromInputValue(v) {
  return new Date(v);
}

// Snap a Date to the nearest `mins` minutes.
export function snapDate(d, mins) {
  const ms = mins * 60000;
  return new Date(Math.round(d.getTime() / ms) * ms);
}
