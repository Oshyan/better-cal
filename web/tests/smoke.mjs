// Static smoke test for pure logic: date utils, overlap layout, month
// virtualization math. Run: node web/tests/smoke.mjs

import './copy-to.mjs';
import './drafts.mjs';
import './credentials.mjs';
import './mail.mjs';
import './resources.mjs';
import {
  parseISO, toISOWithOffset, dayKeyOf, dateOfDayKey, epochDayOfKey,
  keyOfEpochDay, addDaysKey, diffDaysKey, weekIndexOfKey, firstEpochDayOfWeek,
  dayKeysOfWeek, startOfWeekKey, setWeekStart, getWeekStart, setTimeFormat, fmtTime,
  fmtMonthShort, timeState, startMs, byStart, eventDuration, allDayFields, allDayInputs, untilDayKey,
  fmtRange, fmtRangeParts, otherYear,
} from '../src/lib/dates.js';
import { layoutOverlaps, assignLanes, rangesOverlap } from '../src/ui/layout.js';
import {
  visibleWeekRange, weekTop, totalHeight, segmentSpan, occurrenceDaySpan,
  monthStartsInRange, dominantMonthOfWeek, isMultiDay, isOvernight,
  monthsAround, miniMonthGrid, dayDropDates, timeDropDates, shiftOccurrenceDays, resizeOccurrenceDays,
  rowSpanSegments, rowIndexOfEpochDay, rowIndexOfDayKey, firstEpochDayOfRow,
  dayKeysOfRow, isWeekendEpochDay, dominantMonthOfRow, dominantMonthOfRows,
} from '../src/ui/monthmath.js';
import { contrastText, withAlpha, parseHex, inkColor, luminance } from '../src/lib/color.js';
import { mapMosaic, stadiaStyle, mapTilerStyle, isPendingLocation, gmapsUrl, validCoordinates } from '../src/lib/maps.js';
import { safeWebUrl, meetingLinkInText } from '../src/lib/urls.js';
import { baseTitle, groupOccurrences, itemMatchesFilter, isGroupId } from '../src/ui/grouping.js';
import { collapseDuplicates } from '../src/ui/duplicates.js';
import { dayStepList, hidesCancelled } from '../src/ui/daysteps.js';
import { sortByMatch } from '../src/lib/rank.js';
import { zoneIndex, filterZones, parseOffset } from '../src/lib/zonesearch.js';
import { parseJumpText, jumpGranularity, parseJumpTarget } from '../src/lib/jumpparse.js';
import { activeMarker, markerSuggestions, applyMarkerPick, calendarMarker, personMarker, SUGGEST_MAX } from '../src/lib/markersuggest.js';
import { pastedText } from '../src/lib/pastetext.js';
import { sidebarGroups, moveGroup } from '../src/lib/sidebarorder.js';
import { timeChange, editedWords, listWords } from '../src/lib/changeline.js';
import { feedProblem, whenText } from '../src/lib/feedproblem.js';
import { bandDays, isBand, canReschedule } from '../src/lib/bands.js';
import { hereKeyOf } from '../src/lib/viewpos.js';
import { fmtDateShort as fmtDS, fmtDateShortYear as fmtDSY } from '../src/lib/dates.js';
import { parseClockText, resolveClock, minsToHHMM, hhmmToMins, parseDateText, durationLabel } from '../src/lib/whenparse.js';
import { monthWeeks, stepMonthOf } from '../src/lib/minimonth.js';
import { buildRange } from '../src/lib/quickrange.js';
import { clampSidebarWidth, readSidebarWidth, saveSidebarWidth } from '../src/lib/sidebarwidth.js';
import { toISOWithOffset as isoAt } from '../src/lib/dates.js';
import { state as storeState, missingRanges, pruneOccurrenceCache, invalidateRecords, mergeWindow, insertOccurrence, markStaleOutside, freshRanges, patchOccurrence, removeOccurrencesOfEvent } from '../src/app/store.js';
import { formattingReasons } from '../src/lib/eventwhy.js';
import {
  normalizeDayRange, dayRangeDraft, dayRangeLabel, timeRangeLabel, chipPosition,
  dragCreateMode, allDayRangeDraft,
} from '../src/lib/quickcreate.js';
import { HOTKEYS, HOTKEY_GROUPS } from '../src/app/hotkeys.js';
import {
  describeRepeat, repeatSentence, repeatPresets, presetOf, ruleBase, joinRule, ruleParts,
  withRepeatEnd, repeatEnd, retypeUntil, describable, weekOfMonth,
} from '../src/lib/repeat.js';
import { hiddenDayCounts, relShown, showRelFromConfig } from '../src/lib/relfilter.js';
import { contextToken, contextTitle, compactTime, contextByDay, withoutContext, dayLabelsByDay, isDayLabel, dayLabelText } from '../src/lib/context.js';
import {
  fmtOffsetMinutes, fmtReminder, allDayEntryToMinutes, entryToMinutes,
  normalizeMinutesList, effectiveReminders, toMinutes, fromMinutes,
  TIMED_CHOICES, MAX_REMINDER_MINUTES, REMINDER_UNITS,
  summarizeDefaults,
} from '../src/lib/reminders.js';
import { deepLinkAnchorMs, deepLinkWindows } from '../src/lib/deeplink.js';
import {
  tripSpan, tripSpanLabel, tripBandSegments, spansOverlapOrAbut,
  candidateTrips, attachableInSpan, extendTripSpan,
} from '../src/ui/trips.js';
import {
  buildAgendaGroups, railRanges, washRects, spanDayCount, dayOfSpanLabel,
} from '../src/ui/agendarails.js';
import { hasHtml, stripToText, isEmptyHtml, splitUrlTail, safeLinkMatcher } from '../src/lib/richtext.js';
import { batteryTipApplies, BATTERY_TIP_BODY, BATTERY_TIP_TITLE } from '../src/lib/batterytip.js';
import { fuzzyScore, rankByFuzzy } from '../src/lib/fuzzy.js';
import { STATIC_COMMANDS, COMMAND_GROUPS, MANAGE_ITEMS, VIEW_LABELS, VIEW_ICONS } from '../src/app/commanddefs.js';
import { normalizeShareParams } from '../src/app/handoff.js';
import { api as requestApi, fetchMe as fetchApiMe, login as apiLogin, logout as apiLogout } from '../src/app/api.js';

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

console.log('--- multi-day duration ---');
const durationOcc = (days, start = '2026-09-01') => ({
  title: 'Trip', allDay: true, start: start + 'T00:00:00+00:00',
  end: addDaysKey(start, days) + 'T00:00:00+00:00',
});
for (const [days, compact] of [[2, '2d'], [6, '6d'], [7, '1w'], [10, '10d'],
  [14, '2w'], [21, '3w'], [25, '25d'], [27, '27d'], [28, '1mo'],
  [29, '1mo'], [30, '1mo'], [31, '1mo'], [32, '32d'], [35, '5w'], [56, '8w'], [60, '60d']]) {
  eq(`duration ${days} days`, eventDuration(durationOcc(days)), { compact, exact: `${days} days` });
}
for (const start of ['2026-03-07', '2026-10-31', '2028-02-28', '2026-12-31']) {
  eq(`all-day duration ignores DST and month/year boundaries: ${start}`,
    eventDuration(durationOcc(3, start)), { compact: '3d', exact: '3 days' });
}
eq('all-day duration reads literal dates despite opposing offsets', eventDuration({
  allDay: true, start: '2026-09-01T00:00:00+14:00', end: '2026-09-04T00:00:00-12:00',
}), { compact: '3d', exact: '3 days' });
for (const occ of [null, {}, durationOcc(0), durationOcc(1), durationOcc(-2),
  { ...durationOcc(3), isGroup: true }, { ...durationOcc(3), end: null },
  { ...durationOcc(3), start: 'invalid' }, { ...durationOcc(3), start: '2026-02-30' },
  { start: 'invalid', end: '2026-09-04T00:00:00Z' },
  { start: '2026-09-01T22:00:00Z', end: '2026-09-02T02:00:00Z' },
  { start: '2026-03-07T12:00:00-08:00', end: '2026-03-08T12:00:00-07:00' }]) {
  eq('short, invalid or synthetic occurrence has no duration suffix', eventDuration(occ), null);
}
eq('timed duration preserves hours/minutes/seconds', eventDuration({
  start: '2026-09-01T12:00:00Z', end: '2026-09-03T18:30:15Z',
}), { compact: '2d 6h 30m 15s', exact: '2 days, 6 hours, 30 minutes, 15 seconds' });
eq('timed exact week', eventDuration({ start: '2026-09-01T12:00:00Z', end: '2026-09-08T12:00:00Z' }),
  { compact: '1w', exact: '7 days' });
eq('timed DST fall-back counts the extra elapsed hour', eventDuration({
  start: '2026-10-31T12:00:00-07:00', end: '2026-11-01T12:00:00-08:00',
}), { compact: '1d 1h', exact: '1 day, 1 hour' });
eq('timed timezone changes compare instants', eventDuration({
  start: '2026-09-01T12:00:00+02:00', end: '2026-09-02T12:00:00-07:00',
}), { compact: '1d 9h', exact: '1 day, 9 hours' });
const recurringTrip = { ...durationOcc(21), recurring: true, rrule: 'FREQ=YEARLY' };
eq('recurrence measures this occurrence only', eventDuration(recurringTrip).compact, '3w');
eq('edited recurrence exception uses its own end', eventDuration({ ...recurringTrip, end: '2026-09-11' }).compact, '10d');
eq('timed duration starts at exactly 24 hours', eventDuration({
  start: '2026-09-01T12:00:00Z', end: '2026-09-02T12:00:00Z',
}), { compact: '1d', exact: '1 day' });
eq('timed partial month retains its hours', eventDuration({
  start: '2026-09-01T12:00:00Z', end: '2026-09-29T18:00:00Z',
}), { compact: '28d 6h', exact: '28 days, 6 hours' });
assert('timed trip details use elapsed duration rather than touched dates', tripSpanLabel({
  start: '2026-09-01T12:00:00Z', end: '2026-09-03T18:00:00Z',
}).endsWith(', 2 days, 6 hours'));

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
// The column of the 1st places the stepped line where the months meet.
assert('monthStartsInRange: col is the column holding the 1st',
  labels.length > 0 && labels.every((l) => keyOfEpochDay(firstEpochDayOfRow(l.weekIndex, 7) + l.col).endsWith('-01')));

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
assert('3col: col is the column holding the 1st',
  labels3.length > 0 && labels3.every((l) => keyOfEpochDay(firstEpochDayOfRow(l.weekIndex, 3) + l.col).endsWith('-01')));

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

// All-day moves are date arithmetic and go out as bare dates. They used to be
// pushed through a local Date: from Los Angeles a move to Aug 12 was sent as
// "2026-08-11T17:00:00-07:00", which the server read as Aug 11. Identical in
// every zone is the point, so this runs under the deploy's whole zone matrix.
const occAllDay = { start: '2026-08-03T00:00:00+00:00', end: '2026-08-04T00:00:00+00:00', allDay: true };
eq('dayDropDates all-day sends bare dates, exclusive end', dayDropDates(occAllDay, '2026-08-12'),
  { newStart: '2026-08-12', newEnd: '2026-08-13', delta: 9 });
const occAllDayMulti = { start: '2026-08-03T00:00:00+00:00', end: '2026-08-07T00:00:00+00:00', allDay: true };
eq('shiftOccurrenceDays all-day multi-day keeps its length', shiftOccurrenceDays(occAllDayMulti, -5),
  { newStart: '2026-07-29', newEnd: '2026-08-02' });
eq('shiftOccurrenceDays all-day across a DST change (US, Nov 1)', shiftOccurrenceDays(
  { start: '2026-10-30T00:00:00+00:00', end: '2026-10-31T00:00:00+00:00', allDay: true }, 3),
  { newStart: '2026-11-02', newEnd: '2026-11-03' });
eq('shiftOccurrenceDays all-day across a year boundary', shiftOccurrenceDays(
  { start: '2026-12-31T00:00:00+00:00', end: '2027-01-01T00:00:00+00:00', allDay: true }, 1),
  { newStart: '2027-01-01', newEnd: '2027-01-02' });
eq('shiftOccurrenceDays timed keeps the local time of day', shiftOccurrenceDays(occTimed, 9),
  { newStart: localISO(2026, 8, 12, 14, 30), newEnd: localISO(2026, 8, 12, 16, 0) });
// What is sent must read back as the same day: the bare date, parsed the way
// occurrenceDaySpan parses a server all-day string, is the target day.
eq('all-day bare date round-trips through occurrenceDaySpan',
  occurrenceDaySpan({ allDay: true, start: '2026-08-12', end: '2026-08-13' }),
  { startKey: '2026-08-12', endKey: '2026-08-12' });

// timeDropDates snaps the pointer minute to 15 and keeps the duration.
eq('timeDropDates snaps to 15', timeDropDates(occTimed, '2026-08-12', 611), // 10:11 -> 10:15
  { newStart: localISO(2026, 8, 12, 10, 15), newEnd: localISO(2026, 8, 12, 11, 45) });

// timeDropDates keeps the start inside the target day; the end may run past
// midnight (#183: an overnight event dropped at 10 PM stays at 10 PM).
eq('timeDropDates keeps the start in the day', timeDropDates(occTimed, '2026-08-12', 1435),
  { newStart: localISO(2026, 8, 12, 23, 45), newEnd: localISO(2026, 8, 13, 1, 15) });
eq('timeDropDates leaves an overnight event where it was dropped',
  timeDropDates({ allDay: false, start: localISO(2026, 8, 12, 22, 0), end: localISO(2026, 8, 13, 1, 0) }, '2026-08-12', 1320),
  { newStart: localISO(2026, 8, 12, 22, 0), newEnd: localISO(2026, 8, 13, 1, 0) });

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

const boundedItems = layoutOverlaps(Array.from({ length: 12 }, (_, i) => ({
  id: `item-${i}`, startMin: i * 60, endMin: i * 60 + 30,
})), 30, { maxItems: 10, maxColumns: 4 });
eq('overlap layout: exact item cap is rendered and the remainder counted', [boundedItems.length, boundedItems.overflow], [10, 2]);
const boundedColumns = layoutOverlaps(Array.from({ length: 8 }, (_, i) => ({
  id: `overlap-${i}`, startMin: 60, endMin: 180,
})), 30, { maxItems: 20, maxColumns: 3 });
eq('overlap layout: column cap bounds lane state and counts hidden events', [boundedColumns.length, boundedColumns.overflow, Math.max(...boundedColumns.map((e) => e.cols))], [3, 5, 3]);

// 35. Lane assignment packs bars greedily.
const lanes = assignLanes([
  { id: 'a', startCol: 0, endCol: 2 },
  { id: 'b', startCol: 1, endCol: 4 },
  { id: 'c', startCol: 3, endCol: 6 },
]);
eq('assignLanes greedy packing', [lanes.get('a'), lanes.get('b'), lanes.get('c')], [0, 1, 0]);
const boundedLanes = assignLanes(Array.from({ length: 5 }, (_, i) => ({ id: `lane-${i}`, startCol: 0, endCol: 6 })), 2);
eq('assignLanes: overflow uses one sentinel without growing lane state', [...boundedLanes.values()], [0, 1, 2, 2, 2]);

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

// The persisted duplicate graph is sparse. Two visible spokes still share
// the server-provided group id when their hub is hidden/outside the window.
const spokeA = mkOcc(1, 'Sample gathering', '2026-06-18', { extra: {
  eventId: 101, dupes: [{ pairId: 1, eventId: 100, calendarId: 3, groupId: 100 }],
} });
const spokeB = mkOcc(2, 'Sample gathering', '2026-06-18', { extra: {
  eventId: 102, dupes: [{ pairId: 2, eventId: 100, calendarId: 3, groupId: 100 }],
} });
const collapsedSpokes = collapseDuplicates([spokeA, spokeB], new Map([
  [1, { kind: 'local', provider: 'ics' }],
  [2, { kind: 'subscribed', provider: 'google' }],
]));
assert('duplicates: visible spokes collapse without their sparse hub',
  collapsedSpokes.length === 1 && collapsedSpokes[0].eventId === 102);

// The day's step list (#27) matches what the views draw.
{
  const cals = [
    { id: 1, visible: true, kind: 'local', provider: 'ics' },
    { id: 2, visible: true, kind: 'subscribed', provider: 'google' },
    { id: 3, visible: false, kind: 'local', provider: 'ics' },
  ];
  const timed = (id, cal, hh, extra = {}) => ({
    instanceId: id, eventId: Number(id.replace(/\D/g, '')) || 1, calendarId: cal, title: id,
    // Local times, so the day holds in every zone the suite runs under.
    start: new Date(2026, 5, 18, Number(hh)).toISOString(), end: new Date(2026, 5, 18, Number(hh), 30).toISOString(),
    allDay: false, attendance: 'none', ...extra,
  });
  const a = timed('s1', 1, '09');
  const hidden = timed('s2', 3, '10');
  const twinLocal = timed('s3', 1, '12', { eventId: 3, dupes: [{ pairId: 9, eventId: 4, calendarId: 2, groupId: 3 }] });
  const twinGoogle = timed('s4', 2, '12', { eventId: 4, dupes: [{ pairId: 9, eventId: 3, calendarId: 1, groupId: 3 }] });
  const weather = timed('s5', 1, '13', { relationship: 'context' });
  const maybe = timed('s6', 1, '14', { relationship: 'maybe' });
  const span = { ...mkOcc(1, 'Trip', '2026-06-17', { days: 3 }), instanceId: 's7' };
  const all = [a, hidden, twinLocal, twinGoogle, weather, maybe, span];
  const ids = (l) => l.map((o) => o.instanceId).join(',');
  const opts = (keep, showRel = {}) => ({ dayKey: '2026-06-18', calendars: cals, showRel, keep });
  eq('steps: what the views show, a twin once (the one drawn), spans included',
    ids(dayStepList(all, opts(['s1']))), 's7,s1,s4,s6');
  eq('steps: an event opened on a hidden calendar stays, and stays after stepping off it',
    ids(dayStepList(all, opts(['s1', 's2']))), 's7,s1,s2,s4,s6');
  eq('steps: the twin you opened takes the drawn twin\'s place',
    ids(dayStepList(all, opts(['s3']))), 's7,s1,s3,s6');
  eq('steps: a kind the Show filter hides leaves the list, context never joins it',
    ids(dayStepList(all, opts(['s1'], { maybe: false }))), 's7,s1,s4');
  eq('steps: an opened context item is still steppable back to',
    ids(dayStepList(all, opts(['s1', 's5']))), 's7,s1,s4,s5,s6');

  // A calendar set to hide cancelled events (#135) leaves them out; one that
  // isn't keeps them (struck through), and an opened one stays steppable.
  const gone = timed('s8', 2, '15', { status: 'cancelled' });
  const kept = timed('s9', 1, '16', { status: 'cancelled' });
  const hideCals = cals.map((c) => (c.id === 2 ? { ...c, hideCancelled: true } : c));
  const hopts = (keep) => ({ dayKey: '2026-06-18', calendars: hideCals, keep });
  eq('steps: cancelled events leave a calendar that hides them, only that one',
    ids(dayStepList([...all, gone, kept], hopts(['s1']))), 's7,s1,s4,s6,s9');
  eq('steps: an opened cancelled event stays steppable back to',
    ids(dayStepList([...all, gone, kept], hopts(['s1', 's8']))), 's7,s1,s4,s6,s8,s9');
  eq('steps: hidesCancelled lists only the calendars that chose it',
    [...hidesCancelled(hideCals)].join(','), '2');
}

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
eq('jump: ordinal day', parseJumpText('june 9th', JB), '2027-06-09');

// A view named at either end, with noise words; an odd view word never stops
// the jump, and no view word means stay in the current view.
const jt = (text) => JSON.stringify(parseJumpTarget(text, JB));
const tgt = (dayKey, granularity, view) => JSON.stringify({ dayKey, granularity, view });
eq('target: plain date has no view', jt('8/15'), tgt('2026-08-15', 'day', null));
eq('target: view first', jt('day view of June 9th'), tgt('2027-06-09', 'day', 'day'));
eq('target: view last', jt('june 9 day view'), tgt('2027-06-09', 'day', 'day'));
eq('target: month view frames the month', jt('September month view'), tgt('2026-09-01', 'month', 'month'));
eq('target: day view of a month lands on the 1st', jt('day view of september'), tgt('2026-09-01', 'month', 'day'));
eq('target: agenda from a weekday', jt('agenda from next tuesday'), tgt('2026-08-04', 'day', 'agenda'));
eq('target: schedule is agenda', jt('schedule for 8/20'), tgt('2026-08-20', 'day', 'agenda'));
eq('target: 3 weeks is not week', jt('aug 10 3 weeks'), tgt('2026-08-10', 'day', 'weeks3'));
eq('target: hyphenated 2-week view', jt('2-week view of aug 10'), tgt('2026-08-10', 'day', 'weeks2'));
eq('target: "in" before the view', jt('today in week view'), tgt('2026-07-31', 'day', 'week'));
eq('target: weekday ending in "day" is a date', jt('sunday'), tgt('2026-08-02', 'day', null));
eq('target: unknown view word is ignored', jt('june 9 calendar view'), tgt('2027-06-09', 'day', null));
eq('target: a view with no date is nothing', jt('day view'), 'null');
eq('target: gibberish', jt('fnord'), 'null');

console.log('--- quick add marker suggestions (#169) ---');
{
  const at = (text, caret = text.length) => JSON.stringify(activeMarker(text, caret));
  eq('marker: / at the start of a word', at('Lunch /fr'), JSON.stringify({ kind: '/', query: 'fr', start: 6, end: 9 }));
  eq('marker: a bare # opens the list', at('Gym #'), JSON.stringify({ kind: '#', query: '', start: 4, end: 5 }));
  eq('marker: the whole word is replaced, caret mid-word', at('Call @Sam now', 8), JSON.stringify({ kind: '@', query: 'Sa', start: 5, end: 9 }));
  eq('marker: w/ is not a marker', at('Lunch w/'), 'null');
  eq('marker: a date is not', at('Lunch 7/31'), 'null');
  eq('marker: a web address is not', at('see https://example.test/a'), 'null');
  eq('marker: an email address is not', at('mail sam@exa'), 'null');
  eq('marker: inside quotes is not', at('"Meet #te'), 'null');
  eq('marker: after a closed quote it is', at('"Meet" #te'), JSON.stringify({ kind: '#', query: 'te', start: 7, end: 10 }));
  eq('marker: a space ends it', at('Gym #health '), 'null');

  const cals = [{ id: 1, name: 'Personal' }, { id: 2, name: 'Work Travel' }, { id: 3, name: 'Friends & Family' }, { id: 4, name: 'Workouts' }];
  const people = [{ id: 1, name: 'Sam Sample' }, { id: 2, name: 'Alex Example' }, { id: 3, name: 'Alex' }, { id: 4, name: 'Robin' }];
  const tags = [{ id: 1, name: 'food' }, { id: 2, name: 'fitness' }, { id: 3, name: 'road trip' }, { id: 4, name: 'family-fun' }];
  const sug = (text) => markerSuggestions(activeMarker(text, text.length), { calendars: cals, tags, people }).map((s) => s.insert).join(' ');
  eq('suggest: calendars by same name, start, then a word in it', sug('x /w'), '/Work-Travel /Workouts');
  eq('suggest: a word inside a calendar name', sug('x /fam'), '/Friends-Family');
  eq('suggest: an empty / lists every calendar in order', sug('x /'), '/Personal /Work-Travel /Friends-Family /Workouts');
  eq('suggest: tags a marker can hold, best first', sug('x #f'), '#food #fitness #family-fun');
  eq('suggest: a tag with a space is not offered', sug('x #road'), '');
  eq('suggest: a unique first name is enough', sug('x @sa'), '@Sam');
  eq('suggest: a shared first name: the one-word name as is, the other whole', sug('x @alex'), '@Alex @"Alex Example"');
  eq('suggest: a one-word name', sug('x @rob'), '@Robin');
  eq('suggest: capped', markerSuggestions(activeMarker('#', 1), { tags: Array.from({ length: 40 }, (_, i) => ({ id: i, name: 'tag' + i })) }).length, SUGGEST_MAX);
  eq('suggest: the server reads a picked calendar as that calendar', calendarMarker('Friends & Family'), '/Friends-Family');
  eq('suggest: a person alone with one name', personMarker({ id: 9, name: 'Taylor' }, [{ id: 9, name: 'Taylor' }, { id: 10, name: 'Taylor Test' }]), '@Taylor');

  const m = activeMarker('Coffee @al tomorrow', 10);
  eq('pick: replaces the word and leaves the rest', JSON.stringify(applyMarkerPick('Coffee @al tomorrow', m, '@"Alex Example"')),
    JSON.stringify({ text: 'Coffee @"Alex Example" tomorrow', caret: 23 }));
  eq('pick: at the end adds a space to keep typing', JSON.stringify(applyMarkerPick('Gym #f', activeMarker('Gym #f', 6), '#food')),
    JSON.stringify({ text: 'Gym #food ', caret: 10 }));
}

console.log('--- paste anywhere ---');
eq('paste: plain text as is', pastedText('  Party at mine tomorrow 8pm \n', ''), 'Party at mine tomorrow 8pm');
eq('paste: a copied title brings its one link', pastedText('Halloween party', '<a href="https://events.example.test/e/abc?x=1&amp;y=2">Halloween party</a>'),
  'Halloween party https://events.example.test/e/abc?x=1&y=2');
eq('paste: text with its own link keeps it alone', pastedText('See https://events.example.test/e/abc', '<a href="https://other.example.test/">x</a>'), 'See https://events.example.test/e/abc');
eq('paste: several links say nothing about which', pastedText('Two events', '<a href="https://a.example.test/1">a</a> <a href="https://b.example.test/2">b</a>'), 'Two events');
eq('paste: the same link twice is one link', pastedText('Gig', '<a href="https://a.example.test/1">a</a><a href=\'https://a.example.test/1\'>b</a>'), 'Gig https://a.example.test/1');
eq('paste: mail and anchor links are not event links', pastedText('Ask', '<a href="mailto:host@example.test">x</a><a href="#top">y</a>'), 'Ask');
eq('paste: nothing is nothing', pastedText('', ''), '');

console.log('--- sidebar group order ---');
{
  const fs = [{ id: 3 }, { id: 1 }, { id: 7 }];
  eq('order: none chosen is folders, then All calendars, People, Plugins', sidebarGroups(fs, []).join(' '), 'f3 f1 f7 all people plugins');
  eq('order: a chosen order is kept', sidebarGroups(fs, ['all', 'f7', 'people', 'f3', 'f1', 'plugins']).join(' '), 'all f7 people f3 f1 plugins');
  eq('order: a new folder joins the other folders, before a group placed after them',
    sidebarGroups([...fs, { id: 9 }], ['all', 'f7', 'f3', 'f1', 'people', 'plugins']).join(' '), 'all f7 f3 f1 f9 people plugins');
  eq('order: a deleted folder and an unknown key are skipped', sidebarGroups([{ id: 1 }], ['f2', 'people', 'bogus', 'f1']).join(' '), 'people f1 all plugins');
  eq('order: repeats once', sidebarGroups([{ id: 1 }], ['f1', 'f1', 'all']).join(' '), 'f1 all people plugins');
  const keys = ['f3', 'f1', 'all', 'people'];
  eq('move: up one', moveGroup(keys, 'all', 1).join(' '), 'f3 all f1 people');
  eq('move: to the top', moveGroup(keys, 'people', 0).join(' '), 'people f3 f1 all');
  eq('move: past the end stays last', moveGroup(keys, 'f3', 99).join(' '), 'f1 all people f3');
  eq('move: an unknown key changes nothing', moveGroup(keys, 'f9', 0).join(' '), 'f3 f1 all people');
}

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
// Bare keys and Cmd/Ctrl keys dispatch separately (keyboard.js), so k for
// "back one week" and Cmd/Ctrl+K for the palette don't collide.
const plainKeys = HOTKEYS.filter((h) => !h.mod).flatMap((h) => h.keys);
const modKeys = HOTKEYS.filter((h) => h.mod).flatMap((h) => h.keys);
eq('hotkeys: no bare key bound twice', new Set(plainKeys).size, plainKeys.length);
eq('hotkeys: no Cmd/Ctrl key bound twice', new Set(modKeys).size, modKeys.length);
eq('hotkeys: vi keys move like the arrows', ['h', 'l', 'k', 'j'].map((k) => HOTKEYS.find((h) => !h.mod && h.keys.includes(k))?.id),
  ['prevDay', 'nextDay', 'prevWeek', 'nextWeek']);
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
setTimeFormat('24');
eq('reminder fmt entry minutes shape', fmtReminder({ minutes: 15 }), '15 minutes before');
eq('reminder fmt entry allday shape', fmtReminder({ daysBefore: 1, time: '18:00' }), 'Day before at 18:00');
eq('reminder fmt entry same day', fmtReminder({ daysBefore: 0, time: '09:00' }), 'Same day at 09:00');
setTimeFormat('12');
assert('reminder fmt all-day time follows the 12-hour setting', /^Day before at 6:00\s?PM$/i.test(fmtReminder({ daysBefore: 1, time: '18:00' })));
setTimeFormat('24'); // the summaries below read in 24-hour

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
eq('reminder defaults summary', summarizeDefaults(remSettings.reminderTimed, remSettings.reminderAllDay), '10 minutes before; all-day: Day before at 18:00');
eq('reminder defaults summary, all-day only', summarizeDefaults([], [{ daysBefore: 0, time: '08:00' }]), 'none; all-day: Same day at 08:00');
eq('reminder defaults summary, none', summarizeDefaults([], []), 'none');
setTimeFormat('12'); // later tests expect the 12-hour clock
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
eq('reminder effective an unset Opportunities calendar stays quiet',
  effectiveReminders({ override: null, calendarDefaults: null, settings: remSettings, allDay: false, calendarRole: 'opportunities' }),
  { reminders: [], source: 'calendar' });
eq('reminder effective an unset Context calendar stays quiet',
  effectiveReminders({ override: null, calendarDefaults: null, settings: remSettings, allDay: true, calendarRole: 'context' }),
  { reminders: [], source: 'calendar' });
eq('reminder effective "defaults" makes a quiet role follow the global default',
  effectiveReminders({ override: null, calendarDefaults: 'defaults', settings: remSettings, allDay: false, calendarRole: 'opportunities' }),
  { reminders: [{ minutes: 10 }], source: 'default' });

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
// Untrusted or intentionally far dates never produce a now-to-anchor window.
// The caller can do one bounded targeted lookup around the occurrence instead.
{
  const farAt = '9999-01-01T00:00:00Z';
  const w = deepLinkWindows('7:99990101T000000Z', farAt, DL_NOW);
  eq('deeplink: extreme future date has no broad window', null, w.broad);
  eq('deeplink: extreme future lookup stays three days wide', 3 * 86400000,
    parseISO(w.tight.end).getTime() - parseISO(w.tight.start).getTime());
}
{
  const w = deepLinkWindows('7:20400101T000000Z', null, DL_NOW);
  eq('deeplink: far legacy instance-id fallback has no broad window', null, w.broad);
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
{
  const malformedSuffix = stripToText('<p>fine</p>' + '<a'.repeat(20000));
  assert('richtext: malformed tag suffix stays text without rescanning',
    malformedSuffix.startsWith('fine\n<a<a') && malformedSuffix.length === 40005);
}
eq('richtext: strip empty input', stripToText(''), '');

eq('event URL: absolute HTTPS is active', safeWebUrl('https://example.test/events/7'), 'https://example.test/events/7');
eq('event URL: absolute HTTP remains compatible', safeWebUrl('http://example.test/events/7'), 'http://example.test/events/7');
for (const unsafe of ['javascript:alert(1)', 'data:text/html,x', 'file:///tmp/x', '//example.test/x', '/relative']) {
  eq('event URL: unsafe form is inert ' + unsafe, safeWebUrl(unsafe), null);
}
eq('meeting: legitimate Zoom subdomain is branded', meetingLinkInText('Join https://us02web.zoom.us/j/123')?.name, 'Zoom');
eq('meeting: exact Google Meet host is branded', meetingLinkInText('https://meet.google.com/abc-defg-hij')?.name, 'Google Meet');
for (const lookalike of ['https://evilzoom.us/j/123', 'https://zoom.us.evil.example/j/123', 'https://attacker.example/zoom.us/j/123', 'https://zoom.us@attacker.example/j/123', 'http://zoom.us/j/123']) {
  eq('meeting: lookalike stays unbranded ' + lookalike, meetingLinkInText(lookalike), null);
}

// isEmptyHtml: squire's empty document shapes count as empty.
assert('richtext: empty div-br is empty', isEmptyHtml('<div><br></div>'));
assert('richtext: empty string is empty', isEmptyHtml(''));
assert('richtext: null is empty', isEmptyHtml(null));
assert('richtext: real content is not empty', !isEmptyHtml('<div>note</div>'));
assert('richtext: plain text is not empty', !isEmptyHtml('note'));

console.log('--- battery tip ---');

// Android-only guidance; the copy must name the settings path users follow.
assert('batterytip: applies on Android UA', batteryTipApplies('Mozilla/5.0 (Linux; Android 15; K) Chrome/126'));
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
// Timed fixtures from here on are LOCAL wall-clock times (localISO), like the
// occurrences the app itself handles: the code under test works in the
// viewer's zone, so a fixture with a fixed "-07:00" is only the day and hour
// it claims to be in Pacific time, and these sections failed everywhere else.
const flightOut = { eventId: 2, allDay: false, start: localISO(2026, 5, 31, 8, 0), end: localISO(2026, 5, 31, 11, 0) };
const farTrip = {
  eventId: 3, instanceId: 't3', isContainer: true, allDay: true,
  start: '2026-07-01T00:00:00+00:00', end: '2026-07-05T00:00:00+00:00',
};
eq('candidateTrips abutting flight finds the trip once',
  candidateTrips(flightOut, [trip, farTrip, flightOut, { ...trip, instanceId: 't1b' }]).map((c) => c.eventId),
  [1]);

// attachableInSpan: overlapping non-containers only; outside, hidden and
// container occurrences drop out.
const inside = { eventId: 4, allDay: false, start: localISO(2026, 6, 3, 9, 0), end: localISO(2026, 6, 3, 10, 0) };
const outside = { eventId: 5, allDay: false, start: localISO(2026, 6, 20, 9, 0), end: localISO(2026, 6, 20, 10, 0) };
const hiddenOcc = { eventId: 6, attendance: 'hidden', allDay: false, start: localISO(2026, 6, 4, 9, 0), end: localISO(2026, 6, 4, 10, 0) };
eq('attachableInSpan filters to overlapping non-containers',
  attachableInSpan(trip, [inside, outside, hiddenOcc, farTrip, trip]).map((o) => o.eventId),
  [4]);

// Span extension: a member inside the span changes nothing.
eq('extendTripSpan no-op inside the span', extendTripSpan(trip, inside), null);
// A member after the end grows the exclusive all-day end to cover it.
const lateEvent = { eventId: 7, allDay: false, start: localISO(2026, 6, 14, 9, 0), end: localISO(2026, 6, 14, 10, 0) };
const grown = extendTripSpan(trip, lateEvent);
assert('extendTripSpan keeps the start', grown.start.slice(0, 10) === '2026-06-01');
assert('extendTripSpan exclusive end covers the late member', grown.end.slice(0, 10) === '2026-06-15');
// A member before the start moves the start day.
const earlyEvent = { eventId: 8, allDay: true, start: '2026-05-30T00:00:00+00:00', end: '2026-05-31T00:00:00+00:00' };
eq('extendTripSpan earlier start', extendTripSpan(trip, earlyEvent).start.slice(0, 10), '2026-05-30');
// Timed trips keep their times of day on the new boundary days.
const timedTrip = {
  eventId: 10, isContainer: true, allDay: false,
  start: localISO(2026, 6, 1, 10, 0), end: localISO(2026, 6, 2, 18, 0),
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
  allDay: false, start: localISO(2026, 6, 2, 9, 0), end: localISO(2026, 6, 3, 17, 0),
};
const railSingle = {
  instanceId: 'rs1', calendarId: 'c1', title: 'Dentist',
  allDay: false, start: localISO(2026, 6, 2, 11, 0), end: localISO(2026, 6, 2, 12, 0),
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

// The gaps option (Split view): every day appears, each run of empty days is
// one group, runs split at month boundaries, and a run a multi-day event
// passes through is marked covered ("nothing else on").
{
  const before = { instanceId: 'g1', calendarId: 'c1', title: 'Dinner', allDay: false, start: localISO(2026, 6, 28, 19, 0), end: localISO(2026, 6, 28, 21, 0) };
  const trip = { instanceId: 'g2', calendarId: 'c1', title: 'Trip', allDay: true, start: '2026-06-30T00:00:00+00:00', end: '2026-07-07T00:00:00+00:00' };
  const after = { instanceId: 'g3', calendarId: 'c1', title: 'Lunch', allDay: false, start: localISO(2026, 7, 9, 12, 0), end: localISO(2026, 7, 9, 13, 0) };
  const gs = buildAgendaGroups([before, trip, after], { gaps: true });
  eq('gaps: every day, empty runs folded, split at the month',
    gs.map((g) => g.gap ? `${g.dayKey}..${g.gapTo}${g.covered ? ' covered' : ''}` : g.dayKey),
    ['2026-06-28', '2026-06-29..2026-06-29', '2026-06-30', '2026-07-01..2026-07-05 covered', '2026-07-06', '2026-07-07..2026-07-08', '2026-07-09']);
  eq('gaps: the month separator opens July on the folded run',
    gs.filter((g) => g.monthStart).map((g) => g.dayKey), ['2026-07-01']);
  eq('gaps: groups stack with no holes',
    gs.every((g, i) => i === 0 || g.top === gs[i - 1].top + gs[i - 1].height), true);
  // Loaded windows: the list covers them fully, and a stretch outside them is
  // "not loaded", never "nothing on".
  const ed = (y, m, d) => epochDayOfKey(`${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`);
  const held = buildAgendaGroups([before, trip], { gaps: true, loaded: [[ed(2026, 6, 25), ed(2026, 7, 8)], [ed(2026, 7, 20), ed(2026, 7, 22)]] });
  eq('gaps: loaded edges are covered, the unloaded stretch is one line',
    held.map((g) => g.gap ? `${g.dayKey}..${g.gapTo}${g.unloaded ? ' unloaded' : ''}${g.covered ? ' covered' : ''}` : g.dayKey),
    ['2026-06-25..2026-06-27', '2026-06-28', '2026-06-29..2026-06-29', '2026-06-30', '2026-07-01..2026-07-05 covered', '2026-07-06', '2026-07-07..2026-07-08', '2026-07-09..2026-07-19 unloaded', '2026-07-20..2026-07-22']);
  eq('gaps: nothing loaded and no events is an empty list',
    buildAgendaGroups([], { gaps: true, loaded: [] }), []);
  eq('gaps: without the option, empty days are still skipped',
    buildAgendaGroups([before, trip, after]).map((g) => g.dayKey), ['2026-06-28', '2026-06-30', '2026-07-06', '2026-07-09']);

  const extreme = {
    instanceId: 'extreme', calendarId: 'c1', title: 'Long span', allDay: true,
    start: '1000-01-01T00:00:00+00:00', end: '9999-12-31T00:00:00+00:00',
  };
  const bounded = buildAgendaGroups([extreme], { gaps: true, loaded: [[ed(2026, 6, 1), ed(2026, 6, 7)]] });
  assert('gaps: extreme event work is bounded by the loaded week', bounded.length <= 7);
  eq('gaps: extreme event keeps visible continuation boundaries',
    bounded.filter((g) => !g.gap).map((g) => [g.dayKey, g.rows[0].kind]),
    [['2026-06-01', 'start'], ['2026-06-07', 'end']]);
}

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
  allDay: false, start: localISO(2026, 6, 4, 11, 0), end: localISO(2026, 6, 4, 12, 0),
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
    ev('jul', localISO(2026, 7, 31, 10, 0), localISO(2026, 7, 31, 11, 0)),
    ev('aug', localISO(2026, 8, 1, 10, 0), localISO(2026, 8, 1, 11, 0)),
    ev('aug2', localISO(2026, 8, 2, 10, 0), localISO(2026, 8, 2, 11, 0)),
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

const denseDay = Array.from({ length: 7 }, (_, i) => ({
  instanceId: `dense-${i}`, calendarId: 'c1', title: `Example ${i}`,
  allDay: false, start: localISO(2026, 6, 8, 9, i), end: localISO(2026, 6, 8, 10, i),
}));
const denseGroups = buildAgendaGroups(denseDay, { maxRowsPerDay: 5 });
eq('dense agenda: row cap preserves an exact overflow count and bounded height',
  [denseGroups[0].rows.length, denseGroups[0].overflow, denseGroups[0].height],
  [5, 2, 40 + 6 * 36]);

// Non-overlapping spans reuse lane 0.
const sequential = railRanges(buildAgendaGroups([
  { instanceId: 'q1', calendarId: 'c1', title: 'A', allDay: true, start: '2026-06-01T00:00:00+00:00', end: '2026-06-03T00:00:00+00:00' },
  { instanceId: 'q2', calendarId: 'c1', title: 'B', allDay: true, start: '2026-06-10T00:00:00+00:00', end: '2026-06-12T00:00:00+00:00' },
]));
eq('sequential rails share lane 0', sequential.map((r) => r.lane), [0, 0]);

// Washes are bounded by each span's own pills, not by whole days, so two
// overlapping spans produce three visible tones (first alone, both, second
// alone). Rails and decorative washes each have independent hard caps.
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
const manySpans = Array.from({ length: 120 }, (_, i) => ({
  instanceId: `wash-${i}`, calendarId: 'c1', title: `Span ${i}`, allDay: true,
  start: '2026-06-01T00:00:00+00:00', end: '2026-06-05T00:00:00+00:00',
}));
eq('agenda washes stop at their independent render cap',
  washRects(buildAgendaGroups(manySpans)).length, 100);
eq('no washes without spans', washRects(buildAgendaGroups([railSingle])).length, 0);

console.log('--- color utils ---');

// 37. Contrast text flips between light and dark backgrounds.
assert('contrast on dark bg is white', contrastText('#1c2a4a') === '#ffffff');
assert('contrast on light bg is dark', contrastText('#f2e9c9') === '#1a1a1a');
eq('withAlpha', withAlpha('#ff0000', 0.5), 'rgba(255,0,0,0.5)');
// Ink: a dark calendar colour lifts on a dark ground until it reads; light
// ground and light colours pass through untouched.
eq('ink: light ground leaves the colour alone', inkColor('#1c2a4a', false), '#1c2a4a');
assert('ink: dark colour lifts on a dark ground', luminance(inkColor('#1c2a4a', true)) >= 0.28);
assert('ink: lifted colour keeps its hue (blue stays bluest)', (() => { const c = parseHex(inkColor('#1c2a4a', true)); return c.b > c.r && c.b > c.g; })());
eq('ink: a readable colour is untouched on dark', inkColor('#c98c3d', true), '#c98c3d');
eq('ink: garbage passes through', inkColor('nope', true), 'nope');
assert('parseHex shorthand', JSON.stringify(parseHex('#abc')) === JSON.stringify({ r: 170, g: 187, b: 204 }));

console.log('--- chronological ordering across timezone offsets ---');

// Occurrence start strings carry each event's OWN offset, so an imported
// UTC-tzid event ("+00:00") and a local one ("-07:00") cannot be compared as
// strings: "17:00:00+00:00" sorts after "12:30:00-07:00" while actually
// being two and a half hours earlier. This is what put a 10 AM event at the
// bottom of the day-expand list.
{
  const utcTen = { start: '2026-08-18T17:00:00+00:00', title: 'Team sync' };    // 10:00 local
  const localNoon = { start: '2026-08-18T12:30:00-07:00', title: 'Errand' };    // 12:30 local
  const localTwo = { start: '2026-08-18T14:00:00-07:00', title: 'Review' };     // 14:00 local
  assert('naive string compare gets it wrong', utcTen.start > localNoon.start);
  assert('startMs orders by instant', startMs(utcTen) < startMs(localNoon));
  eq(
    'byStart sorts mixed offsets chronologically',
    [localTwo, localNoon, utcTen].sort(byStart).map((o) => o.title).join(','),
    'Team sync,Errand,Review',
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

// A long-lived tab must not retain every date it has ever visited. Trimming
// keeps the complete active window, including a multi-day event that began
// before it, and never discards an optimistic edit in progress.
{
  storeState.occ.clear();
  storeState.loadedRanges = [{ start: 0, end: Date.UTC(2030, 0, 1) }];
  const add = (id, start, end, extra = {}) => storeState.occ.set(id, { instanceId: id, start, end, ...extra });
  add('old-a', '2025-01-01T10:00:00Z', '2025-01-01T11:00:00Z');
  add('old-b', '2025-02-01T10:00:00Z', '2025-02-01T11:00:00Z');
  add('inside', '2026-04-10T10:00:00Z', '2026-04-10T11:00:00Z');
  add('spanning', '2026-03-30T10:00:00Z', '2026-04-02T11:00:00Z');
  add('draft', '2025-03-01T10:00:00Z', '2025-03-01T11:00:00Z', { _optimistic: true });
  assert('window cache: crossing the cap prunes retained history',
    pruneOccurrenceCache('2026-04-01T00:00:00Z', '2026-05-01T00:00:00Z', { max: 3 }));
  eq('window cache: the active window, overlapping span, and optimistic draft remain',
    [...storeState.occ.keys()].sort(), ['draft', 'inside', 'spanning']);
  eq('window cache: only the complete active range remains marked loaded',
    storeState.loadedRanges, [{ start: Date.parse('2026-04-01T00:00:00Z'), end: Date.parse('2026-05-01T00:00:00Z') }]);
  storeState.occ.clear();
  storeState.loadedRanges = [];

  add('history-c', '2025-01-01T10:00:00Z', '2025-01-01T11:00:00Z');
  add('history-d', '2025-02-01T10:00:00Z', '2025-02-01T11:00:00Z');
  add('partial', '2026-04-05T10:00:00Z', '2026-04-05T11:00:00Z');
  storeState.loadedRanges = [
    { start: Date.parse('2026-03-01T00:00:00Z'), end: Date.parse('2026-04-10T00:00:00Z') },
    { start: Date.parse('2026-04-20T00:00:00Z'), end: Date.parse('2026-06-01T00:00:00Z') },
  ];
  eq('window cache: intermediate pruning preserves only ranges actually loaded so far',
    pruneOccurrenceCache('2026-04-01T00:00:00Z', '2026-05-01T00:00:00Z', { max: 2, markLoaded: false }),
    'pruned');
  eq('window cache: intermediate pruning never marks the unfinished full window loaded', storeState.loadedRanges, [
    { start: Date.parse('2026-04-01T00:00:00Z'), end: Date.parse('2026-04-10T00:00:00Z') },
    { start: Date.parse('2026-04-20T00:00:00Z'), end: Date.parse('2026-05-01T00:00:00Z') },
  ]);
  storeState.occ.clear();
  storeState.loadedRanges = [];

  for (let i = 0; i < 4; i++) {
    add('dense-' + i, `2026-04-${String(10 + i).padStart(2, '0')}T10:00:00Z`, `2026-04-${String(10 + i).padStart(2, '0')}T11:00:00Z`);
  }
  eq('window cache: an active range over the cap is refused rather than silently truncated',
    pruneOccurrenceCache('2026-04-01T00:00:00Z', '2026-05-01T00:00:00Z', { max: 3 }), 'limited');
  eq('window cache: a refused dense range is neither rendered partially nor marked loaded',
    [storeState.occ.size, storeState.loadedRanges.length], [0, 0]);

  add('current', '2026-06-10T10:00:00Z', '2026-06-10T11:00:00Z');
  add('history-a', '2025-01-01T10:00:00Z', '2025-01-01T11:00:00Z');
  add('history-b', '2025-02-01T10:00:00Z', '2025-02-01T11:00:00Z');
  eq('window cache: a stale completion cannot choose the retained window',
    pruneOccurrenceCache('2026-04-01T00:00:00Z', '2026-05-01T00:00:00Z', {
      max: 2,
      activeWindow: { start: '2026-06-01T00:00:00Z', end: '2026-07-01T00:00:00Z' },
    }), 'stale');
  eq('window cache: stale completion leaves the current and historical cache untouched', storeState.occ.size, 3);
  storeState.occ.clear();
  storeState.loadedRanges = [];
}

{
  // Tile mosaic maths. At zoom 1 the world is a 2x2 grid of 256px tiles, so
  // lat 0 / lng 0 sits at pixel (256, 256): the corner shared by all four.
  // A 512x256 box centred there spans x 0..512 and y 128..384, so it needs
  // all four tiles, the top row drawn 128px above the box.
  const m = mapMosaic(0, 0, { width: 512, height: 256, zoom: 1, retina: false });
  eq('mosaic: four tiles cover a box on the zoom-1 origin', m.tiles.length, 4);
  eq('mosaic: first tile is (0,0) drawn 128px above the box', m.tiles[0].top, -128);
  eq('mosaic: first tile is at the left edge', m.tiles[0].left, 0);
  assert('mosaic: tile url carries z/x/y', m.tiles[0].url.endsWith('/1/0/0.png'));
  eq('mosaic: pin sits at the centre, anchored bottom-middle', JSON.stringify(m.pin), JSON.stringify({ left: 512 / 2 - 12, top: 256 / 2 - 41 }));
  const r = mapMosaic(45.52, -122.68, { width: 640, height: 200, zoom: 15 });
  assert('mosaic: a real point at zoom 15 needs 3-4 columns and 1-2 rows', r.tiles.length >= 3 && r.tiles.length <= 8);
  assert('mosaic: retina tiles by default', r.tiles[0].url.includes('@2x'));
  assert('mosaic: MapTiler when a key is given', mapMosaic(0, 0, { zoom: 1, maptilerKey: 'k', style: 'streets-v2' }).tiles[0].url.startsWith('https://api.maptiler.com/maps/streets-v2/1/'));
  eq('mosaic: no coordinates, no mosaic', mapMosaic(null, null), null);
  for (const [lat, lng] of [[91, 0], [-91, 0], [0, 181], [0, -181], [1e308, 1e308], [NaN, 0], [Infinity, 0]]) {
    eq('mosaic: invalid coordinate pair is inert ' + lat + ',' + lng, mapMosaic(lat, lng), null);
  }
  eq('coordinates: boundary pair is valid', validCoordinates(90, -180), { lat: 90, lng: -180 });
  assert('mosaic: accepted coordinate has a fixed small tile set', mapMosaic(90, 180).tiles.length <= 8);
  // Tiles follow the theme: Stadia's dark style, MapTiler's dark twin where
  // one exists, the same style where it does not.
  assert('mosaic: dark ground picks the dark Stadia style', mapMosaic(0, 0, { zoom: 1, dark: true }).tiles[0].url.includes('/tiles/alidade_smooth_dark/'));
  eq('map style: stadia light', stadiaStyle(false), 'outdoors');
  eq('map style: maptiler dark twin', mapTilerStyle('streets-v2', true), 'streets-v2-dark');
  eq('map style: maptiler already dark', mapTilerStyle('streets-v2-dark', true), 'streets-v2-dark');
  eq('map style: maptiler style without a twin stays', mapTilerStyle('topo-v2', true), 'topo-v2');
  eq('map style: maptiler default on light', mapTilerStyle(null, false), 'streets-v2');
}

// A day cell with the filter box in use (#126): matches first, grayed-out
// events only in slots left over, and "+N" counting matches only.
{
  const { planCell } = await import('../src/ui/monthmath.js');
  const { assignLanes } = await import('../src/ui/layout.js');
  const ev = (id, q = false) => ({ instanceId: id, q });
  const quiet = (o) => o.q;
  const names = (plan) => plan.shown.map((o) => o.instanceId);
  const day = [ev('a', true), ev('b', true), ev('c'), ev('d', true), ev('e')];
  let plan = planCell(day, quiet, 3);
  eq('+N: matches come first, grayed-out events fill what is left', names(plan), ['c', 'e', 'a']);
  eq('+N: nothing hidden when the matches fit', plan.hidden, 0);
  plan = planCell([ev('a', true), ev('b'), ev('c'), ev('d'), ev('e', true), ev('f')], quiet, 3);
  eq('+N: too many matches show room - 1 of them and count the rest', [names(plan), plan.hidden], [['b', 'c'], 2]);
  eq('+N: the phone dots are the hidden matches', plan.more.map((o) => o.instanceId), ['d', 'f']);
  plan = planCell([ev('a'), ev('b', true)], quiet, 3, 2);
  eq('+N: hidden matching bars count; a grayed-out event takes the slot left over', [names(plan), plan.hidden], [['a', 'b'], 2]);
  plan = planCell([ev('a'), ev('b'), ev('c', true)], quiet, 3, 2);
  eq('+N: matching bars that push matches over leave no slot for grayed-out ones', [names(plan), plan.hidden], [['a', 'b'], 2]);
  plan = planCell([ev('a'), ev('b'), ev('c'), ev('d')], () => false, 3);
  eq('+N: with nothing grayed out the layout is unchanged', [names(plan), plan.hidden], [['a', 'b'], 2]);
  plan = planCell([ev('a', true), ev('b', true), ev('c', true), ev('d', true)], quiet, 3);
  eq('+N: a day of only grayed-out events shows what fits, with no "+N"', [plan.shown.length, plan.hidden], [3, 0]);
  const lanes = assignLanes([
    { id: 'quiet', startCol: 0, endCol: 3, quiet: true },
    { id: 'match', startCol: 1, endCol: 2 },
  ], 1);
  eq('+N: a grayed-out bar gives its lane to a matching one', [lanes.get('match'), lanes.get('quiet')], [0, 1]);
}

// --- Very long spans cost nothing until a row is drawn (BC-15) ------------------
{
  const { rowSegmentAt, spanRowCount, clampDayRange, LONG_SPAN_ROWS } = await import('../src/ui/monthmath.js');
  // rowSegmentAt must be indistinguishable from the pre-segmented path, row for
  // row, or a long event would draw differently from a short one.
  for (const columns of [7, 3]) {
    for (const [s, e] of [['2026-06-03', '2026-06-03'], ['2026-05-29', '2026-06-14'], ['2026-01-01', '2027-03-15']]) {
      const all = rowSpanSegments(s, e, columns);
      eq(`long span: row count matches segmentation (${columns} cols, ${s}..${e})`, spanRowCount(s, e, columns), all.length);
      eq(`long span: every row's segment matches (${columns} cols, ${s}..${e})`,
        all.map((seg) => rowSegmentAt(s, e, seg.rowIndex, columns)), all);
      eq(`long span: the row before and the row after get nothing (${columns} cols, ${s}..${e})`,
        [rowSegmentAt(s, e, all[0].rowIndex - 1, columns), rowSegmentAt(s, e, all[all.length - 1].rowIndex + 1, columns)], [null, null]);
    }
  }
  // The attack shape: a thousand years. Deciding it is long, and getting the
  // segment for a row in the middle, are both arithmetic, not loops.
  const t0 = Date.now();
  const rows = spanRowCount('1000-01-01', '2000-01-01', 7);
  const mid = rowSegmentAt('1000-01-01', '2000-01-01', rowIndexOfDayKey('1500-06-15', 7), 7);
  assert('long span: a millennium is tens of thousands of rows', rows > 52000 && rows > LONG_SPAN_ROWS);
  eq('long span: a middle row is a full-width continuation', mid && [mid.startCol, mid.endCol, mid.contLeft, mid.contRight], [0, 6, true, true]);
  assert('long span: answered without walking it', Date.now() - t0 < 50);
  eq('long span: an ordinary two-week trip is pre-segmented as before', spanRowCount('2026-06-01', '2026-06-14', 7) <= LONG_SPAN_ROWS, true);
  eq('clamp: inside', clampDayRange(10, 20, 0, 100), [10, 20]);
  eq('clamp: cut to the rendered rows at both ends', clampDayRange(-500000, 500000, 20600, 20641), [20600, 20641]);
  eq('clamp: a span wholly off screen highlights nothing', clampDayRange(10, 20, 30, 40), null);
}

// --- Month rows that fit their contents (#171): row geometry ----------------------
{
  const { fixedGeometry, fitGeometry, weekRangeIn, dominantMonthOfWeeks, weekTop, totalHeight, visibleWeekRange } = await import('../src/ui/monthmath.js');
  // Fixed rows must be exactly the arithmetic the grid always used.
  const fx = fixedGeometry(100, 300, 120);
  eq('geometry: fixed tops are index x height', [fx.top(100), fx.top(150), fx.top(300)], [weekTop(100, 100, 120), weekTop(150, 100, 120), weekTop(300, 100, 120)]);
  eq('geometry: fixed total is the old spacer', fx.total(), totalHeight(100, 300, 120));
  eq('geometry: fixed weekAt is the old floor', [fx.weekAt(0), fx.weekAt(119), fx.weekAt(120), fx.weekAt(6000)], [100, 100, 101, 150]);
  // Fit: two tall weeks among 120px ones.
  const ft = fitGeometry(0, 20, 120, new Map([[5, 300], [7, 500]]));
  eq('geometry: a week below a tall one starts after it', [ft.top(5), ft.top(6), ft.top(7), ft.top(8)], [600, 900, 1020, 1520]);
  eq('geometry: heights come back per week', [ft.height(4), ft.height(5), ft.height(7)], [120, 300, 500]);
  eq('geometry: the week under a point, by binary search', [ft.weekAt(0), ft.weekAt(599), ft.weekAt(600), ft.weekAt(899), ft.weekAt(900), ft.weekAt(1519), ft.weekAt(1520)], [0, 4, 5, 5, 6, 7, 8]);
  eq('geometry: points past either end stay on the first and last week', [ft.weekAt(-50), ft.weekAt(1e9)], [0, 20]);
  eq('geometry: total adds every height', ft.total(), 19 * 120 + 300 + 500);
  eq('geometry: a week past the end has the total as its top', ft.top(21), ft.total());
  eq('geometry: the rendered range covers the screen plus the buffer', weekRangeIn(ft, 900, 700, 0, 20, 2), { first: 4, last: 10 });
  eq('geometry: with fixed rows it renders what it always did, give or take one buffer row', (() => {
    const a = weekRangeIn(fx, 3000, 700, 100, 300, 3);
    const b = visibleWeekRange(3000, 700, 120, 100, 300, 3);
    return a.first === b.first && Math.abs(a.last - b.last) <= 1;
  })(), true);
  // The month on screen, weighing each week by how much of it shows.
  const { rowIndexOfDayKey: rowOf } = await import('../src/ui/monthmath.js');
  const late = rowOf('2026-09-28', 7); // Sep 28 - Oct 4 (Monday start or Sunday start, mostly late Sep / early Oct)
  const next = rowOf('2026-10-12', 7);
  eq('month by area: a sliver of a September week loses to a screenful of October', dominantMonthOfWeeks([{ weekIndex: late, px: 20 }, { weekIndex: next, px: 700 }], 7), { year: 2026, month: 10 });
  eq('month by area: and wins when it fills the screen', dominantMonthOfWeeks([{ weekIndex: rowOf('2026-09-14', 7), px: 700 }, { weekIndex: next, px: 30 }], 7), { year: 2026, month: 9 });
}

// --- Home zone vs device zone -------------------------------------------------
{
  const { tzOffsetMinutes, tzOffsetLabel, tzCity, sameClock } = await import('../src/lib/dates.js');
  const summer = new Date('2026-07-01T12:00:00Z');
  const winter = new Date('2026-01-15T12:00:00Z');
  eq('tz offset: LA summer', tzOffsetMinutes('America/Los_Angeles', summer), -420);
  eq('tz offset: LA winter', tzOffsetMinutes('America/Los_Angeles', winter), -480);
  eq('tz offset: UTC', tzOffsetMinutes('UTC', summer), 0);
  eq('tz offset: half-hour zone', tzOffsetMinutes('Asia/Kolkata', summer), 330);
  eq('tz offset: 45-minute zone', tzOffsetMinutes('Asia/Kathmandu', summer), 345);
  eq('tz offset: unknown zone is null, not a guess', tzOffsetMinutes('Mars/Olympus_Mons', summer), null);
  eq('tz label: negative whole hours', tzOffsetLabel('America/Los_Angeles', summer), 'UTC-7');
  eq('tz label: half hour', tzOffsetLabel('Asia/Kolkata', summer), 'UTC+5:30');
  eq('tz label: UTC', tzOffsetLabel('UTC', summer), 'UTC');
  eq('tz city', tzCity('America/Argentina/Buenos_Aires'), 'Buenos Aires');
  assert('same clock: identical names', sameClock('America/Los_Angeles', 'America/Los_Angeles', summer));
  // Tijuana follows the US clock changes. (This used Vancouver until British
  // Columbia moved to permanent UTC-7 from 2026-11-01 in tzdata 2026a; the
  // answer then depended on the machine's time-zone data.)
  assert('same clock: Tijuana keeps Los Angeles time all year (no notice)', sameClock('America/Los_Angeles', 'America/Tijuana', summer));
  assert('same clock: Lisbon is not Los Angeles', !sameClock('America/Los_Angeles', 'Europe/Lisbon', summer));
  // Phoenix matches Los Angeles in summer only; agreeing today is not enough.
  assert('same clock: Phoenix differs from LA once DST ends', !sameClock('America/Los_Angeles', 'America/Phoenix', summer));
  assert('same clock: nothing stored means nothing to announce', sameClock(null, 'Europe/Lisbon', summer));
  assert('same clock: an unknown zone is not announced', sameClock('Mars/Olympus_Mons', 'Europe/Lisbon', summer));
}

// --- Per-event time zone: a wall-clock reading in a named zone ------------------
// The editor takes "3:00 PM" plus a picked zone. These must not depend on the
// zone the test (or the browser) runs in, which is the whole point.
{
  const { instantFromWallTime, wallTimeInZone, zoneOptions, zoneNote } = await import('../src/lib/dates.js');
  const utc = (d) => d.toISOString().slice(0, 16) + 'Z';
  eq('zone: 3 PM New York in summer', utc(instantFromWallTime('2026-06-04T15:00', 'America/New_York')), '2026-06-04T19:00Z');
  eq('zone: 3 PM New York in winter', utc(instantFromWallTime('2026-01-15T15:00', 'America/New_York')), '2026-01-15T20:00Z');
  eq('zone: half-hour offset', utc(instantFromWallTime('2026-06-04T09:00', 'Asia/Kolkata')), '2026-06-04T03:30Z');
  eq('zone: east of the date line lands on the previous UTC day', utc(instantFromWallTime('2026-06-04T08:00', 'Pacific/Auckland')), '2026-06-03T20:00Z');
  eq('zone: UTC is the identity', utc(instantFromWallTime('2026-06-04T15:00', 'UTC')), '2026-06-04T15:00Z');
  // The offset must be read at the RIGHT instant: the evening of the US
  // fall-back day is already on winter time, the morning before it is not.
  eq('zone: evening after fall-back uses the winter offset', utc(instantFromWallTime('2026-11-01T20:00', 'America/Los_Angeles')), '2026-11-02T04:00Z');
  eq('zone: just before fall-back uses the summer offset', utc(instantFromWallTime('2026-11-01T00:30', 'America/Los_Angeles')), '2026-11-01T07:30Z');
  eq('zone: a time inside the spring-forward gap moves an hour later', utc(instantFromWallTime('2026-03-08T02:30', 'America/Los_Angeles')), '2026-03-08T10:30Z');
  // 0.9.15: the repeated hour when clocks go back is the earlier pass, east and west.
  eq('zone: repeated hour is the earlier pass (London)', utc(instantFromWallTime('2026-10-25T01:30', 'Europe/London')), '2026-10-25T00:30Z');
  eq('zone: repeated hour is the earlier pass (Los Angeles)', utc(instantFromWallTime('2026-11-01T01:30', 'America/Los_Angeles')), '2026-11-01T08:30Z');
  eq('zone: repeated hour is the earlier pass (Sydney)', utc(instantFromWallTime('2026-04-05T02:30', 'Australia/Sydney')), '2026-04-04T15:30Z');
  const { addDaysInZone } = await import('../src/lib/dates.js');
  eq('zone: a London 9:00 moved a week across the US change stays 9:00 London', utc(addDaysInZone(new Date('2026-03-02T09:00:00Z'), 7, 'Europe/London')), '2026-03-09T09:00Z');
  eq('zone: a London 9:00 moved across the UK change stays 9:00 London', utc(addDaysInZone(new Date('2026-03-23T09:00:00Z'), 7, 'Europe/London')), '2026-03-30T08:00Z');
  assert('zone: an unknown zone is NaN, never a silent guess', isNaN(instantFromWallTime('2026-06-04T15:00', 'Mars/Olympus_Mons')));
  assert('zone: a malformed value is NaN', isNaN(instantFromWallTime('next tuesday', 'UTC')));
  eq('zone: wall time in New York', wallTimeInZone(new Date('2026-06-04T19:00:00Z'), 'America/New_York'), '2026-06-04T15:00');
  eq('zone: wall time crosses the date line', wallTimeInZone(new Date('2026-06-03T20:00:00Z'), 'Pacific/Auckland'), '2026-06-04T08:00');
  for (const tz of ['America/Los_Angeles', 'Europe/London', 'Asia/Kolkata', 'Pacific/Auckland', 'America/St_Johns']) {
    eq('zone: wall -> instant -> wall round-trips in ' + tz, wallTimeInZone(instantFromWallTime('2026-08-20T18:45', tz), tz), '2026-08-20T18:45');
  }
  const opts = zoneOptions(['Mars/Olympus_Mons']);
  // India is listed as Asia/Calcutta or Asia/Kolkata depending on the runtime's ICU.
  assert('zone options: includes UTC and real zones, labelled with an offset',
    opts.some(([z, l]) => z === 'Asia/Tokyo' && l === 'Asia/Tokyo (UTC+9)')
    && opts.some(([z, l]) => /^Asia\/(Kolkata|Calcutta)$/.test(z) && l.endsWith('(UTC+5:30)'))
    && opts.some(([z]) => z === 'UTC'));
  assert('zone options: a zone the runtime does not list is still offered', opts.some(([z]) => z === 'Mars/Olympus_Mons'));
  assert('zone options: sorted by zone id', opts.every(([z], i) => i === 0 || opts[i - 1][0].localeCompare(z) <= 0));
  // The detail view's note: only for a timed event whose zone keeps a different
  // clock from this device, and never for UTC (what imports write for "unknown").
  const device = Intl.DateTimeFormat().resolvedOptions().timeZone;
  const far = device === 'Asia/Tokyo' || device === 'Asia/Seoul' ? 'America/New_York' : 'Asia/Tokyo';
  const timed = { allDay: false, start: '2026-06-04T19:00:00+00:00', end: '2026-06-04T20:00:00+00:00' };
  assert('zone note: names the city for a far zone', zoneNote({ ...timed, tzid: far }).endsWith(' in ' + far.split('/')[1].replace('_', ' ')));
  eq('zone note: none for an event in this device\'s zone', zoneNote({ ...timed, tzid: device }), '');
  eq('zone note: none for UTC (unknown, not a place)', zoneNote({ ...timed, tzid: 'UTC' }), '');
  eq('zone note: none for all-day events', zoneNote({ ...timed, allDay: true, tzid: far }), '');
  eq('zone note: none without a zone', zoneNote(timed), '');
}

// --- Repeat end date: the local day, however UNTIL was written (0.9.14) ---
{
  // What the editor writes for "ends Oct 20" (23:59:59 local, in UTC) reads
  // back as Oct 20 in every zone the suite runs in, so saving never moves it.
  const written = new Date(2026, 9, 20, 23, 59, 59);
  const z = written.getUTCFullYear() + String(written.getUTCMonth() + 1).padStart(2, '0') + String(written.getUTCDate()).padStart(2, '0')
    + 'T' + String(written.getUTCHours()).padStart(2, '0') + String(written.getUTCMinutes()).padStart(2, '0') + '00Z';
  eq('until: a UTC end time reads as its local day', untilDayKey(z), '2026-10-20');
  eq('until: a date-only end is that date', untilDayKey('20261020'), '2026-10-20');
  eq('until: nothing usable is empty', untilDayKey(''), '');
  eq('until: a UTC end read on the series clock (Tokyo)', untilDayKey('20261020T145900Z', 'Asia/Tokyo'), '2026-10-20');
  eq('until: a UTC end read on the series clock (Los Angeles)', untilDayKey('20261021T065900Z', 'America/Los_Angeles'), '2026-10-20');
}

// --- All-day date fields: the last day, not the stored exclusive end (#34) ---
{
  eq('all-day fields: a two-week trip reads Sep 12 to Sep 27', allDayFields('2026-09-12T00:00', '2026-09-28T00:00'), { start: '2026-09-12', end: '2026-09-27' });
  eq('all-day fields: one day reads as that day twice', allDayFields('2026-09-12T00:00', '2026-09-13T00:00'), { start: '2026-09-12', end: '2026-09-12' });
  eq('all-day fields: across a year boundary', allDayFields('2026-12-31T00:00', '2027-01-01T00:00'), { start: '2026-12-31', end: '2026-12-31' });
  eq('all-day fields: a timed event just switched to all day keeps its own day', allDayFields('2026-09-24T10:00', '2026-09-24T11:00'), { start: '2026-09-24', end: '2026-09-24' });
  eq('all-day fields: a timed event ending at midnight ends the day before', allDayFields('2026-09-24T22:00', '2026-09-25T00:00'), { start: '2026-09-24', end: '2026-09-24' });
  eq('all-day fields: an end before the start is pulled up to it', allDayFields('2026-09-24T00:00', '2026-09-24T00:00'), { start: '2026-09-24', end: '2026-09-24' });
  eq('all-day inputs: the last day becomes the stored exclusive end', allDayInputs('2026-09-12', '2026-09-27'), { start: '2026-09-12T00:00', end: '2026-09-28T00:00' });
  eq('all-day inputs: a last day before the first is the first', allDayInputs('2026-09-12', '2026-09-01'), { start: '2026-09-12T00:00', end: '2026-09-13T00:00' });
  eq('all-day inputs: the day clocks change is still one day', allDayInputs('2026-11-01', '2026-11-01'), { start: '2026-11-01T00:00', end: '2026-11-02T00:00' });
  const trip = allDayInputs('2026-02-27', '2026-03-02');
  eq('all-day: round trip over the end of February', allDayFields(trip.start, trip.end), { start: '2026-02-27', end: '2026-03-02' });
}

// --- A location that only says it will come later is not a place ---
{
  for (const t of ["Location available once RSVP'd", 'Location will be shared after you register', 'Address revealed upon approval',
    'RSVP to see location', 'Register to view the address', 'Location: TBA', 'Venue TBD']) {
    eq('pending location: ' + t, isPendingLocation(t), true);
  }
  for (const t of ['100 Main St, Springfield', 'Location Bar & Grill, Denver', 'The RSVP Lounge', 'https://zoom.us/j/1', '', null]) {
    eq('a real place is not pending: ' + String(t), isPendingLocation(t), false);
  }
}

// --- Web Share Target: bounded one-shot handoff --------------------------------
{
  eq('share handoff: ordinary fields are preserved',
    normalizeShareParams({ title: 'Example', text: 'Meet at 10', url: 'https://example.com/item' }),
    { title: 'Example', text: 'Meet at 10', url: 'https://example.com/item' });
  eq('share handoff: exact aggregate character limit is accepted',
    normalizeShareParams({ text: 'x'.repeat(4000) })?.text.length, 4000);
  eq('share handoff: a field over the character limit is refused',
    normalizeShareParams({ text: 'x'.repeat(4001) }), null);
  eq('share handoff: combined fields over the aggregate limit are refused',
    normalizeShareParams({ title: 'x'.repeat(2000), text: 'y'.repeat(2001) }), null);
  eq('share handoff: non-string fields are refused', normalizeShareParams({ text: 42 }), null);
}

// --- Event windows: a failed load is shown and retried, not left blank (#48) ---
{
  const realFetch = globalThis.fetch;
  const { loadWindow, retryWindowsNow } = await import('../src/app/api.js');
  const { state: st, set: put } = await import('../src/app/store.js');
  let mode = 'offline';
  let calls = 0;
  globalThis.fetch = async () => {
    calls++;
    if (mode === 'offline') {
      return new Response(JSON.stringify({ error: { code: 'offline', message: 'Offline and not cached' } }), { status: 503, headers: { 'Content-Type': 'application/json' } });
    }
    if (mode === 'limited') {
      return new Response(JSON.stringify({ error: { code: 'event_window_too_large', message: 'Narrow this date range.' } }), { status: 422, headers: { 'Content-Type': 'application/json' } });
    }
    return new Response(JSON.stringify({ events: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  };
  put({ loadedRanges: [], windowStatus: null });
  const a = '2026-09-01T00:00:00-07:00';
  const b = '2026-10-01T00:00:00-07:00';
  let threw = false;
  try { await loadWindow(a, b); } catch { threw = true; }
  assert('window: a failed load no longer rejects into nowhere', !threw);
  eq('window: the failure is shown, as offline, with a retry scheduled', [st.windowStatus && st.windowStatus.kind, st.windowStatus && st.windowStatus.offline, st.windowStatus && st.windowStatus.retryAt > Date.now()], ['failed', true, true]);
  const before = calls;
  mode = 'online';
  retryWindowsNow();
  await new Promise((r) => setTimeout(r, 20));
  assert('window: Retry now asks again', calls > before);
  eq('window: once it loads, the note goes away', st.windowStatus, null);
  assert('window: and the dates count as loaded', st.loadedRanges.length > 0);
  // A range that fails, then turns out to be covered by a wider window that
  // loaded, must not leave the note stuck on.
  mode = 'offline';
  put({ loadedRanges: [], windowStatus: null });
  await loadWindow('2027-01-01T00:00:00-08:00', '2027-01-15T00:00:00-08:00');
  mode = 'online';
  await loadWindow('2026-12-01T00:00:00-08:00', '2027-02-01T00:00:00-08:00');
  retryWindowsNow();
  await new Promise((r) => setTimeout(r, 20));
  eq('window: a failed range covered later by a wider load clears the note', st.windowStatus, null);
  put({ loadedRanges: [], windowStatus: null });
  mode = 'online';
  const loading = loadWindow(a, b);
  eq('window: a cold load says it is loading', st.windowStatus && st.windowStatus.kind, 'loading');
  await loading;
  eq('window: and stops saying so when it lands', st.windowStatus, null);
  put({ loadedRanges: [], windowStatus: null });
  mode = 'limited';
  await loadWindow('2028-03-01T00:00:00-08:00', '2028-04-01T00:00:00-07:00');
  eq('window: a server work limit is explained without scheduling a retry',
    [st.windowStatus && st.windowStatus.kind, st.windowStatus && st.windowStatus.message, st.windowStatus && st.windowStatus.retryAt],
    ['limited', 'Narrow this date range.', undefined]);
  const limitedCalls = calls;
  retryWindowsNow();
  await new Promise((r) => setTimeout(r, 20));
  eq('window: Retry now does not repeat an unchanged over-budget request', calls, limitedCalls);
  mode = 'online';
  const beforeWide = calls;
  await loadWindow('2020-01-01T00:00:00Z', '2022-01-01T00:00:00Z');
  eq('window: a client request over 700 days is refused without a request fan-out',
    [calls, st.windowStatus && st.windowStatus.kind], [beforeWide, 'limited']);
  globalThis.fetch = realFetch;
}

console.log('--- API offline session transitions ---');

{
  const originalFetch = globalThis.fetch;
  const jsonResponse = (data, status = 200, headers = {}) => new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json', ...headers },
  });
  try {
    const calls = [];
    storeState.offlineReadOnly = true;
    storeState.authed = false;
    storeState.csrf = null;
    globalThis.fetch = async (url, options = {}) => {
      calls.push([url, options.method || 'GET']);
      return jsonResponse({ ok: true });
    };
    await requestApi('/auth/login', { method: 'POST', body: { email: 'owner@example.test', password: 'sample' } });
    eq('api offline: credential recovery POST is not blocked', calls, [['/api/v1/auth/login', 'POST']]);

    globalThis.fetch = async () => jsonResponse({ user: { email: 'owner@example.test' } }, 200, {
      'X-BetterCal-Offline': '1',
    });
    await fetchApiMe();
    eq('api offline: cached /me is authenticated but read-only and carries no CSRF',
      [storeState.authed, storeState.offlineReadOnly, storeState.csrf], [true, true, null]);

    globalThis.fetch = async () => jsonResponse({ config: {} });
    await requestApi('/config');
    eq('api offline: an unrelated live GET does not claim writable session recovery', storeState.offlineReadOnly, true);

    const recoveryCalls = [];
    globalThis.fetch = async (url, options = {}) => {
      recoveryCalls.push([url, options.method || 'GET', options.headers?.['X-CSRF'] || null]);
      if (url.endsWith('/me')) return jsonResponse({ user: { email: 'owner@example.test' }, csrf: 'LIVE-CSRF' });
      return jsonResponse({ ok: true });
    };
    await requestApi('/events', { method: 'POST', body: { title: 'Sample event' } });
    eq('api offline: a mutation restores live /me before sending its CSRF', recoveryCalls, [
      ['/api/v1/me', 'GET', null],
      ['/api/v1/events', 'POST', 'LIVE-CSRF'],
    ]);
    eq('api offline: live /me restores writable mode', [storeState.offlineReadOnly, storeState.csrf], [false, 'LIVE-CSRF']);

    storeState.authed = true;
    storeState.offlineReadOnly = true;
    storeState.csrf = null;
    globalThis.fetch = async () => jsonResponse({ error: { code: 'unauthorized' } }, 401);
    try { await requestApi('/me'); } catch { /* expected */ }
    eq('api offline: a live 401 resets local state so login can recover',
      [storeState.authed, storeState.offlineReadOnly, storeState.csrf], [false, false, null]);

    storeState.authed = true;
    storeState.offlineReadOnly = true;
    storeState.csrf = null;
    let releaseMe;
    let eventWrites = 0;
    globalThis.fetch = async (url) => {
      if (url.endsWith('/me')) {
        return {
          status: 200, ok: true, statusText: 'OK', headers: new Headers(),
          json: () => new Promise((resolve) => { releaseMe = () => resolve({ user: { email: 'owner@example.test' }, csrf: 'STALE-CSRF' }); }),
        };
      }
      if (url.endsWith('/events')) eventWrites++;
      return jsonResponse({ ok: true });
    };
    const interruptedWrite = requestApi('/events', { method: 'POST', body: { title: 'Sample event' } });
    while (!releaseMe) await new Promise((resolve) => setTimeout(resolve, 0));
    // The logout itself is online here; the point under test is that it wins
    // over the older /me body still being parsed by the interrupted write.
    storeState.offlineReadOnly = false;
    storeState.csrf = 'CURRENT-CSRF';
    await apiLogout();
    releaseMe();
    try { await interruptedWrite; } catch { /* logout invalidates the pending recovery */ }
    eq('api offline: logout cannot be undone by a pending live /me response',
      [storeState.authed, storeState.offlineReadOnly, storeState.csrf, eventWrites], [false, false, null, 0]);

    storeState.authed = true;
    storeState.offlineReadOnly = true;
    storeState.csrf = null;
    const logoutCalls = [];
    globalThis.fetch = async (url, options = {}) => {
      logoutCalls.push([url, options.method || 'GET', options.headers?.['X-CSRF'] || null]);
      if (url.endsWith('/me')) return jsonResponse({ user: { email: 'owner@example.test' }, csrf: 'LOGOUT-CSRF' });
      return jsonResponse({ ok: true });
    };
    await apiLogout();
    eq('api offline: logout recovers live CSRF before ending the server session', logoutCalls, [
      ['/api/v1/me', 'GET', null],
      ['/api/v1/auth/logout', 'POST', 'LOGOUT-CSRF'],
    ]);

    storeState.authed = true;
    storeState.offlineReadOnly = false;
    storeState.csrf = 'OLD-CSRF';
    let releaseOld401;
    globalThis.fetch = async (url) => {
      if (url.endsWith('/events')) {
        return new Promise((resolve) => { releaseOld401 = () => resolve(jsonResponse({ error: { code: 'unauthorized' } }, 401)); });
      }
      if (url.endsWith('/auth/login')) return jsonResponse({ ok: true });
      if (url.endsWith('/me')) return jsonResponse({ user: { email: 'new@example.test' }, csrf: 'NEW-CSRF' });
      return jsonResponse({ ok: true });
    };
    const stale401 = requestApi('/events');
    while (!releaseOld401) await new Promise((resolve) => setTimeout(resolve, 0));
    await apiLogin('new@example.test', 'sample');
    releaseOld401();
    let stale401Code = null;
    try { await stale401; } catch (e) { stale401Code = e.code; }
    eq('api session: a delayed 401 cannot sign out a newer login',
      [stale401Code, storeState.authed, storeState.user?.email, storeState.csrf],
      ['session_changed', true, 'new@example.test', 'NEW-CSRF']);

    storeState.authed = true;
    storeState.offlineReadOnly = true;
    globalThis.fetch = async () => { throw new TypeError('offline'); };
    try { await apiLogout(); } catch { /* server session may remain; local logout must still win */ }
    eq('api offline: failed logout still leaves this device locally signed out',
      [storeState.authed, storeState.offlineReadOnly, storeState.csrf], [false, false, null]);
  } finally {
    globalThis.fetch = originalFetch;
    storeState.authed = false;
    storeState.user = null;
    storeState.csrf = null;
    storeState.offlineReadOnly = false;
  }
}

// --- Service worker: the API cache must not outlive the session (BC-04) -------
// Runs the REAL web/sw.js in a sandbox with a fake CacheStorage and network.
// Before the fix: sign out, go offline, and /api/v1/me still answered 200 from
// cache with the CSRF token, so the app booted as if signed in.
{
  const { readFileSync } = await import('node:fs');
  const vm = await import('node:vm');
  // The committed file is a template; the server fills in VERSION and SHELL
  // as it serves it (server/src/Http/AppShell.php). Fill it the same way.
  const swTemplate = readFileSync(new URL('../sw.js', import.meta.url), 'utf8');
  const fillWorker = (version, shell) => swTemplate.replace(
    /\/\/ @generated-shell:start[\s\S]*?\/\/ @generated-shell:end/,
    `// @generated-shell:start\nconst VERSION = '${version}';\nconst SHELL = ${JSON.stringify(shell)};\n// @generated-shell:end`,
  );
  const swSource = fillWorker('bc-test00000000', ['/', '/assets/src/app/main.js']);
  assert('sw template: the committed file has the unversioned placeholder', /const VERSION = 'bc-unversioned';/.test(swTemplate) && swSource.includes("const VERSION = 'bc-test00000000';"));

  const makeWorker = (source = swSource, { failHandoffWrites = false } = {}) => {
    const stores = new Map(); // cache name -> Map(url -> Response)
    const keyOf = (r) => (typeof r === 'string' ? r : r.url);
    // Every cache call the worker makes is tracked, so settle() can wait for
    // its un-awaited writes to finish instead of guessing how long they take
    // (a fixed 5 ms wait failed when the machine was busy).
    const pending = new Set();
    const tracked = (fn) => (...args) => {
      const p = Promise.resolve().then(() => fn(...args));
      pending.add(p);
      p.finally(() => pending.delete(p)).catch(() => {});
      return p;
    };
    const caches = {
      keys: tracked(async () => [...stores.keys()]),
      delete: tracked(async (name) => stores.delete(name)),
      open: tracked(async (name) => {
        if (!stores.has(name)) stores.set(name, new Map());
        const m = stores.get(name);
        return {
          put: tracked(async (req, res) => {
            if (failHandoffWrites && name === 'bc-handoff') throw new Error('quota');
            m.set(keyOf(req), res);
          }),
          match: tracked(async (req) => (m.has(keyOf(req)) ? m.get(keyOf(req)).clone() : undefined)),
          delete: tracked(async (req) => m.delete(keyOf(req))),
        };
      }),
      match: tracked(async (req) => {
        for (const m of stores.values()) if (m.has(keyOf(req))) return m.get(keyOf(req)).clone();
        return undefined;
      }),
    };
    const handlers = {};
    const net = { respond: null }; // set per test: (url) => Response | Promise<Response>, or throws for offline
    const sandbox = {
      self: { addEventListener: (type, fn) => { handlers[type] = fn; }, skipWaiting: async () => {}, clients: { claim: async () => {}, matchAll: async () => [] }, registration: {}, location: { origin: 'https://cal.example.com' } },
      caches,
      clients: { matchAll: async () => [], openWindow: async () => {} },
      location: { origin: 'https://cal.example.com' },
      fetch: async (req) => net.respond(keyOf(req)),
      // A worker resolves relative URLs against its origin; Node's Request does not.
      Response, Headers, Request: class extends Request { constructor(u, o) { super(typeof u === 'string' && u.startsWith('/') ? 'https://cal.example.com' + u : u, o); } },
      URL, URLSearchParams, TextDecoder, Uint8Array, Promise, JSON, setTimeout, clearTimeout,
      console: { warn: () => {}, log: () => {} },
    };
    vm.runInNewContext(source, sandbox);
    const get = (path) => new Promise((resolve) => {
      const request = new Request('https://cal.example.com' + path);
      handlers.fetch({ request, respondWith: (p) => resolve(p) });
    });
    const message = async (data) => {
      let done = Promise.resolve();
      handlers.message({ data, waitUntil: (p) => { done = p; } });
      await done;
    };
    const postShare = (body, headers = {}) => new Promise((resolve) => {
      const request = new Request('https://cal.example.com/share', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', ...headers },
        body,
      });
      handlers.fetch({ request, respondWith: (p) => resolve(p) });
    });
    const handoff = async () => {
      const response = stores.get('bc-handoff')?.get('/__handoff');
      return response ? response.clone().json() : null;
    };
    // Let the worker's un-awaited cache writes land: wait until no cache call
    // is in flight, and none starts in the turn after (a write's next step).
    const settle = async () => {
      for (;;) {
        await Promise.allSettled([...pending]);
        await new Promise((r) => setImmediate(r));
        if (pending.size === 0) return;
      }
    };
    // Dispatch a fetch and report whether the worker took it (respondWith) or left it to the network.
    const takes = (path, mode) => {
      let took = false;
      const request = new Request('https://cal.example.com' + path);
      handlers.fetch({ request: mode ? { url: request.url, method: 'GET', mode } : request, respondWith: (p) => { took = true; p.catch(() => {}); } });
      return took;
    };
    const lifecycle = async (type) => {
      let done = Promise.resolve();
      handlers[type]({ waitUntil: (p) => { done = p; } });
      await done;
    };
    return { stores, net, get, postShare, handoff, message, settle, takes, lifecycle };
  };
  const json = (body, status = 200, { session = 'session-a', deadline = Math.floor(Date.now() / 1000) + 86400 } = {}) => new Response(JSON.stringify(body), {
    status,
    headers: {
      'Content-Type': 'application/json',
      'X-BetterCal-Session': session,
      'X-BetterCal-Offline-Until': String(deadline),
    },
  });
  const offline = () => { throw new TypeError('Failed to fetch'); };
  const apiCached = (w) => [...w.stores.entries()].filter(([k]) => k.endsWith('-api')).reduce((n, [, m]) => n + m.size, 0);

  // Native Web Share payloads stay local, one-shot, and bounded before the
  // app's quick-add parser ever sees them.
  {
    const w = makeWorker();
    const response = await w.postShare('title=Example&text=Meet+at+10', { Origin: 'null' });
    eq('sw share: native share is redirected to a bare root',
      [response.status, response.headers.get('Location')], [303, '/']);
    eq('sw share: ordinary fields are cached once for the app', await w.handoff(), {
      path: '/share', params: { title: 'Example', text: 'Meet at 10' },
    });

    await w.postShare('text=' + encodeURIComponent('x'.repeat(4000)), { Origin: 'null' });
    eq('sw share: exact decoded character limit is accepted', (await w.handoff()).params.text.length, 4000);
    await w.postShare('text=' + encodeURIComponent('é'.repeat(4000)), { Origin: 'null' });
    eq('sw share: the decoded character limit also accepts non-ASCII text', (await w.handoff()).params.text.length, 4000);
    await w.postShare('text=' + encodeURIComponent('x'.repeat(4001)), { Origin: 'null' });
    assert('sw share: decoded content over the limit replaces old content with a visible error',
      !!(await w.handoff()).error);

    await w.postShare('padding=' + 'x'.repeat(65536), { Origin: 'null' });
    assert('sw share: raw encoded body over the byte limit is refused visibly', !!(await w.handoff()).error);

    await w.postShare('text=old', { Origin: 'null' });
    const foreign = await w.postShare('text=foreign', { Origin: 'https://other.example' });
    eq('sw share: a cross-site form is rejected before touching a legitimate handoff',
      [foreign.status, await w.handoff()], [403, { path: '/share', params: { text: 'old' } }]);

    const concurrent = await Promise.all([
      w.postShare('text=first', { Origin: 'null' }),
      w.postShare('text=second', { Origin: 'null' }),
    ]);
    eq('sw share: a simultaneous second body is rejected instead of queued or overwriting',
      [concurrent.map((r) => r.status), await w.handoff()],
      [[303, 429], { path: '/share', params: { text: 'first' } }]);

    const noCache = makeWorker(swSource, { failHandoffWrites: true });
    const noCacheResponse = await noCache.postShare('text=Example', { Origin: 'null' });
    eq('sw share: cache failure still keeps content out of the URL and lands at root',
      [noCacheResponse.status, noCacheResponse.headers.get('Location'), await noCache.handoff()],
      [303, '/', null]);
  }

  // Baseline: the offline calendar still works while signed in.
  {
    const w = makeWorker();
    w.net.respond = () => json({ user: { email: 'owner@example.com' }, csrf: 'SECRET-CSRF' });
    await w.get('/api/v1/me');
    await w.settle();
    eq('sw: a signed-in API response is cached', apiCached(w), 1);
    w.net.respond = offline;
    const res = await w.get('/api/v1/me');
    eq('sw: signed in and offline, the cache still answers (offline calendar kept)', res.status, 200);
    eq('sw: offline response is explicitly read-only context', res.headers.get('X-BetterCal-Offline'), '1');
    assert('sw: cached /me does not retain a CSRF credential', !(await res.text()).includes('SECRET-CSRF'));
  }

  // The finding itself.
  {
    const w = makeWorker();
    w.net.respond = () => json({ user: { email: 'owner@example.com' }, csrf: 'SECRET-CSRF' });
    await w.get('/api/v1/me');
    await w.get('/api/v1/events?start=a&end=b');
    await w.settle();
    eq('sw: two private responses cached before sign-out', apiCached(w), 2);
    await w.message({ type: 'purge-api' });
    eq('sw: sign-out purge empties the API cache', apiCached(w), 0);
    w.net.respond = offline;
    const me = await w.get('/api/v1/me');
    eq('sw: after sign-out, offline /me is NOT answered from cache', me.status, 503);
    assert('sw: and carries no private data', !(await me.text()).includes('SECRET-CSRF'));
    eq('sw: after sign-out, offline events are not answered either', (await w.get('/api/v1/events?start=a&end=b')).status, 503);
  }

  // A legacy or poisoned shell cache must never satisfy a private API request.
  {
    const w = makeWorker();
    w.stores.set('bc-test00000000-shell', new Map());
    const shell = w.stores.get('bc-test00000000-shell');
    shell.set('https://cal.example.com/api/v1/me', json({ user: { email: 'wrong-cache@example.com' }, csrf: 'SHELL-CSRF' }));
    w.net.respond = offline;
    const me = await w.get('/api/v1/me');
    eq('sw: offline private API ignores a matching response in the shell cache', me.status, 503);
    assert('sw: shell cache poisoning cannot disclose its private-looking response', !(await me.text()).includes('SHELL-CSRF'));
  }

  // A 401 means the session is gone (expired, or revoked by a password reset).
  {
    const w = makeWorker();
    w.net.respond = () => json({ user: { email: 'owner@example.com' }, csrf: 'SECRET-CSRF' });
    await w.get('/api/v1/me');
    w.net.respond = () => json({ events: [{ title: 'Private dinner' }] });
    await w.get('/api/v1/events?start=a&end=b');
    await w.settle();
    w.net.respond = () => json({ error: { code: 'unauthorized' } }, 401);
    const res = await w.get('/api/v1/me');
    eq('sw: the 401 itself still reaches the page', res.status, 401);
    eq('sw: a 401 purges everything cached under the dead session', apiCached(w), 0);
  }

  // A delayed 401 from an earlier login must not erase the cache established
  // by a newer login while that old network request was still in flight.
  {
    const w = makeWorker();
    w.net.respond = () => json({ user: { email: 'old@example.test' }, csrf: 'OLD' });
    await w.get('/api/v1/me');
    await w.settle();
    let releaseOld401;
    w.net.respond = () => new Promise((resolve) => {
      releaseOld401 = () => resolve(json({ error: { code: 'unauthorized' } }, 401));
    });
    const stale401 = w.get('/api/v1/me');
    while (!releaseOld401) await new Promise((resolve) => setTimeout(resolve, 0));
    await w.message({ type: 'purge-api' });
    w.net.respond = () => json({ user: { email: 'new@example.test' }, csrf: 'NEW' }, 200, { session: 'session-b' });
    await w.get('/api/v1/me');
    await w.settle();
    releaseOld401();
    eq('sw: the stale 401 still reaches its original page request', (await stale401).status, 401);
    await w.settle();
    w.net.respond = offline;
    const currentMe = await w.get('/api/v1/me');
    eq('sw: a delayed old-session 401 cannot erase a newer login cache',
      [currentMe.status, (await currentMe.json()).user.email], [200, 'new@example.test']);
  }

  // A cached body already being read when logout lands must not escape after
  // the purge. The epoch protects delivery as well as later cache writes.
  {
    const w = makeWorker();
    w.net.respond = () => json({ user: { email: 'old@example.test' }, csrf: 'OLD' });
    await w.get('/api/v1/me');
    await w.settle();
    const apiStore = [...w.stores.entries()].find(([name]) => name.endsWith('-api'))[1];
    const meUrl = 'https://cal.example.com/api/v1/me';
    const body = new TextEncoder().encode(JSON.stringify({ user: { email: 'old@example.test' } }));
    const headers = new Headers({
      'Content-Type': 'application/json',
      'X-BetterCal-Session': 'session-a',
      'X-BetterCal-Offline-Until': String(Math.floor(Date.now() / 1000) + 86400),
    });
    let releaseRead;
    let cloneCount = 0;
    apiStore.set(meUrl, {
      clone: () => {
        const shouldPause = ++cloneCount === 1;
        return {
          status: 200, statusText: 'OK', headers,
          arrayBuffer: () => shouldPause
            ? new Promise((resolve) => { releaseRead = () => resolve(body.buffer); })
            : Promise.resolve(body.buffer),
        };
      },
    });
    w.net.respond = offline;
    const pending = w.get('/api/v1/me');
    while (!releaseRead) await new Promise((resolve) => setTimeout(resolve, 0));
    await w.message({ type: 'purge-api' });
    releaseRead();
    const result = await pending;
    eq('sw: logout invalidates a cached body already being read', result.status, 503);
    assert('sw: invalidated cached bytes are not returned', !(await result.text()).includes('old@example.test'));
  }

  // Filled in by the server: the app's files are precached and served from cache.
  {
    const w = makeWorker();
    w.net.respond = () => new Response('ok');
    await w.lifecycle('install');
    eq('sw: a versioned worker precaches its shell', [...w.stores.keys()], ['bc-test00000000-shell']);
    eq('sw: and answers app files and navigations itself', [w.takes('/assets/src/app/main.js'), w.takes('/add', 'navigate')], [true, true]);
  }

  // Served raw (a web server sent the template from disk): app files go to the
  // network, so no cache can pin a version that no deploy would ever replace.
  {
    const w = makeWorker(swTemplate);
    w.net.respond = () => new Response('ok');
    await w.lifecycle('install');
    eq('sw raw: nothing is precached', w.stores.size, 0);
    eq('sw raw: app files and navigations are left to the network', [w.takes('/assets/src/app/main.js'), w.takes('/add', 'navigate')], [false, false]);
    eq('sw raw: the API is still handled (offline calendar data, sign-out purge)', w.takes('/api/v1/me'), true);
  }

  // The race a plain delete leaves open: a response in flight at sign-out.
  {
    const w = makeWorker();
    w.net.respond = () => json({ user: { email: 'owner@example.com' }, csrf: 'SECRET-CSRF' });
    await w.get('/api/v1/me');
    await w.settle();
    let release;
    w.net.respond = () => new Promise((r) => { release = () => r(json({ events: [{ title: 'Private dinner' }] })); });
    const pending = w.get('/api/v1/events?start=a&end=b');
    await w.message({ type: 'purge-api' });
    release();
    await pending;
    await w.settle();
    eq('sw: a response that was in flight at sign-out is not cached afterwards', apiCached(w), 0);
    w.net.respond = (url) => url.endsWith('/me')
      ? json({ user: { email: 'owner@example.com' }, csrf: 'NEW-CSRF' }, 200, { session: 'session-b' })
      : json({ events: [] }, 200, { session: 'session-b' });
    await w.get('/api/v1/me');
    await w.get('/api/v1/events?start=a&end=b');
    await w.settle();
    eq('sw: caching resumes for the next session', apiCached(w), 2);
  }

  // A stale response paused while its private JSON copy is being stripped
  // must not adopt the epoch of a newer login and replace that account.
  {
    const w = makeWorker();
    w.net.respond = () => json({ user: { email: 'old@example.test' }, csrf: 'OLD' });
    await w.get('/api/v1/me');
    await w.settle();
    let releaseCopy;
    const slowOldResponse = {
      status: 200,
      statusText: 'OK',
      ok: true,
      headers: json({}, 200, { session: 'session-a' }).headers,
      clone: () => ({
        json: () => new Promise((resolve) => { releaseCopy = () => resolve({ user: { email: 'old@example.test' }, csrf: 'OLD' }); }),
      }),
    };
    w.net.respond = () => slowOldResponse;
    const stale = w.get('/api/v1/me');
    while (!releaseCopy) await new Promise((resolve) => setTimeout(resolve, 0));
    await w.message({ type: 'purge-api' });
    w.net.respond = () => json({ user: { email: 'new@example.test' }, csrf: 'NEW' }, 200, { session: 'session-b' });
    await w.get('/api/v1/me');
    await w.settle();
    releaseCopy();
    await stale;
    await w.settle();
    w.net.respond = offline;
    const me = await (await w.get('/api/v1/me')).json();
    eq('sw: a stale copied response cannot replace a newer login', me.user.email, 'new@example.test');
  }

  // A different login gets a different opaque cache identity. The worker
  // drops the prior account before storing the new /me.
  {
    const w = makeWorker();
    w.net.respond = () => json({ user: { email: 'first@example.test' }, csrf: 'FIRST' });
    await w.get('/api/v1/me');
    await w.settle();
    w.net.respond = () => json({ user: { email: 'second@example.test' }, csrf: 'SECOND' }, 200, { session: 'session-b' });
    await w.get('/api/v1/me');
    await w.settle();
    w.net.respond = offline;
    const me = await w.get('/api/v1/me');
    eq('sw: identity change leaves only the new login cache', (await me.json()).user.email, 'second@example.test');
  }

  // The absolute offline deadline wins even when the browser never observed
  // the server-side revocation while online.
  {
    const w = makeWorker();
    w.net.respond = () => json({ user: { email: 'owner@example.test' }, csrf: 'SECRET' }, 200, {
      deadline: Math.floor(Date.now() / 1000) - 1,
    });
    await w.get('/api/v1/me');
    await w.settle();
    w.net.respond = offline;
    eq('sw: expired private cache is refused and purged', (await w.get('/api/v1/me')).status, 503);
    eq('sw: expiry clears all private entries', apiCached(w), 0);
  }

  // Capability-management and integration endpoints are never offline data.
  {
    const w = makeWorker();
    w.net.respond = () => json({ user: { email: 'owner@example.test' }, csrf: 'SECRET' });
    await w.get('/api/v1/me');
    await w.get('/api/v1/outfeeds');
    await w.get('/api/v1/google/status');
    await w.settle();
    eq('sw: sensitive integration endpoints are excluded from the private cache', apiCached(w), 1);
    w.net.respond = offline;
    eq('sw: an offline online-only endpoint fails without purging saved calendar data',
      [(await w.get('/api/v1/plugins')).status, apiCached(w)], [503, 1]);
    eq('sw: a normal offline cache miss also preserves the signed-in cache',
      [(await w.get('/api/v1/events?start=uncached&end=range')).status, apiCached(w)], [503, 1]);
    eq('sw: saved /me remains available after unrelated offline misses', (await w.get('/api/v1/me')).status, 200);
  }

  // The calendar list is needed to paint offline, but subscription addresses
  // are bearer-like capabilities and are removed from its cached copy.
  {
    const w = makeWorker();
    w.net.respond = (url) => url.endsWith('/me')
      ? json({ user: { email: 'owner@example.test' }, csrf: 'SECRET' })
      : json({ calendars: [{ id: 1, name: 'Sample feed', sourceUrl: 'https://feeds.example.test/private.ics?key=secret' }] });
    await w.get('/api/v1/me');
    await w.get('/api/v1/calendars');
    await w.settle();
    w.net.respond = offline;
    const calendars = await (await w.get('/api/v1/calendars')).json();
    eq('sw: cached calendar metadata removes subscription capabilities', calendars.calendars[0].sourceUrl, null);
  }

  // The shell is not private and must survive, or signing out breaks offline load.
  {
    const w = makeWorker();
    w.net.respond = () => new Response('body{}', { status: 200 });
    await w.get('/assets/styles/app.css');
    await w.settle();
    await w.message({ type: 'purge-api' });
    assert('sw: the purge leaves the app shell cache alone', [...w.stores.keys()].some((k) => k.endsWith('-shell') && w.stores.get(k).size === 1));
    assert('sw: unrelated messages are ignored', await w.message({ type: 'something-else' }).then(() => true));
  }
}

console.log('');

// --- relationship filter: hidden-by-filter day counts ------------------------
{
  const showRel = { planned: true, maybe: true, available: false, context: false };
  // Local wall-clock instants, so the suite reads the same in every zone it runs under.
  const at = (d, h) => new Date(2026, 8, d, h).toISOString();
  const occs = [
    { instanceId: 'a', relationship: 'planned', allDay: false, start: at(21, 10), end: at(21, 11) },
    { instanceId: 'b', relationship: 'available', allDay: false, start: at(21, 18), end: at(21, 19) },
    { instanceId: 'c', relationship: 'context', allDay: true, start: '2026-09-22', end: '2026-09-24' },
    { instanceId: 'd', relationship: 'context', allDay: false, start: at(25, 22), end: at(26, 0) },
    { instanceId: 'e', relationship: 'hidden', allDay: false, start: at(27, 10), end: at(27, 11) },
  ];
  assert('relShown: kinds switched on pass', relShown(occs[0], showRel));
  assert('relShown: kinds switched off do not', !relShown(occs[1], showRel));
  assert('relShown: hidden-by-choice is not a filter matter', relShown(occs[4], showRel));
  const counts = hiddenDayCounts(occs, showRel);
  eq('hidden days: one timed available on the 21st', counts.get('2026-09-21'), 1);
  eq('hidden days: all-day context covers 22 and 23, not the exclusive 24th', [counts.get('2026-09-22'), counts.get('2026-09-23'), counts.get('2026-09-24')], [1, 1, undefined]);
  eq('hidden days: a timed end at midnight stays on its own day', [counts.get('2026-09-25'), counts.get('2026-09-26')], [1, undefined]);
  eq('hidden days: nothing when every kind is on', hiddenDayCounts(occs, { planned: true, maybe: true, available: true, context: true }).size, 0);
  eq('saved view: no hideRel means show all', showRelFromConfig({}), { planned: true, maybe: true, available: true, context: true });
  eq('saved view: hideRel switches those kinds off', showRelFromConfig({ hideRel: ['context', 'available'] }), { planned: true, maybe: true, available: false, context: false });
  eq('saved view: a view saved before the filter existed shows all', showRelFromConfig(null), { planned: true, maybe: true, available: true, context: true });
}

// --- context tokens: what the day's header says --------------------------------
{
  const at = (d, h, m = 0) => new Date(2026, 8, d, h, m).toISOString();
  const weather = { instanceId: 'w', relationship: 'context', allDay: true, start: '2026-09-20', end: '2026-09-21', title: 'Portland, OR: 72/58 fog' };
  const aqi = { instanceId: 'q', relationship: 'context', allDay: true, start: '2026-09-20', end: '2026-09-21', title: 'Portland, OR: AQI 54 (Moderate)' };
  const sunset = { instanceId: 's', relationship: 'context', allDay: false, start: at(20, 19, 10), end: at(20, 19, 20), title: 'Sunset' };
  const tide = { instanceId: 't', relationship: 'context', allDay: false, start: at(20, 14, 15), end: at(20, 14, 25), title: 'Low tide: 0.8 ft' };
  const away = { instanceId: 'a', relationship: 'context', allDay: true, start: '2026-09-19', end: '2026-09-22', title: 'Sam in Denver' };
  const plan = { instanceId: 'p', relationship: 'planned', allDay: false, start: at(20, 10), end: at(20, 11), title: 'Dentist' };
  const plug = { instanceId: 'g', relationship: 'context', allDay: true, start: '2026-09-20', end: '2026-09-21', title: 'Rain likely (60%)', icon: 'rain', tzid: 'UTC' };
  eq('context token: weather pair with a condition word', [contextToken(weather).icon, contextToken(weather).text], ['cloud', '72/58']);
  eq('context token: a long-range normal keeps its tilde and gets the thermometer', [contextToken({ allDay: true, title: 'Portland, OR: ~80/58 avg' }).icon, contextToken({ allDay: true, title: 'Portland, OR: ~80/58 avg' }).text], ['thermometer', '~80/58']);
  eq('context token: clear skies are the sun; wind is the air icon', [contextToken({ allDay: true, title: '96/66 clear' }).icon, contextToken({ allDay: true, title: '70/55 windy' }).icon], ['sun', 'air']);
  {
    const at = new Date(2026, 10, 7, 15, 35);
    const tide = { allDay: false, title: 'Low tide -0.4 ft', start: at.toISOString(), end: at.toISOString(), tzid: 'UTC', icon: 'tideLow' };
    eq('context token: a UTC-stored plugin moment reads on the device clock', contextToken(tide).time, compactTime(at, null));
  }
  eq('context token: AQI keeps the number, the category goes to the tooltip', [contextToken(aqi).icon, contextToken(aqi).text], ['air', '54']);
  eq('context token: a sunset is its icon and time', [contextToken(sunset).icon, contextToken(sunset).text, contextToken(sunset).time], ['sunset', '', '7:10p']);
  eq('context token: low tide keeps its height', [contextToken(tide).icon, contextToken(tide).text], ['tideLow', '0.8 ft']);
  eq('context token: unrecognized text keeps its words and the calendar square', [contextToken(away).icon, contextToken(away).text], [null, 'Sam in Denver']);
  eq('context token: a holiday calendar gets the flag', contextToken({ allDay: true, title: 'Labor Day' }, { name: 'US Holidays' }).icon, 'flag');
  eq('context token: a plugin tide keeps its icon and shows the height', [contextToken({ allDay: false, start: at(20, 14, 0), end: at(20, 14, 0), title: 'High tide 5.2 ft', icon: 'tideHigh' }).icon, contextToken({ allDay: false, start: at(20, 14, 0), end: at(20, 14, 0), title: 'High tide 5.2 ft', icon: 'tideHigh' }).text], ['tideHigh', '5.2 ft']);
  eq('context token: a plugin weather day keeps its icon and shows the pair', [contextToken({ allDay: true, title: '64°/57° rain', icon: 'rain' }).icon, contextToken({ allDay: true, title: '64°/57° rain', icon: 'rain' }).text], ['rain', '64/57']);
  eq('context token: plugin AQI shows the number', contextToken({ allDay: true, title: 'AQI 54 (Moderate)', icon: 'air' }).text, '54');
  eq('context token: the plugin icon wins and a long parenthetical is dropped', [contextToken(plug).icon, contextToken(plug).text], ['rain', 'Rain likely']);
  eq('context token: no zone suffix in the device zone', contextToken(sunset).zone, '');
  assert('context title: a brief moment shows one time, no range', contextTitle(sunset).startsWith('Sunset · ') && !contextTitle(sunset).includes(' to '));
  const byDay = contextByDay([weather, aqi, sunset, away, plan]);
  eq('context by day: all-day first (values before named days), then moments by time; spans cover each day', byDay.get('2026-09-20').map((o) => o.instanceId), ['w', 'q', 'a', 's']);
  eq('context by day: the span reaches its last day and not the exclusive end', [byDay.has('2026-09-21'), byDay.has('2026-09-22')], [true, false]);
  eq('context by day: plans are not context', byDay.get('2026-09-20').some((o) => o.instanceId === 'p'), false);
  eq('withoutContext: leaves only the plans', withoutContext([weather, sunset, plan]).map((o) => o.instanceId), ['p']);
  // A holiday is a day label, written with the date, not a token.
  const cals = { 1: { name: 'US Holidays' }, 2: { name: 'Portland weather' } };
  const hol = { instanceId: 'h', calendarId: 1, relationship: 'context', allDay: true, start: '2026-10-12', end: '2026-10-13', title: 'Columbus Day' };
  const wx = { ...weather, calendarId: 2, start: '2026-10-12', end: '2026-10-13' };
  eq('day labels: a holiday calendar day is a label on its day', [...dayLabelsByDay([hol, wx], cals).get('2026-10-12')].map((o) => o.instanceId), ['h']);
  eq('day labels: and not a context token there', contextByDay([hol, wx], cals).get('2026-10-12').map((o) => o.instanceId), ['w']);
  eq('day labels: without calendars, nothing is a label', [dayLabelsByDay([hol], null).size, contextByDay([hol]).get('2026-10-12').length], [0, 1]);
  eq('day labels: a timed holiday-calendar item stays a moment', isDayLabel({ ...hol, allDay: false }, cals), false);
  eq('day labels: the words drop a source prefix and join', dayLabelText([{ title: 'US: Columbus Day' }, { title: "Indigenous Peoples' Day" }]), "Columbus Day · Indigenous Peoples' Day");
  // A place-bound moment in another zone keeps its own clock and names the zone.
  const zoned = { allDay: false, start: '2026-09-20T19:10:00-07:00', end: '2026-09-20T19:20:00-07:00', title: 'Sunset', tzid: 'America/Los_Angeles' };
  const tk = contextToken(zoned);
  const here = Intl.DateTimeFormat().resolvedOptions().timeZone;
  if (here === 'America/Los_Angeles') eq('context zone: same clock, no suffix', [tk.time, tk.zone], ['7:10p', '']);
  else eq('context zone: own clock with the zone named', [tk.time, tk.zone], ['7:10p', 'PDT']);
}


// --- ReDoS-safe text handling (scan 2026-09-23, F22, F26, F27/F28) -----------
{
  let t0 = Date.now();
  hasHtml('<a'.repeat(40000));
  assert('hasHtml: 40,000 "<a" with no ">" in under 200ms', Date.now() - t0 < 200);
  t0 = Date.now();
  stripToText('<b>x</b>' + '<script x>'.repeat(20000));
  assert('stripToText: 20,000 unclosed "<script" in under 300ms', Date.now() - t0 < 300);
  eq('stripToText: closed script dropped, text kept', 'a b', stripToText('<p>a</p><script>evil()</script><p>b</p>').replace(/\s+/g, ' ').trim());
  assert('hasHtml: still finds markup, still ignores prose', hasHtml('see <b>x</b>') && !hasHtml('a < b <3'));
  eq('splitUrlTail: punctuation is prose', ['https://x.org/a', ').'], splitUrlTail('https://x.org/a).'));
  t0 = Date.now();
  splitUrlTail('https://x.org/' + ')'.repeat(2000));
  assert('splitUrlTail: a long punctuation run is linear', Date.now() - t0 < 50);
  eq('splitUrlTail: longer than 2,048 is not a link', null, splitUrlTail('https://x.org/' + 'a'.repeat(3000))[0]);
  const m1 = safeLinkMatcher.exec('see https://example.com/path?q=1 now');
  eq('safeLinkMatcher: web address in group 1', 'https://example.com/path?q=1', m1 && m1[1]);
  const m2 = safeLinkMatcher.exec('mail me@example.org');
  eq('safeLinkMatcher: email in group 2', 'me@example.org', m2 && m2[2]);
  t0 = Date.now();
  safeLinkMatcher.exec('http://a' + '.'.repeat(30000));
  safeLinkMatcher.exec('www.' + 'a('.repeat(1000) + ' x');
  safeLinkMatcher.exec(('https://a.example/' + 'x'.repeat(40) + ' ').repeat(3000));
  safeLinkMatcher.exec('a'.repeat(200000));
  assert('safeLinkMatcher: crafted and long input returns promptly', Date.now() - t0 < 400);
}

// --- when fields: typed times and dates (whenparse.js) ---
{
  const clk = (t, o) => { const r = resolveClock(parseClockText(t), o); return r == null ? null : minsToHHMM(r); };
  eq('clock: 7pm', clk('7pm'), '19:00');
  eq('clock: 7 p.m.', clk('7 p.m.'), '19:00');
  eq('clock: 7p', clk('7p'), '19:00');
  eq('clock: 730p', clk('730p'), '19:30');
  eq('clock: 7:30 am', clk('7:30 am'), '07:30');
  eq('clock: 12am is midnight', clk('12am'), '00:00');
  eq('clock: 12pm is noon', clk('12pm'), '12:00');
  eq('clock: bare 12 is noon', clk('12'), '12:00');
  eq('clock: noon', clk('noon'), '12:00');
  eq('clock: midnight', clk('midnight'), '00:00');
  eq('clock: 19:30', clk('19:30'), '19:30');
  eq('clock: 1930', clk('1930'), '19:30');
  eq('clock: 07:00 is morning', clk('07:00', { current: 1000 }), '07:00');
  eq('clock: 7.15pm', clk('7.15pm'), '19:15');
  eq('clock: bare 7, start in the afternoon keeps pm', clk('7', { role: 'start', current: 17 * 60 }), '19:00');
  eq('clock: bare 7, start in the morning keeps am', clk('7', { role: 'start', current: 9 * 60 }), '07:00');
  eq('clock: bare 7 on a 24-hour clock', clk('7', { role: 'start', current: 17 * 60, h24: true }), '07:00');
  eq('clock: bare 8, end after a 7pm start', clk('8', { role: 'end', after: 19 * 60 }), '20:00');
  eq('clock: bare 11, end after a 10am start', clk('11', { role: 'end', after: 600 }), '11:00');
  eq('clock: bare 1, end after a 10pm start wraps to am', clk('1', { role: 'end', after: 22 * 60 }), '01:00');
  eq('clock: 25:00 rejected', clk('25:00'), null);
  eq('clock: 7:75 rejected', clk('7:75'), null);
  eq('clock: 13pm rejected', clk('13pm'), null);
  eq('clock: words rejected', clk('soon'), null);
  eq('hhmmToMins', hhmmToMins('19:45'), 1185);
  const dk = (t) => parseDateText(t, { todayKey: '2026-09-26', currentKey: '2026-09-26' });
  eq('date: display text round-trips', dk('Sat, Sep 26, 2026'), '2026-09-26');
  eq('date: weekday name is the next one', dk('fri'), '2026-10-02');
  eq('date: tomorrow', dk('tomorrow'), '2026-09-27');
  eq('date: m/d', dk('10/5'), '2026-10-05');
  eq('date: month day', dk('oct 5'), '2026-10-05');
  eq('date: bare day stays in the shown month', dk('3'), '2026-09-03');
  eq('date: ordinal day', dk('30th'), '2026-09-30');
  eq('date: day past the month end rejected', dk('31'), null);
  eq('date: weekday then date', dk('thu oct 8'), '2026-10-08');
  eq('date: nonsense rejected', dk('whenever'), null);
  eq('duration: 30 min', durationLabel(30), '30 min');
  eq('duration: 1 hr', durationLabel(60), '1 hr');
  eq('duration: 1.5 hr', durationLabel(90), '1.5 hr');
  eq('duration: 2 hr 15 min', durationLabel(135), '2 hr 15 min');
  eq('duration: 1 day', durationLabel(1440), '1 day');
}

// --- Google Maps links: the place by name, anchored at its coordinates ---
eq('gmaps: name and coordinates open the place there',
  gmapsUrl('Harbor Hall & Bar, 100 Main Street, Chicago', 41.8781136, -87.6297982),
  'https://www.google.com/maps/search/Harbor+Hall+%26+Bar%2C+100+Main+Street%2C+Chicago/@41.8781136,-87.6297982,17z');
eq('gmaps: a slash in the name stays inside the query', gmapsUrl('Bar 1/2', 1, 2), 'https://www.google.com/maps/search/Bar+1%2F2/@1,2,17z');
eq('gmaps: pending text falls back to the coordinates', gmapsUrl("Location available once RSVP'd", 45.5, -122.6), 'https://www.google.com/maps/search/?api=1&query=45.5,-122.6');
eq('gmaps: coordinate text uses the coordinates', gmapsUrl('45.5, -122.6', 45.5, -122.6), 'https://www.google.com/maps/search/?api=1&query=45.5,-122.6');
eq('gmaps: text alone is a search', gmapsUrl('Example Cafe', null, null), 'https://www.google.com/maps/search/?api=1&query=Example%20Cafe');

// A change anywhere (the change cursor moving) used to strip every fetched
// record, so an open event view lost its description and own time zone until
// something refetched them. Now they stay, marked stale; an edit to one event
// still strips that event's record.
{
  const saved = new Map(storeState.occ);
  storeState.occ.clear();
  const base = { instanceId: 'r1', eventId: 1, start: '2026-03-10T17:00:00Z', end: '2026-03-10T18:00:00Z', title: 'Review' };
  storeState.occ.set('r1', { ...base, full: true, description: 'Agenda here', tzid: 'Europe/Lisbon' });
  storeState.occ.set('r2', { ...base, instanceId: 'r2', eventId: 2, full: true, description: 'Other notes' });
  invalidateRecords();
  const r1 = storeState.occ.get('r1');
  eq('records: a change anywhere keeps the fetched details on screen', [r1.description, r1.tzid], ['Agenda here', 'Europe/Lisbon']);
  assert('records: ... and marks them stale for a refetch', r1.stale === true && r1.full === true);
  mergeWindow('2026-03-10T00:00:00Z', '2026-03-11T00:00:00Z', [{ ...base }]);
  const merged = storeState.occ.get('r1');
  assert('records: a window refresh carries the details and the stale mark', merged.description === 'Agenda here' && merged.stale === true);
  storeState.occ.set('r2', { ...base, instanceId: 'r2', eventId: 2, full: true, description: 'Other notes' });
  invalidateRecords(2);
  const r2 = storeState.occ.get('r2');
  assert('records: editing one event still strips that event’s record', !r2.full && !('description' in r2));
  storeState.occ.clear();
  for (const [k, v] of saved) storeState.occ.set(k, v);
}

// An event in another year says which: a 2011 event read as this October's.
{
  const now = new Date(2026, 9, 13, 12);
  const at = (y, m, d, h = 0) => new Date(y, m - 1, d, h);
  assert('range: a moment has one time, not a range', !fmtRange(at(2026, 10, 8, 15), at(2026, 10, 8, 15), false, now).includes(' to '));
  assert('year: this year needs none', !fmtRange(at(2026, 10, 8, 10), at(2026, 10, 8, 16), false, now).includes('2026'));
  assert('year: an old event names its year', fmtRange(at(2011, 10, 8, 10), at(2011, 10, 8, 16), false, now).includes('2011'));
  assert('year: next year too', fmtRange(at(2027, 3, 1), at(2027, 3, 4), true, now).includes('2027'));
  eq('year: the year is its own piece, to stand out', fmtRangeParts(at(2011, 10, 8, 10), at(2011, 10, 8, 16), false, now).filter((p) => p.year).map((p) => p.text), ['2011']);
  assert('year: across New Year both dates say theirs', otherYear(at(2026, 12, 31, 22), at(2027, 1, 1, 2), false, now));
  assert('year: an all-day event ending at midnight Jan 1 is still this year', !otherYear(at(2026, 12, 31), at(2027, 1, 1), true, now));
}

// An event opened from Activity can sit on a hidden calendar, which the
// window never returns; the refresh that followed the jump dropped it and
// left the open panel blank. It stays while it is open, and only then.
{
  const saved = new Map(storeState.occ);
  const savedPop = storeState.popover;
  storeState.occ.clear();
  const held = { instanceId: 'h1', eventId: 7, calendarId: 9, start: '2026-03-10T17:00:00Z', end: '2026-03-10T18:00:00Z', title: 'Example meeting' };
  insertOccurrence(held);
  storeState.popover = { instanceId: 'h1', anchorRect: null };
  mergeWindow('2026-03-10T00:00:00Z', '2026-03-11T00:00:00Z', []);
  assert('held: the open event survives a window that lacks it', storeState.occ.has('h1'));
  mergeWindow('2026-03-10T00:00:00Z', '2026-03-11T00:00:00Z', [{ ...held, title: 'Example meeting, moved' }]);
  eq('held: a window that has it replaces it as usual', storeState.occ.get('h1').title, 'Example meeting, moved');
  insertOccurrence(held);
  storeState.popover = null;
  mergeWindow('2026-03-10T00:00:00Z', '2026-03-11T00:00:00Z', []);
  assert('held: once closed, a window that lacks it drops it', !storeState.occ.has('h1'));
  storeState.popover = savedPop;
  storeState.occ.clear();
  for (const [k, v] of saved) storeState.occ.set(k, v);
}

// Why an event looks the way it does (the hover card's italic lines).
{
  const later = Date.parse('2026-03-10T12:00:00Z');
  const base = { title: 'Review', start: '2026-03-11T17:00:00Z', end: '2026-03-11T18:00:00Z', relationship: 'planned' };
  eq('why: a plain planned future event needs no explanation', formattingReasons(base, later), []);
  eq('why: a highlight names the filter and where it matched',
    formattingReasons({ ...base, highlighted: true, filterReason: { type: 'keyword', pattern: 'dinner', field: 'description' } }, later),
    ['Underlined: highlighted by your “dinner” filter (matched in the description)']);
  eq('why: a dimmed event from a plain-language filter',
    formattingReasons({ ...base, dimmed: true, filterReason: { type: 'prompt' } }, later),
    ['Grayed out: dimmed by one of your plain-language filters']);
  eq('why: a plain-language filter is named by its words',
    formattingReasons({ ...base, dimmed: true, filterReason: { type: 'prompt', pattern: 'Only the big public holidays' } }, later),
    ['Grayed out: dimmed by your plain-language filter “Only the big public holidays”']);
  eq('why: a regex filter shows its pattern as one',
    formattingReasons({ ...base, highlighted: true, filterReason: { type: 'regex', pattern: 'a|b', field: 'title' } }, later)[0],
    'Underlined: highlighted by your /a|b/ filter (matched in the title)');
  eq('why: a maybe that already ended',
    formattingReasons({ ...base, relationship: 'maybe' }, Date.parse('2026-03-12T00:00:00Z')),
    ['Dashed outline: a maybe', 'Faded: already ended']);
  eq('why: happening now, new, cancelled',
    formattingReasons({ ...base, isNew: true, status: 'cancelled' }, Date.parse('2026-03-11T17:30:00Z')),
    ['Struck through: cancelled', 'New: arrived in the last day', 'Gold ring: happening now']);
}

// --- time zone search (ZonePicker) -----------------------------------------------
{
  const zones = zoneIndex([]);
  const first = (q) => (filterZones(zones, q)[0] || {}).z;
  const finds = (q, z) => filterZones(zones, q).some((e) => e.z === z);
  eq('zones: a city, typed loosely', first('los ang'), 'America/Los_Angeles');
  assert('zones: a city with accents or none', finds('sao paulo', 'America/Sao_Paulo'));
  assert('zones: a country by name', finds('portugal', 'Europe/Lisbon'));
  assert('zones: a country by code', finds('nz', 'Pacific/Auckland'));
  assert('zones: a region note from the tz database', finds('mountain', 'America/Denver'));
  eq('zones: an offset, written as people write it', parseOffset('UTC -7'), -420);
  eq('zones: with minutes', parseOffset('+5:30'), 330);
  eq('zones: GMT works too', parseOffset('gmt+1'), 60);
  eq('zones: not an offset', parseOffset('utc'), null);
  assert('zones: an offset finds the zones on it now', filterZones(zones, 'UTC+5:30').some((e) => e.z === 'Asia/Kolkata' || e.z === 'Asia/Calcutta'));
  assert('zones: an offset finds only those', filterZones(zones, '+5:30').every((e) => e.off === 330));
  assert('zones: a city by its current name, whatever the browser calls the zone', filterZones(zones, 'kolkata').length > 0);
  assert('zones: cities that start with it come first', filterZones(zones, 'lond')[0].city === 'London');
  eq('zones: nothing for nonsense', filterZones(zones, 'qqzzx').length, 0);
}

// --- repeats in words, presets and ends (lib/repeat.js, #165) ----------------------
{
  const thu = new Date(2026, 6, 30); // Thursday Jul 30, 2026: the fifth (and last) Thursday
  const tue = new Date(2026, 7, 4); // Tuesday Aug 4: the first
  const said = (r, o) => describeRepeat(r, o);
  eq('repeat: daily', said('FREQ=DAILY'), 'Daily');
  eq('repeat: every 3 days', said('FREQ=DAILY;INTERVAL=3'), 'Every 3 days');
  eq('repeat: weekly on a day', said('FREQ=WEEKLY;BYDAY=TU'), 'Weekly on Tuesday');
  eq('repeat: weekly takes the start day', said('FREQ=WEEKLY', { start: thu }), 'Weekly on Thursday');
  eq('repeat: two days', said('FREQ=WEEKLY;BYDAY=MO,TH'), 'Weekly on Monday and Thursday');
  eq('repeat: three days go short', said('FREQ=WEEKLY;BYDAY=MO,WE,FR'), 'Weekly on Mon, Wed and Fri');
  eq('repeat: weekdays', said('FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR'), 'Every weekday');
  eq('repeat: weekends', said('FREQ=WEEKLY;BYDAY=SA,SU'), 'Every weekend');
  eq('repeat: every other week', said('FREQ=WEEKLY;INTERVAL=2;BYDAY=SA'), 'Every 2 weeks on Saturday');
  eq('repeat: monthly on a day', said('FREQ=MONTHLY;BYMONTHDAY=15'), 'Monthly on day 15');
  eq('repeat: monthly takes the start day', said('FREQ=MONTHLY', { start: tue }), 'Monthly on day 4');
  eq('repeat: first Monday', said('FREQ=MONTHLY;BYDAY=1MO'), 'Monthly on the first Monday');
  eq('repeat: last Friday', said('FREQ=MONTHLY;BYDAY=-1FR'), 'Monthly on the last Friday');
  eq('repeat: last day', said('FREQ=MONTHLY;BYMONTHDAY=-1'), 'Monthly on the last day');
  eq('repeat: last weekday', said('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1'), 'Monthly on the last weekday');
  eq('repeat: quarterly', said('FREQ=MONTHLY;INTERVAL=3'), 'Every 3 months');
  assert('repeat: yearly takes the start day', /^Yearly on /.test(said('FREQ=YEARLY', { start: thu })));
  eq('repeat: count', said('FREQ=WEEKLY;BYDAY=SU;COUNT=10'), 'Weekly on Sunday, 10 times');
  assert('repeat: until', /^Weekly on Tuesday, until /.test(said('FREQ=WEEKLY;BYDAY=TU;UNTIL=20261215')));
  eq('repeat: ends left out on request', said('FREQ=WEEKLY;BYDAY=TU;COUNT=3', { ends: false }), 'Weekly on Tuesday');
  eq('repeat: a rule it cannot say', said('FREQ=YEARLY;BYMONTH=3;BYDAY=2SU'), null);
  eq('repeat: hourly is not ours', describable('FREQ=HOURLY'), false);
  eq('repeat: the sentence', repeatSentence('FREQ=WEEKLY;BYDAY=MO'), 'Repeats weekly on Monday');
  eq('repeat: the sentence falls back', repeatSentence('FREQ=YEARLY;BYWEEKNO=20'), 'Repeats');
  eq('repeat: parts and join', joinRule(ruleParts('rrule:until=20261215;byday=TU;freq=weekly')), 'FREQ=WEEKLY;BYDAY=TU;UNTIL=20261215');
  eq('repeat: base drops the end', ruleBase('FREQ=WEEKLY;COUNT=4;BYDAY=TU'), 'FREQ=WEEKLY;BYDAY=TU');

  const keys = (d) => repeatPresets(d).map((p) => p.key);
  eq('presets: a fifth weekday offers only last', keys(thu).filter((k) => k.startsWith('monthly-')), ['monthly-last', 'monthly-day']);
  eq('presets: a first weekday offers first', keys(tue).filter((k) => k.startsWith('monthly-')), ['monthly-nth', 'monthly-day']);
  const fourthLast = new Date(2026, 1, 26); // Thursday Feb 26: fourth and last
  eq('presets: a fourth that is last offers both', keys(fourthLast).filter((k) => k.startsWith('monthly-')), ['monthly-nth', 'monthly-last', 'monthly-day']);
  eq('presets: the weekly one names the day', repeatPresets(tue).find((p) => p.key === 'weekly').label, 'Weekly on Tuesday');
  eq('presets: monthly nth rule', repeatPresets(tue).find((p) => p.key === 'monthly-nth').rrule, 'FREQ=MONTHLY;BYDAY=1TU');
  eq('presets: a rule with an end is still its preset', (presetOf('FREQ=WEEKLY;BYDAY=TU;COUNT=5', tue) || {}).key, 'weekly');
  eq('presets: none for a rule that is not one', presetOf('FREQ=DAILY;INTERVAL=3', tue), null);
  eq('presets: weekOfMonth', weekOfMonth(thu), { nth: 5, last: true });

  eq('ends: until on a date series', withRepeatEnd('FREQ=DAILY', { kind: 'until', until: '2026-12-15' }, true), 'FREQ=DAILY;UNTIL=20261215');
  assert('ends: until on a timed series is an instant', /^FREQ=DAILY;UNTIL=2026121[56]T\d{4}00Z$/.test(withRepeatEnd('FREQ=DAILY', { kind: 'until', until: '2026-12-15' }, false)));
  eq('ends: count replaces until', withRepeatEnd('FREQ=DAILY;UNTIL=20261215', { kind: 'count', count: 4 }, true), 'FREQ=DAILY;COUNT=4');
  eq('ends: never clears it', withRepeatEnd('FREQ=DAILY;COUNT=4', { kind: 'never' }, true), 'FREQ=DAILY');
  eq('ends: read back', repeatEnd('FREQ=DAILY;COUNT=4'), { kind: 'count', until: '', count: 4 });
  eq('ends: until read back', repeatEnd('FREQ=DAILY;UNTIL=20261215').until, '2026-12-15');
  eq('ends: all day makes UNTIL a date', retypeUntil(withRepeatEnd('FREQ=DAILY', { kind: 'until', until: '2026-12-15' }, false), true), 'FREQ=DAILY;UNTIL=20261215');
  eq('ends: no end, nothing to retype', retypeUntil('FREQ=DAILY', true), 'FREQ=DAILY');
}

// The sidebar's width (#129, web/src/lib/sidebarwidth.js).
{
  eq('sidebar width: clamped to 200-420', [clampSidebarWidth(100), clampSidebarWidth(300.4), clampSidebarWidth(900), clampSidebarWidth('x')], [200, 300, 420, 256]);
  const store = new Map();
  const had = globalThis.localStorage;
  globalThis.localStorage = { getItem: (k) => (store.has(k) ? store.get(k) : null), setItem: (k, v) => store.set(k, String(v)), removeItem: (k) => store.delete(k) };
  eq('sidebar width: nothing stored reads as the default', readSidebarWidth(), 256);
  saveSidebarWidth(333);
  eq('sidebar width: a saved width reads back', [readSidebarWidth(), store.get('bc-sidebar-w')], [333, '333']);
  saveSidebarWidth(256);
  eq('sidebar width: the default forgets the stored width', store.has('bc-sidebar-w'), false);
  store.set('bc-sidebar-w', '9999');
  eq('sidebar width: a stored width out of range is clamped', readSidebarWidth(), 420);
  globalThis.localStorage = had;
}

// Quick add's strip -> what Create saves (web/src/lib/quickrange.js).
{
  const at = (y, mo, d, h = 0, mi = 0) => isoAt(new Date(y, mo - 1, d, h, mi));
  const timed = { dateKey: '2026-07-31', startTime: '17:00', endTime: '14:00', allDay: false };
  // "Camping Fri 5pm to Sun 2pm": the parsed end keeps its day.
  const stretch = { allDay: false, start: at(2026, 7, 31, 17), end: at(2026, 8, 2, 14) };
  eq('quick range: a parsed end two days on is kept', buildRange(timed, stretch, false), { start: at(2026, 7, 31, 17), end: at(2026, 8, 2, 14) });
  eq('quick range: moving the date drops the parsed span', buildRange(timed, stretch, true).end, at(2026, 8, 1, 14));
  eq('quick range: an end before the start is the next day', buildRange(timed, null, false).end, at(2026, 8, 1, 14));
  const overnight = { allDay: false, start: at(2026, 7, 31, 22), end: at(2026, 8, 1, 2) };
  eq('quick range: overnight stays overnight', buildRange({ ...timed, startTime: '22:00', endTime: '02:00' }, overnight, false).end, at(2026, 8, 1, 2));
  eq('quick range: same day', buildRange({ ...timed, startTime: '09:00', endTime: '10:00' }, null, false), { start: at(2026, 7, 31, 9), end: at(2026, 7, 31, 10) });
  // "Workshop Mon-Fri": the all-day span is kept until the date moves.
  const allDay = { dateKey: '2026-08-03', allDay: true };
  const span = { allDay: true, start: '2026-08-03T00:00:00Z', end: '2026-08-08T00:00:00Z' };
  eq('quick range: an all-day span is kept', buildRange(allDay, span, false).end, at(2026, 8, 8));
  eq('quick range: moving the date makes it one day', buildRange(allDay, span, true).end, at(2026, 8, 4));
  eq('quick range: no date, nothing to save', buildRange({ ...timed, dateKey: '' }, null, false), null);
}

console.log('--- undo toast: time change wording ---');
{
  setTimeFormat('24');
  const now = new Date(2026, 9, 28, 12);
  const t = (y, mo, d, h = 0, mi = 0) => toISOWithOffset(new Date(y, mo - 1, d, h, mi));
  const timed = (s, e) => ({ start: s, end: e, allDay: false });
  const allDay = (s, e) => ({ start: s + 'T00:00:00+00:00', end: e + 'T00:00:00+00:00', allDay: true });
  const day = (y, mo, d) => fmtDS(new Date(y, mo - 1, d));
  eq('change: same day, new time', timeChange(timed(t(2026, 11, 6, 15), t(2026, 11, 6, 16)), timed(t(2026, 11, 6, 16, 30), t(2026, 11, 6, 17, 30)), now), '15:00 → 16:30');
  eq('change: new day, same time', timeChange(timed(t(2026, 11, 6, 15), t(2026, 11, 6, 16)), timed(t(2026, 11, 17, 15), t(2026, 11, 17, 16)), now), day(2026, 11, 6) + ' → ' + day(2026, 11, 17));
  eq('change: new day and time', timeChange(timed(t(2026, 11, 6, 15), t(2026, 11, 6, 16)), timed(t(2026, 11, 17, 10), t(2026, 11, 17, 11)), now), day(2026, 11, 6) + ', 15:00 → ' + day(2026, 11, 17) + ', 10:00');
  eq('change: all-day move', timeChange(allDay('2026-11-06', '2026-11-07'), allDay('2026-11-17', '2026-11-18'), now), day(2026, 11, 6) + ' → ' + day(2026, 11, 17));
  eq('change: end dragged', timeChange(timed(t(2026, 11, 6, 15), t(2026, 11, 6, 16)), timed(t(2026, 11, 6, 15), t(2026, 11, 6, 17)), now), 'ends 17:00');
  eq('change: end dragged to midnight', timeChange(timed(t(2026, 11, 6, 22), t(2026, 11, 6, 23)), timed(t(2026, 11, 6, 22), t(2026, 11, 7, 0)), now), 'ends 00:00');
  eq('change: end dragged past midnight names the day', timeChange(timed(t(2026, 11, 6, 22), t(2026, 11, 6, 23)), timed(t(2026, 11, 6, 22), t(2026, 11, 7, 1)), now), 'ends ' + day(2026, 11, 7) + ', 01:00');
  eq('change: start dragged', timeChange(timed(t(2026, 11, 6, 15), t(2026, 11, 6, 17)), timed(t(2026, 11, 6, 14), t(2026, 11, 6, 17)), now), 'starts 14:00');
  eq('change: all-day end dragged', timeChange(allDay('2026-11-06', '2026-11-07'), allDay('2026-11-06', '2026-11-09'), now), 'ends ' + day(2026, 11, 8));
  eq('change: all-day to timed', timeChange(allDay('2026-11-06', '2026-11-07'), timed(t(2026, 11, 6, 9), t(2026, 11, 6, 10)), now), day(2026, 11, 6) + ' → ' + day(2026, 11, 6) + ', 09:00');
  eq('change: moved and longer', timeChange(timed(t(2026, 11, 6, 15), t(2026, 11, 6, 16)), timed(t(2026, 11, 7, 15), t(2026, 11, 7, 18)), now), day(2026, 11, 6) + ' → ' + day(2026, 11, 7) + ', ends 18:00');
  eq('change: another year names it', timeChange(allDay('2026-12-30', '2026-12-31'), allDay('2027-01-04', '2027-01-05'), now), fmtDSY(new Date(2026, 11, 30)) + ' → ' + fmtDSY(new Date(2027, 0, 4)));
  const form = { title: 'Dentist', calendarId: 3, start: '2026-11-06T15:00', end: '2026-11-06T16:00', allDay: false, tz: 'UTC', location: '', tags: '', people: [], rrule: null, reminders: null, rel: 'planned' };
  eq('edited: title and time', editedWords(form, { ...form, title: 'Dentist checkup', end: '2026-11-06T16:30' }), ['title', 'time']);
  eq('edited: a calendar reselected as text is unchanged', editedWords(form, { ...form, calendarId: '3' }), []);
  eq('edited: people', editedWords(form, { ...form, people: [{ id: 1, name: 'Sam Sample' }] }), ['people']);
  eq('edited: list of three', listWords(['title', 'time', 'place']), 'title, time and place');
  eq('change: nothing changed', timeChange(timed(t(2026, 11, 6, 15), t(2026, 11, 6, 16)), timed(t(2026, 11, 6, 15), t(2026, 11, 6, 16)), now), '');
  setTimeFormat('12');
}

console.log('--- feed problems in words ---');
{
  const now = new Date(2026, 9, 29, 17, 0);
  const feed = (health, extra = {}) => ({ kind: 'subscribed', health, ...extra });
  const failed = feedProblem(feed({ status: 'error', error: 'Feed fetch failed: HTTP 404', lastPolledAt: new Date(2026, 9, 29, 16, 15).toISOString(), lastOkAt: new Date(2026, 9, 12, 9, 0).toISOString() }), now);
  eq('feed: a 404 is an error', failed.level, 'error');
  assert('feed: a 404 says nothing was found', /Nothing was found/.test(failed.reason));
  eq('feed: the raw detail is kept', failed.detail, 'Feed fetch failed: HTTP 404');
  assert('feed: tried today', /^today at /.test(failed.tried));
  assert('feed: last worked on its date', !/today|yesterday/.test(failed.worked) && failed.worked.includes(' at '));
  assert('feed: 403 says access was refused', /refused access/.test(feedProblem(feed({ status: 'error', error: 'Feed fetch failed: HTTP 403' }), now).reason));
  assert('feed: not a calendar', /not with a complete calendar/.test(feedProblem(feed({ status: 'error', error: 'Response is not an ICS calendar' }), now).reason));
  eq('feed: emptied is a warning', feedProblem(feed({ status: 'ok', content: 'emptied' }), now).level, 'warn');
  eq('feed: paused says so', feedProblem(feed({ status: 'ok' }, { subscriptionAuthorization: { status: 'paused' } }), now).headline, 'Updates are paused');
  eq('feed: a healthy feed has no problem', feedProblem(feed({ status: 'ok', content: 'ok' }), now), null);
  eq('feed: a local calendar has none', feedProblem({ kind: 'local', health: { status: 'error' } }, now), null);
  assert('when: yesterday', /^yesterday at /.test(whenText(new Date(2026, 9, 28, 9, 0).toISOString(), now)));
}

console.log('--- view review fixes (#183) ---');
{
  // Bands end the day after their last day, like every all-day item; a
  // midnight end doesn't reach into the next day.
  const at = (y, m, d, h = 0) => isoAt(new Date(y, m - 1, d, h));
  eq('band: Monday to Friday midnight covers Mon-Thu', bandDays(at(2026, 11, 2), at(2026, 11, 6)),
    { start: '2026-11-02T00:00:00+00:00', end: '2026-11-06T00:00:00+00:00' });
  eq('band: ending mid-afternoon covers that day', bandDays(at(2026, 11, 2), at(2026, 11, 6, 15)).end, '2026-11-07T00:00:00+00:00');
  eq('band: a one-day band', bandDays(at(2026, 11, 2), at(2026, 11, 3)),
    { start: '2026-11-02T00:00:00+00:00', end: '2026-11-03T00:00:00+00:00' });
  eq('band: an end before its start still covers the start day', bandDays(at(2026, 11, 5), at(2026, 11, 2)).end, '2026-11-06T00:00:00+00:00');

  assert('band: away time is a band', isBand({ instanceId: 'avail:3', availKind: 'away' }));
  assert('band: a plugin range is a band', isBand({ instanceId: 'plg:tides:9' }));
  assert('band: an event is not', !isBand({ instanceId: '41:2026-11-02' }));

  const occ = { instanceId: '41:2026-11-02' };
  assert('drag: a local event moves', canReschedule(occ, { editable: true }));
  assert('drag: a feed event does not', !canReschedule(occ, { editable: false }));
  assert('drag: a reschedulable plugin calendar\'s event does', canReschedule(occ, { editable: false, reschedulable: true }));
  assert('drag: a plugin band never does', !canReschedule({ instanceId: 'plg:tides:9' }, null));
  assert('drag: away time does', canReschedule({ instanceId: 'avail:3', availKind: 'away' }, undefined));

  // Where Next, a view switch and a reload start from.
  eq('here: the anchor while it is on screen', hereKeyOf('2026-11-04', { start: '2026-11-01', end: '2026-11-07' }), '2026-11-04');
  eq('here: the middle of the screen once scrolled away', hereKeyOf('2026-11-04', { start: '2027-01-03', end: '2027-01-09' }), '2027-01-06');
  eq('here: the anchor when nothing is reported', hereKeyOf('2026-11-04', null), '2026-11-04');
  eq('here: one day on screen', hereKeyOf('2026-11-04', { start: '2026-12-25', end: '2026-12-25' }), '2026-12-25');
  eq('here: on the way to the anchor, the anchor', hereKeyOf('2026-11-04', { start: '2026-10-11', end: '2026-10-17' }, false), '2026-11-04');

  // After a change only the refreshed stretch is fresh; the rest stays held
  // (nothing blanks) but is fetched again when next shown.
  const ms = (iso) => Date.parse(iso);
  const r = (a, b) => ({ start: ms(a + 'T00:00:00Z'), end: ms(b + 'T00:00:00Z') });
  storeState.occ.clear();
  storeState.loadedRanges = [r('2026-08-01', '2026-09-01'), r('2026-10-01', '2026-12-01')];
  storeState.staleRanges = [];
  markStaleOutside('2026-10-15T00:00:00Z', '2026-11-15T00:00:00Z');
  eq('stale: everything held stays held', storeState.loadedRanges, [r('2026-08-01', '2026-09-01'), r('2026-10-01', '2026-12-01')]);
  eq('stale: only the refreshed stretch is fresh', freshRanges(), [r('2026-10-15', '2026-11-15')]);
  eq('stale: missing for a fetch is the stale part', missingRanges(ms('2026-08-15T00:00:00Z'), ms('2026-11-01T00:00:00Z'), freshRanges()),
    [r('2026-08-15', '2026-10-15')]);
  mergeWindow('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z', []);
  eq('stale: fetching a stretch again makes it fresh', freshRanges(), [r('2026-08-01', '2026-09-01'), r('2026-10-15', '2026-11-15')]);
  storeState.loadedRanges = [];
  storeState.staleRanges = [];

  // A group keeps what its members share: grouped holidays stay day labels.
  const hol = (title) => ({ ...mkOcc(1, title, '2026-11-26'), relationship: 'context', status: 'confirmed' });
  const g = groupOccurrences([hol('Harvest Day (North)'), hol('Harvest Day (South)')], FLAGS);
  eq('grouping: a holiday group is still context', [g.length, g[0].relationship, g[0].status], [1, 'context', 'confirmed']);
  const mixed = groupOccurrences([hol('Harvest Day (North)'), { ...mkOcc(1, 'Harvest Day (South)', '2026-11-26'), relationship: 'planned' }], FLAGS);
  eq('grouping: members that differ give no relationship', mixed[0].relationship, undefined);
}

console.log('--- window merges (#183) ---');
{
  storeState.occ.clear();
  storeState.loadedRanges = [];
  storeState.staleRanges = [];
  const occ = (id, start, end, extra = {}) => ({ instanceId: id, eventId: 1, calendarId: 1, title: id, allDay: false, start, end, ...extra });
  // A timed event that began before the window and runs into it, deleted elsewhere.
  storeState.occ.set('long', occ('long', '2026-10-31T20:00:00Z', '2026-11-01T10:00:00Z'));
  storeState.occ.set('allday', occ('allday', '2026-10-31T00:00:00+00:00', '2026-11-02T00:00:00+00:00', { allDay: true }));
  mergeWindow('2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z', []);
  assert('merge: a timed event running into the window is cleared when the answer lacks it', !storeState.occ.has('long'));
  assert('merge: an all-day span from before the window still goes by its start', storeState.occ.has('allday'));

  // A drop while an older answer is on its way: the moved copy stays.
  storeState.occ.clear();
  storeState.occ.set('m', occ('m', '2026-11-03T10:00:00Z', '2026-11-03T11:00:00Z'));
  const asked = Date.now() - 1000;
  patchOccurrence('m', { start: '2026-11-04T10:00:00Z', end: '2026-11-04T11:00:00Z', _optimistic: true });
  patchOccurrence('m', { _optimistic: false });
  mergeWindow('2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z', [occ('m', '2026-11-03T10:00:00Z', '2026-11-03T11:00:00Z')], asked);
  eq('merge: an answer asked before the drop keeps the moved copy', storeState.occ.get('m').start, '2026-11-04T10:00:00Z');
  mergeWindow('2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z', [occ('m', '2026-11-04T10:00:00Z', '2026-11-04T11:00:00Z', { title: 'fresh' })], Date.now() + 1);
  eq('merge: an answer asked after it replaces it', storeState.occ.get('m').title, 'fresh');
  // A delete while an older answer is on its way: it stays deleted.
  storeState.occ.set('d', occ('d', '2026-11-05T10:00:00Z', '2026-11-05T11:00:00Z', { eventId: 9 }));
  const asked2 = Date.now() - 1000;
  removeOccurrencesOfEvent(9);
  mergeWindow('2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z', [occ('d', '2026-11-05T10:00:00Z', '2026-11-05T11:00:00Z', { eventId: 9 })], asked2);
  assert('merge: an answer asked before a delete does not bring it back', !storeState.occ.has('d'));
  // A Maybe (any local patch) while an older answer is on its way: it stays.
  storeState.occ.set('r', occ('r', '2026-11-06T10:00:00Z', '2026-11-06T11:00:00Z', { relationship: 'available' }));
  const asked3 = Date.now() - 1000;
  patchOccurrence('r', { relationship: 'maybe' });
  mergeWindow('2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z', [occ('r', '2026-11-06T10:00:00Z', '2026-11-06T11:00:00Z', { relationship: 'available' })], asked3);
  eq('merge: an answer asked before a Maybe keeps it', storeState.occ.get('r').relationship, 'maybe');
  storeState.occ.clear();
  storeState.loadedRanges = [];
  storeState.staleRanges = [];
}

console.log('--- bar resize (#183) ---');
{
  const iso = (d, h) => isoAt(new Date(2026, 10, d, h));
  const bar = { allDay: false, start: iso(2, 10), end: iso(4, 0) }; // Mon 10 AM to Wed 00:00 (last day Tue)
  eq('resize: a bar ending at midnight, end dropped on Thu, ends Fri 00:00', resizeOccurrenceDays(bar, 'end', '2026-11-05').newEnd, iso(6, 0));
  eq('resize: a bar ending mid-day keeps its time', resizeOccurrenceDays({ allDay: false, start: iso(2, 10), end: iso(4, 15) }, 'end', '2026-11-05').newEnd, iso(5, 15));
  eq('resize: all-day end, inclusive Thu', resizeOccurrenceDays({ allDay: true, start: '2026-11-02T00:00:00+00:00', end: '2026-11-04T00:00:00+00:00' }, 'end', '2026-11-05'),
    { newStart: '2026-11-02', newEnd: '2026-11-06' });
}

console.log('--- overnight events (#183) ---');
{
  const at = (d, h, m = 0) => isoAt(new Date(2026, 10, d, h, m));
  const ev = (s, e, extra = {}) => ({ instanceId: 'x', allDay: false, start: s, end: e, ...extra });
  assert('overnight: 10 PM to 1 AM', isOvernight(ev(at(6, 22), at(7, 1))));
  assert('overnight: a 14-hour overnight flight', isOvernight(ev(at(6, 18), at(7, 8))));
  assert('overnight: not one ending at midnight', !isOvernight(ev(at(6, 22), at(7, 0))));
  assert('overnight: not a same-day event', !isOvernight(ev(at(6, 9), at(6, 10))));
  assert('overnight: not a span of a day or more', !isOvernight(ev(at(6, 14), at(8, 10))));
  assert('overnight: not all-day', !isOvernight({ instanceId: 'x', allDay: true, start: '2026-11-06T00:00:00+00:00', end: '2026-11-08T00:00:00+00:00' }));
  assert('overnight: not a trip', !isOvernight(ev(at(6, 22), at(7, 1), { isContainer: true })));
  assert('overnight: not one running on into the next afternoon', !isOvernight(ev(at(6, 22), at(7, 21))));
  assert('overnight: landing by noon is', isOvernight(ev(at(6, 23), at(7, 12))));
}

console.log(passed + ' passed, ' + failed + ' failed');
if (failed > 0) process.exit(1);
