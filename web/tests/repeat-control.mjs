// The editor's repeat control (EditorDrawer.js, lib/repeat.js): the choices
// say a number goes with them ("Every … days"), and once one is picked the
// row reads as one sentence with the number inside it ("Every 45 days").
// Driven through the real editor in the stand-in DOM: for each unit and each
// interval (1, 2, 45, the largest a rule may hold, one more), the row is set
// and saved, the rule the stand-in server receives is read, and an event with
// that rule is opened again to read the row back. One more than the largest
// is said under the row and nothing is sent. Run: node
// web/tests/repeat-control.mjs (smoke.mjs imports it). Invented events only.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fakeDom } from './fakedom.mjs';

const { MAX_INTERVAL, REPEAT_UNITS, repeatChoiceLabels } = await import('../src/lib/repeat.js');

// The client's largest interval is the server's.
const server = readFileSync(new URL('../../server/src/Domain/Recurrence.php', import.meta.url), 'utf8');
assert.equal(Number((server.match(/MAX_INTERVAL\s*=\s*(\d+)/) || [])[1]), MAX_INTERVAL, 'the editor allows exactly the interval the server accepts');

// The labels, as a person reads them.
assert.deepEqual(repeatChoiceLabels('none', 1).map((c) => c.label), ['Does not repeat', 'Every … days', 'Every … weeks', 'Every … months', 'Every … years'],
  'before a choice, each option says a number goes with it');
assert.deepEqual(repeatChoiceLabels('DAILY', 1).map((c) => c.label), ['Does not repeat', 'day', 'week', 'month', 'year'], 'after a choice, the units for 1');
assert.deepEqual(repeatChoiceLabels('WEEKLY', 45).map((c) => c.label), ['Does not repeat', 'days', 'weeks', 'months', 'years'], 'and for more than 1');

const dom = fakeDom();
dom.install();
const { options } = await import('../vendor/preact.module.js');
const savedRaf = options.requestAnimationFrame;
const savedDebounce = options.debounceRendering;
options.requestAnimationFrame = (cb) => setImmediate(cb);
options.debounceRendering = (cb) => Promise.resolve().then(cb);
const { render, html } = await import('../vendor/index.js');
const { state, set } = await import('../src/app/store.js');
const { EditorDrawer } = await import('../src/app/EditorDrawer.js');
const turns = async (until = () => false, max = 60) => { for (let i = 0; i < max && !until(); i++) await new Promise((r) => setImmediate(r)); };
const realError = console.error;
console.error = (...a) => { if (!String(a[0]).startsWith('Rich text editor failed')) realError(...a); };

set({ calendars: [{ id: 1, name: 'Sample home', kind: 'local', editable: true, visible: true, role: 'mine' }], people: [] });
const realFetch = globalThis.fetch;
const sent = [];
const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
globalThis.fetch = async (url, init = {}) => {
  const path = new URL(String(url), 'http://app.test').pathname.replace('/api/v1', '');
  if (init.method === 'PATCH' && path === '/events/9') { sent.push(JSON.parse(init.body)); return json(200, { ok: true }); }
  return json(200, { events: [], cursor: null });
};

// A fixed date and clock: the 10th of March 2027, 10:00 to 11:00 on this device.
const pad = (n) => String(n).padStart(2, '0');
const local = (d) => { const o = -d.getTimezoneOffset(); const a = Math.abs(o); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':00' + (o < 0 ? '-' : '+') + pad(Math.floor(a / 60)) + ':' + pad(a % 60); };
const event = (rrule) => ({ instanceId: '9:x', eventId: 9, calendarId: 1, title: 'Sample chore', recurring: !!rrule, rrule: rrule || null, full: true,
  start: local(new Date(2027, 2, 10, 10)), end: local(new Date(2027, 2, 10, 11)), allDay: false, tzid: Intl.DateTimeFormat().resolvedOptions().timeZone,
  status: 'confirmed', relationship: 'planned', tags: [], people: [], reminderSource: 'default', reminders: [] });

const ed = dom.doc.createElement('div');
dom.doc.body.appendChild(ed);
const q = (sel) => ed.querySelector(sel);
const open = async (occ) => {
  render(null, ed);
  set({ editor: null, editorDirty: false, occ: new Map([[occ.instanceId, occ]]) });
  await turns();
  set({ editor: { mode: 'edit', occ } });
  render(html`<${EditorDrawer} />`, ed);
  await turns();
  for (const s of dom.doc.head.childNodes) if (s.onerror && !s.failed) { s.failed = true; s.onerror(); await turns(); }
  await turns();
};
// The repeat row as a person reads it: its words, the number typed, the unit shown.
const sentence = () => {
  const row = q('.bc-ed-repeat').querySelector('.bc-ed-inline');
  const sel = row.querySelector('select.bc-ed-freq');
  const shown = sel.querySelectorAll('option').find((o) => o.value === sel.value);
  const num = row.querySelector('input.bc-ed-every');
  return [num ? 'Every' : null, num ? num.value : null, shown && shown.textContent].filter((x) => x != null).join(' ');
};

let rows = 0;
for (const [freq, [one, more]] of Object.entries(REPEAT_UNITS)) {
  for (const n of [1, 2, 45, MAX_INTERVAL, MAX_INTERVAL + 1]) {
    const label = freq + ' every ' + n;
    await open(event(null));
    assert.equal(sentence(), 'Does not repeat', label + ': opens on no repeat');
    const sel = q('select.bc-ed-freq');
    sel.value = freq; sel.dispatch('change'); await turns();
    const num = q('input.bc-ed-every');
    assert.equal(num.getAttribute('aria-label'), 'Repeat every how many', label + ': the number says what it is');
    assert.equal(q('select.bc-ed-freq').getAttribute('aria-label'), 'Repeat unit', label + ': and so does the unit');
    num.value = String(n); num.dispatch('input'); await turns();
    const word = n === 1 ? one : more;
    assert.equal(sentence(), 'Every ' + n + ' ' + word, label + ': the row reads as one sentence');
    sent.length = 0;
    q('form').dispatch('submit');
    await turns(() => sent.length || !state.editor, 120);
    await turns();
    if (n > MAX_INTERVAL) {
      assert.deepEqual(sent, [], label + ': over the largest, nothing is sent');
      assert.equal(q('.bc-ed-err').textContent, 'Repeats can be up to every ' + MAX_INTERVAL.toLocaleString() + ' ' + more + '.', label + ': and the row says so');
      const saves = ed.querySelectorAll('button').filter((b) => b.getAttribute('type') === 'submit');
      assert.ok(saves.length && saves.every((b) => b.disabled === true || b.getAttribute('disabled') != null), label + ': Save waits for it: ' + JSON.stringify(saves.map((b) => [b.textContent, b.disabled, b.getAttribute('disabled')])));
      rows++;
      continue;
    }
    assert.equal(sent.length, 1, label + ': saved');
    const parts = Object.fromEntries(String(sent[0].rrule).split(';').map((p) => p.split('=')));
    assert.equal(parts.FREQ, freq, label + ': the rule repeats by the unit');
    assert.equal(parts.INTERVAL, n > 1 ? String(n) : undefined, label + ': and the number, when more than 1');
    // And back: the same rule opens as the same sentence.
    await open(event(sent[0].rrule));
    assert.equal(sentence(), 'Every ' + n + ' ' + word, label + ': opened again, the row reads the same');
    rows++;
  }
}
render(null, ed);
set({ editor: null, editorDirty: false });
await turns();
globalThis.fetch = realFetch;
console.error = realError;
if (savedRaf === undefined) delete options.requestAnimationFrame; else options.requestAnimationFrame = savedRaf;
if (savedDebounce === undefined) delete options.debounceRendering; else options.debounceRendering = savedDebounce;
dom.restore();
console.log('Repeat control: ' + rows + ' unit and interval rows read as one sentence, saved and opened again; the largest interval is the server\'s (' + MAX_INTERVAL + ')');
