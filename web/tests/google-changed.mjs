// A change or delete of one event that changed in Google Calendar since it
// was shown (docs/design/google-conflict-choice.md, slice 1): every sentence
// the person reads, in one place to be read as prose, then the one sender
// (web/src/app/googlechange.js): `over` from what was shown, the question
// asked again with newer values, Overwrite as the same request with `over`
// set to Google's values, Cancel sending nothing. Run: node web/tests/google-changed.mjs
// (smoke.mjs imports it). Invented events only.

import assert from 'node:assert/strict';
import { googleChangedAsk, keptWords, stoppedWords, timeValueWords } from '../src/lib/planwords.js';

// A fixed today, so a date in another year says its year whatever year the
// test runs in.
const now = new Date(2026, 10, 14);
const ask = (a) => googleChangedAsk({ name: 'Team sync', now, ...a });
const timed = (day, from, to) => ({ start: `2026-11-${day}T${from}:00-08:00`, end: `2026-11-${day}T${to}:00-08:00`, allDay: false });
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
// read by server/tests/google-conflict-words.php too). A timed value is said on
// the clock it is served on there and on this device's here, so a row with one
// is checked only where the two give the same offset; the CI's Los Angeles and
// UTC runs check every row.
{
  const { readFileSync } = await import('node:fs');
  const table = JSON.parse(readFileSync(new URL('../../server/tests/fixtures/google-changed-words.json', import.meta.url), 'utf8'));
  const [y, m, d] = table.now.split('-').map(Number);
  const onThisClock = (iso) => {
    const off = /([+-])(\d\d):(\d\d)$/.exec(iso);
    return !!off && -new Date(iso).getTimezoneOffset() === (off[1] === '-' ? -1 : 1) * (Number(off[2]) * 60 + Number(off[3]));
  };
  const timed = (f) => f.field === 'time' ? [f.was, f.now, f.yours].filter((t) => t && !t.allDay).flatMap((t) => [t.start, t.end]) : [];
  for (const row of table.rows) {
    if (!row.fields.flatMap(timed).every(onThisClock)) continue;
    const a = googleChangedAsk({ name: row.name, fields: row.fields, remove: row.remove, now: new Date(y, m - 1, d) });
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
assert.equal(ask({ remove: true, fields: [{ field: 'time', was: { start: '2027-03-03T10:00:00-08:00', end: '2027-03-03T11:00:00-08:00', allDay: false }, now: { start: '2027-03-03T00:00:00+00:00', end: '2027-03-04T00:00:00+00:00', allDay: true }, yours: null }] }).body,
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

// ---- kept, on the toast -------------------------------------------------------

assert.equal(keptWords([], now), null, 'nothing kept: the toast is as always');
assert.equal(keptWords(undefined, now), null, 'no kept at all (the setting off): the toast is as always');
assert.equal(keptWords([{ field: 'title', now: 'Team sync' }], now), "Changed in Google Calendar meanwhile, and kept: the title is now 'Team sync'.");
assert.equal(keptWords([{ field: 'status', now: 'tentative' }], now), 'Changed in Google Calendar meanwhile, and kept: it is now a Maybe.');
assert.equal(keptWords([{ field: 'status', now: 'confirmed' }, { field: 'title', now: 'Standup' }], now),
  "Changed in Google Calendar meanwhile, and kept: it is now Planned and the title is now 'Standup'.");
assert.equal(keptWords([{ field: 'location', now: 'Sample Hall' }, { field: 'url', now: '' }], now),
  "Changed in Google Calendar meanwhile, and kept: the location is now 'Sample Hall' and the link was removed.");
assert.equal(keptWords([{ field: 'time', now: timed(17, '10:00', '11:00') }, { field: 'location', now: null }, { field: 'description', now: notesThere }], now),
  'Changed in Google Calendar meanwhile, and kept: the time is now Tue, Nov 17, 10:00 AM to 11:00 AM; the location was removed; and the notes were changed.',
  'a time with commas, and notes too long to repeat');

assert.equal(stoppedWords(['Sample hike', 'Sample dinner']), "Stopped. Already deleted: 'Sample hike' and 'Sample dinner'.");
assert.equal(timeValueWords(allDay(17), now), 'all day on Tue, Nov 17');

console.log('Google changed: sentences read as written');

// ---- the one sender ------------------------------------------------------------

const { overOf, sendEventChange, CANCELLED, keptLine, failWords } = await import('../src/app/googlechange.js');
const { state, set, subscribe } = await import('../src/app/store.js');
const { ApiError } = await import('../src/app/api.js');

const shown = {
  instanceId: 'e7', eventId: 7, calendarId: 3, recurring: false, title: 'Team sync', location: 'Room 2', url: null,
  status: 'confirmed', start: '2026-11-17T10:00:00-08:00', end: '2026-11-17T11:00:00-08:00', allDay: false,
};
assert.deepEqual(overOf(shown, { title: 'Weekly sync', tagNames: ['x'] }), { title: 'Team sync' }, 'only the fields the request changes');
assert.deepEqual(overOf(shown, { start: 'a', end: 'b' }), { start: shown.start, end: shown.end, allDay: false }, 'a time sends all three keys, from what was shown');
assert.deepEqual(overOf(shown, { relationship: 'maybe' }), { status: 'confirmed' }, 'Planned or Maybe is the status');
assert.deepEqual(overOf(shown, { description: '<p>New</p>' }), {}, 'notes the window never held are left to the server');
assert.deepEqual(overOf({ ...shown, description: null }, { description: '<p>New</p>' }), { description: null }, 'notes the record held, even empty');
assert.deepEqual(Object.keys(overOf(shown, {}, { remove: true })).sort(), ['allDay', 'end', 'location', 'start', 'status', 'title', 'url'], 'a delete: every field held');
assert.deepEqual(overOf({ ...shown, recurring: true }, { title: 'x' }), {}, 'a day of a series: none');
assert.deepEqual(overOf(shown, { answer: 'ACCEPTED' }), {}, 'an answer to an invitation: none');

// A stand-in for the server: answers each request from a list, and records them.
const realFetch = globalThis.fetch;
const sent = [];
let answers = [];
const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
globalThis.fetch = async (url, init = {}) => {
  const path = String(url).replace('/api/v1', '');
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
  asked.push({ title: a.title, body: a.body, lines: a.lines, foot: a.foot, confirmLabel: a.confirmLabel });
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
assert.equal(keptLine(res), "Changed in Google Calendar meanwhile, and kept: the location is now 'Sample Hall'.");

// A time: Overwrite sets all three keys of over from Google's time.
sent.length = 0; asked.length = 0;
const now2 = { start: '2026-11-17T10:30:00-08:00', end: '2026-11-17T11:30:00-08:00', allDay: false };
answers = [changed([{ field: 'time', was: { start: shown.start, end: shown.end, allDay: false }, now: now2, yours: { start: '2026-11-18T10:00:00-08:00', end: '2026-11-18T11:00:00-08:00', allDay: false } }]), json(200, { kept: [] })];
reply = [true];
const moved = await sendEventChange(shown, '/events/7', 'PATCH', { start: '2026-11-18T10:00:00-08:00', end: '2026-11-18T11:00:00-08:00' });
assert.deepEqual(sent[1].body.over, now2, 'the time asked: over.start, over.end, over.allDay are Google\'s');
assert.equal(keptLine(moved), null, 'empty kept: no line');

// Overwrite sends what the question showed as yours. A drag (start and end
// only) over an event Google made all-day: the repeat carries yours whole,
// allDay false with it, and over from Google's all-day time; the scope
// fields and anything else in the body stay as they were.
sent.length = 0; asked.length = 0;
const googleAllDay = { start: '2026-11-17T00:00:00+00:00', end: '2026-11-18T00:00:00+00:00', allDay: true };
const yoursAt6 = { start: '2026-11-17T18:00:00-08:00', end: '2026-11-17T19:00:00-08:00', allDay: false };
answers = [changed([{ field: 'time', was: { start: shown.start, end: shown.end, allDay: false }, now: googleAllDay, yours: yoursAt6 }]), json(200, { kept: [] })];
reply = [true];
await sendEventChange(shown, '/events/7', 'PATCH', { start: yoursAt6.start, end: yoursAt6.end, tagNames: ['sample'] });
assert.equal(asked[0].body, "Since 'Team sync' was last shown to you, its time was changed in Google Calendar. It is now all day on Tue, Nov 17 there; your change makes it Tue, Nov 17, 6:00 PM to 7:00 PM.",
  'overwrite yours: the question said six o\'clock');
assert.deepEqual(sent[1].body, { start: yoursAt6.start, end: yoursAt6.end, allDay: false, tagNames: ['sample'], over: googleAllDay },
  'overwrite yours: a time sends start, end and allDay from yours, over from now');

// A plain field: the body's value is yours, over is now.
sent.length = 0; asked.length = 0;
answers = [changed([{ field: 'location', was: 'Room 2', now: 'Sample Hall', yours: 'Room 3' }]), json(200, { kept: [] })];
reply = [true];
await sendEventChange(shown, '/events/7', 'PATCH', { location: 'Room 3', locationLat: null, locationLng: null });
assert.deepEqual(sent[1].body, { location: 'Room 3', locationLat: null, locationLng: null, over: { location: 'Sample Hall' } },
  'overwrite yours: a plain field sends yours, over from now');

// Cancel: nothing more is sent, the caller's copy is put back, CANCELLED.
sent.length = 0; asked.length = 0;
let reverted = 0;
answers = [changed([{ field: 'location', was: 'Room 2', now: 'Sample Hall', yours: null }])];
reply = [false];
const gone = await sendEventChange(shown, '/events/7', 'DELETE', {}, { remove: true, revert: () => { reverted++; } });
assert.equal(gone, CANCELLED);
assert.equal(sent.length, 1, 'Cancel sends nothing');
assert.equal(reverted, 1, 'and puts back what the caller showed');
assert.equal(asked[0].confirmLabel, 'Delete');
assert.equal(asked[0].foot, DELETE_FOOT);
assert.equal(sent[0].body.over.title, 'Team sync', 'a delete carries over for every field');

// The editor open on the event: Cancel says it takes you back.
sent.length = 0; asked.length = 0;
set({ editor: { mode: 'edit', occ: shown } });
answers = [changed([t1])];
reply = [false];
assert.equal(await sendEventChange(shown, '/events/7', 'PATCH', { title: 'Weekly sync' }), CANCELLED);
assert.equal(asked[0].foot, OVERWRITE_BACK);
set({ editor: null });

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
assert.equal(keptLine(plain), null);

off();
globalThis.fetch = realFetch;
assert.equal(state.confirmAsk, null);
console.log('Google changed: one sender, over from what was shown, Overwrite and Cancel');
