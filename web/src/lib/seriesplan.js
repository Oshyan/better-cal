// A series plan (docs/design/series-model.md, The Plan) in the blocks the
// series change panel shows, top to bottom. Pure, no DOM: the panel
// (app/SeriesDialog.js) draws what this returns, and the smoke tests check
// its words against the examples (docs/design/series-model-examples.md).
//
// The plan is the server's; its sentences (rule words, day outcomes, option
// labels, refusals, notes) are the server's too, so the panel, the Activity
// entry and an agent read the same words. What's put together here is only
// the frame: every date through one formatter, "now" between the rule
// before and after, the rows each day is drawn as, the size and the action.

import { untilDayKey } from './dates.js';

const WEEKDAY = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTH = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const lowerFirst = (s) => (s ? s.charAt(0).toLowerCase() + s.slice(1) : s);
const upperFirst = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);
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

// The groups that are choices about days, not readings of the rule: drawn
// as radios on a row, never as dated rows, though the server gives their
// options dates.
const PLAIN_GROUP = /^(movedDays|outside|own[A-Z].*)$/;

/**
 * What the change is called: its verb (Move for a drag, Change for a resize
 * or an editor save, Rename for an editor save of the title alone), the
 * header's title ("Move all events") and the action button, which says the
 * count when the plan has one ("Move 13 events").
 */
export function verbOf(kind, edits = []) {
  if (kind === 'drag') return 'Move';
  if (kind === 'editor' && edits.length === 1 && edits[0] === 'title') return 'Rename';
  return 'Change';
}
export function actionWords(kind, scope, { edits = [], count = null } = {}) {
  const verb = verbOf(kind, edits);
  if (scope === 'following') return verb + ' this and following';
  if (count == null) return verb + ' all events';
  return verb + ' ' + count + (count === 1 ? ' event' : ' events');
}

/** The rule's words: before and after, or "as before" when its days don't change. */
export function ruleLine(rule) {
  if (!rule) return '';
  if (rule.line) return rule.line;
  const after = rule.words || '';
  const before = rule.wordsBefore || '';
  if (!after) return before;
  if (!before || before === after || (rule.before && rule.before === rule.after)) return 'Repeats ' + lowerFirst(after) + ', as before';
  return before + ', now ' + lowerFirst(after);
}

/** The Change sentence: the rule, the end part of it. */
export function changeLine(rule, bound) {
  const r = ruleLine(rule);
  const b = wordsOf(bound);
  if (!r) return upperFirst(b);
  if (!b) return r;
  // "as before" once, at the end, when the rule and the end both say it.
  const AS_BEFORE = ', as before';
  const head = r.endsWith(AS_BEFORE) && b.endsWith(AS_BEFORE) ? r.slice(0, -AS_BEFORE.length) : r;
  return head + ', ' + lowerFirst(b);
}

// A refusal is of the whole plan unless it names an option (or a group).
function refusalFor(plan, groupId, optionId) {
  return (plan.refusals || []).find((r) => r && r.option === optionId && (r.group == null || r.group === groupId));
}
export function planRefusal(plan) {
  const r = (plan && plan.refusals || []).find((x) => x && x.option == null && x.group == null);
  return r ? wordsOf(r) || r.message || 'This change can’t be made to the series.' : null;
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

// Skipped days that stay skipped in the new series (a forgotten one leaves
// it, an anchor was never an event).
const isSkip = (d) => d.kind === 'skipped' && d.outcome !== 'anchor' && d.outcome !== 'dropped';

/**
 * How many events the series has after the change, from the plan: its
 * bound's count less the skipped days among them; null with no count (no
 * end, or an end date: the plan says no number then).
 */
export function planCount(plan) {
  const n = plan && plan.bound && plan.bound.count;
  if (typeof n !== 'number' || !isFinite(n)) return null;
  return Math.max(0, n - (plan.days || []).filter(isSkip).length);
}

/**
 * Size's first part: "13 events" with a count, "Ends Wed 20 Jan 2027" with
 * an end date (its last day, read in the series' zone), "No end" without.
 */
export function endWords(bound, count, tz = null, refYear = new Date().getFullYear()) {
  if (count != null) return count + (count === 1 ? ' event' : ' events');
  const until = bound && bound.until;
  if (until) {
    const day = untilDayKey(until, tz);
    if (day) return 'Ends ' + planDate(day, { tz, refYear });
  }
  return 'No end';
}

/**
 * Everything the panel shows, from a plan (null while it is being worked
 * out). ctx: kind ('drag' | 'resize' | 'editor'), title (the series'),
 * scope ('all' | 'following'), choices (picked so far), tz (the series'
 * zone; the calendar's when the occurrence carries none), refYear, fields
 * ([{label, words}] the editor changed), edits (the field names the change
 * sends, for its verb), changed (a 409 replaced the plan).
 */
export function planView(plan, ctx = {}) {
  const edits = ctx.edits || [];
  const verb = verbOf(ctx.kind, edits);
  const title = actionWords(ctx.kind, ctx.scope, { edits });
  const view = {
    heading: title + (ctx.title ? ' · ' + ctx.title : ''),
    title,
    name: ctx.title || '',
    verb,
    action: title,
    onlyThis: verb + ' only this one',
    loading: !plan,
    changed: !!ctx.changed,
    fields: [],
    refused: null,
    rule: '',
    bound: '',
    change: '',
    start: '',
    readings: [],
    next: '',
    size: '',
    count: null,
    edited: [],
    skipped: [],
    choices: [],
    ownership: '',
    gains: '',
    losses: '',
    notes: [],
    canConfirm: false,
  };
  if (!plan) return view;
  // One formatter for every date the panel derives, read in one zone.
  const tz = ctx.tz || calendarZone();
  const at = (iso, weekday = true) => planDate(iso, { weekday, tz, refYear: ctx.refYear });
  // The server's field lines read "Title: Choir, now Chorus"; the label
  // goes in its column.
  view.fields = (plan.fields || ctx.fields || []).map((f) => {
    const words = wordsOf(f);
    if (f.label) return { label: f.label, words };
    const m = /^([^:]{1,24}):\s+(.*)$/.exec(words);
    return m ? { label: m[1], words: m[2] } : { label: upperFirst(f.field || ''), words };
  });
  view.refused = planRefusal(plan);
  view.rule = ruleLine(plan.rule);
  view.bound = wordsOf(plan.bound);
  view.change = changeLine(plan.rule, plan.bound);
  view.start = plan.start && typeof plan.start === 'object' ? wordsOf(plan.start) : (plan.startWords || '');
  const picked = chosen(plan, ctx.choices);
  const days = plan.days || [];
  const radios = (g) => ({
    id: g.id,
    label: g.label || '',
    options: (g.options || []).map((o) => {
      const refusal = refusalFor(plan, g.id, o.id);
      return {
        id: o.id,
        label: o.label || o.id,
        selected: picked[g.id] === o.id,
        disabled: !!(o.disabled || refusal),
        reason: o.reason || wordsOf(refusal) || '',
      };
    }),
  });
  // The option labels and the preselection are the plan's, in its order.
  // A group for one day is that day's row's radios, whatever its options
  // carry (the server gives every option its dates and bound). The other
  // choices that aren't readings (a day moved on its own, days outside the
  // new series, own values for a changed field) are rows of their own in
  // the Edited block. The rest, a group whose options carry dates or words,
  // is reading rows under Change: its label, its dates, its consequence
  // (what it drops or adds, the clocks a zone moves).
  const noted = (o) => (Array.isArray(o.next) && o.next.length) || wordsOf(o.words) || wordsOf(o.differs) || wordsOf(o.bound);
  const dateList = (list) => (list || []).map((n) => {
    const d = at(startOf(n));
    return d && n && typeof n === 'object' && n.words ? d + ' (' + n.words + ')' : d;
  }).filter(Boolean).join(' · ');
  for (const g of plan.groups || []) {
    if (g.day != null && days.some((d) => d.key === g.day)) continue; // on its day's row
    if (!PLAIN_GROUP.test(g.id || '') && (g.options || []).some(noted)) {
      const group = radios(g);
      group.dated = (g.options || []).some((o) => Array.isArray(o.next) && o.next.length);
      group.options = group.options.map((o, i) => {
        const src = g.options[i];
        const words = wordsOf(src.words);
        const notes = [wordsOf(src.differs), wordsOf(src.bound)].filter((x) => x && x !== words);
        return { ...o, words, dates: dateList(src.next), note: [...new Set(notes)].join('; ') };
      });
      view.readings.push(group);
    } else {
      view.choices.push({ key: 'choice:' + g.id, head: g.label || '', line: '', choice: radios(g) });
    }
  }
  view.next = dateList(plan.next);
  const groupOf = (key) => (plan.groups || []).find((g) => g.day === key);
  // The day acted on is in Next where it landed; it gets a row of its own
  // only when it doesn't simply move there (kept on its own, say). A day
  // that does something other than go with its event (stays at its own
  // date, becomes an event of its own, is deleted) or carries a choice is a
  // consequence to see, and has its own row, first; a day at its own date
  // is one that owns its date (`kept` has it). Days that go with their
  // events the same way are one row, by the plan's data: their outcome, the
  // fields they keep and take (`kept`, `takes`), and whether a choice is
  // theirs. Their days open under it, each its own row (groupRow).
  const rows = [];
  const groups = new Map();
  const list = (x) => (Array.isArray(x) ? x : []);
  for (const d of days.filter((x) => x.kind !== 'skipped' && !(x.kind === 'acted' && x.outcome === 'moves'))) {
    const g = groupOf(d.key);
    const row = { ...dayRow(d, at), choice: g ? radios(g) : null };
    const kept = list(d.kept), takes = list(d.takes);
    const goesWith = (d.outcome === 'moves' || d.outcome === 'stays') && !g && !kept.includes('date');
    if (!goesWith) { rows.push(row); continue; }
    const id = JSON.stringify([d.outcome, kept, takes, !!g]);
    if (!groups.has(id)) groups.set(id, { outcome: d.outcome, kept, takes, days: [] });
    groups.get(id).days.push({ d, row });
  }
  view.edited = [...rows, ...[...groups.values()].map((grp) => groupRow(grp, { tz, at }))];
  // An anchor is the stored day before a first day in a skipped hour: no
  // event of the series, so it has no row (the gap note says the time).
  view.skipped = days.filter((x) => x.kind === 'skipped' && x.outcome !== 'anchor').map((d) => dayRow(d, at));
  // A group's name leads its rows when there's more than one, or when its
  // rows aren't readings of the rule ("First week", "Bound").
  for (const g of view.readings) g.showLabel = !!g.label && (view.readings.length > 1 || !g.dated);
  view.ownership = wordsOf(plan.ownership);
  view.gains = upperFirst((plan.gains || []).map(wordsOf).filter(Boolean).join('; '));
  view.losses = upperFirst((plan.losses || []).map(wordsOf).filter(Boolean).join('; '));
  view.notes = (plan.notes || []).map(wordsOf).filter(Boolean);
  // Size: the end (the events' count, or no end, or the end date), then the
  // edited and skipped days among them, always in that order.
  view.count = planCount(plan);
  const skips = days.filter(isSkip).length;
  const editedDays = days.filter((d) => d.kind === 'edited').length;
  view.size = [endWords(plan.bound, view.count, tz, ctx.refYear),
    editedDays ? editedDays + ' edited' : '', skips ? skips + ' skipped' : ''].filter(Boolean).join(', ');
  view.action = actionWords(ctx.kind, ctx.scope, { edits, count: view.count });
  const everyGroup = (plan.groups || []).every((g) => picked[g.id] != null);
  view.canConfirm = !view.refused && everyGroup && !!plan.hash;
  return view;
}

// What follows a day's head in a line two: "; keeps its title" rather than
// a comma run, so the outcome reads first.
const outcomeLine = (s) => String(s || '').trim().replace(/^[,:;]\s*/, '').replace(/, (keeps|takes) /, '; $1 ');

/**
 * A day as a row, edited or skipped: line one its head, the day (its name
 * when it owns its title, its date, and "moved to <date>" when it shows at
 * a date of its own), line two what happens to it. Both are the server's
 * (`head`, `outcomeWords`), so nothing here takes a sentence apart. A day
 * the plan gives no parts for is its sentence on line one; one it gives no
 * words for at all is put together from its keys and outcome.
 */
function dayRow(d, at) {
  const key = d.key || d.newKey || d.words || '';
  if (d.head || d.outcomeWords) return { key, head: d.head || '', line: outcomeLine(d.outcomeWords), words: d.words || '' };
  if (d.words) return { key, head: d.words, line: '', words: d.words };
  const made = dayParts(d, at);
  return { key, head: made.head, line: made.line, words: made.head + ' ' + made.line };
}

// A day's parts when the plan gave no words: "Tue 30 Mar", "moves to Wed 31 Mar".
function dayParts(d, at) {
  const head = (d.title && d.kind !== 'skipped' ? "'" + d.title + "' " : '') + (d.key ? at(d.key) : d.newKey ? at(d.newKey) : '');
  const line = d.outcome === 'moves' && d.newKey ? 'moves to ' + at(d.newKey)
    : d.outcome === 'stays' ? (d.kind === 'skipped' ? 'stays skipped' : 'stays')
      : d.outcome === 'own' || d.outcome === 'separate' ? 'becomes an event of its own'
        : d.outcome === 'deleted' ? 'is deleted'
          : d.outcome === 'dropped' ? 'is forgotten'
            : d.outcome === 'rejoins' ? 'goes back to the series' + (d.newKey ? ', on ' + at(d.newKey) : '')
              : '';
  return { head, line };
}

// The own fields in words, as the server's sentences name them.
const FIELD_WORDS = {
  date: 'date', time: 'time', length: 'length', allDay: 'all-day setting', title: 'title', location: 'place',
  description: 'notes', url: 'link', reminders: 'reminders', status: 'status', attendance: 'answer',
  tags: 'tags', people: 'people', icon: 'icon',
};
const WEEKDAY_NAME = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const andList = (items) => (items.length <= 1 ? items[0] || '' : items.slice(0, -1).join(', ') + ' and ' + items[items.length - 1]);
const fieldList = (fields) => andList(fields.map((f) => FIELD_WORDS[f] || f));

const dayNumber = (iso, tz) => {
  const w = wallDate(iso, tz);
  return w ? Date.UTC(w.y, w.mo - 1, w.d) / 86400000 : null;
};

// The wall clock an ISO value names ("19:00"), in `tz` for a UTC key; null
// for a bare date.
function wallClock(iso, tz) {
  const s = String(iso || '');
  if (!/T\d{2}:\d{2}/.test(s)) return null;
  if (/Z$/i.test(s) && tz) {
    try {
      const parts = new Intl.DateTimeFormat('en-GB', { timeZone: tz, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(s));
      return parts.find((p) => p.type === 'hour').value + ':' + parts.find((p) => p.type === 'minute').value;
    } catch { /* an unknown zone reads as UTC */ }
  }
  return /Z$/i.test(s) ? new Date(s).toISOString().slice(11, 16) : s.slice(11, 16);
}

/**
 * One row for days that go with their events the same way, from what the
 * plan says of them, not their sentences: the outcome, where their keys
 * land (newKey), the fields they keep and take. Line one the count and the
 * range of their dates ("9 edited days, Tue 3 Nov to Tue 19 Jan 2027"),
 * line two what they share ("move to Wednesday; each keeps its title").
 * Its days open under it, each its own row. A group of one is that day's
 * row.
 */
function groupRow({ outcome, kept, takes, days }, { tz, at }) {
  if (days.length === 1) return days[0].row;
  const sorted = [...days].sort((a, b) => (Date.parse(a.d.key) || 0) - (Date.parse(b.d.key) || 0));
  // The range without weekdays: the outcome line names where they land.
  const first = at(sorted[0].d.key, false), last = at(sorted[sorted.length - 1].d.key, false);
  // Where they go: a weekday they all land on, a shift they all make, or
  // a clock time they all take. Every day read in the same zone as the
  // dates, so a Tuesday evening's key lands on Wednesday, not Thursday.
  let does = 'stay';
  let named = [];
  if (outcome === 'moves') {
    does = 'move with their events';
    const shifts = new Set(sorted.map((x) => {
      const a = dayNumber(x.d.key, tz), b = dayNumber(x.d.newKey, tz);
      return a == null || b == null ? null : b - a;
    }));
    const [shift] = shifts;
    const weekdays = new Set(sorted.map((x) => {
      const w = wallDate(x.d.newKey, tz);
      return w ? new Date(Date.UTC(w.y, w.mo - 1, w.d)).getUTCDay() : null;
    }));
    const [wd] = weekdays;
    const clocks = new Set(sorted.map((x) => wallClock(x.d.newKey, tz)));
    const [clock] = clocks;
    const sameClock = clocks.size === 1 && clock && sorted.every((x) => wallClock(x.d.key, tz) !== clock);
    if (shifts.size === 1 && shift === 0) {
      if (sameClock && takes.includes('time')) { does = 'move to ' + clock; named = ['time']; }
    } else if (!shifts.has(null) && !shifts.has(0)) {
      named = ['date'];
      if (weekdays.size === 1 && wd != null) does = 'move to ' + WEEKDAY_NAME[wd];
      else if (shifts.size === 1) does = 'move ' + (Math.abs(shift) === 1 ? 'a day' : Math.abs(shift) + ' days') + (shift > 0 ? ' later' : ' earlier');
    }
  }
  const taken = takes.filter((f) => !named.includes(f));
  const each = [kept.length ? 'keeps its ' + fieldList(kept) : '', taken.length ? 'takes the new ' + fieldList(taken) : ''].filter(Boolean);
  const count = sorted.length + ' edited days';
  return {
    key: 'group:' + sorted[0].d.key,
    strong: count,
    head: first === last ? first : first + ' to ' + last,
    words: count + ', ' + (first === last ? first : first + ' to ' + last),
    line: does + (each.length ? '; each ' + each.join(', ') : ''),
    choice: null,
    days: sorted.map((x) => x.row),
  };
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

