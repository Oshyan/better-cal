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

/** The zone the calendar is drawn in: this device's. */
export function calendarZone() {
  try { return Intl.DateTimeFormat().resolvedOptions().timeZone || null; } catch { return null; }
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
 * A UTC key is read in `tz`; the panel always passes one (the series' zone,
 * else the calendar's), so a Tuesday evening in Los Angeles is never read
 * as the Wednesday its UTC instant falls on.
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

// Where each group is drawn: under Change (the readings of the rule, the
// bound, the zone), on one day's row, as a row of its own among the edited
// days, or among the skipped days (a day the new rule would add).
const CHANGE_GROUPS = new Set(['reading', 'bound', 'zone']);
const dayOfGroup = (g) => (g.day != null ? g.day : Array.isArray(g.days) && g.days.length === 1 ? g.days[0] : null);

const dayNumber = (key) => (key ? Date.UTC(+key.slice(0, 4), +key.slice(5, 7) - 1, +key.slice(8, 10)) / 86400000 : null);

/**
 * Everything the panel shows, from a plan (null while it is being worked
 * out), every sentence from lib/planwords.js. ctx: kind ('drag' | 'resize'
 * | 'editor'), title (the series'), calendar (its name), scope, choices
 * (picked so far), tz (the series' zone, for times), refYear, fields
 * ([{label, words}] the editor changed), edits (the field names the change
 * sends, for its verb), newTitle (a rename's), changed (a 409 replaced the
 * plan).
 */
export function planView(plan, ctx = {}) {
  const verb = W.verbOf(ctx.kind, ctx.edits || []);
  const title = W.titleWords(verb, ctx.scope);
  const opts = { refYear: ctx.refYear, tz: ctx.tz || calendarZone(), series: ctx.title || '', newTitle: ctx.newTitle || '' };
  const view = {
    heading: title + (ctx.title ? ' · ' + ctx.title : ''),
    title,
    verb,
    name: ctx.title || '',
    series: W.seriesLine(ctx.title, ctx.calendar),
    action: title,
    onlyThis: W.onlyThisWords(verb),
    loading: !plan,
    changed: ctx.changed ? W.CHANGED_WORDS : '',
    working: W.WORKING_WORDS,
    saving: W.SAVING_WORDS,
    fields: [],
    refused: null,
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
        const lines = CHANGE_GROUPS.has(g.id) && g.id !== 'bound' ? W.optionLines(o, { selected: picked[g.id] === o.id, opts }) : { dates: '', note: '' };
        return {
          id: o.id, label: w.options[i].label, selected: picked[g.id] === o.id, disabled,
          reason: disabled ? W.refusalWords(refusal ? refusal.code : o.code) : '', dates: lines.dates, note: lines.note,
        };
      }),
    };
  };
  view.fields = (ctx.fields || []).map(W.fieldSentence);
  view.refused = planRefusal(plan);
  view.change = view.refused ? '' : W.changeSentence(plan.rule, plan.bound, opts);
  view.comingUp = W.comingUp(plan.next, plan.nextMore, opts);
  view.count = plan.counts ? plan.counts.events : null;
  view.affects = view.refused ? '' : W.affects(plan.counts, plan.bound, opts, plan.days);

  const onDay = new Map();
  const onDays = new Map(); // a day's key -> the choice about it and other days
  const editedChoices = [];
  const skippedChoices = [];
  for (const g of plan.groups || []) {
    const day = dayOfGroup(g);
    if (CHANGE_GROUPS.has(g.id)) view.readings.push(choice(g));
    else if (g.id === 'edge') skippedChoices.push({ key: 'choice:edge', line1: '', line2: '', choice: choice(g) });
    else if (day != null && byKey.has(day)) onDay.set(day, [...(onDay.get(day) || []), g]);
    else if (/^own[A-Z]/.test(g.id) && Array.isArray(g.days) && g.days.length > 1 && g.days.every((k) => byKey.has(k) && ['moves', 'stays'].includes(byKey.get(k).outcome))) g.days.forEach((k) => onDays.set(k, g));
    else editedChoices.push({ key: 'choice:' + g.id, line1: '', line2: '', choice: choice(g) });
  }

  // Edited days. A day that does something of its own (stays at its own
  // date, becomes an event of its own, is deleted, goes back to the
  // series) or carries a choice has its own row, first. Days that go with
  // their events the same way are one row, by their outcome alone (what
  // they keep or take never splits it). The day acted on is in Coming up.
  const rows = [];
  const groups = new Map();
  const list = (x) => (Array.isArray(x) ? x : []);
  for (const d of days.filter((x) => x.kind !== 'skipped' && !(x.kind === 'acted' && x.outcome === 'moves'))) {
    const gs = onDay.get(d.key) || [];
    const kept = list(d.kept);
    let row = { key: d.key, ...W.dayLines(d, opts), choice: null };
    // A day's own choice sits on its row: its subject, then the reason and radios.
    const own = gs.find((g) => /^own[A-Z]/.test(g.id));
    const outside = gs.find((g) => g.id === 'outside');
    if (outside) row = { key: d.key, line1: W.dayName(d, opts.series) + ' on ' + W.fmtDay(d.shows, opts) + ' falls after the new end.', line2: '', choice: choice(outside) };
    else if (own) row = { key: d.key, line1: W.dayName({ ...d, kept: ['title'] }) + ' on ' + W.fmtDay(d.shows, opts) + ' has its own ' + (own.field === 'title' ? 'title, ' + "'" + d.title + "'" : W.fieldNoun(own.field)) + '.', line2: '', choice: choice(own, { oldTitle: d.title }) };
    else if (gs.length) row.choice = choice(gs[0]);
    const goesWith = (d.outcome === 'moves' || d.outcome === 'stays') && !gs.length && !kept.includes('date');
    if (!goesWith) { rows.push(row); continue; }
    // Grouped by outcome alone: what a day keeps or takes never splits a
    // group; days a choice is about together are a group of their own with it.
    const shared = onDays.get(d.key) || null;
    const id = d.outcome + (shared ? ':' + shared.id : '');
    if (!groups.has(id)) groups.set(id, { outcome: d.outcome, shared, days: [] });
    groups.get(id).days.push({ d, row });
  }
  const groupRows = [...groups.values()].map((grp) => {
    if (grp.days.length === 1) return grp.days[0].row;
    const sorted = [...grp.days].sort((a, b) => String(a.d.shows || a.d.key).localeCompare(String(b.d.shows || b.d.key)));
    const shifts = new Set(sorted.map((x) => (x.d.to && x.d.shows ? dayNumber(x.d.to) - dayNumber(x.d.shows) : null)));
    const weekdays = new Set(sorted.map((x) => (x.d.to ? new Date(dayNumber(x.d.to) * 86400000).getUTCDay() : null)));
    const clocks = new Set(sorted.map((x) => (x.d.newKey ? W.fmtClock(x.d.newKey, opts.tz) : null)));
    const [shift] = shifts, [weekday] = weekdays, [clock] = clocks;
    const moved = grp.outcome === 'moves' && shifts.size >= 1 && !shifts.has(null) && !shifts.has(0);
    const words = W.groupLines({
      count: sorted.length, outcome: grp.outcome,
      weekday: moved && weekdays.size === 1 ? weekday : null,
      shift: moved && shifts.size === 1 ? shift : null,
      clock: grp.outcome === 'moves' && !moved && clocks.size === 1 && sorted.every((x) => (x.d.takes || []).includes('time')) ? clock : null,
      first: sorted[0].d.shows, last: sorted[sorted.length - 1].d.shows,
    }, opts);
    const line1 = grp.shared ? W.sharedLine(grp.shared, sorted.length) : words.line1;
    return { key: 'group:' + sorted[0].d.key, line1, range: words.range, toggle: words.toggle, choice: grp.shared ? choice(grp.shared, { onRow: true }) : null, days: sorted.map((x) => x.row) };
  });
  view.edited = [...rows, ...groupRows, ...editedChoices];
  // Skipped days: an anchor is no event, and a day the new rule would add
  // is said by its choice.
  const edge = skippedChoices.length > 0;
  view.skipped = [
    ...days.filter((x) => x.kind === 'skipped' && x.outcome !== 'anchor' && !(edge && x.outcome === 'generated')).map((d) => ({ key: d.key || d.newKey, ...W.skipLines(d, opts), choice: null })),
    ...skippedChoices,
  ];
  view.notes = (plan.notes || []).map((n) => W.noteWords(n, opts)).filter(Boolean);
  view.action = W.actionWords(verb, view.count, ctx.scope);
  const everyGroup = (plan.groups || []).every((g) => picked[g.id] != null);
  view.canConfirm = !view.refused && everyGroup && !!plan.hash;
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

