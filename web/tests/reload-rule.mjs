// A new version reloads the page by itself when nothing would be lost, and
// offers the reload otherwise (#323, drafts.js). One rule decides "nothing
// would be lost" (whatWouldBeLost), over every state that holds it; a page
// reloads by itself at most once for a version; the offer stays while
// something is open, and the reload follows once nothing is.
import assert from 'node:assert/strict';

const storage = () => {
  const m = new Map();
  return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) };
};
const saved = {
  localStorage: globalThis.localStorage, sessionStorage: globalThis.sessionStorage,
  document: globalThis.document, location: globalThis.location, fetch: globalThis.fetch,
};
globalThis.localStorage = storage();
globalThis.sessionStorage = storage();

const drafts = await import('../src/app/drafts.js');
const { whatWouldBeLost, pageNow, decideReload, holdReload } = drafts;
const { state, set } = await import('../src/app/store.js');
const { confirmTurn } = await import('../src/app/ConfirmDialog.js');
const { api, writesPending } = await import('../src/app/api.js');
const { openLayers } = await import('../src/ui/outside.js');
// A stand-in page: the open layers, the body's classes, the focused field.
const page = { layers: [], bodyClasses: new Set(), dragging: false, active: null };
const layer = (kind = '', cls = '') => ({ kind, getAttribute: (a) => (a === 'data-layer' ? kind : null), classList: { contains: (c) => c === cls } });
globalThis.document = {
  querySelectorAll: (sel) => (sel === '[data-layer]' ? page.layers : []),
  querySelector: (sel) => (sel === '.is-dragging' && page.dragging ? {} : null),
  get body() { return { classList: { contains: (c) => page.bodyClasses.has(c) } }; },
  get activeElement() { return page.active; },
  addEventListener() {},
  visibilityState: 'visible',
};
const replaced = [];
globalThis.location = { search: '', replace: (u) => replaced.push(u) };

set({ booted: true, authed: false });

// --- the rule over a snapshot: each input alone holds the reload ---------------
const quiet = { layers: 0, forms: 0, question: false, choices: 0, moving: false, typing: false, writes: 0, starting: false };
assert.deepEqual(whatWouldBeLost(quiet), [], 'nothing open: nothing would be lost');
for (const key of Object.keys(quiet)) {
  const one = { ...quiet, [key]: typeof quiet[key] === 'number' ? 1 : true };
  assert.deepEqual(whatWouldBeLost(one), [key], key + ' alone holds the reload');
}
assert.deepEqual(Object.keys(pageNow()).sort(), Object.keys(quiet).sort(), 'the page is read for every input of the rule');

// --- the page read where each state lives ----------------------------------------
const lost = () => whatWouldBeLost();
assert.deepEqual(lost(), [], 'a quiet page, an event panel or not: nothing would be lost');

page.layers = [layer('')];
assert.deepEqual(lost(), ['layers'], 'an open layer (the editor, quick add, search, a menu, a dialog) holds it');
page.layers = [layer('top')];
assert.deepEqual(lost(), ['layers'], 'a confirm or password step-up (a top layer) holds it');
assert.equal(openLayers().length, 0, 'the keyboard still reads top layers as taking no keys');
page.layers = [layer('top', 'bc-toasts')];
assert.deepEqual(lost(), [], 'the toast list alone holds nothing');

set({ toasts: [{ id: 1, text: 'Saved', undoable: false }] });
assert.deepEqual(lost(), [], 'a toast with nothing to choose holds nothing');
set({ toasts: [{ id: 2, text: 'Moved', undoable: true, undo: [5] }] });
assert.deepEqual(lost(), ['choices'], 'a toast still offering Undo holds it');
set({ toasts: [{ id: 3, text: 'Draft discarded', actionLabel: 'Undo' }] });
assert.deepEqual(lost(), ['choices'], 'a toast with an action holds it');
set({ toasts: [{ id: 4, text: 'Changed in Google Calendar', actions: [{ label: 'Keep mine', run() {} }] }] });
assert.deepEqual(lost(), ['choices'], 'a toast with choices holds it');
set({ toasts: [] });
page.layers = [];

const turn = await confirmTurn();
assert.deepEqual(lost(), ['question'], 'a question held for asking (Cancel or Overwrite) holds it');
turn.release();
assert.deepEqual(lost(), [], 'and lets go once answered');

holdReload('invented-settings', true);
assert.deepEqual(lost(), ['forms'], 'a page form with unsaved changes holds it');
holdReload('invented-settings', false);

page.bodyClasses.add('bc-dragging');
assert.deepEqual(lost(), ['moving'], 'a drag in progress holds it');
page.bodyClasses.clear();
page.dragging = true;
assert.deepEqual(lost(), ['moving'], 'a list being reordered holds it');
page.dragging = false;
set({ reschedule: { instanceId: 'x1' } });
assert.deepEqual(lost(), ['moving'], 'reschedule mode holds it');
set({ reschedule: null });

page.active = { tagName: 'INPUT', type: 'text', value: 'Invented note' };
assert.deepEqual(lost(), ['typing'], 'a focused field with text holds it');
page.active = { tagName: 'INPUT', type: 'text', value: '' };
assert.deepEqual(lost(), [], 'a focused empty field holds nothing');
page.active = { tagName: 'INPUT', type: 'checkbox', value: 'on' };
assert.deepEqual(lost(), [], 'a focused checkbox holds nothing');
page.active = null;

let answer;
globalThis.fetch = () => new Promise((r) => { answer = r; });
const write = api('/events/1', { method: 'PATCH', body: { title: 'Invented' } });
await Promise.resolve();
assert.equal(writesPending(), 1, 'a write in flight is counted');
assert.deepEqual(lost(), ['writes'], 'a write not yet answered holds it');
answer({ status: 200, ok: true, json: async () => ({}), headers: { get: () => null } });
await write;
assert.equal(writesPending(), 0, 'an answered write is not');
const read = api('/events');
await Promise.resolve();
assert.deepEqual(lost(), [], 'a read in flight holds nothing');
answer({ status: 200, ok: true, json: async () => ({}), headers: { get: () => null } });
await read;

set({ booted: false });
assert.deepEqual(lost(), ['starting'], 'a page still starting (a link or share not yet opened) holds it');
set({ booted: true });

// --- what to do about a version --------------------------------------------------
assert.equal(decideReload({ version: 'bc-v2', page: 'bc-v2', tried: null, lost: [] }), 'none', 'the page already runs it');
assert.equal(decideReload({ version: 'bc-v2', page: 'bc-v1', tried: null, lost: [] }), 'reload', 'nothing would be lost: reload');
assert.equal(decideReload({ version: 'bc-v2', page: 'bc-v1', tried: null, lost: ['layers'] }), 'offer', 'something would be lost: offer');
assert.equal(decideReload({ version: 'bc-v2', page: 'bc-v1', tried: 'bc-v1', lost: [] }), 'reload', 'a reload for an older version does not count');
assert.equal(decideReload({ version: 'bc-v2', page: 'bc-v1', tried: 'bc-v2', lost: [] }), 'offer', 'reloaded for it once and still old: offer, never again');
assert.equal(decideReload({ version: 'bc-v2', page: null, tried: 'bc-v2', lost: [] }), 'offer', 'a page that cannot tell its version reloads once too');

// --- the flow: offer while something is open, reload once nothing is ---------------
page.layers = [layer('')];
drafts.newVersionActive('bc-v2');
assert.equal(replaced.length, 0, 'an open editor: no reload');
assert.equal(state.appUpdateReady, true, 'the offer shows');
drafts.reloadIfReady();
assert.equal(replaced.length, 0, 'the page changes and the editor is still open: still the offer');
set({ appUpdateReady: false }); // Not now
page.layers = [];
drafts.reloadIfReady();
assert.equal(replaced.length, 1, 'the editor closed: the page reloads by itself');
assert.match(replaced[0], /^\/\?updated=/, 'by the same path as the banner\'s Reload');
assert.equal(sessionStorage.getItem('bc-reloaded-for'), 'bc-v2', 'the version it reloaded for is written down');
drafts.reloadIfReady();
assert.equal(replaced.length, 1, 'once only while it leaves');

// The page after that reload (a module of its own), still told of the same version.
const after = await import('../src/app/drafts.js?after-reload');
set({ appUpdateReady: false });
after.newVersionActive('bc-v2');
assert.equal(replaced.length, 1, 'reloaded for this version once already: no second reload');
assert.equal(state.appUpdateReady, true, 'the offer instead');
after.reloadIfReady();
assert.equal(replaced.length, 1, 'and none at the next change either');

// A later version, nothing open: at once.
const next = await import('../src/app/drafts.js?next-version');
next.newVersionActive('bc-v3');
assert.equal(replaced.length, 2, 'a new version and nothing open: the page reloads right away');
assert.equal(sessionStorage.getItem('bc-reloaded-for'), 'bc-v3');

set({ appUpdateReady: false, toasts: [] });
Object.assign(globalThis, saved);
console.log('Reload-for-a-new-version tests passed');
