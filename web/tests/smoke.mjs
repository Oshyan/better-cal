// Static smoke test for pure logic: date utils, overlap layout, month
// virtualization math. Run: node web/tests/smoke.mjs

import {
  parseISO, toISOWithOffset, dayKeyOf, dateOfDayKey, epochDayOfKey,
  keyOfEpochDay, addDaysKey, diffDaysKey, weekIndexOfKey, firstEpochDayOfWeek,
  dayKeysOfWeek, startOfWeekKey, setWeekStart, getWeekStart, setTimeFormat, fmtTime,
} from '../src/lib/dates.js';
import { layoutOverlaps, assignLanes, rangesOverlap } from '../src/ui/layout.js';
import {
  visibleWeekRange, weekTop, totalHeight, segmentSpan, occurrenceDaySpan,
  monthStartsInRange, dominantMonthOfWeek, isMultiDay,
  monthsAround, miniMonthGrid, dayDropDates, timeDropDates,
} from '../src/ui/monthmath.js';
import { contrastText, withAlpha, parseHex } from '../src/lib/color.js';
import { baseTitle, groupOccurrences, itemMatchesFilter, isGroupId } from '../src/ui/grouping.js';
import { sortByMatch } from '../src/lib/rank.js';
import { parseJumpText } from '../src/lib/jumpparse.js';
import { monthWeeks, stepMonthOf } from '../src/lib/minimonth.js';
import { HOTKEYS, HOTKEY_GROUPS } from '../src/app/hotkeys.js';

let passed = 0;
let failed = 0;

function assert(name, cond) {
  if (cond) { passed++; console.log('  ok  ' + name); }
  else { failed++; console.error('FAIL  ' + name); }
}

function eq(name, actual, expected) {
  const a = JSON.stringify(actual);
  const b = JSON.stringify(expected);
  if (a === b) { passed++; console.log('  ok  ' + name); }
  else { failed++; console.error('FAIL  ' + name + '\n      got      ' + a + '\n      expected ' + b); }
}

console.log('--- ISO round-trips ---');

// 1. Offset-preserving instant round-trip.
const iso1 = '2026-07-30T14:30:00-07:00';
eq('parseISO preserves instant', parseISO(iso1).getTime(), Date.UTC(2026, 6, 30, 21, 30, 0));

// 2. Local format -> parse round-trip preserves the instant.
const d2 = new Date(2026, 0, 15, 9, 45, 30);
eq('toISOWithOffset round-trip', parseISO(toISOWithOffset(d2)).getTime(), d2.getTime());

// 3. Formatted string carries an explicit offset (never Z).
assert('toISOWithOffset has offset suffix', /[+-]\d{2}:\d{2}$/.test(toISOWithOffset(d2)));

// 4. Day keys are local-time based and survive dateOfDayKey round-trip.
eq('dayKey round-trip', dayKeyOf(dateOfDayKey('2026-03-08')), '2026-03-08'); // DST-spring day in US

// 5. Epoch day math is timezone-proof integer arithmetic.
eq('epochDay of 1970-01-01', epochDayOfKey('1970-01-01'), 0);
eq('keyOfEpochDay inverse', keyOfEpochDay(epochDayOfKey('2026-07-30')), '2026-07-30');

console.log('--- day/week math ---');

// 7. addDays across a month boundary.
eq('addDaysKey month boundary', addDaysKey('2026-07-30', 3), '2026-08-02');

// 8. addDays across a year boundary, backwards.
eq('addDaysKey year boundary back', addDaysKey('2026-01-01', -1), '2025-12-31');

// 9. diffDays across DST (calendar days, not 24h blocks).
eq('diffDaysKey across DST', diffDaysKey('2026-03-10', '2026-03-05'), 5);

// 10. Monday-start weeks: 2026-07-30 is a Thursday; week starts 2026-07-27.
eq('startOfWeekKey Monday', startOfWeekKey('2026-07-30'), '2026-07-27');
eq('startOfWeek of a Monday is itself', startOfWeekKey('2026-07-27'), '2026-07-27');

// 12. dayKeysOfWeek returns 7 consecutive days beginning Monday.
const wk = dayKeysOfWeek(weekIndexOfKey('2026-07-30'));
eq('dayKeysOfWeek span', [wk[0], wk[6]], ['2026-07-27', '2026-08-02']);

// 13. Week index/firstEpochDay agree.
assert('week index anchors to Monday',
  firstEpochDayOfWeek(weekIndexOfKey('2026-07-30')) === epochDayOfKey('2026-07-27'));

console.log('--- virtualization math ---');

// 14. Visible range covers viewport plus buffer, clamped to bounds.
eq('visibleWeekRange basic', visibleWeekRange(0, 400, 100, 1000, 2000, 2), { first: 1000, last: 1006 });
eq('visibleWeekRange mid-scroll', visibleWeekRange(1000, 400, 100, 1000, 2000, 2), { first: 1008, last: 1016 });
eq('visibleWeekRange clamps to max', visibleWeekRange(99900, 400, 100, 1000, 2000, 2).last, 2000);

// 17. weekTop/totalHeight are consistent.
eq('weekTop', weekTop(1005, 1000, 100), 500);
eq('totalHeight', totalHeight(1000, 2000, 100), 100100);

console.log('--- multi-day segmentation ---');

// 19. A span within one week yields a single segment.
eq('segmentSpan single week', segmentSpan('2026-07-28', '2026-07-30'), [
  { weekIndex: weekIndexOfKey('2026-07-28'), startCol: 1, endCol: 3, contLeft: false, contRight: false },
]);

// 20. A span crossing a week boundary splits with continuation flags.
const segs = segmentSpan('2026-07-31', '2026-08-04'); // Fri -> Tue
eq('segmentSpan two weeks', segs, [
  { weekIndex: weekIndexOfKey('2026-07-31'), startCol: 4, endCol: 6, contLeft: false, contRight: true },
  { weekIndex: weekIndexOfKey('2026-08-03'), startCol: 0, endCol: 1, contLeft: true, contRight: false },
]);

// 21. Jul 1 (Wed) to Jul 20 (Mon) 2026 spans 4 week rows; the two middle
// weeks are fully continued Monday-to-Sunday.
const seg3 = segmentSpan('2026-07-01', '2026-07-20');
assert('segmentSpan 4 weeks middle continuation',
  seg3.length === 4 &&
  seg3.slice(1, 3).every((s) => s.contLeft && s.contRight && s.startCol === 0 && s.endCol === 6) &&
  seg3[3].startCol === 0 && seg3[3].endCol === 0 && seg3[3].contLeft && !seg3[3].contRight);

// 22. occurrenceDaySpan: timed event ending at midnight stays on its start day.
const localISO = (y, m, d, hh, mm) => toISOWithOffset(new Date(y, m - 1, d, hh, mm));
eq('midnight end does not spill',
  occurrenceDaySpan({ start: localISO(2026, 7, 30, 22, 0), end: localISO(2026, 7, 31, 0, 0), allDay: false }),
  { startKey: '2026-07-30', endKey: '2026-07-30' });

// 23. All-day exclusive end: 1-day event spans one day.
eq('allday exclusive end',
  occurrenceDaySpan({ start: localISO(2026, 7, 30, 0, 0), end: localISO(2026, 7, 31, 0, 0), allDay: true }),
  { startKey: '2026-07-30', endKey: '2026-07-30' });

// 24. Multi-day detection.
assert('isMultiDay true for 3-day event',
  isMultiDay({ start: localISO(2026, 7, 30, 10, 0), end: localISO(2026, 8, 1, 10, 0), allDay: false }));

// 25. Month gutter labels: August 2026 starts in the week of 2026-07-27.
const labels = monthStartsInRange(weekIndexOfKey('2026-07-20'), weekIndexOfKey('2026-08-10'));
assert('monthStartsInRange finds Aug 1',
  labels.some((l) => l.year === 2026 && l.month === 8 && l.weekIndex === weekIndexOfKey('2026-07-27')));

// 26. Dominant month of a boundary week (Jul 27 - Aug 2: 5 July days).
eq('dominantMonthOfWeek boundary', dominantMonthOfWeek(weekIndexOfKey('2026-07-30')), { year: 2026, month: 7 });

console.log('--- reschedule math ---');

// monthsAround: 25 entries centered on the base, rolling across year bounds.
const ma = monthsAround(2026, 1, 12);
eq('monthsAround span', [ma.length, ma[0].year, ma[0].month, ma[24].year, ma[24].month],
  [25, 2025, 1, 2027, 1]);

// miniMonthGrid: Feb 2027 starts on a Monday and fits exactly 4 week rows.
eq('miniMonthGrid Feb 2027', miniMonthGrid(2027, 2),
  { year: 2027, month: 2, firstKey: '2027-02-01', weekCount: 4 });

// miniMonthGrid: Aug 2026 (Sat start, Mon end) spans 6 week rows.
eq('miniMonthGrid Aug 2026 weeks', miniMonthGrid(2026, 8).weekCount, 6);

// dayDropDates preserves time-of-day and duration.
const occTimed = { start: localISO(2026, 8, 3, 14, 30), end: localISO(2026, 8, 3, 16, 0), allDay: false };
eq('dayDropDates timed', dayDropDates(occTimed, '2026-08-12'),
  { newStart: localISO(2026, 8, 12, 14, 30), newEnd: localISO(2026, 8, 12, 16, 0), delta: 9 });

// dayDropDates multi-day: length kept, drop sets the START day.
const occMulti = { start: localISO(2026, 8, 3, 9, 0), end: localISO(2026, 8, 6, 17, 0), allDay: false };
eq('dayDropDates multi-day keeps length', dayDropDates(occMulti, '2026-09-01'),
  { newStart: localISO(2026, 9, 1, 9, 0), newEnd: localISO(2026, 9, 4, 17, 0), delta: 29 });

// timeDropDates snaps the pointer minute to 15 and keeps the duration.
eq('timeDropDates snaps to 15', timeDropDates(occTimed, '2026-08-12', 611), // 10:11 -> 10:15
  { newStart: localISO(2026, 8, 12, 10, 15), newEnd: localISO(2026, 8, 12, 11, 45) });

// timeDropDates clamps so the event still starts inside the target day.
eq('timeDropDates clamps to day end', timeDropDates(occTimed, '2026-08-12', 1435).newEnd,
  localISO(2026, 8, 13, 0, 0));

console.log('--- overlap layout ---');

// 27. Non-overlapping events each get full width.
const L1 = layoutOverlaps([
  { id: 'a', startMin: 60, endMin: 120 },
  { id: 'b', startMin: 180, endMin: 240 },
]);
assert('disjoint events full width', L1.every((e) => e.cols === 1 && e.col === 0));

// 28. Two overlapping events split into two columns.
const L2 = layoutOverlaps([
  { id: 'a', startMin: 60, endMin: 180 },
  { id: 'b', startMin: 120, endMin: 240 },
]);
assert('pair overlap two cols', L2.every((e) => e.cols === 2) &&
  new Set(L2.map((e) => e.col)).size === 2);

// 29. Transitive cluster: a-b overlap, b-c overlap, a-c do not; all share width.
const L3 = layoutOverlaps([
  { id: 'a', startMin: 60, endMin: 130 },
  { id: 'b', startMin: 120, endMin: 200 },
  { id: 'c', startMin: 190, endMin: 260 },
]);
const cById = Object.fromEntries(L3.map((e) => [e.id, e]));
assert('transitive cluster shares column count', L3.every((e) => e.cols === 2));
assert('greedy lane reuse: c reuses column of a', cById.c.col === cById.a.col);

// 31. Separate clusters reset column math.
const L4 = layoutOverlaps([
  { id: 'a', startMin: 0, endMin: 60 },
  { id: 'b', startMin: 30, endMin: 90 },
  { id: 'x', startMin: 600, endMin: 660 },
]);
assert('cluster reset', L4.find((e) => e.id === 'x').cols === 1);

// 32. Minimum visual span inflates collision: two back-to-back 10-min events overlap visually.
const L5 = layoutOverlaps([
  { id: 'a', startMin: 60, endMin: 70 },
  { id: 'b', startMin: 75, endMin: 85 },
], 30);
assert('min visual span forces columns', L5.every((e) => e.cols === 2));
assert('visualEnd >= start + minSpan', L5.every((e) => e.visualEnd - e.startMin >= 30));

// 34. Sort order: same start, longer first gets column 0.
const L6 = layoutOverlaps([
  { id: 'short', startMin: 60, endMin: 90 },
  { id: 'long', startMin: 60, endMin: 240 },
]);
assert('longest-first at same start', L6.find((e) => e.id === 'long').col === 0);

// 35. Lane assignment packs bars greedily.
const lanes = assignLanes([
  { id: 'a', startCol: 0, endCol: 2 },
  { id: 'b', startCol: 1, endCol: 4 },
  { id: 'c', startCol: 3, endCol: 6 },
]);
eq('assignLanes greedy packing', [lanes.get('a'), lanes.get('b'), lanes.get('c')], [0, 1, 0]);

// 36. rangesOverlap half-open semantics.
assert('rangesOverlap half-open', !rangesOverlap(0, 10, 10, 20) && rangesOverlap(0, 11, 10, 20));

console.log('--- match ranking sort ---');

// Scored events lead (score desc), unscored sink to time order after them.
const rk = (id, start, score) => ({ instanceId: id, start, score });
const ranked = sortByMatch([
  rk('a', '2026-08-01T10:00:00-07:00', 0.2),
  rk('b', '2026-08-03T10:00:00-07:00', null),
  rk('c', '2026-08-02T10:00:00-07:00', 0.9),
  rk('d', '2026-08-01T09:00:00-07:00', undefined),
]);
eq('sortByMatch scored first by score desc', ranked.map((o) => o.instanceId), ['c', 'a', 'd', 'b']);

// Equal scores fall back to time order.
eq('sortByMatch equal scores tie by start',
  sortByMatch([rk('x', '2026-08-05T10:00:00-07:00', 0.5), rk('y', '2026-08-04T10:00:00-07:00', 0.5)]).map((o) => o.instanceId),
  ['y', 'x']);

// All unscored: plain time order (score of 0 still counts as scored).
eq('sortByMatch zero score is still scored',
  sortByMatch([rk('u', '2026-08-01T10:00:00-07:00', null), rk('v', '2026-08-02T10:00:00-07:00', 0)]).map((o) => o.instanceId),
  ['v', 'u']);
eq('sortByMatch empty input', sortByMatch([]), []);

console.log('--- near-duplicate grouping ---');

// All-day occurrence factory (literal-date serialization, exclusive end).
let occSeq = 0;
const mkOcc = (calendarId, title, dayKey, opts = {}) => ({
  instanceId: 'occ' + (++occSeq),
  calendarId,
  title,
  start: dayKey + 'T00:00:00+00:00',
  end: addDaysKey(dayKey, opts.days || 1) + 'T00:00:00+00:00',
  allDay: true,
  attendance: 'none',
  ...opts.extra,
});
const FLAGS = { 1: true, 2: true, 3: false };

// baseTitle strips one trailing parenthetical, keeps everything else.
eq('grouping: baseTitle strips parenthetical', baseTitle('Juneteenth (Alabama)'), 'Juneteenth');
eq('grouping: baseTitle plain title unchanged', baseTitle('Juneteenth'), 'Juneteenth');
eq('grouping: baseTitle keeps mid-title parens', baseTitle('Day (off) party'), 'Day (off) party');
eq('grouping: baseTitle pure parenthetical kept', baseTitle('(TBD)'), '(TBD)');

// Parenthetical variants plus an exact-duplicate title collapse to one group.
const j1 = mkOcc(1, 'Juneteenth (Alabama)', '2026-06-19');
const j2 = mkOcc(1, 'Juneteenth (Texas)', '2026-06-19');
const j3 = mkOcc(1, 'Juneteenth', '2026-06-19');
const j4 = mkOcc(1, 'Juneteenth', '2026-06-19'); // exact duplicate title
const solo = mkOcc(1, 'Solstice', '2026-06-19');
const g1 = groupOccurrences([j1, j2, j3, j4, solo], FLAGS);
eq('grouping: variants + duplicates collapse to group + singleton', g1.length, 2);
assert('grouping: group item flagged and counted', g1[0].isGroup === true && g1[0].count === 4);
eq('grouping: group title is the shared base', g1[0].title, 'Juneteenth');
assert('grouping: group id is recognizable', isGroupId(g1[0].instanceId) && !isGroupId(solo.instanceId));
assert('grouping: group replaces first member in order', g1[1] === solo);
assert('grouping: members kept inside the group', g1[0].members.length === 4 && g1[0].members.includes(j3));

// Singletons never group: a lone parenthetical variant passes through as-is.
const loneVariant = mkOcc(1, 'Juneteenth (Alabama)', '2026-06-20');
eq('grouping: no group for singletons', groupOccurrences([loneVariant, solo], FLAGS), [loneVariant, solo]);

// Different calendars never group, even with identical titles and day.
const calA = mkOcc(1, 'Juneteenth', '2026-06-19');
const calB = mkOcc(2, 'Juneteenth', '2026-06-19');
assert('grouping: different calendars never group', groupOccurrences([calA, calB], FLAGS).every((o) => !o.isGroup));

// Different days never group.
const day1 = mkOcc(1, 'Standup', '2026-06-19');
const day2 = mkOcc(1, 'Standup', '2026-06-20');
assert('grouping: different days never group', groupOccurrences([day1, day2], FLAGS).every((o) => !o.isGroup));

// Calendars without groupSimilar never group.
const off1 = mkOcc(3, 'Juneteenth (A)', '2026-06-19');
const off2 = mkOcc(3, 'Juneteenth (B)', '2026-06-19');
assert('grouping: flag off passes through', groupOccurrences([off1, off2], FLAGS).every((o) => !o.isGroup));

// Hidden members never join a group; counts exclude them.
const h1 = mkOcc(1, 'Holiday (A)', '2026-07-04');
const h2 = mkOcc(1, 'Holiday (B)', '2026-07-04');
const h3 = mkOcc(1, 'Holiday (C)', '2026-07-04', { extra: { attendance: 'hidden' } });
const gh = groupOccurrences([h1, h2, h3], FLAGS);
assert('grouping: hidden excluded from count', gh[0].isGroup && gh[0].count === 2 && gh.includes(h3));

// Multi-day events never group.
const m1 = mkOcc(1, 'Fair', '2026-06-19', { days: 3 });
const m2 = mkOcc(1, 'Fair', '2026-06-19', { days: 3 });
assert('grouping: multi-day never groups', groupOccurrences([m1, m2], FLAGS).every((o) => !o.isGroup));

// Group dim state: dimmed only when ALL members are dimmed.
const dm1 = mkOcc(1, 'Fest (A)', '2026-08-01', { extra: { dimmed: true } });
const dm2 = mkOcc(1, 'Fest (B)', '2026-08-01', { extra: { dimmed: true } });
const dm3 = mkOcc(1, 'Fest (C)', '2026-08-01');
assert('grouping: all members dimmed -> group dimmed', groupOccurrences([dm1, dm2], FLAGS)[0].dimmed === true);
assert('grouping: one undimmed member -> group not dimmed', groupOccurrences([dm1, dm2, dm3], FLAGS)[0].dimmed === false);

// Client-filter predicate: a group matches when any member matches.
const gMatch = groupOccurrences([j1, j2], FLAGS)[0];
assert('grouping: group matches when any member matches',
  itemMatchesFilter(gMatch, (o) => o.title.includes('Texas')) &&
  !itemMatchesFilter(gMatch, (o) => o.title.includes('Ohio')) &&
  itemMatchesFilter(solo, (o) => o.title === 'Solstice'));

console.log('--- jump text parser ---');

// Base for all cases: Friday 2026-07-31.
const JB = '2026-07-31';

// Relative words.
eq('jump: today', parseJumpText('today', JB), '2026-07-31');
eq('jump: tomorrow crosses month', parseJumpText('tomorrow', JB), '2026-08-01');

// ISO and plain year.
eq('jump: ISO date', parseJumpText('2027-06-15', JB), '2027-06-15');
eq('jump: bare year', parseJumpText('2027', JB), '2027-01-01');

// m/d forms: future-biased without a year, literal with one.
eq('jump: m/d upcoming this year', parseJumpText('8/15', JB), '2026-08-15');
eq('jump: m/d already passed rolls to next year', parseJumpText('3/1', JB), '2027-03-01');
eq('jump: m/d/yy', parseJumpText('8/15/27', JB), '2027-08-15');
eq('jump: invalid day rejected', parseJumpText('2/30', JB), null);

// Month names: prefix match, case-insensitive, future-biased.
eq('jump: month + year', parseJumpText('June 2027', JB), '2027-06-01');
eq('jump: bare future month stays this year', parseJumpText('august', JB), '2026-08-01');
eq('jump: bare passed month rolls to next year', parseJumpText('jun', JB), '2027-06-01');
eq('jump: current month is not passed', parseJumpText('july', JB), '2026-07-01');
eq('jump: month + day passed rolls forward', parseJumpText('jun 5', JB), '2027-06-05');
eq('jump: day-first form with year', parseJumpText('5 june 2027', JB), '2027-06-05');

// Weekdays: soonest strictly-future occurrence; "next"/"this" are synonyms.
eq('jump: weekday', parseJumpText('tuesday', JB), '2026-08-04');
eq('jump: next weekday', parseJumpText('next tuesday', JB), '2026-08-04');
eq('jump: same weekday means a week out', parseJumpText('friday', JB), '2026-08-07');
eq('jump: weekday prefix', parseJumpText('tue', JB), '2026-08-04');

// Garbage in, null out.
eq('jump: gibberish', parseJumpText('fnord', JB), null);
eq('jump: empty', parseJumpText('   ', JB), null);

console.log('--- week start setting ---');
// All earlier week tests ran under the default (Monday). Flip to Sunday and
// verify the whole week pipeline pivots, then restore.

setWeekStart('sun');
eq('getWeekStart reflects setting', getWeekStart(), 'sun');
// 2026-07-30 is a Thursday; its Sunday-started week begins 2026-07-26.
eq('sun: startOfWeekKey of a Thursday', startOfWeekKey('2026-07-30'), '2026-07-26');
eq('sun: startOfWeek of a Sunday is itself', startOfWeekKey('2026-07-26'), '2026-07-26');
// A Monday now belongs to the week that began the day before.
eq('sun: Monday joins the prior Sunday week', startOfWeekKey('2026-07-27'), '2026-07-26');
const wkSun = dayKeysOfWeek(weekIndexOfKey('2026-07-30'));
eq('sun: dayKeysOfWeek span', [wkSun[0], wkSun[6]], ['2026-07-26', '2026-08-01']);
assert('sun: week index anchors to Sunday',
  firstEpochDayOfWeek(weekIndexOfKey('2026-07-30')) === epochDayOfKey('2026-07-26'));

setWeekStart('mon');
eq('mon restored: startOfWeekKey', startOfWeekKey('2026-07-30'), '2026-07-27');
eq('mon restored: getWeekStart', getWeekStart(), 'mon');

console.log('--- mini month grid (shared: jump popover + sidebar) ---');

// Monday start (restored above): July 2026 (Wed start, Fri end) spans 5 rows.
const mw = monthWeeks(2026, 7);
assert('minimonth: every row is 7 wide', mw.every((r) => r.length === 7));
eq('minimonth: July 2026 row count', mw.length, 5);
eq('minimonth: grid starts on the configured week start', mw[0][0], startOfWeekKey('2026-07-01'));
assert('minimonth: first row contains the 1st', mw[0].includes('2026-07-01'));
assert('minimonth: last row contains the 31st', mw[mw.length - 1].includes('2026-07-31'));
eq('minimonth: covers every day of the month exactly once',
  mw.flat().filter((k) => k.startsWith('2026-07')).length, 31);
eq('minimonth: pads with adjacent-month days', mw[0][0], '2026-06-29');

// Feb 2027 fits exactly 4 Monday-started rows; a Sunday start adds a fifth.
eq('minimonth: Feb 2027 Monday start rows', monthWeeks(2027, 2).length, 4);
setWeekStart('sun');
eq('minimonth: Feb 2027 Sunday start rows', monthWeeks(2027, 2).length, 5);
eq('minimonth: Sunday grid starts on a Sunday', monthWeeks(2027, 2)[0][0], '2027-01-31');
setWeekStart('mon');

// stepMonthOf rolls across year boundaries in both directions.
eq('minimonth: step back across year', stepMonthOf(2026, 1, -1), { year: 2025, month: 12 });
eq('minimonth: step forward across year', stepMonthOf(2026, 12, 1), { year: 2027, month: 1 });
eq('minimonth: multi-year step', stepMonthOf(2026, 7, 30), { year: 2029, month: 1 });

console.log('--- hotkey table integrity ---');

// keyboard.js dispatches from this table and the ? cheat sheet renders it;
// these assertions keep the table well-formed so neither can drift.
assert('hotkeys: table is non-empty', HOTKEYS.length >= 15);
assert('hotkeys: every entry has keys + label + group + id', HOTKEYS.every((h) =>
  Array.isArray(h.keys) && h.keys.length > 0 &&
  h.keys.every((k) => typeof k === 'string' && k.length > 0) &&
  typeof h.label === 'string' && h.label.length > 0 &&
  typeof h.id === 'string' && h.id.length > 0 &&
  HOTKEY_GROUPS.includes(h.group)));
eq('hotkeys: ids are unique', new Set(HOTKEYS.map((h) => h.id)).size, HOTKEYS.length);
const allKeys = HOTKEYS.flatMap((h) => h.keys);
eq('hotkeys: no key bound twice', new Set(allKeys).size, allKeys.length);
assert('hotkeys: every group is used', HOTKEY_GROUPS.every((g) => HOTKEYS.some((h) => h.group === g)));
assert('hotkeys: cheat sheet binding exists', HOTKEYS.some((h) => h.keys.includes('?')));
assert('hotkeys: display overrides are string arrays', HOTKEYS.every((h) =>
  h.display === undefined || (Array.isArray(h.display) && h.display.every((k) => typeof k === 'string'))));

console.log('--- time format setting ---');

const t1405 = new Date(2026, 0, 1, 14, 5);
setTimeFormat('24');
assert('24h format shows 14', fmtTime(t1405).includes('14'));
assert('24h format has no AM/PM', !/am|pm/i.test(fmtTime(t1405)));
setTimeFormat('12');
assert('12h format shows 2:05', fmtTime(t1405).includes('2:05'));

console.log('--- color utils ---');

// 37. Contrast text flips between light and dark backgrounds.
assert('contrast on dark bg is white', contrastText('#1c2a4a') === '#ffffff');
assert('contrast on light bg is dark', contrastText('#f2e9c9') === '#1a1a1a');
eq('withAlpha', withAlpha('#ff0000', 0.5), 'rgba(255,0,0,0.5)');
assert('parseHex shorthand', JSON.stringify(parseHex('#abc')) === JSON.stringify({ r: 170, g: 187, b: 204 }));

console.log('');
console.log(passed + ' passed, ' + failed + ' failed');
if (failed > 0) process.exit(1);
