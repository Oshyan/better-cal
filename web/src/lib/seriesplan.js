// A series plan (docs/design/series-model.md, The Plan) in the blocks the
// series change panel shows, top to bottom. Pure, no DOM: the panel
// (app/SeriesDialog.js) draws what this returns.
//
// The plan is data. This module decides the blocks and rows (which days
// have rows of their own, which go together, where each choice sits); every
// sentence in them comes from lib/planwords.js, in the user's format. The
// server's `words` fields are for API callers and are never read here.

import * as W from './planwords.js';

const WEEKDAY = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTH = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const wordsOf = (x) => (x == null ? '' : typeof x === 'string' ? x : x.words || '');

/**
 * The plan's zone (series-model.md, One zone): the series' after the
 * change, in which every timed value of the plan is written; null for a
 * series all-day after it, whose dates are themselves and whose timed days
 * carry their own `tz`. A plan from a server before `zone` names the zone
 * in its rule. Never the device's or an occurrence's: an occurrence from
 * the calendar's window carries no zone of its own.
 */
export function planZone(plan) {
  if (plan && 'zone' in plan) return plan.zone || null;
  const to = plan && plan.rule && plan.rule.to;
  return (to && to.tz) || null;
}

/**
 * The zone a day's `day` (the day it is about, its old key's day) is read
 * in: the series' before the change, null when it was all-day (its days are
 * dates).
 */
export function dayZone(plan) {
  const from = plan && plan.rule && plan.rule.from;
  return from && !from.allDay ? from.tz || null : null;
}

// The wall date an ISO value names: its own date when it carries an offset
// (the plan writes occurrences on the series' clock), the date in `tz` for
// a UTC key ("...Z", an occurrence's RECURRENCE-ID), a bare date as itself.
export function wallDate(iso, tz = null) {
  const s = String(iso || '');
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
  if (!m) return null;
  if (/Z$/i.test(s) && /T/.test(s)) {
    const d = new Date(s);
    if (isNaN(d)) return null;
    if (tz) {
      try {
        const parts = new Intl.DateTimeFormat('en-US', { timeZone: tz, year: 'numeric', month: 'numeric', day: 'numeric' }).formatToParts(d);
        const get = (t) => Number(parts.find((p) => p.type === t).value);
        return { y: get('year'), mo: get('month'), d: get('day') };
      } catch { /* an unknown zone reads as UTC */ }
    }
    return { y: d.getUTCFullYear(), mo: d.getUTCMonth() + 1, d: d.getUTCDate() };
  }
  return { y: Number(m[1]), mo: Number(m[2]), d: Number(m[3]) };
}

/**
 * A plan date as the examples write it: "Wed 10 Mar", or "10 Mar" without
 * the weekday, with the year only when it isn't `refYear` ("29 Feb 2028").
 * A UTC key is read in `tz`; the panel always passes one (the plan's
 * zone), so a Tuesday evening in Los Angeles is never read as the
 * Wednesday its UTC instant falls on.
 */
export function planDate(iso, { weekday = true, tz = null, refYear = new Date().getFullYear() } = {}) {
  const w = wallDate(iso, tz);
  if (!w) return '';
  const day = new Date(Date.UTC(w.y, w.mo - 1, w.d)).getUTCDay();
  return (weekday ? WEEKDAY[day] + ' ' : '') + w.d + ' ' + MONTH[w.mo - 1] + (w.y !== refYear ? ' ' + w.y : '');
}

const startOf = (n) => (typeof n === 'string' ? n : n && n.start);

// A refusal is of the whole plan unless it names an option (or a group).
function refusalFor(plan, groupId, optionId) {
  return (plan.refusals || []).find((r) => r && r.option === optionId && (r.group == null || r.group === groupId));
}
/** The whole plan's refusal in words (by its code), or null. */
export function planRefusal(plan) {
  const r = (plan && plan.refusals || []).find((x) => x && x.option == null && x.group == null);
  return r ? W.refusalWords(r.code) : null;
}

// The choice each group is on: the person's, where it is still an option,
// else the plan's selected one.
export function chosen(plan, choices = {}) {
  const out = {};
  for (const g of (plan && plan.groups) || []) {
    const mine = choices[g.id];
    const ok = (id) => (g.options || []).some((o) => o.id === id && !o.disabled && !refusalFor(plan, g.id, o.id));
    out[g.id] = mine != null && ok(mine) ? mine : g.selected;
  }
  return out;
}

// The readings of the rule, the adoptions of the day acted on's own date,
// time and length, the bound and the zone are drawn under Change: each is
// about the series, not about a day with a row.
const CHANGE_GROUPS = new Set(['day', 'time', 'length', 'reading', 'bound', 'zone']);
// The days a group is about: its `day`, or its `days`.
const namesOf = (g) => (g.day != null ? [g.day] : Array.isArray(g.days) ? g.days : []);
const push = (map, key, value) => map.set(key, [...(map.get(key) || []), value]);

const dayNumber = (key) => (key ? Date.UTC(+key.slice(0, 4), +key.slice(5, 7) - 1, +key.slice(8, 10)) / 86400000 : null);

/**
 * Everything the panel shows, from a plan (null while it is being worked
 * out), every sentence from lib/planwords.js, every date and time in the
 * plan's zone (planZone). ctx: kind ('drag' | 'resize' | 'editor'), title
 * (the series'), calendar (its name), scope, choices (picked so far),
 * refYear, fields ([{label, words}] the editor changed), edits (the field
 * names the change sends, for its verb), newTitle (a rename's), changed (a
 * 409 replaced the plan).
 */
export function planView(plan, ctx = {}) {
  const verb = W.verbOf(ctx.kind, ctx.edits || []);
  const title = W.titleWords(verb, ctx.scope);
  const opts = { refYear: ctx.refYear, tz: planZone(plan), dayTz: dayZone(plan), series: ctx.title || '', newTitle: ctx.newTitle || '' };
  const view = {
    heading: title + (ctx.title ? ' · ' + ctx.title : ''),
    title,
    verb,
    name: ctx.title || '',
    series: W.seriesLine(ctx.title, ctx.calendar),
    started: '',
    action: title,
    onlyThis: W.onlyThisWords(verb),
    loading: !plan,
    changed: ctx.changed ? W.CHANGED_WORDS : '',
    working: W.WORKING_WORDS,
    saving: W.SAVING_WORDS,
    fields: [],
    refused: null,
    split: '',
    change: '',
    readings: [],
    comingUp: '',
    affects: '',
    edited: [],
    skipped: [],
    notes: [],
    count: null,
    canConfirm: false,
  };
  if (!plan) return view;
  const picked = chosen(plan, ctx.choices);
  const days = plan.days || [];
  const byKey = new Map(days.filter((d) => d.key).map((d) => [d.key, d]));
  // A group in words and its radios, in the plan's order, its preselection first.
  const choice = (g, extra = {}) => {
    const w = W.choiceWords(g, { opts, rule: plan.rule, bound: plan.bound, newTitle: ctx.newTitle, ...extra });
    return {
      id: g.id,
      lead: w.lead,
      reason: w.reason,
      options: (g.options || []).map((o, i) => {
        const refusal = refusalFor(plan, g.id, o.id);
        const disabled = !!(o.disabled || refusal);
        const lines = CHANGE_GROUPS.has(g.id) && g.id !== 'bound' ? W.optionLines(o, { opts }) : { dates: '', note: '' };
        return {
          id: o.id, label: w.options[i].label, selected: picked[g.id] === o.id, disabled,
          reason: disabled ? W.refusalWords(refusal ? refusal.code : o.code) : '', dates: lines.dates, note: lines.note,
        };
      }),
    };
  };
  // The editor's lines; the trip flag's says when the days differed, from the plan.
  const trip = (plan.fields || []).find((f) => f && f.field === 'is_container');
  view.fields = (ctx.fields || []).map((f) => W.fieldSentence(f.field === 'isContainer' && trip ? { ...f, words: W.tripWords(trip) } : f));
  view.refused = planRefusal(plan);
  // A split says what the events before the day acted on become, then the
  // part from the day as any whole-series change; a delete following has
  // no part, so its change is the delete itself.
  const deletes = plan.action === 'delete';
  view.split = view.refused ? '' : W.splitLine(plan.split, opts);
  view.change = view.refused ? '' : deletes ? W.deleteSentence(plan.split, opts) : W.changeSentence(plan.rule, plan.bound, opts);
  view.comingUp = deletes ? '' : W.comingUp(plan.next, plan.nextMore, opts);
  view.started = W.startedLine(plan.acted, opts);
  view.count = plan.counts ? plan.counts.events : null;
  view.affects = view.refused || deletes ? '' : W.affects(plan.counts, plan.bound, opts, plan.days, plan.rule);

  // Every group is drawn once, by its id: the readings, the bound and the
  // zone under Change; a day the new rule would add among the removed days;
  // every other group on the row of the days it is about, of those with
  // rows: one day's own row, or the row of the days it is about together.
  // A group about no day with a row is a row of its own.
  const rowDays = days.filter((x) => x.kind !== 'skipped' && !(x.kind === 'acted' && x.outcome === 'moves'));
  const hasRow = new Set(rowDays.map((d) => d.key));
  const onDay = new Map(); // a day's key -> the groups about it alone
  const onDays = new Map(); // the keys of days a group is about together -> those groups
  const editedChoices = [];
  const skippedChoices = [];
  for (const g of plan.groups || []) {
    const names = [...new Set(namesOf(g).filter((k) => hasRow.has(k)))].sort();
    if (CHANGE_GROUPS.has(g.id)) view.readings.push(choice(g));
    else if (g.id === 'edge') skippedChoices.push({ key: 'choice:' + g.id, line1: '', line2: '', choices: [choice(g)] });
    else if (names.length === 1) push(onDay, names[0], g);
    else if (names.length > 1) push(onDays, names.join(' '), g);
    else editedChoices.push({ key: 'choice:' + g.id, line1: '', line2: '', choices: [choice(g)] });
  }

  // Edited days. A day that does something of its own (stays at its own
  // date or time, becomes an event of its own, is deleted, goes back to the
  // series) or has a choice about it alone has its own row, first. Days a
  // choice is about together are its row. The rest that go with their
  // events the same way are one row, by their outcome alone (what they keep
  // or take never splits it). The day acted on is in Coming up.
  const together = new Set([...onDays.keys()].flatMap((k) => k.split(' ')));
  const plainRow = (d) => ({ key: d.key, ...W.dayLines(d, opts), choices: [] });
  const rows = [];
  const outcomes = new Map();
  for (const d of rowDays) {
    const gs = onDay.get(d.key) || [];
    // A day of its own: an outcome no other day shares, a date of its own, or a clock of its own.
    const ownOutcome = !(d.outcome === 'moves' || d.outcome === 'stays') || !!d.ownDate || W.ownClock(d);
    if (gs.length) rows.push({ key: d.key, line1: W.choiceDayLine(d, gs, opts), line2: '', choices: gs.map((g) => choice(g, { oldTitle: d.title })) });
    else if (ownOutcome) rows.push(plainRow(d));
    // Days share a row when their rows say the same happens: the same outcome, and the same clock when they say one.
    else if (!together.has(d.key)) push(outcomes, W.outcomeOf(d) + (W.saysClock(d) ? ' ' + W.clockOf(d.after, opts) : ''), d);
  }
  // Days in order of the day each is about, the date its row names first.
  const byDay = (a, b) => String(a.day || a.key).localeCompare(String(b.day || b.key)) || String(a.key).localeCompare(String(b.key));
  // The row of days a choice is about together: what they have, their dates, the choices, and the days under it.
  const togetherRows = [...onDays].map(([k, gs]) => {
    const sorted = k.split(' ').map((key) => byKey.get(key)).sort(byDay);
    const span = W.daysSpan(sorted.length, sorted[0].day, sorted[sorted.length - 1].day, opts);
    return {
      key: 'days:' + k, line1: W.togetherLine(gs, sorted.length, sorted.map((d) => d.title).filter(Boolean)), ...span,
      choices: gs.map((g) => choice(g, { onRow: true })), days: sorted.map(plainRow),
    };
  });
  // Days that go the same way, by what happens to them (outcomeOf): how far
  // each moves is its date after less its date before, both where it shows;
  // the clock they move to is their placement's, said when each row says it.
  // Two dates are subtracted only when they are read in the same zone.
  const placed = (d, side) => (d[side] && d[side].date && W.sameZone(W.placeZone(d.before, opts.tz), W.placeZone(d.after, opts.tz)) ? d[side].date : null);
  const outcomeRows = [...outcomes].map(([, ds]) => {
    if (ds.length === 1) return plainRow(ds[0]);
    const outcome = W.outcomeOf(ds[0]);
    const sorted = [...ds].sort(byDay);
    const shifts = new Set(sorted.map((d) => (placed(d, 'after') && placed(d, 'before') ? dayNumber(placed(d, 'after')) - dayNumber(placed(d, 'before')) : null)));
    const weekdays = new Set(sorted.map((d) => (placed(d, 'after') ? new Date(dayNumber(placed(d, 'after')) * 86400000).getUTCDay() : null)));
    const clocks = new Set(sorted.map((d) => (W.saysClock(d) ? W.clockOf(d.after, opts) || null : null)));
    const [shift] = shifts, [weekday] = weekdays, [clock] = clocks;
    const moved = outcome === 'moves' && shifts.size >= 1 && !shifts.has(null) && !shifts.has(0);
    // What every day of the group takes, said on the group's line.
    const takes = sorted.reduce((acc, d) => acc.filter((f) => (d.takes || []).includes(f)), [...(sorted[0].takes || [])]);
    const words = W.groupLines({
      takes,
      count: sorted.length, outcome,
      weekday: moved && weekdays.size === 1 ? weekday : null,
      shift: moved && shifts.size === 1 ? shift : null,
      clock: !moved && clocks.size === 1 && !clocks.has(null) ? clock : null,
      first: sorted[0].day, last: sorted[sorted.length - 1].day,
    }, opts);
    return { key: 'group:' + sorted[0].key, line1: words.line1, range: words.range, toggle: words.toggle, choices: [], days: sorted.map(plainRow) };
  });
  view.edited = [...rows, ...togetherRows, ...outcomeRows, ...editedChoices];
  // Skipped days: an anchor is no event, and a day the new rule would add
  // is said by its choice.
  const edge = skippedChoices.length > 0;
  view.skipped = [
    ...W.removedRows(days.filter((x) => x.kind === 'skipped' && x.outcome !== 'anchor' && !(edge && x.outcome === 'generated')), opts).map((r) => ({ ...r, choices: [] })),
    ...skippedChoices,
  ];
  view.notes = (plan.notes || []).map((n) => W.noteWords(n, opts)).filter(Boolean);
  view.action = W.actionWords(verb, view.count, ctx.scope);
  const everyGroup = (plan.groups || []).every((g) => picked[g.id] != null);
  view.canConfirm = !view.refused && everyGroup && (!!plan.hash || !!plan.whole);
  return view;
}

/** The commit's plan: the hash and an option for every group. */
export function commitPlan(plan, choices = {}) {
  return { hash: plan.hash, choices: chosen(plan, choices) };
}

/**
 * Quick add's parse carries its series' creation plan (`draft.series`);
 * it's the one to show only while the repeat and start are still the
 * parse's. Otherwise null, and the card asks for a new one.
 */
export function parsedSeries(draft, rule, range) {
  if (!draft || !draft.series || !rule || !range) return null;
  const sameStart = draft.start && Date.parse(draft.start) === Date.parse(range.start);
  return draft.rrule === rule && sameStart ? draft.series : null;
}

/**
 * The creation line under quick add's chips and the editor's Repeat row:
 * "Monthly on the 30th, or the last day · Sat 30 Jan, Sun 28 Feb, Tue 30 Mar
 * · 6 events", and when the start moved to the rule's first day, its own
 * line. typedStart is the start asked for (ISO or day key). Null without a
 * plan.
 */
export function creationLine(plan, { typedStart = null, tz = null, refYear } = {}) {
  if (!plan) return null;
  const rule = plan.rule || {};
  const words = rule.words || plan.words || '';
  const dates = (plan.next || []).slice(0, 3).map((n) => planDate(startOf(n), { weekday: true, tz, refYear })).filter(Boolean).join(', ');
  const bound = wordsOf(plan.bound);
  const line = [words, dates, bound].filter(Boolean).join(' · ');
  const startAt = startOf(plan.start && typeof plan.start === 'object' ? plan.start.at || plan.start : plan.start);
  let moved = '';
  if (typedStart && startAt) {
    const a = wallDate(startAt, tz);
    const b = wallDate(typedStart, tz);
    if (a && b && (a.y !== b.y || a.mo !== b.mo || a.d !== b.d)) {
      moved = plan.startWords || wordsOf(plan.start && typeof plan.start === 'object' ? plan.start : null)
        || 'Starts ' + planDate(startAt, { tz, refYear }) + ', its first day on or after the date you typed';
    }
  }
  return { line, moved };
}

