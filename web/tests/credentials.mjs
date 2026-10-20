// Execute real component handlers with tiny hook stubs, without a browser or network.
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import assert from 'node:assert/strict';

const html = (strings, ...values) => ({ strings, values });
const walk = (node) => Array.isArray(node) ? node.flatMap(walk) : node?.values ? [node, ...node.values.flatMap(walk)] : [node];
function component(file, name, globals = {}, initial = []) {
  let slots = initial, index = 0;
  let effects = [], effectIndex = 0, pending = [];
  let source = readFileSync(new URL('../src/app/' + file, import.meta.url), 'utf8').replace(/^import .*;\n/gm, '').replace(/^export /gm, '');
  if (name === 'OtherBrowsersRow') source = source.slice(source.indexOf('function OtherBrowsersRow('), source.indexOf('\nfunction Row(', source.indexOf('function OtherBrowsersRow(')));
  const fn = runInNewContext(source + '\n' + name, {
    html, useEffect: (fn, deps) => { const i = effectIndex++; const key = JSON.stringify(deps); if (effects[i] !== key) { effects[i] = key; pending.push(fn); } }, useStore: (f) => f({ people: [], calendars: [], plugins: [] }),
    useState: (value) => { const i = index++; if (!(i in slots)) slots[i] = typeof value === 'function' ? value() : value; return [slots[i], (v) => { slots[i] = typeof v === 'function' ? v(slots[i]) : v; }]; },
    ...globals,
  });
  return { render: (props) => { index = 0; effectIndex = 0; pending = []; const tree = fn(props); for (const effect of pending) effect(); return tree; }, slots };
}
const schema = [{ key: 'credential', label: 'Credential', type: 'secret' }, { key: 'mode', label: 'Mode', type: 'text' }];
const writes = [];
const form = component('SchemaForm.js', 'SchemaForm', { PlaceInput: () => {} });
const props = { schema, values: { credential: { set: true }, mode: 'before' }, onSave: async (body) => { writes.push(body); return true; } };
form.render(props);
form.slots[0].mode = 'after';
form.render({ ...props, values: { credential: { set: true }, mode: 'before' } });
assert.equal(form.slots[0].mode, 'after', 'unrelated plugin reload preserves unsaved edits');
const save = (tree) => walk(tree).filter((n) => typeof n === 'function').at(-1)();
await save(form.render(props));
assert.equal(Object.hasOwn(writes.at(-1), 'credential'), false, 'omitted secret is never re-submitted as its status object');
form.slots[0].credential = 'invented-new-secret';
await save(form.render(props));
assert.equal(writes.at(-1).credential, 'invented-new-secret');
assert.equal(JSON.stringify(form.slots).includes('invented-new-secret'), false, 'successful save clears plaintext from draft');
form.slots[0].credential = null;
await save(form.render(props));
assert.equal(writes.at(-1).credential, null, 'explicit clear is sent');

const ep = component('EventPluginData.js', 'EventPluginData', {
  useEffect: () => {},
  useStore: (f) => f({ plugins: [{ id: 'sample', name: 'Sample', enabled: true, eventSettingsSchema: schema }] }),
  SchemaForm: () => {}, Icon: () => {}, toast: () => {}, api: async () => ({ saved: { credential: { set: true } } }),
}, [{ sample: { credential: { set: false } } }]);
const handler = walk(ep.render({ eventId: 1 })).find((n) => typeof n === 'function' && n.constructor.name === 'AsyncFunction');
await handler({ credential: 'invented-new-secret' });
assert.equal(JSON.stringify(ep.slots).includes('invented-new-secret'), false, 'event editor stores projected server response, not submitted secret');
assert.equal(ep.slots[0].sample.credential.set, true);

const google = component('GoogleConnector.js', 'GoogleConnector', { useEffect: () => {}, WorkerNote: () => {}, Icon: () => {}, usePasswordStepUp: () => ({ prompt: null, run: async (_, action) => action() }) }, [
  { configured: true, accounts: [{ id: 1, email: 'sam@example.test', status: 'error', reauthRequired: true }] }, {}, null, null,
]);
const output = JSON.stringify(google.render());
assert.ok(output.includes('Reconnect needed'), 'Settings plainly labels a revoked Google grant');
assert.ok(!output.includes('Connection problem'), 'revoked grant is not an ordinary sync failure');
assert.ok(!output.includes('Asking Google'), 'revoked grant does not keep trying calendar discovery');
google.slots[0].accounts[0].reauthRequired = false;
assert.ok(JSON.stringify(google.render()).includes('Connection problem'), 'transient failure does not incorrectly demand reconnection');

const recoveryWrites = [];
const recovery = component('SettingsPage.js', 'OtherBrowsersRow', {
  useEffect: () => {}, Row: () => {}, toast: () => {}, currentEndpointHash: async () => null,
  api: async (path, options) => {
    if (!options) return { others: 1, version: 'sample-version', subscriptions: [{ id: 1, name: 'Sample feed', host: 'feeds.example.test', addedAt: '2027-01-01T00:00:00Z', fingerprint: 'sample-fingerprint' }] };
    recoveryWrites.push(options.body);
    return { sessions: 1, subscriptionsPaused: options.body.subscriptionReview.choice === 'pause' ? 1 : 0 };
  },
}, [1, false, null]);
await walk(recovery.render()).find((n) => typeof n === 'function' && n.name === 'prepare')();
assert.equal(recoveryWrites.length, 0, 'opening confirmation does not revoke or pause anything');
const recoveryTree = recovery.render();
assert.ok(JSON.stringify(recoveryTree).includes('feeds.example.test'), 'confirmation shows feed host');
await walk(recoveryTree).find((n) => typeof n === 'function' && n.toString().includes("run('pause')"))();
assert.equal(recoveryWrites[0].subscriptionReview.choice, 'pause');
assert.equal(recoveryWrites[0].subscriptionReview.version, 'sample-version');
assert.deepEqual(recoveryWrites[0].subscriptionReview.reviewed, ['sample-fingerprint'], 'the page names exactly the subscriptions the owner saw');
console.log('Credential UI regression tests passed');
