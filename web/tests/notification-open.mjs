// Opening an event from a tapped reminder (#26): straight to the event, never
// the calendar first. Three parts, each run for real with what is around it
// stubbed: the service worker's click (web/sw.js, with a stubbed clients
// API), the app's first load (main.js boot, with its calls stubbed), and the
// link's handling in the app (push.js). Run: node web/tests/notification-open.mjs
// (smoke.mjs imports it). Invented events only.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const ORIGIN = 'https://cal.example.test';
const LINK = '/?event=' + encodeURIComponent('42:20270406T170000Z') + '&at=' + encodeURIComponent('2027-04-06T17:00:00Z');

// ---- the service worker's click -------------------------------------------
{
  const source = readFileSync(new URL('../sw.js', import.meta.url), 'utf8');
  const worker = (windows) => {
    const handlers = {};
    const log = [];
    const sandbox = {
      self: { addEventListener: (type, fn) => { handlers[type] = fn; }, registration: {}, location: { origin: ORIGIN }, clients: {} },
      clients: {
        matchAll: async () => windows(log),
        openWindow: async (url) => { log.push(['open', url]); },
      },
      location: { origin: ORIGIN },
      URL, Response, Headers, Request, caches: {}, console: { warn: () => {} },
    };
    runInNewContext(source, sandbox);
    const click = async (data, action = '') => {
      let done = Promise.resolve();
      handlers.notificationclick({ notification: { close: () => log.push(['close']), data }, action, waitUntil: (p) => { done = p; } });
      await done;
      // Plain data: the worker's objects are of another realm.
      return JSON.parse(JSON.stringify(log));
    };
    return click;
  };
  const appWindow = (url = ORIGIN + '/') => (log) => [{
    url,
    focus: async () => { log.push(['focus']); },
    navigate: async (to) => { log.push(['navigate', to]); },
    postMessage: (message) => { log.push(['post', message]); },
  }];

  assert.deepEqual(await worker(appWindow())({ url: LINK }),
    [['close'], ['post', { type: 'open-event', url: LINK }], ['focus']],
    'sw: an open app window is told which event to open and brought forward, not reloaded at the link');
  assert.deepEqual(await worker(() => [])({ url: LINK }),
    [['close'], ['open', LINK]],
    'sw: with no window open, one opens on the link');
  assert.deepEqual(await worker(appWindow('https://elsewhere.example.test/'))({ url: LINK }),
    [['close'], ['open', LINK]],
    'sw: a window of another origin is not this app');
  assert.deepEqual(await worker(appWindow())({ url: LINK, map: 'https://maps.example.test/?q=hall' }, 'map'),
    [['close'], ['open', 'https://maps.example.test/?q=hall']],
    'sw: the Map button still goes straight out');
  assert.deepEqual(await worker(appWindow())({}),
    [['close'], ['post', { type: 'open-event', url: '/' }], ['focus']],
    'sw: a notification with no link brings the app forward, with nothing to open');
}

// ---- the app's first load ---------------------------------------------------
// boot() from main.js, its calls stubbed and recorded: the linked event is
// opened, and its opening finished, before the app is marked booted, which is
// what first draws the calendar (App.js).
{
  const main = readFileSync(new URL('../src/app/main.js', import.meta.url), 'utf8');
  const start = main.indexOf('async function boot() {');
  const end = main.indexOf('\n}\n', start) + 2;
  assert.ok(start > 0 && end > start, 'main.js has its boot()');
  const boot = (handoff, linkOpens) => {
    const log = [];
    const ctx = runInNewContext(main.slice(start, end) + '\nboot', {
      takeHandoff: async () => handoff,
      set: (patch) => log.push(['set', Object.keys(patch).join(',')]),
      sessionStorage: { getItem: () => null, setItem: () => {} },
      QA_WINDOW_KEY: 'qa',
      fetchMe: async () => log.push(['me']),
      loadSession: async () => log.push(['session']),
      recordStart: () => {},
      history: { state: null, replaceState: () => {} },
      restoreResume: () => log.push(['resume']),
      handleEventLink: () => { log.push(['link']); return linkOpens.then(() => log.push(['link opened'])); },
      startSession: () => log.push(['start']),
      reportStalledStart: () => {},
    });
    return { log, run: ctx() };
  };
  let opened;
  const linkOpens = new Promise((resolve) => { opened = resolve; });
  const { log, run } = boot(null, linkOpens);
  // Let boot go as far as it can while the event's window is still loading.
  for (let i = 0; i < 20; i++) await new Promise((r) => setImmediate(r));
  assert.deepEqual(log, [['me'], ['session'], ['resume'], ['link']], 'boot: the link is opening and the app is not yet drawn');
  opened();
  await run;
  assert.deepEqual(log, [['me'], ['session'], ['resume'], ['link'], ['link opened'], ['set', 'booted,pendingHandoff'], ['start']],
    'boot: the app is drawn once the event is open, in the same frame');
  const qa = boot({ path: '/new', hash: '#window' }, Promise.resolve());
  await qa.run;
  assert.ok(!qa.log.some(([k]) => k === 'link'), 'boot: quick add on its own window opens no event');
}

// ---- the link in the app -----------------------------------------------------
// push.js against the real store: the address holds a link until it is opened,
// a running session opens one at once, and opening takes it off the address.
{
  const realWindow = globalThis.window;
  const loc = { origin: ORIGIN, pathname: '/', search: '', hash: '' };
  const hist = {
    state: null,
    replaceState(s, _title, url) {
      this.state = s;
      const u = new URL(url, ORIGIN);
      loc.pathname = u.pathname; loc.search = u.search; loc.hash = u.hash;
    },
  };
  globalThis.window = { location: loc, history: hist, addEventListener: () => {}, removeEventListener: () => {} };
  try {
    const { handleEventLink, receiveEventLink } = await import('../src/app/push.js');
    const { state, set } = await import('../src/app/store.js');
    const id = '42:20270406T170000Z';
    state.occ.set(id, { instanceId: id, eventId: 42, title: 'Sample workshop', start: '2027-04-06T10:00:00-07:00', end: '2027-04-06T11:00:00-07:00', allDay: false });
    const was = { booted: state.booted, authed: state.authed, route: state.route, popover: state.popover, quickAddWindow: state.quickAddWindow };

    // Still starting (or signed out): the link waits in the address.
    set({ booted: false, authed: false, popover: null, route: 'calendar' });
    receiveEventLink(LINK);
    assert.equal(loc.search, '?event=' + encodeURIComponent(id) + '&at=' + encodeURIComponent('2027-04-06T17:00:00Z'), 'app: a link that comes before the session waits in the address');
    assert.equal(state.popover, null, 'app: and opens nothing yet');
    set({ booted: true, authed: true });
    await handleEventLink();
    assert.equal(state.popover && state.popover.instanceId, id, 'app: the session start opens it');
    assert.equal(loc.search, '', 'app: and takes it off the address');
    set({ popover: null });
    await handleEventLink();
    assert.equal(state.popover, null, 'app: a second look finds nothing to open');

    // Running, on another page, with that page's history entry: opened now,
    // on the calendar, the entry kept.
    set({ route: 'settings', popover: null });
    hist.state = { bcRoute: 'settings' };
    receiveEventLink(LINK);
    await new Promise((r) => setImmediate(r));
    assert.equal(state.popover && state.popover.instanceId, id, 'app: a running session opens the event at once');
    assert.equal(state.route, 'calendar', 'app: on the calendar');
    assert.equal(loc.search, '', 'app: the address is bare again');
    assert.deepEqual(hist.state, { bcRoute: 'settings' }, 'app: the history entry the page made is kept');

    // Not this app's link: nothing happens.
    set({ popover: null });
    receiveEventLink('https://elsewhere.example.test' + LINK);
    receiveEventLink('/?at=2027-04-06T17:00:00Z');
    receiveEventLink('not a url ::');
    await new Promise((r) => setImmediate(r));
    assert.deepEqual([state.popover, loc.search], [null, ''], 'app: another origin, or no event, opens nothing and leaves the address');

    // Quick add in a window of its own does not open events.
    set({ quickAddWindow: true });
    receiveEventLink(LINK);
    await new Promise((r) => setImmediate(r));
    assert.equal(state.popover, null, 'app: the quick add window opens no event');
    hist.replaceState(null, '', '/');

    state.occ.delete(id);
    set(was);
  } finally {
    globalThis.window = realWindow;
  }
}

console.log('Notification open checks passed');
