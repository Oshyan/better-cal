// Repeat rules (RFC 5545 RRULE text, without the "RRULE:" prefix) in words,
// the presets a date offers, and the end of a series. Shared by quick add's
// repeat chip (#165), the full editor and the event views. Pure, no DOM.
//
// The presets follow Google Calendar's menu: each is built from the event's
// date ("Weekly on Thursday", "Monthly on the fourth Thursday"), plus day N of
// the month and every two weeks, which Apple and Fantastical also offer.

import { fmtDateFull, untilDayKey, dateOfDayKey, pad, instantFromWallTime } from './dates.js';

export const DAY_CODES = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA']; // Date#getDay order
const DAY_NAMES = { MO: 'Monday', TU: 'Tuesday', WE: 'Wednesday', TH: 'Thursday', FR: 'Friday', SA: 'Saturday', SU: 'Sunday' };
const DAY_SHORT = { MO: 'Mon', TU: 'Tue', WE: 'Wed', TH: 'Thu', FR: 'Fri', SA: 'Sat', SU: 'Sun' };
const ORDINAL = { 1: 'first', 2: 'second', 3: 'third', 4: 'fourth', 5: 'fifth', '-1': 'last' };
const WEEKDAYS = 'MO,TU,WE,TH,FR';
const WEEKEND = 'SA,SU';
// The parts the words and presets understand; anything else (BYMONTH,
// BYYEARDAY, ...) is a rule only the full editor's custom view can carry.
const KNOWN = new Set(['FREQ', 'INTERVAL', 'BYDAY', 'BYMONTHDAY', 'BYSETPOS', 'UNTIL', 'COUNT', 'WKST']);
const ORDER = ['FREQ', 'INTERVAL', 'BYDAY', 'BYMONTHDAY', 'BYSETPOS'];

// "FREQ=WEEKLY;BYDAY=TU" -> { FREQ: 'WEEKLY', BYDAY: 'TU' }.
export function ruleParts(rrule) {
  const parts = {};
  for (const piece of String(rrule || '').replace(/^RRULE:/i, '').split(';')) {
    const at = piece.indexOf('=');
    if (at > 0) parts[piece.slice(0, at).trim().toUpperCase()] = piece.slice(at + 1).trim().toUpperCase();
  }
  return parts;
}

// Parts back to text: the rule first in a fixed order, then the rest, then its end.
export function joinRule(parts) {
  const keys = Object.keys(parts).filter((k) => parts[k] !== '' && parts[k] != null);
  const rest = keys.filter((k) => !ORDER.includes(k) && k !== 'UNTIL' && k !== 'COUNT');
  return [...ORDER.filter((k) => keys.includes(k)), ...rest, ...['COUNT', 'UNTIL'].filter((k) => keys.includes(k))]
    .map((k) => k + '=' + parts[k]).join(';');
}

// The rule without its end, for comparing a rule with a preset.
export function ruleBase(rrule) {
  const p = ruleParts(rrule);
  delete p.UNTIL;
  delete p.COUNT;
  return joinRule(p);
}

// Whether the words and presets cover every part of the rule.
export function describable(rrule) {
  const p = ruleParts(rrule);
  return !!p.FREQ && ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'].includes(p.FREQ) && Object.keys(p).every((k) => KNOWN.has(k));
}

const lowerFirst = (s) => s.charAt(0).toLowerCase() + s.slice(1);
const dayList = (codes, short) => {
  const names = codes.map((c) => (short || codes.length > 2 ? DAY_SHORT : DAY_NAMES)[c]).filter(Boolean);
  return names.length > 1 ? names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1] : names[0] || '';
};
const monthDay = (d) => d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });

/**
 * A rule in words: "Weekly on Tuesday", "Every weekday", "Monthly on the
 * first Monday", "Every 2 weeks on Mon, Wed and Fri", with its end
 * (", until Dec 15, 2026", ", 10 times"). `start` (a Date) fills in what a
 * bare rule takes from its first day ("Weekly on Thursday", "Yearly on Dec
 * 23"); `short` uses short day names. Null for a rule it can't put in words.
 */
export function describeRepeat(rrule, { start = null, short = false, ends = true } = {}) {
  if (!rrule || !describable(rrule)) return null;
  const p = ruleParts(rrule);
  const n = parseInt(p.INTERVAL || '1', 10) || 1;
  // Some apps write "every weekday" as a daily rule on those days.
  if (p.FREQ === 'DAILY' && p.BYDAY && n === 1 && !p.BYMONTHDAY && !p.BYSETPOS) p.FREQ = 'WEEKLY';
  const every = (unit, adverb) => (n === 1 ? adverb : `Every ${n} ${unit}s`);
  const startCode = start ? DAY_CODES[start.getDay()] : null;
  let text;
  if (p.FREQ === 'DAILY') {
    if (p.BYDAY || p.BYMONTHDAY || p.BYSETPOS) return null;
    text = every('day', 'Daily');
  } else if (p.FREQ === 'WEEKLY') {
    if (p.BYMONTHDAY || p.BYSETPOS) return null;
    const codes = (p.BYDAY || startCode || '').split(',').filter(Boolean);
    if (codes.some((c) => !DAY_NAMES[c])) return null;
    const set = [...codes].sort((a, b) => DAY_CODES.indexOf(a) - DAY_CODES.indexOf(b)).join(',');
    if (n === 1 && set === 'MO,TU,WE,TH,FR') text = 'Every weekday';
    else if (n === 1 && set === 'SU,SA') text = 'Every weekend';
    else text = every('week', 'Weekly') + (codes.length ? ' on ' + dayList(codes, short) : '');
  } else if (p.FREQ === 'MONTHLY') {
    text = every('month', 'Monthly');
    const byday = (p.BYDAY || '').split(',').filter(Boolean);
    if (p.BYMONTHDAY) {
      if (byday.length || p.BYSETPOS || p.BYMONTHDAY.includes(',')) return null;
      const d = parseInt(p.BYMONTHDAY, 10);
      if (d === -1) text += ' on the last day';
      else if (d >= 1 && d <= 31) text += ' on day ' + d;
      else return null;
    } else if (p.BYSETPOS) {
      const pos = ORDINAL[parseInt(p.BYSETPOS, 10)];
      const set = byday.join(',');
      if (!pos) return null;
      if (set === WEEKDAYS) text += ` on the ${pos} weekday`;
      else if (set === WEEKEND) text += ` on the ${pos} weekend day`;
      else if (byday.length === 1 && DAY_NAMES[byday[0]]) text += ` on the ${pos} ${DAY_NAMES[byday[0]]}`;
      else return null;
    } else if (byday.length) {
      const m = /^([+-]?\d)([A-Z]{2})$/.exec(byday[0]);
      if (byday.length !== 1 || !m || !ORDINAL[parseInt(m[1], 10)] || !DAY_NAMES[m[2]]) return null;
      text += ` on the ${ORDINAL[parseInt(m[1], 10)]} ${DAY_NAMES[m[2]]}`;
    } else if (start) {
      text += ' on day ' + start.getDate();
    }
  } else {
    if (p.BYDAY || p.BYMONTHDAY || p.BYSETPOS) return null;
    text = every('year', 'Yearly') + (start ? ' on ' + monthDay(start) : '');
  }
  if (!ends) return text;
  if (p.COUNT && /^\d+$/.test(p.COUNT)) text += `, ${p.COUNT} times`;
  else if (p.UNTIL && /^\d{8}/.test(p.UNTIL)) {
    const last = dateOfDayKey(untilDayKey(p.UNTIL));
    // The chip's short form: "until Dec 15", with the year only when it isn't this one.
    text += ', until ' + (!short ? fmtDateFull(last)
      : last.getFullYear() === new Date().getFullYear() ? monthDay(last)
        : last.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }));
  }
  return text;
}

// The event views' line: "Repeats weekly on Monday", or plain "Repeats".
export function repeatSentence(rrule, opts) {
  const words = rrule ? describeRepeat(rrule, opts) : null;
  return words ? 'Repeats ' + lowerFirst(words) : 'Repeats';
}

// Which week of its month a day falls in (1-5), and whether it is the last.
export function weekOfMonth(d) {
  const nth = Math.ceil(d.getDate() / 7);
  const last = d.getDate() + 7 > new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
  return { nth, last };
}

/**
 * The repeat menu for a day (a Date): [{key, label, rrule}], "Does not
 * repeat" first. A fifth weekday only offers "last"; a fourth that is also
 * the last offers both, as Google does.
 */
export function repeatPresets(d) {
  const code = DAY_CODES[d.getDay()];
  const name = DAY_NAMES[code];
  const { nth, last } = weekOfMonth(d);
  const out = [
    { key: 'none', label: 'Does not repeat', rrule: '' },
    { key: 'daily', label: 'Daily', rrule: 'FREQ=DAILY' },
    { key: 'weekdays', label: 'Every weekday (Mon to Fri)', rrule: 'FREQ=WEEKLY;BYDAY=' + WEEKDAYS },
    { key: 'weekly', label: 'Weekly on ' + name, rrule: 'FREQ=WEEKLY;BYDAY=' + code },
    { key: 'biweekly', label: 'Every 2 weeks on ' + name, rrule: 'FREQ=WEEKLY;INTERVAL=2;BYDAY=' + code },
  ];
  if (nth <= 4) out.push({ key: 'monthly-nth', label: `Monthly on the ${ORDINAL[nth]} ${name}`, rrule: `FREQ=MONTHLY;BYDAY=${nth}${code}` });
  if (last) out.push({ key: 'monthly-last', label: `Monthly on the last ${name}`, rrule: `FREQ=MONTHLY;BYDAY=-1${code}` });
  out.push({ key: 'monthly-day', label: 'Monthly on day ' + d.getDate(), rrule: 'FREQ=MONTHLY;BYMONTHDAY=' + d.getDate() });
  out.push({ key: 'yearly', label: 'Yearly on ' + monthDay(d), rrule: 'FREQ=YEARLY' });
  return out;
}

// The preset a rule is (ignoring its end) for a day, or null.
export function presetOf(rrule, d) {
  const base = ruleBase(rrule);
  return repeatPresets(d).find((p) => p.rrule === base) || null;
}

/**
 * A series end as an UNTIL value. A date series ends on a date (RFC 5545:
 * UNTIL takes DTSTART's type); a timed one at the end of that day on the
 * event's clock, `tz` when it has its own zone, else this device's.
 */
export function untilValue(dayKey, allDay, tz = null) {
  if (allDay) return dayKey.replace(/-/g, '');
  const d = tz ? instantFromWallTime(dayKey + 'T23:59', tz) : new Date(dayKey + 'T23:59:59');
  return d.getUTCFullYear() + pad(d.getUTCMonth() + 1) + pad(d.getUTCDate()) + 'T' + pad(d.getUTCHours()) + pad(d.getUTCMinutes()) + '00Z';
}

// The rule's end: { kind: 'never' | 'until' | 'count', until: 'YYYY-MM-DD', count }.
export function repeatEnd(rrule, tz = null) {
  const p = ruleParts(rrule);
  if (p.COUNT && /^\d+$/.test(p.COUNT)) return { kind: 'count', until: '', count: parseInt(p.COUNT, 10) };
  const until = p.UNTIL ? untilDayKey(p.UNTIL, tz) : '';
  return until ? { kind: 'until', until, count: 10 } : { kind: 'never', until: '', count: 10 };
}

// A rule with a new end (see repeatEnd); allDay and tz as for untilValue.
export function withRepeatEnd(rrule, end, allDay, tz = null) {
  if (!rrule) return '';
  const p = ruleParts(rrule);
  delete p.UNTIL;
  delete p.COUNT;
  if (end.kind === 'until' && end.until) p.UNTIL = untilValue(end.until, allDay, tz);
  else if (end.kind === 'count' && end.count > 0) p.COUNT = String(Math.floor(end.count));
  return joinRule(p);
}

/**
 * Keep a rule's UNTIL in step with the event: a date series' UNTIL is a date
 * and a timed one's an instant, so ticking All day rewrites it. Same day.
 */
export function retypeUntil(rrule, allDay, tz = null) {
  const end = repeatEnd(rrule, tz);
  return end.kind === 'until' ? withRepeatEnd(rrule, end, allDay, tz) : rrule;
}
