// Static smoke test for pure logic: date utils, overlap layout, month
// virtualization math. Run: node web/tests/smoke.mjs

import {
  parseISO, toISOWithOffset, dayKeyOf, dateOfDayKey, epochDayOfKey,
  keyOfEpochDay, addDaysKey, diffDaysKey, weekIndexOfKey, firstEpochDayOfWeek,
  dayKeysOfWeek, startOfWeekKey, setWeekStart, getWeekStart, setTimeFormat, fmtTime,
  fmtMonthShort, timeState, startMs, byStart,
} from '../src/lib/dates.js';
import { layoutOverlaps, assignLanes, rangesOverlap } from '../src/ui/layout.js';
import {
  visibleWeekRange, weekTop, totalHeight, segmentSpan, occurrenceDaySpan,
  monthStartsInRange, dominantMonthOfWeek, isMultiDay,
  monthsAround, miniMonthGrid, dayDropDates, timeDropDates,
  rowSpanSegments, rowIndexOfEpochDay, rowIndexOfDayKey, firstEpochDayOfRow,
  dayKeysOfRow, isWeekendEpochDay, dominantMonthOfRow, dominantMonthOfRows,
} from '../src/ui/monthmath.js';
import { contrastText, withAlpha, parseHex } from '../src/lib/color.js';
import { baseTitle, groupOccurrences, itemMatchesFilter, isGroupId } from '../src/ui/grouping.js';
import { sortByMatch } from '../src/lib/rank.js';
import { parseJumpText, jumpGranularity } from '../src/lib/jumpparse.js';
import { monthWeeks, stepMonthOf } from '../src/lib/minimonth.js';
import { missingRanges } from '../src/app/store.js';
import {
  normalizeDayRange, dayRangeDraft, dayRangeLabel, timeRangeLabel, chipPosition,
  dragCreateMode, allDayRangeDraft,
} from '../src/lib/quickcreate.js';
import { HOTKEYS, HOTKEY_GROUPS } from '../src/app/hotkeys.js';
import {
  fmtOffsetMinutes, fmtReminder, allDayEntryToMinutes, entryToMinutes,
  normalizeMinutesList, effectiveReminders, toMinutes, fromMinutes,
  TIMED_CHOICES, MAX_REMINDER_MINUTES, REMINDER_UNITS,
} from '../src/lib/reminders.js';
import { deepLinkAnchorMs, deepLinkWindows } from '../src/lib/deeplink.js';
import {
  tripSpan, tripSpanLabel, tripBandSegments, spansOverlapOrAbut,
  candidateTrips, attachableInSpan, extendTripSpan,
} from '../src/ui/trips.js';
import {
  buildAgendaGroups, railRanges, washRects, spanDayCount, dayOfSpanLabel,
} from '../src/ui/agendarails.js';
import { hasHtml, stripToText, isEmptyHtml } from '../src/lib/richtext.js';
import { batteryTipApplies, BATTERY_TIP_BODY, BATTERY_TIP_TITLE } from '../src/lib/batterytip.js';
import { fuzzyScore, rankByFuzzy } from '../src/lib/fuzzy.js';
import { STATIC_COMMANDS, COMMAND_GROUPS, MANAGE_ITEMS, VIEW_LABELS, VIEW_ICONS } from '../src/app/commanddefs.js';

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

// 26b. Dominant month over the whole visible window (visible-month desync
// fix): the top row alone can disagree with what the window mostly shows.
const wJul27 = weekIndexOfKey('2026-07-27'); // Jul 27 - Aug 2: July-dominant row
// Scrolled down so the old month's tail is the top row: 6 visible rows run
// Jul 27 - Sep 6 (5 Jul, 31 Aug, 6 Sep cells) and the window is August even
// though the top row alone says July.
eq('dominantMonthOfRows: July tail top row, window is August',
  dominantMonthOfRows(wJul27, 6, 7), { year: 2026, month: 8 });
// Coming from the other direction with a short window (2 rows: Jul 27 -
// Aug 9 = 5 Jul vs 9 Aug), August already wins.
eq('dominantMonthOfRows: 2-row window flips at the boundary',
  dominantMonthOfRows(wJul27, 2, 7), { year: 2026, month: 8 });
// A 1-row window degenerates to the top-row answer (July).
eq('dominantMonthOfRows: 1-row window matches dominantMonthOfRow',
  dominantMonthOfRows(wJul27, 1, 7), dominantMonthOfRow(wJul27, 7));
// Exact boundary: Jun 1 2026 is a Monday, so 4 rows are exactly Jun 1-28.
eq('dominantMonthOfRows: exact month-aligned window',
  dominantMonthOfRows(weekIndexOfKey('2026-06-01'), 4, 7), { year: 2026, month: 6 });
// Tie goes to the later month: May 25-31 (all May) + Jun 1-7 (all June)
// is 7 cells each; June wins.
eq('dominantMonthOfRows: tie prefers later month',
  dominantMonthOfRows(weekIndexOfKey('2026-05-25'), 2, 7), { year: 2026, month: 6 });
// 3-column ribbon: the chunk holding Aug 1 is Jul 30-Aug 1 (July-dominant on
// its own), but a 10-row window (Jul 30 - Aug 28) is August.
eq('dominantMonthOfRows: 3col window is August despite July top chunk',
  dominantMonthOfRows(rowIndexOfDayKey('2026-08-01', 3), 10, 3), { year: 2026, month: 8 });
// Invariant sweep: the returned month always owns at least as many visible
// cells as any other month, for both column modes across many windows.
{
  let holds = true;
  for (const cols of [7, 3]) {
    const base = rowIndexOfDayKey('2026-01-01', cols);
    for (let r = base; r < base + 40; r++) {
      const got = dominantMonthOfRows(r, 5, cols);
      const counts = new Map();
      for (let i = 0; i < 5; i++) {
        for (const k of dayKeysOfRow(r + i, cols)) {
          const [y, m] = k.split('-').map(Number);
          const mk = y * 12 + (m - 1);
          counts.set(mk, (counts.get(mk) || 0) + 1);
        }
      }
      const gotN = counts.get(got.year * 12 + (got.month - 1)) || 0;
      for (const n of counts.values()) if (n > gotN) holds = false;
    }
  }
  assert('dominantMonthOfRows invariant: winner has max visible cells', holds);
}

console.log('--- 3-column ribbon math ---');

// Chunk boundaries are anchored at epoch day 0 and are exactly 3 days wide.
eq('3col: epoch day 0 is row 0', rowIndexOfEpochDay(epochDayOfKey('1970-01-01'), 3), 0);
eq('3col: day 2 still row 0', rowIndexOfEpochDay(epochDayOfKey('1970-01-03'), 3), 0);
eq('3col: day 3 starts row 1', rowIndexOfEpochDay(epochDayOfKey('1970-01-04'), 3), 1);
eq('3col: firstEpochDayOfRow inverts row 0', keyOfEpochDay(firstEpochDayOfRow(0, 3)), '1970-01-01');
assert('3col: rows are 3 days wide',
  firstEpochDayOfRow(rowIndexOfDayKey('2026-08-01', 3) + 1, 3) -
  firstEpochDayOfRow(rowIndexOfDayKey('2026-08-01', 3), 3) === 3);

// A day always falls inside its own row.
const r801 = rowIndexOfDayKey('2026-08-01', 3);
assert('3col: day within its row bounds',
  epochDayOfKey('2026-08-01') >= firstEpochDayOfRow(r801, 3) &&
  epochDayOfKey('2026-08-01') <= firstEpochDayOfRow(r801, 3) + 2);

// The chunk holding 2026-08-01 is 2026-07-30..2026-08-01 (epoch % 3 = 2).
eq('3col: dayKeysOfRow around Aug 1 2026', dayKeysOfRow(r801, 3),
  ['2026-07-30', '2026-07-31', '2026-08-01']);

// Stable anchors: chunking never depends on the week-start setting.
setWeekStart('sun');
eq('3col: row index unaffected by week start', rowIndexOfDayKey('2026-08-01', 3), r801);
setWeekStart('mon');

// columns=7 delegates to real week rows (week-start aware).
eq('7col: rowIndex matches weekIndex', rowIndexOfEpochDay(epochDayOfKey('2026-07-30'), 7), weekIndexOfKey('2026-07-30'));
eq('7col: dayKeysOfRow matches dayKeysOfWeek', dayKeysOfRow(weekIndexOfKey('2026-07-30'), 7), dayKeysOfWeek(weekIndexOfKey('2026-07-30')));

// Segmentation within one chunk: no continuation flags.
eq('3col: segment inside one chunk', rowSpanSegments('2026-07-30', '2026-07-31', 3), [
  { rowIndex: r801, startCol: 0, endCol: 1, contLeft: false, contRight: false },
]);

// Segmentation across a chunk boundary splits with continuation flags.
eq('3col: segment across two chunks', rowSpanSegments('2026-07-31', '2026-08-03', 3), [
  { rowIndex: r801, startCol: 1, endCol: 2, contLeft: false, contRight: true },
  { rowIndex: r801 + 1, startCol: 0, endCol: 1, contLeft: true, contRight: false },
]);

// A 7-day span covers 3+ chunks; middle chunks are fully continued.
const seg7 = rowSpanSegments('2026-07-30', '2026-08-05', 3);
assert('3col: 7-day span middle chunks fully continued',
  seg7.length === 3 &&
  seg7[0].startCol === 0 && seg7[0].endCol === 2 && !seg7[0].contLeft && seg7[0].contRight &&
  seg7[1].contLeft && seg7[1].contRight && seg7[1].startCol === 0 && seg7[1].endCol === 2 &&
  seg7[2].contLeft && !seg7[2].contRight && seg7[2].startCol === 0 && seg7[2].endCol === 0);

// segmentSpan stays the historical columns=7 shape (delegation intact).
eq('segmentSpan delegates to rowSpanSegments(7)',
  segmentSpan('2026-07-31', '2026-08-04'),
  rowSpanSegments('2026-07-31', '2026-08-04', 7).map((s) => ({
    weekIndex: s.rowIndex, startCol: s.startCol, endCol: s.endCol,
    contLeft: s.contLeft, contRight: s.contRight,
  })));

// Gutter labels: the chunk containing Aug 1 2026 carries the August label.
const labels3 = monthStartsInRange(r801 - 4, r801 + 4, 3);
assert('3col: monthStartsInRange finds Aug 1',
  labels3.some((l) => l.year === 2026 && l.month === 8 && l.weekIndex === r801));

// Dominant month of the boundary chunk Jul 30, Jul 31, Aug 1: July (2 of 3).
eq('3col: dominantMonthOfRow boundary chunk', dominantMonthOfRow(r801, 3), { year: 2026, month: 7 });

// Weekend detection (2026-08-01 Sat, 08-02 Sun, 08-03 Mon).
assert('weekend: Saturday', isWeekendEpochDay(epochDayOfKey('2026-08-01')));
assert('weekend: Sunday', isWeekendEpochDay(epochDayOfKey('2026-08-02')));
assert('weekend: Monday is not', !isWeekendEpochDay(epochDayOfKey('2026-08-03')));

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

// Granularity: how much of a date the text pinned down. A whole-month
// request frames the month in the grid views instead of landing on its 1st,
// which is also what keeps the toolbar label naming the month you asked for.
eq('gran: bare month', jumpGranularity('june'), 'month');
eq('gran: month prefix', jumpGranularity('jun'), 'month');
eq('gran: month + year', jumpGranularity('june 2027'), 'month');
eq('gran: month + year, comma and case', jumpGranularity('June, 2027'), 'month');
eq('gran: bare year', jumpGranularity('2027'), 'year');
eq('gran: month + day is a day', jumpGranularity('june 5'), 'day');
eq('gran: m/d is a day', jumpGranularity('8/15'), 'day');
eq('gran: ISO is a day', jumpGranularity('2027-06-15'), 'day');
eq('gran: weekday is a day', jumpGranularity('next tuesday'), 'day');
eq('gran: today is a day', jumpGranularity('today'), 'day');
eq('gran: gibberish is a day', jumpGranularity('fnord'), 'day');
eq('gran: empty is a day', jumpGranularity('   '), 'day');

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

console.log('--- quick create selection math ---');

// Range normalization is drag-direction agnostic, inclusive on both ends.
eq('quickcreate: normalize ordered', normalizeDayRange('2026-08-04', '2026-08-06'),
  { startKey: '2026-08-04', endKey: '2026-08-06', days: 3 });
eq('quickcreate: normalize reversed', normalizeDayRange('2026-08-06', '2026-08-04'),
  { startKey: '2026-08-04', endKey: '2026-08-06', days: 3 });
eq('quickcreate: normalize single day', normalizeDayRange('2026-08-04', '2026-08-04').days, 1);
eq('quickcreate: normalize across month', normalizeDayRange('2026-09-02', '2026-08-30'),
  { startKey: '2026-08-30', endKey: '2026-09-02', days: 4 });

// Single-day draft: default 1-hour timed event at 9am.
eq('quickcreate: single-day draft', dayRangeDraft('2026-08-04', '2026-08-04'),
  { start: localISO(2026, 8, 4, 9, 0), end: localISO(2026, 8, 4, 10, 0), allDay: false });

// Multi-day draft: all-day span with the exclusive end.
eq('quickcreate: multi-day draft', dayRangeDraft('2026-03-01', '2026-03-15'),
  { start: localISO(2026, 3, 1, 0, 0), end: localISO(2026, 3, 16, 0, 0), allDay: true });
assert('quickcreate: multi-day draft exclusive end crosses month',
  dayRangeDraft('2026-08-30', '2026-08-31').end === localISO(2026, 9, 1, 0, 0));

// Labels (built through fmtMonthShort so any runtime locale agrees).
const aug = fmtMonthShort(dateOfDayKey('2026-08-04'));
const mar = fmtMonthShort(dateOfDayKey('2026-03-01'));
const apr = fmtMonthShort(dateOfDayKey('2026-04-02'));
eq('quickcreate: single-day label', dayRangeLabel('2026-08-04', '2026-08-04'), aug + ' 4');
eq('quickcreate: same-month range label', dayRangeLabel('2026-03-01', '2026-03-15'), mar + ' 1 to 15');
eq('quickcreate: cross-month range label', dayRangeLabel('2026-03-28', '2026-04-02'),
  mar + ' 28 to ' + apr + ' 2');

// Timed labels honor the time format setting.
setTimeFormat('24');
eq('quickcreate: timed label 24h', timeRangeLabel('2026-08-04', 840, 930), '14:00 to 15:30');
setTimeFormat('12');
assert('quickcreate: timed label 12h', /2:00.*to.*3:30/.test(timeRangeLabel('2026-08-04', 840, 930)));

// Week-view create-drag mode: staying in the origin column is timed; crossing
// columns becomes an inclusive day range, direction agnostic; dragging back
// to the origin column reverts to timed.
eq('quickcreate: drag mode same column is timed', dragCreateMode('2026-08-03', '2026-08-03'), { mode: 'timed' });
eq('quickcreate: drag mode crossing right', dragCreateMode('2026-08-03', '2026-08-05'),
  { mode: 'days', startKey: '2026-08-03', endKey: '2026-08-05' });
eq('quickcreate: drag mode crossing left normalizes', dragCreateMode('2026-08-05', '2026-08-03'),
  { mode: 'days', startKey: '2026-08-03', endKey: '2026-08-05' });
eq('quickcreate: drag mode across month boundary', dragCreateMode('2026-09-02', '2026-08-30'),
  { mode: 'days', startKey: '2026-08-30', endKey: '2026-09-02' });

// All-day lane drafts are all-day at any length (exclusive end), and the
// multi-day dayRangeDraft agrees with them.
eq('quickcreate: allday lane single day draft', allDayRangeDraft('2026-08-04', '2026-08-04'),
  { start: localISO(2026, 8, 4, 0, 0), end: localISO(2026, 8, 5, 0, 0), allDay: true });
eq('quickcreate: allday lane range draft crosses month', allDayRangeDraft('2026-08-30', '2026-09-02'),
  { start: localISO(2026, 8, 30, 0, 0), end: localISO(2026, 9, 3, 0, 0), allDay: true });
eq('quickcreate: multi-day dayRangeDraft matches allDayRangeDraft',
  dayRangeDraft('2026-03-01', '2026-03-15'), allDayRangeDraft('2026-03-01', '2026-03-15'));

// Chip position clamps into the viewport with an 8px margin.
eq('quickcreate: chip position untouched inside', chipPosition(100, 100, 200, 40, 1000, 800),
  { x: 100, y: 100 });
eq('quickcreate: chip clamps right/bottom', chipPosition(950, 790, 200, 40, 1000, 800),
  { x: 792, y: 752 });
eq('quickcreate: chip clamps top/left', chipPosition(-20, -20, 200, 40, 1000, 800), { x: 8, y: 8 });

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

console.log('--- command palette: fuzzy matching ---');

assert('fuzzy: empty query matches everything', fuzzyScore('Go to today', '') === 0);
assert('fuzzy: no match returns -1', fuzzyScore('Go to today', 'zzz') === -1);
assert('fuzzy: substring matches', fuzzyScore('Show all calendars', 'calendars') > 0);
assert('fuzzy: case insensitive', fuzzyScore('Show all calendars', 'CALEND') > 0);
assert('fuzzy: initials match as subsequence', fuzzyScore('Month view', 'mv') > 0);
assert('fuzzy: spaces in query are ignored', fuzzyScore('Month view', 'm v') > 0);
// A contiguous name beats scattered letters, so typing more of what you mean
// never demotes it below an accidental subsequence hit.
assert('fuzzy: substring outranks subsequence',
  fuzzyScore('Keyboard shortcuts', 'shortcuts') > fuzzyScore('Show all calendars', 'shortcuts'));
assert('fuzzy: word start outranks mid-word',
  fuzzyScore('Go to today', 'today') > fuzzyScore('Yesterdays', 'today'));

const fuzzRanked = rankByFuzzy(
  [{ label: 'Settings' }, { label: 'Search all events' }, { label: 'Saved views' }],
  'sea',
  (c) => [c.label],
);
eq('fuzzy: best match ranks first', fuzzRanked[0].label, 'Search all events');
eq('fuzzy: non-matches are dropped',
  rankByFuzzy([{ label: 'Settings' }, { label: 'People' }], 'zzq', (c) => [c.label]).length, 0);
eq('fuzzy: ties keep input order',
  rankByFuzzy([{ label: 'aX' }, { label: 'aY' }], 'a', (c) => [c.label]).map((c) => c.label), ['aX', 'aY']);

// The FR's headline case: a name lives in the label and the synonym lives in
// the keywords, so no single field holds the whole query. Before token-wise
// matching this returned nothing and Enter fell through to quick add.
const palCmds = [
  { label: 'Ada Lovelace is away\u2026', keywords: 'gone out ooo vacation availability', group: 'People' },
  { label: 'Ada Lovelace is busy\u2026', keywords: 'unavailable availability', group: 'People' },
  { label: 'Show calendar: Personal', keywords: null, group: 'Calendars' },
  { label: 'Month view', keywords: null, group: 'Views' },
];
const palFields = (c) => [c.label, c.keywords, c.group];
eq('fuzzy: "<person> gone" finds the away command',
  rankByFuzzy(palCmds, 'ada gone', palFields).map((c) => c.label), ['Ada Lovelace is away\u2026']);
eq('fuzzy: a bare synonym lists the away command',
  rankByFuzzy(palCmds, 'gone', palFields)[0].label, 'Ada Lovelace is away\u2026');
eq('fuzzy: "<person> busy" picks busy over away',
  rankByFuzzy(palCmds, 'ada busy', palFields)[0].label, 'Ada Lovelace is busy\u2026');
assert('fuzzy: every token must match somewhere',
  rankByFuzzy(palCmds, 'ada zzzq', palFields).length === 0);
eq('fuzzy: a command name still outranks a token spread',
  rankByFuzzy(palCmds, 'month view', palFields)[0].label, 'Month view');

console.log('--- command palette: definitions ---');

// The palette names hotkey ids rather than restating what those keys do. If
// a hotkey is renamed or dropped, the palette entry must fail loudly here
// rather than silently going dead at runtime.
const hotkeyIds = new Set(HOTKEYS.map((h) => h.id));
assert('commands: every hotkey reference resolves',
  STATIC_COMMANDS.every((c) => !c.hotkey || hotkeyIds.has(c.hotkey)));
eq('commands: ids are unique', new Set(STATIC_COMMANDS.map((c) => c.id)).size, STATIC_COMMANDS.length);
assert('commands: every entry has a label and group',
  STATIC_COMMANDS.every((c) => typeof c.label === 'string' && c.label.length > 0 && COMMAND_GROUPS.includes(c.group)));
assert('commands: needs gates are known contexts',
  STATIC_COMMANDS.every((c) => c.needs === undefined || c.needs === 'occ' || c.needs === 'popover'));
assert('commands: prompting entries are labelled with an ellipsis',
  STATIC_COMMANDS.every((c) => !c.prompt || c.label.endsWith('\u2026')));
assert('commands: the palette itself has a hotkey', hotkeyIds.has('palette'));
// A modifier binding must say so, or the plain dispatch would silently
// reserve its bare key (a lone "k" doing nothing forever).
assert('hotkeys: mod entries declare a display override',
  HOTKEYS.filter((h) => h.mod).every((h) => Array.isArray(h.display) && h.display.length > 0));
assert('hotkeys: the palette binding is mod-gated',
  HOTKEYS.find((h) => h.id === 'palette').mod === true);
assert('commands: manage items are [route, label] pairs',
  MANAGE_ITEMS.length > 0 && MANAGE_ITEMS.every((r) => Array.isArray(r) && r.length === 2 && r.every((x) => typeof x === 'string')));
eq('commands: manage routes are unique', new Set(MANAGE_ITEMS.map((r) => r[0])).size, MANAGE_ITEMS.length);
assert('commands: every view id has a label',
  ['month', 'weeks3', 'weeks2', 'week', 'day', 'agenda'].every((v) => typeof VIEW_LABELS[v] === 'string'));
assert('commands: every static entry carries an icon',
  STATIC_COMMANDS.every((c) => typeof c.icon === 'string' && c.icon.length > 0));
assert('commands: every view id has an icon',
  ['month', 'weeks3', 'weeks2', 'week', 'day', 'agenda'].every((v) => typeof VIEW_ICONS[v] === 'string'));

console.log('--- time format setting ---');

const t1405 = new Date(2026, 0, 1, 14, 5);
setTimeFormat('24');
assert('24h format shows 14', fmtTime(t1405).includes('14'));
assert('24h format has no AM/PM', !/am|pm/i.test(fmtTime(t1405)));
setTimeFormat('12');
assert('12h format shows 2:05', fmtTime(t1405).includes('2:05'));

console.log('--- reminder helpers ---');

// Offset formatting picks the largest clean unit.
eq('reminder fmt 0', fmtOffsetMinutes(0), 'At start');
eq('reminder fmt minutes', fmtOffsetMinutes(10), '10 minutes before');
eq('reminder fmt hour', fmtOffsetMinutes(60), '1 hour before');
eq('reminder fmt 90 stays minutes', fmtOffsetMinutes(90), '90 minutes before');
eq('reminder fmt day', fmtOffsetMinutes(1440), '1 day before');
eq('reminder fmt week', fmtOffsetMinutes(10080), '1 week before');
eq('reminder fmt 2 weeks', fmtOffsetMinutes(20160), '2 weeks before');

// toMinutes/fromMinutes: unit conversion, clamping, clean-unit decomposition.
eq('toMinutes minutes passthrough', toMinutes(45, 'minutes'), 45);
eq('toMinutes hours', toMinutes(2, 'hours'), 120);
eq('toMinutes days', toMinutes(3, 'days'), 4320);
eq('toMinutes weeks', toMinutes(1, 'weeks'), 10080);
eq('toMinutes fractional rounds', toMinutes(1.5, 'hours'), 90);
eq('toMinutes clamps at 4 weeks', toMinutes(99, 'weeks'), MAX_REMINDER_MINUTES);
eq('toMinutes negative clamps to 0', toMinutes(-5, 'hours'), 0);
eq('toMinutes unknown unit treated as minutes', toMinutes(7, 'nope'), 7);
eq('fromMinutes week', fromMinutes(10080), { n: 1, unit: 'weeks' });
eq('fromMinutes days', fromMinutes(2880), { n: 2, unit: 'days' });
eq('fromMinutes hours', fromMinutes(120), { n: 2, unit: 'hours' });
eq('fromMinutes uneven stays minutes', fromMinutes(90), { n: 90, unit: 'minutes' });
eq('fromMinutes zero is minutes', fromMinutes(0), { n: 0, unit: 'minutes' });
eq('toMinutes/fromMinutes round trip', toMinutes(fromMinutes(4320).n, fromMinutes(4320).unit), 4320);
assert('reminder units well-formed', REMINDER_UNITS.length === 4 && REMINDER_UNITS.includes('weeks'));
assert('timed presets within server cap', TIMED_CHOICES.every((m) => m >= 0 && m <= MAX_REMINDER_MINUTES));
assert('timed presets include week', TIMED_CHOICES.includes(10080));
eq('reminder fmt entry minutes shape', fmtReminder({ minutes: 15 }), '15 minutes before');
eq('reminder fmt entry allday shape', fmtReminder({ daysBefore: 1, time: '18:00' }), 'Day before at 18:00');
eq('reminder fmt entry same day', fmtReminder({ daysBefore: 0, time: '09:00' }), 'Same day at 09:00');

// All-day default -> minutes before local midnight; same-day clamps to 0.
eq('reminder allday to minutes day before 18:00', allDayEntryToMinutes({ daysBefore: 1, time: '18:00' }), 360);
eq('reminder allday to minutes 2 days noon', allDayEntryToMinutes({ daysBefore: 2, time: '12:00' }), 2160);
eq('reminder allday same-day clamps', allDayEntryToMinutes({ daysBefore: 0, time: '09:00' }), 0);
eq('reminder entryToMinutes passthrough', entryToMinutes({ minutes: 30 }), 30);

// Normalization: unique + ascending {minutes} entries.
eq('reminder normalize', normalizeMinutesList([30, 5, 5, 0]),
  [{ minutes: 0 }, { minutes: 5 }, { minutes: 30 }]);

// Effective resolution mirrors the server: event > calendar > global, and
// subscribed calendars never inherit the global default.
const remSettings = { reminderTimed: [{ minutes: 10 }], reminderAllDay: [{ daysBefore: 1, time: '18:00' }] };
const remCalDef = { timed: [{ minutes: 30 }], allDay: [] };
eq('reminder effective event wins',
  effectiveReminders({ override: [{ minutes: 5 }], calendarDefaults: remCalDef, settings: remSettings, allDay: false }),
  { reminders: [{ minutes: 5 }], source: 'event' });
eq('reminder effective explicit none',
  effectiveReminders({ override: [], calendarDefaults: remCalDef, settings: remSettings, allDay: false }),
  { reminders: [], source: 'event' });
eq('reminder effective calendar over global',
  effectiveReminders({ override: null, calendarDefaults: remCalDef, settings: remSettings, allDay: false }),
  { reminders: [{ minutes: 30 }], source: 'calendar' });
eq('reminder effective global fallback allday',
  effectiveReminders({ override: null, calendarDefaults: null, settings: remSettings, allDay: true }),
  { reminders: [{ daysBefore: 1, time: '18:00' }], source: 'default' });
eq('reminder effective subscribed never inherits',
  effectiveReminders({ override: null, calendarDefaults: null, settings: remSettings, allDay: false, calendarKind: 'subscribed' }),
  { reminders: [], source: 'default' });

console.log('--- notification deep-link windows ---');

// Anchor resolution: &at= wins, instanceId compact timestamp is the fallback,
// garbage falls back to now.
const DL_NOW = Date.UTC(2026, 6, 31, 12, 0, 0);
eq('deeplink: at param wins',
  deepLinkAnchorMs('42:20260807T190000Z', '2026-09-01T10:00:00Z', DL_NOW),
  Date.UTC(2026, 8, 1, 10, 0, 0));
eq('deeplink: instanceId timestamp fallback',
  deepLinkAnchorMs('42:20260807T190000Z', null, DL_NOW),
  Date.UTC(2026, 7, 7, 19, 0, 0));
eq('deeplink: invalid at falls back to instanceId',
  deepLinkAnchorMs('42:20260807T190000Z', 'not-a-date', DL_NOW),
  Date.UTC(2026, 7, 7, 19, 0, 0));
eq('deeplink: opaque id falls back to now', deepLinkAnchorMs('whatever', null, DL_NOW), DL_NOW);
eq('deeplink: empty id falls back to now', deepLinkAnchorMs('', null, DL_NOW), DL_NOW);

// Broad window spans now AND the occurrence; tight window brackets the
// occurrence by 36h on each side.
{
  const farAt = '2026-08-28T07:00:00Z'; // 28 days out (max daysBefore lead)
  const w = deepLinkWindows('7:20260828T070000Z', farAt, DL_NOW);
  const atMs = Date.parse(farAt);
  assert('deeplink: broad window starts before now', parseISO(w.broad.start).getTime() < DL_NOW);
  assert('deeplink: broad window ends after far occurrence', parseISO(w.broad.end).getTime() > atMs);
  assert('deeplink: tight window brackets occurrence',
    parseISO(w.tight.start).getTime() === atMs - 1.5 * 86400000 &&
    parseISO(w.tight.end).getTime() === atMs + 1.5 * 86400000);
  assert('deeplink: window ISO strings carry offsets',
    [w.broad.start, w.broad.end, w.tight.start, w.tight.end].every((s) => /[+-]\d{2}:\d{2}$/.test(s)));
}
// Past occurrence (grace-period reminder clicked late): broad still spans both.
{
  const w = deepLinkWindows('9:20260730T090000Z', null, DL_NOW);
  assert('deeplink: past occurrence inside broad window',
    parseISO(w.broad.start).getTime() < Date.UTC(2026, 6, 30, 9, 0, 0) &&
    parseISO(w.broad.end).getTime() > DL_NOW);
}

console.log('--- time-relative state (timeState) ---');

// Reference instant: 2026-07-31 12:00 local time.
const NOW = new Date(2026, 6, 31, 12, 0).getTime();
const timedOcc = (s, e) => ({ start: s, end: e, allDay: false });
// All-day occurrences serialize literal dates at +00:00 with an exclusive end.
const alldayOcc = (startDay, endDay) => ({
  start: startDay + 'T00:00:00+00:00', end: endDay + 'T00:00:00+00:00', allDay: true,
});

// Timed: ended earlier today is past, running is now, later today is future.
eq('timeState: timed ended earlier today is past',
  timeState(timedOcc(localISO(2026, 7, 31, 9, 0), localISO(2026, 7, 31, 10, 0)), NOW), 'past');
eq('timeState: timed running is now',
  timeState(timedOcc(localISO(2026, 7, 31, 11, 30), localISO(2026, 7, 31, 12, 30)), NOW), 'now');
eq('timeState: timed later today is future',
  timeState(timedOcc(localISO(2026, 7, 31, 14, 0), localISO(2026, 7, 31, 15, 0)), NOW), 'future');

// Boundaries: exactly at start counts as now; exactly at end counts as past.
eq('timeState: boundary exactly at start is now',
  timeState(timedOcc(localISO(2026, 7, 31, 12, 0), localISO(2026, 7, 31, 13, 0)), NOW), 'now');
eq('timeState: boundary exactly at end is past',
  timeState(timedOcc(localISO(2026, 7, 31, 11, 0), localISO(2026, 7, 31, 12, 0)), NOW), 'past');

// All-day: date-anchored (literal dates, never timezone-converted).
eq('timeState: allday yesterday is past',
  timeState(alldayOcc('2026-07-30', '2026-07-31'), NOW), 'past');
eq('timeState: allday today is now',
  timeState(alldayOcc('2026-07-31', '2026-08-01'), NOW), 'now');
// Exclusive end: an end date of today means the last day was yesterday.
eq('timeState: allday exclusive end lands on today is past',
  timeState(alldayOcc('2026-07-29', '2026-07-31'), NOW), 'past');
eq('timeState: allday multi-day spanning today is now',
  timeState(alldayOcc('2026-07-29', '2026-08-03'), NOW), 'now');
eq('timeState: allday starting tomorrow is future',
  timeState(alldayOcc('2026-08-01', '2026-08-02'), NOW), 'future');

console.log('--- rich text helpers ---');

// hasHtml: markup detection, prose with angle brackets stays plain.
assert('richtext: hasHtml true for tags', hasHtml('<p>hi</p>'));
assert('richtext: hasHtml true for closing tag only', hasHtml('text</div>'));
assert('richtext: hasHtml false for prose', !hasHtml('a < b and b > c'));
assert('richtext: hasHtml false for heart', !hasHtml('I <3 calendars'));
assert('richtext: hasHtml false for non-string', !hasHtml(null) && !hasHtml(42));

// stripToText: blocks and brs become newlines, tags drop, entities decode.
eq('richtext: strip plain passthrough', stripToText('just text'), 'just text');
eq('richtext: strip blocks to newlines', stripToText('<div>one</div><div>two<br>three</div>'), 'one\ntwo\nthree');
eq('richtext: strip list items separate', stripToText('<ul><li>a</li><li>b</li></ul>'), 'a\nb');
eq('richtext: strip inline formatting seamless', stripToText('<b>bold</b> and <i>italic</i>'), 'bold and italic');
eq('richtext: strip decodes entities', stripToText('<p>a &amp; b &lt;ok&gt;</p>'), 'a & b <ok>');
eq('richtext: strip drops script bodies', stripToText('<script>alert(1)</script><p>fine</p>'), 'fine');
eq('richtext: strip empty input', stripToText(''), '');

// isEmptyHtml: squire's empty document shapes count as empty.
assert('richtext: empty div-br is empty', isEmptyHtml('<div><br></div>'));
assert('richtext: empty string is empty', isEmptyHtml(''));
assert('richtext: null is empty', isEmptyHtml(null));
assert('richtext: real content is not empty', !isEmptyHtml('<div>note</div>'));
assert('richtext: plain text is not empty', !isEmptyHtml('note'));

console.log('--- battery tip ---');

// Android-only guidance; the copy must name the settings path users follow.
assert('batterytip: applies on Android UA', batteryTipApplies('Mozilla/5.0 (Linux; Android 15; Pixel 9) Chrome/126'));
assert('batterytip: not on iPhone', !batteryTipApplies('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)'));
assert('batterytip: not on desktop', !batteryTipApplies('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5)'));
assert('batterytip: empty UA no-op', !batteryTipApplies(''));
assert('batterytip: copy names Unrestricted path', BATTERY_TIP_BODY.includes('Battery') && BATTERY_TIP_BODY.includes('Unrestricted'));
assert('batterytip: title mentions Android', BATTERY_TIP_TITLE.includes('Android'));

console.log('--- trips (container events) ---');

// A 12-day all-day trip: Jun 1 to Jun 12 with the iCal exclusive end Jun 13.
const trip = {
  eventId: 1, instanceId: 't1', isContainer: true, allDay: true,
  start: '2026-06-01T00:00:00+00:00', end: '2026-06-13T00:00:00+00:00',
  title: 'Hawaii Trip',
};

// Inclusive span honors the all-day exclusive end date.
eq('tripSpan all-day exclusive end', tripSpan(trip),
  { startKey: '2026-06-01', endKey: '2026-06-12', days: 12 });
eq('tripSpanLabel', tripSpanLabel(trip), 'Jun 1 to 12, 12 days');
const oneDayTrip = {
  eventId: 9, isContainer: true, allDay: true,
  start: '2026-06-01T00:00:00+00:00', end: '2026-06-02T00:00:00+00:00',
};
eq('tripSpanLabel single day', tripSpanLabel(oneDayTrip), 'Jun 1, 1 day');

// Band segmentation is exactly the shared row segmentation (bars and bands
// always agree on row boundaries), for month and ribbon column counts.
eq('tripBandSegments matches rowSpanSegments (7 cols)',
  tripBandSegments(trip, 7), rowSpanSegments('2026-06-01', '2026-06-12', 7));
eq('tripBandSegments matches rowSpanSegments (3-col ribbon)',
  tripBandSegments(trip, 3), rowSpanSegments('2026-06-01', '2026-06-12', 3));

// Overlap/abut with the +/-1 day slack of the candidate filter.
assert('spans overlap', spansOverlapOrAbut('2026-06-01', '2026-06-12', '2026-06-05', '2026-06-05'));
assert('spans abut the day before', spansOverlapOrAbut('2026-06-01', '2026-06-12', '2026-05-31', '2026-05-31'));
assert('spans abut the day after', spansOverlapOrAbut('2026-06-01', '2026-06-12', '2026-06-13', '2026-06-13'));
assert('spans beyond the slack do not abut', !spansOverlapOrAbut('2026-06-01', '2026-06-12', '2026-06-15', '2026-06-16'));

// Candidate trips for an event: overlapping/abutting containers only,
// deduped by eventId; the event itself and far-away trips never qualify.
const flightOut = { eventId: 2, allDay: false, start: '2026-05-31T08:00:00-07:00', end: '2026-05-31T11:00:00-07:00' };
const farTrip = {
  eventId: 3, instanceId: 't3', isContainer: true, allDay: true,
  start: '2026-07-01T00:00:00+00:00', end: '2026-07-05T00:00:00+00:00',
};
eq('candidateTrips abutting flight finds the trip once',
  candidateTrips(flightOut, [trip, farTrip, flightOut, { ...trip, instanceId: 't1b' }]).map((c) => c.eventId),
  [1]);

// attachableInSpan: overlapping non-containers only; outside, hidden and
// container occurrences drop out.
const inside = { eventId: 4, allDay: false, start: '2026-06-03T09:00:00-07:00', end: '2026-06-03T10:00:00-07:00' };
const outside = { eventId: 5, allDay: false, start: '2026-06-20T09:00:00-07:00', end: '2026-06-20T10:00:00-07:00' };
const hiddenOcc = { eventId: 6, attendance: 'hidden', allDay: false, start: '2026-06-04T09:00:00-07:00', end: '2026-06-04T10:00:00-07:00' };
eq('attachableInSpan filters to overlapping non-containers',
  attachableInSpan(trip, [inside, outside, hiddenOcc, farTrip, trip]).map((o) => o.eventId),
  [4]);

// Span extension: a member inside the span changes nothing.
eq('extendTripSpan no-op inside the span', extendTripSpan(trip, inside), null);
// A member after the end grows the exclusive all-day end to cover it.
const lateEvent = { eventId: 7, allDay: false, start: '2026-06-14T09:00:00-07:00', end: '2026-06-14T10:00:00-07:00' };
const grown = extendTripSpan(trip, lateEvent);
assert('extendTripSpan keeps the start', grown.start.slice(0, 10) === '2026-06-01');
assert('extendTripSpan exclusive end covers the late member', grown.end.slice(0, 10) === '2026-06-15');
// A member before the start moves the start day.
const earlyEvent = { eventId: 8, allDay: true, start: '2026-05-30T00:00:00+00:00', end: '2026-05-31T00:00:00+00:00' };
eq('extendTripSpan earlier start', extendTripSpan(trip, earlyEvent).start.slice(0, 10), '2026-05-30');
// Timed trips keep their times of day on the new boundary days.
const timedTrip = {
  eventId: 10, isContainer: true, allDay: false,
  start: '2026-06-01T10:00:00-07:00', end: '2026-06-02T18:00:00-07:00',
};
const timedGrown = extendTripSpan(timedTrip, lateEvent);
assert('extendTripSpan timed keeps the end time of day',
  timedGrown.end.slice(0, 10) === '2026-06-14' && timedGrown.end.slice(11, 16) === '18:00');
assert('extendTripSpan timed keeps the start time of day', timedGrown.start.slice(11, 16) === '10:00');

console.log('--- agenda multi-day rails ---');

// Fixtures: an all-day trip Jun 1-4 (exclusive iCal end), a timed two-day
// event Jun 2-3, a single all-day and a single timed event.
const railTrip = {
  instanceId: 'rt1', calendarId: 'c1', title: 'Tahoe', isContainer: true,
  allDay: true, start: '2026-06-01T00:00:00+00:00', end: '2026-06-05T00:00:00+00:00',
};
const railTimed = {
  instanceId: 'rm1', calendarId: 'c2', title: 'Offsite',
  allDay: false, start: '2026-06-02T09:00:00-07:00', end: '2026-06-03T17:00:00-07:00',
};
const railSingle = {
  instanceId: 'rs1', calendarId: 'c1', title: 'Dentist',
  allDay: false, start: '2026-06-02T11:00:00-07:00', end: '2026-06-02T12:00:00-07:00',
};
const railAllDaySingle = {
  instanceId: 'ra1', calendarId: 'c1', title: 'Holiday',
  allDay: true, start: '2026-06-02T00:00:00+00:00', end: '2026-06-03T00:00:00+00:00',
};
const railHidden = {
  instanceId: 'rh1', calendarId: 'c1', title: 'Hidden span', attendance: 'hidden',
  allDay: true, start: '2026-06-01T00:00:00+00:00', end: '2026-06-05T00:00:00+00:00',
};

const railGroups = buildAgendaGroups([railTrip, railTimed, railSingle, railAllDaySingle, railHidden]);

// Boundary days synthesize groups; intermediate empty days (Jun 4 is the
// trip's end so it exists, but a pure middle day like Jun 3 exists here only
// because the timed event ends there — drop it from the check below).
eq('buildAgendaGroups group day keys',
  railGroups.map((g) => g.dayKey),
  ['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04']);

// Synthesized start day: header plus the single start row.
eq('start day rows', railGroups[0].rows.map((r) => r.kind + ':' + r.occ.instanceId), ['start:rt1']);

// Starts and normals merge chronologically (all-day first, then by start
// time); end markers sort last in their group.
eq('mixed day row order',
  railGroups[1].rows.map((r) => r.kind + ':' + r.occ.instanceId),
  ['normal:ra1', 'start:rm1', 'normal:rs1']);
eq('end marker day rows', railGroups[3].rows.map((r) => r.kind + ':' + r.occ.instanceId), ['end:rt1']);

// End markers sort after the final day's normal events (the span bounds its
// last day) and the rail extends down past them to the end pill.
const endDayMix = buildAgendaGroups([railTrip, {
  instanceId: 'rx1', calendarId: 'c1', title: 'Flight home',
  allDay: false, start: '2026-06-04T11:00:00-07:00', end: '2026-06-04T12:00:00-07:00',
}]);
eq('end day: marker sorts last',
  endDayMix[endDayMix.length - 1].rows.map((r) => r.kind + ':' + r.occ.instanceId),
  ['normal:rx1', 'end:rt1']);
// Start pill top 40+8 = 48; end group top 76, end row is index 1, its pill
// bottom 76+40+36+28 = 180; height 180-48 = 132.
eq('end day: rail reaches below the day events', railRanges(endDayMix)[0].heightPx, 132);

// Hidden occurrences never produce rows or synthesized groups.
assert('hidden multi-day excluded', !railGroups.some((g) => g.rows.some((r) => r.occ.instanceId === 'rh1')));

// Tops/heights stack (headH 40 + rowH 36 per row).
eq('group heights', railGroups.map((g) => g.height), [76, 148, 76, 76]);
eq('group tops', railGroups.map((g) => g.top), [0, 76, 224, 300]);

// A two-day event gets a start row on day 1 and an end marker on day 2.
const twoDay = buildAgendaGroups([railTimed]);
eq('two-day start/end split',
  twoDay.map((g) => g.rows.map((r) => r.kind).join(',')),
  ['start', 'end']);
// Adjacent boundary days still yield a positive-height connected bar: start
// pill top 0+40+8 = 48 down to end pill bottom 76+40+28 = 144.
eq('two-day rail spans row centers',
  railRanges(twoDay).map((r) => [r.topPx, r.heightPx]), [[48, 96]]);

// Month separators are part of the layout, not an overlay drawn on top of
// the previous day's rows: the group that opens a month is taller by the
// separator band and everything after it shifts down by the same amount.
{
  const ev = (id, start, end) => ({
    instanceId: id, calendarId: 1, title: id, start, end, allDay: false,
  });
  const across = buildAgendaGroups([
    ev('jul', '2026-07-31T10:00:00-07:00', '2026-07-31T11:00:00-07:00'),
    ev('aug', '2026-08-01T10:00:00-07:00', '2026-08-01T11:00:00-07:00'),
    ev('aug2', '2026-08-02T10:00:00-07:00', '2026-08-02T11:00:00-07:00'),
  ]);
  eq('month start flagged only on the first group of a month',
    across.map((g) => !!g.monthStart), [false, true, false]);
  // 40 head + 36 row = 76 per plain group; the August 1 group adds the 30px band.
  eq('month separator height is in the layout',
    across.map((g) => [g.top, g.height]), [[0, 76], [76, 106], [182, 76]]);

  // A span crossing the boundary keeps its rail aligned to the shifted rows.
  const spanning = buildAgendaGroups([
    { instanceId: 's', calendarId: 1, title: 's', allDay: true,
      start: '2026-07-31T00:00:00+00:00', end: '2026-08-02T00:00:00+00:00' },
  ]);
  const rail = railRanges(spanning)[0];
  // End group starts at 76 and opens August, so its row sits 30px lower.
  eq('rail end clears the separator band', Math.round(rail.topPx + rail.heightPx), 76 + 30 + 40 + 28);
}

// Single-day events never produce end markers.
assert('single-day has no markers',
  buildAgendaGroups([railSingle]).every((g) => g.rows.every((r) => r.kind === 'normal')));

// Day count / label helpers.
eq('spanDayCount trip', spanDayCount(railTrip), 4);
eq('spanDayCount single', spanDayCount(railSingle), 1);
eq('dayOfSpanLabel start', dayOfSpanLabel(railTrip, '2026-06-01'), 'Day 1/4');
eq('dayOfSpanLabel end', dayOfSpanLabel(railTrip, '2026-06-04'), 'Day 4/4');

// Rails: pixel span from the start row's vertical center to the end row's
// vertical center (both ends terminate behind their pills, so bar and pills
// physically connect), lanes packed for overlaps, calendar color resolved
// via colorOf.
const railsOut = railRanges(railGroups, { colorOf: (o) => (o.calendarId === 'c1' ? '#a00' : '#0a0') });
eq('rail count', railsOut.length, 2);
const tripRail = railsOut.find((r) => r.instanceId === 'rt1');
const timedRail = railsOut.find((r) => r.instanceId === 'rm1');
eq('trip rail top', tripRail.topPx, 48); // group 0 top 0 + headH 40 + pill inset 8 (top of the start pill)
eq('trip rail height', tripRail.heightPx, 320); // to 368, bottom of the Jun 4 end pill (300+40+28)
eq('timed rail span', [timedRail.topPx, timedRail.heightPx], [160, 132]); // top of the Jun 2 row-1 pill (76+40+36+8) to bottom of the Jun 3 end pill (224+40+28)
eq('overlapping rails get distinct lanes', [tripRail.lane, timedRail.lane], [0, 1]);
assert('rail is-trip flag', tripRail.isTrip === true && timedRail.isTrip === false);
eq('rail colors', [tripRail.color, timedRail.color], ['#a00', '#0a0']);

// Lane overflow: a fourth overlapping span keeps its rows but gets no rail.
const stacked = ['a', 'b', 'c', 'd'].map((id) => ({
  instanceId: id, calendarId: 'c1', title: id, allDay: true,
  start: '2026-06-01T00:00:00+00:00', end: '2026-06-05T00:00:00+00:00',
}));
const stackedGroups = buildAgendaGroups(stacked);
eq('lane overflow drops the 4th rail', railRanges(stackedGroups).length, 3);
assert('overflow keeps start/end rows',
  stackedGroups[0].rows.length === 4 && stackedGroups[1].rows.length === 4);

// Non-overlapping spans reuse lane 0.
const sequential = railRanges(buildAgendaGroups([
  { instanceId: 'q1', calendarId: 'c1', title: 'A', allDay: true, start: '2026-06-01T00:00:00+00:00', end: '2026-06-03T00:00:00+00:00' },
  { instanceId: 'q2', calendarId: 'c1', title: 'B', allDay: true, start: '2026-06-10T00:00:00+00:00', end: '2026-06-12T00:00:00+00:00' },
]));
eq('sequential rails share lane 0', sequential.map((r) => r.lane), [0, 0]);

// Washes are bounded by each span's own pills, not by whole days, so two
// overlapping spans produce three visible tones (first alone, both, second
// alone). Rails cap at MAX_RAIL_LANES; washes never do.
const wash = washRects(railGroups);
eq('one wash per span', wash.length, railRanges(railGroups).length);
eq('wash matches its rail geometry',
  wash.map((w) => [w.topPx, w.heightPx]).sort((a, b) => a[0] - b[0]),
  railRanges(railGroups).map((r) => [r.topPx, r.heightPx]).sort((a, b) => a[0] - b[0]));
assert('washes are ordered longest first',
  washRects(railGroups).every((w, i, a) => i === 0 || a[i - 1].heightPx >= w.heightPx));
eq('every overlapping span gets a wash even past the rail lane cap',
  washRects(stackedGroups).length, 4);
assert('rails still cap at three lanes', railRanges(stackedGroups).length === 3);
eq('no washes without spans', washRects(buildAgendaGroups([railSingle])).length, 0);

console.log('--- color utils ---');

// 37. Contrast text flips between light and dark backgrounds.
assert('contrast on dark bg is white', contrastText('#1c2a4a') === '#ffffff');
assert('contrast on light bg is dark', contrastText('#f2e9c9') === '#1a1a1a');
eq('withAlpha', withAlpha('#ff0000', 0.5), 'rgba(255,0,0,0.5)');
assert('parseHex shorthand', JSON.stringify(parseHex('#abc')) === JSON.stringify({ r: 170, g: 187, b: 204 }));

console.log('--- chronological ordering across timezone offsets ---');

// Occurrence start strings carry each event's OWN offset, so an imported
// UTC-tzid event ("+00:00") and a local one ("-07:00") cannot be compared as
// strings: "17:00:00+00:00" sorts after "12:30:00-07:00" while actually
// being two and a half hours earlier. This is what put a 10 AM event at the
// bottom of the day-expand list.
{
  const utcTen = { start: '2026-08-18T17:00:00+00:00', title: 'Aquarium' };     // 10:00 local
  const localNoon = { start: '2026-08-18T12:30:00-07:00', title: 'Cleaners' };  // 12:30 local
  const localTwo = { start: '2026-08-18T14:00:00-07:00', title: 'Weeklies' };   // 14:00 local
  assert('naive string compare gets it wrong', utcTen.start > localNoon.start);
  assert('startMs orders by instant', startMs(utcTen) < startMs(localNoon));
  eq(
    'byStart sorts mixed offsets chronologically',
    [localTwo, localNoon, utcTen].sort(byStart).map((o) => o.title).join(','),
    'Aquarium,Cleaners,Weeklies',
  );
}

// --- window gap arithmetic (GH #14) ----------------------------------------
// Scrolling asks for a window re-centred on the viewport, so each demand
// overlaps the last by most of its width. Fetching only the remainder is what
// stops a fling queueing a dozen multi-thousand-occurrence responses, so the
// subtraction has to be exactly right: too little and events silently never
// load, too much and the overlap problem comes straight back.
{
  const r = (start, end) => ({ start, end });
  const fmt = (gaps) => gaps.map((g) => g.start + '-' + g.end).join(',');

  eq('gaps: nothing held yet returns the whole span', fmt(missingRanges(0, 100, [])), '0-100');
  eq('gaps: fully covered returns nothing', fmt(missingRanges(10, 90, [r(0, 100)])), '');
  eq('gaps: exactly covered returns nothing', fmt(missingRanges(0, 100, [r(0, 100)])), '');
  eq('gaps: overlap on the left', fmt(missingRanges(50, 150, [r(0, 100)])), '100-150');
  eq('gaps: overlap on the right', fmt(missingRanges(0, 100, [r(50, 200)])), '0-50');
  eq('gaps: hole in the middle', fmt(missingRanges(0, 100, [r(0, 30), r(70, 100)])), '30-70');
  eq('gaps: two holes', fmt(missingRanges(0, 100, [r(20, 40), r(60, 80)])), '0-20,40-60,80-100');
  eq('gaps: unsorted input still works', fmt(missingRanges(0, 100, [r(60, 80), r(20, 40)])), '0-20,40-60,80-100');
  eq('gaps: ranges outside the ask are ignored', fmt(missingRanges(50, 60, [r(0, 10), r(90, 100)])), '50-60');
  eq('gaps: touching ranges leave no sliver', fmt(missingRanges(0, 100, [r(0, 50), r(50, 100)])), '');
  eq('gaps: nested ranges collapse', fmt(missingRanges(0, 100, [r(0, 80), r(20, 40)])), '80-100');
  eq('gaps: empty span asks for nothing', fmt(missingRanges(50, 50, [])), '');
  eq('gaps: inverted span asks for nothing', fmt(missingRanges(80, 20, [])), '');

  // The case from the bug: a five-month window stepped by one month should
  // ask for one month, not five.
  const MONTH = 30 * 86400000;
  const held = [r(0, 5 * MONTH)];
  const stepped = missingRanges(MONTH, 6 * MONTH, held);
  eq('gaps: stepping a 5-month window asks for 1 month', stepped.length, 1);
  assert('gaps: and that month is the new tail',
    stepped[0].start === 5 * MONTH && stepped[0].end === 6 * MONTH);
}

console.log('');
console.log(passed + ' passed, ' + failed + ' failed');
if (failed > 0) process.exit(1);
