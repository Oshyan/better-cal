// A filter's dim and highlight show on context items as on events (#72):
// every shape drawn for an occurrence (a chip, a context token, a moment on
// the timeline, a holiday's label, a row in the day sheet) takes the classes
// and the colour of lib/filtermarks.js, and the stylesheet has a rule for
// each. Drawn in the stand-in DOM and read back.
//
// Run: node web/tests/filter-marks.mjs (smoke.mjs imports it). Invented
// events only; all-day dates and instants with their own offsets, so the
// result is the same in any zone.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fakeDom } from './fakedom.mjs';

const { filterClasses, hlStyle, combinedFilterMarks, foldedMarks } = await import('../src/lib/filtermarks.js');

let checks = 0;
const ok = (cond, msg) => { assert.ok(cond, msg); checks++; };
const eq = (a, b, msg) => { assert.deepEqual(a, b, msg); checks++; };

// --- the rule itself ---------------------------------------------------------
eq(filterClasses({}), '', 'no filter: no class');
eq(filterClasses({ dimmed: true }), ' is-filter-dimmed', 'dimmed');
eq(filterClasses({ highlighted: true }), ' is-highlighted', 'highlighted');
eq(hlStyle({ highlighted: true, highlightColor: '#c95d5d' }), '--hl:#c95d5d', 'a highlight names its colour');
eq(hlStyle({ highlighted: true }), '', 'a highlight without a colour leaves the accent');
eq(hlStyle({ highlightColor: '#c95d5d' }), '', 'a colour without a highlight is nothing');
eq(combinedFilterMarks([{ dimmed: true }, { dimmed: true }]), { dimmed: true, highlighted: false, highlightColor: undefined }, 'every one dimmed: dimmed');
eq(combinedFilterMarks([{ dimmed: true }, {}]).dimmed, false, 'one not dimmed: not dimmed');
eq(combinedFilterMarks([{}, { highlighted: true, highlightColor: '#4f9d69' }, { highlighted: true, highlightColor: '#c95d5d' }]),
  { dimmed: false, highlighted: true, highlightColor: '#4f9d69' }, 'any highlighted: highlighted, in the first one\'s colour');
eq(combinedFilterMarks([]).dimmed, false, 'nothing: not dimmed');

// --- drawn ---------------------------------------------------------------------
const dom = fakeDom();
dom.install();
// Effects run on a turn of their own here, and finish before the DOM goes.
const { options } = await import('../vendor/preact.module.js');
const savedRaf = options.requestAnimationFrame;
options.requestAnimationFrame = (cb) => setImmediate(cb);
const turns = async (n = 5) => { for (let i = 0; i < n; i++) await new Promise((r) => setImmediate(r)); };
const { render, html } = await import('../vendor/index.js');
const { ContextStrip, ContextMark, DayLabel } = await import('../src/ui/ContextStrip.js');
const { EventChip } = await import('../src/ui/EventChip.js');
const { groupOccurrences } = await import('../src/ui/grouping.js');

const cals = {
  1: { id: 1, name: 'Weather', color: '#3d9dc9' },
  2: { id: 2, name: 'Holidays', color: '#4f9d69' },
  3: { id: 3, name: 'Home', color: '#5b7fd4' },
};
const day = (id, title, extra = {}) => ({ instanceId: 'i' + id, eventId: id, calendarId: 1, title, start: '2031-03-04', end: '2031-03-05', allDay: true, relationship: 'context', ...extra });
const plain = day(1, '61/48 clear');
const dim = day(2, '58/50 fog', { dimmed: true });
const lit = day(3, '55/47 rain', { highlighted: true, highlightColor: '#c95d5d' });
const litAccent = day(4, 'AQI 41 (Good)', { highlighted: true });

const classOf = (el) => (el && el.getAttribute('class')) || '';
const styleOf = (el) => (el && (el.getAttribute('style') || el.style.cssText)) || '';
const host = document.createElement('div');
document.body.appendChild(host);
const draw = (vnode) => { render(null, host); render(vnode, host); return host; };

// Context tokens: month and weeks cells, week and day headers, Split, the
// agenda's day headings and the toolbar all draw them with ContextStrip.
draw(html`<${ContextStrip} occs=${[plain, dim, lit, litAccent]} calendars=${cals} />`);
const tokens = host.querySelectorAll('.bc-ctx-token');
eq(tokens.length, 4, 'four tokens drawn');
const tokenOf = (occ) => tokens.find((t) => t.getAttribute('data-ctx-instance') === occ.instanceId);
ok(!/is-filter-dimmed|is-highlighted/.test(classOf(tokenOf(plain))), 'a token no filter matched carries no mark');
ok(classOf(tokenOf(dim)).includes('is-filter-dimmed'), 'a dimmed context token is dimmed, as a dimmed chip is');
ok(classOf(tokenOf(lit)).includes('is-highlighted'), 'a highlighted context token is highlighted');
ok(styleOf(tokenOf(lit)).includes('--hl:#c95d5d'), 'in its filter\'s colour');
ok(classOf(tokenOf(litAccent)).includes('is-highlighted') && !styleOf(tokenOf(litAccent)).includes('--hl'), 'or the accent, when the filter names none');

// The moment on the timeline: the marks on its label and line, never on the
// mark itself (a dim there would sink both below the events they cross).
const moment = { ...day(5, 'Sunset'), allDay: false, start: '2031-03-04T18:10:00-08:00', end: '2031-03-04T18:10:00-08:00', tzid: 'America/Los_Angeles' };
draw(html`<${ContextMark} occ=${{ ...moment, dimmed: true }} cal=${cals[1]} top=${100} />`);
ok(classOf(host.querySelector('.bc-ctx-mark-label')).includes('is-filter-dimmed'), 'a dimmed moment\'s label is dimmed');
ok(classOf(host.querySelector('.bc-ctx-mark-line')).includes('is-filter-dimmed'), 'and its line');
ok(!classOf(host.querySelector('.bc-ctx-mark')).includes('is-filter-dimmed'), 'and the mark around them is not');
draw(html`<${ContextMark} occ=${{ ...moment, highlighted: true, highlightColor: '#8e6cc0' }} cal=${cals[1]} top=${100} />`);
const label = host.querySelector('.bc-ctx-mark-label');
ok(classOf(label).includes('is-highlighted') && styleOf(label).includes('--hl:#8e6cc0'), 'a highlighted moment\'s label is highlighted in its colour');

// A holiday's label stands for every holiday on the day.
const hol = (id, title, extra) => day(id, title, { calendarId: 2, ...extra });
draw(html`<${DayLabel} occs=${[hol(6, 'Founders Day'), hol(7, 'Lantern Night', { highlighted: true, highlightColor: '#c98c3d' })]} />`);
const dl = host.querySelector('.bc-daylabel');
ok(classOf(dl).includes('is-highlighted') && styleOf(dl).includes('--hl:#c98c3d'), 'a day label with a highlighted holiday is highlighted');
draw(html`<${DayLabel} occs=${[hol(6, 'Founders Day', { dimmed: true }), hol(7, 'Lantern Night')]} flag=${true} />`);
ok(!classOf(host.querySelector('.bc-daylabel')).includes('is-filter-dimmed'), 'one holiday dimmed of two: the label is not');
draw(html`<${DayLabel} occs=${[hol(6, 'Founders Day', { dimmed: true }), hol(7, 'Lantern Night', { dimmed: true })]} flag=${true} />`);
ok(classOf(host.querySelector('.bc-daylabel')).includes('is-filter-dimmed'), 'every holiday dimmed: the flag is dimmed');

// Events keep the same rule, alone and in a group.
const ev = { instanceId: 'e1', eventId: 11, calendarId: 3, title: 'Book club', start: '2031-03-04T19:00:00-08:00', end: '2031-03-04T20:00:00-08:00', allDay: false, relationship: 'planned' };
draw(html`<${EventChip} occ=${{ ...ev, highlighted: true, highlightColor: '#c95d5d' }} cal=${cals[3]} />`);
ok(classOf(host.querySelector('.bc-chip')).includes('is-highlighted') && styleOf(host.querySelector('.bc-chip')).includes('--hl:#c95d5d'), 'a highlighted chip, as before');
draw(html`<${EventChip} occ=${{ ...ev, dimmed: true }} cal=${cals[3]} />`);
ok(classOf(host.querySelector('.bc-chip')).includes('is-filter-dimmed'), 'a dimmed chip, as before');
const grouped = groupOccurrences([
  { ...ev, instanceId: 'g1', title: 'Market (north)', dimmed: true },
  { ...ev, instanceId: 'g2', title: 'Market (south)', highlighted: true, highlightColor: '#4f9d69' },
], { 3: true });
eq([grouped.length, grouped[0].dimmed, grouped[0].highlighted, grouped[0].highlightColor], [1, false, true, '#4f9d69'], 'a group takes the combined marks');

// The day sheet's context rows.
const { DayExpand } = await import('../src/ui/DayExpand.js');
draw(html`<${DayExpand} dayKey="2031-03-04" occurrences=${[plain, dim, lit]} calendars=${cals} onClose=${() => {}} />`);
const rows = host.querySelectorAll('.bc-dayexpand-ctxrow');
const rowOf = (occ) => rows.find((r) => r.getAttribute('data-ctx-instance') === occ.instanceId);
eq(rows.length, 3, 'the day sheet lists the day\'s context');
ok(classOf(rowOf(dim)).includes('is-filter-dimmed'), 'a dimmed context row in the day sheet is dimmed');
ok(classOf(rowOf(lit)).includes('is-highlighted') && styleOf(rowOf(lit)).includes('--hl:#c95d5d'), 'a highlighted one is highlighted in its colour');
ok(!/is-filter-dimmed|is-highlighted/.test(classOf(rowOf(plain))), 'and an unmatched one carries neither');
// "+N" stands for the tokens it folds (#307): highlighted when any is,
// dimmed only when all are.
const more = (occs) => { draw(html`<${ContextStrip} occs=${occs} calendars=${cals} max=${1} />`); return host.querySelector('.bc-ctx-more'); };
const litMore = more([plain, lit]);
ok(classOf(litMore).includes('is-highlighted') && styleOf(litMore).includes('--hl:#c95d5d'), 'a highlighted token folded behind "+N": the "+N" is highlighted in its colour');
ok(!classOf(more([lit, plain])).includes('is-highlighted'), 'one drawn, not folded: the "+N" for the rest is not');
const dim2 = day(8, '57/49 haze', { dimmed: true });
ok(classOf(more([plain, dim, dim2])).includes('is-filter-dimmed'), 'every folded token dimmed: the "+N" is dimmed');
ok(!classOf(more([plain, dim, plain])).includes('is-filter-dimmed'), 'one folded token not dimmed: the "+N" is not');
ok(!/is-filter-dimmed|is-highlighted/.test(classOf(more([plain, day(9, '60/50 clear')]))), 'nothing marked behind it: a plain "+N"');
render(null, host);
await turns();
if (savedRaf === undefined) delete options.requestAnimationFrame; else options.requestAnimationFrame = savedRaf;
dom.restore();

// The stylesheet draws each of those marks.
const css = readFileSync(new URL('../styles/app.css', import.meta.url), 'utf8');
const hlRule = css.match(/\.is-highlighted :is\(([^)]*)\)[^{]*\{\s*text-decoration: underline/);
ok(hlRule, 'the highlight\'s underline rule is there');
for (const part of ['.bc-chip-title', '.bc-ctx-token-text', '.bc-ctx-token-time', '.bc-dayexpand-ctxtext']) {
  ok(hlRule[1].includes(part), 'the highlight underlines ' + part);
}
const MORE = ['.bc-ctx-more', '.bc-more', '.bc-tg-allday-more', '.bc-tg-density-more', '.bc-vday-more', '.bc-agenda-overflow'];
const underlined = css.match(/,\n:is\(([^)]*)\)\.is-highlighted \{\n  text-decoration: underline/);
const coloured = css.match(/:is\(([^)]*)\)\.is-highlighted \{ color: var\(--hl, var\(--accent\)\); \}/);
for (const part of ['.bc-daylabel', ...MORE]) ok(underlined && underlined[1].includes(part), 'the highlight underlines ' + part);
for (const part of ['.bc-ctx-token', '.bc-dayexpand-ctxrow', '.bc-daylabel', ...MORE]) ok(coloured && coloured[1].includes(part), part + ' takes the highlight\'s colour');

// Every other "+N" in the app takes the folded items' marks the same way:
// the lists behind them, and each one's class.
const { layoutOverlaps } = await import('../src/ui/layout.js');
const laid = layoutOverlaps([{ id: 'a', occ: plain, startMin: 600, endMin: 660 }, { id: 'b', occ: lit, startMin: 610, endMin: 670 }], 30, { maxItems: 1 });
eq([laid.overflow, laid.overflowItems.map((it) => it.occ.instanceId)], [1, [lit.instanceId]], 'the timeline layout names what it folds');
const { buildAgendaGroups } = await import('../src/ui/agendarails.js');
const agendaDay = (id, extra) => ({ ...ev, instanceId: 'a' + id, eventId: 100 + id, title: 'Sample errand ' + id, start: `2031-03-04T${String(8 + id).padStart(2, '0')}:00:00-08:00`, end: `2031-03-04T${String(9 + id).padStart(2, '0')}:00:00-08:00`, ...extra });
const agendaGroup = buildAgendaGroups([agendaDay(1), agendaDay(2, { highlighted: true, highlightColor: '#3d9dc9' })], { maxRowsPerDay: 1 })
  .find((g) => g.overflow > 0);
ok(agendaGroup && agendaGroup.folded.length === agendaGroup.overflow, 'the agenda names what "+N more on this day" folds');
eq(foldedMarks(agendaGroup.folded), { cls: ' is-highlighted', style: '--hl:#3d9dc9' }, 'and it is highlighted when what it folds is');
eq(foldedMarks([]), { cls: '', style: undefined }, 'nothing folded: no mark');
const src = (f) => readFileSync(new URL('../src/ui/' + f, import.meta.url), 'utf8');
const month = src('MonthGrid.js');
const grid = src('TimeGrid.js');
const agenda = src('AgendaList.js');
ok(/class=\$\{'bc-more' \+ fold\.cls\} style=\$\{fold\.style\}/.test(month) && /foldedMarks\(\[\.\.\.plan\.more, \.\.\.foldedByCol\[col\]\]\)/.test(month),
  'the month cell\'s "+N more" wears the marks of the singles and bars it folds');
ok(/class=\$\{'bc-tg-allday-more' \+ fold\.cls\}/.test(grid), 'the week\'s all-day "+N" wears the marks of the bars it folds');
ok(/class=\$\{'bc-tg-density-more' \+ timedFold\.cls\} style=\$\{timedFold\.style\}/.test(grid) && /overflowItems\.map\(\(it\) => it\.occ\), \.\.\.timedContext\.slice\(TIMED_CONTEXT_PER_DAY\)/.test(grid),
  'the timeline\'s "+N more" wears the marks of the events and moments it folds');
ok(/class=\$\{'bc-vday-more' \+ fold\.cls\} style=\$\{fold\.style\}/.test(grid) && /foldedMarks\(bars\.folded\)/.test(grid), 'the day view\'s all-day "+N more" wears the marks of the bars it folds');
ok(/class=\$\{'bc-agenda-overflow' \+ fold\.cls\}/.test(agenda) && /foldedMarks\(g\.folded\)/.test(agenda), 'the agenda\'s "+N more on this day" wears the marks of what it folds');
ok(/^\.is-filter-dimmed \{/m.test(css), 'the dim is one rule for every shape');

console.log('Filter marks: ' + checks + ' checks (context items take a filter\'s dim and highlight as events do)');
