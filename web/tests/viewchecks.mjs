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

  // --- From the release review of 1.4.1 to 1.4.3 (#185) ---
  // These add sample events ("View check sample") to the first editable
  // local calendar and delete them after. Moves and resizes are caught
  // before they reach the server: what would have been saved is checked,
  // and nothing is.
  const local = st().calendars.find((c) => c.kind === 'local' && c.editable);
  const iso = (ms) => { const d = new Date(ms); const off = -d.getTimezoneOffset(); const a = Math.abs(off); return keyOf(d) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':00' + (off < 0 ? '-' : '+') + pad(Math.floor(a / 60)) + ':' + pad(a % 60); };
  const made = [];
  const sample = async (fields) => {
    const r = await API.api('/events', { method: 'POST', body: { calendarId: local.id, title: 'View check sample', allDay: false, ...fields } });
    made.push(r.eventId);
    await API.refreshWindow();
    await until(() => st().occ.has(r.instanceId));
    return r.instanceId;
  };
  // Saves of moves and resizes, caught and refused.
  const caught = [];
  const realFetch = window.fetch;
  window.fetch = (url, o) => {
    if (o && o.method === 'PATCH' && /\/events\//.test(String(url))) {
      caught.push(JSON.parse(o.body || '{}'));
      return Promise.resolve(new Response(JSON.stringify({ error: { code: 'view_check', message: 'Caught by a view check' } }), { status: 409, headers: { 'Content-Type': 'application/json' } }));
    }
    return realFetch(url, o);
  };
  const shown = (id) => [...document.querySelectorAll(`[data-instance="${CSS.escape(id)}"]`)].find((el) => el.getBoundingClientRect().width > 0);
  const press = (el, x, y, extra = {}) => el.dispatchEvent(new PointerEvent('pointerdown', { ...opts(x, y), ...extra }));
  const moveTo = (x, y, extra = {}) => window.dispatchEvent(new PointerEvent('pointermove', { ...opts(x, y), ...extra }));
  const release = (x, y, extra = {}) => window.dispatchEvent(new PointerEvent('pointerup', { ...opts(x, y, 0), ...extra }));
  // The day this zone's clock goes back, within the next year, and the first
  // instant of the hour it repeats.
  let back = null;
  for (let t = Date.now(); t < Date.now() + 400 * 864e5 && back === null; t += 3600000) {
    if (new Date(t + 3600000).getTimezoneOffset() > new Date(t).getTimezoneOffset()) back = Math.floor((t + 3600000) / 3600000) * 3600000;
  }
  try {
    if (!local) {
      results.push({ check: 'sample events', ok: false, detail: 'no editable local calendar to add them to' });
    } else {
      // An all-day event let go over the toolbar, above the week, stays put.
      await check('week view: an all-day event let go over the toolbar moves nothing', async () => {
        A.jumpToDate(today);
        A.setView('week');
        await until(() => st().anchorSeen === st().scrollSeq);
        const id = await sample({ allDay: true, start: today, end: addDays(today, 1) });
        await until(() => shown(id));
        const bar = shown(id);
        if (!bar) return { ok: false, detail: 'the sample event did not show' };
        const r = bar.getBoundingClientRect();
        const x = r.left + Math.min(20, r.width / 2);
        const y = r.top + r.height / 2;
        const bar0 = document.querySelector('.bc-toolbar').getBoundingClientRect();
        caught.length = 0;
        press(bar, x, y);
        moveTo(x + 10, y);
        moveTo(x + 10, bar0.top + bar0.height / 2);
        release(x + 10, bar0.top + bar0.height / 2);
        await wait(600);
        const outside = caught.length;
        // The control: let go a day on, it does move.
        const b2 = shown(id).getBoundingClientRect();
        press(shown(id), b2.left + 10, y);
        moveTo(b2.left + 20, y);
        moveTo(b2.left + 10 + b2.width, y);
        release(b2.left + 10 + b2.width, y);
        await wait(600);
        S.set({ dropChoice: null });
        return { ok: outside === 0 && caught.length === 1, overToolbar: outside, aDayOn: caught.length };
      });

      // Two fingers: once one has lifted an event, another press starts
      // nothing, and only the first finger moves anything.
      await check('week view: a second finger starts no drag beside the first', async () => {
        const a = await sample({ allDay: true, start: addDays(today, 1), end: addDays(today, 2) });
        const b = await sample({ allDay: true, start: addDays(today, 1), end: addDays(today, 2), title: 'View check sample 2' });
        await until(() => shown(a) && shown(b));
        if (!shown(a) || !shown(b)) return { ok: false, detail: 'the sample events did not show' };
        const ra = shown(a).getBoundingClientRect();
        const rb = shown(b).getBoundingClientRect();
        const t1 = { pointerId: 11, pointerType: 'touch' };
        const t2 = { pointerId: 12, pointerType: 'touch' };
        caught.length = 0;
        press(shown(a), ra.left + 10, ra.top + ra.height / 2, t1);
        await wait(500); // the long press lifts it
        const lifted = document.body.classList.contains('bc-dragging');
        press(shown(b), rb.left + 10, rb.top + rb.height / 2, t2);
        await wait(500);
        moveTo(rb.left + 10 + rb.width, rb.top + rb.height / 2, t2);
        release(rb.left + 10 + rb.width, rb.top + rb.height / 2, t2);
        await wait(400);
        const bySecond = caught.length;
        moveTo(ra.left + 10 + ra.width, ra.top + ra.height / 2, t1);
        release(ra.left + 10 + ra.width, ra.top + ra.height / 2, t1);
        await wait(600);
        S.set({ dropChoice: null });
        return { ok: lifted && bySecond === 0 && caught.length === 1, lifted, bySecond, byFirst: caught.length };
      });

      // Reschedule mode: after a jump to a far month, the arrow keys pick a
      // day on screen and Enter asks to move there.
      await check('reschedule mode: arrows and Enter work after a far jump', async () => {
        const id = await sample({ start: iso(new Date(new Date().setHours(10, 0, 0, 0)).getTime()), end: iso(new Date(new Date().setHours(11, 0, 0, 0)).getTime()) });
        A.setView('month');
        A.enterReschedule(id);
        await until(() => document.body.classList.contains('bc-rescheduling'));
        A.jumpToDate(addDays(today, 200));
        await until(() => st().anchorSeen === st().scrollSeq);
        await wait(400);
        const key = (k) => window.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
        key('ArrowRight');
        key('Enter');
        const ok = await until(() => document.querySelector('.bc-confirm-chip'), 1500);
        const label = ok ? document.querySelector('.bc-confirm-chip').textContent.trim() : null;
        key('Escape');
        key('Escape');
        A.exitReschedule();
        return { ok: !!ok && /Move to/.test(label), label };
      });

      // Resizing across or during the hour a clock change repeats keeps the
      // end not dragged, and the one dragged lands on the right side of it.
      if (back === null) {
        results.push({ check: 'clock change resizes', ok: true, detail: 'skipped: this time zone has no clock change in the next year' });
      } else {
        const day = keyOf(new Date(back));
        const M = 60000;
        const resize = async (id, edge, dMin) => {
          const block = shown(id);
          const handle = block && block.querySelector('.bc-block-handle.' + (edge === 'end' ? 'b' : 't'));
          if (!handle) return null;
          const hours = [...document.querySelectorAll('.bc-hour-label')].filter((h) => h.getBoundingClientRect().height > 0);
          const perMin = (hours[2].getBoundingClientRect().top - hours[1].getBoundingClientRect().top) / 60;
          const r = handle.getBoundingClientRect();
          const x = r.left + r.width / 2;
          const y = r.top + r.height / 2;
          caught.length = 0;
          press(handle, x, y);
          for (let i = 1; i <= 6; i++) moveTo(x, y + (dMin * perMin * i) / 6);
          release(x, y + dMin * perMin);
          await wait(600);
          return caught[0] || null;
        };
        const go = async (key) => {
          A.jumpToDate(key);
          A.setView('day');
          await until(() => document.querySelector('.bc-tg-vday-head') && st().anchorSeen === st().scrollSeq);
        };
        await check('clock change: resizing in the repeated hour keeps the end not dragged', async () => {
          await go(day);
          // From half past into the hour's second pass, for an hour and a half.
          const s0 = back + 30 * M;
          const id = await sample({ start: iso(s0), end: iso(s0 + 90 * M) });
          await until(() => shown(id));
          shown(id).scrollIntoView({ block: 'center' });
          await wait(300);
          const longer = await resize(id, 'end', 60);
          const shorter = await resize(id, 'end', -75); // to a quarter past the start
          const later = await resize(id, 'start', 30);
          const o = st().occ.get(id);
          const fine = (b, keep, other) => !!b && b[keep] === o[keep] && Date.parse(b.end) > Date.parse(b.start) && b[other] !== o[other];
          return { ok: fine(longer, 'start', 'end') && fine(shorter, 'start', 'end') && fine(later, 'end', 'start'), longer, shorter, later };
        });
        await check('clock change: resizing an overnight event across it keeps the end not dragged', async () => {
          const eve = addDays(day, -1);
          await go(eve);
          const s0 = new Date(Number(eve.slice(0, 4)), Number(eve.slice(5, 7)) - 1, Number(eve.slice(8, 10)), 21, 0).getTime();
          const id = await sample({ start: iso(s0), end: iso(back + 120 * M) });
          await until(() => shown(id));
          shown(id).scrollIntoView({ block: 'center' });
          await wait(300);
          const b = await resize(id, 'start', 30);
          const o = st().occ.get(id);
          return { ok: !!b && b.end === o.end && b.start !== o.start, sent: b, end: o.end };
        });
      }
    }
  } finally {
    window.fetch = realFetch;
    for (const id of made) { try { await API.api('/events/' + id, { method: 'DELETE' }); } catch { /* already gone */ } }
    if (made.length) await API.refreshWindow();
  }

  // A required plugin credential can be cleared: Save stays on, and saving
  // sends the clear. The form is drawn on its own, off screen.
  await check('settings: a required credential can be cleared', async () => {
    // By their plain addresses, which the page maps to the copies it runs:
    // a second copy of the UI runtime would break the form's hooks.
    const { SchemaForm } = await import('/assets/src/app/SchemaForm.js');
    const { render, html } = await import('/assets/vendor/index.js');
    const box = document.createElement('div');
    box.style.cssText = 'position:fixed;left:-2000px;top:0;width:600px';
    document.body.appendChild(box);
    let saved = null;
    try {
      render(html`<${SchemaForm} schema=${[{ key: 'token', type: 'secret', label: 'API key', required: true }]} values=${{ token: { set: true } }} onSave=${async (b) => { saved = b; return true; }} />`, box);
      await until(() => box.querySelector('.bc-schema-secret'));
      // Let its first effects run, as they have long before anyone clicks:
      // the one that takes in the saved values would undo the Clear.
      await wait(300);
      [...box.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Clear').click();
      await until(() => /cleared/i.test(box.querySelector('.bc-schema-secret').textContent));
      const saveBtn = box.querySelector('.bc-schema-actions .bc-btn-primary');
      const enabled = !saveBtn.disabled;
      saveBtn.click();
      await until(() => saved, 1500);
      return { ok: enabled && !!saved && saved.token === null, enabled, saved };
    } finally {
      render(null, box);
      box.remove();
    }
  });

  // Next month pressed quickly lands as many months on, and the grid never
  // turns back on the way: rows changing height mid-glide sent it months
  // back, and the next press stepped from there.
  for (const gap of [300, 1000]) {
    await check(`month view: Next five times, ${gap}ms apart, lands five months on`, async () => {
      A.setView('month');
      A.goToday();
      await until(() => st().anchorSeen === st().scrollSeq);
      await wait(1500);
      const from = st().visibleMonth;
      const next = [...document.querySelectorAll('.bc-toolbar-nav button')].find((b) => b.getAttribute('aria-label') === 'Next month');
      const seen = [];
      let watching = true;
      (function watch() { const d = st().visibleDays; if (d && seen[seen.length - 1] !== d.start) seen.push(d.start); if (watching) requestAnimationFrame(watch); })();
      for (let i = 0; i < 5; i++) { next.click(); await wait(gap); }
      await wait(3000);
      watching = false;
      const k = from.year * 12 + from.month - 1 + 5;
      const want = { year: Math.floor(k / 12), month: (k % 12) + 1 };
      const back2 = seen.filter((x, i) => i > 0 && x < seen[i - 1]);
      return { ok: sameMonth(st().visibleMonth, want) && back2.length === 0, from, want, landed: st().visibleMonth, wentBack: back2 };
    });
  }

  A.setView(start.view);
  A.jumpToDate(start.anchor);
  return results;
}
