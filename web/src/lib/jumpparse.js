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

// Forms that name a whole month or year rather than a day. Shared with
// jumpGranularity below so the two can never disagree about which is which.
const YEAR_ONLY = /^\d{4}$/;
const BARE_WORD = /^(?:(?:next|this) )?([a-z]+)$/;
const MONTH_YEAR = /^([a-z]+) (\d{4})$/;

function normalize(text) {
  return String(text || '').trim().toLowerCase().replace(/\s+/g, ' ').replace(/,/g, '')
    .replace(/\b(\d{1,2})(?:st|nd|rd|th)\b/g, '$1'); // "june 9th"
}

// How much of a date the text actually pinned down: 'day' | 'month' | 'year'.
// A request for a whole month should frame that month rather than land on its
// first day — see jumpAnchorFor in actions.js.
export function jumpGranularity(text) {
  const t = normalize(text);
  if (!t) return 'day';
  if (YEAR_ONLY.test(t)) return 'year';
  const bare = t.match(BARE_WORD);
  if (bare) return wordIndex(bare[1], MONTH_NAMES) != null ? 'month' : 'day';
  const my = t.match(MONTH_YEAR);
  if (my && wordIndex(my[1], MONTH_NAMES) != null) return 'month';
  return 'day';
}

// View words a jump may name, at either end: "day view of june 9",
// "june 9 day view", "september month view", "agenda from next tuesday".
// Closed vocabulary, matching the views that exist (actions.js VIEWS, plus the
// phone's 3-day overview). Longest phrases first so "3 weeks" never reads as
// "week".
const VIEW_PHRASES = [
  ['three weeks', 'weeks3'], ['three week', 'weeks3'], ['3 weeks', 'weeks3'], ['3 week', 'weeks3'],
  ['two weeks', 'weeks2'], ['two week', 'weeks2'], ['2 weeks', 'weeks2'], ['2 week', 'weeks2'],
  ['full month', 'month'], ['3 days', '3day'], ['3 day', '3day'],
  ['schedule', 'agenda'], ['agenda', 'agenda'], ['month', 'month'],
  ['split', 'split'], ['week', 'week'], ['day', 'day'],
];
const VIEW_ALT = VIEW_PHRASES.map(([p]) => p).join('|');
const LEADING_VIEW = new RegExp('^(?:in |show )?(?:the |a )?(' + VIEW_ALT + ')(?: view)?(?: (?:of|for|from|on|at|starting))? (.+)$');
const TRAILING_VIEW = new RegExp('^(.+?) (?:in |as )?(?:the |a )?(' + VIEW_ALT + ')(?: view)?$');
// "june 9 calendar view": a word we don't know, followed by "view", is dropped
// rather than failing the jump.
const UNKNOWN_LEADING = /^[a-z]+ view(?: (?:of|for|from|on|at|starting))? (.+)$/;
const UNKNOWN_TRAILING = /^(.+?) (?:in |as )?(?:the |a )?[a-z]+ view$/;

function viewOf(phrase) {
  const hit = VIEW_PHRASES.find(([p]) => p === phrase);
  return hit ? hit[1] : null;
}

// A jump's full target: the day (as parseJumpText), how much of a date was
// named (as jumpGranularity), and the view it asked for, or null to stay in
// the current one. Null when no date is recognized, view word or not.
export function parseJumpTarget(text, baseKey) {
  const t = normalize(text).replace(/\b(\d)-(weeks?|days?)\b/g, '$1 $2');
  if (!t || !baseKey) return null;
  const target = (rest, view) => {
    const dayKey = parseJumpText(rest, baseKey);
    return dayKey ? { dayKey, granularity: jumpGranularity(rest), view } : null;
  };
  const direct = target(t, null);
  if (direct) return direct;
  let m = t.match(LEADING_VIEW);
  const lead = m && target(m[2], viewOf(m[1]));
  if (lead) return lead;
  m = t.match(TRAILING_VIEW);
  const trail = m && target(m[1], viewOf(m[2]));
  if (trail) return trail;
  m = t.match(UNKNOWN_LEADING) || t.match(UNKNOWN_TRAILING);
  return m ? target(m[1], null) : null;
}

export function parseJumpText(text, baseKey) {
  const t = normalize(text);
  if (!t || !baseKey) return null;
  const baseEd = epochDayOfKey(baseKey);
  const baseYear = Number(baseKey.slice(0, 4));
  const baseMonth = Number(baseKey.slice(5, 7));

  if (t === 'today') return baseKey;
  if (t === 'tomorrow') return keyOfEpochDay(baseEd + 1);
  if (t === 'yesterday') return keyOfEpochDay(baseEd - 1);

  let m = t.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
  if (m) return validKey(Number(m[1]), Number(m[2]), Number(m[3]));

  if (YEAR_ONLY.test(t)) return validKey(Number(t), 1, 1);

  m = t.match(/^(\d{1,2})[/.](\d{1,2})(?:[/.](\d{2}|\d{4}))?$/);
  if (m) {
    const mo = Number(m[1]);
    const d = Number(m[2]);
    if (m[3]) return validKey(fullYear(m[3]), mo, d);
    return upcomingKey(baseKey, mo, d);
  }

  // Word-based forms.
  // "next tuesday" / "this tuesday" / "tuesday"
  m = t.match(BARE_WORD);
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
  m = t.match(MONTH_YEAR);
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
