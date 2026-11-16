// The filter form's Which events switch ("that match | that don't match"),
// drawn in the stand-in DOM: a new keyword filter starts on the events that
// match, a new AI prompt filter on the ones that don't; untouched, the
// switch follows the type, and once chosen it stays put whatever the type
// or the action does; an edit opens on the filter's own side; what is saved
// carries it. The AI description's label is neutral. Run: node
// web/tests/filter-form.mjs (smoke.mjs imports it). Invented filters only.

import assert from 'node:assert/strict';
import { fakeDom } from './fakedom.mjs';

let checks = 0;
const eq = (a, b, msg) => { assert.deepEqual(a, b, msg); checks++; };

const dom = fakeDom();
dom.install();
const { options } = await import('../vendor/preact.module.js');
const savedRaf = options.requestAnimationFrame;
const savedDebounce = options.debounceRendering;
options.requestAnimationFrame = (cb) => setImmediate(cb);
options.debounceRendering = (cb) => Promise.resolve().then(cb);
const turns = async (n = 5) => { for (let i = 0; i < n; i++) await new Promise((r) => setImmediate(r)); };
const { render, html } = await import('../vendor/index.js');
const { FilterForm } = await import('../src/app/FiltersPage.js');

const host = document.createElement('div');
document.body.appendChild(host);
const saved = [];
const draw = async (props = {}) => {
  render(null, host);
  render(html`<${FilterForm} calendars=${[{ id: 1, name: 'Sample home' }]} folders=${[]} onSave=${(b) => saved.push(b)} onCancel=${() => {}} ...${props} />`, host);
  await turns();
};
// The stand-in DOM's selectors take no descendant step: the form holds one switch.
const segs = () => host.querySelectorAll('.bc-seg-btn');
const side = () => segs().filter((b) => b.getAttribute('class').includes('is-active')).map((b) => b.textContent);
const typeSelect = () => host.querySelectorAll('select')[0];
const actionSelect = () => host.querySelector('.bc-filter-action').querySelector('select');
const setType = async (v) => { const el = typeSelect(); el.value = v; el.dispatch('change'); await turns(); };
const setAction = async (v) => { const el = actionSelect(); el.value = v; el.dispatch('change'); await turns(); };
const press = async (label) => { segs().find((b) => b.textContent === label).click(); await turns(); };
const submit = async () => { host.querySelector('form').dispatch('submit'); await turns(); return saved[saved.length - 1]; };
const labels = () => host.querySelectorAll('.bc-field').map((f) => f.childNodes.find((c) => c.localName === 'span')).filter(Boolean).map((s) => s.textContent);

await draw();
eq(segs().map((b) => b.textContent), ['that match', "that don't match"], 'the switch reads after the action');
eq(actionSelect().querySelectorAll('option').map((o) => o.textContent), ['Hide events', 'Dim events', 'Highlight events'], 'the action reads as the start of the sentence');
eq(side(), ['that match'], 'a new keyword filter: that match');
await setType('prompt');
eq(side(), ["that don't match"], 'untouched, a new AI prompt filter: that don\'t match');
eq(labels().includes('Describe the events'), true, 'the description\'s label is neutral');
eq(labels().some((l) => /events that match/i.test(l)), false, 'and says no side');
await setType('regex');
eq(side(), ['that match'], 'untouched, a new regex filter: that match');
await press("that don't match");
await setType('prompt');
await setAction('dim');
eq(side(), ["that don't match"], 'chosen, it stays put through a type and an action');
await press('that match');
await setType('keyword');
host.querySelector('input[placeholder]').value = 'webinar';
host.querySelector('input[placeholder]').dispatch('input');
await turns();
const body = await submit();
eq([body.type, body.action, body.appliesTo], ['keyword', 'dim', 'match'], 'saved with the side shown');

await draw({ initial: { id: 4, scope: 'global', scopeId: null, type: 'prompt', action: 'highlight', appliesTo: 'match', enabled: true, config: { prompt: 'outdoor things' } }, inline: true });
eq(side(), ['that match'], 'an edit opens on the filter\'s own side');
await setType('keyword');
eq(side(), ['that match'], 'and a type change there does not move it');
await draw({ initial: { id: 5, scope: 'global', scopeId: null, type: 'prompt', action: 'dim', enabled: true, config: { prompt: 'family days' } }, inline: true });
eq(side(), ["that don't match"], 'a filter listed without a side reads as its kind\'s');

render(null, host);
await turns();
if (savedRaf === undefined) delete options.requestAnimationFrame; else options.requestAnimationFrame = savedRaf;
if (savedDebounce === undefined) delete options.debounceRendering; else options.debounceRendering = savedDebounce;
dom.restore();
console.log('Filter form: ' + checks + ' checks (the Which events switch: defaults by kind, stays where chosen, saved as shown)');
