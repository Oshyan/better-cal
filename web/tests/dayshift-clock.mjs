// A move by whole days keeps an event's time on the clock its times are on
// (dates.js ownClock, ui/monthmath.js shiftOccurrenceDays): its own zone's,
// or this device's for an event with the same clock time in any zone, whose
// times the server gives on the device's clock whatever zone it carries.
// Each case is a copy as the window holds it (its `tzid` included), moved a
// week across the US clock change of 2027-03-14, which Tokyo never has and
// London has two weeks later, so the two clocks part in that week. Each case
// sets the device's clock itself, and puts it back. Run: node
// web/tests/dayshift-clock.mjs (smoke.mjs imports it). Invented events only.

import assert from 'node:assert/strict';

const { shiftOccurrenceDays } = await import('../src/ui/monthmath.js');

const given = process.env.TZ;
const CASES = [
  {
    name: 'an event made in Tokyo, seen from Los Angeles, stays 9:00 in Tokyo',
    device: 'America/Los_Angeles',
    occ: { allDay: false, tzid: 'Asia/Tokyo', start: '2027-03-09T16:00:00-08:00', end: '2027-03-09T17:00:00-08:00' },
    want: { start: '2027-03-17T09:00:00+09:00', end: '2027-03-17T10:00:00+09:00' },
  },
  {
    name: 'a same-clock-time event stored in Los Angeles, seen from Tokyo, stays 9:00 on the device',
    device: 'Asia/Tokyo',
    occ: { allDay: false, floating: true, tzid: 'America/Los_Angeles', start: '2027-03-10T09:00:00+09:00', end: '2027-03-10T10:00:00+09:00' },
    want: { start: '2027-03-17T09:00:00+09:00', end: '2027-03-17T10:00:00+09:00' },
  },
  {
    name: 'a same-clock-time event stored in Los Angeles, seen from London, stays 9:00 on the device',
    device: 'Europe/London',
    occ: { allDay: false, floating: true, tzid: 'America/Los_Angeles', start: '2027-03-10T09:00:00+00:00', end: '2027-03-10T10:00:00+00:00' },
    want: { start: '2027-03-17T09:00:00+00:00', end: '2027-03-17T10:00:00+00:00' },
  },
  {
    name: 'an event in the device\'s own zone stays 9:00 across its own change',
    device: 'America/Los_Angeles',
    occ: { allDay: false, tzid: 'America/Los_Angeles', start: '2027-03-10T09:00:00-08:00', end: '2027-03-10T10:00:00-08:00' },
    want: { start: '2027-03-17T09:00:00-07:00', end: '2027-03-17T10:00:00-07:00' },
  },
];

let n = 0;
try {
  for (const c of CASES) {
    process.env.TZ = c.device;
    const { newStart, newEnd } = shiftOccurrenceDays(c.occ, 7);
    // Compared as instants: the same moment whatever offset it is written with.
    assert.deepEqual([Date.parse(newStart), Date.parse(newEnd)], [Date.parse(c.want.start), Date.parse(c.want.end)],
      c.name + ': got ' + newStart + ' to ' + newEnd);
    n++;
  }
} finally {
  if (given === undefined) delete process.env.TZ; else process.env.TZ = given;
}
console.log('Day moves: ' + n + ' cases keep the clock their times are on');
