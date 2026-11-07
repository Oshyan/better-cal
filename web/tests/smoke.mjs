// Static smoke test for pure logic: date utils, overlap layout, month
// virtualization math. Run: node web/tests/smoke.mjs

import './copy-to.mjs';
import './drafts.mjs';
import './credentials.mjs';
import './mail.mjs';
import './resources.mjs';
// The series panel never guesses a clock's zone: under test a clock with none throws.
import { setStrictClocks } from '../src/lib/planwords.js';
setStrictClocks(true);
import {
  parseISO, toISOWithOffset, dayKeyOf, dateOfDayKey, epochDayOfKey,
  keyOfEpochDay, addDaysKey, diffDaysKey, weekIndexOfKey, firstEpochDayOfWeek,
  dayKeysOfWeek, startOfWeekKey, setWeekStart, getWeekStart, setTimeFormat, fmtTime,
  fmtMonthShort, timeState, startMs, byStart, eventDuration, allDayFields, allDayInputs, untilDayKey,
  fmtRange, fmtRangeParts, otherYear, instantFromWallTime as wallInstantOf, tzOffsetMinutes as zoneOffsetOf,
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
import { placeAnswer } from '../src/lib/placeanswer.js';
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
import { state as storeState, missingRanges, pruneOccurrenceCache, invalidateRecords, mergeWindow, insertOccurrence, freshRanges, patchOccurrence, restoreOccurrence, removeOccurrencesOfEvent, localSaved, dataGeneration, newGeneration } from '../src/app/store.js';
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
import { updateStatusText, checkResultText, bannerText, INSTALL_UPDATING_URL } from '../src/lib/updatetext.js';

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
  STATIC_COMMANDS.every((c) => c.needs === undefined || c.needs === 'occ' || c.needs === 'popover' || c.needs === 'sheet'));
// #207: o shows all of an open event's details, from the keyboard and the palette.
eq('hotkeys: o shows all details', HOTKEYS.find((h) => !h.mod && h.keys.includes('o'))?.id, 'fullDetails');
assert('commands: all details is in the palette while the sheet is open',
  STATIC_COMMANDS.some((c) => c.hotkey === 'fullDetails' && c.needs === 'sheet'));
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
// before it, and never discards a local edit not yet in an answer.
{
  storeState.occ.clear();
  storeState.loadedRanges = [{ start: 0, end: Date.UTC(2030, 0, 1), gen: 0 }];
  const add = (id, start, end, extra = {}) => storeState.occ.set(id, { instanceId: id, start, end, ...extra });
  add('old-a', '2025-01-01T10:00:00Z', '2025-01-01T11:00:00Z');
  add('old-b', '2025-02-01T10:00:00Z', '2025-02-01T11:00:00Z');
  add('inside', '2026-04-10T10:00:00Z', '2026-04-10T11:00:00Z');
  add('spanning', '2026-03-30T10:00:00Z', '2026-04-02T11:00:00Z');
  add('draft', '2025-03-01T10:00:00Z', '2025-03-01T11:00:00Z');
  patchOccurrence('draft', { title: 'Moved here' });
  assert('window cache: crossing the cap prunes retained history',
    pruneOccurrenceCache('2026-04-01T00:00:00Z', '2026-05-01T00:00:00Z', { max: 3 }));
  eq('window cache: the active window, overlapping span, and local edit remain',
    [...storeState.occ.keys()].sort(), ['draft', 'inside', 'spanning']);
  eq('window cache: only the complete active range remains marked loaded',
    storeState.loadedRanges, [{ start: Date.parse('2026-04-01T00:00:00Z'), end: Date.parse('2026-05-01T00:00:00Z'), gen: 0 }]);
  restoreOccurrence('draft', null);
  storeState.occ.clear();
  storeState.loadedRanges = [];

  add('history-c', '2025-01-01T10:00:00Z', '2025-01-01T11:00:00Z');
  add('history-d', '2025-02-01T10:00:00Z', '2025-02-01T11:00:00Z');
  add('partial', '2026-04-05T10:00:00Z', '2026-04-05T11:00:00Z');
  storeState.loadedRanges = [
    { start: Date.parse('2026-03-01T00:00:00Z'), end: Date.parse('2026-04-10T00:00:00Z'), gen: 0 },
    { start: Date.parse('2026-04-20T00:00:00Z'), end: Date.parse('2026-06-01T00:00:00Z'), gen: 0 },
  ];
  eq('window cache: intermediate pruning preserves only ranges actually loaded so far',
    pruneOccurrenceCache('2026-04-01T00:00:00Z', '2026-05-01T00:00:00Z', { max: 2 }),
    'pruned');
  eq('window cache: intermediate pruning never marks the unfinished full window loaded', storeState.loadedRanges, [
    { start: Date.parse('2026-04-01T00:00:00Z'), end: Date.parse('2026-04-10T00:00:00Z'), gen: 0 },
    { start: Date.parse('2026-04-20T00:00:00Z'), end: Date.parse('2026-05-01T00:00:00Z'), gen: 0 },
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

// --- The place dropdown: rows, or the reason nothing matched ---
{
  const rows = [{ name: 'Harbor Light Cinema', address: 'Example Bay', lat: 1, lng: 2 }];
  const credit = [{ label: 'Photon', url: 'https://photon.komoot.io' }];
  eq('place answer: rows and who found them', placeAnswer({ results: rows, credits: credit, reason: null }), { results: rows, credits: credit, note: '' });
  eq('place answer: nothing matched shows the one sentence, no credits', placeAnswer({ results: [], credits: credit, reason: 'No place matches all of those words.' }),
    { results: [], credits: [], note: 'No place matches all of those words.' });
  eq('place answer: no reason (no service reached, or an older server) leaves it closed', placeAnswer({ results: [], credits: [] }), { results: [], credits: [], note: '' });
  eq('place answer: no answer at all', placeAnswer(null), { results: [], credits: [], note: '' });
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
  const { loadWindow } = await import('../src/app/api.js');
  const { runOwed, isOwed } = await import('../src/app/reloads.js');
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
  eq('window: the failure is shown, as offline, and the window is owed a reload', [st.windowStatus && st.windowStatus.kind, st.windowStatus && st.windowStatus.offline, isOwed('window')], ['failed', true, true]);
  eq('window: and the dates it failed for are not loaded', st.loadedRanges, []);
  const before = calls;
  mode = 'online';
  await runOwed(); // Retry now, or the next change check
  assert('window: Retry now asks again', calls > before);
  assert('window: and nothing is owed once it lands', !isOwed('window'));
  eq('window: once it loads, the note goes away', st.windowStatus, null);
  assert('window: and the dates count as loaded', st.loadedRanges.length > 0);
  // A range that fails, then turns out to be covered by a wider window that
  // loaded, must not leave the note stuck on.
  mode = 'offline';
  put({ loadedRanges: [], windowStatus: null });
  await loadWindow('2027-01-01T00:00:00-08:00', '2027-01-15T00:00:00-08:00');
  mode = 'online';
  await loadWindow('2026-12-01T00:00:00-08:00', '2027-02-01T00:00:00-08:00');
  await runOwed();
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
  await runOwed();
  eq('window: Retry now does not repeat an unchanged over-budget request', calls, limitedCalls);
  mode = 'online';
  const beforeWide = calls;
  await loadWindow('2020-01-01T00:00:00Z', '2022-01-01T00:00:00Z');
  eq('window: a client request over 700 days is refused without a request fan-out',
    [calls, st.windowStatus && st.windowStatus.kind], [beforeWide, 'limited']);
  globalThis.fetch = realFetch;
}

// --- Event windows (#184): whole-month gaps, the loading note, own changes ---
{
  const realFetch = globalThis.fetch;
  const { loadWindow, refreshWindow, ownChangeCursor } = await import('../src/app/api.js');
  const { state: st, set: put } = await import('../src/app/store.js');
  const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  const DAY = 86400000;
  const asked = [];
  let held = null; // a promise the events answer waits on
  let dense = false; // the server refuses anything wider than ten days
  let failEvents = false;
  globalThis.fetch = async (url) => {
    const u = new URL(String(url), 'http://app.test');
    if (u.pathname.endsWith('/changes/cursor')) return json({ cursor: 'c-after-save' });
    const s = Date.parse(u.searchParams.get('start'));
    const e = Date.parse(u.searchParams.get('end'));
    asked.push([s, e]);
    if (held) await held;
    if (failEvents) return json({ error: { code: 'server', message: 'Down for a moment' } }, 500);
    if (dense && e - s > 10 * DAY) return json({ error: { code: 'event_window_too_large', message: 'Too many.' } }, 422);
    return json({ events: [] });
  };
  const at = (y, m, d) => isoAt(new Date(y, m - 1, d));

  // A dense month: widened to the whole month, the request is refused, so
  // just the days needed are asked for, and they load.
  put({ loadedRanges: [], windowStatus: null, visibleDays: null });
  dense = true;
  asked.length = 0;
  await loadWindow(at(2029, 5, 10), at(2029, 5, 13));
  eq('window: a refused whole-month gap falls back to the days needed', [st.windowStatus, asked.length], [null, 2]);
  assert('window: and those days count as loaded', st.loadedRanges.some((r) => r.start <= Date.parse(at(2029, 5, 10)) && r.end >= Date.parse(at(2029, 5, 13))));
  dense = false;

  // "Loading" stays while days on screen are still on their way, even when
  // the view asks again for a window those requests already cover.
  put({ loadedRanges: [], windowStatus: null, visibleDays: { start: '2029-08-10', end: '2029-08-16' } });
  let release;
  held = new Promise((r) => { release = r; });
  const first = loadWindow(at(2029, 8, 3), at(2029, 8, 24));
  await new Promise((r) => setTimeout(r, 0));
  const second = loadWindow(at(2029, 8, 4), at(2029, 8, 25)); // the view settling, all of it in flight
  await new Promise((r) => setTimeout(r, 0));
  eq('window: the note stays while what it waits on is on its way', st.windowStatus && st.windowStatus.kind, 'loading');
  held = null;
  release();
  await first;
  await second;
  eq('window: and goes when it lands', st.windowStatus, null);

  // The month in flight is refused (too dense) and only the first window's
  // days are fetched in its place: the view that waited on it asks for its
  // own days, and the note goes once they land.
  put({ loadedRanges: [], windowStatus: null, visibleDays: { start: '2029-05-14', end: '2029-05-20' } });
  dense = true;
  held = new Promise((r) => { release = r; });
  const jump = loadWindow(at(2029, 5, 10), at(2029, 5, 13));
  await new Promise((r) => setTimeout(r, 0));
  const settled = loadWindow(at(2029, 5, 14), at(2029, 5, 21));
  await new Promise((r) => setTimeout(r, 0));
  held = null;
  release();
  await jump;
  await settled;
  eq('window: a refused month in flight still brings the view its days', [st.windowStatus, st.loadedRanges.some((r) => r.start <= Date.parse(at(2029, 5, 14)) && r.end >= Date.parse(at(2029, 5, 21)))], [null, true]);
  dense = false;

  // A save's own refresh reads the change cursor first; only one that lands
  // tells the poll it needn't fetch the window again.
  await refreshWindow();
  eq('own changes: a landed refresh remembers the cursor it read', ownChangeCursor(), 'c-after-save');
  failEvents = true;
  const ok = await refreshWindow();
  eq('own changes: a failed refresh does not', [ok, ownChangeCursor()], [false, null]);
  failEvents = false;
  put({ windowStatus: null, visibleDays: null });
  globalThis.fetch = realFetch;
}

// --- Event windows and the server's ETag (#16): a 304 keeps the window ---
{
  const realFetch = globalThis.fetch;
  const { loadWindow } = await import('../src/app/api.js');
  const { state: st, set: put, newGeneration, freshRanges, heldTag } = await import('../src/app/store.js');
  const { isOwed } = await import('../src/app/reloads.js');
  const at = (y, m, d) => isoAt(new Date(y, m - 1, d));
  const json = (body) => new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
  const occ = (id, iso) => ({ instanceId: `${id}:${iso.replace(/[-:]/g, '')}`, eventId: id, calendarId: 1, title: `Sample ${id}`, start: iso, end: iso.replace('T19', 'T20'), allDay: false });
  // A pretend server: one tag for the account's window at a time, a 304 to it.
  const server = { tag: 'W/"tag-1"', events: [], asked: [] };
  globalThis.fetch = async (url, init = {}) => {
    const u = new URL(String(url), 'http://app.test');
    const p = u.pathname.replace('/api/v1', '');
    if (p === '/changes/cursor') return json({ cursor: 'c1' });
    if (p !== '/events') return json({});
    const sent = (init.headers || {})['If-None-Match'] || null;
    server.asked.push(sent);
    const headers = { ETag: server.tag, 'Cache-Control': 'private, no-cache' };
    if (sent === server.tag) return new Response(null, { status: 304, headers });
    return new Response(JSON.stringify({ events: server.events }), { status: 200, headers: { 'Content-Type': 'application/json', ...headers } });
  };
  const held = (y1, m1, y2, m2) => heldTag(Date.parse(at(y1, m1, 1)), Date.parse(at(y2, m2, 1)));
  put({ loadedRanges: [], windowStatus: null, visibleDays: null });
  st.occ.clear();

  server.events = [occ(1, '2033-04-12T19:00:00Z')];
  await loadWindow(at(2033, 4, 10), at(2033, 4, 20));
  eq('etag: a stretch not held is asked for plainly, and held under the answer\'s tag', [server.asked, held(2033, 4, 2033, 5)], [[null], 'W/"tag-1"']);

  // A change elsewhere moves the generation; the server says nothing changed.
  newGeneration();
  server.asked.length = 0;
  const ok = await loadWindow(at(2033, 4, 10), at(2033, 4, 20));
  eq('etag: the held stretch is asked for with its tag', server.asked, ['W/"tag-1"']);
  eq('etag: a 304 keeps the window: the event stays, the stretch is fresh, nothing owed, no note',
    [ok, st.occ.has('1:20330412T190000Z'), freshRanges().length > 0, isOwed('window'), st.windowStatus], [true, true, true, false, null]);

  // Asked wider than what's held (April held, May not: one request for
  // both), no tag is sent: a 304 could only say part of it is current.
  newGeneration();
  server.asked.length = 0;
  server.tag = 'W/"tag-2"';
  server.events = [occ(1, '2033-04-12T19:00:00Z'), occ(2, '2033-05-03T19:00:00Z')];
  await loadWindow(at(2033, 4, 10), at(2033, 5, 10));
  eq('etag: a stretch held only in part is asked for plainly, and the answer is held under its tag',
    [server.asked, st.occ.has('2:20330503T190000Z'), held(2033, 4, 2033, 6)], [[null], true, 'W/"tag-2"']);

  // The server's tag moved on: the held tag is answered in full with the new one.
  newGeneration();
  server.asked.length = 0;
  server.tag = 'W/"tag-3"';
  server.events = [occ(2, '2033-05-03T19:00:00Z')];
  await loadWindow(at(2033, 4, 10), at(2033, 5, 10));
  eq('etag: a tag the server no longer names is answered in full: the gone event goes, the new tag is held',
    [server.asked, st.occ.has('1:20330412T190000Z'), st.occ.has('2:20330503T190000Z'), held(2033, 4, 2033, 6)], [['W/"tag-2"'], false, true, 'W/"tag-3"']);

  put({ loadedRanges: [], windowStatus: null, visibleDays: null });
  st.occ.clear();
  globalThis.fetch = realFetch;
}

// --- Event windows and records: the second release review (#185) ---
{
  const realFetch = globalThis.fetch;
  const { loadWindow, refreshWindow, ensureFullOccurrence, fetchPluginRanges } = await import('../src/app/api.js');
  const { defineReload, owe, reload, isOwed, runOwed } = await import('../src/app/reloads.js');
  const { state: st, set: put, invalidateRecords } = await import('../src/app/store.js');
  const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  const at = (y, m, d, h = 0) => isoAt(new Date(y, m - 1, d, h));
  const tick = () => new Promise((r) => setTimeout(r, 0));
  const ev = (id, startISO, endISO) => ({ instanceId: 'r' + id, eventId: id, calendarId: 1, title: 'Sample ' + id, start: startISO, end: endISO, allDay: false });
  let handler = null;
  globalThis.fetch = async (url) => handler(new URL(String(url), 'http://app.test'));
  const range = (u) => [Date.parse(u.searchParams.get('start')), Date.parse(u.searchParams.get('end'))];

  // Capped series: see "Loading the calendar's events" (#190), whose pretend
  // server answers as the real one does, with the first 1,000.

  // "Loading" stays while part of the view's days are in an older request
  // still on its way, even after the view's other months land.
  const holds = new Map(); // month start -> {promise, release}
  const hold = (y, m) => { let release; const promise = new Promise((r) => { release = r; }); holds.set(Date.parse(at(y, m, 1)), { promise, release }); return release; };
  handler = async (u) => {
    const [s] = range(u);
    const h = holds.get(s);
    if (h) await h.promise;
    return json({ events: [] });
  };
  put({ loadedRanges: [], windowStatus: null, visibleDays: { start: '2030-05-28', end: '2030-06-05' } });
  const releaseMay = hold(2030, 5);
  const older = loadWindow(at(2030, 5, 10), at(2030, 5, 13)); // May on its way
  await tick();
  const view = loadWindow(at(2030, 5, 28), at(2030, 6, 6)); // needs June too
  for (let i = 0; i < 5; i++) await tick();
  eq('loading: the note stays while May is still on its way, June landed', st.windowStatus && st.windowStatus.kind, 'loading');
  releaseMay();
  await older;
  await view;
  eq('loading: and goes once May lands', st.windowStatus, null);

  // A refresh asked for while another is on its way shares the one run
  // after it; when that fails, neither answers true, and the window stays
  // owed for the change check (#185).
  put({ loadedRanges: [], windowStatus: null, visibleDays: null });
  handler = async () => json({ events: [] });
  await loadWindow(at(2031, 3, 1), at(2031, 3, 8));
  let releaseA;
  const heldA = new Promise((r) => { releaseA = r; });
  let n = 0;
  handler = async (u) => {
    if (u.pathname.endsWith('/changes/cursor')) return json({ cursor: 'c2' });
    n++;
    if (n === 1) { await heldA; return json({ events: [] }); }
    return json({ error: { code: 'server', message: 'Down for a moment' } }, 500);
  };
  const a = refreshWindow();
  await tick();
  const b = refreshWindow();
  await tick();
  releaseA();
  eq('refresh: one taken over answers with what the newer one found', [await b, await a, isOwed('window')], [false, false, true]);
  handler = async () => json({ events: [] });
  await runOwed();
  assert('refresh: and the next check that reaches the server fills it', !isOwed('window') && st.windowStatus === null);

  // A record asked for before a change elsewhere marked records out of date
  // isn't taken as current: it's asked for again.
  st.occ.clear();
  st.occ.set('d1', { ...ev(91, at(2031, 4, 2, 9), at(2031, 4, 2, 10)), instanceId: 'd1' });
  let releaseOld;
  const heldOld = new Promise((r) => { releaseOld = r; });
  let detailCalls = 0;
  handler = async () => {
    detailCalls++;
    if (detailCalls === 1) { await heldOld; return json({ occurrence: { description: 'Before the change' } }); }
    return json({ occurrence: { description: 'After the change' } });
  };
  const opened = ensureFullOccurrence('d1');
  await tick();
  invalidateRecords();
  releaseOld();
  await opened;
  eq('records: an answer from before a change is asked for again', [st.occ.get('d1').description, st.occ.get('d1').stale, detailCalls], ['After the change', false, 2]);

  // Plugin bands: a stretch's bands come in one request, whole. A refresh
  // between two reads shows only its set: the server reads each plugin's
  // ranges in one statement and a refresh replaces them in one transaction,
  // so no answer holds part of each. One cut at the cap stays marked, and
  // the person is told once.
  let bandSet = [{ id: 1, key: 'k1', start: '2031-05-01T00:00:00+00:00', end: '2031-05-30T00:00:00+00:00', label: 'before' }];
  for (let i = 2; i <= 6; i++) bandSet.push({ id: i, key: 'k' + i, start: '2031-05-10T06:00:00+00:00', end: '2031-05-10T07:00:00+00:00', label: 'before' });
  let bandCalls = 0;
  let bandsCut = false;
  let askedByStretchAlone = true;
  handler = async (u) => {
    bandCalls++;
    if (u.searchParams.has('afterStart') || u.searchParams.has('afterKey') || u.searchParams.has('plugin')) askedByStretchAlone = false;
    return json({ plugins: { tides: { truncated: bandsCut, ranges: bandSet } } });
  };
  const bands = await fetchPluginRanges('2031-05-05T00:00:00Z', '2031-05-20T00:00:00Z');
  eq('bands: a stretch\'s bands come in one request, whole', [bandCalls, askedByStretchAlone, bands.tides.ranges.map((r) => r.key), bands.tides.truncated], [1, true, ['k1', 'k2', 'k3', 'k4', 'k5', 'k6'], false]);
  bandSet = bandSet.map((r) => ({ ...r, id: r.id + 100, label: 'after', start: r.key === 'k6' ? '2031-05-02T00:00:00+00:00' : r.start }));
  bandCalls = 0;
  const refreshed = await fetchPluginRanges('2031-05-05T00:00:00Z', '2031-05-20T00:00:00Z');
  eq('bands: after a refresh only its set shows, at its times', [bandCalls, [...new Set(refreshed.tides.ranges.map((r) => r.label))], refreshed.tides.ranges.find((r) => r.key === 'k6').start], [1, ['after'], '2031-05-02T00:00:00+00:00']);
  const toastsBeforeBands = st.toasts.length;
  bandsCut = true;
  const cut = await fetchPluginRanges('2031-05-05T00:00:00Z', '2031-05-20T00:00:00Z');
  await fetchPluginRanges('2031-05-05T00:00:00Z', '2031-05-20T00:00:00Z');
  eq('bands: a plugin cut at the cap stays marked, and the person is told once', [cut.tides.truncated, st.toasts.slice(toastsBeforeBands).filter((t) => t.text.includes('more bands here')).length], [true, 1]);

  // The change check's reloads: one at a time each, owed again meanwhile
  // it runs once more, and one that failed stays owed (#185).
  const loads = [];
  defineReload('sample-list', () => new Promise((resolve, reject) => loads.push({ resolve, reject })));
  owe('sample-list');
  reload('sample-list');
  owe('sample-list');
  reload('sample-list');
  await tick();
  eq('reloads: one at a time', loads.length, 1);
  loads[0].resolve(true);
  for (let i = 0; i < 5; i++) await tick();
  eq('reloads: owed again meanwhile, it runs once more after the first lands', loads.length, 2);
  loads[1].reject(new Error('Down for a moment'));
  for (let i = 0; i < 5; i++) await tick();
  assert('reloads: a newer one that failed stays owed', isOwed('sample-list'));
  const retried = runOwed();
  await tick();
  loads[2].resolve(true);
  await retried;
  eq('reloads: retried with the others, one that lands settles it', [loads.length, isOwed('sample-list')], [3, false]);

  put({ loadedRanges: [], windowStatus: null, visibleDays: null });
  st.occ.clear();
  globalThis.fetch = realFetch;
}

// --- Loading the calendar's events: one fetch path (#190) ---
{
  const realFetch = globalThis.fetch;
  const { loadWindow, refreshWindow, ensureRangeLoaded } = await import('../src/app/api.js');
  const { runOwed, isOwed } = await import('../src/app/reloads.js');
  const { checkChanges } = await import('../src/app/changecheck.js');
  const { state: st, set: put } = await import('../src/app/store.js');
  const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  const down = () => json({ error: { code: 'server', message: 'Down for a moment' } }, 500);
  const tick = async (n = 5) => { for (let i = 0; i < n; i++) await new Promise((r) => setTimeout(r, 0)); };
  const HOUR = 3600000;
  const at = (y, m, d) => isoAt(new Date(y, m - 1, d));
  const fresh = (a, b) => missingRanges(Date.parse(a), Date.parse(b), freshRanges()).length === 0;
  // A pretend server with an hourly series (half an hour each), expanding
  // at most 1,000 of it a request and naming it in capped past that.
  const first = Date.UTC(2035, 0, 1);
  const hourly = (u) => {
    const s = Date.parse(u.searchParams.get('start'));
    const e = Date.parse(u.searchParams.get('end'));
    const out = [];
    let k = Math.max(0, Math.ceil((s - HOUR / 2 - first) / HOUR));
    for (; first + k * HOUR < e; k++) {
      const a = first + k * HOUR;
      if (a + HOUR / 2 <= s) continue;
      if (out.length === 1000) return json({ events: out, capped: [501] });
      out.push({ instanceId: '501:' + k, eventId: 501, calendarId: 1, title: 'Sample hourly', start: isoAt(new Date(a)), end: isoAt(new Date(a + HOUR / 2)), allDay: false, recurring: true });
    }
    return json({ events: out });
  };
  let requests = 0;
  let failRequest = 0; // that request fails, counting from 1
  globalThis.fetch = async (url) => {
    const u = new URL(String(url), 'http://app.test');
    if (u.pathname.endsWith('/changes/cursor')) return json({ cursor: 'c-sample' });
    requests++;
    if (requests === failRequest) return down();
    return hourly(u);
  };
  const inView = () => [...st.occ.values()].filter((o) => o.eventId === 501
    && Date.parse(o.start) >= Date.parse(at(2035, 3, 1)) && Date.parse(o.start) < Date.parse(at(2035, 7, 1))).length;
  const hours = Math.round((Date.parse(at(2035, 7, 1)) - Date.parse(at(2035, 3, 1))) / HOUR);

  // The released bug: scrolled into view a month at a time, every hour is
  // held; a save's refresh asked for the whole window at once, took the
  // first 1,000 as the lot, and left nothing to fetch the rest.
  put({ loadedRanges: [], windowStatus: null, visibleDays: null });
  st.occ.clear();
  for (let m = 3; m < 7; m++) await loadWindow(at(2035, m, 1), at(2035, m + 1, 1));
  await loadWindow(at(2035, 3, 1), at(2035, 7, 1));
  eq('hourly series: every occurrence in the window is held', inView(), hours);
  await refreshWindow();
  eq('hourly series: and still every one after a save', inView(), hours);

  // A cold load of the whole window: every hour too, a request per 1,000,
  // and nothing said about occurrences not shown.
  put({ loadedRanges: [], windowStatus: null, visibleDays: null });
  st.occ.clear();
  requests = 0;
  await loadWindow(at(2035, 3, 1), at(2035, 7, 1));
  eq('hourly series: a cold load holds every occurrence', [inView(), requests, st.windowStatus], [hours, Math.ceil(hours / 1000), null]);
  assert('hourly series: with no word of occurrences left out', !st.toasts.some((t) => t.text.includes('Sample hourly')));

  // A request after the first fails: only what landed counts as loaded, the
  // window is owed, and the retry fills the rest.
  put({ loadedRanges: [], windowStatus: null, visibleDays: null });
  st.occ.clear();
  requests = 0;
  failRequest = 2;
  await loadWindow(at(2035, 3, 1), at(2035, 7, 1));
  const heldMs = st.loadedRanges.reduce((n, r) => n + (r.end - r.start), 0);
  eq('a failed fetch: the note says so and the window is owed', [st.windowStatus && st.windowStatus.kind, isOwed('window')], ['failed', true]);
  assert('a failed fetch: only what landed counts as loaded', heldMs > 0 && heldMs < Date.parse(at(2035, 7, 1)) - Date.parse(at(2035, 3, 1)) && !fresh(at(2035, 3, 1), at(2035, 7, 1)));
  failRequest = 0;
  await runOwed();
  eq('a failed fetch: the next retry fills the rest', [inView(), st.windowStatus, isOwed('window')], [hours, null, false]);

  // Three generations overlapping (a change elsewhere, then another, each
  // asking again while the one before is on its way): answers land out of
  // order, and an older one landing after a newer one is ignored.
  put({ loadedRanges: [], windowStatus: null, visibleDays: null });
  st.occ.clear();
  const holds = [];
  globalThis.fetch = async (url) => {
    const u = new URL(String(url), 'http://app.test');
    if (u.pathname.endsWith('/changes/cursor')) return json({ cursor: 'c-sample' });
    const version = holds.length + 1;
    // The three asked for are held; one more (there should be none) isn't.
    if (version <= 3) await new Promise((r) => holds.push(r));
    else holds.push(() => {});
    return json({ events: [{ instanceId: 'v1', eventId: 601, calendarId: 1, title: 'Version ' + version, start: isoAt(new Date(2036, 1, 10, 9)), end: isoAt(new Date(2036, 1, 10, 10)), allDay: false }] });
  };
  const viewLoad = loadWindow(at(2036, 2, 1), at(2036, 3, 1));
  await tick();
  newGeneration();
  const second = ensureRangeLoaded(at(2036, 2, 1), at(2036, 3, 1));
  await tick();
  newGeneration();
  const third = ensureRangeLoaded(at(2036, 2, 1), at(2036, 3, 1));
  await tick();
  eq('generations: each asks once', holds.length, 3);
  holds[1]();
  await tick();
  eq('generations: the second lands first and shows', st.occ.get('v1').title, 'Version 2');
  holds[2]();
  await tick();
  eq('generations: the newest replaces it', st.occ.get('v1').title, 'Version 3');
  holds[0]();
  await Promise.all([viewLoad, second, third]);
  eq('generations: the oldest, landing last, is ignored, and nothing is asked again', [st.occ.get('v1').title, holds.length, fresh(at(2036, 2, 1), at(2036, 3, 1)), st.windowStatus], ['Version 3', 3, true, null]);

  // The change check: a pretend server whose cursor moves.
  const server = { cursor: 'k1', hold: null, failEvents: false, failCalendars: false, events: 0, calendars: 0 };
  globalThis.fetch = async (url) => {
    const u = new URL(String(url), 'http://app.test');
    const p = u.pathname.replace('/api/v1', '');
    if (p === '/changes/cursor') return json({ cursor: server.cursor, review: 'r1', status: 's1' });
    if (p === '/events') {
      server.events++;
      if (server.hold) await server.hold;
      return server.failEvents ? down() : json({ events: [] });
    }
    if (p === '/calendars') {
      server.calendars++;
      return server.failCalendars ? down() : json({ calendars: st.calendars, folders: st.folders, tags: st.tags });
    }
    if (p === '/people') return json({ people: st.people });
    if (p === '/views') return json({ views: st.savedViews });
    if (p === '/review/count') return json({ count: st.reviewCount });
    return json({});
  };
  put({ loadedRanges: [], windowStatus: null, visibleDays: null });
  st.occ.clear();
  await loadWindow(at(2036, 5, 1), at(2036, 5, 8));
  await checkChanges(); // the baseline

  // The cursor moves twice more while the window's reload is on its way:
  // it isn't asked for twice at once, and exactly one further reload
  // follows, at the newest generation.
  server.events = 0;
  let release;
  server.hold = new Promise((r) => { release = r; });
  server.cursor = 'k2';
  const c1 = checkChanges();
  await tick();
  eq('check: a moved cursor reloads the window', server.events, 1);
  server.cursor = 'k3';
  const c2 = checkChanges();
  await tick();
  server.cursor = 'k4';
  const c3 = checkChanges();
  await tick();
  eq('check: moves seen while it is on its way ask nothing more yet', server.events, 1);
  server.hold = null;
  release();
  await Promise.all([c1, c2, c3]);
  eq('check: then exactly one further reload', [server.events, isOwed('window'), fresh(at(2036, 5, 1), at(2036, 5, 8))], [2, false, true]);

  // The window and the calendar list both fail: both stay owed, and the
  // next check that reaches the server reloads them together.
  server.failEvents = true;
  server.failCalendars = true;
  server.cursor = 'k5';
  await checkChanges();
  eq('check: a failed window and calendar list are both owed', [isOwed('window'), isOwed('calendars'), st.windowStatus && st.windowStatus.kind], [true, true, 'failed']);
  server.failEvents = false;
  server.failCalendars = false;
  const was = [server.events, server.calendars];
  await checkChanges(); // nothing moved
  eq('check: the next check reloads them together', [server.events - was[0], server.calendars - was[1], isOwed('window'), isOwed('calendars'), st.windowStatus], [1, 1, false, false, null]);

  put({ loadedRanges: [], windowStatus: null, visibleDays: null });
  st.occ.clear();
  globalThis.fetch = realFetch;
}

// --- Loading the calendar's events: the review of one fetch path (#190) ---
{
  const realFetch = globalThis.fetch;
  const { loadWindow, refreshWindow, ensureRangeLoaded, afterUndo } = await import('../src/app/api.js');
  const { isOwed, runOwed } = await import('../src/app/reloads.js');
  const { checkChanges } = await import('../src/app/changecheck.js');
  const { moveEvent } = await import('../src/app/actions.js');
  const { state: st, set: put } = await import('../src/app/store.js');
  const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  const down = () => json({ error: { code: 'server', message: 'Down for a moment' } }, 500);
  const tick = async (n = 10) => { for (let i = 0; i < n; i++) await new Promise((r) => setTimeout(r, 0)); };
  const at = (y, m, d, h = 0) => isoAt(new Date(y, m - 1, d, h));
  const fresh = (a, b) => missingRanges(Date.parse(a), Date.parse(b), freshRanges()).length === 0;
  const reset = () => { put({ loadedRanges: [], windowStatus: null, visibleDays: null, popover: null }); st.occ.clear(); };
  const gate = () => { let open; const p = new Promise((r) => { open = r; }); return { p, open }; };
  const path = (url) => new URL(String(url), 'http://app.test');
  const inRange = (u, o) => Date.parse(o.start) < Date.parse(u.searchParams.get('end')) && Date.parse(o.end) > Date.parse(u.searchParams.get('start'));
  const lists = (p) => {
    if (p === '/calendars') return json({ calendars: st.calendars, folders: st.folders, tags: st.tags });
    if (p === '/people') return json({ people: st.people });
    if (p === '/views') return json({ views: st.savedViews });
    if (p === '/review/count') return json({ count: st.reviewCount });
    return json({});
  };

  // 1. Past the cache's ceiling, a window the view moved on from used to be
  // pruned away after every request and asked for again without end. It
  // now stops once it isn't the view's, and only the view's load prunes.
  reset();
  const crowd = [];
  for (let i = 0; i < 24990; i++) {
    const t = Date.UTC(2031, 0, 2) + i * 60000;
    crowd.push({ instanceId: 'c' + i, eventId: 700000 + i, calendarId: 1, title: 'Sample crowd', start: isoAt(new Date(t)), end: isoAt(new Date(t + 30000)), allDay: false });
  }
  let olderAsks = 0;
  let asksAll = 0;
  const olderGate = gate();
  globalThis.fetch = async (url) => {
    const u = path(url);
    if (u.pathname.endsWith('/changes/cursor')) return json({ cursor: 'c-sample' });
    asksAll++;
    if (asksAll > 40) return down(); // a loop with no end stops here, and fails the checks below
    if (Date.parse(u.searchParams.get('start')) < Date.parse(at(2031, 1, 1))) {
      olderAsks++;
      if (olderAsks === 1) await olderGate.p;
      return json({ events: Array.from({ length: 20 }, (_, i) => ({ instanceId: 'o' + i, eventId: 710000 + i, calendarId: 1, title: 'Sample older', start: at(2030, 6, 2 + i, 9), end: at(2030, 6, 2 + i, 10), allDay: false })) });
    }
    return json({ events: crowd });
  };
  const older = loadWindow(at(2030, 6, 1), at(2030, 7, 1));
  await tick();
  await loadWindow(at(2031, 1, 1), at(2031, 2, 1));
  olderGate.open();
  await older;
  await tick();
  eq('cache ceiling: a window the view moved on from stops asking', [olderAsks, asksAll], [1, 2]);
  eq("cache ceiling: and the view's window stays fresh", [fresh(at(2031, 1, 1), at(2031, 2, 1)), st.windowStatus], [true, null]);
  crowd.length = 0;

  // 2. Shown but not fresh: days on screen held from before a save or a
  // change elsewhere stay drawn while they're asked for again, with no
  // "Loading"; only days never loaded say it.
  reset();
  const server = { cursor: 'n1', hold: null, events: 0 };
  globalThis.fetch = async (url) => {
    const u = path(url);
    const p = u.pathname.replace('/api/v1', '');
    if (p === '/changes/cursor') return json({ cursor: server.cursor, review: 'r1', status: 's1' });
    if (p === '/events') { server.events++; if (server.hold) await server.hold; return json({ events: [] }); }
    return lists(p);
  };
  put({ visibleDays: { start: '2032-03-08', end: '2032-03-21' } });
  await loadWindow(at(2032, 3, 1), at(2032, 4, 1));
  await checkChanges(); // the baseline
  let g = gate();
  server.hold = g.p;
  server.cursor = 'n2'; // this tab's save
  let asked = server.events;
  const saving = refreshWindow();
  await tick();
  const duringSave = [st.windowStatus, server.events > asked];
  server.hold = null;
  g.open();
  await saving;
  eq("shown but not fresh: a save's refresh says nothing over days already showing", [...duringSave, st.windowStatus], [null, true, null]);
  g = gate();
  server.hold = g.p;
  server.cursor = 'n3'; // a change from another device
  asked = server.events;
  const checking = checkChanges();
  await tick();
  const duringCheck = [st.windowStatus, server.events > asked];
  server.hold = null;
  g.open();
  await checking;
  eq('shown but not fresh: nor does a change from elsewhere', [...duringCheck, st.windowStatus, fresh(at(2032, 3, 1), at(2032, 4, 1))], [null, true, null, true]);
  put({ visibleDays: { start: '2032-03-25', end: '2032-04-07' } });
  newGeneration(); // March held, not fresh; April never loaded
  g = gate();
  server.hold = g.p;
  const jump = loadWindow(at(2032, 3, 15), at(2032, 4, 15));
  await tick();
  const duringJump = st.windowStatus && st.windowStatus.kind;
  server.hold = null;
  g.open();
  await jump;
  eq('shown but not fresh: days on screen never loaded still say Loading', [duringJump, st.windowStatus], ['loading', null]);

  // 3 and 4. Saves to one event in flight together (the server's instanceId
  // carries the start, as the real one's does).
  const srv = { at: null, title: 'Sample review', version: 1, patches: [] };
  const one = (start) => ({ instanceId: '7@' + Date.parse(start), eventId: 7, calendarId: 1, title: srv.title, start, end: isoAt(new Date(Date.parse(start) + 3600000)), allDay: false });
  globalThis.fetch = async (url, o = {}) => {
    const u = path(url);
    const p = u.pathname.replace('/api/v1', '');
    if (p === '/changes/cursor') return json({ cursor: 'm' + srv.version });
    if (p === '/events/7' && o.method === 'PATCH') {
      const body = JSON.parse(o.body);
      const turn = gate();
      srv.patches.push(turn);
      if (!(await turn.p)) return down();
      srv.at = body.start;
      srv.version++;
      return json({ ok: true });
    }
    if (p === '/events') return json({ events: inRange(u, one(srv.at)) ? [one(srv.at)] : [] });
    return lists(p);
  };
  const nov = (d) => at(2031, 11, d, 10);
  const toNov = (d) => ({ newStart: nov(d), newEnd: at(2031, 11, d, 11) });
  const shown = () => [...st.occ.values()].filter((x) => x.eventId === 7).map((x) => x.start);
  const begin = async () => {
    reset();
    srv.at = nov(3);
    srv.title = 'Sample review';
    srv.patches.length = 0;
    await loadWindow(at(2031, 11, 1), at(2031, 12, 1));
    return [...st.occ.values()].find((x) => x.eventId === 7).instanceId;
  };
  const twoMoves = async (id) => {
    const a = moveEvent({ instanceId: id, ...toNov(4) });
    await tick();
    const b = moveEvent({ instanceId: id, ...toNov(6) });
    await tick();
    return [a, b];
  };
  const answerNow = () => mergeWindow(at(2031, 11, 1), at(2031, 12, 1), [one(srv.at)], dataGeneration());

  // 3. Moved to the 4th, then the 6th, both landing: the first's refresh,
  // asked before the second reached the server, no longer puts it back on
  // the 4th (it jumped the 6th, the 4th, the 6th).
  let id = await begin();
  let [a, b] = await twoMoves(id);
  srv.patches[0].open(true);
  await a;
  await tick();
  const afterFirst = shown();
  srv.patches[1].open(true);
  await b;
  await tick();
  eq("two saves: the first landing leaves the second's copy on screen", afterFirst, [nov(6)]);
  eq("two saves: and the second's refresh shows it, once", [shown(), [...st.occ.keys()].filter((k) => k.startsWith('7@'))], [[nov(6)], ['7@' + Date.parse(nov(6))]]);

  // 4. Both failing: back where the server has it, and the next answer
  // replaces it (it held the first failed position against every answer).
  id = await begin();
  [a, b] = await twoMoves(id);
  srv.patches[0].open(false);
  await a;
  const oneFailed = shown();
  srv.patches[1].open(false);
  await b;
  const bothFailed = shown();
  srv.title = 'Sample review, renamed elsewhere';
  answerNow();
  eq('failed saves: the later copy stays while it is on its way, then the server copy', [oneFailed, bothFailed], [[nov(6)], [nov(3)]]);
  eq('failed saves: and the next answer replaces it', [...st.occ.values()].filter((x) => x.eventId === 7).map((x) => [x.start, x.title]), [[nov(3), 'Sample review, renamed elsewhere']]);
  // Failing the other way round: the earlier save's copy, then the server's.
  id = await begin();
  [a, b] = await twoMoves(id);
  srv.patches[1].open(false);
  await b;
  const laterFailed = shown();
  srv.patches[0].open(false);
  await a;
  eq('failed saves: in either order', [laterFailed, shown()], [[nov(4)], [nov(3)]]);
  answerNow();
  // The first landing and the second failing: the first one's copy, held
  // until an answer carries it.
  id = await begin();
  [a, b] = await twoMoves(id);
  srv.patches[0].open(true);
  await a;
  await tick();
  srv.patches[1].open(false);
  await b;
  const landedThenFailed = shown();
  srv.title = 'Sample review, carried';
  answerNow();
  eq('failed saves: after one that landed, its copy shows, until an answer carries it', [landedThenFailed, st.occ.get('7@' + Date.parse(nov(4))) && st.occ.get('7@' + Date.parse(nov(4))).title, shown()], [[nov(4)], 'Sample review, carried', [nov(4)]]);

  // 5. The drop rule is by occurrence: an older answer for January doesn't
  // overwrite an event running into a February held from a newer one, nor
  // bring back one deleted elsewhere.
  reset();
  const span = (title) => ({ instanceId: 'sp1', eventId: 81, calendarId: 1, title, start: '2033-01-31T20:00:00Z', end: '2033-02-03T08:00:00Z', allDay: false });
  const jan = ['2033-01-01T00:00:00Z', '2033-02-01T00:00:00Z'];
  const feb = ['2033-02-01T00:00:00Z', '2033-03-01T00:00:00Z'];
  let olderGen = dataGeneration();
  newGeneration();
  mergeWindow(...feb, [span('Now')], dataGeneration());
  const janOnly = { instanceId: 'j1', eventId: 82, calendarId: 1, title: 'Sample January', start: '2033-01-10T09:00:00Z', end: '2033-01-10T10:00:00Z', allDay: false };
  const mergedJan = mergeWindow(...jan, [span('Before'), janOnly], olderGen);
  eq('by occurrence: an older answer keeps its own days, not the newer copy of one running on', [mergedJan, st.occ.get('sp1').title, st.occ.has('j1'), fresh(...feb)], [true, 'Now', true, true]);
  reset();
  olderGen = dataGeneration();
  newGeneration();
  mergeWindow(...feb, [], dataGeneration()); // deleted elsewhere
  mergeWindow(...jan, [span('Before')], olderGen);
  eq('by occurrence: nor brings back one deleted elsewhere', [st.occ.has('sp1'), fresh(...feb)], [false, true]);

  // 6. A capped series the answer shows none of (hidden by a filter on an
  // older server, or only edited dates) doesn't stop the follow-up for
  // the one shown, and says nothing about it.
  reset();
  const HOUR = 3600000;
  const first = Date.UTC(2037, 0, 1);
  let asks6 = 0;
  globalThis.fetch = async (url) => {
    const u = path(url);
    if (u.pathname.endsWith('/changes/cursor')) return json({ cursor: 'c-sample' });
    asks6++;
    const s = Date.parse(u.searchParams.get('start'));
    const e = Date.parse(u.searchParams.get('end'));
    const out = [];
    if (s < Date.UTC(2037, 3, 1) && e > Date.UTC(2037, 2, 10)) out.push({ instanceId: '9603@x', eventId: 9603, calendarId: 1, title: 'Sample edited date', start: isoAt(new Date(Date.UTC(2037, 2, 10, 9))), end: isoAt(new Date(Date.UTC(2037, 2, 10, 10))), allDay: false, recurring: true, isException: true });
    for (let k = Math.max(0, Math.ceil((s - HOUR / 2 - first) / HOUR)); first + k * HOUR < e; k++) {
      const t = first + k * HOUR;
      if (t + HOUR / 2 <= s) continue;
      if (out.length === 1000) return json({ events: out, capped: [601, 602, 603] });
      out.push({ instanceId: '601:' + k, eventId: 601, calendarId: 1, title: 'Sample hourly', start: isoAt(new Date(t)), end: isoAt(new Date(t + HOUR / 2)), allDay: false, recurring: true });
    }
    return json({ events: out, capped: [602, 603] });
  };
  const toastsBefore = st.toasts.length;
  await loadWindow(at(2037, 3, 1), at(2037, 6, 1));
  const hours6 = Math.round((Date.parse(at(2037, 6, 1)) - Date.parse(at(2037, 3, 1))) / HOUR);
  const held6 = [...st.occ.values()].filter((o) => o.eventId === 601 && Date.parse(o.start) >= Date.parse(at(2037, 3, 1)) && Date.parse(o.start) < Date.parse(at(2037, 6, 1))).length;
  eq('capped: a series shown with none of its own rows does not stop the others', [held6, asks6, fresh(at(2037, 3, 1), at(2037, 6, 1))], [hours6, Math.ceil(hours6 / 1000), true]);
  eq('capped: and nothing is said about it', st.toasts.slice(toastsBefore).filter((t) => t.text.includes('repeats more often')).length, 0);
  // Too dense even for twelve requests, loaded cold: the toast names it
  // from the answer.
  reset();
  const minute = 60000;
  const first2 = Date.UTC(2038, 6, 1);
  let asks6b = 0;
  globalThis.fetch = async (url) => {
    const u = path(url);
    asks6b++;
    const s = Date.parse(u.searchParams.get('start'));
    const e = Date.parse(u.searchParams.get('end'));
    const out = [];
    for (let k = Math.max(0, Math.ceil((s - first2) / minute)); first2 + k * minute < e; k++) {
      if (out.length === 1000) return json({ events: out, capped: [604] });
      out.push({ instanceId: '604:' + k, eventId: 604, calendarId: 1, title: 'Sample every minute', start: isoAt(new Date(first2 + k * minute)), end: isoAt(new Date(first2 + k * minute + 30000)), allDay: false, recurring: true });
    }
    return json({ events: out });
  };
  const toastsBefore2 = st.toasts.length;
  await loadWindow(at(2038, 7, 1), at(2038, 8, 1));
  eq('capped: one too dense is asked for twelve times and named from the answer', [asks6b, st.toasts.slice(toastsBefore2).filter((t) => t.text.includes('"Sample every minute"')).length], [12, 1]);

  // 7. A single event moved: an answer asked before its save landed, from
  // after the server moved it, names it by its new start. It replaces
  // nothing until an answer carries the save, then replaces the local copy.
  reset();
  const single = (id, start) => ({ instanceId: id, eventId: 5, calendarId: 1, title: 'Sample single', start, end: isoAt(new Date(Date.parse(start) + 3600000)), allDay: false });
  st.occ.set('5@old', single('5@old', '2034-11-03T10:00:00Z'));
  const beforeMove = patchOccurrence('5@old', { start: '2034-11-04T10:00:00Z', end: '2034-11-04T11:00:00Z' });
  const nov34 = ['2034-11-01T00:00:00Z', '2034-12-01T00:00:00Z'];
  mergeWindow(...nov34, [single('5@new', '2034-11-04T10:00:00Z')], dataGeneration());
  const interleaved = [...st.occ.keys()];
  localSaved([beforeMove]);
  newGeneration();
  mergeWindow(...nov34, [single('5@new', '2034-11-04T10:00:00Z')], dataGeneration());
  eq('moved single event: shown once while the save is on its way, then as the answer names it', [interleaved, [...st.occ.keys()]], [['5@old'], ['5@new']]);
  // The save's refresh missed where it sits now (the view moved on), then
  // another device moves it into the window: an answer carrying the save
  // names it, wherever, and its copy stands in place of the local one.
  reset();
  const single77 = (id, start) => ({ ...single(id, start), eventId: 77 });
  const oct35 = ['2035-10-01T00:00:00Z', '2035-11-01T00:00:00Z'];
  st.occ.set('77@a', single77('77@a', '2035-10-30T10:00:00Z'));
  const before77 = patchOccurrence('77@a', { start: '2035-11-02T10:00:00Z', end: '2035-11-02T11:00:00Z' });
  mergeWindow(...oct35, [single77('77@b', '2035-10-20T10:00:00Z')], dataGeneration());
  const whilePending = [...st.occ.values()].map((o) => o.start);
  localSaved([before77]);
  mergeWindow(...oct35, [single77('77@b', '2035-10-20T10:00:00Z')], dataGeneration());
  const askedBefore = [...st.occ.values()].map((o) => o.start);
  newGeneration(); // the save's refresh, asking October only
  mergeWindow(...oct35, [], dataGeneration());
  newGeneration(); // the change check: moved elsewhere
  mergeWindow(...oct35, [single77('77@b', '2035-10-20T10:00:00Z')], dataGeneration());
  eq('moved single event: an answer carrying the save names it outside the local place, and its copy stands alone', [whilePending, askedBefore, [...st.occ.values()].map((o) => o.start)], [['2035-11-02T10:00:00Z'], ['2035-11-02T10:00:00Z'], ['2035-10-20T10:00:00Z']]);

  // 8. A failing health read stays owed and is asked again, but plugins and
  // their bands are fetched once for the move that owed them.
  reset();
  const srv8 = { status: 's1', health: 0, plugins: 0 };
  globalThis.fetch = async (url) => {
    const p = path(url).pathname.replace('/api/v1', '');
    if (p === '/changes/cursor') return json({ cursor: 'n3', review: 'r1', status: srv8.status });
    if (p === '/system/health') { srv8.health++; return down(); }
    if (p === '/plugins') { srv8.plugins++; return json({ plugins: [] }); }
    if (p === '/events') return json({ events: [] });
    return lists(p);
  };
  await checkChanges(); // the baseline
  const was8 = { health: srv8.health, plugins: srv8.plugins, seq: st.pluginSeq };
  srv8.status = 's2';
  await checkChanges();
  for (let i = 0; i < 3; i++) await checkChanges();
  eq('status: a failing health read stays owed and is asked at each check', [isOwed('status'), srv8.health - was8.health], [true, 4]);
  eq('status: plugins and their bands are fetched once for the move', [srv8.plugins - was8.plugins, st.pluginSeq - was8.seq, isOwed('plugins')], [1, 1, false]);

  // 9. Two loads at once: a month the second asked for while the first was
  // on an earlier one isn't asked for again by the first.
  reset();
  const asks9 = new Map();
  const holds9 = new Map();
  globalThis.fetch = async (url) => {
    const u = path(url);
    const s = Date.parse(u.searchParams.get('start'));
    asks9.set(s, (asks9.get(s) || 0) + 1);
    if (holds9.has(s)) await holds9.get(s).p;
    return json({ events: [] });
  };
  mergeWindow(at(2039, 2, 1), at(2039, 3, 1), [], dataGeneration()); // February held and fresh
  const janGate = gate();
  const marGate = gate();
  holds9.set(Date.parse(at(2039, 1, 1)), janGate);
  holds9.set(Date.parse(at(2039, 3, 1)), marGate);
  const loadA = ensureRangeLoaded(at(2039, 1, 1), at(2039, 4, 1));
  await tick();
  const loadB = ensureRangeLoaded(at(2039, 3, 1), at(2039, 4, 1));
  await tick();
  janGate.open();
  await tick();
  marGate.open();
  await Promise.all([loadA, loadB]);
  eq('in flight: a month another load asked for meanwhile is asked for once', [asks9.get(Date.parse(at(2039, 1, 1))), asks9.get(Date.parse(at(2039, 3, 1))), fresh(at(2039, 1, 1), at(2039, 4, 1))], [1, 1, true]);

  // 10. An undo's settings are owed like the rest: not adopted while the
  // Settings page is open, and asked for once it's left.
  reset();
  let settingsAsks = 0;
  globalThis.fetch = async (url) => {
    const p = path(url).pathname.replace('/api/v1', '');
    if (p === '/settings') { settingsAsks++; return json({ settings: { ...st.settings } }); }
    if (p === '/changes/cursor') return json({ cursor: 'n3', review: 'r1', status: 's2' });
    if (p === '/events') return json({ events: [] });
    return lists(p);
  };
  const routeWas = st.route;
  put({ route: 'settings' });
  await afterUndo();
  await tick();
  const onPage = [settingsAsks, isOwed('settings')];
  put({ route: routeWas });
  await runOwed();
  eq("undo: settings are owed while the Settings page is open, then asked for", [...onPage, settingsAsks, isOwed('settings')], [0, true, 1, false]);

  reset();
  globalThis.fetch = realFetch;
}

// --- Drags: only the pointer that started one moves or drops it (#185) ---
{
  const hadWindow = 'window' in globalThis;
  const hadDocument = 'document' in globalThis;
  const savedWindow = globalThis.window;
  const savedDocument = globalThis.document;
  const win = new EventTarget();
  win.innerWidth = 1000; win.innerHeight = 800;
  globalThis.window = win;
  globalThis.document = { body: { classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } }, appendChild() {} }, elementFromPoint: () => null };
  const realRaf = globalThis.requestAnimationFrame;
  globalThis.requestAnimationFrame = () => 0;
  globalThis.cancelAnimationFrame = () => {};
  const { startPointerDrag } = await import('../src/ui/DragController.js');
  const pe = (type, id, x, y) => { const e = new Event(type); Object.assign(e, { pointerId: id, pointerType: 'touch', clientX: x, clientY: y, buttons: 1, altKey: false }); return e; };
  const down = { pointerId: 1, pointerType: 'touch', clientX: 100, clientY: 100, button: 0, altKey: false, currentTarget: null, target: null, preventDefault() {}, stopPropagation() {} };
  const drops = [];
  const moves = [];
  startPointerDrag(down, { onMove: (pt) => moves.push(pt), onDrop: (pt) => drops.push(pt), onCancel: () => drops.push('cancel') });
  await new Promise((r) => setTimeout(r, 700)); // the long press lifts it
  win.dispatchEvent(pe('pointerdown', 2, 400, 400));
  win.dispatchEvent(pe('pointermove', 2, 420, 420));
  win.dispatchEvent(pe('pointerup', 2, 420, 420));
  eq("drag: a second finger doesn't move or drop it", [moves.some((m) => m.x === 420), drops.length], [false, 0]);
  // Nor, pressed on another event, starts a drag of its own beside it.
  const other = [];
  startPointerDrag({ ...down, pointerId: 3, clientX: 300, clientY: 300 }, { onLift: () => other.push('lift'), onDrop: (pt) => other.push(pt), onCancel: () => other.push('cancel') });
  await new Promise((r) => setTimeout(r, 700));
  win.dispatchEvent(pe('pointermove', 3, 320, 320));
  win.dispatchEvent(pe('pointerup', 3, 320, 320));
  eq('drag: a second finger on another event starts no drag beside it', [other, drops.length], [[], 0]);
  win.dispatchEvent(pe('pointermove', 1, 150, 150));
  win.dispatchEvent(pe('pointerup', 1, 150, 150));
  eq('drag: its own finger still drops it, where that finger is', drops.map((d) => d.x), [150]);
  if (hadWindow) globalThis.window = savedWindow; else delete globalThis.window;
  if (hadDocument) globalThis.document = savedDocument; else delete globalThis.document;
  globalThis.requestAnimationFrame = realRaf;
}

// --- Next month mid-glide (#185) ---
{
  const hadWindow = 'window' in globalThis;
  const savedWindow = globalThis.window;
  if (!hadWindow) globalThis.window = { addEventListener() {}, removeEventListener() {}, matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }), location: { href: 'http://app.test/' } };
  const { stepAnchor } = await import('../src/app/actions.js');
  const { setGlideActive } = await import('../src/lib/glide.js');
  const { state: st, set: put } = await import('../src/app/store.js');
  const saved = { view: st.view, anchor: st.anchor, visibleMonth: st.visibleMonth, visibleDays: st.visibleDays, scrollSeq: st.scrollSeq, glideSeq: st.glideSeq, anchorSeen: st.anchorSeen, viewportNarrow: st.viewportNarrow, settings: st.settings };
  // One reading of "on the way" (actions.js arrived): the view has not yet
  // shown the anchor since the move, or the grid is still gliding there.
  // Until then a step starts from the anchor's month, where it is going;
  // after, from the month the view names.
  const n = (st.scrollSeq || 0) + 1;
  put({ view: 'month', viewportNarrow: false, anchor: '2027-03-15', visibleMonth: { year: 2026, month: 8 }, visibleDays: { start: '2026-07-26', end: '2026-08-22' }, scrollSeq: n, glideSeq: n, anchorSeen: n - 1 });
  setGlideActive(false);
  stepAnchor(1);
  eq('next month: before the grid has shown the anchor, steps from where it is going', st.anchor, '2027-04-15');
  // The anchor came on screen (the report after the move) but the glide has
  // not stopped: rows changing height mid-glide left the grid months short
  // of where it was going; Next stepped from there and the grid turned round.
  put({ visibleMonth: { year: 2027, month: 3 }, visibleDays: { start: '2027-03-21', end: '2027-04-24' }, anchorSeen: st.scrollSeq });
  setGlideActive(true);
  stepAnchor(1);
  eq('next month: while the grid glides, steps from where it is going', st.anchor, '2027-05-15');
  setGlideActive(false);
  // Arrived and scrolled off: from the month on screen.
  put({ anchor: '2027-03-15', visibleMonth: { year: 2026, month: 8 }, visibleDays: { start: '2026-07-26', end: '2026-08-22' }, anchorSeen: st.scrollSeq });
  stepAnchor(1);
  eq('next month: arrived, from the month on screen', st.anchor, '2026-09-15');
  // Arrived and scrolled a little, the anchor still on screen but the grid
  // naming the next month: the grid's window frames a month, so what it
  // names is where you are.
  put({ anchor: '2026-07-15', visibleMonth: { year: 2026, month: 8 }, visibleDays: { start: '2026-07-12', end: '2026-08-22' }, anchorSeen: st.scrollSeq });
  stepAnchor(1);
  eq('next month: the grid names August with July\'s anchor still on screen, Next goes to September', st.anchor, '2026-09-15');
  put({ anchor: '2026-07-15', visibleMonth: { year: 2026, month: 8 }, visibleDays: { start: '2026-07-12', end: '2026-08-22' }, anchorSeen: st.scrollSeq });
  stepAnchor(-1);
  eq('prev month: and Prev goes to July', st.anchor, '2026-07-15');
  // The phone's 3-day ribbon can't frame a month: a 1st landed on still
  // reads as the month before, so the anchor's month stands while the
  // anchor is on screen (#184), in both directions.
  put({ viewportNarrow: true, settings: { ...(st.settings || {}), overviewMode: '3day' }, anchor: '2026-09-01', visibleMonth: { year: 2026, month: 8 }, visibleDays: { start: '2026-08-24', end: '2026-09-07' }, anchorSeen: st.scrollSeq });
  stepAnchor(1);
  eq('next month: the ribbon on the 1st, named the month before, steps on from the 1st', st.anchor, '2026-10-01');
  put({ anchor: '2026-09-01', visibleMonth: { year: 2026, month: 8 }, visibleDays: { start: '2026-08-24', end: '2026-09-07' }, anchorSeen: st.scrollSeq });
  stepAnchor(-1);
  eq('prev month: the ribbon on the 1st steps back from the 1st', st.anchor, '2026-08-01');
  put({ anchor: '2026-09-01', visibleMonth: { year: 2026, month: 9 }, visibleDays: { start: '2026-09-20', end: '2026-10-04' }, anchorSeen: st.scrollSeq });
  stepAnchor(1);
  eq('next month: the ribbon scrolled off the 1st steps from the month it names', st.anchor, '2026-10-01');
  // The agenda shows the next month's events for a month with nothing in
  // it, and names that month: Prev into the empty month, then Prev again,
  // steps on from the month gone to; Next from it goes back (#184).
  put({ view: 'agenda', viewportNarrow: false, anchor: '2026-09-01', visibleMonth: { year: 2026, month: 10 }, visibleDays: { start: '2026-09-01', end: '2026-10-03' }, anchorSeen: st.scrollSeq });
  stepAnchor(-1);
  eq('prev month: the agenda in an empty month steps on from that month', st.anchor, '2026-08-01');
  put({ anchor: '2026-09-01', visibleMonth: { year: 2026, month: 10 }, visibleDays: { start: '2026-09-01', end: '2026-10-03' }, anchorSeen: st.scrollSeq });
  stepAnchor(1);
  eq('next month: the agenda in an empty month steps on from that month', st.anchor, '2026-10-01');
  put({ anchor: '2026-10-01', visibleMonth: { year: 2026, month: 12 }, visibleDays: { start: '2026-12-03', end: '2026-12-10' }, anchorSeen: st.scrollSeq });
  stepAnchor(1);
  eq('next month: the agenda scrolled off its anchor steps from the month it names', st.anchor, '2027-01-01');
  put(saved);
  if (!hadWindow) delete globalThis.window; else globalThis.window = savedWindow;
}

// --- A glide's rules (#190) ---
{
  const { glideDistance, samePlace } = await import('../src/lib/glide.js');
  const { fixedGeometry, fitGeometry } = await import('../src/ui/monthmath.js');
  // Over when within a pixel of the target, or of as far as the scroller goes.
  eq('glide: arrived', glideDistance(1000, 1000.5, 5000, 40), null);
  eq('glide: arrived at the end of the scroller, short of the target', glideDistance(4000, 4800, 4000, 300), null);
  // Closing in: the distance left, from the first scroll on.
  eq('glide: the first scroll', glideDistance(500, 1000, 5000, null), 500);
  eq('glide: closing in', glideDistance(900, 1000, 5000, 200), 100);
  eq('glide: a pixel of jitter is still closing in', glideDistance(801, 1000, 5000, 198), 199);
  // Farther off than at the last scroll is you scrolling.
  eq('glide: moving away ends it', glideDistance(700, 1000, 5000, 200), null);
  // The same week, the same way into it, in another geometry.
  const fixed100 = fixedGeometry(0, 100, 100);
  const fixed150 = fixedGeometry(0, 100, 150);
  eq('same place: the geometry unchanged', samePlace(fixed100, fixed100, 250), 250);
  eq('same place: every row taller (a resize) scales into the week', samePlace(fixed100, fixed150, 250), 375);
  const plain = fitGeometry(0, 100, 100, new Map());
  eq('same place: a week above grows, the place moves down with it', samePlace(plain, fitGeometry(0, 100, 100, new Map([[1, 300]])), 250), 450);
  eq('same place: the week itself grows, the same fraction into it', samePlace(plain, fitGeometry(0, 100, 100, new Map([[2, 200]])), 250), 300);
  eq('same place: a week below grows, nothing moves', samePlace(plain, fitGeometry(0, 100, 100, new Map([[3, 200]])), 250), 250);
  eq('same place: back from fitted to fixed rows', samePlace(fitGeometry(0, 100, 100, new Map([[1, 300]])), fixed100, 450), 250);
}

// --- Calendars in a folder's own order, and where a dragged one lands (#187) ---
{
  const { sortedCalendars, calendarDrop } = await import('../src/lib/calorder.js');
  const cal = (id, name, folderIds = []) => ({ id, name, folderIds });
  const cals = [cal(1, 'Work', [5]), cal(2, 'birthdays', [5]), cal(3, 'Alpha', [5, 6]), cal(4, 'Unfiled')];
  const ids = (list) => list.map((c) => c.id);
  eq('folder order: its own, as the server sends it', ids(sortedCalendars(cals, { id: 5, calendarIds: [3, 1, 2], sort: 'manual' })), [3, 1, 2]);
  eq('folder order: A to Z, whatever the case', ids(sortedCalendars(cals, { id: 5, calendarIds: [3, 1, 2], sort: 'az' })), [3, 2, 1]);
  eq('folder order: one filed but not listed yet goes last', ids(sortedCalendars(cals, { id: 5, calendarIds: [1], sort: 'manual' })), [1, 2, 3]);
  eq('folder order: one listed but no longer filed is left out', ids(sortedCalendars(cals, { id: 6, calendarIds: [1, 3], sort: 'manual' })), [3]);
  eq('main list: the calendars in their order, or A to Z', [ids(sortedCalendars(cals, { sort: 'manual' })), ids(sortedCalendars(cals, { sort: 'az' }))], [[1, 2, 3, 4], [3, 2, 4, 1]]);

  const order = [3, 1, 2];
  eq('drop: over the top half of a row, before it', calendarDrop({ calId: 2, from: 5, to: 5, toSort: 'manual', order, overId: 3, after: false }), { kind: 'place', folderId: 5, at: 0, from: null });
  eq('drop: over the bottom half, after it', calendarDrop({ calId: 3, from: 5, to: 5, toSort: 'manual', order, overId: 1, after: true }), { kind: 'place', folderId: 5, at: 1, from: null });
  eq('drop: where it already is, nothing', calendarDrop({ calId: 1, from: 5, to: 5, toSort: 'manual', order, overId: 1, after: false }), null);
  eq('drop: within a folder listed A to Z, nothing', calendarDrop({ calId: 2, from: 5, to: 5, toSort: 'az', order, overId: 3 }), null);
  eq('drop: into another folder, out of the first', calendarDrop({ calId: 1, from: 5, to: 6, toSort: 'manual', order: [3], overId: 3, after: true }), { kind: 'place', folderId: 6, at: 1, from: 5 });
  eq('drop: with Option, in both', calendarDrop({ calId: 1, from: 5, to: 6, toSort: 'manual', order: [3], overId: 3, after: true, copy: true }), { kind: 'place', folderId: 6, at: 1, from: null });
  eq('drop: into an A to Z folder, at the end of its own order', calendarDrop({ calId: 4, from: 'all', to: 6, toSort: 'az', order: [3], overId: 3 }), { kind: 'place', folderId: 6, at: 1, from: null });
  eq('drop: from a folder onto the main list, out of the folder', calendarDrop({ calId: 1, from: 5, to: 'all', toSort: 'manual', order: [1, 2, 3, 4], overId: 4 }), { kind: 'unfile', folderId: 5 });
  eq('drop: within the main list, a new place', calendarDrop({ calId: 4, from: 'all', to: 'all', toSort: 'manual', order: [1, 2, 3, 4], overId: 1 }), { kind: 'list', at: 0 });
  eq('drop: within the main list A to Z, nothing', calendarDrop({ calId: 4, from: 'all', to: 'all', toSort: 'az', order: [1, 2, 3, 4], overId: 1 }), null);
  eq('drop: onto People or Plugins, nothing', calendarDrop({ calId: 4, from: 'all', to: null, toSort: 'manual', order: [] }), null);
}

// --- A calendar's role change reloads the events on screen (#186) ---
{
  const hadWindow = 'window' in globalThis;
  const savedWindow = globalThis.window;
  if (!hadWindow) globalThis.window = { addEventListener() {}, removeEventListener() {}, matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }), location: { href: 'http://app.test/' } };
  const realFetch = globalThis.fetch;
  const { updateCalendar } = await import('../src/app/actions.js');
  const { loadWindow } = await import('../src/app/api.js');
  const { state: st, set: put } = await import('../src/app/store.js');
  const json = (body) => new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
  let windows = 0;
  let role = 'mine';
  globalThis.fetch = async (url, o) => {
    const u = new URL(String(url), 'http://app.test');
    if (u.pathname.endsWith('/calendars/7') && o && o.method === 'PATCH') { const b = JSON.parse(o.body); if (b.role) role = b.role; return json({ id: 7, role }); }
    if (u.pathname.endsWith('/calendars') && (!o || !o.method || o.method === 'GET')) return json({ calendars: [{ id: 7, name: 'Sample', role }], folders: [], tags: [] });
    if (u.pathname.endsWith('/events')) { windows++; return json({ events: [] }); }
    return json({});
  };
  const savedCals = st.calendars;
  put({ calendars: [{ id: 7, name: 'Sample', role: 'mine' }], loadedRanges: [], windowStatus: null, visibleDays: null });
  await loadWindow(isoAt(new Date(2034, 2, 1)), isoAt(new Date(2034, 2, 8)));
  const before = windows;
  await updateCalendar(st.calendars[0], { role: 'context' });
  for (let i = 0; i < 5; i++) await new Promise((r) => setTimeout(r, 0));
  eq('calendar role: the events on screen are asked for again', [windows > before, st.calendars[0].role], [true, 'context']);
  const asked = windows;
  await updateCalendar(st.calendars[0], { reminderDefaults: [10] });
  for (let i = 0; i < 5; i++) await new Promise((r) => setTimeout(r, 0));
  eq("calendar role: other settings don't reload them", windows, asked);
  globalThis.fetch = realFetch;
  put({ calendars: savedCals, loadedRanges: [], windowStatus: null, visibleDays: null });
  if (!hadWindow) delete globalThis.window; else globalThis.window = savedWindow;
}

// --- The default calendar, and what Undo says (#187 review) ---
{
  const hadWindow = 'window' in globalThis;
  const savedWindow = globalThis.window;
  if (!hadWindow) globalThis.window = { addEventListener() {}, removeEventListener() {}, matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }), location: { href: 'http://app.test/' } };
  const realFetch = globalThis.fetch;
  const { defaultTargetCalendarId, deleteCalendar } = await import('../src/app/actions.js');
  const { undoEntries: undoOwn } = await import('../src/app/api.js');
  const { state: st, set: put } = await import('../src/app/store.js');
  const saved = { calendars: st.calendars, settings: st.settings, toasts: st.toasts };
  const cals = [
    { id: 3, name: 'Tides', kind: 'plugin', visible: true },
    { id: 9, name: 'Work', kind: 'local', visible: true },
    { id: 5, name: 'Home', kind: 'local', visible: false },
    { id: 2, name: 'Feed', kind: 'subscribed', visible: true },
  ];
  put({ calendars: cals, settings: { ...st.settings, defaultCalendarId: 9 } });
  eq('default calendar: the one chosen', defaultTargetCalendarId(), 9);
  put({ settings: { ...st.settings, defaultCalendarId: 3 } });
  eq("default calendar: missing or not one that takes events, the oldest local until the settings catch up, whatever the list's order", defaultTargetCalendarId(), 5);
  const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  globalThis.fetch = async (url, o) => {
    const u = new URL(String(url), 'http://app.test');
    if (u.pathname.endsWith('/calendars/5') && o && o.method === 'DELETE') return json({ ok: true, defaultCalendarId: 9 });
    if (u.pathname.endsWith('/undo')) return json({ error: { code: 'not_undoable', message: "This entry is log-only and cannot be undone" } }, 400);
    if (u.pathname.endsWith('/calendars')) return json({ calendars: cals.filter((c) => c.id !== 5), folders: [], tags: [] });
    return json({});
  };
  put({ settings: { ...st.settings, defaultCalendarId: 5 }, toasts: [] });
  await deleteCalendar(cals[2]);
  eq('default calendar: deleting it moves it, and the toast says where new events go', [st.settings.defaultCalendarId, st.toasts.some((t) => /New events now go to Work/.test(t.text))], [9, true]);
  put({ toasts: [] });
  await undoOwn([7]);
  eq("undo: a change that can't be undone says so, in the server's words", st.toasts.some((t) => t.text.startsWith('This entry is log-only')), true);
  // A write's answer says which journal entries it made; a toast's Undo
  // undoes just those, newest first, whatever came after.
  const { api: call, entriesOf, undoEntries } = await import('../src/app/api.js');
  const posted = [];
  globalThis.fetch = async (url, o) => {
    const u = new URL(String(url), 'http://app.test');
    if (u.pathname.endsWith('/events/5')) return new Response(JSON.stringify({ ok: true }), { status: 200, headers: { 'Content-Type': 'application/json', 'X-Undo-Entries': '41,42' } });
    const m = u.pathname.match(/\/activity\/(\d+)\/undo$/);
    if (m) { posted.push(Number(m[1])); return json({ ok: true }); }
    return json({ calendars: [], folders: [], tags: [], events: [] });
  };
  const answer = await call('/events/5', { method: 'PATCH', body: {} });
  eq("undo: a write's answer says which entries it made", entriesOf(answer), [41, 42]);
  eq('undo: an answer without any has none', entriesOf({ ok: true }), []);
  await undoEntries(entriesOf(answer));
  eq("undo: a toast's Undo undoes just its own entries, newest first", posted, [42, 41]);
  // An accept undone is open in Review again: pages' own lists reload
  // (undoSeq), and so do the badge, people and saved views.
  const seq = st.undoSeq || 0;
  const tripSeq = st.tripLinksSeq;
  const fetched = new Set();
  let counted = false;
  globalThis.fetch = async (url) => {
    const u = new URL(String(url), 'http://app.test');
    fetched.add(u.pathname.replace(/^.*\/api/, ''));
    if (u.pathname.endsWith('/review/count')) { counted = true; return json({ count: 1 }); }
    if (/\/activity\/\d+\/undo$/.test(u.pathname)) return json({ ok: true });
    return json({ calendars: [], folders: [], tags: [], events: [] });
  };
  await undoEntries([43]);
  eq('undo: own lists reload, an open trip\'s members, and Review\'s count', [st.undoSeq, st.tripLinksSeq, counted], [seq + 1, tripSeq + 1, true]);
  // Only the newest load's answer is shown: an older one finishing later
  // (the accept's reload, after the undo's) is dropped, failures too; a
  // further page adds only to the list its cursor came from.
  const { loadGuard, superseded, newestOnly } = await import('../src/app/lists.js');
  const latest = loadGuard();
  const outcome = (p) => p.then(() => 'shown', (e) => (superseded(e) ? 'dropped' : 'failed'));
  let release;
  const older = outcome(latest(new Promise((r) => { release = r; })));
  const newer = await latest(Promise.resolve('reopened'));
  release('empty');
  const oldFail = outcome(latest(Promise.reject(new Error('x'))));
  await latest(Promise.resolve('fresh'));
  const of = latest.current();
  const page = await latest(Promise.resolve('page 2'), of);
  eq('own lists: an older answer after a newer one is dropped, its failure too; a further page of the list is kept', [await older, newer, await oldFail, page],
    ['dropped', 'reopened', 'dropped', 'page 2']);
  // A refresh begun (an undo restored a newer item, so the page boundary
  // moves), then "Show older" from the old list's cursor: that page is
  // dropped rather than added after the new first page, skipping an item.
  let refreshed;
  const refresh = outcome(latest(new Promise((r) => { refreshed = r; })));
  const stalePage = outcome(latest(Promise.resolve('older from the old cursor'), of));
  refreshed('new first page');
  eq('own lists: a page from a cursor before a refresh is dropped; the refresh is kept', [await stalePage, await refresh], ['dropped', 'shown']);
  // The store's shared lists: an older answer after a newer one isn't
  // applied, and a caller awaiting the older load sees the newer list.
  const applied = [];
  const answers = [];
  const shared = newestOnly(() => new Promise((r) => answers.push(r)), (d) => applied.push(d));
  const first = shared();
  const second = shared();
  answers[1]('with Alex');
  answers[0]('empty');
  eq('shared lists: only the newest answer is applied; the older load settles with it', [await first, await second, applied], ['with Alex', 'with Alex', ['with Alex']]);
  eq('undo: people and saved views reload', ['/people', '/views'].map((p) => [...fetched].some((f) => f.endsWith(p))), [true, true]);
  // The badge and the plugin list the same way: a count from before an
  // undo, answered after the undo's own reload, doesn't put the old number
  // back; and a failed load never rejects (boot awaits them together).
  const { loadReviewCount, loadPlugins } = await import('../src/app/api.js');
  const pending = { count: [], plugins: [] };
  globalThis.fetch = (url) => new Promise((resolve, reject) => {
    const u = new URL(String(url), 'http://app.test');
    const slot = u.pathname.endsWith('/review/count') ? 'count' : 'plugins';
    pending[slot].push({ ok: (body) => resolve(json(body)), fail: () => reject(new TypeError('offline')) });
  });
  const counts = [loadReviewCount(), loadReviewCount()];
  const lists = [loadPlugins(), loadPlugins()];
  pending.count[1].ok({ count: 0 });
  pending.plugins[1].ok({ plugins: [{ id: 'sample' }] });
  pending.count[0].ok({ count: 7 });
  pending.plugins[0].ok({ plugins: [] });
  await Promise.all([...counts, ...lists]);
  eq('badge and plugins: an older answer after a newer one is not applied', [st.reviewCount, st.plugins.map((p) => p.id)], [0, ['sample']]);
  const failing = [loadReviewCount(), loadPlugins()];
  pending.count[2].fail();
  pending.plugins[2].fail();
  eq('badge and plugins: a failed load settles quietly and keeps the last answer', [await Promise.all(failing).then(() => 'settled', () => 'rejected'), st.reviewCount], ['settled', 0]);
  // Review's paged list: a further page adds by key, and a failed fresh
  // load leaves no "Show older" to a list that isn't there, while a failed
  // page keeps where it was.
  const { pageItems, pageFailed } = await import('../src/app/lists.js');
  const shown = [{ key: 'a' }, { key: 'b' }];
  eq('review pages: a further page adds by key; a fresh answer replaces', [pageItems(shown, { before: 1 }, [{ key: 'b' }, { key: 'c' }]).map((i) => i.key), pageItems(shown, null, [{ key: 'z' }]).map((i) => i.key)], [['a', 'b', 'c'], ['z']]);
  eq('review pages: a failed fresh load clears the cursor; a failed page keeps it', [pageFailed(null, { before: 1, of: 2 }), pageFailed({ before: 1, of: 2 }, { before: 1, of: 2 })], [null, { before: 1, of: 2 }]);
  globalThis.fetch = realFetch;
  put(saved);
  if (!hadWindow) delete globalThis.window; else globalThis.window = savedWindow;
}

// --- Sidebar edits: folder ids kept apart, and older answers don't win (#187 review) ---
{
  const hadWindow = 'window' in globalThis;
  const savedWindow = globalThis.window;
  if (!hadWindow) globalThis.window = { addEventListener() {}, removeEventListener() {}, matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }), location: { href: 'http://app.test/' } };
  const realFetch = globalThis.fetch;
  const { fileCalendar, placeCalendarInList, setFolderSort, updateCalendar, toggleCalendarVisible } = await import('../src/app/actions.js');
  const { loadCalendars, undoEntries } = await import('../src/app/api.js');
  const { isOwed } = await import('../src/app/reloads.js');
  const calendarsOwed = () => isOwed('calendars');
  const { state: st, set: put } = await import('../src/app/store.js');
  const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  const tick = async (n = 6) => { for (let i = 0; i < n; i++) await new Promise((r) => setTimeout(r, 0)); };
  const saved = { calendars: st.calendars, folders: st.folders };
  // A pretend server: the list's order, and each folder's.
  let server;
  const reset = () => {
    holds = [];
    server = { list: [1, 2, 3], names: {}, hidden: {}, folders: { 1: { sort: 'manual', ids: [1] }, 2: { sort: 'manual', ids: [] } } };
    put({
      calendars: server.list.map((id) => ({ id, name: 'Sample ' + id, visible: true, folderIds: id === 1 ? [1] : [] })),
      folders: [{ id: 1, name: 'One', sort: 'manual', calendarIds: [1] }, { id: 2, name: 'Two', sort: 'manual', calendarIds: [] }],
    });
  };
  const listed = () => json({
    calendars: server.list.map((id) => ({ id, name: server.names[id] || 'Sample ' + id, visible: server.hidden[id] ? false : true, folderIds: Object.keys(server.folders).map(Number).filter((f) => server.folders[f].ids.includes(id)) })),
    folders: Object.keys(server.folders).map(Number).map((f) => ({ id: f, name: 'F' + f, sort: server.folders[f].sort, calendarIds: [...server.folders[f].ids] })),
    tags: [],
  });
  let holds = []; // [{match, promise, fail}], each used once
  let failReads = 0; // the next reloads of the list that fail
  let reads = 0; // reloads of the list answered
  const holdNext = (match, fail = false) => { let release; holds.push({ match, fail, promise: new Promise((r) => { release = r; }) }); return () => release(); };
  globalThis.fetch = async (url, o) => {
    const u = new URL(String(url), 'http://app.test');
    const method = (o && o.method) || 'GET';
    const body = o && o.body ? JSON.parse(o.body) : {};
    const hi = holds.findIndex((x) => x.match(u, method));
    if (hi >= 0) {
      // Answered as things stood when asked, however late it lands.
      const [h] = holds.splice(hi, 1);
      const asked = u.pathname.endsWith('/calendars') && method === 'GET' ? listed() : null;
      await h.promise;
      if (h.fail) return json({ error: { code: 'server', message: 'Down for a moment' } }, 500);
      if (asked) return asked;
    }
    let m;
    if ((m = u.pathname.match(/\/calendars\/(\d+)\/position$/))) { const id = Number(m[1]); server.list = server.list.filter((x) => x !== id); server.list.splice(body.at, 0, id); return json({ calendarIds: server.list }); }
    if ((m = u.pathname.match(/\/folders\/(\d+)\/calendars\/(\d+)$/))) { const f = server.folders[m[1]]; const id = Number(m[2]); f.ids = f.ids.filter((x) => x !== id); f.ids.splice(body.at, 0, id); return json({ calendarIds: f.ids }); }
    if ((m = u.pathname.match(/\/folders\/(\d+)$/)) && method === 'PATCH') { server.folders[m[1]].sort = body.sort; return json({ id: Number(m[1]), sort: body.sort }); }
    if ((m = u.pathname.match(/\/calendars\/(\d+)$/)) && method === 'PATCH') {
      if (body.name) server.names[m[1]] = body.name;
      if ('visible' in body) server.hidden[m[1]] = !body.visible;
      return json({ id: Number(m[1]), name: server.names[m[1]] || 'Sample ' + m[1], visible: !server.hidden[m[1]] });
    }
    if (u.pathname.endsWith('/calendars') && method === 'PATCH') {
      for (const c of body.calendars) server.hidden[c.id] = !c.visible;
      return json({ calendars: body.calendars.map((c) => ({ id: c.id, name: server.names[c.id] || 'Sample ' + c.id, visible: !server.hidden[c.id] })) });
    }
    if (u.pathname.endsWith('/calendars') && method === 'GET' && failReads > 0) { failReads--; return json({ error: { code: 'server', message: 'Down for a moment' } }, 500); }
    if (u.pathname.endsWith('/calendars') && method === 'GET') reads++;
    if (u.pathname.endsWith('/undo') && method === 'POST') return json({ ok: true, undone: { entity: 'calendar', op: 'update' } });
    if (u.pathname.endsWith('/calendars') && method === 'GET') return listed();
    return json({});
  };

  // Option-dragging calendar 1 into folder 2 keeps it in folder 1 at once:
  // filtering its folder ids by its own id dropped folder 1.
  reset();
  const releaseFile = holdNext((u, m) => m === 'POST');
  const filing = fileCalendar(st.calendars[0], st.folders[1], 0, null);
  await tick();
  eq('sidebar: a calendar added to a folder keeps the folder it was in, before it is saved', st.calendars[0].folderIds, [1, 2]);
  releaseFile();
  await filing;
  await tick(10);

  const order = () => st.calendars.map((c) => c.id);
  // Two drags queued behind a slow save, and a reload asked for between
  // them answered last: it doesn't put its older order back (#187 review).
  reset();
  const releaseFirst = holdNext((u, m) => m === 'POST');
  const first = placeCalendarInList(st.calendars[2], 0); // 3 to the top
  await tick();
  const second = placeCalendarInList(st.calendars.find((c) => c.id === 2), 0); // then 2
  await tick();
  eq('sidebar: two drags show at once, in the order made', order(), [2, 3, 1]);
  // The next reload asked for (the change check's, or one after the first
  // save) is held, as things stood, until everything else is done.
  const releaseStray = holdNext((u, m) => m === 'GET' && u.pathname.endsWith('/calendars'));
  releaseFirst();
  await tick(3);
  const stray = loadCalendars();
  await second;
  await tick(10);
  releaseStray();
  await first;
  await stray;
  await tick(10);
  eq('sidebar: a reload asked for between saves and answered last leaves the saved order', [order(), server.list], [[2, 3, 1], [2, 3, 1]]);

  // A drag that fails, then a sort change: once both are done the screen
  // is as saved, the drag undone.
  reset();
  const failDrag = holdNext((u, m) => m === 'POST', true);
  const drag = placeCalendarInList(st.calendars[2], 0);
  await tick();
  const sorted = setFolderSort(st.folders[0], 'az');
  failDrag();
  await drag;
  await sorted;
  await tick(10);
  eq('sidebar: a failed drag is put right once the saves after it are done', [order(), st.folders[0].sort], [[1, 2, 3], 'az']);

  // A failed A to Z in one folder and a saved one in another: each shows
  // as saved.
  reset();
  const failOne = holdNext((u, m) => m === 'PATCH' && u.pathname.endsWith('/folders/1'), true);
  const one = setFolderSort(st.folders[0], 'az');
  await tick();
  const two = setFolderSort(st.folders[1], 'az');
  failOne();
  await one;
  await two;
  await tick(10);
  eq('sidebar: a failed sort in one folder and a saved one in another each show as saved', st.folders.map((f) => f.sort), ['manual', 'az']);

  // A rename from a calendar's settings, saved after a reload was asked
  // for: the reload, answered later, doesn't put the old name back.
  reset();
  const releaseOld = holdNext((u, m) => m === 'GET' && u.pathname.endsWith('/calendars'));
  const old = loadCalendars();
  await tick();
  await updateCalendar(st.calendars[0], { name: 'Renamed sample' });
  await tick(10);
  releaseOld();
  await old;
  await tick(10);
  eq("sidebar: a reload asked for before a rename doesn't put the old name back", st.calendars[0].name, 'Renamed sample');

  // A checkbox clicked while an older reload is still out: the reload,
  // answered after, doesn't tick it back (#187 review).
  reset();
  const releaseOldRead = holdNext((u, m) => m === 'GET' && u.pathname.endsWith('/calendars'));
  const oldRead = loadCalendars();
  await tick();
  await toggleCalendarVisible(st.calendars[1]);
  await tick(10);
  releaseOldRead();
  await oldRead;
  await tick(10);
  eq("sidebar: a reload asked for before a checkbox click doesn't undo it", [st.calendars[1].visible, server.hidden[2]], [false, true]);

  // A failed A to Z whose reload fails too (an outage): the folder shows
  // its own order again at once, and the list is owed a reload until one
  // lands (the change check asks).
  reset();
  await tick(10);
  const failSortSave = holdNext((u, m) => m === 'PATCH', true);
  failReads = 1;
  const sorting = setFolderSort(st.folders[0], 'az');
  await tick();
  eq('sidebar: A to Z shows at once', st.folders[0].sort, 'az');
  failSortSave();
  await sorting;
  await tick(10);
  eq('sidebar: a failed A to Z goes back, with its reload failing too, and a reload is owed', [st.folders[0].sort, calendarsOwed()], ['manual', true]);
  await loadCalendars();
  eq('sidebar: a reload that lands settles what was owed', calendarsOwed(), false);

  // A calendar's settings save: shown at once, and its answer, landing
  // after a newer change, isn't laid over it (#187 review).
  reset();
  await tick(10);
  const releaseRename = holdNext((u, m) => m === 'PATCH' && u.pathname.endsWith('/calendars/2'));
  const renaming = updateCalendar(st.calendars[1], { name: 'Renamed at once' });
  await tick();
  eq('sidebar: a settings change shows at once', st.calendars[1].name, 'Renamed at once');
  const hiding = toggleCalendarVisible(st.calendars[1]);
  await tick();
  releaseRename();
  await renaming;
  await hiding;
  await tick(10);
  eq("sidebar: a settings save's answer doesn't undo a newer checkbox click", [st.calendars[1].name, st.calendars[1].visible, server.hidden[2]], ['Renamed at once', false, true]);
  reset();
  await tick(10);
  const failRename = holdNext((u, m) => m === 'PATCH', true);
  const failing = updateCalendar(st.calendars[0], { name: 'Not saved' });
  await tick();
  failRename();
  await failing;
  await tick(10);
  eq('sidebar: a settings change that fails goes back', st.calendars[0].name, 'Sample 1');

  // Undo, from a toast or the log, brings the list up to date: a drag it
  // undid showed where it had gone.
  reset();
  await tick(10);
  const readsBefore = reads;
  await undoEntries([3]);
  await tick(10);
  assert('sidebar: an undo reloads the list', reads > readsBefore);

  // Of two reloads, the older answer, landing last, doesn't replace the newer.
  reset();
  await tick(10);
  const releaseFirstRead = holdNext((u, m) => m === 'GET' && u.pathname.endsWith('/calendars'));
  const firstRead = loadCalendars();
  await tick();
  server.names[1] = 'Newer name';
  await loadCalendars();
  server.names[1] = 'Even newer, unseen';
  releaseFirstRead();
  await firstRead;
  eq('sidebar: an older reload answered last leaves the newer list', st.calendars[0].name, 'Newer name');

  globalThis.fetch = realFetch;
  put(saved);
  if (!hadWindow) delete globalThis.window; else globalThis.window = savedWindow;
}

// --- Drawn: a folder's Only in the sidebar (#187), Apply to after a calendar change (#186) ---
{
  // The real Sidebar and EditorDrawer, drawn by Preact into a stand-in DOM
  // (fakedom.mjs), clicked and changed as a person would, and read back.
  const { fakeDom } = await import('./fakedom.mjs');
  const dom = fakeDom();
  dom.install();
  const { options } = await import('../vendor/preact.module.js');
  const savedRaf = options.requestAnimationFrame, savedDebounce = options.debounceRendering;
  options.requestAnimationFrame = (cb) => setImmediate(cb);
  options.debounceRendering = (cb) => Promise.resolve().then(cb);
  const settle = async () => { for (let i = 0; i < 12; i++) await new Promise((r) => setImmediate(r)); };
  const { render, html } = await import('../vendor/index.js');
  const { state: st, set: put } = await import('../src/app/store.js');
  const saved = { calendars: st.calendars, folders: st.folders, editor: st.editor, collapsedFolders: st.collapsedFolders };
  const realFetch = globalThis.fetch;
  const json = (body) => new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });

  // A pretend server that keeps each calendar's checkbox.
  const sample = [
    { id: 1, name: 'Sample lessons', folderIds: [10], visible: true },
    { id: 2, name: 'Sample recitals', folderIds: [10], visible: false },
    { id: 3, name: 'Sample league', folderIds: [11], visible: true },
    { id: 4, name: 'Sample feed', folderIds: [], visible: false },
    { id: 5, name: 'Sample errands', folderIds: [], visible: true },
  ];
  const server = new Map(sample.map((c) => [c.id, c.visible]));
  const folderList = [{ id: 10, name: 'Sample music', sort: 'manual', calendarIds: [1, 2] }, { id: 11, name: 'Sample sport', sort: 'manual', calendarIds: [3] }];
  const listed = () => sample.map((c) => ({ ...c, visible: server.get(c.id) }));
  globalThis.fetch = async (url, o = {}) => {
    const u = new URL(String(url), 'http://app.test');
    const m = u.pathname.match(/\/calendars\/(\d+)$/);
    if (m && o.method === 'PATCH') {
      const body = JSON.parse(o.body);
      if ('visible' in body) server.set(Number(m[1]), body.visible);
      return json({ id: Number(m[1]), visible: server.get(Number(m[1])) });
    }
    if (u.pathname.endsWith('/calendars') && o.method === 'PATCH') {
      // Only and a folder's All or None: one request for all of them (#190).
      const body = JSON.parse(o.body);
      for (const c of body.calendars) server.set(c.id, c.visible);
      return json({ calendars: listed().filter((c) => body.calendars.some((b) => b.id === c.id)) });
    }
    if (u.pathname.endsWith('/calendars')) return json({ calendars: listed(), folders: folderList, tags: [] });
    return json({ events: [], people: [], plugins: [], count: 0 });
  };
  const on = () => st.calendars.filter((c) => c.visible).map((c) => c.id);
  const onServer = () => [...server].filter(([, v]) => v).map(([id]) => id);

  put({ calendars: listed(), folders: folderList, collapsedFolders: {} });
  const { Sidebar } = await import('../src/app/Sidebar.js');
  const bar = dom.doc.createElement('div');
  dom.doc.body.appendChild(bar);
  render(html`<${Sidebar} open=${true} collapsed=${false} onClose=${() => {}} />`, bar);
  await settle();
  const only = (name) => bar.querySelectorAll('.bc-cal-solo').find((b) => b.getAttribute('aria-label') === 'Show only ' + name || b.getAttribute('aria-label') === 'Stop showing only ' + name);
  const banner = () => bar.querySelectorAll('.bc-solo-banner').map((b) => b.querySelector('strong').textContent);
  const showAll = () => bar.querySelector('.bc-solo-banner').querySelector('.bc-link-btn');
  const before = on();
  eq('folder Only: the set before, for the checks below (one in the folder off, one outside it off)', before, [1, 3, 5]);

  only('Sample music').click();
  await settle();
  eq("folder Only: exactly the folder's calendars are on, here and on the server", [on(), onServer()], [[1, 2], [1, 2]]);
  eq('folder Only: its button is pressed and the banner names the folder', [only('Sample music').getAttribute('aria-pressed'), banner()], ['true', ['Sample music']]);
  only('Sample music').click();
  await settle();
  eq('folder Only, clicked again: exactly the set from before, the one in the folder that was off off again', [on(), onServer(), banner()], [before, before, []]);

  only('Sample music').click();
  await settle();
  showAll().click();
  await settle();
  eq("folder Only, the banner's Show all: exactly the set from before", [on(), onServer(), banner()], [before, before, []]);

  only('Sample music').click();
  await settle();
  only('Sample sport').click();
  await settle();
  eq("folder Only on a second folder while in the first: just the second folder's", [on(), banner()], [[3], ['Sample sport']]);
  showAll().click();
  await settle();
  eq("folder Only, then another: Show all restores the set from before either, not the first folder's", [on(), onServer()], [before, before]);
  only('Sample music').click();
  await settle();
  only('Sample sport').click();
  await settle();
  only('Sample sport').click();
  await settle();
  eq("folder Only, then another, clicked again: the set from before either", [on(), onServer()], [before, before]);
  render(null, bar);
  await settle();

  // A Google calendar's row: its provider shows once, as the G in its disc,
  // and the disc carries what the row says of the account; nothing follows
  // the name.
  put({ calendars: [
    { id: 31, name: 'Sample shared', kind: 'subscribed', provider: 'google', googleAccountEmail: 'sam@example.test', editable: true, visible: true, folderIds: [] },
    { id: 32, name: 'Sample chores', kind: 'local', editable: true, visible: true, folderIds: [] },
  ], folders: [] });
  render(html`<${Sidebar} open=${true} collapsed=${false} onClose=${() => {}} />`, bar);
  await settle();
  const rowOf = (id) => bar.querySelector('[data-cal-id="' + id + '"]').querySelector('.bc-cal-label');
  const g = rowOf(31), gDisc = g.querySelector('.bc-cal-dot');
  eq('a Google row: one provider mark, the G in its disc, and no second G after the name', [
    g.querySelectorAll('svg').length, gDisc.querySelectorAll('svg').length, gDisc.classes.includes('is-marked'),
    g.querySelectorAll('.bc-cal-src').length, g.childNodes.filter((n) => n.nodeType === 1).map((n) => n.localName + (n.classes[0] ? '.' + n.classes[0] : '')),
  ], [1, 1, true, 0, ['input', 'span.bc-cal-dot', 'span.bc-cal-name']]);
  eq("a Google row: the disc carries the account and where edits go, as its name and tooltip", [gDisc.getAttribute('role'), gDisc.getAttribute('aria-label'), gDisc.getAttribute('title')],
    ['img', 'From Google Calendar (sam@example.test); edits here go to Google', 'From Google Calendar (sam@example.test); edits here go to Google']);
  const plainDisc = rowOf(32).querySelector('.bc-cal-dot');
  eq('a plain row: an empty disc with no name of its own', [plainDisc.querySelectorAll('svg').length, plainDisc.getAttribute('role'), plainDisc.getAttribute('aria-label')], [0, null, null]);
  render(null, bar);
  await settle();

  // The editor of a repeating event, and of one that doesn't repeat.
  put({ calendars: [
    { id: 21, name: 'Sample home', editable: true, visible: true, kind: 'local' },
    { id: 22, name: 'Sample work', editable: true, visible: true, kind: 'local' },
    { id: 23, name: 'Sample club', editable: true, visible: true, kind: 'local' },
  ], folders: [] });
  const { EditorDrawer } = await import('../src/app/EditorDrawer.js');
  const ed = dom.doc.createElement('div');
  dom.doc.body.appendChild(ed);
  const repeating = { instanceId: '61@1', eventId: 61, calendarId: 21, title: 'Sample class', recurring: true, rrule: 'FREQ=WEEKLY;BYDAY=TU', start: '2027-03-09T19:00:00-08:00', end: '2027-03-09T20:00:00-08:00', allDay: false, full: true };
  const open = async (occ) => {
    render(null, ed);
    put({ editor: null });
    await settle();
    put({ editor: { mode: 'edit', occ } });
    render(html`<${EditorDrawer} />`, ed);
    await settle();
  };
  const applyTo = () => ed.querySelector('select[aria-label="Apply to"]');
  const scope = () => applyTo().value;
  const lit = () => applyTo().parentNode.classes.includes('bc-nl-applied');
  const pick = async (select, value) => { select.value = String(value); select.dispatch('change'); await settle(); };
  const calendar = (v) => pick(ed.querySelector('select.bc-calselect'), v);

  await open(repeating);
  eq('Apply to: a repeating event opens on This event only', scope(), 'this');
  await calendar(22);
  eq('Apply to: another calendar for a repeating event switches it to All events in series, lit', [scope(), lit()], ['all', true]);
  await calendar(21);
  eq('Apply to: its own calendar again puts This event only back', scope(), 'this');

  await open(repeating);
  await pick(applyTo(), 'all');
  await calendar(22);
  await calendar(21);
  eq('Apply to: All events picked by hand stays through a calendar change and back', scope(), 'all');
  const following = applyTo().querySelectorAll('option').some((o) => o.getAttribute('value') === 'following' && !o.hasAttribute('disabled'));
  if (following) {
    await open(repeating);
    await pick(applyTo(), 'following');
    await calendar(22);
    await calendar(21);
    eq('Apply to: This and following picked by hand stays through a calendar change and back', scope(), 'following');
  }
  await open(repeating);
  await calendar(22);
  await pick(applyTo(), 'this');
  await calendar(21);
  eq('Apply to: This event only picked by hand after the switch stays when its own calendar is picked again', scope(), 'this');

  await open({ ...repeating, instanceId: '62@1', eventId: 62, recurring: false, rrule: null });
  await calendar(22);
  eq("Apply to: an event that doesn't repeat has none, and its calendar changes alone", [applyTo(), ed.querySelector('select.bc-calselect').value], [null, '22']);

  render(null, ed);
  put({ editor: null });
  await settle();
  globalThis.fetch = realFetch;
  put(saved);
  if (savedRaf === undefined) delete options.requestAnimationFrame; else options.requestAnimationFrame = savedRaf;
  if (savedDebounce === undefined) delete options.debounceRendering; else options.debounceRendering = savedDebounce;
  dom.restore();
}

// --- Resizing in a repeated hour (#185) ---
{
  const { wallInstant, dayKeyOf: keyOf } = await import('../src/lib/dates.js');
  eq('wall instant: an ordinary time is that time', wallInstant('2026-03-10', 90), new Date(2026, 2, 10, 1, 30).getTime());
  // The time this zone's clock change repeats this year, if it has one, and
  // how long it is (an hour, half of one, two), measured, not assumed.
  let back = null;
  let shift = 0;
  for (let t = Date.UTC(2026, 0, 1); t < Date.UTC(2027, 0, 1) && back === null; t += 15 * 60000) {
    const d = new Date(t + 15 * 60000).getTimezoneOffset() - new Date(t).getTimezoneOffset();
    if (d > 0) { back = t + 15 * 60000; shift = d; }
  }
  if (back !== null) {
    const M = 60000;
    const d = new Date(back);
    const key = keyOf(d);
    const m0 = d.getHours() * 60 + d.getMinutes(); // the repeated time's first wall minute
    const half = Math.floor(shift / 2);
    // Starting as the second pass begins, an end dragged into that pass
    // lands in it, after the start, at the time asked for.
    eq('wall instant: a shortened end stays after a start in the second pass', wallInstant(key, m0 + half, { after: back + M }), back + half * M);
    // Ending half way through the first pass, a start dragged to its
    // beginning lands there, before the end.
    eq('wall instant: a moved start stays before an end in the first pass', wallInstant(key, m0, { before: back - (shift - half) * M - M }), back - shift * M);
    eq('wall instant: with nothing to stay beside, the first pass', wallInstant(key, m0 + half), back - (shift - half) * M);
  }
}

// --- Times when dragging (#190) ---
// One wall-clock reading for a move, a resize and a draw (wallRangeInstants,
// the typed range's rule), in a northern zone, a southern one and one on a
// half-hour offset. The suite's own zone is set per zone and put back.
{
  const { wallMinutes, typedInstants, toISOWithOffset: iso } = await import('../src/lib/dates.js');
  const { movedDates, resizedDates, drawnDates } = await import('../src/ui/monthmath.js');
  const savedTz = process.env.TZ;
  // Each zone: the day its clocks skip an hour and the first hour skipped,
  // the day they repeat one and the first hour repeated, an ordinary day,
  // and its offsets (minutes east) in daylight time, standard time and on
  // that ordinary day.
  const zones = [
    ['America/Los_Angeles', { gap: ['2027-03-14', 2], fold: ['2027-11-07', 1], plain: '2027-06-16', dst: -420, std: -480, plainOff: -420 }],
    ['Australia/Sydney', { gap: ['2027-10-03', 2], fold: ['2027-04-04', 2], plain: '2027-06-16', dst: 660, std: 600, plainOff: 600 }],
    ['Australia/Adelaide', { gap: ['2027-10-03', 2], fold: ['2027-04-04', 2], plain: '2027-06-16', dst: 630, std: 570, plainOff: 570 }],
  ];
  const pair = (r) => (r ? [r.newStart || r.start, r.newEnd || r.end] : null);
  for (const [tz, z] of zones) {
    process.env.TZ = tz;
    // An instant from a day's clock and an offset, as the server stores it.
    const at = (day, h, m, off) => { const [y, mo, d] = day.split('-').map(Number); return iso(new Date(Date.UTC(y, mo - 1, d, h, m) - off * 60000)); };
    const abs = (day, h, m) => wallMinutes(day, h * 60 + m);
    const ev = (day, h1, m1, h2, m2, off) => ({ allDay: false, start: at(day, h1, m1, off), end: at(day, h2, m2, off) });
    const { plain, plainOff: po, dst, std } = z;
    const [gapDay, g] = z.gap;
    const [foldDay, f] = z.fold;
    const ordinary = ev(plain, 9, 0, 10, 0, po);
    eq(`dragging (${tz}): an ordinary day: a move keeps the length, a resized end lands at its clock, a draw is its clocks`,
      [movedDates(ordinary, abs(plain, 11, 15)), resizedDates(ordinary, 'end', abs(plain, 11, 0)), resizedDates(ordinary, 'start', abs(plain, 8, 30)), drawnDates(plain, 14 * 60, 15 * 60 + 30)].map(pair),
      [[at(plain, 11, 15, po), at(plain, 12, 15, po)], [at(plain, 9, 0, po), at(plain, 11, 0, po)], [at(plain, 8, 30, po), at(plain, 10, 0, po)], [at(plain, 14, 0, po), at(plain, 15, 30, po)]]);
    // The repeated hour: an event in either pass stays in it, and its
    // dragged end lands beside the held one.
    const first = ev(foldDay, f, 0, f, 30, dst);
    const second = ev(foldDay, f, 0, f, 30, std);
    eq(`dragging (${tz}): a resized end lands in the pass its start is in`,
      [resizedDates(first, 'end', abs(foldDay, f, 45)), resizedDates(second, 'end', abs(foldDay, f, 45)), resizedDates(first, 'end', abs(foldDay, f + 1, 15))].map(pair),
      [[first.start, at(foldDay, f, 45, dst)], [second.start, at(foldDay, f, 45, std)], [first.start, at(foldDay, f + 1, 15, std)]]);
    const firstLate = ev(foldDay, f, 15, f, 45, dst);
    const secondLate = ev(foldDay, f, 15, f, 45, std);
    eq(`dragging (${tz}): a resized start lands in the pass its end is in`,
      [resizedDates(firstLate, 'start', abs(foldDay, f, 0)), resizedDates(secondLate, 'start', abs(foldDay, f, 0))].map(pair),
      [[at(foldDay, f, 0, dst), firstLate.end], [at(foldDay, f, 0, std), secondLate.end]]);
    eq(`dragging (${tz}): a nudge keeps the pass, down or up`,
      [movedDates(second, abs(foldDay, f, 15)), movedDates(ev(foldDay, f, 30, f + 1, 0, std), abs(foldDay, f, 15)), movedDates(first, abs(foldDay, f, 15))].map(pair),
      [[at(foldDay, f, 15, std), at(foldDay, f, 45, std)], [at(foldDay, f, 15, std), at(foldDay, f, 45, std)], [at(foldDay, f, 15, dst), at(foldDay, f, 45, dst)]]);
    // Brought from another day the event keeps its clock length (an hour on
    // the grid, two of elapsed time across the fold), the first pass going
    // forward and the second going back.
    const before = ev(addDaysKey(foldDay, -7), 9, 0, 10, 0, dst);
    const after = ev(addDaysKey(foldDay, 7), 9, 0, 10, 0, std);
    eq(`dragging (${tz}): moved onto the fold day from before, the first pass; from after, the second; an hour on the clock either way`,
      [movedDates(before, abs(foldDay, f, 30)), movedDates(after, abs(foldDay, f, 30))].map(pair),
      [[at(foldDay, f, 30, dst), at(foldDay, f + 1, 30, std)], [at(foldDay, f, 30, std), at(foldDay, f + 1, 30, std)]]);
    eq(`dragging (${tz}): a draw into the repeated hour is the first pass, and across it the clocks it covers`,
      [drawnDates(foldDay, f * 60, f * 60 + 30), drawnDates(foldDay, f * 60 + 30, (f + 1) * 60 + 30)].map(pair),
      [[at(foldDay, f, 0, dst), at(foldDay, f, 30, dst)], [at(foldDay, f, 30, dst), at(foldDay, f + 1, 30, std)]]);
    // The skipped hour: a clock there is the clock plus the gap, and an end
    // moves by the push its start got, as typed (#222).
    const hh = (h) => String(h).padStart(2, '0');
    eq(`dragging (${tz}): a draw into the skipped hour is pushed as a typed range is; one across it keeps its clocks`,
      [drawnDates(gapDay, g * 60 + 30, (g + 1) * 60 + 30), drawnDates(gapDay, (g - 1) * 60 + 30, (g + 2) * 60)].map(pair),
      [[at(gapDay, g + 1, 30, dst), at(gapDay, g + 2, 30, dst)], [at(gapDay, g - 1, 30, std), at(gapDay, g + 2, 0, dst)]]);
    eq(`dragging (${tz}): the typed reading is the drawn one`,
      [typedInstants(gapDay + 'T' + hh(g) + ':30', gapDay + 'T' + hh(g + 1) + ':30', null).map(iso), typedInstants(foldDay + 'T' + hh(f) + ':30', foldDay + 'T' + hh(f + 1) + ':30', null).map(iso)],
      [pair(drawnDates(gapDay, g * 60 + 30, (g + 1) * 60 + 30)), pair(drawnDates(foldDay, f * 60 + 30, (f + 1) * 60 + 30))]);
    eq(`dragging (${tz}): moved into the skipped hour the whole event is pushed; moved to just before it the end alone is`,
      [movedDates(ordinary, abs(gapDay, g, 30)), movedDates(ordinary, abs(gapDay, g - 1, 30))].map(pair),
      [[at(gapDay, g + 1, 30, dst), at(gapDay, g + 2, 30, dst)], [at(gapDay, g - 1, 30, std), at(gapDay, g + 1, 30, dst)]]);
    const beforeGap = ev(gapDay, g - 1, 0, g - 1, 30, std);
    const afterGap = ev(gapDay, g + 1, 15, g + 1, 30, dst);
    const laterGap = ev(gapDay, g + 1, 45, g + 2, 30, dst);
    eq(`dragging (${tz}): an end dragged into the skipped hour is pushed; a start pushed onto its held end is refused, one pushed short of it lands`,
      [resizedDates(beforeGap, 'end', abs(gapDay, g, 30)), resizedDates(afterGap, 'start', abs(gapDay, g, 30)), resizedDates(laterGap, 'start', abs(gapDay, g, 30))].map(pair),
      [[beforeGap.start, at(gapDay, g + 1, 30, dst)], null, [at(gapDay, g + 1, 30, dst), laterGap.end]]);
  }
  if (savedTz === undefined) delete process.env.TZ; else process.env.TZ = savedTz;
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
  eq('repeat: monthly on a day', said('FREQ=MONTHLY;BYMONTHDAY=15'), 'Monthly on the 15th');
  eq('repeat: monthly takes the start day', said('FREQ=MONTHLY', { start: tue }), 'Monthly on the 4th');
  eq('repeat: first Monday', said('FREQ=MONTHLY;BYDAY=1MO'), 'Monthly on the first Monday');
  eq('repeat: last Friday', said('FREQ=MONTHLY;BYDAY=-1FR'), 'Monthly on the last Friday');
  eq('repeat: last day', said('FREQ=MONTHLY;BYMONTHDAY=-1'), 'Monthly on the last day');
  eq('repeat: last weekday', said('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1'), 'Monthly on the last weekday');
  eq('repeat: first and third Monday', said('FREQ=MONTHLY;BYDAY=1MO,3MO'), 'Monthly on the first and third Monday');
  eq('repeat: nth days that differ', said('FREQ=MONTHLY;BYDAY=1MO,-1FR'), 'Monthly on the first Monday and last Friday');
  eq('repeat: two days of the month', said('FREQ=MONTHLY;BYMONTHDAY=1,15'), 'Monthly on the 1st and 15th');
  eq('repeat: first and last weekday', said('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=1,-1'), 'Monthly on the first and last weekday');
  eq('repeat: a position past the word list, as the plan says it', said('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=20'), 'Monthly on the 20th weekday');
  eq('repeat: a position from the end', said('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-3'), 'Monthly on the third-last weekday');
  eq('repeat: a position from the end past the word list', said('FREQ=MONTHLY;BYDAY=SA,SU;BYSETPOS=-8'), 'Monthly on the 8th-last weekend day');
  eq('repeat: a zero position is no rule', said('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=0'), null);
  eq('repeat: a last day in a list cannot be said', said('FREQ=MONTHLY;BYMONTHDAY=1,-1'), null);
  eq('repeat: quarterly', said('FREQ=MONTHLY;INTERVAL=3'), 'Every 3 months');
  assert('repeat: yearly takes the start day', /^Yearly on /.test(said('FREQ=YEARLY', { start: thu })));
  eq('repeat: count', said('FREQ=WEEKLY;BYDAY=SU;COUNT=10'), 'Weekly on Sunday, 10 times');
  assert('repeat: until', /^Weekly on Tuesday, until /.test(said('FREQ=WEEKLY;BYDAY=TU;UNTIL=20261215')));
  eq('repeat: ends left out on request', said('FREQ=WEEKLY;BYDAY=TU;COUNT=3', { ends: false }), 'Weekly on Tuesday');
  eq('repeat: a rule it cannot say', said('FREQ=YEARLY;BYWEEKNO=1;BYDAY=MO'), null);
  // The series sentences (docs/design/series-model-examples.md), 1.5.0.
  eq('repeat: the 30th, or the last day', said('FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1'), 'Monthly on the 30th, or the last day in shorter months');
  eq('repeat: the clamped 29th', said('FREQ=MONTHLY;BYMONTHDAY=28,29;BYSETPOS=-1'), 'Monthly on the 29th, or the last day in shorter months');
  eq('repeat: the clamped form, short', said('FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1', { short: true }), 'Monthly on the 30th, or the last day');
  eq('repeat: a clamp it does not know', said('FREQ=MONTHLY;BYMONTHDAY=27,30;BYSETPOS=-1'), null);
  eq('repeat: every 31st skips months', said('FREQ=MONTHLY;BYMONTHDAY=31'), 'Monthly on the 31st, skipping months without one');
  eq('repeat: a bare rule from the 30th', said('FREQ=MONTHLY', { start: new Date(2027, 0, 30) }), 'Monthly on the 30th, skipping months without one');
  eq('repeat: the second-last day', said('FREQ=MONTHLY;BYMONTHDAY=-2'), 'Monthly on the second-last day');
  eq('repeat: the seventh-last day', said('FREQ=MONTHLY;BYMONTHDAY=-7'), 'Monthly on the seventh-last day');
  eq('repeat: past the seventh-last, no words', said('FREQ=MONTHLY;BYMONTHDAY=-8'), null);
  eq('repeat: ordinals', [1, 2, 3, 4, 11, 12, 13, 21, 22, 23, 31].map((n) => said('FREQ=MONTHLY;BYMONTHDAY=' + n).replace('Monthly on the ', '').replace(', skipping months without one', '')),
    ['1st', '2nd', '3rd', '4th', '11th', '12th', '13th', '21st', '22nd', '23rd', '31st']);
  eq('repeat: yearly nth weekday of a month', said('FREQ=YEARLY;BYMONTH=3;BYDAY=2SU'), 'Yearly on the second Sunday of March');
  eq('repeat: yearly on 29 February', said('FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=29'), 'Yearly on 29 February, only in leap years');
  eq('repeat: yearly on the last day of February', said('FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=-1'), 'Yearly on the last day of February');
  eq('repeat: yearly on two months', said('FREQ=YEARLY;BYMONTH=3,9;BYDAY=2TU'), 'Yearly on the second Tuesday of March and September');
  assert('repeat: yearly on a month and day', /^Yearly on (Mar 15|15 Mar)$/.test(said('FREQ=YEARLY;BYMONTH=3;BYMONTHDAY=15')));
  // A month without the day is skipped, never rolled into the next one
  // (31 February is no 2 March). Dates read "Mar 31" whatever the locale's order.
  const mdOrder = (s) => s && s.replace(/\b(\d{1,2}) ([A-Z][a-z]{2})\b/g, '$2 $1');
  eq('repeat: yearly, a listed month without the day is skipped, not rolled over', mdOrder(said('FREQ=YEARLY;BYMONTH=2,3;BYMONTHDAY=31;COUNT=3')), 'Yearly on Mar 31, skipping February, 3 times');
  eq('repeat: yearly, several months without the day', mdOrder(said('FREQ=YEARLY;BYMONTH=1,2,4,5;BYMONTHDAY=31')), 'Yearly on Jan 31 and May 31, skipping February and April');
  eq('repeat: yearly on the 30th of January and February', mdOrder(said('FREQ=YEARLY;BYMONTH=1,2;BYMONTHDAY=30')), 'Yearly on Jan 30, skipping February');
  eq('repeat: yearly, the 29th in February only in leap years', mdOrder(said('FREQ=YEARLY;BYMONTH=2,3;BYMONTHDAY=29')), 'Yearly on Feb 29 and Mar 29, February only in leap years');
  // The same wording rule as the server's (server/tests/fixtures/yearly-words.json, read by both suites).
  {
    const { readFileSync } = await import('node:fs');
    const table = JSON.parse(readFileSync(new URL('../../server/tests/fixtures/yearly-words.json', import.meta.url), 'utf8'));
    for (const row of table.rows) eq('repeat: yearly words, the server\'s table: ' + row.rrule, mdOrder(said(row.rrule)), row.editor);
  }
  eq('repeat: yearly, months that all have the day', mdOrder(said('FREQ=YEARLY;BYMONTH=1,3;BYMONTHDAY=31')), 'Yearly on Jan 31 and Mar 31');
  eq('repeat: yearly, a day no listed month has gets no words', said('FREQ=YEARLY;BYMONTH=2,4;BYMONTHDAY=31'), null);
  eq('repeat: yearly from the start\'s day, a month without it skipped', mdOrder(said('FREQ=YEARLY;BYMONTH=1,2,3', { start: new Date(2027, 0, 31) })), 'Yearly on Jan 31 and Mar 31, skipping February');
  eq('repeat: yearly from the start\'s day, every month has it', mdOrder(said('FREQ=YEARLY;BYMONTH=4,6', { start: new Date(2027, 3, 30) })), 'Yearly on Apr 30 and Jun 30');
  eq('repeat: yearly from the start\'s day, none of the months has it', said('FREQ=YEARLY;BYMONTH=4,6', { start: new Date(2027, 2, 31) }), null);
  eq('repeat: BYMONTH outside a yearly rule is not said', said('FREQ=WEEKLY;BYMONTH=3;BYDAY=MO'), null);
  eq('presets: day 30 says it skips', repeatPresets(new Date(2027, 2, 30)).find((p) => p.key === 'monthly-day').label, 'Monthly on the 30th, skipping months without one');
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

// The sidebar's width (#129, web/src/lib/sidebarwidth.js), per screen.
{
  eq('sidebar width: clamped to 200-500', [clampSidebarWidth(100), clampSidebarWidth(300.4), clampSidebarWidth(900), clampSidebarWidth('x')], [200, 300, 500, 256]);
  const store = new Map();
  const had = globalThis.localStorage;
  globalThis.localStorage = { getItem: (k) => (store.has(k) ? store.get(k) : null), setItem: (k, v) => store.set(k, String(v)), removeItem: (k) => store.delete(k) };
  const BIG = '3840x2160';
  const LAPTOP = '1440x900';
  eq('sidebar width: nothing stored reads as the default', readSidebarWidth(BIG), 256);
  saveSidebarWidth(420, BIG);
  eq('sidebar width: a saved width reads back on its screen', readSidebarWidth(BIG), 420);
  eq('sidebar width: a new screen scales the last one set by screen width', readSidebarWidth(LAPTOP), 200);
  saveSidebarWidth(300, LAPTOP);
  eq('sidebar width: each screen keeps its own', [readSidebarWidth(BIG), readSidebarWidth(LAPTOP)], [420, 300]);
  eq('sidebar width: a third screen scales from the last set', readSidebarWidth('1920x1080'), 400);
  store.set('bc-sidebar-w', '333');
  eq('sidebar width: a width from before is kept on any screen', [readSidebarWidth(BIG), readSidebarWidth(LAPTOP)], [333, 333]);
  saveSidebarWidth(350, LAPTOP);
  eq('sidebar width: and becomes the first saved screen\'s', [readSidebarWidth(LAPTOP), Object.keys(JSON.parse(store.get('bc-sidebar-w')).widths)], [350, [LAPTOP]]);
  store.set('bc-sidebar-w', JSON.stringify({ widths: { [BIG]: 9999 }, last: BIG }));
  eq('sidebar width: a stored width out of range is clamped', readSidebarWidth(BIG), 500);
  for (let i = 0; i < 12; i++) saveSidebarWidth(260 + i, (1000 + i) + 'x800');
  eq('sidebar width: only the latest screens are kept', Object.keys(JSON.parse(store.get('bc-sidebar-w')).widths).length, 8);
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

  // After a change only what's fetched since is fresh; the rest stays held
  // (nothing blanks) but is fetched again when next shown.
  const ms = (iso) => Date.parse(iso);
  const r = (a, b) => ({ start: ms(a + 'T00:00:00Z'), end: ms(b + 'T00:00:00Z') });
  storeState.occ.clear();
  storeState.loadedRanges = [];
  mergeWindow('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z', []);
  mergeWindow('2026-10-01T00:00:00Z', '2026-12-01T00:00:00Z', []);
  newGeneration();
  mergeWindow('2026-10-15T00:00:00Z', '2026-11-15T00:00:00Z', []);
  eq('stale: everything held stays held', storeState.loadedRanges.map(({ start, end }) => ({ start, end })),
    [r('2026-08-01', '2026-09-01'), r('2026-10-01', '2026-10-15'), r('2026-10-15', '2026-11-15'), r('2026-11-15', '2026-12-01')]);
  eq('stale: only the refreshed stretch is fresh', freshRanges(), [r('2026-10-15', '2026-11-15')]);
  eq('stale: missing for a fetch is the stale part', missingRanges(ms('2026-08-15T00:00:00Z'), ms('2026-11-01T00:00:00Z'), freshRanges()),
    [r('2026-08-15', '2026-10-15')]);
  mergeWindow('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z', []);
  eq('stale: fetching a stretch again makes it fresh', freshRanges(), [r('2026-08-01', '2026-09-01'), r('2026-10-15', '2026-11-15')]);
  eq('stale: an answer asked before the change, landing after, is dropped', mergeWindow('2026-10-01T00:00:00Z', '2026-12-01T00:00:00Z', [], dataGeneration() - 1), false);
  storeState.loadedRanges = [];

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
  const occ = (id, start, end, extra = {}) => ({ instanceId: id, eventId: 1, calendarId: 1, title: id, allDay: false, start, end, ...extra });
  // A timed event that began before the window and runs into it, deleted elsewhere.
  storeState.occ.set('long', occ('long', '2026-10-31T20:00:00Z', '2026-11-01T10:00:00Z'));
  storeState.occ.set('allday', occ('allday', '2026-10-31T00:00:00+00:00', '2026-11-02T00:00:00+00:00', { allDay: true }));
  mergeWindow('2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z', []);
  assert('merge: a timed event running into the window is cleared when the answer lacks it', !storeState.occ.has('long'));
  assert('merge: an all-day span overlapping the window is cleared when the answer lacks it', !storeState.occ.has('allday'));
  // Against the server's own answer (Events.php window: rows whose stored
  // instants overlap the window; all-day dates are stored at UTC midnight),
  // no window loses an event the database still holds, whatever the zone and
  // wherever the window's edges fall (#184: a merge by local days dropped
  // all-day events the answer never carried).
  const db = [
    occ('a1', '2026-11-08T00:00:00+00:00', '2026-11-09T00:00:00+00:00', { allDay: true }),
    occ('a2', '2026-11-09T00:00:00+00:00', '2026-11-10T00:00:00+00:00', { allDay: true }),
    occ('a3', '2026-11-07T00:00:00+00:00', '2026-11-12T00:00:00+00:00', { allDay: true }),
    occ('a4', '2026-03-08T00:00:00+00:00', '2026-03-09T00:00:00+00:00', { allDay: true }),
    occ('t1', '2026-11-09T07:30:00Z', '2026-11-09T08:30:00Z'),
    occ('t2', '2026-11-08T23:00:00Z', '2026-11-09T01:00:00Z'),
  ];
  const answer = (rows, a, b) => rows.filter((r) => Date.parse(r.start) < Date.parse(b) && Date.parse(r.end) > Date.parse(a));
  const DAY = 86400000;
  const windows = [];
  for (let d = 5; d <= 12; d++) windows.push([new Date(2026, 10, d), new Date(2026, 10, d + 1)]); // local days
  windows.push([new Date(2026, 10, 9, 10), new Date(2026, 10, 10, 10)]); // a deep link's window
  windows.push([new Date(2026, 10, 8, 12), new Date(2026, 10, 9, 12)]);
  windows.push([new Date(2026, 10, 8, 20), new Date(2026, 10, 9, 20)]); // late edges (west of UTC)
  windows.push([new Date(2026, 10, 8, 4), new Date(2026, 10, 9, 4)]); // early edges (east of UTC)
  const cont = new Date(2026, 1, 1); // an agenda continuation across a DST change, by milliseconds
  windows.push([cont, new Date(cont.getTime() + 60 * DAY)]);
  windows.push([new Date(cont.getTime() + 35 * DAY), new Date(cont.getTime() + 36 * DAY)]);
  let lost = [];
  for (const [a, b] of windows) {
    storeState.occ.clear();
    for (const r of db) storeState.occ.set(r.instanceId, r);
    mergeWindow(isoAt(a), isoAt(b), answer(db, isoAt(a), isoAt(b)));
    for (const r of db) if (!storeState.occ.has(r.instanceId)) lost.push(`${r.instanceId} @ ${isoAt(a)}`);
  }
  eq('merge: no window loses an event the server still holds', lost, []);
  // And what's gone from the database leaves with any window that overlaps it.
  const gone = [];
  for (const [a, b] of windows) {
    storeState.occ.clear();
    for (const r of db) storeState.occ.set(r.instanceId, r);
    const kept = db.filter((r) => r.instanceId !== 'a2' && r.instanceId !== 'a3');
    mergeWindow(isoAt(a), isoAt(b), answer(kept, isoAt(a), isoAt(b)));
    for (const id of ['a2', 'a3']) {
      const r = db.find((x) => x.instanceId === id);
      const overlaps = answer([r], isoAt(a), isoAt(b)).length > 0;
      if (overlaps === storeState.occ.has(id)) gone.push(`${id} @ ${isoAt(a)}`);
    }
  }
  eq('merge: a deleted event leaves with exactly the windows overlapping it', gone, []);
  // A moment (no length) at a window's very start isn't in its answer; the
  // window before ended there, so the server sends it with neither.
  storeState.occ.clear();
  storeState.occ.set('pin', occ('pin', '2026-11-09T08:00:00Z', '2026-11-09T08:00:00Z'));
  mergeWindow('2026-11-09T08:00:00Z', '2026-11-10T08:00:00Z', []);
  assert('merge: a moment at the window\'s start is kept', storeState.occ.has('pin'));
  mergeWindow('2026-11-09T00:00:00Z', '2026-11-10T00:00:00Z', []);
  assert('merge: one inside it goes when the answer lacks it', !storeState.occ.has('pin'));

  // A drop while an older answer is on its way: the moved copy stays until
  // an answer asked after its save landed carries it, and only one whose
  // stretch covers it ends the mark (#190).
  storeState.occ.clear();
  storeState.loadedRanges = [];
  const nov = ['2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z'];
  storeState.occ.set('m', occ('m', '2026-11-03T10:00:00Z', '2026-11-03T11:00:00Z'));
  const asked = dataGeneration();
  patchOccurrence('m', { start: '2026-11-04T10:00:00Z', end: '2026-11-04T11:00:00Z' });
  mergeWindow(...nov, [occ('m', '2026-11-03T10:00:00Z', '2026-11-03T11:00:00Z')], asked);
  eq('merge: an answer asked while the save is on its way keeps the moved copy', storeState.occ.get('m').start, '2026-11-04T10:00:00Z');
  localSaved(['m']);
  mergeWindow(...nov, [occ('m', '2026-11-03T10:00:00Z', '2026-11-03T11:00:00Z')], asked);
  eq('merge: so does one asked before the save landed', storeState.occ.get('m').start, '2026-11-04T10:00:00Z');
  newGeneration(); // the save's refresh
  mergeWindow('2026-12-01T00:00:00Z', '2027-01-01T00:00:00Z', [], dataGeneration());
  mergeWindow(...nov, [occ('m', '2026-11-03T10:00:00Z', '2026-11-03T11:00:00Z')], asked);
  eq('merge: a newer answer that does not cover it leaves the mark', storeState.occ.get('m').start, '2026-11-04T10:00:00Z');
  mergeWindow(...nov, [occ('m', '2026-11-04T10:00:00Z', '2026-11-04T11:00:00Z', { title: 'fresh' })], dataGeneration());
  eq('merge: the newer answer that covers it replaces it', storeState.occ.get('m').title, 'fresh');
  mergeWindow(...nov, [occ('m', '2026-11-04T10:00:00Z', '2026-11-04T11:00:00Z', { title: 'fresher' })], dataGeneration());
  eq('merge: and the mark is gone, so the next answer does too', storeState.occ.get('m').title, 'fresher');
  // A delete while an older answer is on its way: it stays deleted.
  storeState.occ.set('d', occ('d', '2026-11-05T10:00:00Z', '2026-11-05T11:00:00Z', { eventId: 9 }));
  const asked2 = dataGeneration();
  removeOccurrencesOfEvent(9);
  mergeWindow(...nov, [occ('d', '2026-11-05T10:00:00Z', '2026-11-05T11:00:00Z', { eventId: 9 })], asked2);
  assert('merge: an answer asked before a delete does not bring it back', !storeState.occ.has('d'));
  // A Maybe (any local patch) while an older answer is on its way: it stays.
  storeState.occ.set('r', occ('r', '2026-11-06T10:00:00Z', '2026-11-06T11:00:00Z', { relationship: 'available' }));
  const asked3 = dataGeneration();
  patchOccurrence('r', { relationship: 'maybe' });
  mergeWindow(...nov, [occ('r', '2026-11-06T10:00:00Z', '2026-11-06T11:00:00Z', { relationship: 'available' })], asked3);
  eq('merge: an answer asked before a Maybe keeps it', storeState.occ.get('r').relationship, 'maybe');
  // A save that failed puts back what was there; an answer from before the
  // next change can't put an older version over it.
  restoreOccurrence('r', occ('r', '2026-11-06T10:00:00Z', '2026-11-06T11:00:00Z', { relationship: 'available' }));
  eq('merge: a failed save puts back what was there', storeState.occ.get('r').relationship, 'available');
  localSaved(['d']);
  newGeneration();
  mergeWindow(...nov, [], dataGeneration());
  assert('merge: once their saves land, an answer carrying them ends the marks', !storeState.occ.has('d') && !storeState.occ.has('r'));
  storeState.occ.clear();
  storeState.loadedRanges = [];
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

console.log('--- second review (#184) ---');
{
  const at = (d, h, m = 0) => isoAt(new Date(2026, 10, d, h, m));
  // The agenda lists an overnight event on its evening, not as a two-day span.
  const late = { instanceId: 'late', calendarId: 1, title: 'Late show', allDay: false, start: at(6, 22), end: at(7, 1) };
  const g = buildAgendaGroups([late]);
  eq('agenda: an overnight event is one row on its evening', g.map((x) => [x.dayKey, x.rows.map((r) => r.kind)]), [['2026-11-06', ['normal']]]);
  // A group mixing all-day and timed members stays on its day, in any zone.
  const hol = { instanceId: 'h1', calendarId: 1, title: 'Harvest Day', allDay: true, start: '2026-06-02T00:00:00+00:00', end: '2026-06-03T00:00:00+00:00' };
  const parade = { instanceId: 'h2', calendarId: 1, title: 'Harvest Day (parade)', allDay: false, start: isoAt(new Date(2026, 5, 2, 10)), end: isoAt(new Date(2026, 5, 2, 12)), highlighted: true, highlightColor: '#ff0000', isNew: true };
  const grp = groupOccurrences([hol, parade], { 1: true })[0];
  eq('grouping: a mixed group stays on its day', occurrenceDaySpan(grp), { startKey: '2026-06-02', endKey: '2026-06-02' });
  eq('grouping: a mixed group keeps the highlight and the new mark', [grp.highlightColor, grp.isNew], ['#ff0000', true]);
  // An event opened from elsewhere that the calendar already holds stays drawn.
  storeState.occ.clear();
  storeState.occ.set('w', { instanceId: 'w', calendarId: 1, title: 'Old', allDay: false, start: at(3, 9), end: at(3, 10) });
  insertOccurrence({ instanceId: 'w', calendarId: 1, title: 'New', allDay: false, start: at(3, 9), end: at(3, 10) });
  eq('held: one already on the calendar is refreshed, not held', [storeState.occ.get('w').title, !!storeState.occ.get('w')._held], ['New', false]);
  insertOccurrence({ instanceId: 'x', calendarId: 1, title: 'Hidden', allDay: false, start: at(3, 9), end: at(3, 10) });
  assert('held: one the windows lack is held', !!storeState.occ.get('x')._held);
  storeState.occ.clear();
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

console.log('--- update notices (#194) ---');
{
  const checkedAt = '2026-11-01T09:00:00.000-07:00';
  const base = { currentVersion: '1.5.0', checkedAt, lastError: null, securityRequired: false, priority: 'routine', releaseUrl: 'https://example.test/r' };
  const current = { ...base, latestVersion: '1.5.0', newestVersion: '1.5.0', available: false, blocked: false, showBanner: false };
  const step = { ...base, currentVersion: '1.4.0', latestVersion: '1.5.0', newestVersion: '2.0.0', available: true, blocked: false, showBanner: true };
  const plain = { ...base, currentVersion: '1.4.0', latestVersion: '1.5.0', newestVersion: '1.5.0', available: true, blocked: false, showBanner: true };
  const blocked = { ...base, latestVersion: '1.5.0', newestVersion: '2.0.0', available: false, blocked: true, showBanner: true };
  assert('updates: a current install is up to date', /^Up to date \(checked /.test(updateStatusText(current)));
  eq('updates: a plain update is available', updateStatusText(plain), 'Version 1.5.0 is available');
  eq('updates: a step says what needs it', updateStatusText(step), 'Version 1.5.0 is available; releases up to 2.0.0 need it first');
  const blockedText = "Better-Cal 2.0.0 is out, but this copy can't verify it: it's signed with a key this copy doesn't list. Update by hand (Updating in docs/install.md).";
  eq('updates: a blocked copy is not called up to date', updateStatusText(blocked), blockedText);
  eq('updates: Check now says the same', checkResultText(blocked), blockedText);
  eq('updates: Check now on a current install', checkResultText(current), 'Better-Cal is up to date');
  eq('updates: Check now on a step', checkResultText(step), 'Better-Cal 1.5.0 is available; releases up to 2.0.0 need it first');
  eq('updates: the banner shows the blocked notice once', bannerText(blocked), blockedText);
  eq('updates: the banner says nothing once dismissed', bannerText({ ...blocked, showBanner: false }), null);
  eq('updates: the banner says nothing on a current install', bannerText(current), null);
  eq('updates: the banner names a step', bannerText(step), 'Better-Cal 1.5.0 is available; releases up to 2.0.0 need it first.');
  eq('updates: the banner names a required security update first', bannerText({ ...blocked, securityRequired: true, minimumSecureVersion: '1.5.1' }),
    'A Better-Cal security update is required. Update to 1.5.1 or newer.');
  eq('updates: not checked yet', updateStatusText({ ...current, checkedAt: null }), 'Not checked yet');
  assert('updates: the by-hand link goes to the Updating section', /docs\/install\.md#updating$/.test(INSTALL_UPDATING_URL));
}

// A weekly series on Tuesdays at 19:00 in Los Angeles dragged to Wednesday,
// with forty edited days, as the server sends the plan (Series/Model.php):
// data only for the panel (dates, outcomes, `kept` and `takes`), and the
// server's own `words` beside them for API callers, which the panel never
// reads. The sixth shows on a date of its own with its keep-or-move choice,
// the twenty-first becomes an event of its own, and the rest go with their
// events (taking the new date), each keeping the fields keeps(i) names.
const fixtureKey = (n) => new Date(Date.UTC(2027, 0, 1) + n * 86400000).toISOString().slice(0, 10);
// A placement as the plan writes it (series-model.md, The Plan): a timed
// day's date and clock of one instant in `tz`, `tz` named beside them when
// it isn't the plan's (named); an all-day day's date alone.
const pad2 = (n) => String(n).padStart(2, '0');
function wallAt(date, hhmm, tz) {
  const off = zoneOffsetOf(tz, wallInstantOf(date + 'T' + hhmm, tz));
  const a = Math.abs(off);
  return date + 'T' + hhmm + ':00' + (off < 0 ? '-' : '+') + pad2(Math.floor(a / 60)) + ':' + pad2(a % 60);
}
const timedPlace = (date, hhmm, tz, named = false) => ({ date, at: wallAt(date, hhmm, tz), ...(named ? { tz } : {}) });
const datePlace = (date) => ({ date });
const LA_TZ = 'America/Los_Angeles';
function sampleEditedDays(keeps = () => ['title', 'location']) {
  const iso = (ms) => new Date(ms).toISOString().replace('.000Z', 'Z');
  const DAY = 86400000;
  const days = [], groups = [];
  for (let i = 0; i < 40; i++) {
    const key = iso(Date.UTC(2027, 3, 7, 2) + i * 7 * DAY);
    const newKey = iso(Date.parse(key) + DAY);
    const shows = fixtureKey(95 + i * 7); // Tue 6 Apr 2027 on
    const to = fixtureKey(96 + i * 7);
    const title = 'Sample practice ' + (i + 1);
    const at = (date) => timedPlace(date, '19:00', LA_TZ);
    if (i === 5) {
      const own = fixtureKey(95 + i * 7 + 3);
      days.push({ key, newKey, kind: 'edited', title, outcome: 'stays', day: shows, ownDate: at(own), before: at(own), after: at(own), shows: own, to: own, kept: ['date', 'title'], takes: [],
        head: `'${title}' server head`, outcomeWords: 'server outcome: stays there', words: `'${title}' server sentence: stays there, keeps its title` });
      groups.push({ id: 'movedDays', label: 'Server label for days moved on their own', day: key, selected: 'keep', count: 1,
        options: [{ id: 'keep', label: 'Server keep label', date: own }, { id: 'move', label: 'Server move label', date: fixtureKey(95 + i * 7 + 4) }] });
    } else if (i === 20) {
      days.push({ key, newKey: null, kind: 'edited', title, outcome: 'separate', day: shows, before: at(shows), after: at(shows), shows, to: shows, kept: ['title'], takes: [],
        head: `'${title}' server head`, outcomeWords: 'server outcome: becomes an event of its own', words: `'${title}' server sentence: becomes an event of its own` });
    } else {
      days.push({ key, newKey, kind: 'edited', title, outcome: 'moves', day: shows, before: at(shows), after: at(to), shows, to, kept: keeps(i), takes: ['date'],
        head: `'${title}' server head`, outcomeWords: 'server outcome: moves', words: `'${title}' server sentence: moves, keeps its things` });
    }
  }
  return {
    hash: 'sample-forty', scope: 'all', direct: false, zone: LA_TZ,
    rule: {
      before: 'FREQ=WEEKLY;BYDAY=TU', after: 'FREQ=WEEKLY;BYDAY=WE', wordsBefore: 'Server words before', words: 'Server words after',
      from: { rrule: 'FREQ=WEEKLY;BYDAY=TU', start: '2027-01-05T19:00:00-08:00', tz: 'America/Los_Angeles', allDay: false },
      to: { rrule: 'FREQ=WEEKLY;BYDAY=WE', start: '2027-01-06T19:00:00-08:00', tz: 'America/Los_Angeles', allDay: false },
    },
    bound: { kind: 'none', words: 'Server bound words', events: null, last: null, from: { kind: 'none' } },
    next: ['2027-04-07T19:00:00-07:00', '2027-04-14T19:00:00-07:00', '2027-04-21T19:00:00-07:00', '2027-04-28T19:00:00-07:00'].map((start) => ({ start, kind: 'edited' })),
    nextMore: true,
    counts: { events: null, edited: 40, skipped: 0 },
    groups, days,
    summary: 'Server summary',
  };
}

// Edited days with their own title and place, each owned field a choice
// about all of them, as the server sends them for a rename and a new place
// (1.5.0 review, F2): owned([ownDay(...), ...]).
const ownDay = (key, shows, extra = {}) => {
  const d = { key, newKey: key, kind: 'edited', title: 'Sample own title ' + shows.slice(8), outcome: 'stays', shows, to: shows,
    at: shows + 'T18:00:00+00:00', kept: ['title', 'location'], takes: [], ...extra };
  const place = { date: d.shows, at: d.at };
  return { ...d, day: key.slice(0, 10), before: place, after: place, ...(d.kept.includes('date') ? { ownDate: place } : {}) };
};
const ownGroups = (keys) => [['ownTitle', 'title'], ['ownLocation', 'location']].map(([id, field]) => ({ id, field, count: keys.length, days: keys, selected: 'keep',
  ...(field === 'title' ? { value: 'Sample renamed series' } : {}), options: [{ id: 'keep' }, { id: 'give' }] }));
const utcPart = (rrule, start) => ({ rrule, start, tz: 'UTC', allDay: false });
const owned = (days) => ({
  hash: 'own' + days.length, direct: false, zone: 'UTC', rule: { from: utcPart('FREQ=WEEKLY;BYDAY=TU', '2027-01-05T18:00:00+00:00'), to: utcPart('FREQ=WEEKLY;BYDAY=TU', '2027-01-05T18:00:00+00:00') },
  bound: { kind: 'none', events: null, last: null, from: { kind: 'none' } }, next: [], counts: { events: null, edited: days.length, skipped: 0 },
  groups: ownGroups(days.map((d) => d.key)), days, refusals: [], notes: [],
});
// A New York series on Tuesdays at 01:30 dragged to Wednesday, with a day at
// its own 00:30 (1.5.0 review, F4).
const nyPart = (rrule, start) => ({ rrule, start, tz: 'America/New_York', allDay: false });
const sampleLateClass = () => ({
  hash: 'ny1', direct: false, zone: 'America/New_York', rule: { from: nyPart('FREQ=WEEKLY;BYDAY=TU', '2027-01-05T01:30:00-05:00'), to: nyPart('FREQ=WEEKLY;BYDAY=WE', '2027-01-06T01:30:00-05:00') },
  bound: { kind: 'none', events: null, last: null, from: { kind: 'none' } },
  next: ['2027-01-20T01:30:00-05:00', '2027-01-27T01:30:00-05:00', '2027-02-03T01:30:00-05:00'].map((start) => ({ start, allDay: false })), nextMore: true,
  counts: { events: null, edited: 1, skipped: 0 }, groups: [], refusals: [], notes: [],
  days: [{ key: '2027-01-12T06:30:00Z', newKey: '2027-01-13T06:30:00Z', kind: 'edited', title: 'Sample late class', outcome: 'moves', day: '2027-01-12',
    before: { date: '2027-01-12', at: '2027-01-12T00:30:00-05:00' }, after: { date: '2027-01-13', at: '2027-01-13T00:30:00-05:00' }, shows: '2027-01-12', to: '2027-01-13',
    at: '2027-01-13T00:30:00-05:00', kept: ['time'], takes: ['date'] }],
});

console.log('--- the series change panel, its sentences from the plan\'s data (1.5.0) ---');
{
  const { planView, commitPlan, creationLine } = await import('../src/lib/seriesplan.js');
  const { setTimeFormat } = await import('../src/lib/dates.js');
  setTimeFormat('12');
  const LA = 'America/Los_Angeles';
  const ctx = (extra = {}) => ({ kind: 'drag', title: 'Choir', calendar: 'Sample', refYear: 2027, ...extra });
  const rule = (before, after, startBefore, startAfter, extra = {}) => ({
    before, after, from: { rrule: before, start: startBefore, tz: LA, allDay: false }, to: { rrule: after, start: startAfter, tz: LA, allDay: false }, ...extra,
  });
  const noEnd = { kind: 'none', events: null, last: null, from: { kind: 'none' } };
  // The doc's "Choir": Tue 9 Mar dragged to Wed 10 Mar, an edited day with its own title and place, one at its own date, a skip.
  const at7 = (date) => timedPlace(date, '19:00', LA);
  const choir = {
    hash: '9f2c41d07a', scope: 'all', direct: false, zone: LA,
    rule: rule('FREQ=WEEKLY;BYDAY=TU', 'FREQ=WEEKLY;BYDAY=WE', '2027-01-05T19:00:00-08:00', '2027-01-06T19:00:00-08:00'),
    bound: noEnd,
    next: ['2027-03-10T19:00:00-08:00', '2027-03-17T19:00:00-07:00', '2027-03-26T19:00:00-07:00', '2027-04-07T19:00:00-07:00'].map((start) => ({ start })),
    nextMore: true,
    counts: { events: null, edited: 2, skipped: 1 },
    groups: [{ id: 'movedDays', day: '2027-03-24T02:00:00Z', selected: 'keep', count: 1, options: [{ id: 'keep', date: '2027-03-26' }, { id: 'move', date: '2027-03-27' }] }],
    days: [
      { key: '2027-04-14T02:00:00Z', newKey: '2027-04-15T02:00:00Z', kind: 'edited', title: 'Rehearsal', outcome: 'moves', day: '2027-04-13', before: at7('2027-04-13'), after: at7('2027-04-14'),
        shows: '2027-04-13', to: '2027-04-14', kept: ['title', 'location'], takes: ['date'] },
      { key: '2027-03-24T02:00:00Z', newKey: '2027-03-25T02:00:00Z', kind: 'edited', title: 'Choir', outcome: 'stays', day: '2027-03-23', ownDate: at7('2027-03-26'), before: at7('2027-03-26'), after: at7('2027-03-26'),
        shows: '2027-03-26', to: '2027-03-26', kept: ['date'], takes: [] },
      { key: '2027-03-31T02:00:00Z', newKey: '2027-04-01T02:00:00Z', kind: 'skipped', outcome: 'moves', shows: '2027-03-30', to: '2027-03-31', kept: [], takes: [] },
    ],
    refusals: [], notes: [],
  };
  const v = planView(choir, ctx());
  eq('series panel: the title states the action and the scope; the series on its calendar under it', [v.title, v.series], ['Move all events', "'Choir' on Sample"]);
  eq('series panel: CHANGE names only what changes, the rest as context, the end part of it', v.change, 'From Tuesday to Wednesday each week, at 7:00 PM, with no end, as before.');
  eq('series panel: COMING UP, three dates and the ellipsis while the series goes on', v.comingUp, 'Wed, Mar 10 · Wed, Mar 17 · Fri, Mar 26 …');
  const zoned = planView({ ...choir, groups: [{ id: 'zone', from: LA, to: 'Europe/Berlin', selected: 'clock', options: [
    { id: 'clock', start: '2027-01-06T19:00:00+01:00', next: [{ start: '2027-03-10T19:00:00+01:00', allDay: false }, { start: '2027-03-12T00:00:00+00:00', allDay: true }] },
    { id: 'moment', start: '2027-01-07T04:00:00+01:00', next: [{ start: '2027-03-11T03:00:00+01:00', allDay: false }] }] }] }, ctx());
  eq('series panel: a zone option\'s list entries give its dates, an all-day entry its date, and its start its clocks', zoned.readings[0].options.map((o) => [o.label, o.dates]), [
    ['Keep the clock time: 7:00 PM in Berlin', 'Wed, Mar 10 · Fri, Mar 12'], ['Keep the same moment: 7:00 PM in Los Angeles, 4:00 AM in Berlin', 'Thu, Mar 11']]);
  // A zone change names each side's zone after its clock, as the Activity
  // entry does (#223): the same clock time is no longer "as before".
  const BERLIN = 'Europe/Berlin';
  const toZone = (after, startAfter) => planView({ ...choir, zone: BERLIN, rule: { before: 'FREQ=WEEKLY;BYDAY=TU', after,
    from: { rrule: 'FREQ=WEEKLY;BYDAY=TU', start: '2027-01-05T19:00:00-08:00', tz: LA, allDay: false },
    to: { rrule: after, start: startAfter, tz: BERLIN, allDay: false } } }, ctx()).change;
  eq('series panel: a zone change keeping the clock time names both zones', toZone('FREQ=WEEKLY;BYDAY=TU', '2027-01-05T19:00:00+01:00'),
    'From weekly on Tuesday at 7:00 PM in Los Angeles to weekly on Tuesday at 7:00 PM in Berlin, with no end, as before.');
  eq('series panel: a zone change keeping the same moment names both zones and the new clock', toZone('FREQ=WEEKLY;BYDAY=WE', '2027-01-06T04:00:00+01:00'),
    'From weekly on Tuesday at 7:00 PM in Los Angeles to weekly on Wednesday at 4:00 AM in Berlin, with no end, as before.');
  eq('series panel: AFFECTS, all events with no end, then the changed days with what they have of their own, and the removed', v.affects, 'All events, with no end. 2 were changed earlier (title, location and 1 other), 1 was removed.');
  // Each class of days the change loses or adds is one sentence under
  // Affects, from its first days and its rate (no end) or its count.
  const classed = (losses, gains) => planView({ ...choir, losses, gains }, ctx()).classes;
  eq('series panel: a class of days lost for want of a day names the member, its first days and its rate', classed([
    { why: 'noday', count: null, dates: ['2027-02-15', '2027-04-15', '2027-06-15'], member: '31st', period: 'month', every: { count: 5, span: 12, unit: 'month' } }], []),
  ['No 31st in some months, so Feb 15, Apr 15 and Jun 15 have no event, and 5 in every 12 months after them.']);
  eq('series panel: a bounded series counts its class instead of a rate; one day says it alone', [
    classed([{ why: 'noday', count: 7, dates: ['2027-02-15', '2027-04-15', '2027-06-15', '2027-09-15', '2027-11-15'], member: '31st', period: 'month' }], []),
    classed([{ why: 'noday', count: 1, dates: ['2028-02-29'], member: '29 February', period: 'year' }], []),
  ], [['No 31st in some months, so Feb 15, Apr 15, Jun 15, Sep 15, Nov 15 and 2 more have no event.'], ['No 29 February in some years, so Feb 29, 2028 has no event.']]);
  eq('series panel: days before a moved start, days past the new end, and the days a change adds, each a sentence', classed([
    { why: 'start', count: 2, dates: ['2027-01-04', '2027-01-11'] }, { why: 'end', count: 1, dates: ['2027-12-15'] }],
  [{ count: null, dates: ['2027-03-01', '2027-05-01', '2027-07-01'], member: '1st', period: 'month', every: { count: 5, span: 12, unit: 'month' } }]),
  ['Jan 4 and Jan 11 are before the new start.', 'Dec 15 is after the new end.', 'Adds Mar 1, May 1 and Jul 1, and 5 in every 12 months after them.']);
  eq('series panel: a start moved far: the first five days before it and how many more, whatever the end', classed([{ why: 'start', count: 8, dates: ['2027-01-15', '2027-02-15', '2027-03-15', '2027-04-15', '2027-05-15'] }], []),
    ['Jan 15, Feb 15, Mar 15, Apr 15, May 15 and 3 more are before the new start.']);
  eq('series panel: no class, no sentence; a refused plan says none', [classed([], []), planView({ ...choir, refusals: [{ code: 'dayset', words: '' }], losses: [{ why: 'noday', count: 1, dates: ['2027-02-15'] }] }, ctx()).classes], [[], []]);
  eq('series panel: a day moved earlier says so, then its question right under it, then its radios', [v.edited[0].line1, v.edited[0].line2, v.edited[0].choices[0].reason, v.edited[0].choices[0].options.map((o) => [o.label, o.selected])], [
    "'Choir' on Tue, Mar 23 was moved earlier to Fri, Mar 26 and stays where it is.", '', 'Keep it there, or move it with the rest?', [['Keep it on Fri, Mar 26', true], ['Move it to Sat, Mar 27', false]]]);
  eq('series panel: an ordinary edited day in one sentence, what it has of its own beside its name, nothing it keeps', v.edited[1].line1, "'Rehearsal', with its own title and location, on Tue, Apr 13 moves to Wed, Apr 14.");
  const inTrip = (kept) => planView({ ...choir, days: [{ ...choir.days[0], kept }, choir.days[1], choir.days[2]] }, ctx());
  eq('series panel: a day in a trip says so beside its name, and the trip is no field in the count', [inTrip(['title', 'trip']).edited[1].line1, inTrip(['title', 'location', 'trip']).edited[1].line1, inTrip(['title', 'trip']).affects], [
    "'Rehearsal', with its own title and in a trip, on Tue, Apr 13 moves to Wed, Apr 14.", "'Rehearsal', with its own title and location and in a trip, on Tue, Apr 13 moves to Wed, Apr 14.", 'All events, with no end. 2 were changed earlier (title and date), 1 was removed.']);
  eq('series panel: a removed day that moves with the series, in one sentence with its consequence', v.skipped.map((r) => r.line1), ["'Choir' on Tue, Mar 30 was removed earlier, so no event is added on Wed, Mar 31."]);
  eq('series panel: no count, so the button says all events', [v.action, v.canConfirm, v.refused], ['Move all events', true, null]);
  eq('series panel: the commit is the doc\'s', commitPlan(choir, {}), { hash: '9f2c41d07a', choices: { movedDays: 'keep' } });
  eq('series panel: a pick goes into the commit, and a pick that is no option is the plan\'s', [commitPlan(choir, { movedDays: 'move' }).choices, commitPlan(choir, { movedDays: 'nowhere' }).choices], [{ movedDays: 'move' }, { movedDays: 'keep' }]);
  const waiting = planView(null, ctx());
  eq('series panel: before the plan, only what the client knows, and no confirm', [waiting.title, waiting.loading, waiting.canConfirm], ['Move all events', true, false]);
  eq('series panel: a plan without a hash can\'t be confirmed', planView({ ...choir, hash: '' }, ctx()).canConfirm, false);

  // Every time in the user's setting: 12-hour and 24-hour.
  setTimeFormat('24');
  eq('series panel: in the 24-hour setting every time is 24-hour', planView(choir, ctx()).change, 'From Tuesday to Wednesday each week, at 19:00, with no end, as before.');
  setTimeFormat('12');
  eq('series panel: and back in the 12-hour setting, 12-hour', planView(choir, ctx()).change, 'From Tuesday to Wednesday each week, at 7:00 PM, with no end, as before.');

  // The bound in the Change sentence and in Affects.
  const bound = (b) => planView({ ...choir, bound: b, counts: { events: b.events, edited: 0, skipped: 0 } }, ctx());
  eq('series panel: the bound, as before or changed', [
    bound({ kind: 'none', from: { kind: 'count', count: 6 } }).change.replace(/^.*7:00 PM, /, ''),
    bound({ kind: 'until', events: 13, last: '2028-01-20', from: { kind: 'until', last: '2028-01-20' } }).change.replace(/^.*7:00 PM, /, ''),
    bound({ kind: 'until', events: 13, last: '2027-11-01', from: { kind: 'until', last: '2027-10-31' } }).change.replace(/^.*7:00 PM, /, ''),
    bound({ kind: 'count', count: 6, events: 6, last: '2027-07-01', from: { kind: 'count', count: 6 } }).change.replace(/^.*7:00 PM, /, ''),
    bound({ kind: 'count', count: 10, events: 10, last: '2027-11-01', from: { kind: 'count', count: 6 } }).change.replace(/^.*7:00 PM, /, ''),
  ], ['with no end (was 6 events).', 'ending Jan 20, 2028, as before.', 'ending Nov 1 (was Oct 31).', 'for 6 events, as before.', 'for 10 events (was 6).']);
  eq('series panel: an UNTIL with no last day is read in its rule\'s zone, never the device\'s', bound({ kind: 'until', until: '20271102T060000Z', events: 13, from: { kind: 'none' } }).change.replace(/^.*7:00 PM, /, ''), 'ending Nov 1.');
  const until = planView({ ...choir, bound: { kind: 'until', events: 13, last: '2028-01-20', from: { kind: 'until', last: '2028-01-20' } }, counts: { events: 13, edited: 11, skipped: 1 }, next: choir.next.slice(0, 3), nextMore: false }, ctx());
  eq('series panel: an end date, its count on Affects and the button, no ellipsis when the series ends with the list', [until.affects, until.action, until.comingUp.endsWith('…')],
    ['13 events, ending Jan 20, 2028. 11 were changed earlier (title, location and 1 other), 1 was removed.', 'Move 13 events', false]);
  const fieldsOf = (kepts) => planView({ ...choir, counts: { events: null, edited: kepts.length, skipped: 0 }, days: kepts.map((kept, i) => ({ key: 'k' + i, kind: 'edited', outcome: 'moves', kept })) }, ctx()).affects;
  eq('series panel: AFFECTS names the fields, most common first, at most two', [fieldsOf([['title'], ['title']]), fieldsOf([['title', 'location'], ['title']]), fieldsOf([['title', 'location', 'description'], ['title', 'reminders']])], [
    'All events, with no end. 2 were changed earlier (title).', 'All events, with no end. 2 were changed earlier (title and location).', 'All events, with no end. 2 were changed earlier (title, location and 2 others).']);
  eq('series panel: Affects in the singular, a zero part left out', [
    planView({ ...choir, counts: { events: null, edited: 1, skipped: 0 } }, ctx()).affects, planView({ ...choir, counts: { events: null, edited: 0, skipped: 2 } }, ctx()).affects,
  ], ['All events, with no end. 1 was changed earlier (title, location and 1 other).', 'All events, with no end. 2 were removed.']);
  eq('series panel: Affects says nothing more when no day is edited or skipped', planView({ ...choir, counts: { events: null, edited: 0, skipped: 0 } }, ctx()).affects, 'All events, with no end.');

  // Readings: "Gym", Wed 10 Mar dragged to Thu 11 Mar.
  const gymNext = (days) => days.map((d) => '2027-03-' + d + 'T07:00:00-0' + (Number(d) >= 14 ? '7' : '8') + ':00');
  const gym = {
    hash: 'a1', rule: rule('FREQ=WEEKLY;BYDAY=MO,WE,FR', 'FREQ=WEEKLY;BYDAY=MO,TH,FR', '2027-01-04T07:00:00-08:00', '2027-01-04T07:00:00-08:00'), bound: noEnd,
    groups: [{ id: 'reading', selected: 'member', options: [
      { id: 'member', rrule: 'FREQ=WEEKLY;BYDAY=MO,TH,FR', next: gymNext(['11', '12', '15']) },
      { id: 'pattern', rrule: 'FREQ=WEEKLY;BYDAY=TU,TH,SA', next: gymNext(['11', '13', '16']), differs: { dates: ['2027-03-13'] } },
    ] }],
    days: [], refusals: [],
  };
  const g = planView(gym, ctx({ title: 'Gym' }));
  eq('series panel: readings under CHANGE, a reason, then each option with its dates and how it differs', [g.readings[0].reason, g.readings[0].options.map((o) => [o.label, o.dates, o.note, o.selected])], [
    'This change can mean two things:', [
      ['Move every Wednesday to Thursday', 'Thu, Mar 11 · Fri, Mar 12 · Mon, Mar 15', '', true],
      ['Shift the whole weekly pattern', 'Thu, Mar 11 · Sat, Mar 13 · Tue, Mar 16', '', false],
    ]]);
  const toFri = { ...gym, groups: [{ ...gym.groups[0], selected: 'pattern' }], refusals: [{ group: 'reading', option: 'member', code: 'member_taken', words: 'Server reason' }] };
  const f = planView(toFri, ctx({ title: 'Gym' }));
  eq('series panel: a refused reading is a disabled row with its reason, the rest stands', [f.readings[0].options[0].disabled, f.readings[0].options[0].reason, f.refused, f.canConfirm], [true, 'That day is already one of its days.', null, true]);
  eq('series panel: a refused reading can\'t be picked into the commit', commitPlan(toFri, { reading: 'member' }).choices, { reading: 'pattern' });
  const monthEnd = planView({ ...gym, rule: rule('FREQ=MONTHLY;BYMONTHDAY=15', 'FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1', '2027-01-15T19:00:00-08:00', '2027-01-30T19:00:00-08:00'), groups: [{ id: 'reading', selected: 'clamped', options: [
    { id: 'clamped', rrule: 'FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1', next: ['2027-03-30T19:00:00-07:00'] }, { id: 'skip', rrule: 'FREQ=MONTHLY;BYMONTHDAY=30', next: ['2027-03-30T19:00:00-07:00'] },
  ] }] }, ctx({ title: 'Book club' }));
  eq('series panel: month-end readings read as their rules', monthEnd.readings[0].options.map((o) => o.label), ['Monthly on the 30th, or the last day in shorter months', 'Monthly on the 30th, skipping months without one']);

  // The bound choice: "Cello lesson", 6 events, its 31 Oct dragged to 1 Nov.
  const cello = {
    hash: 'k1', rule: rule('FREQ=MONTHLY;BYMONTHDAY=31;COUNT=6', 'FREQ=MONTHLY;BYMONTHDAY=1;COUNT=10', '2027-01-31T16:00:00-08:00', '2027-02-01T16:00:00-08:00'),
    bound: { kind: 'count', count: 10, events: 10, last: '2027-11-01', from: { kind: 'count', count: 6 } }, counts: { events: 10, edited: 0, skipped: 0 },
    groups: [{ id: 'bound', selected: 'end', count: 6, splits: true, counted: true, options: [
      { id: 'end', bound: { kind: 'count', events: 10, last: '2027-11-01' } }, { id: 'count', bound: { kind: 'count', events: 6, last: '2027-07-01', separate: 1 } },
    ] }],
  };
  const c = planView(cello, ctx({ title: 'Cello lesson' }));
  eq('series panel: the bound choice says why and asks', [c.readings[0].reason, c.readings[0].options.map((o) => [o.label, o.selected])], [
    'The series is set to 6 events, and the day you moved would fall after the sixth. Keep the end date, or keep the count?',
    [['Keep the end: 10 events, ending Nov 1', true], ['Keep 6 events; the moved day becomes an event of its own', false]]]);
  eq('series panel: a count names its events on the button', c.action, 'Move 10 events');
  // A count changed from a day on: 8 in the whole series (6 from the split day), or 8 from that day.
  const club = {
    ...cello, rule: rule('FREQ=WEEKLY;BYDAY=TU;COUNT=6', 'FREQ=WEEKLY;BYDAY=TU;COUNT=6', '2027-01-19T10:00:00-08:00', '2027-01-19T10:00:00-08:00'),
    bound: { kind: 'count', count: 6, events: 6, last: '2027-02-23', from: { kind: 'count', count: 4 } },
    groups: [{ id: 'bound', selected: 'whole', count: 8, at: '2027-01-19', options: [
      { id: 'whole', bound: { kind: 'count', events: 6, last: '2027-02-23' } }, { id: 'part', bound: { kind: 'count', events: 8, last: '2027-03-09' } },
    ] }],
  };
  const cl = planView(club, ctx({ title: 'Sample club' }));
  eq('series panel: a count changed from a day on asks whether it counts the whole series or from that day', [cl.readings[0].reason, cl.readings[0].options.map((o) => [o.label, o.selected])], [
    'You set 8 events. Count them in the whole series, or from Jan 19 on?',
    [['8 in the whole series: 6 events from Jan 19, ending Feb 23', true], ['8 events from Jan 19, ending Mar 9', false]]]);

  // A day the new rule would add at the start: among the skipped days, a reason and its radios.
  const pottery = {
    hash: 'p2', rule: rule('FREQ=WEEKLY;BYDAY=MO,WE;COUNT=5', 'FREQ=WEEKLY;BYDAY=WE,FR;COUNT=6', '2027-01-04T18:00:00-08:00', '2027-01-06T18:00:00-08:00'),
    bound: { kind: 'count', count: 6, events: 5, last: '2027-01-22', from: { kind: 'count', count: 5 } }, counts: { events: 5, edited: 0, skipped: 1 },
    groups: [{ id: 'edge', selected: 'without', dates: ['2027-01-08'], side: 'start', options: [{ id: 'without' }, { id: 'add' }] }],
    days: [{ key: null, newKey: '2027-01-09T02:00:00Z', kind: 'skipped', outcome: 'generated', to: '2027-01-08', side: 'start' }],
  };
  const pv = planView(pottery, ctx({ title: 'Pottery' }));
  eq('series panel: a day the new rule would add is a choice among the skipped days', [pv.skipped.length, pv.skipped[0].choices[0].lead, pv.skipped[0].choices[0].reason, pv.skipped[0].choices[0].options.map((o) => o.label)],
    [1, 'The new rule would add Fri, Jan 8 at the start.', 'Add it, or leave it out?', ['Leave it out', 'Add it']]);
  eq('series panel: a skip the new rule has no day for', planView({ ...pottery, groups: [], days: [{ key: '2027-01-14T02:00:00Z', kind: 'skipped', outcome: 'dropped', shows: '2027-01-13', why: 'noday' }] }, ctx()).skipped.map((r) => r.line1),
    ["'Choir' on Wed, Jan 13 was removed earlier; the new pattern has no day there."]);
  // A start moved later: the removed days before it go with their occurrences, said once.
  {
    const W = await import('../src/lib/planwords.js');
    const before = (key, shows) => ({ key, newKey: null, kind: 'skipped', outcome: 'dropped', shows, why: 'outside', side: 'start' });
    eq('series panel: removed days before a start moved later are no longer needed, two and one', [
      W.removedRows([before('2027-01-05T02:00:00Z', '2027-01-04'), before('2027-01-12T02:00:00Z', '2027-01-11')], { series: 'Pottery class' }).map((r) => r.line1),
      W.removedRows([before('2027-01-05T02:00:00Z', '2027-01-04'), { key: '2027-03-02T02:00:00Z', newKey: '2027-03-02T02:00:00Z', kind: 'skipped', outcome: 'stays', shows: '2027-03-01' }], { series: 'Pottery class', refYear: 2027 }).map((r) => r.line1),
    ], [
      ['2 removed days before the new start are no longer needed.'],
      ['1 removed day before the new start is no longer needed.', "'Pottery class' on Mon, Mar 1 was removed earlier, and stays removed."],
    ]);
    // Acceptance 8 in the panel: REMOVED DAYS says the two in one line.
    const course = {
      hash: 'a8', rule: rule('FREQ=WEEKLY;BYDAY=MO;COUNT=8', 'FREQ=WEEKLY;BYDAY=MO;COUNT=8', '2027-01-04T18:00:00-08:00', '2027-01-18T18:00:00-08:00'),
      bound: { kind: 'count', count: 8, events: 8, last: '2027-03-08', from: { kind: 'count', count: 8, events: 8, last: '2027-02-22' } }, counts: { events: 8, edited: 0, skipped: 0 },
      groups: [], days: [before('2027-01-05T02:00:00Z', '2027-01-04'), before('2027-01-12T02:00:00Z', '2027-01-11')],
    };
    eq('series panel: acceptance 8, the removed days before the new start in one line', planView(course, ctx({ kind: 'editor', title: 'Pottery class' })).skipped.map((r) => r.line1),
      ['2 removed days before the new start are no longer needed.']);
    // Acceptance 10: a day at a time of its own after the change has its own row, which states that time.
    const study = {
      hash: 'a10', rule: rule('FREQ=WEEKLY;BYDAY=TU', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05T19:00:00-08:00', '2027-01-05T20:00:00-08:00'), bound: noEnd,
      counts: { events: null, edited: 3, skipped: 0 }, groups: [],
      days: [
        { key: '2027-01-20T03:00:00Z', newKey: '2027-01-20T04:00:00Z', kind: 'edited', title: 'Study group', outcome: 'stays', day: '2027-01-19',
          before: timedPlace('2027-01-19', '13:00', LA), after: timedPlace('2027-01-19', '13:00', LA), shows: '2027-01-19', to: '2027-01-19', at: '2027-01-19T13:00:00-08:00', kept: ['time'], takes: [] },
        { key: '2027-01-27T03:00:00Z', newKey: '2027-01-27T04:00:00Z', kind: 'edited', title: 'Study group', outcome: 'stays', day: '2027-01-26', ownDate: timedPlace('2027-01-22', '13:00', LA),
          before: timedPlace('2027-01-22', '13:00', LA), after: timedPlace('2027-01-22', '13:00', LA), shows: '2027-01-22', to: '2027-01-22', at: '2027-01-22T13:00:00-08:00', kept: ['date', 'time'], takes: [] },
        { key: '2027-02-03T03:00:00Z', newKey: '2027-02-03T04:00:00Z', kind: 'edited', title: 'Study group', outcome: 'moves', day: '2027-02-02',
          before: timedPlace('2027-02-02', '19:00', LA), after: timedPlace('2027-02-02', '20:00', LA), shows: '2027-02-02', to: '2027-02-02', at: '2027-02-02T20:00:00-08:00', kept: ['location'], takes: ['time'] },
      ],
    };
    eq('series panel: acceptance 10, an own time after the change is stated on its row, and a new time a day takes', planView({ ...study, zone: LA }, ctx({ kind: 'editor', title: 'Study group' })).edited.map((r) => r.line1), [
      "'Study group' on Tue, Jan 19 stays at 1:00 PM.",
      "'Study group' on Tue, Jan 26 was moved earlier to Fri, Jan 22 and stays at 1:00 PM.",
      "'Study group', with its own location, on Tue, Feb 2 moves to 8:00 PM.",
    ]);
  }

  // The other days: after the new end, going back to the series, deleted, a rename's own title.
  const piano = {
    hash: 'd1', rule: rule('FREQ=MONTHLY;BYMONTHDAY=31;COUNT=6', 'FREQ=MONTHLY;BYMONTHDAY=1;COUNT=6', '2027-01-31T16:00:00-08:00', '2027-02-01T16:00:00-08:00'), bound: noEnd,
    groups: [{ id: 'outside', day: '2027-11-01T00:00:00Z', selected: 'keep', count: 1, options: [{ id: 'keep' }, { id: 'delete' }] }],
    days: [
      { key: '2027-11-01T00:00:00Z', kind: 'edited', title: 'Recital at Sample Hall', outcome: 'separate', day: '2027-10-31', before: timedPlace('2027-10-31', '17:00', LA), after: timedPlace('2027-10-31', '17:00', LA),
        shows: '2027-10-31', to: '2027-10-31', kept: ['title'] },
      { key: '2027-03-17T02:00:00Z', newKey: '2027-03-18T02:00:00Z', kind: 'edited', title: 'Rehearsal', outcome: 'rejoins', day: '2027-03-16', before: timedPlace('2027-03-16', '19:00', LA), after: timedPlace('2027-03-17', '19:00', LA),
        shows: '2027-03-16', to: '2027-03-17', kept: [], takes: ['title'] },
      { key: '2027-05-01T00:00:00Z', kind: 'edited', title: 'Sample class', outcome: 'deleted', day: '2027-04-30', before: timedPlace('2027-04-30', '17:00', LA), shows: '2027-04-30', kept: ['title'] },
    ],
  };
  const p = planView(piano, ctx({ title: 'Piano lesson with Alex Example' }));
  eq('series panel: a day after the new end, a day going back to the series, a deleted day', p.edited.map((r) => [r.line1, r.choices[0] && r.choices[0].reason, r.choices[0] && r.choices[0].options.map((o) => o.label)]), [
    ["'Recital at Sample Hall' on Sun, Oct 31 falls after the new end.", 'Keep it as an event of its own, or delete it?', ['Keep it on its own', 'Delete it']],
    ["'Piano lesson with Alex Example' on Tue, Mar 16 goes back to the series; nothing of its own is left.", null, null],
    ["'Sample class' on Fri, Apr 30 is deleted.", null, null],
  ]);
  eq('series panel: every group goes into the commit', commitPlan(piano, { outside: 'delete' }).choices, { outside: 'delete' });
  const rename = planView({ ...choir, rule: rule('FREQ=WEEKLY;BYDAY=TU', 'FREQ=WEEKLY;BYDAY=TU', '2027-01-05T19:00:00-08:00', '2027-01-05T19:00:00-08:00'),
    groups: [{ id: 'ownTitle', selected: 'keep', field: 'title', count: 1, days: ['2027-04-14T02:00:00Z'], value: 'Chorus', options: [{ id: 'keep' }, { id: 'give' }] }],
    days: [{ key: '2027-04-14T02:00:00Z', newKey: '2027-04-14T02:00:00Z', kind: 'edited', title: 'Rehearsal', outcome: 'stays', day: '2027-04-13', before: at7('2027-04-13'), after: at7('2027-04-13'),
      shows: '2027-04-13', to: '2027-04-13', kept: ['title'], takes: [] }] },
  ctx({ kind: 'editor', edits: ['title'], newTitle: 'Chorus', fields: [{ label: 'Title', words: 'Choir, now Chorus' }] }));
  eq('series panel: a rename, its field, the rule as before, and the day with its own title asking', [rename.title, rename.fields, rename.change, rename.edited[0].line1, rename.edited[0].choices[0].reason, rename.edited[0].choices[0].options.map((o) => o.label)], [
    'Rename all events', ["Title from 'Choir' to 'Chorus'."], 'Still weekly on Tuesday at 7:00 PM, with no end, as before.',
    "'Rehearsal' on Tue, Apr 13 has its own title, 'Rehearsal'.", 'Keep its title, or give it the new one too?', ["Keep 'Rehearsal'", "Rename it to 'Chorus'"]]);
  eq('series panel: a resize is a change, a refused plan offers only this one', [planView({ hash: 'z' }, ctx({ kind: 'resize' })).action, planView({ hash: 'z', refusals: [{ code: 'weekno' }] }, ctx()).onlyThis],
    ['Change all events', 'Move only this one']);

  // Refusals and notes, by code.
  const gRefused = planView({ hash: 'e1', rule: rule('FREQ=WEEKLY;BYDAY=TU', 'FREQ=WEEKLY;BYDAY=WE', '2027-01-05T19:00:00-08:00', '2027-01-06T19:00:00-08:00'), refusals: [{ code: 'googleAllDay', words: 'Server words' }] }, ctx());
  eq('series panel: a refused change says why by its code, and can\'t be confirmed', [gRefused.refused, gRefused.canConfirm, gRefused.change],
    ['At Google, a day of an all-day series can’t have a time of its own, so this change can’t be made while an edited day keeps one. Give that day the series’ all-day setting first, or change only this one.', false, '']);
  const gNote = planView({ ...choir, notes: [{ kind: 'google', words: 'Server note' }] }, ctx());
  eq('series panel: the Google note says the change is sent to Google and read back, and can\'t be undone yet', gNote.notes, ['This change is sent to Google and read back from it: the calendar shows what Google stores. It can’t be undone yet.']);
  const gap = planView({ ...choir, notes: [{ kind: 'gap', date: '2027-03-14', clock: '02:30', at: '2027-03-14T03:30:00-07:00', tz: LA, words: 'Server note' }] }, ctx());
  eq('series panel: a note in the user\'s format', gap.notes, ['There is no 2:30 AM on Sun, Mar 14 in Los Angeles, so that one is at 3:30 AM.']);

  // Readability: a day with no title of its own carries the series' title.
  const untitled = planView({ ...choir, days: [{ key: '2026-11-04T03:00:00Z', newKey: '2026-11-05T03:00:00Z', kind: 'edited', title: 'Sample practice', outcome: 'moves', day: '2026-11-03', before: at7('2026-11-03'), after: at7('2026-11-04'), shows: '2026-11-03', to: '2026-11-04', kept: ['location'], takes: ['date'] }], groups: [] },
    ctx({ title: 'Sample practice', refYear: 2026 }));
  eq('series panel: a day with no title of its own carries the series\' title', untitled.edited[0].line1, "'Sample practice', with its own location, on Tue, Nov 3 moves to Wed, Nov 4.");
  const plain = planView({ ...choir, days: [{ key: '2026-11-04T03:00:00Z', newKey: '2026-11-05T03:00:00Z', kind: 'edited', title: 'Sample practice 1', outcome: 'moves', day: '2026-11-03', before: at7('2026-11-03'), after: at7('2026-11-04'), shows: '2026-11-03', to: '2026-11-04', kept: ['title'], takes: ['date'] }], groups: [] },
    ctx({ title: 'Sample practice', refYear: 2026 }));
  eq('series panel: a day with only its own title says nothing more of itself', plain.edited[0].line1, "'Sample practice 1' on Tue, Nov 3 moves to Wed, Nov 4.");
  const renamed = planView({ ...choir, days: [{ key: '2026-11-04T03:00:00Z', newKey: '2026-11-04T03:00:00Z', kind: 'edited', title: 'Sample practice', outcome: 'stays', day: '2026-11-03', before: at7('2026-11-03'), after: at7('2026-11-03'), shows: '2026-11-03', to: '2026-11-03', kept: ['location'], takes: ['title'] }], groups: [] },
    ctx({ title: 'Sample practice', refYear: 2026, kind: 'editor', edits: ['title'], newTitle: 'Sample rehearsal' }));
  eq('series panel: only a field that changes is said', renamed.edited[0].line1, "'Sample practice', with its own location, on Tue, Nov 3 stays where it is and is renamed to 'Sample rehearsal'.");
  const several = planView({ ...choir, groups: [{ id: 'movedDays', selected: 'keep', count: 2, options: [{ id: 'keep' }, { id: 'move' }] }] }, ctx());
  const severalChoice = several.edited.find((r) => (r.choices || []).some((c) => c.id === 'movedDays')).choices[0];
  eq('series panel: several days with their own dates give their reason first', [severalChoice.lead, severalChoice.reason, severalChoice.options.map((o) => o.label)],
    ['', 'These days were moved earlier. Keep them there, or move them with the rest?', ['Keep their dates', 'Move them with the rest']]);
  const labels = (g, r = gym.rule) => planView({ ...gym, rule: r, groups: [g] }, ctx()).readings[0].options.map((o) => o.label);
  eq('series panel: a new rule\'s readings', labels({ id: 'reading', selected: 'map', options: [{ id: 'map', rrule: 'FREQ=WEEKLY;BYDAY=TH' }, { id: 'keep', rrule: 'FREQ=WEEKLY;BYDAY=TH' }] }),
    ['Move them along with the pattern', 'Keep them where they are, as events of their own']);
  eq('series panel: one member moved by whole weeks is "only the Mondays"', labels({ id: 'reading', selected: 'member', options: [
    { id: 'member', rrule: 'FREQ=WEEKLY;BYDAY=MO,WE,FR', next: ['2027-03-15T07:00:00-07:00'] }, { id: 'pattern', rrule: 'FREQ=WEEKLY;BYDAY=MO,WE,FR', next: ['2027-03-15T07:00:00-07:00'] }] }),
    ['Move only the Mondays', 'Shift the whole weekly pattern']);
  const monthly = rule('FREQ=MONTHLY;BYMONTHDAY=1,15', 'FREQ=MONTHLY;BYMONTHDAY=1,15', '2027-01-01T07:00:00-08:00', '2027-01-01T07:00:00-08:00');
  eq('series panel: a month day moved by whole months is "only the 15th of the month", anything else "this day of the pattern"', [
    labels({ id: 'reading', selected: 'member', options: [{ id: 'member', rrule: 'FREQ=MONTHLY;BYMONTHDAY=1,15', next: ['2027-04-15T07:00:00-07:00'] }, { id: 'pattern', rrule: 'FREQ=MONTHLY;BYMONTHDAY=1,15' }] }, monthly)[0],
    labels({ id: 'reading', selected: 'member', options: [{ id: 'member', rrule: 'FREQ=YEARLY;BYMONTH=3,9;BYMONTHDAY=1', next: ['2028-03-01T07:00:00-08:00'] }, { id: 'pattern', rrule: 'FREQ=YEARLY;BYMONTH=3,9;BYMONTHDAY=1' }] })[0],
  ], ['Move only the 15th of the month', 'Move only this day of the pattern']);
  eq('series panel: a repeat several times a day says why it can\'t move as a series', planView({ hash: 's', refusals: [{ code: 'several' }] }, ctx()).refused,
    'This repeat happens several times a day, so its days and times can\u2019t be moved all at once. Move only this one, or change its details for all events.');

  // From the scenario set: the clock before a month-end clause, a start that moved, an nth weekday, a choice about several days.
  eq('series panel: the clock goes with the day, before a month-end clause', planView({ ...choir, rule: rule('FREQ=MONTHLY;BYMONTHDAY=31', 'FREQ=MONTHLY;BYMONTHDAY=30', '2027-01-31T18:00:00-08:00', '2027-03-30T18:00:00-07:00') }, ctx()).change,
    'From the 31st to the 30th each month, at 6:00 PM, skipping months without one, with no end, as before.');
  eq('series panel: the same rule starting on another day says where it starts now', planView({ ...choir, rule: rule('FREQ=MONTHLY;BYMONTHDAY=1;COUNT=6', 'FREQ=MONTHLY;BYMONTHDAY=1;COUNT=6', '2027-01-01T16:00:00-08:00', '2027-02-01T16:00:00-08:00'),
    bound: { kind: 'count', count: 6, events: 6, from: { kind: 'count', count: 6 } } }, ctx()).change, 'Starts Mon, Feb 1 (was Fri, Jan 1). Still monthly on the 1st at 4:00 PM, for 6 events, as before.');
  eq('series panel: an nth weekday moved reads as the days people name', labels({ id: 'reading', selected: 'member', options: [{ id: 'member', rrule: 'FREQ=MONTHLY;BYDAY=2WE,3MO' }, { id: 'pattern', rrule: 'FREQ=MONTHLY;BYDAY=1WE,3WE' }] },
    rule('FREQ=MONTHLY;BYDAY=1MO,3MO', 'FREQ=MONTHLY;BYDAY=2WE,3MO', '2026-11-02T19:00:00-08:00', '2026-11-02T19:00:00-08:00')), ['Move every first Monday to the second Wednesday', 'Shift the whole monthly pattern']);
  const twoTitles = planView({ ...choir, rule: rule('FREQ=WEEKLY;BYDAY=TU', 'FREQ=WEEKLY;BYDAY=TU', '2026-11-03T19:00:00-08:00', '2026-11-03T19:00:00-08:00'),
    groups: [{ id: 'ownTitle', selected: 'keep', field: 'title', count: 2, days: ['k1', 'k2'], value: 'Chorus', options: [{ id: 'keep' }, { id: 'give' }] }],
    days: [{ key: 'k1', kind: 'edited', title: 'Choir, extra rehearsal', outcome: 'stays', day: '2026-11-17', before: at7('2026-11-17'), after: at7('2026-11-17'), shows: '2026-11-17', to: '2026-11-17', kept: ['title'], takes: [] },
      { key: 'k2', kind: 'edited', title: 'Choir, concert', outcome: 'stays', day: '2026-12-15', before: at7('2026-12-15'), after: at7('2026-12-15'), shows: '2026-12-15', to: '2026-12-15', kept: ['title'], takes: [] }] },
  ctx({ kind: 'editor', edits: ['title'], newTitle: 'Chorus', refYear: 2026 }));
  eq('series panel: a choice about several days sits on their group row', [twoTitles.edited.length, twoTitles.edited[0].line1, twoTitles.edited[0].range, twoTitles.edited[0].choices[0].lead, twoTitles.edited[0].choices[0].reason, twoTitles.edited[0].choices[0].options.map((o) => o.label)],
    [1, "2 days have their own titles ('Choir, extra rehearsal', 'Choir, concert').", 'Nov 17 to Dec 15', '', 'Keep their titles, or give them the new one too?', ['Keep their own titles', "Rename them to 'Chorus'"]]);

  // The change sentence names only what changes.
  const changeOf = (before, after, sb, sa) => planView({ ...choir, rule: rule(before, after, sb, sa) }, ctx()).change;
  eq('series panel: only the clock changed', changeOf('FREQ=WEEKLY;BYDAY=MO', 'FREQ=WEEKLY;BYDAY=MO', '2026-11-09T09:00:00-08:00', '2026-11-09T10:00:00-08:00'), 'From 9:00 AM to 10:00 AM every Monday, with no end, as before.');
  eq('series panel: nth weekdays changed', changeOf('FREQ=MONTHLY;BYDAY=1MO,3MO', 'FREQ=MONTHLY;BYDAY=2WE,3MO', '2026-11-02T19:00:00-08:00', '2026-11-02T19:00:00-08:00'),
    'From the first and third Monday to the second Wednesday and third Monday each month, at 7:00 PM, with no end, as before.');
  eq('series panel: a new frequency gives both whole rules', changeOf('FREQ=WEEKLY;BYDAY=TU', 'FREQ=MONTHLY;BYMONTHDAY=15', '2027-01-05T19:00:00-08:00', '2027-01-15T19:00:00-08:00'),
    'From weekly on Tuesday at 7:00 PM to monthly on the 15th at 7:00 PM, with no end, as before.');
  // A reading's dates run until they differ from the other's; a change to earlier days is said.
  const apart = planView({ ...gym, groups: [{ id: 'reading', selected: 'skip', options: [
    { id: 'skip', rrule: 'FREQ=MONTHLY;BYMONTHDAY=30', dates: ['2027-03-30T18:00:00-07:00', '2027-04-30T18:00:00-07:00', '2027-05-30T18:00:00-07:00'], then: ['2028-01-30T18:00:00-08:00', '2028-03-30T18:00:00-07:00'], earlier: null },
    { id: 'clamped', rrule: 'FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1', dates: ['2027-03-30T18:00:00-07:00', '2027-04-30T18:00:00-07:00', '2027-05-30T18:00:00-07:00'], then: ['2028-01-30T18:00:00-08:00', '2028-02-29T18:00:00-08:00'], earlier: '2027-02-28' },
  ] }] }, ctx());
  eq('series panel: readings list their dates until they differ, and say when one changes earlier days', apart.readings[0].options.map((o) => [o.dates, o.note]), [
    ['Tue, Mar 30 · Fri, Apr 30 · Sun, May 30 … Sun, Jan 30, 2028 · Thu, Mar 30', ''],
    ['Tue, Mar 30 · Fri, Apr 30 · Sun, May 30 … Sun, Jan 30, 2028 · Tue, Feb 29', 'also changes earlier days, from Sun, Feb 28'],
  ]);
  const fourTitles = planView({ ...choir, groups: [{ id: 'ownTitle', selected: 'keep', field: 'title', count: 4, days: ['a', 'b', 'c', 'd'], value: 'Chorus', options: [{ id: 'keep' }, { id: 'give' }] }],
    days: ['a', 'b', 'c', 'd'].map((k, i) => ({ key: k, kind: 'edited', title: 'Choir ' + (i + 1), outcome: 'stays', day: fixtureKey(300 + i * 7), before: at7(fixtureKey(300 + i * 7)), after: at7(fixtureKey(300 + i * 7)), shows: fixtureKey(300 + i * 7), to: fixtureKey(300 + i * 7), kept: ['title'], takes: [] })) }, ctx());
  eq('series panel: a group with a choice names what its days have, three names and then a count', fourTitles.edited[0].line1, "4 days have their own titles ('Choir 1', 'Choir 2', 'Choir 3' and 1 more).");

  // Every reading that changes days before the day acted on says so, the preselected one included.
  const preEarlier = planView({ ...gym, groups: [{ id: 'reading', selected: 'member', options: [
    { id: 'member', rrule: 'FREQ=MONTHLY;BYDAY=2WE,3MO', dates: ['2026-12-09T19:00:00-08:00'], then: [], earlier: '2026-11-02' },
    { id: 'pattern', rrule: 'FREQ=MONTHLY;BYDAY=1WE,3WE', dates: ['2026-12-09T19:00:00-08:00'], then: [], earlier: '2026-11-02' }] }] }, ctx({ refYear: 2026 }));
  eq('series panel: the preselected reading says it changes earlier days too', preEarlier.readings[0].options.map((o) => o.note), ['also changes earlier days, from Mon, Nov 2', 'also changes earlier days, from Mon, Nov 2']);
  // The header's third line, when the edit started from a day with something of its own.
  const fromOwn = planView({ ...choir, acted: { key: 'k6', title: 'Sample practice 6', day: '2026-12-08', ownDate: at7('2026-12-11'), before: at7('2026-12-11'), shows: '2026-12-11', kept: ['date', 'title'] } }, ctx({ title: 'Sample practice', refYear: 2026 }));
  eq('series panel: started from a day with something of its own says so; from an ordinary day, nothing', [fromOwn.started, planView({ ...choir, acted: null }, ctx()).started,
    planView({ ...choir, acted: { key: 'k', title: 'Sample practice', day: '2026-12-11', before: at7('2026-12-11'), shows: '2026-12-11', kept: ['location'] } }, ctx({ title: 'Sample practice', refYear: 2026 })).started],
    ["Started from 'Sample practice 6' on Fri, Dec 11.", '', "Started from 'Sample practice' on Fri, Dec 11."]);
  // A change of the end or count alone says what stays.
  const sameRule = (a, b) => rule(a, b, '2027-01-01T16:00:00-08:00', '2027-01-01T16:00:00-08:00');
  const longer = planView({ ...choir, rule: sameRule('FREQ=MONTHLY;BYMONTHDAY=1;COUNT=6', 'FREQ=MONTHLY;BYMONTHDAY=1;COUNT=10'), bound: { kind: 'count', count: 10, events: 10, last: '2027-10-01', from: { kind: 'count', count: 6, events: 6 } },
    counts: { events: 10, edited: 0, skipped: 0 }, days: [] }, ctx());
  const later = planView({ ...choir, rule: sameRule('FREQ=MONTHLY;BYMONTHDAY=1;UNTIL=20271001T235959Z', 'FREQ=MONTHLY;BYMONTHDAY=1;UNTIL=20280701T235959Z'), bound: { kind: 'until', events: 19, last: '2028-07-01', from: { kind: 'until', events: 10, last: '2027-10-01' } },
    counts: { events: 19, edited: 0, skipped: 0 }, days: [] }, ctx({ refYear: 2027 }));
  eq('series panel: a longer count or a later end says what stays unchanged', [longer.affects, later.affects], [
    '10 events (was 6). The 6 events before the new ones are unchanged.', 'Ends Jul 1, 2028 (was Oct 1, 2027). The 10 events before it are unchanged.']);

  // Forty edited days: two rows of their own, then one row for the 38 that go with their events.
  const forty = planView(sampleEditedDays(), ctx({ title: 'Sample practice' }));
  eq('edited days grouped: the forty are two rows of their own and one group row', forty.edited.map((d) => [d.line1, d.line2, d.range || null]), [
    ["'Sample practice 6' on Tue, May 11 was moved earlier to Fri, May 14 and stays where it is.", '', null],
    ["'Sample practice 21' on Tue, Aug 24 becomes an event of its own.", '', null],
    ['38 days that were changed earlier move to Wednesday.', undefined, 'Apr 6 to Jan 4, 2028'],
  ]);
  eq('edited days grouped: the group\'s button, closed and open', [forty.edited[2].toggle(false), forty.edited[2].toggle(true)], ['Show 38 days', 'Hide']);
  eq('edited days grouped: opened, its days in the ordinary form, by date', forty.edited[2].days.slice(0, 2).map((d) => d.line1),
    ["'Sample practice 1', with its own title and location, on Tue, Apr 6 moves to Wed, Apr 7.", "'Sample practice 2', with its own title and location, on Tue, Apr 13 moves to Wed, Apr 14."]);
  const named = [...forty.edited.filter((d) => !d.days), ...forty.edited.flatMap((d) => d.days || [])];
  eq('edited days grouped: opened, all forty are named once', [named.length, new Set(named.map((d) => d.key)).size], [40, 40]);
  const mixed = planView(sampleEditedDays((i) => (i < 20 ? ['title', 'location'] : i < 39 ? ['title'] : ['location'])), ctx());
  eq('edited days grouped: by outcome alone, so days keeping other fields (a location of their own) join the group', mixed.edited.map((d) => [d.line1, d.days ? d.days.length : 0]), [
    ["'Sample practice 6' on Tue, May 11 was moved earlier to Fri, May 14 and stays where it is.", 0],
    ["'Sample practice 21' on Tue, Aug 24 becomes an event of its own.", 0],
    ['38 days that were changed earlier move to Wednesday.', 38],
  ]);
  eq('edited days grouped: the day with only its own location is among the group\'s rows', mixed.edited[2].days.map((d) => d.line1).slice(-1), ["'Choir', with its own location, on Tue, Jan 4, 2028 moves to Wed, Jan 5."]);
  const takesSplit = sampleEditedDays();
  takesSplit.days = takesSplit.days.map((d, i) => (d.outcome === 'moves' && i >= 30 ? { ...d, takes: ['date', 'reminders'] } : d));
  const split = planView(takesSplit, ctx());
  eq('edited days grouped: days taking other fields stay in the group; each row says what it takes', [split.edited.slice(2).map((d) => d.line1), split.edited[2].days.slice(-1).map((d) => d.line1)], [
    ['38 days that were changed earlier move to Wednesday.'],
    ["'Sample practice 40', with its own title and location, on Tue, Jan 4, 2028 moves to Wed, Jan 5 and takes the new reminders."],
  ]);
  const shifted = planView({ hash: 't2', days: ['2027-03-03', '2027-03-11', '2027-03-19'].map((k, n) => ({ key: k + 'T00:00:00Z', newKey: null, kind: 'edited', outcome: 'moves',
    day: k, before: datePlace(k), after: datePlace(fixtureKey(61 + n * 8 + 2)), shows: k, to: fixtureKey(61 + n * 8 + 2), kept: [], takes: ['date'] })) }, ctx());
  eq('edited days grouped: days landing on different weekdays say how far they move', shifted.edited.map((d) => [d.line1, d.range]), [['3 days that were changed earlier move 2 days later.', 'Mar 3 to Mar 19']]);
  const choiceDays = planView({ ...sampleEditedDays(), groups: sampleEditedDays().days.slice(0, 3).map((d, n) => ({ id: 'movedDays' + n, day: d.key, selected: 'keep', options: [{ id: 'keep', date: d.shows }, { id: 'move', date: d.to }] })) }, ctx());
  eq('edited days grouped: a day with a choice of its own is never folded into a group', [choiceDays.edited.slice(0, 3).map((d) => d.choices.length > 0), choiceDays.edited.find((d) => d.days).days.length], [[true, true, true], 35]);
  // The groups come from the plan's data, never its words.
  const reworded = sampleEditedDays();
  reworded.days = reworded.days.map((d, i) => ({ ...d, words: 'Reworded ' + 'x'.repeat(i), head: 'h' + i, outcomeWords: 'o' + i }));
  eq('edited days grouped: by outcome, kept and takes, whatever the words say', planView(reworded, ctx()).edited.map((d) => (d.days ? d.days.length : 0)), [0, 0, 38]);

  // Every group of a plan is drawn exactly once, by its own id: a day's row
  // lists every group about that day alone, and a group about several days
  // is on the row of those days, whatever else each of them does. The
  // commit sends what each drawn choice shows (1.5.0 review, F2).
  const choicesIn = (view) => [
    ...view.readings,
    ...[...view.edited, ...view.skipped].flatMap((r) => [...(r.choices || []), ...(r.days || []).flatMap((x) => x.choices || [])]),
  ];
  const ownCases = [
    ['one day with its own title and place', owned([ownDay('2027-01-12T18:00:00Z', '2027-01-12')]), [
      ["'Sample own title 12' on Tue, Jan 12 has its own title, 'Sample own title 12', and its own location.", ['ownTitle', 'ownLocation']]]],
    ['two days with their own titles and places', owned([ownDay('2027-01-12T18:00:00Z', '2027-01-12'), ownDay('2027-01-19T18:00:00Z', '2027-01-19')]), [
      ["2 days have their own titles ('Sample own title 12', 'Sample own title 19') and locations.", ['ownTitle', 'ownLocation']]]],
    ['two days, one of them at a date and time of its own', owned([
      ownDay('2027-01-12T18:00:00Z', '2027-01-14', { at: '2027-01-14T19:00:00+00:00', kept: ['date', 'time', 'title', 'location'] }), ownDay('2027-01-19T18:00:00Z', '2027-01-19')]), [
      ["'Sample own title 14', with its own title and location, on Tue, Jan 12 was moved earlier to Thu, Jan 14 and stays at 7:00 PM.", []],
      ["2 days have their own titles ('Sample own title 14', 'Sample own title 19') and locations.", ['ownTitle', 'ownLocation']]]],
  ];
  for (const [name, p, rows] of ownCases) {
    const ids = p.groups.map((g) => g.id);
    const view = planView(p, ctx({ kind: 'editor', title: 'Sample series' }));
    eq(`every choice drawn, ${name}: each group once, by its id`, choicesIn(view).map((c) => c.id).sort(), [...ids].sort());
    eq(`every choice drawn, ${name}: the rows, and the choices on each`, view.edited.map((r) => [r.line1, (r.choices || []).map((c) => c.id)]), rows);
    const picks = Object.fromEntries(ids.map((id) => [id, 'give']));
    const picked = planView(p, ctx({ kind: 'editor', title: 'Sample series', choices: picks }));
    eq(`every choice drawn, ${name}: each shows its pick, and the commit sends the picks`, [
      choicesIn(picked).map((c) => [c.id, c.options.find((o) => o.selected).id]).sort(), commitPlan(p, picks).choices,
    ], [ids.map((id) => [id, 'give']).sort(), picks]);
  }
  // A group about a day with no row of its own (the day acted on, moving with the drag) is still drawn.
  const actedOwn = { ...owned([]), groups: [{ id: 'ownTitle', field: 'title', count: 1, days: ['2027-01-12T18:00:00Z'], selected: 'keep', options: [{ id: 'keep' }, { id: 'give' }] }],
    days: [{ key: '2027-01-12T18:00:00Z', newKey: '2027-01-13T18:00:00Z', kind: 'acted', outcome: 'moves', day: '2027-01-12', before: { date: '2027-01-12', at: '2027-01-12T18:00:00+00:00' }, after: { date: '2027-01-13', at: '2027-01-13T18:00:00+00:00' }, shows: '2027-01-12', to: '2027-01-13', kept: ['title'], takes: [] }] };
  eq('every choice drawn: one about the day acted on, which has no row, is a row of its own', choicesIn(planView(actedOwn, ctx())).map((c) => c.id), ['ownTitle']);

  // Every date and time in the panel is in one zone, the plan's (the
  // series' after the change), never the device's or an occurrence's: a
  // New York series' day at its own 00:30 reads 12:30 AM on its New York
  // date wherever the panel is drawn (1.5.0 review, F4).
  const late = planView(sampleLateClass(), { kind: 'drag', title: 'Sample late class', scope: 'all', refYear: 2027 });
  eq('one zone: the dates and clocks of a New York plan are New York\'s, wherever it is drawn', [late.change, late.edited.map((r) => r.line1)], [
    'From Tuesday to Wednesday each week, at 1:30 AM, with no end, as before.', ["'Sample late class' on Tue, Jan 12 moves to Wed, Jan 13 at 12:30 AM."]]);

  // The zone rule: the plan names its zone, the series' after the change,
  // and null for a series all-day after it; a plan from an older server
  // names it in its rule.
  const { planZone } = await import('../src/lib/seriesplan.js');
  const W2 = await import('../src/lib/planwords.js');
  // A split's first line and delete following's change (series-model.md,
  // The Plan, 2, a split): what the events before the day acted on become.
  const splitPlan = { day: '2027-01-19', before: { bound: { kind: 'until', until: '20270112T180000Z', events: 2, last: '2027-01-12' } } };
  eq('split: the events before the day stay, now ending on the old part\'s last day, with its count; delete following deletes the day and every one after', [
    W2.splitLine(splitPlan, { refYear: 2027 }), W2.deleteSentence(splitPlan, { refYear: 2027 }), W2.splitLine(null), W2.titleWords(W2.verbOf('delete'), 'following'),
  ], ['Events before Tue, Jan 19 stay as they are, now ending Jan 12 (2 events).', 'Deletes Tue, Jan 19 and every event after it.', '', 'Delete this and following']);
  const laTo = { rule: { to: { tz: 'America/Los_Angeles', allDay: false } } };
  eq('zone rule: the plan\'s zone is plan.zone; an older plan\'s is its rule\'s; an all-day plan has none', [
    planZone({ ...laTo, zone: 'Asia/Tokyo' }), planZone(laTo), planZone({ ...laTo, zone: null }), planZone({ rule: { to: { tz: null, allDay: true } } }),
  ], ['Asia/Tokyo', 'America/Los_Angeles', null, null]);
  // An all-day series with a day at its own 20:00 in Los Angeles: its clock is
  // read in its own zone and said with the zone's name, wherever the panel is drawn.
  const evening = {
    hash: 'ad1', direct: false, zone: null,
    rule: { from: { rrule: 'FREQ=WEEKLY;BYDAY=TU', start: '2027-01-05', tz: null, allDay: true }, to: { rrule: 'FREQ=WEEKLY;BYDAY=TU', start: '2027-01-05', tz: null, allDay: true } },
    bound: { kind: 'none', events: null, last: null, from: { kind: 'none' } },
    next: [{ start: '2027-01-05T00:00:00+00:00' }, { start: '2027-01-12T20:00:00-08:00', tz: 'America/Los_Angeles', kind: 'edited' }, { start: '2027-01-19T00:00:00+00:00' }], nextMore: true,
    counts: { events: null, edited: 1, skipped: 0 }, groups: [], refusals: [], notes: [],
    days: [{ key: '2027-01-12T00:00:00Z', newKey: '2027-01-12T00:00:00Z', kind: 'edited', title: 'Sample practice', outcome: 'stays', day: '2027-01-12', before: timedPlace('2027-01-12', '20:00', LA, true), after: timedPlace('2027-01-12', '20:00', LA, true), shows: '2027-01-12', to: '2027-01-12',
      at: '2027-01-12T20:00:00-08:00', tz: 'America/Los_Angeles', kept: ['allDay', 'time'], takes: [] }],
  };
  const ev = planView(evening, { kind: 'editor', title: 'Sample practice', scope: 'all', refYear: 2027 });
  eq('zone rule: an all-day plan\'s timed day says its clock in its own zone, named, and coming up has its date there', [ev.edited.map((r) => r.line1), ev.comingUp], [
    ["'Sample practice', with a time of its own, on Tue, Jan 12 stays at 8:00 PM in Los Angeles."], 'Tue, Jan 5 · Tue, Jan 12 · Tue, Jan 19 …']);
  eq('zone rule: a value with its own zone is read there, before the plan\'s', [W2.fmtClock(evening.days[0]), W2.fmtClock(evening.days[0], 'Asia/Tokyo'), W2.fmtClock('2027-01-12T20:00:00-08:00', 'Asia/Tokyo')],
    ['8:00 PM', '8:00 PM', '1:00 PM']);
  let threw = null;
  try { W2.fmtClock('2027-01-12T20:00:00-08:00'); } catch (e) { threw = e.message; }
  eq('zone rule: under test a clock with no zone throws', threw, 'fmtClock: no zone for 2027-01-12T20:00:00-08:00');
  W2.setStrictClocks(false);
  const quiet = [W2.fmtClock('2027-01-12T20:00:00-08:00'), planView({ ...evening, days: [{ ...evening.days[0], tz: undefined, after: { ...evening.days[0].after, tz: undefined } }] }, { kind: 'editor', title: 'Sample practice', scope: 'all', refYear: 2027 }).edited.map((r) => r.line1)];
  W2.setStrictClocks(true);
  eq('zone rule: in the app a clock with no zone shows as unavailable, never guessed', quiet, ['time unavailable', ["'Sample practice', with a time of its own, on Tue, Jan 12 stays at time unavailable."]]);
  // A zone name the browser can't read (an import's zone the server accepts
  // and Intl doesn't) is no zone: its clock shows as unavailable under its
  // name, never the device's.
  const MARS = 'Mars/Olympus_Mons';
  const { fmtTimeInZone } = await import('../src/lib/dates.js');
  let unreadable = null;
  try { W2.fmtClock('2027-01-12T20:00:00-08:00', MARS); } catch (e) { unreadable = e.message; }
  eq('zone rule: under test a clock in a zone the browser can\'t read throws', [unreadable, (() => { try { return W2.fmtClock({ at: '2027-01-12T20:00:00-08:00', tz: MARS }, LA); } catch (e) { return e.message; } })()],
    ['fmtClock: unreadable zone ' + MARS + ' for 2027-01-12T20:00:00-08:00', 'fmtClock: unreadable zone ' + MARS + ' for 2027-01-12T20:00:00-08:00']);
  W2.setStrictClocks(false);
  const marsDay = { ...evening.days[0], tz: MARS, before: { ...evening.days[0].before, tz: MARS }, after: { ...evening.days[0].after, tz: MARS } };
  const marsQuiet = [W2.fmtClock('2027-01-12T20:00:00-08:00', MARS), W2.clockOf(marsDay.after), planView({ ...evening, days: [marsDay] }, { kind: 'editor', title: 'Sample practice', scope: 'all', refYear: 2027 }).edited.map((r) => r.line1)];
  W2.setStrictClocks(true);
  eq('zone rule: in the app a clock in a zone the browser can\'t read shows as unavailable, beside the zone\'s name', marsQuiet, ['time unavailable', 'time unavailable in Olympus Mons', ["'Sample practice', with a time of its own, on Tue, Jan 12 stays at time unavailable in Olympus Mons."]]);
  eq('zone rule: a clock for its zone\'s name is null for a zone the browser can\'t read', [fmtTimeInZone(new Date('2027-01-12T20:00:00-08:00'), MARS), fmtTimeInZone(new Date('2027-01-12T20:00:00-08:00'), null), fmtTimeInZone(new Date('2027-01-12T20:00:00-08:00'), LA)],
    [null, null, '8:00 PM']);

  // The verification set of 1.5.0's sentences (workfolder plan, The
  // client): plans shaped as the server writes them, read by the real
  // planView, each row's sentence asserted whole. Under test a clock with no
  // zone throws (setStrictClocks), so every check in an all-day plan below
  // also proves no clock was formatted without one.
  {
    const NY = 'America/New_York', TOKYO = 'Asia/Tokyo';
    const allDayPart = (rrule, start) => ({ rrule, start, tz: null, allDay: true });
    const timedPart = (rrule, start, tz) => ({ rrule, start, tz, allDay: false });
    const weekly = (tz, day = 'TU', clock = '19:00', start = '2027-01-05') => (tz ? timedPart('FREQ=WEEKLY;BYDAY=' + day, wallAt(start, clock, tz), tz) : allDayPart('FREQ=WEEKLY;BYDAY=' + day, start));
    // A plan of edited days in `zone` (null: all-day after the change), as the server sends it.
    const plan = (zone, days, extra = {}) => ({
      hash: 'verify', scope: 'all', direct: false, zone, rule: { from: weekly(zone), to: weekly(zone) }, bound: noEnd,
      next: [], nextMore: false, counts: { events: null, edited: days.length, skipped: 0 }, groups: [], refusals: [], notes: [], days, ...extra,
    });
    const view = (p, extra = {}) => planView(p, ctx({ kind: 'editor', title: 'Sample practice', refYear: 2027, ...extra }));
    const rows = (p, extra) => view(p, extra).edited.map((r) => r.line1);
    // An edited day about Tue, Jan 12 (its identity), keyed by the series' old 19:00 in Los Angeles.
    const day = (extra) => ({ key: '2027-01-13T03:00:00Z', newKey: '2027-01-13T03:00:00Z', kind: 'edited', title: 'Sample practice', day: '2027-01-12', kept: [], takes: [], ...extra });
    const at = (date, clock, tz, named = false) => timedPlace(date, clock, tz, named);
    const ad = (date, kind) => ({ ...datePlace(date), ...(kind ? { kind } : {}) });

    // A timed day in an all-day series: its clock in its own zone, named, whether it stays or moves.
    eq('verify: a timed day in an all-day series stays at its own clock, in its own zone, named', rows(plan(null, [
      day({ outcome: 'stays', before: at('2027-01-12', '20:00', LA, true), after: at('2027-01-12', '20:00', LA, true), kept: ['allDay', 'time'] })])),
    ["'Sample practice', with a time of its own, on Tue, Jan 12 stays at 8:00 PM in Los Angeles."]);
    eq('verify: a timed day in an all-day series moved a day says its new date and its own clock, named', rows(plan(null, [
      day({ outcome: 'moves', before: at('2027-01-12', '20:00', LA, true), after: at('2027-01-13', '20:00', LA, true), kept: ['allDay', 'time'], takes: ['date'] })])),
    ["'Sample practice', with a time of its own, on Tue, Jan 12 moves to Wed, Jan 13 at 8:00 PM in Los Angeles."]);

    // An all-day day in a timed series: a date, never a clock.
    eq('verify: an all-day day in a timed series moves to a date', rows(plan(LA, [
      day({ outcome: 'moves', before: ad('2027-01-12'), after: ad('2027-01-13'), kept: ['allDay'], takes: ['date'] })])),
    ["'Sample practice', all-day, on Tue, Jan 12 moves to Wed, Jan 13."]);

    // A series made all-day: a plain edited day and a day at its own time each become all-day.
    const madeAllDay = (extra) => day({ outcome: 'moves', before: at('2027-01-12', '19:00', LA, true), after: ad('2027-01-12', 'all-day'), takes: ['allDay'], ...extra });
    eq('verify: a series made all-day, a plain edited day becomes all-day', rows(plan(null, [madeAllDay({ title: 'Sample practice 2', kept: ['title'] })])),
      ["'Sample practice 2' on Tue, Jan 12 becomes all-day."]);
    eq('verify: a series made all-day, a day at its own time becomes all-day too', rows(plan(null, [
      madeAllDay({ before: at('2027-01-12', '21:30', LA, true), kept: ['location'] })])),
    ["'Sample practice', with its own location, on Tue, Jan 12 becomes all-day."]);
    eq('verify: a series made all-day and moved, the day says the date it becomes all-day on', rows(plan(null, [
      madeAllDay({ title: 'Sample practice 2', kept: ['title'], after: ad('2027-01-13', 'all-day'), takes: ['allDay', 'date'] })])),
    ["'Sample practice 2' on Tue, Jan 12 becomes all-day on Wed, Jan 13."]);
    const twoAllDay = view(plan(null, [madeAllDay({ title: 'Sample practice 2', kept: ['title'] }),
      madeAllDay({ key: '2027-01-20T03:00:00Z', day: '2027-01-19', before: at('2027-01-19', '21:30', LA, true), after: ad('2027-01-19', 'all-day'), kept: ['location'] })]));
    eq('verify: days that become all-day share a row that says so', [twoAllDay.edited.map((r) => [r.line1, r.range]), twoAllDay.edited[0].days.map((r) => r.line1)], [
      [['2 days that were changed earlier become all-day.', 'Jan 12 to Jan 19']],
      ["'Sample practice 2' on Tue, Jan 12 becomes all-day.", "'Sample practice', with its own location, on Tue, Jan 19 becomes all-day."]]);

    // A series made timed: a day all-day on its own stays a date; a day timed inside it keeps its instant.
    eq('verify: a series made timed, a day all-day on its own stays where it is', rows(plan(LA, [
      day({ outcome: 'stays', before: ad('2027-01-12'), after: ad('2027-01-12'), kept: ['allDay'] })])),
    ["'Sample practice', all-day, on Tue, Jan 12 stays where it is."]);
    eq('verify: a series made timed in Tokyo, a day at its own time inside it stays at its instant, on its Tokyo date', rows(plan(TOKYO, [
      day({ outcome: 'stays', before: at('2027-01-13', '13:00', TOKYO), after: at('2027-01-13', '13:00', TOKYO), kept: ['time'] })])),
    ["'Sample practice' on Tue, Jan 12 stays at 1:00 PM on Wed, Jan 13."]);
    const madeTimed = (extra) => day({ outcome: 'moves', title: 'Sample practice 2', kept: ['title'], before: ad('2027-01-12'), after: { ...at('2027-01-12', '18:00', LA), kind: 'timed' }, takes: ['allDay'], ...extra });
    eq('verify: a series made timed, a plain all-day day now starts at the series\' new time', rows(plan(LA, [madeTimed()])),
      ["'Sample practice 2' on Tue, Jan 12 now starts at 6:00 PM."]);
    const twoTimed = view(plan(LA, [madeTimed(), madeTimed({ key: '2027-01-20T03:00:00Z', day: '2027-01-19', before: ad('2027-01-19'), after: { ...at('2027-01-19', '18:00', LA), kind: 'timed' } })]));
    eq('verify: days made timed share a row that says when they now start', twoTimed.edited.map((r) => r.line1), ['2 days that were changed earlier now start at 6:00 PM.']);
    eq('verify: a made-timed day moved as well says its date too', rows(plan(LA, [madeTimed({ after: { ...at('2027-01-13', '18:00', LA), kind: 'timed' }, takes: ['allDay', 'date'] })])),
      ["'Sample practice 2' on Tue, Jan 12 now starts at 6:00 PM on Wed, Jan 13."]);
    // An all-day setting of its own reads by the day's kind, joined with the rest of what it has.
    eq('verify: an own all-day setting beside other own fields, timed and all-day, and with a trip', [
      rows(plan(null, [day({ outcome: 'stays', before: at('2027-01-12', '20:00', LA, true), after: at('2027-01-12', '20:00', LA, true), kept: ['allDay', 'time', 'location'] })])),
      rows(plan(LA, [day({ outcome: 'moves', before: ad('2027-01-12'), after: ad('2027-01-13'), kept: ['allDay', 'location'], takes: ['date'] })])),
      rows(plan(LA, [day({ outcome: 'moves', before: ad('2027-01-12'), after: ad('2027-01-13'), kept: ['allDay', 'location', 'trip'], takes: ['date'] })])),
    ], [
      ["'Sample practice', with its own location and a time of its own, on Tue, Jan 12 stays at 8:00 PM in Los Angeles."],
      ["'Sample practice', all-day and with its own location, on Tue, Jan 12 moves to Wed, Jan 13."],
      ["'Sample practice', all-day and with its own location and in a trip, on Tue, Jan 12 moves to Wed, Jan 13."],
    ]);

    // Midnight each way: a New York day at its own time under a Los Angeles series, and the reverse.
    eq('verify: a New York day at its own 00:30 under a Los Angeles series stays at its Los Angeles clock, on the evening before', rows(plan(LA, [
      day({ outcome: 'stays', before: at('2027-01-11', '21:30', LA), after: at('2027-01-11', '21:30', LA), kept: ['time'] })])),
    ["'Sample practice' on Tue, Jan 12 stays at 9:30 PM on Mon, Jan 11."]);
    eq('verify: a Los Angeles day at its own 22:00 under a New York series moves past midnight there', rows(plan(NY, [
      day({ key: '2027-01-13T01:00:00Z', outcome: 'moves', before: at('2027-01-13', '01:00', NY), after: at('2027-01-14', '01:00', NY), kept: ['time'], takes: ['date'] })])),
    ["'Sample practice' on Tue, Jan 12 moves to Thu, Jan 14 at 1:00 AM."]);
    eq('verify: a date set in another zone is named with that zone', rows(plan(LA, [
      day({ outcome: 'stays', ownDate: { date: '2027-01-14', at: '2027-01-14T00:30:00-05:00', tz: NY }, before: at('2027-01-13', '21:30', LA), after: at('2027-01-13', '21:30', LA), kept: ['date', 'time'] })])),
    ["'Sample practice' on Tue, Jan 12 was moved earlier to Thu, Jan 14 in New York and stays at 9:30 PM in Los Angeles on Wed, Jan 13."]);
    // A date is left out only against a date said in the same zone. A daily
    // 18:00 Los Angeles series; a day set earlier to Thu 14 Jan 10:00 in Tokyo
    // (Wed 13 Jan 17:00 in Los Angeles) moves a day later with the series, to
    // Thu 14 Jan 17:00 in Los Angeles: its new date there is said, and its
    // clock, after a date named in Tokyo, names Los Angeles.
    const daily = { rule: { from: timedPart('FREQ=DAILY', wallAt('2027-01-04', '18:00', LA), LA), to: timedPart('FREQ=DAILY', wallAt('2027-01-05', '18:00', LA), LA) } };
    const wed = (extra) => day({ key: '2027-01-14T02:00:00Z', day: '2027-01-13', outcome: 'moves', kept: ['date', 'time'], before: at('2027-01-13', '17:00', LA), ...extra });
    eq('verify: a date set in another zone is never compared with the plan\'s: the day says its new date and its clock names its zone', rows(plan(LA, [
      wed({ ownDate: { date: '2027-01-14', at: '2027-01-14T10:00:00+09:00', tz: TOKYO }, after: at('2027-01-14', '17:00', LA) })], daily)),
    ["'Sample practice' on Wed, Jan 13 was moved earlier to Thu, Jan 14 in Tokyo and moves to Thu, Jan 14 at 5:00 PM in Los Angeles."]);
    eq('verify: a date set in the plan\'s zone is still left out where the day keeps it', rows(plan(LA, [
      wed({ ownDate: { date: '2027-01-14', at: wallAt('2027-01-14', '17:00', LA) }, before: at('2027-01-14', '17:00', LA), after: at('2027-01-14', '20:00', LA) })], daily)),
    ["'Sample practice' on Wed, Jan 13 was moved earlier to Thu, Jan 14 and moves to 8:00 PM."]);
    // The day a row is about is the old series' day: under a series moved to
    // Tokyo, a Tuesday in Los Angeles is not compared with a Tuesday in Tokyo.
    // When it reads the same, the zone is why it is said, so its clock names the zone.
    eq('verify: in a zone change the day\'s date in the new zone is said, even when it reads the same, its clock naming the zone', rows(plan(TOKYO, [
      day({ outcome: 'moves', kept: ['time'], before: at('2027-01-13', '13:00', TOKYO), after: at('2027-01-12', '20:00', TOKYO) })], { rule: { from: weekly(LA), to: weekly(TOKYO) } })),
    ["'Sample practice' on Tue, Jan 12 moves to Tue, Jan 12 at 8:00 PM in Tokyo."]);
    eq('verify: in a zone change a day whose date changes there says its clock unlabelled', rows(plan(TOKYO, [
      day({ outcome: 'moves', kept: ['time'], before: at('2027-01-13', '13:00', TOKYO), after: at('2027-01-13', '20:00', TOKYO) })], { rule: { from: weekly(LA), to: weekly(TOKYO) } })),
    ["'Sample practice' on Tue, Jan 12 moves to Wed, Jan 13 at 8:00 PM."]);
    eq('verify: a date in no zone (all-day) is compared with any: a series made timed leaves the day\'s date out', rows(plan(LA, [
      madeTimed()], { rule: { from: weekly(null), to: weekly(LA) } })),
    ["'Sample practice 2' on Tue, Jan 12 now starts at 6:00 PM."]);

    // The day acted on, leaving the series: its after placement is the standalone row written.
    eq('verify: the day you moved, becoming an event of its own, is where the row is written', rows(plan(LA, [
      day({ kind: 'acted', newKey: null, title: undefined, outcome: 'separate', before: at('2027-01-12', '19:00', LA), after: at('2027-01-14', '18:00', LA) })])),
    ['The day you moved becomes an event of its own on Thu, Jan 14.']);
    eq('verify: an edited day acted on, becoming an event of its own, is named by its day', rows(plan(LA, [
      day({ newKey: null, title: 'Sample practice 3', kept: ['title'], outcome: 'separate', before: at('2027-01-12', '19:00', LA), after: at('2027-01-14', '18:00', LA) })])),
    ["'Sample practice 3' on Tue, Jan 12 becomes an event of its own on Thu, Jan 14."]);

    // Notes, each clock read in the note's own zone.
    eq('verify: notes name their zone and read their clocks there', view(plan(LA, [], { notes: [
      { kind: 'gap', date: '2027-03-14', clock: '02:30', at: '2027-03-14T03:30:00-07:00', tz: LA },
      { kind: 'overlap', date: '2027-11-07', at: '2027-11-07T01:30:00-07:00', tz: LA, fold: 'first', daylight: true },
      { kind: 'gap', date: '2027-03-28', clock: '02:30', at: '2027-03-28T03:30:00+02:00', tz: 'Europe/Berlin' },
    ] })).notes, [
      'There is no 2:30 AM on Sun, Mar 14 in Los Angeles, so that one is at 3:30 AM.',
      '1:30 AM happens twice on Sun, Nov 7 in Los Angeles; this is the first, in daylight time.',
      'There is no 2:30 AM on Sun, Mar 28 in Berlin, so that one is at 3:30 AM.',
    ]);
    // An overlap note's words come from which instant the series is at and
    // whether that one is in daylight time; what the note doesn't say is left out.
    eq('verify: an overlap note says which of the two the series is at, and its kind of time, only from its data', view(plan(LA, [], { notes: [
      { kind: 'overlap', date: '2027-10-31', at: '2027-10-31T02:30:00+01:00', tz: 'Europe/Berlin', fold: 'second', daylight: false },
      { kind: 'overlap', date: '2027-11-07', at: '2027-11-07T01:30:00-08:00', tz: LA, fold: 'second', daylight: null },
      { kind: 'overlap', date: '2027-11-07', at: '2027-11-07T01:30:00-07:00', tz: LA },
    ] })).notes, [
      '2:30 AM happens twice on Sun, Oct 31 in Berlin; this is the second, in standard time.',
      '1:30 AM happens twice on Sun, Nov 7 in Los Angeles; this is the second.',
      '1:30 AM happens twice on Sun, Nov 7 in Los Angeles.',
    ]);

    // A zone change: the zone choice's clocks in the zones it names, a reading's dates and Coming up in the plan's zone.
    const tokyo = view(plan(TOKYO, [], {
      rule: { from: weekly(LA, 'WE', '19:00', '2027-01-06'), to: weekly(TOKYO, 'TH', '12:00', '2027-01-07') },
      next: [{ start: '2027-03-11T12:00:00+09:00', allDay: false }, { start: '2027-03-18T12:00:00+09:00', allDay: false }, { start: '2027-03-25T12:00:00+09:00', allDay: false }], nextMore: true,
      groups: [
        { id: 'zone', from: LA, to: TOKYO, selected: 'moment', options: [
          { id: 'clock', start: '2027-01-06T19:00:00+09:00', next: [{ start: '2027-03-10T19:00:00+09:00', allDay: false }] },
          { id: 'moment', start: '2027-01-07T12:00:00+09:00', next: [{ start: '2027-03-11T12:00:00+09:00', allDay: false }] }] },
        { id: 'reading', selected: 'member', options: [
          { id: 'member', rrule: 'FREQ=WEEKLY;BYDAY=TH', dates: [{ start: '2027-03-11T07:00:00+09:00', allDay: false }, { start: '2027-03-18T07:00:00+09:00', allDay: false }] },
          { id: 'pattern', rrule: 'FREQ=WEEKLY;BYDAY=TH', dates: [{ start: '2027-03-11T07:00:00+09:00', allDay: false }, { start: '2027-03-19T07:00:00+09:00', allDay: false }] }] },
      ],
    }));
    eq('verify: a zone change, its choice\'s clocks in the zones it names, a reading\'s dates and Coming up on their Tokyo dates', [
      tokyo.readings.map((c) => c.options.map((o) => [o.label, o.dates])), tokyo.comingUp], [[
      [['Keep the clock time: 7:00 PM in Tokyo', 'Wed, Mar 10'], ['Keep the same moment: 7:00 PM in Los Angeles, 12:00 PM in Tokyo', 'Thu, Mar 11']],
      [['Move every Wednesday to Thursday', 'Thu, Mar 11 · Thu, Mar 18'], ['Shift the whole weekly pattern', 'Thu, Mar 11 · Fri, Mar 19']],
    ], 'Thu, Mar 11 · Thu, Mar 18 · Thu, Mar 25 …']);

    // A zone option's clocks are the series' start under it, never its next
    // list: here a weekly Tuesday 1:30 AM New York series whose 12 Jan day was
    // moved to 5 Jan at 3:30 AM lists that day first, at 12:30 AM in Los Angeles.
    {
      const NY = 'America/New_York';
      const zoneGroup = (options) => view(plan(LA, [], { rule: { from: weekly(NY, 'TU', '01:30', '2027-01-05'), to: weekly(LA, 'TU', '01:30', '2027-01-05') },
        groups: [{ id: 'zone', from: NY, to: LA, selected: 'clock', options }] })).readings[0].options.map((o) => [o.label, o.dates]);
      eq('verify: a zone option\'s clocks are the series\' start under it, not an edited day listed first', zoneGroup([
        { id: 'clock', start: '2027-01-05T01:30:00-08:00', next: [{ start: '2027-01-05T00:30:00-08:00', allDay: false }, { start: '2027-01-19T01:30:00-08:00', allDay: false }] },
        { id: 'moment', start: '2027-01-04T22:30:00-08:00', next: [{ start: '2027-01-05T00:30:00-08:00', allDay: false }, { start: '2027-01-18T22:30:00-08:00', allDay: false }] }]), [
        ['Keep the clock time: 1:30 AM in Los Angeles', 'Tue, Jan 5 · Tue, Jan 19'],
        ['Keep the same moment: 1:30 AM in New York, 10:30 PM in Los Angeles', 'Tue, Jan 5 · Mon, Jan 18']]);
      eq('verify: a zone option without a start (an older server) shows no clocks, its dates still from its list', zoneGroup([
        { id: 'clock', next: [{ start: '2027-01-12T01:30:00-08:00', allDay: false }] },
        { id: 'moment', next: [{ start: '2027-01-11T22:30:00-08:00', allDay: false }] }]), [
        ['Keep the clock time', 'Tue, Jan 12'], ['Keep the same moment', 'Mon, Jan 11']]);
    }

    // A clock that can't be said (in the app, a zone the browser can't read)
    // shows as unavailable in its place, so a preview missing a clock never
    // reads as complete, and never prints an empty or null clock.
    {
      const MARS = 'Mars/Olympus_Mons';
      W2.setStrictClocks(false);
      // An all-day series made timed in a zone the browser can't read.
      const mars = { rule: { from: weekly(null), to: weekly(LA) }, zone: MARS };
      const one = rows(plan(LA, [madeTimed()], mars));
      const moved = rows(plan(LA, [madeTimed({ after: { ...at('2027-01-13', '18:00', LA), kind: 'timed' }, takes: ['allDay', 'date'] })], mars));
      // Days made timed there share a row, its clock unavailable.
      const two = view(plan(LA, [madeTimed(), madeTimed({ key: '2027-01-20T03:00:00Z', day: '2027-01-19', before: ad('2027-01-19'), after: { ...at('2027-01-19', '18:00', LA), kind: 'timed' } })], mars)).edited.map((r) => r.line1);
      const group = W2.groupLines({ count: 2, outcome: 'timed', clock: null, first: '2027-01-12', last: '2027-01-19' }, { refYear: 2027 }).line1;
      const notes = view(plan(LA, [], { notes: [
        { kind: 'gap', date: '2027-03-14', clock: '02:30', at: '2027-03-14T03:30:00-07:00', tz: MARS },
        { kind: 'overlap', date: '2027-11-07', at: '2027-11-07T01:30:00-07:00', tz: MARS, fold: 'first', daylight: true },
      ] })).notes;
      const marsRule = { rrule: 'FREQ=WEEKLY;BYDAY=TU', start: '2027-01-05T19:00:00-08:00', tz: MARS, allDay: false };
      const rule = [W2.ruleWords(marsRule), W2.changeSentence({ from: weekly(LA), to: marsRule }, noEnd, { refYear: 2027 })];
      const zone = view(plan(LA, [], { groups: [{ id: 'zone', from: LA, to: MARS, selected: 'moment', options: [
        { id: 'clock', start: '2027-01-05T19:00:00-08:00', next: [{ start: '2027-03-10T19:00:00-08:00', allDay: false }] },
        { id: 'moment', start: '2027-01-05T19:00:00-08:00', next: [{ start: '2027-03-10T19:00:00-08:00', allDay: false }] }] }] })).readings.map((c) => c.options.map((o) => o.label));
      W2.setStrictClocks(true);
      // A group given no clock at all (groupLines' own input) still says only the kind.
      eq('verify: a clock that can\'t be said shows as unavailable: a day made timed, alone, moved and in a group', [one, moved, two, group], [
        ["'Sample practice 2' on Tue, Jan 12 now starts at time unavailable."],
        ["'Sample practice 2' on Tue, Jan 12 now starts at time unavailable on Wed, Jan 13."],
        ['2 days that were changed earlier now start at time unavailable.'],
        '2 days that were changed earlier are no longer all-day.']);
      eq('verify: a clock that can\'t be said shows as unavailable: notes, the rule and the zone choice, beside the zone\'s name', [notes, rule, zone], [
        ['A clock time is skipped on Sun, Mar 14 in Olympus Mons; its time is unavailable.', 'A clock time happens twice on Sun, Nov 7 in Olympus Mons; its time is unavailable; this is the first, in daylight time.'],
        ['weekly on Tuesday at time unavailable', 'From weekly on Tuesday at 7:00 PM in Los Angeles to weekly on Tuesday at time unavailable in Olympus Mons, with no end, as before.'],
        [['Keep the clock time: time unavailable in Olympus Mons', 'Keep the same moment: 7:00 PM in Los Angeles, time unavailable in Olympus Mons']]]);
    }

    // A group other than the zone, whose options agree at first and then diverge, in an all-day plan.
    const entry = (date) => ({ start: date + 'T00:00:00+00:00', allDay: true });
    const diverge = view(plan(null, [], { rule: { from: allDayPart('FREQ=MONTHLY;BYMONTHDAY=30', '2027-01-30'), to: allDayPart('FREQ=MONTHLY;BYMONTHDAY=30', '2027-01-30') }, groups: [{ id: 'reading', selected: 'skip', options: [
      { id: 'skip', rrule: 'FREQ=MONTHLY;BYMONTHDAY=30', dates: ['2027-03-30', '2027-04-30', '2027-05-30'].map(entry), then: ['2028-01-30', '2028-03-30'].map(entry), earlier: null },
      { id: 'clamped', rrule: 'FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1', dates: ['2027-03-30', '2027-04-30', '2027-05-30'].map(entry), then: ['2028-01-30', '2028-02-29'].map(entry), earlier: '2027-02-28', earlierSeries: 'new' },
    ] }] }));
    eq('verify: options that agree at first and then differ list their dates to where they part, in an all-day plan', diverge.readings[0].options.map((o) => [o.label, o.dates, o.note]), [
      ['Monthly on the 30th, skipping months without one', 'Tue, Mar 30 · Fri, Apr 30 · Sun, May 30 … Sun, Jan 30, 2028 · Thu, Mar 30', ''],
      ['Monthly on the 30th, or the last day in shorter months', 'Tue, Mar 30 · Fri, Apr 30 · Sun, May 30 … Sun, Jan 30, 2028 · Tue, Feb 29', 'also changes earlier days, from Sun, Feb 28'],
    ]);

    // Clocks the zone rules bend: a day in the spring gap, and both instants of the repeated hour.
    const sunday = (after) => day({ key: '2027-03-14T10:30:00Z', day: '2027-03-14', outcome: 'stays', before: after, after, kept: ['time'] });
    eq('verify: a day at its own 2:30 on the spring-forward day shows at 3:30', rows(plan(LA, [sunday({ date: '2027-03-14', at: '2027-03-14T03:30:00-07:00' })])),
      ["'Sample practice' on Sun, Mar 14 stays at 3:30 AM."]);
    const fold = (offset) => rows(plan(LA, [{ ...sunday({ date: '2027-11-07', at: '2027-11-07T01:30:00' + offset }), day: '2027-11-07' }]));
    eq('verify: both instants of the repeated hour read 1:30, the daylight one and the standard one', [fold('-07:00'), fold('-08:00')],
      [["'Sample practice' on Sun, Nov 7 stays at 1:30 AM."], ["'Sample practice' on Sun, Nov 7 stays at 1:30 AM."]]);

    // A timed plan's group of days taking the series' new time says that time.
    const newTime = (key, date) => day({ key, day: date, outcome: 'moves', kept: ['location'], takes: ['time'], before: at(date, '19:00', LA), after: at(date, '20:00', LA) });
    eq('verify: days taking the series\' new time share a row that names it', rows(plan(LA, [newTime('2027-01-13T03:00:00Z', '2027-01-12'), newTime('2027-01-20T03:00:00Z', '2027-01-19')])),
      ['2 days that were changed earlier move to 8:00 PM.']);

    // Every sentence path in an all-day plan, so none of them formats a clock without a zone.
    const outsideGroup = (key) => ({ id: 'outside', day: key, selected: 'keep', count: 1, options: [{ id: 'keep' }, { id: 'delete' }] });
    const paths = [
      ['an edited day that moves', [day({ outcome: 'moves', before: ad('2027-01-12'), after: ad('2027-01-13'), kept: ['location'], takes: ['date'] })], {},
        ["'Sample practice', with its own location, on Tue, Jan 12 moves to Wed, Jan 13."]],
      ['an edited day that stays and takes a change', [day({ outcome: 'stays', before: ad('2027-01-12'), after: ad('2027-01-12'), kept: ['location'], takes: ['title', 'reminders'] })], { newTitle: 'Sample rehearsal' },
        ["'Sample practice', with its own location, on Tue, Jan 12 stays where it is and is renamed to 'Sample rehearsal' and takes the new reminders."]],
      ['a day with a date of its own that stays', [day({ outcome: 'stays', ownDate: ad('2027-01-15'), before: ad('2027-01-15'), after: ad('2027-01-15'), kept: ['date'] })], {},
        ["'Sample practice' on Tue, Jan 12 was moved earlier to Fri, Jan 15 and stays where it is."]],
      ['a day with a date of its own that moves and takes a change', [day({ outcome: 'moves', ownDate: ad('2027-01-15'), before: ad('2027-01-15'), after: ad('2027-01-16'), kept: ['date'], takes: ['reminders'] })], {},
        ["'Sample practice' on Tue, Jan 12 was moved earlier to Fri, Jan 15 and moves to Sat, Jan 16, and takes the new reminders."]],
      ['a timed day that stays', [day({ outcome: 'stays', before: at('2027-01-12', '20:00', LA, true), after: at('2027-01-12', '20:00', LA, true), kept: ['allDay', 'time'] })], {},
        ["'Sample practice', with a time of its own, on Tue, Jan 12 stays at 8:00 PM in Los Angeles."]],
      ['a timed day with a date of its own', [day({ outcome: 'stays', ownDate: at('2027-01-14', '20:00', LA, true), before: at('2027-01-14', '20:00', LA, true), after: at('2027-01-14', '20:00', LA, true), kept: ['date', 'allDay', 'time'] })], {},
        ["'Sample practice', with a time of its own, on Tue, Jan 12 was moved earlier to Thu, Jan 14 and stays at 8:00 PM in Los Angeles."]],
      ['a day that becomes all-day', [madeAllDay({ title: 'Sample practice 2', kept: ['title'] })], {}, ["'Sample practice 2' on Tue, Jan 12 becomes all-day."]],
      ['a day that becomes an event of its own', [day({ title: 'Sample practice 4', kept: ['title'], newKey: null, outcome: 'separate', before: ad('2027-01-12'), after: ad('2027-01-12') })], {},
        ["'Sample practice 4' on Tue, Jan 12 becomes an event of its own."]],
      ['a deleted day', [day({ title: 'Sample practice 5', kept: ['title'], newKey: null, outcome: 'deleted', before: ad('2027-01-12') })], {}, ["'Sample practice 5' on Tue, Jan 12 is deleted."]],
      ['a day going back to the series', [day({ outcome: 'rejoins', before: ad('2027-01-12'), after: ad('2027-01-13'), takes: ['title'] })], {},
        ["'Sample practice' on Tue, Jan 12 goes back to the series; nothing of its own is left."]],
      ['the day you moved, leaving the series', [day({ kind: 'acted', newKey: null, title: undefined, outcome: 'separate', before: ad('2027-01-12'), after: ad('2027-01-14') })], {},
        ['The day you moved becomes an event of its own on Thu, Jan 14.']],
      ['a day after the new end, with its choice', [day({ title: 'Sample practice 4', kept: ['title'], newKey: null, outcome: 'separate', before: ad('2027-01-12'), after: ad('2027-01-12') })],
        { groups: [outsideGroup('2027-01-13T03:00:00Z')] }, ["'Sample practice 4' on Tue, Jan 12 falls after the new end."]],
      ['a day with its own title, with its choice', [day({ title: 'Sample practice 6', kept: ['title'], outcome: 'stays', before: ad('2027-01-12'), after: ad('2027-01-12') })],
        { groups: [{ id: 'ownTitle', field: 'title', count: 1, days: ['2027-01-13T03:00:00Z'], value: 'Sample rehearsal', selected: 'keep', options: [{ id: 'keep' }, { id: 'give' }] }] },
        ["'Sample practice 6' on Tue, Jan 12 has its own title, 'Sample practice 6'."]],
      ['days that move together', ['2027-01-12', '2027-01-19'].map((d, i) => day({ key: 'k' + i, day: d, outcome: 'moves', before: ad(d), after: ad(d.slice(0, 8) + String(Number(d.slice(8)) + 1)), kept: ['location'], takes: ['date'] })), {},
        ['2 days that were changed earlier move to Wednesday.']],
      ['days that stay together', ['2027-01-12', '2027-01-19'].map((d, i) => day({ key: 'k' + i, day: d, outcome: 'stays', before: ad(d), after: ad(d), kept: ['location'] })), {},
        ['2 days that were changed earlier stay where they are.']],
      ['days a choice is about together', ['2027-01-12', '2027-01-19'].map((d, i) => day({ key: 'k' + i, day: d, title: 'Sample practice ' + (7 + i), outcome: 'stays', before: ad(d), after: ad(d), kept: ['title'] })),
        { groups: [{ id: 'ownTitle', field: 'title', count: 2, days: ['k0', 'k1'], value: 'Sample rehearsal', selected: 'keep', options: [{ id: 'keep' }, { id: 'give' }] }] },
        ["2 days have their own titles ('Sample practice 7', 'Sample practice 8')."]],
    ];
    for (const [name, days, extra, expected] of paths) {
      const { newTitle, ...rest } = extra;
      eq('verify, all-day plan: ' + name, rows(plan(null, days, rest), newTitle ? { newTitle } : {}), expected);
    }
    const whole = view(plan(null, [day({ outcome: 'moves', ownDate: ad('2027-01-15'), before: ad('2027-01-15'), after: ad('2027-01-16'), kept: ['date'] })], {
      rule: { from: allDayPart('FREQ=WEEKLY;BYDAY=TU', '2027-01-05'), to: allDayPart('FREQ=WEEKLY;BYDAY=WE', '2027-01-06') },
      bound: { kind: 'until', until: '20270331', events: 12, from: { kind: 'none' } }, counts: { events: 12, edited: 1, skipped: 1 },
      next: [entry('2027-01-13'), { start: '2027-01-13T20:00:00-08:00', allDay: false, tz: LA }, entry('2027-01-20')], nextMore: true,
      acted: { key: '2027-01-13T03:00:00Z', title: 'Sample practice 6', day: '2027-01-12', ownDate: ad('2027-01-15'), before: ad('2027-01-15'), shows: '2027-01-15', kept: ['date', 'title'] },
      groups: [{ id: 'movedDays', day: '2027-01-13T03:00:00Z', selected: 'move', count: 1, options: [{ id: 'keep', date: '2027-01-15' }, { id: 'move', date: '2027-01-16' }] }],
    }));
    eq('verify, all-day plan: the change, coming up, affects, where the edit started and a day\'s choice, with no clock', [whole.change, whole.comingUp, whole.affects, whole.started,
      whole.edited.map((r) => [r.line1, r.choices[0].reason, r.choices[0].options.map((o) => o.label)])], [
      'From Tuesday to Wednesday each week, ending Mar 31.', 'Wed, Jan 13 · Wed, Jan 13 · Wed, Jan 20 …', '12 events, ending Mar 31. 1 was changed earlier (date), 1 was removed.',
      "Started from 'Sample practice 6' on Fri, Jan 15.",
      [["'Sample practice' on Tue, Jan 12 was moved earlier to Fri, Jan 15 and moves to Sat, Jan 16.", 'Keep it there, or move it with the rest?', ['Keep it on Fri, Jan 15', 'Move it to Sat, Jan 16']]],
    ]);
    eq('verify, all-day plan: a removed day leads with its day', view(plan(null, [{ key: '2027-01-26T00:00:00Z', newKey: '2027-01-27T00:00:00Z', kind: 'skipped', outcome: 'moves', shows: '2027-01-26', to: '2027-01-27', kept: [], takes: [] }])).skipped.map((r) => r.line1),
      ["'Sample practice' on Tue, Jan 26 was removed earlier, so no event is added on Wed, Jan 27."]);
  }

  // Creating a series: quick add's line and the editor's Repeat row.
  const created = { rule: { words: 'Monthly on the 30th, or the last day' }, start: '2027-01-30T19:00:00-08:00', next: ['2027-01-30T19:00:00-08:00', '2027-02-28T19:00:00-08:00', '2027-03-30T19:00:00-07:00'], bound: { words: '6 events' } };
  eq('creation line: the rule, the first three dates, the bound', creationLine(created, { typedStart: '2027-01-30', refYear: 2027 }), { line: 'Monthly on the 30th, or the last day · Sat 30 Jan, Sun 28 Feb, Tue 30 Mar · 6 events', moved: '' });
  eq('creation line: a start moved to the rule\'s first day says so', creationLine({ ...created, start: '2027-03-02T09:00:00-08:00', startWords: 'Starts Tue 2 Mar, the first Tuesday on or after the date you typed' }, { typedStart: '2027-02-27', refYear: 2027 }).moved,
    'Starts Tue 2 Mar, the first Tuesday on or after the date you typed');
  eq('creation line: without the server\'s words, a plain one', creationLine({ ...created, start: '2027-03-02T09:00:00-08:00' }, { typedStart: '2027-02-27', refYear: 2027 }).moved,
    'Starts Tue 2 Mar, its first day on or after the date you typed');
  eq('creation line: none without a plan', creationLine(null), null);
  const { parsedSeries } = await import('../src/lib/seriesplan.js');
  const draft = { title: 'Sample book club', start: '2027-01-30T19:00:00-08:00', rrule: 'FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1;COUNT=6', series: created };
  const range = { start: '2027-01-30T19:00:00-08:00', end: '2027-01-30T21:00:00-08:00' };
  eq('quick add: the parse\'s own plan while the repeat and date are the parse\'s', parsedSeries(draft, draft.rrule, range), created);
  eq('quick add: and its words under the chips', creationLine(parsedSeries(draft, draft.rrule, range), { typedStart: range.start, refYear: 2027 }).line,
    'Monthly on the 30th, or the last day · Sat 30 Jan, Sun 28 Feb, Tue 30 Mar · 6 events');
  eq('quick add: a repeat picked from the menu asks for a new plan', parsedSeries(draft, 'FREQ=MONTHLY;BYMONTHDAY=30', range), null);
  eq('quick add: so does a new date', parsedSeries(draft, draft.rrule, { ...range, start: '2027-02-01T19:00:00-08:00' }), null);
  eq('quick add: no plan in the parse, none to show', parsedSeries({ ...draft, series: undefined }, draft.rrule, range), null);
}

console.log('--- the series panel, docked, open while the window crosses the phone width (1.5.0) ---');
{
  // The panel drawn by Preact into a small stand-in DOM and kept open while
  // the window goes from desktop to phone width and back: the same element,
  // its edited days still opened, and nothing written on it for any layout
  // (app.css places and sizes it).
  const kebab = (k) => k.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase());
  const fakeStyle = () => {
    const props = new Map();
    const own = {
      get cssText() { return [...props].map(([k, v]) => k + ': ' + v + ';').join(' '); },
      set cssText(text) {
        props.clear();
        for (const part of String(text).split(';')) {
          const i = part.indexOf(':');
          if (i > 0) props.set(part.slice(0, i).trim(), part.slice(i + 1).trim());
        }
      },
      setProperty(k, v) { if (v === '' || v == null) props.delete(k); else props.set(k, String(v)); },
      removeProperty(k) { props.delete(k); },
      getPropertyValue(k) { return props.get(k) || ''; },
    };
    return new Proxy(own, {
      get: (t, k) => (k in t ? t[k] : typeof k === 'string' ? props.get(kebab(k)) || '' : undefined),
      set: (t, k, v) => { if (k === 'cssText') t.cssText = v; else own.setProperty(kebab(k), v); return true; },
    });
  };
  let fakeDoc = null;
  class FakeNode {
    constructor(nodeType, name = '') {
      this.nodeType = nodeType; this.localName = name; this.namespaceURI = 'http://www.w3.org/1999/xhtml';
      this.childNodes = []; this.parentNode = null; this.attrs = new Map(); this.listeners = {};
      if (nodeType === 1) this.style = fakeStyle();
    }
    get ownerDocument() { return fakeDoc; }
    get firstChild() { return this.childNodes[0] || null; }
    get nextSibling() { const p = this.parentNode; return p ? p.childNodes[p.childNodes.indexOf(this) + 1] || null : null; }
    get textContent() { return this.nodeType === 3 ? String(this.data) : this.childNodes.map((c) => c.textContent).join(''); }
    get attributes() { return [...this.attrs].map(([name, value]) => ({ name, value })); }
    get classes() { return (this.attrs.get('class') || '').split(/\s+/).filter(Boolean); }
    insertBefore(node, ref) {
      if (node.parentNode) node.parentNode.removeChild(node);
      const i = ref ? this.childNodes.indexOf(ref) : -1;
      if (i < 0) this.childNodes.push(node); else this.childNodes.splice(i, 0, node);
      node.parentNode = this;
      return node;
    }
    appendChild(node) { return this.insertBefore(node, null); }
    removeChild(node) { this.childNodes = this.childNodes.filter((c) => c !== node); node.parentNode = null; return node; }
    remove() { if (this.parentNode) this.parentNode.removeChild(this); }
    setAttribute(k, v) { this.attrs.set(k, String(v)); }
    getAttribute(k) { return this.attrs.has(k) ? this.attrs.get(k) : null; }
    removeAttribute(k) { this.attrs.delete(k); }
    addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
    removeEventListener(type, fn) { this.listeners[type] = (this.listeners[type] || []).filter((f) => f !== fn); }
    focus() {}
    contains(n) { for (; n; n = n.parentNode) if (n === this) return true; return false; }
    matches(sel) { return sel.split(',').map((s) => s.trim()).some((s) => (s[0] === '.' ? this.classes.includes(s.slice(1)) : this.localName === s)); }
    closest(sel) { for (let n = this; n && n.nodeType === 1; n = n.parentNode) if (n.matches(sel)) return n; return null; }
    querySelectorAll(sel) {
      const out = [];
      const walk = (n) => { for (const c of n.childNodes) if (c.nodeType === 1) { if (c.matches(sel)) out.push(c); walk(c); } };
      walk(this);
      return out;
    }
    // Drawn 520 wide and as tall as its limit lets it: forty edited days
    // opened are taller than any window here.
    getBoundingClientRect() {
      const h = parseFloat(this.style.maxHeight) || 400;
      return { left: 0, top: 0, width: 520, height: h, right: 520, bottom: h };
    }
    click() { for (const fn of [...(this.listeners.click || [])]) fn.call(this, { type: 'click', target: this, currentTarget: this }); }
  }
  fakeDoc = {
    body: new FakeNode(1, 'body'),
    createElementNS: (ns, name) => new FakeNode(1, name),
    createElement: (name) => new FakeNode(1, name),
    createTextNode: (data) => { const t = new FakeNode(3); t.data = data; return t; },
    addEventListener() {}, removeEventListener() {},
    querySelectorAll: (sel) => fakeDoc.body.querySelectorAll(sel),
  };
  const { PHONE_QUERY } = await import('../src/lib/breakpoints.js');
  const win = {
    innerWidth: 1280, innerHeight: 800, listeners: {}, location: { href: 'http://app.test/' },
    addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); },
    removeEventListener(type, fn) { this.listeners[type] = (this.listeners[type] || []).filter((f) => f !== fn); },
    matchMedia: (q) => ({ matches: q === PHONE_QUERY && win.innerWidth <= 640, addEventListener() {}, removeEventListener() {} }),
  };
  const hadWindow = 'window' in globalThis, savedWindow = globalThis.window;
  const hadDocument = 'document' in globalThis, savedDocument = globalThis.document;
  globalThis.window = win;
  globalThis.document = fakeDoc;
  // Renders and effects run when the queues they wait in are drained.
  const { options } = await import('../vendor/preact.module.js');
  const savedRaf = options.requestAnimationFrame, savedDebounce = options.debounceRendering;
  options.requestAnimationFrame = (cb) => setImmediate(cb);
  options.debounceRendering = (cb) => Promise.resolve().then(cb);
  const settle = async () => { for (let i = 0; i < 6; i++) await new Promise((r) => setImmediate(r)); };
  const { render, html } = await import('../vendor/index.js');
  const { SeriesDialog } = await import('../src/app/SeriesDialog.js');
  const { set: put, state: st } = await import('../src/app/store.js');
  const resize = async (w, h) => {
    win.innerWidth = w; win.innerHeight = h;
    for (const fn of [...(win.listeners.resize || [])]) fn({ type: 'resize' });
    await settle();
  };
  const plan = sampleEditedDays();
  let opened = 0;
  const open = async (host) => {
    opened++;
    put({ seriesChange: {
      id: 9000 + opened, kind: host === 'editor' ? 'editor' : 'drag',
      occ: { instanceId: 'sample-' + opened, allDay: false }, body: { start: '2027-03-10T19:00:00-08:00' },
      at: host === 'editor' ? null : { x: 640, y: 300 }, before: null, apply: null, onDone: null, fields: [],
      title: 'Sample practice', google: false, scope: 'all',
      plan, status: 'ready', choices: {}, changed: false, hidden: false,
    } });
    const box = new FakeNode(1, 'div');
    fakeDoc.body.appendChild(box);
    render(html`<${SeriesDialog} host=${host} />`, box);
    await settle();
    return box;
  };
  const panel = (box) => box.firstChild.firstChild; // .bc-sd, in its lane
  // The rows of the Edited block, and the group's button.
  const editedRows = (box) => box.querySelectorAll('.bc-sd-block').find((b) => b.firstChild && b.firstChild.textContent === 'Edited days');
  const topRows = (box) => editedRows(box).childNodes[1].childNodes.filter((n) => n.classes.includes('bc-sd-row'));
  const toggle = (box) => box.querySelectorAll('.bc-sd-toggle')[0];
  const openDays = (box) => box.querySelectorAll('.bc-sd-days').flatMap((n) => n.childNodes).length;
  const seen = (box) => ({ cls: box.firstChild.getAttribute('class') + ' > ' + panel(box).getAttribute('class'), style: box.firstChild.style.cssText + panel(box).style.cssText, rows: topRows(box).length, opened: openDays(box) });
  const close = async (box) => { render(null, box); box.remove(); put({ seriesChange: null }); await settle(); };
  const docked = { cls: 'bc-sd-lane > bc-sd', style: '', rows: 3, opened: 0 };

  for (const host of ['editor', 'calendar']) {
    await resize(1280, 800);
    const box = await open(host);
    const el = panel(box);
    eq(`series panel, ${host}: forty edited days as two rows of their own and one group row, closed, with no style of its own`, seen(box), docked);
    eq(`series panel, ${host}: the group row's lines and its button`, [
      topRows(box)[2].childNodes[0].textContent, topRows(box)[2].childNodes[1].textContent,
      toggle(box).localName, toggle(box).textContent, toggle(box).getAttribute('aria-expanded'),
    ], ['38 days that were changed earlier move to Wednesday.', 'Apr 6, 2027 to Jan 4, 2028 · Show 38 days', 'button', 'Show 38 days', 'false']);
    eq(`series panel, ${host}: a day's choice is its radios, each on a line of its own`,
      topRows(box)[0].querySelectorAll('.bc-sd-opt').map((n) => n.textContent), ['Keep it on Fri, May 14, 2027', 'Move it to Sat, May 15, 2027']);
    // The panel writes every sentence itself: none of the server's words for the sample plan shows.
    const text = panel(box).textContent;
    const serverWords = [plan.rule.wordsBefore, plan.rule.words, plan.bound.words, plan.summary,
      ...plan.days.flatMap((d) => [d.words, d.head, d.outcomeWords]), ...plan.groups.flatMap((g) => [g.label, ...g.options.map((o) => o.label)])].filter(Boolean);
    eq(`series panel, ${host}: none of the server's words shows`, serverWords.filter((w) => text.includes(w)), []);
    toggle(box).click();
    await settle();
    eq(`series panel, ${host}: "Show 38 days" opens the days under the group as rows`, [openDays(box), toggle(box).textContent, toggle(box).getAttribute('aria-expanded')], [38, 'Hide', 'true']);
    // Its place is app.css's: a window crossing the phone width changes nothing on the element.
    for (const [w, h] of [[412, 915], [1400, 900], [1280, 800]]) {
      await resize(w, h);
      eq(`series panel, ${host}: at ${w} x ${h} the same element, its class and its (empty) style unchanged, the group still open`,
        [panel(box) === el, seen(box)], [true, { ...docked, opened: 38 }]);
    }
    toggle(box).click();
    await settle();
    eq(`series panel, ${host}: "Hide" folds the group again`, [openDays(box), toggle(box).textContent], [0, 'Show 38 days']);
    eq(`series panel, ${host}: the header is the title, the series and Change scope; the action names the change`, [
      box.querySelectorAll('.bc-sd-head')[0].textContent, box.querySelectorAll('.bc-sd-actions')[0].textContent,
    ], [(host === 'editor' ? 'Change all events' : 'Move all events') + "Change scope'Sample practice'", 'Cancel' + (host === 'editor' ? 'Change all events' : 'Move all events')]);
    // Every time in the panel follows the user's 12- or 24-hour setting.
    const { setTimeFormat: setClock } = await import('../src/lib/dates.js');
    const times = async (tf) => { setClock(tf); put({ seriesChange: { ...st.seriesChange, choices: { ...st.seriesChange.choices } } }); await settle(); return panel(box).textContent; };
    const twelve = await times('12'), full = await times('24');
    eq(`series panel, ${host}: every time follows the 12- or 24-hour setting`, [/7:00\sPM/.test(twelve), /19:00/.test(twelve), /19:00/.test(full), /\b(AM|PM)\b/.test(full)], [true, false, true, false]);
    setClock('12');
    await close(box);
  }
  // Through the real panel and its flow: every group's radios are drawn,
  // once each; picking every one asks again with the picks, and Confirm
  // commits them (1.5.0 review, F2). Then the zone: a drag from a window
  // occurrence (no zone of its own) and an editor save (the full record,
  // with its zone) both read a New York plan in New York (F4).
  {
    const realFetch = globalThis.fetch;
    const { openSeriesChange, cancelSeriesChange: cancelChange } = await import('../src/app/serieschange.js');
    const reply = (body) => new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
    const sentNow = [];
    let planNow = null;
    globalThis.fetch = async (url, o = {}) => {
      const p = new URL(String(url), 'http://app.test').pathname.replace('/api/v1', '');
      if (p === '/events/81' && o.method === 'PATCH') { const b = JSON.parse(o.body); sentNow.push(b); return reply(b.preview ? { plan: planNow } : { ok: true }); }
      return reply(p === '/changes/cursor' ? { cursor: 'p1' } : p === '/events' ? { events: [] } : {});
    };
    const savedConfig = st.config;
    put({ config: { ...st.config, seriesPlans: true }, calendars: [{ id: 3, name: 'Sample', editable: true }], loadedRanges: [], visibleDays: null });
    const fire = (node, type) => { for (const fn of [...((node && node.listeners[type]) || [])]) fn.call(node, { type, target: node, currentTarget: node }); };
    const mount = async (host) => { const box = new FakeNode(1, 'div'); fakeDoc.body.appendChild(box); render(html`<${SeriesDialog} host=${host} />`, box); await settle(); return box; };
    const unmount = async (box) => { cancelChange(); render(null, box); box.remove(); await settle(); };
    const occOf = (extra = {}) => ({ instanceId: '81@1', eventId: 81, calendarId: 3, title: 'Sample series', recurring: true, start: '2027-01-05T18:00:00+00:00', end: '2027-01-05T19:00:00+00:00', allDay: false, ...extra });
    const radios = (box) => box.querySelectorAll('input').map((n) => n.getAttribute('name'));
    for (const [name, days] of [
      ['one day with two owned fields', [ownDay('2027-01-12T18:00:00Z', '2027-01-12')]],
      ['two days', [ownDay('2027-01-12T18:00:00Z', '2027-01-12'), ownDay('2027-01-19T18:00:00Z', '2027-01-19')]],
      ['two days, one timed on its own', [ownDay('2027-01-12T18:00:00Z', '2027-01-14', { at: '2027-01-14T19:00:00+00:00', kept: ['date', 'time', 'title', 'location'] }), ownDay('2027-01-19T18:00:00Z', '2027-01-19')]],
    ]) {
      planNow = owned(days);
      sentNow.length = 0;
      openSeriesChange({ kind: 'editor', occ: occOf({ tzid: 'UTC' }), body: { title: 'Sample renamed series', location: 'Sample Annex' }, quiet: true });
      await settle();
      const box = await mount('editor');
      eq(`panel, ${name}: two radios for each group, by its id, and no other`, radios(box), ['sd-ownTitle', 'sd-ownTitle', 'sd-ownLocation', 'sd-ownLocation']);
      for (const id of ['ownTitle', 'ownLocation']) {
        fire(box.querySelectorAll('input').filter((n) => n.getAttribute('name') === 'sd-' + id)[1], 'change');
        await settle();
      }
      box.querySelectorAll('.bc-btn-primary')[0].click();
      await settle();
      eq(`panel, ${name}: each pick asked again with it, and Confirm committed both`, sentNow.map((b) => (b.preview ? (b.plan ? b.plan.choices : {}) : ['commit', b.plan.choices])), [
        {}, { ownTitle: 'give' }, { ownTitle: 'give', ownLocation: 'give' }, ['commit', { ownTitle: 'give', ownLocation: 'give' }]]);
      await unmount(box);
    }
    const lateText = async (kind, occ) => {
      planNow = sampleLateClass();
      openSeriesChange({ kind, occ, body: kind === 'drag' ? { start: '2027-01-20T01:30:00-05:00', end: '2027-01-20T02:30:00-05:00' } : { title: 'Sample later class' }, quiet: kind === 'editor' });
      await settle();
      const box = await mount(kind === 'editor' ? 'editor' : 'calendar');
      const text = box.textContent;
      await unmount(box);
      return [/at 1:30 AM, with no end/.test(text), /moves to Wed, Jan 13 at 12:30 AM\./.test(text)];
    };
    eq('panel zone: a drag from a window occurrence, which carries no zone, reads the New York plan in New York', await lateText('drag', occOf({ title: 'Sample late class', start: '2027-01-19T01:30:00-05:00' })), [true, true]);
    eq('panel zone: an editor save, from the record with its zone, the same', await lateText('editor', occOf({ title: 'Sample late class', start: '2027-01-19T01:30:00-05:00', tzid: 'America/New_York' })), [true, true]);
    put({ config: savedConfig, seriesChange: null });
    globalThis.fetch = realFetch;
  }

  // Where the panel sits and how tall it may be are app.css's alone, chosen
  // by the width it has: its lane is the window less the editor drawer or
  // the event panel beside it; it docks at the lane's bottom right where
  // the lane holds it at its full width, else it is the sheet across the
  // bottom of the window, as on a phone (1.5.0 review, F5).
  const { readFileSync: readCss } = await import('node:fs');
  const css = readCss(new URL('../styles/app.css', import.meta.url), 'utf8');
  const rule = (sel, from = 0) => { const i = css.indexOf('\n' + sel + ' {', from); return i < 0 ? '' : css.slice(i, css.indexOf('}', i)); };
  const sd = rule('.bc-sd'), lane = rule('.bc-sd-lane');
  eq('series panel css: the lane is the window less what lies beside it on the right, and a container', [
    /position: fixed/.test(lane), /top: 0; bottom: 0; left: 0; right: var\(--bc-sd-side, 0px\)/.test(lane), /container: bc-sd-lane \/ inline-size/.test(lane), /pointer-events: none/.test(lane),
  ], [true, true, true, true]);
  eq('series panel css: docked at the lane\'s bottom right, 480 wide, no taller than the window less a margin', [
    /position: absolute; right: 12px; bottom: 12px; width: 480px; max-height: calc\(100% - 24px\)/.test(sd), /pointer-events: auto/.test(sd),
  ], [true, true]);
  eq('series panel css: the editor drawer and the event panel say how far they reach', [
    /\.bc-drawer \{ --bc-sd-side: min\(480px, 94vw\); \}/.test(css), /\.bc-app:has\(\.bc-evpanel\) \{ --bc-sd-side: min\(480px, 100vw\); \}/.test(css),
  ], [true, true]);
  const sheetAt = css.indexOf('@container bc-sd-lane (max-width: ');
  const sheet = sheetAt < 0 ? '' : css.slice(sheetAt, css.indexOf('}', sheetAt));
  const limit = sheetAt < 0 ? NaN : parseFloat(css.slice(sheetAt + '@container bc-sd-lane (max-width: '.length));
  const full = 480 + 2 * 12;
  eq('series panel css: the sheet wherever the lane is narrower than the panel at its full width, from the lane\'s left to the window\'s right edge', [
    limit < full && limit > full - 1, /left: 0; right: calc\(0px - var\(--bc-sd-side, 0px\)\); bottom: 0; width: auto/.test(sheet), /max-height: 66dvh/.test(sheet),
  ], [true, true, true]);
  const phoneAt = css.indexOf('@media (max-width: 640px), (pointer: coarse) and (max-height: 500px) and (max-width: 1000px) {\n  .bc-sd-lane { --bc-sd-side: 100vw; }');
  eq('series panel css: a phone has no room to dock in, so its lane is no width and the panel the sheet', phoneAt > 0, true);
  // Those rules at the widths around the drawer: the lane, then where the
  // panel lands. Its body is within the window at its full width, and its
  // actions (never scrolled) on screen.
  const sideOf = (sel) => { const m = /min\((\d+)px, (\d+)vw\)/.exec(rule(sel)); return (w) => Math.min(+m[1], (+m[2] * w) / 100); };
  const hosts = { editor: sideOf('.bc-drawer'), 'event panel': sideOf('.bc-app:has(.bc-evpanel)') };
  const place = (w, h, side) => {
    const laneWidth = w <= 640 ? 0 : Math.max(0, w - side(w));
    if (laneWidth <= limit) return { as: 'sheet', left: 0, right: w, bottom: h };
    return { as: 'docked', left: w - side(w) - 12 - 480, right: w - side(w) - 12, bottom: h - 12 };
  };
  for (const [host, side] of Object.entries(hosts)) {
    for (const [w, as] of [[641, 'sheet'], [700, 'sheet'], [900, 'sheet'], [1024, 'docked'], [1280, 'docked']]) {
      const at = place(w, 800, side);
      eq(`series panel beside the ${host} at ${w} x 800: ${as}, its body within the window at its full width, its actions on screen`, [
        at.as, at.left >= 0 && at.right <= w, at.right - at.left >= 480, at.bottom <= 800,
      ], [as, true, true, true]);
    }
  }
  // The renderer owns the panel's style: the component writes none itself,
  // so no layout can leave one behind for another.
  const { readFileSync: readSource } = await import('node:fs');
  const dialogSource = readSource(new URL('../src/app/SeriesDialog.js', import.meta.url), 'utf8').replace(/\/\/.*$/gm, '');
  eq('series panel: SeriesDialog.js writes no inline style of its own and measures nothing', /\.style\s*(\.|\[|=)|setProperty|cssText|getBoundingClientRect|innerWidth|innerHeight/.test(dialogSource), false);

  if (savedRaf === undefined) delete options.requestAnimationFrame; else options.requestAnimationFrame = savedRaf;
  if (savedDebounce === undefined) delete options.debounceRendering; else options.debounceRendering = savedDebounce;
  if (hadWindow) globalThis.window = savedWindow; else delete globalThis.window;
  if (hadDocument) globalThis.document = savedDocument; else delete globalThis.document;
}

console.log('--- the series change flow: a drag, its plan, the commit (1.5.0) ---');
{
  const realFetch = globalThis.fetch;
  const { seriesDrop } = await import('../src/app/actions.js');
  const { pickSeriesOption, confirmSeriesChange, cancelSeriesChange, onlyThisOne, changeSeriesScope } = await import('../src/app/serieschange.js');
  const { planView } = await import('../src/lib/seriesplan.js');
  const { state: st, set: put } = await import('../src/app/store.js');
  const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  const tick = async (n = 10) => { for (let i = 0; i < n; i++) await new Promise((r) => setTimeout(r, 0)); };
  const sent = [];
  let answer = null; // (body) => Response for PATCH /events/41
  globalThis.fetch = async (url, o = {}) => {
    const u = new URL(String(url), 'http://app.test');
    const p = u.pathname.replace('/api/v1', '');
    if (p === '/events/41' && o.method === 'PATCH') {
      const body = JSON.parse(o.body);
      sent.push(body);
      return answer(body);
    }
    if (p === '/changes/cursor') return json({ cursor: 's1' });
    if (p === '/events') return json({ events: [] });
    return json({});
  };
  let n = 0;
  const occ = {
    instanceId: '41@0', eventId: 41, calendarId: 3, title: 'Sample choir', recurring: true, rrule: 'FREQ=WEEKLY;BYDAY=TU',
    tzid: 'America/Los_Angeles', start: '2027-03-09T19:00:00-08:00', end: '2027-03-09T20:30:00-08:00', allDay: false,
  };
  const reset = () => {
    put({ config: { ...st.config, seriesPlans: true }, seriesChange: null, calendars: [{ id: 3, name: 'Sample', provider: null, editable: true }], loadedRanges: [], visibleDays: null, popover: null, editor: null });
    st.occ.clear();
    // A fresh occurrence each time: a landed save holds its own until an answer carries it.
    occ.instanceId = '41@' + ++n;
    drop.instanceId = occ.instanceId;
    st.occ.set(occ.instanceId, { ...occ });
    sent.length = 0;
  };
  const drop = { instanceId: occ.instanceId, newStart: '2027-03-10T19:00:00-08:00', newEnd: '2027-03-10T20:30:00-08:00', at: { x: 300, y: 200 } };
  const groupPlan = (hash, selected = 'member') => ({
    hash, direct: false,
    rule: { before: 'FREQ=WEEKLY;BYDAY=MO,WE,FR', after: 'FREQ=WEEKLY;BYDAY=MO,TH,FR', wordsBefore: 'Weekly on Monday, Wednesday and Friday at 07:00', words: 'Weekly on Monday, Thursday and Friday at 07:00' },
    groups: [{ id: 'rule', selected, options: [
      { id: 'member', label: 'Move every Wednesday to Thursday', next: ['2027-03-11T07:00:00-08:00'] },
      { id: 'pattern', label: 'Shift the whole weekly pattern', next: ['2027-03-11T07:00:00-08:00'] },
    ] }],
    days: [], refusals: [], notes: [],
  });

  // 1. All events: the dialog opens at once, the block pending where dropped,
  // and the preview is the drag's fields with preview: true.
  reset();
  let gate;
  answer = (body) => (body.preview ? new Promise((r) => { gate = () => r(json({ plan: groupPlan('h1') })); }) : json({ ok: true }));
  seriesDrop('drag', st.occ.get(occ.instanceId), drop, 'all');
  await tick();
  const shown = () => st.occ.get(occ.instanceId);
  eq('series drag: the block waits where it was dropped, drawn pending', [shown().start, shown().seriesPending], [drop.newStart, true]);
  eq('series drag: the dialog is up, working out the plan, confirm off', [st.seriesChange && st.seriesChange.status, planView(st.seriesChange.plan, {}).canConfirm], ['loading', false]);
  eq('series drag: the preview asks with the drag\'s fields', sent[0], { start: drop.newStart, end: drop.newEnd, scope: 'all', instanceStart: occ.start, preview: true });
  eq('series drag: confirm before the plan sends nothing', [await confirmSeriesChange(), sent.length], [false, 1]);
  gate();
  await tick();
  eq('series drag: the plan arrives and the dialog is ready', [st.seriesChange.status, st.seriesChange.plan.hash], ['ready', 'h1']);

  // 2. Picking another reading asks again with the choice.
  answer = (body) => json({ plan: groupPlan('h2', body.plan && body.plan.choices.rule) });
  pickSeriesOption('rule', 'pattern');
  eq('series drag: a pick asks for the plan again, confirm off meanwhile', st.seriesChange.status, 'refreshing');
  await tick();
  eq('series drag: the new preview carries the pick', sent[1].plan, { choices: { rule: 'pattern' } });
  eq('series drag: and the plan follows it', [st.seriesChange.plan.hash, st.seriesChange.status], ['h2', 'ready']);

  // 3. The series changed meanwhile: 409 with the new plan, asked again.
  answer = (body) => (body.plan && body.plan.hash === 'h2'
    ? json({ error: { code: 'series_plan_changed', message: 'The series changed', plan: groupPlan('h3', 'pattern') } }, 409)
    : json({ ok: true }));
  await confirmSeriesChange();
  eq('series drag: the commit names the hash and every group', sent[2].plan, { hash: 'h2', choices: { rule: 'pattern' } });
  eq('series drag: a stale hash brings the new plan and asks again', [st.seriesChange && st.seriesChange.plan.hash, st.seriesChange && st.seriesChange.changed, shown().seriesPending], ['h3', true, true]);
  eq('series drag: the panel says the series changed', planView(st.seriesChange.plan, { changed: st.seriesChange.changed }).changed, 'The series changed since this was worked out. Here is the new plan.');
  const toastsBefore = st.toasts.length;
  await confirmSeriesChange();
  await tick();
  eq('series drag: the commit with the new hash lands', [sent[3].plan.hash, st.seriesChange], ['h3', null]);
  eq('series drag: the block is drawn solid where it was dropped', [shown().start, !!shown().seriesPending], [drop.newStart, false]);
  eq('series drag: the toast stays short', st.toasts.slice(toastsBefore).map((t) => [t.title, t.text]), [['Sample choir', 'Moved all events']]);
  answer = () => json({ plan: groupPlan('h9') });
  seriesDrop('drag', shown(), { ...drop, newStart: '2027-03-11T19:00:00-08:00', newEnd: '2027-03-11T20:30:00-08:00' }, 'all');
  await tick();
  cancelSeriesChange();
  eq('series drag: a second drag cancelled before the refresh goes back to the landed one, solid', [shown().start, !!shown().seriesPending], [drop.newStart, false]);

  // 4. Cancel puts the block back and sends nothing more.
  reset();
  answer = () => json({ plan: groupPlan('h4') });
  seriesDrop('drag', st.occ.get(occ.instanceId), drop, 'all');
  await tick();
  cancelSeriesChange();
  eq('series drag: cancel puts the block back, nothing written', [shown().start, !!shown().seriesPending, st.seriesChange, sent.length], [occ.start, false, null, 1]);

  // 5. Refused: the reasons, no confirm, nothing written.
  reset();
  answer = () => json({ error: { code: 'series_refused', message: 'Refused', plan: { hash: 'r1', refusals: [{ code: 'weekno', words: 'Server words' }] } } }, 422);
  seriesDrop('drag', st.occ.get(occ.instanceId), drop, 'all');
  await tick();
  const refusedView = planView(st.seriesChange.plan, {});
  eq('series drag: a refusal shows its reason by its code, with no confirm', [refusedView.refused, refusedView.canConfirm], ['This repeat counts weeks of the year, so its days can\u2019t follow a drag.', false]);
  eq('series drag: and confirm sends nothing', [await confirmSeriesChange(), sent.length], [false, 1]);

  // 6. "Only this one" from the dialog: the old path with scope this.
  answer = (body) => json({ ok: true, scope: body.scope });
  onlyThisOne();
  await tick();
  eq('series drag: only this one moves the one day', [st.seriesChange, sent[1].scope, sent[1].plan, sent[1].preview, shown().start], [null, 'this', undefined, undefined, drop.newStart]);

  // 7. Any other error: the block goes back with the error toast.
  reset();
  answer = () => json({ error: { code: 'server', message: 'Down for a moment' } }, 500);
  const before7 = st.toasts.length;
  seriesDrop('drag', st.occ.get(occ.instanceId), drop, 'all');
  await tick();
  eq('series drag: a failed preview puts the block back and says why', [st.seriesChange, shown().start, st.toasts.slice(before7).map((t) => t.text)], [null, occ.start, ['Move failed: Down for a moment']]);

  // 8. "This event" acts at once, as before, with no dialog; resize the same way.
  reset();
  answer = () => json({ ok: true });
  await seriesDrop('drag', st.occ.get(occ.instanceId), drop, 'this');
  eq('series drag: this event moves at once', [st.seriesChange, sent.length, sent[0].scope, sent[0].preview], [null, 1, 'this', undefined]);
  reset();
  answer = (body) => (body.preview ? json({ plan: { ...groupPlan('z1'), groups: [] } }) : json({ ok: true }));
  seriesDrop('resize', st.occ.get(occ.instanceId), { ...drop, newStart: occ.start }, 'all');
  await tick();
  eq('series resize: through the dialog too, named for what it does', [st.seriesChange.kind, planView(st.seriesChange.plan, { kind: 'resize' }).action, sent[0].preview], ['resize', 'Change all events', true]);
  cancelSeriesChange();

  // 9. "Change scope": the preview cancelled, the block back where it was,
  // and the scope chooser asked again; nothing more is sent.
  reset();
  answer = () => json({ plan: groupPlan('c1') });
  let asked = 0;
  seriesDrop('drag', st.occ.get(occ.instanceId), drop, 'all', () => { asked++; });
  await tick();
  changeSeriesScope();
  eq('series drag: Change scope cancels the preview, puts the block back and asks the scope again', [st.seriesChange, shown().start, !!shown().seriesPending, asked, sent.length], [null, occ.start, false, 1, 1]);

  put({ seriesChange: null });
  st.occ.clear();
  globalThis.fetch = realFetch;
}

console.log('--- 1.5.1: adoption readings, typed values, one field table, quick add\'s other reading ---');
{
  const { planView } = await import('../src/lib/seriesplan.js');
  const { EDIT_FIELDS, changedFields, fieldLines, editField } = await import('../src/lib/editfields.js');
  const { typedRange, otherReadingWords, takeOtherReading, assumedFor } = await import('../src/lib/quickrange.js');
  const nx = (s) => ({ start: s, allDay: false });
  const base = {
    hash: 'a1', zone: 'America/Los_Angeles', rule: { from: { rrule: 'FREQ=WEEKLY;BYDAY=TU', tz: 'America/Los_Angeles', allDay: false }, to: { rrule: 'FREQ=WEEKLY;BYDAY=FR', start: '2027-01-08T19:00:00-08:00', tz: 'America/Los_Angeles', allDay: false } },
    bound: { kind: 'none' }, next: [], days: [], refusals: [], notes: [],
  };
  // Case 13: the day group, Friday adopted first, the same way a day later.
  const day = planView({ ...base, groups: [{ id: 'day', selected: 'adopt', shift: 1, by: 3, options: [
    { id: 'adopt', rrule: 'FREQ=WEEKLY;BYDAY=FR', next: [nx('2027-03-12T19:00:00-08:00')] },
    { id: 'same', rrule: 'FREQ=WEEKLY;BYDAY=WE', next: [nx('2027-03-12T19:00:00-08:00')] }] }] }, { kind: 'drag', title: 'Choir', refYear: 2027 }).readings[0];
  eq('adoption: the day\'s question and its two readings, as a person reads them', [day.reason, day.options.map((o) => o.label), day.options[0].selected], [
    'This day was moved earlier to a date of its own. Should its new day become the series’ day, or every event move the same way?',
    ['Friday becomes its day: weekly on Friday', 'Every event moves a day later: weekly on Wednesday'], true]);
  // Case 14: the time group: 20:00 adopted, or half an hour later and this one at 20:00.
  const time = planView({ ...base, groups: [{ id: 'time', selected: 'adopt', shift: 1800, options: [
    { id: 'adopt', start: '2027-01-05T20:00:00-08:00' }, { id: 'same', start: '2027-01-05T19:30:00-08:00' }] }] }, { kind: 'drag', title: 'Choir', refYear: 2027 }).readings[0];
  eq('adoption: the time\'s readings', time.options.map((o) => o.label.replace(/\s/g, ' ')),
    ['8:00 PM becomes its time: every event at 8:00 PM', 'Every event moves half an hour later: 7:30 PM, this one at 8:00 PM']);
  // A length typed: two hours becomes its length, or every event 30 minutes longer.
  const len = planView({ ...base, groups: [{ id: 'length', selected: 'adopt', shift: 1800, length: 7200, options: [{ id: 'adopt' }, { id: 'same' }] }] }, { kind: 'editor', title: 'Choir' }).readings[0];
  eq('adoption: the length\'s readings', len.options.map((o) => o.label), ['2 hours becomes its length: every event 2 hours long', 'Every event lengthens by half an hour, this one 2 hours long']);
  // A length change reads as one: the group says what every day takes.
  const dayOf = (key, day) => ({ key, kind: 'edited', title: 'Choir', day, outcome: 'stays', before: { date: day, at: day + 'T19:00:00-08:00' }, after: { date: day, at: day + 'T19:00:00-08:00' }, kept: ['title'], takes: ['length'] });
  const lengthOnly = planView({ ...base, groups: [], days: [dayOf('k1', '2027-01-12'), dayOf('k2', '2027-01-19')] }, { kind: 'resize', title: 'Choir', refYear: 2027 });
  eq('a length-only change: its days\' group says they take the new length', lengthOnly.edited.map((r) => r.line1), ['2 days that were changed earlier stay where they are and take the new length.']);
  // One field table: every field the editor sends has a row, and the panel names it.
  const sendable = ['title', 'calendarId', 'start', 'end', 'allDay', 'tzid', 'typed', 'location', 'locationLat', 'locationLng', 'locationProvider', 'url', 'description', 'tagNames', 'personIds', 'rrule', 'reminders', 'isContainer', 'status'];
  eq('field table: every field the editor sends is a row of the table', sendable.filter((k) => !editField(k)), []);
  eq('field table: a field the table lacks is never sent', changedFields({ title: 'A', mystery: 2 }, { title: 'A', mystery: 1 }), {});
  eq('field table: a new time sends its typed clocks with it', Object.keys(changedFields({ start: 'b', end: 'c', allDay: false, typed: { start: '2027-03-14T02:30', end: '2027-03-14T03:30', tz: 'America/Los_Angeles' } }, { start: 'a', end: 'c', allDay: false, typed: null })), ['start', 'typed', 'end', 'allDay']);
  eq('field table: every row but the rule has a label the panel says', EDIT_FIELDS.filter((r) => r.keys[0] !== 'rrule').every((r) => r.label && fieldLines(Object.fromEntries(r.keys.map((k) => [k, ['reminders', 'tagNames', 'personIds'].includes(k) ? [30] : 'x'])), {}, { timeWords: () => 'later', calendarName: (x) => x, personName: (x) => x }).length === 1), true);
  // The typed clock from quick add's strip: the clocks, not the instants. The
  // range is the one the strip builds on this device, whatever its zone: a
  // fixed offset here read the end's day in the device zone and failed on a
  // device a day ahead of the fixture's zone.
  const gapForm = { dateKey: '2027-03-14', startTime: '02:30', endTime: '03:30', allDay: false };
  eq('quick add typed clock: 02:30 on the spring change day reaches the server as 02:30',
    typedRange(gapForm, buildRange(gapForm, null, false), 'America/Los_Angeles'),
    { start: '2027-03-14T02:30', end: '2027-03-14T03:30', tz: 'America/Los_Angeles' });
  eq('quick add typed clock: none for an all-day range', typedRange({ dateKey: '2027-03-14', allDay: true }, { start: 'x', end: 'y' }, 'UTC'), null);
  // A range typed across the skipped hour (#222): the end is its clock
  // moved by the push the gap gave the start, the server's rule, for the
  // editor (typedInstants) and quick add's strip (buildRange). A northern
  // zone, a southern one, one on a half-hour offset, one with a half-hour gap.
  {
    const { typedInstants, wallTimeInZone } = await import('../src/lib/dates.js');
    const span = (z, a, b) => typedInstants(a, b, z).map((d) => (isNaN(d) ? 'none' : wallTimeInZone(d, z)));
    for (const [z, day] of [['America/Los_Angeles', '2027-03-14'], ['Australia/Sydney', '2027-10-03'], ['Australia/Adelaide', '2027-10-03']]) {
      eq(`typed across the gap (${z}): 02:30 to 03:30 is 03:30 to 04:30, 01:30 to 04:00 as typed, 02:30 to 05:00 is 03:30 to 06:00`,
        [span(z, day + 'T02:30', day + 'T03:30'), span(z, day + 'T01:30', day + 'T04:00'), span(z, day + 'T02:30', day + 'T05:00')],
        [[day + 'T03:30', day + 'T04:30'], [day + 'T01:30', day + 'T04:00'], [day + 'T03:30', day + 'T06:00']]);
      const [s0, e0] = typedInstants(day + 'T02:30', day + 'T02:15', z);
      const [s1, e1] = typedInstants(day + 'T03:10', day + 'T02:45', z);
      eq(`typed across the gap (${z}): an end before the start on the clocks reads as backwards`, [e0 <= s0, e1 <= s1], [true, true]);
    }
    eq('typed across a half-hour gap (Lord Howe Island): 02:15 to 02:45 is 02:45 to 03:15', span('Australia/Lord_Howe', '2027-10-03T02:15', '2027-10-03T02:45'), ['2027-10-03T02:45', '2027-10-03T03:15']);
    // This device's zone (null): the spring day of whichever zone the suite runs in is one of these.
    for (const day of ['2027-03-14', '2027-10-03']) {
      const r = buildRange({ dateKey: day, startTime: '02:30', endTime: '03:30', allDay: false }, null, false);
      eq(`quick range across the gap (${day}, this device): 02:30 to 03:30 is an hour that day, never the next`,
        [(parseISO(r.end) - parseISO(r.start)) / 60000, dayKeyOf(parseISO(r.end))], [60, day]);
      const [ls, le] = typedInstants(day + 'T02:30', day + 'T03:30', null);
      eq(`typed across the gap (${day}, this device): the editor's reading is quick add's`, [toISOWithOffset(ls), toISOWithOffset(le)], [r.start, r.end]);
    }
  }
  // Quick add's other reading: one tap, and a second puts it back.
  const stretch = { title: 'Workshop', start: '2026-08-03T09:00:00-07:00', end: '2026-08-07T17:00:00-07:00', allDay: false, rrule: null,
    other: { reading: 'each', start: '2026-08-03T09:00:00-07:00', end: '2026-08-03T17:00:00-07:00', allDay: false, rrule: 'FREQ=DAILY;COUNT=5', days: 5 } };
  const each = takeOtherReading(stretch);
  eq('quick add other reading: the chip says what a tap does', [otherReadingWords(stretch.other).label, otherReadingWords(each.other).label], ['Each day instead', 'One event instead']);
  eq('quick add other reading: taken, the days are each day\'s, and taken again, the stretch', [each.rrule, each.end, takeOtherReading(each).rrule, takeOtherReading(each).end],
    ['FREQ=DAILY;COUNT=5', '2026-08-03T17:00:00-07:00', null, '2026-08-07T17:00:00-07:00']);
  // What the parse assumed, beside the field it set: the server's entries
  // for "Standup 9-5pm" typed after 9 in the morning and "Dentist Mar 3 2pm"
  // in November, and none for "Lunch tomorrow noon".
  const standup = { assumed: [
    { field: 'date', kind: 'rolled', typed: null, date: '2026-11-06', start: '09:00' },
    { field: 'time', kind: 'clock', typed: '9-5pm', start: '09:00', end: '17:00' }] };
  const dentist = { assumed: [{ field: 'date', kind: 'year', typed: 'Mar 3', date: '2027-03-03' }] };
  eq('quick add assumed: the date and time say what was assumed, in the user\'s clock',
    [assumedFor(standup, 'date'), assumedFor(standup, 'time'), assumedFor(dentist, 'date'), assumedFor(dentist, 'time')].map((w) => w.replace(/\s/g, ' ')),
    ['tomorrow, as 9:00 AM has passed today', '9-5pm read as 9:00 AM to 5:00 PM', 'Mar 3 read as next year', '']);
  eq('quick add assumed: nothing shown when nothing was assumed, or from a server without the list',
    [assumedFor({ assumed: [] }, 'date'), assumedFor({ assumed: [] }, 'time'), assumedFor({}, 'time'), assumedFor(null, 'date')], ['', '', '', '']);
  eq('quick add assumed: no time read at all says so, once',
    assumedFor({ assumed: [{ field: 'time', kind: 'default', typed: null, start: '11:00', end: null }, { field: 'time', kind: 'default', typed: null, start: '11:00', end: null }] }, 'time'),
    'no time read, so the next hour');
}

console.log('--- the editor\'s save: only what changed (#199), all events through the plan (1.5.0) ---');
{
  const { changedFields, fieldLines } = await import('../src/lib/editfields.js');
  const was = {
    title: 'Sample choir', calendarId: 3, start: '2027-03-09T19:00:00-08:00', end: '2027-03-09T20:30:00-08:00', allDay: false,
    location: 'Sample Hall', locationLat: 1, locationLng: 2, url: null, description: null, tagNames: ['music'], personIds: [5],
    rrule: 'FREQ=WEEKLY;BYDAY=TU',
  };
  eq('editor payload: a rename sends the title alone, no rule', changedFields({ ...was, title: 'Sample chorus' }, was), { title: 'Sample chorus' });
  eq('editor payload: a rule built differently but untouched is not sent', changedFields({ ...was, rrule: 'FREQ=WEEKLY;INTERVAL=1;BYDAY=TU' }, was), {});
  eq('editor payload: the repeat controls touched send the rule', changedFields({ ...was, rrule: 'FREQ=WEEKLY;BYDAY=WE' }, was, { rruleTouched: true }), { rrule: 'FREQ=WEEKLY;BYDAY=WE' });
  // A date moved a day with the repeat controls untouched: the time alone,
  // so the server moves the rule and its end with the day as a drag does,
  // even where the form would build the rule for the new date differently.
  const moved = { ...was, start: '2027-03-10T19:00:00-08:00', end: '2027-03-10T20:30:00-08:00', rrule: 'FREQ=WEEKLY;BYDAY=TU;UNTIL=20270331T065959Z' };
  eq('editor payload: a date move, repeat untouched, sends the time and no rule', changedFields(moved, { ...was, rrule: 'FREQ=WEEKLY;BYDAY=TU;UNTIL=20270330T065959Z' }),
    { start: moved.start, end: moved.end, allDay: false });
  eq('editor payload: a date move with the repeat controls touched sends the rule with the time', changedFields(moved, was, { rruleTouched: true }),
    { start: moved.start, end: moved.end, allDay: false, rrule: moved.rrule });
  eq('editor payload: a new end time sends the whole time', changedFields({ ...was, end: '2027-03-09T21:00:00-08:00' }, was),
    { end: '2027-03-09T21:00:00-08:00', start: was.start, allDay: false });
  eq('editor payload: a zone picked sends the time with it', Object.keys(changedFields({ ...was, tzid: 'Europe/Berlin' }, was)), ['tzid', 'start', 'end', 'allDay']);
  eq('editor payload: a new place sends its coordinates too', changedFields({ ...was, location: 'Sample Annex', locationLat: null, locationLng: null }, was),
    { location: 'Sample Annex', locationLat: null, locationLng: null });
  eq('editor payload: tags by value, not by reference', changedFields({ ...was, tagNames: ['music'] }, was), {});
  eq('editor lines: each changed field, before and now', fieldLines({ title: 'Sample chorus', reminders: [30] }, { ...was, reminders: [60] }),
    [{ label: 'Title', words: 'Sample choir, now Sample chorus' }, { label: 'Reminder', words: '1 hour before, now 30 minutes before' }]);
  eq('editor lines: the trip flag, before and now', fieldLines({ isContainer: false }, { ...was, isContainer: true }), [{ label: 'Trip', field: 'isContainer', words: 'yes, now no' }]);
  // Reminders reach the panel in the shapes the editor holds them: the form
  // as it opened has minutes, the save sends entries ({minutes}). One
  // normalization, then one formatter (1.5.0 review, F6).
  const { normalizeMinutesList, remindersWords } = await import('../src/lib/reminders.js');
  const { planView: panelOf } = await import('../src/lib/seriesplan.js');
  // The trip flag's line says when the days differed, from the plan's data (review F4).
  const tripLine = (plan) => panelOf({ hash: 't1', ...plan }, { kind: 'editor', fields: fieldLines({ isContainer: false }, { ...was, isContainer: true }) }).fields;
  eq('series panel: the trip flag change is a line of its own; the plan says when the days differed', [
    tripLine({ fields: [{ field: 'is_container', words: 'Trip: yes, now no', was: 1, now: 0, mixed: false }] }),
    tripLine({ fields: [{ field: 'is_container', words: 'Trip: yes on some days, now no for every event', was: 1, now: 0, mixed: true }] }),
    tripLine({}),
  ], [['Trip from yes to no.'], ['Trip from yes on some days to no for every event.'], ['Trip from yes to no.']]);
  const reminderChange = (opened, now) => panelOf({ hash: 'r1' }, { kind: 'editor', fields: fieldLines({ reminders: normalizeMinutesList(now) }, { ...was, reminders: opened }) }).fields;
  eq('editor to panel: a reminder from 1 hour to 30 minutes before', reminderChange([60], [30]), ['Reminder from 1 hour before to 30 minutes before.']);
  eq('editor to panel: a reminder from 30 minutes to 1 hour before', reminderChange([30], [60]), ['Reminder from 30 minutes before to 1 hour before.']);
  eq('reminder words: minutes and entries read the same; none of its own is the default', [
    remindersWords([30, 60]), remindersWords([{ minutes: 30 }, { minutes: 60 }]), remindersWords([0]), remindersWords([]), remindersWords(null), remindersWords([{ daysBefore: 1, time: '18:00' }]),
  ].map((w) => w.replace(/\s/g, ' ')), ['30 minutes before, 1 hour before', '30 minutes before, 1 hour before', 'At start', 'none', 'default', 'Day before at 6:00 PM']);

  const realFetch = globalThis.fetch;
  const { openSeriesChange, confirmSeriesChange, cancelSeriesChange } = await import('../src/app/serieschange.js');
  const { state: st, set: put } = await import('../src/app/store.js');
  const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  const tick = async (n = 10) => { for (let i = 0; i < n; i++) await new Promise((r) => setTimeout(r, 0)); };
  const sent = [];
  let plan = null;
  let commits = []; // answers for the next commits, in turn; then { ok: true }
  globalThis.fetch = async (url, o = {}) => {
    const p = new URL(String(url), 'http://app.test').pathname.replace('/api/v1', '');
    if (p === '/events/52' && o.method === 'PATCH') {
      const body = JSON.parse(o.body);
      sent.push(body);
      return body.preview ? json({ plan }) : commits.length ? commits.shift()() : json({ ok: true });
    }
    if (p === '/changes/cursor') return json({ cursor: 'e1' });
    if (p === '/events') return json({ events: [] });
    return json({});
  };
  put({ config: { ...st.config, seriesPlans: true }, seriesChange: null, calendars: [{ id: 3, name: 'Sample', editable: true }], loadedRanges: [], visibleDays: null });
  const occ = { instanceId: '52@1', eventId: 52, calendarId: 3, title: 'Sample choir', recurring: true, start: '2027-03-09T19:00:00-08:00', end: '2027-03-09T20:30:00-08:00' };
  let closed = 0;
  // Direct: a rename no edited day owns. Previewed, then committed at once, no dialog.
  plan = { hash: 'd1', direct: true, groups: [], refusals: [] };
  openSeriesChange({ kind: 'editor', occ, body: { title: 'Sample chorus' }, quiet: true, onDone: () => { closed++; } });
  eq('editor save: while the plan is asked for, the dialog stays hidden', [st.seriesChange && st.seriesChange.hidden], [true]);
  await tick();
  eq('editor save, direct: previewed with only the title, then committed with its hash', sent.map((b) => [b.title, b.rrule, b.preview, b.plan]),
    [['Sample chorus', undefined, true, undefined], ['Sample chorus', undefined, undefined, { hash: 'd1', choices: {} }]]);
  eq('editor save, direct: no dialog, and the editor closes', [st.seriesChange, closed], [null, 1]);
  // Not direct: an edited day owns its title, so the dialog opens over the footer.
  sent.length = 0;
  plan = { hash: 'n1', direct: false, groups: [{ id: 'ownTitle', selected: 'keep', options: [{ id: 'keep', label: 'Keep its own title' }, { id: 'give', label: 'Give it the new title too' }] }], refusals: [] };
  openSeriesChange({ kind: 'editor', occ, body: { title: 'Sample chorus' }, quiet: true, onDone: () => { closed++; } });
  await tick();
  eq('editor save, previewed: the dialog opens with the plan, nothing written', [st.seriesChange && st.seriesChange.hidden, st.seriesChange && st.seriesChange.status, sent.length, closed], [false, 'ready', 1, 1]);
  await confirmSeriesChange();
  await tick();
  eq('editor save, previewed: Confirm saves with the choice and closes the editor', [sent[1].plan, st.seriesChange, closed], [{ hash: 'n1', choices: { ownTitle: 'keep' } }, null, 2]);
  // A quiet save is quiet only while its plan needs no one. Its commit met a
  // series changed since the preview: the new plan, with a choice or a
  // refusal, shows; one that still needs no one is committed (F8).
  const changedWith = (next) => () => json({ error: { code: 'series_plan_changed', message: 'The series changed', plan: next } }, 409);
  const ownChoice = { hash: 'n2', direct: false, groups: [{ id: 'ownTitle', selected: 'keep', options: [{ id: 'keep' }, { id: 'give' }] }], refusals: [] };
  for (const [what, answerOf, after] of [
    ['a choice', changedWith(ownChoice), 'n2'],
    ['a refusal', () => json({ error: { code: 'series_refused', message: 'Refused', plan: { hash: 'n3', direct: false, groups: [], refusals: [{ code: 'weekno' }] } } }, 422), 'n3'],
  ]) {
    sent.length = 0;
    plan = { hash: 'd2', direct: true, groups: [], refusals: [] };
    commits = [answerOf];
    openSeriesChange({ kind: 'editor', occ, body: { title: 'Sample chorus' }, quiet: true, onDone: () => { closed++; } });
    await tick();
    eq(`editor save, quiet, its commit answered with ${what}: the panel shows the new plan, the save waits for it`, [
      sent.map((b) => (b.preview ? 'preview' : b.plan.hash)), st.seriesChange && st.seriesChange.hidden, st.seriesChange && st.seriesChange.plan.hash, st.seriesChange && st.seriesChange.status,
    ], [['preview', 'd2'], false, after, 'ready']);
    cancelSeriesChange();
  }
  sent.length = 0;
  plan = { hash: 'd3', direct: true, groups: [], refusals: [] };
  commits = [changedWith({ hash: 'd4', direct: true, groups: [], refusals: [] })];
  const closedBefore = closed;
  openSeriesChange({ kind: 'editor', occ, body: { title: 'Sample chorus' }, quiet: true, onDone: () => { closed++; } });
  await tick();
  eq('editor save, quiet, its commit answered with a plan that still needs no one: committed with the new hash, no panel', [
    sent.map((b) => (b.preview ? 'preview' : b.plan.hash)), st.seriesChange, closed - closedBefore], [['preview', 'd3', 'd4'], null, 1]);
  // From an edited day with its own title: the heading and the toast name the series.
  sent.length = 0;
  plan = { hash: 'm1', direct: false, groups: [], refusals: [] };
  const edited = { ...occ, instanceId: '52@2', title: 'Sample rehearsal', isException: true, seriesId: 51, seriesTitle: 'Sample choir', seriesKey: '2027-03-16T03:00:00Z' };
  openSeriesChange({ kind: 'drag', occ: edited, body: { start: '2027-03-10T19:00:00-08:00', end: '2027-03-10T20:30:00-08:00' }, pending: { start: '2027-03-10T19:00:00-08:00', end: '2027-03-10T20:30:00-08:00' } });
  await tick();
  eq('from an edited day: the dialog is named for the series, not the day', st.seriesChange && st.seriesChange.title, 'Sample choir');
  await confirmSeriesChange();
  await tick();
  const lastToast = st.toasts[st.toasts.length - 1];
  eq('from an edited day: the toast names the series', [lastToast && lastToast.text, lastToast && lastToast.title], ['Moved all events', 'Sample choir']);
  globalThis.fetch = realFetch;
}

console.log('--- series plans only where the server says so (/config seriesPlans) ---');
{
  const realFetch = globalThis.fetch;
  const { loadConfig } = await import('../src/app/api.js');
  const { seriesDrop, useSeriesValue } = await import('../src/app/actions.js');
  const { openSeriesChange, seriesPlans } = await import('../src/app/serieschange.js');
  const { state: st, set: put } = await import('../src/app/store.js');
  const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  const tick = async (n = 10) => { for (let i = 0; i < n; i++) await new Promise((r) => setTimeout(r, 0)); };
  let config = {};
  const sent = [];
  globalThis.fetch = async (url, o = {}) => {
    const p = new URL(String(url), 'http://app.test').pathname.replace('/api/v1', '');
    if (p === '/config') return json(config);
    if (p === '/events/63' && o.method === 'PATCH') { sent.push(JSON.parse(o.body)); return json({ ok: true }); }
    if (p === '/changes/cursor') return json({ cursor: 'g1' });
    if (p === '/events') return json({ events: [] });
    return json({});
  };
  const occ = { instanceId: '63@1', eventId: 63, calendarId: 3, title: 'Sample swim', recurring: true, isException: true, ownFields: ['title'], start: '2027-01-11T07:00:00-08:00', end: '2027-01-11T08:00:00-08:00' };
  const drop = { instanceId: occ.instanceId, newStart: '2027-01-15T07:00:00-08:00', newEnd: '2027-01-15T08:00:00-08:00', at: { x: 10, y: 10 } };
  put({ seriesChange: null, calendars: [{ id: 3, name: 'Sample', editable: true }], loadedRanges: [], visibleDays: null });
  st.occ.clear();
  st.occ.set(occ.instanceId, { ...occ });

  config = { maptilerKey: null };
  await loadConfig();
  eq('series gate: a server that doesn\'t say seriesPlans is taken as one without', seriesPlans(), false);
  await seriesDrop('drag', st.occ.get(occ.instanceId), drop, 'all');
  await tick();
  eq('series gate: off, All events goes straight to the PATCH, as before 1.5.0', [st.seriesChange, sent.length, sent[0] && sent[0].scope, sent[0] && sent[0].preview, sent[0] && sent[0].plan], [null, 1, 'all', undefined, undefined]);
  eq('series gate: off, no dialog opens for an editor save either', openSeriesChange({ kind: 'editor', occ, body: { title: 'x' }, quiet: true }), null);
  eq('series gate: off, Use series value sends nothing', [await useSeriesValue(occ, ['title']), sent.length], [false, 1]);

  config = { seriesPlans: true };
  await loadConfig();
  eq('series gate: on when the server says so', seriesPlans(), true);
  sent.length = 0;
  await useSeriesValue(occ, ['title']);
  eq('series gate: on, Use series value gives the field back on this day', sent[0], { scope: 'this', instanceStart: occ.start, useSeries: ['title'] });
  put({ config: { ...st.config, seriesPlans: false }, seriesChange: null });
  st.occ.clear();
  globalThis.fetch = realFetch;
}

console.log('--- Use series value: aimed at the series with the day\'s key, its answer applied (1.5.0) ---');
{
  const realFetch = globalThis.fetch;
  const { useSeriesValue } = await import('../src/app/actions.js');
  const { state: st, set: put } = await import('../src/app/store.js');
  const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  const sent = [];
  let record = null; // what GET /events/71/occurrence answers; null is a 404
  globalThis.fetch = async (url, o = {}) => {
    const p = new URL(String(url), 'http://app.test').pathname.replace('/api/v1', '');
    if (o.method === 'PATCH') { sent.push([p, JSON.parse(o.body)]); return json({ ok: true }); }
    if (p === '/events/71/occurrence') return record ? json({ occurrence: record }) : json({ error: { code: 'not_found', message: 'Event not found' } }, 404);
    if (p === '/changes/cursor') return json({ cursor: 'u1' });
    if (p === '/events') return json({ events: [] });
    return json({});
  };
  put({ config: { ...st.config, seriesPlans: true }, seriesChange: null, calendars: [{ id: 3, name: 'Sample', editable: true }], loadedRanges: [], visibleDays: null });
  // An edited day with its own date and time: the series has it on Tue 16 Mar
  // at 19:00 (its key), it shows on Thu 18 Mar at 18:00.
  const day = {
    instanceId: '71@1', eventId: 71, calendarId: 3, title: 'Rehearsal', recurring: true, isException: true,
    ownFields: ['date', 'time', 'title'], seriesId: 70, seriesKey: '2027-03-17T03:00:00Z', seriesTitle: 'Sample choir',
    start: '2027-03-18T18:00:00-07:00', end: '2027-03-18T19:30:00-07:00', tzid: 'America/Los_Angeles',
  };
  const fresh = () => { st.occ.clear(); st.occ.set(day.instanceId, { ...day }); sent.length = 0; return st.toasts.length; };
  const toastsSince = (n) => st.toasts.slice(n).map((t) => t.text);

  let t0 = fresh();
  record = { ...day, title: 'Sample choir', ownFields: ['date', 'time'] };
  const ok = await useSeriesValue(st.occ.get(day.instanceId), ['title']);
  eq('use series value: sent to the series with the day\'s key, not where it shows', sent, [['/events/70', { scope: 'this', instanceStart: '2027-03-17T03:00:00Z', useSeries: ['title'] }]]);
  eq('use series value: the answer read back is applied to the day', [ok, st.occ.get(day.instanceId) && st.occ.get(day.instanceId).title, st.occ.get(day.instanceId) && st.occ.get(day.instanceId).ownFields], [true, 'Sample choir', ['date', 'time']]);
  eq('use series value: the toast names what went back', toastsSince(t0), ["Uses the series' title"]);

  t0 = fresh();
  record = { ...day };
  eq('use series value: a server that changed nothing is not reported as done', [await useSeriesValue(st.occ.get(day.instanceId), ['title']), toastsSince(t0)],
    [false, ['Nothing changed: this day still has its own title']]);

  t0 = fresh();
  record = null;
  eq('use series value: the last own fields given back, the day is an occurrence again', [await useSeriesValue(st.occ.get(day.instanceId), ['date', 'time', 'title']), toastsSince(t0)],
    [true, ["Uses the series' date, time and title"]]);

  t0 = fresh();
  record = { ...day, ownFields: ['date', 'time'] };
  const older = { ...day, seriesId: undefined, seriesKey: undefined };
  await useSeriesValue(older, ['title']);
  eq('use series value: without the series\' id and key, the day\'s own id and start', sent, [['/events/71', { scope: 'this', instanceStart: day.start, useSeries: ['title'] }]]);

  put({ config: { ...st.config, seriesPlans: false } });
  st.occ.clear();
  globalThis.fetch = realFetch;
}

console.log('--- the editor\'s monthly forms (1.5.0) ---');
{
  const { monthDayRule, monthlyOptions } = await import('../src/lib/repeat.js');
  const opts = (d) => monthlyOptions(d).map((o) => o.value + ': ' + o.label);
  eq('monthly forms: a 15th', opts(new Date(2027, 2, 15)), ['day: on day 15', 'nth: on the third Monday']);
  eq('monthly forms: a 30th that is its month\'s second-last', opts(new Date(2027, 2, 30)), [
    'day: on day 30, skipping months without one', 'clamp: on day 30, or the last day in shorter months',
    'fromend: on the second-last day', 'last: on the last Tuesday']);
  eq('monthly forms: a 30th that is its month\'s last', opts(new Date(2027, 3, 30)).map((o) => o.split(':')[0]), ['day', 'clamp', 'lastday', 'last']);
  eq('monthly forms: the 25th of March, the seventh-last', opts(new Date(2027, 2, 25)).filter((o) => o.startsWith('fromend')), ['fromend: on the seventh-last day']);
  eq('monthly forms: the 24th of March offers no from-the-end form', opts(new Date(2027, 2, 24)).filter((o) => o.startsWith('fromend')), []);
  const rules = [
    ['clamp', new Date(2027, 2, 30), 'BYMONTHDAY=28,29,30;BYSETPOS=-1', 'Monthly on the 30th, or the last day in shorter months'],
    ['clamp', new Date(2027, 2, 29), 'BYMONTHDAY=28,29;BYSETPOS=-1', 'Monthly on the 29th, or the last day in shorter months'],
    ['lastday', new Date(2027, 3, 30), 'BYMONTHDAY=-1', 'Monthly on the last day'],
    ['fromend', new Date(2027, 2, 30), 'BYMONTHDAY=-2', 'Monthly on the second-last day'],
    ['fromend', new Date(2027, 2, 25), 'BYMONTHDAY=-7', 'Monthly on the seventh-last day'],
  ];
  for (const [mode, d, part, words] of rules) {
    eq('monthly forms: ' + mode + ' writes ' + part + ' and reads back in words', [monthDayRule(mode, d), describeRepeat('FREQ=MONTHLY;' + part)], [part, words]);
  }
  eq('monthly forms: day N is written bare, and a form the start lacks falls back to it', [monthDayRule('day', new Date(2027, 2, 30)), monthDayRule('clamp', new Date(2027, 2, 15)), monthDayRule('lastday', new Date(2027, 2, 30))], [null, null, null]);
  eq('monthly forms: bare from a 30th reads as skipping', describeRepeat('FREQ=MONTHLY', { start: new Date(2027, 2, 30) }), 'Monthly on the 30th, skipping months without one');
}

console.log('--- the scopes an occurrence has, one rule for every chooser (1.5.1) ---');
{
  const realFetch = globalThis.fetch;
  const { seriesScopes, scopeDropChoice } = await import('../src/app/scopes.js');
  const { ScopeChoice } = await import('../src/app/DeleteScope.js');
  const { deleteEvent, setAttendance, setRelationship } = await import('../src/app/actions.js');
  const { changeSeriesScope, cancelSeriesChange } = await import('../src/app/serieschange.js');
  const { refusalWords, unofferedWords } = await import('../src/lib/planwords.js');
  const { planRefusal } = await import('../src/lib/seriesplan.js');
  const { state: st, set: put } = await import('../src/app/store.js');
  const tick = async (n = 10) => { for (let i = 0; i < n; i++) await new Promise((r) => setTimeout(r, 0)); };
  const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  const sent = [];
  globalThis.fetch = async (url, o = {}) => {
    const p = new URL(String(url), 'http://app.test').pathname.replace('/api/v1', '');
    if (p.startsWith('/events/')) {
      sent.push([o.method, p, JSON.parse(o.body || '{}')]);
      return json({ plan: { hash: 's1', scope: 'following', direct: false, groups: [], refusals: [], split: null } });
    }
    if (p === '/changes/cursor') return json({ cursor: 'e1' });
    if (p === '/events') return json({ events: [] });
    return json({});
  };
  const savedCalendars = st.calendars;
  put({
    config: { ...st.config, seriesPlans: true }, seriesChange: null, loadedRanges: [], visibleDays: null,
    calendars: [
      { id: 31, name: 'Sample home', kind: 'local', provider: 'ics', editable: true, role: 'mine' },
      { id: 32, name: 'Sample shared', kind: 'subscribed', provider: 'google', editable: true, role: 'mine' },
      { id: 33, name: 'Sample other', kind: 'local', provider: 'ics', editable: true, role: 'mine' },
    ],
  });
  const base = { eventId: 81, title: 'Sample swim', recurring: true, rrule: 'FREQ=WEEKLY', start: '2027-04-06T18:00:00-07:00', end: '2027-04-06T19:00:00-07:00' };
  const local = { ...base, instanceId: '81@1', calendarId: 31 };
  const google = { ...base, instanceId: '82@1', eventId: 82, calendarId: 32 };
  eq('scopes: a series on a local calendar has this, following and all', seriesScopes(local), { scopes: ['this', 'following', 'all'], why: null });
  eq('scopes: on a Google calendar too, since 1.5.4 (GoogleSeries carries a split)', seriesScopes(google), { scopes: ['this', 'following', 'all'], why: null });
  eq('scopes: no scope is left unoffered for a calendar kind', unofferedWords('google'), null);
  eq('scopes: a date edited on its own has following too, a calendar move included', seriesScopes({ ...local, isException: true }), { scopes: ['this', 'following', 'all'], why: null });

  // The drop chip (drag, resize, reschedule, a calendar or a person drop).
  const chip = (occ, o) => scopeDropChoice({ x: 10, y: 20 }, occ, () => {}, o);
  eq('drop chip: local, three scopes and Cancel, no note', [chip(local).options.map((x) => x.value), chip(local).note], [['this', 'following', 'all', null], null]);
  eq('drop chip: Google, three scopes too, no note, the title says Google and that it can\'t be undone yet', [chip(google).options.map((x) => x.value), chip(google).note, /Google/.test(chip(google).title), /undone yet/.test(chip(google).title)],
    [['this', 'following', 'all', null], null, true, true]);
  eq('drop chip: the all label the caller gives', chip(local, { allLabel: 'All events…' }).options.map((x) => x.label), ['This event', 'This + following', 'All events…', 'Cancel']);

  // The delete, answer and new-time row.
  const texts = (v) => (v == null || v === false ? [] : typeof v === 'string' ? [v] : Array.isArray(v) ? v.flatMap(texts) : v.props ? texts(v.props.children) : []);
  const row = (occ) => texts(ScopeChoice({ occ, verb: 'Delete', onPick: () => {}, onCancel: () => {} }));
  eq('scope row: local offers This and following', row(local).includes('This and following'), true);
  eq('scope row: Google offers This and following too', row(google).includes('This and following'), true);

  // The refusal panel's sentence for a split is the split's where it has one, else the change's.
  eq('refusal words: a split\'s own refusal (its shape) has its own sentence; the all-day Google reason reads the same under either scope', [
    planRefusal({ scope: 'following', refusals: [{ code: 'shape' }] }) !== refusalWords('shape'), planRefusal({ scope: 'following', refusals: [{ code: 'googleAllDay' }] }) === refusalWords('googleAllDay'),
  ], [true, true]);

  // Delete following and an answer from a day on carry "Change scope".
  let asked = 0;
  await deleteEvent(local, 'following', () => { asked++; });
  await tick();
  eq('delete following: the dialog opens through the delete route with a rescope', [st.seriesChange && st.seriesChange.kind, typeof (st.seriesChange && st.seriesChange.rescope), sent[sent.length - 1].slice(0, 2)],
    ['delete', 'function', ['DELETE', '/events/81']]);
  changeSeriesScope();
  eq('delete following: Change scope closes the preview and asks again', [st.seriesChange, asked], [null, 1]);
  await setAttendance(local, 'going', 'following', () => { asked++; });
  await tick();
  eq('answer from a day on (attendance): the dialog carries a rescope', [st.seriesChange && st.seriesChange.kind, typeof (st.seriesChange && st.seriesChange.rescope)], ['answer', 'function']);
  changeSeriesScope();
  eq('answer from a day on: Change scope asks again', [st.seriesChange, asked], [null, 2]);
  await setRelationship(local, 'maybe', 'following', () => { asked++; });
  await tick();
  eq('answer from a day on (relationship): the dialog carries a rescope', typeof (st.seriesChange && st.seriesChange.rescope), 'function');
  changeSeriesScope();
  eq('answer from a day on (relationship): Change scope asks again', asked, 3);
  // Without one of its own, Change scope opens the panel that was open again.
  put({ popover: { instanceId: local.instanceId, anchorRect: null } });
  await deleteEvent(local, 'following');
  await tick();
  eq('delete following, no rescope given: the panel closed while the dialog shows', [st.popover, typeof (st.seriesChange && st.seriesChange.rescope)], [null, 'function']);
  changeSeriesScope();
  eq('delete following, no rescope given: Change scope opens the panel again', st.popover && st.popover.instanceId, local.instanceId);
  cancelSeriesChange();
  put({ popover: null, editor: null, calendars: savedCalendars, config: { ...st.config, seriesPlans: false } });
  globalThis.fetch = realFetch;
}

console.log('--- a change for more than one occurrence goes through its plan, whatever row asked (1.5.1) ---');
{
  const realFetch = globalThis.fetch;
  const { updateEvent, moveEventToCalendar, linkPersonToEvent, setRelationship } = await import('../src/app/actions.js');
  const { changeSeriesScope, cancelSeriesChange, confirmSeriesChange } = await import('../src/app/serieschange.js');
  const { scopeDropChoice } = await import('../src/app/scopes.js');
  const { ScopeChoice } = await import('../src/app/DeleteScope.js');
  const { state: st, set: put } = await import('../src/app/store.js');
  const tick = async (n = 10) => { for (let i = 0; i < n; i++) await new Promise((r) => setTimeout(r, 0)); };
  const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  const sent = [];
  let plan = null;
  let refuseWithoutPlan = false;
  globalThis.fetch = async (url, o = {}) => {
    const p = new URL(String(url), 'http://app.test').pathname.replace('/api/v1', '');
    if (p.startsWith('/events/')) {
      const body = JSON.parse(o.body || '{}');
      sent.push([o.method, p, body]);
      if (body.preview) return json({ plan });
      if (refuseWithoutPlan && !body.plan) return json({ error: { code: 'series_plan_required', message: 'Needs its plan', plan } }, 409);
      return json({ ok: true });
    }
    if (p === '/changes/cursor') return json({ cursor: 'e1' });
    if (p === '/events') return json({ events: [] });
    return json({});
  };
  const savedCalendars = st.calendars, savedPeople = st.people;
  put({
    config: { ...st.config, seriesPlans: true }, seriesChange: null, loadedRanges: [], visibleDays: null,
    calendars: [
      { id: 41, name: 'Sample home', kind: 'local', provider: 'ics', editable: true, role: 'mine' },
      { id: 42, name: 'Sample work', kind: 'local', provider: 'ics', editable: true, role: 'mine' },
      { id: 43, name: 'Sample shared', kind: 'subscribed', provider: 'google', editable: true, role: 'mine' },
    ],
    people: [{ id: 7, name: 'Alex Example' }],
  });
  const occ = { instanceId: '91@1', eventId: 91, calendarId: 41, title: 'Sample run', recurring: true, rrule: 'FREQ=WEEKLY', people: [], start: '2027-05-04T07:00:00-07:00', end: '2027-05-04T08:00:00-07:00' };
  const google = { ...occ, instanceId: '92@1', eventId: 92, calendarId: 43 };
  const newTime = { start: '2027-05-04T08:00:00-07:00', end: '2027-05-04T09:00:00-07:00' };
  const needsSomeone = { hash: 'p1', scope: 'following', direct: false, groups: [], refusals: [], split: null };
  const rows = [
    ['the New time row', (scope, re) => updateEvent(occ, newTime, scope, null, re), newTime],
    ['a calendar drop', (scope, re) => moveEventToCalendar(occ, 42, scope, re), { calendarId: 42 }],
    ['a person drop', (scope, re) => linkPersonToEvent(occ, { id: 7, name: 'Alex Example' }, scope, re), { personIds: [7] }],
  ];
  for (const [what, run, body] of rows) {
    for (const scope of ['following', 'all']) {
      sent.length = 0;
      plan = { ...needsSomeone, scope };
      let asked = 0;
      await run(scope, () => { asked++; });
      await tick();
      const sc = st.seriesChange;
      eq(`${what}, ${scope}: previewed with its own body, nothing written, the dialog shows its plan`, [
        sent.map(([m, , b]) => [m, b.preview, b.scope]), sc && sc.status, sc && sc.hidden, sc && sc.body,
      ], [[['PATCH', true, scope]], 'ready', false, body]);
      changeSeriesScope();
      eq(`${what}, ${scope}: Change scope closes it and asks again`, [st.seriesChange, asked], [null, 1]);
    }
  }
  // A date edited on its own, moved to another calendar with the following ones: its plan, as any other day's.
  sent.length = 0;
  plan = { ...needsSomeone };
  const editedDay = { ...occ, instanceId: '93@1', eventId: 93, isException: true, seriesId: 91, seriesTitle: 'Sample run', seriesKey: '2027-05-11T14:00:00Z', start: '2027-05-11T07:30:00-07:00', end: '2027-05-11T08:30:00-07:00' };
  eq('a calendar drop on an edited day: the chip offers following', scopeDropChoice({ x: 0, y: 0 }, editedDay, () => {}).options.map((o) => o.value), ['this', 'following', 'all', null]);
  let askedAgain = 0;
  await moveEventToCalendar(editedDay, 42, 'following', () => { askedAgain++; });
  await tick();
  eq('a calendar drop on an edited day, following: previewed with the calendar, the dialog shows its plan, Change scope there', [
    sent.map(([m, p, b]) => [m, p, b.preview, b.scope, b.calendarId]), st.seriesChange && st.seriesChange.status, st.seriesChange && st.seriesChange.hidden, typeof (st.seriesChange && st.seriesChange.rescope),
  ], [[['PATCH', '/events/93', true, 'following', 42]], 'ready', false, 'function']);
  changeSeriesScope();
  eq('a calendar drop on an edited day: Change scope asks again', [st.seriesChange, askedAgain], [null, 1]);
  // A change to all events whose plan needs no one commits at once, no panel.
  sent.length = 0;
  plan = { hash: 'p2', scope: 'all', direct: true, groups: [], refusals: [] };
  await moveEventToCalendar(occ, 42, 'all');
  await tick();
  eq('a calendar drop, all, its plan direct: previewed, then committed with the hash, no panel', [
    sent.map(([, , b]) => (b.preview ? 'preview' : b.plan && b.plan.hash)), st.seriesChange,
  ], [['preview', 'p2'], null]);
  // Committed through the dialog: the row's own body, the plan's hash.
  sent.length = 0;
  plan = { ...needsSomeone };
  await linkPersonToEvent(occ, { id: 7, name: 'Alex Example' }, 'following');
  await tick();
  await confirmSeriesChange();
  await tick();
  eq('a person drop, following, confirmed: the commit carries the people and the hash', sent[1] && [sent[1][2].personIds, sent[1][2].scope, sent[1][2].plan], [[7], 'following', { hash: 'p1', choices: {} }]);
  // An answer for all events whose status edit needs its plan: the 409 opens the dialog with the same change.
  sent.length = 0;
  refuseWithoutPlan = true;
  plan = { hash: 'p3', scope: 'all', direct: false, groups: [], refusals: [] };
  await setRelationship(occ, 'maybe', 'all');
  await tick();
  eq('an answer for all events, its plan needed: the dialog opens through the same route and body, no failure toast', [
    sent.map(([m, p, b]) => [m, p, !!b.preview]), st.seriesChange && st.seriesChange.body, st.seriesChange && typeof st.seriesChange.rescope,
  ], [[['POST', '/events/91/relationship', false], ['POST', '/events/91/relationship', true]], { relationship: 'maybe' }, 'function']);
  cancelSeriesChange();
  refuseWithoutPlan = false;
  // On a Google calendar the rows offer every scope, as on a local one (1.5.4).
  const texts = (v) => (v == null || v === false ? [] : typeof v === 'string' ? [v] : Array.isArray(v) ? v.flatMap(texts) : v.props ? texts(v.props.children) : []);
  eq('the New time row on Google: This and following too', texts(ScopeChoice({ occ: google, verb: 'New time for', onPick: () => {}, onCancel: () => {} })).includes('This and following'), true);
  eq('a calendar or person drop on Google: following in the chip too', scopeDropChoice({ x: 0, y: 0 }, google, () => {}).options.map((o) => o.value), ['this', 'following', 'all', null]);
  put({ calendars: savedCalendars, people: savedPeople, config: { ...st.config, seriesPlans: false } });
  globalThis.fetch = realFetch;
}

console.log(passed + ' passed, ' + failed + ' failed');
if (failed > 0) process.exit(1);
