// The question an undo asks (docs/design/google-conflict-choice.md, "Slice
// 3a", (e) and (h)): the undo of an edit to one event or one day on a Google
// calendar whose field was changed again since. Under test: sendUndo, the one
// call Activity's Undo makes, and undoEntries, the toast's Undo, against a
// stand-in for the server that answers 409 google_changed; the dialog is
// answered through the store, as the forward question's tests answer it.
// Cancel undoes nothing and sends nothing more; Undo anyway sends the undo
// again with `over`, each asked field's `now` (a time whole); a newer change
// asks again with the newer value; two questions at once take turns.
// Run: node web/tests/google-undo-question.mjs (google-changed.mjs runs it,
// and smoke.mjs runs that). Invented events only.

import assert from 'node:assert/strict';

const { sendUndo, undoEntries, CANCELLED } = await import('../src/app/googlechange.js');
const { state, set, subscribe } = await import('../src/app/store.js');

const realFetch = globalThis.fetch;
const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
// The server: each entry's undo answered from its own list, every request recorded.
let sent = [];
let answers = {};
globalThis.fetch = async (url, init = {}) => {
  const path = String(url).replace('/api/v1', '');
  const m = /^\/activity\/(\d+)\/undo$/.exec(path);
  if (!m) return json(200, { events: [], cursor: null, calendars: [], folders: [], tags: [] });
  sent.push({ id: Number(m[1]), method: init.method, body: init.body ? JSON.parse(init.body) : null });
  return answers[m[1]].shift();
};
const asks = (fields, extra = {}) => json(409, { error: { code: 'google_changed', message: 'whole sentence', entry: 5, name: 'Weekly sync', fields, kept: [], ...extra } });
const done = (kept) => json(200, { ok: true, undone: { entity: 'event', op: 'update' }, ...(kept ? { kept } : {}) });
const title = (now) => [{ field: 'title', was: 'Weekly sync', now, yours: 'Standup' }];

// The dialog, answered when it shows; `open` counts the questions showing at once.
let asked = [];
let reply = [];
let open = 0;
let most = 0;
const off = subscribe((st) => {
  const a = st.confirmAsk;
  if (!a || a._seen) return;
  a._seen = true;
  open += 1;
  most = Math.max(most, open);
  asked.push({ title: a.title, body: a.body, lines: a.lines, foot: a.foot, confirmLabel: a.confirmLabel });
  // Answered a moment later, so a second question asked meanwhile would show over this one if it could.
  setTimeout(() => { open -= 1; a.resolve(reply.shift()); }, 5);
});
const fresh = (list, replies) => { sent = []; answers = list; asked = []; reply = replies; most = 0; set({ toasts: [] }); };
const said = () => state.toasts.filter((t) => !t.error);

// Cancel: nothing undone, nothing more sent, and the dialog says the undo's own sentences, the event named.
fresh({ 5: [asks(title('Team sync'))] }, [false]);
assert.equal(await sendUndo(5), CANCELLED, 'Cancel: the undo is cancelled');
assert.deepEqual(sent.map((s) => [s.id, s.method, s.body]), [[5, 'POST', {}]], 'Cancel: one request, nothing more sent');
assert.deepEqual(asked, [{
  title: 'Undo anyway?',
  body: "After this change to 'Weekly sync', the title was changed again: it's 'Team sync' now. Undo puts back 'Standup', replacing 'Team sync'.",
  lines: [], foot: 'Cancel undoes nothing, and the event stays as it is now.', confirmLabel: 'Undo anyway',
}], 'the question, read as prose');

// Undo anyway: the same undo again with `over`, the asked field as the question showed it now.
fresh({ 5: [asks(title('Team sync')), done([])] }, [true]);
assert.deepEqual((await sendUndo(5)).undone, { entity: 'event', op: 'update' }, 'Undo anyway: undone');
assert.deepEqual(sent.map((s) => s.body), [{}, { over: { title: 'Team sync' } }], 'Undo anyway: over is the field\'s now');

// A newer change before Undo anyway: asked again with the newer value, and that value is the next `over`.
fresh({ 5: [asks(title('Team sync')), asks(title('Retro')), done([])] }, [true, true]);
await sendUndo(5);
assert.deepEqual(sent.map((s) => s.body), [{}, { over: { title: 'Team sync' } }, { over: { title: 'Retro' } }], 'a newer change: each over is what the last question showed');
assert.match(asked[1].body, /it's 'Retro' now\. Undo puts back 'Standup', replacing 'Retro'\./, 'a newer change: the second question says the newer value');

// A time is one value: `over` carries its four parts as the question served them. A day is named by its date.
const time = (h) => ({ start: '2026-11-17T' + h + ':00:00+00:00', end: '2026-11-17T' + h + ':30:00+00:00', allDay: false, tzid: 'UTC' });
fresh({ 5: [asks([{ field: 'time', was: time('10'), now: time('12'), yours: time('09'), from: 'day' }], { day: time('10') }), done([])] }, [true]);
await sendUndo(5);
assert.deepEqual(sent[1].body, { over: time('12') }, 'a time: over is start, end, allDay and tzid together');
assert.match(asked[0].body, /^After this change to Tue, Nov 17(, 2026)? of 'Weekly sync', the time was changed again: /, 'a day: named by its date and its event');
assert.equal(asked[0].foot, 'Cancel undoes nothing, and the day stays as it is now.', 'a day: the foot says the day');

// From Activity after its own stale question: `force` stays with the undo and never answers this question.
fresh({ 5: [asks(title('Team sync')), done([])] }, [true]);
await sendUndo(5, { force: true });
assert.deepEqual(sent.map((s) => s.body), [{ force: true }, { force: true, over: { title: 'Team sync' } }], 'force: asked all the same, then sent with over');

// A refusal that is no question is thrown as it was.
fresh({ 5: [json(400, { error: { code: 'stale_undo', message: 'Newer changes exist for this item; undoing would overwrite them' } })] }, []);
await assert.rejects(() => sendUndo(5), (e) => e.code === 'stale_undo', 'another refusal throws');
assert.equal(asked.length, 0, 'another refusal asks nothing');

// The toast's Undo: Cancel leaves no toast of an undo; Undo anyway says Undone and what was kept.
fresh({ 5: [asks(title('Team sync'))] }, [false]);
await undoEntries([5]);
assert.deepEqual([sent.length, said().length], [1, 0], 'the toast\'s Undo, Cancel: one request, no toast of an undo');
fresh({ 5: [asks(title('Team sync')), done([{ field: 'location', now: 'Room 5' }])] }, [true]);
await undoEntries([5]);
assert.deepEqual(sent.map((s) => s.body), [{}, { over: { title: 'Team sync' } }], 'the toast\'s Undo, Undo anyway: sent again with over');
assert.deepEqual([said().map((t) => t.text), /the location is now 'Room 5'/.test(said()[0].kept || '')], [['Undone'], true], 'the toast\'s Undo: Undone, and the change kept is named');
// Two entries, newest first: the first undone, the second asked and cancelled stops there, and the toast says what was undone.
fresh({ 6: [done([])], 5: [asks(title('Team sync'))] }, [false]);
await undoEntries([5, 6]);
assert.deepEqual([sent.map((s) => s.id), said().map((t) => t.text)], [[6, 5], ['Undone']], 'the toast\'s Undo over two entries: newest first, Cancel stops, the one undone is said');

// Two questions at once take turns: never two showing, each answered once, each caller given its own answer.
fresh({ 5: [asks(title('Team sync')), done([])], 6: [asks(title('Retro'))] }, [true, false]);
const [a, b] = await Promise.all([sendUndo(5), sendUndo(6)]);
assert.deepEqual([most, asked.length, a && a.ok, b === CANCELLED], [1, 2, true, true], 'two questions: one at a time, the first undone anyway, the second cancelled');
assert.deepEqual(sent.filter((s) => s.id === 6).length, 1, 'two questions: the cancelled one sent nothing more');

off();
globalThis.fetch = realFetch;
console.log('Google conflict, an undo\'s question: Cancel, Undo anyway with over, a newer change, a time whole, the toast, two at once');
