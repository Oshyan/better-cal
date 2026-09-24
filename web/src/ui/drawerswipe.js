// Swipe the sidebar drawer in and out (touch, drawer layouts only), as in
// Google Calendar's app: a sideways drag that starts near the left edge pulls
// the drawer out with the finger; a leftward drag while it is open pushes it
// back. Let go past a third of its width, or with a flick, and it finishes;
// otherwise it springs back.
//
// The start zone is the left EDGE_PX: the grid's gutter, where the month
// names sit. Android's own back gesture owns roughly the first 20-30px of the
// screen edge, so a swipe starting there goes to the system; starting a
// little further in reaches this.
//
// Touch events rather than pointer events: the grid scrolls vertically, and
// once the browser takes a touch for scrolling it cancels the pointer stream.
// A move listener that can prevent scrolling is attached only while a
// possible swipe is being tracked, so ordinary scrolling never waits on it.

const EDGE_PX = 56;
const SLOP_PX = 10;
const FLICK_PX_PER_MS = 0.5;

export function installDrawerSwipe({ enabled, isOpen, open, close }) {
  let t = null;
  const drawer = () => document.querySelector('.bc-sidebar');

  const finish = () => {
    document.removeEventListener('touchmove', onMove, { passive: false, capture: true });
    document.removeEventListener('touchend', onEnd, true);
    document.removeEventListener('touchcancel', onEnd, true);
  };

  function onStart(e) {
    if (!enabled() || e.touches.length !== 1) return;
    const { clientX: x, clientY: y } = e.touches[0];
    const opened = isOpen();
    const el = drawer();
    if (!el) return;
    // Open: only a drag on the drawer itself (its backdrop closes on a press).
    if (opened && !el.contains(e.target)) return;
    if (!opened) {
      if (x > EDGE_PX) return;
      // Not from under a sheet, a dialog or any other overlay.
      if (document.querySelector('.bc-bsheet, [aria-modal="true"]')) return;
    }
    t = { x0: x, y0: y, opened, el, dragging: false, dx: 0, w: el.offsetWidth || 280, t0: 0 };
    document.addEventListener('touchmove', onMove, { passive: false, capture: true });
    document.addEventListener('touchend', onEnd, true);
    document.addEventListener('touchcancel', onEnd, true);
  }

  function onMove(e) {
    if (!t) return;
    const dx = e.touches[0].clientX - t.x0;
    const dy = e.touches[0].clientY - t.y0;
    if (!t.dragging) {
      if (Math.abs(dx) < SLOP_PX && Math.abs(dy) < SLOP_PX) return;
      // Mostly vertical, or the wrong way for the drawer's state: a scroll.
      const wrongWay = t.opened ? dx > 0 : dx < 0;
      if (Math.abs(dx) < Math.abs(dy) * 1.3 || wrongWay) { t = null; finish(); return; }
      t.dragging = true;
      t.t0 = performance.now();
      t.x0 += dx > 0 ? SLOP_PX : -SLOP_PX; // no jump as the drag takes over
      t.el.style.transition = 'none';
    }
    if (e.cancelable) e.preventDefault();
    t.dx = e.touches[0].clientX - t.x0;
    const off = t.opened ? Math.min(0, t.dx) : Math.min(0, t.dx - t.w);
    t.el.style.transform = `translateX(${off}px)`;
  }

  function onEnd() {
    finish();
    if (!t || !t.dragging) { t = null; return; }
    const { el, opened, dx, w } = t;
    const v = dx / Math.max(1, performance.now() - t.t0);
    t = null;
    const toOpen = opened
      ? !(-dx > w / 3 || v < -FLICK_PX_PER_MS)
      : (dx > w / 3 || v > FLICK_PX_PER_MS);
    if (toOpen !== opened) { if (toOpen) open(); else close(); }
    // Hand back to the stylesheet once the open/closed class has rendered,
    // so the transition runs from where the finger left it.
    setTimeout(() => requestAnimationFrame(() => {
      el.style.transition = '';
      el.style.transform = '';
    }), 0);
  }

  document.addEventListener('touchstart', onStart, { passive: true, capture: true });
  return () => {
    document.removeEventListener('touchstart', onStart, { passive: true, capture: true });
    finish();
  };
}
