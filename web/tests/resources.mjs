// Execute the actual handoff and trip-rendering boundaries with invented input.
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import assert from 'node:assert/strict';
import { stripToText } from '../src/lib/richtext.js';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const head = [...read('../index.html').matchAll(/<script>([^]*?)<\/script>/g)].map(m => m[1]).find(s => s.includes('bc-handoff'));
const parked = new Map(); let address;
const location = new URL('https://calendar.example.test/subscribe?url=old#url=https%3A%2F%2Fexample.test%2Ffeed.ics%3Fkey%3Dsample');
runInNewContext(head, { location, localStorage: { getItem: () => null }, document: {}, sessionStorage: { setItem: (k, v) => parked.set(k, v) }, history: { replaceState: (_a, _b, v) => { address = v; } } });
assert.equal(address, '/');
assert.equal(JSON.parse(parked.get('bc-handoff')).hash, location.hash, 'cold launch retains fragment before clearing address');
const patches = [], requests = [];
const handoffSource = read('../src/app/handoff.js').replace(/^import .*;$/gm, '').replace(/export /g, '');
const handoff = runInNewContext(handoffSource + '\nrunHandoff', {
  URLSearchParams, TextDecoder, localTz: () => 'UTC', set: p => patches.push(p), toast: () => {},
  api: async (path, request) => { requests.push([path, request]); return { draft: { start: '2027-01-01T09:00:00Z', title: 'Sample' } }; },
});
await handoff(JSON.parse(parked.get('bc-handoff')));
assert.equal(patches.at(-1).createDrawer.url, 'https://example.test/feed.ics?key=sample', 'fragment wins over legacy query');
await handoff({ path: '/add', hash: '#text=Sample&dates=20270101T090000Z/20270101T100000Z' });
assert.equal(requests.at(-1)[1].body.text, 'https://calendar.google.com/calendar/render?text=Sample&dates=20270101T090000Z/20270101T100000Z');
await handoff({ path: '/subscribe', search: '?url=https%3A%2F%2Fexample.test%2Fold.ics' });
assert.equal(patches.at(-1).createDrawer.url, 'https://example.test/old.ics', 'legacy handlers still work during transition');

const main = read('../src/app/main.js'); let consumer; let launch;
runInNewContext(main.slice(main.indexOf("if ('launchQueue' in window)"), main.indexOf('\nrender(html')), {
  window: { launchQueue: { setConsumer: fn => { consumer = fn; } } }, location,
  sessionStorage: { getItem: () => null }, URL, runHandoff: async h => { launch = h; },
});
await consumer({ targetURL: location.href });
assert.equal(launch.hash, location.hash, 'already-open installed app retains fragment too');
const rules = runInNewContext(read('../../extension/rules.js') + '\nrulesFor("https://calendar.example.test")');
assert.equal(rules[0].action.redirect.regexSubstitution, 'https://calendar.example.test/add#\\1');
const manifest = JSON.parse(read('../manifest.webmanifest'));
assert.equal(manifest.protocol_handlers[0].url, '/subscribe#url=%s');

const trip = read('../src/app/Trips.js');
const expression = trip.match(/linkify\((stripToText\(String\(occ.description\)\))\)/)?.[1];
assert.ok(expression, 'trip renderer uses the shared linear text boundary');
const crafted = '<a'.repeat(32767);
assert.equal(runInNewContext(expression, { stripToText, occ: { description: crafted } }), crafted);
assert.equal(runInNewContext(expression, { stripToText, occ: { description: '<p>Sample</p>' } }).trim(), 'Sample');
console.log('Resource UI and deep-link regression checks passed');
