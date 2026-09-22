// Context events (docs/relationships.md) are information about a day, not
// plans: weather, AQI, sunset, tides, a holiday, someone being away. They
// are consulted, not attended, so the views draw them as small tokens in
// the day's header (and as hairline moments on the timeline) instead of
// spending event slots on them. This module decides what a token says: an
// icon for what kind of information it is, and the value.

import { fmtTime, fmtTimeIn, tzAbbrev, sameClock, localTz, parseISO, occDayKey, addDaysKey } from './dates.js';
import { occurrenceDaySpan } from '../ui/monthmath.js';

export function isContext(occ) {
  return occ.relationship === 'context';
}

/** A host icon name, as opposed to a literal glyph such as an emoji. */
export const HOST_ICON = /^[a-zA-Z][a-zA-Z0-9_-]*$/;

/** "8:30 PM" -> "8:30p", "12:00 PM" -> "12p"; "20:30" stays under the 24h setting. In the event's own zone when given. */
export function compactTime(d, tz) {
  return fmtTimeIn(d, tz).replace(':00', '').replace(/\s?AM$/i, 'a').replace(/\s?PM$/i, 'p');
}

// A place-bound moment (sunset in Oakland) keeps its own clock: when the
// event's zone reads a different time from the device's, the token says
// which zone. Plugin events are stored as UTC instants and carry no place,
// so they show device time with no suffix.
export function zoneSuffix(occ) {
  const tz = occ.tzid;
  if (!tz || tz === 'UTC' || occ.allDay) return '';
  const at = parseISO(occ.start);
  return sameClock(tz, localTz(), at) ? '' : tzAbbrev(tz, at);
}

function clip(s, max) {
  return s.length > max ? s.slice(0, max - 1).trimEnd() + '…' : s;
}

/** The title without a source prefix: "Oakland, CA: 72/58 fog" -> "72/58 fog". */
export function contextText(occ) {
  const t = (occ.title || '').trim();
  const i = t.indexOf(': ');
  if (!(i > 0 && i <= 24 && i < t.length - 2)) return t;
  // "Low tide: 0.8 ft" names the thing, not the source: keep it.
  return /\b(tide|sunset|sunrise|moon|aqi|uv|pollen|weather)\b/i.test(t.slice(0, i)) ? t : t.slice(i + 2);
}

const WEATHER = [
  ['storm', /thunder|storm|lightning/i],
  ['snow', /snow|sleet|flurr|blizzard|ice/i],
  ['rain', /rain|shower|drizzle|wet/i],
  ['cloud', /fog|mist|haze|cloud|overcast|smok/i],
  ['sun', /sun|clear|fair/i],
];

// What a feed title is about, when the plugin did not say. The rules are
// deliberately few and literal; anything else keeps its words.
function recognize(bare, cal) {
  let m;
  if (/\bAQI\b/i.test(bare)) return { icon: 'air', text: bare.replace(/\bAQI\b\s*:?\s*/i, '').trim() };
  if (/\bsunset\b/i.test(bare)) return { icon: 'sunset', text: '' };
  if (/\bsunrise\b/i.test(bare)) return { icon: 'sunrise', text: '' };
  if (/\bhigh tide\b/i.test(bare)) return { icon: 'tideHigh', text: bare.replace(/\bhigh tide\b:?\s*/i, '').trim() };
  if (/\blow tide\b/i.test(bare)) return { icon: 'tideLow', text: bare.replace(/\blow tide\b:?\s*/i, '').trim() };
  if (/\b(full|new) moon\b|moonrise|moonset/i.test(bare)) return { icon: 'moon', text: bare.replace(/\bmoon\b/i, '').trim() };
  if ((m = bare.match(/^(-?\d{1,3})°?\s*\/\s*(-?\d{1,3})°?\s*(.*)$/))) {
    const words = m[3] || '';
    const icon = (WEATHER.find(([, re]) => re.test(words)) || ['thermometer'])[0];
    return { icon, text: m[1] + '/' + m[2] };
  }
  if (cal && /holiday/i.test(cal.name || '')) return { icon: 'flag', text: bare };
  return null;
}

/**
 * The token: {icon, text, time, zone}. icon is the plugin's own per-event
 * icon (a host name or a glyph) or one recognized from the title; text is
 * the value with a trailing parenthetical dropped when there is an icon to
 * carry the meaning ("AQI 54 (Moderate)" -> air icon + "54"); time and
 * zone for a timed moment.
 */
export function contextToken(occ, cal, max = 14) {
  const t = contextText(occ);
  const paren = t.match(/\s*\([^)]*\)\s*$/);
  const bare = paren ? t.slice(0, paren.index).trim() : t;
  let icon = occ.icon || null;
  let text;
  if (icon) {
    text = paren && t.length > max ? bare : t;
  } else {
    const r = recognize(bare, cal);
    if (r) { icon = r.icon; text = r.text; } else text = paren && t.length > max ? bare : t;
  }
  const timed = !occ.allDay;
  text = clip(text, timed ? Math.max(6, max - 4) : max);
  return {
    icon,
    text,
    time: timed ? compactTime(parseISO(occ.start), occ.tzid) : null,
    zone: timed ? zoneSuffix(occ) : '',
  };
}

/** The full story for a tooltip: title, and the time (own zone) for a moment. */
export function contextTitle(occ) {
  const t = (occ.title || '').trim() || '(untitled)';
  if (occ.allDay) return t;
  const s = parseISO(occ.start);
  const e = occ.end ? parseISO(occ.end) : null;
  const brief = !e || (e.getTime() - s.getTime()) <= 15 * 60000;
  const z = zoneSuffix(occ);
  const tz = z ? occ.tzid : null;
  return t + ' · ' + (tz ? fmtTimeIn(s, tz) : fmtTime(s)) + (brief ? '' : ' to ' + (tz ? fmtTimeIn(e, tz) : fmtTime(e))) + (z ? ' ' + z : '');
}

/**
 * Day key -> the context occurrences that speak for that day, all-day first
 * (the state of the day), then moments by time. A multi-day all-day span
 * counts on each day it covers (capped so a runaway span cannot flood).
 */
export function contextByDay(occurrences) {
  const out = new Map();
  for (const occ of occurrences) {
    if (!isContext(occ) || occ.attendance === 'hidden') continue;
    if (!occ.allDay) {
      const k = occDayKey(occ);
      if (!out.has(k)) out.set(k, []);
      out.get(k).push(occ);
      continue;
    }
    const { startKey, endKey } = occurrenceDaySpan(occ);
    for (let k = startKey, i = 0; k <= endKey && i < 62; k = addDaysKey(k, 1), i++) {
      if (!out.has(k)) out.set(k, []);
      out.get(k).push(occ);
    }
  }
  const order = (a, b) => (Number(!a.allDay) - Number(!b.allDay)) || (a.start < b.start ? -1 : a.start > b.start ? 1 : 0) || (a.title || '').localeCompare(b.title || '');
  for (const list of out.values()) list.sort(order);
  return out;
}

/** Everything that is not context: what the views draw as events. */
export function withoutContext(occurrences) {
  return occurrences.filter((o) => !isContext(o));
}
