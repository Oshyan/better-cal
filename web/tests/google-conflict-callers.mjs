// The conflict choice (docs/design/google-conflict-choice.md) at its callers:
// the real EditorDrawer drawn into the stand-in DOM, and the real actions,
// against a stand-in server. Two rules:
//
// E. One base per opening of the editor: for each field, what the person has
//    most recently been shown. A Save sends exactly the fields that differ
//    from it, with `over` from it; after a question, the asked fields' base
//    is Google's value as the question showed it, the rest keep theirs. The
//    table below runs every field through every path, and works out what
//    each Save must send from that rule alone (expect), never per case.
//
// Q. Every question asked is answered exactly once, and every caller's
//    in-flight state ends on every exit: questions that arise together are
//    asked one at a time in the order they came, each naming its event, and
//    one that waited asks from what the server says when its turn comes.
//
// W. A time is one value: every request that changes an event's time sends
//    start, end, all-day and zone together, and `over` names the time as
//    shown the same way; a request the server refuses as from an older
//    page is undone, said as given, and offers the update's Reload.
//
// Run: node web/tests/google-conflict-callers.mjs (google-changed.mjs runs
// it after its own checks, so smoke.mjs does too). Invented events only.

import assert from 'node:assert/strict';
import { fakeDom } from './fakedom.mjs';

const dom = fakeDom();
dom.install();
const { options } = await import('../vendor/preact.module.js');
const savedRaf = options.requestAnimationFrame;
const savedDebounce = options.debounceRendering;
options.requestAnimationFrame = (cb) => setImmediate(cb);
options.debounceRendering = (cb) => Promise.resolve().then(cb);
const { render, html } = await import('../vendor/index.js');
const { state, set, subscribe, adoptEvent, invalidateRecords } = await import('../src/app/store.js');
const { EditorDrawer } = await import('../src/app/EditorDrawer.js');
const { deleteEvent, moveEvent, resizeEvent, updateEvent, moveTrip, maybePromptExtendTrip, setRelationship, useSeriesValue } = await import('../src/app/actions.js');
const { AppReloadBanner } = await import('../src/app/updates.js');
const { askConfirm } = await import('../src/app/ConfirmDialog.js');
const { toISOWithOffset, parseISO, dayKeyOf } = await import('../src/lib/dates.js');

// Turns of the event loop until a condition holds, counted, never a clock.
const turns = async (until = () => false, max = 60) => { for (let i = 0; i < max && !until(); i++) await new Promise((r) => setImmediate(r)); };
const settle = () => turns();
// The rich-text box's editor scripts can't load under node (mount fails
// them) and it falls back to a plain box; its message about that is expected.
const realError = console.error;
console.error = (...a) => { if (!String(a[0]).startsWith('Rich text editor failed')) realError(...a); };

const realFetch = globalThis.fetch;
const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
// `day`: a question about one day of a repeating event names it, as it was shown.
const changed = (fields, day = null) => json(409, { error: { code: 'google_changed', message: 'Changed in Google Calendar', ...(day ? { day } : {}), fields, kept: [] } });

// Answer each question as it shows, from `reply`; record what it said.
const asked = [];
let reply = [];
const off = subscribe((st) => {
  const a = st.confirmAsk;
  if (!a || a._seen) return;
  a._seen = true;
  asked.push({ title: a.title, body: a.body, foot: a.foot, confirmLabel: a.confirmLabel });
  if (!reply.length) return; // left open for the test to answer
  const yes = reply.shift();
  queueMicrotask(() => a.resolve(yes));
});

set({ calendars: [
  { id: 3, name: 'Sample team', provider: 'google', kind: 'subscribed', editable: true, visible: true, role: 'mine' },
  { id: 4, name: 'Sample side', provider: 'google', kind: 'subscribed', editable: true, visible: true, role: 'mine' },
], people: [] });

// ---- E: the editor's one base ---------------------------------------------------

const at = (day, hhmm) => { const [h, m] = hhmm.split(':').map(Number); return toISOWithOffset(new Date(2027, 2, day, h, m)); };
const dayOf = (iso) => dayKeyOf(parseISO(iso));
// The time a date key names, whole; Google Calendar's times (the 5th, the
// 6th) are in another zone than the event opened with, so a base that takes
// a question's time takes its zone too.
const timeOf = (key) => { const d = Number(key.slice(8)); return { start: at(d, '10:00'), end: at(d, '11:00'), allDay: false, tzid: d >= 5 ? 'Asia/Tokyo' : 'America/Los_Angeles' }; };
// The event the editor opens on: one in no series, then (the same table,
// OPENINGS below) one day of a repeating event.
const single = {
  instanceId: '7:open', eventId: 7, calendarId: 3, recurring: false, rrule: null, full: true,
  title: 'Sample review', url: 'https://example.test/one', location: 'Room 2', description: 'Bring the agenda',
  status: 'confirmed', relationship: 'planned', showAs: 'busy', ...timeOf('2027-03-03'), tags: ['alpha'], people: [],
  isContainer: false, reminderSource: 'event', reminders: [{ minutes: 30 }],
};
let opening = single;

const ed = dom.doc.createElement('div');
dom.doc.body.appendChild(ed);
const q = (sel) => ed.querySelector(sel);
const type = async (el, v) => { el.value = v; el.dispatch('input'); await settle(); };
const tick = async (el, on) => { el.checked = on; el.dispatch('change'); await settle(); };
const checkIn = (start) => ed.querySelectorAll('label.bc-check').find((l) => (l.getAttribute('title') || '').startsWith(start)).querySelector('input');

// Every field of the editor, each with three values (as it opened, as
// Google Calendar has it after a change there, as the person types it) and
// a fourth for a second change in Google Calendar; `google`: the server
// compares it (googlechange.js FIELDS), else it is local and never asked.
// `put` sets it through the editor's own control; `sent`/`over` read it
// from a request; `occ` is the event's keys for a value.
const FIELDS = {
  title: { google: true, O: 'Sample review', G: 'Sample standup', D: 'Sample retro', G2: 'Sample sync',
    put: (v) => type(q('.bc-ed-title'), v), sent: (b) => b.title, over: (o) => o.title, occ: (v) => ({ title: v }) },
  time: { google: true, O: '2027-03-03', G: '2027-03-05', D: '2027-03-04', G2: '2027-03-06',
    put: async (v) => { const el = q('input[aria-label="Start date"]'); await type(el, v); el.dispatch('blur'); await settle(); },
    sent: (b) => ('start' in b ? dayOf(b.start) : undefined), over: (o) => ('start' in o ? dayOf(o.start) : undefined), occ: timeOf, wire: timeOf },
  location: { google: true, O: 'Room 2', G: 'Room 5', D: 'Room 9', G2: 'Room 7',
    put: (v) => type(q('input[aria-label="Location"]'), v), sent: (b) => b.location, over: (o) => o.location, occ: (v) => ({ location: v }) },
  link: { google: true, O: 'https://example.test/one', G: 'https://example.test/two', D: 'https://example.test/three', G2: 'https://example.test/four',
    put: (v) => type(q('input[aria-label="Link"]'), v), sent: (b) => b.url, over: (o) => o.url, occ: (v) => ({ url: v }) },
  notes: { google: true, O: 'Bring the agenda', G: 'Bring the slides', D: 'Bring the notes', G2: 'Bring the handouts',
    put: async (v) => { const el = q('[contenteditable]'); el.textContent = v; el.dispatch('input'); await settle(); },
    sent: (b) => b.description, over: (o) => o.description, occ: (v) => ({ description: v }) },
  // Two values only: a person's change to it is Google's change or none.
  status: { google: true, O: 'confirmed', G: 'tentative', D: 'tentative', G2: 'confirmed',
    put: async (v) => { ed.querySelectorAll('.bc-ed-segbtn').find((b) => b.textContent.includes(v === 'tentative' ? 'Maybe' : 'Planned')).click(); await settle(); },
    sent: (b) => b.status, over: (o) => o.status, occ: (v) => ({ status: v, relationship: v === 'tentative' ? 'maybe' : 'planned' }) },
  // Free or busy: two values too, held by the record the editor opens.
  showAs: { google: true, O: 'busy', G: 'free', D: 'free', G2: 'busy',
    put: async (v) => { ed.querySelectorAll('.bc-ed-segbtn').find((b) => b.textContent.trim() === (v === 'free' ? 'Free' : 'Busy')).click(); await settle(); },
    sent: (b) => b.showAs, over: (o) => o.showAs, occ: (v) => ({ showAs: v }) },
  tags: { google: false, O: 'alpha', D: 'alpha, beta',
    put: (v) => type(ed.querySelectorAll('input').find((i) => i.getAttribute('aria-label') === 'Tags, comma separated'), v), sent: (b) => (b.tagNames ? b.tagNames.join(', ') : undefined) },
  calendar: { google: false, O: 3, D: 4,
    put: async (v) => { const el = q('select.bc-calselect'); el.value = String(v); el.dispatch('change'); await settle(); }, sent: (b) => b.calendarId },
  trip: { google: false, O: false, D: true, put: (v) => tick(checkIn('A trip'), v), sent: (b) => b.isContainer },
  reminders: { google: false, O: '30', D: '10,30',
    put: async (v) => {
      if (v === '10,30') { const el = q('select.bc-rem-add'); el.value = '10'; el.dispatch('change'); }
      else ed.querySelectorAll('.bc-rem-x').find((b) => /10 min/.test(b.getAttribute('aria-label'))).click();
      await settle();
    },
    sent: (b) => (b.reminders ? b.reminders.map((r) => r.minutes).join(',') : undefined) },
};
// People go through the same two functions (formFields, changedFields)
// as tags; their control needs the people directory, so they are not driven.
const NAMES = Object.keys(FIELDS);
const wireOf = (f, v) => (FIELDS[f].wire ? FIELDS[f].wire(v) : v);

// The stand-in server: answers PATCH /events/7 as the server would from what
// Google Calendar holds (`model.google`): a request changing a compared field
// whose `over` differs from Google's value is asked about; otherwise saved.
let model = null;
const sent = [];
const storedOcc = () => {
  let o = { ...opening, instanceId: '7:google' };
  for (const f of NAMES) if (FIELDS[f].google) o = { ...o, ...FIELDS[f].occ(model.google[f]) };
  return o;
};
globalThis.fetch = async (url, init = {}) => {
  const path = new URL(String(url), 'http://app.test').pathname.replace('/api/v1', '');
  if (path === '/events/7/occurrence') return json(200, { occurrence: storedOcc() });
  if (path !== '/events/7' || init.method !== 'PATCH') return json(200, { events: [], cursor: null });
  const body = JSON.parse(init.body);
  sent.push(body);
  const over = body.over || {};
  // A change to all events of a repeating event is compared with nothing
  // (it carries no `over`): saved as sent.
  const compared = body.scope === undefined || body.scope === 'this';
  const ask = NAMES.filter((f) => compared && FIELDS[f].google && FIELDS[f].sent(body) !== undefined && FIELDS[f].over(over) !== model.google[f]);
  if (ask.length) return changed(ask.map((f) => ({ field: f === 'notes' ? 'description' : f === 'link' ? 'url' : f, was: wireOf(f, FIELDS[f].over(over)), now: wireOf(f, model.google[f]), yours: wireOf(f, FIELDS[f].sent(body)) })));
  // A day's answer carries no occurrence: the window's refetch shows it.
  return json(200, opening.recurring ? { kept: [] } : { kept: [], occurrence: { ...storedOcc(), instanceId: '7:saved' } });
};

const mount = async () => {
  render(null, ed);
  set({ editor: null, editorDirty: false, occ: new Map([[opening.instanceId, opening]]) });
  await settle();
  set({ editor: { mode: 'edit', occ: opening } });
  render(html`<${EditorDrawer} />`, ed);
  await settle();
  // The notes box's editor scripts can't load here: fail them, and it
  // falls back to its plain box, which saves what is typed in it.
  for (const s of dom.doc.head.childNodes) if (s.onerror && !s.failed) { s.failed = true; s.onerror(); await settle(); }
  await settle();
};

// The rule, as the model: what a Save sends is every field whose form
// value differs from its base, with `over` from the base; the server asks
// about the compared ones whose base is not Google's; Cancel makes those
// fields' base Google's value; Overwrite sends again with Google's as over.
const expectSave = () => {
  const changedNow = NAMES.filter((f) => model.form[f] !== model.base[f]);
  if (!changedNow.length) return { requests: [], closes: true };
  const first = { fields: Object.fromEntries(changedNow.map((f) => [f, model.form[f]])), over: Object.fromEntries(changedNow.filter((f) => FIELDS[f].google).map((f) => [f, model.base[f]])) };
  const asks = changedNow.filter((f) => FIELDS[f].google && model.google[f] !== model.base[f]);
  return { first, asks, changedNow };
};
const readRequest = (body) => ({
  fields: Object.fromEntries(NAMES.filter((f) => FIELDS[f].sent(body) !== undefined).map((f) => [f, FIELDS[f].sent(body)])),
  over: Object.fromEntries(NAMES.filter((f) => FIELDS[f].google && FIELDS[f].over(body.over || {}) !== undefined).map((f) => [f, FIELDS[f].over(body.over || {})])),
});
// Only the fields of the table, never another key of a row: `typed` and
// `allDay` go with a time, locationLat/Lng with a place.
const ROW_KEYS = new Set(['over', 'title', 'start', 'end', 'allDay', 'tzid', 'typed', 'location', 'locationLat', 'locationLng', 'url', 'description', 'status', 'showAs', 'tagNames', 'calendarId', 'isContainer', 'reminders']);
// A day's request names the day beside them.
const IDENTITY_KEYS = new Set(['scope', 'instanceStart']);

let checks = 0;
// T1, T2 (a time is one value): a request that sends any part of a time
// sends all four, and an `over` that names any part names all four. The
// keys from the API contract, never from the product's own list.
const TIME = ['start', 'end', 'allDay', 'tzid'];
function wholeTimes(body, label) {
  let n = 0;
  for (const [where, o] of [['the request', body], ['over', body.over || {}]]) {
    if (!TIME.some((k) => k in o)) continue;
    for (const k of TIME) assert.ok(o[k] !== undefined && o[k] !== null && o[k] !== '', label + ': ' + where + ' names the time whole, ' + k + ' too: ' + JSON.stringify(o));
    assert.equal(typeof o.allDay, 'boolean', label + ': ' + where + ': all-day is a flag');
    if (o.allDay) assert.equal(o.tzid, 'UTC', label + ': ' + where + ': an all-day time is dates, its zone UTC');
    n++;
  }
  return n;
}
async function save(label, answer) {
  const want = expectSave();
  sent.length = 0;
  asked.length = 0;
  reply = answer === undefined ? [] : [answer];
  q('form').dispatch('submit');
  await turns(() => !state.editor || (sent.length && (!want.asks || !want.asks.length || asked.length)) , 200);
  await settle();
  if (!want.first) {
    assert.deepEqual(sent, [], label + ': nothing differs from what was shown, so nothing is sent');
    assert.equal(state.editor, null, label + ': and the editor closes');
    checks += 2;
    return;
  }
  assert.ok(sent.length >= 1, label + ': a request is sent');
  for (const b of sent) checks += wholeTimes(b, label);
  assert.deepEqual(readRequest(sent[0]), { fields: want.first.fields, over: want.first.over }, label + ': exactly what differs from the base, over from the base');
  for (const k of Object.keys(sent[0])) assert.ok(ROW_KEYS.has(k) || (opening.recurring && IDENTITY_KEYS.has(k)), label + ': only rows of changed fields go: ' + k);
  // What is being edited is fixed for the opening: a day's every request
  // names the day it opened on, whatever a question showed since.
  for (const b of sent) assert.deepEqual([b.scope, b.instanceStart], opening.recurring ? ['this', opening.start] : [undefined, undefined], label + ': every request names what the editor opened');
  checks += 3;
  if (!want.asks.length) {
    assert.equal(state.editor, null, label + ': saved, the editor closes');
    checks++;
    return;
  }
  assert.equal(asked.length, 1, label + ': asked once');
  if (answer) {
    assert.equal(sent.length, 2, label + ': Overwrite sends again');
    const again = readRequest(sent[1]);
    assert.deepEqual(again.fields, want.first.fields, label + ': Overwrite sends the same values');
    assert.deepEqual(again.over, { ...want.first.over, ...Object.fromEntries(want.asks.map((f) => [f, model.google[f]])) }, label + ': with Google\'s values as over for the asked fields');
    assert.equal(state.editor, null, label + ': saved, the editor closes');
    checks += 4;
    return;
  }
  for (const f of want.asks) model.base[f] = model.google[f];
  assert.ok(state.editor, label + ': Cancel keeps the editor open');
  assert.equal(state.editorDirty, NAMES.some((f) => model.form[f] !== model.base[f]), label + ': the dirty mark is the same comparison with the same base');
  checks += 3;
}

// The paths, each as steps on a field `f`. A local field is never asked
// about, so for it the question is about the title, changed alongside.
const PATHS = {
  'Cancel, then Save': (f, s) => [s.change(f), s.save(false), s.save()],
  'Cancel, edit back to its opening value, Save': (f, s) => [s.change(f), s.save(false), s.put(f, 'O'), s.save()],
  'Cancel, edit to what Google holds, Save': (f, s) => [s.change(f), s.save(false), s.put(f, 'G'), s.save()],
  'Overwrite': (f, s) => [s.change(f), s.save(true)],
  'a second question after the first, Cancel, edit back, Save': (f, s) => [s.change(f), s.save(false), s.google(s.q(f), 'G2'), s.save(false), s.put(f, 'O'), s.save()],
  'a poll lands with no question, then Save': (f, s) => [s.google(s.q(f), 'G'), s.poll(), s.put(f, 'D'), ...(f === s.q(f) ? [] : [s.put('title', 'D')]), s.save(false), s.save()],
  'a field the question did not ask about keeps its base': (f, s) => (f === 'title' ? null
    : [s.put(f, 'D'), s.put('title', 'D'), s.google('title', 'G'), s.save(false), s.put(f, 'O'), s.save()]),
};
const steps = {
  q: (f) => (FIELDS[f].google ? f : 'title'),
  put: (f, which) => async () => { model.form[f] = FIELDS[f][which]; await FIELDS[f].put(FIELDS[f][which]); },
  google: (f, which) => async () => { model.google[f] = FIELDS[f][which]; },
  poll: () => async () => { adoptEvent(storedOcc()); await settle(); },
  save: (answer) => async (label) => save(label, answer),
  // The person changes `f` (and the title, when `f` is local) and Google
  // Calendar changed the asked field meanwhile.
  change: (f) => async () => {
    for (const g of new Set([f, steps.q(f)])) { model.form[g] = FIELDS[g].D; await FIELDS[g].put(FIELDS[g].D); }
    model.google[steps.q(f)] = FIELDS[steps.q(f)].G;
  },
};

// The same rule for one day of a repeating event (slice 2, (l)): one base
// per opening, moved only by a question, and the day the editor opened on
// named by every Save. A day's calendar is left out: picking another one
// sets Apply to all events (#186), a save to every day, compared with
// nothing (section D).
const OPENINGS = [
  { name: 'an event in no series', occ: single, skip: [] },
  { name: 'a day of a repeating event', occ: { ...single, instanceId: '7:day', recurring: true, rrule: 'FREQ=WEEKLY' }, skip: ['calendar'] },
];
let rows = 0;
for (const op of OPENINGS) {
  opening = op.occ;
  for (const f of NAMES) {
    if (op.skip.includes(f)) continue;
    for (const [name, path] of Object.entries(PATHS)) {
      if (!FIELDS[f].google && /what Google holds|poll/.test(name)) continue; // a local field has no value at Google
      const list = path(f, steps);
      if (!list) continue;
      model = { form: {}, base: {}, google: {} };
      for (const g of NAMES) { model.form[g] = FIELDS[g].O; model.base[g] = FIELDS[g].O; model.google[g] = FIELDS[g].O; }
      await mount();
      // A Save that closes the editor ends the path (a two-valued field can
      // hold nothing but Google's value after its question).
      for (const step of list) { if (!state.editor) break; await step(op.name + ', ' + f + ', ' + name); }
      rows++;
    }
  }
}

// A Save to all events after a question about the day: compared with
// nothing (no `over`), it sends what it would have sent had no question
// been asked, its difference from the day as the editor opened. Read
// against the same edits saved with no question first, never a list. (A
// Google calendar offers no "this and following": scopes.js.)
{
  opening = OPENINGS[1].occ;
  const toAll = async () => { const sel = ed.querySelector('select[aria-label="Apply to"]'); sel.value = 'all'; sel.dispatch('change'); await settle(); };
  const submit = async () => { q('form').dispatch('submit'); await turns(() => !state.editor || asked.length, 2000); await settle(); };
  for (const f of ['title', 'time', 'location']) {
    // What the person types after the question: their own value still, or
    // what the question showed as Google's.
    for (const after of ['D', 'G']) {
      const label = 'all events after a question about the day\'s ' + f + ', the form at ' + (after === 'D' ? 'the person\'s value' : 'Google\'s');
      model = { form: {}, base: {}, google: {} };
      for (const g of NAMES) { model.form[g] = FIELDS[g].O; model.base[g] = FIELDS[g].O; model.google[g] = FIELDS[g].O; }
      // No question: the edits, Apply to all events, Save.
      await mount();
      await FIELDS[f].put(FIELDS[f][after]);
      await toAll();
      sent.length = 0; asked.length = 0; reply = [];
      await submit();
      const unasked = sent[0];
      // The question first: the change to this day asked about, Cancel,
      // then the same edits and Save to all events.
      model.google[f] = FIELDS[f].G;
      await mount();
      await FIELDS[f].put(FIELDS[f].D);
      sent.length = 0; asked.length = 0; reply = [false];
      await submit();
      assert.equal(asked.length, 1, label + ': asked about the day');
      await FIELDS[f].put(FIELDS[f][after]);
      await toAll();
      sent.length = 0; asked.length = 0; reply = [];
      await submit();
      assert.equal(sent.length, 1, label + ': one request');
      assert.equal(sent[0].over, undefined, label + ': no over');
      assert.deepEqual(sent[0], unasked, label + ': what it would have sent with no question');
      assert.equal(state.editor, null, label + ': saved');
      checks += 4;
      rows++;
      model.google[f] = FIELDS[f].O;
    }
  }
}
opening = single;
render(null, ed);
set({ editor: null, editorDirty: false });
await settle();
console.log('Google conflict, the editor: ' + rows + ' field and path rows, ' + checks + ' checks, from one base per opening');

// ---- Q: every question answered once, one at a time -----------------------------

const ev = (id, extra = {}) => ({ instanceId: id + ':shown', eventId: id, calendarId: 3, recurring: false, full: true,
  title: 'Sample event ' + id, location: 'Room ' + id, url: null, status: 'confirmed', ...timeOf('2027-03-03'), allDay: false, ...extra });
// Per event, what Google Calendar holds and the answers its requests get.
let google = {};
const requests = [];
globalThis.fetch = async (url, init = {}) => {
  const path = new URL(String(url), 'http://app.test').pathname.replace('/api/v1', '');
  const id = Number((path.match(/^\/events\/(\d+)/) || [])[1]);
  if (path.endsWith('/occurrence')) return google[id] ? json(200, { occurrence: google[id] }) : json(404, { error: { code: 'not_found', message: 'Not found' } });
  if (!id || !init.method || init.method === 'GET') return json(200, { events: [], cursor: null });
  const body = init.body ? JSON.parse(init.body) : {};
  requests.push({ id, method: init.method, body });
  const held = google[id];
  if (!held) return json(404, { error: { code: 'not_found', message: 'That event no longer exists' } });
  // A field's value in `over`, in the event held, or in the request.
  const value = (k, o) => (k === 'time' ? ('start' in o ? { start: o.start, end: o.end, allDay: !!o.allDay, tzid: o.tzid } : undefined) : o[k]);
  const over = body.over || {};
  const differs = ['title', 'location', 'time'].filter((k) => value(k, over) !== undefined && JSON.stringify(value(k, over)) !== JSON.stringify(value(k, held)));
  // A question about one day names the day as it was shown.
  const day = body.scope === 'this' ? { start: over.start, end: over.end, allDay: !!over.allDay, tzid: over.tzid } : null;
  if (differs.length) return changed(differs.map((k) => ({ field: k, was: value(k, over), now: value(k, held), yours: init.method === 'DELETE' ? null : (value(k, body) ?? value(k, over)), from: day ? 'day' : undefined })), day);
  if (init.method === 'DELETE') delete google[id];
  return json(200, { kept: [] });
};
const showing = () => state.confirmAsk;
const answer = async (yes) => { const a = state.confirmAsk; a.resolve(yes); await settle(); };
const put = (...list) => set({ occ: new Map(list.map((o) => [o.instanceId, o])), editor: null, popover: null });

// Two deletes answered by Google at once: asked one after the other in the
// order the answers came, each naming its event; each settles on its own
// answer; a retry of either is not blocked.
{
  google = { 7: ev(7, { title: 'Sample event 7 (moved)' }), 8: ev(8, { title: 'Sample event 8 (moved)' }) };
  put(ev(7), ev(8));
  requests.length = 0; asked.length = 0; reply = [];
  const done = { 7: false, 8: false };
  const a7 = deleteEvent(ev(7)).then(() => { done[7] = true; });
  const a8 = deleteEvent(ev(8)).then(() => { done[8] = true; });
  await turns(() => showing());
  assert.match(showing().body, /'Sample event 7' was last shown/, 'Q: the first question names its event');
  assert.equal(asked.length, 1, 'Q: one question at a time: the second waits, nothing replaces the first');
  await answer(false);
  await a7;
  assert.equal(done[7], true, 'Q: Cancel settles the first delete');
  await turns(() => showing());
  assert.match(showing().body, /'Sample event 8' was last shown/, 'Q: then the second, naming its own event');
  assert.deepEqual(requests.map((r) => r.id), [7, 8, 8], 'Q: the second asks from a request sent again when its turn came');
  await answer(true);
  await a8;
  assert.equal(done[8], true, 'Q: Delete settles the second');
  assert.equal(google[8], undefined, 'Q: and it is deleted');
  // The first's guard is released: a retry sends a request and asks again.
  requests.length = 0;
  const retry = deleteEvent(ev(7));
  await turns(() => showing());
  assert.deepEqual(requests.map((r) => r.id), [7], 'Q: a retry of the first is sent, not swallowed by a guard left behind');
  await answer(false);
  await retry;
}

// Two at once, one of them a day of a repeating event: the same line, the
// day's question in its turn, from a request sent again that names the day.
{
  google = { 7: ev(7, { title: 'Sample event 7 (moved)' }), 8: ev(8, { title: 'Sample event 8 (moved)' }) };
  const day8 = { ...ev(8), recurring: true };
  put(ev(7), day8);
  requests.length = 0; asked.length = 0; reply = [];
  const a7 = deleteEvent(ev(7));
  const a8 = deleteEvent(day8, 'this');
  await turns(() => showing());
  assert.match(showing().body, /'Sample event 7' was last shown/, 'Q, a day: the first question names its event');
  assert.equal(asked.length, 1, 'Q, a day: one question at a time');
  await answer(false);
  await a7;
  await turns(() => showing());
  assert.match(showing().body, /'Sample event 8' was last shown/, 'Q, a day: then the day\'s question');
  assert.deepEqual(requests.map((r) => r.id), [7, 8, 8], 'Q, a day: the day asks from a request sent again when its turn came');
  await answer(true);
  await a8;
  assert.equal(google[8], undefined, 'Q, a day: Delete settles it');
}

// A drag and a delete at once, the delete's event changed again in Google
// Calendar while it waited: its question shows what Google holds when it
// is shown, not what the first answer said.
{
  google = { 7: ev(7, timeOf('2027-03-05')), 8: ev(8, { title: 'Sample event 8 (renamed)' }) };
  put(ev(7), ev(8));
  requests.length = 0; asked.length = 0; reply = [];
  const drag = moveEvent({ instanceId: '7:shown', newStart: at(4, '10:00'), newEnd: at(4, '11:00') });
  await turns(() => showing());
  const del = deleteEvent(ev(8));
  await turns(() => requests.length >= 2);
  await settle();
  assert.equal(asked.length, 1, 'Q: the drag\'s question stays while the delete\'s waits');
  google[8] = { ...google[8], title: 'Sample event 8 (renamed again)' };
  await answer(false);
  assert.equal(await drag, false, 'Q: the drag settles as Cancel');
  await turns(() => showing());
  assert.match(showing().body, /now 'Sample event 8 \(renamed again\)'/, 'Q: the waiting question shows what Google holds when it is shown');
  await answer(false);
  await del;
}

// A drag of one day of a repeating event and a delete of another event at
// once: the day's question first, naming the day; its Overwrite names the
// same day; then the delete's, asked from a request sent again.
{
  const day = ev(9, { recurring: true, rrule: 'FREQ=WEEKLY' });
  google = { 9: { ...day, ...timeOf('2027-03-05') }, 8: ev(8, { title: 'Sample event 8 (renamed)' }) };
  put(day, ev(8));
  requests.length = 0; asked.length = 0; reply = [];
  const drag = moveEvent({ instanceId: day.instanceId, newStart: at(4, '10:00'), newEnd: at(4, '11:00') });
  await turns(() => showing());
  const del = deleteEvent(ev(8));
  await turns(() => requests.length >= 2);
  await settle();
  assert.equal(asked.length, 1, 'Q, a day: one question at a time');
  assert.match(showing().body, /^Since Wed, Mar 3(, 2027)? of 'Sample event 9' was last shown to you, /, 'Q, a day: its question names the day');
  assert.equal(showing().foot, 'Overwrite saves your change for this day only. Cancel saves nothing, and the day shows as it is in Google Calendar now.', 'Q, a day: Overwrite is for this day');
  await answer(true);
  assert.equal(await drag, true, 'Q, a day: Overwrite goes through');
  await turns(() => showing());
  assert.match(showing().body, /^Since 'Sample event 8' was last shown/, 'Q, a day: then the delete\'s question, of an event in no series');
  await answer(false);
  await del;
  // The day's Overwrite and the delete sent again on its turn go out together, in either order.
  assert.deepEqual(requests.map((r) => r.id).sort(), [8, 8, 9, 9], 'Q, a day: the day, the delete, the day\'s Overwrite, the delete sent again on its turn');
  assert.deepEqual(requests.slice(0, 2).map((r) => r.id), [9, 8], 'Q, a day: the day first');
  const days = requests.filter((r) => r.id === 9).map((r) => [r.body.scope, r.body.instanceStart]);
  assert.deepEqual(days, [['this', day.start], ['this', day.start]], 'Q, a day: the Overwrite names the same day');
  assert.deepEqual(TIME.map((k) => requests.filter((r) => r.id === 9)[1].body.over[k]), TIME.map((k) => google[9][k]), 'Q, a day: its over is the day as Google Calendar has it, whole');
}

// A question waiting for one whose event is deleted meanwhile: its request
// goes again, the server says the event is gone, and the action ends as a
// failure, its guard released.
{
  google = { 7: ev(7, { title: 'Sample event 7 (moved)' }), 8: ev(8, { title: 'Sample event 8 (moved)' }) };
  put(ev(7), ev(8));
  requests.length = 0; asked.length = 0; reply = [];
  const a7 = deleteEvent(ev(7));
  await turns(() => showing());
  const a8 = deleteEvent(ev(8));
  await turns(() => requests.length >= 2);
  await settle(); // its answer read, the event shown as stored, in line
  delete google[8];
  set({ toasts: [] });
  await answer(false);
  await a7;
  await a8;
  assert.equal(showing(), null, 'Q: no question for an event that is gone');
  assert.match(state.toasts.map((t) => t.text).join(' | '), /no longer exists/, 'Q: the delete says the event is gone');
  requests.length = 0;
  google[8] = ev(8);
  await deleteEvent(ev(8));
  assert.deepEqual(requests.map((r) => r.id), [8], 'Q: a retry is sent: the guard was released');
}

// Any other question asked while one is open waits its turn, and is answered.
{
  google = { 7: ev(7, { title: 'Sample event 7 (moved)' }) };
  put(ev(7));
  asked.length = 0; reply = [];
  const del = deleteEvent(ev(7));
  await turns(() => showing());
  let other = null;
  const plain = askConfirm({ title: 'Adopt "Sample feed" as a local calendar?', confirmLabel: 'Adopt as local' }).then((v) => { other = v; });
  await settle();
  assert.match(showing().body, /'Sample event 7'/, 'Q: the open question stays');
  await answer(false);
  await del;
  await turns(() => showing());
  assert.equal(showing().title, 'Adopt "Sample feed" as a local calendar?', 'Q: then the other question');
  await answer(true);
  await plain;
  assert.equal(other, true, 'Q: answered, once');
  assert.equal(showing(), null);
}

// ---- W: a time is one value (T1, T2) -------------------------------------------
//
// Every request from the app that changes the time of an event in no series
// sends start, end, all-day and zone together, and its `over` names the
// time as shown with the same four. The inventory of those requests is the
// table below (CALLERS); each runs over every event of EVENTS, and what the
// stand-in server receives is read against the rule (wholeTimes) and the
// event as shown, never against the product's own builder. The window's
// copy of an event names its zone as the record does (api-contract.md,
// Occurrences), and the zone sent is the one on the copy shown: no request
// goes before the change. Every time here is fixed in its own zone, so the
// suite reads the same in every zone it runs in.

const DAY = 86400000;
const shiftIso = (iso, ms) => toISOWithOffset(new Date(parseISO(iso).getTime() + ms));
// A timed event west of UTC, one east of it, and an all-day event of three days.
const EVENTS = [
  { name: 'a timed event in Los Angeles', rec: { eventId: 21, start: '2027-03-10T10:00:00-08:00', end: '2027-03-10T11:00:00-08:00', allDay: false, tzid: 'America/Los_Angeles' } },
  { name: 'a timed event in Tokyo', rec: { eventId: 22, start: '2027-03-10T10:00:00+09:00', end: '2027-03-10T11:30:00+09:00', allDay: false, tzid: 'Asia/Tokyo' } },
  { name: 'an all-day event of three days', rec: { eventId: 23, start: '2027-03-10T00:00:00+00:00', end: '2027-03-13T00:00:00+00:00', allDay: true, tzid: 'UTC' } },
];
const record = (e, extra = {}) => ({ instanceId: e.rec.eventId + ':shown', calendarId: 3, recurring: false, title: 'Sample ' + e.rec.eventId,
  location: null, url: null, status: 'confirmed', relationship: 'planned', isContainer: false, ...e.rec, full: true, ...extra });
// As the window holds it: the list shape, its zone included.
const windowCopy = (e, extra = {}) => { const { full: _f, ...o } = record(e, extra); return o; };
// The time as shown, whole, as the contract serves it.
const shownWhole = (r) => ({ start: r.start, end: r.end, allDay: r.allDay, tzid: r.allDay ? 'UTC' : r.tzid });

// The stand-in server: the record on GET, every write recorded and answered
// from `answerFor` (a 200 by default).
let records = {};
let writes = [];
// Every request about an event, in order: what goes before a change.
let calls = [];
let answerFor = () => json(200, { kept: [] });
globalThis.fetch = async (url, init = {}) => {
  const path = new URL(String(url), 'http://app.test').pathname.replace('/api/v1', '');
  const id = Number((path.match(/^\/events\/(\d+)/) || [])[1]);
  const method = init.method || 'GET';
  if (id) calls.push({ id, method, path });
  if (path.endsWith('/occurrence') && method === 'GET') return records[id] ? json(200, { occurrence: records[id] }) : json(404, { error: { code: 'not_found', message: 'Not found' } });
  if (!id || method === 'GET') return json(200, { events: [], cursor: null });
  const body = init.body ? JSON.parse(init.body) : {};
  writes.push({ id, method, body });
  return answerFor({ id, method, body }, writes.filter((w) => w.id === id).length);
};

// The inventory: every request from the app that changes an event's time,
// or names it as shown, for an event in no series. `run(occ, e)` does it from
// the event as the window holds it; `time`: the request changes the time.
const later = (iso, e) => (e.rec.allDay ? shiftIso(iso, 2 * DAY) : shiftIso(iso, 2 * DAY + 3600000));
const CALLERS = [
  { name: 'a drag', time: true, run: (o, e) => moveEvent({ instanceId: o.instanceId, newStart: later(o.start, e), newEnd: later(o.end, e) }) },
  { name: 'a resize', time: true, run: (o) => resizeEvent({ instanceId: o.instanceId, newStart: o.start, newEnd: shiftIso(o.end, DAY) }) },
  // EventPopover.js saveTime: the start and end typed, as instants.
  { name: 'the panel\'s time fields', time: true, run: (o) => updateEvent(o, { start: shiftIso(o.start, DAY), end: shiftIso(o.end, DAY) }) },
  { name: 'a trip moved', time: true, trip: true, run: (o) => moveTrip(o, 2, false) },
  { name: 'a trip moved with its events', time: true, trip: true, run: (o) => moveTrip(o, 2, true) },
  { name: 'Extend from attaching an event outside the trip', time: true, trip: true,
    run: async (o) => {
      set({ toasts: [] });
      maybePromptExtendTrip(o, { ...o, eventId: 40, instanceId: '40:x', isContainer: false, start: shiftIso(o.start, 9 * DAY), end: shiftIso(o.end, 9 * DAY) });
      const t = state.toasts.find((x) => x.actionLabel === 'Extend');
      await t.onAction();
    } },
  { name: 'Extend trip after an event of it moved outside', time: true, trip: true,
    run: async (o) => {
      set({ toasts: [] });
      const member = { instanceId: '41:shown', eventId: 41, calendarId: 3, recurring: false, title: 'Sample member', allDay: false,
        start: '2027-03-11T09:00:00-08:00', end: '2027-03-11T10:00:00-08:00', containers: [{ eventId: o.eventId, title: o.title }] };
      member.tzid = 'America/Los_Angeles';
      records[41] = { ...member };
      state.occ.set(member.instanceId, member);
      await moveEvent({ instanceId: member.instanceId, newStart: '2027-04-20T09:00:00-07:00', newEnd: '2027-04-20T10:00:00-07:00' });
      const t = state.toasts.find((x) => Array.isArray(x.actions) && x.actions.some((a) => a.label === 'Extend trip'));
      t.actions.find((a) => a.label === 'Extend trip').run();
      await turns(() => writes.some((w) => w.id === o.eventId));
      await settle();
    } },
  { name: 'a delete', time: false, run: (o) => deleteEvent(o) },
  // Overwrite: the request again with the question's yours and its now.
  { name: 'Overwrite at a question about the time', time: true, overwrite: true,
    run: (o, e) => { reply = [true]; return moveEvent({ instanceId: o.instanceId, newStart: later(o.start, e), newEnd: later(o.end, e) }); } },
];

let wRows = 0;
let wChecks = 0;
for (const e of EVENTS) {
  for (const c of CALLERS) {
    const label = c.name + ', ' + e.name;
    const extra = c.trip ? { isContainer: true } : {};
    records = { [e.rec.eventId]: record(e, extra) };
    writes = [];
    calls = [];
    asked.length = 0;
    // Google Calendar holds the event two hours later than shown, for Overwrite.
    const google = { ...shownWhole(records[e.rec.eventId]), start: shiftIso(e.rec.start, 2 * 3600000), end: shiftIso(e.rec.end, 2 * 3600000) };
    if (e.rec.allDay) Object.assign(google, { start: '2027-03-11T00:00:00+00:00', end: '2027-03-14T00:00:00+00:00' });
    answerFor = c.overwrite
      ? (w, n) => (n === 1 ? changed([{ field: 'time', was: shownWhole(w.body.over), now: google, yours: { start: w.body.start, end: w.body.end, allDay: w.body.allDay, tzid: w.body.tzid } }]) : json(200, { kept: [] }))
      : () => json(200, { kept: [] });
    const o = windowCopy(e, extra);
    set({ occ: new Map([[o.instanceId, o]]), editor: null, popover: null, toasts: [] });
    await c.run(o, e);
    await settle();
    const mine = writes.filter((w) => w.id === e.rec.eventId);
    assert.ok(mine.length >= 1, label + ': a request is sent');
    const ofIt = calls.filter((x) => x.id === e.rec.eventId);
    assert.notEqual(ofIt[0].method, 'GET', label + ': the change is the first request about the event, nothing read before it: ' + JSON.stringify(ofIt[0]));
    wChecks++;
    const shown = shownWhole(records[e.rec.eventId]);
    for (const [i, w] of mine.entries()) {
      const b = w.body;
      wChecks += wholeTimes(b, label);
      if (c.time) {
        assert.ok(TIME.every((k) => k in b), label + ': the request sends the time: ' + JSON.stringify(b));
        assert.equal(b.allDay, e.rec.allDay, label + ': the kind as shown');
        assert.equal(b.tzid, shown.tzid, label + ': the zone as shown');
        wChecks += 3;
      } else {
        assert.ok(!TIME.some((k) => k in b), label + ': no time in the request');
        wChecks++;
      }
      const overTime = b.over ? Object.fromEntries(TIME.filter((k) => k in b.over).map((k) => [k, b.over[k]])) : {};
      assert.deepEqual(overTime, i === 0 ? shown : google, label + ': over names the time ' + (i === 0 ? 'as shown' : 'as the question showed Google\'s') + ', whole');
      wChecks++;
    }
    if (c.overwrite) {
      assert.equal(mine.length, 2, label + ': Overwrite sends again');
      assert.deepEqual(TIME.map((k) => mine[1].body[k]), TIME.map((k) => mine[0].body[k]), label + ': Overwrite sends yours, whole');
      wChecks += 2;
    }
    if (c.name.endsWith('with its events')) { assert.equal(mine[0].body.moveMembers, true, label + ': its events go with it'); wChecks++; }
    wRows++;
  }
}
reply = [];
console.log('Google conflict, a time is one value: ' + wRows + ' caller and event rows, ' + wChecks + ' checks');

// A timed copy shown without its zone is a fault of whatever made it: the
// action fails saying so, the block is back, and nothing is sent or read.
{
  // An event of its own: what the rows above held of theirs stays theirs.
  const e = { rec: { ...EVENTS[0].rec, eventId: 29 } };
  records = { 29: record(e) };
  writes = []; calls = [];
  const { tzid: _z, ...o } = windowCopy(e);
  await settle(); // the window refetches of the rows before have landed
  set({ occ: new Map([[o.instanceId, o]]), toasts: [] });
  const ok = await moveEvent({ instanceId: o.instanceId, newStart: later(o.start, e), newEnd: later(o.end, e) });
  assert.equal(state.occ.get(o.instanceId).start, o.start, 'no zone: the block is back');
  await settle();
  assert.equal(ok, false, 'no zone: the move fails');
  assert.deepEqual(calls, [], 'no zone: nothing is sent or read');
  assert.match(state.toasts[state.toasts.length - 1].text, /without its time zone/, 'no zone: said as a fault');
  // The zone rides the list shape: forgetting an event's record keeps it.
  const shownCopy = record(e);
  set({ occ: new Map([[shownCopy.instanceId, shownCopy]]) });
  invalidateRecords(e.rec.eventId);
  assert.equal(state.occ.get(shownCopy.instanceId).tzid, e.rec.tzid, 'a record forgotten keeps the zone the list gave');
  wChecks += 5;
}

// The editor's time: one row of its comparison. Any of start, end, all-day
// or zone differing from the base sends all of it, nothing else of it
// otherwise; "Same clock time in any zone" is a field of its own.
const device = Intl.DateTimeFormat().resolvedOptions().timeZone;
const EDITS = [
  { name: 'the title alone', time: false, edit: () => type(q('.bc-ed-title'), 'Sample renamed') },
  { name: 'the start date', time: true,
    // A day after the date the field shows: a timed start's on this
    // device's clock, an all-day one's its own date.
    edit: async (e) => {
      const el = q(e.rec.allDay ? 'input[aria-label="First day"]' : 'input[aria-label="Start date"]');
      const shownDay = e.rec.allDay ? e.rec.start.slice(0, 10) : dayKeyOf(parseISO(e.rec.start));
      const [y, m, d] = shownDay.split('-').map(Number);
      await type(el, dayKeyOf(new Date(y, m - 1, d + 1)));
      el.dispatch('blur');
      await settle();
    },
    kind: (e) => e.rec.allDay, zone: (e) => (e.rec.allDay ? 'UTC' : e.rec.tzid) },
  { name: 'the all-day switch', time: true, edit: (e) => tick(ed.querySelectorAll('label.bc-check').find((l) => /All day/.test(l.textContent)).querySelector('input'), !e.rec.allDay),
    // A timed event made all-day is dates; an all-day one made timed is on
    // the clock its fields were filled on, this device's.
    kind: (e) => !e.rec.allDay, zone: (e) => (e.rec.allDay ? device : 'UTC') },
  { name: 'a zone picked', time: true, timedOnly: true,
    edit: async () => {
      ed.querySelectorAll('button').find((b) => /^Time zone:/.test(b.getAttribute('aria-label') || '')).click();
      await settle();
      const input = ed.querySelector('input[aria-label="Time zone"]');
      input.dispatch('focus');
      await settle();
      input.value = 'Lisbon';
      input.dispatch('input');
      await settle();
      ed.querySelectorAll('li').find((li) => /Lisbon/.test(li.textContent)).click();
      await settle();
    },
    kind: () => false, zone: () => 'Europe/Lisbon' },
  { name: 'same clock time in any zone', time: false, timedOnly: true,
    edit: () => tick(ed.querySelectorAll('label.bc-check').find((l) => /Same clock time in any zone/.test(l.textContent)).querySelector('input'), true),
    also: (b) => b.floating === true },
];
let eRows = 0;
for (const e of EVENTS) {
  for (const x of EDITS) {
    if (x.timedOnly && e.rec.allDay) continue;
    const label = 'the editor, ' + x.name + ', ' + e.name;
    const rec = record(e);
    records = { [e.rec.eventId]: rec };
    writes = [];
    answerFor = () => json(200, { kept: [], occurrence: rec });
    render(null, ed);
    set({ editor: null, editorDirty: false, occ: new Map([[rec.instanceId, rec]]) });
    await settle();
    set({ editor: { mode: 'edit', occ: rec } });
    render(html`<${EditorDrawer} />`, ed);
    await settle();
    for (const s2 of dom.doc.head.childNodes) if (s2.onerror && !s2.failed) { s2.failed = true; s2.onerror(); await settle(); }
    await settle();
    await x.edit(e);
    q('form').dispatch('submit');
    await turns(() => !state.editor || writes.length, 200);
    await settle();
    assert.equal(writes.length, 1, label + ': one request');
    const b = writes[0].body;
    wChecks += wholeTimes(b, label);
    if (x.time) {
      assert.deepEqual([b.allDay, b.tzid], [x.kind(e), x.zone(e)], label + ': the time whole, its kind and zone');
      assert.deepEqual(Object.fromEntries(TIME.map((k) => [k, b.over[k]])), shownWhole(rec), label + ': over names the time as shown, whole');
    } else {
      assert.ok(!TIME.some((k) => k in b) && !TIME.some((k) => k in (b.over || {})), label + ': no part of the time: ' + JSON.stringify(b));
    }
    if (x.also) assert.ok(x.also(b), label + ': its own field: ' + JSON.stringify(b));
    wChecks += 2;
    eRows++;
  }
}
render(null, ed);
set({ editor: null, editorDirty: false });
await settle();
console.log('Google conflict, the editor\'s time: ' + eRows + ' edit and event rows, one row of its comparison');

// The refusal (T2): a page older than the server sent a time in part. The
// change is undone here as on any failed save, the server's sentence shown
// as it is, the update banner's Reload offered with it, and nothing sent again.
{
  const outdated = 'Better-Cal was updated. Reload the page and make the change again.';
  const e = EVENTS[0];
  records = { [e.rec.eventId]: record(e) };
  writes = [];
  answerFor = () => json(400, { error: { code: 'client_outdated', message: outdated } });
  const o = windowCopy(e);
  set({ occ: new Map([[o.instanceId, o]]), toasts: [], appUpdateReady: false });
  const ok = await moveEvent({ instanceId: o.instanceId, newStart: later(o.start, e), newEnd: later(o.end, e) });
  await settle();
  assert.equal(ok, false, 'refused: the move did not happen');
  assert.equal(writes.length, 1, 'refused: nothing is sent again');
  assert.equal(state.occ.get(o.instanceId).start, o.start, 'refused: the block is back where it was shown');
  const said = state.toasts[state.toasts.length - 1];
  assert.deepEqual([said.text, said.error], [outdated, true], 'refused: the server\'s sentence, as it is');
  assert.equal(state.appUpdateReady, true, 'refused: the update banner is up');
  const banner = dom.doc.createElement('div');
  dom.doc.body.appendChild(banner);
  render(html`<${AppReloadBanner} />`, banner);
  await settle();
  assert.ok(banner.querySelectorAll('button').some((b) => b.textContent === 'Reload'), 'refused: with its Reload');
  render(null, banner);
  set({ appUpdateReady: false, toasts: [] });
  console.log('Google conflict, a refused time: undone, said as given, Reload offered');
}

// ---- D: one day of a repeating event (slice 2) ----------------------------------
//
// A request that changes or deletes one day (scope 'this') carries `over`
// from the day as shown, for each field it changes (every field the day
// holds, for a delete), and a time whole, beside its scope and instanceStart,
// which stay as they were. Overwrite after a question about the day is the
// same request (same path, scope and instanceStart) with `over` at each asked
// field's now and its value at yours; "Use series value" names fields, not
// values, so its Overwrite changes `over` alone. A change to more than one
// day (all events, this and following, Planned or Maybe with no scope
// picked) sends no `over` and keeps its shape. Read against the rule and the
// day as shown, never against the product's own builder.
{
  const zone = 'America/Los_Angeles';
  // An ordinary day of a weekly event, as the window holds it (no notes),
  // its instants on this device's clock so its date reads the same in every
  // zone the suite runs in.
  const dayShown = { instanceId: '31:0310', eventId: 31, calendarId: 3, recurring: true, rrule: 'FREQ=WEEKLY', title: 'Sample weekly',
    location: 'Room 2', url: null, status: 'confirmed', relationship: 'planned', start: at(10, '10:00'), end: at(10, '11:00'), allDay: false, tzid: zone };
  // An edited day of it: its own row (32), moved two hours, its own title,
  // its key the day it repeats on.
  const edited = { ...dayShown, instanceId: '32:0310', eventId: 32, seriesId: 31, seriesKey: at(10, '10:00'), isException: true, ownFields: ['title', 'time'],
    title: 'Sample weekly (moved)', start: at(10, '12:00'), end: at(10, '13:00') };
  const asShown = (o) => ({ start: o.start, end: o.end, allDay: false, tzid: zone });
  const held = (o) => ({ title: o.title, location: o.location, url: o.url, status: o.status, ...asShown(o) });
  // The question for a day: `day` is the day as shown, each field says where it changed.
  const dayAsked = (o, fields) => json(409, { error: { code: 'google_changed', message: 'Changed in Google Calendar', day: asShown(o), fields, kept: [] } });
  const googleTime = { start: at(10, '14:00'), end: at(10, '15:00'), allDay: false, tzid: zone };
  const drop = { start: at(11, '10:00'), end: at(11, '11:00') };
  const longer = { start: at(10, '10:00'), end: at(10, '12:00') };
  const lead = /^Since Wed, Mar 10(, 2027)? of 'Sample weekly( \(moved\))?' was last shown to you, /;
  const DAY_STAY = 'Overwrite saves your change for this day only. Cancel saves nothing, and the day shows as it is in Google Calendar now.';
  const DAY_BACK = 'Overwrite saves your change for this day only. Cancel saves nothing and takes you back to your change.';
  const DAY_DELETE = 'Delete deletes this day here and in Google Calendar. Cancel keeps it, as it is in Google Calendar now.';
  const editorOn = async (o, edit) => {
    render(null, ed);
    set({ editor: null, editorDirty: false, occ: new Map([[o.instanceId, o]]) });
    await settle();
    set({ editor: { mode: 'edit', occ: { ...o, full: true } } });
    render(html`<${EditorDrawer} />`, ed);
    await settle();
    for (const s2 of dom.doc.head.childNodes) if (s2.onerror && !s2.failed) { s2.failed = true; s2.onerror(); await settle(); }
    await settle();
    await edit();
    q('form').dispatch('submit');
    await turns(() => !state.editor || writes.length > 1 || (writes.length === 1 && !state.confirmAsk && asked.length), 200);
    await settle();
  };
  // Each caller: what it does to the day, the path it goes to, the request
  // it must send (from the rule: the day's key, and `over` from the day as
  // shown for the fields changed), and the field a question asks about.
  const DAY_CALLERS = [
    { name: 'a drag', o: dayShown, run: (o) => moveEvent({ instanceId: o.instanceId, newStart: drop.start, newEnd: drop.end }),
      want: (o) => ({ ...drop, allDay: false, tzid: zone, scope: 'this', instanceStart: o.start, over: asShown(o) }),
      ask: (o) => ({ field: 'time', was: asShown(o), now: googleTime, yours: { ...drop, allDay: false, tzid: zone }, from: 'day' }) },
    { name: 'a resize', o: dayShown, run: (o) => resizeEvent({ instanceId: o.instanceId, newStart: longer.start, newEnd: longer.end }),
      want: (o) => ({ ...longer, allDay: false, tzid: zone, scope: 'this', instanceStart: o.start, over: asShown(o) }),
      ask: (o) => ({ field: 'time', was: asShown(o), now: googleTime, yours: { ...longer, allDay: false, tzid: zone }, from: 'day' }) },
    { name: 'a drag of an edited day', o: edited, run: (o) => moveEvent({ instanceId: o.instanceId, newStart: drop.start, newEnd: drop.end }),
      want: (o) => ({ ...drop, allDay: false, tzid: zone, scope: 'this', instanceStart: o.start, over: asShown(o) }),
      ask: (o) => ({ field: 'time', was: asShown(o), now: googleTime, yours: { ...drop, allDay: false, tzid: zone }, from: 'day' }) },
    { name: 'the editor, a title, this event', o: dayShown, back: true, editor: true,
      run: (o) => editorOn(o, () => type(q('.bc-ed-title'), 'Sample renamed')),
      want: (o) => ({ title: 'Sample renamed', scope: 'this', instanceStart: o.start, over: { title: o.title } }),
      ask: (o) => ({ field: 'title', was: o.title, now: 'Sample standup', yours: 'Sample renamed', from: 'series' }) },
    { name: 'the editor, the start date, this event', o: dayShown, back: true, editor: true,
      run: (o) => editorOn(o, async () => { const el = q('input[aria-label="Start date"]'); await type(el, dayOf(drop.start)); el.dispatch('blur'); await settle(); }),
      want: (o) => ({ ...drop, allDay: false, tzid: zone, scope: 'this', instanceStart: o.start, over: asShown(o) }),
      // The editor sends its typed clocks beside the time; they are not read here.
      ignore: ['typed'],
      ask: (o) => ({ field: 'time', was: asShown(o), now: googleTime, yours: { ...drop, allDay: false, tzid: zone }, from: 'day' }) },
    { name: 'a delete of this day', o: dayShown, remove: true, run: (o) => deleteEvent(o, 'this'),
      want: (o) => ({ scope: 'this', instanceStart: o.start, over: held(o) }),
      ask: (o) => ({ field: 'location', was: o.location, now: 'Room 5', yours: null, from: 'day' }) },
    // Planned or Maybe has two values: it never asks about itself, so no Overwrite here.
    { name: 'Maybe on this day', o: dayShown, run: (o) => setRelationship(o, 'maybe', 'this'),
      want: (o) => ({ relationship: 'maybe', scope: 'this', instanceStart: o.start, over: { status: 'confirmed' } }) },
    { name: 'Use series value, by the repeating event and the day\'s key', o: edited, plans: true, path: 31, run: (o) => useSeriesValue(o, ['title', 'time']),
      want: (o) => ({ scope: 'this', instanceStart: o.seriesKey, useSeries: ['title', 'time'], over: { title: o.title, ...asShown(o) } }),
      ask: (o) => ({ field: 'title', was: o.title, now: 'Sample weekly (renamed)', yours: 'Sample weekly', from: 'day' }) },
    { name: 'Use series value, by the day\'s own row', o: { ...edited, seriesKey: null }, plans: true, path: 32, run: (o) => useSeriesValue(o, ['location']),
      want: (o) => ({ scope: 'this', instanceStart: o.start, useSeries: ['location'], over: { location: o.location } }),
      ask: (o) => ({ field: 'location', was: o.location, now: 'Room 5', yours: 'Room 2', from: 'day' }) },
  ];
  const strip = (b, keys = []) => { const c = { ...b }; for (const k of keys) delete c[k]; return c; };
  let dRows = 0;
  for (const c of DAY_CALLERS) {
    for (const mode of c.ask ? ['sent', 'overwrite', 'cancel'] : ['sent']) {
      const label = c.name + ', ' + mode;
      const o = c.o;
      const path = c.path || o.eventId;
      // The day's record as read back after the change: "Use series value"
      // gave its fields back, so it holds none of its own.
      records = { [o.eventId]: { ...o, full: true, ...(c.plans ? { ownFields: [] } : {}) } };
      writes = []; calls = []; asked.length = 0;
      reply = mode === 'overwrite' ? [true] : mode === 'cancel' ? [false] : [];
      answerFor = (w, n) => (w.body.preview ? json(200, { plan: null })
        : mode !== 'sent' && n === 1 ? dayAsked(o, [c.ask(o)])
        : json(200, { kept: [{ field: 'title', now: 'Sample standup' }] }));
      set({ occ: new Map([[o.instanceId, o]]), editor: null, popover: null, toasts: [], config: c.plans ? { seriesPlans: true } : {} });
      await c.run(o);
      await settle();
      const mine = writes.filter((w) => !w.body.preview);
      assert.ok(mine.length >= 1, label + ': a request is sent');
      assert.deepEqual(mine.map((w) => w.id), mine.map(() => path), label + ': to the day\'s path, every time');
      assert.deepEqual(strip(mine[0].body, c.ignore), c.want(o), label + ': over from the day as shown, the time whole, the scope and the day\'s key as they were');
      if (mode === 'overwrite') {
        const f = c.ask(o);
        assert.equal(mine.length, 2, label + ': Overwrite sends again');
        const valueOf = (v) => (f.field === 'time' ? v : { [f.field]: v });
        // The same request, `over` at what Google holds now; the body's value
        // at yours where the body sets the field ("Use series value" sets none).
        const sets = c.plans || f.yours == null ? {} : valueOf(f.yours);
        assert.deepEqual(strip(mine[1].body, c.ignore), { ...c.want(o), ...sets, over: { ...c.want(o).over, ...valueOf(f.now) } }, label + ': Overwrite is the same request, over at now');
        assert.match(asked[0].body, lead, label + ': the lead names the day: ' + asked[0].body);
        assert.equal(asked[0].foot, c.remove ? DAY_DELETE : c.back ? DAY_BACK : DAY_STAY, label + ': the foot is for this day');
        if (c.remove) assert.equal(asked[0].title, 'Delete this day anyway?', label + ': titled for the day');
        if (f.from === 'series') assert.match(asked[0].body, /in Google Calendar, for the whole repeating event\. /, label + ': the change to the whole repeating event said');
      }
      if (mode === 'cancel') {
        assert.equal(mine.length, 1, label + ': Cancel sends nothing more');
        assert.ok(!state.toasts.some((t) => !t.error), label + ': no toast of a change made: ' + JSON.stringify(state.toasts.map((t) => t.text)));
      }
      if (mode === 'sent' && !c.editor && !c.remove) {
        const t = state.toasts[state.toasts.length - 1];
        assert.ok(t && /the title is now 'Sample standup'/.test(t.kept || ''), label + ': the toast says what was kept: ' + JSON.stringify(t && [t.text, t.kept]));
      }
      if (c.editor) { render(null, ed); set({ editor: null, editorDirty: false }); await settle(); }
      dRows++;
    }
  }
  // A change to more than one day: no `over`, the shape as it was (the time
  // in parts, as the series' own requests send it).
  const MANY = [
    { name: 'a drag, all events', run: (o) => moveEvent({ instanceId: o.instanceId, newStart: drop.start, newEnd: drop.end, scope: 'all' }),
      want: (o) => ({ ...drop, scope: 'all', instanceStart: o.start }) },
    { name: 'a resize, this and following', run: (o) => resizeEvent({ instanceId: o.instanceId, newStart: longer.start, newEnd: longer.end, scope: 'following' }),
      want: (o) => ({ ...longer, scope: 'following', instanceStart: o.start }) },
    { name: 'a title, all events', run: (o) => updateEvent(o, { title: 'Sample renamed' }, 'all'),
      want: (o) => ({ title: 'Sample renamed', scope: 'all', instanceStart: o.start }) },
    { name: 'a delete, all events', run: (o) => deleteEvent(o, 'all'), want: (o) => ({ scope: 'all', instanceStart: o.start }) },
    { name: 'Maybe, no scope picked (all events)', run: (o) => setRelationship(o, 'maybe'), want: (o) => ({ relationship: 'maybe', scope: 'all', instanceStart: o.start }) },
  ];
  for (const c of MANY) {
    records = { 31: { ...dayShown, full: true } };
    writes = []; calls = []; asked.length = 0; reply = [];
    answerFor = () => json(200, { kept: [] });
    set({ occ: new Map([[dayShown.instanceId, dayShown]]), editor: null, popover: null, toasts: [], config: {} });
    await c.run(dayShown);
    await settle();
    assert.equal(writes.length, 1, c.name + ': one request');
    assert.deepEqual(writes[0].body, c.want(dayShown), c.name + ': no over, its own shape');
    dRows++;
  }
  set({ config: {} });
  reply = [];
  console.log('Google conflict, one day of a repeating event: ' + dRows + ' caller rows, over from the day as shown, Overwrite for the day');
}

off();
globalThis.fetch = realFetch;
console.error = realError;
if (savedRaf === undefined) delete options.requestAnimationFrame; else options.requestAnimationFrame = savedRaf;
if (savedDebounce === undefined) delete options.debounceRendering; else options.debounceRendering = savedDebounce;
dom.restore();
console.log('Google conflict, questions: one at a time, each answered once, every caller released');
