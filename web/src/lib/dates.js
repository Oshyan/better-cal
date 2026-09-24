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

let timeFmtSetting = '';
const zoneTimeFmts = new Map();
function tfOpts(tf) {
  return tf === '24' ? { hour12: false } : tf === '12' ? { hour12: true } : {};
}

export function setTimeFormat(tf) {
  timeFmtSetting = tf;
  zoneTimeFmts.clear();
  const opts = tfOpts(tf);
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
/** The clock time in a given zone (the user's time format), or the local time when the zone is unknown. */
export function fmtTimeIn(d, tz) {
  if (!tz) return fmtTime(d);
  let f = zoneTimeFmts.get(tz);
  if (!f) {
    try {
      f = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit', timeZone: tz, ...tfOpts(timeFmtSetting) });
    } catch {
      return fmtTime(d);
    }
    zoneTimeFmts.set(tz, f);
  }
  return f.format(d).replace(/\s/g, ' ');
}

/** "PDT", "BST", "CET"; "UTC-7" where the browser has no name for the zone at that instant. */
export function tzAbbrev(tz, at = new Date()) {
  try {
    const p = new Intl.DateTimeFormat('en-US', { timeZone: tz, timeZoneName: 'short' }).formatToParts(at).find((x) => x.type === 'timeZoneName');
    return p ? p.value.replace(/^GMT([+-])/, 'UTC$1') : '';
  } catch {
    return '';
  }
}
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

// --- Home zone vs this device's zone ----------------------------------------
// The screen always follows the device. settings.tz is the owner's HOME zone,
// which the server uses where there is no device to ask: the clock all-day
// reminders fire on, and the zone of events created for them by email ingest,
// plugins and API calls. The two differ whenever the owner travels.

// Minutes east of UTC for an IANA zone at an instant; null for an unknown zone.
export function tzOffsetMinutes(tz, at = new Date()) {
  try {
    const name = new Intl.DateTimeFormat('en-US', { timeZone: tz, timeZoneName: 'longOffset' })
      .formatToParts(at).find((p) => p.type === 'timeZoneName').value; // "GMT-07:00", or "GMT" for UTC
    const m = /GMT([+-])(\d{1,2})(?::?(\d{2}))?/.exec(name);
    if (!m) return 0;
    return (m[1] === '-' ? -1 : 1) * (Number(m[2]) * 60 + Number(m[3] || 0));
  } catch {
    return null;
  }
}

// "UTC-7", "UTC+5:30", "UTC".
export function tzOffsetLabel(tz, at = new Date()) {
  const off = tzOffsetMinutes(tz, at);
  if (off == null) return '';
  if (off === 0) return 'UTC';
  const abs = Math.abs(off);
  return 'UTC' + (off < 0 ? '-' : '+') + Math.floor(abs / 60) + (abs % 60 ? ':' + pad(abs % 60) : '');
}

// "America/Los_Angeles" -> "Los Angeles". The city is what people recognize.
export function tzCity(tz) {
  return String(tz || '').split('/').pop().replace(/_/g, ' ');
}

// [[zone, "America/Los Angeles (UTC-7)"], ...] for a zone <select>: every zone
// the browser knows plus any passed in (a stored zone an older browser lacks).
// Built once per session: each label costs an Intl formatter and there are
// about 420 zones, so callers should not build this per render.
let zoneOptionCache = null;
export function zoneOptions(extra = []) {
  if (!zoneOptionCache) {
    let all = [];
    try { all = Intl.supportedValuesOf('timeZone'); } catch { /* older browser: only the extras */ }
    const now = new Date();
    zoneOptionCache = new Map([...new Set(['UTC', ...all])].map((z) => [z, z.replace(/_/g, ' ') + ' (' + tzOffsetLabel(z, now) + ')']));
  }
  for (const z of extra) {
    if (z && !zoneOptionCache.has(z)) zoneOptionCache.set(z, z.replace(/_/g, ' ') + ' (' + tzOffsetLabel(z) + ')');
  }
  return [...zoneOptionCache.entries()].sort((a, b) => a[0].localeCompare(b[0]));
}

// --- wall-clock time in a named zone -----------------------------------------
// A datetime-local value ("2026-06-02T15:00") is a wall-clock reading with no
// zone. These two say which instant it is when read in a given IANA zone, and
// the reverse, so the editor can take "3 PM New York" on a laptop in London.

// The instant at which the clock in `tz` reads the given wall time.
export function instantFromWallTime(value, tz) {
  const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(String(value || ''));
  if (!m) return new Date(NaN);
  const asUtc = Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
  const first = tzOffsetMinutes(tz, new Date(asUtc));
  if (first == null) return new Date(NaN);
  // The offset was read at the wrong instant (the wall time taken as UTC), so
  // read it again at the corrected one and, if it changed, check the answer
  // that gives. It holds unless the wall time falls in a DST gap and does not
  // exist; then the first guess stands, which lands an hour later, as every
  // calendar does (2:30 AM on spring-forward night becomes 3:30).
  const guess = asUtc - first * 60000;
  const second = tzOffsetMinutes(tz, new Date(guess));
  if (second === first) return new Date(guess);
  const settled = asUtc - second * 60000;
  return new Date(tzOffsetMinutes(tz, new Date(settled)) === second ? settled : guess);
}

// The reverse: a datetime-local value for what the clock in `tz` reads at `date`.
export function wallTimeInZone(date, tz) {
  const off = tzOffsetMinutes(tz, date);
  if (off == null || isNaN(date)) return '';
  const d = new Date(date.getTime() + off * 60000);
  return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()) +
    'T' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes());
}

// "Thu, Jun 4, 3:00 PM to 4:00 PM in New York" for a timed event whose own zone
// keeps a different clock from this device; '' otherwise. UTC is what imports
// write when the zone is unknown, so it is never announced as a place.
export function zoneNote(occ) {
  if (!occ || occ.allDay || !occ.tzid || occ.tzid === 'UTC' || sameClock(occ.tzid, localTz())) return '';
  // Reuse the local formatters (and the 12/24-hour setting they follow) by
  // shifting the instant so that the device's clock reads what the zone's does.
  const inZone = (iso) => {
    const d = new Date(iso);
    const off = tzOffsetMinutes(occ.tzid, d);
    return off == null || isNaN(d) ? null : new Date(d.getTime() + (off + d.getTimezoneOffset()) * 60000);
  };
  const s = inZone(occ.start);
  const e = inZone(occ.end);
  return s && e ? fmtRange(s, e, false) + ' in ' + tzCity(occ.tzid) : '';
}

// Do two zones keep the same clock? Names alone over-report: a browser in
// Vancouver or Tijuana says so, and is on Los Angeles time all year. Compared
// now and half a year on, so zones that agree only until a DST change differ.
export function sameClock(a, b, at = new Date()) {
  if (!a || !b || a === b) return true;
  const later = new Date(at.getTime() + 182 * 86400000);
  const oa = [tzOffsetMinutes(a, at), tzOffsetMinutes(a, later)];
  const ob = [tzOffsetMinutes(b, at), tzOffsetMinutes(b, later)];
  if (oa.includes(null) || ob.includes(null)) return true; // unknown zone: nothing useful to say
  return oa[0] === ob[0] && oa[1] === ob[1];
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

// All-day events are stored with an exclusive end: Sep 12 to Sep 27 is
// start 09-12, end 09-28. People mean the last day, so date fields show
// that (#34: the editors used to show "09/28/2026, 12:00 AM").
//
// From the editors' datetime-local values (exclusive end) to the two date
// fields (inclusive end, never before the start).
export function allDayFields(startInput, endInput) {
  const start = String(startInput || '').slice(0, 10);
  let end = String(endInput || '').slice(0, 10);
  if (/^\d{4}-\d{2}-\d{2}$/.test(end)) {
    // An end at midnight is exclusive: the last day is the one before. An end
    // later in a day (a timed event just switched to all-day) is its own day.
    const t = String(endInput).slice(11, 16);
    if (!t || t === '00:00') end = addDaysKey(end, -1);
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(end) || end < start) end = start;
  return { start, end };
}

// And back: two date fields (inclusive end) to datetime-local values at local
// midnight with the exclusive end the server stores.
export function allDayInputs(startKey, lastKey) {
  const last = lastKey && lastKey >= startKey ? lastKey : startKey;
  return { start: startKey + 'T00:00', end: addDaysKey(last, 1) + 'T00:00' };
}

// Snap a Date to the nearest `mins` minutes.
export function snapDate(d, mins) {
  const ms = mins * 60000;
  return new Date(Math.round(d.getTime() / ms) * ms);
}
