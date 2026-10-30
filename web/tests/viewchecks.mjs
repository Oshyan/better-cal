// Browser checks of the calendar views: navigation, what each view loads and
// shows, and where drags may land. Run them only on a test install with
// invented data, signed in, with the page in front, from the browser console
// or an automated browser:
//
//   const { run } = await import('/assets/tests/viewchecks.mjs?' + Date.now());
//   console.table(await run());
//
// Never on an install with real data: they move the view around, refresh,
// and try a drag, and if a regression lets that drag land, the event really
// moves (or a repeating one asks This/Following/All). Release zips leave
// web/tests out, but an install deployed by copying the repository serves it.
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
  // Without the browser's scroll anchoring (Safari), each arrow press used to
  // leave the view a day off the date it named. Turned off here to match.
  await check('day view: the arrows land on their day without scroll anchoring', async () => {
    const sc = document.querySelector('.bc-tg-scroll');
    sc.style.overflowAnchor = 'none';
    const topDay = () => {
      let cur = null;
      for (const p of sc.querySelectorAll('[data-vday]')) { if (p.offsetTop <= sc.scrollTop + 4) cur = p.dataset.vday; else break; }
      return cur;
    };
    const off = [];
    for (let i = 0; i < 4; i++) {
      A.navigate(i < 2 ? 1 : -1);
      await until(() => topDay() === st().anchor, 1500);
      if (topDay() !== st().anchor) off.push(st().anchor + ' showed ' + topDay());
    }
    sc.style.overflowAnchor = '';
    return { ok: off.length === 0, off };
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
    const want = addDays(addDays(scrolled.start, Math.floor((new Date(scrolled.end) - new Date(scrolled.start)) / 864e5 / 2)), 7);
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

  // Month with rows that fit their contents: Today glides, and a data load
  // landing mid-glide used to stop it part way. Loads are simulated by
  // nudging the occurrence version while it moves.
  await check('month view: a glide finishes when data lands during it', async () => {
    const rows = st().settings.monthRows;
    S.set({ settings: { ...st().settings, monthRows: 'fit' } });
    A.setView('month');
    A.jumpToDate(addDays(today, 21));
    await until(() => st().anchorSeen === st().scrollSeq);
    await wait(500);
    A.goToday();
    for (let i = 0; i < 6; i++) { await wait(60); S.set({ occVersion: st().occVersion + 1 }); }
    await wait(1500);
    const sc = document.querySelector('.bc-month-scroll');
    const cell = sc && sc.querySelector(`[data-day="${today}"]`);
    const r = cell && cell.getBoundingClientRect();
    const box = sc && sc.getBoundingClientRect();
    const ok = !!(r && box && r.top >= box.top - 2 && r.bottom <= box.top + box.height * 0.75);
    S.set({ settings: { ...st().settings, monthRows: rows } });
    return { ok, todayRowTop: r && box ? Math.round(r.top - box.top) : null };
  });

  // A refresh leaves what's held on screen but marks everything outside the
  // refreshed stretch to be fetched again.
  await check('after a refresh only the refreshed stretch is fresh', async () => {
    A.setView('month');
    await until(() => document.querySelector('.bc-month-rule, [data-day]') && !st().windowStatus);
    await wait(500); // the month's own window requests
    const held = st().loadedRanges.reduce((n, r) => n + (r.end - r.start), 0);
    await API.refreshWindow();
    const after = st().loadedRanges.reduce((n, r) => n + (r.end - r.start), 0);
    const fresh = S.freshRanges();
    return { ok: held > 0 && after >= held && fresh.length === 1, heldDays: Math.round(held / 864e5), freshDays: fresh.length ? Math.round((fresh[0].end - fresh[0].start) / 864e5) : 0 };
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
    await until(() => st().anchorSeen === st().scrollSeq);
    const cals = Object.fromEntries(st().calendars.map((c) => [c.id, c]));
    const grid = document.querySelector('.bc-tg-scroll').getBoundingClientRect();
    const movable = () => [...document.querySelectorAll('.bc-block[data-instance]')].find((el) => {
      const o = st().occ.get(el.dataset.instance);
      const r = el.getBoundingClientRect();
      return o && cals[o.calendarId] && cals[o.calendarId].editable && r.height > 20
        && r.top > grid.top && r.bottom < grid.bottom - 80 && r.left > grid.left && r.right < grid.right;
    });
    // Look a few weeks on for a timed event in view.
    let block = movable();
    for (let i = 0; !block && i < 8; i++) {
      A.navigate(7);
      await until(() => st().anchorSeen === st().scrollSeq);
      await wait(300);
      block = movable();
    }
    if (!block) return { ok: false, detail: 'no editable timed event within eight weeks: nothing to drag' };
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

  const opts = (x, y, buttons = 1) => ({ bubbles: true, cancelable: true, clientX: x, clientY: y, pointerId: 1, pointerType: 'mouse', button: 0, buttons, isPrimary: true });

  // Week view: a click in empty all-day space starts an all-day event.
  await check('week view: clicking the all-day lane starts an all-day event', async () => {
    A.jumpToDate(today);
    A.setView('week');
    await until(() => st().anchorSeen === st().scrollSeq);
    const lane = document.querySelector('.bc-tg-allday .bc-tg-htrack, .bc-tg-allday-lane');
    const hs = document.querySelector('.bc-tg-hscroll').getBoundingClientRect();
    const lr = lane.parentElement.getBoundingClientRect();
    const y = lr.bottom - 3;
    let x = null;
    for (let px = hs.left + 20; px < hs.right - 20; px += 15) {
      if (document.elementFromPoint(px, y) === lane) { x = px; break; }
    }
    if (x == null) return { ok: false, detail: 'no empty all-day space on screen' };
    S.set({ editor: null });
    lane.dispatchEvent(new PointerEvent('pointerdown', opts(x, y)));
    window.dispatchEvent(new PointerEvent('pointerup', opts(x, y, 0)));
    const ok = await until(() => st().editor && st().editor.mode === 'create' && st().editor.draft && st().editor.draft.allDay, 1500);
    S.set({ editor: null });
    return { ok };
  });

  // Week view: resizing follows the pointer's height, whichever column it drifts into.
  await check('week view: a resize drifting sideways keeps its length', async () => {
    const handle = [...document.querySelectorAll('.bc-block .bc-block-handle.b')].find((h) => {
      const r = h.getBoundingClientRect();
      const g = document.querySelector('.bc-tg-scroll').getBoundingClientRect();
      return r.top > g.top + 20 && r.bottom < g.bottom - 120 && r.left > g.left + 150 && r.right < g.right - 150;
    });
    if (!handle) return { ok: false, detail: 'no event end to drag on screen' };
    const r = handle.getBoundingClientRect();
    const x0 = r.left + r.width / 2;
    const y0 = r.top + r.height / 2;
    const col = handle.closest('.bc-block').parentElement.getBoundingClientRect().width;
    const height = () => { const p = document.querySelector('.bc-tg-preview'); return p && getComputedStyle(p).display !== 'none' ? Math.round(p.getBoundingClientRect().height) : null; };
    handle.dispatchEvent(new PointerEvent('pointerdown', opts(x0, y0)));
    window.dispatchEvent(new PointerEvent('pointermove', opts(x0, y0 + 40)));
    window.dispatchEvent(new PointerEvent('pointermove', opts(x0, y0 + 60)));
    const straight = height();
    window.dispatchEvent(new PointerEvent('pointermove', opts(x0 + col, y0 + 60)));
    const right = height();
    window.dispatchEvent(new PointerEvent('pointermove', opts(x0 - col, y0 + 60)));
    const left = height();
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    window.dispatchEvent(new PointerEvent('pointerup', opts(x0 - col, y0 + 60, 0)));
    S.set({ dropChoice: null });
    return { ok: straight != null && right === straight && left === straight, straight, right, left };
  });

  A.setView(start.view);
  A.jumpToDate(start.anchor);
  return results;
}
