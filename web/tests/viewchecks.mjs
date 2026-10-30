// Browser checks of the calendar views: navigation, what each view loads and
// shows, and where drags may land. Run on a test install with invented data
// (it moves the view around, refreshes and tries a drag), signed in, from
// the browser console or an automated browser:
//
//   const { run } = await import('/assets/tests/viewchecks.mjs?' + Date.now());
//   console.table(await run());
//
// Only a test install serves this file: releases leave web/tests out. Each
// check says what it found; none of them edits or creates anything unless a
// regression lets a drag through (then the event moves, as a person's would).
// Started from the findings of a review of every view (#183).

const wait = (ms) => new Promise((r) => setTimeout(r, ms));

// Waits for a condition, polling, up to a limit; returns whether it held.
async function until(cond, ms = 4000) {
  const end = Date.now() + ms;
  while (Date.now() < end) {
    try { if (cond()) return true; } catch { /* not yet */ }
    await wait(50);
  }
  return false;
}

// The app's own module instances: the same version query the page loaded.
async function appModule(name) {
  const url = performance.getEntriesByType('resource').map((e) => e.name)
    .find((n) => new RegExp('/app/' + name + '\\.js\\?v=').test(n));
  if (!url) throw new Error('the page has not loaded ' + name + '.js');
  return import(url);
}

const pad = (n) => String(n).padStart(2, '0');
const keyOf = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
const addDays = (key, n) => { const [y, m, d] = key.split('-').map(Number); return keyOf(new Date(y, m - 1, d + n)); };
const monthOf = (key) => ({ year: Number(key.slice(0, 4)), month: Number(key.slice(5, 7)) });
const sameMonth = (a, b) => !!a && !!b && a.year === b.year && a.month === b.month;

export async function run() {
  // Scrolling is handled on animation frames, which a hidden or background
  // page doesn't get: the scroll checks would fail for that reason alone.
  const frames = await Promise.race([
    new Promise((r) => requestAnimationFrame(() => r(true))),
    wait(500).then(() => false),
  ]);
  if (!frames) return [{ check: 'page shown', ok: false, detail: 'no animation frames: bring the page to the front and run again' }];
  const A = await appModule('actions');
  const S = await appModule('store');
  const API = await appModule('api');
  const st = () => S.state;
  const results = [];
  const check = async (name, fn) => {
    try {
      const detail = await fn();
      results.push({ check: name, ok: detail === true || (detail && detail.ok !== false), detail: typeof detail === 'object' ? JSON.stringify(detail) : String(detail) });
    } catch (e) {
      results.push({ check: name, ok: false, detail: String(e && e.message || e) });
    }
  };
  const start = { view: st().view, anchor: st().anchor };
  const today = keyOf(new Date());

  // Day view: the month follows Today, the arrows and a jump.
  await check('day view: month follows a far step', async () => {
    A.setView('day');
    await until(() => document.querySelector('.bc-tg-vday-head'));
    const want = addDays(A.hereKey(), 45);
    A.navigate(45);
    const ok = await until(() => st().anchor === want && sameMonth(st().visibleMonth, monthOf(want)) && st().visibleDays && st().visibleDays.start === want);
    return { ok, want, anchor: st().anchor, visibleMonth: st().visibleMonth };
  });
  await check('day view: Today comes back to today', async () => {
    A.goToday();
    const ok = await until(() => st().anchor === today && sameMonth(st().visibleMonth, monthOf(today)));
    return { ok, anchor: st().anchor, visibleMonth: st().visibleMonth };
  });

  // Week view: Next starts from where you scrolled, not where you last navigated.
  await check('week view: Next after scrolling goes on from there', async () => {
    A.setView('week');
    // Scroll only once the view has landed, or its landing undoes the scroll.
    await until(() => document.querySelector('.bc-tg-hscroll') && st().anchorSeen === st().scrollSeq);
    const hs = document.querySelector('.bc-tg-hscroll');
    const before = st().visibleDays && st().visibleDays.start;
    hs.scrollLeft += hs.clientWidth * 5;
    await until(() => st().visibleDays && st().visibleDays.start > addDays(before || today, 21));
    const scrolled = st().visibleDays;
    A.navigate(7);
    const want = addDays(scrolled.start, Math.floor(6 / 2) + 7);
    const ok = await until(() => st().anchor === want && st().visibleDays && st().anchor >= st().visibleDays.start && st().anchor <= st().visibleDays.end);
    return { ok, scrolled, want, anchor: st().anchor, now: st().visibleDays };
  });
  await check('week view: Next twice in quick succession goes two weeks on', async () => {
    const from = A.hereKey();
    A.navigate(7);
    A.navigate(7);
    const want = addDays(from, 14);
    const ok = await until(() => st().visibleDays && want >= st().visibleDays.start && want <= st().visibleDays.end);
    return { ok: ok && st().anchor === want, want, anchor: st().anchor };
  });
  await check('Today, then a view switch straight away, opens on today', async () => {
    A.goToday();
    A.setView('day');
    const ok = await until(() => document.querySelector('.bc-tg-vday-head') && st().anchor === today);
    A.setView('week');
    await until(() => st().visibleDays && today >= st().visibleDays.start && today <= st().visibleDays.end);
    return { ok, anchor: st().anchor };
  });
  await check('switching views keeps the scrolled-to place', async () => {
    await until(() => st().anchorSeen === st().scrollSeq);
    const hs = document.querySelector('.bc-tg-hscroll');
    const before = st().visibleDays.start;
    hs.scrollLeft += hs.clientWidth * 4;
    await until(() => st().visibleDays.start > addDays(before, 14));
    const days = st().visibleDays;
    A.setView('day');
    const ok = await until(() => document.querySelector('.bc-tg-vday-head') && st().anchor >= days.start && st().anchor <= days.end);
    return { ok, weekShowed: days, dayOpenedOn: st().anchor };
  });

  // A refresh keeps only what it refetched as loaded.
  await check('after a refresh only the refreshed stretch counts as loaded', async () => {
    A.setView('month');
    await until(() => document.querySelector('.bc-month-rule, [data-day]'));
    await API.refreshWindow();
    const r = st().loadedRanges;
    return { ok: r.length <= 1, ranges: r.length };
  });

  // Bands: people's away times show in week and day, a day short no more.
  const span = (st().availSpans || [])[0];
  if (span) {
    await check('week view shows away and busy times', async () => {
      A.jumpToDate(span.start.slice(0, 10));
      A.setView('week');
      const ok = await until(() => document.querySelector('.bc-tg-allday-lane .bc-band.is-avail, .bc-tg-htrack .bc-band.is-avail'));
      return { ok };
    });
    await check('day view shows them in its header', async () => {
      A.setView('day');
      const ok = await until(() => document.querySelector('.bc-tg-vday-allday .bc-band.is-avail'));
      return { ok };
    });
  } else {
    results.push({ check: 'away and busy bands', ok: true, detail: 'skipped: no away or busy times on this install' });
  }

  // Read-only calendars: no resize handles, no drop target in the sidebar.
  await check('only movable events have resize handles', async () => {
    A.setView('month');
    await until(() => document.querySelector('.bc-bar[data-instance], .bc-band[data-instance]'));
    const cals = Object.fromEntries(st().calendars.map((c) => [c.id, c]));
    const wrong = [...document.querySelectorAll('.bc-bar[data-instance], .bc-band[data-instance], .bc-block[data-instance]')]
      .filter((el) => el.querySelector('.bc-bar-handle, .bc-block-handle'))
      .map((el) => st().occ.get(el.dataset.instance) || { instanceId: el.dataset.instance })
      .filter((o) => String(o.instanceId).startsWith('plg:') || (cals[o.calendarId] && !cals[o.calendarId].editable && !cals[o.calendarId].reschedulable))
      .map((o) => o.instanceId);
    return { ok: wrong.length === 0, wrong };
  });
  await check('sidebar takes drops only on calendars an event can move to', async () => {
    const byId = Object.fromEntries(st().calendars.map((c) => [String(c.id), c]));
    const wrong = [...document.querySelectorAll('[data-drop-cal]')]
      .map((el) => byId[el.dataset.dropCal])
      .filter((c) => !c || !c.editable || c.provider === 'google')
      .map((c) => (c ? c.id : '?'));
    return { ok: wrong.length === 0, wrong };
  });

  // A drag let go off the grid moves nothing.
  await check('week view: a drag let go above the grid moves nothing', async () => {
    A.jumpToDate(today);
    A.setView('week');
    await until(() => document.querySelector('.bc-block[data-instance]'));
    const cals = Object.fromEntries(st().calendars.map((c) => [c.id, c]));
    const grid = document.querySelector('.bc-tg-scroll').getBoundingClientRect();
    const block = [...document.querySelectorAll('.bc-block[data-instance]')].find((el) => {
      const o = st().occ.get(el.dataset.instance);
      const r = el.getBoundingClientRect();
      return o && cals[o.calendarId] && cals[o.calendarId].editable && r.height > 20
        && r.top > grid.top && r.bottom < grid.bottom - 80 && r.left > grid.left && r.right < grid.right;
    });
    if (!block) return { ok: true, skipped: 'no timed event on screen' };
    const id = block.dataset.instance;
    const before = st().occ.get(id).start;
    const title = block.querySelector('.bc-chip-title') || block;
    const r = title.getBoundingClientRect();
    const x0 = r.left + Math.min(10, r.width / 2);
    const y0 = r.top + r.height / 2;
    const opts = (x, y, buttons = 1) => ({ bubbles: true, cancelable: true, clientX: x, clientY: y, pointerId: 1, pointerType: 'mouse', button: 0, buttons, isPrimary: true });
    const move = (x, y) => window.dispatchEvent(new PointerEvent('pointermove', opts(x, y)));
    const previewShown = () => { const p = document.querySelector('.bc-tg-preview'); return !!p && getComputedStyle(p).display !== 'none'; };
    S.set({ dropChoice: null });
    title.dispatchEvent(new PointerEvent('pointerdown', opts(x0, y0)));
    move(x0 + 12, y0 + 12);
    const lifted = document.body.classList.contains('bc-dragging');
    // Over the grid, an hour on: the drag is live and shows where it would land.
    move(x0 + 12, y0 + 60);
    const shownInside = previewShown();
    // Over the day headings above the grid: nowhere to land.
    move(x0 + 12, grid.top - 20);
    const shownOutside = previewShown();
    window.dispatchEvent(new PointerEvent('pointerup', opts(x0 + 12, grid.top - 20, 0)));
    await wait(800);
    const after = st().occ.get(id) && st().occ.get(id).start;
    // A repeating event asks This/Following/All before it moves: asking at
    // all means the drop found somewhere to land.
    const asked = !!st().dropChoice;
    S.set({ dropChoice: null });
    return { ok: lifted && shownInside && !shownOutside && after === before && !asked, lifted, shownInside, shownOutside, before, after, asked };
  });

  A.setView(start.view);
  A.jumpToDate(start.anchor);
  return results;
}
