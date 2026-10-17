// Exercise the component's real handlers with deferred useState updates.
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import assert from 'node:assert/strict';

const source = readFileSync(process.env.BC_COPY_SOURCE || new URL('../src/app/CopyTo.js', import.meta.url), 'utf8')
  .replace(/^import .*;\n/gm, '').replace('export function CopyTo', 'function CopyTo');
let slots = [], index = 0;
const calls = [];
const CopyTo = runInNewContext(source + '\nCopyTo', {
  html: (strings, ...values) => ({ strings, values }),
  useState: (initial) => { const i = index++; slots[i] ??= initial; return [slots[i], (v) => { slots[i] = v; }]; },
  state: { calendars: [{ id: 1, editable: true }, { id: 2, editable: true }, { id: 3, editable: true }] },
  copyEventTo: async (...args) => { calls.push(args); return true; },
});
const render = (recurring = false) => { index = 0; return CopyTo({ occ: { calendarId: 1, recurring } }); };
const change = (tree, id) => tree.values.find((v) => typeof v === 'function')({ target: { value: String(id) } });
change(render(), 2);
await new Promise(setImmediate);
assert.equal(calls.at(-1)?.[1], 2, 'first choice copies to that calendar');
change(render(), 3);
await new Promise(setImmediate);
assert.equal(calls.at(-1)?.[1], 3, 'next choice cannot use the previous destination');
change(render(), '');
assert.equal(calls.length, 2, 'empty choice does not copy');
slots = [];
change(render(true), 3);
assert.equal(calls.length, 2, 'recurring choice waits for explicit scope');
const nested = render(true).values.find((v) => v?.strings?.join('').includes('This occurrence'));
await nested.values.find((v) => typeof v === 'function')();
assert.equal(calls.at(-1)[1], 3);
assert.equal(calls.at(-1)[2], 'this');
console.log('Copy-to destination regression tests passed');
