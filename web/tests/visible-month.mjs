// The month the month grid's label names (ui/monthmath.js visibleMonthOf):
// the one filling most of the visible day area, as a person sees it. Each
// case is a real row geometry (the phone's three columns at fixed height,
// the desktop's seven at fixed and fitted heights) and a scroll position:
// where Next lands for every month of two years (so the 1st falls on every
// weekday) and a sweep of positions around it. The expected month is worked
// out here from the area each month's cells show; only the row geometry and
// which days a row holds come from the product. Run: node
// web/tests/visible-month.mjs (smoke.mjs imports it). No clock is read.

import assert from 'node:assert/strict';

const M = await import('../src/ui/monthmath.js');
const { visibleMonthOf, fixedGeometry, fitGeometry, firstEpochDayOfRow, rowIndexOfDayKey, ANCHOR_LINE } = M;
const { keyOfEpochDay } = await import('../src/lib/dates.js');

// The month (year * 12 + month - 1) filling most of [top, top + h), from the
// days' own dates; ties to the later month.
function byArea(geo, top, h, columns) {
  const area = new Map();
  for (let w = geo.weekAt(top); w <= geo.weekAt(top + h - 1); w++) {
    const px = Math.min(geo.top(w) + geo.height(w), top + h) - Math.max(geo.top(w), top);
    if (px <= 0) continue;
    for (let i = 0; i < columns; i++) {
      const [y, m] = keyOfEpochDay(firstEpochDayOfRow(w, columns) + i).split('-').map(Number);
      const k = y * 12 + m - 1;
      area.set(k, (area.get(k) || 0) + px);
    }
  }
  let best = null;
  for (const [k, a] of area) if (best === null || a > area.get(best) || (a === area.get(best) && k > best)) best = k;
  return { year: Math.floor(best / 12), month: (best % 12) + 1, share: area.get(best) / [...area.values()].reduce((s, a) => s + a, 0) };
}

// The screens: the scroller's height (between the bars) and its rows, as
// measured on the local demo (phone 412x915 and 390x844 in the 3-day ribbon,
// desktop 1280x800), and Next's anchor: the 1st on the phone's ribbon, the
// 15th in the desktop month (actions.js jumpAnchorFor).
const lo = rowIndexOfDayKey('2025-06-01', 7) - 40;
const SCREENS = [
  { name: 'phone 412x915', columns: 3, h: 812, geo: (c) => fixedGeometry(rowIndexOfDayKey('2025-06-01', c) - 40, rowIndexOfDayKey('2028-06-01', c), 162), day: '01' },
  { name: 'phone 390x844', columns: 3, h: 741, geo: (c) => fixedGeometry(rowIndexOfDayKey('2025-06-01', c) - 40, rowIndexOfDayKey('2028-06-01', c), 162), day: '01' },
  { name: 'desktop 1280x800', columns: 7, h: 735, geo: () => fixedGeometry(lo, rowIndexOfDayKey('2028-06-01', 7), 182), day: '15' },
  // Fitted rows: every fourth week a tall one, as busy weeks are.
  { name: 'desktop, fitted rows', columns: 7, h: 735,
    geo: () => fitGeometry(lo, rowIndexOfDayKey('2028-06-01', 7), 120, new Map(Array.from({ length: 200 }, (_, i) => [lo + i * 4, 300]))), day: '15' },
];
const MONTHS = [];
for (let y = 2026; y <= 2027; y++) for (let m = 1; m <= 12; m++) MONTHS.push(y + '-' + String(m).padStart(2, '0'));

let checks = 0;
const ties = [];
for (const s of SCREENS) {
  const geo = s.geo(s.columns);
  for (const mo of MONTHS) {
    const row = rowIndexOfDayKey(mo + '-' + s.day, s.columns);
    const land = Math.max(0, geo.top(row) - Math.max(0, Math.round((s.h - geo.height(row)) * ANCHOR_LINE)));
    for (let d = -210; d <= 210; d += 7) {
      const top = land + d;
      const want = byArea(geo, top, s.h, s.columns);
      const got = visibleMonthOf(geo, top, s.h, s.columns);
      assert.deepEqual(got, { year: want.year, month: want.month }, s.name + ', Next to ' + mo + ', ' + d + 'px from where it lands: the month filling most of the screen');
      checks++;
    }
    // Where Next lands: the month it was aimed at, and by how much.
    const at = byArea(geo, land, s.h, s.columns);
    const aimed = at.year + '-' + String(at.month).padStart(2, '0') === mo;
    ties.push({ screen: s.name, month: mo, aimed, share: Math.round(at.share * 1000) / 10 });
  }
}

// The two seen on the phone after Next (2026-11-14 review): January 2027
// and April 2027 at three columns, the 1st late in its row.
const phone = SCREENS[0];
for (const [mo, want] of [['2027-01', { year: 2027, month: 1 }], ['2027-04', { year: 2027, month: 4 }]]) {
  const geo = phone.geo(3);
  const row = rowIndexOfDayKey(mo + '-01', 3);
  const land = geo.top(row) - Math.round((phone.h - geo.height(row)) * ANCHOR_LINE);
  assert.deepEqual(visibleMonthOf(geo, land, phone.h, 3), want, 'phone, Next to ' + mo + ': the label names it');
  checks++;
}

const missed = ties.filter((t) => !t.aimed);
const closest = Object.values(ties.reduce((o, t) => { if (t.aimed && (!o[t.screen] || t.share < o[t.screen].share)) o[t.screen] = t; return o; }, {}));
console.log('Visible month: ' + checks + ' positions name the month filling most of the screen; where Next lands, '
  + (missed.length ? missed.length + ' months name another month (' + missed.map((t) => t.screen + ' ' + t.month).join(', ') + ')' : 'every month names the one aimed at')
  + '; closest: ' + closest.map((t) => t.screen + ' ' + t.month + ' ' + t.share + '%').join(', '));
export { ties };
