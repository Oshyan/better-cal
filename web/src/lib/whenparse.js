// Typed dates and times for the editor's When fields. Deterministic, no LLM,
// pure so it is unit-testable in node.
//
// Times: "7", "7p", "7pm", "7 p.m.", "7:30", "730", "730p", "19:30", "1930",
// "noon", "midnight". A bare hour from 1 to 12 with no am/pm is ambiguous:
// parseClockText says so and resolveClock picks the reading, the start keeping
// the half of the day it was already in and the end taking the first reading
// after the start. "12" alone is noon.
//
// Dates: everything the jump parser takes (today, tomorrow, fri, next tue,
// 10/5, oct 5, 5 oct 2027, 2027-10-05), the field's own display text
// ("Sat, Sep 26, 2026"), and a bare day number, which stays in the month the
// field already shows.

import { parseJumpText } from './jumpparse.js';
import { pad } from './dates.js';

/** {mins, ambiguous} for a typed time, or null. mins is the am reading when ambiguous. */
export function parseClockText(text) {
  let t = String(text || '').trim().toLowerCase();
  if (!t) return null;
  if (t === 'noon' || t === 'midday') return { mins: 720, ambiguous: false };
  if (t === 'midnight') return { mins: 0, ambiguous: false };
  t = t.replace(/\s+/g, '').replace(/([ap])\.?m?\.?$/, '$1').replace(/\./g, ':');
  const m = t.match(/^(\d{1,2})(?::?(\d{2}))?([ap])?$/);
  if (!m) return null;
  const h = Number(m[1]);
  const min = m[2] ? Number(m[2]) : 0;
  if (min > 59) return null;
  if (m[3]) {
    if (h < 1 || h > 12) return null;
    return { mins: (h % 12) * 60 + min + (m[3] === 'p' ? 720 : 0), ambiguous: false };
  }
  if (h > 23) return null;
  // 24-hour readings: 0-23 with a leading zero, or 13 and up.
  if (h === 0 || h > 12 || m[1].length === 2 && m[1][0] === '0') return { mins: h * 60 + min, ambiguous: false };
  if (h === 12) return { mins: 720 + min, ambiguous: false };
  return { mins: h * 60 + min, ambiguous: true };
}

/**
 * Minutes from midnight for a parsed time.
 * role 'start': an ambiguous hour keeps the half of the day `current` is in.
 * role 'end': an ambiguous hour takes the first reading after `after`
 * (the start's minutes, same day); if neither is after it, the am reading.
 * h24: the user reads a 24-hour clock, so a bare hour is exactly that hour.
 */
export function resolveClock(parsed, { role = 'start', current = 0, after = null, h24 = false } = {}) {
  if (!parsed) return null;
  if (!parsed.ambiguous || h24) return parsed.mins;
  const am = parsed.mins;
  const pm = am + 720;
  if (role === 'end' && after != null) {
    if (am > after) return am;
    if (pm > after) return pm;
    return am;
  }
  return current >= 720 ? pm : am;
}

export function minsToHHMM(mins) {
  const m = ((mins % 1440) + 1440) % 1440;
  return pad(Math.floor(m / 60)) + ':' + pad(m % 60);
}

export function hhmmToMins(hhmm) {
  const m = String(hhmm || '').match(/^(\d{2}):(\d{2})/);
  return m ? Number(m[1]) * 60 + Number(m[2]) : null;
}

const WEEKDAY_LEAD = /^(?:mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun)[a-z]*\.?,?\s+(?=\S)/;

/** A 'YYYY-MM-DD' day key for typed text, or null. currentKey is what the field held. */
export function parseDateText(text, { todayKey, currentKey }) {
  const t = String(text || '').trim().toLowerCase().replace(/\s+/g, ' ');
  if (!t) return null;
  const day = t.match(/^(\d{1,2})(?:st|nd|rd|th)?$/);
  if (day && currentKey) {
    const d = Number(day[1]);
    const y = Number(currentKey.slice(0, 4));
    const mo = Number(currentKey.slice(5, 7));
    const last = new Date(Date.UTC(y, mo, 0)).getUTCDate();
    return d >= 1 && d <= last ? currentKey.slice(0, 8) + pad(d) : null;
  }
  const direct = parseJumpText(t, todayKey);
  if (direct) return direct;
  // "Sat, Sep 26, 2026" and "sat sep 26": the weekday is decoration.
  const rest = t.replace(WEEKDAY_LEAD, '');
  if (rest !== t) return parseJumpText(rest, todayKey);
  return null;
}

/** "30 min", "1 hr", "1.5 hr", "2 hr 15 min", "1 day": the end list's duration labels. */
export function durationLabel(mins) {
  if (mins < 60) return mins + ' min';
  if (mins % 1440 === 0) return mins / 1440 + (mins === 1440 ? ' day' : ' days');
  const h = Math.floor(mins / 60);
  const r = mins % 60;
  if (r === 0) return h + ' hr';
  if (r === 30) return h + '.5 hr';
  return h + ' hr ' + r + ' min';
}
