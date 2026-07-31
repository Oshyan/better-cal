// Deterministic natural-language date parser for the jump popover.
// No LLM: month names, weekday names, m/d, ISO, plain years, today/tomorrow.
// Pure day-key math so it is unit-testable in node.
//
// Rules (all case-insensitive, resolved against a base day key):
// - "today" / "tomorrow" / "yesterday"
// - ISO: "2027-06-15"
// - Year only: "2027" -> Jan 1 of that year
// - m/d or m.d, optional year: "8/15", "8/15/27", "8/15/2027".
//   Without a year: this year, or next year if the date already passed.
// - Month name (3+ letter prefix ok): "june" -> first of the next occurrence
//   of that month (this year if not already passed, else next year);
//   "june 2027" -> 2027-06-01; "jun 5" / "5 jun" (+ optional year) -> that
//   day, same passed-date rule as m/d.
// - Weekday name (3+ letter prefix ok), optional "next"/"this" prefix:
//   always the soonest strictly-future occurrence ("friday" on a Friday
//   means a week out, never today).
// Returns a 'YYYY-MM-DD' day key or null when nothing matches.

import { epochDayOfKey, keyOfEpochDay, pad } from './dates.js';

const MONTH_NAMES = ['january', 'february', 'march', 'april', 'may', 'june',
  'july', 'august', 'september', 'october', 'november', 'december'];
const WEEKDAY_NAMES = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday',
  'saturday', 'sunday']; // Monday = 0, matching the week math in dates.js

// Match a word against a name list by full name or unique 3+ letter prefix.
function wordIndex(word, names) {
  if (word.length < 3) return null;
  const hits = [];
  for (let i = 0; i < names.length; i++) {
    if (names[i] === word) return i;
    if (names[i].startsWith(word)) hits.push(i);
  }
  return hits.length === 1 ? hits[0] : null;
}

function daysInMonth(y, m) {
  return new Date(Date.UTC(y, m, 0)).getUTCDate();
}

// Validated day key or null.
function validKey(y, m, d) {
  if (!Number.isInteger(y) || !Number.isInteger(m) || !Number.isInteger(d)) return null;
  if (y < 1000 || y > 9999 || m < 1 || m > 12 || d < 1 || d > daysInMonth(y, m)) return null;
  return y + '-' + pad(m) + '-' + pad(d);
}

// Month/day with no year: this year, or next year if already passed.
function upcomingKey(baseKey, month, day) {
  const y = Number(baseKey.slice(0, 4));
  const k = validKey(y, month, day);
  if (!k) return null;
  if (epochDayOfKey(k) < epochDayOfKey(baseKey)) return validKey(y + 1, month, day);
  return k;
}

function fullYear(raw) {
  const y = Number(raw);
  return raw.length <= 2 ? 2000 + y : y;
}

export function parseJumpText(text, baseKey) {
  const t = String(text || '').trim().toLowerCase().replace(/\s+/g, ' ').replace(/,/g, '');
  if (!t || !baseKey) return null;
  const baseEd = epochDayOfKey(baseKey);
  const baseYear = Number(baseKey.slice(0, 4));
  const baseMonth = Number(baseKey.slice(5, 7));

  if (t === 'today') return baseKey;
  if (t === 'tomorrow') return keyOfEpochDay(baseEd + 1);
  if (t === 'yesterday') return keyOfEpochDay(baseEd - 1);

  let m = t.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
  if (m) return validKey(Number(m[1]), Number(m[2]), Number(m[3]));

  if (/^\d{4}$/.test(t)) return validKey(Number(t), 1, 1);

  m = t.match(/^(\d{1,2})[/.](\d{1,2})(?:[/.](\d{2}|\d{4}))?$/);
  if (m) {
    const mo = Number(m[1]);
    const d = Number(m[2]);
    if (m[3]) return validKey(fullYear(m[3]), mo, d);
    return upcomingKey(baseKey, mo, d);
  }

  // Word-based forms.
  // "next tuesday" / "this tuesday" / "tuesday"
  m = t.match(/^(?:(?:next|this) )?([a-z]+)$/);
  if (m) {
    const wd = wordIndex(m[1], WEEKDAY_NAMES);
    if (wd != null) {
      const baseWd = (baseEd + 3) % 7; // Monday = 0
      let delta = (wd - baseWd + 7) % 7;
      if (delta === 0) delta = 7; // strictly future
      return keyOfEpochDay(baseEd + delta);
    }
    const mo = wordIndex(m[1], MONTH_NAMES);
    if (mo != null) {
      const y = mo + 1 >= baseMonth ? baseYear : baseYear + 1;
      return validKey(y, mo + 1, 1);
    }
    return null;
  }

  // "june 2027"
  m = t.match(/^([a-z]+) (\d{4})$/);
  if (m) {
    const mo = wordIndex(m[1], MONTH_NAMES);
    if (mo != null) return validKey(Number(m[2]), mo + 1, 1);
    return null;
  }

  // "june 5" / "june 5 2027"
  m = t.match(/^([a-z]+) (\d{1,2})(?: (\d{2}|\d{4}))?$/);
  if (m) {
    const mo = wordIndex(m[1], MONTH_NAMES);
    if (mo == null) return null;
    if (m[3]) return validKey(fullYear(m[3]), mo + 1, Number(m[2]));
    return upcomingKey(baseKey, mo + 1, Number(m[2]));
  }

  // "5 june" / "5 june 2027"
  m = t.match(/^(\d{1,2}) ([a-z]+)(?: (\d{2}|\d{4}))?$/);
  if (m) {
    const mo = wordIndex(m[2], MONTH_NAMES);
    if (mo == null) return null;
    if (m[3]) return validKey(fullYear(m[3]), mo + 1, Number(m[1]));
    return upcomingKey(baseKey, mo + 1, Number(m[1]));
  }

  return null;
}
