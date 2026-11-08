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
  fmtDayMedium, fmtDateFull, fmtDateShort, fmtDateShortYear, fmtTimeIn, fmtTimeInZone,
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

/**
 * The wall date an ISO value or a day key names, as a day key ("2026-11-11"):
 * of a list entry ({start, allDay, tz?}) its start, of a placement ({date,
 * at?, tz?}) its date.
 */
export function dayKeyOf(value) {
  const v = value && typeof value === 'object' ? value.start || value.date : value;
  const m = /^(\d{4}-\d{2}-\d{2})/.exec(String(v || ''));
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
// zone), and a zone the browser can't read is no zone: the smoke suite
// turns this on so such a call fails there; in the app the clock's place
// says it is unavailable instead, so a preview missing a clock never reads
// as complete.
let strictClocks = false;
/** What a clock that can't be said shows in its place. */
export const CLOCK_UNAVAILABLE = 'time unavailable';
export function setStrictClocks(on) { strictClocks = !!on; }

/**
 * A clock time, in the user's 12- or 24-hour setting, of "HH:MM" or of an
 * instant: an ISO string read in `tz` (the plan's zone), or a value of the
 * plan carrying its own zone ({at or start, tz}: a timed day in an all-day
 * plan), read in that zone. With no zone, or one the browser can't read,
 * the clock is unavailable (CLOCK_UNAVAILABLE), never the device's in its
 * place; with no instant there is no clock ("").
 */
export function fmtClock(value, tz = null) {
  if (/^\d{2}:\d{2}$/.test(String(value))) {
    const [h, m] = String(value).split(':').map(Number);
    return fmtTimeIn(new Date(Date.UTC(2000, 0, 1, h, m)), 'UTC');
  }
  const own = value && typeof value === 'object' ? value : null;
  const at = own ? own.at || own.start : value;
  const zone = (own && own.tz) || tz;
  const d = new Date(at);
  const clock = zone && !isNaN(d) ? fmtTimeInZone(d, zone) : '';
  if (clock == null || !zone) {
    if (strictClocks) throw new Error(zone ? 'fmtClock: unreadable zone ' + zone + ' for ' + at : 'fmtClock: no zone for ' + at);
    return isNaN(d) ? '' : CLOCK_UNAVAILABLE;
  }
  return clock;
}

/** A zone as people name it: "Berlin". */
export const zoneName = (tz) => tzCity(tz);

// ---- the rule and its bound ------------------------------------------------

/**
 * A rule in words with its clock: "weekly on Tuesday at 7:00 PM"; with
 * `zoned`, its zone's name after the clock: "weekly on Tuesday at 7:00 PM
 * in Berlin", for a sentence about a change of zone.
 */
export function ruleWords(part, zoned = false) {
  if (!part || !part.rrule) return '';
  const start = dayKeyOf(part.start);
  const words = describeRepeat(part.rrule, { start: start ? dateOfDayKey(start) : null, ends: false }) || 'on a custom schedule';
  const at = !part.allDay && part.start ? fmtClock(part.start, part.tz) : '';
  const clock = at ? ' at ' + at + (zoned && part.tz ? ' in ' + zoneName(part.tz) : '') : '';
  // "on the 31st at 6:00 PM, skipping months without one", never the clock after the clause.
  const clause = /(, skipping months without one|, or the last day(?: in shorter months)?)$/.exec(words);
  if (clause) return lowerFirst(words.slice(0, clause.index)) + clock + clause[1];
  return lowerFirst(words) + clock;
}

// The day a bound's UNTIL names, read in its rule's zone (an all-day rule's
// dates are UTC), never the device's: `rule` is the plan's rule.to.
const untilDay = (until, rule) => (until ? untilDayKey(until, rule ? rule.tz || 'UTC' : null) : null);

/**
 * The bound as the Change sentence ends: "with no end, as before", "with no
 * end (was 6 events)", "ending Nov 1, as before", "ending Nov 1 (was Oct 31)",
 * "for 6 events, as before", "for 10 events (was 6)". `rule` is the plan's
 * rule, whose `to` zone its UNTIL is read in.
 */
export function boundPhrase(bound, opts = {}, rule = null) {
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
  const last = bound.last || untilDay(bound.until, rule && rule.to);
  const ending = 'ending ' + fmtDay(last, { weekday: false, ...opts });
  if (from.kind === 'until') return ending + (from.last === last ? ', as before' : ' (was ' + fmtDay(from.last, { weekday: false, ...opts }) + ')');
  return ending;
}

/**
 * CHANGE: "From weekly on Tuesday at 7:00 PM to weekly on Wednesday at 7:00
 * PM, with no end, as before." A timed series given another zone names
 * each side's zone after its clock, as the Activity entry does.
 */
export function changeSentence(rule, bound, opts = {}) {
  const a0 = rule && rule.from, b0 = rule && rule.to;
  const zoned = !!(a0 && b0 && !a0.allDay && !b0.allDay && a0.tz && b0.tz && a0.tz !== b0.tz);
  const from = ruleWords(a0, zoned);
  const to = ruleWords(b0, zoned);
  const end = boundPhrase(bound, opts, rule);
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
  const clock = !part.allDay && part.start ? fmtClock(part.start, part.tz) : '';
  // A timed rule whose clock can't be said has no shape to compare by clock:
  // two unavailable clocks are not the same clock.
  if (!m || (!part.allDay && (!clock || clock === CLOCK_UNAVAILABLE))) return null;
  return {
    period: PERIODS[m[1]] || lowerFirst(m[1]), weekly: m[1] === 'Weekly', days: m[2] || '', clause: m[3] || '',
    clock, zone: part.allDay ? '' : part.tz || '',
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

// ---- a split ------------------------------------------------------------------

/**
 * CHANGE, first line of a split ("this and following", delete following, an
 * answer from a day on): what the events before the day acted on become.
 * "Events before Tue, Jan 19 stay as they are, now ending Jan 12 (2 events)."
 */
export function splitLine(split, opts = {}) {
  if (!split || !split.before || !split.day) return '';
  const b = split.before.bound || {};
  const n = b.events != null ? ' (' + plural(b.events, 'event', 'events') + ')' : '';
  const ending = b.last ? ', now ending ' + fmtDay(b.last, { weekday: false, ...opts, prev: split.day }) : '';
  return 'Events before ' + fmtDay(split.day, opts) + ' stay as they are' + ending + n + '.';
}

/** CHANGE of delete following: "Deletes Tue, Jan 19 and every event after it." */
export const deleteSentence = (split, opts = {}) => (split && split.day ? 'Deletes ' + fmtDay(split.day, opts) + ' and every event after it.' : '');

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
  const last = bound && (bound.last || untilDay(bound.until, rule && rule.to));
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

/**
 * Under AFFECTS, one sentence per class of days the change loses or adds
 * (series-model.md, Bounds: Losses and gains by class), from the class's
 * data: its first days, its count for a bounded series, its rate for one
 * with no end. "No 31st in some months, so Feb 15, Apr 15 and Jun 15 have
 * no event, and 5 in every 12 months after them.", "Jan 4 and Jan 11 are
 * before the new start.", "Nov 15 and Dec 15 are after the new end.",
 * "Adds Mar 1, May 1 and Jul 1, and 5 in every 12 months after them."
 */
export function classLines(losses, gains, opts = {}) {
  const say = (c) => {
    let prev = null;
    const days = (c.dates || []).map((d) => { const s = fmtDay(d, { ...opts, weekday: false, prev }); prev = dayKeyOf(d); return s; }).filter(Boolean);
    const more = c.count != null && c.count > days.length ? c.count - days.length : 0;
    const list = andList(more ? [...days, more + ' more'] : days);
    const one = days.length === 1 && !more;
    const e = c.every;
    const rate = e ? ', and ' + (e.span === 1 ? (e.count === 1 ? '' : e.count + ' ') + 'every ' + e.unit : e.count + ' in every ' + e.span + ' ' + e.unit + 's') + ' after ' + (one ? 'it' : 'them') : '';
    return { list, one, rate };
  };
  const out = [];
  for (const c of losses || []) {
    const { list, one, rate } = say(c);
    if (!list) continue;
    if (c.why === 'start') out.push(list + (one ? ' is' : ' are') + ' before the new start.');
    else if (c.why === 'end') out.push(list + (one ? ' is' : ' are') + ' after the new end.');
    else out.push((c.member ? 'No ' + c.member + ' in some ' + (c.period || 'month') + 's, so ' : '') + list + (one ? ' has' : ' have') + ' no event' + rate + '.');
  }
  for (const c of gains || []) {
    const { list, rate } = say(c);
    if (list) out.push('Adds ' + list + rate + '.');
  }
  return out;
}

/**
 * Header line 3, when the edit started from a day with something of its
 * own: "Started from 'Sample practice 6' on Fri, Dec 11.", the date being
 * where that day showed (its `before` placement), where the person started.
 */
export function startedLine(acted, opts = {}) {
  if (!acted || !(acted.kept || []).length) return '';
  const on = fmtDay(acted.before && acted.before.date, opts);
  return 'Started from ' + dayName(acted, opts.series) + (on ? ' on ' + on : '') + '.';
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

/** The verb of a change: Move for a drag, Rename for a title-only save, Delete for a delete, Change for the rest. */
export function verbOf(kind, edits = []) {
  if (kind === 'drag') return 'Move';
  if (kind === 'delete') return 'Delete';
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
  // References that keep a day's row (OwnFields::REFERENCES): its trip, a duplicate decision, plugin data.
  duplicates: 'duplicate decision', pluginData: 'plugin data',
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
 * What changes on a day besides where it goes: "is renamed to 'Chorus'",
 * "takes the new reminders". Its date, time and all-day setting are said by
 * its outcome, so they are never among these. What it keeps is never said;
 * only what changes.
 */
export function changesWords(d, opts = {}) {
  const takes = (d.takes || []).filter((f) => !PLACE_FIELDS.includes(f));
  const renamed = takes.includes('title') && opts.newTitle ? 'is renamed to ' + quoted(opts.newTitle) : '';
  const rest = takes.filter((f) => !(renamed && f === 'title'));
  return [renamed, rest.length ? 'takes the new ' + fieldList(rest) : ''].filter(Boolean).join(' and ');
}
const PLACE_FIELDS = ['date', 'time', 'allDay'];
const CLOCK_FIELDS = ['time', 'allDay'];
const hasAny = (list, fields) => (list || []).some((f) => fields.includes(f));

// What a day has of its own, said after its name only when it is more
// than its title: ", with its own title and location,", ", in a trip,",
// ", with its own location and in a trip,". Its own all-day setting reads
// by the day's kind (its placement, after the change or else before): an
// all-day day is ", all-day,", a timed one ", with a time of its own,",
// joined with the rest: ", all-day and with its own location,", ", with
// its own location and a time of its own,".
function ownAside(d) {
  const own = contentOf(d.kept);
  if (!own.some((f) => f !== 'title')) return '';
  const fields = own.filter((f) => f !== 'trip' && f !== 'allDay');
  const kind = own.includes('allDay') ? ((d.after || d.before || {}).at ? 'timed' : 'all-day') : null;
  const ownTime = kind === 'timed' ? (fields.length ? ' and a time of its own' : 'with a time of its own') : '';
  const parts = [
    kind === 'all-day' ? 'all-day' : '',
    (fields.length ? 'with its own ' + fieldList(fields) : '') + ownTime,
    own.includes('trip') ? 'in a trip' : '',
  ].filter(Boolean);
  return ', ' + parts.join(' and ') + ',';
}

/**
 * Whether a day's row says the clock it shows at after the change: it is
 * timed there (its `after` has `at`) and the clock is news, not the series'
 * time the Change sentence already gives: the day's own (it keeps its time
 * or its all-day setting), one this change gives it (it takes them, or it
 * becomes timed: its `after` names a new `kind`), or one in a zone other
 * than the plan's (its `after` names `tz`: a timed day in an all-day plan),
 * which is always said with the zone's name.
 */
export function saysClock(d) {
  const a = d && d.after;
  return !!(a && a.at) && (hasAny(d.kept, CLOCK_FIELDS) || hasAny(d.takes, CLOCK_FIELDS) || !!a.kind || !!a.tz);
}

/**
 * Whether that clock is the day's alone, so its row is its own: its own
 * time, or a clock in another zone. A clock it takes from the series is
 * the same for every day that takes it, so those days can share a row.
 */
export function ownClock(d) {
  const a = d && d.after;
  return !!(a && a.at) && (hasAny(d.kept, CLOCK_FIELDS) || !!a.tz);
}

/**
 * A placement's clock as a row says it: "8:00 PM", read in its own zone
 * when it names one, else the plan's (`opts.tz`), and then with that zone's
 * name: "8:00 PM in Los Angeles". `named` names the plan's zone too, for a
 * clock that follows a sentence naming another. A clock that can't be
 * said is "time unavailable", with its zone's name all the same.
 */
export function clockOf(place, opts = {}, named = false) {
  const clock = place && place.at ? fmtClock(place, opts.tz) : '';
  return clock && (place.tz || named) ? clock + ' in ' + zoneName(place.tz || opts.tz) : clock;
}

/**
 * The zone a placement's date is read in: a timed one's own (`tz`), else
 * the plan's (`tz` here); an all-day date is itself, in no zone (null).
 */
export const placeZone = (place, tz = null) => (place && place.at ? place.tz || tz || null : null);
/**
 * Whether two dates may be compared, one repeated or left out against the
 * other: only when they are read in the same zone. A date in no zone (an
 * all-day one) is itself in any.
 */
export const sameZone = (a, b) => !a || !b || a === b;

// Where a day shows after the change, as its row says it: its date, left
// out when the sentence has just said that date in the same zone (`prev`, a
// day key, and `same`), and its clock when the row says one (saysClock).
// `full` is its date always.
function placeOf(d, prev, opts, same = true) {
  const a = d.after || {};
  const full = fmtDay(a.date, { ...opts, prev });
  return { date: a.date && !(same && a.date === prev) ? full : '', clock: saysClock(d) ? clockOf(a, opts) : '', full };
}
// "at 9:30 PM on Mon, Jan 11", "at 9:30 PM", "on Mon, Jan 11" or "".
const atOn = (p) => (p.clock ? 'at ' + p.clock + (p.date ? ' on ' + p.date : '') : p.date ? 'on ' + p.date : '');
const spaced = (s) => (s ? ' ' + s : '');

// What happens to a day now, by its outcome, and for a day that moves, by
// the kind its `after` names when the change gives it another one. Each
// says the placement after the change, never a date compared with another.
const OUTCOMES = {
  // "moves to Wed, Jan 13 at 1:30 AM", "moves to 8:00 PM", "moves to Wed, Jan 13".
  moves: (p) => 'moves to ' + (p.date && p.clock ? p.date + ' at ' + p.clock : p.date || p.clock || p.full),
  'all-day': (p) => 'becomes all-day' + spaced(atOn(p)),
  // "now starts at 6:00 PM[ on Wed, Jan 13]": a timed placement has a clock, and a new kind is news (saysClock),
  // "now starts at time unavailable" when its zone can't be read; with no clock at all, "is no longer all-day[ and moves to Wed, Jan 13]".
  timed: (p) => (p.clock ? 'now starts ' + atOn(p) : 'is no longer all-day' + (p.date ? ' and moves to ' + p.date : '')),
  stays: (p) => 'stays ' + (atOn(p) || 'where it is'),
  separate: (p) => 'becomes an event of its own' + spaced(atOn(p)),
  deleted: () => 'is deleted',
  rejoins: () => 'goes back to the series; nothing of its own is left',
};
// Outcomes that say all that happens to a day: a deleted day has nothing
// left to change, and a day going back to the series takes everything.
const SAYS_ALL = new Set(['deleted', 'rejoins']);
/** The name of what happens to a day: its outcome, or the kind a day that moves becomes. */
export const outcomeOf = (d) => (d.outcome === 'moves' && d.after && d.after.kind) || d.outcome;

/**
 * An edited day's row, in three parts: the day it is about (its name and
 * its day: "'Sample practice' on Tue, Jan 12"), what was done to it earlier
 * when it has a date of its own ("was moved earlier to Wed, Jan 13", with
 * its zone when that date is in another), and what happens to it now (its
 * outcome, from its placement after the change), then what changes on it.
 * Never what it keeps. The day acted on, leaving the series, is named as
 * the day you moved.
 */
export function dayLines(d, opts = {}) {
  const acted = d.kind === 'acted';
  const name = acted ? 'The day you moved' : dayName(d, opts.series) + ownAside(d) + (d.day ? ' on ' + fmtDay(d.day, opts) : '');
  // The date the sentence has just said and the zone it is read in: the
  // date set on it, in its own zone, or the day it is about, in the old
  // series' (`opts.dayTz`). Where it goes is left out against it only in
  // the same zone.
  const said = d.ownDate ? d.ownDate.date : acted ? null : d.day;
  const saidTz = d.ownDate ? placeZone(d.ownDate, opts.tz) : opts.dayTz || null;
  const same = sameZone(saidTz, placeZone(d.after, opts.tz));
  const place = placeOf(d, said, opts, same);
  // The date set on it, with its zone when that isn't the plan's, named
  // once: not again when the clock after it is in the same zone and says so.
  const ownZone = d.ownDate && d.ownDate.tz && !(place.clock && d.after.tz === d.ownDate.tz) ? ' in ' + zoneName(d.ownDate.tz) : '';
  // A clock after a date named in another zone names its own, and so does
  // one whose date is said again only because the date named before it is
  // in another zone: the zone is why it is said.
  const repeated = !same && !!place.date && (d.after || {}).date === said;
  if ((ownZone || repeated) && place.clock) place.clock = clockOf(d.after, opts, true);
  const own = d.ownDate ? 'was moved earlier to ' + fmtDay(d.ownDate.date, { ...opts, prev: d.day }) + ownZone : '';
  const say = OUTCOMES[outcomeOf(d)];
  const now = say ? say(place) : '';
  const changes = SAYS_ALL.has(d.outcome) ? '' : changesWords(d, opts);
  const line1 = name + spaced([own, now].filter(Boolean).join(' and ')) + (changes ? (own ? ', and ' : ' and ') + changes : '') + '.';
  return { line1, line2: '' };
}

/** A row of several days: their dates ("Nov 3 to Jan 19, 2027") and the button that shows them. */
export function daysSpan(count, first, last, opts = {}) {
  const range = first === last ? fmtDay(first, { weekday: false, ...opts }) : fmtDay(first, { weekday: false, ...opts }) + ' to ' + fmtDay(last, { weekday: false, ...opts, prev: first });
  return { range, toggle: (open) => (open ? 'Hide' : 'Show ' + count + ' days') };
}

/**
 * A group of days with the same outcome (outcomeOf): "10 days that were
 * changed earlier move to Wednesday." then "Nov 3 to Jan 19, 2027".
 */
export function groupLines({ count, outcome, weekday = null, shift = null, clock = null, takes = [], first, last }, opts = {}) {
  const GROUPS = {
    moves: () => (weekday != null ? 'move to ' + WEEKDAY_NAMES[weekday]
      : shift ? 'move ' + (Math.abs(shift) === 1 ? 'a day' : Math.abs(shift) + ' days') + (shift > 0 ? ' later' : ' earlier')
        : clock ? 'move to ' + clock : 'move with their events'),
    'all-day': () => 'become all-day',
    timed: () => (clock ? 'now start at ' + clock : 'are no longer all-day'),
    stays: () => 'stay where they are',
  };
  // A group says what happens to all of its days: where they go, by their
  // outcome, and what every one of them takes from the change (a new
  // length, the new reminders), as a day's own row does; what only some
  // take is said on their rows when the group is opened.
  const does = (GROUPS[outcome] || GROUPS.stays)();
  const taken = (takes || []).filter((f) => !PLACE_FIELDS.includes(f));
  const renamed = taken.includes('title') && opts.newTitle ? 'are renamed to ' + quoted(opts.newTitle) : '';
  const rest = taken.filter((f) => !(renamed && f === 'title'));
  const also = [renamed, rest.length ? 'take the new ' + fieldList(rest) : ''].filter(Boolean).join(' and ');
  return { line1: count + ' days that were changed earlier ' + does + (also ? ' and ' + also : '') + '.', ...daysSpan(count, first, last, opts) };
}

const isOwn = (g) => /^own[A-Z]/.test(g.id || '');
const pluralNoun = (noun) => (/s$|people|data$/.test(noun) ? noun : noun + 's');

/**
 * A day's row when choices are about it alone: what they are about.
 * "'Sample practice' on Tue, Jan 12 has its own title, 'Sample practice',
 * and its own location." / "... falls after the new end." Other choices
 * (a date of its own) keep the day's own sentence.
 */
export function choiceDayLine(d, groups, opts = {}) {
  const on = d.day ? ' on ' + fmtDay(d.day, opts) : '';
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
  if (g.id === 'bound' && g.at) {
    // A count changed from a day on (a split, `at` its day): the series'
    // whole count, the part taking what is left, or that many from the day.
    const day = fmtDay(g.at, { weekday: false, ...opts });
    return {
      lead: '',
      reason: 'You set ' + plural(g.count, 'event', 'events') + '. Count them in the whole series, or from ' + day + ' on?',
      options: label((x) => {
        const b = x.bound || {};
        const last = b.last ? ', ending ' + fmtDay(b.last, { weekday: false, ...opts }) : '';
        return x.id === 'whole'
          ? g.count + ' in the whole series: ' + plural(b.events != null ? b.events : 0, 'event', 'events') + ' from ' + day + last
          : plural(g.count, 'event', 'events') + ' from ' + day + last;
      }),
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
    // The option's clocks are the series' start after the change under it
    // (ISO in the plan's zone), read in each zone the label names, so a clock
    // and its zone's name always go together. Never its next list, whose first
    // timed entry can be an edited day at a clock of its own.
    // A clock that can't be said shows as unavailable beside its zone's name;
    // an option without a timed start (an older server) has no clocks to list.
    const inZone = (x, tz) => { const c = /T\d{2}:\d{2}/.test(x.start || '') ? fmtClock(x.start, tz) : ''; return c ? c + ' in ' + zoneName(tz) : ''; };
    const listed = (head, parts) => head + (parts.filter(Boolean).length ? ': ' + parts.filter(Boolean).join(', ') : '');
    return {
      lead: '',
      reason: 'The series moves to ' + zoneName(toTz) + ' time. Keep the clock time, or the same moment?',
      options: label((x) => (x.id === 'clock'
        ? listed('Keep the clock time', [inZone(x, toTz)])
        : listed('Keep the same moment', [inZone(x, fromTz), inZone(x, toTz)]))),
    };
  }
  if (ADOPTION[g.id]) return ADOPTION[g.id](g, o, label, ctx);
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

// A span of time in words: "half an hour", "an hour", "1 hour 30 minutes", "45 minutes".
export function spanWords(secs) {
  const m = Math.round(Math.abs(secs) / 60);
  if (m === 30) return 'half an hour';
  if (m === 60) return 'an hour';
  const h = Math.floor(m / 60), r = m % 60;
  const hours = h ? plural(h, 'hour', 'hours') : '';
  const mins = r ? plural(r, 'minute', 'minutes') : '';
  return [hours, mins].filter(Boolean).join(' ') || '0 minutes';
}
const daysWords = (n) => (Math.abs(n) === 1 ? 'a day' : Math.abs(n) + ' days') + (n > 0 ? ' later' : ' earlier');
const lowerRule = (rrule) => lowerFirst(describeRepeat(rrule || '', { ends: false }) || '');
// The day a rule names, for "Friday becomes its day": a weekday, the nth of the month, or the day.
function dayNamed(rrule) {
  const p = ruleParts(rrule || '');
  if (p.FREQ === 'WEEKLY' && /^[A-Z]{2}$/.test(p.BYDAY || '')) return WEEKDAY_NAMES[DAY_CODE[p.BYDAY]];
  if (p.FREQ === 'MONTHLY' && /^\d+$/.test(p.BYMONTHDAY || '') && !p.BYDAY) return 'The ' + ordinalDay(Number(p.BYMONTHDAY));
  return 'Its new day';
}

/**
 * The adoptions (series-model.md, Changing the Series from an Edited Day,
 * and the Dialog section's one line per sentence kind): the day acted on
 * has its own date, time or length, and its new one can become the
 * series' own, or every event moves by the same amount. Each option says
 * the schedule it makes. `g.shift` is the same-way change (days, or
 * seconds), `g.length` the new length; a day option carries its `rrule`,
 * a time option its series' `start`.
 */
const ADOPTION = {
  day: (g, o, label) => ({
    lead: '',
    reason: 'This day was moved earlier to a date of its own. Should its new day become the series’ day, or every event move the same way?',
    options: label((x) => (x.id === 'adopt'
      ? dayNamed(x.rrule) + ' becomes its day: ' + lowerRule(x.rrule)
      : 'Every event moves ' + daysWords(g.shift || 0) + ': ' + lowerRule(x.rrule))),
  }),
  time: (g, o, label, ctx) => {
    const tz = (ctx.opts || {}).tz || null;
    const at = (x) => fmtClock(x.start, tz);
    const adopt = o('adopt');
    return {
      lead: '',
      reason: 'This day has a time of its own. Should its new time become the series’ time, or every event move by the same amount?',
      options: label((x) => (x.id === 'adopt'
        ? at(x) + ' becomes its time: every event at ' + at(x)
        : 'Every event moves ' + spanWords(g.shift || 0) + ((g.shift || 0) > 0 ? ' later' : ' earlier') + ': ' + at(x) + ', this one at ' + at(adopt))),
    };
  },
  length: (g, o, label) => ({
    lead: '',
    reason: 'This day has a length of its own. Should its new length become the series’ length, or every event change by the same amount?',
    options: label((x) => (x.id === 'adopt'
      ? upperFirst(spanWords(g.length || 0)) + ' becomes its length: every event ' + spanWords(g.length || 0) + ' long'
      : 'Every event ' + ((g.shift || 0) > 0 ? 'lengthens' : 'shortens') + ' by ' + spanWords(g.shift || 0) + ', this one ' + spanWords(g.length || 0) + ' long')),
  }),
};

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
  googleAllDay: 'At Google, a day of an all-day series can’t have a time of its own, so this change can’t be made while an edited day keeps one. Give that day the series’ all-day setting first, or change only this one.',
  empty: 'This would leave the series with no events. Delete it instead?',
  member_taken: 'That day is already one of its days.',
  off_weeks: 'Those days would fall in weeks this repeat skips.',
  interval_month: 'Those days would fall in months this repeat skips.',
  past_month_end: 'A day of its pattern would move past the end of its month.',
  count_range: 'A day would have no place in some months.',
};
// A split's own refusals (this and following, delete following, an answer
// from a day on: Series\Split::REASONS). Its plan carries scope 'following'.
const SPLIT_REFUSALS = {
  shape: 'This repeat can’t be split on this day: started there, it wouldn’t give the same days. Change only this one, or all events.',
};
// The same reason in one line, where a scope chooser leaves a scope out
// (app/scopes.js): a scope refused for every occurrence of a calendar kind
// is not offered there, and these say why in its place. None since 1.5.4:
// a Google calendar takes every scope.
const UNOFFERED = {};
/** A refusal's sentence, by its code (and the plan's scope: a split's are its own). */
export const refusalWords = (code, scope) => (scope === 'following' && SPLIT_REFUSALS[code]) || REFUSALS[code] || 'This change can’t be made to the series.';
/** Why a scope chooser leaves "This and following" out, by the split refusal's code. */
export const unofferedWords = (code) => UNOFFERED[code] || null;

/** A note's sentence, by its kind and data. */
export function noteWords(n, opts = {}) {
  if (!n) return '';
  if (n.kind === 'google') return 'This change is sent to Google and read back from it: the calendar shows what Google stores.';
  // A note's clocks are its point. Its subject is its clock when that can be
  // said, and "a clock time" with its time unavailable when its zone can't be
  // read (never the device's clock); a note with no instant says what it can.
  const at = fmtClock(n.at, n.tz);
  const unavailable = at === CLOCK_UNAVAILABLE;
  const where = (date) => ' on ' + fmtDay(date, opts) + ' in ' + zoneName(n.tz);
  if (n.kind === 'gap') {
    if (unavailable) return 'A clock time is skipped' + where(n.date) + '; its time is unavailable.';
    const missing = fmtClock(n.clock);
    return missing ? 'There is no ' + missing + where(n.date) + (at ? ', so that one is at ' + at : '') + '.' : '';
  }
  if (n.kind === 'overlap') {
    if (!at) return '';
    // Which of the two instants the series is at, and whether that one is in
    // daylight time, come from the note: a note without them says neither.
    const fold = n.fold === 'first' || n.fold === 'second' ? n.fold : null;
    const kind = n.daylight === true ? 'daylight' : n.daylight === false ? 'standard' : null;
    const which = fold ? '; this is the ' + fold + (kind ? ', in ' + kind + ' time' : '') : '';
    return (unavailable ? 'A clock time' : at) + ' happens twice' + where(n.date) + (unavailable ? '; its time is unavailable' : '') + which + '.';
  }
  return '';
}

/** The line a 409 brings. */
export const CHANGED_WORDS = 'The series changed since this was worked out. Here is the new plan.';
export const WORKING_WORDS = 'Working out the plan…';
export const SAVING_WORDS = 'Saving…';
