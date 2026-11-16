// The original of an event being moved fades, whatever kind it is: a week
// or day block, an all-day bar, a month chip, a trip band. DragController
// marks it (is-drag-source) while it is lifted and the drop would move it,
// takes the mark off for an Option/Alt copy and when the drag ends, and the
// stylesheet fades it in one rule for every kind. Drawn in the stand-in DOM
// and dragged with stand-in pointer events. Run: node
// web/tests/drag-source.mjs (smoke.mjs imports it). Invented events only.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fakeDom } from './fakedom.mjs';

let checks = 0;
const ok = (cond, msg) => { assert.ok(cond, msg); checks++; };

// --- the stylesheet: one rule, for any element marked ------------------------
const css = readFileSync(new URL('../styles/app.css', import.meta.url), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
const fades = [...css.matchAll(/([^{}]*\.is-drag-source[^{}]*)\{([^}]*)\}/g)].filter(([, , body]) => /opacity/.test(body));
ok(fades.some(([, sel, body]) => sel.trim() === '.is-drag-source' && /opacity:\s*0\.4\s*!important/.test(body)), 'the fade is one rule on the mark alone, over any resting style');
ok(fades.every(([, sel]) => !/\.is-drag-source/.test(sel.replace(/:not\(\.is-drag-source\)/g, '')) || sel.trim() === '.is-drag-source'), 'no kind of event has a fade of its own beside it');

// --- drawn and dragged -------------------------------------------------------
const dom = fakeDom();
dom.install();
const { options } = await import('../vendor/preact.module.js');
const savedRaf = options.requestAnimationFrame;
const savedDebounce = options.debounceRendering;
options.requestAnimationFrame = (cb) => setImmediate(cb);
options.debounceRendering = (cb) => Promise.resolve().then(cb);
const settle = async () => { for (let i = 0; i < 6; i++) await new Promise((r) => setImmediate(r)); };
const { render, html } = await import('../vendor/index.js');
const { EventChip, EventBar, EventBlock, TripBand } = await import('../src/ui/EventChip.js');
const { startPointerDrag } = await import('../src/ui/DragController.js');

const cal = { id: 1, name: 'Sample home', color: '#3a7bd5', visible: true, editable: true };
const occ = (n, more = {}) => ({ instanceId: n + '@1', eventId: n, calendarId: 1, title: 'Sample event ' + n, start: '2027-05-03T09:00:00Z', end: '2027-05-03T10:00:00Z', allDay: false, ...more });
const seg = { contLeft: false, contRight: false };
const kinds = [
  ['a month chip', html`<${EventChip} occ=${occ(1)} cal=${cal} />`, '.bc-chip'],
  ['an all-day bar in week or day view', html`<${EventBar} occ=${occ(2, { allDay: true, start: '2027-05-03', end: '2027-05-05' })} cal=${cal} seg=${seg} />`, '.bc-bar'],
  ['a timed block in week or day view', html`<${EventBlock} occ=${occ(3)} cal=${cal} rect=${{ top: 0, height: 60, leftPct: 0, widthPct: 100 }} />`, '.bc-block'],
  ['a trip band', html`<${TripBand} occ=${occ(4, { allDay: true, start: '2027-05-03', end: '2027-05-06', isContainer: true })} cal=${cal} seg=${seg} />`, '.bc-band'],
];
const fire = (type, x, more = {}) => {
  const ev = { type, clientX: x, clientY: 10, pointerId: 1, pointerType: 'mouse', button: 0, buttons: type === 'pointerup' ? 0 : 1, altKey: false, cancelable: true, preventDefault() {}, stopPropagation() {}, ...more };
  for (const fn of [...(dom.win.listeners[type] || [])]) fn(ev);
};
const host = dom.doc.createElement('div');
dom.doc.body.appendChild(host);
for (const [name, view, sel] of kinds) {
  render(view, host);
  await settle();
  const src = host.querySelector(sel);
  const marked = () => src.classList.contains('is-drag-source');
  startPointerDrag({ clientX: 10, clientY: 10, pointerId: 1, pointerType: 'mouse', button: 0 }, { source: src, copyable: true });
  ok(!marked(), `${name}: not faded before it lifts`);
  fire('pointermove', 40);
  ok(marked(), `${name}: faded while it is moved`);
  fire('keydown', 40, { key: 'Alt' });
  ok(!marked(), `${name}: as it was while Option makes the drop a copy`);
  fire('keyup', 40, { key: 'Alt' });
  ok(marked(), `${name}: faded again when Option is let go`);
  fire('pointerup', 40);
  ok(!marked(), `${name}: as it was once dropped`);
  startPointerDrag({ clientX: 10, clientY: 10, pointerId: 1, pointerType: 'mouse', button: 0 }, { source: src });
  fire('pointermove', 40);
  fire('keydown', 40, { key: 'Escape' });
  ok(!marked(), `${name}: as it was once the drag is cancelled`);
  render(null, host);
  await settle();
}
await new Promise((r) => setTimeout(r, 400)); // the click the drop suppresses
if (savedRaf === undefined) delete options.requestAnimationFrame; else options.requestAnimationFrame = savedRaf;
if (savedDebounce === undefined) delete options.debounceRendering; else options.debounceRendering = savedDebounce;
dom.restore();

// Every caller that moves an event names it as the source.
const src = (f) => readFileSync(new URL('../src/ui/' + f, import.meta.url), 'utf8');
ok((src('TimeGrid.js').match(/source: src,/g) || []).length === 2, 'week and day view name the block and the bar they move');
ok(/makeGhost: \(\) => cloneAsGhost\(src\),\s*source: src,/.test(src('MonthGrid.js')), 'month view (and the day sheet through it) names what it moves');
ok(!/classList\.(add|remove)\('is-drag-source'\)/.test(src('TimeGrid.js') + src('MonthGrid.js')), 'no view marks the source itself');
console.log(`Drag source: ${checks} checks, each kind of event faded while moved and as it was after`);
