// A series plan (docs/design/series-model.md, The Plan) in the lines the
// series change dialog shows, top to bottom. Pure, no DOM: the dialog
// (app/SeriesDialog.js) draws what this returns, and the smoke tests check
// its words against the examples (docs/design/series-model-examples.md).
//
// The plan is the server's; its sentences (rule words, day outcomes, option
// labels, refusals, notes) are the server's too, so the dialog, the Activity
// entry and an agent read the same words. What's put together here is only
// the frame: the dates as short dates, "now" between the rule before and
// after, the action's name.

const WEEKDAY = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTH = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const lowerFirst = (s) => (s ? s.charAt(0).toLowerCase() + s.slice(1) : s);
const upperFirst = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);
const wordsOf = (x) => (x == null ? '' : typeof x === 'string' ? x : x.words || '');

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
 */
export function planDate(iso, { weekday = true, tz = null, refYear = new Date().getFullYear() } = {}) {
  const w = wallDate(iso, tz);
  if (!w) return '';
  const day = new Date(Date.UTC(w.y, w.mo - 1, w.d)).getUTCDay();
  return (weekday ? WEEKDAY[day] + ' ' : '') + w.d + ' ' + MONTH[w.mo - 1] + (w.y !== refYear ? ' ' + w.y : '');
}

// Daily and weekly rules name their dates with the weekday; monthly and
// yearly ones by the day of the month alone, as the examples do.
function freqOf(rule) {
  const m = /FREQ=([A-Z]+)/i.exec(String(rule || ''));
  return m ? m[1].toUpperCase() : null;
}
export function datesWithWeekday(plan) {
  const f = freqOf(plan && plan.rule && (plan.rule.after || plan.rule.before));
  return f === 'DAILY' || f === 'WEEKLY';
}

const startOf = (n) => (typeof n === 'string' ? n : n && n.start);

// What each action is called, on the heading and its button.
const ACTIONS = {
  drag: { all: 'Move all events', following: 'Move this and following' },
  resize: { all: 'Change all events', following: 'Change this and following' },
  editor: { all: 'Save for all events', following: 'Save for this and following' },
};
export function actionWords(kind, scope) {
  return (ACTIONS[kind] || ACTIONS.editor)[scope === 'following' ? 'following' : 'all'];
}

/** The rule's line: before and after, or "as before" when its days don't change. */
export function ruleLine(rule) {
  if (!rule) return '';
  if (rule.line) return rule.line;
  const after = rule.words || '';
  const before = rule.wordsBefore || '';
  if (!after) return before;
  if (!before || before === after || (rule.before && rule.before === rule.after)) return 'Repeats ' + lowerFirst(after) + ', as before';
  return before + ', now ' + lowerFirst(after);
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

/**
 * Everything the dialog shows, from a plan (null while it is being worked
 * out). ctx: kind ('drag' | 'resize' | 'editor'), title (the series'),
 * scope ('all' | 'following'), choices (picked so far), tz (the series'
 * zone, for UTC keys), refYear, fields ([{label, words}] the editor
 * changed), changed (a 409 replaced the plan).
 */
export function planView(plan, ctx = {}) {
  const action = actionWords(ctx.kind, ctx.scope);
  const view = {
    heading: action + (ctx.title ? ' · ' + ctx.title : ''),
    action,
    loading: !plan,
    changed: !!ctx.changed,
    fields: [],
    refused: null,
    rule: '',
    bound: '',
    start: '',
    readings: [],
    next: '',
    edited: [],
    skipped: '',
    choices: [],
    ownership: '',
    gains: '',
    losses: '',
    notes: [],
    canConfirm: false,
  };
  if (!plan) return view;
  const weekday = datesWithWeekday(plan);
  const fmt = (iso, wd = weekday) => planDate(iso, { weekday: wd, tz: ctx.tz || null, refYear: ctx.refYear });
  view.fields = (plan.fields || ctx.fields || []).map((f) => ({ label: f.label || upperFirst(f.field || ''), words: wordsOf(f) }));
  view.refused = planRefusal(plan);
  view.rule = ruleLine(plan.rule);
  view.bound = wordsOf(plan.bound);
  view.start = plan.start && typeof plan.start === 'object' ? wordsOf(plan.start) : (plan.startWords || '');
  const picked = chosen(plan, ctx.choices);
  const days = plan.days || [];
  const inline = (g) => ({
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
  // A group whose options carry dates or a consequence is drawn as rows
  // ("Don't add Fri 8 Jan   5 events, ends 22 Jan"); bare labels go inline.
  const noted = (o) => (Array.isArray(o.next) && o.next.length) || wordsOf(o.words || o.consequence) || wordsOf(o.differs) || wordsOf(o.bound);
  for (const g of plan.groups || []) {
    const dated = (g.options || []).some(noted);
    if (dated) {
      const group = inline(g);
      group.dated = (g.options || []).some((o) => Array.isArray(o.next) && o.next.length);
      group.options = group.options.map((o, i) => {
        const src = g.options[i];
        const notes = [wordsOf(src.words || src.consequence), wordsOf(src.differs), wordsOf(src.bound)].filter(Boolean);
        return { ...o, dates: (src.next || []).map((n) => fmt(startOf(n))).filter(Boolean).join(' · '), note: [...new Set(notes)].join('; ') };
      });
      view.readings.push(group);
    } else if (g.day != null && days.some((d) => d.key === g.day)) {
      continue; // drawn on its day's line
    } else {
      view.choices.push(inline(g));
    }
  }
  view.next = (plan.next || []).map((n) => {
    const at = fmt(startOf(n));
    return n && n.words ? at + ' (' + n.words + ')' : at;
  }).filter(Boolean).join(' · ');
  const groupOf = (key) => (plan.groups || []).find((g) => g.day === key);
  for (const d of days.filter((x) => x.kind !== 'skipped')) {
    const g = groupOf(d.key);
    view.edited.push({ key: d.key, words: d.words || dayWords(d, fmt), choice: g ? inline(g) : null });
  }
  view.skipped = days.filter((x) => x.kind === 'skipped').map((d) => d.words || dayWords(d, (iso) => fmt(iso, false))).join(' · ');
  // A group's name leads its rows when there's more than one, or when its
  // rows aren't readings of the rule ("First week", "Bound").
  for (const g of view.readings) g.showLabel = !!g.label && (view.readings.length > 1 || !g.dated);
  view.ownership = wordsOf(plan.ownership);
  view.gains = upperFirst((plan.gains || []).map(wordsOf).filter(Boolean).join('; '));
  view.losses = upperFirst((plan.losses || []).map(wordsOf).filter(Boolean).join('; '));
  view.notes = (plan.notes || []).map(wordsOf).filter(Boolean);
  const everyGroup = (plan.groups || []).every((g) => picked[g.id] != null);
  view.canConfirm = !view.refused && everyGroup && !!plan.hash;
  return view;
}

// A day's line when the plan gave none: "30 Mar moves to 31 Mar".
function dayWords(d, fmt) {
  const at = fmt(d.key);
  const title = d.title ? "'" + d.title + "' " : '';
  if (d.outcome === 'moves' && d.newKey) return title + at + ' moves to ' + fmt(d.newKey);
  if (d.outcome === 'stays') return title + at + ' stays';
  if (d.outcome === 'own') return title + at + ' becomes an event of its own';
  if (d.outcome === 'deleted') return title + at + ' is deleted';
  if (d.outcome === 'dropped') return title + at + ' is forgotten';
  return title + at;
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
