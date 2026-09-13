// Minimal date helpers. No external date library.
// Day keys are 'YYYY-MM-DD' strings in the user's local timezone.
// Epoch days are integer day counts since 1970-01-01 (local calendar days).
// Week indexes are integers over weeks starting on the configured first day
// (setWeekStart, default Monday); week 0 contains the start-of-week nearest
// before 1970-01-01.

const DAY_MS = 86400000;

export function pad(n, w = 2) {
  return String(n).padStart(w, '0');
}

// Parse ISO8601 (with or without offset) into a Date. Date() handles offsets.
export function parseISO(s) {
  return new Date(s);
}

// Chronological comparator for occurrences. Start strings carry each event's
// OWN timezone offset (an imported UTC-tzid event serializes "+00:00" while
// a local one serializes "-07:00"), so comparing them as strings orders
// 10 AM after 12:30 PM. Always compare instants.
export function startMs(occ) {
  const t = new Date(occ.start).getTime();
  return Number.isNaN(t) ? 0 : t;
}

export function byStart(a, b) {
  return startMs(a) - startMs(b);
}

// Same rule for the far edge: an occurrence's end carries its own offset too.
export function endMs(occ) {
  const t = new Date(occ.end).getTime();
  return Number.isNaN(t) ? 0 : t;
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

// 'past' | 'now' | 'future' for an occurrence at the instant nowMs.
// Timed events compare real instants: past once end <= now, active while
// start <= now < end. All-day events are calendar dates (literal date
// serialization, see occDayKey): read the date portions verbatim — never
// timezone-convert — and compare against the local day of nowMs. The all-day
// end date is exclusive, so an event whose end date is today ended yesterday.
export function timeState(occ, nowMs) {
  if (occ.allDay) {
    const today = dayKeyOf(new Date(nowMs));
    const startKey = occ.start.slice(0, 10);
    const endKey = occ.end ? occ.end.slice(0, 10) : addDaysKey(startKey, 1);
    if (endKey <= today) return 'past';
    if (startKey > today) return 'future';
    return 'now';
  }
  const s = parseISO(occ.start).getTime();
  const e = occ.end ? parseISO(occ.end).getTime() : s;
  if (e <= nowMs) return 'past';
  if (s <= nowMs) return 'now';
  return 'future';
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

// Total duration of one occurrence, never of a clipped grid segment or a
// recurrence series. All-day dates have an exclusive end and ignore offsets;
// timed events measure elapsed time, including DST and timezone changes.
// Short events and synthetic groups have no multi-day duration decoration.
export function eventDuration(occ) {
  if (!occ || occ.isGroup || !occ.start || !occ.end) return null;
  let seconds;
  if (occ.allDay) {
    const startKey = occ.start.slice(0, 10);
    const endKey = occ.end.slice(0, 10);
    const validKey = (key) => /^\d{4}-\d{2}-\d{2}$/.test(key)
      && Number.isFinite(epochDayOfKey(key))
      && keyOfEpochDay(epochDayOfKey(key)) === key;
    if (!validKey(startKey) || !validKey(endKey)) return null;
    const days = diffDaysKey(endKey, startKey);
    if (days < 2) return null;
    seconds = days * 86400;
  } else {
    seconds = (parseISO(occ.end) - parseISO(occ.start)) / 1000;
    if (!Number.isFinite(seconds) || seconds < 86400) return null;
  }

  const days = Math.floor(seconds / 86400);
  let rest = seconds % 86400;
  const parts = [[days, 'd', 'day']];
  for (const [size, short, word] of [[3600, 'h', 'hour'], [60, 'm', 'minute'], [1, 's', 'second']]) {
    const n = size === 1 ? Number(rest.toFixed(3)) : Math.floor(rest / size);
    if (n) parts.push([n, short, word]);
    rest %= size;
  }
  // Month shorthand deliberately wins over 4w at 28 days. Other spans only
  // use weeks when exact; no rounding, decimals, or mixed weeks and days.
  const compact = seconds % 86400 === 0
    ? (days >= 28 && days <= 31 ? '1mo' : days % 7 === 0 ? `${days / 7}w` : `${days}d`)
    : parts.map(([n, short]) => `${n}${short}`).join(' ');
  const exact = parts.map(([n, , word]) => `${n} ${word}${n === 1 ? '' : 's'}`).join(', ');
  return { compact, exact };
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

// --- weeks (configurable start day) ---------------------------------------

// 1970-01-01 was a Thursday; the Monday of that week is 1969-12-29 (epoch
// day -3) and the Sunday is 1969-12-28 (epoch day -4). weekAnchor is that
// negated offset, so all week math pivots on one number. Settings thread it
// in at boot via setWeekStart; the default matches the historical behavior.
let weekAnchor = 3; // 3 = Monday start, 4 = Sunday start

export function setWeekStart(ws) {
  weekAnchor = ws === 'sun' ? 4 : 3;
}

export function getWeekStart() {
  return weekAnchor === 4 ? 'sun' : 'mon';
}

export function weekIndexOfEpochDay(ed) {
  return Math.floor((ed + weekAnchor) / 7);
}

export function weekIndexOfKey(key) {
  return weekIndexOfEpochDay(epochDayOfKey(key));
}

// First epoch day (configured start day) of a week index.
export function firstEpochDayOfWeek(wi) {
  return wi * 7 - weekAnchor;
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

// Time formatters honor the 12/24-hour setting (setTimeFormat, from user
// settings at boot); everything else follows the locale.
let timeFmt = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' });
let timeShortFmt = new Intl.DateTimeFormat(undefined, { hour: 'numeric' });

export function setTimeFormat(tf) {
  const opts = tf === '24' ? { hour12: false } : tf === '12' ? { hour12: true } : {};
  timeFmt = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit', ...opts });
  timeShortFmt = new Intl.DateTimeFormat(undefined, { hour: 'numeric', ...opts });
}

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
