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
const { state, set, subscribe, adoptEvent } = await import('../src/app/store.js');
const { EditorDrawer } = await import('../src/app/EditorDrawer.js');
const { deleteEvent, moveEvent } = await import('../src/app/actions.js');
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
const changed = (fields) => json(409, { error: { code: 'google_changed', message: 'Changed in Google Calendar', fields, kept: [] } });

// Answer each question as it shows, from `reply`; record what it said.
const asked = [];
let reply = [];
const off = subscribe((st) => {
  const a = st.confirmAsk;
  if (!a || a._seen) return;
  a._seen = true;
  asked.push({ title: a.title, body: a.body });
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
const timeOf = (key) => { const d = Number(key.slice(8)); return { start: at(d, '10:00'), end: at(d, '11:00'), allDay: false }; };
const opening = {
  instanceId: '7:open', eventId: 7, calendarId: 3, recurring: false, rrule: null, full: true,
  title: 'Sample review', url: 'https://example.test/one', location: 'Room 2', description: 'Bring the agenda',
  status: 'confirmed', relationship: 'planned', ...timeOf('2027-03-03'), tags: ['alpha'], people: [],
  isContainer: false, reminderSource: 'event', reminders: [{ minutes: 30 }],
};

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
  const ask = NAMES.filter((f) => FIELDS[f].google && FIELDS[f].sent(body) !== undefined && FIELDS[f].over(over) !== model.google[f]);
  if (ask.length) return changed(ask.map((f) => ({ field: f === 'notes' ? 'description' : f === 'link' ? 'url' : f, was: wireOf(f, FIELDS[f].over(over)), now: wireOf(f, model.google[f]), yours: wireOf(f, FIELDS[f].sent(body)) })));
  return json(200, { kept: [], occurrence: { ...storedOcc(), instanceId: '7:saved' } });
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
const ROW_KEYS = new Set(['over', 'title', 'start', 'end', 'allDay', 'typed', 'location', 'locationLat', 'locationLng', 'url', 'description', 'status', 'tagNames', 'calendarId', 'isContainer', 'reminders']);

let checks = 0;
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
  assert.deepEqual(readRequest(sent[0]), { fields: want.first.fields, over: want.first.over }, label + ': exactly what differs from the base, over from the base');
  for (const k of Object.keys(sent[0])) assert.ok(ROW_KEYS.has(k), label + ': only rows of changed fields go: ' + k);
  checks += 2;
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

let rows = 0;
for (const f of NAMES) {
  for (const [name, path] of Object.entries(PATHS)) {
    if (!FIELDS[f].google && /what Google holds|poll/.test(name)) continue; // a local field has no value at Google
    const list = path(f, steps);
    if (!list) continue;
    model = { form: {}, base: {}, google: {} };
    for (const g of NAMES) { model.form[g] = FIELDS[g].O; model.base[g] = FIELDS[g].O; model.google[g] = FIELDS[g].O; }
    await mount();
    // A Save that closes the editor ends the path (a two-valued field can
    // hold nothing but Google's value after its question).
    for (const step of list) { if (!state.editor) break; await step(f + ', ' + name); }
    rows++;
  }
}
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
  const value = (k, o) => (k === 'time' ? ('start' in o ? { start: o.start, end: o.end, allDay: !!o.allDay } : undefined) : o[k]);
  const over = body.over || {};
  const differs = ['title', 'location', 'time'].filter((k) => value(k, over) !== undefined && JSON.stringify(value(k, over)) !== JSON.stringify(value(k, held)));
  if (differs.length) return changed(differs.map((k) => ({ field: k, was: value(k, over), now: value(k, held), yours: init.method === 'DELETE' ? null : (value(k, body) ?? value(k, over)) })));
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

off();
globalThis.fetch = realFetch;
console.error = realError;
if (savedRaf === undefined) delete options.requestAnimationFrame; else options.requestAnimationFrame = savedRaf;
if (savedDebounce === undefined) delete options.debounceRendering; else options.debounceRendering = savedDebounce;
dom.restore();
console.log('Google conflict, questions: one at a time, each answered once, every caller released');
