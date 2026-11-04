// Every sentence of the series change panel, in one place, to be read as
// prose (docs/design/series-model.md, The Plan: "The plan is data; the
// client writes the sentences in the user's format, from one module of
// templates reviewed as prose"). The plan the server sends is data: rules as
// RRULE text with their start and zone, bounds as kinds and numbers, days as
// keys, dates and outcomes, choices as ids with their data. Nothing here
// reads the server's `words`. Every date and time goes through lib/dates.js,
// so the user's locale and 12- or 24-hour setting apply.
//
// Pure, no DOM. lib/seriesplan.js decides which blocks and rows there are;
// this module only says them.

import {
  fmtDayMedium, fmtDateFull, fmtDateShort, fmtDateShortYear, fmtTimeIn,
  dateOfDayKey, tzCity, untilDayKey,
} from './dates.js';
import { describeRepeat, ruleParts } from './repeat.js';

const lowerFirst = (s) => (s ? s.charAt(0).toLowerCase() + s.slice(1) : s);
export const upperFirst = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);
const plural = (n, one, many) => n + ' ' + (n === 1 ? one : many);
const COUNT_WORDS = ['no', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten'];
const ORDINAL_WORDS = ['', 'first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth', 'eleventh', 'twelfth'];
const ordinalWord = (n) => ORDINAL_WORDS[n] || (n % 100 >= 11 && n % 100 <= 13 ? n + 'th' : n + (['th', 'st', 'nd', 'rd'][n % 10] || 'th'));
const andList = (items) => (items.length <= 1 ? items[0] || '' : items.slice(0, -1).join(', ') + ' and ' + items[items.length - 1]);
const quoted = (s) => "'" + s + "'";
const WEEKDAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const DAY_CODE = { SU: 0, MO: 1, TU: 2, WE: 3, TH: 4, FR: 5, SA: 6 };

// ---- dates and times -----------------------------------------------------

/** The wall date an ISO value or a day key names, as a day key ("2026-11-11"). */
export function dayKeyOf(value) {
  const m = /^(\d{4}-\d{2}-\d{2})/.exec(String(value || ''));
  return m ? m[1] : null;
}
const yearOf = (key) => Number(String(key).slice(0, 4));

/**
 * A date as the panel writes it, through the user's locale: with its weekday
 * ("Wed, Nov 11") or without ("Nov 3"), and its year only when it isn't the
 * year before it in the sentence (`prev`, a day key), or this year
 * (`refYear`) for the first date in one.
 */
export function fmtDay(value, { weekday = true, prev = null, refYear = new Date().getFullYear() } = {}) {
  const key = dayKeyOf(value);
  if (!key) return '';
  const d = dateOfDayKey(key);
  const showYear = yearOf(key) !== (prev ? yearOf(prev) : refYear);
  if (weekday) return showYear ? fmtDateFull(d) : fmtDayMedium(d);
  return showYear ? fmtDateShortYear(d) : fmtDateShort(d);
}

// A clock with no zone to read it in is never guessed (series-model.md, One
// zone): the smoke suite turns this on so such a call fails there; in the
// app the clock is left out instead.
let strictClocks = false;
export function setStrictClocks(on) { strictClocks = !!on; }

/**
 * A clock time, in the user's 12- or 24-hour setting, of "HH:MM" or of an
 * instant: an ISO string read in `tz` (the plan's zone), or a value of the
 * plan carrying its own zone ({at or start, tz}: a timed day in an all-day
 * plan), read in that zone. With no zone there is no clock.
 */
export function fmtClock(value, tz = null) {
  if (/^\d{2}:\d{2}$/.test(String(value))) {
    const [h, m] = String(value).split(':').map(Number);
    return fmtTimeIn(new Date(Date.UTC(2000, 0, 1, h, m)), 'UTC');
  }
  const own = value && typeof value === 'object' ? value : null;
  const at = own ? own.at || own.start : value;
  const zone = (own && own.tz) || tz;
  if (!zone) {
    if (strictClocks) throw new Error('fmtClock: no zone for ' + at);
    return '';
  }
  const d = new Date(at);
  return isNaN(d) ? '' : fmtTimeIn(d, zone);
}

/** A zone as people name it: "Berlin". */
export const zoneName = (tz) => tzCity(tz);

// ---- the rule and its bound ------------------------------------------------

/** A rule in words with its clock: "weekly on Tuesday at 7:00 PM". */
export function ruleWords(part) {
  if (!part || !part.rrule) return '';
  const start = dayKeyOf(part.start);
  const words = describeRepeat(part.rrule, { start: start ? dateOfDayKey(start) : null, ends: false }) || 'on a custom schedule';
  const clock = !part.allDay && part.start ? ' at ' + fmtClock(part.start, part.tz) : '';
  // "on the 31st at 6:00 PM, skipping months without one", never the clock after the clause.
  const clause = /(, skipping months without one|, or the last day(?: in shorter months)?)$/.exec(words);
  if (clause) return lowerFirst(words.slice(0, clause.index)) + clock + clause[1];
  return lowerFirst(words) + clock;
}

/**
 * The bound as the Change sentence ends: "with no end, as before", "with no
 * end (was 6 events)", "ending Nov 1, as before", "ending Nov 1 (was Oct 31)",
 * "for 6 events, as before", "for 10 events (was 6)".
 */
export function boundPhrase(bound, opts = {}) {
  const from = (bound && bound.from) || { kind: 'none' };
  const kind = bound ? bound.kind : 'none';
  if (kind === 'none') {
    if (from.kind === 'none') return 'with no end, as before';
    if (from.kind === 'count') return 'with no end (was ' + plural(from.count, 'event', 'events') + ')';
    return 'with no end (was ending ' + fmtDay(from.last, { weekday: false, ...opts }) + ')';
  }
  if (kind === 'count') {
    const n = bound.events != null ? bound.events : bound.count;
    if (from.kind === 'count') return 'for ' + plural(n, 'event', 'events') + (from.count === n ? ', as before' : ' (was ' + from.count + ')');
    return 'for ' + plural(n, 'event', 'events');
  }
  const last = bound.last || (bound.until ? untilDayKey(bound.until) : null);
  const ending = 'ending ' + fmtDay(last, { weekday: false, ...opts });
  if (from.kind === 'until') return ending + (from.last === last ? ', as before' : ' (was ' + fmtDay(from.last, { weekday: false, ...opts }) + ')');
  return ending;
}

/** CHANGE: "From weekly on Tuesday at 7:00 PM to weekly on Wednesday at 7:00 PM, with no end, as before." */
export function changeSentence(rule, bound, opts = {}) {
  const from = ruleWords(rule && rule.from);
  const to = ruleWords(rule && rule.to);
  const end = boundPhrase(bound, opts);
  if (!from && !to) return upperFirst(end) + '.';
  const fromDay = dayKeyOf(rule && rule.from && rule.from.start), toDay = dayKeyOf(rule && rule.to && rule.to.start);
  if (from === to || !to) {
    const starts = fromDay && toDay && fromDay !== toDay ? 'Starts ' + fmtDay(toDay, opts) + ' (was ' + fmtDay(fromDay, { ...opts, prev: toDay }) + '). ' : '';
    return starts + 'Still ' + from + ', ' + end + '.';
  }
  // Only what changes, with the rest as context, while the frequency stays.
  const a = ruleShape(rule.from), b = ruleShape(rule.to);
  if (a && b && a.period === b.period && a.clause === b.clause && a.zone === b.zone) {
    if (a.days === b.days) return 'From ' + a.clock + ' to ' + b.clock + ' ' + (a.days ? everyDays(a) : a.period) + b.clause + ', ' + end + '.';
    if (a.clock === b.clock) return 'From ' + a.days + ' to ' + b.days + ' ' + a.period + (b.clock ? ', at ' + b.clock : '') + b.clause + ', ' + end + '.';
    return 'From ' + a.days + ' at ' + a.clock + ' to ' + b.days + ' at ' + b.clock + ' ' + a.period + b.clause + ', ' + end + '.';
  }
  return 'From ' + from + ' to ' + to + ', ' + end + '.';
}

// A rule's parts for the change sentence: its days ("Tuesday", "the 31st",
// "the first and third Monday"), its period ("each week", "each month",
// "every 2 weeks"), its clock, its month-end clause and its zone.
const PERIODS = { Daily: 'each day', Weekly: 'each week', Monthly: 'each month', Yearly: 'each year' };
function ruleShape(part) {
  if (!part || !part.rrule) return null;
  const start = dayKeyOf(part.start);
  const words = describeRepeat(part.rrule, { start: start ? dateOfDayKey(start) : null, ends: false });
  const m = words && /^(Daily|Weekly|Monthly|Yearly|Every \d+ (?:day|week|month|year)s)(?: on (.+?))?(, skipping months without one|, or the last day(?: in shorter months)?)?$/.exec(words);
  if (!m) return null;
  return {
    period: PERIODS[m[1]] || lowerFirst(m[1]), weekly: m[1] === 'Weekly', days: m[2] || '', clause: m[3] || '',
    clock: !part.allDay && part.start ? fmtClock(part.start, part.tz) : '', zone: part.allDay ? '' : part.tz || '',
  };
}
// "every Monday", "on the 1st each month".
const everyDays = (s) => (s.weekly ? 'every ' + s.days : 'on ' + s.days + ' ' + s.period);

/** A field the editor changed: "Title from 'Choir' to 'Chorus'.", "Reminder from 1 hour before to 30 minutes." */
/**
 * The trip flag's change from the plan's data: "no, now yes", or when the
 * days differed, "no on some days, now yes for every event".
 */
export function tripWords(trip) {
  const yn = (x) => (x ? 'yes' : 'no');
  return trip.mixed ? yn(trip.was) + ' on some days, now ' + yn(trip.now) + ' for every event' : yn(trip.was) + ', now ' + yn(trip.now);
}

export function fieldSentence(f) {
  const m = /^(.*), now (.*)$/.exec(f.words || '');
  if (!m) return f.label + ': ' + f.words + '.';
  const q = f.label === 'Title' ? quoted : (x) => x;
  return f.label + ' from ' + q(m[1]) + ' to ' + q(m[2]) + '.';
}

// ---- coming up and affects ---------------------------------------------------

/**
 * COMING UP: the next three dates, "Wed, Nov 11 · Wed, Nov 18 · Wed, Nov
 * 25 …", the ellipsis whenever the series goes on past them.
 */
export function comingUp(next, more, opts = {}) {
  let prev = null;
  const list = next || [];
  const days = list.slice(0, 3).map((n) => {
    const at = typeof n === 'string' ? n : n && n.start;
    const s = fmtDay(at, { ...opts, prev });
    prev = dayKeyOf(at);
    return s;
  }).filter(Boolean);
  if (!days.length) return '';
  return days.join(' · ') + (more || list.length > 3 ? ' …' : '');
}

/**
 * AFFECTS: "All events, with no end. 11 were changed earlier (title), 1 was
 * removed." or "13 events, ending Jan 20, 2027. ..."; the second sentence
 * only when a day was changed or removed earlier. The parenthesis names the
 * fields the changed days have of their own, by how many days have each, at
 * most two named and the rest counted. `days` are the plan's days.
 */
export function affects(counts, bound, opts = {}, days = [], rule = null) {
  const c = counts || {};
  const last = bound && (bound.last || (bound.until ? untilDayKey(bound.until) : null));
  const first = endChange(bound, rule, opts) || (c.events == null
    ? 'All events, with no end.'
    : plural(c.events, 'event', 'events') + (last ? ', ending ' + fmtDay(last, { weekday: false, ...opts }) : '') + '.');
  const was = (n) => (n === 1 ? 'was' : 'were');
  const parts = [];
  if (c.edited) parts.push(c.edited + ' ' + was(c.edited) + ' changed earlier' + ownFieldsAside(days));
  if (c.skipped) parts.push(c.skipped + ' ' + was(c.skipped) + ' removed');
  return parts.length ? first + ' ' + parts.join(', ') + '.' : first;
}

// A change of the end or the count alone, the days kept: what it becomes,
// what it was, and what stays. "Ends Jul 1, 2027 (was Oct 1). The 6 events
// before it are unchanged." / "10 events (was 6). The 6 events before the
// new ones are unchanged."
const patternOf = (rrule) => String(rrule || '').split(';').filter((x) => !/^(COUNT|UNTIL)=/.test(x)).sort().join(';');
function endChange(bound, rule, opts) {
  const from = bound && bound.from;
  if (!bound || !from || !rule || !rule.from || !rule.to) return '';
  if (patternOf(rule.from.rrule) !== patternOf(rule.to.rrule) || dayKeyOf(rule.from.start) !== dayKeyOf(rule.to.start)) return '';
  const was = from.events, now = bound.events;
  if (bound.kind === 'count' && from.kind === 'count' && now != null && was != null && now !== was) {
    const kept = Math.min(now, was);
    return plural(now, 'event', 'events') + ' (was ' + was + '). ' + (now > was ? 'The ' + plural(kept, 'event', 'events') + ' before the new ones ' + (kept === 1 ? 'is' : 'are') + ' unchanged.' : 'The first ' + kept + ' ' + (kept === 1 ? 'is' : 'are') + ' unchanged.');
  }
  if (bound.kind === 'until' && from.kind === 'until' && bound.last && from.last && bound.last !== from.last) {
    const kept = now != null && was != null ? Math.min(now, was) : null;
    return 'Ends ' + fmtDay(bound.last, { weekday: false, ...opts }) + ' (was ' + fmtDay(from.last, { weekday: false, ...opts, prev: bound.last }) + ').'
      + (kept != null ? ' The ' + plural(kept, 'event', 'events') + ' before it ' + (kept === 1 ? 'is' : 'are') + ' unchanged.' : '');
  }
  return '';
}

/** Header line 3, when the edit started from a day with something of its own: "Started from 'Sample practice 6' on Fri, Dec 11." */
export function startedLine(acted, opts = {}) {
  if (!acted || !(acted.kept || []).length) return '';
  return 'Started from ' + dayName(acted, opts.series) + ' on ' + fmtDay(acted.shows, opts) + '.';
}

// The fields changed days have of their own, most common first: " (title,
// location and 2 others)". Ties keep the editor's order.
const OWN_ORDER = ['title', 'location', 'description', 'date', 'time', 'length', 'reminders', 'status', 'tags', 'people', 'url', 'icon', 'attendance', 'allDay', 'trip'];
function ownFieldsAside(days) {
  const counts = new Map();
  for (const d of days || []) {
    if (d.kind !== 'edited') continue;
    for (const f of d.kept || []) if (f !== 'trip') counts.set(f, (counts.get(f) || 0) + 1);
  }
  const fields = [...counts.keys()].sort((a, b) => counts.get(b) - counts.get(a) || OWN_ORDER.indexOf(a) - OWN_ORDER.indexOf(b));
  if (!fields.length) return '';
  const named = fields.slice(0, 2).map((f) => FIELD_NOUNS[f] || f);
  const rest = fields.length - named.length;
  if (!rest) return ' (' + andList(named) + ')';
  return ' (' + named.join(', ') + ' and ' + plural(rest, 'other', 'others') + ')';
}

// ---- the header and the action ----------------------------------------------

/** The verb of a change: Move for a drag, Rename for a title-only save, Change for the rest. */
export function verbOf(kind, edits = []) {
  if (kind === 'drag') return 'Move';
  if (kind === 'editor' && edits.length === 1 && edits[0] === 'title') return 'Rename';
  return 'Change';
}
export const titleWords = (verb, scope) => verb + (scope === 'following' ? ' this and following' : ' all events');
export const actionWords = (verb, count, scope) => (scope === 'following' ? titleWords(verb, scope) : count == null ? verb + ' all events' : verb + ' ' + plural(count, 'event', 'events'));
export const onlyThisWords = (verb) => verb + ' only this one';
/** Header line 2: "'Sample practice' on Practice". */
export const seriesLine = (name, calendar) => quoted(name || 'Untitled event') + (calendar ? ' on ' + calendar : '');

// ---- days --------------------------------------------------------------------

const FIELD_NOUNS = {
  date: 'date', time: 'time', length: 'length', allDay: 'all-day setting', title: 'title', location: 'location',
  description: 'notes', url: 'link', reminders: 'reminders', status: 'status', attendance: 'answer',
  tags: 'tags', people: 'people', icon: 'icon', trip: 'trip',
};
const fieldList = (fields) => andList(fields.map((f) => FIELD_NOUNS[f] || f));
const contentOf = (list) => (list || []).filter((f) => f !== 'date' && f !== 'time');

/** A day's name, quoted: its own title, or the series' when it has none of its own. */
export function dayName(d, series = '') {
  const own = (d.kept || []).includes('title') && d.title;
  return quoted(own ? d.title : series || d.title || 'Untitled event');
}

/** A field's name as the editor labels it: "title", "location". */
export const fieldNoun = (f) => FIELD_NOUNS[f] || f;

/**
 * What changes on a day besides its date: "is renamed to 'Chorus'", "takes
 * the new reminders". What it keeps is never said; only what changes.
 */
export function changesWords(d, opts = {}) {
  const takes = contentOf(d.takes);
  const renamed = takes.includes('title') && opts.newTitle ? 'is renamed to ' + quoted(opts.newTitle) : '';
  const rest = takes.filter((f) => !(renamed && f === 'title'));
  return [renamed, rest.length ? 'takes the new ' + fieldList(rest) : ''].filter(Boolean).join(' and ');
}

// What a day has of its own, said after its name only when it is more
// than its title: ", with its own title and location,", ", in a trip,",
// ", with its own location and in a trip,".
function ownAside(d) {
  const own = contentOf(d.kept);
  if (!own.some((f) => f !== 'title')) return '';
  const fields = own.filter((f) => f !== 'trip');
  const parts = [fields.length ? 'with its own ' + fieldList(fields) : '', own.includes('trip') ? 'in a trip' : ''].filter(Boolean);
  return ', ' + parts.join(' and ') + ',';
}

/**
 * The time a day shows at after the change when it isn't the series' new
 * time there (its own time kept): "1:00 PM", else "". That time is its
 * outcome, so its row states it; a time equal to the series' is never said.
 * An all-day plan (no zone) has no series time: a timed day there always
 * says its clock, in its own zone, named: "8:00 PM in Los Angeles".
 */
export function ownClock(d, opts = {}) {
  if (!d || !d.at || !d.newKey) return '';
  if (!opts.tz) {
    const at = fmtClock(d);
    return at && at + ' in ' + zoneName(d.tz);
  }
  const at = fmtClock(d, opts.tz);
  return at && at !== fmtClock(d.newKey, opts.tz) ? at : '';
}

// Where a day goes: its new date, or its new time on the same day (a time
// of the series, so only in a plan with a zone).
function goes(d, opts) {
  if (d.to && d.shows && d.to !== d.shows) return 'moves to ' + fmtDay(d.to, { ...opts, prev: d.shows });
  if (opts.tz && d.newKey && (d.takes || []).includes('time')) return 'moves to ' + fmtClock(d.newKey, opts.tz);
  return 'stays where it is';
}

/**
 * An edited day's row, by its outcome: what was done to it earlier, what
 * happens to it now, and what changes on it (never what it keeps).
 */
export function dayLines(d, opts = {}) {
  const name = dayName(d, opts.series) + ownAside(d);
  const on = d.shows ? ' on ' + fmtDay(d.shows, opts) : '';
  const changes = changesWords(d, opts);
  if (d.kind === 'acted') {
    return d.outcome === 'separate'
      ? { line1: 'The day you moved becomes an event of its own on ' + fmtDay(d.to, opts) + '.', line2: '' }
      : { line1: 'The day you moved stays where you put it, on ' + fmtDay(d.to, opts) + '.', line2: '' };
  }
  const clock = (d.outcome === 'moves' || d.outcome === 'stays') ? ownClock(d, opts) : '';
  const day = d.to || d.shows;
  if ((d.kept || []).includes('date')) {
    if (clock) return { line1: dayName(d, opts.series) + (day !== d.shows ? ' moves to ' : ' stays on ') + fmtDay(day, opts) + ' at ' + clock + (changes ? ', and ' + changes : '') + '.', line2: '' };
    return { line1: dayName(d, opts.series) + ' was moved earlier to ' + fmtDay(d.shows, opts) + (changes ? ', and ' + changes : '') + '.', line2: '' };
  }
  switch (d.outcome) {
    case 'moves':
    case 'stays':
      if (clock) return { line1: name + on + (day !== d.shows ? ' moves to ' + fmtDay(day, { ...opts, prev: d.shows }) + ' at ' : ' stays at ') + clock + (changes ? ' and ' + changes : '') + '.', line2: '' };
      return { line1: name + on + ' ' + goes(d, opts) + (changes ? ' and ' + changes : '') + '.', line2: '' };
    case 'separate':
      return { line1: name + on + ' becomes an event of its own on ' + fmtDay(d.to || d.shows, { ...opts, prev: d.shows }) + '.', line2: '' };
    case 'deleted':
      return { line1: name + on + ' is deleted.', line2: '' };
    case 'rejoins':
      return { line1: name + on + ' goes back to the series; nothing of its own is left.', line2: '' };
    default:
      return { line1: name + on + '.', line2: '' };
  }
}

/** A row of several days: their dates ("Nov 3 to Jan 19, 2027") and the button that shows them. */
export function daysSpan(count, first, last, opts = {}) {
  const range = first === last ? fmtDay(first, { weekday: false, ...opts }) : fmtDay(first, { weekday: false, ...opts }) + ' to ' + fmtDay(last, { weekday: false, ...opts, prev: first });
  return { range, toggle: (open) => (open ? 'Hide' : 'Show ' + count + ' days') };
}

/**
 * A group of days with the same outcome: "10 days that were changed
 * earlier move to Wednesday." then "Nov 3 to Jan 19, 2027".
 */
export function groupLines({ count, outcome, weekday = null, shift = null, clock = null, first, last }, opts = {}) {
  let does = 'stay where they are';
  if (outcome === 'moves') {
    if (weekday != null) does = 'move to ' + WEEKDAY_NAMES[weekday];
    else if (shift) does = 'move ' + (Math.abs(shift) === 1 ? 'a day' : Math.abs(shift) + ' days') + (shift > 0 ? ' later' : ' earlier');
    else if (clock) does = 'move to ' + clock;
    else does = 'move with their events';
  }
  return { line1: count + ' days that were changed earlier ' + does + '.', ...daysSpan(count, first, last, opts) };
}

const isOwn = (g) => /^own[A-Z]/.test(g.id || '');
const pluralNoun = (noun) => (/s$|people/.test(noun) ? noun : noun + 's');

/**
 * A day's row when choices are about it alone: what they are about.
 * "'Sample practice' on Tue, Jan 12 has its own title, 'Sample practice',
 * and its own location." / "... falls after the new end." Other choices
 * (a date of its own) keep the day's own sentence.
 */
export function choiceDayLine(d, groups, opts = {}) {
  const on = ' on ' + fmtDay(d.shows, opts);
  if (groups.some((g) => g.id === 'outside')) return dayName(d, opts.series) + on + ' falls after the new end.';
  const fields = groups.filter(isOwn).map((g) => g.field);
  if (!fields.length) return dayLines(d, opts).line1;
  const rest = fields.filter((f) => f !== 'title').map(fieldNoun);
  const title = fields.includes('title') ? 'title, ' + quoted(d.title) : '';
  const has = title ? title + (rest.length ? ', and its own ' + andList(rest) : '') : andList(rest);
  return dayName({ ...d, kept: ['title'] }) + on + ' has its own ' + has + '.';
}

/**
 * The row of days choices are about together: "2 days have their own
 * titles ('Choir 1', 'Choir 2') and locations." `titles` are the days'.
 */
export function togetherLine(groups, count, titles = []) {
  if (groups.some((g) => g.id === 'outside')) return count + ' days that were changed earlier fall after the new end.';
  const own = groups.filter(isOwn).map((g) => {
    const noun = pluralNoun(FIELD_NOUNS[g.field] || 'value');
    return g.field === 'title' && titles.length
      ? noun + ' (' + titles.slice(0, 3).map(quoted).join(', ') + (titles.length > 3 ? ' and ' + (titles.length - 3) + ' more' : '') + ')' : noun;
  });
  if (own.length) return count + ' days have their own ' + andList(own) + '.';
  if (groups.some((g) => g.id === 'movedDays')) return count + ' days were moved earlier to dates of their own.';
  return count + ' days that were changed earlier.';
}

/** A removed day's row (a skipped occurrence). */
export function skipLines(d, opts = {}) {
  const name = quoted(opts.series || 'Untitled event');
  const was = name + ' on ' + fmtDay(d.shows, opts) + ' was removed earlier';
  if (d.outcome === 'moves') return { line1: was + ', so no event is added on ' + fmtDay(d.to, { ...opts, prev: d.shows }) + '.', line2: '' };
  if (d.outcome === 'stays') return { line1: was + ', and stays removed.', line2: '' };
  if (d.outcome === 'dropped') return { line1: was + (d.why !== 'outside' ? '; the new pattern has no day there.' : d.side === 'start' ? '; it is before the new start, so it is no longer needed.' : '; it falls outside the new series.'), line2: '' };
  return { line1: 'Nothing will show on ' + fmtDay(d.to, opts) + '.', line2: '' };
}

/**
 * REMOVED DAYS as rows, from the plan's skipped days: a skip belongs to the
 * occurrence it removed, so the ones before a start moved later go with
 * their occurrences, said once: "2 removed days before the new start are
 * no longer needed." Every other removed day is its own row (skipLines).
 */
export function removedRows(days, opts = {}) {
  const before = (days || []).filter((d) => d.outcome === 'dropped' && d.why === 'outside' && d.side === 'start');
  const rows = (days || []).filter((d) => !before.includes(d)).map((d) => ({ key: d.key || d.newKey, ...skipLines(d, opts) }));
  if (!before.length) return rows;
  const n = before.length;
  const line1 = n === 1 ? '1 removed day before the new start is no longer needed.' : n + ' removed days before the new start are no longer needed.';
  return [{ key: 'removed:start', line1, line2: '' }, ...rows];
}

// ---- choices -----------------------------------------------------------------

const SIDE = { start: 'at the start', end: 'at the end', both: 'at the start and the end' };

/**
 * A choice in words: its sentence (`lead`, the day or days it is about,
 * when it isn't on a day's row), its reason ending in a question, and each
 * option's label, line two and line three. `g` is the plan's group, with its
 * data; `ctx` carries the plan's rule and bound for the readings.
 */
export function choiceWords(g, ctx = {}) {
  const opts = ctx.opts || {};
  const o = (id) => (g.options || []).find((x) => x.id === id) || {};
  const label = (fn) => (g.options || []).map((x) => ({ id: x.id, label: fn(x) }));
  const n = g.count || 1;
  if (g.id === 'movedDays') {
    return {
      lead: '',
      reason: n > 1 ? 'These days were moved earlier. Keep them there, or move them with the rest?' : 'Keep it there, or move it with the rest?',
      options: label((x) => (x.id === 'keep'
        ? (x.date ? 'Keep it on ' + fmtDay(x.date, opts) : 'Keep their dates')
        : (x.date ? 'Move it to ' + fmtDay(x.date, opts) : 'Move them with the rest'))),
    };
  }
  if (g.id === 'outside') {
    return {
      lead: n > 1 && !ctx.onRow ? n + ' days that were changed earlier fall after the new end.' : '',
      reason: n > 1 ? 'Keep them as events of their own, or delete them?' : 'Keep it as an event of its own, or delete it?',
      options: label((x) => (x.id === 'keep' ? (n > 1 ? 'Keep them on their own' : 'Keep it on its own') : (n > 1 ? 'Delete them' : 'Delete it'))),
    };
  }
  if (/^own[A-Z]/.test(g.id || '')) {
    const noun = FIELD_NOUNS[g.field] || 'value';
    const title = g.field === 'title';
    const old = ctx.oldTitle || '';
    const fresh = g.value || ctx.newTitle || '';
    return {
      lead: n > 1 && !ctx.onRow ? n + ' days that were changed earlier have their own ' + noun + '.' : '',
      reason: n > 1 ? 'Keep their ' + noun + (title ? 's' : '') + ', or give them the new one too?' : 'Keep its ' + noun + ', or give it the new one too?',
      options: label((x) => (x.id === 'keep'
        ? (title && n === 1 && old ? 'Keep ' + quoted(old) : n > 1 ? 'Keep their own ' + noun + (title ? 's' : '') : 'Keep its own ' + noun)
        : (title && fresh ? (n > 1 ? 'Rename them to ' : 'Rename it to ') + quoted(fresh) : (n > 1 ? 'Give them the new ' : 'Give it the new ') + noun))),
    };
  }
  if (g.id === 'edge') {
    const days = andList((g.dates || []).map((d) => fmtDay(d, opts)));
    const one = (g.dates || []).length <= 1;
    return {
      lead: 'The new rule would add ' + days + ' ' + (SIDE[g.side] || SIDE.start) + '.',
      reason: one ? 'Add it, or leave it out?' : 'Add them, or leave them out?',
      options: label((x) => (x.id === 'without' ? 'Leave ' + (one ? 'it' : 'them') + ' out' : 'Add ' + (one ? 'it' : 'them'))),
    };
  }
  if (g.id === 'bound') {
    const count = g.count;
    const from = (ctx.bound && ctx.bound.from) || {};
    const reason = g.counted
      ? 'The series is set to ' + plural(count, 'event', 'events') + (g.splits ? ', and the day you moved would fall after the ' + ordinalWord(count) + '.' : ', and this change moves where they end.')
      : 'The series ends ' + fmtDay(from.last, { weekday: false, ...opts }) + ', and this change gives it a different number of events.';
    return {
      lead: '',
      reason: reason + ' Keep the end date, or keep the count?',
      options: label((x) => {
        const b = x.bound || {};
        const last = b.last ? ', ending ' + fmtDay(b.last, { weekday: false, ...opts }) : '';
        if (x.id === 'end') return 'Keep the end: ' + plural(b.events != null ? b.events : count, 'event', 'events') + last;
        return g.splits ? 'Keep ' + plural(count, 'event', 'events') + '; the moved day becomes an event of its own' : 'Keep ' + plural(count, 'event', 'events') + last;
      }),
    };
  }
  if (g.id === 'zone') {
    const fromTz = g.from, toTz = g.to;
    const first = (x) => (x.next || [])[0];
    return {
      lead: '',
      reason: 'The series moves to ' + zoneName(toTz) + ' time. Keep the clock time, or the same moment?',
      options: label((x) => (x.id === 'clock'
        ? 'Keep the clock time: ' + fmtClock(first(x), toTz) + ' in ' + zoneName(toTz)
        : 'Keep the same moment: ' + fmtClock(first(x), fromTz) + ' in ' + zoneName(fromTz) + ', ' + fmtClock(first(x), toTz) + ' in ' + zoneName(toTz))),
    };
  }
  // The readings of the rule.
  const many = (g.options || []).length;
  const newRule = (g.options || []).some((x) => x.id === 'keep' || x.id === 'map');
  return {
    lead: '',
    reason: newRule
      ? 'The new rule doesn’t have every day the old one had. What should happen to the edited and skipped days?'
      : 'This change can mean ' + (COUNT_WORDS[many] || many) + ' things:',
    options: label((x) => readingLabel(x, ctx)),
  };
}

// A reading's label, from the rule it writes.
function readingLabel(x, ctx) {
  const before = (ctx.rule && ctx.rule.from && ctx.rule.from.rrule) || '';
  if (x.id === 'keep') return 'Keep them where they are, as events of their own';
  if (x.id === 'map') return 'Move them along with the pattern';
  const freq = (ruleParts(x.rrule || before).FREQ || '').toLowerCase();
  const unit = freq === 'monthly' ? 'monthly' : freq === 'yearly' ? 'yearly' : freq === 'daily' ? 'daily' : 'weekly';
  if (x.id === 'pattern') return 'Shift the whole ' + unit + ' pattern';
  if (x.id === 'member') {
    const moved = movedMember(before, x.rrule);
    if (moved) return 'Move every ' + moved[0] + ' to ' + moved[1];
    // The same days moved by whole periods: the member is the day acted on's, where it lands.
    const landed = dayKeyOf(startOfNext((x.next || [])[0]));
    if (landed && unit === 'weekly') return 'Move only the ' + WEEKDAY_NAMES[dateOfDayKey(landed).getDay()] + 's';
    if (landed && unit === 'monthly' && !ruleParts(x.rrule || before).BYDAY) return 'Move only the ' + ordinalDay(Number(landed.slice(8, 10))) + ' of the month';
    return 'Move only this day of the pattern';
  }
  const start = ctx.rule && ctx.rule.to && dayKeyOf(ctx.rule.to.start);
  return upperFirst(describeRepeat(x.rrule || '', { start: start ? dateOfDayKey(start) : null, ends: false }) || 'Another reading');
}

const startOfNext = (n) => (typeof n === 'string' ? n : n && n.start);

// The member a one-member reading moves, by comparing the lists before and after.
function movedMember(before, after) {
  const a = ruleParts(before || ''), b = ruleParts(after || '');
  const list = (p, k) => String(p[k] || '').split(',').filter(Boolean);
  for (const k of ['BYDAY', 'BYMONTHDAY']) {
    const x = list(a, k), y = list(b, k);
    const gone = x.filter((v) => !y.includes(v)), added = y.filter((v) => !x.includes(v));
    if (gone.length === 1 && added.length === 1) {
      if (k === 'BYDAY') {
        const nth = (v) => { const m = /^([+-]?\d)?([A-Z]{2})$/.exec(v); return m && DAY_CODE[m[2]] != null ? (m[1] ? (Number(m[1]) === -1 ? 'last' : ordinalWord(Number(m[1]))) + ' ' : '') + WEEKDAY_NAMES[DAY_CODE[m[2]]] : null; };
        const g = nth(gone[0]), n = nth(added[0]);
        if (!g || !n) return null;
        return /^[A-Z]{2}$/.test(added[0]) ? [g, n] : [g, 'the ' + n];
      }
      return [ordinalDay(Number(gone[0])), 'the ' + ordinalDay(Number(added[0]))];
    }
  }
  return null;
}
// A day of the month: "15th", "last day", "second-last day".
function ordinalDay(n) {
  if (n === -1) return 'last day';
  if (n < 0) return ordinalWord(-n) + '-last day';
  return n + (n % 100 >= 11 && n % 100 <= 13 ? 'th' : ['th', 'st', 'nd', 'rd'][n % 10] || 'th');
}

/** A reading option's dates and consequence lines, from its data. */
export function optionLines(x, { opts = {} } = {}) {
  // From the day acted on until its first date that differs from the other
  // reading's ("… Sun, Jan 30, 2028 · Tue, Feb 29" when that is further on).
  let prev = null;
  const say = (list) => list.map((n) => { const at = typeof n === 'string' ? n : n && n.start; const s = fmtDay(at, { ...opts, prev }); prev = dayKeyOf(at); return s; }).filter(Boolean).join(' · ');
  const first = say(x.dates || x.next || []);
  const then = (x.then || []).length ? say(x.then) : '';
  const dates = then ? first + ' … ' + then : first;
  const note = x.earlier ? 'also changes earlier days, from ' + fmtDay(x.earlier, opts) : '';
  return { dates, note };
}

// ---- refusals and notes ----------------------------------------------------

const REFUSALS = {
  unreadable: 'This repeat came from another app in a form Better-Cal can’t read, so only its details can change here.',
  several: 'This repeat happens several times a day, so its days and times can’t be moved all at once. Move only this one, or change its details for all events.',
  severalAllDay: 'This repeat happens several times a day, so it can’t be made all-day.',
  weekno: 'This repeat counts weeks of the year, so its days can’t follow a drag.',
  overlap: 'Two of its days are the same day in some months, so its days can’t follow a drag.',
  dayset: 'This repeat came from another app and picks its days in a way a drag can’t follow. Move only this one, or open the series to choose its days.',
  google: 'On a Google calendar, changing which days this repeats on comes in a later version. Move only this one, or change its time or details for all events.',
  empty: 'This would leave the series with no events. Delete it instead?',
  member_taken: 'That day is already one of its days.',
  off_weeks: 'Those days would fall in weeks this repeat skips.',
  interval_month: 'Those days would fall in months this repeat skips.',
  past_month_end: 'A day of its pattern would move past the end of its month.',
  count_range: 'A day would have no place in some months.',
};
/** A refusal's sentence, by its code. */
export const refusalWords = (code) => REFUSALS[code] || 'This change can’t be made to the series.';

/** A note's sentence, by its kind and data. */
export function noteWords(n, opts = {}) {
  if (!n) return '';
  if (n.kind === 'google') return 'Google decides what happens to edited days, and Better-Cal hasn’t checked what it does yet. This can’t be undone.';
  if (n.kind === 'gap') return 'There is no ' + fmtClock(n.clock) + ' on ' + fmtDay(n.date, opts) + ' in ' + zoneName(n.tz) + ', so that one is at ' + fmtClock(n.at, n.tz) + '.';
  if (n.kind === 'overlap') return fmtClock(n.at, n.tz) + ' happens twice on ' + fmtDay(n.date, opts) + '; this is the first, in daylight time.';
  return '';
}

/** The line a 409 brings. */
export const CHANGED_WORDS = 'The series changed since this was worked out. Here is the new plan.';
export const WORKING_WORDS = 'Working out the plan…';
export const SAVING_WORDS = 'Saving…';
