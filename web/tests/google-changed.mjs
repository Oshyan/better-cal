// A change or delete of one event that changed in Google Calendar since it
// was shown (docs/design/google-conflict-choice.md, slice 1): every sentence
// the person reads, in one place to be read as prose, then the one sender
// (web/src/app/googlechange.js): `over` from what was shown, the question
// asked again with newer values, Overwrite as the same request with `over`
// set to Google's values, Cancel sending nothing. Run: node web/tests/google-changed.mjs
// (smoke.mjs imports it). Invented events only.

import assert from 'node:assert/strict';
import { googleChangedAsk, keptWords, stoppedWords, timeValueWords, useSeriesWords, stillOwnWords, useSeriesLabel } from '../src/lib/planwords.js';
import { toISOWithOffset } from '../src/lib/dates.js';

// A fixed today, so a date in another year says its year whatever year the
// test runs in.
const now = new Date(2026, 10, 14);
const ask = (a) => googleChangedAsk({ name: 'Team sync', now, ...a });
// A test of a sentence fixes the clock its times are shown on: a timed value
// is a wall-clock time on this device's clock, as the app serves one in the
// person's zone, so "10:00 AM" reads 10:00 AM in every zone the suite runs in.
const at = (day, hhmm) => {
  const [y, mo, d] = day.split('-').map(Number);
  const [h, mi] = hhmm.split(':').map(Number);
  return toISOWithOffset(new Date(y, mo - 1, d, h, mi));
};
const timed = (day, from, to) => ({ start: at(`2026-11-${day}`, from), end: at(`2026-11-${day}`, to), allDay: false });
const allDay = (day) => ({ start: `2026-11-${day}T00:00:00+00:00`, end: `2026-11-${Number(day) + 1}T00:00:00+00:00`, allDay: true });
const notesThere = '<p>Bring the agenda and the slides for the quarterly review, and the <b>printed</b> handouts for everyone who asked for one at the last meeting.</p>';
const notesYours = '<p>Bring the agenda for the quarterly review, and the <b>printed</b> handouts for everyone who asked for one at the last meeting.</p>';
const OVERWRITE_BACK = 'Overwrite saves your change. Cancel saves nothing and takes you back to your change.';
const OVERWRITE_STAY = 'Overwrite saves your change. Cancel saves nothing, and the event shows as it is in Google Calendar now.';
const DELETE_FOOT = 'Delete deletes it here and in Google Calendar. Cancel keeps it, as it is in Google Calendar now.';

// ---- the server's table ----------------------------------------------------------
//
// The same fields give the same sentences on both sides: the server's 409
// message says what this dialog says (server/tests/fixtures/google-changed-words.json,
// read by server/tests/google-conflict-words.php too). The server says a time
// on the clock it is served on; here each timed value is that same wall clock
// on this device's clock, so every row is checked in every zone. Which clock a
// time is shown on (the event's or the person's) is not what this table checks.
{
  const { readFileSync } = await import('node:fs');
  const table = JSON.parse(readFileSync(new URL('../../server/tests/fixtures/google-changed-words.json', import.meta.url), 'utf8'));
  const [y, m, d] = table.now.split('-').map(Number);
  // A timed value keeps its wall clock and takes this device's offset at it,
  // so the app reads the clock the server says it on, in any zone.
  const onThisClock = (t) => (t && !t.allDay ? { ...t, start: at(t.start.slice(0, 10), t.start.slice(11, 16)), end: at(t.end.slice(0, 10), t.end.slice(11, 16)) } : t);
  for (const row of table.rows) {
    const fields = row.fields.map((f) => (f.field === 'time' ? { ...f, was: onThisClock(f.was), now: onThisClock(f.now), yours: onThisClock(f.yours) } : f));
    // A day of a repeating event: the day it names on this clock too.
    const a = googleChangedAsk({ name: row.name, fields, remove: row.remove, day: row.day ? onThisClock(row.day) : null, now: new Date(y, m - 1, d) });
    assert.deepEqual({ body: a.body, lines: a.lines }, { body: row.body, lines: row.lines }, 'the server\'s table: ' + row.case);
  }
}

// Planned or Maybe is never called a status, in the sentences about a change
// to a repeating event too: with no value to say it by, it is named by both.
{
  const { changesWords, choiceWords, togetherLine } = await import('../src/lib/planwords.js');
  assert.equal(changesWords({ takes: ['title', 'status'] }), 'takes the new title and Planned or Maybe');
  assert.deepEqual(choiceWords({ id: 'ownStatus', field: 'status', count: 2, options: [{ id: 'keep' }, { id: 'give' }] }), {
    lead: '2 days that were changed earlier have their own Planned or Maybe.',
    reason: 'Keep their Planned or Maybe, or give them the new one too?',
    options: [{ id: 'keep', label: 'Keep their own Planned or Maybe' }, { id: 'give', label: 'Give them the new Planned or Maybe' }],
  });
  assert.equal(togetherLine([{ id: 'ownStatus', field: 'status' }], 2), '2 days have their own Planned or Maybe.');
}

// ---- the question, a field at a time ----------------------------------------

assert.deepEqual(ask({ back: true, fields: [{ field: 'title', was: 'Team sync', now: 'Standup', yours: 'Weekly sync' }] }), {
  title: 'The title was changed in Google Calendar',
  body: "Since 'Team sync' was last shown to you, its title was changed in Google Calendar. It is now 'Standup' there; your change makes it 'Weekly sync'.",
  lines: [],
  foot: OVERWRITE_BACK,
  confirmLabel: 'Overwrite', danger: false,
}, 'one field, from the editor');

assert.equal(ask({ fields: [{ field: 'location', was: 'Room 2', now: '', yours: 'Sample Hall' }] }).body,
  "Since 'Team sync' was last shown to you, its location was removed in Google Calendar; your change makes it 'Sample Hall'.",
  'a value removed in Google Calendar');

assert.equal(ask({ back: true, fields: [{ field: 'location', was: 'Room 2', now: 'Sample Hall', yours: null }] }).body,
  "Since 'Team sync' was last shown to you, its location was changed in Google Calendar. It is now 'Sample Hall' there; your change removes it.",
  'a value the change removes');

const time = ask({ fields: [{ field: 'time', was: timed(17, '10:00', '11:00'), now: allDay(17), yours: timed(18, '14:00', '15:00') }] });
assert.equal(time.title, 'The time was changed in Google Calendar');
assert.equal(time.body,
  "Since 'Team sync' was last shown to you, its time was changed in Google Calendar. It is now all day on Tue, Nov 17 there; your change makes it Wed, Nov 18, 2:00 PM to 3:00 PM.",
  'a time, made all-day at Google, in the person\'s formats, no year this year');
assert.equal(time.foot, OVERWRITE_STAY, 'a drag: Cancel leaves the event as Google has it');

assert.equal(ask({ fields: [{ field: 'time', was: timed(17, '10:00', '11:00'), now: { start: '2027-01-05T00:00:00+00:00', end: '2027-01-06T00:00:00+00:00', allDay: true }, yours: timed(18, '18:00', '19:00') }] }).body,
  "Since 'Team sync' was last shown to you, its time was changed in Google Calendar. It is now all day on Tue, Jan 5, 2027 there; your change makes it Wed, Nov 18, 2026, 6:00 PM to 7:00 PM.",
  'two times in another year than each other: both say the year');

assert.equal(ask({ fields: [{ field: 'status', was: 'confirmed', now: 'tentative', yours: 'confirmed' }] }).body,
  "Since 'Team sync' was last shown to you, it was changed in Google Calendar. It is now a Maybe there; your change makes it Planned.",
  'Planned or Maybe, never called a status');

const notes = ask({ back: true, fields: [{ field: 'description', was: '<p>Bring the agenda.</p>', now: notesThere, yours: notesYours }] });
assert.equal(notes.title, 'The notes were changed in Google Calendar');
assert.equal(notes.body,
  "Since 'Team sync' was last shown to you, its notes were changed in Google Calendar. They are now 'Bring the agenda and the slides for the quarterly review, and the printed…' there; "
  + "your change makes them 'Bring the agenda for the quarterly review, and the printed handouts for everyone…'.",
  'long notes: as text, not markup, cut at a word');

const later = 'The room is booked from nine, and the projector is in the cupboard by the door. ';
const cutLate = ask({ fields: [{ field: 'description', was: '', now: later + 'Bring the agenda and the slides.', yours: later + 'Bring the agenda.' }] });
assert.equal(cutLate.body,
  "Since 'Team sync' was last shown to you, its notes were changed in Google Calendar. They are now '…Bring the agenda and the slides' there; your change makes them '…Bring the agenda'.",
  'notes that differ late: cut from a little before where they differ');

assert.equal(ask({ back: true, fields: [{ field: 'description', was: '<p>x</p>', now: '<p>Bring the agenda and the slides.</p>', yours: '' }] }).body,
  "Since 'Team sync' was last shown to you, its notes were changed in Google Calendar. They are now 'Bring the agenda and the slides.' there; your change removes them.",
  'notes the change removes');

// ---- several fields -----------------------------------------------------------

assert.deepEqual(ask({ back: true, fields: [
  { field: 'title', was: 'Team sync', now: 'Standup', yours: 'Weekly sync' },
  { field: 'location', was: 'Room 2', now: 'Sample Hall', yours: '' },
  { field: 'status', was: 'confirmed', now: 'tentative', yours: 'confirmed' },
  { field: 'time', was: timed(17, '10:00', '11:00'), now: timed(17, '10:30', '11:30'), yours: timed(18, '10:00', '11:00') },
] }), {
  title: 'Changed in Google Calendar',
  body: "Since 'Team sync' was last shown to you, these were changed in Google Calendar:",
  lines: [
    "Title: 'Standup' in Google Calendar; 'Weekly sync' in your change.",
    "Location: 'Sample Hall' in Google Calendar; no location in your change.",
    'Planned or Maybe: a Maybe in Google Calendar; Planned in your change.',
    'Time: Tue, Nov 17, 10:30 AM to 11:30 AM in Google Calendar; Wed, Nov 18, 10:00 AM to 11:00 AM in your change.',
  ],
  foot: OVERWRITE_BACK,
  confirmLabel: 'Overwrite', danger: false,
}, 'several fields, a line each');

// ---- a delete ---------------------------------------------------------------

assert.deepEqual(ask({ remove: true, fields: [{ field: 'location', was: 'Room 2', now: 'Sample Hall', yours: null }] }), {
  title: 'Delete it anyway?',
  body: "Since 'Team sync' was last shown to you, its location was changed in Google Calendar. It was 'Room 2'; it is now 'Sample Hall'.",
  lines: [],
  foot: DELETE_FOOT,
  confirmLabel: 'Delete', danger: true,
}, 'a delete over one change');
assert.equal(ask({ remove: true, fields: [{ field: 'url', was: null, now: 'https://example.org/agenda', yours: null }] }).body,
  "Since 'Team sync' was last shown to you, its link was added in Google Calendar; it is now 'https://example.org/agenda'.", 'a delete over a value added');
assert.equal(ask({ remove: true, fields: [{ field: 'location', was: 'Room 2', now: null, yours: null }] }).body,
  "Since 'Team sync' was last shown to you, its location was removed in Google Calendar; it was 'Room 2'.", 'a delete over a value removed');
assert.equal(ask({ remove: true, fields: [{ field: 'status', was: 'confirmed', now: 'tentative', yours: null }] }).body,
  "Since 'Team sync' was last shown to you, it was changed in Google Calendar. It was Planned; it is now a Maybe.", 'a delete over Maybe');
assert.equal(ask({ remove: true, fields: [{ field: 'time', was: { start: at('2027-03-03', '10:00'), end: at('2027-03-03', '11:00'), allDay: false }, now: { start: '2027-03-03T00:00:00+00:00', end: '2027-03-04T00:00:00+00:00', allDay: true }, yours: null }] }).body,
  "Since 'Team sync' was last shown to you, its time was changed in Google Calendar. It was Wed, Mar 3, 2027, 10:00 AM to 11:00 AM; it is now all day on Wed, Mar 3, 2027.",
  'a delete over a time made all-day: read once through, the year on both sides');
assert.equal(ask({ remove: true, fields: [{ field: 'description', was: '<p>Bring the agenda.</p>', now: '<p>Bring the agenda and slides.</p>', yours: null }] }).body,
  "Since 'Team sync' was last shown to you, its notes were changed in Google Calendar. They were 'Bring the agenda.'; they are now 'Bring the agenda and slides.'",
  'a quoted value that ends its own sentence takes no second full stop');
assert.deepEqual(ask({ remove: true, fields: [
  { field: 'title', was: 'Team sync', now: 'Standup', yours: null },
  { field: 'url', was: 'https://example.org/agenda', now: null, yours: null },
  { field: 'status', was: 'tentative', now: 'confirmed', yours: null },
] }).lines, [
  "Title: was 'Team sync'; now 'Standup'.",
  "Link: was 'https://example.org/agenda'; now no link.",
  'Planned or Maybe: was a Maybe; now Planned.',
], 'a delete over several changes');

// ---- one day of a repeating event (slice 2) -------------------------------------
//
// The lead names the day, a change made to the whole repeating event says
// so, and Overwrite or Delete is for this day only.

const day17 = { ...timed(17, '10:00', '11:00'), tzid: 'America/Los_Angeles' };
const DAY_BACK = 'Overwrite saves your change for this day only. Cancel saves nothing and takes you back to your change.';
const DAY_STAY = 'Overwrite saves your change for this day only. Cancel saves nothing, and the day shows as it is in Google Calendar now.';
const DAY_DELETE_FOOT = 'Delete deletes this day here and in Google Calendar. Cancel keeps it, as it is in Google Calendar now.';
assert.deepEqual(ask({ day: day17, back: true, fields: [{ field: 'title', was: 'Team sync', now: 'Standup', yours: 'Weekly sync', from: 'series' }] }), {
  title: 'The title was changed in Google Calendar',
  body: "Since Tue, Nov 17 of 'Team sync' was last shown to you, its title was changed in Google Calendar, for the whole repeating event. It is now 'Standup' there; your change makes it 'Weekly sync'.",
  lines: [],
  foot: DAY_BACK,
  confirmLabel: 'Overwrite', danger: false,
}, 'a day, from the editor: the day named, the change to the whole repeating event said, Overwrite for this day only');
assert.deepEqual(ask({ day: day17, fields: [{ field: 'time', was: timed(17, '10:00', '11:00'), now: timed(17, '10:30', '11:30'), yours: timed(18, '10:00', '11:00'), from: 'day' }] }), {
  title: 'The time was changed in Google Calendar',
  body: "Since Tue, Nov 17 of 'Team sync' was last shown to you, its time was changed in Google Calendar. It is now Tue, Nov 17, 10:30 AM to 11:30 AM there; your change makes it Wed, Nov 18, 10:00 AM to 11:00 AM.",
  lines: [],
  foot: DAY_STAY,
  confirmLabel: 'Overwrite', danger: false,
}, 'a day dragged: its own record changed reads as one event\'s, Cancel leaves the day as Google has it');
assert.deepEqual(ask({ day: day17, remove: true, fields: [{ field: 'location', was: 'Room 2', now: 'Sample Hall', yours: null, from: 'day' }] }), {
  title: 'Delete this day anyway?',
  body: "Since Tue, Nov 17 of 'Team sync' was last shown to you, its location was changed in Google Calendar. It was 'Room 2'; it is now 'Sample Hall'.",
  lines: [],
  foot: DAY_DELETE_FOOT,
  confirmLabel: 'Delete', danger: true,
}, 'a delete of a day');
assert.deepEqual(ask({ day: day17, remove: true, fields: [{ field: 'title', was: 'Team sync', now: 'Standup', yours: null, from: 'series' }, { field: 'location', was: 'Room 2', now: null, yours: null, from: 'day' }] }).lines, [
  "Title, for the whole repeating event: was 'Team sync'; now 'Standup'.",
  'Location: was \'Room 2\'; now no location.',
], 'a delete of a day over several changes: a line says which was the whole repeating event\'s');
// The day is one of the question's dates: in another year, it says its year,
// and so does every time beside it.
assert.equal(ask({ day: { ...timed(17, '10:00', '11:00'), start: at('2027-01-05', '10:00'), end: at('2027-01-05', '11:00') },
  fields: [{ field: 'time', was: { start: at('2027-01-05', '10:00'), end: at('2027-01-05', '11:00'), allDay: false }, now: { start: at('2027-01-05', '10:30'), end: at('2027-01-05', '11:30'), allDay: false }, yours: { start: at('2027-01-06', '10:00'), end: at('2027-01-06', '11:00'), allDay: false }, from: 'day' }] }).body,
  "Since Tue, Jan 5, 2027 of 'Team sync' was last shown to you, its time was changed in Google Calendar. It is now Tue, Jan 5, 2027, 10:30 AM to 11:30 AM there; your change makes it Wed, Jan 6, 2027, 10:00 AM to 11:00 AM.",
  'a day in another year: the day and the times say it');
assert.equal(ask({ day: day17, fields: [{ field: 'time', was: timed(17, '10:00', '11:00'), now: { start: '2027-01-05T00:00:00+00:00', end: '2027-01-06T00:00:00+00:00', allDay: true }, yours: timed(18, '10:00', '11:00'), from: 'day' }] }).body,
  "Since Tue, Nov 17, 2026 of 'Team sync' was last shown to you, its time was changed in Google Calendar. It is now all day on Tue, Jan 5, 2027 there; your change makes it Wed, Nov 18, 2026, 10:00 AM to 11:00 AM.",
  'a day this year beside a time in another: the day says its year too');
for (const a of [ask({ day: day17, fields: [{ field: 'title', was: 'a', now: 'b', yours: 'c', from: 'series' }] }), ask({ day: day17, remove: true, fields: [{ field: 'title', was: 'a', now: 'b', yours: null, from: 'series' }] })]) {
  const prose = [a.title, a.body, ...a.lines, a.foot].join(' ');
  assert.doesNotMatch(prose, /series|at Google|\u2014/, 'a day\'s question: never "series", never "at Google", no dash: ' + prose);
}
// An event in no series is said as it always was, `from` or not.
assert.equal(ask({ fields: [{ field: 'title', was: 'Team sync', now: 'Standup', yours: 'Weekly sync' }] }).foot, OVERWRITE_STAY);
assert.equal(ask({ remove: true, fields: [{ field: 'title', was: 'Team sync', now: 'Standup', yours: null }] }).title, 'Delete it anyway?');

// ---- kept, on the toast -------------------------------------------------------
//
// Under a line that names Google Calendar ("Title changed, here and in Google
// Calendar") the kept line says "there"; under one that doesn't, it names
// Google Calendar itself.

const kw = (kept, there = true) => keptWords(kept, { there, now });
assert.equal(kw([]), null, 'nothing kept: the toast is as always');
assert.equal(kw(undefined), null, 'no kept at all (the setting off): the toast is as always');
assert.equal(kw([{ field: 'title', now: 'Team sync' }]), "A separate change there was kept: the title is now 'Team sync'.");
assert.equal(kw([{ field: 'title', now: 'Team sync' }], false), "A separate change in Google Calendar was kept: the title is now 'Team sync'.",
  'under a line that does not name Google Calendar, the kept line names it');
assert.equal(kw([{ field: 'status', now: 'tentative' }]), 'A separate change there was kept: it is now a Maybe.');
assert.equal(kw([{ field: 'status', now: 'confirmed' }, { field: 'title', now: 'Standup' }]),
  "Separate changes there were kept: it is now Planned and the title is now 'Standup'.");
assert.equal(kw([{ field: 'location', now: 'Sample Hall' }, { field: 'url', now: '' }]),
  "Separate changes there were kept: the location is now 'Sample Hall' and the link was removed.");
assert.equal(kw([{ field: 'time', now: timed(17, '10:00', '11:00') }, { field: 'location', now: null }, { field: 'description', now: notesThere }]),
  'Separate changes there were kept: the time is now Tue, Nov 17, 10:00 AM to 11:00 AM; the location was removed; and the notes were changed.',
  'a time with commas, and notes too long to repeat');

assert.equal(stoppedWords(['Sample hike', 'Sample dinner']), "Stopped. Already deleted: 'Sample hike' and 'Sample dinner'.");
assert.equal(timeValueWords(allDay(17), now), 'all day on Tue, Nov 17');

console.log('Google changed: sentences read as written');

// ---- the one sender ------------------------------------------------------------

const { overOf, sendEventChange, CANCELLED, keptOf, failWords } = await import('../src/app/googlechange.js');
const { state, set, subscribe, toast } = await import('../src/app/store.js');
const { ApiError } = await import('../src/app/api.js');

const shown = {
  instanceId: 'e7', eventId: 7, calendarId: 3, recurring: false, title: 'Team sync', location: 'Room 2', url: null,
  status: 'confirmed', start: at('2026-11-17', '10:00'), end: at('2026-11-17', '11:00'), allDay: false, tzid: 'America/New_York',
};
// A time as one value, as the API serves it: start, end, all-day and zone.
const shownTime = { start: shown.start, end: shown.end, allDay: false, tzid: 'America/New_York' };
assert.deepEqual(overOf(shown, { title: 'Weekly sync', tagNames: ['x'] }), { title: 'Team sync' }, 'only the fields the request changes');
assert.deepEqual(overOf(shown, { start: 'a', end: 'b' }), shownTime, 'a time sends its four keys, from what was shown');
assert.deepEqual(overOf(shown, { tzid: 'Asia/Tokyo' }), shownTime, 'a zone alone is the time too: the whole time as shown');
assert.throws(() => overOf({ ...shown, tzid: undefined }, { start: 'a', end: 'b' }), /without its time zone/, 'a timed copy shown with no zone is a fault, said, never named in part');
assert.deepEqual(overOf({ ...shown, allDay: true, start: '2026-11-17T00:00:00+00:00', end: '2026-11-20T00:00:00+00:00', tzid: undefined }, { start: 'a' }),
  { start: '2026-11-17T00:00:00+00:00', end: '2026-11-20T00:00:00+00:00', allDay: true, tzid: 'UTC' }, 'an all-day time is dates, its zone UTC');
assert.deepEqual(overOf(shown, { relationship: 'maybe' }), { status: 'confirmed' }, 'Planned or Maybe is the status');
assert.deepEqual(overOf(shown, { description: '<p>New</p>' }), {}, 'notes the window never held are left to the server');
assert.deepEqual(overOf({ ...shown, description: null }, { description: '<p>New</p>' }), { description: null }, 'notes the record held, even empty');
assert.deepEqual(Object.keys(overOf(shown, {}, { remove: true })).sort(), ['allDay', 'end', 'location', 'start', 'status', 'title', 'tzid', 'url'], 'a delete: every field held');
assert.deepEqual(overOf({ ...shown, recurring: true }, { title: 'x', scope: 'all' }), {}, 'all events of a repeating event: none');
assert.deepEqual(overOf({ ...shown, recurring: true }, { title: 'x', scope: 'following' }), {}, 'this and following: none');
assert.deepEqual(overOf({ ...shown, recurring: true }, { title: 'x' }), {}, 'a repeating event with no scope (an answer): none');
assert.deepEqual(overOf({ ...shown, recurring: true }, { title: 'x', scope: 'this', instanceStart: shown.start }), { title: 'Team sync' }, 'one day: over from the day as shown');
assert.deepEqual(overOf({ ...shown, recurring: true }, { scope: 'this', instanceStart: shown.start, useSeries: ['title', 'length', 'tags'] }), { title: 'Team sync', ...shownTime },
  'Use series value: the fields its names mean, its length the time whole; a field the server never compares names none');
assert.deepEqual(overOf(shown, { answer: 'ACCEPTED' }), {}, 'an answer to an invitation: none');

// A stand-in for the server: answers each request from a list, and records them.
const realFetch = globalThis.fetch;
const sent = [];
let answers = [];
const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
// What the server stored at a question, read back as the event's record
// (showStored); `reads` counts those reads, apart from the requests sent.
let stored = null;
let reads = 0;
// A refetch of the window held until the test lets it go (`slowWindow`), as a
// large calendar's week is slow to fetch again: its first request waits.
let slowWindow = null;
globalThis.fetch = async (url, init = {}) => {
  const path = String(url).replace('/api/v1', '');
  if (path === '/events/7/occurrence' && (init.method || 'GET') === 'GET') { reads++; return json(200, { occurrence: stored }); }
  if (path === '/changes/cursor' && slowWindow) { slowWindow.asked = true; await slowWindow.gate; }
  if (!path.startsWith('/events/7')) return json(200, { events: [], cursor: null });
  sent.push({ path, method: init.method, body: init.body ? JSON.parse(init.body) : null });
  return answers.shift();
};
const changed = (fields, message = 'not changed') => json(409, { error: { code: 'google_changed', message, fields, kept: [] } });
// Answer the dialog when it shows: the store says so, no waiting on a clock.
const asked = [];
let reply = [];
const off = subscribe((st) => {
  const a = st.confirmAsk;
  if (!a || a._seen) return;
  a._seen = true;
  // What every view holds of the event while the question is open.
  const held = [...st.occ.values()].filter((o) => o.eventId === 7);
  asked.push({ title: a.title, body: a.body, lines: a.lines, foot: a.foot, confirmLabel: a.confirmLabel,
    held: held.map((o) => ({ title: o.title, start: o.start })), editor: !!st.editor, popover: st.popover && st.popover.instanceId });
  const yes = reply.shift();
  queueMicrotask(() => { set({ confirmAsk: null }); a.resolve(yes); });
});

// Asked, asked again with Google's newer value, then Overwrite goes through.
const t1 = { field: 'title', was: 'Team sync', now: 'Standup', yours: 'Weekly sync' };
const t2 = { ...t1, was: 'Standup', now: 'Daily standup' };
answers = [changed([t1]), changed([t2]), json(200, { instanceId: 'e7', kept: [{ field: 'location', now: 'Sample Hall' }] })];
reply = [true, true];
const res = await sendEventChange(shown, '/events/7', 'PATCH', { title: 'Weekly sync' });
assert.equal(sent.length, 3, 'three requests: the change, Overwrite, Overwrite again');
assert.deepEqual(sent.map((s) => s.body), [
  { title: 'Weekly sync', over: { title: 'Team sync' } },
  { title: 'Weekly sync', over: { title: 'Standup' } },
  { title: 'Weekly sync', over: { title: 'Daily standup' } },
], 'Overwrite is the same request with over set to what Google holds now');
assert.equal(asked[1].body, "Since 'Team sync' was last shown to you, its title was changed in Google Calendar. It is now 'Daily standup' there; your change makes it 'Weekly sync'.", 'the second question has the newer value');
assert.deepEqual(keptOf(res), [{ field: 'location', now: 'Sample Hall' }], 'the kept fields go to the toast');
assert.equal(reads, 2, 'the stored event is read at each question: each stored something newer');

// A time: Overwrite sets the four keys of over from Google's time.
sent.length = 0; asked.length = 0;
const now2 = { start: at('2026-11-17', '10:30'), end: at('2026-11-17', '11:30'), allDay: false, tzid: 'America/New_York' };
answers = [changed([{ field: 'time', was: shownTime, now: now2, yours: { start: at('2026-11-18', '10:00'), end: at('2026-11-18', '11:00'), allDay: false, tzid: 'America/New_York' } }]), json(200, { kept: [] })];
reply = [true];
const moved = await sendEventChange(shown, '/events/7', 'PATCH', { start: at('2026-11-18', '10:00'), end: at('2026-11-18', '11:00') });
assert.deepEqual(sent[0].body, { start: at('2026-11-18', '10:00'), end: at('2026-11-18', '11:00'), allDay: false, tzid: 'America/New_York', over: shownTime }, 'a drag sends its time whole: the kind and zone it was shown with');
assert.deepEqual(sent[1].body.over, now2, 'the time asked: over\'s start, end, all-day and zone are Google\'s');
assert.equal(keptOf(moved), null, 'empty kept: no line');

// Overwrite sends what the question showed as yours. A drag (start and end
// only) over an event Google made all-day: the repeat carries yours whole,
// allDay false with it, and over from Google's all-day time; the scope
// fields and anything else in the body stay as they were.
sent.length = 0; asked.length = 0;
const googleAllDay = { start: '2026-11-17T00:00:00+00:00', end: '2026-11-18T00:00:00+00:00', allDay: true, tzid: 'UTC' };
const yoursAt6 = { start: at('2026-11-17', '18:00'), end: at('2026-11-17', '19:00'), allDay: false, tzid: 'America/New_York' };
answers = [changed([{ field: 'time', was: shownTime, now: googleAllDay, yours: yoursAt6 }]), json(200, { kept: [] })];
reply = [true];
await sendEventChange(shown, '/events/7', 'PATCH', { start: yoursAt6.start, end: yoursAt6.end, tagNames: ['sample'] });
assert.equal(asked[0].body, "Since 'Team sync' was last shown to you, its time was changed in Google Calendar. It is now all day on Tue, Nov 17 there; your change makes it Tue, Nov 17, 6:00 PM to 7:00 PM.",
  'overwrite yours: the question said six o\'clock');
assert.deepEqual(sent[1].body, { ...yoursAt6, tagNames: ['sample'], over: googleAllDay },
  'overwrite yours: a time sends its four keys from yours, over from now');

// A plain field: the body's value is yours, over is now.
sent.length = 0; asked.length = 0;
answers = [changed([{ field: 'location', was: 'Room 2', now: 'Sample Hall', yours: 'Room 3' }]), json(200, { kept: [] })];
reply = [true];
await sendEventChange(shown, '/events/7', 'PATCH', { location: 'Room 3', locationLat: null, locationLng: null });
assert.deepEqual(sent[1].body, { location: 'Room 3', locationLat: null, locationLng: null, over: { location: 'Sample Hall' } },
  'overwrite yours: a plain field sends yours, over from now');

// Cancel: nothing more is sent, CANCELLED. The caller's own copy came off
// before the question opened, once.
sent.length = 0; asked.length = 0;
let reverted = 0;
answers = [changed([{ field: 'location', was: 'Room 2', now: 'Sample Hall', yours: null }])];
reply = [false];
const gone = await sendEventChange(shown, '/events/7', 'DELETE', {}, { remove: true, revert: () => { reverted++; } });
assert.equal(gone, CANCELLED);
assert.equal(sent.length, 1, 'Cancel sends nothing');
assert.equal(reverted, 1, 'the caller\'s copy taken off once');
assert.equal(asked[0].confirmLabel, 'Delete');
assert.equal(asked[0].foot, DELETE_FOOT);
assert.equal(sent[0].body.over.title, 'Team sync', 'a delete carries over for every field');

// A change made in a surface that stays open holding it: Cancel says it takes you back.
sent.length = 0; asked.length = 0;
answers = [changed([t1])];
reply = [false];
assert.equal(await sendEventChange(shown, '/events/7', 'PATCH', { title: 'Weekly sync' }, { back: true }), CANCELLED);
assert.equal(asked[0].foot, OVERWRITE_BACK);
answers = [changed([t1])];
reply = [false];
await sendEventChange(shown, '/events/7', 'PATCH', { title: 'Weekly sync' });
assert.equal(asked[1].foot, OVERWRITE_STAY, 'a drag: Cancel leaves the event as Google has it');

// A google_changed with no fields to ask about: thrown, its message whole.
const whole = "Since 'Team sync' was last shown to you, its title was changed in Google Calendar. It is now 'Standup' there; your change makes it 'Weekly sync'. It wasn't changed; open the event to see it and make the change again.";
answers = [json(409, { error: { code: 'google_changed', message: whole } })];
let thrown = null;
try { await sendEventChange(shown, '/events/7', 'PATCH', { title: 'Weekly sync' }); } catch (e) { thrown = e; }
assert.ok(thrown instanceof ApiError);
assert.equal(failWords('Update failed: ', thrown), whole);
assert.equal(failWords('Update failed: ', new ApiError('error', 'Server error', 500)), 'Update failed: Server error', 'any other failure as before');

// The setting off: a plain 200, one request, no question, nothing kept.
sent.length = 0; asked.length = 0;
answers = [json(200, { instanceId: 'e7' })];
const plain = await sendEventChange(shown, '/events/7', 'PATCH', { title: 'Weekly sync' });
assert.equal(sent.length, 1);
assert.equal(asked.length, 0);
assert.equal(keptOf(plain), null);

console.log('Google changed: one sender, over from what was shown, Overwrite and Cancel');

// ---- the callers: what shows while the question is open, and after -----------------
//
// The question interrupts the action (nothing of it shows first) and the
// screen behind it shows what the server stored (Google's values), never the
// copy from before the action; Cancel leaves both as they are.

const { deleteEvent, moveEvent, resizeEvent, updateEvent, setRelationship } = await import('../src/app/actions.js');
state.calendars = [{ id: 3, name: 'Sample team', provider: 'google', editable: true, visible: true, role: 'mine' }];
const put = (o) => { for (const [id, x] of state.occ) if (x.eventId === 7) state.occ.delete(id); state.occ.set(o.instanceId, o); };
const googleTime = { start: at('2026-11-19', '15:00'), end: at('2026-11-19', '16:00'), tzid: 'America/New_York' };
const atGoogle = (extra = {}) => ({ ...shown, ...extra, instanceId: '7:google' });

// A delete from the editor: the editor and the event stay while asked, and after Cancel.
sent.length = 0; asked.length = 0; reads = 0;
put(shown);
set({ editor: { mode: 'edit', occ: shown }, popover: null });
stored = atGoogle({ location: 'Sample Hall' });
answers = [changed([{ field: 'location', was: 'Room 2', now: 'Sample Hall', yours: null }])];
reply = [false];
await deleteEvent(shown);
assert.equal(asked[0].editor, true, 'A: the editor is open behind the question');
assert.deepEqual(asked[0].held, [{ title: 'Team sync', start: shown.start }], 'A: the event is on the calendar behind the question, as stored');
assert.ok(state.editor, 'A: Cancel leaves the editor open');
assert.equal(state.occ.get('7:google').location, 'Sample Hall', 'B: after Cancel the event shows as Google has it');
// Delete at the question: then it goes, and the editor closes.
answers = [changed([{ field: 'location', was: 'Room 2', now: 'Sample Hall', yours: null }]), json(200, { undo: [] })];
reply = [true];
await deleteEvent(shown);
assert.equal(state.editor, null, 'A: deleted, the editor closes');
assert.equal([...state.occ.values()].filter((o) => o.eventId === 7).length, 0, 'A: deleted, the event leaves the calendar');

// A delete from the panel: the panel stays open over the question, and follows the event.
sent.length = 0; asked.length = 0;
put(shown);
set({ editor: null, popover: { instanceId: shown.instanceId } });
stored = atGoogle({ ...googleTime });
answers = [changed([{ field: 'time', was: shownTime, now: { ...googleTime, allDay: false }, yours: null }])];
reply = [false];
await deleteEvent(shown);
assert.equal(asked[0].popover, '7:google', 'A, B: the panel is open behind the question, on the event as stored');
assert.equal(state.popover && state.popover.instanceId, '7:google', 'A: Cancel leaves the panel open');

// An edit: the calendar behind the question shows Google's title, and after Cancel.
sent.length = 0; asked.length = 0;
put(shown);
set({ popover: null, editor: { mode: 'edit', occ: shown } });
stored = atGoogle({ title: 'Standup' });
answers = [changed([t1])];
reply = [false];
assert.equal(await updateEvent(shown, { title: 'Weekly sync' }), false);
assert.deepEqual(asked[0].held.map((h) => h.title), ['Standup'], 'B: the grid behind the question shows Google\'s title');
assert.equal(asked[0].foot, OVERWRITE_BACK, 'the editor holds the change: Cancel takes you back');
assert.deepEqual([...state.occ.values()].filter((o) => o.eventId === 7).map((o) => o.title), ['Standup'], 'B: and after Cancel');
set({ editor: null });

// A drag, a resize, to another day: the dropped block shows Google's time
// while asked and after Cancel, never the time from before the drag, and a
// failure after the question doesn't put that back either.
for (const [act, fail] of [[moveEvent, false], [resizeEvent, false], [moveEvent, true]]) {
  sent.length = 0; asked.length = 0;
  put(shown);
  stored = atGoogle({ ...googleTime });
  const time = { field: 'time', was: shownTime, now: { ...googleTime, allDay: false }, yours: { start: at('2026-11-21', '10:00'), end: at('2026-11-21', '11:00'), allDay: false, tzid: 'America/New_York' } };
  answers = fail ? [changed([time]), json(500, { error: { code: 'server', message: 'Server error' } })] : [changed([time])];
  reply = [fail];
  await act({ instanceId: shown.instanceId, newStart: time.yours.start, newEnd: time.yours.end });
  assert.deepEqual(asked[0].held, [{ title: 'Team sync', start: googleTime.start }], 'B: the block shows Google\'s time behind the question');
  assert.deepEqual([...state.occ.values()].filter((o) => o.eventId === 7).map((o) => o.start), [googleTime.start],
    'B: ' + (fail ? 'after a failed Overwrite' : 'after Cancel') + ', Google\'s time, not the one from before the drag');
}

// Planned or Maybe: the mark taken off, the event as stored.
sent.length = 0; asked.length = 0;
put(shown);
stored = atGoogle({ title: 'Standup' });
answers = [changed([t1])];
reply = [false];
assert.equal(await setRelationship(shown, 'maybe'), null);
assert.deepEqual([...state.occ.values()].filter((o) => o.eventId === 7).map((o) => [o.title, o.relationship]), [['Standup', undefined]], 'B: Cancel shows the event as stored, without the Maybe');

// D: the toast after a change sent to Google Calendar names it once, and the kept line says "there".
sent.length = 0; asked.length = 0;
put(shown);
set({ toasts: [] });
answers = [json(200, { kept: [{ field: 'location', now: 'Sample Hall' }] })];
await updateEvent(shown, { title: 'Design crit' }, null, ['title']);
const said = state.toasts[state.toasts.length - 1];
assert.equal(said.title, 'Design crit');
assert.equal(said.text, 'Title changed, here and in Google Calendar');
assert.equal(said.kept, "A separate change there was kept: the location is now 'Sample Hall'.");
toast('Accepted. The reply went to the organizer.', { kept: [{ field: 'title', now: 'Standup' }] });
assert.equal(state.toasts[state.toasts.length - 1].kept, "A separate change in Google Calendar was kept: the title is now 'Standup'.",
  'D: under a line that does not name Google Calendar, the kept line names it');

console.log('Google changed: the question interrupts, and every view shows what Google holds');

// ---- the editor: its fields are the person's, and an answer shows at once ---------
//
// R1: nothing that refreshes the event under an open editor makes it another
// opening (EditorDrawer seeds its form once per opening, sameOpening), and
// the base its save sends as `over` is what the person has been shown: the
// copy it opened with, then what a question showed (editorBase). R2: an
// answer that carries the saved event puts it in every view before the
// caller goes on, so the editor closes, the grid shows it and the toast
// says it together, with the refetch of the window still on its way.

const { sameOpening, editorBase } = await import('../src/lib/editfields.js');
const heldOf7 = () => [...state.occ.values()].filter((o) => o.eventId === 7);
// The event as the server stores it after a save of `extra`, served whole.
const savedAs = (extra) => ({ ...shown, ...extra, instanceId: '7:saved' });
// Let a held refetch go, once whatever waits on it has had every chance to
// run: turns of the event loop, counted, never a clock.
const turns = async (until, max = 200) => { for (let i = 0; i < max && !until(); i++) await new Promise((r) => setImmediate(r)); };

// The editor opened from the panel on 'Team sync'; the person types "Weekly
// sync" and saves; Google holds "Standup". The question opens, Cancel.
sent.length = 0; asked.length = 0; reads = 0;
put(shown);
const opened = { mode: 'edit', occ: shown, returnTo: { instanceId: shown.instanceId } };
set({ popover: null, editor: opened, toasts: [] });
stored = atGoogle({ title: 'Standup' });
answers = [changed([t1])];
reply = [false];
assert.equal(await updateEvent(editorBase(state.editor), { title: 'Weekly sync' }, null, ['title']), false, 'R1: Cancel saves nothing');
assert.deepEqual(heldOf7().map((o) => o.title), ['Standup'], 'the grid behind shows Google\'s title');
assert.ok(sameOpening(opened, state.editor), 'R1: the question and the event read back under the editor are not another opening: its form stays the person\'s');
assert.equal(state.editor.returnTo.instanceId, '7:google', 'Back from the editor returns to the event where it now is');
assert.equal(editorBase(state.editor).title, 'Standup', 'R1: the editor\'s base is now what the question showed');
assert.equal(editorBase(state.editor).location, 'Room 2', 'R1: a field the question did not show stays as the editor opened with it');

// Save again after Cancel: the person has seen 'Standup' (the question showed
// it), so the same change goes through without asking, with over from it.
sent.length = 0; asked.length = 0;
answers = [json(200, { kept: [], occurrence: savedAs({ title: 'Weekly sync' }) })];
assert.equal(await updateEvent(editorBase(state.editor), { title: 'Weekly sync' }, null, ['title']), true, 'Save after Cancel goes through');
assert.equal(asked.length, 0, 'not asked again: what Google holds was shown');
assert.deepEqual(sent.map((s) => s.body), [{ title: 'Weekly sync', over: { title: 'Standup' } }], 'over is what the question showed');
set({ editor: null });

// The panel's time fields close as they send: with no editor holding the
// change, Cancel leaves the event as Google has it, and the question says so.
sent.length = 0; asked.length = 0;
put(shown);
set({ editor: null, popover: { instanceId: shown.instanceId } });
stored = atGoogle({ ...googleTime });
answers = [changed([{ field: 'time', was: shownTime, now: { ...googleTime, allDay: false }, yours: { start: at('2026-11-21', '10:00'), end: at('2026-11-21', '11:00'), allDay: false, tzid: 'America/New_York' } }])];
reply = [false];
assert.equal(await updateEvent(shown, { start: at('2026-11-21', '10:00'), end: at('2026-11-21', '11:00') }), false);
assert.equal(asked[0].foot, OVERWRITE_STAY, 'from the panel, Cancel does not promise a way back to a change it no longer holds');
set({ popover: null });

// Overwrite at the question, with the refetch of the window slow: the save
// resolves on the answer, with the grid holding the saved event and the
// toast up, while the refetch has not come back.
sent.length = 0; asked.length = 0;
put(shown);
set({ editor: { mode: 'edit', occ: shown, returnTo: { instanceId: shown.instanceId } }, toasts: [] });
stored = atGoogle({ title: 'Standup' });
answers = [changed([t1]), json(200, { kept: [], occurrence: savedAs({ title: 'Weekly sync' }) })];
reply = [true];
let letGo;
slowWindow = { asked: false, gate: new Promise((r) => { letGo = r; }) };
let settled = null;
const saving = updateEvent(editorBase(state.editor), { title: 'Weekly sync' }, null, ['title']).then((v) => { settled = { v, held: heldOf7().map((o) => o.title), toast: state.toasts.map((x) => x.text), windowBack: false }; });
await turns(() => settled);
const windowWasAsked = slowWindow.asked;
const early = settled; // before the refetch is let go
letGo();
slowWindow = null;
await saving;
assert.ok(early, 'R2: the save resolves on the answer, not on the refetch of the window');
assert.equal(settled.v, true, 'R2: saved, so the editor closes');
assert.deepEqual(settled.held, ['Weekly sync'], 'R2: the grid holds the saved event when the editor closes');
assert.deepEqual(settled.toast, ['Title changed, here and in Google Calendar'], 'R2: the toast is up when the editor closes');
assert.ok(windowWasAsked, 'the refetch was asked for, and only confirms');
set({ editor: null });

// A drag Overwritten at the question: the block shows the saved time on the
// answer, not Google's time until a refetch.
sent.length = 0; asked.length = 0;
put(shown);
stored = atGoogle({ ...googleTime });
{
  const time = { field: 'time', was: shownTime, now: { ...googleTime, allDay: false }, yours: { start: at('2026-11-21', '10:00'), end: at('2026-11-21', '11:00'), allDay: false, tzid: 'America/New_York' } };
  answers = [changed([time]), json(200, { kept: [], occurrence: savedAs({ start: time.yours.start, end: time.yours.end }) })];
  reply = [true];
  slowWindow = { asked: false, gate: new Promise((r) => { letGo = r; }) };
  let dragged = null;
  const dragging = moveEvent({ instanceId: shown.instanceId, newStart: time.yours.start, newEnd: time.yours.end }).then(() => { dragged = heldOf7().map((o) => o.start); });
  await turns(() => dragged);
  const early = dragged;
  letGo();
  slowWindow = null;
  await dragging;
  assert.deepEqual(early, [time.yours.start], 'R2: after Overwrite the block is at the saved time on the answer');
}

// A drag of one day of a repeating event asked about: no record is put in
// place for a day (one record serves many days), so the question waits for
// the window's own answer, which shows the day where Google Calendar has it;
// it never opens over the block at its place from before the drag. Then
// Overwrite: the same request again, naming the same day.
sent.length = 0; asked.length = 0;
{
  const dayShown = { ...shown, instanceId: '7:day', recurring: true };
  put(dayShown);
  stored = { ...dayShown };
  const yours = { start: at('2026-11-21', '10:00'), end: at('2026-11-21', '11:00'), allDay: false, tzid: 'America/New_York' };
  const time = { field: 'time', was: shownTime, now: { ...googleTime, allDay: false }, yours, from: 'day' };
  answers = [json(409, { error: { code: 'google_changed', message: 'not changed', day: shownTime, fields: [time], kept: [] } }), json(200, { kept: [] })];
  reply = [true];
  slowWindow = { asked: false, gate: new Promise((r) => { letGo = r; }) };
  const dragging = moveEvent({ instanceId: dayShown.instanceId, newStart: yours.start, newEnd: yours.end });
  await turns(() => slowWindow.asked);
  for (let i = 0; i < 20; i++) await new Promise((r) => setImmediate(r));
  assert.equal(asked.length, 0, 'a day: the question waits for the window\'s answer, which shows the day as stored');
  letGo();
  slowWindow = null;
  await dragging;
  assert.equal(asked.length, 1, 'a day: then the question');
  assert.match(asked[0].body, /^Since .+ of 'Team sync' was last shown to you, its time was changed in Google Calendar\./, 'a day: the question names the day');
  assert.equal(asked[0].foot, 'Overwrite saves your change for this day only. Cancel saves nothing, and the day shows as it is in Google Calendar now.', 'a day: the foot');
  assert.deepEqual(sent.map((s) => s.body), [
    { ...yours, scope: 'this', instanceStart: dayShown.start, over: shownTime },
    { ...yours, scope: 'this', instanceStart: dayShown.start, over: { ...googleTime, allDay: false } },
  ], 'a day: the drag whole with over from the day as shown, then Overwrite: the same day, over what Google Calendar holds');
}

// Busy or Free is one of the fields `over` is built from, for an event and
// a day alike, where the copy shown holds it (the record does; the
// window's list shape does not, and the server then reads what it stores).
assert.deepEqual(overOf({ ...shown, showAs: 'busy' }, { showAs: 'free' }), { showAs: 'busy' }, 'Busy or Free: over from the event as shown');
assert.deepEqual(overOf({ ...shown, showAs: 'busy', recurring: true }, { showAs: 'free', scope: 'this', instanceStart: shown.start }), { showAs: 'busy' }, 'Busy or Free: over from the day as shown');
assert.deepEqual(overOf(shown, { showAs: 'free' }), {}, 'Busy or Free: a copy from the window does not hold it, and names none');
assert.equal(overOf({ ...shown, showAs: 'busy' }, {}, { remove: true }).showAs, 'busy', 'Busy or Free: a delete names it with every field held');
assert.deepEqual(overOf({ ...shown, showAs: 'busy', recurring: true }, { scope: 'this', instanceStart: shown.start, useSeries: ['showAs'] }), { showAs: 'busy' }, 'Busy or Free: Use series value names it');

// "Use series value": its sentences say "the repeating event", each field by its one name, read as prose.
{
  const words = { useSeriesWords, stillOwnWords, useSeriesLabel };
  const names = { date: 'date', time: 'time', length: 'length', allDay: 'all-day setting', title: 'title', location: 'location', description: 'notes', url: 'link',
    reminders: 'reminders', status: 'Planned or Maybe', showAs: 'free or busy setting', attendance: 'answer', tags: 'tags', people: 'people', icon: 'icon' };
  for (const [field, name] of Object.entries(names)) {
    assert.equal(words.useSeriesWords([field]), "Uses the repeating event's " + name, 'the toast, ' + field);
    assert.equal(words.stillOwnWords([field]), 'Nothing changed: this day still has its own ' + name, 'the refusal, ' + field);
    assert.equal(words.useSeriesLabel([field]), "Use the repeating event's " + name, 'the control, ' + field);
  }
  assert.equal(words.useSeriesWords(['date', 'time', 'description']), "Uses the repeating event's date, time and notes", 'several fields');
  assert.equal(words.stillOwnWords(['title', 'location']), 'Nothing changed: this day still has its own title and location', 'several fields, the refusal');
  assert.equal(words.useSeriesLabel(), "Use the repeating event's values", 'the control for all of them');
  for (const s of [words.useSeriesWords(Object.keys(names)), words.stillOwnWords(Object.keys(names)), words.useSeriesLabel(Object.keys(names))]) {
    assert.ok(!/series|—| at Google/.test(s), 'no "series", no em-dash: ' + s);
  }
}

off();
globalThis.fetch = realFetch;
assert.equal(state.confirmAsk, null);
console.log('Google changed: an open editor keeps the person\'s fields, and an answer shows at once');

// The same choice at its callers, in the stand-in DOM: the editor's one base,
// and questions one at a time.
await import('./google-conflict-callers.mjs');
