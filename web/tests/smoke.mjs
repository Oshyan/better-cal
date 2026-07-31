// Static smoke test for pure logic: date utils, overlap layout, month
// virtualization math. Run: node web/tests/smoke.mjs

import {
  parseISO, toISOWithOffset, dayKeyOf, dateOfDayKey, epochDayOfKey,
  keyOfEpochDay, addDaysKey, diffDaysKey, weekIndexOfKey, firstEpochDayOfWeek,
  dayKeysOfWeek, startOfWeekKey,
} from '../src/lib/dates.js';
import { layoutOverlaps, assignLanes, rangesOverlap } from '../src/ui/layout.js';
import {
  visibleWeekRange, weekTop, totalHeight, segmentSpan, occurrenceDaySpan,
  monthStartsInRange, dominantMonthOfWeek, isMultiDay,
  monthsAround, miniMonthGrid, dayDropDates, timeDropDates,
} from '../src/ui/monthmath.js';
import { contrastText, withAlpha, parseHex } from '../src/lib/color.js';
import { sortByMatch } from '../src/lib/rank.js';

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

console.log('--- color utils ---');

// 37. Contrast text flips between light and dark backgrounds.
assert('contrast on dark bg is white', contrastText('#1c2a4a') === '#ffffff');
assert('contrast on light bg is dark', contrastText('#f2e9c9') === '#1a1a1a');
eq('withAlpha', withAlpha('#ff0000', 0.5), 'rgba(255,0,0,0.5)');
assert('parseHex shorthand', JSON.stringify(parseHex('#abc')) === JSON.stringify({ r: 170, g: 187, b: 204 }));

console.log('');
console.log(passed + ' passed, ' + failed + ' failed');
if (failed > 0) process.exit(1);
