// With an event open in the panel (or the phone's sheet), one press on
// anything else that opens an event opens that event and leaves the panel
// open (#305). Everything that opens an event says so in one way
// (data-opens-event, read by opensEvent in ui/outside.js), and the panel's
// outside-press rule asks that one question. Drawn in the stand-in DOM: the
// real panel open on one event, each surface drawn beside it, a press
// sent the way the browser sends it (pointerdown at the window, then the
// click), and the panel read back. Run: node web/tests/opens-event.mjs
// (smoke.mjs imports it). Invented events only.

import assert from 'node:assert/strict';
import { fakeDom } from './fakedom.mjs';

const dom = fakeDom();
dom.install();
globalThis.CSS ??= { escape: (s) => String(s) };
class Watcher { observe() {} unobserve() {} disconnect() {} }
globalThis.MutationObserver ??= Watcher;
globalThis.ResizeObserver ??= Watcher;
// The phone's sheet and search keep a history entry of their own.
const hadHistory = 'history' in globalThis, savedHistory = globalThis.history;
const hadMatchMedia = 'matchMedia' in globalThis, savedMatchMedia = globalThis.matchMedia;
globalThis.history = { state: null, pushState(st) { this.state = st; }, replaceState(st) { this.state = st; }, back() { this.state = null; }, go() { this.state = null; } };
globalThis.matchMedia = (q) => dom.win.matchMedia(q);
const { options } = await import('../vendor/preact.module.js');
const savedRaf = options.requestAnimationFrame;
const savedDebounce = options.debounceRendering;
options.requestAnimationFrame = (cb) => setImmediate(cb);
options.debounceRendering = (cb) => Promise.resolve().then(cb);
const settle = async () => { for (let i = 0; i < 12; i++) await new Promise((r) => setImmediate(r)); };
const { render, html } = await import('../vendor/index.js');
const { state, set } = await import('../src/app/store.js');
const { opensEvent } = await import('../src/ui/outside.js');
const { EventPopover } = await import('../src/app/EventPopover.js');
const { EventChip, EventBar, EventBlock, TripBand } = await import('../src/ui/EventChip.js');
const { ContextStrip, ContextMark, DayLabel } = await import('../src/ui/ContextStrip.js');
const { SearchOverlay } = await import('../src/app/SearchOverlay.js');

const realFetch = globalThis.fetch;
const json = (body) => new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
const found = { instanceId: '72@1', eventId: 72, calendarId: 1, title: 'Sample choir', start: '2027-05-04T19:00:00Z', end: '2027-05-04T20:00:00Z', allDay: false, upcoming: true };
globalThis.fetch = async (url) => {
  const u = new URL(String(url), 'http://app.test');
  if (u.pathname.endsWith('/search')) return json({ results: [found] });
  return json({});
};

const at = (n, more = {}) => ({
  instanceId: n + '@1', eventId: n, calendarId: 1, title: 'Sample event ' + n,
  start: '2027-05-03T09:00:00Z', end: '2027-05-03T10:00:00Z', allDay: false, ...more,
});
const opened = at(70);
const others = Object.fromEntries([71, 73, 74, 75, 76, 77, 78].map((n) => [n, at(n)]));
others[75] = at(75, { allDay: true, start: '2027-05-03', end: '2027-05-04', isContainer: true });
others[76] = at(76, { relationship: 'context', title: 'Sample sunset' });
others[77] = at(77, { relationship: 'context', title: 'Sample tide' });
others[78] = at(78, { allDay: true, start: '2027-05-03', end: '2027-05-04', dayLabel: true, title: 'Sample holiday' });
const cal = { id: 1, name: 'Sample home', color: '#3a7bd5', visible: true, editable: true, kind: 'local', folderIds: [] };
set({ calendars: [cal], folders: [], tags: [], route: 'calendar' });
for (const o of [opened, found, ...Object.values(others)]) state.occ.set(o.instanceId, o);
set({ occVersion: state.occVersion + 1 });

const panelHost = dom.doc.createElement('div');
const surfaces = dom.doc.createElement('div');
dom.doc.body.appendChild(panelHost);
dom.doc.body.appendChild(surfaces);
render(html`<${EventPopover} />`, panelHost);

// What App.js does with a press on an event in the calendar.
const onOpen = (instanceId) => set({ popover: { instanceId, anchorRect: null } });
const seg = { contLeft: false, contRight: false };
const rect = { top: 0, height: 60, leftPct: 0, widthPct: 100 };

// Each surface: how to draw it, and the element a person presses.
const cases = [
  ['a month or agenda chip', html`<${EventChip} occ=${others[71]} cal=${cal} onOpen=${onOpen} />`, '.bc-chip', '71@1'],
  ['an all-day bar', html`<${EventBar} occ=${others[73]} cal=${cal} seg=${seg} onOpen=${onOpen} />`, '.bc-bar', '73@1'],
  ['a timed block', html`<${EventBlock} occ=${others[74]} cal=${cal} rect=${rect} onOpen=${onOpen} />`, '.bc-block', '74@1'],
  // A handle inside the block is part of it: the press reaches it (a resize
  // starts there), and the panel stays on the event it showed.
  ["a timed block's resize handle", html`<${EventBlock} occ=${others[74]} cal=${cal} rect=${rect} onOpen=${onOpen} onEdgePointerDown=${() => {}} />`, '.bc-block-handle', '70@1'],
  ['a trip band', html`<${TripBand} occ=${others[75]} cal=${cal} seg=${seg} onOpen=${onOpen} />`, '.bc-band', '75@1'],
  ["a day's context token", html`<${ContextStrip} occs=${[others[76]]} calendars=${{ 1: cal }} onOpen=${onOpen} />`, '.bc-ctx-token', '76@1'],
  ["the timeline's context mark", html`<${ContextMark} occ=${others[77]} cal=${cal} top=${0} onOpen=${onOpen} />`, '.bc-ctx-mark-hit', '77@1'],
  ['a holiday label', html`<${DayLabel} occs=${[others[78]]} onOpen=${onOpen} />`, '.bc-daylabel', '78@1'],
  ["a phone month's holiday flag", html`<${DayLabel} occs=${[others[78]]} onOpen=${onOpen} flag=${true} />`, '.bc-daylabel', '78@1'],
];

const press = async (el) => {
  const ev = { type: 'pointerdown', target: el, cancelable: true, preventDefault() {}, stopPropagation() {} };
  for (const fn of [...(dom.win.listeners.pointerdown || [])]) fn(ev);
  const stillOpen = !!state.popover;
  el.click();
  await settle();
  return [stillOpen, state.popover && state.popover.instanceId];
};
const openOn = async (occ) => { set({ popover: { instanceId: occ.instanceId, anchorRect: null } }); await settle(); };

let checked = 0;
for (const phone of [false, true]) {
  const where = phone ? 'phone sheet' : 'desktop panel';
  dom.win.matchMedia = () => ({ matches: phone, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
  for (const [name, view, sel, expect] of cases) {
    render(view, surfaces);
    await openOn(opened);
    const el = surfaces.querySelector(sel);
    assert.ok(el, name + ': drawn');
    assert.ok(opensEvent(el), `${name}: says it opens an event`);
    assert.deepEqual(await press(el), [true, expect], `${where}, ${name}: one press opens the pressed event and the panel stays`);
    render(null, surfaces);
    await settle();
    checked++;
  }

  // Search over the open event: a result opens in one press.
  render(html`<${SearchOverlay} />`, surfaces);
  await openOn(opened);
  set({ searchOpen: true });
  await settle();
  const input = surfaces.querySelector('.bc-search-input');
  input.value = 'choir';
  input.dispatch('input');
  await new Promise((r) => setTimeout(r, 300));
  await settle();
  const result = surfaces.querySelector('.bc-search-row');
  assert.ok(result && opensEvent(result), 'a search result says it opens an event');
  assert.deepEqual(await press(result), [true, '72@1'], `${where}, a search result: one press opens it and the panel stays`);
  checked++;
  assert.equal(state.searchOpen, false, 'and search closes behind it');
  render(null, surfaces);
  await settle();
}

// The rule is the press's nearest pressable thing: a control inside
// something that opens an event takes its press for itself, and a press on
// nothing that opens an event still only closes the panel.
dom.win.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
render(html`<div><div class="bc-sample-row" data-opens-event=""><button type="button" class="bc-sample-act">Act</button></div><button type="button" class="bc-sample-plain">Plain</button></div>`, surfaces);
await openOn(opened);
assert.equal(opensEvent(surfaces.querySelector('.bc-sample-act')), false, 'a button inside something that opens an event does not open it');
const plain = surfaces.querySelector('.bc-sample-plain');
plain.click = () => {};
assert.deepEqual(await press(plain), [false, null], 'a press on something else closes the panel');
render(null, surfaces);
render(null, panelHost);
await settle();

// Search's jump flashes the event a moment later (actions.js jumpToDate):
// let that run while the stand-in DOM is still here.
await new Promise((r) => setTimeout(r, 300));
globalThis.fetch = realFetch;
set({ popover: null, searchOpen: false, groupPopover: null });
if (savedRaf === undefined) delete options.requestAnimationFrame; else options.requestAnimationFrame = savedRaf;
if (savedDebounce === undefined) delete options.debounceRendering; else options.debounceRendering = savedDebounce;
dom.restore();
if (hadHistory) globalThis.history = savedHistory; else delete globalThis.history;
if (hadMatchMedia) globalThis.matchMedia = savedMatchMedia; else delete globalThis.matchMedia;
console.log(`Opening an event over the open panel: ${checked} surfaces on a desktop and a phone, one press each`);
